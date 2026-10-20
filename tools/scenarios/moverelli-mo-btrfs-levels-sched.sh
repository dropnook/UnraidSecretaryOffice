#!/bin/bash
# Cell M17 of matrix.md: a btrfs pool and no ZFS pool (SH-BTRFS / DK-NO-ZFS — USOTest3VM's base; Tower's cache is ZFS:
# needs btrfs, waits for test3). Her setup's levels for a btrfs pool come from her own measuring (pool-du, in the
# background: «not measured» until it is done, never a guess), the limit X holds on btrfs, and her joint run reads the
# level from the same measure. Pre-release on test3 (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-btrfs-levels-sched
needs test3 btrfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state DK-NO-ZFS
snap_before
pool=$(awk -F'"' '/^\[/{n=""} /^name=/{n=$2} /^type=/{t=$2} /^fsType=/{if (t=="Cache" && $2=="btrfs") print n}' "$DISKS_INI" | head -1)
[[ -n $pool ]] || _row state pool red "no btrfs pool in disks.ini"
lv=$(state_json moverelli '.levels // {} | tostring' 2>/dev/null)
say "her levels: ${lv:0:300}"
m=$(jq -r --arg p "$pool" '.levels[$p] // {} | (.measured // .used_pct // "none") | tostring' "$D/moverelli.json" 2>/dev/null)
[[ $m != none && $m != null ]] && cell levels 1 "pool $pool: $m (pool-du)" || cell levels 0 "pool $pool: no level («not measured» never filled in)"
flow MV-RUN-SCHED
n=$(jq -c --arg p "$pool" '{pool: .picture.pool, x: .picture.x, end: .end_pct, result}' "$D/moverelli/night.json" 2>/dev/null)
[[ $(jq -r '.result' <<<"$n") =~ ^(ok|missed)$ ]] && cell joint-run 1 "the joint run on btrfs: $n" || cell joint-run 0 "the joint run on btrfs: $n"
check standard
scenario_end
