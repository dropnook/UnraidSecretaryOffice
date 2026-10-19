#!/bin/bash
# Cell M22 of matrix.md (batch E): an old install updated (IN-UPDATE-OLD: v1.60.0 with the 1.60 fixture), then the
# plugin manager's update to the release installed here (OF-UPDATE). The fixture has no Ms. Moverelli (in training in
# 1.60, hired by none): her ini as a 1.61 install left it — the halves (X and her target Y, move_until_used_below), Jack
# Emby's slice and floor, age_stat=mtime, in her writer's own form — is laid into the old data folder before the update, Unraid's mover switched
# off as her 1.61 setup did (put back by the undo). After the update: Y, the slice and the floor are gone, X stays,
# age_stat=newest, the ini of before kept as smart_mover.ini.before-<version> (cell halves-gone), the night-run step
# leaves one line of hers at most and none of Jack Streamer's EmbyCache (INV-CRON-ONE, cell night-run); hired, her
# setup opened and saved without a change leaves the ini as it was (MV-RESAVE, INV-REPLAN). Nightly (~8 min).
. "$(dirname "$0")/lib.sh"
scenario_begin moverelli-in-update-old-resave
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
mover_now() { tr -d '\r' </boot/config/share.cfg | sed -n 's/^shareMoverSchedule="\(.*\)"$/\1/p'; }
cur=$(_st_office_version)
INI=$D/moverelli/smart_mover.ini
m0=$(mover_now)
state IN-UPDATE-OLD 1.60
undo_push unraid_schedule_set "$m0"
unraid_schedule_set '' || _row state prepare red "Unraid's mover schedule not switched off"
mkdir -p "$D/moverelli" && cat >"$INI" <<INI
# Ms. Moverelli (Unraid Secretary Office) - written by the office; change it on her setup page
[GLOBAL]
log_file=$D/moverelli/smart_mover.log
min_age=30
move_when_used_above=80
move_until_used_below=70
jack_slice_percent=30
jack_min_percent=20
age_stat=mtime
move_prefer_shares=yes
mover_debug=0
max_list=50

[uso-yes]
min_age=30
move_until_used_below=60
INI
chmod 600 "$INI"
snap_before
flow OF-UPDATE "plg=$OFFICE_RELEASES/v$cur/unraid-secretary-office.plg"
evidence "$INI" smart_mover-after-update.ini >/dev/null
left=$(grep -E '^(move_until_used_below|jack_slice_percent|jack_min_percent)=' "$INI" | paste -sd' ' -)
[[ -z $left ]] && grep -qx 'move_when_used_above=80' "$INI" && grep -qx 'age_stat=newest' "$INI" && ls "$INI".before-* >/dev/null 2>&1 \
    && cell halves-gone 1 "Y, slice and floor gone, X 80 kept, age_stat=newest, the old ini kept ($(ls "$INI".before-* | xargs -n1 basename | paste -sd' ' -))" \
    || cell halves-gone 0 "after the update: left «${left:-none}», X $(grep -c '^move_when_used_above=80$' "$INI"), age_stat $(grep '^age_stat=' "$INI" | paste -sd' ' -), kept $(ls "$INI".before-* 2>/dev/null | wc -l)"
c=$(grep -v '^#' "$OFFICE_FLASH"/*.cron 2>/dev/null | grep -cE 'job\.sh (embycache|moverelli)( |$)')
grep -v '^#' "$OFFICE_FLASH"/*.cron 2>/dev/null | grep -q 'job\.sh embycache' && cell night-run 0 "Jack Streamer's EmbyCache line still in the cron file" \
    || cell night-run 1 "no EmbyCache line of its own; her lines: $c"
flow OF-HIRE desk=moverelli
flow MV-RESAVE
allow INV-SETTINGS "the update's migration rewrites her ini (cell halves-gone) and the Team Lead hires her (OF-HIRE) — both this scenario's flow; her re-save is MV-RESAVE's own check"
allow INV-SETUP-MSGS "docker_volumes is the engine's coded warning (setup.sh:1420 wrn_code — its text is not a setup.msg key) and «more» a continuation line (setup.sh:1423 hint_as more): the check's gap, a helper follow-up"
check standard
scenario_end
