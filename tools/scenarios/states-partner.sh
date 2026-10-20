#!/bin/bash
# tools/scenarios/states-partner.sh — the partner states of zustaende.md «H. Partner» (PT-*) between Tower (the sender)
# and USOPartner (the receiver, 192.168.7.190, a free test VM since 2026-10-20), the pairing the test bed keeps, the
# partner base of both servers and Mr. Restori's flow RS-PARTNER. Sourced by lib.sh; uses states-common.sh. Batch G.
#
# The test bed (made once, 2026-10-20): Tower reaches USOPartner's root shell with a key of its own,
# $OFFICE_FLASH/scenarios-partner.key (0600, its host key pinned in scenarios-partner.known) — USOPartner's
# authorized_keys carries its line `from="192.168.7.111" ssh-ed25519 … uso-scenarios-tower` (never a uso-partner: line:
# the office there never writes or removes it). Tower's half of the pairing (flash partners/<id>.key, .key.pub, .known
# and the door line for USOPartner) is carried in $OFFICE_FLASH/scenarios-partner-base/ (0600): a plugin remove takes it
# away by design (OF-REMOVE-REINSTALL), pt_ok puts it back. Without the test bed every PT state answers «needs» (2).
#
#   pt_sh <cmd…>              a command on USOPartner as root (ssh joins the words: quote with printf %q), its output
#   pt_ok                     the test bed whole: the key, USOPartner answers, the pairing on both sides (pairs.json, the
#                             keys and known_hosts, the door lines both ways) — says what is missing and returns 2
#   pt_id                     the pairing's id (Tower's pairs.json: the pair at 192.168.7.190)
#   pt_ask <desk.action> [json] [timeout]
#                             the button on USOPartner's page: tools/scenarios/ask.php copied there (/tmp/uso-pt/ask.php)
#   pt_fp [here|there]        the partner base of one server, one line per fact: partner/ files (not its logs, state.json,
#                             the looks' held.json), flash partners/, the door lines, the datasets and snapshots under
#                             every <pool>/UnraidSecretaryOffice-partners and …-restored, the pairs' zfs quota
#   pt_base_snap <dir> / pt_base_diff <dir>
#                             both servers' pt_fp into <dir> / the difference to it now (nothing printed: as before; 1)
#   pt_base_same              after `pt_base_snap $KEEP/ptbase` and `undo_push pt_base_same` as a scenario's first undo
#                             (runs last): 1 and the difference when either server's partner base isn't as before
#   pt_keep <id> / pt_back <id>
#                             both sides' partner files set aside for a state and put back exactly (partner/ but its
#                             logs, flash partners/, the uso-partner:/uso-ticket: lines of authorized_keys); the logs
#                             (door.log, deletes.jsonl) stay as they grew
#   pt_drop_received <share>  what USOPartner received of the scratch share <share> (uso-wip-* only) destroyed: its unit
#                             dataset under every …-partners/<id>/ (received/<id>.json comes back with pt_back)
#   pt_quota_bytes            the bytes the pair's dataset at USOPartner holds (zfs used)
#
# States (`state` pushes the undo; every undo is idempotent):
#   PT-OFFLINE                `iptables -I OUTPUT -d 192.168.7.190 -j DROP` on Tower; undo deletes exactly that rule
#                             (iptables -S compared with before). USOPartner's shell is out of reach meanwhile.
#   PT-ARRAY-STOPPED          USOPartner's array stopped through its emcmd (cmdStop); undo starts it, whatever happened
#                             in between (and waits for its agent's heartbeat)
#   PT-QUOTA-FULL [gb]        USOPartner's pairs.json receive.quota_gb = gb (1) for the pair — refused (1) unless the pair's
#                             dataset there holds ≥ gb GiB already (the scenario sends that much first); undo: pairs.json
#                             and the dataset's zfs quota as before
#   PT-ENDED                  the partnership ended at Tower's Team Lead (caretaker.partner_end: its line, key and
#                             known_hosts gone there); undo: both sides' partner files back (pt_back)
#   PT-ANEW                   ended on both sides, then paired anew the Team Lead's way (Tower offers, USOPartner accepts,
#                             Tower pastes the answer with the safety code; the receive rules both had): a new id
#                             (printed into $KEEP/pt-anew.id); undo: both sides' partner files back (the old pairing, the
#                             same id), what the new id received destroyed (uso-wip-* units only)
#   PT-PULLED unit= [snap=]   a pull from USOPartner lies beside (= the flow RS-PARTNER); undo: «Remove what was pulled…»
#
# Flow RS-PARTNER unit=<unit> [snap=<uso-backup-…>] [refuse=ok]: «At USOPartner» looked at anew (restore.partner_look —
# his tick hands the look to atd; waited for until held.json is newer than the click), the moment picked (the newest
# unless snap= names one), «Bring back…» (restore.preview/start {kind:'partner', pair, unit, snap}, desk.js ~1449), the
# job waited for. The journal id in $KEEP/.rs-last (fl_last_restore), the pulled dataset in $KEEP/pt-pulled.ds.

