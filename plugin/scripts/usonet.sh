#!/bin/bash
# Unraid Secretary Office - the USO network on this server (agent/lib/usonet.php has the office's side: joining,
# fetching the helper, the page, the checks). Underneath the partner pairing only: two or more offices see each other
# on a tailnet of their own and pair over its 100.x addresses as over any other network.
#
#   usonet.sh check    what should run, runs: the namespace, the helper, the office's sshd in it - nothing when this
#                      server hasn't joined (then whatever is left of it goes). Every minute (usonet.cron), at the
#                      plugin's start (agent.sh start) and after a join. Never touches /mnt (cwd /), so the array's
#                      stop never waits for it - the door answers its ping with the array stopped.
#   usonet.sh stop     the helper, the sshd and the namespace gone (the node stays joined; check brings it back)
#   usonet.sh leave    stop, the node leaves its tailnet (helper logout), its state, the helper and the cron line
#                      gone from the flash - nothing of the USO network is left (the office's «Leave», the .plg remove)
#   usonet.sh status   one line
#
# The pieces: the namespace uso-net holds nothing but the helper's device uso0 (the node's addresses, routes to the
# tailnet only) and the office's own sshd on those addresses; the host's devices, addresses, routes, DNS and firewall
# are never touched. The sshd is Unraid's /usr/sbin/sshd with a config of the office's (RAM): Unraid's host key (what
# partners pinned), no key file, only the pairing's lines (scripts/usonet-keys.sh: uso-partner:/uso-ticket: lines of
# Unraid's authorized_keys, as written), forced commands only, started as «uso-sshd» (a link to Unraid's sshd): Unraid's
# rc.sshd stops sshd by name and counts it by its path - never this one; and this one never touches Unraid's.
#
# The helper (helper/ in the repository, Go) is pinned here and in agent/lib/usonet.php (USONET_HELPER) by its
# SHA-256: checked on the flash and again in RAM before every start; a different file is never run.
#
# Started through bash (cron, agent.sh, the agent, the .plg), never run directly.

PLUGIN=unraid-secretary-office
DIR=/usr/local/emhttp/plugins/$PLUGIN
FLASH=${USONET_FLASH:-/boot/config/plugins/$PLUGIN/usonet}
RUN=${USONET_RUN:-/var/run/$PLUGIN/usonet}
BIN_DIR=${USONET_BIN_DIR:-/usr/local/lib/$PLUGIN}
CRON_FILE=${USONET_CRON:-/boot/config/plugins/$PLUGIN/usonet.cron}
CRON_LINE="* * * * * bash $DIR/scripts/usonet.sh check > /dev/null 2>&1"
NETNS=${USONET_NETNS:-uso-net}
IDENT=${USONET_IDENT:-/boot/config/ident.cfg}
SSHD=${USONET_SSHD:-/usr/sbin/sshd}
HOST_KEY=/etc/ssh/ssh_host_ed25519_key
HELPER_VERSION=1
HELPER_FILE=uso-net-$HELPER_VERSION-linux-amd64
HELPER_SHA256=61bec7969716174cad94f2ea736f242db4d98a63b51c29e45e839a65e9d95b83
UDP_PORT=41642
# a coordination server of the user's own (Headscale): scheme, host[:port], an optional plain path - the same rule as USONET_CONTROL_RE
CONTROL_RE='^https?://[][A-Za-z0-9.:_-]+(/[A-Za-z0-9._/-]*)?$'
LOG=$RUN/usonet.log

log() { mkdir -p "$RUN" && echo "$(date '+%F %T') $*" >>"$LOG"; }

# KEY=value from the office's config (written by the agent at the join; values without quotes or spaces)
conf() { sed -n "s/^$1=\"\{0,1\}\([^\"]*\)\"\{0,1\}\$/\1/p" "$FLASH/usonet.cfg" 2>/dev/null | tail -n 1; }

joined() { [[ "$(conf JOINED)" == yes && -f "$FLASH/usonet.cfg" && ! -L "$FLASH/usonet.cfg" ]]; }

