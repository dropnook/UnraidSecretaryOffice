#!/bin/bash
# Cell M18 of matrix.md, a foreign mover script: User Scripts' «uso-mover-now» starts Unraid's mover on a schedule of its
# own (MO-FOREIGN-SCRIPT). Her «Run now» is refused (moverelli_foreign_mover, the script and its time named), her Team
# Lead check names it, and the script is never touched (its folder and User Scripts' schedule byte for byte).
# Pre-release (~3 min, a plugin install).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-foreign-script-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state MO-FOREIGN-SCRIPT
ssum() { { find /boot/config/plugins/user.scripts/scripts/uso-mover-now -type f -exec md5sum {} +; md5sum /boot/config/plugins/user.scripts/schedule.json; } 2>/dev/null | sort -k2 | md5sum; }
s0=$(ssum)
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_foreign_mover
p=$(ask_last '.error.params | tostring')
[[ $p == *uso-mover-now* || $p == *2* ]] && cell named 1 "refused with the script named: $p" || cell named 0 "refused, but not named: $p"
f=$(state_json caretaker '[.checks.moverelli[]? | select(.id == "foreign_mover") | {id, ok, params}] | tostring' 2>/dev/null)
say "the Team Lead's checks about movers: $f"
[[ $f == *'"ok":false'* ]] && cell finding 1 "the Team Lead names it: $f" || cell finding 0 "no Team Lead check names the script: $f"
[[ $(ssum) == "$s0" ]] && cell never-changed 1 "the script and User Scripts' schedule byte for byte as before" || cell never-changed 0 "the script or its schedule changed"
check standard
scenario_end
