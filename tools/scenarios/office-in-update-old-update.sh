#!/bin/bash
# Cell O1 of matrix.md (batch E) — and B41's IN-UPDATE-OLD row: real 1.60 data (the fixture data-1.60.tar, STATES.md:
# made on Tower with v1.60.0 — its shares and apps are Tower's) under v1.60.0, then the plugin manager's update to the
# code under test, this tree built as a release is (OF-UPDATE plg=tree: plugin/build.sh, the package on the flash —
# until 2026-10-21 the release's address, which proved only what GitHub had, never a fix before its release). Every
# migration step runs once (the data folder's marker lists all of this version's steps, none pending — cell
# steps-done), the old files are kept as <name>.before-<version> where a step changed one, Jack Streamer's data under
# its new id (jack-id: emby.json → jack.json — cell jack-id), and a second update changes nothing
# (INV-MIGRATE-IDEMPOTENT). «Look at the server again» afterwards works on the old settings: its Apply list names only
# what differs between Tower (the fixture) and this server — announced as a whole (expect_changes '*': the fixture is
# another server's), no settings_error. The undo puts the office back as it was. Nightly (~8 min: two installs and the
# office put back).
. "$(dirname "$0")/lib.sh"
scenario_begin office-in-update-old-update
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cur=$(_st_office_version)
state IN-UPDATE-OLD 1.60
say "on the old release: $(_of_versions)"
snap_before
agent_log_mark update
flow OF-UPDATE plg=tree
[[ $(_st_office_version) == "$cur" ]] && cell updated 1 "office $cur after the update" || cell updated 0 "office $(_st_office_version) after the update, not $cur"
want=$(grep -oE "^ +\['id' => '[a-z-]+'" "$P/agent/lib/migrate.php" | sed -E "s/.*'([a-z-]+)'/\1/" | paste -sd' ' -)     # this version's steps
m=$(jq -c '{version, done, pending}' "$D/office.json" 2>/dev/null)
miss=$(for s in $want; do jq -e --arg s "$s" '.done | index($s)' <<<"$m" >/dev/null || echo "$s"; done | paste -sd' ' -)
[[ -n $want && -z $miss ]] && jq -e '(.pending | length) == 0' <<<"$m" >/dev/null && cell steps-done 1 "the marker: $m" \
    || cell steps-done 0 "steps missing: ${miss:-?} (this version's: ${want:-unknown}); marker $m"
agent_log_since update | grep -E 'Data folder|→|[Mm]igrat' | head -20 >"$KEEP/migration.log"; evidence "$KEEP/migration.log" >/dev/null
[[ -f $D/jack.json ]] && cell jack-id 1 "Jack Streamer's data under jack.json (emby.json $( [[ -f $D/emby.json ]] && echo kept || echo gone))" \
    || cell jack-id 0 "no jack.json after the update (emby.json $( [[ -f $D/emby.json ]] && echo there || echo missing))"
expect_changes '*'                         # the fixture's settings are Tower's: what differs from this server is listed
flow BK-REPLAN
jq -e '[.messages[]? | .code] | index("settings_error") | not' "$KEEP/plan-replan.json" >/dev/null 2>&1 \
    && cell replan-reads 1 "the old settings read without settings_error" \
    || cell replan-reads 0 "settings_error on the old settings: $(jq -c '[.messages[]? | select(.code == "settings_error") | .params]' "$KEEP/plan-replan.json" | head -c 300)"
check standard
scenario_end
