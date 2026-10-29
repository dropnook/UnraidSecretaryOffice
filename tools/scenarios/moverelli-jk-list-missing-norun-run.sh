#!/bin/bash
# Wave-1 finding «Jack Streamer's list missing» (parking lot «Resilienz-Funde», H4 — the half moverelli-jk-list-empty-h-run
# doesn't cover): Jack Streamer hired and set up, but he never had a real run (no mediacache_origin.json) and his list
# isn't there (taken away right after his setup, as a restore of appdata or a hand would). Three 40-day-old files lie in
# media on the pool (her age rule for media: 30 days) — whether they are his nobody can tell without his list. What must
# hold (task/jack-helmi's fix, brief «4 a»):
#   list-not-made-empty  his look never writes an EMPTY list where there was none — the list stays missing (or holds what
#                        he really keeps), never «nothing to keep» made up
#   pending              while he has no list of his own, her run treats media as «pending» (moverelli_jack_list_pending,
#                        the refusal she knows from JK-LIST-PENDING) — not as «nothing of his to keep»
#   files-stay           the three files are still on the pool after her run
# His Emby can't be asked: his settings carry a key Emby refuses (an Emby being updated, a key revoked — so his own part
# can't plan and decides nothing here, on Tower too where the scenarios' real key would answer). RED until
# task/jack-helmi lands (on 1.77.0 his look writes the list empty «until his first real run» and her run moves the files).
# Ms. Moverelli set up for the scenario, put back after. Pre-release candidate once green (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-jk-list-missing-norun-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# his settings with a key Emby refuses (not a secret: an invented one) — h_jack_hire_standin reads the key through _st_env
eval "$(declare -f _st_env | sed '1s/^_st_env/_st_env_real/')"
_st_env() { [[ $1 == EMBY_API_KEY ]] && { printf '%s' 0000usowip0000refused0000key0000; return 0; }; _st_env_real "$@"; }
F=uso-wip-j1-files
h_mv_set_up J1 rule=media:30 || _row state MV-SETUP red "her setup failed"
put_files J1 cache "media/$F" 3 --age 40d || _row state put_files red "the files could not be written"
undo_push files_unput J1
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-j1-files; exit 0'
h_fresh_beat moverelli
files=$(find "/mnt/cache/media/$F" -type f | sort)
h_jack_hire_standin J1; rc=$?; undo_push jack_unhire J1
((rc == 2)) && { echo "needs Ms. Moverelli set up and scenarios.env" >"$KEEP/.skip"; scenario_end; }
((rc == 0)) || { _row state JK-HIRED red "h_jack_hire_standin failed"; scenario_end; }
l=$(_st_jack_list) o=$D/mediacache/mediacache_origin.json
# never ran: no trace of a real run of his (his origin file aside if the hire left one), his list taken away
if [[ -f $o ]]; then mkdir -p "$KEEP_STASH/J1-ORIGIN" && mv -- "$o" "$KEEP_STASH/J1-ORIGIN/" && undo_push bash -c '[[ -f $1 ]] && mv -f -- "$1" "$2"; exit 0' _ "$KEEP_STASH/J1-ORIGIN/mediacache_origin.json" "$o"; fi
jack_list_remove J1 && undo_push jack_list_back J1 || { _row state list red "his list could not be taken away"; scenario_end; }
track_share media
snap_before
agent_log_mark look
ask jack.refresh '{}' 120 >/dev/null
if [[ -f $l ]]; then
    c=$(grep -c . "$l"); evidence "$l" list-after-look.txt >/dev/null
    ((c > 0)) && cell list-not-made-empty 1 "his look wrote a list of $c lines (what he keeps)" \
        || cell list-not-made-empty 0 "his look wrote the list anew EMPTY (he never ran): «$(agent_log_since look | grep -m1 -i 'list' | cut -c1-200)»"
else
    cell list-not-made-empty 1 "his look left the list missing"
fi
agent_log_mark run
flow MV-RUN refuse=ok
said=$( { ask_last '.error.key // empty'; jq -r '[.shares[]? | select(.share == "media") | (.state // ""), (.reason // "")] | join(" ")' "$D/moverelli/status.json" 2>/dev/null; agent_log_since run | grep -o 'moverelli_jack_list_pending\|list.*pending\|pending.*list' | head -1; } | paste -sd' ' -)
say "her run and media: $said"
[[ $said == *pending* ]] && cell pending 1 "media pending while he has no list: $said" || cell pending 0 "nothing says media waits for his list: ${said:-no word}"
stay=0; while IFS= read -r f; do [[ -f $f ]] && stay=$((stay + 1)); done <<<"$files"
((stay == 3)) && cell files-stay 1 "all 3 files still on the pool" || cell files-stay 0 "$((3 - stay)) of 3 files moved to the array by her run"
allow INV-COUNTS "a refused or partial run for media is the cell's end — her count is judged by the cells"
check standard
scenario_end
