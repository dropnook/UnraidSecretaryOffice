#!/bin/bash
# =============================================================================
#  consolidate_master.sh  —  Unraid Media Consolidator & Cleaner  (V11.4)
# =============================================================================
#  Führt zersplitterte Medienordner (Film-/Serienordner) auf EINER Array-Disk
#  zusammen, entfernt identische Duplikate und räumt leere Ordner auf.
#
#  AUFRUF
#    ./consolidate_master.sh                 Dryrun (Standard, ändert nichts)
#    ./consolidate_master.sh --run           scharf: verschieben / löschen
#    ./consolidate_master.sh --run --include-cache
#                                            zusätzlich Dateien vom Cache/Pool
#                                            aufs Array holen (sonst macht das
#                                            der Unraid-Mover)
#    ./consolidate_master.sh --dryrun        Dryrun erzwingen (auch wenn in der
#                                            consolidate.ini DRYRUN=false steht)
#    ./consolidate_master.sh --help
#
#  KONFIGURATION  (consolidate.ini im Script-Ordner, per setup_consolidate.sh,
#                  oder die Datei in CONSOLIDATE_CONFIG)
#    BASE_DIRS=(...)     User-Shares, die aufgeräumt werden (/mnt/user/...)
#    LOGFILE             Protokoll (Ordner wird im scharfen Modus angelegt)
#    ARRAY_PATTERN       Glob für Array-Disks, Standard /mnt/disk[0-9]*
#    CACHE_PATTERN       Glob(s) für Cache/Pools, mehrere mit Leerzeichen
#    EXCLUDE_FILE        Textdatei mit Ausnahmen, eine pro Zeile:
#                          - kompletter Dateipfad (/mnt/user/... oder /mnt/diskN/...)
#                          - Ordnerpfad (ganzer Ordner wird ignoriert)
#    DRYRUN              true | false   (alles andere wird abgewiesen)
#    MIN_FREE_GB         Mindest-Freiplatz, der auf einer Ziel-Disk bleiben muss
#    CACHE_ONLY_TARGET   most-free | skip
#                          Ordner, die NUR auf dem Cache liegen (mit --include-cache):
#                          most-free = Array-Disk mit dem meisten freien Platz
#                          skip      = liegen lassen, als "Cache ignoriert" zählen
#    DUP_CHECK           size | cmp
#                          Wann gilt eine zweite Kopie als identisches Duplikat?
#                          size = gleiche Grösse (schnell), cmp = Byte-Vergleich
#                          Ungleiche Kopien werden NIE gelöscht (-> "Konflikt")
#
#  UMGEBUNGSVARIABLEN  (überschreiben die consolidate.ini für einen Lauf)
#    CONSOLIDATE_DRYRUN, CONSOLIDATE_MOVE_CACHE, CONSOLIDATE_MIN_FREE_GB,
#    CONSOLIDATE_CACHE_ONLY_TARGET, CONSOLIDATE_DUP_CHECK, CONSOLIDATE_LOGFILE
#      Beispiel:  CONSOLIDATE_DUP_CHECK=cmp ./consolidate_master.sh --run
#    CONSOLIDATE_CONFIG  Pfad der ini (Standard: consolidate.ini neben dem Script)
#    CONSOLIDATE_STATUS  JSON-Datei, in die am Ende das Ergebnis geschrieben wird
#                        (Modus, Zähler, Exit-Code) – für Programme, die das Script starten;
#                        nach der Indexierung auch "sizes": je Share und Disk/Pool Bytes und
#                        Dateien (aus dem Index, kein zusätzlicher Disk-Zugriff; im scharfen
#                        Lauf nachgeführt), "sizes_at": wann diese Zahlen stimmten
#    CONSOLIDATE_STOP    Datei: sobald es sie gibt, hört das Script nach dem Ordner auf,
#                        an dem es gerade ist (kein Retry, kein Deep Clean; Ergebnis
#                        "stopped", Exit 3) – z.B. wenn jemand Emby zu schauen beginnt
#    CONSOLIDATE_LOCK, CONSOLIDATE_USER_ROOT   Sperrdatei und /mnt/user – nur für Tests
#
#  LEERE ORDNER  werden gelöscht – ausser es gibt denselben Ordner auf einem
#                Cache/Pool: Dann liegt der Inhalt gerade dort (z.B. von EmbyCache
#                bereitgelegt), und der leere Ordner zeigt, auf welche Disk er
#                zurückgehört (beim Split-Level «nur oberste Ebene» folgt Unraid ihm).
#
#  EXIT-CODES   0 = ok, 1 = Konfig-/Startfehler, 2 = Lauf mit Fehlern/Konflikten,
#               3 = auf Wunsch angehalten (CONSOLIDATE_STOP)
# =============================================================================
set -u
shopt -s nullglob

