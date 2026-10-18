#!/bin/bash
# tools/scenarios/apps.sh — the demo apps of the apps server (Tower), each made the way Unraid makes it (H2).
#
#   bash apps.sh [--recreate] [--project <name>] all | <app>…    make what is missing (--recreate: anew, new id)
#   bash apps.sh remove <container> [keep-template]               docker rm -f; the template goes too unless kept
#   bash apps.sh status                                           the demo containers and their state
#
# Apps: EmbyServer · Nextcloud (with mariadb-nextcloud) · mariadb-nextcloud · Immich (immich_server, immich_postgres,
# immich_redis — one compose project «immich», --project another name) · each Immich member alone.
#
# Single containers get a user template in templates-user (as ⟦Add Container⟧ writes it) and are created by Unraid's own
# rebuild_container from it (what «Apply» runs) — so a state that removes and re-creates one does it the Unraid way.
# Nextcloud and its database share a user network «uso-apps» (Docker keeps it: DOCKER_USER_NETWORKS=preserve). Immich is
# a compose stack as `docker compose` leaves it: labels, no template (Tower has no Compose Manager — STATES.md).
# Passwords only from /boot/config/plugins/unraid-secretary-office/scenarios.env (0600; MARIADB_ROOT_PASSWORD,
# MARIADB_NC_PASSWORD, NEXTCLOUD_ADMIN_PASSWORD, IMMICH_DB_PASSWORD) — never in this file, never printed; the
# templates on the flash carry them masked, as Unraid's do. The shares the apps need (nextcloud, photos; media and
# appdata exist) are made with Unraid's «Add Share» when missing. Seed: briefs/tools/tower-demo/tower-apps.sh.
set -u
SC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
declare -F say >/dev/null || say() { printf 'apps: %s\n' "$*"; }
declare -F track_share >/dev/null || track_share() { :; }
: "${KEEP_STASH:=/tmp/uso-keep/apps/stash}"
. "$SC_DIR/states-common.sh"
. "$SC_DIR/states-shares.sh"

APPS_NET=uso-apps
APPS_PROJECT=immich
APPS_RECREATE=no
IMMICH_MEMBERS=(immich_postgres immich_redis immich_server)

