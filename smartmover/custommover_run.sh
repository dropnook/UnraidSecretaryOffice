#!/bin/bash
# ==============================================================================
# Smart Mover for Unraid (custommover_run.sh) – V7
#
# Wrapper around the official Unraid move binary. For every user share whose
# mover direction is set in /boot/config/shares/<share>.cfg, files are filtered
# by age and exclude lists and then piped to the move binary:
#
#   shareUseCache="yes"     Primary: pool, Secondary: array/pool2, Mover: pool -> array (or pool2)
#                           Source scanned: /mnt/<pool>/<share>
#   shareUseCache="prefer"  Primary: pool, Secondary: array/pool2, Mover: array (or pool2) -> pool
#                           Source scanned: /mnt/user0/<share> (or /mnt/<pool2>/<share>)
#   "only" / "no"           nothing to move, skipped
#
# Usage:
#   custommover_run.sh                 dry-run (default): show what would move, touch nothing
#   custommover_run.sh --run           move for real
#   custommover_run.sh --force         ignore the age filter (exclude lists still apply)
#   custommover_run.sh --force-all     ignore age AND exclude lists
#   custommover_run.sh --share "A,B"   only these shares
#   custommover_run.sh --list-shares   show discovered shares, direction and effective settings
#   custommover_run.sh --help
#
# Environment variables (override INI / defaults; CLI flags take precedence):
#   CUSTOMMOVER_MODE          dry | run
#   CUSTOMMOVER_FORCE         age | all
#   CUSTOMMOVER_SHARES        comma separated share filter
#   CUSTOMMOVER_INI           path to smart_mover.ini      (default: <script dir>/smart_mover.ini)
#   CUSTOMMOVER_LOG_LEVEL     DEBUG | INFO | WARN         (default: INFO)
#   CUSTOMMOVER_MOVER_DEBUG   0-3, -d level for the move binary (overrides INI mover_debug)
#   CUSTOMMOVER_IGNORE_MOVER  1 = run even if the stock Unraid mover is currently running
#   CUSTOMMOVER_SHARES_DIR    share config dir           (default: /boot/config/shares, for tests)
#   CUSTOMMOVER_MNT           mount root                 (default: /mnt, for tests)
#   CUSTOMMOVER_ZFS           zfs command                (default: zfs, for tests)
#   CUSTOMMOVER_DF            df command                 (default: df, for tests; USO)
# Added in the Unraid Secretary Office (unset: the script behaves as upstream):
#   CUSTOMMOVER_LOG           log file                   (overrides INI log_file)
#   CUSTOMMOVER_LOCK          lock file                  (default: <script dir>/.custommover.lock)
#   CUSTOMMOVER_MOVER_BIN     move binary                (overrides INI mover_bin)
#   CUSTOMMOVER_STATUS        JSON file written when the script ends: mode, result, exit code,
#                             totals and per share what was found, moved and left
#   CUSTOMMOVER_STOP          a file: once it exists, no further file is started (exit 3) — with it set, a real run
#                             hands the move binary one file at a time (the office block «one file at a time») and,
#                             before a share's files, removes leftover <rel>.partial copies whose source still exists
#   CUSTOMMOVER_EXCLUDES_REQUIRED  1 = on a real run (--run) an exclude file that is missing skips its
#                             share with an ERROR (counted as an error) instead of a WARN
#   CUSTOMMOVER_DISKS_INI     emhttpd's disks.ini: on a real run a share that reaches the array names its
#                             array disks that sleep and that the run will wake (an INFO line, nothing else)
#   CUSTOMMOVER_FILL_MAX_BYTES  USO: the fill (below) moves at most this many bytes in this run (0 = no fill; the age rule
#                             is untouched); with it the fill starts at/above the TARGET (the caller decided it must go)
#   CUSTOMMOVER_FILL_MAX_POOL USO: the pool that cap is for (empty: every pool shares it; other pools fill as without it)
# Upstream (helmi1987/custom-mover-for-unraid) this copy matches: 3bee448, with
#   6382fb4  threshold on ZFS: the whole pool from zfs list (used/avail), not df on /mnt/<pool>
#   3bee448  absolute exclude entries match literally (find_literal; the office's own fix, taken upstream)
# USO: a <share>.cfg whose share is gone (no $MNT/user/<share>: a share removed by hand leaves its .cfg)
#      is skipped with an INFO line, never moved (not upstream as of 68ee9ce)
#
# INI (smart_mover.ini):
#   [GLOBAL]  mover_bin, log_file, min_age (days), age_stat (ctime|mtime), global_excludes (comma list),
#             move_when_used_above (percent, 0 = off; USO: the fill's trigger), move_until_used_below (percent, its target),
#             move_prefer_shares (yes|no), mover_debug (0-3), max_list (dry-run lines shown on console)
#   [<Share>] min_age, age_stat, excludes, move_when_used_above, move_until_used_below, skip=yes
#
# USO «fill» (2026-10-12, the office's change of meaning — upstream move_when_used_above is a gate):
#   1. the age rule always: every run moves what is older than min_age, however full the pool is;
#   2. then, for a pool -> array share with move_when_used_above=X > 0: is the pool still at/above X %, the rest of the
#      share (never an excluded file) goes OLDEST FIRST by age_stat, whatever its age, file by file, the pool re-read
#      after each one (like the threshold: ZFS whole pool, else df), until the pool is below move_until_used_below=Y %
#      (Y < X; 0/empty: below X). Several shares on one pool share the target: once below, later shares fill nothing.
#      A dry run estimates it from the files' sizes. Not with --force/--force-all (they move everything anyway).
#
# Exclude files: one entry per line, comments (#) and blank lines ignored, CRLF tolerated.
#   /abs/path/dir          protects the directory and everything below it
#   /abs/path/file.mkv     protects exactly that file (an EmbyCache exclude list works as-is)
#                          absolute entries are taken literally: [ ] * ? are part of the name
#   *.nfo  or  Season 0*   glob against the file name (no slash) or the full path (with slash)
#   anything else          substring match on the full path (legacy behaviour)
#   /mnt/user/<share>/...  and /mnt/user0/<share>/... are translated to the scanned source root
# ==============================================================================

set -u -o pipefail

SCRIPT_DIR="$(dirname "$(realpath "$0")")"
INI_FILE="${CUSTOMMOVER_INI:-$SCRIPT_DIR/smart_mover.ini}"
SHARES_DIR="${CUSTOMMOVER_SHARES_DIR:-/boot/config/shares}"
MNT="${CUSTOMMOVER_MNT:-/mnt}"
LOCK_FILE="${CUSTOMMOVER_LOCK:-$SCRIPT_DIR/.custommover.lock}"
MOVER_PIDFILE="/var/run/mover.pid"
ZFS_BIN="${CUSTOMMOVER_ZFS:-zfs}"
DF_BIN="${CUSTOMMOVER_DF:-df}"
LOG_LEVEL="${CUSTOMMOVER_LOG_LEVEL:-INFO}"

