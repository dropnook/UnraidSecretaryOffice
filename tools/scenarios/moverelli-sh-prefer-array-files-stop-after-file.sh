#!/bin/bash
# Cell M1 of matrix.md (★, a bug from the owner's server), its «Stop after this file»: a prefer share's real run back to the
# pool, stopped by the user while a file is on its way. The file in flight finishes, no further one is begun: some files
# are on cache, the rest still whole on the array, no .partial anywhere, her last run «stopped by the user» (the flow).
# Until 1.66.1 nothing moved at all (one file at a time, handed over as /mnt/user0/…). Its own files: 6 × 192 MB in a
# folder per disk, big enough that a file is in flight when the stop comes (SH-PREFER-ARRAY-FILES' are 1 MB).
# $1 = an older release (v1.66.0): must be red. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-prefer-array-files-stop-after-file
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
on() { find "/mnt/$1/uso-prefer/m1s-d1" "/mnt/$1/uso-prefer/m1s-d2" -type f ! -name '*.partial' 2>/dev/null | wc -l; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
undo_push bash -c 'rm -rf /mnt/*/uso-prefer/m1s-d1 /mnt/*/uso-prefer/m1s-d2'
share_exists uso-prefer && [[ $(_st_share_ini uso-prefer useCache) == prefer ]] || _row state uso-prefer red "the base has no prefer share uso-prefer"
put_files M1S disk1 uso-prefer/m1s-d1 6 --size 192M && put_files M1S disk2 uso-prefer/m1s-d2 6 --size 192M || _row state put_files red "the files could not be written"
undo_push files_unput M1S
track_share uso-prefer
snap_before
log=$D/moverelli/smart_mover.log
lines0=$(wc -l <"$log" 2>/dev/null || echo 0)
# her plan goes Filme, «Fotos Ärger», … before uso-prefer: the stop is sent once a file of uso-prefer is in flight
flow MV-STOP-AFTER-FILE share=uso-prefer
h=$(jq -r '.history[0] | "\(.result)/\(.why // "")"' "$D/moverelli.json" 2>/dev/null)
[[ $h == stopped/user ]] && cell stopped-by-user 1 "her last run: stopped by the user" || cell stopped-by-user 0 "her last run: $h"
a=$(( $(on disk1) + $(on disk2) )) c=$(on cache)
((c >= 1 && a >= 1 && a + c == 12)) && cell stopped-between 1 "$c of 12 files reached cache, $a stayed on the array — stopped after a file" \
    || cell stopped-between 0 "after the stop: $c on cache, $a on the array (of 12)"
part=$(find /mnt/cache/uso-prefer /mnt/disk1/uso-prefer /mnt/disk2/uso-prefer -name '*.partial' 2>/dev/null | head -2)
[[ -z $part ]] && cell no-partial 1 "no half-moved file" || cell no-partial 0 "left half-moved: ${part//$'\n'/ }"
tail -n +"$((lines0 + 1))" "$log" 2>/dev/null >"$KEEP/smart_mover-run.log"; evidence "$KEEP/smart_mover-run.log" >/dev/null
check standard
scenario_end
