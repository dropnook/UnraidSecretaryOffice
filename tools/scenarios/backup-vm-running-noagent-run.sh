#!/bin/bash
# Cell B36 of matrix.md, a VM without a guest agent: uso-vm-plain running (VM-RUNNING-NOAGENT), saved prepare=pause.
# The run pauses it for the snapshot and resumes it — for real: the run's log says both, libvirt shows it running
# afterwards (never left paused). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-running-noagent-run
needs tower docker vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state VM-RUNNING-NOAGENT
snap_before
flow BK-RUN-MANUAL mode=backup
run=$(jq -r .run "$UB/state/status.json"); log=$UB/logs/run-$run.log
evidence "$log" run.log >/dev/null 2>&1
p=$(grep -iE "uso-vm-plain.*paus|paus.*uso-vm-plain" "$log" | head -1); r=$(grep -iE "uso-vm-plain.*resum|resum.*uso-vm-plain" "$log" | head -1)
[[ -n $p && -n $r ]] && cell pause-resume 1 "paused and resumed: ${p:0:120} / ${r:0:120}" || cell pause-resume 0 "the run's log: pause «${p:-none}», resume «${r:-none}»"
[[ $(virsh domstate uso-vm-plain 2>/dev/null | head -1) == running ]] && cell runs-after 1 "uso-vm-plain runs after the run" || cell runs-after 0 "uso-vm-plain is «$(virsh domstate uso-vm-plain | head -1)» after the run"
check standard
check final
scenario_end
