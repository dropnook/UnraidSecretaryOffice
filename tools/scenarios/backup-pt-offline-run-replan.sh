#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-OFFLINE: USOPartner out of reach from Tower (iptables DROP on Tower's
# OUTPUT to 192.168.7.190). The scratch share uso-wip-g-pt (a dataset on cache) is set to «local + partner» by Apply and
# sent once before the state (the first copy at USOPartner).
# - BK-RUN: `partner_missed` (why unreachable) as the one warning, the rest ok — the share's local snapshot made, the
#   run «with warnings», never failed, done in minutes (ssh gives up after 15 s).
# - The Team Lead's card: the partner «couldn't reach it» after his «Look again» (caretaker.partner_ping); his finding
#   partner_silent waits 6 hours of silence (PARTNER_SILENT_AFTER, agent/lib/partner.php:67) — here it is still «in
#   place»; the 6 hours themselves: backup-pt-offline-silent-6h (nightly).
# - Mr. Restori's partner look from his tick, never from the page: his page's look (restore.refresh) and «Look again»
#   (restore.partner_look) answer at once; his look job (atd, from the tick) writes «not reachable» into held.json.
# - BK-REPLAN: the plan keeps the share at «local + partner» (no partner_gone, nothing to Apply for it).
# The undo: the share, settings.ini, what USOPartner received of the share, both sides' partner files, then the partner
# base of both servers compared with before (pt_base_same). Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-offline-run-replan
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
pt_keep G-B39-OFF || _row state partner-keep red "both sides' partner files could not be kept"
undo_push pt_back G-B39-OFF
undo_push pt_drop_received "$S"
mkdir -p "$KEEP_STASH/G-B39-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/G-B39-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/G-B39-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed                                   # Unraid's «Add Share» opened Nextcloud's data folder again (backup-sh-add-share-nc-0777-run)
undo_push scratch_drop
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
grep -A12 "^\[share \"\?$S\"\?\]" "$UB/settings.ini" | grep -q "^partner *= *$ID" || _row state prepare red "settings.ini: no «partner = $ID» for $S after Apply"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run (the first copy at USOPartner) failed"
[[ $(jq -r '[.partner_ok // [] | .[]? | tostring] | length' "$UB/state/status.json") != 0 || $(jq -r '.partner_ok // 0' "$UB/state/status.json") != 0 ]] \
    || _row state prepare red "the first run sent nothing to USOPartner: $(jq -c '{result, partner_ok, partner_skipped, partner_failed}' "$UB/state/status.json")"
state PT-OFFLINE
track_share "$S"
snap_before
# 1. a backup run while USOPartner is out of reach
t0=$SECONDS
flow BK-RUN-MANUAL mode=backup
trun=$((SECONDS - t0)) st=$UB/state/status.json
evidence "$st" status-offline.json >/dev/null
items=$(jq -c '[.warning_items[]? | {code, why: .params.why}]' "$st")
pm=$(jq -c --arg n USOPartner '[.warning_items[]? | select(.code | startswith("partner"))]' "$st")
[[ $(jq -r .result "$st") == warnings ]] && cell run-warnings 1 "the run ended «with warnings» in $trun s" || cell run-warnings 0 "the run: $(jq -c '{result, errors, warnings, message}' "$st")"
[[ $(jq 'length' <<<"$pm") == 1 && $(jq -r '.[0].code' <<<"$pm") == partner_missed && $(jq -r '.[0].params.why' <<<"$pm") == unreachable ]] \
    && cell partner-missed 1 "one partner warning: partner_missed, why unreachable ($(jq -c '.[0].params' <<<"$pm"))" \
    || cell partner-missed 0 "the partner warnings: $pm"
[[ $(jq '[.warning_items[]? | select(.code | startswith("partner") | not)] | length' "$st") == 0 ]] \
    && cell rest-ok 1 "no other warning ($items)" || cell rest-ok 0 "other warnings too: $items"
zfs list -H -t snapshot -o name "cache/$S" | grep -q "@uso-backup-$(jq -r .run "$st")\$" \
    && cell local-snapshot 1 "cache/$S@uso-backup-$(jq -r .run "$st") made" || cell local-snapshot 0 "no snapshot of this run on cache/$S"
