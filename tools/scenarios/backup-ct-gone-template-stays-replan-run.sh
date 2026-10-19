#!/bin/bash
# Cell B21 of matrix.md, variant CT-GONE-TEMPLATE-STAYS: EmbyServer removed the way Unraid usually leaves it — the
# container gone, my-EmbyServer.xml and the image stay — after a run had written its package. «Look at the server again»
# says app_gone once and its Apply list drops it; after Apply the next plan says nothing of it (the section goes once).
# The run after that keeps the package where it is and does not list it gone: its image is still there, and Mr.
# Backupsy's rule (agent/desks/backup-gone.php: «none of whose images either (an image alone may be an app about to
# be made again)») offers «Into the storeroom» only once the image went too (backup-ct-gone-replan-run-aside). The
# template the user kept is no leftover: my-EmbyServer.xml stays as it was through Apply and both runs, and the copy in
# the package (its my-EmbyServer.xml) is nobody's «stray template» (Ms. Dustdevil lists none inside the backup
# place). Apply's diff = the Apply list (INV-DIFF-EXACT); the settings.ini of before back by the undo. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-gone-template-stays-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
apply_list_diff() {
    node "$SC_DIR/plan-changes.mjs" "$1" "$UB/settings.ini" 2>/dev/null | jq -r '.[] | .key as $k
        | ((.was // []) | if type == "array" then . else [.] end | map(tostring)) as $w
        | ((.is // []) | if type == "array" then . else [.] end | map(tostring)) as $i
        | (($w - $i)[] | "-\($k)=\(.)"), (($i - $w)[] | "+\($k)=\(.)")'
}
TPL=/boot/config/plugins/dockerMan/templates-user/my-EmbyServer.xml
nc_data_closed
mkdir -p "$KEEP_STASH/ini" && cp -p "$UB/settings.ini" "$KEEP_STASH/ini/settings.ini"
undo_push bash -c '[[ -f $1 ]] && cp -p "$1" "$2" && rm -f "$1"' _ "$KEEP_STASH/ini/settings.ini" "$UB/settings.ini"
flow BK-RUN-MANUAL mode=backup               # the history: EmbyServer's package
[[ -f $PLACE/apps/EmbyServer/manifest.json ]] || _row state "EmbyServer's package" red "the run before wrote no apps/EmbyServer"
state CT-GONE-TEMPLATE-STAYS EmbyServer
tpl0=$(md5sum <"$TPL" 2>/dev/null)
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    cp "$plan" "$KEEP/plan-gone.json"
    n=$(jq '[.messages[]? | select(.code == "app_gone" and ((.params | tostring) | test("EmbyServer")))] | length' "$plan")
    l=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -r '[.[].key] | join(" ")')
    [[ $n == 1 ]] && cell gone-said 1 "the plan says app_gone for EmbyServer once; the Apply list: $l" \
        || cell gone-said 0 "app_gone for EmbyServer $n times in the plan; the Apply list: $l"
fi
expect_diff "$(apply_list_diff "$KEEP/plan-gone.json")"
say "the Apply list's diff: $(apply_list_diff "$KEEP/plan-gone.json" | paste -sd' ' -)"
flow BK-APPLY plan=old
if flow BK-REPLAN; then
    n=$(jq '[.messages[]? | select(.code == "app_gone")] | length' "$KEEP/plan-replan.json")
    l=$(node "$SC_DIR/plan-changes.mjs" "$KEEP/plan-replan.json" "$UB/settings.ini" 2>/dev/null | jq -r '[.[].key | select(startswith("partner|") | not)] | join(" ")')
    [[ $n == 0 && -z $l ]] && cell gone-once 1 "after Apply the plan says nothing of EmbyServer" \
        || cell gone-once 0 "after Apply the plan still says app_gone ×$n, Apply list: ${l:-nothing}"
fi
flow BK-RUN-MANUAL mode=backup
ask backup.refresh '{}' 300 >/dev/null
g=$(state_json backup '[.packages_gone[]? | select(.folder == "EmbyServer")] | length')
[[ $g == 0 && -f $PLACE/apps/EmbyServer/manifest.json ]] \
    && cell kept-not-offered 1 "the package stays in its place, not offered for the storeroom (its image is still there)" \
    || cell kept-not-offered 0 "packages_gone lists EmbyServer $g time(s); apps/EmbyServer in the place: $([[ -d $PLACE/apps/EmbyServer ]] && echo yes || echo no)"
[[ $(md5sum <"$TPL" 2>/dev/null) == "$tpl0" && -n $tpl0 ]] && cell template-stays 1 "my-EmbyServer.xml is still there, unchanged" \
    || cell template-stays 0 "my-EmbyServer.xml $([[ -f $TPL ]] && echo changed || echo GONE) after Apply and the runs"
php "$SC_DIR/ask.php" --wait 300 cleanup.refresh '{}' >/dev/null 2>&1
evidence "$D/cleanup.json" cleanup.json
st=$(jq -r --arg p "$(dirname "$PLACE")" '[.. | strings | select(test("/my-[^/]+\\.xml$") and (startswith($p) or contains("/" + ($p | split("/") | last) + "/backup/")))] | unique | join(" ")' "$D/cleanup.json" 2>/dev/null)
[[ -z $st ]] && cell no-stray-in-backup 1 "Ms. Dustdevil names no template inside the backup place" \
    || cell no-stray-in-backup 0 "Ms. Dustdevil lists templates inside the backup place: $st"
check standard
check final
scenario_end
