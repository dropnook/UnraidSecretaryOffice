#!/bin/bash
# Batch H, finding 6 (parking lot «Resilienz-Funde», H4's wave 1b): Kopia's image declares VOLUMEs (/backups — where its
# local filesystem repository goes —, /source, /cache, /config); a template that doesn't bind one leaves it to an
# anonymous Docker volume, and every re-made container (an update, a template edit: Unraid's rebuild_container) gets a
# NEW empty one — what was in it is gone from Kopia (the old volume dangles in Docker's folder). USOTest3VM's base binds
# them (kopia_bind); this cell takes the template as CA ships it (/backups and /source not bound), the container re-made
# from it, a file in /backups (a local repository's stand-in), then re-made again. Expected: Mr. Backupsy or the
# Consultant notice — before: «Look at the server again» (or the Consultant's look) names Kopia's anonymous volumes
# (cell said-before; the engine names other apps' volumes — docker_volumes — but leaves Kopia out, setup.sh ~1495);
# after the re-make: something says what vanished from /backups (cell said-after). Cell gone records the mechanism
# (info). The template and the container put back, the anonymous volumes the scenario made removed. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-kp-volumes-recreated-h-replan
needs docker kopia
tpl=$(_st_kopia_tpl)
[[ -f $tpl ]] || { echo "no Kopia template" >"$KEEP/.skip"; scenario_end; }
mkdir -p "$KEEP_STASH/H6" && cp -p "$tpl" "$KEEP_STASH/H6/template.xml"
docker volume ls -q | sort >"$KEEP_STASH/H6/volumes-before"
vols_back() {     # the template and the container as before, the volumes this scenario made gone
    [[ -f $KEEP_STASH/H6/template.xml ]] || return 0
    cp -p "$KEEP_STASH/H6/template.xml" "$tpl" && kopia_make || return 1
    local v; for v in $(comm -13 "$KEEP_STASH/H6/volumes-before" <(docker volume ls -q | sort)); do
        [[ $v =~ ^[0-9a-f]{64}$ ]] && docker volume rm "$v" >/dev/null 2>&1 && say "undo: anonymous volume ${v:0:12}… removed"; done
    rm -rf "$KEEP_STASH/H6"
}
undo_push vols_back
ct_vols() { docker inspect -f '{{range .Mounts}}{{if eq .Type "volume"}}{{.Destination}}={{printf "%.12s" .Name}} {{end}}{{end}}' kopia 2>/dev/null; }
said_kopia() {     # what the office says of Kopia's volumes: the plan's messages, his state, the Consultant's, the Team Lead's
    local f
    for f in "$UB/state/setup-plan.json" "$UB/state/setup-status.json" "$D/backup.json" "$D/advisor.json" "$D/caretaker.json"; do
        [[ -f $f ]] && jq -c --arg f "$(basename "$f")" '[.. | objects | select((.code? // .key? // .id? // "") | type == "string" and length > 0)
            | select((.code // .key // .id | tostring | test("volume"; "i")) and (tostring | test("kopia"; "i")))
            | {f: $f, code: (.code // .key // .id)}] | unique | .[]' "$f" 2>/dev/null
    done
}
# the template as CA ships it: /backups and /source not bound
awk '!/Target="\/(backups|source)"/' "$KEEP_STASH/H6/template.xml" >"$tpl.uso-new" && mv "$tpl.uso-new" "$tpl"
kopia_make || _row state KP-VOLUMES red "Kopia not re-made from the template without the binds"
say "Kopia's anonymous volumes now: $(ct_vols)"
[[ $(ct_vols) == *"/backups="* ]] || _row state KP-VOLUMES red "/backups is no anonymous volume: $(ct_vols)"
timeout 30 docker exec kopia sh -c 'mkdir -p /backups/uso-wip-h-repo && date > /backups/uso-wip-h-repo/kopia.repository.f' \
    || _row state KP-VOLUMES red "nothing written into /backups"
snap_before
flow BK-REPLAN
ask advisor.refresh '{}' 120 >/dev/null
ask caretaker.refresh '{}' 120 >/dev/null
b=$(said_kopia)
[[ -n $b ]] && h_cell said-before 1 "said of Kopia's volumes: $(paste -sd' ' <<<"$b")" \
    || h_cell said-before 0 "nothing names Kopia's anonymous volumes ($(ct_vols)) — the engine's docker_volumes leaves Kopia out"
v0=$(ct_vols)
kopia_make || _row state KP-RECREATED red "Kopia not re-made"
v1=$(ct_vols)
there=$(timeout 30 docker exec kopia sh -c 'ls /backups/uso-wip-h-repo 2>/dev/null | wc -l')
h_info gone "re-made: volumes $v0 → $v1; /backups/uso-wip-h-repo: $( ((there > 0)) && echo still there || echo gone from the container)"
flow BK-REPLAN
ask backup.refresh '{}' 120 >/dev/null
ask advisor.refresh '{}' 120 >/dev/null
ask caretaker.refresh '{}' 120 >/dev/null
a=$(said_kopia)
[[ -n $a ]] && h_cell said-after 1 "said: $(paste -sd' ' <<<"$a")" \
    || h_cell said-after 0 "after the re-make nothing says /backups is a new empty volume (the old one dangling in Docker's folder)"
check standard
scenario_end
