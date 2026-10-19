#!/bin/bash
# Cell B31 of matrix.md — nightly: 100 000 small files in one share (SH-100K) through «Look at the server again» with
# measuring, and a run with the share at «local + Kopia» (Kopia walks every file). Measured and held: the replan within
# 15 min, the run within 45 min, the agent's heartbeat steady throughout (INV-TICK), the page's time estimate while the
# run is at work a number (backup.json estimates/rough sampled every 10 s — never null-then-garbage), the share's size
# measured in the plan. The times go into the report (README «Batch C»). Scratch share uso-wip-c-100k (only cache: a
# dataset). Preparation (before snap_before): Apply of the share; settings.ini put back by the undo, the scratch
# dataset's engine snapshots destroyed before Unraid removes it. Nightly (~20 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-100k-run
needs tower zfs kopia
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-100k
snaps_drop() { local x; zfs list -H -t snapshot -o name "cache/$S" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done; }
mkdir -p "$KEEP_STASH/C-B31-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-B31-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-B31-INI" "$UB/settings.ini"
nc_data_closed
share_use C-B31 "$S" only cache || _row state share red "$S could not be made"
undo_push share_unuse C-B31
state SH-100K "$S"
nc_data_closed
undo_push snaps_drop
flow BK-APPLY "share|$S|mode=kopia" || _row state prepare red "Apply of $S as local + Kopia failed"
expect_changes "share|UnraidSecretaryOffice|mode"     # derived by the page with a share's Kopia (desk.js setupDerive()), not by the flow's body
track_share "$S"
snap_before
t0=$SECONDS
flow BK-REPLAN measure=true
tr=$((SECONDS - t0))
gb=$(jq -r --arg s "$S" '.shares[]? | select(.name == $s) | .gb // "null"' "$KEEP/plan-replan.json" 2>/dev/null)
((tr <= 900)) && cell replan-time 1 "«Look again» with measuring took $tr s (share size: $gb GB)" || cell replan-time 0 "«Look again» with measuring took $tr s (> 900)"
[[ $gb != null && -n $gb ]] && cell measured 1 "the plan measured $S: $gb GB" || cell measured 0 "the plan has no size for $S"
# the page's estimate while the run is at work: sampled
est=$KEEP/estimates.jsonl
( while :; do sleep 3; lock_held "$UB/state/lock" || { [[ -s $est ]] && break; continue; }
    php "$SC_DIR/ask.php" --wait 30 backup.refresh '{}' >/dev/null 2>&1
    jq -c --arg t "$(date +%s)" '{t: ($t | tonumber), rough, total: (.estimates.total // null), phase: (.status.phase // .step // null)}' "$D/backup.json" >>"$est" 2>/dev/null
    ((SECONDS - t0 > 3600)) && break; done ) &
smp=$!
t1=$SECONDS
flow BK-RUN-MANUAL mode=backup
trun=$((SECONDS - t1))
kill "$smp" 2>/dev/null; wait "$smp" 2>/dev/null
evidence "$est" >/dev/null
((trun <= 2700)) && cell run-time 1 "the run took $trun s" || cell run-time 0 "the run took $trun s (> 2700)"
# Kopia really walked the 100 000 (its snapshot's own file count)
fc=$(timeout 120 docker exec kopia kopia snapshot list --all --json 2>/dev/null | jq -r --arg s "$S" '[.[] | select((.source.path // "") | endswith("/" + $s))] | sort_by(.startTime) | last | .stats.fileCount // empty')
[[ $fc == 100000 ]] && cell kopia-files 1 "Kopia's snapshot of $S holds 100000 files" || cell kopia-files 0 "Kopia's snapshot of $S: ${fc:-none} files (want 100000)"
n=$(wc -l <"$est" 2>/dev/null || echo 0); bad=$(jq -s '[.[] | select(((.rough // .total) | type) as $t | ($t != "null" and $t != "number" and $t != "object"))] | length' "$est" 2>/dev/null)
any=$(jq -s '[.[] | select(.rough != null or .total != null)] | length' "$est" 2>/dev/null)
((n > 0 && ${bad:-1} == 0)) && cell estimate 1 "$n samples while at work, ${any:-0} with an estimate (rough or from history), none garbled" \
    || cell estimate 0 "$n samples, ${bad:-?} garbled: $(head -c 300 "$est" 2>/dev/null)"
check standard
check final
scenario_end
