#!/bin/bash
# Cell R23 of matrix.md (batch E): Docker switched off by the user (SV-DOCKER-OFF cfg) on a server without the VM service (USOTest3VM's
# base is SV-VM-OFF itself: no libvirt, no VMs — the state SV-VM-OFF answers «needs» here, so the base is the variant).
# Mr. Restori's look (RS-SCAN) ends and says what is: no VMs, Docker off — never «0 of 0» or «?» (INV-PAGE); his drill
# (RS-DRILL) practises without container steps and passes (steps ok or «not checked» with a reason, never failed), no
# drill container or folder left (INV-LEFTOVER). Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-sv-docker-off-scan-drill
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
has vm && say "this server runs the VM service — the «no VMs» part is the base's only where it doesn't"
nc_data_closed
state SV-DOCKER-OFF cfg                    # switched off as the user does (docker.cfg): stopped alone is «on but silent»
snap_before
t0=$SECONDS
flow RS-SCAN
cell scan-ends $(( SECONDS - t0 <= 300 )) "his look took $((SECONDS - t0)) s (≤ 300)"
v=$(state_json restore '{vms: (.vms // .vm // null) | (if type == "array" then length elif type == "object" then (.list // .items // [] | length) else . end), docker: (.docker // .containers // null) | (if type == "object" then {up, running} else . end)}' 2>/dev/null | tr -d '\n')
say "Mr. Restori's look: $v"
bubble_look scan restore
if flow RS-DRILL; then
    j=$(ls -t "$D"/restore-drill/*/journal.json 2>/dev/null | head -1)
    evidence "$j" drill-journal.json >/dev/null
    # the steps that need a container: the dumps (verified in a throwaway database container) — with Docker off each is
    # «not checked» with its reason; packages, SQLite files and share files are read from disk and may pass
    n=$(jq '[.steps[]? | select(.do == "dump") | select(.state != "not_checked" or ((.code // "") | length) == 0)] | length' "$j" 2>/dev/null)
    ((${n:-1} == 0)) && cell no-container-steps 1 "no dump step ran a container ($(_drill_result "$(basename "$(dirname "$j")")"))" \
        || cell no-container-steps 0 "dump steps with Docker off: $(jq -c '[.steps[]? | select(.do == "dump") | {name, state, code}]' "$j" | head -c 300)"
fi
check standard
scenario_end
