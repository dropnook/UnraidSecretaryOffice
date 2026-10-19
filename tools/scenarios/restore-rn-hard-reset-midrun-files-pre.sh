#!/bin/bash
# Cell R4 of matrix.md, the restore, pre half (nightly — a power cut and a boot): Mr. Restori copies a folder back
# beside the live one (RS-FILES beside: EmbyServer's app folder appdata/emby with 4 × 512 MB of ours in it, from the moment
# the run makes here) when the power goes
# (RN-HARD-RESET-MIDRUN rs: as soon as his job is at work, after a sync). This half writes the files, makes the moment,
# notes what must come back (OF-REBOOT), puts the demo set on Docker's autostart (CT-AUTOSTART — undone by the post
# half), arms the cut and ends; a detached starter clicks through the restore dialog once this half's result is
# written, so the runner has its report before the server goes.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-hard-reset-midrun-files-pre
needs tower docker zfs reboot
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart nothing comes back after a power cut\n' >"$KEEP/.skip"; scenario_end; }
note=${USO_SC_OUT:-$SC_REPORTS}/restore-rn-hard-reset-midrun-files.prenotes
{ [[ -f /var/lib/docker/unraid-autostart ]] && echo autostart=had || echo autostart=none; } >"$note"
[[ -f /var/lib/docker/unraid-autostart ]] && cp -p /var/lib/docker/unraid-autostart "$note.autostart"
put_files R4F cache appdata/emby/uso-wip-d-r4f 4 --size 512M || _row state put_files red "the files could not be written"
nc_data_closed
state_ct_autostart                           # not `state`: its undo is the post half's
# the moment/packages from the cron's run (job.sh backup, no look at the page): a pre half's page looks would live in
# /tmp/uso-keep and be gone after the reboot before the runner's Mac-side checks read them
minute_wait && job backup && job_wait backup 1800 && wait_backup 1800
flow OF-REBOOT
midrun_arm RN-HARD-RESET-MIDRUN rs 0 reset 180 && armed=green || armed=red
pid=$(_st_bg bash -c 'r=$1; for i in $(seq 1 240); do [[ -s $r ]] && break; sleep 0.5; done; sleep 3; . "$2"; flow_rs_files mode=beside path=/mnt/user/appdata/emby >/dev/null 2>&1' \
    _ "${REPORT%.md}.result" "$SC_DIR/lib.sh")
say "the restore handed to a detached starter (pid $pid): 3 s after this half's result, the power cut when his job is at work"
_row cell armed "$armed" "the power cut armed ($armed)"
scenario_end
