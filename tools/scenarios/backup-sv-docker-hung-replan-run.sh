#!/bin/bash
# Cell B15 of matrix.md (batch E): Docker switched on but silent (dockerd stopped by SIGSTOP — `docker version` hangs).
# «Look at the server again» ends in finite time and says «Docker is switched on but doesn't answer» (setup.msg
# docker_silent), nothing saved changes; «Back up now» ends in finite time with a reason (engine 2.54: «Docker switched
# on but silent still ends the run» — ub_docker_answers, timeout 20 s), no container stopped or left stopped; the
# agent's round stays quick the whole time (INV-TICK: no heartbeat gap, his own look at the containers has a timeout).
# Budgets: the replan ≤ 300 s, the run ≤ 600 s. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-docker-hung-replan-run
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state SV-DOCKER-HUNG
snap_before
t0=$SECONDS
flow BK-REPLAN
tr=$((SECONDS - t0))
((tr <= 300)) && cell replan-ends 1 "«Look again» ended in $tr s" || cell replan-ends 0 "«Look again» took $tr s (> 300)"
jq -e '[.messages[]? | .code] | index("docker_silent")' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
    && cell replan-says 1 "the plan says docker_silent" \
    || cell replan-says 0 "no docker_silent in the plan: $(jq -c '[.messages[]? | .code] | unique' "$KEEP/plan-replan.json" 2>/dev/null | head -c 300)"
# «Back up now» — the button; its end looked at here (a run that ends with a reason is what must hold, not «ok»)
minute_wait
t0=$SECONDS
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    wait_backup 900
    trun=$((SECONDS - t0))
    st=$UB/state/status.json
    r=$(jq -c '{result, message, code: (.code // .why // null), errors, warnings}' "$st" 2>/dev/null)
    evidence "$st" >/dev/null
    evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" run.log >/dev/null 2>&1
    ((trun <= 600)) && cell run-ends 1 "the run ended in $trun s: $r" || cell run-ends 0 "the run took $trun s (> 600): $r"
    jq -e '(.result // "") != "running" and ((.message // .code // .why // "") | tostring | length > 0)' "$st" >/dev/null 2>&1 \
        && cell run-says 1 "it ended with a reason: $r" || cell run-says 0 "it ended without a reason: $r"
else
    cell run-ends 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
check standard
scenario_end
