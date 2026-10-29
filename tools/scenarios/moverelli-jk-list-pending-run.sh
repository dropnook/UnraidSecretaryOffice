#!/bin/bash
# Cell M19 of matrix.md, the list pending: Jack Streamer hired, his list never made (JK-LIST-PENDING), her ini naming it.
# «Run now» is refused (moverelli_jack_list_pending, the file named) and a dry run goes. Her cron line's joint run (since
# task/jack-helmi, main 47520dab) lets Jack Streamer go first — his run writes his list (cell sched-jack-first: night.json's
# steps start with {who: jack} and his list exists); where his media server can't be asked, her step is refused with
# moverelli_jack_list_pending (by: night) instead and nothing of media moved. Never told Unraid's notifications
# (moverelli.php: «a scheduled refusal is counted, never notified»). Pre-release (~3 min).
# run-late: 1 her refused row stays in her history (every later Moverelli page red on it until a base reset)
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-list-pending-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state JK-LIST-PENDING
l=$(_st_jack_list)
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_jack_list_pending
flow MV-DRY
t0=$(_sc_now)
# her cron line's job as crond starts it (a refused joint run may write no «done»: the job itself waited for, then the
# joint run's end when it began)
job moverelli && job_wait moverelli 900
WAIT_SOFT=1 wait_for 60 "the joint run to settle" _night_done
evidence "$D/moverelli/night.json" night.json >/dev/null
steps=$(jq -c '[.steps[]? | {who, phase, result, why: (.why // .reason // .error // null)}]' "$D/moverelli/night.json" 2>/dev/null)
first=$(jq -r '.[0].who // empty' <<<"${steps:-[]}" 2>/dev/null)
ns=$(jq -r '.started // 0' "$D/moverelli/night.json" 2>/dev/null)
say "the joint run (started $(date -d "@${ns:-0}" +%T 2>/dev/null)): $steps"
ask moverelli.refresh '{}' 120 >/dev/null
hrow=$(jq -c '[.history[]? | select(.why == "moverelli_jack_list_pending" and .by == "night")][0] // empty' "$D/moverelli.json" 2>/dev/null)
if ((ns >= t0)) && [[ $first == jack && -f $l ]]; then
    cell sched-jack-first 1 "the joint run let Jack Streamer go first, his list is there ($(grep -c . "$l") lines)"
elif [[ -n $hrow || $steps == *moverelli_jack_list_pending* ]]; then
    cell sched-jack-first 1 "his media server couldn't be asked: her step refused moverelli_jack_list_pending (by night) ${hrow:-$steps}"
else
    cell sched-jack-first 0 "her cron line's joint run: steps ${steps:-none} (started ${ns:-0}, asked $t0), his list $( [[ -f $l ]] && echo there || echo missing), history row ${hrow:-none}"
fi
told=$(find "$NOTIFY_DIR/unread" "$NOTIFY_DIR/archive" -name 'Unraid_Secretary_Office*' -newermt "@$t0" 2>/dev/null | xargs -r grep -l -i 'moverelli\|Moverelli\|mover' 2>/dev/null | head -3)
[[ -z $told ]] && cell not-notified 1 "no notification of hers about the refusals" || cell not-notified 0 "told: $(cat $told | grep -h '^subject=' | head -2 | paste -sd' ' -)"
check standard
scenario_end
