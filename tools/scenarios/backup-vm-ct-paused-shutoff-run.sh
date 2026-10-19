#!/bin/bash
# Cells B6, B7 and B8 of matrix.md in one run (the states don't meet: two VMs, two containers):
#   B6  uso-vm-plain, paused by the user (Unraid's ⟦Pause⟧; prepare = pause in the base), is still paused after the run —
#       the run resumes only what it paused itself (backup.sh vm_plan: a VM not «running» is not_running, never held).
#   B7  a paused container is paused again afterwards, never left running: immich_server (the run stops it with the
#       apps — `docker stop` takes a paused container down, `docker start` brings it back running) and EmbyServer
#       (no_stop in the base: never stopped, so never touched).
#   B8  uso-vm-agent, shut off (prepare = freeze in the base), is never started and has no vm_* warning.
# The prepare = shutdown variants of B6 and B8: backup-vm-shutoff-prepare-shutdown-run. Pre-release (~6 min).
# RED on 1.66.8 (B7, product): a paused immich_server runs after the run — backup.sh:514 takes CT_RUNNING (docker's
# State.Running, true for a paused container) as «running», stop_tier stops it, restore_service (~806) starts it.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-ct-paused-shutoff-run
needs tower docker vm vmagent
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# how often libvirt started a VM (its qemu log's «starting up» lines; no log = never started)
starts() { local n; n=$(grep -c 'starting up' "/var/log/libvirt/qemu/$1.log" 2>/dev/null); echo "${n:-0}"; }
nc_data_closed
state VM-PAUSED-BY-USER plain
state VM-SHUTOFF agent shutdown
state CT-PAUSED immich_server
state CT-PAUSED EmbyServer
snap_before
agent0=$(starts uso-vm-agent) plain0=$(starts uso-vm-plain)
flow BK-RUN-MANUAL mode=backup
evidence "$UB/state/status.json" status-after-run.json
codes=$(_ck_warning_codes | paste -sd' ' -)
say "the run's warning codes: ${codes:-none}"

s=$(_st_vm_state uso-vm-plain)
[[ $s == paused && $(starts uso-vm-plain) == "$plain0" ]] && cell B6-paused-stays 1 "uso-vm-plain is still paused, not started anew" \
    || cell B6-paused-stays 0 "uso-vm-plain is «$s» after the run (was paused by the user; starts $plain0 → $(starts uso-vm-plain))"
for c in immich_server EmbyServer; do
    s=$(ct_status "$c")
    [[ $s == paused ]] && cell "B7-$c" 1 "$c is still paused" \
        || cell "B7-$c" 0 "$c is «$s» after the run (was paused by the user)"
done
s=$(_st_vm_state uso-vm-agent)
vmw=$(grep -o 'vm_[a-z_]*' <<<"$codes" | sort -u | paste -sd' ' -)
[[ $s == "shut off" && $(starts uso-vm-agent) == "$agent0" && -z $vmw ]] \
    && cell B8-off-stays 1 "uso-vm-agent stays shut off (never started), no vm_* warning" \
    || cell B8-off-stays 0 "uso-vm-agent is «$s» (starts $agent0 → $(starts uso-vm-agent)), VM warnings: ${vmw:-none}"
check standard
check final
scenario_end
