#!/bin/bash
# Batch H, finding 4 (parking lot «Resilienz-Funde», H2's wave 1 on Tower): the ZFS pool that holds an app's data is
# full (the backup place elsewhere has room — B13 is the place full). Mr. Backupsy stops the app for its snapshot and
# starts it again on a pool with no room left: an app that writes on start (a database, a lock, a log) fails or
# corrupts there; on Tower Docker itself lives on that pool and an image pull left broken layers. Expected: before he
# stops an app whose data lies on a full pool he refuses or warns — the app untouched (never stopped) or a warning with
# a code about the pool's room in the run (cell warns-before-stop); either way it runs again after the run (cell
# app-back). The app: uso-wip-h-dkapp (alpine, sleep) with its data in the scratch share uso-wip-h-dk (only on the ZFS
# pool «fast»), decided «stop it for the snapshot» by Apply (the page's draft with uso-wip-h-dkapp out of no_stop); the
# pool filled to 200 MiB free by a refreservation (DK-POOL-FULL, nothing written). Not here: Docker's own folder on the
# full pool (USOTest3VM keeps docker.img on the btrfs cache with the backup place — that is B13's state). settings.ini
# put back from its copy. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-dk-app-pool-full-h-run
needs docker zfs pool2
S=uso-wip-h-dk POOL=fast APP=uso-wip-h-dkapp DS=fast/uso-wip-h-dk
mkdir -p "$KEEP_STASH/H4-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/H4-INI/settings.ini"
undo_push bash -c 'mv -f "$1/settings.ini" "$2" && rmdir "$1"' _ "$KEEP_STASH/H4-INI" "$UB/settings.ini"
undo_push h_ds_destroy "$DS"
undo_push h_share_drop "$S"
undo_push h_snaps_drop "$DS"
undo_push bash -c 'find "/mnt/$1/$2" -mindepth 1 -delete 2>/dev/null; exit 0' _ "$POOL" "$S"
nc_data_closed
share_make "$S" only "$POOL" || _row state share_make red "Unraid's «Add Share» made no $S on $POOL"
nc_data_closed
mkdir -p "/mnt/$POOL/$S/db" && chown -R nobody:users "/mnt/$POOL/$S"
ct_make H4 "$APP" alpine:latest -v "/mnt/user/$S/db:/data" -- sleep 1d || _row state ct_make red "no $APP"
undo_push ct_unmake H4
undo_push bash -c '[[ -n $1 && -d $1 ]] && rm -rf -- "$1"; exit 0' _ "$PLACE/apps/$APP"
flow BK-REPLAN
plan=$KEEP/plan-replan.json
ns=$(jq -c --arg a "$APP" '(.P["docker|no_stop"] // []) | if type == "array" then . else [.] end | map(select(. != $a))' "$plan")
kn=$(jq -c --arg a "$APP" '(.P["docker|known"] // []) | if type == "array" then . else [.] end | . + [$a] | unique' "$plan")
flow BK-APPLY plan=old "docker|no_stop=$ns" "docker|known=$kn" "share|$S|mode=snapshot" || _row state prepare red "Apply failed"
grep -qx "no_stop = $APP" "$UB/settings.ini" && _row state prepare red "$APP still in no_stop after Apply"
state DK-POOL-FULL "$POOL" 200
say_cmd zfs list -o name,used,avail,refreservation "$POOL"
track_share "$S"
snap_before
t0=$(docker inspect -f '{{.State.StartedAt}}' "$APP")
minute_wait
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
run=$(jq -r '.run // ""' "$st")
evidence "$UB/logs/run-$run.log" run.log >/dev/null
r=$(jq -r '"\(.result) \(.message // "")"' "$st")
t1=$(docker inspect -f '{{.State.StartedAt}}' "$APP")
codes=$(_ck_warning_codes | paste -sd' ' -)
room=$(_ck_warning_codes | grep -iE 'space|full|room|free' | paste -sd' ' -)
stopped=no; [[ $t1 != "$t0" ]] && stopped=yes
grep -nE "$APP" "$UB/logs/run-$run.log" | head -5 >"$KEEP/app-lines.txt"; evidence "$KEEP/app-lines.txt" >/dev/null
say "the run: $r · warnings: ${codes:-none} · $APP stopped and started again: $stopped ($t0 → $t1)"
if [[ $stopped == no ]]; then
    h_cell warns-before-stop 1 "the run left $APP untouched on the full pool (run: $r)"
elif [[ -n $room ]]; then
    h_cell warns-before-stop 1 "$APP stopped, but the run warned about the room: $room"
else
    h_cell warns-before-stop 0 "$APP stopped and started on «$POOL» with $(zfs get -Hp -o value available "$POOL" | awk '{printf "%d MiB", $1/1048576}') free — no word about the full pool (run: $r; warnings: ${codes:-none})"
fi
[[ $(ct_status "$APP") == running ]] && h_cell app-back 1 "$APP runs after the run" || h_cell app-back 0 "$APP is $(ct_status "$APP") after the run"
check standard
scenario_end
