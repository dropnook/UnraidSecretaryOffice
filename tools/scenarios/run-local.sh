#!/bin/bash
# The scenarios' runner on the test VM itself (playbook §5) - what tools/scenarios/run.sh calls over ssh, scenario by
# scenario, and what the nightly cron on Tower runs alone (no Mac: the Mac-side checks stay «pending (mac)», scenarios
# in reboot halves are skipped).
#
#   bash run-local.sh [--all | --desk backup,… | --only name[:arg],…] [--no-reset] [--no-nightly] [--keep] [--out DIR]
#                                         list mode: base.sh, every scenario (a dirty one → base.sh again), summary.md
#   bash run-local.sh --list [--all | --desk … | --only …] [--no-nightly]   the scenarios' names, one a line
#   bash run-local.sh --one name[:arg] [--half pre|post] --out DIR     one scenario (or one half), its result file
#   bash run-local.sh --base --out DIR                                  base.sh, its time and log noted in DIR/run.info
#   bash run-local.sh --summary DIR                                     DIR/summary.md from DIR/*.result (also on the Mac)
#
# A scenario is tools/scenarios/<desk|office>-<…>.sh (desk = a folder of public/desks); one that reboots is two halves
# <name>-pre.sh and <name>-post.sh. `name:arg` hands `arg` to the scenario as $1. Each runs as root under the Tower lock
# (flock /tmp/uso-scenarios.lock, resilienz/wave1/_shared.md), at most --timeout s (default 3600), with
#   USO_SC_OUT   the run's report folder - lib.sh writes <name>.md, <name>.result, <name>.pending there
#   USO_SC_TREE  the tree the scenario runs from;   USO_SC_KEEP=1  with --keep (lib.sh: leave the state for a look)
# (flock -o: the lock's fd is not handed down - an agent a scenario restarts would hold the lock for its whole life.)
# Its output goes to <name>.out. A scenario run with an argument keeps its files as <name>@<arg>.* (one run may call the
# same scenario with several). Without a result file from lib.sh one is made from the exit code: 0 green, 1 red,
# 2 skipped, 3 dirty (undo failed: base.sh before the next), 124 timeout, 75 lock busy, else error (both dirty too).
# Exit (list mode): 0 nothing red, 1 something red/dirty/error, 2 wrong call.
# --summary is plain bash 3.2 (the Mac's) and reads only DIR.
set -u
SC=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TREE=$(cd "$SC/../.." && pwd)
LOCK=/tmp/uso-scenarios.lock

mode=list sel=all names='' out='' half='' reset=1 nightly=1 keep=0 tmo=3600 one=''
while (($#)); do
    case $1 in
        --all) sel=all; shift ;;
        --desk) sel=desk; names=${2:-}; shift 2 ;;
        --only) sel=only; names=${2:-}; shift 2 ;;
        --list) mode=names; shift ;;
        --one) mode=one; one=${2:-}; shift 2 ;;
        --half) half=${2:-}; shift 2 ;;
        --base) mode=base; shift ;;
        --summary) mode=summary; out=${2:-}; shift 2 ;;
        --out) out=${2:-}; shift 2 ;;
        --no-reset) reset=0; shift ;;
        --no-nightly) nightly=0; shift ;;
        --keep) keep=1; shift ;;
        --timeout) tmo=${2:-3600}; shift 2 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
        *) echo "run-local: unknown argument $1" >&2; exit 2 ;;
    esac
done

