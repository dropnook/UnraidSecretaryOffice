#!/bin/bash
# The scenarios from this Mac (playbook §5): this clone's tree to the test VM, the VM reset to its base, the scenarios
# one by one there (tools/scenarios/run-local.sh), the Mac-side checks completed, the reports collected.
#
#   bash tools/scenarios/run.sh <tower|test3> [--all | --desk backup,… | --only name[:arg],…] [--no-sync] [--no-reset]
#                               [--keep] [--no-nightly] [--dev-sync] [--timeout s]
#
#   1. rsync -a --delete --exclude .git ./ → <vm>:/tmp/uso-scenarios/tree/ (unless --no-sync), its git describe beside it;
#      --dev-sync: then plugin/dev-sync.sh there - the code under test into the plugin (off by default: wave 1 tests the
#      released plugin; never on USOPartner).
#   2. base.sh (unless --no-reset).
#   3. Each scenario (--all: every one, --desk: a desk's, --only: these, in this order; `name:arg` hands arg to it as $1):
#      run-local.sh --one there. Exit 3 (dirty), a timeout or an error → base.sh before the next (not with --keep).
#      Reboot halves (<name>-pre.sh, <name>-post.sh): pre, the reports copied here, the reboot (the runner's when pre left
#      the VM up), then wait for ssh, the array Started and a fresh heartbeat; the tree and the reports back; post.
#   4. The reports to ~/Claude/UnraidSecretaryOffice-briefs/scenarios/<date>/<host>/ (<name>.md, .result, .pending, .out,
#      run.info, base-*.log); Mac-side checks of <name>.pending (plan_changes: tools/scenarios/plan-changes.mjs on the
#      copied plan, page_text / page_clean: page.mjs on the copied state files) done here - a failing one turns the
#      scenario red, and with all of them done a scenario whose other checks were green is green;
#      summary.md (red first) written and printed.
# Hosts: tower (ssh uso-test, 192.168.7.111) and test3 (ssh uso-test3, 192.168.7.197) only; USOPartner and nostromo are
# refused by the addresses the VM really has. Exit 0 nothing red, 1 something red/dirty, 2 wrong call, 4 VM unreachable.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
VMDIR=/tmp/uso-scenarios
TREE=$VMDIR/tree

host=${1:-}
shift || true
sel='--all' sync=1 reset=1 keep=0 nightly=1 devsync=0 tmo=3600
while (($#)); do
    case $1 in
        --all) sel='--all'; shift ;;
        --desk|--only) sel="$1 ${2:-}"; shift 2 ;;
        --no-sync) sync=0; shift ;;
        --no-reset) reset=0; shift ;;
        --keep) keep=1; shift ;;
        --no-nightly) nightly=0; shift ;;
        --dev-sync) devsync=1; shift ;;
        --timeout) tmo=${2:-3600}; shift 2 ;;
        -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
        *) echo "run: unknown argument $1" >&2; exit 2 ;;
    esac
done
case $host in
    tower) alias=uso-test ip=192.168.7.111 ;;
    test3) alias=uso-test3 ip=192.168.7.197 ;;
    -h|--help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "usage: run.sh <tower|test3> [--all | --desk … | --only …] [--no-sync] [--no-reset] [--keep] [--no-nightly] [--dev-sync]" >&2; exit 2 ;;
esac

SSH="ssh -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4"
vm() { $SSH "$alias" "$@"; }
say() { echo "run: $*"; }

# never USOPartner (the release canary) or nostromo (real): by the addresses the VM really has
guard() {
    local addrs
    addrs=$(vm "ip -4 -o addr show | awk '{print \$4}' | cut -d/ -f1") || { say "$host ($alias) not reachable"; exit 4; }
    if grep -qxE '192\.168\.7\.(20|190)' <<<"$addrs" || ! grep -qxF "$ip" <<<"$addrs"; then
        say "$alias is not the test VM $host ($ip) - refused"
        exit 2
    fi
}
guard

stamp=$(date +%Y%m%d-%H%M)
OUT=$VMDIR/$stamp-$host
local_dir=$HOME/Claude/UnraidSecretaryOffice-briefs/scenarios/$(date +%Y-%m-%d)/$host
# an earlier run of the day: its reports beside, as <host>-<HHMM> (the summary is this run's alone)
if [[ -n $(ls -A "$local_dir" 2>/dev/null) ]]; then
    was=$(sed -n 's/^stamp=.* \([0-9][0-9]\):\([0-9][0-9]\)$/\1\2/p' "$local_dir/run.info" 2>/dev/null | head -1)
    aside=$local_dir-${was:-earlier}
    [[ -e $aside ]] && aside=$aside-$$
    mv "$local_dir" "$aside" && echo "run: the day's earlier reports moved to $aside"
