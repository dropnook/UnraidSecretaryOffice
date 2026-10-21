#!/bin/bash
# tools/scenarios/states-services.sh — Unraid's services and the array (the E states, SV-*) (H2). Sourced by lib.sh.
# Every «off» notes what ran before (containers, VMs, the service itself) and its «on» brings back exactly that —
# Tower has no Docker autostart list, so nothing comes back by itself.
#
#   docker_off <id> [cfg]   rc.docker stop (cfg: DOCKER_ENABLED="no" in docker.cfg too)    docker_on <id>
#   docker_hang <id>        dockerd stopped by SIGSTOP (`docker version` hangs)             docker_unhang <id>
#   vm_service_off <id> [cfg]  rc.libvirt stop (cfg: SERVICE="disable" in domain.cfg)       vm_service_on <id>
#   array_stop <id>         emcmd cmdStop=Stop, waits for mdState=STOPPED                    array_start <id>
#                           (array_start: whatever the stop left — still unmounting: settled first, what holds /mnt named)
#   atd_off <id> / atd_on <id>      rc.atd stop / start
#   notify_off <id> [bin|agents]    bin: Unraid's notify script not executable (the engine's `no_notify`); agents: no
#                                   agent and no mail reaches anybody (the Team Lead's «notifications» check)
#   notify_on <id>
#   varlog_fill <id> / varlog_unfill <id>   /var/log (RAM, 128 MB) full but 64 KB
#
# States: SV-DOCKER-OFF SV-DOCKER-HUNG SV-VM-OFF SV-ARRAY-STOPPED SV-ARRAY-STOP-MIDRUN SV-ATD-OFF SV-NOTIFY-OFF SV-LOG-FULL

ST_NOTIFY=/usr/local/emhttp/webGui/scripts/notify
ST_DYNAMIX=/boot/config/plugins/dynamix/dynamix.cfg
ST_AGENTS=/boot/config/plugins/dynamix/notifications/agents

_st_docker_up() { timeout 10 docker info >/dev/null 2>&1; }
# _st_ct_note <id> — which containers run / are paused now (for the «on» to bring back exactly that)
_st_ct_note() {
    local dir; dir=$(_st_dir "$1")
    _st_docker_up || return 0
    timeout 20 docker ps --filter status=running --format '{{.Names}}' > "$dir/ct.running"
    timeout 20 docker ps --filter status=paused --format '{{.Names}}' > "$dir/ct.paused"
}
_st_ct_back() {
    local dir c rc=0; dir=$(_st_dir "$1")
    [[ -f $dir/ct.running ]] || return 0
    _st_until 120 _st_docker_up || { say "Docker doesn't answer"; return 1; }
    # databases first (an app started before its database exits again — immich_server did), then a second look a
    # moment later for one that stopped again
    local order; order=$(cat "$dir/ct.running" "$dir/ct.paused" | awk 'NF && /mariadb|postgres|redis|valkey|db/ { print; next } NF { r = r $0 "\n" } END { printf "%s", r }')
    while read -r c; do
        [[ -z $c ]] && continue
        [[ $(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null) == running ]] || docker start "$c" >/dev/null || rc=1
    done <<< "$order"
    sleep 15
    while read -r c; do
        [[ -z $c ]] && continue
        [[ $(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null) == running ]] || { say "$c stopped again — started once more"; docker start "$c" >/dev/null || rc=1; }
    done <<< "$order"
    while read -r c; do [[ -n $c ]] && { docker pause "$c" >/dev/null || rc=1; }; done < "$dir/ct.paused"
    ((rc == 0)) && rm -f "$dir/ct.running" "$dir/ct.paused"
    return $rc
}
_st_vm_note() { virsh list --name --state-running 2>/dev/null | sed '/^$/d' > "$(_st_dir "$1")/vm.running"; }
_st_vm_back() {
    local f v rc=0; f=$(_st_dir "$1")/vm.running
    [[ -f $f ]] || return 0
    while read -r v; do [[ -n $v ]] && { [[ $(virsh domstate "$v" 2>/dev/null) == running ]] || virsh start "$v" >/dev/null || rc=1; }; done < "$f"
    ((rc == 0)) && rm -f "$f"
    return $rc
}
_st_cfg_set() { # <file> <key> <value> — a KEY="value" line of an Unraid cfg (docker.cfg, domain.cfg)
    if grep -q "^$2=" "$1"; then sed -i "s|^$2=.*|$2=\"$3\"|" "$1"; else printf '%s="%s"\n' "$2" "$3" >> "$1"; fi
}

