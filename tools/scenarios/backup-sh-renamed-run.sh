#!/bin/bash
# Cell B9 of matrix.md: a share renamed in Unraid's own form (7.4: `zfs rename` of its dataset, the snapshots go along)
# after Mr. Backupsy backed it up. The run before the user looks again says «renamed» once (share_renamed), never «gone»
# + «new»; «Look at the server again» takes the settings over (renamed_from: mode, retention); after Apply the next
# run's retention (1 0 0: keep one) treats the old snapshot — now under the new dataset name — as the engine's own and
# thins it. The moments under the new name are Mr. Restori's (he restores only what an app or VM binds): cell R8,
# restore-sh-renamed-files. Pre-release (~4 min).
# Preparation (before snap_before, as a user's earlier days): scratch share uso-wip-c-ren (only cache: a dataset, 10
# files), set to «local» with retention 1 0 0 by Apply, one run. settings.ini is put back by the undo (a copy — Apply
# changed it for this scratch share), the scratch dataset's engine snapshots are destroyed before Unraid removes it
# (Unraid can't delete a ZFS share with snapshots, STATES.md).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-renamed-run
needs tower zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
A=uso-wip-c-ren B=uso-wip-c-ren2
# the engine's snapshots of the scratch datasets (whichever name they carry now) — never anything else
snaps_drop() { local s ds; for s in "$@"; do [[ $s == uso-wip-c-* ]] || continue; ds=cache/$s
    zfs list -H -t snapshot -o name "$ds" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; done; }
ours() { zfs list -H -t snapshot -o name "cache/$1" 2>/dev/null | grep -c '@uso-backup-'; }
mkdir -p "$KEEP_STASH/C-B9" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-B9/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-B9" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$A"
nc_data_closed                               # Unraid's «Add Share» opened Nextcloud's data folder again (0777)
undo_push snaps_drop "$A" "$B"
flow BK-APPLY "share|$A|mode=snapshot" "share|$A|retention=1 0 0" || _row state prepare red "Apply of $A as local failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run failed"
first=$(zfs list -H -t snapshot -o name -s creation "cache/$A" 2>/dev/null | grep '@uso-backup-' | tail -1)
[[ -n $first ]] || _row state prepare red "no engine snapshot of cache/$A after the first run"
say "the first run's snapshot: $first"
share_rename "$A" "$B" && undo_push share_rename "$B" "$A" || _row state SH-RENAMED red "Unraid's form didn't rename $A"
nc_data_closed
moment=cache/$B@${first#*@}
zfs list -H "$moment" >/dev/null 2>&1 && say "the snapshot went along: $moment" || _row state SH-RENAMED red "no $moment after the rename"
track_share "$B"
snap_before
# INV-NOTIFY-ONCE takes one subject twice within 60 s as one event told twice (one run a minute): the runs inside the
# flow end ≥ 61 s apart, each run's notification its own event
last_end=0
run_spaced() { local w=$((last_end + 61 - $(date +%s))); ((w > 0)) && { say "waiting $w s: the previous run's notification ≥ 61 s before this one's"; sleep "$w"; }
    flow BK-RUN-MANUAL mode=backup; local rc=$?; last_end=$(date +%s); return $rc; }
expect_warnings share_renamed
# 1. the scheduled run before the user looks again: «renamed» once, not «gone» and «new»
run_spaced
dr=$(jq -c '[.items[]? | {code: (.code // ""), name: (.params.name // ""), old: (.params.old // "")}]' "$UB/state/drift.json" 2>/dev/null)
say "drift after the run: $dr"
ren=$(jq --arg a "$A" --arg b "$B" '[.[] | select(.code == "share_renamed" and .name == $b and .old == $a)] | length' <<<"$dr")
bad=$(jq -c --arg a "$A" --arg b "$B" '[.[] | select((.code == "share_gone" and .name == $a) or (.code == "share_new" and .name == $b))]' <<<"$dr")
[[ $ren == 1 && $bad == '[]' ]] && cell renamed-once 1 "the run says «$A renamed to $B» once, no gone/new" \
    || cell renamed-once 0 "share_renamed ×${ren:-0}, gone/new: $bad"
# 2. «Look at the server again»: the settings taken over (renamed_from), the old entry goes
flow BK-REPLAN
plan=$KEEP/plan-replan.json
pm=$(jq -r --arg b "$B" '.P["share|\($b)|mode"] // empty' "$plan") pr=$(jq -r --arg b "$B" '.P["share|\($b)|retention"] // empty' "$plan")
wc=$(jq -r --arg b "$B" '.shares[]? | select(.name == $b) | .why' "$plan" 2>/dev/null)
[[ $pm == snapshot && $pr == '1 0 0' && $wc == renamed_from ]] && cell settings-taken-over 1 "the plan keeps $B at local, retention 1 0 0 (why: ${wc:-?})" \
    || cell settings-taken-over 0 "the plan proposes $B mode «$pm» retention «$pr» (why: ${wc:-?}) — saved for $A: snapshot, 1 0 0"
expect_changes "share|$A|*" "share|$B|*"   # the rename itself is the change the page lists (its Apply follows)
# 3. Apply as proposed, then the next run: the old snapshot is the engine's own under the new name — retention 1 thins it
before_ini=$(_ini_flat "$UB/settings.ini" | grep -F "share|$A|")
flow BK-APPLY plan=old
exp=$( { sed 's/^/-/' <<<"$before_ini"; sed "s/^share|$A|/+share|$B|/" <<<"$before_ini"; } | sed '/^[-+]$/d')
mapfile -t exp_lines <<<"$exp"
expect_diff "${exp_lines[@]}"              # INV-DIFF-EXACT: the rename's lines, nothing else
run_spaced
left=$(zfs list -H -t snapshot -o name "cache/$B" 2>/dev/null | grep '@uso-backup-' | paste -sd' ')
if zfs list -H "$moment" >/dev/null 2>&1; then
    cell retention-new-name 0 "the retention (1 0 0) kept $moment beside the new run's: $left"
else
    [[ $(ours "$B") == 1 ]] && cell retention-new-name 1 "the old snapshot ${first#*@} was thinned under its new name; left: $left" \
        || cell retention-new-name 0 "the old one went, but now: ${left:-no snapshot at all}"
fi
say "drift after the run that followed Apply: $(jq -c '[.items[]? | {code, name: .params.name}]' "$UB/state/drift.json" 2>/dev/null | head -c 400)"
dr2=$(jq -r --arg a "$A" --arg b "$B" '[.items[]? | select((.params.name // "") == $a or (.params.name // "") == $b or (.params.old // "") == $a) | .code] | join(",")' "$UB/state/drift.json" 2>/dev/null)
[[ -z $dr2 ]] && cell after-apply-quiet 1 "after Apply the run says nothing more of $A or $B" \
    || cell after-apply-quiet 0 "after Apply the run still says of $A/$B: $dr2"
check standard
check final
scenario_end
