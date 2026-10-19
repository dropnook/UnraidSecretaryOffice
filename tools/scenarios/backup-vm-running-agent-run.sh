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
f=$(grep -iE "uso-vm-agent.*(froz|freez)|(froz|freez).*uso-vm-agent" "$log" | head -1); t=$(grep -iE "uso-vm-agent.*thaw|thaw.*uso-vm-agent" "$log" | head -1)
[[ -n $f && -n $t ]] && cell freeze-thaw 1 "frozen and thawed: ${f:0:120} / ${t:0:120}" || cell freeze-thaw 0 "the run's log: freeze «${f:-none}», thaw «${t:-none}»"
[[ $(virsh domstate uso-vm-agent 2>/dev/null | head -1) == running ]] && cell runs-after 1 "uso-vm-agent runs after the run" || cell runs-after 0 "uso-vm-agent is «$(virsh domstate uso-vm-agent | head -1)» after the run"
[[ -n $(ls "$PLACE/vms/uso-vm-agent" 2>/dev/null) ]] && cell package 1 "package vms/uso-vm-agent: $(ls "$PLACE/vms/uso-vm-agent" | head -5 | paste -sd' ' -)" || cell package 0 "no package vms/uso-vm-agent"
check standard
check final
scenario_end
