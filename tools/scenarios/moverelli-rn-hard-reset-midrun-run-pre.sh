#!/bin/bash
# Cell M6 of matrix.md, the power cut, pre half (nightly): Ms. Moverelli's real run moves 6 × 1.25 GiB (40 days old,
# uso-yes → the array) when the power goes (RN-HARD-RESET-MIDRUN mv: 3 s after Smart Mover is at work, after a sync).
# This half writes the files, notes what must come back (OF-REBOOT), puts the demo set on Docker's autostart
# (CT-AUTOSTART — undone by the post half), arms the cut and ends; a detached «Run now» starts her run once this half's
# result is written.
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-hard-reset-midrun-run-pre
needs tower docker reboot
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after a power cut\n' >"$KEEP/.skip"; scenario_end; }
note=${USO_SC_OUT:-$SC_REPORTS}/moverelli-rn-hard-reset-midrun-run.prenotes
{ [[ -f /var/lib/docker/unraid-autostart ]] && echo autostart=had || echo autostart=none; } >"$note"
[[ -f /var/lib/docker/unraid-autostart ]] && cp -p /var/lib/docker/unraid-autostart "$note.autostart"
put_files M6R cache uso-yes/uso-wip-d-m6r/a 3 --size 1280M --age 40d && put_files M6R cache uso-yes/uso-wip-d-m6r/b 3 --size 1280M --age 40d \
    || _row state put_files red "the files could not be written"
state_ct_autostart
flow OF-REBOOT
midrun_arm RN-HARD-RESET-MIDRUN mv 3 180
pid=$(_st_bg bash -c 'r=$1; for i in $(seq 1 240); do [[ -s $r ]] && break; sleep 0.5; done; sleep 3; . "$2"; ask moverelli.start_run "{\"mode\":\"run\"}" >/dev/null 2>&1' \
    _ "${REPORT%.md}.result" "$SC_DIR/lib.sh")
say "«Run now» handed to a detached starter (pid $pid): 3 s after this half's result, the power cut 3 s into her run"
_row cell armed green "the power cut armed"
scenario_end
