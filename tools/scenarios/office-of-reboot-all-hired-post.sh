#!/bin/bash
# Cell O3 of matrix.md, the post half (nightly): back after a clean reboot with everything hired. What must hold: the
# agent is back (a new one, at work), OF-REBOOT-BACK finds version, staff, the cron file, settings and the running
# containers as the pre half noted them, every line of the office's cron files is in crond's table exactly once (no
# double line in /etc/cron.d/root, none in root's own crontab — INV-CRON-ONE), the night shift isn't running with the
# array started and nothing waits for a --recover (nothing noted), no parity check was started (a clean stop). The undo
# lets Jack Streamer go again when the pre half hired him, takes the demo set off Docker's autostart, starts any
# container still missing.
. "$(dirname "$0")/lib.sh"
scenario_begin office-of-reboot-all-hired
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# Unraid's array start queues an at job of its own (`sleep 10; …/reload_services`): no leftover of the office's
# (INV-LEFTOVER looks at atd's queue) — waited for before the checks
atq_quiet() { [[ -z $(atq 2>/dev/null) ]]; }
out=${USO_SC_OUT:-$SC_REPORTS}
reb=$out/office-of-reboot-all-hired.reboot
prenote=$out/office-of-reboot-all-hired.prenotes
was=$(sed -n 's/^docker=//p' "$reb" 2>/dev/null)
if grep -qx autostart=had "$prenote" 2>/dev/null; then undo_push cp -p "$prenote.autostart" /var/lib/docker/unraid-autostart
else undo_push rm -f /var/lib/docker/unraid-autostart; fi
undo_push ct_start ${was//,/ }
grep -qx jack=hired "$prenote" 2>/dev/null && undo_push flow OF-FIRE desk=jack
agent_wait 300
nc_data_closed                               # Unraid's array start at the boot opened Nextcloud's data folder (0777) — not this cell's
snap_before
flow OF-REBOOT-BACK
dups=$(grep -h 'unraid-secretary-office' /etc/cron.d/root 2>/dev/null | grep -v '^#' | sort | uniq -d | head -2)
mine=$(crontab -l 2>/dev/null | grep -c 'unraid-secretary-office')
[[ -z $dups && $mine == 0 ]] && cell cron-once 1 "every office line once in /etc/cron.d/root, none in root's crontab" \
    || cell cron-once 0 "twice: ${dups:-none}; in root's crontab: $mine"
ns=$(head -1 "$RUN/nightshift.lock" 2>/dev/null | tr -dc 0-9)
[[ -n $ns ]] && kill -0 "$ns" 2>/dev/null && cell night-shift-off 0 "the night shift ($ns) still runs with the array started" || cell night-shift-off 1 "no night shift with the array started"
left=$(cat "$UB/state/stopped" "$UB/state/maintenance" "$UB/state/vms" 2>/dev/null | sed '/^$/d' | paste -sd' ' -)
[[ -z $left ]] && cell nothing-noted 1 "nothing noted for a --recover" || cell nothing-noted 0 "noted after a clean reboot: $left"
(( $(/usr/local/sbin/mdcmd status | awk -F= '$1 == "mdResyncPos" { print $2 }') == 0 )) && cell no-parity 1 "no parity check after the clean reboot" \
    || cell no-parity 0 "Unraid checks parity after the reboot (an unclean stop?)"
WAIT_SOFT=1 wait_for 90 "Unraid's own at job after the array start" atq_quiet
check standard
check final
scenario_end
