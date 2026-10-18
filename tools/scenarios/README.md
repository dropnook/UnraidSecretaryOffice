# Scenarios — real states, real flows, the invariants checked

A scenario puts a test VM into a real state, does what a button does (the agent's mailbox, the same request), checks
the invariants, writes a report and undoes the state. The spec is the resilience playbook (briefs folder,
`resilienz/playbook.md`); the catalogue is `zustaende.md` (states), `ablaeufe.md` (flows), `invarianten.md` (checks) and
`matrix.md` (which cells). **Only on Tower (192.168.7.111) and USOTest3VM (192.168.7.197)** — `scenario_begin` refuses any
other host. Never USOPartner, never nostromo.

## Writing one

One file `tools/scenarios/<desk>-<state>-<flow>.sh`, run on the VM as root:

```bash
#!/bin/bash
# <one line: the matrix cell and what must hold>
. "$(dirname "$0")/lib.sh"
scenario_begin backup-vm-paused-by-user-run
needs tower                                # unmet: the report says why, exit 2
state VM-PAUSED-BY-USER                    # state_vm_paused_by_user; undo_vm_paused_by_user pushed for the end
snap_before                                # after the state, before the flow
flow BK-RUN-MANUAL mode=backup             # flow_bk_run_manual (flows.sh)
expect_warnings vm_late_paused
check standard                             # allow INV-X <why> before it — a documented product decision only
check final
scenario_end                               # verdict table, undo in reverse, exit code
```

Rules: helpers only (never `docker`, `zfs`, `ask.php` directly when a helper exists); one cell per file; assert only
what the cell says — the invariants do the rest. A state that makes or changes a share calls `track_share` (only
tracked shares are inventoried: a look at every share would touch every disk). On Tower, everything that changes live
state runs as `flock -o /tmp/uso-scenarios.lock bash tools/scenarios/<name>.sh` — **`-o`**: without it the command inherits
the lock's fd, and an agent (re)started from it keeps the lock for its whole life. Scratch objects are named `uso-wip-<you>-…`.

## The helpers (lib.sh — its header is the interface)

| Group | Helpers |
|---|---|
| Facts | `P D UB PLACE RUN KEEP KEEP_STASH REPORT SC_NAME SC_START` · `host_is tower\|test3\|any` · `has docker\|nodocker\|vm\|vmagent\|hdd\|zfs\|btrfs\|kopia\|minio\|jack\|partner\|pool2\|compose\|exclusive` · `needs …` · `bases` · `disks_asleep` · `base_asleep <base>` · `share_asleep <share>` · `lock_held <file>` |
| Report | `say` · `say_cmd <cmd…>` · `evidence <file> [name]` · `queue_mac <INV-ID> plan_changes\|page_text <args…>` |
| Mailbox | `ask <desk.action> [json] [timeout]` · `ask_err …` (prints the refusal's key) · `ask_last [jq]` · `ask_save_post <desk.action> [json]` (canned dialog answer → `/tmp/uso-scenarios/post/`) |
| Agent | `agent_wait [s]` · `agent_mark_restart` · `agent_restart` · `agent_log_mark [name]` · `agent_log_since [name]` · `doorbell_ok` · `state_json <desk>[-part] [jq]` |
| Waiters | `minute_wait` · `wait_backup` · `wait_setup` · `wait_restore <id>` · `wait_drill` · `wait_moverelli` · `wait_night` · `wait_orphans` · `wait_letgo` · `wait_for <s> <what> <cmd…>` |
| States | `state <ID> [args]` · `flow <ID> [k=v…]` · `undo_push <cmd…>` · `track_share <share>…` · `files_strict` |
| Snapshots | `snap_before` · `snap_after` · `inventory <share>…` · `where_is <path>` · `ini_diff <a> <b>` |
| Checks | `check standard\|final\|<INV-ID>…` · `allow <INV-ID> <why>` · `expect_ok` · `expect_err <key>` · `expect_warnings` · `expect_diff` · `expect_changes` · `expect_bubble` · `expect_no_text` |

`ask.php` is the button: `php ask.php [--wait s] [--raw] [--save-post file] <desk.action> [json]` → the plugin's
`askAgent()` with api.php's checks (action shape, desk known, hired unless `refresh`); `office.hire|fire` through
`officeStaffAction()` like the page. Never a `secret`.

Waiters first wait (≤ 90 s, softly) for the job to start after the last accepted `ask`, then for its end; a timeout is a
red row «timed out waiting for X». Inventories are read on each awake disk and pool (never through /mnt/user); a base
asleep is marked `#asleep` and left out of the comparison.

### Checks of wave 1

INV-SETTINGS (+ INV-DIFF-EXACT with `expect_diff 'share|media|mode: local→kopia'`), INV-FILES, INV-FILES-STRICT (after
`files_strict`), INV-LEFTOVER, INV-NO-STALE-LOCK, INV-COUNTS (backup part: packages on the place vs `backup.json`, a fresh
run's counts vs its history row), INV-AGENT, INV-UNRAID-SAME, INV-NO-WAKE, INV-NOTIFY-ONCE, INV-NO-SECRET, INV-TICK,
INV-CRON-ONE. A check of the standard set without its function (INV-REPLAN, INV-PAGE, INV-BUBBLE — wave 1b, `checks.sh`)
is «pending (H5)», never green. A check function prints one line and returns 0 green · 1 red · 2 pending · 3 n/a.

Deviations from `invarianten.md`: the drill settings live in `$D/restore-drill/settings.json`; `btrfs-snap` may hold
symlinks (the engine's btrfs view — only mounts or other files are leftovers); INV-NO-WAKE can't be shown for real in
the VMs (QEMU ignores STANDBY) — `needs hdd` marks scenarios that need a real disk; the syslog part of INV-NO-WAKE is not
built; INV-COUNTS' per-share snapshot count isn't (backup.json carries none).

## Exit codes and files

`0` green · `1` red · `2` skipped (`needs` unmet, or a state returned 2) · `3` undo failed (the VM is dirty: the runner resets it).
Report `/tmp/uso-scenarios/<date>/<name>.md` (or `$USO_SC_OUT/<name>.md` from the runner) with a last line
`VERDICT <green|red|skipped|dirty> exit=<n> red=<ids>`; beside it `<name>.result` (`verdict= red= pending= keep= why=`)
and `<name>.pending` (Mac-side checks). Evidence in `/tmp/uso-keep/<name>/` (`before/`, `after/`, `ask/`, `undo.log`).

## Self-test

`flock -o /tmp/uso-scenarios.lock bash tools/scenarios/lib.sh --self-test` — makes a `uso-wip-h1-st` share (files on the
first pool and the first array disk), a container (Docker only), fake notifications; shows every wave-1 check green on
the quiet state and red on its violation; undoes all and checks INV-LEFTOVER on itself. `office-smoke-refresh.sh` is the
toy scenario: the three desks' fresh looks, standard checks.
