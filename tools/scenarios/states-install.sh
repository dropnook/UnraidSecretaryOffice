#!/bin/bash
# tools/scenarios/states-install.sh — the office's version, install and hand-edited files, the J states (OS-*, IN-*) of
# zustaende.md (H4, resilience wave 1b). Sourced by lib.sh; uses states-common.sh. Tower and USOTest3VM (never USOPartner).
#
#   office_keep <id> / office_back <id>   everything an install touches kept first — the data folder, the plugin's RAM
#                         folder (dev-synced code too), its flash folder (but scenarios.env), the installed .plg, root's
#                         authorized_keys (a remove takes the partner lines away) — and put back exactly: the kept .plg
#                         installed again (`forced`), the folders over it, the data folder (the one the state left set
#                         aside as <data>.aside-<stamp> — base.sh --prune removes those), cron rebuilt, the agent started
#   plugin_install <id> <url|latest|previous|vX.Y.Z|worktree>   Unraid's `plugin install … forced` (a lower version too);
#                         previous = the release before the newest on GitHub; worktree = this tree built by plugin/build.sh
#                         (plugin version <today>z) with its package laid on the flash first, so nothing is downloaded
#   plugin_remove <id>    Unraid's `plugin remove` (the office's own remove script runs)
#   data_fixture <id> <name>   the data folder of an old install (<data>.base/fixtures/data-<name>.tar, else the tree's
#                         base/fixtures/data-<name>.tar.gz) in place of the current one, the agent stopped
#   data_fixture_make <name> <tag>   (USOTest3VM) that release installed, a fresh data folder, desks hired, Mr. Backupsy's
#                         plan applied as proposed (its P), schedules — tarred into <data>.base/fixtures/, office put back
#   ini_edit <id> <variant> / ini_restore <id>    settings.ini by hand: unknown crlf bom dup gone-section zero-ret
#                         preset-bad mode-off-by-hand perms owner
#   mv_ini_edit <id> / mv_ini_restore <id>        smart_mover.ini by hand: a value out of range, a foreign key, `mover_bin =`
#
# States: OS-73 OS-74 IN-FRESH IN-UPDATE-PREV IN-UPDATE-OLD IN-DOWNGRADE IN-TAKEOVER IN-HANDEDIT-<VARIANT>
# IN-INI-MISSING IN-MOVERELLI-INI-HANDEDIT. Notes: $KEEP/state-<id>.env.

ST_PLG_NAME=unraid-secretary-office
ST_PLG_FLASH=/boot/config/plugins/$ST_PLG_NAME
ST_PLG_REPO=dropnook/UnraidSecretaryOffice
ST_KEYS=(/root/.ssh/authorized_keys /boot/config/ssh/root/authorized_keys)

declare -F _st_fp_add >/dev/null || _st_fp_add() { [[ " ${ST_FP_EXTRA[*]-} " == *" $1 "* ]] || ST_FP_EXTRA+=("$1"); }
declare -F _st_keep_env >/dev/null || _st_keep_env() {
    local f=${KEEP:-/tmp/uso-keep/states}/state-${1,,}.env kv; shift
    mkdir -p "$(dirname "$f")"
    for kv in "$@"; do printf '%s\n' "$kv" >> "$f"; done
}
_st_fp_install() {
    echo "## office install"
    echo "plg $(_st_plg_version) office $(_st_office_version)"
    ls /var/log/plugins/"$ST_PLG_NAME".plg 2>/dev/null
    (cd "$ST_PLG_FLASH" 2>/dev/null && ls | grep -vx scenarios.env | while read -r f; do [[ -f $f ]] && md5sum "$f" || echo "$f/"; done)
    (cd "$P" 2>/dev/null && find . -type f -not -path './data/*' -print0 | sort -z | xargs -0 md5sum | md5sum | sed 's/^/code /')
    for f in "${ST_KEYS[@]}"; do [[ -f $f ]] && echo "keys $f $(grep -c 'uso-' "$f") $(md5sum < "$f" | cut -c1-12)"; done
    md5sum "$UB/settings.ini" "$D/moverelli/smart_mover.ini" 2>/dev/null | sed "s|$D/||"
    stat -c 'settings.ini %a %U' "$UB/settings.ini" 2>/dev/null
    jq -c '.hired | keys' "$D/office/staff.json" 2>/dev/null
}
_st_fp_add _st_fp_install