fi
mkdir -p "$local_dir" || exit 2
opts="--out $OUT --timeout $tmo"
((keep)) && opts="$opts --keep"
((nightly)) || opts="$opts --no-nightly"
t_start=$SECONDS

sync_tree() {
    vm "mkdir -p $TREE" || return 1
    rsync -a --delete --exclude .git -e "$SSH" "$repo/" "$alias:$TREE/" || return 1
    # the branch and the version as tools/release.sh names it (the last v1.* tag, commits since, the commit)
    printf '%s\n' "$(cd "$repo" && git rev-parse --abbrev-ref HEAD 2>/dev/null) $(cd "$repo" && { git describe --tags --match 'v1.*' --dirty 2>/dev/null || git describe --always --dirty; })" \
        | vm "cat > $TREE/.git-describe"
}
pull() { rsync -a --exclude '*.lock' -e "$SSH" "$alias:$OUT/" "$local_dir/" 2>/dev/null; }

if ((sync)); then
    sync_tree || { say "could not copy the tree to $host"; exit 4; }
    say "tree synced to $host:$TREE ($(vm "cat $TREE/.git-describe"))"
fi
vm "test -f $TREE/tools/scenarios/run-local.sh" || { say "no tree on $host (run without --no-sync once)"; exit 2; }
if ((devsync)); then
    say "dev-sync on $host (the code under test into the plugin)"
    vm "bash $TREE/plugin/dev-sync.sh" || { say "dev-sync failed"; exit 1; }
fi
vm "mkdir -p $OUT"

names=$(vm "bash $TREE/tools/scenarios/run-local.sh --list $sel $( ((nightly)) || echo --no-nightly)") || exit 2
[[ -n $names ]] || { say "no scenario to run"; exit 2; }
say "$(echo "$names" | wc -l | tr -d ' ') scenario(s): $(echo $names)"

base() { vm "bash $TREE/tools/scenarios/run-local.sh --base $opts"; }
if ((reset)); then
    base || { say "the base reset failed - no scenario runs on an unknown state"; pull; bash "$here/run-local.sh" --summary "$local_dir"; exit 1; }
fi

# a reboot half: wait for the VM to go away (at most 3 min), come back (15 min), the array Started and a fresh heartbeat
wait_back() {
    local t
    t=$((SECONDS + 180))
    while ((SECONDS < t)) && vm true 2>/dev/null; do sleep 5; done
    t=$((SECONDS + 900))
    until vm true 2>/dev/null; do ((SECONDS < t)) || return 1; sleep 10; done
    t=$((SECONDS + 600))
    until vm 'grep -q "^mdState=\"STARTED\"" /var/local/emhttp/var.ini && f=/var/run/unraid-secretary-office/agent.json && [[ -f $f ]] && (( $(date +%s) - $(stat -c %Y $f) < 60 ))' 2>/dev/null; do
        ((SECONDS < t)) || return 1
        sleep 10
    done
}

reds=0 base_failed=0
for spec in $names; do
    n=${spec%%:*}
    if vm "test -f $TREE/tools/scenarios/$n-pre.sh -a -f $TREE/tools/scenarios/$n-post.sh"; then
        vm "bash $TREE/tools/scenarios/run-local.sh --one '$spec' --half pre $opts"
        pull
        # pre left the VM up (it noted reboot=1, or simply ended): the runner reboots it
        sleep 20
        if vm true 2>/dev/null; then
            say "$n: rebooting $host"
            vm 'nohup sh -c "sleep 2; /sbin/reboot" >/dev/null 2>&1 &' 2>/dev/null
        fi
        if ! wait_back; then
            say "$n: $host did not come back (ssh, array, agent) - stopping here"
            printf 'verdict=error\nwhy=the VM did not come back after the reboot\n' >> "$local_dir/$n.result"
            break
        fi
        say "$n: $host is back"
        sync_tree
        vm "mkdir -p $OUT" && rsync -a -e "$SSH" "$local_dir/" "$alias:$OUT/"
        vm "bash $TREE/tools/scenarios/run-local.sh --one '$spec' --half post $opts"
        code=$?
    else
        vm "bash $TREE/tools/scenarios/run-local.sh --one '$spec' $opts"
        code=$?
    fi
    case $code in
        0|2) ;;
        1) reds=$((reds + 1)) ;;
        255) say "$n: the connection broke"; reds=$((reds + 1)) ;;
        *) reds=$((reds + 1))
           if ((!keep)) && ! base; then
               say "the base reset after $n failed - no further scenario runs on an unknown state"
               base_failed=1
               break
           fi ;;
    esac
