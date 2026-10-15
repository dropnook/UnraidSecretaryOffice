#!/bin/bash
# Lab only (task/mediacache-lab, never in main): writes short real test videos with invented private titles
# straight onto the array disks (/mnt/diskN/media), so nothing starts on a pool. Idempotent: existing files are kept.
# Usage on the server: bash make-media.sh   (needs docker image jrottenberg/ffmpeg:7.1-alpine)
set -euo pipefail
IMG=jrottenberg/ffmpeg:7.1-alpine
for d in 1 2; do [[ -d /mnt/disk$d/media ]] || { echo "missing /mnt/disk$d/media"; exit 1; }; done

ff() { docker run --rm --user 99:100 -v /mnt/disk1/media:/d1 -v /mnt/disk2/media:/d2 -v /tmp/lab-seg:/seg "$IMG" -hide_banner -loglevel error "$@"; }
mkdir -p /tmp/lab-seg && chmod 777 /tmp/lab-seg

# small: disk file seconds size(WxH) hue
small() {
  local disk=$1 rel=$2 sec=$3 size=$4 freq=$5
  local host="/mnt/disk$disk/media/$rel"
  [[ -s $host ]] && return 0
  mkdir -p "$(dirname "$host")"; chown nobody:users "$(dirname "$host")" "$(dirname "$(dirname "$host")")" 2>/dev/null || true
  chmod 777 "$(dirname "$host")"
  ff -f lavfi -i "testsrc2=size=$size:rate=25:duration=$sec" -f lavfi -i "sine=frequency=$freq:duration=$sec" \
     -c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -b:a 96k -shortest "/d$disk/$rel"
  echo "made $host ($(du -h "$host" | cut -f1))"
}
# big: a 20 s high-bitrate segment looped by stream copy until about MB megabytes (still a valid, playable mkv)
big() {
  local disk=$1 rel=$2 mb=$3
  local host="/mnt/disk$disk/media/$rel"
  [[ -s $host ]] && return 0
  mkdir -p "$(dirname "$host")"; chmod 777 "$(dirname "$host")"
  if [[ ! -s /tmp/lab-seg/seg.mkv ]]; then
    ff -f lavfi -i "testsrc2=size=1920x1080:rate=25:duration=20,noise=alls=40:allf=t" -f lavfi -i "sine=frequency=330:duration=20" \
       -c:v libx264 -preset ultrafast -b:v 40M -maxrate 40M -bufsize 80M -pix_fmt yuv420p -c:a aac -b:a 128k -shortest /seg/seg.mkv
  fi
  local segmb=$(( $(stat -c %s /tmp/lab-seg/seg.mkv) / 1048576 )); local loops=$(( mb / segmb ))
  ff -stream_loop "$((loops - 1))" -i /seg/seg.mkv -c copy "/d$disk/$rel"
  echo "made $host ($(du -h "$host" | cut -f1))"
}

F=Familienfilme; S=Familienserien
big   1 "$F/Ferien am See (2019)/Ferien am See (2019).mkv" 1500
small 1 "$F/Omas 80. Geburtstag (2018)/Omas 80. Geburtstag (2018).mkv" 30 1280x720 440
big   1 "$F/Skiurlaub in den Bergen (2020)/Skiurlaub in den Bergen (2020).mkv" 2500
small 1 "$F/Der erste Schultag (2017)/Der erste Schultag (2017).mkv" 25 1280x720 550
big   2 "$F/Weihnachten zu Hause (2022)/Weihnachten zu Hause (2022).mkv" 1000
small 2 "$F/Hochzeit im Garten (2021)/Hochzeit im Garten (2021).mkv" 30 1280x720 660
small 2 "$F/Velotour am Rhein (2023)/Velotour am Rhein (2023).mkv" 20 1280x720 770
small 2 "$F/Sommerfest im Hof (2024)/Sommerfest im Hof (2024).mkv" 15 1280x720 880

ep() { # disk series season count
  local disk=$1 series=$2 season=$3 n=$4 e
  for ((e = 1; e <= n; e++)); do
    small "$disk" "$S/$series/Season $(printf %02d "$season")/$series - S$(printf %02d "$season")E$(printf %02d "$e").mkv" $((10 + e * 3)) 640x360 $((300 + season * 100 + e * 20))
  done
}
ep 1 "Gartenprojekt (2020)" 1 5
ep 1 "Gartenprojekt (2020)" 2 4
ep 2 "Unser Hausbau (2021)" 1 6
ep 1 "Kinderzimmer-Konzerte (2022)" 1 4
ep 2 "Kinderzimmer-Konzerte (2022)" 2 4
chown -R nobody:users /mnt/disk1/media /mnt/disk2/media
rm -rf /tmp/lab-seg
echo done
