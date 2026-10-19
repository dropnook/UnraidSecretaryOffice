#!/bin/bash
# Cell B20 of matrix.md: two backup runs at once, both ways round. (a) The plugin's cron line starts the run (job.sh
# backup, RN-TWO-RUNS), then «Back up now» while it works: refused (backup_running) or skipped by the engine — never a
# second run. (b) «Back up now», then the cron line's job while that run works: the engine skips it (exit 75,
# state/skipped.json) and his page lists that skip once (the button in (a) may be skipped by the engine too, when the
# agent's look came before the run's lock note: one skip row per skipped start, never more). Afterwards the history
# holds exactly the two real runs, each minute once — no double row, no second run of a minute. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-two-runs-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
runs() { state_json backup '[.history[]? | .run] | join(" ")' 2>/dev/null; }
skips() { state_json backup '[.skips[]? | .time] | length' 2>/dev/null; }
nc_data_closed
# the base again when the office left Nextcloud in maintenance mode (its checks are red then): base.sh doesn't reset it, and
# every later run would refuse («already in maintenance mode») — no helper for it, occ as Nextcloud's own user
nc_maint_off() { timeout 60 docker exec -u 33 Nextcloud php occ maintenance:mode 2>/dev/null | grep -qi enabled || return 0
    say "undo: Nextcloud was left in maintenance mode — switched off"; timeout 60 docker exec -u 33 Nextcloud php occ maintenance:mode --off >/dev/null; }
undo_push nc_maint_off
snap_before
runs0=$(runs) skips0=$(skips)
# (a) the cron's run first, the button while it works
_bk_setup_free && minute_wait
state RN-TWO-RUNS backup
a=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    k=$(ask_last 'if .skipped then "skipped:\(.skipped.reason // "?")" elif .queued then "queued" elif .started then "started" else "handed over" end')
    say "(a) «Back up now» while the cron's run works was accepted: $k"
else
    k="refused:$(ask_last '.error.key')"
fi
[[ $k == refused:backup_running || $k == skipped:* || $k == queued ]] && cell a-second-refused 1 "the button while the cron's run works: $k" \
    || cell a-second-refused 0 "the button while the cron's run works: $k"
second_run_wait RN-TWO-RUNS 1800
wait_backup 1800
# (b) the button first, the cron's job while it works
minute_wait
ask backup.start '{"mode":"backup"}' >/dev/null
expect_ok "backup.start (b)"
wait_for 120 "the button's run to be at work" lock_held "$UB/state/lock"
b=$(jq -r '.run // ""' "$UB/state/status.json" 2>/dev/null)
t0=$(_sc_now)
job backup && job_wait backup 300
sk=$(jq -c '{time, reason, holder: (.holder // null)}' "$UB/state/skipped.json" 2>/dev/null)
[[ $(jq -r '.time // 0' <<<"$sk") -ge $((t0 - 1)) ]] && cell b-cron-skipped 1 "the cron's job while the button's run works: skipped ($sk)" \
    || cell b-cron-skipped 0 "no skip noted for the cron's job (state/skipped.json: ${sk:-none})"
wait_backup 1800
runs1=$(runs) skips1=$(skips)
new=$(comm -13 <(tr ' ' '\n' <<<"$runs0" | sed '/^$/d' | sort) <(tr ' ' '\n' <<<"$runs1" | sed '/^$/d' | sort))
dup=$(tr ' ' '\n' <<<"$runs1" | sed '/^$/d' | sort | uniq -d)
nnew=$(sed '/^$/d' <<<"$new" | wc -l)
[[ $nnew == 2 && -z $dup ]] && cell history-two 1 "two new runs in the history ($(paste -sd' ' <<<"$new")), none twice" \
    || cell history-two 0 "new runs in the history: ${new//$'\n'/ } (expected the two of $a and $b)${dup:+; twice: $dup}"
# one skip per collision the engine skipped: (b) always, (a) when the agent let the button through to the engine
want=$(( 1 + ( ${k:-} == skipped:* ? 1 : 0 ) ))
(( skips1 - skips0 == want )) && cell skip-once 1 "his page lists each skipped start once ($want)" || cell skip-once 0 "skips on his page: $skips0 → $skips1 (expected $want more)"
expect_bubble backup bubble.skipped
check standard
check final
scenario_end
