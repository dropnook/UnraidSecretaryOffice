#!/bin/bash
# Cell B33 of matrix.md: a container whose health check never passes (CT-HEALTH-STARTING: uso-sick, «starting» for
# ever), decided in the setup as one to stop for the backup (docker|known — what the page's Apply sends). The run stops
# it with the apps, starts it again and waits for it to be ready — that wait ends (wait_ready's limit), never an endless
# loop: the run ends with a warning, the others come back in their order (databases before the apps, Nextcloud out of
# maintenance mode). The decision is put back by Apply in the undo. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-health-starting-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
# the decision put back once uso-sick is gone (pushed before the state: runs after its undo) — while it is still there
# a new plan holds it «keep running until you decide» and Apply would write it into docker|no_stop
was=$(_ini_flat "$UB/settings.ini" | sed -n 's/^docker|known=//p' | jq -R . | jq -sc .)
undo_push flow BK-APPLY "docker|known=$was"
state CT-HEALTH-STARTING
flow BK-REPLAN
plan=$KEEP/plan-replan.json
known=$(jq -c '.P["docker|known"] // []' "$plan")
flow BK-APPLY plan=old "docker|known=$(jq -c '. + ["uso-sick"] | unique' <<<"$known")"
grep -q '^known = uso-sick' "$UB/settings.ini" && say "uso-sick decided: stopped for the backup" || _row state decided red "uso-sick not in docker|known after Apply"
before_ct=$(timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort)
snap_before
t0=$(_sc_now)
flow BK-RUN-MANUAL mode=backup
dt=$(($(_sc_now) - t0))
run=$(jq -r .run "$UB/state/status.json")
log=$UB/logs/run-$run.log
evidence "$log" run.log >/dev/null 2>&1
r=$(jq -r '"\(.result) w=\(.warnings)"' "$UB/state/status.json")
((dt < 900)) && cell run-ends 1 "the run ended in $dt s: $r" || cell run-ends 0 "the run took $dt s: $r"
grep -n 'uso-sick' "$log" 2>/dev/null | tail -3 | sed 's/^/  /' | while read -r l; do say "log: ${l:0:200}"; done
now_ct=$(timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort)
miss=$(comm -23 <(printf '%s\n' "$before_ct") <(printf '%s\n' "$now_ct") | paste -sd' ' -)
[[ -z $miss ]] && cell all-back 1 "every container that ran runs again (uso-sick included, still «starting»)" || cell all-back 0 "not running after the run: $miss"
db=$(grep -n 'Containers started\|started: \|Starting' "$log" | head -5 | paste -sd' ' -)
say "the run's start order: ${db:0:300}"
expect_warnings not_ready container_not_ready ready_timeout
check standard
check final
scenario_end
