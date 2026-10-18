#!/bin/bash
# tools/scenarios/states-shares.sh — shares the way Unraid makes them, and the C states (SH-*) of zustaende.md (H2).
# Sourced by lib.sh; uses states-common.sh. Facts: STATES.md «Day-1 findings» 1.
#
#   share_exists <name>                          shares.ini lists it
#   share_make <name> <no|only|yes|prefer> [pool] [pool2] [exclusive]
#                                                Unraid's «Add Share» (emcmd); a name 7.4 refuses ([^A-Za-z0-9._-]) gets a
#                                                hand-made root on its primary first, then Unraid's «Apply» writes the cfg
#   share_apply <name> <cfgkey=value>…           Unraid's «Apply» with the share's current values and these changed
#                                                (shareUseCache, shareCachePool, shareCachePool2, shareInclude …)
#   share_rename <old> <new>                     Unraid's «Apply» with a new name (7.4: cfg and every root renamed, a
#                                                dataset with its snapshots)
#   share_remove <name>                          Unraid's «Delete» (refused while it holds files or snapshots)
#   share_roots <name>                           the disks/pools that have a root of it, one per line (cache, disk1 …)
#   share_primary <name>                         where its new files go first (the pool, or disk1 for an array share)
#   share_aside <id> <name> / share_back <id> <name>
#                                                every root moved out of the share (same filesystem: a ZFS dataset renamed to
#                                                <pool>/uso-keep-<id>-<share> with no mountpoint, a folder renamed into
#                                                /mnt/<disk>/.uso-keep/<id>/ — a dot folder is never a share) and back:
#                                                births, snapshots and ids stay
#   share_cfg_aside <id> <name> / share_cfg_back <id> <name>
#   share_cfg_edit <name> <cfgkey> <value>       the cfg by hand (only for what Unraid's form can't make — SH-CFG-POOL-GONE)
#   share_use <id> <name> <mode> [pool] [pool2]  the share as a state needs it: made (and noted for the undo) when missing
#   share_unuse <id>                             removes what share_use made and the files the state put there
#
# States: SH-ARRAY SH-POOL-ONLY SH-YES SH-PREFER-ARRAY-FILES SH-PREFER-POOL2 SH-YES-POOL2 SH-EXCLUSIVE SH-EXCLUSIVE-BROKEN
# SH-ZFS SH-BTRFS SH-XFS SH-MULTI-DISK SH-RENAMED SH-DELETED SH-CFG-ORPHAN SH-CFG-POOL-GONE SH-OFFICE-SHARE-EXISTS
# SH-OFFICE-SHARE-MISSING SH-APPDATA-MISSING SH-NAMES SH-100K SH-BIG-FILE SH-OPEN-FILE SH-STOREROOM

ST_SHARES_CFG=/boot/config/shares

share_exists() { grep -qxF "[\"$1\"]" "$ST_SHARES_INI"; }
_st_share_ini() { _st_ini "$ST_SHARES_INI" "$1" "$2"; }
_st_name_ok() { [[ $1 =~ ^[A-Za-z][A-Za-z0-9._-]*$ && $1 != *. ]]; }

# _st_root <disk> <share> — the share's root on that disk/pool, made the way shfs makes it (a dataset on ZFS)
_st_root() {
    local d=$1 s=$2 src fs
    [[ -d /mnt/$d/$s ]] && return 0
    read -r src fs < <(findmnt -no SOURCE,FSTYPE "/mnt/$d")
    if [[ $fs == zfs && $s =~ ^[A-Za-z0-9_.:-]+$ ]]; then
        zfs create "$src/$s" || return 1
    else                                   # (on ZFS too for a name no dataset may have: a plain folder, as a user's mkdir)
        mkdir "/mnt/$d/$s" || return 1
    fi
    chown nobody:users "/mnt/$d/$s"; chmod 0777 "/mnt/$d/$s"
}

