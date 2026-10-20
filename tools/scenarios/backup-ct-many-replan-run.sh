#!/bin/bash
# Cell B32 of matrix.md: 40 containers more (CT-MANY: uso-m1 … uso-m40, alpine, an appdata folder each). «Look at the
# server again» ends within 2 minutes and names all 40 as new (keep running until the user decides); a backup run
# leaves them running (never stopped undecided) and ends; the agent's tick stays cheap (INV-TICK). The setup page at
# 375 px without horizontal scrolling is checked by hand on the Mac from this scenario's state files (the page tool
# has no width check in the runner — named in the batch's report). Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-many-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state CT-MANY 40
snap_before
# a new container is proposed as one that keeps running until the user decides (backup/setup.sh:1374 «new since the
# last setup (2.21): keeps running until you decide - backup.sh doesn't stop it either», CT_STOP=no → docker|no_stop)
expect_changes 'docker|known' 'docker|no_stop'
t0=$(_sc_now)
flow BK-REPLAN
dt=$(($(_sc_now) - t0))
plan=$KEEP/plan-replan.json
n=$(jq '[.containers[]? | select(.name | test("^uso-m[0-9]+$"))] | length' "$plan" 2>/dev/null)
((dt < 120)) && cell plan-time 1 "the look took $dt s" || cell plan-time 0 "the look took $dt s (≥ 2 min)"
((n == 40)) && cell plan-names-40 1 "the plan names all 40" || cell plan-names-40 0 "the plan names $n of 40"
flow BK-RUN-MANUAL mode=backup
up=$(timeout 20 docker ps --filter name=uso-m --filter status=running --format '{{.Names}}' | grep -c '^uso-m[0-9]*$')
((up == 40)) && cell run-keeps-running 1 "all 40 undecided containers kept running through the run" || cell run-keeps-running 0 "$up of 40 running after the run"
grep -q 'New, keep running' "$UB/logs/run-$(jq -r .run "$UB/state/status.json").log" 2>/dev/null && say "the run's plan: «New, keep running» for them"
mkdir -p "$KEEP/looks-375" && cp -p "$D"/backup*.json "$KEEP/looks-375/" 2>/dev/null
check standard
scenario_end
