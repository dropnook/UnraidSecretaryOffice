#!/bin/bash
# Jack Streamer's «theme.mp3 stays on the pool» (2026-10-29, the owner: «muss wirklich gut durchgetestet sein»), for real
# on USOTest3VM: a throwaway share uso-wip-jt-media («Cache: yes» on cache) with three home films and one home series,
# each with its theme.mp3, all on the array disks; one film on deck. His media server is a stand-in on 127.0.0.1 (its
# users, the film on deck, its library — Emby's answers, no real Emby asked), his settings saved as his page saves them
# with the switch on; Ms. Moverelli set up for the share with age 0 (she would move everything of it) and his list in her
# ini. Cells:
#   whats-next-says     «What's next» (report) says beforehand how many folders the first collection reads; nothing moves
#   first-collect       his real run (the joint run): the four themes on the pool, none left on the array; status: the
#                       first collection, 4 looked at, 4 fetched, from the disks they lay on
#   film-and-theme      the film on deck on the pool (his fill), its theme.mp3 not among the film's files
#   list-section        his list: the themes in their own section (exactly the four), the film's files in the other
#   moverelli-leaves    her part of the next joint run (age 0: it would move everything) moves the share's control file
#                       to the array and leaves every theme on the pool (his fill comes after her part: the first run
#                       brought them only then)
#   page-says           his page's state: «4 themes on the pool» (his list's section), MediaCache's state the same
#   second-new-only     a new film with its theme in the library: the next real run looks only at it and fetches it
#   letgo-back          his let-go with «bring the films back»: every theme and the film back on the array, the section gone
# Everything made here goes at the end (the share, its files, the stand-in, his hire — the let-go's «Let go» itself, the
# Team Lead's fire, is the undo's —, her settings as they were).
# Not Tower (the release runs there): needs test3. ~10 min.
. "$(dirname "$0")/lib.sh"
scenario_begin jack-jk-themes-collect-run
needs test3 docker
command -v python3 >/dev/null || { echo "needs python3" >"$KEEP/.skip"; _row state python3 red "no python3 on this server"; scenario_end; }
S=uso-wip-jt-media
FM=Familienfilme SR=Familienserien
PORT=18097
KEY=uso0standin0key0jt
LIB=$KEEP/standin-library.json
ok=1
cell() { h_cell "$@"; }
has jack && { _row state JACK red "Jack Streamer is hired already (the base has him not)"; scenario_end; }

# --------------------------------------------------------------------------------------------------- the share and files
jt_drop() {
    local r
    for r in /mnt/*/"$S"; do [[ -d $r && ! -L $r && $r != /mnt/user*/* ]] && find "$r" -mindepth 1 -delete; done
    share_exists "$S" && { share_remove "$S" || { sleep 3; share_remove "$S"; }; }
    rm -f "/boot/config/shares/$S.cfg"
    ! ls -d /mnt/*/"$S" >/dev/null 2>&1 || { say "jt_drop: left: $(ls -d /mnt/*/"$S" | tr '\n' ' ')"; return 1; }
}
undo_push jt_drop
share_make "$S" yes cache || { _row state share_make red "Unraid's «Add Share» made no $S"; scenario_end; }
# put <disk> <rel> <KiB>: a file of the share on that disk (Unraid's owner and mode)
put() {
    local f=/mnt/$1/$S/$2
    mkdir -p "$(dirname "$f")" && head -c "$(($3 * 1024))" /dev/urandom >"$f" && chown -R nobody:users "/mnt/$1/$S" && chmod 666 "$f"
}
F1="Sommerfest 2019" F2="Opas Geburtstag" F3="Zeltlager 2021" SE=Kinderzimmertheater FN="Herbstwanderung 2026"
put disk1 "$FM/$F1/$F1.mkv" 2048 && put disk1 "$FM/$F1/theme.mp3" 96 \
    && put disk2 "$FM/$F2/$F2.mkv" 1024 && put disk2 "$FM/$F2/theme.mp3" 64 \
    && put disk1 "$FM/$F3/$F3.mkv" 1024 && put disk1 "$FM/$F3/theme.mp3" 80 \
    && put disk2 "$SR/$SE/Season 01/$SE S01E01.mkv" 1024 && put disk2 "$SR/$SE/theme.mp3" 112 \
    || { _row state files red "the share's files could not be written"; scenario_end; }
THEMES=("$FM/$F1/theme.mp3" "$FM/$F2/theme.mp3" "$FM/$F3/theme.mp3" "$SR/$SE/theme.mp3")
# the share's root on the pool there (empty) from the start: Unraid and her run leave it when everything moved off
mkdir -p "/mnt/cache/$S" && chown nobody:users "/mnt/cache/$S" || ok=0

