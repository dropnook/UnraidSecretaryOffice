#!/bin/bash
# tools/scenarios/flows.sh — the flows of resilienz/ablaeufe.md (H5, wave 1b). Sourced by lib.sh; a scenario says
# `flow <ID> [key=value…]` (lib.sh → flow_<id>). Each flow does exactly what the page's button does: the request body is
# the one public/desks/<desk>/desk.js posts (the line is named at each flow), sent through `ask` (the plugin's askAgent())
# — never a desk function, never the browser. A cron flow goes through the plugin's own scripts/job.sh as crond starts
# it. Each flow waits with lib.sh's waiters until its work has ended and returns 0 when it ran to its end the way the
# page would call done, 1 otherwise (a refusal included). `refuse=ok`: a refusal is the expected end (the scenario's
# `expect_err <key>` then checks the key) — the flow returns 0 on it.
#
#   Mr. Backupsy   BK-FIRST-SETUP [measure=true] [preset=auto|local|kopia|partner|kopia_partner] [key=value…]
#                  BK-REPLAN [measure=true]      BK-APPLY [plan=old] [key=value…]      BK-DECLINE key= [value=]  BK-DECLINE-UNDO key=
#                  BK-RUN-MANUAL mode=backup|nokopia|dryrun|check     BK-RUN-SCHED     BK-RUN-QUEUED
#                  BK-ABORT [after=s]    BK-UNMOUNT    BK-SCHEDULE cron='30 4 * * *'|cron=    BK-ORPHANS [delete=src,…|all]
#                  BK-PACKAGE-ASIDE kind=app|vm folder=    BK-DATASET-MAKE kind= name= share= dir= [stack=] [no_fresh_ok=1]
#                  BK-LETGO [clear=yes]     BK-FORGET
#   Mr. Restori    RS-SCAN   RS-FILES mode=beside|replace path=/mnt/user/<share>/<dir> [moment=<snap>] [wake=1]
#                  RS-FILES-BESIDE / RS-FILES-REPLACE (the same, mode set)   RS-DB app= file= [version=]
#                  RS-SQLITE app= container= [version=]   RS-CONFIG app= [version=]   RS-VM vm= [version=]
#                  RS-KOPIA source= [snapshot=]   RS-PUTBACK of=<journal id>|last   RS-DRILL (= RS-DRILL-MANUAL)
#   Ms. Moverelli  MV-SETUP [x=80] [mover_off=1] [rule=<share>:<days>…]   MV-RESAVE   MV-LIST   MV-DRY   MV-RUN [look=s]
#                  MV-RUN-SCHED   MV-STOP-AFTER-FILE (= MV-NIGHT-STOP)   MV-SCHEDULE cron=…|cron=   MV-MOVER-OFF
#                  MV-IMPORT folder=   MV-LETGO
#   The office     OF-UPDATE plg=<file|url>|worktree   OF-REMOVE-REINSTALL [plg=]   OF-ARRAY-STOP-START
#                  OF-REBOOT (the pre half: notes for the post half, the runner reboots)   OF-REBOOT-BACK (the post half)
#                  OF-AGENT-RESTART   OF-HIRE desk=   OF-FIRE desk=
#
# Helpers for scenarios and checks: decisions_from_plan <plan.json> [key=value…] (Apply's body as desk.js makes it — node
# with plan-changes.mjs --apply), job <name> (scripts/job.sh as crond runs it, waits for its end), bubble_look [desk…]
# (the bubbles and tiles looked at now — checks.sh; flows call it while a run is at work), fl_last_restore (the id of
# the scenario's last restore job).
# Files a flow leaves for the checks ($KEEP): flow-<n>.json (what ran), compare.tsv (INV-COMPARE: snapshot source ·
# target), .mv-touched (a Moverelli flow ran: INV-REPLAN's part of hers), .of-update (OF-UPDATE's command, for
# INV-MIGRATE-IDEMPOTENT), .of-array (OF-ARRAY-STOP-START's INV-NO-MNT-OPEN look before the stop).

# ------------------------------------------------------------------------------------------------------------- helpers
# _fl_args key=value…: into the associative array FL (a key twice: the last; `rule` and bare keys collect in FL_LIST)
declare -A FL=()
FL_LIST=()
FL_KV=()
_fl_args() {
    FL=() FL_LIST=() FL_KV=()
    local a
    for a in "$@"; do
        if [[ $a =~ ^(refuse|measure|preset|plan|mode|after|cron|delete|kind|folder|name|share|dir|stack|no_fresh_ok|clear|path|moment|wake|app|file|version|container|vm|source|snapshot|of|x|mover_off|look|plg|desk|key|value)=(.*)$ ]]; then
            FL[${BASH_REMATCH[1]}]=${BASH_REMATCH[2]}
        elif [[ $a == rule=* ]]; then
            FL_LIST+=("${a#rule=}")
        elif [[ $a == *=* ]]; then
            FL_KV+=("$a")              # a settings key for Apply (share|media|mode=kopia)
        else
            say "flow: «$a» is not key=value"
            return 1
        fi
    done
}

# _fl_ask <desk.action> <json> [timeout]: ask; a refusal ends the flow (0 with refuse=ok, else 1). Prints the answer.
_fl_ask() {
    local out
    if out=$(ask "$@"); then
        printf '%s\n' "$out"
        return 0
    fi
    if [[ ${FL[refuse]:-} == ok ]]; then
        say "flow: refused as allowed (refuse=ok): $(ask_last '.error.key // "?"')"
        printf '%s\n' "$out"
        return 3                       # the caller ends the flow with 0
    fi
    return 1
}
# the end of a flow after _fl_ask: 0 ran, 3 refused as allowed → the flow returns 0 now (and stops)
_fl_end() { (($1 == 3)) && return 0; return "$1"; }

# _fl_note <what> <json>: a line of what this flow did, for the checks ($KEEP/flows.jsonl)
_fl_note() { jq -cn --arg w "$1" --argjson j "${2:-null}" --arg t "$(date +%s)" '{what: $w, at: ($t|tonumber), data: $j}' >>"$KEEP/flows.jsonl"; }

_fl_node() { command -v node >/dev/null 2>&1; }

# decisions_from_plan <plan.json> [key=value…]: the body backup.setup_apply gets from the page (desk.js line ~2624:
# {decisions: setupDecisions(), plan_time}) when the user opens the setup on this plan and changes these keys
decisions_from_plan() {
    local plan=$1
    shift
    _fl_node || { say "decisions_from_plan: no node on this server — desk.js can't make Apply's body here"; return 1; }
    node "$SC_DIR/plan-changes.mjs" "$plan" --apply "$@"
}

