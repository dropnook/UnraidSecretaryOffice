#!/bin/bash
# tools/scenarios/truth.sh — INV-TRUTH, the VM's half (sourced by lib.sh after checks.sh): no unit shows more protection than
# it has, no «ok» for a check that couldn't run, no «gone» while Docker is off. At the scenario's last look (checks.sh's
# _ck_final_look: the desks' fresh looks, their state files copied to $KEEP/looks/<n>-final/states) the server's truth is
# read beside them — truth-facts.php: the engine's snapshots on the very datasets, Kopia's own snapshot list, each
# partner's door, Docker, libvirt; never the office's code — into truth.json, and the Mac draws the pages from those
# state files and compares (queue_mac INV-TRUTH page_truth <look> <langs> → tools/scenarios/truth.mjs, run.sh's mac_check).
# Pending (mac) until then; red there turns the scenario red. README.md «INV-TRUTH» has the rules.
#
#   truth_look <dir>        the state files and truth.json into <dir> (a look of its own, outside a scenario too)
#   bash truth.sh --look <dir>    the same from a shell (tools/scenarios/truth-now.sh uses it)

CHECK_STANDARD+=(INV-TRUTH)

truth_look() {
    local dir=$1
    mkdir -p "$dir/states/post" || return 1
    find "$D" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$dir/states/" \;
    [[ -d $SC_POST ]] && find "$SC_POST" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$dir/states/post/" \;
    php "$SC_DIR/truth-facts.php" >"$dir/truth.json.new" 2>"$dir/truth.err" && mv "$dir/truth.json.new" "$dir/truth.json"
}

# INV-TRUTH — the last look's pages against the server's truth (on the Mac)
check_inv_truth() {
    local dir
    _ck_final_look
    dir=$(ls -d "$KEEP"/looks/*-final 2>/dev/null | tail -1)
    [[ -d $dir/states ]] || { echo "no final look ($KEEP/looks)"; return 1; }
    if ! php "$SC_DIR/truth-facts.php" >"$dir/truth.json.new" 2>"$dir/truth.err"; then
        echo "the server's truth could not be read: $(head -c 200 "$dir/truth.err")"
        return 1
    fi
    mv "$dir/truth.json.new" "$dir/truth.json"
    queue_mac INV-TRUTH page_truth "$dir" "${TRUTH_LANGS:-en}"
    echo "pending (mac): the pages of $(basename "$dir") against the truth ($(jq -c '{shares: (.shares | length), vms: (.vms | length), apps: (.apps | length), docker: .docker.answers, kopia: .kopia.asked, partners: [.partners[] | "\(.name):\(if .asked then "asked" else .why end)"]}' "$dir/truth.json"))"
    return 2
}

if [[ ${BASH_SOURCE[0]} == "$0" && -z ${SC_DIR:-} ]]; then
    case ${1:-} in
        --look)
            [[ -n ${2:-} ]] || { echo "usage: bash truth.sh --look <dir>" >&2; exit 2; }
            # shellcheck disable=SC1091
            . "$(dirname "$0")/lib.sh" >/dev/null 2>&1
            truth_look "$2" || { echo "truth: could not read the truth: $(cat "$2/truth.err" 2>/dev/null)" >&2; exit 1; } ;;
        *) sed -n '2,13p' "$0"; exit 2 ;;
    esac
fi
