#!/bin/bash
###############################################################################
# unraid-backup - lib/common.sh
#
# Functions shared by setup.sh and backup.sh. Loaded with "source",
# never run directly.
#
# Contents
#   1. Basics          paths, logging, notifications
#   2. settings.ini    reading, checking, getting values
#   3. Inventory       pools, disks, shares, ZFS datasets, containers
#   4. Plan            what is snapshotted, mounted, backed up
#   5. Kopia           mapping, identity, policies (wanted/actual)
#   6. Drift           comparing inventory <-> settings.ini
###############################################################################

# shellcheck disable=SC2034   # many variables are only used in the scripts

UB_VERSION="2.15"
UB_NAME="unraid-backup"
UB_USER_SCRIPT="unraid-secretary-office_backup"   # the User Scripts entry (was unraid-backup; the office moves it)
# The office's own places. Nothing of ours directly in /mnt (Fix Common Problems rightly
# complains): mounts go to /mnt/addons, which Unraid creates at boot for this - a small
# tmpfs in RAM with mount propagation, holding only empty mount points and symlinks. The
# data the desks keep (dumps, archives) go to the share UnraidSecretaryOffice, one folder
# per desk - this one is "backup".
UB_OFFICE_SHARE="UnraidSecretaryOffice"
UB_DESK_DIR="backup"

##############################################################################
# 1. Basics
##############################################################################

