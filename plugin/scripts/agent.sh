#!/bin/bash
# Unraid Secretary Office - the agent as a service of the plugin.
#
#   agent.sh start     start it (if it isn't running yet)
#   agent.sh stop      stop it, quickly: the array may be waiting (emhttp runs
#                      event scripts and waits for them)
#   agent.sh restart
#   agent.sh status
#
# "start" leaves a small supervisor behind (its own session, so "stop" can end
# everything the agent started - a du measuring a share would keep the pool
# busy). The supervisor restarts the agent if it dies. Jobs that must outlive
# the agent (backup runs) go through atd and are not touched.
#
# Started through bash (event/started, event/stopping, the .plg), never run directly.

PLUGIN=unraid-secretary-office
DIR=/usr/local/emhttp/plugins/$PLUGIN
RUN=/var/run/$PLUGIN
SUPERVISOR=$RUN/supervisor.pid
STOPPING=$RUN/stopping
LOG=/var/log/$PLUGIN.log          # only what the agent can't put into its own log (RAM)

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
    if [[ -n "$(supervisor_pid)" ]]; then
        echo "The agent is already running."
        return 0
    fi
    # moving over from the Compose stack: never two agents (snapshot schedules twice, desks racing)
    if [[ -S /var/run/docker.sock ]] && [[ "$(timeout 10 docker inspect -f '{{.State.Running}}' UnraidSecretaryOffice-Agent 2>/dev/null)" == true ]]; then
        echo "The Compose stack's agent (UnraidSecretaryOffice-Agent) is running - not starting a second one. Stop the stack, then: bash $0 start"
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

case "$1" in
    start)     start ;;
    stop)      stop ;;
    restart)   stop; start ;;
    status)    if [[ -n "$(supervisor_pid)" ]]; then echo "The agent is running."; else echo "The agent is not running."; exit 3; fi ;;
    supervise) supervise ;;
    *)         echo "Usage: bash $0 start|stop|restart|status"; exit 2 ;;
esac
