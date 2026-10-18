#!/bin/bash
# tools/scenarios/checks.sh — the invariants of resilienz/invarianten.md that wave 1 left «pending (H5)» (H5, wave 1b).
# Sourced by lib.sh after flows.sh; each check_inv_<id> prints one line and returns 0 green · 1 red · 2 pending ·
# 3 not applicable (lib.sh's contract). Added to lib.sh's standard set (CHECK_STANDARD) — those that don't apply say
# n/a: INV-SETUP-MSGS INV-STOPPED-KEEPS INV-NC-MAINT-OFF INV-MOUNTS-GONE INV-NO-MNT-OPEN INV-COMPARE
# INV-MIGRATE-IDEMPOTENT. The final set (INV-BACKUP-AFTER, INV-DRILL-GREEN) is lib.sh's.
#
# The page (INV-PAGE, INV-BUBBLE) is looked at by `bubble_look <label> [desk…]`: the desks' fresh looks (what opening
# the page asks), the real facts of the server at that moment (bubble_facts → facts.json), the data folder's state files
# copied (the page is built from them on the Mac: page.mjs), and the rules of bubble-rules.tsv judged —
#   * every rule whose condition is false → the page must not show that line (queue_mac … page_text … not <keys>) — a
#     greeting too: page.mjs draws the page once per greeting (Math.random pinned), whatever the desk could pick;
#   * INV-PAGE: the page's text clean (queue_mac … page_clean: no raw key, undefined, NaN, «{n}», script name, «0 of 0» …)
#   * the scenario's expect_bubble / expect_no_text (want / not).
# Flows call bubble_look while a run is at work; INV-PAGE/INV-BUBBLE take a last look after the flow. On the VM alone
# (no Mac) the page part stays «pending (mac)»; run.sh completes it.
#
# expect_changes (INV-REPLAN): the change keys the replan may list besides the base's known ones
# (base/<host>/plan-changes.known) — as plan-changes.mjs prints them (`docker|known`, `app|EmbyServer|mode`), a trailing
# `*` matches the rest (`app|EmbyServer|*`).

CHECK_STANDARD+=(INV-SETUP-MSGS INV-STOPPED-KEEPS INV-NC-MAINT-OFF INV-MOUNTS-GONE INV-NO-MNT-OPEN INV-COMPARE INV-MIGRATE-IDEMPOTENT)
BUBBLE_RULES=$SC_DIR/bubble-rules.tsv
PAGE_LANGS=${PAGE_LANGS:-de,en}

