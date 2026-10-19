#!/bin/bash
# Cell R11 of matrix.md: the database container is stopped (CT-STOPPED-DB: mariadb-nextcloud) and the user opens «Restore
# the database…» for its dump (from a run made first). Mr. Restori never starts a container (docs/DEVELOPMENT.md «The
# desks», restore: «never starts containers»; docs/GUIDE.md: «He never starts or recreates a container — he says what
# to click»): the plan must block and say why, with no step that starts mariadb-nextcloud. Only the dialog's preview is
# asked (restore.preview, what opening it sends); nothing is restored. Pre-release (~4 min).
# RED on 1.66.8 (product): the plan doesn't block — restore.php rsPlanDb (~2014) puts «start» + «ready» of the stopped
# database first (note.db_start «I start it for this»). Also red, a finding of its own: INV-BACKUP-AFTER — a running
# Nextcloud whose database is stopped makes the run fail (backup.sh ~1213 dies when occ can't switch the maintenance
# mode on; 2.62 goes on only for a data folder readable by others). INV-NC-MAINT-OFF's «in maintenance mode» there is
# likely the check's own (occ can't answer without its database; config.php says 'maintenance' => false).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-stopped-db-db
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
DB=mariadb-nextcloud
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the dump to restore
state CT-STOPPED-DB
snap_before
flow RS-SCAN
file=$(jq -r --arg a "$DB" '[.apps[]? | select(.id == $a) | .dumps[]?.file][0] // empty' "$D/restore.json")
if [[ -z $file ]]; then
    cell plan-blocks 0 "no dump of $DB in the package after the run"
elif ask restore.preview "$(jq -cn --arg a "$DB" --arg f "$file" '{kind: "db", app: $a, file: $f}')" 300 >/dev/null; then
    plan=$(ask_last '.preview')
    printf '%s\n' "$plan" >"$KEEP/rs-preview.json"
    b=$(jq -r '[.blockers[]?.key] | join(",")' <<<"$plan")
    st=$(jq -r --arg c "$DB" '[.steps[]? | select(.do == "start" and ((.containers // []) | index($c)))] | length' <<<"$plan")
    say "the plan: blockers ${b:-none}, steps $(jq -c '[.steps[]?.do]' <<<"$plan"), notes $(jq -c '[.notes[]?.key]' <<<"$plan")"
    [[ -n $b && $st == 0 ]] && cell plan-blocks 1 "the plan blocks ($b) and starts nothing" \
        || cell plan-blocks 0 "the plan $([[ -n $b ]] && echo "blocks ($b)" || echo "doesn't block") and has $st step(s) that start the stopped $DB (notes: $(jq -r '[.notes[]?.key] | join(",")' <<<"$plan"))"
    k=$(jq -r '.blockers[0].key // empty' <<<"$plan")
    if [[ -n $k ]]; then
        jq -e --arg k "errors.$k" 'has($k)' "$SC_TREE/public/desks/restore/lang/en.json" >/dev/null \
            && cell says-why 1 "the blocker has its text (errors.$k)" || cell says-why 0 "the blocker $k has no text errors.$k"
    fi
else
    cell plan-blocks 0 "the preview was refused: $(ask_last '.error.key')"
fi
[[ $(ct_status "$DB") == exited ]] && cell db-stays-stopped 1 "$DB is still stopped" || cell db-stays-stopped 0 "$DB is «$(ct_status "$DB")» now"
check standard
check final
scenario_end
