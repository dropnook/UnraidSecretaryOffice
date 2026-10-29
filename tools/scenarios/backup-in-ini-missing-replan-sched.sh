#!/bin/bash
# Cell B42 of matrix.md (wave 3): Mr. Backupsy's settings.ini gone (IN-INI-MISSING: a hand, a restore of appdata), the
# engine's state stays. What must hold (zustaende.md IN-INI-MISSING):
#   history     his page still lists the runs before (backup.json's history readable, not emptied)
#   replan      «Look at the server again» makes a plan from the server — no settings_error, his setup opens
#   not-set-up  his look says he isn't set up (the page's setup step), not «the backup failed» before anything ran
#   run-said    the plugin's cron line at its time (BK-RUN-SCHED): no run starts — or it ends with a reason the page
#               translates — a code (backup.message.<code>), never the engine's English text (which names a script:
#               «run setup.sh first» — CLAUDE.md: no internal script names on the page); the page's look is checked clean
#               (INV-PAGE: no script name)
# The settings come back with the undo. Partner sections (partners-kept.ini) are n/a here: Tower's base has no
# partner. Pre-release candidate (~3 min).
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
# the plugin's cron line, as crond starts it at its time
minute_wait
r0=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
job backup
job_wait backup 600
wait_for 120 "the engine to end" bash -c "! pgrep -f '[b]ackup/backup.sh' >/dev/null"
[[ $(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null) != "$r0" ]] || say "the scheduled job left no new status (the run before: $r0)"
st=$(jq -c '{run, result, message, message_code: (.message_code // .code // null)}' "$UB/state/status.json" 2>/dev/null)
evidence "$UB/state/status.json" status-sched.json >/dev/null
say "the scheduled run with settings.ini gone: $st"
msg=$(jq -r '.message // ""' "$UB/state/status.json" 2>/dev/null)
if [[ $(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null) == "$r0" ]]; then
    cell run-said 1 "no run started without settings (the job left the status of $r0) — his page says he isn't set up"
elif [[ -z $msg ]]; then
    cell run-said 0 "the run ended without a reason: $st"
elif jq -e --arg m "$msg" 'has("message." + $m)' "$P/desks/backup/lang/en.json" >/dev/null 2>&1; then
    cell run-said 1 "the run's reason is a code the page translates: $msg"
else
    cell run-said 0 "the run's reason is the engine's own text, shown on the page as it is: «$msg»"
fi
bubble_look ini-missing-run backup
# the run's failure on missing settings is this cell's own end: one alert of the engine (ub_tell) is expected
allow INV-NOTIFY-ONCE "the scheduled run without settings.ini ends failed — the engine's one notification of it is the cell's expected end"
check standard
scenario_end
