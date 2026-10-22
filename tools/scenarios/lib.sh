#!/bin/bash
# tools/scenarios/lib.sh — the one shared library every scenario sources (resilience programme, playbook §1–2).
# Runs on a test VM as root (bash, `set -u`, never `set -e`). A scenario:
#
#   . "$(dirname "$0")/lib.sh"
#   scenario_begin <name>        # opens the report, reads the facts
#   needs tower                  # unmet → report says why, scenario_end, exit 2
#   state SOME-STATE [args]      # state_some_state args; undo_some_state args pushed on the undo stack
#   snap_before                  # the «before» snapshot (invarianten.md «vorher»)
#   flow … / ask …               # what the button does
#   check standard               # the standard invariants (allow INV-X <why> before it)
#   scenario_end                 # verdict table, undo in reverse, exit 0 green | 1 red | 2 skipped | 3 undo failed
#
# INTERFACE (frozen after wave 1; H2 states-*.sh, H5 flows.sh/checks.sh build on it):
#   Facts      P D UB PLACE RUN KEEP REPORT SC_NAME SC_DIR SC_START      host_is tower|test3|test4|any   has <thing>
#              needs <thing>…   (things: tower test3 any docker nodocker vm vmagent hdd zfs btrfs kopia minio jack partner
#              pool2 compose exclusive reboot)
#              bases (disks and pools mounted under /mnt)   base_asleep <base>   disks_asleep   share_asleep <share>
#              lock_held <file> (via /proc/locks, never by taking it)
#   Report     say <text…>   say_cmd <cmd…> (runs it, its output into the report)   evidence <file|dir> [name]
#   Mailbox    ask <desk.action> [json] [timeout]   ask_err <desk.action> [json] [timeout]   ask_save_post <desk.action> [json] [timeout]
#              ask_last [jq filter] (the last answer, state stripped; works after `x=$(ask …)` too)
#   Agent      agent_wait [s]   agent_mark_restart   agent_restart   agent_log_mark [name]   agent_log_since [name]   doorbell_ok
#              state_json <desk>[-<part>] [jq filter] (after <desk>.refresh)
#   Waiters    minute_wait   wait_backup [s]   wait_setup [s]   wait_restore <id> [s]   wait_drill [s]   wait_moverelli [s]
#              wait_night [s]   wait_orphans [s]   wait_letgo [s]   wait_for <s> <what> <cmd…> (the generic one)
#   States     state <ID> [args]   flow <ID> [key=value…]   undo_push <cmd…>   files_strict
#              (H2: state_<id> / undo_<id>; H5: flow_<id>; ID lower case, - → _)
#              track_share <share>… (shares whose files snap_before/after inventory)   KEEP_STASH=$KEEP/stash
#   Snapshots  snap_before   snap_after   inventory <share>…   where_is <path>   ini_diff <a> <b>
#   Checks     check standard|final|<INV-ID>…   allow <INV-ID> <why>   expect_warnings <code>…   expect_err <key>   expect_ok [what]
#              expect_diff <spec>…   expect_changes <spec>…   expect_bubble <desk> <key>…   expect_no_text <desk> <key>…
#              check_<inv_id> (INV-NO-WAKE → check_inv_no_wake): prints the violation in one line;
#              returns 0 green · 1 red · 2 pending (reason printed) · 3 not applicable (reason printed)
#              queue_mac <INV-ID> plan_changes|page_text <args…> (a Mac-side check for the runner)
#   Exit codes 0 green · 1 red · 2 skipped (needs unmet) · 3 undo failed (the VM is dirty: the runner resets it)
#   Runner     USO_SC_OUT (report folder of the run), `.git-describe` in the tree; writes <report>.result (verdict= red=
#              pending= keep= why=) and <report>.pending (queue_mac lines)
#
# Helpers say what they do (into the report and stderr — stdout is only the data they print), return 0/1 and never
# exit — except scenario_begin (wrong host / no plugin / not root) and needs (unmet → scenario_end → exit 2). Everything a
# helper records goes into files under $KEEP, so a helper called as `x=$(ask …)` still counts. Secrets (scenarios.env)
# are never printed; INV-NO-SECRET greps for them.
# `flock -o /tmp/uso-scenarios.lock bash lib.sh --self-test` makes a uso-wip-h1-st share, files, a container, undoes all.
# Always `flock -o` (the lock's fd closed for the command): an agent started from a scenario would keep the lock for life.

# ---------------------------------------------------------------------------------------------------------------- facts
P=/usr/local/emhttp/plugins/unraid-secretary-office
RUN=/var/run/unraid-secretary-office
SC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)       # tools/scenarios of the synced tree
SC_TREE=$(cd "$SC_DIR/../.." && pwd)
OFFICE_CFG=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
OFFICE_FLASH=/boot/config/plugins/unraid-secretary-office
SCENARIOS_ENV=$OFFICE_FLASH/scenarios.env
DISKS_INI=/var/local/emhttp/disks.ini
SHARES_INI=/var/local/emhttp/shares.ini
NOTIFY_DIR=/tmp/notifications
TOWER_IP=192.168.7.111
TEST3_IP=192.168.7.197
TEST4_IP=192.168.7.193     # USOTest4 (2026-10-19): a second apps server like Tower, on Unraid 7.3.3
SC_REPORTS=/tmp/uso-scenarios
SC_KEEPS=/tmp/uso-keep
SC_POST=$SC_REPORTS/post             # canned dialog answers for the page tool (page.sh copies it as the harness's post/)
D= UB= PLACE= KEEP= REPORT= SC_NAME= SC_START= KEEP_STASH= SC_ENDED=

# The standard and final sets (invarianten.md); H5's checks.sh may add to them. A check without its function is
# «pending (H5)», never green. INV-FILES becomes INV-FILES-STRICT after `files_strict` (a flow that changes nothing).
CHECK_STANDARD=(INV-SETTINGS INV-REPLAN INV-FILES INV-LEFTOVER INV-NO-STALE-LOCK INV-COUNTS INV-PAGE INV-BUBBLE INV-AGENT
    INV-UNRAID-SAME INV-NO-WAKE INV-NO-SECRET INV-NOTIFY-ONCE INV-TICK INV-CRON-ONE)
CHECK_FINAL=(INV-BACKUP-AFTER INV-DRILL-GREEN)

_sc_id() { local s=${1,,}; s=${s//-/_}; printf '%s' "$s"; }      # INV-NO-WAKE → inv_no_wake
_sc_now() { date +%s; }

# a value of a plain ini (KEY="value" or key = value)
_ini_value() {
    [[ -f $1 ]] || return 1
    sed -n -E "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*\"?([^\"]*)\"?[[:space:]]*$/\1/p" "$1" | head -1
}

_facts() {
    D=$(_ini_value "$OFFICE_CFG" DATA_DIR)
    D=${D:-/mnt/user/appdata/UnraidSecretaryOffice/data}
    D=${D%/}
    UB=$D/unraid-backup
    local share
    share=$(awk '/^\[general\]/{g=1;next} /^\[/{g=0} g && /^[[:space:]]*dumps_share[[:space:]]*=/{sub(/^[^=]*=[[:space:]]*/,"");print;exit}' "$UB/settings.ini" 2>/dev/null)
    PLACE=${share:+/mnt/user/$share/backup}
}

host_is() {
    local ips
    ips=$(ip -4 -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1)
    case $1 in
        any) return 0 ;;
        tower) grep -qx "$TOWER_IP" <<<"$ips" ;;
        test3) grep -qx "$TEST3_IP" <<<"$ips" ;;
        test4) grep -qx "$TEST4_IP" <<<"$ips" ;;
        *) return 1 ;;
    esac
}

# a rotating array disk that isn't QEMU's (only such a disk can really be spun down)
_real_hdd() {
    local dev
    for dev in $(awk -F'"' 'function out(){if (r=="1" && t=="Data" && d!="") print d} /^\[/{out(); d=t=r=""} /^device=/{d=$2}
        /^type=/{t=$2} /^rotational=/{r=$2} END{out()}' "$DISKS_INI" 2>/dev/null); do
        grep -qi qemu "/sys/block/$dev/device/model" 2>/dev/null || return 0
    done
    return 1
}

_container_up() { docker ps --format '{{.Names}}' 2>/dev/null | grep -qix "$1"; }

has() {
    case $1 in
        docker) [[ -S /var/run/docker.sock ]] && timeout 10 docker info >/dev/null 2>&1 ;;
        vm) [[ -S /var/run/libvirt/libvirt-sock ]] && timeout 10 virsh list --all >/dev/null 2>&1 ;;
        vmagent) has vm && virsh list --all --name 2>/dev/null | grep -qx uso-vm-agent ;;   # H4 builds it
        hdd) _real_hdd ;;      # a disk that really sleeps: QEMU's virtual disks ignore STANDBY (H2, 2026-10-18)
        nodocker) ! has docker ;;
        pool2) awk -v m="/mnt/${POOL2:-fast}" '$2==m{f=1} END{exit !f}' /proc/mounts ;;     # a second pool (H2's states)
        compose) has docker && docker compose version >/dev/null 2>&1 && [[ -d /boot/config/plugins/compose.manager/projects ]] ;;
        exclusive) tr -d '\r' </boot/config/share.cfg 2>/dev/null | grep -qx 'shareUserExclusive="yes"' ;;
        zfs) grep -q '^fsType="zfs"' "$DISKS_INI" 2>/dev/null ;;
        btrfs) grep -q '^fsType="btrfs"' "$DISKS_INI" 2>/dev/null ;;
        kopia) has docker && _container_up kopia ;;
        minio) has docker && _container_up uso-minio ;;
        jack) jq -e '.hired | has("jack")' "$D/office/staff.json" >/dev/null 2>&1 ;;
        partner) jq -e '(.pairs // []) | length > 0' "$D/partner/pairs.json" >/dev/null 2>&1 ;;
        *) return 1 ;;
    esac
}

