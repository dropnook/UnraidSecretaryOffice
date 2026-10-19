#!/bin/bash
# Cell M26 of matrix.md: the array stopped (SV-ARRAY-STOPPED) when her cron line fires (MV-RUN-SCHED: scripts/job.sh
# moverelli as crond starts it). job.sh doesn't run the job (agent.sh's array_started): no Smart Mover, no php job, no
# night.json and no history line written, nothing under /mnt touched — the night shift alone. Docker's autostart for
# the demo set (CT-AUTOSTART) brings the apps back after ⟦Start⟧. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-array-stopped-sched
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nj0=$(stat -c %Y "$D/moverelli/night.json" 2>/dev/null || echo 0)
h0=$(stat -c '%Y %s' "$D/moverelli/office-history.json" 2>/dev/null)
state CT-AUTOSTART
snap_before
agent_mark_restart
state SV-ARRAY-STOPPED
t0=$(_sc_now)
rc=0; timeout 60 bash -c "cd / && bash $P/scripts/job.sh moverelli" || rc=$?
pids=$(pgrep -af '[c]ustommover_run|[a]gent.php job moverelli' | head -2)
((rc == 0)) && [[ -z $pids ]] && cell job-not-run 1 "job.sh moverelli ended at once (exit $rc), nothing of hers started" \
    || cell job-not-run 0 "job.sh moverelli: exit $rc, at work: ${pids:-nothing}"
array_start SV-ARRAY-STOPPED
agent_wait 300
nc_data_closed
WAIT_SOFT=1 wait_for 90 "Unraid's own at job after the array start" bash -c '[[ -z $(atq) ]]'
[[ $(stat -c %Y "$D/moverelli/night.json" 2>/dev/null || echo 0) == "$nj0" && $(stat -c '%Y %s' "$D/moverelli/office-history.json" 2>/dev/null) == "$h0" ]] \
    && cell nothing-written 1 "night.json and her history as before" || cell nothing-written 0 "night.json or her history written while the array was stopped"
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
scenario_end
