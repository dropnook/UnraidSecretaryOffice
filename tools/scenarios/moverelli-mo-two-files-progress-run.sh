#!/bin/bash
# BF3 of batch F (M15, seen once): her live progress named the small file before a big one for the whole time the big one
# moved, with 0 bytes done — her card is written at most once a second (and per 5 % of the share), and a file that
# started in the same second as the one before was never named. Here one folder of a share holds twenty 1 MB files and
# one of 4 GiB (media has no age rule; ZFS lists a folder in hash order, so the big one goes somewhere among the small
# ones — until 2026-10-22 two folders, and the big one's went first in half the runs; now about 1 in 21: that run is
# «skipped», no verdict, the cell judges only a run where at least one small file went before it). «Run now» (MV-RUN); while the big file is on its
# way (some of its bytes on the array, not all) every look at her progress names it (cell named) and its bytes done
# (file_bytes — what the card's rate comes from) grow at the rate the bytes really arrive on the array, within a factor
# of 2 (cell rate). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-two-files-progress-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_files BF3 cache media/uso-wip-f-bf3s 20 --name a-small.bin && put_files BF3 cache media/uso-wip-f-bf3s 1 --size 4G --name z-big.bin \
    || _row state put_files red "the files could not be written"
undo_push files_unput BF3
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-f-bf3s /mnt/*/media/uso-wip-f-bf3b'
big=$(stat -c %s /mnt/cache/media/uso-wip-f-bf3s/z-big.bin 2>/dev/null)
track_share media
snap_before
# two lookers, neither waiting for the other (lib.sh mv_look_*): her progress about eight times a second through one
# resident asker — the file in flight and its bytes done — and the array ten times a second — what of the big file
# arrived (one stat, at most 1 s), how many small ones are over. Until 2026-10-25 one loop started a new php for each
# look and then ran du: while the 4 GiB file (~6 s on Tower) filled the page cache that php waited up to 6 s to start,
# and the run got no look while the file moved
mv_look_progress "$KEEP/progress.jsonl" & ppid=$!
mv_look_array "$KEEP/array.jsonl" '/mnt/disk*/media/uso-wip-f-bf3s/z-big.bin*' '/mnt/disk*/media/uso-wip-f-bf3s' 'a-small-*.bin' & apid=$!
flow MV-RUN
mv_look_stop "$ppid" "$apid"
evidence "$KEEP/progress.jsonl" >/dev/null
evidence "$KEEP/array.jsonl" >/dev/null
# the looks at her while the big file was on its way: the array look that ended before she was asked had some of it,
# the one begun after she answered not all of it — her answer came while it moved, whatever the two lookers' pace
jq -n --slurpfile p "$KEEP/progress.jsonl" --slurpfile a "$KEEP/array.jsonl" '
    ($a | map(select(.arrived != null))) as $a
    | [$p[] | select(.file != null) | . as $x
       | ([$a[] | select(.t <= $x.t0)] | last) as $b | ([$a[] | select(.t0 >= $x.t)] | first) as $n
       | select($b != null and $n != null)
       | {t: .t, file: .file, fb: .fb, before: $b.arrived, arrived: $n.arrived, at: $n.t, small: $n.small}]
    | map(select(.before > 0 and .arrived < '"${big:-0}"'))' >"$KEEP/inflight.json" 2>/dev/null
evidence "$KEEP/inflight.json" >/dev/null
n=$(jq 'length' "$KEEP/inflight.json" 2>/dev/null); n=${n:-0}
first=$(jq -r '.[0].small // empty' "$KEEP/inflight.json" 2>/dev/null)
wrong=$(jq -c '[.[] | select(.file | test("z-big") | not) | .file | split("/") | last] | unique' "$KEEP/inflight.json" 2>/dev/null)
say "while the big file moved: $n looks; small files over when it was first seen: ${first:-?}; looks naming another file: ${wrong:-?}"
if ((n < 2)); then
    _row state SH-BIG-FILE red "fewer than two looks while the big file was on its way ($n) — no verdict"
elif [[ -z $first || $first == 0 ]]; then
    printf 'the big file went first (ZFS hash order, about 1 in 21) — the case BF3 needs did not come: no verdict, run it again\n' >"$KEEP/.skip"
    say "the big file went first — no verdict (skipped)"
elif [[ $wrong == '[]' ]]; then cell named 1 "all $n looks while the big file moved name it ($first small file(s) went before it)"
else cell named 0 "looks while the big file moved name ${wrong} instead"; fi
her=$(jq '[.[] | select(.file | test("z-big")) | select(.fb != null and .fb > 0 and .fb < '"${big:-0}"')] | if length > 1 then ((.[-1].fb - .[0].fb) / (.[-1].t - .[0].t)) else 0 end' "$KEEP/inflight.json" 2>/dev/null)
# the array's own rate over the same span: the array looks right after her first and last of those looks
real=$(jq '[.[] | select(.file | test("z-big")) | select(.fb != null and .fb > 0 and .fb < '"${big:-0}"')] | if length > 1 and .[-1].at > .[0].at then ((.[-1].arrived - .[0].arrived) / (.[-1].at - .[0].at)) else 0 end' "$KEEP/inflight.json" 2>/dev/null)
awk -v r="${her:-0}" -v q="${real:-0}" 'BEGIN { exit !(r > 0 && q > 0 && r < 2 * q && q < 2 * r) }' \
    && cell rate 1 "her file_bytes grew at $(numfmt --to=iec "${her%.*}")/s, the array got $(numfmt --to=iec "${real%.*}")/s" \
    || cell rate 0 "her file_bytes grew at ${her%.*} B/s, the array got ${real%.*} B/s"
check standard
scenario_end
