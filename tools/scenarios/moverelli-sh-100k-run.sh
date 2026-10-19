#!/bin/bash
# Cell M14 of matrix.md — nightly: 100 000 small files in one yes share (SH-100K, uso-wip-c-100kmv, empty files on the
# pool) through Ms. Moverelli's «List», «Dry run» and a real run. Measured and held: the list within 2 min, the dry run
# within 10 min and its output naming at most max_list files (her setting, 50 on Tower), the real run within 50 min
# moving all 100 000 to the array (none left on the pool), her progress while at work growing (office-run.json sampled
# every 10 s), the agent's heartbeat steady (INV-TICK). The times go into the report (README «Batch C»). Nightly (~40 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-100k-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-100kmv
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
share_use C-M14 "$S" yes cache || _row state share red "$S could not be made"
undo_push share_unuse C-M14
state SH-100K "$S"
nc_data_closed
expect_changes "share|$S|*"                 # a new share for Mr. Backupsy's setup too
track_share "$S"
snap_before
max=$(jq -r '.settings.global.max_list // 50' "$D/moverelli.json" 2>/dev/null)
t0=$SECONDS; flow MV-LIST; tl=$((SECONDS - t0))
((tl <= 120)) && cell list-time 1 "«List» took $tl s" || cell list-time 0 "«List» took $tl s (> 120)"
t0=$SECONDS; flow MV-DRY; td=$((SECONDS - t0))
((td <= 600)) && cell dry-time 1 "«Dry run» took $td s" || cell dry-time 0 "«Dry run» took $td s (> 600)"
out=$D/moverelli/office-output.txt
shown=$(grep -c "/many/f" "$out" 2>/dev/null)
((shown <= max)) && cell max-list 1 "the dry run's output names $shown files (max_list $max)" || cell max-list 0 "the dry run's output names $shown files (max_list $max)"
prog=$KEEP/progress.jsonl
( while :; do sleep 10; [[ -f $D/moverelli/office-run.json ]] || continue
    jq -c --arg t "$(date +%s)" '{t: ($t | tonumber), finished, progress}' "$D/moverelli/office-run.json" >>"$prog" 2>/dev/null
    [[ $(jq -r '.finished // 0' "$D/moverelli/office-run.json" 2>/dev/null) != 0 ]] && [[ $(wc -l <"$prog") -gt 1 ]] && break
    ((SECONDS - t0 > 3600)) && break; done ) &
smp=$!
t0=$SECONDS; flow MV-RUN; tr=$((SECONDS - t0))
kill "$smp" 2>/dev/null; wait "$smp" 2>/dev/null
evidence "$prog" >/dev/null
((tr <= 3000)) && cell run-time 1 "the real run took $tr s" || cell run-time 0 "the real run took $tr s (> 3000)"
c=$(find "/mnt/cache/$S/many" -type f 2>/dev/null | wc -l) a=0
for d in $(bases); do [[ $d == disk* ]] && a=$((a + $(find "/mnt/$d/$S/many" -type f 2>/dev/null | wc -l))); done
((c == 0 && a == 100000)) && cell moved 1 "all 100 000 on the array, none on the pool" || cell moved 0 "$a on the array, $c still on the pool"
g=$(jq -s '[.[] | .progress | tostring] | unique | length' "$prog" 2>/dev/null)
((${g:-0} >= 3)) && cell progress 1 "$g different progress readings while at work" || cell progress 0 "her progress while at work: ${g:-0} different readings ($(head -c 300 "$prog" 2>/dev/null))"
check standard
scenario_end
