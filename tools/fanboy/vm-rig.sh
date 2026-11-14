#!/bin/bash
# tools/fanboy/vm-rig.sh — gives the throw-away VM test3 a fan chip that isn't there, for Fanboy's whole run with the
# real, installed agent and its real processes (the agent, the control's `at`-free setsid job, the cron guard, a shell).
# Dev only, never in the package. Runs ON test3, as root, from a copy of the tree.
#
# What it does (host mount namespace — every process sees it; a reboot removes it all by itself):
#   /sys/class/hwmon                         a tmpfs: the VM's real entries (links to nvme0's and nvme1's real hwmon
#                                            folders) plus the fake chips' links, numbered as a «boot» would
#   /sys/devices/platform/pcspkr             a tmpfs: the fan chip's device (ITE IT8696E as nostromo's: it8696_a0060607,
#                                            6 outputs pwm1–6 with pwmN_enable and auto files, 6 tach inputs, 3 board
#                                            temperatures; driver → module «it87»). The PC speaker's folder only gives
#                                            the address its name («pcspkr» where nostromo says «it87.2624»)
#   /sys/devices/platform/intel_rapl_msr.0   a tmpfs: a «coretemp» chip (Package id 0, Core 0, Core 1) — the VM has none
#   …/0000:00:11.0/nvme/nvme1/hwmon1         a tmpfs over the REAL NVMe's hwmon folder: same name, label and limits,
#                                            a temperature the knobs drive (nvme0 stays real and untouched)
#   a responder (vm-responder.php)           makes the fans answer the values written (lag, stall, start value, a
#                                            splitter, an output without tach, an empty connector, a fan that stands)
#                                            and writes the temperatures the knobs say
#
#   bash vm-rig.sh up [--layout a|b] [--addr pcspkr|serial8250]    build and mount (refuses on a host with a real pwm)
#   bash vm-rig.sh down                                            responder ended, everything unmounted, nothing left
#   bash vm-rig.sh status                                          mounts, responder, every output and fan, the knobs
#   bash vm-rig.sh knob <key> <value>                              e.g. knob cpu 70 · knob board1 45 · knob nvme 55 ·
#                                                                  knob stand4 1 · knob quiet_nvme 1 (a sensor says nothing)
#   bash vm-rig.sh chip gone|back [--layout a|b] [--addr …]        the driver unloaded / loaded again (registers kept)
#   bash vm-rig.sh other on|off                                    a process that looks like FanCtrl Plus's loop
#   bash vm-rig.sh foreign <n> <value> [enable]                    someone else writes pwmN (or pwmN_enable)
#   bash vm-rig.sh watch <seconds> [every]                         one line per sample: t, every pwm/enable/rpm
#
# Not real (said in the notes): the files are tmpfs files, not sysfs attributes — a write of a value the chip would
# refuse (EINVAL) lands and is put back by the responder a moment later; reading never fails with EIO; the chip's
# automatic mode drives the rpm from the board temperature but leaves the pwm file as it was.
set -uo pipefail

RIG=/tmp/uso-fanboy-rig
HERE=$(cd "$(dirname "$0")" && pwd -P)
HW=/sys/class/hwmon
CHIP_NAME=it8696_a0060607
CPU_DEV=/sys/devices/platform/intel_rapl_msr.0
NVME_REAL=/sys/devices/pci0000:00/0000:00:11.0/nvme/nvme1/hwmon1
TEST3_UUID=89b3d774-ddbb-4fbd-84b1-49b1f8647350      # test3's VM (QEMU product_uuid) — the real Tower is another one

say() { printf '%s rig: %s\n' "$(date +%H:%M:%S.%3N)" "$*"; }
die() { say "$*"; exit 1; }

