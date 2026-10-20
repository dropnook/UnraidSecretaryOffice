#!/bin/bash
# Cell B14 of matrix.md (batch E), variant DUP: a key of [general] twice in settings.ini (pasted twice by hand, the same
# value). «Look at the server again» takes it as one key — no settings_error, no value changed, the Apply list names
# nothing (INV-REPLAN); Apply on that plan writes the same values once (cell same-values: values as a set). Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-in-handedit-dup-replan-apply
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
flat() { tr -d '\r' <"$1" | sed '1s/^\xEF\xBB\xBF//' >"$KEEP/flat.tmp" && _ini_flat "$KEEP/flat.tmp" | LC_ALL=C sort -u; }
state IN-HANDEDIT-DUP
snap_before
flat "$UB/settings.ini" >"$KEEP/values-before.txt"
if flow BK-REPLAN; then
    p=$KEEP/plan-replan.json
    cr=$(jq -r '[.P | to_entries[] | select((.value | tostring) | test("\r")) | .key] | join(" ")' "$p")
    [[ -z $cr ]] && cell no-cr 1 "no value of the plan carries a «\\r»" || cell no-cr 0 "values with «\\r» in the plan: $cr"
    er=$(jq -r '[.messages[]? | select(.code == "settings_error") | .params.error // .text // "?"] | join(" | ")' "$p")
    [[ -z $er ]] && cell no-settings-error 1 "no settings_error" || cell no-settings-error 0 "settings_error: ${er:0:300}"
fi
flow BK-APPLY plan=old
d=$(diff <(cat "$KEEP/values-before.txt") <(flat "$UB/settings.ini") | grep '^[<>]' | head -6)
[[ -z $d ]] && cell same-values 1 "the same values as before (line ends left out)" || cell same-values 0 "values changed by Apply: ${d//$'\n'/ | }"
allow INV-SETTINGS "Apply writes settings.ini anew (setup.sh's header: «Another setup.sh run takes over all values but writes the comments anew») — the values are compared by cell same-values"
check standard
scenario_end
