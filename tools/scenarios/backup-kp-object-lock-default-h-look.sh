#!/bin/bash
# Batch H, finding 5 (parking lot «Resilienz-Funde», H4's wave 1b): a Kopia repository made with Object Lock by hand (or
# by any other tool) has Kopia's default «extend the locks» OFF — once the retention period is over, what never changes
# loses its lock without a word. B38 (backup-kp-object-lock-tick) judged the office's verdicts on engine files the state
# wrote; this cell takes the real thing: MinIO's bucket with Object Lock, a repository made there with GOVERNANCE 7 d
# (kopia_use s3-lock), then Kopia's default put back (maintenance set --extend-object-locks=false — what a repository
# made without the Consultant has), the engine's file of before set aside so the office's tick asks anew (his tick:
# backupLockTick → backup.sh --kopia-lock through atd). Expected: the office says so — the engine's own look records
# «extend: false» (cell engine-sees), the Team Lead's check kopia_lock_extend is open with the way to switch it on (cell
# says-extend), no other kopia_lock_* (cell one-finding). The office doesn't set it itself (no write to Kopia outside the
# Consultant's confirmed install — info row offers-fix). Kopia back on its base repository and the engine's file of
# before put back in the undo. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-object-lock-default-h-look
needs docker kopia minio
KL=$UB/state/kopia-lock.json
open_locks() { jq -r '[.. | objects | select(has("id") and has("ok")) | select((.id | startswith("kopia_lock")) and .ok == false) | .id] | unique | join(" ")' "$D/caretaker.json" 2>/dev/null; }
mkdir -p "$KEEP_STASH/H5"
[[ -f $KL ]] && cp -p "$KL" "$KEEP_STASH/H5/kopia-lock.json"
undo_push bash -c 'if [[ -f $1/kopia-lock.json ]]; then cp -p "$1/kopia-lock.json" "$2"; else rm -f "$2"; fi; rm -rf "$1"' _ "$KEEP_STASH/H5" "$KL"
kopia_use H5 s3-lock || _row state KP-OBJECT-LOCK red "no Object Lock repository in MinIO"
undo_push kopia_use_back H5
extend_back() { KX_T=60 _st_kx maintenance set --extend-object-locks=true >/dev/null 2>&1; return 0; }    # as the helper leaves lock/
undo_push extend_back
KX_T=60 _st_kx maintenance set --extend-object-locks=false >/dev/null 2>&1 || _row state default red "Kopia refused --extend-object-locks=false"
say "Kopia's maintenance now: $(KX_T=30 _st_kx maintenance info --json 2>/dev/null | jq -c '{owner, extendObjectLocks: (.extendObjectLocks // false), full: .full.enabled}')"
rm -f "$KL"
snap_before
agent_log_mark look
agent_restart                                  # his tick's statics anew: the look is due at once (no file)
wait_for 240 "the engine's look at Object Lock (kopia-lock.json)" test -s "$KL"
evidence "$KL" kopia-lock.json >/dev/null
e=$(jq -c '{ok, lock, extend: .maintenance.extend, full: .maintenance.full_enabled}' "$KL" 2>/dev/null)
[[ $(jq -r '.ok' "$KL" 2>/dev/null) == true && $(jq -r '.lock.mode // ""' "$KL") != "" && $(jq -r '.maintenance.extend' "$KL") == false ]] \
    && h_cell engine-sees 1 "the engine's look: $e" || h_cell engine-sees 0 "the engine's look: ${e:-no file}"
ask backup.refresh '{}' 120 >/dev/null
ask caretaker.refresh '{}' 120 >/dev/null
got=$(open_locks)
how=$(jq -r '[.. | objects | select(.id? == "kopia_lock_extend")][0] | (.how // .how_key // .text // "") | tostring' "$D/caretaker.json" 2>/dev/null)
[[ " $got " == *" kopia_lock_extend "* ]] && h_cell says-extend 1 "the Team Lead's check kopia_lock_extend open${how:+ ($how)}" \
    || h_cell says-extend 0 "kopia_lock_extend not open (open: ${got:-none}) — the locks lapse after 7 days without a word"
[[ $got == kopia_lock_extend ]] && h_cell one-finding 1 "only kopia_lock_extend open" || h_cell one-finding 0 "open: ${got:-none}"
grep -qi 'extend-object-locks' <(agent_log_since look) && h_info offers-fix "the agent ran something with extend-object-locks" \
    || h_info offers-fix "the office says it, it doesn't switch it on itself (the way: check.kopia_lock_extend_how, a console command)"
bubble_look lock-default backup caretaker
check standard
scenario_end
