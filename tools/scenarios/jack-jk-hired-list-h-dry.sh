#!/bin/bash
# Batch H, finding 2 (parking lot «Resilienz-Funde»): «MediaCache's dry run never writes Jack Streamer's list». Is that
# right? By design yes (mediacache/README.md: dry = «nothing changed»; mediacache_run.py writes the exclude list only in
# run_mode — write_exclude() under `if self.run_mode`), so the cell holds what must be true: his dry run («Try it out»,
# jack.start_run {mode: dry}) leaves his list exactly as it was — the same bytes, the same time (cell dry-list-same) — and
# with his list gone, a dry run doesn't make one either (cell dry-no-list) — only his look does (jackListEnsure, finding
# 1: moverelli-jk-list-empty-h-run). His dry run itself ends (cell dry-ends) and says it changed nothing (its status
# mode dry). His Emby answers 401 here (the stand-in key, h_jack_hire_standin): the dry run can't plan — the list must
# stay all the same. Ms. Moverelli set up for the scenario, put back after.
# Pre-release (~3 min).
. "$(dirname "$0")/lib.sh"
scenario_begin jack-jk-hired-list-h-dry
needs docker
h_mv_set_up H2 || _row state MV-SETUP red "her setup failed"
put_files H2 disk2 media/uso-wip-h-fill 1 --size 4096 || _row state put_files red "the file for his fill could not be written"
undo_push files_unput H2
undo_push bash -c 'rm -rf /mnt/*/media/uso-wip-h-fill; exit 0'
h_jack_hire_standin H2; rc=$?; undo_push jack_unhire H2
((rc == 2)) && { echo "needs Jack Streamer's stand-in key" >"$KEEP/.skip"; scenario_end; }
((rc == 0)) || { _row state JK-HIRED-LIST red "h_jack_hire_standin failed"; say "the state isn't there: no flow, no cells"; scenario_end; }
l=$(_st_jack_list)
st=$D/mediacache/status.json
dry_wait() {      # his dry run handed over (atd), then its end: no mediacache_run.py, a status newer than the start
    local t0=$1
    wait_for 60 "his dry run to start" bash -c "pgrep -f '[m]ediacache_run[.]py' >/dev/null || (( \$(stat -c %Y '$st' 2>/dev/null || echo 0) >= $t0 ))"
    wait_for 600 "his dry run to end" bash -c "! pgrep -f '[m]ediacache_run[.]py' >/dev/null && (( \$(stat -c %Y '$st' 2>/dev/null || echo 0) >= $t0 ))"
}
track_share media
snap_before
files_strict
before=$(md5sum <"$l") bt=$(stat -c %Y "$l")
t0=$(_sc_now)
ask jack.start_run '{"mode":"dry"}' >/dev/null && expect_ok jack.start_run
dry_wait "$t0" && h_cell dry-ends 1 "his dry run ended: $(jq -c '{mode: (.mode // null), result: (.result // null), exit: (.exit // null)}' "$st" 2>/dev/null)" \
    || h_cell dry-ends 0 "his dry run didn't end within 10 min"
evidence "$st" status-dry1.json >/dev/null
[[ $(md5sum <"$l") == "$before" && $(stat -c %Y "$l") == "$bt" ]] && h_cell dry-list-same 1 "his list the same bytes and time after the dry run ($(grep -c . "$l") lines)" \
    || h_cell dry-list-same 0 "his dry run changed his list: $(grep -c . "$l") lines now, mtime $(stat -c %y "$l")"
jack_list_remove H2 && undo_push jack_list_back H2 || _row state JK-LIST-GONE red "his list could not be taken away"
t0=$(_sc_now); sleep 1
ask jack.start_run '{"mode":"dry"}' >/dev/null && expect_ok jack.start_run
dry_wait "$((t0 + 1))" || h_cell dry-ends 0 "his second dry run (list gone) didn't end"
evidence "$st" status-dry2.json >/dev/null
# his look (the answer to start_run is his fresh state: jackScan → jackListEnsure) writes an EMPTY list when it is missing
# (finding 1); a dry run that wrote one would write what of his lies on the pool (our fill's file) — so: empty or none
if [[ -f $l ]]; then
    c=$(grep -c . "$l")
    ((c == 0)) && h_cell dry-no-list 1 "the dry run wrote nothing; the list there is the empty one his look wrote ($(stat -c %y "$l"))" \
        || h_cell dry-no-list 0 "a list with $c lines after a dry run with the list gone"
else
    h_cell dry-no-list 1 "no list after his dry run with the list gone"
fi
check standard
scenario_end
