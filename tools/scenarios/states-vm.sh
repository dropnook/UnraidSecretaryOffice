#!/bin/bash
# tools/scenarios/states-vm.sh — the test VMs and the B states (VM-*) of zustaende.md (H4, resilience wave 1b).
# Sourced by lib.sh; uses states-common.sh. Tower only (the VM service; nested KVM). Tiny VMs, at most two running.
#
#   vm_make plain|agent|xfs|block|hostdev   uso-vm-<kind> defined (shut off) when missing, the way Unraid's VM manager
#                       lays it out: folder domains/uso-vm-<kind>/ (a dataset of its own on ZFS), vdisk1.img qcow2, Unraid's
#                       <vmtemplate> metadata. plain: an empty 1 GiB disk, no OS (boots into «no bootable device» and stays
#                       running), no guest agent channel. agent: Alpine with qemu-guest-agent (vm-alpine.sh's cached disk),
#                       the channel org.qemu.guest_agent.0, NAT network. xfs: its disk on an array share without snapshots
#                       (uso-vmxfs on disk1, xfs on Tower). block: its disk a loop device (type=block). hostdev: a PCI
#                       device passed through (an address no device has — defined, never started).
#   vm_remove <kind>    undefined, its folder (dataset) and loop device gone — xfs|block|hostdev only (plain/agent are base)
#   vm_start|vm_shutdown|vm_destroy|vm_suspend|vm_resume <vm>    libvirt's own, waited for (shutdown: through the guest
#                       agent when it answers, else ACPI, destroy after 60 s)
#   vm_undefine <id> <vm> / vm_define_back <id> <vm>     the XML (and autostart) kept in the stash, the folder stays
#   vm_disk_hide <id> <vm> / vm_disk_back <id> <vm>      its first disk file moved into the stash and back (≤ 512 MiB)
#   vm_note <id> <vm> / vm_back <id> <vm>                its state (running|paused|shut off) and autostart noted / restored
#
# States: VM-RUNNING-AGENT VM-RUNNING-NOAGENT VM-SHUTOFF VM-PAUSED-BY-USER VM-GONE VM-DISK-MISSING VM-DISK-ON-XFS
# VM-DISK-BLOCK VM-HOSTDEV VM-SERVICE-OFF VM-MANY-SNAPSHOTS. Notes for the checks: $KEEP/state-<id>.env (VM=…, …).

ST_VM_PREFIX=uso-vm-
ST_VM_XFS_SHARE=uso-vmxfs
ST_VM_HELPERS=/usr/local/emhttp/plugins/dynamix.vm.manager/include/libvirt_helpers.php

# ------------------------------------------------------------------- shared by H4's files: more in the fingerprint
# _st_fp_add <fn> — the fn's lines join the states' fingerprint (_st_fp of states-common.sh, before its «ids» part)
declare -F _st_fp_add >/dev/null || _st_fp_add() { [[ " ${ST_FP_EXTRA[*]-} " == *" $1 "* ]] || ST_FP_EXTRA+=("$1"); }
if declare -F _st_fp >/dev/null && ! declare -F _st_fp_h2 >/dev/null; then
    eval "$(declare -f _st_fp | sed '1s/^_st_fp /_st_fp_h2 /')"
    _st_fp() {
        local out f; out=$(_st_fp_h2)
        sed '/^## ids/,$d' <<<"$out"
        for f in "${ST_FP_EXTRA[@]-}"; do [[ -n $f ]] && "$f"; done
        sed -n '/^## ids/,$p' <<<"$out"
    }
fi
# _st_keep_env <id> KEY=value… — what H5's checks read about a state ($KEEP/state-<id>.env, one KEY=value a line)
declare -F _st_keep_env >/dev/null || _st_keep_env() {
    local f=${KEEP:-/tmp/uso-keep/states}/state-${1,,}.env kv; shift
    mkdir -p "$(dirname "$f")"
    for kv in "$@"; do printf '%s\n' "$kv" >> "$f"; done
}

