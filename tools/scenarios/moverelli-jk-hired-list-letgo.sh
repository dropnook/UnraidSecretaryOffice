#!/bin/bash
# Cell M28 of matrix.md: her let-go while Jack Streamer is hired (JK-HIRED-LIST) and Unraid's schedule was switched off
# by her setup (MO-UNRAID-SCHEDULE-ON, then her save with the consent). Jack Streamer first: her let-go is refused
# (moverelli_jack_hired) while he is hired — nothing changes; then his let-go (jack.letgo, his films left on the pool),
# then hers: Unraid's schedule back exactly («40 3 * * *»). Both re-hired as the base has them in the undo (Jack
# Streamer not: the base has him not). Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-hired-list-letgo
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
sched() { _st_share_cfg_val shareMoverSchedule; }
mycron=$(grep -h 'job.sh moverelli' "$OFFICE_FLASH"/*.cron 2>/dev/null | head -1 | awk '{print $1,$2,$3,$4,$5}')
state JK-HIRED-LIST                          # first: his setup saves her ini, refused while Unraid's schedule is on without consent
state MO-UNRAID-SCHEDULE-ON '40 3 * * *'
# her back first in the undo (pushed after the states: runs before them — Jack Streamer's undo saves her settings)
undo_push flow MV-SCHEDULE "cron=$mycron"
undo_push bash -c 'jq -e ".hired | has(\"moverelli\")" '"$D"'/office/staff.json >/dev/null 2>&1 || php '"$SC_DIR"'/ask.php office.hire "{\"desks\":[\"moverelli\"]}" >/dev/null'
flow MV-SETUP mover_off=1
_broken && { scenario_end; }
snap_before
[[ -z $(sched) ]] || say "note: Unraid's schedule still «$(sched)» after her setup with the consent"
flow MV-LETGO refuse=ok
k=$(ask_last '.error.key // empty')
[[ $k == moverelli_jack_hired ]] && has jack && [[ -z $(sched) ]] && cell jack-first 1 "her let-go refused while Jack Streamer is hired ($k), nothing changed" \
    || cell jack-first 0 "her let-go with Jack Streamer hired: ${k:-accepted}; Unraid's schedule «$(sched)»"
ask jack.letgo_look '{}' >/dev/null
ask jack.letgo '{"confirm":true,"release":false}' >/dev/null && ask office.fire '{"desk":"jack"}' >/dev/null
has jack && cell jack-gone 0 "Jack Streamer still hired after his let-go" || cell jack-gone 1 "Jack Streamer let go"
flow MV-LETGO
s=$(sched)
[[ $s == '40 3 * * *' ]] && cell letgo-back 1 "her let-go put Unraid's schedule back: «$s»" || cell letgo-back 0 "after her let-go Unraid's schedule is «$s» (was «40 3 * * *»)"
allow INV-SETTINGS "the let-gos fire both (staff.json) and take their cron lines — what MV-LETGO and Jack Streamer's let-go do; the undo hires her again"
allow INV-CRON-ONE "the let-gos took the cron lines on purpose; hers put back in the undo"
check standard
scenario_end
