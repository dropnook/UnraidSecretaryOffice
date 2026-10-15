#!/bin/bash
# Lab only: Emby, Jellyfin and Plex as Unraid docker containers with a user template (shown in the Docker tab).
# Path mappings differ on purpose (MediaCache must translate each):
#   EmbyServer /mnt/media  <- /mnt/user/media   port 8096 (bridge)
#   Jellyfin   /data/media <- /mnt/user/media   port 8097 -> 8096 (bridge)
#   Plex       /media      <- /mnt/user/media   port 32400 (host network, unclaimed, ALLOWED_NETWORKS = LAN)
# Existing containers are left alone. Usage on the server: bash containers.sh
set -euo pipefail
T=/boot/config/plugins/dockerMan/templates-user
IP=$(ip -4 -o addr show br0 2>/dev/null | awk '{print $4}' | cut -d/ -f1); IP=${IP:-$(hostname -i | awk '{print $1}')}
NET=$(echo "$IP" | cut -d. -f1-3).0/24
cfg() { # name target host mode type
  printf '  <Config Name="%s" Target="%s" Default="%s" Mode="%s" Description="" Type="%s" Display="always" Required="true" Mask="false">%s</Config>\n' "$1" "$2" "$3" "$4" "$5" "$3"
}
tpl() { # name repo network webui icon overview configs...
  local name=$1 repo=$2 net=$3 webui=$4 icon=$5 ov=$6; shift 6
  [[ -f $T/my-$name.xml ]] && return 0
  { echo '<?xml version="1.0"?>'; echo '<Container version="2">'
    echo "  <Name>$name</Name>"; echo "  <Repository>$repo</Repository>"; echo '  <Registry>https://hub.docker.com/</Registry>'
    echo "  <Network>$net</Network>"; echo '  <Privileged>false</Privileged>'; echo "  <Overview>$ov</Overview>"
    echo '  <Category>MediaServer:Video</Category>'; echo "  <WebUI>$webui</WebUI>"; echo "  <Icon>$icon</Icon>"
    if [[ -n ${EXTRA:-} ]]; then echo "  <ExtraParams>$EXTRA</ExtraParams>"; else echo '  <ExtraParams/>'; fi; echo '  <PostArgs/>'; echo "  <DateInstalled>$(date +%s)</DateInstalled>"
    printf '%s' "$@"; echo '</Container>'; } > "$T/my-$name.xml"
}
running() { docker inspect "$1" >/dev/null 2>&1; }
labels() { echo --label net.unraid.docker.managed=dockerman --label "net.unraid.docker.webui=$1" --label "net.unraid.docker.icon=$2"; }

EI=https://raw.githubusercontent.com/MediaBrowser/Emby.Resources/master/images/logoicon.png
JI=https://raw.githubusercontent.com/linuxserver/docker-templates/master/linuxserver.io/img/jellyfin-logo.png
PI=https://raw.githubusercontent.com/plexinc/pms-docker/master/img/plex-server.png

mkdir -p /mnt/user/appdata/emby /mnt/user/appdata/jellyfin/cache /mnt/user/appdata/plex/transcode
chown nobody:users /mnt/user/appdata/emby /mnt/user/appdata/jellyfin /mnt/user/appdata/jellyfin/cache /mnt/user/appdata/plex /mnt/user/appdata/plex/transcode

tpl EmbyServer emby/embyserver:latest bridge 'http://[IP]:[PORT:8096]/' "$EI" 'Emby Server is a personal media server.' \
  "$(cfg WebUI 8096 8096 tcp Port)" "$(cfg Config /config /mnt/user/appdata/emby rw Path)" "$(cfg Media /mnt/media /mnt/user/media ro Path)" \
  "$(cfg UID UID 99 '' Variable)" "$(cfg GID GID 100 '' Variable)"
running EmbyServer || docker run -d --name EmbyServer --restart unless-stopped --net bridge -p 8096:8096 \
  -e UID=99 -e GID=100 -v /mnt/user/appdata/emby:/config:rw -v /mnt/user/media:/mnt/media:ro \
  $(labels 'http://[IP]:[PORT:8096]/' "$EI") emby/embyserver:latest

EXTRA='--user 99:100' tpl Jellyfin jellyfin/jellyfin:latest bridge 'http://[IP]:[PORT:8096]/' "$JI" 'Jellyfin, the free software media system.' \
  "$(cfg WebUI 8096 8097 tcp Port)" "$(cfg Config /config /mnt/user/appdata/jellyfin rw Path)" \
  "$(cfg Cache /cache /mnt/user/appdata/jellyfin/cache rw Path)" "$(cfg Media /data/media /mnt/user/media ro Path)"
running Jellyfin || docker run -d --name Jellyfin --restart unless-stopped --net bridge -p 8097:8096 --user 99:100 \
  -v /mnt/user/appdata/jellyfin:/config:rw -v /mnt/user/appdata/jellyfin/cache:/cache:rw -v /mnt/user/media:/data/media:ro \
  $(labels 'http://[IP]:[PORT:8096]/' "$JI") jellyfin/jellyfin:latest

tpl Plex plexinc/pms-docker:latest host 'http://[IP]:[PORT:32400]/web' "$PI" 'Plex Media Server (unclaimed lab server).' \
  "$(cfg Config /config /mnt/user/appdata/plex rw Path)" "$(cfg Transcode /transcode /mnt/user/appdata/plex/transcode rw Path)" \
  "$(cfg Media /media /mnt/user/media ro Path)" "$(cfg PLEX_UID PLEX_UID 99 '' Variable)" "$(cfg PLEX_GID PLEX_GID 100 '' Variable)" \
  "$(cfg ALLOWED_NETWORKS ALLOWED_NETWORKS "$NET,172.16.0.0/12,127.0.0.1/32" '' Variable)" "$(cfg TZ TZ Europe/Zurich '' Variable)"
running Plex || docker run -d --name Plex --restart unless-stopped --net host \
  -e PLEX_UID=99 -e PLEX_GID=100 -e TZ=Europe/Zurich -e "ALLOWED_NETWORKS=$NET,172.16.0.0/12,127.0.0.1/32" \
  -v /mnt/user/appdata/plex:/config:rw -v /mnt/user/appdata/plex/transcode:/transcode:rw -v /mnt/user/media:/media:ro \
  $(labels 'http://[IP]:[PORT:32400]/web' "$PI") plexinc/pms-docker:latest
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E 'Emby|Jellyfin|Plex'