# ------------------------------------------------------------------------------------------------------------- summary
# One table, red rows first: scenario · verdict · red invariants · pending · seconds · evidence. A .result is key=value.
rkey() { sed -n "s/^$2=//p" "$1" 2>/dev/null | tail -1; }
summary() {
    local dir=$1 f n v red pend keep secs rank mac lines='' info="$1/run.info"
    [[ -d $dir ]] || { echo "run-local: no folder $dir" >&2; return 2; }
    for f in "$dir"/*.result; do
        [[ -f $f ]] || continue
        n=$(basename "$f" .result)
        v=$(rkey "$f" verdict); red=$(rkey "$f" red); pend=$(rkey "$f" pending); keep=$(rkey "$f" keep)
        secs=$(rkey "$f" seconds); mac=$(rkey "$f" mac)
        # Mac-side checks queued and not done here: pending (mac)
        if [[ -s $dir/$n.pending && $mac != done ]]; then
            local macs
            macs=$(cut -f1 "$dir/$n.pending" | sort -u | tr '\n' ',' | sed 's/,$//')
            pend=$(printf '%s\n' ${pend//,/ } | grep -vxF -e "${macs//,/
}" | paste -sd, -)
            pend="${pend:+$pend,}$macs (mac)"
        fi
        # the verdict as the row shows and the total counts it: green only when every check ran green (the Mac-side
        # ones done) - a green verdict with a check still pending is «pending», never «green»
        case $v in
            red|dirty|error|timeout|busy) rank=0 ;;
            skipped) rank=2 ;;
            *) if [[ -n $pend ]]; then rank=1 v=pending; else rank=3; fi ;;
        esac
        lines="$lines$rank	$n	${v:-?}	${red:--}	${pend:--}	${secs:--}	${keep:--}
"
    done
    {
        echo "# Scenarios - $(rkey "$info" host) ($(rkey "$info" ip))"
        echo
        echo "Run $(rkey "$info" stamp) · Unraid $(rkey "$info" unraid) · office $(rkey "$info" office) · engine $(rkey "$info" engine)" \
            "· tree $(rkey "$info" tree) · base $(rkey "$info" base)"
        echo
        local total=0 g=0 r=0 s=0 p=0
        if [[ -n $lines ]]; then
            echo "| scenario | verdict | red invariants | pending | s | evidence |"
            echo "|---|---|---|---|---|---|"
            while IFS='	' read -r rank n v red pend secs keep; do
                [[ -n $n ]] || continue
                total=$((total + 1))
                case $rank in 0) r=$((r + 1)) ;; 1) p=$((p + 1)) ;; 2) s=$((s + 1)) ;; 3) g=$((g + 1)) ;; esac
                case $v in red|dirty|error|timeout|busy) v="**$v**" ;; esac
                echo "| $n | $v | $red | $pend | $secs | $keep |"
            done <<EOF
$(printf '%s' "$lines" | sort -t '	' -k1,1n -k2,2)
EOF
        else
            echo "(no scenario ran)"
        fi
        echo
        echo "$total scenarios: $r red, $p pending, $s skipped, $g green."
        grep -q '^base=.*failed' "$info" 2>/dev/null && echo "**A base reset failed** ($(grep '^base=' "$info" | sed 's/^base=//' | paste -sd'|' - | sed 's/|/ · /g')) - see base-*.log."
    } > "$dir/summary.md"
    cat "$dir/summary.md"
    # red: a red row, or a base reset that failed (then nothing after it ran on a known state)
    grep -q '^base=.*failed' "$info" 2>/dev/null && return 1
    [[ -z $lines ]] || ! printf '%s' "$lines" | grep -q '^0	'
}
if [[ $mode == summary ]]; then
    summary "$out"
    exit $?
fi

# ------------------------------------------------------------------------------------------------------- on the VM only
[[ $(id -u) == 0 ]] || { echo "run-local: runs as root on the test VM" >&2; exit 2; }

# the scenarios there are: <desk|office>-….sh, a pair of halves as one name
scenario_names() {
    local ids f n
    ids=$(cd "$TREE/public/desks" 2>/dev/null && ls -d */ 2>/dev/null | tr -d / | tr '\n' '|')
    ids="${ids}office"
    for f in "$SC"/*.sh; do
        n=$(basename "$f" .sh)
        [[ $n =~ ^($ids)-[a-z0-9-]+$ ]] || continue
        [[ $n == *-post ]] && continue
        n=${n%-pre}
        if ((!nightly)) && head -5 "$f" | grep -qw nightly; then continue; fi
        echo "$n"
    done
}
select_names() {
    local all n w
    all=$(scenario_names)
    case $sel in
        all) printf '%s\n' "$all" | sed '/^$/d' ;;
        desk) for w in ${names//,/ }; do printf '%s\n' "$all" | grep -E "^$w-" ; done ;;
        only) for w in ${names//,/ }; do
                  n=${w%%:*}
                  if printf '%s\n' "$all" | grep -qxF "$n" || [[ -f $SC/$n.sh || -f $SC/$n-pre.sh ]]; then echo "$w"
                  else echo "run-local: no scenario «$n»" >&2; fi
              done ;;
    esac
}
if [[ $mode == names ]]; then
    select_names
    exit 0
fi

[[ -n $out ]] || out=/tmp/uso-scenarios/$(date +%Y%m%d-%H%M)-$(hostname)
mkdir -p "$out" || exit 2
export USO_SC_OUT=$out USO_SC_TREE=$TREE
((keep)) && export USO_SC_KEEP=1

# what this run is: the summary's head (written once per folder; base= added by each reset)
info() {
    local f=$out/run.info ip
    [[ -f $f ]] && return 0
    ip=$(ip -4 -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -E '^192\.168\.' | head -1)
    {
        echo "stamp=$(date '+%Y-%m-%d %H:%M')"
        echo "host=$(hostname)"
        echo "ip=$ip"
        echo "unraid=$(sed -n 's/^version="\(.*\)"$/\1/p' /etc/unraid-version 2>/dev/null)"
        echo "office=$(sed -n "s/.*const OFFICE_VERSION = '\([^']*\)'.*/\1/p" /usr/local/emhttp/plugins/unraid-secretary-office/src/bootstrap.php 2>/dev/null)"
        echo "engine=$(sed -n 's/^UB_VERSION="\{0,1\}\([0-9.]*\)"\{0,1\}.*/\1/p' /usr/local/emhttp/plugins/unraid-secretary-office/backup/lib/common.sh 2>/dev/null | head -1)"
        echo "tree=$(cat "$TREE/.git-describe" 2>/dev/null || echo '?')"
    } > "$f"
}
info