# ------------------------------------------------------------------------------------------- the stand-in media server
dc() { date -u -d "@$1" +%Y-%m-%dT%H:%M:%S.0000000Z; }
old=$(dc $(( $(date +%s) - 30 * 86400 )))
movie() { jq -nc --arg id "$1" --arg n "$2" --arg dc "$3" '{Type: "Movie", Id: $id, Name: $n, Path: "/media/movies/\($n)/\($n).mkv", DateCreated: $dc, LocationType: "FileSystem"}'; }
jq -n --argjson a "$(movie m1 "$F1" "$old")" --argjson b "$(movie m2 "$F2" "$old")" --argjson c "$(movie m3 "$F3" "$old")" --arg se "$SE" --arg dc "$old" \
    '{users: [{Id: "u1", Name: "Oma Gerda"}], resume: {u1: [$a]},
      library: [$a, $b, $c, {Type: "Series", Id: "s1", Name: $se, Path: "/media/tv/\($se)", DateCreated: $dc, LocationType: "FileSystem"}]}' >"$LIB"
cat >"$KEEP/standin.py" <<'PY'
import http.server, json, sys, urllib.parse
LIB, PORT = sys.argv[1], int(sys.argv[2])
class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        u = urllib.parse.urlparse(self.path)
        q = {k: v[0] for k, v in urllib.parse.parse_qs(u.query).items()}
        lib = json.load(open(LIB, encoding="utf-8"))
        parts = [p for p in u.path.split("/") if p]
        if u.path == "/Users":
            body = lib["users"]
        elif u.path == "/Sessions":
            body = []
        elif u.path == "/System/Info":
            body = {"ServerName": "Emby", "Version": "4.8.0.0", "Id": "standin"}
        elif len(parts) == 4 and parts[0] == "Users" and parts[2:] == ["Items", "Resume"]:
            body = {"Items": lib["resume"].get(parts[1], []) if q.get("IncludeItemTypes") == "Movie" else []}
        elif u.path == "/Items" and q.get("IncludeItemTypes") == "Movie,Series":
            start, limit = int(q.get("StartIndex", 0)), int(q.get("Limit", 1000))
            body = {"Items": lib["library"][start:start + limit], "TotalRecordCount": len(lib["library"])}
        else:
            body = {"Items": []}
        b = json.dumps(body).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)
        with open(LIB + ".asked", "a") as f:
            f.write(u.path + ("?" + u.query if u.query else "") + "\n")
    def log_message(self, *a):
        pass
http.server.ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
PY
# (stopped by its own command line: its pid is setsid's child's, never the shell's)
jt_standin_stop() { pkill -f "[s]tandin.py $LIB $PORT"; _st_until 10 bash -c "! pgrep -f '[s]tandin.py $LIB $PORT'"; }
undo_push jt_standin_stop
(cd / && exec setsid python3 -I "$KEEP/standin.py" "$LIB" "$PORT" </dev/null >"$KEEP/standin.err" 2>&1) &
wait_for 20 "the stand-in media server" curl -sf "http://127.0.0.1:$PORT/Users" || { _row state standin red "the stand-in didn't answer: $(cat "$KEEP/standin.err")"; scenario_end; }

# ----------------------------------------------------------------------- Ms. Moverelli set up, Jack Streamer hired on it
h_mv_set_up JT "rule=$S:0" || { _row state MV-SETUP red "her setup failed"; ok=0; }
jt_unhire() {
    has jack && { ask office.fire '{"desk":"jack"}' >/dev/null || return 1; }
    [[ -f $KEEP/mediacache.new ]] && rm -rf -- "$D/mediacache"
    return 0
}
[[ -d $D/mediacache ]] || touch "$KEEP/mediacache.new"
undo_push jt_unhire
ask office.hire '{"desks":["jack"]}' >/dev/null || { _row state JACK-HIRE red "his hire was refused: $(ask_last '.error')"; scenario_end; }
set=$(jq -nc --arg u "http://127.0.0.1:$PORT" --arg k "$KEY" --arg s "$S" --arg fm "$FM" --arg sr "$SR" --arg b "$ST_JACK_BUDGET" '{settings: {
    instances: [{url: $u, api_key: $k, type: "emby", servername: "Emby", path_mappings: {"/media/movies": "/mnt/user/\($s)/\($fm)", "/media/tv": "/mnt/user/\($s)/\($sr)"}}],
    libraries: [$fm, $sr], library_types: {($fm): "movies", ($sr): "tvshows"}, valid_users: [], user_budgets: {}, people: [],
    cache_path: "/mnt/cache", cache_budget: $b, themes: true}}')