DRY_RUN=true
FORCE_AGE=false
FORCE_ALL=false
LIST_ONLY=false
TARGET_SHARES="${CUSTOMMOVER_SHARES:-}"
[[ "${CUSTOMMOVER_MODE:-dry}" == "run" ]] && DRY_RUN=false
case "${CUSTOMMOVER_FORCE:-}" in age) FORCE_AGE=true ;; all) FORCE_AGE=true; FORCE_ALL=true ;; esac
STATUS_FILE="${CUSTOMMOVER_STATUS:-}"
STOP_FILE="${CUSTOMMOVER_STOP:-}"
EXCL_REQUIRED="${CUSTOMMOVER_EXCLUDES_REQUIRED:-0}"
DISKS_INI="${CUSTOMMOVER_DISKS_INI:-}"
FILL_MAX_B="${CUSTOMMOVER_FILL_MAX_BYTES:-}"       # USO: the fill's cap (empty: none)
[[ -z "$FILL_MAX_B" || "$FILL_MAX_B" =~ ^[0-9]+$ ]] || FILL_MAX_B=0
FILL_MAX_POOL="${CUSTOMMOVER_FILL_MAX_POOL:-}"
FILL_LEFT_B="${FILL_MAX_B:-0}"                     # what the cap still allows

usage() {  # prints the header comment block above
    awk 'NR > 2 && /^# =====/ { exit } NR > 2 { sub(/^# ?/, ""); print }' "$0"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --run)         DRY_RUN=false; shift ;;
        --force)       FORCE_AGE=true; shift ;;
        --force-all)   FORCE_AGE=true; FORCE_ALL=true; shift ;;
        --share)       [[ $# -ge 2 ]] || { echo "--share needs a value"; exit 1; }; TARGET_SHARES="$2"; shift 2 ;;
        --list-shares) LIST_ONLY=true; shift ;;
        -h|--help)     usage; exit 0 ;;
        *)             echo "Unknown option: $1 (see --help)"; exit 1 ;;
    esac
done

# ------------------------------------------------------------------ Status (office)
# CUSTOMMOVER_STATUS: written once when the script ends (trap), atomically (a new file + rename)
STARTED=$(date +%s)
STATUS_MSG=""
STOPPED=false
STOP_SHARE=""
ERRORS=0
WARNINGS=0      # office: WARN lines so far (the run card's «N errors, N warnings», as Mr. Backupsy's)
SHARE_JSON=()
SH_STATE=""; SH_DIRECTION=""; SH_FILES=0; SH_BYTES=0; SH_MOVED=0; SH_LEFT=0
SH_AGE=""; SH_STAT=""; SH_THRESHOLD=""; SH_EXCLUDES=""
# USO «fill»: the pool, its target, its usage when the share started and the last reading, the target reached in it
SH_POOL=""; SH_UNTIL=""; SH_POOL_USED=""; SH_POOL_NOW=""; SH_FILL=false; SH_REACHED=false; SH_RC=0
FILL_POOL=""; FILL_TARGET=0; FILL_REACHED=false; FILL_CAP=false; FILL_CAPPED=false
declare -A POOL_REACHED=() POOL_SIM_B=()
# office: the live progress of a real run (CUSTOMMOVER_STATUS while it runs: result «running»)
PLAN=(); PHASE=look; CUR_SHARE=""; CUR_FILE=""; LAST_FILE=""; SH_DONE_FILES=0; SH_DONE_BYTES=0; RUN_DONE_FILES=0; RUN_DONE_BYTES=0

json_str() {  # text -> a JSON string (invalid UTF-8 dropped; quotes, backslashes escaped; other control characters and DEL dropped)
    local s="$1"
    if command -v iconv >/dev/null 2>&1; then s=$(printf '%s' "$s" | iconv -c -f UTF-8 -t UTF-8 2>/dev/null); fi
    s=${s//\\/\\\\}; s=${s//\"/\\\"}; s=${s//$'\n'/\\n}; s=${s//$'\r'/\\r}; s=${s//$'\t'/\\t}
    s=$(printf '%s' "$s" | tr -d '\000-\010\013\014\016-\037\177')
    printf '"%s"' "$s"
}

json_num() {  # a whole number, else 0
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '0'; fi
}

fill_json() {  # USO «fill»: the share's pool and target as JSON members (a leading comma)
    printf ',"pool":%s,"until":%s,"pool_used":%s,"pool_now":%s,"fill":%s,"reached":%s' "$(json_str "$SH_POOL")" "$(json_str "$SH_UNTIL")" \
        "$(json_str "$SH_POOL_USED")" "$(json_str "$SH_POOL_NOW")" "$SH_FILL" "$SH_REACHED"
}

share_status_add() {  # name mode — what process_share left in SH_*
    SHARE_JSON+=("{\"share\":$(json_str "$1"),\"mode\":$(json_str "$2"),\"state\":$(json_str "$SH_STATE"),\"direction\":$(json_str "$SH_DIRECTION"),\"files\":$(json_num "$SH_FILES"),\"bytes\":$(json_num "$SH_BYTES"),\"moved\":$(json_num "$SH_MOVED"),\"left\":$(json_num "$SH_LEFT"),\"min_age\":$(json_str "$SH_AGE"),\"age_stat\":$(json_str "$SH_STAT"),\"threshold\":$(json_str "$SH_THRESHOLD"),\"excludes\":$(json_str "$SH_EXCLUDES")$(fill_json)}")
}

write_status() {  # exit code
    [[ -n "$STATUS_FILE" ]] || return 0
    local code="$1" result mode joined
    case "$code" in
        0) if (( ERRORS > 0 )); then result=errors; else result=ok; fi ;;
        1) result=config ;;
        2) result=busy ;;
        3) result=stopped ;;
        *) result=failed ;;
    esac
    if [[ "$LIST_ONLY" == true ]]; then mode=list; elif [[ "$DRY_RUN" == true ]]; then mode=dry; else mode=run; fi
    joined=$(IFS=,; printf '%s' "${SHARE_JSON[*]}")
    printf '{"mode":"%s","result":"%s","exit":%s,"started":%s,"finished":%s,"files":%s,"bytes":%s,"moved":%s,"left":%s,"errors":%s,"stop_share":%s,"stop_file":%s,"message":%s,"shares":[%s]}\n' \
        "$mode" "$result" "$(json_num "$code")" "$STARTED" "$(date +%s)" "$(json_num "${TOTAL_FILES:-0}")" "$(json_num "${TOTAL_BYTES:-0}")" \
        "$(json_num "${TOTAL_MOVED:-0}")" "$(json_num "${TOTAL_LEFT:-0}")" "$(json_num "$ERRORS")" "$(json_str "$STOP_SHARE")" "$(json_str "$LAST_FILE")" "$(json_str "$STATUS_MSG")" "$joined" \
        > "$STATUS_FILE.$$.tmp" 2>/dev/null && mv -f "$STATUS_FILE.$$.tmp" "$STATUS_FILE" 2>/dev/null
    rm -f "$STATUS_FILE.$$.tmp" 2>/dev/null
    return 0
}
[[ -n "$STATUS_FILE" ]] && trap 'write_status $?' EXIT

