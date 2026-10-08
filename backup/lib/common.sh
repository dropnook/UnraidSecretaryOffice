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
#   7. Status          status.json & co. for other programs
#   8. Packages        names and housekeeping of the backup place (since 2.18)
#   9. Kopia per app   apps and VMs with a Kopia source of their own (since 2.19)
#  10. Snapshot names  the engine's ZFS snapshots: prefixes, exact matching, retention (since 2.20); what its
#                      retention removed, state/pruned.json (since 2.21)
#  11. New things      what is new stays local and keeps running until the user decided (since 2.21)
#  12. Partners        units sent to partner offices by zfs send through their door (since 2.27)
#  13. Sleeping pools  [general] asleep_pools = skip: pools and disks that sleep are left out of a run (since 2.28)
###############################################################################

# shellcheck disable=SC2034   # many variables are only used in the scripts

UB_VERSION="2.28"
UB_NAME="unraid-backup"
UB_USER_SCRIPT="unraid-secretary-office_backup"   # the User Scripts entry setup.sh offers outside the plugin (was unraid-backup)
# What the office creates in numbers is named uso-... (Unraid Secretary Office); places keep the long
# name. The engine's ZFS snapshots: <snap_prefix>YYYYMMDD-HHMM, by default uso-backup-... (section 10);
# before 2.20 the default was unraidbackup- - still the engine's, its snapshots age out by the retention.
UB_SNAP_PREFIX="uso-backup-"
UB_SNAP_PREFIX_LEGACY="unraidbackup-"
UB_KOPIA_DESC="uso-backup"        # Kopia snapshot description "uso-backup <run>" (before 2.20: "unraid-backup <run>")
# The office's own places. Nothing of ours directly in /mnt (Fix Common Problems rightly
# complains): mounts go to /mnt/addons, which Unraid creates at boot for this - a small
# tmpfs in RAM with mount propagation, holding only empty mount points and symlinks. The
# data the desks keep (here: the packages of apps and VMs) go to the share
# UnraidSecretaryOffice, one folder per desk - this one is "backup".
UB_OFFICE_SHARE="UnraidSecretaryOffice"
UB_DESK_DIR="backup"

##############################################################################
# 1. Basics
##############################################################################

# The script is part of the Unraid Secretary Office's plugin: the code lies in RAM
# (/usr/local/emhttp/plugins/unraid-secretary-office/backup) and the data - settings,
# state and logs, root only (the logs name every database) - in the office's data
# folder: DATA_DIR in the plugin's .cfg on the flash, by default
# <appdata>/UnraidSecretaryOffice/data (like src/place.php), in unraid-backup/.
# UB_DATA names another folder; a copy outside the plugin folder (a clone, a test)
# keeps its data next to its code in ../data/unraid-backup.
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
UB_DUMPS="$UB_DATA/dumps"      # the backup place - set from [general] dumps_share (apply_settings)
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
# agent as root)
ub_data_dirs() {
    mkdir -p "$UB_STATE" "$UB_LOGS" || return 1      # dumps: in their own backup share (dumps_share), never here
    chmod 700 "$UB_DATA" 2>/dev/null
    return 0
}

