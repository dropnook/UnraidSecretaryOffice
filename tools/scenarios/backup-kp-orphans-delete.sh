#!/bin/bash
# Cell B3 of matrix.md (★, a bug from the owner's server): «Delete old sources in Kopia» really deletes. Three old sources
# of the office's (a gone share, app and VM, one snapshot each) are listed; the user ticks them and deletes; afterwards the
# real Kopia repository holds no snapshot of them (until 1.65.12 every delete was refused — idList's keys — and the same
# dialog came back). Fixed in 1.65.13. $1 = an older release (v1.65.12): the same scenario on that plugin, which must
# be red. Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-orphans-delete
needs tower docker kopia vm   # with the VM service off a VM source is «unknown» and never offered (setup.sh orphans_unit: libvirt can't be asked)
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state KP-ORPHANS
snap_before
files_strict
# the look first (what the page shows), then the three ticked — by their ids as the list names them (user@host:path)
flow BK-ORPHANS
want=$(printf '%s\n' "${ST_KOPIA_ORPHANS[@]}" | jq -R . | jq -sc .)
srcs=$(jq -r --argjson w "$want" '[(.sources // [])[] | select(.path as $p | $w | index($p)) | .source] | join(",")' "$KEEP/orphans-list.json" 2>/dev/null)
n=$(awk -F, '{print NF}' <<<"$srcs")
[[ ${n:-0} == 3 ]] && cell listed 1 "the look lists all three old sources: $srcs" \
    || cell listed 0 "the look lists ${n:-none} of the three: $(jq -c '[(.sources // [])[] | .source]' "$KEEP/orphans-list.json" 2>/dev/null | head -c 300)"
[[ -n $srcs ]] && flow BK-ORPHANS "delete=$srcs"
left=$(_st_kx snapshot list --all --json 2>/dev/null | jq -r --argjson p "$(printf '%s\n' "${ST_KOPIA_ORPHANS[@]}" | jq -R . | jq -sc .)" \
    '[.[].source | select(.path as $x | $p | index($x)) | .path] | unique | join(" ")')
[[ -z $left ]] && cell deleted-in-kopia 1 "Kopia's repository holds no snapshot of the three any more" \
    || cell deleted-in-kopia 0 "still in Kopia's repository: $left (orphans: $(state_json backup '.orphans | {mode, result, code, deleted, failed, refused}' 2>/dev/null | head -c 200))"
check standard
scenario_end
