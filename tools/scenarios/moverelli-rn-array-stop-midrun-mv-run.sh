#!/bin/bash
# Cell M6 of matrix.md, the array stop: Unraid's ⟦Stop⟧ while Ms. Moverelli's real run moves 3000 × 1 MB (40 days old,
# uso-yes → the array; 3 s in). Smart Mover dies with the array (its process group); Unraid's move copies first, so
# a half copy <name>.partial may stay on the target disk. After ⟦Start⟧: her history says the run was cut short
# (stopped/aborted — never «ok», never «running» for ever), nobody holds her locks (INV-NO-STALE-LOCK), and her next
# run removes the leftover .partial whose source still exists (custommover_run.sh: «removes leftover <rel>.partial
# copies») and moves the rest — no file lost, none twice (INV-FILES, INV-COUNTS). Docker's autostart for the demo
# set (CT-AUTOSTART) brings the apps back after the start. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-array-stop-midrun-mv-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# put_files --age writes with the clock set back (states' clock_back): an agent napping across that step back sleeps
# until a request rings its doorbell (agent/agent.php agentNap: a relative wait from wall-clock time — the batch's F12),
# its heartbeat stops meanwhile. Not this cell's: her page's look rings it, then a fresh heartbeat before snap_before
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
# Unraid's array start queues an at job of its own (`sleep 10; …/reload_services`): no leftover of the office's
# (INV-LEFTOVER looks at atd's queue) — waited for before the checks
atq_quiet() { [[ -z $(atq 2>/dev/null) ]]; }
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after an array stop\n' >"$KEEP/.skip"; scenario_end; }
partials() { find /mnt/disk*/uso-yes /mnt/cache/uso-yes -name '*.partial' 2>/dev/null; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m6 -type f ! -name '*.partial' 2>/dev/null | wc -l; }
state CT-AUTOSTART
# 3000 × 1 MB: she hands Unraid's move one file at a time, so many small files make a long run — Unraid needs ~16 s
# from ⟦Stop⟧ to the unmount (Docker first), and 6 × 1.25 GiB were all moved in that time
put_files M6 cache uso-yes/uso-wip-d-m6 3000 --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M6
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m6'
track_share uso-yes
state RN-ARRAY-STOP-MIDRUN-MV 3 300
dir=$(_st_dir RN-ARRAY-STOP-MIDRUN-MV)
fresh_beat
snap_before
agent_mark_restart
t0=$(( $(_sc_now) - 1 ))
(flow_mv_run >/dev/null 2>&1) & fpid=$!
if wait_for 300 "the array stop while her run moves" _st_is STOPPED _st_var mdState; then
    _st_emcmd cmdStart=Start
    wait_for 300 "the array started again" _st_is Started _st_var fsState
    agent_wait 300
    nc_data_closed                           # Unraid's array start opened Nextcloud's data folder (0777) — not this cell's
    wait "$fpid"
    p=$(partials | paste -sd' ' -)
    say "after the start: $(on cache) on cache, $(( $(on disk1) + $(on disk2) )) on the array; .partial: ${p:-none}"
    ask moverelli.refresh '{}' 120 >/dev/null
    # her record of the run the stop cut (started at t0 or later, from the page)
    h=$(jq -c --argjson t "$t0" '[.history[]? | select((.started // 0) >= $t)][0] // null | if . == null then null else {result, why, mode, by} end' "$D/moverelli.json" 2>/dev/null)
    r=$(jq -r '.result // ""' <<<"$h" 2>/dev/null)
    if [[ $h == null || -z $h ]]; then cell history-says 0 "her history has no record of the run the array stop cut (the newest: $(jq -c '.history[0] | {result, by, started}' "$D/moverelli.json" 2>/dev/null)) — office-run.json $(jq -c '{started, finished, exit}' "$D/moverelli/office-run.json" 2>/dev/null)"
    elif [[ $r =~ ^(stopped|aborted|interrupted|failed)$ ]]; then cell history-says 1 "her record of the cut run: $h"
    else cell history-says 0 "her record of the cut run: $h (the array stop cut it — never «ok»)"; fi
    [[ $(jq -r '.job.running // false' "$D/moverelli.json") == false ]] && cell not-running 1 "her page: no run at work" || cell not-running 0 "her page still says a run is at work"
    snap_before                              # her next run's own before (INV-COUNTS compares one run with the disks)
    flow MV-RUN                              # her next run: the leftover .partial removed, the rest moved
    p=$(partials | paste -sd' ' -)
    [[ -z $p ]] && cell partial-cleared 1 "no .partial after her next run" || cell partial-cleared 0 "after her next run still: $p"
    c=$(on cache) a=$(( $(on disk1) + $(on disk2) ))
    ((c == 0 && a == 3000)) && cell all-moved 1 "all 3000 files on the array now, once each" || cell all-moved 0 "after her next run: $c on cache, $a on the array (of 3000)"
else
    cell history-says 0 "the array was never stopped while her run moved (watcher: $(cat "$dir/fired" 2>/dev/null || echo 'never fired'))"
    wait "$fpid"
fi
WAIT_SOFT=1 wait_for 90 "Unraid's own at job after the array start" atq_quiet
for j in $(atq 2>/dev/null | cut -f1); do say "atd's queue after the start: $(atq | grep "^$j" | tr '\t' ' ') — $(at -c "$j" 2>/dev/null | tail -2 | head -1 | cut -c1-160)"; done
# the agent stops with the array by design (CLAUDE.md: «event/started starts, event/stopping stops»): no heartbeat meanwhile
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
scenario_end
