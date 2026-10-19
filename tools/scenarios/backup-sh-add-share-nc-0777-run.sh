#!/bin/bash
# The user adds a share (Unraid's «Add Share»): emhttpd also resets the exclusive share nextcloud's root to 0777
# nobody:users (every share root that isn't group users with an owner other than nobody — 7.4.0-rc.1, the same on «Apply»
# of any share), Nextcloud refuses its data folder (its page answers 503, occ refuses everything). Up to engine 2.61 the
# next backup failed (result failed, nc_datadir_readable) and nothing was backed up (H5 finding, 1.66.6). Since 2.62 the
# run goes on without the maintenance mode — Nextcloud is stopped with the apps — and says it as the warning
# nc_datadir_readable with the lasting fix (chown <Nextcloud's uid>:users, chmod 0770): announced below.
# INV-REPLAN was red up to 1.66.7: the new share kept the engine's Kopia proposal on the page, and the backup place
# UnraidSecretaryOffice followed it to Kopia (snapshot → kopia) — a change nobody chose. Since 1.66.8 a new share is
# local until asked. Since 1.66.8 the overview also offers «Close the folder…» (backup.nc_open_close): the cell
# nc-open-close clicks it — the root becomes Nextcloud's, group users, 0770 (Unraid leaves that alone), only the root,
# and the office no longer lists it. $1 = an older release (v1.66.7): the same scenario on that plugin, which must be red.
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-add-share-nc-0777-run
needs tower docker exclusive
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
[[ -n ${1:-} ]] && state IN-DOWNGRADE "$1"
nc_data_closed                               # the base as apps.sh made it — the state below breaks it, as a user's click does
undo_push nc_data_closed                     # … and closed again after the scenario (the next one finds the base)
state SH-POOL-ONLY uso-wip-h5-addshare
snap_before
expect_changes 'share|uso-wip-h5-addshare|*'      # the new share: its proposal is a real change
expect_warnings nc_datadir_readable               # engine 2.62: Nextcloud refuses to work — said, the run goes on
flow BK-RUN-MANUAL mode=backup
# the 0777 one-click: the folder the run named, listed while open; the dialog's button; its root only, then not listed
nc=$(state_json backup '.nc_open[0].name // empty' 2>/dev/null)
ncp=$(state_json backup '.nc_open[0].path // empty' 2>/dev/null)
t=$(readlink -f /mnt/user/nextcloud 2>/dev/null)
inner=$(find "$t" -mindepth 1 -maxdepth 1 -printf '%u:%g %m %f\n' 2>/dev/null | sort | md5sum)
if [[ -z $nc ]]; then
    cell nc-open-close 0 "the office lists no open Nextcloud data folder (state.nc_open) after the run's nc_datadir_readable"
elif ! ask backup.nc_open_close "$(jq -cn --arg n "$nc" '{name: $n}')" >/dev/null; then
    cell nc-open-close 0 "«Close the folder» for $nc refused: $(ask_last '.error.key')"
else
    now=$(stat -c '%u:%G %a' "$t")
    left=$(state_json backup '[.nc_open[]?.name] | join(",")' 2>/dev/null)
    after=$(find "$t" -mindepth 1 -maxdepth 1 -printf '%u:%g %m %f\n' 2>/dev/null | sort | md5sum)
    [[ $now == '33:users 770' && -z $left && $after == "$inner" ]] \
        && cell nc-open-close 1 "$nc's $ncp closed: $now, nothing inside changed, no longer listed" \
        || cell nc-open-close 0 "$nc's $ncp now «$now», still listed «$left», inside $([[ $after == "$inner" ]] && echo same || echo CHANGED)"
fi
check standard
check final
scenario_end
