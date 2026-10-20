#!/bin/bash
# Cell B36 of matrix.md, a VM's disk missing: uso-vm-plain's XML names a disk file that isn't there (VM-DISK-MISSING).
# The plan warns vm_no_snapshot_missing, the run warns too and doesn't abort; the VM stays as it was. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-disk-missing-run
needs tower docker vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state VM-DISK-MISSING
snap_before
flow BK-REPLAN
m=$(jq -c '[.messages[]? | select(.code | test("^vm_no_snapshot")) | {code, vm: (.params.vm // .params.name // null)}]' "$KEEP/plan-replan.json")
[[ $m == *vm_no_snapshot_missing* ]] && cell plan-says 1 "the plan: $m" || cell plan-says 0 "the plan's VM words: $m"
expect_warnings vm_no_snapshot_missing vm_disk_missing
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '"\(.result) \(.message // "")"' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings) ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
check standard
check final
scenario_end
