#!/bin/bash
# tools/scenarios/states-apps.sh — containers and compose stacks (the A states, CT-* and ST-*) (H2). Sourced by lib.sh.
# The real thing every time: a stopped container is `docker stop`ped, a renamed one `docker rename`d, a removed one is
# gone (its template kept or moved aside as the id says), a re-created one is made anew by Unraid's rebuild_container
# from its template (or by apps.sh for a compose member). An undo brings back the same names, images, binds, labels,
# templates and running/paused/stopped state; a container that had to be re-created gets a new id (unavoidable —
# reported by the self-test, not compared).
#
#   ct_status <name>                    running | paused | exited | created | missing
#   ct_note <id> <name>…                remembers their state for ct_back
#   ct_back <id>                        each noted container in its noted state again (re-created when gone)
#   ct_stop <name>… / ct_start <name>…  (start waits for «healthy» when the container has a health check)
#   ct_pause <name> / ct_unpause <name>
#   ct_remove <id> <name> [keep-template]   docker rm -f; the template moved into the stash unless kept
#   ct_recreate <name>                  anew: Unraid's rebuild_container from my-<name>.xml, else apps.sh --recreate
#   ct_rename <old> <new>               docker rename
#   ct_image_remove <id> <name>         its image's tag removed (docker rmi -f <repo:tag>): the container still points at
#                                       the image id, `docker image inspect <repo:tag>` fails — what an update/prune leaves
#   ct_make <id> <name> <image> [docker run args…] [-- cmd…]   a throwaway container (uso-…), noted for ct_unmake
#   ct_unmake <id>                      those removed, with the appdata folders and volumes they made
#   stack_members <project>             the containers labelled com.docker.compose.project=<project>
#
# States: CT-RUNNING CT-STOPPED CT-STOPPED-NC CT-STOPPED-DB CT-STOPPED-PLAIN CT-PAUSED CT-GONE CT-GONE-TEMPLATE-STAYS
# CT-IMAGE-GONE CT-RENAMED-DOCKER CT-RENAMED-UNRAID CT-RECREATED CT-NEW CT-BINDS-ROOT CT-APP-IS-DATASET CT-VOLUME-ONLY
# CT-MANY CT-HEALTH-STARTING CT-AUTOSTART ST-STACK ST-STACK-CM ST-RENAMED ST-MEMBER-STOPPED ST-MEMBER-GONE ST-CM-FILE-UNREADABLE

ST_CM_PROJECTS=/boot/config/plugins/compose.manager/projects

# (docker inspect prints an empty line for a container that isn't there: the exit code decides)
ct_status() { local s; s=$(timeout 20 docker inspect -f '{{.State.Status}}' "$1" 2>/dev/null) && [[ -n $s ]] || s=missing; printf '%s\n' "$s"; }
_st_ct_health() { timeout 20 docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$1" 2>/dev/null; }
_st_ct_need() { local n; for n; do [[ $(ct_status "$n") != missing ]] || { say "needs container $n (apps.sh $n)"; return 2; }; done; }

ct_note() {
    local id=$1 n dir; shift; dir=$(_st_dir "$id")
    for n; do grep -q "^$n " "$dir/ct.notes" 2>/dev/null || printf '%s %s\n' "$n" "$(ct_status "$n")" >> "$dir/ct.notes"; done
}
ct_stop() {
    local n
    for n; do
        [[ $(ct_status "$n") == exited || $(ct_status "$n") == created ]] && continue
        timeout 120 docker stop "$n" >/dev/null || return 1
        _st_until 60 bash -c "[[ \$(docker inspect -f '{{.State.Status}}' '$n') == exited ]]" || { say "ct_stop: $n not exited"; return 1; }
        say "ct_stop: $n stopped"
    done
}
ct_start() {
    local n h
    for n; do
        case $(ct_status "$n") in
            running) continue ;;
            paused) docker unpause "$n" >/dev/null || return 1; continue ;;
        esac
        timeout 120 docker start "$n" >/dev/null || { say "ct_start: $n doesn't start"; return 1; }
        h=$(_st_ct_health "$n")
        if [[ -n $h ]]; then
            _st_until 300 bash -c "[[ \$(docker inspect -f '{{.State.Health.Status}}' '$n') != starting ]]" \
                || say "note: $n still «starting» after 5 min"
        fi
        say "ct_start: $n running${h:+ ($(_st_ct_health "$n"))}"
    done
}
ct_pause() { [[ $(ct_status "$1") == running ]] || { say "ct_pause: $1 isn't running"; return 1; }; docker pause "$1" >/dev/null && say "ct_pause: $1 paused"; }
ct_unpause() { [[ $(ct_status "$1") == paused ]] || return 0; docker unpause "$1" >/dev/null; }

