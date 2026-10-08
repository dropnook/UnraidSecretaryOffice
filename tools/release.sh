#!/bin/bash
# Unraid Secretary Office - the mechanical part of a release (for the coordinator, on the Mac).
#
#   tools/release.sh <x.y.z> [--dry] [--no-nostromo] [--servers-only]
#
# Run it from the clone on the Mac. What must be done and checked BEFORE it (suite, browser smoke, update test from
# the previous version) and the rules around it (order of the servers, the quiet day after a release) are in
# ~/Claude/UnraidSecretaryOffice-briefs/release-playbook.md. The script stops at the first red step with one line
# that says why. Its steps:
#
#    1 tools      git, gh (logged in), rsync, ssh, curl there; the version looks like x.y.z
#    2 clone      on main, nothing uncommitted, main == origin/main; the tag v<x.y.z> not taken yet
#    3 notes      ~/Claude/UnraidSecretaryOffice-briefs/release-<x.y.z>-notes.md exists and isn't empty
#    4 bump       OFFICE_VERSION (src/bootstrap.php) == AGENT_VERSION (agent/agent.php), both older than x.y.z;
#                 both set to x.y.z
#    5 build      bash plugin/build.sh <x.y.z> passes on the Mac (what the Action runs: versions, the .plg's max)
#    6 suite      rsync to uso-test:/tmp/uso-main/, php tests/run.php there ends «… passed, 0 failed»
#                 (a red build or suite puts the two version lines back)
#    7 commit     «Version x.y.z» (+ Co-Authored-By), push main, origin/main is that commit
#    8 release    gh release create v<x.y.z> --target <that commit> --title «Version x.y» (x.y.z for a fix)
#                 --notes-file <notes>; the tag points to the commit
#    9 action     the Action plugin.yml for v<x.y.z> finished green (polled, at most 10 min)
#   10 assets     the release has the .plg and the .txz; its .plg names office x.y.z; the public «latest» address
#                 serves that .plg (what `plugin check` reads)
#   11 servers    USOPartner (uso-partner) -> Tower (uso-test) -> nostromo (unless --no-nostromo), each:
#                 reachable; no long job running (the .plg's own guard pattern: backup run/setup, restore, drill,
#                 Jack Emby's runs) - refused, never waited for; plugin check names the new .plg version;
#                 plugin update; then the installed .plg's version, OFFICE_VERSION in the installed bootstrap.php,
#                 the agent process, «Agent started (vx.y.z,» in the agent log within 60 s, no line with the word
#                 error/fatal in the agent log's last 2 min (printed if any).
#                 A server already on x.y.z is not updated again, only checked.
#
#   --dry           prints every command; runs only the read-only ones: steps 1-3, the version check (no bump), the
#                   build with the CURRENT version (into dist/, ignored by git), the suite on a copy of its own
#                   (uso-test:/tmp/uso-release-dry/), steps 9-10 against the CURRENT release, and on USOPartner and
#                   Tower `plugin check` plus the checks against what is installed. Nothing is bumped, committed,
#                   pushed, released or updated; nostromo is not contacted at all. A red step does not stop a dry
#                   run: every red step is listed at the end (exit 1 if any).
#   --no-nostromo   stop after Tower - nostromo later, when no run is active: `tools/release.sh <x.y.z> --servers-only`
#   --servers-only  the release is out already: only steps 1, 9, 10, 11 (resume after a red server step)
#
# Log: ~/Claude/UnraidSecretaryOffice-briefs/releases/<x.y.z>.log - every run appended: all it prints, the suite's
# output and every server's answers included. Exit: 0 done, 1 a red step, 2 wrong call.
#
# No `set -e`: every step checks what it ran and says what went wrong.
set -u -o pipefail

briefs=${USO_BRIEFS:-$HOME/Claude/UnraidSecretaryOffice-briefs}
coauthor='Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>'
name=unraid-secretary-office
plugin_dir=/usr/local/emhttp/plugins/$name
suite_host=uso-test

