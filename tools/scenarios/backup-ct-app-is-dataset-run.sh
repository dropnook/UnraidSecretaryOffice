#!/bin/bash
# Cell B4 of matrix.md (★, a bug from the owner's server): an app's package inside goes only into its folder in appdata
# (a dataset of its own there, like Tower's jellyfin, too) — never into a share's root. The owner's S3 gateway bound a
# whole share and listed the engine's .uso-backup there as a bucket. Here: CT-APP-IS-DATASET (appdata/jellyfin a
# dataset, appdata/emby a folder) and CT-BINDS-ROOT on a scratch share of its own (uso-wide binds only
# /mnt/user/uso-wip-ba-s3, as a gateway does). After a run no .uso-backup lies in any share's root, nor anywhere but
# appdata/<app>/ and domains/<vm>/. Fixed in 1.65.10 (engine 2.57). $1 = an older release (v1.65.9): the same scenario
# on that plugin, which must be red. Pre-release (~5 min).
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
share_use B4 uso-wip-ba-s3 only cache || _row state uso-wip-ba-s3 red "share_use failed"
undo_push share_unuse B4
track_share uso-wip-ba-s3
nc_data_closed                               # Unraid's «Add Share» opened Nextcloud's data folder (0777)
state CT-BINDS-ROOT share uso-wip-ba-s3
snap_before
before=$(stray)
[[ -z $before ]] || say "before the run already: $before"
expect_changes 'share|uso-wip-ba-s3|*' 'app|uso-wide|*' 'docker|known'      # the new share and the new app: real changes
flow BK-RUN-MANUAL mode=backup
now=$(stray)
[[ -z $now ]] && cell no-uso-backup-in-roots 1 "no .uso-backup outside appdata/<app>/ and domains/<vm>/" \
    || cell no-uso-backup-in-roots 0 "the run wrote .uso-backup into: $(paste -sd' ' <<<"$now")"
[[ -d /mnt/cache/appdata/jellyfin/.uso-backup ]] && cell inside-dataset 1 "appdata/jellyfin (a dataset) has its package inside" \
    || cell inside-dataset 0 "appdata/jellyfin (a dataset of its own) has no .uso-backup after the run"
check standard
check final
scenario_end
