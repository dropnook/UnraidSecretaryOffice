#!/bin/bash
# Cell B36 of matrix.md, a VM with its guest agent: uso-vm-agent running, the agent answering (VM-RUNNING-AGENT), saved
# prepare=freeze. The run freezes its file systems through the agent and thaws them (for real: the run's log and
# libvirt), the VM runs afterwards, its package vms/uso-vm-agent and the copy in its folder (domains/…/.uso-backup) are
# written. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-running-agent-run
needs tower docker vm vmagent
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state VM-RUNNING-AGENT
snap_before
flow BK-RUN-MANUAL mode=backup
run=$(jq -r .run "$UB/state/status.json"); log=$UB/logs/run-$run.log
evidence "$log" run.log >/dev/null 2>&1
# the engine's words (backup.sh): «VM '<vm>': file systems frozen» … «VM '<vm>': released (frozen, N s)»; libvirt: the
# guest agent's own freeze status afterwards
f=$(grep -E "VM 'uso-vm-agent': file systems frozen" "$log" | head -1); t=$(grep -E "VM 'uso-vm-agent': released \(frozen" "$log" | head -1)
fs=$(timeout 20 virsh qemu-agent-command uso-vm-agent '{"execute":"guest-fsfreeze-status"}' 2>/dev/null | jq -r '.return // empty')
[[ -n $f && -n $t && $fs == thawed ]] && cell freeze-thaw 1 "frozen and thawed: ${f:0:100} / ${t:0:100}; the agent says «$fs»" \
    || cell freeze-thaw 0 "the run's log: freeze «${f:-none}», release «${t:-none}»; the agent says «${fs:-no answer}»"
[[ $(virsh domstate uso-vm-agent 2>/dev/null | head -1) == running ]] && cell runs-after 1 "uso-vm-agent runs after the run" || cell runs-after 0 "uso-vm-agent is «$(virsh domstate uso-vm-agent | head -1)» after the run"
[[ -n $(ls "$PLACE/vms/uso-vm-agent" 2>/dev/null) ]] && cell package 1 "package vms/uso-vm-agent: $(ls "$PLACE/vms/uso-vm-agent" | head -5 | paste -sd' ' -)" || cell package 0 "no package vms/uso-vm-agent"
check standard
check final
scenario_end
