#!/bin/bash
# Cell M19 of matrix.md, the list pending: Jack Streamer hired, his list never made (JK-LIST-PENDING), her ini naming it.
# «Run now» is refused (moverelli_jack_list_pending, the file named), a dry run goes, and her cron line's joint run is
# refused too — one row in her history counting the tries (times), never told Unraid's notifications (moverelli.php: «a scheduled
# refusal is counted, never notified»). Two tries of the cron line: one row, times 2. Pre-release (~3 min).
# run-late: 1 her refused row stays in her history (every later Moverelli page red on it until a base reset)
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-list-pending-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# her history: one row for repeated identical scheduled refusals, `times` counting them (moverelliRemember)
pend() { ask moverelli.refresh '{}' 120 >/dev/null; jq '[.history[]? | select(.why == "moverelli_jack_list_pending" and .by == "schedule") | (.times // 1)] | add // 0' "$D/moverelli.json" 2>/dev/null; }
rows() { jq '[.history[]? | select(.why == "moverelli_jack_list_pending" and .by == "schedule")] | length' "$D/moverelli.json" 2>/dev/null; }
state JK-LIST-PENDING
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_jack_list_pending
flow MV-DRY
n0=$(pend)
t0=$(_sc_now)
# her cron line's job as crond starts it — twice, a minute apart (job.sh starts a job once a minute); a refused joint
# run writes no night.json, so not MV-RUN-SCHED (it waits for the night's «done»): the job itself waited for
job moverelli && job_wait moverelli 300
job moverelli && job_wait moverelli 300
n1=$(pend)
((n1 - n0 == 2)) && [[ $(rows) == 1 ]] && cell sched-counted 1 "two tries of her cron line: one row in her history, counted twice (times $n0 → $n1)" \
    || cell sched-counted 0 "two tries of her cron line: $(rows) row(s), counted $n0 → $n1"
told=$(find "$NOTIFY_DIR/unread" "$NOTIFY_DIR/archive" -name 'Unraid_Secretary_Office*' -newermt "@$t0" 2>/dev/null | xargs -r grep -l -i 'moverelli\|Moverelli\|mover' 2>/dev/null | head -3)
[[ -z $told ]] && cell not-notified 1 "no notification of hers about the refusals" || cell not-notified 0 "told: $(cat $told | grep -h '^subject=' | head -2 | paste -sd' ' -)"
check standard
scenario_end
