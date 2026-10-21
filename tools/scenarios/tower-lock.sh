# Sourced by prerelease.sh and nightly.sh (on the Mac): who holds Tower for a whole run of scenarios.
#
#   tower_take <what> <alias> <wait s>   the Mac's lock (a folder, its owner's pid inside) and then Tower free of any
#                                        other run (no run-local.sh there, three looks 5 s apart) - waits at most <wait s>;
#                                        1 with $TOWER_WHY saying who holds it, 4 when Tower is not reachable
#   tower_drop                           the lock let go (only by its owner - the caller's EXIT trap calls it)
#
# Tower's own lock (flock /tmp/uso-scenarios.lock) is held scenario by scenario only, so two runs would interleave and one's
# base reset would land in the other's run; workers' run.sh calls take no Mac lock - the look for run-local.sh catches them.
TOWER_LOCK=${USO_TOWER_LOCK:-$HOME/Claude/UnraidSecretaryOffice-briefs/scenarios/.tower.lock}
TOWER_WHY=''

_tl_owner() { cat "$TOWER_LOCK/owner" 2>/dev/null; }
_tl_pid() { _tl_owner | sed -n 's/^pid=//p' | head -1; }

_tl_mac() {         # the Mac's lock: taken (0) or held by a live process (1)
    local pid
    mkdir -p "$(dirname "$TOWER_LOCK")" 2>/dev/null
    if mkdir "$TOWER_LOCK" 2>/dev/null; then
        printf 'pid=%s\nwhat=%s\nsince=%s\n' "$$" "$1" "$(date '+%Y-%m-%d %H:%M')" > "$TOWER_LOCK/owner"
        return 0
    fi
    pid=$(_tl_pid)
    [[ $pid == "$$" ]] && return 0
    # a lock whose owner is gone (killed, the Mac rebooted): taken over
    if [[ -z $pid ]] || ! kill -0 "$pid" 2>/dev/null; then
        rm -rf "$TOWER_LOCK"
        _tl_mac "$1"
        return
    fi
    TOWER_WHY="the Mac's Tower lock: $(_tl_owner | sed -n 's/^what=//p') (pid $pid, since $(_tl_owner | sed -n 's/^since=//p'))"
    return 1
}

_tl_tower() {       # another run on Tower: 0 free, 1 busy ($TOWER_WHY), 4 unreachable
    local i got
    for i in 1 2 3; do
        got=$(ssh -o BatchMode=yes -o ConnectTimeout=10 "$1" 'ps -eo pid=,etime=,args= | grep "[r]un-local\.sh" | head -3') \
            || { TOWER_WHY="$1 not reachable"; return 4; }
        if [[ -n $got ]]; then
            TOWER_WHY="another run on $1: $(echo "$got" | head -1 | tr -s ' ' | cut -c1-160)"
            return 1
        fi
        ((i < 3)) && sleep 5
    done
    return 0
}

tower_take() {
    local what=$1 alias=$2 deadline=$(( $(date +%s) + ${3:-0} )) said='' rc
    while :; do
        if _tl_mac "$what"; then
            _tl_tower "$alias"
            rc=$?
            ((rc == 0)) && return 0
            tower_drop
            ((rc == 4)) && return 4
        fi
        (( $(date +%s) >= deadline )) && return 1
        [[ $said == "$TOWER_WHY" ]] || { echo "tower: waiting - $TOWER_WHY"; said=$TOWER_WHY; }
        sleep 60
    done
}

tower_drop() { [[ $(_tl_pid) == "$$" ]] && rm -rf "$TOWER_LOCK"; return 0; }
