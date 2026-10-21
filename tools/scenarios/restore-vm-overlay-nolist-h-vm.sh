#!/bin/bash
# Batch H, finding 8 (parking lot «Resilienz-Funde», H4's wave 1b): Unraid's VM manager names a VM snapshot's overlay
# `vdisk1.<name>qcow2` (no dot before qcow2) and keeps its own list (/etc/libvirt/qemu/snapshotdb) — libvirt's snapshot
# list it drops (seen: `virsh snapshot-list` empty while the VM runs on the overlay). B36 (backup-vm-many-snapshots-run)
# backs such a VM up with libvirt's list there; this cell drops libvirt's list (virsh snapshot-delete --metadata: the
# overlay and Unraid's snapshotdb stay — what Unraid leaves), backs the VM up, then the VM gone (undefined, its folder
# kept as Unraid's ⟦Remove VM⟧ without disks) and Mr. Restori's «Restore VM» (RS-VM). Expected: the run doesn't fail
# and its package holds the whole chain — the overlay and the image under it (cell package-chain); the restored VM's
# disk is the overlay with its backing file resolved (cell chain-resolves: qemu-img info --backing-chain) and Unraid's
# snapshotdb back (cell snapshotdb-back). Needs the VM service (Tower: uso-vm-plain) — USOTest3VM has none: skipped
# there (2026-10-21). Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-vm-overlay-nolist-h-vm
needs tower docker vm
VM=uso-vm-plain
nc_data_closed
state VM-MANY-SNAPSHOTS 1
. "$KEEP/state-vm-many-snapshots.env" 2>/dev/null
for s in $(virsh snapshot-list --name "$VM" 2>/dev/null); do virsh snapshot-delete "$VM" "$s" --metadata >/dev/null && say "libvirt's snapshot $s dropped from its list (the overlay stays)"; done
say "libvirt's list now: $(virsh snapshot-list --name "$VM" 2>/dev/null | paste -sd' ' -) · Unraid's snapshotdb: $(ls /etc/libvirt/qemu/snapshotdb/$VM 2>/dev/null | paste -sd' ' -) · disk: ${OVERLAY:-?}"
snap_before
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '"\(.result) \(.message // "")"' "$UB/state/status.json")
pk=$(find "$PLACE/vms/$VM" -type f \( -name '*.img' -o -name '*qcow2' \) 2>/dev/null | sed "s#^$PLACE/vms/$VM/##" | paste -sd' ' -)
[[ $r =~ ^(ok|warnings) && $pk == *"$(basename "${OVERLAY:-x}")"* && $pk == *vdisk1.img* ]] && h_cell package-chain 1 "the run $r; the package: $pk" \
    || h_cell package-chain 0 "the run $r; the package's disks: ${pk:-none} (overlay $(basename "${OVERLAY:-?}") and vdisk1.img both needed)"
state VM-GONE plain
flow RS-VM vm="$VM"
d=$(virsh domblklist "$VM" --details 2>/dev/null | awk '$2 == "disk" {print $4; exit}')
c=$(qemu-img info --backing-chain -U "$d" 2>&1 | grep -E '^(image|backing file):' | paste -sd' ' -)
[[ -n $d && $c == *"backing file"* && $(qemu-img info --backing-chain -U "$d" 2>&1 | grep -c '^image:') -ge 2 ]] \
    && h_cell chain-resolves 1 "the restored VM's disk $d: $c" || h_cell chain-resolves 0 "the restored VM's disk ${d:-none}: ${c:-no chain}"
[[ -d /etc/libvirt/qemu/snapshotdb/$VM ]] && h_cell snapshotdb-back 1 "Unraid's snapshotdb of $VM there: $(ls /etc/libvirt/qemu/snapshotdb/$VM | paste -sd' ' -)" \
    || h_cell snapshotdb-back 0 "no snapshotdb of $VM after the restore — Unraid's VM view knows no snapshot of it"
check standard
scenario_end
