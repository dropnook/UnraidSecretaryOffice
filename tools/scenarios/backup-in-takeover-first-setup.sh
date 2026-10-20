#!/bin/bash
# Cell B40 of matrix.md (batch E): what an old script install left (IN-TAKEOVER — User Scripts' «unraid-backup» on a
# schedule of its own, settings.ini with the old snapshot prefix unraidbackup-). Mr. Backupsy's setup (plan, the page's
# draft, Apply) says what it found: the old User Script by name (setup.msg user_script_* — cell says-script) and the new
# snapshot names (snap_prefix_new — cell says-prefix; Apply writes exactly that — INV-DIFF-EXACT), and never
# changes the old script or its schedule (cell script-untouched: the user switches it off). USOTest3VM's appdata is
# btrfs: no old @unraidbackup- ZFS snapshot here (the state makes one only on ZFS) — the retention part is Tower's.
# Pre-release (~3 min; the first time User Scripts is installed from its release address).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-in-takeover-first-setup
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
US=/boot/config/plugins/user.scripts
nc_data_closed
state IN-TAKEOVER
snap_before
m0=$(md5sum "$US/scripts/unraid-backup/script" "$US/schedule.json" 2>/dev/null | md5sum)
expect_diff 'general|snap_prefix: unraidbackup-→uso-backup-'     # the one change Apply makes: the new prefix the plan announced
if flow BK-FIRST-SETUP; then
    c=$(jq -r '[.messages[]? | .code] | unique | join(" ")' "$KEEP/plan-first.json")
    s=$(jq -c '[.messages[]? | select(.code | startswith("user_script")) | {code, params}] | .[0:4]' "$KEEP/plan-first.json")
    grep -q 'unraid-backup' <<<"$s" && cell says-script 1 "the plan names the old User Script: $s" || cell says-script 0 "the plan doesn't name the User Script unraid-backup: $c"
    grep -qw snap_prefix_new <<<"$c" && cell says-prefix 1 "the plan says snap_prefix_new" || cell says-prefix 0 "no snap_prefix_new: $c"
fi
[[ $(md5sum "$US/scripts/unraid-backup/script" "$US/schedule.json" 2>/dev/null | md5sum) == "$m0" ]] \
    && cell script-untouched 1 "the old User Script and its schedule as they were" || cell script-untouched 0 "the old User Script or its schedule changed"
check standard
scenario_end
