#!/bin/bash
# Cell M12 of matrix.md: a share whose secondary storage is a second pool (shareCachePool2=fast) — Ms. Moverelli moves
# pool → pool as Unraid's mover does. SH-PREFER-POOL2 (uso-wip-c-p2, prefer cache ← fast: 10 files on fast): her run
# scans /mnt/fast/<share> and brings them to cache; SH-YES-POOL2 (uso-wip-c-y2, yes cache → fast: 10 files 40 days old
# on cache): they go to fast, never to the array. Her run card's «to» names the real target. Tower has one pool today:
# `needs pool2` (a second pool «fast» — two small vdisks from the coordinator). Pre-release (~1 min) once runnable.
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-pool2-run
needs tower pool2
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
P2=${POOL2:-fast} SP=uso-wip-c-p2 SY=uso-wip-c-y2
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-PREFER-POOL2 "$SP" "$P2"
state SH-YES-POOL2 "$SY" "$P2"
nc_data_closed
track_share "$SP" "$SY"
snap_before
flow MV-RUN
on() { find "/mnt/$1/$2" -type f ! -name '*.partial' 2>/dev/null | wc -l; }
arr() { local n=0 d; for d in $(bases); do [[ $d == disk* ]] && n=$((n + $(on "$d" "$1"))); done; echo $n; }
c=$(on cache "$SP") f=$(on "$P2" "$SP")
((c == 10 && f == 0)) && cell prefer-pool2 1 "the 10 files of $SP came from $P2 to cache" \
    || cell prefer-pool2 0 "$SP after her run: cache $c, $P2 $f (want 10, 0)"
f=$(on "$P2" "$SY") c=$(on cache "$SY") a=$(arr "$SY")
((f == 10 && c == 0 && a == 0)) && cell yes-pool2 1 "the 10 old files of $SY went from cache to $P2, none to the array" \
    || cell yes-pool2 0 "$SY after her run: $P2 $f, cache $c, array $a (want 10, 0, 0)"
log=$D/moverelli/smart_mover.log
grep -qF "/mnt/$P2/$SP" "$log" 2>/dev/null && cell source-pool2 1 "her log names /mnt/$P2/$SP as the source" \
    || cell source-pool2 0 "her log never names /mnt/$P2/$SP"
check standard
scenario_end
