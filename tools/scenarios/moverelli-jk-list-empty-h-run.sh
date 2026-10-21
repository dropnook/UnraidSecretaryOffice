#!/bin/bash
# Batch H, finding 1 (parking lot «Resilienz-Funde», marked «wichtig»): Jack Streamer set up, his films on the pool and on
# his list; the list goes (a restore of appdata, a hand) — his next look writes it anew EMPTY (agent/desks/jack.php
# jackListEnsure(): «empty until his first real run», though he had one) and Ms. Moverelli's next run takes an empty
# list for «nothing to keep»: his films go back to the array. Expected: a missing list is never taken for «nothing to
# keep» — the films stay on the pool (cell films-stay), and his look never writes an empty list over one that was
# there (cell list-not-emptied). Emby can't be asked in the run (USOTest3VM's Emby has no key for him: the stand-in
# key, h_jack_hire_standin — as an Emby being updated at night): his own part can't plan then and keeps «the list as it
# was» (mediacache_run.py: «bisherige Exclude-Liste bleibt geschützt») — which is the empty one. The films are three
# 40-day-old files in media (written old on the pool itself, born old too), her age rule for media 30 days: without his
# list she moves them, with it she must not. Ms. Moverelli set up for the scenario (her page's save), put back after.
# Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-list-empty-h-run
needs docker
F=uso-wip-h-films
h_mv_set_up H1 rule=media:30 || _row state MV-SETUP red "her setup failed"
put_files H1 cache "media/$F" 3 --age 40d || _row state put_files red "the films could not be written"
put_files H1 disk2 media/uso-wip-h-fill 1 --size 4096 || _row state put_files red "the file for his fill could not be written"
undo_push files_unput H1
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-h-films /mnt/*/media/uso-wip-h-fill; exit 0'   # wherever her run put them
h_fresh_beat moverelli
films=$(find "/mnt/cache/media/$F" -type f | sort)
ok=1
h_jack_hire_standin H1; rc=$?; undo_push jack_unhire H1
((rc == 2)) && { touch "$KEEP/.skip"; echo "needs Jack Streamer's stand-in key" >"$KEEP/.skip"; scenario_end; }
((rc == 0)) || { _row state JK-HIRED-LIST red "h_jack_hire_standin failed"; ok=0; }
l=$(_st_jack_list)
n=$(grep -c "/$F/" "$l" 2>/dev/null)
say "his list: $(grep -c . "$l" 2>/dev/null) lines, $n of them our films"
((n == 3)) || { _row state JK-HIRED-LIST red "our three films not on his list ($n)"; ok=0; }
evidence "$l" list-before.txt >/dev/null
jack_list_remove H1 && undo_push jack_list_back H1 || { _row state JK-LIST-GONE red "his list could not be taken away"; ok=0; }
((ok)) || { say "the state isn't there: no flow, no cells"; scenario_end; }
track_share media
snap_before
agent_log_mark look
ask jack.refresh '{}' 120 >/dev/null
if [[ -f $l ]]; then
    evidence "$l" list-after-look.txt >/dev/null
    c=$(grep -c . "$l")
    w=$(agent_log_since look | grep -m1 'his list written' | cut -c1-200)
    ((c > 0)) && h_cell list-not-emptied 1 "his look wrote the list anew with $c lines" \
        || h_cell list-not-emptied 0 "his look wrote the list anew EMPTY (after a real run of his, films on the pool): «${w:-no log line}»"
else
    h_cell list-not-emptied 1 "his look left the list missing (her run must then refuse media)"
fi
flow MV-RUN
evidence "$D/moverelli/night.json" night.json >/dev/null
say "the joint run's steps: $(jq -c '[.steps[]? | {who, phase, result}] + [{jack_out: (.jack_out // null), result: (.result // null)}]' "$D/moverelli/night.json" 2>/dev/null)"
say "media in her status: $(jq -c '[.shares[]? | select(.share == "media") | {state, moved, left}]' "$D/moverelli/status.json" 2>/dev/null)"
stay=0 gone=()
while IFS= read -r f; do [[ -f $f ]] && stay=$((stay + 1)) || gone+=("$(ls /mnt/disk*/media/"$F"/"$(basename "$f")" 2>/dev/null | head -1)"); done <<<"$films"
((stay == 3)) && h_cell films-stay 1 "all 3 films of his list still on the pool" \
    || h_cell films-stay 0 "$((3 - stay)) of his 3 films given back to the array by her run: ${gone[*]}"
cp -p "$D/mediacache/logs/mediacache.log" "$KEEP/mediacache.log" 2>/dev/null && evidence "$KEEP/mediacache.log" >/dev/null
allow INV-COUNTS "the joint run's count includes Jack Streamer's part (nightrun.php), not hers alone (as M19)"
check standard
scenario_end
