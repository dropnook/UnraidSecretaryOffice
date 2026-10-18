#!/bin/bash
# A test VM back to its base (playbook §3) - on the VM as root, idempotent, about a minute. NEVER deletes: what is not
# base is set aside (the data folder as <data>.aside-<stamp>, leftovers in a share as <root>/.uso-base-aside/<stamp>/~…~,
# ZFS snapshots renamed @uso-aside-<stamp>-…, flash files under scenarios-aside/<stamp>/); only --prune removes asides,
# all but the newest 3. Every uso-wip-* share, container, folder and dataset is left alone, and so is what a partner
# sent (…/UnraidSecretaryOffice-partners/…).
#
#   bash base.sh [--dry]            what it would do, changing nothing (read it before the first real reset)
#   bash base.sh                    the reset: steps 1-7 below
#   bash base.sh --full             also `apps.sh all --pull` (images anew) where apps.sh is there
#   bash base.sh --make [--dry]     the base made anew from a scripted first setup through the mailbox: the office's data
#                                   folder aside, a fresh one, desks hired, Mr. Backupsy set up (the default for
#                                   everything: BACKUP_PRESET), schedules, Ms. Moverelli's X and rules, the drill - then
#                                   kept as <data>.base/ (data.tar, cron, manifest) and the reset done with it
#   bash base.sh --prune [--dry]    asides older than the newest 3 removed (data folders, share asides, snapshots, flash)
#
# Steps: 0 refuse while anything of the office's runs (a backup run or setup, restore, drill, Jack Streamer's or Ms.
# Moverelli's run - the plugin's own pattern - or the engine's or her lock held); the Tower lock (flock
# /tmp/uso-scenarios.lock, resilienz/wave1/_shared.md) for the whole reset. 1 office: the agent stopped, the engine's
# mounts under /mnt/addons/UnraidSecretaryOffice unmounted, the data folder aside, <data>.base/data.tar unpacked, CARRY
# folders copied over from the old one, the plugin's cron file from <data>.base/cron. 2 shares: the base's from
# base/<host>/shares.list (cfg compared with base/<host>/shares/), the test shares (base/test-shares.list) via the
# states' share_make/put_files, others reported (share_remove where there). 3 engine leftovers aside: @uso-backup-* and
# @uso-plan-* snapshots, .btrfs-snap, the backup place's apps/ vms/ server/ flash/, .uso-backup* in app and VM folders,
# the storeroom, *.aside-<n>, *.restored-<n>, *.partial, .uso-drill-*. 4 apps (Tower): apps.sh all - when there.
# 5 VMs: vm_make - when there. 6 Unraid: the mover's schedule, Mover Tuning, User Scripts, parity check, disks spun up,
# NTP, the time zone, unread notifications archived (Unraid's notify archive). 7 the agent started, its heartbeat,
# then `check standard` against the base (lib.sh, once it is there).
# Facts per VM: base/<tower|test3>/base.env. Exit 0 the base is there, 1 a step failed (said), 2 refused / wrong call.
set -u
SC=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TREE=$(cd "$SC/../.." && pwd)

dry=0 full=0 make=0 prune=0 made=0
ARGS=("$@")
while (($#)); do
    case $1 in
        --dry) dry=1 ;;
        --full) full=1 ;;
        --make) make=1 ;;
        --prune) prune=1 ;;
        -h|--help) sed -n '2,33p' "$0"; exit 0 ;;
        *) echo "base: unknown argument $1" >&2; exit 2 ;;
    esac
    shift
done

T0=$SECONDS
STAMP=$(date +%Y%m%d-%H%M%S)
fails=0
log() { echo "base: $*"; }
warn() { echo "base: ! $*"; }
# act <what> <cmd…>: said, and done unless --dry; a failure counted
act() {
    local what=$1
    shift
    if ((dry)); then echo "base: would $what"; return 0; fi
    echo "base: $what"
    "$@" || { warn "failed: $what"; fails=$((fails + 1)); return 1; }
}