# the page's look at the setup: backup.setup_get (desk.js ~2406) → its plan (with declined and migrated, as the page has it)
_bk_plan_get() {
    local out=$1 i
    for ((i = 0; i < 6; i++)); do
        ask backup.setup_get '{}' 120 >/dev/null || return 1
        ask_last '.plan // empty' >"$out"
        jq -e '(.P | type) == "object" and .O != null' "$out" >/dev/null 2>&1 && return 0
        # a fresh data folder: the first look starts a tour and answers without a plan — once that tour is done, again
        say "setup_get: no plan yet — waiting for the tour"
        sleep 3
        wait_setup 600 || return 1
    done
    return 1
}

# job <name> [timeout]: the plugin's cron job as crond runs it (scripts/job.sh <name>, no lock descriptor handed down),
# waited for until it ended
job() {
    local name=$1 s=${2:-7200} pid
    [[ -x $P/scripts/job.sh || -f $P/scripts/job.sh ]] || { say "job: no $P/scripts/job.sh"; return 1; }
    # job.sh starts a job once a minute: a start in a minute that had one already ends quietly
    if [[ $(cat "$RUN/job-$name.minute" 2>/dev/null) == "$(date +%Y%m%d%H%M)" ]]; then
        say "job $name: started this minute already — waiting for the next"
        wait_for 70 "a new minute for job $name" bash -c "[[ \$(cat '$RUN/job-$name.minute' 2>/dev/null) != \$(date +%Y%m%d%H%M) ]]" || return 1
    fi
    printf '%s 0\n' "$(_sc_now)" >"$KEEP/.ask-at"        # the waiters' «started since»: since now
    pid=$(_st_bg bash "$P/scripts/job.sh" "$name")
    say "job $name: scripts/job.sh started as crond would (pid $pid)"
    printf '%s\n' "$pid" >"$KEEP/.job-$name.pid"
    _fl_note "job $name" "$(jq -cn --arg p "$pid" '{pid: $p}')"
    return 0
}
_job_gone() { ! kill -0 "$1" 2>/dev/null; }
job_wait() {
    local name=$1 s=${2:-7200} pid
    pid=$(cat "$KEEP/.job-$name.pid" 2>/dev/null) || return 0
    wait_for "$s" "job $name (pid $pid) to end" _job_gone "$pid" || return 1
    rm -f "$KEEP/.job-$name.pid"
}

