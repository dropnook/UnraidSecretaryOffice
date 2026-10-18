#!/bin/bash
# Cell M2 of matrix.md (★, a bug from the owner's server): her own rules for a share that has nothing to move any more —
# uso-mo-empty (a rule of 5 days, MO-OWN-RULES) removed in Unraid while her rule for it stays.
# Her settings saved unchanged keep them byte for byte (MV-RESAVE, INV-REPLAN's part of hers), and her setup page shows
# them with «Remove rules» (nothing removed by itself). Fixed in 1.66.1. $1 = an older release (v1.66.0): the same
# scenario on that plugin, which must be red — 1.66.0 refused every save naming a share Unraid no longer has (moverelli_bad_share), its page dropped the rules silently.
# Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-mo-own-rules-gone-setup
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state MO-OWN-RULES
share_remove uso-mo-empty || _row state uso-mo-empty red "share_remove failed"
nc_data_closed                               # Unraid's share form opened Nextcloud's data folder (0777)
snap_before
flow MV-RESAVE
ini=$D/moverelli/smart_mover.ini
r=$(awk '$0 == "[uso-mo-empty]" { s = 1; next } /^\[/ { s = 0 } s && /^min_age=/ { sub(/^min_age=/, ""); print }' "$ini")
[[ $r == 5 ]] && cell rules-kept 1 "smart_mover.ini still has [uso-mo-empty] min_age=5" \
    || cell rules-kept 0 "smart_mover.ini has [uso-mo-empty] min_age «${r:-gone}» (her rule was 5)"
g=$(state_json moverelli '(.shares_gone // []) | index("uso-mo-empty") != null')
[[ $g == true ]] && cell shares-gone 1 "her state lists uso-mo-empty among the shares Unraid no longer has" \
    || cell shares-gone 0 "her state's shares_gone: $(state_json moverelli '.shares_gone // "missing"' | head -c 200)"
bubble_look own-rules moverelli
look=$(ls -d "$KEEP"/looks/*-own-rules | tail -1)
queue_mac INV-BUBBLE page_text moverelli/setup "$look/states" "$PAGE_LANGS" want "moverelli.setup.own_gone moverelli.setup.own_remove"
queue_mac INV-PAGE page_clean moverelli/setup "$look/states" "$PAGE_LANGS"
check standard
scenario_end
