#!/bin/bash
# Cell R12 of matrix.md: Nextcloud and its database are stopped (CT-STOPPED-NC) and the user restores (a) its database
# and (b) its data folder (the share nextcloud it binds whole — the unit his page offers — beside), from a run made first. Mr. Restori leaves the
# maintenance mode alone when Nextcloud doesn't run: neither plan has a maintenance step (restore.php: maintenance_off
# only for a config folder from the engine's snapshot), config.php stays byte for byte, both containers stay stopped.
# (a) is judged on the dialog's preview only (restore.preview) — the plan's start of the stopped database is R11's
# finding (restore-ct-stopped-db-db); (b) is restored for real beside (the copy goes again in the undo).
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-stopped-nc-db-files
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
DB=mariadb-nextcloud
CFG=/mnt/user/appdata/nextcloud/config/config.php
# the copy beside (<folder>.restored-<stamp>, a dataset of its own when the restore made one) goes at the end
restored_gone() {
    local to ds
    while IFS=$'\t' read -r to ds; do
        [[ $to == *.restored-* ]] || continue
        if [[ -n $ds ]] && zfs list -H "$ds" >/dev/null 2>&1; then zfs destroy -r "$ds"; else rm -rf -- "$to"; fi
    done < <(jq -r '.steps[]? | select(.do == "copy") | "\(.to)\t\(.dataset // "")"' "$D/restore/$1/plan.json" 2>/dev/null)
    return 0
}
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the dump and the snapshot to restore from
state CT-STOPPED-NC
cfg0=$(md5sum <"$CFG" 2>/dev/null)
snap_before
flow RS-SCAN
file=$(jq -r --arg a "$DB" '[.apps[]? | select(.id == $a) | .dumps[]?.file][0] // empty' "$D/restore.json")
if [[ -n $file ]] && ask restore.preview "$(jq -cn --arg a "$DB" --arg f "$file" '{kind: "db", app: $a, file: $f}')" 300 >/dev/null; then
    plan=$(ask_last '.preview')
    printf '%s\n' "$plan" >"$KEEP/rs-preview-db.json"
    m=$(jq -r '[.steps[]?, .putback_pre[]?, .putback_post[]? | .do | select(test("maint"))] | join(",")' <<<"$plan")
    nc=$(jq -r '[.steps[]? | select(.do == "start" and ((.containers // []) | index("Nextcloud")) and ((.only_stopped // false) | not))] | length' <<<"$plan")
    [[ -z $m && $nc == 0 ]] && cell db-no-maintenance 1 "the database plan touches no maintenance mode and never starts Nextcloud" \
        || cell db-no-maintenance 0 "the database plan: maintenance steps «${m:-none}», $nc unconditional start(s) of Nextcloud"
else
    cell db-no-maintenance 0 "no preview of $DB's dump «$file»: $(ask_last '.error.key')"
fi
restored_gone_last() { local id; id=$(fl_last_restore); [[ -z $id ]] || restored_gone "$id"; }
undo_push restored_gone_last
if flow RS-FILES mode=beside path=/mnt/user/nextcloud; then
    id=$(fl_last_restore)
    j=$D/restore/$id/journal.json
    evidence "$j" journal-files.json
    m=$(jq -r '[.steps[]? | (.do // .name // "") | select(test("maint"))] | join(",")' "$j" 2>/dev/null)
    a=$(jq -r '[.after[]?.key | select(test("maint"))] | join(",")' "$D/restore/$id/plan.json" 2>/dev/null)
    [[ -z $m && -z $a ]] && cell files-no-maintenance 1 "the files restore ($id) touched no maintenance mode, said none" \
        || cell files-no-maintenance 0 "the files restore: maintenance steps «${m:-none}», after-notes «${a:-none}»"
fi
[[ $(md5sum <"$CFG" 2>/dev/null) == "$cfg0" ]] && cell config-untouched 1 "config.php is byte for byte as before" \
    || cell config-untouched 0 "config.php changed while Nextcloud was stopped (maintenance: $(grep -o "'maintenance' => [a-z]*" "$CFG"))"
[[ $(ct_status Nextcloud) == exited && $(ct_status "$DB") == exited ]] && cell stay-stopped 1 "Nextcloud and $DB are still stopped" \
    || cell stay-stopped 0 "Nextcloud «$(ct_status Nextcloud)», $DB «$(ct_status "$DB")» after the restores"
check standard
check final
scenario_end