_st_share_form() { # <orig> <name> <useCache> <pool> <pool2> <allocator> <split> <include> <exclude> <floor> <comment> <cow> <cmd>
    printf 'shareNameOrig=%s&shareName=%s&shareComment=%s&shareFloor=%s&shareUseCache=%s&shareCachePool=%s&shareCachePool2=%s&shareCOW=%s&shareAllocator=%s&shareSplitLevel=%s&shareInclude=%s&shareExclude=%s&cmdEditShare=%s' \
        "$(_st_uri "$1")" "$(_st_uri "$2")" "$(_st_uri "${11}")" "$(_st_uri "${10}")" "$3" "$(_st_uri "$4")" "$(_st_uri "$5")" \
        "${12:-auto}" "${6:-highwater}" "$(_st_uri "$7")" "$(_st_uri "$8")" "$(_st_uri "$9")" "$(_st_uri "${13}")"
}
_st_share_seen() { _st_until "${2:-10}" share_exists "$1"; }
_st_share_gone() { _st_until "${2:-10}" bash -c "! grep -qxF '[\"$1\"]' '$ST_SHARES_INI'"; }

share_make() {
    local name=$1 mode=$2 pool=${3:-} pool2=${4:-} excl=${5:-} prim
    case $mode in no) pool= ;; only|yes|prefer) pool=${pool:-cache} ;; *) say "share_make: mode $mode?"; return 1 ;; esac
    share_exists "$name" && { say "share_make: $name exists already"; return 1; }
    [[ -n $pool ]] && ! mountpoint -q "/mnt/$pool" && { say "needs pool $pool: not on this server"; return 2; }
    [[ -n $pool2 ]] && ! mountpoint -q "/mnt/$pool2" && { say "needs pool2 $pool2: not on this server"; return 2; }
    say "share_make $name $mode ${pool:-array}${pool2:+ → $pool2}"
    if _st_name_ok "$name"; then
        _st_emcmd "$(_st_share_form '' "$name" "$mode" "$pool" "$pool2" highwater '' '' '' '' '' auto 'Add Share')" || return 1
    else
        # 7.4's emhttpd refuses this name: a root made by hand (what a user's terminal or SMB client does), then «Apply»
        prim=${pool:-disk1}
        _st_root "$prim" "$name" || return 1
        _st_share_seen "$name" || { say "share_make: Unraid doesn't list the hand-made $name"; return 1; }
        _st_emcmd "$(_st_share_form "$name" "$name" "$mode" "$pool" "$pool2" highwater '' '' '' '' '' auto Apply)" || return 1
    fi
    _st_share_seen "$name" || { say "share_make: $name not in shares.ini"; return 1; }
    _st_until 10 test -f "$ST_SHARES_CFG/$name.cfg" || { say "share_make: no $name.cfg"; return 1; }
    if [[ $excl == exclusive ]]; then
        _st_until 10 _st_is yes _st_share_ini "$name" exclusive \
            || { say "share_make: $name is not exclusive (shares.ini exclusive=$(_st_share_ini "$name" exclusive))"; return 1; }
    fi
    return 0
}

