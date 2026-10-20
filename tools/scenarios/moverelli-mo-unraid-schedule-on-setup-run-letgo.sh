#!/bin/bash
# Cell M10 of matrix.md: Unraid's own mover on a schedule (MO-UNRAID-SCHEDULE-ON «40 3 * * *», set through Unraid's
# form). Her «Run now» is refused (moverelli_schedule); her setup's save without the page's consent is refused and
# writes nothing; with the consent (mover_off: true) Unraid's schedule goes off through its own form (share.cfg
# shareMoverSchedule="", the emcmd syslog line) and her real run goes; her let-go puts Unraid's schedule back exactly
# («40 3 * * *»). The let-go fires her and takes her cron line: re-hired and her schedule put back in the undo
# (INV-SETTINGS allowed for that: it is what the let-go does). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-unraid-schedule-on-setup-run-letgo
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
sched() { _st_share_cfg_val shareMoverSchedule; }
mycron=$(grep -h 'job.sh moverelli' "$OFFICE_FLASH"/*.cron 2>/dev/null | head -1 | awk '{print $1,$2,$3,$4,$5}')
# the undo, after the state's (pushed later: runs first): her back, her schedule as it was
undo_push flow MV-SCHEDULE "cron=$mycron"
undo_push bash -c 'jq -e ".hired | has(\"moverelli\")" '"$D"'/office/staff.json >/dev/null 2>&1 || php '"$SC_DIR"'/ask.php office.hire "{\"desks\":[\"moverelli\"]}" >/dev/null'
state MO-UNRAID-SCHEDULE-ON '40 3 * * *'
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_schedule
md0=$(md5sum <"$D/moverelli/smart_mover.ini")
flow MV-SETUP refuse=ok
k=$(ask_last '.error.key // empty')
[[ -n $k && $(sched) == '40 3 * * *' && $(md5sum <"$D/moverelli/smart_mover.ini") == "$md0" ]] \
    && cell no-consent-no-change 1 "her save without the consent refused ($k), nothing written, Unraid's schedule as it was" \
    || cell no-consent-no-change 0 "her save without the consent: ${k:-accepted}; Unraid's schedule «$(sched)»"
lm=$(wc -l </var/log/syslog)
flow MV-SETUP mover_off=1
s=$(sched)
form=$(tail -n +"$lm" /var/log/syslog | grep -c 'emcmd\|shareMoverSchedule\|update_cron' )
[[ -z $s ]] && cell consent-off 1 "with the consent Unraid's schedule is «Disabled» (share.cfg shareMoverSchedule=\"\"; syslog lines of the form: $form)" \
    || cell consent-off 0 "with the consent Unraid's schedule is still «$s»"
flow MV-RUN
flow MV-LETGO
s=$(sched)
[[ $s == '40 3 * * *' ]] && cell letgo-back 1 "her let-go put Unraid's schedule back: «$s»" || cell letgo-back 0 "after her let-go Unraid's schedule is «$s» (was «40 3 * * *»)"
allow INV-SETTINGS "her let-go fires her (staff.json) and takes her cron line — what MV-LETGO does (CLAUDE.md: «her let-go puts it back»; firing hides a desk, data stays); the undo hires her again"
allow INV-CRON-ONE "her let-go took her cron line on purpose; put back in the undo"
check standard
scenario_end
