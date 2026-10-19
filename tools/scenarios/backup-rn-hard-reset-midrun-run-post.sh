#!/bin/bash
# Cell B11 of matrix.md, the post half (nightly): the server is back after the power cut that hit the backup run. What
# must hold: his page says the run was «interrupted» (status.json still says running — nobody holds the lock — the
# office reads it as failed / interrupted), the office's own backup.sh --recover (event/started → atd) took Nextcloud
# out of maintenance mode and brought back what the run had paused (Docker's autostart the rest), nothing stays noted,
# OF-REBOOT-BACK finds everything as the pre half noted it (cron, settings, staff, the containers), and the next run
# is clean. Unraid's own parity check after the unclean stop is cancelled (its history line put back) — it isn't this
# cell's. The undo takes the demo set off Docker's autostart again and starts any container still missing.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-hard-reset-midrun-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
notes_gone() { [[ ! -s $UB/state/stopped && ! -s $UB/state/maintenance && ! -s $UB/state/vms ]] && ! lock_held "$UB/state/lock"; }
resync() { /usr/local/sbin/mdcmd status | awk -F= '$1 == "mdResyncPos" { print $2 }'; }
parity_cancel() {   # Unraid's own check after an unclean stop: cancelled, its history line put back (the states' parity_stop)
    local d; d=$(_st_dir UNCLEAN-PARITY)
    if [[ -f /boot/config/parity-checks.log ]]; then cp -p /boot/config/parity-checks.log "$d/parity-checks.log"; else : >"$d/parity-checks.none"; fi
    parity_stop UNCLEAN-PARITY
}
out=${USO_SC_OUT:-$SC_REPORTS}
reb=$out/backup-rn-hard-reset-midrun-run.reboot
prenote=$out/backup-rn-hard-reset-midrun-run.prenotes
was=$(sed -n 's/^docker=//p' "$reb" 2>/dev/null)
# the undo, in this order at the end: the containers that ran before back (whatever didn't come), the autostart list as it was
if grep -qx autostart=had "$prenote" 2>/dev/null; then undo_push cp -p "$prenote.autostart" /var/lib/docker/unraid-autostart
else undo_push rm -f /var/lib/docker/unraid-autostart; fi
undo_push ct_start ${was//,/ }
agent_wait 300
if WAIT_SOFT=1 wait_for 60 "Unraid's parity check after the unclean stop" bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))'; then
    say "Unraid checks parity after the unclean stop — cancelled (not this cell's)"; parity_cancel
else
    say "note: no parity check after the boot — was it a power cut? (Unraid starts one after an unclean stop)"
fi
st=$UB/state/status.json
raw=$(jq -r '"\(.run)/\(.result)/\(.message // "")"' "$st" 2>/dev/null)
say "status.json as the engine left it: $raw"
wait_for 600 "the office's backup.sh --recover to bring back what the run left" notes_gone
snap_before
o=$(state_json backup '"\(.status.result // "")/\(.status.message // "")/\(.status.interrupted // false)"' 2>/dev/null)
[[ $o == failed/interrupted/true ]] && cell interrupted 1 "his page: the run «interrupted» (status.json left at $raw)" \
    || cell interrupted 0 "his page: «$o» (status.json $raw) — a power cut must read interrupted"
run=${raw%%/*}
evidence "$UB/logs/run-$run.log" run-cut.log >/dev/null 2>&1
rl=$(ls -t "$UB"/logs/recover*.log 2>/dev/null | head -1); [[ -n $rl ]] && evidence "$rl" recover.log >/dev/null
grep -q "maintenance mode on" "$UB/logs/run-$run.log" 2>/dev/null && say "the run had put Nextcloud into maintenance mode before the cut" \
    || say "note: the run's log (as far as it reached the disk) shows no maintenance mode"
miss=()
for c in ${was//,/ }; do [[ $(ct_status "$c") == running ]] || miss+=("$c ($(ct_status "$c"))"); done
((${#miss[@]} == 0)) && cell back 1 "every container that ran before the cut runs again" || cell back 0 "not back after the boot: ${miss[*]}"
left=$(cat "$UB/state/stopped" "$UB/state/maintenance" "$UB/state/vms" 2>/dev/null | sed '/^$/d' | paste -sd' ' -)
[[ -z $left ]] && cell nothing-noted 1 "nothing left noted" || cell nothing-noted 0 "still noted: $left"
flow OF-REBOOT-BACK
check standard
check final
scenario_end
