#!/bin/bash
# Cell B49 of matrix.md: Mr. Backupsy let go with backups there — packages in the backup place, the engine's snapshots
# (made by a run here first). (1) Let go alone (BK-LETGO): everything stays — every package file byte for byte, every
# snapshot, settings.ini and his schedule. (2) Hired again and let go with «Also clear away what he kept here»
# (BK-LETGO clear=yes): his packages go into Ms. Dustdevil's storeroom (none deleted: each file there, «Put back»
# possible), the engine's own local snapshots go — as the dialog says, the user's explicit tick (backup-letgo.php) — but
# never Ms. Snapshotini's plans', settings.ini and the schedule stay; hired again his plan is there. Run last: the
# packages come back with the next base reset. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-letgo-snapshots-packages
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup
pk() { find "$PLACE" -path '*/.UnraidSecretaryOffice-trash' -prune -o -type f -printf '%P %s\n' 2>/dev/null | sort; }
snaps() { zfs list -H -t snapshot -o name 2>/dev/null | grep '@uso-backup-' | sort; }
plans() { zfs list -H -t snapshot -o name 2>/dev/null | grep '@uso-plan-' | sort; }
pk >"$KEEP/pk0"; snaps >"$KEEP/sn0"; plans >"$KEEP/pl0"; md0=$(md5sum <"$UB/settings.ini"); cr0=$(grep -h 'job.sh backup' "$OFFICE_FLASH"/*.cron)
say "before: $(wc -l <"$KEEP/pk0") package files, $(wc -l <"$KEEP/sn0") engine snapshots, $(wc -l <"$KEEP/pl0") of Ms. Snapshotini's plans"
undo_push bash -c 'jq -e ".hired | has(\"backup\")" '"$D"'/office/staff.json >/dev/null 2>&1 || php '"$SC_DIR"'/ask.php office.hire "{\"desks\":[\"backup\"]}" >/dev/null'
track_share "$(basename "$(dirname "$PLACE")")"
snap_before
flow BK-LETGO
diff -q <(pk) "$KEEP/pk0" >/dev/null && diff -q <(snaps) "$KEEP/sn0" >/dev/null && [[ $(md5sum <"$UB/settings.ini") == "$md0" ]] \
    && cell letgo-keeps 1 "let go alone: every package file, every snapshot, settings.ini as before" \
    || cell letgo-keeps 0 "let go alone changed: packages $(diff <(pk) "$KEEP/pk0" | grep -c '^[<>]') lines, snapshots $(diff <(snaps) "$KEEP/sn0" | grep -c '^[<>]'), settings $( [[ $(md5sum <"$UB/settings.ini") == "$md0" ]] && echo same || echo changed)"
flow OF-HIRE desk=backup
flow BK-LETGO clear=yes
gone=$(comm -23 <(cut -d' ' -f2- "$KEEP/pk0" | sort) <(find "$PLACE" -type f -printf '%s\n' 2>/dev/null | sort) | head -0)
inbox=$(find "$PLACE" -path '*/.UnraidSecretaryOffice-trash/*' -type f 2>/dev/null | wc -l)
n0=$(wc -l <"$KEEP/pk0"); left=$(pk | wc -l)
((inbox + left >= n0)) && cell packages-kept 1 "his $n0 package files: $inbox in the storeroom, $left still in place — none deleted" \
    || cell packages-kept 0 "of $n0 package files: $inbox in the storeroom, $left in place — $((n0 - inbox - left)) gone"
[[ -z $(snaps) ]] && cell engine-snaps 1 "the engine's snapshots cleared away (the tick)" || cell engine-snaps 0 "$(snaps | wc -l) engine snapshots left after the clearing"
diff -q <(plans) "$KEEP/pl0" >/dev/null && cell plans-kept 1 "Ms. Snapshotini's $(wc -l <"$KEEP/pl0") plan snapshots untouched" || cell plans-kept 0 "Ms. Snapshotini's plan snapshots changed"
[[ $(md5sum <"$UB/settings.ini") == "$md0" && $(grep -h 'job.sh backup' "$OFFICE_FLASH"/*.cron) == "$cr0" ]] && cell settings-stay 1 "settings.ini and his schedule stay" \
    || cell settings-stay 0 "settings.ini or his schedule changed by the let-go"
flow OF-HIRE desk=backup
allow INV-SETTINGS "the let-go fires him (staff.json) — what BK-LETGO does (firing hides a desk, data stays); hired again at the end"
allow INV-FILES "«Also clear away» moves his packages into Ms. Dustdevil's storeroom by design (backup-letgo.php); the cell counts them there"
allow INV-COUNTS "his packages are in the storeroom after the clearing — the place's count drops by design"
check standard
scenario_end
