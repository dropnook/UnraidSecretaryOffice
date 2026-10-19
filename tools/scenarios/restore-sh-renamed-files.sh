#!/bin/bash
# Cell R8 of matrix.md: Mr. Restori after a share was renamed (SH-RENAMED, Unraid's form: its dataset renamed with the
# snapshots). He restores what an app binds (rsUnitOwner: an app's folder from its package), so the scratch share is
# bound by two scratch apps: uso-wip-c-r8old binds uso-wip-c-r8a/album from before the rename (its package keeps that
# path), uso-wip-c-r8new is the user's app made afterwards on uso-wip-c-r8b/album.
# - RS-SCAN after the rename and a run: his look answers.
# - RS-FILES to the old name (r8old's folder): refused plainly — «The share uso-wip-c-r8a is missing»
#   (restore_share_missing, a blocker); never anything restored silently elsewhere.
# - RS-FILES beside to the new name (r8new's folder): the moment taken under the old name is offered (the snapshot
#   went along with the dataset) and comes back whole (INV-COMPARE).
# SH-DELETED (Unraid's «Delete») can't be made of a share that has snapshots (STATES.md: Unraid refuses it), and a
# share without snapshots has no moment to restore: its «missing» side is the old name here.
# Preparation (before snap_before): the share set to «local» by Apply and one run; settings.ini put back by the undo,
# the scratch dataset's engine snapshots destroyed before Unraid removes it. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-renamed-files
needs tower zfs docker
files_gone() { files_unput "$1" >/dev/null 2>&1; rm -f "$KEEP_STASH/$1/files"; return 0; }
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
A=uso-wip-c-r8a B=uso-wip-c-r8b OLD=uso-wip-c-r8old NEW=uso-wip-c-r8new
snaps_drop() { local s ds; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue; ds=cache/$s
    zfs list -H -t snapshot -o name "$ds" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; }
mkdir -p "$KEEP_STASH/C-R8-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-R8-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-R8-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$A"
put_files C-R8 cache "$A/album" 4 --size 64K || _row state files red "album/ could not be written"
undo_push files_gone C-R8             # what the flow left in them goes with the share's undo
nc_data_closed
undo_push snaps_drop "$A" "$B"
ct_make C-R8 "$OLD" alpine:latest -v "/mnt/user/$A/album:/data" -- sleep 86400 || _row state app red "$OLD could not be made"
undo_push ct_unmake C-R8
flow BK-APPLY "share|$A|mode=snapshot" || _row state prepare red "Apply of $A as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run failed"
first=$(zfs list -H -t snapshot -o name -s creation "cache/$A" 2>/dev/null | grep '@uso-backup-' | tail -1)
[[ -n $first ]] || _row state prepare red "no engine snapshot of cache/$A"
ct_stop "$OLD" >/dev/null                    # its folder's dataset is renamed: the user stops the app first (a bind keeps it busy)
share_rename "$A" "$B" && undo_push share_rename "$B" "$A" || _row state SH-RENAMED red "Unraid's form didn't rename $A"
nc_data_closed
ct_make C-R8N "$NEW" alpine:latest -v "/mnt/user/$B/album:/data" -- sleep 86400 || _row state app red "$NEW could not be made"
undo_push ct_unmake C-R8N
expect_changes 'docker|*'                    # the scratch apps are new containers for Mr. Backupsy's setup
expect_changes "share|$A|*" "share|$B|*"   # the rename: his setup lists it (Apply follows by the user)
track_share "$B"
snap_before
flow BK-RUN-MANUAL mode=backup               # the next run: the new app's package names its folder in $B
flow RS-SCAN && cell scan 1 "his look after the rename answered" || cell scan 0 "his look failed: $(ask_last '.error')"
# the old name: refused plainly
pv=$(ask restore.preview "$(jq -cn --arg p "/mnt/user/$A/album" '{kind: "files", path: $p, mode: "copy"}')" 300)
printf '%s\n' "$pv" >"$KEEP/preview-old.json"
k=$(jq -r '((.preview.blockers // [])[0].key) // .error.key // "none"' <<<"$pv" 2>/dev/null)
[[ $k == restore_share_missing ]] && cell old-name-refused 1 "a restore into $A is refused: $k" \
    || cell old-name-refused 0 "a restore into the old name $A: «$k» ($(jq -c '{blockers: .preview.blockers, steps: (.preview.steps | length)}' <<<"$pv" 2>/dev/null))"
# the new name: the moment from before the rename, offered and whole
flow RS-FILES-BESIDE path="/mnt/user/$B/album"
mid=$(jq -r '.moment.id // .target.snap // empty' "$KEEP/rs-preview.json" 2>/dev/null)
all=$(jq -c '[.options.moments[]?.id]' "$KEEP/rs-preview.json" 2>/dev/null)
[[ $all == *"${first##*@uso-backup-}"* ]] && cell moment-new-name 1 "the moment ${first#*@} (taken as $A) is offered for $B: $all" \
    || cell moment-new-name 0 "the moments offered for $B: ${all:-none} — the one from before the rename: ${first#*@}"
r=$(ls -d /mnt/cache/"$B"/album.restored-* 2>/dev/null | head -1)
n=$(find "${r:-/nonexistent}" -type f 2>/dev/null | wc -l)
[[ -n $r && $n == 4 ]] && cell new-name-restored 1 "album/ came back beside from ${mid:-?}: ${r##*/}" \
    || cell new-name-restored 0 "beside copy ${r:-missing} with $n of 4 files (moment ${mid:-?})"
check standard
check final
scenario_end
