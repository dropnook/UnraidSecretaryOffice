#!/bin/bash
# Batch H, finding 3 (parking lot «Resilienz-Funde», H2's wave 1): a ZFS share Mr. Backupsy backs up carries his
# @uso-backup-* snapshots; the user empties it and clicks ⟦Delete⟧ in Unraid's share form — Unraid can't destroy a
# dataset with snapshots, its «Delete» only unmounts it (STATES.md; share_remove refuses). Expected: the office says it
# and helps — after «Look at the server again» and his look, a hint names the share's snapshots and the way (Ms.
# Snapshotini, or the engine's own let-go of that share), never silence (cell says-snapshots); his next run doesn't fail
# on the share left half-deleted (cell run-not-failed). The share: uso-wip-h-zdel on the ZFS pool «fast» (only), 5 files,
# «local» by Apply, one run (its snapshot). Cell delete-blocked records what Unraid did (info: Unraid's behaviour, the
# state itself). settings.ini put back from its copy; the scratch dataset's snapshots and the dataset destroyed in the
# undo (uso-wip-h-* only). The user's «Delete» is part of the state (before snap_before): what it left — Unraid drops
# the cfg, the dataset stays mounted and listed — is the state the office must speak about. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-zfs-snapshots-h-delete
needs zfs pool2
S=uso-wip-h-zdel POOL=fast DS=fast/uso-wip-h-zdel
mkdir -p "$KEEP_STASH/H3-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/H3-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/H3-INI" "$UB/settings.ini"
undo_push h_ds_destroy "$DS"
undo_push h_share_drop "$S"
undo_push h_snaps_drop "$DS"
nc_data_closed
share_make "$S" only "$POOL" || _row state share_make red "Unraid's «Add Share» made no $S on $POOL"
nc_data_closed
put_files H3 "$POOL" "$S" 5 || _row state put_files red "no files in $S"
undo_push rm -rf "$KEEP_STASH/H3"        # the files went with the user's «Delete»; the dataset with h_ds_destroy (after its snapshots)
flow BK-APPLY "share|$S|mode=snapshot" || _row state prepare red "Apply of $S as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run failed"
snaps=$(zfs list -H -t snapshot -o name "$DS" 2>/dev/null | grep -c '@uso-backup-')
((snaps > 0)) || _row state prepare red "no engine snapshot of $DS after the run"
say "$DS carries $snaps engine snapshot(s)"
cp -p "$UB/settings.ini" "$KEEP/settings-after-apply.ini"
# the user: the files away (through the pool itself), then Unraid's «Delete» in the share form
find "/mnt/$POOL/$S" -mindepth 1 -delete
if share_remove "$S"; then
    h_info delete-blocked "Unraid's «Delete» removed $S (snapshots: $(zfs list -H -t snapshot -o name "$DS" 2>/dev/null | wc -l))"
else
    h_info delete-blocked "Unraid's «Delete» left it: shares.ini $(share_exists "$S" && echo lists || echo "doesn't list") $S, cfg $( [[ -f /boot/config/shares/$S.cfg ]] && echo there || echo gone), $DS mounted=$(zfs get -H -o value mounted "$DS" 2>/dev/null), $(zfs list -H -t snapshot -o name "$DS" 2>/dev/null | wc -l) snapshot(s)"
fi
track_share "$S"
snap_before                                  # the half-deleted share is the state: the user's «Delete» came before
say_cmd zfs list -r -t all -o name,mounted,used "$DS"
flow BK-REPLAN
ask backup.refresh '{}' 120 >/dev/null
ask snapshot.refresh '{}' 120 >/dev/null
ask caretaker.refresh '{}' 120 >/dev/null
# whatever the office says of the share now (the plan's hints and warnings, the run's drift, his state, the Team Lead)
said=$(for f in "$UB/state/setup-plan.json" "$UB/state/setup-status.json" "$UB/state/drift.json" "$D/backup.json" "$D/caretaker.json" "$D/snapshot.json"; do
    [[ -f $f ]] || continue
    jq -c --arg s "$S" --arg f "$(basename "$f")" '[.. | objects | select((.code? // .key? // .id? // "") | type == "string" and length > 0)
        | select(tostring | contains($s)) | {f: $f, code: (.code // .key // .id), p: ((.params // {}) | tostring | .[0:120])}] | unique | .[]' "$f" 2>/dev/null
done)
say "what the office says of $S: ${said:-nothing}"
printf '%s\n' "$said" >"$KEEP/said.jsonl"; evidence "$KEEP/said.jsonl" >/dev/null
if grep -qiE '"code":"[^"]*snap' <<<"$said"; then
    h_cell says-snapshots 1 "a hint about the snapshots: $(grep -iE '"code":"[^"]*snap' <<<"$said" | head -2 | paste -sd' ')"
else
    h_cell says-snapshots 0 "nothing names $S's $snaps snapshot(s) that keep Unraid from deleting it — said: $(jq -r '.code' <<<"$said" 2>/dev/null | sort -u | paste -sd' ' -)"
fi
expect_changes "share|$S|*"
minute_wait
flow BK-RUN-MANUAL mode=backup
r=$(jq -r '"\(.result) \(.message // "")"' "$UB/state/status.json")
[[ $r =~ ^(ok|warnings) ]] && h_cell run-not-failed 1 "the next run: $r" || h_cell run-not-failed 0 "the next run: $r"
h_info snapshots-after "after the next run $DS carries $(zfs list -H -t snapshot -o name "$DS" 2>/dev/null | grep -c '@uso-backup-') engine snapshot(s) (before it: $snaps) — the share's cfg gone, the dataset still mounted"
expect_warnings share_gone share_new share_empty
check standard
scenario_end
