#!/bin/bash
# Cell R21 of matrix.md (nightly — 100 000 files): EmbyServer's app folder with 100 000 empty files of ours in it
# (SH-100K in appdata/emby — Mr. Restori restores app folders), backed up (the moment). His look (RS-SCAN) answers
# quickly while the sizes are measured in the background (rsDu, RS_DU_PARALLEL), his page stays usable meanwhile (each
# look within 5 s, INV-TICK), and the copy beside (RS-FILES, the dialog waiting for the measured sizes) brings back all
# 100 000. Nightly (~15 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-100k-scan-files
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
put_many R21 cache appdata 100000 emby/uso-wip-f-r21 || _row state put_many red "the files could not be written"
undo_push files_unput R21
undo_push bash -c 'rm -rf /mnt/cache/appdata/emby/uso-wip-f-r21 /mnt/cache/appdata/emby.restored-* /mnt/cache/appdata/emby.aside-* /mnt/cache/appdata/emby.putback-*'
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the moment
snap_before
# his look while the sizes are measured: the preview queues the du, the page asks again and again
t0=$(date +%s%N); ask restore.preview '{"kind":"files","path":"/mnt/user/appdata/emby","mode":"copy"}' 300 >/dev/null
ms=$((($(date +%s%N) - t0) / 1000000)); m=$(ask_last '.preview.sizes.measuring // false')
[[ $m == true ]] && ((ms < 5000)) && cell du-background 1 "his preview answered in $ms ms, the sizes measured in the background" \
    || cell du-background 0 "his preview: $ms ms, measuring «$m» (the 100 000 files' du not in the background, or done before it answered)"
slow=0 max=0
for i in $(seq 1 20); do
    t0=$(date +%s%N); ask restore.refresh '{}' 60 >/dev/null; ms=$((($(date +%s%N) - t0) / 1000000))
    ((ms > max)) && max=$ms; ((ms > 5000)) && slow=$((slow + 1)); sleep 2
done
((slow == 0)) && cell page-usable 1 "20 looks while measuring, the slowest $max ms" || cell page-usable 0 "$slow of 20 looks took longer than 5 s (slowest $max ms)"
flow RS-FILES mode=beside path=/mnt/user/appdata/emby
c=$(find /mnt/cache/appdata/emby.restored-*/uso-wip-f-r21 -type f 2>/dev/null | wc -l)
((c == 100000)) && cell all-back 1 "the copy beside holds all 100 000" || cell all-back 0 "the copy beside holds $c of 100 000"
check standard
scenario_end