guard_host() {
    [[ $(id -u) == 0 ]] || die "needs root"
    [[ $(cat /sys/class/dmi/id/product_uuid 2>/dev/null) == "$TEST3_UUID" ]] || die "not test3 (product_uuid differs) — refused"
    [[ $(hostname) == Tower && -f /etc/unraid-version ]] || die "not test3's Unraid — refused"
}
mounted() { mountpoint -q "$1"; }
# a real fan output anywhere (outside the rig's own mounts) — then this is real hardware: refused
real_pwm() {
    local f
    for f in /sys/devices/platform/*/hwmon/hwmon*/pwm[0-9] /sys/devices/platform/*/pwm[0-9] /sys/devices/pci*/*/hwmon/hwmon*/pwm[0-9] \
             /sys/devices/pci*/*/*/hwmon/hwmon*/pwm[0-9]; do
        [[ -e $f ]] || continue
        case $f in /sys/devices/platform/pcspkr/*|/sys/devices/platform/serial8250/*|$CPU_DEV/*) mounted "${f%%/hwmon/*}" && continue ;; esac
        echo "$f"; return 0
    done
    return 1
}

layout() {   # a|b → «it87 hwmon number» «coretemp hwmon number»
    case ${1:-a} in a) echo "3 2" ;; b) echo "7 4" ;; *) die "layout a|b" ;; esac
}

put() { printf '%s\n' "$2" > "$1"; }

# the fan chip's device folder (tmpfs at /sys/devices/platform/<addr>); $3 = state file of the registers to restore
build_chip() {
    local addr=$1 n=$2 regs=${3:-} dev=/sys/devices/platform/$1 h i
    mount -t tmpfs -o size=1m,mode=755 rig-chip "$dev" || die "mount $dev"
    mkdir -p "$dev/_rig/drivers/it87" "$dev/_rig/module/it87" "$dev/hwmon/hwmon$n"
    ln -s ../../module/it87 "$dev/_rig/drivers/it87/module"
    ln -s _rig/drivers/it87 "$dev/driver"
    put "$dev/modalias" "platform:it87"
    put "$dev/uevent" "DRIVER=it87"
    h=$dev/hwmon/hwmon$n
    ln -s ../.. "$h/device"
    put "$h/name" "$CHIP_NAME"
    for i in 1 2 3 4 5 6; do
        # the BIOS at power-on: every output on the chip's own curve (2) — pwm3 a fixed 128 (as another plugin leaves it)
        if [[ $i == 3 ]]; then put "$h/pwm$i" 128; put "$h/pwm${i}_enable" 1; else put "$h/pwm$i" 128; put "$h/pwm${i}_enable" 2; fi
        put "$h/pwm${i}_auto_channels_temp" 1
        put "$h/pwm${i}_auto_point1_temp" 0
        put "$h/pwm${i}_auto_point2_temp" 20000
        put "$h/pwm${i}_auto_point3_temp" 60000
        put "$h/pwm${i}_auto_slope" 5
        put "$h/pwm${i}_freq" 23437
        put "$h/fan${i}_input" 0
        put "$h/fan${i}_min" 0
    done
    for i in 1 2 3; do put "$h/temp${i}_input" 35000; put "$h/temp${i}_max" 127000; put "$h/temp${i}_type" 4; done
    if [[ -n $regs && -f $regs ]]; then         # the driver loaded again: the chip's registers are what they were
        while read -r f v; do [[ -e $h/$f ]] && put "$h/$f" "$v"; done < "$regs"
    fi
    chmod 644 "$h"/*_input "$h"/pwm* "$h/name" 2>/dev/null
}
build_cpu() {
    local n=$1 h
    mount -t tmpfs -o size=1m,mode=755 rig-cpu "$CPU_DEV" || die "mount $CPU_DEV"
    mkdir -p "$CPU_DEV/_rig/drivers/coretemp" "$CPU_DEV/_rig/module/coretemp" "$CPU_DEV/hwmon/hwmon$n"
    ln -s ../../module/coretemp "$CPU_DEV/_rig/drivers/coretemp/module"
    ln -s _rig/drivers/coretemp "$CPU_DEV/driver"
    h=$CPU_DEV/hwmon/hwmon$n
    ln -s ../.. "$h/device"
    put "$h/name" coretemp
    put "$h/temp1_label" "Package id 0"; put "$h/temp2_label" "Core 0"; put "$h/temp3_label" "Core 1"
    for i in 1 2 3; do put "$h/temp${i}_input" 45000; put "$h/temp${i}_max" 100000; put "$h/temp${i}_crit" 100000; done
}
build_nvme() {
    local tmp f
    tmp=$(mktemp -d)
    for f in name temp1_label temp1_max temp1_min temp1_crit temp1_alarm temp1_input; do cat "$NVME_REAL/$f" > "$tmp/$f" 2>/dev/null; done
    mount -t tmpfs -o size=1m,mode=755 rig-nvme "$NVME_REAL" || die "mount $NVME_REAL"
    cp "$tmp"/* "$NVME_REAL/"
    rm -rf "$tmp"
    ln -s ../../nvme1 "$NVME_REAL/device"
    : > "$NVME_REAL/.rig"
}
build_class() {   # <it87 n|-> <addr> <cpu n>
    local link
    # strictatime: every look into the folder leaves its time (the control's retry rhythm, read by vm-run.sh)
    mount -t tmpfs -o size=1m,mode=755,strictatime rig-hwmon "$HW" || die "mount $HW"
    ln -s ../../devices/pci0000:00/0000:00:10.0/nvme/nvme0/hwmon0 "$HW/hwmon0"
    ln -s ../../devices/pci0000:00/0000:00:11.0/nvme/nvme1/hwmon1 "$HW/hwmon1"
    ln -s "../../devices/platform/intel_rapl_msr.0/hwmon/hwmon$3" "$HW/hwmon$3"
    [[ $1 != - ]] && ln -s "../../devices/platform/$2/hwmon/hwmon$1" "$HW/hwmon$1"
    for link in "$HW"/hwmon*; do [[ -d $link/ ]] || die "$link points nowhere"; done
}

responder_start() {
    pgrep -f 'vm-responder.php' >/dev/null && return 0
    [[ -f $RIG/knobs.json ]] || printf '{"cpu":45,"board1":35,"board2":33,"board3":38,"nvme":42}\n' > "$RIG/knobs.json"
    (cd / && exec setsid php "$HERE/vm-responder.php" "$RIG" </dev/null >>"$RIG/responder.log" 2>&1) &
    sleep 0.5
    pgrep -f 'vm-responder.php' > "$RIG/responder.pid" || die "the responder didn't start (see $RIG/responder.log)"
}
responder_stop() {
    pkill -f 'vm-responder.php' 2>/dev/null
    local i; for i in $(seq 20); do pgrep -f 'vm-responder.php' >/dev/null || break; sleep 0.1; done
    rm -f "$RIG/responder.pid"
}

chip_dir() { local a; a=$(jq -r '.addr // "pcspkr"' "$RIG/layout.json" 2>/dev/null); echo "/sys/devices/platform/$a"; }

cmd_up() {
    local lay=a addr=pcspkr
    while (($#)); do case $1 in --layout) lay=$2; shift 2 ;; --addr) addr=$2; shift 2 ;; *) die "up: $1?" ;; esac; done
    [[ $addr == pcspkr || $addr == serial8250 ]] || die "--addr pcspkr|serial8250"
    guard_host
    mounted "$HW" && die "already up (bash $0 status)"
    if f=$(real_pwm); then die "this host has a real fan output ($f) — refused"; fi
    read -r n cpu < <(layout "$lay")
    mkdir -p "$RIG"
    build_chip "$addr" "$n" "${RIG_REGS:-}"
    build_cpu "$cpu"
    build_nvme
    build_class "$n" "$addr" "$cpu"
    printf '{"layout":"%s","addr":"%s","it87":%s,"cpu":%s,"chip":"there","up":"%s"}\n' "$lay" "$addr" "$n" "$cpu" "$(date -Is)" > "$RIG/layout.json"
    responder_start
    say "up: layout $lay — $CHIP_NAME at platform/$addr as hwmon$n, coretemp hwmon$cpu, nvme1 hwmon1 driven; responder $(cat "$RIG/responder.pid")"
}

cmd_down() {
    guard_host
    responder_stop
    pkill -f 'fanctrlplus/scripts/fanctrlplus_loop' 2>/dev/null
    local m rc=0
    for m in "$HW" /sys/devices/platform/pcspkr /sys/devices/platform/serial8250 "$CPU_DEV" "$NVME_REAL"; do
        while mounted "$m"; do umount "$m" 2>/dev/null || { say "busy: $m — $(fuser -vm "$m" 2>&1 | tail -n +2 | head -3)"; umount -l "$m" || rc=1; }; done
    done
    rm -f "$RIG/layout.json"
    mount | grep -E '^rig-' && { say "down: something is still mounted"; return 1; }
    say "down: nothing of the rig mounted, no responder"
    return $rc
}

regs_save() {   # the chip's registers (pwm, enable) into a file, for a «driver loaded again»
    local h; h=$(chip_dir)/hwmon
    h=$(echo "$h"/hwmon*)
    : > "$RIG/regs.txt"
    for f in "$h"/pwm[1-6] "$h"/pwm[1-6]_enable; do printf '%s %s\n' "${f##*/}" "$(cat "$f")" >> "$RIG/regs.txt"; done
}

