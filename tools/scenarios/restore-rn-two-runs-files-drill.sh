#!/bin/bash
# Cell R20 of matrix.md, its first part: a backup run is at work (the cron's, RN-TWO-RUNS) and the user asks Mr. Restori
# for a folder back and for a drill. Both are refused while the engine's lock is held — the restore's plan blocks with
# restore_busy_backup (rsBusy: who holds it, the run), the drill with the same — nothing is started, and both go
# through once the run is done (the drill: INV-DRILL-GREEN). Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-two-runs-files-drill
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
U=/mnt/user/appdata/emby                     # EmbyServer's app folder: he restores app and VM folders
put_files R20 cache appdata/emby/uso-wip-d-r20 3 || _row state put_files red "the files could not be written"
undo_push files_unput R20
undo_push bash -c 'rm -rf /mnt/cache/appdata/emby/uso-wip-d-r20 /mnt/cache/appdata/emby.aside-* /mnt/cache/appdata/emby.restored-* /mnt/cache/appdata/emby.putback-*'
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the moment, and the packages for the drill
snap_before
minute_wait
state RN-TWO-RUNS backup
flow RS-FILES mode=beside path="$U" refuse=ok
k=$(ask_last '.error.key // empty'); held=$(lock_held "$UB/state/lock" && echo held || echo free)
[[ $k == restore_busy_backup ]] && cell files-busy 1 "the restore's plan: $k ($(jq -c '[.blockers[]? | select(.key == "restore_busy_backup") | .params][0]' "$KEEP/rs-preview.json" 2>/dev/null))" \
    || cell files-busy 0 "the restore while the run works (lock $held): ${k:-accepted}"
flow RS-DRILL refuse=ok
k=$(ask_last '.error.key // empty'); held=$(lock_held "$UB/state/lock" && echo held || echo free)
[[ $k == restore_busy_backup ]] && cell drill-busy 1 "the drill: $k" || cell drill-busy 0 "the drill while the run works (lock $held): ${k:-accepted}"
second_run_wait RN-TWO-RUNS 1800
wait_backup 1800
flow RS-FILES mode=beside path="$U"
check standard
check final
scenario_end
