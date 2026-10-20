#!/bin/bash
# Cell R22 of matrix.md (nightly — the clock is set): Mr. Restori's drill on its own (RS-DRILL-SCHED: his tick, weekly,
# the night after a backup run, once packages exist for 7 days) under the clock (TM-*: clock_set with ntpd stopped, put
# back by the states' guard). On his weekly night ≥ 7 days on (drillWeekDue: the night ending on the chosen weekday,
# ≥ 6 calendar days after the last drill — every cell's check final drills, so the last one is today): (1) at 04:16
# with the scheduled backup at 04:30 (restore-drill.php:475: now + its estimate > 04:30 − 15 min — any estimate) — a run, then his tick refuses (auto.json: drill_deadline: it could not end ≥ 15 min before the backup); (2) at 02:20 — a run, then the drill
# starts by itself and its deadline lies ≥ 15 min before 04:30; (3) at 08:05 — a run, then nothing (outside the window
# 00–07). After every step of the clock the agent's doorbell is rung (a nap across a step back sleeps until it: batch D's
# F12). No INV-BACKUP-AFTER (the history then lies in the future). Run last: its runs on the clock set on stay in the
# history and as snapshots dated in the future until the next base reset (another cell would take them for the newest
# moment). Nightly (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-tm-drill-sched
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
ring() { ask restore.refresh '{}' 60 >/dev/null; agent_wait 60 || _row state heartbeat red "no heartbeat within 60 s after the clock step (batch D F12?)"; }
auto() { jq -c '{run, at, id, refused}' "$D/restore-drill/auto.json" 2>/dev/null; }
tick_after() {   # his tick's note (auto.json) about the run just made (its id — the clock goes back between the steps:
    # a note's time says nothing), ≤ 150 s
    local run; run=$(jq -r '.run // empty' "$UB/state/last-run.json" 2>/dev/null)
    wait_for 150 "his tick's note about run $run (auto.json)" bash -c "[[ \$(jq -r '.run // empty' '$D/restore-drill/auto.json' 2>/dev/null) == '$run' ]]"; }
# a run on the clock just set, once the engine's lock is free (seen once: held right after the step, backup.start
# refused backup_running — who held it goes into the report)
_unlocked() { ! lock_held "$UB/state/lock"; }
run_free() {
    lock_held "$UB/state/lock" && say "the engine's lock held after the clock step: $(jq -c . "$UB/state/lock-holder.json" 2>/dev/null)"
    wait_for 180 "the engine's lock free" _unlocked
    flow BK-RUN-MANUAL mode=backup
}
nc_data_closed
cron=$(grep -h 'job.sh backup' "$OFFICE_FLASH"/*.cron | awk '{print $1, $2}')
[[ $cron == '30 4' ]] || _row state schedule red "the base's backup runs at «$cron», the cell wants 04:30"
flow BK-RUN-MANUAL mode=backup               # packages from now on (his 7 days count from the first history line)
snap_before
# that night's day: the first one ≥ 7 days on that ends on his weekday (settings.json, date +%w), its midnight in its own
# zone (summer time ends on the last Sunday of October: «today + n × 86400» would be an hour off)
wd=$(jq -r '.weekday // 0' "$D/restore-drill/settings.json" 2>/dev/null)
for i in 7 8 9 10 11 12 13; do [[ $(date -d "+$i days" +%w) == "$wd" ]] && break; done
day=$(date -d "$(date -d "+$i days" +%F) 00:00" +%s)
say "his night: $(date -d "@$day" +%F) (weekday $wd), $i days on"
# the clock back whatever happens (each restore does nothing when its clock wasn't set; the states' guard too)
undo_push clock_restore R22-DEADLINE; undo_push clock_restore R22-WINDOW; undo_push clock_restore R22-OUTSIDE
# (1) 04:16 — the deadline
clock_set R22-DEADLINE "@$((day + 4 * 3600 + 16 * 60))"; ring
run_free
tick_after && a=$(auto) || a=none
[[ $(jq -r '.refused // empty' <<<"$a" 2>/dev/null) == drill_deadline ]] && cell deadline 1 "at $(date +%H:%M) his tick refused: $a" || cell deadline 0 "at $(date +%H:%M) (backup 04:30) his tick: $a"
clock_restore R22-DEADLINE; ring
# (2) 02:20 — the window
clock_set R22-WINDOW "@$((day + 2 * 3600 + 20 * 60))"; ring
run_free
tick_after && a=$(auto) || a=none
id=$(jq -r '.id // empty' <<<"$a" 2>/dev/null)
if [[ -n $id ]]; then
    wait_drill 3600 >/dev/null
    dl=$(jq -r '.deadline // 0' "$D/restore-drill/$id/journal.json" 2>/dev/null)
    r=$(jq -r '.result // ""' "$D/restore-drill/$id/journal.json" 2>/dev/null)
    cell window-start 1 "at 02:2x the drill started by itself ($id): $r"
    ((dl > 0 && dl <= day + 4 * 3600 + 30 * 60 - 900)) && cell deadline-before-backup 1 "its deadline $(date -d "@$dl" +%H:%M) ≥ 15 min before 04:30" \
        || cell deadline-before-backup 0 "its deadline $(date -d "@$dl" +%H:%M) (the backup at 04:30)"
else
    cell window-start 0 "at 02:2x no drill started by itself: $a"
fi
clock_restore R22-WINDOW; ring
# (3) 08:05 — outside the window
clock_set R22-OUTSIDE "@$((day + 8 * 3600 + 5 * 60))"; ring
run_free
a0=$(auto); sleep 130; a1=$(auto)
[[ $a1 == "$a0" ]] && cell outside-window 1 "at 08:0x his tick tried nothing" || cell outside-window 0 "at 08:0x his tick: $a1 (was $a0)"
clock_restore R22-OUTSIDE; ring
allow INV-TICK "the wall clock itself is set days on and back (TM-*: clock_set); the heartbeat is a time() of that clock — the gap is the jump, a missing heartbeat after a step is the cell's own row (ring)"
allow INV-NOTIFY-ONCE "four backup runs within minutes on a clock set to and fro — each run tells its result (backup.sh ub_tell at its end)"
check standard
scenario_end
