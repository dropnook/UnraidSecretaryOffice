#!/bin/bash
# office-flows-selftest — wave 1b's proof (H5): one flow per desk on Tower through the button's request (Mr. Backupsy's
# re-plan and a manual backup, Mr. Restori's restore beside, Ms. Moverelli's dry run), the standard and the final
# checks — and the owner's bubble case: a real run of Ms. Moverelli's that moves a prefer share's files from the array
# into the pool while her greeting may say «Old files packed and labelled — off to the array they go!» (moverelli.greet.1,
# seen on 1.66.4 with nextcloud_data). Expected red today (INV-BUBBLE) until the product is fixed: a finding, not a
# scenario bug — never «fixed» here by an allow.
set -u
. "$(dirname "$0")/lib.sh"
scenario_begin office-flows-selftest
needs tower
# a prefer share of our own with files on the array (disk1, disk2) — big enough that her run is still at work when its
# bubble is looked at; its undo removes the share and every file of it, wherever her run put them
state SH-PREFER-ARRAY-FILES uso-wip-h5-prefer
put_files SH-PREFER-ARRAY-FILES disk1 uso-wip-h5-prefer/big 6 --size 192M
[[ -n $PLACE ]] && track_share "$(basename "$(dirname "$PLACE")")"
snap_before

flow BK-REPLAN
flow BK-RUN-MANUAL mode=backup
# a restore beside: the first folder of an app's whose snapshots the run just made (as the page offers it)
unit=$(state_json restore '[.apps[]? | (.folders // .parts // [])[]? | select((.snaps // 0) != 0 and (.whole | not)) | .path] | .[0] // empty')
if [[ -n $unit ]]; then
    flow RS-FILES mode=beside path="$unit"
    restored=$(jq -r '.target.dest // .target.to // empty' "$D/restore/$(fl_last_restore)/plan.json" 2>/dev/null)
    [[ -n $restored && $restored == *.restored-* ]] && undo_push rm -rf -- "$restored"
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
