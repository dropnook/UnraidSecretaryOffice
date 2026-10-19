#!/bin/bash
# Cell M13 of matrix.md: disk1 asleep (DK-ASLEEP-DISK1) × Ms. Moverelli's list, dry run and real run, and her page's
# levels. Never woken unasked: «List» and «Dry run» leave disk1 asleep (INV-NO-WAKE, disks.ini spundown=1 after); her
# real run names the sleeping disks it wakes before it does (CUSTOMMOVER_DISKS_INI: «array disks asleep: disk1 – this
# run wakes them»); her page's level of disk1 says «not measured», never a number read off a woken disk. QEMU's virtual
# disks never sleep (STATES.md day-1 finding 3; disk_sleep answers «needs») and disks.ini can't be edited for longer
# than ~10 s (emhttpd rewrites it): `needs hdd` — written against the expected layout, runs where a rotating disk is
# passed through. The files: SH-PREFER-ARRAY-FILES (a prefer share with files on disk1 and disk2: her run reads disk1).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-sh-asleep-disk1-run
needs tower hdd
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-c-sleep
_st_mv_configured || _row state moverelli red "Ms. Moverelli isn't set up (the base)"
nc_data_closed
state SH-PREFER-ARRAY-FILES "$S"
nc_data_closed
state DK-ASLEEP-DISK1
track_share "$S"
snap_before
log=$D/moverelli/smart_mover.log
flow MV-LIST
base_asleep disk1 && cell list-no-wake 1 "«List» left disk1 asleep" || cell list-no-wake 0 "disk1 is awake after «List»"
flow MV-DRY
base_asleep disk1 && cell dry-no-wake 1 "«Dry run» left disk1 asleep" || cell dry-no-wake 0 "disk1 is awake after «Dry run»"
ask moverelli.refresh '{}' 120 >/dev/null
lv=$(jq -c '[(.levels // .pool.levels // [])[]? | select((.name // .disk // "") == "disk1")] | first // empty' "$D/moverelli.json" 2>/dev/null)
[[ -n $lv ]] && jq -e '(.measured == false) or (.used == null) or (.asleep == true)' <<<"$lv" >/dev/null \
    && cell levels-not-measured 1 "her page's level of disk1: $lv" || cell levels-not-measured 0 "her page's level of disk1: ${lv:-none}"
bubble_look asleep-levels moverelli
lines0=$(wc -l <"$log" 2>/dev/null || echo 0)
flow MV-RUN
tail -n +"$((lines0 + 1))" "$log" 2>/dev/null | grep -q 'asleep: .*disk1.*wakes' \
    && cell run-names-disk 1 "her real run said it wakes disk1" || cell run-names-disk 0 "her run's log never named disk1 as woken"
allow INV-NO-WAKE "her real run wakes disk1 on purpose — the share's files lie there, and it said so first (cell run-names-disk)"
check standard
scenario_end
