#!/bin/bash
# Cell B12 of matrix.md: Unraid's ⟦Stop⟧ while a backup run is at work (9 s in: the apps paused, Nextcloud in maintenance
# mode). The run ends «aborted / array_stopping», never «failed»; nothing of the engine stays mounted; after ⟦Start⟧ the
# office's own backup.sh --recover (event/started → atd) brings back what the run had paused and takes Nextcloud out of
# maintenance mode — the array is started here WITHOUT the states' own container bring-back, so only the office's
# recovery and Docker's autostart count (CT-AUTOSTART: the demo set on it, as a user's server has it — without it the
# databases stay down after the stop, and Nextcloud can't leave maintenance mode; the undo brings back anything
# missed). The snapshot names stay unique (one set per minute) and the next run (INV-BACKUP-AFTER) is clean.
# Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-array-stop-midrun-bk-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
notes_gone() { [[ ! -s $UB/state/stopped && ! -s $UB/state/maintenance && ! -s $UB/state/vms ]] && ! lock_held "$UB/state/lock"; }
snaps_dup() { zfs list -H -t snapshot -o name 2>/dev/null | grep '@uso-backup-' | sort | uniq -d; }
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after an array stop\n' >"$KEEP/.skip"; scenario_end; }
nc_data_closed
state CT-AUTOSTART
state RN-ARRAY-STOP-MIDRUN-BK 9 300          # its note of what runs now (ct.running) is what must come back
dir=$(_st_dir RN-ARRAY-STOP-MIDRUN-BK)
snap_before
agent_mark_restart                           # the array stop ends the agent; a new one after the start
_bk_setup_free && minute_wait
ask backup.start '{"mode":"backup"}' >/dev/null
expect_ok backup.start
if wait_for 300 "the array stop mid-run" _st_is STOPPED _st_var mdState; then
    say "array stopped while the run was at work ($(cat "$dir/fired" 2>/dev/null || echo '?'))"
    # ⟦Start⟧ as Unraid's Main page sends it — the states' array_start would also start the noted containers itself
    _st_emcmd cmdStart=Start
    wait_for 300 "the array started again" _st_is Started _st_var fsState
    agent_wait 300
    wait_for 600 "the office's backup.sh --recover to bring back what the run left" notes_gone
    run=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
    r=$(jq -r '"\(.result)/\(.message // "")"' "$UB/state/status.json" 2>/dev/null)
    [[ $r == aborted/array_stopping ]] && cell aborted 1 "run $run: aborted / array_stopping" \
        || cell aborted 0 "run $run ended «$r» (must be aborted/array_stopping, never failed)"
    h=$(state_json backup '[.history[]? | select(.run == "'"$run"'") | .result] | join(",")' 2>/dev/null)
    o=$(state_json backup '"\(.status.result // "")/\(.status.message // "")/\(.status.interrupted // false)"' 2>/dev/null)
    [[ $h == aborted && $o == aborted/array_stopping/false ]] && cell office-says 1 "his page: the run aborted for the array stop (history «$h»)" \
        || cell office-says 0 "his page: status «$o», history of $run «$h»"
    missing=()
    while IFS= read -r c; do [[ -n $c && $(ct_status "$c") != running ]] && missing+=("$c ($(ct_status "$c"))"); done <"$dir/ct.running"
    ((${#missing[@]} == 0)) && cell back 1 "every container that ran before the run runs again ($(wc -l <"$dir/ct.running"))" \
        || cell back 0 "not back after the office's --recover: ${missing[*]}"
    rl=$(ls -t "$UB"/logs/recover*.log 2>/dev/null | head -1)
    [[ -n $rl ]] && evidence "$rl" recover.log >/dev/null
    evidence "$UB/logs/run-$run.log" run-aborted.log >/dev/null 2>&1
else
    cell aborted 0 "the array was never stopped mid-run (watcher: $(cat "$dir/fired" 2>/dev/null || echo 'never fired'))"
fi
# the agent stops with the array by design (CLAUDE.md: «event/started starts, event/stopping stops»): no heartbeat meanwhile
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
check final
d=$(snaps_dup)
[[ -z $d ]] && cell snapshots-unique 1 "every @uso-backup snapshot name once ($(zfs list -H -t snapshot -o name 2>/dev/null | grep -c '@uso-backup-') in all)" \
    || cell snapshots-unique 0 "twice: ${d//$'\n'/ }"
scenario_end
