#!/bin/bash
# Cell B18 of matrix.md (batch E; finding 9 of 2026-10-16): a share's cfg names a pool that isn't there
# (SH-CFG-POOL-GONE: uso-only's shareCachePool="big", the array restarted — writes through /mnt/user/uso-only end with
# ENOMEDIUM, «No medium found»). «Look at the server again» names the cause for uso-only (a message that mentions the
# share or the pool «big» — cell replan-cause), never a «?» (INV-PAGE); a run goes on with the other shares and says
# what happened to uso-only in words (cell run-cause), never a whole failed run for one share. Two array restarts (state
# and undo). Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-cfg-pool-gone-replan-run
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-only
nc_data_closed
state SH-CFG-POOL-GONE "$S" big
nc_data_closed
agent_wait 300
track_share "$S"
snap_before
# writing through the user share now: Unraid's own answer (the state's proof)
e=$(touch "/mnt/user/$S/.uso-wip-e-probe" 2>&1; rm -f "/mnt/user/$S/.uso-wip-e-probe")
say "a write to /mnt/user/$S: ${e:-it worked}"
if flow BK-REPLAN; then
    m=$(jq -c --arg s "$S" '[.messages[]? | select((.params // {} | tostring | test($s + "|\"big\"")) or ((.text // "") | test($s))) | {code, params}] | .[0:4]' "$KEEP/plan-replan.json")
    [[ -n $m && $m != '[]' ]] && cell replan-cause 1 "the plan says it for $S: $m" \
        || cell replan-cause 0 "no message of the plan names $S or the pool «big»: codes $(jq -c '[.messages[]? | .code] | unique' "$KEEP/plan-replan.json" | head -c 300)"
fi
minute_wait
if ask backup.start '{"mode":"backup"}' >/dev/null; then
    wait_backup 1800
    st=$UB/state/status.json
    log=$UB/logs/run-$(jq -r '.run // ""' "$st").log
    evidence "$st" >/dev/null; evidence "$log" run.log >/dev/null 2>&1
    r=$(jq -c '{result, errors, warnings, message}' "$st" | head -c 300)
    [[ $(jq -r .result "$st") != failed ]] && cell run-goes-on 1 "the run: $r" || cell run-goes-on 0 "the whole run failed for one share: $r"
    w=$(jq -c --arg s "$S" '[.. | objects | select((.share // .name // "") == $s or ((.text // .detail // "") | tostring | test($s))) | {code, text: ((.text // .detail // "") | tostring | .[0:120])}] | .[0:3]' "$st" 2>/dev/null)
    l=$(grep -E "$S" "$log" 2>/dev/null | grep -iE 'error|warn|medium|fail' | head -2)
    [[ ( -n $w && $w != '[]' ) || -n $l ]] && cell run-cause 1 "the run says what happened to $S: ${w} ${l:0:200}" \
        || cell run-cause 0 "the run says nothing about $S: $r"
else
    cell run-goes-on 0 "«Back up now» refused: $(ask_last '.error.key')"
fi
bubble_look after-run backup
check standard
scenario_end
