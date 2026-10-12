# Working on Unraid Secretary Office

Read README.md first (what the office does, for users), then docs/DEVELOPMENT.md — it explains the architecture and how a
desk (secretary) is built. This file holds the rules: conventions, the desks in a few sentences each, the glossary, the UI
conventions, server facts and the checklist for a change. It says *what holds*, not how it came to be: the detailed notes of
how every piece was built, decided and tested up to 1.60.0 (per desk, per engine version, with the names of every helper
and test) are in this file's earlier shape — `git show v1.60.0:CLAUDE.md | grep -n '<word>'` finds them. The code, the
tests (`tests/run.php`, a test per piece) and `backup/README.md` are the reference for details.

## How it runs: the plugin

The office is an Unraid plugin — the only way to run it. Its code lies in RAM under
`/usr/local/emhttp/plugins/unraid-secretary-office/` (`public/` at its top, `src/`, `agent/`, `backup/`, `embycache/`,
`gather/`, `smartmover/`, `scripts/`, `event/`, `images/` beside it — built by `plugin/build.sh`). Unraid's nginx/php-fpm
serve the page behind Unraid's login: `SecretaryOffice.page` puts the office into Unraid's menu bar (`officeInUnraid()`,
`OFFICE_IN_UNRAID`), or — the user's choice in the ⋯ menu, `caretaker.menu_name`, kept as `MENU_NAME`/`MENU_PLACE` in the
.cfg and put back by the .plg at every boot (`officeMenuPageApply()`) — an icon under Settings → User Utilities or only a
button in Unraid's header (`SecretaryOfficeButton.page`, loaded on every Unraid page: keep it a one-liner).
`SecretaryOfficeDashboard.page` puts a tile on Unraid's Dashboard (src/dashboard.php: state files only, `api.php?a=dash`
every minute while in view).

The **agent** is a service (`scripts/agent.sh`; `event/started` starts it, `event/stopping` stops it; `ARRAY_RUNNING` —
fsState Started, Formatting or Clearing — is defined once each in agent.sh, agent.php and the engine, a test compares
them). While the array isn't started, `agent.sh` runs the night watchman's **night shift** instead (`php agent.php
nightshift`, RAM and flash only, never both at once); the page and the Dashboard tile say so (`agentInfo()['night']` from
`officeNightShift()`, src/mailbox.php — the pid in `WATCH_NIGHT_LOCK` living as `agent.php nightshift`, never the lock
itself). Without the data folder the page shows only the reception and looks again every minute.