pid_of() {      # pid_of <file> <word in its cmdline> - the pid when that process lives and is that program
    local pid
    pid=$(head -c 16 "$1" 2>/dev/null | tr -dc '0-9')
    [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null && tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null | grep -qF -- "$2" && echo "$pid"
}

sha() { sha256sum "$1" 2>/dev/null | cut -d' ' -f1; }

netns_up() {
    [[ -e "/run/netns/$NETNS" ]] || ip netns add "$NETNS" || return 1
    ip -n "$NETNS" link set lo up 2>/dev/null
    return 0
}

# The helper from the flash into RAM (the flash can't run programs), its SHA-256 checked on both
helper_ready() {
    local src="$FLASH/$HELPER_FILE" dst="$BIN_DIR/uso-net"
    if [[ ! -f "$src" || -L "$src" ]]; then
        echo missing >"$RUN/helper.refused"; return 1
    fi
    if [[ "$(sha "$dst")" != "$HELPER_SHA256" ]]; then
        if [[ "$(sha "$src")" != "$HELPER_SHA256" ]]; then
            echo checksum >"$RUN/helper.refused"; log "the helper on the flash isn't the pinned one - not started"; return 1
        fi
        mkdir -p "$BIN_DIR" && chmod 700 "$BIN_DIR" && rm -f "$dst.new" && cp "$src" "$dst.new" && chmod 700 "$dst.new" && mv -f "$dst.new" "$dst" || return 1
        [[ "$(sha "$dst")" == "$HELPER_SHA256" ]] || { rm -f "$dst"; echo checksum >"$RUN/helper.refused"; return 1; }
    fi
    rm -f "$RUN/helper.refused"
    return 0
}

helper_up() {
    local pid key=() control
    pid=$(pid_of "$RUN/helper.pid" "uso-net up")
    [[ -n "$pid" ]] && return 0
    helper_ready || return 1
    [[ -f "$FLASH/authkey" && ! -L "$FLASH/authkey" ]] && key=(-authkey-file "$FLASH/authkey")
    control=$(conf CONTROL)
    [[ "$control" =~ $CONTROL_RE ]] || control=""
    mkdir -p "$FLASH/state" && tail -c 32768 "$RUN/helper.log" >"$RUN/helper.log.tmp" 2>/dev/null && mv -f "$RUN/helper.log.tmp" "$RUN/helper.log"
    cd / || return 1
    TS_NO_LOGS_NO_SUPPORT=true setsid "$BIN_DIR/uso-net" up -state "$FLASH/state" -netns "$NETNS" -status "$RUN/status.json" \
        -hostname "$(conf NAME)" -port "$UDP_PORT" ${control:+-control "$control"} "${key[@]}" </dev/null >>"$RUN/helper.log" 2>&1 8>&- &
    echo $! >"$RUN/helper.pid"
    log "helper started (PID $!)"
}

# The office's sshd: its config from the node's addresses (status.json, the helper's) - none yet: not started
sshd_conf() {
    local ips port a
    ips=$(jq -r 'select(.v == 1 and .netns == true) | .ips[]' "$RUN/status.json" 2>/dev/null | grep -E '^(100\.|fd7a:115c:a1e0:)')
    [[ -n "$ips" ]] || return 1
    port=$(sed -n 's/^PORTSSH="\{0,1\}\([0-9]*\)"\{0,1\}.*/\1/p' "$IDENT" 2>/dev/null | tail -n 1)
    [[ "$port" =~ ^[0-9]+$ ]] && (( port >= 1 && port <= 65535 )) || port=22
    for a in $ips; do
        if [[ "$a" == *:* ]]; then echo "ListenAddress [$a]:$port"; else echo "ListenAddress $a:$port"; fi
    done
    cat <<EOF
HostKey $HOST_KEY
AuthorizedKeysFile none
AuthorizedKeysCommand /bin/bash $DIR/scripts/usonet-keys.sh %u
AuthorizedKeysCommandUser root
PermitRootLogin forced-commands-only
AllowUsers root
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
HostbasedAuthentication no
DisableForwarding yes
PermitTTY no
PermitUserRC no
PermitUserEnvironment no
UseDNS no
LoginGraceTime 30
MaxStartups 10:30:60
PidFile $RUN/sshd.pid
SyslogFacility AUTH
EOF
}

sshd_up() {
    local want pid
    want=$(sshd_conf) || return 0
    pid=$(pid_of "$RUN/sshd.pid" "$RUN/sshd_config")
    if [[ -n "$pid" && "$want" == "$(cat "$RUN/sshd_config" 2>/dev/null)" ]]; then
        return 0
    fi
    [[ -n "$pid" ]] && kill "$pid" 2>/dev/null && sleep 0.5
    printf '%s\n' "$want" >"$RUN/sshd_config.new" && chmod 600 "$RUN/sshd_config.new" && mv -f "$RUN/sshd_config.new" "$RUN/sshd_config"
    if ! "$SSHD" -t -f "$RUN/sshd_config" >>"$LOG" 2>&1; then
        log "the office's sshd config was refused by sshd -t"; return 1
    fi
    # through a link of its own name: Unraid's rc.sshd stops «sshd» by name (killall --ns $$ sshd, its PID namespace
    # only) and counts /usr/sbin/sshd by command line - neither is this one (Unraid's updates of sshd still apply)
    mkdir -p "$BIN_DIR" && chmod 700 "$BIN_DIR" && ln -sfn "$SSHD" "$BIN_DIR/uso-sshd" || return 1
    (cd / && nsenter --net="/run/netns/$NETNS" "$BIN_DIR/uso-sshd" -f "$RUN/sshd_config") >>"$LOG" 2>&1 8>&- && log "the office's sshd started in $NETNS"
}

cron_on() {
    local want="# Unraid Secretary Office - the USO network (written by scripts/usonet.sh)"$'\n'"$CRON_LINE"
    if [[ "$(cat "$CRON_FILE" 2>/dev/null)" != "$want" ]]; then
        printf '%s\n' "$want" >"$CRON_FILE.tmp" && mv -f "$CRON_FILE.tmp" "$CRON_FILE"
    fi
    if ! grep -qF "$CRON_LINE" /etc/cron.d/root 2>/dev/null; then
        bash /usr/local/sbin/update_cron >/dev/null 2>&1
    fi
}

check() {
    cd / || exit 1
    if ! joined; then
        [[ -e "/run/netns/$NETNS" || -f "$RUN/helper.pid" ]] && stop
        return 0
    fi
    mkdir -p "$RUN" && chmod 700 "$RUN"
    exec 8>"$RUN/check.lock"
    flock -n 8 || return 0                  # one at a time (cron and the agent at once); what it starts never inherits fd 8
    cron_on
    netns_up || { log "the namespace $NETNS could not be made"; return 1; }
    helper_up
    sshd_up
}

stop() {
    local pid i
    pid=$(pid_of "$RUN/sshd.pid" "$RUN/sshd_config"); [[ -n "$pid" ]] && kill "$pid" 2>/dev/null
    pid=$(pid_of "$RUN/helper.pid" "uso-net up")
    if [[ -n "$pid" ]]; then
        kill -TERM "$pid" 2>/dev/null
        for ((i = 0; i < 20; i++)); do kill -0 "$pid" 2>/dev/null || break; sleep 0.25; done
        kill -KILL "$pid" 2>/dev/null
    fi
    # whatever else still runs in the namespace (a door's session): it goes with it
    if [[ -e "/run/netns/$NETNS" ]]; then
        ip netns pids "$NETNS" 2>/dev/null | xargs -r kill -TERM 2>/dev/null
        ip netns del "$NETNS" 2>/dev/null
    fi
    rm -f "$RUN/helper.pid" "$RUN/sshd.pid" "$RUN/status.json" "$RUN/sshd_config"
    log "stopped"
}

leave() {
    local control
    stop
    if [[ -d "$FLASH/state" ]] && helper_ready; then
        control=$(conf CONTROL)
        [[ "$control" =~ $CONTROL_RE ]] || control=""
        (cd / && TS_NO_LOGS_NO_SUPPORT=true timeout 40 "$BIN_DIR/uso-net" logout -state "$FLASH/state" ${control:+-control "$control"}) >>"$LOG" 2>&1 \
            && log "the node left its tailnet" || log "the node could not log out (offline?) - it stays listed in the tailnet until removed there"
    fi
    rm -rf "$FLASH" "$BIN_DIR"
    if [[ -f "$CRON_FILE" ]]; then
        rm -f "$CRON_FILE"
        bash /usr/local/sbin/update_cron >/dev/null 2>&1
    fi
    rm -rf "$RUN"
}

# sourced (the tests): the functions only
[[ "${BASH_SOURCE[0]}" == "$0" ]] || return 0

case "$1" in
    check)  check ;;
    stop)   stop ;;
    leave)  leave ;;
    status) if [[ -n "$(pid_of "$RUN/helper.pid" "uso-net up")" ]]; then echo "The USO network's helper runs."
            elif joined; then echo "Joined, but the helper isn't running."; exit 3
            else echo "Not joined."; exit 3; fi ;;
    *)      echo "Usage: bash $0 check|stop|leave|status"; exit 2 ;;
esac
