#!/bin/bash
# The user adds a share (Unraid's «Add Share»): emhttpd also resets the exclusive share nextcloud's root to 0777
# nobody:users, Nextcloud refuses its data folder, and the next backup fails (result failed, nc_datadir_readable)
# instead of going on with the others. Red until fixed (H5 finding, 1.66.6): the flow and INV-BACKUP-AFTER.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-add-share-nc-0777-run
needs tower docker exclusive
nc_data_closed                               # the base as apps.sh made it — the state below breaks it, as a user's click does
state SH-POOL-ONLY uso-wip-h5-addshare
snap_before
expect_changes 'share|uso-wip-h5-addshare|*'      # the new share: its proposal is a real change
flow BK-RUN-MANUAL mode=backup
check standard
check final
scenario_end
