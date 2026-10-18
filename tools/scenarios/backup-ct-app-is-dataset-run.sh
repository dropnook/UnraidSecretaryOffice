#!/bin/bash
# Cell B4 of matrix.md (★, a bug from the owner's server): an app's package inside goes only into its folder in appdata
# (a dataset of its own there, like Tower's jellyfin, too) — never into a share's root. The owner's S3 gateway bound a
# whole share and listed the engine's .uso-backup there as a bucket. Here: CT-APP-IS-DATASET (appdata/jellyfin a
# dataset, appdata/emby a folder) and CT-BINDS-ROOT on a share no other app binds (uso-wide binds only
# /mnt/user/uso-arr, as a gateway does; an existing test share, as a new one would bring the Add Share finding along).
# After a run no .uso-backup lies in any share's root, nor anywhere but appdata/<app>/ and domains/<vm>/.
# Fixed in 1.65.10 (engine 2.57). $1 = an older release (v1.65.9): the same scenario on that plugin, which must be red.
# Pre-release (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-ct-app-is-dataset-run
needs tower docker zfs
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
# every .uso-backup on the disks and pools that isn't an app's (appdata/<app>/) or a VM's (domains/<vm>/)
stray() {
    local b
    for b in $(bases); do
        base_asleep "$b" && continue
        find "/mnt/$b" -mindepth 2 -maxdepth 4 -type d -name .uso-backup 2>/dev/null
    done | grep -Ev '^/mnt/[^/]+/(appdata|domains)/[^/]+/\.uso-backup$'
}
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
state CT-APP-IS-DATASET jellyfin emby
nc_data_closed
track_share uso-arr
undo_push bash -c 'rm -rf /mnt/*/uso-arr/.uso-backup'     # what an older engine writes there (runs after the container went)
state CT-BINDS-ROOT share uso-arr
snap_before
before=$(stray)
[[ -z $before ]] || say "before the run already: $before"
printf '%s\n' "$before" >"$KEEP/stray-before.txt"
# what an older engine writes into share roots goes again after the checks — only a folder new since snap_before whose
# README.txt is the engine's (pushed last: it runs first)
undo_push bash -c '[[ -f $2 ]] || exit 0; while IFS= read -r d; do [[ -n $d ]] || continue; grep -qxF -- "$d" "$1" && continue
    head -1 "$d/README.txt" 2>/dev/null | grep -q "^Written by the Unraid Secretary Office" && rm -rf -- "$d"; done <"$2"' _ "$KEEP/stray-before.txt" "$KEEP/stray-after.txt"
expect_changes 'app|uso-wide|*' 'docker|no_stop' 'docker|known'   # the new app: a real change (its proposal, kept running, known)
flow BK-RUN-MANUAL mode=backup
now=$(stray)
printf '%s\n' "$now" >"$KEEP/stray-after.txt"
[[ -z $now ]] && cell no-uso-backup-in-roots 1 "no .uso-backup outside appdata/<app>/ and domains/<vm>/" \
    || cell no-uso-backup-in-roots 0 "the run wrote .uso-backup into: $(paste -sd' ' <<<"$now")"
[[ -d /mnt/cache/appdata/jellyfin/.uso-backup ]] && cell inside-dataset 1 "appdata/jellyfin (a dataset) has its package inside" \
    || cell inside-dataset 0 "appdata/jellyfin (a dataset of its own) has no .uso-backup after the run"
check standard
check final
scenario_end