needs() {
    local n why=()
    for n in "$@"; do
        case $n in
            # «tower» = the apps server: Tower or its twin USOTest4 (7.3.3) — host_is tower stays Tower alone
            tower) host_is tower || host_is test4 || why+=("not an apps server (Tower/USOTest4)") ;;
            test3 | test4 | any) host_is "$n" || why+=("not $n") ;;
            reboot) _row needs reboot info "the runner runs this scenario in halves" ;;
            # a power cut (sysrq b): only when the runner says so (run.sh / run-local.sh --powercut → USO_POWERCUT=1)
            powercut) [[ ${USO_POWERCUT:-} == 1 ]] || why+=("a power cut — only with a vdisk boot or the owner nearby (--powercut)") ;;
            docker | nodocker | vm | vmagent | hdd | zfs | btrfs | kopia | minio | jack | partner | pool2 | compose | exclusive)
                has "$n" || why+=("no $n") ;;
            *) why+=("unknown need $n") ;;
        esac
    done
    ((${#why[@]})) || return 0
    [[ -n $SC_NAME ]] || { say "needs: ${why[*]}"; return 1; }      # outside a scenario: just say so
    printf '%s\n' "${why[*]}" >"$KEEP/.skip"
    say "skipped: needs $* — ${why[*]}"
    scenario_end
}

# The disks and pools mounted under /mnt (disks.ini Data/Cache sections whose /mnt/<name> is a mount — never a look
# at the disk itself)
bases() {
    awk -F'"' '/^\[/{n=""} /^name=/{n=$2} /^type=/{if ($2=="Data"||$2=="Cache") print n}' "$DISKS_INI" 2>/dev/null |
        while read -r b; do
            [[ -n $b ]] && awk -v m="/mnt/$b" '$2==m{f=1} END{exit !f}' /proc/mounts && echo "$b"
        done
}

# The disks Unraid spun down that rotate (agent/lib/mounts.php diskAsleep(): `rotational` missing = rotating)
disks_asleep() {
    awk -F'"' '/^\[/{if (n!="" && s=="1" && r!="0") print n; n=""; s=""; r=""} /^name=/{n=$2} /^spundown=/{s=$2}
        /^rotational=/{r=$2} END{if (n!="" && s=="1" && r!="0") print n}' "$DISKS_INI" 2>/dev/null
}

# A disk or pool asleep? disk1 is itself (never disk10); a pool sleeps when any of its disks does (cache, cache2 …)
base_asleep() {
    local d
    for d in $(disks_asleep); do
        if [[ $1 =~ ^disk[0-9]+$ ]]; then
            [[ $d == "$1" ]] && return 0
        elif [[ $d =~ ^$1[0-9]*$ ]]; then
            return 0
        fi
    done
    return 1
}

# share_asleep <share>: does a disk or pool the share may use sleep? (shares.ini: useCache, cachePool, cachePool2,
# include/exclude — no look at a disk). A share shares.ini doesn't know: any base asleep counts.
share_asleep() {
    local sec use inc exc b parts=()
    sec=$(awk -v s="[\"$1\"]" '$0==s{f=1; next} /^\[/{f=0} f' "$SHARES_INI" 2>/dev/null)
    if [[ -z $sec ]]; then
        [[ -n $(disks_asleep) ]]
        return
    fi
    _sv() { sed -n -E "s/^$1=\"(.*)\"$/\1/p" <<<"$sec"; }
    use=$(_sv useCache)
    inc=$(_sv include)
    exc=$(_sv exclude)
    if [[ $use != only ]]; then
        for b in $(bases); do
            [[ $b =~ ^disk[0-9]+$ ]] || continue
            [[ -n $inc && ,$inc, != *,$b,* ]] && continue
            [[ ,$exc, == *,$b,* ]] && continue
            parts+=("$b")
        done
    fi
    [[ $use != no ]] && parts+=($(_sv cachePool) $(_sv cachePool2))
    for b in "${parts[@]}"; do
        base_asleep "$b" && return 0
    done
    return 1
}

# Does anybody hold an flock on the file? Looked up in /proc/locks, never by taking it (a run starting that moment
# would skip). /proc/locks names the file by MAJOR:MINOR:INODE of its filesystem: stat's device, and the mount's
# (btrfs: stat shows the subvolume's own device, /proc/locks the filesystem's — mountinfo has that one). Through
# /mnt/user the lock lies on the disk's file: that one's ids are looked for too (system.LOCATION).
_lock_ids() {
    local s dev ino mnt mm
    s=$(stat -L -c '%d %i %m' -- "$1" 2>/dev/null) || return
    read -r dev ino mnt <<<"$s"
    printf '%02x:%02x:%d\n' $((((dev >> 8) & 0xfff) | ((dev >> 32) & ~0xfff))) $(((dev & 0xff) | ((dev >> 12) & ~0xff))) "$ino"
    mm=$(awk -v m="$mnt" '$5==m{x=$3} END{print x}' /proc/self/mountinfo)
    [[ $mm =~ ^([0-9]+):([0-9]+)$ ]] && printf '%02x:%02x:%d\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" "$ino"
}
lock_held() {
    local f=$1 ids loc real
    [[ -e $f ]] || return 1
    ids=$(_lock_ids "$f")
    if [[ $f == /mnt/user/* ]]; then
        loc=$(getfattr -n system.LOCATION --only-values --absolute-names -- "$f" 2>/dev/null)
        real=/mnt/${loc%%[ ,]*}/${f#/mnt/user/}
        [[ -n $loc && -e $real ]] && ids+=$'\n'$(_lock_ids "$real")
    fi
    awk -v ids="${ids//$'\n'/ }" 'BEGIN{n=split(ids,a," "); for(i=1;i<=n;i++) w[a[i]]=1} $2=="FLOCK" && ($6 in w){f=1} END{exit !f}' /proc/locks
}

# --------------------------------------------------------------------------------------------------------------- report
say() {
    printf '[%s] %s\n' "${SC_NAME:-lib}" "$*" >&2
    [[ -n $REPORT && -f $REPORT ]] && printf -- '- `%s` %s\n' "$(date +%H:%M:%S)" "$*" >>"$REPORT"
    return 0
}

say_cmd() {
    local out rc
    out=$("$@" 2>&1)
    rc=$?
    say "\`$*\` → $rc"
    [[ -n $REPORT ]] && printf '```\n%s\n```\n' "$(head -c 4000 <<<"$out")" >>"$REPORT"
    return $rc
}

# a row of the verdict table: <kind> <name> <green|red|allowed|pending|n/a|info|skipped> <note>
_row() {
    [[ -n $SC_NAME ]] || return 0
    printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "${4//$'\n'/ }" >>"$KEEP/.rows"
}

evidence() {
    local src=$1 name=${2:-$(basename "$1")}
    [[ -e $src ]] || { say "evidence: $src is not there"; return 1; }
    mkdir -p "$KEEP/evidence"
    cp -a -- "$src" "$KEEP/evidence/$name" && printf '%s\n' "$KEEP/evidence/$name" >>"$KEEP/.evidence"
}

_versions() {
    local office engine unraid agent tree
    office=$(sed -n -E "s/^const OFFICE_VERSION = '([^']+)'.*/\1/p" "$P/src/bootstrap.php" 2>/dev/null)
    engine=$(sed -n -E 's/^UB_VERSION="([^"]+)".*/\1/p' "$P/backup/lib/common.sh" 2>/dev/null)
    unraid=$(_ini_value /etc/unraid-version version)
    agent=$(jq -r '.version // "?"' "$RUN/agent.json" 2>/dev/null)
    if [[ -f $SC_TREE/.git-describe ]]; then
        tree=$(head -1 "$SC_TREE/.git-describe")                     # written by run.sh before the sync (no .git there)
    else
        tree=$(git -C "$SC_TREE" describe --always --dirty 2>/dev/null || echo unknown)
    fi
    printf 'office %s (agent %s) · engine %s · Unraid %s · tree %s' "${office:-?}" "${agent:-?}" "${engine:-?}" "${unraid:-?}" "$tree"
}

scenario_begin() {
    SC_NAME=$1
    if [[ ! $SC_NAME =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
        echo "scenario_begin: a name in lower case, digits and - (got '$SC_NAME')" >&2
        exit 2
    fi
    SC_START=$(_sc_now)
    KEEP=$SC_KEEPS/$SC_NAME
    KEEP_STASH=$KEEP/stash
    if [[ -n $(find "$KEEP_STASH" -mindepth 1 -not -type d 2>/dev/null | head -1) ]]; then
        echo "scenario_begin: $KEEP_STASH is not empty — an earlier run was not undone (the VM is dirty)" >&2
        exit 3
    fi
    rm -rf -- "$KEEP"
    # the runner's folder for this run (run.sh sets USO_SC_OUT), else one per day
    REPORT=${USO_SC_OUT:-$SC_REPORTS/$(date +%F)}/$SC_NAME.md
    mkdir -p "$KEEP_STASH" "$KEEP/ask" "$(dirname "$REPORT")"
    touch "$KEEP/.start" "$KEEP/.rows" "$KEEP/.undo"
    rm -f "${REPORT%.md}.result" "${REPORT%.md}.pending"
    _facts
    local role=other
    host_is tower && role=tower
    host_is test3 && role=test3
    host_is test4 && role=test4
    {
        printf '# Scenario %s\n\n' "$SC_NAME"
        printf -- '- host: %s (%s) · %s\n' "$(hostname)" "$role" "$(_versions)"
        printf -- '- started: %s · data folder %s · backup place %s\n' "$(date '+%F %T %Z')" "$D" "${PLACE:-none}"
        printf -- '- script: %s\n\n## Log\n\n' "${BASH_SOURCE[1]:-?}"
    } >"$REPORT"
    trap '_sc_on_exit' EXIT
    trap '_sc_on_signal' INT TERM HUP
    if ((EUID != 0)); then
        say "scenario_begin: runs as root only"
        printf 'not root\n' >"$KEEP/.skip"
        scenario_end
    fi
    if [[ $role == other ]]; then
        say "scenario_begin: $(hostname) is not a scenario VM (Tower $TOWER_IP, USOTest3VM $TEST3_IP or USOTest4 $TEST4_IP) — nothing done"
        printf 'not a scenario VM\n' >"$KEEP/.skip"
        scenario_end
    fi
    if [[ ! -f $P/src/bootstrap.php ]]; then
        say "scenario_begin: no plugin under $P"
        printf 'no plugin\n' >"$KEEP/.skip"
        scenario_end
    fi
    agent_log_mark begin
    say "begin on $role"
    if ! WAIT_SOFT=1 wait_for 120 "a settled agent" _agent_settled; then
        _row scenario agent red "the agent isn't at work at the begin (heartbeat $(_agent_age) s old)"
    fi
}

_sc_on_signal() {
    say "interrupted by a signal"
    _row scenario signal red "interrupted"
    scenario_end
}

_sc_on_exit() {
    [[ -n $SC_NAME && -z $SC_ENDED ]] || return
    _row scenario exit red "the script ended without scenario_end"
    scenario_end
}

_pulse_stop() {
    local pid
    pid=$(cat "$KEEP/.pulse.pid" 2>/dev/null) || return 0
    kill "$pid" 2>/dev/null
    wait "$pid" 2>/dev/null
    rm -f "$KEEP/.pulse.pid"
}

scenario_end() {
    [[ -z $SC_ENDED ]] || exit "${SC_EXIT:-1}"
    SC_ENDED=1
    trap - EXIT INT TERM HUP
    _pulse_stop
    local undo_fail=0 line n=0 cmd rc
    # undo in reverse order: every pushed undo, whatever happened before
    if [[ -s $KEEP/.undo ]]; then
        while IFS= read -r line; do
            [[ -n $line ]] || continue
            n=$((n + 1))
            cmd=$line
            say "undo: $cmd"
            eval "$cmd" >>"$KEEP/undo.log" 2>&1
            rc=$?
            if ((rc == 0)); then
                _row undo "${cmd%% *}" green ""
            else
                _row undo "${cmd%% *}" red "exit $rc (undo.log)"
                undo_fail=1
            fi
        done < <(tac "$KEEP/.undo")
    fi
    # last: the share roots Unraid's share commands reset on the side (states-shares.sh _st_roots_mark) — after every
    # undo, since a share's own «Delete» in the undo resets them once more
    if [[ -s $KEEP/.roots-before ]] && declare -F _st_roots_restore >/dev/null; then
        _st_roots_restore "$KEEP/.roots-before" >>"$KEEP/undo.log" 2>&1
        _row undo share-roots green "as before the scenario (undo.log names any put back)"
    fi
    if [[ ! -s $KEEP/.skip && $n -gt 0 ]]; then
        local out
        out=$(check_inv_leftover)
        case $? in
            0) _row undo INV-LEFTOVER green "after undo" ;;
            1) _row undo INV-LEFTOVER red "after undo: $out"; undo_fail=1 ;;
            *) _row undo INV-LEFTOVER n/a "$out" ;;
        esac
        if [[ -n $(find "$KEEP_STASH" -mindepth 1 -not -type d 2>/dev/null | head -1) ]]; then
            _row undo stash red "$KEEP_STASH is not empty"
            undo_fail=1
        fi
    fi
    local verdict red_ids
    red_ids=$(awk -F'\t' '$3=="red"{print $2}' "$KEEP/.rows" | sort -u | paste -sd, -)
    if [[ -s $KEEP/.skip ]]; then
        verdict=skipped SC_EXIT=2
    elif ((undo_fail)); then
        verdict=dirty SC_EXIT=3
    elif [[ -n $red_ids ]]; then
        verdict=red SC_EXIT=1
    else
        verdict=green SC_EXIT=0
    fi
    if [[ -n $REPORT && -f $REPORT ]]; then
        {
            printf '\n## Verdict: %s\n\n| what | name | verdict | note |\n|---|---|---|---|\n' "$verdict"
            [[ -s $KEEP/.skip ]] && printf '| needs | — | skipped | %s |\n' "$(head -1 "$KEEP/.skip")"
            awk -F'\t' '{gsub(/\|/,"\\|",$4); printf "| %s | %s | %s | %s |\n", $1, $2, $3, $4}' "$KEEP/.rows"
            printf '\n## Evidence\n\n'
            printf -- '- %s\n' "$KEEP/before" "$KEEP/after" "$KEEP/ask"
            [[ -s $KEEP/.evidence ]] && sed 's/^/- /' "$KEEP/.evidence"
            [[ -s $KEEP/undo.log ]] && printf -- '- %s\n' "$KEEP/undo.log"
            printf '\nVERDICT %s exit=%s red=%s\n' "$verdict" "$SC_EXIT" "${red_ids:-none}"
        } >>"$REPORT"
        # for the runner (key=value, no secrets)
        local why pending
        pending=$(awk -F'\t' '$3=="pending"{print $2}' "$KEEP/.rows" | sort -u | paste -sd, -)
        why=$(head -1 "$KEEP/.skip" 2>/dev/null)
        [[ -n $why ]] || why=$(awk -F'\t' '$3=="red"{print $1 " " $2 ": " $4; exit}' "$KEEP/.rows")
        printf 'verdict=%s\nred=%s\npending=%s\nkeep=%s\nwhy=%s\n' "$verdict" "$red_ids" "$pending" "$KEEP" "${why:0:300}" \
            >"${REPORT%.md}.result"
    fi
    printf '[%s] verdict %s (exit %s)%s — %s\n' "$SC_NAME" "$verdict" "$SC_EXIT" "${red_ids:+, red: $red_ids}" "$REPORT" >&2
    exit "$SC_EXIT"
}

# -------------------------------------------------------------------------------------------------------------- mailbox
# ask <desk.action> [json] [timeout]: the button's request (tools/scenarios/ask.php → askAgent()). Prints the answer
# (without state); 1 on ok:false — the error key goes into the report. Each answer kept in $KEEP/ask/.
ask() {
    local action=$1 json=${2:-'{}'} wait=${3:-600} out rc t0 t1 n key      # 600 s: the page's wait (api.php)
    t0=$(date +%s%N)
    out=$(php "$SC_DIR/ask.php" --wait "$wait" ${ASK_SAVE_POST:+--save-post "$ASK_SAVE_POST"} "$action" "$json" 2>"$KEEP/ask/.stderr")
    rc=$?
    t1=$(date +%s%N)
    [[ -n $out ]] || out=$(jq -cn --arg e "$(head -c 300 "$KEEP/ask/.stderr")" '{ok:false, error:{key:"ask_failed", detail:$e}}')
    n=$(($(cat "$KEEP/ask/.n" 2>/dev/null || echo 0) + 1))
    echo "$n" >"$KEEP/ask/.n"
    printf '%s\n' "$out" | tee "$KEEP/ask/last.json" >"$KEEP/ask/$(printf %03d "$n")-$action.json"
    printf '%s %s\n' "$(_sc_now)" "$rc" >"$KEEP/.ask-at"
    jq -e '.. | objects | select(.wake == true)' <<<"$json" >/dev/null 2>&1 && touch "$KEEP/.wake-sent"
    key=$(jq -r '.error.key // empty' <<<"$out" 2>/dev/null)
    local ms=$(((t1 - t0) / 1000000)) body=${json:0:240}
    [[ ${#json} -gt 240 ]] && body+=…
    if ((rc == 0)); then
        say "ask $action $body → ok (${ms} ms)"
    else
        say "ask $action $body → refused: ${key:-?} (${ms} ms)"
        rc=1
    fi
    printf '%s\n' "$out"
    return $rc
}

# the last answer (file, so it works after `x=$(ask …)`), through jq when a filter is given
ask_last() {
    if [[ $# -gt 0 ]]; then jq -r "$1" "$KEEP/ask/last.json"; else cat "$KEEP/ask/last.json"; fi
}

# a request that must be refused: prints its error key; 1 when it was accepted
ask_err() {
    if ask "$@" >/dev/null; then
        say "ask_err $1: expected a refusal, it was accepted"
        return 1
    fi
    ask_last '.error.key // empty'
}

# ask_save_post <desk.action> [json] [timeout]: ask, and keep the answer as the canned dialog answer for the page tool
# ($SC_POST/<desk>.<action>.json — the harness's tests/ui/states/post/ shape)
ask_save_post() {
    mkdir -p "$SC_POST"
    ASK_SAVE_POST=$SC_POST/$1.json ask "$@"
}

# queue_mac <INV-ID> <plan_changes|page_text> <args…>: a check only the Mac can do (node, the page): one TAB line in
# ${REPORT%.md}.pending for the runner (H3's contract), «pending (mac)» in the verdict table until it ran
queue_mac() {
    local id=$1
    shift
    (IFS=$'\t'; printf '%s\t%s\n' "$id" "$*") >>"${REPORT%.md}.pending"
    _row check "$id" pending "pending (mac): $1"
}

# ---------------------------------------------------------------------------------------------------------------- agent
_agent_field() { jq -r "$1 // empty" "$RUN/agent.json" 2>/dev/null; }
_agent_age() { local m; m=$(stat -c %Y "$RUN/agent.json" 2>/dev/null) || { echo 9999; return; }; echo $(($(_sc_now) - m)); }

# heartbeat fresh (< 70 s, running) — and, after agent_mark_restart, a new agent (another start time)
agent_wait() {
    local s=${1:-90} from=
    [[ -f $KEEP/.restart-from ]] && from=$(cat "$KEEP/.restart-from")
    wait_for "$s" "the agent at work${from:+ (a new one)}" _agent_ready "$from"
}
_agent_ready() {
    [[ $(_agent_field .running) == true ]] && (($(_agent_age) < 70)) || return 1
    [[ -z $1 ]] || [[ "$(_agent_field .pid):$(_agent_field .started)" != "$1" ]]
}

_agent_settled() { _agent_ready "" && (($(_sc_now) - $(_agent_field .started) >= 15)); }

agent_mark_restart() {
    printf '%s:%s\n' "$(_agent_field .pid)" "$(_agent_field .started)" >"$KEEP/.restart-from"
    _row agent restart info "a restart is expected (INV-AGENT allows a new pid)"
}

# a restart the way a deploy makes one: the agent sees a changed file of its own and restarts itself
agent_restart() {
    agent_mark_restart
    say "agent_restart: touching agent.php"
    touch "$P/agent/agent.php"
    agent_wait 120
}

agent_log_mark() {
    local name=${1:-mark}
    stat -c %s "$D/agent.log" >"$KEEP/.log-$name" 2>/dev/null || echo 0 >"$KEEP/.log-$name"
}

# the agent.log lines since the mark (all of it when the log was rotated meanwhile)
agent_log_since() {
    local name=${1:-mark} from size
    from=$(cat "$KEEP/.log-$name" 2>/dev/null || echo 0)
    size=$(stat -c %s "$D/agent.log" 2>/dev/null || echo 0)
    ((size < from)) && from=0
    tail -c +$((from + 1)) "$D/agent.log" 2>/dev/null
}

# the doorbell: a FIFO of root only (as agentRing() wants it), held open by the agent
doorbell_ok() {
    local bell=$RUN/doorbell pid st
    [[ -p $bell && ! -L $bell ]] || { say "doorbell: $bell is not a FIFO"; return 1; }
    st=$(stat -c '%u %a' "$bell")
    [[ $st == "0 600" || $st =~ ^0\ [67]00$ ]] || { say "doorbell: owner/mode $st"; return 1; }
    pid=$(_agent_field .pid)
    local real fd
    real=$(readlink -f "$bell")
    for fd in /proc/"$pid"/fd/*; do
        [[ $(readlink "$fd" 2>/dev/null) == "$real" ]] && return 0
    done
    say "doorbell: the agent (${pid:-?}) doesn't hold it open"
    return 1
}

# state_json <desk>[-<part>] [jq filter]: the desk's state file after its fresh look (<desk>.refresh)
state_json() {
    local name=$1 desk=${1%%-*}
    ask "$desk.refresh" '{}' 120 >/dev/null || return 1
    [[ -f $D/$name.json ]] || { say "state_json: $D/$name.json is not there"; return 1; }
    jq -r "${2:-.}" "$D/$name.json"
}

# -------------------------------------------------------------------------------------------------------------- waiters
# wait_for <seconds> <what> <cmd…>: every 2 s until cmd succeeds; «timed out waiting for <what>» otherwise — a red row
# of the verdict table, unless WAIT_SOFT is set (a waiter's first phase: did it start at all?)
wait_for() {
    local s=$1 what=$2 t0
    shift 2
    t0=$(_sc_now)
    while :; do
        if "$@"; then
            say "waited $(($(_sc_now) - t0)) s for $what"
            return 0
        fi
        if (($(_sc_now) - t0 >= s)); then
            say "timed out waiting for $what ($s s)"
            [[ -n ${WAIT_SOFT:-} ]] || _row wait "$what" red "timed out after $s s"
            return 1
        fi
        sleep 2
    done
}

# the time of the last ask, if it was accepted (a refused start never starts: nothing to wait for)
_asked_at() { local t rc; read -r t rc <"$KEEP/.ask-at" 2>/dev/null || return 1; ((rc == 0)) && echo "$t"; }

# phase one of a waiter: the job's file says it started at or after the last ask (≤ 90 s), or it is running already
_started_since() {   # <file> <jq for the start time> [cmd that says it runs]
    local since t
    since=$(_asked_at) || return 0
    t=$(jq -r "$2 // 0" "$1" 2>/dev/null)
    [[ $t =~ ^[0-9]+$ ]] && ((t >= since - 1))
}

# one real backup a minute (uso-backup-YYYYMMDD-HHMM): wait until the last run's minute is over, never at :58/:59
minute_wait() {
    local last
    last=$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null)
    wait_for 120 "a free minute for a run" _minute_free "$last"
}
_minute_free() { [[ $(date +%Y%m%d-%H%M) != "$1" ]] && (($(date +%-S) < 55)); }

_backup_done() {
    lock_held "$UB/state/lock" && return 1
    [[ $(jq -r '.result // empty' "$UB/state/status.json" 2>/dev/null) != running ]]
}
wait_backup() {
    local s=${1:-3600}
    if _asked_at >/dev/null; then
        WAIT_SOFT=1 wait_for 90 "the backup run to start" _bk_started || say "wait_backup: no run started after the last ask"
    fi
    wait_for "$s" "the backup run to end" _backup_done || return 1
    say "backup run: $(jq -c '{run, result, errors, warnings, message}' "$UB/state/status.json" 2>/dev/null)"
}
_bk_started() { lock_held "$UB/state/lock" || _started_since "$UB/state/status.json" .started; }

_setup_done() {
    local f=$UB/state/setup-status.json pid
    [[ $(jq -r '.result // empty' "$f" 2>/dev/null) == running ]] || return 0
    pid=$(jq -r '.pid // 0' "$f")
    ! kill -0 "$pid" 2>/dev/null
}
wait_setup() {
    local s=${1:-900}
    if _asked_at >/dev/null; then
        WAIT_SOFT=1 wait_for 90 "the setup look to start" _started_since "$UB/state/setup-status.json" .started ||
            say "wait_setup: no setup started after the last ask"
    fi
    wait_for "$s" "the setup look to end" _setup_done || return 1
    say "setup: $(jq -c '{mode, result, errors, warnings, written}' "$UB/state/setup-status.json" 2>/dev/null)"
}

_restore_done() { local r; r=$(jq -r '.result // empty' "$D/restore/$1/journal.json" 2>/dev/null); [[ -n $r && $r != queued && $r != running ]]; }
wait_restore() {
    local id=$1 s=${2:-3600}
    [[ $id =~ ^[0-9A-Za-z-]+$ ]] || { say "wait_restore: no job id"; return 1; }
    wait_for "$s" "restore job $id" _restore_done "$id" || return 1
    say "restore $id: $(jq -c '{kind, result}' "$D/restore/$id/journal.json")"
    jq -r .result "$D/restore/$id/journal.json"
}

_drill_done() { php "$SC_DIR/ask.php" --wait 30 restore.drill_state '{}' 2>/dev/null | jq -e '.ok and .running == null' >/dev/null; }
wait_drill() {
    wait_for "${1:-3600}" "the drill" _drill_done || return 1
    ask restore.drill_state '{}' >/dev/null
    say "drill: $(ask_last '.certificate | {result, finished} | tostring')"
}

_mv_done() {
    local f=$D/moverelli/office-run.json pid
    [[ -f $f ]] || return 0
    [[ $(jq -r '.finished // 0' "$f") != 0 ]] && return 0
    pid=$(jq -r '.pid // 0' "$f")
    ! kill -0 "$pid" 2>/dev/null
}
wait_moverelli() {
    local s=${1:-3600}
    if _asked_at >/dev/null; then
        WAIT_SOFT=1 wait_for 90 "Ms. Moverelli's run to start" _started_since "$D/moverelli/office-run.json" .started ||
            say "wait_moverelli: no run started after the last ask"
    fi
    wait_for "$s" "Ms. Moverelli's run to end" _mv_done || return 1
    say "moverelli run: $(jq -c '{mode, by, exit}' "$D/moverelli/office-run.json" 2>/dev/null)"
}

_night_done() { [[ $(jq -r '.phase // empty' "$D/moverelli/night.json" 2>/dev/null) == done ]]; }
wait_night() {
    local s=${1:-7200}
    if _asked_at >/dev/null; then
        WAIT_SOFT=1 wait_for 90 "the joint run to start" _started_since "$D/moverelli/night.json" .started ||
            say "wait_night: no joint run started after the last ask"
    fi
    wait_for "$s" "the joint run to end" _night_done || return 1
    say "joint run: $(jq -c '{mode, result, phase}' "$D/moverelli/night.json")"
}

_orphans_done() { php "$SC_DIR/ask.php" --wait 30 backup.orphans_get '{}' 2>/dev/null | jq -e '.ok and .orphans != null and .orphans.running == false' >/dev/null; }
wait_orphans() {
    wait_for "${1:-1800}" "the look at old offsite sources" _orphans_done || return 1
    ask backup.orphans_get '{}' >/dev/null
}

_letgo_done() { local r; r=$(jq -r '.result // empty' "$D/backup-letgo-job.json" 2>/dev/null); [[ $r != queued && $r != running ]]; }
wait_letgo() {
    wait_for "${1:-1800}" "Mr. Backupsy's clearing away" _letgo_done || return 1
    say "let-go: $(jq -c '{id, result, reason}' "$D/backup-letgo-job.json" 2>/dev/null)"
}

# --------------------------------------------------------------------------------------------------------- states, undo
_broken() { [[ -f $KEEP/.broken ]]; }

# state <ID> [args]: state_<id> args; undo_<id> args on the stack — pushed even when the state fails (undos are
# idempotent: they clean up whatever part was done). A state returning 2 «needs what isn't here»: skipped (exit 2).
state() {
    local id=$1 fn un rc
    shift
    fn=state_$(_sc_id "$id")
    un=undo_$(_sc_id "$id")
    if ! declare -F "$fn" >/dev/null; then
        _row state "$id" red "no helper $fn"
        touch "$KEEP/.broken"
        return 1
    fi
    if declare -F "$un" >/dev/null; then
        undo_push "$un" "$@"
    else
        _row state "$id" red "no $un"
    fi
    say "state $id $*"
    "$fn" "$@"
    rc=$?
    if ((rc == 0)); then
        _row state "$id${*:+ $*}" green ""
    elif ((rc == 2)); then
        # the state says what it needs isn't here (H2's states): skipped like an unmet `needs`, after the undo
        printf 'state %s: needs what this server lacks\n' "$id" >"$KEEP/.skip"
        say "skipped: state $id needs what this server lacks"
        scenario_end
    else
        _row state "$id${*:+ $*}" red "$fn exit $rc — the flow is not run"
        touch "$KEEP/.broken"
    fi
    return $rc
}

# flow <ID> [key=value…]: flow_<id> (H5, flows.sh); not run when a state failed
flow() {
    local id=$1 fn rc
    shift
    fn=flow_$(_sc_id "$id")
    if _broken; then
        _row flow "$id" skipped "a state failed"
        return 1
    fi
    if ! declare -F "$fn" >/dev/null; then
        _row flow "$id" pending "no $fn yet (H5)"
        return 1
    fi
    say "flow $id $*"
    "$fn" "$@"
    rc=$?
    _row flow "$id${*:+ $*}" "$( ((rc == 0)) && echo green || echo red)" "$( ((rc == 0)) || echo "$fn exit $rc")"
    return $rc
}

# undo_push <cmd…>: a command for scenario_end, run in reverse order
undo_push() {
    printf '%q ' "$@" >>"$KEEP/.undo"
    printf '\n' >>"$KEEP/.undo"
}

track_share() {
    local s
    for s in "$@"; do
        grep -qxF -- "$s" "$KEEP/.shares" 2>/dev/null || printf '%s\n' "$s" >>"$KEEP/.shares"
    done
}

# a flow that must change nothing at all: INV-FILES is checked strictly (the same files, same places, same inodes)
files_strict() { touch "$KEEP/.strict"; }

# ------------------------------------------------------------------------------------------------------------ snapshots
# the files INV-SETTINGS guards (globs expanded at snapshot time)
_settings_files() {
    local f
    for f in "$UB/settings.ini" "$D/moverelli/smart_mover.ini" "$D/moverelli/excludes/"* "$OFFICE_FLASH/"*.cron \
        "$D/office/staff.json" "$D/restore-drill/settings.json" "$D/backup/declined.json" "$D/partner/pairs.json"; do
        [[ $f == *'*' ]] || printf '%s\n' "$f"
    done
}

_unraid_files() {
    local f
    for f in /boot/config/share.cfg /boot/config/shares/*.cfg /boot/config/docker.cfg /boot/config/domain.cfg \
        /boot/config/plugins/dockerMan/templates-user/*.xml /etc/libvirt/qemu/*.xml; do
        [[ $f == *'*' ]] || printf '%s\n' "$f"
    done
}

_md5_list() {
    local f
    while IFS= read -r f; do
        if [[ -f $f ]]; then md5sum -- "$f"; else printf 'MISSING  %s\n' "$f"; fi
    done
}

_snap() {
    local dir=$1 f s
    rm -rf -- "$dir"
    mkdir -p "$dir/files" "$dir/inventory"
    _settings_files | _md5_list >"$dir/settings.md5"
    # (+ Jack Streamer's list: his look rewrites it during a joint run — checks.sh's run card tells his moves from hers)
    for f in "$UB/settings.ini" "$D/moverelli/smart_mover.ini" "$OFFICE_FLASH/"*.cron "$D/mediacache/mediacache_exclude.txt"; do
        [[ -f $f ]] && cp -p -- "$f" "$dir/files/$(basename "$f")"
    done
    _unraid_files | _md5_list >"$dir/unraid.md5"
    cp -p /boot/config/share.cfg "$dir/files/share.cfg" 2>/dev/null
    crontab -l >"$dir/crontab.txt" 2>/dev/null
    cp /etc/cron.d/root "$dir/cron.d-root.txt" 2>/dev/null
    grep -v 'uso-partner:\|uso-ticket:' /root/.ssh/authorized_keys 2>/dev/null | md5sum >"$dir/authorized_keys.md5"
    if has docker; then
        docker ps -a --format '{{.Names}} {{.State}}' | sort >"$dir/docker.txt"
    else
        echo 'docker off' >"$dir/docker.txt"
    fi
    if has vm; then
        virsh list --all 2>/dev/null | awk 'NR>2 && NF>=3 {n=$2; $1=$2=""; sub(/^ +/,""); print n, $0}' | sort >"$dir/virsh.txt"
    else
        echo 'libvirt off' >"$dir/virsh.txt"
    fi
    awk -F'"' '/^\[/{if (n!="") print n, "rotational=" r, "spundown=" s; n=r=s=""} /^name=/{n=$2} /^spundown=/{s=$2}
        /^rotational=/{r=$2} END{if (n!="") print n, "rotational=" r, "spundown=" s}' "$DISKS_INI" >"$dir/disks.txt" 2>/dev/null
    # Unraid keeps each notification in unread/ and archive/ under the same name: one name each
    ls -1 "$NOTIFY_DIR/unread" "$NOTIFY_DIR/archive" 2>/dev/null | grep '\.notify$' | LC_ALL=C sort -u >"$dir/notify.txt"
    cp "$RUN/agent.json" "$dir/agent.json" 2>/dev/null
    ls -la --time-style=+%s "$D/mailbox" >"$dir/mailbox.txt" 2>/dev/null
    stat -c %s /var/log/syslog >"$dir/syslog.size" 2>/dev/null
    cp /proc/locks "$dir/locks.txt"
    if has kopia; then
        timeout 30 docker exec kopia kopia policy list --json >"$dir/kopia-policies.txt" 2>/dev/null || echo 'kopia: no answer' >"$dir/kopia-policies.txt"
    fi
    if [[ -s $KEEP/.shares ]]; then
        while IFS= read -r s; do
            inventory "$s" >"$dir/inventory/$s.tsv"
            _empty_dirs "$s" >"$dir/inventory/$s.empty"
        done <"$KEEP/.shares"
    fi
}

snap_before() {
    rm -rf -- "$KEEP/after"
    _snap "$KEEP/before"
    agent_log_mark before
    # the heartbeat watched over the scenario (INV-TICK): its mtime every 2 s
    _pulse_stop
    (while :; do stat -c %Y "$RUN/agent.json" 2>/dev/null; sleep 2; done) >"$KEEP/.pulse" 2>/dev/null &
    echo $! >"$KEEP/.pulse.pid"
    say "snap_before: $(wc -l <"$KEEP/before/settings.md5") settings files, $(wc -l <"$KEEP/before/unraid.md5") Unraid files, shares: $(paste -sd' ' "$KEEP/.shares" 2>/dev/null || echo none)"
}

snap_after() {
    _pulse_stop
    _snap "$KEEP/after"
    say "snap_after"
}

# The office's own folders inside a share (left out of an inventory: INV-LEFTOVER looks at them)
_INV_PRUNE=(-name .uso-backup -o -name '.uso-backup.new-*' -o -name '.uso-drill-*' -o -name .UnraidSecretaryOffice-trash
    -o -name '*.aside-*' -o -name '*.restored-*' -o -name '*.putback-*' -o -name .btrfs-snap -o -name .zfs
    -o -name '.uso-base-aside*')   # base.sh's asides (and a restore's copy of one beside, .uso-base-aside.restored-…): a
                                   # pool's and a disk's may hold the same names (batch A: INV-COUNTS)

# inventory <share>…: one line per file of the share on every awake disk and pool — rel-path, size, mtime, inode, place
# — read on the disk or pool itself (never through /mnt/user: no look at a disk that sleeps); a base asleep: `#asleep`
inventory() {
    local share b
    for share in "$@"; do
        for b in $(bases); do
            if base_asleep "$b"; then
                printf '#asleep\t%s\n' "$b"
                continue
            fi
            [[ -d /mnt/$b/$share ]] || continue
            find "/mnt/$b/$share" \( "${_INV_PRUNE[@]}" \) -prune -o -not -type d \
                -printf "%P\t%s\t%T@\t%i\t$b\n" 2>/dev/null | awk -F'\t' -v OFS='\t' '{sub(/\..*/,"",$3); print}'
        done
    done | LC_ALL=C sort
}

_empty_dirs() {
    local b
    for b in $(bases); do
        base_asleep "$b" && continue
        [[ -d /mnt/$b/$1 ]] && find "/mnt/$b/$1" \( "${_INV_PRUNE[@]}" \) -prune -o -type d -empty -printf "$b/%P\n" 2>/dev/null
    done | LC_ALL=C sort
}

where_is() { getfattr -n system.LOCATION --only-values --absolute-names -- "$1" 2>/dev/null; echo; }

# ini_diff <a> <b>: `-section|key=value` / `+section|key=value` lines ([share "media"] → share|media)
_ini_flat() {
    awk '/^[[:space:]]*[#;]/ || /^[[:space:]]*$/ {next}
        /^\[/ {s=$0; gsub(/^\[|\]$/,"",s); gsub(/ "/,"|",s); gsub(/"/,"",s); next}
        /=/ {k=$0; sub(/[[:space:]]*=.*/,"",k); sub(/^[[:space:]]+/,"",k); v=$0; sub(/^[^=]*=[[:space:]]*/,"",v);
             sub(/[[:space:]]+$/,"",v); gsub(/^"|"$/,"",v); print (s==""?"":s "|") k "=" v}' "$1" 2>/dev/null | LC_ALL=C sort
}
ini_diff() {
    LC_ALL=C comm -23 <(_ini_flat "$1") <(_ini_flat "$2") | sed 's/^/-/'
    LC_ALL=C comm -13 <(_ini_flat "$1") <(_ini_flat "$2") | sed 's/^/+/'
}

# --------------------------------------------------------------------------------------------------------------- checks
allow() {
    local id=$1
    shift
    printf '%s\t%s\n' "$id" "$*" >>"$KEEP/.allow"
    _row allow "$id" info "$*"
    say "allow $id: $*"
}
_allowed() { awk -F'\t' -v i="$1" '$1==i{print $2; f=1; exit} END{exit !f}' "$KEEP/.allow" 2>/dev/null; }

_expect() {   # <kind> <args…>: recorded for the checks that read them (H5) and in the report
    local kind=$1
    shift
    printf '%s\n' "$@" >>"$KEEP/.expect-$kind"
    _row expect "$kind" info "$*"
}
expect_warnings() { _expect warnings "$@"; }
expect_diff() { _expect diff "$@"; }
expect_changes() { _expect changes "$@"; }
expect_bubble() { local d=$1 k; shift; for k in "$@"; do printf '%s %s\n' "$d" "$k"; done >>"$KEEP/.expect-bubble"; _row expect "bubble $d" info "$*"; }
expect_no_text() { local d=$1 k; shift; for k in "$@"; do printf '%s %s\n' "$d" "$k"; done >>"$KEEP/.expect-no-text"; _row expect "no-text $d" info "$*"; }

# expect_err <key>: the last request was refused with exactly this key
expect_err() {
    local got
    got=$(ask_last '.error.key // "none (accepted)"' 2>/dev/null)
    if [[ $got == "$1" ]]; then _row expect_err "$1" green ""; else _row expect_err "$1" red "got $got"; fi
}

# expect_ok [what]: the last request was accepted
expect_ok() {
    local key
    key=$(ask_last '.error.key // empty' 2>/dev/null)
    if [[ $(ask_last .ok 2>/dev/null) == true ]]; then _row expect_ok "${1:-ask}" green ""; else _row expect_ok "${1:-ask}" red "refused: ${key:-?}"; fi
}

# check standard|final|<INV-ID>…
check() {
    local ids=() id fn out rc why
    if _broken; then
        _row check "$*" skipped "a state failed"
        return 1
    fi
    for id in "$@"; do
        case $id in
            standard) ids+=("${CHECK_STANDARD[@]}") ;;
            final) ids+=("${CHECK_FINAL[@]}") ;;
            *) ids+=("$id") ;;
        esac
    done
    [[ -d $KEEP/before ]] || { _row check snap_before red "the scenario took no snap_before"; return 1; }
    [[ -d $KEEP/after ]] && [[ ! -f $KEEP/.ask-at || $KEEP/after -nt $KEEP/.ask-at ]] || snap_after
    local bad=0
    for id in "${ids[@]}"; do
        [[ $id == INV-FILES && -f $KEEP/.strict ]] && id=INV-FILES-STRICT
        fn=check_$(_sc_id "$id")
        if ! declare -F "$fn" >/dev/null; then
            _row check "$id" pending "pending (H5)"
            continue
        fi
        out=$("$fn" 2>&1)
        rc=$?
        out=${out:0:600}
        out=${out//$'\n'/; }
        case $rc in
            0) _row check "$id" green "$out" ;;
            1)
                if why=$(_allowed "$id"); then
                    _row check "$id" allowed "$why — was: $out"
                else
                    _row check "$id" red "$out"
                    bad=1
                fi
                ;;
            2) _row check "$id" pending "$out" ;;
            *) _row check "$id" n/a "$out" ;;
        esac
        say "check $id → $(awk -F'\t' 'END{print $3}' "$KEEP/.rows")${out:+: ${out:0:200}}"
    done
    return $bad
}

_diff_md5() {   # <file in before/after>: the paths whose md5 changed, appeared or went
    diff <(awk '{print $2, $1}' "$KEEP/before/$1" | sort) <(awk '{print $2, $1}' "$KEEP/after/$1" | sort) |
        awk '/^[<>]/{print $2}' | sort -u
}

# INV-SETTINGS — no saved setting changes without an Apply. With expect_diff: INV-DIFF-EXACT, the ini diff is exactly that.
check_inv_settings() {
    local changed f exp got
    changed=$(_diff_md5 settings.md5)
    [[ -z $changed ]] && { echo "unchanged"; return 0; }
    if [[ -s $KEEP/.expect-diff ]]; then
        got=$(for f in settings.ini smart_mover.ini; do
            [[ -f $KEEP/before/files/$f || -f $KEEP/after/files/$f ]] && ini_diff "$KEEP/before/files/$f" "$KEEP/after/files/$f"
        done | LC_ALL=C sort)
        exp=$(while IFS= read -r f; do
            if [[ $f =~ ^(.+):\ (.*)→(.*)$ ]]; then
                [[ -n ${BASH_REMATCH[2]} ]] && echo "-${BASH_REMATCH[1]}=${BASH_REMATCH[2]}"
                [[ -n ${BASH_REMATCH[3]} ]] && echo "+${BASH_REMATCH[1]}=${BASH_REMATCH[3]}"
            else
                echo "$f"
            fi
        done <"$KEEP/.expect-diff" | LC_ALL=C sort)
        changed=$(grep -v -e '/settings.ini$' -e '/smart_mover.ini$' <<<"$changed")
        if [[ $got == "$exp" && -z $changed ]]; then
            echo "INV-DIFF-EXACT: the diff is exactly what was announced"
            return 0
        fi
        echo "INV-DIFF-EXACT: diff $(paste -sd' ' <<<"$got") ≠ expected $(paste -sd' ' <<<"$exp")${changed:+; also changed: $(paste -sd' ' <<<"$changed")}"
        return 1
    fi
    echo "changed: $(paste -sd' ' <<<"$changed")"
    return 1
}

# INV-FILES — no file lost: every file of before is there after (same path, size, mtime — its place may change),
# or in the storeroom, as an .aside sibling, or in a ZFS snapshot. Only bases awake both times are compared.
check_inv_files() { _files_check loose; }
# INV-FILES-STRICT — the inventory is identical, places and inodes included
check_inv_files_strict() { _files_check strict; }

_files_check() {
    local mode=$1 s bad=() asleep missing rel size mtime ino base n=0
    [[ -s $KEEP/.shares ]] || { echo "no share tracked (track_share)"; return 3; }
    while IFS= read -r s; do
        local b=$KEEP/before/inventory/$s.tsv a=$KEEP/after/inventory/$s.tsv
        asleep=$(cat <(grep '^#asleep' "$b") <(grep '^#asleep' "$a") | cut -f2 | sort -u | paste -sd'|' -)
        n=$((n + $(grep -vc '^#' "$b")))
        if [[ $mode == strict ]]; then
            missing=$(diff <(grep -v '^#' "$b" | grep -Ev "	(${asleep:-^$})$") <(grep -v '^#' "$a" | grep -Ev "	(${asleep:-^$})$") | grep '^[<>]' | head -3)
            [[ -n $missing ]] && bad+=("$s: ${missing//$'\n'/, }")
            continue
        fi
        while IFS=$'\t' read -r rel size mtime ino base; do
            [[ -n $asleep && $base =~ ^($asleep)$ ]] && continue
            # through the environment: awk -v would read a «\» in a name as an escape (back\slash → backslash, never found)
            R=$rel Z=$size M=$mtime awk -F'\t' '$1==ENVIRON["R"] && $2==ENVIRON["Z"] && $3==ENVIRON["M"]{f=1; exit} END{exit !f}' "$a" && continue
            _file_kept "$s" "$rel" && continue
            bad+=("$s/$rel")
        done < <(grep -v '^#' "$b")
    done <"$KEEP/.shares"
    if ((${#bad[@]})); then
        echo "${#bad[@]} lost or changed: ${bad[*]:0:5}"
        return 1
    fi
    echo "$n files, $(wc -l <"$KEEP/.shares") shares${asleep:+ (asleep, not compared: $asleep)}"
}

# a file kept where the office puts what it replaces: storeroom, .aside sibling, a ZFS snapshot of the share
_file_kept() {
    local share=$1 rel=$2 b
    for b in $(bases); do
        base_asleep "$b" && continue
        compgen -G "/mnt/$b/$share/.UnraidSecretaryOffice-trash/*/$rel" >/dev/null && return 0
        compgen -G "/mnt/$b/$share/$rel.aside-*" >/dev/null && return 0
        compgen -G "/mnt/$b/$share/.zfs/snapshot/*/$rel" >/dev/null && return 0
    done
    return 1
}

# INV-LEFTOVER — no leftovers (awake disks and pools only: a disk asleep is never looked at)
check_inv_leftover() {
    local b bad=() x
    for b in $(bases); do
        base_asleep "$b" && continue
        # base.sh's asides (<share root>/.uso-base-aside/<stamp>/~…~) are kept on purpose, never leftovers: skipped
        # (a drill's .uso-drill-* in a storeroom base.sh set aside is no leftover either — coordinator, 2026-10-18)
        x=$(find "/mnt/$b" -maxdepth 3 -name '.uso-base-aside*' -prune -o -name '.uso-backup*' -print 2>/dev/null |
            grep -Ev "^/mnt/$b/(appdata|domains)/[^/]+/\.uso-backup$" | head -3)
        [[ -n $x ]] && bad+=("${x//$'\n'/ }")
        x=$(timeout 60 find "/mnt/$b" -path "/mnt/$b/system/docker" -prune -o -path '*/.UnraidSecretaryOffice-trash' -prune -o -name '.uso-base-aside*' -prune -o \
            \( -name '*.partial' -o -name '.uso-drill-*' \) -print 2>/dev/null | head -3)
        [[ -n $x ]] && bad+=("${x//$'\n'/ }")
    done
    if has docker; then
        x=$(docker ps -a --filter name=uso-drill- --format '{{.Names}}' | head -3)
        [[ -n $x ]] && bad+=("drill containers: ${x//$'\n'/ }")
    fi
    grep -q ' /mnt/addons/UnraidSecretaryOffice/snapshots' /proc/mounts && ! grep -qs '^keep_mounts *= *yes' "$UB/settings.ini" &&
        bad+=("snapshot mounts under /mnt/addons/UnraidSecretaryOffice/snapshots")
    # the btrfs view (view_root): only symlinks belong there (backup.sh unmount_all) — never a mount, nothing else
    x=$(find /mnt/addons/UnraidSecretaryOffice/btrfs-snap -mindepth 1 -maxdepth 1 -not -type l 2>/dev/null | head -2)
    [[ -n $x ]] && bad+=("btrfs-snap holds more than links: ${x//$'\n'/ }")
    grep -q ' /mnt/addons/UnraidSecretaryOffice/btrfs-snap/' /proc/mounts && bad+=("mounts under /mnt/addons/UnraidSecretaryOffice/btrfs-snap")
    x=$(find "$D/mailbox" -maxdepth 1 -type f -mmin +10 2>/dev/null | head -3)
    [[ -n $x ]] && bad+=("old mailbox files: ${x//$'\n'/ }")
    x=$(atq 2>/dev/null | head -3)
    [[ -n $x ]] && bad+=("atq: ${x//$'\n'/; }")
    if [[ -s $KEEP/.shares && -d $KEEP/before/inventory ]]; then
        local s
        while IFS= read -r s; do
            # by path below the base: an empty folder the mover handed to another base (cache/x → disk1/x) isn't new
            x=$(_empty_dirs "$s" | awk 'FILENAME == ARGV[1] {sub(/^[^\/]*\//,""); was[$0]=1; next}
                {r=$0; sub(/^[^\/]*\//,"",r)} !(r in was) {print "> " $0}' "$KEEP/before/inventory/$s.empty" - 2>/dev/null | head -3)
            [[ -n $x ]] && bad+=("new empty folders in $s: ${x//$'\n'/ }")
        done <"$KEEP/.shares"
    fi
    x=$(pgrep -af "$P/backup/(backup|setup)\.sh|$P/agent/agent\.php job |[c]ustommover_run|/run/unraid-secretary-office/[a-z0-9_-]+\.sh" | head -3)
    [[ -n $x ]] && bad+=("processes: ${x//$'\n'/; }")
    # /tmp: the scenarios' own folders and the suite's (tests/run.php: uso-tmp-<name>-<pid>, a worker's copy uso-<task>) apart
    x=$(find /tmp -maxdepth 1 -name '*uso*' -newer "$KEEP/.start" -not -name 'uso-keep' -not -name 'uso-scenarios*' \
        -not -name 'uso-wip-*' -not -name 'uso-tmp-*' -not -name 'uso-main' 2>/dev/null | head -3)
    [[ -n $x ]] && bad+=("/tmp: ${x//$'\n'/ }")
    if ((${#bad[@]})); then
        echo "${bad[*]}"
        return 1
    fi
    echo "none"
}

# INV-NO-STALE-LOCK — no lock nobody holds: nothing runs, so nothing holds the engine's or Ms. Moverelli's locks
check_inv_no_stale_lock() {
    local bad=() f pid
    for f in "$UB/state/lock" "$D/moverelli/"{office-run,office-history,night,smartmover}.lock; do
        lock_held "$f" && bad+=("held: $f")
    done
    pid=$(jq -r '.pid // empty' "$UB/state/lock-holder.json" 2>/dev/null)
    [[ -n $pid ]] && ! lock_held "$UB/state/lock" && kill -0 "$pid" 2>/dev/null && bad+=("lock-holder.json names live pid $pid")
    if [[ $(_ini_value /var/local/emhttp/var.ini mdState) == STARTED ]]; then
        pid=$(head -1 "$RUN/nightshift.lock" 2>/dev/null | tr -dc 0-9)
        [[ -n $pid ]] && kill -0 "$pid" 2>/dev/null && [[ $(cat "/proc/$pid/comm" 2>/dev/null) == php* ]] &&
            bad+=("the night shift ($pid) runs with the array started")
    fi
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "free"
}

# INV-COUNTS — counters = the file system: every part that exists (check_inv_counts_<desk>: backup here, restore and
# moverelli from H5's checks.sh); red when one is red, n/a when all are, «pending» names the parts not built yet
check_inv_counts() {
    local d out rc red=() ok=() na=() pend=()
    for d in backup restore moverelli; do
        if ! declare -F "check_inv_counts_$d" >/dev/null; then pend+=("$d"); continue; fi
        out=$("check_inv_counts_$d" 2>&1)
        rc=$?
        case $rc in 0) ok+=("$d: $out") ;; 1) red+=("$d: $out") ;; *) na+=("$d: $out") ;; esac
    done
    if ((${#red[@]})); then echo "${red[*]}"; return 1; fi
    ((${#ok[@]})) || { echo "${na[*]:-nothing to count}"; return 3; }
    echo "${ok[*]}${na:+; n/a ${na[*]}}${pend:+; pending (H5): ${pend[*]}}"
}

# INV-COUNTS, backup part — Mr. Backupsy's package counts = the place on disk; a fresh run's counts = its status
check_inv_counts_backup() {
    local bj=$D/backup.json bad=() kind on_disk in_state srv run share
    # what the page would show: its fresh look first (straight through ask.php — not one of the scenario's requests)
    [[ ${COUNTS_NO_LOOK:-} ]] || php "$SC_DIR/ask.php" --wait 120 backup.refresh '{}' >/dev/null 2>&1
    [[ -f $bj ]] || { echo "no backup.json"; return 3; }
    [[ -n $PLACE ]] || { echo "no backup place set"; return 3; }
    share=${PLACE#/mnt/user/}
    share=${share%%/*}
    share_asleep "$share" && { echo "a disk of the place's share $share sleeps: not looked at"; return 3; }
    srv=$(jq -r '.run // empty' "$PLACE/server/run.json" 2>/dev/null)
    for kind in apps vms; do
        on_disk=$(for f in "$PLACE/$kind"/*/manifest.json; do [[ -f $f ]] || continue
            run=$(jq -r '.run // ""' "$f"); printf '%s %s\n' "$(basename "$(dirname "$f")")" "$([[ -n $srv && $run < $srv ]] && echo stale || echo fresh)"; done | sort)
        in_state=$(jq -r ".packages.$kind // [] | .[] | \"\(.folder) \(if .stale then \"stale\" else \"fresh\" end)\"" "$bj" | sort)
        [[ $on_disk == "$in_state" ]] || bad+=("$kind: place [$(paste -sd, <<<"$on_disk")] ≠ state [$(paste -sd, <<<"$in_state")]")
    done
    # a real run during the scenario (not a tour or dry run): its row in the page's history says what its status says
    local st=$UB/state/status.json started mode
    started=$(jq -r '.started // 0' "$st" 2>/dev/null)
    mode=$(jq -r '.mode // empty' "$st" 2>/dev/null)
    if [[ $started =~ ^[0-9]+$ && $mode =~ ^(backup|nokopia)$ ]] && ((started >= SC_START)); then
        local a b2
        a=$(jq -c '{errors, warnings}' "$st")
        b2=$(jq -c --arg r "$(jq -r .run "$st")" '[.history[]? | select(.run == $r)][0] // {} | {errors, warnings}' "$bj")
        [[ $a == "$b2" ]] || bad+=("run counts: status $a ≠ history $b2")
    fi
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "apps $(jq '.packages.apps // [] | length' "$bj"), vms $(jq '.packages.vms // [] | length' "$bj") as on the place"
}

# INV-AGENT — the agent is clean: no error in its log since before, heartbeat fresh, the same agent (unless a restart was expected)
check_inv_agent() {
    local bad=() x before now
    x=$(agent_log_since before | grep -E 'Error:|PHP (Warning|Fatal|Notice|Deprecated)|Fatal|restart loop' | head -2)
    [[ -n $x ]] && bad+=("agent.log: ${x//$'\n'/ | }")
    (($(_agent_age) < 70)) || bad+=("heartbeat $(_agent_age) s old")
    [[ $(_agent_field .running) == true ]] || bad+=("agent.json says not running")
    before=$(jq -r '"\(.pid):\(.started)"' "$KEEP/before/agent.json" 2>/dev/null)
    now="$(_agent_field .pid):$(_agent_field .started)"
    [[ -f $KEEP/.restart-from || $before == "$now" ]] || bad+=("another agent: $before → $now")
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "pid ${now%%:*}"
}

# INV-UNRAID-SAME — the server is as it was: containers, VMs, Unraid's cfgs, root's crontab, authorized_keys, Kopia policies
check_inv_unraid_same() {
    local bad=() f x
    for f in docker.txt virsh.txt crontab.txt authorized_keys.md5 kopia-policies.txt; do
        [[ -f $KEEP/before/$f || -f $KEEP/after/$f ]] || continue
        [[ $f == kopia-policies.txt ]] && grep -qsx 'kopia: no answer' "$KEEP/before/$f" "$KEEP/after/$f" && continue
        x=$(diff "$KEEP/before/$f" "$KEEP/after/$f" 2>/dev/null | grep '^[<>]' | head -4)
        [[ -n $x ]] && bad+=("$f: ${x//$'\n'/ }")
    done
    x=$(_diff_md5 unraid.md5)
    if [[ -n $x ]]; then
        # share.cfg: what changed, key by key (MV-SETUP/MV-MOVER-OFF/MV-LETGO may change shareMoverSchedule — allow it)
        if grep -qx /boot/config/share.cfg <<<"$x"; then
            bad+=("share.cfg: $(ini_diff "$KEEP/before/files/share.cfg" "$KEEP/after/files/share.cfg" | paste -sd' ' -)")
            x=$(grep -vx /boot/config/share.cfg <<<"$x")
        fi
        [[ -n $x ]] && bad+=("changed: $(paste -sd' ' <<<"$x")")
    fi
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "same ($(wc -l <"$KEEP/after/docker.txt") containers, $(wc -l <"$KEEP/after/unraid.md5") files)"
}

# INV-NO-WAKE — no disk woken unasked: every disk asleep before is asleep after, unless the scenario sent wake:true
check_inv_no_wake() {
    local d bad=() was
    was=$(awk '$3=="spundown=1" && $2!="rotational=0"{print $1}' "$KEEP/before/disks.txt")
    [[ -n $was ]] || { echo "no disk asleep before"; return 3; }
    for d in $was; do
        grep -qx "$d rotational=[^ ]* spundown=1" "$KEEP/after/disks.txt" || bad+=("$d")
    done
    if ((${#bad[@]})); then
        [[ -f $KEEP/.wake-sent ]] && { echo "woken (${bad[*]}) — the scenario sent wake:true"; return 0; }
        echo "woken: ${bad[*]}"
        return 1
    fi
    echo "still asleep: $(paste -sd' ' <<<"$was")"
}

# INV-NOTIFY-ONCE — at most one notification per event (subject), each of the office's subjects begins with its name
check_inv_notify_once() {
    local new f bad=() subj x
    new=$(comm -13 <(sort "$KEEP/before/notify.txt") <(sort "$KEEP/after/notify.txt"))
    [[ -n $new ]] || { echo "none new"; return 0; }
    # «once» is once per event, not per subject: two runs may send the same subject (and the same summary). A
    # notification carries no run id — its event is its time: one run a minute, so the same subject twice within 60 s
    # is one event told twice (H5, 2026-10-18, the coordinator's rule)
    local subjects=() lines=() ts
    for f in $new; do
        f=$(ls "$NOTIFY_DIR"/{unread,archive}/"$f" 2>/dev/null | head -1)
        [[ -f $f ]] || continue
        grep -q '^event="Unraid Secretary Office"' "$f" || [[ $(basename "$f") == Unraid_Secretary_Office* ]] || continue
        subj=$(_ini_value "$f" subject)
        ts=$(_ini_value "$f" timestamp)
        [[ $subj == 'Unraid Secretary Office'* ]] || bad+=("subject without the office's name: $subj")
        subjects+=("$subj")
        lines+=("${ts:-0}"$'\t'"$subj")
    done
    x=$(printf '%s\n' "${lines[@]}" | sort -t$'\t' -k2,2 -k1,1n | awk -F'\t' '$2 == s && $1 - t < 60 {print $2} {s = $2; t = $1}' | sort -u | head -2)
    [[ -n ${subjects[*]} && -n $x ]] && bad+=("twice within a minute: ${x//$'\n'/ | }")
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "${#subjects[@]} new, each event once"
}

# INV-NO-SECRET — no value of scenarios.env (nor an AWS key) in the state, the logs, the report, the kept evidence.
# The values are read in a subshell and never printed: a hit names the file and «value #n» only.
check_inv_no_secret() {
    local files=() hits f
    files+=("$D"/*.json "$D/agent.log" "$REPORT")
    while IFS= read -r -d '' f; do files+=("$f"); done < <(find "$UB/logs" -name '*.log' -newer "$KEEP/.start" -print0 2>/dev/null)
    while IFS= read -r -d '' f; do [[ $f != "$SCENARIOS_ENV" ]] && files+=("$f"); done < <(find "$KEEP" -type f -print0 2>/dev/null)
    hits=$( (
        [[ -r $SCENARIOS_ENV ]] && . "$SCENARIOS_ENV" >/dev/null 2>&1
        i=0
        for v in $(sed -n -E 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=.*/\2/p' "$SCENARIOS_ENV" 2>/dev/null); do
            i=$((i + 1))
            val=${!v-}
            ((${#val} >= 6)) || continue
            grep -lF -- "$val" "${files[@]}" 2>/dev/null | sed "s|\$| (value #$i)|"
        done
        grep -lE 'AKIA[0-9A-Z]{16}' "${files[@]}" 2>/dev/null | sed 's|$| (AWS key id)|'
    ) | sort -u | head -5)
    if [[ -n $hits ]]; then echo "${hits//$'\n'/; }"; return 1; fi
    [[ -r $SCENARIOS_ENV ]] || { echo "no scenarios.env: only the AWS pattern looked for (${#files[@]} files)"; return 0; }
    echo "${#files[@]} files clean"
}

# INV-TICK — the round stays cheap: the heartbeat never older than 25 s while the scenario ran, office.ping < 1 s
check_inv_tick() {
    local gap ms t0
    [[ -s $KEEP/.pulse ]] || { echo "no heartbeat samples (snap_before starts them)"; return 3; }
    gap=$(awk -v now="$(_sc_now)" 'NR==1{p=$1; first=$1} $1!=p{if ($1-p>g) g=$1-p; p=$1} END{if (now-p>g && now-p<1e6) g2=now-p; print (g>g2?g:g2)+0}' "$KEEP/.pulse")
    t0=$(date +%s%N)
    php "$SC_DIR/ask.php" --wait 5 office.ping '{}' >/dev/null 2>&1
    ms=$((($(date +%s%N) - t0) / 1000000))
    if ((gap > 25)); then
        echo "heartbeat gap ${gap} s"
        return 1
    fi
    if ((ms > 1000)); then
        echo "office.ping ${ms} ms"
        return 1
    fi
    echo "heartbeat gap ≤ ${gap} s, ping ${ms} ms"
}

# INV-CRON-ONE — one line per job in the plugin's cron files, never in root's crontab, each once in /etc/cron.d/root
check_inv_cron_one() {
    local bad=() job n line
    for job in $(grep -ho 'job\.sh [a-z0-9_-]*' "$OFFICE_FLASH"/*.cron 2>/dev/null | awk '{print $2}' | sort -u); do
        n=$(grep -h "job\.sh $job\( \|$\)" "$OFFICE_FLASH"/*.cron | grep -vc '^#')
        ((n <= 1)) || bad+=("$job: $n lines")
    done
    n=$(crontab -l 2>/dev/null | grep -c unraid-secretary-office)
    ((n == 0)) || bad+=("root's crontab: $n lines")
    while IFS= read -r line; do
        [[ -z $line || $line == '#'* ]] && continue
        n=$(grep -cxF -- "$line" /etc/cron.d/root 2>/dev/null)
        ((n == 1)) || bad+=("$n× in /etc/cron.d/root: ${line:0:60}")
    done < <(cat "$OFFICE_FLASH"/*.cron 2>/dev/null)
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "one each"
}

# ------------------------------------------------------------------------------- facts at once, a KEEP outside scenarios
# States, flows and base.sh may use the helpers without scenario_begin: the facts are read now, and what helpers keep
# goes to /tmp/uso-keep/_lib until a scenario names its own KEEP.
_facts
KEEP=$SC_KEEPS/_lib
KEEP_STASH=$KEEP/stash
mkdir -p "$KEEP/ask" "$KEEP_STASH" 2>/dev/null

# ------------------------------------------------------------------------- the other parts (H2 states, H5 flows/checks)
for _f in "$SC_DIR"/states-*.sh "$SC_DIR"/flows.sh "$SC_DIR"/checks.sh; do
    [[ -f $_f ]] && . "$_f"
done
unset _f

# ------------------------------------------------------------------------------------------------------------ self-test
# Run as `bash lib.sh --self-test` on a test VM, under flock /tmp/uso-scenarios.lock. Makes a uso-wip-h1-st share (files
# on the first pool and the first array disk), a container (docker only), fake notifications; proves each check of
# wave 1 green on the quiet state and red on its violation — real ones where they are cheap (files, leftovers,
# notifications, locks, a container), edited snapshots where the VM can't (a disk asleep: QEMU ignores STANDBY);
# undoes all and looks for leftovers.
_st() {   # <expected rc> <what> <cmd…>: one assertion of the self-test
    local want=$1 what=$2 out rc
    shift 2
    out=$("$@" 2>&1)
    rc=$?
    out=${out//$'\n'/; }
    if [[ $rc == "$want" ]]; then
        _row self-test "$what" green "rc $rc: ${out:0:160}"
    else
        _row self-test "$what" red "rc $rc, wanted $want: ${out:0:200}"
    fi
    say "self-test $what → rc $rc (want $want)"
}

_st_share_remove() {
    local b
    for b in $(bases); do
        base_asleep "$b" && continue
        [[ -d /mnt/$b/uso-wip-h1-st ]] && rm -rf -- "/mnt/$b/uso-wip-h1-st"
    done
    rm -f /tmp/uso-h1-st-leftover
    [[ ! -e /mnt/user/uso-wip-h1-st ]]
}

_st_inv_asleep() { inventory uso-wip-h1-st | grep -qx "#asleep	$1"; }

_st_notify_remove() { rm -f "$NOTIFY_DIR"/unread/Unraid_Secretary_Office_uso_wip_h1_st_*.notify; }

_st_notify() {   # <n> <subject>
    mkdir -p "$NOTIFY_DIR/unread"
    printf 'timestamp=%s\nevent="Unraid Secretary Office"\nsubject="%s"\ndescription="self-test"\nimportance="normal"\n' \
        "$(_sc_now)" "$2" >"$NOTIFY_DIR/unread/Unraid_Secretary_Office_uso_wip_h1_st_$1.notify"
}

_self_test() {
    scenario_begin lib-self-test
    needs any
    local pool disk b real_after
    for b in $(bases); do
        [[ -z ${pool:-} && ! $b =~ ^disk[0-9]+$ ]] && pool=$b
        [[ -z ${disk:-} && $b =~ ^disk[0-9]+$ ]] && ! base_asleep "$b" && disk=$b
    done
    say "self-test on pool ${pool:-none}, array disk ${disk:-none}"
    [[ -n ${pool:-} ]] || { _row self-test pool red "no pool to make the share on"; scenario_end; }

    # ---- pure helpers
    _st 0 "_sc_id" test "$(_sc_id INV-NO-WAKE)" = inv_no_wake
    printf '[general]\nserver = A\n[share "media"]\nmode = local\nkeep = 3\n' >"$KEEP/a.ini"
    printf '[general]\nserver = A\n[share "media"]\nmode = kopia\nkeep = 3\n' >"$KEEP/b.ini"
    _st 0 "ini_diff" test "$(ini_diff "$KEEP/a.ini" "$KEEP/b.ini" | paste -sd' ' -)" = '-share|media|mode=local +share|media|mode=kopia'
    _st 0 "bases" test -n "$(bases)"
    _st 0 "host_is any" host_is any
    _st 1 "has nonsense" has nonsense

    # ---- the share, its files, a container
    undo_push _st_share_remove
    mkdir -p "/mnt/$pool/uso-wip-h1-st/sub"
    local i
    for i in 1 2 3 4 5; do
        head -c 4096 /dev/urandom >"/mnt/$pool/uso-wip-h1-st/f000$i"
        touch -d '2026-01-0'"$i"' 12:00' "/mnt/$pool/uso-wip-h1-st/f000$i"
    done
    touch "/mnt/$pool/uso-wip-h1-st/st.lock"
    # a name with a backslash (SH-NAMES' back\slash): INV-FILES must find it again (awk -v once read it as an escape)
    head -c 4096 /dev/urandom >"/mnt/$pool/uso-wip-h1-st/back\\slash"
    if [[ -n ${disk:-} ]]; then
        mkdir -p "/mnt/$disk/uso-wip-h1-st"
        echo disk >"/mnt/$disk/uso-wip-h1-st/f-disk"
    fi
    track_share uso-wip-h1-st
    expect_changes 'share|uso-wip-h1-st|*'     # the scratch share is new for Mr. Backupsy: his proposal for it
    if has docker; then
        local img
        img=$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -m1 '^alpine:' || docker images --format '{{.Repository}}:{{.Tag}}' | head -1)
        if [[ -n $img ]]; then
            undo_push docker rm -f uso-wip-h1-st
            docker create --name uso-wip-h1-st --label net.unraid.docker.icon=/plugins/dynamix.docker.manager/images/question.png \
                --entrypoint /bin/true "$img" >/dev/null
            expect_changes 'docker|known' 'docker|no_stop' 'dump|uso-wip-h1-st|*'   # the new container: Mr. Backupsy's proposal for it
            _st 0 "container made" docker inspect -f '{{.State.Status}}' uso-wip-h1-st
        fi
    else
        say "self-test: no Docker here — the container part is left out"
        _row self-test container n/a "Docker isn't running"
    fi
    snap_before

    # ---- mailbox, agent
    ask office.ping >/dev/null
    expect_ok office.ping
    ask_err nosuch.action >/dev/null
    expect_err unknown_action
    _st 0 "state_json" state_json backup '.interface'
    _st 0 "agent_wait" agent_wait 30
    _st 0 "doorbell_ok" doorbell_ok
    _st 0 "agent_log_since" agent_log_since begin
    _st 1 "lock_held (engine lock free)" lock_held "$UB/state/lock"
    flock "/mnt/$pool/uso-wip-h1-st/st.lock" sleep 4 &
    i=$!
    sleep 1
    _st 0 "lock_held through /mnt/user" lock_held /mnt/user/uso-wip-h1-st/st.lock
    wait "$i"
    _st 0 "where_is" test "$(where_is /mnt/user/uso-wip-h1-st/f0001)" = "$pool"
    _st 0 "inventory" test "$(inventory uso-wip-h1-st | wc -l)" = "$((7 + ${disk:+1}+0))"   # f0001–f0005, st.lock, back\slash

    # ---- the quiet state: every check green
    snap_after
    for i in INV-SETTINGS INV-FILES INV-FILES-STRICT INV-LEFTOVER INV-NO-STALE-LOCK INV-AGENT INV-UNRAID-SAME INV-NOTIFY-ONCE \
        INV-NO-SECRET INV-TICK INV-CRON-ONE; do
        _st 0 "$i quiet" "check_$(_sc_id "$i")"
    done

    # ---- INV-FILES: a file gone (red), in the storeroom (green), moved to another disk (loose green, strict red)
    mkdir -p "$KEEP_STASH/files"
    mv "/mnt/$pool/uso-wip-h1-st/f0003" "$KEEP_STASH/files/"
    snap_after
    _st 1 "INV-FILES file gone" check_inv_files
    mkdir -p "/mnt/$pool/uso-wip-h1-st/.UnraidSecretaryOffice-trash/st-run"
    cp -p "$KEEP_STASH/files/f0003" "/mnt/$pool/uso-wip-h1-st/.UnraidSecretaryOffice-trash/st-run/"
    _st 0 "INV-FILES file in the storeroom" check_inv_files
    rm -rf "/mnt/$pool/uso-wip-h1-st/.UnraidSecretaryOffice-trash"
    mv "$KEEP_STASH/files/f0003" "/mnt/$pool/uso-wip-h1-st/"
    rmdir "$KEEP_STASH/files"
    if [[ -n ${disk:-} ]]; then
        cp -p "/mnt/$pool/uso-wip-h1-st/f0002" "/mnt/$disk/uso-wip-h1-st/f0002" && rm "/mnt/$pool/uso-wip-h1-st/f0002"
        snap_after
        _st 0 "INV-FILES moved to $disk" check_inv_files
        _st 1 "INV-FILES-STRICT moved to $disk" check_inv_files_strict
        cp -p "/mnt/$disk/uso-wip-h1-st/f0002" "/mnt/$pool/uso-wip-h1-st/f0002" && rm "/mnt/$disk/uso-wip-h1-st/f0002"
    fi
    echo x >"/mnt/$pool/uso-wip-h1-st/sub/f-changed"
    snap_after
    _st 1 "INV-FILES-STRICT a new file" check_inv_files_strict
    rm -f "/mnt/$pool/uso-wip-h1-st/sub/f-changed"

    # ---- INV-LEFTOVER: a .partial, a new empty folder, a /tmp file
    touch "/mnt/$pool/uso-wip-h1-st/x.partial"
    _st 1 "INV-LEFTOVER .partial" check_inv_leftover
    rm -f "/mnt/$pool/uso-wip-h1-st/x.partial"
    mkdir "/mnt/$pool/uso-wip-h1-st/empty-new"
    _st 1 "INV-LEFTOVER empty folder" check_inv_leftover
    rmdir "/mnt/$pool/uso-wip-h1-st/empty-new"
    touch /tmp/uso-h1-st-leftover
    _st 1 "INV-LEFTOVER /tmp file" check_inv_leftover
    rm -f /tmp/uso-h1-st-leftover
    _st 0 "INV-LEFTOVER clean again" check_inv_leftover

    # ---- INV-NO-WAKE: a disk asleep before, awake after (the snapshot edited: the VM's disks can't sleep)
    if [[ -n ${disk:-} ]]; then
        snap_after
        sed -i "s/^$disk rotational=\([^ ]*\) spundown=0$/$disk rotational=1 spundown=1/" "$KEEP/before/disks.txt"
        _st 1 "INV-NO-WAKE woken" check_inv_no_wake
        cp "$KEEP/before/disks.txt" "$KEEP/after/disks.txt"
        _st 0 "INV-NO-WAKE still asleep" check_inv_no_wake
        touch "$KEEP/.wake-sent"
        sed -i "s/^$disk rotational=1 spundown=1$/$disk rotational=1 spundown=0/" "$KEEP/after/disks.txt"
        _st 0 "INV-NO-WAKE woken on wake:true" check_inv_no_wake
        rm -f "$KEEP/.wake-sent"
        # Unraid's bookkeeping says asleep: the helpers never look at that disk
        sed "/^\[\"$disk\"\]/,/^\[/ s/^spundown=\"0\"/spundown=\"1\"/; /^\[\"$disk\"\]/,/^\[/ s/^rotational=\"[01]\"/rotational=\"1\"/" "$DISKS_INI" >"$KEEP/disks-asleep.ini"
        DISKS_INI=$KEEP/disks-asleep.ini _st 0 "base_asleep $disk (fixture)" base_asleep "$disk"
        DISKS_INI=$KEEP/disks-asleep.ini _st 1 "base_asleep ${disk}0 (fixture)" base_asleep "${disk}0"
        DISKS_INI=$KEEP/disks-asleep.ini _st 0 "inventory leaves $disk asleep alone" _st_inv_asleep "$disk"
        printf '["uso-wip-h1-st"]\nname="uso-wip-h1-st"\nuseCache="yes"\ncachePool="%s"\ninclude=""\nexclude=""\n["uso-wip-h1-p"]\nuseCache="only"\ncachePool="%s"\n' \
            "$pool" "$pool" >"$KEEP/shares.ini"
        SHARES_INI=$KEEP/shares.ini DISKS_INI=$KEEP/disks-asleep.ini _st 0 "share_asleep on $disk (fixture)" share_asleep uso-wip-h1-st
        SHARES_INI=$KEEP/shares.ini DISKS_INI=$KEEP/disks-asleep.ini _st 1 "share_asleep pool only (fixture)" share_asleep uso-wip-h1-p
        _st 1 "base_asleep $disk (real)" base_asleep "$disk"
    fi

    # ---- INV-NOTIFY-ONCE: twice the same subject; a subject without the office's name
    undo_push _st_notify_remove
    _st_notify 1 'Unraid Secretary Office: self-test'
    snap_after
    _st 0 "INV-NOTIFY-ONCE once" check_inv_notify_once
    _st_notify 2 'Unraid Secretary Office: self-test'
    snap_after
    _st 1 "INV-NOTIFY-ONCE twice" check_inv_notify_once
    _st_notify_remove
    _st_notify 3 'Self-test without the name'
    snap_after
    _st 1 "INV-NOTIFY-ONCE no prefix" check_inv_notify_once
    _st_notify_remove

    # ---- INV-NO-SECRET: a value of a (fake) scenarios.env in the kept evidence
    printf 'USO_ST_FAKE=st-not-a-secret-4711\n' >"$KEEP/fake.env"
    SCENARIOS_ENV=$KEEP/fake.env _st 0 "INV-NO-SECRET clean (fake env)" check_inv_no_secret
    echo "leaked st-not-a-secret-4711" >"$KEEP/leak.txt"
    SCENARIOS_ENV=$KEEP/fake.env _st 1 "INV-NO-SECRET leak" check_inv_no_secret
    rm -f "$KEEP/leak.txt" "$KEEP/fake.env"

    # ---- INV-NO-STALE-LOCK: an engine lock held (a fake engine folder)
    mkdir -p "$KEEP/ub/state"
    touch "$KEEP/ub/state/lock"
    flock "$KEEP/ub/state/lock" sleep 4 &
    i=$!
    sleep 1
    UB=$KEEP/ub _st 1 "INV-NO-STALE-LOCK held" check_inv_no_stale_lock
    wait "$i"
    UB=$KEEP/ub _st 0 "INV-NO-STALE-LOCK let go" check_inv_no_stale_lock

    # ---- INV-SETTINGS / INV-DIFF-EXACT: a fake engine folder whose settings.ini changes
    mkdir -p "$KEEP/ub"
    cp "$KEEP/a.ini" "$KEEP/ub/settings.ini"
    UB=$KEEP/ub snap_before
    cp "$KEEP/b.ini" "$KEEP/ub/settings.ini"
    UB=$KEEP/ub snap_after
    _st 1 "INV-SETTINGS changed" check_inv_settings
    expect_diff 'share|media|mode: local→kopia'
    _st 0 "INV-DIFF-EXACT announced" check_inv_settings
    printf 'extra = 1\n' >>"$KEEP/ub/settings.ini"
    UB=$KEEP/ub snap_after
    _st 1 "INV-DIFF-EXACT one more" check_inv_settings
    rm -f "$KEEP/.expect-diff"
    rm -rf "$KEEP/ub"

    # ---- edited snapshots: another agent, a long tick, a changed container, cron twice
    snap_before
    snap_after
    real_after=$KEEP/after.real
    cp -a "$KEEP/after" "$real_after"
    jq '.pid = 1' "$KEEP/before/agent.json" >"$KEEP/x.json" && mv "$KEEP/x.json" "$KEEP/before/agent.json"
    _st 1 "INV-AGENT another agent" check_inv_agent
    printf '%s\n' $(($(_sc_now) - 60)) $(($(_sc_now) - 20)) >"$KEEP/.pulse"
    _st 1 "INV-TICK 40 s gap" check_inv_tick
    printf '%s\n' $(($(_sc_now) - 15)) $(($(_sc_now) - 2)) >"$KEEP/.pulse"
    _st 0 "INV-TICK 13 s gap" check_inv_tick
    if has docker; then
        docker start uso-wip-h1-st >/dev/null 2>&1
        snap_after
        _st 1 "INV-UNRAID-SAME container started" check_inv_unraid_same
    fi
    echo 'x' >>"$KEEP/after/crontab.txt"
    _st 1 "INV-UNRAID-SAME root's crontab" check_inv_unraid_same
    mkdir -p "$KEEP/flash"
    printf '1 1 * * * bash %s/scripts/job.sh backup\n2 2 * * * bash %s/scripts/job.sh backup\n' "$P" "$P" >"$KEEP/flash/x.cron"
    OFFICE_FLASH=$KEEP/flash _st 1 "INV-CRON-ONE twice" check_inv_cron_one
    rm -rf "$KEEP/flash" "$real_after"

    # ---- INV-COUNTS: a package on the place the state doesn't know (a fake place and state)
    mkdir -p "$KEEP/place/apps/A" "$KEEP/place/apps/B" "$KEEP/place/server" "$KEEP/d"
    echo '{"run":"20260101-0100"}' >"$KEEP/place/server/run.json"
    echo '{"run":"20260101-0100"}' >"$KEEP/place/apps/A/manifest.json"
    echo '{"run":"20251231-0100"}' >"$KEEP/place/apps/B/manifest.json"
    echo '{"packages":{"apps":[{"folder":"A","stale":false},{"folder":"B","stale":true}],"vms":[]}}' >"$KEEP/d/backup.json"
    COUNTS_NO_LOOK=1 D=$KEEP/d PLACE=$KEEP/place _st 0 "INV-COUNTS as on the place" check_inv_counts_backup
    echo '{"packages":{"apps":[{"folder":"A","stale":false}],"vms":[]}}' >"$KEEP/d/backup.json"
    COUNTS_NO_LOOK=1 D=$KEEP/d PLACE=$KEEP/place _st 1 "INV-COUNTS a package unknown" check_inv_counts_backup
    COUNTS_NO_LOOK=1 D=$KEEP/d PLACE=$KEEP/place _st 1 "INV-COUNTS red through the whole" check_inv_counts
    rm -rf "$KEEP/place" "$KEEP/d"

    # ---- a restart the way a deploy makes one: a new agent (another start time), INV-AGENT lets it pass once marked
    local was
    was=$(_agent_field .started)
    _st 0 "agent_restart" agent_restart
    _st 0 "a new agent" test "$(_agent_field .started)" != "$was"
    _st 0 "INV-AGENT after a marked restart" check_inv_agent
    rm -f "$KEEP/.restart-from"
    _st 0 "the doorbell of the new agent" doorbell_ok
    ask office.ping >/dev/null
    expect_ok "office.ping after the restart"

    # ---- the end: a fresh before/after, the standard set as a scenario sees it
    if has docker; then docker stop uso-wip-h1-st >/dev/null 2>&1; fi
    snap_before
    check standard
    scenario_end
}

if [[ ${BASH_SOURCE[0]} == "$0" && ${1:-} == --self-test ]]; then
    _self_test
fi
