#!/bin/bash
# Cell B42 of matrix.md (wave 3): Mr. Backupsy's settings.ini gone (IN-INI-MISSING: a hand, a restore of appdata), the
# engine's state stays. What must hold (zustaende.md IN-INI-MISSING):
#   history     his page still lists the runs before (backup.json's history readable, not emptied)
#   replan      «Look at the server again» makes a plan from the server — no settings_error, his setup opens
#   not-set-up  his look says he isn't set up (the page's setup step), not «the backup failed» before anything ran
#   run-said    the plugin's cron line at its time (BK-RUN-SCHED), two nights: no run starts (engine 2.82, --scheduled) —
#               status.json keeps the run before, his history gets ONE «skipped» line with the code settings_missing that
#               counts 2 after the second night (backup.message.settings_missing on the page, never the engine's English
#               naming a script); the page's look is checked clean (INV-PAGE: no script name)
#   no-alert    no notification of Mr. Backupsy's for the skipped nights (up to 2.81: a failed run with an alert a night)
#   skip-row    his page lists the skip apart from the runs: reason settings_missing, count 2
#   todo        the Team Lead's must «Mr. Backupsy is set up» is open (checks.backup setup: required, not ok)
#   packages    his packages tile still lists the packages, found without settings (packages.from not «settings»)
# The settings come back with the undo. Partner sections (partners-kept.ini) are n/a here: Tower's base has no
# partner. Pre-release candidate (~4 min).
# run-late: 2 the skipped line stays in his history until the base reset (INV-PAGE of every later scenario sees it)
. "$(dirname "$0")/lib.sh"
scenario_begin backup-in-ini-missing-replan-sched
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup                    # a run before: the history the page must keep showing
h0=$(state_json backup '[.history[]? | .run] | length' 2>/dev/null)
say "his history before: ${h0:-?} runs"
state IN-INI-MISSING
snap_before
h1=$(state_json backup '[.history[]? | .run] | length' 2>/dev/null)
[[ -n $h1 && $h1 -gt 0 && $h1 -ge ${h0:-1} ]] && cell history 1 "his page lists $h1 runs with settings.ini gone" \
    || cell history 0 "his page lists ${h1:-no} runs with settings.ini gone (${h0:-?} before)"
sf=$(jq -r '.settings_found' "$D/backup.json" 2>/dev/null)
[[ $sf == false ]] && cell not-set-up 1 "his look: settings_found=false" || cell not-set-up 0 "his look with settings.ini gone: settings_found=${sf:-?}"
if flow BK-REPLAN; then
    jq -e '[.messages[]? | .code] | index("settings_error") | not' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
        && cell replan 1 "a plan from the server: $(jq -c '[.messages[]?.code] | unique' "$KEEP/plan-replan.json" | head -c 300)" \
        || cell replan 0 "settings_error with settings.ini gone: $(jq -c '[.messages[]? | select(.code == "settings_error")]' "$KEEP/plan-replan.json" | head -c 300)"
else
    cell replan 0 "«Look at the server again» didn't end with a plan"
fi
ask_save_post backup.setup_get '{}' 120 >/dev/null
bubble_look ini-missing backup
look=$(ls -d "$KEEP"/looks/*-ini-missing | tail -1)
queue_mac not-set-up page_text backup "$look/states" "$PAGE_LANGS" want "backup.notice.no_settings"
# the plugin's cron line, as crond starts it at its time — two nights
minute_wait
r0=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
hl0=$(wc -l <"$UB/state/history.jsonl" 2>/dev/null || echo 0)
nt0=$(ls "$NOTIFY_DIR"/unread "$NOTIFY_DIR"/archive 2>/dev/null | sort)
for night in 1 2; do
    job backup
    job_wait backup 600
    wait_for 120 "the engine to end" bash -c "! pgrep -f '[b]ackup/backup.sh' >/dev/null"
done
hl=$(wc -l <"$UB/state/history.jsonl" 2>/dev/null || echo 0)
last=$(tail -n 1 "$UB/state/history.jsonl" 2>/dev/null)
evidence "$UB/state/history.jsonl" history-sched.jsonl >/dev/null
evidence "$UB/state/status.json" status-sched.json >/dev/null
say "two scheduled starts with settings.ini gone: history $hl0 → $hl lines, the last: $(jq -c '{result, reason, count, first, time}' <<<"$last" 2>/dev/null)"
r1=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
if [[ $r1 == "$r0" ]] && jq -e '.result == "skipped" and .reason == "settings_missing" and .count == 2' <<<"$last" >/dev/null 2>&1 && ((hl == hl0 + 1)); then
    cell run-said 1 "no run started without settings (status.json still $r0), one «skipped» line settings_missing counted 2 over two nights"
else
    cell run-said 0 "status.json $r0 → $r1, history $hl0 → $hl lines, the last: $(head -c 300 <<<"$last")"
fi
new=$(comm -13 <(printf '%s\n' "$nt0") <(ls "$NOTIFY_DIR"/unread "$NOTIFY_DIR"/archive 2>/dev/null | sort) | sed '/^$/d')
said=""
for f in $new; do
    f=$(ls "$NOTIFY_DIR"/{unread,archive}/"$f" 2>/dev/null | head -1)
    [[ -f $f ]] && grep -qi 'backup' "$f" && said+="$(_ini_value "$f" subject); "
done
[[ -z $said ]] && cell no-alert 1 "no notification about the backup for the two skipped nights" || cell no-alert 0 "notified: $said"
sk=$(state_json backup '[.skips[]? | select(.reason == "settings_missing")][0] | [.reason, .count] | @tsv' 2>/dev/null)
[[ $sk == $'settings_missing\t2' ]] && cell skip-row 1 "his history lists the skip apart: settings_missing, 2 times" || cell skip-row 0 "his skips: ${sk:-none}"
pk=$(state_json backup '.packages | [(.from // "settings"), ((.apps // []) | length), ((.vms // []) | length)] | @tsv' 2>/dev/null)
IFS=$'\t' read -r pf pa pv <<<"$pk"
[[ $pf != settings && $((pa + pv)) -gt 0 ]] && cell packages 1 "his packages tile: $pa apps, $pv VMs, found without settings ($pf)" \
    || cell packages 0 "his packages tile with settings.ini gone: from ${pf:-?}, ${pa:-0} apps, ${pv:-0} VMs"
td=$(state_json caretaker '[.checks.backup[]? | select(.id == "setup")][0] | [.level, .ok] | @tsv' 2>/dev/null)
[[ $td == $'required\tfalse' ]] && cell todo 1 "the Team Lead's must «set up» is open" || cell todo 0 "the Team Lead's setup check: ${td:-none}"
bubble_look ini-missing-run backup
check standard
scenario_end
