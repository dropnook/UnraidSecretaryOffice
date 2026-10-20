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

**MV-STOP-AFTER-FILE share=<share>** waits for a file of that share in flight (her plan may take other shares
first: up to 5 min); M1-stop uses it. **SH-PREFER-ARRAY-FILES** puts its files in a folder per disk
(`sh-paf-d1` on disk1, `sh-paf-d2` on disk2 — the same path on two disks would be duplicates Unraid never moves); its
undo takes them from wherever a run put them; M1-run uses it again.
**INV-TICK** (checks.sh overrides lib.sh's): the gaps between the pulses the sampler saw, and at the end the
heartbeat's age right now — lib.sh took «now − the last pulse the sampler saw», but the sampler stops at snap_after and
the checks before INV-TICK take seconds (B5 and M1-stop: 26/28 s = a pulse 20 s before snap_after + 6/8 s of checks,
the agent fine).

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
