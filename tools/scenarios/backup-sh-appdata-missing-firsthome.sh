#!/bin/bash
# Cell B28 of matrix.md (wave 3), the appdata half (the office share's half: backup-sh-office-share-missing-first-setup-
# gate): a server with Docker switched off (SV-DOCKER-OFF cfg: DOCKER_ENABLED="no") and no appdata share
# (SH-APPDATA-MISSING: its roots and cfg aside — as a NAS that never had Docker). The office's data folder lies in
# appdata, so the agent waits for it and the page shows its welcome from RAM (agent/lib/firsthome.php). What must hold:
#   home-look     RUN/home.json says the data folder's share appdata is missing and new (fresh: Unraid never knew it,
#                 Docker off) — the welcome offers Unraid's own «Add Share» on the user's click
#   nothing-made  a minute later nothing made appdata by itself: no root on a pool or disk, no cfg on the flash,
#                 shares.ini doesn't list it (CLAUDE.md: the office never creates a share without the click)
#   agent-waits   the agent waits (one agent.php at work, its heartbeat in RAM fresh) — no crash loop, no data folder
#                 made somewhere else
# The click itself is not made: «Add Share» would make a new appdata the undo then couldn't put the old one back over.
# Both states are undone (appdata back, Docker on, what ran runs again) before the invariants. Nightly (~5 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sh-appdata-missing-firsthome
needs tower
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
HOME_JSON=$RUN/home.json
nc_data_closed
snap_before
touch "$KEEP/.t0"
state SV-DOCKER-OFF cfg
state SH-APPDATA-MISSING
share_exists appdata && { _row state SH-APPDATA-MISSING red "Unraid still lists appdata"; scenario_end; }
if wait_for 60 "the agent's welcome look (home.json)" bash -c "jq -e '.share == \"appdata\"' '$HOME_JSON' >/dev/null 2>&1"; then
    look=$(jq -c '{share, fresh, missing, optional, pool}' "$HOME_JSON")
    cp -p "$HOME_JSON" "$KEEP/home.json" && evidence "$KEEP/home.json" >/dev/null
    [[ $(jq -r '.fresh' <<<"$look") == true ]] && jq -e '[.missing[]? | select(.share == "appdata" and .fresh)] | length == 1' <<<"$look" >/dev/null \
        && cell home-look 1 "the welcome: $look" || cell home-look 0 "the welcome says: $look (appdata new, offered, expected)"
else
    cell home-look 0 "no welcome look in $HOME_JSON a minute after appdata went: $(head -c 300 "$HOME_JSON" 2>/dev/null)"
fi
sleep 45
roots=$(for b in $(bases); do [[ -e /mnt/$b/appdata ]] && echo "/mnt/$b/appdata"; done | paste -sd' ' -)
cfg=$( [[ -f /boot/config/shares/appdata.cfg ]] && echo yes || echo no )
listed=$(share_exists appdata && echo yes || echo no)
[[ -z $roots && $cfg == no && $listed == no ]] && cell nothing-made 1 "after 105 s: no appdata root, no cfg, not listed" \
    || cell nothing-made 0 "made by itself: roots ${roots:-none}, cfg $cfg, listed $listed"
n=$(pgrep -fc '[a]gent\.php run')
age=$(( $(_sc_now) - $(stat -c %Y "$RUN/agent.json" 2>/dev/null || echo 0) ))
elsewhere=$(find /mnt/user /mnt/cache /mnt/disk* -maxdepth 3 -path '*UnraidSecretaryOffice/data' -newer "$KEEP/.t0" -type d 2>/dev/null | head -2 | paste -sd' ' -)
[[ $n == 1 ]] && ((age < 60)) && [[ -z $elsewhere ]] && cell agent-waits 1 "one agent waiting, heartbeat ${age}s old" \
    || cell agent-waits 0 "agents: $n, heartbeat ${age}s old, a data folder made elsewhere: ${elsewhere:-none}"
# the states end before the invariants (they read the data folder): appdata back, Docker on, the agent at work again
agent_mark_restart
undo_sh_appdata_missing || _row undo SH-APPDATA-MISSING red "appdata not put back"
undo_sv_docker_off || _row undo SV-DOCKER-OFF red "Docker not back"
agent_wait 180
allow INV-TICK "the agent waits for its data folder without a heartbeat on purpose (agent.sh watch: «no data folder (yet): the page says why») and the state stops it around the move"
check standard
scenario_end
