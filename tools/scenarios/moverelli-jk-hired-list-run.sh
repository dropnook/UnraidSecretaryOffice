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
# media files the joint run's fetch brings onto the pool: back to the disk they were on (the inventory before), and the
# folders that emptied on the pool with it (only those not there before)
media_back() { local rel b f d; [[ -f $KEEP/before/inventory/media.tsv ]] || return 0
    while IFS= read -r f; do rel=${f#/mnt/cache/media/}
        grep -qxF -- "$f" "$KEEP/media-cache-before.txt" && continue
        b=$(awk -F'\t' -v r="$rel" '$1 == r && $5 != "cache" {print $5; exit}' "$KEEP/before/inventory/media.tsv")
        [[ -n $b ]] && mkdir -p "/mnt/$b/media/$(dirname "$rel")" && mv -- "$f" "/mnt/$b/media/$rel" && say "undo: $rel back to $b"
    done < <(find /mnt/cache/media -type f ! -path '*uso-wip-f-m7*' 2>/dev/null)
    local i; for i in 1 2 3 4 5; do       # (a parent empties only once its children are gone: again, deepest first)
        find /mnt/cache/media -mindepth 1 -depth -type d -empty 2>/dev/null | while IFS= read -r d; do
            grep -qxF -- "$d" "$KEEP/media-dirs-before.txt" || rmdir -- "$d" 2>/dev/null; done; done; }
undo_push media_back
track_share media
fresh_beat
find /mnt/cache/media -type f 2>/dev/null | sort >"$KEEP/media-cache-before.txt"
find /mnt/cache/media -type d 2>/dev/null | sort >"$KEEP/media-dirs-before.txt"
where() { local f; for f in "${listed[@]}"; do printf '%s → %s\n' "${f#/mnt/cache/media/}" "$( [[ -f $f ]] && echo pool || where_is "/mnt/user/media/${f#/mnt/cache/media/}")"; done | paste -sd';' -; }
snap_before
stay() { local f n=0; for f in "${listed[@]}"; do [[ -f $f ]] && n=$((n + 1)); done; echo "$n"; }
ours() { find /mnt/disk*/media/uso-wip-f-m7 -type f 2>/dev/null | wc -l; }
cp -p "$list" "$KEEP/list-before-run.txt" 2>/dev/null; evidence "$KEEP/list-before-run.txt" >/dev/null
say "his list before her run: $(grep -c . "$list" 2>/dev/null) lines$(diff -q <(sort "$list") <(printf '%s\n' "${listed[@]}" | sort) >/dev/null 2>&1 || echo " — not the state's any more: $(diff <(printf '%s\n' "${listed[@]}" | sort) <(sort "$list") | grep '^[<>]' | head -4 | tr '\n' ' ')")"
say "his films before her run: $(where)"
flow MV-RUN
say "his films after her run: $(where)"
cp -p "$list" "$KEEP/list-after-run.txt" 2>/dev/null; evidence "$KEEP/list-after-run.txt" >/dev/null
grep -h 'excludes:\|\[media\]' "$D/moverelli/smart_mover.log" 2>/dev/null | tail -3 | while IFS= read -r l; do say "her log: ${l:0:160}"; done
s=$(stay); o=$(ours)
((s == ${#listed[@]})) && cell run-list-stays 1 "after «Run now» all $s of his films still on the pool" || cell run-list-stays 0 "after «Run now» $s of ${#listed[@]} of his films on the pool"
((o == 2)) && cell run-moved-ours 1 "our 2 files (not on his list) went to the array" || cell run-moved-ours 0 "of our 2 files $o went to the array"
flow MV-RUN-SCHED
say "his films after the joint run: $(where)"
s=$(stay)
((s == ${#listed[@]})) && cell joint-list-stays 1 "after the joint run all $s of his films still on the pool (nothing was watched)" \
    || cell joint-list-stays 0 "after the joint run $s of ${#listed[@]} of his films on the pool — nothing was watched"
allow INV-COUNTS "the joint run's fetch is Jack Streamer's (EmbyCache: nightrun.php's fill step moves his films onto the pool), not hers — her count is her own part"
check standard
scenario_end
