#!/bin/bash
# tools/scenarios/states-runs.sh — interruptions and things running at the same time (the F states, RN-*) (H2).
# Sourced by lib.sh. A «mid-run» state is ARMED before the flow: a watcher (no lock descriptor inherited — _st_bg) waits
# until the run is really at work, waits <delay> s more and then does the interruption; the flow that follows starts the
# run as the button does. The undo stops a watcher that never fired and brings the server back (array started, what ran
# running again).
#
#   run_active <bk|mv|rs|drill>             the run is at work now (its process, as `pgrep` sees it)
#   midrun_arm <id> <run> <delay> <stop|reset|reboot> [timeout s]   / midrun_disarm <id>
#   second_run <id> <backup|moverelli>      the cron's job started now, as crond would (scripts/job.sh), in the background
#   second_run_wait <id> [s]                waits until that job ended (default 30 min)
#   parity_start <id> / parity_stop <id>    a read-only parity check (mdcmd check NOCORRECT) / cancelled, its history
#                                           line (parity-checks.log) put back as it was
#   unraid_mover_fake_running <id> / unraid_mover_fake_stop <id>
#                                           /var/run/mover.pid naming a living process whose command line says «mover»
#                                           (what Smart Mover and Jack Streamer's guard look for) — Unraid's mover itself
#                                           ends at once when there is nothing to move
#
# States: RN-ARRAY-STOP-MIDRUN-BK -MV -RS -DRILL  RN-HARD-RESET-MIDRUN  RN-REBOOT-CLEAN-MIDRUN  RN-TWO-RUNS
# RN-SETUP-WHILE-RUN  RN-PLAN-OLD  RN-AGENT-RESTART-MIDREQUEST  RN-PARITY-CHECK  RN-UNRAID-MOVER-RUNNING

declare -A ST_RUN_PGREP=(
    [bk]='[b]ackup/backup.sh'
    [mv]='[c]ustommover_run'
    [rs]='[a]gent\.php job restore [0-9A-Za-z]'
    [drill]='[a]gent\.php job restore-drill'
)
run_active() { [[ -n ${ST_RUN_PGREP[$1]:-} ]] && pgrep -f "${ST_RUN_PGREP[$1]}" >/dev/null; }

midrun_arm() {
    local id=$1 run=$2 delay=$3 what=$4 timeout=${5:-1800} dir act pid
    [[ -n ${ST_RUN_PGREP[$run]:-} ]] || { say "midrun_arm: run $run? (bk|mv|rs|drill)"; return 1; }
    dir=$(_st_dir "$id")
    case $what in
        stop)   act="/usr/local/sbin/emcmd cmdStop=Stop"
                _st_ct_note "$id"; _st_vm_note "$id" ;;
        reset)  act='echo 1 > /proc/sys/kernel/sysrq; sync; echo b > /proc/sysrq-trigger' ;;
        reboot) act='/sbin/reboot' ;;
        *) say "midrun_arm: stop|reset|reboot, not $what"; return 1 ;;
    esac
    rm -f "$dir/fired"
    pid=$(_st_bg bash -c '
        pat=$1 delay=$2 timeout=$3 dir=$4 act=$5 what=$6 end=$((SECONDS + timeout))
        until pgrep -f "$pat" >/dev/null; do ((SECONDS >= end)) && exit 0; sleep 0.5; done
        sleep "$delay"
        pgrep -f "$pat" >/dev/null || { echo "ended before" > "$dir/fired"; exit 0; }
        [[ $what == stop ]] && echo started > "$dir/array.was"
        date +%s > "$dir/fired"; logger -t uso-scenarios "midrun: $what while $pat runs"
        eval "$act"' uso-midrun-watch "${ST_RUN_PGREP[$run]}" "$delay" "$timeout" "$dir" "$act" "$what")
    printf '%s\n' "$pid" > "$dir/watch.pid"
    say "midrun: armed — $what ${delay}s after the $run run is at work (watcher pid $pid, gives up after ${timeout}s)"
}
midrun_disarm() {
    local id=$1 dir pid; dir=$(_st_dir "$id")
    if [[ -f $dir/watch.pid ]]; then
        pid=$(cat "$dir/watch.pid")
        kill -0 "$pid" 2>/dev/null && kill "$pid" 2>/dev/null
        rm -f "$dir/watch.pid"
    fi
    if [[ -f $dir/fired ]]; then say "midrun: fired ($(cat "$dir/fired"))"; rm -f "$dir/fired"; else say "midrun: never fired"; fi
    # an array stop the watcher started: started again with what ran before, whatever it left (array_start waits for a
    # stop still unmounting — mdState stays STARTED meanwhile, so «STARTED or STOPPED» read it as started: Tower
    # 2026-10-20 07:56, the array left stopped and every scenario after it on a stopped array)
    if [[ -f $dir/array.was ]]; then
        array_start "$id" || return 1
    fi
    rm -f "$dir/ct.running" "$dir/ct.paused" "$dir/vm.running"
    return 0
}

