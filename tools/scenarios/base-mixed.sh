#!/bin/bash
# tools/scenarios/base-mixed.sh — the objects of Tower's «mixed» base profile (base/tower/profile-mixed.env says what and
# why) on the VM as root. Sourced by base.sh (mixed_objects_ensure, mixed_objects_remove, mixed_after_agent) and run on
# its own by tools/scenarios/mixed-make.sh:
#
#   bash base-mixed.sh objects        the profile's VMs and shares there (idempotent: what is there stays)
#   bash base-mixed.sh remove         taken away again (the default reset does it: the default base never sees them)
#   bash base-mixed.sh after          with the agent at work: the running VM started, the pair with the partner asked
#   bash base-mixed.sh pair-id        the newest pair with MIXED_PARTNER_IP in Tower's pairs.json (its id), else exit 1
#   bash base-mixed.sh has-base       exit 0 when the profile's base is kept (<data>.base-mixed/data.tar)
#   bash base-mixed.sh apply <id>     Mr. Backupsy's plan made anew and Apply with MIXED_DECISIONS (@ID@ = <id>) — the
#                                     page's own body (flows.sh BK-APPLY: plan-changes.mjs --apply)
#
# What it made is noted on the flash ($OFFICE_FLASH/scenarios-mixed.objects, one «vm <name>» / «share <name>» a line):
# only that is ever removed. Exit 0 done, 1 a step failed (said).

_MX_SC=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
MIXED_OBJECTS=/boot/config/plugins/unraid-secretary-office/scenarios-mixed.objects
_mx_say() { if declare -F log >/dev/null; then log "mixed: $*"; else echo "mixed: $*"; fi; }
_mx_env() {
    [[ -n ${MIXED_DECISIONS+x} ]] && return 0
    # shellcheck disable=SC1091
    . "$_MX_SC/base/tower/profile-mixed.env"
}
_mx_known() { sed -e 's/#.*//' -e '/^[[:space:]]*$/d' "$_MX_SC/base/tower/plan-changes.mixed.known" 2>/dev/null | jq -Rcs 'split("\n") | map(select(length > 0))'; }
_mx_note() { grep -qxF "$1" "$MIXED_OBJECTS" 2>/dev/null || printf '%s\n' "$1" >>"$MIXED_OBJECTS"; }
_mx_unnote() { [[ -f $MIXED_OBJECTS ]] || return 0; grep -vxF "$1" "$MIXED_OBJECTS" >"$MIXED_OBJECTS.new"; mv "$MIXED_OBJECTS.new" "$MIXED_OBJECTS"; }

mixed_objects_ensure() {
    _mx_env
    local k vm rc=0
    for k in $MIXED_VMS; do
        vm=uso-vm-$k
        if virsh domstate "$vm" >/dev/null 2>&1; then _mx_say "VM $vm there"; _mx_note "vm $vm"; continue; fi
        # noted first: a half-made one goes with the next default reset too
        _mx_note "vm $vm"
        [[ $k == xfs ]] && _mx_note "share $ST_VM_XFS_SHARE"
        vm_make "$k" >/dev/null 2>&1 || { _mx_say "! VM $vm could not be made"; rc=1; continue; }
        _mx_say "VM $vm made ($(virsh domblklist "$vm" --details 2>/dev/null | awk '$2 == "disk" {print $4}'))"
    done
    return $rc
}

mixed_objects_remove() {
    local kind name rc=0 d
    [[ -s $MIXED_OBJECTS ]] || return 0
    # the VMs first (their folders), then the shares they lay in — each emptied only of the profile's own VM folder
    while read -r kind name; do
        [[ $kind == vm && $name == uso-vm-* ]] || continue
        if vm_remove "${name#uso-vm-}" >/dev/null 2>&1 || ! virsh domstate "$name" >/dev/null 2>&1; then
            _mx_unnote "vm $name"; _mx_say "VM $name taken away"
        else _mx_say "! VM $name could not be taken away"; rc=1; fi
    done < <(cat "$MIXED_OBJECTS")
    while read -r kind name; do
        [[ $kind == share ]] || continue
        for d in /mnt/*/"$name"; do [[ -d $d && $d != /mnt/user*/* ]] && rmdir "$d"/uso-vm-* 2>/dev/null; done
        if share_remove "$name" >/dev/null 2>&1; then _mx_unnote "share $name"; _mx_say "share $name taken away"
        else _mx_say "! share $name could not be taken away (not empty?)"; rc=1; fi
    done < <(cat "$MIXED_OBJECTS")
    rm -rf "$(_st_dir VM-XFS)" 2>/dev/null
    [[ -s $MIXED_OBJECTS ]] || rm -f "$MIXED_OBJECTS"
    return $rc
}