_st_office_version() { sed -n "s/^const OFFICE_VERSION = '\([^']*\)'.*/\1/p" "$P/src/bootstrap.php" 2>/dev/null; }
_st_plg_version() { sed -n 's/^<!ENTITY version *"\([^"]*\)">.*/\1/p' "/boot/config/plugins/$ST_PLG_NAME.plg" 2>/dev/null; }
_st_plugin() { _st_clean timeout 900 /usr/local/sbin/plugin "$@" > "$KEEP/plugin.out" 2>&1; }
_st_agent_up() { [[ -f $RUN/agent.json ]] && (( $(date +%s) - $(stat -c %Y "$RUN/agent.json") < 30 )) && grep -q '"running":true' "$RUN/agent.json"; }
_st_agent_stop() { [[ -f $P/scripts/agent.sh ]] && _st_clean bash "$P/scripts/agent.sh" stop >/dev/null 2>&1; return 0; }
_st_agent_start() { _st_clean bash "$P/scripts/agent.sh" start >/dev/null 2>&1; _st_until 90 _st_agent_up; }

office_keep() {
    local id=$1 dir f; dir=$(_st_dir "$id")/office
    [[ -f $dir/kept ]] && return 0
    mkdir -p "$dir" || return 1
    tar -C "$(dirname "$D")" -cf "$dir/data.tar" "$(basename "$D")" || return 1
    tar -C "$P" -cf "$dir/plugin.tar" . || return 1
    tar -C "$ST_PLG_FLASH" --exclude ./scenarios.env -cf "$dir/flash.tar" . || return 1
    cp -p "/boot/config/plugins/$ST_PLG_NAME.plg" "$dir/plg" || return 1
    for f in "${ST_KEYS[@]}"; do [[ -f $f ]] && cp -p "$f" "$dir/keys.$(md5sum <<<"$f" | cut -c1-8)"; done
    printf '%s %s\n' "$(_st_plg_version)" "$(_st_office_version)" > "$dir/kept"
    say "office_keep: the office as it is kept (plugin $(_st_plg_version), office $(_st_office_version))"
}
office_back() {
    local id=$1 dir f k stamp; dir=$(_st_dir "$id")/office
    [[ -f $dir/kept ]] || return 0
    stamp=$(date +%Y%m%d-%H%M%S)
    _st_agent_stop
    [[ -f /var/log/plugins/$ST_PLG_NAME.plg ]] && _st_plugin remove "$ST_PLG_NAME.plg"
    # the flash folder as it was (what the state's installs left there goes; scenarios.env stays)
    find "$ST_PLG_FLASH" -mindepth 1 -maxdepth 1 ! -name scenarios.env -exec rm -rf -- {} + 2>/dev/null
    tar -C "$ST_PLG_FLASH" -xpf "$dir/flash.tar" || return 1
    cp -p "$dir/plg" "/boot/config/plugins/$ST_PLG_NAME.plg" || return 1
    _st_plugin install "/boot/config/plugins/$ST_PLG_NAME.plg" forced
    [[ -f /var/log/plugins/$ST_PLG_NAME.plg ]] || { say "office_back: the kept .plg didn't install: $(tail -3 "$KEEP/plugin.out" | tr '\n' ' ')"; return 1; }
    _st_agent_stop
    # the code as it ran (a dev-synced tree is not the package), the data folder as it was
    find "$P" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} + && tar -C "$P" -xpf "$dir/plugin.tar" || return 1
    [[ -e $D ]] && { mv -- "$D" "$D.aside-$stamp" || return 1; }
    tar -C "$(dirname "$D")" -xpf "$dir/data.tar" || return 1
    tar -C "$ST_PLG_FLASH" -xpf "$dir/flash.tar"            # the install's script may have rewritten cfg or cron
    for f in "${ST_KEYS[@]}"; do k=$dir/keys.$(md5sum <<<"$f" | cut -c1-8); [[ -f $k ]] && cp -p "$k" "$f"; done
    /usr/local/sbin/update_cron >/dev/null 2>&1
    _st_agent_start || { say "office_back: no heartbeat after the restore"; return 1; }
    say "office_back: the office back (plugin $(_st_plg_version), office $(_st_office_version)$( [[ -d $D.aside-$stamp ]] && echo ", the state's data folder aside as $(basename "$D").aside-$stamp"))"
    rm -rf -- "$dir"
}

