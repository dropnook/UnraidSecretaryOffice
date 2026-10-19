#!/bin/bash
# Cell B37 of matrix.md (batch E), state KP-CONTAINER-GONE: Kopia's container removed, its template stays. With uso-only
# at «local + offsite» (prepared by Apply with the backup place, as the plan then proposes — put back by the undo): «Look at the server again» says it (setup.msg
# kopia_container_missing / kopia_no_container — cell replan-hint), Apply on the page's draft changes nothing
# (INV-DIFF-EXACT), the run backs up locally — the office never makes or starts a container (cells kopia-not-started).
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-container-gone-replan-apply-run
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
kstate() { timeout 10 docker inspect -f '{{.State.Status}}' kopia 2>/dev/null || echo missing; }
undo_push wait_for 180 "the engine at rest" bash -c '! pgrep -f "[b]ackup/(backup|setup)\.sh"'     # last: the agent's look after Kopia came back (--kopia-count)
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" "share|UnraidSecretaryOffice|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
state KP-CONTAINER-GONE
track_share "$S"
snap_before
k0=$(kstate)
if flow BK-REPLAN; then
    c=$(jq -r '[.messages[]? | .code] | unique | join(" ")' "$KEEP/plan-replan.json")
    grep -qE 'kopia_container_missing|kopia_no_container|kopia_container_none' <<<"$c" && cell replan-hint 1 "the plan's hint: $(grep -oE 'kopia_container_missing|kopia_no_container|kopia_container_none' <<<"$c" | paste -sd' ' -)" \
        || cell replan-hint 0 "no hint for this state (kopia_container_missing|kopia_no_container|kopia_container_none) in the plan: $c"
fi
flow BK-APPLY plan=old
expect_diff ''                                   # Apply on the page's draft as it is: no value changes
[[ $(kstate) == "$k0" ]] && cell kopia-not-started 1 "Kopia's container $k0 after Apply" || cell kopia-not-started 0 "Kopia's container $k0 → $(kstate) after Apply"
minute_wait
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    wait_backup 1800
    st=$UB/state/status.json
    evidence "$st" >/dev/null; evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" run.log >/dev/null 2>&1
    r=$(jq -c '{result, errors, warnings, message}' "$st" | head -c 300)
    [[ $(jq -r .result "$st") =~ ^(ok|warnings|errors)$ ]] && cell run-local 1 "the run went on locally: $r" || cell run-local 0 "the run: $r"
    [[ $(kstate) == "$k0" ]] && cell run-kopia-not-started 1 "Kopia's container $k0 after the run" || cell run-kopia-not-started 0 "Kopia's container $k0 → $(kstate) after the run"
else
    cell run-local 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
bubble_look after-run backup
check standard
scenario_end