# office: while a real run goes, the same file says how far it is (result «running»): the shares it will look at
# (plan), the phase (look, share, tidy), the share and file in flight, what of the share and of the run is done
office_progress() {
    [[ -n "$STATUS_FILE" && "$DRY_RUN" == false && "$LIST_ONLY" == false ]] || return 0
    local plan joined p
    plan=""; for p in "${PLAN[@]}"; do plan+="${plan:+,}$(json_str "$p")"; done
    joined=$(IFS=,; printf '%s' "${SHARE_JSON[*]}")
    printf '{"mode":"run","result":"running","errors":%s,"warnings":%s,"started":%s,"time":%s,"plan":[%s],"phase":"%s","share":%s,"file":%s,"share_files":%s,"share_bytes":%s,"share_done_files":%s,"share_done_bytes":%s,"done_files":%s,"done_bytes":%s,"files":%s,"bytes":%s%s,"shares":[%s]}\n' \
        "$(json_num "$ERRORS")" "$(json_num "$WARNINGS")" "$STARTED" "$(date +%s)" "$plan" "$PHASE" "$(json_str "$CUR_SHARE")" "$(json_str "$CUR_FILE")" "$(json_num "$SH_FILES")" "$(json_num "$SH_BYTES")" \
        "$(json_num "$SH_DONE_FILES")" "$(json_num "$SH_DONE_BYTES")" "$(json_num "$RUN_DONE_FILES")" "$(json_num "$RUN_DONE_BYTES")" \
        "$(json_num "${TOTAL_FILES:-0}")" "$(json_num "${TOTAL_BYTES:-0}")" "$(fill_json)" "$joined" \
        > "$STATUS_FILE.$$.tmp" 2>/dev/null && mv -f "$STATUS_FILE.$$.tmp" "$STATUS_FILE" 2>/dev/null
    rm -f "$STATUS_FILE.$$.tmp" 2>/dev/null
    return 0
}

# ------------------------------------------------------------------ INI
[[ -f "$INI_FILE" ]] || { STATUS_MSG="$INI_FILE missing"; echo "Error: $INI_FILE missing – run setup_custommover.sh first."; exit 1; }

get_ini_val() {  # section key
    awk -v sec="[$1]" -v key="$2" '
        { sub(/\r$/, "") }
        /^[ \t]*[#;]/ { next }
        /^[ \t]*\[/ { line=$0; gsub(/^[ \t]+|[ \t]+$/, "", line); insec = (line == sec); next }
        insec {
            i = index($0, "=")
            if (i == 0) next
            k = substr($0, 1, i - 1); gsub(/^[ \t]+|[ \t]+$/, "", k)
            if (k == key) { v = substr($0, i + 1); gsub(/^[ \t]+|[ \t]+$/, "", v); print v; exit }
        }' "$INI_FILE"
}

is_int() { [[ "$1" =~ ^[0-9]+$ ]]; }

MOVER_BIN="${CUSTOMMOVER_MOVER_BIN:-$(get_ini_val GLOBAL mover_bin)}"
LOG_FILE="${CUSTOMMOVER_LOG:-$(get_ini_val GLOBAL log_file)}"
GLOBAL_MIN_AGE=$(get_ini_val GLOBAL min_age)
GLOBAL_AGE_STAT=$(get_ini_val GLOBAL age_stat)
GLOBAL_EXC=$(get_ini_val GLOBAL global_excludes)
GLOBAL_THRESHOLD=$(get_ini_val GLOBAL move_when_used_above)
GLOBAL_UNTIL=$(get_ini_val GLOBAL move_until_used_below)    # USO «fill»
MOVE_PREFER=$(get_ini_val GLOBAL move_prefer_shares)
MOVER_DEBUG=$(get_ini_val GLOBAL mover_debug)
MAX_LIST=$(get_ini_val GLOBAL max_list)

if [[ -z "$MOVER_BIN" ]]; then
    for cand in /usr/libexec/unraid/move /usr/local/sbin/move /usr/local/bin/move; do
        [[ -x "$cand" ]] && { MOVER_BIN="$cand"; break; }
    done
fi
: "${GLOBAL_MIN_AGE:=0}" "${GLOBAL_AGE_STAT:=ctime}" "${GLOBAL_THRESHOLD:=0}" "${GLOBAL_UNTIL:=0}" "${MOVE_PREFER:=yes}" "${MOVER_DEBUG:=0}" "${MAX_LIST:=50}"
[[ -z "$LOG_FILE" ]] && LOG_FILE="$SCRIPT_DIR/smart_mover.log"
MOVER_DEBUG="${CUSTOMMOVER_MOVER_DEBUG:-$MOVER_DEBUG}"

# ------------------------------------------------------------------ Logging
mkdir -p "$(dirname "$LOG_FILE")"
if [[ -t 1 ]]; then GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; GREY='\033[0;90m'; NC='\033[0m'
else GREEN=''; YELLOW=''; RED=''; GREY=''; NC=''; fi

rotate_logs() {
    [[ -f "$LOG_FILE" ]] || return 0
    (( $(stat -c%s "$LOG_FILE") > 10485760 )) || return 0
    for i in {19..1}; do [[ -f "$LOG_FILE.$i" ]] && mv -f "$LOG_FILE.$i" "$LOG_FILE.$((i+1))"; done
    mv -f "$LOG_FILE" "$LOG_FILE.1"
}

log() {  # level message
    local level="$1"; shift
    local msg="$*" ts c=$NC
    [[ "$level" == "DEBUG" && "$LOG_LEVEL" != "DEBUG" ]] && return 0
    ts=$(date '+%Y-%m-%d %H:%M:%S')
    case "$level" in INFO) c=$GREEN ;; WARN) c=$YELLOW ;; ERROR) c=$RED ;; DEBUG) c=$GREY ;; esac
    [[ "$level" == "WARN" ]] && (( WARNINGS++ ))      # office: counted for the run card
    printf '%b[%s] [%s] %s%b\n' "$c" "$ts" "$level" "$msg" "$NC"
    printf '[%s] [%s] %s\n' "$ts" "$level" "$msg" >> "$LOG_FILE"
}

die() { STATUS_MSG="$*"; log ERROR "$*"; exit 1; }

human() {
    local b=$1 u=(B KB MB GB TB PB) i=0
    while (( b >= 1024 && i < 5 )); do b=$(( b / 1024 )); (( i++ )); done
    echo "$b ${u[$i]}"
}

# ------------------------------------------------------------------ Validation
is_int "$GLOBAL_MIN_AGE" || die "GLOBAL min_age must be a whole number of days (got '$GLOBAL_MIN_AGE')"
is_int "$GLOBAL_THRESHOLD" || die "GLOBAL move_when_used_above must be a percentage 0-100 (got '$GLOBAL_THRESHOLD')"
is_int "$GLOBAL_UNTIL" || die "GLOBAL move_until_used_below must be a percentage 0-100 (got '$GLOBAL_UNTIL')"
is_int "$MOVER_DEBUG" || die "mover_debug must be 0-3"
[[ "$GLOBAL_AGE_STAT" == "ctime" || "$GLOBAL_AGE_STAT" == "mtime" ]] || die "age_stat must be ctime or mtime"
[[ -d "$SHARES_DIR" ]] || die "Share config directory not found: $SHARES_DIR"

# ------------------------------------------------------------------ Lock / stock mover
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    STATUS_MSG="another Smart Mover run is active"
    echo "Another Smart Mover run is active ($LOCK_FILE) – exiting."; exit 2
fi
stock_mover_running() {
    local pid
    pid=$(cat "$MOVER_PIDFILE" 2>/dev/null) || return 1
    [[ -n "$pid" && -d "/proc/$pid" ]]
}

