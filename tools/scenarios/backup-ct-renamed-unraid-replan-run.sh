#!/bin/bash
# Cell B22 of matrix.md, variant CT-RENAMED-UNRAID: after a run wrote EmbyServer's package, the user renamed it in
# Unraid's Docker form (a new template my-EmbyServer2.xml, the old container and template gone, EmbyServer2 made from
# it with the same binds). «Look at the server again» says both at once and honestly in its Apply list: EmbyServer gone from [docker] known/no_stop and
# EmbyServer2 new, keeps running until decided. The run after it keeps EmbyServer2 running (new) and writes its package
# naming its template my-EmbyServer2.xml (manifest `template`), and the old package is listed as backed up under the
# new name (packages_gone moved_to EmbyServer2: the same folder appdata/emby), offered for the storeroom, never moved
# by itself. The `docker rename` variant: backup-ct-renamed-docker-replan-run. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-renamed-unraid-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
NEW=EmbyServer2
nc_data_closed
# the package the scenario's own scratch name got goes again at the end (never the engine's way — a test's leftover:
# the drill and the packages tile of the next scenario would list it)
undo_push bash -c '[[ -n $1 && -d $1 ]] && rm -rf -- "$1"; exit 0' _ "$PLACE/apps/$NEW"
flow BK-RUN-MANUAL mode=backup               # the history: EmbyServer's package
[[ -f $PLACE/apps/EmbyServer/manifest.json ]] || _row state "EmbyServer's package" red "the run before wrote no apps/EmbyServer"
state CT-RENAMED-UNRAID EmbyServer "$NEW"
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    # no [app] section for EmbyServer in Tower's base, so no hint app_gone (setup.sh ~683, only for an [app] entry): the
    # Apply list says it — EmbyServer leaves [docker] known/no_stop and EmbyServer2 comes in (new: kept running)
    l=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -c '[.[] | select(.key | startswith("partner|") | not)
        | ((.was // []) | if type == "array" then . else [.] end) as $w | ((.is // []) | if type == "array" then . else [.] end) as $i
        | {key, gone: ($w - $i), new: ($i - $w)}]')
    say "the Apply list: $l"
    ok=$(jq -r --arg n "$NEW" 'map(select(.key == "docker|known")) | length == 1 and (.[0].gone == ["EmbyServer"]) and (.[0].new == [$n])' <<<"$l")
    [[ $ok == true ]] && cell gone-and-new 1 "the Apply list says both at once: EmbyServer goes, $NEW comes ($l)" \
        || cell gone-and-new 0 "the Apply list for the rename: $l"
fi
flow BK-RUN-MANUAL mode=backup
run1=$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null)
evidence "$UB/logs/run-$run1.log" run.log
man=$PLACE/apps/$NEW/manifest.json
if [[ -f $man ]]; then
    t=$(jq -r --arg n "$NEW" '[.containers[]? | select(.name == $n) | .template // ""][0] // "missing"' "$man")
    [[ $t == "my-$NEW.xml" && -f $PLACE/apps/$NEW/my-$NEW.xml ]] && cell manifest-template 1 "apps/$NEW/manifest.json names my-$NEW.xml, the package holds it" \
        || cell manifest-template 0 "apps/$NEW/manifest.json names the template «$t» for $NEW (want my-$NEW.xml); in the package: $(cd "$PLACE/apps/$NEW" 2>/dev/null && ls my-*.xml 2>/dev/null | paste -sd' ' -)"
else
    cell manifest-template 0 "the run wrote no package apps/$NEW"
fi
ask backup.refresh '{}' 300 >/dev/null
g=$(state_json backup '[.packages_gone[]? | select(.kind == "app" and .folder == "EmbyServer")] | .[0] // empty')
[[ -n $g && $(jq -r '.moved_to // ""' <<<"$g") == "$NEW" && -f $PLACE/apps/EmbyServer/manifest.json ]] \
    && cell old-package-moved 1 "the old package is said backed up as $NEW ($(jq -c '{moved_to, signal}' <<<"$g")), still in its place" \
    || cell old-package-moved 0 "the old package: ${g:-not listed}; apps/EmbyServer $([[ -d $PLACE/apps/EmbyServer ]] && echo in its place || echo GONE)"
expect_bubble backup pk.moved_line.folders
expect_changes 'docker|known' 'docker|no_stop'    # the rename is a real change: EmbyServer gone, EmbyServer2 new
check standard
check final
scenario_end