share_apply() {
    local name=$1 kv k v; shift
    share_exists "$name" || { say "share_apply: no share $name"; return 1; }
    local -A c=([shareUseCache]=$(_st_share_ini "$name" useCache) [shareCachePool]=$(_st_share_ini "$name" cachePool)
        [shareCachePool2]=$(_st_share_ini "$name" cachePool2) [shareAllocator]=$(_st_share_ini "$name" allocator)
        [shareSplitLevel]=$(_st_share_ini "$name" splitLevel) [shareInclude]=$(_st_share_ini "$name" include)
        [shareExclude]=$(_st_share_ini "$name" exclude) [shareFloor]=$(_st_share_ini "$name" floor)
        [shareComment]=$(_st_share_ini "$name" comment) [shareCOW]=$(_st_share_ini "$name" cow))
    for kv; do k=${kv%%=*}; v=${kv#*=}; [[ -v c[$k] ]] || { say "share_apply: $k is no field of Unraid's form"; return 1; }; c[$k]=$v; done
    _st_emcmd "$(_st_share_form "$name" "$name" "${c[shareUseCache]}" "${c[shareCachePool]}" "${c[shareCachePool2]}" \
        "${c[shareAllocator]}" "${c[shareSplitLevel]}" "${c[shareInclude]}" "${c[shareExclude]}" "${c[shareFloor]}" \
        "${c[shareComment]}" "${c[shareCOW]}" Apply)" || return 1
    for kv; do
        k=${kv%%=*}; v=${kv#*=}
        _st_until 10 bash -c 'tr -d "\r" < "$1" | grep -qxF "$2"' _ "$ST_SHARES_CFG/$name.cfg" "$k=\"$v\"" \
            || { say "share_apply: $k not $v in $name.cfg"; return 1; }
    done
}

share_rename() {
    local old=$1 new=$2
    share_exists "$old" || { say "share_rename: no share $old"; return 1; }
    share_exists "$new" && { say "share_rename: $new exists already"; return 1; }
    _st_emcmd "$(_st_share_form "$old" "$new" "$(_st_share_ini "$old" useCache)" "$(_st_share_ini "$old" cachePool)" \
        "$(_st_share_ini "$old" cachePool2)" "$(_st_share_ini "$old" allocator)" "$(_st_share_ini "$old" splitLevel)" \
        "$(_st_share_ini "$old" include)" "$(_st_share_ini "$old" exclude)" "$(_st_share_ini "$old" floor)" \
        "$(_st_share_ini "$old" comment)" "$(_st_share_ini "$old" cow)" Apply)" || return 1
    _st_share_seen "$new" && _st_share_gone "$old" || { say "share_rename: shares.ini doesn't show $old → $new"; return 1; }
}

share_remove() {
    local name=$1
    share_exists "$name" || { say "share_remove: $name is not there (fine)"; rm -f "$ST_SHARES_CFG/$name.cfg"; return 0; }
    _st_emcmd "shareNameOrig=$(_st_uri "$name")&shareName=$(_st_uri "$name")&cmdEditShare=Delete" || return 1
    _st_share_gone "$name" || { say "share_remove: $name still in shares.ini"; return 1; }
    ! ls -d /mnt/*/"$name" >/dev/null 2>&1 || { say "share_remove: a root of $name is left: $(ls -d /mnt/*/"$name" | tr '\n' ' ')"; return 1; }
}

share_roots() { local d; for d in $(_st_pools); do [[ -e /mnt/$d/$1 ]] && printf '%s\n' "$d"; done; }
share_primary() {
    local u p; u=$(_st_share_ini "$1" useCache); p=$(_st_share_ini "$1" cachePool)
    case $u in only|yes|prefer) printf '%s' "${p:-cache}" ;; *) printf 'disk1' ;; esac
}

# the empty dirs a dataset leaves when it is unmounted, and the dataset name of a root (empty: a plain folder)
_st_dataset_of() { local src fs; read -r src fs < <(findmnt -no SOURCE,FSTYPE --mountpoint "$1" 2>/dev/null); [[ $fs == zfs ]] && printf '%s' "$src"; }

share_aside() {
    local id=$1 name=$2 only=${3:-} dir d ds keep
    dir=$(_st_dir "$id") || return 1
    for d in $(share_roots "$name"); do
        [[ $only == nonempty && -z $(ls -A "/mnt/$d/$name" 2>/dev/null) ]] && continue
        ds=$(_st_dataset_of "/mnt/$d/$name")
        if [[ -n $ds ]]; then
            keep="${ds%/*}/uso-keep-${id,,}-$name"
            zfs set mountpoint=none "$ds" && rmdir "/mnt/$d/$name" 2>/dev/null; zfs rename "$ds" "$keep" || return 1
            printf 'zfs\t%s\t%s\t%s\n' "$d" "$ds" "$keep" >> "$dir/aside.$name"
        else
            keep="/mnt/$d/.uso-keep/${id,,}"
            mkdir -p "$keep" && mv "/mnt/$d/$name" "$keep/" || return 1
            printf 'dir\t%s\t%s\t%s\n' "$d" "/mnt/$d/$name" "$keep/$name" >> "$dir/aside.$name"
        fi
        say "share_aside: $name on $d → ${keep}"
    done
}
share_back() {
    local id=$1 name=$2 dir kind d from keep rc=0
    dir=$(_st_dir "$id") || return 1
    [[ -f $dir/aside.$name ]] || return 0
    while IFS=$'\t' read -r kind d from keep; do
        if [[ $kind == zfs ]]; then
            zfs rename "$keep" "$from" && zfs inherit mountpoint "$from" || { rc=1; continue; }
        else
            mv "$keep" "$from" || { rc=1; continue; }
            rmdir "${keep%/*}" "/mnt/$d/.uso-keep" 2>/dev/null
        fi
        say "share_back: $name on $d"
    done < "$dir/aside.$name"
    ((rc == 0)) && rm -f "$dir/aside.$name"
    return $rc
}
share_cfg_aside() { local dir; dir=$(_st_dir "$1"); [[ -f $ST_SHARES_CFG/$2.cfg ]] && mv "$ST_SHARES_CFG/$2.cfg" "$dir/$2.cfg"; return 0; }
share_cfg_back() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/$2.cfg ]] || return 0
    cp "$dir/$2.cfg" "$ST_SHARES_CFG/$2.cfg" && rm -f "$dir/$2.cfg" && _st_emcmd 'cmdStatus=Apply'
}
# _st_cfg_val <cfg> <key> — a value of an Unraid cfg (share cfgs are written with CRLF)
_st_cfg_val() { tr -d '\r' < "$1" | sed -n "s/^$2=\"\(.*\)\"$/\1/p"; }
share_cfg_edit() {
    local f=$ST_SHARES_CFG/$1.cfg
    [[ -f $f ]] || { say "share_cfg_edit: no $1.cfg"; return 1; }
    say "share_cfg_edit $1: $2=\"$3\" (by hand)"
    if grep -q "^$2=" "$f"; then sed -i "s|^$2=\"[^\"]*\"|$2=\"$3\"|" "$f"; else printf '%s="%s"\r\n' "$2" "$3" >> "$f"; fi
}