ct_recreate() {
    local n=$1
    if [[ -f $ST_TEMPLATES/my-$n.xml ]]; then
        "$ST_REBUILD" "$n" >/dev/null 2>&1
        [[ $(ct_status "$n") != missing ]] || { say "ct_recreate: rebuild_container made no $n"; return 1; }
        say "ct_recreate: $n anew from my-$n.xml (Unraid's rebuild_container)"
    else
        bash "$ST_DIR/apps.sh" --recreate "$n" >/dev/null || { say "ct_recreate: apps.sh can't make $n"; return 1; }
        say "ct_recreate: $n anew (apps.sh)"
    fi
}
# _st_ct_to <name> <state> — the container in that state (re-created when missing)
_st_ct_to() {
    local n=$1 want=$2
    [[ $(ct_status "$n") == missing ]] && { ct_recreate "$n" || return 1; }
    case $want in
        running) ct_start "$n" ;;
        paused) ct_start "$n" && ct_pause "$n" ;;
        exited|created) ct_stop "$n" ;;
        missing) timeout 60 docker rm -f "$n" >/dev/null ;;
    esac
}
ct_back() {
    local dir n s rc=0; dir=$(_st_dir "$1")
    [[ -f $dir/ct.notes ]] || return 0
    # a database before its app: mariadb-nextcloud, immich_postgres/redis first
    while read -r n s; do _st_ct_to "$n" "$s" || rc=1; done < <(sort -k1,1 -t' ' "$dir/ct.notes" | awk '/mariadb|postgres|redis/ { print; next } { rest = rest $0 "\n" } END { printf "%s", rest }')
    ((rc == 0)) && rm -f "$dir/ct.notes"
    return $rc
}

ct_remove() {
    local id=$1 n=$2 keep=${3:-} dir; dir=$(_st_dir "$id")
    ct_note "$id" "$n"
    if [[ $keep != keep-template && -f $ST_TEMPLATES/my-$n.xml ]]; then
        mv "$ST_TEMPLATES/my-$n.xml" "$dir/my-$n.xml" || return 1
        printf '%s\n' "$n" >> "$dir/templates.aside"
    fi
    timeout 60 docker rm -f "$n" >/dev/null || return 1
    say "ct_remove: $n removed${keep:+ (template kept)}"
}
_st_templates_back() {
    local dir n; dir=$(_st_dir "$1")
    [[ -f $dir/templates.aside ]] || return 0
    while read -r n; do [[ -f $dir/my-$n.xml ]] && mv "$dir/my-$n.xml" "$ST_TEMPLATES/my-$n.xml"; done < "$dir/templates.aside"
    rm -f "$dir/templates.aside"
}
ct_rename() { [[ $(ct_status "$2") == missing ]] || { say "ct_rename: $2 exists"; return 1; }; docker rename "$1" "$2" && say "ct_rename: $1 → $2"; }

