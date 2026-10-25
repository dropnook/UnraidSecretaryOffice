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

**Power cuts.** A scenario whose pre half cuts the power (`sysrq b`: the four `*-rn-hard-reset-midrun-*` cells) carries
`# needs-powercut:` and `needs … powercut`: left out of every list and skipped (its reboot and post half too) unless
`run.sh --powercut` — only on a VM that boots from a vdisk, or with the owner nearby: after one on 2026-10-22 Tower's
passed-through USB boot stick no longer enumerated («error -71») and the VM couldn't start. Clean reboots
(`office-of-reboot-all-hired`) stay in the lists.

**Run late.** A scenario that leaves something behind until the next base reset (a row in a history, packages in the
storeroom, runs dated ahead by a clock cell) carries a line `# run-late: <n> <why>` in its header: `--all` and `--desk`
lists run it after every other one, the higher `n` the later (R22: 9, the last), each after a base reset of its own
(run.sh and run-local.sh alike); `--only` keeps the order given. Today: M19 pending (1), B49 let-go (2), B43 clock back
(3), the clock-ahead cells year roll and both DST cells (4), B39's 6 h of silence (5), R22 (9).

## Self-test

`flock -o /tmp/uso-scenarios.lock bash tools/scenarios/lib.sh --self-test` — makes a `uso-wip-h1-st` share (files on the
first pool and the first array disk), a container (Docker only), fake notifications; shows every wave-1 check green on
the quiet state and red on its violation; undoes all and checks INV-LEFTOVER on itself. `office-smoke-refresh.sh` is the
toy scenario: the three desks' fresh looks, standard checks.

## Before a release and every night (wave 3)

* **Release step 6b** (`tools/release.sh`, between the click test and the suite): `prerelease.sh --since v<last>` maps
  the files changed since the last tag to desks (the table in its head: a desk's files → it, `backup/` → backup and
  restore, `mediacache/`/`gather/` → jack, `smartmover/` → moverelli, shared code, the web side, the plugin, office
  strings and the scenario helpers → all; docs and tests → none) and runs their scenarios of **`quick.list`** — the
  pre-release set: short, green lately, no reboot, clock or let-go, ≈ 27 min all together — through
  `USO_RUN_TAG=prerelease run.sh tower --only … --dev-sync`. Red stops the release (the version lines put back);
  `--dry` lists the desks, the scenarios and the estimate without Tower (`USO_RELEASE_DIFF=<file>`: an invented diff);
  `--no-scenarios` skips it with a loud line; the step's time stands in the release's last lines.
  By hand: `bash tools/scenarios/prerelease.sh --dry` (or `--only <name>` for a real run of the step).
* **The nightly** (`nightly.sh`, launchd `uso-scenarios-nightly.plist` at 02:30 — written, installed only by the
  coordinator with the owner's OK; the install lines are in the plist): origin/main in its own detached worktree
  (`~/Claude/UnraidSecretaryOffice-worktrees/nightly`), `run.sh tower --all --dev-sync`, every red one once more (red,
  then green = **flaky**, listed apart), `scenarios/<date>/nightly.md` + `nightly.tsv` with the diff to the night before
  (new reds, fixed, flaky flips, new and gone scenarios). `--dry` says what it would do.
* **Who has Tower** (`tower-lock.sh`): the Mac's lock `scenarios/.tower.lock` (its owner's pid; a dead owner's is taken
  over) and no `run-local.sh` of another run on Tower (three looks) — a release waits 30 min for it, the nightly 2 h,
  then each gives up (exit 4). A worker's run.sh takes no Mac lock; the look on Tower sees it.
* **Self-test:** `bash tools/scenarios/prerelease.sh --self-test` (the mapping on invented file lists, quick.list, release.sh's
  own step 6b `--dry` and `--no-scenarios` cut out of it); `nightly.sh --compare <old.tsv> <new.tsv>` prints the diff part.

, H5: flows.sh, checks.sh, bubble-rules.tsv)

`flow <ID> [key=value…]` runs `flow_<id>` of `flows.sh` — the request body the page's button sends (desk.js's line is
named at each flow), through `ask`; a cron flow through the plugin's `scripts/job.sh` as crond starts it (`job <name>`,
`job_wait`); each waits with lib.sh's waiters. `refuse=ok`: a refusal is the expected end (then `expect_err <key>`).
Apply's body is desk.js's own `setupDecisions()` under node (`decisions_from_plan <plan> [key=value…]` =
`plan-changes.mjs --apply`), so `setup_plan_old` and the change list behave as on the page.

| Desk | Flows |
|---|---|
| Mr. Backupsy | `BK-FIRST-SETUP [preset=…] [key=value…]` · `BK-REPLAN` · `BK-APPLY [plan=old] key=value…` · `BK-DECLINE key= [value=]` · `BK-RUN-MANUAL mode=backup\|nokopia\|dryrun\|check` · `BK-RUN-SCHED` · `BK-RUN-QUEUED` · `BK-ABORT [after=s]` · `BK-UNMOUNT` · `BK-SCHEDULE cron=…` · `BK-ORPHANS [delete=…\|all]` · `BK-PACKAGE-ASIDE kind= folder=` · `BK-DATASET-MAKE kind= name= share= dir=` · `BK-LETGO [clear=yes]` · `BK-FORGET` |
| Mr. Restori | `RS-SCAN` · `RS-FILES mode=beside\|replace path= [moment=]` (`RS-FILES-BESIDE`, `-REPLACE`) · `RS-DB app= file=` · `RS-SQLITE app= container=` · `RS-CONFIG app=` · `RS-VM vm=` · `RS-KOPIA source= [snapshot=]` · `RS-PUTBACK of=<id>\|last` · `RS-DRILL` |
| Ms. Moverelli | `MV-SETUP [x=] [rule=<share>:<days>…] [mover_off=1]` · `MV-RESAVE` · `MV-LIST` · `MV-DRY` · `MV-RUN` · `MV-RUN-SCHED` · `MV-STOP-AFTER-FILE` · `MV-SCHEDULE cron=…` · `MV-MOVER-OFF` · `MV-IMPORT folder=` · `MV-LETGO` |
| The office | `OF-UPDATE plg=<file\|url>\|update\|worktree\|tree` · `OF-REMOVE-REINSTALL [plg=]` · `OF-ARRAY-STOP-START` · `OF-REBOOT` (pre half) / `OF-REBOOT-BACK` (post half) · `OF-AGENT-RESTART` · `OF-HIRE desk=` · `OF-FIRE desk=` |

Checks added to the standard set (n/a where they don't apply): INV-SETUP-MSGS, INV-STOPPED-KEEPS, INV-NC-MAINT-OFF,
INV-MOUNTS-GONE, INV-NO-MNT-OPEN, INV-COMPARE, INV-MIGRATE-IDEMPOTENT; INV-REPLAN (the Apply list = the base's
`base/<host>/plan-changes.known` + `expect_changes`, a trailing `*` matching the rest; Ms. Moverelli's settings saved
unchanged after one of her flows), INV-COUNTS' restore and moverelli parts, INV-PAGE, INV-BUBBLE, INV-TRUTH (truth.sh,
below: «INV-TRUTH»); the final set
INV-BACKUP-AFTER (`expect_warnings` lists the codes a state may cause), INV-DRILL-GREEN.

