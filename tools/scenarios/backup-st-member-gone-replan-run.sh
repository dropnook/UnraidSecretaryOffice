#!/bin/bash
# Cell B35 of matrix.md, a member gone: immich_redis removed (ST-MEMBER-GONE). The stack stays known with its two
# members, its saved settings stay (nothing about the app removed — INV-REPLAN: the Apply list names nothing, or only
# the member's own key), a run backs it up and ends ok or with the warning that a member is gone. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-member-gone-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
# immich_server exits when its redis goes (it can't reach it) and the state's undo brings redis back only: the members
# that ran before started again after it (pushed before the state: runs after its undo)
ran=$(timeout 20 docker ps --filter name=immich_ --filter status=running --format '{{.Names}}' | paste -sd' ' -)
undo_push bash -c 'for c in $1; do [[ -n $(docker ps -q --filter "name=^$c\$" --filter status=running) ]] || docker start "$c" >/dev/null; done' _ "$ran"
state ST-MEMBER-GONE immich_redis
snap_before
flow BK-REPLAN
mem=$(jq -c '[.containers[]? | select(.project == "immich") | .name] | sort' "$KEEP/plan-replan.json")
[[ $mem == '["immich_postgres","immich_server"]' ]] && cell members 1 "immich known with its two members: $mem" || cell members 0 "immich's members in the plan: $mem"
# the gone member leaves Mr. Backupsy's lists — only a Compose stack brought down keeps its place there (backup/setup.sh:1412
# «2.60: the containers of a Compose stack brought down keep their place in the lists, as stored», down_keep_list: a
# name still on the server); so docker|known and docker|no_stop may change, by that member alone (cell only-member)
d=""
for k in known no_stop; do
    old=$(_ini_flat "$UB/settings.ini" | sed -n "s/^docker|$k=//p" | grep -vx immich_redis | sort | paste -sd' ' -)
    new=$(jq -r --arg k "docker|$k" '.P[$k] // [] | .[]' "$KEEP/plan-replan.json" | sort | paste -sd' ' -)
    [[ $old == "$new" ]] || d+=" $k: settings without immich_redis «$old», the plan «$new»;"
done
[[ -z $d ]] && cell only-member 1 "the plan's docker lists are the saved ones without immich_redis" || cell only-member 0 "${d# }"
expect_changes 'docker|known' 'docker|no_stop'
expect_warnings compose_none app_no_compose container_gone member_gone
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '.result' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings)$ ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
check standard
check final
scenario_end