_st_midrun() { local id=$1 run=$2; shift 2; midrun_arm "$id" "$run" "${1:-10}" stop "${2:-1800}"; }
state_rn_array_stop_midrun_bk() { _st_midrun RN-ARRAY-STOP-MIDRUN-BK bk "$@"; }
undo_rn_array_stop_midrun_bk() { midrun_disarm RN-ARRAY-STOP-MIDRUN-BK; }
state_rn_array_stop_midrun_mv() { _st_midrun RN-ARRAY-STOP-MIDRUN-MV mv "$@"; }
undo_rn_array_stop_midrun_mv() { midrun_disarm RN-ARRAY-STOP-MIDRUN-MV; }
state_rn_array_stop_midrun_rs() { _st_midrun RN-ARRAY-STOP-MIDRUN-RS rs "$@"; }
undo_rn_array_stop_midrun_rs() { midrun_disarm RN-ARRAY-STOP-MIDRUN-RS; }
state_rn_array_stop_midrun_drill() { _st_midrun RN-ARRAY-STOP-MIDRUN-DRILL drill "$@"; }
undo_rn_array_stop_midrun_drill() { midrun_disarm RN-ARRAY-STOP-MIDRUN-DRILL; }
# a power cut / a clean reboot mid-run: the server goes down — needs reboot (the runner's -pre/-post halves); the undo
# here only disarms a watcher that never fired, the -post half finds the server rebooted
state_rn_hard_reset_midrun() { midrun_arm RN-HARD-RESET-MIDRUN "${1:-bk}" "${2:-10}" reset "${3:-1800}"; }
undo_rn_hard_reset_midrun() { midrun_disarm RN-HARD-RESET-MIDRUN; }
state_rn_reboot_clean_midrun() { midrun_arm RN-REBOOT-CLEAN-MIDRUN "${1:-bk}" "${2:-10}" reboot "${3:-1800}"; }
undo_rn_reboot_clean_midrun() { midrun_disarm RN-REBOOT-CLEAN-MIDRUN; }

second_run() {
    local id=$1 job=${2:-backup} run pid
    case $job in backup) run=bk ;; moverelli) run=mv ;; *) say "second_run: backup|moverelli, not $job"; return 1 ;; esac
    run_active "$run" && { say "second_run: a $job run is at work already"; return 1; }
    # job.sh starts a job once a minute (a second start in the same minute ends quietly) — and the engine makes one real
    # backup a minute: wait for a minute of its own
    if [[ $(cat "$RUN/job-$job.minute" 2>/dev/null) == "$(date +%Y%m%d%H%M)" ]]; then
        say "second_run: $job started this minute already — waiting for the next"
        _st_until 65 bash -c "[[ \$(cat '$RUN/job-$job.minute' 2>/dev/null) != \$(date +%Y%m%d%H%M) ]]"
    fi
    pid=$(_st_bg bash "$P/scripts/job.sh" "$job")
    printf '%s\n' "$pid" > "$(_st_dir "$id")/job.pid"
    _st_until 30 run_active "$run" || { say "second_run: the $job job (pid $pid) isn't at work after 30 s"; return 1; }
    say "second_run: the cron's $job job is at work (pid $pid)"
}
second_run_wait() {
    local f pid; f=$(_st_dir "$1")/job.pid
    [[ -f $f ]] || return 0
    pid=$(cat "$f")
    _st_until "${2:-1800}" bash -c "! kill -0 $pid" || { say "second_run_wait: job $pid still at work"; return 1; }
    _st_until 60 bash -c '! pgrep -f "[b]ackup/backup.sh|[c]ustommover_run"' || { say "second_run_wait: a run is still at work"; return 1; }
    rm -f "$f"
}