# -----------------------
# 🛠 DEFAULTS
# -----------------------
BASE_DIRS=("/mnt/user/Filme")
LOGFILE="/mnt/user/PlexMedia/consolidate.log"
EXCLUDE_FILE=""
ARRAY_PATTERN="/mnt/disk[0-9]*"
CACHE_PATTERN="/mnt/cache"
DRYRUN=true
MIN_FREE_GB=256
MOVE_CACHE=false
CACHE_ONLY_TARGET="most-free"
DUP_CHECK="size"
LOCKFILE="${CONSOLIDATE_LOCK:-/var/run/consolidate_master.lock}"
MOVER_PIDFILE="/var/run/mover.pid"
USER_ROOT="${CONSOLIDATE_USER_ROOT:-/mnt/user}"

# -----------------------
# 📥 CONFIG LADEN
# -----------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${CONSOLIDATE_CONFIG:-$SCRIPT_DIR/consolidate.ini}"
STATUS_FILE="${CONSOLIDATE_STATUS:-}"
STOP_FILE="${CONSOLIDATE_STOP:-}"
STARTED=$(printf '%(%s)T' -1)
N_FOLDERS=0; N_FOLDERS_DONE=0
N_MOVED=0; N_DUP_DELETED=0; N_IGNORED=0; N_SKIPPED_CACHE=0
N_FULL_FAILED=0; N_RSYNC_ERR=0; N_CONFLICT=0; N_DIRS_DELETED=0; N_DIRS_FAILED=0; N_DIRS_KEPT=0
declare -A SZ_BYTES=() SZ_FILES=()   # "<base>\t<root>" -> Bytes / Dateien (aus dem Index, im scharfen Lauf nachgeführt)
SIZES_DONE=false; SIZES_AT=0

json_str() {                 # $1 -> JSON-String (Anführungszeichen, Backslash, Steuerzeichen)
    local s="$1"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\n'/ }"; s="${s//$'\r'/ }"; s="${s//$'\t'/ }"
    printf '"%s"' "$s"
}
sizes_json() {               # ,"sizes":{"<share>":{"<disk>":{"bytes":n,"files":n}}},"sizes_at":n  (erst nach dem Index)
    $SIZES_DONE || return 0
    local b r k part out=""
    for b in "${BASE_RELS[@]}"; do
        part=""
        for r in "${ALL_ROOTS[@]}"; do
            k="$b"$'\t'"$r"
            [[ -n "${SZ_BYTES[$k]-}" ]] || continue
            part+="${part:+,}$(json_str "${r##*/}"):{\"bytes\":${SZ_BYTES[$k]},\"files\":${SZ_FILES[$k]}}"
        done
        out+="${out:+,}$(json_str "${b#/}"):{$part}"
    done
    [[ "${DRYRUN:-true}" == false ]] && SIZES_AT=$(printf '%(%s)T' -1)    # scharf: die Zahlen stimmen jetzt, nach den Verschiebungen
    printf ',"sizes":{%s},"sizes_at":%d' "$out" "$SIZES_AT"
}
write_status() {             # $1 result (ok|errors|failed|aborted|stopped), $2 exit code, $3 Meldung
    [[ -n "$STATUS_FILE" ]] || return 0
    printf '{"version":"V11.4","mode":%s,"result":%s,"exit":%d,"message":%s,"started":%d,"finished":%d,"moved":%d,"duplicates":%d,"ignored":%d,"cache_skipped":%d,"full":%d,"errors":%d,"conflicts":%d,"dirs_deleted":%d,"dirs_failed":%d,"dirs_kept":%d,"folders":%d,"folders_done":%d%s}\n' \
        "$(json_str "$( [[ "${DRYRUN:-true}" == false ]] && echo run || echo dry )")" "$(json_str "$1")" "$2" "$(json_str "${3:-}")" \
        "$STARTED" "$(printf '%(%s)T' -1)" "$N_MOVED" "$N_DUP_DELETED" "$N_IGNORED" "$N_SKIPPED_CACHE" \
        "$N_FULL_FAILED" "$N_RSYNC_ERR" "$N_CONFLICT" "$N_DIRS_DELETED" "$N_DIRS_FAILED" "$N_DIRS_KEPT" "$N_FOLDERS" "$N_FOLDERS_DONE" "$(sizes_json)" > "$STATUS_FILE.tmp" 2>/dev/null \
        && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"
}

usage() { awk '/^# =+$/ {n++; if (n==3) exit; next} n>=1 {sub(/^# ?/,""); print}' "${BASH_SOURCE[0]}"; }
for arg in "$@"; do [[ "$arg" == "-h" || "$arg" == "--help" ]] && { usage; exit 0; }; done

if [[ -f "$CONFIG_FILE" ]]; then
    # shellcheck source=/dev/null
    source "$CONFIG_FILE"
    echo "ℹ️  Config geladen: $CONFIG_FILE"
else
    echo "⚠️  Keine consolidate.ini in $SCRIPT_DIR – Standardwerte aktiv."
fi

# Umgebungsvariablen überschreiben die ini
for v in DRYRUN MOVE_CACHE MIN_FREE_GB CACHE_ONLY_TARGET DUP_CHECK LOGFILE; do
    ev="CONSOLIDATE_$v"
    if [[ -n "${!ev-}" ]]; then declare "$v=${!ev}"; fi
done

for arg in "$@"; do
    case "$arg" in
        --run)            DRYRUN=false ;;
        --dryrun|--dry-run) DRYRUN=true ;;
        --include-cache)  MOVE_CACHE=true ;;
        -h|--help)        usage; exit 0 ;;
        *) echo "❌ Unbekanntes Argument: $arg (siehe --help)"; exit 1 ;;
    esac
