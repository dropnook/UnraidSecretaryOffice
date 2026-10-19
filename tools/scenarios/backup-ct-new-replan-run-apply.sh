#!/bin/bash
# Cell B24 of matrix.md: a container the setup never saw (CT-NEW: uso-new, alpine, appdata/uso-new) «waits» exactly until
# the user decides, then is handled as decided. The base's default for new things is «Automatic» (no preset_new: it
# waits). «Look at the server again» lists it as new; a run keeps it running untouched («New, keep running», backup.sh
# T_NEW); Apply with the page's draft as it is (the user's decision = the proposal); the next plan names nothing; the
# next run no longer calls it new and stops it or not exactly as settings.ini now says (no_stop or not). Apply's diff
# must be exactly the Apply list the page showed (INV-DIFF-EXACT); the settings of before are put back by the undo
# (no helper undoes an Apply). Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-new-replan-run-apply
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# the diff Apply may write: exactly what the page's Apply list shows for this plan (as backup-ct-stopped-nc-replan-apply)
apply_list_diff() {
    node "$SC_DIR/plan-changes.mjs" "$1" "$UB/settings.ini" 2>/dev/null | jq -r '.[] | .key as $k
        | ((.was // []) | if type == "array" then . else [.] end | map(tostring)) as $w
        | ((.is // []) | if type == "array" then . else [.] end | map(tostring)) as $i
        | (($w - $i)[] | "-\($k)=\(.)"), (($i - $w)[] | "+\($k)=\(.)")'
}
started() { docker inspect -f '{{.State.StartedAt}}' uso-new 2>/dev/null; }
runlog() { echo "$UB/logs/run-$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null).log"; }
nc_data_closed
mkdir -p "$KEEP_STASH/ini" && cp -p "$UB/settings.ini" "$KEEP_STASH/ini/settings.ini"
undo_push bash -c '[[ -f $1 ]] && cp -p "$1" "$2" && rm -f "$1"' _ "$KEEP_STASH/ini/settings.ini" "$UB/settings.ini"
state CT-NEW
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    cp "$plan" "$KEEP/plan-new.json"
    l=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -r '[.[] | select(((.is // []) | tostring | contains("uso-new")) or (.key | contains("uso-new"))) | .key] | unique | join(" ")')
    [[ -n $l ]] && cell plan-lists-new 1 "the Apply list names uso-new: $l" || cell plan-lists-new 0 "the Apply list doesn't name uso-new"
fi
t0=$(started)
flow BK-RUN-MANUAL mode=backup
evidence "$(runlog)" run-1.log
[[ $(started) == "$t0" && $(ct_status uso-new) == running ]] && cell run1-untouched 1 "uso-new kept running through the run, never stopped" \
    || cell run1-untouched 0 "uso-new was touched by the run: started $t0 → $(started), now $(ct_status uso-new)"
grep -qE 'New, keep running:.*\buso-new\b' "$(runlog)" && cell run1-says-new 1 "the run says uso-new is new and keeps running" \
    || cell run1-says-new 0 "the run's log doesn't name uso-new as new: $(grep -m1 'New, keep running' "$(runlog)")"
bubble_look waiting backup
expect_diff "$(apply_list_diff "$KEEP/plan-new.json")"
say "the Apply list's diff: $(apply_list_diff "$KEEP/plan-new.json" | paste -sd' ' -)"
flow BK-APPLY plan=old                         # the page's draft as it is: the user takes the proposal
grep -qx 'known = uso-new' "$UB/settings.ini" && cell apply-known 1 "settings.ini [docker] knows uso-new" \
    || cell apply-known 0 "after Apply settings.ini has no «known = uso-new»"
nostop=no; grep -qx 'no_stop = uso-new' "$UB/settings.ini" && nostop=yes
if flow BK-REPLAN; then
    l=$(node "$SC_DIR/plan-changes.mjs" "$KEEP/plan-replan.json" "$UB/settings.ini" 2>/dev/null | jq -r '[.[].key | select(contains("uso-new") or . == "docker|known" or . == "docker|no_stop")] | join(" ")')
    [[ -z $l ]] && cell replan-quiet 1 "the plan after Apply names nothing of uso-new" || cell replan-quiet 0 "after Apply the plan still lists: $l"
fi
t1=$(started)
flow BK-RUN-MANUAL mode=backup
evidence "$(runlog)" run-2.log
if grep -qE 'New, keep running:.*\buso-new\b' "$(runlog)"; then
    cell run2-decided 0 "after Apply the run still says uso-new is new"
elif [[ $nostop == yes ]]; then
    [[ $(started) == "$t1" ]] && cell run2-decided 1 "decided «keep running» (no_stop): the run left uso-new running" \
        || cell run2-decided 0 "decided no_stop, but the run restarted uso-new ($t1 → $(started))"
else
    [[ $(started) != "$t1" && $(ct_status uso-new) == running ]] && cell run2-decided 1 "decided «stop for the snapshot»: stopped and started again" \
        || cell run2-decided 0 "decided stop, but uso-new started $t1 → $(started), now $(ct_status uso-new)"
fi
expect_no_text backup waiting.callout
check standard
check final
scenario_end