ct_image_remove() {
    local id=$1 n=$2 img iid dir keep pool; dir=$(_st_dir "$id")
    img=$(docker inspect -f '{{.Config.Image}}' "$n") && iid=$(docker inspect -f '{{.Image}}' "$n") || return 1
    # `docker rmi -f` on an image only a stopped container uses deletes it (Tower, 2026-10-18): the container keeps an
    # image id nobody has, `docker start` fails. The way back without the net: the image saved first, on Docker's own
    # pool beside the shares (a dot folder is no share), loaded again by the undo — the same id.
    pool=$(findmnt -no SOURCE /var/lib/docker | sed 's|/.*||'); pool=${pool:-cache}
    mountpoint -q "/mnt/$pool" || pool=cache
    keep=/mnt/$pool/.uso-keep/${id,,}; mkdir -p "$keep" || return 1
    (( $(df --output=avail -B1G "/mnt/$pool" | tail -1) > 5 )) || { say "ct_image_remove: no room on $pool to keep the image"; return 1; }
    ct_note "$id" "$n"; ct_stop "$n" || return 1
    printf '%s %s %s\n' "$iid" "$img" "$keep/image.tar" > "$dir/image.untagged"
    docker save -o "$keep/image.tar" "$img" || return 1
    docker rmi -f "$img" >/dev/null || return 1
    ! docker image inspect "$img" >/dev/null 2>&1 || { say "ct_image_remove: $img is still there"; return 1; }
    say "ct_image_remove: $img gone — $n points at an image id nobody has"
}
_st_image_back() {
    local f iid img tar; f=$(_st_dir "$1")/image.untagged
    [[ -f $f ]] || return 0
    read -r iid img tar < "$f"
    if ! docker image inspect "$iid" >/dev/null 2>&1; then docker load -q -i "$tar" >/dev/null || return 1; fi
    docker image inspect "$img" >/dev/null 2>&1 || docker tag "$iid" "$img" || return 1
    rm -f "$tar"; rmdir "${tar%/*}" "${tar%/*/*}" 2>/dev/null
    rm -f "$f"
}

ct_make() {
    local id=$1 n=$2 img=$3 dir args=() cmd=() a; shift 3; dir=$(_st_dir "$id")
    while (($#)); do [[ $1 == -- ]] && { shift; cmd=("$@"); break; }; args+=("$1"); shift; done
    [[ $(ct_status "$n") == missing ]] || { say "ct_make: $n exists already"; return 1; }
    timeout 20 docker image inspect "$img" >/dev/null 2>&1 || docker pull -q "$img" >/dev/null || return 1
    # the appdata folders and named volumes it binds that aren't there yet: made now, removed by ct_unmake
    for a in "${args[@]}"; do
        case $a in
            /mnt/user/appdata/*:*) a=${a%%:*}; [[ -e $a ]] || { mkdir -p "$a" && printf 'dir %s\n' "$a" >> "$dir/made"; } ;;
            [a-z]*:/*) a=${a%%:*}; [[ $a == */* ]] || timeout 20 docker volume inspect "$a" >/dev/null 2>&1 || printf 'vol %s\n' "$a" >> "$dir/made" ;;
        esac
    done
    printf 'ct %s\n' "$n" >> "$dir/made"
    docker run -d --name "$n" "${args[@]}" "$img" "${cmd[@]}" >/dev/null || return 1
    say "ct_make: $n ($img)"
}
ct_unmake() {
    local dir kind what rc=0; dir=$(_st_dir "$1")
    [[ -f $dir/made ]] || return 0
    while read -r kind what; do
        case $kind in
            ct) [[ $(ct_status "$what") == missing ]] || timeout 60 docker rm -f "$what" >/dev/null || rc=1 ;;
            vol) timeout 20 docker volume inspect "$what" >/dev/null 2>&1 && { docker volume rm "$what" >/dev/null || rc=1; } ;;
            dir) rm -rf -- "$what" ;;
        esac
    done < <(tac "$dir/made")
    ((rc == 0)) && rm -f "$dir/made"
    return $rc
}
stack_members() { timeout 20 docker ps -a --filter "label=com.docker.compose.project=$1" --format '{{.Names}}' | sort; }

# ------------------------------------------------------------------------------------------------------------ states
state_ct_running() { say "CT-RUNNING: the base state — nothing to make"; }
undo_ct_running() { :; }

