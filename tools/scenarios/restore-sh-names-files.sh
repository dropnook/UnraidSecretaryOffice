#!/bin/bash
# Cell R1 of matrix.md: awkward names on Mr. Restori's way back (JSON and shell escaping, rsCleanPath). The share
# uso-wip-c-rn (only cache: a dataset — a share name no dataset can carry has no moment of its own) holds only the
# twelve put_names files (umlauts, quotes, $, \, leading space/dash/dot, CJK, 255 bytes, a tab, «;» and «&»); the app
# uso-wip-c-rnapp binds the whole share (he restores what an app binds: the share's top entries are its items).
# - RS-FILES-BESIDE of the share's entries (the dialog's ticked items): the eleven names back beside as
#   «<name>.restored-<stamp>», the same bytes (cell beside); the name of 255 bytes on its own (cell long-name — its copy's
#   name would be longer than a name may be: in the first run, 2026-10-19, rsync failed and the whole restore with it).
# - RS-FILES-REPLACE after the user deleted «it's here.mkv» and changed «Ümläut Ärger.txt»: the eleven as in the
#   moment, each live one aside as «<name>.aside-<stamp>» (cell replace).
# - RS-DRILL, its «one file of a share» step in this share (only awkward names in it — every one ≥ 1 KB, so the drill's
#   file is one of them): checked ok, its scratch folder in the storeroom (cell drill-sharefile). The drill picks one share
#   a day (restore-drill.php drillShareFilePick: sorted, day number modulo their count), so empty scratch shares
#   uso-wip-c-a<n> / uso-wip-c-z<n> are added around this one until today's pick is it.
# Preparation (before snap_before): Apply of the shares as «local» and one run; settings.ini put back by the undo, the
# scratch datasets' engine snapshots destroyed before Unraid removes them. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-names-files
needs tower zfs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-rn APP=uso-wip-c-rnapp
snaps_drop() { local s x; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue
    zfs list -H -t snapshot -o name "cache/$s" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; }
