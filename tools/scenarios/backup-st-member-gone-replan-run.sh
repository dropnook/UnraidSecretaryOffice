#!/bin/bash
# Cell B35 of matrix.md, a member gone: immich_redis removed (ST-MEMBER-GONE). The stack stays known with its two
# members, its saved settings stay (nothing about the app removed — INV-REPLAN: the Apply list names nothing, or only
# the member's own key), a run backs it up and ends ok or with the warning that a member is gone. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-member-gone-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state ST-MEMBER-GONE immich_redis
snap_before
flow BK-REPLAN
mem=$(jq -c '[.containers[]? | select(.project == "immich") | .name] | sort' "$KEEP/plan-replan.json")
[[ $mem == '["immich_postgres","immich_server"]' ]] && cell members 1 "immich known with its two members: $mem" || cell members 0 "immich's members in the plan: $mem"
expect_warnings compose_none app_no_compose container_gone member_gone
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '.result' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings)$ ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
check standard
check final
scenario_end
