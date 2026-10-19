#!/bin/bash
# Cell B19 of matrix.md (batch E), TM-DST autumn: the night of 2026-10-25 in a zone with summer time (Europe/Berlin —
# USOTest3VM's own zone file is missing, so the state links one for its time; helper task/scenarios-helper-tm-dst-zone),
# the clock two minutes before 03:00 CEST, ntpd stopped; Mr. Backupsy's schedule at 02:59 (prepared, put back by the
# undo). crond itself starts the job — at 02:59 CEST and, an hour later, at the second 02:59 (CET). One real run a
# minute and a name per minute (uso-backup-YYYYMMDD-HHMM): the second 02:59 must not start a second run under the same
# name, or ends refused and says so in words (the engine: «the name exists — touching nothing», 2.34); never two runs
# for the night, never a failed one, nothing touched by the refused one (cells one-run, said). Nightly (~70 min: the
# hour between the two 02:59s is waited for; the clock guard holds the set clock 90 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-tm-dst-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
export USO_CLOCK_MAX=5400
nc_data_closed
cron0=$(state_json backup '.schedule.custom // "30 4 * * *"')
flow BK-SCHEDULE cron='59 2 * * *' || _row state prepare red "the schedule 59 2 * * * not saved"
undo_push flow BK-SCHEDULE "cron=$cron0"
state TM-DST autumn Europe/Berlin
say "the server: $(date '+%F %T %Z') ($(date -u '+%T UTC'))"
snap_before
st=$UB/state/status.json
wait_for 240 "crond's first 02:59 (CEST) run to start" bash -c "[[ \$(jq -r '.run // \"\"' '$st') == 20261025-0259 ]]"
wait_backup 1200
r1=$(jq -c '{run, result, started}' "$st"); say "the first 02:59: $r1"
h0=$(jq -s '[.[] | select(.run == "20261025-0259")] | length' "$UB/state/history.jsonl" 2>/dev/null || echo 0)
# the second 02:59 (CET) comes 60 minutes later
wait_for 4200 "the second 02:59 (CET) to pass" bash -c "[[ \$(date +%H%M%Z) == 0301CET ]]"
wait_backup 1200
r2=$(jq -c '{run, result, started, message}' "$st"); say "after the second 02:59: $r2"
sk=$(jq -c '{reason: (.reason // .why // null), time}' "$UB/state/skipped.json" 2>/dev/null)
n=$(jq -s '[.[] | select(.run == "20261025-0259")] | length' "$UB/state/history.jsonl" 2>/dev/null || echo 0)
say "history rows named 20261025-0259: $h0 → $n; skipped.json: ${sk:-none}"
evidence "$UB/state/history.jsonl" >/dev/null 2>&1
(( n == h0 && h0 == 1 )) && cell one-run 1 "one run named 20261025-0259 for the night ($r1)" \
    || cell one-run 0 "runs named 20261025-0259: $n (after the first: $h0) — $r2"
jq -e '.result != "failed"' "$st" >/dev/null && cell not-failed 1 "the last run: $r2" || cell not-failed 0 "the last run: $r2"
check standard
scenario_end