state_rn_two_runs() { second_run RN-TWO-RUNS "${1:-backup}"; }
undo_rn_two_runs() { second_run_wait RN-TWO-RUNS; }
# Apply while a run holds the engine's lock
state_rn_setup_while_run() {
    second_run RN-SETUP-WHILE-RUN backup || return
    _st_until 60 bash -c "! flock -n '$UB/state/lock' true" || { say "RN-SETUP-WHILE-RUN: the engine's lock isn't held"; return 1; }; }
undo_rn_setup_while_run() { second_run_wait RN-SETUP-WHILE-RUN; }

# settings.ini newer than the plan the page holds (a second tab, a setup in the terminal): only its time changes
state_rn_plan_old() { local f=$UB/settings.ini dir
    [[ -f $f ]] || { say "RN-PLAN-OLD: no settings.ini (no setup yet)"; return 1; }
    dir=$(_st_dir RN-PLAN-OLD); stat -c %Y "$f" > "$dir/settings.mtime"
    [[ -f $UB/setup-plan.json ]] || say "note: no setup-plan.json yet — the flow asks for a plan first, then this state again"
    touch "$f"; say "RN-PLAN-OLD: settings.ini touched (newer than any plan made before)"; }
undo_rn_plan_old() { local dir; dir=$(_st_dir RN-PLAN-OLD)
    [[ -f $dir/settings.mtime ]] || return 0
    touch -d "@$(cat "$dir/settings.mtime")" "$UB/settings.ini" && rm -f "$dir/settings.mtime"; }

