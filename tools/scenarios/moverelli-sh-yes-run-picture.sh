#!/bin/bash
# Cell M3 of matrix.md (★, a bug from the owner's server): her run card while a yes share's old files go to the array —
# a stretch of many small files, then a few big ones (the owner's Nextcloud: «6 days» for a run of under 2 h).
# - The time left is honest (cell card-left): her live progress, read every 3 s as an open page reads it, replayed into
#   the drawn page (page.mjs --replay, the page's own clock and timers) and the time left read off her run card: every
#   one within 3× (or 30 s) of the seconds the share really still took. Fixed in 1.66.2 / 1.66.4. On the server too
#   (eta-honest: moverelli-eta.mjs, this tree's runCardData() on the same readings).
# - The card's percent and «On its way … → to» against the disks (bubble_look → INV-PAGE), and her bubble names the share
#   and its way to the array (bubble.run_to_array, 1.66.6) instead of a greeting.
# The target pool's own bar (1.66.2) needs a second pool (a prefer share into it) — Tower has one: not covered here.
# All of it is the page's code: on an older release ($1 = v1.66.1) the agent's progress is the same, and run.sh draws
# the page of that tag (card-left red there; eta-honest judges this tree's code, so it says only «info»). Pre-release
# (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-yes-run-picture
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
undo_push bash -c 'rm -rf /mnt/*/uso-yes/m3'        # what reached the array (runs last)
share_exists uso-yes && [[ $(_st_share_ini uso-yes useCache) == yes ]] || _row state uso-yes red "the base has no yes share uso-yes"
# 40 days old (her rule for uso-yes: 30): the small ones first, the big ones last. She moves in find's order — the
# folder's own order, not by name (Tower 2026-10-19: the big ones came between the small ones, and 1.66.1's card was
# honest by luck) — so two folders, the small ones in the one find visits first (an emptied parent folder stays on
# the pool before 1.66.7's tidy step: moverelli-sh-yes-nested-tidy)
_st_part M3 cache uso-yes/m3/f1 && _st_part M3 cache uso-yes/m3/f2 || _row state m3 red "the folders could not be made"
first=$(find /mnt/cache/uso-yes/m3 -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | head -1)
second=$([[ $first == f1 ]] && echo f2 || echo f1)
say "find visits uso-yes/m3/$first first: the small files there, the big ones in $second"
put_files M3 cache "uso-yes/m3/$first" 1500 --size 4K --age 40d --name a-small.bin && put_files M3 cache "uso-yes/m3/$second" 4 --size 256M --age 40d --name z-big.bin \
    || _row state put_files red "the files could not be written"
order=$(find /mnt/cache/uso-yes/m3 -type f -printf '%f\n' 2>/dev/null | grep -n '^z-big' | head -1 | cut -d: -f1)
[[ $order == 1501 ]] || _row state m3 red "find doesn't give the big files last (first big one at ${order:-?} of 1504)"
undo_push files_unput M3
track_share uso-yes
snap_before
readings=$KEEP/m3-readings.jsonl
(
    while :; do
        p=$(_ck_mv_state | jq -c '.progress // empty' 2>/dev/null)
        [[ -n $p && $p != null ]] && jq -cn --argjson t "$(date +%s)" --argjson p "$p" '{t: $t, progress: $p}' >>"$readings"
        sleep 3
    done
) &
sampler=$!
flow MV-RUN look=20
kill "$sampler" 2>/dev/null; wait "$sampler" 2>/dev/null
jq -cn --argjson t "$(date +%s)" '{t: $t, end: true}' >>"$readings"
evidence "$readings" >/dev/null
n=$(grep -c progress "$readings" 2>/dev/null || echo 0)
if [[ -n ${1:-} ]]; then
    _row cell eta-honest info "judged with $1's page on the Mac (card-left); here only this tree's code could judge"
elif ! command -v node >/dev/null 2>&1; then
    _row cell eta-honest info "no node on this server: judge $readings on the Mac (moverelli-eta.mjs)"
elif ((n < 5)); then
    cell eta-honest 0 "only $n readings of her run — too short to judge its time left"
else
    out=$(node "$SC_DIR/moverelli-eta.mjs" "$SC_TREE/public/desks/moverelli/desk.js" "$SC_TREE/public/assets/core.js" "$readings" 2>&1)
    printf '%s\n' "$out" >"$KEEP/m3-eta.txt"; evidence "$KEEP/m3-eta.txt" >/dev/null
    tail -1 <<<"$out" | grep -q '^ETA ok' && cell eta-honest 1 "$(tail -1 <<<"$out") ($n readings)" \
        || cell eta-honest 0 "$(tail -1 <<<"$out") — $(grep -m2 'NOT HONEST' <<<"$out" | tr '\n' ' ')"
fi
# each reading with the seconds her share really still took (expect: until the first reading of another share or
# phase, or the run's end) — the server's number the card's time left is judged against
replay=$KEEP/m3-replay.jsonl
jq -s -c '. as $a | range(0; length) as $i | $a[$i] + (if ($a[$i].progress.phase == "share" and ($a[$i].progress.share // "") != "")
    then {expect: (([range($i + 1; $a | length) | select($a[.].end or $a[.].progress.phase != "share"
        or $a[.].progress.share != $a[$i].progress.share)] | first) as $j | if $j == null then null else $a[$j].t - $a[$i].t end)}
    else {} end)' "$readings" >"$replay" 2>/dev/null && evidence "$replay" >/dev/null
# her bubble while the share was in flight (the flow's look 20 s into the run)
look=$(ls -d "$KEEP"/looks/*-mid-moverelli-run 2>/dev/null | tail -1)
if [[ -n $look && -f $look/states/moverelli-progress.json ]]; then
    queue_mac INV-BUBBLE page_text moverelli "$look/states" "$PAGE_LANGS" want "moverelli.bubble.run_to_array"
    # her card's time left off the drawn page, reading by reading, against the seconds really left (look's state the frame)
    if ((n >= 5)) && [[ -s $replay ]]; then
        queue_mac card-left page_value --factor 3 --slack 30 moverelli "$look/states" en "$replay" "moverelli.rc.left:left moverelli.rc.left_range:lo..hi"
    else
        cell card-left 0 "only $n readings of her run — too short to judge the card's time left"
    fi
else
    cell mid-run-look 0 "no look while her run was at work"
fi
moved=$(find /mnt/disk*/uso-yes/m3 -type f 2>/dev/null | wc -l)
((moved == 1504)) && cell moved 1 "all 1504 old files of uso-yes/m3 are on the array" || cell moved 0 "$moved of 1504 old files reached the array"
check standard
scenario_end
