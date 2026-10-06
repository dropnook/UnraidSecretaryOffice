# Working on Unraid Secretary Office

Read README.md first — it explains the architecture and how a desk (secretary) is built.
This file holds the conventions and the checklist for changes.

## How it runs: the plugin

The office is an Unraid plugin — the only way to run it (the old Compose stack
went in 2026-10). Its code lies in RAM under
`/usr/local/emhttp/plugins/unraid-secretary-office/` — the web files of
`public/` at its top, `src/`, `agent/`, `backup/`, `embycache/`, `gather/`,
`scripts/`, `event/`, `images/` beside them (built by `plugin/build.sh`). Unraid's nginx/php-fpm
serve the page behind the Unraid login: `SecretaryOffice.page` (`Menu="Tasks:85"`)
puts the office into Unraid's menu bar, inside Unraid's page (`officeInUnraid()`,
`OFFICE_IN_UNRAID`, files from `/plugins/unraid-secretary-office/`); its label is
the page's `Name=` (default *Sekretariat*); or, the user's choice, an icon
under Settings → User Utilities (`Menu="Utilities"` + Title/Icon/Tag), or
only a button in Unraid's header (`SecretaryOfficeButton.page` gets
`Menu="Buttons:90"`, the office's page no Menu= — Unraid still serves it at
/SecretaryOffice; a button page is loaded on every Unraid page, keep it a
one-liner). All in
the ⋯ menu → `caretaker.menu_name`, kept as `MENU_NAME`/`MENU_PLACE` in the
.cfg, put back by the .plg at every boot (`officeMenuPageApply()`);
`index.php` only forwards there. `SecretaryOfficeDashboard.page` puts a tile
on Unraid's Dashboard (src/dashboard.php: the messenger, the caretaker's
traffic light, Mr. Backupsy's last/next run — from the state files only,
refreshed by `api.php?a=dash` every minute while in view). The agent is a service
(`scripts/agent.sh`, started at install/boot and by `event/started`, stopped
by `event/stopping`) on the host. The data folder is `DATA_DIR` from
`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
(default `<appdata>/UnraidSecretaryOffice/data`; `OFFICE_DATA_DIR` overrides it —
the tests use the repository's `data/`, and `OFFICE_WEB` its `public/`).
Development: a git clone anywhere, `plugin/dev-sync.sh` puts it into the
installed plugin (see the checklist).

## Conventions

* **English** in code, comments, commit messages, README. The UI is translated:
  every string lives in `public/lang/<code>.json` (office) or
  `public/desks/<id>/lang/<code>.json` (desk). `en`, `de`, `it`, `fr` and `es`
  must always have the same keys — a new UI string needs all five; any other language may
  leave keys out (English fills in) but never has keys `en` lacks, and
  placeholders `{…}` and plurals match `en` (tests/run.php checks it). Unraid
  menu paths follow Unraid's language packs (github.com/unraid/lang-it_IT,
  lang-fr_FR, lang-es_ES). French `one` covers 0 and 1 (Intl): write `{n}` in
  French one-forms where 0 can occur.
  Never hard-code UI text in JS or PHP.
* **No build step, no dependencies.** Plain PHP 8.4 (Unraid's own PHP runs both
  the page and the agent — use nothing newer), vanilla JS, one CSS file plus optional
  `desk.css`. The look follows levelnext Airdrop (tokens in `office.css :root`).
  The only "build" is the plugin package (`plugin/build.sh`).
* **The web side never touches the host** (even though Unraid's php-fpm runs as
  root). Everything that needs zfs, docker, /boot/config, /proc etc. goes
  through the agent (`Office.api.post('<desk>.<action>')` → `agent/desks/<desk>.php`).
  One exception: secrets the user types for the agent (the Consultant's Kopia
  setup) never go into the mailbox (it lies on the pool) — `apiSecretStash()` (src/api.php)
  puts them into a 0600 file in `officeInboxDir()` (`/var/run/unraid-secretary-office/inbox`,
  RAM), the request carries only its name, the agent reads and removes it at once
  (`advisorSecretTake()`). Only actions in `OFFICE_SECRET_ACTIONS` may carry a `secret`.
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every
  action re-reads the current state and validates ids against it. Errors are
  `throw new Problem('key', [...])`; the UI translates `errors.<key>`
  (desk-specific keys in the desk's lang file).
* **Hardening rules (1.26):** files only through `writeAtomic()`/`writeNewFile()`
  (exclusive random tmp, mode from the start, never through a symlink); the agent
  reads the mailbox only while it is a real folder of the web server's user
  (`privateDirOk()`) and only plain request files; validators that feed names,
  files or commands end with `$/D` (PCRE's `$` also matches before a newline);
  links built from state files only via `Office.safeHref()`; manifests read back
  from shares (Ms. Dustdevil's storeroom) are trusted only in exactly the shape
  the office writes. `HARDENING.md` lists what was checked and what is
  recommended but open.
* **Never wake sleeping disks on your own.** Check `sleepingDisks()` before
  reading array disks; offer an explicit "wake" option instead.
* **Nothing directly in /mnt** (Fix Common Problems): mounts go to
  `/mnt/addons/UnraidSecretaryOffice/…` (the place for add-on mounts, RAM — see Server facts),
  data the desks keep to the share `UnraidSecretaryOffice/<desk>/`.
* **Never block the array stop:** no sockets or open files in the pool; the agent
  keeps nothing open there.
* **Keep `tick` functions cheap** — they run every ~150 ms. Long work (like `du`)
  runs as a background process polled from `tick` (see whereabouts sizes).
* Desk lang keys `name` and `role` are the desk's title and subtitle — don't reuse them.
* **No lock of its own:** every page and POST is behind Unraid's login (nginx `auth_request`) and
  carries its `csrf_token` (plus the API's `X-Office`/Origin checks, src/api.php); whoever is logged
  in to Unraid is root. The office adds previews and confirmations of changing actions against
  mistakes — never a PIN or a second login (the PIN went in 2026-10; an old `data/office/auth.json`
  is ignored). HARDENING.md «Who gets in».
* **Checks for the caretaker:** a desk that needs plugins, containers or
  settings registers `'checks'` returning `finding()`s (agent/lib/house.php):
  `required` only when the desk really can't work without it, otherwise
  `recommended`; `hint` for things to know. Texts: `check.<id>` states how it
  should be, `check.<id>_how` what the user does in Unraid to get there.
* **Metrics for Prometheus** (`agent/lib/metrics.php`): a desk may register
  `'metrics' => fn (): array => [...]` — families `['name' => 'uso_<desk>_…',
  'type' => 'gauge'|'counter', 'help' => '…', 'samples' => [[['label' => 'value'], <number>], …]]`
  (`metricsGauge()` builds one). Called once a minute from the agent's loop, hired
  desks only, so like `tick`: state files only (`metricsCached()` re-reads a file
  only when it changed), no zfs/docker/commands. The agent writes `uso_<desk>.prom`
  plus `uso_office.prom` into `/mnt/addons/UnraidSecretaryOffice/metrics` (RAM, see
  Server facts) with `writeAtomic()` in that folder; files of desks let go
  or no longer reporting go. `metricsEnsureDir()` looks before every write (an lstat per
  part, said once per change): a missing `/mnt/addons` is made as a plain folder (root,
  0755 — never a mount of the office's own), the office's folders below it again when they
  vanished; a link or another owner on the way is refused. The Consultant says «reads the
  office's folder» only while that folder is there (`advisorMetricsThere()`). Names `uso_…` (`[a-zA-Z_][a-zA-Z0-9_]*`), each once over
  all desks, the same label names within a family, no `_count`/`_sum`/`_bucket` on a
  gauge (promtool), times as `…_timestamp_seconds` (Grafana does `time() - x`); few
  label values (no series per file or per snapshot). **Size cap:** all files together
  ≤ `METRICS_MAX_BYTES` (16 KB) — the families with the most series are dropped first,
  logged once, counted in `uso_metrics_dropped_families`. `monitoring/grafana-dashboard.json`
  uses the names: renaming one means changing it there too. Where a Node Exporter or
  Prometheus exists the team lead follows the chain (numbers fresh → the exporter
  reads their folder → Prometheus ready → an up node target); nobody writes
  prometheus.yml. Tests: `OFFICE_METRICS_DIR`.
* **Notifications** to Unraid only through `officeNotify()` (agent/lib/house.php):
  event and subject prefix `Unraid Secretary Office` like the engine; `warning`
  for something to fix, `alert` only when something is at risk now (failed
  runs, agent gone); texts via `officeNotifyText()` in Unraid's language
  (`officeNotifyLang()`), keys `notify.*` in the desk's lang file; tests set
  `OFFICE_NOTIFY_BIN` to a stand-in. The caretaker runs every hired desk's
  `checks` every 30 min even without a browser (in the agent's tick) and
  reports new red findings once after 30 min (`data/caretaker/notify.json`,
  switch `notify_set`) — so checks must stay cheap and must not wake disks:
  when the disk/pool behind a path sleeps, return `ok = null` (not looked).
  `agent-watch.cron` (written by `agent.sh start`, removed with the
  plugin) runs `job.sh watch` every 5 min: agent.json older than 70 s for 10 min
  with the array started → one alert, and a normal notification when it is back.
* **Jobs that must outlive the agent** (backup runs) go through the host's
  `atd` (`backupLaunch()` in agent/desks/backup.php), never as a child of the
  agent: `agent.sh stop` ends the agent's whole session (array stop, plugin
  update).
* **Schedules** (nightly backup, snapshot plans, EmbyCache, the gather) only through
  `officeJobSchedule()` / `officeJobSetSchedule()` (agent/lib/house.php): a line in
  the plugin's cron file (`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`,
  calling `scripts/job.sh <job>`, which runs only while the array is
  started). Never User Scripts for the office's own jobs (User Scripts are the user's: Ms.
  Dustdevil, Ms. Whereabouts, Jack Emby and the night watchman only look at them).
* **The backup engine** (`backup/`, bash, English since 2.13) runs on its own via the plugin's cron file. Mr. Backupsy only uses
  its interface (`backup.sh --about`, `data/unraid-backup/state/status.json` &
  co., interface 1; the setup assistant uses `setup.sh --plan` / `--apply`
  with `state/setup-plan.json` / `setup-status.json`). Never parse its log
  lines for anything new — extend the interface; reasons go out as codes
  (why/ctwhy) so the office can translate them. Version lives in backup.sh, setup.sh, lib/common.sh and
  backup/README.md. backup.sh and setup.sh are one `{ … }` block (since 2.14),
  so bash has read all of it before a run starts; keep it that way (code goes
  inside the block) and still replace files with a new file + `mv`, never by
  writing into them. After a `mv` on the host the Mac's SMB view may still show
  the old content: compare md5 on both sides before committing.
* **Jack Emby's tools** ship with the office: `embycache/` (EmbyCache by
  helmi1987, Python 3 stdlib, German) and `gather/` (media-disk-gather,
  `consolidate_master.sh`, bash, German). Changes are ours now, kept small and
  listed at the top of their README.md; their data stays apart
  (`EMBYCACHE_DIR` = data/embycache, `CONSOLIDATE_CONFIG` = data/gather/consolidate.ini)
  and they report through status files (`EMBYCACHE_STATUS`,
  `CONSOLIDATE_STATUS`), never parsed log lines for anything new. EmbyCache
  MOVES files (rsync, then delete the source) — test with report and dry
  runs. Runs go through `php agent.php job embycache|gather <mode>` (atd from
  the page, the cron file on schedule); a real run holds the
  other tool's lock, EmbyCache's real runs wait for the gather's first real
  run. The cleaner (`embycache_cleaner.py`) is left out on purpose: on a
  share whose primary is the pool it would take every new film for an orphan.
* **Packages (engine 2.18):** the backup place holds one package per app
  (compose project or single container) and per VM — `apps/<app>/`,
  `vms/<vm>/`, plus `server/` and `flash/`, each with a `manifest.json` —
  built in `.ub-stage-<run>` and swapped in before the snapshots, overwritten
  every run (a failed dump keeps the last good one). Their history lies in the
  snapshots of the backup place's share, which must be at least `snapshot`.
  Packages are never deleted (stale ones stay). A compose app whose services build their own image
  gets `build/<service>/` (the Dockerfile and the build context's small top-level files, engine 2.20;
  Compose Manager's update can't update such an image — it pulls first). The office reads the manifests
  as part of the engine's interface; restore commands use the credentials the
  manifest names (variable names, never values). Under `set -o pipefail` a
  `while … | jq … || fallback` loop must not end on `[[ … ]] && cmd` (use `if`).
* **Kopia per app/VM (engine 2.19):** `[app|vm "<name>"] kopia = yes,
  folder = <share>/<folder>, kopia_retention, kopia_ignore`. The source is
  `<mount_root>/.apps|.vms/<name>`: read-only binds (`ro_bind`) of its folders
  and its package out of the share mounts; shares get those parts as ignore
  rules the engine adds itself. Kopia phase order: apps, shares, VMs, flash.
  Never put a Kopia source under another source's policy path (parent-path
  ignore rules are merged and re-anchored at the source root), never `@` in a
  source path (Kopia reads `/x/@y` as user@host). Media servers that keep
  running get SQLite copies (`db/sqlite_<c>_<file>`, backup API through the
  container's bind path, before the apps stop); the apps' own backups are named
  in the manifest (`own_backups`). The setup's step 4 has "Kopia per app and
  VM"; offered ignore rules are never preselected; data warnings (Nextcloud's
  data, Immich's uploads in a share backed up less than the app) on the app row.
* **New things stay local and keep running until the user decides (engine 2.21, Benj's rule):** a share that goes
  to Kopia records its top-level folders at the setup's Apply (`[share] kopia_known = /<folder>/`; the first time all
  that are there and not ignored, later what was known plus what the user sends to Kopia; a sleeping part: recorded at
  a later setup, never woken; more than 500 folders at its top = a collection, `kopia_known = *`, every folder goes). A folder neither known nor left out (kopia_ignore, global ignore, an app's/VM's own
  part, the backup place's folder) is new: right before the upload the run leaves it out of the share's Kopia policy
  (its own rules, taken away again once decided or gone; Kopia refusing = the share is skipped, never uploaded
  unasked) and says so — `status.json` `new_local`, `state/new-local.json`, drift info `new_waiting`, one notification
  (normal) when first seen. Containers not in `[docker] known` keep running in a run; setup.sh proposes them (and VMs
  without a section, `prepare = none`) so. The office never sends something new to Kopia or stops it unasked: new
  apps/VMs at most «local», kept running, marked «new — please decide», listed first; step 3 lists a share's new
  folders (`waiting` in the plan) with only local (= kopia_ignore, proposed) / local + Kopia (= kopia_known); the
  apply dialog has a group «New»; the main page a callout (`state.waiting`, `backupWaiting()`). A share without
  `kopia_known` works as before (drift `known_missing`) until the setup is applied once. Settings already decided
  never change level by this.
* **What the engine pruned (engine 2.21):** `state/pruned.json` lists per real run the snapshots its retention destroyed
  (`runs: [{run, time, zfs: [dataset@name], btrfs: [path]}]`, the last 30 runs within 30 days, lists capped) — for the
  night watchman, who never parses the engine's logs.
* **VMs that shut down go first (engine 2.22):** `[vm] prepare = shutdown` VMs are asked and waited for (`vm_shutdowns`:
  `vm_hold_begin` + `vm_shutdown_wait`, one deadline `UB_VM_SHUTDOWN_TIMEOUT` from the request) before Nextcloud's
  maintenance mode and the apps stop — `downtime_s` (first app stopped → all started) never includes that wait; status
  phase `vm_shutdown` (desk.js STEPS: step `dumps`). `vm_hold`, right before the snapshots, only freezes and pauses — also
  a VM not off by its deadline (its `seconds` from the pause); one that went off late stays off and is started like the
  others. A run stopped during the wait waits for the VMs still going down (`vm_shutdown_wait abort`) and starts them.
  `testBackupVmOrder` runs backup.sh on a fixture server (stand-ins on PATH, an events file) — extend it for changes there.
* **One run at a time, never lost silently (engine 2.20):** `state/lock` (flock) is held by
  backup.sh, setup.sh and Mr. Restori's restores; whoever takes it opens it with `>>` (never
  truncating), `touch`es it and writes `state/lock-holder.json` (`holder`, `mode`, `what`, `run`,
  `pid`, `started` — backup/README.md "When the lock is busy"), trusted only while its pid lives
  (`backupLockHolder()`; unknown = `other`). A backup.sh that finds it busy touches nothing of the
  run going on (no status.json, no latest.log, nothing loaded or mounted) and writes `skipped.json`,
  for a real backup also a `history.jsonl` line `"result": "skipped"` (reason `skipped_busy_<holder>`,
  translated as `backup.message.<code>`) and a warning notification; exit 75. The office keeps skips
  apart from runs (`state.skips` — history, estimates, the last run and the Dashboard's last run never
  count them) and shows the newest skip while no run finished after it.
* **Names (engine 2.20):** what the office creates in numbers carries the short prefix `uso`; places
  keep the long name (share `UnraidSecretaryOffice`, `/mnt/addons/UnraidSecretaryOffice/…`,
  `_UnraidSecretaryOffice-trash`, the plugin's folders, `unraid-backup` as the interface's name). The
  engine's ZFS snapshots `uso-backup-YYYYMMDD-HHMM` (`[general] snap_prefix`), Ms. Snapshotini's
  `uso-plan-<plan>-YYYYMMDD-HHMM`, Kopia descriptions `uso-backup <run>`, the Kopia container path shown
  to new setups `/uso`. Old names stay recognised wherever they are read and age out by their normal
  retention: the old default `unraidbackup-` counts as the default (lib/common.sh section 10,
  `backupSnapPrefixes()` / `backupIsEngineSnap()` in agent/lib/backupscript.php — always exact:
  `<prefix>` + `YYYYMMDD-HHMM`, never looser); a prefix of the user's own stays alone.
* **Backups never live in appdata.** Dumps, archives and manifests go to a
  backup share of their own (`[general] dumps_share` → `<share>/unraid-backup`,
  in the office's share `UnraidSecretaryOffice` → `backup/`, one folder per desk;
  root only); without a valid one the engine refuses to run (stops before
  pausing anything), setup.sh won't write settings.ini, the caretaker lists it
  as a must. appdata only holds the office's own settings, state and logs.
  Failures the office should explain go out as codes (`die_code <code>`,
  translated as `backup.message.<code>`).
* **While a backup runs** the office may read the engine's crash notes
  (`state/stopped`, `state/maintenance`) to show what is paused — but nobody
  edits `backup/` then (see above).
* **Versions:** `OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION`
  (agent/agent.php) move together; the release tag is `v<that version>`.
  The plugin's version is a date (`2026.10.05`, a letter for a second one that
  day): Unraid compares plugin versions with `strcmp`. The GitHub Action
  (`.github/workflows/plugin.yml`) picks it (the last one from the previous
  release's `.plg` — "latest" is the new release itself) and builds `.plg` +
  `.txz` when a release is published; `plugin/build.sh` refuses when the
  code's versions don't match the tag. Release notes in English, written for
  users; check afterwards that the `.plg` got a new version
  (`gh release download v<version> -p unraid-secretary-office.plg -O -`; the
  public "latest" address may answer 404 for a few seconds after the upload).
  Releases are made with `gh release create v<version> --target main --title
  "Version x.y" --notes-file <notes>` (allowed in .claude/settings.local.json).

## The desks

| id | name (en / de) | does |
|---|---|---|
| `snapshot` | Ms. Snapshotini / Frau Snapshotini | ZFS, btrfs and VM snapshots: create, delete with an estimate, rename, hold, unmount; schedules with retention (lib/snapshotplans.php: snapshots `uso-plan-<plan>-YYYYMMDD-HHMM` — up to 1.27 `auto-<plan>-…`, counted with them —, retention touches only those and never what matches the engine's names (`backupIsEngineSnap()`); `php agent.php job snapshot-plans` every 5 min — the plugin's cron line `job.sh snapshots`) |
| `whereabouts` | Ms. Whereabouts / Frau Wasistwo | what is where and going on; "where things are" (config files, boot medium, VM files) with their backup protection; "If I were you …" — her operational advice (waAdvice() reads a few settings; the tips are built in desk.js, «I know, thanks» per browser with a signature, so a tip returns when the situation changes; nothing Fix Common Problems checks): system shares on the array, the mover, exclusive shares, compose builds, spin-down, old disks, parity, UPS, syslog, cron lines whose program went with its plugin (`waCron()` `gone`/`program` — `waCronGone()`, never under /mnt; for root's own crontab the cleanup command as information). No security tips — they are the night watchman's; while he isn't hired one tip (`security`, info) says so and links the team lead. Her users/share access and SMB/NFS sections stay a plain directory, with a line linking his watch book when he is hired (`Office.desks.get('watchman').hired` — the staff list, not his code). Read only |
| `backup` | Mr. Backupsy / Herr Backupsi | runs the engine in `backup/`: status, overview tiles (Kopia, containers, databases, VMs, flash), history, protection (shares, then the VMs; rows unfold to their rules), restore help incl. "Onto a new server", setup assistant (`#/backup/setup`: 0 basics → 1 VMs → 2 apps → 3 other shares → 4 retention; a level per VM/app — nicht / lokal / lokal + Kopia, say "Kopia", never "offsite" — and setupDerive() turns it into share modes (locked in step 3), `docker|no_stop`, dumps and Kopia ignores for folders of apps/VMs that are only local; an app's further shares are never ticked unasked; "start anew" = `setup.sh --forget`; what is new since the last setup waits for a decision, engine 2.21 — see «New things stay local»; step 0 explains the backup place — Benj's five points (not appdata's pool, ZFS/btrfs for earlier nights, redundancy, one place without secondary storage, small), a link Shares → Add Share (`/Shares/Share?name=`, name `UnraidSecretaryOffice`; the office never makes the share) — and warns, never blocks, from `backupPlaceFacts()` in setup_get (codes same_pool / no_history / secondary / no_redundancy from the share's cfg, disks.ini and the plan's locations); the page re-plans when the setup opens with a plan older than a minute (`SETUP_REPLAN`), and a new plan keeps what the user chose and didn't apply (`setupDraftKeep()`: a key the engine still proposes as before keeps the user's value, levels and holds stay)). VMs (engine 2.16): `[vm "<name>"] prepare` = freeze (guest agent) / pause / shutdown (never forced off) / none for the seconds of the snapshot, released right after the snapshot holding their disks (`state/vms` for a killed run); `mode = off` and `retention` only for a VM in a dataset of its own (`VM_OWN_DS`); per-VM results in `status.json` `vms`. A source's **first upload** to Kopia (no ok result in a run since the Kopia container's repository.config was written — a new repository starts every source anew) is reckoned from its snapshot's size (`logicalreferenced` of this run's `@<snap>` datasets) and the Kopia process's `rchar` rate between looks in RAM (`backupUpload()`, `state.upload`), never "late"; the texts say Kopia *reads* ~rate/s, and what it really sent so far (`wchar`, `sent`) stands beside it. The stop button, its dialog and toast name the run going on (`runMode()`: the lock's note, then status.json — «Stop the tour» for a check, «Stop the dry run», a backup «Stop the run»); «Start a run» says a full backup without Kopia while `kopia_enabled` is off |
| `emby` | Jack Emby (the intern) | EmbyCache (`embycache/`, from github.com/helmi1987/embycache-for-unraid, extended: back to the origin disk via embycache_origin.json, the emptied folder stays on the disk as a signpost, separate limits for started films and series, deliberately skipped folders = empty mapping) and the gather "Consolidate folders" (`gather/`: one disk per film folder, keeps empty folders whose content is on the pool). Settings via EmbyCache's own save_config() (trial file first); the gather's ini written by Jack; API key never leaves the server; schedules: jobs `embycache` and `gather` (job.sh). **Never a real gather while someone watches Emby** (Benj, 2026-10-06): `embyWatching()` asks every server of EmbyCache's settings for `/Sessions` (PHP's curl, the key in memory only, 5 s); a session with a NowPlayingItem, also paused = watching; no HTTP answer = down → the run goes, logged; 401/403, another status, no JSON → not started (`emby_watch_key`, `emby_watch_answer`). From the page refused with who watches what on which device (`emby_watching`), no override — whoever wants it stops Emby himself; on schedule `embyGatherGate()` waits holding only its own lock (`emby-gather-wait.lock/.json` in RUN_DIR — nothing open on the pool), looks again every 15 min up to 2 h, then `skipped` in his list of runs (a second start while one waits adds nothing; a real gather from the page meanwhile or the array stopping ends the wait); during a real run `embyGatherWatch()` asks once a minute and writes `CONSOLIDATE_STOP` → the gather stops after its current folder (result `stopped`, exit 3, never its first real run). Dry runs are never asked about. **Taking over an earlier install** (`import_preview` / `import_apply`, a section on `#/emby/setup`, his menu): the user types the folder of the old EmbyCache and/or the old gather — Jack never searches (his detection of other copies, `embyForeignSchedules()`, stays apart); `embyImportFolder()` checks it before anything is read (absolute, no `.`/`..`, under `/mnt/user/<share>`, a pool, `/mnt/diskN` or `/boot/config/plugins/user.scripts/scripts/<name>`; its share's/pool's/disk's disks awake — refused `emby_import_asleep`, never woken; every part a real folder, only an exclusive share's own link followed; not his own folder); only `EMBY_IMPORT_FILES` are read there (plain, nlink 1, size-capped; consolidate.ini parsed by `embyImportIni()`, never sourced). EmbyCache: keys 7.3.0 doesn't know left out (names only), keys the old install lacks keep Jack's current value, or without one Jack's own default (`EMBY_JACK_DEFAULTS` — what his setup page would choose, e.g. `cleanup_tool` rsync; `max_resume_*` = the old `max_resume_items`; else embycache_lib.py's `DEFAULTS`), values that run or write elsewhere (`mover_bin`, `rsync_args`) or are the office's (`EMBY_IMPORT_FIXED`) are Jack's, the pool must be this server's; the old global mappings merged into each server's; the list and origins only as lines under `<pool>/<share>/` of an existing share, merged with Jack's (his origins win); EmbyCache's own save_config()+load_config() tried on a trial folder in the preview. The gather: BASE_DIRS (whole shares only), MIN_FREE_GB, DUP_CHECK into gather.json, the rest Jack's own. The preview never carries an API key (`embyImportScrub()` over every answer; «API key: found / Jack's / not found»); the apply needs its token (over the preview, what will be done and Jack's files) and first copies each of his files it replaces to `<file>.before-import-<time>` in his data folder. Afterwards he says: switch the old User Script / cron line off yourself, start with a dry run. Under the tools' tiles at the bottom a quiet credit to helmi1987, who wrote both tools (`credit()`, links to his two repositories, both GPL-3.0-or-later like our modified copies; a share of the tips goes to him — Benj's decision) |
| `logs` | Ms. Protocolli / Frau Protokolli | reads logs out loud (fixed source list in agent/desks/logs.php, ids only, never paths; `backup:latest` = the engine's latest.log); tail/follow by file offset, docker logs and dmesg re-read; lines go into the page as text only. A picker with a search field instead of a select; favourites in `Office.store` (defaults until the user stars/unstars one, "out of the box" brings them back); nothing is read until a log is chosen. Read only. Tour (`logs.tour`, open action; `php agent.php job logs-tour` in the background, polled from tick → `data/logs-tour.json`, part `tour`): /var/log fill and biggest files with growth, container log sizes by stat plus Docker LOG rotation, errors and warnings per source since the last tour (offset, rotation by inode, first tour 24 h; ≤4 MB per file, ≤3000 lines per running container), similar lines grouped (`logsNormalize`: numbers, addresses, ids, file names and paths — quoted, absolute, relative, Samba's `for <name> with NT_STATUS_…`); a line's own level beats words, the page's colouring uses the same patterns (tests compare them). Check `varlog`: /var/log >60 % recommended, >80 % required |
| `watchman` | The Night Watchman / Der Nachtwächter (it Il guardiano notturno, fr Le veilleur de nuit, es El sereno) | calm, factual, few words; the office's security department, two parts on his page. **How secure does it stand?** — his posture tips (`watchmanPosture()`, every round, RAM and flash only, in `state.json` `posture`): the flash exported to guests, also only for reading (`flash`, sec.ini/sec_nfs.ini level ≥ secure: shadow and ssh/ lie there; link `/Main/Boot?name=flash`; the `public` tip leaves the flash out — other «secure» shares get no tip), shares guests may read, write and delete (sec.ini/sec_nfs.ini level public — user shares, disks, pools; links `/Shares/Share`, `/Shares/Disk`), Telnet (ident.cfg), Unraid's FTP server (inetd.conf; not while Fix Common Problems is installed and `vsftpd.user_list` exists — then it is FCP's check), the CPU's protection off (`mitigations=off`, open flaws from /sys; link Settings → Boot Parameters) with VMScape while VMs exist (`virsh list --all` — never a look into libvirt.img; libvirt not answering: the last round's word) or on (info), privileged containers (info). Each with why, a link into Unraid and «I know, thanks» kept on the server (`data/watchman/posture.json`, id → signature of what it is about; back when that changes, forgotten when the tip goes; `posture_ack` {id, on}). Advice, not findings: never in the book, never notified; the team lead gets one `hint` (`posture`, how many advice-level tips are open). Not FCP's checks (a root password, plugins CA doesn't know — FCP's `unknownPluginInstalled()`). **What changed?** — keeps the watch book (Wachbuch, registro di guardia, main courante, libro de guardia) and reports only what is DIFFERENT from normal. Read only. His first round after hiring is the baseline (also the login addresses in the syslog's history; hired again = a new baseline), nothing reported; then `php agent.php job watchman-round` every 5 min, started from his tick (a round lock in RUN_DIR; the merge into `data/watchman/` baseline/book/state/seen.json under a book lock, so «I know, thanks» never races a round): WebGUI/SSH logins from a new address and bursts of failures (WATCH_FAIL_BURST within WATCH_FAIL_WINDOW; syslog by offset, rotation by inode; names tried kept only when they are users), container rights as docker run flags (privileged, host network/PID/IPC, ports, caps, devices, docker.sock, /; a new container only with rights), plugins (`/var/log/plugins`, the `<PLUGIN pluginURL>` host, on code hosts with the owner), the flash (go as line hashes, /boot/extra, users, a hash per shadow field, authorized_keys fingerprints), shares newly open to guests (sec.ini/sec_nfs.ini); scheduled and auto-starting things (group `sched`, T1053): root's own crontab against `/etc/cron.d/root` (only lines of a cron line's shape — five time fields or an @keyword, then a command, `WATCH_CRON_LINE`; dcron's reload signal `cron.update` — `crontab -c <dir> -` writes the user's name there, crond deletes it — is never a crontab, in both folders; `cron_new`, `cron_twice` — in both, they run twice —, `cron_office` — the office's job.sh lines there; lines whose program went with its plugin are Ms. Whereabouts', order not security (`cron_dead` was his up to 1.28; `watchmanLoad()` leaves out entries of kinds he no longer keeps); the spool file's mtime and the syslog lines ±2 min around it naming plugins/scripts/cron, scrubbed, as evidence; the undo command only as information — he never edits a crontab), other users' crontabs and `/etc/cron.d`'s other files (`cron_new`), the plugins' `.cron` files on the flash (`cron_file`; `cron_file_foreign` = folder of no installed plugin), User Scripts (`script_new`/`script_changed`: content hash, schedule.json), atd's queue (`at_job`: not marked `HOST_LAUNCH_MARK` by hostLaunch(), command after at's `cd … || {}` only — never its environment; a job that is exactly User Scripts' «Run in background» — `startBackground.php /tmp/user.scripts/tmpScripts/<name>/script` for an existing script, no other command, no LD_*/BASH_ENV/…, PATH and SHELL the system's — is `at_userscript`, a line noted by itself (`by` auto), once per job number), notification agents (`notify_agent`, a salted hash only); stat first (size, mtime, ctime from seen.json), read only on change. **The office's own** (`watchmanOfficeLook()`, by the consultant's root-only record `data/advisor/installs.json` — folder 0700, file 0600 of root's, `{installs: [{t, kind: plugin|container, id, name, url|image}]}`, the newest 20 within 30 days, written by `advisorRecord()` before his plugin job and when he prepares a form; trusted only in exactly that shape, `WATCH_OFFICE_KEEP` 7 days): a new plugin of exactly that name whose `/var/log/plugins` link appeared within `WATCH_OFFICE_PLUGIN` (900 s) after his record, the `/etc/cron.d/<that plugin's name>` file made then (ctime), a new container with rights of exactly the recorded name and image, label `uso.installed-by=consultant` and created within `WATCH_OFFICE_FORM` (2 h) — adopted into the baseline and one line noted by himself (`by` office, `p.installed_by`), never told; anything else stays reported. Findings: the watch book (500 entries, noted ones 90 days), the team lead's `checks` (one recommended per kind; his «I know, thanks» on it counts as noted at the next round), the important kinds to `officeNotify()` (per kind once an hour; switch `notify_set` like the team lead's, default on — `state.json` `notify`; entries that come while it is off are `muted`, never told later). «I know, thanks» (`ack`, `ack_all`) adopts that state into the baseline; what gets safer adopts itself. **Data flow** (group `flow`, in his round, `watchmanFlowLook()` outside the book's lock, `watchmanFlowCompare()` inside): per client and file service (SMB 445/139, NFS 2049, SSH and the WebGUI on var.ini's `PORTSSH`/`PORT`/`PORTSSL`) the bytes the server delivered — `ss -tinH state established` through `hostNet()`, tcp_info `bytes_acked` (else `bytes_sent`) diffed per connection (local+peer address:port; a smaller counter = a new connection), loopback left out; SMB from `smbstatus -b --json` (the table as fallback): `smb_user` (important), `smb_client`, `smb_hour` (a session started at an hour of the week ±1 that machine never used, after its learning time); containers from `/proc/<State.Pid>/net/dev` (all but lo; any network type; one namespace counted once under its first name; the host's network can't be separated — listed); ZFS `written,used,snapshots_changed` of every dataset of the awake ZFS pools/array disks (disks.ini, `baseAsleep()`; a sleeping pool keeps its counters), summed per share `pool/share` (a new snapshot: written since it; snapshots changed and it grew: that round left out; `-` = never a snapshot → only growth shows). The last round's counters live in RAM (`RUN_DIR/watchman-flow-<hash>.json`, stale after 30 min = start anew; a part not looked at keeps its counters), the history aggregated in `data/watchman/flow.json` (hourly sums 14 days, past hours < 1 MB dropped, capped: 64 client/service pairs, 100 containers, 200 shares; forgotten after 30 days unseen). Unusual (`flow_client`, `flow_container`, `flow_written`, all important): learned (7 days after first seen) — this hour so far > 4 × max(the most at that hour of the week ±1 h, a quarter of the busiest hour, the «I know, thanks» floor) and > 2 GB; still learning — one round > 50 GB (a share: > 20 % of its size, ≥ 1 GB). One open entry per key; rounds that follow update it (bytes, minutes, `peak`), a later one is a new episode (count + 1). While the engine's lock is held (backup, check, dryrun, restore) the Kopia container's traffic (`[kopia] container`), what goes into the backup place's share (`UnraidSecretaryOffice` / `dumps_share`) and, during a restore, every write are the office's own (`o`, never learned or told); media servers (image/name emby, jellyfin, plex) are learned, never told. «I know, thanks» raises that one's normal (`baseline.flow.ack[<entry key>]` = its peak) or makes the SMB user/machine/hours known. Not possible (page help): which files were read (Samba full_audit — too much for /var/log), where data goes on the internet (`nf_conntrack_acct`, off; he never switches it on). **Gone** (`flow_gone`, important): what vanished between two rounds — per user share the drop of ZFS `referenced` of its datasets on the awake pools (`usedbysnapshots` rising = what its snapshots keep; pools asleep named; a dataset renamed into the storeroom or a storeroom dataset destroyed is no loss), per awake XFS/btrfs disk or pool the drop of its used space (statfs; the shares with a folder there named; left out while its `.btrfs-snap` changed in the last 30 min — pruned snapshots free old deletions); learned like written, while learning told at one round ≥ `WATCH_GONE_NEW` (100 GB) or ≥ `WATCH_GONE_PART` (10 %) of the share; the entry says who moved data over SMB/NFS/SSH in that round or `server` (nobody). Expected, never told: the engine's lock held, or `watchmanFlowMovers()` saw this round or the last the mover (`mover`, `age_mover`), `embycache_run.py`, `consolidate_master.sh`, `rsync --remove-source-files`, `rm` of a storeroom (not unbalanced: its web server runs all the time). `metrics` hook: open entries per kind, the last round, `uso_watchman_sent_bytes_total{service}`, `uso_watchman_written_bytes_total{share}` (top 8). **Snapshots that vanish** (group `snap`, `watchmanSnaps()` outside the book's lock, `watchmanSnapCompare()` inside; only `watchmanRun()` passes `snaps: true`): round against round `zfs list -H -p -t snapshot -o name,guid,userrefs -r <the awake ZFS pools>` (disks.ini like the data flow, never the boot pool; Docker's layer datasets out; ≤ `WATCH_SNAP_MAX`, a pool beyond is not compared) and the snapshot folders (`.btrfs-snap` + the engine's `btrfs_snap_dir`) of the awake btrfs disks and pools; `data/watchman/snaps.json` (rounds only: the lists per pool/disk with their time, what the office removed, the position in agent.log). A pool asleep or zfs not answering keeps its last list — never «gone». No news: Ms. Snapshotini's own record, root only (`data/snapshot/deletes.jsonl`: folder 0700, file 0600, JSON lines `{t, do: deleted|released|renamed, …}`, appended under a lock by `snapshotRecord()`, `.1` beyond 1 MB, a record others could write is set aside `.untrusted-<time>` and begun anew; `watchmanSnapRecord()`) and her lines in the office's log (`Deleted: <ds>@<a>,<b>`, `Deleted: <path> (btrfs)`, `Released: <ds>@<name>`, `Renamed: <where> <old> → <new>` — logLine() in snapshot.php; their shape is his interface too), both read by offset/inode, kept `WATCH_SNAP_OFFICE`; the record wins — log lines count only until it is there (snaps.json `record`), never once a record she kept isn't one; the engine's retention (its own names, a real backup run between the two lists — status.json/history.jsonl, never its log —, a newer one of its own still on that dataset/folder), renamed (the ZFS guid still there: her rename, Mr. Restori's and Ms. Dustdevil's dataset renames), the storeroom, learned series («I know, thanks» on `snap_gone` adopts its series — the name with numbers as `#` — into `baseline.snaps.series`: may go while a newer one of the series stays). The rest: `snap_gone` (important; one open entry per pool/disk, count = snapshots; datasets, names, series, held, the pool's `zpool history -l` lines naming them since the list before — read only then — and the syslog around, `WATCH_SNAP_EVIDENCE`); a hold released (userrefs fewer) without her `Released:` line: `snap_hold_released` (important). **Grafana:** where the consultant saw Grafana running with the office's dashboard provisioned where it reads it (`advisorGrafanaDashboard()` — his state file, no docker call), the data flow's groups «who pulls» and «written into shares» link `<WebUI>/d/unraid-secretary-office?viewPanel=50|51` (`WATCH_GRAFANA_PANELS`; the tests check those panels show his metrics) — a link in a new tab, never embedded |
| `cleanup` | Ms. Dustdevil / Frau Putzteufel | clears away what nobody uses: Docker templates, Compose stacks, appdata folders, stray my-*.xml elsewhere, what deleted VMs left behind (domains folders, NVRAM, TPM, snapshot lists, unused disk images; VMs without disks are only pointed out), switched-off User Scripts that lie around, Docker's leftovers. Never deletes right away: renames into `_UnraidSecretaryOffice-trash` on the same filesystem (ZFS datasets with `zfs rename` next to it), `manifest.json` per run, put back or empty; Docker leftovers can only be removed. Only rename, never copy; nothing while a backup runs. **Mr. Restori's leftovers** (room `leftovers`, per restore): only what his journals name (`data/restore/<id>/journal.json`, read only from his root-only folder, `clRestoreJournals()`) in exactly his shapes with the restore's own time (`clLeftoverUnits()`: `aside` entries `.aside-`/`.putback-`/`.restored-aside-<time>`, his `_UnraidSecretaryOffice-restore/<time>` on the flash and in libvirt.img, the safety dumps' `<place>/restore/<app>/<time>/`; copy steps' `.restored-<time>`) — never a scan by name; each looked at cheaply (`clLeftoverPlace()`: parts per pool/disk through /mnt/user by the share's cfg, sleeping ones named, never looked at; libvirt.img only mounted); what his «Put back» still needs (`clRestoreUndoable()`) is «his way back» (put away only with the in-use warning); into the storeroom on its own filesystem inside its share (`clLeftoverTrash()`: the share's top or a dataset of its own inside it, never above the share; datasets with `zfs rename` next to it, kind `leftover`, `restore/<hash>/<name>` in the run), back only to his places (`clLeftoverHome()`); what his journals name is his room's only — left out of appdata/domains/isos exactly by path (`clWithoutLeftovers()`); his job file written after her last look (`restore_newer`, a stat from tick, `looked` = her last tour) makes her page look again once. **A dataset's size** (leftovers, appdata/domains folders, the storeroom's parked datasets) is ZFS's `used` with what its snapshots hold (`clZfsSpace()`: one `zfs list` per tour naming only those datasets, all on awake pools) — find sees only live files (a VM folder set aside: «0 B» though 17 GB in its snapshots). Missing pictures: containers without a picture on the Docker page/Dashboard (found like DockerClient::getIcon: template by Name+Repository, else the label; docker.json / question.png); logos from the CA feed, `CL_ICON_TABLE` and checked guesses — dashboard-icons URLs via jsDelivr, never bundled; set in the user template's `<Icon>` or the Compose Manager override (shape-checked, refused otherwise), old file to the storeroom (kind `icon`, put back only while unchanged), Unraid's cache filled; `cleanupIconLoopRisk()` feeds the caretaker's 7.3.2 check, stand-in question.png (RAM); local candidates (dockerMan/images named like the app, AppleTimeMachine.png for Time Machine) go to the page as data: previews in the state (PNG/JPEG ≤ 1 MB); uploads: the browser makes a ≤ 256×256 PNG, kept in `/boot/config/plugins/unraid-secretary-office/icons/<container>.png` (≤ 512 KB, set as file://, put back removes it while unchanged); `icon_url` from the stack's main app (`clIconMainRank()`) |
| `advisor` | The Consultant / Der Berater | an external: whether the tools the office relies on are there (Fix Common Problems, Files Viewer, a Kopia container, Stream Viewer where Emby/Jellyfin/Plex runs; unbalanced optional, never suggested — it can get in the way of backups, EmbyCache and the gather), what they are good for, who needs them, how to install them by hand (ADVISOR_EXTERNALS in agent/desks/advisor.php, EXTERNALS in his desk.js). Optional monitoring (group `monitoring`, never counted as missing): Node Exporter (container recommended, ich777's plugin counts; its textfile collector reads `/mnt/addons/UnraidSecretaryOffice/metrics`, he shows whether it does) → Prometheus (ready prometheus.yml command, never overwrites) → Grafana; Loki `later` (for the night watchman). **Installs, second offer after the manual way** (preview + confirm, never over what is there, detection by image): plugins with Unraid's `plugin install <url>` as an atd job (URLs pinned in ADVISOR_EXTERNALS `plg`, output read back by the open action `job`; never unbalanced); containers through Unraid's own Add Container form — his template (`public/desks/advisor/templates/<id>.xml`, from the CA template, names as CA's: `kopia`, `Node-Exporter`, `prometheus`, `Grafana`, label `uso.installed-by=consultant`) filled in into `RUN_DIR/templates/` and the form opened with `xmlTemplate=default:<file>`; the user clicks Apply, Unraid writes `my-<Name>.xml`; refused while a container of that kind, that name or a `my-<Name>.xml` exists. Beforehand, only where nothing is: prometheus.yml; Grafana's provisioning (`GF_PATHS_PROVISIONING=/var/lib/grafana/provisioning` in his template; data source uid `uso-prometheus`, the office's dashboard from `monitoring/grafana-dashboard.json` with its input filled in, the empty folders `plugins` and `alerting` Grafana logs errors for — `ADVISOR_GRAFANA_EMPTY`; for an existing Grafana the same files plus the one template change; `GF_SECURITY_ADMIN_PASSWORD` optional, Benj 2026-10-06: without it admin/admin, Grafana asks for a new one at the first login — the page warns about admin/admin for every Grafana). What he installs he notes in his root-only record for the night watchman (`advisorRecord()`, see there). His page looks again when his state is older than a minute (desk.json `refresh_after` 60; his scan ≈ 40 ms on nostromo) and, for `ADVISOR_RELOOK_FOR` after he prepared a form, his tick looks every `ADVISOR_RELOOK_EVERY` s (`docker ps`, `advisorRelookDue()`) whether the container came, then scans once. Kept current afterwards (no confirmation, `advisorDashboardKeep()` at his scan and hourly while Grafana runs, stat-cached, logged once): only `<provisioning>/dashboards/uso/unraid-secretary-office.json`, only while it is a plain file in a real folder whose JSON has the uid `unraid-secretary-office`, only when it differs from `advisorDashboardJson()` — new file + rename, owner/group/mode kept; Grafana reloads it and never saves provisioned dashboards. The data source and provider yaml stay only-when-absent. Kopia: `/uso` (ro,slave) and `/uso-restore` (`<share>/restore/kopia`, rw), PUID/PGID 0; the repository assistant (S3 or a folder, create or connect) takes the keys and password only after an explicit confirmation, through RAM (see the web-side exception above) to `docker exec -i … sh -c` on Kopia's stdin, restarts the container once, keeps only the non-secret facts; the recovery sheet is made in the browser only. **Ransomware protection (S3 Object Lock):** before a new repository in S3 the assistant asks the bucket (`kopia_repo` with `step: probe` — the keys through RAM like the setup; `advisorObjectLock()`: one `GET ?object-lock`, AWS Signature V4 made in the agent (`advisorS3Sign()`), PHP's curl, HTTPS only, certificate checked, no proxy, no redirect; Amazon virtual-hosted and signed again for the region the bucket names, elsewhere path style): `enabled` → offers COMPLIANCE for N days (`ADVISOR_LOCK_*`: 30, 7–365; preselected — the bucket was made with it on purpose; with the cost note), `off` → where providers switch it on (a bucket made with it), `unsupported` (MEGA S4 answers 501; `ADVISOR_NO_LOCK` for an unclear no) → said plainly, no option, `unknown` → why (keys, denied, no_bucket, unreachable, other), no option. `lock_days` on the create: the bucket is asked again (not enabled → `ad_lock_off`, nothing created), Kopia gets `--retention-mode=COMPLIANCE --retention-period=<n>d`, then `kopia maintenance set --extend-object-locks=true`. Create or connect, `advisorKopiaLock()` reads the repository's lock (`repository status --json` → `blobRetention`, `maintenance info --json` → `extendObjectLocks`) for the done dialog and the recovery sheet (its line, and `--point-in-time` after an attack). «Install by hand» for Kopia ends with «Ransomware protection with Object Lock» (what, why, who offers it — not MEGA S4 —, how, the price, MinIO on a second server, after an attack). He never logs into a web UI or an HTTP API: files and command lines only |
| `restore` | Mr. Restori / Herr Restori | brings apps and VMs back (calm restorer in white gloves, «Piano, piano»; never over something that is still there without asking, everything he replaces is put aside first, never deleted). Reads: per app/VM what comes back from the package (and earlier nights from the backup place's snapshots), local snapshots and Kopia, chips grouped «Package» / «Data» (a package in Kopia is not the data), ready-made commands, the Kopia guide, onto a new server; he reads the packages himself (`rsPackages`). Tile «Databases»: every dump and SQLite copy of all packages per app (`dbGroups()` in desk.js from the state's apps; one media server's copies are one entry, they go back together), where the package is kept (`package_protection`, `rsPackageProtection()`), «Restore…» opens the existing `db`/`sqlite` plans, earlier nights from `restore.versions` (which carries `sqlite` too). Restores: every restore is planned against a fresh look (`rsPlan` → preview: steps, what goes aside where, what stops how long, sizes — `du` in his tick on the source as `<path>/.` (a ZFS snapshot not mounted yet), allocated and apparent (sparse files said so); a ZFS source counts with its snapshot's logicalreferenced/referenced where the target doesn't compress; copies with rsync `--sparse`), confirmed with the plan's token (`rsStart`; a changed server = `restore_changed`), run by atd as `php agent.php job restore <id>` (`rsJob`). The job takes the engine's `state/lock` non-blocking (busy → journal `refused`, `restore_busy_<holder>`, exit 75), writes `state/lock-holder.json` `{holder: restore, mode: <kind>, what, pid, started}` (new file + rename, removed only while its pid), journals each step in `data/restore/<id>/` (plan.json, journal.json, log.txt, root only) and `data/restore-job.json` (page polls api part `job`, the Dashboard shows a row while its heartbeat is fresh). Stops at the first failed step; each step records its undo, «Put back» (`kind putback`) runs those newest first between the restore's own container handling — also a job, also puts aside (`.putback-<time>`); the restore's journal then says when and where its state went instead of «Afterwards». «Put back» is only the undo — the package's buttons say «Restore…». Kinds: `db` (safety dump into `<place>/restore/<app>/<time>/`, the app's other containers stop; Postgres the fresh way when the cluster's folder can be put aside — container stops, folder aside (dataset: `zfs rename`, new dataset with its local properties), starts on an empty folder, ready over TCP, dump in, tables checked; Immich only that way, its search_path line replaced while streaming; else in place after ending other connections; MariaDB/MongoDB in place; credentials only as variable names from `RS_ENV_VARS` in `sh -c` inside the container), `sqlite` (a media server's copies, -wal/-shm aside), `files` (a folder unit — or a whole share an app binds, all of it or chosen entries at its top, ≤ `RS_ENTRIES_MAX` — from a *moment*: snapshots grouped by run (the engine's, on all of a share's pools and disks at once, ZFS and btrfs alike) or by name (others, maybe on some parts only — the list says which, a part with content the moment doesn't cover is warned); the source is the union of the parts holding it (primary storage first: rsync with several sources, the first wins a name, like shfs shows it), the default the newest moment that holds something (a sleeping part: «wake», never «nothing there»; ticked, `rsWake()` reads one block from each disk of the share's sleeping parts and waits ≤ `RS_WAKE_TIMEOUT`, one that doesn't answer stays asleep — `restore_wake_failed`; the dialog keeps every choice across re-plans, each control sends only its own field); the target is the share, `/mnt/user/<share>/…`, so Unraid places it by the share's settings (an exclusive share: the link to its pool; room = primary + secondary from disks.ini less the minimum free space): copy to `<name>.restored-<time>` or swap — copy first, then stop its users (a whole share: what binds the share too), the live one aside through shfs (all parts at once), copy in; nothing there (an empty share, a folder that is gone or empty — an empty one goes aside too) → «put in place», the default; a ZFS dataset of its own on one pool stays one (zfs rename on its pool); refuses swap with datasets inside or an own mountpoint; never swaps or puts back a folder holding the disks (not CD-ROMs) of a VM that isn't shut off (`restore_vm_uses`; libvirt's disk sources however the path is written; the job's step `vms_off` looks again right before anything is replaced — he never stops a VM); a dataset of its own put aside takes its snapshots along (said so), they stay offered as moments `aside:<folder>@<snap>`; through /mnt/user any sleeping part of the share blocks without `wake`; a missing share blocks — Unraid creates shares, never he — with its old settings from the package's `server/shares/<share>.cfg` as information only (pool names may not exist here, said so)), «Shares it needs» per app and VM (there / empty / missing / asleep — emhttp's shares.ini and the top folder on the awake parts; data restores into a missing share are disabled, also listed in «Onto a new server»), `config` (templates/compose files that differ or are missing; aside on the flash into `/boot/config/_UnraidSecretaryOffice-restore/<time>/`, elsewhere `<file>.restored-aside-<time>`; never starts or recreates containers — says what to click), `vm` (XML, NVRAM, TPM only while shut off; aside in `/etc/libvirt/_UnraidSecretaryOffice-restore/<time>/`), `kopia` (into a writable rw mapping of the Kopia container named *restore*, as the server's UID like the engine's `kopia_x`; without one he explains the template path; the list shows what a snapshot holds, `rootEntry.summ`, not `stats.fileCount` — the files read anew). One restore at a time; Mr. Backupsy refuses (`restore_running`) and says so, Ms. Dustdevil refuses (`cleanup_restore_running`). His `fit` (`rsFit()`, state files only): `packages` (his last look, or last-run.json's `packages` when written after it) / `no_packages` (set up, none yet) / `with_backup` (ZFS/btrfs, nothing yet: hire with Mr. Backupsy) / `nothing` — never snapshots alone (his page has nothing without packages) |
| `caretaker` | The Team Lead / Der Teamchef (until 1.23: the caretaker / der Hauswart; the id stays `caretaker`) | leads the team (hires, fires, suggests whom to hire); collects every desk's `checks` and what the office needs; tells the user what is left to do; reports new red findings to Unraid's notifications (see Notifications); «I know, thanks» (`ack`/`unack`) puts aside recommended findings and hints — never musts — in `data/caretaker/acks.json`, keyed by sig = desk:id:hash(level, params without `days`/`size`); noted ones count nowhere (bubble, mood, reception, Dashboard tile); forgotten once in place or unseen for 30 days. Params that grow on their own must be named `days`/`size` |

Desks know each other only through shared libraries (`backupProtection()`,
`finding()`) and links (`#/<desk>`) — each one must work on its own.

**Security is the night watchman's** (2026-10): how secure the server stands (his posture tips) and what
changed (his watch book, the data flow). No other desk gives security advice — Ms. Whereabouts' tips are
operational (order, not security: a cron line of a removed plugin is hers) and point to him.

**Staff:** a fresh office has only the caretaker (desk.json `"always": true`).
Every other desk declares in the agent whether it suits the server
(`'fit' => fn () => fit(bool, why, params)`, texts `fit.<why>` in its own lang
file, told by the caretaker). The caretaker suggests whom to hire; hiring
(`office.hire`, src/staff.php → data/office/staff.json) shows
the desk in the tabs and at the reception, firing hides it again — data and
whatever it set up on the server stay (`fire_note` says what keeps running).
Unhired desks get no write actions (`not_hired`) and their checks don't count.
After hiring, the tip jar (core.js `tipJar`, link `OFFICE_TIP_URL`) says hello — and that a share of the tips goes to helmi1987, the author of Jack Emby's tools (`office.tip_credit`), and what goes beyond our work's cost to animal shelters (`office.tip_shelter`, Benj).
**Supporter key** (Benj, 2026-10-06: no licence, no paywall, nothing ever locked): a thank-you for a tip that unlocks
nothing — only the reminders ask it, never backups, restores or a desk (`src/supporter.php`, web side like staff.php).
Server ID = sha256("uso-supporter:" + upper(regGUID, else flashGUID)), first 16 hex as `XXXX-XXXX-XXXX-XXXX`, from
var.ini like the csrf token (the GUID never leaves the server). Key `USO1.<b64url(payload)>.<b64url(DER ECDSA P-256/SHA-256
over "USO1.<b64url(payload)>")>`, payload `{"v":1,"id","name","date"}` in that order, checked as transmitted with
`openssl_verify()` against `OFFICE_SUPPORTER_PUBLIC_KEY` (the exact rules in supporter.php's head; `OFFICE_SUPPORTER_PUBKEY`
= a PEM file, tests only). Kept in `data/office/supporter.json` (0600, `officeWriteAtomic()`, also `first_seen` and the
team lead's ask), actions `office.supporter_set|remove|ask`, the page gets `CONFIG.supporter`. A valid key: no tip jar
after hiring, the tip jar thanks, «☕ Thank you, <name>» at the team lead's «The team». Without: his one ask, a callout
under «The team» 7 days after `first_seen` («Not now» = 30 days, at most twice more; «Don't ask again») — never a modal,
never at the reception or on the Dashboard. Keys come from the support page (`OFFICE_SUPPORT_URL`, opened with
`?id=<server ID>&lang=<language>`, shows the key after the tip) or by hand: `tools/supporter-key.sh <server-id> "<name>"
[YYYY-MM-DD]` (private key on Benj's Mac only, `~/.config/uso-supporter/`; never in the repo or on a server).
`OFFICE_SPONSOR_URL` adds a GitHub Sponsors button.

**Pictures:** every desk has its own drawing, `public/desks/<id>/avatar.svg`
(64×64, flat, thick shapes, outlined where a light part meets a light theme;
the reception's is `assets/reception.svg`, a desk bell filling its square; the plugin icon in
`plugin/images/` is that bell with a "USO" plate (Benj's pick; too small for the reception's tabs and chips), its 128×128 PNG rendered on the Mac with NSImage
— JXA: `NSImage.alloc.initWithContentsOfFile(svg)` drawn into an `NSBitmapImageRep`, saved as PNG). `Office.deskIcon(id)` /
`Office.avatar(id)` show it in avatars, tabs and chips; desk.json's `icon`
emoji is only the fallback. A desk may change it with its state:
`Office.setDeskMood(id, mood)` shows `avatar-<mood>.svg` (the caretaker:
`advice` yellow, `todo` red, otherwise the green check — set from his
findings, loaded on every page through the desk hook `started()`). Never use an emoji for a desk where a drawing
exists.

**Characters:** Frau Snapshotini (Italian), Herr Backupsi (scatterbrained,
anxious, checks everything three times), Frau Wasistwo (nosy gossip), Jack
Emby (the intern), Frau Protokolli (reads everything out, understands nothing),
Frau Putzteufel (sees dust everywhere, but never throws anything away at once —
"man weiss ja nie"), the team lead / der Teamchef (plain and friendly, knows his people), Herr Restori (calm restorer in white gloves, «Piano, piano», apprentice with Herr Backupsi), the consultant (an external,
consultant speak: "quick win", "best practice", the hour runs anyway). In Italian:
Signora Snapshotini, Signor Backupsi, Signora Dovè, Jack Emby (lo stagista),
Signora Protocolli, Signora Spolverina, Il capoufficio, Signor Restori, Il consulente
(addressing the user with "tu", like the German "du"). In French / Spanish:
Madame / Señora Snapshotini, Monsieur / Señor Backupsi, Madame Saitout / Señora
Dondestá, Madame Protocolli / Señora Protocoli, Madame Plumeau / Señora Plumero
(storeroom débarras / trastero), Le chef d'équipe / El jefe de equipo, Le
consultant / El consultor, Monsieur / Señor Restori, Jack Emby le stagiaire / el
becario (tu / tú). Greetings are lang keys
`greet.1…n` (`Office.greet` / `Office.withGreeting`); chatty bubbles may carry
character, warnings and errors stay plain and clear.

## UI conventions (every desk looks and behaves the same)

* **Inside Unraid:** everything the office shows lives in `#sso` (src/page.php);
  office.css and every desk.css nest all their rules in `#sso{ … }` (CSS
  nesting), so nothing leaks out and, under the id, Unraid's rules don't leak
  in. A reset at the top (`#sso :where(p, button, input, … span.warn …){all:revert}`)
  undoes Unraid's styles for bare elements; it sits below every rule of ours.
  Don't use Unraid's `unapi` class (it switches on Tailwind utilities like
  `.grid`). Dialogs, menus, the selection bar and tips are appended to `#sso`,
  never to `body`. Links into Unraid's own pages (`/Docker`, `/Plugins` …) open in
  the same tab, like Unraid's own. The office
  follows Unraid's language (`unraid_lang`; ⋯ → Language per browser).
  Buttons inside Unraid: its theme's frame, plain letters (no capitals).
  Colours are tokens (`--ink`, `--surface` …) mixed from Unraid's theme
  variables (`--text-color`, `--background-color`, `--button-background` …), so
  black, white, azure and gray all work — never a hard-coded colour. Buttons,
  tabs and section title bars look like Unraid's (the `.in-unraid` rules; `#sso`
  always carries that class).
* **Head:** `Office.deskHead(...)`, then right after it
  `Office.pageHelp(ID, [[term, text], …])` — "How to read this page", folded
  by default, remembered per desk. Explanations of labels, buttons and tiles go
  there, not into repeated legends on every list.
* **CSS names:** desk classes carry the desk's prefix (`jo-`, `lg-`, `bk-` …);
  never reuse a name office.css already styles (`.empty`, `.card`, `.row` …) for
  something else — a button with `.empty` got 34 px padding.
* **Remembered per browser** (`Office.store`, prefix `office.`): filters,
  chosen tiles, favourites. Tests in the browser save and restore those keys —
  the user's own pane shares them.
* **Sections:** `Office.sectionHead(title, explanation, ...extras)` — a title
  bar (Unraid's inside Unraid) with counts, status and buttons on its right, the
  explanation right under it. No explanatory text floating on the right.
* **Tiles** (`button.card`): a click opens or filters; a click on the active
  tile closes it again — nothing open is a valid state. Remember the choice in
  `Office.store`.
* **Rows** with one main action (unfold details, open a log): the whole row is
  clickable (`.row.unfolds`), except its own buttons, links, fields and
  elements with `data-own`. The "details" tooltip sits on the name only; every
  chip carries its own `title`. Lists that unfold offer "Unfold all".
* **Chip explanations:** a chip's `title` (or `data-tip` on anything) becomes
  a bubble (core.js `initTips`): on hover with a mouse, on click or tap
  everywhere. That click belongs to the chip and never folds the row under it.
  Chips that do something themselves (own `onclick`, `data-own`, inside a
  button/link/label) keep their click and explain on hover only.
* **Folding never makes the page jump:** wrap every fold/unfold (rows, groups,
  tiles that open or close a section, "Unfold all", re-renders after a toggle)
  in `Office.keepInPlace(anchor, change)` — the clicked element stays where it
  is on screen; space that vanished below is given back as the user scrolls up.
  Exception: selection lists (snapshots) — selecting means deleting, so only
  the checkbox selects.
* **Subheadings inside a list** (a compose stack, a group) are a tinted title
  bar (`var(--surface2)`, like `.group-head`), the rows under it slightly
  indented — never just bold text between rows.
* **Backup protection** is always shown with `Office.backupChip(level)`
  (offsite / only local / not backed up), the level coming from
  `backupProtection()` in agent/lib/backupscript.php.
* **Names in German** read naturally (Frau Snapshotini, Herr Backupsi, Der
  Teamchef); a desk can set its reception button with the lang key `visit`
  ("Zum Teamchef"), otherwise the office's "Visit {name}" is used.
* **Say what is really there:** detect it (boot from a USB stick or a boot
  pool, license bound to the stick or the TPM, a VM disk sitting on a snapshot
  overlay) instead of describing the common case. Restore texts must be
  complete and honest.

## Server facts that bite

* **Which disk a file is on:** `getfattr -n system.LOCATION` (Python
  `os.getxattr`) on a `/mnt/user/…` or `/mnt/user0/…` path gives `disk2`,
  `master` … (`system.LOCATIONS`: all of them) — no look at other disks.
* A share's split level is `shareSplitLevel` in `/boot/config/shares/<share>.cfg`
  ("top level only": files go to the disk where their folder already exists,
  even an empty one — Jack Emby keeps such folders as signposts).
* On a ZFS pool the top folder only sees its own (nearly empty) dataset:
  for how full the pool is take `zfs list -Hp -o used,avail <pool>`.
* **Exclusive shares** (Settings → Global Share Settings → Permit exclusive shares, `shareUserExclusive` in
  `/boot/config/share.cfg`, changeable only with the array stopped): `/mnt/user/<share>` is then a **symlink**
  `../<pool>/<share>` to `/mnt/<pool>/<share>`, past shfs — for a share whose primary storage is a pool, secondary
  none, and whose top folder exists on that one volume only (an empty folder of that name on another disk or pool is
  enough to prevent it). emhttpd decides at array start; shares.ini says `exclusive="yes|no"`, never why (Ms.
  Whereabouts' `waExclusive()`). On nostromo most pool shares are (appdata, system, domains …). Code that refuses
  links must allow exactly this one — `/mnt/user/<share>` → `../<pool>/<share>` or `/mnt/<pool>/<share>`, the same
  share name, a real folder there, like `advisorUnraidPath()` in agent/desks/advisor.php; `realpath()` gives the pool
  path, `is_dir()` follows the link, `lstat()`/`is_link()` don't.

* **shfs across pools (7.3.2, tried on drop: hive + mother):** a `rename()` through `/mnt/user/<share>/…` renames the folder
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
  `scripts/commands.sh update_user_script`, older ones) keep whatever is in it: on nostromo it once held a full copy of
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
  `disks.ini`); `disk1` is not `disk10`.
* Images pinned by digest (`image: x@sha256:…`) don't show up in
  `docker image ls`; take the image ids from `docker inspect` of the
  containers. `docker system df` takes seconds — not in a `refresh`.
* Searching a media pool deeper than 3 levels lists every episode (minutes):
  keep searches shallow, prune `*.sparsebundle`, never the array.
* Backups keep copies on purpose (the office's share, anything called
  "backup", e.g. `my-*.xml` in a backup's `templates-user`): never treat
  them as leftovers.
* **Ms. Dustdevil's storeroom:** `_UnraidSecretaryOffice-trash` folders (in
  shares, on the flash, in `/etc/libvirt`) and datasets named
  `_UnraidSecretaryOffice-trash-<stamp>-<name>` hold things put away, with a
  `manifest.json` per run. Other desks list them as what they are (or skip
  them) — never as a share's own folders, and nothing but her writes there.
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
  `/backup-snapshots/<share>` on nostromo and older installs; the engine reads the real one from the
  container's mapping), not the host path: changing only the Host Path keeps their history; changing
  the Container Path makes new sources (same repository: content deduplicated, but every file read
  once more; the old sources keep their history under the old names until removed in Kopia); a new
  repository (bucket) starts every source with a full upload — no dedup across repositories.
* Unraid's `notify` names a notification `<event>-<second>`: a second one with
  the same event in the same second is dropped. Line breaks in `-m` are a
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
  `docker exec`): it reads the files' own sizes (sparse holes too — a little past `logicalreferenced`). Not `wchar` (what it sent:
  half of it for nostromo's 2.36 TB on 2026-10-06 — compression, content the repository had), not the files it has open (it
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
  else is saved as rw (CA's Node-Exporter template says `ro,rslave`: nostromo's `/host` became rw). Config
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
2. JS syntax (no Node on the dev Mac): `osascript -l JavaScript` with `new Function(src)`.
3. Tests on the host: `php tests/run.php` (logic: cron, snapshot retention,
   Emby detection, the plugin's cron file — on copies; strings: `en`/`de`/`it`/`fr`/`es`
   keys identical, every language against English, every T('…'), check and error text exists).
   Must end with 0 failed.
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

## Layout

```
agent/agent.php          loop, mailbox, desk loading, self-restart
agent/lib/*.php          shared helpers (util: run, writeAtomic, readCfg, Problem;
                         mounts; backupscript; house: plugins, containers, finding)
agent/desks/<id>.php     one desk each: desk('<id>', [...])
src/*.php                web side: bootstrap, mailbox client, desk/lang discovery, staff, API, page, Dashboard tile
public/assets/core.js    Office: i18n, routing, reception, API, dialog, menu, toast, fmt,
                         deskHead, pageHelp, sectionHead, backupChip
public/desks/<id>/       desk.json, desk.js, lang/*.json (and desk.css, avatar.svg)
data/                    runtime only (state per desk, mailbox, agent log, office/staff.json) — not in git
backup/                  the backup engine: backup.sh, setup.sh, lib/common.sh (data in data/unraid-backup)
embycache/               Jack Emby's EmbyCache (Python; data in data/embycache)
gather/                  Jack Emby's media gather, consolidate_master.sh (bash; data in data/gather)
monitoring/              grafana-dashboard.json: the office's dashboard to import (its numbers: lib/metrics.php)
support-worker/          the support page OFFICE_SUPPORT_URL points to — a Cloudflare Worker, NOT in the plugin:
                         tips (PayPal; bank/Bitcoin on trust), signs supporter keys (format as src/supporter.php),
                         lost keys; worker.js, wrangler.toml, SETUP.md (de/en), test.mjs (node, fake PayPal)
plugin/                  the Unraid plugin: .plg template, build.sh, dev-sync.sh, scripts/ (agent.sh
                         service, job.sh for the cron file), event/ (started, stopping), images/,
                         SecretaryOffice.page (the office in Unraid), SecretaryOfficeButton.page
                         (the header button), SecretaryOfficeDashboard.page (the Dashboard tile),
                         README.md (the short text Unraid's Plugins list shows); images/ has the
                         plugin icon as PNG with its SVG source (rendered on the Mac via NSImage)
.github/workflows/       plugin.yml: builds and attaches .plg/.txz when a release is published
```
