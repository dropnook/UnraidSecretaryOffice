#!/bin/bash
# The nightly run of all scenarios (on the Mac, launchd: tools/scenarios/uso-scenarios-nightly.plist - written, installed
# only by the coordinator with the owner's OK): origin/main on Tower, the reds rerun once, a summary with the diff to the
# night before.
#
#   bash tools/scenarios/nightly.sh [--ref origin/main | --tree DIR] [--released] [--no-rerun] [--wait s] [--dry]
#   bash tools/scenarios/nightly.sh --compare <old nightly.tsv> <new nightly.tsv>     the diff part alone (its self-test)
#
#   1. The Tower lock (tower-lock.sh: the Mac's lock, no other run on Tower - a release, a worker - waited for at most
#      --wait s, default 7200); not free → nightly.md says so, exit 4.
#   2. The tree: a detached worktree of this repository at <ref> (default origin/main, fetched first) in
#      ~/Claude/UnraidSecretaryOffice-worktrees/nightly (USO_NIGHTLY_TREE), made the first time and reset to <ref>
#      every night (only that worktree - it is the nightly's own); --tree DIR runs DIR as it is.
#   3. `USO_RUN_TAG=nightly run.sh tower --all --dev-sync` from that tree (--released: without --dev-sync, the plugin
#      Tower has). Only Tower: run.sh refuses USOPartner and nostromo by their addresses.
#   4. Every red one (red, dirty, error, timeout, busy; at most 20) once more: `USO_RUN_TAG=nightly-rerun run.sh tower
#      --only <them>`. Red and then green = flaky, listed apart; red twice = red.
#   5. ~/Claude/UnraidSecretaryOffice-briefs/scenarios/<date>/nightly.md (atop: the feedback inbox, tools/inbox-health.sh -
#      red when it takes no reports; and nightly.tsv: name, verdict, first verdict,
#      seconds, red invariants - what the next night compares with), the diff to the last night before: new reds, fixed,
#      flaky flips, new and gone scenarios; run.sh's own reports beside in tower-nightly/ and tower-nightly-rerun/.
#   --dry: says what it would do (the tree, the lock, the last night) - nothing runs, Tower is not contacted.
# Exit 0 nothing red (flaky ones allowed), 1 something red, 2 wrong call, 4 Tower not free or not reachable.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
BRIEFS=${USO_BRIEFS:-$HOME/Claude/UnraidSecretaryOffice-briefs}
WT=${USO_NIGHTLY_TREE:-$HOME/Claude/UnraidSecretaryOffice-worktrees/nightly}
MAX_RERUN=20

# ------------------------------------------------------------------------------------------------- the diff
# verdict classes: green (green, pending: nothing red in it), red, flaky, skipped
is_red() { case $1 in red|dirty|error|timeout|busy) return 0 ;; esac; return 1; }

# compare <old tsv> <new tsv>: the diff's markdown lines (new reds, fixed, flaky flips, new, gone)
compare() {
    local old=$1 new=$2 n v pv nr='' fx='' fl='' nw='' gone='' sr=0
    pv_of() { awk -F'\t' -v n="$1" '$1 == n {print $2; exit}' "$old"; }
    while IFS=$'\t' read -r n v _; do
        [[ -z $n || $n == \#* ]] && continue
        pv=$(pv_of "$n")
        if [[ -z $pv ]]; then
            nw="$nw $n ($v)"
            [[ $v == red ]] && nr="$nr
- $n (new scenario)"
            continue
        fi
        if [[ $v == red && $pv != red ]]; then nr="$nr
- $n (last night $pv)"
        elif [[ $v == red ]]; then sr=$((sr + 1))
        elif [[ $pv == red && $v == green ]]; then fx="$fx
- $n"
        fi
        if [[ $v == flaky && $pv != flaky ]]; then fl="$fl
- $n became flaky (last night $pv)"
        elif [[ $pv == flaky && $v != flaky ]]; then fl="$fl
- $n no longer flaky: $v"
        fi
    done < "$new"
    while IFS=$'\t' read -r n v _; do
        [[ -z $n || $n == \#* ]] && continue
        awk -F'\t' -v n="$n" '$1 == n {f = 1} END {exit !f}' "$new" || gone="$gone $n"
    done < "$old"
    echo "**New reds:** ${nr:-none}"
    echo
    echo "**Fixed** (red last night, green now): ${fx:-none}"
    echo
    echo "**Flaky flips:** ${fl:-none}"
    echo
    echo "Still red: $sr. New scenarios:${nw:- none}. Gone:${gone:- none}."
}

if [[ ${1:-} == --compare ]]; then
    [[ -f ${2:-} && -f ${3:-} ]] || { echo "usage: nightly.sh --compare <old.tsv> <new.tsv>" >&2; exit 2; }
    compare "$2" "$3"
    exit 0
fi

ref=origin/main tree='' devsync=--dev-sync rerun=1 wait=7200 dry=0
while (($#)); do
    case $1 in
        --ref) ref=${2:-}; shift 2 ;;
        --tree) tree=${2:-}; shift 2 ;;
        --released) devsync=''; shift ;;
        --no-rerun) rerun=0; shift ;;
        --wait) wait=${2:-7200}; shift 2 ;;
        --dry) dry=1; shift ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "nightly: unknown argument $1" >&2; exit 2 ;;
    esac
