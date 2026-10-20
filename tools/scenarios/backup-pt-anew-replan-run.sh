#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-ANEW: the partnership with USOPartner ended on both sides and paired anew
# under the same address (the Team Lead's three dialogs: Tower offers, USOPartner accepts with the receive rules it had,
# Tower pastes the answer with the safety code) — a new id. The scratch share uso-wip-g-pt was at «local + partner» of
# the old id and sent once before.
# - BK-REPLAN: the setup says `partner_anew` (a hint) and no partner_gone; the share's unit goes to the new id (taken
#   over); the plan names one partner, the new one — no two cards of the same name (findings 14/15), nor on either Team
#   Lead (Tower: one USOPartner, USOPartner: one Tower).
# - BK-APPLY of that plan as it is (the page's Apply without a change), then BK-RUN: sent to the new id at USOPartner
#   (a full send into …-partners/<new>/share-uso-wip-g-pt), no partner warning.
# The undo: both sides' partner files back (the old pairing, the same id b601d464 — exactly as before), what the new
# id received at USOPartner destroyed (only scratch units, then its empty parent), the share, settings.ini, the
# partner base compared. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-anew-replan-run
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
state PT-ANEW                                     # keeps both sides' partner files first (its undo puts them back)
NEW=$(cat "$KEEP/pt-anew.id" 2>/dev/null)
track_share "$S"
snap_before
# 1. the setup looks again
flow BK-REPLAN
pl=$KEEP/plan-replan.json
codes=$(jq -c '[.messages[]?.code]' "$pl")
jq -e '[.messages[]?.code] | index("partner_anew") != null and index("partner_gone") == null' "$pl" >/dev/null \
    && cell setup-partner-anew 1 "the setup says partner_anew: $(jq -c '[.messages[]? | select(.code == "partner_anew") | .params]' "$pl")" \
    || cell setup-partner-anew 0 "the plan's messages: $codes"
[[ $(jq -c --arg k "share|$S|partner" '.P[$k] // []' "$pl") == "[\"$NEW\"]" ]] && cell setup-taken-over 1 "$S goes to the new pairing $NEW" \
    || cell setup-taken-over 0 "the plan's $S: $(jq -c --arg k "share|$S|partner" '.P[$k]' "$pl") (new id ${NEW:-?})"
pc=$(jq -c '[.partners[]? | {id, name}]' "$pl")
[[ $(jq 'length' <<<"$pc") == 1 && $(jq -r '.[0].id' <<<"$pc") == "$NEW" ]] && cell setup-one-card 1 "one partner in the plan: $pc" || cell setup-one-card 0 "the plan's partners: $pc"
ask caretaker.refresh '{}' 120 >/dev/null
tc=$(ask caretaker.partner_state '{}' 60 | jq -c '[.partners.pairs[]? | .name]')
oc=$(pt_ask caretaker.partner_state '{}' 60 | jq -c '[.partners.pairs[]? | .name]')
[[ $tc == '["USOPartner"]' && $oc == '["Tower"]' ]] && cell lead-one-card 1 "Team Leads: Tower $tc, USOPartner $oc" || cell lead-one-card 0 "Team Leads: Tower $tc, USOPartner $oc"
expect_changes "share|$S|partner" "partner|*"     # the unit to the new id; the old section goes, the new one comes
# 2. Apply as the page shows it, then a run
flow BK-APPLY plan=old || cell apply 0 "Apply of the plan refused: $(ask_last '.error.key')"
grep -A12 "^\[share \"\?$S\"\?\]" "$UB/settings.ini" | grep -q "^partner *= *$NEW" && cell apply 1 "settings.ini: $S → partner $NEW" \
    || cell apply 0 "settings.ini's $S section: $(grep -A12 "^\[share \"\?$S\"\?\]" "$UB/settings.ini" | grep '^partner' | paste -sd' ')"
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
evidence "$st" status-anew.json >/dev/null
pm=$(jq -c '[.warning_items[]? | select(.code | startswith("partner"))]' "$st")
[[ $(jq -r .result "$st") =~ ^(ok|warnings)$ && $(jq 'length' <<<"$pm") == 0 ]] && cell run-sends 1 "the run: $(jq -c '{result, partner_ok, items: [.warning_items[]?.code]}' "$st")" \
    || cell run-sends 0 "the run: $(jq -c '{result, partner_ok, partner_skipped, partner_failed}' "$st"), partner warnings $pm"
r=$(pt_sh "zfs list -H -t snapshot -o name -r \$(zfs list -H -o name | grep -m1 '/UnraidSecretaryOffice-partners/$NEW\$')/share-$S 2>/dev/null | tail -1")
[[ $r == *"@uso-backup-$(jq -r .run "$st")" ]] && cell received-new 1 "USOPartner received it under the new pairing: $r" || cell received-new 0 "USOPartner's newest of $S under $NEW: «$r»"
bubble_look after-anew backup caretaker
check standard
check final
scenario_end