# Used space of a pool in percent -> POOL_USED (empty if unknown), POOL_USED_SRC (zfs|df).
# On ZFS /mnt/<pool> is only the pool's root dataset and the shares are child datasets,
# so df shows next to nothing there: take used/(used+avail) of the whole pool from zfs.
# Not ZFS, or zfs gives no numbers: df (rounded up, like df itself).
# USO «fill»: also POOL_USED_B / POOL_SIZE_B (bytes used / used+available; empty if unknown) for a dry run's estimate
pool_used_pct() {  # pool
    local path="$MNT/$1" ds="" used avail pc
    POOL_USED=""; POOL_USED_SRC="df"; POOL_USED_B=""; POOL_SIZE_B=""
    if command -v "$ZFS_BIN" >/dev/null 2>&1 && ds=$("$ZFS_BIN" list -H -o name "$path" 2>/dev/null) && [[ -n "$ds" ]]; then
        if read -r used avail < <("$ZFS_BIN" list -Hp -o used,avail "${ds%%/*}" 2>/dev/null) \
            && is_int "$used" && is_int "$avail" && (( used + avail > 0 )); then
            POOL_USED=$(( (used * 100 + used + avail - 1) / (used + avail) ))
            POOL_USED_SRC="zfs"; POOL_USED_B=$used; POOL_SIZE_B=$(( used + avail ))
            return 0
        fi
        log WARN "  zfs gave no usage for pool ${ds%%/*} – falling back to df"
    fi
    read -r used avail pc < <("$DF_BIN" -B1 --output=used,avail,pcent "$path" 2>/dev/null | tail -1)
    POOL_USED=$(printf '%s' "${pc:-}" | tr -dc '0-9')
    if is_int "${used:-}" && is_int "${avail:-}" && (( used + avail > 0 )); then POOL_USED_B=$used; POOL_SIZE_B=$(( used + avail )); fi
}

# ------------------------------------------------------------------ Excludes
# An absolute entry names exactly one file or folder, so find must match it literally:
# escape what find's -path reads as a pattern (\ [ ] * ?), e.g. «Film (2020) [1080p].mkv».
find_literal() {  # path -> FIND_LITERAL (no subshell: an EmbyCache list has many lines)
    local s="$1" bs='\'
    s="${s//"$bs"/"$bs$bs"}"; s="${s//"["/"$bs["}"; s="${s//"]"/"$bs]"}"; s="${s//"*"/"$bs*"}"; s="${s//"?"/"$bs?"}"
    FIND_LITERAL="$s"
}

