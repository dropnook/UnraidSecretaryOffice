#!/bin/bash
# Cell R3 of matrix.md: «Put back» after a replacing restore, with changes in between. EmbyServer's app folder
# (appdata/emby — Mr. Restori restores app and VM folders, not any folder of a share) with 10 files of ours in it is
# backed up (the moment), then changed by the user (one file edited, one deleted, one added); Mr. Restori replaces the
# folder with the moment's (RS-FILES-REPLACE: Emby stopped, the changed folder set aside first); the user then adds a
# file to the restored folder; «Put back» (RS-PUTBACK). What must hold: put back undoes only what the restore
# recorded — the folder is the changed one again, file for file — and nothing is lost: the file added after the
# restore is still there (in the folder, or set aside beside it). The undo removes our files and what the restores set
# aside beside appdata/emby. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-changes-between-putback
needs tower zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
F=/mnt/cache/appdata/emby/uso-wip-d-r3
U=/mnt/user/appdata/emby
sums() { (cd "$1" 2>/dev/null && find . -type f -exec md5sum {} + | sort -k2); }
put_files R3 cache appdata/emby/uso-wip-d-r3 10 || _row state put_files red "the files could not be written"
undo_push files_unput R3
# (pushed after: runs first — the restore leaves the folder changed and its asides beside it)
undo_push bash -c 'rm -rf /mnt/cache/appdata/emby/uso-wip-d-r3 /mnt/cache/appdata/emby.aside-* /mnt/cache/appdata/emby.restored-* /mnt/cache/appdata/emby.putback-*'
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the moment
# the user's changes after the backup
head -c 4096 /dev/urandom >>"$F/f-0003.bin"; rm -f "$F/f-0005.bin"; head -c 65536 /dev/urandom >"$F/new-after-backup.bin"
chown nobody:users "$F"/*; sums "$F" >"$KEEP/changed.md5"
snap_before
flow RS-FILES mode=replace path="$U"
rid=$(fl_last_restore)
sums "$F" >"$KEEP/restored.md5"
grep -q 'f-0005.bin' "$KEEP/restored.md5" && ! grep -q new-after-backup "$KEEP/restored.md5" \
    && cell replaced 1 "the folder is the moment's (f-0005 back, the new file not in it)" || cell replaced 0 "after the replace: $(wc -l <"$KEEP/restored.md5") files, not the moment's"
# the user works in the restored folder before putting it back
head -c 32768 /dev/urandom >"$F/added-after-restore.bin"; chown nobody:users "$F/added-after-restore.bin"
md5sum "$F/added-after-restore.bin" | awk '{print $1}' >"$KEEP/added.md5"
flow RS-PUTBACK of="$rid"
sums "$F" | grep -v added-after-restore >"$KEEP/putback.md5"
diff -q "$KEEP/changed.md5" "$KEEP/putback.md5" >/dev/null && cell undo-exact 1 "after «Put back» the folder is the changed one again, file for file" \
    || cell undo-exact 0 "after «Put back»: $(diff "$KEEP/changed.md5" "$KEEP/putback.md5" | grep '^[<>]' | head -3 | tr '\n' ' ')"
want=$(cat "$KEEP/added.md5")
found=$(find /mnt/cache/appdata -maxdepth 4 -path '*/emby*/uso-wip-d-r3/*' -name added-after-restore.bin -type f 2>/dev/null | while read -r f; do [[ $(md5sum <"$f" | awk '{print $1}') == "$want" ]] && echo "$f"; done | head -1)
[[ -n $found ]] && cell nothing-lost 1 "the file added after the restore is kept: ${found#/mnt/cache/appdata/}" \
    || cell nothing-lost 0 "the file added after the restore is gone (not in the folder nor set aside)"
row=$(state_json restore '[.restores[]? | select(.id == "'"$rid"'")][0] | {result, can_putback, putback}' 2>/dev/null | jq -c .)
say "the restore's row after the put back: $row"
evidence "$D/restore/$rid/journal.json" restore-journal.json >/dev/null 2>&1
check standard
scenario_end
