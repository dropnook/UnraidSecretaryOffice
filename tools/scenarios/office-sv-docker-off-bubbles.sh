#!/bin/bash
# Docker off (or never on): no container at all, so none lacks a picture — Ms. Dustdevil's greeting «A container
# without a picture? That simply won't do!» (cleanup.greet.6) must not show. Red until fixed (H5 finding, seen on
# USOTest3VM, 1.66.6): INV-BUBBLE on the Mac (the page drawn once per greeting). The Consultant's bubble offers «— or I
# do it for you» only while Docker answers (bubble-rules.tsv; fixed after 1.66.7, which is red). $1 = an older release
# (v1.66.7): the same scenario on that plugin.
. "$(dirname "$0")/lib.sh"
scenario_begin office-sv-docker-off-bubbles
needs any
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state SV-DOCKER-OFF cfg                    # switched off as the user does (docker.cfg): stopped alone is «on but silent», no plan
snap_before
files_strict
for desk in cleanup caretaker backup restore advisor; do
    ask "$desk.refresh" '{}' 120 >/dev/null
done
expect_no_text cleanup greet.6
check standard
scenario_end