# ---------------------------------------------------------------------------------------- the helpers of the others
# lib.sh (H1) and the states (H2: share_make, put_files, share_remove, …; H4: vm_make, unraid_schedule_set, …), sourced
# before our own names are set; a function counts only when it does more than `:` (the skeleton's stubs)
if [[ -f $SC/lib.sh ]]; then
    # shellcheck disable=SC1091
    . "$SC/lib.sh" >/dev/null 2>&1
fi
real_fn() { declare -F "$1" >/dev/null && declare -f "$1" | sed '1,2d;$d' | grep -qvE '^\s*:;?\s*(#.*)?$'; }

# --------------------------------------------------------------------------------------------------------- the facts
P=/usr/local/emhttp/plugins/unraid-secretary-office
RUN=/var/run/unraid-secretary-office
CFG=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
CRON=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron
FLASH_ASIDE=/boot/config/plugins/unraid-secretary-office/scenarios-aside
LOCK=/tmp/uso-scenarios.lock
JOBS="^((/usr)?(/bin/)?bash $P/backup/(backup|setup)\.sh|([^ ]*/)?php $P/agent/agent\.php job (restore|restore-drill|embycache|gather|moverelli)( |\$))"

[[ $(id -u) == 0 ]] || { echo "base: runs as root on the test VM" >&2; exit 2; }
addrs=$(ip -4 -o addr show | awk '{print $4}' | cut -d/ -f1)
if grep -qxF 192.168.7.111 <<<"$addrs"; then HOST=tower
elif grep -qxF 192.168.7.197 <<<"$addrs"; then HOST=test3
else echo "base: not a test VM (Tower 192.168.7.111, USOTest3VM 192.168.7.197) - refused" >&2; exit 2; fi
BASE=$SC/base/$HOST
[[ -f $BASE/base.env ]] || { echo "base: no $BASE/base.env" >&2; exit 2; }
HIRE='' BACKUP_PRESET=auto BACKUP_CRON='' MOVERELLI_X=80 MOVERELLI_CRON='' MOVERELLI_RULES='' DRILL='' MOVER_SCHEDULE='' LOCALTIME='' CARRY='' DOCKER=no
# shellcheck disable=SC1090
. "$BASE/base.env"
D=$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$CFG" 2>/dev/null | head -1)
[[ $D == /mnt/*/* && $D != *..* ]] || { echo "base: no data folder in $CFG" >&2; exit 2; }
DB=$D.base
# the Tower lock for the whole reset, taken first (another worker's experiment holds it while its job runs; not for a
# dry run: it changes nothing): base.sh runs again under `flock -o` - the lock's fd never reaches a child (the agent it
# starts would hold the lock for its whole life). A caller holding the lock says USO_SC_LOCKED=1.
if ((!dry)) && [[ ${USO_SC_LOCKED:-} != 1 ]]; then
    USO_SC_LOCKED=1 flock -o -w 1800 -E 75 "$LOCK" bash "$0" "${ARGS[@]}"
    code=$?
    ((code == 75)) && echo "base: the Tower lock ($LOCK) stayed busy for 30 min - refused" >&2 && exit 2
    exit $code
fi
log "$HOST ($(hostname)), data folder $D, base $DB$( ((dry)) && echo ' - dry run, nothing changes')"

# the pools and disks mounted under /mnt (their share roots are where leftovers lie)
roots() { findmnt -rn -o TARGET | grep -E '^/mnt/[^/]+$' | grep -vE '^/mnt/(user|user0|addons|rootshare|remotes|disks)$' | sort -u; }
array_started() { grep -q '^mdState="STARTED"' /var/local/emhttp/var.ini; }

# ------------------------------------------------------------------------------------------------- 0. may it run?

refuse=''
array_started || refuse="${refuse}the array is not started; "
pgrep -f "$JOBS" >/dev/null 2>&1 && refuse="${refuse}a job of the office runs ($(pgrep -af "$JOBS" | head -1 | cut -c1-120)); "
# the engine's lock and hers: held = flock -n fails (on the pool path too - shfs answers for the backing file)
held() {
    local f p
    for f in "$@"; do
        [[ -e $f ]] || continue
        flock -n -E 99 "$f" true 2>/dev/null; [[ $? == 99 ]] && return 0
        p=$(readlink -f "$f" 2>/dev/null)
        [[ -n $p && $p != "$f" && -e $p ]] && { flock -n -E 99 "$p" true 2>/dev/null; [[ $? == 99 ]] && return 0; }
    done
    return 1
}
held "$D/unraid-backup/state/lock" && refuse="${refuse}the engine's lock is held (a backup run or setup); "
held "$D/moverelli/office-run.lock" "$D/moverelli/night.lock" && refuse="${refuse}Ms. Moverelli's run lock is held; "
if ((!make && !prune)) && [[ ! -f $DB/data.tar ]]; then
    refuse="${refuse}no base yet ($DB/data.tar) - make it once: base.sh --make; "
fi
if [[ -n $refuse ]]; then
    if ((dry)); then warn "would refuse: ${refuse%; }"; else echo "base: refused - ${refuse%; }" >&2; exit 2; fi
fi

# -------------------------------------------------------------------------------------------------------- --prune
# all but the newest 3 of each kind of aside base.sh made
if ((prune)); then
    old() { sort -r | awk 'NR > 3'; }
    for x in $(ls -d "$D".aside-* 2>/dev/null | old); do act "remove the old data folder aside $x" rm -rf -- "$x"; done
    for x in $(ls -d "$DB".aside-* 2>/dev/null | old); do act "remove the old base aside $x" rm -rf -- "$x"; done
    for r in $(roots); do
        for s in "$r"/*/.uso-base-aside; do
            [[ -d $s ]] || continue
            for x in $(ls -d "$s"/[0-9]* 2>/dev/null | old); do act "remove the old share aside $x" rm -rf -- "$x"; done
        done
    done
    for x in $(ls -d "$FLASH_ASIDE"/[0-9]* 2>/dev/null | old); do act "remove the old flash aside $x" rm -rf -- "$x"; done
    if command -v zfs >/dev/null; then
        keep=$(zfs list -H -t snapshot -o name 2>/dev/null | sed -n 's/.*@uso-aside-\([0-9]\{8\}-[0-9]\{6\}\)-.*/\1/p' | sort -ru | head -3 | paste -sd'|')
        zfs list -H -t snapshot -o name 2>/dev/null | grep '@uso-aside-' | grep -v '/UnraidSecretaryOffice-partners' \
            | { [[ -n $keep ]] && grep -vE "@uso-aside-($keep)-" || cat; } | while read -r s; do
            act "destroy the old snapshot aside $s" zfs destroy "$s"
        done
    fi
    log "pruned in $((SECONDS - T0)) s"
    exit $((fails ? 1 : 0))
fi

agent_up() {        # the heartbeat in RAM fresh (< 30 s) from a live agent
    local f=$RUN/agent.json
    [[ -f $f ]] && (($(date +%s) - $(stat -c %Y "$f") < 30)) && grep -q '"running":true' "$f"
}
agent_wait() {
    local i
    for ((i = 0; i < ${1:-90}; i++)); do agent_up && return 0; sleep 1; done
    return 1
}
agent_stop() { bash "$P/scripts/agent.sh" stop >/dev/null; }
agent_start() { bash "$P/scripts/agent.sh" start >/dev/null && agent_wait 90; }

# ------------------------------------------------------------------------------------------------- 1a. the office down
step() { echo; log "--- $*"; }
step "1 the office"
act "stop the agent" agent_stop
# the engine's mounts (keep_mounts=no: none should be there), deepest first
mounts=$(findmnt -rn -o TARGET | grep -E '^/mnt/addons/UnraidSecretaryOffice/(snapshots|btrfs-snap)/' | sort -r)
for m in $mounts; do act "unmount the engine's leftover mount $m" umount "$m"; done
[[ -z $mounts ]] && log "no engine mounts left under /mnt/addons/UnraidSecretaryOffice"

if [[ -d $D ]]; then
    act "set the data folder aside: $D → $D.aside-$STAMP" mv -- "$D" "$D.aside-$STAMP"
else
    log "no data folder at $D (nothing to set aside)"
fi

# ------------------------------------------------------------------------------- --make: the scripted first setup
# through the mailbox as the buttons do it (src/mailbox.php askAgent, src/staff.php for hiring - the page's own path)
ask() {             # ask <desk.action> [json] [wait s] → the answer (state left out); 1 on ok:false
    local body=${2-}
    [[ -n $body ]] || body='{}'
    php -r '
        $P = "/usr/local/emhttp/plugins/unraid-secretary-office";
        require "$P/src/bootstrap.php"; require_once "$P/src/mailbox.php";
        $d = json_decode($argv[2] ?? "{}", true);
        try { $r = askAgent($argv[1], is_array($d) ? $d : [], (float) ($argv[3] ?? 60)); }
        catch (Throwable $e) { $r = ["ok" => false, "error" => ["key" => get_class($e) . ": " . $e->getMessage()]]; }
        unset($r["state"]);
        echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        exit(($r["ok"] ?? false) === true ? 0 : 1);' -- "$1" "$body" "${3:-60}"
}
hire() {
    php -r '
        $P = "/usr/local/emhttp/plugins/unraid-secretary-office";
        require "$P/src/bootstrap.php";
        try { $r = officeStaffAction("office.hire", ["desks" => array_slice($argv, 1)]); }
        catch (Throwable $e) { $r = ["ok" => false, "error" => $e->getMessage()]; }
        echo json_encode($r), PHP_EOL;
        exit(($r["ok"] ?? false) === true ? 0 : 1);' -- "$@"
}
wait_json() {       # wait_json <file> <jq test> <s>: until the file says so
    local i
    for ((i = 0; i < $3; i++)); do jq -e "$2" "$1" >/dev/null 2>&1 && return 0; sleep 1; done
    return 1
}
make_base() {
    local plan body st sh
    agent_start || { warn "the agent did not start on a fresh data folder"; return 1; }
    [[ -d $D/mailbox ]] || { warn "no mailbox in the fresh data folder"; return 1; }
    log "hire: $HIRE"
    hire $HIRE || return 1
    # Mr. Backupsy: a plan, then Apply with the default for everything, as the setup page sends it
    log "Mr. Backupsy: a plan"
    ask backup.setup_plan '{}' 60 >/dev/null || return 1
    st=$D/unraid-backup/state/setup-status.json
    sleep 2
    wait_json "$st" '.running != true and .mode == "plan" and .result != null' 600 || { warn "the plan did not finish"; return 1; }
    # the page's look at the plan: on a fresh data folder the first one starts the plan anew (the engine's note isn't
    # there yet) and answers without one - looked at again once that plan is done
    local i
    for ((i = 0; i < 5; i++)); do
        ask backup.setup_get '{}' 60 | jq '.plan' > /tmp/uso-base-plan.json || return 1
        jq -e '(.P | type) == "object" and .O != null' /tmp/uso-base-plan.json >/dev/null && break
        log "Mr. Backupsy: the plan is made anew - waiting for it"
        sleep 3
        wait_json "$st" '.running != true and .mode == "plan" and .result != null' 600 || { warn "the plan did not finish"; return 1; }
    done
    body=$(node "$TREE/tools/scenarios/plan-changes.mjs" /tmp/uso-base-plan.json --decisions "$BACKUP_PRESET") || { warn "no decisions from desk.js"; return 1; }
    rm -f /tmp/uso-base-plan.json
    log "Mr. Backupsy: Apply ($BACKUP_PRESET for everything, $(jq '.decisions | length' <<<"$body") keys)"
    ask backup.setup_apply "$body" 60 || return 1
    sleep 2
    wait_json "$st" '.running != true and .mode == "apply" and .result != null' 900 || { warn "the apply did not finish"; return 1; }
    jq -e '.result == "ok" or .result == "warnings"' "$st" >/dev/null || { warn "the apply ended $(jq -r .result "$st")"; return 1; }
    if [[ -n $BACKUP_CRON ]]; then ask backup.schedule "$(jq -nc --arg c "$BACKUP_CRON" '{cron: $c}')" || return 1; fi
    # Ms. Moverelli: her settings as her page has them, with X and the shares of MOVERELLI_RULES (those there)
    ask moverelli.refresh >/dev/null || return 1
    body=$(jq -c --argjson x "$MOVERELLI_X" '.settings | .global.move_when_used_above = $x' "$D/moverelli.json") || return 1
    for sh in $MOVERELLI_RULES; do
        if [[ -f /boot/config/shares/$sh.cfg ]]; then
            body=$(jq -c --arg s "$sh" '.shares[$s] = {skip: false, min_age: null, move_when_used_above: null, files: [], patterns: []}' <<<"$body")
        else
            warn "Ms. Moverelli: no rule for $sh - the share is not there (the states' share_make makes it)"
        fi
    done
    ask moverelli.save "$(jq -nc --argjson s "$body" '{settings: $s}')" || return 1
    if [[ -n $MOVERELLI_CRON ]]; then ask moverelli.schedule "$(jq -nc --arg c "$MOVERELLI_CRON" '{cron: $c}')" || return 1; fi
    if [[ -n $DRILL ]]; then ask restore.drill_set "$(jq -nc --arg s "$DRILL" '{schedule: $s}')" || return 1; fi
    sleep 3
    agent_stop
    # kept: the data folder, the cron file it made, what it is
    [[ -d $DB ]] && mv -- "$DB" "$DB.aside-$STAMP"
    mkdir -p "$DB" && chmod 700 "$DB" || return 1
    tar -C "$D" -cpf "$DB/data.tar.new" . && mv "$DB/data.tar.new" "$DB/data.tar" || return 1
    cp -p "$CRON" "$DB/cron" 2>/dev/null || : > "$DB/cron"
    {
        echo "made=$(date '+%Y-%m-%d %H:%M:%S')"
        echo "host=$HOST"
        echo "office=$(sed -n "s/.*const OFFICE_VERSION = '\([^']*\)'.*/\1/p" "$P/src/bootstrap.php")"
        echo "engine=$(sed -n 's/^UB_VERSION="\([0-9.]*\)".*/\1/p' "$P/backup/lib/common.sh" | head -1)"
        echo "unraid=$(sed -n 's/^version="\(.*\)"$/\1/p' /etc/unraid-version)"
        echo "hire=$HIRE"
        echo "backup=$BACKUP_PRESET $BACKUP_CRON"
        echo "moverelli=X $MOVERELLI_X, rules: $MOVERELLI_RULES, $MOVERELLI_CRON"
        echo "drill=$DRILL"
    } > "$DB/manifest"
    log "base kept in $DB ($(du -sh "$DB/data.tar" | cut -f1))"
}
if ((make)); then
    step "1 the base made anew (--make)"
    if ((dry)); then
        log "would start the agent on a fresh data folder, hire $HIRE, plan + Apply Mr. Backupsy ($BACKUP_PRESET for everything),"
        log "would set schedules (backup $BACKUP_CRON, Ms. Moverelli $MOVERELLI_CRON), her X=$MOVERELLI_X and rules ($MOVERELLI_RULES), the drill $DRILL,"
        log "would keep it as $DB/data.tar + cron + manifest (an older base aside as $DB.aside-$STAMP)"
    else
        make_base && made=1 || { warn "the base could not be made - the office stays on a fresh data folder; the old one is $D.aside-$STAMP"; agent_start; exit 1; }
    fi
