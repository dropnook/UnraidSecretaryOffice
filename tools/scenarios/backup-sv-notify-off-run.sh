#!/bin/bash
# Cell B47 of matrix.md (wave 3), the notifications half: Unraid's notify program can't be run (SV-NOTIFY-OFF bin: its
# x bit off — a broken update, a hand). What must hold:
#   plan-said    «Look at the server again» says it (setup.msg.no_notify: «I can't tell you how a backup went») and
#                changes nothing in the Apply list (a warning, not a setting)
#   run-ok       «Back up now» ends as it would (ok or warnings) — a notification that can't be sent never fails a run
#   agent-up     the agent keeps working: its heartbeat fresh, no PHP error about notify in its log since the state
# The x bit comes back with the undo. Pre-release candidate (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-notify-off-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state SV-NOTIFY-OFF bin
agent_log_mark notify-off
snap_before
if flow BK-REPLAN; then
    jq -e '[.messages[]? | .code] | index("no_notify")' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
        && cell plan-said 1 "the plan says no_notify («$(jq -r '."setup.msg.no_notify"' "$P/desks/backup/lang/en.json")»)" \
        || cell plan-said 0 "the plan doesn't say Unraid's notifications are missing: $(jq -c '[.messages[]?.code] | unique' "$KEEP/plan-replan.json" | head -c 300)"
else
    cell plan-said 0 "«Look at the server again» didn't end with a plan"
fi
expect_warnings no_notify
if flow BK-RUN-MANUAL mode=backup; then
    cell run-ok 1 "the run ended $(jq -r '.result' "$UB/state/status.json") with notify not runnable"
else
    cell run-ok 0 "the run ended $(jq -c '{result, message}' "$UB/state/status.json" 2>/dev/null) with notify not runnable"
fi
bubble_look notify-off backup caretaker
age=$(( $(_sc_now) - $(stat -c %Y "$RUN/agent.json" 2>/dev/null || echo 0) ))
errs=$(agent_log_since notify-off | grep -i -E 'PHP (Fatal|Warning)|Uncaught|TypeError' | grep -i notif | head -3)
((age < 60)) && [[ -z $errs ]] && cell agent-up 1 "heartbeat ${age}s old, no PHP error about notify since the state" \
    || cell agent-up 0 "heartbeat ${age}s old; ${errs:-no notify error}"
check standard
check final
scenario_end
