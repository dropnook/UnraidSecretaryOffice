#!/bin/bash
# Cell B29 of matrix.md: parts on a file system without snapshots (finding 19 of 2026-10-16: the setup showed «local
# snapshot» for an XFS part without a word). SH-ARRAY uso-wip-c-arr (array only: files on disk1 = xfs and disk2 = zfs)
# and VM-DISK-ON-XFS (uso-vm-xfs, its disk on the xfs-only share uso-vmxfs).
# - BK-REPLAN: the share is never proposed «local» (off, why no_snapshots — setup.sh 2.55), or where it is local the
#   plan warns share_no_snapshots; the VM's disk is warned vm_no_snapshot_live wherever the VM is backed up locally.
# - The setup page shows those words (page_text, cells share-hint / vm-hint) — never «local snapshot» bare.
# - BK-RUN: the run says the VM's disk isn't held (vm_disk_live / vm_no_snapshot_live), never a snapshot claimed (its
#   word for a VM not set up yet, new_vm, says «its disks are in the snapshot of their share» — false on xfs).
# Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-array-xfs-replan-run
needs tower zfs vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-arr V=uso-vm-xfs
nc_data_closed
state SH-ARRAY "$S" 5
state VM-DISK-ON-XFS
nc_data_closed
track_share "$S"
snap_before
expect_changes "share|$S|*" "vm|$V|*" "share|uso-vmxfs|*"     # new for the setup: the share, the VM and its share
expect_warnings vm_disk_live vm_no_snapshot_live share_new vm_new
flow BK-REPLAN
plan=$KEEP/plan-replan.json
msgs=$(jq -c '[.messages[]? | {code, params}]' "$plan")
evidence "$plan" >/dev/null
sm=$(jq -r --arg s "$S" '.P["share|\($s)|mode"] // empty' "$plan") sw=$(jq -r --arg s "$S" '.shares[]? | select(.name == $s) | .why' "$plan")
sh=$(jq -r --arg s "$S" '[.messages[]? | select(.code == "share_no_snapshots" and (.params.share // .params.name // "") == $s)] | length' "$plan")
if [[ $sm != snapshot ]]; then
    [[ $sw == no_snapshots || $sm == kopia ]] && cell share-not-local 1 "$S proposed «$sm» (why: $sw)" \
        || cell share-not-local 0 "$S proposed «$sm», why «$sw» — no word that it can't have a local snapshot"
else
    ((sh > 0)) && cell share-not-local 1 "$S at local, warned share_no_snapshots" || cell share-not-local 0 "$S proposed «local snapshot» without share_no_snapshots"
fi
vm=$(jq -r --arg v "$V" '.P["vm|\($v)|mode"] // empty' "$plan")
vh=$(jq -r --arg v "$V" '[.messages[]? | select((.code | startswith("vm_no_snapshot")) and ((.params | tostring) | contains($v)))] | map(.code) | join(",")' "$plan")
if [[ $vm == snapshot || $vm == kopia ]]; then
    [[ -n $vh ]] && cell vm-hint 1 "$V proposed «$vm», the plan says $vh" || cell vm-hint 0 "$V proposed «$vm» (its disk on xfs) without a vm_no_snapshot_* message: $msgs"
else
    cell vm-hint 1 "$V proposed «${vm:-nothing}» (not backed up locally)${vh:+, said: $vh}"
fi
# the setup page on this plan: the words, not «local snapshot» bare
ask_save_post backup.setup_get '{}' 120 >/dev/null
bubble_look setup backup
look=$(ls -d "$KEEP"/looks/*-setup | tail -1)
[[ $sm != snapshot ]] && queue_mac share-hint page_text --open backup/setup "$look/states" "$PAGE_LANGS" want "backup.setup.why.no_snapshots"
[[ $sm == snapshot ]] && queue_mac share-hint page_text --open backup/setup "$look/states" "$PAGE_LANGS" want "backup.setup.msg.share_no_snapshots"
[[ -n $vh ]] && queue_mac vm-hint-page page_text --open backup/setup "$look/states" "$PAGE_LANGS" want "backup.setup.msg.${vh%%,*}"
queue_mac INV-PAGE page_clean backup/setup "$look/states" "$PAGE_LANGS"
flow BK-RUN-MANUAL mode=backup
ws=$(jq -r '[.warnings[]? | (.code // .why // empty)] | join(",")' "$UB/state/status.json" 2>/dev/null)
dr=$(jq -r --arg v "$V" '[.items[]? | select((.params.name // "") == $v) | .code] | join(",")' "$UB/state/drift.json" 2>/dev/null)
say "the run's warnings: ${ws:-none}; its words on $V: ${dr:-none}"
[[ ,$ws,$dr, == *,vm_disk_live,* || ,$ws,$dr, == *vm_no_snapshot_live* ]] && cell run-says-vm 1 "the run says $V's disk isn't held: ${dr:-$ws}" \
    || cell run-says-vm 0 "the run names nothing about $V's disk on xfs (warnings ${ws:-none}, its words: ${dr:-none}$([[ ,$dr, == *,new_vm,* ]] && echo " — new_vm: «its disks are in the snapshot of their share», false on xfs"))"
held=$(zfs list -H -t snapshot -o name 2>/dev/null | grep -F "/$S@uso-backup-" | head -2 | paste -sd' ')
say "engine snapshots of $S's parts: ${held:-none}"
check standard
check final
scenario_end
