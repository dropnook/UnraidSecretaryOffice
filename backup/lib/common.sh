#!/bin/bash
###############################################################################
# unraid-backup - lib/common.sh
#
# Gemeinsame Funktionen fuer setup.sh und backup.sh. Wird per "source"
# geladen und nie direkt ausgefuehrt.
#
# Inhalt
#   1. Grundlagen      Pfade, Logging, Mitteilungen
#   2. settings.ini    Lesen, Pruefen, Werte holen
#   3. Inventar        Pools, Disks, Shares, ZFS-Datasets, Container
#   4. Plan            was wird gesnapshottet, gemountet, gesichert
#   5. Kopia           Mapping, Identitaet, Policies (Soll/Ist)
#   6. Abweichungen    Vergleich Inventar <-> settings.ini
###############################################################################

# shellcheck disable=SC2034   # viele Variablen werden erst in den Scripten benutzt

UB_VERSION="2.6"
UB_NAME="unraid-backup"

##############################################################################
# 1. Grundlagen
##############################################################################

# Das Script ist Teil des Unraid Secretary Office: Code in <office>/backup,
# Einstellungen, Zustand, Protokolle und Dumps in <office>/data/unraid-backup
# (nicht im Git, nur root hat Zugriff - die Dumps enthalten alle Datenbanken).
UB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
UB_DATA="${UB_DATA:-$(cd "$UB_DIR/.." && pwd -P)/data/$UB_NAME}"
UB_SETTINGS="${UB_SETTINGS:-$UB_DATA/settings.ini}"
UB_STATE="$UB_DATA/state"
UB_LOGS="$UB_DATA/logs"
UB_DUMPS="$UB_DATA/dumps"
# Privater Zwischenbereich fuer Mounts, die Kopia nicht sehen soll (ZFS-Ebenen)
# und fuer read-only-Binds, bevor sie in den Snapshot-Ordner verschoben werden
UB_STAGE="${UB_STAGE:-/run/$UB_NAME-stage}"

# Testhilfen - im Betrieb nie setzen. Erlauben einen Probelauf gegen einen
# nachgebauten Verzeichnisbaum statt gegen das echte System.
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
warn() { WARNINGS=$((WARNINGS+1)); _log_line "$(_ts)  WARNUNG: $*"; }
err()  { ERRORS=$((ERRORS+1));     _log_line "$(_ts)  FEHLER: $*"; }

