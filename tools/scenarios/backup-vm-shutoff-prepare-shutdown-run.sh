#!/bin/bash
# Cell B8 of matrix.md with prepare = shutdown (the base's freeze/pause: backup-vm-ct-paused-shutoff-run), and B6 the same
# way: both VMs set to «shut down for the snapshot» by the setup's Apply (the user's choice, before the state), then
#   B8  uso-vm-agent shut off: the run never starts it (backup.sh vm_release starts only what it shut down itself:
#       vm_plan leaves a VM that isn't running out of VM_TODO) and says no «paused instead» (vm_not_shut_down).
#   B6  uso-vm-plain paused by the user: neither shut down nor started — still paused afterwards.
# The Apply is undone by putting the settings.ini of before back (no helper undoes an Apply; the next plan of the base
# then proposes nothing). Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-shutoff-prepare-shutdown-run
needs tower vm vmagent
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
starts() { local n; n=$(grep -c 'starting up' "/var/log/libvirt/qemu/$1.log" 2>/dev/null); echo "${n:-0}"; }
nc_data_closed
mkdir -p "$KEEP_STASH/ini" && cp -p "$UB/settings.ini" "$KEEP_STASH/ini/settings.ini"
undo_push bash -c '[[ -f $1 ]] && cp -p "$1" "$2" && rm -f "$1"' _ "$KEEP_STASH/ini/settings.ini" "$UB/settings.ini"
flow BK-APPLY 'vm|uso-vm-plain|prepare=shutdown' 'vm|uso-vm-agent|prepare=shutdown'
for v in uso-vm-plain uso-vm-agent; do
    p=$(_ini_flat "$UB/settings.ini" | sed -n "s/^vm|$v|prepare=//p")
    [[ $p == shutdown ]] || _row state "prepare $v" red "after Apply settings.ini says prepare «$p», not shutdown"
done
state VM-SHUTOFF agent shutdown
state VM-PAUSED-BY-USER plain
snap_before
agent0=$(starts uso-vm-agent) plain0=$(starts uso-vm-plain)
flow BK-RUN-MANUAL mode=backup
evidence "$UB/state/status.json" status-after-run.json
codes=$(_ck_warning_codes | paste -sd' ' -)
say "the run's warning codes: ${codes:-none}"
vmw=$(grep -o 'vm_[a-z_]*' <<<"$codes" | sort -u | paste -sd' ' -)
s=$(_st_vm_state uso-vm-agent)
[[ $s == "shut off" && $(starts uso-vm-agent) == "$agent0" && -z $vmw ]] \
    && cell B8-shutdown-off-stays 1 "uso-vm-agent (prepare shutdown) stays shut off, never started, no vm_* warning" \
    || cell B8-shutdown-off-stays 0 "uso-vm-agent is «$s» (starts $agent0 → $(starts uso-vm-agent)), VM warnings: ${vmw:-none}"
s=$(_st_vm_state uso-vm-plain)
[[ $s == paused && $(starts uso-vm-plain) == "$plain0" ]] \
    && cell B6-shutdown-paused-stays 1 "uso-vm-plain (prepare shutdown, paused by the user) is still paused" \
    || cell B6-shutdown-paused-stays 0 "uso-vm-plain is «$s» after the run (starts $plain0 → $(starts uso-vm-plain))"
check standard
check final
scenario_end