share_use() {
    local id=$1 name=$2 mode=$3 rc
    if share_exists "$name"; then
        [[ $(_st_share_ini "$name" useCache) == "$mode" ]] \
            || say "note: $name exists with useCache=$(_st_share_ini "$name" useCache), not $mode — used as it is"
        return 0
    fi
    printf '%s\n' "$name" >> "$(_st_dir "$id")/made-shares"        # noted first: a half-made share goes too
    share_make "$name" "$mode" "${4:-}" "${5:-}" "${6:-}"; rc=$?
    track_share "$name" 2>/dev/null
    return $rc
}
share_unuse() {
    local id=$1 dir s d ds rc=0
    dir=$(_st_dir "$id") || return 1
    files_unput "$id" || rc=1
    if [[ -f $dir/made-shares ]]; then
        while IFS= read -r s; do
            [[ -n $s ]] || continue
            for d in $(share_roots "$s"); do find "/mnt/$d/$s" -mindepth 1 -delete; done
            # right after the files went, `zfs destroy` can find the dataset busy (seen on Tower): Unraid then leaves it
            # unmounted and refuses again — a second try a moment later, a dataset it left behind destroyed by us (ours)
            share_remove "$s" || { sleep 3; _st_emcmd 'cmdStatus=Apply'; share_remove "$s"; } || rc=1
            for d in $(_st_pools); do
                read -r ds _ < <(findmnt -no SOURCE,FSTYPE "/mnt/$d")
                zfs list -H "$ds/$s" >/dev/null 2>&1 && [[ $(zfs get -H -o value mounted "$ds/$s") == no ]] \
                    && { say "share_unuse: Unraid left $ds/$s unmounted — destroyed"; zfs destroy "$ds/$s" || rc=1; }
            done
            [[ -f $ST_SHARES_CFG/$s.cfg ]] && ! share_exists "$s" && { say "share_unuse: Unraid left $s.cfg — removed"; rm -f "$ST_SHARES_CFG/$s.cfg"; }
        done < <(tac "$dir/made-shares")
        ((rc == 0)) && rm -f "$dir/made-shares"
    fi
    return $rc
}

# ------------------------------------------------------------------------------------------------------------ states
_st_disk_of_fs() { # <fstype> — the first array disk or pool with that filesystem (disks.ini fsType), else nothing
    awk -v t="$1" '/^\[/ { n = $0; gsub(/^\["?|"?\]$/, "", n) } index($0, "fsType=\"" t "\"") == 1 { print n; exit }' "$ST_DISKS_INI"
}

state_sh_array() { local s=${1:-uso-arr} n=${2:-50}
    share_use SH-ARRAY "$s" no || return
    put_files SH-ARRAY disk1 "$s" "$n" && put_files SH-ARRAY disk2 "$s" "$n"; }
undo_sh_array() { share_unuse SH-ARRAY; }

