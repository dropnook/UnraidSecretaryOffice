#!/bin/bash
# Cell R10 of matrix.md (wave 3): the array disk a share's moments live on asleep (DK-ASLEEP-DISK2: Tower's disk2 is ZFS,
# uso-arr's half there has the engine's moments) × Mr. Restori. Never woken unasked:
#   scan-no-wake     his look (RS-SCAN) leaves disk2 asleep and shows what he kept from before (rsKeptFile) — no «?»
#   preview-no-wake  the restore dialog's preview of a file on disk2 without «wake» leaves it asleep and asks for the
#                    wake (a blocker or note naming the sleeping disk), never reads it
#   files-wake       with the user's «wake» the restore runs and brings the file back beside (INV-COMPARE)
# QEMU's disks never sleep (STATES.md day-1 finding 3): `needs hdd` — written against the expected layout, runs where a
# rotating disk is passed through. Nightly.
. "$(dirname "$0")/lib.sh"
scenario_begin restore-dk-asleep-disk2-scan-files
needs tower hdd
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
nc_data_closed
f=$(find /mnt/disk2/uso-arr -maxdepth 2 -type f | sort | head -1)
[[ -n $f ]] || { _row state uso-arr red "no file of uso-arr on disk2"; scenario_end; }
path=/mnt/user/uso-arr/${f#/mnt/disk2/uso-arr/}
flow BK-RUN-MANUAL mode=backup                 # a moment of uso-arr on disk2
flow RS-SCAN                                    # his look while awake: what he keeps for the sleeping one
state DK-ASLEEP-DISK2
track_share uso-arr
snap_before
flow RS-SCAN
base_asleep disk2 && cell scan-no-wake 1 "his look left disk2 asleep" || cell scan-no-wake 0 "disk2 is awake after his look"
ask restore.preview "$(jq -cn --arg p "$path" '{kind: "files", path: $p, mode: "copy"}')" 300 >/dev/null
pv=$(ask_last '{blockers: [.preview.blockers[]?.key], notes: [.preview.notes[]?.key], error: .error.key}' | jq -c .)
base_asleep disk2 && [[ $pv == *sleep* || $pv == *asleep* || $pv == *wake* ]] && cell preview-no-wake 1 "the preview asks for the wake: $pv" \
    || cell preview-no-wake 0 "the preview: $pv; disk2 $(base_asleep disk2 && echo asleep || echo AWAKE)"
flow RS-FILES mode=beside path="$path" wake=1 && cell files-wake 1 "restored beside with «wake»" \
    || cell files-wake 0 "with «wake»: $(ask_last '.error.key // empty')"
allow INV-NO-WAKE "files-wake: the user's own «wake» in the dialog woke disk2 — the cell's flow"
check standard
scenario_end
