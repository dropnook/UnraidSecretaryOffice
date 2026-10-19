#!/bin/bash
# Cell B23 of matrix.md: a container re-created under its own name (an update, «Force update», a template changed —
# Unraid's rebuild_container: a new container id, the same template, binds and image). Here EmbyServer (an app with a
# package from a run before) and kopia (the offsite tool itself: its hostname must not be the container id, else every
# Kopia source turns «foreign» — setup.sh's hint kopia_host_container_id). «Look at the server again» names neither as
# new nor any change (INV-REPLAN), says no kopia_host_container_id; the run after it backs both up as before (no «new,
# kept running», no unannounced warning), and EmbyServer's package is simply rewritten with the new container's image id.
# Pre-release (~7 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-recreated-run
needs tower docker kopia
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup            # the history: EmbyServer's package written by the old container
run0=$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null)
ids0="$(docker inspect -f '{{.Id}}' EmbyServer kopia 2>/dev/null | cut -c1-12 | paste -sd' ' -)"
state CT-RECREATED EmbyServer
state CT-RECREATED kopia
ids1="$(docker inspect -f '{{.Id}}' EmbyServer kopia 2>/dev/null | cut -c1-12 | paste -sd' ' -)"
say "container ids EmbyServer kopia: $ids0 → $ids1"
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    m=$(jq -r '[.messages[]? | select(.code == "kopia_host_container_id") | .code] | join(",")' "$plan")
    [[ -z $m ]] && cell no-host-id-hint 1 "the plan has no kopia_host_container_id hint" \
        || cell no-host-id-hint 0 "the plan says kopia_host_container_id after kopia was re-created: $(jq -c '[.messages[]? | select(.code == "kopia_host_container_id") | .params]' "$plan")"
    nw=$(jq -r '[.apps[]? | select((.new // false) == true or (.status // "") == "new") | .name] | join(",")' "$plan" 2>/dev/null)
    [[ -z $nw ]] && cell plan-nothing-new 1 "the plan names no app as new" || cell plan-nothing-new 0 "the plan names as new: $nw"
fi
flow BK-RUN-MANUAL mode=backup
run1=$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null)
log=$UB/logs/run-$run1.log
evidence "$log" run.log
nl=$(grep -E 'New, keep running:' "$log" 2>/dev/null)
[[ -z $nl ]] && cell run-nothing-new 1 "the run keeps nothing running as new" || cell run-nothing-new 0 "the run says: $nl"
man=$PLACE/apps/EmbyServer/manifest.json
img_now=$(docker inspect -f '{{.Image}}' EmbyServer 2>/dev/null)
mr=$(jq -r '.run // empty' "$man" 2>/dev/null)
mi=$(jq -r '[.containers[]? | select(.name == "EmbyServer") | .image_id][0] // empty' "$man" 2>/dev/null)
[[ $mr == "$run1" && $mr != "$run0" && $mi == "$img_now" ]] \
    && cell package-rewritten 1 "apps/EmbyServer/manifest.json from run $mr, image id of the container now" \
    || cell package-rewritten 0 "apps/EmbyServer/manifest.json run «$mr» (runs $run0 → $run1), image_id «${mi:0:19}» vs now ${img_now:0:19}"
check standard
check final
scenario_end
