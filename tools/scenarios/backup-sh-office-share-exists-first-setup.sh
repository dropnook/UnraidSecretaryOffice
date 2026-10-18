#!/bin/bash
# Cell B5 of matrix.md (★, a bug from the owner's server): the office's share UnraidSecretaryOffice exists and is the
# backup place (SH-OFFICE-SHARE-EXISTS placed — the owner's case). «Look at the server again» keeps it as the place, and
# the setup page shows the guide «What matters for the backup place» without «Create a share in Unraid» (until 1.66.1 it
# offered that for the share that already was the place). By design the button stays while no place is chosen
# (the «unplaced» variant: the plan proposes none — the user picks one; not this cell). Fixed in 1.66.1, in desk.js
# only: an older plugin's state files are the same, so its red is shown by drawing these state files with that tag's
# page (README). $1 = an older release (v1.66.0). Pre-release (~2 min).
# The guide is folded once a place is chosen, and page.mjs reads innerText, which skips a closed <details>, so the
# «not» check proves nothing until page.mjs opens them. It is marked «pending: helper --open» (coordinator, 2026-10-18).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-office-share-exists-first-setup
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state SH-OFFICE-SHARE-EXISTS placed
snap_before
flow BK-REPLAN
ds=$(jq -r '.P["general|dumps_share"] // empty' "$KEEP/plan-replan.json" 2>/dev/null)
[[ $ds == UnraidSecretaryOffice ]] && cell place-kept 1 "the plan keeps the existing share UnraidSecretaryOffice as the backup place" \
    || cell place-kept 0 "the plan proposes «${ds:-no place}» as the place (saved: UnraidSecretaryOffice)"
# the setup page on this plan (its setup_get answer is the page's dialog data)
ask_save_post backup.setup_get '{}' 120 >/dev/null
bubble_look first-setup backup
look=$(ls -d "$KEEP"/looks/*-first-setup | tail -1)
queue_mac INV-BUBBLE page_text backup/setup "$look/states" "$PAGE_LANGS" not "backup.setup.place_add backup.setup.place_name"
queue_mac INV-PAGE page_clean backup/setup "$look/states" "$PAGE_LANGS"
# the «not» above is green on 1.66.0 too (the button sits in the closed guide): no proof until page.mjs opens <details>
_row cell no-create-share pending "pending: helper --open — page.mjs skips a closed <details>, where «Create a share» sits"
check standard
scenario_end
