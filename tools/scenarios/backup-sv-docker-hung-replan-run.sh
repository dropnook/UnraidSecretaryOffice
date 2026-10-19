#!/bin/bash
# Cell B15 of matrix.md (batch E): Docker switched on but silent (dockerd stopped by SIGSTOP — `docker version` hangs).
# «Look at the server again» ends in finite time and says «Docker is switched on but doesn't answer» (setup.msg
# docker_silent), nothing saved changes; «Back up now» ends in finite time with a reason (engine 2.54: «Docker switched
# on but silent still ends the run» — ub_docker_answers, timeout 20 s), no container stopped or left stopped; the
# agent's round stays quick the whole time (INV-TICK). Budgets: the replan ≤ 120 s, the run ≤ 300 s. Each half on its
# own: a half that hangs is let go (dockerd continued, its job waited for) before the other one starts with Docker
# silent again — so a hung replan doesn't hide the run behind «setup_running». Pre-release (~6 min when both hold).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-docker-hung-replan-run
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
cts() { timeout 20 docker ps -a --format '{{.Names}} {{.State}}' 2>/dev/null | sort; }
cts >"$KEEP/containers-before.txt"            # snap_before can't ask Docker once it is silent (lib.sh has docker: timeout 10)
state SV-DOCKER-HUNG
snap_before
# 1. «Look at the server again» (the page's setup_plan {measure:false}), its end awaited for 120 s
t0=$SECONDS
if ask backup.setup_plan '{"measure":false}' 120 >/dev/null; then
    sleep 2
    if wait_for 120 "the replan to end" _setup_done; then
        _bk_plan_get "$KEEP/plan-replan.json"
        cell replan-ends 1 "«Look again» ended in $((SECONDS - t0)) s"
        jq -e '[.messages[]? | .code] | index("docker_silent")' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
            && cell replan-says 1 "the plan says docker_silent" \
            || cell replan-says 0 "no docker_silent in the plan: $(jq -c '[.messages[]? | .code] | unique' "$KEEP/plan-replan.json" 2>/dev/null | head -c 300)"
    else
        cell replan-ends 0 "«Look again» still at work after $((SECONDS - t0)) s: $(pstree -a "$(jq -r '.pid // 0' "$UB/state/setup-status.json")" 2>/dev/null | sed -n 2p | sed 's/^[ |`-]*//')"
        docker_unhang SV-DOCKER-HUNG; wait_setup 600; docker_hang SV-DOCKER-HUNG
    fi
else
    cell replan-ends 0 "«Look again» refused: $(ask_last '.error.key')"
fi
# 2. «Back up now», its end awaited for 300 s
minute_wait
t0=$SECONDS
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    sleep 2
    st=$UB/state/status.json
    if wait_for 300 "the run to end" _backup_done; then
        r=$(jq -c '{result, message, errors, warnings}' "$st" 2>/dev/null)
        evidence "$st" >/dev/null
        evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" run.log >/dev/null 2>&1
        cell run-ends 1 "the run ended in $((SECONDS - t0)) s: $r"
        jq -e '(.message // "") | tostring | length > 0' "$st" >/dev/null 2>&1 && cell run-says 1 "with a reason: $r" || cell run-says 0 "without a reason: $r"
    else
        cell run-ends 0 "the run still at work after $((SECONDS - t0)) s: $(pgrep -af '^docker (info|version|ps)' | head -1)"
        docker_unhang SV-DOCKER-HUNG; wait_backup 900; docker_hang SV-DOCKER-HUNG
    fi
else
    cell run-ends 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
# the checks ask Docker without a time limit (checks.sh INV-SETUP-MSGS: `docker volume ls`) — Docker answers again
# before them; INV-TICK's pulses cover the silent time all the same
docker_unhang SV-DOCKER-HUNG
d=$(diff "$KEEP/containers-before.txt" <(cts) | grep '^[<>]' | head -4)
[[ -z $d ]] && cell containers-same 1 "every container as before the state ($(grep -c . "$KEEP/containers-before.txt"))" || cell containers-same 0 "${d//$'\n'/ | }"
allow INV-UNRAID-SAME "snap_before was taken while Docker was silent (lib.sh's has docker: timeout 10 → «docker off»), the containers are compared by cell containers-same"
check standard
scenario_end
