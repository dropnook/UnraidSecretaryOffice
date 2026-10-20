#!/bin/bash
# Cell R19 of matrix.md (batch G), state PT-PULLED × RS-PARTNER, OF-AGENT-RESTART: Mr. Restori brings the scratch share
# uso-wip-g-pt back from USOPartner (sent there once before, at «local + partner»).
# - RS-PARTNER: «Look again» on his partner tile, the newest moment USOPartner holds, «Bring back…» — a NEW dataset
#   beside the original (cache/uso-wip-g-pt.restored-<time>), mounted read-only under
#   /mnt/addons/UnraidSecretaryOffice/restored/, its files those of the moment (Tower's own snapshot of that name); the
#   original untouched (INV-FILES strict), never a `zfs clone` (origin «-»), never `zfs receive -F` (zpool history of
#   the pool since the start: every receive without -F, no clone).
# - OF-AGENT-RESTART: still mounted read-only. OF-ARRAY-STOP-START (the real reason a legacy mount goes — the pool is
#   exported): the stop not held by the mount, and after the start the agent's rspRemount mounts it read-only again.
# - Ms. Dustdevil lists it among Mr. Restori's leftovers (BG2: «pulled from a partner», read only — why «pulled», his
#   «Remove what was pulled…» removes it); a backup run's retention leaves it (the dataset and its moment still there
#   afterwards).
# The undo: «Remove what was pulled…» (his put-back of the pull — destroys exactly that dataset), the share, settings.ini,
# what USOPartner received of the share, both sides' partner files, the partner base compared. Pre-release (~8 min).
. "$(dirname "$0")/lib.sh"
scenario_begin restore-pt-pulled-partner-restart
needs tower zfs partner
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
S=uso-wip-g-pt
ID=$(pt_id)
scratch_drop() { local x; [[ $S == uso-wip-g-* ]] || return 1
    zfs list -H -r -t snapshot -o name "cache/$S" 2>/dev/null | grep -E '@uso-(backup|plan)-' | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    zfs list -H -t bookmark -o name "cache/$S" 2>/dev/null | while read -r x; do zfs destroy "$x" && say "undo: destroyed $x"; done
    return 0; }
pt_base_snap "$KEEP/ptbase" || _row state partner-base red "the partner base could not be read"
undo_push pt_base_same
pt_keep G-R19 || _row state partner-keep red "both sides' partner files could not be kept"
undo_push pt_back G-R19
undo_push pt_drop_received "$S"
mkdir -p "$KEEP_STASH/G-R19-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/G-R19-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/G-R19-INI" "$UB/settings.ini"
nc_data_closed
state SH-POOL-ONLY "$S"
nc_data_closed                                   # Unraid's «Add Share» opened Nextcloud's data folder again (backup-sh-add-share-nc-0777-run)
undo_push scratch_drop
flow BK-APPLY "share|$S|mode=snapshot" "share|$S|partner=[\"$ID\"]" || _row state prepare red "Apply of $S as local + partner failed"
flow BK-RUN-MANUAL mode=backup || _row state prepare red "the run that sends $S to USOPartner failed"
track_share "$S"
files_strict                                     # the pull lands beside: the share's own files never change
t_start=$(date '+%Y-%m-%d.%H:%M:%S')
snap_before
# 1. «Bring back…»
state PT-PULLED unit="share:$S"                  # = flow RS-PARTNER; its undo: «Remove what was pulled…»
ds=$(cat "$KEEP/pt-pulled.ds" 2>/dev/null)
j=$(fl_last_restore)
snap=$(jq -r '[.pulled[]?.snap][0] // empty' "$D/restore/$j/journal.json" 2>/dev/null)
mnt=$(jq -r '[.pulled[]?.mount][0] // empty' "$D/restore/$j/journal.json" 2>/dev/null)
ro_now() { [[ -n $mnt ]] && findmnt -no SOURCE,OPTIONS --mountpoint "$mnt" 2>/dev/null | awk -v d="$ds" '$1 == d && $2 ~ /(^|,)ro(,|$)/ {f=1} END {exit !f}'; }
if [[ $ds == "cache/$S.restored-"* ]] && zfs list -H "$ds" >/dev/null 2>&1; then
    cell beside 1 "a new dataset $ds beside cache/$S (moment $snap)"
else
    cell beside 0 "the pull's dataset: «$ds» ($(jq -c '.pulled // .result' "$D/restore/$j/journal.json" 2>/dev/null))"