docker_off() {
    local id=$1 how=${2:-} dir; dir=$(_st_dir "$id")
    pgrep -x dockerd >/dev/null || { say "docker_off: Docker isn't running (already off)"; return 0; }
    _st_ct_note "$id"
    if [[ $how == cfg ]]; then
        cp -p /boot/config/docker.cfg "$dir/docker.cfg" && _st_cfg_set /boot/config/docker.cfg DOCKER_ENABLED no || return 1
    fi
    printf 'on\n' > "$dir/docker.was"
    _st_clean /etc/rc.d/rc.docker stop >/dev/null 2>&1
    _st_until 90 bash -c '! pgrep -x dockerd' || { say "docker_off: dockerd still runs"; return 1; }
    say "docker_off: Docker stopped${how:+ (and switched off in docker.cfg)}"
}
docker_on() {
    local id=$1 dir; dir=$(_st_dir "$id")
    [[ -f $dir/docker.cfg ]] && { cp -p "$dir/docker.cfg" /boot/config/docker.cfg && rm -f "$dir/docker.cfg" || return 1; }
    [[ -f $dir/docker.was ]] || return 0
    pgrep -x dockerd >/dev/null || _st_clean /etc/rc.d/rc.docker start >/dev/null 2>&1
    _st_ct_back "$id" && rm -f "$dir/docker.was"
}

docker_hang() {
    local id=$1 pid; pid=$(pgrep -x dockerd)
    [[ -n $pid ]] || { say "needs docker: not running"; return 2; }
    printf '%s\n' "$pid" > "$(_st_dir "$id")/dockerd.stopped"
    kill -STOP "$pid" || return 1
    ! timeout 5 docker version >/dev/null 2>&1 || { say "docker_hang: docker still answers"; return 1; }
    say "docker_hang: dockerd (pid $pid) stopped — Docker is on but silent"
}
docker_unhang() {
    local f pid; f=$(_st_dir "$1")/dockerd.stopped
    [[ -f $f ]] || return 0
    pid=$(cat "$f"); kill -CONT "$pid" 2>/dev/null
    _st_until 30 _st_docker_up || { say "docker_unhang: Docker doesn't answer after SIGCONT"; return 1; }
    rm -f "$f"
}

vm_service_off() {
    local id=$1 how=${2:-} dir; dir=$(_st_dir "$id")
    pgrep -x libvirtd >/dev/null || { say "vm_service_off: libvirt isn't running (already off)"; return 0; }
    _st_vm_note "$id"
    if [[ $how == cfg ]]; then
        cp -p /boot/config/domain.cfg "$dir/domain.cfg" && _st_cfg_set /boot/config/domain.cfg SERVICE disable || return 1
    fi
    printf 'on\n' > "$dir/libvirt.was"
    _st_clean /etc/rc.d/rc.libvirt stop >/dev/null 2>&1
    _st_until 90 bash -c '! pgrep -x libvirtd' || { say "vm_service_off: libvirtd still runs"; return 1; }
    say "vm_service_off: the VM service stopped${how:+ (and disabled in domain.cfg)}"
}
vm_service_on() {
    local id=$1 dir; dir=$(_st_dir "$id")
    [[ -f $dir/domain.cfg ]] && { cp -p "$dir/domain.cfg" /boot/config/domain.cfg && rm -f "$dir/domain.cfg" || return 1; }
    [[ -f $dir/libvirt.was ]] || return 0
    pgrep -x libvirtd >/dev/null || _st_clean /etc/rc.d/rc.libvirt start >/dev/null 2>&1
    _st_until 60 bash -c 'virsh version >/dev/null 2>&1' || { say "vm_service_on: libvirt doesn't answer"; return 1; }
    _st_vm_back "$id" && rm -f "$dir/libvirt.was"
}