# CT-STOPPED <names…> (default EmbyServer); the three catalogue variants have ids of their own
state_ct_stopped() { (($#)) || set -- EmbyServer; _st_ct_need "$@" || return; ct_note CT-STOPPED "$@"; ct_stop "$@"; }
undo_ct_stopped() { ct_back CT-STOPPED; }
state_ct_stopped_nc() { _st_ct_need Nextcloud mariadb-nextcloud || return; ct_note CT-STOPPED-NC Nextcloud mariadb-nextcloud; ct_stop Nextcloud mariadb-nextcloud; }
undo_ct_stopped_nc() { ct_back CT-STOPPED-NC; }
state_ct_stopped_db() { _st_ct_need mariadb-nextcloud || return; ct_note CT-STOPPED-DB mariadb-nextcloud; ct_stop mariadb-nextcloud; }
undo_ct_stopped_db() { ct_back CT-STOPPED-DB; }
state_ct_stopped_plain() { _st_ct_need EmbyServer || return; ct_note CT-STOPPED-PLAIN EmbyServer; ct_stop EmbyServer; }
undo_ct_stopped_plain() { ct_back CT-STOPPED-PLAIN; }

state_ct_paused() { local n=${1:-EmbyServer}; _st_ct_need "$n" || return; ct_note CT-PAUSED "$n"; ct_pause "$n"; }
undo_ct_paused() { ct_back CT-PAUSED; }

state_ct_gone() { local n=${1:-EmbyServer}; _st_ct_need "$n" || return; ct_remove CT-GONE "$n"; }
undo_ct_gone() { _st_templates_back CT-GONE; ct_back CT-GONE; }
state_ct_gone_template_stays() { local n=${1:-EmbyServer}; _st_ct_need "$n" || return; ct_remove CT-GONE-TEMPLATE-STAYS "$n" keep-template; }
undo_ct_gone_template_stays() { ct_back CT-GONE-TEMPLATE-STAYS; }

state_ct_image_gone() { local n=${1:-EmbyServer}; _st_ct_need "$n" || return; ct_image_remove CT-IMAGE-GONE "$n"; }
undo_ct_image_gone() { _st_image_back CT-IMAGE-GONE && ct_back CT-IMAGE-GONE; }

state_ct_renamed_docker() { local a=${1:-EmbyServer} b=${2:-EmbyServer2}
    _st_ct_need "$a" || return
    printf '%s %s\n' "$a" "$b" > "$(_st_dir CT-RENAMED-DOCKER)/renamed"; ct_rename "$a" "$b"; }
undo_ct_renamed_docker() { local f a b; f=$(_st_dir CT-RENAMED-DOCKER)/renamed
    [[ -f $f ]] || return 0; read -r a b < "$f"
    [[ $(ct_status "$b") != missing && $(ct_status "$a") == missing ]] && { docker rename "$b" "$a" || return 1; }
    rm -f "$f"; }

# renamed in Unraid's Docker form: a new template with the new name, the old container and template gone, the new
# container made from the new template (the same binds)
state_ct_renamed_unraid() { local a=${1:-EmbyServer} b=${2:-EmbyServer2} dir
    _st_ct_need "$a" || return
    dir=$(_st_dir CT-RENAMED-UNRAID)
    [[ -f $ST_TEMPLATES/my-$a.xml ]] || { say "CT-RENAMED-UNRAID: $a has no template — not one Unraid's form renames"; return 1; }
    [[ $(ct_status "$b") == missing && ! -e $ST_TEMPLATES/my-$b.xml ]] || { say "CT-RENAMED-UNRAID: $b exists"; return 1; }
    printf '%s %s\n' "$a" "$b" > "$dir/renamed"
    ct_note CT-RENAMED-UNRAID "$a"
    sed "s|<Name>$a</Name>|<Name>$b</Name>|" "$ST_TEMPLATES/my-$a.xml" > "$ST_TEMPLATES/my-$b.xml" || return 1
    ct_remove CT-RENAMED-UNRAID "$a" || return 1
    "$ST_REBUILD" "$b" >/dev/null 2>&1
    [[ $(ct_status "$b") != missing ]] || { say "CT-RENAMED-UNRAID: no $b"; return 1; }
    [[ $(grep "^$a " "$dir/ct.notes" | cut -d' ' -f2) == running ]] && { ct_start "$b" || return 1; }
    say "CT-RENAMED-UNRAID: $a is now $b (template my-$b.xml)"; }
undo_ct_renamed_unraid() { local dir a b; dir=$(_st_dir CT-RENAMED-UNRAID)
    [[ -f $dir/renamed ]] || return 0; read -r a b < "$dir/renamed"
    [[ $(ct_status "$b") == missing ]] || timeout 60 docker rm -f "$b" >/dev/null || return 1
    rm -f "$ST_TEMPLATES/my-$b.xml"
    _st_templates_back CT-RENAMED-UNRAID && ct_back CT-RENAMED-UNRAID || return 1
    rm -f "$dir/renamed"; }

# re-created under the same name (update, force update): a new id, nothing else changes — there is no way back to
# the old id, so the undo only brings the noted state back
state_ct_recreated() { local n=${1:-EmbyServer}; _st_ct_need "$n" || return
    ct_note CT-RECREATED "$n"; local was; was=$(docker inspect -f '{{.Id}}' "$n")
    ct_recreate "$n" && _st_ct_to "$n" "$(grep "^$n " "$(_st_dir CT-RECREATED)/ct.notes" | cut -d' ' -f2)" || return 1
    [[ $(docker inspect -f '{{.Id}}' "$n") != "$was" ]] || { say "CT-RECREATED: same id"; return 1; }; }
undo_ct_recreated() { ct_back CT-RECREATED; }

state_ct_new() { _st_need docker _st_docker_up || return
    ct_make CT-NEW uso-new alpine:latest -v /mnt/user/appdata/uso-new:/data -- sleep 1d; }
undo_ct_new() { ct_unmake CT-NEW; }

# CT-BINDS-ROOT [all|share]: /mnt/user as a whole, or one whole share (media)
state_ct_binds_root() { _st_need docker _st_docker_up || return
    case ${1:-all} in
        all) ct_make CT-BINDS-ROOT uso-wide alpine:latest -v /mnt/user:/all:ro -- sleep 1d ;;
        share) ct_make CT-BINDS-ROOT uso-wide alpine:latest -v "/mnt/user/${2:-media}:/media" -- sleep 1d ;;
        *) say "CT-BINDS-ROOT: all|share"; return 1 ;;
    esac; }
