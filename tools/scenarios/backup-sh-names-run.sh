#!/bin/bash
# Cell B10 of matrix.md: awkward names through Mr. Backupsy's run (bash, jq, Kopia). SH-NAMES «uso-wip-c Ärger 's»
# (yes cache: space, umlaut, apostrophe — a hand-made top folder on the pool, no dataset name can carry it) with the
# twelve put_names files, set to «local + Kopia» with two Kopia ignore rules naming awkward files («/it's here.mkv»,
# «/Ümläut Ärger.txt»). The run: ends ok (or only with warnings the state explains), Kopia's snapshot of the share holds
# the ten others by their exact names and not the two ignored, the local moment holds all twelve, nothing in the log
# breaks on a name (no «No such file», no jq/parse error). App names can't be awkward (Docker allows [a-zA-Z0-9_.-]
# only); a VM with an awkward name needs a vm_make by name (not a helper yet — not covered here).
# Preparation (before snap_before): Apply of the share; settings.ini put back by the undo. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-names-run
needs tower kopia
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S="uso-wip-c Ärger 's"
mkdir -p "$KEEP_STASH/C-B10" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-B10/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-B10" "$UB/settings.ini"
nc_data_closed
state SH-NAMES "$S"
nc_data_closed
flow BK-APPLY "share|$S|mode=kopia" "share|$S|kopia_ignore=[\"/it's here.mkv\",\"/Ümläut Ärger.txt\"]" \
    || _row state prepare red "Apply of «$S» as local + Kopia failed: $(ask_last '.error // empty')"
ig=$(_ini_flat "$UB/settings.ini" | grep -F "share|$S|kopia_ignore=" | paste -sd'|')
say "settings.ini: ${ig:-no kopia_ignore for the share}"
track_share "$S"
snap_before
flow BK-RUN-MANUAL mode=backup
log=$(ls -t "$UB"/logs/run-*.log 2>/dev/null | head -1)
r=$(jq -r '.result // empty' "$UB/state/status.json" 2>/dev/null)
[[ $r == ok || $r == warnings ]] && cell run-result 1 "the run ended $r" || cell run-result 0 "the run ended «$r»"
bad=$(grep -F "$S" "${log:-/dev/null}" | grep -iE 'no such file|error|parse|invalid|cannot' | head -2 | cut -c1-200)
[[ -z $bad ]] && cell log-clean 1 "the run's log names «$S» without an error" || cell log-clean 0 "the log: ${bad//$'\n'/ | }"
# the local moment: whatever snapshot holds the share's folder (its pool's top dataset)
mom=$(zfs list -H -t snapshot -o name cache 2>/dev/null | grep '@uso-backup-' | tail -1)
n=0; [[ -n $mom ]] && n=$(find "/mnt/cache/.zfs/snapshot/${mom#*@}/$S" -maxdepth 1 -type f 2>/dev/null | wc -l)
((n == 12)) && cell local-moment 1 "the local moment $mom holds all 12 names" || cell local-moment 0 "local moment ${mom:-none} holds $n of 12 files of «$S»"
# Kopia: the share's newest snapshot, its top entries by name
snaps=$(timeout 120 docker exec kopia kopia snapshot list --all --json 2>/dev/null)
sid=$(jq -r --arg s "$S" '[.[] | select((.source.path // "") | endswith("/" + $s))] | sort_by(.startTime) | last | .id // empty' <<<"${snaps:-[]}")
if [[ -z $sid ]]; then
    cell kopia-ignore 0 "no Kopia snapshot whose source ends in «/$S»: $(jq -r '[.[].source.path] | unique | join(", ")' <<<"${snaps:-[]}" | head -c 300)"
else
    ls=$(timeout 120 docker exec kopia kopia ls "$sid" 2>/dev/null)
    want=$(find "/mnt/cache/$S" -maxdepth 1 -type f -printf '%f\n' | grep -vxF -e "it's here.mkv" -e 'Ümläut Ärger.txt' | sort)
    miss=$(comm -23 <(printf '%s\n' "$want") <(printf '%s\n' "$ls" | sort) | paste -sd'|')
    extra=$(printf '%s\n' "$ls" | grep -xF -e "it's here.mkv" -e 'Ümläut Ärger.txt' | paste -sd'|')
    [[ -z $miss && -z $extra ]] && cell kopia-ignore 1 "Kopia's $sid holds the 10 others by name, not the 2 ignored" \
        || cell kopia-ignore 0 "Kopia's $sid: missing «$miss», ignored but there «$extra»"
fi
check standard
check final
scenario_end