base() {
    local t0=$SECONDS n code
    n=$(ls "$out"/base-*.log 2>/dev/null | wc -l)
    bash "$SC/base.sh" > "$out/base-$((n + 1)).log" 2>&1
    code=$?
    echo "base=$((SECONDS - t0)) s $( ((code == 0)) && echo ok || echo "failed ($code)")" >> "$out/run.info"
    echo "base: $( ((code == 0)) && echo ok || echo "FAILED ($code)") in $((SECONDS - t0)) s (log $out/base-$((n + 1)).log)"
    return $code
}
if [[ $mode == base ]]; then
    base
    exit $?
fi

# one scenario (or a half): its file, under the lock, at most $tmo s; the result file made when lib.sh wrote none
run_one() {
    local spec=$1 name arg file code t0 v why label x
    name=${spec%%:*}; arg=''; [[ $spec == *:* ]] && arg=${spec#*:}
    label=$name${arg:+@$arg}
    # a post half of a run with an argument: its pre half's files back under the scenario's own name
    if [[ $half == post && $label != "$name" ]]; then
        for x in md result pending out; do [[ -f $out/$label.$x ]] && mv -f "$out/$label.$x" "$out/$name.$x"; done
    fi
    file=$SC/$name.sh
    if [[ ! -f $file ]]; then
        if [[ -f $SC/$name-pre.sh && -f $SC/$name-post.sh ]]; then
            if [[ -z $half ]]; then
                printf 'verdict=skipped\nwhy=reboot halves - only the Mac runner (run.sh) runs them\nseconds=0\n' > "$out/$name.result"
                echo "$name: skipped (reboot halves need run.sh)"
                return 2
            fi
            file=$SC/$name-$half.sh
        else
            printf 'verdict=error\nwhy=no such scenario\nseconds=0\n' > "$out/$name.result"
            echo "$name: no such scenario"
            return 4
        fi
    fi
    # a fresh result for this run (a post half keeps the pre half's report and .pending - lib.sh appends - and its
    # result as <name>.result-pre)
    if [[ $half == post ]]; then
        [[ -f $out/$name.result ]] && mv -f "$out/$name.result" "$out/$name.result-pre"
    else
        rm -f "$out/$name.result" "$out/$name.pending" "$out/$name.result-pre" "$out/$name.out" "$out/$name.md"
    fi
    t0=$SECONDS
    ( cd / && timeout "$tmo" flock -o -w 1800 -E 75 "$LOCK" bash "$file" ${arg:+"$arg"} ) >> "$out/$name.out" 2>&1 < /dev/null
    code=$?
    case $code in
        0) v=green ;; 1) v=red ;; 2) v=skipped ;; 3) v=dirty ;; 124) v=timeout; why="ran longer than $tmo s" ;;
        75) v=busy; why='the Tower lock stayed busy for 30 min' ;; *) v=error; why="exit $code" ;;
    esac
    if [[ ! -s $out/$name.result ]] || ((code == 124 || code == 75)); then
        { echo "verdict=$v"; [[ -n ${why:-} ]] && echo "why=$why"; } > "$out/$name.result"
    fi
    echo "exit=$code" >> "$out/$name.result"
    echo "seconds=$((SECONDS - t0))" >> "$out/$name.result"
    [[ -n $half ]] && echo "half=$half" >> "$out/$name.result"
    if [[ ! -s $out/$name.md ]]; then
        { echo "# $name"; echo; echo "No report from lib.sh - the scenario's output (last 60 lines):"; echo; echo '```';
          tail -60 "$out/$name.out"; echo '```'; } > "$out/$name.md"
    fi
    echo "$label${half:+ ($half)}: $(rkey "$out/$name.result" verdict) in $((SECONDS - t0)) s"
    if [[ $label != "$name" ]]; then
        for x in md result pending out result-pre; do [[ -f $out/$name.$x ]] && mv -f "$out/$name.$x" "$out/$label.$x"; done
    fi
    return $code
}
if [[ $mode == one ]]; then
    run_one "$one"
    exit $?
fi

# ------------------------------------------------------------------------------------------------------ list mode
list=$(select_names)
[[ -n $list ]] || { echo "run-local: no scenario to run" >&2; summary "$out" >/dev/null; exit 2; }
if ((reset)); then
    base || { echo "run-local: the base reset failed - no scenario runs on an unknown state" >&2; summary "$out"; exit 1; }
fi
for spec in $list; do
    run_one "$spec"
    code=$?
    case $code in
        0|1|2) ;;
        *) if ((!keep)) && ! base; then
               echo "run-local: the base reset after $spec failed - no further scenario runs on an unknown state" >&2
               summary "$out"
               exit 1
           fi ;;
    esac
done
summary "$out"
