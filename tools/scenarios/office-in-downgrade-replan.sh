#!/bin/bash
# Cell B41 of matrix.md (batch E), IN-DOWNGRADE: the release before the newest installed over today's data folder («an
# older on a newer's», CLAUDE.md «Updates»). The older office starts and reads tolerantly: nothing of the data folder
# goes (cell nothing-gone: its files by name), nothing saved changes (INV-SETTINGS), the marker says it was a downgrade
# and keeps the newer one's steps (cell marker), and «Look at the server again» on the older office lists no change
# (INV-REPLAN). The undo puts the release back exactly. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin office-in-downgrade-replan
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cur=$(_st_office_version)
find "$D" -path "$D/mailbox" -prune -o -type f -printf '%P\n' 2>/dev/null | LC_ALL=C sort >"$KEEP/data-before.txt"
m0=$(jq -c '.done' "$D/office.json" 2>/dev/null)
snap_before
agent_log_mark down
state IN-DOWNGRADE previous
say "after the downgrade: $(_of_versions)"
[[ $(_st_office_version) != "$cur" ]] && cell older 1 "office $(_st_office_version) on the data folder of $cur" || cell older 0 "still $cur"
agent_wait 120
find "$D" -path "$D/mailbox" -prune -o -type f -printf '%P\n' 2>/dev/null | LC_ALL=C sort >"$KEEP/data-after.txt"
gone=$(comm -23 "$KEEP/data-before.txt" "$KEEP/data-after.txt" | grep -v '^agent\.\|\.lock$' | head -5)
[[ -z $gone ]] && cell nothing-gone 1 "every file of the data folder still there" || cell nothing-gone 0 "gone after the downgrade: ${gone//$'\n'/ }"
d=$(agent_log_since down | grep -m1 'downgrade')
m1=$(jq -c '.done' "$D/office.json" 2>/dev/null)
[[ -n $d && $m1 == "$m0" ]] && cell marker 1 "${d:0:200}" || cell marker 0 "the log: ${d:-no «downgrade» line}; steps done $m0 → $m1"
flow BK-REPLAN
check standard
scenario_end