The **data folder** is `DATA_DIR` from `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
(default `<appdata>/UnraidSecretaryOffice/data`; `OFFICE_DATA_DIR` overrides it — the tests use the repository's `data/`,
`OFFICE_WEB` its `public/`). In an exclusive share the agent and the web side use the pool path directly
(`officeUnraidPath()`, 4 instead of 200 µs per look); `DATA_DIR_USER` / `OFFICE_DATA_USER` keep the path as set for what
the user and other programs read (the engine's `UB_DATA`, `dataPathUser()`); the agent decides at its start and
`dataWayLook()` watches it every minute. The office's RAM folder is `officeRunDir()` (src/place.php,
`/var/run/unraid-secretary-office`, the agent's `RUN_DIR`; `OFFICE_RUN_DIR` for the tests — they never touch the live one).
Development: a git clone anywhere, `plugin/dev-sync.sh` puts it into the installed plugin (see the checklist).

## Conventions

* **English** in code, comments, commit messages, README. The UI is translated: every string lives in
  `public/lang/<code>.json` (office) or `public/desks/<id>/lang/<code>.json` (desk). `en`, `de`, `it`, `fr`, `es` always
  have the same keys — a new UI string needs all five; placeholders `{…}` and plurals match `en` (tests/run.php checks it).
  The office speaks the browser's language; Unraid's own labels in the texts are tokens `⟦English label⟧` read in the
  language Unraid runs in (see «Words»). French `one` covers 0 and 1: write `{n}` in French one-forms where 0 can occur.
  **Counts are plurals:** a text whose noun, verb or pronoun changes with a number is `{"one": …, "other": …}` chosen by
  the param `n` (core.js `t()`, `officeNotifyText()`, `officeDashT()`), never «file(s)»; a text with several counts is
  composed from `count.<what>` keys (`nOf('<what>', n)` / `T('count.<what>', {n})`) and keeps only its glue. Counts that
  never reach 1 may stay plain. `testStrings` guards it. Never hard-code UI text in JS or PHP.
* **No build step, no dependencies.** Plain PHP 8.4 (Unraid's own PHP runs page and agent — use nothing newer), vanilla
  JS, one CSS file plus optional `desk.css`; the look's tokens live in `office.css :root`. The only «build» is the plugin
  package (`plugin/build.sh`).
* **The web side never touches the host** (though php-fpm runs as root). Everything that needs zfs, docker, /boot/config,
  /proc goes through the agent (`Office.api.post('<desk>.<action>')` → `agent/desks/<desk>.php`). Exceptions: the night
  shift's RAM state while the agent is gone; secrets the user types for the agent go through `apiSecretStash()` (a 0600
  file in `officeInboxDir()`, RAM — never the mailbox on the pool), the request carries only its name, the agent reads
  and removes it (only `OFFICE_SECRET_ACTIONS` may carry a `secret`); the web side reads the agent's heartbeat
  (`agentRecord()`) and rings its **doorbell** after dropping a request (one byte into the FIFO `RUN_DIR/doorbell` the
  agent makes at every start, `agentRing()` — lstat then fstat, opened read+write, non-blocking; the agent waits on it
  between rounds, `agentNap()`), never a signal or a process.
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every action re-reads the current state and
  validates ids against it. Errors are `throw new Problem('key', [...])`, the UI translates `errors.<key>`. **Request
  fields are read, never cast:** `textField()`, `optText()`, `idList()`, `boolField()`, `cronField()` (agent/lib/util.php;
  `apiText()` on the web side) — never `(string) ($r['x'] ?? '')`, and never let a missing key or wrong type turn a
  setting «off». The agent reports every PHP warning (`error_reporting(E_ALL)`, `agentPhpError()`); the web side keeps
  Unraid's level. `testRequestTypes`, `testStrictSettings`.
* **Hardening:** files only through `writeAtomic()`/`writeNewFile()` (exclusive random tmp, mode from the start, never
  through a symlink); the agent reads the mailbox only while it is a real folder of the web server's user
  (`privateDirOk()`); validators that feed names, files or commands end with `$/D`; links built from state files only via
  `Office.safeHref()`; manifests read back from shares are trusted only in exactly the shape the office writes.
  `HARDENING.md` lists what was checked and what is open.
* **Never wake sleeping disks on your own.** Check `sleepingDisks()` before reading array disks; offer an explicit «wake»
  option instead. Only a rotating disk is ever asleep for the office (`diskAsleep()`, the engine's `ub_asleep_load`).
* **Nothing directly in /mnt** (Fix Common Problems): mounts go to `/mnt/addons/UnraidSecretaryOffice/…` (RAM), data the
  desks keep to the share `UnraidSecretaryOffice/<desk>/`. **The office never creates a share** — the user does, under
  ⟦Shares⟧; the office says which and why.
* **Never block the array stop:** no sockets or open files in the pool; the agent keeps nothing open there (doorbell and
  heartbeat lie in `RUN_DIR`). The night shift never opens anything under `/mnt` (cwd `/`, `watchmanNightPaths()`).
* **Keep `tick` functions cheap** — they run every ~150 ms. Long work runs as a background process polled from `tick`.
* Desk lang keys `name` and `role` are the desk's title and subtitle — don't reuse them.
* **No lock of its own:** every page and POST is behind Unraid's login (nginx `auth_request`) and carries its
  `csrf_token` (plus the API's `X-Office`/Origin checks); whoever is logged in to Unraid is root. The office adds
  previews and confirmations — never a PIN or a second login. HARDENING.md «Who gets in».
* **Checks for the Team Lead:** a desk that needs plugins, containers or settings registers `'checks'` returning
  `finding()`s (agent/lib/house.php): `required` only when the desk really can't work without it, else `recommended`;
  `hint` for things to know. Texts: `check.<id>` states how it should be, `check.<id>_how` what the user does in Unraid.
  Checks run every 30 min in the agent's tick without a browser — cheap, state files, never waking a disk (a sleeping
  place → `ok = null`). What a finding says is part of what was noted («I know, thanks»): change a check's text only when
  what it says really changed (`testAckContent`).
* **Metrics for Prometheus** (`agent/lib/metrics.php`): a desk may register `'metrics' => fn (): array => [...]`
  (families `uso_<desk>_…`, gauge|counter, samples with few label values; `metricsGauge()` builds one), called once a
  minute for hired desks — state files only. The agent writes `uso_<desk>.prom` + `uso_office.prom` into
  `/mnt/addons/UnraidSecretaryOffice/metrics` (RAM; `metricsEnsureDir()` makes a missing `/mnt/addons` as a plain folder,
  refuses links). All files together ≤ `METRICS_MAX_BYTES` (16 KB; the biggest families dropped first). Names each once
  over all desks, times as `…_timestamp_seconds`, no `_count`/`_sum`/`_bucket` on a gauge. Renaming one means changing
  `monitoring/grafana-dashboard.json` too. Tests: `OFFICE_METRICS_DIR`.
* **Notifications** to Unraid only through `officeNotify()` (house.php): event and subject prefix `Unraid Secretary
  Office` like the engine; `warning` for something to fix, `alert` only when something is at risk now; texts via
  `officeNotifyText()` in the language the office was last used in (`officeNotifyLang()`: `data/office/lang.json`, the
  night shift's copy `RUN_DIR/notify.lang`), keys `notify.*` in the desk's lang file; tests set `OFFICE_NOTIFY_BIN` to a
  stand-in. Agent, night shift, agent.sh (`tell`) and engine (`ub_notify`) take turns through one stamp in RAM
  (`OFFICE_NOTIFY_STAMP` = `RUN_DIR/notify.second`) because Unraid drops a second notification of the same event in the
  same second. The Team Lead reports new red findings once after 30 min (`data/caretaker/notify.json`, switch
  `notify_set`). `agent-watch.cron` (`job.sh watch` every 5 min) alerts when the agent's heartbeat is older than 70 s
  for 10 min with the array started. **The heartbeat lives in RAM** (`RUN_DIR/agent.json`, pulse every 20 s,
  `agentPulse()`; the data folder's copy only at start/stop); `agentUp()` is the one rule for «at work»; a request waiting
  while the heartbeat says otherwise is answered `agent_away` after `AGENT_AWAY_GRACE` (4 s); action buttons are
  disabled while `Office.agent.running` is false.
* **Jobs that must outlive the agent** (backup runs, restores, drills, let-gos, Jack's and Ms. Moverelli's runs) go
  through the host's `atd` (`hostLaunch()`, `backupLaunch()`), never as a child of the agent: `agent.sh stop` ends the
  agent's whole session.
* **Schedules** only through `officeJobSchedule()` / `officeJobSetSchedule()` (house.php): a line in the plugin's cron
  file (`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`, calling `scripts/job.sh <job>`,
  which runs only while the array is started). Never User Scripts for the office's own jobs (User Scripts are the user's).
* **The backup engine** (`backup/`, bash, English) runs on its own via the plugin's cron file. Mr. Backupsy only uses
  its interface (`backup.sh --about`, `data/unraid-backup/state/status.json` & co., `setup.sh --plan` / `--apply` with
  `state/setup-plan.json` / `setup-status.json`). **Never parse its log lines for anything new — extend the
  interface:** reasons go out as codes (`die_code`, `why`/`ctwhy` → `backup.message.<code>`), every setup hint and warning
  has a code (`hint_as`/`wrn_as` → `setup.msg.<code>` ×5, `testSetupMessages` greps setup.sh), every drift note has a code
  (`drift_add` → `drift_code.<code>` ×5, `testDriftCodes`); the engine's own notifications and drift.txt stay English. The
  server's name comes from ident.cfg (`ub_host_name()`), never settings.ini. Version lives in backup.sh, setup.sh,
  lib/common.sh and backup/README.md (`UB_VERSION`), with a CHANGELOG line. backup.sh and setup.sh are one `{ … }` block
  (bash reads all of it before a run starts — code goes inside the block); replace files with a new file + `mv`, never
  by writing into them; after a `mv` on the host compare md5 on both sides before committing (SMB may show the old
  content). **A reader that stops early** (`grep -q`, `break` in a loop) never reads a line-writing helper through a
  pipe or `< <( )` — SIGPIPE, and under pipefail the match counts as none — but a `$( )` of it (`<<<"$(helper)"`,
  `mapfile`); `testBackupEpipe`. Under `set -o pipefail` a `while … | jq … || fallback` loop must not end on `[[ … ]] &&
  cmd` (use `if`). Engine fixtures run on the tests' own clock (checklist 3).
* **Engine rules that hold** (details in backup/README.md and `git show v1.60.0:CLAUDE.md`): one run at a time behind
  `state/lock` + `state/lock-holder.json` (`backupLockHolder()`; the office sees the lock only through `flockHeld()`,
  never by taking it; a busy start writes `skipped.json`, exit 75, kept apart from runs as `state.skips`); **one real
  backup a minute** (its id, log and snapshot names carry the minute; tours, dry runs and setups log by the second); names `uso-backup-YYYYMMDD-HHMM`
  (`snap_prefix`; old `unraidbackup-` still recognised, always exact — `backupIsEngineSnap()`); **backups never live in
  appdata** (`[general] dumps_share`, the office's share → `backup/`; without a valid one the engine refuses to run);
  **packages** (`apps/<app>/`, `vms/<vm>/`, `server/`, `flash/`, each with `manifest.json`, swapped in before the
  snapshots, never deleted; since 2.43/2.44 a VM's package is also written into `domains/<vm>/.uso-backup/` and an app's
  into its own folders, so every snapshot — local or at a partner — carries its configuration, `testBackupInside`);
  **Kopia per app/VM** (`[app|vm] kopia = yes`, sources `<mount_root>/.apps|.vms/<name>`, never under another source's
  policy path, never `@` in a source path; order flash, apps, then shares and VMs by expected size — a sparse vdisk by
  its apparent size, `VM_APPARENT`); **new things stay local until the user decides** (a new top folder inherits its
  share's level; in app/VM shares a folder follows the app or VM owning it; `kopia_known`; new apps/VMs at most «local»,
  marked «new — please decide», or the user's default `[general] preset_new = auto|local|kopia|partner|kopia_partner`,
  which only Apply makes so); **VMs that shut down go first** (`[vm] prepare = shutdown|freeze|pause|none`, never forced
  off; a VM not off by its deadline is paused — a warning, never an error; `testBackupVmOrder`); **the array stop ends a
  run at once** (`array_stopping()` looked at every phase; Kopia interrupted, mounts released, `aborted` + exit 3 — the
  office shows a stopped run, never a failure; `testBackupArrayStop`) and `backup.sh --recover` repairs what an aborted
  run left at the next array start (no run, `logs/recover.log`); **sleeping pools** (`[general] asleep_pools = wake|skip`;
  with skip a sleeping pool's units are left out and said so, never failed; the backup place's and the data folder's
  pools are always woken; `testBackupAsleep`); **the partner phase** (`[partner "<id>"] name, address, port, rate_mbit`
  from pairs.json, units `[share|vm] partner = <id>`, `[general] partner_place`; a unit is ONE dataset of its own; ZFS
  send through the door, `partner_ssh_cmd()` the one place for ssh options; never `zfs send -s`; results done / skipped /
  failed with codes; whatever a partner did not get is a warning in that same run, no nights of grace (2.46,
  `partner_missed`, said in Mr. Backupsy's bubble; `testPartnerAtOnce`); `status.json` `partner` with `current.bytes` every 10 s and `estimate`; the rate shown is the median
  of the last 10 min like Jack Emby's panel; `testBackupPartnerPhase`); `state/pruned.json` for the watchman.
* **Jack Emby's tools** ship with the office: `embycache/` (EmbyCache by helmi1987, Python 3 stdlib) and `gather/`
  (media-disk-gather, bash). Changes are ours now, kept small and listed at the top of their README.md; their data stays
  apart (`EMBYCACHE_DIR`, `CONSOLIDATE_CONFIG`) and they report through status files, never parsed log lines. EmbyCache
  MOVES files — test with report and dry runs. Runs go through `php agent.php job embycache|gather <mode>`; a real run
  holds the other tool's lock. The cleaner is left out on purpose.
* **Versions:** `OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION` (agent/agent.php) move together; the release
  tag is `v<version>`. The plugin's version is a date (`2026.10.05`, a letter for a second one that day; Unraid compares
  with `strcmp`), picked by the GitHub Action (`.github/workflows/plugin.yml`) from the previous release's `.plg`;
  `plugin/build.sh` refuses when the code's versions don't match the tag. Releases through `tools/release.sh <version>`
  from a clean main (it runs the click test and the suite first); notes in English for users; afterwards check that the
  `.plg` got a new version. The Unraid the office was tested on is ONE constant, `OFFICE_UNRAID_TESTED` (src/place.php);
  the .plg's `max` is `<major>.99.99` of it — raise it only once tested (`testUnraidTested`).
* **Updates:** users update through Unraid's plugin manager and read no notes — every version starts on any older
  version's data folder, and an older one on a newer's. `officeMigrateStart()` (agent/lib/migrate.php, in `setUp()`
  before any desk reads state) is **the one place for migrations**: a step `['id', 'version', 'run']` in
  `officeMigrateSteps()`, idempotent, logged, never deleting (`officeMigrateAside()`: `<name>.before-<version>`).
  Rules: new keys are additive and read with a default — in PHP and in desk.js (the first state a new page meets can be
  the old agent's); keys a reader doesn't know are kept on a rewrite; no rename of a key, file, path, cron command, door
  path, snapshot prefix, Kopia source path or desk id without a step and readers of both; exact-shape files (pairs.json,
  the watchman's mirror, the drill's certificate, the storeroom's manifests) change only with a new `v`/`interface`;
  the engine's defaults for settings.ini keys don't change. **Tolerant writers** (`testPartnerTolerant`): a reader of an
  exact-shape file uses only entries in exactly its shape, a writer keeps every entry it doesn't recognise as it is.
  **The install swaps** (`testPlgInstall`): the package is unpacked beside the running office and checked before anything
  happens; the .plg refuses an update or remove while a run, setup, restore, drill or one of Jack's/Ms. Moverelli's runs
  is active (one `pgrep -f` pattern, `testPlgGuard`); the remove runs `agent.sh release`, puts the cron file aside
  (`officeCronBack()` restores it when the agent starts without one) and takes the office's lines out of
  authorized_keys. The page notices a new agent version and offers «Reload» (`updateNotice()`, never forced);
  `Office.loadState()` asks for a fresh look when a desk's render throws on a kept state (`testLookPage`); Mr. Backupsy
  re-plans once per engine version (`backupSetupReplan()`).

## The desks

Each row says what the desk is, where its code lives and the rules that hold for it. The build notes per feature (every
helper, every decision, every test by name) are in `git show v1.60.0:CLAUDE.md`; the tests named here are the entry
points (`php tests/run.php <name>`).

| id | name (en / de) | does |
|---|---|---|
| `snapshot` | Ms. Snapshotini / Frau Snapshotini | ZFS, btrfs and VM snapshots: create, delete with an estimate, rename, hold, unmount; schedules with retention (lib/snapshotplans.php: snapshots `uso-plan-<plan>-YYYYMMDD-HHMM`, retention touches only those, never the engine's — `backupIsEngineSnap()`; `job.sh snapshots` every 5 min). Plans are read through `snapPlans()` only (one normalising reader, unknown keys carried on); a target that is gone is no failure (`snapPlanTargets()`, told once, chip «target gone», the Team Lead's `plan_target_gone`). She lists only awake ZFS pools (`poolsBySleep()`), keeps what she last saw of a sleeping one (`asleep`, «as of …»), and every action on a sleeping pool's snapshot refuses unless the request carries `wake` — the page asks, never on its own. A partner's copies under `<pool>/UnraidSecretaryOffice-partners` are chipped and never a target, never deleted, renamed or held while the pair exists (`snapshotPartnerMark()`). Ids only in the shape her scans make (`snapshotIdOk()`). A deleted VM's leftover snapshot list (`snapshotdb/<VM>`) is judged entry by entry (`snapshotVmLeft()`); only a true orphan can go into Ms. Dustdevil's storeroom (`vm_unlist`/`vm_relist`), she judges the same way. Tiles (tiles v2): a card per ZFS pool, «Array» (one card, a row per array disk — every array disk, btrfs or ZFS-formatted, like Unraid's Main page), the VMs, «Schedules», «Snapshots»; the pool is the one filter kept (`snapshot.pool`), the section choice `snapshot.tile`; `poolSplit()` says per source what deleting would free; her list's bar is `Office.filterBar` (`snapshot.list`) with the three hide switches as extras — a switch redraws the row at once (`sw.row()`). Tests: `testPlanGone`, `testSleepingPools`, `testSnapshotPartner`, `testSnapshotIds`, `testVmOrphans`, `testSnapArrayDisks`, `tests/snappool.js`. |
| `backup` | Mr. Backupsy / Herr Backupsi | runs the engine in `backup/` through its interface only (see «The backup engine»). Two pages as tabs, «Overview» / «Set up» (`#/backup`, `#/backup/setup`). Overview tiles (tiles v2, `backup.tile`, `tests/backuptiles.js`): Last runs, Schedule, the protection filters (Offsite / Databases / Packages / VMs / Flash → «Is everything protected?» filtered, never stored), Protection, Partners, Changed (drift), Restore; a run going on shows `runningCard()` in the row's place (the step «Partners» only in runs with `status.partner`, the rate like Jack's panel with an estimate and the time left; `skip_kopia` → «Backup without offsite upload»). The run card titles the mode (`runMode()`); Only a real backup holds its minute (its snapshots carry it): one asked for within that minute is queued through atd for the next («I start at hh:mm», «Don't start it»); a tour, dry run or setup never waits (their logs carry the second). **The setup** (`#/backup/setup/<basics|vms|apps|shares|retention|more|sources|messages>`, its steps are tiles of their own, `backup.setup.tile`): a level per share, VM and app — «off · local · local + partner · local + offsite · local + partner + offsite» (the partner levels only with a pair and for a dataset of its own, `partner.why.<code>`; the backup place a two-field switch under Basics) — `setupDerive()` turns it into the engine's share modes, `docker|no_stop`, dumps and Kopia ignores; an app's further shares are never ticked unasked; «Start anew» = `setup.sh --forget`. **The default** for new things (`preset_new`: auto · local · kopia · partner · kopia_partner, cards at the top of a new server's setup, the dialog's scope «everything now» / «only what is new»): only Apply makes it so; new things otherwise wait «new — please decide»; a re-plan keeps hand changes (`setupDraftKeep()`). **First place:** while the plan has no backup place the setup opens on «Basics» (`setupPlaceStart()`, `sw.pick('basics','start')`, never kept) with the guide first and «Review…» greyed; the bar says what to create (a share on a ZFS pool, exclusive, not the array; a mirror first) — the office never makes the share. `backupPlaceFacts()` warns (same pool, no history, secondary, no redundancy), never blocks. Apply sends `general|…` keys only when chosen on the page (`ONLY_CHOSEN`), the dialog lists exactly what goes against settings.ini as the engine reads it, the agent refuses decisions made on a plan older than settings.ini (`setup_plan_old`); a gone app or share is listed «gone — entry will be removed». A failed plan is shown with its errors (`setupFailedCallout()`), never «One moment…» forever. Checks: `kopia_running`, `kopia_autostart` (Unraid's autostart list, Docker's `always`, Compose Manager's stack autostart — the office warns, never starts Kopia), the Kopia repository finding from the newest run that checked it (`backupKopiaLastWord()`), the data share, the dumps share. Let go: «Also clear away what he kept here» → an atd job holding the engine's lock, the packages into Ms. Dustdevil's storeroom, the engine's snapshots deleted (`testBackupLetGo`). Partner: `partnerSendUnits()` from settings.ini says what this server sends; the Team Lead's card says what is missing on either side (`partnerReady()`: Mr. Backupsy set up with a place for sending, Mr. Restori for starting). Tests: `testBackupTiles`, `testSetupTiles`, `testSetupMessages`, `testBackupPresets`, `testSetupFirstPlace`, `testSetupFilter`, `testSetupAsleepKept`, `testSetupGoneApp`, `testBackupKopiaAutostart`, `testBackupPartnerOffice`, `testBackupInside`. |
| `emby` | Jack Emby (the intern) | EmbyCache (`embycache/`, from helmi1987, extended: back to the origin disk via embycache_origin.json, the emptied folder stays as a signpost, limits for started films and series) and the gather «Consolidate folders» (`gather/`). Settings through EmbyCache's own save_config() (trial file first); the gather's ini written by Jack (`embyGatherIni()`); the API key never leaves the server. **Rsync both ways, always** (`EMBY_TOOLS_RSYNC` laid over every read, write and his environment — an old `mover` reads as rsync; EmbyCache keeps its mover path for standalone users). **Unraid's own mover schedule must be «Disabled» for his real runs** (`embyMoverRule()`; Mover Tuning is no way around it since 7.2.1; his page has «Switch the mover schedule off…» = exactly Unraid's form through `emcmd`, after a confirm — `OFFICE_EMCMD_BIN` points the suite at nothing); Mover Tuning (optional) gets his exclude list written at every look (`embyTuningSet()`, line by line, every other byte kept); a mover at work stops his run after the file it is on (`EMBYCACHE_STOP`, `CONSOLIDATE_STOP`, result `stopped`), and he never runs beside Ms. Moverelli nor she beside him (`RUN_DIR/movers-start.lock`). **Never a real gather while someone watches Emby** (`embyWatching()` asks `/Sessions`; refused from the page with who watches what, waits up to 2 h on schedule, stops a running gather after its folder); dry runs are never asked. The live panel while EmbyCache runs (`EMBYCACHE_PROGRESS` in RAM, speed = the median of the last 10 min, ETA per user until their last file, «Stop after this file»). «Ready on the pool» (`embyCacheStats()`, `poolView()` pure), «What lies where» (the gather's `sizes`, «Measure sizes…» = the gather's mode `measure`). Taking over an earlier install (`import_preview`/`import_apply`: the user types the folder, Jack never searches; only `EMBY_IMPORT_FILES` read; the preview never carries an API key; copies `<file>.before-import-<time>` first). Let go: both schedules off always, a run going finishes, the tick «bring the prepared films back» hands EmbyCache's `--release` to atd, Mover Tuning's cfg put back as his note says. «Last run» from his list of runs, never status.json. Tiles (tiles v2, start «Pool», `emby.tile`): Last run (a filter tile: EmbyCache's runs only), Schedules, Pool, Shares, Consolidate folders; his setup's steps are tiles too (`emby.setup.tile`). Credit to helmi1987 under the tools' line; a share of the tips goes to him. Tests: `testEmby*` (`testEmbyRsync`, `testEmbyMover`, `testEmbyProgress`, `testEmbyPool`, `testEmbySizes`, `testEmbyImport`, `testEmbyLetGo`, `testEmbyUpstream`, `testEmbyLastRunDuringRun`). |
| `moverelli` | Ms. Moverelli / Frau Moverelli | **In training** (desk.json `training: true` — nobody can hire her yet; texts ×5). Completes Unraid's mover with helmi1987's Smart Mover (`smartmover/custommover_run.sh`, office hooks `CUSTOMMOVER_*` listed in smartmover/README.md; she is the setup, no setup_custommover.sh): per share files by age, exclude lists and the pool's fill, piped to Unraid's own move binary; prefer shares allowed, no sleep gate. Unraid 7.3.2+ (`moverelliFit()`). agent/desks/moverelli.php, data `data/moverelli` (smart_mover.ini written by her, every value checked — `moverelliCheck()`; her lists `excludes/*.txt`; a new file + rename each). **Real runs only while no other mover has a schedule of its own** (`moverelliRunCheck()`: Unraid's own «Disabled» — her page switches it off through Jack's `embyMoverOff()` —, Mover Tuning without an active cron line; other scripts' mover calls are named, never refused or changed — `officeForeignCalls()`); never beside Unraid's mover at work (waits on schedule, then `skipped`); while Jack is hired her ini keeps EmbyCache's list out for his shares (`moverelli_emby_list`, his third way). A cfg whose share is gone is none of hers (`officeShareExists()`; Smart Mover skips it with an INFO line — a `USO:` change). Credit to helmi1987. Tests: `testMoverelli*`, `testStaleShares`. |
| `logs` | Ms. Protocolli / Frau Protokolli | reads logs out loud (fixed source list in agent/desks/logs.php, ids only, never paths; `backup:latest` = the engine's latest.log; `partner:door*`; routers' files `router:<sender>` from Unraid's syslog server folder, only while the share's disks are awake); tail/follow by file offset, docker logs and dmesg re-read; lines go into the page as text only. Nothing is read until a log is chosen. Read only. Tour (`job logs-tour` in the background, `data/logs-tour.json`): /var/log fill and biggest files, container log sizes plus Docker's rotation, errors and warnings per source since the last tour (`logsNormalize` groups similar lines; the page's colouring uses the same patterns). Check `varlog`: /var/log > 60 % recommended, > 80 % required. Tiles (tiles v2, `logs.tile`, no start): «/var/log», «Container logs», «Errors & warnings» (chips per loud log in its `Office.filterBar`, never kept), «Read out» (the reader, built once per visit, keeps its scroll and field; `#/logs/read/<source>`); rows of /var/log and the containers open the reader (`.lg-readable`). Tests: `testLogs*`, `testLogsPartner`. |
| `watchman` | The Night Watchman / Der Nachtwächter | calm, factual, few words; the office's security department — **no other desk gives security advice**. Read only, never a command that changes anything. Two parts: **posture tips** (`watchmanPosture()`, RAM and flash only: the flash exported, public shares, Telnet, UPnP, remote access, FTP, an API key that can do everything, CPU mitigations off, privileged containers that run or auto-start; advice, never in the book or notified; «I know, thanks» kept per signature + text hash) and **the watch book** — what is DIFFERENT from normal, baseline at his first round after hiring, then `job watchman-round` every 5 min (merge under a book lock): logins from new addresses and failure bursts (syslog by offset/inode), container rights as docker run flags, plugins, the flash (go, extra, users, shadow hashes, authorized_keys), shares newly public, scheduled things (crontabs, cron files, User Scripts, atd's queue — Unraid's own, a plugin's own and User Scripts' jobs noted by himself), notification agents; the office's own installs (the Consultant's root-only record) and Mr. Restori's drill throwaways adopted, never told. **Data flow** (`watchmanFlowLook()`: per client and service via `ss -tin`, SMB sessions, containers' namespaces, ZFS `written` per share — `flow_client`, `flow_container`, `flow_written`, `flow_gone`; learned 7 days; who wrote it by the datasets below; the engine's lock and media servers are the office's own / never told). **Snapshots that vanish** (`snap_gone`, `snap_hold_released`; Ms. Snapshotini's root-only record `deletes.jsonl` and the engine's retention are no news). **The host** (`log_cleared`, `user_ram`, `listen_new`, `proc_odd`, `door_new` from the flash's settings incl. Unraid API's doors, `api_key_new/changed` from the keys' files — only id, name, roles, a permissions fingerprint; never the key). **Partner doors** (`agent/lib/partnerlook.php`: the office's own line noted, `door_changed`, `door_key_moved`, `door_refused`). **The network** (`agent/lib/watchnet.php`, read only: the router sends to Unraid's syslog server, UniFi CEF/netfilter and MikroTik RouterOS 7 lines parsed from the share's files; kinds `net_*`; **the privacy rule**: another client's address, MAC or name only in `net_new_device`/`net_spoof`, everything else carries only the server's addresses — asserted over every entry in `testWatchmanNet`). **Why a parity check runs** (`agent/lib/paritywhy.php`: unclean stop / tuning / schedule / manual, from this boot's syslog, parity-checks.log and the diagnostics zip; one entry per `sbSynced`, a notification for an unclean one, the Team Lead's `parity_unclean`/`parity_crash`). Chains (`watchmanChains()`: a way in plus something else within an hour → one notification, a callout), every kind has a MITRE ATT&CK technique (`WATCH_ATTACK`), a SIEM line per entry when switched on (`logger -t uso-watchman`), Grafana links where the Consultant provisioned the dashboard. **Night shift** (`watchmanNightRound()` while the array isn't started; book and state in RAM `WATCH_NIGHT_DIR`; mirror RAM always, flash only when changed and hourly — no passwords, no key values; handover into the day's book). The array's and the server's own lines (`array_stop/start`, `server_boot`) noted by himself. Tiles (tiles v2, start «Watch book», `watchman.tile`): Watch book, Not noted yet (a filter tile), How secure does it stand?, Keeping an eye on; «The last round» is the row's head; the book folds entries of one kind and day (`bookView()` pure, `tests/watchbook.js`), its bar `Office.filterBar` (`watchman.book`, area pick kept), «What I keep an eye on» has its own (`tests/watchfilter.js`). Tests: `testWatchman*`, `testWatchBookView`, `testParityWhy`, `testWatchmanNet*`. |
| `cleanup` | Ms. Dustdevil / Frau Putzteufel | knows every corner of the server and clears away what nobody uses — two pages as tabs: **«Where is what»** (`#/cleanup/where`; agent/lib/where.php, the `wa…` functions; state `data/cleanup-where.json`, du sizes in a background job; her operational advice «If I were you …» — system shares on the array, the mover, exclusive shares, spin-down, parity, UPS, syslog, cron lines of removed plugins, Windows VMs without a guest agent, sparse VM disks, virtio-net, «Migratable» — never security, that is the watchman's; a disk being built shows «parity being built · 81 %» instead of red; read only) and **«Tidying up»** (`#/cleanup/tidy`: Docker templates, Compose stacks, appdata folders, stray my-*.xml, what deleted VMs left, switched-off User Scripts, Docker's leftovers, Docker volumes — copied into the storeroom first, `clVolumeAway()` —, what a drill or a restore left (only what his journals name), what ended partners left, stale share .cfg files on the flash — room «On the flash»). **Never deletes right away:** renames into the hidden `.UnraidSecretaryOffice-trash` on the same filesystem (datasets with `zfs rename`; the old `_…-trash` name is read for good and migrated once, `clTrashMigrate()`), `manifest.json` per run, put back or empty; Docker's images and build cache can only be removed; nothing while a backup runs; something still in use can't be chosen (`CL_IN_USE_LOCKED`). Missing container pictures: found like Unraid does, logos from the CA feed and `CL_ICON_TABLE` via jsDelivr, uploads ≤ 256×256 into the plugin's `icons/`, the old value to the storeroom. A dataset's size is ZFS's `used` with its snapshots (`clZfsSpace()`). Tiles (tiles v2, two switchers): «Where is what» = 13 number tiles (Advice, Where things are, eleven corners; start «Where things are», `cleanup.where.tile`, one `Office.filterBar` over the corners), «Tidying up» = her rooms (`cleanup.section`, nothing chosen = every room); her findings' links open their tile with `sw.pick(id, 'route')`; nothing on her page jumps of its own. Tests: `testCleanup*`, `testWhere*`, `testIconStacks`, `testHiddenStoreroom`, `testStaleShares`. |
| `advisor` | The Consultant / Der Berater | an external: whether the tools the office relies on are there (Fix Common Problems, Files Viewer, a Kopia container, Stream Viewer where a media server runs, Mover Tuning optional, unbalanced never suggested), what they are good for, how to install them by hand (`ADVISOR_EXTERNALS` in agent/desks/advisor.php, `EXTERNALS` in his desk.js); optional monitoring Node Exporter → Prometheus → Grafana (he provisions the office's dashboard where nothing is and keeps only that file current, `advisorDashboardKeep()`). **Installs:** plugins with Unraid's `plugin install <url>` as an atd job (URLs pinned); containers through Unraid's own Add Container form with his template filled into `RUN_DIR/templates/` (`xmlTemplate=default:<file>`, label `uso.installed-by=consultant`) — the user clicks Apply; refused while a container of that kind or name exists; what he installs goes into his root-only record for the watchman (`advisorRecord()`). **Kopia:** `/uso` (ro,slave) and `/uso-restore` (rw), PUID/PGID 0; the repository assistant takes keys and password only after an explicit confirmation, through RAM, to `docker exec -i` on Kopia's stdin, keeps only non-secret facts; the recovery sheet is made in the browser only. Before a new S3 repository he probes the bucket's Object Lock (`advisorObjectLock()`: one signed GET, HTTPS only) and offers COMPLIANCE for N days; guides «Ransomware protection with Object Lock», «versitygw», «Encrypted Kopia to a partner's server». He never logs into a web UI or an HTTP API — files and command lines only. **The network:** a setting he explains and never changes (`syslogserver`: Unraid's syslog server for the router, a share of its own on a pool that never spins down) and the guides `unifi`, `mikrotik`, `neighbours` (opening one posts `network_seen`). Tests: `testAdvisor*`. |
| `restore` | Mr. Restori / Herr Restori | brings apps and VMs back — never over something still there without asking, everything he replaces is put aside first, never deleted. Reads the packages himself (`rsPackages`); per app/VM what comes back from the package, earlier backups from the backup place's snapshots, local snapshots and Kopia; «Databases» = every dump and SQLite copy per app. **Every restore is planned against a fresh look** (`rsPlan` → preview with steps, what goes aside where, sizes), confirmed with the plan's token (`rsStart`), run by atd as `job restore <id>` (`rsJob`) under the engine's `state/lock` (`{holder: restore}`; busy → `refused`), journaled in `data/restore/<id>/` (root only), stops at the first failed step, each step records its undo — «Put back» runs those newest first and is only the undo. Kinds: `db` (safety dump first; Postgres the fresh way when the cluster folder can be put aside, Immich only so; credentials only as variable names), `sqlite`, `files` (a folder or whole share from a *moment* — snapshots grouped by run or name over all a share's parts; copy to `<name>.restored-<time>` or swap, «put in place» when empty; a sleeping part needs «wake»; never a folder holding a running VM's disks; a missing share blocks — Unraid creates shares, never he), `config` (templates/compose aside on the flash, never starts containers), `vm` (XML, NVRAM, TPM while shut off), `kopia` (into the container's *restore* mapping), `partner` (a pull from a partner's door into a NEW dataset beside, never over; resumable; its undo `drop` only his own names), `partner_start` (below). A Nextcloud's config folder swapped back ends with `maintenance:mode --off` in the container. He lists only awake ZFS pools and keeps what he last saw of a sleeping one (`data/restore-zfs.json`). **The drill** (`agent/desks/restore-drill.php`, `public/desks/restore/drill.js`, tile «Drill»): proves on throwaway objects that the backups come back and writes a certificate (`data/restore-drill.json`, `interface: 1`) — packages L1, dumps L2 in a throwaway container built only by `drillContainerArgs()` and checked by `drillArgsSafe()` (`--network none`, no ports/binds/volumes/caps, tmpfs, RAM budget 25 % ≤ 8 GB — too big = «not checked», never failed), dumps from Kopia, SQLite integrity, VM disk headers, Kopia list/verify/sample within `kopia_mb`; refused while a parity check runs or the deadline is near; monthly/weekly on its own after a good run between 00:00 and 07:00; follow-up drills cover what was not checked; a failed drill = one warning. **Across servers** (`agent/desks/restore-partner.php`): tiles «At <partner>» (what a partner keeps of mine — the door's `list`, asked by `job restore-partner-look` from his tick, never from a page request; `rspTick()` looks at once after a run with `partner_ok > 0`), «Kept for <partner>» (`rspKept()`: what this office keeps for a partner, per unit its moments) with **«Start here…»** = a preflight that names the target and every conflict first: `pull_local` (`zfs send | pv | zfs recv` into `<pool>/UnraidSecretaryOffice-restored/<unit>.from-<partner>-<time>` on the same pool — an independent copy, never a `zfs clone`), mount read-only under `/mnt/addons/UnraidSecretaryOffice/restored/`, rsync into the share the user created (missing → blocker `restore_partner_share_needed`) / `domains` / `appdata`, then `start_from` from the copy's `.uso-backup/` (a VM: `virsh define` with the disk paths rewritten, nvram/TPM only where nothing is; an app: its template into templates-user, dumps named — he never starts a container; without a package a warning); a share copy is scanned one level deep; conflicts → «Cancel» / «Beside as …»; «Put back» undoes only what the start made. He can be hired alone on a receiver (`partner_kept`). Tiles (tiles v2, start «Apps», `restore.section`, `wants` for a partner's tile); one `Office.filterBar` per list, never kept. Tests: `testRestore*`, `testRestoreDrill`, `testRestorePartner`, `testPartnerStart`, `testPartnerTicket`, `testRestoreNextcloud`. |
| `caretaker` | The Team Lead / Der Teamchef (the id stays `caretaker`) | leads the team: hires, fires, suggests whom to hire («Hire together with <name>» where desk.json says `with`), collects every desk's `checks` and the office's own (`api_down`: Unraid's API service over its socket; `network_gone`/`network_recreated`: a container pointing at a network Docker no longer has; `unraid_tested`; the syslog server's checks for the router — `watchnetChecks()`; `parity_unclean`/`parity_crash` from the watchman's verdict), tells the user what is left to do, reports new red findings to Unraid's notifications. «I know, thanks» puts aside recommended findings and hints — never musts — in `data/caretaker/acks.json`, keyed by desk:id:hash(level, params without `days`/`size`, the English texts); params that grow on their own must be named `days`/`size`. **Partner offices are his** (`public/desks/caretaker/partner.js`, `agent/lib/partner.php`; actions `partner_add|accept|finish|end|ping|state`, the job `partner-ping`, the finding `partner_silent`; restore tickets `partner_ticket_*`): **the Team Lead only pairs** — the receiver's consent is the pairing (pool, quota, retention; no units, no window; its door always wakes the pool — `wake` is written true, never asked), what goes is the level in Mr. Backupsy's setup; a pairing is allowed at any time, the card and dialogs say per side what is missing (`partnerReady()`, `needLine()`); the cards say «{host} sends to {name}: …» / «{host} keeps of {name}: …» and are drawn anew after every pairing step, with a tour right after. Tiles (tiles v2, `caretaker.tile`, no start): Team, Office (the version, an update), Still to do, Reports, Partners, In place (routes `#/caretaker/<team|office|todo|notify|partner|done>` — other desks' deep links use them); one words-only `Office.filterBar` under «Still to do». Tests: `testCaretaker*`, `testHireWith`, `testAckContent`, `testPartner*`. |

Desks know each other only through shared libraries (`backupProtection()`, `finding()`, `partnerlook.php`) and links
(`#/<desk>`) — each one must work on its own.

**Partner offices** — two offices keep copies of each other's engine snapshots (ZFS datasets only: shares that are a
dataset of their own, VMs with one, the backup place's), each with a retention of its own; partners, never a master, never
automatic failover. **The pairing** (`agent/lib/partner.php`): «Add a partner…» makes the pair's id (8 hex), its key
(ed25519, private key under `/boot/config/plugins/unraid-secretary-office/partners/<id>.key` 0600) and BLOCK-A; «Accept a
partner…» sets the receiver's rules and answers with BLOCK-B and the SAFETY CODE (`partnerSafetyCode()`: sha256 of both
pair keys and both host-key sets); «Paste the partner's answer» compares the code and stores the pair. Blocks are validated
field by field (`partnerBlockDecode()`). **The pairs file** `data/partner/pairs.json` (0700/0600 root, `writeAtomic()`,
read back only in exactly its shape — `partnerPairValid()`: `send {rate_mbit}`, `receive {pool, quota_gb, retention,
wake}` — `wake` written true since 1.60.2 and read as ignored, kept for an office up to 1.60.1; older shapes are read and stripped, the migration step `partner-simple` keeps the units sent so far in
`migrated.json` for Mr. Backupsy's draft); the partner's host keys pinned in `partners/<id>.known`. **The line** in
`/boot/config/ssh/root/authorized_keys`: `restrict,from="<IP>",command="…/partner-door.sh <id>" ssh-ed25519 …
uso-partner:<id>` — only lines with that comment are ever written or removed, every other byte stays, never through a
link (`partnerAuthKeysEdit()`); missing or changed → «door closed/changed», never rewritten unasked. **The door**
(`plugin/scripts/partner-door.sh` → `agent/partner-door.php`, root, cwd /, `env -i`): `SSH_ORIGINAL_COMMAND` word by word,
verbs `ping` (RAM and flash only), `status`, `quota`, `list <unit>`, `resume <unit>`, `offer` (kept for old senders), `recv
<unit> <snap> [<from>|-t]` (any valid unit of the pair up to the quota and `PARTNER_UNITS_MAX`, a sleeping pool woken by the receive — never refused since 1.60.2, the
chain checked — a full stream onto an existing unit puts the old dataset aside `.old-<time>`, never destroyed; `mbuffer` →
`zfs recv -s -u` into `<pool>/UnraidSecretaryOffice-partners/<id>/<unit>`, `mountpoint=legacy canmount=noauto readonly=on`,
never `-F`, never mounted; afterwards the receiver's retention, `received/<id>.json`), `send-back <unit> <snap> [<from>]` /
`-t <token>` (the way back for Mr. Restori's pulls). Refusals in `data/partner/door.log` and `RUN_DIR/partner/refused-<id>.json`
(the watchman's `door_refused`). **The mutual watch:** `job partner-ping` every 15 min; a partner unheard for 6 h is
`partner_silent`. **Hooks:** `agent.sh array stopping` → `partner_release`; the .plg's remove takes the `uso-partner:` and
`uso-ticket:` lines out and `partners/` off the flash; the copies stay. **Restore tickets:** a gone server's copies to a NEW
server — BLOCK-N from the new server, the holder's card writes `tickets.json` and a time-limited `uso-ticket:<id>` line,
BLOCK-T back; a ticket pair never sends or receives and is never in pairs.json; expired tickets go by themselves
(`partnerTicketsTidy()`). What the other desks do with the files is read-only (`agent/lib/partnerlook.php`). Tests:
`testPartnerPairing`, `testPartnerDoor`, `testPartnerWatch`, `testPartnerRelease`, `testPartnerSendBack`, `testPartnerTicket`,
`testPartnerUnits`, `testPartnerTolerant`, `testWatchmanPartner`.

**Staff:** a fresh office has only the Team Lead (desk.json `"always": true`). Every other desk declares in the agent whether
it suits the server (`'fit' => fn () => fit(bool, why, params)`, texts `fit.<why>`, told by the Team Lead). Hiring
(`office.hire`, src/staff.php → data/office/staff.json) shows the desk in the tabs and at the reception, firing hides it
— data and whatever it set up stay (`fire_note` says what keeps running). Unhired desks get no write actions
(`not_hired`): refused by the web side and again at the agent's dispatch (`agentDeskMayAct()`, `AGENT_UNHIRED_ACTIONS`;
`testAgentHired`). **Order:** «Change the order» at the reception (`arrangeable()`, ▲/▼, saved at once as staff.json
`"order"`; `officeStaffOrderOf()` = the `always` desks, then that list, then desk.json's `order`); the Dashboard tile keeps
its fixed rows. After hiring, the tip jar (core.js `tipJar`) says hello — a share of the tips goes to helmi1987
(`office.tip_credit`), what goes beyond the work's cost to animal shelters.

**Supporter key** (no licence, no paywall, nothing ever locked — a thank-you for a tip that only silences the reminders;
`src/supporter.php`): server ID = sha256("uso-supporter:" + upper(regGUID, else flashGUID)), first 16 hex as
`XXXX-XXXX-XXXX-XXXX` (the GUID never leaves the server); key `USO1.<b64url(payload)>.<b64url(DER ECDSA P-256/SHA-256)>`,
payload `{"v":1,"id","name","date"[,"l"]}`, checked with `openssl_verify()` against `OFFICE_SUPPORTER_PUBLIC_KEY`; kept in
`data/office/supporter.json` (0600; several keys, at most 20, the page never gets a key, only a `ref`); levels `l` coffee ·
round · cake · raise (`Office.supporterLevel()`; not a rank). Without a key: his one ask under «The team» 7 days after
`first_seen`, never a modal. Keys come from the support page (`OFFICE_SUPPORT_URL` in src/place.php, `?id=&lang=#claim=`)
by a one-time code the agent asks for (`office.supporter_claim`, agent/lib/supporter.php — never the browser) or by hand:
`tools/supporter-key.sh` (the private key lives only on the maintainer's Mac, `~/.config/uso-supporter/`).

**Reports** («Report a problem or a wish…», German «die Rückmeldung»; `agent/lib/report.php`, `Office.reportDialog()`;
only with an inbox, `CONFIG.report`): **never a request without the user's click on «Send»** — the preview keeps exactly
what goes in RAM (`RUN_DIR/report/<token>.json`, 10 min) and the send takes only that; **the scrubber** (`reportScrub()`)
over every log line and error param (secrets, mails, paths by structure, server, partners, users, addresses, MACs, tokens,
UUIDs; the user's own words never scrubbed, only hinted at); caps `REPORT_CAP_DAY` (25) from `data/office/reports.json`;
the inbox `OFFICE_FEEDBACK_URL` (src/place.php; `FEEDBACK_URL` in the .cfg for tests), curl through `hostNet()`, https
only, the Worker answers by `error` codes; the report ID `sha256("uso-report:" + GUID)` — never the supporter ID. A desk's
log lines come by its labels (`REPORT_LOG_LABELS`; `testReport` greps every `logLine()`). «Where a report stands» asks the
Worker once an hour for the newest 10 (`X-Office` = the report ID) — the page never gets the private inbox's number or
link, only a public issue of `dropnook/UnraidSecretaryOffice`. Up to 3 pictures (stashed 0600 in the RAM inbox, drawn anew
by `src/reportimage.php` without metadata, ≤ 2560 px, the required tick «nothing private on them»). Tests: `testReport*`.

**Pictures:** every desk has its own drawing `public/desks/<id>/avatar.svg` (64×64, flat, thick shapes; the reception's
`assets/reception.svg` is a desk bell, the plugin icon that bell with a «USO» plate, its PNG rendered on the Mac with
NSImage). `Office.deskIcon(id)` / `Office.avatar(id)` show it; desk.json's `icon` emoji is only the fallback; a desk may
change it with its state (`Office.setDeskMood(id, mood)` → `avatar-<mood>.svg`). Never an emoji where a drawing exists.

**Characters:** Frau Snapshotini (Italian), Herr Backupsi (scatterbrained, anxious, checks everything three times), Jack
Emby (the intern), Frau Protokolli (reads everything out, understands nothing), Frau Putzteufel (sees dust everywhere, but
never throws anything away at once — «man weiss ja nie» — and knows every corner of the server), der Teamchef (plain and
friendly, knows his people), Herr Restori (calm restorer in white gloves, «Piano, piano», apprentice with Herr Backupsi),
der Berater (an external, consultant speak: «quick win», «best practice», the hour runs anyway), Frau Moverelli (in
training). Italian: Signora Snapshotini, Signor Backupsi, Jack Emby (lo stagista), Signora Protocolli, Signora Spolverina,
Il capoufficio, Signor Restori, Il consulente, Signora Moverelli («tu»). French / Spanish: Madame / Señora Snapshotini,
Monsieur / Señor Backupsi, Madame Protocolli / Señora Protocoli, Madame Plumeau / Señora Plumero, Le chef d'équipe / El
jefe de equipo, Le consultant / El consultor, Monsieur / Señor Restori, Jack Emby le stagiaire / el becario, Madame /
Señora Moverelli (tu / tú). Greetings are lang keys `greet.1…n` (`Office.greet` / `Office.withGreeting`); chatty bubbles
may carry character, warnings and errors stay plain and clear.

## Words (glossary)

The same thing has the same word on every desk and in every text that speaks of it (2026-10-07: «Rundgang»,
not «Runde»; for Mr. Backupsy «Backup», not «Lauf»). Grammar follows the word (de *der* Rundgang, *das* Backup;
it *il* backup).

**No internal script names on the page** (2026-10-09): texts never mention `setup.sh`, `backup.sh` or any other
internal script — the user works in the browser; say what the person does («Herr Backupsi», «das Plugin unter ⟦Plugins⟧
neu installieren», «in der KopiaUI»). `testNoScriptNames` guards it. Engine log lines and README/docs for admins may name them.

| Thing | en | de | it | fr | es | Desk |
|---|---|---|---|---|---|---|
| a desk's look around (button) · its toast | Tour · Tour done | Rundgang · Rundgang fertig | Giro · Giro finito | Tournée · Tournée terminée | Ronda · Ronda terminada | every desk (Mr. Restori's too) |
| the watchman's round (every 5 min) | round | der Rundgang, die Rundgänge | il giro, i giri | la ronde | la ronda | watchman, office |
| his button · his section | A round now · The last round | Jetzt Rundgang machen · Der letzte Rundgang | Un giro adesso · L'ultimo giro | Une ronde maintenant · La dernière ronde | Una ronda ahora · La última ronda | watchman |
| his book | watch book | das Wachbuch | il registro di guardia | la main courante | el libro de guardia | watchman, advisor |
| while the array is stopped | night shift | die Nachtschicht | il turno di notte | le service de nuit | el turno de noche | watchman, office |
| a backup run | run | das Backup, die Backups (the verb stays sichern, gesichert) | il backup, i backup | la sauvegarde | la copia de seguridad (short: la copia) | backup and all who speak of it |
| … in use | Start a run · last / next run · Stop the run · stopped | Backup starten · das letzte / nächste Backup · Backup abbrechen · abgebrochen | Avvia un backup · l'ultimo / il prossimo backup · Interrompi il backup · interrotto | Lancer une sauvegarde · la dernière / prochaine sauvegarde · Arrêter la sauvegarde · arrêtée | Iniciar una copia · la última / próxima copia · Detener la copia · detenida | backup, office (Dashboard) |
| the nightly one (only where it really is the night: the default schedule in setup) · the role and the drill never say «nightly» (the role: «the backup»; the drill: «at night between 00:00 and 07:00, right after a backup that went well») · any other time | the nightly backup · the scheduled backup | das nächtliche Backup · das geplante Backup | il backup notturno · il backup pianificato | la sauvegarde nocturne · la sauvegarde planifiée | la copia nocturna · la copia programada | backup, advisor, caretaker |
| one backup run or what it left — never «a night» | a backup, a run · earlier backups · {nights} backups in a row | das Backup · frühere Stände (a point in time) · {nights} Backups in Folge | il backup · i backup precedenti · {nights} backup di fila | la sauvegarde · les sauvegardes précédentes · {nights} sauvegardes d'affilée | la copia · las copias anteriores · {nights} copias seguidas | backup, restore, advisor, caretaker, snapshot |
| the check (mode `check`, the «Tour» button) | tour | der Rundgang | il giro | la tournée | la ronda | backup, restore |
| dry run | dry run | der Probelauf | la prova a secco | l'essai à blanc | la simulación | backup, restore, emby |
| backup.sh and co. | the (backup) engine | die (Backup-)Engine | il motore (di backup) | le moteur (de sauvegarde) | el motor (de copias) | backup, restore, watchman |
| where packages go | backup place | die Backup-Ablage | il deposito dei backup | l'emplacement des sauvegardes | el lugar de las copias | backup, restore, watchman |
| per app / VM | package | das Paket | il pacchetto | le paquet | el paquete | backup, restore |
| ZFS/btrfs/VM | snapshot | der Snapshot | lo snapshot | le snapshot | la instantánea | all |
| where a backup lies — levels, summaries, chips, tiles, bubbles, notifications, help about the protection (2026-10-10: Kopia is the tool, not the place) · the levels (2026-10-12: «off» instead of «not», the two partner levels with a pair) · the Grundlagen switch | offsite · off / local / local + partner / local + offsite / local + partner + offsite · Back up offsite (with Kopia) | ausser Haus · aus / lokal / lokal + Partner / lokal + ausser Haus / lokal + Partner + ausser Haus · Ausser Haus sichern (mit Kopia) | fuori sede · off / locale / locale + partner / locale + fuori sede / locale + partner + fuori sede · Backup fuori sede (con Kopia) | hors site · off / local / local + partenaire / local + hors site / local + partenaire + hors site · Sauvegarder hors site (avec Kopia) | fuera de casa · off / local / local + socio / local + fuera de casa / local + socio + fuera de casa · Copiar fuera de casa (con Kopia) | all |
| the tool itself — its container, repository/storage, KopiaUI, policies (retention, ignore rules, compression), sources, its errors, the Consultant installing it, the recovery sheet; the first time on a page it says what it is: «offsite (encrypted with Kopia: S3, Backblaze B2, SFTP, WebDAV, a NAS …)». Partner offices are offsite too but keep «to the partner»; a text that sums up where copies go says «offsite: in your storage (S3, SFTP …) or to your partner server». Internal keys and values (`mode = kopia`, JSON fields, CSS classes, engine output) stay | Kopia | Kopia | Kopia | Kopia | Kopia | backup, restore, advisor |
| the office (the whole) | the office | das Sekretariat (not Büro) | la segreteria (not ufficio) | le secrétariat (a *bureau* is a desk) | la secretaría (not oficina) | all |
| the magnifier's palette · what it finds | the search · place | die Suche · der Ort | la ricerca · il luogo | la recherche · l'endroit | la búsqueda · el lugar | office |
| the agent | the messenger | der Hausbote | il fattorino | le coursier | el mensajero | all; only the office's help adds «(agent)» |
| Mr. Backupsy's setup | the setup · «Set up…» · Apply | die Einrichtung · «Einrichten…» · Übernehmen | la configurazione · «Configura…» · Applica | la configuration · «Configurer…» · Appliquer | la configuración · «Configurar…» · Aplicar | backup and all who point to it |
| what the setup fills in for new things (`preset_new`) · its scope | the default · Apply to everything now / Only to what is new — my settings stay | die Vorgabe · Auf alles anwenden / Nur auf Neues — meine Einstellungen bleiben | l'impostazione predefinita · Applica a tutto adesso / Solo a ciò che è nuovo — le mie impostazioni restano | le réglage par défaut · Appliquer à tout maintenant / Seulement à ce qui est nouveau — mes réglages restent | el ajuste predeterminado · Aplicar a todo ahora / Solo a lo nuevo — mis ajustes se quedan | backup |
| a restore | restore, bring back | die Rückholung, zurückholen (a dump: zurückspielen) | il ripristino | la restauration | la restauración | restore and all who speak of it |
| Mr. Restori's drill · its button | drill (restore drill) · Practise now… | die Übung (Rückhol-Übung) · Jetzt üben… | l'esercitazione · Esercitati ora… | l'exercice · S'exercer maintenant… | el simulacro · Practicar ahora… | restore, backup, office (Dashboard) |
| what a drill writes | certificate | das Zeugnis | il certificato | l'attestation | el certificado | restore, backup |
| what it practises on | throwaway (container) | der Wegwerf-Container | il contenitore usa e getta | le conteneur jetable | el contenedor desechable | restore |
| Jack Emby's run (EmbyCache, the gather) | run · real run · dry run | der Lauf · echter Lauf · Probelauf | l'esecuzione · esecuzione vera · prova a secco | l'exécution · exécution réelle · essai à blanc | la ejecución · ejecución real · simulación | emby, watchman |
| Ms. Snapshotini's plans · a plan's run | schedule · run | der Zeitplan · der Lauf | la pianificazione · l'esecuzione | la planification · l'exécution | la programación · la ejecución | snapshot |
| a ZFS hold | Hold · held · Release hold | Schützen · geschützt · Schutz aufheben | Proteggi · protetto · Togli la protezione | Protéger · protégé · Lever la protection | Proteger · protegida · Quitar la protección | snapshot, watchman |
| the thank-you's levels (tip page, the team lead's plate; the maintainer's words) | a coffee for the team · a round for everyone · cake for the whole office · a pay rise and the donations | ein Kaffee fürs Team · eine Runde für alle · Kuchen fürs ganze Büro (deliberately «Büro») · Lohnerhöhung und Spenden | un caffè per il team · un giro per tutti · una torta per tutta la segreteria · un aumento e le donazioni | un café pour l'équipe · une tournée pour tout le monde · du gâteau pour tout le secrétariat · une augmentation et les dons | un café para el equipo · una ronda para todos · pastel para toda la secretaría · un aumento de sueldo y las donaciones | office, caretaker (a round of drinks — not the watchman's round) |
| an Unraid share | share | der Share (masculine) | la condivisione | le partage | el recurso compartido | all |
| an array/pool disk | disk | die Disk (not Platte) | il disco | le disque | el disco | all |
| /boot | the flash | der Flash | l'unità flash | la flash | el flash | all; a physical USB stick stays a stick |
| a Docker template | template | das Template (not Vorlage) | il template | le modèle | la plantilla | all |
| a log | log | das Protokoll (not Log) | il log | le journal | el registro | all; it *registro* is the watch book |
| Unraid's bell | notifications | die Benachrichtigungen (what a desk sends there: eine Meldung) | le notifiche | les notifications | las notificaciones | all |
| what the user sends the makers · its menu item | report · Report a problem or a wish… | die Rückmeldung (not Meldung: that is Unraid's bell) · Problem oder Wunsch mitteilen… | la segnalazione · Segnala un problema o un desiderio… | le signalement · Signaler un problème ou un souhait… | el comentario · Contar un problema o un deseo… | office, caretaker |
| ZFS dataset · Emby's key | dataset · API key | das Dataset · der API-Schlüssel | il dataset · la chiave API | le dataset · la clé API | el dataset · la clave API | all |
| another office that keeps copies · to pair · its key in authorized_keys · the code compared | partner office (the partner) · pair · the door · safety code | das Partner-Sekretariat (der Partner) · verbinden · die Tür · der Sicherheitscode | la segreteria partner (il partner) · abbinare · la porta · il codice di sicurezza | le secrétariat partenaire (le partenaire) · appairer · la porte · le code de sécurité | la secretaría asociada (el socio) · emparejar · la puerta · el código de seguridad | caretaker, backup, restore, watchman |
| what a partner keeps of mine · pulling it back · a new server's first step · the temporary door | At <partner> · Bring back (from <partner>) · Start from a partner's copy… · (restore) ticket | Beim Partner <name> · Vom Partner zurückholen (die Rückholung) · Von der Kopie eines Partners starten… · das Ticket | Presso <partner> · Recupera (il recupero) · Parti dalla copia di un partner… · il ticket | Chez <partenaire> · Rapatrier (le rapatriement) · Partir de la copie d'un partenaire… · le ticket | En <socio> · Traer (la transferencia) · Empezar desde la copia de un socio… · el ticket | restore, caretaker, watchman |

**Nights (2026-10-09):** a backup can run at any time (one at 01:00, others in the afternoon), so «a night» never
stands for one run or its snapshot/package — the text says «backup» / «Backup» / «Stand» (and it/fr/es their backup word).
«Night» stays where it is literal or a name: the Night Watchman and his night shift, «at night» as a time, «Every day at»
(not «Every night at») for the schedule's time, the drill's window (00:00–07:00, «right after a nightly backup»), Immich's
own nightly dump, Jack Emby's runs; code names (`{nights}`, `asleep_nights`) and the engine's own log
lines and notifications keep theirs.
German «Backup» stays for the whole and for what is kept (im Backup, Backups gehören nicht in appdata, Backup-Ablage,
Backup-Engine; «Eingerichtete Backups»); one run is a «Backup» too. «Probelauf» stays the engine's dry run — Mr. Restori's
drill is «die Übung». «Lauf» / esecuzione / exécution / ejecución stay for
Jack Emby's runs and Ms. Snapshotini's plan runs — they back nothing up, and «Lauf» pairs with «Probelauf».
**Unraid's own words (2026-10-07):** the office speaks the browser's language, Unraid may run in another one. Every
label of Unraid's own UI a text names so the user finds it — menu tabs, Settings/Tools tiles, buttons, form fields and
their values, in paths (`⟦Settings⟧ → ⟦User Utilities⟧ → Fix Common Problems`), quotes («⟦Read Only - Slave⟧») and «the
⟦Main⟧ page» — is a token `⟦English label⟧`, the label exactly as English Unraid shows it, in **all five** languages. It
reads as Unraid shows it in the language Unraid runs in (dynamix.cfg `[display] locale`, `unraid_lang`), whatever the
office speaks: a German office on an English Unraid says «Öffne Settings → User Utilities → Fix Common Problems», on a
German Unraid «Öffne Einstellungen → Benutzer-Dienstprogramme → …». Resolved by core.js `t()` (`CONFIG.unraid_words`),
`officeNotifyText()` and `officeDashT()` through src/words.php (shared with the agent); `⟦ ⟧` never clashes with
`{placeholders}`, a placeholder never goes inside a token. Dictionaries: `public/lang/unraid/<code>.json` (de, it, fr,
es; English needs none), English label → the word of Unraid's language pack (github.com/unraid/lang-de_DE, lang-it_IT,
lang-fr_FR, lang-es_ES; `_meta` names pack and commit), taken from the file of the page where Unraid shows it (Settings
tiles: settings.txt, the Add Container form: docker.txt, the menu bar: translations.txt …) — so it can differ from the
office's own word (Unraid's «Freigaben», «Vorlage» vs. Share, Template) and keeps the pack's quirks (es Key «Licencia»).
A label the pack leaves in English maps to itself (Boot Parameters). Not tokens: labels of other software and of plugins
no pack translates (Fix Common Problems, User Scripts, Compose Manager, Viewers Suite, Emby, Kopia, Grafana), the office's
own words (Mr. Backupsy's «Set up…» → Apply), values Unraid never translates (User templates, the network types bridge/
host) and prose about the Docker page or the Dashboard. **Adding a label:** write `⟦label⟧` in all five languages (the
same labels per text; words around it that still read when the label stays English — «the setting «⟦X⟧»», not an article
glued to it), then add it to each dictionary from that pack — tests/run.php `testUnraidWords` checks every language
against English and every dictionary against the texts (none missing, none unused). Titles in running text are small in
it/es («il signor Restori», «la señora Snapshotini»), capitalised in fr («Monsieur Restori»).


## UI conventions (every desk looks and behaves the same)

* **Working, no need to wait: the wave.** `el('span', 'spin')` (shown inside `.running`) is Unraid's own loading mark,
  true to the original — its nine bars, its colours (#e32929 → #ff8d30), its flip — only smaller (12 px) and slower
  (2.5 s); still under `prefers-reduced-motion`. One mark everywhere: wherever something runs and the user needn't wait
  (a run, a restore, a tour, a scan). Never a circle spinner or a GIF.
* **Inside Unraid:** everything the office shows lives in `#sso` (src/page.php); office.css and every desk.css nest all
  their rules in `#sso{ … }`, so nothing leaks out or in; a reset at the top (`#sso :where(…){all:revert}`) undoes
  Unraid's styles for bare elements. Don't use Unraid's `unapi` class. Dialogs, menus, the selection bar and tips are
  appended to `#sso`, never to `body`. Links into Unraid's own pages open in the same tab. The office speaks the browser's
  language (⋯ → Language per browser, `Office.store` `lang`); Unraid's labels follow Unraid's language («Words»).
  Buttons inside Unraid: its theme's frame, plain letters. Colours are tokens (`--ink`, `--surface` …) mixed from
  Unraid's theme variables, so black, white, azure and gray all work — never a hard-coded colour. `#sso` always carries
  `.in-unraid`.
* **Theme switch** (Auto · Dark · Light at the reception; `public/assets/theme-switch.js/.css`, `Office.theme`; kept per
  browser as `office.theme`): Dark / Light set Unraid's own theme variables on `#sso[data-theme]` (the values of Unraid
  7.3.2's black.css / white.css), so everything mixed from them follows; src/page.php sets the attribute before the
  first paint. Off with `OFFICE_THEME_SWITCH = false` (src/bootstrap.php); `testThemeSwitch` checks the variables against
  Unraid's theme files on the host. **Text size switch** (three «A» radios beside it; `public/assets/size-switch.js/.css`,
  `Office.size`, `office.size`): Medium / Large set CSS `zoom` 1.15 / 1.3 on `#sso[data-size]` (the office's sizes are
  px); core.js `fixedSpace()` turns screen px into the office's px for fixed boxes (the ⋯ menu, tips, the palette), the
  12–13 px secondary lines get a floor (`--floor`, the groups at the end of size-switch.css — a new small prose class
  goes into a group there). Off with `OFFICE_SIZE_SWITCH = false`; `testSizeSwitch`.
* **Show first, then look:** a desk's state comes through `Office.loadState(id, {fresh, part}, took)` (core.js), never
  `Office.api.get({a: 'state'})`: `api.php` answers at once with what is kept (`apiLook()`: `age`, `stale`, `refreshing`
  while the agent looks again after `fastcgi_finish_request()`; one look per state at a time), the page then asks with
  `wait` and hands the new look to `took(j, true)` inside `Office.keepInPlace` once the user is calm (`Office.calm()`;
  fields built once carry `data-keep`); an older answer is dropped. Only the desk shown looks; the reception's cards and
  the badges read `stored`; a card older than 15 min says «As of …». `fresh` (Tour, «Look again») waits for the look.
  **Actions on what a page shows** start with `await Office.freshState(id)` and look the thing up again by id — never
  act on a stale list (`office.stale_refused`). `testApiLook`, `testLookPage`.
* **Head:** `Office.deskHead(desk, {bubble, actions, pages})` — the bubble across the width, inside it the words
  (`.bubble-text`) and at its end the signature (avatar and name, floated right; a phone shows the avatar alone). No role
  line. Then `Office.pageHelp(ID, [[term, text], …])` — «How to read this page», folded by default, remembered per desk;
  explanations of labels, buttons and tiles go there, not into repeated legends. **Two tabs = two pages** (`pages:
  {list, current, store}` → a `.desk-pages` tablist under the bubble; `Office.pages()`, exactly two at most — Mr.
  Backupsy's and Jack's setup, Ms. Dustdevil's two; everything else is a section, a tile); no crumb, no «← Back», no head
  button for the other page. `testDeskPages`.
* **CSS names:** desk classes carry the desk's prefix (`jo-`, `lg-`, `bk-` …); never reuse a name office.css already
  styles (`.empty`, `.card`, `.row`, `.hint` …) for something else, and **never a level or state from data as a bare
  class** (`bk-msg-<level>`). A desk.css grid that overrides office.css's `.kv` or a row's columns brings its own phone
  rule (`@media (max-width:640px)`).
* **Remembered per browser** (`Office.store`, prefix `office.`): choices — chosen section tiles (`<desk>.tile`; a desk
  keeps a key it had: `restore.section`, `cleanup.section`), a corner, a pool, selects (`<key>.pick`), favourites, Ms.
  Dustdevil's last page `cleanup.page`; **never** filter words, «only …» switches or filter tiles. Tests in the browser
  save and restore those keys.
* **Sections:** `Office.sectionHead(title, explanation, ...extras)` — a title bar (Unraid's inside Unraid) with counts,
  status and buttons on its right, the explanation right under it. No explanatory text floating on the right.
* **Tiles — a tile is a section.** The row of tiles is the page's table of contents: each tile stands for one section
  under it and carries its summary; a tile without a section goes; facts without a section go into a section head's
  line or the bubble. **A click shows that section alone** under the row (every other section gone, not folded; a filter
  tile shows its section filtered). **Nothing active = the whole page** in the row's order; the second click on the
  active tile = nothing active; each desk has one start tile or none. The active tile glows (`.tile-on`) and carries a
  mark «Filter on» / «Open» (`.tile-mark`; `aria-pressed` says it). The chosen section is in view after the click (on a
  desktop `scrollIntoView({block: 'nearest'})` only when its head isn't under the row anyway; on a phone the head sticky
  at the top with a chip «◂ All»); `keepInPlace(the row)` first, then that one move. **Hints are no section:** what
  stands above the row (head, bubble, page help, callouts, a live panel) stays at every click, so does a footer. Two
  shapes, one behaviour: the number tile (`.stat`) and the choice tile (`.card`), both real `<button>`s under the
  switcher. **The page says what is filtered in one place:** the filter bar's note line right above the list («The list
  shows only: [VMs ×] [«müller» ×] · 1 of 12 fit — Show all», `bar.done()`), never a sentence in a section head. A tile
  is a place with a sub-route (`#/<desk>/<tile>`) that sets the choice exactly like a click. **A switch inside a section
  that changes what its tile says** (a hide switch, a sort) redraws the row at once: `sw.row()` right after the section's
  own redraw. Phone: number tiles two columns, choice tiles one.
  **The switcher (core.js):** `Office.sections({id, key, grid, tiles, sections, default, onPick(id, how), wants})` → `sw`
  — `tiles` (a list or a function; the row's order = the «nothing active» page's) of `{id, key, tile: (a) =>
  Office.stat|tile({…, ...a}), section: () => node | '<name>', filters}` (the builder spreads the helper's `a`; `section` a
  node holding one `.section-head`, null, or the name of a shared one in `sections`; `filters` = a filter tile's words
  for the note line, never stored; `key` the tile's lang key; `default` the start tile; `onPick(id, how)` before anything
  is drawn — how = click | route | store | all | clear; `wants(sub)` a sub-route of a tile the list has only later).
  `sw.el` (`.sections`: `sw.grid`, `sw.body` `[data-sections-body]` with `data-active`/`data-sections`), `sw.render()`
  (the choice stays, in `keepInPlace(sw.grid)`), `sw.pick(id[, how])` (like a click; `'route'`: no toggle, no move;
  `'start'`: like `'route'` but never kept — a desk's own opening), `sw.active()`, `sw.mount(sub)`, `sw.places()`,
  `sw.note(name)` → `{tile, clear}` for `bar.done()`, `sw.clear()`, `sw.row()`. Every desk is on it (`SECTIONS_DESKS`
  in tests/run.php names them; no `renderTiles`+`renderSection` pair of its own, no `hidden` on a section).
  **The helpers:** `Office.filterBar({key, label, only: {text, hint}, select: {label, all}, extra: [nodes], min,
  onChange(f, what)})` — built once per visit and held by the desk across renders (`data-keep`); `bar.el` (append under
  the list's section head at each render), `bar.read()` → `{words, only, pick, tile}` (match with `Office.fits(words,
  hay)`), `bar.done(n, total, {tile, clear, onlyAvail})` after the list is drawn (shown from `Office.FILTER_MIN` = 10 rows
  or while anything filters), `bar.options([[value, text, n], …])`, `bar.tile(name | '')`, `bar.reset()`, `bar.set({text,
  only, pick})`; `onChange` runs inside `keepInPlace`. Pure, also under node: `Office.fold(text)`, `Office.filterWords()`,
  `Office.fits()`. **Every list's bar is `Office.filterBar`.** `Office.stat({label, value, sub, alert, tip, on, pressed,
  controls, filters, id, place, cls, mark})` → `div.stat` / `button.stat` with `on`; `Office.tile({name, icon, chip,
  count, lines, active, on, controls, filters, tip, id, place, cls, extra, mark})` → `button.card[aria-pressed]`. Tests:
  `testSections`, `testFilterWords`, `testFilterBar`, **`testTilesConvention`** (every `public/desks/*/*.js` read for the
  rule: no `role="button"`, no `scrollIntoView(` without `/* reveal */`, no folding of a desk's own, every filtering tile
  a place, every `Office.store` key free of filter/words/only/query, every desk on the switcher), `tools/ui-clicks.sh`
  (after every tile click: aria-pressed, `.tile-on`, the body its section alone), `bash tools/ui-tiles.sh --only <route>`
  (pictures before/after, 1440 and 390 — how a desk proves its tiles). For contributors: docs/DEVELOPMENT.md «Tiles and
  lists».
* **Search** (the magnifier in the top line, ⌘K / Ctrl+K; the palette `#sso-palette`): it finds **places** — each desk
  lists its own with ONE `Office.places(ID, [{kind, key, route, anchor, text, crumb, shown, part, paras}, …])` (kind
  desk | section | tile | step | setting | help | guide; key = the lang key) and marks them on the page (`{ place:
  '<anchor>' }` among `Office.sectionHead()`'s extras or `Office.place('<anchor>', node)`); every
  `sectionHead/section/sectionBox/setupSection(T('…'))` is a place (or named in the test's `$notPlaces` with why); a
  tile the search opens has a sub-route its `mount(root, sub)` takes. `places.json` beside desk.json lists the same keys
  (`{"keys": […], "texts": […]}`); `api.php?a=places` sends only their words, asked once on the first open, never with
  the first paint or per keystroke. **Items** — what a desk's STATE holds worth finding: ONE provider
  `Office.placesFrom(ID, (state, part) => [{text, sub, route, anchor, words}, …])`, called when a state arrives through
  `Office.loadState()` (idle, ≤ 2 ms, ≤ 200 per state, never a request of its own); **never a raw log line, a secret or a
  path the page doesn't show** (the test greps); **the anchor rule:** every item's anchor is on the page as `data-place`
  (`` Office.place(`<prefix>:${id}`, row) ``) or a row's `data-id`, and what hides it opens through the route
  (`#/restore/<tile>`, `#/cleanup/tidy/<room>`, `#/watchman/entry/<id>` …). **Texts:** a place's explanation and a guide's
  paragraphs (`paras`) are secondary words (English comes from `api.php?a=places&part=text` after the words). `shown: ()
  => boolean` keeps a place not drawn now out; `part` names the part a place lies in so `Office.reveal(anchor, {part})`
  can bring at least that into view. A choice: `Office.goToPlace()` → the route, then `Office.reveal(anchor)` (waits ≤
  10 s, opens `<details>` above it, scrolls under Unraid's menu, marks `.place-hit`, keeps its place for 6 s while the
  page settles). Tests: `testSearchPlaces`, `testSearchItems`, `testSearchGuides`.
* **Rows** with one main action (unfold details, open a log): the whole row is clickable (`.row.unfolds`), except its
  own buttons, links, fields and elements with `data-own`; the «details» tooltip sits on the name only; every chip
  carries its own `title`. Lists that unfold offer «Unfold all». A row with a «Details» / «Less» button unfolds on a
  click of the row too; the name takes Enter / Space (`testSetupUnfold`).
* **Chip explanations:** a chip's `title` (or `data-tip` on anything) becomes a bubble (core.js `initTips`): on hover
  with a mouse, on click or tap everywhere; that click never folds the row under it. Chips that do something themselves
  keep their click and explain on hover only; a button, link or field inside an element with `data-tip` keeps its click.
  `data-tip` is the tooltip's hook only: an element's id goes into `data-id`.
* **Folding never makes the page jump:** wrap every fold/unfold (rows, groups, tiles, «Unfold all», re-renders after a
  toggle) in `Office.keepInPlace(anchor, change)` — the clicked element stays where it is on screen; space that vanished
  below is given back as the user scrolls up. Exception: selection lists (snapshots) — only the checkbox selects.
* **What the user opened stays open:** a desk draws its page anew often (every new look, the messenger's word with
  every answer), so a `<details>` a desk builds goes through its `keepFold(det, key, open)`; rows keep theirs in a set,
  groups and tiles in `Office.store`. `testDetailsKept` finds a bare `el('details', …)`; `tools/ui-clicks.sh` clicks them.
* **Subheadings inside a list** (a compose stack, a group) are a tinted title bar (`var(--surface2)`, like
  `.group-head`), the rows under it slightly indented — never just bold text between rows.
* **A long list stays readable:** fold entries of one kind and day into one row with a count (the watch book's
  `bookView()`, pure under node), day headings, «Show n more»; with filter words the rows unfold to their hits; a deep
  link (`#/watchman/entry/<id>`) clears a filter that hides the entry.
* **Backup protection** is always shown with `Office.backupChip(level)` (offsite / only local / not backed up), the level
  from `backupProtection()` in agent/lib/backupscript.php.
* **Names in German** read naturally (Frau Snapshotini, Herr Backupsi, Der Teamchef); a desk can set its reception
  button with the lang key `visit` («Zum Teamchef»), otherwise the office's «Visit {name}».
* **Say what is really there:** detect it (boot from a stick or a boot pool, licence bound to the stick or the TPM, a VM
  disk on a snapshot overlay) instead of describing the common case. Restore texts must be complete and honest. Docker
  stopped is «Docker isn't running» (`common.docker_off`), never «0 of 0 running»; no VMs is «No VMs»; a missing share
  is «The share X is missing», never a hint at a setting.

## Server facts that bite

* **Which disk a file is on:** `getfattr -n system.LOCATION` (Python
  `os.getxattr`) on a `/mnt/user/…` or `/mnt/user0/…` path gives `disk2`,
  `maple` … (`system.LOCATIONS`: all of them) — no look at other disks.
* A share's split level is `shareSplitLevel` in `/boot/config/shares/<share>.cfg`
  ("top level only": files go to the disk where their folder already exists,
  even an empty one — Jack Emby keeps such folders as signposts).
* On a ZFS pool the top folder only sees its own (nearly empty) dataset:
  for how full the pool is take `zfs list -Hp -o used,avail <pool>`.
* **Disk tunables (Unraid 7.3.3, Settings → Disk Settings; read-only for the office):** `md_write_method` in
  `/boot/config/disk.cfg` (`auto` — the help: «Auto selects read/modify/write» —, `0` read/modify/write, `1` reconstruct
  write; var.ini mirrors it); Squid's «CA Auto Turbo Write Mode» is `ca.turbo.plg`, its switching on only with
  `enabled="yes"` in `/boot/config/plugins/ca.turbo/settings.ini`. `zfs_arc_max` lives in
  `/boot/config/modprobe.d/zfs.conf` (`options zfs zfs_arc_max=<bytes>`): `/etc/rc.d/rc.modules.local` writes 20 % of the
  installed RAM (dmidecode) there at boot when the line is missing and applies it via `/sys/module/zfs/parameters`;
  «Unlimited (Dynamic)» writes 0 (DiskSettings.page also shows a value of the whole RAM so). OpenZFS 2.4.4's own default
  (arc_os.c `arc_default_max()`) is the larger of 5/8 of RAM and RAM − 1 GiB; a value ≥ RAM is ignored, and 0 written at
  runtime changes nothing until the next boot (arc.c `arc_tuning_update()`). Ms. Dustdevil's `waWriteMethod()` /
  `waZfsArc()`: a ZFS tip only for a PCI-passthrough VM that could come too late (2026-10-09: unlimited is right).
* **Exclusive shares** (Settings → Global Share Settings → Permit exclusive shares, `shareUserExclusive` in
  `/boot/config/share.cfg`, changeable only with the array stopped): `/mnt/user/<share>` is then a **symlink**
  `../<pool>/<share>` to `/mnt/<pool>/<share>`, past shfs — for a share whose primary storage is a pool, secondary
  none, and whose top folder exists on that one volume only (an empty folder of that name on another disk or pool is
  enough to prevent it). emhttpd decides at array start; shares.ini says `exclusive="yes|no"`, never why (Ms.
  Dustdevil's `waExclusive()`, agent/lib/where.php). On a large server most pool shares are (appdata, system, domains …). Code that refuses
  links must allow exactly this one — `/mnt/user/<share>` → `../<pool>/<share>` or `/mnt/<pool>/<share>`, the same
  share name, a real folder there: `officeUnraidPath()` in src/place.php, the one helper (the data folder of the agent
  and the web side, the Consultant, Ms. Dustdevil); `realpath()` gives the pool path, `is_dir()` follows the link,
  `lstat()`/`is_link()` don't. Going through `/mnt/user` costs shfs (FUSE) even then: about 50× the pool path per look.

* **shfs and flock (7.3.2, QA 2026-10-08 on a partner test server):** a flock taken through `/mnt/user/…` (FUSE) lies on the disk's
  or pool's file — /proc/locks names that one (`00:28:266`), never the inode stat() shows through /mnt/user. shfs numbers
  its inodes `(st_dev << 48) | st_ino` of the file behind (ZFS and btrfs, on two test servers: 11258999068426506 =
  0x28 << 48 | 266) — `flockHeld()` looks for both. The lock itself still works through shfs (FUSE hands it down).

* **shfs across pools (7.3.2, tried on drop: hazel + moss):** a `rename()` through `/mnt/user/<share>/…` renames the folder
  on every pool and disk that has it (no copy) — put aside and put back work for a folder spread over two pools; a folder made
  through /mnt/user lands on the share's primary storage; for a name on two parts shfs shows the primary's
  (`system.LOCATION`); hard links, owners and user xattrs survive an `rsync -aHX` into /mnt/user. A ZFS dataset of its own is
  renamed with `zfs rename` on its pool, never through shfs. Looking up a path through /mnt/user may touch every disk of the
  share — check `sleepingDisks()` for all of its parts first.
* **A share's storage in its cfg:** `shareUseCache` no = the array, only = the pool `shareCachePool`, yes/prefer = primary
  `shareCachePool` + secondary `shareCachePool2` (empty: the array; the mover goes primary→secondary for yes,
  secondary→primary for prefer) — the array holds no part of a share whose secondary is a pool; `shareFloor` (minimum
  free space) is in KB. emhttp's `/var/local/emhttp/shares.ini` lists every share (`exclusive`), `disks.ini` has `fsFree` (KB)
  per pool and disk without touching it. The engine packs every share's cfg into `<backup place>/server/shares/`.
* **Unraid's web stack:** php-fpm runs as root; nginx guards everything with
  `auth_request` (the login), also `/plugins/…`; `local_prepend.php` (prepended
  to every PHP run, also CLI) chdir's to `/usr/local/emhttp`, sets the time
  zone and ends every POST without the right `csrf_token` (field or header
  `X-CSRF-Token`, value in `/var/local/emhttp/var.ini`) with an empty 200.
  nginx compresses only `.js/.css/.woff` (`gzip off` for `.php`, 7.3.2) and PHP has `zlib.output_compression`
  and `output_buffering` off — so the API compresses its own answers (`apiSend()`, src/api.php, 2026-10-07): ≥ 1 KB
  and `Accept-Encoding: gzip` → gzip level 1 with `Content-Encoding`, `Vary: Accept-Encoding` and the Content-Length
  of what goes out, complete BEFORE `fastcgi_finish_request()` (the stale-state answer of answerThenLook()); −80 %
  of the reception's bytes (876 → 166 KB on a large server), the strings 465 → 155 KB, ≈ 1.5 ms per 300 KB. Every answer
  goes through `answer()`/`answerThenLook()` — never echo JSON elsewhere. Tests: `testApiGzip()` under `php-cgi`.
* **Plugin manager:** `.plg` versions compared with `strcmp`, `min`/`max` with
  `version_compare`; a `Run` script failing (exit ≠ 0) aborts the install.
  Every installed plugin is installed again at each boot (before the array).
  `.page` and `.plg` icons (`*.png`) are looked up in `<plugin>/images/`.
* **Header buttons (7.3.2, `DefaultPageLayout.php`, `Navigation/Main.php`):** a `Menu="Buttons:…"`
  page's body goes through `parse_text()` (`_(…)_`, `:…_help:` lines) and is evaluated inside `<head>`
  of every Unraid page — only `<style>`/`<script>` belong there, kept to a line or two (a
  `<plugin>/sheets/<Page>.css` would be linked on every page too). `Icon=` becomes
  `<b class="fa fa-<icon> system">` (`icon-…`: Unraid's own font; `*.png`: an `<img>` from
  `<plugin>/icons/`, not themed); `Code=` is the glyph the sidebar themes (azure, gray) put in
  `.nav-item.<Page> a:before` (fonts docker-icon, fontawesome, unraid), for Tasks pages too; `Link="<class>"`
  renders only an empty div. Unraid's own header icons are 1em glyphs (12 px in the black/white header,
  16 px in the sidebar) in the header's text colour. Font Awesome 4.7 has no desk bell, so the office's
  button paints the reception's bell as a CSS mask in `currentColor` over its glyph (fallback
  `building-o`). A Tasks page's code runs only on its own page: its sidebar glyph stays a font glyph
  (`f0f7` building-o — never a bell, that's Unraid's notifications); `Tag=` (the title bar) likewise, or a
  PNG from `<plugin>/icons/`. Settings tiles (`Icon=*.png`) come from `<plugin>/images/`.
* **emhttp waits for event scripts** (`<plugin>/event/<event>`, executable):
  a slow one holds up the array start or stop. `started` = end of array
  start, `stopping` = start of array stop.
* `update_cron` builds root's crontab from `dynamix/*.cron` and the `*.cron`
  of **installed** plugins only (`/var/log/plugins/<name>.plg`).
* **Unraid's crond (dcron) reads two crontabs for root:** `/etc/cron.d/root` (what `update_cron` writes, `crontab -c
  /etc/cron.d -`) and root's own `/var/spool/cron/crontabs/root` (what `crontab -l` / `crontab -` use; also other files
  in `/etc/cron.d`, e.g. Files Viewer's). Plugins that write root's own with `crontab -l | … | crontab -` (VM Backup's
  `scripts/commands.sh update_user_script`, older ones) keep whatever is in it: on a large server it once held a full copy of
  the system crontab — every job ran twice, and a stale copy of the office's backup line (`0 2`) would have started a
  second backup. `job.sh` skips a second start of a job in the same minute (logged); the night watchman reports lines
  in both. Never "fix" it by writing root's crontab from the office. atd's queue is `/var/spool/atjobs`; notification
  agents are every file in `/boot/config/plugins/dynamix/notifications/agents/` (run by `notify` as root; disabled ones
  in `agents-disabled/`).
* Scripts edited over SMB may stay open in Samba for a moment ("Text file
  busy"): start them through `bash <script>`, never by executing them directly.
* VM configuration (XML, NVRAM, TPM state) lives inside `libvirt.img`, mounted
  at `/etc/libvirt` while the VM service runs.
* Unraid rebuilds root's crontab in RAM from `*.cron` files (`update_cron`);
  anything typed into `crontab -e` is gone after a reboot.
  `/usr/local/sbin/update_cron`'s first line is `#/bin/bash` (no shebang):
  start it through `bash`.
* **Unraid resets a share's root to `0777 nobody:users`** when share settings
  are saved (emhttpd "Restarting services", logged as `shcmd … chmod 0777`),
  unless the root belongs to the group `users`. Nextcloud then refuses `occ`
  (data folder readable by others). Lasting fix: the root `33:100` (www-data:users)
  with `0750` — the caretaker checks that others can't read it.
* **Unraid 7.3.2: the Dashboard and the Docker page flood `/var/log`** when a
  container has no icon: their fallback `onerror=this.src='/plugins/dynamix.docker.manager/images/question.png'`
  points to a file 7.3.2 doesn't ship and never stops (≈1'000 requests/s,
  nginx error.log + syslog, the 128 MB tmpfs full in minutes). Keep the
  Dashboard open only briefly in tests and watch `df /var/log`. Workaround
  (RAM, gone after a reboot): copy compose.manager's `images/question.png` there.
* `btrfs filesystem show` without arguments reads every device raw (a busy or
  sleeping disk holds it up for minutes): use `--mounted` or a mount point.
* A btrfs snapshot of a disk busy with a large copy can take minutes; bash
  runs a trap (abort) only after the current command — that is the pause then.
* User Scripts may be extended by **User Scripts Enhanced** (categories in
  `/boot/config/plugins/user.scripts.enhanced/categories.json`, by
  `name<folder>`).
  When a User Script last ran is only known since the reboot
  (`/tmp/user.scripts/tmpScripts/<name>/log.txt`, RAM).
* On ZFS pools Unraid makes **each VM folder in `domains` a dataset of its
  own** (and every share is one): `rename()` can't move them — use
  `zfs rename`, and remember a share and its folders are different
  filesystems.
* A pool sleeps when **any** of its disks does (`cache`, `cache2` … in
  `disks.ini`); `disk1` is not `disk10`. **Only a rotating disk is ever asleep for the office** (2026-10-08):
  Unraid puts SATA SSDs into standby too (`spundown="1"` with `rotational="0"`), but an SSD wakes in milliseconds and
  wears nothing worth sparing — `diskAsleep()` (agent/lib/mounts.php) and the engine's `ub_asleep_load` count a disk
  asleep only when it is spun down AND rotates (`rotational` missing = rotating, as before 7.x). So an SSD pool is never
  «asleep», never left out, never «not looked at»; a mixed pool sleeps when one of its HDDs does. Every reader of
  disks.ini goes through those two helpers.
* Images pinned by digest (`image: x@sha256:…`) don't show up in
  `docker image ls`; take the image ids from `docker inspect` of the
  containers. `docker system df` takes seconds — not in a `refresh`.
* Searching a media pool deeper than 3 levels lists every episode (minutes):
  keep searches shallow, prune `*.sparsebundle`, never the array.
* Backups keep copies on purpose (the office's share, anything called
  "backup", e.g. `my-*.xml` in a backup's `templates-user`): never treat
  them as leftovers.
* **Ms. Dustdevil's storeroom:** `.UnraidSecretaryOffice-trash` folders (in
  shares, on the flash, in `/etc/libvirt`) and datasets named
  `.UnraidSecretaryOffice-trash-<stamp>-<name>` hold things put away, with a
  `manifest.json` per run. Other desks list them as what they are (or skip
  them) — never as a share's own folders, and nothing but her writes there.
  **Hidden since issue #7** (2026-10-09; Mr. Restori's set-aside folder too: `.UnraidSecretaryOffice-restore` on the
  flash and in libvirt.img). The old names (`_UnraidSecretaryOffice-trash`, `_UnraidSecretaryOffice-restore`) are read
  everywhere for good — listing, sizes, put back, empty, excludes (agent/lib/util.php `OFFICE_STOREROOM(_OLD)`,
  `OFFICE_ASIDE(_OLD)`, `storeroomName()` for a name, `storeroomIn()` for a path or dataset; the engine's
  `ub_storeroom`) — and never written. `clTrashMigrate()` (from `clScan()`, once per place and agent run, never while
  the engine's lock is held or a job of hers works there, never on a sleeping disk — done when she looks there next):
  only the old folder → renamed (a dataset of its own named like it: `zfs rename` while its mountpoint is inherited);
  both → the old one's runs renamed in one by one (a taken run name gets `-N`, `clTrashAsOk()` lets such a run own the
  datasets named by its time without it; a set-aside time taken in both stays), then the empty old folder removed; any
  failure → logged, the old folder stays in use (`clTrashBoth()` reads both). Datasets put away under the old prefix
  stay as they are (their manifests name them). Older records (his journals' undo steps, manifests' `from`, let-go
  journals) are read through `officeHiddenNow()`: the old path while it is there, else the new name (also where to
  write). Kopia: `[kopia] ignore` carries both rules (setup.sh adds the new one; until it is applied the old rule
  stands for both — `apply_settings`). The watchman's «odd place» rule doesn't count the office's hidden folders
  (`watchmanOddPath()`). `testHiddenStoreroom`.
* **Kopia over S3:** a full maintenance's snapshot GC reads every snapshot's
  directory tree from the bucket — with ~3 TB in 25 snapshots it ran 15 hours
  (~10k contents/h) and then aborted on one download that failed ten times
  ("Transfer aborted"); it never resumes, the next GC starts from the front.
  Never run `maintenance run --full --safety=none` while anything can write
  (no backup, no KopiaUI snapshot), and never drop deleted contents by hand:
  after a GC that saw no snapshots, newer snapshots can reference contents
  still marked deleted — only a complete GC undeletes them. Kopia's own
  schedule (`kopia maintenance info`, owner `user@host`) runs a full cycle every 24 h.
* Kopia's sources are named by the **container path** (`/uso/<share>` for new setups,
  `/backup-snapshots/<share>` on a large server and older installs; the engine reads the real one from the
  container's mapping), not the host path: changing only the Host Path keeps their history; changing
  the Container Path makes new sources (same repository: content deduplicated, but every file read
  once more; the old sources keep their history under the old names until removed in Kopia); a new
  repository (bucket) starts every source with a full upload — no dedup across repositories.
* Unraid's `notify` names a notification `<event>-<second>`: a second one with
  the same event in the same second is dropped — the second its script reads the clock, somewhere in
  our call: `officeNotify()` starts the next one only after the second the last call ended in
  (the night shift lost one of three sent in a row, 2026-10-07) — across processes since 1.31 (a stamp in RAM shared
  with agent.sh and the engine, which all send as «Unraid Secretary Office»). Line breaks in `-m` are a
  literal `\n`; never a real newline in `-d`.
* The plugin manager registers a plugin (`/var/log/plugins` symlink) only after
  its install script: `update_cron` run during a fresh install doesn't pick up
  the plugin's own cron files yet.
* **Container pictures (7.3.2):** Unraid's icon downloader (curl, no
  User-Agent) can't open a bare local path — it needs `file://`; hosts that
  refuse requests without a User-Agent (Wikimedia: 403) never load. Unraid keeps
  the icon path in `docker.json` as long as that file exists — once
  `question.png` exists it never looks again. The Compose Manager's override is
  `<composefile>.override.<ext>` (or the old `docker-compose.override.yml`);
  `labels_view_mode=advanced` means "Manual", hands off. CA's
  `templates_new.json` is PHP-serialized (~30 MB): grep it, never unserialize.
* `/mnt/addons` is **Unassigned Devices'** 1 MB tmpfs (propagation shared), not Unraid's: a fresh
  Unraid without UD has none. `/mnt` itself is RAM with shared propagation, so the office makes a plain
  `/mnt/addons` there (metrics; the engine's `mkdir -p` of its mount root does the same); UD installed
  later mounts its tmpfs over it and the office's folders are made again below. Shared with the
  backup's mount points: the office's metrics files there (`/mnt/addons/UnraidSecretaryOffice/metrics`,
  read by the node exporter's textfile collector) must stay a few KB.
* Unraid's Docker LOG rotation is `DOCKER_LOG_ROTATION/DOCKER_LOG_SIZE/DOCKER_LOG_FILES`
  in `/boot/config/docker.cfg` (dockerd `--log-opt max-size/max-file`); it only
  applies to containers created afterwards — `docker inspect … .HostConfig.LogConfig`
  shows each container's own limit.
* The official Postgres image's first start runs initdb and its init scripts with a temporary
  server on the **socket only**, then restarts: wait for the real one over TCP
  (`psql -h 127.0.0.1 …`), otherwise a dump played in early is cut off. A bind mount is resolved
  when a container starts: stop it, swap the folder (rename), start it — it sees the new one.
* **A Kopia upload's progress** is the `rchar` of its process (`/proc/<pid>/io`, the host sees the process of the engine's
  `docker exec`): it reads the files' own sizes (sparse holes too — a little past `logicalreferenced` for a share; for a VM far past it: a Windows VM's 1.6 TB vdisk holding 21 GB was 2 TB of rchar in 2.7 h on 2026-10-07 while its snapshot said 382 GB — so a VM source is reckoned by its files' apparent size, engine 2.26). Not `wchar` (what it sent:
  half of it for a large server's 2.36 TB on 2026-10-06 — compression, content the repository had), not the files it has open (it
  reads folders in parallel).
* `docker top <container> -eo …` needs `pid` in the list (`-eo pid,args`),
  otherwise Docker only answers "Couldn't find PID field".
* **Data flow without switching anything on:** `ss -tin` shows tcp_info per established socket (`bytes_sent` includes
  retransmissions, `bytes_acked` is what arrived; kernel sockets like nfsd's show too) — the server's ports in `sport`;
  with `state established` there is no State column. A container's traffic is its network namespace's counters,
  `/proc/<pid>/net/dev` of its main process (bridge, macvlan, ipvlan alike — macvlan/ipvlan containers have no host-side
  veth); `--network host` containers share the host's counters. Samba 4.16+ has `smbstatus -b --json` (sessions with
  `username`, `remote_machine`, `hostname` = `ipv4:<ip>:<port>` / `ipv6:[<ip>]:<port>`, `creation_time`). ZFS `written`
  is what was written since the latest snapshot (rewritten blocks count; without a snapshot it equals `referenced`, so
  only growth shows); `snapshots_changed` (OpenZFS 2.2+) is the time of the last snapshot created or destroyed, `-` when
  never — a destroyed newest snapshot makes `written` jump. Unraid has `net.netfilter.nf_conntrack_acct = 0` (no bytes per
  flow in conntrack) and logs no file access (full_audit would flood the 128 MB /var/log).
* **Logins in the syslog (7.3.2):** the WebGUI writes `webgui: Successful login user <name> from <ip>` and `webgui: Unsuccessful login user <name> from <ip>. [Ignoring login attempts for 900 seconds.]` (dynamix/include/.login.php; `<name>` is whatever was typed — maybe a password —, the address is the last ` from `; three failures lock an address for 15 minutes, `/var/log/pwfail/<ip>`); OpenSSH logs as `sshd-session[pid]`, at LogLevel VERBOSE. `/root/.ssh` links to `/boot/config/ssh/root`. Every share's SMB/NFS export and security as applied — user shares, disks, pools and the flash — is in emhttp's `/var/local/emhttp/sec.ini` / `sec_nfs.ini` (shares.ini has none). A plugin's update source is the `pluginURL` attribute of `<PLUGIN>` (entities resolved), not necessarily the entity of that name (Files Viewer points it to `&selfURL;`).

* **Unraid's Add Container form (7.3.2, dockerMan/include/CreateDocker.php):** `/Docker/AddContainer?xmlTemplate=<type>:<file>`
  loads any file PHP can read (`is_file`; the path is cut at `; | & ? =` by `unscript()`); type `default` sets a
  `/config` path to `<DOCKER_APP_CONFIG_PATH>/<Name>` (Community Applications opens its templates this way, from
  `/tmp/community.applications/…`), `user`/`edit` just load. On Apply it writes `templates-user/my-<Name>.xml`
  (an existing one of that name, any case, is overwritten) and **removes an existing container of that name**
  before creating the new one. Path modes it knows: rw, rw,slave, rw,shared, ro, ro,slave, ro,shared — anything
  else is saved as rw (CA's Node-Exporter template says `ro,rslave`: a large server's `/host` became rw). Config
  Type `Label` adds `-l key=value`; a Variable whose Default is `a|b` becomes a select; `Mask="true"` a password
  field; `Required="true"` can't stay empty; Name and Description go into the page as HTML (no `<>`).
  Template updates from `TemplateURL` are switched off in 7.3.2 (DockerClient.php returns early).
* **Kopia (imagegenius image):** the server runs `kopia server start --insecure --address=0.0.0.0:51515
  --htpasswd-file /config/htpasswd` as user abc (= PUID); the htpasswd is made from USERNAME/PASSWORD at the first
  start only (without them the container waits forever). `KOPIA_CONFIG_PATH=/config/repository.config`: the S3 keys
  live in it, the repository password in `repository.config.kopia-password` (KOPIA_PERSIST_CREDENTIALS_ON_CONNECT) —
  that is how the nightly run connects. A server started unconnected doesn't notice a connect from the CLI: restart
  the container. `kopia repository create|connect s3` takes `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` and
  `KOPIA_PASSWORD` from the environment; `kopia repository set-client --username= --hostname=` sets user@host.
* **Kopia and S3 Object Lock:** `--retention-mode COMPLIANCE|GOVERNANCE --retention-period 30d` only at `repository create`
  (kingpin's durations take days; the blob config `kopia.blobcfg` is written first — a bucket without Object Lock refuses that
  PUT, nothing is left); `repository status --json` has `blobRetention.retentionMode` and `retentionPeriod` (nanoseconds);
  `maintenance set --extend-object-locks=true` extends the locks at every full maintenance and needs the period at least a
  day longer than the full interval (default 24 h). Deleted objects stay as noncurrent versions until their lock ends (and
  after it, unless a lifecycle rule removes them); `--point-in-time=<time>` at connect sees the repository as it was.
  GetObjectLockConfiguration: enabled → 200 `<ObjectLockEnabled>Enabled`; a bucket made without it → 404
  `ObjectLockConfigurationNotFoundError` (AWS, MinIO); MEGA S4 → 501 `NotImplemented` (2026-10, already before the keys are
  checked); wrong keys → 403 `InvalidAccessKeyId`.
* **VMs at the array stop (7.3.2, `/etc/rc.d/rc.libvirt`):** paused VMs are resumed, every running VM gets `virsh shutdown`
  (libvirt uses the guest agent while its channel is `connected`, else the ACPI power button), Unraid waits up to domain.cfg
  `TIMEOUT` (Settings → VM Manager → VM shutdown time-out; empty = 60 s), then `virsh destroy`s what still runs — a hard
  power-off. An idle Windows with its display off ignores the power button: a large server's array stop waited the full 180 s for a
  Windows 11 (2026-10-07). A running VM's live XML with the agent channel's `state` is libvirt's status file
  `/var/run/libvirt/qemu/<vm>.xml` (RAM, root 0600); `/etc/libvirt/qemu/<vm>.xml` has no state.
* **Unraid's mover and Mover Tuning (7.3.3, Mover Tuning 2026.10.03 by masterwishx, tried on a test server 2026-10-09):** ⟦Mover
  Settings⟧ «Disabled» = share.cfg `shareMoverSchedule=""` and no `/boot/config/plugins/dynamix/mover.cron`; any other
  mode writes it with `… /usr/local/sbin/mover start …` (emcmd `changeMover=Apply&shareMoverSchedule=…`). Unraid's mover
  moves everything of a share's pool folder (no list; files in use are skipped) and writes `/var/run/mover.pid`. From
  Unraid 7.2.1 on Mover Tuning no longer replaces it: its install renames mover.cron to mover.cron.old and puts the time
  into its own `mover.tuning.cron` (age_mover, which honours `filelistf`/`filelistv` and its thresholds — 85 % by
  default, so below that it moves nothing at all), but ⟦Move now⟧ in ⟦Mover Settings⟧, Main's Move and a new ⟦Apply⟧
  there run Unraid's mover again — the listed file went to the array. A share override counts only with
  `moverOverride="yes"`; its remove puts mover.cron.old back and leaves the cfg folder unless empty. «Disabled» lasts:
  emhttpd itself runs `rm -f /boot/config/plugins/dynamix/mover.cron` at every array start while the key is empty.
  ⟦Mover Settings⟧' form sends only `shareMoverSchedule`, `shareMoverLogging` and `changeMover=Apply` (emcmd adds the
  csrf_token from var.ini and posts to emhttpd's socket; an answer → printed, exit 1).
* **Emby sessions (4.10):** a device that went to sleep with something paused stays in `/Sessions` with its NowPlayingItem
  for hours (an iPad, 10 h); `LastActivityDate` (UTC, seven digits of a second) is when Emby last heard from it. Its web
  dashboard: Manage Emby Server (the gear) → Dashboard → «Now Playing», one card per playing session with ■ Stop (only while
  the session supports remote control).
* **Grafana** reads its provisioning from `GF_PATHS_PROVISIONING` (the image's `/etc/grafana/provisioning`, not
  mapped by CA's template); `GF_SECURITY_ADMIN_PASSWORD` counts at the very first start only.

## Several chats at once

When more than one Claude chat works on the office, one of them is the
**coordinator**; the others are **workers**, one per task (not one per desk).

* **Workers** work in a git worktree of their own on a branch of their own
  (`task/<short-name>`), on a local disk — never in the working copy on the
  server share (slow over SMB, and its index.lock blocks everybody). They
  commit and push their branch, run the checks they can (`php -l` via ssh,
  JS syntax, `php tests/run.php` on a copy) and report what they did and what
  is untested. They never merge into `main`, never run `dev-sync.sh`, never
  release, never start backup runs or apply settings.
* **The coordinator** owns `main`, the working copy on the server and the
  server itself: reviews a worker's branch, merges it, runs the full checklist
  below, `dev-sync.sh`, checks the page, releases. Only one version runs on the
  server at a time — so only the coordinator deploys.
* **Shared files** (`public/assets/core.js`, `office.css`, `src/`, `agent/lib/`,
  the engine in `backup/`, CLAUDE.md) are changed by one chat at a time; the
  coordinator says which task may touch them. A desk's own folder
  (`public/desks/<id>/`, `agent/desks/<id>.php`) belongs to the task working on it.
* At most two or three workers at once; a finished worker is closed, not kept.
* Tests that write (`setup.sh --apply`, runs) touch the real Kopia repository and
  containers even with another `UB_DATA`: only with Kopia switched off in the
  test decisions, and only by the coordinator.

## Checklist for a change

1. PHP syntax: on the host `php -l` for every file in `agent/`, `src/`,
   `public/*.php`; `bash -n` for `plugin/scripts/*`, `plugin/event/*`, `backup/`.
2. JS syntax: `osascript -l JavaScript` with `new Function(src)` (or `node --check`; Node is on the dev Mac for the browser tools since 2026-10-10).
3. Tests on the host: `php tests/run.php` (logic: cron, snapshot retention,
   Emby detection, the plugin's cron file — on copies; strings: `en`/`de`/`it`/`fr`/`es`
   keys identical, every language against English, every T('…'), check and error text exists).
   Must end with 0 failed. `php tests/run.php <part|test> …` runs only those (each once, one sum; an unknown name:
   exit 2, nothing run — `testRunnerNames`). They have a RAM folder of their own (`OFFICE_RUN_DIR`; a process a test starts with an
   environment of its own gets `'OFFICE_RUN_DIR' => TESTS_RUN_DIR`) and, as root with `unshare`, run in a mount
   namespace of their own whose `/var/run/unraid-secretary-office` is an empty folder of theirs — the live agent's
   locks, heartbeat and doorbell are never met; `testLiveRunUntouched()` fails if anything landed there.
   **The engine's fixtures run on a clock of the tests' own**, never the real one (a run id, a log, a snapshot's name
   carry the minute; «nights in a row» and «once a day» the date — the suite went red by the clock, 2026-10-08):
   `testsClock($bin, $clock)` puts a `date` stand-in into the fixture's bin folder (first on PATH; the engine reads
   the time only through `date`), starting at 03:00 tomorrow — never behind the real clock, so what the fixture writes
   is never newer than the engine's «now». The seconds run at the real pace (deadlines still pass); the test moves
   minute and day: `testsClockRun($clock)` before every run (the next minute — the run's id is
   `date('Ymd-Hi', <returned>)`), `testsClockNight($clock)` for a night later (03:00 the next day), `testsClockNow()`
   for anything compared with the engine's time (never `time()`/`date('Y-m-d')`). Stand-ins note times with
   `date +%s` too. The stand-in knows exactly the `date` forms the engine uses (`+FORMAT`, `-d @N|YYYY-MM-DD`,
   `-d "-N days"|"N days ago"|yesterday …`); a new form in the engine fails loudly — add it to `testsClock()`; each
   such test ends with `testsClockUnsupported($clock) === ''`, and its `$run` passes the output through
   `testsClockRan()` (a run refused as «one run a minute» fails). Never remove logs between runs to dodge the minute.
4. On a server that runs the plugin, `bash plugin/dev-sync.sh` on the host puts
   the working copy into the plugin (RAM, until reboot/update; leaves backup/
   alone while a run is active). The agent restarts itself when its files change (agent/, and src/place.php it shares with the web side) — watch `data/agent.log`
   ("Agent code changed — restarting"); a syntax error keeps the old code running.
5. Reload the page for real (changing only the `#` part of the URL doesn't reload).
6. Check the page at phone width (375 px): no horizontal scrolling. Inside
   Unraid check the black and the white theme at least (in a test tab, swap
   `themes/black.css` for `white.css` — never change the user's setting).
7. Test destructive actions only on throwaway objects, then clean up. Never
   wake sleeping disks, start backup runs or apply settings on a real server
   just to test.
8. Something for the plugin changed (paths, scripts, `.plg`)? `bash plugin/build.sh <version>`
   must pass.
9. The page changed? `bash tools/ui-clicks.sh` on the Mac (node + playwright-core, a headless Chrome with a throwaway
   profile — never a browser with your sign-ins): every desk page built from the repository's files (`tests/ui/harness.mjs`,
   stub states in `tests/ui/states/` — scrubbed copies of a test server's, `post/<desk>.<action>.json` for what a page posts on
   its own), both themes at 1440 and 375 px; every `<summary>`, unfolding row, group head, tile and other
   `aria-expanded` control clicked twice: it changes, nothing else does, it stays after 1.5 s and when the desk draws
   itself anew (the messenger's word after each click, the poll after each round), no console error, no horizontal
   scrolling. ≈ 2 min, exit ≠ 0 on any failure; `--only <route>,…`, `-v`, `UC_DEBUG=1`. `tools/release.sh` runs it
   before the suite (step 6) and stops on red like the suite. A new state shape a page needs: add it to the stub.
   **Screenshots** for the product page and the forum: `bash tools/ui-shots.sh` — the same harness with `demo: true`
   (`tests/ui/demo/`: invented family data only — never a real server's names, paths, addresses or titles; times moved
   to now), inside Unraid's look (the harness always lays Unraid 7.3's palette, black or white theme and fonts from
   `tests/ui/unraid/` around the office; `?theme=white`), black/white × de/en × 1440/390 at scale 2, a route's own shots
   (`SHOTS`, e.g. Jack Emby's head + live panel and the panel alone) or `--shot full|until:<css>|clip:<css>`; PNGs into
   `$USO_SHOTS/<date>/` (default `~/uso-shots/<date>/`). `--moments [nr,…]`: the product page's moments
   (`tests/ui/demo/moments.json`: per desk a clipped element after a small «prepare» - click, fill, select, js -, each
   in its demo state, `states` naming another file of the folder, e.g. `backup-run.json`), black, 1280 px, de/en,
   seeded greetings, into `shots/<date>-moments/` with `contact-sheet.html/-de.png/-en.png`; with `--themes black,white`
   each moment per theme (`<nr>-<desk>-<moment>-<theme>-<lang>.png`, the sheet shows them side by side) — the product
   page and README.md show both, following the viewer's theme. Prepare steps stay
   language-neutral (`Office.t(…)` in a js step, not a German text selector). Neither tests/ nor tools/ go into the plugin package.

## Layout

```
agent/agent.php          loop, mailbox and its doorbell, heartbeat, desk loading, self-restart
agent/partner-door.php   the partner door: the forced command of a pair's line in authorized_keys (lib/partner.php)
agent/lib/*.php          shared helpers (util: run, writeAtomic, readCfg, Problem;
                         mounts; backupscript; house: plugins, containers, finding)
agent/desks/<id>.php     one desk each: desk('<id>', [...])
src/*.php                web side: bootstrap, mailbox client, desk/lang discovery, staff, API, page, Dashboard tile;
                         words.php (Unraid's labels in Unraid's language) shared with the agent
public/lang/unraid/      Unraid's words per language (from Unraid's language packs) for the ⟦label⟧ tokens
public/assets/core.js    Office: i18n, routing, reception, API, dialog, menu, toast, fmt,
                         deskHead, pageHelp, sectionHead, backupChip, the search (places, palette, reveal)
public/desks/<id>/       desk.json, desk.js, places.json (the search), lang/*.json (and desk.css, avatar.svg)
data/                    runtime only (state per desk, mailbox, agent log, office/staff.json) — not in git
tests/                   run.php (the suite, on a server), watchbook.js; ui/ the click test's page (harness.mjs) and stub states,
                         ui/demo/ invented family data for screenshots, ui/unraid/ Unraid 7.3's palette, black/white theme, fonts
tools/                   release.sh (the coordinator's release), ui-clicks.mjs/.sh (the click test), ui-shots.mjs/.sh (screenshots), bpmn-svg.mjs (docs/diagrams/*.bpmn → .svg with bpmn-js), supporter-key.sh, lang-keys.php
backup/                  the backup engine: backup.sh, setup.sh, lib/common.sh (data in data/unraid-backup)
embycache/               Jack Emby's EmbyCache (Python; data in data/embycache)
gather/                  Jack Emby's media gather, consolidate_master.sh (bash; data in data/gather)
smartmover/              Ms. Moverelli's Smart Mover, custommover_run.sh (bash; data in data/moverelli)
monitoring/              grafana-dashboard.json: the office's dashboard to import (its numbers: lib/metrics.php)
(the support page OFFICE_SUPPORT_URL points to — a Cloudflare Worker: tips, supporter keys — lives in the maintainer's
                         private repository since 2026-10-08; its key format is src/supporter.php's)
plugin/                  the Unraid plugin: .plg template, build.sh, dev-sync.sh, scripts/ (agent.sh
                         service, job.sh for the cron file), event/ (started, stopping), images/,
                         SecretaryOffice.page (the office in Unraid), SecretaryOfficeButton.page
                         (the header button), SecretaryOfficeDashboard.page (the Dashboard tile),
                         README.md (the short text Unraid's Plugins list shows); images/ has the
                         plugin icon as PNG with its SVG source (rendered on the Mac via NSImage)
.github/workflows/       plugin.yml: builds and attaches .plg/.txz when a release is published
```
