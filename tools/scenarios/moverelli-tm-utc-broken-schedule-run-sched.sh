#!/bin/bash
# Cell M24 of matrix.md (batch E), TM-UTC-BROKEN (USOTest3VM's base: ident.cfg Europe/Zurich, its zone file missing —
# the system and crond run UTC). Her schedule dialog's Save (MV-SCHEDULE, 0 3 * * *) and the joint run as her cron line
# starts it (MV-RUN-SCHED). What her page says must be the server's truth: the next run it shows is the moment crond
# starts the job (cell next-is-cron), and «last run» right after her run is now — not «2 h ago» (cell last-now). She is
# set up for the scenario (her page's save, with its consent to switch Unraid's mover off) and put back as the base has
# her. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-tm-utc-broken-schedule-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cron_next() { local t; t=$(date -d "today $2:$1" +%s); (( t <= $(date +%s) )) && t=$(date -d "tomorrow $2:$1" +%s); echo "$t"; }
MVK=$KEEP_STASH/E-MV
mover_now() { tr -d '\r' </boot/config/share.cfg | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p'; }
# her setup as her page saves it, with its consent to switch Unraid's mover off (USOTest3VM's base keeps her not set up);
# undone by mv_put_back: her folder, the cron file and Unraid's mover schedule exactly as before
mv_set_up() {
    mkdir -p "$MVK" && tar -C "$D" -cf "$MVK/moverelli.tar" moverelli moverelli.json && cp -p "$OFFICE_FLASH/unraid-secretary-office.cron" "$MVK/cron" \
        && mover_now >"$MVK/mover" || return 1
    undo_push mv_put_back
    flow MV-SETUP x=80 mover_off=1 "$@"
}
mv_put_back() {
    [[ -f $MVK/moverelli.tar ]] || return 0
    rm -rf "$D/moverelli" && tar -C "$D" -xpf "$MVK/moverelli.tar" || return 1
    cp -p "$MVK/cron" "$OFFICE_FLASH/unraid-secretary-office.cron" && /usr/local/sbin/update_cron
    [[ $(mover_now) == "$(cat "$MVK/mover")" ]] || unraid_schedule_set "$(cat "$MVK/mover")" || return 1
    php "$SC_DIR/ask.php" --wait 60 moverelli.refresh '{}' >/dev/null 2>&1
    rm -rf "$MVK"
}
mv_set_up || _row state prepare red "her setup failed"
state TM-UTC-BROKEN
say "the server: $(date '+%F %T %Z'), ident.cfg timeZone $(tr -d '\r' </boot/config/ident.cfg | sed -n 's/^timeZone=//p')"
flow MV-SCHEDULE cron='0 3 * * *' || _row state prepare red "her schedule not saved"     # prepared: the cron file is the saved state
snap_before
ask moverelli.refresh '{}' 120 >/dev/null
n=$(jq -r '.schedule.next // empty' "$D/moverelli.json"); c=$(cron_next 0 3)
[[ $n == "$c" ]] && cell next-is-cron 1 "her next run $(date -d @"$n" '+%F %T %Z') = when crond starts it" \
    || cell next-is-cron 0 "her page says the next run is $( [[ -n $n ]] && date -d @"$n" '+%F %T %Z' || echo nothing), crond starts it at $(date -d @"$c" '+%F %T %Z')"
flow MV-RUN-SCHED
ask moverelli.refresh '{}' 120 >/dev/null
l=$(jq -r '[(.history // [])[0] | objects | (.finished // .ended // .end // .started // .time // empty)] | map(select(type == "number")) | first // empty' "$D/moverelli.json")
now=$(date +%s)
[[ -n $l ]] && (( now - l >= 0 && now - l < 900 )) && cell last-now 1 "«last run» $((now - l)) s ago" \
    || cell last-now 0 "«last run» at ${l:-?} ($( [[ -n $l ]] && date -d @"$l" '+%T %Z')), now $(date '+%T %Z') — $(jq -c '(.history // [])[0]' "$D/moverelli.json" | head -c 200)"
check standard
scenario_end