_st_plg_url() { printf 'https://github.com/%s/releases/download/%s/%s.plg' "$ST_PLG_REPO" "$1" "$ST_PLG_NAME"; }
_st_release_tags() { curl -fsSL "https://api.github.com/repos/$ST_PLG_REPO/releases?per_page=10" | jq -r '.[] | select(.draft == false and .prerelease == false) | .tag_name'; }

plugin_install() {
    local id=$1 what=$2 plg tag office ver
    office_keep "$id" || return 1
    case $what in
        http*://*) plg=$what ;;
        latest) tag=$(_st_release_tags | sed -n 1p); plg=$(_st_plg_url "$tag") ;;
        previous) tag=$(_st_release_tags | sed -n 2p); plg=$(_st_plg_url "$tag") ;;
        v[0-9]*) tag=$what; plg=$(_st_plg_url "$tag") ;;
        worktree)
            office=$(sed -n "s/^const OFFICE_VERSION = '\([^']*\)'.*/\1/p" "$SC_TREE/src/bootstrap.php")
            ver=$(date -u +%Y.%m.%d)z
            (cd "$SC_TREE" && bash plugin/build.sh "$office" "$ver") > "$KEEP/build.out" 2>&1 \
                || { say "plugin_install: plugin/build.sh failed: $(tail -2 "$KEEP/build.out" | tr '\n' ' ')"; return 1; }
            cp -p "$SC_TREE/dist/$ST_PLG_NAME-$ver.txz" "$ST_PLG_FLASH/" || return 1
            plg=$SC_TREE/dist/$ST_PLG_NAME.plg ;;
        *) say "plugin_install: url|latest|previous|vX.Y.Z|worktree, not $what"; return 1 ;;
    esac
    [[ $what == previous || $what == latest ]] && [[ -z $tag ]] && { say "plugin_install: GitHub's release list didn't answer"; return 1; }
    _st_plugin install "$plg" forced
    [[ -f /var/log/plugins/$ST_PLG_NAME.plg ]] || { say "plugin_install: $plg didn't install: $(tail -3 "$KEEP/plugin.out" | tr '\n' ' ')"; return 1; }
    _st_until 90 _st_agent_up || say "note: no fresh heartbeat 90 s after the install"
    _st_keep_env "$id" PLG="$(_st_plg_version)" OFFICE="$(_st_office_version)" FROM="$(cut -d' ' -f2 "$(_st_dir "$id")/office/kept")" SOURCE="${tag:-$what}"
    say "plugin_install: $what → plugin $(_st_plg_version), office $(_st_office_version)"
}
plugin_remove() {
    local id=$1
    office_keep "$id" || return 1
    _st_plugin remove "$ST_PLG_NAME.plg"
    [[ -f /var/log/plugins/$ST_PLG_NAME.plg ]] && { say "plugin_remove: still installed: $(tail -3 "$KEEP/plugin.out" | tr '\n' ' ')"; return 1; }
    say "plugin_remove: the office removed (its own remove script ran)"
}

_st_fixture() { local f=${D}.base/fixtures/data-$1.tar; [[ -f $f ]] || f=$SC_DIR/base/fixtures/data-$1.tar.gz; [[ -f $f ]] && printf '%s' "$f"; }
data_fixture() {
    local id=$1 name=$2 f
    f=$(_st_fixture "$name") || { say "needs the data fixture $name (data_fixture_make on USOTest3VM): not on this server"; return 2; }
    office_keep "$id" || return 1
    _st_agent_stop
    mv -- "$D" "$D.aside-$(date +%Y%m%d-%H%M%S)" && mkdir -p "$D" || return 1
    tar -C "$D" -xpf "$f" || return 1
    say "data_fixture: the data folder of $name in place ($(basename "$f")), the agent stopped"
}

