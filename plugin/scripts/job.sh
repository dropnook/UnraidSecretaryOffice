#!/bin/bash
# Unraid Secretary Office - what the plugin's cron file calls (written by the
# office: agent/lib/house.php, officeJobSetSchedule()).
#
#   job.sh backup      Mr. Backupsy's scheduled run (the engine in backup/) - after a run of Ms. Moverelli's going
#   job.sh snapshots   Ms. Snapshotini's schedules: the snapshots that are due
#   job.sh embycache   Jack Streamer: MediaCache (what's watched next onto the pool; the job keeps EmbyCache's old name)
#   job.sh gather      Jack Emby: the media gather (folders together on one disk)
#   job.sh moverelli   Ms. Moverelli: a real Smart Mover run (the bundled smartmover/)
#   job.sh cleanup     Ms. Dustdevil's tour on schedule (a mark in RAM: her agent part tours as a click would)
#   job.sh logs        Ms. Protocolli's tour on schedule (likewise; a log on a sleeping disk is left out)
#   job.sh watch       is the agent at work? (agent-watch.cron, written by scripts/agent.sh)
#
# Only while the array is started (agent.sh's array_started): the office's data
# lies in appdata, and nothing may land in /mnt while it is a bare RAM folder.
# The watch looks itself (a stopped array resets its count).
#
# Once per job and minute: Unraid's crond reads root's own crontab as well as
# /etc/cron.d/root, so a line that is in both (or twice in one) starts a job
# twice at the same time. The second start ends here quietly, with one line in
# the syslog (the night watchman and Ms. Protocolli see it).

DIR=/usr/local/emhttp/plugins/unraid-secretary-office
RUN=/var/run/unraid-secretary-office

# Nothing of a login's session into the jobs (crond's is clean; a job.sh started by hand over SSH is not): pam removes
# XDG_RUNTIME_DIR=/run/user/0 at the last logout, and from then on docker can't reach a container for the run (agent.sh's CLEAN_ENV)
unset XDG_RUNTIME_DIR SSH_CLIENT SSH_CONNECTION SSH_TTY SSH_AUTH_SOCK
cd / || exit 1
case "$1" in
    backup|snapshots|embycache|gather|moverelli|cleanup|logs|watch) ;;
    *) echo "Usage: bash $0 backup|snapshots|embycache|gather|moverelli|cleanup|logs|watch"; exit 2 ;;
esac

# the minute this job last started, in a stamp of its own, read and written under its lock;
# anything in the way (no RAM folder, no lock): the job runs - twice is better than never
if mkdir -p "$RUN" 2>/dev/null && chmod 700 "$RUN" && exec 9>>"$RUN/job-$1.minute" && flock -w 10 9; then
    now=$(date +%Y%m%d%H%M)
    if [[ "$(cat "$RUN/job-$1.minute" 2>/dev/null)" == "$now" ]]; then
        logger -t unraid-secretary-office "job $1: second start in the same minute skipped"
        exit 0
    fi
    echo "$now" >"$RUN/job-$1.minute"
fi
exec 9>&-          # not into the job: it would hold the lock for hours

[[ "$1" == watch ]] && exec bash "$DIR/scripts/agent.sh" watch
# the array runs - agent.sh's array_started (Started, and Unraid's "Started, formatting/clearing": the engine
# backs up while a new disk is cleared for hours); sourced, agent.sh only defines
source "$DIR/scripts/agent.sh" 2>/dev/null && array_started || exit 0

case "$1" in
    backup)
        # Ms. Moverelli's run going (agent/lib/nightorder.php): the office waits here until it is done and names the wait
        # (UB_ORDER, the run's log); exit 3: nothing to start now. The office not answering: the backup starts as ever
        order=$(php "$DIR/agent/agent.php" job backup-order 2>/dev/null); rc=$?
        (( rc == 3 )) && exit 0
        [[ "$order" =~ ^[a-z_]+:[0-9]+$ ]] && export UB_ORDER="$order"
        # --scheduled (engine 2.82): without settings.ini no run starts - one counted «skipped» line in his history, no alert
        exec bash "$DIR/backup/backup.sh" --scheduled ;;
    snapshots) exec php "$DIR/agent/agent.php" job snapshot-plans ;;
    embycache|gather|moverelli) exec php "$DIR/agent/agent.php" job "$1" ;;
    cleanup|logs) exec php "$DIR/agent/agent.php" job "$1-tour-due" ;;
esac
