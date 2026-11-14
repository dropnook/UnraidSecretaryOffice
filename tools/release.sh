#!/bin/bash
# Unraid Secretary Office - the mechanical part of a release (for the coordinator, on the Mac).
#
#   tools/release.sh <x.y.z> [--dry] [--fresh] [--no-main] [--servers-only] [--no-scenarios] [--no-inbox-check]
#
# Run it from the clone on the Mac. What must be done and checked BEFORE it (suite, browser smoke, update test from
# the previous version) and the rules around it (order of the servers, the quiet day after a release) are in the
# maintainer's release playbook (beside the notes, see below). The script stops at the first red step with one line
# that says why.
#
# Its machines and folders come from a file outside the repository, $USO_RELEASE_CONF (default
# ~/.config/uso-release.conf; bash, sourced). Without it the script stops before anything else:
#   notes_dir=<folder>              release-<x.y.z>-notes.md lies there, the logs go to <folder>/releases/
#   suite_host=<ssh host>           where the suite runs (step 7) - Tower, the scenarios' host: after them
#   suite_host2=<ssh host>          optional: the suite runs there instead, beside the scenarios (test4); the tests it
#                                   skips (no python3 there: «… 0 failed, N skipped») then run alone on suite_host
#                                   once the scenarios are done - both together must be 0 failed
#   servers=(<host>=<Label> …)      step 12's order: the first is the canary, the last the main server
#
# Its steps:
#
#    1 tools      git, gh (logged in), rsync, ssh, curl there; the version looks like x.y.z; the makers' feedback inbox
#                 takes reports (tools/inbox-health.sh: the Worker's /api/health open, no problems - else red; no answer:
#                 a note, no stop)
#    2 clone      on main, nothing uncommitted, main == origin/main; the tag v<x.y.z> not taken yet
#    3 notes      <notes_dir>/release-<x.y.z>-notes.md exists and isn't empty
#    4 bump       OFFICE_VERSION (src/bootstrap.php) == AGENT_VERSION (agent/agent.php), both older than x.y.z;
#                 both set to x.y.z
#    5 build      bash plugin/build.sh <x.y.z> passes on the Mac (what the Action runs: versions, the .plg's max)
#   6, 6b and 7 run side by side (each in the background, its output into a file of its own - `tail -f` named at the
#   start): the click test on this Mac, the scenarios on Tower, the suite on suite_host2 (without it: on suite_host
#   after the scenarios). A red one doesn't stop the others: when all three ended, each step gets its block in the log
#   (6, 6b, 7 - never interleaved) and every red is said; then the run stops (the version lines put back only then).
#    6 ui clicks  bash tools/ui-clicks.sh on this Mac: every desk page built from this clone's files in a headless
#                 Chrome (a throwaway profile, both themes, 1440 and 375 px) - every <summary>, row, group and tile
#                 that opens or closes does so on its own and stays so (also when the desk draws itself anew), no
#                 console error, no horizontal scrolling. Red stops the release like the suite; without node,
#                 playwright-core or a Chrome it is skipped with a note (≈ 8 min). A red page gets one retry: the red
#                 pages' routes alone (--only, from its «ui clicks failed:» lines); green then = the step is green, each
#                 page said as «flaky: <page> (<theme> <width>)» in its block and in the last lines; red twice = red.
#                 (USO_RELEASE_FLAKY=<route> forces one page of <route> red in the first run - the flaky path shown;
#                 <route>:twice also in the retry - red twice. Never in a real release.)
#   6b scenarios  the desks this release touches (the files changed since the last tag, the bump left out) and their
#                 scenarios of the pre-release set on Tower with the code under test: tools/scenarios/prerelease.sh
#                 (its head: which file calls which desk, tools/scenarios/quick.list: the set, ≈ 40 min at most). Red
#                 stops the release like the suite; it waits at most 30 min for Tower held by another run (the nightly,
#                 a worker), then stops. No desk touched: nothing runs. Its time stands in the last lines
#    7 suite      rsync to <suite_host>:/tmp/uso-main/, php tests/run.php there ends «… passed, 0 failed»
#                 (suite_host2: there, then the tests it skipped on <suite_host> - see the conf above). On
#                 <suite_host> under the Tower lock like the scenarios (tools/scenarios/tower-lock.sh): never beside
#                 another run of scenarios there - waited for 30 min at most, then red
#                 (a red build, click test, scenario or suite puts the two version lines back)
#
#   Steps 5-7 keep their result in the state file beside the log (<notes_dir>/releases/<x.y.z>.state: a line a step -
#   its inputs' hash, when it ran, green/red). A step green in an earlier run of the same version whose inputs are the
#   same is not run again: «>>> TAKEN from the run of <time>, same inputs», in its block and the last lines. Its inputs
#   (conservative - git ls-tree of the working tree as it is, untracked files too, hashed before the bump, so the two
#   version lines never change them; plus the version the step runs with):
#       5 build       the whole tree
#       6 ui clicks   public/ tests/ui/ tools/ui-* src/
#       6b scenarios  everything but docs/ (+ the last tag, --dry, USO_RELEASE_DIFF's content)
#       7 suite       the whole tree
#   Steps 1-4 and 8-12 always run. --fresh: nothing taken (every step runs, the state file is written anew).
#    8 commit     «Version x.y.z» , push main, origin/main is that commit
#    9 release    gh release create v<x.y.z> --target <that commit> --title «Version x.y» (x.y.z for a fix) - step 11
#                 retitles it to the plugin's date version (2026.10.18e) and puts «Unraid Secretary Office x.y.z» atop the notes
#                 --notes-file <notes>; the tag points to the commit
#   10 action     the Action plugin.yml for v<x.y.z> finished green (polled, at most 10 min)
#   11 assets     the release has the .plg and the .txz; its .plg names office x.y.z; the public «latest» address
#                 serves that .plg (what `plugin check` reads)
#   12 servers    the servers in their order (the main one, the last, unless --no-main), each:
#                 reachable; no long job running (the .plg's own guard pattern: backup run/setup, restore, drill,
#                 Jack Emby's runs) - refused, never waited for; plugin check names the new .plg version;
#                 plugin update; then the installed .plg's version, OFFICE_VERSION in the installed bootstrap.php,
#                 the agent process, «Agent started (vx.y.z,» in the agent log within 60 s, no line with the word
#                 error/fatal in the agent log's last 2 min (printed if any).
#                 The first is the canary: after its update its agent log is watched 5 min (no error/fatal line,
#                 the agent's PID unchanged) before the next one is updated. A server already on x.y.z is not updated
#                 again, only checked.
#
#   --dry           prints every command; runs only the read-only ones: steps 1-3, the version check (no bump), the
#                   build with the CURRENT version (into dist/, ignored by git), the click test, the scenarios step's
#                   list (the desks, the scenarios, the estimate - Tower not contacted; USO_RELEASE_DIFF=<file> takes
#                   the changed files from <file>, one a line: an invented diff), the suite on a copy of
#                   its own (<suite_host>:/tmp/uso-release-dry/), steps 10-11 against the CURRENT release, and on every
#                   server but the main one `plugin check` plus the checks against what is installed. Nothing is bumped,
#                   committed, pushed, released or updated; the main server is not contacted at all. A red step does not stop a dry
#                   run: every red step is listed at the end (exit 1 if any).
#   --fresh         steps 5-7 run even when the state file has them green with the same inputs
#   --no-main       stop before the main server - it later, when no run is active: `tools/release.sh <x.y.z> --servers-only`
#   --servers-only  the release is out already: only steps 1, 10, 11, 12 (resume after a red server step)
#   --no-inbox-check  step 1's look at the feedback inbox skipped - said loudly there: say why in the day's notes
#   --no-scenarios  step 6b skipped - said loudly there and in the last lines (Tower down, a known red the release fixes
#                   elsewhere): say why in the day's notes
#
# Log: <notes_dir>/releases/<x.y.z>.log - every run appended: all it prints, the suite's
# output and every server's answers included; the state file beside it (<x.y.z>.state). Exit: 0 done, 1 a red step,
# 2 wrong call.
#
# No `set -e`: every step checks what it ran and says what went wrong.
# The whole script is one { … } block: bash reads it all before the first step, so a pull or merge into this clone
# while a run lasts (35-120 min) can't change what the running one does next.
set -u -o pipefail
{

conf=${USO_RELEASE_CONF:-$HOME/.config/uso-release.conf}
name=unraid-secretary-office
plugin_dir=/usr/local/emhttp/plugins/$name
canary_watch=300   # s: the canary's agent log without error/fatal before the next server gets the release

usage() { sed -n '4p' "${BASH_SOURCE[0]}" | sed 's/^# *//' >&2; exit 2; }

ver='' dry=0 no_main=0 servers_only=0 no_scenarios=0 no_inbox_check=0 fresh=0
for a in "$@"; do
    case $a in
        --dry) dry=1 ;;
        --fresh) fresh=1 ;;
        --no-scenarios) no_scenarios=1 ;;
        --no-inbox-check) no_inbox_check=1 ;;
        --no-main) no_main=1 ;;
        --servers-only) servers_only=1 ;;
        -h|--help) usage ;;
        -*) echo "release: unknown option $a" >&2; usage ;;
        *) [[ -z "$ver" ]] || usage; ver=${a#v} ;;
    esac
done
[[ -n "$ver" ]] || usage
[[ "$ver" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "release: the version must look like 1.47.0, not '$ver'" >&2; exit 2; }

repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$repo" || exit 2
# shellcheck source=tools/release-lib.sh
. "$repo/tools/release-lib.sh"
# who holds Tower (the Mac's lock, no run-local.sh there): the suite on suite_host takes it like the scenarios do
# shellcheck source=tools/scenarios/tower-lock.sh
. "$repo/tools/scenarios/tower-lock.sh"
notes_dir='' suite_host='' suite_host2='' servers=()
# shellcheck source=/dev/null
[[ -r "$conf" ]] && source "$conf"
if [[ -z "$notes_dir" || -z "$suite_host" ]] || (( ${#servers[@]} == 0 )); then
    echo "release: $conf must set notes_dir, suite_host and servers=(<host>=<Label> ...) - see the head of tools/release.sh" >&2
    exit 2
fi
mkdir -p "$notes_dir/releases" || { echo "release: cannot create $notes_dir/releases" >&2; exit 2; }
log="$notes_dir/releases/$ver.log"
state="$notes_dir/releases/$ver.state"
notes="$notes_dir/release-$ver-notes.md"
exec > >(tee -a "$log") 2>&1

# the servers' hosts in their order: the first is the canary, the last the main server (left out with --no-main)
hosts=()
for s in "${servers[@]}"; do hosts+=("${s%%=*}"); done
canary=${hosts[0]} main=${hosts[$(( ${#hosts[@]} - 1 ))]}
run_hosts=("${hosts[@]}")
(( no_main && ${#hosts[@]} > 1 )) && unset "run_hosts[$(( ${#hosts[@]} - 1 ))]"
label() { local s; for s in "${servers[@]}"; do [[ "${s%%=*}" == "$1" ]] && { echo "${s#*=}"; return; }; done; echo "$1"; }

reds=()
step_no=0 step_name=''
step() { step_no=$1; step_name=$2; echo; echo "== [$step_no] $step_name"; }
ok() { echo "   ok: $*"; }
# red: the release stops here (a dry run notes it and goes on); returns 1 so a step can skip its rest
red() {
    echo "   RED [$step_no $step_name]: $*"
    if (( dry )); then
        reds+=("[$step_no $step_name] $*")
        return 1
    fi
    echo
    echo "STOPPED at step $step_no ($step_name): $*"
    last_lines
    echo "Log: $log"
    exit 1
}
# show: print a command, run it (every command that runs is shown)
show() { echo "   \$ $*"; "$@"; }
# a red build or suite after the bump puts the two version lines back (so does Ctrl-C before the commit)
bumped=0
unbump() { (( bumped )) && { git checkout -- src/bootstrap.php agent/agent.php; echo "   (the two version lines are put back)"; bumped=0; }; return 0; }
# steps 6, 6b, 7 run as background jobs: Ctrl-C ends them (and what they started) before the version lines go back
par_pids=()
killtree() { local c; for c in $(pgrep -P "$1" 2>/dev/null); do killtree "$c"; done; kill "$1" 2>/dev/null; }
stop_jobs() { local p; for p in ${par_pids[@]+"${par_pids[@]}"}; do killtree "$p"; done; par_pids=(); }
trap 'echo; stop_jobs; tower_drop; unbump; echo "INTERRUPTED at step $step_no ($step_name). Log: $log"; exit 1' INT TERM

# The state file ($state, beside the log): a green step of an earlier run of this version is taken, not run again, while
# its inputs (the table in the head) are the same - said loudly; --fresh runs everything
tree0='' taken_when='' taken_what='' taken_line='' taken_notes=() flaky_notes=() par_reds=()
inputs() { [[ -n "$tree0" ]] && rl_hash "$repo" "$tree0" "$@"; }
taken_quiet() {     # taken_quiet <step> <hash>: green with these inputs in the state file (and no --fresh)
    local got
    taken_when='' taken_what='' taken_line=''
    (( fresh )) && return 1
    [[ -n "$2" ]] || return 1
    got=$(rl_state_get "$state" "$1" "$2") || return 1
    taken_when=${got%%$'\t'*} taken_what=${got#*$'\t'}
    taken_line="   >>> TAKEN from the run of $taken_when, same inputs ($1 $2): $taken_what - not run again (--fresh runs it)"
}
taken() {
    taken_quiet "$@" || return 1
    echo "$taken_line"
    taken_notes+=("[$step_no $step_name] taken from the run of $taken_when, same inputs")
}
remember() { [[ -n "$2" ]] && rl_state_put "$state" "$1" "$2" "$3" "$4"; return 0; }
# pred: a red step of the three side by side - said now, the stop comes when all three are said
pred() { echo "   RED [$step_no $step_name]: $*"; par_reds+=("[$step_no $step_name] $*"); }
# the last lines' notes: flaky pages, steps taken from an earlier run, the scenarios' time
last_lines() {
    local n
    for n in ${flaky_notes[@]+"${flaky_notes[@]}"}; do echo "$n"; done
    for n in ${taken_notes[@]+"${taken_notes[@]}"}; do echo "$n"; done
    [[ -n "${scen_note:-}" ]] && echo "$scen_note"
    return 0
}

# remote <host> [var=value …] <<'EOF' … EOF - runs the script from stdin on <host> with those variables set first.
# Everything goes through stdin: no pattern of ours ever stands in a remote command line (pgrep -f would see it).
# Commands in such a script that could read stdin get </dev/null - they would eat the rest of the script.
remote() {
    local host=$1; shift
    { for kv in "$@"; do printf '%s=%q\n' "${kv%%=*}" "${kv#*=}"; done; cat; } \
        | ssh -o BatchMode=yes -o ConnectTimeout=10 "$host" bash -s
}

# vernewer a b: a is newer than b (x.y.z, numerically)
vernewer() {
    local IFS=. i; local -a a=($1) b=($2)
    for i in 0 1 2; do
        (( 10#${a[i]} > 10#${b[i]} )) && return 0
        (( 10#${a[i]} < 10#${b[i]} )) && return 1
    done
    return 1
}
const_of() { sed -n "s/^const $1 *= *'\([^']*\)';.*/\1/p" "$2" | head -n 1; }
plg_entity() { sed -n "s/.*ENTITY $1 *\"\([^\"]*\)\".*/\1/p" | head -n 1; }

echo
echo "=== $(date '+%Y-%m-%d %H:%M:%S') tools/release.sh $ver$( ((dry)) && echo ' --dry')$( ((no_main)) && echo ' --no-main')$( ((servers_only)) && echo ' --servers-only')$( ((no_scenarios)) && echo ' --no-scenarios')  ($(git -C "$repo" rev-parse --short HEAD 2>/dev/null) in $repo)"
(( dry )) && echo "DRY RUN: nothing is bumped, committed, pushed, released or updated; $(label "$main") is not contacted."

# ---------------------------------------------------------------------------------------------------------------------
step 1 tools
missing=()
for t in git gh rsync ssh curl sed awk; do command -v "$t" >/dev/null 2>&1 || missing+=("$t"); done
if (( ${#missing[@]} )); then red "missing on this Mac: ${missing[*]}"
elif ! gh auth status >/dev/null 2>&1; then red "gh is not logged in (gh auth status)"
else ok "git gh rsync ssh curl; gh logged in; version $ver"; fi
github=$(plg_entity github < "plugin/$name.plg")
[[ -n "$github" ]] || red "no ENTITY github in plugin/$name.plg"
# the makers' feedback inbox (2026-10-21: archived, every report failed, nobody knew) - only we need to know
if (( no_inbox_check )); then
    echo "   !!! NOT CHECKED: the feedback inbox (--no-inbox-check) - say why in the day's notes"
else
    inbox=$(bash tools/inbox-health.sh); inbox_rc=$?
    if (( inbox_rc == 0 )); then ok "feedback inbox $inbox"
    elif (( inbox_rc == 3 )); then echo "   NOTE: the feedback inbox didn't answer - $inbox (not stopped; look at it after the release)"
    else
        echo "   !!! THE FEEDBACK INBOX TAKES NO REPORTS !!!"
        red "the feedback inbox: $inbox - fix it first (uso-support feedback/SETUP.md), or --no-inbox-check"
    fi
fi

cur_office=$(const_of OFFICE_VERSION src/bootstrap.php)
cur_agent=$(const_of AGENT_VERSION agent/agent.php)

if (( ! servers_only )); then
# ---------------------------------------------------------------------------------------------------------------------
step 2 clone
branch=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
dirty=$(git status --porcelain --untracked-files=normal 2>/dev/null)
if [[ "$branch" != main ]]; then red "the clone is on '$branch', not on main"; fi
if [[ -n "$dirty" ]]; then red "uncommitted changes:"$'\n'"$(sed 's/^/      /' <<<"$dirty")"; fi
if ! show git fetch -q origin main; then
    red "git fetch origin main failed"
else
    head=$(git rev-parse HEAD) remote_main=$(git rev-parse origin/main)
    if [[ "$head" != "$remote_main" ]]; then
        red "HEAD ${head:0:7} is not origin/main ${remote_main:0:7} (pull or push first)"
    else
        ok "HEAD ${head:0:7} == origin/main"
    fi
fi
if [[ -n "$(git ls-remote --tags origin "refs/tags/v$ver" 2>/dev/null)" ]] || gh release view "v$ver" >/dev/null 2>&1; then
    red "the tag or release v$ver exists already (resume with --servers-only?)"
else
    ok "tag v$ver free"
fi

# ---------------------------------------------------------------------------------------------------------------------
step 3 notes
if [[ ! -s "$notes" ]]; then
    red "release notes missing or empty: $notes"
else
    ok "$notes ($(wc -l < "$notes" | tr -d ' ') lines; first: $(grep -m1 . "$notes" | cut -c1-80))"
fi

# the inputs' hashes come from the tree as it is now - before the bump, so the two version lines never change them
tree0=$(rl_tree "$repo") || tree0=''
[[ -n "$tree0" ]] || echo "   note: no tree hash (git add -A into a throwaway index failed) - every step runs, nothing is taken or kept"

# ---------------------------------------------------------------------------------------------------------------------
step 4 bump
if [[ -z "$cur_office" || -z "$cur_agent" ]]; then
    red "cannot read OFFICE_VERSION ('$cur_office') or AGENT_VERSION ('$cur_agent')"
elif [[ "$cur_office" != "$cur_agent" ]]; then
    red "OFFICE_VERSION is $cur_office but AGENT_VERSION is $cur_agent - they move together"
elif [[ "$cur_office" == "$ver" ]]; then
    red "both constants are $ver already (released? then --servers-only)"
elif ! vernewer "$ver" "$cur_office"; then
    red "$ver is not newer than $cur_office"
else
    ok "both constants $cur_office -> $ver"
    if (( dry )); then
        echo "   (dry, not run) set OFFICE_VERSION in src/bootstrap.php and AGENT_VERSION in agent/agent.php to '$ver'"
    else
        for f in src/bootstrap.php:OFFICE_VERSION agent/agent.php:AGENT_VERSION; do
            show sed -i.release-bak "s/^const ${f#*:} = '$cur_office';/const ${f#*:} = '$ver';/" "${f%%:*}"
            rm -f "${f%%:*}.release-bak"
        done
        bumped=1
        [[ "$(const_of OFFICE_VERSION src/bootstrap.php)" == "$ver" && "$(const_of AGENT_VERSION agent/agent.php)" == "$ver" ]] \
            || { git checkout -- src/bootstrap.php agent/agent.php; red "the bump did not take (lines put back): look at the const lines"; }
        show git diff --stat
    fi
fi

# ---------------------------------------------------------------------------------------------------------------------
step 5 build
build_ver=$ver
(( dry )) && build_ver=$cur_office && echo "   (dry: built with the current version $cur_office - the bump is not made)"
h_build=$(inputs build "$build_ver")
if taken build "$h_build"; then
    :
elif [[ -n "$build_ver" ]] && show bash plugin/build.sh "$build_ver"; then
    ok "plugin/build.sh $build_ver"
    remember build "$h_build" green "plugin/build.sh $build_ver"
else
    remember build "$h_build" red "plugin/build.sh $build_ver failed"
    unbump; red "plugin/build.sh $build_ver failed - the Action would fail the same way"
fi

# ---------------------------------------------------------------------------------------------------------------------
# Steps 6, 6b and 7 side by side: the click test on this Mac, the scenarios on Tower, the suite on $suite_host2 (without
# it: on $suite_host once the scenarios are done - Tower can't take both). Each writes into a folder of its own; when all
# are done, each step gets its block below, in their order. A red one doesn't stop the others: all results are said,
# then the release stops (the version lines put back only then - the others read the tree until they end).
run_ver=$ver; (( dry )) && run_ver=$cur_office
last_tag=$(git describe --tags --abbrev=0 --match 'v[0-9]*' HEAD 2>/dev/null)
diff_word=''; [[ -n ${USO_RELEASE_DIFF:-} ]] && diff_word="diff $(rl_sha < "$USO_RELEASE_DIFF" 2>/dev/null)"
h_clicks=$(inputs clicks "$run_ver")
h_scen=$(inputs scenarios "$run_ver" "since ${last_tag:-none}" "dry $dry" "$diff_word")
h_suite=$(inputs suite "$run_ver")
suite_dir=/tmp/uso-main
(( dry )) && suite_dir=/tmp/uso-release-dry
par=$(mktemp -d "${TMPDIR%/}/uso-release-par.XXXXXX")
mkdir -p "$par/clicks" "$par/scen" "$par/suite"

# the jobs (each in the background; «done» is written last)
clicks_job() {
    local t0; t0=$(date +%s)
    rl_clicks "$par/clicks"
    echo $(( $(date +%s) - t0 )) > "$par/clicks/took"; touch "$par/clicks/done"
}
scen_job() {
    local t0 rc; t0=$(date +%s)
    if [[ -z "$last_tag" ]]; then
        echo "no v* tag before HEAD" > "$par/scen/out"; rc=2
    else
        { echo "\$ bash tools/scenarios/prerelease.sh ${sc_args[*]}   (the desks, the scenarios, run.sh's lines and summary)"
          bash tools/scenarios/prerelease.sh "${sc_args[@]}" < /dev/null 2>&1; } > "$par/scen/out"
        rc=$?
    fi
    echo "$rc" > "$par/scen/rc"; echo $(( $(date +%s) - t0 )) > "$par/scen/took"; touch "$par/scen/done"
}
# suite_on <host> [test …]: the copy to <host>:$suite_dir and php tests/run.php there (the named tests only) - its
# output into the suite's out, the «… passed, … failed» line into $par/suite/line; exit as the run's. On suite_host
# (Tower) under the Tower lock: never beside a run of scenarios (the nightly's, a worker's) - waited for 30 min at most
suite_on() {
    local host=$1 rc; shift
    if [[ "$host" == "$suite_host" ]]; then
        tower_take "release $ver suite" "$host" "${USO_RELEASE_TOWER_WAIT:-1800}" >> "$par/suite/out" 2>&1
        rc=$?
        if (( rc != 0 )); then
            echo "$host not free for the suite: ${TOWER_WHY:-not reachable} (waited $(( ${USO_RELEASE_TOWER_WAIT:-1800} / 60 )) min) - later again" > "$par/suite/line"
            return 1
        fi
    fi
    suite_run "$host" "$@"
    rc=$?
    [[ "$host" == "$suite_host" ]] && tower_drop
    return $rc
}
suite_run() {
    local host=$1 rc; shift
    echo "\$ rsync -a --delete --exclude .git ./ $host:$suite_dir/" >> "$par/suite/out"
    if ! rsync -a --delete --exclude .git ./ "$host:$suite_dir/" >> "$par/suite/out" 2>&1; then
        echo "rsync to $host:$suite_dir failed" > "$par/suite/line"; return 1
    fi
    echo "\$ ssh $host 'cd $suite_dir && php tests/run.php${*:+ $*}'   (it prints its failures and the sum)" >> "$par/suite/out"
    printf 'cd %q && php tests/run.php%s </dev/null\n' "$suite_dir" "$(printf ' %q' "$@")" \
        | ssh -o BatchMode=yes -o ConnectTimeout=10 "$host" bash -s > "$par/suite/run" 2>&1
    rc=$?
    cat "$par/suite/run" >> "$par/suite/out"
    grep -E '^[0-9]+ passed, [0-9]+ failed' "$par/suite/run" | tail -n 1 > "$par/suite/line"
    return $rc
}
# the suite's verdict into $par/suite/why (empty: green) and $par/suite/sum
suite_job() {
    local t0 rc line skipped why=''; t0=$(date +%s)
    : > "$par/suite/out"
    if [[ -z "$suite_host2" ]]; then
        if (( scen_bg )); then
            echo "(waiting for the scenarios: Tower is theirs until they end)" >> "$par/suite/out"
            while [[ ! -e "$par/scen/done" ]]; do sleep 5; done
        fi
        suite_on "$suite_host"; rc=$?
        line=$(cat "$par/suite/line")
        if [[ $rc -ne 0 || ! "$line" =~ ^[0-9]+\ passed,\ 0\ failed$ ]]; then
            why="the suite on $suite_host did not end with 0 failed (${line:-no summary line}; its output above)"
        fi
        echo "$suite_host: ${line:-no summary line}" > "$par/suite/sum"
    else
        suite_on "$suite_host2"; rc=$?
        line=$(cat "$par/suite/line")
        echo "$suite_host2: ${line:-no summary line}" > "$par/suite/sum"
        if [[ ! "$line" =~ ^[0-9]+\ passed,\ 0\ failed(,\ [0-9]+\ skipped\ .*)?$ ]] || { [[ "$line" != *skipped* ]] && (( rc != 0 )); }; then
            why="the suite on $suite_host2 did not end with 0 failed (${line:-no summary line}; its output above)"
        elif [[ "$line" == *skipped* ]]; then
            # what $suite_host2 can't run (no python3 there): those tests alone on $suite_host, once Tower is free
            skipped=$(sed -n 's/^SKIP  \(test[A-Za-z0-9_]*\).*/\1/p' "$par/suite/run" | sort -u | tr '\n' ' ')
            if [[ -z "${skipped// }" ]]; then
                why="$suite_host2 skipped tests but named none (SKIP lines) - nothing to run on $suite_host"
            else
                { echo; echo "($suite_host2 skipped: $skipped- they run on $suite_host$( (( scen_bg )) && echo ' once the scenarios are done'))"; } >> "$par/suite/out"
                if (( scen_bg )); then while [[ ! -e "$par/scen/done" ]]; do sleep 5; done; fi
                # shellcheck disable=SC2086
                suite_on "$suite_host" $skipped; rc=$?
                local line2; line2=$(cat "$par/suite/line")
                echo "$suite_host2: $line + on $suite_host the $(wc -w <<<"$skipped" | tr -d ' ') skipped: ${line2:-no summary line}" > "$par/suite/sum"
                if [[ $rc -ne 0 || ! "$line2" =~ ^[0-9]+\ passed,\ 0\ failed$ ]]; then
                    why="the tests $suite_host2 skipped did not end with 0 failed on $suite_host (${line2:-no summary line}; its output above)"
                fi
            fi
        fi
    fi
    echo "$why" > "$par/suite/why"
    echo $(( $(date +%s) - t0 )) > "$par/suite/took"; touch "$par/suite/done"
}

echo
echo "== [6 6b 7] side by side: the click test (this Mac), the scenarios (Tower), the suite (${suite_host2:-$suite_host after the scenarios}) - each step's block below when all are done"
sc_args=(--since "$last_tag")
(( dry )) && sc_args+=(--dry)
clicks_bg=0 scen_bg=0 suite_bg=0 par_pids=()
step_no=6 step_name='ui clicks'
if taken_quiet clicks "$h_clicks" && [[ -z ${USO_RELEASE_FLAKY:-} ]]; then echo "   6  ui clicks   taken from the run of $taken_when"
else clicks_job & par_pids+=($!); clicks_bg=1; echo "   6  ui clicks   running on this Mac        (live: tail -f $par/clicks/out)"; fi
if (( no_scenarios )); then echo "   6b scenarios   skipped (--no-scenarios)"
elif taken_quiet scenarios "$h_scen"; then echo "   6b scenarios   taken from the run of $taken_when"
else scen_job & par_pids+=($!); scen_bg=1; echo "   6b scenarios   running$( (( dry )) && echo ' (dry: the list only)' || echo ' on Tower')            (live: tail -f $par/scen/out)"; fi
if taken_quiet suite "$h_suite"; then echo "   7  suite       taken from the run of $taken_when"
else suite_job & par_pids+=($!); suite_bg=1; echo "   7  suite       running on ${suite_host2:-$suite_host}$( [[ -z "$suite_host2" ]] && (( scen_bg )) && echo ' after the scenarios')   (live: tail -f $par/suite/out)"; fi
# waiting: a line when one ends (not their output - that comes in the blocks)
par_t0=$(date +%s)
for j in clicks:'6  ui clicks' scen:'6b scenarios' suite:'7  suite'; do eval "said_${j%%:*}=0"; done
while :; do
    left=0
    for j in clicks:'6  ui clicks' scen:'6b scenarios' suite:'7  suite'; do
        k=${j%%:*} bg_var="${j%%:*}_bg" said_var="said_${j%%:*}"
        (( ${!bg_var} )) || continue
        if [[ -e "$par/$k/done" ]]; then
            (( ${!said_var} )) || { echo "   $(date '+%H:%M:%S') ${j#*:} ended after $(( $(cat "$par/$k/took") / 60 )) min $(( $(cat "$par/$k/took") % 60 )) s"; eval "$said_var=1"; }
        else
            left=1
        fi
    done
    (( left )) || break
    sleep 5
done
wait 2>/dev/null
par_pids=()
par_reds=()

# ---------------------------------------------------------------------------------------------------------------------
step 6 'ui clicks'
if (( ! clicks_bg )); then
    taken clicks "$h_clicks"
else
    rc=$(cat "$par/clicks/rc" 2>/dev/null || echo 9)
    last=$(cat "$par/clicks/summary" 2>/dev/null)
    sed 's/^/   | /' "$par/clicks/out"
    echo "   $(cat "$par/clicks/took") s, exit $rc"
    if (( rc == 3 )); then
        echo "   SKIPPED: ${last:-the click test did not run} - it needs node and playwright-core on this Mac (tools/ui-clicks.mjs)"
    elif (( rc != 0 )); then
        remember clicks "$h_clicks" red "${last:-no summary line}"
        pred "the click test failed (${last:-no summary line}; its table above)"
    else
        while IFS= read -r f; do [[ -n $f ]] && { echo "   $f"; flaky_notes+=("$f"); }; done < "$par/clicks/flaky"
        remember clicks "$h_clicks" green "$last"
        ok "$last"
    fi
fi

# ---------------------------------------------------------------------------------------------------------------------
step 6b scenarios
scen_note=''
if (( no_scenarios )); then
    scen_note="!!! NO SCENARIOS: step 6b skipped (--no-scenarios) - say why in the day's notes"
    echo "   $scen_note"
elif (( ! scen_bg )); then
    taken scenarios "$h_scen"
    scen_note="6b scenarios: taken from the run of $taken_when ($taken_what)"
else
    rc=$(cat "$par/scen/rc" 2>/dev/null || echo 9)
    took=$(cat "$par/scen/took" 2>/dev/null || echo 0)
    sc_out=$par/scen/out
    sed 's/^/   | /' "$sc_out"
    sum=$(grep -E '^[0-9]+ scenarios: ' "$sc_out" | tail -n 1)
    est=$(sed -n 's/^prerelease: \([0-9]* scenario(s), ≈ [0-9]* min\).*/\1/p' "$sc_out" | head -n 1)
    note3=$(grep -m1 -E 'no desk touched|nothing to run' "$sc_out" | sed 's/^prerelease: //')
    note4=$(grep -m1 'Tower not free' "$sc_out" | sed 's/^prerelease: //')
    echo "   $(( took / 60 )) min $(( took % 60 )) s, exit $rc"
    case $rc in
        0) if (( dry )); then scen_note="6b scenarios (dry): ${est:-see above} would run since $last_tag"
           else scen_note="6b scenarios: ${sum:-green} in $(( took / 60 )) min"; fi
           remember scenarios "$h_scen" green "${scen_note#6b scenarios: }"
           ok "$scen_note" ;;
        3) scen_note="6b scenarios: none to run ($note3)"
           remember scenarios "$h_scen" green "none to run"
           ok "$scen_note" ;;
        4) scen_note="6b scenarios: not run - ${note4:-Tower not reachable}"
           pred "Tower was not free or not reachable (above) - later again, or --no-scenarios with a reason" ;;
        *) scen_note="6b scenarios: RED after $(( took / 60 )) min (${sum:-no summary})"
           remember scenarios "$h_scen" red "${sum:-no summary}"
           # the verdict is run.sh's exit code: exit 1 with no red cell means not every scenario ran (the line to
           # Tower dropped, a base reset failed) - say that, not «not green»
           if [[ -z $sum || $sum == *" 0 red,"* ]]; then
               pred "run.sh ended $rc without a red cell (${sum:-no summary line}) - not every scenario ran (the line to Tower dropped? run.sh's last lines above say where it stopped): later again"
           else
               pred "the scenarios of the touched desks were not green ($sum; the reports: the folder in run.sh's last line above)"
           fi ;;
    esac
fi

# ---------------------------------------------------------------------------------------------------------------------
step 7 suite
(( dry )) && echo "   (dry: a copy of its own, $suite_dir - /tmp/uso-main stays the coordinator's)"
if (( ! suite_bg )); then
    taken suite "$h_suite"
else
    sed 's/^/   | /' "$par/suite/out"
    why=$(cat "$par/suite/why" 2>/dev/null) sum=$(cat "$par/suite/sum" 2>/dev/null)
    echo "   $(cat "$par/suite/took" 2>/dev/null) s: ${sum:-no summary line}"
    if [[ ! -e "$par/suite/done" ]]; then
        pred "the suite did not end (no verdict)"
    elif [[ -n "$why" ]]; then
        remember suite "$h_suite" red "$sum"
        pred "$why"
    else
        remember suite "$h_suite" green "$sum"
        ok "$sum"
    fi
fi
rm -rf "$par"
# a red one of the three: all are said above; now the version lines go back and the release stops
if (( ${#par_reds[@]} )); then
    unbump
    if (( dry )); then
        reds+=("${par_reds[@]}")
    else
        echo
        echo "STOPPED at step(s) $(printf '%s\n' "${par_reds[@]}" | sed 's/^\[\([^ ]*\) .*/\1/' | paste -sd, - | sed 's/,/, /g'):"
        printf '   %s\n' "${par_reds[@]}"
        last_lines
        echo "Log: $log"
        exit 1
    fi
fi

# ---------------------------------------------------------------------------------------------------------------------
step 8 commit
msg="Version $ver"   # no Co-Authored-By line (the owner, 2026-10-14: only dropnook as contributor)
if (( dry )); then
    echo "   (dry, not run) \$ git add src/bootstrap.php agent/agent.php"
    echo "   (dry, not run) \$ git commit -m 'Version $ver'"
    echo "   (dry, not run) \$ git push origin main"
else
    show git add src/bootstrap.php agent/agent.php
    staged=$(git diff --cached --name-only | sort | tr '\n' ' ')
    [[ "$staged" == "agent/agent.php src/bootstrap.php " ]] || red "staged is '$staged', not just the two version files"
    show git commit -q -m "$msg" || red "git commit failed"
    bumped=0
    sha=$(git rev-parse HEAD)
    show git push -q origin main || red "git push origin main failed (the commit ${sha:0:7} is local only)"
    show git fetch -q origin main
    [[ "$(git rev-parse origin/main)" == "$sha" ]] || red "origin/main is not ${sha:0:7} after the push"
    ok "Version $ver = ${sha:0:7}, pushed"
fi

# ---------------------------------------------------------------------------------------------------------------------
step 9 release
IFS=. read -r v_maj v_min v_fix <<<"$ver"
title="Version $v_maj.$v_min"; [[ "$v_fix" == 0 ]] || title="Version $ver"
if (( dry )); then
    echo "   (dry, not run) \$ gh release create v$ver --target <the Version commit> --title '$title' --notes-file $notes"
else
    show gh release create "v$ver" --target "$sha" --title "$title" --notes-file "$notes" || red "gh release create failed"
    tag_sha=$(git ls-remote origin "refs/tags/v$ver" | cut -f1)
    [[ "$tag_sha" == "$sha" ]] || red "tag v$ver points to '${tag_sha:0:7}', not ${sha:0:7}"
    ok "v$ver released ('$title') on ${sha:0:7}"
fi
fi  # not --servers-only

# ---------------------------------------------------------------------------------------------------------------------
# steps 10-12 look at a release: the new one - in a dry run the current one (what the servers should have now)
rel=$ver
if (( dry )) && (( ! servers_only )); then rel=$cur_office; echo; echo "(dry: steps 10-12 look at the CURRENT release v$rel)"; fi

step 10 action
run_line=''
deadline=$(( $(date +%s) + 600 ))
while :; do
    run_line=$(gh run list --workflow plugin.yml --limit 20 --json databaseId,status,conclusion,headBranch \
        --jq ".[] | select(.headBranch == \"v$rel\") | \"\(.databaseId) \(.status) \(.conclusion)\"" 2>/dev/null | head -n 1)
    read -r run_id run_status run_conclusion <<<"$run_line"
    [[ "${run_status:-}" == completed ]] && break
    (( $(date +%s) >= deadline )) && break
    echo "   $(date '+%H:%M:%S') ${run_line:-no run for v$rel yet} - waiting 15 s"
    sleep 15
done
if [[ -z "$run_line" ]]; then
    red "no run of plugin.yml for v$rel within 10 min (gh run list --workflow plugin.yml)"
elif [[ "$run_status" != completed ]]; then
    red "the Action for v$rel is still '$run_status' after 10 min (run $run_id)"
elif [[ "$run_conclusion" != success ]] && gh release view "v$rel" --json assets --jq '.assets[].name' 2>/dev/null | grep -q '\.plg$'; then
    # the release's own run failed, a later one (by hand: gh workflow run plugin.yml -f tag=v<x.y.z>) attached the files -
    # step 11 checks them (2026-10-10: a stale runner index broke the release's run)
    ok "run $run_id ended '$run_conclusion', but the release has its .plg (a later run) - step 11 checks it"
elif [[ "$run_conclusion" != success ]]; then
    gh run view "$run_id" --log-failed 2>/dev/null | tail -n 30 | sed 's/^/      /'
    red "the Action for v$rel ended '$run_conclusion' (gh run view $run_id)"
else
    ok "run $run_id: completed success"
fi

step 11 assets
plg_ver=''
assets=$(gh release view "v$rel" --json assets --jq '.assets[].name' 2>/dev/null)
echo "$assets" | sed 's/^/      /'
if ! grep -qx "$name.plg" <<<"$assets" || ! grep -qE "^$name-[0-9.]+[a-z]?\.txz$" <<<"$assets"; then
    red "v$rel lacks the .plg or the .txz"
else
    rel_plg=$(gh release download "v$rel" -p "$name.plg" -O - 2>/dev/null)
    plg_ver=$(plg_entity version <<<"$rel_plg")
    plg_office=$(plg_entity office <<<"$rel_plg")
    if [[ "$plg_office" != "$rel" || ! "$plg_ver" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]]; then
        red "the release's .plg says office '$plg_office', version '$plg_ver'"
    else
        ok ".plg $plg_ver (office $plg_office) and its .txz attached"
        # the title is the plugin's date version (what Unraid's plugin manager shows), the office version heads the notes
        # (owner 2026-10-18); the tag stays v<x.y.z>
        if (( ! dry )) && [[ "$(gh release view "v$rel" --json name --jq .name 2>/dev/null)" != "$plg_ver" ]]; then
            body=$(gh release view "v$rel" --json body --jq .body 2>/dev/null)
            [[ "$body" == "Unraid Secretary Office $rel"* ]] || body=$(printf 'Unraid Secretary Office %s\n\n%s' "$rel" "$body")
            if gh release edit "v$rel" --title "$plg_ver" --notes "$body" >/dev/null 2>&1; then ok "release v$rel titled '$plg_ver'"
            else echo "   note: could not retitle v$rel to '$plg_ver' (gh release edit) - do it by hand"; fi
        fi
        # the public «latest» address may answer 404 for a few seconds after the upload
        latest_url="https://github.com/$github/releases/latest/download/$name.plg"
        latest=''
        for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do
            latest=$(curl -fsL --max-time 20 "$latest_url" 2>/dev/null | plg_entity version)
            [[ "$latest" == "$plg_ver" ]] && break
            sleep 5
        done
        if [[ "$latest" == "$plg_ver" ]]; then ok "$latest_url -> $latest"
        else red "$latest_url answers version '${latest:-nothing}', not $plg_ver (after 60 s)"; fi
    fi
fi

# ---------------------------------------------------------------------------------------------------------------------
# One definition of «a long job runs»: the .plg's own guard (jobs="…" in its install and remove sections)
jobs_pat=$(sed -n 's/^jobs="\(.*\)"$/\1/p' "plugin/$name.plg" | head -n 1)
jobs_pat=${jobs_pat//'$dir'/$plugin_dir}
jobs_pat=${jobs_pat//'\$'/'$'}
[[ -n "$jobs_pat" ]] || red "no jobs=\"…\" guard pattern found in plugin/$name.plg"
agent_pat="^([^ ]*/)?php $plugin_dir/agent/agent\\.php run\$"

# server_state <host>: the read-only look - the installed .plg, OFFICE_VERSION, a long job
server_state() {
    remote "$1" name="$name" dir="$plugin_dir" jobpat="$jobs_pat" <<'EOF'
echo "plg=$(sed -n 's/.*ENTITY version *"\([^"]*\)".*/\1/p' /boot/config/plugins/$name.plg 2>/dev/null | head -n 1)"
echo "office=$(sed -n "s/^const OFFICE_VERSION *= *'\([^']*\)';.*/\1/p" $dir/src/bootstrap.php 2>/dev/null | head -n 1)"
echo "now=$(date '+%Y-%m-%d %H:%M:%S')"
pgrep -f "$jobpat" >/dev/null 2>&1 && ps -o pid=,etime=,args= -p "$(pgrep -d, -f "$jobpat")" | sed 's/^/busy=/'
exit 0
EOF
}

# server_verify <host> <office> <plg version> [<since>]: after an update (since = the server's time before it) -
# prints «ok …» / «RED …» lines, exit 1 if any is red
server_verify() {
    remote "$1" name="$name" dir="$plugin_dir" ver="$2" plgver="$3" since="${4:-}" agentpat="$agent_pat" <<'EOF'
bad=0
r() { echo "RED $*"; bad=1; }
got=$(sed -n 's/.*ENTITY version *"\([^"]*\)".*/\1/p' /boot/config/plugins/$name.plg 2>/dev/null | head -n 1)
[[ "$got" == "$plgver" ]] && echo "ok .plg $got" || r ".plg on the flash is '$got', not $plgver"
got=$(sed -n "s/^const OFFICE_VERSION *= *'\([^']*\)';.*/\1/p" $dir/src/bootstrap.php 2>/dev/null | head -n 1)
[[ "$got" == "$ver" ]] && echo "ok OFFICE_VERSION $got" || r "OFFICE_VERSION in $dir/src/bootstrap.php is '$got', not $ver"
pid=$(pgrep -f "$agentpat" | head -n 1)
[[ -n "$pid" ]] && echo "ok agent PID $pid" || r "no agent process ($dir/agent/agent.php run)"
data=$(php -r "require '$dir/src/place.php'; echo officePluginDataDir();" </dev/null 2>/dev/null)
log=$data/agent.log
if [[ ! -r "$log" ]]; then
    r "agent log $log not readable"
else
    if [[ -n "$since" ]]; then
        line=''
        for _ in $(seq 1 20); do
            line=$(awk -v c="$since" 'substr($0, 1, 19) >= c' "$log" | grep -F "Agent started (v$ver," | tail -n 1)
            [[ -n "$line" ]] && break
            sleep 3
        done
        [[ -n "$line" ]] && echo "ok $line" || r "no «Agent started (v$ver,» in $log since $since (60 s)"
    else
        line=$(grep -F "Agent started (v" "$log" | tail -n 1)
        [[ "$line" == *"Agent started (v$ver,"* ]] && echo "ok last start: $line" || r "the last start in $log is not v$ver: ${line:-none}"
    fi
    cut=$(date -d '-2 min' '+%Y-%m-%d %H:%M:%S')
    errs=$(awk -v c="$cut" 'substr($0, 1, 19) >= c' "$log" | grep -iwE 'error|fatal')
    if [[ -n "$errs" ]]; then
        r "error/fatal in $log since $cut:"; echo "$errs" | sed 's/^/      /'
    else
        echo "ok no error/fatal line in $log since $cut"
    fi
fi
exit $bad
EOF
}

# server_watch <host> <since>: the agent's PID and every error/fatal line in its log since <since> (the canary)
server_watch() {
    remote "$1" dir="$plugin_dir" since="$2" agentpat="$agent_pat" <<'EOF'
echo "pid=$(pgrep -f "$agentpat" | head -n 1)"
data=$(php -r "require '$dir/src/place.php'; echo officePluginDataDir();" </dev/null 2>/dev/null)
awk -v c="$since" 'substr($0, 1, 19) >= c' "$data/agent.log" 2>/dev/null | grep -iwE 'error|fatal' | sed 's/^/err=/'
exit 0
EOF
}

# plugin_check <host>: `plugin check` on it (downloads the public .plg to /tmp/plugins, prints its version among
# hook lines and progress) - sets $checked to the version line it printed, shows the rest
plugin_check() {
    local out
    out=$(echo "plugin check $name.plg </dev/null 2>&1" | ssh -o BatchMode=yes -o ConnectTimeout=10 "$1" bash -s | tr '\r' '\n')
    checked=$(grep -E '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$' <<<"$out" | tail -n 1)
    echo "   \$ plugin check $name.plg   -> ${checked:-no version}"
    grep -v -E '^plugin: downloading|^$' <<<"$out" | sed 's/^/      /'
}

step 12 servers
done_at=()
for host in "${run_hosts[@]}"; do
    who=$(label "$host")
    echo "   -- $who ($host)"
    if (( dry )) && [[ "$host" == "$main" && "$host" != "$canary" ]]; then
        echo "   (dry, not contacted) on $who: refuse if pgrep -f the .plg's jobs pattern finds a job; plugin check $name.plg;"
        echo "   (dry, not contacted) plugin update $name.plg; then the same checks as above"
        continue
    fi
    if [[ -z "$plg_ver" ]]; then red "$who: no .plg version from step 11 to compare with"; continue; fi
    state=$(server_state "$host"); rc=$?
    if (( rc != 0 )) || ! grep -q '^now=' <<<"$state"; then red "$who: ssh $host failed (exit $rc)"; continue; fi
    sed 's/^/      /' <<<"$state"
    s_plg=$(sed -n 's/^plg=//p' <<<"$state") s_office=$(sed -n 's/^office=//p' <<<"$state") s_now=$(sed -n 's/^now=//p' <<<"$state")
    if grep -q '^busy=' <<<"$state"; then
        red "$who: a long job runs (above) - not updated; once it is done: tools/release.sh $rel --servers-only"
        continue
    fi
    since=''
    if [[ "$s_plg" == "$plg_ver" && "$s_office" == "$rel" ]]; then
        echo "   $who is on $rel ($s_plg) already - checked, not updated"
        if (( dry )); then
            plugin_check "$host"
            [[ "$checked" == "$plg_ver" ]] || red "$who: plugin check says '${checked:-nothing}', not $plg_ver"
            echo "   (dry, not run) \$ plugin update $name.plg"
        fi
    elif (( dry )); then
        red "$who has .plg '$s_plg' / office '$s_office', not the current release's $plg_ver / $rel"
        continue
    else
        echo "   $who updates from office '$s_office' (.plg '$s_plg')"
        plugin_check "$host"
        [[ "$checked" == "$plg_ver" ]] || { red "$who: plugin check says '${checked:-nothing}', not $plg_ver"; continue; }
        since=$s_now
        echo "   \$ plugin update $name.plg"
        echo "plugin update $name.plg </dev/null 2>&1" | ssh -o BatchMode=yes -o ConnectTimeout=10 "$host" bash -s \
            | tr '\r' '\n' | grep -v -E '^plugin: downloading|^$' | sed 's/^/      /'
        rc=${PIPESTATUS[1]}
        (( rc == 0 )) || { red "$who: plugin update exited $rc (output above)"; continue; }
    fi
    verify=$(server_verify "$host" "$rel" "$plg_ver" "$since"); rc=$?
    sed 's/^/      /' <<<"$verify"
    if (( rc != 0 )); then red "$who: $(grep '^RED' <<<"$verify" | head -n 1 | cut -c5-)"; continue; fi
    done_at+=("$who $(date '+%H:%M:%S')")
    ok "$who on $rel"
    # the first server is the canary: its agent log stays free of error/fatal for 5 min after the update before the next gets it
    if [[ "$host" == "$canary" && ${#run_hosts[@]} -gt 1 ]]; then
        if (( dry )) || [[ -z "$since" ]]; then
            echo "   (dry or not updated now: no watch) after an update: $who's agent log watched ${canary_watch} s for error/fatal, the agent's PID kept"
        else
            pid0=$(server_watch "$host" "$since" | sed -n 's/^pid=//p')
            end=$(( $(date +%s) + canary_watch )) watch_red=''
            echo "   watching $who's agent log for ${canary_watch} s (error/fatal since $since, agent PID $pid0) before the next server"
            while (( $(date +%s) < end )); do
                sleep 30
                w=$(server_watch "$host" "$since")
                pid=$(sed -n 's/^pid=//p' <<<"$w")
                if grep -q '^err=' <<<"$w"; then
                    sed -n 's/^err=/      /p' <<<"$w"; watch_red="error/fatal in its agent log since the update (above)"; break
                fi
                [[ -n "$pid" && "$pid" == "$pid0" ]] || { watch_red="its agent is gone or restarted (PID $pid0 -> '${pid:-none}')"; break; }
                echo "   $(date '+%H:%M:%S') $who quiet"
            done
            [[ -z "$watch_red" ]] || { red "$who: $watch_red - the servers after it not updated"; continue; }
            ok "$who quiet for ${canary_watch} s"
        fi
    fi
done

# ---------------------------------------------------------------------------------------------------------------------
echo
if (( dry )); then
    if (( ${#reds[@]} )); then
        echo "DRY RUN: ${#reds[@]} red step(s) - a real run would stop at the first:"
        printf '   %s\n' "${reds[@]}"
        last_lines
        echo "Log: $log"
        exit 1
    fi
    echo "DRY RUN: all green."
    last_lines
    echo "Log: $log"
    exit 0
fi
echo "DONE: v$rel ($plg_ver) - ${done_at[*]:-no server}"
last_lines
(( no_main )) && echo "$(label "$main") not updated: when no run is active, tools/release.sh $rel --servers-only"
echo "Next (the release playbook): tonight's run on $(label "$main"); note the release in the day's notes."
echo "Log: $log"
exit 0
}
