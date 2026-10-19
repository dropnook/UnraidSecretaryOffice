#!/bin/bash
# Cell B13 of matrix.md: the pool that holds the backup place is (nearly) full — 1 GiB left on cache, below the place's
# [general] min_free_gb (8 GB in the base). The run must stop before any package or snapshot is begun (no half package
# in the place, no snapshot of its minute), with a code the office translates (die_code → backup.message.<code>, the
# space in its params), Nextcloud out of maintenance mode and the apps running again; his page names the space. The
# fill is a refreservation on a dataset of its own (DK-POOL-FULL, nothing written); it goes before INV-BACKUP-AFTER.
# 1 GiB (not the state's 200 MiB): Docker's image and the office's state files live on the same pool. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-dk-pool-full-run
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
_ct_note_now() { timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort; }
before_ct=$(_ct_note_now)
state DK-POOL-FULL cache 1024
snap_before
_bk_setup_free && minute_wait
ask backup.start '{"mode":"backup"}' >/dev/null
expect_ok backup.start
WAIT_SOFT=1 wait_for 60 "the run to be at work" lock_held "$UB/state/lock" && bubble_look mid-backup backup
wait_backup 1800
st=$UB/state/status.json
run=$(jq -r '.run // ""' "$st")
r=$(jq -r '.result // ""' "$st") m=$(jq -r '.message // ""' "$st")
evidence "$UB/logs/run-$run.log" run-full.log >/dev/null 2>&1
[[ $r == failed ]] && cell refused 1 "run $run ended failed before the packages (the place has too little room)" \
    || cell refused 0 "run $run ended «$r» on a pool with 1 GiB free (min_free_gb 8)"
# a code, translated on his page in all five languages — the engine's English text is no code (die, not die_code)
if [[ $m =~ ^[a-z][a-z0-9_]*$ ]] && jq -e --arg k "message.$m" 'has($k)' "$P/public/desks/backup/lang/en.json" >/dev/null; then
    cell code 1 "status.json message «$m» (backup.message.$m)"
else
    cell code 0 "status.json message is no code: «${m:0:160}» — his page shows the engine's English sentence as it is (desk.js messageText: no backup.message.<code>)"
fi
n=$(zfs list -H -t snapshot -o name 2>/dev/null | grep -c "@uso-backup-${run}\$")
((n == 0)) && cell no-snapshot 1 "no snapshot uso-backup-$run" || cell no-snapshot 0 "$n snapshot(s) uso-backup-$run made although the run was refused"
half=$(find "$PLACE" -maxdepth 3 \( -name '.ub-stage*' -o -name '.ub-new*' -o -name '*.new-*' -o -name '.ub-old-*' \) 2>/dev/null | head -3)
[[ -z $half ]] && cell no-half-package 1 "no package begun in $PLACE" || cell no-half-package 0 "begun and left: ${half//$'\n'/ }"
now_ct=$(_ct_note_now)
miss=$(comm -23 <(printf '%s\n' "$before_ct") <(printf '%s\n' "$now_ct") | paste -sd' ' -)
[[ -z $miss ]] && cell apps-back 1 "every container that ran runs again" || cell apps-back 0 "not running after the refused run: $miss"
check standard
pool_unfill DK-POOL-FULL                     # the room back before the run INV-BACKUP-AFTER makes (the undo then has nothing to do)
check final
scenario_end
