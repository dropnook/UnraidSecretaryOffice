#!/bin/bash
# Cell R17 of matrix.md (wave 3): a share on btrfs (SH-BTRFS: USOTest3VM, its cache and disk1 are btrfs) backed up as
# «local» — the engine's btrfs moments (read-only subvolumes in .btrfs-snap, Mr. Backupsy's view) — and Mr. Restori:
#   moments      his look lists the share with the run's moment (a btrfs moment, not «no snapshots»)
#   beside       RS-FILES-BESIDE: one file back beside its original out of that moment, the same bytes (INV-COMPARE)
#   replace      RS-FILES-REPLACE after the user changed the file: the moment's bytes in place, the changed one aside
#                (`.aside-<stamp>`), never lost
#   drill        the drill passes (a btrfs share's file practised, or said «not checked» — never failed)
# Mr. Backupsy must be set up (the base's settings.ini). Apply of the share as «local» (mode=snapshot), one run; settings.ini put back by
# the undo; the run's btrfs moments of the scratch share are base.sh's to set aside (step 3). Nightly (~8 min). Needs
# test3 (btrfs) — never Tower (zfs/xfs).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sh-btrfs-scan-files-drill
needs test3 btrfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-r17-btrfs
[[ -f $UB/settings.ini ]] || { echo "Mr. Backupsy set up on this server (settings.ini — the base's; base.sh resets it)" >"$KEEP/.skip"; say "skipped: Mr. Backupsy isn't set up here"; scenario_end; }
mkdir -p "$KEEP_STASH/R17-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/R17-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/R17-INI" "$UB/settings.ini"
state SH-BTRFS "$S"
d=$(share_roots "$S" | head -1)
say "the share $S lies on $d ($(findmnt -no FSTYPE "/mnt/$d" 2>/dev/null))"
f=$(find "/mnt/$d/$S" -maxdepth 1 -type f | sort | head -1)
[[ -n $f ]] || { _row state SH-BTRFS red "no file in $S on $d"; scenario_end; }
path=/mnt/user/$S/$(basename "$f")
sum=$(md5sum <"$f")
track_share "$S"
expect_changes "share|$S|*"
flow BK-APPLY "share|$S|mode=snapshot" || _row state prepare red "Apply of $S as local failed"
flow BK-RUN-MANUAL mode=backup
snap_before
flow RS-SCAN
mom=$(jq -c --arg s "$S" '[.shares[]? | select(.name == $s or .share == $s) | {snaps: ((.snaps // .moments // []) | length), fs: (.fs // .kind // null)}][0] // empty' "$D/restore.json" 2>/dev/null)
[[ -n $mom ]] && jq -e '.snaps > 0' <<<"$mom" >/dev/null 2>&1 && cell moments 1 "his look: $S $mom" \
    || cell moments 0 "his look of $S: ${mom:-not listed} ($(ls "/mnt/$d/.btrfs-snap" 2>/dev/null | head -3 | paste -sd' ' -))"
if flow RS-FILES mode=beside path="$path"; then
    r=$(tail -1 "$KEEP/compare.tsv" 2>/dev/null | cut -f4)
    [[ $r == ok ]] && cell beside 1 "back beside the original, the same bytes" || cell beside 0 "back beside: ${r:-no comparison}"
else
    cell beside 0 "restore beside: $(ask_last '.error.key // empty') $(jq -c '[.blockers[]?.key]' "$KEEP/rs-preview.json" 2>/dev/null)"
fi
printf 'changed by the user\n' >>"$f"
if flow RS-FILES mode=replace path="$path"; then
    aside=$(find "/mnt/$d/$S" -maxdepth 1 -name "$(basename "$f").aside-*" | head -1)
    [[ $(md5sum <"$f") == "$sum" && -n $aside ]] && grep -q 'changed by the user' "$aside" \
        && cell replace 1 "the moment's bytes in place, the changed one aside as $(basename "$aside")" \
        || cell replace 0 "after replace: bytes $( [[ $(md5sum <"$f") == "$sum" ]] && echo 'as in the moment' || echo DIFFER), aside ${aside:-none}"
else
    cell replace 0 "restore in place: $(ask_last '.error.key // empty') $(jq -c '[.blockers[]?.key]' "$KEEP/rs-preview.json" 2>/dev/null)"
fi
flow RS-DRILL && cell drill 1 "the drill passed" || cell drill 0 "the drill: $(ask_last '.error.key // empty')"
check standard
check final
scenario_end