fi
[[ $(zfs get -H -o value origin "$ds" 2>/dev/null) == - ]] && cell no-clone 1 "origin «-»: received, not cloned" || cell no-clone 0 "origin of $ds: $(zfs get -H -o value origin "$ds" 2>&1)"
[[ $mnt == /mnt/addons/UnraidSecretaryOffice/restored/* ]] && ro_now && cell mounted-ro 1 "mounted read-only at $mnt" \
    || cell mounted-ro 0 "mount of $ds: $(findmnt -no TARGET,OPTIONS -S "$ds" 2>&1 | paste -sd' ') (journal: ${mnt:-none})"
src=/mnt/cache/$S/.zfs/snapshot/$snap
if [[ -n $snap && -d $src && -n $mnt ]]; then
    d=$(diff -rq --no-dereference -x .uso-backup "$src" "$mnt" 2>&1 | head -3)
    [[ -z $d ]] && cell same-files 1 "$mnt holds the files of cache/$S@$snap" || cell same-files 0 "differs from cache/$S@$snap: ${d//$'\n'/; }"
else
    cell same-files 0 "nothing to compare (moment «$snap», mount «$mnt»)"
fi
hist() { zpool history cache 2>/dev/null | awk -v t="$t_start" '$1 >= t' | grep -E ' zfs (receive|recv|clone) '; }
h=$(hist)
if grep -qE ' zfs (receive|recv) .*(^| )-[a-zA-Z]*F' <<<"$h" || grep -q ' zfs clone ' <<<"$h"; then
    cell no-force 0 "zpool history: ${h//$'\n'/; }"
else
    cell no-force 1 "zpool history since $t_start: $(grep -cE ' zfs (receive|recv) ' <<<"$h") receive(s), none with -F, no clone ($(head -1 <<<"$h" | cut -d' ' -f2-))"
fi
# 2. an agent restart, then an array stop and start (the pool exported: the legacy mount goes; rspRemount at the start)
flow OF-AGENT-RESTART
ro_now && cell restart-ro 1 "after the agent's restart still read-only at $mnt" || cell restart-ro 0 "after the agent's restart: $(findmnt -no TARGET,OPTIONS -S "$ds" 2>&1 | paste -sd' ')"
agent_log_mark array
flow OF-ARRAY-STOP-START; rc=$?
nc_data_closed                                   # Unraid's array start opened Nextcloud's data folder (0777) — not this cell's
((rc == 0)) && cell array-stop-free 1 "the array stopped and started ($(grep stop_seconds "$KEEP/.of-array" 2>/dev/null))" \
    || cell array-stop-free 0 "the array stop/start: $(cat "$KEEP/.of-array" 2>/dev/null | paste -sd' ')"
if wait_for 120 "the pull mounted again" ro_now; then
    cell remount-ro 1 "after the array's start mounted read-only again: $(agent_log_since array | grep -m1 'mounted again' | cut -c1-200)"
else
    cell remount-ro 0 "after the array's start: $(findmnt -no TARGET,OPTIONS -S "$ds" 2>&1 | paste -sd' ') — agent log: $(agent_log_since array | grep -i 'restori' | tail -2 | paste -sd' ' | cut -c1-300)"
fi
# 3. Ms. Dustdevil lists it; a backup run's retention leaves it
# her look is a tour of its own (a job): her state files are waited for up to 3 min to name it
names_it() { grep -l -F -- "${ds#cache/}" "$D"/cleanup*.json "$D"/cleanup/*.json 2>/dev/null | head -1; }
ask cleanup.refresh '{}' 300 >/dev/null
wait_for 180 "Ms. Dustdevil's look to name ${ds#cache/}" bash -c "grep -qlF -- '${ds#cache/}' $D/cleanup*.json $D/cleanup/*.json 2>/dev/null"
c=$(names_it)
lo=$(jq -c --arg d "$ds" '[.leftovers.list[]? | select(.path == $d)] | .[0] // empty | {what, category, why, parts: [.parts[]? | {path, dataset}]}' "$D/cleanup.json" 2>/dev/null)
if [[ -n $lo && $(jq -r '.what + " " + .category + " " + .why' <<<"$lo") == "pulled pulled pulled" ]]; then
    cell dustdevil-lists 1 "among Mr. Restori's leftovers, read only: $lo"
elif [[ -n $c ]]; then
    cell dustdevil-lists 0 "her state names ${ds#cache/} but not as a pull of his: ${lo:-$(jq -c --arg n "${ds#cache/}" '[paths(type == "string" and test($n)) | map(tostring) | join(".")] | .[0:3]' "$c")}"
else
    cell dustdevil-lists 0 "no state of hers names ${ds#cache/}"
fi
before=$(zfs list -H -r -t filesystem,snapshot -o name "$ds" 2>/dev/null | paste -sd' ')
flow BK-RUN-MANUAL mode=backup
after=$(zfs list -H -r -t filesystem,snapshot -o name "$ds" 2>/dev/null | paste -sd' ')
[[ -n $before && $after == "$before" ]] && cell retention-leaves 1 "after a run still: $after" || cell retention-leaves 0 "before the run: «$before», after: «$after»"
ro_now || cell retention-leaves 0 "after the run no longer mounted read-only"
bubble_look after-pull restore cleanup
allow INV-TICK "the array stop ends the agent by design (CLAUDE.md: the agent — event/started starts, event/stopping stops); its heartbeat pauses until the start"
check standard
check final
scenario_end
