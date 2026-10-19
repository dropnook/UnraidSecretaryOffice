#!/bin/bash
# Cell M9 of matrix.md: Ms. Dustdevil's storeroom (.UnraidSecretaryOffice-trash, one put-away run with her manifest) in a
# yes share on the pool, then Ms. Moverelli's real run. Unraid's mover takes a hidden folder like any other, so it may
# travel to the array (she behaves like Unraid's mover; the storeroom is no exclude of hers) — but then Ms. Dustdevil
# must still find it: her look lists the run before and after, with its item, and «Put back» stays possible (the run's
# manifest and files whole wherever they went). Scratch share uso-wip-c-store. Pre-release (~1 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-storeroom-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-store RUNID=20260901-120000 T=.UnraidSecretaryOffice-trash
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-STOREROOM "$S"
nc_data_closed
put_files C-M9 cache "$S" 3 --size 1M || _row state files red "the share's files could not be written"
undo_push files_unput C-M9
# her look: the put-away run of this share's storeroom (by its stamp and the share's path in its item)
found() { ask cleanup.refresh '{}' 300 >/dev/null || return 1
    jq -c --arg s "$S" --arg r "$RUNID" '[.trash.runs[]? | select(((.stamp // .id // .name // "") | tostring | contains($r)) and (tostring | contains($s)))] | first // empty' "$D/cleanup.json"; }
r0=$(found)
[[ -n $r0 ]] && say "Ms. Dustdevil lists it before the run: $(head -c 300 <<<"$r0")" || _row state SH-STOREROOM red "Ms. Dustdevil doesn't list the storeroom's run before the run: $(jq -c '[.trash.runs[]? | .stamp // .id // .name]' "$D/cleanup.json" 2>/dev/null | head -c 300)"
expect_changes "share|$S|*"                 # a new share for Mr. Backupsy's setup too
track_share "$S"
snap_before
flow MV-RUN
where=$(for b in $(bases); do [[ -d /mnt/$b/$S/$T/$RUNID ]] && printf '%s ' "$b"; done)
say "the storeroom's run lies on: ${where:-nowhere}"
m=$(find /mnt/*/"$S/$T/$RUNID" -name manifest.json 2>/dev/null | head -1)
db=$(find /mnt/*/"$S/$T/$RUNID/appdata/uso-old-app/config" -name settings.db 2>/dev/null | head -1)
[[ -n $m && -n $db ]] && cell storeroom-whole 1 "its manifest and files are whole on ${where:-?}" \
    || cell storeroom-whole 0 "after her run: manifest ${m:-missing}, settings.db ${db:-missing} (on: ${where:-nowhere})"
r1=$(found)
[[ -n $r1 ]] && cell dustdevil-finds 1 "Ms. Dustdevil still lists the run (now on ${where:-?})" \
    || cell dustdevil-finds 0 "Ms. Dustdevil no longer lists her run $RUNID of $S — it now lies on ${where:-nowhere}"
bubble_look storeroom-run cleanup moverelli
look=$(ls -d "$KEEP"/looks/*-storeroom-run 2>/dev/null | tail -1)
[[ -n $look ]] && queue_mac INV-PAGE page_clean cleanup "$look/states" "$PAGE_LANGS"
check standard
scenario_end
