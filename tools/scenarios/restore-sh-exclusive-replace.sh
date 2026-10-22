#!/bin/bash
# Cell R15 of matrix.md: Mr. Restori in an exclusive share (SH-EXCLUSIVE: /mnt/user/uso-wip-c-rex is a symlink to the
# pool's dataset cache/uso-wip-c-rex).
# - RS-FILES-REPLACE of /mnt/user/uso-wip-c-rex/dir: the swap renames through the symlink — dir.aside-<stamp> and the
#   restored dir both in the pool's folder, the symlink still there and pointing at the pool (cells symlink-kept,
#   swapped), the content the moment's (INV-COMPARE).
# - RS-DRILL, its «one file of a share» step in this share: the scratch copy .uso-drill-<id> made on the pool and moved
#   into Ms. Dustdevil's storeroom of the share at once (cell drill-sharefile). The drill picks one share a day from the
#   shares backed up locally (sorted, day number modulo their count — restore-drill.php drillShareFilePick), so empty
#   scratch shares uso-wip-c-a<n> / uso-wip-c-z<n> are added around this one until today's pick is it.
# The app uso-wip-c-rexapp binds /mnt/user/uso-wip-c-rex/dir (he restores what an app binds).
# Preparation (before snap_before): the shares set to «local» by Apply and one run; settings.ini put back by the undo,
# the scratch datasets' engine snapshots destroyed before Unraid removes them. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-exclusive-replace
needs tower zfs docker exclusive
files_gone() { files_unput "$1" >/dev/null 2>&1; rm -f "$KEEP_STASH/$1/files"; return 0; }
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-rex APP=uso-wip-c-rexapp
snaps_drop() { local s ds; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue; ds=cache/$s
    zfs list -H -t snapshot -o name "$ds" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; }
mkdir -p "$KEEP_STASH/C-R15-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-R15-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-R15-INI" "$UB/settings.ini"
nc_data_closed
state SH-EXCLUSIVE "$S"
put_files C-R15 cache "$S/dir" 4 --size 64K || _row state files red "dir/ could not be written"
# settled files: the drill's sample is a file not changed in the hour before the snapshot (1.70.2, restore-drill.php
# DRILL_FILE_SETTLED, by mtime) — written now they'd all be too fresh, the step «not checked» (sharefile_none)
find "/mnt/cache/$S/dir" -type f -exec touch -m -d '1 day ago' {} +
undo_push files_gone C-R15             # what the flow left in them goes with the share's undo
# today's pick of the drill: the shares backed up locally (settings.ini, mode not off, a name the drill takes, not the
# office's own) + this one + pads, sorted; index = day number % count — pads before (a) or after (z) this one until it is
pick=($(awk -F'"' '/^\[share "/ { s = $2; next } /^\[/ { s = "" } s != "" && /^mode *= */ { m = $0; sub(/^mode *= */, "", m); if (m != "off") print s }' "$UB/settings.ini" \
    | grep -E '^[A-Za-z0-9_ .-]+$' | grep -vx UnraidSecretaryOffice))
day=$(( $(date +%s) / 86400 )) base_n=$(( ${#pick[@]} + 1 )) pre=-1 post=0
for ((n = base_n; n <= base_n + 12; n++)); do
    i=$((day % n)) p=$((i - (base_n - 1)))
    if ((p >= 0 && p <= n - base_n)); then pre=$p post=$((n - base_n - p)); break; fi
done
pads=()
for ((k = 1; k <= pre; k++)); do pads+=("uso-wip-c-a$k"); done
for ((k = 1; k <= post; k++)); do pads+=("uso-wip-c-z$k"); done
say "the drill's shares today: ${pick[*]} + $S (day $day): $pre before, $post after → ${#pads[@]} pads"
((pre >= 0)) || _row state pick red "no pad count up to +12 makes $S today's pick"
for p in "${pads[@]}"; do share_use C-R15-PAD "$p" only cache >/dev/null && put_files C-R15-PAD cache "$p" 1 --size 4K >/dev/null \
    || _row state pad red "pad share $p could not be made"; done
undo_push share_unuse C-R15-PAD
nc_data_closed
undo_push snaps_drop "$S" "${pads[@]}"
ct_make C-R15 "$APP" alpine:latest -v "/mnt/user/$S/dir:/data" -- sleep 86400 || _row state app red "$APP could not be made"
undo_push ct_unmake C-R15
expect_changes 'docker|*'                    # the scratch app is a new container for Mr. Backupsy's setup
apply=("share|$S|mode=snapshot"); for p in "${pads[@]}"; do apply+=("share|$p|mode=snapshot"); done
flow BK-APPLY "${apply[@]}" || _row state prepare red "Apply of $S (and pads) as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run failed"
zfs list -H -t snapshot -o name "cache/$S" 2>/dev/null | grep -q '@uso-backup-' || _row state prepare red "no moment of cache/$S"
track_share "$S"
snap_before
# 1. the swap through the symlink
flow RS-FILES-REPLACE path="/mnt/user/$S/dir"
l=$(readlink -f "/mnt/user/$S")              # Unraid's link is relative (../cache/<share>)
[[ -L /mnt/user/$S && $l == "/mnt/cache/$S" ]] && cell symlink-kept 1 "/mnt/user/$S is still the symlink to /mnt/cache/$S" \
    || cell symlink-kept 0 "/mnt/user/$S: $(stat -c %F "/mnt/user/$S" 2>&1) → «$l»"
a=$(ls -d /mnt/cache/"$S"/dir.aside-* 2>/dev/null | head -1)
n=$(find "/mnt/cache/$S/dir" -type f 2>/dev/null | wc -l)
[[ -n $a && $n == 4 && $(find "$a" -type f | wc -l) == 4 ]] && cell swapped 1 "dir/ restored (4 files), the old one as ${a##*/} on the pool" \
    || cell swapped 0 "after the swap: dir/ $n files, aside ${a:-missing}"
# 2. the drill: its «one file of a share» step here
flow RS-DRILL
id=$(jq -r 'select(.what == "drill") | .data.id' "$KEEP/flows.jsonl" 2>/dev/null | tail -1)
j=$D/restore-drill/$id/journal.json
sf=$(jq -c '[.steps[]? | select(.do == "sharefile" or .kind == "share")] | first // empty' "$j" 2>/dev/null)
say "the drill's share step: ${sf:-none}"
sh=$(jq -r '.id // .name // .params.share // empty' <<<"$sf" 2>/dev/null) stt=$(jq -r '.state // empty' <<<"$sf" 2>/dev/null)
left=$(find "/mnt/cache/$S" -maxdepth 1 -name '.uso-drill-*' 2>/dev/null | head -1)
kept=$(find "/mnt/cache/$S/.UnraidSecretaryOffice-trash" -maxdepth 4 -name ".uso-drill-$id*" 2>/dev/null | head -1)
if [[ $sh != "$S" ]]; then
    cell drill-sharefile 0 "today's drill took the share «${sh:-none}», not $S (the pads' count is wrong)"
elif [[ $stt == ok && -z $left && -n $kept ]]; then
    cell drill-sharefile 1 "the drill's copy in $S checked ($stt), its scratch folder in the storeroom: ${kept#/mnt/cache/$S/}"
else
    cell drill-sharefile 0 "the drill's step in $S: state ${stt:-?}, scratch left at the top: ${left:-no}, in the storeroom: ${kept:-no}"
fi
check standard
check final
scenario_end
