#!/bin/bash
# Cell O5 of matrix.md (wave 3): the office installed anew on Unraid 7.3.x (OS-73) with a container that has no icon
# (CT-NEW: uso-new, alpine, no Icon in its template). 7.3.2's Dashboard/Docker page loops on question.png for such a
# container and fills /var/log in minutes (CLAUDE.md «Server facts»). What must hold:
#   installed    the plugin manager's remove + install (OF-REMOVE-REINSTALL) ends with the office at work
#   no-flood     the office never asks Unraid's Docker page for a container's icon: /var/log grows by less than 1 MB in the
#                two minutes after, and no question.png line is written by the office's own requests
# OS-73 is a look only: runs on USOTest4 (7.3.3); skipped on the 7.4 VMs.
. "$(dirname "$0")/lib.sh"
scenario_begin office-os-73-install-noicon
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state OS-73
state CT-NEW
snap_before
v0=$(du -sk /var/log | cut -f1)
q0=$(grep -rc 'question.png' /var/log/nginx 2>/dev/null | awk -F: '{s += $2} END {print s + 0}')
flow OF-REMOVE-REINSTALL && agent_wait 180 && cell installed 1 "office $(_st_office_version) at work" || cell installed 0 "reinstall: the office isn't at work"
sleep 120
v1=$(du -sk /var/log | cut -f1)
q1=$(grep -rc 'question.png' /var/log/nginx 2>/dev/null | awk -F: '{s += $2} END {print s + 0}')
(( v1 - v0 < 1024 && q1 == q0 )) && cell no-flood 1 "/var/log +$((v1 - v0)) KB, no new question.png line" \
    || cell no-flood 0 "/var/log +$((v1 - v0)) KB, question.png lines $q0 → $q1"
check standard
scenario_end
