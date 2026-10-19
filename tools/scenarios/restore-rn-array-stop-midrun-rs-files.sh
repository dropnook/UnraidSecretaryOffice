#!/bin/bash
# Cell R5 of matrix.md, the restore: Unraid's ⟦Stop⟧ while Mr. Restori copies EmbyServer's app folder back beside the
# live one (RS-FILES beside, appdata/emby with 4 × 512 MB of ours in it, from the moment the run just made; the stop as
# soon as his job is at work). The job ends at once: after ⟦Start⟧ his journal says failed / restore_array_stopping and
# names the step it stopped at, nothing of his is still «running», nobody holds the engine's lock, and the half copy
# beside the folder doesn't stay looking like a restore (removed, or «Put back» offered to take it away). Docker's
# autostart for the demo set (CT-AUTOSTART, as a user's server has it) brings the apps back; the array is started
# without the states' own container bring-back. atd's queue after the start is named in the report. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-array-stop-midrun-rs-files
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# Unraid's array start queues an at job of its own (`sleep 10; …/reload_services`): no leftover of the office's
# (INV-LEFTOVER looks at atd's queue) — waited for before the checks
atq_quiet() { [[ -z $(atq 2>/dev/null) ]]; }
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after an array stop\n' >"$KEEP/.skip"; scenario_end; }
U=/mnt/user/appdata/emby                     # EmbyServer's app folder: he restores app and VM folders
put_files R5 cache appdata/emby/uso-wip-d-r5 4 --size 512M || _row state put_files red "the files could not be written"
undo_push files_unput R5
undo_push bash -c 'rm -rf /mnt/cache/appdata/emby/uso-wip-d-r5 /mnt/cache/appdata/emby.aside-* /mnt/cache/appdata/emby.restored-* /mnt/cache/appdata/emby.putback-*'
nc_data_closed
state CT-AUTOSTART
flow BK-RUN-MANUAL mode=backup               # the moment
state RN-ARRAY-STOP-MIDRUN-RS 0 300
dir=$(_st_dir RN-ARRAY-STOP-MIDRUN-RS)
snap_before
agent_mark_restart
(flow_rs_files mode=beside path="$U" >/dev/null 2>&1) & fpid=$!
if wait_for 300 "the array stop while the restore copies" _st_is STOPPED _st_var mdState; then
    _st_emcmd cmdStart=Start
    wait_for 300 "the array started again" _st_is Started _st_var fsState
    agent_wait 300
    nc_data_closed                           # Unraid's array start opened Nextcloud's data folder (0777) — not this cell's
    wait "$fpid"
    rid=$(fl_last_restore)
    j=$D/restore/$rid/journal.json
    evidence "$j" journal.json >/dev/null 2>&1
    r=$(jq -r '"\(.result)/\(.reason // "")"' "$j" 2>/dev/null)
    [[ $r == failed/restore_array_stopping ]] && cell ended 1 "restore $rid: $r" || cell ended 0 "restore $rid ended «$r» (must be failed / restore_array_stopping)"
    at=$(jq -r '[.steps | to_entries[] | select(.value.state != "ok" and .value.state != "skipped")][0] | "\(.key + 1) \(.value.do) \(.value.state)"' "$j" 2>/dev/null)
    after=$(jq -r '[.steps | to_entries[] | select(.value.state == "running" or .value.state == "queued")] | length' "$j" 2>/dev/null)
    [[ -n $at && $at != null* && $after == 0 ]] && cell stopped-at-step 1 "stopped at step $at; no step left «running»" \
        || cell stopped-at-step 0 "the journal names no step it stopped at ($at), $after step(s) still «running»"
    row=$(state_json restore '[.restores[]? | select(.id == "'"$rid"'")][0] | "\(.result) can_putback=\(.can_putback)"' 2>/dev/null)
    # a copy beside replaces nothing: what the stop leaves is a half copy <folder>.restored-<stamp> — removed, or
    # «Put back» offered to take it away, never left looking like a finished restore
    half=$(find /mnt/cache/appdata -maxdepth 1 -name 'emby.restored-*' -printf '%f ' 2>/dev/null)
    hs=$(du -sm /mnt/cache/appdata/emby.restored-* 2>/dev/null | awk '{s+=$1} END {print s+0}'); ws=$(du -sm /mnt/cache/appdata/emby | awk '{print $1}')
    say "after the start: beside the folder ${half:-nothing} (${hs} MiB of the live ${ws} MiB); his page: $row"
    if [[ -z $half || $row == *can_putback=true ]]; then cell half-copy 1 "${half:+the half copy $half — «Put back» offered}${half:-no half copy left}"
    else cell half-copy 0 "the half copy ${half}(${hs} of ${ws} MiB) stays beside the folder, named like a restore, and nothing offers to take it away ($row)"; fi
    run=$(state_json restore '.running // null | tostring' 2>/dev/null)
    [[ $run == null ]] && cell nothing-running 1 "no restore of his «running»" || cell nothing-running 0 "his page says a restore runs: $run"
else
    cell ended 0 "the array was never stopped while the restore copied (watcher: $(cat "$dir/fired" 2>/dev/null || echo 'never fired'))"
    wait "$fpid"
fi
WAIT_SOFT=1 wait_for 90 "Unraid's own at job after the array start" atq_quiet
for j in $(atq 2>/dev/null | cut -f1); do say "atd's queue after the start: $(atq | grep "^$j" | tr '\t' ' ') — $(at -c "$j" 2>/dev/null | tail -2 | head -1 | cut -c1-160)"; done
# the agent stops with the array by design (CLAUDE.md: «event/started starts, event/stopping stops»): no heartbeat meanwhile
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
check final
scenario_end
