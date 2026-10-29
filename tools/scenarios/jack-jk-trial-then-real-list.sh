#!/bin/bash
# Wave-1 finding «MediaCache's trial run never writes Jack Streamer's list» (parking lot «Resilienz-Funde», H4 — the half
# jack-jk-hired-list-h-dry doesn't cover): by design a trial (report mode, «Try it out») changes nothing, so the list comes
# from a real run — this cell checks that the real run after a trial really writes it (task/jack-helmi's brief «4 b»).
# Jack Streamer hired on Tower's Emby (JK-HIRED-LIST), then «never ran»: his list and his origin file taken away.
#   trial-ends         his trial (jack.start_run report) ends and says what it would do
#   trial-list-same    the trial leaves his list as his look left it (missing, or what the look wrote) — a trial writes nothing
#   real-writes-list   his real run after it (jack.start_run run: the joint run with Ms. Moverelli) writes his list: there,
#                      newer than the run's start, every line a file that lies on the pool
# What the real run's fetch brings onto the pool goes back to its disk in the undo (as M7). Nightly (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin jack-jk-trial-then-real-list
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state JK-HIRED-LIST
l=$(_st_jack_list) o=$D/mediacache/mediacache_origin.json st=$D/mediacache/status.json
if [[ -f $o ]]; then mkdir -p "$KEEP_STASH/J2-ORIGIN" && mv -- "$o" "$KEEP_STASH/J2-ORIGIN/" && undo_push bash -c '[[ -f $1 ]] && mv -f -- "$1" "$2"; exit 0' _ "$KEEP_STASH/J2-ORIGIN/mediacache_origin.json" "$o"; fi
jack_list_remove JK-HIRED-LIST && undo_push jack_list_back JK-HIRED-LIST || { _row state list red "his list could not be taken away"; scenario_end; }
# the real run's fetch: back to the disk each file was on (the inventory before), folders it emptied on the pool away
media_back() { local rel b f d i; [[ -f $KEEP/before/inventory/media.tsv ]] || return 0
    while IFS= read -r f; do rel=${f#/mnt/cache/media/}
        grep -qxF -- "$f" "$KEEP/media-cache-before.txt" && continue
        b=$(awk -F'\t' -v r="$rel" '$1 == r && $5 != "cache" {print $5; exit}' "$KEEP/before/inventory/media.tsv")
        [[ -n $b ]] && mkdir -p "/mnt/$b/media/$(dirname "$rel")" && mv -- "$f" "/mnt/$b/media/$rel" && say "undo: $rel back to $b"
    done < <(find /mnt/cache/media -type f 2>/dev/null)
    for i in 1 2 3 4 5; do find /mnt/cache/media -mindepth 1 -depth -type d -empty 2>/dev/null | while IFS= read -r d; do
        grep -qxF -- "$d" "$KEEP/media-dirs-before.txt" || rmdir -- "$d" 2>/dev/null; done; done; }
undo_push media_back
track_share media
ask jack.refresh '{}' 120 >/dev/null                 # his page open: his look first
lsum() { [[ -f $l ]] && printf '%s %s' "$(md5sum <"$l" | cut -c1-12)" "$(grep -c . "$l")" || printf missing; }
find /mnt/cache/media -type f 2>/dev/null | sort >"$KEEP/media-cache-before.txt"
find /mnt/cache/media -type d 2>/dev/null | sort >"$KEEP/media-dirs-before.txt"
snap_before
before=$(lsum)
say "his list after his look (never ran): $before"
t0=$(_sc_now)
ask jack.start_run '{"mode":"report"}' >/dev/null && expect_ok jack.start_run
if wait_for 600 "his trial to end" bash -c "! pgrep -f '[m]ediacache_run[.]py' >/dev/null && (( \$(stat -c %Y '$st' 2>/dev/null || echo 0) >= $t0 ))"; then
    cell trial-ends 1 "his trial ended: $(jq -c '{mode: (.mode // null), result: (.result // null)}' "$st" 2>/dev/null)"
else
    cell trial-ends 0 "his trial didn't end within 10 min"
fi
evidence "$st" status-trial.json >/dev/null
after=$(lsum)
[[ $after == "$before" ]] && cell trial-list-same 1 "the trial left his list as it was ($after)" || cell trial-list-same 0 "the trial changed his list: $before → $after"
minute_wait
t1=$(_sc_now)
ask jack.start_run '{"mode":"run"}' >/dev/null && expect_ok jack.start_run
wait_night 3600
evidence "$D/moverelli/night.json" night.json >/dev/null
if [[ -f $l ]] && (( $(stat -c %Y "$l") >= t1 )); then
    off=$(while IFS= read -r p; do [[ -z $p || -f $p || -f ${p/#\/mnt\/user\//\/mnt\/cache\/} ]] || echo "$p"; done <"$l" | head -3 | paste -sd' ' -)
    [[ -z $off ]] && cell real-writes-list 1 "his real run wrote his list: $(grep -c . "$l") lines, $(stat -c %y "$l" | cut -c1-19)" \
        || cell real-writes-list 0 "his list names files not on the pool: $off"
    evidence "$l" list-after-run.txt >/dev/null
else
    cell real-writes-list 0 "after his real run his list is $( [[ -f $l ]] && echo "older than the run ($(stat -c %y "$l" | cut -c1-19))" || echo missing) — night: $(jq -c '[.steps[]? | {who, phase, result}]' "$D/moverelli/night.json" 2>/dev/null | head -c 300)"
fi
cp -p "$D/mediacache/logs/mediacache.log" "$KEEP/mediacache.log" 2>/dev/null && evidence "$KEEP/mediacache.log" >/dev/null
allow INV-COUNTS "the joint run's fetch is Jack Streamer's, not hers — her count is her own part (as M7)"
check standard
scenario_end
