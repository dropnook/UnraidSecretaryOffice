#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-OFFLINE, the Team Lead's finding: a partner silent for 6 hours
# (PARTNER_SILENT_AFTER, agent/lib/partner.php:67) is his finding partner_silent and one warning to Unraid's
# notifications (once per silence). Six hours can't be waited for: with USOPartner out of reach the clock is set 6 h
# 10 min ahead (ntpd stopped, put back by the undo — crond doesn't catch up a jump of more than an hour). Then his
# mutual watch (from his tick, every 15 min — due at once after the jump) finds the silence.
# - lead-finding: partner_silent red, «since» the last time it answered; the card «silent».
# - told-once: one notification «Partner office USOPartner is silent».
# - agent-beats: the agent's heartbeat fresh after the jump and after the clock came back (monotonic naps since 1.67.0).
# INV-TICK is allowed: the heartbeat's own times jump with the clock this scenario sets — not a stall (agent-beats says
# whether it kept beating). The undo: the clock back first, then the rule; both sides' partner files back (state.json's
# «told» and the looks' «tried» of the clock set ahead go with them), the partner base compared. Nightly (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-offline-silent-6h
needs tower partner
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
fresh() { (( $(date +%s) - $(stat -c %Y "$RUN/agent.json") < 25 )); }
silent_finding() { ask caretaker.refresh '{}' 60 >/dev/null 2>&1; state_json caretaker '[.. | objects | select(.id? == "partner_silent")] | .[0] // empty' | jq -e '.ok == false' >/dev/null 2>&1; }
pt_base_snap "$KEEP/ptbase" || _row state partner-base red "the partner base could not be read"
undo_push pt_base_same
pt_keep G-B39-SIL || _row state partner-keep red "both sides' partner files could not be kept"
undo_push pt_back G-B39-SIL
ID=$(pt_id)
state PT-OFFLINE
snap_before
heard=$(jq -r --arg i "$ID" '.pairs[$i].last_heard // empty' "$D/partner/state.json")
say "USOPartner last heard: $(date -d "@${heard:-0}" '+%F %T') — the clock goes to 6 h 10 min after now"
notes0=$(ls /tmp/notifications/unread 2>/dev/null | wc -l)
clock_set G-B39-SIL "@$(( $(date +%s) + 6 * 3600 + 600 ))" || _row state clock red "the clock could not be set ahead"
undo_push clock_restore G-B39-SIL
if wait_for 600 "the Team Lead's finding partner_silent" silent_finding; then
    f=$(state_json caretaker '[.. | objects | select(.id? == "partner_silent")] | .[0]')
    cell lead-finding 1 "partner_silent: $(jq -c '{ok, level, params}' <<<"$f")"
else
    cell lead-finding 0 "no partner_silent finding within 10 min: $(state_json caretaker '[.. | objects | select(.id? == "partner_silent")] | .[0] // "none"' | jq -c . 2>/dev/null)"
fi
expect_bubble caretaker partner.silent
wait_for 120 "the warning to Unraid's notifications" bash -c "grep -l 'is silent' /tmp/notifications/unread/* >/dev/null 2>&1"
n=$(grep -l 'USOPartner is silent' /tmp/notifications/unread/* 2>/dev/null | wc -l)
((n == 1)) && cell told-once 1 "one notification «Partner office USOPartner is silent» ($((notes0)) → $(ls /tmp/notifications/unread | wc -l) unread)" \
    || cell told-once 0 "$n notifications «… USOPartner is silent»"
fresh && a1=1 || a1=0
bubble_look silent caretaker
clock_restore G-B39-SIL
wait_for 60 "a fresh heartbeat after the clock came back" fresh && a2=1 || a2=0
((a1 && a2)) && cell agent-beats 1 "the heartbeat fresh with the clock ahead and after it came back" || cell agent-beats 0 "heartbeat fresh: ahead $a1, back $a2"
allow INV-TICK "the scenario sets the clock 6 h ahead and back: the heartbeat's times jump with it (agent-beats shows it kept beating)"
check standard
check final
scenario_end
