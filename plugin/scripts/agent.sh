#!/bin/bash
# Unraid Secretary Office - the agent as a service of the plugin.
#
#   agent.sh start     start it (if it isn't running yet) - while the array isn't
#                      started (at boot, before an encrypted array gets its key) the
#                      night watchman's night shift instead
#   agent.sh stop      stop it (and the night shift), quickly: the array may be
#                      waiting (emhttp runs event scripts and waits for them)
#   agent.sh restart
#   agent.sh status
#   agent.sh watch     is the agent at work? (cron, every 5 minutes: job.sh watch)
#   agent.sh array stopping|started   from the event scripts: a line for the night
#                      watchman's book, then the agent goes and the night shift
#                      comes (stopping) or the other way round (started); at the
#                      stop the backup engine's mounts left between runs go, at the
#                      start what a backup run the stop ended left stopped comes
#                      back (backup.sh --unmount / --recover, engine 2.25); a partner's
#                      transfer through the door ends at the stop (partner_release)
#   agent.sh nightshift  the night shift alone (php agent.php nightshift: RAM and
#                      flash only - nothing under /mnt; it ends by itself without
#                      the night watchman's mirror, when the array is started or
#                      the agent runs)
#
# "start" leaves a small supervisor behind (its own session, so "stop" can end
# everything the agent started - a du measuring a share would keep the pool
# busy). The supervisor restarts the agent if it dies. Jobs that must outlive
# the agent (backup runs) go through atd and are not touched.
#
# "watch" looks from outside: the agent touches its heartbeat in RAM ($RUN/agent.json)
# every 20 seconds (the page's green dot; an agent up to 1.32 touched agent.json in
# its data folder - the newer counts). Has it not for more than 10 minutes while
# the array is started, Unraid's notifications hear of it (alert), once per outage,
# and once more (normal) when it is back. The marks are in RAM. A fresh heartbeat
# is all it reads: nothing under /mnt then.
# "start" puts the watch into the crontab (agent-watch.cron on the flash).
#
# Started through bash (event/started, event/stopping, the .plg, cron), never run directly.

PLUGIN=unraid-secretary-office
DIR=/usr/local/emhttp/plugins/$PLUGIN
FLASH=/boot/config/plugins/$PLUGIN
RUN=/var/run/$PLUGIN
SUPERVISOR=$RUN/supervisor.pid
STOPPING=$RUN/stopping
NIGHT_SUPERVISOR=$RUN/nightshift-supervisor.pid
NIGHT_STOPPING=$RUN/nightshift-stopping
ARRAY_EVENTS=$RUN/array-events  # "<time> stop|start" - the night watchman's array lines (WATCH_ARRAY_EVENTS)
LOG=/var/log/$PLUGIN.log          # only what the agent can't put into its own log (RAM)
NOTIFY=/usr/local/emhttp/webGui/scripts/notify
WATCH_CRON=$FLASH/agent-watch.cron
WATCH_LINE="*/5 * * * * bash $DIR/scripts/job.sh watch > /dev/null 2>&1"
WATCH_DOWN=$RUN/watch-down        # since when the agent hasn't checked in (array started)
WATCH_TOLD=$RUN/watch-told        # this outage was reported
WATCH_AFTER=600                   # seconds without a sign of life before Unraid hears of it
# the mark of the office's jobs in atd's queue - the same line as HOST_LAUNCH_MARK in agent/lib/house.php
# (hostLaunch()): the night watchman knows them by it
HOST_LAUNCH_MARK='# written by the Unraid Secretary Office agent'
PROC_MOUNTS=/proc/mounts
BACKUP_STAGE=/run/unraid-backup-stage   # the backup engine's private staging area (UB_STAGE in backup/lib/common.sh)
VAR_INI=/var/local/emhttp/var.ini
# The array runs: "Started" - and Unraid's "Started, formatting/clearing" (fsState Formatting, Clearing: a new
# disk is formatted or cleared for hours while the array runs). One definition: agent.php's ARRAY_RUNNING says the
# same, the backup engine counts them as started too (lib/common.sh array_stopping(): only Stopping and Stopped
# end a run) - tests/run.php compares all three; job.sh uses array_started from here.
ARRAY_RUNNING='Started|Formatting|Clearing'

