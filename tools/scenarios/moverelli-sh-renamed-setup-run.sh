#!/bin/bash
# Cell M16 of matrix.md: shares that changed under Ms. Moverelli's own rules — her rule goes only on a click, and a
# cfg without its share is skipped by her run.
# - SH-RENAMED: uso-wip-c-mva (yes) with her rule (age 0, a list line) renamed to uso-wip-c-mvb in Unraid's form.
# - SH-DELETED: uso-wip-c-mvd (yes) with her rule, deleted by Unraid's «Delete».
# - SH-CFG-ORPHAN: uso-wip-c-mvo (yes, files) whose roots are gone while its cfg stays (a share removed by hand).
# MV-RESAVE (her setup saved as it is): smart_mover.ini byte for byte — the rules of mva and mvd stay (never moved over
# to mvb by themselves), her state lists both as shares Unraid no longer has. MV-RUN: the run ends ok, its log says the
# orphan cfg was skipped (smartmover/README: «a <share>.cfg whose share is gone … is skipped with an INFO line, never
# moved»); mvb's files stay whole — Unraid's move binary refuses a just-renamed share's files («No medium found»), as it
# would for Unraid's own mover — and her words say why (cells renamed-whole, renamed-said).
# Her settings are saved back by the undo (her own «save»). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-renamed-setup-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
A=uso-wip-c-mva B=uso-wip-c-mvb DEL=uso-wip-c-mvd O=uso-wip-c-mvo
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-YES "$A"
share_use C-M16 "$DEL" yes cache && put_files C-M16 cache "$DEL" 3 --size 64K || _row state share red "$DEL could not be made"
share_use C-M16 "$O" yes cache && put_files C-M16 cache "$O" 3 --size 64K || _row state share red "$O could not be made"
undo_push share_unuse C-M16
nc_data_closed
mv_settings_note C-M16 || _row state settings red "her settings could not be read"
undo_push mv_settings_back C-M16
rule='{skip: false, min_age: 0, move_when_used_above: null, files: [], patterns: ["*.keep"]}'
set=$(jq -c --arg a "$A" --arg d "$DEL" ".shares[\$a] = $rule | .shares[\$d] = $rule" "$KEEP_STASH/C-M16/moverelli.settings")
touch "$KEEP/.mv-touched"
mv_settings_save "$set" || _row state MV-SETUP red "her save was refused: $(ask_last '.error')"
share_rename "$A" "$B" && undo_push share_rename "$B" "$A" || _row state SH-RENAMED red "Unraid's form didn't rename $A"
state SH-DELETED "$DEL"
state SH-CFG-ORPHAN "$O"
nc_data_closed
expect_changes "share|$B|*"                 # the renamed share is new for Mr. Backupsy's setup
track_share "$B"
snap_before
ini=$D/moverelli/smart_mover.ini
sum=$(md5sum <"$ini")
flow MV-RESAVE && cell resave-same 1 "her setup saved as it is: smart_mover.ini unchanged" || cell resave-same 0 "MV-RESAVE: $(ask_last '.error // empty')"
for s in "$A" "$DEL"; do
    grep -qxF "[$s]" "$ini" && cell "rule-kept-$s" 1 "[$s] (Unraid no longer has it) is still in her ini" \
        || cell "rule-kept-$s" 0 "her rule for $s is gone from smart_mover.ini without a click"
done
grep -qxF "[$B]" "$ini" && cell no-rule-moved 0 "a section [$B] appeared by itself (her rule for $A moved over)" \
    || cell no-rule-moved 1 "no rule for $B made by itself"
ask moverelli.refresh '{}' 120 >/dev/null
g=$(jq --arg a "$A" --arg d "$DEL" '(.shares_gone // []) as $g | ($g | index($a) != null) and ($g | index($d) != null)' "$D/moverelli.json" 2>/dev/null)
[[ $g == true ]] && cell shares-gone 1 "her state lists $A and $DEL as shares Unraid no longer has" \
    || cell shares-gone 0 "her state's shares_gone: $(jq -c '.shares_gone // "missing"' "$D/moverelli.json" | head -c 200)"
log=$D/moverelli/smart_mover.log
lines0=$(wc -l <"$log" 2>/dev/null || echo 0)
flow MV-RUN
tail -n +"$((lines0 + 1))" "$log" 2>/dev/null >"$KEEP/smart_mover-run.log"
evidence "$KEEP/smart_mover-run.log" >/dev/null
grep -F "$O" "$KEEP/smart_mover-run.log" | grep -qiE 'skip|gone' && ! grep -F "$O" "$KEEP/smart_mover-run.log" | grep -q ERROR \
    && cell orphan-skipped 1 "her run skipped $O (a cfg without its share): $(grep -F "$O" "$KEEP/smart_mover-run.log" | head -1 | cut -c1-160)" \
    || cell orphan-skipped 0 "her run's log on $O: $(grep -F "$O" "$KEEP/smart_mover-run.log" | head -2 | tr '\n' ' ' | cut -c1-240)"
r=$(jq -r '.result // empty' "$D/moverelli/status.json" 2>/dev/null)
[[ $r =~ ^(ok|warnings)$ ]] && cell run-result 1 "her run ended $r" || cell run-result 0 "her run ended «$r»"
# the renamed share's files: Unraid's move binary refuses each with «No medium found» right after Unraid's rename (by
# hand, 2026-10-19: even 20 s later) — Unraid's mover leaves them the same way, so they stay whole on the pool; her
# words must give that reason, not «in use, or already present at the destination»
c=$(find "/mnt/cache/$B" -maxdepth 1 -type f 2>/dev/null | wc -l) n=0
for d in $(bases); do [[ $d == disk* ]] && n=$((n + $(find "/mnt/$d/$B" -type f 2>/dev/null | wc -l))); done
((c + n == 40)) && cell renamed-whole 1 "the 40 files of $B are whole: $n on the array, $c on the pool" || cell renamed-whole 0 "$B: $n on the array + $c on the pool, not 40"
if ((c > 0)); then
    grep -F "$B" "$KEEP/smart_mover-run.log" | grep -qiE 'no medium|medium' \
        && cell renamed-said 1 "her log gives the move binary's reason for the $c left (No medium found)" \
        || cell renamed-said 0 "$c files of $B left; her log says: «$(grep -A1 "moved .* of .* files" "$KEEP/smart_mover-run.log" | grep -m1 -oE 'still on the source side[^:]*')» — the move binary said «No medium found»"
fi
bubble_look renamed moverelli
look=$(ls -d "$KEEP"/looks/*-renamed 2>/dev/null | tail -1)
[[ -n $look ]] && queue_mac remove-rules page_text --open moverelli/setup "$look/states" "$PAGE_LANGS" want "moverelli.setup.own_gone moverelli.setup.own_remove"
[[ -n $look ]] && queue_mac INV-PAGE page_clean moverelli/setup "$look/states" "$PAGE_LANGS"
check standard
scenario_end
