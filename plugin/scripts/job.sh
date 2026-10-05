#!/bin/bash
# Unraid Secretary Office - what the plugin's cron file calls (written by the
# office: agent/lib/house.php, officeJobSetSchedule()).
#
#   job.sh backup      Mr. Backupsy's nightly run (the engine in backup/)
#   job.sh snapshots   Ms. Snapshotini's schedules: the snapshots that are due
#   job.sh embycache   Jack Emby: EmbyCache (what's watched next onto the pool)
#   job.sh gather      Jack Emby: the media gather (folders together on one disk)
#   job.sh watch       is the agent at work? (agent-watch.cron, written by scripts/agent.sh)
#
# Only while the array is started: the office's data lies in appdata, and
# nothing may land in /mnt while it is a bare RAM folder. The watch looks
# itself (a stopped array resets its count).

DIR=/usr/local/emhttp/plugins/unraid-secretary-office

cd / || exit 1
[[ "$1" == watch ]] && exec bash "$DIR/scripts/agent.sh" watch
grep -q '^fsState="Started"' /var/local/emhttp/var.ini 2>/dev/null || exit 0

case "$1" in
    backup)    exec bash "$DIR/backup/backup.sh" ;;
    snapshots) exec php "$DIR/agent/agent.php" job snapshot-plans ;;
    embycache|gather) exec php "$DIR/agent/agent.php" job "$1" ;;
    *)         echo "Usage: bash $0 backup|snapshots|embycache|gather|watch"; exit 2 ;;
esac
