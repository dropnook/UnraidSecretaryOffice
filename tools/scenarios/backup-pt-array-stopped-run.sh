#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-ARRAY-STOPPED: USOPartner's array stopped through its own emcmd (its
# office answers from RAM, the door receives nothing). The scratch share uso-wip-g-pt is set to «local + partner» and
# sent once before the state.
# - BK-RUN: `partner_missed` with why array_stopped as the one warning, said in this very run; the rest ok, the run
#   «with warnings», never failed. The catalogue's «nights counted» is gone since engine 2.46 (backup/backup.sh:80-82:
#   «the nights counter and state/partner-skips.json are gone», a partner that got nothing «is said in that run (no
#   nights of grace)» — Benj 2026-10-12): the cell holds the rule of today, the first run in the state warns.
# - The Team Lead's card says «array stopped» after «Look again»; Mr. Restori's look (from his tick) says array_stopped.
# The undo starts USOPartner's array whatever happened (and waits for its agent), the share and settings.ini go, both
# sides' partner files and the partner base as before. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-array-stopped-run
needs tower zfs partner
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-g-pt
ID=$(pt_id)
scratch_drop() { local x; [[ $S == uso-wip-g-* ]] || return 1
    zfs list -H -r -t snapshot -o name "cache/$S" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    zfs list -H -t bookmark -o name "cache/$S" 2>/dev/null | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    return 0; }
pt_base_snap "$KEEP/ptbase" || _row state partner-base red "the partner base could not be read"
undo_push pt_base_same
pt_keep G-B39-AS || _row state partner-keep red "both sides' partner files could not be kept"
undo_push pt_back G-B39-AS
undo_push pt_drop_received "$S"
mkdir -p "$KEEP_STASH/G-B39-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/G-B39-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/G-B39-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed                                   # Unraid's «Add Share» opened Nextcloud's data folder again (backup-sh-add-share-nc-0777-run)
undo_push scratch_drop
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run (the first copy at USOPartner) failed"
state PT-ARRAY-STOPPED
cp -p "$D/partner/pairs.json" "$KEEP_STASH/G-B39-AS/pairs.before"
track_share "$S"
snap_before
# 1. a backup run while USOPartner's array is stopped
t0=$SECONDS
flow BK-RUN-MANUAL mode=backup
trun=$((SECONDS - t0)) st=$UB/state/status.json
evidence "$st" status-array-stopped.json >/dev/null
items=$(jq -c '[.warning_items[]? | {code, why: .params.why}]' "$st")
pm=$(jq -c '[.warning_items[]? | select(.code | startswith("partner"))]' "$st")
[[ $(jq -r .result "$st") == warnings ]] && cell run-warnings 1 "the run ended «with warnings» in $trun s" || cell run-warnings 0 "the run: $(jq -c '{result, errors, warnings, message}' "$st")"
[[ $(jq 'length' <<<"$pm") == 1 && $(jq -r '.[0].code' <<<"$pm") == partner_missed && $(jq -r '.[0].params.why' <<<"$pm") == array_stopped ]] \
    && cell array-stopped-said 1 "one partner warning in this first run: partner_missed, why array_stopped ($(jq -c '.[0].params' <<<"$pm"))" \
    || cell array-stopped-said 0 "the partner warnings: $pm"
[[ $(jq '[.warning_items[]? | select(.code | startswith("partner") | not)] | length' "$st") == 0 ]] \
    && cell rest-ok 1 "no other warning ($items)" || cell rest-ok 0 "other warnings too: $items"
zfs list -H -t snapshot -o name "cache/$S" | grep -q "@uso-backup-$(jq -r .run "$st")\$" \
    && cell local-snapshot 1 "cache/$S@uso-backup-$(jq -r .run "$st") made" || cell local-snapshot 0 "no snapshot of this run on cache/$S"
# 2. the Team Lead's card and Mr. Restori's look
ask caretaker.partner_ping "$(jq -cn --arg i "$ID" '{id: $i}')" 90 >/dev/null
pa=$(ask_last '.ask // {}' | jq -c .)
[[ $(jq -r '.array' <<<"$pa") == stopped ]] && cell lead-array-stopped 1 "the card's look: $pa" || cell lead-array-stopped 0 "the card's look: $pa"
expect_bubble caretaker partner.array_stopped
t1=$(date +%s)
ask restore.partner_look '{}' 60 >/dev/null
if wait_for 240 "Mr. Restori's look at USOPartner" _pt_looked_since "$ID" "$t1"; then
    h=$(jq -c '{reachable, why, tried}' "$D/partner/$ID/held.json")
    [[ $(jq -r .why <<<"$h") == array_stopped ]] && cell restore-look 1 "his look (from the tick): $h" || cell restore-look 0 "held.json: $h"
else
    cell restore-look 0 "his look never ran within 240 s of «Look again»"
fi
# a ping it answers is heard: partner_ping writes last_heard and last_answer (the array «stopped») into pairs.json
# (agent/lib/partner.php:2095-2101 partnerPairUpdate) — nothing else of the pair may change
pj() { jq -S '[.pairs[] | del(.last_heard, .last_answer)]' "$1"; }
if diff <(pj "$KEEP_STASH/G-B39-AS/pairs.before") <(pj "$D/partner/pairs.json") >/dev/null 2>&1; then
    cell pairs-only-heard 1 "pairs.json: only last_heard/last_answer moved ($(jq -c '.pairs[0].last_answer' "$D/partner/pairs.json"))"
else
    cell pairs-only-heard 0 "pairs.json changed beyond last_heard/last_answer: $(diff <(pj "$KEEP_STASH/G-B39-AS/pairs.before") <(pj "$D/partner/pairs.json") 2>&1 | head -5 | paste -sd' ')"
fi
rm -f "$KEEP_STASH/G-B39-AS/pairs.before"
allow INV-SETTINGS "partner_ping heard USOPartner (its array stopped): pairs.json's last_heard/last_answer move (agent/lib/partner.php:2095-2101); pairs-only-heard checks nothing else changed"
bubble_look after-array-stopped backup caretaker restore
expect_warnings partner_missed
check standard
check final
scenario_end
