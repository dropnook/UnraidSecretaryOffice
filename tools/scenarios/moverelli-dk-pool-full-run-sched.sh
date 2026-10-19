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
# base.sh keeps what it set aside in the share's root (.uso-base-aside) — her fill moves it like any file of uso-yes,
# while the inventories leave it out (INV-COUNTS would count her moves of base.sh's asides as none): out of the share
# for this scenario, back in the undo
aside_off() { local b; for b in $(bases); do [[ -d /mnt/$b/uso-yes/.uso-base-aside ]] || continue
    mkdir -p "$KEEP_STASH/M8-ASIDE/$b" && mv "/mnt/$b/uso-yes/.uso-base-aside" "$KEEP_STASH/M8-ASIDE/$b/" || return 1; done; }
aside_back() { local d b; for d in "$KEEP_STASH"/M8-ASIDE/*/; do [[ -d $d ]] || continue; b=$(basename "$d")
    [[ -d /mnt/$b/uso-yes ]] || install -d -o nobody -g users -m 0777 "/mnt/$b/uso-yes"
    mv "$d.uso-base-aside" "/mnt/$b/uso-yes/" && rmdir "$d" || return 1; done; rmdir "$KEEP_STASH/M8-ASIDE" 2>/dev/null; return 0; }
undo_push aside_back
aside_off || _row state aside red "base.sh's asides could not be set apart"
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
