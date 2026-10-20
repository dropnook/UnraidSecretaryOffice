#!/bin/bash
# Cell B43 of matrix.md (batch E), TM-YEAR-ROLL: the clock two minutes before New Year (2026-12-31 23:58, ntpd stopped,
# put back by the undo), a run as crond starts it in the old year and one in the new (BK-RUN-SCHED twice): both run
# (cells runs-ok), named 20261231-… and 20270101-…, the retention by month and year keeps the last run of the old year
# (cell year-kept: its snapshots still there after the new year's run). Needs a licence valid past 2027-01-01: Unraid's
# trial ends with a date — a clock beyond it may end the trial on this server (USOTest3VM runs a trial: skipped there).
# Nightly (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-tm-year-roll-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
snaps() { { find /mnt/*/.btrfs-snap -mindepth 1 -maxdepth 1 -name "$1" 2>/dev/null; zfs list -H -t snapshot -o name 2>/dev/null | grep "@uso-backup-$1\$"; } | wc -l; }
exp=$(_ini_value /var/local/emhttp/var.ini regTm2)
if [[ $(_ini_value /var/local/emhttp/var.ini regTy) == Trial ]] && (( ${exp:-0} < $(date -d '2027-01-02' +%s) )); then
    printf 'a licence valid past 2027-01-01 (this server runs a trial that ends %s)\n' "$(date -d @"${exp:-0}" +%F)" >"$KEEP/.skip"
    say "skipped: the clock past the trial's end may end it"
    scenario_end
fi
nc_data_closed
snap_before
state TM-YEAR-ROLL 2026
flow BK-RUN-SCHED
r1=$(jq -r '.run // ""' "$UB/state/status.json"); n1=$(snaps "$r1")
wait_for 300 "the new year" bash -c '[[ $(date +%Y) == 2027 ]]'
flow BK-RUN-SCHED
r2=$(jq -r '.run // ""' "$UB/state/status.json")
[[ $r1 == 20261231-* && $r2 == 20270101-* ]] && cell runs-ok 1 "$r1 and $r2" || cell runs-ok 0 "the runs: $r1, $r2"
(( $(snaps "$r1") == n1 && n1 > 0 )) && cell year-kept 1 "the old year's last run $r1 keeps its $n1 snapshot(s)" || cell year-kept 0 "$r1's snapshots: $n1 → $(snaps "$r1")"
check standard
scenario_end