usage() { sed -n '4p' "${BASH_SOURCE[0]}" | sed 's/^# *//' >&2; exit 2; }

ver='' dry=0 no_nostromo=0 servers_only=0
for a in "$@"; do
    case $a in
        --dry) dry=1 ;;
        --no-nostromo) no_nostromo=1 ;;
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
mkdir -p "$briefs/releases" || { echo "release: cannot create $briefs/releases" >&2; exit 2; }
log="$briefs/releases/$ver.log"
notes="$briefs/release-$ver-notes.md"
exec > >(tee -a "$log") 2>&1

servers=(uso-partner uso-test)
(( no_nostromo )) || servers+=(nostromo)
label() { case $1 in uso-partner) echo USOPartner ;; uso-test) echo Tower ;; *) echo "$1" ;; esac; }

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
    echo "Log: $log"
    exit 1
}
# show: print a command, run it (every command that runs is shown)
show() { echo "   \$ $*"; "$@"; }
# change: a command that changes something - only printed in a dry run
change() {
    if (( dry )); then echo "   (dry, not run) \$ $*"; return 0; fi
    echo "   \$ $*"; "$@"
}
trap 'echo; echo "INTERRUPTED at step $step_no ($step_name). Log: $log"; exit 1' INT TERM

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
echo "=== $(date '+%Y-%m-%d %H:%M:%S') tools/release.sh $ver$( ((dry)) && echo ' --dry')$( ((no_nostromo)) && echo ' --no-nostromo')$( ((servers_only)) && echo ' --servers-only')  ($(git -C "$repo" rev-parse --short HEAD 2>/dev/null) in $repo)"
(( dry )) && echo "DRY RUN: nothing is bumped, committed, pushed, released or updated; nostromo is not contacted."

