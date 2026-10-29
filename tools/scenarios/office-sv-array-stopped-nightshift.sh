#!/bin/bash
# Cell O4 of matrix.md (wave 3): the array stopped (SV-ARRAY-STOPPED: ⟦Stop⟧ on Unraid's Main page) with every desk of
# the base hired — the agent hands over to the night watchman's night shift (agent.php nightShift(): RAM and flash
# only). What must hold:
#   stop-fast    the stop ends within 120 s (nothing of the office holds /mnt: the doorbell and heartbeat live in RAM)
#   shift-on     within a minute the night shift is at work: its pid in nightshift.lock, `agent.php nightshift`, never
#                together with the agent
#   shift-cwd    its cwd is /, it holds nothing under /mnt (INV-NO-MNT-OPEN while stopped)
#   shift-rounds its log in RAM says it began (the watchman's baseline from his mirror) — a night shift that does nothing
#                is no night shift
#   shift-ends   ⟦Start⟧: the night shift ends («the array is started — the agent takes over»), the agent comes back with
#                a fresh heartbeat within 2 min, no second agent and no night shift left
# Unraid starts the array again in the undo when a cell fails half way. Pre-release candidate (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin office-sv-array-stopped-nightshift
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
LOCK=$RUN/nightshift.lock
NLOG=$RUN/nightshift/nightshift.log
shift_pid() { local p; p=$(head -c 20 "$LOCK" 2>/dev/null | tr -dc 0-9); [[ -n $p ]] && kill -0 "$p" 2>/dev/null && tr '\0' ' ' <"/proc/$p/cmdline" | grep -q 'agent.php nightshift' && echo "$p"; }
nlines0=$(wc -l <"$NLOG" 2>/dev/null || echo 0)
snap_before
agent_mark_restart
t0=$(_sc_now)
state SV-ARRAY-STOPPED
secs=$(($(_sc_now) - t0))
[[ $(_st_var mdState) == STOPPED ]] || { _row state SV-ARRAY-STOPPED red "mdState=$(_st_var mdState)"; scenario_end; }
((secs <= 120)) && cell stop-fast 1 "the array stopped in $secs s" || cell stop-fast 0 "the array stop took $secs s — holding /mnt: $(_st_mnt_holders)"
if wait_for 90 "the night shift at work" bash -c "$(declare -f shift_pid); LOCK='$LOCK'; [[ -n \$(shift_pid) ]]"; then
    ns=$(shift_pid)
    ag=$(pgrep -f '[a]gent\.php run' | paste -sd' ' -)
    [[ -z $ag ]] && cell shift-on 1 "night shift pid $ns, no agent beside it" || cell shift-on 0 "night shift pid $ns AND an agent ($ag)"
    cwd=$(readlink "/proc/$ns/cwd")
    open=$(ls -l "/proc/$ns/fd" 2>/dev/null | awk '{print $NF}' | grep '^/mnt/' | head -3 | paste -sd' ' -)
    [[ $cwd == / && -z $open ]] && cell shift-cwd 1 "cwd /, nothing under /mnt open" || cell shift-cwd 0 "cwd $cwd, open: ${open:-nothing}"
    sleep 20
    new=$(tail -n +"$((nlines0 + 1))" "$NLOG" 2>/dev/null)
    evidence "$NLOG" nightshift.log >/dev/null
    grep -q 'Night shift begins' <<<"$new" && cell shift-rounds 1 "$(grep -m1 'Night shift begins' <<<"$new" | cut -c1-200)" \
        || cell shift-rounds 0 "its log since the stop: $(tail -3 <<<"$new" | tr '\n' ' ' | cut -c1-300)"
else
    cell shift-on 0 "no night shift 90 s after the stop (nightshift.lock: $(head -c 20 "$LOCK" 2>/dev/null)); agent: $(pgrep -af '[a]gent\.php' | head -2 | tr '\n' ' ')"
fi
check_inv_no_mnt_open >"$KEEP/no-mnt-open.txt" 2>&1; say "INV-NO-MNT-OPEN while stopped: $(cat "$KEEP/no-mnt-open.txt")"
# ⟦Start⟧ — the undo would do it; here it is the cell's flow
array_start SV-ARRAY-STOPPED
if agent_wait 180; then
    sleep 5
    ag=$(pgrep -f '[a]gent\.php run' | wc -l)
    left=$(shift_pid)
    ends=$(tail -n +"$((nlines0 + 1))" "$NLOG" 2>/dev/null | grep -m1 'Night shift ends' | cut -c1-200)
    [[ $ag == 1 && -z $left && -n $ends ]] && cell shift-ends 1 "$ends; one agent at work" \
        || cell shift-ends 0 "agents: $ag, night shift left: ${left:-none}, its end: ${ends:-not logged}"
else
    cell shift-ends 0 "no agent at work 3 min after ⟦Start⟧"
fi
check standard
scenario_end
