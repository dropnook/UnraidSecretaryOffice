#!/bin/bash
# Cell R2 of matrix.md: Mr. Restori replaces something that is in use — never without asking, and what he puts aside is
# complete. Scratch share uso-wip-c-ro (only cache: a dataset), backed up once (the moment), then:
# - SH-OPEN-FILE: data/open.bin held open read+write by a process on the server (no container) — «replace» of
#   /mnt/user/uso-wip-c-ro/data (the folder the app uso-wip-c-ro-data binds — he restores what an app binds): the plan
#   must say a file in it is in use (a blocker, or a note the dialog shows) before anything is swapped (cell
#   asks-open). If it swaps anyway, data.aside-<stamp>/open.bin must be the file as it was (cell aside-whole).
# - CT-RUNNING (an app writing): the container uso-wip-c-writer appends to app/log.txt every 2 s — «replace» of
#   /mnt/user/uso-wip-c-ro/app: the plan stops it (plan.stops, the dialog names it — the asking), app.aside-<stamp>
#   holds every file of before, the container runs again afterwards.
# Preparation (before snap_before): the share set to «local» by Apply and one run; settings.ini put back by the undo,
# the scratch dataset's engine snapshots destroyed before Unraid removes it. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-open-file-replace
needs tower zfs docker
files_gone() { files_unput "$1" >/dev/null 2>&1; rm -f "$KEEP_STASH/$1/files"; return 0; }
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-ro W=uso-wip-c-writer H=uso-wip-c-ro-data
snaps_drop() { local s ds; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue; ds=cache/$s
    zfs list -H -t snapshot -o name "$ds" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; }
mkdir -p "$KEEP_STASH/C-R2-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-R2-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-R2-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed
undo_push snaps_drop "$S"
# SH-OPEN-FILE, in the app's folder data/: open.bin (and two more) — held open by a process
put_files C-R2 cache "$S/data" 2 --size 64K && put_files C-R2 cache "$S/data" 1 --size 1M --name open.bin \
    && put_files C-R2 cache "$S/app" 3 --size 64K || _row state files red "data/ and app/ could not be written"
undo_push files_gone C-R2             # what the flow left in them goes with the share's undo
# by its pool path: /mnt/user/$S is a symlink (an exclusive share) and /proc names the target (open_file looks there)
open_file C-R2 "/mnt/cache/$S/data/open.bin" || _row state SH-OPEN-FILE red "open.bin could not be held open"
undo_push close_files C-R2
ct_make C-R2 "$H" alpine:latest -v "/mnt/user/$S/data:/data" -- sleep 86400 || _row state app red "$H could not be made"
ct_make C-R2 "$W" alpine:latest -v "/mnt/user/$S/app:/data" -- sh -c 'while :; do date >> /data/log.txt; sleep 2; done' \
    || _row state CT-RUNNING red "the writer container could not be made"
undo_push ct_unmake C-R2
flow BK-APPLY "share|$S|mode=snapshot" || _row state prepare red "Apply of $S as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run failed"
zfs list -H -t snapshot -o name "cache/$S" 2>/dev/null | grep -q '@uso-backup-' || _row state prepare red "no moment of cache/$S"
expect_changes 'docker|*'                    # the scratch apps are new containers for Mr. Backupsy's setup
track_share "$S"
snap_before
# 1. the open file
sum=$(md5sum <"/mnt/cache/$S/data/open.bin")
ask restore.refresh '{}' 300 >/dev/null
pv=$(ask restore.preview "$(jq -cn --arg p "/mnt/user/$S/data" '{kind: "files", path: $p, mode: "swap"}')" 300)
printf '%s\n' "$pv" >"$KEEP/preview-open.json"
said=$(jq -r '[(.preview.blockers // [])[], (.preview.notes // [])[]] | map(select((.key | test("use|open|busy")) or ((.params // {}) | tostring | contains("open.bin")))) | map(.key) | join(",")' <<<"$pv" 2>/dev/null)
[[ -n $said ]] && cell asks-open 1 "the plan says open.bin is in use: $said" \
    || cell asks-open 0 "the plan to replace data/ (open.bin held open by a process) says nothing of it: blockers $(jq -c '[.preview.blockers[]?.key]' <<<"$pv"), notes $(jq -c '[.preview.notes[]?.key]' <<<"$pv")"
if jq -e '(.preview.blockers // []) | length == 0' <<<"$pv" >/dev/null 2>&1; then
    flow RS-FILES-REPLACE path="/mnt/user/$S/data"
    a=$(ls -d /mnt/cache/"$S"/data.aside-* 2>/dev/null | head -1)
    [[ -n $a && $(md5sum <"$a/open.bin" 2>/dev/null) == "$sum" ]] && cell aside-whole 1 "${a##*/}/open.bin is the file as it was" \
        || cell aside-whole 0 "after the swap: aside ${a:-missing}$([[ -n $a ]] && echo ", content differs")"
fi
# 2. the app writing into app/
before=$(find "/mnt/cache/$S/app" -type f -printf '%f\n' | sort)
pv=$(ask restore.preview "$(jq -cn --arg p "/mnt/user/$S/app" '{kind: "files", path: $p, mode: "swap"}')" 300)
printf '%s\n' "$pv" >"$KEEP/preview-app.json"
jq -e --arg w "$W" '(.preview.stops // []) | index($w) != null' <<<"$pv" >/dev/null \
    && cell asks-app 1 "the plan stops $W for the swap (the dialog names it)" \
    || cell asks-app 0 "the plan's stops: $(jq -c '.preview.stops' <<<"$pv") — $W writes into app/"
flow RS-FILES-REPLACE path="/mnt/user/$S/app"
a=$(ls -d /mnt/cache/"$S"/app.aside-* 2>/dev/null | head -1)
miss=$(comm -23 <(printf '%s\n' "$before") <(find "${a:-/nonexistent}" -type f -printf '%f\n' 2>/dev/null | sort) | paste -sd' ')
[[ -n $a && -z $miss ]] && cell aside-app 1 "${a##*/} holds every file app/ had" || cell aside-app 0 "aside ${a:-missing}, missing in it: ${miss:-?}"
[[ $(ct_status "$W") == running ]] && cell app-runs 1 "$W runs again" || cell app-runs 0 "$W is $(ct_status "$W") after the restore"
check standard
check final
scenario_end
