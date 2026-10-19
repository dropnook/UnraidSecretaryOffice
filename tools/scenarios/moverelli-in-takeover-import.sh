#!/bin/bash
# Cell M21 of matrix.md (batch E): taking over an old Smart Mover install (IN-TAKEOVER, Smart Mover's side: its
# smart_mover.ini in a folder of a share, as a User Script's folder copied there) whose exclude list can't be read — a
# binary file (NUL bytes in its first 4 KB). Her import's preview blocks (agent/desks/moverelli-import.php: «a protecting
# list that can't be had never silently disappears») and names the file, the import itself is refused (cell blocked), nothing of
# hers is written (cell nothing-written; she stays not set up as the base has her). The folder uso-wip-e-smartmover in
# uso-only is made here and removed by the undo. Pre-release (~1 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-in-takeover-import
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
F=/mnt/user/uso-only/uso-wip-e-smartmover
[[ -e $F ]] && { _row state prepare red "$F exists"; }
undo_push rm -rf -- "$F"
mkdir -p "$F" && cat >"$F/smart_mover.ini" <<INI
[GLOBAL]
min_age=30
move_when_used_above=80
global_excludes=$F/excludes.bin
mover_bin=/usr/local/sbin/mover
log_file=$F/smart_mover.log
INI
printf 'movies/keep\0\0\0\x01\x02 binary\n' >"$F/excludes.bin"
h0=$(find "$D/moverelli" -type f -printf '%P %s %T@\n' 2>/dev/null | sort | md5sum)
snap_before
files_strict
flow MV-IMPORT "folder=$F" refuse=ok
pv=$(jq '.preview // .' "$(ls "$KEEP"/ask/*-moverelli.import_preview.json | tail -1)")
ap=$(jq -r '.error.key // "none"' "$(ls "$KEEP"/ask/*-moverelli.import_apply.json 2>/dev/null | tail -1)" 2>/dev/null)
printf '%s\n' "$pv" >"$KEEP/import-preview.json"; evidence "$KEEP/import-preview.json" >/dev/null
b=$(jq -c '[(.blocked // .blockers // [])[]? | select((.why // .key // "") | tostring | test("not_read|unreadable|binary"))] | .[0:3]' <<<"$pv" 2>/dev/null)
# blocked in the preview, naming the list; an import with its token refused (the page's button is grey; the agent
# refuses it all the same: moverelli_import_blocked)
[[ -n $b && $b != '[]' ]] && grep -q 'excludes.bin' <<<"$b" && [[ ${ap:-none} == none || $ap == moverelli_import_blocked ]] \
    && cell blocked 1 "the preview blocks and names the list: $b; the import itself: ${ap:-not tried}" || cell blocked 0 "the preview: blocked ${b:-none}; the import: ${ap:-not tried}"
[[ $(find "$D/moverelli" -type f -printf '%P %s %T@\n' 2>/dev/null | sort | md5sum) == "$h0" ]] \
    && cell nothing-written 1 "her folder as before" || cell nothing-written 0 "her folder changed by a blocked import"
bubble_look import moverelli
check standard
scenario_end
