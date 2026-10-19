#!/bin/bash
# Cell B46 of matrix.md: atd isn't running (SV-ATD-OFF) and the user clicks «Back up now», then Apply in the setup. The
# office hands both to atd (hostLaunch: `at now`) — `at` queues the job even without atd, so nothing starts. What must
# hold: the page says the job could not start (a refusal the page shows, errors.<key>) — not «handed over, it should
# start any moment» —, and nothing is «running» for ever: a minute later his page says no run and no setup are going,
# settings.ini is unchanged (INV-SETTINGS). The jobs atd never ran are taken out of at's queue before atd comes back
# in the undo (else they would start then, a run nobody waits for) — their number is said in the report.
# (Named -sv-: the cell is the service SV-ATD-OFF; batch D owns it.) Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-atd-off-run-apply
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# the office's jobs in at's queue (its job scripts carry HOST_LAUNCH_MARK — agent/lib/house.php)
office_atq() { local id; for id in $(atq 2>/dev/null | awk '{print $1}'); do at -c "$id" 2>/dev/null | grep -q 'unraid-secretary-office\|UnraidSecretaryOffice\|uso-' && echo "$id"; done; }
atq_clear() { local id n=0; for id in $(office_atq); do atrm "$id" && n=$((n + 1)); done; say "at's queue: $n job(s) of the office removed (atd never ran them)"; }
said() {   # <cell> <answer file>: a refusal (the page shows errors.<key>) — or ok with started=false (the page's toast)
    local c=$1 a; a=$(cat "$2")
    if [[ $(jq -r '.ok' <<<"$a") == false ]]; then
        cell "$c" 1 "refused: $(jq -r '.error.key' <<<"$a") — the page says why"
    elif [[ $(jq -r '.started // false' <<<"$a") == true ]]; then
        cell "$c" 0 "the answer says it started — with atd stopped"
    else
        cell "$c" 0 "accepted with started=false: the page's toast «$(jq -r '.started_pending' "$P/desks/backup/lang/en.json")» — nothing says it could not start"
    fi
}
nc_data_closed
q0=$(office_atq | wc -l)
state SV-ATD-OFF
undo_push atq_clear                          # runs before atd comes back (undo in reverse order)
snap_before
minute_wait
ask backup.start '{"mode":"backup"}' >/dev/null
cp "$KEEP/ask/last.json" "$KEEP/start-answer.json"
said start-said "$KEEP/start-answer.json"
# the page's look a minute later: no run, nothing «starting» for ever
sleep 50
r=$(state_json backup '"running=\(.running) status=\(.status.result // "none") queued=\(.queued != null)"' 2>/dev/null)
[[ $r == "running=false status=none queued=false" || ( $r == running=false* && $r != *status=running* ) ]] \
    && cell start-not-running 1 "a minute later his page: $r" || cell start-not-running 0 "a minute later his page: $r"
lock_held "$UB/state/lock" && cell start-no-lock 0 "the engine's lock is held — by what?" || cell start-no-lock 1 "nobody holds the engine's lock"
# Apply on the plan the setup shows (the base's), nothing changed by the user
plan=$KEEP/plan-apply.json
if _bk_plan_get "$plan"; then
    body=$(decisions_from_plan "$plan")
    ask backup.setup_apply "$body" 120 >/dev/null
    cp "$KEEP/ask/last.json" "$KEEP/apply-answer.json"
    said apply-said "$KEEP/apply-answer.json"
    sleep 30
    s=$(state_json backup '"setup.running=\(.setup.running // false)"' 2>/dev/null)
    [[ $s == setup.running=false ]] && cell apply-not-running 1 "30 s later his page: $s" || cell apply-not-running 0 "30 s later his page: $s"
else
    cell apply-said 0 "no plan to apply (backup.setup_get)"
fi
q1=$(office_atq | wc -l)
say "at's queue: $q0 office job(s) before, $q1 now — atd would start them whenever it comes back"
# the cell's state ends before the invariants: the jobs atd never ran out of its queue, atd back (a plan for INV-REPLAN)
atq_clear
atd_on SV-ATD-OFF
check standard
scenario_end
