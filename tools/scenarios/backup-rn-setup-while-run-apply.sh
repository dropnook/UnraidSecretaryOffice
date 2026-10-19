#!/bin/bash
# Cell B45 of matrix.md, its first state: Apply (and «Look at the server again») while a backup run holds the engine's
# lock (RN-SETUP-WHILE-RUN: the cron's run). Both are refused with backup_running and nothing is written — settings.ini
# byte for byte as before (INV-SETTINGS). The page keeps the user's draft in the browser (setupDraftKeep) — not
# visible from the server. After the run the same Apply goes through: exactly the one change (INV-DIFF-EXACT), put back
# in the undo. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-setup-while-run-apply
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
plan=$KEEP/plan-old.json
_bk_plan_get "$plan" || _row state plan red "no plan from backup.setup_get"
was=$(jq -r '.P["share|uso-yes|mode"] // "off"' "$plan")
to=snapshot; [[ $was == snapshot ]] && to=off
md0=$(md5sum <"$UB/settings.ini")
state RN-SETUP-WHILE-RUN
snap_before
ask backup.setup_apply "$(decisions_from_plan "$plan" "share|uso-yes|mode=$to")" 120 >/dev/null
expect_err backup_running
ask backup.setup_plan '{"measure":false}' 120 >/dev/null
expect_err backup_running
[[ $(md5sum <"$UB/settings.ini") == "$md0" ]] && cell nothing-written 1 "settings.ini unchanged while the run held the lock" \
    || cell nothing-written 0 "settings.ini changed although Apply was refused"
second_run_wait RN-SETUP-WHILE-RUN 1800
wait_backup 1800
# after the run: the same Apply on a fresh look goes through
undo_push flow BK-APPLY "share|uso-yes|mode=$was"
expect_diff "share|uso-yes|mode: $was→$to"
expect_changes "share|uso-yes|mode"
flow BK-APPLY "share|uso-yes|mode=$to"
check standard
scenario_end
