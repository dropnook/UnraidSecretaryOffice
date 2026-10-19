#!/bin/bash
# Cell R20 of matrix.md, its second part: a parity check runs (RN-PARITY-CHECK, read-only) and the user starts a drill.
# It is refused at once with drill_parity (the drill would read every disk beside the check) — nothing started, no
# throwaway made — and passes once the check is cancelled (the undo; INV-DRILL-GREEN runs while it still runs, so the
# drill there is refused too: INV-DRILL-GREEN is left out, the cell asks for the refusal). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-rn-parity-check-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # packages for the drill
state RN-PARITY-CHECK
snap_before
flow RS-DRILL refuse=ok
k=$(ask_last '.error.key // empty')
[[ $k == drill_parity ]] && cell refused 1 "the drill during a parity check: $k" || cell refused 0 "the drill during a parity check: ${k:-accepted}"
left=$(timeout 20 docker ps -a --filter name=uso-drill- --format '{{.Names}}' | paste -sd' ' -)
[[ -z $left ]] && cell nothing-made 1 "no throwaway container" || cell nothing-made 0 "made although refused: $left"
check standard
parity_stop RN-PARITY-CHECK                  # the check cancelled, its history line put back (the undo then has nothing to do)
flow RS-DRILL
scenario_end
