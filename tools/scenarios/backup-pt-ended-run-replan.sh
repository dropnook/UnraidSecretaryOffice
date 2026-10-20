#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-ENDED: the partnership with USOPartner ended at Tower's Team Lead («End the
# partnership»: the pair, its key, known_hosts and door line gone at Tower; USOPartner still keeps the pair — the user
# ended it on one side). The scratch share uso-wip-g-pt was at «local + partner» and sent once before.
# - BK-RUN: `partner_ended` as the one partner warning (nothing sent, apply the setup), the run «with warnings», never
#   failed; the share's local snapshot made.
# - BK-REPLAN: the setup says `partner_gone` (a hint), the share's partner level and the partner's section go in the Apply
#   list (expect_changes: share|uso-wip-g-pt|partner, partner|<id>|*) and nothing else; the plan names no partner.
# - The Team Lead keeps no card of the ended pair.
# The undo: both sides' partner files back (the pairing as before, the same id), the share, settings.ini, what USOPartner
# received of the share, the partner base compared. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-ended-run-replan
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
undo_push pt_drop_received "$S"
mkdir -p "$KEEP_STASH/G-B39-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/G-B39-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/G-B39-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed                                   # Unraid's «Add Share» opened Nextcloud's data folder again (backup-sh-add-share-nc-0777-run)
undo_push scratch_drop
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run (the first copy at USOPartner) failed"
state PT-ENDED                                   # keeps both sides' partner files first (its undo puts them back)
track_share "$S"
snap_before
# 1. a backup run after the end
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
evidence "$st" status-ended.json >/dev/null
pm=$(jq -c '[.warning_items[]? | select(.code | startswith("partner"))]' "$st")
items=$(jq -c '[.warning_items[]? | .code]' "$st")
[[ $(jq -r .result "$st") == warnings ]] && cell run-warnings 1 "the run ended «with warnings»" || cell run-warnings 0 "the run: $(jq -c '{result, errors, warnings, message}' "$st")"
[[ $(jq 'length' <<<"$pm") == 1 && $(jq -r '.[0].code' <<<"$pm") == partner_ended ]] \
    && cell partner-ended-said 1 "partner_ended ($(jq -c '.[0].params' <<<"$pm")), all: $items" || cell partner-ended-said 0 "the partner warnings: $pm (all: $items)"
zfs list -H -t snapshot -o name "cache/$S" | grep -q "@uso-backup-$(jq -r .run "$st")\$" \
    && cell local-snapshot 1 "cache/$S@uso-backup-$(jq -r .run "$st") made" || cell local-snapshot 0 "no snapshot of this run on cache/$S"
# 2. the setup looks again
flow BK-REPLAN
pl=$KEEP/plan-replan.json
codes=$(jq -c '[.messages[]?.code]' "$pl")
jq -e '[.messages[]?.code] | index("partner_gone") != null' "$pl" >/dev/null && cell setup-partner-gone 1 "the setup says partner_gone: $(jq -c '[.messages[]? | select(.code == "partner_gone") | .params]' "$pl")" \
    || cell setup-partner-gone 0 "the plan's messages: $codes"
[[ $(jq -r --arg k "share|$S|partner" '(.P[$k] // []) | length' "$pl") == 0 ]] && cell setup-unit-goes 1 "the plan sends $S to no partner any more" \
    || cell setup-unit-goes 0 "the plan's $S: $(jq -c --arg k "share|$S|partner" '.P[$k]' "$pl")"
[[ $(jq '(.partners // []) | length' "$pl") == 0 ]] && cell setup-no-card 1 "the plan names no partner" || cell setup-no-card 0 "the plan's partners: $(jq -c '[.partners[]? | {id, name}]' "$pl")"
expect_changes "share|$S|partner" "partner|$ID|*"      # the share's partner level and the ended partner's section go
tc=$(ask caretaker.partner_state '{}' 60 | jq -c '[.partners.pairs[]? | {id, name}]')
[[ $(jq --arg i "$ID" '[.[] | select(.id == $i)] | length' <<<"$tc") == 0 ]] && cell lead-no-card 1 "the Team Lead's cards: $tc" || cell lead-no-card 0 "the Team Lead still shows $ID: $tc"
bubble_look after-ended backup caretaker
expect_warnings partner_ended
check standard
check final
scenario_end