_st_fp_vm() {
    echo "## vms"
    if timeout 10 virsh version >/dev/null 2>&1; then
        local v
        while IFS= read -r v; do
            [[ -z $v ]] && continue
            # the XML without what libvirt changes by itself (ids, the running state's extras)
            printf '%s %s autostart=%s xml=%s\n' "$v" "$(virsh domstate "$v" 2>/dev/null | head -1 | tr ' ' _)" \
                "$(virsh dominfo "$v" 2>/dev/null | awk '/^Autostart:/ {print $2}')" \
                "$(virsh dumpxml --inactive "$v" 2>/dev/null | md5sum | cut -c1-12)"
            virsh snapshot-list --name "$v" 2>/dev/null | sed '/^$/d; s/^/  snapshot /'
        done < <(virsh list --all --name 2>/dev/null | sort)
    else
        echo "libvirt off"
    fi
    ls -A /etc/libvirt/qemu/snapshotdb 2>/dev/null | sed 's/^/snapshotdb /'
    losetup -a 2>/dev/null | grep -F "$ST_VM_PREFIX" | sed 's/^[^:]*: //'
}
_st_fp_add _st_fp_vm

_st_vm_up() { [[ -S /var/run/libvirt/libvirt-sock ]] && timeout 10 virsh version >/dev/null 2>&1; }
_st_vm_there() { virsh domstate "$1" >/dev/null 2>&1; }
_st_vm_state() { virsh domstate "$1" 2>/dev/null | head -1; }
_st_vm_agent_ok() { timeout 5 virsh qemu-agent-command "$1" '{"execute":"guest-ping"}' >/dev/null 2>&1; }
_st_vm_name() { case $1 in "$ST_VM_PREFIX"*) printf '%s' "$1" ;; *) printf '%s%s' "$ST_VM_PREFIX" "$1" ;; esac; }
_st_vm_running_n() { virsh list --name --state-running --state-paused 2>/dev/null | sed '/^$/d' | wc -l; }

