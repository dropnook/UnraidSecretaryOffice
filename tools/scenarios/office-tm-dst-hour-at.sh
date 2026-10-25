#!/bin/bash
# The autumn's repeated hour and atd (nostromo, 2026-10-25, Europe/Zurich): at 02:xx CEST `at now` took the minute as its
# second (CET) instance and queued the office's jobs an hour ahead; the agent's requeue of stranded jobs (hostAtRequeue,
# 1.71.1) queued them anew «for now» every 3 s - an hour ahead again, ~5800 log lines in that hour, the jobs late. TM-DST
# autumn, 20 minutes before the switch: 02:40 CEST on 2026-10-25 (Europe/Berlin linked where the zone file is missing,
# as on USOTest3VM). An office job handed to atd by the office's own hostLaunch() (the plugin's code, library mode) must
# run within 2 minutes, in the host's zone (at itself runs in UTC since the fix; the job puts the zone back); the agent
# goes on looking at atd's queue for 90 s more: at most 2 requeue lines, no office job left an hour ahead; the clock and
# the zone are put back (its own cell). $1 = an older release (v1.71.3): the same scenario on that plugin - red.
# timeout: 900
. "$(dirname "$0")/lib.sh"
scenario_begin office-tm-dst-hour-at
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
z0=$(readlink /etc/localtime)
# the zone linked for the state: the agent starts anew in it (restartInPlace — same pid, a new start)
agent_mark_restart
state TM-DST autumn Europe/Berlin 20
say "the server: $(date '+%F %T %Z') ($(date -u '+%T UTC'))"
snap_before
agent_log_mark dst
# the office's jobs in atd's queue: <job> <due epoch> (the spool name's minute - atq prints it in the ambiguous local time)
office_at() {
    local f
    for f in /var/spool/atjobs/[a-z]*; do
        [[ -f $f ]] && grep -q 'unraid-secretary-office\|sc-dst-hour' "$f" 2>/dev/null || continue
        f=${f##*/}
        printf '%d %d\n' "$((16#${f:1:5}))" "$((16#${f:6:8} * 60))"
    done
}
ran=$KEEP/sc-dst-hour.ran
t0=$(date +%s)
job='echo "$(date +%s) $(date +%Z) ${TZ-unset}" >>"$0"'
out=$(php -r 'define("AGENT_LIBRARY_ONLY", true); $argv = ["agent.php"]; $a = $_SERVER["argv"]; require $a[1] . "/agent/agent.php";
    hostLaunch("sc-dst-hour", ["/bin/sh", "-c", $a[3], $a[2]]); echo "handed over\n";' -- "$P" "$ran" "$job" 2>&1 | grep -v 'already defined')
say "hostLaunch at $(date -d "@$t0" '+%T %Z'): ${out:0:300}"
q=$(office_at)
say "the office's jobs in atd's queue right after: ${q:-none}"
wait_for 150 "the job to run" test -s "$ran"
read -r t1 zone tz < <(head -1 "$ran" 2>/dev/null)
((${t1:-0} > 0 && t1 - t0 <= 120)) && cell on-time 1 "ran $((t1 - t0)) s after it was handed over ($(date -d "@$t1" '+%T %Z'))" \
    || cell on-time 0 "handed over at $(date -d "@$t0" '+%T %Z'), ran: ${t1:+$(date -d "@$t1" '+%T %Z')}${t1:-not within 150 s}; queue: ${q:-none}"
[[ $zone == CEST || $zone == CET ]] && [[ $tz == unset ]] && cell host-zone 1 "the job ran in the host's zone ($zone, TZ unset)" \
    || cell host-zone 0 "the job ran with zone ${zone:-?}, TZ=${tz:-?} (expected CEST/CET, TZ unset)"
# the agent looks at atd's queue every few seconds: 90 s more inside the repeated hour
sleep 90 & wait $!
lines=$(agent_log_since dst | grep -c "in atd's queue")
agent_log_since dst | grep "in atd's queue" | head -5 | while IFS= read -r l; do say "agent.log: ${l:0:200}"; done
((lines <= 2)) && cell no-flood 1 "requeue lines in agent.log over $(( $(date +%s) - t0 )) s: $lines" \
    || cell no-flood 0 "requeue lines in agent.log over $(( $(date +%s) - t0 )) s: $lines (the loop of 2026-10-25)"
now=$(date +%s) late=()
while read -r j due; do
    [[ -n $j ]] && ((due > now + 120)) && late+=("$j for $(date -d "@$due" '+%F %T %Z')")
done < <(office_at)
((${#late[@]} == 0)) && cell none-ahead 1 "no office job in atd's queue lies ahead ($(date '+%T %Z'))" \
    || cell none-ahead 0 "office jobs ahead in atd's queue at $(date '+%T %Z'): ${late[*]}"
rm -f "$RUN/sc-dst-hour.sh"
allow INV-LEFTOVER "the clock state's own note and guard (/tmp/uso-clock-*.set, uso-clock-guard.sh — states-time.sh) live while the clock is set; put back right after the checks (cell clock-back)"
check standard
undo_tm_dst
z=$(readlink /etc/localtime)
[[ ! -f /tmp/uso-clock-tm-dst.set && $(date -u +%Y) -ge 2026 && $(date +%s) -gt $(( $(date -d '2026-10-25 01:00 UTC' +%s) + 3600 )) && $z == "$z0" ]] \
    && cell clock-back 1 "the clock back at $(date '+%F %T %Z'), /etc/localtime → $z" \
    || cell clock-back 0 "the clock at $(date '+%F %T %Z'), /etc/localtime → $z (was $z0), note $(ls /tmp/uso-clock-tm-dst.set 2>&1)"
scenario_end
