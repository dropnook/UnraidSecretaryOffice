#!/bin/bash
# Cell M27 of matrix.md: two of her runs at once, both ways round. (a) The joint run from her cron line (RN-TWO-RUNS:
# job.sh moverelli) is at work and the user clicks «Run now»: refused moverelli_running (the night's lock, night.lock).
# (b) Her run from the page is at work and the cron line's joint run starts: it doesn't run — refused, her record
# says why (moverelli_running) — and her page's run goes on to its end. 6 × 512 MB (40 days old) in uso-yes keep each
# run at work long enough. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-two-runs-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_files M27 cache uso-yes/uso-wip-d-m27a 6 --size 512M --age 40d && put_files M27 cache uso-yes/uso-wip-d-m27b 6 --size 512M --age 40d \
    || _row state put_files red "the files could not be written"
undo_push files_unput M27
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m27a /mnt/*/uso-yes/uso-wip-d-m27b'
track_share uso-yes
snap_before
# (a)
state RN-TWO-RUNS moverelli
lock_held "$D/moverelli/night.lock" && say "the joint run holds night.lock" || say "note: night.lock isn't held while the joint run works"
flow MV-RUN refuse=ok
k=$(ask_last '.error.key // empty')
[[ $k == moverelli_running ]] && cell a-page-refused 1 "«Run now» during the joint run: $k" || cell a-page-refused 0 "«Run now» during the joint run: ${k:-accepted}"
second_run_wait RN-TWO-RUNS 1800
wait_night 1800
# (b) — the second folder still waits on cache: her page's run has work
t0=$(( $(_sc_now) - 1 ))
(flow_mv_run >/dev/null 2>&1) & fpid=$!
wait_for 300 "her page's run moving a file" _mv_moving "$t0"
job moverelli && job_wait moverelli 300
nr=$(jq -c '{result, why: (.why // .refused // null), started}' "$D/moverelli/night.json" 2>/dev/null)
rec=$(ask moverelli.refresh '{}' 120 >/dev/null; jq -c '[.history[]? | select((.started // 0) >= '"$t0"') | {result, why, by, night}]' "$D/moverelli.json" 2>/dev/null)
say "the cron's joint run while her page's run works: night $nr; her records since: $rec"
[[ $(jq '[.[] | select(.by == "schedule" or .night == true) | select(.result == "refused" or .result == "skipped")] | length' <<<"${rec:-[]}") -gt 0 \
   || $(jq -r '.result // ""' <<<"$nr") == refused ]] && cell b-cron-refused 1 "the joint run didn't run beside hers" \
    || cell b-cron-refused 0 "no refusal of the joint run recorded (night $nr; records $rec)"
wait "$fpid"
ask moverelli.refresh '{}' 120 >/dev/null
h=$(jq -c '[.history[]? | select((.started // 0) >= '"$t0"' and .by != "schedule" and (.night // false) == false)][0] | {result, why}' "$D/moverelli.json" 2>/dev/null)
[[ $(jq -r '.result' <<<"$h") == ok ]] && cell b-page-run-ends 1 "her page's run ended ok" || cell b-page-run-ends 0 "her page's run: $h"
check standard
scenario_end