# ub_notify <betreff> <kurztext> [normal|warning|alert] [langtext]
# Datenordner anlegen und abschliessen (0700: das Office liest ihn ueber seinen
# Agent als root, der Web-Container kommt nicht heran)
ub_data_dirs() {
    mkdir -p "$UB_STATE" "$UB_LOGS" "$UB_DUMPS" || return 1
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

# Ganzzahl-Pruefung ohne Ueberraschungen bei leeren Werten
is_uint() { [[ "$1" =~ ^[0-9]+$ ]]; }

# Bytes -> GB (abgerundet)
to_gb() { local b="${1:-0}"; is_uint "$b" || b=0; echo $(( b / 1073741824 )); }

human() { numfmt --to=iec --suffix=B "${1:-0}" 2>/dev/null || echo "${1:-0} B"; }

# Pfad fuer overlayfs-Optionen maskieren (Doppelpunkt, Komma, Backslash)
ovl_escape() { local p="$1"; p="${p//\\/\\\\}"; p="${p//:/\\:}"; p="${p//,/\\,}"; printf '%s' "$p"; }

# Pfad so maskieren, wie /proc/*/mountinfo ihn schreibt
mi_escape() { local p="$1"; p="${p//\\/\\134}"; p="${p// /\\040}"; p="${p//$'\t'/\\011}"; printf '%s' "$p"; }

# Ein Share-Name ist nur dann verwendbar, wenn er Kopia, overlayfs und
# settings.ini nicht durcheinanderbringt.
share_name_ok() { [[ "$1" != *[@:\"\|]* && "$1" != *$'\n'* && -n "$1" ]]; }

##############################################################################
# 2. settings.ini
##############################################################################
# Format
#   [abschnitt]              z.B. [general]
#   [typ "name"]             z.B. [share "appdata"]
#   schluessel = wert        Listen: Schluessel mehrfach angeben
#   # oder ; am Zeilenanfang = Kommentar (keine Kommentare hinter Werten,
#                              damit Muster wie "#recycle" moeglich bleiben)
#
# Intern: CFG["general|mount_root"], CFG["share|appdata|mode"], ...
# Mehrfachwerte sind durch Zeilenumbrueche getrennt.

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
                CFG_ERRORS+=( "Zeile $n: Eintrag steht vor dem ersten [Abschnitt]" ); continue
            fi
            key="$sec|${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
            if [[ -n "${CFG[$key]:-}" ]]; then CFG[$key]+=$'\n'"$val"; else CFG[$key]="$val"; fi
            continue
        else
            CFG_ERRORS+=( "Zeile $n: nicht verstanden: $line" ); continue
        fi
        if [[ -z "${seen[$sec]:-}" ]]; then seen[$sec]=1; CFG_SECTIONS+=( "$sec" ); fi
    done <"$file"
    return 0
}

# cfg <schluessel> [vorgabe]  -> letzter Wert (bei Einzelwerten gewinnt der letzte)
cfg() {
    local k="$1"
    if [[ -n "${CFG[$k]+x}" && -n "${CFG[$k]}" ]]; then printf '%s' "${CFG[$k]##*$'\n'}"
    else printf '%s' "${2-}"; fi
}
# cfg_list <schluessel>  -> alle Werte, einer pro Zeile, leere weggelassen
cfg_list() {
    local k="$1" v
    [[ -n "${CFG[$k]:-}" ]] || return 0
    while IFS= read -r v; do [[ -n "$v" ]] && printf '%s\n' "$v"; done <<<"${CFG[$k]}"
    return 0
}
# cfg_names <typ>  -> Namen aller [typ "name"]-Abschnitte
cfg_names() {
    local t="$1" s
    for s in "${CFG_SECTIONS[@]}"; do [[ "$s" == "$t|"* ]] && printf '%s\n' "${s#*|}"; done
    return 0
}
cfg_has() { local s; for s in "${CFG_SECTIONS[@]}"; do [[ "$s" == "$1" ]] && return 0; done; return 1; }
sec_display() { if [[ "$1" == *"|"* ]]; then printf '[%s "%s"]' "${1%%|*}" "${1#*|}"; else printf '[%s]' "$1"; fi; }

declare -gA UB_SCHEMA=(
    [general]="server mount_root view_root snap_prefix btrfs_snap_dir keep_runs keep_logs min_free_gb keep_mounts notify_success"
    [zfs]="retention"
    [btrfs]="keep_days min_free_gb snapshot_all"
    [drift]="ignore remind_days"
    [docker]="stop no_stop stop_timeout known"
    [flash]="mode tar_exclude kopia_ignore"
    [kopia]="enabled container identity keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual compression ignore"
    [nextcloud]="preexisting_maintenance"
    [dump]="type"
    [share]="mode method retention kopia_retention kopia_ignore exclude_dataset id locations note"
)

# Prueft Abschnitte, Schluessel und Werte. Fehler landen in CFG_ERRORS.
cfg_validate() {
    local k sec typ key allowed v
    for k in "${!CFG[@]}"; do
        sec="${k%|*}"; key="${k##*|}"; typ="${sec%%|*}"
        allowed="${UB_SCHEMA[$typ]:-}"
        if [[ -z "$allowed" ]]; then
            CFG_ERRORS+=( "Unbekannter Abschnitt [$typ]" ); continue
        fi
        if [[ " $allowed " != *" $key "* ]]; then
            CFG_ERRORS+=( "Unbekannter Schluessel '$key' in $(sec_display "$sec")" ); continue
        fi
    done
    _val() { # _val <schluessel> <regex> <beschreibung>
        local v; v="$(cfg "$1")"
        [[ -z "$v" || "$v" =~ $2 ]] || CFG_ERRORS+=( "$1 = '$v' ist ungueltig ($3)" )
    }
    _val "general|keep_runs"     '^[0-9]+$'                 "Zahl"
    _val "general|keep_logs"     '^[0-9]+$'                 "Zahl"
    _val "general|min_free_gb"   '^[0-9]+$'                 "Zahl"
    _val "general|keep_mounts"   '^(yes|no)$'               "yes/no"
    _val "general|notify_success" '^(yes|no)$'              "yes/no"
    _val "general|snap_prefix"   '^[a-z0-9_]+-$'            "Kleinbuchstaben/Ziffern, endet auf -"
    _val "general|mount_root"    "^$UB_MNT/[^/]+$"          "direkt unter $UB_MNT"
    _val "general|view_root"     "^$UB_MNT/[^/]+$"          "direkt unter $UB_MNT"
    _val "zfs|retention"         '^[0-9]+ [0-9]+ [0-9]+$'   "drei Zahlen: taeglich woechentlich monatlich"
    _val "btrfs|keep_days"       '^[0-9]+$'                 "Zahl"
    _val "btrfs|min_free_gb"     '^[0-9]+$'                 "Zahl"
    _val "btrfs|snapshot_all"    '^(yes|no)$'               "yes/no"
    _val "drift|remind_days"     '^[0-9]+$'                 "Zahl"
    _val "docker|stop"           '^(all|none)$'             "all/none"
    _val "docker|stop_timeout"   '^[0-9]+$'                 "Zahl"
    _val "flash|mode"            '^(snapshot|tar|off)$'     "snapshot/tar/off"
    _val "kopia|enabled"         '^(yes|no)$'               "yes/no"
    for key in keep_latest keep_hourly keep_daily keep_weekly keep_monthly keep_annual; do
        _val "kopia|$key" '^([0-9]+|inherit)$' "Zahl oder inherit"
    done
    local n
    while IFS= read -r n; do
        [[ -n "$n" ]] && _val "nextcloud|$n|preexisting_maintenance" '^(abort|continue)$' "abort/continue"
    done < <(cfg_names nextcloud)
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        _val "dump|$n|type" '^(mariadb|postgres|mongodb)$' "mariadb/postgres/mongodb"
        [[ -z "$(cfg "dump|$n|type")" ]] && CFG_ERRORS+=( "[dump \"$n\"] ohne type" )
    done < <(cfg_names dump)
    while IFS= read -r n; do
        [[ -z "$n" ]] && continue
        share_name_ok "$n" || CFG_ERRORS+=( "Share-Name '$n' enthaelt @ : \" oder | - nicht unterstuetzt" )
        _val "share|$n|mode"      '^(kopia|snapshot|off)$'  "kopia/snapshot/off"
        _val "share|$n|method"    '^(auto|live)$'           "auto/live"
        _val "share|$n|retention" '^[0-9]+ [0-9]+ [0-9]+$'  "drei Zahlen"
        _val "share|$n|kopia_retention" '^([0-9]+|inherit)( ([0-9]+|inherit)){5}$' "sechs Werte: latest hourly daily weekly monthly annual"
        [[ -z "$(cfg "share|$n|mode")" ]] && CFG_ERRORS+=( "[share \"$n\"] ohne mode" )
    done < <(cfg_names share)
    unset -f _val
    [[ ${#CFG_ERRORS[@]} -eq 0 ]]
}

# Laedt settings.ini in feste Variablen. Rueckgabe 1, wenn die Datei fehlt.
load_settings() {
    cfg_load "$UB_SETTINGS" || return 1
    cfg_validate
    apply_settings
    return 0
}

# Uebernimmt CFG in Variablen (auch ohne Datei aufrufbar -> Vorgaben)
apply_settings() {
    SERVER_NAME="$(cfg "general|server" "$(hostname -s 2>/dev/null || echo unraid)")"
    MOUNT_ROOT="$(cfg "general|mount_root" "$UB_MNT/backup-snapshots")"
    VIEW_ROOT="$(cfg "general|view_root" "$UB_MNT/btrfs-snap")"
    SNAP_PREFIX="$(cfg "general|snap_prefix" "ub-")"
    BTRFS_SNAP_DIR="$(cfg "general|btrfs_snap_dir" ".btrfs-snap")"
    KEEP_RUNS="$(cfg "general|keep_runs" 14)"
    KEEP_LOGS="$(cfg "general|keep_logs" 60)"
    MIN_FREE_GB="$(cfg "general|min_free_gb" 8)"
    KEEP_MOUNTS="$(cfg "general|keep_mounts" no)"
    NOTIFY_SUCCESS="$(cfg "general|notify_success" yes)"

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

in_list() { # in_list <wert> <eintrag...>
    local x="$1"; shift
    local e; for e in "$@"; do [[ "$e" == "$x" ]] && return 0; done; return 1
}

matches_any() { # matches_any <wert> <glob...>
    local x="$1"; shift
    local g
    for g in "$@"; do
        # shellcheck disable=SC2053  # Glob-Vergleich ist hier gewollt
        [[ -n "$g" && "$x" == $g ]] && return 0
    done
    return 1
}

##############################################################################
# 3. Inventar
##############################################################################

# --- Mounttabelle --------------------------------------------------------
declare -ga MT_TARGET=() MT_SOURCE=() MT_FSTYPE=() MT_OPTS=()
declare -gA MT_SET=()
# Testhilfe: UB_TEST_FSMAP = Datei mit Zeilen "ziel typ quelle"; ueberschreibt
# Typ und Quelle dieser Mounts (Probelauf ohne echtes ZFS/btrfs)
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

# mount_of <pfad>  -> MO_IDX = Index des Mounts, auf dem der Pfad liegt
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

# Alle Mountpunkte unterhalb eines Pfades (tiefste zuerst)
mounts_below() {
    local root="$1" t
    mounts_load
    for t in "${MT_TARGET[@]}"; do
        [[ "$t" == "$root/"* ]] && printf '%s\n' "$t"
    done | awk '{print length($0) "\t" $0}' | sort -rn | cut -f2- | awk '!seen[$0]++'
}

# --- ZFS-Datasets (ein einziger zfs-Aufruf) -------------------------------
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

# --- Basen (Pools und Array-Disks) und Shares -----------------------------
#   INV_BASES                 Namen, Pools alphabetisch, dann disk1..N
#   INV_BASE_PATH/FS/KIND/SRC  je Basis
#   INV_SHARES                Share-Namen
#   INV_LOCS[s]               Zeilen "basis|methode|layer|unterpfad"
#                             methode: zfs | btrfs | live
#                             layer:   ZFS-Dataset bzw. btrfs-Wurzel
#                             unterpfad: "" = Share ist selbst das Dataset
#   INV_CHILDREN[s]           Zeilen "basis|dataset|mountpoint" (Kind-Datasets)
#   INV_METHOD[s]             snap | live | none
#   INV_LAYOUT[s]             single | overlay | split | live | none
#   INV_ID[s]                 zfs:<guid> oder ino:<basis>:<inode>  (fuer Umbenennungen)
#   INV_GB[s]                 Groesse in GB, wenn per ZFS sofort bekannt, sonst leer
#   INV_NOTE[s]               Hinweise (eine Zeile pro Hinweis)
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

    # --- Share-Namen: Verzeichnisse auf allen Basen + Unraid-Share-Configs
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

    # --- Lage jedes Shares
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
                        m="live"; INV_NOTE[$s]+="Dataset $layer ist verschluesselt und gesperrt"$'\n'
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
                        INV_NOTE[$s]+="$p ist ein eigener Mount - nicht im Disk-Snapshot"$'\n'
                    elif [[ "$(stat -c %i "$p" 2>/dev/null)" == "256" ]]; then
                        INV_NOTE[$s]+="$p ist ein eigenes btrfs-Subvolume - nicht im Disk-Snapshot"$'\n'
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
                    INV_NOTE[$s]+="$b ist $mfs - keine Snapshots moeglich"$'\n'
            fi
            if [[ -z "${INV_ID[$s]}" ]]; then
                ino="$(stat -c %i "$p" 2>/dev/null)"
                INV_ID[$s]="ino:$b:${ino:-?}"
            fi
            INV_LOCS[$s]+="$b|$m|$layer|$sub"$'\n'

            # Kind-Datasets: alles, was unterhalb des Share-Pfades gemountet ist
            if [[ "$m" == "zfs" && "$HAVE_ZFS" == "yes" ]]; then
                for ds in "${!ZDS_MP[@]}"; do
                    cmp="${ZDS_MP[$ds]}"
                    [[ "$cmp" == "$p/"* ]] || continue
                    [[ "${ZDS_CAN[$ds]}" == "on" ]] || continue
                    [[ "${ZDS_KEY[$ds]}" == "unavailable" ]] && continue
                    [[ -n "${MT_SET[$cmp]+x}" ]] || continue      # nicht gemountet
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

# Basen eines Shares als Text "cache, disk5"
inv_locnames() {
    local s="$1" l out=""
    while IFS='|' read -r l _; do [[ -n "$l" ]] && out+="${out:+, }$l"; done <<<"${INV_LOCS[$s]:-}"
    printf '%s' "${out:--}"
}

# Kurzform fuer Tabellen: Pools mit Namen, mehrere Array-Disks gezaehlt
#   "cache"  |  "cache + 3 Disks"  |  "disk4"  |  "cache, nvme"
inv_locnames_short() {
    local s="$1" b out="" ndisk=0 onedisk=""
    while IFS='|' read -r b _; do
        [[ -z "$b" ]] && continue
        if [[ "${INV_BASE_KIND[$b]:-}" == "disk" ]]; then ndisk=$((ndisk+1)); onedisk="$b"
        else out+="${out:+, }$b"; fi
    done <<<"${INV_LOCS[$s]:-}"
    if (( ndisk == 1 )); then out+="${out:+, }$onedisk"
    elif (( ndisk > 1 )); then out+="${out:+ + }$ndisk Disks"; fi
    printf '%s' "${out:--}"
}

inv_has_share() { [[ -n "${INV_METHOD[$1]+x}" ]]; }

# inv_measure <share> <timeout>  -> GB, -1 bei Zeitueberschreitung
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
#   FLASH_FS, FLASH_DATASET (wenn ZFS)
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

# --- Container -------------------------------------------------------------
#   CT_NAMES             alle Container
#   CT_IMAGE[n] CT_RUNNING[n] (true/false) CT_NET[n] CT_ID[n]
#   CT_BINDS[n]          Zeilen "quelle|ziel|rw"
#   CT_VOLUMES[n]        Zeilen "volume|ziel"
#   CT_HEALTH[n]         "" oder healthcheck-Status
#   CT_ENVKEYS[n]        Namen der Umgebungsvariablen (nur Namen, nie Werte)
#   CT_PORTS[n]          freigegebene Ports, z.B. "5432/tcp 8080/tcp"
#   CT_PROJECT[n] CT_SERVICE[n]   Compose-Stack und -Dienst (leer bei Einzel-Containern)
declare -ga CT_NAMES=()
declare -gA CT_IMAGE=() CT_RUNNING=() CT_NET=() CT_ID=() CT_BINDS=() CT_VOLUMES=() CT_HEALTH=()
declare -gA CT_ENVKEYS=() CT_PORTS=() CT_PROJECT=() CT_SERVICE=()

docker_load() {
    CT_NAMES=(); CT_IMAGE=(); CT_RUNNING=(); CT_NET=(); CT_ID=(); CT_BINDS=(); CT_VOLUMES=(); CT_HEALTH=()
    CT_ENVKEYS=(); CT_PORTS=(); CT_PROJECT=(); CT_SERVICE=()
    local ids n img run net id binds vols health envk ports proj svc
    mapfile -t ids < <(docker ps -aq 2>/dev/null)
    [[ ${#ids[@]} -eq 0 ]] && return 0
    # Feldtrenner \x1e statt Tab: bash fasst aufeinanderfolgende Tabs zusammen,
    # ein leeres Feld (z.B. kein Healthcheck) wuerde alle folgenden verschieben
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

# Name eines Containers zu einer ID oder einem Namen
ct_resolve() {
    local x="$1" n
    for n in "${CT_NAMES[@]}"; do
        [[ "$n" == "$x" || "${CT_ID[$n]}" == "$x"* ]] && { printf '%s' "$n"; return 0; }
    done
    return 1
}

# Datenbank erkennen - an drei Merkmalen, weil Image-Namen frei waehlbar sind:
#   1. Umgebungsvariablen, die das offizielle Server-Image selbst setzt
#      (PG_MAJOR, MARIADB_VERSION, MONGO_VERSION, REDIS_VERSION ...) - die erben
#      auch umbenannte oder abgeleitete Images
#   2. der Image-Name (mariadb, postgres, pgvecto-rs, mongo, redis ...)
#   3. der Standard-Port (3306, 5432, 27017, 6379)
# Werkzeuge wie phpMyAdmin, Adminer oder Exporter werden nicht mitgezaehlt.
# db_detect <image> <env-namen> <ports>  ->  "art|merkmal"
#   art: mariadb | postgres | mongodb  (Dump moeglich)
#        cache                         (Redis & Co. - Zwischenspeicher, kein Dump)
#        other                         (InfluxDB, CouchDB ... - Sicherung ueber den Snapshot)
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
# Datenbank-Art eines Containers (ohne Merkmal), leer = keine Datenbank
ct_db_type() { local r; r="$(ct_db "$1")"; printf '%s' "${r%%|*}"; }

is_nextcloud_image() {
    local img="${1,,}"
    [[ "$img" == *nextcloud* && "$img" != *aio-mastercontainer* && "$img" != *db* \
       && "$img" != *redis* && "$img" != *postgres* && "$img" != *mariadb* ]]
}

# --- Compose-Stacks ----------------------------------------------------------
# Liest die Stacks des Compose Managers (auch solche, die gerade nicht laufen)
# und meldet ihre Datenbank-Dienste. Ausgewertet wird, was "docker compose
# config" daraus macht - also inklusive .env und Variablen.
#   COMPOSE_DB  Zeilen "stack|dienst|image|art|merkmal|container_name"
#   COMPOSE_ERR Zeilen "stack|grund" (Stack nicht auswertbar)
declare -ga COMPOSE_DB=() COMPOSE_ERR=()
compose_scan() {
    COMPOSE_DB=(); COMPOSE_ERR=()
    local root="$UB_BOOT/config/plugins/compose.manager/projects" d dir f js stack svc img envk ports cname r
    [[ -d "$root" ]] || return 0
    docker compose version >/dev/null 2>&1 || { COMPOSE_ERR+=( "*|docker compose fehlt" ); return 0; }
    for d in "$root"/*/; do
        [[ -d "$d" ]] || continue
        stack="$(basename "$d")"; dir="${d%/}"
        # "indirect": der Stack liegt in einem anderen Ordner
        [[ -s "$dir/indirect" ]] && dir="$(head -1 "$dir/indirect" | tr -d '\r')"
        f=""
        for r in compose.yaml compose.yml docker-compose.yml docker-compose.yaml; do
            [[ -f "$dir/$r" ]] && { f="$dir/$r"; break; }
        done
        [[ -n "$f" ]] || { COMPOSE_ERR+=( "$stack|keine Compose-Datei in $dir" ); continue; }
        js="$(cd "$dir" && timeout 30 docker compose --project-directory "$dir" -f "$f" config --format json 2>/dev/null)" \
            || { COMPOSE_ERR+=( "$stack|docker compose config scheitert (fehlende Variablen?)" ); continue; }
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

# Container eines Compose-Dienstes finden (Label oder container_name)
compose_container() { # compose_container <stack> <dienst> <container_name>
    local n
    if [[ -n "$3" ]] && in_list "$3" "${CT_NAMES[@]}"; then printf '%s' "$3"; return 0; fi
    for n in "${CT_NAMES[@]}"; do
        [[ "${CT_SERVICE[$n]}" == "$2" && ( "${CT_PROJECT[$n]}" == "$1" || "${CT_PROJECT[$n]}" == "${1,,}" ) ]] \
            && { printf '%s' "$n"; return 0; }
    done
    return 1
}

is_kopia_image() { [[ "${1,,}" == *kopia* ]]; }

##############################################################################
# 4. Plan
##############################################################################
#   PLAN_KOPIA       Shares, die an Kopia gehen (mode=kopia und Daten vorhanden)
#   PLAN_SNAP        Shares mit mode=kopia|snapshot und Snapshot-Methode
#   PLAN_ZFS         Datasets, die gesnapshottet werden
#   PLAN_ZFS_RET[ds] Aufbewahrung "t w m" (Maximum ueber alle Shares)
#   PLAN_BTRFS       Basen (Pfade), die einen btrfs-Snapshot bekommen
#   PLAN_EXCL[ds]    1 = Kind-Dataset bewusst ausgeschlossen
#   PLAN_FLASH       snapshot | tar | off  (tatsaechlich wirksam)
declare -ga PLAN_KOPIA=() PLAN_SNAP=() PLAN_ZFS=() PLAN_BTRFS=()
declare -gA PLAN_ZFS_RET=() PLAN_EXCL=()
PLAN_FLASH="off"

_ret_max() { # _ret_max "a b c" "x y z"
    local a1 a2 a3 b1 b2 b3
    read -r a1 a2 a3 <<<"$1"; read -r b1 b2 b3 <<<"$2"
    echo "$(( a1 > b1 ? a1 : b1 )) $(( a2 > b2 ? a2 : b2 )) $(( a3 > b3 ? a3 : b3 ))"
}

share_mode()      { cfg "share|$1|mode" "off"; }
share_retention() { cfg "share|$1|retention" "$ZFS_RETENTION"; }
share_method()    { # effektive Methode
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
        # mode=kopia bei ausgeschaltetem Kopia = nur lokaler Snapshot
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

_parent_excluded() { # liegt das Dataset unter einem ausgeschlossenen?
    local ds="$1"; shift
    local e; for e in "$@"; do [[ -n "$e" && "$ds" == "$e/"* ]] && return 0; done; return 1
}

# Kind-Datasets eines Shares, die gemountet werden (ohne ausgeschlossene)
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
#   KM_SRC/KM_DST/KM_RW/KM_PROP   Bind-Mounts des Kopia-Containers
#   KOPIA_RUNNING                 yes/no
#   KOPIA_CONNECTED               yes/no
#   KOPIA_ID                      benutzer@host laut Repository-Config
#   KOPIA_USER, KOPIA_HOST
declare -ga KM_SRC=() KM_DST=() KM_RW=() KM_PROP=()
KOPIA_RUNNING="no"; KOPIA_CONNECTED="no"; KOPIA_ID=""; KOPIA_USER=""; KOPIA_HOST=""
KOPIA_VERSION=""; KOPIA_CONFIG_FILE=""; KOPIA_STORAGE=""; KP_JSON="[]"
KOPIA_SERVER_UID=""     # UID, unter der der Kopia-Server im Container laeuft
KOPIA_RUN_UID="0"       # UID fuer docker exec (= Server-UID, damit Cache/Logs ihm gehoeren)

# Kopia im Container aufrufen - immer als derselbe Benutzer wie der Server.
# Jeder Kopia-Aufruf (auch "repository status") schreibt in Cache und Logs.
# Liefe er als root, waehrend der Server z.B. als UID 99 laeuft, entstuenden
# root-eigene Cache-Ordner (0700) - der Server kann das Repository danach
# nicht mehr oeffnen ("permission denied"). Snapshots brauchen aber root, um
# alle Dateien lesen zu koennen -> der Server muss ebenfalls als root laufen.
kopia_x() {
    local e=()
    [[ "$KOPIA_RUN_UID" != "0" ]] && e=( -e HOME=/tmp )
    docker exec -u "$KOPIA_RUN_UID" "${e[@]}" "$KOPIA_CONTAINER" kopia --no-progress "$@"
}

# UID des Kopia-Servers: aus der Prozessliste, sonst Besitzer der Config-Datei
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

# Kopia-Container automatisch finden, wenn settings.ini keinen nennt
kopia_find_container() {
    local n found=""
    for n in "${CT_NAMES[@]}"; do
        is_kopia_image "${CT_IMAGE[$n]}" || continue
        [[ -n "$found" ]] && { printf '%s' "$found"; return 2; }   # mehrere -> erster, aber melden
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

# k_map <hostpfad>  -> KMAP_IDX (Mapping, das den Pfad abdeckt), -1 wenn keins
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

# k_path <hostpfad>  -> Pfad im Container (Rueckgabe 1, wenn nicht abgebildet)
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

# Host-Pfad, unter dem Kopia einen Share liest - immer <mount_root>/<share>,
# auch bei "live" (dann ein read-only-Bind von /mnt/user/<share>)
share_kopia_hostpath() { printf '%s' "$MOUNT_ROOT/$1"; }
FLASH_SOURCE_NAME="_flash"

# Soll-Ignoreliste eines Ziels
#   root    -> globale Liste aus [kopia] (wird an alle Shares vererbt)
#   share   -> nur die Share-eigenen Regeln
kopia_want_ignores() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)  printf '%s\n' "${KOPIA_IGNORE[@]}" ;;
        share) cfg_list "share|$s|kopia_ignore" ;;
        flash) printf '%s\n' "${FLASH_KOPIA_IGNORE[@]}" ;;
    esac | sed '/^$/d' | LC_ALL=C sort -u
}

# Soll-Aufbewahrung eines Ziels: sechs Werte (latest hourly daily weekly monthly annual)
kopia_want_retention() {
    local kind="$1" s="${2:-}"
    case "$kind" in
        root)  echo "$KOPIA_KEEP_LATEST $KOPIA_KEEP_HOURLY $KOPIA_KEEP_DAILY $KOPIA_KEEP_WEEKLY $KOPIA_KEEP_MONTHLY $KOPIA_KEEP_ANNUAL" ;;
        share) cfg "share|$s|kopia_retention" "inherit inherit inherit inherit inherit inherit" ;;
        *)     echo "inherit inherit inherit inherit inherit inherit" ;;
    esac
}

# kopia_policy_eval <container-pfad> <art: root|share|flash> <soll-ignores> <soll-aufbewahrung>
#   Vergleicht die in Kopia gespeicherte Policy mit dem Soll. Nur das Ziel
#   "root" (<mount_root>) traegt Zeitplan, one-file-system und Kompression;
#   die Shares erben das und tragen nur ihre Abweichungen.
#   KP_DIFF      Textzeilen mit den Abweichungen
#   KP_ARGS      Argumente fuer "kopia policy set", die das Soll herstellen
#   KP_MISSING   Ignore-Regeln, die in Kopia fehlen (Kopia saehe mehr als gewollt)
#   Rueckgabe 0 = stimmt, 1 = weicht ab
kopia_policy_eval() {
    local target="$1" kind="$2" want_ign="$3" want_ret="$4" cur cur_ign x f want have
    local -a wr
    read -r -a wr <<<"$want_ret"
    KP_DIFF=""; KP_ARGS=(); KP_MISSING=""
    cur="$(jq -c --arg p "$target" --arg u "$KOPIA_USER" --arg h "$KOPIA_HOST" \
            'first(.[] | select(.target.path==$p and .target.userName==$u and .target.host==$h)) // {}' \
            <<<"$KP_JSON")"
    cur_ign="$(jq -r '.files.ignore[]?' <<<"$cur" | LC_ALL=C sort -u)"
    while IFS= read -r x; do
        [[ -z "$x" ]] && continue
        if ! grep -Fxq -- "$x" <<<"$cur_ign"; then
            KP_DIFF+="  Ignore fehlt in Kopia: $x"$'\n'; KP_ARGS+=( --add-ignore "$x" ); KP_MISSING+="$x"$'\n'
        fi
    done <<<"$want_ign"
    while IFS= read -r x; do
        [[ -z "$x" ]] && continue
        if ! grep -Fxq -- "$x" <<<"$want_ign"; then
            KP_DIFF+="  Ignore zusaetzlich in Kopia: $x"$'\n'; KP_ARGS+=( --remove-ignore "$x" )
        fi
    done <<<"$cur_ign"
    local i=0
    for f in keepLatest:keep-latest keepHourly:keep-hourly keepDaily:keep-daily \
             keepWeekly:keep-weekly keepMonthly:keep-monthly keepAnnual:keep-annual; do
        x="${f%%:*}"; f="${f#*:}"; want="${wr[$i]:-inherit}"; i=$((i+1))
        have="$(jq -r --arg k "$x" '.retention[$k] // "inherit" | tostring' <<<"$cur")"
        if [[ "$have" != "$want" ]]; then
            KP_DIFF+="  $f: Kopia $have, Soll $want"$'\n'; KP_ARGS+=( "--$f=$want" )
        fi
    done
    if [[ "$kind" == "root" ]]; then
        have="$(jq -r '.scheduling.manual // false | tostring' <<<"$cur")"
        if [[ "$have" != "true" ]]; then
            KP_DIFF+="  Zeitplan: Kopia darf diese Quellen selbst planen (Soll: nur manuell)"$'\n'
            KP_ARGS+=( --manual )
        fi
        # Achtung jq: "//" haelt auch false fuer "fehlt" - darum explizit auf null pruefen
        have="$(jq -r 'if .files.oneFileSystem == null then "inherit" else (.files.oneFileSystem|tostring) end' <<<"$cur")"
        if [[ "$have" != "false" ]]; then
            KP_DIFF+="  one-file-system: Kopia $have, Soll false (Kind-Datasets muessen mit)"$'\n'
            KP_ARGS+=( --one-file-system=false )
        fi
        have="$(jq -r '.compression.compressorName // "inherit"' <<<"$cur")"
        if [[ "$KOPIA_COMPRESSION" != "inherit" && "$have" != "$KOPIA_COMPRESSION" ]] || \
           [[ "$KOPIA_COMPRESSION" == "inherit" && "$have" != "inherit" ]]; then
            KP_DIFF+="  Kompression: Kopia $have, Soll $KOPIA_COMPRESSION"$'\n'
            KP_ARGS+=( "--compression=$KOPIA_COMPRESSION" )
        fi
    fi
    [[ -z "$KP_DIFF" ]]
}

# Alle Kopia-Ziele, die dieses Script verwaltet, mit Art:
#   Zeilen "art|hostpfad|share"   art: root | share | flash
kopia_targets() {
    local s
    is_yes "$KOPIA_ENABLED" || return 0
    printf 'root|%s|\n' "$MOUNT_ROOT"
    for s in "${PLAN_KOPIA[@]}"; do printf 'share|%s|%s\n' "$(share_kopia_hostpath "$s")" "$s"; done
    [[ "$PLAN_FLASH" == "snapshot" ]] && printf 'flash|%s|\n' "$MOUNT_ROOT/$FLASH_SOURCE_NAME"
    return 0
}

# Alle Quellen im Repository (benutzer@host:pfad), je eine Zeile
kopia_sources() {
    kopia_x snapshot list --all --json -n 1 2>/dev/null \
        | jq -r '.[]? | "\(.source.userName)@\(.source.host):\(.source.path)"' 2>/dev/null | LC_ALL=C sort -u
}

# Mountinfo im Container -> KMI[pfad]=optionen
declare -gA KMI=()
kopia_mountinfo_load() {
    KMI=()
    local mp opts
    while IFS=$'\t' read -r mp opts; do
        [[ "$mp" == *\\* ]] && mp="$(printf '%b' "$mp")"
        KMI[$mp]="$opts"
    done < <(docker exec "$KOPIA_CONTAINER" cat /proc/self/mountinfo 2>/dev/null | awk '{print $5 "\t" $6}')
}

# Probe: sieht der laufende Container einen Mount, der jetzt auf dem Host entsteht?
#   0 = ja, 1 = nein, 2 = Probe nicht moeglich
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

# Hat ein frueherer Lauf hart abgebrochen (kill -9, Absturz), stehen in state/
# die Container, die er angehalten, und die Nextclouds, die er in den
# Wartungsmodus gesetzt hatte. Beides wird hier zurueckgeholt.
# Aufruf nur mit gehaltener Sperre (dann laeuft kein anderer Lauf).
recover_interrupted_run() {
    local n occ u list=""
    if [[ -s "$UB_STATE/stopped" ]]; then
        while IFS= read -r n; do
            [[ -z "$n" ]] && continue
            [[ "$(docker inspect -f '{{.State.Running}}' "$n" 2>/dev/null)" == "false" ]] || continue
            if docker start "$n" >/dev/null 2>&1; then list+="$n "; else err "Container '$n' (vom abgebrochenen Lauf) startet nicht"; fi
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
                    && list+="Wartungsmodus $n aus " || err "Wartungsmodus von '$n' liess sich nicht ausschalten"
                break
            done
        done <"$UB_STATE/maintenance"
        rm -f "$UB_STATE/maintenance"
    fi
    if [[ -n "$list" ]]; then
        warn "Ein frueherer Lauf wurde abgebrochen - wiederhergestellt: $list"
        ub_notify "Abgebrochener Lauf repariert" "Wieder gestartet bzw. zurueckgesetzt: $list" "warning"
    fi
    return 0
}

##############################################################################
# 6. Abweichungen (Inventar <-> settings.ini)
##############################################################################
#   DRIFT        Zeilen "stufe|text"   stufe: info | warn | error
#   SKIP_KOPIA[share]=grund   Shares, die Kopia diesmal nicht sichern darf
declare -ga DRIFT=()
declare -gA SKIP_KOPIA=()
drift_add() { DRIFT+=( "$1|$2" ); }

drift_check_shares() {
    local s mode meth id loc_now loc_cfg o
    local -A cfg_has=() missing_by_id=() renamed=()
    while IFS= read -r s; do [[ -n "$s" ]] && cfg_has[$s]=1; done < <(cfg_names share)

    # In settings.ini, aber ohne Daten -> Kandidaten fuer "geloescht" oder "umbenannt"
    for s in "${!cfg_has[@]}"; do
        if ! inv_has_share "$s" || [[ "${INV_METHOD[$s]}" == "none" ]]; then
            id="$(cfg "share|$s|id")"
            [[ -n "$id" && "$id" != *":?" ]] && missing_by_id[$id]="$s"
        fi
    done

    # Neu oder umbenannt
    for s in "${INV_SHARES[@]}"; do
        [[ "${INV_METHOD[$s]}" == "none" || -n "${cfg_has[$s]:-}" ]] && continue
        o=""
        [[ -n "${INV_ID[$s]:-}" ]] && o="${missing_by_id[${INV_ID[$s]}]:-}"
        if [[ -n "$o" ]]; then
            renamed[$o]="$s"
            drift_add warn "Share '$o' wurde umbenannt in '$s' (gleiche Kennung ${INV_ID[$s]%%:*}) - '$s' wird erst nach setup.sh gesichert"
            continue
        fi
        matches_any "$s" "${DRIFT_IGNORE[@]}" && continue
        drift_add warn "Neuer Share '$s' ($(inv_locnames "$s")${INV_GB[$s]:+, ${INV_GB[$s]} GB}) - nicht in settings.ini, wird nicht gesichert"
    done

    # Geloescht oder leer
    for s in "${!cfg_has[@]}"; do
        [[ -n "${renamed[$s]:-}" ]] && continue
        mode="$(share_mode "$s")"
        if ! inv_has_share "$s"; then
            # weder Daten noch Unraid-Config
            if [[ "$mode" == "off" ]]; then
                drift_add info "Share '$s' existiert nicht mehr (stand auf off)"
            else
                drift_add warn "Share '$s' existiert nicht mehr (mode=$mode)"
            fi
        elif [[ "${INV_METHOD[$s]}" == "none" && "$mode" != "off" ]]; then
            # nur noch in der Unraid-Config. War er schon beim Setup leer (keine
            # Kennung), ist das nichts Neues - sonst sind die Daten weg.
            if [[ -n "$(cfg "share|$s|id")" ]]; then
                drift_add warn "Share '$s' hat keine Daten mehr (bisher auf $(cfg "share|$s|locations" "?"), mode=$mode)"
            fi
        elif [[ "${INV_METHOD[$s]}" != "none" && "$mode" != "off" && -z "$(cfg "share|$s|id")" ]]; then
            drift_add info "Share '$s' hat jetzt Daten und wird gesichert - setup.sh merkt sich dann seine Kennung (fuer das Erkennen von Umbenennungen)"
        fi
    done

    for s in "${INV_SHARES[@]}"; do
        [[ "${INV_METHOD[$s]}" == "none" || -z "${cfg_has[$s]:-}" ]] && continue
        share_name_ok "$s" || { drift_add warn "Share '$s': Name enthaelt @ : \" oder | - wird nicht gesichert"; continue; }
        mode="$(share_mode "$s")"
        [[ "$mode" == "off" ]] && continue
        meth="$(share_method "$s")"
        loc_now="$(inv_locnames "$s")"; loc_cfg="$(cfg "share|$s|locations")"
        if [[ -n "$loc_cfg" && "$loc_cfg" != "$loc_now" ]]; then
            drift_add info "Share '$s' liegt jetzt auf: $loc_now (laut settings.ini: $loc_cfg) - wird automatisch mitgenommen"
        fi
        if [[ "$meth" == "live" && "$(cfg "share|$s|method" auto)" != "live" ]]; then
            drift_add warn "Share '$s' kann nicht per Snapshot gesichert werden ($(printf '%s' "${INV_NOTE[$s]}" | head -1)) - Kopia liest live"
        fi
        if [[ "$mode" == "snapshot" && "$meth" == "live" ]]; then
            drift_add warn "Share '$s' steht auf mode=snapshot, liegt aber auf einem Dateisystem ohne Snapshots"
        fi
        if [[ "${INV_LAYOUT[$s]}" == "split" ]]; then
            drift_add info "Share '$s' liegt auf mehreren Basen und hat Kind-Datasets - Kopia sieht je Basis einen Unterordner"
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
                drift_add warn "Neuer Datenbank-Container '$n' ($t, $img${CT_PROJECT[$n]:+, Stack ${CT_PROJECT[$n]}}) - kein Dump eingerichtet (Rohdaten sind per Snapshot dabei)"
            elif is_nextcloud_image "$img" && ! cfg_has "nextcloud|$n"; then
                drift_add warn "Neuer Nextcloud-Container '$n' - kein Wartungsmodus eingerichtet"
            else
                drift_add info "Neuer Container '$n' ($img)"
            fi
        fi
        if [[ -n "${CT_VOLUMES[$n]}" && -z "${known[$n]:-}" ]]; then
            drift_add warn "Container '$n' nutzt Docker-Volumes ($(cut -d'|' -f1 <<<"${CT_VOLUMES[$n]}" | paste -sd, -)) - die liegen im Docker-Image und sind NICHT im Backup"
        fi
    done
    for n in $(cfg_names dump) $(cfg_names nextcloud) "${DOCKER_NO_STOP[@]}"; do
        [[ -n "${referenced[$n]:-}" ]] && continue
        referenced[$n]=1
        in_list "$n" "${CT_NAMES[@]}" || drift_add warn "Container '$n' steht in settings.ini, existiert aber nicht"
    done
}

# Kopia-Pruefung fuer den Lauf; setzt KOPIA_OK und SKIP_KOPIA
KOPIA_OK="no"
drift_check_kopia() {
    KOPIA_OK="no"
    is_yes "$KOPIA_ENABLED" || { KOPIA_OK="off"; return 0; }
    [[ ${#PLAN_KOPIA[@]} -eq 0 && "$PLAN_FLASH" != "snapshot" ]] && { KOPIA_OK="none"; return 0; }
    if [[ -z "$KOPIA_CONTAINER" ]]; then
        drift_add error "Kein Kopia-Container in settings.ini"; return 1
    fi
    if ! in_list "$KOPIA_CONTAINER" "${CT_NAMES[@]}"; then
        drift_add error "Kopia-Container '$KOPIA_CONTAINER' existiert nicht"; return 1
    fi
    kopia_mounts_load
    if ! k_map "$MOUNT_ROOT"; then
        drift_add error "Kopia-Container bindet $MOUNT_ROOT nicht ein (erwartet: $MOUNT_ROOT -> $MOUNT_ROOT, Read Only - Slave)"; return 1
    fi
    case "${KM_PROP[$KMAP_IDX]}" in
        slave|rslave|shared|rshared) ;;
        *) drift_add error "Mapping ${KM_SRC[$KMAP_IDX]} im Kopia-Container hat Propagation '${KM_PROP[$KMAP_IDX]:-rprivate}' - neue Snapshot-Mounts bleiben unsichtbar. Access Mode auf 'Read Only - Slave' stellen"
           return 1 ;;
    esac
    [[ "${KM_RW[$KMAP_IDX]}" == "true" ]] && \
        drift_add warn "Mapping ${KM_SRC[$KMAP_IDX]} im Kopia-Container ist beschreibbar - 'Read Only - Slave' empfohlen"
    if ! kopia_status_load; then
        if [[ "$KOPIA_RUNNING" != "yes" ]]; then drift_add error "Kopia-Container '$KOPIA_CONTAINER' laeuft nicht"
        else drift_add error "Kopia ist mit keinem Repository verbunden"; fi
        return 1
    fi
    local uid_ok="yes"
    if [[ "$KOPIA_SERVER_UID" != "0" ]]; then
        drift_add error "Kopia-Server laeuft im Container als UID $KOPIA_SERVER_UID, Snapshots brauchen root (alle Dateien lesen). Ein root-Lauf wuerde den gemeinsamen Cache fuer den Server unlesbar machen - Kopia wird NICHT gestartet. Abhilfe: im Template PUID=0 und PGID=0 setzen"
        uid_ok="no"
    fi
    if [[ -n "$KOPIA_IDENTITY_CFG" && "$KOPIA_IDENTITY_CFG" != "$KOPIA_ID" ]]; then
        drift_add warn "Kopia-Identitaet ist jetzt $KOPIA_ID (settings.ini: $KOPIA_IDENTITY_CFG) - neue Quellen statt Fortsetzung"
    fi
    kopia_policies_load
    local kind hpath share cpath
    while IFS='|' read -r kind hpath share; do
        [[ -z "$kind" ]] && continue
        cpath="$(k_path "$hpath")" || continue
        if ! kopia_policy_eval "$cpath" "$kind" "$(kopia_want_ignores "$kind" "$share")" "$(kopia_want_retention "$kind" "$share")"; then
            # Fehlt eine Share-eigene Ignore-Regel, wuerde Kopia mehr hochladen als
            # gewollt (z.B. eine Blockchain). Diese Quelle bleibt dann aussen vor.
            local miss_own="" x
            if [[ "$kind" == "share" ]]; then
                while IFS= read -r x; do
                    [[ -n "$x" ]] && grep -Fxq -- "$x" <<<"$KP_MISSING" && miss_own+="$x "
                done < <(cfg_list "share|$share|kopia_ignore")
            fi
            if [[ -n "$miss_own" ]]; then
                drift_add error "Kopia-Policy fuer $cpath fehlen Ignore-Regeln ($miss_own) - Share '$share' wird NICHT an Kopia gegeben, bis 'setup.sh --kopia' lief"
                SKIP_KOPIA[$share]="Policy unvollstaendig"
            else
                drift_add warn "Kopia-Policy fuer $cpath weicht von settings.ini ab ('setup.sh --kopia' gleicht an):"$'\n'"${KP_DIFF%$'\n'}"
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

# Fingerabdruck (nur warn/error), damit dieselbe Meldung nicht jede Nacht kommt
drift_fingerprint() {
    printf '%s\n' "${DRIFT[@]}" | grep -E '^(warn|error)\|' | LC_ALL=C sort | md5sum | cut -c1-16
}

drift_text() { # alle Meldungen lesbar, schwerste zuerst
    local lvl l
    for lvl in error warn info; do
        for l in "${DRIFT[@]}"; do
            [[ "${l%%|*}" == "$lvl" ]] || continue
            case "$lvl" in error) printf 'FEHLER  ' ;; warn) printf 'WARNUNG ' ;; info) printf 'INFO    ' ;; esac
            printf '%s\n' "${l#*|}"
        done
    done
}

drift_count() { local lvl="$1" n=0 l; for l in "${DRIFT[@]}"; do [[ "${l%%|*}" == "$lvl" ]] && n=$((n+1)); done; echo "$n"; }

##############################################################################
# 7. Status fuer andere Programme (z.B. Unraid Secretary Office)
##############################################################################
# state/status.json beschreibt den laufenden bzw. zuletzt beendeten Lauf mit
# festen, englischen Schluesseln. Die Texte im Protokoll duerfen sich aendern,
# diese Schnittstelle nicht - wer sie liest, prueft "interface".
#   status.json      laufender oder letzter Lauf (alle Modi ausser unmount)
#   last-run.json    letzter echter Backup-Lauf (kein Trockenlauf, keine Pruefung)
#   history.jsonl    eine Zeile je echtem Backup-Lauf, die letzten 200
#   drift.json       Abweichungen der letzten Pruefung (Stufe + Text)
# Schreiben ist nie kritisch: schlaegt es fehl, laeuft das Backup weiter.
UB_INTERFACE=1
UB_HISTORY_MAX=200

ST_ACTIVE="no"            # erst mit gehaltener Sperre - sonst ueberschriebe ein
ST_MODE=""                # abgewiesener zweiter Start den Status des laufenden
ST_PHASE=""
ST_RESULT="running"       # running | ok | warnings | errors | failed | aborted
ST_MESSAGE=""
ST_STARTED=0
ST_FINISHED=0
ST_ABORTED="no"
ST_KOPIA_PLAN=()          # Namen der Kopia-Quellen in Reihenfolge
ST_KOPIA_CUR=""
ST_KOPIA_CUR_T=0
ST_KOPIA_DONE=()          # Zeilen "name|ok(1/0)|sekunden|ende"
ST_DUMP_BYTES=0

status_init() { # status_init <modus>
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

status_finish() { # status_finish <ergebnis> [meldung]
    [[ "$ST_ACTIVE" == "yes" ]] || return 0
    [[ "$ST_RESULT" == "running" ]] || return 0      # schon abgeschlossen
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

drift_json_write() {
    local tmp="$UB_STATE/.drift.json.$$"
    if printf '%s\n' "${DRIFT[@]}" | jq -R 'select(length > 0) | index("|") as $i
            | {level: .[0:$i], text: .[$i + 1:]}' | jq -sc --argjson t "$(date +%s)" '{time: $t, items: .}' >"$tmp" 2>/dev/null; then
        mv -f "$tmp" "$UB_STATE/drift.json" 2>/dev/null
    else
        rm -f "$tmp"
    fi
    return 0
}