array_stop() {
    local id=$1 dir; dir=$(_st_dir "$id")
    [[ $(_st_var mdState) == STARTED ]] || { say "array_stop: the array isn't started (mdState=$(_st_var mdState))"; return 0; }
    _st_ct_note "$id"; _st_vm_note "$id"
    printf 'started\n' > "$dir/array.was"
    cd / || return 1
    _st_emcmd 'cmdStop=Stop' || return 1
    _st_until 300 _st_is STOPPED _st_var mdState || { say "array_stop: not stopped after 5 min (mdState=$(_st_var mdState))"; return 1; }
    say "array_stop: the array is stopped"
}
# _st_mnt_holders — the processes that hold a mount under /mnt open (what keeps an array stop unmounting): pid and command
_st_mnt_holders() {
    local m p out=''
    for m in $(findmnt -rn -o TARGET | grep -E '^/mnt/[^/]+(/|$)' | grep -v '^/mnt/addons'); do
        for p in $(fuser -m "$m" 2>/dev/null); do
            p=${p//[^0-9]/}; [[ -n $p ]] || continue
            out+="$m: $p $(tr '\0' ' ' < "/proc/$p/cmdline" 2>/dev/null | cut -c1-100) (cwd $(readlink "/proc/$p/cwd" 2>/dev/null)); "
        done
    done
    printf '%s' "${out:-nothing}"
}
# settled: started (mdState STARTED, fsState Started) or stopped — a stop still unmounting is neither (mdState stays
# STARTED while emhttpd retries a busy pool: Tower 2026-10-20, 07:56 → 08:07, «/mnt/cache/appdata: pool or dataset is busy»)
_st_array_settled() { local m; m=$(_st_var mdState); [[ $m == STOPPED || ( $m == STARTED && $(_st_var fsState) == Started ) ]]; }
_st_array_settle() {
    local end=$((SECONDS + ${1:-900})) next=$((SECONDS + 60))
    until _st_array_settled; do
        ((SECONDS >= end)) && { say "array: still mdState=$(_st_var mdState) fsState=$(_st_var fsState) — holding /mnt: $(_st_mnt_holders)"; return 1; }
        ((SECONDS >= next)) && { say "array: a stop still unmounting (fsState=$(_st_var fsState)) — holding /mnt: $(_st_mnt_holders)"; next=$((SECONDS + 120)); }
        sleep 2
    done
}
# array_start <id>: whatever a stop left — a stop still unmounting settles first (15 min at most, what holds a mount
# named), then ⟦Start⟧ (twice at most), then what ran runs again
array_start() {
    local id=$1 dir i; dir=$(_st_dir "$id")
    [[ -f $dir/array.was ]] || return 0
    _st_array_settle 900 || return 1
    for ((i = 0; i < 2; i++)); do
        [[ $(_st_var mdState) == STARTED ]] && break
        _st_emcmd 'cmdStart=Start' || { sleep 10; continue; }
        _st_until 300 _st_is STARTED _st_var mdState || say "array_start: not started after 5 min (mdState=$(_st_var mdState))"
    done
    [[ $(_st_var mdState) == STARTED ]] || { say "array_start: the array doesn't start (mdState=$(_st_var mdState))"; return 1; }
    _st_until 120 _st_is Started _st_var fsState || { say "array_start: fsState=$(_st_var fsState)"; return 1; }
    _st_ct_back "$id" && _st_vm_back "$id" || return 1
    # the agent comes back by itself (event/started); its heartbeat fresh again before the next step
    _st_until 120 bash -c "[[ -f $RUN/agent.json ]] && (( \$(date +%s) - \$(stat -c %Y $RUN/agent.json) < 30 ))" \
        || say "note: no fresh agent heartbeat 2 min after the array's start"
    rm -f "$dir/array.was"
    say "array_start: the array is started, what ran runs again"
}

atd_off() {
    local dir; dir=$(_st_dir "$1")
    pgrep -x atd >/dev/null || { say "atd_off: atd isn't running (already off)"; return 0; }
    printf 'on\n' > "$dir/atd.was"
    _st_clean /etc/rc.d/rc.atd stop >/dev/null 2>&1
    _st_until 10 bash -c '! pgrep -x atd' || { say "atd_off: atd still runs"; return 1; }
}
atd_on() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/atd.was ]] || return 0
    pgrep -x atd >/dev/null || _st_clean /etc/rc.d/rc.atd start >/dev/null 2>&1
    _st_until 10 pgrep -x atd && rm -f "$dir/atd.was"
}

