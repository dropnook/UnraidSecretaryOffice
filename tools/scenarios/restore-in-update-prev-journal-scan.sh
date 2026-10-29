#!/bin/bash
# Cell R24 of matrix.md (wave 3): Mr. Restori's journals written by the release before (IN-UPDATE-PREV), read by the code
# under test after the plugin manager's update (OF-UPDATE plg=tree). The 1.60 fixture holds no restore journals, so the
# old release writes them here: a backup run, the user changes a file, the file restored in place out of the moment
# («replace»: the changed one aside — the kind of restore that can be put back; a copy beside has nothing to put back by
# design), a drill — all under the old release.
# Then the update, and his look:
#   restore-row   the old restore is in his list with its result (ok) — not «interrupted», not missing
#   drill-row     the old drill is in his drill list with its result (passed)
#   putback       «Put back» on the old restore works with the new code (the old journal's own undo): the user's
#                 changed file is in place again
# Nothing saved changes (INV-SETTINGS), the second update changes nothing (INV-MIGRATE-IDEMPOTENT); the undo puts the
# office back as it was. Nightly (~12 min: two installs, a run, a drill).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-in-update-prev-journal-scan
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cur=$(_st_office_version)
nc_data_closed
state IN-UPDATE-PREV previous
say "on the release before: $(_of_versions)"
[[ $(_st_office_version) != "$cur" ]] || _row state IN-UPDATE-PREV red "the release before is the same version ($cur)"
f=$(find /mnt/cache/uso-only -maxdepth 1 -type f -name 'f1d-*.bin' | sort | head -1)
[[ -n $f ]] || { _row state uso-only red "no f1d-*.bin in uso-only on the pool (the base's files)"; scenario_end; }
path=/mnt/user/uso-only/$(basename "$f")      # uso-only: «local» (snapshot) in the base, so it has moments
track_share uso-only
flow BK-RUN-MANUAL mode=backup                       # a moment of uso-only, by the old release
cp -p "$f" "$KEEP/original.bin"
undo_push bash -c 'cp -p "$1" "$2"; rm -f -- "$2".aside-* "$2".putback-* "$2".restored-*; exit 0' _ "$KEEP/original.bin" "$f"
printf 'changed by the user\n' >>"$f"
changed=$(md5sum <"$f")
flow RS-FILES mode=replace path="$path"
rid=$(fl_last_restore)
say "the old release's restore: $rid ($(jq -c '{kind, result}' "$D/restore/$rid/journal.json" 2>/dev/null))"
flow RS-DRILL
did=$(ls -t "$D/restore-drill" 2>/dev/null | grep -E '^[0-9]{8}-' | head -1)
say "the old release's drill: $did ($(jq -c '{result}' "$D/restore-drill/$did/journal.json" 2>/dev/null))"
evidence "$D/restore/$rid/journal.json" restore-journal-old.json >/dev/null
evidence "$D/restore-drill/$did/journal.json" drill-journal-old.json >/dev/null
snap_before
flow OF-UPDATE plg=tree
[[ $(_st_office_version) == "$cur" ]] && say "office $cur after the update" || _row flow OF-UPDATE red "office $(_st_office_version) after the update, not $cur"
flow RS-SCAN
row=$(jq -c --arg i "$rid" '[.restores[]? | select(.id == $i) | {kind, result, can_putback}][0] // empty' "$D/restore.json" 2>/dev/null)
[[ $(jq -r '"\(.result) \(.can_putback)"' <<<"${row:-null}" 2>/dev/null) == "ok true" ]] && cell restore-row 1 "his list after the update: $rid $row" \
    || cell restore-row 0 "his list after the update: $rid → ${row:-not listed}"
ask restore.drill_state '{}' 300 >/dev/null
drow=$(ask_last "[.drills[]? | select(.id == \"$did\") | {result}][0] // empty" | jq -c . 2>/dev/null)
[[ $(jq -r '.result // empty' <<<"${drow:-null}" 2>/dev/null) == passed ]] && cell drill-row 1 "his drills after the update: $did $drow" \
    || cell drill-row 0 "his drills after the update: $did → ${drow:-not listed}"
if flow RS-PUTBACK of="$rid"; then
    [[ $(md5sum <"$f") == "$changed" ]] && cell putback 1 "put back with the new code: the user's changed file in place again" \
        || cell putback 0 "put back ended, but the file in place is not the user's changed one"
else
    cell putback 0 "«Put back» on the old release's restore: $(ask_last '.error.key // empty') $(jq -c '[.blockers[]?.key]' "$KEEP/rs-preview.json" 2>/dev/null)"
fi
check standard
scenario_end