# Unraid names a notification <event>-<second> - the second its notify script reads the clock, somewhere in our call - and
# one more with the same event in that second overwrites it. The engine, the office's agent and its night shift
# (officeNotify() in agent/lib/house.php) and the plugin's agent.sh (tell) send with the same event, so they share one
# guard (2.25): the stamp UB_NOTIFY_STAMP in RAM - the plugin's /var/run/unraid-secretary-office/notify.second - holds the
# second the last call ended in; under its flock (held through the call, so they take turns; at most 10 s waited, then
# the call goes anyway) a call starts only after that second. Without the folder (a copy outside the plugin): this
# process's own guard (UB_NOTIFY_LAST). The notify script never inherits the lock.
UB_NOTIFY_STAMP="${UB_NOTIFY_STAMP-$(ub_is_plugin && echo /var/run/unraid-secretary-office/notify.second)}"
UB_NOTIFY_LAST=0
ub_notify_after() { # ub_notify_after <second>  - returns once the clock is past that second (at most ~1 s)
    local now us
    now="$(date +%s%N)"
    [[ "$now" =~ ^[0-9]{10,}$ ]] || { (( $(date +%s) <= $1 )) && sleep 1; return 0; }
    (( ${now:0:${#now}-9} <= $1 )) || return 0
    us=$(( 1010000 - 10#${now:${#now}-9} / 1000 ))
    sleep "$(( us / 1000000 )).$(printf '%06d' $(( us % 1000000 )))"
}
ub_notify() {
    [[ "${UB_NO_NOTIFY:-0}" == "1" ]] && return 0
    [[ -x "$UB_NOTIFY_BIN" ]] || return 0
    # Unraid puts the server's name in front of the subject itself
    local args=( -e "Unraid Secretary Office" -s "Unraid Secretary Office: $1" -d "$2" -i "${3:-normal}" ) st="$UB_NOTIFY_STAMP" fd="" last
    [[ -n "${4:-}" ]] && args+=( -m "$4" )
    if [[ -n "$st" && -d "${st%/*}" && ! -L "$st" ]] && { exec {fd}>>"$st"; } 2>/dev/null; then
        if flock -w 10 "$fd" 2>/dev/null; then
            last="$(head -c 32 "$st" 2>/dev/null | tr -dc '0-9')"
            is_uint "$last" && (( last > UB_NOTIFY_LAST )) && UB_NOTIFY_LAST="$last"
        else
            exec {fd}>&-; fd=""
        fi
    fi
    ub_notify_after "$UB_NOTIFY_LAST"
    if [[ -n "$fd" ]]; then
        "$UB_NOTIFY_BIN" "${args[@]}" >/dev/null 2>&1 {fd}>&-
        UB_NOTIFY_LAST="$(date +%s)"
        printf '%s\n' "$UB_NOTIFY_LAST" >"$st" 2>/dev/null
        exec {fd}>&-
    else
        "$UB_NOTIFY_BIN" "${args[@]}" >/dev/null 2>&1
        UB_NOTIFY_LAST="$(date +%s)"
    fi
    return 0
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
    # keep_runs: before 2.18 the number of run folders kept - accepted in old files, ignored
    [general]="server mount_root view_root snap_prefix btrfs_snap_dir keep_runs keep_logs min_free_gb keep_mounts notify_success dumps_share partner_place asleep_pools"
    [zfs]="retention"
    [btrfs]="keep_days min_free_gb snapshot_all"
    [drift]="ignore remind_days"
    [docker]="stop no_stop stop_timeout known skip"
    [flash]="mode tar_exclude kopia_ignore"
    [libvirt]="mode"
    [kopia]="enabled container identity keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual compression ignore"
    [nextcloud]="preexisting_maintenance"
    [dump]="type"
    # kopia_known (since 2.21): the top-level folders that go to Kopia (section 11)
    [share]="mode method retention kopia_retention kopia_ignore kopia_known exclude_dataset id locations note partner"
    # kopia, folder, kopia_retention, kopia_ignore (since 2.19): a Kopia source of its own (section 9)
    [vm]="mode prepare retention kopia folder kopia_retention kopia_ignore partner"
    [app]="kopia folder kopia_retention kopia_ignore"
    # since 2.27 (section 12): a partner office the run sends to, and per share/VM/the backup place the partners it goes to
    [partner]="name address port rate_mbit"
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
    _val "general|keep_logs"     '^[0-9]+$'                 "number"
    _val "general|min_free_gb"   '^[0-9]+$'                 "number"
    _val "general|keep_mounts"   '^(yes|no)$'               "yes/no"
    _val "general|notify_success" '^(yes|no)$'              "yes/no"
    _val "general|asleep_pools"  '^(wake|skip)$'            "wake/skip"
    _val "general|snap_prefix"   '^[a-z0-9_]+(-[a-z0-9_]+)*-$' "lower-case letters/digits, words joined by -, ends with -"
    # Ms. Snapshotini's schedules name theirs uso-plan-<plan>-...: never the engine's prefix (its retention would take them)
    [[ "$(cfg "general|snap_prefix")" == uso-plan-* ]] && CFG_ERRORS+=( "general|snap_prefix = '$(cfg "general|snap_prefix")' is invalid (uso-plan- belongs to Ms. Snapshotini's schedules)" )
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
        local kk
        while IFS= read -r kk; do
            [[ "$kk" == "*" || ( "$kk" =~ ^/[^/]+/$ && "$kk" != "/./" && "$kk" != "/../" ) ]] \
                || CFG_ERRORS+=( "[share \"$n\"] kopia_known = '$kk' is invalid (/<folder>/: a folder at the top of the share, or * for all)" )
        done < <(cfg_list "share|$n|kopia_known")
    done < <(cfg_names share)
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        _val "vm|$n|mode"      '^(snapshot|off)$'                  "snapshot/off"
        _val "vm|$n|prepare"   '^(freeze|pause|shutdown|none)$'    "freeze/pause/shutdown/none"
        _val "vm|$n|retention" '^[0-9]+ [0-9]+ [0-9]+$'            "three numbers"
    done < <(cfg_names vm)
    # apps and VMs with a Kopia source of their own (section 9)
    local t f
    for t in app vm; do
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            [[ "$n" == */* ]] && CFG_ERRORS+=( "[$t \"$n\"]: a name with / is not supported" )
            _val "$t|$n|kopia" '^(yes|no)$' "yes/no"
            _val "$t|$n|kopia_retention" '^([0-9]+|inherit)( ([0-9]+|inherit)){5}$' "six values: latest hourly daily weekly monthly annual"
            while IFS= read -r f; do
                [[ -z "$f" ]] && continue
                item_folder_ok "$f" || CFG_ERRORS+=( "[$t \"$n\"] folder = '$f' is invalid (<share>/<folder> inside the share, without .. and without a leading /)" )
            done < <(cfg_list "$t|$n|folder")
        done < <(cfg_names "$t")
    done
    # partners (section 12): only the format here - a partner named without a [partner] section (the partnership ended,
    # the setup not applied since) is skipped by the run, never an error that stops it
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        partner_id_ok "$n" || CFG_ERRORS+=( "[partner \"$n\"]: the id is 8 hex digits (lower case)" )
        _val "partner|$n|name"      '^[A-Za-z0-9._-]{1,40}$'        "letters, digits, . _ -, at most 40"
        _val "partner|$n|address"   '^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$' "an IP address or a host name"
        _val "partner|$n|port"      '^[0-9]{1,5}$'                  "a port number"
        _val "partner|$n|rate_mbit" '^[0-9]+$'                      "Mbit/s, 0 = unlimited"
        [[ -n "$(cfg "partner|$n|address")" ]] || CFG_ERRORS+=( "[partner \"$n\"] without address" )
    done < <(cfg_names partner)
    local pk
    for k in "${!CFG[@]}"; do
        [[ "$k" =~ ^(share\|.+\|partner|vm\|.+\|partner|general\|partner_place)$ ]] || continue
        while IFS= read -r pk; do
            [[ -z "$pk" ]] || partner_id_ok "$pk" || CFG_ERRORS+=( "${k##*|} = '$pk' in $(sec_display "${k%|*}") is invalid (a partner's id: 8 hex digits)" )
        done < <(cfg_list "$k")
    done
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
    SNAP_PREFIX_SET="$(cfg "general|snap_prefix")"        # as settings.ini says it (empty: not at all)
    snap_prefix_resolve "$SNAP_PREFIX_SET"               # -> SNAP_PREFIX, SNAP_PREFIXES (section 10)
    BTRFS_SNAP_DIR="$(cfg "general|btrfs_snap_dir" ".btrfs-snap")"
    KEEP_LOGS="$(cfg "general|keep_logs" 60)"
    MIN_FREE_GB="$(cfg "general|min_free_gb" 8)"
    KEEP_MOUNTS="$(cfg "general|keep_mounts" no)"
    NOTIFY_SUCCESS="$(cfg "general|notify_success" yes)"
    # since 2.28 (section 13): wake = sleeping pools are woken for the snapshot (as before), skip = left out that night
    ASLEEP_POOLS="$(cfg "general|asleep_pools" wake)"
    [[ "$ASLEEP_POOLS" == "skip" ]] || ASLEEP_POOLS="wake"
    # The packages (apps, VMs, server, flash) live in a backup share of their own, never in
    # appdata: <share>/unraid-backup/, in the office's share <share>/backup/
    # (dumps_share_problem says whether the share will do; section 8 has the layout)
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
    # apps the user chose not to back up: they keep running like no_stop (no dump, Kopia leaves them out)
    mapfile -t DOCKER_SKIP    < <(cfg_list "docker|skip")
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
#   INV_BYTES[s]              the same in bytes (since 2.25: the Kopia order, kopia_order)
#   INV_NOTE[s]               notes (one line per note)
declare -ga INV_BASES=() INV_SHARES=()
declare -gA INV_BASE_PATH=() INV_BASE_FS=() INV_BASE_KIND=() INV_BASE_SRC=()
declare -gA INV_LOCS=() INV_CHILDREN=() INV_METHOD=() INV_LAYOUT=() INV_ID=() INV_GB=() INV_BYTES=() INV_NOTE=()

inv_scan() {
    mounts_load
    zfs_load
    INV_BASES=(); INV_SHARES=()
    INV_BASE_PATH=(); INV_BASE_FS=(); INV_BASE_KIND=(); INV_BASE_SRC=()
    INV_LOCS=(); INV_CHILDREN=(); INV_METHOD=(); INV_LAYOUT=(); INV_ID=(); INV_GB=(); INV_BYTES=(); INV_NOTE=()

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
        INV_LOCS[$s]=""; INV_CHILDREN[$s]=""; INV_NOTE[$s]=""; INV_ID[$s]=""; INV_GB[$s]=""; INV_BYTES[$s]=""
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
        (( gb_known && nloc > 0 )) && { INV_GB[$s]="$(to_gb "$refsum")"; INV_BYTES[$s]="$refsum"; }
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

# --- The btrfs emergency brake (2.23) ---------------------------------------
# [btrfs] min_free_gb is meant for big array disks: below it the oldest snapshots go early. On a small
# disk (a 24 GB test disk, a 250 GB SSD) that number may be more than the disk can ever have free, so
# the floor is at most a tenth of the disk (at least 1 GB); 0 switches the brake off.
brake_floor_gb() { # brake_floor_gb <size in GB>  -> the free GB below which the oldest snapshots go
    local size="${1:-0}" tenth
    [[ "$size" =~ ^[0-9]+$ ]] || size=0
    (( ${BTRFS_MIN_FREE_GB:-0} > 0 )) || { printf '0'; return; }
    tenth=$(( size / 10 )); (( tenth < 1 )) && tenth=1
    (( BTRFS_MIN_FREE_GB < tenth )) && tenth=$BTRFS_MIN_FREE_GB
    printf '%s' "$tenth"
}

# --- Sleeping disks (since 2.19) -------------------------------------------
# Unraid notes in disks.ini which disks are spun down. A pool sleeps when any of its disks does
# (cache, cache2 ...); an array disk is just itself (disk1 is not disk10). Only used where the
# engine reads something it doesn't snapshot (the media servers' databases, the apps' own backups).
UB_DISKS_INI="${UB_DISKS_INI:-/var/local/emhttp/disks.ini}"
ub_base_asleep() { # ub_base_asleep <base>  -> 0 when it sleeps
    [[ -n "$1" && -r "$UB_DISKS_INI" ]] || return 1
    awk -v b="$1" '
        /^\[/ { name = $0; gsub(/[\[\]"]/, "", name); next }
        /^spundown=/ {
            v = $0; sub(/^spundown="?/, "", v); sub(/"$/, "", v)
            if (v != "1") next
            if (b ~ /^disk[0-9]+$/) { if (name == b) f = 1 }
            else if (name == b || (substr(name, 1, length(b)) == b && substr(name, length(b) + 1) ~ /^[0-9]+$/)) f = 1
        }
        END { exit f ? 0 : 1 }' "$UB_DISKS_INI"
}
# --- The array being stopped (since 2.24) ------------------------------------
# Unraid writes fsState="Stopping" into var.ini the moment an array stop begins - minutes before it
# shuts down the VMs and Docker, long before it unmounts the pools (where a run's lock, log and
# mounts would hold it up). "Started" - and "Formatting", "Clearing", which Unraid shows as "Started,
# formatting/clearing" (a new disk is cleared for hours while the array runs) - means it runs;
# "Stopping" and "Stopped" that it is going or gone. Anything else (no var.ini, a value not known
# here, the file read while emhttpd rewrites it) is never taken for a stop. Cheap: one small file in
# RAM, read at the run's safe points and every few seconds while Kopia uploads (backup.sh).
UB_VAR_INI="${UB_VAR_INI:-/var/local/emhttp/var.ini}"
array_fsstate() { sed -n 's/^fsState="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$UB_VAR_INI" 2>/dev/null | tail -n 1; }
array_stopping() { # -> 0 while the array is being stopped (or is stopped)
    [[ -r "$UB_VAR_INI" ]] || return 1
    case "$(array_fsstate)" in Stopping|Stopped) return 0 ;; esac
    return 1
}

# ub_path_where <path>  -> the path on the pool or disk that holds it ("" when it lies only on sleeping
# disks or nowhere); a /mnt/user path is looked up on the awake bases of its share only
UB_WHERE_ASLEEP="no"
ub_path_where() {
    local p="${1%/}" rel s b
    UB_WHERE_ASLEEP="no"
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then
        rel="${p#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"; s="${rel%%/*}"
        while IFS='|' read -r b _; do
            [[ -z "$b" ]] && continue
            if ub_base_asleep "$b"; then UB_WHERE_ASLEEP="yes"; continue; fi
            [[ -e "${INV_BASE_PATH[$b]}/$rel" ]] && { printf '%s' "${INV_BASE_PATH[$b]}/$rel"; return 0; }
        done <<<"${INV_LOCS[$s]:-}"
        return 1
    fi
    for b in "${INV_BASES[@]}"; do
        [[ "$p" == "${INV_BASE_PATH[$b]}/"* ]] || continue
        if ub_base_asleep "$b"; then UB_WHERE_ASLEEP="yes"; return 1; fi
        break
    done
    [[ -e "$p" ]] && printf '%s' "$p"
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
# media_kind <image>  -> emby | jellyfin | plex (empty for anything else)
media_kind() { local i="${1,,}"; case "$i" in *jellyfin*) echo jellyfin ;; *emby*) echo emby ;; *plex*) echo plex ;; esac; }
is_immich_server() { [[ "${1,,}" == *immich-server* || "${1,,}" == *immich_server* ]]; }
# ct_host_path <container> <path inside it>  -> where that lies on the host (the bind with the longest
# matching target), returns 1 when no bind holds it
ct_host_path() {
    local c="$1" p="${2%/}" src dst best="" bsrc=""
    while IFS='|' read -r src dst _; do
        [[ -z "$src" ]] && continue
        dst="${dst%/}"
        if [[ "$p" == "$dst" || "$p" == "$dst/"* ]] && (( ${#dst} > ${#best} )); then best="$dst"; bsrc="${src%/}"; fi
    done <<<"${CT_BINDS[$c]:-}"
    [[ -n "$best" ]] || return 1
    printf '%s%s' "$bsrc" "${p#"$best"}"
}
is_kopia_image() { [[ "${1,,}" == *kopia* ]]; }

# --- VMs (libvirt) -------------------------------------------------------------
# Their disks are files in a share (usually domains) and so already in that share's
# snapshot. What a VM adds: how it is treated while the snapshot is taken
# ([vm "<name>"] prepare), whether its own dataset is left out (mode = off) and its
# own retention - the last two only when its disks lie in datasets of their own
# (Unraid makes one per VM folder on ZFS pools).
#   VM_NAMES             all VMs libvirt knows, shut-off ones too
#   VM_STATE[n]          running | paused | shut off | ...
#   VM_AUTOSTART[n]      yes | no
#   VM_AGENT[n]          yes (the guest agent answers) | no (running, no answer) |
#                        channel (not running, a guest agent channel is configured) | none
#   VM_HOSTDEV[n]        number of passed-through devices (GPU & co.)
#   VM_TPM[n]            yes | no
#   VM_DISKS[n]          lines "target|source|base|fs|dataset|share" (device=disk only)
#   VM_SNAP[n]           yes = every disk lies on ZFS or btrfs; otherwise the reason:
#                        block (a whole device) | live (no snapshots there) | missing | none (no disk)
#   VM_OWN_DS[n]         its own datasets, one per line - empty if a disk shares its dataset
#                        with the share or another VM (then it can't be left out or kept apart)
#   VM_BYTES[n]          what its disk files take on their pool or disk (allocated blocks - a sparse
#                        vdisk counts what it holds), "" when it has none (since 2.25)
#   VM_APPARENT[n]       the same files' own sizes (stat %s: a sparse vdisk's full virtual size, holes
#                        included), "" when it has none - what Kopia reads at a first upload, so since
#                        2.26 the Kopia order goes by it (on 2026-10-07 a 1.6 TB vdisk holding 21 GB
#                        cost Kopia 2 TB of reading: the holes come as zeros); the setup's plan carries both
declare -ga VM_NAMES=()
declare -gA VM_STATE=() VM_AUTOSTART=() VM_AGENT=() VM_HOSTDEV=() VM_TPM=() VM_DISKS=() VM_SNAP=() VM_OWN_DS=() VM_BYTES=() VM_APPARENT=()
VM_SERVICE="no"
VM_SHUTDOWN_TIMEOUT="${UB_VM_SHUTDOWN_TIMEOUT:-300}"   # from the request; backup.sh waits for it before anything stops (2.22)
VM_SHUTDOWN_RETRY="${UB_VM_SHUTDOWN_RETRY:-60}"     # the shutdown request again every so many seconds (Windows swallows the first one)

vm_load() {
    VM_NAMES=(); VM_STATE=(); VM_AUTOSTART=(); VM_AGENT=(); VM_HOSTDEV=(); VM_TPM=(); VM_DISKS=(); VM_SNAP=(); VM_OWN_DS=(); VM_BYTES=(); VM_APPARENT=()
    VM_SERVICE="no"
    command -v virsh >/dev/null 2>&1 || return 0
    local names
    names="$(timeout 20 virsh list --all --name 2>/dev/null)" || return 0
    VM_SERVICE="yes"
    mapfile -t VM_NAMES < <(sed '/^[[:space:]]*$/d' <<<"$names")
    local n xml type dev target src p b fs ds share snap blk bsz app
    local -A ds_users=()
    for n in "${VM_NAMES[@]}"; do
        VM_STATE[$n]="$(timeout 10 virsh domstate "$n" 2>/dev/null | head -1)"
        VM_AUTOSTART[$n]="no"
        timeout 10 virsh dominfo "$n" 2>/dev/null | grep -qE '^Autostart:[[:space:]]+enable' && VM_AUTOSTART[$n]="yes"
        xml="$(timeout 10 virsh dumpxml "$n" 2>/dev/null)"
        VM_HOSTDEV[$n]="$(grep -c '<hostdev ' <<<"$xml")"
        if grep -q '<tpm ' <<<"$xml"; then VM_TPM[$n]="yes"; else VM_TPM[$n]="no"; fi
        if [[ "${VM_STATE[$n]}" == "running" ]]; then
            if timeout 5 virsh qemu-agent-command "$n" '{"execute":"guest-ping"}' >/dev/null 2>&1; then VM_AGENT[$n]="yes"; else VM_AGENT[$n]="no"; fi
        elif grep -q 'org.qemu.guest_agent.0' <<<"$xml"; then VM_AGENT[$n]="channel"
        else VM_AGENT[$n]="none"; fi
        VM_DISKS[$n]=""; VM_BYTES[$n]=""; VM_APPARENT[$n]=""; snap="yes"
        while read -r type dev target src; do
            [[ "$dev" == "disk" ]] || continue
            b=""; fs=""; ds=""; share=""
            if [[ "$type" == "block" ]]; then snap="block"
            else
                # /mnt/user/<share>/... -> the pool or disk that really holds the file
                p="$src"
                if [[ "$src" == "$UB_MNT"/user/* || "$src" == "$UB_MNT"/user0/* ]]; then
                    local rel="${src#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"
                    share="${rel%%/*}"; p=""
                    for b in "${INV_BASES[@]}"; do
                        [[ -e "${INV_BASE_PATH[$b]}/$rel" ]] && { p="${INV_BASE_PATH[$b]}/$rel"; break; }
                    done
                else
                    for b in "${INV_BASES[@]}"; do
                        [[ "$src" == "${INV_BASE_PATH[$b]}/"* ]] || continue
                        share="${src#"${INV_BASE_PATH[$b]}"/}"; share="${share%%/*}"; break
                    done
                fi
                if [[ -z "$p" || ! -e "$p" ]]; then snap="missing"; b=""
                else
                    b=""
                    local x; for x in "${INV_BASES[@]}"; do [[ "$p" == "${INV_BASE_PATH[$x]}/"* ]] && { b="$x"; break; }; done
                    # its size: the file was just looked at on its pool or disk (no FUSE, nothing woken) -
                    # what it takes (allocated blocks) and what it is (apparent: what Kopia reads, holes too)
                    if [[ -f "$p" ]] && read -r blk bsz app < <(stat -c '%b %B %s' -- "$p" 2>/dev/null) && is_uint "$blk" && is_uint "$bsz" && is_uint "$app"; then
                        VM_BYTES[$n]=$(( ${VM_BYTES[$n]:-0} + blk * bsz ))
                        VM_APPARENT[$n]=$(( ${VM_APPARENT[$n]:-0} + app ))
                    fi
                    mount_of "$p"
                    fs="${MT_FSTYPE[$MO_IDX]:-}"
                    case "$fs" in
                        zfs)   ds="${MT_SOURCE[$MO_IDX]}" ;;
                        btrfs) ;;
                        *)     [[ "$snap" == "yes" ]] && snap="live" ;;
                    esac
                fi
            fi
            VM_DISKS[$n]+="$target|$src|$b|$fs|$ds|$share"$'\n'
            [[ -n "$ds" ]] && ds_users[$ds]+="$n"$'\n'
        done < <(timeout 10 virsh domblklist --details "$n" 2>/dev/null | awk 'NF >= 4 && $2 ~ /^(disk|cdrom|floppy|lun)$/')
        [[ -z "${VM_DISKS[$n]}" ]] && snap="none"
        VM_SNAP[$n]="$snap"
    done
    # A dataset is the VM's own when it is not a share's own dataset and no other VM uses it
    local line own
    for n in "${VM_NAMES[@]}"; do
        own=""
        while IFS='|' read -r target src b fs ds share; do
            [[ -z "$target" ]] && continue
            if [[ -z "$ds" ]] || [[ "$(sort -u <<<"${ds_users[$ds]}" | sed '/^$/d' | wc -l)" -ne 1 ]] \
               || [[ -n "$share" && "${ZDS_MP[$ds]:-}" == "${INV_BASE_PATH[$b]:-?}/$share" ]]; then
                own=""; break
            fi
            grep -Fxq -- "$ds" <<<"$own" || own+="$ds"$'\n'
        done <<<"${VM_DISKS[$n]}"
        VM_OWN_DS[$n]="$own"
    done
}

vm_mode()    { cfg "vm|$1|mode" "snapshot"; }
vm_prepare() { cfg "vm|$1|prepare" "none"; }
# vm_snapshotted <name>  -> 0 when a snapshot of this run holds all of its disks
vm_snapshotted() {
    local n="$1" target src b fs ds share
    [[ "${VM_SNAP[$n]:-}" == "yes" ]] || return 1
    while IFS='|' read -r target src b fs ds share; do
        [[ -z "$target" ]] && continue
        case "$fs" in
            zfs)   in_list "$ds" "${PLAN_ZFS[@]}" || return 1 ;;
            btrfs) in_list "${INV_BASE_PATH[$b]:-?}" "${PLAN_BTRFS[@]}" || return 1 ;;
            *)     return 1 ;;
        esac
    done <<<"${VM_DISKS[$n]}"
    return 0
}
# vm_on_btrfs <name>  -> 0 when one of its disks lies on btrfs (released after the btrfs snapshots)
vm_on_btrfs() { local t s b fs r; while IFS='|' read -r t s b fs r; do [[ "$fs" == "btrfs" ]] && return 0; done <<<"${VM_DISKS[$1]:-}"; return 1; }
# vm_packed <name>  -> 0 when the VM gets a package (its configuration in the backup place): unless it is
# left out (mode = off) or every share holding its disks is off. A VM whose disks no snapshot can hold
# (a whole device, XFS) still gets one - then its configuration is all there is.
vm_packed() {
    local n="$1" t s b fs ds share any=0 on=0
    [[ "$(vm_mode "$n")" == "off" ]] && return 1
    while IFS='|' read -r t s b fs ds share; do
        [[ -z "$t" || -z "$share" ]] && continue
        any=1; [[ "$(share_mode "$share")" != "off" ]] && on=1
    done <<<"${VM_DISKS[$n]:-}"
    (( ! any || on ))
}

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
#   PLAN_KITEMS      apps and VMs with a Kopia source of their own: "kind|name|folder" (section 9)
#   PLAN_MOUNT       shares mounted for Kopia: PLAN_KOPIA and the shares the items' parts lie in
declare -ga PLAN_KOPIA=() PLAN_SNAP=() PLAN_ZFS=() PLAN_BTRFS=() PLAN_KITEMS=() PLAN_MOUNT=()
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
        off)       echo "Backup place '$2' is not backed up itself (mode=off) - the packages would keep no history and be backed up nowhere" ;;
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
    PLAN_KOPIA=(); PLAN_SNAP=(); PLAN_ZFS=(); PLAN_BTRFS=(); PLAN_ZFS_RET=(); PLAN_EXCL=(); PLAN_KITEMS=(); PLAN_MOUNT=()
    local s mode meth b m layer sub ret line ds mp
    local -A seen_b=() seen_ds=()
    local -a excl
    # VMs with datasets of their own: left out (mode = off) or kept by their own retention
    local -A vm_excl=() vm_ret=()
    local vn
    for vn in "${VM_NAMES[@]}"; do
        while IFS= read -r ds; do
            [[ -z "$ds" ]] && continue
            [[ "$(vm_mode "$vn")" == "off" ]] && vm_excl[$ds]=1
            [[ -n "$(cfg "vm|$vn|retention")" ]] && vm_ret[$ds]="$(cfg "vm|$vn|retention")"
        done <<<"${VM_OWN_DS[$vn]:-}"
    done
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
            # Ms. Dustdevil's storeroom (datasets put away as _UnraidSecretaryOffice-trash-<stamp>-<name>) is never backed up
            if in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}" || [[ -n "${vm_excl[$ds]:-}" ]] \
               || [[ "${ds##*/}" == _UnraidSecretaryOffice-trash* ]]; then
                PLAN_EXCL[$ds]=1; continue
            fi
            if [[ -z "${seen_ds[$ds]:-}" ]]; then seen_ds[$ds]=1; PLAN_ZFS+=( "$ds" ); PLAN_ZFS_RET[$ds]="$ret"
            else PLAN_ZFS_RET[$ds]="$(_ret_max "${PLAN_ZFS_RET[$ds]}" "$ret")"; fi
        done <<<"${INV_CHILDREN[$s]}"
    done < <(cfg_names share)
    for ds in "${!vm_ret[@]}"; do [[ -n "${PLAN_ZFS_RET[$ds]:-}" ]] && PLAN_ZFS_RET[$ds]="${vm_ret[$ds]}"; done

    if is_yes "$BTRFS_SNAPSHOT_ALL"; then
        for b in "${INV_BASES[@]}"; do
            [[ "${INV_BASE_KIND[$b]}" == "disk" && "${INV_BASE_FS[$b]}" == "btrfs" ]] || continue
            [[ -z "${seen_b[${INV_BASE_PATH[$b]}]:-}" ]] && { seen_b[${INV_BASE_PATH[$b]}]=1; PLAN_BTRFS+=( "${INV_BASE_PATH[$b]}" ); }
        done
    fi

    inv_flash
    PLAN_FLASH="$FLASH_MODE"
    if [[ "$PLAN_FLASH" == "snapshot" && -z "$FLASH_DATASET" ]]; then PLAN_FLASH="tar"; fi

    # apps and VMs with a Kopia source of their own, and the shares their parts lie in - those are
    # mounted for Kopia too, also when the share itself stays local (section 9)
    mapfile -t PLAN_KITEMS < <(kopia_items)
    PLAN_MOUNT=( "${PLAN_KOPIA[@]}" )
    local it t n f sh
    for it in "${PLAN_KITEMS[@]}"; do
        IFS='|' read -r t n f <<<"$it"
        while IFS='|' read -r sh _; do
            [[ -z "$sh" ]] && continue
            in_list "$sh" "${PLAN_MOUNT[@]}" && continue
            share_name_ok "$sh" && [[ "$(share_mode "$sh")" != "off" ]] || continue
            case "$(share_method "$sh")" in snap|live) PLAN_MOUNT+=( "$sh" ) ;; esac
        done < <(kopia_item_parts "$t" "$n")
    done
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

# The size each source is expected to have, from Kopia (since 2.25, for the Kopia order: kopia_order) -> KSIZE[<container
# path>] = bytes: the size of its newest complete snapshot of this identity - or, when larger, of a newer incomplete one
# (a checkpoint of an upload interrupted or still going on, run after run: a lower bound of what the source holds). A share
# whose folders were all left out until the setup changed has a tiny complete snapshot while its first real upload of
# terabytes is still in checkpoints - where the server knows no size of its own (an array share on XFS or btrfs, a folder
# in a pool's root dataset) only the checkpoint says it is huge. One "snapshot list" (metadata only - Kopia keeps the
# manifests in its index); its JSON lists the incomplete ones too, and with -n 1 a newer checkpoint would hide the complete
# snapshot, so all are listed and picked here. At most UB_KOPIA_LIST_TIMEOUT s; whatever goes wrong leaves KSIZE empty
# (then the inventory's sizes).
declare -gA KSIZE=()
kopia_sizes_load() {
    KSIZE=()
    [[ "$KOPIA_CONNECTED" == "yes" && -n "$KOPIA_USER" && -n "$KOPIA_HOST" ]] || return 0
    local e=() p b
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    while IFS=$'\t' read -r p b; do
        [[ -n "$p" ]] && is_uint "$b" && KSIZE[$p]="$b"
    done < <(timeout "${UB_KOPIA_LIST_TIMEOUT:-60}" docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" \
                 kopia --no-progress snapshot list --all --json 2>/dev/null \
             | jq -r --arg u "$KOPIA_USER" --arg h "$KOPIA_HOST" '
                 def size: (.stats.totalSize // .rootEntry.summ.size // null) | if type == "number" then floor else null end;
                 [.[]? | select(.source.userName == $u and .source.host == $h)]
                 | group_by(.source.path)[] as $g
                 | ($g | map(select((.incompleteReason // "") == "")) | max_by(.startTime // "")) as $c
                 | ($g | map(select((.incompleteReason // "") != "" and ((.startTime // "") > ($c.startTime // ""))))
                       | max_by(.startTime // "")) as $i
                 | ([$c, $i] | map(select(. != null) | size) | map(select(. != null)) | max) as $b
                 | select($b != null)
                 | [$g[0].source.path, ($b | tostring)] | @tsv' 2>/dev/null)
    return 0
}

# Host path under which Kopia reads a share - always <mount_root>/<share>,
# also for "live" (then a read-only bind of /mnt/user/<share>)
share_kopia_hostpath() { printf '%s' "$MOUNT_ROOT/$1"; }
FLASH_SOURCE_NAME="_flash"

# Wanted ignore list of a target
#   root    -> the global list from [kopia] (inherited by all shares)
#   share   -> only the share's own rules, and the parts of apps and VMs with a source of their own, and
#              (since 2.21) the rules for its new folders that stay local (NEW_RULES, section 11)
#   app/vm  -> only its own rules (relative to its source: /<share>/<path>/)
kopia_want_ignores() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)   printf '%s\n' "${KOPIA_IGNORE[@]}" ;;
        share)  cfg_list "share|$s|kopia_ignore"; kopia_derived_ignores "$s"; printf '%s\n' "${NEW_RULES[$s]:-}" ;;
        app|vm) cfg_list "$kind|$s|kopia_ignore" ;;
        flash)  printf '%s\n' "${FLASH_KOPIA_IGNORE[@]}" ;;
    esac | sed '/^$/d' | LC_ALL=C sort -u
}

# Wanted retention of a target: six values (latest hourly daily weekly monthly annual)
kopia_want_retention() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)  echo "$KOPIA_KEEP_LATEST $KOPIA_KEEP_HOURLY $KOPIA_KEEP_DAILY $KOPIA_KEEP_WEEKLY $KOPIA_KEEP_MONTHLY $KOPIA_KEEP_ANNUAL" ;;
        share) cfg "share|$s|kopia_retention" "inherit inherit inherit inherit inherit inherit" ;;
        # an app or VM without its own: inherits the policy on <mount_root>, so [kopia] keep_*
        app|vm) cfg "$kind|$s|kopia_retention" "inherit inherit inherit inherit inherit inherit" ;;
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
#   lines "kind|host path|name"   kind: root | share | app | vm | flash  (name: the share, app or VM)
kopia_targets() {
    local s t n f
    is_yes "$KOPIA_ENABLED" || return 0
    printf 'root|%s|\n' "$MOUNT_ROOT"
    for s in "${PLAN_KOPIA[@]}"; do printf 'share|%s|%s\n' "$(share_kopia_hostpath "$s")" "$s"; done
    while IFS='|' read -r t n f; do
        [[ -n "$t" ]] && printf '%s|%s|%s\n' "$t" "$(item_hostpath "$t" "$f")" "$n"
    done < <(kopia_items)
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

# The notes an interrupted run left in state/ (stopped containers, Nextclouds in maintenance mode,
# held VMs) - read without the lock only to see whether there is anything to do (backup.sh --recover);
# acting on them needs the lock (a run going on writes its own notes there).
recover_notes() { [[ -s "$UB_STATE/stopped" || -s "$UB_STATE/maintenance" || -s "$UB_STATE/vms" ]]; }
# Does Docker (libvirt) answer? Without, a container (VM) would look "not stopped" and its note be lost.
ub_docker_answers()  { timeout 20 docker version --format '{{.Server.Version}}' >/dev/null 2>&1; }
ub_libvirt_answers() { timeout 20 virsh list --name >/dev/null 2>&1; }
# Unraid's VM service switched off (Settings > VM Manager > Enable VMs: No, domain.cfg SERVICE="disable"): no libvirt
# to answer, no VM to start (2.25). A missing domain.cfg is never taken for off.
ub_vm_service_off() { grep -qE '^SERVICE="?disable"?[[:space:]]*$' "$UB_BOOT/config/domain.cfg" 2>/dev/null; }

UB_RECOVER_READY="${UB_RECOVER_READY:-120}"   # noted containers started again: seconds a tier may take to run ("healthy")
UB_NC_RUN_WAIT="${UB_NC_RUN_WAIT:-120}"       # a noted Nextcloud: seconds its container may take to run before its note stays

# wait_ready <seconds> <name...>: the containers run (and are "healthy" where they have a health check)? 1 after
# <seconds>, or at once when the array is being stopped (Docker is about to stop them all)
wait_ready() {
    local limit="$1" t=0 n st all; shift
    [[ $# -eq 0 ]] && return 0
    while (( t < limit )); do
        array_stopping && return 1
        all=1
        for n in "$@"; do
            st="$(docker inspect -f '{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$n" 2>/dev/null)"
            [[ "$st" == "true " || "$st" == "true healthy" ]] || { all=0; break; }
        done
        (( all )) && return 0
        sleep 2; t=$((t+2))
    done
    return 1
}

# note_write <file> <line...>: what of a note still waits, as a new file + mv; no line left - the note goes
note_write() {
    local f="$1" tmp; shift
    if (( $# == 0 )); then rm -f "$f"; return 0; fi
    tmp="${f%/*}/.${f##*/}.$$"
    if printf '%s\n' "$@" >"$tmp" 2>/dev/null && mv -f "$tmp" "$f"; then return 0; fi
    rm -f "$tmp"
    return 1
}

# If an earlier run was killed hard (kill -9, crash) - or, since 2.24, ended by an array stop -
# state/ lists the containers it had stopped, the Nextclouds it had put into maintenance mode and
# the VMs it held. All are brought back here: at the start of backup.sh and setup.sh, and since 2.25
# right after the array start (backup.sh --recover, started by the plugin's event/started).
# Call only while holding the lock (then no other run is going).
# Never into a stopping array (2.24): the notes stay for the first run after the array start.
# Since 2.25:
#   - a note keeps exactly what didn't come back - a service that doesn't answer (Docker, libvirt), a container
#     or VM that doesn't start, a Nextcloud whose container doesn't run -, rewritten, so the next start tries
#     that again (and only that); what is back, running already or gone leaves it
#   - the containers come back in the order a run starts them (restore_service): network containers, then
#     databases, each tier waited for, then the apps (recover_containers)
#   - a Nextcloud's container is waited for before occ is asked (recover_maintenance)
#   - with Unraid's VM service switched off the VMs' note goes (nothing can start them)
#   - the notification: «Aborted run repaired» when all came back - normal after an array stop (expected,
#     nothing went wrong: the last real run - last-run.json; only real runs write notes - ended with
#     array_stopping and noted them before it finished; a later run's notes would be newer), a warning
#     after a crash -; «Aborted run not fully repaired» (warning) naming what didn't
recover_interrupted_run() {
    local list="" stay="" level="warning" why="An earlier run was aborted" fin f
    if array_stopping && recover_notes; then
        log "The array is being stopped - what an earlier run left stopped stays so (state/stopped, maintenance, vms) until a run after the array start"
        return 0
    fi
    recover_notes || return 0
    if [[ "$(jq -r '"\(.result)|\(.message)"' "$UB_STATE/last-run.json" 2>/dev/null)" == "aborted|array_stopping" ]]; then
        fin="$(jq -r '.finished // 0' "$UB_STATE/last-run.json" 2>/dev/null)"; is_uint "$fin" || fin=0
        level="normal"; why="The run the array stop ended left things for after the array start"
        for f in stopped maintenance vms; do
            [[ -e "$UB_STATE/$f" ]] && (( $(stat -c %Y "$UB_STATE/$f" 2>/dev/null || echo 0) > fin + 2 )) && { level="warning"; why="An earlier run was aborted"; }
        done
    fi
    if [[ -s "$UB_STATE/vms" ]] && ub_vm_service_off; then
        log "Unraid's VM service is switched off - the VMs an earlier run held ($(cut -d'|' -f1 "$UB_STATE/vms" | paste -sd' ' -)) can't be started; their note (state/vms) goes"
        rm -f "$UB_STATE/vms"
    fi
    if [[ -s "$UB_STATE/stopped" || -s "$UB_STATE/maintenance" ]]; then
        if ub_docker_answers; then
            [[ -s "$UB_STATE/stopped" ]] && recover_containers
            [[ -s "$UB_STATE/maintenance" ]] && recover_maintenance
        else
            warn "Docker does not answer - what an earlier run left stopped (state/stopped, maintenance) stays noted for the next start"
            stay+="${stay:+; }Docker did not answer - still noted: $(cat "$UB_STATE/stopped" "$UB_STATE/maintenance" 2>/dev/null | sed '/^$/d' | awk '!s[$0]++' | paste -sd' ' -)"
        fi
    fi
    # VMs the run froze, paused or shut down (lines "name|frozen|paused|shutdown")
    if [[ -s "$UB_STATE/vms" ]] && command -v virsh >/dev/null 2>&1; then
        if ub_libvirt_answers; then
            recover_vms
        else
            warn "libvirt does not answer - the VMs an earlier run held (state/vms) stay noted for the next start"
            stay+="${stay:+; }libvirt did not answer - still noted: VMs $(cut -d'|' -f1 "$UB_STATE/vms" | paste -sd' ' -)"
        fi
    fi
    list="${list% }"
    if [[ -n "$stay" ]]; then
        log "$why - not all of it came back${list:+ (restored: $list)}: $stay"
        ub_notify "Aborted run not fully repaired" "$why. ${list:+Started again or reset: $list. }Not brought back: $stay. What is still noted, the next run tries again." "warning"
    elif [[ -n "$list" ]]; then
        if [[ "$level" == "warning" ]]; then warn "$why - restored: $list"; else log "$why - restored: $list"; fi
        ub_notify "Aborted run repaired" "$why. Started again or reset: $list" "$level"
    fi
    return 0
}

# recover_containers (recover_interrupted_run): state/stopped lists them in the order the run stopped them (apps,
# databases, network) - they start the other way round, like a run's restore_service: the network containers first
# (an app on --network container:<vpn> fails to start before its provider), then the databases, each tier waited for
# (running, "healthy", at most UB_RECOVER_READY s), then the apps. Which tier: from docker inspect of all containers
# (who provides another's network; a database by image, environment or port - or a [dump] section, when settings.ini
# is loaded). Running already (Unraid's autostart) or gone: nothing to do. Adds to the caller's list and stay.
recover_containers() {
    local -a names=() net=() db=() app=() keep=() started=() tier=()
    local -A provider=()
    local n prov t
    mapfile -t names < <(sed '/^$/d' "$UB_STATE/stopped" | awk '!s[$0]++')
    docker_load
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_NET[$n]}" == container:* ]] || continue
        prov="$(ct_resolve "${CT_NET[$n]#container:}")" && provider[$prov]=1
    done
    for n in "${names[@]}"; do
        if [[ -n "${provider[$n]:-}" ]]; then net+=( "$n" )
        elif in_list "$n" $(cfg_names dump) || [[ -n "$(ct_db_type "$n")" ]]; then db+=( "$n" )
        else app+=( "$n" ); fi
    done
    for t in network database app; do
        case "$t" in
            network)  tier=( "${net[@]}" ) ;;
            database) tier=( "${db[@]}" ) ;;
            app)      tier=( "${app[@]}" ) ;;
        esac
        started=()
        for n in "${tier[@]}"; do
            if array_stopping; then keep+=( "$n" ); continue; fi
            case "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" in
                true)  ;;
                false) if docker start "$n" >/dev/null 2>>"${LOG_FILE:-/dev/null}"; then started+=( "$n" ); list+="$n "
                       else err "Container '$n' (from the aborted run) does not start - it stays noted (state/stopped)"; keep+=( "$n" ); fi ;;
                *)     log "  Container '$n' (from the aborted run) is gone - nothing to start" ;;
            esac
        done
        if [[ "$t" != app && ${#started[@]} -gt 0 ]] && ! wait_ready "$UB_RECOVER_READY" "${started[@]}" && ! array_stopping; then
            warn "Not all $t containers started again are ready after ${UB_RECOVER_READY} s (${started[*]}) - starting the next ones anyway"
        fi
    done
    (( ${#keep[@]} )) && stay+="${stay:+; }containers not started (still noted): ${keep[*]}"
    note_write "$UB_STATE/stopped" "${keep[@]}"
}

# recover_maintenance (recover_interrupted_run): each Nextcloud of state/maintenance out of maintenance mode - occ needs
# its container running: one just started above, or one Unraid's autostart is still starting, so it is waited for (at
# most UB_NC_RUN_WAIT s), then occ until it answers (a container just started needs a moment). One whose container never
# runs, or whose occ isn't found or refuses, keeps its note (warning); one that is gone (no such container) can't be
# reached here at all - its note goes, the warning says to check it by hand. Adds to the caller's list and stay.
recover_maintenance() {
    local -a keep=() names=()
    local n st t f occ u i out
    mapfile -t names < <(sed '/^$/d' "$UB_STATE/maintenance" | awk '!s[$0]++')
    for n in "${names[@]}"; do
        if array_stopping; then keep+=( "$n" ); continue; fi
        st="$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)"
        if [[ -z "$st" ]]; then
            warn "Nextcloud '$n' (put into maintenance mode by the aborted run) is gone - if its data serves another container now, switch its maintenance mode off by hand (occ maintenance:mode --off)"
            stay+="${stay:+; }Nextcloud $n is gone (check its maintenance mode by hand)"
            continue
        fi
        t=0
        while [[ "$st" != "true" ]] && (( t < UB_NC_RUN_WAIT )) && ! array_stopping; do
            (( t )) || log "  Nextcloud '$n': waiting for its container to run (at most ${UB_NC_RUN_WAIT} s) ..."
            sleep 2; t=$((t+2))
            st="$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)"
        done
        if [[ "$st" != "true" ]]; then
            array_stopping || warn "Nextcloud '$n' is not running - its maintenance mode stays on, noted (state/maintenance) for the next start"
            keep+=( "$n" ); continue
        fi
        occ=""
        for f in /var/www/html/occ /app/www/public/occ /config/www/nextcloud/occ /var/www/nextcloud/occ; do
            docker exec "$n" test -f "$f" 2>/dev/null && { occ="$f"; break; }
        done
        if [[ -z "$occ" ]]; then
            warn "Nextcloud '$n': occ not found - its maintenance mode stays on, noted (state/maintenance)"
            keep+=( "$n" ); continue
        fi
        u="$(docker exec "$n" stat -c %U "$occ" 2>/dev/null)"; [[ -z "$u" || "$u" == root || "$u" == UNKNOWN ]] && u="www-data"
        for (( i = 0; i < 30; i++ )); do
            docker exec -u "$u" "$n" php "$occ" status >/dev/null 2>&1 && break
            array_stopping && break
            sleep 2
        done
        if out="$(docker exec -u "$u" "$n" php "$occ" maintenance:mode --off 2>&1)"; then
            list+="maintenance mode $n off "
        else
            while IFS= read -r f; do [[ -n "${f//[[:space:]]/}" ]] && log "    occ: ${f%$'\r'}"; done < <(head -20 <<<"$out")
            err "Maintenance mode of '$n' could not be switched off - it stays noted (state/maintenance)"
            keep+=( "$n" )
        fi
    done
    (( ${#keep[@]} )) && stay+="${stay:+; }maintenance mode still on (still noted): ${keep[*]}"
    note_write "$UB_STATE/maintenance" "${keep[@]}"
}

# recover_vms (recover_interrupted_run): each VM of state/vms back as it was - thawed (while it runs), resumed (while
# paused), started (while shut off); in any other state (Unraid shut it down, its autostart started it) or gone there is
# nothing to undo. One that doesn't thaw, resume or start keeps its line. Adds to the caller's list and stay.
recover_vms() {
    local -a keep=()
    local n how st
    while IFS='|' read -r n how; do
        [[ -z "$n" ]] && continue
        if array_stopping; then keep+=( "$n|$how" ); continue; fi
        st="$(timeout 10 virsh domstate "$n" 2>/dev/null | head -1)"
        if [[ -z "$st" ]]; then log "  VM '$n' (from the aborted run) is gone - nothing to do"; continue; fi
        case "$how" in
            frozen)   [[ "$st" == "running" ]] || continue
                      if timeout 30 virsh domfsthaw "$n" >/dev/null 2>&1; then list+="VM $n thawed "
                      else err "VM '$n' (from the aborted run) could not be thawed - it stays noted (state/vms)"; keep+=( "$n|$how" ); fi ;;
            paused)   [[ "$st" == "paused" ]] || continue
                      if timeout 30 virsh resume "$n" >/dev/null 2>&1; then list+="VM $n resumed "
                      else err "VM '$n' (from the aborted run) does not resume - it stays noted (state/vms)"; keep+=( "$n|$how" ); fi ;;
            shutdown) [[ "$st" == "shut off" ]] || continue
                      if timeout 60 virsh start "$n" >/dev/null 2>&1; then list+="VM $n started "
                      else err "VM '$n' (from the aborted run) does not start - it stays noted (state/vms)"; keep+=( "$n|$how" ); fi ;;
        esac
    done <"$UB_STATE/vms"
    (( ${#keep[@]} )) && stay+="${stay:+; }VMs not back (still noted): $(printf '%s\n' "${keep[@]}" | cut -d'|' -f1 | paste -sd' ' -)"
    note_write "$UB_STATE/vms" "${keep[@]}"
}

##############################################################################
# 6. Drift (inventory <-> settings.ini)
##############################################################################
#   DRIFT        lines "level|text"   level: info | warn | error
#   DRIFT_CODE   the same index: "code<US>value" for messages the office translates (since 2.18;
#                empty for the others - their text is shown as it is)
#   SKIP_KOPIA[share]=reason   shares Kopia must not back up this time
declare -ga DRIFT=() DRIFT_CODE=()
declare -gA SKIP_KOPIA=()
drift_add() { DRIFT+=( "$1|$2" ); DRIFT_CODE+=( "${3:-}"$'\x1f'"${4:-}" ); }   # drift_add <level> <text> [code] [value]

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
            # since 2.21 a new container keeps running in a run until the user decided (section 11)
            t="$(ct_db_type "$n")"
            if db_dumpable "$t" && ! cfg_has "dump|$n"; then
                drift_add warn "New database container '$n' ($t, $img${CT_PROJECT[$n]:+, stack ${CT_PROJECT[$n]}}) - keeps running, no dump set up until you decide (its raw data is in the snapshot, crash-consistent)" \
                    new_database "$n"
            elif is_nextcloud_image "$img" && ! cfg_has "nextcloud|$n"; then
                drift_add warn "New Nextcloud container '$n' - keeps running, no maintenance mode set up until you decide" new_nextcloud "$n"
            else
                drift_add info "New container '$n' ($img) - keeps running during the backup until you decide" new_container "$n"
            fi
        fi
        if [[ -n "${CT_VOLUMES[$n]}" && -z "${known[$n]:-}" ]]; then
            drift_add warn "Container '$n' uses Docker volumes ($(cut -d'|' -f1 <<<"${CT_VOLUMES[$n]}" | paste -sd, -)) - they live in the Docker image and are NOT in the backup"
        fi
    done
    for n in $(cfg_names dump) $(cfg_names nextcloud) "${DOCKER_NO_STOP[@]}" "${DOCKER_SKIP[@]}"; do
        [[ -n "${referenced[$n]:-}" ]] && continue
        referenced[$n]=1
        in_list "$n" "${CT_NAMES[@]}" || drift_add warn "Container '$n' is in settings.ini but does not exist"
    done
}

drift_check_vms() {
    local n
    [[ "$VM_SERVICE" == "yes" ]] || return 0
    for n in "${VM_NAMES[@]}"; do
        if ! cfg_has "vm|$n"; then
            drift_add info "New VM '$n' - not set up yet: its disks are in the snapshot of their share, but it is not prepared for it (keeps running) until you decide" \
                new_vm "$n"
            continue
        fi
        [[ "$(vm_mode "$n")" == "off" ]] && {
            [[ -n "${VM_OWN_DS[$n]}" ]] || drift_add info "VM '$n' is set to off, but its disks share a dataset - they are in the share's snapshot anyway"
            continue
        }
        case "${VM_SNAP[$n]}" in
            block)   drift_add warn "VM '$n' uses a whole device as a disk - no snapshot holds it" ;;
            live)    drift_add warn "VM '$n' has a disk on a file system without snapshots - it is not backed up" ;;
            missing) drift_add warn "VM '$n': a disk file was not found" ;;
        esac
    done
    while IFS= read -r n; do
        [[ -n "$n" ]] && ! in_list "$n" "${VM_NAMES[@]}" && drift_add info "VM '$n' is in settings.ini but no longer exists"
    done < <(cfg_names vm)
}

# Kopia check for the run; sets KOPIA_OK and SKIP_KOPIA
#   KP_STATUS    one JSON object per Kopia target (for drift.json): kind, share, path,
#                ok, skipped (the run leaves the share out), differences as codes
#   KP_CHECKED   yes once the policies were compared (Kopia reachable)
#   KOPIA_OK     yes | no | off | none | skip (--no-kopia); "" until checked (2.24: a run ended before its
#                check - e.g. by the array stop - says nothing about Kopia in status.json, never "no")
KOPIA_OK=""
declare -ga KP_STATUS=()
KP_CHECKED="no"
kp_status_add() { # kp_status_add <kind> <name: share, app or VM> <container path> <ok 1/0> <skipped 1/0>
    local diffs
    diffs="$(printf '%s\n' "${KP_CODES[@]}" | jq -R 'select(length > 0) | split("\u001f")
        | {what: .[0], item: (.[1] // ""), have: (.[2] // ""), want: (.[3] // "")}' | jq -sc .)" || diffs='[]'
    # share: only for kind share (as before 2.19); name (since 2.19): the share, app or VM
    KP_STATUS+=( "$(jq -nc --arg k "$1" --arg s "$2" --arg p "$3" --arg ok "$4" --arg sk "$5" --argjson d "${diffs:-[]}" \
        '{kind: $k, share: (if $k == "share" then $s else "" end), name: $s, path: $p, ok: ($ok == "1"), skipped: ($sk == "1"), differences: $d}')" )
}
drift_check_kopia() {
    KOPIA_OK="no"; KP_STATUS=(); KP_CHECKED="no"
    is_yes "$KOPIA_ENABLED" || { KOPIA_OK="off"; return 0; }
    [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" && ${#PLAN_KITEMS[@]} -eq 0 ]] && { KOPIA_OK="none"; return 0; }
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
            # If one of the share's (app's, VM's) own ignore rules is missing, Kopia would upload more
            # than wanted (e.g. a blockchain). This source is then left out. A missing rule for the
            # folder of an app with a source of its own only differs: that folder then goes twice.
            local miss_own="" x key="$share" what="share '$share'"
            [[ "$kind" == "app" || "$kind" == "vm" ]] && { key="$kind:$share"; what="$kind '$share'"; }
            if [[ "$kind" == "share" || "$kind" == "app" || "$kind" == "vm" ]]; then
                while IFS= read -r x; do
                    [[ -n "$x" ]] && grep -Fxq -- "$x" <<<"$KP_MISSING" && miss_own+="$x "
                done < <(cfg_list "$kind|$share|kopia_ignore")
            fi
            if [[ -n "$miss_own" ]]; then
                drift_add error "The Kopia policy for $cpath lacks ignore rules ($miss_own) - $what is NOT given to Kopia until 'setup.sh --kopia' has run"
                SKIP_KOPIA[$key]="policy incomplete"
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

# Apps and VMs with a Kopia source of their own (section 9): gone, or a part in a share that isn't backed up
drift_check_items() {
    local it t n f sh rel c found
    for it in "${PLAN_KITEMS[@]}"; do
        IFS='|' read -r t n f <<<"$it"
        if [[ "$t" == "vm" ]]; then
            [[ "$VM_SERVICE" == "yes" ]] && ! in_list "$n" "${VM_NAMES[@]}" \
                && drift_add info "VM '$n' has a Kopia source of its own in settings.ini but no longer exists - what is left of it still goes there"
            [[ "$(vm_mode "$n")" == "off" ]] \
                && drift_add warn "VM '$n' is left out of the snapshots (mode = off) but has a Kopia source of its own - only its package goes there"
        else
            found=0
            for c in "${CT_NAMES[@]}"; do
                [[ "${CT_PROJECT[$c]:-}" == "$n" || ( -z "${CT_PROJECT[$c]:-}" && "$c" == "$n" ) ]] && { found=1; break; }
            done
            (( found )) || drift_add info "App '$n' has a Kopia source of its own in settings.ini but no container of it exists any more - what is left of it still goes there"
        fi
        while IFS='|' read -r sh rel; do
            [[ -z "$sh" ]] && continue
            in_list "$sh" "${PLAN_MOUNT[@]}" && continue
            drift_add warn "The Kopia source of $t '$n': '$sh/$rel' lies in share '$sh', which takes no snapshot (mode=$(share_mode "$sh")) - that part is left out"
        done < <(kopia_item_parts "$t" "$n")
    done
    return 0
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
#   status.json      running or last run (every mode except unmount); since 2.16 "vms":
#                    per VM what the run did (prepare, done, seconds held, snapshot); "downtime_s":
#                    from stopping the first app until all run again - since 2.22 without waiting for
#                    VMs to shut down (backup.sh does that before anything stops; new phase vm_shutdown)
#   last-run.json   last real backup run (no dry run, no check)
#   history.jsonl    one line per real backup run, the last 200
#   drift.json       drift found by the last check (level + text, since 2.18 a code + value for
#                    the messages the office translates), and since 2.14 per Kopia target
#                    whether its policy matches settings.ini ("policies")
#   since 2.18 status.json and last-run.json carry "packages" (what the run packed, see section 8);
#   history.jsonl keeps only its counts (without "list")
#   since 2.21 status.json carries "new_local": the new folders this run left out of Kopia (section 11;
#   null when it didn't look - no Kopia this time), and state/new-local.json keeps them between runs
#   since 2.20:
#   lock-holder.json who holds state/lock right now (whoever takes the lock writes it, see below)
#   skipped.json     the last run that could not start because the lock was busy; a real backup run
#                    that was skipped also gets a line in history.jsonl ("result": "skipped") - it
#                    never touches status.json, which describes the run going on
#   since 2.24 a run that ends because the array is being stopped: "result": "aborted", "message":
#   "array_stopping" (a code - the office translates backup.message.array_stopping), a history.jsonl
#   line like any run; "kopia" carries "skipped" (the planned sources it didn't do - not failed: the
#   next run does them) and "interrupted" (the source whose upload was interrupted, null if none)
#   since 2.27 "partner" (section 12; null when the run sends to no partner): partners [{id, name}], planned
#   [{id, unit}], current {id, unit, since, bytes} | null, done [{id, unit, snap, from, bytes, seconds, mbit, resumed}],
#   skipped [{id, unit, why}], failed [{id, unit, why}], interrupted {id, unit} | null; and the counts partner_ok,
#   partner_failed, partner_skipped at the top (history.jsonl keeps partner without planned/current)
#   since 2.28 "asleep" (section 13; null unless [general] asleep_pools = skip): mode, pools, shares, units (how many
#   shares were left out), vms, containers, sources, woken, nights; and "kopia.skipped_why": {<source>: "asleep"} for
#   the skipped sources the array stop didn't skip (a source without an entry there: the array stop, as in 2.24)
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
ST_KOPIA_SKIPPED=()       # since 2.24: planned sources not done because the array is being stopped (not failed)
ST_KOPIA_INTERRUPTED=""   # since 2.24: the source whose upload the array stop interrupted
ST_DUMP_BYTES=0
ST_VMS=()                 # lines "name|prepare|done|seconds|snapshot(1/0)" - what the run did with each VM
                          #   done: planned | frozen | paused | shutdown | kept_running | off | not_running | failed
                          #   seconds held: a shutdown from its request until it is started again, a freeze or
                          #   pause from then (since 2.22 also a VM that ignored its shutdown and was paused)
ST_PACKAGES="null"        # JSON object: the packages of this run (backup.sh pkg_status), null = none

status_init() { # status_init <mode>
    ST_MODE="$1"; ST_STARTED="$(date +%s)"; ST_ACTIVE="yes"; ST_PHASE="start"
    status_write
}

status_phase() { ST_PHASE="$1"; status_write; }

status_json() {
    local plan done_ drift skipped
    plan="$(printf '%s\n' "${ST_KOPIA_PLAN[@]}" | jq -R 'select(length > 0)' | jq -sc .)" || plan='[]'
    skipped="$(printf '%s\n' "${ST_KOPIA_SKIPPED[@]}" | jq -R 'select(length > 0)' | jq -sc .)" || skipped='[]'
    done_="$(printf '%s\n' "${ST_KOPIA_DONE[@]}" | jq -R 'select(length > 0) | split("|")
        | {name: .[0], ok: (.[1] == "1"), seconds: (.[2] | tonumber), finished: (.[3] | tonumber)}' | jq -sc .)" || done_='[]'
    drift="$(jq -nc --argjson e "$(drift_count error)" --argjson w "$(drift_count warn)" --argjson i "$(drift_count info)" \
        '{error: $e, warn: $w, info: $i}')" || drift='{}'
    local vms
    vms="$(printf '%s\n' "${ST_VMS[@]}" | jq -R 'select(length > 0) | split("|")
        | {name: .[0], prepare: .[1], done: .[2], seconds: ((.[3] // "0") | tonumber), snapshot: (.[4] == "1")}' | jq -sc .)" || vms='[]'
    local partner asleep why
    partner="$(partner_status_json)" || partner="null"
    asleep="$(asleep_status_json)" || asleep="null"
    why="$(kopia_skipped_why_json)" || why='{}'
    [[ -n "$why" ]] || why='{}'
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
        --arg current "$ST_KOPIA_CUR" --argjson current_since "$ST_KOPIA_CUR_T" --argjson vms "${vms:-[]}" \
        --argjson skipped "${skipped:-[]}" --arg interrupted "$ST_KOPIA_INTERRUPTED" \
        --argjson packages "${ST_PACKAGES:-null}" --argjson new_local "${ST_NEW_LOCAL:-null}" \
        --argjson partner "${partner:-null}" --argjson p_ok "${#ST_PARTNER_DONE[@]}" --argjson p_failed "${#ST_PARTNER_FAILED[@]}" \
        --argjson p_skipped "${#ST_PARTNER_SKIPPED[@]}" --argjson asleep "${asleep:-null}" --argjson skipped_why "$why" \
        '{interface: $interface, name: $name, version: $version, mode: $mode, run: $run, pid: $pid,
          started: $started, updated: $updated, finished: $finished, phase: $phase, result: $result,
          message: $message, errors: $errors, warnings: $warnings, downtime_s: $downtime,
          snapshot: $snapshot, dump_bytes: $dump_bytes, log: $log, drift: $drift, vms: $vms, packages: $packages,
          new_local: $new_local, partner: $partner, partner_ok: $p_ok, partner_failed: $p_failed, partner_skipped: $p_skipped,
          asleep: $asleep,
          kopia: {enabled: ($kopia_enabled | ascii_downcase | test("^(yes|ja|1|true)$")), state: $kopia_ok,
                  planned: $planned, current: (if $current == "" then null else $current end),
                  current_since: (if $current == "" then null else $current_since end), done: $done,
                  skipped: $skipped, skipped_why: $skipped_why,
                  interrupted: (if $interrupted == "" then null else $interrupted end)}}'
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
    ST_PHASE="done"; ST_KOPIA_CUR=""; ST_PARTNER_CUR=""
    status_write
    [[ "$ST_MODE" == "backup" ]] || return 0
    cp -f "$UB_STATE/status.json" "$UB_STATE/.last-run.json.$$" 2>/dev/null \
        && mv -f "$UB_STATE/.last-run.json.$$" "$UB_STATE/last-run.json" 2>/dev/null
    # a short line per run: the packages only as counts
    history_append "$(jq -c 'if (.packages | type) == "object" then .packages |= del(.list) else . end
        | if (.partner | type) == "object" then .partner |= del(.planned, .current) else . end' "$UB_STATE/status.json" 2>/dev/null \
        || tr -d '\n' <"$UB_STATE/status.json")"
    return 0
}

# history_append <json line>: history.jsonl keeps the last UB_HISTORY_MAX lines. A skipped run (below) writes
# while the run holding state/lock may finish - so both take a lock of their own first: an flock on the
# state folder itself (no file of its own; state/lock is the runs' lock and must stay free here)
history_append() {
    [[ -n "$1" ]] || return 0
    (
        flock -w 10 8 || true
        {
            tail -n $((UB_HISTORY_MAX - 1)) "$UB_STATE/history.jsonl" 2>/dev/null
            printf '%s\n' "$1"
        } >"$UB_STATE/.history.jsonl.$$" 2>/dev/null \
            && mv -f "$UB_STATE/.history.jsonl.$$" "$UB_STATE/history.jsonl" 2>/dev/null
        rm -f "$UB_STATE/.history.jsonl.$$"
    ) 8<"$UB_STATE"
    return 0
}

# --- Who holds the lock (since 2.20) -------------------------------------------
# state/lock is held (flock) by one run at a time: backup.sh, setup.sh, Mr. Restori's restore jobs,
# anything else that must not run beside a backup. Whoever takes it writes state/lock-holder.json
# (a new file + mv), and removes it again when done:
#   {"holder": "backup" | "setup" | "restore" | <other name>, "mode": <its mode>, "what": <what it
#    works on, e.g. the app a restore brings back>, "run": <run id>, "pid": <process holding the lock>,
#    "started": <unix seconds>, "version": <engine version>}
# backup.sh: mode backup | check | dryrun | recover (2.25: no run - a run waits for it), run = its run id
# (YYYYMMDD-HHMM); setup.sh: mode plan | apply | forget | check | kopia | interactive | auto. Only holder
# and pid are a must.
# The note is never trusted blindly: the lock itself stays the truth, the note counts only while its
# pid lives (and, for backup.sh and setup.sh, is that script). Unknown or missing = "other" - unless
# status.json names a running run whose pid runs backup.sh (an engine before 2.20 writes no note).
ub_holder_write() { # ub_holder_write <holder> <mode> <run> <started>
    local tmp="$UB_STATE/.lock-holder.json.$$"
    if jq -nc --arg h "$1" --arg m "${2:-}" --arg r "${3:-}" --argjson pid "$$" --argjson started "${4:-$(date +%s)}" \
            --arg v "$UB_VERSION" '{holder: $h, mode: $m, what: "", run: $r, pid: $pid, started: $started, version: $v}' >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$UB_STATE/lock-holder.json" 2>/dev/null
    fi
    rm -f "$tmp"
    return 0
}
# only our own note goes (another holder may already have written its own)
ub_holder_clear() {
    [[ "$(jq -r '.pid // empty' "$UB_STATE/lock-holder.json" 2>/dev/null)" == "$$" ]] && rm -f "$UB_STATE/lock-holder.json"
    return 0
}
# ub_pid_runs <pid> <script name>: is that process running the script (bash <path>/<script> ...)?
ub_pid_runs() {
    local a
    while IFS= read -r -d '' a; do
        [[ "$a" == "$2" || "$a" == */"$2" ]] && return 0
    done < <(head -c 4096 "/proc/$1/cmdline" 2>/dev/null)
    return 1
}
# ub_holder_read: who holds the lock, from the note - sets HOLDER_KIND (backup | check | dryrun |
# setup | restore | other), HOLDER_MODE, HOLDER_WHAT, HOLDER_RUN, HOLDER_PID, HOLDER_STARTED.
# Without a note that counts, a run that status.json calls running while its pid runs backup.sh
# (an engine before 2.20 writes no note) is taken as the holder.
ub_holder_read() {
    HOLDER_KIND="other"; HOLDER_MODE=""; HOLDER_WHAT=""; HOLDER_RUN=""; HOLDER_PID=0; HOLDER_STARTED=0
    local line h="" m="" w="" r="" p="" t="" kind=""
    if [[ -s "$UB_STATE/lock-holder.json" ]]; then
        line="$(jq -r 'def s: tostring | gsub("[\u0000-\u001f]"; " ") | .[:80];
            [(.holder // "" | s), (.mode // "" | s), (.what // "" | s), (.run // "" | s), (.pid // 0 | s), (.started // 0 | s)]
            | join("\u001f")' "$UB_STATE/lock-holder.json" 2>/dev/null)"
        IFS=$'\x1f' read -r h m w r p t <<<"$line"
        if is_uint "$p" && (( p > 1 && p != $$ )) && kill -0 "$p" 2>/dev/null; then
            case "$h" in
                backup)  ub_pid_runs "$p" backup.sh && kind="backup" ;;
                setup)   ub_pid_runs "$p" setup.sh && kind="setup" ;;
                restore) kind="restore" ;;
                *)       kind="other" ;;
            esac
        fi
    fi
    if [[ -z "$kind" ]]; then
        line="$(jq -r 'select(.result == "running") | [(.mode // "" | tostring), (.run // "" | tostring), (.pid // 0 | tostring),
            (.started // 0 | tostring)] | map(gsub("[\u0000-\u001f]"; " ")) | join("\u001f")' "$UB_STATE/status.json" 2>/dev/null)"
        IFS=$'\x1f' read -r m r p t <<<"$line"; w=""
        is_uint "$p" && (( p > 1 && p != $$ )) && kill -0 "$p" 2>/dev/null && ub_pid_runs "$p" backup.sh || return 0
        kind="backup"
    fi
    if [[ "$kind" == "backup" ]]; then
        case "$m" in check|dryrun) HOLDER_KIND="$m" ;; *) HOLDER_KIND="backup" ;; esac
    else
        HOLDER_KIND="$kind"
    fi
    HOLDER_MODE="$m"; HOLDER_WHAT="$w"; HOLDER_PID="$p"
    [[ "$r" =~ ^[0-9]{8}-[0-9]{4}$ ]] && HOLDER_RUN="$r"
    is_uint "$t" && HOLDER_STARTED="$t"
    return 0
}

# status_skipped <mode> <reason> <holder's phase> <holder's current Kopia source>: a run that could
# not take the lock - state/skipped.json (every mode), and for a real backup run a line in
# history.jsonl. Shaped like a status line ("result": "skipped", "message" = the reason code), so a
# reader of history.jsonl that doesn't know it sees a run without Kopia, errors or downtime.
status_skipped() {
    local tmp="$UB_STATE/.skipped.json.$$" now line
    now="$(date +%s)"
    line="$(jq -nc --argjson interface "$UB_INTERFACE" --arg name "$UB_NAME" --arg version "$UB_VERSION" \
        --arg mode "$1" --arg run "${TS:-}" --argjson pid "$$" --argjson time "$now" --arg reason "$2" \
        --arg kind "$HOLDER_KIND" --arg hmode "$HOLDER_MODE" --arg what "$HOLDER_WHAT" --arg hrun "$HOLDER_RUN" \
        --argjson hpid "${HOLDER_PID:-0}" --argjson hstarted "${HOLDER_STARTED:-0}" --arg phase "${3:-}" --arg current "${4:-}" \
        '{interface: $interface, name: $name, version: $version, mode: $mode, run: $run, pid: $pid,
          time: $time, started: $time, finished: $time, result: "skipped", reason: $reason, message: $reason,
          errors: 0, warnings: 0, downtime_s: 0,
          holder: {kind: $kind, mode: $hmode, what: $what, run: $hrun, pid: $hpid, started: $hstarted,
                   phase: $phase, current: $current}}' 2>/dev/null)" || return 0
    if printf '%s\n' "$line" >"$tmp" 2>/dev/null; then mv -f "$tmp" "$UB_STATE/skipped.json" 2>/dev/null; fi
    rm -f "$tmp"
    [[ "$1" == "backup" ]] && history_append "$line"
    return 0
}

# drift.json: {time, items: [{level, text, code, value}], policies: null (not compared) | [KP_STATUS ...]}
#   code/value: since 2.18, for the messages the office translates ("" for the others)
drift_json_write() {
    local tmp="$UB_STATE/.drift.json.$$" pol="null" i
    [[ "$KP_CHECKED" == "yes" ]] && { pol="$(printf '%s\n' "${KP_STATUS[@]}" | jq -sc .)" || pol="null"; }
    if for i in "${!DRIFT[@]}"; do printf '%s\x1f%s\n' "${DRIFT[$i]%%|*}" "${DRIFT_CODE[$i]:-$'\x1f'}"; done \
            | jq -R 'select(length > 0) | split("\u001f") | {level: .[0], code: (.[1] // ""), value: (.[2] // "")}' \
            | jq -sc --argjson t "$(date +%s)" --argjson p "${pol:-null}" --args \
              '[., ($ARGS.positional | map(index("|") as $i | .[$i + 1:]))] | transpose | map(.[0] + {text: .[1]})
               | {time: $t, items: ., policies: $p}' "${DRIFT[@]}" >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$UB_STATE/drift.json" 2>/dev/null
    else
        rm -f "$tmp"
    fi
    return 0
}

##############################################################################
# 8. Packages (since 2.18)
##############################################################################
# The backup place (dumps_path: <share>/unraid-backup, in the office's share <share>/backup)
# holds one package per app and VM - small files only, overwritten by every run. The big data
# stays in the snapshots; the history of the packages lies in the snapshots of the backup
# place's own share (which therefore takes at least local snapshots). Kopia reads both.
#   apps/<app>/   manifest.json (containers with their docker inspect, images, dumps, files),
#                 my-<name>.xml (Unraid templates) or compose/ (the Compose Manager's project
#                 folder) and compose-files/ (compose files living elsewhere, e.g. indirect
#                 stacks), db/ (database dumps), nextcloud/ (config.php, occ lists)
#   vms/<vm>/     manifest.json (disks, the snapshot holding them, how the VM was held),
#                 <vm>.xml, nvram/, tpm/<uuid>/, snapshotdb/ (Unraid's VM snapshot list)
#   flash/        flash.tar.gz ([flash] mode = tar)
#   server/       what belongs to no app: run.json (which run wrote the packages, the result
#                 of each), settings.ini, shares/, docker lists, libvirt.tar.gz (all of
#                 libvirt.img), templates and compose projects without a container
# An app is a compose project (named after it) or a single container (named after it). A
# package is built in .ub-stage-<run>/ and swapped in only when complete; the one it replaces
# goes aside as .ub-old-<run>-<folder> for that moment. Packages of apps and VMs a run leaves
# out (gone, or not backed up) are never deleted - the office lists them as stale.

# pkg_folder <name>  -> the folder for an app or VM: letters, digits, . _ - only, no leading dot
pkg_folder() {
    local f
    f="$(printf '%s' "$1" | LC_ALL=C sed 's/[^A-Za-z0-9._-]/_/g; s/^\./_/')"
    printf '%s' "${f:-_}"
}

# old_runs_list <dir>  -> the run folders of engines before 2.18 directly in <dir>, one per line:
# named like a run (YYYYMMDD-HHMM), owned by root, no mount point or link, and holding nothing but
# what those runs wrote (db/, manifest/, libvirt.tar.gz, flash*.tar*). Anything else stays.
old_runs_list() {
    local dir="$1" d e bad
    [[ -n "$dir" && -d "$dir" && ! -L "$dir" ]] || return 0
    for d in "$dir"/[0-9]*-[0-9]*; do
        [[ -d "$d" && ! -L "$d" && "${d##*/}" =~ ^[0-9]{8}-[0-9]{4}$ ]] || continue
        [[ "$(stat -c %u "$d" 2>/dev/null)" == "0" ]] || continue
        mountpoint -q "$d" 2>/dev/null && continue
        bad=0
        for e in "$d"/* "$d"/.[!.]* "$d"/..?*; do
            [[ -e "$e" || -L "$e" ]] || continue
            case "${e##*/}" in
                db|manifest)    [[ -d "$e" && ! -L "$e" ]] || bad=1 ;;
                libvirt.tar.gz|flash*.tar*) [[ -f "$e" && ! -L "$e" ]] || bad=1 ;;
                *)              bad=1 ;;
            esac
            (( bad )) && break
        done
        (( bad )) || printf '%s\n' "$d"
    done
    return 0
}

# pkg_recover <base>  -> what a killed run left behind: a package it had put aside goes back when
# its place is empty (otherwise the aside copy goes), a half-built stage goes. Prints the count.
pkg_recover() {
    local base="$1" sub d name n=0
    [[ -n "$base" && -d "$base" && ! -L "$base" ]] || { echo 0; return 0; }
    for sub in apps vms .; do
        for d in "$base/$sub"/.ub-old-*; do
            [[ -d "$d" && ! -L "$d" ]] || continue
            name="${d##*/.ub-old-}"; name="${name:14}"          # .ub-old-YYYYMMDD-HHMM-<folder>
            [[ -n "$name" && "$name" != */* ]] || continue
            if [[ -e "$base/$sub/$name" ]]; then rm -rf -- "$d"; else mv -- "$d" "$base/$sub/$name"; fi
            n=$((n+1))
        done
    done
    for d in "$base"/.ub-stage-*; do
        [[ -d "$d" && ! -L "$d" ]] || continue
        rm -rf -- "$d"; n=$((n+1))
    done
    echo "$n"
}

##############################################################################
# 9. Kopia per app and VM (since 2.19)
##############################################################################
# An app or a VM the office sets to "local + Kopia" is a Kopia source of its own:
#   [app "<name>"] / [vm "<name>"]
#     kopia = yes                  a source of its own
#     folder = <share>/<folder>    what it keeps in shares (repeatable): an app's folders in container-data
#                                  shares (appdata/<app>), a VM's folder in domains
#     kopia_retention = latest hourly daily weekly monthly annual   (default: [kopia] keep_*)
#     kopia_ignore = /<share>/<path>/   rules relative to its source (repeatable)
# The source joins its folders and its package (apps/<app>/ or vms/<vm>/ in the backup place) under
#   <mount_root>/.apps/<name>/<share>/<path>      (.vms/<name>/ for a VM)
# - read-only binds out of the shares' mounted snapshots, so it mirrors where everything lies on the
# server (Kopia names it <container path>/.apps/<name>). The shares those parts lie in leave them out
# of their own sources (kopia_derived_ignores): nothing goes twice, and the item's own retention holds.
# Same repository: what Kopia already has (from the share's source) is not uploaded again.
# Why not <mount_root>/<share>/<folder> as the source: Kopia merges the ignore rules of the policies on
# every parent path into a source and anchors them at the source's root - an app under
# <container path>/appdata would also lose its own subfolder mariadb/ because appdata leaves out the
# folder of another app called mariadb. A folder whose name begins with a dot is never taken for a
# share (inv_scan skips them, Unraid makes no such shares), so these paths only inherit the policy on
# <mount_root> (global rules, retention, manual, one-file-system = false) and, like a share, an item
# carries only what differs. (Not '@': Kopia reads a path with '@' and without ':' as user@host.)

# item_folder_ok <share>/<path>  -> 0 when it names a path inside a share (no leading /, no .., no empty parts)
item_folder_ok() {
    local f="$1" sh="${1%%/*}" rel="${1#*/}"
    [[ "$f" == */* && "$f" != /* && -n "$sh" && -n "$rel" && "$f" != *$'\n'* ]] || return 1
    share_name_ok "$sh" || return 1
    [[ "/$rel/" != *"/../"* && "/$rel/" != *"/./"* && "$rel" != *//* && "$rel" != */ ]]
}

# item_hostpath <kind> <folder>  -> where its source is assembled on the host
item_hostpath() { printf '%s/.%ss/%s' "$MOUNT_ROOT" "$1" "$2"; }

# dumps_rel  -> the backup place relative to its share (dumps_path): backup in the office's share, else unraid-backup
dumps_rel() { if [[ "$DUMPS_SHARE" == "$UB_OFFICE_SHARE" ]]; then printf '%s' "$UB_DESK_DIR"; else printf '%s' "$UB_NAME"; fi; }

# kopia_items  -> lines "kind|name|folder" of the apps (first) and VMs with a Kopia source of their own;
# folder: the name made safe (pkg_folder) - the second of two that come out the same gets a suffix
kopia_items() {
    is_yes "${KOPIA_ENABLED:-no}" || return 0
    local t n f
    local -A taken=()
    for t in app vm; do
        taken=()
        while IFS= read -r n; do
            [[ -n "$n" && "$n" != */* && "$(cfg "$t|$n|kopia" no)" == "yes" ]] || continue
            f="$(pkg_folder "$n")"
            [[ -n "${taken[$f]:-}" ]] && f="$f-$(printf '%s' "$n" | md5sum | cut -c1-6)"
            taken[$f]=1
            printf '%s|%s|%s\n' "$t" "$n" "$f"
        done < <(cfg_names "$t" | LC_ALL=C sort)
    done
    return 0
}

# kopia_item_parts <kind> <name> [package folder]  -> lines "share|path": its folders, then its package in
# the backup place (the package folder as given, "-" = none; otherwise its name made safe)
kopia_item_parts() {
    local t="$1" n="$2" pf="${3:-}" f
    while IFS= read -r f; do
        item_folder_ok "$f" && printf '%s|%s\n' "${f%%/*}" "${f#*/}"
    done < <(cfg_list "$t|$n|folder")
    [[ -n "${DUMPS_SHARE:-}" ]] || return 0
    [[ -z "$pf" ]] && pf="$(pkg_folder "$n")"
    [[ "$pf" == "-" ]] && return 0
    printf '%s|%s/%ss/%s\n' "$DUMPS_SHARE" "$(dumps_rel)" "$t" "$pf"
}

# share_layout <share>  -> how its snapshot is mounted: single | overlay | split | live | none
share_layout() {
    if [[ "$(share_method "$1")" == "live" ]]; then echo live; else echo "${INV_LAYOUT[$1]:-none}"; fi
}

# kopia_derived_ignores <share>  -> the rules the share's own source gets for the parts of apps and VMs
# with a source of their own (one per base when the share appears as one subfolder per base)
kopia_derived_ignores() {
    local s="$1" t n f sh rel b
    while IFS='|' read -r t n f; do
        [[ -z "$t" ]] && continue
        while IFS='|' read -r sh rel; do
            [[ "$sh" == "$s" && -n "$rel" ]] || continue
            if [[ "$(share_layout "$s")" == "split" ]]; then
                while IFS='|' read -r b _; do [[ -n "$b" ]] && printf '/%s/%s/\n' "$b" "$rel"; done <<<"${INV_LOCS[$s]:-}"
            else
                printf '/%s/\n' "$rel"
            fi
        done < <(kopia_item_parts "$t" "$n")
    done < <(kopia_items)
    return 0
}

# uri_escape <path>  -> the path for an SQLite URI (file:...): everything but letters, digits and / . _ ~ - as %XX
uri_escape() {
    local LC_ALL=C p="$1" out="" ch i
    for (( i = 0; i < ${#p}; i++ )); do
        ch="${p:i:1}"
        case "$ch" in [A-Za-z0-9/._~-]) out+="$ch" ;; *) out+="$(printf '%%%02X' "'$ch")" ;; esac
    done
    printf '%s' "$out"
}

# --- The order of the Kopia phase (since 2.25) ---------------------------------
# Small and important first: the flash (what a new server needs first, a few MB), then the apps' own
# sources in their order (small, and what a restore needs first), then the shares and the VMs' own sources
# together, the smallest first - so a first upload of terabytes, which takes a day or more and is continued
# run after run from Kopia's checkpoints, no longer holds back everything queued behind it. (Up to 2.24: the
# apps, the shares in settings.ini's order, the VMs, the flash last - on 2026-10-07 a 2.3 TB first upload of
# one share kept the flash, appdata and the VMs from Kopia for days.)
# The size a source is expected to have, from what is cheap and reliable: Kopia's (KSIZE, kopia_sizes_load - the
# size of its newest complete snapshot, what Kopia read then with its ignore rules applied, or of a newer checkpoint
# when that is larger) and the server's (ZFS's referenced for a share that is a dataset of its own, INV_BYTES; the
# VM's disk files' own sizes, VM_APPARENT - since 2.26: Kopia reads a sparse vdisk whole, its holes as zeros, so a
# 1.6 TB vdisk holding 21 GB is 1.6 TB of reading at its first upload (nostromo's Windows11_Gaming, 2026-10-07:
# 382 GB by its snapshot, 2 TB read in 2.7 h); up to 2.25 the allocated blocks, VM_BYTES, put such a VM far too
# early) - the LARGER of the two when both are known, either alone when only one is. A complete
# snapshot alone can be stale: a share whose folders were all ignored until the setup changed has a tiny complete
# snapshot while its first real upload of terabytes is still going on in checkpoints (nostromo's Backups,
# 2026-10-07) - by that size it would go first; the checkpoint (or ZFS) says better. The server's overestimates a
# share with big ignored parts, which only moves it later. An
# unknown size goes last; equal sizes and the unknown keep their order (the shares as settings.ini lists
# them, then the VMs).
# kopia_order  -> lines "name|kind|item|folder|bytes|from" in the order they go:
#   name    as status.json names it (kopia.planned): flash, app:<name>, <share>, vm:<name>
#   kind    flash | app | share | vm;   item: the share's, app's or VM's name;   folder: an app's or VM's source
#   bytes   the size it is expected to have ("" = unknown; none for the flash and the apps - their place is fixed)
#   from    kopia | inventory (whichever was larger) | "" (unknown)
# kopia_expect <Kopia's bytes> <the server's bytes>  -> "bytes|from": the larger known one ("|" = unknown)
kopia_expect() {
    if is_uint "$1" && { ! is_uint "$2" || (( $1 >= $2 )); }; then printf '%s|kopia' "$1"
    elif is_uint "$2"; then printf '%s|inventory' "$2"
    else printf '|'; fi
}
kopia_order() {
    local it t n f s cp b from i=0
    [[ "${PLAN_FLASH:-}" == "snapshot" ]] && printf 'flash|flash||||\n'
    for it in "${PLAN_KITEMS[@]}"; do
        IFS='|' read -r t n f <<<"$it"
        [[ "$t" == "app" ]] && printf 'app:%s|app|%s|%s||\n' "$n" "$n" "$f"
    done
    {
        for s in "${PLAN_KOPIA[@]}"; do
            cp="$(k_path "$(share_kopia_hostpath "$s")" 2>/dev/null)" || cp=""
            IFS='|' read -r b from <<<"$(kopia_expect "${cp:+${KSIZE[$cp]:-}}" "${INV_BYTES[$s]:-}")"
            i=$((i+1)); printf '%d\t%s\t%d\t%s|share|%s||%s|%s\n' "$([[ -n "$from" ]] && echo 0 || echo 1)" "${b:-0}" "$i" "$s" "$s" "$b" "$from"
        done
        for it in "${PLAN_KITEMS[@]}"; do
            IFS='|' read -r t n f <<<"$it"
            [[ "$t" == "vm" ]] || continue
            cp="$(k_path "$(item_hostpath vm "$f")" 2>/dev/null)" || cp=""
            IFS='|' read -r b from <<<"$(kopia_expect "${cp:+${KSIZE[$cp]:-}}" "${VM_APPARENT[$n]:-}")"
            i=$((i+1)); printf '%d\t%s\t%d\tvm:%s|vm|%s|%s|%s|%s\n' "$([[ -n "$from" ]] && echo 0 || echo 1)" "${b:-0}" "$i" "$n" "$n" "$f" "$b" "$from"
        done
    } | LC_ALL=C sort -t $'\t' -k1,1n -k2,2n -k3,3n | cut -f4-
    return 0
}

##############################################################################
# 10. Snapshot names (since 2.20)
##############################################################################
# The engine's ZFS snapshots (the shares' datasets and the flash's) are called <prefix>YYYYMMDD-HHMM,
# [general] snap_prefix. Since 2.20 the default prefix is uso-backup- (what the office makes is named
# uso-...; Ms. Snapshotini's schedules: uso-plan-<plan>-...). From 2.9 to 2.19 it was unraidbackup-, and
# settings.ini files of that time name it: exactly that old default counts as the default. New
# snapshots then get uso-backup-; the unraidbackup- ones stay the engine's and age out by the normal
# retention - one series per dataset, by creation, as if they had the new name. A prefix of the user's
# own stays as it is, and alone (nothing else of the engine's is cleared away then - as before 2.20).
# Matching is exact: <prefix> + 8 digits + "-" + 4 digits, nothing before or after - never looser.
# The office follows the same rule (backupSnapPrefixes() in agent/lib/backupscript.php).
# The engine's btrfs snapshots are folders <btrfs_snap_dir>/YYYYMMDD-HHMM on each disk (unchanged).

# snap_prefix_resolve <prefix as settings.ini says it>  -> SNAP_PREFIX (new snapshots), SNAP_PREFIXES (all of the engine's)
snap_prefix_resolve() {
    local p="${1:-$UB_SNAP_PREFIX}"
    if [[ "$p" == "$UB_SNAP_PREFIX" || "$p" == "$UB_SNAP_PREFIX_LEGACY" ]]; then
        SNAP_PREFIX="$UB_SNAP_PREFIX"
        SNAP_PREFIXES=( "$UB_SNAP_PREFIX" "$UB_SNAP_PREFIX_LEGACY" )
    else
        SNAP_PREFIX="$p"
        SNAP_PREFIXES=( "$p" )
    fi
}

# snap_is_ours <dataset@name>  -> 0 when it is exactly one of the engine's snapshot names (SNAP_PREFIXES)
snap_is_ours() {
    local s="$1" n p
    [[ "$s" == ?*@?* && "$s" != *$'\n'* ]] || return 1
    n="${s##*@}"
    for p in "${SNAP_PREFIXES[@]}"; do
        [[ -n "$p" && "$n" == "$p"* && "${n#"$p"}" =~ ^[0-9]{8}-[0-9]{4}$ ]] && return 0
    done
    return 1
}

# snap_filter  -> of the snapshot names on stdin (dataset@name, one per line) only the engine's
snap_filter() {
    local s
    while IFS= read -r s; do snap_is_ours "$s" && printf '%s\n' "$s"; done
    return 0
}
# snap_filter_not  -> the others
snap_filter_not() {
    local s
    while IFS= read -r s; do snap_is_ours "$s" || printf '%s\n' "$s"; done
    return 0
}

# snap_prefix_ok <prefix>  -> 0 when it will do as [general] snap_prefix (the same rule as cfg_validate);
# a new one also never begins with auto- (Ms. Snapshotini's schedules before 2.20)
snap_prefix_ok() {
    [[ "$1" =~ ^[a-z0-9_]+(-[a-z0-9_]+)*-$ && "$1" != uso-plan-* && "$1" != auto-* ]]
}

# zfs_prune_select <"d w m">  -> of one dataset's snapshots on stdin (dataset@name, oldest first, as
# "zfs list -s creation" gives them) those the retention lets go: only the engine's (snap_is_ours), never
# anything else. Kept: the newest d, the newest of each of the last w weeks and of the last m months (by
# the time in the name). A retention that isn't three numbers lets nothing go.
zfs_prune_select() {
    local keep_d keep_w keep_m s i n stamp day week month
    read -r keep_d keep_w keep_m _ <<<"$1"
    is_uint "$keep_d" && is_uint "$keep_w" && is_uint "$keep_m" || { cat >/dev/null; return 0; }
    local -a snaps=()
    while IFS= read -r s; do snap_is_ours "$s" && snaps+=( "$s" ); done
    [[ ${#snaps[@]} -eq 0 ]] && return 0
    local -A keep=() wk=() mo=()
    for (( i=${#snaps[@]}-1, n=0; i>=0 && n<keep_d; i--, n++ )); do keep[${snaps[$i]}]=1; done
    for (( i=${#snaps[@]}-1; i>=0; i-- )); do
        stamp="${snaps[$i]: -13}"                        # YYYYMMDD-HHMM, whichever prefix
        day="${stamp:0:4}-${stamp:4:2}-${stamp:6:2}"
        week="$(date -d "$day" +%G-%V 2>/dev/null)"
        month="${stamp:0:6}"
        if (( keep_w > 0 )) && [[ -n "$week" && -z "${wk[$week]:-}" ]] && (( ${#wk[@]} < keep_w )); then
            wk[$week]=1; keep[${snaps[$i]}]=1
        fi
        if (( keep_m > 0 )) && [[ -z "${mo[$month]:-}" ]] && (( ${#mo[@]} < keep_m )); then
            mo[$month]=1; keep[${snaps[$i]}]=1
        fi
    done
    for s in "${snaps[@]}"; do [[ -n "${keep[$s]:-}" ]] || printf '%s\n' "$s"; done
    return 0
}

# --- What the retention removed (since 2.21) ------------------------------------
# state/pruned.json says which snapshots the engine itself destroyed, run by run - so whoever watches the
# server (the night watchman) doesn't have to guess or read logs:
#   {interface, version, updated, runs: [{run, time, zfs: ["pool/ds@name", ...], btrfs: ["/mnt/diskN/.btrfs-snap/
#    YYYYMMDD-HHMM", ...]}, ...]}   newest last; a run that removed nothing has empty lists
# Kept: the last UB_PRUNED_RUNS runs (30), none older than UB_PRUNED_DAYS (30); per run at most UB_PRUNED_CAP
# names per list (1000), what is left out counted in zfs_more / btrfs_more. A new file + mv; the state folder is root's.
UB_PRUNED_RUNS="${UB_PRUNED_RUNS:-30}"
UB_PRUNED_DAYS="${UB_PRUNED_DAYS:-30}"
UB_PRUNED_CAP="${UB_PRUNED_CAP:-1000}"
declare -ga PRUNED_ZFS=() PRUNED_BTRFS=()   # what this run's retention removed (backup.sh prune_zfs, prune_btrfs)

# pruned_write [time]  -> this run's PRUNED_ZFS / PRUNED_BTRFS appended to state/pruned.json
pruned_write() {
    local now="${1:-$(date +%s)}" old='{}' z b tmp="$UB_STATE/.pruned.json.$$"
    if [[ -s "$UB_STATE/pruned.json" ]]; then
        old="$(jq -c 'if type == "object" and (.runs | type) == "array" then . else {} end' "$UB_STATE/pruned.json" 2>/dev/null)" || old='{}'
        [[ -n "$old" ]] || old='{}'
    fi
    z="$(printf '%s\n' "${PRUNED_ZFS[@]}" | jq -R 'select(length > 0)' | jq -sc .)" || z='[]'
    b="$(printf '%s\n' "${PRUNED_BTRFS[@]}" | jq -R 'select(length > 0)' | jq -sc .)" || b='[]'
    if jq -nc --argjson old "$old" --argjson z "${z:-[]}" --argjson b "${b:-[]}" --arg run "${TS:-}" --argjson time "$now" \
            --argjson interface "$UB_INTERFACE" --arg version "$UB_VERSION" --argjson runs "$UB_PRUNED_RUNS" \
            --argjson days "$UB_PRUNED_DAYS" --argjson cap "$UB_PRUNED_CAP" '
        def entry: {run: $run, time: $time, zfs: $z[:$cap], btrfs: $b[:$cap]}
            + (if ($z | length) > $cap then {zfs_more: (($z | length) - $cap)} else {} end)
            + (if ($b | length) > $cap then {btrfs_more: (($b | length) - $cap)} else {} end);
        ([($old.runs // [])[] | select(type == "object" and ((.time // 0) | type) == "number")] + [entry])
        | map(select(.time >= $time - $days * 86400)) | .[-$runs:]
        | {interface: $interface, version: $version, updated: $time, runs: .}' >"$tmp" 2>/dev/null && [[ -s "$tmp" ]]; then
        mv -f "$tmp" "$UB_STATE/pruned.json" 2>/dev/null
    fi
    rm -f "$tmp"
    return 0
}

##############################################################################
# 11. New things stay local (since 2.21)
##############################################################################
# What is new on the server is backed up only locally and without stopping, on its own - Kopia, and
# stopping or pausing, only once the user decided (Mr. Backupsy's setup, or setup.sh in a terminal):
#   - a container not in [docker] known keeps running in a run (backup.sh build_stop_tiers; setup.sh
#     proposes it so too); a VM without a [vm] section is not held (prepare none, as before)
#   - a share going to Kopia records its top-level folders when the setup is applied:
#       [share "<name>"] kopia_known = /<folder>/   (repeatable; an empty "kopia_known =" means recorded,
#                                                    none yet)
#     A share with more than UB_KNOWN_MAX folders at its top when it is first recorded is a collection (films,
#     photos - a new folder there is the collection growing, not a new thing): kopia_known = * - every folder
#     goes, new ones too, as before 2.21; listing folders instead makes new ones wait there too.
#     A top-level folder that is neither known nor left out - by the share's kopia_ignore, the global
#     [kopia] ignore, as a part of an app or VM with a Kopia source of its own (section 9), or as the
#     backup place's own folder - is NEW: the run leaves it out of the share's Kopia source (rules it
#     adds to the share's policy right before the upload and takes away again once the folder is
#     decided or gone); the local snapshot holds it all the same. A share without any kopia_known line
#     works as before 2.21 (every folder goes) until the setup is applied once. The setup shows the new
#     folders ("waiting for your decision"): only local = kopia_ignore, local + Kopia = kopia_known.
#   - an app's or VM's own Kopia source holds only its folder = lines and its package: nothing new
#     reaches it without the setup.
# The run says so: a log line, status.json "new_local", drift info new_waiting, state/new-local.json, and
# one notification (normal) for the folders it sees for the first time - not every night:
#   state/new-local.json  {interface, version, run, time, folders: [{share, folder, bytes, first_seen, rules}]}
#                         bytes: only where it is cheap (a ZFS dataset of its own), else null; rules: the
#                         ignore rules the run set for it. Written by real backup runs that reached Kopia.

UB_KNOWN_MAX="${UB_KNOWN_MAX:-500}"   # more folders at a share's top at its first record: a collection (kopia_known = *)
declare -gA NEW_RULES=()       # share -> ignore rules for its new folders (lines): part of its wanted policy
declare -gA NEW_SEEN=()        # "share|folder" -> first seen (unix), from state/new-local.json
declare -gA NEW_SEEN_RULES=()  # "share|folder" -> the rules a run set for it (lines)
declare -gA NEW_SEEN_BYTES=()  # "share|folder" -> its size as noted ("" = unknown)
declare -ga NEW_LIST=()        # new folders: "share<US>folder<US>bytes<US>first seen<US>rules joined by <RS>"
declare -gA ND_KNOWN=() ND_PARTS=()   # new_decided_load: the share's known folders, its folders that are decided otherwise
declare -ga SK_DIRS=()         # share_top_live: the top-level folders on the awake parts
SK_ASLEEP="no"
ND_SHARE=""
ST_NEW_LOCAL="null"            # status.json "new_local" (null: this run didn't look)

# share_watched <share>  -> 0 when the share's new folders stay local: it goes to Kopia and its folders are recorded
# (not "*", a collection)
share_watched() {
    is_yes "${KOPIA_ENABLED:-no}" && [[ "$(share_mode "$1")" == "kopia" && -n "${CFG[share|$1|kopia_known]+x}" \
        && $'\n'"${CFG[share|$1|kopia_known]}"$'\n' != *$'\n*\n'* ]]
}

# top_dirs <dir>  -> the folders right inside it, one per line: no links, no .zfs or lost+found, no names with
# control characters (they could not travel through the state files)
top_dirs() {
    local d="$1" p n
    [[ -d "$d" ]] || return 0
    for p in "$d"/* "$d"/.[!.]* "$d"/..?*; do
        [[ -d "$p" && ! -L "$p" ]] || continue
        n="${p##*/}"
        [[ "$n" == ".zfs" || "$n" == "lost+found" || "$n" == *[$'\x01'-$'\x1f']* ]] && continue
        printf '%s\n' "$n"
    done
    return 0
}

# share_top_live <share>  -> SK_DIRS: its top-level folders as they are now, on the parts that are awake;
# SK_ASLEEP=yes when a part sleeps (never woken - its folders are then not all known)
share_top_live() {
    local s="$1" b n
    local -A seen=()
    SK_DIRS=(); SK_ASLEEP="no"
    while IFS='|' read -r b _; do
        [[ -n "$b" ]] || continue
        if ub_base_asleep "$b"; then SK_ASLEEP="yes"; continue; fi
        while IFS= read -r n; do
            [[ -n "$n" && -z "${seen[$n]:-}" ]] || continue
            seen[$n]=1; SK_DIRS+=( "$n" )
        done < <(top_dirs "${INV_BASE_PATH[$b]:-/nonexistent}/$s")
    done <<<"${INV_LOCS[$s]:-}"
    mapfile -t SK_DIRS < <(printf '%s\n' "${SK_DIRS[@]}" | sed '/^$/d' | LC_ALL=C sort)
}

# new_rule_name <folder>  -> the folder as part of an ignore rule: a character Kopia reads as a pattern
# (* ? [ ] \) becomes ? - at worst a look-alike is left out too (it stays local), never less
new_rule_name() { local n="$1"; printf '%s' "${n//[]*?[\\]/?}"; }

# new_rules_for <share> <folder>  -> the rules that leave the folder out of the share's source: /<folder>/,
# or one per base when the share appears as one subfolder per base (split)
new_rules_for() {
    local s="$1" n b
    n="$(new_rule_name "$2")"
    if [[ "$(share_layout "$s")" == "split" ]]; then
        while IFS='|' read -r b _; do [[ -n "$b" ]] && printf '/%s/%s/\n' "$b" "$n"; done <<<"${INV_LOCS[$s]:-}"
    else
        printf '/%s/\n' "$n"
    fi
    return 0
}

# rule_hides_top <rule> <folder>  -> 0 when the ignore rule leaves out that top-level folder as a whole:
# /x/, /x, x/ or x - x a name or a simple pattern (* ? [..]) as Kopia reads it. Deeper rules and anything
# fancier don't count: such a folder stays new, so the run leaves it out itself - never uploaded unasked
rule_hides_top() {
    local r="$1" n="$2"
    [[ -n "$r" && "$r" != '!'* && "$r" != '#'* && "$r" != *[\\\(\)\|\{\}]* ]] || return 1
    r="${r#/}"; r="${r%/}"
    [[ -n "$r" && "$r" != */* ]] || return 1
    # shellcheck disable=SC2053   # a pattern on purpose
    [[ "$n" == $r ]]
}

# new_decided_load <share>  -> ND_KNOWN (its kopia_known), ND_PARTS (its folders that are an app's or VM's
# own part, or the backup place's folder) for new_decided
new_decided_load() {
    local s="$1" x t n f sh rel
    ND_KNOWN=(); ND_PARTS=(); ND_SHARE="$s"
    while IFS= read -r x; do [[ -n "$x" ]] && ND_KNOWN[$x]=1; done < <(cfg_list "share|$s|kopia_known")
    while IFS='|' read -r t n f; do
        [[ -n "$t" ]] || continue
        while IFS='|' read -r sh rel; do
            [[ "$sh" == "$s" && -n "$rel" && "$rel" != */* ]] && ND_PARTS[$rel]=1
        done < <(kopia_item_parts "$t" "$n")
    done < <(kopia_items)
    [[ -n "${DUMPS_SHARE:-}" && "$s" == "$DUMPS_SHARE" ]] && ND_PARTS[$(dumps_rel)]=1
    return 0
}

# new_decided <share> <folder>  -> 0 when the top-level folder is decided (new_decided_load <share> first):
# known (goes to Kopia), left out (the share's rules, the global ones), or another source's part
new_decided() {
    local k="/$2/"
    [[ -n "${ND_KNOWN[$k]:-}" || -n "${ND_PARTS[$2]:-}" ]] && return 0
    share_rules_hide "$1" "$2"
}

# share_rules_hide <share> <folder>  -> 0 when the share's own ignore rules or the global ones leave the
# top-level folder out as a whole
share_rules_hide() {
    local s="$1" n="$2" r b
    while IFS= read -r r; do
        [[ -n "$r" ]] || continue
        rule_hides_top "$r" "$n" && return 0
        if [[ "$(share_layout "$s")" == "split" ]]; then
            # one subfolder per base: a rule for the folder on every base it may lie on
            while IFS='|' read -r b _; do
                [[ -n "$b" && ( "$r" == "/$b/$n/" || "$r" == "/$b/$n" ) ]] && return 0
            done <<<"${INV_LOCS[$s]:-}"
        fi
    done < <(cfg_list "share|$s|kopia_ignore")
    for r in "${KOPIA_IGNORE[@]}"; do rule_hides_top "$r" "$n" && return 0; done
    return 1
}

# new_folder_bytes <share> <folder>  -> its size when that is cheap: a ZFS dataset of its own (the inventory
# knows its "referenced"); empty otherwise - nothing is measured, no disk is woken
new_folder_bytes() {
    local s="$1" n="$2" b ds mp sum=""
    while IFS='|' read -r b ds mp; do
        [[ -n "$ds" && "$mp" == "${INV_BASE_PATH[$b]:-/nonexistent}/$s/$n" ]] || continue
        is_uint "${ZDS_REF[$ds]:-}" && sum=$(( ${sum:-0} + ${ZDS_REF[$ds]} ))
    done <<<"${INV_CHILDREN[$s]:-}"
    printf '%s' "$sum"
}

# new_local_scan_live  -> NEW_LIST, NEW_RULES: the new folders of the shares going to Kopia as they are now
# (awake parts only) - for setup.sh: what it shows as waiting, and what the policies it writes leave out
new_local_scan_live() {
    local s n
    NEW_LIST=(); NEW_RULES=()
    while IFS= read -r s; do
        [[ -n "$s" ]] && share_watched "$s" && inv_has_share "$s" || continue
        share_top_live "$s"
        new_decided_load "$s"
        for n in "${SK_DIRS[@]}"; do
            new_decided "$s" "$n" && continue
            NEW_RULES[$s]+="$(new_rules_for "$s" "$n")"$'\n'
            NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"$(new_folder_bytes "$s" "$n")"$'\x1f'"${NEW_SEEN[$s|$n]:-}"$'\x1f' )
        done
    done < <(cfg_names share | LC_ALL=C sort)
    return 0
}

# new_local_state_load  -> NEW_SEEN, NEW_SEEN_RULES, NEW_SEEN_BYTES from state/new-local.json (trusted only in
# its own shape)
new_local_state_load() {
    local s f t b r
    NEW_SEEN=(); NEW_SEEN_RULES=(); NEW_SEEN_BYTES=()
    [[ -s "$UB_STATE/new-local.json" ]] || return 0
    while IFS=$'\x1f' read -r s f t b r; do
        [[ -n "$s" && -n "$f" ]] && share_name_ok "$s" || continue
        is_uint "$t" || t=0
        is_uint "$b" || b=""
        NEW_SEEN[$s|$f]="$t"; NEW_SEEN_BYTES[$s|$f]="$b"
        NEW_SEEN_RULES[$s|$f]="$(tr '\036' '\n' <<<"$r" | grep '^/' )"
    done < <(jq -r '.folders[]? | select((.share | type) == "string" and (.folder | type) == "string")
                    | [.share, .folder, (.first_seen // 0 | tostring), (.bytes // "" | tostring),
                       ((.rules // []) | map(select(type == "string")) | join("\u001e"))]
                    | select(all(.[]; test("[\u0000-\u001d\n]") | not)) | join("\u001f")' "$UB_STATE/new-local.json" 2>/dev/null)
    return 0
}

# new_local_drift  -> the drift notes new_waiting, as NEW_LIST says (the old ones go)
new_local_drift() {
    local i l s n b
    local -a d=() dc=()
    for i in "${!DRIFT[@]}"; do
        [[ "${DRIFT_CODE[$i]:-}" == new_waiting$'\x1f'* ]] && continue
        d+=( "${DRIFT[$i]}" ); dc+=( "${DRIFT_CODE[$i]:-$'\x1f'}" )
    done
    DRIFT=( "${d[@]}" ); DRIFT_CODE=( "${dc[@]}" )
    for l in "${NEW_LIST[@]}"; do
        IFS=$'\x1f' read -r s n b _ <<<"$l"
        drift_add info "New folder '$s/$n'${b:+ ($(human "$b"))} stays local - Kopia leaves it out until you decide in the setup" \
            new_waiting "$s/$n"
    done
    return 0
}

# drift_check_new_local  -> what the last run left out and is still undecided: NEW_LIST, NEW_RULES (so the
# policies compare as the run left them), drift info new_waiting; and the shares going to Kopia without a
# record of their folders (known_missing). Before drift_check_kopia, after plan_build.
drift_check_new_local() {
    local k s n missing=""
    local -a keys=()
    new_local_state_load
    NEW_LIST=(); NEW_RULES=(); ND_SHARE=""
    mapfile -t keys < <(printf '%s\n' "${!NEW_SEEN[@]}" | sed '/^$/d' | LC_ALL=C sort)
    for k in "${keys[@]}"; do
        s="${k%%|*}"; n="${k#*|}"
        share_watched "$s" || continue
        [[ "$ND_SHARE" == "$s" ]] || new_decided_load "$s"
        new_decided "$s" "$n" && continue
        NEW_RULES[$s]+="${NEW_SEEN_RULES[$k]:-}"$'\n'
        NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"${NEW_SEEN_BYTES[$k]:-}"$'\x1f'"${NEW_SEEN[$k]}"$'\x1f'"${NEW_SEEN_RULES[$k]//$'\n'/$'\x1e'}" )
    done
    new_local_drift
    for s in "${PLAN_KOPIA[@]}"; do
        [[ -n "${CFG[share|$s|kopia_known]+x}" ]] || missing+="${missing:+, }$s"
    done
    [[ -n "$missing" ]] && drift_add info "Every folder of these shares goes to Kopia, new ones too, until the setup is applied once and records their folders: $missing" \
        known_missing "$missing"
    return 0
}

# new_local_json  -> NEW_LIST as status.json's "new_local"
new_local_json() {
    printf '%s\n' "${NEW_LIST[@]}" | jq -R 'select(length > 0) | split("\u001f")
        | {share: .[0], folder: .[1], bytes: (if (.[2] // "") == "" then null else (.[2] | tonumber) end),
           first_seen: ((.[3] // "0") | if . == "" then 0 else tonumber end),
           rules: ((.[4] // "") | split("\u001e") | map(select(length > 0)))}' | jq -sc . 2>/dev/null || echo '[]'
}

new_local_state_write() {
    local tmp="$UB_STATE/.new-local.json.$$"
    if new_local_json | jq -c --argjson interface "$UB_INTERFACE" --arg version "$UB_VERSION" --arg run "${TS:-}" \
            --argjson time "$(date +%s)" '{interface: $interface, version: $version, run: $run, time: $time, folders: .}' \
            >"$tmp" 2>/dev/null && [[ -s "$tmp" ]]; then
        mv -f "$tmp" "$UB_STATE/new-local.json" 2>/dev/null
    fi
    rm -f "$tmp"
    return 0
}

# new_policy_align <share> <container path>  -> the share's policy leaves out its new folders (NEW_RULES), and no
# longer leaves out those a run left out before and nobody wants left out now (decided or gone). Only these
# rules - everything else in the policy is the setup's. 1 when Kopia refused.
new_policy_align() {
    local s="$1" cpath="$2" cur want r k
    local -a args=()
    local -A seen=()
    cur="$(jq -r --arg p "$cpath" --arg u "$KOPIA_USER" --arg h "$KOPIA_HOST" \
            'first(.[] | select(.target.path==$p and .target.userName==$u and .target.host==$h)) // {} | .files.ignore[]?' \
            <<<"$KP_JSON" 2>/dev/null)"
    want="$(kopia_want_ignores share "$s")"
    while IFS= read -r r; do
        [[ -n "$r" && -z "${seen[$r]:-}" ]] || continue
        seen[$r]=1
        grep -Fxq -- "$r" <<<"$cur" || args+=( --add-ignore "$r" )
    done <<<"${NEW_RULES[$s]:-}"
    while IFS= read -r k; do
        [[ -n "$k" && "${k%%|*}" == "$s" ]] || continue
        while IFS= read -r r; do
            [[ -n "$r" && -z "${seen[$r]:-}" ]] || continue
            seen[$r]=1
            grep -Fxq -- "$r" <<<"$cur" && ! grep -Fxq -- "$r" <<<"$want" && args+=( --remove-ignore "$r" )
        done <<<"${NEW_SEEN_RULES[$k]}"
    done < <(printf '%s\n' "${!NEW_SEEN_RULES[@]}" | LC_ALL=C sort)
    (( ${#args[@]} )) || return 0
    kopia_x policy set "$KOPIA_ID:$cpath" "${args[@]}" >>"${LOG_FILE:-/dev/null}" 2>&1 || return 1
    log "  Kopia policy of '$s': ${args[*]}"
    return 0
}

# new_local_run  -> right before the Kopia uploads: which top-level folders of the shares going there are new
# - looked up where Kopia reads them, in the mounted snapshots (<mount_root>/<share>, per base when split) -,
# left out of the share's policy (a share whose policy Kopia refuses is skipped: never uploaded unasked),
# noted in state/new-local.json, status.json and drift.json, and told once. backup.sh: Kopia phase, after
# drift_check_new_local; uses SHARE_MOUNTED and SKIP_KOPIA of the run.
new_local_run() {
    local s n b cpath bytes first rules now k l fresh="" nfresh=0
    local -A looked=() names=()
    local -a keep=( "${NEW_LIST[@]}" )
    now="$(date +%s)"
    NEW_LIST=()
    for s in "${PLAN_KOPIA[@]}"; do
        share_watched "$s" || continue
        [[ -z "${SKIP_KOPIA[$s]:-}" && -n "${SHARE_MOUNTED[$s]:-}" ]] || continue
        cpath="$(k_path "$(share_kopia_hostpath "$s")")" || continue
        looked[$s]=1
        new_decided_load "$s"
        names=()
        if [[ "$(share_layout "$s")" == "split" ]]; then
            while IFS='|' read -r b _; do
                [[ -n "$b" ]] || continue
                while IFS= read -r n; do [[ -n "$n" ]] && names[$n]=1; done < <(top_dirs "$MOUNT_ROOT/$s/$b")
            done <<<"${INV_LOCS[$s]:-}"
        else
            while IFS= read -r n; do [[ -n "$n" ]] && names[$n]=1; done < <(top_dirs "$MOUNT_ROOT/$s")
        fi
        NEW_RULES[$s]=""
        while IFS= read -r n; do
            [[ -n "$n" ]] || continue
            new_decided "$s" "$n" && continue
            k="$s|$n"
            bytes="$(new_folder_bytes "$s" "$n")"
            first="${NEW_SEEN[$k]:-}"
            if [[ -z "$first" ]]; then
                first="$now"; nfresh=$((nfresh+1))
                fresh+="${fresh:+, }$s/$n${bytes:+ ($(human "$bytes"))}"
            fi
            rules="$(new_rules_for "$s" "$n")"
            NEW_RULES[$s]+="$rules"$'\n'
            NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"$bytes"$'\x1f'"$first"$'\x1f'"${rules//$'\n'/$'\x1e'}" )
            log "  New folder '$s/$n'${bytes:+ ($(human "$bytes"))} stays local: Kopia leaves it out until you decide (Set up...)"
        done < <(printf '%s\n' "${!names[@]}" | sed '/^$/d' | LC_ALL=C sort)
        new_policy_align "$s" "$cpath" || SKIP_KOPIA[$s]="its new folders could not be left out of its policy"
    done
    # what this run didn't look at (a share skipped or not mounted) stays as it was, while undecided
    for l in "${keep[@]}"; do
        s="${l%%$'\x1f'*}"
        [[ -n "${looked[$s]:-}" ]] || NEW_LIST+=( "$l" )
    done
    new_local_state_write
    ST_NEW_LOCAL="$(new_local_json | jq -c 'map(del(.rules))' 2>/dev/null)" || ST_NEW_LOCAL="[]"
    [[ -n "$ST_NEW_LOCAL" ]] || ST_NEW_LOCAL="[]"
    new_local_drift
    drift_text >"$UB_STATE/drift.txt" 2>/dev/null
    drift_json_write
    status_write
    if (( nfresh > 0 )); then
        if (( nfresh == 1 )); then
            ub_notify "New folder stays local" "$fresh - new in a share that goes to Kopia: only in the local snapshots until you decide in Mr. Backupsy's setup." "normal"
        else
            ub_notify "$nfresh new folders stay local" "$fresh - new in shares that go to Kopia: only in the local snapshots until you decide in Mr. Backupsy's setup." "normal"
        fi
        # Unraid's notify keeps one notification per event and second: the run's own report comes later
        [[ "${UB_NO_NOTIFY:-0}" == "1" ]] || sleep 1
    fi
    return 0
}

##############################################################################
# 12. Partners (since 2.27)
##############################################################################
# Two offices can be partners - never master and slave, never an automatic failover. The Team Lead pairs them
# (data/partner/pairs.json, a key pair per pair on the flash, the partner's door: ssh with a forced command that
# knows a handful of verbs). Each night, right after the snapshots and the apps' restart and before Kopia's long
# upload, the phase "partner" (backup.sh) sends the run's ZFS snapshots of the units the setup ticked to each
# partner - incremental `zfs send` over that door, always encrypted (ssh), the partner keeps the copy with its own
# retention and quota; the sender can never delete anything there. ZFS only: no rsync, a unit that isn't one
# dataset of its own is "not covered", said so.
#   [partner "<id>"]  name, address, port, rate_mbit (Mbit/s, 0 = unlimited) - the Team Lead's agreement; the setup
#                     writes it from pairs.json. Never a secret: the private key and the partner's known_hosts are
#                     files derived from the id, <UB_PARTNER_DIR>/<id>.key and <id>.known (the flash folder)
#   [share "<s>"]   partner = <id>  (repeatable)   unit share:<s>
#   [vm "<v>"]      partner = <id>  (repeatable)   unit vm:<v>
#   [general]       partner_place = <id> (repeatable)  unit place - the backup place's dataset
# The door's verbs the engine uses: ping, list <unit>, resume <unit>, recv <unit> <snap> [<from>|-t].
# Whatever crosses as a word is checked here first: units by partner_unit_name_ok, snapshots by UB_PARTNER_SNAP_RE.
UB_PARTNER_DIR="${UB_PARTNER_DIR:-$UB_BOOT/config/plugins/unraid-secretary-office/partners}"
UB_PARTNER_PAIRS="${UB_PARTNER_PAIRS:-$(dirname "$UB_DATA")/partner/pairs.json}"   # the Team Lead's pairs (setup.sh reads it)
UB_PARTNER_ASK="${UB_PARTNER_ASK:-60}"           # seconds for a short question to the door (ping, list, resume)
UB_PARTNER_NIGHTS="${UB_PARTNER_NIGHTS:-3}"      # unreachable so many nights in a row: a warning (once a day)
UB_PARTNER_SNAP_RE='^uso-backup-[0-9]{8}-[0-9]{4}$'   # the only snapshot names the door accepts
UB_PARTNER_BOOKMARK="uso-partner-"               # <dataset>#uso-partner-<id>: the last snapshot sent to that partner

partner_id_ok()        { [[ "$1" =~ ^[0-9a-f]{8}$ ]]; }
partner_unit_name_ok() { [[ "$1" =~ ^[A-Za-z0-9._-]{1,64}$ && "$1" != .* ]]; }   # one word through the door, a dataset name there

# partner_ids  -> the partners settings.ini names ([partner "<id>"] with a valid id), in its order
partner_ids() { local id; while IFS= read -r id; do partner_id_ok "$id" && printf '%s\n' "$id"; done < <(cfg_names partner); return 0; }
partner_name() { cfg "partner|$1|name" "$1"; }

# partner_units <id>  -> the units ticked for that partner: place first, then the shares and the VMs in settings.ini's order
partner_units() {
    local id="$1" n
    cfg_list "general|partner_place" | grep -Fxq -- "$id" && echo place
    while IFS= read -r n; do
        [[ -n "$n" ]] || continue
        if cfg_list "share|$n|partner" | grep -Fxq -- "$id"; then echo "share:$n"; fi
    done < <(cfg_names share)
    while IFS= read -r n; do
        [[ -n "$n" ]] || continue
        if cfg_list "vm|$n|partner" | grep -Fxq -- "$id"; then echo "vm:$n"; fi
    done < <(cfg_names vm)
    return 0
}

# partner_vm_ds <dataset>  -> 0 when it is (or lies below) a VM's own dataset
partner_vm_ds() {
    local n d
    for n in "${VM_NAMES[@]}"; do
        while IFS= read -r d; do [[ -n "$d" && ( "$1" == "$d" || "$1" == "$d/"* ) ]] && return 0; done <<<"${VM_OWN_DS[$n]:-}"
    done
    return 1
}

# partner_unit_dataset <unit>  -> 0 and PU_DS = the dataset that travels, or 1 and PU_WHY:
#   name         the name can't travel as one word through the door ([A-Za-z0-9._-], at most 64)
#   no_dataset   not on ZFS (XFS/btrfs, an array disk), gone, locked - or a VM sharing its dataset
#   not_dataset  a folder in a dataset (e.g. in a pool's root dataset), not a dataset of its own
#   split        on several pools/disks; a VM with disks in several datasets
#   children     a share with child datasets other than its VMs' own (zfs send without -R would leave them out)
# Inventory (inv_scan), VMs (vm_load) and the backup place (DUMPS_SHARE) must be loaded.
partner_unit_dataset() {
    local u="$1" s="" b m layer sub ds mp own n
    local -a locs=() excl=()
    PU_DS=""; PU_WHY=""
    case "$u" in
        place)   s="${DUMPS_SHARE:-}"; [[ -n "$s" ]] || { PU_WHY="no_dataset"; return 1; } ;;
        share:*) s="${u#share:}"; partner_unit_name_ok "$s" || { PU_WHY="name"; return 1; } ;;
        vm:*)
            s="${u#vm:}"
            partner_unit_name_ok "$s" || { PU_WHY="name"; return 1; }
            own="$(sed '/^$/d' <<<"${VM_OWN_DS[$s]:-}")"
            n=0; [[ -n "$own" ]] && n="$(wc -l <<<"$own")"
            (( n == 0 )) && { PU_WHY="no_dataset"; return 1; }
            (( n > 1 ))  && { PU_WHY="split"; return 1; }
            PU_DS="$own"; return 0 ;;
        *)       PU_WHY="name"; return 1 ;;
    esac
    inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] || { PU_WHY="no_dataset"; return 1; }
    mapfile -t locs < <(sed '/^$/d' <<<"${INV_LOCS[$s]}")
    (( ${#locs[@]} > 1 )) && { PU_WHY="split"; return 1; }
    IFS='|' read -r b m layer sub <<<"${locs[0]:-}"
    [[ "$m" == "zfs" && -n "$layer" ]] || { PU_WHY="no_dataset"; return 1; }
    [[ -z "$sub" ]] || { PU_WHY="not_dataset"; return 1; }
    mapfile -t excl < <(cfg_list "share|$s|exclude_dataset")
    while IFS='|' read -r b ds mp; do
        [[ -z "$ds" ]] && continue
        [[ "${ds##*/}" == _UnraidSecretaryOffice-trash* ]] && continue          # Ms. Dustdevil's storeroom: never backed up
        partner_vm_ds "$ds" && continue                                          # a VM's own dataset is a unit of its own
        in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}" && continue
        PU_WHY="children"; return 1
    done <<<"${INV_CHILDREN[$s]:-}"
    PU_DS="$layer"
    return 0
}

# partner_ssh_cmd <id>  -> PSSH: the ssh call to that partner's door - the one place for its options (plan 3.5):
# the pair's own key only, never a password or agent, the pinned host keys only, a fast AEAD cipher, no compression
# (zfs send -c sends compressed blocks), a dead link noticed within two minutes
partner_ssh_cmd() {
    PSSH=( ssh -i "$UB_PARTNER_DIR/$1.key" -o IdentitiesOnly=yes -o BatchMode=yes
           -o "UserKnownHostsFile=$UB_PARTNER_DIR/$1.known" -o StrictHostKeyChecking=yes
           -o Ciphers=aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com -o Compression=no
           -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=4
           -p "$(cfg "partner|$1|port" 22)" "root@$(cfg "partner|$1|address")" )
}
# partner_ssh <id> <verb> [words...]  - the door's verb and its words as one command line (each word checked before)
partner_ssh() { local id="$1"; shift; partner_ssh_cmd "$id"; "${PSSH[@]}" "$*"; }

# partner_ask <id> <verb> [words...]  - a short question (ping, list, resume): PA_JSON = the door's answer (its first JSON
# line on stdout, "" without one); returns 0 answered, 255 unreachable (ssh: no connection, the host key, the key refused -
# or no answer within UB_PARTNER_ASK s), else ssh's exit code. ssh's own words go to the log.
partner_ask() {
    local id="$1" out rc e
    shift
    PA_JSON=""
    partner_ssh_cmd "$id"
    e="$(mktemp "${TMPDIR:-/tmp}/uso-partner-ask.XXXXXX")" || return 1
    out="$(timeout "$UB_PARTNER_ASK" "${PSSH[@]}" "$*" </dev/null 2>"$e")"; rc=$?
    (( rc == 124 )) && { printf 'no answer within %s s\n' "$UB_PARTNER_ASK" >>"$e"; rc=255; }
    if (( rc != 0 )) && [[ -s "$e" ]]; then log "    ($(partner_name "$id") $1: $(head -c 300 "$e" | tr '\n' ' ' | tr -d '\r'))"; fi
    rm -f "$e"
    # one JSON line (the door's contract) - else, should it print its answer over several lines, the whole of it
    PA_JSON="$(printf '%s\n' "$out" | jq -R -c 'fromjson? | select(type == "object")' 2>/dev/null | head -n 1)"
    [[ -n "$PA_JSON" ]] || PA_JSON="$(printf '%s\n' "$out" | jq -c 'select(type == "object")' 2>/dev/null | head -n 1)"
    return "$rc"
}

# partner_why_ok <code>  -> the code when it looks like one of the door's (lower case, _), else "refused"
partner_why_ok() { if [[ "$1" =~ ^[a-z_]{1,40}$ ]]; then printf '%s' "$1"; else printf 'refused'; fi; }

# The Team Lead's pairs (contract: plan 3.2, data/partner/pairs.json, {"v": 1, "pairs": [...]}) - read by setup.sh to
# propose the [partner] sections; trusted only in exactly that shape. A pair the office sends to has "my_key".
# partner_pairs_load -> 0 and PAIRS (lines "id|name|address|port|rate_mbit"), 1 when the file is missing or not as expected
partner_pairs_load() {
    PAIRS=()
    local f="$UB_PARTNER_PAIRS"
    [[ -f "$f" && ! -L "$f" && -r "$f" ]] || return 1
    jq -e '.v == 1 and (.pairs | type) == "array"' "$f" >/dev/null 2>&1 || return 1
    mapfile -t PAIRS < <(jq -r '.pairs[]
        | select(type == "object" and (.id | type) == "string" and (.id | test("^[0-9a-f]{8}$"))
                 and (.name | type) == "string" and (.name | test("^[A-Za-z0-9._-]{1,40}$"))
                 and (.address | type) == "string" and (.address | test("^[A-Za-z0-9][A-Za-z0-9._:-]{0,252}$"))
                 and (.port | type) == "number" and .port >= 1 and .port <= 65535 and .port == (.port | floor)
                 and (.my_key | type) == "string")
        | [.id, .name, .address, (.port | tostring),
           ((.send.rate_mbit // 0) | if type == "number" and . >= 0 then floor else 0 end | tostring)] | join("|")' "$f" 2>/dev/null)
    return 0
}

# --- What the phase did, for status.json (section 7) ---------------------------
ST_PARTNER_IDS=()         # "id|name" of the partners this run sends to (empty: "partner": null)
ST_PARTNER_PLAN=()        # "id|unit" in the phase's order
ST_PARTNER_CUR=""         # "id|unit" being sent
ST_PARTNER_CUR_T=0
ST_PARTNER_CUR_BYTES=0    # what left so far (pv's count, every 10 s)
ST_PARTNER_DONE=()        # "id|unit|snap|from|bytes|seconds|resumed(1/0)"
ST_PARTNER_SKIPPED=()     # "id|unit|why"
ST_PARTNER_FAILED=()      # "id|unit|why"
ST_PARTNER_INTERRUPTED="" # "id|unit": the transfer an array stop (or a stop by hand) ended - resumed by the next run

partner_status_json() {
    (( ${#ST_PARTNER_IDS[@]} )) || { echo null; return 0; }
    local ids plan done_ sk fl
    ids="$(printf '%s\n' "${ST_PARTNER_IDS[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], name: .[1]}' | jq -sc .)" || ids='[]'
    plan="$(printf '%s\n' "${ST_PARTNER_PLAN[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1]}' | jq -sc .)" || plan='[]'
    done_="$(printf '%s\n' "${ST_PARTNER_DONE[@]}" | jq -R 'select(length > 0) | split("|")
        | {id: .[0], unit: .[1], snap: .[2], from: (if .[3] == "" then null else .[3] end), bytes: (.[4] | tonumber),
           seconds: (.[5] | tonumber), resumed: (.[6] == "1")}
        | .mbit = (if .seconds > 0 then ((.bytes * 8 / .seconds / 1000000 * 10 | round) / 10) else null end)' | jq -sc .)" || done_='[]'
    sk="$(printf '%s\n' "${ST_PARTNER_SKIPPED[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1], why: .[2]}' | jq -sc .)" || sk='[]'
    fl="$(printf '%s\n' "${ST_PARTNER_FAILED[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1], why: .[2]}' | jq -sc .)" || fl='[]'
    jq -nc --argjson partners "${ids:-[]}" --argjson planned "${plan:-[]}" --argjson done "${done_:-[]}" \
        --argjson skipped "${sk:-[]}" --argjson failed "${fl:-[]}" --arg cur "$ST_PARTNER_CUR" --argjson since "${ST_PARTNER_CUR_T:-0}" \
        --argjson bytes "${ST_PARTNER_CUR_BYTES:-0}" --arg intr "$ST_PARTNER_INTERRUPTED" \
        '{partners: $partners, planned: $planned,
          current: (if $cur == "" then null else ($cur | split("|") | {id: .[0], unit: .[1], since: $since, bytes: $bytes}) end),
          done: $done, skipped: $skipped, failed: $failed,
          interrupted: (if $intr == "" then null else ($intr | split("|") | {id: .[0], unit: .[1]}) end)}'
}

# --- What the phase keeps between runs (state/, root only) ---------------------
# partner-sent.json  {"<id>": {"<unit>": {"snap", "dataset", "time"}}} - the last snapshot each partner got of each unit:
#                    with the bookmark <dataset>#uso-partner-<id> it is the base of the next incremental send even when
#                    the engine's retention destroyed that snapshot here
# partner-skips.json {"<id>": {"nights", "first", "last_day", "warned", "quota_warned", "window_warned"}} - nights in a row
#                    unreachable, and the day each warning was last said (once a day)
partner_state_get() { # partner_state_get <file> <jq path, e.g. .["id"]["unit"].snap>
    jq -r "($2) // empty" "$UB_STATE/$1" 2>/dev/null
}
partner_state_set() { # partner_state_set <file> <jq filter with $v> <value as JSON>
    local f="$UB_STATE/$1" tmp="$UB_STATE/.$1.$$"
    { if [[ -s "$f" ]] && jq -e 'type == "object"' "$f" >/dev/null 2>&1; then cat "$f"; else echo '{}'; fi; } \
        | jq -c --argjson v "$3" "$2" >"$tmp" 2>/dev/null && [[ -s "$tmp" ]] && mv -f "$tmp" "$f"
    rm -f "$tmp"
    return 0
}

##############################################################################
# 13. Sleeping pools (since 2.28)
##############################################################################
# [general] asleep_pools: wake (the default, as before) = a pool whose disks sleep is woken by its snapshot;
# skip = backup.sh leaves it out of that night's run - the office's own desks never wake a sleeping pool, the
# engine offers the same choice. Unraid notes in disks.ini which disks are spun down (spundown="1"); a pool
# sleeps when ANY of its disks does (hive, hive2 ...), an array disk is just itself. disks.ini is read once
# (ub_asleep_load) - never a disk touched to find out: no zfs list or zfs get on such a pool, no look into it.
# backup.sh decides it once, when the run makes its plan (asleep_plan) - before anything is stopped, so VMs
# and apps whose data sleeps are neither prepared nor stopped for nothing:
#   ASLEEP_BASE[base]  a ZFS pool of the snapshot plan or a btrfs disk/pool whose snapshot is planned, asleep:
#                      no snapshot there, no retention, no mount
#   ASLEEP_DS[ds]      its datasets, taken out of PLAN_ZFS (a partner's unit there: skipped, why asleep)
#   ASLEEP_SHARE[s]    a backed-up share with a part there (or read live from a disk that sleeps): its Kopia
#                      source is skipped (why asleep), so are the apps' and VMs' own sources with a part in it
#   NOT_LOOKED[base]   every pool or disk asleep at plan time the run doesn't wake: the retention looks neither
#                      at its ZFS snapshots nor at its btrfs snapshot folder
# Never left out: the pool of the backup place (the packages are the point - woken as before, said in the
# log) and the pool of the engine's data folder (the run's own log is written there).
# state/asleep.json {"shares": {"<share>": {"nights", "first", "last_day", "warned"}}}: the nights in a row a
# share was left out (two runs on one day count once); the UB_ASLEEP_NIGHTS-th (7) one warns, once - a pool
# that never wakes at night would otherwise never be backed up and nobody would know. A night the share is
# snapshotted (also every night with asleep_pools = wake) takes it out of the file.
UB_ASLEEP_NIGHTS="${UB_ASLEEP_NIGHTS:-7}"
[[ "$UB_ASLEEP_NIGHTS" =~ ^[1-9][0-9]{0,3}$ ]] || UB_ASLEEP_NIGHTS=7
declare -gA UB_SPUNDOWN=()
UB_ASLEEP_LOADED="no"
ub_asleep_load() { # the disks disks.ini calls spun down -> UB_SPUNDOWN[<disk>]=1 (one read)
    local n
    UB_SPUNDOWN=(); UB_ASLEEP_LOADED="yes"
    [[ -r "$UB_DISKS_INI" ]] || return 0
    while IFS= read -r n; do [[ -n "$n" ]] && UB_SPUNDOWN[$n]=1; done < <(awk '
        /^\[/ { name = $0; gsub(/[\[\]"]/, "", name); next }
        /^spundown=/ { v = $0; sub(/^spundown="?/, "", v); sub(/"$/, "", v); if (v == "1" && name != "") print name }' "$UB_DISKS_INI")
    return 0
}
ub_base_sleeps() { # ub_base_sleeps <base>  -> 0 when it sleeps (like ub_base_asleep, from the one read of ub_asleep_load)
    local b="$1" n
    [[ -n "$b" ]] || return 1
    [[ "$UB_ASLEEP_LOADED" == "yes" ]] || ub_asleep_load
    if [[ "$b" =~ ^disk[0-9]+$ ]]; then [[ -n "${UB_SPUNDOWN[$b]:-}" ]]; return; fi
    for n in "${!UB_SPUNDOWN[@]}"; do
        [[ "$n" == "$b" ]] && return 0
        [[ "$n" == "$b"* && "${n#"$b"}" =~ ^[0-9]+$ ]] && return 0
    done
    return 1
}
# share_bases <share>  -> the pools and disks holding it - its parts and its child datasets -, one per line
share_bases() {
    local b
    { while IFS='|' read -r b _; do [[ -n "$b" ]] && printf '%s\n' "$b"; done <<<"${INV_LOCS[$1]:-}"
      while IFS='|' read -r b _; do [[ -n "$b" ]] && printf '%s\n' "$b"; done <<<"${INV_CHILDREN[$1]:-}"; } | awk '!seen[$0]++'
}
# ub_path_bases <path>  -> the pools and disks that may hold it: a /mnt/user path those of its share, a pool's or
# disk's path that one (nothing for a path outside them)
ub_path_bases() {
    local p="${1%/}" rel b
    if [[ "$p" == "$UB_MNT/user/"* || "$p" == "$UB_MNT/user0/"* ]]; then
        rel="${p#"$UB_MNT"/user/}"; rel="${rel#"$UB_MNT"/user0/}"
        share_bases "${rel%%/*}"; return 0
    fi
    for b in "${INV_BASES[@]}"; do
        [[ "$p" == "${INV_BASE_PATH[$b]}" || "$p" == "${INV_BASE_PATH[$b]}/"* ]] && { printf '%s\n' "$b"; return 0; }
    done
    return 0
}
# share_asleep_now <share> / vm_asleep_now <vm>  -> the pools and disks of the share (of the VM's disks) that sleep
# right now, one per line (setup.sh's plan: «pool asleep now» on the office's rows)
share_asleep_now() { local b; while IFS= read -r b; do [[ -n "$b" ]] && ub_base_sleeps "$b" && printf '%s\n' "$b"; done < <(share_bases "$1"); return 0; }
vm_asleep_now() {
    local t b
    while IFS='|' read -r t _ b _; do [[ -n "$t" && -n "$b" ]] && ub_base_sleeps "$b" && printf '%s\n' "$b"; done <<<"${VM_DISKS[$1]:-}" | awk '!seen[$0]++'
    return 0
}

# What the run left out, for status.json "asleep" (null unless asleep_pools = skip): pools (and btrfs disks) left
# out, shares with a part there, units = how many shares, vms not prepared and not snapshotted, containers kept
# running (all their backed-up data asleep), sources = the Kopia sources skipped, woken = pools asleep but
# woken all the same (the backup place, the data folder), nights = per share left out the nights in a row so far
ST_ASLEEP_ON="no"
ST_ASLEEP_POOLS=(); ST_ASLEEP_SHARES=(); ST_ASLEEP_VMS=(); ST_ASLEEP_CTS=(); ST_ASLEEP_SRC=(); ST_ASLEEP_WOKEN=()
declare -gA ST_ASLEEP_NIGHTS=()
declare -gA ST_KOPIA_SKIPPED_WHY=()   # name -> why, for kopia.skipped_why: a source skipped for another reason than the array stop
asleep_status_json() {
    [[ "$ST_ASLEEP_ON" == "yes" ]] || { echo null; return 0; }
    local k nights
    nights="$(for k in "${!ST_ASLEEP_NIGHTS[@]}"; do printf '%s\x1f%s\n' "$k" "${ST_ASLEEP_NIGHTS[$k]}"; done \
        | jq -R 'select(length > 0) | split("\u001f") | {key: .[0], value: (.[1] | tonumber? // 0)}' | jq -sc 'from_entries')" || nights='{}'
    [[ -n "$nights" ]] || nights='{}'
    _al() { printf '%s\n' "$@" | jq -R 'select(length > 0)' | jq -sc .; }
    jq -nc --argjson pools "$(_al "${ST_ASLEEP_POOLS[@]}")" --argjson shares "$(_al "${ST_ASLEEP_SHARES[@]}")" \
        --argjson vms "$(_al "${ST_ASLEEP_VMS[@]}")" --argjson cts "$(_al "${ST_ASLEEP_CTS[@]}")" \
        --argjson src "$(_al "${ST_ASLEEP_SRC[@]}")" --argjson woken "$(_al "${ST_ASLEEP_WOKEN[@]}")" --argjson nights "$nights" \
        '{mode: "skip", pools: $pools, shares: $shares, units: ($shares | length), vms: $vms, containers: $cts,
          sources: $src, woken: $woken, nights: $nights}'
    unset -f _al
}
kopia_skipped_why_json() {
    local k
    for k in "${!ST_KOPIA_SKIPPED_WHY[@]}"; do printf '%s\x1f%s\n' "$k" "${ST_KOPIA_SKIPPED_WHY[$k]}"; done \
        | jq -R 'select(length > 0) | split("\u001f") | {key: .[0], value: .[1]}' | jq -sc 'from_entries'
}

# asleep_nights <share...>  - a real run left these shares out; every other share was snapshotted (their count goes).
# The UB_ASLEEP_NIGHTS-th night in a row warns once per stretch; ST_ASLEEP_NIGHTS gets the counts
asleep_nights() {
    local f="$UB_STATE/asleep.json" tmp="$UB_STATE/.asleep.json.$$" cur new s n first
    cur='{"shares":{}}'
    if [[ -s "$f" ]]; then
        cur="$(jq -c 'if type == "object" and (.shares | type) == "object" then {shares: .shares} else {shares: {}} end' "$f" 2>/dev/null)" || cur=""
        [[ -n "$cur" ]] || cur='{"shares":{}}'
    fi
    new="$(jq -c --arg today "$(date +%F)" --argjson now "$(date +%s)" --args '
        .shares as $old
        | {shares: ([$ARGS.positional[] | select(length > 0) | . as $s | ($old[$s] // {}) as $o
            | (($o.nights // 0) | if type == "number" and . >= 0 then floor else 0 end) as $n
            | {key: $s, value: {nights: (if $o.last_day == $today and $n > 0 then $n else $n + 1 end),
                                first: (($o.first // $now) | if type == "number" then . else $now end), last_day: $today,
                                warned: (($o.warned // "") | if type == "string" then . else "" end)}}] | from_entries)}' \
        "$@" <<<"$cur" 2>/dev/null)" || new=""
    [[ -n "$new" ]] || return 0
    ST_ASLEEP_NIGHTS=()
    while IFS=$'\t' read -r s n first; do
        [[ -n "$s" ]] || continue
        ST_ASLEEP_NIGHTS[$s]="$n"
    done < <(jq -r '.shares | to_entries[] | [.key, (.value.nights | tostring), (.value.first | tostring)] | @tsv' <<<"$new" 2>/dev/null)
    while IFS=$'\t' read -r s n first; do
        [[ -n "$s" ]] || continue
        warn "Share '$s' was left out $n nights in a row (since $(date -d "@$first" '+%Y-%m-%d' 2>/dev/null)) - its pool or disk sleeps every night at the run's time (asleep_pools = skip), so it is not backed up; wake it now and then, or choose «wake them» in the setup (asleep_long)"
        new="$(jq -c --arg s "$s" --arg today "$(date +%F)" '.shares[$s].warned = $today' <<<"$new" 2>/dev/null)" || break
    done < <(jq -r --argjson lim "$UB_ASLEEP_NIGHTS" '.shares | to_entries[] | select(.value.nights >= $lim and .value.warned == "")
        | [.key, (.value.nights | tostring), (.value.first | tostring)] | @tsv' <<<"$new" 2>/dev/null)
    if [[ "$new" == '{"shares":{}}' ]]; then rm -f "$f"
    elif printf '%s\n' "$new" >"$tmp" 2>/dev/null; then mv -f "$tmp" "$f"; fi
    rm -f "$tmp"
    return 0
}