# ============================================================================================================ Mr. Backupsy
# BK-FIRST-SETUP: a plan (desk.js ~2463 {measure}), the page's look at it, Apply with every key of the page's draft
# (~2624), waited for. preset=<kind>: the user picked that default «for everything» (base.sh --make's way).
flow_bk_first_setup() {
    _fl_args "$@" || return 1
    local plan=$KEEP/plan-first.json body rc
    _fl_ask backup.setup_plan "$(jq -cn --arg m "${FL[measure]:-}" '{measure: ($m == "true" or $m == "1")}')" 120 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_setup 900 || return 1
    _bk_plan_get "$plan" || { say "BK-FIRST-SETUP: no plan from setup_get"; return 1; }
    if [[ -n ${FL[preset]:-} ]]; then
        _fl_node || { say "BK-FIRST-SETUP: no node here"; return 1; }
        body=$(node "$SC_DIR/plan-changes.mjs" "$plan" --decisions "${FL[preset]}") || return 1
        ((${#FL_KV[@]})) && say "BK-FIRST-SETUP: with preset= the overrides ${FL_KV[*]} are not applied (one or the other)"
    else
        body=$(decisions_from_plan "$plan" "${FL_KV[@]}") || return 1
    fi
    _bk_apply_body "$body"
}

# Apply's request and its end (setup-status.json mode=apply, result ok|warnings)
_bk_apply_body() {
    local body=$1 rc
    printf '%s\n' "$body" >"$KEEP/apply-body.json"
    _fl_note apply "$(jq -c '{keys: (.decisions | length), plan_time}' <<<"$body")"
    _fl_ask backup.setup_apply "$body" 120 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_setup 1800 || return 1
    local st=$UB/state/setup-status.json
    if ! jq -e '.mode == "apply" and (.result == "ok" or .result == "warnings")' "$st" >/dev/null 2>&1; then
        say "Apply ended: $(jq -c '{mode, result, errors}' "$st" 2>/dev/null)"
        return 1
    fi
}

# BK-REPLAN: «Look at the server again» (desk.js ~2463 setup_plan {measure:false}), waited for; the plan kept for the checks
flow_bk_replan() {
    _fl_args "$@" || return 1
    local rc
    _fl_ask backup.setup_plan "$(jq -cn --arg m "${FL[measure]:-}" '{measure: ($m == "true" or $m == "1")}')" 120 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_setup 900 || return 1
    _bk_plan_get "$KEEP/plan-replan.json" || return 1
    _fl_note replan "$(jq -c '{time, messages: [.messages[]?.code]}' "$KEEP/plan-replan.json")"
}

# BK-APPLY [plan=old] key=value…: the setup open on a fresh plan (plan=old: on the plan as it is, made before — the page
# left open), the keys changed, Apply (desk.js ~2624)
flow_bk_apply() {
    _fl_args "$@" || return 1
    local plan=$KEEP/plan-apply.json body rc
    if [[ ${FL[plan]:-} != old ]]; then
        _fl_ask backup.setup_plan '{"measure":false}' 120 >/dev/null
        rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
        wait_setup 900 || return 1
    fi
    _bk_plan_get "$plan" || return 1
    body=$(decisions_from_plan "$plan" "${FL_KV[@]}") || return 1
    _bk_apply_body "$body"
}

# BK-DECLINE key= [value=]: «No, thanks» at a proposal (desk.js ~2349 {key, value: proposed|null})
flow_bk_decline() {
    _fl_args "$@" || return 1
    [[ -n ${FL[key]:-} ]] || { say "BK-DECLINE: key= missing"; return 1; }
    local rc value
    if [[ -n ${FL[value]+1} ]]; then
        value=$(jq -cn --arg v "${FL[value]}" '$v')
    else
        # the page sends what the plan proposes for that key (null when it proposes nothing)
        _bk_plan_get "$KEEP/plan-decline.json" || return 1
        value=$(jq -c --arg k "${FL[key]}" '.P[$k] // null' "$KEEP/plan-decline.json")
    fi
    _fl_ask backup.setup_decline "$(jq -cn --arg k "${FL[key]}" --argjson v "$value" '{key: $k, value: $v}')" >/dev/null
    rc=$?; _fl_end $rc
}

# BK-DECLINE-UNDO key=: «Propose it again» (desk.js ~2380 setup_decline_undo {key})
flow_bk_decline_undo() {
    _fl_args "$@" || return 1
    local rc
    _fl_ask backup.setup_decline_undo "$(jq -cn --arg k "${FL[key]:-}" '{key: $k}')" >/dev/null
    rc=$?; _fl_end $rc
}

# BK-RUN-MANUAL mode=…: «Back up now» (desk.js ~1946 start {mode}) — one real run a minute: the minute waited for first
flow_bk_run_manual() {
    _fl_args "$@" || return 1
    local mode=${FL[mode]:-backup} rc
    [[ $mode =~ ^(backup|nokopia|dryrun|check)$ ]] || { say "BK-RUN-MANUAL: mode backup|nokopia|dryrun|check"; return 1; }
    minute_wait || return 1
    _fl_ask backup.start "$(jq -cn --arg m "$mode" '{mode: $m}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    if [[ $(ask_last '.skipped // empty') != "" ]]; then
        say "BK-RUN-MANUAL: the engine skipped the run: $(ask_last '.skipped | tostring')"
        return 1
    fi
    [[ -n $(ask_last '.queued // empty') ]] && say "BK-RUN-MANUAL: queued for $(ask_last '.queued.at')"
    _fl_note "backup.start $mode" "$(ask_last '{started, queued, skipped}')"
    _bk_run_wait_with_look
}

# a run waited for — with a look at the bubbles while it is at work (INV-BUBBLE: «running» lines only while it runs)
_bk_run_wait_with_look() {
    if WAIT_SOFT=1 wait_for 120 "the backup run to be at work" lock_held "$UB/state/lock"; then
        sleep "${FL[look]:-5}"
        lock_held "$UB/state/lock" && bubble_look mid-backup backup
    fi
    wait_backup 7200 || return 1
    local r
    r=$(jq -r '.result // empty' "$UB/state/status.json" 2>/dev/null)
    [[ $r =~ ^(ok|warnings|errors)$ ]] || { say "the run ended $r"; return 1; }
}

# BK-RUN-SCHED: the plugin's cron line's job (scripts/job.sh backup), as crond starts it at its time
flow_bk_run_sched() {
    _fl_args "$@" || return 1
    minute_wait || return 1
    job backup || return 1
    _bk_run_wait_with_look || return 1
    job_wait backup 600
}

# BK-RUN-QUEUED: «Back up now» again in the minute of a run that just ended — one real run a minute, so the page
# queues it for the next (desk.js ~1946 → queued {at}); then «Don't start it» (desk.js ~475 start_cancel). A start while
# a run is still at work is refused (backup_running), not queued: the first run must end within its minute.
flow_bk_run_queued() {
    _fl_args "$@" || return 1
    local i run=''
    for ((i = 0; i < 3; i++)); do
        minute_wait || return 1
        ask backup.start '{"mode":"backup"}' >/dev/null || return 1
        wait_backup 3600 || return 1
        run=$(jq -r '.run // ""' "$UB/state/status.json")
        [[ $run == "$(date +%Y%m%d-%H%M)" ]] && break
        say "BK-RUN-QUEUED: the run outlasted its minute — again"
        run=''
    done
    [[ -n $run ]] || { say "BK-RUN-QUEUED: no run ended within its minute (3 tries)"; return 1; }
    ask backup.start '{"mode":"backup"}' >/dev/null || return 1
    if [[ -z $(ask_last '.queued // empty') ]]; then
        say "BK-RUN-QUEUED: a start in the run's minute was not queued: $(ask_last '{started, queued, skipped} | tostring')"
        return 1
    fi
    _fl_note queued "$(ask_last '.queued')"
    ask backup.start_cancel '{}' >/dev/null || return 1
    wait_for 90 "the queued minute to pass" bash -c "[[ \$(date +%Y%m%d-%H%M) > $run ]] && (( \$(date +%-S) > 20 ))" || return 1
    [[ $(jq -r '.run // ""' "$UB/state/status.json") == "$run" ]] || { say "BK-RUN-QUEUED: a run started although it was cancelled"; return 1; }
}

# BK-ABORT [after=s]: a run started, then «Stop the run» (desk.js ~1975 abort {}) once it is at work (+after s)
flow_bk_abort() {
    _fl_args "$@" || return 1
    minute_wait || return 1
    ask backup.start '{"mode":"backup"}' >/dev/null || return 1
    wait_for 120 "the backup run to be at work" lock_held "$UB/state/lock" || return 1
    sleep "${FL[after]:-5}"
    local rc
    _fl_ask backup.abort '{}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_backup 3600 || return 1
    local r
    r=$(jq -r '.result // empty' "$UB/state/status.json")
    _fl_note abort "$(jq -c '{result, message}' "$UB/state/status.json")"
    [[ $r == aborted ]] || { say "BK-ABORT: the run ended $r, not aborted"; return 1; }
}

# BK-UNMOUNT: «Unmount the snapshots» (desk.js ~2012 unmount {})
flow_bk_unmount() { _fl_args "$@" || return 1; local rc; _fl_ask backup.unmount '{}' >/dev/null; rc=$?; _fl_end $rc; }

# BK-SCHEDULE cron=…: the schedule dialog's Save (desk.js ~5932 schedule {cron}); cron= (empty) = off
flow_bk_schedule() {
    _fl_args "$@" || return 1
    local rc
    _fl_ask backup.schedule "$(jq -cn --arg c "${FL[cron]-}" '{cron: $c}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    [[ $(ask_last '.live // empty') == false ]] && say "BK-SCHEDULE: saved, but not live (the page says schedule.not_live)"
    return 0
}

# BK-ORPHANS [delete=src,…|all]: «Old offsite sources…» (desk.js ~5673 orphans_look {}, ~5649 orphans_get {} while it
# looks), then «Delete» with the ticked sources and the list's time (~5738 orphans_delete {sources, listed})
flow_bk_orphans() {
    _fl_args "$@" || return 1
    local rc o sources started
    _fl_ask backup.orphans_look '{}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_orphans 1800 || return 1
    o=$(ask_last '.orphans')
    printf '%s\n' "$o" >"$KEEP/orphans-list.json"
    _fl_note orphans "$(jq -c '{n: ((.sources // .list // []) | length), started}' <<<"$o" 2>/dev/null || echo null)"
    [[ -n ${FL[delete]:-} ]] || return 0
    started=$(jq -r '.started // empty' <<<"$o")
    if [[ ${FL[delete]} == all ]]; then
        sources=$(jq -c '[(.sources // .list // [])[] | (.source // .id // .name // .)] | map(select(type == "string"))' <<<"$o")
    else
        sources=$(jq -cn --arg s "${FL[delete]}" '$s | split(",")')
    fi
    [[ $sources != '[]' ]] || { say "BK-ORPHANS: nothing to delete"; return 0; }
    _fl_ask backup.orphans_delete "$(jq -cn --argjson s "$sources" --arg l "$started" '{sources: $s, listed: $l}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    wait_orphans 1800
}

# BK-PACKAGE-ASIDE kind= folder=: a gone app's or VM's package into the storeroom (desk.js ~1423 {kind, folder})
flow_bk_package_aside() {
    _fl_args "$@" || return 1
    local rc
    _fl_ask backup.package_aside "$(jq -cn --arg k "${FL[kind]:-app}" --arg f "${FL[folder]:-${FL[name]:-}}" '{kind: $k, folder: $f}')" >/dev/null
    rc=$?; _fl_end $rc
}

# BK-DATASET-MAKE kind= name= share= dir= [stack=] [no_fresh_ok=1]: «Make it a dataset of its own…» (desk.js ~4882
# dataset_look <target>, ~4934 dataset_make {…target, no_fresh_ok}) — Mr. Restori's job, waited for
flow_bk_dataset_make() {
    _fl_args "$@" || return 1
    local target rc id
    target=$(jq -cn --arg k "${FL[kind]:-app}" --arg n "${FL[name]:-}" --arg s "${FL[share]:-}" --arg d "${FL[dir]:-}" --arg st "${FL[stack]:-}" \
        '{kind: $k, name: $n, share: $s, dir: $d} + (if $st != "" then {stack: $st} else {} end)')
    _fl_ask backup.dataset_look "$target" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    if [[ $(ask_last '[.blockers[]? | select(.key != "md_stack_running")] | length') != 0 ]]; then
        say "BK-DATASET-MAKE: blocked: $(ask_last '[.blockers[].key] | join(",")')"
        [[ ${FL[refuse]:-} == ok ]] && return 0
        return 1
    fi
    local fresh
    fresh=$(ask_last '.last.fresh // false')
    _fl_ask backup.dataset_make "$(jq -c --argjson nf "$([[ $fresh != true && ${FL[no_fresh_ok]:-} =~ ^(1|true|yes)$ ]] && echo true || echo false)" '. + {no_fresh_ok: $nf}' <<<"$target")" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    id=$(ask_last '.id // empty')
    printf '%s\n' "$id" >"$KEEP/.rs-last"
    _fl_note dataset_make "$(jq -cn --arg i "$id" --argjson t "$target" '{id: $i, target: $t}')"
    local r
    r=$(wait_restore "$id" 3600) || return 1
    [[ $r =~ ^(ok|warnings)$ ]] || { say "BK-DATASET-MAKE: the job ended $r"; return 1; }
}

# BK-LETGO [clear=yes]: the let-go dialog (desk.js ~5986 letgo_look {}; with «also clear away» ~5997 letgo_clear
# {confirm:true} and its job followed (~6032)), then «Let go» — office.fire {desk} (core.js ~1969)
flow_bk_letgo() {
    _fl_args "$@" || return 1
    local rc
    _fl_ask backup.letgo_look '{}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    [[ $(ask_last '.busy // false') == true ]] && { say "BK-LETGO: busy — the page says so and waits"; return 1; }
    if [[ ${FL[clear]:-} =~ ^(1|yes|true)$ ]]; then
        _fl_ask backup.letgo_clear '{"confirm":true}' >/dev/null
        rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
        wait_letgo 3600 || return 1
        local r
        r=$(jq -r '.result // empty' "$D/backup-letgo-job.json" 2>/dev/null)
        [[ $r == done ]] || { say "BK-LETGO: the clearing ended $r"; return 1; }
    fi
    _fl_ask office.fire '{"desk":"backup"}' >/dev/null
    rc=$?; _fl_end $rc
}

# BK-FORGET: «Start over» (desk.js ~2755 setup_forget {})
flow_bk_forget() { _fl_args "$@" || return 1; local rc; _fl_ask backup.setup_forget '{}' >/dev/null; rc=$?; ((rc == 0)) || { _fl_end $rc; return; }; wait_setup 600; }

# ============================================================================================================ Mr. Restori
# RS-SCAN: his fresh look (the page's restore.refresh)
flow_rs_scan() { _fl_args "$@" || return 1; ask restore.refresh '{}' 300 >/dev/null; }

fl_last_restore() { cat "$KEEP/.rs-last" 2>/dev/null; }

# _rs_go <request json>: the restore dialog (desk.js ~1793 preview {…ask, stamp?}; ~1832 start {…ask, stamp, token})
# — a plan with blockers is refused as the dialog's grey «Restore» button refuses it; the job waited for
_rs_go() {
    local req=$1 rc plan stamp token id r
    _fl_ask restore.preview "$req" 300 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    plan=$(ask_last '.preview')
    printf '%s\n' "$plan" >"$KEEP/rs-preview.json"
    if [[ $(jq '(.blockers // []) | length' <<<"$plan") != 0 ]]; then
        say "restore plan blocked: $(jq -c '[.blockers[].key]' <<<"$plan")"
        printf '%s\n' "$(jq -c '{ok: false, error: {key: .blockers[0].key, params: (.blockers[0].params // {})}}' <<<"$plan")" >"$KEEP/ask/last.json"
        [[ ${FL[refuse]:-} == ok ]] && return 0
        return 1
    fi
    [[ $(jq '(.steps // []) | length' <<<"$plan") != 0 ]] || { say "restore plan without steps"; return 1; }
    stamp=$(jq -r .stamp <<<"$plan")
    token=$(jq -r .token <<<"$plan")
    _fl_ask restore.start "$(jq -c --arg s "$stamp" --arg t "$token" '. + {stamp: $s, token: $t}' <<<"$req")" 300 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    id=$(ask_last '.id // empty')
    printf '%s\n' "$id" >"$KEEP/.rs-last"
    _fl_note "restore $(jq -r .kind <<<"$req")" "$(jq -cn --arg i "$id" --argjson r "$req" '{id: $i, req: $r}')"
    # at work (a look at the bubbles then) or already done — atd starts it a moment after the answer
    if WAIT_SOFT=1 wait_for 60 "the restore job to begin" bash -c "[[ \$(jq -r .result '$D/restore/$id/journal.json' 2>/dev/null) != queued ]]"; then
        [[ $(jq -r .result "$D/restore/$id/journal.json" 2>/dev/null) == running ]] && bubble_look mid-restore restore
    fi
    r=$(wait_restore "$id" 7200) || return 1
    [[ $r =~ ^(ok|warnings)$ ]] || { say "restore $id ended $r"; return 1; }
}

# RS-FILES mode=beside|replace path= [moment=] [wake=1]: files from a moment of the snapshots — the dialog's first
# preview without a moment, then the moment picked (the newest unless moment= names one), «beside» = copy, «replace» = swap
flow_rs_files() {
    _fl_args "$@" || return 1
    local path=${FL[path]:-} mode req rc plan snap
    [[ -n $path ]] || { say "RS-FILES: path= missing"; return 1; }
    case ${FL[mode]:-beside} in beside | copy) mode=copy ;; replace | swap) mode=swap ;; *) say "RS-FILES: mode beside|replace"; return 1 ;; esac
    req=$(jq -cn --arg p "$path" --arg m "$mode" --arg w "${FL[wake]:-}" '{kind: "files", path: $p, mode: $m} + (if $w != "" then {wake: true} else {} end)')
    snap=${FL[moment]:-}
    if [[ -z $snap ]]; then
        _fl_ask restore.preview "$req" 300 >/dev/null
        rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
        plan=$(ask_last '.preview')
        snap=$(jq -r '[(.options.moments // .options.snaps // [])[] | (.id // .snap // .name // .)] | map(select(type == "string")) | .[0] // empty' <<<"$plan")
        [[ -n $snap ]] || snap=$(jq -r '.target.snap // .snap // empty' <<<"$plan")
        say "RS-FILES: moment $snap (the newest offered)"
    fi
    [[ -n $snap ]] && req=$(jq -c --arg s "$snap" '. + {snap: $s}' <<<"$req")
    _rs_go "$req" || return 1
    # INV-COMPARE, right when the job ended (an app started again changes its files): the copy out of the moment (a
    # snapshot's folder) against where it ended up (beside: the copy; replace: the step moving the copy into place)
    _rs_compare "$(fl_last_restore)"
    return 0
}
# _rs_compare <journal id>: one line of $KEEP/compare.tsv — id, the moment's folder, the target, ok | the difference
_rs_compare() {
    local id=$1 plan_f=$D/restore/$1/plan.json pair src dst d res=ok
    pair=$(jq -r '([.steps[]? | select(.do == "copy" and ((.from // "") | test("/[.]zfs/snapshot(/|$)|[.]btrfs-snap|/snapshots/"))))][0] as $c
        | if $c == null then empty else
            ([.steps[]? | select(.do == "move" and .from == $c.to) | .to][0] // $c.to) as $to | "\($c.from)\t\($to)" end' "$plan_f" 2>/dev/null)
    [[ -n $pair ]] || { say "INV-COMPARE: no copy out of a moment in $id's plan"; return 0; }
    IFS=$'\t' read -r src dst <<<"$pair"
    if [[ ! -e $src ]]; then res="the moment's $src is gone"
    elif [[ ! -e $dst ]]; then res="$dst isn't there"
    else
        d=$(diff -rq --no-dereference "$src" "$dst" 2>&1 | head -2)
        [[ -n $d ]] && res=${d//$'\n'/; }
        [[ $(stat -c '%U:%G %a' "$src") == "$(stat -c '%U:%G %a' "$dst")" ]] || res+="; owner/mode $(stat -c '%U:%G %a' "$dst") ≠ $(stat -c '%U:%G %a' "$src")"
    fi
    printf '%s\t%s\t%s\t%s\n' "$id" "$src" "$dst" "$res" >>"$KEEP/compare.tsv"
    say "INV-COMPARE $id: $dst vs $src — $res"
}
flow_rs_files_beside() { flow_rs_files mode=beside "$@"; }
flow_rs_files_replace() { flow_rs_files mode=replace "$@"; }

# RS-DB app= file= [version=]: «Restore the database…» (desk.js ~704 {kind:'db', app, file}, ~880 + version)
flow_rs_db() {
    _fl_args "$@" || return 1
    _rs_go "$(jq -cn --arg a "${FL[app]:-}" --arg f "${FL[file]:-}" --arg v "${FL[version]:-}" '{kind: "db", app: $a, file: $f} + (if $v != "" then {version: $v} else {} end)')"
}
# RS-SQLITE app= container= [version=] (desk.js ~775)
flow_rs_sqlite() {
    _fl_args "$@" || return 1
    _rs_go "$(jq -cn --arg a "${FL[app]:-}" --arg c "${FL[container]:-}" --arg v "${FL[version]:-}" '{kind: "sqlite", app: $a, container: $c} + (if $v != "" then {version: $v} else {} end)')"
}
# RS-CONFIG app= [version=]: the template / compose files back (desk.js ~650, ~883)
flow_rs_config() {
    _fl_args "$@" || return 1
    _rs_go "$(jq -cn --arg a "${FL[app]:-}" --arg v "${FL[version]:-}" '{kind: "config", app: $a} + (if $v != "" then {version: $v} else {} end)')"
}
# RS-VM vm= [version=] (desk.js ~1109, ~884)
flow_rs_vm() {
    _fl_args "$@" || return 1
    _rs_go "$(jq -cn --arg a "${FL[vm]:-}" --arg v "${FL[version]:-}" '{kind: "vm", vm: $a} + (if $v != "" then {version: $v} else {} end)')"
}
# RS-KOPIA source= [snapshot=]: Kopia's list of a source (desk.js ~2106 kopia_list {source}), a snapshot picked (the
# newest unless snapshot= names one), restored (~2126 {kind:'kopia', source, snapshot})
flow_rs_kopia() {
    _fl_args "$@" || return 1
    local src=${FL[source]:-} snap=${FL[snapshot]:-} rc
    [[ -n $src ]] || { say "RS-KOPIA: source= missing"; return 1; }
    if [[ -z $snap ]]; then
        _fl_ask restore.kopia_list "$(jq -cn --arg s "$src" '{source: $s}')" 300 >/dev/null
        rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
        snap=$(ask_last '[(.snapshots // .list // [])[] | .id] | .[0] // empty')
        [[ -n $snap ]] || { say "RS-KOPIA: no snapshot of $src"; return 1; }
    fi
    _rs_go "$(jq -cn --arg s "$src" --arg n "$snap" '{kind: "kopia", source: $s, snapshot: $n}')"
}
# RS-PUTBACK of=<id>|last: «Put back» at a journal entry (desk.js ~2309 {kind:'putback', id})
flow_rs_putback() {
    _fl_args "$@" || return 1
    local of=${FL[of]:-last}
    [[ $of == last ]] && of=$(fl_last_restore)
    [[ -n $of ]] || { say "RS-PUTBACK: no restore to put back"; return 1; }
    _rs_go "$(jq -cn --arg i "$of" '{kind: "putback", id: $i}')"
}

# RS-DRILL: «Practise now…» (drill.js: drill_plan {} → its preview's stamp and token → drill_start {stamp, token}),
# the drill waited for
flow_rs_drill() {
    _fl_args "$@" || return 1
    local rc stamp token
    _fl_ask restore.drill_plan '{}' 300 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    if [[ $(ask_last '(.preview.blockers // []) | length') != 0 ]]; then
        say "RS-DRILL: blocked: $(ask_last '[.preview.blockers[].key] | join(",")')"
        jq -c '{ok: false, error: {key: .preview.blockers[0].key}}' "$KEEP/ask/last.json" >"$KEEP/ask/last.tmp" && mv "$KEEP/ask/last.tmp" "$KEEP/ask/last.json"
        [[ ${FL[refuse]:-} == ok ]] && return 0
        return 1
    fi
    stamp=$(ask_last '.preview.stamp')
    token=$(ask_last '.preview.token')
    local t0
    t0=$(_sc_now)
    _fl_ask restore.drill_start "$(jq -cn --arg s "$stamp" --arg t "$token" '{stamp: $s, token: $t}')" 300 >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    local id r
    id=$(ask_last '.id // empty')
    _fl_note drill "$(jq -cn --arg i "$id" '{id: $i}')"
    # atd starts it a moment later: until then drill_state says nothing runs (and would read as «done»)
    WAIT_SOFT=1 wait_for 120 "the drill to begin" _drill_begun "$t0" || say "RS-DRILL: no sign of the drill after 120 s"
    wait_drill 7200 >/dev/null || return 1
    r=$(_drill_result "$id")
    say "RS-DRILL $id: $r"
    [[ $r == passed* ]] || return 1
}
flow_rs_drill_manual() { flow_rs_drill "$@"; }
# the drill started at or after <t>: running now, or its certificate finished since
_drill_begun() {
    php "$SC_DIR/ask.php" --wait 30 restore.drill_state '{}' 2>/dev/null |
        jq -e --argjson t "$1" '.running != null or ([.drills[]? | select((.started // 0) >= $t)] | length > 0)' >/dev/null
}
# _drill_result <id>: «passed|failed|aborted …» with the steps not checked and why (its journal)
_drill_result() {
    local j=$D/restore-drill/$1/journal.json
    [[ -f $j ]] || { echo "no journal"; return 1; }
    jq -r '"\(.result)\([.steps[]? | select(.state == "not_checked") | .code] | if length > 0 then " — not checked: " + (group_by(.) | map("\(.[0]) ×\(length)") | join(", ")) else "" end)\([.steps[]? | select(.state == "failed") | "\(.name) \(.code)"] | if length > 0 then " — failed: " + join(", ") else "" end)"' "$j"
}

# ============================================================================================================ Ms. Moverelli
# her settings as her page holds them (moverelli.json .settings after a fresh look)
_mv_settings() { ask moverelli.refresh '{}' 120 >/dev/null || return 1; jq -c '.settings' "$D/moverelli.json"; }

# MV-SETUP [x=80] [mover_off=1] [rule=<share>:<days>…]: her setup's Save (desk.js ~2138 save {settings[, mover_off:true]})
flow_mv_setup() {
    _fl_args "$@" || return 1
    local s r sh days rc
    touch "$KEEP/.mv-touched"
    s=$(_mv_settings) || return 1
    [[ -n ${FL[x]:-} ]] && s=$(jq -c --argjson x "${FL[x]}" '.global.move_when_used_above = $x' <<<"$s")
    for r in "${FL_LIST[@]}"; do
        sh=${r%%:*} days=${r#*:}
        [[ $days =~ ^[0-9]+$ ]] || { say "MV-SETUP: rule=<share>:<days>, not $r"; return 1; }
        s=$(jq -c --arg s "$sh" --argjson d "$days" '.shares[$s] = ((.shares[$s] // {}) + {skip: false, min_age: $d})
            | .shares[$s] |= ({files: [], patterns: [], move_when_used_above: null} + .)' <<<"$s")
    done
    local body
    if [[ ${FL[mover_off]:-} =~ ^(1|true|yes)$ ]]; then body=$(jq -cn --argjson s "$s" '{settings: $s, mover_off: true}')
    else body=$(jq -cn --argjson s "$s" '{settings: $s}'); fi
    _fl_note "moverelli.save" "$(jq -c '{global: .settings.global, shares: (.settings.shares | keys)}' <<<"$body")"
    _fl_ask moverelli.save "$body" >/dev/null
    rc=$?; _fl_end $rc
}

# MV-RESAVE: the setup opened and saved without a change — the ini must stay byte for byte (INV-REPLAN, her part)
flow_mv_resave() {
    _fl_args "$@" || return 1
    local s ini=$D/moverelli/smart_mover.ini before after rc
    touch "$KEEP/.mv-touched"
    before=$(md5sum <"$ini" 2>/dev/null)
    s=$(_mv_settings) || return 1
    _fl_ask moverelli.save "$(jq -cn --argjson s "$s" '{settings: $s}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    after=$(md5sum <"$ini" 2>/dev/null)
    [[ $before == "$after" ]] || { say "MV-RESAVE: smart_mover.ini changed by a save without a change"; return 1; }
}

# MV-LIST / MV-DRY / MV-RUN: «List» / «Dry run» / «Run now» (desk.js ~1634 start_run {mode}), her run waited for; while a
# real run is at work its bubbles and run card are looked at (INV-BUBBLE, INV-PAGE)
_mv_start() {
    local mode=$1 rc
    touch "$KEEP/.mv-touched"
    _fl_ask moverelli.start_run "$(jq -cn --arg m "$mode" '{mode: $m}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    _fl_note "moverelli.start_run $mode" null
    if WAIT_SOFT=1 wait_for 90 "Ms. Moverelli's run to be at work (or done)" _mv_begun; then
        sleep "${FL[look]:-1}"
        _mv_at_work && bubble_look "mid-moverelli-$mode" moverelli
    fi
    wait_moverelli 7200 || return 1
    local ex
    ex=$(jq -r '.exit // empty' "$D/moverelli/office-run.json" 2>/dev/null)
    [[ -z $ex || $ex == 0 ]] || { say "Ms. Moverelli's $mode ended with exit $ex"; return 1; }
}
_mv_at_work() { local p; p=$(jq -r '.pid // 0' "$D/moverelli/office-run.json" 2>/dev/null); [[ $(jq -r '.finished // 0' "$D/moverelli/office-run.json" 2>/dev/null) == 0 ]] && kill -0 "$p" 2>/dev/null; }
# her run started after the last ask (at work now, or already done — a dry run on a small server takes a second)
_mv_begun() { _mv_at_work || _started_since "$D/moverelli/office-run.json" .started; }
flow_mv_list() { _fl_args "$@" || return 1; _mv_start list; }
flow_mv_dry() { _fl_args "$@" || return 1; _mv_start dry; }
flow_mv_run() { _fl_args "$@" || return 1; _mv_start run; }

# MV-RUN-SCHED: the joint run as her cron line starts it (scripts/job.sh moverelli), waited for until night.json is done
flow_mv_run_sched() {
    _fl_args "$@" || return 1
    touch "$KEEP/.mv-touched"
    job moverelli || return 1
    if WAIT_SOFT=1 wait_for 120 "the joint run to be at work" bash -c "[[ \$(jq -r '.phase // empty' '$D/moverelli/night.json' 2>/dev/null) =~ ^(check|free|recheck|fill|tell)$ ]]"; then
        sleep "${FL[look]:-4}"
        bubble_look mid-joint-run moverelli
    fi
    wait_night 7200 || return 1
    job_wait moverelli 600
}

# MV-STOP-AFTER-FILE: a real run, then «Stop after this file» (desk.js ~1233 act('stop') = stop {}) once a file moves
flow_mv_stop_after_file() {
    _fl_args "$@" || return 1
    local rc
    touch "$KEEP/.mv-touched"
    ask moverelli.start_run '{"mode":"run"}' >/dev/null || return 1
    wait_for 120 "a file on its way" _mv_moving || return 1
    _fl_ask moverelli.stop '{}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    _fl_note "moverelli.stop" "$(ask_last '{stopping: (.state.stopping // null)}')"
    wait_moverelli 3600 || return 1
    # her history's newest row: stopped by the user (the file in flight finished, no further one begun)
    ask moverelli.refresh '{}' 120 >/dev/null
    local h
    h=$(jq -c '.history[0] | {result, why, exit}' "$D/moverelli.json" 2>/dev/null)
    say "MV-STOP-AFTER-FILE: her last run $h"
    [[ $(jq -r '.result + "/" + (.why // "")' <<<"$h") == stopped/user ]] || return 1
}
flow_mv_night_stop() { flow_mv_stop_after_file "$@"; }
# a file in flight: her live progress (moverelli.progress) names one
_mv_moving() {
    _mv_at_work || return 1
    php "$SC_DIR/ask.php" --wait 20 --raw moverelli.progress '{}' 2>/dev/null | jq -e '.state.progress.file // empty' >/dev/null
}

# MV-SCHEDULE cron=…|cron=: her schedule dialog (desk.js ~1779 schedule {cron})
flow_mv_schedule() {
    _fl_args "$@" || return 1
    local rc
    touch "$KEEP/.mv-touched"
    _fl_ask moverelli.schedule "$(jq -cn --arg c "${FL[cron]-}" '{cron: $c}')" >/dev/null
    rc=$?; _fl_end $rc
}

# MV-MOVER-OFF: «Switch Unraid's mover schedule off» (desk.js ~1088 mover_off {confirm:true})
flow_mv_mover_off() { _fl_args "$@" || return 1; local rc; touch "$KEEP/.mv-touched"; _fl_ask moverelli.mover_off '{"confirm":true}' >/dev/null; rc=$?; _fl_end $rc; }

# MV-IMPORT folder=: a former Smart Mover's files (desk.js ~2408 import_preview {folder}, ~2512 import_apply {folder, token})
flow_mv_import() {
    _fl_args "$@" || return 1
    local f=${FL[folder]:-} rc token
    touch "$KEEP/.mv-touched"
    [[ -n $f ]] || { say "MV-IMPORT: folder= missing"; return 1; }
    _fl_ask moverelli.import_preview "$(jq -cn --arg f "$f" '{folder: $f}')" >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    token=$(ask_last '.preview.token // .token // empty')
    [[ -n $token ]] || { say "MV-IMPORT: the preview gave no token (blocked: $(ask_last '[.preview.blockers[]?.key] | join(",")'))"; [[ ${FL[refuse]:-} == ok ]] && return 0; return 1; }
    _fl_ask moverelli.import_apply "$(jq -cn --arg f "$f" --arg t "$token" --arg m "${FL[mover_off]:-}" '{folder: $f, token: $t} + (if $m != "" then {mover_off: true} else {} end)')" >/dev/null
    rc=$?; _fl_end $rc
}

# MV-LETGO: her let-go (desk.js ~2560 letgo_look {}, ~2566 letgo {confirm:true}), then office.fire {desk}
flow_mv_letgo() {
    _fl_args "$@" || return 1
    local rc
    touch "$KEEP/.mv-touched"
    _fl_ask moverelli.letgo_look '{}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    _fl_ask moverelli.letgo '{"confirm":true}' >/dev/null
    rc=$?; ((rc == 0)) || { _fl_end $rc; return; }
    _fl_ask office.fire '{"desk":"moverelli"}' >/dev/null
    rc=$?; _fl_end $rc
}

# ============================================================================================================ the office
# OF-HIRE / OF-FIRE desk=: the Team Lead's cards (core.js office.hire {desks:[…]} / office.fire {desk})
flow_of_hire() { _fl_args "$@" || return 1; local rc; _fl_ask office.hire "$(jq -cn --arg d "${FL[desk]:-}" '{desks: [$d]}')" >/dev/null; rc=$?; _fl_end $rc; }
flow_of_fire() { _fl_args "$@" || return 1; local rc; _fl_ask office.fire "$(jq -cn --arg d "${FL[desk]:-}" '{desk: $d}')" >/dev/null; rc=$?; _fl_end $rc; }

# OF-AGENT-RESTART: a changed file of the agent's own (as a deploy makes one) — it restarts itself
flow_of_agent_restart() { _fl_args "$@" || return 1; agent_restart; }

# the office's version as the plugin's files say and as the agent says
_of_versions() { printf '%s agent %s' "$(sed -n -E "s/^const OFFICE_VERSION = '([^']+)'.*/\1/p" "$P/src/bootstrap.php" 2>/dev/null)" "$(jq -r '.version // "?"' "$RUN/agent.json" 2>/dev/null)"; }

# OF-UPDATE plg=<file|url>|worktree: the plugin manager's update (`plugin update`, or `plugin install <plg>` for a file
# or address) — or worktree: the synced tree into the plugin (plugin/dev-sync.sh, the coordinator's deploy). Waited for
# until a new agent is at work. The command is kept for INV-MIGRATE-IDEMPOTENT (it runs it a second time).
flow_of_update() {
    _fl_args "$@" || return 1
    local how=${FL[plg]:-update} cmd
    case $how in
        worktree) cmd="bash $(printf %q "$SC_TREE/plugin/dev-sync.sh")" ;;
        update) cmd='plugin update unraid-secretary-office.plg' ;;
        *) cmd="plugin install $(printf %q "$how") forced" ;;
    esac
    printf '%s\n' "$cmd" >"$KEEP/.of-update"
    say "OF-UPDATE: before $(_of_versions)"
    agent_log_mark update
    agent_mark_restart
    _of_run "$cmd" || return 1
    agent_wait 300 || { [[ $how == update ]] && say "OF-UPDATE: no new agent — nothing newer to update to?"; return 1; }
    say "OF-UPDATE: after $(_of_versions)"
    _fl_note update "$(jq -cn --arg c "$cmd" --arg v "$(_of_versions)" '{cmd: $c, version: $v}')"
}
# a command of the plugin manager's: run without the scenario lock's descriptor (it may start an agent), its output kept
_of_run() {
    local out=$KEEP/of-$(date +%s).log rc
    _st_clean bash -c "$1" >"$out" 2>&1
    rc=$?
    say "\`$1\` → $rc ($(tail -1 "$out" 2>/dev/null | head -c 160))"
    evidence "$out" >/dev/null
    return $rc
}

# OF-REMOVE-REINSTALL [plg=]: the monthly release step 4 — `plugin remove`, then `plugin install` of the same .plg
# (default: the installed one, copied aside first — the remove takes it from the flash)
flow_of_remove_reinstall() {
    _fl_args "$@" || return 1
    local plg=${FL[plg]:-} keep=$KEEP_STASH/OF-REMOVE-REINSTALL listing
    mkdir -p "$keep"
    if [[ -z $plg ]]; then
        [[ -f /boot/config/plugins/unraid-secretary-office.plg ]] || { say "OF-REMOVE-REINSTALL: no installed .plg"; return 1; }
        plg=$KEEP/unraid-secretary-office.plg
        cp -p /boot/config/plugins/unraid-secretary-office.plg "$plg"
    fi
    # the data folder as it was (names, sizes, times — the agent's own log and heartbeat files left out)
    listing=$(find "$D" -path "$D/mailbox" -prune -o -type f -not -name agent.log -printf '%P %s %T@\n' 2>/dev/null | LC_ALL=C sort)
    printf '%s\n' "$listing" >"$KEEP/of-data-before.txt"
    say "OF-REMOVE-REINSTALL: $(_of_versions), .plg $(basename "$plg")"
    agent_mark_restart
    _of_run 'plugin remove unraid-secretary-office.plg' || return 1
    [[ ! -f $P/agent/agent.php ]] || say "OF-REMOVE-REINSTALL: $P is still there after the remove"
    _of_run "plugin install $(printf %q "$plg")" || return 1
    agent_wait 300 || return 1
    [[ -f $OFFICE_CFG ]] || { say "OF-REMOVE-REINSTALL: the .cfg is gone"; return 1; }
    ls "$OFFICE_FLASH"/*.cron >/dev/null 2>&1 || { say "OF-REMOVE-REINSTALL: no cron file after the reinstall"; return 1; }
    find "$D" -path "$D/mailbox" -prune -o -type f -not -name agent.log -printf '%P %s %T@\n' 2>/dev/null | LC_ALL=C sort >"$KEEP/of-data-after.txt"
    local gone
    gone=$(comm -23 "$KEEP/of-data-before.txt" "$KEEP/of-data-after.txt" | awk '{print $1}' | head -5)
    _fl_note reinstall "$(jq -cn --arg v "$(_of_versions)" --arg g "$gone" '{version: $v, changed_or_gone: $g}')"
    [[ -z $gone ]] || say "OF-REMOVE-REINSTALL: files of the data folder changed or gone: ${gone//$'\n'/ }"
    rmdir "$keep" 2>/dev/null
    return 0
}

# OF-ARRAY-STOP-START: ⟦Stop⟧ and ⟦Start⟧ on Unraid's Main page (emcmd, the states' array_stop/array_start); before the
# stop INV-NO-MNT-OPEN is looked at (kept in .of-array), the stop must end within 120 s, a new agent waited for
flow_of_array_stop_start() {
    _fl_args "$@" || return 1
    local t0 secs
    command -v check_inv_no_mnt_open >/dev/null && { check_inv_no_mnt_open >"$KEEP/.of-array" 2>&1; echo "rc=$?" >>"$KEEP/.of-array"; }
    agent_mark_restart
    t0=$(_sc_now)
    array_stop OF-ARRAY-STOP-START || { array_start OF-ARRAY-STOP-START; return 1; }
    secs=$(($(_sc_now) - t0))
    echo "stop_seconds=$secs" >>"$KEEP/.of-array"
    say "OF-ARRAY-STOP-START: stopped in $secs s"
    # the night shift: nothing under /mnt open (cwd /), RAM and flash only
    local ns
    ns=$(head -1 "$RUN/nightshift.lock" 2>/dev/null | tr -dc 0-9)
    if [[ -n $ns ]] && kill -0 "$ns" 2>/dev/null; then
        [[ $(readlink "/proc/$ns/cwd") == /mnt/* ]] && say "OF-ARRAY-STOP-START: the night shift's cwd is under /mnt"
        echo "nightshift=$ns cwd=$(readlink "/proc/$ns/cwd")" >>"$KEEP/.of-array"
    fi
    array_start OF-ARRAY-STOP-START || return 1
    agent_wait 300 || return 1
    _fl_note array "$(jq -cn --argjson s "$secs" '{stop_seconds: $s}')"
    ((secs <= 120)) || { say "OF-ARRAY-STOP-START: the stop took $secs s (> 120 s: something held it)"; return 1; }
}

# OF-REBOOT: the pre half (playbook §5): what must come back noted in the run's report folder (the runner carries it
# over the reboot), then the runner reboots. OF-REBOOT-BACK: the post half compares.
_of_reboot_note() { printf '%s\n' "${USO_SC_OUT:-$SC_REPORTS}/${SC_NAME%-pre}.reboot"; }
flow_of_reboot() {
    _fl_args "$@" || return 1
    local note
    note=$(_of_reboot_note)
    {
        echo "version=$(_of_versions)"
        echo "staff=$(jq -c '.hired | keys' "$D/office/staff.json" 2>/dev/null)"
        echo "cron=$(cat "$OFFICE_FLASH"/*.cron 2>/dev/null | md5sum | cut -c1-32)"
        echo "settings=$(md5sum <"$UB/settings.ini" 2>/dev/null | cut -c1-32)"
        echo "docker=$(docker ps --format '{{.Names}}' 2>/dev/null | sort | paste -sd, -)"
        echo "at=$(date +%s)"
    } >"$note"
    say "OF-REBOOT: noted for the post half in $note — the runner reboots now"
}
flow_of_reboot_back() {
    _fl_args "$@" || return 1
    local note bad=() k v now
    note=$(_of_reboot_note)
    [[ -f $note ]] || { say "OF-REBOOT-BACK: no note of the pre half ($note)"; return 1; }
    agent_wait 300 || bad+=("no agent at work")
    [[ $(_ini_value /var/local/emhttp/var.ini mdState) == STARTED ]] || bad+=("array not started")
    while IFS='=' read -r k v; do
        case $k in
            version) now=$(_of_versions) ;;
            staff) now=$(jq -c '.hired | keys' "$D/office/staff.json" 2>/dev/null) ;;
            cron) now=$(cat "$OFFICE_FLASH"/*.cron 2>/dev/null | md5sum | cut -c1-32) ;;
            settings) now=$(md5sum <"$UB/settings.ini" 2>/dev/null | cut -c1-32) ;;
            docker) now=$(docker ps --format '{{.Names}}' 2>/dev/null | sort | paste -sd, -) ;;
            *) continue ;;
        esac
        [[ $now == "$v" ]] || bad+=("$k: $v → $now")
    done <"$note"
    grep -q "$(basename "$P")" /etc/cron.d/root 2>/dev/null || bad+=("the plugin's cron lines not in /etc/cron.d/root")
    _fl_note reboot "$(jq -cn --arg b "${bad[*]}" '{differs: $b}')"
    ((${#bad[@]} == 0)) || { say "OF-REBOOT-BACK: ${bad[*]}"; return 1; }
}
