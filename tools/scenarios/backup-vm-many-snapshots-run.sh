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
ts=$(_sc_now)                                # the snapshots are made: a look of «Where is what» from now on sees them
snap_before
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '"\(.result) \(.message // "")"' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings) ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
s=$(find "$PLACE/vms/uso-vm-plain" -path '*snapshotdb*' 2>/dev/null | head -3 | paste -sd' ' -)
[[ -n $s ]] && cell package-snapshotdb 1 "its package carries Unraid's snapshot list: ${s:0:200}" || cell package-snapshotdb 0 "no snapshotdb in vms/uso-vm-plain ($(ls "$PLACE/vms/uso-vm-plain" 2>/dev/null | paste -sd' ' -))"
# «Where is what» as her page asks for it (cleanup.where_refresh: a look younger than 60 s is kept, CL_WHERE_DEBOUNCE) —
# a look from after the snapshots; the page writes where.vm.overlay for a disk whose `chain` isn't empty (desk.js:3424)
. "$KEEP/state-vm-many-snapshots.env" 2>/dev/null
wait_for 150 "a look of «Where is what» from after the snapshots" bash -c 'php "$1" cleanup.where_refresh "{}" >/dev/null 2>&1; (( $(jq -r ".time // 0" "$2") >= $3 ))' _ "$SC_DIR/ask.php" "$D/cleanup-where.json" "$ts"
w=$(jq -c '[.. | objects | select(has("disks") and (.name? // "") == "uso-vm-plain")] | .[0].disks // [] | map({source, n: (.chain // [] | length)})' "$D/cleanup-where.json" 2>/dev/null)
[[ $(jq -r '.[0].source // ""' <<<"$w") == "$OVERLAY" && $(jq -r '.[0].n // 0' <<<"$w") -ge 1 ]] \
    && cell overlay-said 1 "«Where is what»: the disk is the overlay with the files under it: $w" \
    || cell overlay-said 0 "«Where is what» on uso-vm-plain's disk: $w (the disk is $OVERLAY)"
check standard
check final
scenario_end