mkdir -p "$KEEP_STASH/C-R1-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-R1-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-R1-INI" "$UB/settings.ini"
nc_data_closed
share_use C-R1 "$S" only cache || _row state share red "$S could not be made"
undo_push share_unuse C-R1
state SH-NAMES "$S"                          # the share is there (only): the twelve names into it
mapfile -t NAMES < <(find "/mnt/cache/$S" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)
((${#NAMES[@]} == 12)) || _row state SH-NAMES red "${#NAMES[@]} names in $S, not 12"
# settled files: the drill's sample is a file not changed in the hour before the snapshot (1.70.2, restore-drill.php
# DRILL_FILE_SETTLED, by mtime) — written now they'd all be too fresh, the step «not checked» (sharefile_none)
find "/mnt/cache/$S" -type f -exec touch -m -d '1 day ago' {} +
declare -A SUM=(); for f in "${NAMES[@]}"; do SUM[$f]=$(md5sum <"/mnt/cache/$S/$f"); done
# today's drill pick: pads so that it is this share (see the header)
pick=($(awk -F'"' '/^\[share "/ { s = $2; next } /^\[/ { s = "" } s != "" && /^mode *= */ { m = $0; sub(/^mode *= */, "", m); if (m != "off") print s }' "$UB/settings.ini" \
    | grep -E '^[A-Za-z0-9_ .-]+$' | grep -vx UnraidSecretaryOffice))
day=$(( $(date +%s) / 86400 )) base_n=$(( ${#pick[@]} + 1 )) pre=-1 post=0
for ((n = base_n; n <= base_n + 12; n++)); do
    i=$((day % n)) p=$((i - (base_n - 1)))
    if ((p >= 0 && p <= n - base_n)); then pre=$p post=$((n - base_n - p)); break; fi
done
pads=(); for ((k = 1; k <= pre; k++)); do pads+=("uso-wip-c-a$k"); done; for ((k = 1; k <= post; k++)); do pads+=("uso-wip-c-z$k"); done
say "the drill's shares today: ${pick[*]} + $S (day $day): $pre before, $post after"
((pre >= 0)) || _row state pick red "no pad count up to +12 makes $S today's pick"
for p in "${pads[@]}"; do share_use C-R1-PAD "$p" only cache >/dev/null && put_files C-R1-PAD cache "$p" 1 --size 4K >/dev/null \
    || _row state pad red "pad share $p could not be made"; done
undo_push share_unuse C-R1-PAD
nc_data_closed
undo_push snaps_drop "$S" "${pads[@]}"
ct_make C-R1 "$APP" alpine:latest -v "/mnt/user/$S:/data" -- sleep 86400 || _row state app red "$APP could not be made"
undo_push ct_unmake C-R1
apply=("share|$S|mode=snapshot"); for p in "${pads[@]}"; do apply+=("share|$p|mode=snapshot"); done
flow BK-APPLY "${apply[@]}" || _row state prepare red "Apply of $S (and pads) as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run failed"
zfs list -H -t snapshot -o name "cache/$S" 2>/dev/null | grep -q '@uso-backup-' || _row state prepare red "no moment of cache/$S"
expect_changes 'docker|*'                    # the scratch app is a new container for Mr. Backupsy's setup
track_share "$S"
snap_before
# the dialog's ticked entries (desk.js ~2044 sends {snap, items}): the long name apart (its own cell), the eleven others
long=$(printf 'n%.0s' {1..251}).txt
rs_items() { local mode=$1 it; shift; _fl_args      # the names through stdin: «-dash» as an argument would be an option of jq
    it=$(printf '%s\0' "$@" | jq -Rsc 'split("\u0000") | .[:-1]')
    _rs_go "$(jq -cn --arg p "/mnt/user/$S" --arg m "$mode" --argjson i "$it" '{kind: "files", path: $p, mode: $m, items: $i}')" || return 1
    _rs_compare "$(fl_last_restore)"; }           # INV-COMPARE: each item's copy against the moment (owner, mode too)
ELEVEN=(); for f in "${NAMES[@]}"; do [[ $f == "$long" ]] || ELEVEN+=("$f"); done
# 1. beside: the eleven back next to themselves
rs_items copy "${ELEVEN[@]}" || say "the restore beside of the eleven: $(ask_last '.error // empty')"
bad=''
for f in "${ELEVEN[@]}"; do
    r=$(find "/mnt/cache/$S" -maxdepth 1 -name "$(printf '%s' "$f" | sed 's/[][*?\\]/\\&/g').restored-*" 2>/dev/null | head -1)
    [[ -n $r && $(md5sum <"$r") == "${SUM[$f]}" ]] || bad+=" «$f»"
done
offered=$(jq -r '.target.items // [] | length' "$KEEP/rs-preview.json" 2>/dev/null)
[[ -z $bad ]] && cell beside 1 "the 11 names back beside, the same bytes" \
    || cell beside 0 "not back (or other bytes) beside:$bad — his plan took $offered of the 11 ticked (a name with a control character is left out of the share's entries, unsaid)"
# the copies beside go (the user deletes them) before the swap — else they'd be entries of the share too
find "/mnt/cache/$S" -maxdepth 1 -name '*.restored-*' -exec rm -rf {} +
# 2. replace: one deleted, one changed — both as in the moment, the live one aside
rm -f "/mnt/cache/$S/it's here.mkv"; printf 'changed\n' >"/mnt/cache/$S/Ümläut Ärger.txt"
rs_items swap "${ELEVEN[@]}" || say "the swap of the eleven: $(ask_last '.error // empty')"
bad=''
for f in "${ELEVEN[@]}"; do [[ -f /mnt/cache/$S/$f && $(md5sum <"/mnt/cache/$S/$f") == "${SUM[$f]}" ]] || bad+=" «$f»"; done
a=$(find "/mnt/cache/$S" -maxdepth 1 -name 'Ümläut Ärger.txt.aside-*' | head -1)
[[ -z $bad && -n $a && $(cat "$a") == changed ]] && cell replace 1 "the eleven as in the moment, the changed one aside as ${a##*/}" \
    || cell replace 0 "after the swap: not as in the moment:${bad:- none}; the changed one aside: ${a:-missing}"
# 2b. the name of 255 bytes: its copy beside would be «<name>.restored-<stamp>» — longer than a name may be
rs_items copy "$long"
r=$(find "/mnt/cache/$S" -maxdepth 1 -name 'nnnn*.restored-*' 2>/dev/null | head -1)
id=$(fl_last_restore)
if [[ -n $r && $(md5sum <"$r") == "${SUM[$long]}" ]]; then cell long-name 1 "the 255-byte name came back beside"
else cell long-name 0 "the 255-byte name: restore ${id:-?} $(jq -r '.result // "?"' "$D/restore/$id/journal.json" 2>/dev/null) — $(grep -m1 -o 'rsync: .*' "$D/restore/$id/log.txt" 2>/dev/null | cut -c1-120)…"; fi
find "/mnt/cache/$S" -maxdepth 1 -name '*.restored-*' -exec rm -rf {} +
# 3. the drill's share file
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
j=$D/restore-drill/$id/journal.json
sf=$(jq -c '[.steps[]? | select(.do == "sharefile" or .kind == "share")] | first // empty' "$j" 2>/dev/null)
say "the drill's share step: ${sf:-none}"
sh=$(jq -r '.id // .name // .params.share // empty' <<<"$sf" 2>/dev/null) stt=$(jq -r '.state // empty' <<<"$sf" 2>/dev/null)
file=$(jq -r '.params.file // empty' <<<"$sf" 2>/dev/null)
kept=$(find "/mnt/cache/$S/.UnraidSecretaryOffice-trash" -maxdepth 4 -name ".uso-drill-$id*" 2>/dev/null | head -1)
if [[ $sh != "$S" ]]; then cell drill-sharefile 0 "today's drill took the share «${sh:-none}», not $S (the pads' count is wrong)"
elif [[ $stt == ok && -n $kept && -z $(find "/mnt/cache/$S" -maxdepth 1 -name '.uso-drill-*') ]]; then cell drill-sharefile 1 "the drill brought «$file» back and checked it, its folder in the storeroom"
else cell drill-sharefile 0 "the drill's step on «$file»: state ${stt:-?} ($(jq -r '.code // empty' <<<"$sf")), in the storeroom: ${kept:-no}"; fi
check standard
check final
scenario_end
