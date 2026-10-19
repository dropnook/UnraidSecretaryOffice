#!/bin/bash
# Cell B21 of matrix.md, variant CT-GONE: the user removed EmbyServer with its template and its image (Unraid's «Remove»
# with «also remove image») after a run had written its package. «Look at the server again» says app_gone once and
# its Apply list drops it (docker known/no_stop); after Apply the next plan says nothing of it (the section goes once).
# The run after that leaves the package where it is (never moved by itself) and lists it gone (packages_gone, «Into the
# storeroom»); only the click (backup.package_aside) moves it into Ms. Dustdevil's storeroom, and then it is listed no
# more. Apply's diff must be exactly the Apply list the page showed (INV-DIFF-EXACT). The undo: the image back first
# (CT-GONE's undo rebuilds the container from it), Ms. Dustdevil's «Put back» for the package, the settings.ini of before
# (no helper undoes an Apply). The variant whose template and image stay: backup-ct-gone-template-stays-replan-run.
# Pre-release (~7 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-gone-replan-run-aside
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
apply_list_diff() {
    node "$SC_DIR/plan-changes.mjs" "$1" "$UB/settings.ini" 2>/dev/null | jq -r '.[] | .key as $k
        | ((.was // []) | if type == "array" then . else [.] end | map(tostring)) as $w
        | ((.is // []) | if type == "array" then . else [.] end | map(tostring)) as $i
        | (($w - $i)[] | "-\($k)=\(.)"), (($i - $w)[] | "+\($k)=\(.)")'
}
# Ms. Dustdevil's «Put back» (cleanup.restore {ids}) for what the storeroom holds of EmbyServer's package
putback() {
    php "$SC_DIR/ask.php" --wait 300 cleanup.refresh '{}' >/dev/null 2>&1
    local ids
    ids=$(jq -c '[.. | objects | select((.kind // "") == "package" and ((.name // .folder // "") | tostring | test("EmbyServer"))) | .id | select(. != null)] | unique' "$D/cleanup.json" 2>/dev/null)
    [[ -n $ids && $ids != '[]' ]] || return 0
    say "put back from the storeroom: $ids"
    php "$SC_DIR/ask.php" --wait 300 cleanup.restore "$(jq -cn --argjson i "$ids" '{ids: $i}')" >/dev/null 2>&1
}
nc_data_closed
mkdir -p "$KEEP_STASH/ini" && cp -p "$UB/settings.ini" "$KEEP_STASH/ini/settings.ini"
undo_push bash -c '[[ -f $1 ]] && cp -p "$1" "$2" && rm -f "$1"' _ "$KEEP_STASH/ini/settings.ini" "$UB/settings.ini"
flow BK-RUN-MANUAL mode=backup               # the history: EmbyServer's package
[[ -f $PLACE/apps/EmbyServer/manifest.json ]] || _row state "EmbyServer's package" red "the run before wrote no apps/EmbyServer"
undo_push putback
state CT-IMAGE-GONE EmbyServer               # stopped, its image saved aside and removed
state CT-GONE EmbyServer                     # the container and my-EmbyServer.xml gone
undo_push _st_image_back CT-IMAGE-GONE       # (runs first: CT-GONE's undo rebuilds the container from the image)
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    cp "$plan" "$KEEP/plan-gone.json"
    # Tower's base has no [app "EmbyServer"] section (an app follows its share's level), so no hint app_gone (setup.sh
    # says it only for an [app] entry, ~683): what says it is the Apply list — EmbyServer leaves [docker] known/no_stop
    l=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -c '[.[] | select(.key | startswith("partner|") | not)
        | {key, gone: (((.was // []) | if type == "array" then . else [.] end) - ((.is // []) | if type == "array" then . else [.] end))}]')
    ok=$(jq -r '(map(select(.key == "docker|known" or .key == "docker|no_stop")) | length == 2 and all(.gone == ["EmbyServer"])) and length == 2' <<<"$l")
    [[ $ok == true ]] && cell gone-said 1 "the Apply list says EmbyServer goes from [docker] known and no_stop, nothing else: $l" \
        || cell gone-said 0 "the Apply list for the gone EmbyServer: $l"
fi
expect_diff "$(apply_list_diff "$KEEP/plan-gone.json")"
say "the Apply list's diff: $(apply_list_diff "$KEEP/plan-gone.json" | paste -sd' ' -)"
cp -p "$UB/settings.ini" "$KEEP/settings-pre-apply.ini"
flow BK-APPLY plan=old
# + a share's id and locations as the engine found them at Apply: desk.js leaves them out of the list on purpose («a
# share's id and locations the engine writes itself from what it finds - not a decision of the user's», ~2299)
f=$(ini_diff "$KEEP/settings-pre-apply.ini" "$UB/settings.ini" | grep -E '^[-+]share\|[^|]+\|(id|locations)=')
[[ -n $f ]] && { say "Apply also wrote the shares' facts: $(paste -sd' ' <<<"$f")"; expect_diff "$f"; }
if flow BK-REPLAN; then
    n=$(jq '[.messages[]? | select(.code == "app_gone")] | length' "$KEEP/plan-replan.json")
    l=$(node "$SC_DIR/plan-changes.mjs" "$KEEP/plan-replan.json" "$UB/settings.ini" 2>/dev/null | jq -r '[.[].key | select(startswith("partner|") | not)] | join(" ")')
    [[ $n == 0 && -z $l ]] && cell gone-once 1 "after Apply the plan says nothing of EmbyServer" \
        || cell gone-once 0 "after Apply the plan still says app_gone ×$n, Apply list: ${l:-nothing}"
fi
flow BK-RUN-MANUAL mode=backup
ask backup.refresh '{}' 300 >/dev/null
g=$(state_json backup '[.packages_gone[]? | select(.kind == "app" and .folder == "EmbyServer")] | .[0] // empty')
[[ -n $g && -f $PLACE/apps/EmbyServer/manifest.json ]] \
    && cell listed-not-moved 1 "the package is listed gone and still in its place: $(jq -c '{name, folder}' <<<"$g")" \
    || cell listed-not-moved 0 "listed: ${g:-no}; apps/EmbyServer in the place: $([[ -d $PLACE/apps/EmbyServer ]] && echo yes || echo no)"
bubble_look gone-listed backup
flow BK-PACKAGE-ASIDE kind=app folder=EmbyServer
ask backup.refresh '{}' 300 >/dev/null
g=$(state_json backup '[.packages_gone[]? | select(.folder == "EmbyServer")] | length')
inside=$(find "$(dirname "$PLACE")" /mnt/*/"$(basename "$(dirname "$PLACE")")" -maxdepth 6 -path '*UnraidSecretaryOffice-trash*' -name manifest.json -path '*EmbyServer*' 2>/dev/null | head -1)
[[ ! -d $PLACE/apps/EmbyServer && $g == 0 && -n $inside ]] \
    && cell aside-on-click 1 "after the click the package lies in the storeroom ($inside) and is listed no more" \
    || cell aside-on-click 0 "after the click: apps/EmbyServer in the place $([[ -d $PLACE/apps/EmbyServer ]] && echo still || echo gone), listed $g, in the storeroom: ${inside:-nothing found}"
evidence "$D/cleanup.json" cleanup-after-aside.json
check standard
check final
scenario_end
