#!/bin/bash
# Cell B22 of matrix.md, variant CT-RENAMED-DOCKER: after a run wrote EmbyServer's package, the user renamed the
# container by `docker rename EmbyServer EmbyServer2` (its template my-EmbyServer.xml still names the old one).
# «Look at the server again» says both at once and honestly: EmbyServer gone (app_gone) and EmbyServer2 new, keeps
# running until decided. The run after it keeps EmbyServer2 running (new) and writes its package — with no template
# (manifest `template` empty: no my-EmbyServer2.xml exists) — and the old package is listed as backed up under the new
# name (packages_gone moved_to EmbyServer2: the same folder appdata/emby), offered for the storeroom, never moved by
# itself. The variant renamed in Unraid's form: backup-ct-renamed-unraid-replan-run. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-renamed-docker-replan-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
NEW=EmbyServer2
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # the history: EmbyServer's package
[[ -f $PLACE/apps/EmbyServer/manifest.json ]] || _row state "EmbyServer's package" red "the run before wrote no apps/EmbyServer"
state CT-RENAMED-DOCKER EmbyServer "$NEW"
snap_before
if flow BK-REPLAN; then
    plan=$KEEP/plan-replan.json
    gone=$(jq '[.messages[]? | select(.code == "app_gone" and ((.params | tostring) | test("\"EmbyServer\"")))] | length' "$plan")
    l=$(node "$SC_DIR/plan-changes.mjs" "$plan" "$UB/settings.ini" 2>/dev/null | jq -c '[.[] | select(.key | startswith("partner|") | not) | {key, was, is}]')
    say "the Apply list: $l"
    new=$(jq -r --arg n "$NEW" '[.[] | select((.is // []) | tostring | contains($n))] | length' <<<"$l")
    [[ $gone -ge 1 && $new -ge 1 ]] && cell gone-and-new 1 "the plan says EmbyServer gone and lists $NEW as new" \
        || cell gone-and-new 0 "app_gone for EmbyServer ×$gone, $NEW in the Apply list ×$new"
fi
flow BK-RUN-MANUAL mode=backup
run1=$(jq -r '.run // empty' "$UB/state/status.json" 2>/dev/null)
evidence "$UB/logs/run-$run1.log" run.log
man=$PLACE/apps/$NEW/manifest.json
if [[ -f $man ]]; then
    t=$(jq -r --arg n "$NEW" '[.containers[]? | select(.name == $n) | .template // ""][0] // "missing"' "$man")
    [[ -z $t ]] && cell manifest-template 1 "apps/$NEW/manifest.json names no template (none exists for $NEW)" \
        || cell manifest-template 0 "apps/$NEW/manifest.json names the template «$t» for $NEW (no my-$NEW.xml exists)"
else
    cell manifest-template 0 "the run wrote no package apps/$NEW"
fi
ask backup.refresh '{}' 300 >/dev/null
g=$(state_json backup '[.packages_gone[]? | select(.kind == "app" and .folder == "EmbyServer")] | .[0] // empty')
[[ -n $g && $(jq -r '.moved_to // ""' <<<"$g") == "$NEW" && -f $PLACE/apps/EmbyServer/manifest.json ]] \
    && cell old-package-moved 1 "the old package is said backed up as $NEW ($(jq -c '{moved_to, signal}' <<<"$g")), still in its place" \
    || cell old-package-moved 0 "the old package: ${g:-not listed}; apps/EmbyServer $([[ -d $PLACE/apps/EmbyServer ]] && echo in its place || echo GONE)"
expect_bubble backup pk.moved_line.folders
check standard
check final
scenario_end
