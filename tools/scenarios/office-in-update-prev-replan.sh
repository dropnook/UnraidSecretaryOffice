#!/bin/bash
# Cell B41 of matrix.md (batch E), IN-UPDATE-PREV: the release before the newest installed on today's data folder, then
# the plugin manager's update to the code under test, this tree built as a release is (OF-UPDATE plg=tree — the release
# address of the version under test doesn't exist before its release: 1.70.2's step 6b, 2026-10-22) — as a user updates.
# Nothing saved changes (INV-SETTINGS: settings.ini, her ini, the cron file, staff), a second update changes nothing
# (INV-MIGRATE-IDEMPOTENT), and «Look at the server again» afterwards lists no change (INV-REPLAN: the Apply list as on
# the base). The undo puts the office back exactly. Pre-release (~6 min).
. "$(dirname "$0")/lib.sh"
scenario_begin office-in-update-prev-replan
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
cur=$(_st_office_version)
state IN-UPDATE-PREV previous
say "on the release before: $(_of_versions)"
[[ $(_st_office_version) != "$cur" ]] || _row state IN-UPDATE-PREV red "the release before is the same version ($cur)"
snap_before
flow OF-UPDATE plg=tree
[[ $(_st_office_version) == "$cur" ]] && cell updated 1 "office $cur after the update" || cell updated 0 "office $(_st_office_version) after the update, not $cur"
flow BK-REPLAN
check standard
scenario_end