done

day=$(date +%Y-%m-%d)
out=$BRIEFS/scenarios/$day
md=$out/nightly.md tsv=$out/nightly.tsv
mkdir -p "$out" || exit 2
log=$out/nightly.log
say() { echo "nightly: $*"; }
t_start=$(date +%s) started=$(date '+%Y-%m-%d %H:%M')
# the last night before this one: the newest nightly.tsv of an earlier day
prev=$(ls -d "$BRIEFS"/scenarios/20??-??-?? 2>/dev/null | sort | while read -r d; do
    [[ $(basename "$d") < $day && -s $d/nightly.tsv ]] && echo "$d/nightly.tsv"; done | tail -1)

if ((dry)); then
    say "(dry) the tree: ${tree:-$WT at $ref (git fetch origin, then a detached worktree reset to it)}"
    say "(dry) the lock: ${USO_TOWER_LOCK:-$BRIEFS/scenarios/.tower.lock}, then no run-local.sh on Tower, waited for at most $wait s"
    say "(dry) USO_RUN_TAG=nightly bash <tree>/tools/scenarios/run.sh tower --all $devsync; the reds once more (at most $MAX_RERUN)"
    say "(dry) the summary: $md; the night before: ${prev:-none yet}"
    exit 0
fi

exec >> "$log" 2>&1
echo
echo "=== $started nightly.sh $*"
# the Mac stays awake while the run lasts (launchd starts it, nobody is at the Mac)
command -v caffeinate >/dev/null 2>&1 && { caffeinate -i -w $$ & }

# the makers' feedback inbox, atop nightly.md (tools/inbox-health.sh; 2026-10-21: archived, nobody knew) - red when not open
inbox=$(bash "$here/../inbox-health.sh"); inbox_rc=$?
if ((inbox_rc == 0)); then inbox_md="Feedback inbox: $inbox"; else inbox_md="🔴 **Feedback inbox: $inbox**"; fi
say "feedback inbox: $inbox"

not_run() {     # not_run <why>: a nightly.md that says so (the next night compares with the last real one)
    { echo "# Nightly scenarios - $day"; echo; echo "$inbox_md"; echo; echo "**Not run:** $1 ($started)."; } > "$md"
    say "not run: $1"
}

. "$here/tower-lock.sh"
trap tower_drop EXIT
tower_take "nightly $day" uso-test "$wait"
rc=$?
if ((rc != 0)); then not_run "Tower not free - $TOWER_WHY"; exit 4; fi

# ------------------------------------------------------------------------------------------------- the tree
if [[ -z $tree ]]; then
    git -C "$repo" fetch -q origin || { not_run "git fetch origin failed"; exit 2; }
    sha=$(git -C "$repo" rev-parse --verify -q "$ref^{commit}") || { not_run "no commit $ref"; exit 2; }
    if [[ ! -e $WT ]]; then
        git -C "$repo" worktree add -q --detach "$WT" "$sha" || { not_run "git worktree add $WT failed"; exit 2; }
    else
        # only a worktree of this repository (the nightly's own) is reset
        [[ -f $WT/.git && $(git -C "$WT" rev-parse --git-common-dir 2>/dev/null) -ef $(git -C "$repo" rev-parse --git-common-dir) ]] \
            || { not_run "$WT is not a worktree of $repo - left alone"; exit 2; }
        git -C "$WT" checkout -q --detach -f "$sha" && git -C "$WT" clean -fdxq \
            || { not_run "could not reset $WT to $ref"; exit 2; }
    fi
    tree=$WT
fi
[[ -f $tree/tools/scenarios/run.sh ]] || { not_run "no tools/scenarios/run.sh in $tree"; exit 2; }
desc=$(git -C "$tree" describe --tags --match 'v1.*' --always 2>/dev/null)
say "tree $tree ($desc)"

# run <tag> <run.sh args…>: run.sh with that report tag; sets $dir (its report folder) from its last line
run() {
    local tag=$1 rlog
    shift
    rlog=$out/nightly-$tag.out
    USO_RUN_TAG=$tag bash "$tree/tools/scenarios/run.sh" tower "$@" > "$rlog" 2>&1 < /dev/null
    local code=$?
    dir=$(sed -n 's/^run: reports in \(.*\) ([0-9]* min)$/\1/p' "$rlog" | tail -1)
    say "run.sh $tag: exit $code, reports ${dir:-none} (its output: $rlog)"
    return $code
}
# rows <summary.md>: name<TAB>verdict<TAB>red<TAB>seconds from its table
rows() {
    [[ -f $1 ]] || return 0
    sed -n 's/^| \([^ |]*\) | \([^|]*\) | \([^|]*\) | [^|]* | \([^|]*\) | .*|$/\1	\2	\3	\4/p' "$1" \
        | sed 's/\*\*//g; s/	 */	/g; s/ *	/	/g; s/ *$//' | grep -v '^scenario	'
}