fi

# ---------------------------------------------------------------------------------------- 1b. the base data folder
if [[ -f $DB/data.tar ]] || ((dry && make)); then
    # (just made: the data folder is the base already)
    ((made)) || act "unpack the base into $D ($(sed -n 's/^made=//p' "$DB/manifest" 2>/dev/null || echo 'made now'))" \
        bash -c 'mkdir -p "$1" && tar -C "$1" -xpf "$2"' _ "$D" "$DB/data.tar"
    # from the data folder just set aside, else the newest aside that has it (a failed --make leaves a fresh one between)
    for c in $CARRY; do
        from=$(ls -d "$D".aside-*/"$c" 2>/dev/null | sort -r | head -1)
        [[ -e $D.aside-$STAMP/$c ]] && from=$D.aside-$STAMP/$c
        if [[ -n $from ]]; then
            act "carry $c over from ${from%/*}" bash -c 'rm -rf -- "$1/$2" && cp -a -- "$3" "$1/$2"' _ "$D" "$c" "$from"
        elif ((dry)); then
            log "would carry $c over from the data folder set aside"
        else
            log "nothing to carry for $c"
        fi
    done
    if [[ -f $DB/cron ]] && ! cmp -s "$DB/cron" "$CRON"; then
        act "the plugin's cron file from the base (the old one aside)" bash -c '
            mkdir -p "$3" && { [[ -f $2 ]] && cp -p "$2" "$3/"; true; } && cp "$1" "$2.new" && mv "$2.new" "$2" && /usr/local/sbin/update_cron' \
            _ "$DB/cron" "$CRON" "$FLASH_ASIDE/$STAMP"
    else
        log "the plugin's cron file is the base's"
    fi
