#!/bin/bash
# Cell M15 of matrix.md: one big file (SH-BIG-FILE: 8 GiB in media on the pool — media has no age rule — then six 1 MB
# files after it in the same folder) and «Stop after this file» from her run's card (MV-NIGHT-STOP) while the big file
# is on its way: that file arrives whole (size = the original's, no .partial), no further one is begun, her history says
# «stopped» by the user; while it moved her live progress named it and its bytes done (file_bytes — what the card's rate
# comes from) grew at the rate the bytes really arrived on the array (within a factor of 2). Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-big-file-stop
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_files M15 cache media/uso-wip-f-m15 1 --size 8G --name a-big.bin && put_files M15 cache media/uso-wip-f-m15 6 --name z-small.bin \
    || _row state put_files red "the files could not be written"
undo_push files_unput M15
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-f-m15'
big=$(stat -c %s /mnt/cache/media/uso-wip-f-m15/a-big.bin 2>/dev/null)
track_share media
snap_before
# her progress twice a second while it runs: the file in flight and its bytes done; what reached the array (the big
# file's bytes, the small ones whole), and whether the stop is asked (her stop file, or the night's — her «Run now» is a
# joint run). The order the files go in is the pool's (ZFS lists a folder in hash order, not by name): the cells read
# what was in flight when the stop came from these looks, never from the names.
md=/mnt/cache/appdata/UnraidSecretaryOffice/data/moverelli
(while :; do t=$(date +%s.%N)
    php "$SC_DIR/ask.php" --wait 5 --raw moverelli.progress '{}' 2>/dev/null | jq -c --arg t "$t" '{t: ($t|tonumber), file: (.state.progress.file // ""), fb: (.state.progress.file_bytes // null)}'
    printf '{"t":%s,"arrived":%s,"small":%s,"stop":%s}\n' "$(date +%s.%N)" "$(du -B1 -c /mnt/disk*/media/uso-wip-f-m15/a-big.bin* 2>/dev/null | tail -1 | cut -f1)" \
        "$(find /mnt/disk*/media/uso-wip-f-m15 -name 'z-small-*.bin' 2>/dev/null | wc -l)" "$([[ -e $md/office-stop.json || -e $md/night-stop.json ]] && echo 1 || echo 0)"
    sleep 0.4; done) >"$KEEP/progress.jsonl" 2>/dev/null & ppid=$!
flow MV-STOP-AFTER-FILE share=media
kill "$ppid" 2>/dev/null; wait "$ppid" 2>/dev/null
evidence "$KEEP/progress.jsonl" >/dev/null
where=$(find /mnt/disk*/media/uso-wip-f-m15 -name a-big.bin -printf '%s %p\n' 2>/dev/null | head -1)
small=$(find /mnt/disk*/media/uso-wip-f-m15 -name 'z-small*' 2>/dev/null | wc -l)
part=$(find /mnt/cache/media /mnt/disk*/media -name '*.partial' 2>/dev/null | head -1)
[[ ${where%% *} == "$big" && -z $part ]] && cell big-whole 1 "the big file arrived whole ($big bytes), no .partial" || cell big-whole 0 "the big file: ${where:-not on the array} (want $big bytes), .partial: ${part:-none}"
# at the first look that saw the stop: was the big file on its way (some bytes, not all), how many small ones were over
at=$(jq -sc '[.[] | select(.stop == 1)] | .[0] // {}' "$KEEP/progress.jsonl" 2>/dev/null)
s0=$(jq -r '.small // empty' <<<"$at"); a0=$(jq -r '.arrived // 0' <<<"$at")
say "at the stop: big file $a0 of $big bytes on the array, ${s0:-?} small one(s) over; at the end $small"
if [[ -z $s0 ]] || ! (( a0 > 0 && a0 < big )); then
    _row state SH-BIG-FILE red "the stop did not come while the big file was on its way (at the stop: $a0 of $big bytes) — no verdict on «no further»"
elif ((small == s0)); then cell no-further 1 "no further file begun after the big one ($s0 small one(s) went before it, $((6 - small)) still on the pool)"
else cell no-further 0 "$((small - s0)) small file(s) begun after the stop, while the big file was the one in flight"; fi
# rates: her file_bytes of a-big over time, the array's bytes of it over the same span
her=$(jq -s '[.[] | select((.file // "") | test("a-big")) | select(.fb != null)] | if length > 1 then ((.[-1].fb - .[0].fb) / (.[-1].t - .[0].t)) else 0 end' "$KEEP/progress.jsonl" 2>/dev/null)
real=$(jq -s '[.[] | select(.arrived != null and .arrived > 0 and .arrived < '"$big"')] | if length > 1 then ((.[-1].arrived - .[0].arrived) / (.[-1].t - .[0].t)) else 0 end' "$KEEP/progress.jsonl" 2>/dev/null)
n=$(jq -s '[.[] | select((.file // "") | test("a-big"))] | length' "$KEEP/progress.jsonl" 2>/dev/null)
say "while the big file moved: $n looks naming it; her bytes done ${her%.*} B/s, the array ${real%.*} B/s"
awk -v r="${her:-0}" -v q="${real:-0}" 'BEGIN { exit !(r > 0 && q > 0 && r < 2 * q && q < 2 * r) }' \
    && cell rate 1 "her file_bytes grew at $(numfmt --to=iec "${her%.*}")/s, the array got $(numfmt --to=iec "${real%.*}")/s" \
    || cell rate 0 "her file_bytes grew at ${her%.*} B/s, the array got ${real%.*} B/s ($n looks named the file)"
check standard
scenario_end
