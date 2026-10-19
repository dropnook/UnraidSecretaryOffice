#!/bin/bash
# Cell B36 of matrix.md, Unraid's own VM snapshots: three of uso-vm-plain made by Unraid's VM manager (VM-MANY-SNAPSHOTS:
# its disk now a qcow2 overlay over the old one, libvirt's list, snapshotdb). The run backs the VM up (its package carries
# snapshotdb/), doesn't fail, and the office says what is really there: Ms. Dustdevil's «Where is what» names the VM's
# disk as an overlay of a VM snapshot (where.vm.overlay). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-many-snapshots-run
needs tower docker vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state VM-MANY-SNAPSHOTS 3
snap_before
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '"\(.result) \(.message // "")"' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings) ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
s=$(find "$PLACE/vms/uso-vm-plain" -path '*snapshotdb*' 2>/dev/null | head -3 | paste -sd' ' -)
[[ -n $s ]] && cell package-snapshotdb 1 "its package carries Unraid's snapshot list: ${s:0:200}" || cell package-snapshotdb 0 "no snapshotdb in vms/uso-vm-plain ($(ls "$PLACE/vms/uso-vm-plain" 2>/dev/null | paste -sd' ' -))"
w=$(state_json cleanup-where '[.. | objects | select((.vm // .name // "") == "uso-vm-plain") | .. | strings | select(test("overlay"))] | unique | join(",")' 2>/dev/null)
[[ -n $w ]] && cell overlay-said 1 "«Where is what» says: $w" || cell overlay-said 0 "«Where is what» names no overlay for uso-vm-plain's disk"
check standard
check final
scenario_end
