#!/bin/bash
# Cell R13 of matrix.md, the renamed variants (CT-RENAMED-DOCKER, then CT-RENAMED-UNRAID's case of the old template):
# after a run, EmbyServer is renamed by `docker rename` to EmbyServer2 and a second run writes EmbyServer2's package —
# with no template (no my-EmbyServer2.xml exists: manifest `template` empty). «Restore the templates» (the dialog's
# preview, kind config) for EmbyServer2 must explain that its package holds no template — not claim «everything is the
# same» (errors.restore_config_same), which says the package's files match the server's. For the old package
# EmbyServer the plan's template path is Unraid's templates-user/my-EmbyServer.xml (same there: nothing to do). The
# drill passes with both packages (the old one is backed up as the new one — no finding of his). Only previews: nothing
# is restored. Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-ct-renamed-config-drill
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
NEW=EmbyServer2
TPLDIR=/boot/config/plugins/dockerMan/templates-user
preview_config() {   # <app> → the plan in $KEEP/rs-config-<app>.json (or the refusal's key in $KEEP/ask/last.json)
    ask restore.preview "$(jq -cn --arg a "$1" '{kind: "config", app: $a}')" 300 >/dev/null || return 1
    ask_last '.preview' >"$KEEP/rs-config-$1.json"
}
nc_data_closed
flow BK-RUN-MANUAL mode=backup               # EmbyServer's package (its template)
state CT-RENAMED-DOCKER EmbyServer "$NEW"
flow BK-RUN-MANUAL mode=backup               # EmbyServer2's package (no template)
snap_before
flow RS-SCAN
t=$(jq -r --arg n "$NEW" '[.containers[]? | select(.name == $n) | .template // ""][0] // "missing"' "$PLACE/apps/$NEW/manifest.json" 2>/dev/null)
say "apps/$NEW/manifest.json: template «$t», files: $(cd "$PLACE/apps/$NEW" 2>/dev/null && ls | paste -sd' ' -)"
if preview_config "$NEW"; then
    p=$KEEP/rs-config-$NEW.json
    b=$(jq -r '[.blockers[]?.key] | join(",")' "$p")
    s=$(jq -r '[.steps[]?.do] | join(",")' "$p")
    say "config plan for $NEW: blockers ${b:-none}, steps ${s:-none}, notes $(jq -c '[.notes[]?.key]' "$p")"
    [[ -n $b && $b != restore_config_same && -z $s ]] && cell no-template-explained 1 "the plan for $NEW explains: $b" \
        || cell no-template-explained 0 "the plan for $NEW (no template in its package) says «${b:-no blocker}»$([[ $b == restore_config_same ]] && echo ' — «Everything is the same on the server as in the package», though the package holds no template'), steps ${s:-none}"
else
    k=$(ask_last '.error.key')
    [[ -n $k && $k != restore_config_same ]] && cell no-template-explained 1 "the preview for $NEW is refused with $k" \
        || cell no-template-explained 0 "the preview for $NEW is refused with «$k»"
fi
if preview_config EmbyServer; then
    p=$KEEP/rs-config-EmbyServer.json
    to=$(jq -r '[.options.items[]? | select(.what == "template") | .to] | join(" ")' "$p")
    [[ $to == "$TPLDIR/my-EmbyServer.xml" ]] && cell template-path 1 "the old package's template goes to $to ($(jq -r '[.options.items[]?.now] | join(",")' "$p"))" \
        || cell template-path 0 "the old package's template items: ${to:-none}"
else
    cell template-path 0 "the preview for EmbyServer is refused: $(ask_last '.error.key')"
fi
flow RS-DRILL
check standard
check final
scenario_end