**The page (INV-PAGE, INV-BUBBLE):** `bubble_look <label> [desk…]` — the desks' fresh looks, the server's facts at that
moment (`bubble_facts`: locks in /proc/locks, containers' icon labels, files on the disks — not the office's own state),
the state files copied (`$KEEP/looks/<n>-<label>/`). `bubble-rules.tsv` says per desk which bubble, greeting or tile line
needs which fact. A greeting is random on every page: its rule is judged on the VM at once (red). Every other line whose
condition is false goes to the Mac (`page_text … not`), with the scenario's `expect_bubble` / `expect_no_text`, and each
page is checked clean (`page_clean`: page.mjs `--clean` — no raw lang key, `undefined`, `NaN`, `{n}`, script name,
«0 of 0», no «?» where a value belongs, no English in German). While Ms. Moverelli's run is at work the look also saves
her live answer for the page and judges her run card on the VM: its percent (desk.js's own formula) against the bytes
that really reached the other side, and «On its way … → to» against where the share's files really go. Flows look while
a run is at work (backup, restore, Moverelli); the checks take the last look.

**Verdict:** a scenario is green only when every check ran green — with a Mac-side check still queued it is «pending»
(row and total alike, run-local.sh's summary); run.sh completes them (plan_changes, page_text, page_clean).

**Called for real once (Tower, 1.66.4/1.66.5, 2026-10-18, H5's scratch scenarios and office-flows-selftest):**
BK-FIRST-SETUP (after BK-FORGET, preset=auto), BK-REPLAN, BK-APPLY (a share's mode and back), BK-DECLINE / -UNDO,
BK-RUN-MANUAL (backup, check), BK-RUN-SCHED, BK-RUN-QUEUED, BK-ABORT, BK-UNMOUNT, BK-SCHEDULE, BK-ORPHANS (look;
nothing to delete), BK-DATASET-MAKE (a scratch app's folder), BK-LETGO (+ OF-HIRE), BK-FORGET · RS-SCAN, RS-FILES beside
and replace, RS-SQLITE, RS-DB (immich's postgres dump), RS-VM, RS-PUTBACK (of each), RS-DRILL, RS-CONFIG (refused
`restore_config_same` — nothing differed, as it should) · MV-SETUP, MV-RESAVE, MV-LIST, MV-DRY, MV-RUN, MV-RUN-SCHED,
MV-STOP-AFTER-FILE, MV-SCHEDULE, MV-MOVER-OFF, MV-IMPORT, MV-LETGO (+ OF-HIRE) · OF-AGENT-RESTART, OF-UPDATE (`update`;
a same/older .plg is refused by the plugin manager — `from=<version>` removes and installs the older release first),
OF-ARRAY-STOP-START (stopped in 25 s, nothing open under /mnt), OF-REMOVE-REINSTALL.
RS-KOPIA (after BK-APPLY `share|uso-only|mode=kopia` and a run: Tower's base sends nothing to Kopia), OF-UPDATE
from=1.66.5 (remove, 1.66.5, back to 1.66.6 — INV-MIGRATE-IDEMPOTENT green).
**Not run:** BK-PACKAGE-ASIDE was called and refused `package_aside_gone` — the page offers it only once a backup run
lists the package as gone (run a backup after the app went); OF-REBOOT / OF-REBOOT-BACK — the runner's reboot halves
(`<name>-pre.sh` / `-post.sh`), a reboot of Tower not made yet.

## Batch A — the eight bugs from the owner's server (matrix ★ cells)

Each scenario turns one bug the owner met on his own server into a scenario. Each must be green on the release and red on
the release before its fix. Every one takes an older release as its argument (`name:v1.66.0`): `state IN-DOWNGRADE <tag>`
installs that plugin for the scenario and the undo puts the current one back. So
`run.sh tower --only backup-kp-orphans-delete:v1.65.12` is the same cell on the plugin before its fix. Own assertions
are rows `cell <name>` of the verdict table.

| Cell | Scenario | Fixed in · red on | Time on Tower | Run |
|---|---|---|---|---|
| B1 | `backup-ct-stopped-nc-replan-apply` | 1.66.3 · v1.66.2 | 33 s (old: 39 s) | pre-release |
| B2 | `backup-st-renamed-run` | 1.66.0 · v1.65.13 | ~110 s (old: 85 s) | pre-release |
| B3 | `backup-kp-orphans-delete` | 1.65.13 · v1.65.12 | 15 s (old: 39 s) | pre-release |
| B4 | `backup-ct-app-is-dataset-run` | 1.65.10 · v1.65.9 | ~95 s (old: 115 s) | pre-release |
| B5 | `backup-sh-office-share-exists-first-setup` | 1.66.1 · v1.66.0 (page only: `no-create-share`) | 8 s (old: 16 s) | pre-release |
| M1 | `moverelli-sh-prefer-array-files-run` | 1.66.1 · v1.66.0 | 16 s (old: 40 s) | pre-release |
| M1 | `moverelli-sh-prefer-array-files-stop-after-file` | 1.66.1 · v1.66.0 | ~105 s (old: 334 s, the wait times out) | pre-release |
| M2 | `moverelli-mo-own-rules-idle-setup` | 1.66.1 · v1.66.0 (page only: `remove-rules`) | 11 s (old: 33 s) | pre-release |
| M2 | `moverelli-mo-own-rules-gone-setup` | 1.66.1 · v1.66.0 (+ `remove-rules`) | 8 s (old: 29 s) | pre-release |
| M3 | `moverelli-sh-yes-run-picture` (+ `moverelli-eta.mjs`) | 1.66.2 / 1.66.4 · v1.66.1 (page only: `card-left`) | ~70 s (old: 84 s) | pre-release |
| M3 | `moverelli-sh-yes-nested-tidy` (an emptied parent folder left on the pool) | the release after 1.66.7 · v1.66.7 | ~1 min | pre-release |

Times are the scenario alone on Tower; each needs a base reset before it (~27 s), and an older release adds its install.
The whole batch takes ~10 min on the release (~25 min on the older ones).

**Page-only fixes** (B5, M2, M3): the agent's state is the same before and after the fix, so the older release's red
comes from its page. run.sh's Mac-side step draws every page of a scenario that installed an older release (state
IN-DOWNGRADE <tag>: `SOURCE=` of its `state-in-downgrade.env`) with that tag's page — `page.mjs --tree <tag>` (`git
archive <tag> public src tests/ui` once into `$TMPDIR/uso-page-tree/<commit>/`, today's harness beside it); the
report's «Mac-side checks» names the tag. `USO_PAGE_TREE=<tag>` sets it for a whole run (empty: this tree's page). The
Mac needs the tags (`git fetch --tags`).
- B5 `no-create-share`, M2 `remove-rules`: `queue_mac <cell> page_text --open …` — `--open` opens every `<details>` and
  folded row (`.row.unfolds`) before the text is read (the guide and the share row are folded).
- M3 `card-left`: a page-value check. Her progress read every 3 s during the run, each reading with the seconds the
  share really still took (`expect`), is replayed into the drawn page (`queue_mac card-left page_value --factor 3
  --slack 30 moverelli <look> en <replay.jsonl> "moverelli.rc.left:left moverelli.rc.left_range:lo..hi"`): page.mjs
  `--replay` runs the page's own clock to each reading, the part «progress» answered with it, and reads the time left
  off her run card (`--value <key>:<placeholder>`, a duration in the language's own words). `eta-honest` stays the
  server's cross-check on this tree's code (info on an older release).
- `queue_mac <id> page_value [--factor f] [--slack s] <route> <states> <langs> <readings|-> "<key>:<ph>[..<ph2>][=<n>] …"`
  also reads one value off a static page (`=<n>`: the server's number, e.g. a count on a tile).

The red-before check is automatic now (Tower, 2026-10-19, reports `scenarios/2026-10-19/tower-scenarios-helpers*`):
each page-only cell red on its older release, green on main — the same as the by-hand table of 2026-10-18. Run a
scenario and its older release in two runs (one name per run: the second wipes the first's evidence before the
Mac-side step).

**RS-* (the restore dialog)** waits while the plan's sizes are measured in the background, as the dialog's «Restore»
does (desk.js ready(): `!sizes.measuring`) — the preview asked again every 2 s; a start while measuring skipped the room
check (batch D, R9: 1.5 GB copied into a pool with 1 GiB free).
**MV-STOP-AFTER-FILE share=<share>** waits for a file of that share in flight (her plan may take other shares
first: up to 5 min); M1-stop uses it. **SH-PREFER-ARRAY-FILES** puts its files in a folder per disk
(`sh-paf-d1` on disk1, `sh-paf-d2` on disk2 — the same path on two disks would be duplicates Unraid never moves); its
undo takes them from wherever a run put them; M1-run uses it again.
**INV-TICK** (checks.sh overrides lib.sh's): the gaps between the pulses the sampler saw, and at the end the
heartbeat's age right now — lib.sh took «now − the last pulse the sampler saw», but the sampler stops at snap_after and
the checks before INV-TICK take seconds (B5 and M1-stop: 26/28 s = a pulse 20 s before snap_after + 6/8 s of checks,
the agent fine).

## Batch B — stopped, paused, gone (wave 2)

Each scenario makes its own history first where a cell needs one (a backup run before the state: the package, the
dump, the snapshot to restore from). Settings an Apply changes are put back from the copy of before (no helper undoes
an Apply); a scratch name's package (EmbyServer2, uso-new) leaves the backup place in the undo; a package moved into the
storeroom comes back by Ms. Dustdevil's «Put back». Restore cells that would change a base app's data ask only the
dialog's preview (R11, R13); R7 and R12 restore for real and are put back. Times: Tower, 2026-10-19 run 17:29 (reports
`scenarios/2026-10-19/tower-scenarios-batch-b`), tree main bf2f6f5 + the batch, Tower's plugin 1.66.8 (with
task/fix-moverelli-root-dataset's Moverelli code); each time includes waits for the lock shared with batch C.

| Cell | Scenario | Verdict on 1.66.8 | Time on Tower | Run |
|---|---|---|---|---|
| B6 B7 B8 | `backup-vm-ct-paused-shutoff-run` | **red** B7 (a paused container runs after the run); B6, B8 green | 207 s | pre-release |
| B8 B6 (prepare shutdown) | `backup-vm-shutoff-prepare-shutdown-run` | green | 241 s | pre-release |
| B21 | `backup-ct-gone-replan-run-aside` (container, template and image gone) | green | 192 s | pre-release |
| B21 | `backup-ct-gone-template-stays-replan-run` (template and image stay: package kept, not offered) | green | 176 s | pre-release |
| B22 | `backup-ct-renamed-docker-replan-run` | green | 169 s | pre-release |
| B22 | `backup-ct-renamed-unraid-replan-run` | green | 192 s | pre-release |
| B23 | `backup-ct-recreated-run` (EmbyServer and kopia re-created) | green | 179 s | pre-release |
| B24 | `backup-ct-new-replan-run-apply` | green | 181 s | pre-release |
| B27 | `backup-vm-service-off-replan-run` | **red** (Apply list drops both VMs' [vm] keys) | 299 s | pre-release |
| R6 | `restore-ct-image-gone-drill` (mariadb-nextcloud's image: dumps need it) | **red** INV-BACKUP-AFTER (cell green) | 96 s | pre-release |
| R7 | `restore-vm-gone-vm-drill` | green | 93 s | pre-release |
| R11 | `restore-ct-stopped-db-db` | **red** (the plan starts the stopped database) | 86 s | pre-release |
| R12 | `restore-ct-stopped-nc-db-files` | **red** INV-PAGE (inherited, see below; cells and Tower checks green) | 304 s | pre-release |
| R13 | `restore-ct-renamed-config-drill` | **red** (no template in the package → «everything is the same»; + INV-PAGE inherited) | 310 s | pre-release |
| R13 | `restore-ct-recreated-config-drill` | **red** INV-PAGE (inherited; cells and Tower checks green) | 238 s | pre-release |

Findings (each red twice, 2026-10-19 16:27 and 17:29 runs; root causes in the files' headers): B7 backup.sh:514 counts a
paused container as running; B27 setup.sh step_vms (~1443) carries no [vm] keys while libvirt is off; R11 restore.php
rsPlanDb (~2014) starts a stopped database; R13 restore.php rsPlanConfigFor (~2866) says restore_config_same for a
package without a template; and, seen in R6 and R11 (CT-STOPPED-DB × BK-RUN), a running Nextcloud whose database is
stopped fails every run (backup.sh ~1213) — and that run's reason is a plain `die`, no code: Mr. Backupsy's German page
then shows the engine's English «Nextcloud 'Nextcloud': maintenance mode could not be switched on…» (INV-PAGE «English in
German», Mac-side) on every scenario after it until a run ends otherwise (R12, both R13 in the 17:29 run). Corrections of the catalogue's guesses, in the scenarios' headers: no hint
app_gone without an [app] section (the Apply list says it); a gone app's package is offered for the storeroom only once
its image is gone too (backup-gone.php); the drill needs an image only for a dump; RS-VM defines a gone VM and leaves a
disk that is still there alone (after.vm_disks names its moment); his files restore takes the share Nextcloud binds whole.
The whole batch: ~50 min of scenarios plus a base reset (~3 min) on a shared Tower.

## Batch C — shares (wave 2)

Scratch shares `uso-wip-c-…` (SH-NAMES: `uso-wip-c Ärger 's`, `uso-wip-c Fotos Ärger`), scratch apps `uso-wip-c-…app`
(alpine, `sleep`). Mr. Restori restores only what an app or VM binds (`rsUnitOwner()`), so every restore cell binds its
folder with a scratch app. A scenario that sets Mr. Backupsy's settings (Apply of its scratch share) keeps a copy of
settings.ini and puts it back in its undo, and destroys its scratch datasets' engine snapshots before Unraid removes
the share (Unraid can't delete a ZFS share with snapshots). Notes found while building them: two backup runs inside
one flow end ≥ 61 s apart (INV-NOTIFY-ONCE reads one subject twice within 60 s as one event); a file a flow moved goes
with the share's undo (`files_gone`, never a red undo); the drill picks one share a day (sorted, day number modulo
their count) — R1 and R15 add empty pad shares `uso-wip-c-a<n>`/`-z<n>` until today's pick is theirs.

| Cell | Scenario | Verdict on 1.66.8 / engine 2.64 (Tower, final run 2026-10-19 19:43) | Time on Tower | Run |
|---|---|---|---|---|
| B9 | `backup-sh-renamed-run` | green | 5.5 min | pre-release |
| B10 | `backup-sh-names-run` | red: INV-FILES only (helper `task/scenarios-helper-files-escape`) — its cells green | 3 min | pre-release |
| B29 | `backup-sh-array-xfs-replan-run` | red: `run-says-vm` (new_vm claims a snapshot for a VM disk on xfs) | 4 min | pre-release |
| B30 | `backup-sh-exclusive-run` | green | 3 min | pre-release |
| B31 | `backup-sh-100k-run` | green (replan 5 s, run 30 s, Kopia 100 000 files) | 9 min | nightly |
| M4 | `moverelli-sh-names-run` | red: `trailing-space-folder` + INV-LEFTOVER (yes direction), INV-PAGE (run card lags), INV-FILES (helper) | 45 s | pre-release |
| M5 | `moverelli-sh-open-file-run` | green | 20 s | pre-release |
| M9 | `moverelli-sh-storeroom-run` | red: `dustdevil-finds` (the storeroom on the array is lost to her) | 20 s | pre-release |
| M12 | `moverelli-sh-pool2-run` | needs pool2 (skipped) | — | pre-release |
| M13 | `moverelli-sh-asleep-disk1-run` | needs hdd (skipped) | — | pre-release |
| M14 | `moverelli-sh-100k-run` | green cells (list 2 s, dry 7 s, run 40 min at ~42 files/s); INV-AGENT red: another worker's dev-sync restarted the agent | 48 min | nightly |
| M16 | `moverelli-sh-renamed-setup-run` | red: `renamed-said` (her reason for the binary's «No medium found»), INV-REPLAN (a cfg-only share in Mr. Backupsy's plan) | 30 s | pre-release |
| R1 | `restore-sh-names-files` | red: `long-name` (a 255-byte name can't be restored), `beside` (a name with a tab silently left out), INV-FILES (helper) | 2.5 min | pre-release |
| R2 | `restore-sh-open-file-replace` | red: `asks-open` (a file held open by a process: no word) | 4 min | pre-release |
| R8 | `restore-sh-renamed-files` | green | 4.5 min | pre-release |
| R15 | `restore-sh-exclusive-replace` | green | 4 min | pre-release |
| R16 | `restore-sh-multi-disk-files` | green | 2.5 min | pre-release |

Times are the scenario alone on Tower; each needs a base reset before it (~27 s), and an older release adds its install.
The whole batch takes ~10 min on the release (~25 min on the older ones).

**Page-only fixes** (B5, M2, M3): the agent's state is the same before and after the fix, so the older release's red
comes from its page. run.sh's Mac-side step draws every page of a scenario that installed an older release (state
IN-DOWNGRADE <tag>: `SOURCE=` of its `state-in-downgrade.env`) with that tag's page — `page.mjs --tree <tag>` (`git
archive <tag> public src tests/ui` once into `$TMPDIR/uso-page-tree/<commit>/`, today's harness beside it); the
report's «Mac-side checks» names the tag. `USO_PAGE_TREE=<tag>` sets it for a whole run (empty: this tree's page). The
Mac needs the tags (`git fetch --tags`).
- B5 `no-create-share`, M2 `remove-rules`: `queue_mac <cell> page_text --open …` — `--open` opens every `<details>` and
  folded row (`.row.unfolds`) before the text is read (the guide and the share row are folded).
- M3 `card-left`: a page-value check. Her progress read every 3 s during the run, each reading with the seconds the
  share really still took (`expect`), is replayed into the drawn page (`queue_mac card-left page_value --factor 3
  --slack 30 moverelli <look> en <replay.jsonl> "moverelli.rc.left:left moverelli.rc.left_range:lo..hi"`): page.mjs
  `--replay` runs the page's own clock to each reading, the part «progress» answered with it, and reads the time left
  off her run card (`--value <key>:<placeholder>`, a duration in the language's own words). `eta-honest` stays the
  server's cross-check on this tree's code (info on an older release).
- `queue_mac <id> page_value [--factor f] [--slack s] <route> <states> <langs> <readings|-> "<key>:<ph>[..<ph2>][=<n>] …"`
  also reads one value off a static page (`=<n>`: the server's number, e.g. a count on a tile).

The red-before check is automatic now (Tower, 2026-10-19, reports `scenarios/2026-10-19/tower-scenarios-helpers*`):
each page-only cell red on its older release, green on main — the same as the by-hand table of 2026-10-18. Run a
scenario and its older release in two runs (one name per run: the second wipes the first's evidence before the
Mac-side step).

**MV-STOP-AFTER-FILE share=<share>** waits for a file of that share in flight (her plan may take other shares
first: up to 5 min); M1-stop uses it. **SH-PREFER-ARRAY-FILES** puts its files in a folder per disk
(`sh-paf-d1` on disk1, `sh-paf-d2` on disk2 — the same path on two disks would be duplicates Unraid never moves); its
undo takes them from wherever a run put them; M1-run uses it again.
**INV-TICK** (checks.sh overrides lib.sh's): the gaps between the pulses the sampler saw, and at the end the
heartbeat's age right now — lib.sh took «now − the last pulse the sampler saw», but the sampler stops at snap_after and
the checks before INV-TICK take seconds (B5 and M1-stop: 26/28 s = a pulse 20 s before snap_after + 6/8 s of checks,
the agent fine).

## Batch D — interruptions and full (USOTest4)

Array stops, power cuts and reboots in the middle of a run, two runs at once, a full pool, atd off. Run on
**USOTest4** (192.168.7.193, Unraid 7.3.3, Tower's twin — `run.sh test4`), the only worker there: its reboots and
resets disturb nobody. Reboot cells are two halves (`<name>-pre.sh` / `-post.sh`, the runner reboots or the pre half's
power cut does): a power cut is `sysrq b` after a sync, armed by the pre half and fired once its report is written
(a detached starter clicks the button 3 s after the pre half's result, RN-HARD-RESET-MIDRUN's watcher cuts the power
when the run is at work), so the runner has the report before the server goes. The pre half notes what must come back
(OF-REBOOT, `<name>.reboot`, `<name>.prenotes`); the post half compares (OF-REBOOT-BACK) and undoes what the pre half
made. Unraid's own parity check after an unclean stop is cancelled in the post half (its history line put back).

What the base lacks and the cells add: **Docker's autostart** for the demo set (CT-AUTOSTART, helper branch
`task/scenarios-helper-ct-autostart` — the array-stop and reboot cells skip until it merges: without it nothing comes
back after an array stop, the databases stay down); Nextcloud's data folder closed again after an array start (Unraid's
array start opens an exclusive share's root to 0777 unless its group is users — the cells about something else close it,
B11/B12 are about it); Nextcloud's maintenance mode switched off in the undo (base.sh doesn't). Unraid's array start
queues an at job of its own (`sleep 10; reload_services`): the cells wait for it before INV-LEFTOVER looks at atd's
queue. R9 needs `task/scenarios-helper-rs-measuring` (the restore flow waits for the measured sizes, as the dialog's
«Restore» does) and skips without it. After a power cut INV-BUBBLE needs `task/scenarios-helper-bubble-interrupted`
(the bubble facts read a status.json left «running» by the killed run as failed, as the office does — the «didn't
finish» bubble is right). One reboot cell per run.sh call: a later reboot clears /tmp/uso-keep, and the runner's
Mac-side page checks of the earlier one find no state files; a pre half makes no page look of its own for the same
reason (R4's pre halves back up through the cron's job, not «Back up now»).

| Cell | Scenario | Verdict on 1.66.8 (USOTest4) | Time on USOTest4 | Run |
|---|---|---|---|---|
| B11 | `backup-rn-hard-reset-midrun-run` (`-pre`/`-post`; `:users`) | **red** F2 (green with `:users`) | ~8 min (a reboot) | nightly |
| B12 | `backup-rn-array-stop-midrun-bk-run` (`:users`) | **red** F2 (cells green with `:users`) | 8 min (`:users` 2 min) | pre-release |
| B13 | `backup-dk-pool-full-run` | **red** F1 | 1.5 min | pre-release |
| B20 | `backup-rn-two-runs-run` | green | 3 min | pre-release |
| B45 | `backup-rn-setup-while-run-apply`, `backup-rn-plan-old-apply`, `backup-rn-agent-restart-midrequest-replan`, `-apply` | green | 45 s, 20 s, 70 s, 40 s | pre-release |
| B46 | `backup-sv-atd-off-run-apply` | **red** F3 | 2 min | pre-release |
| B48 | `backup-rn-abort-nc-maint-run` | green | 2 min | pre-release |
| R3 | `restore-rn-changes-between-putback` | green | 1 min | pre-release |
| R4 | `restore-rn-hard-reset-midrun-files`, `-drill` (halves) | files **red** F10, F5; drill green | ~5 min each (a reboot) | nightly |
| R5 | `restore-rn-array-stop-midrun-rs-files`, `-drill-drill` | **red** F5, F4 (a race: red in 3 of 5 runs) | 2.5 min each | pre-release |
| R9 | `restore-dk-pool-full-files-drill` | green (needs `task/scenarios-helper-rs-measuring`) | 2 min | pre-release |
| R20 | `restore-rn-two-runs-files-drill`, `restore-rn-parity-check-drill` | green | 4 min, 1 min | pre-release |
| M6 | `moverelli-rn-array-stop-midrun-mv-run`; `moverelli-rn-hard-reset-midrun-run` (halves) | **red** F8; power cut **red** F11 | 2.5 min; ~5 min (a reboot) | pre-release; nightly |
| M8 | `moverelli-dk-pool-full-run-sched` | cells green (INV-PAGE F7 when a run moved nothing) | 20 s | pre-release |
| M11 | `moverelli-rn-unraid-mover-running-run` | green | 40 s | pre-release |
| M27 | `moverelli-rn-two-runs-run` (a), `moverelli-rn-two-runs-sched` (b) | green | 30 s, 2 min | pre-release |
| O3 | `office-of-reboot-all-hired` (halves) | green | ~4 min (a reboot) | nightly |

Findings on 1.66.8 (engine 2.63), USOTest4, 2026-10-19 — red stays red until fixed:
- **F1** (B13) the min_free_gb refusal is a sentence, not a code: backup/backup.sh:3873 `die "Not enough space for the
  packages…"` (no die_code) — his history shows the English text on every language.
- **F2** (B11, B12) Unraid's array start (every boot too) chmods an exclusive share root not of group users to 0777
  (`/mnt/user/nextcloud`); Nextcloud then refuses occ, the --recover can't take it out of maintenance mode and keeps
  the note — Nextcloud stays in maintenance mode after an array stop or a power cut during a run. Green with `:users`.
- **F3** (B46) atd off: agent/lib/house.php:249 hostLaunch checks only `at`'s exit code (it queues without atd) — the
  page says «Handed over — it should start any moment», never that it can't start; the job runs when atd comes back.
- **F4** (R5, a race — 2 of 3 runs) the office's own array-stop hook TERMs the restore/drill (plugin/scripts/agent.sh:434)
  before Unraid says Stopping: the reason reads restore_stopped / «stopped» («the server shut down?»), not array_stopping.
- **F5** (R4, R5) a copy beside cut by the stop or a power cut leaves a half `<folder>.restored-<stamp>` (1 of 2 GB) with
  no undo step: nothing offers to take it away, it looks like a finished restore.
- **F6** (INV-PAGE of backup cells) Ms. Dustdevil's «Where is what» shows the engine's last log line as the backup's step
  while the lock is held (agent/lib/backupscript.php:52 lastLogStep()) — English on the German page.
- **F7** (INV-PAGE of Moverelli cells) her history row of a real run that moved nothing: «0 of 0 moved (0 B)».
- **F8** (M6, array stop) a run the array stop cut short (1079 of 3000 moved) is «ok» (exit 0) in her history.
- **F9** (R9) the agent's restore.start takes a plan whose sizes are still measured (only the dialog waits): the copy
  filled cache to 0 B, the job died, its journal stays «running» — reached through a flow that didn't wait (fixed in
  `task/scenarios-helper-rs-measuring`); defence in depth for the agent.
- **F10** (R4) after a power cut his journal's cut step still says «running» (rsJournals rewrites only the result to
  interrupted): the page draws it with the wave for a job that is gone (public/desks/restore/desk.js:2291).
- **F11** (M6, power cut) 2 of 3000 files gone after a power cut during her run (twice: f40d-2099/-2275 — neither on
  cache nor on the array, no .partial), and no history line for the cut run. Root cause not found (Unraid's move
  binary, files in flight around the cut).
- **F12** (found by M11/M27's own setup) a clock stepped back freezes the agent until a request rings its doorbell:
  agentNap() waits a relative stream_select timeout computed from wall-clock time (agent/agent.php:423, nextTick at
  :350) — stepped back by Δ while it naps, it sleeps Δ; no heartbeat meanwhile (the page's dot, agent-watch after 10
  min). Seen: put_files --age (the clock 40 days back for ~20 s) → no heartbeat 45 s after the clock was back
  (`test4-scenarios-batch-d-2119`). The Moverelli cells now ring the doorbell after writing aged files.
The batch: 56 min pre-release (21 scenarios incl. base and the Mac-side checks, run 20:05 —
`scenarios/2026-10-19/test4-scenarios-batch-d-2005`) + ~30 min nightly (five reboots, one run.sh call each:
`test4-scenarios-batch-d-reboot*`).

Not covered: R9's DK-ARRAY-DISK-FULL half (an app folder's moment lives on the pool; the array's disks hold none of
Mr. Restori's units on this base), B48's paused VM (USOTest4 has no VM service: `needs vm`).
Two runs of a scenario with and without an argument in one run.sh call wipe the first one's report (run-local's
`--one` clears `<name>.*`): B11/B12 `:users` run apart.

## Batch E — services, Kopia, install, time (USOTest3VM)

Run on USOTest3VM (7.4.0-rc.1, btrfs cache + ZFS pool «fast», Docker with the demo apps, Kopia on a filesystem
repository, MinIO, no VM service, the time zone broken on purpose), office 1.66.8 / engine 2.63, final run
`scenarios/2026-10-19/test3-scenarios-batch-e` (99 min). Red = a product finding (the report names the cause); the
scenario is committed red. Cells marked «helper» need a helper branch not merged yet (they answer «needs» until then):
`task/scenarios-helper-tm-dst-zone` (TM-DST on a server whose zone file is missing), `task/scenarios-helper-kopia-empty-test3`
(KP-EMPTIED on USOTest3VM).

| Cell | Scenario | Verdict on 1.66.8 | Time on test3 | Run |
|---|---|---|---|---|
| B14 | `backup-in-handedit-crlf-replan-apply` | green | 24 s | pre-release |
| B14 | `backup-in-handedit-bom-replan-apply` | red: a BOM is «line 1 not understood» | 24 s | pre-release |
| B14 | `backup-in-handedit-dup-replan-apply` | red: a key twice blocks Apply (setup_bad_decisions) | 20 s | pre-release |
| B14 | `backup-in-handedit-zero-ret-replan-apply` | green | 24 s | pre-release |
| B14 | `backup-in-handedit-perms-replan-apply` | red: «Look again»/Apply answer ok while the engine refuses | 2 min | pre-release |
| B15 | `backup-sv-docker-hung-replan-run` | red: `docker info` without a time limit — replan and run hang | 12 min | pre-release |
| B16 | `backup-kp-s3-unreachable-run` | red: no «offsite unreachable since …» | 83 s | pre-release |
| B17 | `backup-kp-emptied-run-accept` | helper — green with it (held, one alarm, Accept lifts it) | 4 min | pre-release |
| B18 | `backup-sh-cfg-pool-gone-replan-run` | red: the plan doesn't name the missing pool | 19 min (two array restarts) | nightly |
| B19 | `backup-tm-dst-run-sched` | helper — red with it: the second 02:59 runs under the same name (btrfs «Read-only file system», errors); counter-check on a server with a real zone | 64 min | nightly |
| B25 | `backup-dk-asleep-disk1-replan-run` | needs hdd | — | pre-release |
| B26 | `backup-sv-docker-off-first-setup-run` | red: Docker off drops every app's settings | 55 s | pre-release |
| B37 | `backup-kp-container-stopped-replan-apply-run` | green | 84 s | pre-release |
| B37 | `backup-kp-container-gone-replan-apply-run` | red: Apply clears the Kopia container | 91 s | pre-release |
| B37 | `backup-kp-disconnected-replan-apply-run` | green | 92 s | pre-release |
| B37 | `backup-kp-mapping-changed-replan-apply-run` | green | 2 min | pre-release |
| B38 | `backup-kp-object-lock-tick` | green (all five variants) | 90 s | pre-release |
| B38 | `backup-kp-locked-repo-run` | green | 74 s | pre-release |
| B38 | `backup-kp-s3-quota-full-run` | green | 7.5 min | pre-release |
| B40 | `backup-in-takeover-first-setup` | red: the old User Script isn't named | 28 s | pre-release |
| B41 | `office-in-update-prev-replan` | green | 2 min | pre-release |
| B41 | `office-in-downgrade-replan` | green | 30 s | pre-release |
| B41, O1 | `office-in-update-old-update` | red: the watchman's alert twice after the update | 2 min | nightly |
| B43 | `backup-tm-utc-broken-schedule-run` | red: «next run» 2 h before crond's | 80 s | pre-release |
| B43 | `backup-tm-clock-back-run-sched` | red: MariaDB dump fails after the clock jump; «last run» by name | 93 s | pre-release |
| B43 | `backup-tm-year-roll-run-sched` | needs a licence past 2027-01-01 (test3 runs a trial) | — | nightly |
| O2 | `office-of-remove-reinstall-running` | green | 108 s | pre-release |
| R18 | `restore-kp-disconnected-kopia-drill` | red: the drill fails (kopia_list_failed) | 2 min | pre-release |
| R18 | `restore-kp-s3-unreachable-kopia-drill` | red: the drill fails (kopia_list_failed) | 2 min | pre-release |
| R23 | `restore-sv-docker-off-scan-drill` | red: INV-REPLAN (as B26) | 37 s | pre-release |
| M20 | `moverelli-in-moverelli-ini-handedit-resave` | red: her save drops a foreign key, writes X 0 for 150 | 16 s | pre-release |
| M21 | `moverelli-in-takeover-import` | green | 15 s | pre-release |
| M22 | `moverelli-in-update-old-resave` | red: the first unchanged save moves age_stat | 90 s | nightly |
| M23 | `moverelli-mo-x-zero-run-sched` | green | 23 s | pre-release |
| M24 | `moverelli-tm-utc-broken-schedule-run-sched` | red: her «next run» 2 h before crond's | 28 s | pre-release |
| M24 | `moverelli-tm-dst-run-sched` | helper — inconclusive: after the clock jump the first 02:59 started nothing (atd/dcron), the stand-in start came a minute late | 64 min | nightly |

Notes for whoever runs them: the Kopia cells set uso-only and the backup place to «local + offsite» first (the plan
proposes the place as soon as a share goes offsite) and put settings.ini back; Ms. Moverelli's cells set her up with
the page's consent to switch Unraid's mover off and put her folder, the cron file and Unraid's schedule back. Run
`backup-tm-clock-back-run-sched` last in a batch: the run it makes under the set-back clock has an older name, and the
backup page reports the earlier run as «last» until the next run — later scenarios' INV-BUBBLE would inherit it.
The DST cells set the clock six days ahead: crond's (and atd's) first minute after such a jump is unreliable on
7.4 — a counter-check on a server whose zone file is there (Tower, USOTest4) without a jump of days is the better
proof. B15 lets Docker answer again before the checks (checks.sh INV-SETUP-MSGS asks Docker without a time limit).

## Batch F — mover environment and Jack (Tower)

Ms. Moverelli next to Unraid's own mover and Jack Streamer, Mr. Backupsy's harder containers and VMs, his let-go, and
Mr. Restori's drill on its own. Run on **Tower** (Unraid 7.4.0-rc.1, ZFS cache, disk1 xfs + disk2 zfs, the demo
containers, uso-vm-agent/uso-vm-plain — `run.sh tower`). Moverelli is held to what Unraid's mover does (emptied
folders and an emptied share root go along, the pool's dataset too); Jack's and her own excludes are the only
exceptions. Jack's lists use the demo films under /mnt/user/media, never a live cache. The let-go cells never delete a
backup: the backup place is counted before and after (INV-FILES strict, the storeroom counted).

What the cells know about the office (so the next ones don't trip over it): Jack Streamer rewrites his list right before
her joint run (his look drops what was watched), so «her run leaves his films» reads the list as she read it, after the
run. Her «Run now» with Jack hired is a joint run (`by: night`); «Stop after this file» then goes through the night's
stop. Identical scheduled refusals merge into one history row with `times` (moverelli.php moverelliRemember). ZFS lists
a folder in hash order, and Smart Mover moves in that order, so M15's big file is the only file of media (the small ones
in photos, the share after it). A new container brings two proposals, each its own Apply item: `docker|known` and
`docker|no_stop` («new - keeps running until you decide», backup/setup.sh:1374). A gone member or VM leaves the lists
(setup.sh:1412 keeps only a Compose stack brought down; :2412 «a VM that is gone drops out»). The engine logs a VM's
thaw/resume as «released (frozen|paused, N s)». «Where is what» has to be asked for anew
(cleanup.where_refresh, 60 s debounce); the page's «overlay» comes from a disk's `chain`. Every `check final` drills, so
R22 picks his weekly night ≥ 6 days after today's drill (drillWeekDue), DST-safe.

| Cell | Scenario | Verdict on 1.66.8 (Tower) | Time on Tower | Run |
|---|---|---|---|---|
| M7 | `moverelli-jk-hired-list-run` | cells green; **red** BF2 (INV-LEFTOVER) | 2.5 min | pre-release |
| M10 | `moverelli-mo-unraid-schedule-on-setup-run-letgo` | green | 15 s | pre-release |
| M15 | `moverelli-mo-big-file-stop` | green (BF3 seen once before the cell was reshaped) | 40 s–2 min | pre-release |
| M17 | `moverelli-mo-btrfs-levels-sched` | skipped on Tower (`needs btrfs`) — waits for test3 | – | pre-release |
| M18 | `moverelli-mo-tuning-run`, `moverelli-mo-foreign-script-run` | green (foreign: INV-BUBBLE needs `task/scenarios-helper-bubble-foreign-mover`) | 10 s each | pre-release |
| M19 | `moverelli-jk-list-pending-run`, `moverelli-jk-list-gone-run` | cells green; **red** BF1 (INV-PAGE), BF2 (INV-LEFTOVER) | 40 s, 70 s | pre-release |
| M25 | `moverelli-mo-os74-run` | green | 20 s | pre-release |
| M26 | `moverelli-mo-array-stopped-sched` | green | 70 s | pre-release |
| M28 | `moverelli-jk-hired-list-letgo` | green | 10 s | pre-release |
| B32 | `backup-ct-many-replan-run` (+ the setup at 375 px by hand) | green (375 px: no horizontal scroll, all 40 named, de/en) | 2 min | pre-release |
| B33 | `backup-ct-health-starting-run` | green | 1.5 min | pre-release |
| B34 | `backup-ct-volume-only-replan` | green | 10 s | pre-release |
| B35 | `backup-st-stack-replan-run`, `-member-stopped-`, `-member-gone-`, `backup-st-cm-file-unreadable-replan-run` | green; cm-file skipped (`needs compose`) | 70 s, 2 min, 2 min | pre-release |
| B36 | `backup-vm-running-agent-run`, `-noagent-run`, `backup-vm-gone-replan-run`, `-disk-missing-run`, `-many-snapshots-run` | green | 2 min each (gone 3 min) | pre-release |
| B49 | `backup-letgo-snapshots-packages` (run late: the next base reset brings his packages back) | cells green; **red** BF4 (INV-LEFTOVER) | 50 s | pre-release |
| B50 | `backup-ct-new-decline-replan` | green | 70 s | pre-release |
| R21 | `restore-sh-100k-scan-files` | green | 5 min | nightly |
| R22 | `restore-tm-drill-sched` (run last: its runs on the clock set on stay until the base reset) | deadline, outside green; **red** BF5 (after-step-back), window-start (open) | 11 min | nightly |

Findings on 1.66.8 (engine 2.64), Tower, 2026-10-20 — red stays red until fixed:
- **BF1** (M19 pending, INV-PAGE) her history row of a refused run shows a raw «{file}» (de, en): «Jack Streamer hasn't
  written his list yet ({file})» — public/desks/moverelli/desk.js:1037 passes only schedule/shares/version/needed to
  errorText, the row's `file` (moverelli.php:1123's param) never. The row stays in her history: in one run.sh call every
  later cell's Moverelli page is red on it too, until the base reset.
