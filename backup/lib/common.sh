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
#  11. New things      what is new stays local and keeps running until the user decided (since 2.21); a new folder
#                      inherits its share's level, in the app/VM shares its app's or VM's (since 2.37) - also an
#                      existing app's or VM's set only local: it stays local with it, silently (since 2.38)
#  12. Partners        units sent to partner offices by zfs send through their door (since 2.27)
#  13. Sleeping pools  [general] asleep_pools = skip: pools and disks that sleep are left out of a run (since 2.28)
###############################################################################

# shellcheck disable=SC2034   # many variables are only used in the scripts

UB_VERSION="2.77"
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
# Ms. Dustdevil's storeroom: a hidden folder since 2.36 (.UnraidSecretaryOffice-trash); up to 2.35 the office named it
# _UnraidSecretaryOffice-trash - both are hers for good (folders, and datasets put away as <storeroom>-<stamp>-<name>)
UB_STOREROOM=".$UB_OFFICE_SHARE-trash"
UB_STOREROOM_OLD="_$UB_OFFICE_SHARE-trash"
ub_storeroom() { [[ "$1" == "$UB_STOREROOM"* || "$1" == "$UB_STOREROOM_OLD"* ]]; }
# A partner office's copies on this server: <pool>/UnraidSecretaryOffice-partners/<pair id>/<unit> (agent/lib/partner.php
# PARTNER_PARENT) - the door's retention (the pair's «d w m») keeps them, never this engine's
UB_PARTNER_PARENT="$UB_OFFICE_SHARE-partners"
# ub_prune_own <dataset>  -> 0 when the engine's ZFS retention may thin that dataset's snapshots (2.48): never a partner's
# copy here (its snapshots carry the engine's names too, uso-backup-*) and never anything in Ms. Dustdevil's storeroom
# (a dataset put away keeps the snapshots it had - she gives it back as it was)
ub_prune_own() {
    local ds="$1" c
    local -a parts=()
    [[ "${ds#*/}" == "$UB_PARTNER_PARENT" || "${ds#*/}" == "$UB_PARTNER_PARENT"/* ]] && return 1
    IFS='/' read -r -a parts <<<"$ds"
    for c in "${parts[@]:1}"; do ub_storeroom "$c" && return 1; done
    return 0
}
# A unit's copy is whole (2.43): a VM's or an app's package is also written inside its own dataset, in this hidden folder
# at the dataset's top - so every snapshot of it, and a partner office's copy of it, carries the configuration (the
# receiver's Mr. Restori starts from it). Small files, overwritten by every run; never a new folder to the drift check
UB_INSIDE_DIR=".uso-backup"

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
log_file() { [[ -n "${LOG_FILE:-}" ]] && printf '%s  %s\n' "$(_ts)" "$*" >>"$LOG_FILE"; return 0; }   # the log only (2.67)
warn() { WARNINGS=$((WARNINGS+1)); _log_line "$(_ts)  WARNING: $*"; }
# warn_code <code> <text> [key=value]...  (2.42) - a warning the office says in its own words: counted and logged like
#   warn (the English text), and noted in WARN_ITEMS ("code<US>key=value<US>...") - status.json "warning_items":
#   [{code, params: {key: value}}]. So far the VMs held otherwise than set up (backup.sh vm_hold_begin, vm_hold)
declare -ga WARN_ITEMS=()
warn_code() {
    local c="$1" t="$2" p="" kv
    shift 2
    for kv in "$@"; do p+=$'\x1f'"$kv"; done
    WARN_ITEMS+=( "$c$p" ); warn "$t"
}
err()  { ERRORS=$((ERRORS+1));     _log_line "$(_ts)  ERROR: $*"; }
# err_code <code> <text> [key=value]...  (2.56) - an error the office says in its own words: counted and logged like err,
#   noted in WARN_ITEMS like warn_code (status.json "warning_items" carries the run's coded words, warnings and errors) -
#   so far Kopia's step held because the repository is suddenly empty (kopia_held)
err_code() {
    local c="$1" t="$2" p="" kv
    shift 2
    for kv in "$@"; do p+=$'\x1f'"$kv"; done
    WARN_ITEMS+=( "$c$p" ); err "$t"
}

# ub_notify <subject> <short text> [normal|warning|alert] [long text]
# Create the data folder and lock it (0700: the office reads it through its
# agent as root)
ub_data_dirs() {
    mkdir -p "$UB_STATE" "$UB_LOGS" || return 1      # dumps: in their own backup share (dumps_share), never here
    chmod 700 "$UB_DATA" 2>/dev/null
    return 0
}

# 2.49 (review 2026-10-15): the engine runs as root and takes what it does from UB_DATA (settings.ini, the notes of an
# interrupted run, its logs): only from a real folder of root's that nobody else can write, settings.ini a plain file of
# root's likewise. A folder someone else made or can write (the office's data folder replaced by a writer as nobody, an
# appdata «New Permissions») is refused before anything is read or written there - its logs could be links.
# ub_data_trusted -> 0, or 1 with UB_UNTRUSTED (data|settings) and UB_UNTRUSTED_PATH; ub_untrusted_refuse says so
# (stderr, syslog, an alert - never into that folder) and ends with exit 4
ub_root_only() { # <path> - root's, not writable by group or others (the path itself, never what a link points at)
    local s
    s="$(stat -c '%u %a' -- "$1" 2>/dev/null)" || return 1
    [[ "${s%% *}" == "0" ]] && (( (8#${s##* } & 8#022) == 0 ))
}
ub_data_trusted() {
    UB_UNTRUSTED=""; UB_UNTRUSTED_PATH=""
    if [[ -L "$UB_DATA" ]] || { [[ -e "$UB_DATA" ]] && { [[ ! -d "$UB_DATA" ]] || ! ub_root_only "$UB_DATA"; }; }; then
        UB_UNTRUSTED="data"; UB_UNTRUSTED_PATH="$UB_DATA"; return 1
    fi
    if [[ -L "$UB_SETTINGS" ]] || { [[ -e "$UB_SETTINGS" ]] && { [[ ! -f "$UB_SETTINGS" ]] || ! ub_root_only "$UB_SETTINGS"; }; }; then
        UB_UNTRUSTED="settings"; UB_UNTRUSTED_PATH="$UB_SETTINGS"; return 1
    fi
    return 0
}
ub_untrusted_refuse() {
    local t="$UB_UNTRUSTED_PATH is not root's own (owner $(stat -c '%U:%G %a' -- "$UB_UNTRUSTED_PATH" 2>/dev/null || echo '?')) - the engine reads nothing from a folder others can write or replace. Check it, then: chown root:root and chmod go-w (the office's Team Lead shows the command)"
    echo "REFUSED: $t" >&2
    logger -t "$UB_NAME" "refused: $t" 2>/dev/null
    ub_tell refused alert "$t" "Backup not started — its folder isn't safe" "Others than the server's administrator could change the backup's folder or its settings, so no backup runs until that is fixed. The Team Lead shows how in the office ($UB_UNTRUSTED_PATH)." "path=$UB_UNTRUSTED_PATH"
    exit 4
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
# 2.66: the office's RAM folder - the Team Lead's settings (notify.cfg), the flood guard and his queue (lib/notifygate.sh)
UB_NOTIFY_HOME="${UB_NOTIFY_HOME-$(ub_is_plugin && echo /var/run/unraid-secretary-office)}"
# shellcheck source=lib/notifygate.sh
source "$UB_DIR/lib/notifygate.sh" 2>/dev/null || true
ub_centre_decides() { # the Team Lead's settings are there (notify.cfg): they decide what goes, not notify_success
    [[ -n "${UB_NOTIFY_HOME:-}" ]] && declare -F ung_cfg_read >/dev/null && ung_cfg_read "$UB_NOTIFY_HOME/notify.cfg"
}
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
    # Unraid puts the server's name in front of the subject itself; the description is one line, the long text's lines
    # are Unraid's literal \n (its mail, the bell and the push agents split there; a blank line between sections stays)
    local d="${2//$'\n'/ }" m="${4:-}" ml=() level="${3:-normal}" plain
    local args=( -e "Unraid Secretary Office" -s "Unraid Secretary Office: $1" -d "${d//$'\r'/}" -i "$level" ) st="$UB_NOTIFY_STAMP" fd="" last
    local home="${UB_NOTIFY_HOME:-}" ev="${UB_NOTIFY_EVENT:-}" key="" rc=0 notice=()
    m="${m//$'\r'/}"; m="${m%$'\n'}"; plain="$m"
    if [[ -n "$m" ]]; then
        mapfile -t ml <<<"$m"; printf -v m '%s\\n' "${ml[@]}"     # every line + a literal \n (printf's \\), the last one cut off
        args+=( -m "${m:0:${#m}-2}" )
    fi
    if [[ -n "$st" && -d "${st%/*}" && ! -L "$st" ]] && { exec {fd}>>"$st"; } 2>/dev/null; then
        if flock -w 10 "$fd" 2>/dev/null; then
            last="$(head -c 32 "$st" 2>/dev/null | tr -dc '0-9')"
            is_uint "$last" && (( last > UB_NOTIFY_LAST )) && UB_NOTIFY_LAST="$last"
        else
            exec {fd}>&-; fd=""
        fi
    fi
    # 2.66: the Team Lead's notification centre (lib/notifygate.sh) decides - send now, hold for his summary, count
    # only; without the office's RAM folder (a copy outside the plugin, the tests) everything goes, as before
    UNG_FATE=sent; UNG_WHY=""; UNG_DUE=0; UNG_NOTICE=""
    if [[ -n "$home" && -d "$home" && ! -L "$home" ]] && declare -F ung_decide >/dev/null; then
        key="$(ung_key backup "$ev" "$1" "$level" "${d//$'\r'/}")"
        ung_resolves backup "$ev" && ung_line "$home" resolved backup - -      # a success resolves what went wrong
        ung_decide "$home" backup "$ev" "$level" "$key" "$(date +%s)"
    else
        home=""
    fi
    if [[ "$UNG_FATE" == sent ]]; then
        ub_notify_after "$UB_NOTIFY_LAST"
        if [[ -n "$fd" ]]; then "$UB_NOTIFY_BIN" "${args[@]}" >/dev/null 2>&1 {fd}>&-; else "$UB_NOTIFY_BIN" "${args[@]}" >/dev/null 2>&1; fi
        rc=$?
        UB_NOTIFY_LAST="$(date +%s)"
        (( rc == 0 )) || UNG_FATE=failed
        [[ -n "$home" ]] && ung_line "$home" sent backup "$level" "$key"
    fi
    if [[ -n "$UNG_NOTICE" ]]; then     # past a cap: ONE notice, the rest collected for a summary
        local who sub txt at
        at="$(date -d "@$UNG_DUE" +%H:%M 2>/dev/null)"
        if [[ "$UNG_NOTICE" == '*' ]]; then
            sub="$(ub_say notify.flood_all.subject)" || sub="The office is reporting a lot right now"
            txt="$(ub_say notify.flood_all "time=$at")" || txt="I'm collecting the rest - a summary follows at $at."
        else
            who="$(ub_say name)" || who="Mr. Backupsy"
            sub="$(ub_say notify.flood.subject "name=$who")" || sub="$who is reporting a lot right now"
            txt="$(ub_say notify.flood "time=$at")" || txt="I'm collecting the rest - a summary follows at $at."
        fi
        notice=( -e "Unraid Secretary Office" -s "Unraid Secretary Office: $sub" -d "$txt" -i warning )
        ub_notify_after "$UB_NOTIFY_LAST"
        if [[ -n "$fd" ]]; then "$UB_NOTIFY_BIN" "${notice[@]}" >/dev/null 2>&1 {fd}>&-; else "$UB_NOTIFY_BIN" "${notice[@]}" >/dev/null 2>&1; fi
        UB_NOTIFY_LAST="$(date +%s)"
        ung_line "$home" flood "$UNG_NOTICE" warning -
    fi
    [[ -n "$home" ]] && ung_drop "$home" "$UNG_FATE" "$UNG_WHY" "$UNG_DUE" backup "$ev" "$level" "$key" "$1" "${d//$'\r'/}" "$plain" ""
    if [[ -n "$fd" ]]; then
        printf '%s\n' "$UB_NOTIFY_LAST" >"$st" 2>/dev/null
        exec {fd}>&-
    fi
    return 0
}

# --- The notifications in the office's language (2.52): subject and description of what the engine tells come from Mr.
# Backupsy's language files (desks/backup/lang/<code>.json, keys notify.*), in the language the office was last used in -
# the copy in RAM the office's agent keeps (/var/run/unraid-secretary-office/notify.lang, {"lang": "de"}; it stays when
# the agent is away). No copy, no such file, no key: English, and without the language files at all (a copy outside the
# plugin) the English text the call carries. The long text (the run's report) stays English.
UB_NOTIFY_LANG_FILE="${UB_NOTIFY_LANG_FILE-$(ub_is_plugin && echo /var/run/unraid-secretary-office/notify.lang)}"
ub_lang_dir() { # the backup desk's language folder: the plugin's (public/ unpacked at its top) or a checkout's
    local d
    for d in "${UB_LANG_DIR:-}" "$UB_DIR/../desks/backup/lang" "$UB_DIR/../public/desks/backup/lang"; do
        [[ -n "$d" && -f "$d/en.json" ]] && { printf '%s' "$d"; return 0; }
    done
    return 1
}
ub_notify_lang() { # the office's language for notifications - en when none is known or spoken
    local f="$UB_NOTIFY_LANG_FILE" l="" d
    if [[ -n "$f" && -f "$f" && ! -L "$f" ]] && (( $(stat -c %s -- "$f" 2>/dev/null || echo 9999) <= 4096 )); then
        l="$(jq -r '.lang // "" | strings' -- "$f" 2>/dev/null)"
    fi
    d="$(ub_lang_dir)" || d=""
    [[ "$l" =~ ^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$ && -n "$d" && -f "$d/$l.json" ]] || l="en"
    printf '%s' "$l"
}
# ub_say <key> [name=value ...] - the text in the office's language (else English), {name} filled in, a plural
# ({"one", "other"}) by n; 1 (nothing printed) when no file has the key
ub_say() {
    local key="$1" d l f t="" kv n="" found=1
    shift
    d="$(ub_lang_dir)" || return 1
    for kv in "$@"; do [[ "$kv" == n=* ]] && n="${kv#n=}"; done
    is_uint "$n" || n=-1
    l="${UB_SAY_LANG:-$(ub_notify_lang)}"
    for f in "$d/$l.json" "$d/en.json"; do
        [[ -f "$f" ]] || continue
        t="$(jq -r --arg k "$key" --argjson n "$n" '.[$k] | if type == "object" then (if $n == 1 then (.one // .other) else .other end)
            elif type == "string" then . else empty end | strings' -- "$f" 2>/dev/null)" && [[ -n "$t" ]] && { found=0; break; }
    done
    (( found == 0 )) || return 1
    for kv in "$@"; do t="${t//"{${kv%%=*}}"/"${kv#*=}"}"; done
    t="${t//⟦/}"; t="${t//⟧/}"                       # an Unraid label as it is written (the texts here carry none)
    printf '%s' "$t"
}
# ub_tell <code> <level> <long text> <English subject> <English description> [name=value ...] - ub_notify with the
# subject "<his name>: <notify.<code>.subject>" and the description notify.<code> in the office's language; the
# English pair is what a copy without the language files says
ub_tell() {
    local code="$1" level="$2" long="$3" subj="$4" desc="$5" name s t
    shift 5
    local UB_SAY_LANG
    UB_SAY_LANG="$(ub_notify_lang)"
    name="$(ub_say name)" || name="Mr. Backupsy"
    s="$(ub_say "notify.$code.subject" "$@")" || s="$subj"
    t="$(ub_say "notify.$code" "$@")" || t="$desc"
    UB_NOTIFY_EVENT="$code" ub_notify "$name: $s" "$t" "$level" "$long"
}
# ub_counts <errors> <warnings> - «2 errors, 1 warning» in the office's language (errors left out when empty)
ub_counts() {
    local e w
    w="$(ub_say notify.count_warnings "n=$2")" || w="$2 warnings"
    [[ -n "$1" ]] || { printf '%s' "$w"; return 0; }
    e="$(ub_say notify.count_errors "n=$1")" || e="$1 errors"
    printf '%s, %s' "$e" "$w"
}

is_yes() { [[ "${1,,}" == "yes" || "${1,,}" == "ja" || "$1" == "1" || "${1,,}" == "true" ]]; }

# Integer check without surprises on empty values
is_uint() { [[ "$1" =~ ^[0-9]+$ ]]; }

# bytes -> GB (rounded down)
to_gb() { local b="${1:-0}"; is_uint "$b" || b=0; echo $(( b / 1073741824 )); }

human() { numfmt --to=iec --suffix=B "${1:-0}" 2>/dev/null || echo "${1:-0} B"; }

# --- The notifications' layout (mail-layout, 2026-10-09): Unraid mails them as plain text, the bell and the push agents
# show the same lines - a headline, then sections (an UPPERCASE title, items indented two spaces, one per line, a blank
# line between sections), long lists trimmed, word lists wrapped at ~70 characters. ub_notify turns the lines into
# Unraid's literal \n.
# human_sp <bytes>  - like human, with a space before the unit: 113 MB
human_sp() {
    local h; h="$(human "$1")"
    [[ "$h" =~ ^([0-9.,]+)([A-Za-z]+)$ ]] && h="${BASH_REMATCH[1]} ${BASH_REMATCH[2]}"
    printf '%s' "$h"
}
# dur_n <seconds>  - a duration for people: 6 h 4 min, 1 min 26 s, 9 s
dur_n() {
    local s="${1:-0}"; is_uint "$s" || s=0
    if (( s >= 3600 )); then printf '%d h' $(( s / 3600 )); (( s % 3600 >= 60 )) && printf ' %d min' $(( s % 3600 / 60 ))
    elif (( s >= 60 )); then printf '%d min' $(( s / 60 )); (( s % 60 )) && printf ' %d s' $(( s % 60 ))
    else printf '%d s' "$s"; fi
    return 0
}
# dur_c <seconds>  - coarser, for lists: 5 h 22 min, 20 min (rounded), 40 s
dur_c() {
    local s="${1:-0}"; is_uint "$s" || s=0
    if (( s >= 3600 )); then dur_n "$s"
    elif (( s >= 60 )); then printf '%d min' $(( (s + 30) / 60 ))
    else printf '%d s' "$s"; fi
}
# wrap_words <prefix> <separator> <item>...  - one list on lines of at most ~70 characters: the first behind <prefix>,
# the others under it (a hanging indent); a line ends with the separator's mark («,»), never with a space
wrap_words() {
    local pre="$1" sep="$2" line="" pad it n=0; shift 2
    printf -v pad '%*s' "${#pre}" ''
    line="$pre"
    for it in "$@"; do
        [[ -n "$it" ]] || continue
        if (( n == 0 )); then line+="$it"
        elif (( ${#line} + ${#sep} + ${#it} > 70 )); then printf '%s\n' "$line${sep% }"; line="$pad$it"
        else line+="$sep$it"; fi
        n=$(( n + 1 ))
    done
    (( n )) && printf '%s\n' "$line"
    return 0
}
# kopia_report <ok> <failed> <name|ok|seconds>...  - the Kopia section of a run's notification: every failed source,
# then the 4 longest, then «N more, each under X» (the longest of the rest, rounded up); the full list stays in the log
kopia_report() {
    local nok="$1" nfail="$2" l n ok secs d w1=0 w2=6
    local -a kf=() ko=()
    shift 2
    for l in "$@"; do
        IFS='|' read -r n ok secs _ <<<"$l"
        is_uint "$secs" || secs=0
        if [[ "$ok" == "1" ]]; then ko+=( "$secs|$n" ); else kf+=( "$n" ); fi
    done
    mapfile -t ko < <(printf '%s\n' "${ko[@]}" | sed '/^$/d' | LC_ALL=C sort -t'|' -k1,1nr -k2)
    echo "KOPIA   $nok ok, $nfail failed"
    for n in "${kf[@]}"; do (( ${#n} > w1 )) && w1=${#n}; done
    for l in "${ko[@]:0:4}"; do n="${l#*|}"; d="$(dur_c "${l%%|*}")"; (( ${#n} > w1 )) && w1=${#n}; (( ${#d} > w2 )) && w2=${#d}; done
    for n in "${kf[@]}"; do printf '  %-*s   %*s\n' "$w1" "$n" "$w2" "FAILED"; done
    for l in "${ko[@]:0:4}"; do printf '  %-*s   %*s\n' "$w1" "${l#*|}" "$w2" "$(dur_c "${l%%|*}")"; done
    (( ${#ko[@]} > 4 )) || return 0
    secs="${ko[4]%%|*}"
    if (( secs < 60 )); then d="1 min"; elif (( secs < 3600 )); then d="$(( secs / 60 + 1 )) min"; else d="$(( secs / 3600 + 1 )) h"; fi
    if (( ${#ko[@]} == 5 )); then echo "  1 more, under $d"; else echo "  $(( ${#ko[@]} - 4 )) more, each under $d"; fi
}

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
# Several values of a list key (UB_LIST_KEYS) are separated by line breaks. A key that holds one value given twice (pasted
# twice by hand): the last one counts - as cfg() always read it - and only that one is kept (CFG_TWICE: <key> US <line>
# US <the value dropped>, for the setup to say it); before, both were kept and the setup took them for a list.
UB_LIST_KEYS='\|(ignore|no_stop|known|skip|kopia_ignore|kopia_known|exclude_dataset|tar_exclude|folder|partner|partner_place)$'

declare -gA CFG=()
declare -ga CFG_SECTIONS=() CFG_ERRORS=() CFG_TWICE=()

cfg_load() {
    local file="$1" line sec="" key val n=0
    local re_sec='^\[([a-z]+)\]$'
    local re_named='^\[([a-z]+)[[:space:]]+"(.+)"\]$'
    local re_kv='^([a-z0-9_]+)[[:space:]]*=[[:space:]]*(.*)$'
    local -A seen=()
    CFG=(); CFG_SECTIONS=(); CFG_ERRORS=(); CFG_TWICE=()
    [[ -r "$file" ]] || return 1
    while IFS= read -r line || [[ -n "$line" ]]; do
        n=$((n+1))
        (( n == 1 )) && line="${line#$'\xEF\xBB\xBF'}"      # a byte order mark an editor put in front (UTF-8 BOM)
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
            if [[ -z "${CFG[$key]:-}" ]]; then CFG[$key]="$val"
            elif [[ "$key" =~ $UB_LIST_KEYS ]]; then CFG[$key]+=$'\n'"$val"
            else CFG_TWICE+=( "$key"$'\x1f'"$n"$'\x1f'"${CFG[$key]}" ); CFG[$key]="$val"; fi
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
    [general]="server mount_root view_root snap_prefix btrfs_snap_dir keep_runs keep_logs min_free_gb keep_mounts notify_success dumps_share partner_place asleep_pools preset_new"
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
    [app]="kopia folder kopia_retention kopia_ignore partner"   # partner since 2.45: an app whose folder is a dataset of its own
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
    # since 2.31: Mr. Backupsy's default for new things - only setup.sh and the office read it, never a run
    _val "general|preset_new"    '^(auto|local|kopia|partner|kopia_partner)$'     "auto/local/kopia/partner/kopia_partner"
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
        [[ "$k" =~ ^(share\|.+\|partner|vm\|.+\|partner|app\|.+\|partner|general\|partner_place)$ ]] || continue
        while IFS= read -r pk; do
            [[ -z "$pk" ]] || partner_id_ok "$pk" || CFG_ERRORS+=( "${k##*|} = '$pk' in $(sec_display "${k%|*}") is invalid (a partner's id: 8 hex digits)" )
        done < <(cfg_list "$k")
    done
    # 2.50: what goes to a partner keeps at least one local snapshot - the partner's incremental send needs one snapshot
    # both sides still have; with «0 0 0» this run's own is gone too and every run would send everything in full
    local ret d w m
    for k in "${!CFG[@]}"; do
        [[ "$k" =~ ^(share\|.+\|partner|vm\|.+\|partner|app\|.+\|partner|general\|partner_place)$ ]] || continue
        pk=0
        while IFS= read -r n; do partner_id_ok "$n" && pk=1; done < <(cfg_list "$k")
        [[ "$pk" == 1 ]] || continue
        ret="$(unit_retention "$k")"
        [[ "$ret" =~ ^[0-9]+\ [0-9]+\ [0-9]+$ ]] || continue
        read -r d w m <<<"$ret"
        (( 10#$d + 10#$w + 10#$m == 0 )) && CFG_ERRORS+=( "retention = '$ret' for $(sec_display "${k%|*}"), which goes to a partner office, is invalid (keep at least one snapshot, e.g. '1 0 0': the partner's incremental send needs one snapshot both sides still have - without it everything would go over in full every time)" )
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
    # (2.36) Ms. Dustdevil's storeroom became a hidden folder: whoever leaves out its old name leaves out the new one
    # too - also before the setup has written the rule for it (setup.sh adds it to the plan)
    if in_list "$UB_STOREROOM_OLD*/" "${KOPIA_IGNORE[@]}" && ! in_list "$UB_STOREROOM*/" "${KOPIA_IGNORE[@]}"; then
        KOPIA_IGNORE+=( "$UB_STOREROOM*/" )
    fi
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
    # 2.65: a config without a root anywhere is a share only while Unraid lists it (shares.ini: a share just made, still
    # empty) - one Unraid no longer lists (its roots gone, e.g. after Unraid renamed it) is gone, never «still empty» and
    # offered for Kopia. Without Unraid's list (not readable, a test's own /mnt) every config counts, as up to 2.64.
    local -A names=() listed=()
    local b d s f sini="${UB_SHARES_INI:-}" have_list=0
    [[ -z "$sini" && "$UB_MNT" == /mnt ]] && sini=/var/local/emhttp/shares.ini
    if [[ -n "$sini" && -r "$sini" ]] && grep -q '^\[' "$sini" 2>/dev/null; then
        have_list=1
        while IFS= read -r s; do [[ -n "$s" ]] && listed[$s]=1; done \
            < <(sed -n 's/^\["\{0,1\}\([^"]*\)"\{0,1\}\]$/\1/p; s/^name="\(.*\)"$/\1/p' "$sini")
    fi
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
        (( have_list )) && [[ -z "${names[$s]:-}" && -z "${listed[$s]:-}" ]] && continue
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
#   CT_PAUSED[n]         true: paused (docker pause, Unraid's Pause) - CT_RUNNING is true then too (2.64)
#   CT_BINDS[n]          lines "source|target|rw"
#   CT_VOLUMES[n]        lines "volume|target"
#   CT_HEALTH[n]         "" or healthcheck status
#   CT_ENVKEYS[n]        names of the environment variables (names only, never values)
#   CT_PORTS[n]          exposed ports, e.g. "5432/tcp 8080/tcp"
#   CT_PROJECT[n] CT_SERVICE[n]   Compose stack and service (empty for single containers)
declare -ga CT_NAMES=()
declare -gA CT_IMAGE=() CT_RUNNING=() CT_NET=() CT_ID=() CT_BINDS=() CT_VOLUMES=() CT_HEALTH=() CT_PAUSED=()
declare -gA CT_ENVKEYS=() CT_PORTS=() CT_PROJECT=() CT_SERVICE=()

DOCKER_SERVICE="yes"    # (2.54) "no": Unraid's Docker service is switched off - no containers, no Kopia (ub_docker_service_off)
docker_load() {
    CT_NAMES=(); CT_IMAGE=(); CT_RUNNING=(); CT_NET=(); CT_ID=(); CT_BINDS=(); CT_VOLUMES=(); CT_HEALTH=(); CT_PAUSED=()
    CT_ENVKEYS=(); CT_PORTS=(); CT_PROJECT=(); CT_SERVICE=()
    DOCKER_SERVICE="yes"
    ub_docker_service_off && { DOCKER_SERVICE="no"; return 0; }
    local ids n img run net id binds vols health envk ports proj svc paused
    mapfile -t ids < <(docker ps -aq 2>/dev/null)
    [[ ${#ids[@]} -eq 0 ]] && return 0
    # Field separator \x1e instead of tab: bash merges consecutive tabs,
    # an empty field (e.g. no healthcheck) would shift all following ones
    while IFS=$'\x1e' read -r n img run net id health binds vols envk ports proj svc paused; do
        [[ -z "$n" ]] && continue
        CT_NAMES+=( "$n" )
        CT_IMAGE[$n]="$img"; CT_RUNNING[$n]="$run"; CT_NET[$n]="$net"; CT_ID[$n]="$id"
        CT_HEALTH[$n]="$health"
        CT_BINDS[$n]="${binds//$'\x1f'/$'\n'}"
        CT_VOLUMES[$n]="${vols//$'\x1f'/$'\n'}"
        CT_ENVKEYS[$n]="${envk//$'\x1f'/$'\n'}"
        CT_PORTS[$n]="$ports"
        CT_PROJECT[$n]="$proj"; CT_SERVICE[$n]="$svc"
        CT_PAUSED[$n]="${paused:-false}"
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
            (.Config.Labels["com.docker.compose.service"] // ""),
            ((.State.Paused // false)|tostring)
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
#   COMPOSE_PROJ   (2.60) every stack with a compose file: "stack<US>ids" - ids: the names Docker may label its containers
#                  with (com.docker.compose.project), joined by <RS>: its name file or folder, the name docker compose
#                  config gives it, each also lower-cased and normalised as compose does ([a-z0-9_-])
#   COMPOSE_SVC    (2.60) every service of a stack that could be read: "stack<US>service<US>container_name"
# Read once per run of the script (compose_scan again returns at once).
declare -ga COMPOSE_DB=() COMPOSE_ERR=() COMPOSE_PROJ=() COMPOSE_SVC=()
COMPOSE_SCANNED="no"
compose_ids() { # compose_ids <name>... -> the ids, one per line, each once
    local n l
    for n in "$@"; do
        [[ -z "$n" ]] && continue
        l="${n,,}"
        printf '%s\n' "$n" "$l" "$(tr -cd 'a-z0-9_-' <<<"$l")"
    done | awk 'length && !seen[$0]++'
}
compose_scan() {
    [[ "$COMPOSE_SCANNED" == "yes" ]] && return 0
    COMPOSE_SCANNED="yes"
    COMPOSE_DB=(); COMPOSE_ERR=(); COMPOSE_PROJ=(); COMPOSE_SVC=()
    local root="$UB_BOOT/config/plugins/compose.manager/projects" d dir f js stack svc img envk ports cname r folder pname
    [[ -d "$root" ]] || return 0
    docker compose version >/dev/null 2>&1 || { COMPOSE_ERR+=( "*|docker compose is missing" ); return 0; }
    for d in "$root"/*/; do
        [[ -d "$d" ]] || continue
        stack="$(basename "$d")"; dir="${d%/}"; folder="$stack"
        # "indirect": the stack lives in another folder
        [[ -s "$dir/indirect" ]] && dir="$(head -1 "$dir/indirect" | tr -d '\r')"
        f=""
        for r in compose.yaml compose.yml docker-compose.yml docker-compose.yaml; do
            [[ -f "$dir/$r" ]] && { f="$dir/$r"; break; }
        done
        [[ -n "$f" ]] || { COMPOSE_ERR+=( "$stack|no compose file in $dir" ); continue; }
        if [[ -s "$dir/name" ]]; then stack="$(head -1 "$dir/name" | tr -d '\r')"
        elif [[ -s "${d%/}/name" ]]; then stack="$(head -1 "${d%/}/name" | tr -d '\r')"; fi
        js="$(cd "$dir" && timeout 30 docker compose --project-directory "$dir" -f "$f" config --format json 2>/dev/null)" \
            || { COMPOSE_ERR+=( "$stack|docker compose config fails (missing variables?)" )
                 COMPOSE_PROJ+=( "$stack"$'\x1f'"$(compose_ids "$stack" "$folder" | paste -sd $'\x1e' -)" ); continue; }
        pname="$(jq -r '.name // ""' <<<"$js" 2>/dev/null)"
        COMPOSE_PROJ+=( "$stack"$'\x1f'"$(compose_ids "$stack" "$folder" "$pname" | paste -sd $'\x1e' -)" )
        while IFS=$'\x1e' read -r svc img envk ports cname; do
            [[ -z "$svc" ]] && continue
            COMPOSE_SVC+=( "$stack"$'\x1f'"$svc"$'\x1f'"$cname" )
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
# media_sqlite_check <container> <kind> <copy>  -> SQ_CHECK_OUT: the first lines of PRAGMA quick_check on a database
# copy; SQ_CHECK_BY = plex (the server's own SQLite checked it) or host (Unraid's sqlite3). Plex's database carries an ICU
# collation (icu_root) and an FTS tokenizer (collating) only its own SQLite knows: Unraid's sqlite3 stops at them and
# checks nothing (2.55). So a Plex copy is checked by the SQLite inside the running container - its loader, libraries
# and «Plex Media Server --sqlite» (what its «Plex SQLite» starts), opened by root through /proc/<pid>/root and run as
# nobody (setpriv, no new privileges, empty environment). The copy lies in root's own staging folder: it is bound onto
# an empty file in a fresh folder in /tmp, in a mount namespace of its own (unshare -m, private - gone with the
# process), and opened there read only (immutable: nothing written beside it). First a sum it must answer within
# 20 s - a binary that doesn't know --sqlite never runs as a server for long.
UB_PROC="${UB_PROC:-/proc}"
media_sqlite_check() {
    local c="$1" k="$2" out="$3" pid d
    SQ_CHECK_BY="host"; SQ_CHECK_OUT=""
    if [[ "$k" == plex ]] && command -v setpriv >/dev/null 2>&1 && command -v unshare >/dev/null 2>&1 \
       && pid="$(timeout 20 docker inspect -f '{{.State.Pid}}' "$c" 2>/dev/null)" && [[ "$pid" =~ ^[1-9][0-9]{0,9}$ ]]; then
        for d in /usr/lib/plexmediaserver /app/usr/lib/plexmediaserver; do      # official and linuxserver; hotio
            d="$UB_PROC/$pid/root$d"
            [[ -f "$d/lib/ld-musl-x86_64.so.1" && -f "$d/Plex Media Server" ]] || continue
            [[ "$(media_plex_sqlite "$d" 20 '' 'SELECT 6*7;')" == "42" ]] || continue
            SQ_CHECK_BY="plex"
            SQ_CHECK_OUT="$(media_plex_sqlite "$d" "${SQ_TIMEOUT:-600}" "$out" 'PRAGMA quick_check;' | head -3)"
            return 0
        done
    fi
    SQ_CHECK_OUT="$(timeout "${SQ_TIMEOUT:-600}" sqlite3 "file:$(uri_escape "$out")?mode=ro" 'PRAGMA quick_check;' 2>&1 | head -3)"
}
# media_plex_sqlite <dir in /proc/<pid>/root> <seconds> <copy, empty = :memory:> <sql>   (files opened as root, run as nobody)
media_plex_sqlite() {
    local d="$1" s="$2" copy="$3" sql="$4" v="" rc
    local -a run=(env -i PATH=/usr/bin:/bin HOME=/nonexistent "$(command -v setpriv)" --reuid=99 --regid=100 --clear-groups --no-new-privs --
                  timeout "$s" /proc/self/fd/5 --library-path /proc/self/fd/6 /proc/self/fd/7 --sqlite)
    if [[ -z "$copy" ]]; then
        ( exec 5<"$d/lib/ld-musl-x86_64.so.1" 6<"$d/lib" 7<"$d/Plex Media Server" || exit 1; exec "${run[@]}" ':memory:' "$sql" ) </dev/null 2>&1
        return
    fi
    v="$(mktemp -d /tmp/.uso-sqlite.XXXXXX)" && chmod 0755 "$v" && : >"$v/copy.db" || { [[ -n "$v" ]] && rm -rf "$v"; echo "no place to check the copy"; return 1; }
    ( exec 5<"$d/lib/ld-musl-x86_64.so.1" 6<"$d/lib" 7<"$d/Plex Media Server" || exit 1
      exec unshare -m --propagation private sh -c 'mount --bind "$1" "$2" || exit 1; shift 2; exec "$@"' sh "$copy" "$v/copy.db" \
          "${run[@]}" "file:$v/copy.db?immutable=1" "$sql" ) </dev/null 2>&1
    rc=$?
    rm -f "$v/copy.db"; rmdir "$v" 2>/dev/null
    return "$rc"
}
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
#   PLAN_DELETED     shares Unraid deleted whose datasets their snapshots keep (2.69, share_deleted_left): left out
declare -ga PLAN_KOPIA=() PLAN_SNAP=() PLAN_ZFS=() PLAN_BTRFS=() PLAN_KITEMS=() PLAN_MOUNT=() PLAN_DELETED=()
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
# unit_retention <a unit's partner key: share|<s>|partner, vm|<v>|partner, app|<a>|partner, general|partner_place>
#   -> the «d w m» its local snapshots keep (2.50, cfg_validate): a VM's own, else the share it lies in (an app's first
#   folder's, the backup place's), else [zfs] retention - read from settings.ini, so it works before apply_settings
unit_retention() {
    local k="$1" n s="" f
    case "$k" in
        "general|partner_place") s="$(cfg "general|dumps_share")" ;;
        "share|"*) s="${k#share|}"; s="${s%|partner}" ;;
        "vm|"*) n="${k#vm|}"; n="${n%|partner}"
                [[ -n "$(cfg "vm|$n|retention")" ]] && { cfg "vm|$n|retention"; return 0; } ;;
        "app|"*) n="${k#app|}"; n="${n%|partner}"; f="$(cfg_list "app|$n|folder")"; f="${f%%$'\n'*}"; s="${f%%/*}" ;;
    esac
    if [[ -n "$s" ]]; then cfg "share|$s|retention" "$(cfg "zfs|retention" "7 4 6")"; else cfg "zfs|retention" "7 4 6"; fi
}
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
    PLAN_KOPIA=(); PLAN_SNAP=(); PLAN_ZFS=(); PLAN_BTRFS=(); PLAN_ZFS_RET=(); PLAN_EXCL=(); PLAN_KITEMS=(); PLAN_MOUNT=(); PLAN_DELETED=()
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
        # 2.69: deleted in Unraid, only its snapshots keep the dataset - no new snapshot, no Kopia (said by the drift)
        share_deleted_left "$s" && { PLAN_DELETED+=( "$s" ); continue; }
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
            # Ms. Dustdevil's storeroom (datasets put away as <storeroom>-<stamp>-<name>) is never backed up - the
            # storeroom .UnraidSecretaryOffice-trash (hidden since 2.36) and its old name _UnraidSecretaryOffice-trash
            if in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}" || [[ -n "${vm_excl[$ds]:-}" ]] \
               || ub_storeroom "${ds##*/}"; then
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

# --- 2.69: a share Unraid deleted, its dataset kept by snapshots -------------
# Unraid's «Delete» of an emptied share whose ZFS dataset carries snapshots drops the share's config but can't destroy
# the dataset: it stays mounted (and listed in shares.ini), and up to 2.68 every run snapshotted it again as the share.
# share_deleted_left <share>  -> 0 when that is the share now: no config of it in UB_SHARES_CFG (that folder there),
# every part of it a ZFS dataset of its own (not a folder in one) with no child datasets, nearly nothing referenced
# (< 1 MiB, looked at before its folder is read) and nothing in it, on a base that doesn't sleep, with snapshots.
# Sets SDL_DS (the datasets, ", "-joined) and SDL_SNAPS (their snapshots). Read only: the engine never destroys it -
# the run leaves it out (plan_build) and says it (drift share_deleted_snapshots), the setup says the way
share_deleted_left() {
    local s="$1" b m layer sub n=0 c
    SDL_DS=""; SDL_SNAPS=0
    [[ "$HAVE_ZFS" == "yes" && -n "$s" && -d "$UB_SHARES_CFG" && ! -e "$UB_SHARES_CFG/$s.cfg" ]] || return 1
    [[ "${INV_METHOD[$s]:-}" == "snap" && -z "${INV_CHILDREN[$s]:-}" ]] || return 1
    while IFS='|' read -r b m layer sub; do
        [[ -z "$b" ]] && continue
        [[ "$m" == "zfs" && -z "$sub" && -n "$layer" ]] || return 1
        [[ "${ZDS_REF[$layer]:-}" =~ ^[0-9]+$ ]] && (( ZDS_REF[$layer] < 1048576 )) || return 1
        ub_base_sleeps "$b" && return 1
        [[ -z "$(find "${INV_BASE_PATH[$b]}/$s" -mindepth 1 -maxdepth 1 ! -name .zfs -print -quit 2>/dev/null)" ]] || return 1
        c="$(zfs list -H -t snapshot -o name -d 1 "$layer" 2>/dev/null | grep -c "^$layer@")"
        SDL_SNAPS=$(( SDL_SNAPS + ${c:-0} ))
        SDL_DS+="${SDL_DS:+, }$layer"; n=$((n+1))
    done <<<"${INV_LOCS[$s]:-}"
    (( n > 0 && SDL_SNAPS > 0 ))
}

# --- 2.69: the room left on a pool holding an app's data ---------------------
# An app stopped for the snapshot on a pool with almost no room may not start cleanly again (a database, a lock, a log
# written at its start), and the new snapshot keeps every block it overwrites afterwards - the pool fills to the brim.
# Low: free below UB_POOL_LOW_PCT (2) % of the pool, that at least UB_POOL_LOW_MIN_GB (1) and at most UB_POOL_LOW_MAX_GB
# (10) GiB. Only pools (an array disk is never asked), never a sleeping one (it isn't woken for this).
# pool_low <base>  -> 0 when low; PL_FREE, PL_SIZE, PL_FLOOR in bytes (ZFS: the pool's root dataset, used + available;
# else the file system's df)
pool_low() {
    local b="$1" r u f pct="${UB_POOL_LOW_PCT:-2}" lo="${UB_POOL_LOW_MIN_GB:-1}" hi="${UB_POOL_LOW_MAX_GB:-10}"
    PL_FREE=0; PL_SIZE=0; PL_FLOOR=0
    [[ "${INV_BASE_KIND[$b]:-}" == "pool" && -n "${INV_BASE_PATH[$b]:-}" ]] || return 1
    ub_base_sleeps "$b" && return 1
    if [[ "${INV_BASE_FS[$b]:-}" == "zfs" ]]; then
        r="$(zfs list -H -p -o used,available "${INV_BASE_SRC[$b]%%/*}" 2>/dev/null | head -1)"
        read -r u f <<<"$r"
        [[ "$u" =~ ^[0-9]+$ && "$f" =~ ^[0-9]+$ ]] || return 1
        PL_FREE=$f; PL_SIZE=$(( u + f ))
    else
        r="$(df -P -B1 "${INV_BASE_PATH[$b]}" 2>/dev/null | awk 'NR==2 {print $4, $2}')"
        read -r f u <<<"$r"
        [[ "$u" =~ ^[0-9]+$ && "$f" =~ ^[0-9]+$ ]] || return 1
        PL_FREE=$f; PL_SIZE=$u
    fi
    [[ "$pct" =~ ^[0-9]+$ && "$lo" =~ ^[0-9]+$ && "$hi" =~ ^[0-9]+$ ]] || { pct=2; lo=1; hi=10; }
    PL_FLOOR=$(( PL_SIZE / 100 * pct ))
    (( PL_FLOOR < lo * 1073741824 )) && PL_FLOOR=$(( lo * 1073741824 ))
    (( PL_FLOOR > hi * 1073741824 )) && PL_FLOOR=$(( hi * 1073741824 ))
    (( PL_SIZE > 0 && PL_FREE < PL_FLOOR ))
}
# ct_low_pools <container>  -> the pools its folders (binds) lie on that are low (pool_low), one per line
ct_low_pools() {
    local src b
    local -A seen=()
    while IFS='|' read -r src _ _; do
        [[ -n "$src" ]] || continue
        while IFS= read -r b; do
            [[ -n "$b" && -z "${seen[$b]:-}" ]] || continue
            seen[$b]=1
            pool_low "$b" && printf '%s\n' "$b"
        done <<<"$(ub_path_bases "$src")"
    done <<<"${CT_BINDS[$1]:-}"
    return 0
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
KOPIA_VERSION=""; KOPIA_CONFIG_FILE=""; KOPIA_STORAGE=""; KOPIA_REPO_ID=""; KP_JSON="[]"
KOPIA_SERVER_UID=""     # UID the Kopia server runs as inside the container
KOPIA_RUN_UID="0"       # UID for docker exec (= server UID, so that cache/logs belong to it)

# Call Kopia inside the container - always as the same user as the server.
# Every Kopia call (even "repository status") writes to cache and logs.
# If it ran as root while the server runs as e.g. UID 99, root-owned
# cache folders (0700) would appear - the server could no longer open
# the repository afterwards ("permission denied"). Snapshots need root, though,
# to read every file -> the server has to run as root as well.
# 2.67: a policy look or change takes seconds once the repository is open (the Kopia check opened it before) - its limit
# is UB_KOPIA_POLICY_TIMEOUT, not the exec's UB_DOCKER_EXEC_TIMEOUT. A call its limit ended (its process inside ended
# too, ub_exec_end) means Kopia doesn't answer: every later call of this setup or run with a limit is refused at once
# (exit 124, kopia_silent) instead of waiting that long again; an upload (UB_DOCKER_LONG) is asked as before.
# 2.68: every other Kopia call but the upload (--version, repository status, snapshot list - reads) UB_KOPIA_TIMEOUT
# (300 s), not the exec's 900 s: a storage that hangs makes the first of them wait that long at most, the rest not at all
UB_KOPIA_POLICY_TIMEOUT="${UB_KOPIA_POLICY_TIMEOUT:-120}"
UB_KOPIA_TIMEOUT="${UB_KOPIA_TIMEOUT:-300}"
kopia_x() {
    local e=() t="$UB_KOPIA_TIMEOUT" rc
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    [[ "${1:-}" == "policy" ]] && t="$UB_KOPIA_POLICY_TIMEOUT"
    if [[ "${UB_DOCKER_LONG:-0}" != "1" ]] && kopia_silent; then
        log_file "  Kopia not asked (it did not answer before): kopia $1 ..."
        return 124
    fi
    UB_EXEC_LIMIT="$t" docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" kopia --no-progress "$@"; rc=$?
    [[ -n "$UB_EXEC_TIMED_OUT" && "${UB_DOCKER_LONG:-0}" != "1" ]] && kopia_silent_mark "$UB_EXEC_TIMED_OUT" "$1"
    return "$rc"
}
# The mark lives in state/ (the scripts call Kopia in subshells too), for this process alone: its pid in the name, a
# token of this start inside - a mark an earlier process of the same pid left is never taken for this one's
UB_KOPIA_SILENT_ID="$$.$(date +%s).$RANDOM"
kopia_silent_file() { printf '%s/.kopia-silent.%s' "$UB_STATE" "$$"; }
kopia_silent() { local m; m="$(cat "$(kopia_silent_file)" 2>/dev/null)" && [[ "${m%% *}" == "$UB_KOPIA_SILENT_ID" ]]; }
kopia_silent_seconds() { local m; m="$(cat "$(kopia_silent_file)" 2>/dev/null)"; m="${m#* }"; printf '%s' "${m%% *}"; }
kopia_silent_mark() { # <seconds> <what Kopia was asked>
    kopia_silent && return 0
    find "$UB_STATE" -maxdepth 1 -name '.kopia-silent.*' -mmin +1440 -delete 2>/dev/null     # older processes' marks
    printf '%s %s %s\n' "$UB_KOPIA_SILENT_ID" "$1" "$2" >"$(kopia_silent_file)" 2>/dev/null
    log_file "  Kopia did not answer within $1 s (kopia $2 ...) - no further Kopia step waits for it"
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
    KOPIA_VERSION=""; KOPIA_CONFIG_FILE=""; KOPIA_STORAGE=""; KOPIA_REPO_ID=""; KOPIA_FORMAT_GONE="no"
    KOPIA_UNREACHABLE="no"; KOPIA_REACH_WHY=""
    KOPIA_LOCK_MODE=""; KOPIA_LOCK_PERIOD=0
    [[ -n "$KOPIA_CONTAINER" ]] || return 1
    [[ "$(docker inspect -f '{{.State.Running}}' "$KOPIA_CONTAINER" 2>/dev/null)" == "true" ]] || return 1
    KOPIA_RUNNING="yes"
    kopia_detect_uid
    KOPIA_VERSION="$(kopia_x --version 2>/dev/null | awk 'NR==1{print $1}')"
    local js e
    if ! js="$(kopia_x repository status --json 2>/dev/null)"; then
        # 2.53: the storage answers, but the repository's own format blob is gone - all of it was deleted (an attacker with
        # the bucket's key; Kopia's cache of it expired) - never said for an error of the way there (unreachable, forbidden)
        e="$(kopia_x repository status --json 2>&1 >/dev/null)"
        if [[ "$e" == *"kopia.repository blob: BLOB not found"* ]]; then KOPIA_FORMAT_GONE="yes"
        # 2.66 (B16): connected (a repository.config), but Kopia can't open the repository - the way there (unreachable,
        # forbidden, a server down); «not connected» only when Kopia says so
        elif [[ -n "$e" && "$e" != *"not connected"* ]]; then KOPIA_UNREACHABLE="yes"; KOPIA_REACH_WHY="$(printf '%s' "$e" | tr -s '\n\t' '  ' | cut -c1-300)"; fi
        return 1
    fi
    [[ -n "$js" ]] || return 1
    KOPIA_USER="$(jq -r '.clientOptions.username // ""' <<<"$js")"
    KOPIA_HOST="$(jq -r '.clientOptions.hostname // ""' <<<"$js")"
    KOPIA_CONFIG_FILE="$(jq -r '.configFile // ""' <<<"$js")"
    KOPIA_STORAGE="$(jq -r '.storage.type // ""' <<<"$js")"
    KOPIA_REPO_ID="$(jq -r '.uniqueIDHex // "" | strings' <<<"$js")"      # 2.53: which repository (kopia_count_load)
    [[ "$KOPIA_REPO_ID" =~ ^[0-9a-f]{16,128}$ ]] || KOPIA_REPO_ID=""
    # 2.56: Object Lock (S3 created with --retention-mode/--retention-period): the mode and the period in seconds
    read -r KOPIA_LOCK_MODE KOPIA_LOCK_PERIOD <<<"$(jq -r '.blobRetention // {} | if (.retentionMode | type == "string")
        and (.retentionPeriod | type == "number") and .retentionPeriod > 0 then "\(.retentionMode) \(.retentionPeriod / 1000000000 | floor)"
        else "- 0" end' <<<"$js" 2>/dev/null)"
    [[ "$KOPIA_LOCK_MODE" =~ ^(COMPLIANCE|GOVERNANCE)$ ]] && is_uint "$KOPIA_LOCK_PERIOD" && (( KOPIA_LOCK_PERIOD > 0 )) \
        || { KOPIA_LOCK_MODE=""; KOPIA_LOCK_PERIOD=0; }
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

# --- How full the offsite repository is (2.53): the number of this identity's snapshots and sources, kept in
# state/kopia-count.json {v: 1, repo (Kopia's uniqueIDHex), identity, storage, time, snapshots, sources, by (run|orphans)}
# at the end of every real run's Kopia phase and after setup.sh --orphans-delete. The next run compares before its
# upload: far fewer than then (none, or less than half) that the office didn't delete - someone emptied the repository
# (an attacker with the bucket's key: Kopia then lists nothing and exits 0) - is said at once (warning_items
# kopia_emptied, a notification) and the run uploads everything anew. Another repository or identity is no comparison;
# a Kopia that errors (unreachable, forbidden) counts nothing. The office's Night Watchman looks once a day the same way
# (backup.sh --kopia-count, read only).
KC_FILE="$UB_STATE/kopia-count.json"
KC_SNAPS=""; KC_SOURCES=""
# kopia_count_load -> KC_SNAPS, KC_SOURCES (this identity's); 1 when Kopia didn't answer with a list
kopia_count_load() {
    KC_SNAPS=""; KC_SOURCES=""
    [[ "$KOPIA_CONNECTED" == "yes" && -n "$KOPIA_REPO_ID" ]] || return 1
    local e=() js c
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    js="$(timeout "${UB_KOPIA_LIST_TIMEOUT:-60}" docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" \
              kopia --no-progress snapshot list --all --json 2>/dev/null)" || return 1
    c="$(jq -r --arg u "$KOPIA_USER" --arg h "$KOPIA_HOST" 'if type == "array" then
            [.[] | select(.source.userName == $u and .source.host == $h)] | "\(length) \(map(.source.path) | unique | length)"
         else error("no list") end' 2>/dev/null <<<"$js")" || return 1
    [[ "$c" =~ ^([0-9]+)\ ([0-9]+)$ ]] || return 1
    KC_SNAPS="${BASH_REMATCH[1]}"; KC_SOURCES="${BASH_REMATCH[2]}"
}
# kopia_count_json <by> - the record as one line of JSON (what kopia_count_load found)
kopia_count_json() {
    jq -nc --arg repo "$KOPIA_REPO_ID" --arg identity "$KOPIA_ID" --arg storage "$KOPIA_STORAGE" --arg by "$1" \
        --argjson time "$(date +%s)" --argjson snapshots "$KC_SNAPS" --argjson sources "$KC_SOURCES" \
        '{v: 1, repo: $repo, identity: $identity, storage: $storage, time: $time, snapshots: $snapshots, sources: $sources, by: $by}'
}
# kopia_count_save <by> - counted anew and kept (a new file + mv); Kopia not answering leaves the last record as it is
kopia_count_save() {
    kopia_count_load || { log "  Kopia's snapshots not counted (it didn't answer) - the last count stays"; return 0; }
    local tmp="$UB_STATE/.kopia-count.json.$$"
    if kopia_count_json "$1" >"$tmp" 2>/dev/null; then chmod 600 "$tmp"; mv -f "$tmp" "$KC_FILE"; else rm -f "$tmp"; fi
    return 0
}
# --- Offsite unreachable since (2.66, B16): state/kopia-reach.json {v: 1, since, run, last, last_run} - the first real run
# whose Kopia check couldn't reach the repository (connected, but Kopia can't open it: the way there, the storage down) and
# the last one; kept from run to run, removed by the first real run that reaches it. Only a real backup writes it (never a
# tour, a dry run or the setup); Kopia's own words go to the log, never into the file.
KR_FILE="$UB_STATE/kopia-reach.json"
kopia_reach_save() { # kopia_reach_save unreachable|ok
    [[ "${UB_MODE:-}" == "backup" && "${DRY:-0}" != "1" && "${TS:-}" =~ ^[0-9]{8}-[0-9]{4}$ ]] || return 0
    if [[ "$1" != "unreachable" ]]; then
        if [[ -e "$KR_FILE" || -L "$KR_FILE" ]]; then rm -f "$KR_FILE"; log "  Kopia reaches its repository again - no longer «offsite unreachable»"; fi
        return 0
    fi
    local now old since="" run="" tmp="$UB_STATE/.kopia-reach.json.$$"
    now="$(date +%s)"
    if [[ -f "$KR_FILE" && ! -L "$KR_FILE" ]]; then
        old="$(jq -r 'select(.v == 1 and (.since | type == "number") and (.run | type == "string")) | "\(.since)\u001f\(.run)"' "$KR_FILE" 2>/dev/null)"
        IFS=$'\x1f' read -r since run <<<"$old"
    fi
    is_uint "$since" && [[ "$run" =~ ^[0-9]{8}-[0-9]{4}$ ]] || { since="$STARTED_AT"; run="$TS"; }
    is_uint "$since" || since="$now"
    if jq -nc --argjson since "$since" --arg run "$run" --argjson last "$now" --arg last_run "$TS" \
            '{v: 1, since: $since, run: $run, last: $last, last_run: $last_run}' >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$KR_FILE"
    else rm -f "$tmp"; fi
    log "  Offsite unreachable since $(date -d "@$since" '+%Y-%m-%d %H:%M' 2>/dev/null || echo "$since") (run $run)"
    return 0
}
# kopia_emptied <before> <now> - far fewer: none left, or less than half
kopia_emptied() { is_uint "$1" && is_uint "$2" && (( $1 > 0 && ( $2 == 0 || $2 * 2 < $1 ) )); }
# --- The Kopia step held (2.56): a repository suddenly (nearly) empty is no reason to upload everything anew over what
# is left - possibly an attack with the bucket's key (Object Lock may still hold the data: the Consultant's guide, a
# read-only --point-in-time connection beside the normal one), possibly the wrong repository. The run then leaves the
# Kopia step out (an error, warning_items kopia_held; an alert when the hold begins) and goes on with everything local
# and the partners. state/kopia-held.json {v: 1, repo, identity, storage, before, now, sources_before, sources_now, when
# (the count compared with), time (the hold began), gone (Kopia can't open it: its format blob deleted too)} keeps it
# from run to run until: the snapshots are back (no longer far fewer than before), Kopia is connected to another
# repository or identity (a new one), or the user says «yes, this repository is new» (backup.sh --kopia-accept=<repo>:
# the count is taken as it is now, kopia-count.json by accepted). A first run on a fresh repository has nothing to
# compare with - never held.
KH_FILE="$UB_STATE/kopia-held.json"
KOPIA_HELD="no"
KH_JSON=""
# kopia_held_load -> KH_JSON: the hold in exactly its shape; 1 when there is none
kopia_held_load() {
    KH_JSON=""
    [[ -f "$KH_FILE" && ! -L "$KH_FILE" ]] || return 1
    KH_JSON="$(jq -c 'select(.v == 1 and (.repo | type == "string") and (.identity | type == "string") and (.before | type == "number")
        and (.now | type == "number") and (.when | type == "number") and (.time | type == "number"))' "$KH_FILE" 2>/dev/null)"
    [[ -n "$KH_JSON" ]]
}
# kopia_held_write <repo> <identity> <before> <now> <sources before> <sources now> <when> <gone yes|no> - a new file + mv
kopia_held_write() {
    local tmp="$UB_STATE/.kopia-held.json.$$"
    if jq -nc --arg repo "$1" --arg identity "$2" --argjson before "$3" --argjson now "$4" --argjson sb "${5:-0}" --argjson sn "${6:-0}" \
            --argjson when "$7" --argjson time "$(date +%s)" --arg storage "${KOPIA_STORAGE:-}" --arg gone "$8" \
            '{v: 1, repo: $repo, identity: $identity, storage: $storage, before: $before, now: $now, sources_before: $sb,
              sources_now: $sn, when: $when, time: $time, gone: ($gone == "yes")}' >"$tmp" 2>/dev/null; then
        chmod 600 "$tmp"; mv -f "$tmp" "$KH_FILE"
    else
        rm -f "$tmp"
    fi
}
# kopia_held_end <why> - the hold is over (a real run only)
kopia_held_end() {
    log "  Kopia: $1 - the hold of the Kopia step ends"
    [[ "${DRY:-0}" == "1" || "${UB_MODE:-backup}" != "backup" ]] || rm -f "$KH_FILE"
    KH_JSON=""
}
# kopia_gone_check - Kopia can't open the repository because its format blob is gone (kopia_status_load): emptied down
# to its description - said like kopia_emptied (now 0) against the last record, whatever repository it was (none can be
# asked now), and once: the record then says 0 (by gone); the hold (2.56) keeps the page saying it
kopia_gone_check() {
    [[ "${KOPIA_FORMAT_GONE:-no}" == "yes" && -f "$KC_FILE" && ! -L "$KC_FILE" ]] || return 0
    local j was ws wt when tmp="$UB_STATE/.kopia-count.json.$$"
    j="$(jq -c 'select(.v == 1 and (.repo | type == "string") and (.identity | type == "string") and (.snapshots | type == "number")
        and (.time | type == "number"))' "$KC_FILE" 2>/dev/null)"
    read -r was wt ws <<<"$(jq -r '"\(.snapshots | floor) \(.time | floor) \(.sources // 0 | floor)"' <<<"$j" 2>/dev/null)"
    is_uint "$was" && is_uint "$wt" && (( was > 0 )) || return 0
    when="$(date -d "@$wt" '+%Y-%m-%d %H:%M')"
    warn_code kopia_gone "The offsite repository was emptied - Kopia can't open it any more, its own description is gone too ($was snapshots on $when) - not by the office. If that wasn't you: delete nothing, Object Lock may still hold the data (the Consultant's Object Lock guide shows how to get it back). Nothing went offsite in this run." \
        before="$was" when="$when"
    [[ "${DRY:-0}" == "1" || "${UB_MODE:-backup}" != "backup" ]] && return 0
    ub_tell kopia_gone alert "Log: $LOG_FILE" "The offsite copy was emptied" \
        "Kopia can't open the repository any more - its own description is gone too (snapshots on $when: $was); not deleted by the office. If that wasn't you: delete nothing, Object Lock may still hold the data; Mr. Restori and the Consultant show how to get it back." \
        "before=$was" "when=$when"
    kopia_held_write "$(jq -r '.repo' <<<"$j")" "$(jq -r '.identity' <<<"$j")" "$was" 0 "${ws:-0}" 0 "$wt" yes
    if jq -c --argjson time "$(date +%s)" '.snapshots = 0 | .sources = 0 | .time = $time | .by = "gone"' <<<"$j" >"$tmp" 2>/dev/null; then
        chmod 600 "$tmp"; mv -f "$tmp" "$KC_FILE"
    else
        rm -f "$tmp"
    fi
    return 0
}
# kopia_emptied_check - before the upload: the count now against the last record of the same repository and identity,
# and against the hold (2.56) - far fewer (none left, or less than half) holds the Kopia step: KOPIA_HELD=yes
kopia_emptied_check() {
    KOPIA_HELD="no"
    local was="" wt="" ws="" when hrepo="" hid="" hb="" hw="" hs="" before="" since="" sb=""
    if kopia_held_load; then
        IFS=$'\t' read -r hrepo hid hb hw hs <<<"$(jq -r '[.repo, .identity, (.before | floor), (.when | floor), (.sources_before // 0 | floor)] | @tsv' <<<"$KH_JSON")"
        [[ "$hrepo" == "$KOPIA_REPO_ID" && "$hid" == "$KOPIA_ID" ]] || kopia_held_end "connected to another repository or identity than the one held (a new one)"
    fi
    if ! kopia_count_load; then
        log "  Kopia's snapshots not counted (it didn't answer) - not compared"
        [[ -n "$KH_JSON" ]] || return 0
        before="$hb"; since="$hw"; sb="$hs"; KC_SNAPS="?"      # held before: nothing says the snapshots are back
    else
        if [[ -f "$KC_FILE" && ! -L "$KC_FILE" ]]; then
            read -r was wt ws <<<"$(jq -r --arg r "$KOPIA_REPO_ID" --arg i "$KOPIA_ID" 'select(.v == 1 and .repo == $r and .identity == $i
                and (.snapshots | type == "number") and (.time | type == "number")) | "\(.snapshots | floor) \(.time | floor) \(.sources // 0 | floor)"' "$KC_FILE" 2>/dev/null)"
        fi
        log "  Kopia snapshots:  $KC_SNAPS in $KC_SOURCES source(s)$(is_uint "$was" && echo " (last count: $was)")"
        if is_uint "$wt" && kopia_emptied "$was" "$KC_SNAPS"; then before="$was"; since="$wt"; sb="$ws"
        elif [[ -n "$KH_JSON" ]] && kopia_emptied "$hb" "$KC_SNAPS"; then before="$hb"; since="$hw"; sb="$hs"
        fi
        if [[ -z "$before" ]]; then
            [[ -n "$KH_JSON" ]] && kopia_held_end "the snapshots are back ($KC_SNAPS, $hb before)"
            return 0
        fi
    fi
    KOPIA_HELD="yes"
    when="$(date -d "@$since" '+%Y-%m-%d %H:%M')"
    err_code kopia_held "The offsite repository is suddenly empty: $before snapshots on $when, $KC_SNAPS now - not deleted by the office. A possible attack or the wrong repository: Kopia's step is held, nothing goes offsite (the local snapshots, packages and partners go on). If that wasn't you: delete nothing, Object Lock may still hold the data (the Consultant's Object Lock guide shows how to get it back). A repository that is really new: say so on Mr. Backupsy's page." \
        before="$before" now="$KC_SNAPS" when="$when"
    [[ "${DRY:-0}" == "1" || "${UB_MODE:-backup}" != "backup" ]] && return 0
    [[ -n "$KH_JSON" ]] && return 0                     # held before: said when the hold began
    kopia_held_write "$KOPIA_REPO_ID" "$KOPIA_ID" "$before" "$KC_SNAPS" "${sb:-0}" "${KC_SOURCES:-0}" "$since" no
    ub_tell kopia_held alert "Log: $LOG_FILE" "The offsite copy is suddenly empty" \
        "Snapshots on $when: $before, now: $KC_SNAPS - not deleted by the office. Possibly an attack or the wrong repository: nothing goes offsite until you decide. If that wasn't you: delete nothing, Object Lock may still hold the data; the Consultant shows how to get it back. A repository that is really new: say so on Mr. Backupsy's page." \
        "before=$before" "now=$KC_SNAPS" "when=$when"
    return 0
}
# kopia_accept <repo> (backup.sh --kopia-accept, 2.56): «yes, this repository is new» - the hold of exactly that repository
# ends, the count is taken as it was when held (kopia-count.json by accepted, before/when kept): what the next run and the
# Night Watchman compare with. Prints the answer as JSON; behind the engine's lock (taken by the caller)
kopia_accept() {
    local tmp="$UB_STATE/.kopia-count.json.$$"
    kopia_held_load || { jq -nc '{ok: false, why: "none"}'; return 0; }
    [[ "$(jq -r '.repo' <<<"$KH_JSON")" == "$1" ]] || { jq -nc '{ok: false, why: "other"}'; return 0; }
    if jq -c --argjson time "$(date +%s)" '{v: 1, repo, identity, storage: (.storage // ""), time: $time, snapshots: (.now | floor),
            sources: (.sources_now // 0 | floor), by: "accepted", accepted: {before: (.before | floor), when: (.when | floor), held: (.time | floor)}}' \
            <<<"$KH_JSON" >"$tmp" 2>/dev/null; then
        chmod 600 "$tmp"; mv -f "$tmp" "$KC_FILE"; rm -f "$KH_FILE"
        jq -nc --argjson h "$KH_JSON" '{ok: true, repo: $h.repo, before: $h.before, now: $h.now}'
    else
        rm -f "$tmp"; jq -nc '{ok: false, why: "write"}'
    fi
    return 0
}

# --- Object Lock kept (2.56): Kopia extends the locks of a repository with Object Lock only at its full maintenance
# (extendObjectLocks). It no longer running (another owner, the Kopia server stopped, full maintenance disabled)
# lets the locks lapse after the retention period - the protection gone without a word. state/kopia-lock.json:
# {v: 1, ok, time, repo, identity, lock: {mode, period_s} | null, maintenance: {owner, extend, full_enabled,
# full_interval_s, next_full, full_runs: [start...], extend_runs: [start...]}} (Kopia's times as it writes them; the
# office reads the starts of the successful runs) - written after a run's Kopia phase and by backup.sh --kopia-lock;
# {v: 1, ok: false, why, time} when Kopia couldn't be asked. The office judges it (Mr. Backupsy's checks).
KL_FILE="$UB_STATE/kopia-lock.json"
# kopia_lock_json - one line of JSON (Kopia connected: kopia_status_load); 1 when maintenance info gave no answer
kopia_lock_json() {
    local e=() info
    [[ "$KOPIA_CONNECTED" == "yes" ]] || return 1
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    info="$(timeout "${UB_KOPIA_LIST_TIMEOUT:-60}" docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" \
                kopia --no-progress maintenance info --json 2>/dev/null)" || return 1
    jq -c --arg repo "$KOPIA_REPO_ID" --arg identity "$KOPIA_ID" --arg mode "$KOPIA_LOCK_MODE" --argjson period "${KOPIA_LOCK_PERIOD:-0}" \
          --argjson time "$(date +%s)" '
        def starts($names): [(.schedule.runs // {}) | to_entries[] | select(.key as $k | $names | index($k)) | .value[]?
                             | select(.success == true) | .start | strings] | sort | .[-10:];
        if type != "object" then error("no info") else
        {v: 1, ok: true, time: $time, repo: $repo, identity: $identity,
         lock: (if $mode == "" then null else {mode: $mode, period_s: $period} end),
         maintenance: {owner: (.owner // "" | tostring), extend: (.extendObjectLocks == true), full_enabled: (.full.enabled == true),
                       full_interval_s: ((.full.interval // 0) / 1000000000 | floor),
                       next_full: (.schedule.nextFullMaintenance | if type == "string" then . else null end),
                       full_runs: starts(["snapshot-gc", "full-delete-blobs", "full-rewrite-contents", "full-drop-deleted-content"]),
                       extend_runs: starts(["extend-blob-retention-time"])}} end' 2>/dev/null <<<"$info"
}
# kopia_lock_save [why] - asked anew and kept (a new file + mv); Kopia not answering: {ok: false, why, time, last} - last
# the facts it gave the last time (the locks run out by them all the same: nothing extended them since that anyone saw)
kopia_lock_save() {
    local tmp="$UB_STATE/.kopia-lock.json.$$" last="null"
    if ! { [[ -z "${1:-}" ]] && kopia_lock_json >"$tmp"; } || [[ ! -s "$tmp" ]]; then
        [[ -f "$KL_FILE" && ! -L "$KL_FILE" ]] && last="$(jq -c 'if .ok == true then . elif (.last | type) == "object" then .last else null end' "$KL_FILE" 2>/dev/null)"
        jq -nc --arg why "${1:-no_answer}" --argjson time "$(date +%s)" --argjson last "${last:-null}" \
            '{v: 1, ok: false, why: $why, time: $time, last: $last}' >"$tmp" 2>/dev/null || { rm -f "$tmp"; return 0; }
    fi
    chmod 600 "$tmp"; mv -f "$tmp" "$KL_FILE"
    return 0
}

# --- Offsite that takes nothing (2.68) -------------------------------------
# Seen 2026-10-21: from one moment on every write to the repository (S3) hung for ever while reads and lists answered at
# once - the Kopia server's hourly maintenance stopped after reading kopia.maintenance (its next step, writing it, never
# returned; a container restart didn't help). Nothing failed, so nothing said it. Two guards:
#  * --kopia-watch (the office, every few minutes): the Kopia server's own content log, read through one docker exec - never
#    the repository opened, nothing written there. Kopia logs a storage step when it ends («n":"storage","m":"PutBlob" …
#    "error":null}): a server that wrote its maintenance before (a PutBlob of kopia.maintenance in its newest two logs - it is
#    the maintenance owner, it writes every hour) and has logged nothing for UB_KOPIA_WATCH_QUIET seconds (90 min: the hour
#    of its quick maintenance and half an hour) takes nothing since the first step after its last write. A server that
#    isn't the owner (state/kopia-lock.json says another user@host) logs nothing for days: never judged so.
#  * a run's upload (kopia_one in backup.sh) has no time limit, but a stall watchdog: the kopia process's own reads and
#    writes (/proc/<pid>/io rchar + wchar: the files it reads, the network) not moving for UB_KOPIA_STALL seconds ends it.
# state/kopia-watch.json {v: 1, time, state: ok|stuck|unknown, why, since, by: server|run, told, storage, last_write}:
# «stuck» is kept from look to look until a write newer than its `since` shows in the server's log (or an upload of a run
# went through); `told` - its notification went (once until resolved). The office's check and page read it.
KW_FILE="$UB_STATE/kopia-watch.json"
UB_KOPIA_WATCH_QUIET="${UB_KOPIA_WATCH_QUIET:-5400}"
UB_KOPIA_STALL="${UB_KOPIA_STALL:-900}"            # an upload whose process neither reads nor writes so long is ended
UB_KOPIA_LOOK="${UB_KOPIA_LOOK:-60}"               # its reads and writes looked at every so many seconds
UB_KOPIA_STALL_BYTES="${UB_KOPIA_STALL_BYTES:-1048576}"   # what counts as moving: so many bytes more read + written
UB_KOPIA_STALL_END="${UB_KOPIA_STALL_END:-10}"     # seconds between INT (Kopia saves what it can) and KILL

# kopia_watch_logs - the tails of the Kopia server's newest two content logs ("#file <name>" before each) and the
# repository's storage type and address ("#storage", then up to four "key": "value" lines - never another key of the
# file: its keys and passwords stay inside the container). 3: no content logs found
kopia_watch_logs() {
    UB_EXEC_LIMIT=30 docker exec -u 0 "$KOPIA_CONTAINER" sh -c '
        d=""
        for x in "${KOPIA_LOG_DIR:-}" "$HOME/.cache/kopia" /app/logs; do
            [ -n "$x" ] && [ -d "$x/content-logs" ] && { d="$x/content-logs"; break; }
        done
        [ -n "$d" ] || exit 3
        ls -1 "$d" | grep -e "-server-start\.[0-9]*\.log$" | sort -t. -k1,1 -k2,2n | tail -n 2 | while read -r f; do
            echo "#file $f"; tail -n 400 "$d/$f"; echo
        done
        echo "#storage"
        c="${KOPIA_CONFIG_PATH:-$HOME/.config/kopia/repository.config}"
        [ -f "$c" ] && grep -o -E "\"(type|endpoint|host|bucket)\": *\"[^\"]*\"" "$c" | head -n 4
        exit 0' 2>/dev/null
}

# kopia_watch_parse <now> - the logs (kopia_watch_logs) on stdin -> one line of JSON: {files, last, last_write, maint, since,
# storage}: the newest line's time, the last storage write that went through (PutBlob/DeleteBlob, error null), whether one of
# them wrote kopia.maintenance, the first line after that write (where it began to take nothing), "<type> · <address>"
kopia_watch_parse() {
    jq -R -s -c --argjson now "$1" '
        def secs: if type == "string" then (sub("\\.[0-9]+"; "") | try fromdateiso8601 catch null) else null end;
        def plain: gsub("[[:cntrl:]]"; "") | .[0:80];
        (split("\n") | reduce .[] as $l ({files: 0, mode: "log", ev: [], st: {}};
            if ($l | startswith("#file ")) then .files += 1 | .mode = "log"
            elif $l == "#storage" then .mode = "storage"
            elif .mode == "storage" then
                ($l | capture("^\"(?<k>[a-z]+)\": *\"(?<v>[^\"]*)\"$")? // null) as $kv
                | if $kv == null or .st[$kv.k] != null then . else .st[$kv.k] = $kv.v end
            else (try ($l | fromjson) catch null) as $j
                | if ($j | type) == "object" and ($j.t | secs) != null
                  then .ev += [{t: ($j.t | secs), n: ($j.n // ""), m: ($j.m // ""), b: ($j.blobID // ""), e: $j.error}] else . end
            end)) as $r
        | ($r.ev | map(select(.n == "storage" and (.m == "PutBlob" or .m == "DeleteBlob") and .e == null))) as $w
        | ($w | map(.t) | max) as $lw
        | {files: $r.files, last: ($r.ev | map(.t) | max), last_write: $lw,
           maint: ($w | any(.m == "PutBlob" and .b == "kopia.maintenance")),
           since: (if $lw == null then null else ($r.ev | map(select(.t > $lw)) | map(.t) | min) end),
           storage: ([($r.st.type // ""), (($r.st.endpoint // $r.st.host // "") | sub("^[a-z]+://"; "") | sub("^[^@/]*@"; "") | sub("/.*$"; ""))]
                     | map(select(. != "") | plain) | join(" · "))}'
}

# kopia_watch_owner_other - Kopia's maintenance belongs to another user@host than this identity (state/kopia-lock.json, the
# last time Kopia was asked): this server never writes its maintenance - its quiet means nothing
kopia_watch_owner_other() {
    jq -e '(if .ok == true then . else .last end) as $f | ($f.maintenance.owner // "") as $o | ($f.identity // "") as $i
           | $o != "" and $i != "" and $o != $i' "$KL_FILE" >/dev/null 2>&1
}

# kopia_watch_write <json> - kept as a new file + mv (0600)
kopia_watch_write() {
    local tmp="$UB_STATE/.kopia-watch.json.$$"
    printf '%s\n' "$1" >"$tmp" 2>/dev/null && chmod 600 "$tmp" && mv -f "$tmp" "$KW_FILE" && return 0
    rm -f "$tmp"; return 1
}
# kopia_watch_prev - the last record in exactly its shape, else {}
kopia_watch_prev() {
    [[ -f "$KW_FILE" && ! -L "$KW_FILE" ]] || { echo '{}'; return 0; }
    jq -c 'if type == "object" and .v == 1 and (.state | IN("ok", "stuck", "unknown")) then . else {} end' "$KW_FILE" 2>/dev/null || echo '{}'
}

# kopia_watch - one look (--kopia-watch): the record written and printed; a turn to «stuck» told once (a warning)
kopia_watch() {
    local now logs rc p prev rec kw_why="" since_h
    now="$(date +%s)"
    prev="$(kopia_watch_prev)"
    if [[ "$(docker inspect -f '{{.State.Running}}' "$KOPIA_CONTAINER" 2>/dev/null)" != "true" ]]; then kw_why="not_running"
    else
        logs="$(kopia_watch_logs)"; rc=$?
        if (( rc == 3 )); then kw_why="no_log"; elif (( rc != 0 )); then kw_why="no_answer"; fi
    fi
    if [[ -n "$kw_why" ]]; then p='{}'; else p="$(kopia_watch_parse "$now" <<<"$logs")" || p='{}'; fi
    [[ -n "$kw_why" || "$(jq -r '.files // 0' <<<"$p")" != "0" ]] || kw_why="no_log"
    local other=false; kopia_watch_owner_other && other=true
    rec="$(jq -c -n --argjson p "$p" --argjson prev "$prev" --argjson now "$now" --argjson quiet "$UB_KOPIA_WATCH_QUIET" \
              --arg why "$kw_why" --argjson other "$other" '
        ($prev.state == "stuck" and ($prev.since | type) == "number") as $was
        | {v: 1, time: $now, storage: (($p.storage // "") | if . == "" then ($prev.storage // "") else . end),
           last_write: ($p.last_write // $prev.last_write // null)} as $base
        | if $was and ($p.last_write // 0) > $prev.since then $base + {state: "ok", why: "wrote", since: null, by: null, told: false}
          elif $was then $base + {state: "stuck", why: ($prev.why // "quiet"), since: $prev.since, by: ($prev.by // "server"), told: ($prev.told == true)}
          elif $why != "" then $base + {state: "unknown", why: $why, since: null, by: null, told: false}
          elif $other then $base + {state: "unknown", why: "not_owner", since: null, by: null, told: false}
          elif ($p.maint == true) and ($p.last | type) == "number" and $now - $p.last > $quiet
          then $base + {state: "stuck", why: "quiet", since: ($p.since // $p.last), by: "server", told: false}
          elif ($p.maint == true) then $base + {state: "ok", why: "", since: null, by: null, told: false}
          else $base + {state: "unknown", why: "no_maintenance", since: null, by: null, told: false} end')" || return 1
    if [[ "$(jq -r '"\(.state) \(.told)"' <<<"$rec")" == "stuck false" ]]; then
        since_h="$(date -d "@$(jq -r '.since' <<<"$rec")" '+%Y-%m-%d %H:%M')"
        p="$(jq -r '.storage' <<<"$rec")"
        ub_tell kopia_writes warning "Kopia's server has logged nothing since $since_h - its maintenance writes every hour. Reads may still answer; nothing goes offsite until the storage takes writes again. Check the provider's status page, its limits and whether the key may still write, then restart the Kopia container ($KOPIA_CONTAINER)." \
            "Offsite takes nothing" "Since $since_h the offsite storage${p:+ ($p)} has taken nothing from Kopia. Check the provider's status and the key's rights, then restart the Kopia container." \
            "since=$since_h" "storage=${p:--}" "name=$KOPIA_CONTAINER"
        rec="$(jq -c '.told = true' <<<"$rec")"
    fi
    kopia_watch_write "$rec"
    printf '%s\n' "$rec"
}

# kopia_watch_run <stuck|ok> [since] - a run's word (2.68): its upload took nothing since <since> (the stall watchdog - the
# run says it in its own warnings and notification: told) or an upload went through (whatever the record said is over)
kopia_watch_run() {
    local prev rec
    prev="$(kopia_watch_prev)"
    if [[ "$1" == "stuck" ]]; then
        [[ "$(jq -r '.state // ""' <<<"$prev")" == "stuck" ]] && return 0
        rec="$(jq -c --argjson now "$(date +%s)" --argjson since "$2" \
            '{v: 1, time: $now, state: "stuck", why: "stalled", since: $since, by: "run", told: true, storage: (.storage // ""), last_write: (.last_write // null)}' <<<"$prev")"
    else
        [[ "$(jq -r '.state // ""' <<<"$prev")" == "stuck" ]] || return 0
        rec="$(jq -c --argjson now "$(date +%s)" '. + {time: $now, state: "ok", why: "uploaded", since: null, by: null, told: false}' <<<"$prev")"
    fi
    kopia_watch_write "$rec"
}

# kopia_upload_pid <container path> - the host pid of `kopia ... snapshot create <path>` inside the container (docker top)
kopia_upload_pid() {
    local out pid args
    out="$(timeout -k 2 20 docker top "$KOPIA_CONTAINER" -eo pid,args 2>/dev/null | tail -n +2)"
    while read -r pid args; do
        [[ "$pid" =~ ^[0-9]+$ && "$args" == *"snapshot create"* && "$args" == *"$1"* ]] && { printf '%s' "$pid"; return 0; }
    done <<<"$out"
    return 1
}
# kopia_io <pid> - what the process read and wrote so far in bytes (/proc/<pid>/io: rchar + wchar - files, network, pipes)
kopia_io() { [[ -r "/proc/$1/io" ]] && awk '$1 == "rchar:" || $1 == "wchar:" {s += $2} END {printf "%d", s}' "/proc/$1/io" 2>/dev/null; }

# The stall watchdog's look, called while an upload runs (kopia_one): every UB_KOPIA_LOOK seconds the process's reads and
# writes; 0 once they haven't moved by UB_KOPIA_STALL_BYTES for UB_KOPIA_STALL seconds (KS_AT: the last time they did,
# KS_IO the count then). Kopia waiting on a storage that takes nothing still moves a few bytes now and then (seen live: Go's
# own wake-ups, 8 bytes written and some read every few seconds) - that is no progress. A look that can't measure (docker
# top silent, the process not found yet) counts as moving - never ended on a guess.
KS_PID=""; KS_IO=""; KS_AT=0; KS_LOOKED=0
kopia_stall_begin() { KS_PID=""; KS_IO=""; KS_AT="$(date +%s)"; KS_LOOKED=0; }
kopia_stall_look() {
    local now io
    now="$(date +%s)"
    (( now - KS_LOOKED >= UB_KOPIA_LOOK )) || return 1
    KS_LOOKED="$now"
    [[ -n "$KS_PID" && -e "/proc/$KS_PID" ]] || KS_PID="$(kopia_upload_pid "$1")" || { KS_PID=""; KS_AT="$now"; return 1; }
    io="$(kopia_io "$KS_PID")"
    if [[ -z "$io" || -z "$KS_IO" ]] || (( io < KS_IO || io - KS_IO >= UB_KOPIA_STALL_BYTES )); then KS_IO="$io"; KS_AT="$now"; return 1; fi
    (( now - KS_AT >= UB_KOPIA_STALL ))
}
# kopia_stall_end <client pid> - the stalled upload ended: INT inside the container (Kopia saves what it can), KILL after
# UB_KOPIA_STALL_END seconds - a write that hangs keeps it from ending by itself; then its docker exec client
kopia_stall_end() {
    local i
    if [[ -n "$KS_PID" ]]; then
        kill -INT "$KS_PID" 2>/dev/null
        for (( i = 0; i < UB_KOPIA_STALL_END * 2; i++ )); do kill -0 "$KS_PID" 2>/dev/null || break; sleep 0.5; done
        kill -0 "$KS_PID" 2>/dev/null && { kill -KILL "$KS_PID" 2>/dev/null; log_file "  ... still there after ${UB_KOPIA_STALL_END} s: KILL"; }
    fi
    for (( i = 0; i < 20; i++ )); do kill -0 "$1" 2>/dev/null || return 0; sleep 0.5; done
    kill -TERM "$1" 2>/dev/null; sleep 1; kill -KILL "$1" 2>/dev/null
    return 0
}

# Host path under which Kopia reads a share - always <mount_root>/<share>,
# also for "live" (then a read-only bind of /mnt/user/<share>)
share_kopia_hostpath() { printf '%s' "$MOUNT_ROOT/$1"; }
FLASH_SOURCE_NAME="_flash"

# Wanted ignore list of a target
#   root    -> the global list from [kopia] (inherited by all shares)
#   share   -> only the share's own rules, and the parts of apps and VMs with a source of their own, and
#              (since 2.21) the rules for its new folders that stay local (NEW_RULES, section 11), and (since
#              2.38) those for the folders of existing apps/VMs set only local (KEPT_RULES, section 11)
#   app/vm  -> only its own rules (relative to its source: /<share>/<path>/)
kopia_want_ignores() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)   printf '%s\n' "${KOPIA_IGNORE[@]}" ;;
        share)  cfg_list "share|$s|kopia_ignore"; kopia_derived_ignores "$s"; printf '%s\n' "${NEW_RULES[$s]:-}" "${KEPT_RULES[$s]:-}" ;;
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

# kopia_policy_eval <container path> <kind: root|share|flash> <wanted ignores> <wanted retention> [tolerated ignores]
#   Compares the policy stored in Kopia with what is wanted. Only the target
#   "root" (<mount_root>) carries schedule, one-file-system and compression;
#   the shares inherit that and carry only their differences.
#   KP_DIFF      text lines with the differences
#   KP_ARGS      arguments for "kopia policy set" that make it as wanted
#   KP_MISSING   ignore rules missing in Kopia (Kopia would see more than wanted)
#   KP_CODES     the same differences for other programs: lines "what<US>item<US>have<US>want"
#                what: ignore_missing | ignore_extra | retention | schedule | one_file_system | compression
#   tolerated: rules Kopia may have on top without a difference (2.37: those of folders no longer new, which the
#   run itself takes away right before the upload - NEW_GOING)
#   Returns 0 = matches, 1 = differs
kopia_policy_eval() {
    local target="$1" kind="$2" want_ign="$3" want_ret="$4" tol="${5:-}" cur cur_ign x f want have
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
        if ! grep -Fxq -- "$x" <<<"$want_ign" && ! grep -Fxq -- "$x" <<<"$tol"; then
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
UB_DOCKER_ANSWER="${UB_DOCKER_ANSWER:-20}"     # seconds Docker may take to answer at all (tests: less)
ub_docker_answers()  { timeout -k 5 "$UB_DOCKER_ANSWER" docker version --format '{{.Server.Version}}' >/dev/null 2>&1; }
# 2.66 (B15): every docker call of the engine has a time limit - a Docker that stops answering (dockerd hung) never holds a
# run, the setup or --recover for ever: UB_DOCKER_TIMEOUT for a look or a change (docker stop: plus its own -t), an exec
# UB_DOCKER_EXEC_TIMEOUT; what really takes long - a database's dump, Kopia's upload - runs with UB_DOCKER_LONG=1, as long
# as it takes. `timeout ... docker` calls with their own limit run the binary itself. Ended by the limit: exit 124 (137).
UB_DOCKER_TIMEOUT="${UB_DOCKER_TIMEOUT:-120}"
UB_DOCKER_EXEC_TIMEOUT="${UB_DOCKER_EXEC_TIMEOUT:-900}"
# 2.67: an exec ended by its limit leaves its process running inside the container - the limit ends only docker's own
# client (seen: a Kopia policy change went on for 37 minutes and held the repository for the next step). ub_exec_end ends
# it too; UB_EXEC_TIMED_OUT then holds the limit (seconds) that ended the last exec of this shell, else empty.
# UB_EXEC_LIMIT (as a prefix of one call) gives that exec a limit of its own.
UB_EXEC_END_WAIT="${UB_EXEC_END_WAIT:-5}"     # seconds between TERM and KILL inside the container
UB_EXEC_TIMED_OUT=""
docker() {
    [[ "${UB_DOCKER_LONG:-0}" == "1" ]] && { command docker "$@"; return; }
    local t="$UB_DOCKER_TIMEOUT" t0=$SECONDS rc
    case "${1:-}" in
        exec) t="${UB_EXEC_LIMIT:-$UB_DOCKER_EXEC_TIMEOUT}"; UB_EXEC_TIMED_OUT="" ;;
        stop) is_uint "${DOCKER_STOP_TIMEOUT:-}" && t=$(( t + DOCKER_STOP_TIMEOUT )) ;;
    esac
    timeout -k 10 "$t" docker "$@"; rc=$?
    if [[ "${1:-}" == "exec" ]] && (( (rc == 124 || rc == 137) && SECONDS - t0 >= t )); then
        UB_EXEC_TIMED_OUT="$t"
        ub_exec_end "$(( SECONDS - t0 ))" "${@:2}"
    fi
    return "$rc"
}

# ub_exec_end <seconds> <docker exec's arguments>  -> ends what an exec its limit ended left running inside the container:
# the processes there (docker top - the host's pids) whose command line is exactly the exec's command and that started
# within those seconds - the newest first -, with their children; TERM, after UB_EXEC_END_WAIT seconds KILL. Never another
# process (the Kopia server, an older call of the same command, someone's shell). Said in the log - the program's name
# only, never its arguments (a password) - and only there: never into what a caller reads from the exec.
ub_exec_end() {
    local secs="$1" c="" want pid ppid et args p i
    shift
    while (( $# )); do
        case "$1" in
            -u|--user|-e|--env|-w|--workdir|--env-file|--detach-keys) shift 2 ;;
            -*) shift ;;
            *) c="$1"; shift; break ;;
        esac
    done
    [[ -n "$c" ]] && (( $# )) || return 0
    want="$(printf '%s ' "$@")"; want="${want% }"
    local -a mine=() kids=()
    local -A parent=()
    while read -r pid ppid et args; do
        [[ "$pid" =~ ^[0-9]+$ && "$ppid" =~ ^[0-9]+$ ]] || continue
        parent[$pid]="$ppid"
        [[ "$args" == "$want" && "$et" =~ ^[0-9]+$ ]] && (( et <= secs + 2 )) && mine+=( "$et $pid" )
    done <<<"$(timeout -k 2 10 docker top "$c" -eo pid,ppid,etimes,args 2>/dev/null)"
    (( ${#mine[@]} )) || { log_file "  docker exec in '$c' ended by its limit ($secs s): its process is no longer there"; return 0; }
    mapfile -t mine < <(printf '%s\n' "${mine[@]}" | sort -n | awk '{print $2}')
    kids=( "${mine[@]}" )
    for (( i = 0; i < ${#kids[@]}; i++ )); do          # their children, theirs ... (from the same list)
        for p in "${!parent[@]}"; do
            [[ "${parent[$p]}" == "${kids[$i]}" ]] && ! in_list "$p" "${kids[@]}" && kids+=( "$p" )
        done
    done
    log_file "  docker exec in '$c' ended by its limit ($secs s) - its process inside goes too: ${kids[*]} ($1 ...)"
    kill -TERM "${kids[@]}" 2>/dev/null
    for (( i = 0; i < UB_EXEC_END_WAIT * 2; i++ )); do
        kill -0 "${kids[@]}" 2>/dev/null || return 0
        sleep 0.5
    done
    kill -KILL "${kids[@]}" 2>/dev/null
    log_file "  ... still there after ${UB_EXEC_END_WAIT} s: KILL"
    return 0
}
ub_libvirt_answers() { timeout 20 virsh list --name >/dev/null 2>&1; }
# Unraid's VM service switched off (Settings > VM Manager > Enable VMs: No, domain.cfg SERVICE="disable"): no libvirt
# to answer, no VM to start (2.25). A missing domain.cfg is never taken for off.
ub_vm_service_off() { grep -qE '^SERVICE="?disable"?[[:space:]]*$' "$UB_BOOT/config/domain.cfg" 2>/dev/null; }
# Unraid's Docker service switched off (Settings > Docker > Enable Docker: No, docker.cfg DOCKER_ENABLED="no") or no
# docker at all (2.54): no apps to back up and no Kopia container - a server of shares and VMs, never an error. A missing
# docker.cfg is never taken for off; Docker switched on but silent stays an error where Docker is needed.
ub_docker_service_off() {
    type -P docker >/dev/null 2>&1 || return 0          # the binary (2.66: docker is a function of the engine too)
    grep -qE '^DOCKER_ENABLED="?no"?[[:space:]]*$' "$UB_BOOT/config/docker.cfg" 2>/dev/null
}

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
    local list="" stay="" level="warning" why="An earlier run was aborted" whyk=why_aborted wt fin f
    local -a back_ct=() back_nc=() back_vm=()     # what came back, for the notification (the helpers add to them)
    if array_stopping && recover_notes; then
        log "The array is being stopped - what an earlier run left stopped stays so (state/stopped, maintenance, vms) until a run after the array start"
        return 0
    fi
    recover_notes || return 0
    if [[ "$(jq -r '"\(.result)|\(.message)"' "$UB_STATE/last-run.json" 2>/dev/null)" == "aborted|array_stopping" ]]; then
        fin="$(jq -r '.finished // 0' "$UB_STATE/last-run.json" 2>/dev/null)"; is_uint "$fin" || fin=0
        level="normal"; why="The run the array stop ended left things for after the array start"; whyk=why_array_stop
        for f in stopped maintenance vms; do
            [[ -e "$UB_STATE/$f" ]] && (( $(stat -c %Y "$UB_STATE/$f" 2>/dev/null || echo 0) > fin + 2 )) && { level="warning"; why="An earlier run was aborted"; whyk=why_aborted; }
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
        wt="$(ub_say "notify.$whyk")" || wt="$why"
        ub_tell recovered_part warning "$(recover_report "$why" "$stay")" "Aborted run not fully repaired" \
            "$why - not all of it is back; what is still noted, the next run tries again." "why=$wt"
    elif [[ -n "$list" ]]; then
        if [[ "$level" == "warning" ]]; then warn "$why - restored: $list"; else log "$why - restored: $list"; fi
        wt="$(ub_say "notify.$whyk")" || wt="$why"
        ub_tell recovered "$level" "$(recover_report "$why" "")" "Aborted run repaired" "$why - all of it is back." "why=$wt"
    fi
    return 0
}

# recover_report <why> <stay>  - the notification's long text: what came back, what didn't (one item per line)
recover_report() {
    local l
    echo "$1."
    if (( ${#back_ct[@]} + ${#back_nc[@]} + ${#back_vm[@]} )); then
        echo; echo "STARTED AGAIN OR RESET"
        (( ${#back_ct[@]} )) && wrap_words "  Containers started: " ", " "${back_ct[@]}"
        (( ${#back_nc[@]} )) && wrap_words "  Maintenance mode off: " ", " "${back_nc[@]}"
        for l in "${back_vm[@]}"; do echo "  $l"; done
    fi
    if [[ -n "$2" ]]; then
        echo; echo "NOT BROUGHT BACK"
        while IFS= read -r l; do [[ -n "$l" ]] && echo "  $l"; done <<<"${2//; /$'\n'}"
        echo; echo "What is still noted, the next run tries again."
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
                false) if docker start "$n" >/dev/null 2>>"${LOG_FILE:-/dev/null}"; then started+=( "$n" ); list+="$n "; back_ct+=( "$n" )
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

# --- Nextcloud's data folder as the run found it (2.65) -------------------------------------------------------------
# state/nc-datadir: one line "<container>\t<folder>\t<uid>\t<gid>\t<mode>" per Nextcloud a run put into maintenance mode -
# its data folder's root on the pool or disk, as it was right before (closed: occ answered). Unraid's array start (every
# boot too), like Add Share or Apply of any share, resets a share root that isn't of the group users to 0777
# nobody:users; Nextcloud no longer owns it then, can't close it itself and refuses every occ call - so after an array
# stop or a power cut during a run its maintenance mode could never be switched off. nc_reclose <container> puts that
# root back exactly as noted - owner, group, mode, only the folder itself, never what is inside - when occ refused for
# it, the note was closed and the folder is open now. Nothing looser than the owner had: it is their own setting.
# Written with state/maintenance (save_restore_state), never older than it; an older engine leaves it alone.
nc_reclose() {
    local n="$1" c p u g m nu ng nm
    [[ -s "$UB_STATE/nc-datadir" && ! "$UB_STATE/nc-datadir" -ot "$UB_STATE/maintenance" ]] || return 1
    while IFS=$'\t' read -r c p u g m; do
        [[ "$c" == "$n" ]] || continue
        is_uint "$u" && is_uint "$g" && [[ "$m" =~ ^[0-7]{3,4}$ && "$p" == /* && "$p" != *'/../'* && "$p" != */.. ]] || return 1
        (( 8#$m & 8#007 )) && return 1                          # noted open: never what makes it work
        [[ -d "$p" && ! -L "$p" ]] || return 1
        read -r nu ng nm <<<"$(stat -c '%u %g %a' -- "$p" 2>/dev/null)"
        [[ "$nm" =~ ^[0-7]{3,4}$ ]] && (( 8#$nm & 8#007 )) || return 1   # closed already: occ refuses for something else
        if chown -h "$u:$g" -- "$p" 2>>"${LOG_FILE:-/dev/null}" && chmod "$m" -- "$p" 2>>"${LOG_FILE:-/dev/null}"; then
            log "  Nextcloud '$n': its data folder $p was open ($nm $nu:$ng - Unraid resets a share root that isn't of the group users so at the array start) - closed again as the run found it ($m $u:$g), only the folder itself. Lasting fix (Unraid then leaves it alone): chown $u:users '$p' && chmod 0770 '$p'"
            return 0
        fi
        err "Nextcloud '$n': its data folder $p could not be closed again ($m $u:$g)"
        return 1
    done <"$UB_STATE/nc-datadir"
    return 1
}
# nc_dirs_keep <container...>: state/nc-datadir keeps only the lines of these (none left - the file goes)
nc_dirs_keep() {
    local -a lines=()
    local l
    [[ -e "$UB_STATE/nc-datadir" ]] || return 0
    while IFS= read -r l; do in_list "${l%%$'\t'*}" "$@" && lines+=( "$l" ); done <"$UB_STATE/nc-datadir"
    note_write "$UB_STATE/nc-datadir" "${lines[@]}"
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
            out="$(docker exec -u "$u" "$n" php "$occ" status 2>&1)" && break
            grep -q "readable by other people" <<<"$out" && break      # stays so until the folder is closed (2.65)
            array_stopping && break
            sleep 2
        done
        if out="$(docker exec -u "$u" "$n" php "$occ" maintenance:mode --off 2>&1)"; then
            list+="maintenance mode $n off "; back_nc+=( "$n" )
        # 2.65: the array start opened its data folder (Unraid: a share root not of the group users) - closed again as the
        # run found it, then switched off; up to 2.64 Nextcloud stayed in maintenance mode, its note kept for ever
        elif grep -q "readable by other people" <<<"$out" && nc_reclose "$n" \
             && out="$(docker exec -u "$u" "$n" php "$occ" maintenance:mode --off 2>&1)"; then
            list+="maintenance mode $n off (its data folder closed again) "; back_nc+=( "$n (its data folder closed again)" )
        else
            while IFS= read -r f; do [[ -n "${f//[[:space:]]/}" ]] && log "    occ: ${f%$'\r'}"; done < <(head -20 <<<"$out")
            err "Maintenance mode of '$n' could not be switched off - it stays noted (state/maintenance)"
            keep+=( "$n" )
        fi
    done
    (( ${#keep[@]} )) && stay+="${stay:+; }maintenance mode still on (still noted): ${keep[*]}"
    note_write "$UB_STATE/maintenance" "${keep[@]}"
    nc_dirs_keep "${keep[@]}"
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
                      if timeout 30 virsh domfsthaw "$n" >/dev/null 2>&1; then list+="VM $n thawed "; back_vm+=( "VM $n thawed" )
                      else err "VM '$n' (from the aborted run) could not be thawed - it stays noted (state/vms)"; keep+=( "$n|$how" ); fi ;;
            paused)   [[ "$st" == "paused" ]] || continue
                      if timeout 30 virsh resume "$n" >/dev/null 2>&1; then list+="VM $n resumed "; back_vm+=( "VM $n resumed" )
                      else err "VM '$n' (from the aborted run) does not resume - it stays noted (state/vms)"; keep+=( "$n|$how" ); fi ;;
            shutdown) [[ "$st" == "shut off" ]] || continue
                      if timeout 60 virsh start "$n" >/dev/null 2>&1; then list+="VM $n started "; back_vm+=( "VM $n started" )
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
#   DRIFT_CODE   the same index: "code<US>value" for messages the office translates (since 2.18; since 2.41
#                every message has a code; value only for the codes from before 2.41, "" for the others)
#   DRIFT_PARAMS the same index (since 2.41): the sentence's parameters for the office, "key=value" joined by <US>
#                (drift.json "params": {key: value}); the text stays English - the log, drift.txt, the notification
#   SKIP_KOPIA[share]=reason   shares Kopia must not back up this time
declare -ga DRIFT=() DRIFT_CODE=() DRIFT_PARAMS=()
declare -gA SKIP_KOPIA=()
# drift_add <level> <text> <code> [value] [key=value]...
#   every call names a code (tests/run.php testDriftCodes checks it, and that the office has words for it)
drift_add() {
    local p="" kv
    for kv in "${@:5}"; do p+="${p:+$'\x1f'}$kv"; done
    DRIFT+=( "$1|$2" ); DRIFT_CODE+=( "${3:-}"$'\x1f'"${4:-}" ); DRIFT_PARAMS+=( "$p" )
}

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
            drift_add warn "Share '$o' was renamed to '$s' (same id ${INV_ID[$s]%%:*}) - '$s' is only backed up after setup.sh" \
                share_renamed "" name="$s" old="$o"
            continue
        fi
        matches_any "$s" "${DRIFT_IGNORE[@]}" && continue
        drift_add warn "New share '$s' ($(inv_locnames "$s")${INV_GB[$s]:+, ${INV_GB[$s]} GB}) - not in settings.ini, not backed up" \
            share_new "" name="$s" locations="$(inv_locnames "$s")" bytes="${INV_BYTES[$s]:-}"
    done

    # Deleted or empty
    for s in "${!cfg_has[@]}"; do
        [[ -n "${renamed[$s]:-}" ]] && continue
        mode="$(share_mode "$s")"
        if ! inv_has_share "$s"; then
            # neither data nor Unraid config
            if [[ "$mode" == "off" ]]; then
                drift_add info "Share '$s' no longer exists (was set to off)" share_gone_off "" name="$s"
            else
                drift_add warn "Share '$s' no longer exists (mode=$mode)" share_gone "" name="$s" mode="$mode"
            fi
        elif [[ "${INV_METHOD[$s]}" == "none" && "$mode" != "off" ]]; then
            # only left in Unraid's config. If it was empty at setup already (no
            # id), that is nothing new - otherwise the data is gone.
            if [[ -n "$(cfg "share|$s|id")" ]]; then
                drift_add warn "Share '$s' has no data any more (so far on $(cfg "share|$s|locations" "?"), mode=$mode)" \
                    share_empty "" name="$s" locations="$(cfg "share|$s|locations" "?")" mode="$mode"
            fi
        elif [[ "${INV_METHOD[$s]}" != "none" && "$mode" != "off" && -z "$(cfg "share|$s|id")" ]]; then
            drift_add info "Share '$s' has data now and is backed up - setup.sh then remembers its id (to spot renames)" \
                share_has_data "" name="$s"
        fi
    done

    for s in "${INV_SHARES[@]}"; do
        [[ "${INV_METHOD[$s]}" == "none" || -z "${cfg_has[$s]:-}" ]] && continue
        share_name_ok "$s" || { drift_add warn "Share '$s': the name contains @ : \" or | - not backed up" share_name_bad "" name="$s"; continue; }
        mode="$(share_mode "$s")"
        [[ "$mode" == "off" ]] && continue
        # 2.69: Unraid deleted it, its dataset stays for its snapshots - left out (plan_build), never destroyed here
        if share_deleted_left "$s"; then
            drift_add warn "Share '$s' was deleted in Unraid, but its dataset ($SDL_DS) stays: $SDL_SNAPS snapshot(s) keep it - not backed up any more; delete its snapshots (Ms. Snapshotini), then delete the share in Unraid again" \
                share_deleted_snapshots "" name="$s" datasets="$SDL_DS" snapshots="$SDL_SNAPS"
            continue
        fi
        meth="$(share_method "$s")"
        loc_now="$(inv_locnames "$s")"; loc_cfg="$(cfg "share|$s|locations")"
        if [[ -n "$loc_cfg" && "$loc_cfg" != "$loc_now" ]]; then
            drift_add info "Share '$s' now lies on: $loc_now (settings.ini says: $loc_cfg) - taken along automatically" \
                share_moved "" name="$s" now="$loc_now" was="$loc_cfg"
        fi
        if [[ "$meth" == "live" && "$(cfg "share|$s|method" auto)" != "live" ]]; then
            drift_add warn "Share '$s' cannot be backed up with a snapshot ($(printf '%s' "${INV_NOTE[$s]}" | head -1)) - Kopia reads it live" \
                share_live "" name="$s" note="$(printf '%s' "${INV_NOTE[$s]}" | head -1)"
        fi
        if [[ "$mode" == "snapshot" && "$meth" == "live" ]]; then
            drift_add warn "Share '$s' is set to mode=snapshot but lies on a file system without snapshots" share_snapshot_no_fs "" name="$s"
        fi
        if [[ "${INV_LAYOUT[$s]}" == "split" ]]; then
            drift_add info "Share '$s' lies on several bases and has child datasets - Kopia sees one subfolder per base" share_split "" name="$s"
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
                    new_database "$n" name="$n" type="$t" image="$img" stack="${CT_PROJECT[$n]:-}"
            elif is_nextcloud_image "$img" && ! cfg_has "nextcloud|$n"; then
                drift_add warn "New Nextcloud container '$n' - keeps running, no maintenance mode set up until you decide" new_nextcloud "$n" name="$n"
            else
                drift_add info "New container '$n' ($img) - keeps running during the backup until you decide" new_container "$n" \
                    name="$n" image="$img"
            fi
        fi
        if [[ -n "${CT_VOLUMES[$n]}" && -z "${known[$n]:-}" ]]; then
            drift_add warn "Container '$n' uses Docker volumes ($(cut -d'|' -f1 <<<"${CT_VOLUMES[$n]}" | paste -sd, -)) - they live in the Docker image and are NOT in the backup" \
                ct_volumes "" name="$n" volumes="$(cut -d'|' -f1 <<<"${CT_VOLUMES[$n]}" | paste -sd, - | sed 's/,/, /g')"
        fi
    done
    # 2.75: Docker switched off - no container is listed, none is gone (nobody knows)
    [[ "${DOCKER_SERVICE:-yes}" == "no" ]] && return 0
    for n in $(cfg_names dump) $(cfg_names nextcloud) "${DOCKER_NO_STOP[@]}" "${DOCKER_SKIP[@]}"; do
        [[ -n "${referenced[$n]:-}" ]] && continue
        referenced[$n]=1
        in_list "$n" "${CT_NAMES[@]}" || drift_add warn "Container '$n' is in settings.ini but does not exist" ct_gone "" name="$n"
    done
}

# vm_live_kopia <name> -> the shares (", ") of its disks on a file system without snapshots, when Kopia is on and every one
#   of them goes offsite with Kopia (read live, without a snapshot); 1 when one of them doesn't (2.65)
vm_live_kopia() {
    local target src b fs ds share out=""
    [[ "${KOPIA_ENABLED,,}" =~ ^(yes|ja|1|true)$ ]] || return 1
    while IFS='|' read -r target src b fs ds share; do
        [[ -z "$target" || "$fs" == zfs || "$fs" == btrfs ]] && continue
        [[ -n "$share" && "$(share_mode "$share")" == kopia ]] || return 1
        [[ ", $out, " == *", $share, "* ]] || out+="${out:+, }$share"
    done <<<"${VM_DISKS[$1]}"
    [[ -n "$out" ]] && printf '%s' "$out"
}

drift_check_vms() {
    local n sh
    [[ "$VM_SERVICE" == "yes" ]] || return 0
    for n in "${VM_NAMES[@]}"; do
        if ! cfg_has "vm|$n"; then
            # 2.65: «in the snapshot of their share» only where a snapshot can hold its disks (ZFS, btrfs) - up to 2.64 also
            # said of a disk on xfs; a disk without a snapshot is said as it is, as for a VM set up (vm_disk_live …)
            if [[ "${VM_SNAP[$n]}" == "yes" ]]; then
                drift_add info "New VM '$n' - not set up yet: its disks are in the snapshot of their share, but it is not prepared for it (keeps running) until you decide" \
                    new_vm "$n" name="$n"
                continue
            fi
            drift_add info "New VM '$n' - not set up yet: it keeps running until you decide" new_vm_unheld "" name="$n"
        elif [[ "$(vm_mode "$n")" == "off" ]]; then
            [[ -n "${VM_OWN_DS[$n]}" ]] || drift_add info "VM '$n' is set to off, but its disks share a dataset - they are in the share's snapshot anyway" \
                vm_off_shared "" name="$n"
            continue
        fi
        case "${VM_SNAP[$n]}" in
            block)   drift_add warn "VM '$n' uses a whole device as a disk - no snapshot holds it" vm_whole_device "" name="$n" ;;
            live)    if sh="$(vm_live_kopia "$n")"; then
                         drift_add warn "VM '$n' has a disk on a file system without snapshots - no snapshot holds it, only Kopia's copy of its share ($sh), read while the VM runs" \
                             vm_disk_live_kopia "" name="$n" share="$sh"
                     else
                         drift_add warn "VM '$n' has a disk on a file system without snapshots - no snapshot holds it, nothing backs it up" vm_disk_live "" name="$n"
                     fi ;;
            missing) drift_add warn "VM '$n': a disk file was not found" vm_disk_missing "" name="$n" ;;
        esac
    done
    while IFS= read -r n; do
        [[ -n "$n" ]] && ! in_list "$n" "${VM_NAMES[@]}" && drift_add info "VM '$n' is in settings.ini but no longer exists" vm_gone "" name="$n"
    done < <(cfg_names vm)
}

# Kopia check for the run; sets KOPIA_OK and SKIP_KOPIA
#   KP_STATUS    one JSON object per Kopia target (for drift.json): kind, share, path,
#                ok, skipped (the run leaves the share out), differences as codes
#   KP_CHECKED   yes once the policies were compared (Kopia reachable)
#   KOPIA_OK     yes | no | off | none | skip (--no-kopia) | docker_off (2.75: Docker switched off - Kopia can't be
#                asked, nothing goes offsite); "" until checked (2.24: a run ended before its
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
    is_yes "$KOPIA_ENABLED" || { KOPIA_OK="off"; kopia_reach_save ok; return 0; }     # 2.66: switched off - nothing to reach
    [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" && ${#PLAN_KITEMS[@]} -eq 0 ]] && { KOPIA_OK="none"; return 0; }
    if [[ -z "$KOPIA_CONTAINER" ]]; then
        drift_add error "No Kopia container in settings.ini" kopia_no_container; return 1
    fi
    # 2.75: Docker switched off by the user (Settings > Docker, ub_docker_service_off) is no container gone and no drift
    # from settings.ini: nothing can be asked of Kopia, which runs in a container - KOPIA_OK docker_off, the run says it
    # once as a warning (docker_off_offsite) and does its local part. Up to 2.74: «Kopia container '<name>' does not
    # exist» (kopia_container_gone), an error, and a repository «no» the Team Lead's check took for a broken one
    if [[ "${DOCKER_SERVICE:-yes}" == "no" ]]; then
        KOPIA_OK="docker_off"; return 0
    fi
    if ! in_list "$KOPIA_CONTAINER" "${CT_NAMES[@]}"; then
        drift_add error "Kopia container '$KOPIA_CONTAINER' does not exist" kopia_container_gone "" name="$KOPIA_CONTAINER"; return 1
    fi
    kopia_mounts_load
    if ! k_map "$MOUNT_ROOT"; then
        drift_add error "The Kopia container does not map $MOUNT_ROOT (expected: $MOUNT_ROOT -> $MOUNT_ROOT, Read Only - Slave)" \
            kopia_no_mount "" path="$MOUNT_ROOT"; return 1
    fi
    case "${KM_PROP[$KMAP_IDX]}" in
        slave|rslave|shared|rshared) ;;
        *) drift_add error "Mapping ${KM_SRC[$KMAP_IDX]} in the Kopia container has propagation '${KM_PROP[$KMAP_IDX]:-rprivate}' - new snapshot mounts stay invisible. Set the access mode to 'Read Only - Slave'" \
               kopia_propagation "" path="${KM_SRC[$KMAP_IDX]}" propagation="${KM_PROP[$KMAP_IDX]:-rprivate}"
           return 1 ;;
    esac
    [[ "${KM_RW[$KMAP_IDX]}" == "true" ]] && \
        drift_add warn "Mapping ${KM_SRC[$KMAP_IDX]} in the Kopia container is writable - 'Read Only - Slave' recommended" \
            kopia_mount_writable "" path="${KM_SRC[$KMAP_IDX]}"
    if ! kopia_status_load; then
        if [[ "$KOPIA_RUNNING" != "yes" ]]; then drift_add error "Kopia container '$KOPIA_CONTAINER' is not running" kopia_not_running "" name="$KOPIA_CONTAINER"
        elif [[ "$KOPIA_UNREACHABLE" == "yes" ]]; then
            log "  Kopia: $KOPIA_REACH_WHY"          # its own words (an address, a bucket): the log only, never drift.json
            drift_add error "Kopia cannot reach its repository (offsite)" kopia_unreachable "" name="$KOPIA_CONTAINER"
            kopia_reach_save unreachable
        else drift_add error "Kopia is not connected to a repository" kopia_not_connected "" name="$KOPIA_CONTAINER"; fi
        return 1
    fi
    kopia_reach_save ok
    local uid_ok="yes"
    if [[ "$KOPIA_SERVER_UID" != "0" ]]; then
        drift_add error "The Kopia server runs as UID $KOPIA_SERVER_UID inside the container, snapshots need root (to read every file). A run as root would make the shared cache unreadable for the server - Kopia is NOT started. Fix: set PUID=0 and PGID=0 in the template" \
            kopia_uid "" uid="$KOPIA_SERVER_UID" name="$KOPIA_CONTAINER"
        uid_ok="no"
    fi
    if [[ -n "$KOPIA_IDENTITY_CFG" && "$KOPIA_IDENTITY_CFG" != "$KOPIA_ID" ]]; then
        drift_add warn "The Kopia identity is now $KOPIA_ID (settings.ini: $KOPIA_IDENTITY_CFG) - new sources instead of continuing the old ones" \
            kopia_identity "" now="$KOPIA_ID" was="$KOPIA_IDENTITY_CFG"
    fi
    kopia_policies_load
    KP_CHECKED="yes"
    local kind hpath share cpath
    while IFS='|' read -r kind hpath share; do
        [[ -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" || continue
        if kopia_policy_eval "$cpath" "$kind" "$(kopia_want_ignores "$kind" "$share")" "$(kopia_want_retention "$kind" "$share")" \
                "$([[ "$kind" == "share" ]] && printf '%s' "${NEW_GOING[$share]:-}")"; then
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
                drift_add error "The Kopia policy for $cpath lacks ignore rules ($miss_own) - $what is NOT given to Kopia until 'setup.sh --kopia' has run" \
                    kopia_ignores_missing "" path="$cpath" kind="$kind" name="$share" rules="$(printf '%s' "${miss_own% }" | sed 's/ /, /g')"
                SKIP_KOPIA[$key]="policy incomplete"
                kp_status_add "$kind" "$share" "$cpath" 0 1
            else
                drift_add warn "The Kopia policy for $cpath differs from settings.ini ('setup.sh --kopia' aligns it):"$'\n'"${KP_DIFF%$'\n'}" \
                    kopia_policy_differs "" path="$cpath" kind="$kind" name="$share"
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
                && drift_add info "VM '$n' has a Kopia source of its own in settings.ini but no longer exists - what is left of it still goes there" \
                    vm_source_orphan "" name="$n"
            [[ "$(vm_mode "$n")" == "off" ]] \
                && drift_add warn "VM '$n' is left out of the snapshots (mode = off) but has a Kopia source of its own - only its package goes there" \
                    vm_source_off "" name="$n"
        else
            found=0
            for c in "${CT_NAMES[@]}"; do
                [[ "${CT_PROJECT[$c]:-}" == "$n" || ( -z "${CT_PROJECT[$c]:-}" && "$c" == "$n" ) ]] && { found=1; break; }
            done
            # 2.75: with Docker switched off no container is listed - none is gone (nobody knows)
            [[ "${DOCKER_SERVICE:-yes}" == "no" ]] && found=1
            (( found )) || drift_add info "App '$n' has a Kopia source of its own in settings.ini but no container of it exists any more - what is left of it still goes there" \
                app_source_orphan "" name="$n"
        fi
        while IFS='|' read -r sh rel; do
            [[ -z "$sh" ]] && continue
            in_list "$sh" "${PLAN_MOUNT[@]}" && continue
            drift_add warn "The Kopia source of $t '$n': '$sh/$rel' lies in share '$sh', which takes no snapshot (mode=$(share_mode "$sh")) - that part is left out" \
                source_part_unsnapped "" kind="$t" name="$n" path="$sh/$rel" share="$sh" mode="$(share_mode "$sh")"
        done < <(kopia_item_parts "$t" "$n")
    done
    return 0
}

drift_check_settings() {
    local e
    for e in "${CFG_ERRORS[@]}"; do drift_add error "settings.ini: $e" settings_error "" detail="$e"; done
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
#                    whether its policy matches settings.ini ("policies"); since 2.41 every item has a code
#                    and "params" (the sentence's parts) - the office says each in its own words
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
#   since 2.70 "order": {code, seconds} | null - a scheduled run waited before it started (the office says so in
#   UB_ORDER, order_load): waited_night (for the office's mover run, Ms. Moverelli's, until it was done)
# Writing is never critical: if it fails, the backup carries on.
UB_INTERFACE=1
UB_HISTORY_MAX=200

ST_ACTIVE="no"            # only once the lock is held - otherwise a second start
ST_MODE=""                # that was turned away would overwrite the running one's status
ST_PHASE=""
ST_RESULT="running"       # running | ok | warnings | errors | failed | aborted
ST_MESSAGE=""
ST_MESSAGE_PARAMS=""      # since 2.65: the coded message's params, "\x1fkey=value..." (die_code) - status.json message_params
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
ST_BTRFS=()               # since 2.63: per btrfs disk or pool the retention looked at, "base|asleep" or
                          #   "base|ok|keep_days|floor_gb|free_gb|kept|oldest|released|none_left(1/0)|brake_last|held(1/0)"
ST_BTRFS_LOOKED=""        #   (backup.sh prune_btrfs) - status.json "btrfs", null until the retention ran
ST_ORDER_CODE=""          # since 2.70: the office's order (order_load) - status.json "order"
ST_ORDER_SECONDS=0

# order_load - the office's order (2.70): UB_ORDER "waited_night:<seconds>" exactly in that shape (the office's
# agent/lib/nightorder.php: the scheduled run waited for Ms. Moverelli's mover run), else nothing
order_load() {
    ST_ORDER_CODE=""; ST_ORDER_SECONDS=0
    [[ "${UB_ORDER:-}" =~ ^(waited_night):([0-9]{1,6})$ ]] || return 0
    ST_ORDER_CODE="${BASH_REMATCH[1]}"; ST_ORDER_SECONDS=$(( 10#${BASH_REMATCH[2]} ))
}

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
    local witems
    witems="$(printf '%s\n' "${WARN_ITEMS[@]}" | jq -R 'select(length > 0) | split("\u001f")
        | {code: .[0], params: ([.[1:][] | capture("^(?<k>[^=]+)=(?<v>.*)$"; "s") | {(.k): .v}] | add // {})}' | jq -sc .)" || witems='[]'
    [[ -n "$why" ]] || why='{}'
    local mparams
    mparams="$(printf '%s' "$ST_MESSAGE_PARAMS" | jq -Rsc 'split("\u001f") | [.[] | select(length > 0) | capture("^(?<k>[^=]+)=(?<v>.*)$"; "s") | {(.k): .v}] | add // {}')" \
        || mparams='{}'
    [[ -n "$mparams" ]] || mparams='{}'
    local btrfs="null"
    if [[ -n "$ST_BTRFS_LOOKED" ]]; then
        btrfs="$(printf '%s\n' "${ST_BTRFS[@]}" | jq -R 'select(length > 0) | split("|")
            | if .[1] == "asleep" then {base: .[0], asleep: true}
              else {base: .[0], asleep: false, keep_days: (.[2] | tonumber), floor_gb: (.[3] | tonumber), free_gb: (.[4] | tonumber),
                    kept: (.[5] | tonumber), oldest: .[6], released: (.[7] | tonumber), none_left: (.[8] == "1"), brake_last: .[9],
                    held: (.[10] == "1")} end' | jq -sc .)" || btrfs="null"
        [[ -n "$btrfs" ]] || btrfs="null"
    fi
    jq -nc \
        --arg name "$UB_NAME" --arg version "$UB_VERSION" --argjson interface "$UB_INTERFACE" \
        --arg mode "$ST_MODE" --arg run "${TS:-}" --argjson pid "$$" \
        --argjson started "$ST_STARTED" --argjson updated "$(date +%s)" --argjson finished "$ST_FINISHED" \
        --arg phase "$ST_PHASE" --arg result "$ST_RESULT" --arg message "$ST_MESSAGE" \
        --argjson errors "${ERRORS:-0}" --argjson warnings "${WARNINGS:-0}" --argjson warning_items "${witems:-[]}" \
        --argjson downtime "${DOWNTIME:-0}" --arg snapshot "${SNAP_NAME:-}" \
        --argjson dump_bytes "${ST_DUMP_BYTES:-0}" --arg log "$(basename "${LOG_FILE:-}")" \
        --argjson drift "$drift" --argjson planned "$plan" --argjson done "$done_" \
        --arg kopia_enabled "${KOPIA_ENABLED:-}" --arg kopia_ok "${KOPIA_OK:-}" \
        --arg current "$ST_KOPIA_CUR" --argjson current_since "$ST_KOPIA_CUR_T" --argjson vms "${vms:-[]}" \
        --argjson skipped "${skipped:-[]}" --arg interrupted "$ST_KOPIA_INTERRUPTED" \
        --argjson packages "${ST_PACKAGES:-null}" --argjson new_local "${ST_NEW_LOCAL:-null}" \
        --argjson partner "${partner:-null}" --argjson p_ok "${#ST_PARTNER_DONE[@]}" --argjson p_failed "${#ST_PARTNER_FAILED[@]}" \
        --argjson p_skipped "${#ST_PARTNER_SKIPPED[@]}" --argjson asleep "${asleep:-null}" --argjson skipped_why "$why" \
        --arg skipk "${SKIPK:-0}" --argjson btrfs "$btrfs" --argjson message_params "$mparams" \
        --arg order "$ST_ORDER_CODE" --argjson order_s "${ST_ORDER_SECONDS:-0}" \
        '{interface: $interface, name: $name, version: $version, mode: $mode, run: $run, pid: $pid, skip_kopia: ($skipk == "1"),
          started: $started, updated: $updated, finished: $finished, phase: $phase, result: $result,
          message: $message, message_params: $message_params, errors: $errors, warnings: $warnings, warning_items: $warning_items, downtime_s: $downtime,
          snapshot: $snapshot, dump_bytes: $dump_bytes, log: $log, drift: $drift, vms: $vms, packages: $packages,
          new_local: $new_local, partner: $partner, partner_ok: $p_ok, partner_failed: $p_failed, partner_skipped: $p_skipped,
          asleep: $asleep, btrfs: $btrfs,
          order: (if $order == "" then null else {code: $order, seconds: $order_s} end),
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
    ST_PHASE="done"; ST_KOPIA_CUR=""; ST_PARTNER_CUR=""; ST_PARTNER_STREAMS=()
    status_write
    [[ "$ST_MODE" == "backup" ]] || return 0
    cp -f "$UB_STATE/status.json" "$UB_STATE/.last-run.json.$$" 2>/dev/null \
        && mv -f "$UB_STATE/.last-run.json.$$" "$UB_STATE/last-run.json" 2>/dev/null
    # a short line per run: the packages only as counts
    history_append "$(jq -c 'if (.packages | type) == "object" then .packages |= del(.list) else . end
        | if (.partner | type) == "object" then .partner |= del(.planned, .current, .streams) else . end' "$UB_STATE/status.json" 2>/dev/null \
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
    kopia_silent && rm -f "$(kopia_silent_file)"        # 2.67: this process's own mark only
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

# drift.json: {time, items: [{level, text, code, value, params}], policies: null (not compared) | [KP_STATUS ...]}
#   code/value: since 2.18, for the messages the office translates; since 2.41 every item has a code, value only
#   the codes from before 2.41 ("" for the others), and params - the sentence's parts as strings ({} for none)
drift_json_write() {
    local tmp="$UB_STATE/.drift.json.$$" pol="null" i
    local -a par=()
    [[ "$KP_CHECKED" == "yes" ]] && { pol="$(printf '%s\n' "${KP_STATUS[@]}" | jq -sc .)" || pol="null"; }
    for i in "${!DRIFT[@]}"; do par+=( "${DRIFT_PARAMS[$i]:-}" ); done
    # texts and params go in as arguments (a text or a value may hold a newline), the level and code as lines
    if for i in "${!DRIFT[@]}"; do printf '%s\x1f%s\n' "${DRIFT[$i]%%|*}" "${DRIFT_CODE[$i]:-$'\x1f'}"; done \
            | jq -R 'select(length > 0) | split("\u001f") | {level: .[0], code: (.[1] // ""), value: (.[2] // "")}' \
            | jq -sc --argjson t "$(date +%s)" --argjson p "${pol:-null}" --argjson n "${#DRIFT[@]}" --args \
              '[., ($ARGS.positional[:$n] | map(index("|") as $i | .[$i + 1:])),
                   ($ARGS.positional[$n:] | map(split("\u001f") | map(select(index("=") != null) | index("=") as $i
                                                | {key: .[:$i], value: .[$i + 1:]}) | from_entries))]
               | transpose | map(.[0] + {text: .[1], params: (.[2] // {})})
               | {time: $t, items: ., policies: $p}' "${DRIFT[@]}" "${par[@]}" >"$tmp" 2>/dev/null; then
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
# 1.6 TB vdisk holding 21 GB is 1.6 TB of reading at its first upload (a Windows VM with a 1.6 TB sparse disk, 2026-10-07:
# 382 GB by its snapshot, 2 TB read in 2.7 h); up to 2.25 the allocated blocks, VM_BYTES, put such a VM far too
# early) - the LARGER of the two when both are known, either alone when only one is. A complete
# snapshot alone can be stale: a share whose folders were all ignored until the setup changed has a tiny complete
# snapshot while its first real upload of terabytes is still going on in checkpoints (a large server's Backups,
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
#   - a new top-level folder in a share that goes to Kopia (2.37, 2026-10-10) - decided per FOLDER by
#     where it lies, never because an app binds a share:
#       * in a DATA share - every share but the two below, also one a container binds whole or in big parts
#         as its data (Emby's films, Nextcloud's data, Immich's uploads) - it simply goes with its share:
#         the share is offsite, so is the folder (an offsite restore of the share is complete). Only the
#         user's kopia_ignore (and the global [kopia] ignore) leave a folder out. Nothing is recorded.
#       * in an APP/VM SHARE - the share Unraid itself names as the place for app configs (docker.cfg
#         DOCKER_APP_CONFIG_PATH) or for VMs (domain.cfg DOMAINDIR), when that path is the share itself
#         (/mnt/<user|pool>/<share>[/]; a path deeper inside a share keeps the apps one level down, so no new
#         top folder of that share is an app's), never by the names appdata/domains; docker.cfg or
#         domain.cfg unreadable: no app/VM share, every folder goes with its share (ub_app_shares_load) -
#         a top folder belongs to an app or VM when a container's bind (docker inspect: the app is its
#         Compose project, else the container) or a VM's disk lies in it (top_owners_load). Such a folder
#         follows its app/VM: one that goes offsite ([app|vm "<name>"] kopia = yes, or - settings from
#         before 2.19 - another folder of it recorded in kopia_known of a share going to Kopia) takes it
#         along. (2.38, 2026-10-10) One that belongs only to EXISTING apps/VMs set only local (an app with a
#         container in [docker] known, a VM with a [vm "<name>"] section - none going offsite) is DECIDED: it
#         stays local with them, silently - not new, no state/new-local.json entry, no notification, no drift.
#         The share still goes to Kopia, so the run leaves it out exactly like a new folder (KEPT_RULES: rules
#         right before the upload, part of the wanted policy so the comparison stays quiet; noted in
#         state/kept-local.json and taken away once the folder is gone or its app/VM goes offsite - settings.ini
#         is never written by a run). Any other (a new app/VM - the office proposes the user's default,
#         preset_new -, or no app/VM at all) leaves it NEW, as before 2.37. An app/VM share that is itself only
#         local sends nothing offsite until the setup is applied - unchanged:
#     The app/VM shares that go to Kopia record their top-level folders when the setup is applied:
#       [share "<name>"] kopia_known = /<folder>/   (repeatable; an empty "kopia_known =" means recorded,
#                                                    none yet)
#     A share with more than UB_KNOWN_MAX folders at its top when it is first recorded is a collection
#     - a new folder there is the collection growing, not a new thing: kopia_known = * - every folder
#     goes, new ones too, as before 2.21; listing folders instead makes new ones wait there too.
#     A top-level folder there that is neither known nor left out - by the share's kopia_ignore, the global
#     [kopia] ignore, as a part of an app or VM with a Kopia source of its own (section 9), as the backup
#     place's own folder, or as the folder of an app/VM that goes offsite - is NEW: the run leaves it out of
#     the share's Kopia source (rules it adds to the share's policy right before the upload and takes away
#     again once the folder is decided or gone); the local snapshot holds it all the same. An app/VM share
#     without any kopia_known line works as before 2.21 (every folder goes) until the setup is applied once.
#     The setup shows the new folders ("waiting for your decision"): only local = kopia_ignore, local + Kopia
#     = kopia_known. Data shares keep kopia_known lines written up to 2.36 as they are - read, never acted on.
#   - an app's or VM's own Kopia source holds only its folder = lines and its package: nothing new
#     reaches it without the setup.
# The run says so: a log line, status.json "new_local", drift info new_waiting, state/new-local.json, and
# one notification (normal) for the folders it sees for the first time - not every night:
#   state/new-local.json  {interface, version, run, time, folders: [{share, folder, bytes, first_seen, rules}]}
#                         bytes: only where it is cheap (a ZFS dataset of its own), else null; rules: the
#                         ignore rules the run set for it. Written by real backup runs that reached Kopia.
#                         Since 2.37 only folders of app/VM shares; the rules a run set up to 2.36 for a folder
#                         of a data share are taken away by the next run (new_local_run), and tolerated by the
#                         policy comparison until then (NEW_GOING). Since 2.38 never a folder of an existing app or
#                         VM set only local:
#   state/kept-local.json (2.38) {interface, version, run, time, folders: [{share, folder, rules}]}: the folders of
#                         existing apps/VMs set only local the run left out of their share's Kopia source, and the
#                         rules it set - so the next run can take them away again. Nothing waits; nobody is told.

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
declare -gA APP_SHARE=()       # (2.37) share -> "docker", "vm" or "docker vm": Unraid's place for app configs / VMs
APP_SHARES_LOADED=""
declare -gA TOP_OWNERS=()      # (2.37) "share|folder" of an app/VM share -> lines "app|<name>" / "vm|<name>" keeping a part there
TOP_OWNERS_LOADED=""
declare -gA NEW_GOING=()       # (2.37) share -> rules a run set for folders no longer new (data shares, decided): the policy
                               # comparison tolerates them until new_local_run takes them away
declare -gA KEPT_RULES=()      # (2.38) share -> ignore rules for the folders of existing apps/VMs set only local: part of its
                               # wanted policy, like NEW_RULES - but nothing waits
declare -gA KEPT_SEEN_RULES=() # (2.38) "share|folder" -> the rules a run set for it, from state/kept-local.json
declare -ga KEPT_LIST=()       # (2.38) such folders: "share<US>folder<US>rules joined by <RS>"

# ub_path_share_part <path>  -> "share|folder": the share and the top-level folder a path lies in
# (<mnt>/user|user0|<pool>|<disk>/<share>/<folder>[/...]; folder empty for the share itself); 1 when it is no share path
ub_path_share_part() {
    local p="${1%/}" r x s f=""
    if [[ "$p" == "$UB_MNT"/* ]]; then r="${p#"$UB_MNT"/}"
    elif [[ "$p" == /mnt/* ]]; then r="${p#/mnt/}"
    else return 1; fi
    [[ "$r" == */* ]] || return 1
    x="${r%%/*}"; r="${r#*/}"
    [[ "$x" != addons && "$x" != remotes && "$x" != disks && "$x" != rootshare && "$x" != .* ]] || return 1
    s="${r%%/*}"
    [[ "$r" == */* ]] && { f="${r#*/}"; f="${f%%/*}"; }
    share_name_ok "$s" && [[ "$s" != .* ]] || return 1
    printf '%s|%s' "$s" "$f"
}

# ub_app_shares_load  -> APP_SHARE (once): the shares Unraid names as the place for app configs (docker.cfg
# DOCKER_APP_CONFIG_PATH) and for VMs (domain.cfg DOMAINDIR) - only when the path is the share itself. A file that
# can't be read, a key not there: no such share (every new folder then goes with its share)
ub_app_shares_load() {
    [[ -n "$APP_SHARES_LOADED" ]] && return 0
    APP_SHARES_LOADED=1; APP_SHARE=()
    local kind key file p sp
    for kind in docker vm; do
        if [[ "$kind" == docker ]]; then key=DOCKER_APP_CONFIG_PATH; file=docker.cfg; else key=DOMAINDIR; file=domain.cfg; fi
        p="$(sed -n "s/^$key=\"\\{0,1\\}\\([^\"]*\\)\"\\{0,1\\}[[:space:]]*\$/\\1/p" "$UB_BOOT/config/$file" 2>/dev/null | tail -n 1)"
        [[ -n "$p" ]] && sp="$(ub_path_share_part "$p")" || continue
        [[ -z "${sp#*|}" ]] || continue
        APP_SHARE[${sp%|}]="${APP_SHARE[${sp%|}]:+${APP_SHARE[${sp%|}]} }$kind"
    done
    return 0
}

# share_app <share>  -> 0 when it is an app/VM share (ub_app_shares_load)
share_app() { ub_app_shares_load; [[ -n "${APP_SHARE[$1]:-}" ]]; }

# top_owners_load  -> TOP_OWNERS (once, after docker_load / vm_load): per top-level folder of an app/VM share the apps
# (Compose project, else the container) whose binds and the VMs whose disks lie in it
top_owners_load() {
    [[ -n "$TOP_OWNERS_LOADED" ]] && return 0
    TOP_OWNERS_LOADED=1; TOP_OWNERS=()
    ub_app_shares_load
    (( ${#APP_SHARE[@]} )) || return 0
    local c n src sp o t
    for c in "${CT_NAMES[@]}"; do
        o="app|${CT_PROJECT[$c]:-$c}"
        while IFS='|' read -r src _; do
            [[ -n "$src" ]] && sp="$(ub_path_share_part "$src")" || continue
            [[ -n "${sp#*|}" && -n "${APP_SHARE[${sp%%|*}]:-}" ]] || continue
            [[ $'\n'"${TOP_OWNERS[$sp]:-}" == *$'\n'"$o"$'\n'* ]] || TOP_OWNERS[$sp]+="$o"$'\n'
        done <<<"${CT_BINDS[$c]:-}"
    done
    for n in "${VM_NAMES[@]}"; do
        o="vm|$n"
        while IFS='|' read -r t src _; do
            [[ -n "$t" && -n "$src" ]] && sp="$(ub_path_share_part "$src")" || continue
            [[ -n "${sp#*|}" && -n "${APP_SHARE[${sp%%|*}]:-}" ]] || continue
            [[ $'\n'"${TOP_OWNERS[$sp]:-}" == *$'\n'"$o"$'\n'* ]] || TOP_OWNERS[$sp]+="$o"$'\n'
        done <<<"${VM_DISKS[$n]:-}"
    done
    return 0
}

# top_owner_offsite <share> <folder>  -> 0 when the top-level folder of an app/VM share belongs to an app or VM that
# goes offsite: one with a Kopia source of its own ([app|vm "<name>"] kopia = yes), or - settings from before 2.19,
# where the share's source carried the apps - one with another folder recorded in kopia_known of a share going there
top_owner_offsite() {
    local s="$1" n="$2" o k sh
    local -a owners known
    top_owners_load
    mapfile -t owners <<<"${TOP_OWNERS[$s|$n]:-}"
    for o in "${owners[@]}"; do
        [[ -n "$o" ]] || continue
        [[ "$(cfg "${o%%|*}|${o#*|}|kopia" no)" == "yes" ]] && return 0
        for k in "${!TOP_OWNERS[@]}"; do
            [[ "$k" != "$s|$n" && $'\n'"${TOP_OWNERS[$k]}" == *$'\n'"$o"$'\n'* ]] || continue
            sh="${k%%|*}"
            [[ "$(share_mode "$sh")" == "kopia" ]] || continue
            mapfile -t known < <(cfg_list "share|$sh|kopia_known")
            in_list "/${k#*|}/" "${known[@]}" && return 0
        done
    done
    return 1
}

# top_owner_kept_local <share> <folder>  -> (2.38) 0 when the top-level folder of an app/VM share belongs only to apps
# and VMs that were set up before - an app with a container in [docker] known (no such list: every container, as
# backup.sh's stop tiers read it), a VM with a [vm "<name>"] section. Asked after new_decided (so none of them goes
# offsite): such a folder stays local with its app/VM, silently. Without an owner, or with a new one: 1 (it is new)
top_owner_kept_local() {
    local o t n c any=""
    local -a owners
    top_owners_load
    mapfile -t owners <<<"${TOP_OWNERS[$1|$2]:-}"
    for o in "${owners[@]}"; do
        [[ -n "$o" ]] || continue
        t="${o%%|*}"; n="${o#*|}"; any=1
        if [[ "$t" == "vm" ]]; then
            cfg_has "vm|$n" || return 1
        elif (( ${#DOCKER_KNOWN[@]} )); then
            for c in "${CT_NAMES[@]}"; do
                [[ "${CT_PROJECT[$c]:-$c}" == "$n" ]] && in_list "$c" "${DOCKER_KNOWN[@]}" && continue 2
            done
            return 1
        fi
    done
    [[ -n "$any" ]]
}

# share_watched <share>  -> 0 when the share's new folders stay local: an app/VM share (2.37 - in a data share every
# folder goes with its share) that goes to Kopia and whose folders are recorded (not "*", a collection)
share_watched() {
    is_yes "${KOPIA_ENABLED:-no}" && [[ "$(share_mode "$1")" == "kopia" && -n "${CFG[share|$1|kopia_known]+x}" \
        && $'\n'"${CFG[share|$1|kopia_known]}"$'\n' != *$'\n*\n'* ]] && share_app "$1"
}

# top_dirs <dir>  -> the folders right inside it, one per line: no links, no .zfs or lost+found, no names with
# control characters (they could not travel through the state files)
top_dirs() {
    local d="$1" p n
    [[ -d "$d" ]] || return 0
    for p in "$d"/* "$d"/.[!.]* "$d"/..?*; do
        [[ -d "$p" && ! -L "$p" ]] || continue
        n="${p##*/}"
        [[ "$n" == ".zfs" || "$n" == "lost+found" || "$n" == "$UB_INSIDE_DIR" || "$n" == *[$'\x01'-$'\x1f']* ]] && continue
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
# known (goes to Kopia), left out (the share's rules, the global ones), another source's part, or (2.37) the
# folder of an app or VM that goes offsite
new_decided() {
    local k="/$2/"
    [[ -n "${ND_KNOWN[$k]:-}" || -n "${ND_PARTS[$2]:-}" ]] && return 0
    share_rules_hide "$1" "$2" && return 0
    top_owner_offsite "$1" "$2"
}

# share_rules_hide <share> <folder>  -> 0 when the share's own ignore rules or the global ones leave the
# top-level folder out as a whole
# (2.35) its rules read whole first: returning from inside a loop over < <(cfg_list) left cfg_list writing into a
# closed pipe (SIGPIPE, or «printf: write error: Broken pipe» on stderr)
share_rules_hide() {
    local s="$1" n="$2" r b
    local -a own
    mapfile -t own < <(cfg_list "share|$s|kopia_ignore")
    for r in "${own[@]}"; do
        [[ -n "$r" ]] || continue
        rule_hides_top "$r" "$n" && return 0
        if [[ "$(share_layout "$s")" == "split" ]]; then
            # one subfolder per base: a rule for the folder on every base it may lie on
            while IFS='|' read -r b _; do
                [[ -n "$b" && ( "$r" == "/$b/$n/" || "$r" == "/$b/$n" ) ]] && return 0
            done <<<"${INV_LOCS[$s]:-}"
        fi
    done
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
    NEW_LIST=(); NEW_RULES=(); KEPT_LIST=(); KEPT_RULES=()
    while IFS= read -r s; do
        [[ -n "$s" ]] && share_watched "$s" && inv_has_share "$s" || continue
        share_top_live "$s"
        new_decided_load "$s"
        for n in "${SK_DIRS[@]}"; do
            new_decided "$s" "$n" && continue
            # (2.38) an existing app's or VM's set only local: left out the same way, but nothing waits
            if top_owner_kept_local "$s" "$n"; then
                KEPT_RULES[$s]+="$(new_rules_for "$s" "$n")"$'\n'
                continue
            fi
            NEW_RULES[$s]+="$(new_rules_for "$s" "$n")"$'\n'
            NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"$(new_folder_bytes "$s" "$n")"$'\x1f'"${NEW_SEEN[$s|$n]:-}"$'\x1f' )
        done
    done < <(cfg_names share | LC_ALL=C sort)
    return 0
}

# new_local_state_load  -> NEW_SEEN, NEW_SEEN_RULES, NEW_SEEN_BYTES from state/new-local.json, and (2.38)
# KEPT_SEEN_RULES from state/kept-local.json (each trusted only in its own shape)
new_local_state_load() {
    local s f t b r
    NEW_SEEN=(); NEW_SEEN_RULES=(); NEW_SEEN_BYTES=(); KEPT_SEEN_RULES=()
    if [[ -s "$UB_STATE/kept-local.json" ]]; then
        while IFS=$'\x1f' read -r s f r; do
            [[ -n "$s" && -n "$f" ]] && share_name_ok "$s" || continue
            KEPT_SEEN_RULES[$s|$f]="$(tr '\036' '\n' <<<"$r" | grep '^/' )"
        done < <(jq -r '.folders[]? | select((.share | type) == "string" and (.folder | type) == "string")
                        | [.share, .folder, ((.rules // []) | map(select(type == "string")) | join("\u001e"))]
                        | select(all(.[]; test("[\u0000-\u001d\n]") | not)) | join("\u001f")' "$UB_STATE/kept-local.json" 2>/dev/null)
    fi
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
    local -a d=() dc=() dp=()
    for i in "${!DRIFT[@]}"; do
        [[ "${DRIFT_CODE[$i]:-}" == new_waiting$'\x1f'* ]] && continue
        d+=( "${DRIFT[$i]}" ); dc+=( "${DRIFT_CODE[$i]:-$'\x1f'}" ); dp+=( "${DRIFT_PARAMS[$i]:-}" )
    done
    DRIFT=( "${d[@]}" ); DRIFT_CODE=( "${dc[@]}" ); DRIFT_PARAMS=( "${dp[@]}" )
    for l in "${NEW_LIST[@]}"; do
        IFS=$'\x1f' read -r s n b _ <<<"$l"
        drift_add info "New folder '$s/$n'${b:+ ($(human "$b"))} stays local - Kopia leaves it out until you decide in the setup" \
            new_waiting "$s/$n" share="$s" folder="$n" bytes="$b"
    done
    return 0
}

# drift_check_new_local  -> what the last run left out and is still undecided: NEW_LIST, NEW_RULES (so the
# policies compare as the run left them), drift info new_waiting; (2.38) the folders of existing apps/VMs set only
# local it left out: KEPT_LIST, KEPT_RULES (wanted too - no drift); the rules of folders no longer new or kept
# (NEW_GOING: decided, gone over to waiting, or in a data share since 2.37 - the run takes them away); and the app/VM
# shares going to Kopia without a record of their folders (known_missing). Before drift_check_kopia, after plan_build.
drift_check_new_local() {
    local k s n r missing=""
    local -a keys=()
    new_local_state_load
    NEW_LIST=(); NEW_RULES=(); NEW_GOING=(); KEPT_LIST=(); KEPT_RULES=(); ND_SHARE=""
    mapfile -t keys < <(printf '%s\n' "${!NEW_SEEN[@]}" "${!KEPT_SEEN_RULES[@]}" | sed '/^$/d' | LC_ALL=C sort -u)
    for k in "${keys[@]}"; do
        s="${k%%|*}"; n="${k#*|}"
        r="$(printf '%s\n' "${NEW_SEEN_RULES[$k]:-}" "${KEPT_SEEN_RULES[$k]:-}" | sed '/^$/d' | LC_ALL=C sort -u)"
        if share_watched "$s"; then
            [[ "$ND_SHARE" == "$s" ]] || new_decided_load "$s"
            if ! new_decided "$s" "$n"; then
                if top_owner_kept_local "$s" "$n"; then
                    # (2.38) its app/VM is an existing one set only local: it stays local, nothing waits
                    KEPT_RULES[$s]+="$r"$'\n'
                    KEPT_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"${r//$'\n'/$'\x1e'}" )
                    continue
                fi
                if [[ -n "${NEW_SEEN[$k]+x}" ]]; then
                    NEW_RULES[$s]+="${NEW_SEEN_RULES[$k]:-}"$'\n'
                    NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"${NEW_SEEN_BYTES[$k]:-}"$'\x1f'"${NEW_SEEN[$k]}"$'\x1f'"${NEW_SEEN_RULES[$k]//$'\n'/$'\x1e'}" )
                    continue
                fi
                # kept before, new now (its app/VM gone): the run finds it new and says so - tolerated meanwhile
            fi
        fi
        NEW_GOING[$s]+="$r"$'\n'
    done
    new_local_drift
    for s in "${PLAN_KOPIA[@]}"; do
        share_app "$s" || continue
        [[ -n "${CFG[share|$s|kopia_known]+x}" ]] || missing+="${missing:+, }$s"
    done
    [[ -n "$missing" ]] && drift_add info "Every folder of these shares goes to Kopia, new ones too, until the setup is applied once and records their folders: $missing" \
        known_missing "$missing" shares="$missing"
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

# kept_local_state_write  -> (2.38) KEPT_LIST as state/kept-local.json: the folders of existing apps/VMs set only local
# the run left out, with their rules (so a later run can take them away again)
kept_local_state_write() {
    local tmp="$UB_STATE/.kept-local.json.$$"
    if printf '%s\n' "${KEPT_LIST[@]}" | jq -R 'select(length > 0) | split("\u001f")
            | {share: .[0], folder: .[1], rules: ((.[2] // "") | split("\u001e") | map(select(length > 0)))}' \
          | jq -sc --argjson interface "$UB_INTERFACE" --arg version "$UB_VERSION" --arg run "${TS:-}" \
            --argjson time "$(date +%s)" '{interface: $interface, version: $version, run: $run, time: $time, folders: .}' \
            >"$tmp" 2>/dev/null && [[ -s "$tmp" ]]; then
        mv -f "$tmp" "$UB_STATE/kept-local.json" 2>/dev/null
    fi
    rm -f "$tmp"
    return 0
}

# new_policy_align <share> <container path>  -> the share's policy leaves out its new folders (NEW_RULES) and (2.38)
# the folders of existing apps/VMs set only local (KEPT_RULES), and no longer leaves out those a run left out before
# and nobody wants left out now (decided or gone). Only these rules - everything else in the policy is the setup's.
# 1 when Kopia refused.
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
    done <<<"${NEW_RULES[$s]:-}"$'\n'"${KEPT_RULES[$s]:-}"
    while IFS= read -r k; do
        [[ -n "$k" && "${k%%|*}" == "$s" ]] || continue
        while IFS= read -r r; do
            [[ -n "$r" && -z "${seen[$r]:-}" ]] || continue
            seen[$r]=1
            grep -Fxq -- "$r" <<<"$cur" && ! grep -Fxq -- "$r" <<<"$want" && args+=( --remove-ignore "$r" )
        done <<<"${NEW_SEEN_RULES[$k]:-}"$'\n'"${KEPT_SEEN_RULES[$k]:-}"
    done < <(printf '%s\n' "${!NEW_SEEN_RULES[@]}" "${!KEPT_SEEN_RULES[@]}" | sed '/^$/d' | LC_ALL=C sort -u)
    (( ${#args[@]} )) || return 0
    kopia_x policy set "$KOPIA_ID:$cpath" "${args[@]}" >>"${LOG_FILE:-/dev/null}" 2>&1 || return 1
    log "  Kopia policy of '$s': ${args[*]}"
    return 0
}

# new_local_run  -> right before the Kopia uploads: which top-level folders of the shares going there are new
# - looked up where Kopia reads them, in the mounted snapshots (<mount_root>/<share>, per base when split) -,
# left out of the share's policy (a share whose policy Kopia refuses is skipped: never uploaded unasked),
# noted in state/new-local.json, status.json and drift.json, and told once. (2.38) A folder of an existing app/VM
# set only local is left out the same way, noted only in state/kept-local.json - silently. backup.sh: Kopia phase,
# after drift_check_new_local; uses SHARE_MOUNTED and SKIP_KOPIA of the run.
new_local_run() {
    local s n b cpath bytes first rules now k l fresh="" nfresh=0
    local -a fresh_l=() pend=() shown=()
    local -A looked=() names=()
    local -a keep=( "${NEW_LIST[@]}" )
    now="$(date +%s)"
    NEW_LIST=(); KEPT_LIST=()
    for s in "${PLAN_KOPIA[@]}"; do
        if ! share_watched "$s"; then
            # (2.37) a data share (or a collection, or not recorded yet): every folder goes with it - the rules a
            # run set before for its folders go (a refusal only keeps them local one more night)
            [[ -n "${NEW_GOING[$s]:-}" ]] || continue
            NEW_RULES[$s]=""; KEPT_RULES[$s]=""
            if [[ -n "${SKIP_KOPIA[$s]:-}" || -z "${SHARE_MOUNTED[$s]:-}" ]] || ! cpath="$(k_path "$(share_kopia_hostpath "$s")")" \
               || ! new_policy_align "$s" "$cpath"; then
                # not done this time: noted on (state file only - nothing waits for a decision), the next run tries again
                log "  The rules for the former new folders of '$s' stay until the next run - those folders stay local meanwhile"
                for k in "${!NEW_SEEN[@]}"; do
                    [[ "${k%%|*}" == "$s" ]] || continue
                    pend+=( "$s"$'\x1f'"${k#*|}"$'\x1f'"${NEW_SEEN_BYTES[$k]:-}"$'\x1f'"${NEW_SEEN[$k]}"$'\x1f'"${NEW_SEEN_RULES[$k]//$'\n'/$'\x1e'}" )
                done
            else
                looked[$s]=1      # (2.38) its kept folders' rules went too
            fi
            continue
        fi
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
        NEW_RULES[$s]=""; KEPT_RULES[$s]=""
        while IFS= read -r n; do
            [[ -n "$n" ]] || continue
            new_decided "$s" "$n" && continue
            if top_owner_kept_local "$s" "$n"; then
                # (2.38) an existing app's or VM's set only local: it stays local with it - left out, nobody told
                rules="$(new_rules_for "$s" "$n")"
                KEPT_RULES[$s]+="$rules"$'\n'
                KEPT_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"${rules//$'\n'/$'\x1e'}" )
                log "  Folder '$s/$n' stays local like its app or VM: Kopia leaves it out"
                continue
            fi
            k="$s|$n"
            bytes="$(new_folder_bytes "$s" "$n")"
            first="${NEW_SEEN[$k]:-}"
            if [[ -z "$first" ]]; then
                first="$now"; nfresh=$((nfresh+1))
                fresh+="${fresh:+, }$s/$n${bytes:+ ($(human "$bytes"))}"
                fresh_l+=( "$s/$n${bytes:+   $(human_sp "$bytes")}" )
            fi
            rules="$(new_rules_for "$s" "$n")"
            NEW_RULES[$s]+="$rules"$'\n'
            NEW_LIST+=( "$s"$'\x1f'"$n"$'\x1f'"$bytes"$'\x1f'"$first"$'\x1f'"${rules//$'\n'/$'\x1e'}" )
            log "  New folder '$s/$n'${bytes:+ ($(human "$bytes"))} stays local: Kopia leaves it out until you decide (Set up...)"
        done < <(printf '%s\n' "${!names[@]}" | sed '/^$/d' | LC_ALL=C sort)
        if ! new_policy_align "$s" "$cpath"; then
            # 2.67: Kopia not answering (kopia_silent): the shares after it aren't asked again - each would wait as long
            if kopia_silent; then SKIP_KOPIA[$s]="Kopia did not answer - its new folders could not be left out of its policy"
            else SKIP_KOPIA[$s]="its new folders could not be left out of its policy"; fi
        fi
    done
    # what this run didn't look at (a share skipped or not mounted) stays as it was, while undecided
    for l in "${keep[@]}"; do
        s="${l%%$'\x1f'*}"
        [[ -n "${looked[$s]:-}" ]] || NEW_LIST+=( "$l" )
    done
    # (2.38) the kept folders' rules of a share not looked at (skipped, not mounted, a refusal) stay noted as they were
    for k in "${!KEPT_SEEN_RULES[@]}"; do
        s="${k%%|*}"
        [[ -n "${looked[$s]:-}" ]] || KEPT_LIST+=( "$s"$'\x1f'"${k#*|}"$'\x1f'"${KEPT_SEEN_RULES[$k]//$'\n'/$'\x1e'}" )
    done
    kept_local_state_write
    shown=( "${NEW_LIST[@]}" )
    NEW_LIST+=( "${pend[@]}" )
    new_local_state_write
    NEW_LIST=( "${shown[@]}" )
    ST_NEW_LOCAL="$(new_local_json | jq -c 'map(del(.rules))' 2>/dev/null)" || ST_NEW_LOCAL="[]"
    [[ -n "$ST_NEW_LOCAL" ]] || ST_NEW_LOCAL="[]"
    new_local_drift
    drift_text >"$UB_STATE/drift.txt" 2>/dev/null
    drift_json_write
    status_write
    if (( nfresh > 0 )); then
        if (( nfresh == 1 )); then
            ub_tell new_folder normal "" "New folder stays local" "$fresh - new in the share for apps or VMs, of no app or VM or of a new one: only in the local snapshots until you decide in Mr. Backupsy's setup." "folder=$fresh"
        else
            # the list one per line in the long text (at most 12; the setup lists them all)
            ub_tell new_folders normal "$(echo "NEW, ONLY LOCAL UNTIL YOU DECIDE"; printf '  %s\n' "${fresh_l[@]:0:12}"
                   (( nfresh > 12 )) && echo "  $(( nfresh - 12 )) more - Mr. Backupsy's setup lists them all")" \
                "$nfresh new folders stay local" "$nfresh new folders in the shares for apps or VMs, of no app or VM or of a new one: only in the local snapshots until you decide in Mr. Backupsy's setup." "n=$nfresh"
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
UB_PARTNER_PAIRS="${UB_PARTNER_PAIRS:-$(dirname "$UB_DATA")/partner/pairs.json}"   # the Team Lead's pairs (setup.sh and, since 2.29, the run read it)
UB_PARTNER_ASK="${UB_PARTNER_ASK:-60}"           # seconds for a short question to the door (ping, list, resume)
UB_PARTNER_SNAP_RE='^uso-backup-[0-9]{8}-[0-9]{4}$'   # the only snapshot names the door accepts
UB_PARTNER_BOOKMARK="uso-partner-"               # <dataset>#uso-partner-<id>: the last snapshot sent to that partner
# 2.73: several datasets to one partner at once, only while that clearly moves more (backup.sh partner_adapt)
UB_PARTNER_STREAMS="${UB_PARTNER_STREAMS:-4}"    # at most so many at once (and what the partner's door takes; 1 = one after another)
UB_PARTNER_WARM="${UB_PARTNER_WARM:-10}"         # seconds after their number changed before the rate counts
UB_PARTNER_WINDOW="${UB_PARTNER_WINDOW:-30}"     # seconds the total rate is measured over
UB_PARTNER_GAIN="${UB_PARTNER_GAIN:-15}"         # percent more the total must move for one more at once to stay
UB_PARTNER_PV_EVERY="${UB_PARTNER_PV_EVERY:-2}"  # seconds between pv's counts (up to 2.72: 10)
# the office's USO network (2.71): its node's status in RAM (the helper's), its namespace, how the call gets there
UB_USONET_STATUS="${UB_USONET_STATUS-$(ub_is_plugin && echo /var/run/unraid-secretary-office/usonet/status.json)}"
UB_USONET_NETNS="uso-net"
UB_USONET_NSENTER="${UB_USONET_NSENTER:-/usr/bin/nsenter}"

partner_id_ok()        { [[ "$1" =~ ^[0-9a-f]{8}$ ]]; }
partner_unit_name_ok() { [[ "$1" =~ ^[A-Za-z0-9._-]{1,64}$ && "$1" != .* ]]; }   # one word through the door, a dataset name there

# partner_ids  -> the partners settings.ini names ([partner "<id>"] with a valid id), in its order
partner_ids() { local id; while IFS= read -r id; do partner_id_ok "$id" && printf '%s\n' "$id"; done < <(cfg_names partner); return 0; }
partner_name() { cfg "partner|$1|name" "$1"; }

# partner_units <id>  -> the units ticked for that partner: place first, then the shares and the VMs in settings.ini's order
# (2.35) each list is read whole before grep looks at it: grep -q stops at the first match, and a cfg_list still
# writing then died of SIGPIPE - under pipefail that match counted as none and the unit was left out of the run
partner_units() {
    local id="$1" n
    grep -Fxq -- "$id" <<<"$(cfg_list "general|partner_place")" && echo place
    while IFS= read -r n; do
        [[ -n "$n" ]] || continue
        if grep -Fxq -- "$id" <<<"$(cfg_list "share|$n|partner")"; then echo "share:$n"; fi
    done < <(cfg_names share)
    while IFS= read -r n; do
        [[ -n "$n" ]] || continue
        if grep -Fxq -- "$id" <<<"$(cfg_list "vm|$n|partner")"; then echo "vm:$n"; fi
    done < <(cfg_names vm)
    while IFS= read -r n; do
        [[ -n "$n" ]] || continue
        if grep -Fxq -- "$id" <<<"$(cfg_list "app|$n|partner")"; then echo "app:$n"; fi
    done < <(cfg_names app)
    return 0
}

# app_own_ds <app>  -> 0 and AO_DS / AO_SHARE when the app's folder ([app] folder, its first: <share>/<dir>) is a ZFS
# dataset of its own (2.45 - a child of its share's dataset: INV_CHILDREN), else 1 and AO_WHY (no_dataset | not_dataset).
# 2.74: no [app] folder (e.g. right after «Make it a dataset of its own…», which leaves settings.ini alone): the first
# child dataset only this app's containers bind into (ds_bind_app, Docker's binds)
app_own_ds() {
    local f s d b ds mp
    AO_DS=""; AO_SHARE=""; AO_WHY="no_dataset"
    if declare -F pu_app_folder >/dev/null; then f="$(pu_app_folder "$1" | head -n 1)"      # setup.sh: the decisions'
    else f="$(cfg_list "app|$1|folder" | head -n 1)"; fi
    if [[ -z "$f" ]]; then
        for s in "${INV_SHARES[@]}"; do
            inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] || continue
            while IFS='|' read -r b ds mp; do
                [[ -n "$ds" ]] && ds_bind_app "$b" "$mp" && [[ "$DBA_APP" == "$1" ]] && { AO_DS="$ds"; AO_SHARE="$s"; return 0; }
            done <<<"${INV_CHILDREN[$s]:-}"
        done
        return 1
    fi
    s="${f%%/*}"; d="${f#*/}"; d="${d%%/*}"
    [[ -n "$f" && "$f" == */* && -n "$d" ]] || return 1
    inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] || return 1
    AO_SHARE="$s"
    while IFS='|' read -r b ds mp; do
        [[ -n "$ds" && "$mp" == */"$s/$d" ]] && { AO_DS="$ds"; return 0; }
    done <<<"${INV_CHILDREN[$s]:-}"
    [[ "${INV_LOCS[$s]}" == *"|zfs|"* ]] && AO_WHY="not_dataset"
    return 1
}

# partner_app_ds <dataset>  -> 0 and PAD_APP when it is an app's own folder dataset (2.45: a unit of its own, like a VM's)
partner_app_ds() {
    local n
    PAD_APP=""
    while IFS= read -r n; do
        [[ -n "$n" ]] && app_own_ds "$n" && [[ "$AO_DS" == "$1" ]] && { PAD_APP="$n"; return 0; }
    done < <(cfg_names app)
    return 1
}

# ds_bind_app <base> <mountpoint>  -> 0 and DBA_APP when exactly one app binds into that dataset: a bind source at or
# below its mountpoint (through /mnt/user, /mnt/user0 or that base) of containers of one app only - the app's name as
# the office says it (its Compose project, else the container). 2.74: an app's own dataset known from Docker's binds,
# not only from its [app] folder. None, or two apps binding into it (a dataset they share is no app's own): 1
ds_bind_app() {
    local b="$1" mp="${2%/}" rel c src r x o=""
    DBA_APP=""
    [[ -n "${INV_BASE_PATH[$b]:-}" && "$mp" == "${INV_BASE_PATH[$b]}/"* ]] || return 1
    rel="${mp#"${INV_BASE_PATH[$b]}"/}"
    for c in "${CT_NAMES[@]}"; do
        while IFS='|' read -r src _; do
            src="${src%/}"
            [[ -n "$src" ]] || continue
            r=""
            for x in "$UB_MNT/user" "$UB_MNT/user0" "${INV_BASE_PATH[$b]}"; do
                if [[ "$src" == "$x/"* ]]; then r="${src#"$x"/}"; break; fi
            done
            [[ -n "$r" && ( "$r" == "$rel" || "$r" == "$rel/"* ) ]] || continue
            x="${CT_PROJECT[$c]:-$c}"
            [[ -z "$o" || "$o" == "$x" ]] || return 1
            o="$x"
        done <<<"${CT_BINDS[$c]:-}"
    done
    [[ -n "$o" ]] || return 1
    DBA_APP="$o"
    return 0
}

# partner_vm_ds <dataset>  -> 0 and PVD_VM when it is (or lies below) a VM's own dataset
partner_vm_ds() {
    local n d
    PVD_VM=""
    for n in "${VM_NAMES[@]}"; do
        while IFS= read -r d; do [[ -n "$d" && ( "$1" == "$d" || "$1" == "$d/"* ) ]] && { PVD_VM="$n"; return 0; }; done <<<"${VM_OWN_DS[$n]:-}"
    done
    return 1
}

# partner_unit_dataset <unit>  -> 0 and PU_DS = the dataset that travels, or 1 and PU_WHY:
#   name         the name can't travel as one word through the door ([A-Za-z0-9._-], at most 64)
#   no_dataset   not on ZFS (XFS/btrfs, an array disk), gone, locked - or a VM sharing its dataset
#   not_dataset  a folder in a dataset (e.g. in a pool's root dataset), not a dataset of its own
#   split        on several pools/disks; a VM with disks in several datasets
#   (children    up to 2.73: a share with child datasets other than its VMs' and apps' own - the whole share stayed here)
# 2.74: a child dataset that is no unit of its own (no VM's, no app's - by its [app] folder or Docker's binds -, not
# excluded, not the storeroom) no longer keeps the share here: the share goes, zfs send without -R leaves the child
# out, and PU_LEFT names each such child ("<dataset>|<folder inside the share>|<owner>", one per line) for the plan and
# the run.
# 2.76 (with <partner id>): a VM's or app's own dataset is skipped only when that unit goes to this partner as that very
# dataset (vm:<n> among its units; app:<n> among them and app_own_ds giving this dataset) - else it stays here too and
# PU_LEFT names its owner (vm:<n> | app:<n>; up to 2.75 it was left off silently). Without an id (the setup's
# partner_ok) such a dataset counts as its unit's, as before. A dataset below another child dataset goes or stays with
# that one and is never named for the share; for an app: unit, PU_LEFT names the child datasets inside the app's own
# dataset (zfs send without -R leaves them out too).
# Inventory (inv_scan), Docker (docker_load), VMs (vm_load) and the backup place (DUMPS_SHARE) must be loaded.
partner_unit_dataset() {
    local u="$1" pid="${2:-}" s="" b m layer sub ds mp own n owner
    local -a locs=() excl=() kids=() pus=()
    PU_DS=""; PU_WHY=""; PU_LEFT=""
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
        app:*)
            s="${u#app:}"
            partner_unit_name_ok "$s" || { PU_WHY="name"; return 1; }
            app_own_ds "$s" || { PU_WHY="$AO_WHY"; return 1; }
            PU_DS="$AO_DS"
            partner_left_inside "$AO_SHARE" "$AO_DS"
            return 0 ;;
        *)       PU_WHY="name"; return 1 ;;
    esac
    inv_has_share "$s" && [[ "${INV_METHOD[$s]}" != "none" ]] || { PU_WHY="no_dataset"; return 1; }
    mapfile -t locs < <(sed '/^$/d' <<<"${INV_LOCS[$s]}")
    (( ${#locs[@]} > 1 )) && { PU_WHY="split"; return 1; }
    IFS='|' read -r b m layer sub <<<"${locs[0]:-}"
    [[ "$m" == "zfs" && -n "$layer" ]] || { PU_WHY="no_dataset"; return 1; }
    [[ -z "$sub" ]] || { PU_WHY="not_dataset"; return 1; }
    mapfile -t excl < <(cfg_list "share|$s|exclude_dataset")
    while IFS='|' read -r b ds mp; do [[ -n "$ds" ]] && kids+=( "$ds" ); done <<<"${INV_CHILDREN[$s]:-}"
    [[ -n "$pid" ]] && mapfile -t pus < <(partner_units "$pid")
    while IFS='|' read -r b ds mp; do
        [[ -z "$ds" ]] && continue
        ub_storeroom "${ds##*/}" && continue                                     # Ms. Dustdevil's storeroom: never backed up
        in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}" && continue
        _parent_excluded "$ds" "${kids[@]}" && continue                          # 2.76: below another child - goes or stays with it
        owner=""
        if partner_vm_ds "$ds"; then owner="vm:$PVD_VM"                         # a VM's own dataset is a unit of its own
        elif partner_app_ds "$ds"; then owner="app:$PAD_APP"                    # an app's own folder dataset too (2.45)
        elif ds_bind_app "$b" "$mp"; then owner="app:$DBA_APP"; fi              # ... known from Docker's binds (2.74)
        if [[ -n "$owner" ]]; then
            [[ -z "$pid" ]] && continue                                          # the setup's partner_ok: its unit's, as before
            partner_unit_takes "$owner" "$ds" "${pus[@]}" && continue            # 2.76: it goes to this partner as that unit
        fi
        PU_LEFT+="$ds|${mp#"${INV_BASE_PATH[$b]:-}/$s/"}|$owner"$'\n'           # 2.74: it stays here, the share goes
    done <<<"${INV_CHILDREN[$s]:-}"
    PU_DS="$layer"
    return 0
}

# partner_unit_takes <vm:n|app:n> <dataset> <the partner's units...>  -> 0 when that unit goes to the partner and sends
# exactly this dataset (2.76): a VM ticked for it (its own datasets, «split» said under its own unit); an app ticked for
# it whose own dataset (app_own_ds: its [app] folder, else Docker's binds) is this one - a second dataset it binds stays
partner_unit_takes() {
    local o="$1" ds="$2"; shift 2
    in_list "$o" "$@" || return 1
    [[ "$o" == vm:* ]] && return 0
    app_own_ds "${o#app:}" && [[ "$AO_DS" == "$ds" ]]
}

# partner_left_inside <share> <dataset>: PU_LEFT += the child datasets right inside that dataset (2.76: an app's own
# dataset holding datasets of its own - zfs send without -R leaves them out); storeroom and excluded ones not named
partner_left_inside() {
    local s="$1" top="$2" b ds mp
    local -a excl=() kids=()
    mapfile -t excl < <(cfg_list "share|$s|exclude_dataset")
    while IFS='|' read -r b ds mp; do [[ -n "$ds" && "$ds" == "$top/"* ]] && kids+=( "$ds" ); done <<<"${INV_CHILDREN[$s]:-}"
    while IFS='|' read -r b ds mp; do
        [[ -n "$ds" && "$ds" == "$top/"* ]] || continue
        ub_storeroom "${ds##*/}" && continue
        in_list "$ds" "${excl[@]}" || _parent_excluded "$ds" "${excl[@]}" && continue
        _parent_excluded "$ds" "${kids[@]}" && continue
        PU_LEFT+="$ds|${mp#"${INV_BASE_PATH[$b]:-}/$s/"}|"$'\n'
    done <<<"${INV_CHILDREN[$s]:-}"
    return 0
}

# ub_host_name: this server's name as Unraid and the office's Team Lead say it («Change what <host> sends…») - ident.cfg
# NAME (Settings -> Identification), else the kernel's; never settings.ini's [general] server, which keeps the name the
# setup was first made under (2.34: a test server renamed after its setup, QA 2026-10-08)
ub_host_name() {
    local n
    n="$(sed -n 's/^NAME="\{0,1\}\([^"]*\)"\{0,1\}[[:space:]]*$/\1/p' "$UB_BOOT/config/ident.cfg" 2>/dev/null | head -1)"
    [[ -n "$n" ]] || n="$(hostname -s 2>/dev/null)"
    printf '%s\n' "${n:-Unraid}"
}

# partner_ssh_cmd <id>  -> PSSH: the ssh call to that partner's door - the one place for its options (plan 3.5):
# the pair's own key only, never a password or agent, the pinned host keys only, a fast AEAD cipher, no compression
# (zfs send -c sends compressed blocks), a dead link noticed within two minutes
# 2.71: a partner whose address is a peer of the office's USO network node (its status in RAM, UB_USONET_STATUS) is
# called from inside the network's namespace - the same rule as the office's partnerSshArgs() (agent/lib/usonet.php)
# 2.72: the address and port from partner_addr (the Team Lead's pairs.json first)
partner_ssh_cmd() {
    partner_addr "$1"
    PSSH=( ssh -i "$UB_PARTNER_DIR/$1.key" -o IdentitiesOnly=yes -o BatchMode=yes
           -o "UserKnownHostsFile=$UB_PARTNER_DIR/$1.known" -o StrictHostKeyChecking=yes
           -o Ciphers=aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com -o Compression=no
           -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=4
           -p "$PPORT" "root@$PADDR" )
    if usonet_peer "$PADDR"; then PSSH=( "$UB_USONET_NSENTER" "--net=/run/netns/$UB_USONET_NETNS" "${PSSH[@]}" ); fi
}
# partner_addr <id>  -> PADDR, PPORT: where that partner's door is (2.72) - the Team Lead's pairs.json (partner_pairs_load,
# exactly its shape) when it names the pair: «Change the address…» there (a partner moved into the USO network, joined
# anew with a new address) reaches the run at once, without Mr. Backupsy's Apply; else - no pairs.json, one not in its
# shape, a pair it doesn't name - settings.ini's [partner "<id>"] as before. The pairs read into a PAIRS of its own (a
# caller looping over PAIRS keeps its list)
partner_addr() {
    local l
    local -a PAIRS=()
    PADDR="$(cfg "partner|$1|address")"; PPORT="$(cfg "partner|$1|port" 22)"
    partner_pairs_load || return 0
    for l in "${PAIRS[@]}"; do
        if [[ "${l%%|*}" == "$1" ]]; then IFS='|' read -r _ _ PADDR PPORT _ <<<"$l"; return 0; fi
    done
    return 0
}
# usonet_peer <address>  - is it a peer of this office's USO network node? Its own peer list (never the 100.64/10 range:
# the user's own tailnet uses the same addresses); not joined, no status: no
usonet_peer() {
    [[ -n "$1" && -f "$UB_USONET_STATUS" && ! -L "$UB_USONET_STATUS" ]] || return 1
    jq -e --arg a "${1,,}" '.v == 1 and ([.peers[]?.ips[]? | ascii_downcase] | index($a) != null)' "$UB_USONET_STATUS" >/dev/null 2>&1
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
# Since 2.29 also its send.units - what the partner agreed to keep (the Team Lead's «Change what <host> sends…» asks
# for more through the door): a unit that isn't among them is not_agreed - the setup says so, the run skips it.
# partner_pairs_load -> 0 and PAIRS (lines "id|name|address|port|rate_mbit|units", units space-separated), 1 when the
# file is missing or not as expected
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
                 and (.my_key | type) == "string"
                 and ((.send.units // []) | type) == "array" and ((.send.units // []) | length) <= 64
                 and all((.send.units // [])[]; type == "string" and test("^((share|vm|app):[A-Za-z0-9][A-Za-z0-9._-]{0,63}|place)$")))
        | [.id, .name, .address, (.port | tostring),
           ((.send.rate_mbit // 0) | if type == "number" and . >= 0 then floor else 0 end | tostring),
           ((.send.units // []) | join(" "))] | join("|")' "$f" 2>/dev/null)
    return 0
}

# partner_ended <id>  -> 0 when the Team Lead's pairs can be read and <id> is not among the pairs this office sends to:
# the partnership was ended there (2.45) - settings.ini still names it until Set up... > Apply drops it or gives its
# units to the pair made anew. 1 when it is paired, or without a pairs.json (nothing to tell then)
partner_ended() {
    local l
    partner_pairs_load || return 1
    for l in "${PAIRS[@]}"; do [[ "${l%%|*}" == "$1" ]] && return 1; done
    return 0
}

# partner_anew_match <gone id> <its address> [<its name>]  (2.45, setup.sh)  -> the id of a current pair (pairs.json, one this office
# sends to) with the same address, else one with a host key the ended pair had (partner/ended.json beside pairs.json: {v: 1,
# pairs: {id: {name, address, port, host_keys, time}}} - the Team Lead writes it when a partnership is ended), else (2.47) the
# one pair of the same name (ignoring case; two of that name: none); nothing without a match
partner_anew_match() {
    local f="$UB_PARTNER_PAIRS" e
    e="$(dirname "$UB_PARTNER_PAIRS")/ended.json"
    [[ -f "$f" && ! -L "$f" ]] || return 0
    [[ -f "$e" && ! -L "$e" ]] || e=/dev/null
    jq -rn --arg old "$1" --arg addr "${2,,}" --arg name "${3:-}" --slurpfile p "$f" --slurpfile e "$e" '
        ($p[0].pairs // []) | map(select(type == "object" and (.id | type) == "string" and (.id | test("^[0-9a-f]{8}$"))
                                          and .id != $old and (.my_key | type) == "string")) as $pairs
        | (($e[0].pairs // {})[$old].host_keys // []) as $hk
        | ([$pairs[] | select($addr != "" and ((.address // "") | ascii_downcase) == $addr) | .id]
           + [$pairs[] | select(($hk | length) > 0 and any((.host_keys // [])[]; . as $k | ($hk | any(.[]; . == $k)))) | .id]
           + ([$pairs[] | select($name != "" and ((.name // "") | ascii_downcase) == ($name | ascii_downcase)) | .id]
              | if length == 1 then . else [] end))
        | first // empty' 2>/dev/null
    return 0
}

# --- What the phase did, for status.json (section 7) ---------------------------
ST_PARTNER_IDS=()         # "id|name" of the partners this run sends to (empty: "partner": null)
declare -gA ST_PARTNER_WALL=() # 2.76: id -> the seconds its transfers took from the first start to the last end (several at
                               # once: the wall time, status.json partners[].seconds - the office's Mbit/s)
ST_PARTNER_PLAN=()        # "id|unit" in the phase's order
ST_PARTNER_CUR=""         # "id|unit" being sent
ST_PARTNER_CUR_T=0
ST_PARTNER_CUR_BYTES=0    # what left so far (pv's count, every 10 s - status.json every 10 s too since 2.42)
ST_PARTNER_CUR_EST=0      # 2.42: what zfs send -nP reckoned for this transfer (0 = unknown: a resumed one)
ST_PARTNER_DONE=()        # "id|unit|snap|from|bytes|seconds|resumed(1/0)"
ST_PARTNER_SKIPPED=()     # "id|unit|why"
ST_PARTNER_FAILED=()      # "id|unit|why"
ST_PARTNER_INTERRUPTED="" # "id|unit": the transfer an array stop (or a stop by hand) ended - resumed by the next run
ST_PARTNER_STREAMS=()     # 2.73: "id|unit|since|bytes|estimate" - every transfer going on (backup.sh partner_cur_set)
ST_PARTNER_INTERRUPTED_ALL=()  # 2.73: every one it ended (several datasets at once), the first = ST_PARTNER_INTERRUPTED
ST_PARTNER_LEFT=()        # 2.74: "id|unit|dataset|folder|owner" - a child dataset of a unit sent that stays here (no unit of its own;
                          # 2.76: owner vm:<n> | app:<n> - its unit doesn't take it to this partner)

partner_status_json() {
    (( ${#ST_PARTNER_IDS[@]} )) || { echo null; return 0; }
    local ids plan done_ sk fl st ia lo
    ids="$(printf '%s\n' "${ST_PARTNER_IDS[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], name: .[1]}' | jq -sc .)" || ids='[]'
    local k wall='{}'
    for k in "${!ST_PARTNER_WALL[@]}"; do
        wall="$(jq -c --arg k "$k" --argjson v "${ST_PARTNER_WALL[$k]}" '.[$k] = $v' <<<"$wall")" || wall='{}'
    done
    ids="$(jq -c --argjson w "$wall" 'map(if $w[.id] != null then .seconds = $w[.id] else . end)' <<<"$ids")" || ids='[]'
    plan="$(printf '%s\n' "${ST_PARTNER_PLAN[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1]}' | jq -sc .)" || plan='[]'
    done_="$(printf '%s\n' "${ST_PARTNER_DONE[@]}" | jq -R 'select(length > 0) | split("|")
        | {id: .[0], unit: .[1], snap: .[2], from: (if .[3] == "" then null else .[3] end), bytes: (.[4] | tonumber),
           seconds: (.[5] | tonumber), resumed: (.[6] == "1")}
        | .mbit = (if .seconds > 0 then ((.bytes * 8 / .seconds / 1000000 * 10 | round) / 10) else null end)' | jq -sc .)" || done_='[]'
    sk="$(printf '%s\n' "${ST_PARTNER_SKIPPED[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1], why: .[2]}' | jq -sc .)" || sk='[]'
    fl="$(printf '%s\n' "${ST_PARTNER_FAILED[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1], why: .[2]}' | jq -sc .)" || fl='[]'
    # 2.73: every transfer going on (several datasets at once) - current is the one going on longest; interrupted_all
    # every one an array stop or a stop by hand ended (interrupted: the first of them)
    st="$(printf '%s\n' "${ST_PARTNER_STREAMS[@]}" | jq -R 'select(length > 0) | split("|")
        | {id: .[0], unit: .[1], since: (.[2] | tonumber), bytes: (.[3] | tonumber), estimate: (.[4] | tonumber | if . > 0 then . else null end)}' | jq -sc .)" || st='[]'
    ia="$(printf '%s\n' "${ST_PARTNER_INTERRUPTED_ALL[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1]}' | jq -sc .)" || ia='[]'
    # 2.74: per unit planned, each child dataset that stays here (the unit goes without it: zfs send without -R)
    # 2.76: owner (vm:<n> | app:<n>) when it is a VM's or app's own dataset that unit doesn't take to this partner
    lo="$(printf '%s\n' "${ST_PARTNER_LEFT[@]}" | jq -R 'select(length > 0) | split("|") | {id: .[0], unit: .[1], dataset: .[2], folder: .[3]}
        + (if (.[4] // "") != "" then {owner: .[4]} else {} end)' | jq -sc .)" || lo='[]'
    jq -nc --argjson partners "${ids:-[]}" --argjson planned "${plan:-[]}" --argjson done "${done_:-[]}" \
        --argjson skipped "${sk:-[]}" --argjson failed "${fl:-[]}" --arg cur "$ST_PARTNER_CUR" --argjson since "${ST_PARTNER_CUR_T:-0}" \
        --argjson bytes "${ST_PARTNER_CUR_BYTES:-0}" --argjson est "${ST_PARTNER_CUR_EST:-0}" --arg intr "$ST_PARTNER_INTERRUPTED" \
        --argjson streams "${st:-[]}" --argjson intr_all "${ia:-[]}" --argjson left "${lo:-[]}" \
        '{partners: $partners, planned: $planned,
          current: (if $cur == "" then null else ($cur | split("|") | {id: .[0], unit: .[1], since: $since, bytes: $bytes, estimate: (if $est > 0 then $est else null end)}) end),
          streams: $streams,
          done: $done, skipped: $skipped, failed: $failed,
          interrupted: (if $intr == "" then null else ($intr | split("|") | {id: .[0], unit: .[1]}) end),
          interrupted_all: $intr_all, left_out: $left}'
}

# --- What the phase keeps between runs (state/, root only) ---------------------
# partner-sent.json  {"<id>": {"<unit>": {"snap", "dataset", "time"}}} - the last snapshot each partner got of each unit:
#                    with the bookmark <dataset>#uso-partner-<id> it is the base of the next incremental send even when
#                    the engine's retention destroyed that snapshot here
# partner-skips.json (up to 2.45: nights in a row unreachable, the day each warning was said) - no longer read or
#                    written since 2.46 (a partner that got nothing is said in the same run); a stale one is harmless
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
# sleeps when ANY of its disks does (hazel, hazel2 ...), an array disk is just itself. disks.ini is read once
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
    # spun down AND rotating: Unraid puts SATA SSDs into standby too, but an SSD wakes in milliseconds and wears nothing
    # worth sparing - never asleep for the engine (2026-10-08; agent/lib/mounts.php diskAsleep() says the same);
    # rotational missing (an older disks.ini) = a rotating disk, as before
    while IFS= read -r n; do [[ -n "$n" ]] && UB_SPUNDOWN[$n]=1; done < <(awk '
        function flush() { if (name != "" && sd == "1" && rot != "0") print name; sd = ""; rot = "" }
        /^\[/ { flush(); name = $0; gsub(/[\[\]"]/, "", name); next }
        /^spundown=/ { v = $0; sub(/^spundown="?/, "", v); sub(/"$/, "", v); sd = v }
        /^rotational=/ { v = $0; sub(/^rotational="?/, "", v); sub(/"$/, "", v); rot = v }
        END { flush() }' "$UB_DISKS_INI")
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
    [[ -n "$1" ]] || return 0
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