# data_fixture_make <name> <tag> — once, on USOTest3VM (never USOPartner): see the header
data_fixture_make() {
    local name=$1 tag=$2 out st plan id=FIXTURE-${1^^}
    host_is test3 || { say "data_fixture_make: on USOTest3VM only"; return 2; }
    plugin_install "$id" "$tag" || return 1
    _st_agent_stop
    mv -- "$D" "$D.aside-$(date +%Y%m%d-%H%M%S)" && mkdir -p "$D" || return 1
    _st_agent_start || { say "data_fixture_make: no agent on a fresh data folder"; return 1; }
    php "$SC_DIR/ask.php" office.hire '{"desks":["advisor","backup","cleanup","logs","moverelli","restore","snapshot","watchman"]}' >/dev/null \
        || { say "data_fixture_make: hire refused"; return 1; }
    st=$D/unraid-backup/state/setup-status.json
    php "$SC_DIR/ask.php" --wait 60 backup.setup_plan '{}' >/dev/null || return 1
    sleep 2; _st_until 600 jq -e '.running != true and .mode == "plan" and .result != null' "$st" || { say "data_fixture_make: no plan"; return 1; }
    plan=$(php "$SC_DIR/ask.php" --raw --wait 60 backup.setup_get '{}' | jq -c '.plan') || return 1
    jq -e '(.P | type) == "object"' <<<"$plan" >/dev/null || { sleep 5; plan=$(php "$SC_DIR/ask.php" --raw --wait 60 backup.setup_get '{}' | jq -c '.plan'); }
    out=$(jq -c '{decisions: .P, plan_time: .time}' <<<"$plan")
    php "$SC_DIR/ask.php" --wait 60 backup.setup_apply "$out" >/dev/null || { say "data_fixture_make: apply refused: $(php "$SC_DIR/ask.php" --wait 60 backup.setup_apply "$out" | jq -c .error)"; return 1; }
    sleep 2; _st_until 900 jq -e '.running != true and .mode == "apply" and .result != null' "$st" || { say "data_fixture_make: apply didn't end"; return 1; }
    php "$SC_DIR/ask.php" backup.schedule '{"cron":"30 4 * * *"}' >/dev/null
    php "$SC_DIR/ask.php" restore.drill_set '{"schedule":"weekly"}' >/dev/null
    sleep 3; _st_agent_stop
    mkdir -p "$D.base/fixtures" && tar -C "$D" -cpf "$D.base/fixtures/data-$name.tar" . || return 1
    say "data_fixture_make: $D.base/fixtures/data-$name.tar ($(du -h "$D.base/fixtures/data-$name.tar" | cut -f1), setup $(jq -r .result "$st"))"
    office_back "$id"
}

# ------------------------------------------------------------------------------------------- hand-edited files
_st_ini_set() { # <file> <section header> <key> <value> — the key's first line in that section replaced, or added after the header
    awk -v h="$2" -v k="$3" -v v="$4" '
        $0 == h { s = 1; print; next }
        /^\[/ { if (s && !done) { print k " = " v; done = 1 } s = 0 }
        s && !done && $0 ~ "^[[:space:]]*" k "[[:space:]]*=" { print k " = " v; done = 1; next }
        { print }
        END { if (s && !done) print k " = " v }' "$1" > "$1.uso-new" && cat "$1.uso-new" > "$1" && rm -f "$1.uso-new"
}
ini_edit() {
    local id=$1 v=${2,,} f=$UB/settings.ini dir; dir=$(_st_dir "$id")
    [[ -f $f ]] || { say "ini_edit: no settings.ini (Mr. Backupsy not set up)"; return 2; }
    [[ -f $dir/settings.ini ]] || cp -p "$f" "$dir/settings.ini" || return 1
    case $v in
        unknown) _st_ini_set "$f" '[general]' note x ;;
        crlf) sed -i 's/$/\r/' "$f" ;;
        bom) printf '\xEF\xBB\xBF' | cat - "$dir/settings.ini" > "$f" ;;
        dup) awk '$0 == "[general]" { print; s = 1; next } s && /=/ && !d { print; print; d = 1; next } { print }' "$dir/settings.ini" > "$f" ;;
        gone-section) printf '\n[share "uso-gone"]\nmode = kopia\n' >> "$f" ;;
        zero-ret) _st_ini_set "$f" "$(grep -m1 '^\[share "' "$f")" retention '0 0 0' ;;
        preset-bad) _st_ini_set "$f" '[general]' preset_new x ;;
        mode-off-by-hand)
            local s; s=$(awk '/^\[share "/ { h = $0 } h && /^mode = (snapshot|kopia)/ { print h; exit }' "$f")
            [[ -n $s ]] || { say "ini_edit: no share at a backup level"; return 1; }
            _st_ini_set "$f" "$s" mode off; printf '%s\n' "$s" > "$dir/share" ;;
        perms) chmod 666 "$f" ;;
        owner) chown nobody "$f" ;;
        *) say "ini_edit: unknown crlf bom dup gone-section zero-ret preset-bad mode-off-by-hand perms owner, not $v"; return 1 ;;
    esac
    say "ini_edit: settings.ini by hand ($v)"
}
ini_restore() {
    local dir f=$UB/settings.ini; dir=$(_st_dir "$1")
    [[ -f $dir/settings.ini ]] || return 0
    cp -p "$dir/settings.ini" "$f" && chmod "$(stat -c %a "$dir/settings.ini")" "$f" && chown "$(stat -c %u:%g "$dir/settings.ini")" "$f" || return 1
    rm -f "$dir/settings.ini" "$dir/share"
}
mv_ini_edit() {
    local id=$1 f=$D/moverelli/smart_mover.ini dir; dir=$(_st_dir "$id")
    [[ -f $f ]] || { say "needs Ms. Moverelli set up (smart_mover.ini)"; return 2; }
    [[ -f $dir/smart_mover.ini ]] || cp -p "$f" "$dir/smart_mover.ini" || return 1
    awk '$0 == "[GLOBAL]" { print; print "uso_foreign_key=1"; print "mover_bin="; next }
        /^move_when_used_above=/ { print "move_when_used_above=150"; next } { print }' "$dir/smart_mover.ini" > "$f"
    say "mv_ini_edit: smart_mover.ini by hand (move_when_used_above=150, a foreign key, mover_bin=)"
}
mv_ini_restore() { local dir; dir=$(_st_dir "$1"); [[ -f $dir/smart_mover.ini ]] || return 0; cp -p "$dir/smart_mover.ini" "$D/moverelli/smart_mover.ini" && rm -f "$dir/smart_mover.ini"; }

