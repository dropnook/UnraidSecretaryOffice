#!/bin/bash
# Cell B11 of matrix.md, the pre half (nightly — a power cut and a boot): a backup run is at work with the apps paused
# and Nextcloud in maintenance mode when the power goes (sysrq b, after a sync: RN-HARD-RESET-MIDRUN's own reset).
# This half notes what must come back (OF-REBOOT: version, staff, cron, settings, the running containers) and puts the
# demo set on Docker's autostart (CT-AUTOSTART, as a user's server has it — undone by the post half), arms the power
# cut (9 s after backup.sh is at work) and ends; a detached «Back up now» starts the run once this half's result is
# written, so the runner has its report before the server goes. The post half checks the boot.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-hard-reset-midrun-run-pre
needs tower docker reboot
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after a power cut\n' >"$KEEP/.skip"; scenario_end; }
note=${USO_SC_OUT:-$SC_REPORTS}/backup-rn-hard-reset-midrun-run.prenotes
{ [[ -f /var/lib/docker/unraid-autostart ]] && echo autostart=had || echo autostart=none; } >"$note"
[[ -f /var/lib/docker/unraid-autostart ]] && cp -p /var/lib/docker/unraid-autostart "$note.autostart"
nc_data_closed
state_ct_autostart                           # not `state`: its undo is the post half's
flow OF-REBOOT
minute_wait
midrun_arm RN-HARD-RESET-MIDRUN bk 9 reset 180
pid=$(_st_bg bash -c 'r=$1; for i in $(seq 1 240); do [[ -s $r ]] && break; sleep 0.5; done; sleep 3; . "$2"; ask backup.start "{\"mode\":\"backup\"}" >/dev/null 2>&1' \
    _ "${REPORT%.md}.result" "$SC_DIR/lib.sh")
say "«Back up now» handed to a detached starter (pid $pid): 3 s after this half's result, the power cut 9 s into the run"
_row cell armed green "the power cut armed"
scenario_end
