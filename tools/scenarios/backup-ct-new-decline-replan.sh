#!/bin/bash
# Cell B50 of matrix.md: a new container (CT-NEW: uso-new) and the user's «Don't take it» at its proposal (BK-DECLINE
# key=docker|known — the proposal the setup makes for a new container). «Look at the server again» makes the same
# proposal no more (plan.declined keeps it; the Apply list names nothing — INV-REPLAN), settings.ini is unchanged, and a
# run leaves uso-new running (undecided, never stopped). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-new-decline-replan
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state CT-NEW
flow BK-REPLAN
has_change() { node "$SC_DIR/plan-changes.mjs" "$1" "$UB/settings.ini" 2>/dev/null | jq -r '.[].key' | grep -qx 'docker|known'; }
has_change "$KEEP/plan-replan.json" && say "the plan proposes docker|known for uso-new" || _row state proposal red "the plan proposes nothing for uso-new"
undo_push flow BK-DECLINE-UNDO key='docker|known'
flow BK-DECLINE key='docker|known'
snap_before
flow BK-REPLAN
d=$(jq -c '.declined["docker|known"] // null' "$KEEP/plan-replan.json")
! has_change "$KEEP/plan-replan.json" && cell stays-declined 1 "the next look proposes it no more (declined: ${d:0:120})" || cell stays-declined 0 "the next look proposes docker|known again (declined: ${d:0:120})"
flow BK-RUN-MANUAL mode=backup
[[ $(ct_status uso-new) == running ]] && cell keeps-running 1 "uso-new ran on through the run" || cell keeps-running 0 "uso-new is $(ct_status uso-new) after the run"
check standard
scenario_end
