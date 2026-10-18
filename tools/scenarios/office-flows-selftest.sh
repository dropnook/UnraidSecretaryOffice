#!/bin/bash
# office-flows-selftest — wave 1b's proof (H5): one flow per desk on Tower through the button's request (Mr. Backupsy's
# re-plan and a manual backup, Mr. Restori's restore beside, Ms. Moverelli's dry run), the standard and the final
# checks — and the owner's bubble case: a real run of Ms. Moverelli's that moves a prefer share's files from the array
# into the pool while her greeting may say «Old files packed and labelled — off to the array they go!» (moverelli.greet.1,
# seen on 1.66.4 with nextcloud_data). Findings, never «fixed» here by an allow:
#   INV-BUBBLE        moverelli.greet.1 could show while her run moved a prefer share array → pool — red on 1.66.4/1.66.5,
#                     green on 1.66.6 (mover-page-truth: no greeting while she runs)
#   INV-PAGE          her run card showed 50 % while 99 % of the share's bytes were on the pool (live status lagging; for
#                     array → pool the file in flight was measured on the array side) — red on 1.66.5, green on 1.66.6
#   INV-BACKUP-AFTER  `Dump mariadb-nextcloud/#mysql50#.uso-backup failed` — the engine's .uso-backup folder inside
#                     MariaDB's datadir (appdata/mariadb-nextcloud) is listed as a database from the second run on —
#                     still red on 1.66.6 (2026-10-18)
set -u
. "$(dirname "$0")/lib.sh"
scenario_begin office-flows-selftest
needs tower
# the base's prefer share uso-prefer with a folder of ours on the array (disk1, disk2) — big enough that her run is still
# at work when its bubble is looked at. Not a share of our own: Unraid's «Add Share» resets the exclusive nextcloud
# share's root to 0777, and Nextcloud's backup then fails (nc_datadir_readable) — a finding of its own (H5 notes).
# The undo removes the folder on every disk and pool, wherever her run put its files.
_h5_prefer_gone() { local b; for b in $(bases); do rm -rf -- "/mnt/$b/uso-prefer/uso-wip-h5"; done; files_unput H5-PREFER; }
undo_push _h5_prefer_gone
# (a folder of its own per disk: the same name on two disks is a duplicate in the share, which Unraid's move leaves)
put_files H5-PREFER disk1 uso-prefer/uso-wip-h5/d1 10 && put_files H5-PREFER disk2 uso-prefer/uso-wip-h5/d2 10 \
    && put_files H5-PREFER disk1 uso-prefer/uso-wip-h5/big 6 --size 192M || _row state H5-PREFER red "put_files failed"
track_share uso-prefer
nc_data_closed                                 # Tower's Nextcloud data folder closed (flows.sh)
[[ -n $PLACE ]] && track_share "$(basename "$(dirname "$PLACE")")"
snap_before

flow BK-REPLAN
flow BK-RUN-MANUAL mode=backup
# a restore beside: the first folder of an app's whose snapshots the run just made (as the page offers it)
unit=$(state_json restore '[.apps[]? | (.folders // .parts // [])[]? | select((.snaps // 0) != 0 and (.whole | not)) | .path] | .[0] // empty')
if [[ -n $unit ]]; then
    flow RS-FILES mode=beside path="$unit"
    # the copy beside (<folder>.restored-<stamp>, a dataset of its own when the restore made one) goes at the end
    restored=$(jq -r '[.steps[]? | select(.do == "copy") | .to][0] // empty' "$D/restore/$(fl_last_restore)/plan.json" 2>/dev/null)
    _h5_restored_gone() {
        local ds
        ds=$(jq -r '[.steps[]? | select(.do == "copy") | .dataset][0] // empty' "$D/restore/$1/plan.json" 2>/dev/null)
        if [[ -n $ds ]] && zfs list -H "$ds" >/dev/null 2>&1; then zfs destroy -r "$ds"; else rm -rf -- "$2"; fi
    }
    [[ $restored == *.restored-* ]] && undo_push _h5_restored_gone "$(fl_last_restore)" "$restored"
else
    say "no app folder with snapshots to restore from — RS-FILES left out"
    _row flow RS-FILES red "no folder with snapshots after the backup run"
fi
flow MV-DRY
flow MV-RUN                                    # the bubble case: a look while it moves array → pool
expect_warnings new_waiting
check standard
check final
scenario_end
