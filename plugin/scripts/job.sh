#!/bin/bash
# Unraid Secretary Office - what the plugin's cron file calls (written by the
# office: agent/lib/house.php, officeJobSetSchedule()).
#
#   job.sh backup      Mr. Backupsy's nightly run (the engine in backup/)
#   job.sh snapshots   Ms. Snapshotini's schedules: the snapshots that are due
#
# Only while the array is started: the office's data lies in appdata, and
# nothing may land in /mnt while it is a bare RAM folder.

DIR=/usr/local/emhttp/plugins/unraid-secretary-office

grep -q '^fsState="Started"' /var/local/emhttp/var.ini 2>/dev/null || exit 0
cd / || exit 1

case "$1" in
    backup)    exec bash "$DIR/backup/backup.sh" ;;
    snapshots) exec php "$DIR/agent/agent.php" job snapshot-plans ;;
    *)         echo "Usage: bash $0 backup|snapshots"; exit 2 ;;
esac
