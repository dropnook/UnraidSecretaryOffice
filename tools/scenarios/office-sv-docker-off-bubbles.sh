#!/bin/bash
# Docker off (or never on): no container at all, so none lacks a picture — Ms. Dustdevil's greeting «A container
# without a picture? That simply won't do!» (cleanup.greet.6) must not show. Red until fixed (H5 finding, seen on
# USOTest3VM, 1.66.6): INV-BUBBLE on the Mac (the page drawn once per greeting).
. "$(dirname "$0")/lib.sh"
scenario_begin office-sv-docker-off-bubbles
needs any
state SV-DOCKER-OFF
snap_before
files_strict
for desk in cleanup caretaker backup restore; do
    ask "$desk.refresh" '{}' 120 >/dev/null
done
expect_no_text cleanup greet.6
check standard
scenario_end
