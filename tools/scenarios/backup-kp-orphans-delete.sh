#!/bin/bash
# Cell B3 of matrix.md (★, a bug from the owner's server): «Delete old sources in Kopia» really deletes. Three old sources
# of the office's (a gone share, app and VM, one snapshot each) are listed; the user ticks them and deletes; afterwards the
# real Kopia repository holds no snapshot of them (until 1.65.12 every delete was refused — idList's keys — and the same
# dialog came back). Fixed in 1.65.13. $1 = an older release (v1.65.12): the same scenario on that plugin, which must
# be red. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-orphans-delete
needs tower docker kopia
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state KP-ORPHANS
snap_before
files_strict
id=$(sed -n 's/^IDENTITY=//p' "$KEEP/state-KP-ORPHANS.env" 2>/dev/null)
srcs=$(printf "$id:%s," "${ST_KOPIA_ORPHANS[@]}"); srcs=${srcs%,}
say "the three sources to tick: $srcs"
flow BK-ORPHANS "delete=$srcs"
listed=$(jq -r --argjson want "$(jq -cn --arg s "$srcs" '$s | split(",")')" \
    '[(.sources // [])[] | .source | select(IN($want[]))] | length' "$KEEP/orphans-list.json" 2>/dev/null)
[[ $listed == 3 ]] && cell listed 1 "the look lists all three old sources" \
    || cell listed 0 "the look lists ${listed:-none} of the three: $(jq -c '[(.sources // [])[] | .source]' "$KEEP/orphans-list.json" 2>/dev/null | head -c 300)"
left=$(_st_kx snapshot list --all --json 2>/dev/null | jq -r --argjson p "$(printf '%s\n' "${ST_KOPIA_ORPHANS[@]}" | jq -R . | jq -sc .)" \
    '[.[].source | select(.path as $x | $p | index($x)) | .path] | unique | join(" ")')
[[ -z $left ]] && cell deleted-in-kopia 1 "Kopia's repository holds no snapshot of the three any more" \
    || cell deleted-in-kopia 0 "still in Kopia's repository: $left (orphans: $(state_json backup '.orphans | {mode, result, code, deleted, failed, refused}' 2>/dev/null | head -c 200))"
check standard
scenario_end
