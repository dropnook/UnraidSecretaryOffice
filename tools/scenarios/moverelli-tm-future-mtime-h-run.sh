#!/bin/bash
# Batch H, finding 7 (parking lot «Resilienz-Funde», H4's wave 1b), Ms. Moverelli's half: the clock ran ahead and was put
# right — files written meanwhile were born, changed and modified in the future. Her age rule («older than N days»,
# age_stat newest) must never read them as «very old» (cell future-stays: still on the pool after her run), her run
# never fails on them (cell run-ok), the rule still moves what is really old (cell old-moves: two 40-day-old files
# beside them — the control), and the office says what it met (cell said: a word in her state or history that files
# with times in the future were left — expected, the brief: «said»). The share: uso-wip-h-fut (yes: cache → array), her
# rule 30 days; the future files written while the clock stood 30 days ahead with the agent stopped (h_put_future: no
# state file of the office takes the future time — batch F's BF5 is its own finding). Ms. Moverelli set up for the
# scenario, put back after. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-tm-future-mtime-h-run
needs any
S=uso-wip-h-fut
undo_push h_share_drop "$S"
nc_data_closed
share_make "$S" yes cache || _row state share_make red "Unraid's «Add Share» made no $S"
nc_data_closed
h_mv_set_up H7 "rule=$S:30" || _row state MV-SETUP red "her setup failed"
h_put_future H7 cache "$S/future" 3 30 || _row state put_future red "the future files could not be written"
put_files H7 cache "$S/old" 2 --age 40d || _row state put_files red "the old files could not be written"
undo_push files_unput H7
h_fresh_beat moverelli
say_cmd stat -c '%n | born %w | changed %z | modified %y' /mnt/cache/$S/future/* /mnt/cache/$S/old/*
track_share "$S"
snap_before
agent_log_mark run
flow MV-RUN
h_fresh_beat moverelli              # her state file (history) refreshed after the run before it is read
evidence "$D/moverelli/status.json" status.json >/dev/null
r=$(jq -c '{result: (.result // null), exit: (.exit // null), shares: [.shares[]? | select(.share == "'"$S"'") | {state, moved, left}]}' "$D/moverelli/status.json" 2>/dev/null)
h=$(jq -c '.[0] // {} | {result, why: (.why // null)}' <(jq -c '.history // []' "$D/moverelli.json" 2>/dev/null) 2>/dev/null)
say "her run: $r · her history's newest row: $h"
fs=$(find "/mnt/cache/$S/future" -type f 2>/dev/null | wc -l); fa=$(find /mnt/disk*/"$S"/future -type f 2>/dev/null | wc -l)
os=$(find /mnt/disk*/"$S"/old -type f 2>/dev/null | wc -l)
((fs == 3)) && h_cell future-stays 1 "the 3 files from the future still on the pool" || h_cell future-stays 0 "$fa of the 3 files from the future moved to the array as «older than 30 days»"
((os == 2)) && h_cell old-moves 1 "the 2 files of 40 days on the array (her rule works)" || h_cell old-moves 0 "$os of the 2 old files on the array"
grep -qiE '"result":"(failed|error)"' <<<"$r$h" && h_cell run-ok 0 "her run: $r $h" || h_cell run-ok 1 "her run: $r"
z=$( { agent_log_since run; tail -300 "$D/moverelli/smart_mover.log" 2>/dev/null; } | grep -iE 'future|negative|in der zukunft|clock' | head -2)
w=$(jq -c '[.. | strings | select(test("future|zukunft|clock"; "i"))] | unique | .[0:3]' "$D/moverelli.json" 2>/dev/null)
# since the fix (batch H7): her status counts them per share (`future`) — her page's last run (state.last = this status)
# says it once (run_future); her history keeps it too (noted, not judged: the history row may lag a tick)
fu=$(jq -c --arg s "$S" '[.shares[]? | select(.share == $s) | .future // 0][0] // 0' "$D/moverelli/status.json" 2>/dev/null)
fh=$(jq -c --arg s "$S" '[.history[0].status.shares[]? | select(.share == $s) | .future // 0][0] // 0' "$D/moverelli.json" 2>/dev/null)
((${fu:-0} >= 3)) && w="her last run's status: future $fu (history: future ${fh:-none})"
[[ $w != '[]' && -n $w ]] && h_cell said 1 "her state says: $w" \
    || h_cell said 0 "nothing on her page says 3 files carry times 30 days in the future (state: none; logs: ${z:-nothing})"
expect_changes "share|$S|*"
check standard
scenario_end