state_sh_pool_only() { local s=${1:-uso-only} pool=${2:-cache}
    share_use SH-POOL-ONLY "$s" only "$pool" || return
    put_files SH-POOL-ONLY "$pool" "$s" 10; }
undo_sh_pool_only() { share_unuse SH-POOL-ONLY; }

state_sh_yes() { local s=${1:-uso-yes} pool=${2:-cache}
    share_use SH-YES "$s" yes "$pool" || return
    put_files SH-YES "$pool" "$s" 20 --age 40d && put_files SH-YES "$pool" "$s" 20 --age 1d; }
undo_sh_yes() { share_unuse SH-YES; }

state_sh_prefer_array_files() { local s=${1:-uso-prefer}
    share_use SH-PREFER-ARRAY-FILES "$s" prefer cache || return
    put_files SH-PREFER-ARRAY-FILES disk1 "$s" 10 && put_files SH-PREFER-ARRAY-FILES disk2 "$s" 10; }
undo_sh_prefer_array_files() { share_unuse SH-PREFER-ARRAY-FILES; }

# a second pool (default «fast») as secondary storage — needs pool2 (Tower has one pool today)
state_sh_prefer_pool2() { local s=${1:-uso-p2} pool2=${2:-fast}
    _st_need "pool2 $pool2" mountpoint -q "/mnt/$pool2" || return
    share_use SH-PREFER-POOL2 "$s" prefer cache "$pool2" || return
    put_files SH-PREFER-POOL2 "$pool2" "$s" 10; }
undo_sh_prefer_pool2() { share_unuse SH-PREFER-POOL2; }
state_sh_yes_pool2() { local s=${1:-uso-y2} pool2=${2:-fast}
    _st_need "pool2 $pool2" mountpoint -q "/mnt/$pool2" || return
    share_use SH-YES-POOL2 "$s" yes cache "$pool2" || return
    put_files SH-YES-POOL2 cache "$s" 10 --age 40d; }
undo_sh_yes_pool2() { share_unuse SH-YES-POOL2; }

# 7.4 makes an «only» share on one pool exclusive by itself (day-1 finding 1) — when ⟦Permit exclusive shares⟧ is on
# (share.cfg shareUserExclusive="yes": Tower yes, USOTest3VM no)
_st_excl_ok() { tr -d '\r' < /boot/config/share.cfg | grep -qx 'shareUserExclusive="yes"'; }
state_sh_exclusive() { local s=${1:-uso-ex}
    _st_need "exclusive shares permitted (share.cfg shareUserExclusive)" _st_excl_ok || return
    share_use SH-EXCLUSIVE "$s" only cache '' exclusive || return
    [[ -L /mnt/user/$s ]] || { say "SH-EXCLUSIVE: /mnt/user/$s is no symlink"; return 1; }
    put_files SH-EXCLUSIVE cache "$s" 5; }
undo_sh_exclusive() { share_unuse SH-EXCLUSIVE; }

# set exclusive, but a root on the array too: Unraid then keeps it non-exclusive (shares.ini exclusive="no" while the
# share's settings would allow it) — checked after a refresh; when 7.4 only looks again at the array's start, the state
# says so and does the stop/start (needs a free array: nothing of the office running)
state_sh_exclusive_broken() { local s=${1:-uso-exb}
    _st_need "exclusive shares permitted (share.cfg shareUserExclusive)" _st_excl_ok || return
    share_use SH-EXCLUSIVE-BROKEN "$s" only cache '' exclusive || return
    put_files SH-EXCLUSIVE-BROKEN disk1 "$s" 3 || return
    _st_emcmd 'cmdStatus=Apply'
    _st_until 15 _st_is no _st_share_ini "$s" exclusive && return 0
    say "SH-EXCLUSIVE-BROKEN: Unraid looks again only at the array's start — restarting the array"
    array_stop SH-EXCLUSIVE-BROKEN && array_start SH-EXCLUSIVE-BROKEN || return 1
    [[ $(_st_share_ini "$s" exclusive) == no ]] || { say "SH-EXCLUSIVE-BROKEN: still exclusive"; return 1; }
}
undo_sh_exclusive_broken() { share_unuse SH-EXCLUSIVE-BROKEN; }