cmd_chip() {
    guard_host
    local what=${1:-}; shift || true
    case $what in
    gone)
        local dir n
        dir=$(chip_dir); n=$(jq -r .it87 "$RIG/layout.json")
        regs_save
        rm -f "$HW/hwmon$n"
        umount "$dir" 2>/dev/null || umount -l "$dir"
        jq '.chip = "gone"' "$RIG/layout.json" > "$RIG/l.tmp" && mv "$RIG/l.tmp" "$RIG/layout.json"
        say "chip gone: hwmon$n and $dir/hwmon removed (registers kept in $RIG/regs.txt: $(tr '\n' ' ' < "$RIG/regs.txt"))"
        ;;
    back)
        local lay addr n cpu
        lay=$(jq -r .layout "$RIG/layout.json"); addr=$(jq -r .addr "$RIG/layout.json")
        while (($#)); do case $1 in --layout) lay=$2; shift 2 ;; --addr) addr=$2; shift 2 ;; *) die "chip back: $1?" ;; esac; done
        read -r n cpu < <(layout "$lay")
        [[ $(jq -r .chip "$RIG/layout.json") == gone ]] || die "the chip is there"
        build_chip "$addr" "$n" "$RIG/regs.txt"
        ln -s "../../devices/platform/$addr/hwmon/hwmon$n" "$HW/hwmon$n"
        jq --arg a "$addr" --argjson n "$n" '.chip = "there" | .addr = $a | .it87 = $n' "$RIG/layout.json" > "$RIG/l.tmp" && mv "$RIG/l.tmp" "$RIG/layout.json"
        say "chip back: platform/$addr as hwmon$n, registers as before"
        ;;
    *) die "chip gone|back" ;;
    esac
}

