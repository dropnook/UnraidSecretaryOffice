#!/bin/bash
# Ms. Moverelli's yes run leaves no emptied folder on the pool (finding of batch A, Tower 2026-10-18 22:22): old files
# in two folders under one parent (uso-yes/m3t/a-small, uso-yes/m3t/z-big) go to the array — both folders AND the
# emptied parent m3t go from the pool (INV-LEFTOVER), the share's own folder stays. Until 1.66.7 m3t stayed: the cleanup
# gave up on a parent the first time it still held a sibling. Folders empty before the run (m3t/empty, m3t/e1/e2 — the
# owner's server: .tmp/, shares/counters/ stayed on the pool) go to the array too, as Unraid's mover takes them.
# $1 = an older release (v1.66.7): must be red. (~1 min)
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-yes-nested-tidy
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
undo_push bash -c 'rm -rf /mnt/*/uso-yes/m3t'        # what reached the array (runs last)
share_exists uso-yes && [[ $(_st_share_ini uso-yes useCache) == yes ]] || _row state uso-yes red "the base has no yes share uso-yes"
# 40 days old (her rule for uso-yes: 30)
put_files M3T cache uso-yes/m3t/a-small 20 --size 4K --age 40d && put_files M3T cache uso-yes/m3t/z-big 2 --size 64M --age 40d \
    || _row state put_files red "the files could not be written"
_st_part M3T cache uso-yes/m3t/empty && _st_part M3T cache uso-yes/m3t/e1/e2 && chown -R nobody:users /mnt/cache/uso-yes/m3t \
    || _row state empty-folders red "the empty folders could not be made"
undo_push files_unput M3T
track_share uso-yes
snap_before
flow MV-RUN
moved=$(find /mnt/disk*/uso-yes/m3t -type f 2>/dev/null | wc -l)
((moved == 22)) && cell moved 1 "all 22 old files of uso-yes/m3t are on the array" || cell moved 0 "$moved of 22 old files reached the array"
if [[ -d /mnt/cache/uso-yes/m3t ]]; then cell parent-gone 0 "the emptied parent uso-yes/m3t is still on the pool: $(find /mnt/cache/uso-yes/m3t | head -3 | tr '\n' ' ')"
else cell parent-gone 1 "uso-yes/m3t is gone from the pool"; fi
e=$(find /mnt/disk*/uso-yes/m3t -type d \( -path '*/m3t/empty' -o -path '*/m3t/e1/e2' \) 2>/dev/null | wc -l)
((e == 2)) && cell empty-folders-moved 1 "m3t/empty and m3t/e1/e2 (empty before the run) are on the array" \
    || cell empty-folders-moved 0 "$e of the 2 folders empty before the run reached the array"
[[ -d /mnt/cache/uso-yes ]] && cell share-root-kept 1 "the share's folder on the pool stays" || cell share-root-kept 0 "the share's folder on the pool is gone"
check standard
scenario_end
