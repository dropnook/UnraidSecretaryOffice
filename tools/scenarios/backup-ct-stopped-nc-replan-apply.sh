#!/bin/bash
# Cell B1 of matrix.md (★, a bug from the owner's server): a stopped Nextcloud keeps its backup settings. «Look at the
# server again» lists it as not running with the hint stopped_kept, its preexisting_maintenance key stays in the plan
# (INV-STOPPED-KEEPS), the Apply list names no change (INV-REPLAN), and Apply leaves it in settings.ini — so the next
# run still puts Nextcloud into maintenance mode. Fixed in 1.66.3 (engine 2.60). $1 = an older release (v1.66.2): the
# same scenario on that plugin, which must be red. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-stopped-nc-replan-apply
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# the diff Apply may write: exactly what the page's Apply list shows for this plan (plan-changes.mjs, desk.js's own code),
# as ini lines — nothing more (INV-SETTINGS → INV-DIFF-EXACT; '' when the list is empty: the ini's content stays)
expect_apply_list() {
    local l
    l=$(node "$SC_DIR/plan-changes.mjs" "$1" "$UB/settings.ini" 2>/dev/null | jq -r '.[] | .key as $k
        | ((.was // []) | if type == "array" then . else [.] end | map(tostring)) as $w
        | ((.is // []) | if type == "array" then . else [.] end | map(tostring)) as $i
        | (($w - $i)[] | "-\($k)=\(.)"), (($i - $w)[] | "+\($k)=\(.)")')
    say "the Apply list for this plan: ${l:-nothing}"
    expect_diff "${l:-}"
}
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
nc_data_closed
state CT-STOPPED-NC
expect_warnings dump_stopped          # engine 2.64: the stopped database's skipped dump is coded (up to 2.63 a plain warning)
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    jq -e '[.messages[]? | select(.code == "stopped_kept" and (.params.name // "") == "Nextcloud")] | length > 0' "$plan" >/dev/null \
        && cell stopped_kept 1 "the plan says Nextcloud isn't running and keeps its settings" \
        || cell stopped_kept 0 "no hint stopped_kept for Nextcloud: $(jq -c '[.messages[]? | .code] | unique' "$plan")"
    k=$(jq -r '.P["nextcloud|Nextcloud|preexisting_maintenance"] // empty | tostring' "$plan")
    [[ $k == abort ]] && cell plan-keeps-key 1 "nextcloud|Nextcloud|preexisting_maintenance = abort in the plan" \
        || cell plan-keeps-key 0 "the plan proposes preexisting_maintenance «${k:-nothing}» (saved: abort)"
    expect_apply_list "$plan"
fi
flow BK-APPLY plan=old                       # Apply on that plan, nothing changed by the user (the page's draft as it is)
v=$(_ini_flat "$UB/settings.ini" | sed -n 's/^nextcloud|Nextcloud|preexisting_maintenance=//p')
[[ $v == abort ]] && cell ini-keeps-key 1 "settings.ini still has [nextcloud \"Nextcloud\"] preexisting_maintenance = abort" \
    || cell ini-keeps-key 0 "after Apply settings.ini has preexisting_maintenance «${v:-nothing}» (was abort): the next run skips maintenance mode"
check standard
check final
scenario_end
