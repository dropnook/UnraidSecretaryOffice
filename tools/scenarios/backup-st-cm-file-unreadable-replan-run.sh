#!/bin/bash
# Cell B35 of matrix.md, the compose file unreadable: the Immich stack as Compose Manager keeps it, its compose file
# chmod 000 (ST-CM-FILE-UNREADABLE). The plan says compose_unreadable, the run writes the package without that file and
# doesn't abort. Needs the Compose Manager (Tower has none: needs compose). Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-cm-file-unreadable-replan-run
needs tower docker compose
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state ST-CM-FILE-UNREADABLE
snap_before
flow BK-REPLAN
m=$(jq -c '[.messages[]? | select(.code | test("compose")) | .code]' "$KEEP/plan-replan.json")
[[ $m == *compose_unreadable* ]] && cell said 1 "the plan: $m" || cell said 0 "the plan's compose words: $m"
expect_warnings compose_unreadable
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '.result' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings)$ ]] && cell run 1 "the run: $r" || cell run 0 "the run: $r"
check standard
scenario_end
