#!/bin/bash
# The scenarios before a release (tools/release.sh step 6b, on the Mac): the desks the release touches, from the files
# changed since the last tag, and their scenarios of the pre-release set (quick.list) on Tower with the code under test.
#
#   bash tools/scenarios/prerelease.sh [--since <tag>] [--dry] [--only name[:arg],…] [--wait s]
#   bash tools/scenarios/prerelease.sh --desks            the changed files' desks, one a line (the files from git, or
#                                                         one a line on stdin with --desks -)
#   bash tools/scenarios/prerelease.sh --self-test        the mapping on invented file lists, quick.list checked
#
#   --since <tag>  the files changed between <tag> and HEAD (default: the last v* tag before HEAD); the working tree's
#                  changes (the release's version bump) don't count. USO_RELEASE_DIFF=<file> takes the file list from
#                  <file> instead (one path a line - an invented diff for a dry run).
#   --dry          says what would run (desks, scenarios, the estimate) - Tower is not contacted
#   --only         these scenarios instead of the desks' (a real run of the step by hand)
#   --wait s       how long to wait for Tower held by another run (default 1800)
#
# Which desks a changed file calls (one table: desks_of below):
#   agent/desks/<id>[-…].php, public/desks/<id>/…, tools/scenarios/<id>-….sh   <id>
#   backup/…  backup and restore     mediacache/… gather/…  jack     smartmover/…  moverelli
#   agent/lib/…  agent/*.php  src/…  public/assets/…  public/lang/…  public/*.php  plugin/…  the other files of
#   tools/scenarios/ (the helpers)                                              all («all quick»)
#   anything else (docs, tests, tools, monitoring, README …)                    none
# A desk without a scenario in quick.list (snapshot, logs …) is named and runs nothing.
#
# Then (not --dry): the Tower lock (tower-lock.sh: the Mac's lock and no other run on Tower, waited for at most --wait),
# `USO_RUN_TAG=prerelease run.sh tower --only <the scenarios> --dev-sync` (its own tree and report folder on Tower,
# reports in ~/Claude/UnraidSecretaryOffice-briefs/scenarios/<date>/tower-prerelease/), and its last lines.
# Exit 0 nothing red, 1 something red, 2 wrong call, 3 nothing to run (no desk touched or none with scenarios),
# 4 Tower not reachable or held by another run past --wait.
set -u
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo=$(cd "$here/../.." && pwd)
QUICK=${USO_QUICK_LIST:-$here/quick.list}

# ------------------------------------------------------------------------------------------------- the mapping
desk_ids() { (cd "$repo/public/desks" 2>/dev/null && ls -d */ 2>/dev/null | tr -d /); }

