#!/bin/bash
# INV-TRUTH on a real, messy server (Tower's «mixed» base profile, base/tower/profile-mixed.env): one backup run started
# here — VMs in a dataset of their own, one as a plain folder in domains' dataset, one on XFS (no snapshot can hold it),
# one running and never held; apps in appdata, one in a dataset of its own, one without data, a compose stack; a share on
# XFS and ZFS at once, one on XFS only, two offsite (Kopia), four units sent to USOTest4 — then the standard set, whose
# INV-TRUTH draws Mr. Backupsy's, Mr. Restori's and the Team Lead's pages from the state files the run left and compares
# each unit with what really protects it (the engine's snapshots, Kopia's list, USOTest4's door). The run itself may end
# with warnings (what can't be snapshotted, a share with a child dataset at the partner): this cell asserts only that it
# ran — the invariants do the rest. Tower only; ~5 min (the first run of the profile sends full copies: longer).
# base-profile: mixed
. "$(dirname "$0")/lib.sh"
scenario_begin backup-mx-mixed-truth-run
needs tower docker vm kopia
[[ $(cat "$OFFICE_FLASH/scenarios-profile" 2>/dev/null) == mixed ]] || _row state profile red "the server is not on the mixed base profile (run.sh resets it by this file's base-profile line)"
nc_data_closed
[[ -n $PLACE ]] && track_share "$(basename "$(dirname "$PLACE")")"
snap_before
flow BK-RUN-MANUAL mode=backup
evidence "$UB/state/status.json" status-after-run.json
say "the run: $(jq -c '{result, errors, warnings, partner_ok, partner_skipped, partner_failed, kopia: .kopia.state}' "$UB/state/status.json")"
[[ $(jq -r '.result' "$UB/state/status.json") =~ ^(ok|warnings)$ ]] && _row cell run green "the run ended $(jq -r .result "$UB/state/status.json")" \
    || _row cell run red "the run ended $(jq -r .result "$UB/state/status.json") ($(jq -c '[.warning_items[]?.code // empty] | unique' "$UB/state/status.json"))"
check standard
scenario_end
