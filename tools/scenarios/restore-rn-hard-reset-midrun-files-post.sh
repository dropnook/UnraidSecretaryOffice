#!/bin/bash
# Cell R4 of matrix.md, the restore, post half (nightly): back after the power cut that hit Mr. Restori's copy. What
# must hold: his page reads the restore as interrupted (rsJournals: the job is gone) and its journal names the step it
# stopped at (no step left «running» — the page draws that with the wave); no job of his «running», the engine's lock
# free; the half copy beside the folder doesn't stay looking like a restore (removed, or «Put back» offered). Unraid's
# own parity check after the unclean stop is cancelled. The undo removes the folder and what the restore put beside it,
# takes the demo set off Docker's autostart again and starts any container still missing.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-hard-reset-midrun-files
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# Unraid's array start queues an at job of its own (`sleep 10; …/reload_services`): no leftover of the office's
# (INV-LEFTOVER looks at atd's queue) — waited for before the checks
atq_quiet() { [[ -z $(atq 2>/dev/null) ]]; }
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
undo_push bash -c 'rm -rf /mnt/cache/appdata/emby/uso-wip-d-r4f /mnt/cache/appdata/emby.aside-* /mnt/cache/appdata/emby.restored-* /mnt/cache/appdata/emby.putback-*'
agent_wait 300
nc_data_closed                               # Unraid's array start at the boot opened Nextcloud's data folder (0777) — not this cell's
if WAIT_SOFT=1 wait_for 60 "Unraid's parity check after the unclean stop" bash -c '(( $(/usr/local/sbin/mdcmd status | awk -F= "\$1 == \"mdResyncPos\" { print \$2 }") > 0 ))'; then
    say "Unraid checks parity after the unclean stop — cancelled (not this cell's)"; parity_cancel
else
    say "note: no parity check after the boot — was it a power cut?"
fi
snap_before
rid=$(ls -1 "$D/restore" 2>/dev/null | grep -E '^[0-9]{8}-' | sort | tail -1)
j=$D/restore/$rid/journal.json
say "the restore the cut hit: $rid — journal as the disk kept it: $(jq -c '{result, steps: [.steps[]? | .state]}' "$j" 2>/dev/null)"
evidence "$j" journal-after-boot.json >/dev/null 2>&1
row=$(state_json restore '[.restores[]? | select(.id == "'"$rid"'")][0] | "\(.result) can_putback=\(.can_putback)"' 2>/dev/null)
[[ $row == interrupted* ]] && cell interrupted 1 "his page: $row" || cell interrupted 0 "his page: ${row:-no row for $rid}"
# the step it stopped at, as his page's journal shows it (rsJournalGet: the journal as rsJournals left it) — a step
# still «running» is drawn with the wave (desk.js: s.state === 'running' → waving) for a job that is gone
st=$(jq -r '[.steps | to_entries[] | select(.value.state != "ok" and .value.state != "skipped" and .value.state != "pending")][0] | "\(.key + 1) \(.value.do) \(.value.state)"' "$j" 2>/dev/null)
[[ -n $st && $st != null* && $st != *running ]] && cell stopped-at-step 1 "the journal names the step it stopped at: $st" \
    || cell stopped-at-step 0 "the journal's step $st after the power cut — his page draws it «running» with the wave, nothing runs"
run=$(state_json restore '.running // null | tostring' 2>/dev/null)
[[ $run == null ]] && cell nothing-running 1 "no restore of his «running»" || cell nothing-running 0 "his page says a restore runs: $run"
lock_held "$UB/state/lock" && cell lock-free 0 "the engine's lock is held after the boot" || cell lock-free 1 "the engine's lock is free"
# a copy beside replaces nothing: what the cut leaves is a half copy <folder>.restored-<stamp> — removed, or «Put
# back» offered to take it away, never left looking like a finished restore
half=$(find /mnt/cache/appdata -maxdepth 1 -name 'emby.restored-*' -printf '%f ' 2>/dev/null)
hs=$(du -sm /mnt/cache/appdata/emby.restored-* 2>/dev/null | awk '{s+=$1} END {print s+0}'); ws=$(du -sm /mnt/cache/appdata/emby | awk '{print $1}')
if [[ -z $half ]]; then cell half-copy 1 "no half copy left beside the folder"
elif [[ $row == *can_putback=true ]]; then flow RS-PUTBACK of="$rid"; cell half-copy 1 "the half copy $half — «Put back» offered and done"
else cell half-copy 0 "the half copy ${half}(${hs} of ${ws} MiB) stays beside the folder, named like a restore, and nothing offers to take it away ($row)"; fi
check standard
check final
scenario_end