- **BF2** (M7, M19 gone — every run) after Jack's fill in a joint run took Season 01's episodes from disk1 to the pool,
  the emptied `disk1/media/Familienserien/Unser Hausbau (2021)/Season 01` stays: his fill tidies through /mnt/user0
  (mediacache_run.py remove_empty_parents, his log «Leeren Ordner entfernt: /mnt/user0/…»), where Season 01 still holds
  S01E01/E02 from disk2. Unraid's own move works per disk and takes an emptied folder along.
- **BF3** (M15, seen once, 2026-10-20 01:02) her live progress named the 1 MB file before an 8 GiB one for the whole 8 s
  the big file took, with 0 bytes done (the array got 0 → 8.6 GB meanwhile): office_progress_tick
  (smartmover/custommover_run.sh office_move_files) writes at most once a second and mover_send doesn't tick while it
  waits — a file started in the same second as the one before is never named. The card's file and rate are wrong for it.
  (M15 now has the big file alone in its share, so the cell's stop is judged on a known order; BF3 needs a cell of its
  own with two files in one share.)
- **BF4** (B49) «Also clear away» leaves the emptied `apps/` and `vms/` folders in the backup place
  (`cache/backup/apps`, `cache/backup/vms`; the packages themselves are in her storeroom, none deleted, his snapshots
  gone, the partner's kept — all as the dialog says).
- **BF5** (R22, after-step-back; extends batch D's F12) after the clock steps back the agent runs no desk tick at all
  until the clock passes its last round again: agent/agent.php:309-312 `if (microtime(true) < $nextTick) { agentNap(…);
  continue; }` — a doorbell gets the mailbox answered, nothing else; agentPulse comes after the ticks, so the heartbeat
  file keeps the later time (an mtime in the future reads as fresh). Mr. Restori's tick has the same guard of its own
  (agent/desks/restore-drill.php:3166 `static $looked`, :3168). Seen: the clock 06:51 → 02:20 with a drill due — no
  tick in 150 s; probe 05:59: the agent frozen since R22's 08:07, ticks again the moment the clock passed 08:07. A
  server whose clock ran ahead and is put right sleeps that long (and agent-watch sees a fresh heartbeat).
- R22's window-start (06:53, past both guards) is red as well and not pinned: drillDue() with that moment's inputs
  (checked by hand on Tower) says «weekly», yet his tick wrote no note within 150 s. Left red, cause open.

The batch: 86 min for all 27 cells in one run.sh call (2026-10-20 03:39, `scenarios/2026-10-20/tower-scenarios-batch-f-0339`
— its INV-PAGE reds after M19 pending are BF1's row, see above), then B35's two member cells and M10 again (05:09,
`…-0509`, green) and R22 alone (`…/tower-scenarios-batch-f`, the last). Run M19 pending late in a call (its row
reddens every later Moverelli page until the base reset), B49 and R22 last. After R22 the agent is frozen until the
clock passes R22's last step (BF5): the next run.sh's base reset restarts it — without one, restart it by hand.

Helper gaps: `task/scenarios-helper-jack-unfill` (jack_unfill moves a film back into a folder her run emptied and
removed — M19 gone's undo failed «cannot move» once; the cell passes without it since it reads the list as she read it);
`task/scenarios-helper-bubble-foreign-mover` (the bubble facts count a foreign mover on schedule, as the page does —
M18 foreign's INV-BUBBLE red until it merges). Without a branch: flows.sh MV-RUN-SCHED waits for night.json «done» and
hangs when the joint run is refused (M19 pending uses `job moverelli` + `job_wait`); the bubble facts are read live a
moment after the look's state files — a 2-second run of hers can end in between (M10's INV-BUBBLE red once); the looks'
`post/` answers are the canned ones of all scenarios ($SC_REPORTS/post), so a page drawn from a look's states shows an old
setup plan (B32's 375 px check used its own plan-replan.json); immich_server exits when its redis goes and nothing
restarts it (B35's cells start the members that ran before); the runner has no width check (page.mjs --width only).

Not covered: M17 (btrfs levels — `needs btrfs`, waits for test3), B35's ST-CM-FILE-UNREADABLE (`needs compose`, Tower
has no Compose Manager), BF3 as a cell of its own (two files of one share started in the same second). Tower's Unraid is
a trial ending between 2026-11-01 and -08: a clock cell set past it stops the array (R22 did once, set back by hand).

## Batch G — partner offices (Tower ↔ USOPartner)

The partner cells between **Tower** (the sender) and **USOPartner** (192.168.7.190, Unraid 7.3.2, office 1.67.1 from the
release, a free test VM since 2026-10-20) over their pairing `b601d464` — real sends, real refusals, a real pull. The
helpers are `states-partner.sh` (branch `task/scenarios-helper-partner`): the PT-* states, Mr. Restori's flow RS-PARTNER,
the partner base of both servers (`pt_base_snap` before, `pt_base_same` as the first undo — the partner files without
their logs, flash `partners/`, the door lines, every dataset and snapshot under …-partners and …-restored, on both) and
`pt_keep`/`pt_back` (both sides' partner files set aside and put back exactly). Each cell sends a scratch share
`uso-wip-g-pt` (a dataset on cache) at «local + partner»; its undo destroys only what USOPartner received of it.

The test bed: Tower reaches USOPartner's root shell with its own key (`scenarios-partner.key` beside scenarios.env; the
line on USOPartner ends `uso-scenarios-tower`, never `uso-partner:`). Tower's half of the pairing (flash key, known_hosts,
door line) is carried in `scenarios-partner-base/` — a plugin remove takes it by design (OF-REMOVE-REINSTALL did on
2026-10-18: Tower couldn't send any more until batch G put it back from Tower's own flash backup).

| Cell | Scenario | Verdict on 1.67.1 (Tower ↔ USOPartner) | Time on Tower | Run |
|---|---|---|---|---|
| B39 PT-OFFLINE × BK-RUN, BK-REPLAN | `backup-pt-offline-run-replan` | cells green; BG1 green since task/fix-partner-bg | 3 min | pre-release |
| B39 PT-OFFLINE, 6 h of silence | `backup-pt-offline-silent-6h` | green | 70 s | nightly |
| B39 PT-ARRAY-STOPPED × BK-RUN | `backup-pt-array-stopped-run` | green | 3 min | pre-release |
| B39 PT-QUOTA-FULL × BK-RUN | `backup-pt-quota-full-run` | green | 3.5 min | pre-release |
| B39 PT-ENDED × BK-RUN, BK-REPLAN | `backup-pt-ended-run-replan` | green | 2.5 min | pre-release |
| B39 PT-ANEW × BK-REPLAN, BK-RUN | `backup-pt-anew-replan-run` | green | 3 min | pre-release |
| R19 PT-PULLED × RS-PARTNER, OF-AGENT-RESTART | `restore-pt-pulled-partner-restart` | green (BG2 fixed in task/fix-partner-bg) | 3.5 min | pre-release |

Findings on 1.67.1 (engine 2.65), Tower ↔ USOPartner, 2026-10-20 — red stays red until fixed:
- **BG1** (B39 offline, `agent-not-held`) the Team Lead's «Look again» on the card of a partner that doesn't answer holds
  the whole agent: caretaker.partner_ping → partnerAskAndKeep → partnerAsk → partnerSsh runs ssh with run() inside the
  agent's request (agent/lib/partner.php:2123, :1208, :1146; ConnectTimeout 15, timeout 30). Another page's request
  waited 13.4 s (agent_away after 4 s on every page); once the heartbeat gapped 35 s (INV-TICK red in the first run).
  Mr. Restori's look and the mutual watch are jobs (atd) and don't. **Fixed** (task/fix-partner-bg): «Ask now» hands
  `php agent.php job partner-look <id>` to atd and answers at once (`looking`, the card «asking …»); the cell waits for
  the job's answer in state.json (`pt_card_ask`). Green on Tower ↔ USOPartner 2026-10-20: another request 18 ms.
- **BG2** (R19, `dustdevil-lists`) Ms. Dustdevil never lists a dataset Mr. Restori pulled from a partner
  (`cache/<share>.restored-<time>`, mounted under /mnt/addons/…/restored): her leftovers take a journal's `.restored-<time>`
  only as a path under /mnt/ (agent/desks/cleanup.php:2408, :2415), and a pull's journal names its dataset by name in
  `aside[].to` (agent/desks/restore-partner.php:625). Her header says she finds his `.restored-<time>` from his journals.
  **Fixed** (task/fix-partner-bg): the plan's aside never reaches the journal — she reads the journal's `pull` steps and
  `pulled`, lists it as «pulled from a partner» (read only, why `pulled`, a link to «Remove what was pulled…»), a
  sleeping pool named, never looked at. The cell checks the entry's shape. Green on Tower ↔ USOPartner 2026-10-20.

What the cells hold (green): offline — partner_missed «unreachable» the one warning, the run «with warnings» in ~30 s,
the card «couldn't reach it», partner_silent waiting for 6 h, his page's look and «Look again» answering at once, his look
job (from the tick) writing «unreachable», the replan keeping the unit at «local + partner»; 6 h — partner_silent red
(«since» the last answer) at once after the clock, one notification, the agent beating on both sides of the jump; array
stopped — partner_missed «array_stopped» said in the first run (no nights of grace since engine 2.46 — the catalogue's
«nights counted» is gone), the card «array stopped»; quota full — partner_quota in each of two runs, nothing at
USOPartner touched; ended — partner_ended in the run, partner_gone in the setup, the unit and the section in the Apply
list, no card left; anew — partner_anew, the unit taken over to the new id, one card on each Team Lead and in the plan,
the run sends a full copy to the new id; R19 — a new dataset beside, origin «-», zpool history without -F or clone, read-only
under /mnt/addons, its files those of the moment, read-only after an agent restart and remounted read-only by
rspRemount after an array stop/start (stopped in 25 s, the mount didn't hold it), a backup run's retention leaving it.

Notes for whoever runs them: the base (Tower's pairs.json carried by base.sh) needs the test bed above — without it each
cell says «needs». Mr. Restori's held.json on Tower carried `tried` 2026-11-08 (batch F's clock cells): his tick computes
the look's age as negative and never looks on his own until then (agent/desks/restore-partner.php:296-297) — only «Look
again» starts one; the waiter takes only a `tried` of now. A successful «Look again» writes last_heard/last_answer into
pairs.json (INV-SETTINGS allows exactly that in the array-stopped cell). The 6-hour cell sets Tower's clock ahead: run it
last in a call. Undo of every cell: both servers' partner base compared (`pt_base_same`) — the received datasets and
partner files of USOPartner exactly as before.

The batch: `scenarios/2026-10-20/tower-scenarios-batch-g` (run 4: ended, anew, R19, 6 h — 26 min with the page checks) and
`…/tower-scenarios-batch-g-1444` (run 3: all pre-release cells, 34 min with the page checks).

## Batch H — the parked resilience findings (USOTest3VM)

The nine findings of waves 1/1b that were parked instead of tested (parking lot «Resilienz-Funde»), each as a scenario
on **USOTest3VM** (7.4.0-rc.1, btrfs cache + ZFS pool «fast», Docker, Kopia, MinIO, no VM service; office 1.69.2 /
engine 2.66 from main, `run.sh test3`). Files `*-h-*.sh`; helpers only in `states-h.sh` (h_cell, h_mv_set_up — her setup
on a base that keeps her not set up —, h_jack_hire_standin, h_put_future, h_agent_stop/start, h_share_drop,
h_snaps_drop, h_ds_destroy, h_fresh_beat). Red = a product finding, committed red (playbook §6); no product code here.

**Jack Streamer on USOTest3VM:** its Emby has no admin and no API key for him (none was made — no account is created on
a test VM by a scenario). `h_jack_hire_standin` sets him up as his page saves him with the scenarios' stand-in key:
Emby answers his runs 401 — exactly an Emby that can't be asked. H1 needs that (his part can't plan, «keeps the list as
it was»); H2 judges only the list. A cell that needs Emby's real plan stays Tower's.

| # | Finding | Scenario | Verdict (2026-10-21, test3) | Time |
|---|---|---|---|---|
| 1 | Jack Streamer's list missing → written empty → films given back | `moverelli-jk-list-empty-h-run` | **red** list-not-emptied, films-stay (twice) | 41 s |
| 2 | MediaCache's dry run never writes his list | `jack-jk-hired-list-h-dry` | green (by design: the dry run never writes it) | 22 s |
| 3 | ZFS share with @uso-backup-* can't be deleted in Unraid | `backup-sh-zfs-snapshots-h-delete` | **red** says-snapshots | 2 min |
| 4 | Full ZFS pool: he stops and starts its apps anyway | `backup-dk-app-pool-full-h-run` | **red** warns-before-stop | 2 min |
| 5 | Kopia Object Lock: extending the locks off by default | `backup-kp-object-lock-default-h-look` | green (the Team Lead's kopia_lock_extend opens; said, not set) | 40 s |
| 6 | Kopia re-made → its anonymous image volumes gone | `backup-kp-volumes-recreated-h-replan` | **red** said-before, said-after | 71 s |
| 7 | Clock ahead and back: files with times in the future | `moverelli-tm-future-mtime-h-run`, `backup-tm-future-mtime-h-run` | **red** said only (both: nothing moved wrongly, nothing failed) | 61 s, 109 s |
| 8 | VM overlay `vdisk1.<name>qcow2`, libvirt's snapshot list dropped | `restore-vm-overlay-nolist-h-vm` | skipped: `needs tower vm` (USOTest3VM has no VM service) — written, never run | — |
| 9 | Unraid's move binary drops trailing whitespace (our fix) | `moverelli-sh-trailing-space-h-run` | **red** yes-said, INV-PAGE (core.js fmt.size below 1 B/s); the fix holds (prefer: moved exactly, yes: stays whole) | 37 s |

Findings (red stays red until fixed), ranked by risk:
- **H1 (data placement, high)** after a real run of his, his list gone (a restore of appdata, a hand), his next look writes
  it anew EMPTY — agent/desks/jack.php jackListEnsure() (:183) writes '' whenever the file is missing, «empty until his
  first real run», even after his runs. In the joint run his part can't plan (Emby 401) and «keeps the list as it was»
  (mediacache_run.py: «Planung unvollständig … bisherige Exclude-Liste bleibt geschützt» → «Exclude-Liste: 0 Einträge»),
  then her part (age rule 30 d) moves all 3 of his films to disk1 — night.json steps jack free ok, her free ok, jack fill
  ok; her status media moved 3. A missing list after a real run must never become «nothing to keep» (rebuild it from
  what of his lies on the pool, or refuse his share until his next real run writes it). Batch F's M19 «list gone» was
  green only because it reads the list after the run (an empty list → its loop vacuous).
- **H4 (app data, medium)** the pool holding an app's data at 199 MiB free (refreservation): the run stops and starts the
  app anyway — `uso-wip-h-dkapp` started again 09:53:09, the run «warnings» (only the backup place not offsite and
  immich's compose files), no word about the pool's room. B13's min_free_gb guards only the backup place. Docker's own
  folder on a full pool (Tower's case: broken layers after a pull) not covered — USOTest3VM keeps docker.img on cache
  with the place (that is B13).
- **H6 (offsite repository, medium)** Kopia's image VOLUMEs (/backups — where a local filesystem repository goes —,
  /source) unbound in the template: anonymous volumes, a new empty one at every re-make (the file in /backups gone from
  the container, the old volume dangling). Nothing says it before or after: the engine's docker_volumes warning skips
  Kopia on purpose (backup/setup.sh ~1495 `"$n" != "$KOPIA_CONTAINER"`), the Consultant's look names nothing.
- **H3 (space, medium)** Unraid's «Delete» of an emptied ZFS share with one @uso-backup-* snapshot: the cfg goes, the
  dataset stays mounted and listed in shares.ini. «Look at the server again», his look, Ms. Snapshotini's and the Team
  Lead's say nothing (only her list carries the dataset and snapshot as entries); the next run makes a second snapshot of
  the half-deleted share (1 → 2) and ends «warnings» as usual. Expected: a hint naming the share's snapshots and the way.
- **H9 (said, low)** the trailing-space fix holds: prefer share (array → pool) all 7 names moved exactly, «twin» and
  «twin » two files, nothing left on disk1; yes share (pool → array) the 4 plain names moved, the 3 ending in whitespace
  stay whole on the pool as smartmover/README.md says («stays (WARN … rename it)»). But her status for the share carries
  `left: 3, left_why: ""` — the reason never reaches her page (cell yes-said). First run's yes cells expected
  «everything moves» — against the README's design, reshaped (scenario bug). Seen beside it (INV-PAGE, Mac-side, the
  look during her run): her run card read «14 % · Ø 65 undefined/s» — public/assets/core.js:172 `Office.fmt.size()` takes
  the unit index from log1024(bytes) without a floor, so a rate below 1 B/s (274 bytes of tiny files over a slow start:
  ~0.06 B/s) gives index −1: «undefined» as the unit, the value ×1024.
- **H7 (said, low)** files born 30 days ahead: her age rule leaves them (never «very old»), the 40-day control moves, her
  run ok — her status counts them nowhere (`left: 0`): they stay unmoved for 30 days + the rule without a word. His run
  backs them up (snapshot, Kopia has all 4) without a word either. Neither side says «times in the future».
Green: **H2** — the dry run leaves his list byte for byte, and with it gone writes none (his look's empty one is H1's);
the brief's question «is that right?» — yes, by design (mediacache_run.py writes the list only in run mode). **H5** —
the real repository (GOVERNANCE 7 d, `--extend-object-locks=false`): the engine's look sees `extend: false`, the Team
Lead's kopia_lock_extend opens alone with the console command; the office never switches it on itself (only the
Consultant's confirmed install does).

Notes for whoever runs them: every `pgrep -f` inside a `bash -c` must not match its own command line (`[p]hp …`,
`[m]ediacache_run[.]py`) — the first runs hung (H2: 10 min) and left the agent stopped (H7: h_agent_stop saw itself).
H7's future files are written with the agent stopped (agent.sh stop/start): batch F's BF5 (a clock stepped back freezes
the agent) is not this finding. H3's user «Delete» is part of the state (before snap_before): otherwise INV-LEFTOVER and
INV-UNRAID-SAME report the user's own act. H8 needs Tower's uso-vm-plain and Unraid's VM manager (VM-MANY-SNAPSHOTS);
it is written against states-vm.sh but has never run — run it on Tower before trusting its cells. Reports:
`scenarios/2026-10-21/test3-scenarios-batch-h*` (`-0947`: all; `-1010`: H1, H9, H7 again; the last folder: H3 with its undo fixed — red only says-snapshots, undo green).

## Full run 2026-10-21/22 (Tower, office 1.70.2, engine 2.70)

One run of all scenarios on Tower, triaged (task/scenarios-fixup): `scenarios/2026-10-22/tower-scenarios-fixup` (100 of
the then 156, 08:30–12:32, stopped when a power cut left Tower's passed-through boot stick dead — the power-cut cells
need `--powercut` since) and `…/tower-scenarios-fixup-final` (the other 58 and the reruns, 13:10–17:32); round 2 (the
reruns of the last tool fixes and six run-late cells) was stopped for the 1.70.3 release. Tool and scenario bugs were
fixed in this branch, each its own commit; product bugs are findings for fix workers.

| Verdict (latest per scenario) | Count |
|---|---|
| green | 128 (B15 ×3 counted apart) |
| skipped (`needs` unmet on Tower: hdd, pool2, btrfs, compose, test3, the Emby premise of H1) | 10 |
| red or dirty, tool — fixed here, rerun pending (round 2) | 11 |
| red, product | 3 (below) |
| error (the power cut: Tower didn't come back) | 1 |
| not run (round 2 stopped): year roll, Moverelli's DST, B39's 6 h, R22 · power cuts (`--powercut`) | 4 · 4 |

Product findings:
- **B15** `backup-sv-docker-hung-replan-run` — INV-TICK: a 39 s heartbeat gap while dockerd is SIGSTOPped (red 2 of 3,
  docker calls of the agent's round outside houseDocker()). Fixed on main by task/docker-guard (554bcaa8): INV-TICK green
  in three reruns. Its side effect, INV-BUBBLE red in 2 of 3: right after Docker answers again Ms. Dustdevil still says
  «Docker doesn't answer» — houseDockerSilent()'s 120 s window (HOUSE_DOCKER_QUIET) keeps her refresh from asking.
- **Batch H #7** `backup-tm-future-mtime-h-run` — «said» red as documented: nothing says files carry times in the future.
- **Clock set ahead, then back** (seen in the DST cell's leftovers): the office's own at jobs handed to atd «now»
  under the clock set ahead (restore-partner-look, partner-ping, --kopia-watch) wait until that moment once the clock is
  put back — minor, the F12/BF5 family. Parked by the coordinator: the agent noticing a zone change at runtime (B43).

Tool fixes (the cell, then what was wrong): B12/M6/R5 judged or snapshotted mid-autostart (`ct_autostart_wait`); B43/M24
need test3 (a zone broken at runtime mixes zones); H2 predates the list rebuild (H1 fix); M8's run card measured against
the whole share, not what her rules move; H1 waited for her part of a joint run only, and on Tower Emby answers (no
verdict); M18's bubble fact read `others`, her state says `foreign`; M3's INV-TICK counted a clock step's mtime; O2
expected a real pairing's key line to survive the remove; R12's leftover check didn't skip a restore's copy of base.sh's
aside; R9 demanded free > 0 (the room is above the share's floor); R19's bubble facts raced a second-long pull; R5 drill
too quick to cut on Tower (no dump fits in RAM: no verdict); R15/R1 wrote files fresher than the drill's hour; H8 looked
for VM disks in the package, a ZFS disk is the dataset's snapshot; B43 clock-back's leftover check met the agent's due
jobs; the DST cell was killed by GNU timeout's wall clock (run-local's limit is the time since boot now). Earlier in this
branch: page checks right after each scenario (a reboot empties /tmp/uso-keep), stale stashes set aside by base.sh,
h_share_drop, CT-AUTOSTART, BK-APPLY errors=kopia (engine 2.67), INV-SETUP-MSGS on this scenario's plan only.

## The autumn's repeated hour and atd (2026-10-25)

`office-tm-dst-hour-at` (USOTest3VM, 4 min; `needs any`): TM-DST autumn 20 minutes before the switch — 02:40 CEST on
2026-10-25, Europe/Berlin linked where the zone file is missing — an office job handed to atd by hostLaunch() (the
plugin's own code, library mode): it runs within 2 min in the host's zone, at most 2 requeue lines in agent.log over
90 s, no office job left ahead, the clock and the zone put back (cells on-time, host-zone, no-flood, none-ahead,
clock-back). Red on 1.71.2 (the job queued for 02:40 CET, 314 requeue lines in 4 min — the loop the owner's server had
that night), green with `at` in UTC (task/at-dst). `$1` = an older release.

## USO network (the office's own Tailscale node)

`usonet-lab.sh up` makes the lab tailnet on USOTest3VM (a Headscale container, data in RAM, policy tag:uso → tag:uso);
`states-usonet.sh` has UN-JOINED, UN-HELPER-DOWN and UN-PAIRED. `caretaker-un-join-leave` needs only the lab;
`caretaker-un-pair-run-down` also a second office in the lab's tailnet (USOTest4 by default, `UN_PEER_IP`), reached as
root with USOTest3VM's key `$OFFICE_FLASH/scenarios-usonet.key` (+ `.known`; the peer's authorized_keys line
`from="192.168.7.197" … uso-scenarios-usonet`, never a uso-partner: line) — without either the cell is skipped. Never the
owner's real tailnet: the real-Tailscale check is done by hand with the owner's test key, which never lands in a file
here.

**Rounds 2 and 3 (Tower, office 1.71.0, 2026-10-22/23).** The tool fixes' reruns, green: M3, O2, R12, R9, R19, R15, R1,
H8, B43 clock-back. R5 drill: «no verdict» — a **coverage gap**, not green: on Tower the drill checks no dump (both too
big for RAM) and passes in ~1 s, before an array stop reaches the agent (parkplatz: a small dump that fits RAM, or the
day's share pick steered to a big settled file). Year roll: skipped (Tower's trial licence ends before 2027). The DST
cells: 90 min (the repeated hour runs in real time), the office's at jobs queued under the clock set ahead removed by
`clock_restore`, job.sh's minute stamp taken for crond's start (the stand-in a minute later landed across the DST step:
a run 20261025-0200 — a tool artifact). Product, not a finding: job.sh skips a second start whose minute stamp (local
time, `%Y%m%d%H%M`) is the last one's, so 02:59 CEST and 02:59 CET (and Ms. Moverelli's 02:00 twice) start once — still
to be shown by the DST cells on the fixed tree. Owed (nostromo's VM service stopped 2026-10-23 08:53): Moverelli DST,
backup DST, B39's 6 h, R22.
Product findings since: **docker-calm** (1.71.0) for the docker guard's stale «Docker doesn't answer» (B15, M7 2026-10-22:
one `docker version` over 5 s under a joint run's load held the house's docker calls for 120 s).

**Round 4 (Tower, office 1.71.0, 2026-10-23 12:55–15:42) — the owed cells, green:** Moverelli DST (64 min: one joint
run for the night, the second 02:59 refused by job.sh's minute stamp — her 02:00 on a real server likewise), backup DST
(63 min: one run named 20261025-0259), R22, B39's 6 h. Tool fixes on the way: the base reset starts the base's stopped
containers (after the VM itself was stopped nothing came back, Nextcloud's install waited in vain); a future
`last_heard` is put to now, not null (null read «never heard»: B39's silence had no «since»).

**Final, every scenario of the full run on Tower (1.70.2 → 1.71.0):** green or its intended skip, except — product:
B15's stale «Docker doesn't answer» (docker-calm, 1.71.0), batch H #7 (future file times not said), the at jobs queued
under a clock set ahead (minor); coverage gap: R5 drill (no verdict on Tower, parkplatz); not run: the four power cuts
(`--powercut`), year roll (needs a licence past 2027).

## INV-TRUTH — the page against the server's truth (2026-10-25)

The truth review of 2026-10 (briefs `truth-review-2026-10.md`) found 73 page lies; the tests had checked that things
work, not that pages say what is there. INV-TRUTH is in the standard set: at the scenario's last look (the desks' fresh
looks, their state files in `$KEEP/looks/<n>-final/states`) `truth.sh` reads the server's truth beside them with
`truth-facts.php` → `truth.json`, and the Mac draws the pages from those state files and compares (`queue_mac INV-TRUTH
page_truth <look> en` → run.sh's mac_check → `truth.mjs`). Pending (mac) on the VM alone, red there turns the scenario
red (`<name>.truth-<n>.txt` beside the report has every line). Not in base.sh's own checks (like INV-PAGE, INV-BUBBLE).

**The truth is never the office's code.** `truth-facts.php` loads nothing of agent/ or src/; it reads the server and
the engine's own files: the engine's snapshots (`<snap_prefix>YYYYMMDD-HHMM`) on the very dataset that holds a share's
place, a VM's disk or an app's folder (`zfs list`, `findmnt -T`; btrfs `<disk>/.btrfs-snap`; XFS and the array hold
none; a sleeping disk is never looked at); Kopia's own list (`kopia snapshot list --all`, and `kopia ls` of a share's
newest snapshot for a VM's or app's folder inside it), the engine's `kopia-held.json` / `kopia-watch.json`; each partner
office's door (`list <unit>` with the pair's own key — the receiver's zfs, not the sender's history; **never USOPartner
or nostromo**: «not asked», the rule unjudged); Docker (`docker info`), libvirt, the engine's runs (`history.jsonl`).
The page's claim is read off the DOM: rows by `data-place` (`row:share:<name>`, `vm:<name>`, `app:<name>`), chips by
their text in the drawn language (`office.protect.*`, `protect.why.*`, Restori's `chip.*`), tiles by `data-tile`.

| Rule | The page must not … | Review |
|---|---|---|
| TR-LOCAL | show a level above «not backed up», or «n local snapshots», for a unit no engine snapshot holds (part of it: a note) | T08 |
| TR-SNAPS | count more snapshots than its folders' datasets hold (snapshots of any name: the chip's own words) | R10 |
| TR-OFFSITE | say «offsite» without a complete Kopia snapshot of it; green alone while the repository is held or stuck | T02 T09 T10 |
| TR-PARTNER | say «local + partner» / «at X» when X's door lists nothing of the unit (a VM or app in a dataset of its own: only its own unit counts); green alone when the partner's newest is not the last run's snapshot | T05 T06 |
| TR-ROW | leave a share of the server without a row on Mr. Backupsy's overview once a backup ran | T07 |
| TR-KOPIA-TILE | keep the offsite tile calm, or the bubble at «saved offsite», while the repository is held or stuck | T02 T10 |
| TR-DOCKER | call apps «gone» while Docker is enabled and silent, or never say «Docker isn't running» (Mr. Restori) | T12 |
| TR-CHECKS | count in «In place» anything but ok true, or as a must anything but required and ok false (ok null: neither) | T13 |
| TR-UNDER | (a note, never red) say «not backed up» for a unit whose datasets hold the engine's snapshots | — |

Pages drawn: Mr. Backupsy's overview, Mr. Restori's apps and VMs, the Team Lead. Not yet: «Where is what» and Ms.
Dustdevil's rooms (P1's chip), the flash row and the apps' package rows, the Dashboard tile, the Consultant and Jack
Streamer with Docker off (R7's other pages), the S2 rules R8–R11 (done/quiet, freshness, numbers, dialogs).

By hand: `bash tools/scenarios/truth-now.sh [--host tower] [--refresh] [--verbose]` (a VM's state files and truth now,
the pages compared; `--keep <dir>` keeps the look); `node tools/scenarios/truth.mjs --look <dir> [--lang en,de]
[--verbose] [--only TR-…]`; `node tools/scenarios/truth.mjs --self-test` — `tests/ui/states/truth/` with an invented
truth (`inv-truth.json`): exactly its six listed lies red, in English and German, nothing else.

## Base profiles — Tower's «mixed» (2026-10-25)

A base profile is the default base plus objects of its own: `base/<host>/profile-<name>.env` over base.env, its kept
data folder `<data>.base-<name>/`, its objects made by `base-<name>.sh`. `base.sh --profile mixed` resets to it
(`USO_BASE_PROFILE=mixed` the same); **the default reset takes the profile's objects away first** (noted on the flash,
`scenarios-mixed.objects`), so the default base and every scenario on it stay exactly as they were. The profile the
server is on: `$OFFICE_FLASH/scenarios-profile` (none = default). A scenario picks its profile with a line
`# base-profile: mixed` in its head; `run.sh … --profile mixed` (and `run-local.sh --profile`) puts every scenario of the
call on it. The runner resets the base whenever the next scenario's profile is not the server's.

**Tower's mixed profile** (`base/tower/profile-mixed.env`) makes Tower look like a real, messy server:
- VMs: uso-vm-agent and uso-vm-plain in datasets of their own (the base's); **uso-vm-shared** a plain folder in
  domains' own dataset; **uso-vm-xfs** on the share uso-vmxfs on disk1 (XFS — no snapshot can hold it); uso-vm-plain
  **running and never held** (prepare none).
- Apps: in appdata (Emby, Plex, Nextcloud + MariaDB …), Jellyfin in a dataset of its own, Node-Exporter without data,
  immich a compose stack (all the base's).
- Shares: media on disk1 (XFS) and disk2 (ZFS) at «local» — half of it can't be snapshotted; uso-arr (XFS) at «local» —
  none of it can; nextcloud and uso-only offsite (Kopia on); appdata, domains, nextcloud and uso-vm-agent sent to
  **USOTest4** over the LAN (a pair made the Team Lead's way: Tower offers, USOTest4 accepts as a receiver only, quota
  40 GB, retention 7 4 6). The backup place UnraidSecretaryOffice goes offsite too (the page's own draft with Kopia on).
- Not in it: a share on two pools (Tower has one pool, two NVMe; a second needs vdisks from the coordinator); a USO-network
  pair (usonet-lab.sh lives on USOTest3VM).

Made once from the Mac: `bash tools/scenarios/mixed-make.sh [--pair]` — the reset (onto the mixed base when there is
one, keeping its pair; else the default), `base-mixed.sh objects`, the pairing when USOTest4 doesn't answer the pair,
Mr. Backupsy's Apply with the profile's keys and then the page's own draft until the plan lists nothing new, `base.sh
--profile mixed --save`, one real reset onto it. A reset to it takes ~30 s (its checks green, the plan's change list
`plan-changes.mixed.known`). **USOTest4's own base reset sets its data folder aside and with it its half of the pair**:
the mixed reset then says «the partner doesn't answer the pair», the partner rules go unjudged (the door refuses), and
`mixed-make.sh` pairs anew. The pair is a receiver-only one on USOTest4 (no [partner] in its own plan: its INV-REPLAN
stays as it was).

`backup-mx-mixed-truth-run` (`# base-profile: mixed`): one backup run on the mixed profile, then the standard set with
INV-TRUTH — the cell for the profile itself.

**First runs (Tower, office 1.71.3, engine 2.74, 2026-10-25)** — `scenarios/2026-10-25/tower-inv-truth-0917` and
`…/tower-inv-truth`. On the mixed profile (`--profile mixed`): backup-mx-mixed-truth-run, office-smoke-refresh,
backup-vm-ct-paused-shutoff-run, backup-ct-app-is-dataset-run, backup-kp-container-stopped-replan-apply-run,
restore-ct-stopped-db-db, restore-sv-docker-off-scan-drill, office-sv-docker-off-bubbles, backup-sv-docker-off-first-setup-run.
INV-TRUTH: green on every one once two tool bugs were fixed (Mr. Restori's «offsite …» chip is judged against the source
its tip names — the package's goes with the backup place's share; Docker switched off counts as silent). The S1/S2 lies
of the review are gone on 1.71.3: the same mixed look drawn with v1.71.2's page (`truth.mjs --tree v1.71.2`) is red on
exactly T08 (media and uso-arr «only local», no snapshot holds them) and T13 (a check that couldn't run counted as a
must). Notes, never red: Mr. Restori names no partner for apps and VMs whose data USOTest4 holds (his look of a new
partner not yet made). Red, not INV-TRUTH: backup-sv-docker-off-first-setup-run on the mixed profile — with shares
offsite, a run with Docker switched off ends «errors» (Kopia's container unreachable; the engine's log says it «does not
exist»), the cell expects ok/warnings from the default base, where nothing goes offsite. Profile switch in one call
(mixed → default): the objects taken away, the default base's checks and plan list green.
