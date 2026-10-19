#!/bin/bash
# Cell M8 of matrix.md: the pool (cache) is (nearly) full — 1 GiB left, a refreservation that no move can free
# (DK-POOL-FULL) — and her scheduled joint run starts (MV-RUN-SCHED, job.sh moverelli as crond runs it). Her part moves
# what the rules and the limit X ask for, but the pool stays above X: the run says «target missed» with the numbers
# (her fill's notification notify.target_missed: pool, now, X — her part alone tells it, nightrun.php) and its status
# says the fill didn't reach (reached:false, pool_now); the night ends — no endless recheck: at most NIGHT_ROUNDS_MAX (3)
# rounds, phase done. Jack Streamer isn't hired in the base: her part alone. The room comes back in the undo.
# Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-dk-pool-full-run-sched
needs tower zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state DK-POOL-FULL cache 1024
track_share uso-yes
snap_before
flow MV-RUN-SCHED
n=$D/moverelli/night.json
evidence "$n" night.json >/dev/null 2>&1
evidence "$D/moverelli/status.json" status.json >/dev/null 2>&1
nr=$(jq -r '"phase=\(.phase) round=\(.round) result=\(.result) finished=\(.finished // 0)"' "$n" 2>/dev/null)
round=$(jq -r '.round // 99' "$n" 2>/dev/null)
[[ $nr == phase=done* ]] && ((round <= 3)) && cell ends 1 "the joint run ended: $nr" || cell ends 0 "the joint run: $nr (≤ 3 rounds, phase done)"
fill=$(jq -c '[.shares[]? | select(.pool == "cache" and .fill == true) | {share, until, pool_now, reached}]' "$D/moverelli/status.json" 2>/dev/null)
[[ $(jq '[.[] | select(.reached == false)] | length' <<<"${fill:-[]}") -gt 0 ]] && cell fill-not-reached 1 "her status: $fill" \
    || cell fill-not-reached 0 "her status says no fill missed its target on cache: ${fill:-none}"
told=$(agent_log_since before | grep -E "pool cache: target not reached|Night run: pool cache still at" | head -2)
[[ -n $told ]] && cell target-missed-told 1 "told Unraid's notifications: ${told//$'\n'/ | }" \
    || cell target-missed-told 0 "no «target not reached» told since the run started (agent.log)"
check standard
scenario_end
