#!/bin/bash
# Cell R7 of matrix.md: uso-vm-plain is gone from libvirt (VM-GONE: `virsh undefine --nvram`, its folder and disk stay)
# after a run wrote its package and snapshotted domains. Mr. Restori's drill checks the VM's disk chain in the snapshot
# (its vmdisk step: vm_chain_ok, or «not checked», never failed); «Restore the VM» defines it again from the package —
# the XML libvirt had before — and leaves the disk that is still there alone (never over something still there without
# asking): its plan names where the disk's moment lies (after.vm_disks: the file and its snapshot — a files restore of
# the folder brings it, restore.php rsPlanVm «its disks come back as a folder from a snapshot (step 4)»), and the VM
# stays off — never started (its qemu log has no new «starting up»). INV-UNRAID-SAME is allowed: the flow's own purpose
# is the VM defined again (ablaeufe.md RS-VM «virsh dominfo ok»); the cell defined-as-before checks that it is exactly
# the XML of before. The undo puts the restore back first (his «Put back», the recorded undo), then VM-GONE's own undo.
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-vm-gone-vm-drill
needs tower vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
VM=uso-vm-plain
starts() { local n; n=$(grep -c 'starting up' "/var/log/libvirt/qemu/$1.log" 2>/dev/null); echo "${n:-0}"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the VM's package and its folder's snapshot
state VM-GONE plain
stat -c '%s %Y' "$(_st_vm_root)/$VM/vdisk1.img" >"$KEEP/disk-before.txt" 2>/dev/null
allow INV-UNRAID-SAME "RS-VM defines the gone VM again — the flow's purpose (ablaeufe.md RS-VM); cell defined-as-before checks its XML"
snap_before
s0=$(starts "$VM")
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id // empty' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
j=$D/restore-drill/$id/journal.json
evidence "$j" drill-journal.json
vd=$(jq -c --arg v "$VM" '[.steps[]? | select(((.name // "") + " " + ((.params // {}) | tostring)) | test($v)) | {name, state, code}]' "$j" 2>/dev/null)
say "the drill's steps for $VM: $vd"
n=$(jq -r '[.[] | select((.code // "") | test("^vm_(chain|disk)"))] | length' <<<"${vd:-[]}")
f=$(jq -r '[.[] | select(.state == "failed")] | length' <<<"${vd:-[]}")
[[ $n -ge 1 && $f == 0 ]] && cell drill-vmdisk 1 "the drill checked $VM's disk from the snapshot: $(jq -c '[.[] | select((.code // "") | test("^vm_(chain|disk)")) | .code]' <<<"$vd")" \
    || cell drill-vmdisk 0 "the drill's disk steps for $VM: $n, failed $f ($vd)"
undo_push flow RS-PUTBACK of=last
if flow RS-VM vm="$VM"; then
    rid=$(fl_last_restore)
    evidence "$D/restore/$rid/journal.json" restore-vm-journal.json
    say "RS-VM $rid steps: $(jq -c '[.steps[]? | .do]' "$D/restore/$rid/plan.json" 2>/dev/null)"
    pl=$D/restore/$rid/plan.json
    disk=$(_st_vm_disk1 "$VM"); d0=$(cat "$KEEP/disk-before.txt" 2>/dev/null)
    if _st_vm_there "$VM"; then
        st=$(_st_vm_state "$VM")
        [[ $st == "shut off" && $(starts "$VM") == "$s0" ]] && cell defined-stays-off 1 "$VM is defined again and stays shut off, never started" \
            || cell defined-stays-off 0 "$VM is «$st» after the restore (starts $s0 → $(starts "$VM"))"
    else
        cell defined-stays-off 0 "$VM is not defined after «Restore the VM»"
    fi
    vd=$(jq -r '[.after[]? | select(.key == "after.vm_disks") | .params.list] | join(" ")' "$pl")
    [[ $vd == *"@uso-backup-"* && $(stat -c '%s %Y' "$disk" 2>/dev/null) == "$d0" ]] \
        && cell disk-said-not-touched 1 "the disk is left as it is, the plan names its moment: $vd" \
        || cell disk-said-not-touched 0 "the plan's after.vm_disks «${vd:-none}», the disk $disk $(stat -c '%s %Y' "$disk" 2>/dev/null) (before: $d0)"
    x0=$(_st_dir VM-GONE)/$VM.xml
    [[ -f $x0 && $(virsh dumpxml --inactive "$VM" 2>/dev/null | md5sum) == "$(md5sum <"$x0")" ]] \
        && cell defined-as-before 1 "$VM is defined with exactly the XML libvirt had before" \
        || cell defined-as-before 0 "$VM's XML now differs from the one before VM-GONE$([[ -f $x0 ]] || echo ' (no XML noted)')"
fi
check standard
check final
scenario_end
