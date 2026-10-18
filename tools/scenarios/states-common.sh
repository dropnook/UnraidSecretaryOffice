#!/bin/bash
# tools/scenarios/states-common.sh — what every states-*.sh file shares (H2, resilience wave 1), plus the states' self-test.
# Sourced by lib.sh (only definitions here). Run as a script it is the self-test of one state on this VM:
#
#   flock /tmp/uso-scenarios.lock bash tools/scenarios/states-common.sh --self-test CT-STOPPED-NC [args…]
#
# = fingerprint of the server, state <ID>, undo, fingerprint again: exit 0 when the state was made AND the server is
# exactly as before (STATES_FP sections, container ids reported but not compared), 1 when the state failed, 2 when it
# needs what this VM lacks, 3 when the undo left something behind (the diff is printed).
#
# Conventions for every state (lib.sh interface): `state ID args` pushes `undo_<id> args` even when the state fails, so an
# undo is idempotent (it undoes only what its stash says was done). A state returns 0 only when the state is really there
# (a stopped container is «exited», a share is in shares.ini …), 1 when it couldn't make it, 2 when this server lacks
# what it needs (the scenario should have said `needs …`). What a state moves away goes to $KEEP_STASH/<id>/.

ST_TEMPLATES=/boot/config/plugins/dockerMan/templates-user
ST_REBUILD=/usr/local/emhttp/plugins/dynamix.docker.manager/scripts/rebuild_container
ST_EMCMD=/usr/local/sbin/emcmd
ST_SHARES_INI=/var/local/emhttp/shares.ini
ST_DISKS_INI=/var/local/emhttp/disks.ini
ST_VAR_INI=/var/local/emhttp/var.ini
ST_SCENARIOS_ENV=/boot/config/plugins/unraid-secretary-office/scenarios.env
ST_DIR=${SC_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}

# _st_dir <id> — the stash folder of a state (made), printed
_st_dir() { local d="${KEEP_STASH:-/tmp/uso-keep/states/stash}/$1"; mkdir -p "$d" && printf '%s' "$d"; }
# _st_until <seconds> <cmd…> — poll every half second until the command succeeds; 1 on timeout
_st_until() {
    local end=$((SECONDS + $1)); shift
    until "$@" >/dev/null 2>&1; do ((SECONDS >= end)) && return 1; sleep 0.5; done
}
# _st_is <want> <cmd…> — the command prints exactly that (for _st_until: re-run each time, unlike a "$(…)" argument)
_st_is() { local want=$1; shift; [[ $("$@" 2>/dev/null) == "$want" ]]; }
# _st_lt <n> <cmd…> — the command prints a number below n
_st_lt() { local n=$1 v; shift; v=$("$@" 2>/dev/null); [[ $v =~ ^[0-9]+$ ]] && ((v < n)); }
# _st_need <what> <test cmd…> — 0 when the test holds, else says so and returns 2 (a state returns that on)
_st_need() { local what=$1; shift; "$@" >/dev/null 2>&1 && return 0; say "needs $what: not on this server"; return 2; }
# _st_ini <file> <section> <key> — a value of Unraid's ["section"] ini files (shares.ini, disks.ini); var.ini: section ''
_st_ini() {
    awk -v s="$2" -v k="$3" '
        /^\[/ { cur = $0; gsub(/^\["?|"?\]$/, "", cur); next }
        (s == "" || cur == s) && index($0, k "=") == 1 { v = substr($0, length(k) + 2); gsub(/^"|"$/, "", v); print v; exit }' "$1"
}
_st_var() { _st_ini "$ST_VAR_INI" '' "$1"; }
# _st_uri <text> — percent-encoded for an emcmd query
_st_uri() { jq -rn --arg v "$1" '$v|@uri'; }
# _st_emcmd <query> — emhttp's own command path (what Unraid's pages send); says it, 1 on refusal
_st_emcmd() {
    local out
    say "emcmd ${1%%&csrf*}"
    out=$("$ST_EMCMD" "$1" 2>&1) && return 0
    say "emcmd refused: ${out:0:200}"
    return 1
}
# _st_env <NAME> — a value from scenarios.env (0600, never printed); empty when missing
_st_env() { [[ -r $ST_SCENARIOS_ENV ]] && (set -a; . "$ST_SCENARIOS_ENV" >/dev/null 2>&1; printf '%s' "${!1:-}"); }
# _st_pools — the pools and array disks mounted under /mnt (cache, disk1 …), one per line
_st_pools() {
    local m
    for m in /mnt/*/; do
        m=${m%/}; m=${m##*/}
        case $m in user|user0|addons|disks|remotes|rootshare) continue ;; esac
        mountpoint -q "/mnt/$m" && printf '%s\n' "$m"
    done
}

