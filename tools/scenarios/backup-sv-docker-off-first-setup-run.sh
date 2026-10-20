#!/bin/bash
# Cell B26 of matrix.md (batch E): Docker switched off by the user (SV-DOCKER-OFF cfg: ⟦Enable Docker⟧ No in docker.cfg and
# the service stopped — only stopped, with docker.cfg still «yes», is «switched on but silent», which by design ends a run
# as an error: engine 2.54, ub_docker_service_off). Mr. Backupsy's setup (plan, the page's draft as it is, Apply) says «Docker is switched
# off» (setup.msg docker_off) and keeps every [app "…"] and [docker] line as it was («Docker not answering: nobody
# knows - kept as it is», engine 2.54: cell apps-kept); the run backs up shares and the flash, no apps, ends ok or with
# announced warnings; the page says «Docker isn't running» (common.docker_off), never «0 of 0» (INV-PAGE). settings.ini
# put back afterwards. The dumps stay set up (engine 2.64), so a run with Docker off skips each one with the warning
# dump_stopped (its database can't run) — announced, not a fault. Pre-release (~4 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-sv-docker-off-first-setup-run
needs docker
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
apps() { _ini_flat "$1" | grep -E '^(app|docker)\|' ; }
mkdir -p "$KEEP_STASH/E-INI" && cp -p "$UB/settings.ini" "$KEEP_STASH/E-INI/settings.ini"
undo_push bash -c 'cp -p "$1/settings.ini" "$2" && rm -rf "$1"' _ "$KEEP_STASH/E-INI" "$UB/settings.ini"
nc_data_closed
state SV-DOCKER-OFF cfg
expect_warnings dump_stopped                     # each kept dump skipped while Docker is off (engine 2.64)
snap_before
apps "$UB/settings.ini" >"$KEEP/apps-before.txt"
if flow BK-FIRST-SETUP; then
    jq -e '[.messages[]? | .code] | index("docker_off")' "$KEEP/plan-first.json" >/dev/null 2>&1 \
        && cell setup-says 1 "the plan says docker_off" \
        || cell setup-says 0 "no docker_off in the plan: $(jq -c '[.messages[]? | .code] | unique' "$KEEP/plan-first.json" | head -c 300)"
fi
d=$(diff "$KEEP/apps-before.txt" <(apps "$UB/settings.ini") | grep '^[<>]' | head -6)
[[ -z $d ]] && cell apps-kept 1 "every [app]/[docker] line as before ($(grep -c . "$KEEP/apps-before.txt") lines)" \
    || cell apps-kept 0 "Apply changed [app]/[docker] with Docker off: ${d//$'\n'/ | }"
expect_diff ''                                   # Apply on the page's draft as it is: no value changes
flow BK-RUN-MANUAL mode=backup
st=$UB/state/status.json
jq -e '(.result == "ok" or .result == "warnings") and (.errors // 0) == 0' "$st" >/dev/null \
    && cell run-ok 1 "the run: $(jq -c '{result, warnings}' "$st")" || cell run-ok 0 "the run: $(jq -c '{result, errors, warnings, message}' "$st")"
# what the page draws «Docker isn't running» from (desk.js ~873: containers.up === false — page.mjs can't want an office
# key, common.*: its --want takes desk keys and office.* only); INV-PAGE checks the page has no «0 of 0»
ask backup.refresh '{}' 120 >/dev/null
[[ $(state_json backup '.containers.up' 2>/dev/null) == false ]] && cell page-docker-off 1 "Mr. Backupsy's state: containers.up false (the page says common.docker_off)" \
    || cell page-docker-off 0 "Mr. Backupsy's state doesn't say Docker is off: containers $(state_json backup '.containers | {up, n: ((.list // []) | length)}' 2>/dev/null | tr -d '\n')"
check standard
check final
scenario_end