_ck_json() { jq -cn "$@"; }
_ck_hired() { jq -r '.hired // {} | keys[]' "$D/office/staff.json" 2>/dev/null; }
_ck_is_hired() { _ck_hired | grep -qx "$1"; }
_ck_cron_has() { cat "$OFFICE_FLASH"/*.cron 2>/dev/null | grep -v '^[[:space:]]*#' | grep -q "job\.sh $1\( \|$\)"; }

# ------------------------------------------------------------------------------------------------------------- facts
# The server as it is now, for the bubble rules — never through the office's own state files where the server can say
# it (a lock in /proc/locks, the containers' labels, the files on the disks), never a sleeping disk.
# her run card's live answer (moverelli.progress → its state: {time, running, real, stopping, progress, night, jack})
_ck_mv_state() { php "$SC_DIR/ask.php" --wait 20 --raw moverelli.progress '{}' 2>/dev/null | jq -c '.state // empty' 2>/dev/null; }
_ck_mv_progress() { _ck_mv_state | jq -c '.progress // null' 2>/dev/null; }
_ck_pools() { awk -F'"' '/^\[/{n=""} /^name=/{n=$2} /^type=/{if ($2=="Cache") print n}' "$DISKS_INI" 2>/dev/null; }
# a share's files on an awake array disk (prefer: what a run moves to the pool) — the first found
_ck_on_array() {
    local b
    for b in $(bases); do
        [[ $b =~ ^disk[0-9]+$ ]] || continue
        base_asleep "$b" && continue
        [[ -n $(find "/mnt/$b/$1" -type f -print -quit 2>/dev/null) ]] && return 0
    done
    return 1
}
_ck_on_pool() {
    local p=$1 s=$2
    base_asleep "$p" && return 1
    [[ -n $(find "/mnt/$p/$s" -type f -print -quit 2>/dev/null) ]]
}
_ck_share_use() { awk -v s="[\"$1\"]" '$0==s{f=1; next} /^\[/{f=0} f && /^useCache=/{gsub(/useCache=|"/,""); print; exit}' "$SHARES_INI" 2>/dev/null; }
_ck_share_pool() { awk -v s="[\"$1\"]" '$0==s{f=1; next} /^\[/{f=0} f && /^cachePool=/{gsub(/cachePool=|"/,""); print; exit}' "$SHARES_INI" 2>/dev/null; }

bubble_facts() {
    local bk_run=false bk_mode='' hist=0 last='' sched=false setup_run=false waiting=0 asleep_last=false
    local st=$UB/state/status.json
    # a run of the engine's: its lock held and its status running (a restore or the setup hold the same lock)
    if lock_held "$UB/state/lock" && [[ $(jq -r '.result // ""' "$st" 2>/dev/null) == running ]]; then
        bk_run=true
        bk_mode=$(jq -r '.mode // ""' "$st" 2>/dev/null)
    fi
    [[ -f $UB/state/history.jsonl ]] && hist=$(grep -c . "$UB/state/history.jsonl")
    last=$(tail -1 "$UB/state/history.jsonl" 2>/dev/null | jq -r '.result // ""' 2>/dev/null)
    [[ $bk_run == false ]] && [[ $(jq -r '.mode // ""' "$st" 2>/dev/null) =~ ^(backup|nokopia)$ ]] && last=$(jq -r '.result // ""' "$st")
    _ck_cron_has backup && sched=true
    _setup_done || setup_run=true
    waiting=$(jq '[.. | objects | select(has("folder"))] | length' "$UB/state/new-local.json" 2>/dev/null || echo 0)
    # and what the agent's backupWaiting() adds: new apps (a compose project or a single container, none of its containers
    # in [docker] known; Kopia's own container left out - only once known lists any) and VMs without a [vm "<name>"] section
    local ini=$UB/settings.ini known kopia_ct newapps=0 newvms=0
    if [[ -f $ini ]]; then
        known=$(_ini_flat "$ini" | sed -n 's/^docker|known=//p')
        kopia_ct=$(_ini_flat "$ini" | sed -n 's/^kopia|container=//p' | head -1)
        if [[ -n $known ]] && has docker; then
            newapps=$(docker ps -a --format '{{.Names}}\t{{.Label "com.docker.compose.project"}}' 2>/dev/null |
                awk -F'\t' -v k="$known" -v kc="$kopia_ct" 'BEGIN{n=split(k,a,"\n"); for(i=1;i<=n;i++) K[a[i]]=1}
                    $1!="" && $1!=kc {app=($2!=""?$2:$1); if(!(app in seen)){seen[app]=1; new[app]=1} if($1 in K) new[app]=0}
                    END{c=0; for(x in new) c+=new[x]; print c}')
        fi
        if has vm; then
            newvms=$(virsh list --all --name 2>/dev/null | grep -v '^$' | while IFS= read -r v; do
                _ini_flat "$ini" | grep -q "^vm|$v|" || echo "$v"; done | grep -c .)
        fi
    fi
    waiting=$(( ${waiting:-0} + ${newapps:-0} + ${newvms:-0} ))
    [[ $(jq -r 'if .asleep == null then "no" else "yes" end' "$st" 2>/dev/null) == yes ]] && asleep_last=true

    # Mr. Restori: packages on the place (folders with a manifest), a restore job or a drill running
    local pk=0 rs_run=false drill_run=false place_asleep=false share
    if [[ -n $PLACE ]]; then
        share=${PLACE#/mnt/user/}; share=${share%%/*}
        if share_asleep "$share"; then place_asleep=true
        else pk=$(find "$PLACE/apps" "$PLACE/vms" -mindepth 2 -maxdepth 2 -name manifest.json 2>/dev/null | wc -l); fi
    fi
    pgrep -f '[a]gent\.php job restore [0-9A-Za-z]' >/dev/null && rs_run=true
    pgrep -f '[a]gent\.php job restore-drill' >/dev/null && drill_run=true

    # Ms. Moverelli: her run at work, where it moves to (her live progress names the file's way; a prefer share with
    # files left on the array = moves to a pool), another mover on schedule, her cron line
    local mv_conf=false mv_run=false to_pool=false to_array=false others=false mv_sched=false prog s use pool
    [[ -f $D/moverelli/smart_mover.ini ]] && mv_conf=true
    if _mv_at_work 2>/dev/null || [[ $(jq -r '.phase // ""' "$D/moverelli/night.json" 2>/dev/null) =~ ^(check|free|recheck|fill|tell)$ ]]; then
        mv_run=true
        prog=$(_ck_mv_progress)
        printf '%s\n' "${prog:-null}" >"$KEEP/.mv-progress.json"
        local to
        to=$(jq -r '.to // .dest // empty' <<<"${prog:-null}" 2>/dev/null)
        if [[ -n $to ]]; then
            if _ck_pools | grep -qx "$to"; then to_pool=true; else to_array=true; fi
        fi
        for s in $(awk -F'"' '/^\[/{gsub(/^\["|"\]$/,""); n=$0} /^useCache=/{print n "\t" $2}' "$SHARES_INI" 2>/dev/null | awk -F'\t' '$2=="prefer"{print $1}'); do
            _ck_on_array "$s" && to_pool=true
            # a prefer share in the run's plan still to come or at work (its files may be moved already — the run's
            # way is array → pool all the same while it lasts)
            jq -e --arg s "$s" '((.plan // []) | index($s)) != null and ((.shares // []) | map(.share) | index($s)) == null' <<<"${prog:-null}" >/dev/null 2>&1 && to_pool=true
        done
        for s in $(awk -F'"' '/^\[/{gsub(/^\["|"\]$/,""); n=$0} /^useCache=/{print n "\t" $2}' "$SHARES_INI" 2>/dev/null | awk -F'\t' '$2=="yes"{print $1}'); do
            pool=$(_ck_share_pool "$s")
            [[ -n $pool ]] && _ck_on_pool "$pool" "$s" && to_array=true
        done
    fi
    [[ -n $(tr -d '\r' </boot/config/share.cfg 2>/dev/null | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p') ]] && others=true
    [[ -d /boot/config/plugins/ca.mover.tuning ]] && others=true
    _ck_cron_has moverelli && mv_sched=true

    # Ms. Dustdevil: containers that show a question mark (no icon label — what Unraid's Docker page shows), Compose
    # Manager stacks without a usable icon_url (no Compose Manager: Unraid shows no stack, none can lack a picture)
    local nopic=0 stacknopic=0 dd=false
    if has docker; then
        nopic=$(docker ps -a --format '{{.Label "net.unraid.docker.icon"}}|{{.Names}}' 2>/dev/null | grep -c '^|')
        if [[ -d /boot/config/plugins/compose.manager/projects ]]; then
            local p
            for p in /boot/config/plugins/compose.manager/projects/*/; do
                [[ -d $p ]] || continue
                local u
                u=$(sed -n 's/^icon_url=//p' "$p/icon_url" 2>/dev/null; cat "$p/icon_url" 2>/dev/null)
                [[ -n ${u//[[:space:]]/} ]] || stacknopic=$((stacknopic + 1))
            done
        fi
    elif [[ $(tr -d '\r' </boot/config/docker.cfg 2>/dev/null | sed -n 's/^DOCKER_ENABLED="\(.*\)"$/\1/p') == yes ]]; then
        dd=true
    fi

    # Ms. Snapshotini: snapshots mounted, disks asleep
    local mounted asleep
    mounted=$(grep -c ' /mnt/addons/UnraidSecretaryOffice/snapshots/' /proc/mounts)
    asleep=$(disks_asleep | grep -c .)

    jq -cn --argjson br "$bk_run" --arg bm "$bk_mode" --argjson hn "$hist" --arg lr "$last" --argjson bs "$sched" \
        --argjson su "$setup_run" --argjson wn "${waiting:-0}" --argjson al "$asleep_last" \
        --argjson pk "$pk" --argjson rr "$rs_run" --argjson dr "$drill_run" --argjson pa "$place_asleep" \
        --argjson mc "$mv_conf" --argjson mr "$mv_run" --argjson tp "$to_pool" --argjson ta "$to_array" --argjson mo "$others" \
        --argjson ms "$mv_sched" --argjson np "$nopic" --argjson sp "$stacknopic" --argjson dd "$dd" \
        --argjson sm "$mounted" --argjson sa "$asleep" '{
        backup: {running: $br, mode: $bm, history_n: $hn, last_result: $lr, scheduled: $bs, setup_running: $su, waiting_n: $wn, asleep_last: $al},
        restore: {packages: $pk, running: $rr, drill_running: $dr, place_asleep: $pa},
        moverelli: {configured: $mc, running: $mr, to_pool: $tp, to_array: $ta, others_on: $mo, scheduled: $ms},
        cleanup: {without_picture: $np, stacks_without_picture: $sp, docker_down: $dd},
        snapshot: {mounted: $sm, asleep: $sa}}'
}

# the lang keys of a desk matching a key or glob (bubble.running_* → each)
_ck_keys() {
    local desk=$1 glob=$2 f=$SC_TREE/public/desks/$1/lang/en.json
    [[ -f $f ]] || return 0
    jq -r 'keys[]' "$f" | while IFS= read -r k; do
        # shellcheck disable=SC2053
        [[ $k == $glob ]] && echo "$k"
    done
}

# the desks that have a page and are hired (the Team Lead always)
_ck_page_desks() {
    local d
    for d in caretaker $(_ck_hired); do
        [[ -f $SC_TREE/public/desks/$d/desk.js ]] && echo "$d"
    done | awk '!s[$0]++'
}

# bubble_look <label> [desk…]: one look at the bubbles, greetings and tiles (default: every hired desk with a page)
bubble_look() {
    local label=${1:-look} desks=("${@:2}") n dir facts d rule key cond why k ok notkeys
    [[ -n $SC_NAME ]] || { say "bubble_look: only inside a scenario"; return 1; }
    ((${#desks[@]})) || mapfile -t desks < <(_ck_page_desks)
    n=$(($(cat "$KEEP/.looks" 2>/dev/null || echo 0) + 1))
    echo "$n" >"$KEEP/.looks"
    dir=$KEEP/looks/$(printf %02d "$n")-$label
    mkdir -p "$dir/states/post"
    # the page's fresh look first (opening a desk asks <desk>.refresh) — straight through ask.php, not the scenario's ask
    for d in "${desks[@]}"; do
        [[ $d == caretaker ]] && continue
        php "$SC_DIR/ask.php" --wait 120 "$d.refresh" '{}' >/dev/null 2>&1
    done
    facts=$(bubble_facts)
    printf '%s\n' "$facts" >"$dir/facts.json"
    find "$D" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$dir/states/" \;
    [[ -d $SC_POST ]] && find "$SC_POST" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$dir/states/post/" \;
    say "bubble_look $n ($label): ${desks[*]} — $(jq -c '{bk: .backup.running, mv: .moverelli.running, to_pool: .moverelli.to_pool, nopic: .cleanup.without_picture}' <<<"$facts")"
    # her run at work: the card's live answer saved for the page (the harness serves it as the part «progress»), and
    # the card's lines judged against the disks now (INV-PAGE: its percent, the file's way)
    if [[ " ${desks[*]} " == *" moverelli "* ]] && [[ $(jq -r .moverelli.running <<<"$facts") == true ]]; then
        _ck_tiles_moverelli "$label" "$dir"
    fi
    for d in "${desks[@]}"; do
        notkeys=()
        while IFS=$'\t' read -r rule key cond why; do
            [[ -z $rule || $rule == '#'* || $rule != "$d" ]] && continue
            ok=$(jq -r "try ($cond) catch \"error\"" <<<"$facts" 2>/dev/null)
            if [[ $ok == error || -z $ok ]]; then
                printf '%s\t%s\t%s\n' "$label" "$d.$key" "rule condition does not evaluate: $cond" >>"$KEEP/.bubble-red"
                continue
            fi
            [[ $ok == true ]] && continue
            # every key the page must not show now — a greeting too: page.mjs draws the page once per greeting
            # (Math.random pinned), so whatever the desk's own logic could pick is seen
            for k in $(_ck_keys "$d" "$key"); do notkeys+=("$d.$k"); done
        done <"$BUBBLE_RULES"
        queue_mac INV-PAGE page_clean "$d" "$dir/states" "$PAGE_LANGS"
        ((${#notkeys[@]})) && queue_mac INV-BUBBLE page_text "$d" "$dir/states" "$PAGE_LANGS" not "${notkeys[*]}"
    done
    # the scenario's own expectations (after the flow: the last look carries them)
    if [[ $label == final ]]; then
        local want=() nott=() x y
        while read -r x y; do [[ -n $y ]] && want+=("$x.$y"); done < <(sed 's/ / /' "$KEEP/.expect-bubble" 2>/dev/null)
        while read -r x y; do [[ -n $y ]] && nott+=("$x.$y"); done < <(cat "$KEEP/.expect-no-text" 2>/dev/null)
        for d in $(printf '%s\n' "${want[@]%%.*}" "${nott[@]%%.*}" | sort -u); do
            local w=() o=()
            for k in "${want[@]}"; do [[ ${k%%.*} == "$d" ]] && w+=("$k"); done
            for k in "${nott[@]}"; do [[ ${k%%.*} == "$d" ]] && o+=("$k"); done
            ((${#w[@]})) && queue_mac INV-BUBBLE page_text "$d" "$dir/states" "$PAGE_LANGS" want "${w[*]}"
            ((${#o[@]})) && queue_mac INV-BUBBLE page_text "$d" "$dir/states" "$PAGE_LANGS" not "${o[*]}"
        done
    fi
    return 0
}

# Ms. Moverelli's run card against the disks (a look while her run is at work): the percent as desk.js runCardData()
# computes it from her live answer (by bytes, the file in flight's .partial counted; else by files) against the bytes
# of the share in flight that really reached the other side since snap_before (a tracked share only) — more than 20
# points apart is a finding; and «On its way: file — from → to» (to: her state's share entry, else «array») against
# where the share's files really go (prefer: its pool; yes: the array)
_ck_tiles_moverelli() {
    local label=$1 dir=$2 prog p share card real0 real1 to want use pool
    # the share in flight from a first reading, the disks, her answer, the disks again: the card is judged against what
    # was surely across before her answer and what was across after it (files move while we look)
    share=$(_ck_mv_progress | jq -r '.share // empty' 2>/dev/null)
    [[ -n $share && -s $KEEP/before/inventory/$share.tsv ]] && real0=$(_ck_moved_pct "$share" "$dir/inventory-$share-0.tsv")
    prog=$(_ck_mv_state)
    [[ -n $prog ]] || return 0
    printf '%s\n' "$prog" >"$dir/states/moverelli-progress.json"
    [[ -n $share && -s $KEEP/before/inventory/$share.tsv ]] && real1=$(_ck_moved_pct "$share" "$dir/inventory-$share-1.tsv")
    p=$(jq -c '.progress // empty' <<<"$prog")
    [[ -n $p && $p != null && $(jq -r '.share // empty' <<<"$p") == "$share" ]] || return 0
    use=$(_ck_share_use "$share")
    pool=$(_ck_share_pool "$share")
    # what the card can show from her answer: its percent lies between the share's files % and bytes % (the file in
    # flight's .partial counted) — desk.js's runCardData(): time done once estimated, before that the larger of the two
    local lo hi
    read -r lo hi < <(jq -r 'if (.phase // "look") != "share" then "" else
        ((.share_bytes // 0) | tonumber) as $b | ((.share_files // 0) | tonumber) as $f |
        (((.share_done_bytes // 0) | tonumber) + ((.file_bytes // 0) | tonumber)) as $db | ((.share_done_files // 0) | tonumber) as $df |
        [(if $b > 0 then ([100, (100 * $db / $b | floor)] | min) else empty end), (if $f > 0 then ([100, (100 * $df / $f | floor)] | min) else empty end)]
        | if length == 0 then "" else "\(min) \(max)" end end' <<<"$p")
    printf 'share=%s card=%s–%s disks-before=%s disks-after=%s\n' "$share" "${lo:-none}" "${hi:-}" "${real0:-?}" "${real1:-?}" >>"$dir/run-card.txt"
    if [[ -n ${lo:-} && -n ${real0:-} && -n ${real1:-} ]] && ((hi < real0 - 20 || lo > real1 + 20)); then
        printf '%s\t%s\t%s\n' "$label" "moverelli run card" "its numbers give ${lo}–${hi} % for $share while ${real0}–${real1} % of its bytes were across (before / after her answer)" >>"$KEEP/.page-red"
        say "INV-PAGE ($label): the run card can show ${lo}–${hi} % for $share, the disks ${real0}–${real1} %"
    fi
    if [[ -n $(jq -r '.file // empty' <<<"$p") ]]; then
        to=$(jq -r --arg s "$share" '[.shares[]? | select(.share == $s) | .to][0] // empty' "$dir/states/moverelli.json" 2>/dev/null)
        to=${to:-array}
        case $use in prefer) want=${pool:-cache} ;; yes) want=array ;; *) want='' ;; esac
        if [[ -n $want && $to != "$want" ]]; then
            printf '%s\t%s\t%s\n' "$label" "moverelli on its way" "«On its way … → $to» for $share, whose files go to $want" >>"$KEEP/.page-red"
            say "INV-PAGE ($label): the file's way says → $to, $share's files go to $want"
        fi
    fi
}
# _ck_moved_pct <share> <file>: the share's inventory now (kept in file); the percent of the bytes that were on the side
# her run takes from at snap_before (prefer: the array; yes: the pool) and are on the other side now
_ck_moved_pct() {
    local use
    use=$(_ck_share_use "$1")
    inventory "$1" >"$2"
    awk -F'\t' -v pre="$use" 'function src(b) { return (pre == "prefer") ? (b ~ /^disk[0-9]+$/) : (b !~ /^disk[0-9]+$/) }
        NR == FNR { if ($0 !~ /^#/ && src($5)) { size[$1] = $2; total += $2 } next }
        ($0 !~ /^#/) && ($1 in size) && !src($5) { done += size[$1] }
        END { if (total > 0) printf "%d", 100 * done / total }' "$KEEP/before/inventory/$1.tsv" "$2"
}

# the last look after the flow — once per check run (INV-PAGE and INV-BUBBLE share it)
_ck_final_look() {
    [[ -f $KEEP/.final-look && ( ! -f $KEEP/.ask-at || $KEEP/.final-look -nt $KEEP/.ask-at ) ]] && return 0
    bubble_look final >/dev/null 2>&1
    touch "$KEEP/.final-look"
}
_ck_mac_n() { grep -c "^$1	" "${REPORT%.md}.pending" 2>/dev/null || echo 0; }

# INV-PAGE — the page says no «?», no raw key, no contradiction: built on the Mac from each look's state files
check_inv_page() {
    _ck_final_look
    if [[ -s $KEEP/.page-red ]]; then
        awk -F'\t' '{printf "%s%s (%s): %s", (NR>1?"; ":""), $2, $1, $3}' "$KEEP/.page-red" | head -c 500
        echo "; pending (mac): $(_ck_mac_n INV-PAGE) page looks"
        return 1
    fi
    echo "pending (mac): $(_ck_mac_n INV-PAGE) page looks queued ($(cat "$KEEP/.looks" 2>/dev/null || echo 0) looks)"
    return 2
}

# INV-BUBBLE — bubbles, greetings and tiles say what is true now: the greetings judged here, the rest on the Mac
check_inv_bubble() {
    _ck_final_look
    if [[ -s $KEEP/.bubble-red ]]; then         # a rule that doesn't evaluate
        awk -F'\t' '{printf "%s%s (%s): %s", (NR>1?"; ":""), $2, $1, $3}' "$KEEP/.bubble-red" | head -c 500
        echo
        return 1
    fi
    echo "pending (mac): $(_ck_mac_n INV-BUBBLE) page checks (greetings drawn once each)"
    return 2
}

# ------------------------------------------------------------------------------------------------------------- replan
# INV-REPLAN — a plan made anew without a change lists no change: Mr. Backupsy's Apply dialog list (plan-changes.mjs,
# desk.js's own code) = the base's known list (base/<host>/plan-changes.known) + expect_changes, nothing else; the
# plan's messages carry no «changes something» code unless announced. Ms. Moverelli's part (after one of her flows):
# her settings saved again without a change leave smart_mover.ini as it was.
_ck_known_changes() {
    local role=other f
    host_is tower && role=tower
    host_is test3 && role=test3
    f=$SC_DIR/base/$role/plan-changes.known
    [[ -f $f ]] && grep -v '^[[:space:]]*#' "$f" | sed '/^[[:space:]]*$/d'
}
_ck_change_ok() {   # <key>: in the known list or an expect_changes line (a trailing * matches the rest)
    local k=$1 p
    while IFS= read -r p; do
        [[ -z $p ]] && continue
        if [[ $p == *'*' ]]; then [[ $k == "${p%\*}"* ]] && return 0; else [[ $k == "$p" ]] && return 0; fi
    done < <(_ck_known_changes; cat "$KEEP/.expect-changes" 2>/dev/null)
    return 1
}
check_inv_replan() {
    local bad=() plan=$KEEP/after/replan.json keys k codes
    if _ck_is_hired moverelli && [[ -f $KEEP/.mv-touched && -f $D/moverelli/smart_mover.ini ]]; then
        local before s
        before=$(md5sum <"$D/moverelli/smart_mover.ini")
        s=$(php "$SC_DIR/ask.php" --wait 120 moverelli.refresh '{}' >/dev/null 2>&1; jq -c '.settings' "$D/moverelli.json")
        if php "$SC_DIR/ask.php" --wait 120 moverelli.save "$(jq -cn --argjson s "$s" '{settings: $s}')" 2>/dev/null | jq -e .ok >/dev/null; then
            [[ $(md5sum <"$D/moverelli/smart_mover.ini") == "$before" ]] || bad+=("Ms. Moverelli: saving her settings unchanged changed smart_mover.ini")
        else
            bad+=("Ms. Moverelli: saving her settings unchanged was refused")
        fi
    fi
    if ! _ck_is_hired backup || [[ ! -f $UB/settings.ini ]]; then
        ((${#bad[@]})) && { echo "${bad[*]}"; return 1; }
        echo "Mr. Backupsy not set up here"
        return 3
    fi
    lock_held "$UB/state/lock" && { echo "a backup run holds the engine — no plan now${bad:+; ${bad[*]}}"; ((${#bad[@]})) && return 1; return 2; }
    # «Look at the server again» — straight through ask.php (the check's own look, not one of the scenario's requests)
    _setup_done || WAIT_SOFT=1 wait_for 900 "a setup look to end" _setup_done >/dev/null 2>&1
    local t0 out
    t0=$(_sc_now)
    out=$(php "$SC_DIR/ask.php" --wait 120 backup.setup_plan '{"measure":false}' 2>/dev/null)
    jq -e .ok <<<"$out" >/dev/null 2>&1 || { echo "no plan now: setup_plan refused ($(jq -r '.error.key // "?"' <<<"$out"))${bad:+; ${bad[*]}}"; ((${#bad[@]})) && return 1; return 2; }
    sleep 2
    WAIT_SOFT=1 wait_for 900 "the replan to end" _setup_done >/dev/null 2>&1
    local i
    for ((i = 0; i < 5; i++)); do
        php "$SC_DIR/ask.php" --wait 120 backup.setup_get '{}' 2>/dev/null | jq '.plan // empty' >"$plan"
        jq -e '(.P | type) == "object"' "$plan" >/dev/null 2>&1 && break
        sleep 3
        WAIT_SOFT=1 wait_for 600 "the replan to end" _setup_done >/dev/null 2>&1
    done
    jq -e '(.P | type) == "object"' "$plan" >/dev/null 2>&1 || { echo "no plan from setup_get${bad:+; ${bad[*]}}"; return 1; }
    # the plan made for this check, not an older one (a refused or slow tour leaves the last plan in place)
    jq -e --argjson t "$t0" '(.time // 0) >= $t - 5' "$plan" >/dev/null 2>&1 ||
        { echo "no fresh plan (the newest is from $(jq -r '.time // 0' "$plan" | xargs -I{} date -d @{} +%T 2>/dev/null))${bad:+; ${bad[*]}}"; ((${#bad[@]})) && return 1; return 2; }
    cp "$UB/settings.ini" "$KEEP/after/replan-settings.ini"
    # the plan's messages: no code that says «something changed» unless the scenario announced a change
    codes=$(jq -r '[.messages[]?.code] | map(select(IN("app_gone","source_gone","dump_gone","partner_gone","settings_error","decision_invalid"))) | unique | join(",")' "$plan")
    [[ -n $codes && ! -s $KEEP/.expect-changes ]] && bad+=("plan messages: $codes")
    if command -v node >/dev/null 2>&1; then
        keys=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>"$KEEP/after/plan-changes.err" | jq -r '.[].key' 2>/dev/null)
        printf '%s\n' "$keys" >"$KEEP/after/plan-changes.txt"
        [[ -s $KEEP/after/plan-changes.err ]] && bad+=("plan-changes.mjs: $(head -c 160 "$KEEP/after/plan-changes.err")")
        local extra=() missing=()
        while IFS= read -r k; do [[ -n $k ]] && ! _ck_change_ok "$k" && extra+=("$k"); done <<<"$keys"
        while IFS= read -r k; do [[ -n $k ]] && ! grep -qxF -- "$k" <<<"$keys" && missing+=("$k"); done < <(cat "$KEEP/.expect-changes" 2>/dev/null | grep -v '\*$')
        ((${#extra[@]})) && bad+=("the Apply list names ${#extra[@]} change(s) nothing made: ${extra[*]:0:6}")
        ((${#missing[@]})) && bad+=("announced but not listed: ${missing[*]}")
        if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
        echo "the Apply list: $(grep -c . <<<"$keys") change(s), all known ($(_ck_known_changes | grep -c .) base, $(grep -c . "$KEEP/.expect-changes" 2>/dev/null || echo 0) announced)"
        return 0
    fi
    # no node here: the Mac makes the list (want = base known + announced; a * line can't be checked there)
    local want
    want=$( (_ck_known_changes; grep -v '\*$' "$KEEP/.expect-changes" 2>/dev/null) | sed '/^$/d' | jq -R . | jq -sc 'unique')
    ((${#bad[@]})) && { echo "${bad[*]}"; return 1; }
    queue_mac INV-REPLAN plan_changes "$plan" "$KEEP/after/replan-settings.ini" "$want"
    echo "pending (mac): no node on this server"
    return 2
}

# INV-STOPPED-KEEPS — whether something runs never changes what is saved: for every container stopped now, its saved
# keys stay in the plan as they are, a stopped Nextcloud is listed with running:false, its maintenance key kept
check_inv_stopped_keeps() {
    local plan=$KEEP/after/replan.json stopped bad=() n k
    has docker || { echo "Docker isn't running"; return 3; }
    stopped=$(docker ps -a --filter status=exited --filter status=created --format '{{.Names}}' 2>/dev/null)
    [[ -n $stopped ]] || { echo "nothing stopped"; return 3; }
    [[ -f $plan ]] || { echo "no replan to compare (INV-REPLAN made none)"; return 3; }
    local ini=$UB/settings.ini
    for n in $stopped; do
        # its [app "<name>"] keys in settings.ini: the plan proposes them unchanged
        while IFS='=' read -r k _; do
            [[ -n $k ]] || continue
            local saved prop
            saved=$(_ini_flat "$ini" | sed -n "s/^app|$n|$k=//p" | head -1)
            prop=$(jq -r --arg k "app|$n|$k" '.P[$k] // empty | if type == "array" then join(",") else tostring end' "$plan")
            [[ -z $prop || $prop == "$saved" ]] || bad+=("app|$n|$k: saved $saved, plan $prop")
        done < <(_ini_flat "$ini" | sed -n "s/^app|$n|\([^=]*\)=.*/\1=/p" | sort -u)
    done
    if grep -qix nextcloud <<<"$stopped"; then
        jq -e '[.nextcloud[]? | select(.running == false)] | length > 0' "$plan" >/dev/null ||
            bad+=("the plan doesn't list the stopped Nextcloud with running:false")
        if _ini_flat "$ini" | grep -q '^nextcloud|.*|preexisting_maintenance='; then
            jq -e '.P | keys | map(select(test("^nextcloud\\|.*\\|preexisting_maintenance$"))) | length > 0' "$plan" >/dev/null ||
                bad+=("preexisting_maintenance dropped from the plan")
        fi
    fi
    if ((${#bad[@]})); then echo "${bad[*]:0:4}"; return 1; fi
    echo "stopped: $(paste -sd' ' <<<"$stopped") — their saved keys kept"
}

# INV-SETUP-MSGS — no setup message whose state isn't there, each with its text
check_inv_setup_msgs() {
    local plan=$KEEP/after/replan.json bad=() code share en=$SC_TREE/public/desks/backup/lang/en.json
    [[ -f $plan ]] || plan=$UB/state/setup-plan.json
    [[ -f $plan ]] || { echo "no setup plan"; return 3; }
    while IFS=$'\t' read -r code share; do
        [[ -n $code ]] || continue
        jq -e --arg k "setup.msg.$code" 'has($k)' "$en" >/dev/null 2>&1 || bad+=("$code: no text setup.msg.$code")
        case $code in
            nextcloud_stopped) docker ps -a --filter status=exited --format '{{.Names}}' 2>/dev/null | grep -qix nextcloud || bad+=("nextcloud_stopped while Nextcloud runs") ;;
            docker_volumes) [[ -n $(docker volume ls -q 2>/dev/null) ]] || bad+=("docker_volumes without a volume") ;;
            vm_freeze_no_agent) [[ -n $(virsh list --all --name 2>/dev/null | sed '/^$/d') ]] || bad+=("vm_freeze_no_agent without a VM") ;;
            kopia_not_running) has kopia && bad+=("kopia_not_running while Kopia runs") ;;
            share_no_snapshots)
                if [[ -n $share ]]; then
                    local b fs snapless=0
                    for b in $(bases); do
                        [[ -d /mnt/$b/$share ]] || continue
                        fs=$(stat -f -c %T "/mnt/$b" 2>/dev/null)
                        [[ $fs == zfs || $fs == btrfs ]] || snapless=1
                    done
                    ((snapless)) || bad+=("share_no_snapshots for $share, whose parts all take snapshots")
                fi ;;
        esac
    done < <(jq -r '.messages[]? | [.code, (.params.share // "")] | @tsv' "$plan" 2>/dev/null)
    if ((${#bad[@]})); then echo "${bad[*]:0:4}"; return 1; fi
    echo "$(jq '[.messages[]?] | length' "$plan") message(s), each true and with its text"
}

# ------------------------------------------------------------------------------------------------------------ server
# INV-NC-MAINT-OFF — Nextcloud isn't left in maintenance mode (when it runs); no «stuck» notification when it is stopped
check_inv_nc_maint_off() {
    has docker || { echo "Docker isn't running"; return 3; }
    local nc out
    nc=$(docker ps -a --format '{{.Names}} {{.State}} {{.Image}}' 2>/dev/null | awk 'tolower($3) ~ /nextcloud/ && tolower($1) !~ /mariadb|redis|db/ {print $1, $2; exit}')
    [[ -n $nc ]] || { echo "no Nextcloud"; return 3; }
    if [[ ${nc#* } != running ]]; then
        local stuck
        stuck=$(comm -13 <(sort "$KEEP/before/notify.txt") <(sort "$KEEP/after/notify.txt") 2>/dev/null | while read -r f; do
            grep -l 'maintenance' "$NOTIFY_DIR"/{unread,archive}/"$f" 2>/dev/null; done | head -1)
        [[ -n $stuck ]] && { echo "a maintenance notification while ${nc% *} is stopped: $(basename "$stuck")"; return 1; }
        echo "${nc% *} is stopped — not asked"
        return 0
    fi
    out=$(timeout 60 docker exec -u www-data "${nc% *}" php occ maintenance:mode 2>&1)
    if grep -qi 'disabled' <<<"$out"; then echo "${nc% *}: maintenance mode off"; return 0; fi
    if grep -qi 'enabled' <<<"$out"; then
        # it was on before the scenario? (base: off) — the scenario may announce it with allow
        echo "${nc% *} is in maintenance mode"
        return 1
    fi
    echo "occ didn't answer: ${out:0:120}"
    return 2
}

# INV-MOUNTS-GONE — no snapshot left mounted after a run (unless keep_mounts = yes)
check_inv_mounts_gone() {
    local m
    m=$(grep -c ' /mnt/addons/UnraidSecretaryOffice/\(snapshots\|btrfs-snap\)/' /proc/mounts)
    if ((m > 0)); then
        grep -qs '^keep_mounts *= *yes' "$UB/settings.ini" && { echo "$m mounted — keep_mounts = yes"; return 0; }
        echo "$m snapshot mount(s) left: $(grep ' /mnt/addons/UnraidSecretaryOffice/' /proc/mounts | awk '{print $2}' | head -2 | paste -sd' ' -)"
        return 1
    fi
    echo "none mounted"
}

# INV-NO-MNT-OPEN — nothing of the office's holds a file or folder under /mnt open (the array could not stop):
# the agent, its children and php-fpm — cwd and open descriptors
check_inv_no_mnt_open() {
    local pids=() p bad=() x
    p=$(jq -r '.pid // empty' "$RUN/agent.json" 2>/dev/null)
    [[ -n $p ]] && pids+=("$p" $(pgrep -P "$p" 2>/dev/null))
    pids+=($(pgrep -f '[n]ightshift|agent\.php night' 2>/dev/null))
    for p in "${pids[@]}"; do
        [[ -d /proc/$p ]] || continue
        x=$(readlink "/proc/$p/cwd" 2>/dev/null)
        [[ $x == /mnt/* ]] && bad+=("pid $p ($(cat /proc/$p/comm)) cwd $x")
        x=$(ls -l "/proc/$p/fd" 2>/dev/null | awk '{print $NF}' | grep '^/mnt/' | grep -v '/mnt/addons/' | head -2)
        [[ -n $x ]] && bad+=("pid $p ($(cat /proc/$p/comm)) holds ${x//$'\n'/ }")
    done
    if [[ -f $KEEP/.of-array ]]; then
        grep -q '^rc=1' "$KEEP/.of-array" && bad+=("before the array stop: $(head -1 "$KEEP/.of-array")")
        x=$(sed -n 's/^stop_seconds=//p' "$KEEP/.of-array")
        [[ -n $x ]] && ((x > 120)) && bad+=("the array stop took $x s")
    fi
    if ((${#bad[@]})); then echo "${bad[*]:0:3}"; return 1; fi
    echo "nothing open under /mnt (${#pids[@]} processes looked at)"
}

# INV-COMPARE — what came back is what lay in the moment (diff -r, owner and mode the same) — compared by the restore
# flow the moment its job ended (flows.sh _rs_compare: an app started again changes its files afterwards)
check_inv_compare() {
    [[ -s $KEEP/compare.tsv ]] || { echo "nothing restored"; return 3; }
    local bad n
    n=$(grep -c . "$KEEP/compare.tsv")
    bad=$(awk -F'\t' '$4 != "ok" {printf "%s%s: %s", (c++ ? "; " : ""), $3, $4}' "$KEEP/compare.tsv")
    [[ -z $bad ]] || { echo "${bad:0:400}"; return 1; }
    echo "$n restored = the moment's content"
}

# INV-MIGRATE-IDEMPOTENT — a second update changes nothing: OF-UPDATE's command again, then no new migration line, no
# new *.before-* file, the same version
check_inv_migrate_idempotent() {
    [[ -f $KEEP/.of-update ]] || { echo "no update in this scenario"; return 3; }
    local cmd v1 v2 mig before
    cmd=$(cat "$KEEP/.of-update")
    v1=$(_of_versions)
    agent_log_mark second-update
    touch "$KEEP/.second-update"
    agent_mark_restart >/dev/null
    _st_clean bash -c "$cmd" >"$KEEP/of-second-update.log" 2>&1
    # nothing newer: the plugin manager installs nothing and the agent goes on (no restart awaited — softly)
    WAIT_SOFT=1 wait_for 60 "a new agent after the second update" _agent_ready "$(cat "$KEEP/.restart-from" 2>/dev/null)" >/dev/null 2>&1
    sleep 5
    v2=$(_of_versions)
    mig=$(agent_log_since second-update | grep -E '→|Migrat' | head -2)
    before=$(find "$D" -name '*.before-*' -newer "$KEEP/.second-update" 2>/dev/null | head -2)
    local bad=()
    [[ $v1 == "$v2" ]] || bad+=("version $v1 → $v2")
    [[ -n $mig ]] && bad+=("migration again: ${mig//$'\n'/ | }")
    [[ -n $before ]] && bad+=("new ${before//$'\n'/ }")
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "the second update changed nothing ($v2)"
}

# ------------------------------------------------------------------------------------------------------------ counts
# INV-COUNTS, Mr. Restori's part — his packages = the place's folders with a manifest
check_inv_counts_restore() {
    local rj=$D/restore.json share on_disk in_state
    _ck_is_hired restore || { echo "not hired"; return 3; }
    [[ ${COUNTS_NO_LOOK:-} ]] || php "$SC_DIR/ask.php" --wait 300 restore.refresh '{}' >/dev/null 2>&1
    [[ -f $rj ]] || { echo "no restore.json"; return 3; }
    [[ -n $PLACE ]] || { echo "no backup place"; return 3; }
    share=${PLACE#/mnt/user/}; share=${share%%/*}
    share_asleep "$share" && { echo "the place's share sleeps"; return 3; }
    local kind bad=()
    for kind in apps vms; do
        on_disk=$(find "$PLACE/$kind" -mindepth 2 -maxdepth 2 -name manifest.json 2>/dev/null | wc -l)
        in_state=$(jq "(.$kind // []) | length" "$rj")
        [[ $on_disk == "$in_state" ]] || bad+=("$kind: place $on_disk ≠ page $in_state")
    done
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "apps $(jq '(.apps // []) | length' "$rj"), vms $(jq '(.vms // []) | length' "$rj") as on the place"
}

# INV-COUNTS, Ms. Moverelli's part — after a real run in this scenario: what her last run says it moved per share =
# the tracked shares' files whose disk changed (the inventories before/after)
check_inv_counts_moverelli() {
    local mj=$D/moverelli.json s moved_fs moved_her bad=() n=0
    _ck_is_hired moverelli || { echo "not hired"; return 3; }
    [[ -f $KEEP/.mv-touched && -s $KEEP/.shares ]] || { echo "no run of hers with a tracked share"; return 3; }
    local started
    started=$(jq -r '.last.started // 0' "$mj" 2>/dev/null)
    [[ $started =~ ^[0-9]+$ ]] && ((started >= SC_START)) && [[ $(jq -r '.last.mode // ""' "$mj") == run ]] ||
        { echo "no real run of hers in this scenario"; return 3; }
    while IFS= read -r s; do
        # the files of the share whose disk or pool changed between before and after
        moved_fs=$(awk -F'\t' 'NR == FNR { if ($0 !~ /^#/) at[$1] = $5; next } ($0 !~ /^#/) && ($1 in at) && at[$1] != $5 { n++ } END { print n + 0 }' \
            "$KEEP/before/inventory/$s.tsv" "$KEEP/after/inventory/$s.tsv")
        moved_her=$(jq -r --arg s "$s" '[.last.shares[]? | select(.share == $s) | (.moved // 0)] | add // empty' "$mj")
        [[ -n $moved_her ]] || continue
        n=$((n + 1))
        [[ $moved_fs == "$moved_her" ]] || bad+=("$s: she says $moved_her moved, the disks show $moved_fs")
    done <"$KEEP/.shares"
    ((n)) || { echo "her last run names no tracked share"; return 3; }
    if ((${#bad[@]})); then echo "${bad[*]}"; return 1; fi
    echo "$n share(s): her counts = the disks"
}

# ------------------------------------------------------------------------------------------------------------- final
# INV-BACKUP-AFTER — a backup now, with the state as the user lives with it: ok, or only the warnings announced
check_inv_backup_after() {
    _ck_is_hired backup && [[ -f $UB/settings.ini ]] || { echo "Mr. Backupsy not set up"; return 3; }
    minute_wait >/dev/null 2>&1
    local out st=$UB/state/status.json r w e codes unexpected=()
    out=$(php "$SC_DIR/ask.php" --wait 120 backup.start '{"mode":"backup"}' 2>/dev/null)
    jq -e '.ok' <<<"$out" >/dev/null 2>&1 || { echo "the start was refused: $(jq -r '.error.key // "?"' <<<"$out")"; return 1; }
    printf '%s 0\n' "$(_sc_now)" >"$KEEP/.ask-at"
    wait_backup 7200 >/dev/null 2>&1 || { echo "the run did not end"; return 1; }
    r=$(jq -r '.result // ""' "$st"); w=$(jq -r '.warnings // 0' "$st"); e=$(jq -r '.errors // 0' "$st")
    evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" backup-after.log >/dev/null 2>&1
    [[ $r == ok ]] && { echo "ok"; return 0; }
    if [[ $r == warnings && $e == 0 ]]; then
        codes=$(_ck_warning_codes)
        local c
        for c in $codes; do grep -qxF -- "$c" "$KEEP/.expect-warnings" 2>/dev/null || unexpected+=("$c"); done
        ((${#unexpected[@]})) || { echo "warnings as announced: $(paste -sd' ' <<<"$codes")"; return 0; }
        echo "$w warning(s), not announced: ${unexpected[*]:0:5}"
        return 1
    fi
    echo "result $r, errors $e, warnings $w: $(jq -r '.message // ""' "$st")"
    return 1
}
# the codes of the last run's warnings: the engine's drift codes, its coded warnings (warning_items, engine 2.42) and its
# WARNING lines' first words (a check may read the log — the office itself never does)
_ck_warning_codes() {
    local st=$UB/state/status.json log
    {
        jq -r '[.drift[]?, .differences[]?, .warning_items[]?] | .[] | objects | (.code // empty)' "$st" 2>/dev/null   # (.drift is counts)
        log=$UB/logs/run-$(jq -r '.run // ""' "$st").log
        grep -oE 'WARNING[: ]+\[?[a-z_]+' "$log" 2>/dev/null | sed -E 's/WARNING[: ]+\[?//'
    } | sort -u
}

# INV-DRILL-GREEN — Mr. Restori's drill passes (steps ok or not checked, each with its reason, never failed); no drill
# leftovers after it
check_inv_drill_green() {
    _ck_is_hired restore || { echo "Mr. Restori not hired"; return 3; }
    local out stamp token r
    out=$(php "$SC_DIR/ask.php" --wait 300 restore.drill_plan '{}' 2>/dev/null)
    jq -e '.ok' <<<"$out" >/dev/null 2>&1 || { echo "drill_plan refused: $(jq -r '.error.key // "?"' <<<"$out")"; return 1; }
    if [[ $(jq '(.preview.blockers // []) | length' <<<"$out") != 0 ]]; then
        # nothing to practise on (no package, no Kopia snapshot yet): not applicable, not a failed drill
        [[ $(jq -r '.preview.blockers[0].key' <<<"$out") == drill_nothing ]] && { echo "nothing to practise on yet (drill_nothing)"; return 3; }
        echo "the drill is blocked: $(jq -c '[.preview.blockers[].key]' <<<"$out")"
        return 1
    fi
    stamp=$(jq -r .preview.stamp <<<"$out"); token=$(jq -r .preview.token <<<"$out")
    local t0
    t0=$(_sc_now)
    out=$(php "$SC_DIR/ask.php" --wait 300 restore.drill_start "$(jq -cn --arg s "$stamp" --arg t "$token" '{stamp: $s, token: $t}')" 2>/dev/null)
    jq -e '.ok' <<<"$out" >/dev/null 2>&1 || { echo "drill_start refused: $(jq -r '.error.key // "?"' <<<"$out")"; return 1; }
    local id
    id=$(jq -r '.id // empty' <<<"$out")
    WAIT_SOFT=1 wait_for 120 "the drill to begin" _drill_begun "$t0" >/dev/null 2>&1
    wait_for 7200 "the drill" _drill_done >/dev/null 2>&1 || { echo "the drill did not end"; return 1; }
    evidence "$D/restore-drill/$id/log.txt" drill-log.txt >/dev/null 2>&1
    r=$(_drill_result "$id")
    [[ $r == passed* ]] || { echo "drill $id: $r"; return 1; }
    local left
    left=$(check_inv_leftover)
    (($? == 0)) || { echo "drill $r, but leftovers: $left"; return 1; }
    echo "drill $r"
}