undo_ct_binds_root() { ct_unmake CT-BINDS-ROOT; }

# one app folder a dataset of its own, another a plain folder — Tower has both (jellyfin, emby): only looked at
state_ct_app_is_dataset() { local ds=${1:-jellyfin} dir=${2:-emby}
    _st_need zfs zpool list -H cache || return
    [[ -n $(_st_dataset_of "/mnt/cache/appdata/$ds") ]] || { say "CT-APP-IS-DATASET: appdata/$ds is no dataset"; return 1; }
    [[ -d /mnt/cache/appdata/$dir && -z $(_st_dataset_of "/mnt/cache/appdata/$dir") ]] || { say "CT-APP-IS-DATASET: appdata/$dir is no plain folder"; return 1; }
    say "CT-APP-IS-DATASET: appdata/$ds is a dataset, appdata/$dir a folder (the base)"; }
undo_ct_app_is_dataset() { :; }

state_ct_volume_only() { _st_need docker _st_docker_up || return
    ct_make CT-VOLUME-ONLY uso-vol alpine:latest -v usovol:/data -- sleep 1d; }
undo_ct_volume_only() { ct_unmake CT-VOLUME-ONLY; }

state_ct_many() { local n=${1:-40} i; _st_need docker _st_docker_up || return
    for ((i = 1; i <= n; i++)); do ct_make CT-MANY "uso-m$i" alpine:latest -v "/mnt/user/appdata/uso-m$i:/d" -- sleep 1d || return 1; done; }
undo_ct_many() { ct_unmake CT-MANY; }

