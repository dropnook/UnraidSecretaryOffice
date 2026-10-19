#!/bin/bash
# Cell M5 of matrix.md: a file held open (a stream, a writer) while Ms. Moverelli's real run moves its share pool → array.
# SH-OPEN-FILE in the scratch share uso-wip-c-open (yes cache): open.bin held open read+write by a process (uso-open-hold)
# beside 5 more files. As Unraid's mover: the open file stays where it is, whole (its md5 unchanged), counted as left in
# her status, never a .partial; the other five go to the array. Pre-release (~1 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-open-file-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-open
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-OPEN-FILE "$S"
nc_data_closed
put_files C-M5 cache "$S" 5 --size 1M || _row state files red "the other files could not be written"
undo_push files_unput C-M5
where=$(disk_of_file "/mnt/user/$S/open.bin")
sum=$(md5sum <"/mnt/cache/$S/open.bin")
say "open.bin lies on ${where:-?}, held open by $(cat "$KEEP_STASH/SH-OPEN-FILE/open.pids" 2>/dev/null)"
track_share "$S"
snap_before
flow MV-RUN
now=$(disk_of_file "/mnt/user/$S/open.bin")
[[ $now == cache && -f /mnt/cache/$S/open.bin && $(md5sum <"/mnt/cache/$S/open.bin") == "$sum" ]] \
    && cell open-stays 1 "open.bin is still on cache, whole" \
    || cell open-stays 0 "open.bin now on «${now:-nowhere}» (was cache), content $([[ $(md5sum <"/mnt/user/$S/open.bin" 2>/dev/null) == "$sum" ]] && echo same || echo CHANGED/missing)"
n=0; for d in $(bases); do [[ $d == disk* ]] && n=$((n + $(find "/mnt/$d/$S" -maxdepth 1 -type f -name 'f-*.bin' 2>/dev/null | wc -l))); done
((n == 5)) && cell others-moved 1 "the 5 other files are on the array" || cell others-moved 0 "$n of the 5 other files reached the array"
st=$D/moverelli/status.json
left=$(jq -r --arg s "$S" '[.shares[]? | select(.share == $s) | .left] | first // "none"' "$st" 2>/dev/null)
[[ $left =~ ^[1-9] ]] && cell counted-left 1 "her status counts $left left for $S" || cell counted-left 0 "her status: left=$left for $S ($(jq -c --arg s "$S" '[.shares[]? | select(.share == $s)] | first | {state, files, moved, left}' "$st" 2>/dev/null))"
pa=$(find /mnt/*/"$S" -name '*.partial' 2>/dev/null | head -3)
[[ -z $pa ]] && cell no-partial 1 "no .partial on any disk" || cell no-partial 0 "left: $pa"
bubble_look open-run moverelli
look=$(ls -d "$KEEP"/looks/*-open-run 2>/dev/null | tail -1)
[[ -n $look ]] && queue_mac INV-PAGE page_clean moverelli "$look/states" "$PAGE_LANGS"
check standard
scenario_end
