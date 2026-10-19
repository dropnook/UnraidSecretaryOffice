#!/bin/bash
# Cell R16 of matrix.md: a folder of a share that lies on two parts (SH-MULTI-DISK: the same folder serie1/ on both) —
# what Mr. Restori brings back lands on a part the share may use, where his plan said (shfs places it by the share's
# settings), and nothing of the other part is lost.
# On Tower only cache and disk2 are ZFS (disk1 is xfs: a share with a part there has no local moment at all — the
# engine reads it live), so the restorable case is uso-wip-c-md: yes cache, include=disk2, serie1/ with p-*.bin on the
# pool and d-*.bin on disk2 (names of their own: the state's put_files would make the same names on both, duplicates
# shfs hides). The app uso-wip-c-mdapp binds /mnt/user/uso-wip-c-md/serie1 (he restores what an app binds).
# - RS-FILES-REPLACE of serie1 after d-0002.bin was deleted on disk2: both parts' files from the moment (a union),
#   every file back, each on cache or disk2 (never disk1, which the share excludes), the pool ones where the plan's
#   note.files_place said (cells union, landed); serie1.aside-<stamp> holds what was there.
# - SH-MULTI-DISK on the array (uso-wip-c-mda: disk1 xfs + disk2 zfs, the state as zustaende.md has it, bound by
#   uso-wip-c-mdaapp): his plan refuses plainly — no moment (restore_no_snapshot / restore_not_in_snapshot), never a
#   half restore of the disk2 part as if it were all (cell array-refused).
# Preparation (before snap_before): Apply of both shares as «local» and one run; settings.ini put back by the undo,
# the scratch datasets' engine snapshots destroyed before Unraid removes them. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-multi-disk-files
needs tower zfs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-md SA=uso-wip-c-mda APP=uso-wip-c-mdapp APPA=uso-wip-c-mdaapp
snaps_drop() { local s b x; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue; for b in cache disk2; do
    zfs list -H -t snapshot -o name "$b/$s" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; done; }
mkdir -p "$KEEP_STASH/C-R16-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-R16-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-R16-INI" "$UB/settings.ini"
nc_data_closed
share_use C-R16 "$S" yes cache && share_apply "$S" shareInclude=disk2 || _row state share red "$S could not be made"
# «Add Share» of a yes share leaves an empty root on disk1 (STATES.md day-1 finding 1) — on xfs that would make the whole
# share «live» (no snapshot); disk1 is excluded now, so its empty root goes (rmdir: only an empty one)
rmdir "/mnt/disk1/$S" 2>/dev/null
put_files C-R16 cache "$S/serie1" 3 --size 64K --name p.bin && put_files C-R16 disk2 "$S/serie1" 3 --size 64K --name d.bin \
    || _row state files red "serie1/ could not be written on both parts"
undo_push share_unuse C-R16
state SH-MULTI-DISK "$SA"
nc_data_closed
undo_push snaps_drop "$S" "$SA"
ct_make C-R16 "$APP" alpine:latest -v "/mnt/user/$S/serie1:/data" -- sleep 86400 \
    && ct_make C-R16 "$APPA" alpine:latest -v "/mnt/user/$SA/serie1:/data" -- sleep 86400 || _row state app red "the scratch apps could not be made"
undo_push ct_unmake C-R16
flow BK-APPLY "share|$S|mode=snapshot" "share|$SA|mode=snapshot" || _row state prepare red "Apply of the shares as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run failed"
for b in cache disk2; do zfs list -H -t snapshot -o name "$b/$S" 2>/dev/null | grep -q '@uso-backup-' || _row state prepare red "no moment of $b/$S"; done
expect_changes 'docker|*'                    # the scratch apps are new containers for Mr. Backupsy's setup
expect_warnings share_snapshot_no_fs share_no_snapshots      # uso-wip-c-mda at local on xfs + zfs: said, not backed up
track_share "$S" "$SA"
snap_before
rm -f "/mnt/disk2/$S/serie1/d-0002.bin"      # the user deleted one (it lay on disk2)
flow RS-FILES-REPLACE path="/mnt/user/$S/serie1"
pv=$KEEP/rs-preview.json
prim=$(jq -r '[.notes[]? | select(.key == "note.files_place" or .key == "note.files_place2") | .params.primary] | first // empty' "$pv")
srcs=$(jq -r '[.steps[]? | select(.do == "copy") | .sources[]?] | join(" ")' "$pv")
[[ $srcs == *"/cache/"* || $srcs == *"/mnt/cache"* ]] && [[ $srcs == *disk2* ]] && cell union 1 "the copy comes from both parts' moments: $srcs" \
    || cell union 0 "the copy's sources: ${srcs:-none} (want the pool's and disk2's moment)"
bad='' n=0
for f in p-0001.bin p-0002.bin p-0003.bin d-0001.bin d-0002.bin d-0003.bin; do
    w=$(disk_of_file "/mnt/user/$S/serie1/$f")
    [[ -n $w ]] && n=$((n + 1))
    case $w in cache|disk2) [[ $w == cache && -n $prim && $prim != cache ]] && bad+=" $f@$w(plan:$prim)" ;; '') bad+=" $f@missing" ;; *) bad+=" $f@$w" ;; esac
done
[[ -z $bad && $n == 6 ]] && cell landed 1 "all 6 back, each on cache or disk2 (the plan's place: ${prim:-?})" || cell landed 0 "$n of 6 back;$bad"
a=$(ls -d /mnt/user/"$S"/serie1.aside-* 2>/dev/null | head -1)
[[ -n $a && $(find "$a" -type f | wc -l) == 5 ]] && cell aside 1 "${a##*/} holds the 5 that were there" || cell aside 0 "aside ${a:-missing} with $(find "${a:-/nonexistent}" -type f 2>/dev/null | wc -l) of 5"
# the array share over xfs + zfs: refused plainly
pv=$(ask restore.preview "$(jq -cn --arg p "/mnt/user/$SA/serie1" '{kind: "files", path: $p, mode: "swap"}')" 300)
k=$(jq -r '((.preview.blockers // [])[0].key) // .error.key // "none"' <<<"$pv" 2>/dev/null)
[[ $k == restore_no_snapshot || $k == restore_not_in_snapshot ]] && cell array-refused 1 "$SA/serie1 (disk1 xfs + disk2): refused, $k" \
    || cell array-refused 0 "$SA/serie1: «$k», steps $(jq -c '[.preview.steps[]?.do]' <<<"$pv" 2>/dev/null), sources $(jq -c '[.preview.steps[]? | .sources[]?]' <<<"$pv" 2>/dev/null | head -c 200)"
check standard
check final
scenario_end
