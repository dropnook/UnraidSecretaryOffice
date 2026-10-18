#!/bin/bash
# The user adds a share (Unraid's «Add Share»): emhttpd also resets the exclusive share nextcloud's root to 0777
# nobody:users (every share root that isn't group users with an owner other than nobody — 7.4.0-rc.1, the same on «Apply»
# of any share), Nextcloud refuses its data folder (its page answers 503, occ refuses everything). Up to engine 2.61 the
# next backup failed (result failed, nc_datadir_readable) and nothing was backed up (H5 finding, 1.66.6). Since 2.62 the
# run goes on without the maintenance mode — Nextcloud is stopped with the apps — and says it as the warning
# nc_datadir_readable with the lasting fix (chown <Nextcloud's uid>:users, chmod 0770): announced below.
# Also red, a separate question (not this cell's): INV-REPLAN — with the new share the page's Apply list would also send
# the backup place UnraidSecretaryOffice to Kopia (snapshot → kopia; the «auto» default for what is new, 1.66.6).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-add-share-nc-0777-run
needs tower docker exclusive
nc_data_closed                               # the base as apps.sh made it — the state below breaks it, as a user's click does
undo_push nc_data_closed                     # … and closed again after the scenario (the next one finds the base)
state SH-POOL-ONLY uso-wip-h5-addshare
snap_before
expect_changes 'share|uso-wip-h5-addshare|*'      # the new share: its proposal is a real change
expect_warnings nc_datadir_readable               # engine 2.62: Nextcloud refuses to work — said, the run goes on
flow BK-RUN-MANUAL mode=backup
check standard
check final
scenario_end
