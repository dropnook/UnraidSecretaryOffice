#!/bin/bash
# Cell R24 of matrix.md (wave 3): Mr. Restori's journals written by the release before (IN-UPDATE-PREV), read by the code
# under test after the plugin manager's update (OF-UPDATE plg=tree). The 1.60 fixture holds no restore journals, so the
# old release writes them here: a backup run, a file restored beside its original, a drill — all under the old release.
# Then the update, and his look:
#   restore-row   the old restore is in his list with its result (ok) — not «interrupted», not missing
#   drill-row     the old drill is in his drill list with its result (passed)
#   putback       «Put back» on the old restore works with the new code (the old journal's own undo): the copy beside
#                 the original goes, the original stays as it was
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
f=$(find /mnt/cache/uso-yes -maxdepth 1 -type f -name 'f1d-*.bin' | sort | head -1)
[[ -n $f ]] || { _row state uso-yes red "no f1d-*.bin in uso-yes on the pool (the base's files)"; scenario_end; }
path=/mnt/user/uso-yes/$(basename "$f")
track_share uso-yes
flow BK-RUN-MANUAL mode=backup                       # a moment of uso-yes, by the old release
flow RS-FILES mode=beside path="$path"
rid=$(fl_last_restore)
say "the old release's restore: $rid ($(jq -c '{kind, result}' "$D/restore/$rid/journal.json" 2>/dev/null))"
flow RS-DRILL
did=$(ls -t "$D/restore-drill" 2>/dev/null | grep -E '^[0-9]{8}-' | head -1)
say "the old release's drill: $did ($(jq -c '{result}' "$D/restore-drill/$did/journal.json" 2>/dev/null))"
evidence "$D/restore/$rid/journal.json" restore-journal-old.json >/dev/null
evidence "$D/restore-drill/$did/journal.json" drill-journal-old.json >/dev/null
beside=$(jq -r '[.steps[]? | select(.do == "copy") | .to][0] // empty' "$D/restore/$rid/plan.json" 2>/dev/null)
say "the copy beside the original: ${beside:-unknown}"
snap_before
flow OF-UPDATE plg=tree
[[ $(_st_office_version) == "$cur" ]] && say "office $cur after the update" || _row flow OF-UPDATE red "office $(_st_office_version) after the update, not $cur"
flow RS-SCAN
row=$(jq -c --arg i "$rid" '[.restores[]? | select(.id == $i) | {kind, result}][0] // empty' "$D/restore.json" 2>/dev/null)
[[ $(jq -r '.result // empty' <<<"${row:-null}" 2>/dev/null) == ok ]] && cell restore-row 1 "his list after the update: $rid $row" \
    || cell restore-row 0 "his list after the update: $rid → ${row:-not listed}"
ask restore.drill_state '{}' 300 >/dev/null
drow=$(ask_last "[.drills[]? | select(.id == \"$did\") | {result}][0] // empty" | jq -c . 2>/dev/null)
[[ $(jq -r '.result // empty' <<<"${drow:-null}" 2>/dev/null) == passed ]] && cell drill-row 1 "his drills after the update: $did $drow" \
    || cell drill-row 0 "his drills after the update: $did → ${drow:-not listed}"
if flow RS-PUTBACK of="$rid"; then
    if [[ -n $beside && ! -e $beside && -f $path ]] && cmp -s "$f" "$path"; then
        cell putback 1 "put back with the new code: the copy $beside gone, the original unchanged"
    else
        cell putback 0 "put back ended, but: copy ${beside:-?} $( [[ -e $beside ]] && echo 'still there' || echo gone), original $( [[ -f $path ]] && echo there || echo MISSING)"
    fi
else
    cell putback 0 "«Put back» on the old release's restore: $(ask_last '.error.key // empty') $(jq -c '[.blockers[]?.key]' "$KEEP/rs-preview.json" 2>/dev/null)"
fi
check standard
scenario_end
