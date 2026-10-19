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
#                           USO «per disk»: /mnt/diskN/<share> of the disks the share may use — Unraid's move binary
#                           moves nothing it is given through /mnt/user0
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
#                             hands the move binary one file at a time (the office block «one file at a time»; USO «one
#                             move binary»: through one long-running binary per share, not one process per file) and,
#                             before a share's files, removes leftover <rel>.partial copies whose source still exists
#   CUSTOMMOVER_EXCLUDES_REQUIRED  1 = on a real run (--run) an exclude file that is missing skips its
#                             share with an ERROR (counted as an error) instead of a WARN
#   CUSTOMMOVER_DISKS_INI     emhttpd's disks.ini: on a real run a share that reaches the array names its
#                             array disks that sleep and that the run will wake (an INFO line, nothing else)
#   CUSTOMMOVER_FILL_MAX_BYTES  USO: the fill (below) moves at most this many bytes in this run (0 = no fill; the age rule
#                             is untouched); with it the fill starts at/above the TARGET (the caller decided it must go)
#   CUSTOMMOVER_FILL_MAX_POOL USO: the pool that cap is for (empty: every pool shares it; other pools fill as without it)
#   CUSTOMMOVER_FILL_UNTIL_BYTES  USO (2026-10-14, the office's night run): on that pool (CUSTOMMOVER_FILL_UNTIL_POOL, empty:
#                             every pool) the fill goes — all its shares with a trigger, oldest first — while the pool uses
#                             more than this many bytes (below X: the room Jack Emby fetches into afterwards), not by percent
#   CUSTOMMOVER_SETTLE        USO (2026-10-15): seconds a real fill waits for the pool's reading to catch up once the bytes
#                             it moved cover what the pool was over (default 30; tests 0) — see «moved enough» in fill_pool
# Upstream (helmi1987/custom-mover-for-unraid) this copy matches: 3bee448, with
#   6382fb4  threshold on ZFS: the whole pool from zfs list (used/avail), not df on /mnt/<pool>
#   3bee448  absolute exclude entries match literally (find_literal; the office's own fix, taken upstream)
# USO: a <share>.cfg whose share is gone (no $MNT/user/<share>: a share removed by hand leaves its .cfg)
#      is skipped with an INFO line, never moved (not upstream as of 68ee9ce)
#
# INI (smart_mover.ini):
#   [GLOBAL]  mover_bin, log_file, min_age (days), age_stat (ctime|mtime; USO: newest), global_excludes (comma list),
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
# USO «newest» (2026-10-14, the office): age_stat=newest — a file's age is the YOUNGER of its birth (stat %W where the
#   filesystem has it, else ctime) and its mtime, for the age rule too: older than min_age only when both are.
# USO «oldest first across the pool» (2026-10-14): the fill no longer goes share by share — after the age step of every
#   share, all pool -> array (or secondary pool) shares with a trigger on one pool fill TOGETHER: their files (never an
#   excluded one) in one list, oldest first, a file's age being the YOUNGER of its birth (stat %W where the filesystem
#   has it, else ctime) and its mtime — something touched lately counts as new. A share's file goes only while the pool
#   is at/above that share's target (per share its own X); one file per call, the pool re-read after each. The age rule
#   stays per share. The status keeps `recent`: the last 50 files done [{name: <share>/<rel>, bytes, from, to}].
# USO «trailing space» (2026-10-18): Unraid's move binary drops whitespace at the end of each path it reads, so a name
#   ending in a space or tab never goes to it (given «foo » it moves a file «foo» beside it). Such a file to a pool is
#   moved by this script (copy, compare, rename, then the source removed); to the array it stays with a WARN. After a
#   prefer share's folder pass, an empty folder left on the array whose copy is on the pool is removed (rmdir).
# USO «tidy» (2026-10-19): the emptied folders above moved files are removed deepest first once all are known (upstream
#   kept a parent whose sibling folder emptied later); never the share's root. After a stop a prefer share's emptied
#   folders on its disks go too — its folder there as well — only those whose copy is on the pool (rmdir, nothing else).
# USO «empty folders» (2026-10-19): every direction gets the folder pass (yes, prefer from a second pool too): the folders
#   holding no file below them go to the move binary deepest first, never one an exclude keeps.
# USO «share root» (2026-10-19, seen on a real server: a prefer share's dataset on its second pool stayed, empty): the
#   share's own folder on the source side goes to the move binary too once nothing below it is held — a pool's (a ZFS
#   dataset: the binary destroys it, one with snapshots stays) as well as a disk's, in every direction, as Unraid's mover
#   hands it over; after the fill too. Never in a dry run, never after a stop.
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
FILL_UNTIL_B="${CUSTOMMOVER_FILL_UNTIL_BYTES:-}"   # USO: the night's room for Jack Streamer — fill until the pool uses at most this (bytes)
[[ -z "$FILL_UNTIL_B" || "$FILL_UNTIL_B" =~ ^[0-9]+$ ]] || FILL_UNTIL_B=""
FILL_UNTIL_POOL="${CUSTOMMOVER_FILL_UNTIL_POOL:-}"
SETTLE_S="${CUSTOMMOVER_SETTLE:-30}"              # USO: the fill's wait for the pool's reading (seconds)
[[ "$SETTLE_S" =~ ^[0-9]{1,4}$ ]] || SETTLE_S=30

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
RECENT=()      # USO: the last 50 files done (JSON objects)
recent_add() {  # name bytes from to
    RECENT+=("{\"name\":$(json_str "$1"),\"bytes\":$(json_num "$2"),\"from\":$(json_str "$3"),\"to\":$(json_str "$4")}")
    (( ${#RECENT[@]} > 50 )) && RECENT=("${RECENT[@]: -50}")
    return 0
}
recent_json() { local IFS=,; printf '"recent":[%s]' "${RECENT[*]}"; }
# USO «fill»: the pool, its target, its usage when the share started and the last reading, the target reached in it
SH_POOL=""; SH_UNTIL=""; SH_POOL_USED=""; SH_POOL_NOW=""; SH_FILL=false; SH_REACHED=false; SH_RC=0
FILL_CAPPED=false; CUR_FROM=""
FILL_HELD=false   # USO: a fill moved what its pool was over, but the pool's reading stayed above (status `fill_held`)
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

# USO «oldest first across the pool»: each share's status kept by name (the fill after all shares adds to it), SHARE_JSON
# rebuilt from it
declare -A S_REC=() S_MODE=()
SHARE_ORDER=()
share_json() {  # name mode — SH_* as one share's JSON
    printf '%s' "{\"share\":$(json_str "$1"),\"mode\":$(json_str "$2"),\"state\":$(json_str "$SH_STATE"),\"direction\":$(json_str "$SH_DIRECTION"),\"files\":$(json_num "$SH_FILES"),\"bytes\":$(json_num "$SH_BYTES"),\"moved\":$(json_num "$SH_MOVED"),\"left\":$(json_num "$SH_LEFT"),\"min_age\":$(json_str "$SH_AGE"),\"age_stat\":$(json_str "$SH_STAT"),\"threshold\":$(json_str "$SH_THRESHOLD"),\"excludes\":$(json_str "$SH_EXCLUDES")$(fill_json)}"
}
sh_save() {  # name — SH_* into S_REC (unit separator: an empty field stays a field)
    S_REC[$1]=$(printf '%s\037' "$SH_STATE" "$SH_DIRECTION" "$SH_FILES" "$SH_BYTES" "$SH_MOVED" "$SH_LEFT" "$SH_AGE" "$SH_STAT" "$SH_THRESHOLD" \
        "$SH_EXCLUDES" "$SH_POOL" "$SH_UNTIL" "$SH_POOL_USED" "$SH_POOL_NOW" "$SH_FILL" "$SH_REACHED" "$SH_RC")
}
sh_load() {  # name — S_REC into SH_*
    IFS=$'\037' read -r SH_STATE SH_DIRECTION SH_FILES SH_BYTES SH_MOVED SH_LEFT SH_AGE SH_STAT SH_THRESHOLD SH_EXCLUDES SH_POOL SH_UNTIL \
        SH_POOL_USED SH_POOL_NOW SH_FILL SH_REACHED SH_RC _rest <<< "${S_REC[$1]}"
}
shares_json_rebuild() {
    local n
    SHARE_JSON=()
    for n in "${SHARE_ORDER[@]}"; do sh_load "$n"; SHARE_JSON+=("$(share_json "$n" "${S_MODE[$n]}")"); done
}

share_status_add() {  # name mode — what process_share left in SH_*
    SHARE_ORDER+=("$1"); S_MODE[$1]="$2"; sh_save "$1"
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
    printf '{"mode":"%s","result":"%s","exit":%s,"started":%s,"finished":%s,"files":%s,"bytes":%s,"moved":%s,"left":%s,"errors":%s,"stop_share":%s,"stop_file":%s,"message":%s,"fill_held":%s,"shares":[%s],%s}\n' \
        "$mode" "$result" "$(json_num "$code")" "$STARTED" "$(date +%s)" "$(json_num "${TOTAL_FILES:-0}")" "$(json_num "${TOTAL_BYTES:-0}")" \
        "$(json_num "${TOTAL_MOVED:-0}")" "$(json_num "${TOTAL_LEFT:-0}")" "$(json_num "$ERRORS")" "$(json_str "$STOP_SHARE")" "$(json_str "$LAST_FILE")" "$(json_str "$STATUS_MSG")" \
        "$([[ "$FILL_HELD" == true ]] && echo true || echo false)" "$joined" "$(recent_json)" \
        > "$STATUS_FILE.$$.tmp" 2>/dev/null && mv -f "$STATUS_FILE.$$.tmp" "$STATUS_FILE" 2>/dev/null
    rm -f "$STATUS_FILE.$$.tmp" 2>/dev/null
    return 0
}
[[ -n "$STATUS_FILE" ]] && trap 'write_status $?' EXIT
# USO «array stop» (2026-10-19, D-F8): ended by a signal it says so — bash runs the EXIT trap of an untrapped TERM with the
# last command's status ($? = 0: «ok» for a run cut short); these end it with the signal's own code (failed)
trap 'exit 143' TERM
trap 'exit 130' INT
trap 'exit 129' HUP

# office: while a real run goes, the same file says how far it is (result «running»): the shares it will look at
# (plan), the phase (look, share, tidy), the share and file in flight, what of the share and of the run is done
office_progress() {
    [[ -n "$STATUS_FILE" && "$DRY_RUN" == false && "$LIST_ONLY" == false ]] || return 0
    local plan joined p
    plan=""; for p in "${PLAN[@]}"; do plan+="${plan:+,}$(json_str "$p")"; done
    joined=$(IFS=,; printf '%s' "${SHARE_JSON[*]}")
    printf '{"mode":"run","result":"running","errors":%s,"warnings":%s,"started":%s,"time":%s,"plan":[%s],"phase":"%s","share":%s,"file":%s,"share_files":%s,"share_bytes":%s,"share_done_files":%s,"share_done_bytes":%s,"done_files":%s,"done_bytes":%s,"files":%s,"bytes":%s%s,"shares":[%s],%s}\n' \
        "$(json_num "$ERRORS")" "$(json_num "$WARNINGS")" "$STARTED" "$(date +%s)" "$plan" "$PHASE" "$(json_str "$CUR_SHARE")" "$(json_str "$CUR_FILE")" "$(json_num "$SH_FILES")" "$(json_num "$SH_BYTES")" \
        "$(json_num "$SH_DONE_FILES")" "$(json_num "$SH_DONE_BYTES")" "$(json_num "$RUN_DONE_FILES")" "$(json_num "$RUN_DONE_BYTES")" \
        "$(json_num "${TOTAL_FILES:-0}")" "$(json_num "${TOTAL_BYTES:-0}")" "$(fill_json)" "$joined" "$(recent_json)" \
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
[[ "$GLOBAL_AGE_STAT" =~ ^(ctime|mtime|newest)$ ]] || die "age_stat must be ctime, mtime or newest"
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
# USO «per disk» (2026-10-18): the source may be several roots, one per line (a prefer share's /mnt/diskN/<share>) —
# such a pattern then stands for each of them
build_excludes() {  # share_name source_root(s) exclude_files(comma list)...
    local share="$1" src="$2"; shift 2
    EXCL_ARGS=(-false)
    EXCL_COUNT=0
    EXCL_MISSING=""
    local f line one root
    local -a roots=() alts=()
    mapfile -t roots <<< "$src"
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
            alts=("$line")
            if [[ "$line" == "$MNT/user/$share"* || "$line" == "$MNT/user0/$share"* ]]; then
                alts=()
                for root in "${roots[@]}"; do
                    one="${line/#"$MNT/user/$share"/"$root"}"      # USO: quoted — a share or path with [ ] * ? is no pattern
                    one="${one/#"$MNT/user0/$share"/"$root"}"
                    alts+=("$one")
                done
            fi
            for one in "${alts[@]}"; do
                case "$one" in
                    /*)  # absolute path: file or subtree, matched literally
                        find_literal "$one"
                        EXCL_ARGS+=(-o -path "$FIND_LITERAL" -o -path "$FIND_LITERAL/*") ;;
                    *[\*\?\[]*)  # glob
                        if [[ "$one" == */* ]]; then EXCL_ARGS+=(-o -path "$one"); else EXCL_ARGS+=(-o -name "$one"); fi ;;
                    *)   # substring (legacy)
                        EXCL_ARGS+=(-o -path "*$one*") ;;
                esac
            done
            (( EXCL_COUNT++ )) || true
        done < "$f"
    done
}

# ------------------------------------------------------------------ Cleanup
# USO «tidy» (2026-10-19, seen on Tower): every folder above a moved file is looked at once, deepest first, after all
# of them are known — upstream gave up on a parent the first time it still held a sibling folder, so an emptied parent
# (uso-yes/m3 once m3/a and m3/b were gone) stayed. Never the share's root; with `to` (a prefer share's folder on its
# pool, per disk: «per disk» above) a folder goes only when its copy is there — the disk's share folder too, as the
# folder pass would move it (a stopped run skips that pass).
remove_empty_parents() {  # root (USO «per disk»: one per line) to ('' or the share's folder on the target) file...
    local roots="$1" to="$2" root; shift 2
    local -A seen=() top=()
    local f d
    for f in "$@"; do
        rel_of "$f" "$roots"; root="${f%"/$REL"}"
        d="${f%/*}"
        while [[ "$d" == "$root/"* && -z "${seen[$d]:-}" ]]; do seen[$d]=1; top[$d]="$root"; d="${d%/*}"; done
        [[ -n "$to" && "$d" == "$root" && -z "${seen[$d]:-}" ]] && { seen[$d]=1; top[$d]="$root"; }
    done
    (( ${#seen[@]} > 0 )) || return 0
    # deepest first: in C order a folder sorts before everything below it
    while IFS= read -r -d '' d; do
        [[ -d "$d" && ! -L "$d" ]] || continue
        if [[ -n "$to" ]]; then
            root="${top[$d]}"
            if [[ "$d" == "$root" ]]; then [[ -d "$to" ]] || continue
            else [[ -d "$to/${d#"$root/"}" ]] || continue; fi
        fi
        rmdir -- "$d" 2>/dev/null && log INFO "  removed empty directory: $d"
    done < <(printf '%s\0' "${!seen[@]}" | LC_ALL=C sort -rz)
    return 0
}

# ------------------------------------------------------------------ Share processing
TOTAL_FILES=0; TOTAL_BYTES=0; TOTAL_MOVED=0; TOTAL_LEFT=0

# ------------------------------------------------------------------ office: one file at a time
# With CUSTOMMOVER_STOP set (the office), a real run hands the move binary one file at a time: before each file the
# stop request is looked at — set, no further file starts and the file in flight has finished («stop after this file»).
# There is no harder stop: Unraid's move catches TERM and finishes the file in flight anyway (seen 2026-10-13).
# Without CUSTOMMOVER_STOP the share's list goes to the move binary at once, as upstream.
# USO «one move binary» (2026-10-18): one file at a time no longer means one move process per file (a fork of move, and
# move's own fuser and losetup, for each of 120 000 small files: hours). ONE move binary per share reads the paths from a
# pipe, handed over one by one: a file is done once its source is gone, or once the binary waits on its stdin again
# (it skipped the file: in use, already at the destination) — seen in /proc/<pid>/syscall (read on fd 0; its children
# too, for a script around move), or once the binary is gone (then a new one for the next file). Without
# /proc/<pid>/syscall (never on Unraid): one process per file, as before.

MV_PID=""; MV_FD=""; MV_RC=0; SLP_FD=""; MV_READS=""
MV_LONG=false
{ read -r _ < /proc/self/syscall; } 2>/dev/null && MV_LONG=true

mover_open() {  # out — the move binary started, reading paths from a pipe (MV_FD), its output to out
    local d
    d=$(mktemp -d) && mkfifo "$d/in" || { [[ -n "${d:-}" ]] && rm -rf "$d"; return 1; }
    trap - PIPE
    "${MOVER_CMD[@]}" < "$d/in" >> "$1" 2>&1 &
    MV_PID=$!
    exec {MV_FD}> "$d/in"
    trap '' PIPE                      # the binary gone: a write fails (and is seen), never ends this script
    rm -f "$d/in"; rmdir "$d"
    if [[ -z "$SLP_FD" ]] && d=$(mktemp -d) && mkfifo "$d/s"; then
        exec {SLP_FD}<> "$d/s"        # nothing ever written: read -t on it is a sleep without a process
        rm -f "$d/s"; rmdir "$d"
    fi
    return 0
}

mover_close() {  # the pipe closed (the binary finishes what it read), its exit -> MV_RC
    MV_RC=0
    [[ -n "$MV_PID" ]] || return 0
    exec {MV_FD}>&-
    wait "$MV_PID"; MV_RC=$?
    MV_PID=""; MV_FD=""
    trap - PIPE
    return 0
}

mover_reads() {  # -> MV_READS: the binary's own read syscalls so far (its thread's syscr — /proc/<pid>/io adds the reaped
    # children's; empty when unknown)
    local k v
    MV_READS=""
    { while read -r k v; do [[ "$k" == syscr: ]] && { MV_READS=$v; break; }; done < "/proc/$MV_PID/task/$MV_PID/io"; } 2>/dev/null
    return 0
}

# 1: the binary looks idle — blocked in read(0) (or a child of it, for a script around move), its read count in MV_READS;
# 0: busy; 2: gone. mover_send counts it as waiting only when seen twice in a row, ≥ 1 ms apart, without a read in
# between, and more reads than before the path was written: right after the write the kernel may still show the old
# read for a moment (a wakeup on another CPU), and a path can reach the binary in two pieces (seen on a VM).
mover_waiting() {
    local a b r k kids=""
    { read -r a b r < "/proc/$MV_PID/syscall"; } 2>/dev/null || return 2
    if [[ "$a" == 0 && "$b" == 0x0 ]]; then mover_reads; return 1; fi
    if [[ "$a" == -1 ]]; then         # not in a syscall: a zombie?
        { read -r r < "/proc/$MV_PID/stat"; } 2>/dev/null || return 2
        r=${r##*) }; [[ "${r:0:1}" == [ZX] ]] && return 2
    fi
    { read -r kids < "/proc/$MV_PID/task/$MV_PID/children"; } 2>/dev/null
    for k in $kids; do
        if { read -r a b r < "/proc/$k/syscall"; } 2>/dev/null && [[ "$a" == 0 && "$b" == 0x0 ]]; then MV_READS="kid:$k"; return 1; fi
    done
    return 0
}

mover_send() {  # path — to the running binary; back once it is done with it (0), or the binary is gone (1)
    local p="$1" d=0.001 w n=0 before seen=""
    mover_reads; before=$MV_READS
    printf '%s\n' "$p" >&"$MV_FD" 2>/dev/null || return 1
    while :; do
        [[ -e "$p" || -L "$p" ]] || return 0
        mover_waiting; w=$?
        (( w == 2 )) && return 1
        if (( w == 1 )) && [[ "$MV_READS" == kid:* || -z "$before" || -z "$MV_READS" || "$MV_READS" -gt "$before" ]]; then
            [[ -n "$seen" && "$seen" == "$MV_READS" ]] && return 0
            seen=$MV_READS
        else
            seen=""
        fi
        # a small file takes the binary ~10 ms (its fuser and losetup): look every 1–2 ms for the first 0.1 s, then
        # every 10 ms, after 1 s every 50 ms (a big file)
        (( ++n == 5 )) && d=0.002
        (( n == 50 )) && d=0.01
        (( n == 140 )) && d=0.05
        if [[ -n "$SLP_FD" ]]; then read -r -t "$d" -u "$SLP_FD" _ 2>/dev/null; else sleep "$d"; fi
    done
}

PROGRESS_AT=0
office_progress_tick() {  # office_progress at most once a second (it forks a few times; 120 000 files) — force: now
    local now
    printf -v now '%(%s)T' -1
    [[ "${1:-}" == force || "$now" != "$PROGRESS_AT" ]] || return 0
    PROGRESS_AT=$now
    office_progress
}

# USO «per disk» (2026-10-18): a prefer share's array side as Unraid's mover scans it — /mnt/disk1/<share> …
# /mnt/disk29/<share>, only the disks the share may use (its cfg's shareInclude, else all, less shareExclude; another
# disk is never asked). Unraid's move binary moves nothing it is given through /mnt/user0 (it answers «No such file or
# directory» and exits 0, seen on 7.4 and 7.3) — prints the roots, one per line
prefer_disk_roots() {  # share
    local cfg="$SHARES_DIR/$1.cfg" inc exc d n out=""
    inc=$(sed -n 's/^shareInclude="\([^"]*\)".*/\1/p' "$cfg" 2>/dev/null | tr -d '\r' | head -1)
    exc=$(sed -n 's/^shareExclude="\([^"]*\)".*/\1/p' "$cfg" 2>/dev/null | tr -d '\r' | head -1)
    for d in "$MNT"/disk[1-9] "$MNT"/disk[1-9][0-9]; do
        n=${d##*/}
        [[ -n "$inc" && ",$inc," != *",$n,"* ]] && continue
        [[ -n "$exc" && ",$exc," == *",$n,"* ]] && continue
        [[ -d "$d/$1" && ! -L "$d/$1" ]] && out+="${out:+$'\n'}$d/$1"
    done
    printf '%s' "$out"
}

rel_of() {  # file src(one root per line) -> REL: the file's path below its root
    local r
    REL="$1"
    while IFS= read -r r; do [[ -n "$r" && "$1" == "$r/"* ]] && { REL="${1#"$r/"}"; return 0; }; done <<< "$2"
    return 0
}

office_disk_awake() {  # disk — not spun down in emhttpd's disks.ini (no ini: counted as awake)
    [[ -n "$DISKS_INI" && -r "$DISKS_INI" ]] || return 0
    awk -v d="$1" '/^\[/ { s = $0; gsub(/[\["\]]/, "", s); next } s == d && $0 == "spundown=\"1\"" { found = 1 } END { exit found ? 1 : 0 }' "$DISKS_INI"
}

# office: before a real run moves a share's files — Unraid's move writes <dest>/<share>/<rel>.partial and renames it at
# the end (seen 2026-10-12); one left behind (a power cut, a crash) is removed, but only while its source <src>/<rel>
# still exists, only on the share's awake destinations (an awake array disk, or the target pool), only a plain file
# named exactly so — never a file under its final name
office_partial_leftovers() {  # share src dest(array|<pool>)
    local share="$1" src="$2" dest="$3" d p rel s at
    local -a roots=()
    if [[ "$dest" == array ]]; then
        for d in "$MNT"/disk[0-9]*; do [[ -d "$d/$share" ]] && office_disk_awake "$(basename "$d")" && roots+=("$d"); done
    else
        [[ -d "$MNT/$dest/$share" ]] && office_disk_awake "$dest" && roots+=("$MNT/$dest")
    fi
    local -a srcs=()
    mapfile -t srcs <<< "$src"            # USO «per disk»: a prefer share's roots, one per line
    for d in "${roots[@]}"; do
        while IFS= read -r -d '' p; do
            rel="${p#"$d/$share"/}"; rel="${rel%.partial}"
            [[ -n "$rel" && "$rel" != *"/../"* && "$rel" != "../"* ]] || continue
            [[ ! -L "$p" ]] || continue
            at=""
            for s in "${srcs[@]}"; do [[ -e "$s/$rel" ]] && { at="$s/$rel"; break; }; done
            [[ -n "$at" ]] || continue
            rm -f -- "$p" && log WARN "  removed a leftover partial copy $p – its file is still at $at"
        done < <(find "$d/$share" -xdev -type f -name '*.partial' -print0 2>/dev/null)
    done
    return 0
}

# The share's files to the move binary one by one (USO «one move binary»: through one binary while it lasts); OFFICE_RC
# the last non-zero exit
office_move_files() {  # share src dest out
    local share="$1" src="$2" dest="$3" out="$4" f r size rec first=true
    local -A SZ=()
    OFFICE_RC=0; OFFICE_TRIED=0
    # the sizes in one go (a stat per file was a process per file)
    while IFS= read -r -d '' rec; do SZ[${rec#*$'\t'}]=${rec%%$'\t'*}; done \
        < <(printf '%s\0' "${FILES[@]}" | xargs -0 -r stat --printf '%s\t%n\0' 2>/dev/null)
    for f in "${FILES[@]}"; do
        if [[ -e "$STOP_FILE" ]]; then
            STOPPED=true; STOP_SHARE="$share"
            log WARN "stop requested ($STOP_FILE) – stopped after ${LAST_FILE:-the files before}; the rest of $share and the shares after it are left for the next run"
            break
        fi
        size=${SZ[$f]:-0}
        CUR_FILE="$f"
        if [[ "$first" == true ]]; then office_progress_tick force; first=false; else office_progress_tick; fi
        (( OFFICE_TRIED++ )) || true
        r=0
        if [[ "$MV_LONG" == true ]] && { [[ -n "$MV_PID" ]] || mover_open "$out"; }; then
            if ! mover_send "$f"; then
                mover_close; r=$MV_RC            # the binary gone in the middle: its exit; the next file gets a new one
            fi
        else
            printf '%s\n' "$f" | "${MOVER_CMD[@]}" >>"$out" 2>&1
            r=$?
        fi
        (( r != 0 )) && OFFICE_RC=$r
        LAST_FILE="$f"
        if [[ ! -e "$f" && ! -L "$f" ]]; then
            (( SH_DONE_FILES++, RUN_DONE_FILES++ )) || true
            (( SH_DONE_BYTES += size, RUN_DONE_BYTES += size )) || true
        fi
    done
    if [[ -n "$MV_PID" ]]; then
        mover_close
        (( MV_RC != 0 )) && OFFICE_RC=$MV_RC
    fi
    CUR_FILE=""
    office_progress
}

# USO «per disk»: a prefer share's folders on the array side, deepest first, for Unraid's mover's second pass (its
# «find -depth -type d» to the move binary: an empty folder goes to the pool — owner, mode and times with it — one still
# holding something stays); never an excluded one -> DIRS
array_dirs() {  # src (one root per line)
    local -a roots=() pre=()
    local i
    mapfile -t roots <<< "$1"
    mapfile -d '' pre < <(find "${roots[@]}" \( "${EXCL_ARGS[@]}" \) -prune -o -type d -print0 2>/dev/null)
    DIRS=()
    for (( i = ${#pre[@]} - 1; i >= 0; i-- )); do [[ "${pre[i]}" == *$'\n'* ]] || DIRS+=("${pre[i]}"); done
    return 0
}

# USO «trailing space» (2026-10-18, seen on a real server: 51 312 files of a share moved to the pool, 57 empty folders
# left on the disk, every leaf named like «06 Juni »): Unraid's move binary drops the whitespace at the end of each path
# it reads (7.4.0-rc.1 on Tower: «No such file or directory» for «…/06 Juni», exit 0) — a file or folder whose name ends
# in a space or tab is never moved, and given «foo » it moves a file «foo» beside it (an excluded one too). Unraid's own
# mover leaves them the same way. Such a name never goes to the binary: a file whose destination is a pool is moved here
# (office_ws_move), one for the array stays (WARN: which disk is the binary's choice); the folders: office_dirs_left.
ends_ws() { [[ "$1" == *[[:space:]] ]]; }

# the folder dst made like src (owner, mode), each missing parent like its source counterpart; never above $MNT/<pool>
# (the caller's) -> MADE_D/MADE_S, deepest first — their times set by office_made_times once their content is there
office_mkdir_like() {  # dst src
    local dst="$1" src="$2" i
    local -a td=() ts=()
    while [[ ! -d "$dst" || -L "$dst" ]]; do
        [[ -e "$dst" || -L "$dst" ]] && return 1          # something else under that name
        [[ -d "$src" && ! -L "$src" ]] || return 1
        td+=("$dst"); ts+=("$src")
        dst=$(dirname "$dst"); src=$(dirname "$src")
        [[ "$dst" == "$MNT" || "$dst" == / || "$dst" == . ]] && return 1
    done
    for (( i = ${#td[@]} - 1; i >= 0; i-- )); do
        mkdir -- "${td[i]}" 2>/dev/null || return 1
        chown --reference="${ts[i]}" -- "${td[i]}" 2>/dev/null
        chmod --reference="${ts[i]}" -- "${td[i]}" 2>/dev/null
    done
    MADE_D+=("${td[@]}"); MADE_S+=("${ts[@]}")
    return 0
}
office_made_times() {
    local i
    for (( i = 0; i < ${#MADE_D[@]}; i++ )); do touch -r "${MADE_S[i]}" -- "${MADE_D[i]}" 2>/dev/null; done
    MADE_D=(); MADE_S=()
}

# USO «trailing space»: one file (or symlink) to its place on a pool, as the move binary would take it — only when
# nothing is there yet, it is not in use and has no other hard link; copied beside it as <name>.partial (owner, mode,
# times, xattrs: cp -a; office_partial_leftovers knows that name), compared, renamed (never over something that came
# meanwhile), the source removed only then. 0: moved; 1: left, WS_WHY says why
office_ws_move() {  # file to
    local f="$1" to="$2" tmp="$2.partial" a b
    WS_WHY=""
    if [[ ! -L "$f" ]]; then
        [[ -f "$f" ]] || { WS_WHY="not a plain file"; return 1; }
        [[ "$(stat -c %h -- "$f" 2>/dev/null)" == 1 ]] || { WS_WHY="it has hard links"; return 1; }
    fi
    [[ -e "$to" || -L "$to" ]] && { WS_WHY="already at the destination"; return 1; }
    [[ -e "$tmp" || -L "$tmp" ]] && { WS_WHY="$tmp is in the way"; return 1; }
    if command -v fuser >/dev/null 2>&1 && fuser -s "$f" >/dev/null 2>&1; then WS_WHY="in use"; return 1; fi
    MADE_D=(); MADE_S=()
    office_mkdir_like "$(dirname "$to")" "$(dirname "$f")" || { WS_WHY="no folder for it at the destination"; MADE_D=(); MADE_S=(); return 1; }
    WS_WHY="the copy failed (no room?)"
    if cp -a --no-target-directory -- "$f" "$tmp" 2>/dev/null; then
        a=$(stat -c '%s %u %g %a %Y' -- "$f" 2>/dev/null); b=$(stat -c '%s %u %g %a %Y' -- "$tmp" 2>/dev/null)
        if [[ -L "$f" ]]; then [[ -L "$tmp" && "$(readlink -- "$f")" == "$(readlink -- "$tmp")" ]] || WS_WHY="the copy differs"
        else cmp -s -- "$f" "$tmp" || WS_WHY="the copy differs"; fi
        [[ -n "$a" && "$a" == "$b" ]] || WS_WHY="the copy differs"
        if [[ "$WS_WHY" == "the copy failed (no room?)" ]]; then
            mv -n -T -- "$tmp" "$to" 2>/dev/null
            if [[ -e "$tmp" || -L "$tmp" ]]; then WS_WHY="already at the destination"
            elif [[ -e "$to" || -L "$to" ]]; then
                rm -f -- "$f"
                office_made_times
                [[ -e "$f" || -L "$f" ]] && { WS_WHY="copied, but the source could not be removed"; return 1; }
                WS_WHY=""; return 0
            fi
        fi
    fi
    rm -f -- "$tmp"
    office_made_times
    return 1
}

# USO «trailing space»: after a share's folder pass (DIRS, deepest first) a folder still on the source side that is
# empty goes when its counterpart is at the destination already (its files went there: a name ending in whitespace the
# binary could not take, or the parent that still held it when the binary came) — rmdir, never a folder holding anything;
# an empty one ending in whitespace without a counterpart is made on a pool first (owner, mode, times), as the binary would.
# C-M4 (2026-10-19): the destination the array (a yes share) — there is no /mnt/array: a counterpart on any data disk
# counts; one without a counterpart stays there (which disk is the binary's choice), as Unraid's mover leaves it
office_dirs_left() {  # share src dest
    local name="$1" src="$2" dest="$3" d to r b gone=0 made=0 where="" onto="$3"
    local -A ROOT=()
    local -a bases=()
    while IFS= read -r r; do [[ -n "$r" ]] && ROOT[$r]=1; done <<< "$src"
    if [[ "$dest" == array ]]; then
        for b in "$MNT"/disk[0-9]*; do [[ -d "$b" && ! -L "$b" ]] && bases+=("$b"); done
        onto="the array"
    elif [[ -d "$MNT/$dest" ]]; then
        bases=("$MNT/$dest")
    fi
    (( ${#bases[@]} > 0 )) || return 0
    for d in "${DIRS[@]}"; do
        [[ -d "$d" && ! -L "$d" ]] || continue
        # the share's own folder towards the array: share_root_said's (the binary had it; a pool's may be a dataset)
        [[ "$dest" == array && -n "${ROOT[$d]:-}" ]] && continue
        if [[ -n "${ROOT[$d]:-}" ]]; then REL=""; else rel_of "$d" "$src"; fi
        to=""
        for b in "${bases[@]}"; do
            r="$b/$name${REL:+/$REL}"
            [[ -d "$r" && ! -L "$r" ]] && { to="$r"; break; }
        done
        if [[ -z "$to" ]]; then
            [[ "$dest" == array ]] && continue
            to="$MNT/$dest/$name${REL:+/$REL}"
            ends_ws "$d" || continue
            [[ -z "$(find "$d" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]] || continue
            MADE_D=(); MADE_S=()
            office_mkdir_like "$to" "$d" || { MADE_D=(); MADE_S=(); continue; }
            office_made_times
            (( made++ )) || true
        fi
        rmdir -- "$d" 2>/dev/null && { (( gone++ )) || true; }
    done
    [[ "$src" == "$MNT"/disk[0-9]* ]] && where=" on the array"
    (( gone > 0 )) && log INFO "  $gone empty folders the move binary left$where removed – they are on $onto$( (( made > 0 )) && printf ' (%s made there first: a name ending in a space or tab)' "$made")"
    return 0
}

# USO «per disk»: the share's folders on the array side to the move binary (Unraid's mover's second pass), deepest first,
# never one whose name ends in whitespace (USO «trailing space»), then office_dirs_left.
# USO «empty folders» (2026-10-19, seen on a real server: a share moved off its pool left .tmp/, shares/counters/,
# shares/files/ — empty before the run, so no file ever took them along): every direction gets the pass, and only the
# folders that hold no file below them go to the binary — one empty, or holding only empty folders (it makes each on
# the target with owner, mode and times and removes it here); never one an exclude keeps (nor a folder above it).
# USO «share root»: the share's own folder on the source side too, in every direction (a pool's — a ZFS dataset the
# binary destroys — as well as a disk's), as Unraid's mover hands it over: share_root_said
share_dirs_pass() {  # share src dest
    local name="$1" src="$2" dest="$3" dirs_out gone=0 d f rc line r
    local -a DIRS=() give=() roots=()
    local -A held=() isroot=()
    array_dirs "$src"
    mapfile -t roots <<< "$src"
    for r in "${roots[@]}"; do [[ -n "$r" ]] && isroot[$r]=1; done
    # what holds a folder: a file (or link, …) below it, or an entry an exclude keeps
    while IFS= read -r -d '' f; do
        d="${f%/*}"
        while [[ -n "$d" && "$d" != / && -z "${held[$d]:-}" ]]; do
            held[$d]=1
            [[ -n "${isroot[$d]:-}" ]] && break
            d="${d%/*}"
        done
    done < <(find "${roots[@]}" -mindepth 1 \( \( "${EXCL_ARGS[@]}" \) -prune -print0 \) -o \( ! -type d -print0 \) 2>/dev/null)
    for d in "${DIRS[@]}"; do
        [[ -n "${held[$d]:-}" ]] && continue
        ends_ws "$d" || give+=("$d")
    done
    if (( ${#give[@]} > 0 )); then
        dirs_out=$(mktemp)
        printf '%s\n' "${give[@]}" | "${MOVER_CMD[@]}" >"$dirs_out" 2>&1
        rc=$?
        while IFS= read -r line; do log DEBUG "  mover: $line"; done < "$dirs_out"
        rm -f "$dirs_out"
        for d in "${give[@]}"; do [[ -n "${isroot[$d]:-}" || -e "$d" ]] || (( gone++ )) || true; done
        (( rc != 0 )) && log WARN "  move binary exited with code $rc on the empty folders"
        (( gone > 0 )) && log INFO "  $gone empty folders moved to $dest"
        for d in "${give[@]}"; do [[ -n "${isroot[$d]:-}" ]] && share_root_said "$d"; done
    fi
    (( ${#DIRS[@]} > 0 )) && office_dirs_left "$name" "$src" "$dest"
    return 0
}

# USO «share root»: what became of a share's folder handed to the move binary — gone: said; still there as a ZFS
# dataset of its own with snapshots (the binary leaves those, as with Unraid's mover): said, one line
share_root_said() {  # root
    local root="$1" ds mp snaps
    if [[ ! -e "$root" && ! -L "$root" ]]; then log INFO "  share folder $root removed (empty)"; return 0; fi
    command -v "$ZFS_BIN" >/dev/null 2>&1 || return 0
    IFS=$'\t' read -r ds mp <<< "$("$ZFS_BIN" list -H -o name,mountpoint "$root" 2>/dev/null)"
    [[ -n "$ds" && "$mp" == "$root" ]] || return 0
    snaps=$("$ZFS_BIN" list -H -t snapshot -o name -d 1 "$ds" 2>/dev/null)
    [[ -n "$snaps" ]] && log INFO "  kept: $ds has snapshots"
    return 0
}

# USO «share root»: after the fill, a share's folder on the pool that holds nothing any more to the move binary (as
# Unraid's mover would take it)
share_root_pass() {  # root
    local root="$1" line
    [[ -d "$root" && ! -L "$root" ]] && ! ends_ws "$root" || return 0
    [[ -z "$(find "$root" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]] || return 0
    while IFS= read -r line; do log DEBUG "  mover: $line"; done < <(printf '%s\n' "$root" | "${FILL_MOVER[@]}" 2>&1)
    share_root_said "$root"
}

# USO «trailing space»: the files of WS (names ending in whitespace, never given to the binary) — to a pool moved here,
# to the array left; the stop file looked at before each -> WS_TRIED (handed on), the moved ones counted as done
office_ws_files() {  # share src dest
    local name="$1" src="$2" dest="$3" f sz ok=0 i=0
    WS_TRIED=0
    for f in "${WS[@]}"; do
        [[ "$STOPPED" == true ]] && break
        if [[ -n "$STOP_FILE" && -e "$STOP_FILE" ]]; then
            STOPPED=true; STOP_SHARE="$name"
            log WARN "stop requested ($STOP_FILE) – stopped after ${LAST_FILE:-the files before}; the rest of $name and the shares after it are left for the next run"
            break
        fi
        (( WS_TRIED++ )) || true
        if [[ "$dest" == array ]]; then
            WS_WHY="its name ends in a space or tab, which Unraid's move binary drops, and the array disk is the binary's choice – rename it to move it"
        else
            rel_of "$f" "$src"
            sz=$(stat -c %s -- "$f" 2>/dev/null); is_int "$sz" || sz=0
            CUR_FILE="$f"; office_progress_tick
            if office_ws_move "$f" "$MNT/$dest/$name/$REL"; then
                LAST_FILE="$f"
                (( ok++, SH_DONE_FILES++, RUN_DONE_FILES++, SH_DONE_BYTES += sz, RUN_DONE_BYTES += sz )) || true
                continue
            fi
            LAST_FILE="$f"
        fi
        (( ++i <= 10 )) && log WARN "  left: $f – $WS_WHY"
    done
    CUR_FILE=""
    (( i > 10 )) && log WARN "  ... $(( i - 10 )) more left whose name ends in a space or tab"
    (( ok > 0 )) && log INFO "  $ok files whose name ends in a space or tab moved to $dest by the script itself (Unraid's move binary drops that space)"
    return 0
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
find_candidates() {  # src (USO: one root per line) [find tests...]
    local src="$1"; shift
    local find_err f line
    local -a raw=() roots=()
    mapfile -t roots <<< "$src"
    FOUND_FILES=()
    find_err=$(mktemp)
    mapfile -d '' raw < <(find "${roots[@]}" \( "${EXCL_ARGS[@]}" \) -prune -o \( -type f -o -type l \) "$@" -print0 2>"$find_err")
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

# FILES to the move binary (one by one with CUSTOMMOVER_STOP, else at once), then what is still at the source; the
# counts to SH_MOVED/SH_LEFT and the run's totals, SH_RC the move binary's last failure (the fill: fill_pool)
move_batch() {  # share src dest
    local name="$1" src="$2" dest="$3" mover_out rc f line rec
    # USO: the sizes of the last files (before they go) for `recent`
    local -A SIZE_OF=()
    while IFS= read -r -d '' rec; do SIZE_OF[${rec#*$'\t'}]=${rec%%$'\t'*}; done \
        < <(printf '%s\0' "${FILES[@]:$(( ${#FILES[@]} > 50 ? ${#FILES[@]} - 50 : 0 ))}" | xargs -0 -r stat --printf '%s\t%n\0' 2>/dev/null)
    # USO «trailing space»: a name ending in whitespace never goes to the move binary (WS: office_ws_files, after it)
    local -a WS=() keep=()
    for f in "${FILES[@]}"; do if ends_ws "$f"; then WS+=("$f"); else keep+=("$f"); fi; done
    FILES=("${keep[@]}")
    mover_out=$(mktemp)
    OFFICE_NOT_STARTED=0; rc=0
    if [[ -n "$STOP_FILE" ]]; then
        # office: one file at a time (see «one file at a time» above)
        : > "$mover_out"
        office_move_files "$name" "$src" "$dest" "$mover_out"
        rc=$OFFICE_RC
        # office: stopped — only the files handed over are verified; the files never started stay and count as left
        [[ "$STOPPED" == true ]] && OFFICE_NOT_STARTED=$(( ${#FILES[@]} - OFFICE_TRIED ))
        [[ "$STOPPED" == true ]] && FILES=("${FILES[@]:0:OFFICE_TRIED}")
    elif (( ${#FILES[@]} > 0 )); then
        printf '%s\n' "${FILES[@]}" | "${MOVER_CMD[@]}" >"$mover_out" 2>&1
        rc=$?
    fi
    if (( ${#WS[@]} > 0 )); then
        office_ws_files "$name" "$src" "$dest"
        (( OFFICE_NOT_STARTED += ${#WS[@]} - WS_TRIED )) || true
        FILES+=("${WS[@]:0:WS_TRIED}")
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
    for f in "${MOVED[@]:$(( ${#MOVED[@]} > 50 ? ${#MOVED[@]} - 50 : 0 ))}"; do rel_of "$f" "$src"; recent_add "$name/$REL" "${SIZE_OF[$f]:-0}" "$CUR_FROM" "$dest"; done
    # USO «per disk», «empty folders»: the source's empty folders as Unraid's mover leaves them (its second pass, one
    # move binary for all); after a stop only the empty parents of what moved, at once
    if [[ "$STOPPED" == false ]]; then
        share_dirs_pass "$name" "$src" "$dest"
    elif (( ${#MOVED[@]} > 0 )); then
        # USO «tidy»: after a stop a prefer share's emptied folders on its disks go too (their copy on the pool)
        if [[ "${dirs_pass:-false}" == true ]]; then remove_empty_parents "$src" "$MNT/$dest/$name" "${MOVED[@]}"
        else remove_empty_parents "$src" "" "${MOVED[@]}"; fi
    fi
    return 0
}

# USO: does CUSTOMMOVER_FILL_MAX_BYTES hold for this pool? (set, and for it or for every pool)
fill_capped() {  # pool
    [[ -n "$FILL_MAX_B" ]] && [[ -z "$FILL_MAX_POOL" || "$FILL_MAX_POOL" == "$1" ]]
}

# USO «oldest first across the pool»: process_share registers a share with a trigger here; the fill runs after every
# share's age step, one pool at a time (fill_pool)
declare -A FS_SRC=() FS_DEST=() FS_POOL=() FS_THR=() FS_TGT=() FS_EXC=() AGED_ALL=() POOL_AGED_B=() POOL_AGE_MOVED=()
FILL_SHARES=(); FILL_POOLS=()
fill_register() {  # name src dest pool threshold target excludes(comma list)
    FILL_SHARES+=("$1"); FS_SRC[$1]="$2"; FS_DEST[$1]="$3"; FS_POOL[$1]="$4"; FS_THR[$1]="$5"; FS_TGT[$1]="$6"; FS_EXC[$1]="$7"
    [[ " ${FILL_POOLS[*]} " == *" $4 "* ]] || FILL_POOLS+=("$4")
    return 0
}

# The candidates of a pool's triggered shares in one list, oldest first by the younger of birth (else ctime) and mtime,
# ties by name -> FILL_FILES, FILL_SIZES, FILL_PHYS, FILL_OWNER (the share); never an excluded file, never one of the age step.
# USO «pool bytes» (2026-10-15): FILL_PHYS = what the file takes on the pool — its allocated bytes (st_blocks; ZFS counts
# them after compression), never more than its size (a small file's block, a file not yet synced)
fill_pool_candidates() {  # share...
    local name f rec key sz rest b c m nb bs ph
    local list
    list=$(mktemp)
    for name in "$@"; do
        local -a EXCL_FILES=()
        IFS=',' read -ra EXCL_FILES <<< "${FS_EXC[$name]}"
        build_excludes "$name" "${FS_SRC[$name]}" "${EXCL_FILES[@]}"
        [[ -n "${EXCL_MISSING:-}" ]] && continue          # a real run refused this share already
        find_candidates "${FS_SRC[$name]}"
        local -a mine=()
        for f in "${FOUND_FILES[@]}"; do [[ -z "${AGED_ALL[$f]:-}" ]] && mine+=("$f"); done
        (( ${#mine[@]} > 0 )) || continue
        while IFS= read -r -d '' rec; do
            b=${rec%%$'\t'*}; rest=${rec#*$'\t'}; c=${rest%%$'\t'*}; rest=${rest#*$'\t'}; m=${rest%%$'\t'*}; rest=${rest#*$'\t'}
            sz=${rest%%$'\t'*}; rest=${rest#*$'\t'}; nb=${rest%%$'\t'*}; rest=${rest#*$'\t'}; bs=${rest%%$'\t'*}; f=${rest#*$'\t'}
            is_int "$b" && (( b > 0 )) || b=$c                 # no birth time on this filesystem: ctime
            key=$b; is_int "$m" && (( m > key )) && key=$m      # touched lately: counts as new
            ph=$sz; is_int "$nb" && is_int "$bs" && (( nb * bs < sz )) && ph=$(( nb * bs ))
            printf '%s\t%s\t%s\t%s\t%s\0' "$key" "$sz" "$ph" "$name" "$f" >> "$list"
        done < <(printf '%s\0' "${mine[@]}" | xargs -0 -r stat --printf '%W\t%Z\t%Y\t%s\t%b\t%B\t%n\0' 2>/dev/null)
    done
    FILL_FILES=(); FILL_SIZES=(); FILL_PHYS=(); FILL_OWNER=()
    while IFS= read -r -d '' rec; do
        rec=${rec#*$'\t'}; FILL_SIZES+=("${rec%%$'\t'*}"); rec=${rec#*$'\t'}; FILL_PHYS+=("${rec%%$'\t'*}"); rec=${rec#*$'\t'}
        FILL_OWNER+=("${rec%%$'\t'*}"); FILL_FILES+=("${rec#*$'\t'}")
    done < <(sort -z -t $'\t' -k1,1n -k5 "$list")
    rm -f "$list"
}

# USO «moved enough»: the fill moved what the pool was over (fill_pool's locals): the pool re-read for up to SETTLE_S
# seconds until its reading is at the target — 0: there; 1: still above (fill_more decides)
fill_settle() {
    local waited
    for (( waited = 0; ; waited += 2 )); do
        if [[ "$room" == true ]]; then (( used_b <= ub )) && return 0; else (( pct < min )) && return 0; fi
        (( waited >= SETTLE_S )) && return 1
        sleep 2
        pool_used_pct "$pool"
        [[ -n "$POOL_USED" ]] && pct=$POOL_USED
        [[ -n "$POOL_USED_B" ]] && used_b=$POOL_USED_B
    done
}

# USO «pool bytes»: after a stretch that moved what the pool was over (fill_pool's locals) and fill_settle said «still
# above»: did the pool get freer by at least a quarter of what moved (compression the sizes didn't show, a btrfs commit
# later)? Then a new stretch for what is still over (0). If not, snapshots keep what moved: FILL_ENOUGH, said once (1).
fill_more() {
    if (( (seg_used - used_b) * 4 >= freed && freed > 0 )); then
        seg_used=$used_b; freed=0
        if [[ "$room" == true ]]; then need0=$(( used_b - ub )); else need0=$(( used_b - (min - 1) * size_b / 100 )); fi
        (( need0 > 0 )) || need0=1
        return 0
    fi
    FILL_ENOUGH=true; FILL_HELD=true
    log WARN "  fill: $(human "$freed") moved – as much as pool $pool was over – but it still uses $(human "$used_b") after ${SETTLE_S} s (snapshots keep what moved until their retention, or the pool still frees it): no further file"
    return 1
}

# One pool's fill (a real run moves, a dry run estimates): its shares whose trigger the pool reaches, oldest first across
# them, a share's file only while the pool is at/above its target; the pool re-read after each file (dry: the sizes)
fill_pool() {  # pool
    local pool="$1" name used used_b size_b pct i f sz ph own min=101 n=0 b2=0 cap=false room=false ub="" need0="" freed=0 seg_used=0
    local -a mine=() go=()
    local -A F_N=() F_B=() F_MOVED=() F_LEFT=() F_GONE=()
    for name in "${FILL_SHARES[@]}"; do [[ "${FS_POOL[$name]}" == "$pool" ]] && mine+=("$name"); done
    # USO «moved enough»: the age step moved files off this pool moments ago — one txg before the reading (ZFS shows freed
    # space late), else the fill would count them as still there and move as much again
    if [[ "$DRY_RUN" == false && -n "${POOL_AGE_MOVED[$pool]:-}" ]] && (( SETTLE_S > 0 )); then sleep $(( SETTLE_S < 6 ? SETTLE_S : 6 )); fi
    pool_used_pct "$pool"
    used=$POOL_USED; used_b=$POOL_USED_B; size_b=$POOL_SIZE_B
    if [[ "$DRY_RUN" == true && -n "$used_b" && -n "$size_b" ]]; then
        (( used_b -= ${POOL_AGED_B[$pool]:-0}, used_b < 0 )) && used_b=0
        used=$(( (used_b * 100 + size_b - 1) / size_b ))
    fi
    fill_capped "$pool" && cap=true
    # USO: the night's room in bytes (CUSTOMMOVER_FILL_UNTIL_BYTES) — every share with a trigger goes while the pool uses more
    if [[ -n "$FILL_UNTIL_B" && ( -z "$FILL_UNTIL_POOL" || "$FILL_UNTIL_POOL" == "$pool" ) && -n "$used_b" && -n "$size_b" ]]; then room=true; ub=$FILL_UNTIL_B; fi
    for name in "${mine[@]}"; do
        sh_load "$name"; SH_POOL="$pool"; SH_UNTIL="${FS_TGT[$name]}"; SH_FILL=true; SH_POOL_USED="$used"; SH_POOL_NOW="$used"; sh_save "$name"
        local thr=${FS_THR[$name]}
        [[ "$cap" == true ]] && thr=${FS_TGT[$name]}
        if [[ "$room" == true ]]; then (( used_b > ub )) && go+=("$name")
        elif [[ -n "$used" ]] && (( used >= thr )); then go+=("$name"); (( FS_TGT[$name] < min )) && min=${FS_TGT[$name]}; fi
    done
    if [[ -z "$used" ]]; then log WARN "pool $pool: usage unknown – no fill"; return 0; fi
    if (( ${#go[@]} == 0 )); then log INFO "pool $pool at ${used}% ($POOL_USED_SRC) – below the limit of ${mine[*]}: no fill"; return 0; fi
    if [[ "$cap" == true ]] && (( FILL_LEFT_B <= 0 )); then log INFO "pool $pool: no fill in this run (its cap: $(human "$FILL_MAX_B"))"; return 0; fi
    if [[ "$room" == true ]]; then
        log INFO "[fill] pool $pool $(human "$used_b") used ($POOL_USED_SRC) – the oldest first across ${go[*]} (the younger of created and modified) until at most $(human "$ub") (room for Jack Streamer)"
    else
        log INFO "[fill] pool $pool ${used}% ($POOL_USED_SRC) – the oldest first across ${go[*]} (the younger of created and modified) until below ${min}%"
    fi
    # USO «moved enough» (2026-10-15): a real fill never moves more than the pool was over at its start — ZFS (and btrfs)
    # report freed space only seconds later (a txg; a large file freed in the background), and a snapshot keeps what was
    # moved until its retention: re-reading the pool alone moved every candidate (seen on a test pool: 8 of 8 for 4)
    if [[ "$DRY_RUN" == false && -n "$used_b" && -n "$size_b" ]]; then
        if [[ "$room" == true ]]; then need0=$(( used_b - ub )); else need0=$(( used_b - (min - 1) * size_b / 100 )); fi
        (( need0 <= 0 )) && need0=""
        seg_used=$used_b
    fi
    fill_pool_candidates "${go[@]}"
    if (( ${#FILL_FILES[@]} == 0 )); then log INFO "  fill: no further files (everything else is excluded)"; fi
    PHASE=share; FILL_CAPPED=false; FILL_ENOUGH=false
    pct=$used
    for (( i = 0; i < ${#FILL_FILES[@]}; i++ )); do
        f=${FILL_FILES[i]}; sz=${FILL_SIZES[i]}; ph=${FILL_PHYS[i]}; own=${FILL_OWNER[i]}
        if [[ -n "$need0" ]] && (( freed >= need0 )); then fill_settle && break; fill_more || break; fi
        if [[ "$room" == true ]]; then
            (( used_b <= ub )) && break
        else
            (( pct < min )) && break
            (( pct < FS_TGT[$own] )) && continue              # this share is below its own target already
        fi
        if [[ "$cap" == true ]] && (( sz > FILL_LEFT_B )); then FILL_CAPPED=true; break; fi
        if [[ "$DRY_RUN" == true ]]; then
            (( used_b -= ph, used_b < 0 )) && used_b=0
            pct=$(( (used_b * 100 + size_b - 1) / size_b ))
        else
            if [[ -n "$STOP_FILE" && -e "$STOP_FILE" ]]; then
                STOPPED=true; STOP_SHARE="$own"
                log WARN "stop requested ($STOP_FILE) – stopped after ${LAST_FILE:-the files before}; the rest of the fill is left for the next run"
                break
            fi
            CUR_SHARE="$own"; CUR_FILE="$f"; office_progress
            local r=0
            WS_WHY="in use, or already present at the destination"
            if ends_ws "$f"; then
                # USO «trailing space»: never to the move binary — to a second pool moved here, to the array left
                if [[ "${FS_DEST[$own]}" == array ]]; then
                    WS_WHY="its name ends in a space or tab, which Unraid's move binary drops, and the array disk is the binary's choice – rename it to move it"
                else
                    office_ws_move "$f" "$MNT/${FS_DEST[$own]}/$own/${f#"${FS_SRC[$own]}/"}"
                fi
            else
                printf '%s\n' "$f" | "${FILL_MOVER[@]}" >> "$FILL_OUT" 2>&1
                r=$?
            fi
            if (( r != 0 )); then (( ERRORS++ )) || true; S_RC_FILL[$own]=$r; log ERROR "  move binary exited with code $r for $f"; fi
            LAST_FILE="$f"
            if [[ -e "$f" || -L "$f" ]]; then
                (( F_LEFT[$own]++ )) || true
                log WARN "  still on the source side ($WS_WHY): $f"
            else
                (( F_MOVED[$own]++, RUN_DONE_FILES++, RUN_DONE_BYTES += sz, freed += ph )) || true
                F_GONE[$own]+="$f"$'\n'
                recent_add "$own/${f#"${FS_SRC[$own]}/"}" "$sz" "$pool" "${FS_DEST[$own]}"
            fi
            pool_used_pct "$pool"
            [[ -n "$POOL_USED" ]] && pct=$POOL_USED
            [[ -n "$POOL_USED_B" ]] && used_b=$POOL_USED_B
        fi
        (( F_N[$own]++, F_B[$own] += sz, n++, b2 += sz )) || true
        [[ "$cap" == true ]] && { (( FILL_LEFT_B -= sz )) || true; }
        [[ "$DRY_RUN" == true ]] && { (( F_N[$own] <= MAX_LIST )) && printf '     %s\n' "$f"; printf '[DRY-RUN]     %s\n' "$f" >> "$LOG_FILE"; }
    done
    CUR_FILE=""
    # the last file moved enough: the same wait before the pool's reading is judged
    [[ -n "$need0" && "$FILL_ENOUGH" == false && "$STOPPED" == false ]] && (( freed > 0 && freed >= need0 )) && { fill_settle || fill_more || true; }
    (( TOTAL_FILES += n, TOTAL_BYTES += b2 )) || true
    for name in "${mine[@]}"; do
        sh_load "$name"
        SH_POOL_NOW=$pct
        (( SH_FILES += ${F_N[$name]:-0}, SH_BYTES += ${F_B[$name]:-0} )) || true
        if [[ "$DRY_RUN" == false ]]; then
            (( SH_MOVED += ${F_MOVED[$name]:-0}, SH_LEFT += ${F_LEFT[$name]:-0}, TOTAL_MOVED += ${F_MOVED[$name]:-0}, TOTAL_LEFT += ${F_LEFT[$name]:-0} )) || true
            [[ -n "${S_RC_FILL[$name]:-}" ]] && { SH_RC=${S_RC_FILL[$name]}; SH_STATE=error; }
            if [[ -n "${F_GONE[$name]:-}" ]]; then
                local -a gone=()
                mapfile -t gone <<< "${F_GONE[$name]%$'\n'}"
                remove_empty_parents "${FS_SRC[$name]}" "" "${gone[@]}"
                [[ "$STOPPED" == false ]] && share_root_pass "${FS_SRC[$name]}"
            fi
            [[ "$SH_STATE" == none && ${F_N[$name]:-0} -gt 0 ]] && SH_STATE=moved
        else
            [[ "$SH_STATE" == none && ${F_N[$name]:-0} -gt 0 ]] && SH_STATE=dry
        fi
        if [[ "$room" == true ]]; then (( used_b <= ub )) && SH_REACHED=true
        else (( pct < FS_TGT[$name] )) && SH_REACHED=true; fi
        sh_save "$name"
        [[ "$SH_REACHED" == true ]] && POOL_REACHED[$pool]="$name"
    done
    local tag=""; [[ "$DRY_RUN" == true ]] && tag="[DRY-RUN] "
    if [[ "$room" == true ]] && (( used_b <= ub )); then
        log INFO "  ${tag}fill: $n files ($(human "$b2")) oldest first – room reached: pool $pool uses $(human "$used_b") (at most $(human "$ub")), the rest stays"
    elif [[ "$room" == false ]] && (( pct < min )); then
        log INFO "  ${tag}fill: $n files ($(human "$b2")) oldest first – target reached: pool $pool at about ${pct}% (< ${min}%), the rest stays"
    elif [[ "$FILL_CAPPED" == true || "$FILL_ENOUGH" == true ]]; then
        [[ "$FILL_CAPPED" == true ]] && log INFO "  ${tag}fill: its cap reached (at most $(human "$FILL_MAX_B") in this run) – pool $pool at ${pct}%, the rest stays"
    elif [[ "$STOPPED" == false ]]; then
        log WARN "  ${tag}target not reached: pool $pool at ${pct}% after all $n further files"
    fi
    return 0
}

process_share() {  # name mode pool pool2
    local name="$1" mode="$2" pool="$3" pool2="$4"
    local src direction age_days age_stat threshold excludes skip until
    local min_age_override dirs_pass=false

    skip=$(get_ini_val "$name" skip)
    if [[ "$skip" == "yes" ]]; then SH_STATE=skip; log INFO "[$name] skip=yes in INI"; return 0; fi

    case "$mode" in
        yes)
            src="$MNT/$pool/$name"
            direction="$pool -> ${pool2:-array}" ;;
        prefer)
            if [[ "$MOVE_PREFER" != "yes" ]]; then SH_STATE=prefer_off; log INFO "[$name] prefer share, move_prefer_shares=no – skipped"; return 0; fi
            # USO «per disk»: the array side per disk, as Unraid's mover hands it over (move ignores /mnt/user0 paths)
            # (the disks looked at only once the share is processed — never for the list)
            if [[ -n "$pool2" ]]; then src="$MNT/$pool2/$name"; else src=""; dirs_pass=true; fi
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
    [[ "$age_stat" =~ ^(ctime|mtime|newest)$ ]] || { log ERROR "[$name] age_stat must be ctime|mtime|newest – share skipped"; return 1; }

    if [[ "$LIST_ONLY" == true ]]; then
        printf '  %-24s %-7s %-24s age>%s d (%s)  threshold %s%%%s  excludes: %s\n' "$name" "$mode" "$direction" "$age_days" "$age_stat" "$threshold" \
            "$( (( until > 0 )) && printf ' until %s%%' "$until")" "${excludes:-${GLOBAL_EXC:-none}}"
        SH_STATE=list
        return 0
    fi

    [[ "$dirs_pass" == true ]] && src=$(prefer_disk_roots "$name")
    local shown="${src//$'\n'/, }"
    log INFO "[$name] $direction  (source: ${shown:-no folder of it on its disks})"
    CUR_SHARE="$name"; PHASE=share; SH_DONE_FILES=0; SH_DONE_BYTES=0; office_progress
    local asleep=""
    [[ "$DRY_RUN" == false && -z "$pool2" ]] && asleep=$(asleep_disks "$name")
    # office: a prefer share's scan reads its array disks — its sleeping disks wake now
    [[ -n "$asleep" && "$mode" == "prefer" ]] && log INFO "  array disks asleep: $asleep – this run wakes them"
    if [[ -z "$src" || ( "$dirs_pass" == false && ! -d "$src" ) ]]; then SH_STATE=no_source; log INFO "  nothing on the source side – skipped"; return 0; fi

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
    # USO «oldest first across the pool»: the fill comes after every share's age step (fill_pool)
    # Age filter (always: the fill comes on top)
    local -a AGE_ARGS=()
    if [[ "$FORCE_AGE" == true ]]; then
        log WARN "  FORCE: age filter ignored (configured: $age_days days)"
    elif (( age_days > 0 )); then
        local mins=$(( age_days * 1440 )) flag="-cmin"
        [[ "$age_stat" == "mtime" || "$age_stat" == "newest" ]] && flag="-mmin"
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
    # USO «newest»: find took those modified before the age (-mmin); of them only those also created (birth, else ctime) before it
    if [[ "$age_stat" == "newest" && ${#AGE_ARGS[@]} -gt 0 && ${#FILES[@]} -gt 0 ]]; then
        local cut=$(( $(date +%s) - age_days * 86400 )) rec b c
        local -a old=()
        while IFS= read -r -d '' rec; do
            b=${rec%%$'\t'*}; rec=${rec#*$'\t'}; c=${rec%%$'\t'*}
            is_int "$b" && (( b > 0 )) || b=$c
            (( b < cut )) && old+=("${rec#*$'\t'}")
        done < <(printf '%s\0' "${FILES[@]}" | xargs -0 -r stat --printf '%W\t%Z\t%n\0' 2>/dev/null)
        FILES=("${old[@]}")
    fi

    local count=${#FILES[@]} bytes=0
    (( count > 0 )) && bytes=$(printf '%s\0' "${FILES[@]}" | xargs -0 stat -c %s 2>/dev/null | awk '{s+=$1} END {print s+0}')
    (( TOTAL_FILES += count, TOTAL_BYTES += bytes )) || true
    SH_FILES=$count; SH_BYTES=$bytes
    office_progress      # office: the share's counts on the card at once, before the first file (its disk may still wake)
    if (( count == 0 )) && [[ "$fill" == false ]]; then
        SH_STATE=none; log INFO "  no files match"
        # USO «trailing space», «empty folders»: the folders an earlier run left (the folder pass ran only after files)
        if [[ "$DRY_RUN" == false && "$STOPPED" == false ]]; then
            local -a MOVER_CMD=("$MOVER_BIN")
            (( MOVER_DEBUG > 0 )) && MOVER_CMD+=(-d "$MOVER_DEBUG")
            if [[ "$mode" == "yes" ]]; then share_dirs_pass "$name" "$src" "${pool2:-array}"
            else share_dirs_pass "$name" "$src" "$pool"; fi
        fi
        return 0
    fi
    (( count == 0 )) && log INFO "  no files older than the age"
    for f in "${FILES[@]}"; do AGED_ALL[$f]=1; done
    local fdest
    if [[ "$mode" == "yes" ]]; then fdest="${pool2:-array}"; else fdest="$pool"; fi
    [[ "$fill" == true ]] && fill_register "$name" "$src" "$fdest" "$pool" "$threshold" "$target" "$GLOBAL_EXC,$excludes"

    if [[ "$DRY_RUN" == true ]]; then
        SH_STATE=dry
        (( count > 0 )) && log INFO "  [DRY-RUN] would move $count files ($(human "$bytes"))"
        dry_list
        # USO «pool bytes»: what the aged files take on the pool (allocated, never above their size) for the fill's estimate
        if [[ "$fill" == true ]] && (( count > 0 )); then
            (( POOL_AGED_B[$pool] += $(printf '%s\0' "${FILES[@]}" | xargs -0 stat -c '%s %b %B' 2>/dev/null | awk '{p=$2*$3; s+=(p<$1?p:$1)} END {printf "%d", s+0}') )) || true
        fi
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
    if [[ "$mode" == "yes" ]]; then CUR_FROM="$pool"; else CUR_FROM="${pool2:-array}"; fi
    [[ -n "$STOP_FILE" ]] && office_partial_leftovers "$name" "$src" "$dest"
    if (( count > 0 )); then
        log INFO "  moving $count files ($(human "$bytes")) via $MOVER_BIN"
        move_batch "$name" "$src" "$dest"
        [[ "$mode" == "yes" ]] && (( SH_MOVED > 0 )) && POOL_AGE_MOVED[$pool]=1
    elif [[ "$STOPPED" == false ]]; then
        share_dirs_pass "$name" "$src" "$dest"      # USO «empty folders»: a fill share without an aged file
    fi
    (( count == 0 )) && SH_STATE=none
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
# USO «oldest first across the pool»: the fill, one pool at a time, after every share's age step
if [[ "$LIST_ONLY" == false && "$STOPPED" == false && ${#FILL_POOLS[@]} -gt 0 ]]; then
    FILL_MOVER=("$MOVER_BIN"); (( MOVER_DEBUG > 0 )) && FILL_MOVER+=(-d "$MOVER_DEBUG")
    declare -A S_RC_FILL=()
    FILL_OUT=$(mktemp)
    for FP in "${FILL_POOLS[@]}"; do
        [[ "$STOPPED" == true ]] && break
        fill_pool "$FP"
    done
    while IFS= read -r line; do log DEBUG "  mover: $line"; done < "$FILL_OUT"
    rm -f "$FILL_OUT"
    shares_json_rebuild
fi
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