# a share whose part lies on that filesystem: ZFS (Tower cache), btrfs (USOTest3VM cache/disk1), xfs (an array disk)
_st_sh_fs() { local id=$1 fs=$2 s=$3 d
    d=$(_st_disk_of_fs "$fs")
    _st_need "a $fs disk or pool" test -n "$d" || return
    if [[ $d == disk* ]]; then
        share_use "$id" "$s" no || return
        share_exists "$s" && [[ -f $(_st_dir "$id")/made-shares ]] && { share_apply "$s" "shareInclude=$d" || return; }
    else
        share_use "$id" "$s" only "$d" || return
    fi
    put_files "$id" "$d" "$s" 10; }
state_sh_zfs() { _st_sh_fs SH-ZFS zfs "${1:-uso-zfs}"; }
undo_sh_zfs() { share_unuse SH-ZFS; }
state_sh_btrfs() { _st_sh_fs SH-BTRFS btrfs "${1:-uso-btrfs}"; }
undo_sh_btrfs() { share_unuse SH-BTRFS; }
state_sh_xfs() { _st_sh_fs SH-XFS xfs "${1:-uso-xfs}"; }
undo_sh_xfs() { share_unuse SH-XFS; }

state_sh_multi_disk() { local s=${1:-uso-arr}
    share_use SH-MULTI-DISK "$s" no || return
    put_files SH-MULTI-DISK disk1 "$s/serie1" 5 && put_files SH-MULTI-DISK disk2 "$s/serie1" 5; }
undo_sh_multi_disk() { share_unuse SH-MULTI-DISK; }

# renamed through Unraid's own form (7.4 renames cfg and roots; a dataset keeps its snapshots under the new name)
state_sh_renamed() { local a=${1:-uso-a} b=${2:-uso-b}
    share_use SH-RENAMED "$a" only cache || return
    [[ -f $(_st_dir SH-RENAMED)/made-shares ]] && { put_files SH-RENAMED cache "$a" 10 || return; }
    share_rename "$a" "$b" && printf '%s\t%s\n' "$a" "$b" > "$(_st_dir SH-RENAMED)/renamed"; }
undo_sh_renamed() { local dir; dir=$(_st_dir SH-RENAMED)
    if [[ -f $dir/renamed ]]; then
        local a b; IFS=$'\t' read -r a b < "$dir/renamed"
        share_rename "$b" "$a" || return 1
        sed -i "s|^/mnt/\([^/]*\)/$b/|/mnt/\1/$a/|" "$dir/files" 2>/dev/null; rm -f "$dir/renamed"
    fi
    share_unuse SH-RENAMED; }

# deleted by Unraid's «Delete» (cfg, shares.ini entry and roots gone). Unraid deletes only an empty share: the parts
# with files are first moved aside on their own filesystem (share_aside nonempty — a dataset renamed, so births,
# snapshots and ids stay), an empty root is left for Unraid to remove. A ZFS share with snapshots can't be deleted by
# Unraid at all (day-1: «Delete» unmounts, `zfs destroy` fails, a second «Delete» drops the cfg and leaves the dataset
# unmounted) — this state refuses then.
state_sh_deleted() { local s=${1:-uso-yes} dir d ds
    dir=$(_st_dir SH-DELETED)
    if ! share_exists "$s"; then share_use SH-DELETED "$s" yes cache && put_files SH-DELETED cache "$s" 10 || return; fi
    for d in $(share_roots "$s"); do
        ds=$(_st_dataset_of "/mnt/$d/$s")
        [[ -n $ds && -n $(zfs list -H -t snapshot -o name "$ds" 2>/dev/null) ]] && { say "SH-DELETED: $ds has snapshots — Unraid can't delete it"; return 1; }
    done
    cp "$ST_SHARES_CFG/$s.cfg" "$dir/deleted.cfg" && printf '%s\n' "$s" > "$dir/deleted" || return 1
    share_roots "$s" > "$dir/deleted.roots"
    share_aside SH-DELETED "$s" nonempty || return 1
    [[ -n $(share_roots "$s") ]] || { _st_root "$(share_primary "$s")" "$s" && _st_share_seen "$s"; } || return 1
    share_remove "$s"; }