# Builds EXCL_ARGS (find expression fragments) for one share. Patterns pointing into
# /mnt/user/<share> or /mnt/user0/<share> are rewritten to the scanned source root.
build_excludes() {  # share_name source_root exclude_files(comma list)...
    local share="$1" src="$2"; shift 2
    EXCL_ARGS=(-false)
    EXCL_COUNT=0
    EXCL_MISSING=""
    local f line
    for f in "$@"; do
        f="${f#"${f%%[![:space:]]*}"}"; f="${f%"${f##*[![:space:]]}"}"
        [[ -z "$f" ]] && continue
        if [[ ! -f "$f" ]]; then
            # office: on a real run a list that is gone must not let its files move
            if [[ "$EXCL_REQUIRED" == "1" && "$DRY_RUN" == false ]]; then log ERROR "  exclude file not found: $f"; EXCL_MISSING="$f"
            else log WARN "  exclude file not found: $f"; fi
            continue
        fi
        while IFS= read -r line || [[ -n "$line" ]]; do
            line="${line%$'\r'}"
            line="${line#"${line%%[![:space:]]*}"}"; line="${line%"${line##*[![:space:]]}"}"
            [[ -z "$line" || "$line" == \#* || "$line" == \;* ]] && continue
            line="${line%/}"
            line="${line/#"$MNT/user/$share"/"$src"}"      # USO: quoted — a share or path with [ ] * ? is no pattern
            line="${line/#"$MNT/user0/$share"/"$src"}"
            case "$line" in
                /*)  # absolute path: file or subtree, matched literally
                    find_literal "$line"
                    EXCL_ARGS+=(-o -path "$FIND_LITERAL" -o -path "$FIND_LITERAL/*") ;;
                *[\*\?\[]*)  # glob
                    if [[ "$line" == */* ]]; then EXCL_ARGS+=(-o -path "$line"); else EXCL_ARGS+=(-o -name "$line"); fi ;;
                *)   # substring (legacy)
                    EXCL_ARGS+=(-o -path "*$line*") ;;
            esac
            (( EXCL_COUNT++ )) || true
        done < "$f"
    done
}

# ------------------------------------------------------------------ Cleanup
remove_empty_parents() {  # root file...
    local root="$1"; shift
    local -A seen=()
    local f d
    for f in "$@"; do
        d=$(dirname "$f")
        while [[ "$d" == "$root/"* && -z "${seen[$d]:-}" ]]; do
            seen[$d]=1
            if [[ -d "$d" ]] && rmdir "$d" 2>/dev/null; then
                log INFO "  removed empty directory: $d"
            else
                break
            fi
            d=$(dirname "$d")
        done
    done
}

# ------------------------------------------------------------------ Share processing
TOTAL_FILES=0; TOTAL_BYTES=0; TOTAL_MOVED=0; TOTAL_LEFT=0

# ------------------------------------------------------------------ office: one file at a time
# With CUSTOMMOVER_STOP set (the office), a real run hands the move binary one file at a time: before each file the
# stop request is looked at — set, no further file starts and the file in flight has finished («stop after this file»).
# There is no harder stop: Unraid's move catches TERM and finishes the file in flight anyway (seen 2026-10-13).
# Without CUSTOMMOVER_STOP the share's list goes to the move binary at once, as upstream.

office_disk_awake() {  # disk — not spun down in emhttpd's disks.ini (no ini: counted as awake)
    [[ -n "$DISKS_INI" && -r "$DISKS_INI" ]] || return 0
    awk -v d="$1" '/^\[/ { s = $0; gsub(/[\["\]]/, "", s); next } s == d && $0 == "spundown=\"1\"" { found = 1 } END { exit found ? 1 : 0 }' "$DISKS_INI"
}

# office: before a real run moves a share's files — Unraid's move writes <dest>/<share>/<rel>.partial and renames it at
# the end (seen 2026-10-12); one left behind (a power cut, a crash) is removed, but only while its source <src>/<rel>
# still exists, only on the share's awake destinations (an awake array disk, or the target pool), only a plain file
# named exactly so — never a file under its final name
office_partial_leftovers() {  # share src dest(array|<pool>)
    local share="$1" src="$2" dest="$3" d p rel
    local -a roots=()
    if [[ "$dest" == array ]]; then
        for d in "$MNT"/disk[0-9]*; do [[ -d "$d/$share" ]] && office_disk_awake "$(basename "$d")" && roots+=("$d"); done
    else
        [[ -d "$MNT/$dest/$share" ]] && office_disk_awake "$dest" && roots+=("$MNT/$dest")
    fi
    for d in "${roots[@]}"; do
        while IFS= read -r -d '' p; do
            rel="${p#"$d/$share"/}"; rel="${rel%.partial}"
            [[ -n "$rel" && "$rel" != *"/../"* && "$rel" != "../"* ]] || continue
            [[ -e "$src/$rel" && ! -L "$p" ]] || continue
            rm -f -- "$p" && log WARN "  removed a leftover partial copy $p – its file is still at $src/$rel"
        done < <(find "$d/$share" -xdev -type f -name '*.partial' -print0 2>/dev/null)
    done
    return 0
}

# The share's files to the move binary one by one; OFFICE_RC the last non-zero exit
office_move_files() {  # share src dest out
    local share="$1" src="$2" dest="$3" out="$4" f r size
    OFFICE_RC=0; OFFICE_TRIED=0
    for f in "${FILES[@]}"; do
        if [[ -e "$STOP_FILE" ]]; then
            STOPPED=true; STOP_SHARE="$share"
            log WARN "stop requested ($STOP_FILE) – stopped after ${LAST_FILE:-the files before}; the rest of $share and the shares after it are left for the next run"
            break
        fi
        size=$(stat -c %s -- "$f" 2>/dev/null || echo 0)
        # USO: the fill's cap (CUSTOMMOVER_FILL_MAX_BYTES) — a file that would go past it is not started
        if [[ -n "$FILL_POOL" && "$FILL_CAP" == true ]] && (( size > FILL_LEFT_B )); then
            FILL_CAPPED=true
            break
        fi
        CUR_FILE="$f"; office_progress
        (( OFFICE_TRIED++ )) || true
        printf '%s\n' "$f" | "${MOVER_CMD[@]}" >>"$out" 2>&1
        r=$?
        (( r != 0 )) && OFFICE_RC=$r
        LAST_FILE="$f"
        if [[ ! -e "$f" && ! -L "$f" ]]; then
            (( SH_DONE_FILES++, RUN_DONE_FILES++ )) || true
            (( SH_DONE_BYTES += size, RUN_DONE_BYTES += size )) || true
            [[ -n "$FILL_POOL" && "$FILL_CAP" == true ]] && { (( FILL_LEFT_B -= size )) || true; }
        fi
        # USO «fill»: the pool after this file — below its target, the rest of the share stays
        if [[ -n "$FILL_POOL" ]]; then
            pool_used_pct "$FILL_POOL"
            [[ -n "$POOL_USED" ]] && SH_POOL_NOW=$POOL_USED
            if [[ -n "$POOL_USED" ]] && (( POOL_USED < FILL_TARGET )); then
                FILL_REACHED=true
                break
            fi
        fi
    done
    CUR_FILE=""
}

# office: the array disks a share may use (its cfg's shareInclude, else all, less shareExclude) that sleep now —
# read from emhttpd's disks.ini (RAM, no disk asked); prints them comma separated, nothing when none or no ini
asleep_disks() {  # share
    [[ -n "$DISKS_INI" && -r "$DISKS_INI" ]] || return 0
    local cfg="$SHARES_DIR/$1.cfg" inc exc sec="" line out=""
    inc=$(sed -n 's/^shareInclude="\([^"]*\)".*/\1/p' "$cfg" 2>/dev/null | tr -d '\r' | head -1)
    exc=$(sed -n 's/^shareExclude="\([^"]*\)".*/\1/p' "$cfg" 2>/dev/null | tr -d '\r' | head -1)
    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        if [[ "$line" =~ ^\[\"?([A-Za-z0-9_]+)\"?\]$ ]]; then sec="${BASH_REMATCH[1]}"; continue; fi
        [[ "$sec" =~ ^disk[0-9]+$ && "$line" == 'spundown="1"' ]] || continue
        [[ -n "$inc" && ",$inc," != *",$sec,"* ]] && continue
        [[ -n "$exc" && ",$exc," == *",$sec,"* ]] && continue
        out+="${out:+, }$sec"
    done < "$DISKS_INI"
    printf '%s' "$out"
}

# ------------------------------------------------------------------ helpers of process_share (they see its locals)
# The share's candidates: files and symlinks below src, the excludes (EXCL_ARGS) pruned, extra find tests (the age)
# -> FOUND_FILES; names with a newline dropped (the move binary reads one path per line)
find_candidates() {  # src [find tests...]
    local src="$1"; shift
    local find_err f line
    local -a raw=()
    FOUND_FILES=()
    find_err=$(mktemp)
    mapfile -d '' raw < <(find "$src" \( "${EXCL_ARGS[@]}" \) -prune -o \( -type f -o -type l \) "$@" -print0 2>"$find_err")
    if [[ -s "$find_err" ]]; then while IFS= read -r line; do log WARN "  find: $line"; done < "$find_err"; fi
    rm -f "$find_err"
    for f in "${raw[@]}"; do
        if [[ "$f" == *$'\n'* ]]; then log WARN "  skipped (newline in name): ${f//$'\n'/\\n}"; else FOUND_FILES+=("$f"); fi
    done
}

# The dry run's list of FILES (console up to max_list, the log all)
dry_list() {
    local i=0 f n=${#FILES[@]}
    for f in "${FILES[@]}"; do
        (( i++ )) || true
        if (( i <= MAX_LIST )); then printf '     %s\n' "$f"; fi
        printf '[DRY-RUN]     %s\n' "$f" >> "$LOG_FILE"
    done
    (( n > MAX_LIST )) && echo "     ... $((n - MAX_LIST)) more (full list in $LOG_FILE)"
    return 0
}

# FILES to the move binary (one by one with CUSTOMMOVER_STOP or the fill, else at once), then what is still at the
# source; the counts to SH_MOVED/SH_LEFT and the run's totals, SH_RC the move binary's last failure. With the fill
# (FILL_POOL) the files not handed over when the target was reached are dropped from FILES (never «left»).
move_batch() {  # share src dest
    local name="$1" src="$2" dest="$3" mover_out rc f line
    mover_out=$(mktemp)
    if [[ -n "$STOP_FILE" || -n "$FILL_POOL" ]]; then
        # office: one file at a time (see «one file at a time» above); USO «fill»: the pool re-read after each file
        : > "$mover_out"
        office_move_files "$name" "$src" "$dest" "$mover_out"
        rc=$OFFICE_RC
        # stopped (or the fill's target reached): only the files handed over are verified — the rest was never started
        OFFICE_NOT_STARTED=0
        # office: stopped — the files never started stay and count as left (not for the fill: its rest is no candidate)
        [[ "$STOPPED" == true && -z "$FILL_POOL" ]] && OFFICE_NOT_STARTED=$(( ${#FILES[@]} - OFFICE_TRIED ))
        [[ "$STOPPED" == true || -n "$FILL_POOL" ]] && FILES=("${FILES[@]:0:OFFICE_TRIED}")
    else
        printf '%s\n' "${FILES[@]}" | "${MOVER_CMD[@]}" >"$mover_out" 2>&1
        rc=$?
    fi
    while IFS= read -r line; do log DEBUG "  mover: $line"; done < "$mover_out"
    if (( rc != 0 )); then
        (( ERRORS++ )) || true
        SH_RC=$rc
        log ERROR "  move binary exited with code $rc – see $LOG_FILE (mover_debug=1 for details)"
        [[ "$LOG_LEVEL" != "DEBUG" ]] && tail -n 5 "$mover_out" | while IFS= read -r line; do log ERROR "  mover: $line"; done
    fi
    rm -f "$mover_out"

    # Verify: what is still on the source side?
    local -a MOVED=() LEFT=()
    for f in "${FILES[@]}"; do
        if [[ -e "$f" || -L "$f" ]]; then LEFT+=("$f"); else MOVED+=("$f"); fi
    done
    local ns=${OFFICE_NOT_STARTED:-0}
    OFFICE_NOT_STARTED=0
    (( TOTAL_MOVED += ${#MOVED[@]}, TOTAL_LEFT += ${#LEFT[@]} + ns )) || true
    (( SH_MOVED += ${#MOVED[@]}, SH_LEFT += ${#LEFT[@]} + ns )) || true
    if (( ns > 0 )); then log INFO "  moved ${#MOVED[@]} of $(( ${#FILES[@]} + ns )) files ($ns not started: stopped)"
    else log INFO "  moved ${#MOVED[@]} of ${#FILES[@]} files"; fi
    if (( ${#LEFT[@]} > 0 )); then
        log WARN "  ${#LEFT[@]} files still on the source side (in use, or already present at the destination):"
        local i=0
        for f in "${LEFT[@]}"; do (( i++ )) || true; (( i <= 10 )) && log WARN "     $f"; done
        (( ${#LEFT[@]} > 10 )) && log WARN "     ... $(( ${#LEFT[@]} - 10 )) more"
    fi
    (( ${#MOVED[@]} > 0 )) && remove_empty_parents "$src" "${MOVED[@]}"
    return 0
}

# USO «fill»: the rest of the share (younger than the age, never an excluded file, never one the age step had) oldest
# first by age_stat, ties by name -> FILL_FILES, FILL_SIZES
fill_candidates() {  # src
    local tf='%Z' rec sz f
    [[ "$age_stat" == "mtime" ]] && tf='%Y'
    find_candidates "$1"
    local -a rest=()
    for f in "${FOUND_FILES[@]}"; do [[ -z "${AGED[$f]:-}" ]] && rest+=("$f"); done
    FILL_FILES=(); FILL_SIZES=()
    (( ${#rest[@]} > 0 )) || return 0
    while IFS= read -r -d '' rec; do
        rec=${rec#*$'\t'}; sz=${rec%%$'\t'*}; FILL_FILES+=("${rec#*$'\t'}"); FILL_SIZES+=("$sz")
    done < <(printf '%s\0' "${rest[@]}" | xargs -0 -r stat --printf "$tf\t%s\t%n\0" 2>/dev/null | sort -z -t $'\t' -k1,1n -k3)
}

# The fill's start: the pool's usage now -> FILL_GO (true: at/above the trigger); said in the log either way
fill_reading() {  # share pool threshold target used(empty: unknown)
    local name="$1" pool="$2" threshold="$3" target="$4" used="$5"
    FILL_GO=false
    SH_POOL="$pool"; SH_UNTIL="$target"; SH_FILL=true; SH_POOL_USED="$used"; SH_POOL_NOW="$used"
    if [[ -z "$used" ]]; then
        log WARN "  pool $pool: usage unknown – no fill"
    elif (( used < threshold )); then
        if [[ -n "${POOL_REACHED[$pool]:-}" ]]; then log INFO "  pool $pool at ${used}% – target below ${target}% already reached with ${POOL_REACHED[$pool]}, nothing more"
        else log INFO "  pool $pool at ${used}% ($POOL_USED_SRC) < ${threshold}% – no fill"; fi
    else
        FILL_GO=true
        log INFO "  pool $pool ${used}% ($POOL_USED_SRC) >= ${threshold}% -> target below ${target}%: the oldest first ($age_stat), whatever their age"
    fi
}

# USO «fill», a real run: after the age step the pool re-read; at/above the trigger the rest goes oldest first, the
# pool re-read after each file, until it is below the target
fill_run() {  # share src dest pool threshold target
    local name="$1" src="$2" dest="$3" pool="$4" threshold="$5" target="$6" n bytes2
    fill_capped "$pool" && threshold=$target
    pool_used_pct "$pool"
    fill_reading "$name" "$pool" "$threshold" "$target" "$POOL_USED"
    [[ "$FILL_GO" == true ]] || return 0
    fill_candidates "$src"
    if (( ${#FILL_FILES[@]} == 0 )); then log INFO "  fill: no further files (everything else is excluded)"; return 0; fi
    FILES=("${FILL_FILES[@]}")
    bytes2=0; for n in "${FILL_SIZES[@]}"; do (( bytes2 += n )) || true; done
    (( SH_FILES += ${#FILES[@]}, SH_BYTES += bytes2 )) || true      # the run card's whole while it fills
    log INFO "  fill: up to ${#FILES[@]} files ($(human "$bytes2")) oldest first via $MOVER_BIN"
    FILL_POOL="$pool"; FILL_TARGET=$target; FILL_REACHED=false; FILL_CAPPED=false
    fill_capped "$pool" && FILL_CAP=true || FILL_CAP=false
    local all=${#FILES[@]}
    move_batch "$name" "$src" "$dest"
    FILL_POOL=""; FILL_CAP=false
    # only the files handed over count as candidates
    local kept=0 i
    for (( i = 0; i < ${#FILES[@]}; i++ )); do (( kept += FILL_SIZES[i] )) || true; done
    (( SH_FILES -= all - ${#FILES[@]}, SH_BYTES -= bytes2 - kept )) || true
    (( TOTAL_FILES += ${#FILES[@]}, TOTAL_BYTES += kept )) || true
    if [[ "$FILL_REACHED" == true ]]; then
        SH_REACHED=true; POOL_REACHED[$pool]="$name"
        log INFO "  target reached: pool $pool at ${SH_POOL_NOW}% (< ${target}%) – the rest stays (not handed over: $(( all - ${#FILES[@]} )))"
    elif [[ "$FILL_CAPPED" == true ]]; then
        log INFO "  fill: its cap reached (at most $(human "$FILL_MAX_B") in this run) – pool $pool at ${SH_POOL_NOW:-?}%, the rest stays"
    elif [[ "$STOPPED" == false ]]; then
        log WARN "  target not reached: pool $pool at ${SH_POOL_NOW:-?}% after all ${#FILES[@]} further files"
    fi
}

# USO: does CUSTOMMOVER_FILL_MAX_BYTES hold for this pool? (set, and for it or for every pool)
fill_capped() {  # pool
    [[ -n "$FILL_MAX_B" ]] && [[ -z "$FILL_MAX_POOL" || "$FILL_MAX_POOL" == "$1" ]]
}

# USO «fill», a dry run: the pool after the age step estimated from the sizes (and what the shares before on this pool
# would have freed); at/above the trigger the oldest of the rest listed until the estimate is below the target
fill_dry() {  # share src pool threshold target aged_bytes
    local name="$1" src="$2" pool="$3" threshold="$4" target="$5" aged="$6" used_b size_b pct n=0 sz b2=0 cap=false
    fill_capped "$pool" && { threshold=$target; cap=true; }
    pool_used_pct "$pool"
    used_b="${POOL_SIM_B[$pool]:-$POOL_USED_B}"; size_b="$POOL_SIZE_B"
    if [[ -z "$used_b" || -z "$size_b" ]]; then fill_reading "$name" "$pool" "$threshold" "$target" "$POOL_USED"; FILL_GO=false
    else
        (( used_b -= aged, used_b < 0 )) && used_b=0
        fill_reading "$name" "$pool" "$threshold" "$target" "$(( (used_b * 100 + size_b - 1) / size_b ))"
    fi
    if [[ "$FILL_GO" == true ]]; then
        fill_candidates "$src"
        for sz in "${FILL_SIZES[@]}"; do
            pct=$(( (used_b * 100 + size_b - 1) / size_b ))
            (( pct < target )) && break
            [[ "$cap" == true ]] && (( sz > FILL_LEFT_B )) && break      # USO: the fill's cap
            (( used_b -= sz, b2 += sz, n++ )) || true
            [[ "$cap" == true ]] && { (( FILL_LEFT_B -= sz )) || true; }
        done
        (( used_b < 0 )) && used_b=0
        pct=$(( (used_b * 100 + size_b - 1) / size_b ))
        SH_POOL_NOW=$pct
        FILES=("${FILL_FILES[@]:0:n}")
        (( SH_FILES += n, SH_BYTES += b2, TOTAL_FILES += n, TOTAL_BYTES += b2 )) || true
        if (( pct < target )); then
            SH_REACHED=true; POOL_REACHED[$pool]="$name"
            log INFO "  [DRY-RUN] fill: would move $n further files ($(human "$b2")) oldest first – target reached at about ${pct}%, the rest stays"
        else
            log INFO "  [DRY-RUN] fill: would move all $n further files ($(human "$b2")) – about ${pct}%, target not reached"
        fi
        dry_list
    fi
    [[ -n "$used_b" ]] && POOL_SIM_B[$pool]=$used_b
    return 0
}

process_share() {  # name mode pool pool2
    local name="$1" mode="$2" pool="$3" pool2="$4"
    local src direction age_days age_stat threshold excludes skip until
    local min_age_override

    skip=$(get_ini_val "$name" skip)
    if [[ "$skip" == "yes" ]]; then SH_STATE=skip; log INFO "[$name] skip=yes in INI"; return 0; fi

    case "$mode" in
        yes)
            src="$MNT/$pool/$name"
            direction="$pool -> ${pool2:-array}" ;;
        prefer)
            if [[ "$MOVE_PREFER" != "yes" ]]; then SH_STATE=prefer_off; log INFO "[$name] prefer share, move_prefer_shares=no – skipped"; return 0; fi
            if [[ -n "$pool2" ]]; then src="$MNT/$pool2/$name"; else src="$MNT/user0/$name"; fi
            direction="${pool2:-array} -> $pool" ;;
        *)  return 0 ;;
    esac

    min_age_override=$(get_ini_val "$name" min_age)
    age_stat=$(get_ini_val "$name" age_stat); : "${age_stat:=$GLOBAL_AGE_STAT}"
    threshold=$(get_ini_val "$name" move_when_used_above); : "${threshold:=$GLOBAL_THRESHOLD}"
    until=$(get_ini_val "$name" move_until_used_below); : "${until:=$GLOBAL_UNTIL}"
    excludes=$(get_ini_val "$name" excludes)
    if [[ "$mode" == "prefer" ]]; then
        age_days="${min_age_override:-0}"   # bringing files back to the pool: no age filter unless set per share
    else
        age_days="${min_age_override:-$GLOBAL_MIN_AGE}"
    fi
    SH_DIRECTION="$direction"; SH_AGE="$age_days"; SH_STAT="$age_stat"; SH_THRESHOLD="$threshold"; SH_EXCLUDES="${excludes:-${GLOBAL_EXC:-}}"
    SH_STATE=error
    is_int "$age_days" || { log ERROR "[$name] min_age '$age_days' is not a number – share skipped"; return 1; }
    is_int "$threshold" || { log ERROR "[$name] move_when_used_above '$threshold' is not a number – share skipped"; return 1; }
    is_int "$until" || { log ERROR "[$name] move_until_used_below '$until' is not a number – share skipped"; return 1; }
    if [[ "$mode" == "yes" ]] && (( threshold > 0 && until >= threshold )); then
        log ERROR "[$name] move_until_used_below ${until}% must be below move_when_used_above ${threshold}% – share skipped"; return 1
    fi
    if [[ "$mode" == "yes" ]] && (( threshold > 0 )); then SH_POOL="$pool"; SH_UNTIL=$(( until > 0 ? until : threshold )); fi
    [[ "$age_stat" == "ctime" || "$age_stat" == "mtime" ]] || { log ERROR "[$name] age_stat must be ctime|mtime – share skipped"; return 1; }

    if [[ "$LIST_ONLY" == true ]]; then
        printf '  %-24s %-7s %-24s age>%s d (%s)  threshold %s%%%s  excludes: %s\n' "$name" "$mode" "$direction" "$age_days" "$age_stat" "$threshold" \
            "$( (( until > 0 )) && printf ' until %s%%' "$until")" "${excludes:-${GLOBAL_EXC:-none}}"
        SH_STATE=list
        return 0
    fi

    log INFO "[$name] $direction  (source: $src)"
    CUR_SHARE="$name"; PHASE=share; SH_DONE_FILES=0; SH_DONE_BYTES=0; office_progress
    local asleep=""
    [[ "$DRY_RUN" == false && -z "$pool2" ]] && asleep=$(asleep_disks "$name")
    # office: a prefer share's scan reads the array (/mnt/user0) — its sleeping disks wake now
    [[ -n "$asleep" && "$mode" == "prefer" ]] && log INFO "  array disks asleep: $asleep – this run wakes them"
    if [[ ! -d "$src" ]]; then SH_STATE=no_source; log INFO "  nothing on the source side – skipped"; return 0; fi

    # USO «fill»: a pool -> array share with a trigger (move_when_used_above): after the age moves, still at/above it,
    # the oldest of the rest go until the pool is below the target (move_until_used_below; 0 = below the trigger)
    local fill=false target=0
    if [[ "$mode" == "yes" ]] && (( threshold > 0 )) && [[ "$FORCE_AGE" == false ]]; then
        fill=true; target=$(( until > 0 ? until : threshold ))
        # USO: the fill's cap used up (or 0): no fill for this pool in this run — the age rule as always
        if fill_capped "$pool" && (( FILL_LEFT_B <= 0 )); then
            fill=false
            log INFO "  fill: none in this run (its cap: $(human "$FILL_MAX_B"))"
        fi
    fi
    # Age filter (always: the fill comes on top)
    local -a AGE_ARGS=()
    if [[ "$FORCE_AGE" == true ]]; then
        log WARN "  FORCE: age filter ignored (configured: $age_days days)"
    elif (( age_days > 0 )); then
        local mins=$(( age_days * 1440 )) flag="-cmin"
        [[ "$age_stat" == "mtime" ]] && flag="-mmin"
        AGE_ARGS=("$flag" "+$mins")
        log INFO "  age filter: older than $age_days days ($age_stat)"
    else
        log INFO "  age filter: none (0 days)"
    fi

    # Excludes
    EXCL_ARGS=(-false); EXCL_COUNT=0
    if [[ "$FORCE_ALL" == true ]]; then
        log WARN "  FORCE-ALL: exclude lists ignored"
    else
        local -a EXCL_FILES=()
        IFS=',' read -ra EXCL_FILES <<< "$GLOBAL_EXC,$excludes"
        build_excludes "$name" "$src" "${EXCL_FILES[@]}"
        if [[ -n "${EXCL_MISSING:-}" ]]; then log ERROR "  [$name] an exclude file is missing – share skipped (nothing moved)"; return 1; fi
        (( EXCL_COUNT > 0 )) && log INFO "  excludes: $EXCL_COUNT patterns"
    fi

    # Find candidates (files and symlinks; excluded dirs are pruned, excluded files dropped)
    local -a FILES=()
    find_candidates "$src" "${AGE_ARGS[@]}"
    FILES=("${FOUND_FILES[@]}")

    local count=${#FILES[@]} bytes=0
    (( count > 0 )) && bytes=$(printf '%s\0' "${FILES[@]}" | xargs -0 stat -c %s 2>/dev/null | awk '{s+=$1} END {print s+0}')
    (( TOTAL_FILES += count, TOTAL_BYTES += bytes )) || true
    SH_FILES=$count; SH_BYTES=$bytes
    office_progress      # office: the share's counts on the card at once, before the first file (its disk may still wake)
    if (( count == 0 )) && [[ "$fill" == false ]]; then SH_STATE=none; log INFO "  no files match"; return 0; fi
    (( count == 0 )) && log INFO "  no files older than the age"
    local -A AGED=()
    for f in "${FILES[@]}"; do AGED[$f]=1; done

    if [[ "$DRY_RUN" == true ]]; then
        SH_STATE=dry
        (( count > 0 )) && log INFO "  [DRY-RUN] would move $count files ($(human "$bytes"))"
        dry_list
        [[ "$fill" == true ]] && fill_dry "$name" "$src" "$pool" "$threshold" "$target" "$bytes"
        (( SH_FILES == 0 )) && SH_STATE=none
        return 0
    fi

    # Execute
    # office: moving to the array wakes the disk Unraid's move binary writes to — said once per share, before
    [[ -n "$asleep" && "$mode" == "yes" ]] && log INFO "  array disks asleep: $asleep – this run wakes the one it writes to"
    local -a MOVER_CMD=("$MOVER_BIN")
    (( MOVER_DEBUG > 0 )) && MOVER_CMD+=(-d "$MOVER_DEBUG")
    local dest
    if [[ "$mode" == "yes" ]]; then dest="${pool2:-array}"; else dest="$pool"; fi
    SH_STATE=moved; SH_DONE_FILES=0; SH_DONE_BYTES=0
    [[ -n "$STOP_FILE" ]] && office_partial_leftovers "$name" "$src" "$dest"
    if (( count > 0 )); then
        log INFO "  moving $count files ($(human "$bytes")) via $MOVER_BIN"
        move_batch "$name" "$src" "$dest"
    fi
    [[ "$fill" == true && "$STOPPED" == false ]] && fill_run "$name" "$src" "$dest" "$pool" "$threshold" "$target"
    (( SH_RC != 0 )) && SH_STATE=error
    return 0
}

# ------------------------------------------------------------------ Main
rotate_logs
if [[ "$LIST_ONLY" == false ]]; then
    log INFO "=== Smart Mover start ($([[ "$DRY_RUN" == true ]] && echo DRY-RUN || echo LIVE)) ==="
    [[ -n "$TARGET_SHARES" ]] && log INFO "share filter: $TARGET_SHARES"
    if stock_mover_running && [[ "${CUSTOMMOVER_IGNORE_MOVER:-0}" != "1" ]]; then
        STATUS_MSG="the stock Unraid mover is running"
        log WARN "The stock Unraid mover is running ($MOVER_PIDFILE) – aborting to avoid moving the same files twice. Set CUSTOMMOVER_IGNORE_MOVER=1 to override."
        exit 2
    fi
    if [[ "$DRY_RUN" == false ]]; then
        [[ -n "$MOVER_BIN" && -x "$MOVER_BIN" ]] || die "move binary not found or not executable: '${MOVER_BIN:-<empty>}' (set mover_bin in $INI_FILE)"
    fi
else
    echo "Shares discovered in $SHARES_DIR (mode yes/prefer only):"
fi

shopt -s nullglob
# office: the shares this run will look at, in its order (the progress' steps)
for cfg in "$SHARES_DIR"/*.cfg; do
    p_name=$(basename "$cfg" .cfg)
    p_mode=$(sed -n 's/^shareUseCache="\([^"]*\)".*/\1/p' "$cfg" | tr -d '\r' | head -1)
    [[ "$p_mode" == "yes" || "$p_mode" == "prefer" ]] || continue
    [[ -z "$TARGET_SHARES" || ",$TARGET_SHARES," == *",$p_name,"* ]] || continue
    [[ -d "$MNT/user/$p_name" ]] || continue
    PLAN+=("$p_name")
done
office_progress
FOUND=0
for cfg in "$SHARES_DIR"/*.cfg; do
    SHARE_NAME=$(basename "$cfg" .cfg)
    MODE=$(sed -n 's/^shareUseCache="\([^"]*\)".*/\1/p' "$cfg" | tr -d '\r' | head -1)
    [[ "$MODE" == "yes" || "$MODE" == "prefer" ]] || continue
    if [[ -n "$TARGET_SHARES" && ",$TARGET_SHARES," != *",$SHARE_NAME,"* ]]; then continue; fi
    # USO: a stale .cfg is no share — Unraid lists shares from the folders, /mnt/user shows them (FUSE, no disk asked)
    [[ -d "$MNT/user/$SHARE_NAME" ]] || { log INFO "skip $SHARE_NAME: no such share (stale .cfg)"; continue; }
    POOL=$(sed -n 's/^shareCachePool="\([^"]*\)".*/\1/p' "$cfg" | tr -d '\r' | head -1)
    POOL2=$(sed -n 's/^shareCachePool2="\([^"]*\)".*/\1/p' "$cfg" | tr -d '\r' | head -1)
    [[ -z "$POOL" ]] && POOL="cache"
    (( FOUND++ )) || true
    # office: a stop request (CUSTOMMOVER_STOP) ends the run before the next share — never inside a move
    [[ "$STOPPED" == true ]] && break          # office: stopped inside the share before (after a file, or at once)
    if [[ "$LIST_ONLY" == false && -n "$STOP_FILE" && -e "$STOP_FILE" ]]; then
        STOPPED=true; STOP_SHARE="$SHARE_NAME"
        log WARN "stop requested ($STOP_FILE) – $SHARE_NAME and the shares after it are left for the next run"
        break
    fi
    SH_STATE=""; SH_DIRECTION=""; SH_FILES=0; SH_BYTES=0; SH_MOVED=0; SH_LEFT=0; SH_AGE=""; SH_STAT=""; SH_THRESHOLD=""; SH_EXCLUDES=""
    SH_POOL=""; SH_UNTIL=""; SH_POOL_USED=""; SH_POOL_NOW=""; SH_FILL=false; SH_REACHED=false; SH_RC=0
    process_share "$SHARE_NAME" "$MODE" "$POOL" "$POOL2" || { (( ERRORS++ )) || true; }
    share_status_add "$SHARE_NAME" "$MODE"
done
shopt -u nullglob
PHASE=tidy; CUR_SHARE=""; office_progress

if [[ "$LIST_ONLY" == true ]]; then
    (( FOUND == 0 )) && echo "  (none)"
    exit 0
fi
(( FOUND == 0 )) && log WARN "no share with shareUseCache yes/prefer found in $SHARES_DIR${TARGET_SHARES:+ matching '$TARGET_SHARES'}"
if [[ "$STOPPED" == true ]]; then
    log INFO "=== Stopped: $TOTAL_MOVED moved, $TOTAL_LEFT left (stopped) of $TOTAL_FILES candidates so far ==="
    exit 3
fi
if [[ "$DRY_RUN" == true ]]; then
    log INFO "=== Finished (dry-run): $TOTAL_FILES files, $(human "$TOTAL_BYTES") would move ==="
else
    log INFO "=== Finished: $TOTAL_MOVED moved, $TOTAL_LEFT left of $TOTAL_FILES candidates ($(human "$TOTAL_BYTES")) ==="
fi
exit 0
