#!/bin/bash
# Cell B17 of matrix.md (batch E): the offsite repository suddenly empty (KP-EMPTIED — the filesystem repository's files
# moved aside: the ransomware case, engine 2.53/2.56), on a real repository. uso-only at «local + offsite» (prepared by
# Apply, put back by the undo) and one run first, so the repository holds its snapshots. Then: the run holds Kopia's
# step (kopia_held, or kopia_gone when Kopia can't open the repository at all) and goes on locally; ONE alarm — a second
# run while held sends none (cell one-alarm); «Yes, it is new» (backup.kopia_accept with the repository the page shows,
# held.accept_go) ends the hold — the next run is not held (cell accept-lifts). Pre-release (~8 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-emptied-run-accept
needs docker kopia
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
H=$UB/state/kopia-held.json
alarms() { grep -l 'Unraid Secretary Office' "$NOTIFY_DIR"/unread/*.notify "$NOTIFY_DIR"/archive/*.notify 2>/dev/null | xargs -r grep -l -i 'suddenly empty\|was emptied' 2>/dev/null | wc -l; }
run_now() {      # «Back up now», waited for; prints the run's result and codes
    minute_wait
    ask backup.start '{"mode":"backup"}' >/dev/null || { echo "refused $(ask_last '.error.key')"; return 1; }
    wait_backup 1800
    local st=$UB/state/status.json
    evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" "run-$1.log" >/dev/null 2>&1
    jq -c '{result, errors, warnings, codes: ([.warning_items[]? | .code] | unique)}' "$st"
}
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
undo_push rm -f "$H"                      # a hold left by a red run is no base state
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
say "the first run (prepare): $(run_now first)"
state KP-EMPTIED
track_share "$S"
snap_before
a0=$(alarms)
r1=$(run_now held)
say "the run after the emptying: $r1"
jq -e '.codes | (index("kopia_held") or index("kopia_gone"))' <<<"$r1" >/dev/null 2>&1 && [[ -f $H ]] \
    && cell held 1 "Kopia's step held: $r1" || cell held 0 "not held: $r1 (kopia-held.json $( [[ -f $H ]] && echo there || echo missing))"
jq -e '.result != "failed"' <<<"$r1" >/dev/null 2>&1 && cell local-goes-on 1 "the run went on locally" || cell local-goes-on 0 "the run failed as a whole: $r1"
a1=$(alarms)
r2=$(run_now again)
a2=$(alarms)
say "a second run while held: $r2 — alarms $a0 → $a1 → $a2"
(( a1 - a0 == 1 && a2 == a1 )) && cell one-alarm 1 "one alarm for the hold, none for the second run" \
    || cell one-alarm 0 "alarms: $((a1 - a0)) for the first held run, $((a2 - a1)) for the second (one in all must be)"
ask backup.refresh '{}' 120 >/dev/null
repo=$(jq -r '.repo // empty' "$H" 2>/dev/null)
bubble_look held backup caretaker
if [[ -n $repo ]] && ask backup.kopia_accept "$(jq -cn --arg r "$repo" '{repo: $r}')" >/dev/null; then
    r3=$(run_now accepted)
    say "the run after «Yes, it is new»: $r3"
    jq -e '.codes | (index("kopia_held") or index("kopia_gone")) | not' <<<"$r3" >/dev/null 2>&1 && [[ ! -f $H ]] \
        && cell accept-lifts 1 "not held any more: $r3" || cell accept-lifts 0 "still held after the accept: $r3"
else
    cell accept-lifts 0 "«Yes, it is new» refused or no repository shown: ${repo:-none} $(ask_last '.error.key // empty' 2>/dev/null)"
fi
expect_warnings kopia_held kopia_gone kopia_emptied
check standard
scenario_end