r=$(_st_ask_secret jack.save <<<"$set") || { _row state JACK-SAVE red "his save was refused: $(jq -c '.error' <<<"$r")"; scenario_end; }
[[ $(jq -r '.themes' "$D/mediacache/mediacache_settings.json") == true ]] || { _row state JACK-SAVE red "the switch isn't on in his settings"; ok=0; }
mv_settings_note JT || ok=0
mset=$(jq -c --arg s "$S" --arg l "$(_st_jack_list_user)" '.shares[$s].files = (((.shares[$s].files // []) + [$l]) | unique)' "$(_st_dir JT)/moverelli.settings")
rm -f "$(_st_dir JT)/moverelli.settings"        # only read here — her settings come back whole (h_mv_set_up's undo)
mv_settings_save "$mset" || { _row state MV-LIST red "her save with his list was refused: $(ask_last '.error')"; ok=0; }
h_fresh_beat jack
((ok)) || { say "the state isn't there: no flow, no cells"; scenario_end; }
track_share "$S"
snap_before
L=$(_st_jack_list)
onpool() { local n=0 t; for t in "${THEMES[@]}"; do [[ -f /mnt/cache/$S/$t ]] && ((n++)); done; echo $n; }
onarray() { local n=0 t; for t in "${THEMES[@]}"; do ls /mnt/disk[0-9]*/"$S/$t" >/dev/null 2>&1 && ((n++)); done; echo $n; }
section() { [[ -f $L ]] && awk -v m='# USO themes' 'index($0, m) == 1 {on = 1; next} /^#/ {next} on && NF' "$L"; }
films() { [[ -f $L ]] && awk -v m='# USO themes' 'index($0, m) == 1 {exit} /^#/ {next} NF' "$L"; }
st=$D/mediacache/status.json
_jack_done() { local f=$D/mediacache/office-run.json; [[ -f $f ]] && (( $(jq -r '.finished // 0' "$f") > 0 )) && (( $(jq -r '.started // 0' "$f") >= $1 )); }

# ------------------------------------------------------------------------------------------------- «What's next» first
t0=$(_sc_now)
ask jack.start_run '{"mode":"report"}' >/dev/null && expect_ok jack.start_run
wait_for 600 "his «What's next» to end" _jack_done "$t0"
evidence "$st" status-whats-next.json >/dev/null
w=$(jq -c '.themes // {}' "$st" 2>/dev/null)
[[ $(jq -r '[.on, .first, .to_look] | @tsv' <<<"$w") == $'true\ttrue\t4' && $(onarray) == 4 && $(onpool) == 0 ]] \
    && cell whats-next-says 1 "said beforehand: $w — nothing moved" || cell whats-next-says 0 "«What's next»: $w, themes on the array $(onarray)/4, on the pool $(onpool)"

# -------------------------------------------------------------------------------------------- the first real run
minute_wait
t1=$(_sc_now)
ask jack.start_run '{"mode":"run"}' >/dev/null && expect_ok jack.start_run
wait_night 3600
evidence "$D/moverelli/night.json" night-1.json >/dev/null
evidence "$st" status-run-1.json >/dev/null
[[ -f $L ]] && evidence "$L" list-run-1.txt >/dev/null
evidence "$D/mediacache/mediacache_themes.json" themes-state-1.json >/dev/null
s1=$(jq -c '.themes // {}' "$st" 2>/dev/null)
say "his last step's themes: $s1"
[[ $(onpool) == 4 && $(onarray) == 0 && $(jq -r '[.first, .looked, .fetched, (.disks | length)] | @tsv' <<<"$s1") == $'true\t4\t4\t2' ]] \
    && cell first-collect 1 "4 themes on the pool, none on the array; $s1" || cell first-collect 0 "on the pool $(onpool)/4, on the array $(onarray); status $s1"
film=/mnt/cache/$S/$FM/$F1/$F1.mkv
films >"$KEEP/films.txt"
[[ -f $film ]] && grep -qxF "$film" "$KEEP/films.txt" && ! grep -q 'theme\.mp3$' "$KEEP/films.txt" \
    && cell film-and-theme 1 "the film on deck on the pool and on his list; no theme.mp3 among the films' entries" \
    || cell film-and-theme 0 "film on the pool: $([[ -f $film ]] && echo yes || echo no); films' entries: $(paste -sd' ' "$KEEP/films.txt" | head -c 400)"
