#!/bin/bash
# Cell M15 of matrix.md: one big file (SH-BIG-FILE: 3 GiB, 40 days old, uso-yes on the pool, then six 1 MB files after it
# in the same folder) and «Stop after this file» from her joint run's card (MV-NIGHT-STOP) while the big file is on its
# way: that file arrives whole (size = the original's, no .partial), no further one is begun, her history says «stopped»
# by the user; while it moved her live progress named it and gave a rate that fits the bytes that really arrived
# (within a factor of 3). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-big-file-stop
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
put_files M15 cache uso-yes/uso-wip-f-m15 1 --size 3G --age 40d --name a-big.bin && put_files M15 cache uso-yes/uso-wip-f-m15 6 --age 40d --name z-small.bin \
    || _row state put_files red "the files could not be written"
undo_push files_unput M15
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-f-m15'
big=$(stat -c %s /mnt/cache/uso-yes/uso-wip-f-m15/a-big.bin 2>/dev/null)
track_share uso-yes
fresh_beat
snap_before
# the progress while the big file moves: its name, its rate, the bytes that reached the other side
(for i in $(seq 1 60); do php "$SC_DIR/ask.php" --wait 10 --raw moverelli.progress '{}' 2>/dev/null | jq -c --arg t "$(date +%s)" '{t: ($t|tonumber), p: .state.progress}' ; \
   du -b /mnt/disk*/uso-yes/uso-wip-f-m15/a-big.bin* 2>/dev/null | awk '{s+=$1} END {print "{\"arrived\":" s+0 "}"}'; sleep 1; done) >"$KEEP/progress.jsonl" 2>/dev/null & ppid=$!
flow MV-STOP-AFTER-FILE share=uso-yes
kill "$ppid" 2>/dev/null; wait "$ppid" 2>/dev/null
evidence "$KEEP/progress.jsonl" >/dev/null
where=$(find /mnt/disk*/uso-yes/uso-wip-f-m15 -name a-big.bin -printf '%s %p\n' 2>/dev/null | head -1)
small=$(find /mnt/disk*/uso-yes/uso-wip-f-m15 -name 'z-small*' 2>/dev/null | wc -l)
part=$(find /mnt/cache/uso-yes /mnt/disk*/uso-yes -name '*.partial' 2>/dev/null | head -1)
[[ ${where%% *} == "$big" && -z $part ]] && cell big-whole 1 "the big file arrived whole (${big} bytes), no .partial" || cell big-whole 0 "the big file: ${where:-not on the array} (want $big bytes), .partial: ${part:-none}"
((small == 0)) && cell no-further 1 "no further file begun (the 6 small ones still on the pool)" || cell no-further 0 "$small small file(s) moved after the stop"
rate=$(jq -s '[.[] | select(.p.file? and ((.p.file // "") | test("a-big")) and (.p.rate // 0) > 0) | .p.rate] | if length > 0 then (add / length) else 0 end' "$KEEP/progress.jsonl" 2>/dev/null)
real=$(jq -s '[.[] | select(.arrived != null) | .arrived] as $a | [.[] | select(.t != null) | .t] as $t | if ($a | length) > 1 and ($a | max) > ($a | min) then (($a | max) - ($a | min)) / ((($a | map(select(. > 0)) | length) // 1)) else 0 end' "$KEEP/progress.jsonl" 2>/dev/null)
say "her rate (mean while the big file moved): ${rate:-0} B/s; the disks: ~${real:-0} B/s"
awk -v r="${rate:-0}" -v q="${real:-0}" 'BEGIN { exit !(r > 0 && q > 0 && r < 3 * q && q < 3 * r) }' \
    && cell rate 1 "her rate $(numfmt --to=iec "${rate%.*}")/s fits the disks' $(numfmt --to=iec "${real%.*}")/s" \
    || cell rate 0 "her rate ${rate:-none} B/s against the disks' ${real:-0} B/s"
check standard
scenario_end
