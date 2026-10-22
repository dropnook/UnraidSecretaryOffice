#!/bin/bash
# The USO network: join and leave (the Team Lead's «Join the USO network…» and «Leave…», the lab's Headscale on
# USOTest3VM). Joined: the card «in» with a 100.x address and tag:uso, the office's sshd only inside uso-net on the node's
# addresses (Unraid's port), the host's network byte for byte as before, the auth key gone from the flash and in no
# state, log or answer. Left: nothing of it on the server (namespace, flash, cron, RAM, processes) and the node logged
# out of the tailnet. (~2 min)
. "$(dirname "$0")/lib.sh"
scenario_begin caretaker-un-join-leave
needs test3 docker
un_needs_lab
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ $(un_card .state) == off ]] || { say "the network is joined already — left first"; un_leave; }
un_host_fp >"$KEEP/host-before"
snap_before
state UN-JOINED || _row state un-joined red "the join didn't reach «in»: $(un_card)"

card=$(un_card)
ip4=$(jq -r '.ips[0] // ""' <<<"$card")
[[ $(jq -r .state <<<"$card") == in && $ip4 == 100.* && $(jq -r .tagged <<<"$card") == true ]] \
    && cell joined 1 "in, $ip4, tag:uso" || cell joined 0 "the card: $card"
port=$(sed -n 's/^PORTSSH="\{0,1\}\([0-9]*\)"\{0,1\}.*/\1/p' /boot/config/ident.cfg); port=${port:-22}
inside=$(nsenter --net=/run/netns/uso-net ss -tlnH 2>/dev/null | awk '{print $4}' | sort | tr '\n' ' ')
[[ $inside == *"$ip4:$port"* ]] && cell door-inside 1 "the office's sshd in uso-net: $inside" || cell door-inside 0 "in uso-net: ${inside:-nothing}"
ss -tlnH | awk '{print $4}' | grep -q "^$ip4:" && cell door-not-host 0 "something listens on $ip4 in the host's namespace" \
    || cell door-not-host 1 "nothing of the node in the host's namespace"
un_host_fp >"$KEEP/host-joined"
if diff -q "$KEEP/host-before" "$KEEP/host-joined" >/dev/null; then cell host-same 1 "addresses, routes, rules, resolv.conf, firewall, listeners, sshd_config, authorized_keys as before"
else cell host-same 0 "$(diff "$KEEP/host-before" "$KEEP/host-joined" | head -5 | tr '\n' ' ')"; fi
[[ ! -e $UN_FLASH/authkey ]] && cell key-gone 1 "no auth key on the flash" || cell key-gone 0 "$UN_FLASH/authkey is still there"
key=$(cat "$KEEP/.secret-usonet")
hits=$(grep -rlF -- "$key" "$D/caretaker.json" "$D/agent.log" "$UN_RUN" "$UN_FLASH" /var/log/syslog 2>/dev/null | tr '\n' ' ')
grep -qF -- "$key" "$KEEP/ask/"*.json 2>/dev/null && hits+="answers"
unset key
[[ -z $hits ]] && cell key-nowhere 1 "the key in no state, log, answer or file" || cell key-nowhere 0 "the key found in: $hits"

un_leave
left=$(un_left)
[[ -z $left ]] && cell left-nothing 1 "nothing of the network left" || cell left-nothing 0 "$(tr '\n' ';' <<<"$left")"
# Headscale keeps a tagged node listed after its logout (no expiry for tagged nodes) — offline is what holds
wait_for 60 "the lab's node offline" bash -c '! bash "$1" status | awk -v a="$2" '"'"'$2 == a'"'"' | grep -q online=true' _ "$UN_LAB_SH" "$ip4"
node=$(bash "$UN_LAB_SH" status | awk -v a="$ip4" '$2 == a')
[[ $node != *online=true* ]] && cell logged-out 1 "the lab says: ${node:-gone}; $(grep -o 'USO network: this server left it.*' "$D/agent.log" | tail -1)" \
    || cell logged-out 0 "the lab still has it online: $node"
un_host_fp >"$KEEP/host-left"
diff -q "$KEEP/host-before" "$KEEP/host-left" >/dev/null && cell host-after 1 "the host's network as before" \
    || cell host-after 0 "$(diff "$KEEP/host-before" "$KEEP/host-left" | head -5 | tr '\n' ' ')"
# the lab's Headscale (a container usonet-lab.sh made) is new to Mr. Backupsy's setup — the test bed, not this flow
allow INV-REPLAN "only the lab's container uso-lab-headscale is new to the setup (docker known/no_stop) — the test bed, not the flow"
check standard
scenario_end