((trun <= 600)) && cell run-quick 1 "$trun s (ssh gives up after 15 s)" || cell run-quick 0 "the run took $trun s"
# 2. the Team Lead: «Look again» on the partner's card
# the card's «Look again» while another page asks the agent something: the agent answers that at once (office.ping —
# a request every page sends) or it stands still while the ping's ssh waits for an unreachable partner
php "$SC_DIR/ask.php" --wait 90 caretaker.partner_ping "$(jq -cn --arg i "$ID" '{id: $i}')" >"$KEEP/ping-card.json" 2>&1 &
pp=$!
sleep 2
s0=$(date +%s%N); php "$SC_DIR/ask.php" --wait 60 office.ping '{}' >/dev/null 2>&1; ms=$((($(date +%s%N) - s0) / 1000000))
wait "$pp"
((ms <= 4000)) && cell agent-not-held 1 "another request answered in $ms ms during the card's look" \
    || cell agent-not-held 0 "another request waited $ms ms while the card's look asked the unreachable partner (agent/lib/partner.php:2123 partnerAskAndKeep → partnerSsh run() in the agent's loop)"
pa=$(jq -c '.ask // {}' "$KEEP/ping-card.json" 2>/dev/null)
[[ $(jq -r '.reachable' <<<"$pa") == false ]] && cell lead-unreachable 1 "the card's look: $pa" || cell lead-unreachable 0 "the card's look: $pa"
ask caretaker.refresh '{}' 120 >/dev/null
f=$(state_json caretaker "[.. | objects | select(.id? == \"partner_silent\")] | .[0] // empty")
[[ -n $f && $(jq -r '.ok' <<<"$f") == true ]] && cell lead-finding-waits 1 "partner_silent in place until 6 h of silence: $(jq -c '{ok, params}' <<<"$f")" \
    || cell lead-finding-waits 0 "partner_silent: ${f:-not in his checks}"
expect_bubble caretaker partner.unreachable
# 3. Mr. Restori: his page's look and «Look again» answer at once; the look itself from his tick (atd)
t1=$(date +%s); s0=$SECONDS
ask restore.refresh '{}' 120 >/dev/null; tr=$((SECONDS - s0)); s0=$SECONDS
ask restore.partner_look '{}' 60 >/dev/null; tl=$((SECONDS - s0))
((tr <= 10 && tl <= 5)) && cell restore-page-quick 1 "his page's look $tr s, «Look again» $tl s — no ssh in either" \
    || cell restore-page-quick 0 "his page's look took $tr s, «Look again» $tl s (an ssh to an unreachable partner waits 15 s)"
if wait_for 240 "Mr. Restori's look at USOPartner" _pt_looked_since "$ID" "$t1"; then
    h=$(jq -c '{reachable, why, tried}' "$D/partner/$ID/held.json")
    [[ $(jq -r .reachable <<<"$h") == false && $(jq -r .why <<<"$h") =~ ^(unreachable|timeout)$ ]] \
        && cell restore-look-tick 1 "his look job (from the tick) wrote: $h" || cell restore-look-tick 0 "held.json: $h"
else
    cell restore-look-tick 0 "his look never ran within 240 s of «Look again»"
fi
# 4. Mr. Backupsy's setup looks again: the share stays «local + partner»
flow BK-REPLAN
pl=$KEEP/plan-replan.json
codes=$(jq -c '[.messages[]?.code]' "$pl")
jq -e --arg k "share|$S|partner" --arg i "$ID" '(.P[$k] // []) | index($i) != null' "$pl" >/dev/null \
    && cell replan-keeps 1 "the plan keeps $S at «local + partner» ($ID); messages $codes" \
    || cell replan-keeps 0 "the plan's $S: $(jq -c --arg k "share|$S|partner" '.P[$k]' "$pl"); messages $codes"
jq -e '[.messages[]?.code] | any(. == "partner_gone" or . == "partner_anew") | not' "$pl" >/dev/null \
    && cell replan-no-gone 1 "no partner_gone/partner_anew" || cell replan-no-gone 0 "the plan says $codes"
bubble_look after-offline backup caretaker restore
expect_warnings partner_missed
check standard
check final
scenario_end
