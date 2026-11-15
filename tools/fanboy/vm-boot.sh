#!/bin/bash
# tools/fanboy/vm-boot.sh — the boot case of Fanboy's run on test3, driven from the Mac (dev only):
#   vm-run.sh boot-pre (armed, rig down) → reboot test3 → the array started (emcmd, as the scenario tools do) → the tree
#   copied again and dev-synced (a reboot brings back the installed package; /tmp is gone) → vm-run.sh boot-post (the rig
#   up 3 minutes after the office's start, he must take over when the chip comes).
# The evidence lands in /tmp/uso-fanboy-vmrun/boot-*/ on test3 (boot-pre's copied to the Mac before the reboot).
#   bash tools/fanboy/vm-boot.sh <evidence folder on the Mac>   (from the worktree)
set -uo pipefail
EV=${1:?evidence folder}
H=uso-test3
T=/tmp/uso-fanboy-vmrig
cd "$(dirname "$0")/../.." || exit 1
[[ $(ssh $H cat /sys/class/dmi/id/product_uuid) == 89b3d774-ddbb-4fbd-84b1-49b1f8647350 ]] || { echo "not test3"; exit 1; }
ssh $H "bash $T/tools/fanboy/vm-run.sh boot-pre" || exit 1
mkdir -p "$EV"
rsync -a $H:/tmp/uso-fanboy-vmrun/boot-pre "$EV/"
echo "$(date +%T) rebooting test3"
ssh $H 'cd / && (sleep 1; /sbin/reboot) </dev/null >/dev/null 2>&1 &' || true
sleep 30
until ssh -o ConnectTimeout=5 $H true 2>/dev/null; do sleep 5; done
echo "$(date +%T) test3 answers; waiting for emhttp"
until ssh $H 'test -s /var/local/emhttp/var.ini && pgrep -x emhttpd >/dev/null'; do sleep 3; done
sleep 10
ssh $H '/usr/local/sbin/emcmd "cmdStart=Start&startState=STOPPED&file=" >/dev/null 2>&1; for i in $(seq 150); do grep -q "^fsState=\"Started\"" /var/local/emhttp/var.ini && break; sleep 2; done; grep -E "^(mdState|fsState)=" /var/local/emhttp/var.ini'
echo "$(date +%T) array started; copying and dev-syncing"
rsync -a --delete --exclude .git ./ $H:$T/
ssh $H "bash $T/plugin/dev-sync.sh && mkdir -p /tmp/uso-fanboy-vmrun && date +%s > /tmp/uso-fanboy-vmrun/boot-synced"
ssh $H "bash $T/tools/fanboy/vm-run.sh boot-post"
rsync -a $H:/tmp/uso-fanboy-vmrun/boot-post "$EV/"
echo "$(date +%T) done"