want=$(for t in "${THEMES[@]}"; do echo "/mnt/cache/$S/$t"; done | sort)
got=$(section | sort)
[[ $got == "$want" ]] && cell list-section 1 "the section holds exactly the four themes" || cell list-section 0 "section: $(paste -sd' ' <<<"$got" | head -c 500)"
h_info night-1 "steps $(jq -c '[.steps[]? | {who, phase, result}]' "$D/moverelli/night.json" 2>/dev/null)"
ask jack.refresh '{}' 120 >/dev/null
pg=$(jq -c '{list: .cache.themes, state: .themes}' "$D/jack.json" 2>/dev/null)
[[ $(jq -r '[.list.files, .state.files, .state.on, .state.first] | @tsv' <<<"$pg") == $'4\t4\ttrue\tfalse' ]] \
    && cell page-says 1 "his page's state: $pg" || cell page-says 0 "his page's state: $pg"

# ------------------------------------------------------------------------- a new title: the next run looks only at it
# her control: a file of the share on the pool no list names — her part (age 0) must move it to the array
put disk1 "$FM/$FN/$FN.mkv" 512 && put disk1 "$FM/$FN/theme.mp3" 72 && put cache "uso-wip-jt-control/control-2.bin" 16 || ok=0
THEMES+=("$FM/$FN/theme.mp3")
jq --argjson n "$(movie m4 "$FN" "$(dc "$(date +%s)")")" '.library += [$n]' "$LIB" >"$LIB.new" && mv "$LIB.new" "$LIB"
: >"$LIB.asked"
minute_wait
ask jack.start_run '{"mode":"run"}' >/dev/null && expect_ok jack.start_run
wait_night 3600
evidence "$st" status-run-2.json >/dev/null
evidence "$LIB.asked" standin-asked-run-2.txt >/dev/null
s2=$(jq -c '.themes // {}' "$st" 2>/dev/null)
steps=$(jq -c '[.steps[]? | {who, phase, result}]' "$D/moverelli/night.json" 2>/dev/null)
ctl_array=$(ls /mnt/disk[0-9]*/"$S"/uso-wip-jt-control/control-2.bin 2>/dev/null | head -1)
old4=0; for t in "${THEMES[@]:0:4}"; do [[ -f /mnt/cache/$S/$t ]] && ((old4++)); done
[[ -n $ctl_array && ! -f /mnt/cache/$S/uso-wip-jt-control/control-2.bin && $old4 == 4 ]] \
    && cell moverelli-leaves 1 "her part moved the control file to $(cut -d/ -f3 <<<"$ctl_array") and left the four themes on the pool; steps $steps" \
    || cell moverelli-leaves 0 "control on the array: ${ctl_array:-no}; of the four themes on the pool $old4; steps $steps"
n2=$(section | grep -c .)
[[ $(jq -r '[.first, .to_look, .looked, .fetched] | @tsv' <<<"$s2") == $'false\t1\t1\t1' && $(onpool) == 5 && $(onarray) == 0 && $n2 == 5 ]] \
    && cell second-new-only 1 "only the new title looked at and fetched: $s2; 5 in the section" \
    || cell second-new-only 0 "status $s2; on the pool $(onpool)/5, on the array $(onarray); section $n2"

# ------------------------------------------------------------------- his let-go with «bring the films back»
t3=$(_sc_now)
ask jack.letgo_look '{}' >/dev/null
lk=$(ask_last '.pool')
ask jack.letgo '{"confirm":true,"release":true}' >/dev/null && expect_ok jack.letgo
rel=$(ask_last '.release')
wait_for 1800 "his release to end" _jack_done "$t3"
evidence "$st" status-release.json >/dev/null
sr=$(jq -c '{mode, result, cleanup, themes}' "$st" 2>/dev/null)
fa=$(ls /mnt/disk[0-9]*/"$S/$FM/$F1/$F1.mkv" 2>/dev/null | head -1)
[[ $(onarray) == 5 && $(onpool) == 0 && -n $fa && -z $(section) ]] \
    && cell letgo-back 1 "every theme (5) and the film back on the array, the section gone; look $lk; $sr" \
    || cell letgo-back 0 "themes on the array $(onarray)/5, on the pool $(onpool); film back: ${fa:-no}; section: $(section | grep -c .); look $lk; release $rel; $sr"
cp -p "$D/mediacache/logs/mediacache.log" "$KEEP/mediacache.log" 2>/dev/null && evidence "$KEEP/mediacache.log" >/dev/null
allow INV-COUNTS "the joint run's fetch and the let-go's release are Jack Streamer's, not hers (as M7)"
expect_changes "share|$S|*"
check standard
scenario_end
