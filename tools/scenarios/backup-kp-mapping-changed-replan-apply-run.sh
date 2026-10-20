#!/bin/bash
# Cell B37 of matrix.md (batch E), state KP-MAPPING-CHANGED: Kopia's container made anew with the snapshots mapped at
# /uso2 instead of /uso (new sources). With uso-only at «local + offsite» (prepared by Apply with the backup place, as the plan then proposes — put back by the undo):
# «Look at the server again» names the mapping (setup.msg kopia_mapping / kopia_other_mapping / kopia_legacy_mapping —
# cell replan-hint), Apply on the page's draft changes nothing (INV-DIFF-EXACT), the run goes on (cells run-local,
# kopia-not-started: the container as the state left it). Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-mapping-changed-replan-apply-run
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
kstate() { timeout 10 docker inspect -f '{{.State.Status}}' kopia 2>/dev/null || echo missing; }
undo_push wait_for 180 "the engine at rest" bash -c '! pgrep -f "[b]ackup/(backup|setup)\.sh"'     # last: the agent's look after Kopia came back (--kopia-count)
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" "share|UnraidSecretaryOffice|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
# what the scenario leaves in Kopia's repository under the new path (the run's snapshots and the policies Apply set for
# /uso2) goes after the container is back on /uso — else the next plan says source_gone for them
uso2_drop() {
    local s
    for s in $(_st_kx snapshot list --all --json 2>/dev/null | jq -r '[.[].source | select(.path | startswith("/uso2")) | "\(.userName)@\(.host):\(.path)"] | unique[]'); do
        _st_kx snapshot delete --all-snapshots-for-source "$s" --delete >/dev/null || return 1
    done
    for s in $(_st_kx policy list 2>/dev/null | awk '$2 ~ /:\/uso2(\/|$)/ {print $2}'); do _st_kx policy delete "$s" >/dev/null || return 1; done
}
undo_push uso2_drop
state KP-MAPPING-CHANGED
expect_changes 'kopia|*'          # the old /uso sources are gone in the new mapping: the plan says source_gone (zustaende.md: «alte Quellen als Waisen»)
track_share "$S"
snap_before
k0=$(kstate)
if flow BK-REPLAN; then
    c=$(jq -r '[.messages[]? | .code] | unique | join(" ")' "$KEEP/plan-replan.json")
    grep -qE 'kopia_no_mapping|kopia_other_mapping|kopia_legacy_mapping|kopia_mapping\b' <<<"$c" && cell replan-hint 1 "the plan's hint: $(grep -oE 'kopia_no_mapping|kopia_other_mapping|kopia_legacy_mapping|kopia_mapping\b' <<<"$c" | paste -sd' ' -)" \
        || cell replan-hint 0 "no hint for this state (kopia_no_mapping|kopia_other_mapping|kopia_legacy_mapping|kopia_mapping\b) in the plan: $c"
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
allow INV-UNRAID-SAME "Apply aligns Kopia's policies with the sources as mapped now (settings.ini's header: «setup.sh --kopia  align the Kopia policies»): a policy for /uso2 is added, the one for /uso kept"
check standard
scenario_end
