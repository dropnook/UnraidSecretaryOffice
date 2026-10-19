#!/bin/bash
# Cell B35 of matrix.md, a member stopped: the Immich stack with immich_redis stopped by the user (ST-MEMBER-STOPPED).
# The stack stays one known app (its project has members still), the Apply list names nothing (INV-REPLAN), a run backs
# it up and leaves immich_redis stopped as the user had it (INV-UNRAID-SAME, INV-STOPPED-KEEPS). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-member-stopped-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state ST-MEMBER-STOPPED immich_redis
snap_before
flow BK-REPLAN
mem=$(jq -c '[.containers[]? | select(.project == "immich") | .name] | sort' "$KEEP/plan-replan.json")
[[ $mem == '["immich_postgres","immich_redis","immich_server"]' ]] && cell members 1 "immich still one app of three: $mem" || cell members 0 "immich's members in the plan: $mem"
expect_warnings compose_none app_no_compose stopped_kept
flow BK-RUN-MANUAL mode=backup
[[ $(ct_status immich_redis) == exited ]] && cell stays-stopped 1 "immich_redis still stopped after the run" || cell stays-stopped 0 "immich_redis is $(ct_status immich_redis) after the run"
check standard
check final
scenario_end
