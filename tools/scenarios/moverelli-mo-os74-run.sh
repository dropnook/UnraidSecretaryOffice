#!/bin/bash
# Cell M25 of matrix.md: Unraid 7.4 (OS-74 — Tower runs 7.4.0-rc.1) with its new mover script and its progress file. Her
# real run hands the files to Unraid's move binary /usr/libexec/unraid/move (her log says so), never to Unraid's mover
# script (/usr/local/sbin/mover is not started: no mover.pid, no «mover:» syslog line of Unraid's own run), every file
# moved once; Unraid's mover progress file stays as Unraid left it. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-os74-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
state OS-74
put_files M25 cache uso-yes/uso-wip-f-m25 10 --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M25
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-f-m25'
track_share uso-yes
fresh_beat
prog=$(ls /var/local/emhttp/mover*.ini /var/run/mover* /tmp/mover* 2>/dev/null | head -3 | xargs -r md5sum 2>/dev/null | md5sum)
lm=$(wc -l </var/log/syslog); l0=$(wc -l <"$D/moverelli/smart_mover.log" 2>/dev/null || echo 0)
snap_before
flow MV-RUN
tail -n +"$((l0 + 1))" "$D/moverelli/smart_mover.log" >"$KEEP/smart_mover-run.log" 2>/dev/null; evidence "$KEEP/smart_mover-run.log" >/dev/null
grep -q 'via /usr/libexec/unraid/move' "$KEEP/smart_mover-run.log" && cell move-binary 1 "her run: «via /usr/libexec/unraid/move»" || cell move-binary 0 "her log names no /usr/libexec/unraid/move: $(grep -m1 'moving' "$KEEP/smart_mover-run.log")"
own=$(tail -n +"$lm" /var/log/syslog | grep -E 'root: mover: (started|finished)|/usr/local/sbin/mover start' | head -2)
[[ -z $own ]] && cell no-unraid-mover 1 "Unraid's mover script not started" || cell no-unraid-mover 0 "Unraid's mover ran: ${own//$'\n'/ | }"
n=$(find /mnt/disk*/uso-yes/uso-wip-f-m25 -type f 2>/dev/null | wc -l)
((n == 10)) && cell moved 1 "all 10 files on the array" || cell moved 0 "$n of 10 files on the array"
[[ $(ls /var/local/emhttp/mover*.ini /var/run/mover* /tmp/mover* 2>/dev/null | head -3 | xargs -r md5sum 2>/dev/null | md5sum) == "$prog" ]] \
    && cell progress-file 1 "Unraid's mover progress file(s) as Unraid left them" || cell progress-file 0 "Unraid's mover progress file changed during her run"
check standard
scenario_end