cmd_knob() {
    [[ $# == 2 ]] || die "knob <key> <value>"
    local k=$1 v=$2
    [[ $k =~ ^(cpu|board[123]|nvme|stand[1-6]|scale[1-6]|quiet_(cpu|board[123]|nvme))$ && $v =~ ^-?[0-9]+(\.[0-9]+)?$ ]] || die "knob: $k $v?"
    jq --arg k "$k" --argjson v "$v" '.[$k] = $v' "$RIG/knobs.json" > "$RIG/k.tmp" && mv "$RIG/k.tmp" "$RIG/knobs.json"
    say "knob $k = $v"
}

cmd_other() {
    case ${1:-} in
    on)
        pgrep -f 'fanctrlplus/scripts/fanctrlplus_loop' >/dev/null && return 0
        # a process whose command line is FanCtrl Plus's loop (Fanboy knows it by /fanctrlplus(?!2)/) — no plugin installed
        (cd / && exec setsid bash -c 'exec -a /usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh sleep 86400' </dev/null >/dev/null 2>&1) &
        sleep 0.3; say "other on: pid $(pgrep -f 'fanctrlplus/scripts/fanctrlplus_loop')" ;;
    off) pkill -f 'fanctrlplus/scripts/fanctrlplus_loop'; say "other off" ;;
    *) die "other on|off" ;;
    esac
}

cmd_foreign() {
    local n=$1 v=$2 what=${3:-} h
    h=$(echo "$(chip_dir)"/hwmon/hwmon*)
    if [[ $what == enable ]]; then printf '%s\n' "$v" > "$h/pwm${n}_enable"; say "foreign: pwm${n}_enable = $v"
    else printf '%s\n' "$v" > "$h/pwm$n"; say "foreign: pwm$n = $v"; fi
}

sample() {   # one line: time, then per output value/enable/rpm
    local h line i
    h=$(echo "$(chip_dir)"/hwmon/hwmon* 2>/dev/null)
    line=$(date +%s.%2N)
    for i in 1 2 3 4 5 6; do
        line+=" p$i=$(cat "$h/pwm$i" 2>/dev/null || echo -)/$(cat "$h/pwm${i}_enable" 2>/dev/null || echo -)/$(cat "$h/fan${i}_input" 2>/dev/null || echo -)"
    done
    echo "$line"
}
cmd_watch() {
    local secs=${1:-10} every=${2:-0.2} end
    end=$(( $(date +%s%N) + secs * 1000000000 ))
    while (( $(date +%s%N) < end )); do sample; sleep "$every"; done
}

cmd_status() {
    echo "mounts:"; mount | grep -E '^rig-' | sed 's/^/  /' || echo "  none"
    echo "responder: $(pgrep -f vm-responder.php || echo none)"
    echo "other: $(pgrep -f 'fanctrlplus/scripts/fanctrlplus_loop' || echo none)"
    [[ -f $RIG/layout.json ]] && echo "layout: $(cat "$RIG/layout.json")"
    [[ -f $RIG/knobs.json ]] && echo "knobs: $(cat "$RIG/knobs.json")"
    ls -l "$HW" 2>/dev/null | tail -n +2 | sed 's/^/  /'
    mounted "$HW" && sample
}

case ${1:-} in
up) shift; cmd_up "$@" ;;
down) cmd_down ;;
status) cmd_status ;;
knob) shift; cmd_knob "$@" ;;
chip) shift; cmd_chip "$@" ;;
other) shift; cmd_other "$@" ;;
foreign) shift; guard_host; cmd_foreign "$@" ;;
watch) shift; cmd_watch "$@" ;;
sample) sample ;;
*) sed -n '2,32p' "$0"; exit 2 ;;
esac
