#!/bin/bash
# Cell B48 of matrix.md: «Stop the run» (BK-ABORT) while the run works with Nextcloud in maintenance mode and the apps
# paused (8 s in). Everything comes back: every container that ran runs again, Nextcloud out of maintenance mode
# (INV-NC-MAINT-OFF), nothing left noted for a --recover, nothing mounted; the run «aborted» (never failed) on his
# page, and the next run is clean. The matrix's «VM paused» part needs the VM service (USOTest4 has none): needs vm,
# not run here. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-rn-abort-nc-maint-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
# the base again when the office left Nextcloud in maintenance mode (its checks are red then): base.sh doesn't reset it, and
# every later run would refuse («already in maintenance mode») — no helper for it, occ as Nextcloud's own user
nc_maint_off() { timeout 60 docker exec -u 33 Nextcloud php occ maintenance:mode 2>/dev/null | grep -qi enabled || return 0
    say "undo: Nextcloud was left in maintenance mode — switched off"; timeout 60 docker exec -u 33 Nextcloud php occ maintenance:mode --off >/dev/null; }
undo_push nc_maint_off
before_ct=$(timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort)
snap_before
# the abort comes 8 s after the lock is taken: the engine has Nextcloud in maintenance mode by then (log: +1 s)
flow BK-ABORT after=8
st=$UB/state/status.json
run=$(jq -r '.run // ""' "$st")
evidence "$UB/logs/run-$run.log" run-aborted.log >/dev/null 2>&1
grep -q "maintenance mode on" "$UB/logs/run-$run.log" 2>/dev/null && cell nc-was-in-maintenance 1 "the run had Nextcloud in maintenance mode before the abort" \
    || cell nc-was-in-maintenance 0 "the run's log shows no maintenance mode before the abort — the abort came too early or too late"
o=$(state_json backup '"\(.status.result // "")/\(.status.message // "")"' 2>/dev/null)
h=$(state_json backup '[.history[]? | select(.run == "'"$run"'") | .result] | join(",")' 2>/dev/null)
[[ $o == aborted/* && $h == aborted ]] && cell office-says 1 "his page: run $run «$o», history «$h»" || cell office-says 0 "his page: run $run «$o», history «$h»"
now_ct=$(timeout 20 docker ps --filter status=running --format '{{.Names}}' | sort)
miss=$(comm -23 <(printf '%s\n' "$before_ct") <(printf '%s\n' "$now_ct") | paste -sd' ' -)
[[ -z $miss ]] && cell back 1 "every container that ran runs again ($(wc -l <<<"$before_ct"))" || cell back 0 "not running after the abort: $miss"
left=$(cat "$UB/state/stopped" "$UB/state/maintenance" "$UB/state/vms" 2>/dev/null | sed '/^$/d' | paste -sd' ' -)
[[ -z $left ]] && cell nothing-noted 1 "nothing left noted for a --recover" || cell nothing-noted 0 "still noted after the abort: $left"
check standard
check final
scenario_end