_apps_secret() {
    local v; v=$(_st_env "$1")
    [[ -n $v ]] || { say "scenarios.env has no $1 — add it (0600, NAME=value)"; return 1; }
    printf '%s' "$v"
}
_apps_xml() { local s=$1; s=${s//&/&amp;}; s=${s//</&lt;}; s=${s//>/&gt;}; s=${s//\"/&quot;}; printf '%s' "$s"; }
# _apps_cfg <Name> <Target> <Default> <Mode> <Type> <Value> [mask]
_apps_cfg() {
    printf '  <Config Name="%s" Target="%s" Default="%s" Mode="%s" Description="" Type="%s" Display="always" Required="true" Mask="%s">%s</Config>\n' \
        "$1" "$2" "$(_apps_xml "$3")" "$4" "$5" "${7:-false}" "$(_apps_xml "$6")"
}
# _apps_tpl <Name> <Repository> <Network> <WebUI> <Icon> <Overview> <Category> <PostArgs> <configs xml>
_apps_tpl() {
    local f=$ST_TEMPLATES/my-$1.xml installed
    installed=$(sed -n 's|.*<DateInstalled>\([0-9]*\)</DateInstalled>.*|\1|p' "$f" 2>/dev/null)
    mkdir -p "$ST_TEMPLATES"
    cat > "$f.uso-new" <<EOF
<?xml version="1.0"?>
<Container version="2">
  <Name>$1</Name>
  <Repository>$2</Repository>
  <Registry>https://hub.docker.com/</Registry>
  <Network>$3</Network>
  <MyIP/>
  <Shell>sh</Shell>
  <Privileged>false</Privileged>
  <Support/>
  <Project/>
  <Overview>$6</Overview>
  <Category>$7</Category>
  <WebUI>$4</WebUI>
  <TemplateURL/>
  <Icon>$5</Icon>
  <ExtraParams/>
  <PostArgs>$8</PostArgs>
  <CPUset/>
  <DateInstalled>${installed:-$(date +%s)}</DateInstalled>
  <DonateText/>
  <DonateLink/>
  <Requires/>
$9</Container>
EOF
    mv "$f.uso-new" "$f"
}
_apps_image() { # pull only what isn't there (a nightly works without the net once the images are in)
    timeout 20 docker image inspect "$1" >/dev/null 2>&1 && return 0
    say "pulling $1"; docker pull -q "$1" >/dev/null
}
_apps_exists() { timeout 20 docker inspect "$1" >/dev/null 2>&1; }
_apps_running() { [[ $(timeout 20 docker inspect -f '{{.State.Status}}' "$1" 2>/dev/null) == running ]]; }
# _apps_from_tpl <Name> — Unraid's rebuild_container from the template, then started
_apps_from_tpl() {
    local n=$1
    _apps_image "$(sed -n 's|.*<Repository>\(.*\)</Repository>.*|\1|p' "$ST_TEMPLATES/my-$n.xml")" || return 1
    "$ST_REBUILD" "$n" >/dev/null 2>&1
    _apps_exists "$n" || { say "rebuild_container made no $n"; return 1; }
    docker start "$n" >/dev/null && _st_until 60 _apps_running "$n" || { say "$n doesn't start"; return 1; }
    say "$n up (Unraid's rebuild_container from my-$n.xml)"
}
_apps_skip() { _apps_exists "$1" && [[ $APPS_RECREATE == no ]] && { say "$1 is there — left as it is"; return 0; }; return 1; }
_apps_share() { share_exists "$1" && return 0; share_make "$1" "$2" "${3:-}"; }
_apps_net() { timeout 20 docker network inspect "$1" >/dev/null 2>&1 || docker network create "$1" >/dev/null; }

app_EmbyServer() {
    _apps_skip EmbyServer && return 0
    _apps_share media yes cache || return 1
    mkdir -p /mnt/user/appdata/emby && chown 99:100 /mnt/user/appdata/emby
    _apps_tpl EmbyServer emby/embyserver:latest bridge 'http://[IP]:[PORT:8096]/' \
        https://raw.githubusercontent.com/MediaBrowser/Emby.Resources/master/images/logoicon.png \
        'Emby Server is a personal media server.' 'MediaServer:Video MediaServer:Music' '' \
        "$(_apps_cfg WebUI 8096 8096 tcp Port 8096)$(_apps_cfg Config /config /mnt/user/appdata/emby rw Path /mnt/user/appdata/emby)$(_apps_cfg Media /mnt/media /mnt/user/media ro Path /mnt/user/media)$(_apps_cfg UID UID 99 '' Variable 99)$(_apps_cfg GID GID 100 '' Variable 100)"
    _apps_from_tpl EmbyServer
}

app_mariadb_nextcloud() {
    _apps_skip mariadb-nextcloud && return 0
    local root nc; root=$(_apps_secret MARIADB_ROOT_PASSWORD) && nc=$(_apps_secret MARIADB_NC_PASSWORD) || return 1
    mkdir -p /mnt/user/appdata/mariadb-nextcloud
    _apps_net "$APPS_NET" || return 1
    _apps_tpl mariadb-nextcloud mariadb:11 "$APPS_NET" '' \
        https://raw.githubusercontent.com/linuxserver/docker-templates/master/linuxserver.io/img/mariadb-logo.png \
        'MariaDB for Nextcloud.' 'Tools:' '--transaction-isolation=READ-COMMITTED --binlog-format=ROW' \
        "$(_apps_cfg Data /var/lib/mysql /mnt/user/appdata/mariadb-nextcloud rw Path /mnt/user/appdata/mariadb-nextcloud)$(_apps_cfg MARIADB_ROOT_PASSWORD MARIADB_ROOT_PASSWORD '' '' Variable "$root" true)$(_apps_cfg MARIADB_DATABASE MARIADB_DATABASE nextcloud '' Variable nextcloud)$(_apps_cfg MARIADB_USER MARIADB_USER nextcloud '' Variable nextcloud)$(_apps_cfg MARIADB_PASSWORD MARIADB_PASSWORD '' '' Variable "$nc" true)"
    _apps_from_tpl mariadb-nextcloud
}

app_Nextcloud() {
    _apps_exists mariadb-nextcloud || app_mariadb_nextcloud || return 1
    _apps_running mariadb-nextcloud || docker start mariadb-nextcloud >/dev/null
    _apps_skip Nextcloud && return 0
    local nc admin; nc=$(_apps_secret MARIADB_NC_PASSWORD) && admin=$(_apps_secret NEXTCLOUD_ADMIN_PASSWORD) || return 1
    _apps_share nextcloud only cache || return 1
    mkdir -p /mnt/user/appdata/nextcloud && chown 33:33 /mnt/user/appdata/nextcloud /mnt/user/nextcloud
    _apps_tpl Nextcloud nextcloud:apache "$APPS_NET" 'http://[IP]:[PORT:8080]/' \
        https://raw.githubusercontent.com/nextcloud/server/master/core/img/favicon.png \
        'Nextcloud: your files, calendar, contacts.' 'Cloud: Productivity:' '' \
        "$(_apps_cfg WebUI 80 8080 tcp Port 8080)$(_apps_cfg Config /var/www/html /mnt/user/appdata/nextcloud rw Path /mnt/user/appdata/nextcloud)$(_apps_cfg Data /var/www/html/data /mnt/user/nextcloud rw Path /mnt/user/nextcloud)$(_apps_cfg MYSQL_HOST MYSQL_HOST mariadb-nextcloud '' Variable mariadb-nextcloud)$(_apps_cfg MYSQL_DATABASE MYSQL_DATABASE nextcloud '' Variable nextcloud)$(_apps_cfg MYSQL_USER MYSQL_USER nextcloud '' Variable nextcloud)$(_apps_cfg MYSQL_PASSWORD MYSQL_PASSWORD '' '' Variable "$nc" true)$(_apps_cfg NEXTCLOUD_ADMIN_USER NEXTCLOUD_ADMIN_USER admin '' Variable admin)$(_apps_cfg NEXTCLOUD_ADMIN_PASSWORD NEXTCLOUD_ADMIN_PASSWORD '' '' Variable "$admin" true)$(_apps_cfg NEXTCLOUD_TRUSTED_DOMAINS NEXTCLOUD_TRUSTED_DOMAINS '*' '' Variable '*')"
    _apps_from_tpl Nextcloud || return 1
    # the first start installs Nextcloud (a minute or two): the office's occ needs it installed
    _st_until 300 bash -c "docker exec -u 33 Nextcloud php occ status --output=json 2>/dev/null | grep -q '\"installed\":true'" \
        && say "Nextcloud installed" || say "note: Nextcloud not installed after 5 min (occ status)"
}

# Immich: a compose stack as compose leaves it (labels), no template
_immich_labels() { printf -- '--label\0com.docker.compose.project=%s\0--label\0com.docker.compose.project.working_dir=/mnt/user/appdata/immich\0--label\0com.docker.compose.service=%s\0' "$APPS_PROJECT" "$1"; }
app_immich_postgres() {
    _apps_skip immich_postgres && return 0
    local pw; pw=$(_apps_secret IMMICH_DB_PASSWORD) || return 1
    local img=ghcr.io/immich-app/postgres:14-vectorchord0.3.0-pgvectors0.2.0 labels=()
    _apps_image "$img" && _apps_net immich_default || return 1
    mkdir -p /mnt/user/appdata/immich/postgres
    mapfile -d '' labels < <(_immich_labels database)
    docker rm -f immich_postgres >/dev/null 2>&1
    docker run -d --name immich_postgres --net immich_default "${labels[@]}" -e POSTGRES_PASSWORD="$pw" -e POSTGRES_USER=postgres \
        -e POSTGRES_DB=immich -e POSTGRES_INITDB_ARGS=--data-checksums -v /mnt/user/appdata/immich/postgres:/var/lib/postgresql/data \
        "$img" >/dev/null && say "immich_postgres up (project $APPS_PROJECT)"
}
app_immich_redis() {
    _apps_skip immich_redis && return 0
    local img=valkey/valkey:8-bookworm labels=()
    _apps_image "$img" && _apps_net immich_default || return 1
    mapfile -d '' labels < <(_immich_labels redis)
    docker rm -f immich_redis >/dev/null 2>&1
    docker run -d --name immich_redis --net immich_default "${labels[@]}" "$img" >/dev/null && say "immich_redis up (project $APPS_PROJECT)"
}
app_immich_server() {
    _apps_skip immich_server && return 0
    local pw; pw=$(_apps_secret IMMICH_DB_PASSWORD) || return 1
    local img=ghcr.io/immich-app/immich-server:release labels=()
    _apps_image "$img" && _apps_net immich_default && _apps_share photos yes cache || return 1
    mkdir -p /mnt/user/appdata/immich/upload && chown 99:100 /mnt/user/appdata/immich/upload
    mapfile -d '' labels < <(_immich_labels immich-server)
    docker rm -f immich_server >/dev/null 2>&1
    docker run -d --name immich_server --net immich_default -p 2283:2283 "${labels[@]}" \
        --label 'net.unraid.docker.webui=http://[IP]:[PORT:2283]/' \
        --label net.unraid.docker.icon=https://raw.githubusercontent.com/immich-app/immich/main/design/immich-logo.svg \
        -e DB_HOSTNAME=immich_postgres -e DB_USERNAME=postgres -e DB_PASSWORD="$pw" -e DB_DATABASE_NAME=immich \
        -e REDIS_HOSTNAME=immich_redis -e MACHINE_LEARNING_ENABLED=false \
        -v /mnt/user/appdata/immich/upload:/usr/src/app/upload -v /mnt/user/photos:/mnt/photos:ro \
        "$img" >/dev/null && say "immich_server up (project $APPS_PROJECT)"
}
app_Immich() { app_immich_postgres && app_immich_redis && app_immich_server; }

apps_remove() {
    local n=$1 keep=${2:-}
    timeout 30 docker rm -f "$n" >/dev/null 2>&1 && say "$n removed"
    [[ $keep == keep-template ]] || { [[ -f $ST_TEMPLATES/my-$n.xml ]] && rm -f "$ST_TEMPLATES/my-$n.xml" && say "my-$n.xml removed"; }
    return 0
}
apps_status() {
    local n
    for n in EmbyServer mariadb-nextcloud Nextcloud "${IMMICH_MEMBERS[@]}"; do
        printf '%-20s %s\n' "$n" "$(timeout 20 docker inspect -f '{{.State.Status}} {{index .Config.Labels "com.docker.compose.project"}}' "$n" 2>/dev/null || echo missing)"
    done
}

apps_main() {
    local what=() a rc=0
    while (($#)); do
        case $1 in
            --recreate) APPS_RECREATE=yes; shift ;;
            --project)  APPS_PROJECT=$2; shift 2 ;;
            remove)     shift; apps_remove "$@"; return ;;
            status)     apps_status; return ;;
            all)        what+=(EmbyServer Nextcloud Immich); shift ;;
            *)          what+=("$1"); shift ;;
        esac
    done
    ((${#what[@]})) || { sed -n '3,6p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; return 64; }
    timeout 10 docker info >/dev/null 2>&1 || { say "Docker isn't running"; return 1; }
    for a in "${what[@]}"; do
        a=${a//-/_}
        declare -F "app_$a" >/dev/null || { say "no app $a (EmbyServer Nextcloud mariadb-nextcloud Immich ${IMMICH_MEMBERS[*]})"; rc=1; continue; }
        "app_$a" || rc=1
    done
    return $rc
}

[[ ${BASH_SOURCE[0]} == "$0" ]] && { apps_main "$@"; exit; }
