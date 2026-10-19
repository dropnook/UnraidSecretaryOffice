#!/bin/bash
# Cell R7 of matrix.md: uso-vm-plain is gone from libvirt (VM-GONE: `virsh undefine --nvram`, its folder and disk stay)
# after a run wrote its package and snapshotted domains. Mr. Restori's drill checks the VM's disk from the snapshot
# (vmdisk: ok or «not checked», never failed); «Restore the VM» defines it again from the package and brings its disk
# from the snapshot, and the VM stays off — never started (its qemu log has no new «starting up»). The undo puts the
# restore back first (his «Put back», the recorded undo) and then VM-GONE's own undo. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-vm-gone-vm-drill
needs tower vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
VM=uso-vm-plain
starts() { local n; n=$(grep -c 'starting up' "/var/log/libvirt/qemu/$1.log" 2>/dev/null); echo "${n:-0}"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the VM's package and its folder's snapshot
state VM-GONE plain
snap_before
s0=$(starts "$VM")
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id // empty' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
j=$D/restore-drill/$id/journal.json
evidence "$j" drill-journal.json
vd=$(jq -c --arg v "$VM" '[.steps[]? | select(((.name // "") + " " + ((.params // {}) | tostring)) | test($v)) | {name, state, code}]' "$j" 2>/dev/null)
say "the drill's steps for $VM: $vd"
n=$(jq -r '[.[] | select((.name // "") | test("disk"; "i"))] | length' <<<"${vd:-[]}")
f=$(jq -r '[.[] | select(.state == "failed")] | length' <<<"${vd:-[]}")
[[ $n -ge 1 && $f == 0 ]] && cell drill-vmdisk 1 "the drill checked $VM's disk from the snapshot: $(jq -c '[.[] | select((.name // "") | test("disk"; "i")) | .state]' <<<"$vd")" \
    || cell drill-vmdisk 0 "the drill's disk steps for $VM: $n, failed $f ($vd)"
undo_push flow RS-PUTBACK of=last
if flow RS-VM vm="$VM"; then
    rid=$(fl_last_restore)
    evidence "$D/restore/$rid/journal.json" restore-vm-journal.json
    say "RS-VM $rid steps: $(jq -c '[.steps[]? | .do]' "$D/restore/$rid/plan.json" 2>/dev/null)"
    dd=$(jq -r '[.steps[]? | select(.do == "copy" or .do == "swap" or .do == "move") | .from // empty | select(test("/[.]zfs/snapshot/"))] | length' "$D/restore/$rid/plan.json" 2>/dev/null)
    if _st_vm_there "$VM"; then
        st=$(_st_vm_state "$VM")
        [[ $st == "shut off" && $(starts "$VM") == "$s0" ]] && cell defined-stays-off 1 "$VM is defined again and stays shut off, never started" \
            || cell defined-stays-off 0 "$VM is «$st» after the restore (starts $s0 → $(starts "$VM"))"
    else
        cell defined-stays-off 0 "$VM is not defined after «Restore the VM»"
    fi
    [[ ${dd:-0} -ge 1 ]] && cell disk-from-snapshot 1 "its disk folder came from a snapshot ($dd step(s))" \
        || cell disk-from-snapshot 0 "the restore's plan brings no disk from a snapshot: $(jq -c '[.steps[]? | {do, from}]' "$D/restore/$rid/plan.json" 2>/dev/null | head -c 300)"
fi
check standard
check final
scenario_end
