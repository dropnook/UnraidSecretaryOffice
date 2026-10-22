#!/bin/bash
# The USO network underneath a partnership (USOTest3VM and a second office in the lab's tailnet, states-usonet.sh):
# - pair over the nodes' 100.x addresses exactly as the Team Lead's dialogs do (UN-PAIRED): the pair's address is the
#   peer's node, the peer's door line carries from= this server's node address — nothing of the pairing changed;
# - «Ask now» reaches the peer's door through the network, and the door saw this node's real address (SSH_CONNECTION);
# - a real backup run with a scratch ZFS share at «local + partner»: its copy lands at the peer through the tunnel;
# - the helper down (UN-HELPER-DOWN): «Ask now» says unreachable, a run still makes its local snapshot and only warns
#   that the copy didn't arrive (partner_missed) — nothing local fails; back up, the peer answers again. (~6 min)
. "$(dirname "$0")/lib.sh"
scenario_begin caretaker-un-pair-run-down
needs test3 docker zfs
un_needs_lab
un_needs_peer
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-un-zfs
state UN-PAIRED || _row state un-paired red "the pairing over the USO network didn't happen"
ID=$(cat "$KEEP/un-pair.id" 2>/dev/null)
me=$(un_card '.ips[0]'); peer=$(un_peer_card '.ips[0]')

# 1. the pairing as it always is, over the nodes' addresses
addr=$(jq -r --arg i "$ID" '.pairs[] | select(.id == $i) | .address' "$D/partner/pairs.json")
line=$(un_peer_sh "grep -F 'uso-partner:$ID' /boot/config/ssh/root/authorized_keys")
[[ $addr == "$peer" && $line == "restrict,from=\"$me\",command=\""*"partner-door.sh $ID\" ssh-ed25519 "*" uso-partner:$ID" ]] \
    && cell paired-over-net 1 "pair $ID: address $addr, the peer's door line from=\"$me\"" || cell paired-over-net 0 "address $addr, line: ${line:0:120}"

# 2. «Ask now» through the network; the door saw this node's address
look=$(pt_card_ask "$ID" 90)
[[ $(jq -r .reachable <<<"$look") == true ]] && cell ask-reaches 1 "the card: $look" || cell ask-reaches 0 "the card: ${look:-no answer}"
heard=$(un_peer_sh "tail -n 20 \$(sed -n 's/^DATA_DIR=\"\\(.*\\)\"/\\1/p' /boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg)/partner/door.log" | grep -F "$me" | tail -1)
[[ -n $heard ]] && cell door-real-address 1 "the peer's door.log: ${heard:0:140}" || cell door-real-address 0 "no line from $me in the peer's door.log"

# 3. a real run: the scratch share's copy goes through the tunnel
state SH-ZFS "$S"
track_share "$S"
undo_push un_peer_sh "zfs destroy -r cache/UnraidSecretaryOffice-partners/$ID/share-$S 2>/dev/null; true"
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
snap_before
flow BK-RUN-MANUAL mode=backup
sent=$(jq -c --arg i "$ID" '[.partner.partners[]? | select(.id == $i) | .units[]? | {unit, result}]' "$UB/state/status.json" 2>/dev/null)
got=$(un_peer_sh "zfs list -H -t snapshot -o name -r cache/UnraidSecretaryOffice-partners/$ID 2>/dev/null | grep -c '@uso-backup-'")
[[ $got -ge 1 ]] && cell run-through-net 1 "the peer holds $got snapshot(s) of $S; the run: $sent" || cell run-through-net 0 "the peer holds none; the run: $sent"
expect_warnings

# 4. the helper down: unreachable, nothing local fails
state UN-HELPER-DOWN || _row state un-helper-down red "the helper couldn't be stopped"
look=$(pt_card_ask "$ID" 120)
[[ $(jq -r .reachable <<<"$look") == false ]] && cell down-unreachable 1 "the card: $look" || cell down-unreachable 0 "the card: ${look:-no answer}"
minute_wait
flow BK-RUN-MANUAL mode=backup
res=$(jq -r '.result // ""' "$UB/state/status.json")
miss=$(jq -c '[.warning_items[]? | select(.code == "partner_missed") | .params // .]' "$UB/state/status.json" 2>/dev/null)
snaps=$(zfs list -H -t snapshot -o name -r "$(zfs list -H -o name 2>/dev/null | grep -E "/$S\$" | head -1)" 2>/dev/null | grep -c '@uso-backup-')
[[ $res == warnings && $miss != '[]' && $snaps -ge 2 ]] && cell down-local-fine 1 "result warnings, partner_missed $miss, $snaps local snapshots" \
    || cell down-local-fine 0 "result $res, partner_missed $miss, $snaps local snapshots"
expect_warnings partner_missed
undo_un_helper_down
look=$(pt_card_ask "$ID" 120)
[[ $(jq -r .reachable <<<"$look") == true ]] && cell back-reaches 1 "the card: $look" || cell back-reaches 0 "the card: ${look:-no answer}"
allow INV-REPLAN "only the lab's container uso-lab-headscale is new to the setup (docker known/no_stop) — the test bed, not the flow"
check standard
scenario_end