fi

# ------------------------------------------------------------------------------------------------------- 2. shares
step "2 shares"
listed() { sed -e 's/#.*//' -e '/^[[:space:]]*$/d' "$1" 2>/dev/null; }
base_shares=$(listed "$BASE/shares.list")
test_shares=$(listed "$SC/base/test-shares.list" | cut -f1)
while IFS= read -r s; do
    [[ -n $s ]] || continue
    if [[ -f $BASE/shares/$s.cfg ]]; then
        if [[ ! -f /boot/config/shares/$s.cfg ]]; then warn "share $s: no cfg on the flash (the base has one) - needs the states' share_make"
        elif ! diff -q <(tr -d '\r' < "$BASE/shares/$s.cfg") <(tr -d '\r' < "/boot/config/shares/$s.cfg") >/dev/null; then
            if real_fn share_cfg_set; then
                warn "share $s: cfg differs from the base - set back key by key"
                while IFS='=' read -r k v; do
                    [[ -n $k ]] || continue
                    grep -qxF "$k=$v" "/boot/config/shares/$s.cfg" || act "share $s: $k=$v" share_cfg_set "$s" "$k" "${v//\"/}"
                done < <(tr -d '\r' < "$BASE/shares/$s.cfg")
            else
                warn "share $s: cfg differs from the base (left: needs the states' share_cfg_set) - $(diff <(tr -d '\r' < "$BASE/shares/$s.cfg") <(tr -d '\r' < "/boot/config/shares/$s.cfg") | grep '^[<>]' | tr '\n' ' ' | cut -c1-200)"
            fi
        fi
    fi
    [[ -d /mnt/user/$s ]] || warn "share $s: not there (/mnt/user/$s)"
