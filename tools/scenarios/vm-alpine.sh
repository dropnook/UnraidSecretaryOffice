#!/bin/bash
# tools/scenarios/vm-alpine.sh — the agent VM's disk, made once (H4, resilience wave 1b). On Tower as root.
#
#   bash vm-alpine.sh [--force]     → <data>.base/iso/alpine-agent.qcow2 (printed), cached: a second call only prints it
#
# Alpine's own cloud image (nocloud, BIOS, cloud-init) is downloaded once into <data>.base/iso/ and checked against the
# sha512 pinned here; a throwaway VM `uso-wip-h4-alpine-build` (libvirt's NAT network «default», 512 MiB, transient)
# boots it with a CIDATA seed disk (vfat — Unraid has no genisoimage): cloud-init installs qemu-guest-agent from Alpine's
# repository, enables it, switches cloud-init off for good and powers off. The result is booted once more without the
# seed and must answer the guest agent's ping (then it is shut down through the agent) before it is kept. Nothing of the
# build stays: the transient VM is gone at power-off, its files are under a temp folder that is removed.
# Exit 0 the disk is there (its path on stdout), 1 the build failed (the serial log's tail on stderr), 2 wrong host.
set -u

ALPINE_REL=3.22.6
ALPINE_IMG=alpine-$ALPINE_REL-x86_64-bios-cloudinit-r0.qcow2
ALPINE_URL=https://dl-cdn.alpinelinux.org/alpine/v${ALPINE_REL%.*}/releases/cloud/$ALPINE_IMG
ALPINE_SHA512=864ad8b9e3d14272d592f380c814c647bb6666fcd5b73c86dc21528da71306a93f2ce29b89ea8e6099b76786bb7835f0e27ca1824ec7f4b0b243160a13ba262d
BUILD_VM=uso-wip-h4-alpine-build
CFG=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg

