#!/bin/bash
# Cell R6 of matrix.md: a database container's image is gone (CT-IMAGE-GONE on mariadb-nextcloud: the container stopped,
# `docker rmi -f` of its image — what an update or a prune leaves) after a run wrote its package with the dump. Mr.
# Restori's drill («Practise now…») plays a dump in a throwaway container of the dump's own image, so that image is
# what it needs (restore-drill.php drillDumpCheck: image_missing before anything else); it says image_missing as «not
# checked» for mariadb-nextcloud's dump, never «failed», and passes. (Not EmbyServer, zustaende.md's example: an app
# without a dump has no step that needs its image — first run 2026-10-19: package_ok, nothing to say.) Ms. Dustdevil's bubbles say only what is
# true now (INV-BUBBLE: a container without a picture only when one has none — the image going takes no icon away).
# Pre-release (~4 min).
# RED on 1.66.8, not this cell's (its cell is green): INV-BACKUP-AFTER — the database stopped (CT-IMAGE-GONE stops it)
# under a running Nextcloud makes the run fail (backup.sh ~1213, see restore-ct-stopped-db-db).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-image-gone-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the package the drill practises with
state CT-IMAGE-GONE mariadb-nextcloud
snap_before
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id // empty' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
[[ -n $id ]] || id=$(ls -t "$D/restore-drill" 2>/dev/null | grep -v '\.json$' | head -1)
j=$D/restore-drill/$id/journal.json
evidence "$j" drill-journal.json
say "drill $id: $(_drill_result "$id")"
im=$(jq -c '[.steps[]? | select(.code == "image_missing") | {name, state, app: (.app // .params.app // null)}]' "$j" 2>/dev/null)
fl=$(jq -c '[.steps[]? | select(.state == "failed") | {name, code}]' "$j" 2>/dev/null)
emby=$(jq -r '[.[] | select((.name // "") == "mariadb-nextcloud")] | length' <<<"${im:-[]}")
bad=$(jq -r '[.[] | select(.state != "not_checked")] | length' <<<"${im:-[]}")
[[ $emby -ge 1 && $bad == 0 && $fl == '[]' ]] && cell image-missing-not-checked 1 "image_missing for mariadb-nextcloud «not checked», nothing failed: $im" \
    || cell image-missing-not-checked 0 "image_missing steps $im, failed steps $fl"
check standard
check final
scenario_end