done <<<"$base_shares"
log "base shares: $(echo $base_shares)"
# shares that aren't base (not the base's, not a test share, not uso-wip-*): reported, removed only by share_remove
for f in /boot/config/shares/*.cfg; do
    [[ -f $f ]] || continue
    s=$(basename "$f" .cfg)
    grep -qxF "$s" <<<"$base_shares" && continue
    grep -qxF "$s" <<<"$test_shares" && continue
    [[ $s == uso-wip-* ]] && continue
    if real_fn share_remove; then act "remove the share $s (not base; share_remove sets its files aside)" share_remove "$s"
    else warn "share $s is not base - left (needs the states' share_remove)"; fi
done
# the test shares and their files
if real_fn share_make && real_fn put_files; then
    while IFS=$'\t' read -r s use pool files; do
        [[ -n $s ]] || continue
        if [[ -f /boot/config/shares/$s.cfg ]]; then log "test share $s there"
        else act "make the test share $s ($use${pool:+, $pool})" share_make "$s" "$use" $([[ $pool != - ]] && echo "$pool"); fi
        read -r fb fn <<<"$files"
        act "its files ($fn from $fb)" put_files "$fb" "$s" "$fn"
    done < <(listed "$SC/base/test-shares.list")
else
    log "skipped: test shares ($(echo $test_shares | tr '\n' ' ')) - not built yet (the states' share_make/put_files, H2)"
fi

# --------------------------------------------------------------------------------------------- 3. engine leftovers
step "3 engine leftovers"
# aside_in <share root> <path inside it>: moved to <root>/.uso-base-aside/<stamp>/~<path with / as %>~ (the same
# disk: a rename; the ~…~ name matches none of INV-LEFTOVER's patterns)
aside_in() {
    local root=$1 rel=$2 to
    to=$root/.uso-base-aside/$STAMP/~${rel//\//%}~
    act "set aside ${root}/${rel}" bash -c 'mkdir -p "$(dirname "$2")" && mv -- "$1" "$2"' _ "$root/$rel" "$to"
}
if command -v zfs >/dev/null 2>&1; then
    snaps=$(zfs list -H -t snapshot -o name 2>/dev/null | grep -E '@uso-(backup|plan)-' | grep -v '/UnraidSecretaryOffice-partners' | grep -v 'uso-wip-')
    n=$(grep -c . <<<"$snaps")
    if [[ -n $snaps ]]; then
        log "$n snapshot(s) @uso-backup-*/@uso-plan-* → @uso-aside-$STAMP-…"
        while read -r s; do
            ((dry)) && continue
            zfs rename "$s" "${s%@*}@uso-aside-$STAMP-${s#*@}" || { warn "could not rename $s"; fails=$((fails + 1)); }
        done <<<"$snaps"
        ((dry)) && log "would rename them: $(head -3 <<<"$snaps" | tr '\n' ' ')…"
    else
        log "no @uso-backup-*/@uso-plan-* snapshots"
    fi
