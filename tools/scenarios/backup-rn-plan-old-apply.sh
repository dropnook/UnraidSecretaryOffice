#!/bin/bash
# Cell B45 of matrix.md, its second state: Apply on a plan older than settings.ini (RN-PLAN-OLD — a second tab, a setup
# in the terminal: settings.ini newer than the plan the page holds). Refused with setup_plan_old, nothing written; the
# page then looks at the server again by itself (desk.js: setup_plan_old → setupPlan) — that look ends with a plan,
# and the same Apply on it goes through with exactly the one change (INV-DIFF-EXACT), put back in the undo. The draft
# kept in the browser (setupDraftKeep) is not visible from the server. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-plan-old-apply
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
plan=$KEEP/plan-old.json
_bk_plan_get "$plan" || _row state plan red "no plan from backup.setup_get"
was=$(jq -r '.P["share|uso-yes|mode"] // "off"' "$plan")
to=snapshot; [[ $was == snapshot ]] && to=off
sleep 1                                      # settings.ini a second newer than the plan, for sure
state RN-PLAN-OLD
md0=$(md5sum <"$UB/settings.ini")
snap_before
ask backup.setup_apply "$(decisions_from_plan "$plan" "share|uso-yes|mode=$to")" 120 >/dev/null
expect_err setup_plan_old
[[ $(md5sum <"$UB/settings.ini") == "$md0" ]] && cell nothing-written 1 "settings.ini unchanged after the refusal" \
    || cell nothing-written 0 "settings.ini changed although Apply was refused (setup_plan_old)"
# the page's own reaction: a new look (quiet), then the user's Apply on it
flow BK-REPLAN
undo_push flow BK-APPLY "share|uso-yes|mode=$was"
expect_diff "share|uso-yes|mode: $was→$to"
expect_changes "share|uso-yes|mode"
flow BK-APPLY plan=old "share|uso-yes|mode=$to"
check standard
scenario_end
