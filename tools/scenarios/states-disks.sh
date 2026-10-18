#!/bin/bash
# tools/scenarios/states-disks.sh — disks and pools: sleep, full, degraded (the D states, DK-*) (H2). Sourced by lib.sh.
#
#   disk_sleep <disk|pool>     Unraid's own spin-down (emcmd cmdSpindown=<name>, what its ⟦Spin down⟧ sends); 0 when
#                              disks.ini says spundown="1" within 15 s, 2 when it doesn't (a VM's emulated disk never
#                              sleeps — day-1 finding 3: needs a real rotating disk)
#   disk_wake  <disk|pool>     emcmd cmdSpinup=<name>, waits for spundown="0"
#   disk_asleep <name>         disks.ini says so (spundown=1 and rotating — the office's own rule, diskAsleep())
#   disk_reads <name>          reads done on its device so far (/sys/block/<dev>/stat) — «nothing read it» in a VM
#   pool_fill <id> [pool] [leave MiB] / pool_unfill <id>
#                              ZFS: a dataset <pool>/uso-fill with no mountpoint and a refreservation of all but
#                              <leave> (default 200 MiB) — nothing written; btrfs/xfs: fallocate <pool>/uso-fill.bin
#   disk_fill <id> <n> [leave MiB] / disk_unfill <id>   the same for array disk n
#
# States: DK-ASLEEP-DISK1 DK-ASLEEP-DISK2 DK-ASLEEP-POOL DK-POOL-FULL DK-POOL-DEGRADED DK-ARRAY-DISK-FULL DK-NO-ZFS

_st_disk_ini() { _st_ini "$ST_DISKS_INI" "$1" "$2"; }
disk_asleep() { [[ $(_st_disk_ini "$1" spundown) == 1 && $(_st_disk_ini "$1" rotational) != 0 ]]; }
disk_reads() { local dev; dev=$(_st_disk_ini "$1" device); [[ -n $dev ]] && awk '{print $1}' "/sys/block/$dev/stat"; }

disk_sleep() {
    local n=$1
    [[ -n $(_st_disk_ini "$n" device) ]] || { say "disk_sleep: disks.ini knows no $n"; return 1; }
    [[ $(_st_disk_ini "$n" rotational) == 0 ]] && { say "needs hdd: $n doesn't rotate — the office never counts it asleep"; return 2; }
    _st_emcmd "cmdSpindown=$n" || return 1
    _st_until 15 disk_asleep "$n" && { say "disk_sleep: $n asleep"; return 0; }
    say "needs hdd: $n doesn't sleep here (disks.ini spundown=$(_st_disk_ini "$n" spundown) — an emulated disk ignores standby)"
    return 2
}
disk_wake() {
    local n=$1
    [[ $(_st_disk_ini "$n" spundown) == 1 ]] || return 0
    _st_emcmd "cmdSpinup=$n" || return 1
    _st_until 30 _st_is 0 _st_disk_ini "$n" spundown
}