# ------------------------------------------------------------------------------------------------------------ states
_st_unraid() { sed -n 's/^version="\(.*\)"$/\1/p' /etc/unraid-version; }
state_os_73() { [[ $(_st_unraid) == 7.3.* ]] || { say "needs 73: Unraid 7.3.x (this is $(_st_unraid))"; return 2; }; _st_keep_env OS-73 UNRAID="$(_st_unraid)"; }
undo_os_73() { :; }
state_os_74() { [[ $(_st_unraid) == 7.4.* ]] || { say "needs Unraid 7.4.x (this is $(_st_unraid))"; return 2; }; _st_keep_env OS-74 UNRAID="$(_st_unraid)"; }
undo_os_74() { :; }

# IN-FRESH [latest|vX.Y.Z] — removed, data folder and the flash folder's files away (scenarios.env stays), installed anew
state_in_fresh() {
    local stamp; stamp=$(date +%Y%m%d-%H%M%S)
    plugin_remove IN-FRESH || return 1
    [[ -e $D ]] && { mv -- "$D" "$D.aside-$stamp" || return 1; }
    find "$ST_PLG_FLASH" -mindepth 1 -maxdepth 1 ! -name scenarios.env ! -name "$ST_PLG_NAME.cfg" -exec rm -rf -- {} +
    rm -f "$ST_PLG_FLASH/$ST_PLG_NAME.cfg"
    plugin_install IN-FRESH "${1:-latest}" || return 1
    _st_keep_env IN-FRESH FRESH=yes
}
undo_in_fresh() { office_back IN-FRESH; }

# IN-UPDATE-PREV [previous|vX.Y.Z] — the release before installed on today's data folder; the flow updates to the tree
# IN-DOWNGRADE  [previous|vX.Y.Z] — the same install, looked at as «an older version on a newer one's data folder»
state_in_update_prev() { plugin_install IN-UPDATE-PREV "${1:-previous}"; }
undo_in_update_prev() { office_back IN-UPDATE-PREV; }
state_in_downgrade() { plugin_install IN-DOWNGRADE "${1:-previous}"; }
undo_in_downgrade() { office_back IN-DOWNGRADE; }

# IN-UPDATE-OLD [1.60] — v1.60.0 installed with its own data folder (the fixture); the flow updates to the tree
state_in_update_old() {
    local n=${1:-1.60}
    _st_fixture "$n" >/dev/null || { say "needs the data fixture $n (data_fixture_make on USOTest3VM)"; return 2; }
    plugin_install IN-UPDATE-OLD "v$n.0" && _st_agent_stop && data_fixture IN-UPDATE-OLD "$n" && _st_agent_start || return 1
    _st_keep_env IN-UPDATE-OLD FIXTURE="$n"
}
undo_in_update_old() { office_back IN-UPDATE-OLD; }

