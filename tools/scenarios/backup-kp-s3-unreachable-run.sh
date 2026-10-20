#!/bin/bash
# Cell B16 of matrix.md (batch E): the offsite storage can't be reached (KP-S3-UNREACHABLE — Kopia connected to MinIO,
# MinIO stopped). A run with uso-only at «local + offsite» (prepared by Apply with the backup place, as the plan then proposes — put back by the undo): the Kopia phase
# gives up in finite time and the run ends (≤ 30 min), the local snapshot is made all the same (cell local-ok), the
# failure is counted against Kopia only (cell kopia-said) — and the office says it in words: «offsite unreachable since …»
# (the matrix's line — cell offsite-since looks for it in Mr. Backupsy's state). Never a hang, the agent quick (INV-TICK).
# Pre-release (~10 min, mostly Kopia's own retries).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-s3-unreachable-run
needs docker kopia minio
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
undo_push wait_for 180 "the engine at rest" bash -c '! pgrep -f "[b]ackup/(backup|setup)\.sh"'     # last: the agent's look after Kopia came back (--kopia-count)
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" "share|UnraidSecretaryOffice|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
state KP-S3-UNREACHABLE
track_share "$S"
snap_before
t0=$SECONDS
minute_wait
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    wait_backup 2400
    trun=$((SECONDS - t0))
    st=$UB/state/status.json
    evidence "$st" >/dev/null
    evidence "$UB/logs/run-$(jq -r '.run // ""' "$st").log" run.log >/dev/null 2>&1
    r=$(jq -c '{result, errors, warnings, kopia: (.kopia // null)}' "$st" | head -c 300)
    ((trun <= 1800)) && cell run-ends 1 "the run ended in $trun s: $r" || cell run-ends 0 "the run took $trun s (> 1800): $r"
    log=$UB/logs/run-$(jq -r '.run // ""' "$st").log
    # the local part: the share's snapshot of this run (the engine's own count of what it did — status.json)
    jq -e '(.result // "") != "failed" and (.result // "") != "running"' "$st" >/dev/null && cell local-ok 1 "the run went on locally: $r" \
        || cell local-ok 0 "the run failed as a whole: $r"
    k=$(jq -r '[.. | objects | select(has("kopia_failed") or has("kopia")) | (.kopia_failed // .kopia.failed // empty)] | first // empty' "$st" 2>/dev/null)
    grep -q "Kopia snapshot of '$S' failed\|Kopia: .*$S" "$log" 2>/dev/null && cell kopia-said 1 "the run says Kopia's part of $S failed (status: kopia failed ${k:-?})" \
        || cell kopia-said 0 "the run's record doesn't say Kopia's part of $S failed (status: $r)"
else
    cell run-ends 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
ask backup.refresh '{}' 120 >/dev/null
o=$(jq -c '[paths(scalars) as $p | select(($p | map(tostring) | join(".")) | test("offsite|kopia"; "i")) | select(($p | map(tostring) | join(".")) | test("unreach|since|reach|fail|down"; "i")) | {k: ($p | map(tostring) | join(".")), v: getpath($p)}] | .[0:6]' "$D/backup.json" 2>/dev/null)
[[ -n $o && $o != '[]' ]] && cell offsite-since 1 "Mr. Backupsy's state says the offsite is unreachable: $o" \
    || cell offsite-since 0 "nothing in Mr. Backupsy's state says «offsite unreachable since …» — the page shows only the run's errors"
bubble_look after-run backup
check standard
scenario_end
