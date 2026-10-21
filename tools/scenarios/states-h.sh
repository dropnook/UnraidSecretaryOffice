#!/bin/bash
# tools/scenarios/states-h.sh — the helpers batch H needed beyond lib.sh/states-*.sh (2026-10-21; sourced by lib.sh like
# every states-*.sh, definitions only). Batch H turns the parked resilience findings into scenarios (README «Batch H»).
#
#   h_cell <name> <0|1> <text…>      a cell row of the verdict table (green 1 / red 0), said in the report too
#   h_info <name> <text…>            a row that only informs (never red, never green)
#   h_mv_set_up <id> [MV-SETUP args…] / h_mv_put_back <id>
#                                    Ms. Moverelli set up as her page saves it (x=80, with its consent to switch Unraid's
#                                    mover off) on a base that keeps her not set up (USOTest3VM); her folder, the cron file
#                                    and Unraid's mover schedule kept first and put back exactly (batch E's way)
#   h_agent_stop <id> / h_agent_start <id>   the agent stopped (plugin/scripts/agent.sh stop) and started again, waited for
#   h_put_future <id> <disk> <share>[/<dir>] <n> <days>
#                                    n files written while the clock stood <days> ahead (born, changed and modified in the
#                                    future — what a clock that ran ahead and was put right leaves), the agent stopped
#                                    meanwhile (no state file of the office takes the future time: batch F's BF5), the
#                                    clock put back at once (clock_restore); noted for files_unput <id>
#   h_snaps_drop <dataset>…          the engine's @uso-backup-/@uso-plan- snapshots of scratch datasets (uso-wip-h-* only)
#   h_ds_destroy <dataset>…          a scratch dataset (uso-wip-h-* only) destroyed with what is left of it

h_cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
h_info() { local n=$1; shift; _row cell "$n" info "$*"; say "info $n: $*"; }

_h_mover_now() { tr -d '\r' </boot/config/share.cfg | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p'; }
h_mv_set_up() {
    local id=$1 k; shift; k=$KEEP_STASH/$id-MV
    mkdir -p "$k" && tar -C "$D" -cf "$k/moverelli.tar" moverelli moverelli.json && cp -p "$OFFICE_FLASH/unraid-secretary-office.cron" "$k/cron" \
        && _h_mover_now >"$k/mover" || return 1
    undo_push h_mv_put_back "$id"
    flow MV-SETUP x=80 mover_off=1 "$@"
}
h_mv_put_back() {
    local k=$KEEP_STASH/$1-MV
    [[ -f $k/moverelli.tar ]] || return 0
    rm -rf "$D/moverelli" && tar -C "$D" -xpf "$k/moverelli.tar" || return 1
    cp -p "$k/cron" "$OFFICE_FLASH/unraid-secretary-office.cron" && /usr/local/sbin/update_cron
    [[ $(_h_mover_now) == "$(cat "$k/mover")" ]] || unraid_schedule_set "$(cat "$k/mover")" || return 1
    php "$SC_DIR/ask.php" --wait 60 moverelli.refresh '{}' >/dev/null 2>&1
    rm -rf "$k"
}

h_agent_stop() {
    say "h_agent_stop: the agent stopped ($1)"
    bash "$P/scripts/agent.sh" stop >/dev/null 2>&1
    # (the pattern never matches its own command line: «[p]hp …agent.php run», pgrep over a bash -c sees itself otherwise)
    _st_until 30 bash -c "! pgrep -f '[p]hp $P/agent/agent[.]php run' >/dev/null" || { say "h_agent_stop: the agent still runs"; h_agent_start "$1"; return 1; }
}
h_agent_start() {
    agent_mark_restart
    bash "$P/scripts/agent.sh" start >/dev/null 2>&1
    agent_wait 120 && say "h_agent_start: the agent at work again ($1)"
}

h_put_future() {
    local id=$1 disk=$2 where=$3 n=$4 days=$5 rc=0
    h_agent_stop "$id" || return 1
    clock_set "$id" "+$days days" || { h_agent_start "$id"; return 1; }
    put_files "$id" "$disk" "$where" "$n" || rc=1
    clock_restore "$id" || rc=1
    h_agent_start "$id" || rc=1
    say "h_put_future: $n files in $disk/$where born $days days ahead ($(stat -c '%w | %y' "$(ls -d /mnt/"$disk"/"$where"/* 2>/dev/null | head -1)" 2>/dev/null))"
    return $rc
}

h_snaps_drop() {
    local ds s
    for ds in "$@"; do
        [[ $ds == */uso-wip-h-* ]] || { say "h_snaps_drop: $ds is no scratch dataset — left"; continue; }
        zfs list -H -t snapshot -o name "$ds" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r s; do
            zfs destroy "$s" && say "undo: destroyed $s"; done
    done
    return 0
}
h_ds_destroy() {
    local ds
    for ds in "$@"; do
        [[ $ds == */uso-wip-h-* ]] || { say "h_ds_destroy: $ds is no scratch dataset — left"; continue; }
        zfs list -H "$ds" >/dev/null 2>&1 || continue
        zfs destroy -r "$ds" && say "undo: destroyed $ds (and what was left of it)" || return 1
    done
}