PT_IP=192.168.7.190
PT_KEY=$OFFICE_FLASH/scenarios-partner.key
PT_KNOWN=$OFFICE_FLASH/scenarios-partner.known
PT_CARRY=$OFFICE_FLASH/scenarios-partner-base
PT_FLASH=$OFFICE_FLASH/partners
PT_AUTH=/boot/config/ssh/root/authorized_keys
PT_ASK=/tmp/uso-pt/ask.php

pt_sh() {
    ssh -i "$PT_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 \
        -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$PT_KNOWN" "root@$PT_IP" "$@"
}

pt_id() { jq -r --arg a "$PT_IP" '[(.pairs // [])[] | select(.address == $a) | .id][0] // empty' "$D/partner/pairs.json" 2>/dev/null; }

# the data folder on USOPartner (its .cfg, as lib.sh's _facts reads Tower's)
_PT_D=
_pt_d() {
    [[ -n $_PT_D ]] || _PT_D=$(pt_sh "sed -n -E 's/^[[:space:]]*DATA_DIR[[:space:]]*=[[:space:]]*\"?([^\"]*)\"?[[:space:]]*\$/\\1/p' $OFFICE_CFG | head -1")
    _PT_D=${_PT_D%/}
    printf '%s' "${_PT_D:-/mnt/user/appdata/UnraidSecretaryOffice/data}"
}

