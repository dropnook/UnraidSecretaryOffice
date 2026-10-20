#!/bin/bash
# Cell B38 of matrix.md (batch E), state KP-OBJECT-LOCK: Kopia on MinIO's locked repository (GOVERNANCE 7 d). Per
# variant (extend, lapse, late, never — what time can't make: the engine's own look, state/kopia-lock.json, rewritten
# by the state) the office's checks name exactly that one: the Team Lead's finding kopia_lock_<variant> open, the other
# kopia_lock_* not (cell finding-<variant>); «ok» opens none. The office's tick never runs Kopia's maintenance with
# --safety=none (cell no-safety-none: the agent's and the engine's logs, Kopia's own). Each variant undone before the
# next (the state keeps the engine's file of before). Pre-release (~8 min: Kopia reconnects per variant).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-object-lock-tick
needs docker kopia minio
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
open_locks() { jq -r '[.. | objects | select(has("id") and has("ok")) | select((.id | startswith("kopia_lock")) and .ok == false) | .id] | unique | join(" ")' "$D/caretaker.json" 2>/dev/null; }
agent_log_mark lock
snap_before
for v in ok extend lapse late never; do
    state KP-OBJECT-LOCK "$v" || continue
    ask backup.refresh '{}' 120 >/dev/null
    ask caretaker.refresh '{}' 120 >/dev/null
    got=$(open_locks)
    want=$([[ $v == ok ]] || echo "kopia_lock_$v")
    [[ $got == "$want" ]] && cell "finding-$v" 1 "open: ${got:-none} (as it must)" || cell "finding-$v" 0 "open: ${got:-none}, must be: ${want:-none}"
    bubble_look "lock-$v" backup caretaker
    undo_kp_object_lock
done
bad=$( { agent_log_since lock; cat "$UB"/logs/*.log 2>/dev/null; timeout 20 docker logs --since 30m kopia 2>&1; } | grep -c -- 'safety=none')
((bad == 0)) && cell no-safety-none 1 "no --safety=none anywhere (agent log since the begin, the engine's logs, Kopia's own)" \
    || cell no-safety-none 0 "--safety=none seen $bad time(s)"
check standard
scenario_end
