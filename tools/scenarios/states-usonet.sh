#!/bin/bash
# tools/scenarios/states-usonet.sh — the USO network's states (the office's own Tailscale node, agent/lib/usonet.php,
# scripts/usonet.sh) on USOTest3VM with the lab tailnet of usonet-lab.sh (Headscale there). Sourced by lib.sh.
#
#   un_needs_lab             the lab answers (else the scenario is skipped: `bash tools/scenarios/usonet-lab.sh up` first)
#   un_join                  the page's join: a new lab key stashed in the agent's RAM inbox as the page stashes it
#                            (apiSecretStash's file), the request carries only its ref; prints the answer
#   un_wait <state> [s]      until the Team Lead's card (caretaker.usonet_look) says <state> (in, off, …), ≤ s (180)
#   un_leave                 the page's «Leave the USO network» and the wait for «off»
#   un_card [jq]             the card now (caretaker.usonet_look's `usonet`)
#   un_host_fp               the host's network as the network must never change it, one line per fact: addresses but
#                            their lifetimes, routes v4/v6, rules, resolv.conf, the firewall's rules (counters out), the
#                            forwarding switches, the host's TCP listeners, Unraid's sshd_config and authorized_keys
#   un_left                  what is left of the network on this server, one line per thing (nothing printed: nothing)
#
# States (`state` pushes the undo):
#   UN-JOINED                this server in the lab's tailnet (un_join, un_wait in); undo: un_leave
#   UN-HELPER-DOWN           the helper stopped and its minute's look held off (the cron line out of root's crontab in
#                            RAM); undo: the crontab rebuilt (update_cron), usonet.sh check, the card «in» again
UN_LAB=${UN_LAB:-http://192.168.7.197:8085}
UN_LAB_SH=$SC_DIR/usonet-lab.sh
UN_RUN=$RUN/usonet
UN_FLASH=$OFFICE_FLASH/usonet

un_needs_lab() {
    curl -fs --max-time 5 "$UN_LAB/health" >/dev/null 2>&1 && return 0
    printf '%s\n' "the USO network's lab ($UN_LAB) doesn't answer — bash tools/scenarios/usonet-lab.sh up" >"$KEEP/.skip"
    say "skipped: no lab tailnet at $UN_LAB"
    scenario_end
}

un_card() { local j; j=$(php "$SC_DIR/ask.php" caretaker.usonet_look 2>/dev/null); if [[ $# -gt 0 ]]; then jq -r ".usonet | $1" <<<"$j"; else jq -c .usonet <<<"$j"; fi; }

un_join() {
    local inbox=$RUN/inbox id key
    key=$(bash "$UN_LAB_SH" key)
    [[ -n $key ]] || { say "un_join: the lab gave no key"; return 1; }
    mkdir -p "$inbox" && chmod 700 "$inbox"
    id=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')
    (umask 177; jq -cn --arg k "$key" '{key: $k}' >"$inbox/$id.secret")
    printf '%s\n' "$key" >"$KEEP/.secret-usonet" && chmod 600 "$KEEP/.secret-usonet"     # for INV-NO-SECRET's look, never printed
    unset key
    ask caretaker.usonet_join "{\"secret_ref\":\"$id\",\"control\":\"$UN_LAB\"}"
}

un_wait() {
    local want=$1 s=${2:-180}
    wait_for "$s" "the USO network's card «$want»" bash -c '[[ $(php "$1/ask.php" caretaker.usonet_look 2>/dev/null | jq -r .usonet.state) == "$2" ]]' _ "$SC_DIR" "$want"
}

un_leave() { ask caretaker.usonet_leave >/dev/null; un_wait off 120; }

un_host_fp() {
    ip -o addr | sed -E 's/ (valid|preferred)_lft [^ ]+//g'
    ip route; ip -6 route; ip rule
    cat /etc/resolv.conf
    iptables-save 2>/dev/null | grep -v '^#' | sed -E 's/\[[0-9]+:[0-9]+\]//'
    ip6tables-save 2>/dev/null | grep -v '^#' | sed -E 's/\[[0-9]+:[0-9]+\]//'
    sysctl -n net.ipv4.ip_forward net.ipv6.conf.all.forwarding
    ss -tlnH | awk '{print "tcp " $4}' | sort -u
    md5sum /etc/ssh/sshd_config /boot/config/ssh/root/authorized_keys 2>/dev/null
}

un_left() {
    [[ -e /run/netns/uso-net ]] && echo "namespace uso-net"
    [[ -e $UN_FLASH ]] && echo "flash $UN_FLASH"
    [[ -e $OFFICE_FLASH/usonet.cron ]] && echo "cron file usonet.cron"
    grep -q 'usonet.sh check' /etc/cron.d/root 2>/dev/null && echo "root's crontab: the usonet line"
    [[ -e /usr/local/lib/unraid-secretary-office ]] && echo "RAM /usr/local/lib/unraid-secretary-office"
    [[ -e $UN_RUN ]] && echo "RAM $UN_RUN"
    pgrep -f 'uso-net up|uso-sshd' >/dev/null && echo "processes: $(pgrep -af 'uso-net up|uso-sshd' | cut -c1-80 | tr '\n' ';')"
    return 0
}

state_un_joined() {
    un_needs_lab
    [[ $(un_card .state) == off ]] || { say "UN-JOINED: not «off» before — $(un_card .state)"; return 1; }
    un_join >/dev/null || return 1
    un_wait in 180
}
undo_un_joined() { [[ $(un_card .joined) == true ]] || return 0; un_leave; }

state_un_helper_down() {
    [[ $(un_card .state) == in ]] || { say "UN-HELPER-DOWN: the network isn't «in»"; return 1; }
    sed -i '/usonet\.sh check/d' /etc/cron.d/root && say "UN-HELPER-DOWN: the minute's look out of root's crontab (RAM)"
    kill "$(cat "$UN_RUN/helper.pid")" 2>/dev/null
    wait_for 30 "the helper gone" bash -c '! kill -0 "$(cat "$1")" 2>/dev/null' _ "$UN_RUN/helper.pid"
}
undo_un_helper_down() {
    bash /usr/local/sbin/update_cron >/dev/null 2>&1
    bash "$P/scripts/usonet.sh" check
    un_wait in 120
}
