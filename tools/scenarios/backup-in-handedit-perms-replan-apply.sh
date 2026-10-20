#!/bin/bash
# Cell B14 of matrix.md (batch E), variant PERMS: settings.ini made writable for everyone by hand (chmod 666). The engine
# refuses to read it (ub_data_trusted, exit 4 — backup/lib/common.sh) — never «exit 4» without a word: «Look at the
# server again» ends and the office says why (the Team Lead's must engine_trust with the command, the setup's answer
# names the cause, never a raw key or «?» on the page — INV-PAGE), Apply writes nothing (settings.ini byte for byte and
# its mode as the user left it — INV-SETTINGS strict, cell untouched). The office refuses every engine start
# here with engine_untrusted (the path and the Team Lead's command; up to 1.67.1 «Plan anew» answered ok and showed the old
# plan as fresh): «Plan anew» ends with that refusal — refuse=ok, the refusal is the answer. Pre-release (~2 min).
. "$(dirname "$0")/lib.sh"
scenario_begin backup-in-handedit-perms-replan-apply
needs any
cell() { local n=$1 ok=$2; shift 2; _row cell "$n" "$( ((ok)) && echo green || echo red)" "$*"; say "cell $n: $( ((ok)) && echo ok || echo RED) — $*"; }
state IN-HANDEDIT-PERMS
snap_before
m0=$(stat -c '%a %U %Y' "$UB/settings.ini")
t0=$SECONDS
flow BK-REPLAN refuse=ok
expect_err engine_untrusted
say "BK-REPLAN took $((SECONDS - t0)) s; setup-status: $(jq -c '{mode, running, result, code: (.code // .why // null), errors: ((.errors // []) | length)}' "$UB/state/setup-status.json" 2>/dev/null)"
evidence "$UB/state/setup-status.json" >/dev/null
# the office's word for it: the Team Lead's must, after his fresh look
ask caretaker.refresh '{}' 120 >/dev/null
f=$(jq -c '[.. | objects | select((.id // .key // "") == "engine_trust")] | first // empty' "$D/caretaker.json" 2>/dev/null)
jq -e '.ok == false' <<<"${f:-null}" >/dev/null 2>&1 && cell said-team-lead 1 "the Team Lead's must engine_trust is open: $(head -c 200 <<<"$f")" \
    || cell said-team-lead 0 "no open engine_trust finding at the Team Lead: ${f:-none}"
ask backup.refresh '{}' 120 >/dev/null
w=$(jq -c '[.. | strings | select(test("untrust|engine_trust|not root|settings_unsafe|data_trust"))] | unique | .[0:4]' "$D/backup.json" 2>/dev/null)
s=$(ask backup.setup_get '{}' 120 | jq -c '{status: (.status // .setup // null) | (if type == "object" then {result, code: (.code // .why // null)} else . end), error: (.error // null), plan_msgs: [(.plan.messages // [])[] | .code] | unique | .[0:12]}' 2>/dev/null)
say "Mr. Backupsy's state on it: $w; his setup's answer: $s"
[[ $w != '[]' && -n $w ]] || grep -q 'untrust\|engine_trust\|refused' <<<"$s" && cell said-setup 1 "the setup's own answer says it: ${w} ${s:0:200}" \
    || cell said-setup 0 "Mr. Backupsy's page has no word for the refusal (his state: ${w:-none}; setup: ${s:0:200})"
bubble_look perms backup caretaker
# Apply on the plan the page holds (the page's own body, desk.js setupDecisions()): its end is looked at, not required
if _bk_plan_get "$KEEP/plan-perms.json" && body=$(decisions_from_plan "$KEEP/plan-perms.json"); then
    ask backup.setup_apply "$body" 120 >/dev/null && wait_setup 600
    say "Apply: $(ask_last '{ok, error: (.error.key // null)}' | tr -d '\n'); setup-status $(jq -c '{mode, result, code: (.code // .why // null)}' "$UB/state/setup-status.json" 2>/dev/null)"
fi
[[ $(stat -c '%a %U %Y' "$UB/settings.ini") == "$m0" ]] && cell untouched 1 "settings.ini as the user left it ($m0)" \
    || cell untouched 0 "settings.ini changed: $m0 → $(stat -c '%a %U %Y' "$UB/settings.ini")"
check standard
scenario_end