# Tower's half of the pairing kept beside the test bed's key (once, while whole) and put back when a remove took it
_pt_carry() {
    local id=$1 f line
    if [[ -f $PT_FLASH/$id.key && -f $PT_FLASH/$id.known ]] && grep -q "uso-partner:$id\$" "$PT_AUTH"; then
        [[ -f $PT_CARRY/$id.key ]] && return 0
        mkdir -p "$PT_CARRY" && chmod 700 "$PT_CARRY" || return 1
        cp "$PT_FLASH/$id.key" "$PT_FLASH/$id.key.pub" "$PT_FLASH/$id.known" "$PT_CARRY/" && grep "uso-partner:$id\$" "$PT_AUTH" >"$PT_CARRY/$id.line" || return 1
        chmod 600 "$PT_CARRY"/* 2>/dev/null
        say "pt: Tower's half of the pairing $id kept in $PT_CARRY (for a plugin remove)"
        return 0
    fi
    [[ -f $PT_CARRY/$id.key && -f $PT_CARRY/$id.line ]] || return 1
    # the carried key must be the one pairs.json names (my_key), else it is another pairing's
    [[ $(ssh-keygen -lf "$PT_CARRY/$id.key.pub" 2>/dev/null | awk '{print $2}') == "$(jq -r --arg i "$id" '.pairs[] | select(.id == $i) | .my_key' "$D/partner/pairs.json")" ]] || return 1
    mkdir -p "$PT_FLASH" && chmod 700 "$PT_FLASH"
    for f in key key.pub known; do [[ -f $PT_FLASH/$id.$f ]] || cp "$PT_CARRY/$id.$f" "$PT_FLASH/$id.$f" || return 1; done
    if ! grep -q "uso-partner:$id\$" "$PT_AUTH"; then
        line=$(cat "$PT_CARRY/$id.line")
        { cat "$PT_AUTH"; printf '%s\n' "$line"; } >"$PT_AUTH.uso-new" && chmod 600 "$PT_AUTH.uso-new" && mv "$PT_AUTH.uso-new" "$PT_AUTH" || return 1
    fi
    say "pt: Tower's half of the pairing $id was gone (a plugin remove takes it) — put back from $PT_CARRY"
}

pt_ok() {
    local id there
    [[ -f $PT_KEY && -f $PT_KNOWN ]] || { say "needs the partner test bed: no $PT_KEY (states-partner.sh's header)"; return 2; }
    id=$(pt_id)
    [[ -n $id ]] || { say "needs a pairing with USOPartner ($PT_IP) in Tower's pairs.json"; return 2; }
    _pt_carry "$id" || { say "needs Tower's half of the pairing $id: $PT_FLASH/$id.key or the door line missing, nothing carried"; return 2; }
    pt_sh true 2>/dev/null || { say "needs USOPartner's shell: ssh root@$PT_IP with the test bed's key failed"; return 2; }
    there=$(pt_sh "jq -r --arg i $id '[(.pairs // [])[] | select(.id == \$i)] | length' $(_pt_d)/partner/pairs.json; test -f $PT_FLASH/$id.key && echo key; grep -c 'uso-partner:$id\$' $PT_AUTH" 2>/dev/null | paste -sd' ')
    [[ $there == '1 key 1' ]] || { say "needs the pairing $id on USOPartner too (pair · key · door line: «$there»)"; return 2; }
    return 0
}

pt_ask() {
    local action=$1 json=${2:-'{}'} t=${3:-60}
    pt_sh "mkdir -p ${PT_ASK%/*} && cat > $PT_ASK" <"$SC_DIR/ask.php" || return 1
    say "pt_ask (USOPartner) $action"
    pt_sh "php $PT_ASK --wait $t $(printf %q "$action") $(printf %q "$json")"
}

# pt_fp: the fingerprint script, run here (bash) or there (pt_sh bash -s) — D, the flash folder and the auth file as args
_PT_FP_SCRIPT='
d=$1 flash=$2 auth=$3
cd "$d/partner" 2>/dev/null && find . -type f ! -name "door.log*" ! -name "deletes.jsonl*" ! -name state.json ! -name held.json \
    ! -name "*.uso-new" -printf "%P\n" | LC_ALL=C sort | while IFS= read -r f; do printf "partner %s %s\n" "$f" "$(md5sum < "$f" | cut -c1-32)"; done
[[ -d $flash ]] && (cd "$flash" && for f in *; do [[ -f $f ]] && printf "flash %s %s\n" "$f" "$(md5sum < "$f" | cut -c1-32)"; done)
grep -E "uso-(partner|ticket):[0-9a-f]{8}\$" "$auth" 2>/dev/null | LC_ALL=C sort | while IFS= read -r l; do printf "door %s %s\n" "${l##* }" "$(printf %s "$l" | md5sum | cut -c1-32)"; done
for p in $(zfs list -H -d 0 -o name 2>/dev/null); do
    for top in "$p/UnraidSecretaryOffice-partners" "$p/UnraidSecretaryOffice-restored"; do
        zfs list -H "$top" >/dev/null 2>&1 || continue
        zfs list -H -r -t filesystem,volume,snapshot -o name "$top" | LC_ALL=C sort | sed "s/^/zfs /"
        zfs list -H -d 1 -o name,quota "$top" | sed "1d; s/^/quota /"
    done
done
zfs list -H -t filesystem -o name 2>/dev/null | grep -E "[.]restored-[0-9]{8}-[0-9]{6}\$" | sed "s/^/pulled /"
'
pt_fp() {
    case ${1:-here} in
        here) bash -c "$_PT_FP_SCRIPT" _ "$D" "$PT_FLASH" "$PT_AUTH" ;;
        there) pt_sh "bash -s -- $(printf %q "$(_pt_d)") $PT_FLASH $PT_AUTH" <<<"$_PT_FP_SCRIPT" ;;
    esac
}
pt_base_snap() {
    local dir=$1
    mkdir -p "$dir" || return 1
    pt_fp here >"$dir/tower.fp" && pt_fp there >"$dir/partner.fp" || { say "pt_base_snap: USOPartner's partner base could not be read"; return 1; }
    say "pt_base_snap: $(wc -l <"$dir/tower.fp") facts on Tower, $(wc -l <"$dir/partner.fp") on USOPartner → $dir"
}
# pt_base_same: the partner base after the undo as $KEEP/ptbase says (a scenario's first undo_push: it runs last)
pt_base_same() {
    local d
    [[ -f $KEEP/ptbase/tower.fp ]] || return 0
    if d=$(pt_base_diff "$KEEP/ptbase"); then say "partner base: both servers as before"; return 0; fi
    say "partner base: NOT as before — $(head -c 600 <<<"$d" | paste -sd' ')"
    return 1
}
pt_base_diff() {
    local dir=$1 d rc=0
    d=$(diff "$dir/tower.fp" <(pt_fp here) | grep '^[<>]') && { printf 'Tower: %s\n' "$d"; rc=1; }
    d=$(diff "$dir/partner.fp" <(pt_fp there) 2>&1 | grep -E '^[<>]|^ssh') && { printf 'USOPartner: %s\n' "$d"; rc=1; }
    return $rc
}

# pt_keep <id>: both sides' partner files into the stash (tar: partner/ without its logs, flash partners/, the lines)
_PT_KEEP_SCRIPT='
d=$(readlink -f "$1") flash=$2 auth=$3 out=$4
rm -rf "$out" && mkdir -p "$out/partner" "$out/flash" || exit 1
(cd "$d/partner" && tar --warning=no-timestamp --exclude="./door.log*" --exclude="./deletes.jsonl*" -cf - .) | tar --warning=no-timestamp -C "$out/partner" -xf - || exit 1
[[ -d $flash ]] && cp -p "$flash"/* "$out/flash/" 2>/dev/null || touch "$out/flash.none"
grep -E "uso-(partner|ticket):[0-9a-f]{8}\$" "$auth" > "$out/lines" || true
tar -C "$out" -cf - . | gzip
'
_PT_BACK_SCRIPT='
d=$(readlink -f "$1") flash=$2 auth=$3 in=$4      # the pool path: rsync refuses /mnt/user/<exclusive share> (a symlink nobody owns)
[[ -d $in/partner ]] || exit 1
rsync -a --delete --exclude="door.log*" --exclude="deletes.jsonl*" "$in/partner/" "$d/partner/" || exit 1
if [[ -f $in/flash.none ]]; then rm -rf "$flash"; else rm -rf "$flash" && mkdir -p "$flash" && cp "$in/flash/"* "$flash/" && chmod 600 "$flash"/*; fi || exit 1
{ grep -vE "uso-(partner|ticket):[0-9a-f]{8}\$" "$auth"; cat "$in/lines"; } > "$auth.uso-new" && chmod 600 "$auth.uso-new" && mv "$auth.uso-new" "$auth"
'
pt_keep() {
    local id=$1 dir
    dir=$(_st_dir "$id") || return 1
    [[ -f $dir/tower.tgz ]] && return 0
    bash -c "$_PT_KEEP_SCRIPT" _ "$D" "$PT_FLASH" "$PT_AUTH" "$dir/tower" >"$dir/tower.tgz.part" && mv "$dir/tower.tgz.part" "$dir/tower.tgz" || return 1
    pt_sh "bash -s -- $(printf %q "$(_pt_d)") $PT_FLASH $PT_AUTH /tmp/uso-pt/keep-$id" <<<"$_PT_KEEP_SCRIPT" >"$dir/partner.tgz.part" \
        && mv "$dir/partner.tgz.part" "$dir/partner.tgz" || { say "pt_keep: USOPartner's partner files could not be kept"; return 1; }
    pt_sh "rm -rf /tmp/uso-pt/keep-$id"
    rm -rf "$dir/tower"
    say "pt_keep $id: both sides' partner files kept ($(du -b "$dir/tower.tgz" | cut -f1) + $(du -b "$dir/partner.tgz" | cut -f1) bytes)"
}
pt_back() {
    local id=$1 dir t
    dir=$(_st_dir "$id") || return 1
    [[ -f $dir/tower.tgz && -f $dir/partner.tgz ]] || return 0
    t=$dir/back.tower && rm -rf "$t" && mkdir -p "$t" && tar --warning=no-timestamp -C "$t" -xzf "$dir/tower.tgz" || return 1
    bash -c "$_PT_BACK_SCRIPT" _ "$D" "$PT_FLASH" "$PT_AUTH" "$t" || { say "pt_back: Tower's partner files could not be put back"; return 1; }
    rm -rf "$t"
    pt_sh "rm -rf /tmp/uso-pt/back-$id && mkdir -p /tmp/uso-pt/back-$id && tar --warning=no-timestamp -C /tmp/uso-pt/back-$id -xzf - && bash -c $(printf %q "$_PT_BACK_SCRIPT") _ $(printf %q "$(_pt_d)") $PT_FLASH $PT_AUTH /tmp/uso-pt/back-$id && rm -rf /tmp/uso-pt/back-$id" <"$dir/partner.tgz" \
        || { say "pt_back: USOPartner's partner files could not be put back"; return 1; }
    rm -f "$dir/tower.tgz" "$dir/partner.tgz"
    # the Team Leads' cards drawn anew from the files put back (his look; never a failure of the undo)
    ask caretaker.refresh '{}' 60 >/dev/null 2>&1; pt_ask caretaker.refresh '{}' 60 >/dev/null 2>&1
    say "pt_back $id: both sides' partner files as before"
}

pt_quota_bytes() {
    local id; id=$(pt_id)
    pt_sh "zfs list -H -p -o used \$(zfs list -H -o name | grep -m1 '/UnraidSecretaryOffice-partners/$id\$')" 2>/dev/null
}

pt_drop_received() {
    local share=$1
    [[ $share == uso-wip-* ]] || { say "pt_drop_received: only a uso-wip-* share's copies, not $share"; return 1; }
    pt_sh "bash -s -- $(printf %q "share-$share")" <<'EOF'
for top in $(zfs list -H -o name | grep -E '/UnraidSecretaryOffice-partners$'); do
    for pair in $(zfs list -H -d 1 -o name "$top" | sed 1d); do
        zfs list -H "$pair/$1" >/dev/null 2>&1 || continue
        zfs destroy -r "$pair/$1" && echo "destroyed $pair/$1"
    done
done
EOF
}

# ---------------------------------------------------------------------------------------------------------- PT-OFFLINE
state_pt_offline() {
    local dir; dir=$(_st_dir PT-OFFLINE)
    pt_ok || return
    iptables -S >"$dir/iptables.before" || return 1
    iptables -I OUTPUT -d "$PT_IP" -j DROP || return 1
    printf 'on\n' >"$dir/rule"
    say "PT-OFFLINE: iptables -I OUTPUT -d $PT_IP -j DROP — USOPartner out of reach"
    ! timeout 8 bash -c "exec 3<>/dev/tcp/$PT_IP/22" 2>/dev/null || { say "PT-OFFLINE: port 22 still answers"; return 1; }
}
undo_pt_offline() {
    local dir; dir=$(_st_dir PT-OFFLINE)
    [[ -f $dir/rule ]] || return 0
    iptables -D OUTPUT -d "$PT_IP" -j DROP || return 1
    rm -f "$dir/rule"
    diff <(cat "$dir/iptables.before") <(iptables -S) >/dev/null || { say "PT-OFFLINE undo: iptables -S differs from before: $(diff "$dir/iptables.before" <(iptables -S) | grep '^[<>]' | paste -sd' ')"; return 1; }
    rm -f "$dir/iptables.before"
    say "PT-OFFLINE undo: the rule gone, iptables -S as before"
}

# ---------------------------------------------------------------------------------------------------- PT-ARRAY-STOPPED
_pt_var() { pt_sh "sed -n -E 's/^$1=\"?([^\"]*)\"?\$/\\1/p' /var/local/emhttp/var.ini" 2>/dev/null; }
_pt_is() { [[ $(_pt_var "$1") == "$2" ]]; }
state_pt_array_stopped() {
    local dir; dir=$(_st_dir PT-ARRAY-STOPPED)
    pt_ok || return
    [[ $(_pt_var mdState) == STARTED ]] || { say "PT-ARRAY-STOPPED: USOPartner's array isn't started ($(_pt_var mdState))"; return 1; }
    printf 'started\n' >"$dir/array.was"
    say "PT-ARRAY-STOPPED: emcmd cmdStop=Stop on USOPartner"
    pt_sh "cd / && /usr/local/sbin/emcmd cmdStop=Stop" >/dev/null 2>&1 || { say "PT-ARRAY-STOPPED: emcmd refused"; return 1; }
    _st_until 300 _pt_is mdState STOPPED || { say "PT-ARRAY-STOPPED: not stopped after 5 min ($(_pt_var mdState))"; return 1; }
    say "PT-ARRAY-STOPPED: USOPartner's array is stopped"
}
undo_pt_array_stopped() {
    local dir; dir=$(_st_dir PT-ARRAY-STOPPED)
    [[ -f $dir/array.was ]] || return 0
    if [[ $(_pt_var mdState) != STARTED ]]; then
        say "PT-ARRAY-STOPPED undo: emcmd cmdStart=Start on USOPartner"
        pt_sh "cd / && /usr/local/sbin/emcmd cmdStart=Start" >/dev/null 2>&1 || { sleep 10; pt_sh "cd / && /usr/local/sbin/emcmd cmdStart=Start" >/dev/null 2>&1; }
        _st_until 300 _pt_is mdState STARTED || { say "PT-ARRAY-STOPPED undo: USOPartner's array not started after 5 min ($(_pt_var mdState))"; return 1; }
        _st_until 120 _pt_is fsState Started || { say "PT-ARRAY-STOPPED undo: fsState=$(_pt_var fsState)"; return 1; }
    fi
    _st_until 120 pt_sh "f=$RUN/agent.json; [[ -f \$f ]] && (( \$(date +%s) - \$(stat -c %Y \$f) < 30 ))" \
        || say "note: no fresh heartbeat of USOPartner's agent 2 min after its array's start"
    rm -f "$dir/array.was"
    say "PT-ARRAY-STOPPED undo: USOPartner's array is started"
}

# ------------------------------------------------------------------------------------------------------- PT-QUOTA-FULL
state_pt_quota_full() {
    local gb=${1:-1} dir id f used
    dir=$(_st_dir PT-QUOTA-FULL)
    pt_ok || return
    id=$(pt_id) f=$(_pt_d)/partner/pairs.json
    used=$(pt_quota_bytes)
    [[ $used =~ ^[0-9]+$ ]] && ((used >= gb << 30)) || { say "PT-QUOTA-FULL: the pair's dataset at USOPartner holds ${used:-?} bytes — less than $gb GiB (send that much first)"; return 1; }
    [[ -f $dir/pairs.json ]] || pt_sh "cat $f" >"$dir/pairs.json" || return 1
    pt_sh "zfs get -H -o value quota \$(zfs list -H -o name | grep -m1 '/UnraidSecretaryOffice-partners/$id\$')" >"$dir/quota.was" || return 1
    pt_sh "jq -c --arg i $id --argjson g $gb '.pairs |= map(if .id == \$i then .receive.quota_gb = \$g else . end)' $f > $f.uso-new && chmod 600 $f.uso-new && mv $f.uso-new $f" || return 1
    say "PT-QUOTA-FULL: USOPartner keeps at most $gb GiB of Tower's (pairs.json receive.quota_gb), it holds $used bytes"
}
undo_pt_quota_full() {
    local dir id f
    dir=$(_st_dir PT-QUOTA-FULL)
    [[ -f $dir/pairs.json ]] || return 0
    id=$(pt_id) f=$(_pt_d)/partner/pairs.json
    pt_sh "cat > $f.uso-new && chmod 600 $f.uso-new && mv $f.uso-new $f" <"$dir/pairs.json" || return 1
    pt_sh "zfs set quota=$(cat "$dir/quota.was") \$(zfs list -H -o name | grep -m1 '/UnraidSecretaryOffice-partners/$id\$')" || return 1
    rm -f "$dir/pairs.json" "$dir/quota.was"
    say "PT-QUOTA-FULL undo: USOPartner's pairs.json and the pair's quota as before"
}

# ---------------------------------------------------------------------------------------------------- PT-ENDED, -ANEW
state_pt_ended() {
    local id
    pt_ok || return
    id=$(pt_id)
    pt_keep PT-ENDED || return 1
    printf '%s\n' "$id" >"$(_st_dir PT-ENDED)/id"
    ask caretaker.partner_end "$(jq -cn --arg i "$id" '{id: $i}')" >/dev/null || { say "PT-ENDED: «End the partnership» refused: $(ask_last '.error.key')"; return 1; }
    [[ -z $(pt_id) && ! -f $PT_FLASH/$id.key ]] && ! grep -q "uso-partner:$id\$" "$PT_AUTH" \
        || { say "PT-ENDED: the pair, its key or its line still at Tower"; return 1; }
    say "PT-ENDED: the partnership $id ended at Tower's Team Lead (the pair, key, known_hosts and door line gone there)"
}
undo_pt_ended() { pt_back PT-ENDED && rm -f "$(_st_dir PT-ENDED)/id"; }

_pt_rules() { jq -c --arg i "$1" '.pairs[] | select(.id == $i) | .receive | {pool, quota_gb, retention}' "$2"; }
state_pt_anew() {
    local id new a b code dir tr pr
    dir=$(_st_dir PT-ANEW)
    pt_ok || return
    id=$(pt_id)
    pt_keep PT-ANEW || return 1
    printf '%s\n' "$id" >"$dir/id"
    tr=$(_pt_rules "$id" "$D/partner/pairs.json")
    pr=$(pt_sh "jq -c --arg i $id '.pairs[] | select(.id == \$i) | .receive | {pool, quota_gb, retention}' $(_pt_d)/partner/pairs.json")
    [[ -n $tr && -n $pr ]] || { say "PT-ANEW: the receive rules of $id not readable (Tower $tr, USOPartner $pr)"; return 1; }
    # ended on both sides (each Team Lead's «End the partnership»)
    ask caretaker.partner_end "$(jq -cn --arg i "$id" '{id: $i}')" >/dev/null || { say "PT-ANEW: ending at Tower refused: $(ask_last '.error.key')"; return 1; }
    pt_ask caretaker.partner_end "$(jq -cn --arg i "$id" '{id: $i}')" | jq -e '.ok == true' >/dev/null || { say "PT-ANEW: ending at USOPartner refused"; return 1; }
    # «Add a partner…» at Tower: its address, the block A
    a=$(ask caretaker.partner_add "$(jq -cn --arg a "$TOWER_IP" '{step: "do", address: $a, port: 22, trust: "mine"}')") || { say "PT-ANEW: the offer refused: $(ask_last '.error.key')"; return 1; }
    new=$(jq -r '.id' <<<"$a")
    printf '%s\n' "$new" >"$dir/new"
    # «Accept a partner…» at USOPartner: it keeps what Tower sends and sends too — its rules as before; the block B and the code
    b=$(pt_ask caretaker.partner_accept "$(jq -cn --arg k "$(jq -r .block <<<"$a")" --arg a "$PT_IP" --argjson r "$pr" \
        '{block: $k, step: "do", confirm: true, receive: $r, send_too: true, address: $a, port: 22, trust: "mine"}')" 120)
    jq -e '.ok == true' <<<"$b" >/dev/null || { say "PT-ANEW: USOPartner's accept refused: $(jq -c '.error // .' <<<"$b" | head -c 200)"; return 1; }
    code=$(jq -r .code <<<"$b")
    # «Paste the partner's answer» at Tower: the same safety code, its rules as before
    ask caretaker.partner_finish "$(jq -cn --arg k "$(jq -r .block <<<"$b")" --arg c "$code" --argjson r "$tr" \
        '{block: $k, step: "do", confirm: true, code: $c, receive: $r}')" 120 >/dev/null || { say "PT-ANEW: Tower's finish refused: $(ask_last '.error.key')"; return 1; }
    [[ $(pt_id) == "$new" ]] || { say "PT-ANEW: Tower's pairs.json doesn't name $new at $PT_IP"; return 1; }
    cp "$dir/new" "$KEEP/pt-anew.id"
    say "PT-ANEW: the partnership $id ended on both sides, paired anew as $new (same address, the same receive rules)"
}
undo_pt_anew() {
    local dir new rc=0
    dir=$(_st_dir PT-ANEW)
    new=$(cat "$dir/new" 2>/dev/null)
    pt_back PT-ANEW || rc=1
    if [[ -n $new ]]; then
        # what the new pairing received there: only scratch units (a base has none under a new id), then its empty parent
        pt_sh "bash -s -- $new" <<'EOF' || rc=1
new=$1
for p in $(zfs list -H -o name | grep -E "/UnraidSecretaryOffice-partners/$new\$"); do
    for u in $(zfs list -H -d 1 -o name "$p" | sed 1d); do
        [[ ${u##*/} == share-uso-wip-* ]] || { echo "left $u: not a scratch unit"; exit 1; }
        zfs destroy -r "$u" && echo "destroyed $u"
    done
    zfs destroy "$p" && echo "destroyed $p"
