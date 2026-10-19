#!/bin/bash
# Cell R4 of matrix.md, the drill, post half (nightly): back after the power cut that hit the drill. What must hold:
# the sweeper (drillSweep at the agent's start) cleared what the drill made — no uso-drill-* container, no .uso-drill-*
# folder (INV-LEFTOVER) — the drill reads «interrupted», never «failed» and never «running», the certificate isn't
# failed, the engine's lock is free, and the next drill passes (INV-DRILL-GREEN). Unraid's own parity check after the
# unclean stop is cancelled. The undo takes the demo set off Docker's autostart again and starts any container missing.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-hard-reset-midrun-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
parity_cancel() { local d; d=$(_st_dir UNCLEAN-PARITY)
    if [[ -f /boot/config/parity-checks.log ]]; then cp -p /boot/config/parity-checks.log "$d/parity-checks.log"; else : >"$d/parity-checks.none"; fi
    parity_stop UNCLEAN-PARITY; }
out=${USO_SC_OUT:-$SC_REPORTS}
reb=$out/restore-rn-hard-reset-midrun-drill.reboot
prenote=$out/restore-rn-hard-reset-midrun-drill.prenotes
was=$(sed -n 's/^docker=//p' "$reb" 2>/dev/null)
if grep -qx autostart=had "$prenote" 2>/dev/null; then undo_push cp -p "$prenote.autostart" /var/lib/docker/unraid-autostart
else undo_push rm -f /var/lib/docker/unraid-autostart; fi
undo_push ct_start ${was//,/ }
agent_wait 300
if WAIT_SOFT=1 wait_for 60 "Unraid's parity check after the unclean stop" bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))'; then
    say "Unraid checks parity after the unclean stop — cancelled (not this cell's)"; parity_cancel
else
    say "note: no parity check after the boot — was it a power cut?"
fi
snap_before
ask restore.drill_state '{}' 300 >/dev/null
d=$(ask_last '[.drills[]?][0] | "\(.id) \(.result)/\(.reason // "")"')
say "the drill the cut hit: $d"
[[ $d == *" interrupted/"* ]] && cell interrupted 1 "drill ${d%% *}: interrupted" || cell interrupted 0 "the drill after the boot: «$d» (must read interrupted)"
[[ $(ask_last '.running // null | tostring') == null ]] && cell nothing-running 1 "no drill «running»" || cell nothing-running 0 "a drill «running» after the boot"
c=$(ask_last '.certificate.result // "none"')
[[ $c != failed ]] && cell certificate 1 "the certificate says «$c»" || cell certificate 0 "the certificate says failed after a power cut"
left=$(timeout 20 docker ps -a --filter name=uso-drill- --format '{{.Names}}' | paste -sd' ' -)
[[ -z $left ]] && cell swept 1 "no uso-drill-* container after the agent's start" || cell swept 0 "left after the boot: $left"
lock_held "$UB/state/lock" && cell lock-free 0 "the engine's lock is held after the boot" || cell lock-free 1 "the engine's lock is free"
check standard
check final
scenario_end
