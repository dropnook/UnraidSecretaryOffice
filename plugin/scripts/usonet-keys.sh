#!/bin/bash
# Unraid Secretary Office - the USO network's sshd (scripts/usonet.sh) asks this for the keys of a login: only the
# pairing's lines of Unraid's authorized_keys (comment uso-partner:<id> or uso-ticket:<id> at the end - the same mark
# agent/lib/partner.php writes and reads), exactly as written: their restrict, from= and forced command hold here as on
# Unraid's own sshd. Every other key of root's never opens the USO network's door.
[[ "$1" == root ]] || exit 0
f=${USONET_AUTH_KEYS:-/boot/config/ssh/root/authorized_keys}
[[ -f "$f" && ! -L "$f" ]] || exit 0
tr -d '\r' <"$f" | grep -E '(^|[ 	])uso-(partner|ticket):[0-9a-f]{8}$'
exit 0
