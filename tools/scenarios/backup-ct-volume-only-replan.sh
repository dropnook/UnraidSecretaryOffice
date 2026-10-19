#!/bin/bash
# Cell B34 of matrix.md: a container whose data lies only in a Docker volume (CT-VOLUME-ONLY: uso-vol, volume usovol).
# «Look at the server again» warns docker_volumes for it (the volume named — it lives in the Docker folder, not in the
# backup), no package error; the Apply list names the new container (docker|known) and nothing else. Pre-release (~1 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-volume-only-replan
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state CT-VOLUME-ONLY
snap_before
expect_changes 'docker|known'
flow BK-REPLAN
m=$(jq -c '[.messages[]? | select(.code == "docker_volumes" and (.params.app // "") == "uso-vol") | {level, volumes: .params.volumes}]' "$KEEP/plan-replan.json")
[[ $(jq length <<<"$m") == 1 && $m == *usovol* ]] && cell warns 1 "docker_volumes for uso-vol: $m" || cell warns 0 "the plan's word on uso-vol: $m"
e=$(jq -c '[.messages[]? | select(.level == "error")] | map(.code)' "$KEEP/plan-replan.json")
[[ $e == '[]' ]] && cell no-error 1 "no error in the plan" || cell no-error 0 "errors: $e"
check standard
scenario_end