# h_fresh_beat [desk] — after put_files --age (the clock set back while they were written): an agent napping across that
# step back sleeps until a request rings its doorbell (batch D F12) — a desk's look rings it, then a fresh heartbeat
h_fresh_beat() {
    local t; t=$(_sc_now)
    ask "${1:-office}.refresh" '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the clock was back" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"
}

# h_jack_hire_standin <id> / jack_unhire <id> — Jack Streamer hired and set up as his page saves it, his list written as
# MediaCache writes it (the files of media on the pool), her ini naming it: jack_hire_with_list (states-mover.sh) without
# its «connect» — USOTest3VM's Emby has no API key for him (no admin set up there), so his settings carry the
# scenarios' stand-in key (scenarios.env EMBY_API_KEY, through the RAM inbox like the page's): Emby answers his runs 401,
# as an Emby that can't be asked (being updated, its key revoked) — his part can't plan. Undone by jack_unhire <id>.
h_jack_hire_standin() {
    local id=$1 dir url key set list n
    dir=$(_st_dir "$id")
    _st_mv_configured || { say "needs Ms. Moverelli set up (Jack Streamer works under her)"; return 2; }
    has jack && { say "h_jack_hire_standin: Jack Streamer is hired already"; return 1; }
    key=$(_st_env EMBY_API_KEY); [[ -n $key ]] || { say "h_jack_hire_standin: no EMBY_API_KEY in scenarios.env"; return 2; }
    mv_settings_note "$id" || return 1
    [[ -d $D/mediacache ]] || printf 'new\n' > "$dir/mediacache.new"
    printf 'hired\n' > "$dir/jack.hired"
    ask office.hire '{"desks":["jack"]}' >/dev/null || return 1
    url=http://$(_st_tower_ip):8096
    set=$(jq -nc --arg u "$url" --arg k "$key" --arg b "$ST_JACK_BUDGET" '{settings: {
        instances: [{url: $u, api_key: $k, type: "emby", servername: "Emby", path_mappings: {"/mnt/media": "/mnt/user/media"}}],
        libraries: ["Filme"], library_types: {"Filme": "movies"},
        valid_users: [], user_budgets: {}, people: [], cache_path: "/mnt/cache", cache_budget: $b}}')
    _st_ask_secret jack.save <<<"$set" > "$dir/jack.save" || { say "h_jack_hire_standin: his save refused: $(jq -c '.error' "$dir/jack.save")"; rm -f "$dir/jack.save"; return 1; }
    rm -f "$dir/jack.save"
    jack_fill "$id" || return 1
    list=$(_st_jack_list)
    find /mnt/cache/media -type f 2>/dev/null | sort | head -200 > "$list.uso-new" && chmod 600 "$list.uso-new" && mv "$list.uso-new" "$list" || return 1
    n=$(grep -c . "$list")
    # his page looked at once (as whenever it is open): the office sees his list with its films — the trace his real run
    # leaves (fix H1: a list that held films and goes missing is written anew from the pool, never empty)
    ask jack.refresh '{}' 120 >/dev/null || say "h_jack_hire_standin: his look didn't answer"
    set=$(jq -c --arg l "$(_st_jack_list_user)" '.shares.media = ((.shares.media // {skip: false, min_age: null, move_when_used_above: null,
        files: [], patterns: []}) | .files = ((.files // []) + [$l] | unique))' "$dir/moverelli.settings")
    mv_settings_save "$set" || { say "h_jack_hire_standin: her save refused: $(ask_last '.error')"; return 1; }
    say "h_jack_hire_standin: Jack Streamer hired, set up on $url with the stand-in key, his list $n files, in her ini for media"
}

# h_share_drop <share> — a scratch share (uso-wip-h-* only) away: its files on every root, Unraid's «Delete», a cfg it
# left; a ZFS dataset Unraid only unmounted goes with h_ds_destroy (after the snapshots: h_snaps_drop)
h_share_drop() {
    local s=$1 r
    [[ $s == uso-wip-h-* ]] || { say "h_share_drop: $s is no scratch share — left"; return 1; }
    for r in /mnt/*/"$s"; do [[ -d $r && ! -L $r && $r != /mnt/user*/* ]] && find "$r" -mindepth 1 -delete; done
    share_exists "$s" && { share_remove "$s" || { sleep 3; _st_emcmd 'cmdStatus=Apply'; share_remove "$s"; }; }
    rm -f "/boot/config/shares/$s.cfg"
    return 0
}
