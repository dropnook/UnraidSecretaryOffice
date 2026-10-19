#!/bin/bash
# Cell M7 of matrix.md: Jack Streamer hired with his list (JK-HIRED-LIST: set up on Tower's Emby, three films of the
# media share on the pool and on his list, her ini naming it for media). Her «Run now» (MV-RUN) and then the joint run
# from her cron line (MV-RUN-SCHED, Jack Streamer's give-back and fetch in it) leave his films on the pool — really,
# file for file; two 40-day-old files of ours in media beside them (not on his list) do go to the array, so the run did
# move media. What the joint run's fetch brings onto the pool goes back to its disk in the undo. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-hired-list-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# put_files --age writes with the clock set back (states' clock_back): an agent napping across that step back sleeps
# until a request rings its doorbell (batch D F12); her page's look rings it, then a fresh heartbeat before snap_before
fresh_beat() { local t; t=$(_sc_now); ask moverelli.refresh '{}' 60 >/dev/null
    wait_for 45 "a heartbeat after the files were written" bash -c "(( \$(stat -c %Y $RUN/agent.json) >= $t ))"; }
state JK-HIRED-LIST
list=$(_st_jack_list)
mapfile -t listed < <(grep '^/mnt/cache/media/' "$list" 2>/dev/null)
say "his list: ${#listed[@]} films on the pool"
((${#listed[@]} > 0)) || _row state list red "his list names no film on the pool"
put_files M7 cache media/uso-wip-f-m7 2 --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput M7
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-f-m7'
# media files the joint run's fetch brings onto the pool: back to the disk they were on (the inventory before)
media_back() { local rel b f; [[ -f $KEEP/before/inventory/media.tsv ]] || return 0
    while IFS= read -r f; do rel=${f#/mnt/cache/media/}
        grep -qxF -- "$f" "$KEEP/media-cache-before.txt" && continue
        b=$(awk -F'\t' -v r="$rel" '$1 == r && $5 != "cache" {print $5; exit}' "$KEEP/before/inventory/media.tsv")
        [[ -n $b ]] && mkdir -p "/mnt/$b/media/$(dirname "$rel")" && mv -- "$f" "/mnt/$b/media/$rel" && say "undo: $rel back to $b"
    done < <(find /mnt/cache/media -type f ! -path '*uso-wip-f-m7*' 2>/dev/null); }
undo_push media_back
track_share media
fresh_beat
find /mnt/cache/media -type f 2>/dev/null | sort >"$KEEP/media-cache-before.txt"
snap_before
stay() { local f n=0; for f in "${listed[@]}"; do [[ -f $f ]] && n=$((n + 1)); done; echo "$n"; }
ours() { find /mnt/disk*/media/uso-wip-f-m7 -type f 2>/dev/null | wc -l; }
flow MV-RUN
s=$(stay); o=$(ours)
((s == ${#listed[@]})) && cell run-list-stays 1 "after «Run now» all $s of his films still on the pool" || cell run-list-stays 0 "after «Run now» $s of ${#listed[@]} of his films on the pool"
((o == 2)) && cell run-moved-ours 1 "our 2 files (not on his list) went to the array" || cell run-moved-ours 0 "of our 2 files $o went to the array"
flow MV-RUN-SCHED
s=$(stay)
((s == ${#listed[@]})) && cell joint-list-stays 1 "after the joint run all $s of his films still on the pool (nothing was watched)" \
    || cell joint-list-stays 0 "after the joint run $s of ${#listed[@]} of his films on the pool — nothing was watched"
check standard
scenario_end