run nightly --all $devsync
code=$?
if [[ -z $dir || ! -f $dir/summary.md ]]; then
    not_run "run.sh ended $code without a summary (Tower unreachable? see $out/nightly-nightly.out)"
    exit 4
fi
first=$(rows "$dir/summary.md")

# the reds once more
reds=$(printf '%s\n' "$first" | while IFS=$'\t' read -r n v _; do is_red "$v" && echo "$n"; done)
again='' rdir=''
if ((rerun)) && [[ -n $reds ]]; then
    specs=$(printf '%s\n' "$reds" | head -$MAX_RERUN | sed 's/@/:/' | paste -sd, -)
    say "rerun: $specs"
    dir_first=$dir
    run nightly-rerun --only "$specs" $devsync
    rdir=$dir dir=$dir_first
    again=$(rows "$rdir/summary.md")
fi

# ------------------------------------------------------------------------------------------------- the summary
tmp=$(mktemp "${TMPDIR:-/tmp}/uso-nightly.XXXXXX")
printf '# name\tverdict\tfirst\tseconds\tred\n' > "$tmp"
printf '%s\n' "$first" | while IFS=$'\t' read -r n v red secs; do
    [[ -n $n ]] || continue
    if is_red "$v"; then
        v2=$(printf '%s\n' "$again" | awk -F'\t' -v n="$n" '$1 == n {print $2; exit}')
        if [[ -z $v2 ]]; then fin=red
        elif is_red "$v2"; then fin=red
        elif [[ $v2 == green || $v2 == pending ]]; then fin=flaky
        else fin=red; fi
    else
        case $v in pending) fin=green ;; *) fin=$v ;; esac
    fi
    printf '%s\t%s\t%s\t%s\t%s\n' "$n" "$fin" "$v" "$secs" "$red"
done >> "$tmp"
mv "$tmp" "$tsv"

count() { awk -F'\t' -v v="$1" '$1 !~ /^#/ && $2 == v' "$tsv" | wc -l | tr -d ' '; }
total=$(grep -vc '^#' "$tsv")
g=$(count green) r=$(count red) f=$(count flaky) s=$(count skipped)
took=$(( ($(date +%s) - t_start) / 60 ))
{
    echo "# Nightly scenarios - $day"
    echo
    echo "$inbox_md"
    echo
    echo "Tower · tree $desc ($( [[ -n $devsync ]] && echo 'the code under test, dev-synced' || echo 'the plugin Tower has')) · started $started · $took min"
    echo
    echo "**$total scenarios: $r red, $f flaky, $s skipped, $g green.**"
    echo
    if [[ -n $prev ]]; then
        echo "## Since the night before ($(basename "$(dirname "$prev")"))"
        echo
        compare "$prev" "$tsv"
    else
        echo "(no night before to compare with)"
    fi
    echo
    echo "## Red"
    echo
    if ((r)); then
        echo "| scenario | first | rerun | red invariants | report |"
        echo "|---|---|---|---|---|"
        awk -F'\t' '$1 !~ /^#/ && $2 == "red"' "$tsv" | while IFS=$'\t' read -r n _ v1 _ red; do
            v2=$(printf '%s\n' "$again" | awk -F'\t' -v n="$n" '$1 == n {print $2; exit}')
            echo "| $n | $v1 | ${v2:-not rerun} | ${red:--} | $dir/$n.md |"
        done
    else
        echo "none"
    fi
    echo
    echo "## Flaky (red, then green on the rerun)"
    echo
    if ((f)); then
        awk -F'\t' '$1 !~ /^#/ && $2 == "flaky"' "$tsv" | while IFS=$'\t' read -r n _ v1 _ red; do
            echo "- $n: first $v1${red:+ ($red)} - $dir/$n.md; the rerun: $rdir/$n.md"
        done
    else
        echo "none"
    fi
    echo
    echo "Reports: $dir/summary.md${rdir:+, the rerun $rdir/summary.md}; this run's log: $log"
    n_reds=$(printf '%s\n' "$reds" | sed '/^$/d' | wc -l | tr -d ' ')
    ((rerun && n_reds > MAX_RERUN)) && echo "Only the first $MAX_RERUN of $n_reds reds were rerun - the others count red."
} > "$md"
say "summary $md: $r red, $f flaky, $g green ($took min)"
tower_drop
((r == 0)) && exit 0
exit 1