state_ct_health_starting() { _st_need docker _st_docker_up || return
    ct_make CT-HEALTH-STARTING uso-sick alpine:latest --health-cmd 'exit 1' --health-interval 5s --health-retries 1000 -- sleep 1d || return 1
    _st_until 20 bash -c '[[ $(docker inspect -f "{{.State.Health.Status}}" uso-sick) =~ starting|unhealthy ]]'; }
undo_ct_health_starting() { ct_unmake CT-HEALTH-STARTING; }

# CT-AUTOSTART [names…]: Unraid's Docker autostart (the Docker page's ⟦Autostart⟧ switch — /var/lib/docker/unraid-autostart,
# a name a line; rc.docker starts them in that order when Docker starts) for these containers, default every one running
# now, databases first. What a user's server has and the test VMs' base lacks: after an array stop or a reboot Docker
# brings back only these — without them a database stays down and the engine's --recover can't take Nextcloud out of
# maintenance mode (batch D, USOTest4 2026-10-19). Lines already there are kept. Undo: the file as it was (or none).
_st_ct_is_db() { [[ $(timeout 20 docker inspect -f '{{.Config.Image}}' "$1" 2>/dev/null) =~ (mariadb|mysql|postgres|redis|valkey|mongo) ]]; }
state_ct_autostart() { local f=/var/lib/docker/unraid-autostart dir n names=() db=() rest=()
    _st_need docker _st_docker_up || return
    dir=$(_st_dir CT-AUTOSTART)
    [[ -e $dir/autostart.before || -e $dir/autostart.none ]] || { if [[ -f $f ]]; then cp -p "$f" "$dir/autostart.before"; else : > "$dir/autostart.none"; fi; }
    if (($#)); then names=("$@"); else mapfile -t names < <(timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort); fi
    for n in "${names[@]}"; do
        [[ -n $n ]] || continue
        grep -qE "^$n( |$)" "$f" 2>/dev/null && continue
        if _st_ct_is_db "$n"; then db+=("$n"); else rest+=("$n"); fi
    done
    # a database's line with a wait (rc.docker sleeps that long after starting it): an app started at once after its
    # database may find it not ready and exit (immich_server did after a reboot)
    # (each part an `if`: a group ending on a false `((…))` failed the state when every name was on the list already)
    { if [[ -f $f ]]; then cat "$f"; fi; if ((${#db[@]})); then printf '%s 5\n' "${db[@]}"; fi
      if ((${#rest[@]})); then printf '%s\n' "${rest[@]}"; fi; } > "$f.uso-new" && mv -f "$f.uso-new" "$f" || return 1
    say "CT-AUTOSTART: $(wc -l < "$f") container(s) on Docker's autostart (databases first: ${db[*]:-none})"; }
undo_ct_autostart() { local f=/var/lib/docker/unraid-autostart dir; dir=$(_st_dir CT-AUTOSTART)
    if [[ -f $dir/autostart.before ]]; then cp -p "$dir/autostart.before" "$f" && rm -f "$dir/autostart.before"
    elif [[ -f $dir/autostart.none ]]; then rm -f "$f" && rm -f "$dir/autostart.none"; fi; }

# ST-STACK: the Immich stack (labels) — made by apps.sh when missing, removed again by the undo then
state_st_stack() { local p=${1:-immich} m
    _st_need docker _st_docker_up || return
    if [[ -z $(stack_members "$p") ]]; then
        printf '%s\n' "$p" > "$(_st_dir ST-STACK)/made"
        bash "$ST_DIR/apps.sh" --project "$p" Immich >/dev/null || return 1
    fi
    m=$(stack_members "$p" | wc -l); ((m >= 3)) || { say "ST-STACK: $p has $m members"; return 1; }
    say "ST-STACK: compose project $p with $m members"; }
undo_st_stack() { local f=$(_st_dir ST-STACK)/made p n
    [[ -f $f ]] || return 0; p=$(cat "$f")
    for n in $(stack_members "$p"); do timeout 60 docker rm -f "$n" >/dev/null || return 1; done
    rm -f "$f"; }

# ST-STACK-CM: the stack as Compose Manager keeps it (its project folder) — needs compose.manager + docker compose
state_st_stack_cm() { local p=${1:-immich}
    _st_need "compose.manager (docker compose)" bash -c "docker compose version && test -d '$ST_CM_PROJECTS'" || return
    [[ -d $ST_CM_PROJECTS/$p ]] || { say "ST-STACK-CM: no Compose Manager project $p — make it in its page"; return 1; }
    state_st_stack "$p"; }
undo_st_stack_cm() { undo_st_stack; }

# installed again under another project name (the bug of 17.10.): the same containers, the new project label
state_st_renamed() { local old=${1:-immich} new=${2:-immich-new} n dir
    dir=$(_st_dir ST-RENAMED)
    [[ -n $(stack_members "$old") ]] || { say "needs the $old stack (apps.sh Immich)"; return 2; }
    [[ $old == immich ]] || { say "ST-RENAMED: apps.sh makes only the Immich stack"; return 1; }
    for n in $(stack_members "$old"); do printf '%s %s\n' "$n" "$(ct_status "$n")" >> "$dir/members"; done
    printf '%s %s\n' "$old" "$new" > "$dir/renamed"
    bash "$ST_DIR/apps.sh" --recreate --project "$new" Immich >/dev/null || return 1
    [[ -z $(stack_members "$old") && -n $(stack_members "$new") ]] || { say "ST-RENAMED: the labels didn't change"; return 1; }
    say "ST-RENAMED: $old → $new"; }
undo_st_renamed() { local dir old new n s; dir=$(_st_dir ST-RENAMED)
    [[ -f $dir/renamed ]] || return 0; read -r old new < "$dir/renamed"
    [[ -n $(stack_members "$new") ]] && { bash "$ST_DIR/apps.sh" --recreate --project "$old" Immich >/dev/null || return 1; }
    while read -r n s; do [[ $s == running ]] || _st_ct_to "$n" "$s" || return 1; done < "$dir/members"
    rm -f "$dir/renamed" "$dir/members"; }

state_st_member_stopped() { local n=${1:-immich_redis}; _st_ct_need "$n" || return; ct_note ST-MEMBER-STOPPED "$n"; ct_stop "$n"; }
undo_st_member_stopped() { ct_back ST-MEMBER-STOPPED; }
state_st_member_gone() { local n=${1:-immich_redis}; _st_ct_need "$n" || return; ct_remove ST-MEMBER-GONE "$n"; }
undo_st_member_gone() { ct_back ST-MEMBER-GONE; }

# the stack's compose file unreadable (chmod 000) or gone (moved aside) — needs a Compose Manager project
state_st_cm_file_unreadable() { local p=${1:-immich} how=${2:-unreadable} f dir r
    _st_need "compose.manager project $p" test -d "$ST_CM_PROJECTS/$p" || return
    dir=$(_st_dir ST-CM-FILE-UNREADABLE)
    for r in compose.yaml compose.yml docker-compose.yml docker-compose.yaml; do [[ -f $ST_CM_PROJECTS/$p/$r ]] && { f=$ST_CM_PROJECTS/$p/$r; break; }; done
    [[ -n ${f:-} ]] || { say "ST-CM-FILE-UNREADABLE: no compose file in $p"; return 1; }
    if [[ $how == gone ]]; then mv "$f" "$dir/" && printf 'gone %s\n' "$f" > "$dir/cm"
    else stat -c %a "$f" > "$dir/mode" && chmod 000 "$f" && printf 'mode %s\n' "$f" > "$dir/cm"; fi; }
undo_st_cm_file_unreadable() { local dir kind f; dir=$(_st_dir ST-CM-FILE-UNREADABLE)
    [[ -f $dir/cm ]] || return 0; read -r kind f < "$dir/cm"
    if [[ $kind == gone ]]; then mv "$dir/${f##*/}" "$f"; else chmod "$(cat "$dir/mode")" "$f"; fi && rm -f "$dir/cm" "$dir/mode"; }
