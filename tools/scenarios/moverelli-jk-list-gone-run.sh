#!/bin/bash
# Cell M19 of matrix.md, the list gone: Jack Streamer set up and has run, his list gone (JK-LIST-GONE — his next look
# writes an empty one again). Her «Run now» and her cron line's joint run must never move his films off the pool for
# want of the list: the share's part ends with Smart Mover's ERROR (EXCLUDES_REQUIRED), the other shares run. Two
# 40-day-old files of ours in uso-yes show the others ran. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-list-gone-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# put_files --age writes with the clock set back (states' clock_back): an agent napping across that step back sleeps
# until a request rings its doorbell (batch D F12); her page's look rings it, then a fresh heartbeat before snap_before
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
state JK-LIST-GONE
films=$(grep '^/mnt/cache/media/' "$(_st_dir JK-LIST-GONE)/mediacache_exclude.txt" 2>/dev/null)
nf=$(grep -c . <<<"$films")
put_files M19 cache uso-yes/uso-wip-f-m19 2 --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M19
undo_push bash -c 'rm -rf /mnt/*/uso-yes/uso-wip-f-m19'
track_share media uso-yes
fresh_beat
snap_before
films_stay() { local f n=0; while IFS= read -r f; do [[ -n $f && -f $f ]] && n=$((n + 1)); done <<<"$films"; echo "$n"; }
l=$(_st_jack_list); say "his list: $( [[ -f $l ]] && echo "there again ($(grep -c . "$l") lines)" || echo gone)"
flow MV-RUN
s=$(films_stay)
((s == nf)) && cell run-films-stay 1 "after «Run now» all $nf of his films still on the pool" || cell run-films-stay 0 "after «Run now» $s of $nf of his films on the pool — moved for want of his list"
m=$(jq -c '[.shares[]? | select(.share == "media") | {state, moved, left}]' "$D/moverelli/status.json" 2>/dev/null)
say "media in her status: $m"
o=$(find /mnt/disk*/uso-yes/uso-wip-f-m19 -type f 2>/dev/null | wc -l)
((o == 2)) && cell others-ran 1 "uso-yes ran: our 2 files on the array" || cell others-ran 0 "uso-yes: $o of our 2 files on the array"
flow MV-RUN-SCHED
s=$(films_stay)
((s == nf)) && cell joint-films-stay 1 "after the joint run all $nf of his films still on the pool" || cell joint-films-stay 0 "after the joint run $s of $nf of his films on the pool"
check standard
scenario_end
