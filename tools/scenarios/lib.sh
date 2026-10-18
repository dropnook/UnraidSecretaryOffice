#!/bin/bash
# tools/scenarios/lib.sh — the one shared library every scenario sources (resilience programme, playbook §1–2).
# Runs on a test VM as root (bash, `set -u`, never `set -e`). A scenario:
#
#   . "$(dirname "$0")/lib.sh"
#   scenario_begin <name>        # opens the report, reads the facts
#   needs tower                  # unmet → report says why, scenario_end, exit 2
#   state SOME-STATE [args]      # state_some_state args; undo_some_state args pushed on the undo stack
#   snap_before                  # the «before» snapshot (invarianten.md «vorher»)
#   flow … / ask …               # what the button does
#   check standard               # the standard invariants (allow INV-X <why> before it)
#   scenario_end                 # verdict table, undo in reverse, exit 0 green | 1 red | 2 skipped | 3 undo failed
#
# INTERFACE (frozen after wave 1; H2 states-*.sh, H5 flows.sh/checks.sh build on it):
#   Facts      P D UB PLACE RUN KEEP REPORT SC_NAME SC_DIR SC_START      host_is tower|test3|any   has <thing>
#              needs <thing>…   (things: tower test3 any docker vm zfs btrfs kopia minio jack partner reboot vmagent)
#   Report     say <text…>   say_cmd <cmd…> (runs it, its output into the report)   evidence <file|dir> [name]
#   Mailbox    ask <desk.action> [json] [timeout]   ask_err <desk.action> [json] [timeout]   ask_save_post <file> <desk.action> [json]
#              ASK_LAST (the last answer JSON, state stripped), ASK_ERR (its error key, empty when ok)
#   Agent      agent_wait [s]   agent_mark_restart   agent_restart   agent_log_mark [name]   agent_log_since [name]   doorbell_ok
#              state_json <desk>[-<part>] [jq filter]
#   Waiters    minute_wait   wait_backup [s]   wait_setup [s]   wait_restore <id> [s]   wait_drill [s]   wait_moverelli [s]
#              wait_night [s]   wait_orphans [s]   wait_letgo [s]   wait_for <s> <what> <cmd…> (the generic one)
#   States     state <ID> [args]   undo_push <cmd…>   (H2: define state_<id> / undo_<id>; ID lower case, - → _)
#              track_share <share>… (shares whose files snap_before/after inventory)   KEEP_STASH=$KEEP/stash
#   Snapshots  snap_before   snap_after   inventory <share>…   where_is <path>   ini_diff <a> <b>
#   Checks     check standard|final|<INV-ID>…   allow <INV-ID> <why>   expect_warnings <code>…   expect_err <key>
#              expect_diff <spec>…   expect_changes <spec>…   expect_bubble <desk> <key>…   expect_no_text <desk> <key>…
#              check_<inv_id> (INV-NO-WAKE → check_inv_no_wake): prints the violation in one line;
#              returns 0 green · 1 red · 2 pending (reason printed) · 3 not applicable (reason printed)
#   Exit codes 0 green · 1 red · 2 skipped (needs unmet) · 3 undo failed (the VM is dirty: the runner resets it)
#
# Helpers say what they do (into the report), return 0/1 and never exit — except scenario_begin (wrong host / no plugin)
# and needs (unmet → scenario_end → exit 2). Secrets (scenarios.env) are never printed; INV-NO-SECRET greps for them.
# `lib.sh --self-test` (run as a script) makes a uso-wip-h1-st share, files, a container, sleeps a disk, undoes all.

# ---------------------------------------------------------------------------------------------------------------- facts
P=/usr/local/emhttp/plugins/unraid-secretary-office
RUN=/var/run/unraid-secretary-office
SC_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)       # tools/scenarios of the synced tree
SC_TREE=$(cd "$SC_DIR/../.." && pwd)
OFFICE_CFG=/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg
SCENARIOS_ENV=/boot/config/plugins/unraid-secretary-office/scenarios.env
TOWER_IP=192.168.7.111
TEST3_IP=192.168.7.197
D= UB= PLACE= KEEP= REPORT= SC_NAME= SC_START=
KEEP_STASH=

# TODO: _facts — D from DATA_DIR in $OFFICE_CFG (pool path for an exclusive share), UB=$D/unraid-backup,
# PLACE from $UB/settings.ini (general|dumps_share → /mnt/user/<share>/backup)

host_is() { :; }          # TODO: tower|test3|any — by IP (both VMs are called «Tower»)
has() { :; }              # TODO: docker vm zfs btrfs kopia minio jack partner vmagent
needs() { :; }            # TODO: every thing given; unmet → say why, scenario_end with exit 2

# --------------------------------------------------------------------------------------------------------------- report
say() { :; }              # TODO: a line into the report and to stdout
say_cmd() { :; }          # TODO
evidence() { :; }         # TODO: copy into $KEEP/evidence, listed in the report

scenario_begin() { :; }   # TODO: <name> — report header (host, OFFICE_VERSION, UB_VERSION, Unraid, git describe), KEEP
scenario_end() { :; }     # TODO: verdict table, undo in reverse, exit 0/1/2/3

# -------------------------------------------------------------------------------------------------------------- mailbox
ASK_LAST= ASK_ERR=
ask() { :; }              # TODO: tools/scenarios/ask.php; prints the answer JSON; 1 on ok:false (key into the report)
ask_err() { :; }          # TODO: expects a refusal, prints error.key; 1 when it was accepted
ask_save_post() { :; }    # TODO: <file> <desk.action> [json] — the answer as a canned dialog answer (tests/ui/states/post)

# ---------------------------------------------------------------------------------------------------------------- agent
agent_wait() { :; }
agent_mark_restart() { :; }
agent_restart() { :; }
agent_log_mark() { :; }
agent_log_since() { :; }
doorbell_ok() { :; }
state_json() { :; }

# -------------------------------------------------------------------------------------------------------------- waiters
wait_for() { :; }
minute_wait() { :; }
wait_backup() { :; }
wait_setup() { :; }
wait_restore() { :; }
wait_drill() { :; }
wait_moverelli() { :; }
wait_night() { :; }
wait_orphans() { :; }
wait_letgo() { :; }

# --------------------------------------------------------------------------------------------------------- states, undo
state() { :; }
undo_push() { :; }
track_share() { :; }

# ------------------------------------------------------------------------------------------------------------ snapshots
snap_before() { :; }
snap_after() { :; }
inventory() { :; }
where_is() { :; }
ini_diff() { :; }

# --------------------------------------------------------------------------------------------------------------- checks
check() { :; }
allow() { :; }
expect_warnings() { :; }
expect_err() { :; }
expect_diff() { :; }
expect_changes() { :; }
expect_bubble() { :; }
expect_no_text() { :; }

# ------------------------------------------------------------------------- the other parts (H2 states, H5 flows/checks)
for _f in "$SC_DIR"/states-*.sh "$SC_DIR"/flows.sh "$SC_DIR"/checks.sh; do
    [[ -f $_f ]] && . "$_f"
done
unset _f