# The script is part of the Unraid Secretary Office: code in <office>/backup,
# settings, state and logs in <office>/data/unraid-backup
# (not in git, root only - the logs name every database).
# Installed as a plugin the code lies in RAM (/usr/local/emhttp/plugins/...) and the
# data in the office's data folder: DATA_DIR in the plugin's .cfg on the flash, by
# default <appdata>/UnraidSecretaryOffice/data (like src/place.php).
UB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
ub_is_plugin() { [[ "$UB_DIR" == /usr/local/emhttp/plugins/* ]]; }
ub_plugin_data() {
    local conf="${UB_BOOT:-/boot}/config" dir appdata
    dir="$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$conf/plugins/unraid-secretary-office/unraid-secretary-office.cfg" 2>/dev/null | tail -n 1)"
    if [[ "$dir" != /mnt/* ]]; then
        appdata="$(sed -n 's/^DOCKER_APP_CONFIG_PATH="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$conf/docker.cfg" 2>/dev/null | tail -n 1)"
        dir="${appdata:-/mnt/user/appdata}"
        dir="${dir%/}/UnraidSecretaryOffice/data"
    fi
    echo "${dir%/}"
}
if [[ -z "${UB_DATA:-}" ]] && ub_is_plugin; then
    UB_DATA="$(ub_plugin_data)/$UB_NAME"
fi
UB_DATA="${UB_DATA:-$(cd "$UB_DIR/.." && pwd -P)/data/$UB_NAME}"
UB_SETTINGS="${UB_SETTINGS:-$UB_DATA/settings.ini}"
UB_STATE="$UB_DATA/state"
UB_LOGS="$UB_DATA/logs"
UB_DUMPS="$UB_DATA/dumps"
# Private staging area for mounts Kopia should not see (ZFS layers)
# and for read-only binds before they are moved into the snapshot folder
UB_STAGE="${UB_STAGE:-/run/$UB_NAME-stage}"

# Test helpers - never set them in operation. They allow a trial run against a
# rebuilt directory tree instead of the real system.
UB_MNT="${UB_MNT:-/mnt}"
UB_BOOT="${UB_BOOT:-/boot}"
UB_MOUNTS_FILE="${UB_MOUNTS_FILE:-/proc/mounts}"
UB_NOTIFY_BIN="${UB_NOTIFY_BIN:-/usr/local/emhttp/webGui/scripts/notify}"
UB_SHARES_CFG="${UB_SHARES_CFG:-$UB_BOOT/config/shares}"

LOG_FILE="${LOG_FILE:-}"
WARNINGS=0
ERRORS=0

_ts()  { date '+%Y-%m-%d %H:%M:%S'; }
_log_line() {
    printf '%s\n' "$1"
    if [[ -n "$LOG_FILE" ]]; then printf '%s\n' "$1" >>"$LOG_FILE"; fi
}
log()  { _log_line "$(_ts)  $*"; }
warn() { WARNINGS=$((WARNINGS+1)); _log_line "$(_ts)  WARNING: $*"; }
err()  { ERRORS=$((ERRORS+1));     _log_line "$(_ts)  ERROR: $*"; }

# ub_notify <subject> <short text> [normal|warning|alert] [long text]
# Create the data folder and lock it (0700: the office reads it through its
# agent as root, the web container cannot get at it)
ub_data_dirs() {
    mkdir -p "$UB_STATE" "$UB_LOGS" || return 1      # dumps: in their own backup share (dumps_share), never here
    chmod 700 "$UB_DATA" 2>/dev/null
    return 0
}

ub_notify() {
    [[ "${UB_NO_NOTIFY:-0}" == "1" ]] && return 0
    [[ -x "$UB_NOTIFY_BIN" ]] || return 0
    local args=( -e "$UB_NAME" -s "${SERVER_NAME:-$(hostname -s)}: $1" -d "$2" -i "${3:-normal}" )
    [[ -n "${4:-}" ]] && args+=( -m "$4" )
    "$UB_NOTIFY_BIN" "${args[@]}" >/dev/null 2>&1 || true
}

is_yes() { [[ "${1,,}" == "yes" || "${1,,}" == "ja" || "$1" == "1" || "${1,,}" == "true" ]]; }

# Integer check without surprises on empty values
is_uint() { [[ "$1" =~ ^[0-9]+$ ]]; }

# bytes -> GB (rounded down)
to_gb() { local b="${1:-0}"; is_uint "$b" || b=0; echo $(( b / 1073741824 )); }

human() { numfmt --to=iec --suffix=B "${1:-0}" 2>/dev/null || echo "${1:-0} B"; }

# Escape a path for overlayfs options (colon, comma, backslash)
ovl_escape() { local p="$1"; p="${p//\\/\\\\}"; p="${p//:/\\:}"; p="${p//,/\\,}"; printf '%s' "$p"; }

# Escape a path the way /proc/*/mountinfo writes it
mi_escape() { local p="$1"; p="${p//\\/\\134}"; p="${p// /\\040}"; p="${p//$'\t'/\\011}"; printf '%s' "$p"; }

# A share name is only usable if it does not confuse Kopia, overlayfs and
# settings.ini.
share_name_ok() { [[ "$1" != *[@:\"\|]* && "$1" != *$'\n'* && -n "$1" ]]; }

##############################################################################
# 2. settings.ini
##############################################################################
# Format
#   [section]                e.g. [general]
#   [type "name"]            e.g. [share "appdata"]
#   key = value              lists: give the key several times
#   # or ; at the start of a line = comment (no comments after values,
#                              so that patterns like "#recycle" stay possible)
#
# Internally: CFG["general|mount_root"], CFG["share|appdata|mode"], ...
# Several values are separated by line breaks.

declare -gA CFG=()
declare -ga CFG_SECTIONS=() CFG_ERRORS=()

cfg_load() {
    local file="$1" line sec="" key val n=0
    local re_sec='^\[([a-z]+)\]$'
    local re_named='^\[([a-z]+)[[:space:]]+"(.+)"\]$'
    local re_kv='^([a-z0-9_]+)[[:space:]]*=[[:space:]]*(.*)$'
    local -A seen=()
    CFG=(); CFG_SECTIONS=(); CFG_ERRORS=()
    [[ -r "$file" ]] || return 1
    while IFS= read -r line || [[ -n "$line" ]]; do
        n=$((n+1))
        line="${line%$'\r'}"
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [[ -z "$line" || "${line:0:1}" == "#" || "${line:0:1}" == ";" ]] && continue
        if [[ "$line" =~ $re_sec ]]; then
            sec="${BASH_REMATCH[1]}"
        elif [[ "$line" =~ $re_named ]]; then
            sec="${BASH_REMATCH[1]}|${BASH_REMATCH[2]}"
        elif [[ "$line" =~ $re_kv ]]; then
            if [[ -z "$sec" ]]; then
                CFG_ERRORS+=( "line $n: entry before the first [section]" ); continue
            fi
            key="$sec|${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
            if [[ -n "${CFG[$key]:-}" ]]; then CFG[$key]+=$'\n'"$val"; else CFG[$key]="$val"; fi
            continue
        else
            CFG_ERRORS+=( "line $n: not understood: $line" ); continue
        fi
        if [[ -z "${seen[$sec]:-}" ]]; then seen[$sec]=1; CFG_SECTIONS+=( "$sec" ); fi
    done <"$file"
    return 0
}

# cfg <key> [default]  -> last value (for single values the last one wins)
cfg() {
    local k="$1"
    if [[ -n "${CFG[$k]+x}" && -n "${CFG[$k]}" ]]; then printf '%s' "${CFG[$k]##*$'\n'}"
    else printf '%s' "${2-}"; fi
}
# cfg_list <key>  -> all values, one per line, empty ones left out
cfg_list() {
    local k="$1" v
    [[ -n "${CFG[$k]:-}" ]] || return 0
    while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\n' "$v"; done <<<"${CFG[$k]}"
    return 0
}
# cfg_names <type>  -> names of all [type "name"] sections
cfg_names() {
    local t="$1" s
    for s in "${CFG_SECTIONS[@]}"; do [[ "$s" == "$t|"* ]] && printf '%s\n' "${s#*|}"; done
    return 0
}
cfg_has() { local s; for s in "${CFG_SECTIONS[@]}"; do [[ "$s" == "$1" ]] && return 0; done; return 1; }
sec_display() { if [[ "$1" == *"|"* ]]; then printf '[%s "%s"]' "${1%%|*}" "${1#*|}"; else printf '[%s]' "$1"; fi; }

declare -gA UB_SCHEMA=(
    [general]="server mount_root view_root snap_prefix btrfs_snap_dir keep_runs keep_logs min_free_gb keep_mounts notify_success dumps_share"
    [zfs]="retention"
    [btrfs]="keep_days min_free_gb snapshot_all"
    [drift]="ignore remind_days"
    [docker]="stop no_stop stop_timeout known"
    [flash]="mode tar_exclude kopia_ignore"
    [libvirt]="mode"
    [kopia]="enabled container identity keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual compression ignore"
    [nextcloud]="preexisting_maintenance"
    [dump]="type"
    [share]="mode method retention kopia_retention kopia_ignore exclude_dataset id locations note"
)

# Checks sections, keys and values. Errors go into CFG_ERRORS.
cfg_validate() {
    local k sec typ key allowed v
    for k in "${!CFG[@]}"; do
        sec="${k%|*}"; key="${k##*|}"; typ="${sec%%|*}"
        allowed="${UB_SCHEMA[$typ]:-}"
        if [[ -z "$allowed" ]]; then
            CFG_ERRORS+=( "Unknown section [$typ]" ); continue
        fi
        if [[ " $allowed " != *" $key "* ]]; then
            CFG_ERRORS+=( "Unknown key '$key' in $(sec_display "$sec")" ); continue
        fi
    done
    _val() { # _val <key> <regex> <description>
        local v; v="$(cfg "$1")"
        [[ -z "$v" || "$v" =~ $2 ]] || CFG_ERRORS+=( "$1 = '$v' is invalid ($3)" )
    }
    _val "general|dumps_share"   '^[A-Za-z0-9._ -]+$'       "name of a share"
    _val "general|keep_runs"     '^[0-9]+$'                 "number"
    _val "general|keep_logs"     '^[0-9]+$'                 "number"
    _val "general|min_free_gb"   '^[0-9]+$'                 "number"
    _val "general|keep_mounts"   '^(yes|no)$'               "yes/no"
    _val "general|notify_success" '^(yes|no)$'              "yes/no"
    _val "general|snap_prefix"   '^[a-z0-9_]+-$'            "lower-case letters/digits, ends with -"
    _val "general|mount_root"    "^$UB_MNT/([^/]+|addons/[^/]+/[^/]+)$" "directly under $UB_MNT or $UB_MNT/addons/<name>/"
    _val "general|view_root"     "^$UB_MNT/([^/]+|addons/[^/]+/[^/]+)$" "directly under $UB_MNT or $UB_MNT/addons/<name>/"
    _val "zfs|retention"         '^[0-9]+ [0-9]+ [0-9]+$'   "three numbers: daily weekly monthly"
    _val "btrfs|keep_days"       '^[0-9]+$'                 "number"
    _val "btrfs|min_free_gb"     '^[0-9]+$'                 "number"
    _val "btrfs|snapshot_all"    '^(yes|no)$'               "yes/no"
    _val "drift|remind_days"     '^[0-9]+$'                 "number"
    _val "docker|stop"           '^(all|none)$'             "all/none"
    _val "docker|stop_timeout"   '^[0-9]+$'                 "number"
    _val "flash|mode"            '^(snapshot|tar|off)$'     "snapshot/tar/off"
    _val "libvirt|mode"          '^(tar|off)$'              "tar/off"
    _val "kopia|enabled"         '^(yes|no)$'               "yes/no"
    for key in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do
        _val "kopia|$key" '^([0-9]+|inherit)$' "number or inherit"
    done
    local n
    while IFS= read -r n; do
        [[ -n "$n" ]] && _val "nextcloud|$n|preexisting_maintenance" '^(abort|continue)$' "abort/continue"
    done < <(cfg_names nextcloud)
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        _val "dump|$n|type" '^(mariadb|postgres|mongodb)$' "mariadb/postgres/mongodb"
        [[ -z "$(cfg "dump|$n|type")" ]] && CFG_ERRORS+=( "[dump \"$n\"] without type" )
    done < <(cfg_names dump)
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        share_name_ok "$n" || CFG_ERRORS+=( "Share name '$n' contains @ : \" or | - not supported" )
        _val "share|$n|mode"      '^(kopia|snapshot|off)$'  "kopia/snapshot/off"
        _val "share|$n|method"    '^(auto|live)$'           "auto/live"
        _val "share|$n|retention" '^[0-9]+ [0-9]+ [0-9]+$'  "three numbers"
        _val "share|$n|kopia_retention" '^([0-9]+|inherit)( ([0-9]+|inherit)){5}$' "six values: latest hourly daily weekly monthly annual"
        [[ -z "$(cfg "share|$n|mode")" ]] && CFG_ERRORS+=( "[share \"$n\"] without mode" )
    done < <(cfg_names share)
    unset -f _val
    [[ ${#CFG_ERRORS[@]} -eq 0 ]]
}

# Loads settings.ini into fixed variables. Returns 1 if the file is missing.
load_settings() {
    cfg_load "$UB_SETTINGS" || return 1
    cfg_validate
    apply_settings
    return 0
}

# Takes CFG into variables (can be called without a file too -> defaults)
apply_settings() {
    SERVER_NAME="$(cfg "general|server" "$(hostname -s 2>/dev/null || echo unraid)")"
    MOUNT_ROOT="$(cfg "general|mount_root" "$UB_MNT/addons/$UB_OFFICE_SHARE/snapshots")"
    VIEW_ROOT="$(cfg "general|view_root" "$UB_MNT/addons/$UB_OFFICE_SHARE/btrfs-snap")"
    SNAP_PREFIX="$(cfg "general|snap_prefix" "unraidbackup-")"
    BTRFS_SNAP_DIR="$(cfg "general|btrfs_snap_dir" ".btrfs-snap")"
    KEEP_RUNS="$(cfg "general|keep_runs" 14)"
    KEEP_LOGS="$(cfg "general|keep_logs" 60)"
    MIN_FREE_GB="$(cfg "general|min_free_gb" 8)"
    KEEP_MOUNTS="$(cfg "general|keep_mounts" no)"
    NOTIFY_SUCCESS="$(cfg "general|notify_success" yes)"
    # Dumps, archives and manifests live in their own backup share, never in appdata:
    # <share>/unraid-backup/<run>/, in the office's share <share>/backup/<run>/
    # (dumps_share_problem says whether the share will do)
    DUMPS_SHARE="$(cfg "general|dumps_share")"
    UB_DUMPS="$(dumps_path "$DUMPS_SHARE")"

    ZFS_RETENTION="$(cfg "zfs|retention" "7 4 6")"
    BTRFS_KEEP_DAYS="$(cfg "btrfs|keep_days" 7)"
    BTRFS_MIN_FREE_GB="$(cfg "btrfs|min_free_gb" 150)"
    BTRFS_SNAPSHOT_ALL="$(cfg "btrfs|snapshot_all" no)"

    DRIFT_REMIND_DAYS="$(cfg "drift|remind_days" 7)"
    mapfile -t DRIFT_IGNORE < <(cfg_list "drift|ignore")

    DOCKER_STOP="$(cfg "docker|stop" all)"
    DOCKER_STOP_TIMEOUT="$(cfg "docker|stop_timeout" 60)"
    mapfile -t DOCKER_NO_STOP < <(cfg_list "docker|no_stop")
    mapfile -t DOCKER_KNOWN   < <(cfg_list "docker|known")

    FLASH_MODE="$(cfg "flash|mode" tar)"
    # VM configuration (XML, NVRAM, TPM state) from libvirt.img: default tar,
    # also without an entry in settings.ini - it is small, and without it
    # moving the VMs is a chore
    LIBVIRT_MODE="$(cfg "libvirt|mode" tar)"
    mapfile -t FLASH_TAR_EXCLUDE  < <(cfg_list "flash|tar_exclude")
    mapfile -t FLASH_KOPIA_IGNORE < <(cfg_list "flash|kopia_ignore")

    KOPIA_ENABLED="$(cfg "kopia|enabled" yes)"
    KOPIA_CONTAINER="$(cfg "kopia|container" "")"
    KOPIA_IDENTITY_CFG="$(cfg "kopia|identity" "")"
    KOPIA_KEEP_LATEST="$(cfg "kopia|keep_latest" 7)"
    KOPIA_KEEP_HOURLY="$(cfg "kopia|keep_hourly" 0)"
    KOPIA_KEEP_DAILY="$(cfg "kopia|keep_daily" 7)"
    KOPIA_KEEP_WEEKLY="$(cfg "kopia|keep_weekly" 4)"
    KOPIA_KEEP_MONTHLY="$(cfg "kopia|keep_monthly" 12)"
    KOPIA_KEEP_ANNUAL="$(cfg "kopia|keep_annual" 3)"
    KOPIA_COMPRESSION="$(cfg "kopia|compression" inherit)"
    mapfile -t KOPIA_IGNORE < <(cfg_list "kopia|ignore")
}

in_list() { # in_list <value> <entry...>
    local x="$1"; shift
    local e; for e in "$@"; do [[ "$e" == "$x" ]] && return 0; done; return 1
}

matches_any() { # matches_any <value> <glob...>
    local x="$1"; shift
    local g
    for g in "$@"; do
        # shellcheck disable=SC2053  # a glob comparison is wanted here
        [[ -n "$g" && "$x" == $g ]] && return 0
    done
    return 1
}

##############################################################################
# 3. Inventory
##############################################################################

# --- Mount table -----------------------------------------------------------
declare -ga MT_TARGET=() MT_SOURCE=() MT_FSTYPE=() MT_OPTS=()
declare -gA MT_SET=()
# Test helper: UB_TEST_FSMAP = file with lines "target type source"; overrides
# type and source of these mounts (trial run without real ZFS/btrfs)
declare -gA _FSMAP_T=() _FSMAP_S=()
if [[ -n "${UB_TEST_FSMAP:-}" && -r "${UB_TEST_FSMAP}" ]]; then
    while read -r _a _b _c; do [[ -n "$_a" ]] && { _FSMAP_T[$_a]="$_b"; _FSMAP_S[$_a]="$_c"; }; done <"$UB_TEST_FSMAP"
fi
mounts_load() {
    MT_TARGET=(); MT_SOURCE=(); MT_FSTYPE=(); MT_OPTS=(); MT_SET=()
    local src tgt fs opts rest
    while read -r src tgt fs opts rest; do
        [[ "$src" == *\\* ]] && src="$(printf '%b' "$src")"
        [[ "$tgt" == *\\* ]] && tgt="$(printf '%b' "$tgt")"
        if [[ -n "${_FSMAP_T[$tgt]:-}" ]]; then fs="${_FSMAP_T[$tgt]}"; src="${_FSMAP_S[$tgt]}"; fi
        MT_SET[$tgt]=${#MT_TARGET[@]}
        MT_SOURCE+=( "$src" ); MT_TARGET+=( "$tgt" ); MT_FSTYPE+=( "$fs" ); MT_OPTS+=( "$opts" )
    done <"$UB_MOUNTS_FILE"
}

# mount_of <path>  -> MO_IDX = index of the mount the path lies on
MO_IDX=-1
mount_of() {
    local p="$1" i t best=-1 bl=-1
    for i in "${!MT_TARGET[@]}"; do
        t="${MT_TARGET[$i]}"
        if [[ "$t" == "/" || "$p" == "$t" || "$p" == "$t/"* ]]; then
            if (( ${#t} >= bl )); then bl=${#t}; best=$i; fi
        fi
    done
    MO_IDX=$best
}

# All mount points below a path (deepest first)
mounts_below() {
    local root="$1" t
    mounts_load
    for t in "${MT_TARGET[@]}"; do
        [[ "$t" == "$root/"* ]] && printf '%s\n' "$t"
    done | awk '{print length($0) "\t" $0}' | sort -rn | cut -f2- | awk '!seen[$0]++'
}

# --- ZFS datasets (one single zfs call) --------------------------------------
declare -gA ZDS_MP=() ZDS_CAN=() ZDS_GUID=() ZDS_REF=() ZDS_KEY=()
HAVE_ZFS="no"
zfs_load() {
    ZDS_MP=(); ZDS_CAN=(); ZDS_GUID=(); ZDS_REF=(); ZDS_KEY=()
    command -v zfs >/dev/null 2>&1 || { HAVE_ZFS="no"; return 0; }
    HAVE_ZFS="yes"
    local n mp can guid ref ks
    while IFS=$'\t' read -r n mp can guid ref ks; do
        [[ -z "$n" ]] && continue
        ZDS_MP[$n]="$mp"; ZDS_CAN[$n]="$can"; ZDS_GUID[$n]="$guid"; ZDS_REF[$n]="$ref"; ZDS_KEY[$n]="$ks"
    done < <(zfs list -H -p -t filesystem -o name,mountpoint,canmount,guid,referenced,keystatus 2>/dev/null)
}

# --- Bases (pools and array disks) and shares --------------------------------
#   INV_BASES                 names, pools alphabetically, then disk1..N
#   INV_BASE_PATH/FS/KIND/SRC  per base
#   INV_SHARES                share names
#   INV_LOCS[s]               lines "base|method|layer|subpath"
#                             method: zfs | btrfs | live
#                             layer:  ZFS dataset or btrfs root
#                             subpath: "" = the share is the dataset itself
#   INV_CHILDREN[s]           lines "base|dataset|mountpoint" (child datasets)
#   INV_METHOD[s]             snap | live | none
#   INV_LAYOUT[s]             single | overlay | split | live | none
#   INV_ID[s]                 zfs:<guid> or ino:<base>:<inode>  (for renames)
#   INV_GB[s]                 size in GB if ZFS knows it at once, else empty
#   INV_NOTE[s]               notes (one line per note)
declare -ga INV_BASES=() INV_SHARES=()
declare -gA INV_BASE_PATH=() INV_BASE_FS=() INV_BASE_KIND=() INV_BASE_SRC=()
declare -gA INV_LOCS=() INV_CHILDREN=() INV_METHOD=() INV_LAYOUT=() INV_ID=() INV_GB=() INV_NOTE=()

inv_scan() {
    mounts_load
    zfs_load
    INV_BASES=(); INV_SHARES=()
    INV_BASE_PATH=(); INV_BASE_FS=(); INV_BASE_KIND=(); INV_BASE_SRC=()
    INV_LOCS=(); INV_CHILDREN=(); INV_METHOD=(); INV_LAYOUT=(); INV_ID=(); INV_GB=(); INV_NOTE=()

    local i t fs name
    local -a pools=() disks=()
    for i in "${!MT_TARGET[@]}"; do
        t="${MT_TARGET[$i]}"; fs="${MT_FSTYPE[$i]}"
        [[ "$t" == "$UB_MNT/"* ]] || continue
        name="${t#"$UB_MNT"/}"
        [[ -z "$name" || "$name" == */* ]] && continue
        case "$name" in user|user0|disks|remotes|addons|rootshare) continue ;; esac
        [[ "$t" == "${MOUNT_ROOT:-}" || "$t" == "${VIEW_ROOT:-}" ]] && continue
        case "$fs" in zfs|btrfs|xfs|ext4|ext3|reiserfs|bcachefs|f2fs) ;; *) continue ;; esac
        if [[ -z "${INV_BASE_PATH[$name]:-}" ]]; then
            if [[ "$name" =~ ^disk[0-9]+$ ]]; then disks+=( "$name" ); else pools+=( "$name" ); fi
        fi
        INV_BASE_PATH[$name]="$t"; INV_BASE_FS[$name]="$fs"; INV_BASE_SRC[$name]="${MT_SOURCE[$i]}"
        if [[ "$name" =~ ^disk[0-9]+$ ]]; then INV_BASE_KIND[$name]="disk"; else INV_BASE_KIND[$name]="pool"; fi
    done
    mapfile -t pools < <(printf '%s\n' "${pools[@]}" | LC_ALL=C sort | sed '/^$/d')
    mapfile -t disks < <(printf '%s\n' "${disks[@]}" | sort -V | sed '/^$/d')
    INV_BASES=( "${pools[@]}" "${disks[@]}" )

    # --- Share names: folders on all bases + Unraid's share configs
    local -A names=()
    local b d s f
    for b in "${INV_BASES[@]}"; do
        for d in "${INV_BASE_PATH[$b]}"/*; do
            [[ -d "$d" && ! -L "$d" ]] || continue
            s="${d##*/}"
            [[ "$s" == "lost+found" ]] && continue
            names[$s]=1
        done
    done
    for f in "$UB_SHARES_CFG"/*.cfg; do
        [[ -f "$f" ]] || continue
        s="${f##*/}"; s="${s%.cfg}"
        names[$s]=1
    done
    mapfile -t INV_SHARES < <(printf '%s\n' "${!names[@]}" | LC_ALL=C sort | sed '/^$/d')

    # --- Where each share lies
    local p mi mt msrc mfs m layer sub ino n ds cmp nloc anylive anychild
    local -i refsum
    for s in "${INV_SHARES[@]}"; do
        INV_LOCS[$s]=""; INV_CHILDREN[$s]=""; INV_NOTE[$s]=""; INV_ID[$s]=""; INV_GB[$s]=""
        nloc=0; anylive=0; anychild=0; refsum=0
        local gb_known=1
        for b in "${INV_BASES[@]}"; do
            p="${INV_BASE_PATH[$b]}/$s"
            [[ -d "$p" && ! -L "$p" ]] || continue
            nloc=$((nloc+1))
            mount_of "$p"; mi=$MO_IDX
            mt="${MT_TARGET[$mi]}"; msrc="${MT_SOURCE[$mi]}"; mfs="${MT_FSTYPE[$mi]}"
            m="live"; layer=""; sub=""
            case "$mfs" in
                zfs)
                    layer="$msrc"; m="zfs"
                    if [[ "$mt" == "$p" ]]; then sub=""; else sub="${p#"$mt"/}"; fi
                    if [[ "${ZDS_KEY[$layer]:-}" == "unavailable" ]]; then
                        m="live"; INV_NOTE[$s]+="Dataset $layer is encrypted and locked"$'\n'
                    fi
                    if [[ -z "$sub" ]]; then
                        [[ -z "${INV_ID[$s]}" ]] && INV_ID[$s]="zfs:${ZDS_GUID[$layer]:-?}"
                        refsum+=$(( ${ZDS_REF[$layer]:-0} ))
                    else
                        gb_known=0
                    fi
                    ;;
                btrfs)
                    if [[ "$mt" != "${INV_BASE_PATH[$b]}" ]]; then
                        INV_NOTE[$s]+="$p is a mount of its own - not in the disk snapshot"$'\n'
                    elif [[ "$(stat -c %i "$p" 2>/dev/null)" == "256" ]]; then
                        INV_NOTE[$s]+="$p is a btrfs subvolume of its own - not in the disk snapshot"$'\n'
                    else
                        m="btrfs"; layer="${INV_BASE_PATH[$b]}"; sub="$s"
                    fi
                    gb_known=0
                    ;;
                *)  gb_known=0 ;;
            esac
            if [[ "$m" == "live" ]]; then
                anylive=1
                [[ "$mfs" != "zfs" && "$mfs" != "btrfs" ]] && \
                    INV_NOTE[$s]+="$b is $mfs - no snapshots possible"$'\n'
            fi
            if [[ -z "${INV_ID[$s]}" ]]; then
                ino="$(stat -c %i "$p" 2>/dev/null)"
                INV_ID[$s]="ino:$b:${ino:-?}"
            fi
            INV_LOCS[$s]+="$b|$m|$layer|$sub"$'\n'

            # Child datasets: everything mounted below the share's path
            if [[ "$m" == "zfs" && "$HAVE_ZFS" == "yes" ]]; then
                for ds in "${!ZDS_MP[@]}"; do
                    cmp="${ZDS_MP[$ds]}"
                    [[ "$cmp" == "$p/"* ]] || continue
                    [[ "${ZDS_CAN[$ds]}" == "on" ]] || continue
                    [[ "${ZDS_KEY[$ds]}" == "unavailable" ]] && continue
                    [[ -n "${MT_SET[$cmp]+x}" ]] || continue      # not mounted
                    INV_CHILDREN[$s]+="$b|$ds|$cmp"$'\n'
                    refsum+=$(( ${ZDS_REF[$ds]:-0} ))
                    anychild=1
                done
            fi
        done
        INV_CHILDREN[$s]="$(printf '%s' "${INV_CHILDREN[$s]}" | sort -t'|' -k3,3 | sed '/^$/d')"
        [[ -n "${INV_CHILDREN[$s]}" ]] && INV_CHILDREN[$s]+=$'\n'

        if (( nloc == 0 )); then
            INV_METHOD[$s]="none"; INV_LAYOUT[$s]="none"
        elif (( anylive )); then
            INV_METHOD[$s]="live"; INV_LAYOUT[$s]="live"
        else
            INV_METHOD[$s]="snap"
            if (( nloc == 1 )); then INV_LAYOUT[$s]="single"
            elif (( anychild )); then INV_LAYOUT[$s]="split"
            else INV_LAYOUT[$s]="overlay"; fi
        fi
        (( gb_known && nloc > 0 )) && INV_GB[$s]="$(to_gb "$refsum")"
    done
}

# Bases of a share as text "cache, disk5"
inv_locnames() {
    local s="$1" l out=""
    while IFS='|' read -r l _; do [[ -n "$l" ]] && out+="${out:+, }$l"; done <<<"${INV_LOCS[$s]:-}"
    printf '%s' "${out:--}"
}

# Short form for tables: pools by name, several array disks counted
#   "cache"  |  "cache + 3 disks"  |  "disk4"  |  "cache, nvme"
inv_locnames_short() {
    local s="$1" b out="" ndisk=0 onedisk=""
    while IFS='|' read -r b _; do
        [[ -z "$b" ]] && continue
        if [[ "${INV_BASE_KIND[$b]:-}" == "disk" ]]; then ndisk=$((ndisk+1)); onedisk="$b"
        else out+="${out:+, }$b"; fi
    done <<<"${INV_LOCS[$s]:-}"
    if (( ndisk == 1 )); then out+="${out:+, }$onedisk"
    elif (( ndisk > 1 )); then out+="${out:+ + }$ndisk disks"; fi
    printf '%s' "${out:--}"
}

inv_has_share() { [[ -n "${INV_METHOD[$1]+x}" ]]; }

# inv_measure <share> <timeout>  -> GB, -1 when time ran out
inv_measure() {
    local s="$1" to="${2:-600}" l b p bytes total=0
    while IFS='|' read -r b _; do
        [[ -z "$b" ]] && continue
        p="${INV_BASE_PATH[$b]}/$s"
        bytes="$(timeout "$to" du -sb "$p" 2>/dev/null | awk '{print $1}')"
        [[ -z "$bytes" ]] && { echo "-1"; return; }
        total=$(( total + bytes ))
    done <<<"${INV_LOCS[$s]:-}"
    to_gb "$total"
}

# --- Flash (/boot) ---------------------------------------------------------
#   FLASH_FS, FLASH_DATASET (if ZFS)
inv_flash() {
    local i
    FLASH_FS=""; FLASH_DATASET=""
    mount_of "$UB_BOOT"; i=$MO_IDX
    (( i >= 0 )) || return 0
    [[ "${MT_TARGET[$i]}" == "$UB_BOOT" ]] || return 0
    FLASH_FS="${MT_FSTYPE[$i]}"
    [[ "$FLASH_FS" == "zfs" ]] && FLASH_DATASET="${MT_SOURCE[$i]}"
    return 0
}

# --- Containers --------------------------------------------------------------
#   CT_NAMES             all containers
#   CT_IMAGE[n] CT_RUNNING[n] (true/false) CT_NET[n] CT_ID[n]
#   CT_BINDS[n]          lines "source|target|rw"
#   CT_VOLUMES[n]        lines "volume|target"
#   CT_HEALTH[n]         "" or healthcheck status
#   CT_ENVKEYS[n]        names of the environment variables (names only, never values)
#   CT_PORTS[n]          exposed ports, e.g. "5432/tcp 8080/tcp"
#   CT_PROJECT[n] CT_SERVICE[n]   Compose stack and service (empty for single containers)
declare -ga CT_NAMES=()
declare -gA CT_IMAGE=() CT_RUNNING=() CT_NET=() CT_ID=() CT_BINDS=() CT_VOLUMES=() CT_HEALTH=()
declare -gA CT_ENVKEYS=() CT_PORTS=() CT_PROJECT=() CT_SERVICE=()

docker_load() {
    CT_NAMES=(); CT_IMAGE=(); CT_RUNNING=(); CT_NET=(); CT_ID=(); CT_BINDS=(); CT_VOLUMES=(); CT_HEALTH=()
    CT_ENVKEYS=(); CT_PORTS=(); CT_PROJECT=(); CT_SERVICE=()
    local ids n img run net id binds vols health envk ports proj svc
    mapfile -t ids < <(docker ps -aq 2>/dev/null)
    [[ ${#ids[@]} -eq 0 ]] && return 0
    # Field separator \x1e instead of tab: bash merges consecutive tabs,
    # an empty field (e.g. no healthcheck) would shift all following ones
    while IFS=$'\x1e' read -r n img run net id health binds vols envk ports proj svc; do
        [[ -z "$n" ]] && continue
        CT_NAMES+=( "$n" )
        CT_IMAGE[$n]="$img"; CT_RUNNING[$n]="$run"; CT_NET[$n]="$net"; CT_ID[$n]="$id"
        CT_HEALTH[$n]="$health"
        CT_BINDS[$n]="${binds//$'\x1f'/$'\n'}"
        CT_VOLUMES[$n]="${vols//$'\x1f'/$'\n'}"
        CT_ENVKEYS[$n]="${envk//$'\x1f'/$'\n'}"
        CT_PORTS[$n]="$ports"
        CT_PROJECT[$n]="$proj"; CT_SERVICE[$n]="$svc"
    done < <(docker inspect "${ids[@]}" 2>/dev/null | jq -r '.[] | [
            .Name[1:],
            .Config.Image,
            (.State.Running|tostring),
            (.HostConfig.NetworkMode // ""),
            .Id,
            (.State.Health.Status // ""),
            ([.Mounts[]? | select(.Type=="bind")   | "\(.Source)|\(.Destination)|\(.RW)"] | join("\u001f")),
            ([.Mounts[]? | select(.Type=="volume") | "\(.Name)|\(.Destination)"]          | join("\u001f")),
            ([.Config.Env[]? | split("=")[0]] | join("\u001f")),
            ((.Config.ExposedPorts // {}) | keys | join(" ")),
            (.Config.Labels["com.docker.compose.project"] // ""),
            (.Config.Labels["com.docker.compose.service"] // "")
        ] | map(gsub("[\n\u001e]"; " ")) | join("\u001e")')
}

# Name of a container for an id or a name
ct_resolve() {
    local x="$1" n
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$x" || "${CT_ID[$n]}" == "$x"* ]] && { printf '%s' "$n"; return 0; }
    done
    return 1
}

# Detecting a database - by three features, because image names are free to choose:
#   1. environment variables the official server image sets itself
#      (PG_MAJOR, MARIADB_VERSION, MONGO_VERSION, REDIS_VERSION ...) - renamed or
#      derived images inherit them too
#   2. the image name (mariadb, postgres, pgvecto-rs, mongo, redis ...)
#   3. the standard port (3306, 5432, 27017, 6379)
# Tools like phpMyAdmin, Adminer or exporters do not count.
# db_detect <image> <env names> <ports>  ->  "kind|feature"
#   kind: mariadb | postgres | mongodb  (dump possible)
#         cache                         (Redis & co. - a cache, no dump)
#         other                         (InfluxDB, CouchDB ... - backed up through the snapshot)
db_detect() {
    local img="${1,,}" envk="$2" ports=" ${3:-} " base k
    base="${img##*/}"
    case "$base" in
        *phpmyadmin*|*adminer*|*exporter*|*pgadmin*|*mongo-express*|*redis-commander*|*redisinsight*|*backup*|*workbench*|*pgbouncer*)
            echo ""; return ;;
    esac
    for k in MARIADB_VERSION MARIADB_MAJOR MYSQL_MAJOR MYSQL_VERSION; do
        grep -qx "$k" <<<"$envk" && { echo "mariadb|$k"; return; }
    done
    for k in PG_MAJOR PG_VERSION PGDATA; do
        grep -qx "$k" <<<"$envk" && { echo "postgres|$k"; return; }
    done
    for k in MONGO_VERSION MONGO_MAJOR; do
        grep -qx "$k" <<<"$envk" && { echo "mongodb|$k"; return; }
    done
    for k in REDIS_VERSION VALKEY_VERSION KEYDB_VERSION; do
        grep -qx "$k" <<<"$envk" && { echo "cache|$k"; return; }
    done
    case "$base" in
        *mariadb*|*mysql*|*percona*)                                          echo "mariadb|Image"; return ;;
        *postgres*|*postgis*|*pgvecto*|*vectorchord*|*timescale*)             echo "postgres|Image"; return ;;
        *mongo*)                                                              echo "mongodb|Image"; return ;;
        *redis*|*valkey*|*keydb*|*memcached*|*dragonfly*)                     echo "cache|Image"; return ;;
        *influxdb*|*couchdb*|*clickhouse*|*elasticsearch*|*opensearch*|*cassandra*|*neo4j*|*questdb*|*victoria-metrics*|*qdrant*|*meilisearch*|*typesense*|*surrealdb*)
                                                                              echo "other|Image"; return ;;
    esac
    case "$ports" in
        *" 3306/tcp "*)  echo "mariadb|Port 3306"; return ;;
        *" 5432/tcp "*)  echo "postgres|Port 5432"; return ;;
        *" 27017/tcp "*) echo "mongodb|Port 27017"; return ;;
        *" 6379/tcp "*)  echo "cache|Port 6379"; return ;;
    esac
    echo ""
}
db_dumpable() { [[ "$1" == "mariadb" || "$1" == "postgres" || "$1" == "mongodb" ]]; }
ct_db() { db_detect "${CT_IMAGE[$1]:-}" "${CT_ENVKEYS[$1]:-}" "${CT_PORTS[$1]:-}"; }
# Database kind of a container (without the feature), empty = no database
ct_db_type() { local r; r="$(ct_db "$1")"; printf '%s' "${r%%|*}"; }

is_nextcloud_image() {
    local img="${1,,}"
    [[ "$img" == *nextcloud* && "$img" != *aio-mastercontainer* && "$img" != *db* \
       && "$img" != *redis* && "$img" != *postgres* && "$img" != *mariadb* ]]
}

# --- Compose stacks ------------------------------------------------------------
# Reads the Compose Manager's stacks (also those not running right now)
# and reports their database services. What counts is what "docker compose
# config" makes of them - so .env and variables included.
#   COMPOSE_DB  lines "stack|service|image|kind|feature|container_name"
#   COMPOSE_ERR lines "stack|reason" (stack could not be read)
declare -ga COMPOSE_DB=() COMPOSE_ERR=()
compose_scan() {
    COMPOSE_DB=(); COMPOSE_ERR=()
    local root="$UB_BOOT/config/plugins/compose.manager/projects" d dir f js stack svc img envk ports cname r
    [[ -d "$root" ]] || return 0
    docker compose version >/dev/null 2>&1 || { COMPOSE_ERR+=( "*|docker compose is missing" ); return 0; }
    for d in "$root"/*/; do
        [[ -d "$d" ]] || continue
        stack="$(basename "$d")"; dir="${d%/}"
        # "indirect": the stack lives in another folder
        [[ -s "$dir/indirect" ]] && dir="$(head -1 "$dir/indirect" | tr -d '\r')"
        f=""
        for r in compose.yaml compose.yml docker-compose.yml docker-compose.yaml; do
            [[ -f "$dir/$r" ]] && { f="$dir/$r"; break; }
        done
        [[ -n "$f" ]] || { COMPOSE_ERR+=( "$stack|no compose file in $dir" ); continue; }
        js="$(cd "$dir" && timeout 30 docker compose --project-directory "$dir" -f "$f" config --format json 2>/dev/null)" \
            || { COMPOSE_ERR+=( "$stack|docker compose config fails (missing variables?)" ); continue; }
        [[ -s "$dir/name" ]] && stack="$(head -1 "$dir/name" | tr -d '\r')"
        while IFS=$'\x1e' read -r svc img envk ports cname; do
            [[ -z "$svc" ]] && continue
            r="$(db_detect "$img" "${envk//$'\x1f'/$'\n'}" "$ports")"
            [[ -n "$r" ]] && COMPOSE_DB+=( "$stack|$svc|$img|${r%%|*}|${r#*|}|$cname" )
        done < <(jq -r '.name as $p | .services | to_entries[] | [
                    .key,
                    (.value.image // ""),
                    ((.value.environment // {}) | keys | join("\u001f")),
                    ([(.value.ports // [])[] | "\(.target)/\(.protocol // "tcp")"] | join(" ")),
                    (.value.container_name // "")
                 ] | map(gsub("[\n\u001e]"; " ")) | join("\u001e")' <<<"$js" 2>/dev/null)
    done
}

# Find the container of a Compose service (label or container_name)
compose_container() { # compose_container <stack> <service> <container_name>
    local n
    if [[ -n "$3" ]] && in_list "$3" "${CT_NAMES[@]}"; then printf '%s' "$3"; return 0; fi
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_SERVICE[$n]}" == "$2" && ( "${CT_PROJECT[$n]}" == "$1" || "${CT_PROJECT[$n]}" == "${1,,}" ) ]] \
            && { printf '%s' "$n"; return 0; }
    done
    return 1
}

is_media_server() { [[ "${1,,}" =~ (emby|jellyfin|plex) ]]; }
is_kopia_image() { [[ "${1,,}" == *kopia* ]]; }

##############################################################################
# 4. Plan
##############################################################################
#   PLAN_KOPIA       shares that go to Kopia (mode=kopia and data present)
#   PLAN_SNAP        shares with mode=kopia|snapshot and a snapshot method
#   PLAN_ZFS         datasets that get snapshotted
#   PLAN_ZFS_RET[ds] retention "d w m" (maximum over all shares)
#   PLAN_BTRFS       bases (paths) that get a btrfs snapshot
#   PLAN_EXCL[ds]    1 = child dataset left out on purpose
#   PLAN_FLASH       snapshot | tar | off  (what really applies)
declare -ga PLAN_KOPIA=() PLAN_SNAP=() PLAN_ZFS=() PLAN_BTRFS=()
declare -gA PLAN_ZFS_RET=() PLAN_EXCL=()
PLAN_FLASH="off"

_ret_max() { # _ret_max "a b c" "x y z"
    local a1 a2 a3 b1 b2 b3
    read -r a1 a2 a3 <<<"$1"; read -r b1 b2 b3 <<<"$2"
    echo "$(( a1 > b1 ? a1 : b1 )) $(( a2 > b2 ? a2 : b2 )) $(( a3 > b3 ? a3 : b3 ))"
}

share_mode()      { cfg "share|$1|mode" "off"; }

# Will this share do for dumps and archives? Prints a code, nothing when it will:
#   missing (none set)  unknown (no such share)  forbidden (appdata/system/domains:
#   backups don't belong there)  off (the share itself isn't backed up)
# $2: the share's mode, if not the one in settings.ini (setup passes its draft)
dumps_share_problem() {
    local share="$1" mode="${2-}"
    [[ -z "$share" ]] && { echo missing; return; }
    case "${share,,}" in appdata|system|domains) echo forbidden; return ;; esac
    [[ -d "$UB_MNT/user/$share" || -f "$UB_BOOT/config/shares/$share.cfg" ]] || { echo unknown; return; }
    [[ -z "$mode" ]] && mode="$(share_mode "$share")"
    [[ "$mode" == "off" ]] && { echo off; return; }
    return 0
}
# dumps_path <share>  -> the folder for dumps and archives in that share (empty without a share)
dumps_path() {
    [[ -n "$1" ]] || return 0
    if [[ "$1" == "$UB_OFFICE_SHARE" ]]; then printf '%s' "$UB_MNT/user/$1/$UB_DESK_DIR"
    else printf '%s' "$UB_MNT/user/$1/$UB_NAME"; fi
}
dumps_share_text() { # dumps_share_text <code> <share>
    case "$1" in
        missing)   echo "No backup place set (general|dumps_share): dumps and archives need a backup share of their own - such things do not belong in appdata" ;;
        unknown)   echo "Backup place '$2' does not exist as a share" ;;
        forbidden) echo "Backup place '$2' will not do: backups do not belong in appdata, system or domains" ;;
        off)       echo "Backup place '$2' is not backed up itself (mode=off) - the dumps would be backed up nowhere" ;;
    esac
}
share_retention() { cfg "share|$1|retention" "$ZFS_RETENTION"; }
share_method()    { # the method that really applies
    local s="$1"
    inv_has_share "$s" || { echo "none"; return; }
    if [[ "$(cfg "share|$s|method" auto)" == "live" && "${INV_METHOD[$s]}" != "none" ]]; then
        echo "live"
    else
        echo "${INV_METHOD[$s]}"
    fi
}

plan_build() {
    PLAN_KOPIA=(); PLAN_SNAP=(); PLAN_ZFS=(); PLAN_BTRFS=(); PLAN_ZFS_RET=(); PLAN_EXCL=()
    local s mode meth b m layer sub ret line ds mp
    local -A seen_b=() seen_ds=()
    local -a excl
    while IFS= read -r s; do
        [[ -z "$s" ]] && continue
        mode="$(share_mode "$s")"
        [[ "$mode" == "off" ]] && continue
        meth="$(share_method "$s")"
        [[ "$meth" == "none" ]] && continue
        # mode=kopia with Kopia switched off = local snapshot only
        [[ "$mode" == "kopia" ]] && is_yes "$KOPIA_ENABLED" && PLAN_KOPIA+=( "$s" )
        [[ "$meth" == "snap" ]] || continue
        PLAN_SNAP+=( "$s" )
        ret="$(share_retention "$s")"
        mapfile -t excl < <(cfg_list "share|$s|exclude_dataset")
        while IFS='|' read -r b m layer sub; do
            [[ -z "$b" ]] && continue
            case "$m" in
                zfs)
                    if [[ -z "${seen_ds[$layer]:-}" ]]; then seen_ds[$layer]=1; PLAN_ZFS+=( "$layer" ); PLAN_ZFS_RET[$layer]="$ret"
                    else PLAN_ZFS_RET[$layer]="$(_ret_max "${PLAN_ZFS_RET[$layer]}" "$ret")"; fi
                    ;;
                btrfs)
                    [[ -z "${seen_b[$layer]:-}" ]] && { seen_b[$layer]=1; PLAN_BTRFS+=( "$layer" ); }
                    ;;
            esac
        done <<<"${INV_LOCS[$s]}"
        while IFS='|' read -r b ds mp; do
            [[ -z "$ds" ]] && continue
            if in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}"; then
                PLAN_EXCL[$ds]=1; continue
            fi
            if [[ -z "${seen_ds[$ds]:-}" ]]; then seen_ds[$ds]=1; PLAN_ZFS+=( "$ds" ); PLAN_ZFS_RET[$ds]="$ret"
            else PLAN_ZFS_RET[$ds]="$(_ret_max "${PLAN_ZFS_RET[$ds]}" "$ret")"; fi
        done <<<"${INV_CHILDREN[$s]}"
    done < <(cfg_names share)

    if is_yes "$BTRFS_SNAPSHOT_ALL"; then
        for b in "${INV_BASES[@]}"; do
            [[ "${INV_BASE_KIND[$b]}" == "disk" && "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
            [[ -z "${seen_b[${INV_BASE_PATH[$b]}]:-}" ]] && { seen_b[${INV_BASE_PATH[$b]}]=1; PLAN_BTRFS+=( "${INV_BASE_PATH[$b]}" ); }
        done
    fi

    inv_flash
    PLAN_FLASH="$FLASH_MODE"
    if [[ "$PLAN_FLASH" == "snapshot" && -z "$FLASH_DATASET" ]]; then PLAN_FLASH="tar"; fi
}

_parent_excluded() { # does the dataset lie below an excluded one?
    local ds="$1"; shift
    local e; for e in "$@"; do [[ -n "$e" && "$ds" == "$e/"* ]] && return 0; done; return 1
}

# Child datasets of a share that get mounted (without excluded ones)
plan_children() {
    local s="$1" b ds mp
    while IFS='|' read -r b ds mp; do
        [[ -z "$ds" || -n "${PLAN_EXCL[$ds]:-}" ]] && continue
        printf '%s|%s|%s\n' "$b" "$ds" "$mp"
    done <<<"${INV_CHILDREN[$s]:-}"
}

##############################################################################
# 5. Kopia
##############################################################################
#   KM_SRC/KM_DST/KM_RW/KM_PROP   bind mounts of the Kopia container
#   KOPIA_RUNNING                 yes/no
#   KOPIA_CONNECTED               yes/no
#   KOPIA_ID                      user@host as the repository config says
#   KOPIA_USER, KOPIA_HOST
declare -ga KM_SRC=() KM_DST=() KM_RW=() KM_PROP=()
KOPIA_RUNNING="no"; KOPIA_CONNECTED="no"; KOPIA_ID=""; KOPIA_USER=""; KOPIA_HOST=""
KOPIA_VERSION=""; KOPIA_CONFIG_FILE=""; KOPIA_STORAGE=""; KP_JSON="[]"
KOPIA_SERVER_UID=""     # UID the Kopia server runs as inside the container
KOPIA_RUN_UID="0"       # UID for docker exec (= server UID, so that cache/logs belong to it)

# Call Kopia inside the container - always as the same user as the server.
# Every Kopia call (even "repository status") writes to cache and logs.
# If it ran as root while the server runs as e.g. UID 99, root-owned
# cache folders (0700) would appear - the server could no longer open
# the repository afterwards ("permission denied"). Snapshots need root, though,
# to read every file -> the server has to run as root as well.
kopia_x() {
    local e=()
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" kopia --no-progress "$@"
}

# UID of the Kopia server: from the process list, otherwise the owner of the config file
kopia_detect_uid() {
    local u cfgp
    u="$(docker top "$KOPIA_CONTAINER" -eo pid,uid,args 2>/dev/null \
         | awk '($3 ~ /(^|\/)kopia$/) && $4 == "server" {print $2; exit}')"
    if [[ -z "$u" ]]; then
        cfgp="$(docker exec "$KOPIA_CONTAINER" sh -c 'printf %s "${KOPIA_CONFIG_PATH:-$HOME/.config/kopia/repository.config}"' 2>/dev/null)"
        u="$(docker exec "$KOPIA_CONTAINER" stat -c %u "$cfgp" 2>/dev/null)"
    fi
    KOPIA_SERVER_UID="${u:-0}"
    KOPIA_RUN_UID="$KOPIA_SERVER_UID"
}

# Find the Kopia container on its own when settings.ini names none
kopia_find_container() {
    local n found=""
    for n in "${CT_NAMES[@]}"; do
        is_kopia_image "${CT_IMAGE[$n]}" || continue
        [[ -n "$found" ]] && { printf '%s' "$found"; return 2; }   # several -> the first, but report it
        found="$n"
    done
    printf '%s' "$found"
    [[ -n "$found" ]]
}

kopia_mounts_load() {
    KM_SRC=(); KM_DST=(); KM_RW=(); KM_PROP=()
    local s d rw pr
    while IFS=$'\x1e' read -r s d rw pr; do
        [[ -z "$s" ]] && continue
        s="${s%/}"; [[ -z "$s" ]] && s="/"
        d="${d%/}"; [[ -z "$d" ]] && d="/"
        KM_SRC+=( "$s" ); KM_DST+=( "$d" ); KM_RW+=( "$rw" ); KM_PROP+=( "$pr" )
    done < <(docker inspect -f '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\x1e"}}{{.Destination}}{{"\x1e"}}{{.RW}}{{"\x1e"}}{{.Propagation}}{{"\n"}}{{end}}{{end}}' \
              "$KOPIA_CONTAINER" 2>/dev/null)
}

# k_map <host path>  -> KMAP_IDX (the mapping that covers the path), -1 if none
KMAP_IDX=-1
k_map() {
    local p="$1" i s bl=-1
    KMAP_IDX=-1
    for i in "${!KM_SRC[@]}"; do
        s="${KM_SRC[$i]}"
        if [[ "$s" == "/" || "$p" == "$s" || "$p" == "$s/"* ]]; then
            (( ${#s} > bl )) && { bl=${#s}; KMAP_IDX=$i; }
        fi
    done
    (( KMAP_IDX >= 0 ))
}

# k_path <host path>  -> path inside the container (returns 1 if not mapped)
k_path() {
    local p="$1" s d rest
    k_map "$p" || return 1
    s="${KM_SRC[$KMAP_IDX]}"; d="${KM_DST[$KMAP_IDX]}"
    if [[ "$s" == "/" ]]; then rest="$p"; else rest="${p#"$s"}"; fi
    if [[ "$d" == "/" ]]; then printf '%s' "${rest:-/}"; else printf '%s' "$d$rest"; fi
}

kopia_status_load() {
    KOPIA_RUNNING="no"; KOPIA_CONNECTED="no"; KOPIA_ID=""; KOPIA_USER=""; KOPIA_HOST=""
    KOPIA_VERSION=""; KOPIA_CONFIG_FILE=""; KOPIA_STORAGE=""
    [[ -n "$KOPIA_CONTAINER" ]] || return 1
    [[ "$(docker inspect -f '{{.State.Running}}' "$KOPIA_CONTAINER" 2>/dev/null)" == "true" ]] || return 1
    KOPIA_RUNNING="yes"
    kopia_detect_uid
    KOPIA_VERSION="$(kopia_x --version 2>/dev/null | awk 'NR==1{print $1}')"
    local js
    js="$(kopia_x repository status --json 2>/dev/null)" || return 1
    [[ -n "$js" ]] || return 1
    KOPIA_USER="$(jq -r '.clientOptions.username // ""' <<<"$js")"
    KOPIA_HOST="$(jq -r '.clientOptions.hostname // ""' <<<"$js")"
    KOPIA_CONFIG_FILE="$(jq -r '.configFile // ""' <<<"$js")"
    KOPIA_STORAGE="$(jq -r '.storage.type // ""' <<<"$js")"
    [[ -n "$KOPIA_USER" && -n "$KOPIA_HOST" ]] || return 1
    KOPIA_ID="$KOPIA_USER@$KOPIA_HOST"
    KOPIA_CONNECTED="yes"
}

kopia_policies_load() {
    KP_JSON="$(kopia_x policy list --json 2>/dev/null)"
    jq -e 'type=="array"' >/dev/null 2>&1 <<<"$KP_JSON" || KP_JSON="[]"
}

# Host path under which Kopia reads a share - always <mount_root>/<share>,
# also for "live" (then a read-only bind of /mnt/user/<share>)
share_kopia_hostpath() { printf '%s' "$MOUNT_ROOT/$1"; }
FLASH_SOURCE_NAME="_flash"

# Wanted ignore list of a target
#   root    -> the global list from [kopia] (inherited by all shares)
#   share   -> only the share's own rules
kopia_want_ignores() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)  printf '%s\n' "${KOPIA_IGNORE[@]}" ;;
        share) cfg_list "share|$s|kopia_ignore" ;;
        flash) printf '%s\n' "${FLASH_KOPIA_IGNORE[@]}" ;;
    esac | sed '/^$/d' | LC_ALL=C sort -u
}

# Wanted retention of a target: six values (latest hourly daily weekly monthly annual)
kopia_want_retention() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)  echo "$KOPIA_KEEP_LATEST $KOPIA_KEEP_HOURLY $KOPIA_KEEP_DAILY $KOPIA_KEEP_WEEKLY $KOPIA_KEEP_MONTHLY $KOPIA_KEEP_ANNUAL" ;;
        share) cfg "share|$s|kopia_retention" "inherit inherit inherit inherit inherit inherit" ;;
        *)     echo "inherit inherit inherit inherit inherit inherit" ;;
    esac
}

# kopia_policy_eval <container path> <kind: root|share|flash> <wanted ignores> <wanted retention>
#   Compares the policy stored in Kopia with what is wanted. Only the target
#   "root" (<mount_root>) carries schedule, one-file-system and compression;
#   the shares inherit that and carry only their differences.
#   KP_DIFF      text lines with the differences
#   KP_ARGS      arguments for "kopia policy set" that make it as wanted
#   KP_MISSING   ignore rules missing in Kopia (Kopia would see more than wanted)
#   KP_CODES     the same differences for other programs: lines "what<US>item<US>have<US>want"
#                what: ignore_missing | ignore_extra | retention | schedule | one_file_system | compression
#   Returns 0 = matches, 1 = differs
kopia_policy_eval() {
    local target="$1" kind="$2" want_ign="$3" want_ret="$4" cur cur_ign x f want have
    local -a wr
    read -r -a wr <<<"$want_ret"
    KP_DIFF=""; KP_ARGS=(); KP_MISSING=""; KP_CODES=()
    cur="$(jq -c --arg p "$target" --arg u "$KOPIA_USER" --arg h "$KOPIA_HOST" \
            'first(.[] | select(.target.path==$p and .target.userName==$u and .target.host==$h)) // {}' \
            <<<"$KP_JSON")"
    cur_ign="$(jq -r '.files.ignore[]?' <<<"$cur" | LC_ALL=C sort -u)"
    while IFS= read -r x; do
        [[ -z "$x" ]] && continue
        if ! grep -Fxq -- "$x" <<<"$cur_ign"; then
            KP_DIFF+="  ignore missing in Kopia: $x"$'\n'; KP_ARGS+=( --add-ignore "$x" ); KP_MISSING+="$x"$'\n'
            KP_CODES+=( "ignore_missing"$'\x1f'"$x"$'\x1f\x1f' )
        fi
    done <<<"$want_ign"
    while IFS= read -r x; do
        [[ -z "$x" ]] && continue
        if ! grep -Fxq -- "$x" <<<"$want_ign"; then
            KP_DIFF+="  extra ignore in Kopia: $x"$'\n'; KP_ARGS+=( --remove-ignore "$x" )
            KP_CODES+=( "ignore_extra"$'\x1f'"$x"$'\x1f\x1f' )
        fi
    done <<<"$cur_ign"
    local i=0
    for f in keepLatest:keep-latest keepHourly:keep-hourly keepDaily:keep-daily \
             keepWeekly:keep-weekly keepMonthly:keep-monthly keepAnnual:keep-annual; do
        x="${f%%:*}"; f="${f#*:}"; want="${wr[$i]:-inherit}"; i=$((i+1))
        have="$(jq -r --arg k "$x" '.retention[$k] // "inherit" | tostring' <<<"$cur")"
        if [[ "$have" != "$want" ]]; then
            KP_DIFF+="  $f: Kopia $have, wanted $want"$'\n'; KP_ARGS+=( "--$f=$want" )
            KP_CODES+=( "retention"$'\x1f'"$f"$'\x1f'"$have"$'\x1f'"$want" )
        fi
    done
    if [[ "$kind" == "root" ]]; then
        have="$(jq -r '.scheduling.manual // false | tostring' <<<"$cur")"
        if [[ "$have" != "true" ]]; then
            KP_DIFF+="  schedule: Kopia may schedule these sources itself (wanted: manual only)"$'\n'
            KP_ARGS+=( --manual )
            KP_CODES+=( "schedule"$'\x1f\x1f'"$have"$'\x1f'"true" )
        fi
        # Careful with jq: "//" also treats false as "missing" - so check for null explicitly
        have="$(jq -r 'if .files.oneFileSystem == null then "inherit" else (.files.oneFileSystem|tostring) end' <<<"$cur")"
        if [[ "$have" != "false" ]]; then
            KP_DIFF+="  one-file-system: Kopia $have, wanted false (child datasets must come along)"$'\n'
            KP_ARGS+=( --one-file-system=false )
            KP_CODES+=( "one_file_system"$'\x1f\x1f'"$have"$'\x1f'"false" )
        fi
        have="$(jq -r '.compression.compressorName // "inherit"' <<<"$cur")"
        if [[ "$KOPIA_COMPRESSION" != "inherit" && "$have" != "$KOPIA_COMPRESSION" ]] || \
           [[ "$KOPIA_COMPRESSION" == "inherit" && "$have" != "inherit" ]]; then
            KP_DIFF+="  compression: Kopia $have, wanted $KOPIA_COMPRESSION"$'\n'
            KP_ARGS+=( "--compression=$KOPIA_COMPRESSION" )
            KP_CODES+=( "compression"$'\x1f\x1f'"$have"$'\x1f'"$KOPIA_COMPRESSION" )
        fi
    fi
    [[ -z "$KP_DIFF" ]]
}

# All Kopia targets this script manages, with their kind:
#   lines "kind|host path|share"   kind: root | share | flash
kopia_targets() {
    local s
    is_yes "$KOPIA_ENABLED" || return 0
    printf 'root|%s|\n' "$MOUNT_ROOT"
    for s in "${PLAN_KOPIA[@]}"; do printf 'share|%s|%s\n' "$(share_kopia_hostpath "$s")" "$s"; done
    [[ "$PLAN_FLASH" == "snapshot" ]] && printf 'flash|%s|\n' "$MOUNT_ROOT/$FLASH_SOURCE_NAME"
    return 0
}

# All sources in the repository (user@host:path), one line each
kopia_sources() {
    kopia_x snapshot list --all --json -n 1 2>/dev/null \
        | jq -r '.[]? | "\(.source.userName)@\(.source.host):\(.source.path)"' 2>/dev/null | LC_ALL=C sort -u
}

# Mountinfo inside the container -> KMI[path]=options
declare -gA KMI=()
kopia_mountinfo_load() {
    KMI=()
    local mp opts
    while IFS=$'\t' read -r mp opts; do
        [[ "$mp" == *\\* ]] && mp="$(printf '%b' "$mp")"
        KMI[$mp]="$opts"
    done < <(docker exec "$KOPIA_CONTAINER" cat /proc/self/mountinfo 2>/dev/null | awk '{print $5 "\t" $6}')
}

# Test: does the running container see a mount that appears on the host now?
#   0 = yes, 1 = no, 2 = test not possible
kopia_probe_propagation() {
    local probe="$MOUNT_ROOT/.ub-probe" cp res
    mkdir -p "$probe" 2>/dev/null || return 2
    mountpoint -q "$probe" && umount "$probe" 2>/dev/null
    mount -t tmpfs -o size=64k,mode=0755 ub-probe "$probe" 2>/dev/null || { rmdir "$probe" 2>/dev/null; return 2; }
    echo "ub-$$" >"$probe/marker"
    cp="$(k_path "$probe")" || { umount "$probe"; rmdir "$probe" 2>/dev/null; return 2; }
    res="$(docker exec "$KOPIA_CONTAINER" cat "$cp/marker" 2>/dev/null)"
    umount "$probe" 2>/dev/null; rmdir "$probe" 2>/dev/null
    [[ "$res" == "ub-$$" ]]
}

# If an earlier run was killed hard (kill -9, crash), state/ lists
# the containers it had stopped and the Nextclouds it had put into
# maintenance mode. Both are brought back here.
# Call only while holding the lock (then no other run is going).
recover_interrupted_run() {
    local n occ u list=""
    if [[ -s "$UB_STATE/stopped" ]]; then
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            [[ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" == "false" ]] || continue
            if docker start "$n" >/dev/null 2>&1; then list+="$n "; else err "Container '$n' (from the aborted run) does not start"; fi
        done <"$UB_STATE/stopped"
        rm -f "$UB_STATE/stopped"
    fi
    if [[ -s "$UB_STATE/maintenance" ]]; then
        sleep 5
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            for occ in /var/www/html/occ /app/www/public/occ /config/www/nextcloud/occ /var/www/nextcloud/occ; do
                docker exec "$n" test -f "$occ" 2>/dev/null || continue
                u="$(docker exec "$n" stat -c %U "$occ" 2>/dev/null)"; [[ -z "$u" || "$u" == root || "$u" == UNKNOWN ]] && u="www-data"
                docker exec -u "$u" "$n" php "$occ" maintenance:mode --off >/dev/null 2>&1 \
                    && list+="maintenance mode $n off " || err "Maintenance mode of '$n' could not be switched off"
                break
            done
        done <"$UB_STATE/maintenance"
        rm -f "$UB_STATE/maintenance"
    fi
    if [[ -n "$list" ]]; then
        warn "An earlier run was aborted - restored: $list"
        ub_notify "Aborted run repaired" "Started again or reset: $list" "warning"
    fi
    return 0
}

##############################################################################
# 6. Drift (inventory <-> settings.ini)
##############################################################################
#   DRIFT        lines "level|text"   level: info | warn | error
#   SKIP_KOPIA[share]=reason   shares Kopia must not back up this time
declare -ga DRIFT=()
declare -gA SKIP_KOPIA=()
drift_add() { DRIFT+=( "$1|$2" ); }

drift_check_shares() {
    local s mode meth id loc_now loc_cfg o
    local -A cfg_has=() missing_by_id=() renamed=()
    while IFS= read -r s; do [[ -n "$s" ]] && cfg_has[$s]=1; done < <(cfg_names share)

    # In settings.ini but without data -> candidates for "deleted" or "renamed"
    for s in "${!cfg_has[@]}"; do
        if ! inv_has_share "$s" || [[ "${INV_METHOD[$s]}" == "none" ]]; then
            id="$(cfg "share|$s|id")"
            [[ -n "$id" && "$id" != *":?" ]] && missing_by_id[$id]="$s"
        fi
    done

    # New or renamed
    for s in "${INV_SHARES[@]}"; do
        [[ "${INV_METHOD[$s]}" == "none" || -n "${cfg_has[$s]:-}" ]] && continue
        o=""
        [[ -n "${INV_ID[$s]:-}" ]] && o="${missing_by_id[${INV_ID[$s]}]:-}"
        if [[ -n "$o" ]]; then
            renamed[$o]="$s"
            drift_add warn "Share '$o' was renamed to '$s' (same id ${INV_ID[$s]%%:*}) - '$s' is only backed up after setup.sh"
            continue
        fi
        matches_any "$s" "${DRIFT_IGNORE[@]}" && continue
        drift_add warn "New share '$s' ($(inv_locnames "$s")${INV_GB[$s]:+, ${INV_GB[$s]} GB}) - not in settings.ini, not backed up"
    done

    # Deleted or empty
    for s in "${!cfg_has[@]}"; do
        [[ -n "${renamed[$s]:-}" ]] && continue
        mode="$(share_mode "$s")"
        if ! inv_has_share "$s"; then
            # neither data nor Unraid config
            if [[ "$mode" == "off" ]]; then
                drift_add info "Share '$s' no longer exists (was set to off)"
            else
                drift_add warn "Share '$s' no longer exists (mode=$mode)"
            fi
        elif [[ "${INV_METHOD[$s]}" == "none" && "$mode" != "off" ]]; then
            # only left in Unraid's config. If it was empty at setup already (no
            # id), that is nothing new - otherwise the data is gone.
            if [[ -n "$(cfg "share|$s|id")" ]]; then
                drift_add warn "Share '$s' has no data any more (so far on $(cfg "share|$s|locations" "?"), mode=$mode)"
            fi
        elif [[ "${INV_METHOD[$s]}" != "none" && "$mode" != "off" && -z "$(cfg "share|$s|id")" ]]; then
            drift_add info "Share '$s' has data now and is backed up - setup.sh then remembers its id (to spot renames)"
        fi
    done

    for s in "${INV_SHARES[@]}"; do
        [[ "${INV_METHOD[$s]}" == "none" || -z "${cfg_has[$s]:-}" ]] && continue
        share_name_ok "$s" || { drift_add warn "Share '$s': the name contains @ : \" or | - not backed up"; continue; }
        mode="$(share_mode "$s")"
        [[ "$mode" == "off" ]] && continue
        meth="$(share_method "$s")"
        loc_now="$(inv_locnames "$s")"; loc_cfg="$(cfg "share|$s|locations")"
        if [[ -n "$loc_cfg" && "$loc_cfg" != "$loc_now" ]]; then
            drift_add info "Share '$s' now lies on: $loc_now (settings.ini says: $loc_cfg) - taken along automatically"
        fi
        if [[ "$meth" == "live" && "$(cfg "share|$s|method" auto)" != "live" ]]; then
            drift_add warn "Share '$s' cannot be backed up with a snapshot ($(printf '%s' "${INV_NOTE[$s]}" | head -1)) - Kopia reads it live"
        fi
        if [[ "$mode" == "snapshot" && "$meth" == "live" ]]; then
            drift_add warn "Share '$s' is set to mode=snapshot but lies on a file system without snapshots"
        fi
        if [[ "${INV_LAYOUT[$s]}" == "split" ]]; then
            drift_add info "Share '$s' lies on several bases and has child datasets - Kopia sees one subfolder per base"
        fi
    done
}

drift_check_containers() {
    local n t img
    local -A known=() referenced=()
    for n in "${DOCKER_KNOWN[@]}"; do known[$n]=1; done
    for n in "${CT_NAMES[@]}"; do
        img="${CT_IMAGE[$n]}"
        if [[ -z "${known[$n]:-}" ]]; then
            t="$(ct_db_type "$n")"
            if db_dumpable "$t" && ! cfg_has "dump|$n"; then
                drift_add warn "New database container '$n' ($t, $img${CT_PROJECT[$n]:+, stack ${CT_PROJECT[$n]}}) - no dump set up (the raw data is in the snapshot)"
            elif is_nextcloud_image "$img" && ! cfg_has "nextcloud|$n"; then
                drift_add warn "New Nextcloud container '$n' - no maintenance mode set up"
            else
                drift_add info "New container '$n' ($img)"
            fi
        fi
        if [[ -n "${CT_VOLUMES[$n]}" && -z "${known[$n]:-}" ]]; then
            drift_add warn "Container '$n' uses Docker volumes ($(cut -d'|' -f1 <<<"${CT_VOLUMES[$n]}" | paste -sd, -)) - they live in the Docker image and are NOT in the backup"
        fi
    done
    for n in $(cfg_names dump) $(cfg_names nextcloud) "${DOCKER_NO_STOP[@]}"; do
        [[ -n "${referenced[$n]:-}" ]] && continue
        referenced[$n]=1
        in_list "$n" "${CT_NAMES[@]}" || drift_add warn "Container '$n' is in settings.ini but does not exist"
    done
}

# Kopia check for the run; sets KOPIA_OK and SKIP_KOPIA
#   KP_STATUS    one JSON object per Kopia target (for drift.json): kind, share, path,
#                ok, skipped (the run leaves the share out), differences as codes
#   KP_CHECKED   yes once the policies were compared (Kopia reachable)
KOPIA_OK="no"
declare -ga KP_STATUS=()
KP_CHECKED="no"
kp_status_add() { # kp_status_add <kind> <share> <container path> <ok 1/0> <skipped 1/0>
    local diffs
    diffs="$(printf '%s\n' "${KP_CODES[@]}" | jq -R 'select(length > 0) | split("\u001f")
        | {what: .[0], item: (.[1] // ""), have: (.[2] // ""), want: (.[3] // "")}' | jq -sc .)" || diffs='[]'
    KP_STATUS+=( "$(jq -nc --arg k "$1" --arg s "$2" --arg p "$3" --arg ok "$4" --arg sk "$5" --argjson d "${diffs:-[]}" \
        '{kind: $k, share: $s, path: $p, ok: ($ok == "1"), skipped: ($sk == "1"), differences: $d}')" )
}
drift_check_kopia() {
    KOPIA_OK="no"; KP_STATUS=(); KP_CHECKED="no"
    is_yes "$KOPIA_ENABLED" || { KOPIA_OK="off"; return 0; }
    [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" ]] && { KOPIA_OK="none"; return 0; }
    if [[ -z "$KOPIA_CONTAINER" ]]; then
        drift_add error "No Kopia container in settings.ini"; return 1
    fi
    if ! in_list "$KOPIA_CONTAINER" "${CT_NAMES[@]}"; then
        drift_add error "Kopia container '$KOPIA_CONTAINER' does not exist"; return 1
    fi
    kopia_mounts_load
    if ! k_map "$MOUNT_ROOT"; then
        drift_add error "The Kopia container does not map $MOUNT_ROOT (expected: $MOUNT_ROOT -> $MOUNT_ROOT, Read Only - Slave)"; return 1
    fi
    case "${KM_PROP[$KMAP_IDX]}" in
        slave|rslave|shared|rshared) ;;
        *) drift_add error "Mapping ${KM_SRC[$KMAP_IDX]} in the Kopia container has propagation '${KM_PROP[$KMAP_IDX]:-rprivate}' - new snapshot mounts stay invisible. Set the access mode to 'Read Only - Slave'"
           return 1 ;;
    esac
    [[ "${KM_RW[$KMAP_IDX]}" == "true" ]] && \
        drift_add warn "Mapping ${KM_SRC[$KMAP_IDX]} in the Kopia container is writable - 'Read Only - Slave' recommended"
    if ! kopia_status_load; then
        if [[ "$KOPIA_RUNNING" != "yes" ]]; then drift_add error "Kopia container '$KOPIA_CONTAINER' is not running"
        else drift_add error "Kopia is not connected to a repository"; fi
        return 1
    fi
    local uid_ok="yes"
    if [[ "$KOPIA_SERVER_UID" != "0" ]]; then
        drift_add error "The Kopia server runs as UID $KOPIA_SERVER_UID inside the container, snapshots need root (to read every file). A run as root would make the shared cache unreadable for the server - Kopia is NOT started. Fix: set PUID=0 and PGID=0 in the template"
        uid_ok="no"
    fi
    if [[ -n "$KOPIA_IDENTITY_CFG" && "$KOPIA_IDENTITY_CFG" != "$KOPIA_ID" ]]; then
        drift_add warn "The Kopia identity is now $KOPIA_ID (settings.ini: $KOPIA_IDENTITY_CFG) - new sources instead of continuing the old ones"
    fi
    kopia_policies_load
    KP_CHECKED="yes"
    local kind hpath share cpath
    while IFS='|' read -r kind hpath share; do
        [[ -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" || continue
        if kopia_policy_eval "$cpath" "$kind" "$(kopia_want_ignores "$kind" "$share")" "$(kopia_want_retention "$kind" "$share")"; then
            kp_status_add "$kind" "$share" "$cpath" 1 0
        else
            # If one of the share's own ignore rules is missing, Kopia would upload more
            # than wanted (e.g. a blockchain). This source is then left out.
            local miss_own="" x
            if [[ "$kind" == "share" ]]; then
                while IFS= read -r x; do
                    [[ -n "$x" ]] && grep -Fxq -- "$x" <<<"$KP_MISSING" && miss_own+="$x "
                done < <(cfg_list "share|$share|kopia_ignore")
            fi
            if [[ -n "$miss_own" ]]; then
                drift_add error "The Kopia policy for $cpath lacks ignore rules ($miss_own) - share '$share' is NOT given to Kopia until 'setup.sh --kopia' has run"
                SKIP_KOPIA[$share]="policy incomplete"
                kp_status_add "$kind" "$share" "$cpath" 0 1
            else
                drift_add warn "The Kopia policy for $cpath differs from settings.ini ('setup.sh --kopia' aligns it):"$'\n'"${KP_DIFF%$'\n'}"
                kp_status_add "$kind" "$share" "$cpath" 0 0
            fi
        fi
    done < <(kopia_targets)
    [[ "$uid_ok" == "yes" ]] || return 1
    KOPIA_OK="yes"
}

drift_check_settings() {
    local e
    for e in "${CFG_ERRORS[@]}"; do drift_add error "settings.ini: $e"; done
}

# Fingerprint (warn/error only), so that the same message does not come every night
drift_fingerprint() {
    printf '%s\n' "${DRIFT[@]}" | grep -E '^(warn|error)\|' | LC_ALL=C sort | md5sum | cut -c1-16
}

drift_text() { # all messages readable, the most serious first
    local lvl l
    for lvl in error warn info; do
        for l in "${DRIFT[@]}"; do
            [[ "${l%%|*}" == "$lvl" ]] || continue
            case "$lvl" in error) printf 'ERROR   ' ;; warn) printf 'WARNING ' ;; info) printf 'INFO    ' ;; esac
            printf '%s\n' "${l#*|}"
        done
    done
}

drift_count() { local lvl="$1" n=0 l; for l in "${DRIFT[@]}"; do [[ "${l%%|*}" == "$lvl" ]] && n=$((n+1)); done; echo "$n"; }

##############################################################################
# 7. Status for other programs (e.g. the Unraid Secretary Office)
##############################################################################
# state/status.json describes the running or last finished run with
# fixed English keys. The texts in the log may change, this
# interface may not - whoever reads it checks "interface".
#   status.json      running or last run (every mode except unmount)
#   last-run.json    last real backup run (no dry run, no check)
#   history.jsonl    one line per real backup run, the last 200
#   drift.json       drift found by the last check (level + text), and since 2.14 per
#                    Kopia target whether its policy matches settings.ini ("policies")
# Writing is never critical: if it fails, the backup carries on.
UB_INTERFACE=1
UB_HISTORY_MAX=200

ST_ACTIVE="no"            # only once the lock is held - otherwise a second start
ST_MODE=""                # that was turned away would overwrite the running one's status
ST_PHASE=""
ST_RESULT="running"       # running | ok | warnings | errors | failed | aborted
ST_MESSAGE=""
ST_STARTED=0
ST_FINISHED=0
ST_ABORTED="no"
ST_KOPIA_PLAN=()          # names of the Kopia sources in order
ST_KOPIA_CUR=""
ST_KOPIA_CUR_T=0
ST_KOPIA_DONE=()          # lines "name|ok(1/0)|seconds|end"
ST_DUMP_BYTES=0

status_init() { # status_init <mode>
    ST_MODE="$1"; ST_STARTED="$(date +%s)"; ST_ACTIVE="yes"; ST_PHASE="start"
    status_write
}

status_phase() { ST_PHASE="$1"; status_write; }

status_json() {
    local plan done_ drift
    plan="$(printf '%s\n' "${ST_KOPIA_PLAN[@]}" | jq -R 'select(length > 0)' | jq -sc .)" || plan='[]'
    done_="$(printf '%s\n' "${ST_KOPIA_DONE[@]}" | jq -R 'select(length > 0) | split("|")
        | {name: .[0], ok: (.[1] == "1"), seconds: (.[2] | tonumber), finished: (.[3] | tonumber)}' | jq -sc .)" || done_='[]'
    drift="$(jq -nc --argjson e "$(drift_count error)" --argjson w "$(drift_count warn)" --argjson i "$(drift_count info)" \
        '{error: $e, warn: $w, info: $i}')" || drift='{}'
    jq -nc \
        --arg name "$UB_NAME" --arg version "$UB_VERSION" --argjson interface "$UB_INTERFACE" \
        --arg mode "$ST_MODE" --arg run "${TS:-}" --argjson pid "$$" \
        --argjson started "$ST_STARTED" --argjson updated "$(date +%s)" --argjson finished "$ST_FINISHED" \
        --arg phase "$ST_PHASE" --arg result "$ST_RESULT" --arg message "$ST_MESSAGE" \
        --argjson errors "${ERRORS:-0}" --argjson warnings "${WARNINGS:-0}" \
        --argjson downtime "${DOWNTIME:-0}" --arg snapshot "${SNAP_NAME:-}" \
        --argjson dump_bytes "${ST_DUMP_BYTES:-0}" --arg log "$(basename "${LOG_FILE:-}")" \
        --argjson drift "$drift" --argjson planned "$plan" --argjson done "$done_" \
        --arg kopia_enabled "${KOPIA_ENABLED:-}" --arg kopia_ok "${KOPIA_OK:-}" \
        --arg current "$ST_KOPIA_CUR" --argjson current_since "$ST_KOPIA_CUR_T" \
        '{interface: $interface, name: $name, version: $version, mode: $mode, run: $run, pid: $pid,
          started: $started, updated: $updated, finished: $finished, phase: $phase, result: $result,
          message: $message, errors: $errors, warnings: $warnings, downtime_s: $downtime,
          snapshot: $snapshot, dump_bytes: $dump_bytes, log: $log, drift: $drift,
          kopia: {enabled: ($kopia_enabled | ascii_downcase | test("^(yes|ja|1|true)$")), state: $kopia_ok,
                  planned: $planned, current: (if $current == "" then null else $current end),
                  current_since: (if $current == "" then null else $current_since end), done: $done}}'
}

status_write() {
    [[ "$ST_ACTIVE" == "yes" ]] || return 0
    local tmp="$UB_STATE/.status.json.$$"
    if status_json >"$tmp" 2>/dev/null && [[ -s "$tmp" ]]; then
        mv -f "$tmp" "$UB_STATE/status.json" 2>/dev/null
    else
        rm -f "$tmp"
    fi
    return 0
}

status_finish() { # status_finish <result> [message]
    [[ "$ST_ACTIVE" == "yes" ]] || return 0
    [[ "$ST_RESULT" == "running" ]] || return 0      # already finished
    ST_RESULT="$1"; ST_MESSAGE="${2:-$ST_MESSAGE}"; ST_FINISHED="$(date +%s)"
    ST_PHASE="done"; ST_KOPIA_CUR=""
    status_write
    [[ "$ST_MODE" == "backup" ]] || return 0
    cp -f "$UB_STATE/status.json" "$UB_STATE/.last-run.json.$$" 2>/dev/null \
        && mv -f "$UB_STATE/.last-run.json.$$" "$UB_STATE/last-run.json" 2>/dev/null
    {
        tail -n $((UB_HISTORY_MAX - 1)) "$UB_STATE/history.jsonl" 2>/dev/null
        cat "$UB_STATE/status.json"; echo
    } >"$UB_STATE/.history.jsonl.$$" 2>/dev/null \
        && mv -f "$UB_STATE/.history.jsonl.$$" "$UB_STATE/history.jsonl" 2>/dev/null
    return 0
}

# drift.json: {time, items: [{level, text}], policies: null (not compared) | [KP_STATUS ...]}
drift_json_write() {
    local tmp="$UB_STATE/.drift.json.$$" pol="null"
    [[ "$KP_CHECKED" == "yes" ]] && { pol="$(printf '%s\n' "${KP_STATUS[@]}" | jq -sc .)" || pol="null"; }
    if printf '%s\n' "${DRIFT[@]}" | jq -R 'select(length > 0) | index("|") as $i
            | {level: .[0:$i], text: .[$i + 1:]}' | jq -sc --argjson t "$(date +%s)" --argjson p "${pol:-null}" \
            '{time: $t, items: ., policies: $p}' >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$UB_STATE/drift.json" 2>/dev/null
    else
        rm -f "$tmp"
    fi
    return 0
}
