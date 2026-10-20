#!/bin/bash
# Cell B36 of matrix.md, a VM gone: libvirt forgot uso-vm-plain, its folder and disk stay (VM-GONE). «Look at the server
# again» drops its saved section (backup/setup.sh:2412 «a VM that is gone drops out - unless the VM service is off»: the
# Apply list names its vm|uso-vm-plain|… keys, nothing else), the run doesn't fail on it and leaves its folder, its disk
# and its package untouched. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-gone-replan-run
needs tower docker vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # its package of before
pk=$(find "$PLACE/vms/uso-vm-plain" -type f -printf '%P %s\n' 2>/dev/null | sort | md5sum)
state VM-GONE
fold=$(sed -n 's/^VM_FOLDER=//p' "$KEEP/state-vm-gone.env" 2>/dev/null)
fs=$(find "$fold" -maxdepth 1 -type f -printf '%f %s\n' 2>/dev/null | sort | md5sum)
snap_before
flow BK-REPLAN
expect_changes 'vm|uso-vm-plain|*'            # the gone VM's section dropped (setup.sh:2412, header)
expect_warnings vm_gone package_gone
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '.result' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings)$ ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
[[ $(find "$fold" -maxdepth 1 -type f -printf '%f %s\n' 2>/dev/null | sort | md5sum) == "$fs" ]] && cell folder-kept 1 "its folder $fold as it was" || cell folder-kept 0 "its folder $fold changed"
[[ $(find "$PLACE/vms/uso-vm-plain" -type f -printf '%P %s\n' 2>/dev/null | sort | md5sum) == "$pk" ]] && cell package-kept 1 "its package untouched" || cell package-kept 0 "its package vms/uso-vm-plain changed"
check standard
check final
scenario_end
