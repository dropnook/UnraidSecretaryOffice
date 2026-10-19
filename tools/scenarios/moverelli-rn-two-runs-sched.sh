#!/bin/bash
# Cell M27 of matrix.md, (b): her run from the page is at work (6 × 512 MB of 40-day-old files in uso-yes to the array)
# and her cron line's joint run starts (job.sh moverelli, as crond would): it doesn't run beside hers — refused, her
# record says why (moverelli_running) — and her page's run goes on to its end, every file moved once. Pre-release
# (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-two-runs-sched
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# put_files --age writes with the clock set back (states' clock_back): an agent napping across that step back sleeps
# until a request rings its doorbell (agent/agent.php agentNap: a relative wait from wall-clock time — the batch's F12),
# its heartbeat stops meanwhile. Not this cell's: her page's look rings it, then a fresh heartbeat before snap_before
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m27b -type f ! -name '*.partial' 2>/dev/null | wc -l; }
put_files M27B cache uso-yes/uso-wip-d-m27b 6 --size 512M --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M27B
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m27b'
track_share uso-yes
fresh_beat
snap_before
t0=$(( $(_sc_now) - 1 ))
(flow_mv_run >/dev/null 2>&1) & fpid=$!
if wait_for 300 "her page's run moving a file" _mv_moving "$t0"; then
    job moverelli && job_wait moverelli 300
    ask moverelli.refresh '{}' 120 >/dev/null
    rec=$(jq -c '[.history[]? | select((.started // 0) >= '"$t0"') | {result, why, by, night}]' "$D/moverelli.json" 2>/dev/null)
    nr=$(jq -c '{result, started}' "$D/moverelli/night.json" 2>/dev/null)
    say "the cron's joint run while her page's run works: her records since: $rec; night.json $nr"
    [[ $(jq '[.[] | select(.result == "refused" or .result == "skipped") | select((.why // "") == "moverelli_running")] | length' <<<"${rec:-[]}") -gt 0 ]] \
        && cell cron-refused 1 "the joint run didn't run beside hers: refused, moverelli_running" \
        || cell cron-refused 0 "no refusal (moverelli_running) of the joint run in her records: $rec"
fi
wait "$fpid"
ask moverelli.refresh '{}' 120 >/dev/null
# «Run now» is the joint run: her part of it is recorded «by night» (nightrun.php) — the newest that isn't the refusal
h=$(jq -c '[.history[]? | select((.started // 0) >= '"$t0"' and .result != "refused")][0] // {} | {result, why, by}' "$D/moverelli.json" 2>/dev/null)
c=$(on cache) a=$(( $(on disk1) + $(on disk2) ))
[[ $(jq -r '.result' <<<"$h") == ok ]] && ((c == 0 && a == 6)) && cell page-run-ends 1 "her page's run ended ok, the 6 files on the array" \
    || cell page-run-ends 0 "her page's run: $h; $c on cache, $a on the array (of 6)"
check standard
scenario_end
