#!/bin/bash
# Cell R5 of matrix.md, the drill: Unraid's ⟦Stop⟧ while Mr. Restori's drill works (3 s in: a throwaway database
# container checks a dump). The drill ends at once — «aborted / array_stopping», never «failed», its throwaways
# removed (fast, the rest at the next agent start: the sweeper) —; after ⟦Start⟧: no uso-drill-* container, no
# .uso-drill-* folder (INV-LEFTOVER), nothing «running», the engine's lock free, the certificate not «failed», and the
# next drill passes (INV-DRILL-GREEN). Docker's autostart for the demo set (CT-AUTOSTART) brings the apps back.
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-array-stop-midrun-drill-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# Unraid's array start queues an at job of its own (`sleep 10; …/reload_services`): no leftover of the office's
# (INV-LEFTOVER looks at atd's queue) — waited for before the checks
atq_quiet() { [[ -z $(atq 2>/dev/null) ]]; }
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after an array stop\n' >"$KEEP/.skip"; scenario_end; }
nc_data_closed
state CT-AUTOSTART
flow BK-RUN-MANUAL mode=backup               # packages for the drill
state RN-ARRAY-STOP-MIDRUN-DRILL 3 300
dir=$(_st_dir RN-ARRAY-STOP-MIDRUN-DRILL)
snap_before
agent_mark_restart
t0=$(_sc_now)
(flow_rs_drill >/dev/null 2>&1) & fpid=$!
if wait_for 300 "the array stop while the drill works" _st_is STOPPED _st_var mdState; then
    _st_emcmd cmdStart=Start
    wait_for 300 "the array started again" _st_is Started _st_var fsState
    agent_wait 300
    nc_data_closed                           # Unraid's array start opened Nextcloud's data folder (0777) — not this cell's
    wait "$fpid"
    ask restore.drill_state '{}' 300 >/dev/null
    d=$(jq -r --argjson t "$t0" '[.drills[]? | select((.started // 0) >= $t - 5)][0] // {} | "\(.id // "none") \(.result // "")/\(.reason // "")"' "$KEEP/ask/last.json")
    say "the drill: $d"
    [[ $d == *" aborted/array_stopping" ]] && cell ended 1 "drill ${d% *}: aborted / array_stopping" || cell ended 0 "drill ended «$d» (must be aborted / array_stopping, never failed)"
    [[ $(ask_last '.running // null | tostring') == null ]] && cell nothing-running 1 "no drill «running»" || cell nothing-running 0 "a drill still «running»: $(ask_last '.running | tostring' | head -c 200)"
    c=$(ask_last '.certificate.result // "none"')
    [[ $c != failed ]] && cell certificate 1 "the certificate says «$c»" || cell certificate 0 "the certificate says failed after an array stop"
    left=$(timeout 20 docker ps -a --filter name=uso-drill- --format '{{.Names}}' | paste -sd' ' -)
    [[ -z $left ]] && cell throwaways-gone 1 "no uso-drill-* container after the start" || cell throwaways-gone 0 "left: $left"
else
    cell ended 0 "the array was never stopped while the drill worked (watcher: $(cat "$dir/fired" 2>/dev/null || echo 'never fired'))"
    wait "$fpid"
fi
WAIT_SOFT=1 wait_for 90 "Unraid's own at job after the array start" atq_quiet
for j in $(atq 2>/dev/null | cut -f1); do say "atd's queue after the start: $(atq | grep "^$j" | tr '\t' ' ') — $(at -c "$j" 2>/dev/null | tail -2 | head -1 | cut -c1-160)"; done
# the agent stops with the array by design (CLAUDE.md: «event/started starts, event/stopping stops»): no heartbeat meanwhile
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
check final
scenario_end