# a pool or disk's own filesystem: zfs <dataset> | btrfs | xfs
_st_fs_of() { local src fs; read -r src fs < <(findmnt -no SOURCE,FSTYPE --mountpoint "/mnt/$1"); printf '%s %s' "$fs" "$src"; }
_st_fill() { # <id> <name> <leave MiB>
    local id=$1 n=$2 leave=$(( ${3:-200} << 20 )) fs src avail dir
    mountpoint -q "/mnt/$n" || { say "needs $n: not mounted here"; return 2; }
    dir=$(_st_dir "$id"); read -r fs src < <(_st_fs_of "$n")
    if [[ $fs == zfs ]]; then
        avail=$(zfs list -Hp -o avail "$src") || return 1
        ((avail > leave)) || { say "fill: $n has only $avail bytes free"; return 0; }
        zfs create -o mountpoint=none -o refreservation=$((avail - leave)) "$src/uso-fill" || return 1
        printf 'zfs\t%s\n' "$src/uso-fill" >> "$dir/fill"
    else
        avail=$(df --output=avail -B1 "/mnt/$n" | tail -1)
        ((avail > leave)) || { say "fill: $n has only $avail bytes free"; return 0; }
        printf 'file\t%s\n' "/mnt/$n/uso-fill.bin" >> "$dir/fill"
        fallocate -l $((avail - leave)) "/mnt/$n/uso-fill.bin" || return 1
    fi
    say "fill: $n full but $(( leave >> 20 )) MiB ($fs)"
    # Unraid's own bookkeeping follows (shares.ini free, disks.ini fsFree) — what the office reads beside zfs/df
    _st_until 30 _st_lt $(( (leave >> 10) * 4 )) _st_disk_ini "$n" fsFree \
        || say "note: disks.ini fsFree of $n is $(_st_disk_ini "$n" fsFree) KB after 30 s"
}
_st_unfill() {
    local f kind what rc=0; f=$(_st_dir "$1")/fill
    [[ -f $f ]] || return 0
    while IFS=$'\t' read -r kind what; do
        case $kind in zfs) zfs list -H "$what" >/dev/null 2>&1 && { zfs destroy "$what" || rc=1; } ;; file) rm -f "$what" || rc=1 ;; esac
    done < <(tac "$f")
    ((rc == 0)) && rm -f "$f"
    return $rc
}
pool_fill() { _st_fill "$1" "${2:-cache}" "${3:-200}"; }
pool_unfill() { _st_unfill "$1"; }
disk_fill() { _st_fill "$1" "disk$2" "${3:-100}"; }
disk_unfill() { _st_unfill "$1"; }

# ------------------------------------------------------------------------------------------------------------ states
state_dk_asleep_disk1() { disk_sleep disk1; }
undo_dk_asleep_disk1() { disk_wake disk1; }
state_dk_asleep_disk2() { disk_sleep disk2; }
undo_dk_asleep_disk2() { disk_wake disk2; }
# a pool sleeps when any of its disks does — needs a pool on a rotating disk (Tower's cache is NVMe), default «slow»
state_dk_asleep_pool() { disk_sleep "${1:-slow}"; }
undo_dk_asleep_pool() { disk_wake "${1:-slow}"; }

state_dk_pool_full() { pool_fill DK-POOL-FULL "${1:-cache}" "${2:-200}"; }
undo_dk_pool_full() { pool_unfill DK-POOL-FULL; }

# a ZFS mirror with one side offline (Tower's cache); btrfs can't take a device out while mounted → needs zfs mirror
state_dk_pool_degraded() { local pool=${1:-cache} dev
    _st_need "a ZFS mirror $pool" bash -c "zpool status '$pool' 2>/dev/null | grep -q mirror" || return
    dev=$(zpool status -P "$pool" | awk '$1 ~ /^\/dev\// && $2 == "ONLINE" { d = $1 } END { print d }')
    [[ -n $dev ]] || { say "DK-POOL-DEGRADED: no online device in $pool"; return 1; }
    printf '%s\t%s\n' "$pool" "$dev" > "$(_st_dir DK-POOL-DEGRADED)/offline"
    zpool offline "$pool" "$dev" || return 1
    _st_until 10 bash -c "zpool status '$pool' | grep -q DEGRADED" && say "DK-POOL-DEGRADED: $dev of $pool offline"; }
undo_dk_pool_degraded() { local f pool dev; f=$(_st_dir DK-POOL-DEGRADED)/offline
    [[ -f $f ]] || return 0
    IFS=$'\t' read -r pool dev < "$f"
    zpool online "$pool" "$dev" || return 1
    _st_until 300 bash -c "zpool status -x '$pool' | grep -q 'is healthy'" || { say "DK-POOL-DEGRADED: $pool not healthy after 5 min"; return 1; }
    rm -f "$f"; }

state_dk_array_disk_full() { disk_fill DK-ARRAY-DISK-FULL "${1:-1}" "${2:-100}"; }
undo_dk_array_disk_full() { disk_unfill DK-ARRAY-DISK-FULL; }

# a server without ZFS (USOTest3VM's base): nothing to make, only to be sure
state_dk_no_zfs() { _st_need "a server without ZFS (USOTest3VM)" bash -c '! zpool list -H 2>/dev/null | grep -q .'; }
undo_dk_no_zfs() { :; }