# the domains share's root that holds VM folders: the pool of an exclusive share, else the first disk/pool with it
_st_vm_root() {
    local r; r=$(readlink -f /mnt/user/domains 2>/dev/null)
    [[ $r == /mnt/user/* || ! -d $r ]] && r=$(ls -d /mnt/*/domains 2>/dev/null | grep -vE '^/mnt/(user|user0)/' | head -1)
    [[ -d $r ]] && printf '%s' "$r"
}
# _st_vm_folder <dir> — made like Unraid's VM manager: a dataset of its own when the parent is a ZFS dataset
_st_vm_folder() {
    local dir=$1 parent ds
    [[ -d $dir ]] && return 0
    parent=$(dirname "$dir")
    if [[ $(findmnt -no FSTYPE --target "$parent") == zfs ]] && ds=$(zfs list -H -o name "$parent" 2>/dev/null) \
        && [[ $(zfs get -H -o value mountpoint "$ds") == "$parent" ]]; then
        zfs create "$ds/$(basename "$dir")" || return 1
    else
        mkdir -p "$dir" || return 1
    fi
    chown nobody:users "$dir"
}
_st_vm_folder_remove() {
    local dir=$1 ds
    [[ -d $dir && $(basename "$dir") == "$ST_VM_PREFIX"* ]] || return 0
    if [[ $(findmnt -no FSTYPE --target "$dir") == zfs ]] && ds=$(zfs list -H -o name "$dir" 2>/dev/null) \
        && [[ $(zfs get -H -o value mountpoint "$ds") == "$dir" ]]; then
        zfs destroy -r "$ds" || return 1
    else
        rm -rf -- "$dir" || return 1
    fi
}

# _st_vm_xml <name> <mem MiB> <disk xml> [agent] [net] [extra devices]
_st_vm_xml() {
    cat <<EOF
<domain type='kvm'>
  <name>$1</name>
  <metadata><vmtemplate xmlns='http://unraid' name='Linux' icon='linux.png' os='linux' storage='default'/></metadata>
  <memory unit='MiB'>$2</memory>
  <currentMemory unit='MiB'>$2</currentMemory>
  <vcpu placement='static'>1</vcpu>
  <os><type arch='x86_64' machine='pc'>hvm</type><boot dev='hd'/></os>
  <features><acpi/><apic/></features>
  <cpu mode='host-passthrough'/>
  <clock offset='utc'/>
  <on_poweroff>destroy</on_poweroff><on_reboot>restart</on_reboot><on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    $3
$( [[ ${5:-} == net ]] && echo "    <interface type='network'><source network='default'/><model type='virtio'/></interface>")
$( [[ ${4:-} == agent ]] && echo "    <channel type='unix'><target type='virtio' name='org.qemu.guest_agent.0'/></channel>")
    <serial type='pty'><target port='0'/></serial>
    <console type='pty'><target type='serial' port='0'/></console>
${6:-}
  </devices>
</domain>
EOF
}
_st_vm_file_disk() { echo "<disk type='file' device='disk'><driver name='qemu' type='qcow2' cache='writeback'/><source file='$1'/><target dev='hdc' bus='virtio'/></disk>"; }

vm_make() {
    local kind=$1 vm root dir disk xml extra='' agent='' net='' mem=128 loop f
    vm=$(_st_vm_name "$kind")
    _st_vm_up || { say "needs vm: the VM service doesn't answer"; return 2; }
    if _st_vm_there "$vm"; then say "vm_make: $vm is there"; return 0; fi
    case $kind in
        plain|agent|block|hostdev)
            root=$(_st_vm_root) || { say "vm_make: no domains share"; return 1; }
            dir=$root/$vm ;;
        xfs)
            share_use VM-XFS "$ST_VM_XFS_SHARE" no >/dev/null || return 1
            share_apply "$ST_VM_XFS_SHARE" shareInclude=disk1 >/dev/null || return 1
            [[ $(findmnt -no FSTYPE /mnt/disk1) == xfs ]] || { say "needs an xfs disk1 (Tower's): not on this server"; return 2; }
            _st_root disk1 "$ST_VM_XFS_SHARE" || return 1
            dir=/mnt/disk1/$ST_VM_XFS_SHARE/$vm ;;
        *) say "vm_make: plain|agent|xfs|block|hostdev, not $kind"; return 1 ;;
    esac
    _st_vm_folder "$dir" || { say "vm_make: no folder $dir"; return 1; }
    disk=/mnt/user/${dir#/mnt/*/}/vdisk1.img          # the path Unraid writes into the XML
    [[ $kind == xfs ]] && disk=$dir/vdisk1.img         # … except where the share must not move it: the xfs disk itself
    case $kind in
        agent)
            f=$(bash "$SC_DIR/vm-alpine.sh") || { say "vm_make: vm-alpine.sh failed"; return 1; }
            cp --sparse=always "$f" "$dir/vdisk1.img" || return 1
            agent=agent net=net mem=256 ;;
        block)
            qemu-img create -q -f raw "$dir/block.img" 256M || return 1
            loop=$(losetup -f --show "$dir/block.img") || { say "vm_make: no loop device"; return 1; }
            printf '%s\n' "$loop" > "$dir/.uso-loop"
            xml="<disk type='block' device='disk'><driver name='qemu' type='raw' cache='none'/><source dev='$loop'/><target dev='hdc' bus='virtio'/></disk>" ;;
        hostdev)
            qemu-img create -q -f qcow2 "$dir/vdisk1.img" 1G || return 1
            extra="    <hostdev mode='subsystem' type='pci' managed='yes'><source><address domain='0x0000' bus='0x7f' slot='0x1f' function='0x7'/></source></hostdev>" ;;
        *)
            qemu-img create -q -f qcow2 "$dir/vdisk1.img" 1G || return 1 ;;
    esac
    chown nobody:users "$dir"/* 2>/dev/null
    _st_vm_xml "$vm" "$mem" "${xml:-$(_st_vm_file_disk "$disk")}" "$agent" "$net" "$extra" > "$dir/.uso-define.xml"
    if ! virsh define "$dir/.uso-define.xml" >/dev/null 2>"$dir/.uso-define.err"; then
        say "vm_make: libvirt refused $vm: $(head -c 200 "$dir/.uso-define.err")"
        [[ $kind == hostdev ]] && { vm_remove hostdev; say "needs a PCI device to pass through: not on this server"; return 2; }
        return 1
    fi
    rm -f "$dir/.uso-define.xml" "$dir/.uso-define.err"
    say "vm_make: $vm defined (shut off) — disk $(virsh domblklist "$vm" --details | awk '$2 == "disk" {print $4}')"
}

vm_remove() {
    local kind=$1 vm dir loop
    vm=$(_st_vm_name "$kind")
    case $kind in xfs|block|hostdev) ;; *) say "vm_remove: only xfs|block|hostdev (plain/agent are the base)"; return 1 ;; esac
    if _st_vm_there "$vm"; then
        [[ $(_st_vm_state "$vm") == "shut off" ]] || virsh destroy "$vm" >/dev/null 2>&1
        virsh undefine "$vm" --nvram >/dev/null 2>&1 || virsh undefine "$vm" >/dev/null || return 1
    fi
    if [[ $kind == xfs ]]; then
        dir=/mnt/disk1/$ST_VM_XFS_SHARE/$vm
        _st_vm_folder_remove "$dir" || return 1
        share_unuse VM-XFS || return 1
    else
        dir=$(_st_vm_root)/$vm
        if [[ -f $dir/.uso-loop ]]; then loop=$(cat "$dir/.uso-loop"); losetup -d "$loop" 2>/dev/null; fi
        _st_vm_folder_remove "$dir" || return 1
    fi
    say "vm_remove: $vm gone"
}

vm_start() {
    local vm; vm=$(_st_vm_name "$1")
    case $(_st_vm_state "$vm") in
        running) return 0 ;;
        paused) virsh resume "$vm" >/dev/null || return 1 ;;
        *)
            (( $(_st_vm_running_n) < 2 )) || { say "vm_start: two VMs run already (Tower's RAM: at most two)"; return 1; }
            virsh start "$vm" >/dev/null || return 1 ;;
    esac
    _st_until 30 _st_is running _st_vm_state "$vm" || { say "vm_start: $vm is $(_st_vm_state "$vm")"; return 1; }
}
vm_shutdown() {
    local vm; vm=$(_st_vm_name "$1")
    [[ $(_st_vm_state "$vm") == "shut off" ]] && return 0
    [[ $(_st_vm_state "$vm") == paused ]] && virsh resume "$vm" >/dev/null 2>&1
    # no guest agent channel = no OS that answers (uso-vm-plain): libvirt's «force stop», as a user would
    virsh dumpxml "$vm" 2>/dev/null | grep -q org.qemu.guest_agent.0 || { vm_destroy "$vm"; return; }
    # a VM still booting ignores ACPI: its agent first (≤ 60 s), then the shutdown through it
    _st_until 60 _st_vm_agent_ok "$vm"
    if _st_vm_agent_ok "$vm"; then virsh shutdown "$vm" --mode agent >/dev/null 2>&1
    else virsh shutdown "$vm" >/dev/null 2>&1; fi
    _st_until 60 _st_is "shut off" _st_vm_state "$vm" && return 0
    say "vm_shutdown: $vm didn't shut down in 60 s — destroyed"
    vm_destroy "$vm"
}
vm_destroy() {
    local vm; vm=$(_st_vm_name "$1")
    [[ $(_st_vm_state "$vm") == "shut off" ]] && return 0
    virsh destroy "$vm" >/dev/null 2>&1
    _st_until 20 _st_is "shut off" _st_vm_state "$vm"
}
vm_suspend() {
    local vm; vm=$(_st_vm_name "$1")
    vm_start "$vm" || return 1
    virsh suspend "$vm" >/dev/null || return 1
    _st_until 10 _st_is paused _st_vm_state "$vm"
}
vm_resume() { local vm; vm=$(_st_vm_name "$1"); virsh resume "$vm" >/dev/null && _st_until 10 _st_is running _st_vm_state "$vm"; }

vm_note() {
    local dir vm; vm=$(_st_vm_name "$2"); dir=$(_st_dir "$1")
    [[ -f $dir/$vm.state ]] && return 0                       # the first note counts (a state may note twice)
    _st_vm_state "$vm" > "$dir/$vm.state" || { rm -f "$dir/$vm.state"; return 1; }
}
vm_back() {
    local dir vm want; vm=$(_st_vm_name "$2"); dir=$(_st_dir "$1")
    [[ -f $dir/$vm.state ]] || return 0
    want=$(cat "$dir/$vm.state")
    case $want in
        running) vm_start "$vm" ;;
        paused) vm_suspend "$vm" ;;
        *) vm_shutdown "$vm" ;;
    esac || { say "vm_back: $vm not back to «$want» (it is $(_st_vm_state "$vm"))"; return 1; }
    rm -f "$dir/$vm.state"
}

vm_undefine() {
    local dir vm; vm=$(_st_vm_name "$2"); dir=$(_st_dir "$1")
    _st_vm_there "$vm" || { say "vm_undefine: $vm isn't defined"; return 1; }
    vm_destroy "$vm" || return 1
    virsh dumpxml --inactive "$vm" > "$dir/$vm.xml" || return 1
    virsh dominfo "$vm" | awk '/^Autostart:/ {print $2}' > "$dir/$vm.autostart"
    virsh undefine "$vm" --nvram >/dev/null 2>&1 || virsh undefine "$vm" >/dev/null || return 1
    say "vm_undefine: $vm gone from libvirt, its folder stays"
}
vm_define_back() {
    local dir vm; vm=$(_st_vm_name "$2"); dir=$(_st_dir "$1")
    [[ -f $dir/$vm.xml ]] || return 0
    _st_vm_there "$vm" || virsh define "$dir/$vm.xml" >/dev/null || return 1
    [[ $(cat "$dir/$vm.autostart" 2>/dev/null) == enable ]] && virsh autostart "$vm" >/dev/null
    rm -f "$dir/$vm.xml" "$dir/$vm.autostart"
}

_st_vm_disk1() { virsh domblklist "$1" --details 2>/dev/null | awk '$2 == "disk" {print $4; exit}'; }
vm_disk_hide() {
    local dir vm f size; vm=$(_st_vm_name "$2"); dir=$(_st_dir "$1")
    f=$(_st_vm_disk1 "$vm"); [[ -f $f ]] || { say "vm_disk_hide: $vm has no disk file ($f)"; return 1; }
    size=$(du -m "$f" | cut -f1)
    ((size <= 512)) || { say "vm_disk_hide: $f takes $size MiB — too much for the stash"; return 1; }
    [[ $(_st_vm_state "$vm") == "shut off" ]] || { say "vm_disk_hide: $vm must be shut off"; return 1; }
    printf '%s\n' "$f" > "$dir/disk.path"
    mv -- "$f" "$dir/disk.img" || return 1
    say "vm_disk_hide: $f moved away ($vm points at a missing disk)"
}
vm_disk_back() {
    local dir f; dir=$(_st_dir "$1")
    [[ -f $dir/disk.path ]] || return 0
    f=$(cat "$dir/disk.path")
    [[ -e $f ]] && { say "vm_disk_back: $f is there again (left as it is, the stash's copy dropped)"; rm -f "$dir/disk.img" "$dir/disk.path"; return 0; }
    mv -- "$dir/disk.img" "$f" && rm -f "$dir/disk.path"
}

# ------------------------------------------------------------------------------------------------------------ states
# every VM state: the VM service there, its VM made when missing (plain/agent stay — the base), its state noted first

_st_vm_need() { _st_need "the VM service" _st_vm_up || return; vm_make "$1"; }

state_vm_running_agent() {
    local vm=uso-vm-agent
    _st_vm_need agent || return
    vm_note VM-RUNNING-AGENT "$vm" && vm_start "$vm" || return 1
    _st_until 120 _st_vm_agent_ok "$vm" || { say "VM-RUNNING-AGENT: the guest agent didn't answer in 2 min"; return 1; }
    _st_keep_env VM-RUNNING-AGENT VM=$vm VM_AGENT=yes
    say "VM-RUNNING-AGENT: $vm runs, its guest agent answers"
}
undo_vm_running_agent() { vm_back VM-RUNNING-AGENT uso-vm-agent; }

state_vm_running_noagent() {
    local vm=uso-vm-plain
    _st_vm_need plain || return
    vm_note VM-RUNNING-NOAGENT "$vm" && vm_start "$vm" || return 1
    _st_keep_env VM-RUNNING-NOAGENT VM=$vm VM_AGENT=no
}
undo_vm_running_noagent() { vm_back VM-RUNNING-NOAGENT uso-vm-plain; }

# VM-SHUTOFF [plain|agent] [shutdown|destroy] — off (it is in the base already: then it was started and shut down, so the
# server saw a real stop)
state_vm_shutoff() {
    local vm; vm=$(_st_vm_name "${1:-plain}")
    _st_vm_need "${vm#"$ST_VM_PREFIX"}" || return
    vm_note VM-SHUTOFF "$vm" || return 1
    [[ $(_st_vm_state "$vm") == "shut off" ]] && { vm_start "$vm" || return 1; sleep 3; }
    if [[ ${2:-shutdown} == destroy ]]; then vm_destroy "$vm"; else vm_shutdown "$vm"; fi || return 1
    _st_keep_env VM-SHUTOFF VM=$vm HOW=${2:-shutdown}
}
undo_vm_shutoff() { vm_back VM-SHUTOFF "$(_st_vm_name "${1:-plain}")"; }

# VM-PAUSED-BY-USER [plain|agent] — the user paused it (virsh suspend, as Unraid's ⟦Pause⟧): a run must never resume it
state_vm_paused_by_user() {
    local vm; vm=$(_st_vm_name "${1:-plain}")
    _st_vm_need "${vm#"$ST_VM_PREFIX"}" || return
    vm_note VM-PAUSED-BY-USER "$vm" && vm_suspend "$vm" || return 1
    _st_keep_env VM-PAUSED-BY-USER VM=$vm VM_PAUSED=$vm
}
undo_vm_paused_by_user() { vm_back VM-PAUSED-BY-USER "$(_st_vm_name "${1:-plain}")"; }

# VM-GONE [plain] — libvirt forgot it (undefine --nvram), its folder and disk stay
state_vm_gone() {
    local vm; vm=$(_st_vm_name "${1:-plain}")
    _st_vm_need "${vm#"$ST_VM_PREFIX"}" || return
    vm_note VM-GONE "$vm" && vm_undefine VM-GONE "$vm" || return 1
    _st_keep_env VM-GONE VM=$vm VM_FOLDER="$(_st_vm_root)/$vm"
}
undo_vm_gone() { local vm; vm=$(_st_vm_name "${1:-plain}"); vm_define_back VM-GONE "$vm" && vm_back VM-GONE "$vm"; }

# VM-DISK-MISSING [plain] — the XML names a disk file that isn't there
state_vm_disk_missing() {
    local vm; vm=$(_st_vm_name "${1:-plain}")
    _st_vm_need "${vm#"$ST_VM_PREFIX"}" || return
    vm_note VM-DISK-MISSING "$vm" && vm_destroy "$vm" && vm_disk_hide VM-DISK-MISSING "$vm" || return 1
    _st_keep_env VM-DISK-MISSING VM=$vm DISK="$(cat "$(_st_dir VM-DISK-MISSING)/disk.path")"
}
undo_vm_disk_missing() { local vm; vm=$(_st_vm_name "${1:-plain}"); vm_disk_back VM-DISK-MISSING && vm_back VM-DISK-MISSING "$vm"; }

# the VMs only a state makes: made, noted for the undo, removed by it
_st_vm_own() { # <id> <kind>
    local dir; dir=$(_st_dir "$1")
    _st_need "the VM service" _st_vm_up || return
    if ! _st_vm_there "$(_st_vm_name "$2")"; then
        printf 'made\n' > "$dir/vm.made"
        vm_make "$2" || return
    fi
}
_st_vm_unown() { local dir; dir=$(_st_dir "$1"); [[ -f $dir/vm.made ]] || return 0; vm_remove "$2" && rm -f "$dir/vm.made"; }

state_vm_disk_on_xfs() {
    _st_vm_own VM-DISK-ON-XFS xfs || return
    _st_keep_env VM-DISK-ON-XFS VM=uso-vm-xfs SHARE=$ST_VM_XFS_SHARE DISK="$(_st_vm_disk1 uso-vm-xfs)" FS=xfs
}
undo_vm_disk_on_xfs() { _st_vm_unown VM-DISK-ON-XFS xfs; }
state_vm_disk_block() {
    _st_vm_own VM-DISK-BLOCK block || return
    _st_keep_env VM-DISK-BLOCK VM=uso-vm-block DEV="$(_st_vm_disk1 uso-vm-block)"
}
undo_vm_disk_block() { _st_vm_unown VM-DISK-BLOCK block; }
state_vm_hostdev() {
    _st_vm_own VM-HOSTDEV hostdev || return
    _st_keep_env VM-HOSTDEV VM=uso-vm-hostdev HOSTDEV=1
}
undo_vm_hostdev() { _st_vm_unown VM-HOSTDEV hostdev; }

# VM-SERVICE-OFF [cfg] = SV-VM-OFF (states-services.sh)
state_vm_service_off() { state_sv_vm_off "$@"; }
undo_vm_service_off() { undo_sv_vm_off "$@"; }

# VM-MANY-SNAPSHOTS [n=3] [plain] — Unraid's own VM snapshots (its VM page's vm_snapshot(): external qcow2 overlays
# vdisk1.<name>qcow2, libvirt's snapshot list, Unraid's snapshotdb/<vm>/snapshots.db), the VM shut off. Undo: the XML as
# it was, libvirt's snapshot metadata dropped, the overlays and Unraid's db entry gone (the base disk was never written).
state_vm_many_snapshots() {
    local n=${1:-3} vm i out dir; vm=$(_st_vm_name "${2:-plain}")
    _st_vm_need "${vm#"$ST_VM_PREFIX"}" || return
    [[ -f $ST_VM_HELPERS ]] || { say "needs Unraid's VM manager ($ST_VM_HELPERS): not on this server"; return 2; }
    dir=$(_st_dir VM-MANY-SNAPSHOTS)
    vm_note VM-MANY-SNAPSHOTS "$vm" && vm_destroy "$vm" || return 1
    virsh dumpxml --inactive "$vm" > "$dir/$vm.xml" || return 1
    [[ -d /etc/libvirt/qemu/snapshotdb/$vm ]] || printf 'new\n' > "$dir/snapshotdb.new"
    for ((i = 1; i <= n; i++)); do
        out=$(cd /usr/local/emhttp && php -r '
            $docroot = "/usr/local/emhttp"; require_once "$docroot/webGui/include/Helpers.php"; require_once "'"$ST_VM_HELPERS"'";
            echo json_encode(vm_snapshot($argv[1], $argv[2], "uso scenario", "no", "QEMU", "no"));' "$vm" "uso-snap$i" 2>&1 | tail -1)
        printf 'uso-snap%s\n' "$i" >> "$dir/snaps"
        [[ $out == *success* ]] || { say "VM-MANY-SNAPSHOTS: Unraid's vm_snapshot refused uso-snap$i: ${out:0:200}"; return 1; }
    done
    _st_keep_env VM-MANY-SNAPSHOTS VM=$vm SNAPSHOTS=$n OVERLAY="$(_st_vm_disk1 "$vm")"
    say "VM-MANY-SNAPSHOTS: $n snapshots of $vm, its disk now the overlay $(_st_vm_disk1 "$vm")"
}
undo_vm_many_snapshots() {
    local vm dir s f base; vm=$(_st_vm_name "${2:-plain}"); dir=$(_st_dir VM-MANY-SNAPSHOTS)
    [[ -f $dir/$vm.xml ]] || return 0
    vm_destroy "$vm"
    base=$(grep -o "<source file='[^']*'" "$dir/$vm.xml" | head -1 | cut -d"'" -f2)
    while read -r s; do
        [[ -z $s ]] && continue
        virsh snapshot-delete "$vm" "$s" --metadata >/dev/null 2>&1
        for f in "${base%.*}.${s}qcow2" "$(dirname "$base")/$s.running" "$(dirname "$base")/memory$s.mem"; do rm -f -- "$f"; done
    done < "$dir/snaps"
    virsh define "$dir/$vm.xml" >/dev/null || return 1
    if [[ -f $dir/snapshotdb.new ]]; then rm -rf -- "/etc/libvirt/qemu/snapshotdb/$vm"
    else
        (cd /usr/local/emhttp && php -r '$docroot = "/usr/local/emhttp"; require_once "$docroot/webGui/include/Helpers.php"; require_once "'"$ST_VM_HELPERS"'";
            foreach (array_slice($argv, 2) as $s) delete_snapshots_database($argv[1], $s);' "$vm" $(cat "$dir/snaps")) >/dev/null 2>&1
    fi
    rm -f "$dir/$vm.xml" "$dir/snaps" "$dir/snapshotdb.new"
    vm_back VM-MANY-SNAPSHOTS "$vm"
}
