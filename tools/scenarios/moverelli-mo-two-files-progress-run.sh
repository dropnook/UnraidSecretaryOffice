#!/bin/bash
# BF3 of batch F (M15, seen once): her live progress named the small file before a big one for the whole time the big one
# moved, with 0 bytes done — her card is written at most once a second (and per 5 % of the share), and a file that
# started in the same second as the one before was never named. Here one share holds twenty 1 MB files and one of 4 GiB
# (media has no age rule; ZFS lists a folder in hash order, so the big one goes somewhere among the small ones — the
# cell judges only a run where at least one small file went before it). «Run now» (MV-RUN); while the big file is on its
# way (some of its bytes on the array, not all) every look at her progress names it (cell named) and its bytes done
# (file_bytes — what the card's rate comes from) grow at the rate the bytes really arrive on the array, within a factor
# of 2 (cell rate). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-two-files-progress-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_files BF3 cache media/uso-wip-f-bf3s 20 --name a-small.bin && put_files BF3 cache media/uso-wip-f-bf3b 1 --size 4G --name z-big.bin \
    || _row state put_files red "the files could not be written"
undo_push files_unput BF3
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-f-bf3s /mnt/*/media/uso-wip-f-bf3b'
big=$(stat -c %s /mnt/cache/media/uso-wip-f-bf3b/z-big.bin 2>/dev/null)
track_share media
snap_before
# her progress about three times a second while it runs: the file in flight and its bytes done; what of the big file
# reached the array, how many small ones are over
(while :; do t=$(date +%s.%N)
    php "$SC_DIR/ask.php" --wait 5 --raw moverelli.progress '{}' 2>/dev/null | jq -c --arg t "$t" '{t: ($t|tonumber), file: (.state.progress.file // ""), fb: (.state.progress.file_bytes // null)}'
    printf '{"t":%s,"arrived":%s,"small":%s}\n' "$(date +%s.%N)" "$(du -B1 -c /mnt/disk*/media/uso-wip-f-bf3b/z-big.bin* 2>/dev/null | tail -1 | cut -f1)" \
        "$(find /mnt/disk*/media/uso-wip-f-bf3s -name 'a-small-*.bin' 2>/dev/null | wc -l)"
    sleep 0.3; done) >"$KEEP/progress.jsonl" 2>/dev/null & ppid=$!
flow MV-RUN
kill "$ppid" 2>/dev/null; wait "$ppid" 2>/dev/null
evidence "$KEEP/progress.jsonl" >/dev/null
# the looks while the big file was on its way: a progress look is judged by the array look right after it
jq -sc '[range(0; length - 1) as $i | select(.[$i].file != null and .[$i + 1].arrived != null)
         | {t: .[$i].t, file: .[$i].file, fb: .[$i].fb, arrived: .[$i + 1].arrived, small: .[$i + 1].small}]
        | map(select(.arrived > 0 and .arrived < '"${big:-0}"'))' "$KEEP/progress.jsonl" >"$KEEP/inflight.json" 2>/dev/null
evidence "$KEEP/inflight.json" >/dev/null
n=$(jq 'length' "$KEEP/inflight.json" 2>/dev/null); n=${n:-0}
first=$(jq -r '.[0].small // empty' "$KEEP/inflight.json" 2>/dev/null)
wrong=$(jq -c '[.[] | select(.file | test("z-big") | not) | .file | split("/") | last] | unique' "$KEEP/inflight.json" 2>/dev/null)
say "while the big file moved: $n looks; small files over when it was first seen: ${first:-?}; looks naming another file: ${wrong:-?}"
if ((n < 2)); then
    _row state SH-BIG-FILE red "fewer than two looks while the big file was on its way ($n) — no verdict"
elif [[ -z $first || $first == 0 ]]; then
    _row state SH-BIG-FILE red "the big file went first (no small file before it) — the case BF3 needs did not come; no verdict"
elif [[ $wrong == '[]' ]]; then cell named 1 "all $n looks while the big file moved name it ($first small file(s) went before it)"
else cell named 0 "looks while the big file moved name ${wrong} instead"; fi
her=$(jq '[.[] | select(.file | test("z-big")) | select(.fb != null and .fb > 0 and .fb < '"${big:-0}"')] | if length > 1 then ((.[-1].fb - .[0].fb) / (.[-1].t - .[0].t)) else 0 end' "$KEEP/inflight.json" 2>/dev/null)
real=$(jq '[.[]] | if length > 1 then ((.[-1].arrived - .[0].arrived) / (.[-1].t - .[0].t)) else 0 end' "$KEEP/inflight.json" 2>/dev/null)
awk -v r="${her:-0}" -v q="${real:-0}" 'BEGIN { exit !(r > 0 && q > 0 && r < 2 * q && q < 2 * r) }' \
    && cell rate 1 "her file_bytes grew at $(numfmt --to=iec "${her%.*}")/s, the array got $(numfmt --to=iec "${real%.*}")/s" \
    || cell rate 0 "her file_bytes grew at ${her%.*} B/s, the array got ${real%.*} B/s"
check standard
scenario_end
