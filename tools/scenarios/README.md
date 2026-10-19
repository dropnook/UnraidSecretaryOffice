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

## Flows and the remaining checks (wave 1b, H5: flows.sh, checks.sh, bubble-rules.tsv)

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
| The office | `OF-UPDATE plg=<file\|url>\|update\|worktree` · `OF-REMOVE-REINSTALL [plg=]` · `OF-ARRAY-STOP-START` · `OF-REBOOT` (pre half) / `OF-REBOOT-BACK` (post half) · `OF-AGENT-RESTART` · `OF-HIRE desk=` · `OF-FIRE desk=` |

Checks added to the standard set (n/a where they don't apply): INV-SETUP-MSGS, INV-STOPPED-KEEPS, INV-NC-MAINT-OFF,
INV-MOUNTS-GONE, INV-NO-MNT-OPEN, INV-COMPARE, INV-MIGRATE-IDEMPOTENT; INV-REPLAN (the Apply list = the base's
`base/<host>/plan-changes.known` + `expect_changes`, a trailing `*` matching the rest; Ms. Moverelli's settings saved
unchanged after one of her flows), INV-COUNTS' restore and moverelli parts, INV-PAGE, INV-BUBBLE; the final set
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