done
EOF
        rm -f "$dir/new"
    fi
    ((rc == 0)) && rm -f "$dir/id"
    return $rc
}

# ------------------------------------------------------------------------------------------------- PT-PULLED, RS-PARTNER
# held.json newer than <time>: his look ran after the click
_pt_looked_since() { local t; t=$(jq -r '.tried // 0' "$D/partner/$1/held.json" 2>/dev/null); [[ $t =~ ^[0-9]+$ ]] && ((t >= $2)); }
flow_rs_partner() {
    _fl_args "${@/#unit=/name=}" || return 1          # unit= rides on FL[name] (flows.sh's keys)
    local unit=${FL[name]:-} snap=${FL[snapshot]:-${FL[moment]:-}} id t0 req
    [[ -n $unit ]] || { say "RS-PARTNER: unit= missing"; return 1; }
    id=$(pt_id)
    [[ -n $id ]] || { say "RS-PARTNER: no pairing with USOPartner"; return 1; }
    # «Look again» on his partner tile (desk.js ~1423): his tick hands the look to atd
    t0=$(date +%s)
    ask restore.partner_look '{}' 60 >/dev/null || return 1
    wait_for 240 "Mr. Restori's look at USOPartner (held.json)" _pt_looked_since "$id" "$t0" || return 1
    if [[ -z $snap ]]; then
        snap=$(jq -r --arg u "$unit" '[(.units[$u] // [])[] | .name] | sort | last // empty' "$D/partner/$id/held.json")
        [[ -n $snap ]] || { say "RS-PARTNER: USOPartner holds no moment of $unit ($(jq -c '{reachable, why}' "$D/partner/$id/held.json"))"; return 1; }
        say "RS-PARTNER: moment $snap (the newest USOPartner holds)"
    fi
    req=$(jq -cn --arg p "$id" --arg u "$unit" --arg s "$snap" '{kind: "partner", pair: $p, unit: $u, snap: $s}')
    _rs_go "$req" || return 1
    jq -r '[.pulled[]?.dataset][0] // empty' "$D/restore/$(fl_last_restore)/journal.json" >"$KEEP/pt-pulled.ds"
    say "RS-PARTNER: pulled into $(cat "$KEEP/pt-pulled.ds")"
}
state_pt_pulled() {
    pt_ok || return
    flow_rs_partner "$@" && [[ -s $KEEP/pt-pulled.ds ]] || return 1
    fl_last_restore >"$(_st_dir PT-PULLED)/journal"
}
undo_pt_pulled() {
    local dir j; dir=$(_st_dir PT-PULLED)
    j=$(cat "$dir/journal" 2>/dev/null) || return 0
    [[ -n $j ]] || return 0
    # «Remove what was pulled…» (desk.js ~2320: putback of the pull's journal) — destroys exactly that dataset
    flow_rs_putback of="$j" || return 1
    rm -f "$dir/journal"
}
