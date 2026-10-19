#!/bin/bash
# Cell M27 of matrix.md, (a): the joint run from her cron line is at work (RN-TWO-RUNS: job.sh moverelli, moving
# 6 × 512 MB of 40-day-old files in uso-yes to the array) and the user clicks «Run now»: refused moverelli_running (the
# night's lock, night.lock), nothing started beside it; the joint run goes on to its end. (b) is
# moverelli-rn-two-runs-sched (one real run per scenario: INV-COUNTS compares a run's counts with the disks).
# Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-two-runs-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_files M27A cache uso-yes/uso-wip-d-m27a 6 --size 512M --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M27A
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m27a'
track_share uso-yes
snap_before
state RN-TWO-RUNS moverelli
lock_held "$D/moverelli/night.lock" && say "the joint run holds night.lock" || say "note: night.lock isn't held while the joint run works"
flow MV-RUN refuse=ok
k=$(ask_last '.error.key // empty')
[[ $k == moverelli_running ]] && cell page-refused 1 "«Run now» during the joint run: $k" || cell page-refused 0 "«Run now» during the joint run: ${k:-accepted}"
second_run_wait RN-TWO-RUNS 1800
wait_night 1800
r=$(jq -r '"\(.result) phase=\(.phase)"' "$D/moverelli/night.json" 2>/dev/null)
[[ $r == "ok phase=done" ]] && cell joint-run-ends 1 "the joint run ended: $r" || cell joint-run-ends 0 "the joint run: $r"
check standard
scenario_end
