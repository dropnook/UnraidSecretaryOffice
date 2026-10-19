#!/bin/bash
# Cell M18 of matrix.md, Mover Tuning: the plugin installed with a schedule line of its own (MO-TUNING). Her «Run now»
# is refused (moverelli_tuning_schedule, its schedule and file named), her Team Lead check names the foreign mover, and
# nothing of Mover Tuning is ever changed by her (its folder byte for byte). Pre-release (~3 min, a plugin install).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-tuning-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state MO-TUNING
tsum() { find /boot/config/plugins/ca.mover.tuning -type f -exec md5sum {} + 2>/dev/null | sort -k2 | md5sum; }
t0=$(tsum)
snap_before
flow MV-RUN refuse=ok
expect_err moverelli_tuning_schedule
p=$(ask_last '.error.params | tostring')
[[ $p == *cron* || $p == *schedule* ]] && cell named 1 "refused with its schedule named: $p" || cell named 0 "refused, but nothing named: $p"
f=$(state_json caretaker '[.checks.moverelli[]? | select(.id == "foreign_mover") | {id, ok, params}] | tostring' 2>/dev/null)
say "the Team Lead's checks about movers: $f"
[[ $f == *'"ok":false'* ]] && cell finding 1 "the Team Lead names it: $f" || cell finding 0 "no Team Lead check names Mover Tuning: $f"
[[ $(tsum) == "$t0" ]] && cell never-changed 1 "Mover Tuning's folder byte for byte as before" || cell never-changed 0 "Mover Tuning's folder changed"
check standard
scenario_end
