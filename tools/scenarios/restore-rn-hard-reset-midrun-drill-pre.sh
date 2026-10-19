#!/bin/bash
# Cell R4 of matrix.md, the drill, pre half (nightly): Mr. Restori's drill works (3 s in: a throwaway database
# container checks a dump) when the power goes. This half makes the packages (a backup run), notes what must come back
# (OF-REBOOT), puts the demo set on Docker's autostart (CT-AUTOSTART — undone by the post half), arms the cut and
# ends; a detached starter starts the drill once this half's result is written.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-hard-reset-midrun-drill-pre
needs tower docker reboot
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after a power cut\n' >"$KEEP/.skip"; scenario_end; }
note=${USO_SC_OUT:-$SC_REPORTS}/restore-rn-hard-reset-midrun-drill.prenotes
{ [[ -f /var/lib/docker/unraid-autostart ]] && echo autostart=had || echo autostart=none; } >"$note"
[[ -f /var/lib/docker/unraid-autostart ]] && cp -p /var/lib/docker/unraid-autostart "$note.autostart"
nc_data_closed
state_ct_autostart
flow BK-RUN-MANUAL mode=backup               # the packages the drill tries
flow OF-REBOOT
midrun_arm RN-HARD-RESET-MIDRUN drill 3 reset 180 && armed=green || armed=red
pid=$(_st_bg bash -c 'r=$1; for i in $(seq 1 240); do [[ -s $r ]] && break; sleep 0.5; done; sleep 3; . "$2"; flow_rs_drill >/dev/null 2>&1' \
    _ "${REPORT%.md}.result" "$SC_DIR/lib.sh")
say "the drill handed to a detached starter (pid $pid): 3 s after this half's result, the power cut 3 s into the drill"
_row cell armed "$armed" "the power cut armed ($armed)"
scenario_end
