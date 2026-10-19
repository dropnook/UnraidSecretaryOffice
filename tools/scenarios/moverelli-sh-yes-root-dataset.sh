#!/bin/bash
# Ms. Moverelli removes an emptied share root on a pool as Unraid's mover does (the owner's server, 2026-10-19: a prefer
# share's dataset on its second pool stayed, empty and without snapshots — Unraid's mover removes it). A scratch yes share
# uso-wip-rootds on cache, its root a dataset of its own (made the way shfs makes it), 5 old files: her run → the files
# on the array, the dataset gone (Unraid's move binary destroys it when handed the root; a plain rmdir fails «busy»),
# said in her log, INV-LEFTOVER green. Until 1.66.8 the dataset stayed: her folder pass never handed over a pool's share
# root. $1 = an older release (v1.66.8): must be red. (~1 min)
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-yes-root-dataset
needs tower zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
s=uso-wip-rootds
undo_push nc_data_closed                             # Unraid's «Delete» may open Nextcloud's data folder again (runs last)
undo_push share_unuse ROOTDS                         # the files wherever the run put them, the share (Unraid's «Delete»)
share_use ROOTDS "$s" yes cache || _row state "$s" red "the scratch yes share $s could not be made"
put_files ROOTDS cache "$s/sub" 5 --size 4K --age 40d || _row state put_files red "the files could not be written"
ds=$(_st_dataset_of "/mnt/cache/$s")
[[ $ds == cache/$s ]] || _row state dataset red "the share's root on cache is no dataset of its own (${ds:-a plain folder})"
nc_data_closed                               # Unraid's «Add Share» opened Nextcloud's data folder (0777)
expect_changes "share|$s|*"                  # the new share: its proposal is a real change
snap_before
log=$D/moverelli/smart_mover.log
at=$(stat -c %s "$log" 2>/dev/null || echo 0)
flow MV-RUN
moved=$(find /mnt/disk*/"$s"/sub -type f 2>/dev/null | wc -l)
((moved == 5)) && cell moved 1 "all 5 files of $s are on the array" || cell moved 0 "$moved of 5 files of $s reached the array"
if zfs list -H "cache/$s" >/dev/null 2>&1 || [[ -e /mnt/cache/$s ]]; then
    cell root-dataset-gone 0 "the emptied share root is still on the pool: $(zfs list -H -o name,mountpoint,mounted "cache/$s" 2>&1 | head -1)"
else
    cell root-dataset-gone 1 "the dataset cache/$s is gone, /mnt/cache/$s too"
fi
new=$(tail -c +$((at + 1)) "$log" 2>/dev/null)
grep -qF "share folder /mnt/cache/$s removed (empty)" <<<"$new" \
    && cell said 1 "her log: share folder /mnt/cache/$s removed (empty)" || cell said 0 "her log doesn't say the share folder went"
check standard
scenario_end