notify_off() {
    local id=$1 how=${2:-bin} dir; dir=$(_st_dir "$id")
    case $how in
        bin)
            [[ -x $ST_NOTIFY ]] || { say "notify_off: $ST_NOTIFY isn't executable (already off)"; return 0; }
            stat -c %a "$ST_NOTIFY" > "$dir/notify.mode" && chmod a-x "$ST_NOTIFY" ;;
        agents)
            if [[ -d $ST_AGENTS ]]; then mv "$ST_AGENTS" "$dir/agents" || return 1; fi
            if grep -q '^\[notify\]' "$ST_DYNAMIX" 2>/dev/null; then
                cp -p "$ST_DYNAMIX" "$dir/dynamix.cfg" || return 1
                # alert/warning/normal are bit masks (1 browser, 2 mail, 4 agents): the browser only
                awk '/^\[/ { n = ($0 == "[notify]") } n && /^(normal|warning|alert)=/ { sub(/=.*/, "=\"1\"") } { print }' \
                    "$dir/dynamix.cfg" > "$ST_DYNAMIX.uso-new" && mv "$ST_DYNAMIX.uso-new" "$ST_DYNAMIX"
            else
                say "notify_off: dynamix.cfg has no [notify] — nothing reached anybody already"
            fi ;;
        *) say "notify_off: bin|agents, not $how"; return 1 ;;
    esac
    say "notify_off: $how"
}
notify_on() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/notify.mode ]] && { chmod "$(cat "$dir/notify.mode")" "$ST_NOTIFY" && rm -f "$dir/notify.mode" || return 1; }
    [[ -d $dir/agents ]] && { mv "$dir/agents" "$ST_AGENTS" || return 1; }
    [[ -f $dir/dynamix.cfg ]] && { cp -p "$dir/dynamix.cfg" "$ST_DYNAMIX" && rm -f "$dir/dynamix.cfg" || return 1; }
    return 0
}

varlog_fill() {
    local avail; avail=$(df --output=avail /var/log | tail -1)
    ((avail > 64)) || { say "varlog_fill: /var/log is full already"; return 0; }
    [[ -e /var/log/uso-fill ]] && { say "varlog_fill: /var/log/uso-fill exists"; return 1; }
    printf 'x\n' > "$(_st_dir "$1")/varlog.made"
    fallocate -l $(( (avail - 64) * 1024 )) /var/log/uso-fill || return 1
    say "varlog_fill: /var/log full but 64 KB"
}
varlog_unfill() { local f; f=$(_st_dir "$1")/varlog.made; [[ -f $f ]] || return 0; rm -f /var/log/uso-fill && rm -f "$f"; }

# ------------------------------------------------------------------------------------------------------------ states
state_sv_docker_off() { docker_off SV-DOCKER-OFF "${1:-}"; }     # already off (test3): the state holds as it is
undo_sv_docker_off() { docker_on SV-DOCKER-OFF; }
state_sv_docker_hung() { docker_hang SV-DOCKER-HUNG; }
undo_sv_docker_hung() { docker_unhang SV-DOCKER-HUNG; }
state_sv_vm_off() { _st_need "the VM service" pgrep -x libvirtd || return; vm_service_off SV-VM-OFF "${1:-}"; }
undo_sv_vm_off() { vm_service_on SV-VM-OFF; }
state_sv_array_stopped() { array_stop SV-ARRAY-STOPPED; }
undo_sv_array_stopped() { array_start SV-ARRAY-STOPPED; }
# the same as RN-ARRAY-STOP-MIDRUN-<run> (states-runs.sh): SV-ARRAY-STOP-MIDRUN [bk|mv|rs|drill]
state_sv_array_stop_midrun() { local r=${1:-bk}; "state_rn_array_stop_midrun_${r,,}" "${@:2}"; }
undo_sv_array_stop_midrun() { local r=${1:-bk}; "undo_rn_array_stop_midrun_${r,,}" "${@:2}"; }
state_sv_atd_off() { atd_off SV-ATD-OFF; }
undo_sv_atd_off() { atd_on SV-ATD-OFF; }
state_sv_notify_off() { notify_off SV-NOTIFY-OFF "${1:-bin}"; }
undo_sv_notify_off() { notify_on SV-NOTIFY-OFF; }
state_sv_log_full() { varlog_fill SV-LOG-FULL; }
undo_sv_log_full() { varlog_unfill SV-LOG-FULL; }