undo_sh_deleted() { local dir s d ds; dir=$(_st_dir SH-DELETED)
    if [[ -f $dir/deleted ]]; then
        s=$(cat "$dir/deleted")
        # a root Unraid (or anybody) made meanwhile is in the way of the renamed-back part: only an empty one goes
        for d in $(share_roots "$s"); do
            [[ -n $(ls -A "/mnt/$d/$s") ]] && { say "SH-DELETED: /mnt/$d/$s holds files — not put back"; return 1; }
            ds=$(_st_dataset_of "/mnt/$d/$s"); if [[ -n $ds ]]; then zfs destroy "$ds"; else rmdir "/mnt/$d/$s"; fi || return 1
        done
        share_back SH-DELETED "$s" || return 1
        while read -r d; do [[ -e /mnt/$d/$s ]] || _st_root "$d" "$s" || return 1; done < "$dir/deleted.roots"   # the empty ones
        cp "$dir/deleted.cfg" "$ST_SHARES_CFG/$s.cfg" && _st_emcmd 'cmdStatus=Apply' && _st_share_seen "$s" || return 1
        rm -f "$dir/deleted" "$dir/deleted.cfg" "$dir/deleted.roots"
    fi
    share_unuse SH-DELETED; }

# the cfg stays, every root is gone (a share removed by hand): roots aside, Unraid stops listing it
state_sh_cfg_orphan() { local s=${1:-uso-only}
    share_use SH-CFG-ORPHAN "$s" only cache || return
    [[ -f $(_st_dir SH-CFG-ORPHAN)/made-shares ]] && { put_files SH-CFG-ORPHAN cache "$s" 5 || return; }
    share_aside SH-CFG-ORPHAN "$s" || return 1
    _st_share_gone "$s" || { say "SH-CFG-ORPHAN: Unraid still lists $s"; return 1; }
    [[ -f $ST_SHARES_CFG/$s.cfg ]]; }
undo_sh_cfg_orphan() { share_back SH-CFG-ORPHAN "${1:-uso-only}" || return 1
    _st_emcmd 'cmdStatus=Apply'; _st_share_seen "${1:-uso-only}"; share_unuse SH-CFG-ORPHAN; }

# the cfg names a pool that isn't there (by hand: Unraid's form offers only pools it has); Unraid takes it at the array's start
state_sh_cfg_pool_gone() { local s=${1:-uso-only} big=${2:-big} dir
    dir=$(_st_dir SH-CFG-POOL-GONE)
    share_use SH-CFG-POOL-GONE "$s" only cache || return
    cp "$ST_SHARES_CFG/$s.cfg" "$dir/pool-gone.cfg" && printf '%s\n' "$s" > "$dir/pool-gone" || return 1
    share_cfg_edit "$s" shareCachePool "$big" || return 1
    array_stop SH-CFG-POOL-GONE && array_start SH-CFG-POOL-GONE || return 1
    [[ $(_st_share_ini "$s" cachePool) == "$big" ]] || { say "SH-CFG-POOL-GONE: shares.ini says cachePool=$(_st_share_ini "$s" cachePool)"; return 1; }; }
undo_sh_cfg_pool_gone() { local dir s; dir=$(_st_dir SH-CFG-POOL-GONE)
    if [[ -f $dir/pool-gone ]]; then
        s=$(cat "$dir/pool-gone")
        cp "$dir/pool-gone.cfg" "$ST_SHARES_CFG/$s.cfg" || return 1
        array_stop SH-CFG-POOL-GONE-UNDO && array_start SH-CFG-POOL-GONE-UNDO || return 1
        rm -f "$dir/pool-gone" "$dir/pool-gone.cfg"
    fi
    share_unuse SH-CFG-POOL-GONE; }

# the office's share is there (base); variant «unplaced»: Mr. Backupsy has no backup place chosen yet (dumps_share empty)
state_sh_office_share_exists() { local v=${1:-placed} dir
    share_exists UnraidSecretaryOffice || { say "SH-OFFICE-SHARE-EXISTS: Unraid lists no UnraidSecretaryOffice"; return 1; }
    [[ $v == unplaced ]] || return 0
    [[ -f $UB/settings.ini ]] || { say "SH-OFFICE-SHARE-EXISTS unplaced: no settings.ini (no setup yet — already unplaced)"; return 0; }
    dir=$(_st_dir SH-OFFICE-SHARE-EXISTS); cp -p "$UB/settings.ini" "$dir/settings.ini" || return 1
    awk '/^\[/ { g = ($0 == "[general]") } g && /^dumps_share *=/ { print "dumps_share ="; next } { print }' "$dir/settings.ini" > "$UB/settings.ini.uso-new" \
        && mv "$UB/settings.ini.uso-new" "$UB/settings.ini"; }
