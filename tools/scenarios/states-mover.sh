#!/bin/bash
# tools/scenarios/states-mover.sh — Jack Streamer and the movers around Ms. Moverelli, the K states (JK-*, MO-*) of
# zustaende.md (H4, resilience wave 1b). Sourced by lib.sh; uses states-common.sh and states-shares.sh.
#
#   unraid_schedule_set <cron|''>        Unraid's ⟦Mover schedule⟧ through its own form (emcmd: shareMoverSchedule,
#                                        shareMoverLogging as it is, changeMover=Apply) — '' = «Disabled»
#   tuning_install <id> [plg-url] / tuning_remove <id>      the Mover Tuning plugin (CA's URL, the fork kept up for 7.x)
#                                        and a schedule line of its own when its settings left none
#   userscript_mover_make <id> / userscript_mover_remove <id>   the User Scripts plugin (installed when missing) with a
#                                        script «uso-mover-now» that starts Unraid's mover on a schedule of its own
#   mv_settings_note <id> / mv_settings_back <id>           her settings (moverelli.json .settings) kept / saved back
#                                        through her own «save» (the button)
#   jack_hire_with_list <id> / jack_unhire <id>             Jack Streamer hired (the Team Lead's hire), set up on Tower's
#                                        Emby (its «MediaCache» API key, copied once into scenarios.env as EMBY_API_KEY —
#                                        through a request that never lands in a report), his list written as MediaCache
#                                        writes it (the files of his shares that lie on the pool), her ini naming it
#   jack_list_remove <id> / jack_list_back <id>             his list gone (kept in the stash)
#
# States: JK-HIRED-LIST JK-LIST-PENDING JK-LIST-GONE MO-UNRAID-SCHEDULE-ON MO-TUNING MO-FOREIGN-SCRIPT MO-OWN-RULES
# MO-X-ZERO. Notes for the checks: $KEEP/state-<id>.env.

ST_SHARE_CFG=/boot/config/share.cfg
ST_TUNING_PLG=https://raw.githubusercontent.com/masterwishx/ca.mover.tuning/master/plugins/ca.mover.tuning.plg
ST_TUNING_DIR=/boot/config/plugins/ca.mover.tuning
ST_US_PLG=https://raw.githubusercontent.com/Squidly271/user.scripts/master/plugins/user.scripts.plg
ST_US_DIR=/boot/config/plugins/user.scripts
ST_US_SCRIPT=uso-mover-now
ST_JACK_BUDGET=50G

declare -F _st_fp_add >/dev/null || _st_fp_add() { [[ " ${ST_FP_EXTRA[*]-} " == *" $1 "* ]] || ST_FP_EXTRA+=("$1"); }
declare -F _st_keep_env >/dev/null || _st_keep_env() {
    local f=${KEEP:-/tmp/uso-keep/states}/state-${1,,}.env kv; shift
    mkdir -p "$(dirname "$f")"
    for kv in "$@"; do printf '%s\n' "$kv" >> "$f"; done
}
_st_fp_mover() {
    echo "## movers"
    tr -d '\r' < "$ST_SHARE_CFG" 2>/dev/null | grep '^shareMover'
    ls /var/log/plugins/ 2>/dev/null | grep -iE 'mover|user.scripts' | sed 's/^/plugin /'
    ls -d "$ST_TUNING_DIR" "$ST_US_DIR" 2>/dev/null | sed 's/^/folder /'
    find "$ST_US_DIR/scripts" -maxdepth 1 -mindepth 1 2>/dev/null | sed 's/^/script /'
    md5sum /usr/local/sbin/mover /boot/config/plugins/dynamix/mover.cron 2>/dev/null
    echo "## office (movers)"
    jq -c '.hired | keys' "$D/office/staff.json" 2>/dev/null
    md5sum "$D/moverelli/smart_mover.ini" 2>/dev/null | sed "s|$D/||"
    ls "$D/mediacache" 2>/dev/null | sed 's/^/mediacache /'
}
_st_fp_add _st_fp_mover

_st_share_cfg_val() { tr -d '\r' < "$ST_SHARE_CFG" | sed -n "s/^$1=\"\(.*\)\"$/\1/p"; }

