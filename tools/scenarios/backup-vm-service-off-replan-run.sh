#!/bin/bash
# Cell B27 of matrix.md: the VM service is off (SV-VM-OFF, `rc.libvirt stop` — what ⟦Settings⟧ → ⟦VM Manager⟧ → «Enable
# VMs: No» does for the moment). «Look at the server again» proposes no change to the two VMs' [vm] sections (setup.sh
# keeps a VM it can't see while libvirt is off — INV-REPLAN), the plan says the service is off, the setup page says
# «The VM service is off — nothing to set up.» (backup.setup.vm_service_off), and a run backs up the rest without
# failing. The undo starts the service again with both VMs defined, shut off as before (INV-UNRAID-SAME of the base).
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-service-off-replan-run
needs tower vm
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state SV-VM-OFF
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    vs=$(jq -r '.vm_service // .facts.vm_service // empty' "$plan")
    vk=$(jq -r '[.P | to_entries[] | select(.key | startswith("vm|"))] | from_entries | tostring' "$plan")
    say "plan: vm_service «$vs», its vm keys $vk"
    [[ $vs == no ]] && cell plan-says-off 1 "the plan says the VM service is off" \
        || cell plan-says-off 0 "the plan's vm_service is «${vs:-missing}» while libvirt is stopped"
    if command -v node >/dev/null 2>&1; then
        ch=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -r '[.[].key | select(startswith("vm|"))] | join(" ")')
        [[ -z $ch ]] && cell no-vm-change 1 "the Apply list names no [vm] key" \
            || cell no-vm-change 0 "the Apply list names VM keys while the service is off: $ch"
    fi
    bubble_look vm-off-setup backup
    queue_mac B27-setup-says page_text backup/setup "$KEEP/looks/$(printf %02d "$(cat "$KEEP/.looks")")-vm-off-setup/states" en,de want backup.setup.vm_service_off
fi
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '.result // empty' "$UB/state/status.json" 2>/dev/null)
[[ $r == ok || $r == warnings ]] && cell run-not-failed 1 "the run ended «$r» with the VM service off" \
    || cell run-not-failed 0 "the run ended «$r» with the VM service off: $(jq -r '.message // ""' "$UB/state/status.json")"
check standard
check final
scenario_end
