#!/bin/bash
# tools/scenarios/usonet-lab.sh up|down|key|status — the USO network's test tailnet: a Headscale in a container on
# USOTest3VM (D10 b: no account, nothing of the owner's real Tailscale), its data in RAM (gone at a reboot; `up` makes
# it again). Policy: tag:uso → tag:uso only, as the office shows it (usonetPolicyLines()). Never on nostromo, Tower or
# USOPartner.
#
#   usonet-lab.sh up       the container uso-lab-headscale on port 8085 (http://<test3>:8085), a user «uso»
#   usonet-lab.sh key      a new auth key (one-off, tag:uso, 1 h) on stdout — a secret: into a 0600 file, never a log
#   usonet-lab.sh status   the nodes it knows (name, address, online)
#   usonet-lab.sh tidy     the offices' nodes that are offline (left) deleted — Headscale never expires a tagged one
#   usonet-lab.sh down     the container and its data gone
set -u
NAME=uso-lab-headscale
DIR=/tmp/uso-lab-headscale
PORT=8085
IP=192.168.7.197
IMAGE=headscale/headscale:v0.29.5
hs() { docker exec "$NAME" headscale "$@"; }

up() {
    [[ $(hostname -I 2>/dev/null) == *"$IP"* ]] || { echo "usonet-lab: only on USOTest3VM ($IP)"; exit 2; }
    docker ps --format '{{.Names}}' | grep -qx "$NAME" && { echo "usonet-lab: $NAME runs"; return 0; }
    docker rm -f "$NAME" >/dev/null 2>&1
    mkdir -p "$DIR/etc" "$DIR/lib" "$DIR/run"
    cat >"$DIR/etc/config.yaml" <<EOF
server_url: http://$IP:$PORT
listen_addr: 0.0.0.0:8080
metrics_listen_addr: 127.0.0.1:9090
grpc_listen_addr: 127.0.0.1:50443
noise:
  private_key_path: /var/lib/headscale/noise_private.key
prefixes:
  v4: 100.64.0.0/10
  v6: fd7a:115c:a1e0::/48
  allocation: sequential
derp:
  server:
    enabled: false
  urls:
    - https://controlplane.tailscale.com/derpmap/default
  auto_update_enabled: false
database:
  type: sqlite
  sqlite:
    path: /var/lib/headscale/db.sqlite
log:
  level: warn
policy:
  mode: file
  path: /etc/headscale/policy.hujson
dns:
  magic_dns: false
  override_local_dns: false
  nameservers:
    global: []
unix_socket: /var/run/headscale/headscale.sock
unix_socket_permission: "0770"
EOF
    cat >"$DIR/etc/policy.hujson" <<'EOF'
{
  "tagOwners": { "tag:uso": ["uso@"] },
  "acls": [ { "action": "accept", "src": ["tag:uso"], "dst": ["tag:uso:*"] } ]
}
EOF
    docker run -d --name "$NAME" --restart unless-stopped -p "$PORT:8080" -v "$DIR/etc:/etc/headscale" -v "$DIR/lib:/var/lib/headscale" \
        -v "$DIR/run:/var/run/headscale" "$IMAGE" serve >/dev/null || exit 1
    for _ in $(seq 1 20); do curl -fs "http://$IP:$PORT/health" >/dev/null && break; sleep 1; done
    hs users create uso >/dev/null 2>&1
    echo "usonet-lab: $NAME up on http://$IP:$PORT"
}

key() {
    local id
    id=$(hs users list -o json 2>/dev/null | jq -r '.[] | select(.name == "uso") | .id' | head -1)
    hs preauthkeys create -u "${id:-1}" --tags tag:uso -e 1h -o json 2>/dev/null | jq -r '.key // empty'
}

case "${1:-}" in
    up) up ;;
    key) key ;;
    tidy) hs nodes list -o json 2>/dev/null | jq -r '.[] | select((.online // false) | not) | select(.given_name | startswith("uso")) | .id' |
              while read -r id; do hs nodes delete -i "$id" --force >/dev/null 2>&1; done ;;    # the offices' nodes gone offline (left)
    status) hs nodes list -o json 2>/dev/null | jq -r '.[] | "\(.given_name) \(.ip_addresses[0]) online=\(.online // false)"' ;;
    down) docker rm -f "$NAME" >/dev/null 2>&1; rm -rf "$DIR"; echo "usonet-lab: down" ;;
    *) echo "usage: bash $0 up|key|status|tidy|down"; exit 2 ;;
esac
