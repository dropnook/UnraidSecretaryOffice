#!/bin/bash
# Unraid Secretary Office - the agent as a service of the plugin.
#
#   agent.sh start     start it (if it isn't running yet)
#   agent.sh stop      stop it, quickly: the array may be waiting (emhttp runs
#                      event scripts and waits for them)
#   agent.sh restart
#   agent.sh status
#   agent.sh watch     is the agent at work? (cron, every 5 minutes: job.sh watch)
#
# "start" leaves a small supervisor behind (its own session, so "stop" can end
# everything the agent started - a du measuring a share would keep the pool
# busy). The supervisor restarts the agent if it dies. Jobs that must outlive
# the agent (backup runs) go through atd and are not touched.
#
# "watch" looks from outside: the agent touches agent.json in its data folder
# every 20 seconds (the page's green dot). Has it not for more than 10 minutes
# while the array is started, Unraid's notifications hear of it (alert), once
# per outage, and once more (normal) when it is back. The marks are in RAM.
# "start" puts the watch into the crontab (agent-watch.cron on the flash).
#
# Started through bash (event/started, event/stopping, the .plg, cron), never run directly.

PLUGIN=unraid-secretary-office
DIR=/usr/local/emhttp/plugins/$PLUGIN
FLASH=/boot/config/plugins/$PLUGIN
RUN=/var/run/$PLUGIN
SUPERVISOR=$RUN/supervisor.pid
STOPPING=$RUN/stopping
LOG=/var/log/$PLUGIN.log          # only what the agent can't put into its own log (RAM)
NOTIFY=/usr/local/emhttp/webGui/scripts/notify
WATCH_CRON=$FLASH/agent-watch.cron
WATCH_LINE="*/5 * * * * bash $DIR/scripts/job.sh watch > /dev/null 2>&1"
WATCH_DOWN=$RUN/watch-down        # since when the agent hasn't checked in (array started)
WATCH_TOLD=$RUN/watch-told        # this outage was reported
WATCH_AFTER=600                   # seconds without a sign of life before Unraid hears of it

supervisor_pid() {
    local pid
    pid="$(cat "$SUPERVISOR" 2>/dev/null)"
    [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null && grep -qa 'agent.sh' "/proc/$pid/cmdline" 2>/dev/null && echo "$pid"
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

start() {
    watch_cron
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

# tell <subject> <short text> <normal|warning|alert> [long text] - like the engine's ub_notify; a click opens the office
tell() {
    local place link=/SecretaryOffice
    [[ -x "$NOTIFY" ]] || return 0
    place=$(sed -n 's/^MENU_PLACE="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$FLASH/$PLUGIN.cfg" 2>/dev/null | tail -n 1)
    [[ "$place" == settings ]] && link=/Settings/SecretaryOffice
    local args=( -e "Unraid Secretary Office" -s "Unraid Secretary Office: $1" -d "$2" -i "$3" -l "$link" )
    [[ -n "${4:-}" ]] && args+=( -m "$4" )
    timeout 60 "$NOTIFY" "${args[@]}" >/dev/null 2>&1 || true
}

watch() {
    local now data pulse since minutes
    now=$(date +%s)
    # only while the array is started (without it the agent waits, on purpose); that time doesn't count
    if ! grep -q '^fsState="Started"' /var/local/emhttp/var.ini 2>/dev/null; then
        rm -f "$WATCH_DOWN"
        return 0
    fi
    data=$(data_dir)
    if [[ ! -d "$data" ]]; then           # no data folder (yet): the page says why
        rm -f "$WATCH_DOWN"
        return 0
    fi
    pulse=$(stat -c %Y "$data/agent.json" 2>/dev/null || echo 0)
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

case "$1" in
    start)     start ;;
    stop)      stop ;;
    restart)   stop; start ;;
    status)    if [[ -n "$(supervisor_pid)" ]]; then echo "The agent is running."; else echo "The agent is not running."; exit 3; fi ;;
    supervise) supervise ;;
    watch)     watch ;;
    *)         echo "Usage: bash $0 start|stop|restart|status|watch"; exit 2 ;;
esac