done

die() { echo "❌ $*" >&2; write_status failed 1 "$*"; exit 1; }

# -----------------------
# ✅ KONFIG-VALIDIERUNG
# -----------------------
case "$DRYRUN" in true|false) ;; *) die "DRYRUN muss 'true' oder 'false' sein (ist: '$DRYRUN'). Abbruch." ;; esac
case "$MOVE_CACHE" in true|false) ;; *) die "MOVE_CACHE muss 'true' oder 'false' sein (ist: '$MOVE_CACHE')." ;; esac
case "$CACHE_ONLY_TARGET" in most-free|skip) ;; *) die "CACHE_ONLY_TARGET muss 'most-free' oder 'skip' sein (ist: '$CACHE_ONLY_TARGET')." ;; esac
case "$DUP_CHECK" in size|cmp) ;; *) die "DUP_CHECK muss 'size' oder 'cmp' sein (ist: '$DUP_CHECK')." ;; esac
[[ "$MIN_FREE_GB" =~ ^[0-9]+$ ]] || die "MIN_FREE_GB muss eine ganze Zahl (GB) sein (ist: '$MIN_FREE_GB')."
[[ ${#BASE_DIRS[@]} -gt 0 ]] || die "BASE_DIRS ist leer."

BASE_RELS=()
for base in "${BASE_DIRS[@]}"; do
    base="${base%/}"
    [[ "$base" == "$USER_ROOT/"?* ]] || die "BASE_DIRS-Eintrag '$base' liegt nicht unter $USER_ROOT/."
    if [[ ! -d "$base" ]]; then echo "⚠️  $base fehlt – wird übersprungen (Schreibweise? Array gestartet?)."; continue; fi
    BASE_RELS+=("${base#"$USER_ROOT"}")
done
[[ ${#BASE_RELS[@]} -gt 0 ]] || die "Keines der BASE_DIRS existiert."

is_dry() { [[ "$DRYRUN" == true ]]; }
stop_requested() { [[ -n "$STOP_FILE" && -e "$STOP_FILE" ]]; }    # CONSOLIDATE_STOP: nach dem aktuellen Ordner aufhören
STOPPED=false

# -----------------------
# 📝 LOGGING
# -----------------------
logf() { printf '%(%F %T)T | %s\n' -1 "$*" >> "$LOGFILE"; }      # nur Datei (im Dryrun deaktiviert)
log_console() {                                                  # Aktionszeile
    local icon="$1" src_root="$2" tgt_root="$3" file="$4"
    if [[ -n "$tgt_root" ]]; then
        printf '   %s [%s -> %s] %s\n' "$icon" "${src_root##*/}" "${tgt_root##*/}" "${file##*/}"
    else
        printf '   %s [%s] %s\n' "$icon" "${src_root##*/}" "${file##*/}"
    fi
}
clear_line() { printf '\r\033[K'; }

if is_dry; then
    # Dryrun: nichts wird ins Log geschrieben
    logf() { :; }
else
    # the log grows with every real run: past 5 MB it moves to .1 (one older copy kept) — 2026-10-09
    if [[ -f "$LOGFILE" && $(stat -c %s "$LOGFILE" 2>/dev/null || echo 0) -gt 5242880 ]]; then mv -f -- "$LOGFILE" "$LOGFILE.1" 2>/dev/null; fi
    if ! { mkdir -p "$(dirname "$LOGFILE")" && touch "$LOGFILE"; } 2>/dev/null; then
        die "Logdatei $LOGFILE kann nicht angelegt werden."
    fi
fi

# -----------------------
# 🔒 SPERRE & MOVER
# -----------------------
[[ -d "$(dirname "$LOCKFILE")" && -w "$(dirname "$LOCKFILE")" ]] || LOCKFILE="/tmp/consolidate_master.lock"
exec 9>"$LOCKFILE"
flock -n 9 || die "Es läuft bereits eine Instanz von consolidate_master.sh (Lock: $LOCKFILE)."

mover_running() {
    if [[ -f "$MOVER_PIDFILE" ]] && kill -0 "$(<"$MOVER_PIDFILE")" 2>/dev/null; then return 0; fi
    pgrep -f '(^|/)mover( |$)' >/dev/null 2>&1
}
if mover_running; then
    if is_dry; then
        echo "⚠️  Der Unraid-Mover läuft gerade – Ergebnis dieses Dryruns kann unvollständig sein."
    else
        die "Der Unraid-Mover läuft gerade. Scharfer Lauf abgebrochen (Kollisionsgefahr)."
    fi
fi

trap 'echo; echo "⛔ Abgebrochen."; logf "ABBRUCH durch Signal"; write_status aborted 130 "Signal"; exit 130' INT TERM

# -----------------------
# ⚙️ ZÄHLER (oben bei der Status-Datei angelegt)
# -----------------------
RETRY_SRC=(); RETRY_TGT=(); RETRY_REL=()

declare -A EXCLUDE_MAP=()     # exakte Pfade
EXCLUDE_DIRS=()               # Ordner-Präfixe
declare -A RESERVED_KB=()     # Dryrun: bereits "verplanter" Platz je Disk
declare -A GONE=()            # Dryrun: Dateien, die der Plan entfernt hätte (für Deep-Clean-Simulation)

# -----------------------
# 💽 DISKS AUFLÖSEN
# -----------------------
resolve_roots() {            # $1 = Muster (mehrere durch Leerzeichen)
    local pattern="$1" w p
    pattern="${pattern//\"/}"; pattern="${pattern//\'/}"
    local -a words
    read -r -a words <<< "$pattern"
    for w in "${words[@]}"; do
        for p in $w; do
            [[ -d "$p" ]] && printf '%s\n' "${p%/}"
        done
    done | sort -uV
}

ARRAY_ROOTS=(); CACHE_ROOTS=(); ALL_ROOTS=()
mapfile -t ARRAY_ROOTS < <(resolve_roots "$ARRAY_PATTERN")
mapfile -t CACHE_ROOTS < <(resolve_roots "$CACHE_PATTERN")
[[ ${#ARRAY_ROOTS[@]} -gt 0 ]] || die "Keine Array-Disks gefunden (ARRAY_PATTERN='$ARRAY_PATTERN')."
declare -A IS_ARRAY=()
for r in "${ARRAY_ROOTS[@]}"; do IS_ARRAY["$r"]=1; done
for r in "${CACHE_ROOTS[@]}"; do
    [[ -n "${IS_ARRAY[$r]-}" ]] && die "'$r' ist gleichzeitig Array- und Cache-Pfad – Muster prüfen."
done
ALL_ROOTS=("${ARRAY_ROOTS[@]}" "${CACHE_ROOTS[@]}")
is_array_root() { [[ -n "${IS_ARRAY[$1]-}" ]]; }

# -----------------------
# 📏 SPACE CHECK
# -----------------------
declare -A AVAIL_CACHE=()    # freier Platz je Disk (KB), wird nach jedem Move nachgeführt
AVAIL=0
refresh_avail() {            # $1 Disk – df neu lesen
    local a; a=$(df -k --output=avail "$1" 2>/dev/null | tail -n1); AVAIL_CACHE["$1"]=${a:-0}
}
avail_of() {                 # $1 Disk – Ergebnis in $AVAIL (kein Subshell, Cache bleibt erhalten)
    [[ -n "${AVAIL_CACHE[$1]-}" ]] || refresh_avail "$1"
    AVAIL=${AVAIL_CACHE[$1]}
}

has_enough_space() {         # $1 Ziel-Disk, $2 Bytes
    local target="$1" bytes="$2"
    local reserved=${RESERVED_KB[$target]:-0}
    local need_kb=$(( bytes / 1024 + 1 ))
    local min_kb=$(( MIN_FREE_GB * 1024 * 1024 ))
    avail_of "$target"
    (( AVAIL - reserved - need_kb >= min_kb )) && return 0
    refresh_avail "$target"; avail_of "$target"   # vor "voll" einmal frisch nachmessen
    (( AVAIL - reserved - need_kb >= min_kb ))
}
reserve_space() {            # $1 Disk, $2 Bytes – Dryrun: merken; scharf: Cache nachführen
    local kb=$(( $2 / 1024 + 1 ))
    if is_dry; then RESERVED_KB["$1"]=$(( ${RESERVED_KB[$1]:-0} + kb ))
    else AVAIL_CACHE["$1"]=$(( ${AVAIL_CACHE[$1]:-0} - kb )); fi
    return 0
}

most_free_array_disk() {     # Array-Disk mit dem meisten freien Platz (nach Reserve) -> $MOST_FREE
    local best="" best_kb=-1 r a
    for r in "${ARRAY_ROOTS[@]}"; do
        refresh_avail "$r"; avail_of "$r"; a=$(( AVAIL - ${RESERVED_KB[$r]:-0} ))
        (( a > best_kb )) && { best_kb=$a; best="$r"; }
    done
    MOST_FREE="$best"
}

# -----------------------
# 🚀 PHASE 1: INDEX (im Speicher)
# -----------------------
declare -A IDX=()            # rel_path -> physische Pfade, '\n'-getrennt
declare -A PSIZE=()          # physischer Pfad -> Bytes
declare -A PROOT=()          # physischer Pfad -> Disk-Root
declare -A GROUP_FILES=()    # "/Filme/Film (2020)" -> rel_paths, '\n'-getrennt
GROUP_LIST=()

build_index() {
    echo "========================================"
    echo "🚀 PHASE 1: Indexierung"
    echo "========================================"
    local root base target entry size path rel rest group n=0 t=0 sk sb sf
    local -A seen_group=()
    for root in "${ALL_ROOTS[@]}"; do
        for base in "${BASE_RELS[@]}"; do
            target="$root$base"
            [[ -d "$target" ]] || continue
            ((t++))
            sk="$base"$'\t'"$root"; sb=0; sf=0
            while IFS= read -r -d '' entry; do
                size="${entry%%$'\t'*}"; path="${entry#*$'\t'}"
                rel="${path#"$root"}"
                sb=$(( sb + size )); ((sf++))
                PSIZE["$path"]="$size"
                PROOT["$path"]="$root"
                if [[ -z "${IDX[$rel]-}" ]]; then
                    rest="${rel#"$base/"}"
                    if [[ "$rest" == */* ]]; then
                        group="$base/${rest%%/*}"
                        GROUP_FILES["$group"]+="$rel"$'\n'
                        if [[ -z "${seen_group[$group]-}" ]]; then seen_group["$group"]=1; GROUP_LIST+=("$group"); fi
                    fi
                fi
                IDX["$rel"]+="$path"$'\n'
                ((n++))
            done < <(find "$target" -type f -printf '%s\t%p\0' 2>/dev/null)
            SZ_BYTES["$sk"]=$sb; SZ_FILES["$sk"]=$sf
        done
    done
    [[ $t -gt 0 ]] || die "Keine Ordner auf den Disks gefunden (BASE_DIRS/Schreibweise prüfen)!"
    SIZES_DONE=true; SIZES_AT=$(printf '%(%s)T' -1)
    echo "ℹ️  ${#ALL_ROOTS[@]} Disks (${#ARRAY_ROOTS[@]} Array, ${#CACHE_ROOTS[@]} Cache/Pool), $t Pfade gescannt."
    if [[ $n -eq 0 ]]; then
        echo "⚠️ Index leer. Keine Dateien gefunden (nur leere Ordner?)."
    else
        echo "✅ Index: $n physische Dateien, ${#IDX[@]} eindeutige Pfade, ${#GROUP_LIST[@]} Ordner."
    fi
    if [[ ${#GROUP_LIST[@]} -gt 0 ]]; then
        mapfile -t GROUP_LIST < <(printf '%s\n' "${GROUP_LIST[@]}" | sort)
    fi
    echo "----------------------------------------"
}

# -----------------------
# 🚫 AUSNAHMEN
# -----------------------
load_exclusions() {
    [[ -n "$EXCLUDE_FILE" ]] || return 0
    if [[ ! -f "$EXCLUDE_FILE" ]]; then
        echo "⚠️  Exclude-Datei $EXCLUDE_FILE nicht gefunden – keine Ausnahmen aktiv."
        return 0
    fi
    local line c=0 d=0
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line//$'\r'/}"
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [[ -z "$line" || "$line" == \#* ]] && continue
        if [[ "$line" == */ || -d "$line" ]]; then
            EXCLUDE_DIRS+=("${line%/}"); ((d++))
        else
            EXCLUDE_MAP["$line"]=1; ((c++))
        fi
    done < "$EXCLUDE_FILE"
    echo "ℹ️  Exclude-Liste: $c Dateien, $d Ordner."
}

is_excluded() {              # $1 = Pfad (User- oder physisch)
    local p="$1" d
    [[ -n "${EXCLUDE_MAP[$p]-}" ]] && return 0
    for d in "${EXCLUDE_DIRS[@]}"; do
        [[ "$p" == "$d/"* ]] && return 0
    done
    return 1
}

# -----------------------
# 🔍 DUPLIKAT-PRÜFUNG
# -----------------------
same_content() {             # $1 behaltene Kopie, $2 zweite Kopie
    [[ "${PSIZE[$1]}" == "${PSIZE[$2]}" ]] || return 1
    if [[ "$DUP_CHECK" == cmp ]]; then cmp -s "$1" "$2" || return 1; fi
    return 0
}

remove_duplicate() {         # $1 behaltene Kopie, $2 zu löschende Kopie
    local keep="$1" dup="$2" dup_root="${PROOT[$2]}"
    if same_content "$keep" "$dup"; then
        clear_line
        if is_dry; then
            log_console "🧪🗑️" "$dup_root" "" "$dup"
            ((N_DUP_DELETED++)); GONE["$dup"]=1
        else
            log_console "🗑️" "$dup_root" "" "$dup"
            if rm -f "$dup"; then
                ((N_DUP_DELETED++)); logf "GELÖSCHT (Duplikat von $keep): $dup"; size_shift "${dup#"$dup_root"}" "$dup_root" "" "${PSIZE[$dup]}"
            else
                ((N_RSYNC_ERR++)); logf "ERROR rm: $dup"
            fi
        fi
    else
        clear_line
        log_console "⚠️ KONFLIKT (ungleich, behalten)" "$dup_root" "" "$dup"
        logf "KONFLIKT: $dup (${PSIZE[$dup]} B) != $keep (${PSIZE[$keep]} B) – nichts gelöscht"
        ((N_CONFLICT++))
    fi
}

# -----------------------
# 📁 ZIELORDNER MIT RECHTEN DER QUELLE
# -----------------------
ensure_target_dir() {        # $1 Quell-Root, $2 Ziel-Root, $3 rel. Ordnerpfad
    local src_root="$1" tgt_root="$2" rel="$3" acc="" comp src dst
    local -a comps
    IFS='/' read -r -a comps <<< "${rel#/}"
    for comp in "${comps[@]}"; do
        [[ -z "$comp" ]] && continue
        acc="$acc/$comp"; dst="$tgt_root$acc"; src="$src_root$acc"
        if [[ ! -d "$dst" ]]; then
            mkdir "$dst" || return 1
            if [[ -d "$src" ]]; then
                chown --reference="$src" "$dst" 2>/dev/null
                chmod --reference="$src" "$dst" 2>/dev/null
            fi
        fi
    done
}

# -----------------------
# 📊 GRÖSSEN NACHFÜHREN (scharf)
# -----------------------
size_shift() {               # $1 rel. Pfad, $2 von Disk, $3 auf Disk ('' = gelöscht), $4 Bytes
    is_dry && return 0
    local b k
    for b in "${BASE_RELS[@]}"; do [[ "$1" == "$b/"* ]] && break; b=""; done
    [[ -n "$b" ]] || return 0
    k="$b"$'\t'"$2"
    if [[ -n "${SZ_BYTES[$k]-}" ]]; then SZ_BYTES["$k"]=$(( ${SZ_BYTES[$k]} - $4 )); SZ_FILES["$k"]=$(( ${SZ_FILES[$k]} - 1 )); fi
    if [[ -n "$3" ]]; then
        k="$b"$'\t'"$3"; SZ_BYTES["$k"]=$(( ${SZ_BYTES[$k]:-0} + $4 )); SZ_FILES["$k"]=$(( ${SZ_FILES[$k]:-0} + 1 ))
    fi
}

# -----------------------
# 🚚 VERSCHIEBEN
# -----------------------
execute_move() {             # $1 Quelle, $2 Ziel-Disk, $3 rel_path, $4 retry?
    local src="$1" target_disk="$2" rel_path="$3" is_retry="${4:-false}"
    local src_disk="${PROOT[$src]}" dest="$target_disk$rel_path" size="${PSIZE[$src]}"

    if ! has_enough_space "$target_disk" "$size"; then
        if [[ "$is_retry" == true ]]; then
            ((N_FULL_FAILED++))
            clear_line; echo "   ❌ [VOLL] $src -> $target_disk (MIN_FREE_GB=$MIN_FREE_GB)"
            logf "VOLL: $src -> $target_disk nicht verschoben (MIN_FREE_GB=$MIN_FREE_GB)"
        else
            RETRY_SRC+=("$src"); RETRY_TGT+=("$target_disk"); RETRY_REL+=("$rel_path")
            clear_line; echo "   ⏳ [VOLL-QUEUE] $(basename "$src") -> $(basename "$target_disk")"
        fi
        return 1
    fi

    local icon="🚚"
    [[ "$is_retry" == true ]] && icon="🔄"
    is_dry && icon="🧪$icon"
    clear_line; log_console "$icon" "$src_disk" "$target_disk" "$src"

    if is_dry; then
        reserve_space "$target_disk" "$size"; ((N_MOVED++)); GONE["$src"]=1; return 0
    fi
    if ! ensure_target_dir "$src_disk" "$target_disk" "${rel_path%/*}"; then
        ((N_RSYNC_ERR++)); logf "ERROR mkdir: ${dest%/*}"; return 1
    fi
    if rsync -a --remove-source-files "$src" "$dest"; then
        ((N_MOVED++)); reserve_space "$target_disk" "$size"; logf "VERSCHOBEN: $src -> $dest"; size_shift "$rel_path" "$src_disk" "$target_disk" "$size"
        PROOT["$dest"]="$target_disk"; PSIZE["$dest"]="$size"
        return 0
    else
        ((N_RSYNC_ERR++)); logf "ERROR rsync: $src -> $dest"
        return 1
    fi
}

# -----------------------
# 🎬 EIN ORDNER (FILM / SERIE)
# -----------------------
process_group() {
    local group="$1"
    local -a rels=()
    mapfile -t rels <<< "${GROUP_FILES[$group]%$'\n'}"
    [[ ${#rels[@]} -gt 0 ]] || return 0

    # A. Ziel-Disk: Array-Disk mit den meisten Bytes dieses Ordners (physische Grössen)
    local -A tally=()
    local rel p r target_disk="" max=0
    for rel in "${rels[@]}"; do
        while IFS= read -r p; do
            [[ -z "$p" ]] && continue
            r="${PROOT[$p]}"
            is_array_root "$r" || continue
            tally["$r"]=$(( ${tally[$r]:-0} + ${PSIZE[$p]} ))
        done <<< "${IDX[$rel]}"
    done
    for r in "${ARRAY_ROOTS[@]}"; do        # feste Reihenfolge -> Gleichstand deterministisch
        local t=${tally[$r]:-0}
        (( t > max )) && { max=$t; target_disk="$r"; }
    done

    if [[ -z "$target_disk" ]]; then      # Ordner liegt nur auf Cache/Pool
        if [[ "$MOVE_CACHE" == true && "$CACHE_ONLY_TARGET" == most-free ]]; then
            most_free_array_disk; target_disk="$MOST_FREE"
        fi
        if [[ -z "$target_disk" ]]; then
            N_SKIPPED_CACHE=$(( N_SKIPPED_CACHE + ${#rels[@]} ))
            return 0
        fi
    fi

    # B. Dateien verarbeiten
    for rel in "${rels[@]}"; do
        local -a phys=()
        mapfile -t phys <<< "${IDX[$rel]%$'\n'}"

        local user_path="$USER_ROOT$rel" skip=false
        if is_excluded "$user_path"; then skip=true; fi
        for p in "${phys[@]}"; do is_excluded "$p" && skip=true; done
        if $skip; then ((N_IGNORED++)); continue; fi

        local on_target=""
        for p in "${phys[@]}"; do
            [[ "${PROOT[$p]}" == "$target_disk" ]] && { on_target="$p"; break; }
        done

        if [[ -n "$on_target" ]]; then
            for p in "${phys[@]}"; do
                [[ "$p" == "$on_target" ]] && continue
                remove_duplicate "$on_target" "$p"
            done
            continue
        fi

        # Quelle wählen: bevorzugt eine Array-Kopie, sonst Cache (nur mit --include-cache)
        local src=""
        for p in "${phys[@]}"; do is_array_root "${PROOT[$p]}" && { src="$p"; break; }; done
        if [[ -z "$src" ]]; then
            if [[ "$MOVE_CACHE" != true ]]; then ((N_SKIPPED_CACHE++)); continue; fi
            src="${phys[0]}"
        fi

        if execute_move "$src" "$target_disk" "$rel" false; then
            local moved="$target_disk$rel"
            is_dry && { PROOT["$moved"]="$target_disk"; PSIZE["$moved"]="${PSIZE[$src]}"; }
            for p in "${phys[@]}"; do
                [[ "$p" == "$src" ]] && continue
                remove_duplicate "$moved" "$p"
            done
        fi
    done
}

# -----------------------
# 🧹 PHASE 3: DEEP CLEAN
# -----------------------
# Leerer Array-Ordner, dessen Gegenstück auf einem Cache/Pool liegt? Dann bleibt er als Wegweiser stehen.
on_cache_too() {             # $1 Disk-Root, $2 Ordner darauf
    local c rel="${2#"$1"}"
    is_array_root "$1" || return 1
    for c in "${CACHE_ROOTS[@]}"; do [[ -d "$c$rel" ]] && return 0; done
    return 1
}

run_deep_clean() {
    echo "========================================"
    echo "🧹 PHASE 3: Deep Clean (Protected)"
    echo "========================================"
    echo "Suche auf physischen Disks nach leeren Ordnern (Share-Wurzeln bleiben erhalten,"
    echo "ebenso Array-Ordner, deren Inhalt gerade auf einem Cache/Pool liegt)..."
    local root base target d e
    local -A failed=()

    if is_dry; then
        # Simulation: ein Ordner gilt als leer, wenn alle Einträge selbst "weg" wären
        # (inkl. Dateien, die der Plan oben verschoben/gelöscht hätte)
        local -A gone=()
        for e in "${!GONE[@]}"; do gone["$e"]=1; done
        for root in "${ALL_ROOTS[@]}"; do
            for base in "${BASE_RELS[@]}"; do
                target="$root$base"; [[ -d "$target" ]] || continue
                while IFS= read -r -d '' d; do
                    local empty=true
                    for e in "$d"/* "$d"/.[!.]* "$d"/..?*; do
                        [[ -n "${gone[$e]-}" ]] || { empty=false; break; }
                    done
                    if $empty && on_cache_too "$root" "$d"; then
                        ((N_DIRS_KEPT++)); echo "   📌 [LEER, bleibt: Inhalt liegt auf dem Cache] $d"
                    elif $empty; then gone["$d"]=1; ((N_DIRS_DELETED++)); echo "   🧪🗑️ [LEER] $d"; fi
                done < <(find "$target" -mindepth 1 -depth -type d -print0)
            done
        done
        return 0
    fi

    local pass=0 removed=1
    while (( removed > 0 )); do
        ((pass++)); removed=0
        (( pass > 1 )) && echo "   ... Durchgang $pass ..."
        for root in "${ALL_ROOTS[@]}"; do
            for base in "${BASE_RELS[@]}"; do
                target="$root$base"; [[ -d "$target" ]] || continue
                while IFS= read -r -d '' d; do
                    [[ -n "${failed[$d]-}" ]] && continue
                    if on_cache_too "$root" "$d"; then
                        failed["$d"]=1; ((N_DIRS_KEPT++))
                        echo "   📌 [LEER, bleibt: Inhalt liegt auf dem Cache] $d"
                    elif rmdir "$d" 2>/dev/null; then
                        ((removed++)); ((N_DIRS_DELETED++))
                        echo "   🗑️ [LEER] $d"; logf "RMDIR: $d"
                    else
                        failed["$d"]=1; ((N_DIRS_FAILED++))
                        echo "   ⚠️ [LEER, nicht löschbar] $d"; logf "RMDIR FEHLGESCHLAGEN: $d"
                    fi
                done < <(find "$target" -mindepth 1 -depth -type d -empty -print0)
            done
        done
    done
}

# -----------------------
# ▶ START
# -----------------------
if is_dry; then
    echo "🚧 DRYRUN MODUS (nichts wird verändert)"
else
    echo "⚠️ SCHARFER MODUS – Start in 2 Sekunden (Ctrl-C zum Abbrechen)"
    sleep 2
    logf "===== START (MOVE_CACHE=$MOVE_CACHE, MIN_FREE_GB=$MIN_FREE_GB, DUP_CHECK=$DUP_CHECK) ====="
fi
if [[ "$MOVE_CACHE" == true ]]; then
    echo "ℹ️  Cache-Move: AKTIV (Dateien werden vom Cache geholt, Cache-only-Ordner: $CACHE_ONLY_TARGET)"
else
    echo "ℹ️  Cache-Move: INAKTIV (Cache wird ignoriert, identische Cache-Duplikate werden trotzdem entfernt)"
fi
echo "ℹ️  Duplikat-Prüfung: $DUP_CHECK, Mindest-Freiplatz: ${MIN_FREE_GB} GB"

load_exclusions
build_index

echo "🚀 PHASE 2: Verarbeitung"
echo "----------------------------------------"
total=${#GROUP_LIST[@]}; current=0; last_base=""; N_FOLDERS=$total
(( total == 0 )) && echo "   (keine Film-/Serienordner gefunden)"
for group in "${GROUP_LIST[@]}"; do
    if stop_requested; then STOPPED=true; break; fi      # zwischen zwei Ordnern, nie mitten in einem
    ((current++))
    base="${group%/*}"
    if [[ "$base" != "$last_base" ]]; then
        (( current > 1 )) && clear_line
        echo "📂 Scanne: $USER_ROOT$base"; last_base="$base"
    fi
    printf '   [%d/%d] %d%% - %s \033[K\r' "$current" "$total" "$(( 100 * current / total ))" "${group##*/}"
    process_group "$group"
    N_FOLDERS_DONE=$current
done
clear_line

# RETRY (Queue)
if ! $STOPPED && [[ ${#RETRY_SRC[@]} -gt 0 ]]; then
    echo "========================================"
    echo "🔄 RETRY (volle Disks, ${#RETRY_SRC[@]} Dateien)"
    echo "========================================"
    for i in "${!RETRY_SRC[@]}"; do
        if stop_requested; then STOPPED=true; break; fi
        execute_move "${RETRY_SRC[$i]}" "${RETRY_TGT[$i]}" "${RETRY_REL[$i]}" true
    done
fi

echo ""
if $STOPPED; then
    echo "⏸️  Angehalten auf Wunsch nach $N_FOLDERS_DONE von $total Ordnern (kein Deep Clean)."
    logf "ANGEHALTEN auf Wunsch nach $N_FOLDERS_DONE von $total Ordnern"
else
    run_deep_clean
fi

echo ""
echo "========================================"
if is_dry; then echo "📊 ZUSAMMENFASSUNG (DRYRUN – geplant, nichts ausgeführt)"; else echo "📊 ZUSAMMENFASSUNG"; fi
echo "----------------------------------------"
printf 'Verschoben:              %d\n' "$N_MOVED"
printf 'Duplikate gelöscht:      %d\n' "$N_DUP_DELETED"
printf 'Ignoriert (Exclude):     %d\n' "$N_IGNORED"
printf 'Cache ignoriert:         %d\n' "$N_SKIPPED_CACHE"
printf 'Leere Ordner gelöscht:   %d\n' "$N_DIRS_DELETED"
printf 'Leere Ordner behalten:   %d  (Inhalt liegt auf dem Cache)\n' "$N_DIRS_KEPT"
echo "----------------------------------------"
(( N_CONFLICT    > 0 )) && printf '⚠️ Konflikte (ungleiche Kopien, nichts gelöscht): %d\n' "$N_CONFLICT"
(( N_FULL_FAILED > 0 )) && printf '❌ Nicht verschoben (Disk voll):  %d\n' "$N_FULL_FAILED"
(( N_RSYNC_ERR   > 0 )) && printf '❌ Fehler (rsync/rm/mkdir):       %d\n' "$N_RSYNC_ERR"
(( N_DIRS_FAILED > 0 )) && printf '❌ Leere Ordner nicht löschbar:   %d\n' "$N_DIRS_FAILED"
echo "========================================"
logf "===== ENDE: verschoben=$N_MOVED duplikate=$N_DUP_DELETED konflikte=$N_CONFLICT voll=$N_FULL_FAILED fehler=$N_RSYNC_ERR rmdir=$N_DIRS_DELETED/$N_DIRS_FAILED ====="
echo "--- Fertig ---"
if $STOPPED; then write_status stopped 3 "stopped after $N_FOLDERS_DONE of $total folders"; exit 3; fi
if (( N_CONFLICT + N_FULL_FAILED + N_RSYNC_ERR + N_DIRS_FAILED > 0 )); then write_status errors 2; exit 2; fi
write_status ok 0
exit 0
