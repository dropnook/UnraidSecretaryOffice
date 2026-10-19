#!/bin/bash
# Cell R6 of matrix.md: EmbyServer's image is gone (CT-IMAGE-GONE: the container stopped, `docker rmi -f` of its image —
# what an update or a prune leaves) after a run wrote its package. Mr. Restori's drill («Practise now…») says
# image_missing as «not checked» for EmbyServer, never «failed», and passes. Ms. Dustdevil's bubbles say only what is
# true now (INV-BUBBLE: a container without a picture only when one has none — the image going takes no icon away).
# Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-image-gone-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the package the drill practises with
state CT-IMAGE-GONE EmbyServer
snap_before
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id // empty' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
[[ -n $id ]] || id=$(ls -t "$D/restore-drill" 2>/dev/null | grep -v '\.json$' | head -1)
j=$D/restore-drill/$id/journal.json
evidence "$j" drill-journal.json
say "drill $id: $(_drill_result "$id")"
im=$(jq -c '[.steps[]? | select(.code == "image_missing") | {name, state, app: (.app // .params.app // null)}]' "$j" 2>/dev/null)
fl=$(jq -c '[.steps[]? | select(.state == "failed") | {name, code}]' "$j" 2>/dev/null)
emby=$(jq -r '[.[] | select(((.name // "") + (.app // "") | test("EmbyServer|emby"; "i")))] | length' <<<"${im:-[]}")
bad=$(jq -r '[.[] | select(.state != "not_checked")] | length' <<<"${im:-[]}")
[[ $emby -ge 1 && $bad == 0 && $fl == '[]' ]] && cell image-missing-not-checked 1 "image_missing for EmbyServer «not checked», nothing failed: $im" \
    || cell image-missing-not-checked 0 "image_missing steps $im, failed steps $fl"
check standard
check final
scenario_end
