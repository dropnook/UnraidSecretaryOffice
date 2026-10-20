#!/bin/bash
# Cell B35 of matrix.md, the stack: the Immich stack (ST-STACK: server, postgres, redis by their compose labels — no
# Compose Manager on Tower). «Look at the server again» lists one app immich with its three members; a run dumps its
# postgres, writes its package apps/immich (manifest; no compose files without the Compose Manager — said as a warning)
# and the stack runs again after it. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-stack-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state ST-STACK
snap_before
flow BK-REPLAN
mem=$(jq -c '[.containers[]? | select(.project == "immich") | .name] | sort' "$KEEP/plan-replan.json")
[[ $mem == '["immich_postgres","immich_redis","immich_server"]' ]] && cell members 1 "one app immich: $mem" || cell members 0 "immich's members in the plan: $mem"
expect_warnings compose_none app_no_compose
flow BK-RUN-MANUAL mode=backup
run=$(jq -r .run "$UB/state/status.json"); log=$UB/logs/run-$run.log
grep -q "Postgres 'immich_postgres'" "$log" && grep -A1 "Postgres 'immich_postgres'" "$log" | grep -q 'OK' && cell dump 1 "postgres dumped" || cell dump 0 "no good postgres dump in the run's log"
[[ -f $PLACE/apps/immich/manifest.json || -n $(ls "$PLACE"/apps/immich 2>/dev/null) ]] && cell package 1 "package apps/immich: $(ls "$PLACE"/apps/immich 2>/dev/null | head -6 | paste -sd' ' -)" || cell package 0 "no package apps/immich"
up=$(for c in immich_server immich_postgres immich_redis; do ct_status $c; done | paste -sd' ' -)
[[ $up == 'running running running' ]] && cell stack-back 1 "the stack runs again" || cell stack-back 0 "after the run: $up"
check standard
check final
scenario_end