fi
place_share=$(sed -n 's/^dumps_share *= *//p' "$D/unraid-backup/settings.ini" "$D.aside-$STAMP/unraid-backup/settings.ini" 2>/dev/null | head -1)
found=0
for r in $(roots); do
    for sroot in "$r"/*; do
        [[ -d $sroot ]] || continue
        s=$(basename "$sroot")
        [[ $s == uso-wip-* || $s == .* ]] && continue
        # the backup place's parts
        if [[ -n $place_share && $s == "$place_share" ]]; then
            for part in apps vms server flash; do
                [[ -e $sroot/backup/$part ]] && { aside_in "$sroot" "backup/$part"; found=$((found + 1)); }
            done
        fi
        # everything else the engine, Mr. Restori, Ms. Dustdevil or a drill leaves (not in the office's own data folders,
        # not in what base.sh set aside before, not inside a uso-wip-* folder)
        while IFS= read -r -d '' x; do
            rel=${x#"$sroot"/}
            case /$s/$rel in
                */.uso-base-aside/*|*/UnraidSecretaryOffice/data|*/UnraidSecretaryOffice/data/*|*/UnraidSecretaryOffice/data.*|*/uso-wip-*) continue ;;
            esac
            aside_in "$sroot" "$rel"
            found=$((found + 1))
        done < <(find "$sroot" -xdev -maxdepth 4 \( -name .uso-base-aside -o -name 'uso-wip-*' -o -path '*/UnraidSecretaryOffice/data*' \) -prune -o \
                 \( -name '.uso-backup*' -o -name '.btrfs-snap' -o -name '.UnraidSecretaryOffice-trash' -o -name '_UnraidSecretaryOffice-trash' \
                    -o -name '*.aside-[0-9]*' -o -name '*.restored-[0-9]*' -o -name '.uso-drill-*' \) -prune -print0 2>/dev/null)
        # *.partial: only near the top (an app's own .partial files deep in appdata are its business)
        while IFS= read -r -d '' x; do
            aside_in "$sroot" "${x#"$sroot"/}"
            found=$((found + 1))
        done < <(find "$sroot" -xdev -maxdepth 3 \( -name .uso-base-aside -o -name 'uso-wip-*' \) -prune -o -name '*.partial' -print0 2>/dev/null)
    done
