#!/bin/bash
# Cell B30 of matrix.md: an exclusive share (SH-EXCLUSIVE: /mnt/user/uso-wip-c-ex is a symlink to the pool's dataset
# cache/uso-wip-c-ex) holding an app's folder (the scratch app uso-wip-c-exapp binds /mnt/user/uso-wip-c-ex/app — the
# symlink path, as a template would).
# - BK-RUN: the share's snapshot through the symlink path; the app's package written; no .uso-backup at the share's top
#   (it belongs only inside an app's or VM's own dataset — B4's bug was one in share roots).
# - BK-DATASET-MAKE («Own dataset…» for app/): cache/uso-wip-c-ex/app made, the files the same, the old folder in the
#   storeroom, the app running again; the symlink still the share.
# - BK-RUN again: now .uso-backup inside the app's own dataset only, never at the share's top.
# Preparation (before snap_before): the share set to «local» by Apply and one run; settings.ini put back by the undo,
# the scratch datasets' engine snapshots and the app's dataset destroyed before Unraid removes the share. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-exclusive-run
needs tower zfs docker exclusive
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-ex APP=uso-wip-c-exapp
scratch_drop() { local x; [[ $S == uso-wip-c-* ]] || return 1
    zfs list -H -r -t snapshot -o name "cache/$S" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    zfs list -H "cache/$S/app" >/dev/null 2>&1 && { zfs destroy -r "cache/$S/app" && say "undo: destroyed cache/$S/app"; }
    return 0; }
mkdir -p "$KEEP_STASH/C-B30" && cp -p "$UB/settings.ini" "$KEEP_STASH/C-B30/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/C-B30" "$UB/settings.ini"
nc_data_closed
state SH-EXCLUSIVE "$S"
put_files C-B30 cache "$S/app" 6 --size 64K || _row state files red "app/ could not be written"
undo_push files_unput C-B30
nc_data_closed
undo_push scratch_drop
ct_make C-B30 "$APP" alpine:latest -v "/mnt/user/$S/app:/config" -- sleep 86400 || _row state app red "$APP could not be made"
undo_push ct_unmake C-B30
flow BK-APPLY "share|$S|mode=snapshot" || _row state prepare red "Apply of $S as local failed"
expect_changes 'docker|*'                    # the scratch app is a new container for Mr. Backupsy's setup
track_share "$S"
snap_before
# INV-NOTIFY-ONCE takes one subject twice within 60 s as one event told twice (one run a minute): the runs inside the
# flow end ≥ 61 s apart, each run's notification its own event
last_end=0
run_spaced() { local w=$((last_end + 61 - $(date +%s))); ((w > 0)) && { say "waiting $w s: the previous run's notification ≥ 61 s before this one's"; sleep "$w"; }
    flow BK-RUN-MANUAL mode=backup; local rc=$?; last_end=$(date +%s); return $rc; }
tops() { find "/mnt/cache/$S" -name .uso-backup -prune -print 2>/dev/null | sed "s|^/mnt/cache/$S|.|" | sort | paste -sd' '; }
# 1. the run through the symlink path
run_spaced
zfs list -H -t snapshot -o name "cache/$S" 2>/dev/null | grep -q '@uso-backup-' && cell snapshot 1 "cache/$S has the run's snapshot" \
    || cell snapshot 0 "no engine snapshot of cache/$S after the run"
[[ -f $PLACE/apps/$APP/manifest.json ]] && cell package 1 "$APP's package is on the place" || cell package 0 "no package of $APP in $PLACE/apps"
t=$(tops)
[[ -z $t ]] && cell no-uso-backup-top 1 "no .uso-backup in the share (app/ is a folder, not a dataset)" \
    || cell no-uso-backup-top 0 ".uso-backup in the exclusive share: $t"
# 2. «Own dataset…» for app/
sum=$(cd "/mnt/cache/$S/app" && find . -type f ! -path './.uso-backup*' -printf '%P %s\n' | sort | md5sum)
flow BK-DATASET-MAKE kind=app name="$APP" share="$S" dir=app
if zfs list -H "cache/$S/app" >/dev/null 2>&1 && [[ $(zfs get -H -o value mountpoint "cache/$S/app") == /mnt/cache/$S/app ]]; then
    now=$(cd "/mnt/cache/$S/app" && find . -type f ! -path './.uso-backup*' -printf '%P %s\n' | sort | md5sum)
    [[ $now == "$sum" ]] && cell dataset-made 1 "cache/$S/app is a dataset now, the same files" || cell dataset-made 0 "cache/$S/app made, but its files differ"
else
    cell dataset-made 0 "no dataset cache/$S/app after «Own dataset…» ($(ask_last '.error // empty'))"
fi
[[ -L /mnt/user/$S && $(readlink "/mnt/user/$S") == "/mnt/cache/$S" ]] && cell symlink-kept 1 "/mnt/user/$S still the symlink to the pool" \
    || cell symlink-kept 0 "/mnt/user/$S: $(stat -c %F "/mnt/user/$S" 2>&1) → $(readlink "/mnt/user/$S")"
[[ $(ct_status "$APP") == running ]] && cell app-runs 1 "$APP runs again" || cell app-runs 0 "$APP is $(ct_status "$APP")"
# 3. the next run: .uso-backup only inside the app's own dataset
run_spaced
t=$(tops)
[[ $t == ./app/.uso-backup ]] && cell uso-backup-inside 1 "the only .uso-backup is inside the app's dataset (app/.uso-backup)" \
    || cell uso-backup-inside 0 ".uso-backup in the share after the run: «${t:-none}» (want only ./app/.uso-backup)"
check standard
check final
scenario_end
