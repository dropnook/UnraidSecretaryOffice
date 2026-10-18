#!/bin/bash
# A MariaDB whose app folder is its data folder (Tower: appdata/mariadb-nextcloud = /var/lib/mysql): the engine's own
# .uso-backup folder there is listed by MariaDB as a database (#mysql50#.uso-backup) — from the second run on its dump
# fails and the backup ends «with errors». Red until fixed (H5 finding, 1.66.6): INV-BACKUP-AFTER.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-mariadb-uso-backup-dir-run
needs tower docker
state CT-RUNNING
nc_data_closed
snap_before
flow BK-RUN-MANUAL mode=backup              # the first run: .uso-backup written into the app folder (= MariaDB's datadir)
check standard
check final                                  # INV-BACKUP-AFTER: a second run — errors=0 must hold
scenario_end
