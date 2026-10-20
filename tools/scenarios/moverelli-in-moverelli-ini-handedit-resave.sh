#!/bin/bash
# Cell M20 of matrix.md (batch E): her smart_mover.ini edited by hand (IN-MOVERELLI-INI-HANDEDIT: her limit X 150 — out
# of range, a key of someone else's `uso_foreign_key=1`, an empty `mover_bin=`). Her page shows what applies — a value
# out of range never as if it held (cell value-checked: the page's X is not 150, or it is marked), never a raw key
# (INV-PAGE); her setup opened and saved without a change (MV-RESAVE) keeps the foreign line (cell foreign-kept: tolerant
# writer) and leaves the ini as it was (INV-REPLAN, her part). She is set up for the scenario (her page's save with its
# consent to switch Unraid's mover off) and put back as the base has her. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-in-moverelli-ini-handedit-resave
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
INI=$D/moverelli/smart_mover.ini
mv_set_up || _row state prepare red "her setup failed"
state IN-MOVERELLI-INI-HANDEDIT
snap_before
ask moverelli.refresh '{}' 120 >/dev/null
x=$(jq -c '.settings.global.move_when_used_above' "$D/moverelli.json")
p=$(jq -c '[.. | objects | select(has("key") or has("code")) | select((.key // .code // "" | tostring) | test("move_when|range|ini|value"; "i"))] | .[0:3]' "$D/moverelli.json" 2>/dev/null)
[[ $x != 150 || ( -n $p && $p != '[]' ) ]] && cell value-checked 1 "her page's X: $x ${p:+(said: $p)}" || cell value-checked 0 "her page shows X = 150 as if it held, nothing said"
bubble_look handedit moverelli
flow MV-RESAVE
grep -qx 'uso_foreign_key=1' "$INI" && cell foreign-kept 1 "uso_foreign_key=1 still in her ini" || cell foreign-kept 0 "the foreign line is gone after her save"
check standard
scenario_end
