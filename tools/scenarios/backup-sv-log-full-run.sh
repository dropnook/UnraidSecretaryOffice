#!/bin/bash
# Cell B47 of matrix.md (wave 3), the log half: /var/log full (SV-LOG-FULL: one big file, 64 KB left — the RAM disk a
# chatty container or Netdata's nginx look fills in the wild). What must hold:
#   run-ok       «Back up now» ends as it would (ok or warnings): the engine logs into its own folder, a full /var/log
#                never fails a run
#   page-answers his and the Team Lead's looks still answer (a full /var/log breaks no request: php-fpm, nginx)
#   protocolli   Ms. Protocolli's watch of /var/log sees it full and names the file that fills it (/var/log/uso-fill)
#                — the one desk whose job that is (logs-flood.php)
#   agent-up     the agent's heartbeat stays fresh
# The file goes with the undo. Pre-release candidate (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-log-full-run
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state SV-LOG-FULL
say_cmd df -h /var/log
snap_before
if flow BK-RUN-MANUAL mode=backup; then
    cell run-ok 1 "the run ended $(jq -r '.result' "$UB/state/status.json") with /var/log full"
else
    cell run-ok 0 "the run ended $(jq -c '{result, message}' "$UB/state/status.json" 2>/dev/null) with /var/log full"
fi
ok=1 why=()
for d in backup caretaker logs; do
    php "$SC_DIR/ask.php" --wait 120 "$d.refresh" '{}' >"$KEEP/refresh-$d.json" 2>&1 && jq -e '.ok' "$KEEP/refresh-$d.json" >/dev/null 2>&1 \
        || { ok=0; why+=("$d: $(head -c 160 "$KEEP/refresh-$d.json")"); }
done
((ok)) && cell page-answers 1 "backup, caretaker and logs answer their looks" || cell page-answers 0 "${why[*]}"
# her watch runs on its own clock (LOGS_FLOOD_EVERY): given two minutes to see it
named() { grep -rlsF '/var/log/uso-fill' "$D"/logs*.json "$D"/logs "$RUN"/logs* 2>/dev/null | head -1; }
if wait_for 180 "Ms. Protocolli to name /var/log/uso-fill" bash -c "$(declare -f named); D='$D' RUN='$RUN'; [[ -n \$(named) ]]"; then
    cell protocolli 1 "her watch names /var/log/uso-fill (in $(named))"
else
    cell protocolli 0 "after 3 min her state names no /var/log/uso-fill: $(jq -c '.varlog // .flood // empty' "$D/logs.json" 2>/dev/null | head -c 300)"
fi
bubble_look log-full backup logs caretaker
age=$(( $(_sc_now) - $(stat -c %Y "$RUN/agent.json" 2>/dev/null || echo 0) ))
((age < 60)) && cell agent-up 1 "heartbeat ${age}s old" || cell agent-up 0 "heartbeat ${age}s old"
# Ms. Protocolli's one notification at ≥ 90 % (varlog_full) is the cell's expected word — INV-NOTIFY-ONCE counts it once
check standard
check final
scenario_end
