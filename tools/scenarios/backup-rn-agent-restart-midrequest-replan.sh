#!/bin/bash
# Cell B45 of matrix.md, its third state × BK-REPLAN: the agent restarts (a changed file of its own, as a deploy makes
# one) the moment «Look at the server again» lands in the mailbox (RN-AGENT-RESTART-MIDREQUEST). The page gets an
# answer at once — the plan's start, or agent_restarted (the page says so) — never a wait; a plan then comes (after
# agent_restarted the page's next look, here: the same request again); setup-plan.json is whole JSON at every look
# (written atomically, never half); the agent is back. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-agent-restart-midrequest-replan
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
pf=$UB/state/setup-plan.json
whole() { [[ ! -e $pf ]] || jq -e '.P | type == "object"' "$pf" >/dev/null 2>&1; }
watch_whole() { local bad=0 i; for ((i = 0; i < 300; i++)); do whole || bad=$((bad + 1)); sleep 0.2; done; echo "$bad" >"$KEEP/.half"; }
snap_before
agent_mark_restart
state RN-AGENT-RESTART-MIDREQUEST
watch_whole & wpid=$!
t0=$(date +%s%N)
ask backup.setup_plan '{"measure":false}' 120 >/dev/null
ms=$((($(date +%s%N) - t0) / 1000000))
k=$(ask_last 'if .ok then "ok" else .error.key end')
[[ ($k == ok || $k == agent_restarted) && $ms -lt 30000 ]] && cell answered 1 "answered «$k» in $ms ms" || cell answered 0 "answered «$k» after $ms ms"
[[ -f $(_st_dir RN-AGENT-RESTART-MIDREQUEST)/fired ]] && say "the agent was restarted while the request waited" || cell restarted 0 "the watcher never touched agent.php"
agent_wait 120
if [[ $k != ok ]]; then
    say "the page looks again (agent_restarted)"
    flow BK-REPLAN
else
    wait_setup 900
fi
wait "$wpid"
half=$(cat "$KEEP/.half" 2>/dev/null)
[[ $half == 0 ]] && cell plan-whole 1 "setup-plan.json whole JSON at every look (60 s, every 0.2 s)" || cell plan-whole 0 "setup-plan.json not whole JSON at $half of 300 looks"
r=$(jq -r '"\(.mode)/\(.result)"' "$UB/state/setup-status.json" 2>/dev/null)
[[ $r == plan/ok || $r == plan/warnings ]] && cell plan-came 1 "a plan came: $r" || cell plan-came 0 "the setup's last look: $r"
check standard
scenario_end