unraid_schedule_set() {
    local cron=$1 log
    log=$(_st_share_cfg_val shareMoverLogging); log=${log:-no}
    _st_emcmd "shareMoverSchedule=$(_st_uri "$cron")&shareMoverLogging=$log&changeMover=Apply" || return 1
    _st_until 10 _st_is "$cron" _st_share_cfg_val shareMoverSchedule \
        || { say "unraid_schedule_set: share.cfg says «$(_st_share_cfg_val shareMoverSchedule)», not «$cron»"; return 1; }
    say "unraid_schedule_set: Unraid's mover schedule «$cron»$( [[ -z $cron ]] && echo ' (Disabled)')"
}

# -------------------------------------------------------------------------------------- plugins of others
_st_plugin_installed() { [[ -f /var/log/plugins/$1.plg ]]; }
_st_plugin_install() { # <id> <name> <url> — installed (noted), 0 when it is there
    local dir; dir=$(_st_dir "$1")
    _st_plugin_installed "$2" && return 0
    [[ -d /boot/config/plugins/$2 ]] || printf 'new\n' > "$dir/$2.folder-new"
    printf 'installed\n' > "$dir/$2.installed"
    _st_clean timeout 600 /usr/local/sbin/plugin install "$3" >/dev/null 2>&1
    _st_plugin_installed "$2" || { say "plugin $2: install from $3 failed"; return 1; }
    say "plugin $2 installed ($3)"
}
_st_plugin_remove() { # <id> <name>
    local dir; dir=$(_st_dir "$1")
    if [[ -f $dir/$2.installed ]]; then
        _st_plugin_installed "$2" && _st_clean timeout 300 /usr/local/sbin/plugin remove "$2.plg" >/dev/null 2>&1
        _st_plugin_installed "$2" && { say "plugin $2: remove failed"; return 1; }
        rm -f "$dir/$2.installed"
    fi
    if [[ -f $dir/$2.folder-new ]]; then rm -rf -- "/boot/config/plugins/$2" "/boot/config/plugins/$2.plg"; rm -f "$dir/$2.folder-new"; fi
    return 0
}

