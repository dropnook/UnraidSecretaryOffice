#!/bin/bash
# Batch H, finding 9 (parking lot «Resilienz-Funde»): Unraid's move binary drops the whitespace at the end of every path
# it reads (briefs/unraid-mover-trailing-space-repro.md, 7.4.0-rc.1) — names ending in a space or a tab stay where they
# are, and «twin » moves «twin» instead. Our fix (task/mover-trailing-space, smartmover/custommover_run.sh «USO trailing
# space»: such names never go to the binary) holds as smartmover/README.md says it: to a pool (prefer share
# uso-wip-h-tsp, array → pool) the script moves them itself — every file arrives under its exact name with its content
# (cell prefer-all-moved), nothing left on the disk (prefer-nothing-left), «twin» and «twin » two files (prefer-twins);
# to the array (yes share uso-wip-h-ts, pool → array: the disk is the binary's choice) they stay on the pool «with a
# WARN — rename it», counted in `left`: the others moved under their exact names (cell yes-others-moved), the three
# whitespace names still on the pool with their own contents — never «twin» moved for «twin » (cell yes-ws-stay), and
# her page says why they stayed (cell yes-said: her status's left_why for the share — the README's WARN must reach the
# user, not only her log). First run 2026-10-21: the yes cells were written as «everything moves» — the README's design
# says otherwise, reshaped (a scenario bug, playbook §6.3). Her rule 0 days for the yes share (everything goes, as
# Unraid's mover would), prefer shares moved (move_prefer_shares, her default). Ms. Moverelli set up for the scenario, put back after. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-trailing-space-h-run
needs any
Y=uso-wip-h-ts PF=uso-wip-h-tsp
NAMES=("Album /Juni /a.jpg" "Album /note " "twin" "twin " "tab	/x.txt" "plain/end. " "plain/ lead")
undo_push h_share_drop "$PF"
undo_push h_share_drop "$Y"
nc_data_closed
share_make "$Y" yes cache || _row state share_make red "no $Y"
share_make "$PF" prefer cache || _row state share_make red "no $PF"
nc_data_closed
h_mv_set_up H9 "rule=$Y:0" || _row state MV-SETUP red "her setup failed"
# the names written on the side each share's files come from: the yes share's on the pool, the prefer share's on disk1
write_names() { local base=$1 s=$2 n i=0
    for n in "${NAMES[@]}"; do i=$((i + 1))
        mkdir -p "/mnt/$base/$s/$(dirname "$n")" && printf 'file %d of %s: «%s»\n' "$i" "$s" "$n" >"/mnt/$base/$s/$n" || return 1
    done
    chown -R nobody:users "/mnt/$base/$s"; }
write_names cache "$Y" || _row state names red "the names could not be written on the pool"
write_names disk1 "$PF" || _row state names red "the names could not be written on disk1"
track_share "$Y" "$PF"
snap_before
flow MV-RUN
# where a name is on that side (array: any disk — Unraid's allocation picks one)
on() { local to=$1 s=$2 n=$3 b; for b in $( [[ $to == array ]] && ls -d /mnt/disk[0-9]* || echo "/mnt/$to"); do [[ -f "$b/$s/$n" ]] && { printf '%s' "$b/$s/$n"; return 0; }; done; return 1; }
judge() { local dir=$1 s=$2 from=$3 to=$4 n i=0 ok=0 bad=() left f
    for n in "${NAMES[@]}"; do i=$((i + 1))
        if f=$(on "$to" "$s" "$n") && [[ $(cat "$f") == "$(printf 'file %d of %s: «%s»' "$i" "$s" "$n")" ]]; then ok=$((ok + 1))
        else bad+=("«$n»"); fi
    done
    ((ok == ${#NAMES[@]})) && h_cell "$dir-all-moved" 1 "all ${#NAMES[@]} names on $to under their exact names, contents right" \
        || h_cell "$dir-all-moved" 0 "$ok of ${#NAMES[@]} on $to; missing or wrong: ${bad[*]}"
    left=$(cd "/mnt/$from/$s" 2>/dev/null && find . -mindepth 1 | sed 's/$/|/' | paste -sd' ' -)
    [[ -z $left ]] && h_cell "$dir-nothing-left" 1 "nothing of $s left on $from" || h_cell "$dir-nothing-left" 0 "left on $from (| marks the end of a name): $left"
    local a b; a=$(on "$to" "$s" twin) b=$(on "$to" "$s" "twin ")
    [[ -n $a && -n $b && $(cat "$a") != "$(cat "$b")" ]] && h_cell "$dir-twins" 1 "«twin» and «twin » two files on $to" \
        || h_cell "$dir-twins" 0 "«twin»/«twin » on $to: ${a:-none} | ${b:-none}"
}
# the yes share: what may go went, what can't stayed whole, and her page says why
ok=0 bad=() i=0 ws=0 wsok=0
for n in "${NAMES[@]}"; do i=$((i + 1)); want=$(printf 'file %d of %s: «%s»' "$i" "$Y" "$n")
    if [[ $n =~ [[:space:]]$ ]]; then ws=$((ws + 1)); [[ -f "/mnt/cache/$Y/$n" && $(cat "/mnt/cache/$Y/$n") == "$want" ]] && wsok=$((wsok + 1)) || bad+=("«$n» not on the pool")
    else f=$(on array "$Y" "$n") && [[ $(cat "$f") == "$want" ]] && ok=$((ok + 1)) || bad+=("«$n» not on the array"); fi
done
((ok == ${#NAMES[@]} - ws)) && h_cell yes-others-moved 1 "the $ok names without whitespace at the end on the array, contents right" \
    || h_cell yes-others-moved 0 "$ok of $((${#NAMES[@]} - ws)) on the array: ${bad[*]}"
((wsok == ws)) && h_cell yes-ws-stay 1 "the $ws names ending in whitespace still on the pool, each with its own content («twin» moved, «twin » stayed)" \
    || h_cell yes-ws-stay 0 "$wsok of $ws whitespace names whole on the pool: ${bad[*]}"
sh=$(jq -c --arg s "$Y" '[.shares[]? | select(.share == $s) | {moved, left, left_why, left_codes}][0]' "$D/moverelli/status.json" 2>/dev/null)
# since the fix (batch H9): every file left has its reason code — the three here `ws`, which her page words
[[ $(jq -r '((.left_codes.ws // 0) == .left and .left > 0) or (.left_why // "") != ""' <<<"$sh") == true ]] && h_cell yes-said 1 "her status says why: $sh" \
    || h_cell yes-said 0 "her status: $sh — $(jq -r '.left' <<<"$sh") left, no reason for the page (smartmover/README.md: «stays (WARN … rename it)» — the reason never reaches her status)"
judge prefer "$PF" disk1 cache
expect_changes "share|$Y|*" "share|$PF|*"
check standard
scenario_end
