#!/bin/bash
# Batch H, finding 7 (parking lot «Resilienz-Funde», H4's wave 1b), Mr. Backupsy's half: files born, changed and modified
# 30 days in the future (the clock ran ahead and was put right; h_put_future writes them with the agent stopped). His
# run backs them up like any other — local snapshot and offsite (Kopia) — without failing (cell run-not-failed), Kopia
# has them in the share's newest snapshot (cell in-kopia), and the office says what it met (cell said: a warning code or
# a word that files carry times in the future — expected, the brief: «said»). The share: uso-wip-h-futb (only cache,
# btrfs), «local + offsite» by Apply (the place too, as batch E's Kopia cells: the plan proposes it as soon as a share
# goes offsite); settings.ini put back from its copy, the scratch share's Kopia source left to the base reset (Kopia's
# snapshots are never deleted by a scenario — the base's orphans look names it). Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-tm-future-mtime-h-run
needs docker kopia
S=uso-wip-h-futb
mkdir -p "$KEEP_STASH/H7B-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/H7B-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/H7B-INI" "$UB/settings.ini"
undo_push h_share_drop "$S"
nc_data_closed
share_make "$S" only cache || _row state share_make red "Unraid's «Add Share» made no $S"
nc_data_closed
h_put_future H7B cache "$S" 4 30 || _row state put_future red "the future files could not be written"
undo_push files_unput H7B
h_fresh_beat backup
say_cmd stat -c '%n | born %w | modified %y' /mnt/cache/$S/*
flow BK-APPLY "share|$S|mode=kopia" "share|UnraidSecretaryOffice|mode=kopia" || _row state prepare red "Apply of $S as local + offsite failed"
track_share "$S"
snap_before
minute_wait
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
run=$(jq -r '.run // ""' "$st")
evidence "$UB/logs/run-$run.log" run.log >/dev/null
r=$(jq -r '"\(.result) \(.message // "")"' "$st")
codes=$(_ck_warning_codes | paste -sd' ' -)
[[ $r =~ ^(ok|warnings) ]] && h_cell run-not-failed 1 "the run: $r (warnings: ${codes:-none})" || h_cell run-not-failed 0 "the run: $r"
k=$(KX_T=90 _st_kx snapshot list --all --json 2>/dev/null | jq -c --arg s "$S" '[.[] | select(.source.path | endswith("/" + $s))] | sort_by(.startTime) | last
    | if . == null then null else {start: .startTime, files: (.stats.fileCount // .summary.files // null), size: (.stats.totalSize // .summary.size // null)} end')
[[ $k != null && -n $k && $(jq -r '.files // 0' <<<"$k") -ge 4 ]] && h_cell in-kopia 1 "Kopia's newest snapshot of $S: $k" || h_cell in-kopia 0 "Kopia's newest snapshot of $S: ${k:-none}"
z=$(grep -iE 'future|in the future|clock|negative' "$UB/logs/run-$run.log" 2>/dev/null | head -2 | cut -c1-200)
if grep -qiE 'future|clock' <<<"$codes"; then h_cell said 1 "a warning: $codes"
else h_cell said 0 "nothing says 4 files of $S carry times 30 days in the future (warnings: ${codes:-none}; the run's log: ${z:-nothing})"; fi
check standard
scenario_end
