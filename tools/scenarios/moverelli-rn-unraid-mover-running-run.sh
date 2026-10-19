#!/bin/bash
# Cell M11 of matrix.md: Unraid's own mover at work (RN-UNRAID-MOVER-RUNNING: /var/run/mover.pid names a living process
# whose command line says «mover»). (start) «Run now» is refused with moverelli_mover_running — nothing moves. (middle)
# her real run moves 6 × 1.25 GiB (40 days old, uso-yes → the array) and Unraid's mover starts while a file is on its
# way: she stops after that file, for real — once her look (every 5 s, JACK_MOVER_LOOK) has seen the mover and written
# her stop file, only the file in flight arrives (whole), no further one is begun; no .partial, her history says
# «stopped» why mover. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-unraid-mover-running-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# put_files --age writes with the clock set back (states' clock_back): the agent's heartbeat in that time is dated 40
# days back — a fresh one waited for before snap_before, else INV-TICK sees the scenario's own clock as a gap
fresh_beat() { local t; t=$(_sc_now); wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m11 -type f ! -name '*.partial' 2>/dev/null | wc -l; }
arr() { echo $(( $(on disk1) + $(on disk2) )); }
# 6 × 1.25 GiB (two batches: --age stays ≤ 4 GiB each) — 512 MB files each took about a second: all were moved before
# her look (every 5 s) saw the mover
put_files M11 cache uso-yes/uso-wip-d-m11/a 3 --size 1280M --age 40d && put_files M11 cache uso-yes/uso-wip-d-m11/b 3 --size 1280M --age 40d \
    || _row state put_files red "the files could not be written"
undo_push files_unput M11
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m11'
track_share uso-yes
state RN-UNRAID-MOVER-RUNNING
fresh_beat
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_mover_running
[[ $(arr) == 0 ]] && cell start-nothing-moved 1 "nothing moved" || cell start-nothing-moved 0 "$(arr) file(s) moved although refused"
unraid_mover_fake_stop RN-UNRAID-MOVER-RUNNING
# (middle)
t0=$(( $(_sc_now) - 1 ))
(flow_mv_run >/dev/null 2>&1) & fpid=$!
if wait_for 300 "a file of uso-yes on its way" _mv_moving "$t0" uso-yes; then
    m0=$(arr)
    unraid_mover_fake_running M11-MID
    undo_push unraid_mover_fake_stop M11-MID
    say "Unraid's mover «started» with $m0 file(s) of 6 on the array"
    # her watch looks every 5 s (JACK_MOVER_LOOK) and then writes her stop file: from then on no further file may begin
    agent_log_mark mover
    WAIT_SOFT=1 wait_for 30 "her stop file (why mover)" test -f "$D/moverelli/office-stop.json" \
        && ts=$(jq -r '.time // 0' "$D/moverelli/office-stop.json" 2>/dev/null) || ts=0
    wait "$fpid"
    # the stop file goes when her run ends — a run that ended within a poll: the time of her log line instead
    if ((ts == 0)); then
        l=$(agent_log_since mover | grep -m1 "Unraid's mover started" | cut -c1-19)
        # (the office logs in Unraid's time zone — ident.cfg timeZone, agent.php; Unraid's shell has no zoneinfo, PHP has)
        tz=$(_ini_value /boot/config/ident.cfg timeZone)
        [[ -n $l ]] && ts=$(php -r 'date_default_timezone_set($argv[2]); echo (int) strtotime($argv[1]);' "$l" "${tz:-UTC}" 2>/dev/null || echo 0)
    fi
    m1=$(arr)
    late=$(find /mnt/disk1/uso-yes/uso-wip-d-m11 /mnt/disk2/uso-yes/uso-wip-d-m11 -type f ! -name '*.partial' -newerct "@$((ts + 1))" 2>/dev/null | wc -l)
    unraid_mover_fake_stop M11-MID
    ask moverelli.refresh '{}' 120 >/dev/null
    h=$(jq -c '.history[0] | {result, why}' "$D/moverelli.json" 2>/dev/null)
    [[ $(jq -r '"\(.result)/\(.why // "")"' <<<"$h") == stopped/mover ]] && cell stopped-mover 1 "her last run: $h" || cell stopped-mover 0 "her last run: $h"
    if ((ts == 0)); then cell after-this-file 0 "she never wrote her stop file (why mover) within 30 s of Unraid's mover"
    elif ((late <= 1)); then cell after-this-file 1 "she saw the mover at $(date -d "@$ts" '+%T %Z'): $late file(s) finished after that (the one in flight), $m0 → $m1 of 6 on the array"
    else cell after-this-file 0 "she saw the mover at $(date -d "@$ts" +%T), yet $late files finished after that — further files were begun ($m0 → $m1 of 6)"; fi
    p=$(find /mnt/disk*/uso-yes /mnt/cache/uso-yes -name '*.partial' 2>/dev/null | paste -sd' ' -)
    [[ -z $p ]] && cell no-partial 1 "no half-moved file" || cell no-partial 0 "left half-moved: $p"
else
    cell stopped-mover 0 "no file of uso-yes on its way within 300 s"
    wait "$fpid"
fi
check standard
scenario_end