# ---------------------------------------------------------------------------------------------------------------------
step 1 tools
missing=()
for t in git gh rsync ssh curl sed awk; do command -v "$t" >/dev/null 2>&1 || missing+=("$t"); done
if (( ${#missing[@]} )); then red "missing on this Mac: ${missing[*]}"
elif ! gh auth status >/dev/null 2>&1; then red "gh is not logged in (gh auth status)"
else ok "git gh rsync ssh curl; gh logged in; version $ver"; fi
github=$(plg_entity github < "plugin/$name.plg")
[[ -n "$github" ]] || red "no ENTITY github in plugin/$name.plg"

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

# ---------------------------------------------------------------------------------------------------------------------
step 4 bump
bumped=0
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
# a red build or suite after the bump puts the two lines back - the clone stays as it was
unbump() { (( bumped )) && { git checkout -- src/bootstrap.php agent/agent.php; echo "   (the two version lines are put back)"; bumped=0; }; return 0; }

# ---------------------------------------------------------------------------------------------------------------------
step 5 build
build_ver=$ver
(( dry )) && build_ver=$cur_office && echo "   (dry: built with the current version $cur_office - the bump is not made)"
if [[ -n "$build_ver" ]] && show bash plugin/build.sh "$build_ver"; then
    ok "plugin/build.sh $build_ver"
else
    unbump; red "plugin/build.sh $build_ver failed - the Action would fail the same way"
fi

# ---------------------------------------------------------------------------------------------------------------------
step 6 suite
suite_dir=/tmp/uso-main
(( dry )) && suite_dir=/tmp/uso-release-dry && echo "   (dry: a copy of its own, $suite_host:$suite_dir - /tmp/uso-main stays the coordinator's)"
suite_out=$(mktemp "${TMPDIR:-/tmp}/uso-release-suite.XXXXXX")
started=$(date +%s)
if ! show rsync -a --delete --exclude .git ./ "$suite_host:$suite_dir/"; then
    unbump; red "rsync to $suite_host:$suite_dir failed"
else
    echo "   \$ ssh $suite_host 'cd $suite_dir && php tests/run.php'   (it prints its failures and the sum)"
    printf 'cd %q && php tests/run.php </dev/null\n' "$suite_dir" \
        | ssh -o BatchMode=yes -o ConnectTimeout=10 "$suite_host" bash -s > "$suite_out" 2>&1
    rc=$?
    sed 's/^/   | /' "$suite_out"
    last=$(grep -E '^[0-9]+ passed, [0-9]+ failed$' "$suite_out" | tail -n 1)
    echo "   $(( $(date +%s) - started )) s, exit $rc: ${last:-no summary line}"
    if [[ $rc -ne 0 || ! "$last" =~ ^[0-9]+\ passed,\ 0\ failed$ ]]; then
        unbump; red "the suite on $suite_host did not end with 0 failed (${last:-no summary line}; its output above)"
    else
        ok "$last"
    fi
fi
rm -f "$suite_out"

# ---------------------------------------------------------------------------------------------------------------------
step 7 commit
msg=$(printf 'Version %s\n\n%s\n' "$ver" "$coauthor")
if (( dry )); then
    echo "   (dry, not run) \$ git add src/bootstrap.php agent/agent.php"
    echo "   (dry, not run) \$ git commit -m 'Version $ver' -m '$coauthor'"
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
step 8 release
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
# steps 9-11 look at a release: the new one - in a dry run the current one (what the servers should have now)
rel=$ver
if (( dry )) && (( ! servers_only )); then rel=$cur_office; echo; echo "(dry: steps 9-11 look at the CURRENT release v$rel)"; fi

step 9 action
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
elif [[ "$run_conclusion" != success ]]; then
    gh run view "$run_id" --log-failed 2>/dev/null | tail -n 30 | sed 's/^/      /'
    red "the Action for v$rel ended '$run_conclusion' (gh run view $run_id)"
else
    ok "run $run_id: completed success"
fi

step 10 assets
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

# plugin_check <host>: `plugin check` on it (downloads the public .plg to /tmp/plugins, prints its version among
# hook lines and progress) - sets $checked to the version line it printed, shows the rest
plugin_check() {
    local out
    out=$(echo "plugin check $name.plg </dev/null 2>&1" | ssh -o BatchMode=yes -o ConnectTimeout=10 "$1" bash -s | tr '\r' '\n')
    checked=$(grep -E '^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$' <<<"$out" | tail -n 1)
    echo "   \$ plugin check $name.plg   -> ${checked:-no version}"
    grep -v -E '^plugin: downloading|^$' <<<"$out" | sed 's/^/      /'
}

step 11 servers
done_at=()
for host in "${servers[@]}"; do
    who=$(label "$host")
    echo "   -- $who ($host)"
    if (( dry )) && [[ "$host" == nostromo ]]; then
        echo "   (dry, not contacted) on nostromo: refuse if pgrep -f the .plg's jobs pattern finds a job; plugin check $name.plg;"
        echo "   (dry, not contacted) plugin update $name.plg; then the same checks as above"
        continue
    fi
    if [[ -z "$plg_ver" ]]; then red "$who: no .plg version from step 10 to compare with"; continue; fi
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
done

# ---------------------------------------------------------------------------------------------------------------------
echo
if (( dry )); then
    if (( ${#reds[@]} )); then
        echo "DRY RUN: ${#reds[@]} red step(s) - a real run would stop at the first:"
        printf '   %s\n' "${reds[@]}"
        echo "Log: $log"
        exit 1
    fi
    echo "DRY RUN: all green. Log: $log"
    exit 0
fi
echo "DONE: v$rel ($plg_ver) - ${done_at[*]:-no server}"
(( no_nostromo )) && echo "nostromo not updated: when no run is active, tools/release.sh $rel --servers-only"
echo "Next (release-playbook.md): 24 h quiet except hotfixes; tonight's run on nostromo; note the release in the day's notes."
echo "Log: $log"
exit 0
