#!/bin/bash
# Cell O3 of matrix.md, the pre half (nightly — a clean reboot): every desk hired (the base: all but Jack Streamer,
# who is hired here when the office lets him — his undo is the post half's), the office's schedules on (backup 04:30,
# Ms. Moverelli's joint run 03:00, the agent's watch), Docker's autostart for the demo set (CT-AUTOSTART, as a user's
# server has it). OF-REBOOT notes what must come back; the runner reboots the server (/sbin/reboot: the array stops,
# the plugin is installed again from the flash at the boot, the array starts).
. "$(dirname "$0")/lib.sh"
scenario_begin office-of-reboot-all-hired-pre
needs tower docker reboot
declare -F state_ct_autostart >/dev/null || { printf 'needs the helper CT-AUTOSTART (task/scenarios-helper-ct-autostart): without Docker autostart no container comes back after a reboot\n' >"$KEEP/.skip"; scenario_end; }
note=${USO_SC_OUT:-$SC_REPORTS}/office-of-reboot-all-hired.prenotes
{ [[ -f /var/lib/docker/unraid-autostart ]] && echo autostart=had || echo autostart=none; } >"$note"
[[ -f /var/lib/docker/unraid-autostart ]] && cp -p /var/lib/docker/unraid-autostart "$note.autostart"
state_ct_autostart
if has jack; then echo jack=had >>"$note"
elif flow OF-HIRE desk=jack refuse=ok && has jack; then echo jack=hired >>"$note"; say "Jack Streamer hired for the reboot"
else echo jack=no >>"$note"; say "note: Jack Streamer not hired ($(ask_last '.error.key // "?"')) — every other desk is"; fi
say "hired: $(jq -c '.hired | keys' "$D/office/staff.json" 2>/dev/null); schedules: $(grep -c 'job.sh' "$OFFICE_FLASH"/*.cron | paste -sd' ' -)"
flow OF-REBOOT
_row cell noted green "noted for the post half"
scenario_end
