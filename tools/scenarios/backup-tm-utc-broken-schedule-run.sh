#!/bin/bash
# Cell B43 of matrix.md (batch E), TM-UTC-BROKEN (USOTest3VM's base: ident.cfg says Europe/Zurich, its zone file is
# missing on 7.3/7.4, so the system — crond, the engine, `date` — runs UTC). The schedule dialog's Save (BK-SCHEDULE,
# the base's 30 4 * * *) and a run as crond starts it (BK-RUN-SCHED). What the office says must be the server's truth:
# the «next run» it shows (backup.json schedule.next, an epoch the page writes in the reader's time) is the moment crond
# really starts the job (the next 04:30 of the system's clock — cell next-is-cron), the run is named by the server's
# clock (cell run-name) and «last run» is now, not hours away (cell last-now). The browser's zone is the reader's: an
# epoch drawn in it is right as long as the epoch is. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-tm-utc-broken-schedule-run
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cron_next() {    # <m> <h>: the next time crond (the system's clock) starts «m h * * *»
    local t; t=$(date -d "today $2:$1" +%s); (( t <= $(date +%s) )) && t=$(date -d "tomorrow $2:$1" +%s); echo "$t"
}
state TM-UTC-BROKEN
say "the server: $(date '+%F %T %Z'), ident.cfg timeZone $(tr -d '\r' </boot/config/ident.cfg | sed -n 's/^timeZone=//p'), PHP $(php -r 'echo date_default_timezone_get();')"
snap_before
flow BK-SCHEDULE cron='30 4 * * *'
ask backup.refresh '{}' 120 >/dev/null
n=$(state_json backup '.schedule.next // empty'); c=$(cron_next 30 4)
[[ $n == "$c" ]] && cell next-is-cron 1 "next run $(date -d @"$n" '+%F %T %Z') = when crond starts it" \
    || cell next-is-cron 0 "the office says the next run is $(date -d @"${n:-0}" '+%F %T %Z'), crond starts it at $(date -d @"$c" '+%F %T %Z') ($(( (c - ${n:-0}) / 60 )) min apart)"
t0=$(date +%Y%m%d-%H%M)
flow BK-RUN-SCHED
r=$(jq -r '.run // ""' "$UB/state/status.json")
[[ $r == "$t0" || $r == "$(date -d "${t0:0:8} ${t0:9:2}:${t0:11:2} + 1 min" +%Y%m%d-%H%M)" ]] && cell run-name 1 "the run is named $r by the server's clock" \
    || cell run-name 0 "the run is named $r, the server's clock said $t0"
ask backup.refresh '{}' 120 >/dev/null
l=$(state_json backup '[.status.started?, .status.ended?, ((.history // [])[0] | objects | (.started // .time // .end // empty))] | map(select(type == "number")) | max // empty')
now=$(date +%s)
[[ -n $l ]] && (( now - l >= 0 && now - l < 900 )) && cell last-now 1 "«last run» $((now - l)) s ago" \
    || cell last-now 0 "«last run» at ${l:-?} ($(date -d @"${l:-0}" '+%F %T %Z')), now $(date '+%T %Z')"
check standard
scenario_end
