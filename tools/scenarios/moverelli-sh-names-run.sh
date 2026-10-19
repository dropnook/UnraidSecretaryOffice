#!/bin/bash
# Cell M4 of matrix.md: awkward names through Ms. Moverelli's setup and a real run (pool → array, as Unraid's mover).
# - SH-NAMES «uso-wip-c Ärger 's» (yes cache; space, umlaut, apostrophe — no rule of hers can name it: her sections
#   allow [\w.\- ]): the twelve put_names files (umlauts, quotes, $, \, leading space/dash/dot, CJK, 255 bytes, a tab,
#   «;» and «&») go to the array by her global rule (age 0, Tower's base), each whole, none left, no .partial.
# - «uso-wip-c Fotos Ärger» (yes cache): her own rule (section [uso-wip-c Fotos Ärger]) with her own list (umlauts):
#   «/mnt/user/uso-wip-c Fotos Ärger/Behalten Ö» (a folder) and «*.nfö» stay on the pool; «06 Juni /» (a folder whose
#   name ends in a space — the owner's server, 2026-10-18) goes to the array whole, no empty «06 Juni » left on the
#   pool; a file «Notiz » (trailing space) to the array stays on the pool as Unraid's mover leaves it (its move binary
#   drops the space), counted as left — never a file «Notiz» made beside it.
# Her settings are saved back by the undo (her own «save»). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-names-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S1="uso-wip-c Ärger 's" S2="uso-wip-c Fotos Ärger"
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-NAMES "$S1"
state SH-YES "$S2"                           # 40 files (20 old, 20 new) — her rule below moves them all (age 0)
nc_data_closed
put_files C-M4 cache "$S2/Behalten Ö" 3 --size 4K && put_files C-M4 cache "$S2/06 Juni " 3 --size 4K \
    && put_files C-M4 cache "$S2" 1 --size 4K --name 'Info.nfö' && put_files C-M4 cache "$S2" 1 --size 4K --name 'Notiz ' \
    || _row state files red "the files of $S2 could not be written"
undo_push files_unput C-M4
mv_settings_note C-M4 || _row state settings red "her settings could not be read"
undo_push mv_settings_back C-M4
set=$(jq -c --arg s "$S2" '.shares[$s] = {skip: false, min_age: 0, move_when_used_above: null, files: [],
    patterns: ["/mnt/user/\($s)/Behalten Ö", "*.nfö"]}' "$KEEP_STASH/C-M4/moverelli.settings")
touch "$KEEP/.mv-touched"
mv_settings_save "$set" || _row state MV-SETUP red "her save was refused: $(ask_last '.error')"
ini=$D/moverelli/smart_mover.ini
sec=$(awk -v s="[$S2]" '$0 == s { f = 1; next } /^\[/ { f = 0 } f' "$ini")
lst=$(sed -n 's/^excludes=//p' <<<"$sec" | tr ',' '\n' | tail -1)
if [[ -n $sec && -f $lst ]] && grep -qxF "/mnt/user/$S2/Behalten Ö" "$lst" && grep -qxF '*.nfö' "$lst"; then
    cell ini-section 1 "[$S2] written, its list $lst holds both lines as typed"
else
    cell ini-section 0 "section «$(tr '\n' ' ' <<<"$sec")», list «$lst»: $(cat "$lst" 2>/dev/null | tr '\n' '|')"
fi
track_share "$S1" "$S2"
snap_before
flow MV-RUN
on() { find "/mnt/$1/$2" -type f ! -name '*.partial' 2>/dev/null | wc -l; }
arr() { local n=0 d; for d in $(bases); do [[ $d == disk* ]] && n=$((n + $(on "$d" "$1"))); done; echo $n; }
# share 1: every awkward name on the array, none left on the pool
c1=$(on cache "$S1") a1=$(arr "$S1")
((c1 == 0 && a1 == 12)) && cell names-moved 1 "all 12 awkward names of «$S1» are on the array" \
    || cell names-moved 0 "«$S1»: $a1 of 12 on the array, $c1 still on the pool: $(find "/mnt/cache/$S1" -type f -printf '«%f» ' 2>/dev/null | head -c 300)"
# share 2: her list kept its folder and pattern on the pool
k=$(find "/mnt/cache/$S2/Behalten Ö" -type f 2>/dev/null | wc -l)
[[ $k == 3 && -f "/mnt/cache/$S2/Info.nfö" ]] && cell list-umlauts 1 "«Behalten Ö/» (3) and «Info.nfö» stayed on the pool (her list)" \
    || cell list-umlauts 0 "on the pool after her run: Behalten Ö $k of 3, Info.nfö $([[ -f "/mnt/cache/$S2/Info.nfö" ]] && echo yes || echo NO)"
# the folder whose name ends in a space: its files on the array, no emptied «06 Juni » left on the pool
j=0; for d in $(bases); do [[ $d == disk* ]] && j=$((j + $(find "/mnt/$d/$S2/06 Juni " -type f 2>/dev/null | wc -l))); done
if [[ $j == 3 && ! -e "/mnt/cache/$S2/06 Juni " ]]; then cell trailing-space-folder 1 "«06 Juni /» went to the array whole, nothing left on the pool"
else cell trailing-space-folder 0 "«06 Juni /»: $j of 3 files on the array, on the pool: $(find "/mnt/cache/$S2/06 Juni " 2>/dev/null | wc -l) entries"; fi
# a file whose name ends in a space: stays (as Unraid's mover leaves it), counted as left, nothing «Notiz» beside it
st=$D/moverelli/status.json
left=$(jq -r --arg s "$S2" '[.shares[]? | select(.share == $s) | .left] | first // "none"' "$st" 2>/dev/null)
twin=$(find /mnt/*/"$S2" -maxdepth 1 -name 'Notiz' 2>/dev/null | head -1)
if [[ -f "/mnt/cache/$S2/Notiz " && -z $twin && $left =~ ^[1-9] ]]; then cell trailing-space-file 1 "«Notiz » stayed on the pool, counted as left ($left), no «Notiz» made"
else cell trailing-space-file 0 "«Notiz » on the pool: $([[ -f "/mnt/cache/$S2/Notiz " ]] && echo yes || echo NO), left=$left, a twin: ${twin:-none}"; fi
pa=$(find /mnt/cache/"$S1" /mnt/cache/"$S2" /mnt/disk*/"$S1" /mnt/disk*/"$S2" -name '*.partial' 2>/dev/null | head -3)
[[ -z $pa ]] && cell no-partial 1 "no .partial anywhere in both shares" || cell no-partial 0 "left: $pa"
r=$(jq -r '.result // empty' "$st" 2>/dev/null)
[[ $r =~ ^(ok|warnings)$ ]] && cell run-result 1 "her run ended $r" || cell run-result 0 "her run ended «$r»: $(jq -c '[.shares[]? | select(.state == "error") | .share]' "$st" 2>/dev/null)"
bubble_look names-run moverelli
look=$(ls -d "$KEEP"/looks/*-names-run 2>/dev/null | tail -1)
[[ -n $look ]] && queue_mac INV-PAGE page_clean moverelli "$look/states" "$PAGE_LANGS"
check standard
scenario_end
