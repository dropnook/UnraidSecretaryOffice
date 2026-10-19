#!/bin/bash
# Cell R4 of matrix.md, the restore, post half (nightly): back after the power cut that hit Mr. Restori's copy. What
# must hold: his journal no longer says «running» — his page reads it as interrupted (rsJournals: the job is gone) —
# and names the step it stopped at; «Put back» is offered and undoes what the copy had begun (the half copy set
# aside, nothing of the live folder touched); no job of his «running», the engine's lock free, a new restore not
# blocked. Unraid's own parity check after the unclean stop is cancelled. The undo removes the folder and what the
# restore put beside it, takes the demo set off Docker's autostart again and starts any container still missing.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-hard-reset-midrun-files
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
parity_cancel() { local d; d=$(_st_dir UNCLEAN-PARITY)
    if [[ -f /boot/config/parity-checks.log ]]; then cp -p /boot/config/parity-checks.log "$d/parity-checks.log"; else : >"$d/parity-checks.none"; fi
    parity_stop UNCLEAN-PARITY; }
out=${USO_SC_OUT:-$SC_REPORTS}
reb=$out/restore-rn-hard-reset-midrun-files.reboot
prenote=$out/restore-rn-hard-reset-midrun-files.prenotes
was=$(sed -n 's/^docker=//p' "$reb" 2>/dev/null)
if grep -qx autostart=had "$prenote" 2>/dev/null; then undo_push cp -p "$prenote.autostart" /var/lib/docker/unraid-autostart
else undo_push rm -f /var/lib/docker/unraid-autostart; fi
undo_push ct_start ${was//,/ }
undo_push bash -c 'rm -rf /mnt/cache/uso-only/uso-wip-d-r4f /mnt/cache/uso-only/uso-wip-d-r4f.*'
agent_wait 300
if WAIT_SOFT=1 wait_for 60 "Unraid's parity check after the unclean stop" bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))'; then
    say "Unraid checks parity after the unclean stop — cancelled (not this cell's)"; parity_cancel
else
    say "note: no parity check after the boot — was it a power cut?"
fi
track_share uso-only
snap_before
rid=$(ls -1 "$D/restore" 2>/dev/null | grep -E '^[0-9]{8}-' | sort | tail -1)
j=$D/restore/$rid/journal.json
say "the restore the cut hit: $rid — journal as the disk kept it: $(jq -c '{result, steps: [.steps[]? | .state]}' "$j" 2>/dev/null)"
evidence "$j" journal-after-boot.json >/dev/null 2>&1
row=$(state_json restore '[.restores[]? | select(.id == "'"$rid"'")][0] | "\(.result) step=\(.step // "?") can_putback=\(.can_putback)"' 2>/dev/null)
[[ $row == interrupted* ]] && cell interrupted 1 "his page: $row" || cell interrupted 0 "his page: ${row:-no row for $rid}"
[[ $row =~ step=[0-9] ]] && cell stopped-at-step 1 "the row names the step: ${row#* }" || cell stopped-at-step 0 "no step named: $row"
run=$(state_json restore '.running // null | tostring' 2>/dev/null)
[[ $run == null ]] && cell nothing-running 1 "no restore of his «running»" || cell nothing-running 0 "his page says a restore runs: $run"
lock_held "$UB/state/lock" && cell lock-free 0 "the engine's lock is held after the boot" || cell lock-free 1 "the engine's lock is free"
if [[ $row == *can_putback=true ]]; then
    flow RS-PUTBACK of="$rid"
    live=$(find /mnt/cache/uso-only/uso-wip-d-r4f -type f | wc -l)
    ((live == 4)) && cell putback 1 "«Put back» done, the live folder untouched (4 files)" || cell putback 0 "after «Put back» the live folder has $live files (4 before)"
else
    cell putback 0 "«Put back» isn't offered for the interrupted copy ($row)"
fi
check standard
check final
scenario_end
