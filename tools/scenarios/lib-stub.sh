#!/bin/bash
# TEMPORARY stand-in for H1's lib.sh (interface as on origin/task/scenarios-h1-lib); the coordinator deletes it at merge.
P=/usr/local/emhttp/plugins/unraid-secretary-office; RUN=/var/run/unraid-secretary-office; SC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
D=$(sed -n 's/^DATA_DIR="\(.*\)"/\1/p' /boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg); UB=$D/unraid-backup
KEEP=${KEEP:-/tmp/uso-keep/stub}; KEEP_STASH=$KEEP/stash; UNDO=(); TRACKED=(); mkdir -p "$KEEP_STASH"
say() { printf '%s %s\n' "$(date +%T)" "$*" >&2; }
host_is() { local ip; ip=$(ip -4 -o addr show br0 2>/dev/null | awk '{print $4}'); case $1 in tower) [[ $ip == 192.168.7.111/* ]];; test3) [[ $ip == 192.168.7.197/* ]];; any) true;; *) false;; esac; }
has() { case $1 in docker) docker info >/dev/null 2>&1;; vm) virsh version >/dev/null 2>&1;; zfs) zpool list -H >/dev/null 2>&1;; btrfs) grep -q ' btrfs ' /proc/mounts;; *) false;; esac; }
track_share() { TRACKED+=("$@"); }
undo_push() { UNDO+=("$*"); }
state() { local f=${1,,}; f=${f//-/_}; shift; say "state $f $*"; UNDO+=("undo_$f${*:+ $(printf "%q " "$@")}"); "state_$f" "$@"; }
undo_all() { local i rc=0; for ((i=${#UNDO[@]}-1; i>=0; i--)); do say "undo ${UNDO[i]}"; eval "${UNDO[i]}" || rc=3; done; UNDO=(); return $rc; }
for _f in "$SC_DIR"/states-*.sh; do [[ -f $_f ]] && . "$_f"; done; unset _f