done
pull

# ------------------------------------------------------------------------------------------- the Mac-side checks
rkey() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -1; }
setkey() {          # setkey <file> <key> <value>: the key's line replaced (or added)
    local f=$1 tmp
    tmp=$(mktemp)
    grep -v "^$2=" "$f" > "$tmp" 2>/dev/null
    echo "$2=$3" >> "$tmp"
    mv "$tmp" "$f"
}
fetch() {           # fetch <path on VM> <local path>: a file, or a folder's contents
    mkdir -p "$(dirname "$2")"
    if vm "test -d '$1'"; then
        mkdir -p "$2" && vm "tar -C '$1' -cf - ." | tar -C "$2" -xf -
    else
        vm "cat '$1'" > "$2"
    fi
}
for pf in "$local_dir"/*.pending; do
    [[ -s $pf ]] || continue
    n=$(basename "$pf" .pending)
    rf=$local_dir/$n.result
    [[ $(rkey "$rf" mac) == done ]] && continue
    work=$(mktemp -d "${TMPDIR:-/tmp}/uso-mac.XXXXXX")
    macred='' i=0
    { echo; echo "## Mac-side checks"; echo; } >> "$local_dir/$n.md"
    # read from fd 3: ssh in the loop would eat the lines from stdin
    while IFS=$'\t' read -r -u 3 inv kind a b c d e; do
        [[ -n $inv ]] || continue
        i=$((i + 1)) ok=0 detail=''
        case $kind in
            plan_changes)
                fetch "$a" "$work/$i/plan.json"
                ini=''
                [[ $b != - && -n $b ]] && { fetch "$b" "$work/$i/settings.ini"; ini=$work/$i/settings.ini; }
                got=$(node "$here/plan-changes.mjs" "$work/$i/plan.json" $ini 2>&1)
                keys=$(jq -c '[.[].key] | sort' <<<"$got" 2>/dev/null)
                want=$(jq -c 'sort' <<<"${c:-[]}" 2>/dev/null)
                if [[ -n $keys && $keys == "$want" ]]; then ok=1; detail="changes $keys as expected"
                else detail="changes ${keys:-$got}, expected ${want:-$c}"; fi ;;
            page_text)
                fetch "$b" "$work/$i/states"
                args=''
                for k in $e; do args="$args --$d $k"; done
                got=$(node "$here/page.mjs" --states "$work/$i/states" "$a" --lang "$c" $args 2>&1 >/dev/null)
                code=$?
                ((code == 0)) && ok=1
                detail="page $a ($c) $d $e: $(grep -E 'FAIL|did not draw|error' <<<"$got" | head -3 | tr '\n' ';')"
                ((ok)) && detail="page $a ($c) $d $e: ok" ;;
            page_clean)
                # INV-PAGE: the page from these state files says nothing a page never says (page.mjs --clean)
                fetch "$b" "$work/$i/states"
                got=$(node "$here/page.mjs" --states "$work/$i/states" "$a" --lang "$c" --clean 2>&1 >/dev/null)
                code=$?
                ((code == 0)) && ok=1
                if ((ok)); then detail="page $a ($c) clean"
                else detail="page $a ($c): $(grep -E 'FAIL|did not draw|error' <<<"$got" | head -4 | sed 's/^page [^ ]* //' | tr '\n' ';')"; fi ;;
            *) detail="unknown check «$kind»" ;;
        esac
        echo "- $inv $kind: $( ((ok)) && echo green || echo '**red**') - $detail" >> "$local_dir/$n.md"
        ((ok)) || macred="${macred:+$macred,}$inv"
    done 3< "$pf"
    rm -rf "$work"
    # the result: mac done, the Mac's invariants out of «pending», a red one into «red» (a green verdict turns red)
    macs=$(cut -f1 "$pf" | sort -u)
    pend=$(rkey "$rf" pending | tr ',' '\n' | grep -vxF -e "$macs" | paste -sd, -)
    setkey "$rf" pending "$pend"
    setkey "$rf" mac done
    if [[ -n $macred ]]; then
        red=$(rkey "$rf" red)
        setkey "$rf" red "${red:+$red,}$macred"
        if [[ $(rkey "$rf" verdict) == green ]]; then setkey "$rf" verdict red; reds=$((reds + 1)); fi
    fi
    say "$n: Mac-side checks $([[ -n $macred ]] && echo "red ($macred)" || echo green)"
done

echo
bash "$here/run-local.sh" --summary "$local_dir"
sum=$?
say "reports in $local_dir ($(( (SECONDS - t_start) / 60 )) min)"
((sum == 0 && reds == 0 && !base_failed)) && exit 0
exit 1