done
((found)) || log "no leftovers in the shares"

# -------------------------------------------------------------------------------------------------- 4. apps, 5. VMs
step "4 apps, 5 VMs"
if [[ $DOCKER == yes ]]; then
    if [[ -f $SC/apps.sh ]]; then act "the demo apps (apps.sh all$( ((full)) && echo ' --pull'))" bash "$SC/apps.sh" all $( ((full)) && echo --pull)
    else log "skipped: apps - not built yet (apps.sh, H2)"; fi
fi
if real_fn vm_make; then act "the plain VM" vm_make plain
else log "skipped: VMs - not built yet (vm_make, wave 1b)"; fi

# ------------------------------------------------------------------------------------------------------- 6. Unraid
step "6 Unraid"
mover=$(tr -d '\r' < /boot/config/share.cfg | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p')
if [[ $mover == "$MOVER_SCHEDULE" ]]; then log "Unraid's mover schedule as the base: «$mover»"
elif real_fn unraid_schedule_set; then act "Unraid's mover schedule «$mover» → «$MOVER_SCHEDULE»" unraid_schedule_set "$MOVER_SCHEDULE"
else warn "Unraid's mover schedule is «$mover», the base «$MOVER_SCHEDULE» (left: needs unraid_schedule_set)"; fi
ls /var/log/plugins/ 2>/dev/null | grep -qiE 'mover.tuning' && warn "Mover Tuning is installed (the base has none - tuning_remove)"
ls /boot/config/plugins/user.scripts/scripts 2>/dev/null | grep -q . && warn "User Scripts has scripts (the base has none): $(ls /boot/config/plugins/user.scripts/scripts | tr '\n' ' ')"
if [[ $(sed -n 's/^mdResync="\(.*\)"$/\1/p' /var/local/emhttp/var.ini) != 0 ]]; then
    if real_fn parity_stop; then act "stop the parity check" parity_stop; else warn "a parity check runs (left: needs parity_stop)"; fi
else log "no parity check"; fi
asleep=$(awk -F= '/^\[/{n=$0} /^spundown="1"/{print n}' /var/local/emhttp/disks.ini | tr -d '[]"' | tr '\n' ' ')
if [[ -n $asleep ]]; then act "spin up the disks asleep: $asleep" /usr/local/sbin/emcmd cmdSpinupAll=apply
else log "every disk spun up"; fi
pgrep -x ntpd >/dev/null && log "ntpd runs" || warn "ntpd does not run (the clock is not from NTP)"
lt=$(readlink /etc/localtime)
[[ $lt == "$LOCALTIME" ]] && log "time zone as the base: $lt ($(date +%Z))" || warn "time zone $lt, the base $LOCALTIME"
nu=$(ls /tmp/notifications/unread 2>/dev/null | wc -l)
if ((nu)); then
    act "archive $nu unread notification(s) (Unraid's notify archive)" bash -c \
        'for f in /tmp/notifications/unread/*; do /usr/local/emhttp/webGui/scripts/notify archive "$(basename "$f")" >/dev/null 2>&1; done; true'
else log "no unread notifications"; fi

# ------------------------------------------------------------------------------------------ 7. at work and verified
step "7 the agent, the check"
if ((dry)); then
    log "would start the agent and wait for its heartbeat"
else
    act "start the agent" agent_start || warn "no heartbeat after 90 s"
    # quiet before the base counts: what the agent starts on its first looks (a plan made anew - the engine's note, the
    # partnerships carried over) finished, at most 5 min
    sleep 5
    q=$SECONDS
    while pgrep -f "$JOBS" >/dev/null 2>&1 && ((SECONDS - q < 300)); do sleep 2; done
    if pgrep -f "$JOBS" >/dev/null 2>&1; then warn "a job of the office still runs after 5 min: $(pgrep -af "$JOBS" | head -1 | cut -c1-120)"; fails=$((fails + 1))
    elif ((SECONDS - q > 1)); then log "the agent's first jobs done in $((SECONDS - q)) s ($(grep -c 'started via at' "$D/agent.log" 2>/dev/null) started: $(grep 'started via at' "$D/agent.log" 2>/dev/null | tail -1 | cut -c21-140))"
    else log "the office is quiet"; fi
fi
if real_fn scenario_begin && real_fn check && real_fn scenario_end; then
    if ((dry)); then log "would run check standard against the base (lib.sh)"
    else
        ( USO_SC_OUT=${USO_SC_OUT:-/tmp/uso-scenarios/base} scenario_begin office-base-verify && check standard; scenario_end ) \
            && log "check standard: green" || { warn "check standard against the base is red - the base is not clean"; fails=$((fails + 1)); }
    fi
else
    log "check standard: pending - lib.sh's checks are not there yet (H1)"
fi

echo
if ((dry)); then log "dry run: nothing changed ($((SECONDS - T0)) s)"
elif ((fails)); then log "done in $((SECONDS - T0)) s - $fails step(s) FAILED"
else log "done in $((SECONDS - T0)) s"; fi
exit $((fails ? 1 : 0))