tuning_install() {
    local id=$1 dir; dir=$(_st_dir "$id")
    _st_plugin_install "$id" ca.mover.tuning "${2:-$ST_TUNING_PLG}" || return 1
    # its own schedule: a line in its plugin folder's cron file, the way its settings page writes it, when there is none
    if ! grep -hqE '^[^#]*([0-9*@]).*(age_mover|mover)' "$ST_TUNING_DIR"/*.cron 2>/dev/null; then
        printf '# Generated mover schedule:\n0 4 * * * /usr/local/emhttp/plugins/ca.mover.tuning/age_mover start |& logger -t move\n' \
            > "$ST_TUNING_DIR/ca.mover.tuning.cron" && printf '%s\n' "$ST_TUNING_DIR/ca.mover.tuning.cron" > "$dir/tuning.cron"
        /usr/local/sbin/update_cron >/dev/null 2>&1
    fi
    say "tuning_install: Mover Tuning there, its schedule: $(grep -hv '^#' "$ST_TUNING_DIR"/*.cron 2>/dev/null | head -1)"
}
tuning_remove() {
    local id=$1 dir; dir=$(_st_dir "$id")
    [[ -f $dir/tuning.cron ]] && { rm -f "$(cat "$dir/tuning.cron")" "$dir/tuning.cron"; }
    _st_plugin_remove "$id" ca.mover.tuning || return 1
    /usr/local/sbin/update_cron >/dev/null 2>&1
    return 0
}

userscript_mover_make() {
    local id=$1 dir s; dir=$(_st_dir "$id")
    _st_plugin_install "$id" user.scripts "$ST_US_PLG" || return 1
    s=$ST_US_DIR/scripts/$ST_US_SCRIPT
    [[ -e $s ]] && { say "userscript_mover_make: $s exists"; return 1; }
    [[ -f $ST_US_DIR/schedule.json ]] && cp -p "$ST_US_DIR/schedule.json" "$dir/schedule.json" || printf 'none\n' > "$dir/schedule.none"
    printf '%s\n' "$s" > "$dir/us.script"
    mkdir -p "$s" || return 1
    printf '#!/bin/bash\n# a user'"'"'s own: Unraid'"'"'s mover every night (the state Ms. Moverelli must warn about)\n/usr/local/sbin/mover start\n' > "$s/script"
    printf 'name=%s\ndescription=Starts the mover\n' "$ST_US_SCRIPT" > "$s/name"
    jq -n --arg k "$s/script" '{($k): {script: $k, frequency: "custom", id: "schedule0", custom: "15 2 * * *"}}' \
        | jq -s 'add' "$( [[ -f $ST_US_DIR/schedule.json ]] && echo "$ST_US_DIR/schedule.json" || echo /dev/null)" - 2>/dev/null \
        > "$ST_US_DIR/schedule.json.uso-new" && mv "$ST_US_DIR/schedule.json.uso-new" "$ST_US_DIR/schedule.json" || return 1
    say "userscript_mover_make: User Scripts' «$ST_US_SCRIPT» starts the mover at 15 2 * * *"
}
userscript_mover_remove() {
    local id=$1 dir; dir=$(_st_dir "$id")
    [[ -f $dir/us.script ]] && { rm -rf -- "$(cat "$dir/us.script")"; rm -f "$dir/us.script"; }
    if [[ -f $dir/schedule.json ]]; then cp -p "$dir/schedule.json" "$ST_US_DIR/schedule.json" && rm -f "$dir/schedule.json"
    elif [[ -f $dir/schedule.none ]]; then rm -f "$ST_US_DIR/schedule.json" "$dir/schedule.none"; fi
    _st_plugin_remove "$id" user.scripts
}

# ------------------------------------------------------------------------------------------ Ms. Moverelli's settings
_st_mv_configured() { [[ -f $D/moverelli/smart_mover.ini ]]; }
mv_settings_note() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/moverelli.settings ]] && return 0
    ask moverelli.refresh >/dev/null || return 1
    jq -c '.settings' "$D/moverelli.json" > "$dir/moverelli.settings" && [[ -s $dir/moverelli.settings ]]
}
mv_settings_save() { # <settings json>
    ask moverelli.save "$(jq -nc --argjson s "$1" '{settings: $s}')" >/dev/null
}
mv_settings_back() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/moverelli.settings ]] || return 0
    mv_settings_save "$(cat "$dir/moverelli.settings")" || { say "mv_settings_back: her save refused: $(ask_last '.error')"; return 1; }
    rm -f "$dir/moverelli.settings"
}

# ------------------------------------------------------------------------------------------------- Jack Streamer
# _st_ask_secret <desk.action> — a request whose JSON (stdin) carries a secret: the button's way (askAgent) as ask.php,
# but neither the request nor its key reach the report or a command line. Prints the answer without `state`.
_st_ask_secret() {
    php -r '
        $P = "/usr/local/emhttp/plugins/unraid-secretary-office"; require "$P/src/bootstrap.php";
        $data = json_decode(stream_get_contents(STDIN), true);
        try { $r = askAgent($argv[1], is_array($data) ? $data : [], 120.0); }
        catch (Throwable $e) { $r = ["ok" => false, "error" => ["key" => get_class($e)]]; }
        unset($r["state"]); echo json_encode($r, JSON_UNESCAPED_SLASHES), PHP_EOL; exit(($r["ok"] ?? false) === true ? 0 : 1);' "$1"
}
_st_emby_key() {
    local k; k=$(_st_env EMBY_API_KEY)
    if [[ -z $k ]]; then
        k=$(sqlite3 /mnt/user/appdata/emby/data/authentication.db "select AccessToken from Tokens_2 where AppName='MediaCache' and UserId is null and IsActive=1 limit 1" 2>/dev/null)
        [[ $k =~ ^[A-Za-z0-9_-]{8,128}$ ]] || return 1
        printf 'EMBY_API_KEY=%s\n' "$k" >> "$ST_SCENARIOS_ENV" && chmod 600 "$ST_SCENARIOS_ENV"
        say "Emby's «MediaCache» API key kept in scenarios.env (EMBY_API_KEY)"
    fi
    printf '%s' "$k"
}
_st_jack_list() { printf '%s/mediacache/mediacache_exclude.txt' "$D"; }
_st_jack_list_user() { local l; l=$(_st_jack_list); [[ $l == /mnt/user/* ]] || l=/mnt/user/${l#/mnt/*/}; printf '%s' "$l"; }