# _st_bg <cmd…> — a long-lived process (a holder, a watcher, the agent): setsid, cwd /, stdio /dev/null and NO descriptor
# above 2 inherited — `flock <lock> <cmd>` hands its lock to every child, so a holder started inside an experiment would
# keep /tmp/uso-scenarios.lock for everybody (it happened on Tower, 2026-10-18). Prints the pid.
_ST_CLOSE='for f in /proc/$$/fd/*; do f=${f##*/}; ((f > 2)) && eval "exec $f>&-"; done 2>/dev/null'
_st_bg() { (cd / && exec setsid bash -c "$_ST_CLOSE"'; exec "$@"' _ "$@" </dev/null >/dev/null 2>&1) & echo $!; }
# _st_clean <cmd…> — the same in the foreground (agent.sh start/stop, rc scripts that leave daemons behind)
_st_clean() { (cd / && exec bash -c "$_ST_CLOSE"'; exec "$@"' _ "$@" </dev/null); }

# ------------------------------------------------------------------------------------------------------- the clock
# clock_back <seconds> <cmd…> — runs the command with the clock set back that far (ntpd stopped, put back from
# /proc/uptime in an EXIT/INT trap of a subshell — Unraid has no bc, so it's bash arithmetic), ntpd started again.
# Keep the command short: the whole server lives in the past meanwhile (day-1 finding 2).
clock_back() {
    local back=$1; shift
    (
        _st_up() { local u; read -r u _ < /proc/uptime; echo $((10#${u/./})); }      # centiseconds
        ntp=no; pgrep -x ntpd >/dev/null && ntp=yes
        [[ $ntp == yes ]] && /etc/rc.d/rc.ntpd stop >/dev/null 2>&1
        u0=$(_st_up); t0=$(date +%s%N)
        _st_put() {
            local now=$((t0 + ($(_st_up) - u0) * 10000000))
            date -s "@${now:0:-9}.${now: -9}" >/dev/null
            [[ $ntp == yes ]] && /etc/rc.d/rc.ntpd start >/dev/null 2>&1
            trap - EXIT INT TERM
        }
        trap _st_put EXIT INT TERM
        date -s "@$((t0 / 1000000000 - back))" >/dev/null || exit 1
        "$@"
    )
}

# ------------------------------------------------------------------------------------------------- the fingerprint
# _st_fp — what the states may change, in sections, cheap and stable (no sizes that move by themselves, no times).
# Never reads under /mnt/user (every disk of a share): pools and disks directly, test shares (uso-*) in full.
_st_fp() {
    local p f
    echo "## docker"
    if timeout 10 docker info >/dev/null 2>&1; then
        timeout 20 docker ps -a --format '{{.Names}}|{{.Image}}|{{.State}}' | sort
        echo "## docker-config"      # binds, network, ports, compose project of each container
        timeout 20 docker ps -aq | xargs -r timeout 20 docker inspect | jq -r '.[] | [.Name, .HostConfig.NetworkMode,
            ([.Mounts[] | "\(.Source):\(.Destination):\(.RW)"] | sort | join(",")), (.HostConfig.PortBindings // {} | tostring),
            (.Config.Labels["com.docker.compose.project"] // "")] | join(" ")' | sort
        echo "## docker-volumes"; timeout 20 docker volume ls -q | sort
        echo "## docker-networks"; timeout 20 docker network ls --format '{{.Name}}' | sort
        echo "## docker-images"; timeout 20 docker images --format '{{.Repository}}:{{.Tag}} {{.ID}}' | grep -v '^<none>' | sort
    else
        echo "docker not answering"
    fi
    echo "## templates"; (cd "$ST_TEMPLATES" 2>/dev/null && md5sum -- * 2>/dev/null | sort -k2)
    echo "## cfgs"
    md5sum /boot/config/shares/*.cfg /boot/config/docker.cfg /boot/config/domain.cfg /boot/config/share.cfg \
        /boot/config/plugins/dynamix/dynamix.cfg /boot/config/parity-checks.log /var/lib/docker/unraid-autostart 2>/dev/null
    ls -d /boot/config/plugins/dynamix/notifications/agents/* /boot/config/plugins/compose.manager/projects/* 2>/dev/null
    echo "## shares.ini"
    awk '/^\[/ { n = $0 } /^(useCache|cachePool|cachePool2|exclusive|hasCfg)=/ { print n, $0 }' "$ST_SHARES_INI"
    echo "## roots"
    for p in $(_st_pools); do find "/mnt/$p" -mindepth 1 -maxdepth 1 -printf "$p/%P %y\n"; done | sort
    for p in $(_st_pools); do [[ -d /mnt/$p/appdata ]] && find "/mnt/$p/appdata" -mindepth 1 -maxdepth 1 -printf "$p/appdata/%P\n"; done | sort
    echo "## test shares"
    for f in /mnt/*/uso-*; do
        [[ -d $f && $f != /mnt/user/* && $f != /mnt/user0/* ]] && find "$f" -printf '%p %s %y %m\n'
    done | sort
    echo "## zfs"
    zfs list -H -o name,refreservation,mountpoint 2>/dev/null | sort
    zpool status -x 2>/dev/null
    echo "## services"
    echo "mdState=$(_st_var mdState)"
    for p in dockerd libvirtd atd ntpd crond; do pgrep -x "$p" >/dev/null && echo "$p up" || echo "$p down"; done
    for p in $(pgrep -x dockerd) $(pgrep -x containerd); do echo "dockerd-state $(awk '{print $3}' "/proc/$p/stat")"; done
    stat -c 'notify %a' /usr/local/emhttp/webGui/scripts/notify 2>/dev/null
    echo "mover.pid $(cat /var/run/mover.pid 2>/dev/null)"
    echo "resync $(/usr/local/sbin/mdcmd status 2>/dev/null | awk -F= '$1 == "mdResyncPos" { print ($2 > 0) ? "running" : "idle" }')"
    echo "varlog-avail-10M $(( $(df --output=avail /var/log | tail -1) / 10240 ))"
    echo "## processes"
    pgrep -af 'uso-fake|uso-open|uso-midrun|uso-midreq|uso-wip-h2' | grep -v -e pgrep -e states-common | sed 's/^[0-9]* //' | sort
    echo "## ids (reported, not compared)"
    timeout 20 docker ps -aq --no-trunc 2>/dev/null | sort
}

# _st_fp_check — after the undo: the fingerprint again, compared with $KEEP/states-fp.before; 1 + the diff when not equal
_st_fp_check() {
    local before=$KEEP/states-fp.before after=$KEEP/states-fp.after
    _st_fp > "$after"
    # lib.sh's scenario_end calls a stash that still holds a file «not undone» — the same here
    local left; left=$(find "$KEEP_STASH" -mindepth 1 -not -type d 2>/dev/null | sed "s|^$KEEP_STASH/||")
    [[ -n $left ]] && { say "INV-LEFTOVER (states): RED — the stash still holds: $(tr '\n' ' ' <<< "$left")"; return 1; }
    if diff <(sed '/^## ids/,$d' "$before") <(sed '/^## ids/,$d' "$after") > "$KEEP/states-fp.diff"; then
        say "INV-LEFTOVER (states): green — the server is as before the state"
        diff -q <(sed -n '/^## ids/,$p' "$before") <(sed -n '/^## ids/,$p' "$after") >/dev/null \
            || say "note: container ids changed (a container was re-created)"
        return 0
    fi
    say "INV-LEFTOVER (states): RED — the undo left this:"
    while IFS= read -r l; do say "  $l"; done < "$KEEP/states-fp.diff"
    return 1
}

# --------------------------------------------------------------------------------------------------- the self-test
_st_self_test() {
    local id=$1 rc; shift
    if [[ -f $ST_DIR/lib-stub.sh ]]; then . "$ST_DIR/lib-stub.sh"; else . "$ST_DIR/lib.sh"; fi
    KEEP=${KEEP:-/tmp/uso-keep/states-${id,,}}
    if declare -F scenario_begin >/dev/null && [[ ! -f $ST_DIR/lib-stub.sh ]]; then scenario_begin "states-${id,,}"; fi
    # a fresh stash: an earlier self-test whose undo failed must not hand its notes to this one
    KEEP_STASH=$KEEP/stash; rm -rf "$KEEP_STASH"; mkdir -p "$KEEP_STASH"
    _st_fp > "$KEEP/states-fp.before"
    undo_push _st_fp_check                                    # pushed first, so it runs after every other undo
    state "$id" "$@"; rc=$?
    case $rc in
        0) say "state $id: made" ;;
        2) say "state $id: needs what this server lacks" ;;
        *) say "state $id: FAILED ($rc)" ;;
    esac
    [[ -n ${ST_SHOW:-} ]] && { say "ST_SHOW:"; eval "$ST_SHOW" 2>&1 | while IFS= read -r l; do say "  $l"; done; }
    [[ -n ${ST_HOLD:-} ]] && { say "holding the state for $ST_HOLD s (ST_HOLD)"; sleep "$ST_HOLD"; }
    if declare -F undo_all >/dev/null; then
        undo_all; local u=$?
        ((u != 0)) && exit 3
        ((rc == 0)) && exit 0
        ((rc == 2)) && exit 2
        exit 1
    fi
    scenario_end
}

if [[ ${BASH_SOURCE[0]} == "$0" && -z ${_ST_SELF:-} ]]; then
    _ST_SELF=1
    set -u
    [[ ${1:-} == --self-test && -n ${2:-} ]] || { echo "usage: $0 --self-test <STATE-ID> [args…]" >&2; exit 64; }
    shift
    _st_self_test "$@"
fi