msg() { echo "vm-alpine: $*" >&2; }
[[ $(id -u) == 0 ]] || { msg "runs as root on the test VM"; exit 2; }
[[ -S /var/run/libvirt/libvirt-sock ]] && virsh version >/dev/null 2>&1 || { msg "needs the VM service (libvirt)"; exit 2; }
D=$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CFG" 2>/dev/null | head -1)
[[ $D == /mnt/*/* && $D != *..* ]] || { msg "no data folder in $CFG"; exit 2; }
ISO=${USO_VM_ISO:-${D%/}.base/iso}
OUT=$ISO/alpine-agent.qcow2

if [[ -s $OUT && ${1:-} != --force ]]; then echo "$OUT"; exit 0; fi
mkdir -p "$ISO" || exit 1

# 1. Alpine's image, once
if [[ ! -s $ISO/$ALPINE_IMG ]] || ! sha512sum "$ISO/$ALPINE_IMG" | grep -q "^$ALPINE_SHA512 "; then
    msg "downloading $ALPINE_IMG"
    curl -fsSL --retry 3 -o "$ISO/$ALPINE_IMG.part" "$ALPINE_URL" || { msg "download failed"; rm -f "$ISO/$ALPINE_IMG.part"; exit 1; }
    sha512sum "$ISO/$ALPINE_IMG.part" | grep -q "^$ALPINE_SHA512 " || { msg "sha512 differs - refused"; rm -f "$ISO/$ALPINE_IMG.part"; exit 1; }
    mv "$ISO/$ALPINE_IMG.part" "$ISO/$ALPINE_IMG"
fi

# 2. the build, in a temp folder on the same share (RAM /tmp is small)
W=$ISO/.build-$$
mkdir -p "$W" || exit 1
cleanup() { virsh destroy "$BUILD_VM" >/dev/null 2>&1; rm -rf "$W"; }
trap cleanup EXIT
qemu-img convert -O qcow2 "$ISO/$ALPINE_IMG" "$W/disk.qcow2" && qemu-img resize -q "$W/disk.qcow2" 1G || { msg "qemu-img failed"; exit 1; }
cat > "$W/user-data" <<'EOF'
#cloud-config
hostname: uso-vm-agent
ssh_pwauth: false
package_update: true
packages: [qemu-guest-agent]
runcmd:
  - rc-update add qemu-guest-agent default
  - touch /etc/cloud/cloud-init.disabled
  - sync
  - echo USO-AGENT-READY > /dev/ttyS0
power_state:
  mode: poweroff
  delay: now
  timeout: 30
EOF
printf 'instance-id: uso-vm-agent-build\nlocal-hostname: uso-vm-agent\n' > "$W/meta-data"
truncate -s 4M "$W/seed.img" && mkfs.vfat -n CIDATA "$W/seed.img" >/dev/null \
    && mcopy -i "$W/seed.img" "$W/user-data" "$W/meta-data" :: || { msg "the seed disk failed"; exit 1; }

# vm_xml <name> <disk> <serial log> [seed] — a tiny BIOS VM on the NAT network with the guest agent's channel
vm_xml() {
    cat <<EOF
<domain type='kvm'>
  <name>$1</name>
  <memory unit='MiB'>512</memory>
  <vcpu>1</vcpu>
  <os><type arch='x86_64' machine='pc'>hvm</type><boot dev='hd'/></os>
  <features><acpi/><apic/></features>
  <cpu mode='host-passthrough'/>
  <on_poweroff>destroy</on_poweroff>
  <devices>
    <emulator>/usr/bin/qemu-system-x86_64</emulator>
    <disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='$2'/><target dev='vda' bus='virtio'/></disk>
$( [[ -n ${4:-} ]] && echo "    <disk type='file' device='disk'><driver name='qemu' type='raw'/><source file='$4'/><target dev='vdb' bus='virtio'/><readonly/></disk>")
    <interface type='network'><source network='default'/><model type='virtio'/></interface>
    <serial type='file'><source path='$3'/><target port='0'/></serial>
    <channel type='unix'><target type='virtio' name='org.qemu.guest_agent.0'/></channel>
  </devices>
</domain>
EOF
}
gone() { ! virsh domstate "$BUILD_VM" >/dev/null 2>&1; }
wait_gone() { local i; for ((i = 0; i < $1; i++)); do gone && return 0; sleep 2; done; return 1; }
tail_log() { tail -c 1500 "$1" 2>/dev/null | tr -d '\r' | tail -15 >&2; }

msg "first boot: cloud-init installs qemu-guest-agent (a few minutes)"
vm_xml "$BUILD_VM" "$W/disk.qcow2" "$W/serial1.log" "$W/seed.img" > "$W/vm1.xml"
virsh create "$W/vm1.xml" >/dev/null || { msg "virsh create failed"; exit 1; }
if ! wait_gone 300; then msg "no power-off after 10 min"; tail_log "$W/serial1.log"; exit 1; fi
grep -q USO-AGENT-READY "$W/serial1.log" || { msg "cloud-init did not finish (no marker)"; tail_log "$W/serial1.log"; exit 1; }

msg "second boot: the guest agent must answer"
vm_xml "$BUILD_VM" "$W/disk.qcow2" "$W/serial2.log" > "$W/vm2.xml"
virsh create "$W/vm2.xml" >/dev/null || { msg "virsh create failed"; exit 1; }
ok=0
for ((i = 0; i < 60; i++)); do
    virsh qemu-agent-command "$BUILD_VM" '{"execute":"guest-ping"}' >/dev/null 2>&1 && { ok=1; break; }
    sleep 2
done
((ok)) || { msg "the guest agent did not answer within 2 min"; tail_log "$W/serial2.log"; exit 1; }
virsh shutdown "$BUILD_VM" --mode agent >/dev/null 2>&1
wait_gone 60 || { msg "no shutdown through the agent"; exit 1; }

mv "$W/disk.qcow2" "$OUT.part" && mv "$OUT.part" "$OUT" || exit 1
msg "kept $OUT ($(du -h "$OUT" | cut -f1))"
echo "$OUT"
