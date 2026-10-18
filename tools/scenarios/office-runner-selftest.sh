#!/bin/bash
# The runner's own test (no matrix cell): a scenario that ends as it is told - it changes nothing on the server.
#   office-runner-selftest.sh [0|1|2|3|mac|macred]
#   0 green · 1 red (one red invariant) · 2 skipped · 3 dirty (the runner resets the VM before the next) · mac: green with
#   two Mac-side checks that pass (Mr. Backupsy's page shows his name; the change list of the plan as it is saved:
#   whatever it is now, as expected) · macred: green here, a Mac-side check that fails (his name must NOT be on his page).
# It writes what lib.sh writes (h3-runner-contract.md): $USO_SC_OUT/<name>.md, .result, .pending - by hand, without lib.sh,
# so the runner can be tested before and apart from it.
set -u
name=office-runner-selftest
mode=${1:-0}
out=${USO_SC_OUT:-/tmp/uso-scenarios/$(date +%Y%m%d)}
keep=/tmp/uso-keep/$name
mkdir -p "$out" "$keep"
report=$out/$name.md
cfg=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
D=$(sed -n 's/^DATA_DIR="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$cfg" 2>/dev/null | head -1)

say() { echo "$*" | tee -a "$report"; }
: > "$report"
say "# $name ($mode)"
say ""
say "Host $(hostname), $(date '+%Y-%m-%d %H:%M:%S'), tree $(cat "${USO_SC_TREE:-/nonexistent}/.uso-describe" 2>/dev/null || echo '?')"

pending() { printf '%s\n' "$(IFS=$'\t'; echo "$*")" >> "$out/$name.pending"; }
states() {          # the page's state files now (+ the scenario's canned answers), for a page_text check
    rm -rf "$keep/states"
    mkdir -p "$keep/states/post"
    find "$D" -maxdepth 1 -type f -name '*.json' -exec cp -p {} "$keep/states/" \;
    echo "$keep/states"
}

case $mode in
    0) verdict=green code=0 red='' why='' ;;
    1) verdict=red code=1 red=INV-SELFTEST why='INV-SELFTEST: red on purpose' ;;
    2) verdict=skipped code=2 red='' why='needs nothing - skipped on purpose' ;;
    3) verdict=dirty code=3 red='' why='undo failed on purpose (nothing was changed)' ;;
    mac|macred)
        verdict=green code=0 red='' why=''
        [[ -d $D ]] || { say "no data folder"; verdict=red code=1 red=INV-SELFTEST why='no data folder'; }
        if ((code == 0)); then
            s=$(states)
            if [[ $mode == mac ]]; then
                pending INV-PAGE page_text backup "$s" de,en want backup.name
                # the saved settings against the plan: the change list as it is right now (the check only proves the
                # copy-back and plan-changes.mjs, not the base - a base check expects [])
                cp -p "$D/unraid-backup/state/setup-plan.json" "$keep/plan.json" 2>/dev/null
                cp -p "$D/unraid-backup/settings.ini" "$keep/settings.ini" 2>/dev/null
                if [[ -f $keep/plan.json ]]; then
                    now=$(node "$USO_SC_TREE/tools/scenarios/plan-changes.mjs" "$keep/plan.json" "$keep/settings.ini" 2>/dev/null | jq -c '[.[].key]' 2>/dev/null)
                    pending INV-REPLAN plan_changes "$keep/plan.json" "$keep/settings.ini" "${now:-[]}"
                fi
            else
                pending INV-PAGE page_text backup "$s" en not backup.name
            fi
            say "Mac-side checks queued: $(wc -l < "$out/$name.pending")"
        fi ;;
    *) verdict=error code=4 red='' why="unknown mode $mode" ;;
esac
say ""
say "| invariant | result |"
say "|---|---|"
say "| INV-SELFTEST | $([[ -n $red ]] && echo "red - $why" || echo green) |"
{
    echo "verdict=$verdict"
    echo "red=$red"
    echo "pending=$([[ -s $out/$name.pending ]] && cut -f1 "$out/$name.pending" | sort -u | paste -sd,)"
    echo "keep=$keep"
    [[ -n $why ]] && echo "why=$why"
} > "$out/$name.result"
exit $code
