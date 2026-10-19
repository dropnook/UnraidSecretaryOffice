#!/bin/bash
# Cell B45 of matrix.md, its third state × BK-APPLY: the agent restarts the moment Apply lands in the mailbox
# (RN-AGENT-RESTART-MIDREQUEST, armed after the page's look at the plan). The page gets an answer at once (ok, or
# agent_restarted — it says so); settings.ini is whole: either as before or with exactly the one change, never a part
# (INV-DIFF-EXACT when it went through; INV-SETTINGS when it didn't) — and his page's next look says which. Put back in
# the undo. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-agent-restart-midrequest-apply
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
plan=$KEEP/plan.json
_bk_plan_get "$plan" || _row state plan red "no plan from backup.setup_get"
was=$(_ini_flat "$UB/settings.ini" | sed -n 's/^general|min_free_gb=//p'); was=${was:-8}
to=$((was + 1))   # one key, one line: the room kept free in the backup place (a share's mode would also write its method);
# the value as the page sends it — a string (plan-changes.mjs reads key=value as JSON: "9", not 9)
body=$(decisions_from_plan "$plan" "general|min_free_gb=\"$to\"")
cp -p "$UB/settings.ini" "$KEEP/settings.before"
snap_before
agent_mark_restart
state RN-AGENT-RESTART-MIDREQUEST
t0=$(date +%s%N)
ask backup.setup_apply "$body" 120 >/dev/null
ms=$((($(date +%s%N) - t0) / 1000000))
k=$(ask_last 'if .ok then "ok" else .error.key end')
[[ ($k == ok || $k == agent_restarted) && $ms -lt 30000 ]] && cell answered 1 "answered «$k» in $ms ms" || cell answered 0 "answered «$k» after $ms ms"
agent_wait 120
WAIT_SOFT=1 wait_setup 600
d=$(ini_diff "$KEEP/settings.before" "$UB/settings.ini" | paste -sd' ' -)
case $d in
    '') cell ini-whole 1 "settings.ini as before (Apply didn't run: «$k»)" ;;
    "-general|min_free_gb=$was +general|min_free_gb=$to" | "+general|min_free_gb=$to") cell ini-whole 1 "settings.ini with exactly the one change («$k»)"
        expect_diff "general|min_free_gb: $was→$to" ;;
    *) cell ini-whole 0 "settings.ini changed otherwise: $d" ;;
esac
[[ -n $d ]] && undo_push flow BK-APPLY "general|min_free_gb=\"$was\""
check standard
scenario_end
