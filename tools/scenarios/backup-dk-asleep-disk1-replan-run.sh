#!/bin/bash
# Cell B25 of matrix.md (batch E): an array disk asleep (DK-ASLEEP-DISK1) × «Look at the server again» and a run. The
# replan never wakes it and keeps what is saved for the shares on it (setup.msg share_asleep; INV-NO-WAKE: spundown=1
# after), the Apply list names nothing (INV-REPLAN); the run wakes it only as settings.ini says (asleep_pools = wake:
# woken for the snapshot, said; skip: left out and said — bubble.asleep_left), never «failed». QEMU's virtual disks never
# sleep (STATES.md day-1 finding 3: disk_sleep answers «needs», disks.ini can't be edited for longer than ~10 s) —
# `needs hdd`: written against the expected layout, runs where a rotating disk is passed through.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-dk-asleep-disk1-replan-run
needs hdd
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
state DK-ASLEEP-DISK1
snap_before
if flow BK-REPLAN; then
    base_asleep disk1 && cell replan-no-wake 1 "«Look again» left disk1 asleep" || cell replan-no-wake 0 "disk1 is awake after «Look again»"
    jq -e '[.messages[]? | .code] | index("share_asleep")' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
        && cell replan-kept 1 "the plan says share_asleep" || cell replan-kept 0 "no share_asleep: $(jq -c '[.messages[]? | .code] | unique' "$KEEP/plan-replan.json")"
fi
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
jq -e '.result != "failed"' "$st" >/dev/null && cell run-not-failed 1 "the run: $(jq -c '{result, asleep}' "$st")" \
    || cell run-not-failed 0 "the run failed: $(jq -c '{result, message, asleep}' "$st")"
[[ $(_ini_value "$UB/settings.ini" asleep_pools) == skip ]] && expect_bubble backup bubble.asleep_left \
    || allow INV-NO-WAKE "the run wakes disk1 on purpose (asleep_pools = wake, the default: woken for the snapshot)"
check standard
scenario_end
