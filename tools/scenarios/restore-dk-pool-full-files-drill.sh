#!/bin/bash
# Cell R9 of matrix.md, the pool: cache (nearly) full — 1 GiB left (DK-POOL-FULL, a refreservation, nothing written) —
# and a 1.5 GB folder of uso-only to bring back beside the live one from the moment the run made before the fill.
# Mr. Restori's plan blocks with the numbers (restore_no_space {need, free}: rsFree), nothing is started or written;
# his drill, on the same full pool, never ends «failed»: what can't be tried for want of room is «not checked» with
# its reason. The room comes back before INV-BACKUP-AFTER. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-dk-pool-full-files-drill
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
U=/mnt/user/uso-only/uso-wip-d-r9
put_files R9 cache uso-only/uso-wip-d-r9 3 --size 512M || _row state put_files red "the files could not be written"
undo_push files_unput R9
undo_push bash -c 'rm -rf /mnt/cache/uso-only/uso-wip-d-r9 /mnt/cache/uso-only/uso-wip-d-r9.*'
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the moment, and the packages for the drill
state DK-POOL-FULL cache 1024
track_share uso-only
snap_before
n0=$(find "$D/restore" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)
flow RS-FILES mode=beside path="$U" refuse=ok
b=$(jq -c '[.blockers[]? | select(.key == "restore_no_space") | .params][0] // null' "$KEEP/rs-preview.json" 2>/dev/null)
need=$(jq -r '.need // 0' <<<"$b") free=$(jq -r '.free // 0' <<<"$b")
[[ $b != null ]] && ((need > 0 && free > 0)) && cell blocked-with-number 1 "restore_no_space: need $((need >> 20)) MiB, free $((free >> 20)) MiB" \
    || cell blocked-with-number 0 "the plan's blockers: $(jq -c '[.blockers[]?.key]' "$KEEP/rs-preview.json" 2>/dev/null) (want restore_no_space with need and free)"
n1=$(find "$D/restore" -mindepth 1 -maxdepth 1 -type d 2>/dev/null | wc -l)
left=$(find /mnt/cache/uso-only -maxdepth 1 -name 'uso-wip-d-r9.*' 2>/dev/null | paste -sd' ' -)
((n1 == n0)) && [[ -z $left ]] && cell nothing-started 1 "no restore job made, nothing beside the folder" || cell nothing-started 0 "jobs $n0 → $n1, beside: ${left:-none}"
flow RS-DRILL refuse=ok
ask restore.drill_state '{}' 300 >/dev/null
r=$(ask_last '[.drills[]?][0] | "\(.result) \(.counts // {} | tostring)"')
said=$(ask_last '.error.key // empty')
if [[ -n $said ]]; then
    cell drill-not-failed 1 "the drill refused at once: $said"
elif [[ $r == failed* ]]; then
    j=$(ls -td "$D"/restore-drill/2* 2>/dev/null | head -1)/journal.json
    evidence "$j" drill-journal.json >/dev/null 2>&1
    cell drill-not-failed 0 "the drill on a full pool ended failed: $(jq -r '[.steps[]? | select(.state == "failed") | "\(.name // .do) \(.code)"] | join(", ")' "$j" 2>/dev/null)"
else
    cell drill-not-failed 1 "the drill: $r"
fi
check standard
pool_unfill DK-POOL-FULL                     # the room back before INV-BACKUP-AFTER's run
check final
scenario_end