mixed_pair_id() {
    _mx_env
    # the newest: pairing anew leaves the old silent pair beside the new one
    jq -r --arg a "$MIXED_PARTNER_IP" '[(.pairs // [])[] | select(.address == $a and (.my_key | type) == "string")] | sort_by(.paired // 0) | last | .id // empty' "$D/partner/pairs.json" 2>/dev/null
}
# the partner's door answers the pair (its `ping`, the pair's own key — as the engine's ssh)
mixed_pair_ok() {
    local id=$1 f=/boot/config/plugins/unraid-secretary-office/partners
    [[ -n $id && -f $f/$id.key ]] || return 1
    timeout 30 ssh -i "$f/$id.key" -o IdentitiesOnly=yes -o BatchMode=yes -o "UserKnownHostsFile=$f/$id.known" -o StrictHostKeyChecking=yes \
        -o ConnectTimeout=10 "root@$MIXED_PARTNER_IP" ping </dev/null 2>/dev/null | grep -q '"ok":true'
}

mixed_after_agent() {
    _mx_env
    local v id rc=0
    for v in $MIXED_RUNNING; do
        if [[ $(virsh domstate "$v" 2>/dev/null | head -1) == running ]]; then _mx_say "VM $v runs"
        elif vm_start "$v" >/dev/null 2>&1; then _mx_say "VM $v started (running, never held: prepare none)"
        else _mx_say "! VM $v could not be started"; rc=1; fi
    done
    id=$(mixed_pair_id)
    if [[ -z $id ]]; then _mx_say "! no pair with $MIXED_PARTNER_IP in Tower's pairs.json (mixed-make.sh pairs them)"; rc=1
    elif mixed_pair_ok "$id"; then _mx_say "the partner $MIXED_PARTNER_IP answers the pair $id"
    else _mx_say "! the partner $MIXED_PARTNER_IP doesn't answer the pair $id (its base reset drops the pair there: mixed-make.sh pairs anew)"; fi
    return $rc
}

mixed_apply() {
    _mx_env
    local id=$1 plan kv args=()
    [[ $id =~ ^[0-9a-f]{8}$ ]] || { _mx_say "apply needs the pair's id"; return 1; }
    plan=$KEEP/mixed-plan.json
    ask backup.setup_plan '{}' 120 >/dev/null || { _mx_say "the plan was refused"; return 1; }
    sleep 2
    wait_setup 900 || return 1
    _bk_plan_get "$plan" || { _mx_say "no plan from setup_get"; return 1; }
    for kv in "${MIXED_DECISIONS[@]}"; do args+=("${kv//@ID@/$id}"); done
    FL=() FL_KV=("${args[@]}")
    _bk_apply_body "$(decisions_from_plan "$plan" "${args[@]}")" || { _mx_say "Apply failed ($(jq -c '{result, messages: [.messages[]? | select(.level == "error") | .code]}' "$UB/state/setup-status.json" 2>/dev/null))"; return 1; }
    _mx_say "Apply done — $(jq -r '.result' "$UB/state/setup-status.json")"
    # then the page's own draft on a plan made anew, as the user opening the setup again would Apply it, until it changes
    # nothing (what the page derives from the first Apply: the backup place offsite with Kopia on, an app's own dataset
    # kept while its share goes to the partner) — the profile's plan then lists no change (INV-REPLAN)
    local i ch
    for i in 1 2 3; do
        ask backup.setup_plan '{}' 120 >/dev/null || return 1
        sleep 2
        wait_setup 900 || return 1
        _bk_plan_get "$plan" || return 1
        # what is left besides the profile's known list (base/tower/plan-changes.mixed.known: a product finding)
        ch=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" | jq -c --argjson k "$(_mx_known)" '[.[].key | select(. as $x | $k | index($x) | not)]')
        [[ $ch == '[]' ]] && { _mx_say "the plan made anew lists no change but the known ones ($(_mx_known))"; return 0; }
        _mx_say "the plan made anew lists $ch - Apply of the page's draft"
        FL=() FL_KV=()
        _bk_apply_body "$(decisions_from_plan "$plan")" || return 1
    done
    _mx_say "! the plan still lists changes after 3 Applies: $ch"
    return 1
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    # shellcheck disable=SC1091
    . "$_MX_SC/lib.sh" >/dev/null 2>&1
    case ${1:-} in
        objects) mixed_objects_ensure ;;
        remove) mixed_objects_remove ;;
        after) mixed_after_agent ;;
        pair-id) id=$(mixed_pair_id); [[ -n $id ]] && echo "$id" ;;
        has-base) [[ -f $D.base-mixed/data.tar ]] ;;
        apply) mixed_apply "${2:-}" ;;
        *) sed -n '2,15p' "$0"; exit 2 ;;
    esac
fi