# IN-TAKEOVER — what an old script install left: User Scripts' «unraid-backup» on a schedule, the old snapshot prefix in
# settings.ini, an old @unraidbackup-… snapshot (ZFS only)
state_in_takeover() {
    local dir s ds; dir=$(_st_dir IN-TAKEOVER)
    [[ -f $UB/settings.ini ]] || { say "needs Mr. Backupsy set up"; return 2; }
    _st_plugin_install IN-TAKEOVER user.scripts "$ST_US_PLG" || return 1
    s=$ST_US_DIR/scripts/unraid-backup
    [[ -e $s ]] && { say "IN-TAKEOVER: $s exists"; return 1; }
    printf '%s\n' "$s" > "$dir/us.script"
    [[ -f $ST_US_DIR/schedule.json ]] && cp -p "$ST_US_DIR/schedule.json" "$dir/schedule.json" || printf 'none\n' > "$dir/schedule.none"
    mkdir -p "$s" && printf '#!/bin/bash\n#arrayStarted=true\nexec /mnt/user/appdata/unraid-backup/backup.sh "$@"\n' > "$s/script" && printf 'unraid-backup\n' > "$s/name" || return 1
    jq -n --arg k "$s/script" '{($k): {script: $k, frequency: "custom", id: "schedule1", custom: "0 3 * * *"}}' \
        | jq -s 'add' "$( [[ -f $ST_US_DIR/schedule.json ]] && echo "$ST_US_DIR/schedule.json" || echo /dev/null)" - \
        > "$ST_US_DIR/schedule.json.uso-new" && mv "$ST_US_DIR/schedule.json.uso-new" "$ST_US_DIR/schedule.json" || return 1
    cp -p "$UB/settings.ini" "$dir/settings.ini" && _st_ini_set "$UB/settings.ini" '[general]' snap_prefix unraidbackup- || return 1
    ds=$(zfs list -H -o name /mnt/cache/appdata 2>/dev/null)
    if [[ -n $ds ]]; then zfs snapshot "$ds@unraidbackup-20260101-0300" && printf '%s\n' "$ds@unraidbackup-20260101-0300" > "$dir/snap"
    else say "IN-TAKEOVER: no ZFS appdata here — no old snapshot"; fi
    _st_keep_env IN-TAKEOVER SCRIPT=unraid-backup SNAP_PREFIX=unraidbackup- OLD_SNAPSHOT="$(cat "$dir/snap" 2>/dev/null)"
}
undo_in_takeover() {
    local dir; dir=$(_st_dir IN-TAKEOVER)
    [[ -f $dir/snap ]] && { zfs destroy "$(cat "$dir/snap")" 2>/dev/null; zfs list -H -t snapshot "$(cat "$dir/snap")" >/dev/null 2>&1 || rm -f "$dir/snap"; }
    [[ -f $dir/settings.ini ]] && { cp -p "$dir/settings.ini" "$UB/settings.ini" && rm -f "$dir/settings.ini"; }
    userscript_mover_remove IN-TAKEOVER
}

# IN-HANDEDIT-<VARIANT> — settings.ini changed by hand (ini_edit's variants)
for _v in unknown crlf bom dup gone-section zero-ret preset-bad mode-off-by-hand perms owner; do
    _f=${_v//-/_}
    eval "state_in_handedit_$_f() { ini_edit IN-HANDEDIT-${_v^^} $_v && _st_keep_env IN-HANDEDIT-${_v^^} VARIANT=$_v; }"
    eval "undo_in_handedit_$_f() { ini_restore IN-HANDEDIT-${_v^^}; }"
done
unset _v _f

state_in_ini_missing() {
    local dir; dir=$(_st_dir IN-INI-MISSING)
    [[ -f $UB/settings.ini ]] || { say "needs Mr. Backupsy set up"; return 2; }
    mv -- "$UB/settings.ini" "$dir/settings.ini" && _st_keep_env IN-INI-MISSING SETTINGS=missing
}
undo_in_ini_missing() { local dir; dir=$(_st_dir IN-INI-MISSING); [[ -f $dir/settings.ini ]] || return 0; mv -- "$dir/settings.ini" "$UB/settings.ini"; }

state_in_moverelli_ini_handedit() { mv_ini_edit IN-MOVERELLI-INI-HANDEDIT && _st_keep_env IN-MOVERELLI-INI-HANDEDIT X=150 FOREIGN=uso_foreign_key MOVER_BIN=empty; }
undo_in_moverelli_ini_handedit() { mv_ini_restore IN-MOVERELLI-INI-HANDEDIT; }