# the agent restarts (a changed file of its own) while a request waits in the mailbox: armed, fires on the next request
# the agent restarts in place (exec: the same pid) — its heartbeat's `started` says a restart happened
_st_agent_pid() { jq -r '.started // empty' "$RUN/agent.json" 2>/dev/null; }
state_rn_agent_restart_midrequest() { local dir pid f=$P/agent/agent.php
    dir=$(_st_dir RN-AGENT-RESTART-MIDREQUEST)
    [[ -f $f ]] || { say "RN-AGENT-RESTART-MIDREQUEST: no $f"; return 1; }
    stat -c %Y "$f" > "$dir/agent.mtime"; _st_agent_pid > "$dir/agent.pid"; rm -f "$dir/fired"
    # the agent takes a request within milliseconds (its doorbell): inotify sees the request land (the page writes
    # .<id>.tmp and renames it to <id>.request), polling would miss it
    command -v inotifywait >/dev/null || { say "needs inotifywait"; return 2; }
    pid=$(_st_bg bash -c '
        box=$1 f=$2 dir=$3
        while read -r n; do
            [[ $n == *.request ]] || continue
            touch "$f"; date +%s > "$dir/fired"; logger -t uso-scenarios "agent restart while a request waits"; break
        done < <(timeout 1800 inotifywait -q -m -e moved_to -e create --format %f "$box")
        pkill -f "inotifywait -q -m -e moved_to -e create --format %f $box"' uso-midreq-watch "$D/mailbox" "$f" "$dir")
    printf '%s\n' "$pid" > "$dir/watch.pid"
    say "RN-AGENT-RESTART-MIDREQUEST: armed (watcher pid $pid) — agent.php is touched when the next request arrives"; }
undo_rn_agent_restart_midrequest() { local dir pid; dir=$(_st_dir RN-AGENT-RESTART-MIDREQUEST)
    [[ -f $dir/watch.pid ]] && { pid=$(cat "$dir/watch.pid"); kill "$pid" 2>/dev/null; rm -f "$dir/watch.pid"; }
    if [[ -f $dir/fired && -f $dir/agent.mtime ]]; then
        pid=$(_st_agent_pid)
        touch -d "@$(cat "$dir/agent.mtime")" "$P/agent/agent.php"
        # the agent sees its file change again and restarts once more: wait for that one
        _st_until 90 bash -c "p=\$(jq -r '.started // empty' '$RUN/agent.json' 2>/dev/null); [[ -n \$p && \$p != '$pid' ]] && (( \$(date +%s) - \$(stat -c %Y '$RUN/agent.json') < 30 ))" \
            || say "note: no new agent heartbeat after agent.php got its time back"
    elif [[ -f $dir/agent.mtime ]]; then
        touch -d "@$(cat "$dir/agent.mtime")" "$P/agent/agent.php"
    fi
    rm -f "$dir/fired" "$dir/agent.mtime" "$dir/agent.pid"; }

_st_resync() { /usr/local/sbin/mdcmd status | awk -F= '$1 == "mdResyncPos" { print $2 }'; }
parity_start() {
    local dir; dir=$(_st_dir "$1")
    [[ $(_st_var mdState) == STARTED ]] || { say "parity_start: the array isn't started"; return 1; }
    [[ $(_st_resync) == 0 ]] || { say "parity_start: a parity operation runs already"; return 1; }
    if [[ -f /boot/config/parity-checks.log ]]; then cp -p /boot/config/parity-checks.log "$dir/parity-checks.log"; else : > "$dir/parity-checks.none"; fi
    /usr/local/sbin/mdcmd check NOCORRECT || return 1
    _st_until 15 bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))' \
        || { say "parity_start: no check running after 15 s"; return 1; }
    say "parity_start: a read-only parity check runs"
}
parity_stop() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/parity-checks.log || -f $dir/parity-checks.none ]] || return 0
    [[ $(_st_resync) == 0 ]] || /usr/local/sbin/mdcmd nocheck
    _st_until 60 _st_is 0 _st_resync || { say "parity_stop: still running"; return 1; }
    sleep 2      # Unraid writes the history line just after the stop
    if [[ -f $dir/parity-checks.log ]]; then cp -p "$dir/parity-checks.log" /boot/config/parity-checks.log; else rm -f /boot/config/parity-checks.log; fi
    rm -f "$dir/parity-checks.log" "$dir/parity-checks.none"
}
state_rn_parity_check() { parity_start RN-PARITY-CHECK; }
undo_rn_parity_check() { parity_stop RN-PARITY-CHECK; }

unraid_mover_fake_running() {
    local id=$1 pid old
    old=$(cat /var/run/mover.pid 2>/dev/null)
    [[ -n $old && -d /proc/$old ]] && { say "unraid_mover_fake_running: Unraid's mover runs for real (pid $old)"; return 1; }
    pid=$(_st_bg bash -c 'exec -a uso-fake-mover sleep 3600')
    _st_until 5 grep -q mover "/proc/$pid/cmdline" || { say "unraid_mover_fake_running: no fake mover"; return 1; }
    [[ -e /var/run/mover.pid ]] && cp -p /var/run/mover.pid "$(_st_dir "$id")/mover.pid.before"
    printf '%s\n' "$pid" > /var/run/mover.pid
    printf '%s\n' "$pid" > "$(_st_dir "$id")/mover.fake"
    say "unraid_mover_fake_running: /var/run/mover.pid → $pid (cmdline «uso-fake-mover»)"
}
unraid_mover_fake_stop() {
    local dir pid; dir=$(_st_dir "$1")
    [[ -f $dir/mover.fake ]] || return 0
    pid=$(cat "$dir/mover.fake")
    [[ $(cat /var/run/mover.pid 2>/dev/null) == "$pid" ]] && rm -f /var/run/mover.pid
    [[ -f $dir/mover.pid.before ]] && mv "$dir/mover.pid.before" /var/run/mover.pid
    [[ $(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null) == uso-fake-mover* ]] && kill "$pid"
    _st_until 5 test ! -d "/proc/$pid" && rm -f "$dir/mover.fake"
}
state_rn_unraid_mover_running() { unraid_mover_fake_running RN-UNRAID-MOVER-RUNNING; }
undo_rn_unraid_mover_running() { unraid_mover_fake_stop RN-UNRAID-MOVER-RUNNING; }