undo_sh_office_share_exists() { local dir; dir=$(_st_dir SH-OFFICE-SHARE-EXISTS)
    [[ -f $dir/settings.ini ]] || return 0
    cp -p "$dir/settings.ini" "$UB/settings.ini" && rm -f "$dir/settings.ini"; }

# no office share: its roots (with the backup place) and its cfg aside — as if never made
state_sh_office_share_missing() { local s=UnraidSecretaryOffice
    share_exists "$s" || { say "SH-OFFICE-SHARE-MISSING: already missing"; return 0; }
    share_cfg_aside SH-OFFICE-SHARE-MISSING "$s" && share_aside SH-OFFICE-SHARE-MISSING "$s" || return 1
    _st_share_gone "$s" || { say "SH-OFFICE-SHARE-MISSING: Unraid still lists it"; return 1; }; }
undo_sh_office_share_missing() { share_back SH-OFFICE-SHARE-MISSING UnraidSecretaryOffice && share_cfg_back SH-OFFICE-SHARE-MISSING UnraidSecretaryOffice; }

# no appdata (Docker never on): the office's data folder lies there, so the agent is stopped around the move
# (needs test3 — on Tower Docker's containers live in appdata)
state_sh_appdata_missing() {
    _st_need "Docker off (USOTest3VM)" bash -c '! pgrep -x dockerd' || return
    share_exists appdata || { say "SH-APPDATA-MISSING: already missing"; return 0; }
    _st_clean bash "$P/scripts/agent.sh" stop >/dev/null 2>&1
    share_cfg_aside SH-APPDATA-MISSING appdata && share_aside SH-APPDATA-MISSING appdata; local rc=$?
    _st_clean bash "$P/scripts/agent.sh" start >/dev/null 2>&1
    ((rc == 0)) || return 1
    _st_share_gone appdata || { say "SH-APPDATA-MISSING: Unraid still lists appdata"; return 1; }; }
undo_sh_appdata_missing() { local dir; dir=$(_st_dir SH-APPDATA-MISSING)
    [[ -f $dir/aside.appdata || -f $dir/appdata.cfg ]] || return 0
    _st_clean bash "$P/scripts/agent.sh" stop >/dev/null 2>&1
    share_back SH-APPDATA-MISSING appdata && share_cfg_back SH-APPDATA-MISSING appdata; local rc=$?
    _st_clean bash "$P/scripts/agent.sh" start >/dev/null 2>&1
    return $rc; }

# a share whose name 7.4's form refuses («Fotos Ärger»: space and umlaut) with the awkward file names in it
state_sh_names() { local s=${1:-Fotos Ärger}
    share_use SH-NAMES "$s" yes cache || return
    put_names SH-NAMES cache "$s"; }
undo_sh_names() { share_unuse SH-NAMES; }

state_sh_100k() { local s=${1:-uso-yes} n=${2:-100000}
    share_use SH-100K "$s" yes cache || return
    put_many SH-100K "$(share_primary "$s")" "$s" "$n"; }
undo_sh_100k() { share_unuse SH-100K; }

state_sh_big_file() { local s=${1:-uso-yes} gb=${2:-2}
    share_use SH-BIG-FILE "$s" yes cache || return
    put_big SH-BIG-FILE "$(share_primary "$s")" "$s" "$gb"; }
undo_sh_big_file() { share_unuse SH-BIG-FILE; }

state_sh_open_file() { local s=${1:-uso-yes}
    share_use SH-OPEN-FILE "$s" yes cache || return
    put_files SH-OPEN-FILE "$(share_primary "$s")" "$s" 1 --name open.bin || return
    open_file SH-OPEN-FILE "/mnt/user/$s/open.bin"; }
undo_sh_open_file() { close_files SH-OPEN-FILE; share_unuse SH-OPEN-FILE; }

state_sh_storeroom() { local s=${1:-uso-yes}
    share_use SH-STOREROOM "$s" yes cache || return
    storeroom_put SH-STOREROOM "$(share_primary "$s")" "$s"; }
undo_sh_storeroom() { share_unuse SH-STOREROOM; }
