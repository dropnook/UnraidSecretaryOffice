#!/bin/bash
# Cell R18 of matrix.md (batch E), state KP-DISCONNECTED: Kopia's container runs, its repository not connected. uso-only
# goes offsite first (Apply + a run, settings.ini put back by the undo), then the state: Mr. Restori's list of its
# offsite snapshots answers in finite time with a word (cells list-ends, list-says), his drill marks its Kopia step «not
# checked» with the reason and passes within its budget (cells drill-not-checked, drill-budget) — never a hang, never
# «failed». Pre-release (~8 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-kp-disconnected-kopia-drill
needs docker kopia minio
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
: # the filesystem repository of the base
flow BK-APPLY "share|$S|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run that puts $S offsite failed"
src=$(ask restore.refresh '{}' 300 >/dev/null; jq -r --arg s "$S" '[.. | objects | select(has("source")) | .source | select(type == "string" and test("/" + $s + "$"))] | first // empty' "$D/restore.json" 2>/dev/null)
say "the offsite source of $S as Mr. Restori lists it: ${src:-none}"
state KP-DISCONNECTED
snap_before
files_strict
# his offsite list of the source (the dialog's first step) — in finite time, refused with a word, never a hang
t0=$SECONDS
if [[ -n $src ]]; then
    out=$(ask restore.kopia_list "$(jq -cn --arg s "$src" '{source: $s}')" 300)
    tk=$((SECONDS - t0)); k=$(ask_last '.error.key // "accepted"')
    ((tk <= 180)) && cell list-ends 1 "his list of $src answered in $tk s: $k" || cell list-ends 0 "his list of $src took $tk s: $k"
    [[ $k != accepted && $k != ask_failed ]] && cell list-says 1 "refused with a word: $k" || cell list-says 0 "the list answered «$k» with Kopia unreachable"
else
    cell list-ends 0 "Mr. Restori lists no offsite source of $S after its run"
fi
# the drill: its Kopia step «not checked» with the reason, the drill passes, within its budget
t0=$SECONDS
flow RS-DRILL
td=$((SECONDS - t0))
j=$(ls -t "$D"/restore-drill/*/journal.json 2>/dev/null | head -1)
evidence "$j" drill-journal.json >/dev/null
ks=$(jq -c '[.steps[]? | select((.kind // .type // .name // "" | tostring) | test("kopia|offsite"; "i")) | {name, state, code}] | .[0:4]' "$j" 2>/dev/null)
((td <= 1200)) && cell drill-budget 1 "the drill took $td s" || cell drill-budget 0 "the drill took $td s (> 1200)"
jq -e '[.steps[]? | select((.kind // .type // .name // "" | tostring) | test("kopia|offsite"; "i"))] | length > 0 and all(.state == "not_checked" and ((.code // "") | length > 0))' "$j" >/dev/null 2>&1 \
    && cell drill-not-checked 1 "Kopia's step: $ks" || cell drill-not-checked 0 "Kopia's step(s): ${ks:-none}"
check standard
scenario_end
