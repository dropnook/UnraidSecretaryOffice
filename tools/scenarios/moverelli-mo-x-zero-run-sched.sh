#!/bin/bash
# Cell M23 of matrix.md (batch E): her limit X 0 — no limit (MO-X-ZERO). The joint run as her cron line starts it
# (MV-RUN-SCHED): her part, then Jack Streamer's (not hired here: none) — it ends (night.json done — cell run-done), no
# division by zero anywhere (cell no-div0: the agent's log, her run's log, the night's), her page's picture without an
# X line and without NaN/Infinity (INV-PAGE; cell no-x: her state names no limit). She is set up for the scenario (her
# page's save, with its consent to switch Unraid's mover off) and put back as the base has her. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-x-zero-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
MVK=$KEEP_STASH/E-MV
mover_now() { tr -d '\r' </boot/config/share.cfg | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p'; }
# her setup as her page saves it, with its consent to switch Unraid's mover off (USOTest3VM's base keeps her not set up);
# undone by mv_put_back: her folder, the cron file and Unraid's mover schedule exactly as before
mv_set_up() {
    mkdir -p "$MVK" && tar -C "$D" -cf "$MVK/moverelli.tar" moverelli moverelli.json && cp -p "$OFFICE_FLASH/unraid-secretary-office.cron" "$MVK/cron" \
        && mover_now >"$MVK/mover" || return 1
    undo_push mv_put_back
    flow MV-SETUP x=80 mover_off=1 "$@"
}
mv_put_back() {
    [[ -f $MVK/moverelli.tar ]] || return 0
    rm -rf "$D/moverelli" && tar -C "$D" -xpf "$MVK/moverelli.tar" || return 1
    cp -p "$MVK/cron" "$OFFICE_FLASH/unraid-secretary-office.cron" && /usr/local/sbin/update_cron
    [[ $(mover_now) == "$(cat "$MVK/mover")" ]] || unraid_schedule_set "$(cat "$MVK/mover")" || return 1
    php "$SC_DIR/ask.php" --wait 60 moverelli.refresh '{}' >/dev/null 2>&1
    rm -rf "$MVK"
}
mv_set_up || _row state prepare red "her setup failed"
state MO-X-ZERO
agent_log_mark night
snap_before
flow MV-RUN-SCHED
n=$(jq -c '{phase, result: (.result // null)}' "$D/moverelli/night.json" 2>/dev/null)
[[ $(jq -r .phase <<<"$n") == done ]] && cell run-done 1 "the joint run: $n" || cell run-done 0 "the joint run: ${n:-no night.json}"
z=$( { agent_log_since night; tail -200 "$D/moverelli/smart_mover.log" 2>/dev/null; cat "$D"/moverelli/night*.log 2>/dev/null; } | grep -iE 'division by zero|DivisionByZero|divide by zero|NAN|INF\b' | head -2)
[[ -z $z ]] && cell no-div0 1 "no division by zero in the logs" || cell no-div0 0 "${z//$'\n'/ | }"
ask moverelli.refresh '{}' 120 >/dev/null
x=$(jq -c '[paths(scalars) as $p | select(($p | map(tostring) | join(".")) | test("(^|\\.)(x|limit|move_when_used_above)$"; "i")) | {k: ($p | map(tostring) | join(".")), v: getpath($p)}] | .[0:5]' "$D/moverelli.json" 2>/dev/null)
grep -qE 'NaN|Infinity' "$D/moverelli.json" && cell no-x 0 "her state carries NaN/Infinity" || cell no-x 1 "her state: $x"
check standard
scenario_end
