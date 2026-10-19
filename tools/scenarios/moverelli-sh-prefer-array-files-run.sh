#!/bin/bash
# Cell M1 of matrix.md (★, a bug from the owner's server): a «prefer» share with files on the array — her «Run now» really
# brings them back to the pool: every file from disk1 and disk2 ends on cache, handed over per disk (never as
# /mnt/user0/…, which Unraid's move binary ignores — until 1.66.1 nothing moved), her counts are the disks' (INV-COUNTS).
# The files: SH-PREFER-ARRAY-FILES (10 in uso-prefer/sh-paf-d1 on disk1, 10 in sh-paf-d2 on disk2 — a folder per disk:
# the same path on both disks would be duplicates Unraid never moves). Fixed in 1.66.1. $1 = an older release (v1.66.0):
# the same scenario on that plugin, which must be red. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-prefer-array-files-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
on() { find "/mnt/$1/uso-prefer/sh-paf-d1" "/mnt/$1/uso-prefer/sh-paf-d2" -type f ! -name '*.partial' 2>/dev/null | wc -l; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
share_exists uso-prefer && [[ $(_st_share_ini uso-prefer useCache) == prefer ]] || _row state uso-prefer red "the base has no prefer share uso-prefer"
state SH-PREFER-ARRAY-FILES uso-prefer      # its undo takes the files from wherever her run put them
track_share uso-prefer
snap_before
log=$D/moverelli/smart_mover.log
lines0=$(wc -l <"$log" 2>/dev/null || echo 0)
flow MV-RUN
a=$(( $(on disk1) + $(on disk2) )) c=$(on cache)
((a == 0 && c == 20)) && cell moved-to-pool 1 "all 20 files are on cache now, none left on the array" \
    || cell moved-to-pool 0 "after her run: $c of 20 on cache, $a still on the array (disk1 $(on disk1), disk2 $(on disk2))"
tail -n +"$((lines0 + 1))" "$log" 2>/dev/null >"$KEEP/smart_mover-run.log"
evidence "$KEEP/smart_mover-run.log" >/dev/null
bad=$(grep -E '/mnt/user0/uso-prefer|No such file or directory' "$KEEP/smart_mover-run.log" | head -2)
[[ -z $bad ]] && cell per-disk 1 "her run log names no /mnt/user0 path for uso-prefer, no «No such file»" \
    || cell per-disk 0 "her run log: ${bad//$'\n'/ | }"
check standard
scenario_end
