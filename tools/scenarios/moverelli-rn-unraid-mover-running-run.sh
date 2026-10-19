#!/bin/bash
# Cell M11 of matrix.md: Unraid's own mover at work (RN-UNRAID-MOVER-RUNNING: /var/run/mover.pid names a living
# process whose command line says «mover»). (start) «Run now» is refused with moverelli_mover_running — nothing moves.
# (middle) her real run moves 6 × 512 MB (40 days old, uso-yes → the array) and Unraid's mover starts while a file is
# on its way: she stops after that file, for real — the file in flight arrives whole, no further one is begun (at
# most one more within her 5 s look), no .partial, her history says «stopped» why mover. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-rn-unraid-mover-running-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
on() { find /mnt/"$1"/uso-yes/uso-wip-d-m11 -type f ! -name '*.partial' 2>/dev/null | wc -l; }
arr() { echo $(( $(on disk1) + $(on disk2) )); }
put_files M11 cache uso-yes/uso-wip-d-m11 6 --size 512M --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M11
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-d-m11'
track_share uso-yes
state RN-UNRAID-MOVER-RUNNING
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
    wait "$fpid"
    m1=$(arr)
    unraid_mover_fake_stop M11-MID
    ask moverelli.refresh '{}' 120 >/dev/null
    h=$(jq -c '.history[0] | {result, why}' "$D/moverelli.json" 2>/dev/null)
    [[ $(jq -r '"\(.result)/\(.why // "")"' <<<"$h") == stopped/mover ]] && cell stopped-mover 1 "her last run: $h" || cell stopped-mover 0 "her last run: $h"
    (( m1 - m0 <= 2 && m1 < 6 )) && cell after-this-file 1 "$m0 on the array when the mover came, $m1 after: the file in flight finished, no further one begun" \
        || cell after-this-file 0 "$m0 on the array when the mover came, $m1 after (of 6)"
    p=$(find /mnt/disk*/uso-yes /mnt/cache/uso-yes -name '*.partial' 2>/dev/null | paste -sd' ' -)
    [[ -z $p ]] && cell no-partial 1 "no half-moved file" || cell no-partial 0 "left half-moved: $p"
else
    cell stopped-mover 0 "no file of uso-yes on its way within 300 s"
    wait "$fpid"
fi
check standard
scenario_end
