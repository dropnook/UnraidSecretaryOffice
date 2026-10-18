#!/bin/bash
# office-smoke-refresh — the toy scenario of wave 1: the three desks' fresh looks (what opening their pages asks the agent)
# change nothing on the server and leave the standard invariants green. Any host.
set -u
. "$(dirname "$0")/lib.sh"
scenario_begin office-smoke-refresh
needs any
[[ -n $PLACE ]] && track_share "$(basename "$(dirname "$PLACE")")"     # the office's share: packages and dumps
snap_before
files_strict                                   # a look changes no file
for desk in backup restore moverelli; do
    ask "$desk.refresh" '{}' 120 >/dev/null
    expect_ok "$desk.refresh"
done
check standard
scenario_end
