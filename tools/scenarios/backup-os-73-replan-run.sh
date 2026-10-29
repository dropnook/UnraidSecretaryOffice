#!/bin/bash
# Cell B44 of matrix.md (wave 3): Mr. Backupsy on Unraid 7.3.x (OS-73 — the tested line before 7.4: its mover script,
# its python, its Dashboard). What must hold: «Look at the server again» reads the server without settings_error and
# lists no change (INV-REPLAN against the base), a run ends ok or warnings, the page's look clean (INV-PAGE).
# OS-73 is a look only (Unraid's version): runs on USOTest4 (7.3.3); Tower and USOTest3VM run 7.4 — skipped there.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-os-73-replan-run
needs tower
state OS-73
nc_data_closed
snap_before
flow BK-REPLAN
flow BK-RUN-MANUAL mode=backup
bubble_look os-73 backup
check standard
check final
scenario_end
