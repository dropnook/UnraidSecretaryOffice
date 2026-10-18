#!/bin/bash
# Cell B2 of matrix.md (★, a bug from the owner's server): a Compose stack installed again under another project name
# (the same containers). After a run, the old package is not just «stale»: the packages tile says its containers are
# now backed up as the new one, with «Into the storeroom», and the drill doesn't report it again and again. Fixed in
# 1.66.0 (the drill part in 1.65.13). $1 = an older release (v1.65.13): the same scenario on that plugin, which must be
# red. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-st-renamed-run
needs tower docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
nc_data_closed
state ST-RENAMED immich immich-new
snap_before
expect_changes 'app|immich-new|*' 'app|immich|*'     # the stack under its new name: a real change for the setup
flow BK-RUN-MANUAL mode=backup
ask backup.refresh '{}' 300 >/dev/null
moved=$(state_json backup '[.packages_gone[]? | select(.kind == "app" and (.moved_to // "") == "immich-new")] | .[0] // empty')
if [[ -n $moved ]]; then
    cell package-moved 1 "the old package is said moved: $(jq -c '{name, moved_to, signal}' <<<"$moved")"
else
    cell package-moved 0 "no package names immich-new as where it went: packages_gone $(state_json backup '[.packages_gone[]? | {name, moved_to}]' | head -c 300)"
fi
expect_bubble backup pk.moved_line.containers
check standard
check final                                  # INV-DRILL-GREEN: the drill passes — the old package is no finding of his
scenario_end
