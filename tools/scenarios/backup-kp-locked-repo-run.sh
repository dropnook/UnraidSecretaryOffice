#!/bin/bash
# Cell B38 of matrix.md (batch E), state KP-LOCKED-REPO: Kopia's maintenance lock (repository.config.mlock) held, as by
# a long maintenance of another process. A run with uso-only at «local + offsite» (prepared by Apply, put back by the
# undo) ends in finite time (≤ 30 min), backs up locally, says what Kopia's part met (cell kopia-said), and nothing ever
# runs Kopia's maintenance with --safety=none, no second maintenance (cell no-safety-none). Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-locked-repo-run
needs docker kopia minio
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
undo_push wait_for 180 "the engine at rest" bash -c '! pgrep -f "[b]ackup/(backup|setup)\.sh"'     # last: the agent's look after Kopia came back (--kopia-count)
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" "share|UnraidSecretaryOffice|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
state KP-LOCKED-REPO
track_share "$S"
agent_log_mark run
snap_before
t0=$SECONDS
minute_wait
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    wait_backup 2400
    trun=$((SECONDS - t0))
    st=$UB/state/status.json
    log=$UB/logs/run-$(jq -r '.run // ""' "$st").log
    evidence "$st" >/dev/null; evidence "$log" run.log >/dev/null 2>&1
    r=$(jq -c '{result, errors, warnings, message}' "$st" | head -c 300)
    ((trun <= 1800)) && cell run-ends 1 "the run ended in $trun s: $r" || cell run-ends 0 "the run took $trun s (> 1800): $r"
    [[ $(jq -r .result "$st") =~ ^(ok|warnings|errors)$ ]] && cell run-local 1 "the run went on locally: $r" || cell run-local 0 "the run: $r"
    k=$(grep -E "Kopia|kopia" "$log" 2>/dev/null | grep -E 'WARNING|ERROR' | head -2)
    [[ -n $k ]] && cell kopia-said 1 "the run says what Kopia's part met: ${k:0:300}" || cell kopia-said 0 "the run's record says nothing of Kopia's trouble: $r"
else
    cell run-ends 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
ask caretaker.refresh '{}' 120 >/dev/null
bad=$( { agent_log_since run; cat "$log" 2>/dev/null; timeout 20 docker logs --since 40m kopia 2>&1; } | grep -c -- 'safety=none')
((bad == 0)) && cell no-safety-none 1 "no --safety=none (agent log, the run's log, Kopia's own)" || cell no-safety-none 0 "--safety=none seen $bad time(s)"
bubble_look after-run backup caretaker
check standard
scenario_end