supervisor_pid() {
    local pid
    pid="$(cat "${1:-$SUPERVISOR}" 2>/dev/null)"
    [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null && grep -qa 'agent.sh' "/proc/$pid/cmdline" 2>/dev/null && echo "$pid"
}

array_started() {
    grep -qE "^fsState=\"($ARRAY_RUNNING)\"" "$VAR_INI" 2>/dev/null
}

supervise() {
    cd / || exit 1
    echo $$ > "$SUPERVISOR"
    local child=
    trap 'touch "$STOPPING"; [[ -n "$child" ]] && kill -TERM "$child" 2>/dev/null' TERM INT HUP
    while [[ ! -e "$STOPPING" ]]; do
        php "$DIR/agent/agent.php" run </dev/null >>"$LOG" 2>&1 &
        child=$!
        wait "$child"
        while kill -0 "$child" 2>/dev/null; do wait "$child"; done    # wait returns early on a signal
        child=
        [[ -e "$STOPPING" ]] && break
        echo "$(date '+%F %T') agent ended - starting it again in 10 s" >>"$LOG"
        sleep 10 &
        wait $!
    done
    rm -f "$SUPERVISOR" "$STOPPING"
}

# The night shift's supervisor (its own session, like the agent's): starts php agent.php nightshift again
# if it fails; an exit 0 means done (the array is started, the agent runs, or there is nothing to watch).
night_supervise() {
    cd / || exit 1
    echo $$ > "$NIGHT_SUPERVISOR"
    local child= code=0
    trap 'touch "$NIGHT_STOPPING"; [[ -n "$child" ]] && kill -TERM "$child" 2>/dev/null' TERM INT HUP
    while [[ ! -e "$NIGHT_STOPPING" ]]; do
        php "$DIR/agent/agent.php" nightshift </dev/null >>"$LOG" 2>&1 &
        child=$!
        wait "$child"
        code=$?
        while kill -0 "$child" 2>/dev/null; do wait "$child"; code=$?; done    # wait returns early on a signal
        child=
        [[ -e "$NIGHT_STOPPING" || "$code" -eq 0 ]] && break
        echo "$(date '+%F %T') night shift ended ($code) - starting it again in 30 s" >>"$LOG"
        sleep 30 &
        wait $!
    done
    rm -f "$NIGHT_SUPERVISOR" "$NIGHT_STOPPING"
}

night_start() {
    if [[ -n "$(supervisor_pid "$NIGHT_SUPERVISOR")" ]]; then
        echo "The night shift is on already."
        return 0
    fi
    mkdir -p "$RUN" && chmod 700 "$RUN"
    rm -f "$NIGHT_STOPPING"
    setsid bash "$DIR/scripts/agent.sh" night-supervise </dev/null >/dev/null 2>&1 &
    echo "Night shift started."
}

# quickly: at the array's start emhttp waits for event/started
night_stop() {
    local pid i
    pid="$(supervisor_pid "$NIGHT_SUPERVISOR")"
    [[ -z "$pid" ]] && return 0
    touch "$NIGHT_STOPPING"
    kill -TERM "$pid" 2>/dev/null
    for ((i = 0; i < 20; i++)); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.5
    done
    pkill -TERM -s "$pid" 2>/dev/null
    sleep 0.2
    pkill -KILL -s "$pid" 2>/dev/null
    rm -f "$NIGHT_SUPERVISOR" "$NIGHT_STOPPING"
    echo "Night shift ended."
}

# start: never the agent and the night shift at once - the array decides which
start() {
    watch_cron
    night_stop
    if ! array_started; then
        night_start
        return 0
    fi
    agent_start
}

agent_start() {
    if [[ -n "$(supervisor_pid)" ]]; then
        echo "The agent is already running."
        return 0
    fi
    mkdir -p "$RUN" && chmod 700 "$RUN"
    rm -f "$STOPPING"
    # tail -c keeps the RAM log small across restarts
    [[ -f "$LOG" ]] && tail -c 65536 "$LOG" >"$LOG.tmp" && mv "$LOG.tmp" "$LOG"
    setsid bash "$DIR/scripts/agent.sh" supervise </dev/null >/dev/null 2>&1 &
    echo "Agent started."
}

stop() {
    night_stop
    agent_stop
}

agent_stop() {
    local pid i
    pid="$(supervisor_pid)"
    if [[ -z "$pid" ]]; then
        echo "The agent is not running."
        return 0
    fi
    touch "$STOPPING"
    kill -TERM "$pid" 2>/dev/null
    # the agent finishes what it is doing (it checks every 150 ms) - at most 10 s
    for ((i = 0; i < 20; i++)); do
        kill -0 "$pid" 2>/dev/null || break
        sleep 0.5
    done
    # whatever is left of the session (a du, a hanging command): gone now
    pkill -TERM -s "$pid" 2>/dev/null
    sleep 0.5
    pkill -KILL -s "$pid" 2>/dev/null
    rm -f "$SUPERVISOR" "$STOPPING"
    echo "Agent stopped."
}

# The watch's line in a cron file of its own (the office rewrites its schedules' file), in root's
# crontab right away: Unraid builds it from the installed plugins' cron files only on update_cron.
watch_cron() {
    local want
    want="# Unraid Secretary Office - is the agent at work? (written by scripts/agent.sh)"$'\n'"$WATCH_LINE"
    if [[ -d "$FLASH" && "$(cat "$WATCH_CRON" 2>/dev/null)" != "$want" ]]; then
        printf '%s\n' "$want" >"$WATCH_CRON.tmp" && mv -f "$WATCH_CRON.tmp" "$WATCH_CRON"
    fi
    if [[ -f "$WATCH_CRON" ]] && ! grep -qF "$WATCH_LINE" /etc/cron.d/root 2>/dev/null; then
        bash /usr/local/sbin/update_cron >/dev/null 2>&1      # its first line is no shebang
    fi
}

# DATA_DIR from the plugin's .cfg, like src/place.php's officePluginDataDir()
data_dir() {
    local d appdata
    d=$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$FLASH/$PLUGIN.cfg" 2>/dev/null | tail -n 1)
    if [[ "$d" != /mnt/* ]]; then
        appdata=$(sed -n 's/^DOCKER_APP_CONFIG_PATH="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' /boot/config/docker.cfg 2>/dev/null | tail -n 1)
        appdata=${appdata:-/mnt/user/appdata}
        d="${appdata%/}/UnraidSecretaryOffice/data"
    fi
    echo "${d%/}"
}

# tell <subject> <short text> <normal|warning|alert> [long text] - like the engine's ub_notify; a click opens the office.
# Unraid names a notification <event>-<second>: one more with the same event in that second overwrites it. The agent,
# its night shift and the backup engine send with the same event - all take turns through one stamp in RAM
# ($RUN/notify.second: the second the last call ended in, under its flock held through the call - at most 10 s
# waited, then it goes anyway); a call starts only after that second. No RAM folder: no guard.
tell() {
    local place link=/SecretaryOffice stamp="$RUN/notify.second" fd="" last now
    [[ -x "$NOTIFY" ]] || return 0
    place=$(sed -n 's/^MENU_PLACE="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$FLASH/$PLUGIN.cfg" 2>/dev/null | tail -n 1)
    [[ "$place" == settings ]] && link=/Settings/SecretaryOffice
    local args=( -e "Unraid Secretary Office" -s "Unraid Secretary Office: $1" -d "$2" -i "$3" -l "$link" )
    [[ -n "${4:-}" ]] && args+=( -m "$4" )
    if [[ -d "$RUN" && ! -L "$stamp" ]] && { exec {fd}>>"$stamp"; } 2>/dev/null; then
        if flock -w 10 "$fd" 2>/dev/null; then
            last=$(head -c 32 "$stamp" 2>/dev/null | tr -dc '0-9')
            now=$(date +%s%N)
            # still in that second: wait until just past it (at most ~1 s)
            if [[ "$last" =~ ^[0-9]+$ && "$now" =~ ^[0-9]{10,}$ ]] && (( ${now:0:${#now}-9} <= last )); then
                now=$(( 1010000 - 10#${now:${#now}-9} / 1000 ))
                sleep "$(( now / 1000000 )).$(printf '%06d' $(( now % 1000000 )))"
            fi
            timeout 60 "$NOTIFY" "${args[@]}" >/dev/null 2>&1 {fd}>&-
            date +%s >"$stamp" 2>/dev/null
            exec {fd}>&-
            return 0
        fi
        exec {fd}>&-
    fi
    timeout 60 "$NOTIFY" "${args[@]}" >/dev/null 2>&1 || true
}

watch() {
    local now data pulse old since minutes
    now=$(date +%s)
    # only while the array is started (without it the agent waits, on purpose); that time doesn't count
    if ! array_started; then
        rm -f "$WATCH_DOWN"
        return 0
    fi
    pulse=$(stat -c %Y "$RUN/agent.json" 2>/dev/null || echo 0)     # the heartbeat in RAM
    if (( now - pulse > 70 )); then
        data=$(data_dir)
        if [[ ! -d "$data" ]]; then           # no data folder (yet): the page says why
            rm -f "$WATCH_DOWN"
            return 0
        fi
        old=$(stat -c %Y "$data/agent.json" 2>/dev/null || echo 0)  # an agent up to 1.32 touched this one
        (( old > pulse )) && pulse=$old
    fi
    mkdir -p "$RUN" && chmod 700 "$RUN"
    if (( now - pulse <= 70 )); then     # checked in (every 20 s) - the page's green dot says the same
        if [[ -e "$WATCH_TOLD" ]]; then
            since=$(cat "$WATCH_TOLD" 2>/dev/null)
            [[ "$since" =~ ^[0-9]+$ ]] || since=$now
            minutes=$(( (now - since) / 60 ))
            rm -f "$WATCH_TOLD"
            tell "Agent running again" "The office's agent is back at work (it was away for about $minutes minutes)." normal
            echo "$(date '+%F %T') watch: the agent is back - Unraid's notifications told" >>"$LOG"
        fi
        rm -f "$WATCH_DOWN"
        return 0
    fi
    since=$(cat "$WATCH_DOWN" 2>/dev/null)
    if [[ ! "$since" =~ ^[0-9]+$ ]]; then
        echo "$now" >"$WATCH_DOWN"         # first seen away now: the 10 minutes start
        return 0
    fi
    (( now - since >= WATCH_AFTER )) || return 0
    [[ -e "$WATCH_TOLD" ]] && return 0      # this outage was told already
    (( pulse > 0 && pulse < since )) && since=$pulse
    echo "$since" >"$WATCH_TOLD"
    minutes=$(( (now - since) / 60 ))
    # Unraid's line breaks in the long text are a literal \n (mail, push agents and the archive turn them into lines)
    tell "Agent not running" "The office's agent hasn't checked in for $minutes minutes - the office can't show or do anything." alert \
        "Scheduled backups, snapshots and Jack Emby's runs still start on their own.\nRestart it in a terminal: bash $DIR/scripts/agent.sh restart\nWhat it said last: $LOG and $data/agent.log"
    echo "$(date '+%F %T') watch: the agent hasn't checked in for $minutes minutes - Unraid's notifications told" >>"$LOG"
}

# One word for the shell, quoted
shq() { printf "'%s'" "${1//\'/\'\\\'\'}"; }

# Array started: what a backup run the array stop ended left behind - containers it had stopped, Nextcloud
# in maintenance mode, VMs it shut down; the engine (2.24) notes them in its state folder instead of starting
# anything into the stopping array - comes back now, not with the next run (often the next night):
# backup.sh --recover (engine 2.25) takes the engine's lock, waits for Docker and libvirt to answer and
# brings them back. Handed to atd like the office's other jobs on the host (hostLaunch() in
# agent/lib/house.php, with its mark), so it outlives this event script; emhttp waits for event/started -
# only a look at three small files here.
backup_recover() {
    local ub job
    [[ -f "$DIR/backup/backup.sh" ]] || return 0
    ub="$(data_dir)/unraid-backup"
    [[ -s "$ub/state/stopped" || -s "$ub/state/maintenance" || -s "$ub/state/vms" ]] || return 0
    mkdir -p "$RUN" && chmod 700 "$RUN" || return 0
    job="$RUN/backup-recover.sh"
    printf '%s\n' '#!/bin/sh' "$HOST_LAUNCH_MARK" 'PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin' 'export PATH' \
        "UB_DATA=$(shq "$ub")" 'export UB_DATA' "cd '/'" "exec /bin/bash $(shq "$DIR/backup/backup.sh") --recover </dev/null >/dev/null 2>&1" >"$job" || return 0
    if timeout 10 at -M -f "$job" now >/dev/null 2>&1; then
        echo "$(date '+%F %T') array started: a backup run left things stopped - backup.sh --recover handed to atd" >>"$LOG"
    else
        echo "$(date '+%F %T') array started: backup.sh --recover could not be handed to atd - the next backup run brings them back" >>"$LOG"
    fi
}

# Array stopping: the backup engine's read-only snapshot mounts under /mnt/addons left between runs
# ([general] keep_mounts = yes keeps them until the next run; a run killed hard leaves them too) would
# keep a pool from unmounting - released now (backup.sh --unmount), at most 10 s, never blocking the stop:
# nothing of ours mounted - nothing done (one look at /proc/mounts). Who holds the engine's lock decides
# (its note state/lock-holder.json): a LIVE backup.sh run, check or dry run releases everything under the
# engine's mount roots itself on its way out of a stopping array (engine 2.25, whatever it mounted) - left
# to it, after 2 s for it to end (it may be just past its last look at the array). Anyone else - nobody,
# the setup, a restore, a --recover, a note whose pid is gone or isn't backup.sh, an orphan of a killed run
# (a docker exec that inherited the lock) - mounts nothing there: released without the lock (UB_ARRAY_STOP:
# no wait for it, busy mounts detached lazily). latest.log stays the last run's (UB_KEEP_LATEST): the office
# and Ms. Protocolli keep showing that run, not this unmount.
backup_release() {
    local ub ini lock roots note holder mode pid
    [[ -f "$DIR/backup/backup.sh" ]] || return 0
    ub="$(data_dir)/unraid-backup"; ini="$ub/settings.ini"; lock="$ub/state/lock"
    [[ -f "$ini" ]] || return 0
    # where the engine mounts: [general] mount_root and view_root (else its defaults), and its staging area
    roots=$(awk '/^[[:space:]]*\[/ { g = ($0 ~ /^[[:space:]]*\[general\][[:space:]]*$/); next }
                 g && $0 ~ /^[[:space:]]*(mount_root|view_root)[[:space:]]*=/ {
                     v = substr($0, index($0, "=") + 1); gsub(/^[[:space:]]+|[[:space:]]+$/, "", v); if (v ~ /^\//) print v }' "$ini" 2>/dev/null)
    roots+=$'\n'"/mnt/addons/UnraidSecretaryOffice/snapshots"$'\n'"/mnt/addons/UnraidSecretaryOffice/btrfs-snap"$'\n'"$BACKUP_STAGE"
    ROOTS="$roots" awk 'BEGIN { n = split(ENVIRON["ROOTS"], r, "\n") }
        { for (i = 1; i <= n; i++) if (r[i] != "" && index($2, r[i] "/") == 1) found = 1 }
        END { exit !found }' "$PROC_MOUNTS" 2>/dev/null || return 0
    if [[ -e "$lock" ]] && ! flock -n "$lock" true 2>/dev/null; then
        note=$(head -c 4096 "$ub/state/lock-holder.json" 2>/dev/null | tr -d '\n')
        holder=$(sed -n 's/.*"holder": *"\([^"]*\)".*/\1/p' <<<"$note")
        mode=$(sed -n 's/.*"mode": *"\([^"]*\)".*/\1/p' <<<"$note")
        pid=$(sed -n 's/.*"pid": *\([0-9][0-9]*\).*/\1/p' <<<"$note")
        if [[ "$holder" == backup && "$mode" =~ ^(backup|check|dryrun)$ && "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null \
           && tr '\0' '\n' <"/proc/$pid/cmdline" 2>/dev/null | grep -qE '(^|/)backup\.sh$' \
           && ! flock -w 2 "$lock" true 2>/dev/null; then
            echo "$(date '+%F %T') array stopping: a backup $mode run (PID $pid) holds the engine's lock - it releases the engine's mounts itself" >>"$LOG"
            return 0
        fi
    fi
    if UB_DATA="$ub" UB_KEEP_LATEST=1 UB_ARRAY_STOP=1 timeout -k 1 7 bash "$DIR/backup/backup.sh" --unmount >/dev/null 2>&1; then
        echo "$(date '+%F %T') array stopping: the backup engine's snapshot mounts released" >>"$LOG"
    else
        echo "$(date '+%F %T') array stopping: backup.sh --unmount did not end within 8 s (see its logs/unmount.log)" >>"$LOG"
    fi
}

# Array stopping: a partner's transfer coming in through the door (agent/partner-door.php recv, the forced command
# of a pair's line in authorized_keys) keeps a dataset of the pool busy - ended now: SIGTERM to every door process
# registered in $RUN/partner/door-<pid>.json whose cmdline is partner-door.php, and to its zfs recv / mbuffer named
# there (by their cmdline too), waited for at most 5 s; zfs recv -s keeps its resume token, the partner's next run
# goes on from there. The records go. Nothing registered - nothing done (a glob in RAM).
partner_release() {
    local f pid k kids left=0 waited=0
    local -a pids=()
    for f in "$RUN"/partner/door-*.json; do
        [[ -f "$f" && ! -L "$f" ]] || continue
        pid=${f##*/door-}; pid=${pid%.json}
        if [[ "$pid" =~ ^[0-9]+$ ]] && tr '\0' '\n' <"/proc/$pid/cmdline" 2>/dev/null | grep -q 'partner-door\.php$'; then
            pids+=("$pid")
            kids=$(head -c 4096 "$f" 2>/dev/null | sed -n 's/.*"children":\[\([0-9,]*\)\].*/\1/p')
            for k in ${kids//,/ }; do
                [[ "$k" =~ ^[0-9]+$ ]] && tr '\0' '\n' <"/proc/$k/cmdline" 2>/dev/null | head -n 1 | grep -qE '(^|/)(zfs|mbuffer)$' && pids+=("$k")
            done
        fi
        rm -f "$f"
    done
    (( ${#pids[@]} )) || return 0
    kill -TERM "${pids[@]}" 2>/dev/null
    while (( waited < 50 )); do
        left=0
        for pid in "${pids[@]}"; do kill -0 "$pid" 2>/dev/null && left=1; done
        (( left )) || break
        sleep 0.1
        waited=$((waited + 1))
    done
    echo "$(date '+%F %T') array stopping: a partner's transfer ended (${#pids[@]} process(es) of the door)$( (( left )) && echo ' - still running after 5 s')" >>"$LOG"
    return 0
}

# Array stopping: Mr. Restori's drill or restore (atd jobs, the engine's lock file open on the pool) ended at once - SIGTERM
# to the agent.php its RAM marker names ("<pid> <id>"), the drill's throwaways (label uso.drill) gone; no marker: nothing.
drill_release() {
    local f pid i
    for f in "$RUN/drill.open" "$RUN/restore.open"; do
        [[ -f "$f" && ! -L "$f" ]] || continue
        pid=$(head -c 64 "$f" 2>/dev/null | tr -dc '0-9 ' | cut -d' ' -f1)
        if [[ "$pid" =~ ^[0-9]+$ ]] && grep -qa 'agent.php' "/proc/$pid/cmdline" 2>/dev/null; then
            kill -TERM "$pid" 2>/dev/null
            for ((i = 0; i < 6; i++)); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
            echo "$(date '+%F %T') array stopping: Mr. Restori's ${f##*/} job (PID $pid) asked to end" >>"$LOG"
        fi
        [[ "$f" == */drill.open ]] && timeout 5 docker ps -aq --filter label=uso.drill 2>/dev/null | xargs -r timeout 5 docker rm -f -v >/dev/null 2>&1
        rm -f "$f"
    done
}

# array stopping|started (the event scripts): a line for the night watchman's book (RAM, the newest 50), then the shift change
array_event() {
    local what
    case "$1" in
        stopping) what=stop ;;
        started)  what=start ;;
        *) echo "Usage: bash $0 array stopping|started"; return 2 ;;
    esac
    mkdir -p "$RUN" && chmod 700 "$RUN"
    echo "$(date +%s) $what" >>"$ARRAY_EVENTS"
    tail -n 50 "$ARRAY_EVENTS" >"$ARRAY_EVENTS.tmp" 2>/dev/null && mv -f "$ARRAY_EVENTS.tmp" "$ARRAY_EVENTS"
    if [[ "$what" == stop ]]; then
        stop                # the agent and whatever it started: nothing may keep a pool busy
        drill_release       # Mr. Restori's drill or restore (atd jobs): ended, the drill's throwaways gone
        partner_release     # a partner's transfer coming in through the door - nor that
        backup_release      # the backup engine's mounts left between runs (keep_mounts) - nor those
        night_start         # RAM and flash only
    else
        watch_cron
        night_stop          # first: never two of them
        agent_start
        backup_recover      # what a backup run the stop ended left stopped
    fi
}

# sourced (the tests): the functions only
[[ "${BASH_SOURCE[0]}" == "$0" ]] || return 0

case "$1" in
    start)     start ;;
    stop)      stop ;;
    restart)   stop; start ;;
    status)    [[ -n "$(supervisor_pid "$NIGHT_SUPERVISOR")" ]] && echo "The night shift is on (the array isn't started)."
               if [[ -n "$(supervisor_pid)" ]]; then echo "The agent is running."; else echo "The agent is not running."; exit 3; fi ;;
    supervise) supervise ;;
    night-supervise) night_supervise ;;
    nightshift) night_start ;;
    array)     array_event "$2" ;;
    watch)     watch ;;
    *)         echo "Usage: bash $0 start|stop|restart|status|watch|array stopping|started|nightshift"; exit 2 ;;
esac
