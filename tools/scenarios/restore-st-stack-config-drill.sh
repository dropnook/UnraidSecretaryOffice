#!/bin/bash
# Cell R14 of matrix.md (wave 3): the Immich stack (ST-STACK: server, postgres, redis by their compose labels — no Compose
# Manager on Tower, so its package holds no compose file) backed up, then ST-RENAMED (the same containers under the new
# project label immich-new, the bug of 17.10.) and a second run. Mr. Restori, previews only — nothing is restored:
#   pkg-listed     his look lists the stack's package apps/immich (and the renamed one's) with its members
#   config-said    «the configuration» of immich: a plan that explains why nothing can come back (no compose file in the
#                  package: restore_config_none_compose — or a template to put back), never «everything is the same»
#                  (restore_config_same) and never steps that would start the stack
#   renamed-said   the old package immich after the rename: the same — explained, nothing started, the running
#                  immich-new members never named as «to stop» for a restore of the old package's config
#   stack-runs     after his previews every member of immich-new runs as before (a preview never stops a container)
# The drill then passes with both packages (a stack's package is practised as a package — not checked is said, never
# failed). RS-STACK-CREATE («Create the stack now») belongs to a partner pull (rspStackLater) — Tower's base has no
# partner: not in this cell. Nightly (~10 min: two runs and a drill).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-st-stack-config-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
NEW=immich-new
nc_data_closed
# the renamed project's package is this scenario's leftover: away again at the end (the next scenario's packages tile)
undo_push bash -c '[[ -n $1 && -d $1 ]] && rm -rf -- "$1"; exit 0' _ "$PLACE/apps/$NEW"
state ST-STACK
flow BK-RUN-MANUAL mode=backup              # apps/immich: the stack's package
state ST-RENAMED immich "$NEW"
flow BK-RUN-MANUAL mode=backup              # apps/immich-new
snap_before
flow RS-SCAN
for a in immich "$NEW"; do
    m=$PLACE/apps/$a/manifest.json
    say "apps/$a: $(cd "$PLACE/apps/$a" 2>/dev/null && ls | paste -sd' ' -) — compose $(jq -c '.compose // null' "$m" 2>/dev/null | head -c 200)"
done
listed=$(state_json restore '[.apps[]?.name] | unique | join(" ")' 2>/dev/null)
[[ " $listed " == *" immich "* && " $listed " == *" $NEW "* ]] && cell pkg-listed 1 "his look lists the packages immich and $NEW" \
    || cell pkg-listed 0 "his look's packages: ${listed:-none} (immich and $NEW expected)"
evidence "$D/restore.json" restore-look.json >/dev/null

# the «earlier» menu's «configuration» for a package (desk.js ~887 {kind: config, app, version})
preview_config() {   # <app> → $KEEP/rs-config-<app>.json, 0 a plan, 1 refused (key in ask_last)
    local v
    ask restore.versions "$(jq -cn --arg i "$1" '{kind: "app", id: $i}')" 300 >/dev/null
    v=$(ask_last '[.versions[]? | .snap // empty][0] // empty')
    say "the «earlier» versions of $1: $(ask_last '[.versions[]?.snap] | join(" ")')"
    ask restore.preview "$(jq -cn --arg a "$1" --arg v "$v" '{kind: "config", app: $a} + (if $v != "" then {version: $v} else {} end)')" 300 >/dev/null || return 1
    ask_last '.preview' >"$KEEP/rs-config-$1.json"
}
judge() {   # <cell> <app>
    local c=$1 a=$2 p b s stops
    if preview_config "$a"; then
        p=$KEEP/rs-config-$a.json
        b=$(jq -r '[.blockers[]?.key] | join(",")' "$p")
        s=$(jq -r '[.steps[]?.do] | join(",")' "$p")
        stops=$(jq -r '[.steps[]? | select((.do // "") | test("stop|start|up")) | .do] | join(",")' "$p")
        say "config plan for $a: blockers ${b:-none}, steps ${s:-none}, after $(jq -c '[.after[]?.key]' "$p"), notes $(jq -c '[.notes[]?.key]' "$p")"
        if [[ $b == *restore_config_same* ]]; then
            cell "$c" 0 "the plan for $a says «Everything is the same on the server as in the package» (restore_config_same) — its package holds no compose file"
        elif [[ -n $stops ]]; then
            cell "$c" 0 "the plan for $a would $stops containers: steps $s"
        elif [[ -n $b || -n $s ]]; then
            cell "$c" 1 "the plan for $a explains: ${b:-steps $s}"
        else
            cell "$c" 0 "the plan for $a: no blocker, no step — the dialog would offer nothing and say nothing"
        fi
    else
        b=$(ask_last '.error.key')
        [[ -n $b && $b != restore_config_same ]] && cell "$c" 1 "the preview for $a is refused with $b" \
            || cell "$c" 0 "the preview for $a is refused with «$b»"
    fi
}
judge config-said "$NEW"
judge renamed-said immich
up=$(for c in $(stack_members "$NEW"); do printf '%s=%s ' "$c" "$(ct_status "$c")"; done)
[[ -n $up && $up != *=exited* && $up != *=paused* && $up != *=created* ]] && cell stack-runs 1 "after the previews: $up" \
    || cell stack-runs 0 "after the previews: ${up:-no members of $NEW}"
flow RS-DRILL
expect_changes 'docker|known' 'docker|no_stop'    # the renamed project is a real change: immich gone, immich-new new
check standard
check final
scenario_end
