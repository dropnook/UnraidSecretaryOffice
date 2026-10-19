#!/bin/bash
# Cell M6 of matrix.md, the array stop: Unraid's ⟦Stop⟧ while Ms. Moverelli's real run moves 6 × 512 MB (40 days old,
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
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after an array stop\n' >"$KEEP/.skip"; scenario_end; }
partials() { find /mnt/disk*/uso-yes /mnt/cache/uso-yes -name '*.partial' 2>/dev/null; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m6 -type f ! -name '*.partial' 2>/dev/null | wc -l; }
state CT-AUTOSTART
put_files M6 cache uso-yes/uso-wip-d-m6 6 --size 512M --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M6
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m6'
track_share uso-yes
state RN-ARRAY-STOP-MIDRUN-MV 3 300
dir=$(_st_dir RN-ARRAY-STOP-MIDRUN-MV)
snap_before
agent_mark_restart
(flow_mv_run >/dev/null 2>&1) & fpid=$!
if wait_for 300 "the array stop while her run moves" _st_is STOPPED _st_var mdState; then
    _st_emcmd cmdStart=Start
    wait_for 300 "the array started again" _st_is Started _st_var fsState
    agent_wait 300
    wait "$fpid"
    p=$(partials | paste -sd' ' -)
    say "after the start: $(on cache) on cache, $(( $(on disk1) + $(on disk2) )) on the array; .partial: ${p:-none}"
    ask moverelli.refresh '{}' 120 >/dev/null
    h=$(jq -c '.history[0] | {result, why, mode, by}' "$D/moverelli.json" 2>/dev/null)
    r=$(jq -r '.result // ""' <<<"$h")
    [[ $r =~ ^(stopped|aborted|interrupted|failed)$ && $r != ok ]] && cell history-says 1 "her last run: $h" || cell history-says 0 "her last run after the array stop: $h"
    [[ $(jq -r '.job.running // false' "$D/moverelli.json") == false ]] && cell not-running 1 "her page: no run at work" || cell not-running 0 "her page still says a run is at work"
    flow MV-RUN                              # her next run: the leftover .partial removed, the rest moved
    p=$(partials | paste -sd' ' -)
    [[ -z $p ]] && cell partial-cleared 1 "no .partial after her next run" || cell partial-cleared 0 "after her next run still: $p"
    c=$(on cache) a=$(( $(on disk1) + $(on disk2) ))
    ((c == 0 && a == 6)) && cell all-moved 1 "all 6 files on the array now, once each" || cell all-moved 0 "after her next run: $c on cache, $a on the array (of 6)"
else
    cell history-says 0 "the array was never stopped while her run moved (watcher: $(cat "$dir/fired" 2>/dev/null || echo 'never fired'))"
    wait "$fpid"
fi
# the agent stops with the array by design (CLAUDE.md: «event/started starts, event/stopping stops»): no heartbeat meanwhile
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
scenario_end
