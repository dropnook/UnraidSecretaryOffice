#!/bin/bash
# Cell O2 of matrix.md (batch E): the plugin manager's «Remove» while the office works. During a backup run and during
# Mr. Restori's drill the remove is refused and nothing goes (the plugin's remove script: «The office is busy right now
# … Nothing was removed» — cells refused-backup, refused-drill: the plugin, its code and the agent still there, the run
# and the drill end as they would). Once idle, remove and install again (OF-REMOVE-REINSTALL): the schedules come back
# (the flow waits for every line of the cron file in crond's), the data folder keeps every file, and the partner lines
# go from root's authorized_keys — every other line byte for byte (cell partner-lines: a throwaway key with the comment
# uso-partner:00e0e0e0 put there first). The office kept first and put back by the undo (states-install.sh office_keep /
# office_back). Pre-release (~8 min).
. "$(dirname "$0")/lib.sh"
scenario_begin office-of-remove-reinstall-running
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
KEYS=/boot/config/ssh/root/authorized_keys
installed() { [[ -f /var/log/plugins/unraid-secretary-office.plg && -f $P/agent/agent.php && -f $P/backup/backup.sh ]]; }
office_keep O2 || _row state office_keep red "the office could not be kept"
undo_push office_back O2
# a partner line as the door writes one (a throwaway key — nobody holds its private half after the scenario)
mkdir -p "$KEEP/key" && ssh-keygen -q -t ed25519 -N '' -C uso-wip-e -f "$KEEP/key/k" >/dev/null 2>&1
[[ -s $KEYS && -n $(tail -c 1 "$KEYS") ]] && printf '\n' >>"$KEYS"     # its last line ended (Unraid's file may end without one)
others=$(md5sum <"$KEYS" 2>/dev/null)
printf '%s uso-partner:00e0e0e0\n' "$(cut -d' ' -f1,2 "$KEEP/key/k.pub")" >>"$KEYS"
rm -rf "$KEEP/key"
nc_data_closed
snap_before
pid0=$(_agent_field .pid)
# 1. a backup run at work
minute_wait
if ask backup.start '{"mode":"backup"}' >/dev/null && wait_for 120 "the backup run to be at work" lock_held "$UB/state/lock"; then
    _of_run 'plugin remove unraid-secretary-office.plg'
    installed && lock_held "$UB/state/lock" && cell refused-backup 1 "refused during the run: $(grep -m1 'busy' "$(_of_last_log)" 2>/dev/null)" \
        || cell refused-backup 0 "the remove during a run: plugin $(installed && echo there || echo GONE), run $(lock_held "$UB/state/lock" && echo at work || echo ended) — $(tail -2 "$(_of_last_log)" 2>/dev/null | tr '\n' ' ')"
    wait_backup 1800
    say "the run: $(jq -c '{run, result}' "$UB/state/status.json")"
else
    cell refused-backup 0 "no backup run at work to try it on"
fi
# 2. a drill at work (the page's «Practise now»: plan, then start with its stamp and token)
if ask restore.drill_plan '{}' 300 >/dev/null && [[ $(ask_last '(.preview.blockers // []) | length') == 0 ]]; then
    t0=$(_sc_now)
    ask restore.drill_start "$(ask_last '{stamp: .preview.stamp, token: .preview.token}')" 300 >/dev/null
    if wait_for 120 "the drill to be at work" pgrep -f '[a]gent\.php job restore-drill'; then
        _of_run 'plugin remove unraid-secretary-office.plg'
        installed && pgrep -f '[a]gent\.php job restore-drill' >/dev/null && cell refused-drill 1 "refused during the drill" \
            || cell refused-drill 0 "the remove during a drill: plugin $(installed && echo there || echo GONE) — $(tail -2 "$(_of_last_log)" 2>/dev/null | tr '\n' ' ')"
    else
        cell refused-drill 0 "no drill at work to try it on"
    fi
    wait_drill 1800
else
    cell refused-drill 0 "the drill could not start: $(ask_last '[.preview.blockers[]?.key] | join(",") // .error.key')"
fi
installed && [[ $(_agent_field .pid) == "$pid0" ]] && say "the agent the same (pid $pid0)"
# 3. idle: remove and install again — the schedules come back, the partner line goes, every other line stays
flow OF-REMOVE-REINSTALL
if grep -q 'uso-partner:00e0e0e0' "$KEYS" 2>/dev/null; then
    cell partner-lines 0 "the partner line is still in $KEYS after the remove"
else
    [[ $(md5sum <"$KEYS" 2>/dev/null) == "$others" ]] && cell partner-lines 1 "the partner line gone, every other line byte for byte" \
        || cell partner-lines 0 "the partner line gone, but the other lines changed"
fi
check standard
scenario_end