# desks_of: paths on stdin → desk ids (and «all»), sorted, one a line
desks_of() {
    local p id ids
    ids=" $(desk_ids | tr '\n' ' ') office "
    while IFS= read -r p; do
        p=${p#./}
        case $p in
            '') ;;
            agent/desks/*.php) id=${p#agent/desks/}; id=${id%.php}; id=${id%%-*}
                               [[ $ids == *" $id "* ]] && echo "$id" || echo all ;;
            public/desks/*/*) id=${p#public/desks/}; id=${id%%/*}; echo "$id" ;;
            backup/*) echo backup; echo restore ;;
            mediacache/*|gather/*) echo jack ;;
            smartmover/*) echo moverelli ;;
            tools/scenarios/*.sh)
                id=${p#tools/scenarios/}; id=${id%%-*}
                if [[ ${p#tools/scenarios/} == *-* && $ids == *" $id "* ]]; then echo "$id"; else echo all; fi ;;
            tools/scenarios/*) echo all ;;
            agent/lib/*|agent/*.php|src/*|public/assets/*|public/lang/*|public/*.php|plugin/*) echo all ;;
            *) ;;
        esac
    done | sort -u
}

# quick_names <desk …|all>: the pre-release scenarios for these desks, in quick.list's order (name<TAB>seconds)
quick_names() {
    local want=" $* " name desks secs d hit
    while read -r name desks secs; do
        [[ -z $name || $name == \#* ]] && continue
        hit=0
        if [[ $want == *" all "* ]]; then hit=1
        else for d in ${desks//,/ }; do [[ $want == *" $d "* ]] && hit=1; done; fi
        ((hit)) && printf '%s\t%s\n' "$name" "${secs:-0}"
    done < "$QUICK"
}

changed_files() {
    if [[ -n ${USO_RELEASE_DIFF:-} ]]; then
        cat "$USO_RELEASE_DIFF" || return 2
    else
        git -C "$repo" diff --name-only "$since" HEAD || return 2
    fi
}

# ----------------------------------------------------------------------------------------------- the self-test
self_test() {
    local fails=0 got want n desks secs total=0 sc=$here f
    check() {   # check <label> <want> <paths…>: desks_of on the paths is want (space-separated)
        local label=$1 want=$2; shift 2
        got=$(printf '%s\n' "$@" | desks_of | tr '\n' ' ' | sed 's/ $//')
        if [[ $got == "$want" ]]; then echo "ok   $label: $got"
        else echo "FAIL $label: «$got», expected «$want»"; fails=$((fails + 1)); fi
    }
    check 'a desk file'       'moverelli'          agent/desks/moverelli.php
    check 'a desk part file'  'restore'            agent/desks/restore-drill.php
    check 'the page'          'jack snapshot'      public/desks/jack/desk.js public/desks/snapshot/lang/de.json
    check 'the engine'        'backup restore'     backup/backup.sh backup/lib/common.sh
    check 'tools of desks'    'jack moverelli'     mediacache/mediacache.py gather/gather.sh smartmover/mover.sh
    check 'shared code'       'all'                agent/lib/util.php
    check 'the web side'      'all'                src/api.php
    check 'the plugin'        'all'                plugin/scripts/agent.sh
    check 'office strings'    'all'                public/lang/de.json
    check 'a scenario'        'backup'             tools/scenarios/backup-kp-orphans-delete.sh
    check 'an office one'     'office'             tools/scenarios/office-smoke-refresh.sh
    check 'a helper'          'all'                tools/scenarios/lib.sh
    check 'docs and tests'    ''                   README.md docs/GUIDE.md tests/run.php tools/release.sh monitoring/grafana-dashboard.json
    check 'mixed'             'backup cleanup restore' backup/setup.sh public/desks/cleanup/desk.js docs/DEVELOPMENT.md
    check 'unknown desk file' 'all'                agent/desks/newdesk.php
    # quick_names: the desks' and all
    got=$(quick_names jack | cut -f1 | tr '\n' ' ')
    [[ $got == 'moverelli-jk-list-missing-norun-run moverelli-jk-list-pending-run moverelli-jk-hired-list-run jack-jk-trial-then-real-list ' ]] && echo "ok   jack's set: $got" \
        || { echo "FAIL jack's set: «$got»"; fails=$((fails + 1)); }
    got=$(quick_names snapshot logs | wc -l | tr -d ' ')
    [[ $got == 0 ]] && echo "ok   desks without scenarios: none" || { echo "FAIL desks without scenarios: $got"; fails=$((fails + 1)); }
    # quick.list: every name a single scenario file, not nightly, desks real, the office-in-* last, the total
    while read -r n desks secs; do
        [[ -z $n || $n == \#* ]] && continue
        f=$sc/$n.sh
        if [[ ! -f $f ]]; then echo "FAIL quick.list: no scenario $n.sh (reboot halves or gone)"; fails=$((fails + 1)); continue; fi
        if head -5 "$f" | grep -qw nightly; then echo "FAIL quick.list: $n is marked nightly"; fails=$((fails + 1)); fi
        for d in ${desks//,/ }; do
            [[ " $(desk_ids | tr '\n' ' ') office " == *" $d "* ]] || { echo "FAIL quick.list: $n names desk «$d»"; fails=$((fails + 1)); }
        done
        [[ $secs =~ ^[0-9]+$ ]] || { echo "FAIL quick.list: $n has no seconds"; fails=$((fails + 1)); secs=0; }
        total=$((total + secs))
    done < "$QUICK"
    got=$(grep -v '^#' "$QUICK" | awk 'NF {print $1}' | tail -2 | tr '\n' ' ')
    [[ $got == office-in-*' 'office-in-*' ' ]] && echo "ok   the release installers last: $got" \
        || { echo "FAIL quick.list: the office-in-* scenarios are not last ($got)"; fails=$((fails + 1)); }
    if ((total <= 2400)); then echo "ok   quick.list all together ≈ $((total / 60)) min"
    else echo "FAIL quick.list ≈ $((total / 60)) min - more than 40"; fails=$((fails + 1)); fi
    # the dry run on an invented diff: what release.sh --dry shows
    local fake
    fake=$(mktemp "${TMPDIR:-/tmp}/uso-prerelease.XXXXXX")
    printf '%s\n' public/desks/restore/desk.js docs/GUIDE.md > "$fake"
    got=$(USO_RELEASE_DIFF=$fake bash "$here/prerelease.sh" --since v0.0.0 --dry 2>&1); n=$?
    if ((n == 0)) && grep -q '^prerelease: desks restore$' <<<"$got" && grep -q '^   restore-sh-renamed-files ' <<<"$got" \
        && ! grep -q 'backup-' <<<"$got"; then echo "ok   --dry on an invented diff (restore only)"
    else echo "FAIL --dry on an invented diff (exit $n):"; sed 's/^/     /' <<<"$got"; fails=$((fails + 1)); fi
    printf '%s\n' docs/GUIDE.md README.md > "$fake"
    USO_RELEASE_DIFF=$fake bash "$here/prerelease.sh" --since v0.0.0 --dry >/dev/null 2>&1; n=$?
    ((n == 3)) && echo "ok   --dry with only docs changed: nothing to run (3)" || { echo "FAIL docs only: exit $n, expected 3"; fails=$((fails + 1)); }
    # release.sh's own step 6b, cut out of it, with its helpers stubbed: --dry on the invented diff, --no-scenarios
    local step6b
    step6b=$(mktemp "${TMPDIR:-/tmp}/uso-prerelease-6b.XXXXXX")
    { echo 'set -u -o pipefail; step() { echo "== [$1] $2"; }; ok() { echo "ok: $*"; }; red() { echo "RED: $*"; }; unbump() { :; }'
      sed -n '/^step 6b /,/^# ------/p' "$repo/tools/release.sh" | sed '$d'
      echo 'echo "NOTE: $scen_note"'; } > "$step6b"
    printf '%s\n' smartmover/mover.sh > "$fake"
    got=$(cd "$repo" && dry=1 no_scenarios=0 USO_RELEASE_DIFF=$fake bash "$step6b" < /dev/null 2>&1)
    if grep -q '^ok: 6b scenarios (dry): [0-9]* scenario(s), ≈ [0-9]* min would run since v' <<<"$got" \
        && grep -q 'moverelli-sh-open-file-run' <<<"$got"; then echo "ok   release.sh --dry, step 6b on an invented diff (moverelli)"
    else echo "FAIL release.sh step 6b --dry:"; sed 's/^/     /' <<<"$got"; fails=$((fails + 1)); fi
    got=$(cd "$repo" && dry=1 no_scenarios=1 bash "$step6b" < /dev/null 2>&1)
    grep -q '^NOTE: !!! NO SCENARIOS' <<<"$got" && echo "ok   release.sh --no-scenarios: the loud line" \
        || { echo "FAIL release.sh --no-scenarios:"; sed 's/^/     /' <<<"$got"; fails=$((fails + 1)); }
    rm -f "$fake" "$step6b"
    echo
    ((fails == 0)) && { echo "prerelease self-test: all green"; return 0; }
    echo "prerelease self-test: $fails failed"
    return 1
}

# ------------------------------------------------------------------------------------------------------- main
since='' dry=0 only='' wait=1800 mode=run
while (($#)); do
    case $1 in
        --since) since=${2:-}; shift 2 ;;
        --dry) dry=1; shift ;;
        --only) only=${2:-}; shift 2 ;;
        --wait) wait=${2:-1800}; shift 2 ;;
        --desks) mode=desks; shift; [[ ${1:-} == - ]] && { mode=desks-stdin; shift; } ;;
        --self-test) mode=selftest; shift ;;
        -h|--help) sed -n '2,32p' "$0"; exit 0 ;;
        *) echo "prerelease: unknown argument $1" >&2; exit 2 ;;
    esac
done
say() { echo "prerelease: $*"; }

case $mode in
    selftest) self_test; exit $? ;;
    desks-stdin) desks_of; exit 0 ;;
esac
if [[ -z $since && -z ${USO_RELEASE_DIFF:-} ]]; then
    since=$(git -C "$repo" describe --tags --abbrev=0 --match 'v[0-9]*' HEAD 2>/dev/null) \
        || { say "no tag before HEAD - name one with --since"; exit 2; }
fi
files=$(changed_files) || { say "the changed files since ${since:-?} could not be listed"; exit 2; }
desks=$(printf '%s\n' "$files" | desks_of | tr '\n' ' ' | sed 's/ $//')
if [[ $mode == desks ]]; then printf '%s\n' $desks; exit 0; fi

if [[ -n $only ]]; then
    list=$(printf '%s\n' ${only//,/ } | sed 's/$/	0/')
    say "by hand: ${only//,/ }"
else
    say "$(printf '%s\n' "$files" | sed '/^$/d' | wc -l | tr -d ' ') file(s) changed $( [[ -n ${USO_RELEASE_DIFF:-} ]] && echo "in $USO_RELEASE_DIFF (an invented diff)" || echo "since $since")"
    if [[ -z $desks ]]; then say "no desk touched (docs, tests, tools only) - no scenario to run"; exit 3; fi
    say "desks $desks"
    list=$(quick_names $desks)
    for d in $desks; do
        [[ $d == all ]] && continue
        [[ -n $(quick_names "$d") ]] || say "$d: no scenario in the pre-release set"
    done
    [[ -n $list ]] || { say "none of these desks has a pre-release scenario - nothing to run"; exit 3; }
fi
est=$(printf '%s\n' "$list" | awk -F'\t' '{s += $2} END {print int((s + 59) / 60)}')
say "$(printf '%s\n' "$list" | wc -l | tr -d ' ') scenario(s)$( [[ -z $only ]] && echo ", ≈ $est min on Tower (+ the base reset and the Mac-side checks)"):"
printf '%s\n' "$list" | awk -F'\t' '{printf "   %-48s %s\n", $1, ($2 > 0 ? "≈ " $2 " s" : "")}'
if ((dry)); then
    say "(dry, not run) USO_RUN_TAG=prerelease bash tools/scenarios/run.sh tower --only <these> --dev-sync"
    exit 0
fi

. "$here/tower-lock.sh"
trap tower_drop EXIT
tower_take "prerelease $(git -C "$repo" rev-parse --short HEAD 2>/dev/null)" uso-test "$wait"
rc=$?
if ((rc != 0)); then say "Tower not free: $TOWER_WHY - not run"; exit 4; fi
names=$(printf '%s\n' "$list" | cut -f1 | paste -sd, -)
t0=$SECONDS
USO_RUN_TAG=prerelease bash "$here/run.sh" tower --only "$names" --dev-sync
rc=$?
tower_drop
say "run.sh ended $rc after $(( (SECONDS - t0) / 60 )) min"
case $rc in
    0) exit 0 ;;
    4) exit 4 ;;
    2) exit 2 ;;
    *) exit 1 ;;
esac
