#!/bin/bash
# Cell M24 of matrix.md (batch E), TM-DST autumn: her joint run scheduled at 02:59 (MV-SCHEDULE, prepared) in the night
# of 2026-10-25 in a zone with summer time (Europe/Berlin — USOTest3VM's own zone file is missing, the state links one
# for its time; helper task/scenarios-helper-tm-dst-zone), the clock two minutes before 03:00 CEST, ntpd stopped. crond
# starts her job at 02:59 CEST and — an hour later — at the second 02:59 (CET): one joint run for the night, never a
# second one at the doubled minute and never a failed one (cells one-run, not-failed: her history). She is set up for
# the scenario (her page's save, with its consent to switch Unraid's mover off) and put back as the base has her.
# Nightly (~70 min: the hour between the two 02:59s is waited for; the clock guard holds the set clock 90 min).
# run-late: 4 the clock set on: her history dated ahead stays until a base reset
# timeout: 5400 the clock goes through the repeated hour in real time (~64 min, batch E)
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-tm-dst-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
hist() { php "$SC_DIR/ask.php" --wait 60 moverelli.refresh '{}' >/dev/null 2>&1; jq '(.history // []) | length' "$D/moverelli.json" 2>/dev/null || echo 0; }
export USO_CLOCK_MAX=5400
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
flow MV-SCHEDULE cron='59 2 * * *' || _row state prepare red "her schedule 59 2 * * * not saved"
state TM-DST autumn Europe/Berlin
say "the server: $(date '+%F %T %Z') ($(date -u '+%T UTC'))"
snap_before
h0=$(hist)
# the first 02:59 (CEST): crond's own start, or — when crond, one minute after the clock was set, lets that minute pass
# (seen 2026-10-19 with Mr. Backupsy's line: dcron skips the minute after a jump of days) — the job as crond starts it
wait_for 180 "02:59:20 CEST" bash -c '[[ $(date +%H%M%S%Z) > 025919CEST && $(date +%Z) == CEST ]]'
if pgrep -f '[a]gent\.php job moverelli' >/dev/null || [[ $(stat -c %Y "$D/moverelli/night.json" 2>/dev/null || echo 0) -ge $(( $(date +%s) - 30 )) ]]; then say "crond started the first 02:59 itself"
else say "crond let the first 02:59 pass — the job started as crond would"; flow MV-RUN-SCHED; fi
wait_night 1200
h1=$(hist)
wait_for 4200 "the second 02:59 (CET) to pass" bash -c "[[ \$(date +%H%M%Z) == 0301CET ]]"
sleep 30; wait_night 1200
h2=$(hist)
say "her history rows: $h0 → $h1 (after the first 02:59) → $h2 (after the second)"
(( h1 == h0 + 1 && h2 == h1 )) && cell one-run 1 "one joint run for the night" || cell one-run 0 "her runs: $((h1 - h0)) at the first 02:59, $((h2 - h1)) at the second"
r=$(jq -c '(.history // [])[0] | {result, why}' "$D/moverelli.json" 2>/dev/null)
[[ $(jq -r '.result // ""' <<<"$r") != failed ]] && cell not-failed 1 "her last run: $r" || cell not-failed 0 "her last run: $r"
allow INV-LEFTOVER "the clock state's own note and guard (/tmp/uso-clock-*.set, uso-clock-guard.sh — states-time.sh) live while the clock is set; its undo removes them (INV-LEFTOVER after the undo)"
check standard
scenario_end
