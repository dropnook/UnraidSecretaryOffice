#!/bin/bash
# Cell B39 of matrix.md (batch G), state PT-QUOTA-FULL: USOPartner keeps at most 1 GiB of Tower's (its pairs.json
# receive.quota_gb = 1 — the Team Lead's dialog sets it only at the pairing, so the file, as zustaende.md says) and
# holds more already: the scratch share uso-wip-g-pt carries 1 GiB of random data (no compression helps), set to «local +
# partner» and sent once before the state.
# - Two backup runs (≥ 61 s apart — INV-NOTIFY-ONCE takes one subject twice within a minute as one event told twice):
#   each says `partner_quota` (its quota for this server is full) as the one partner warning — a warning every run
#   (engine 2.45, backup.sh partner_refused) — and ends «with warnings», never failed; the share's snapshot made.
# - Nothing at USOPartner overwritten or destroyed: its datasets and snapshots under …-partners the same before and after
#   the runs (the door refuses before it reads a byte, agent/partner-door.php:513-516).
# The undo: USOPartner's pairs.json and the pair's zfs quota, what it received of the share, the share, settings.ini, both
# sides' partner files, the partner base compared. Pre-release (~7 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-pt-quota-full-run
needs tower zfs partner
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
files_gone() { files_unput "$1" >/dev/null 2>&1; rm -f "$KEEP_STASH/$1/files"; return 0; }
S=uso-wip-g-pt
ID=$(pt_id)
scratch_drop() { local x; [[ $S == uso-wip-g-* ]] || return 1
    zfs list -H -r -t snapshot -o name "cache/$S" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    zfs list -H -t bookmark -o name "cache/$S" 2>/dev/null | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    return 0; }
pt_base_snap "$KEEP/ptbase" || _row state partner-base red "the partner base could not be read"
undo_push pt_base_same
pt_keep G-B39-Q || _row state partner-keep red "both sides' partner files could not be kept"
undo_push pt_back G-B39-Q
undo_push pt_drop_received "$S"
mkdir -p "$KEEP_STASH/G-B39-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/G-B39-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/G-B39-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed                                   # Unraid's «Add Share» opened Nextcloud's data folder again (backup-sh-add-share-nc-0777-run)
put_big G-B39-Q cache "$S" 1 fill.bin || _row state fill red "1 GiB could not be written into $S"
undo_push files_gone G-B39-Q
undo_push scratch_drop
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the first run (the first copy at USOPartner) failed"
state PT-QUOTA-FULL 1
track_share "$S"
snap_before
pt_fp there | grep -v "^quota " >"$KEEP/partner-before.fp"     # the door sets the pair's zfs quota from pairs.json at its next receive
last_end=0
run_spaced() { local w=$((last_end + 61 - $(date +%s))); ((w > 0)) && { say "waiting $w s: the previous run's notification ≥ 61 s before this one's"; sleep "$w"; }
    flow BK-RUN-MANUAL mode=backup; local rc=$?; last_end=$(date +%s); return $rc; }
for n in 1 2; do
    run_spaced
    st=$UB/state/status.json
    evidence "$st" "status-quota-$n.json" >/dev/null
    pm=$(jq -c '[.warning_items[]? | select(.code | startswith("partner"))]' "$st")
    items=$(jq -c '[.warning_items[]? | .code]' "$st")
    [[ $(jq -r .result "$st") == warnings ]] && cell "run$n-warnings" 1 "run $n ended «with warnings»" || cell "run$n-warnings" 0 "run $n: $(jq -c '{result, errors, warnings, message}' "$st")"
    [[ $(jq 'length' <<<"$pm") == 1 && $(jq -r '.[0].code' <<<"$pm") == partner_quota ]] \
        && cell "run$n-quota-said" 1 "run $n: partner_quota ($(jq -c '.[0].params' <<<"$pm")), all: $items" \
        || cell "run$n-quota-said" 0 "run $n's partner warnings: $pm (all: $items)"
    zfs list -H -t snapshot -o name "cache/$S" | grep -q "@uso-backup-$(jq -r .run "$st")\$" \
        && cell "run$n-local" 1 "cache/$S@uso-backup-$(jq -r .run "$st") made" || cell "run$n-local" 0 "no snapshot of run $n on cache/$S"
done
if d=$(diff "$KEEP/partner-before.fp" <(pt_fp there | grep -v "^quota ") | grep '^[<>]'); then
    cell partner-untouched 0 "USOPartner's partner base changed during the runs: $(head -c 400 <<<"$d" | paste -sd' ')"
else
    cell partner-untouched 1 "USOPartner's datasets, snapshots and partner files the same after both runs ($(grep -c '^zfs' "$KEEP/partner-before.fp") datasets/snapshots)"
fi
bubble_look after-quota backup caretaker
expect_warnings partner_quota
check standard
check final
scenario_end