jack_hire_with_list() {
    local id=$1 dir url key conn libs users set list n
    dir=$(_st_dir "$id")
    _st_need "Emby (EmbyServer running)" _st_ct_up EmbyServer || return
    _st_mv_configured || { say "needs Ms. Moverelli set up (Jack Streamer works under her)"; return 2; }
    has jack && { say "jack_hire_with_list: Jack Streamer is hired already"; return 1; }
    mv_settings_note "$id" || return 1
    [[ -d $D/mediacache ]] || printf 'new\n' > "$dir/mediacache.new"
    printf 'hired\n' > "$dir/jack.hired"
    ask office.hire '{"desks":["jack"]}' >/dev/null || return 1
    key=$(_st_emby_key) || { say "jack_hire_with_list: no Emby API key (scenarios.env EMBY_API_KEY / Emby's «MediaCache» key)"; return 1; }
    url=http://$(_st_tower_ip):8096
    conn=$(jq -nc --arg u "$url" --arg k "$key" '{url: $u, api_key: $k, type: "emby"}' | _st_ask_secret jack.connect) \
        || { say "jack_hire_with_list: Emby refused: $(jq -c '.error' <<<"$conn")"; return 1; }
    libs=$(jq -c '[.libraries[].name]' <<<"$conn"); users=$(jq -c '[.users[].id]' <<<"$conn")
    set=$(jq -nc --arg u "$url" --arg k "$key" --argjson c "$conn" --arg b "$ST_JACK_BUDGET" '{settings: {
        instances: [{url: $u, api_key: $k, type: "emby", servername: ($c.server.name // "Emby"), path_mappings: {"/mnt/media": "/mnt/user/media"}}],
        libraries: [$c.libraries[].name], library_types: ([$c.libraries[] | {(.name): .type}] | add // {}),
        valid_users: [$c.users[].id], user_budgets: {}, people: [], cache_path: "/mnt/cache", cache_budget: $b}}')
    _st_ask_secret jack.save <<<"$set" > "$dir/jack.save" || { say "jack_hire_with_list: his save refused: $(jq -c '.error' "$dir/jack.save")"; return 1; }
    rm -f "$dir/jack.save"
    # what he would have fetched: the smallest films of his share moved from the array to the pool (as MediaCache's
    # fill does), then his list as its write_exclude() writes it: what of his shares lies on the pool, sorted, one a line
    jack_fill "$id" || return 1
    list=$(_st_jack_list)
    find /mnt/cache/media -type f 2>/dev/null | sort | head -200 > "$list.uso-new" && chmod 600 "$list.uso-new" && mv "$list.uso-new" "$list" || return 1
    n=$(grep -c . "$list")
    # her ini names it for his share (her setup page does so while he is hired)
    set=$(jq -c --arg l "$(_st_jack_list_user)" '.shares.media = ((.shares.media // {skip: false, min_age: null, move_when_used_above: null,
        files: [], patterns: []}) | .files = ((.files // []) + [$l] | unique))' "$dir/moverelli.settings")
    mv_settings_save "$set" || { say "jack_hire_with_list: her save refused: $(ask_last '.error')"; return 1; }
    _st_keep_env "$id" JACK=hired LIST="$(_st_jack_list_user)" LIST_FILES="$n" SHARE=media LIBRARIES="$libs" USERS="$(jq -r length <<<"$users")"
    say "jack_hire_with_list: Jack Streamer hired, set up on $url ($(jq -r length <<<"$libs") libraries), his list $n files, in her ini for media"
}
# jack_fill <id> [n=3] — the n smallest files of the media share on array disks moved to the same path on the pool
# (noted «from<TAB>to»; ≤ 300 MiB together) / jack_unfill <id> — back to the disk they came from
jack_fill() {
    local id=$1 n=${2:-3} dir f to sum=0 sz; dir=$(_st_dir "$id")
    while IFS=$'\t' read -r sz f; do
        ((sum + sz > 300 * 1048576)) && break
        to=/mnt/cache/${f#/mnt/disk*/}
        printf '%s\t%s\n' "$f" "$to" >> "$dir/jack.filled"
        local d=$(dirname "$to") made=()
        while [[ ! -d $d ]]; do made+=("$d"); d=$(dirname "$d"); done
        for d in "${made[@]}"; do printf '%s\n' "$d" >> "$dir/jack.dirs"; done
        mkdir -p "$(dirname "$to")" && chown nobody:users "${made[@]}" 2>/dev/null; mv -- "$f" "$to" || return 1
        sum=$((sum + sz))
    done < <(find /mnt/disk[0-9]*/media -type f -printf '%s\t%p\n' 2>/dev/null | sort -n | head -"$n")
    [[ -s $dir/jack.filled ]] || { say "jack_fill: no file of media on an array disk"; return 1; }
    say "jack_fill: $(grep -c . "$dir/jack.filled") files of media moved to the pool ($((sum / 1048576)) MiB)"
}
jack_unfill() {
    local dir f to; dir=$(_st_dir "$1")
    [[ -f $dir/jack.filled ]] || return 0
    while IFS=$'\t' read -r f to; do
        [[ -e $to && ! -e $f ]] && { mv -- "$to" "$f" || return 1; }
    done < <(tac "$dir/jack.filled")
    # only the folders the fill made, deepest first
    [[ -f $dir/jack.dirs ]] && sort -r "$dir/jack.dirs" | while read -r d; do rmdir -- "$d" 2>/dev/null; done
    rm -f "$dir/jack.filled" "$dir/jack.dirs"
}
jack_unhire() {
    local id=$1 dir; dir=$(_st_dir "$id")
    mv_settings_back "$id" || return 1
    jack_unfill "$id" || return 1
    if [[ -f $dir/jack.hired ]]; then
        has jack && { ask office.fire '{"desk":"jack"}' >/dev/null || return 1; }
        rm -f "$dir/jack.hired"
    fi
    if [[ -f $dir/mediacache.new ]]; then rm -rf -- "$D/mediacache"; rm -f "$dir/mediacache.new"; fi
    return 0
}
jack_list_remove() {
    local dir l; dir=$(_st_dir "$1"); l=$(_st_jack_list)
    [[ -f $l ]] || { say "jack_list_remove: no list $l"; return 1; }
    mv -- "$l" "$dir/mediacache_exclude.txt" || return 1
    say "jack_list_remove: his list $l gone"
}
jack_list_back() {
    local dir; dir=$(_st_dir "$1")
    [[ -f $dir/mediacache_exclude.txt ]] || return 0
    [[ -d $D/mediacache ]] && mv -f -- "$dir/mediacache_exclude.txt" "$(_st_jack_list)" || rm -f "$dir/mediacache_exclude.txt"
}

# ------------------------------------------------------------------------------------------------------------ states
state_jk_hired_list() { jack_hire_with_list JK-HIRED-LIST; }
undo_jk_hired_list() { jack_unhire JK-HIRED-LIST; }

# JK-LIST-PENDING — Jack Streamer hired, not set up (his list never made), her ini names it: her real runs wait for it
state_jk_list_pending() {
    local dir set; dir=$(_st_dir JK-LIST-PENDING)
    _st_need "Emby (EmbyServer running)" _st_ct_up EmbyServer || return
    _st_mv_configured || { say "needs Ms. Moverelli set up"; return 2; }
    has jack && { say "JK-LIST-PENDING: Jack Streamer is hired already"; return 1; }
    mv_settings_note JK-LIST-PENDING || return 1
    [[ -d $D/mediacache ]] || printf 'new\n' > "$dir/mediacache.new"
    printf 'hired\n' > "$dir/jack.hired"
    ask office.hire '{"desks":["jack"]}' >/dev/null || return 1
    [[ -f $(_st_jack_list) ]] && { say "JK-LIST-PENDING: his list exists already"; return 1; }
    set=$(jq -c --arg l "$(_st_jack_list_user)" '.shares.media = ((.shares.media // {skip: false, min_age: null, move_when_used_above: null,
        files: [], patterns: []}) | .files = ((.files // []) + [$l] | unique))' "$dir/moverelli.settings")
    mv_settings_save "$set" || { say "JK-LIST-PENDING: her save refused: $(ask_last '.error')"; return 1; }
    _st_keep_env JK-LIST-PENDING JACK=hired LIST="$(_st_jack_list_user)" EXPECT=moverelli_jack_list_pending
}
undo_jk_list_pending() { jack_unhire JK-LIST-PENDING; }

# JK-LIST-GONE — set up, has run (MediaCache's mediacache_origin.json there), his list gone. NB: Jack Streamer's next
# look (jackScan → jackListEnsure) writes an EMPTY list again — the state lasts until then
state_jk_list_gone() {
    local dir; dir=$(_st_dir JK-LIST-GONE)
    jack_hire_with_list JK-LIST-GONE || return
    [[ -f $D/mediacache/mediacache_origin.json ]] || { printf '{}\n' > "$D/mediacache/mediacache_origin.json" && printf 'made\n' > "$dir/origin.made"; }
    jack_list_remove JK-LIST-GONE || return 1
    _st_keep_env JK-LIST-GONE LIST_GONE=yes
}
undo_jk_list_gone() {
    local dir; dir=$(_st_dir JK-LIST-GONE)
    jack_list_back JK-LIST-GONE
    [[ -f $dir/origin.made ]] && { rm -f "$D/mediacache/mediacache_origin.json" "$dir/origin.made"; }
    jack_unhire JK-LIST-GONE
}

# MO-UNRAID-SCHEDULE-ON [cron] — Unraid's own mover on a schedule (USOTest3VM's base: then only looked at)
state_mo_unraid_schedule_on() {
    local cron=${1:-40 3 * * *} now dir; dir=$(_st_dir MO-UNRAID-SCHEDULE-ON)
    now=$(_st_share_cfg_val shareMoverSchedule)
    _st_keep_env MO-UNRAID-SCHEDULE-ON SCHEDULE="$cron" WAS="$now"
    [[ $now == "$cron" ]] && { say "MO-UNRAID-SCHEDULE-ON: the base has it already («$cron»)"; return 0; }
    printf '%s\n' "$now" > "$dir/schedule.was"
    unraid_schedule_set "$cron"
}
undo_mo_unraid_schedule_on() {
    local dir; dir=$(_st_dir MO-UNRAID-SCHEDULE-ON)
    [[ -f $dir/schedule.was ]] || return 0
    unraid_schedule_set "$(cat "$dir/schedule.was")" && rm -f "$dir/schedule.was"
}

state_mo_tuning() { tuning_install MO-TUNING "${1:-}" && _st_keep_env MO-TUNING TUNING=installed SCHEDULE="$(grep -hv '^#' "$ST_TUNING_DIR"/*.cron 2>/dev/null | head -1 | awk '{print $1,$2,$3,$4,$5}')"; }
undo_mo_tuning() { tuning_remove MO-TUNING; }

state_mo_foreign_script() { userscript_mover_make MO-FOREIGN-SCRIPT && _st_keep_env MO-FOREIGN-SCRIPT SCRIPT="$ST_US_SCRIPT" WHEN="15 2 * * *" EXPECT=foreign_mover; }
undo_mo_foreign_script() { userscript_mover_remove MO-FOREIGN-SCRIPT; }

# MO-OWN-RULES — shares with rules of their own (uso-prefer: min_age 14 + a pattern; uso-mo-empty: an EMPTY share with
# min_age 5) beside the base's (uso-yes 30) and shares without (uso-arr …)
state_mo_own_rules() {
    local dir set; dir=$(_st_dir MO-OWN-RULES)
    _st_mv_configured || { say "needs Ms. Moverelli set up"; return 2; }
    share_use MO-OWN-RULES uso-mo-empty yes >/dev/null || return 1
    mv_settings_note MO-OWN-RULES || return 1
    set=$(jq -c '.shares["uso-mo-empty"] = {skip: false, min_age: 5, move_when_used_above: null, files: [], patterns: []}
        | .shares["uso-prefer"] = {skip: false, min_age: 14, move_when_used_above: null, files: [], patterns: ["*.part"]}' \
        "$dir/moverelli.settings")
    mv_settings_save "$set" || { say "MO-OWN-RULES: her save refused: $(ask_last '.error')"; return 1; }
    _st_keep_env MO-OWN-RULES RULES="uso-yes:30 uso-prefer:14 uso-mo-empty:5" EMPTY_SHARE=uso-mo-empty
}
undo_mo_own_rules() { mv_settings_back MO-OWN-RULES && share_unuse MO-OWN-RULES; }

# MO-X-ZERO — her limit X 0 (no limit)
state_mo_x_zero() {
    local dir; dir=$(_st_dir MO-X-ZERO)
    _st_mv_configured || { say "needs Ms. Moverelli set up"; return 2; }
    mv_settings_note MO-X-ZERO || return 1
    mv_settings_save "$(jq -c '.global.move_when_used_above = 0' "$dir/moverelli.settings")" \
        || { say "MO-X-ZERO: her save refused: $(ask_last '.error')"; return 1; }
    _st_keep_env MO-X-ZERO X=0
}
undo_mo_x_zero() { mv_settings_back MO-X-ZERO; }
