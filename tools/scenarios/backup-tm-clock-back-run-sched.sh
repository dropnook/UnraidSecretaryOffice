#!/bin/bash
# Cell B43 of matrix.md (batch E), TM-CLOCK-BACK: a run as crond starts it (BK-RUN-SCHED), then the clock jumps back two
# hours (an NTP correction after a boot without RTC, ntpd stopped, put back by the undo), then the next scheduled run.
# The second run works (its name is older than the first's: no collision — cell second-ok), the first run's snapshots
# are still there (the retention counts by what it keeps, not «newest name first» — cell first-kept), «last run» is
# the run just made and never in the future (cell last-is-new) — files written meanwhile carry future times after the
# undo (STATES.md), INV-TICK and INV-PAGE look at the page with them. Pre-release (~5 min).
# run-late: 3 the run under the set-back clock reads as «last» until the next run
. "$(dirname "$0")/lib.sh"
scenario_begin backup-tm-clock-back-run-sched
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
snaps() { find /mnt/*/.btrfs-snap -mindepth 1 -maxdepth 1 -name "$1" 2>/dev/null | wc -l; zfs list -H -t snapshot -o name 2>/dev/null | grep -c "@uso-backup-$1\$"; }
nc_data_closed
snap_before
flow BK-RUN-SCHED
r1=$(jq -r '.run // ""' "$UB/state/status.json")
n1=$(snaps "$r1" | awk '{s+=$1} END{print s}')
say "the first run $r1: $n1 snapshot(s)"
state TM-CLOCK-BACK 2
flow BK-RUN-SCHED
st=$UB/state/status.json
r2=$(jq -r '.run // ""' "$st")
jq -e '(.result == "ok" or .result == "warnings") and (.errors // 0) == 0' "$st" >/dev/null && [[ $r2 < $r1 ]] \
    && cell second-ok 1 "the second run $r2 (two hours before $r1): $(jq -c '{result, warnings}' "$st")" \
    || cell second-ok 0 "the second run $r2: $(jq -c '{result, errors, warnings, message}' "$st")"
n1b=$(snaps "$r1" | awk '{s+=$1} END{print s}')
(( n1b == n1 && n1 > 0 )) && cell first-kept 1 "the first run's $n1 snapshot(s) still there" || cell first-kept 0 "the first run's snapshots: $n1 → $n1b"
ask backup.refresh '{}' 120 >/dev/null
last=$(state_json backup '.status.run // empty'); s=$(state_json backup '.status.started // 0')
[[ $last == "$r2" ]] && (( s <= $(date +%s) )) && cell last-is-new 1 "«last run» is $r2, started $(( $(date +%s) - s )) s ago" \
    || cell last-is-new 0 "«last run» is ${last:-?} (started $s, now $(date +%s)), the run just made is $r2"
allow INV-LEFTOVER "the clock state's own note and guard (/tmp/uso-clock-*.set, uso-clock-guard.sh — states-time.sh) live while the clock is set; its undo removes them (INV-LEFTOVER after the undo)"
check standard
scenario_end
