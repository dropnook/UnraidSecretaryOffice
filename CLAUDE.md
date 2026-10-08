# Working on Unraid Secretary Office

Read README.md first (what the office does, for users), then docs/DEVELOPMENT.md — it explains the architecture and how a desk (secretary) is built.
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
(`scripts/agent.sh`, started by `event/started` and at install/boot while the array runs — fsState `Started`,
`Formatting` or `Clearing`: `ARRAY_RUNNING` in agent.sh and agent.php, one definition with the engine's, a test compares
them; job.sh asks agent.sh's `array_started` — stopped by `event/stopping`) on the host. While the array isn't started (after a stop; from boot until
the first start — an encrypted array waits for its key) `agent.sh` runs the night watchman's
**night shift** instead (`php agent.php nightshift`, RAM and flash only; never both at once — see
the watchman's row); the event scripts call `agent.sh array stopping|started`. While it is on, the page and the
Dashboard tile say so (2026-10-07): `agentInfo()['night']` from `officeNightShift()` (src/mailbox.php) — the pid in
`WATCH_NIGHT_LOCK` living as `agent.php nightshift` (never the lock itself: the night shift's non-blocking lock at its
start must not meet a look from the web) and `WATCH_NIGHT_DIR/state.json` `night` (`since`, `rounds`, `new` = open
entries new in the night, counted by `watchmanRound()`), RAM only; no state (no mirror, no round yet) = nothing said.
The page gets `CONFIG.agent` at once: a calm `notice info night` with his lantern, «Night shift» and an orange dot by
the messenger's word; without the data folder only the reception (no desks, no tabs, no «not hired» toast, the address
kept), which looks again every minute (`api.php?a=agent`) and reloads once the folder is back. The tile: a row of his
(since, rounds, what is new; orange when something is). The data folder is `DATA_DIR` from
`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
(default `<appdata>/UnraidSecretaryOffice/data`; `OFFICE_DATA_DIR` overrides it —
the tests use the repository's `data/`, and `OFFICE_WEB` its `public/`). When it lies in an **exclusive share** the
agent and the web side read and write it on the pool directly (`DATA_DIR` / `OFFICE_DATA` = `officeUnraidPath()` of the
path as set, past shfs: 4 instead of 200 µs per look on nostromo); `DATA_DIR_USER` / `OFFICE_DATA_USER` keep the path
as set for what the user reads and what other programs get (the engine's `UB_DATA`; `dataPathUser()`). The agent decides
at its start (= every array start, where Unraid decides exclusivity; never in the night shift) and looks again every
minute (`dataWayLook()`: changed twice in a row → it restarts in place). The office's folder in RAM is
`officeRunDir()` (src/place.php, `/var/run/unraid-secretary-office`, the agent's `RUN_DIR`; `OFFICE_RUN_DIR` for the
tests — they never touch the live one, see the checklist).
Development: a git clone anywhere, `plugin/dev-sync.sh` puts it into the
installed plugin (see the checklist).

## Conventions

* **English** in code, comments, commit messages, README. The UI is translated:
  every string lives in `public/lang/<code>.json` (office) or
  `public/desks/<id>/lang/<code>.json` (desk). `en`, `de`, `it`, `fr` and `es`
  must always have the same keys — a new UI string needs all five; any other language may
  leave keys out (English fills in) but never has keys `en` lacks, and
  placeholders `{…}` and plurals match `en` (tests/run.php checks it). The office speaks
  the browser's language (Benj, 2026-10-07: many keep Unraid in English); Unraid's own
  labels in the texts are marked `⟦English label⟧` and read in the language Unraid runs in
  (see «Words»). French `one` covers 0 and 1 (Intl): write `{n}` in
  French one-forms where 0 can occur.
  **Counts are plurals** (QA 2026-10-08: «1 warnings», «1 alerts · 9 warnings unread»): a text whose noun (or verb,
  pronoun, participle — «1 container has … give it», it «1 spostato») changes with a number is `{"one": …, "other": …}`,
  chosen by the param `n` (core.js `t()`, `officeNotifyText()`, `officeDashT()`; pass `n` beside a placeholder of
  another name, `Number(…)` when it may come as a string); never «file(s)». A text with **several** counts is composed:
  each count is a key `count.<what>` of the desk (`{"one": "{n} warning", "other": "{n} warnings"}`), asked for with the
  desk's `nOf('<what>', n)` (backup, cleanup, emby) or `T('count.<what>', { n })`, and the text keeps only its glue
  (`"counts": "{errors}, {warnings}"`, `"where.stat.notices_sub": "Unread: {alerts} · {warnings}"`). Counts that never
  reach 1 (Jack Emby's «every 2–12 hours», a chain of ≥ 2 entries, the lock's 7–365 days the office offers) may stay
  plain. `testStrings`: a plural in English is one in all five, `count.*` are plurals of `{n}`, no «(s)» behind a
  placeholder, every `nOf()` key exists.
  Never hard-code UI text in JS or PHP.
* **No build step, no dependencies.** Plain PHP 8.4 (Unraid's own PHP runs both
  the page and the agent — use nothing newer), vanilla JS, one CSS file plus optional
  `desk.css`. The look follows levelnext Airdrop (tokens in `office.css :root`).
  The only "build" is the plugin package (`plugin/build.sh`).
* **The web side never touches the host** (even though Unraid's php-fpm runs as
  root). Everything that needs zfs, docker, /boot/config, /proc etc. goes
  through the agent (`Office.api.post('<desk>.<action>')` → `agent/desks/<desk>.php`).
  Exceptions: while the agent is gone (the array stopped) the web side reads the night shift's RAM state itself
  (`officeNightShift()`, see «How it runs»); and secrets the user types for the agent (the Consultant's Kopia
  setup) never go into the mailbox (it lies on the pool) — `apiSecretStash()` (src/api.php)
  puts them into a 0600 file in `officeInboxDir()` (`/var/run/unraid-secretary-office/inbox`,
  RAM), the request carries only its name, the agent reads and removes it at once
  (`advisorSecretTake()`). Only actions in `OFFICE_SECRET_ACTIONS` may carry a `secret`.
  In the agent's RAM folder the web side also reads the agent's heartbeat (`agentRecord()`) and **rings its
  doorbell** after dropping a request (2026-10-07): one byte into the FIFO `RUN_DIR/doorbell` the agent makes at every
  start (`doorbellOpen()`: anew, 0600, its own, never through a link) and waits on between its rounds (`agentNap()`,
  `stream_select()` with the round's 150 ms as the limit) — `agentRing()` (src/mailbox.php) writes only such a FIFO
  (lstat, then fstat of the handle: the same inode; opened read+write so it never waits for a reader, non-blocking: no
  doorbell, no reader, a full one — nothing happens, the agent's next round finds the request). Of the agent's files
  it writes that one only (its own there: the look locks, see «Show first, then look»); never a signal, never a
  process. It then looks for the answer after 2, 4 and 8 ms, then every 10 ms
  for two seconds, then every 50 ms: a request costs ~2 ms on top of the agent's own work instead of ~100.
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every
  action re-reads the current state and validates ids against it. Errors are
  `throw new Problem('key', [...])`; the UI translates `errors.<key>`
  (desk-specific keys in the desk's lang file). **Request fields are read, never cast** (QA 2026-10-08): `textField()`,
  `optText()` (absent → a default, a string, else `bad_request`), `idList()`, `boolField()` (true/false said),
  `cronField()` (a line, or null/false/"" for «off» — a missing key is no «off») in agent/lib/util.php; the web side's
  `apiText()` (src/api.php). Never `(string) ($r['x'] ?? '')`: an array becomes «Array» and a warning, and never let a
  missing key or a wrong type change a setting to «off» or a default (a form the page sends whole needs its parts). The
  agent reports every PHP warning (serve(): `error_reporting(E_ALL)` — Unraid's php.ini has 22517, which hides them;
  `agentPhpError()`: `@` still silences, the same one once an hour in agent.log); the web side keeps Unraid's level (no
  log of its own — it would write Unraid's /var/log/phplog). `testRequestTypes`, `testStrictSettings`.
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
  keeps nothing open there (its doorbell and heartbeat lie in RAM, `RUN_DIR`). The night shift (runs while the array is stopped) never opens
  anything under `/mnt`: cwd `/`, its files in `RUN_DIR/nightshift`, paths from `watchmanNightPaths()`.
* **Keep `tick` functions cheap** — they run every ~150 ms. Long work (like `du`)
  runs as a background process polled from `tick` (see the sizes of Ms. Dustdevil's «Where is what»).
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
  runs, agent gone); texts via `officeNotifyText()` in the language the office was last used in
  (`officeNotifyLang()`: `data/office/lang.json`, which the page writes with `office.lang` when it shows
  another language than kept; the night shift reads its copy in RAM, `RUN_DIR/notify.lang`, kept by the agent's
  `officeNotifyLangKeep()` once a minute — none, e.g. after a reboot: Unraid's language), Unraid's labels in them
  in Unraid's language; keys `notify.*` in the desk's lang file; tests set
  `OFFICE_NOTIFY_BIN` to a stand-in (and `OFFICE_NOTIFY_STAMP` to a stamp of their own — with a stand-in and none
  named the RAM stamp is never touched). The agent, the night shift, agent.sh (`tell`) and the engine (`ub_notify`)
  take turns through one stamp in RAM (`OFFICE_NOTIFY_STAMP` = `RUN_DIR/notify.second`, engine `UB_NOTIFY_STAMP`):
  the second the last call ended in, under its flock held through the call (≤ 10 s waited, then it goes anyway; the
  notify script never inherits it) — see Server facts. The caretaker runs every hired desk's
  `checks` every 30 min even without a browser (in the agent's tick) and
  reports new red findings once after 30 min (`data/caretaker/notify.json`,
  switch `notify_set`) — so checks must stay cheap and must not wake disks:
  when the disk/pool behind a path sleeps, return `ok = null` (not looked).
  `agent-watch.cron` (written by `agent.sh start`, removed with the
  plugin) runs `job.sh watch` every 5 min: the agent's heartbeat older than 70 s for 10 min
  with the array started → one alert, and a normal notification when it is back. **The heartbeat lives in RAM**
  (2026-10-07): `RUN_DIR/agent.json` (running, pid, start, version, desks — `writeInfo()`; its mtime the pulse every
  20 s, `agentPulse()`, never through a link); the data folder's `agent.json` is written only when its content
  changes (start, stop), so nothing of the agent lands on the pool every 20 s (an appdata pool of HDDs may sleep). The
  web side (`agentRecord()`: `agentInfo()`, the tile, `askAgent()`'s restart check) takes the newer of the two (an
  agent up to 1.32 touched only the data folder's); `agentUp()` is the one rule for «at work» (said it runs, a sign of
  life within 70 s): a request waiting while the heartbeat says otherwise is taken back after `AGENT_AWAY_GRACE` (4 s —
  a restart after a code change takes about one) and answered `agent_away`, never after api.php's 600 s (a php-fpm
  worker each; QA 2026-10-08); the desks' buttons that lead to an action are disabled while `Office.agent.running` is
  false, also those in callouts; the watch reads the RAM copy and looks under /mnt only when it is
  stale. The night shift never touches either and runs only while the array isn't started (the watch looks at
  nothing then), so neither the watch nor the Dashboard tile takes it for the agent.
* **Jobs that must outlive the agent** (backup runs) go through the host's
  `atd` (`backupLaunch()` in agent/desks/backup.php), never as a child of the
  agent: `agent.sh stop` ends the agent's whole session (array stop, plugin
  update).
* **Schedules** (nightly backup, snapshot plans, EmbyCache, the gather) only through
  `officeJobSchedule()` / `officeJobSetSchedule()` (agent/lib/house.php): a line in
  the plugin's cron file (`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`,
  calling `scripts/job.sh <job>`, which runs only while the array is
  started). Never User Scripts for the office's own jobs (User Scripts are the user's: Ms.
  Dustdevil, Jack Emby and the night watchman only look at them).
* **The backup engine** (`backup/`, bash, English since 2.13) runs on its own via the plugin's cron file. Mr. Backupsy only uses
  its interface (`backup.sh --about`, `data/unraid-backup/state/status.json` &
  co., interface 1; the setup assistant uses `setup.sh --plan` / `--apply`
  with `state/setup-plan.json` / `setup-status.json`). Never parse its log
  lines for anything new — extend the interface; reasons go out as codes
  (why/ctwhy) so the office can translate them — setup.sh's messages too, where the office should say them itself
  (engine 2.34: `hint_code`, messages[] `code` + `params` → `setup.msg.<code>`, desk.js `setupMsgText()`; the server's
  name from ident.cfg, `ub_host_name()`, never settings.ini's `[general] server`). Version lives in backup.sh, setup.sh, lib/common.sh and
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
  rules the engine adds itself. Kopia phase order (engine 2.25): flash, apps, then shares and VMs by expected size (see below).
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
  apps/VMs at most «local», kept running, marked «new — please decide», listed first — unless the user chose a default
  for new things (engine 2.31, `[general] preset_new = auto | local | kopia`, written by setup.sh like `asleep_pools`,
  carried by the plan, dropped by `--forget`, never read by a run or acted on by the terminal setup): then the office's
  draft proposes them at that level, marked «new — default: …», and only Apply makes it so (Mr. Backupsy's row, «The
  default»); step 3 lists a share's new
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
* **The array stop ends a run at once (engine 2.24):** var.ini `fsState` Stopping/Stopped (`array_stopping()`, `UB_VAR_INI`;
  Formatting/Clearing count as started — so do agent.sh and agent.php, `ARRAY_RUNNING`) is looked at every phase (`next_phase`), before packages, dumps, Kopia sources and
  pruning, while waiting for VMs/containers and every `UB_ARRAY_LOOK` s while Kopia uploads (`wait -n` beside a sleep). Then:
  SIGINT to the kopia process in the container (`kopia_stop`), `kopia.skipped`/`interrupted` (never failed), no prune, no
  snapshot/dump, lock and note released; **mounts (2.25): everything under mount_root, view_root and `UB_STAGE`, whatever
  `MOUNTED` says** (keep_mounts, a killed run's) — `cleanup` on the stop path, and `run_exit` / `cleanup` for any run,
  check or dry run that ends normally in a stopping array — busy ones `umount -l` after the normal try (`umount_tree
  lazy`; a Kopia that didn't end in 60 s); a stop noticed after `pruned_write` says what pruned.json says (`PRUNED_DONE`); nothing started into the stopping array — stopped
  containers and shut-down VMs stay noted for the next run (`recover_interrupted_run` also waits while the array stops), frozen/
  paused VMs released, a running Nextcloud out of maintenance. `aborted` + `array_stopping`, one normal notification, exit 3; the
  office shows a stopped run (orange, `backup.message.array_stopping`), never a failure. `testBackupArrayStop` (perl stands in for kopia).
* **After the array stop, at the array start (engine 2.25):** `agent.sh array started` (event/started) hands `backup.sh --recover`
  to atd (`backup_recover`: a job file with `HOST_LAUNCH_MARK` like hostLaunch(), so the watchman knows it) only when
  `state/stopped|maintenance|vms` exist — a stat or three, emhttp waits. `--recover` takes the lock without waiting (busy → exit 75,
  quietly: no skipped.json, no notification), nothing into a stopping array (exit 3), waits ≤ `UB_RECOVER_WAIT` for Docker/libvirt,
  runs `recover_interrupted_run` (lib/common.sh, also at a run's and setup.sh's start: each note rewritten with exactly what
  didn't come back — `note_write`; containers network → database → app like `restore_service`, each tier `wait_ready`
  (`recover_containers`, tiers from `docker_load`); a Nextcloud's container waited for, `UB_NC_RUN_WAIT`, else its note
  stays, gone = note goes with a warning; VM service off (domain.cfg `SERVICE="disable"`, `ub_vm_service_off`) = the VMs'
  note goes and `recover_wait` skips libvirt; «Aborted run repaired» normal after an array stop, «… not fully repaired»
  warning naming what didn't),
  is **no run**: no status.json/last-run/history line, latest.log untouched, log `logs/recover.log`; lock note `holder backup, mode
  recover` — backupScan() doesn't count it as running, a run that meets it waits (`UB_RECOVER_LOCK_WAIT`) instead of skipping.
  `agent.sh array stopping` (`backup_release`, ≤ 10 s) acts when something of the engine is mounted: the lock held by a LIVE
  backup.sh backup/check/dryrun (lock-holder.json, pid running backup.sh) — left to it, after `flock -w 2`; anyone else
  (none, setup, restore, recover, a dead pid, a killed run's orphan holding fd 9) — `UB_KEEP_LATEST=1 UB_ARRAY_STOP=1
  backup.sh --unmount` (no wait for the lock, `umount_tree now`; latest.log stays the last run's). **Kopia order:**
  `kopia_order` (lib/common.sh) — flash, the apps' own sources, then shares and VMs' own sources by expected size (the
  LARGER of Kopia's — `kopia_sizes_load`: the newest complete snapshot, or a newer checkpoint when larger; one `snapshot
  list --all --json` without `-n`, Kopia's JSON lists incomplete ones too — and `INV_BYTES` / `VM_APPARENT`; unknown last).
  **Sparse VM disks (engine 2.26, Benj 2026-10-07):** Kopia reads a sparse vdisk whole, its holes as zeros — nostromo's
  Windows11_Gaming (`vdisk2.img` 1.6 TB apparent, 21 GB allocated, two qcow2 overlays): its snapshot said 382 GB, Kopia read
  2 TB in 2.7 h at its first upload (later runs skip the unchanged file). So `vm_load` keeps both sizes of a VM's disk files
  (`VM_BYTES` allocated, `VM_APPARENT` stat %s), the order goes by `VM_APPARENT`, and `setup.sh --plan` carries `bytes` and
  `apparent` per VM for the office (Mr. Backupsy's VM rows and the «Everything local + Kopia» start, Ms. Dustdevil's tip —
  see their rows). `kopia.planned` and the phase follow it. Tests: `testBackupKopiaOrder`, `testAgentBackupHooks` (agent.sh sourced,
  stand-ins; also the array states agent.sh/agent.php/engine agree), `testBackupRecoverNotes` (recover_interrupted_run
  sourced, stand-ins), the end of `testBackupArrayStop`.
* **Partner phase (engine 2.27, plan `briefs/uso-partner-zfs-plan.md` 3.5–3.7/3.10):** phase `partner` between `starting` (and the
  btrfs snapshots after the restart) and `mounting`/`kopia` (`next_phase "partner"`, only when a unit names a partner). Settings:
  `[partner "<id>"] name, address, port, rate_mbit` (the Team Lead's agreement; setup.sh proposes them from `data/partner/pairs.json` —
  `partner_pairs_load()`, the pairs with `my_key`, exactly the shape of plan 3.2; no file: as settings.ini has them; `--forget` keeps them in
  `state/partners-kept.ini`), the units `[share|vm "<n>"] partner = <id>`, `[general] partner_place = <id>` (lists, from the decisions; kept only
  for a partner with a section, a unit `partner_unit_dataset()` resolves, not `off`). Never secrets in the ini: key and known_hosts are
  `UB_PARTNER_DIR/<id>.key|.known` (default the plugin's `partners/` on the flash). A unit is ONE dataset of its own — `share:<s>` single zfs location
  with empty subpath and no child datasets but its VMs'/the storeroom's/excluded ones, `vm:<v>` exactly one `VM_OWN_DS`, `place` the dumps share's —
  else `partner_ok=false` with `why` `not_dataset|split|no_dataset|children|name`; ZFS only, never rsync. Order per partner: place, then by
  `ZDS_REF` (what zfs send moves — not `VM_APPARENT`). Per unit through the door (`partner_ssh_cmd()`: the ONE place for plan 3.5's ssh options —
  tests put an `ssh` stand-in on PATH): `ping` once per partner (255/timeout `UB_PARTNER_ASK` = unreachable), `resume <unit>` (token → `zfs send -t`,
  its snapshot from `zfs send -nv -t`), `list <unit>` (newest common `uso-backup-*`; else the bookmark `<ds>#uso-partner-<id>` with the snapshot
  `state/partner-sent.json` names; else whole), `recv <unit> <snap> [<from>|-t]` as `zfs send -L -c [-i base] | mbuffer | pv -n -b -i 10 [-L] | ssh`
  in a background subshell (exit codes via PIPESTATUS into a file, the door's two JSON lines from stderr), then the bookmark moves. **Never
  `zfs send -s`** (that is `--skip-missing`, only with `-R` — the plan's line had it; zfs refuses). Results: done / skipped (`unreachable`,
  `refused_window|quota|asleep`, `array_stopped`, `no_key`, `not_snapshotted`, the unit's `why`, `array_stopping`, `signal`) / failed (warning each:
  `recv_failed`, `send_failed`, `link_lost`, `no_answer`, other refusals); `need_full` → the same snapshot whole at once. Unreachable warns from the
  `UB_PARTNER_NIGHTS`-th (3) night in a row, once a day (`state/partner-skips.json`); quota/window once a day. Array stop: `partner_stop()` SIGTERMs
  the pipe's whole tree (`partner_tree()`), `partner.interrupted`, the rest skipped — never failed. `status.json` `partner` (null without partners;
  `partners`, `planned`, `current` {id, unit, since, bytes every 30 s}, `done` with `mbit`/`resumed`, `skipped`, `failed`, `interrupted`) and
  `partner_ok|failed|skipped` (so in last-run.json and history; `last-run` too). The office: `backupPartnerRun()`/`backupPartnersFromSettings()`
  (agent/lib/backupscript.php), `backupPartners()` (state `partners`: per partner the last run that had it, `current`), Apply takes partner lists only
  of the plan's `partners[]`. The door, the pairing, `pairs.json` are the Team Lead's (`agent/lib/partner.php`, `agent/partner-door.php`).
  Tests: `testBackupPartnerPhase` (fixture like `testBackupArrayStop`: stand-ins for ssh = the door, zfs, mbuffer, pv), `testBackupPartnerOffice`.
  **2.29:** `partner_pairs_load()` reads each pair's `send.units` too (what the partner agreed to keep); setup.sh gives a unit that is a dataset of
  its own but agreed by no partner `partner_ok=false`, `partner_why=not_agreed` (hint: ask at the Team Lead, «Change what <host> sends…») and keeps
  its partner key as it is; the run skips a unit its pair doesn't agree to as `not_agreed` before the door is asked (`partner_agreed_load`,
  `partner_agreed`; without pairs.json or for a pair it doesn't name the door decides, as before). `testPartnerUnits` has the Team Lead's side. **2.33:** the backup place's share is the unit `place` for a partner — its row in the plan (`place: true`) takes the
  agreement of `place`, like `place_partner` (`agreed_as` in `step_partners()`; up to 2.32 it looked for `share:<its name>` and said `not_agreed` on the place — USOPartner, 2026-10-08).
* **Sleeping pools (engine 2.28, Benj 2026-10-08):** `[general] asleep_pools = wake` (default, also without the key: as before) `| skip`. With
  skip `asleep_plan` (backup.sh) decides once, when the plan is made (after the drift, before `build_stop_tiers`/`vm_plan` - so before anything
  is held): `ub_asleep_load` reads disks.ini once (lib/common.sh section 13; `ub_base_sleeps`: a pool sleeps when ANY of its disks does), nothing
  on the pool asked. A pool of `PLAN_ZFS` (or a base of `PLAN_BTRFS`) asleep → `ASLEEP_BASE`: its datasets out of `PLAN_ZFS` (`ASLEEP_DS`), the
  base out of `PLAN_BTRFS`; shares with any part there (`share_bases`: INV_LOCS + INV_CHILDREN; and a live share in `PLAN_MOUNT` on a sleeping disk)
  → `ASLEEP_SHARE`: out of `PLAN_KOPIA`/`PLAN_MOUNT`, kitems with a part there out of `PLAN_KITEMS` - `asleep_src`: `kopia.skipped` +
  `kopia.skipped_why` `asleep` (never planned, never failed; `array_stop_kopia` keeps them); `asleep_vm` → `vms[].done` `asleep`, not in `VM_TODO`;
  `ct_rests` (every bind into a backed-up share only on `ASLEEP_BASE`) → `T_REST`, not stopped; partner units → `PARTNER_CANT` `asleep`.
  `NOT_LOOKED` (every base asleep at plan time and not woken): the retention lists ZFS snapshots per awake pool (`-r <pool>`, else the one
  `zfs list` as before), `prune_btrfs`/`refresh_view` skip them; `pkg_vm` doesn't stat its disks. Never left out: the backup place's bases (woken,
  logged, `asleep.woken`) and the data folder's (`ub_path_bases "$UB_DATA"`). The inventory's `zfs_load`/`pkg_server`'s filesystem lists stay.
  `status.json` `asleep` {mode, pools, shares, units, vms, containers, sources, woken, nights} (null with wake; `asleep_status_json`), `last-run`
  `asleep=<n>`, the summary «, n shares asleep (left out)»; the result stays ok. `asleep_nights` after the snapshots (real runs): `state/asleep.json`
  per share nights in a row (one per day), the `UB_ASLEEP_NIGHTS`-th (7) warns once per stretch (`asleep_long`), snapshotted = gone. setup.sh:
  `pinit general|asleep_pools wake`, asked with the snapshots, written only when skip or settings.ini had it (`ORIG_ASLEEP_SET` - `_apply_P` makes
  CFG the proposals); the plan carries `asleep_pools`, `asleep_nights`, per share/VM `pool_asleep` + `asleep_bases` (`share_asleep_now`,
  `vm_asleep_now`). The office: `backupAsleepRun()` / `backupKopiaSkipped()` (agent/desks/backup.php). Tests: `testBackupAsleep` (fixture like
  `testBackupArrayStop`: master awake, hive asleep), `testBackupAsleepOffice`.
* **One run at a time, never lost silently (engine 2.20):** `state/lock` (flock) is held by
  backup.sh, setup.sh and Mr. Restori's restores; whoever takes it opens it with `>>` (never
  truncating), `touch`es it and writes `state/lock-holder.json` (`holder`, `mode`, `what`, `run`,
  `pid`, `started` — backup/README.md "When the lock is busy"), trusted only while its pid lives
  (`backupLockHolder()`; unknown = `other`). The office sees the lock only through `flockHeld()` (agent/lib/backupscript.php):
  /proc/locks, never by taking it (a run starting with `flock -n` would skip the night) — through shfs too (QA
  2026-10-08: a data folder whose share isn't exclusive; see «Server facts»: the flock lies on the pool's file, found by
  shfs's inode number), a FUSE file of another numbering probed with a shared non-blocking flock let go at once
  (`testFlockShfs`). A backup.sh that finds it busy touches nothing of the
  run going on (no status.json, no latest.log, nothing loaded or mounted) and writes `skipped.json`,
  for a real backup also a `history.jsonl` line `"result": "skipped"` (reason `skipped_busy_<holder>`,
  translated as `backup.message.<code>`) and a warning notification; exit 75. The office keeps skips
  apart from runs (`state.skips` — history, estimates, the last run and the Dashboard's last run never
  count them) and shows the newest skip while no run finished after it. **One run a minute (engine 2.34):** a run's id,
  log and snapshots' name carry the minute — `backupStart()` refuses `one_run_a_minute` {seconds} while the last run
  (status.json `run`) has this minute's id or this mode's log exists (`backupMinuteTaken()`), and backup.sh itself ends
  right after taking the lock when its log of the minute is there (an ERROR line, exit 1, nothing touched). Test
  fixtures run backup.sh on the tests' clock (checklist 3): every run a minute of its own.
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
* **Updates (2026-10-08, `briefs/upgrade-audit.md`):** users update through Unraid's plugin manager (CA) and read no
  notes — every version starts on any older version's data folder, and an older one on a newer's (a downgrade is
  remove + install). The agent keeps `data/office.json` (`version`, `since`, `updated_from`, `done`, `pending`) and
  runs `officeMigrateStart()` (agent/lib/migrate.php) in `setUp()`, before any desk reads state and before
  `writeInfo()` (agent.json tells the version before): **the one place for migrations** — a step `['id', 'version',
  'run']` in `officeMigrateSteps()` (version = the first version carrying it), idempotent (it runs again after a
  downgrade and back), logged, never deleting (`officeMigrateAside()`: `<name>.before-<version>`); failed ones are
  tried again at the next start. Jobs (cron, atd), the night shift and the web side never migrate and may meet the old
  shape for a moment. Rules: new keys are additive and read with a default — in PHP and in desk.js (the first state a
  new page meets can be the old agent's; the reception reads stored states); keys a reader doesn't know are kept, never
  dropped on a rewrite; no rename of a key, file, path or name (a cron line's command — `officeJobCommand()` —, the
  door's path in authorized_keys, the snapshot prefixes, Kopia's source paths, desk ids) without a step that rewrites
  it and readers of both; exact-shape files (pairs.json, the watchman's mirror `v`, the drill's `interface`, the
  storeroom's manifests) change only with a new `v`/`interface` read beside the old one; the engine's defaults for
  settings.ini keys don't change (old installs rely on them unwritten). The .plg refuses an update while a backup run
  or setup, a restore or drill, or one of Jack Emby's runs is active (one `pgrep -f` pattern, `testPlgGuard`).
  **The install swaps** (2026-10-08, `testPlgInstall`): the package is unpacked beside the running office
  (`<name>.new-<pid>/<name>` — one level down, where Unraid looks for no `.page`) and checked (tar's exit,
  `agent/agent.php`, `scripts/agent.sh`, `scripts/partner-door.sh`) before anything else happens — a failure leaves the
  old install running and exits 1; then the old agent is stopped and two renames swap the folders (the door's path is
  gone for that moment only), the menu pages re-applied, the new agent started (the install waits ≤ 10 s for its
  heartbeat and says when it doesn't come), the old folder (`<name>.old-<pid>`) removed. After the swap the new version
  stays whatever happens (exit 0: at boot an exit ≠ 0 moves the .plg to `plugins-error`); nothing in it may need the
  array or `var.ini` (a boot install runs before emhttp). **The remove** (`testPlgRemove`): the same guard (one pattern
  in both sections), then `agent.sh release` — the array stop's releases (agent, drill/restore, the door's transfers and
  records, the engine's kept mounts, Mr. Restori's pulls) while the scripts are still there; the office's cron file is
  put aside (`<file>.removed-<YYYYMMDD-HHMMSS>`, an earlier aside makes way; `agent-watch.cron` goes, `agent.sh start`
  writes it anew) and `officeCronBack()` (migrate.php, in `setUp()` before the desks' start) puts it back when the agent
  starts without a cron file (`testCronBack`); its closing text says that partnerships ended and where the schedules went.
  **Tolerant writers** (`testPartnerTolerant`): a reader of an exact-shape file uses only entries in exactly its shape,
  a writer keeps every entry it doesn't recognise where it stood, as it is — `partnerListWrite()` for pairs.json,
  tickets.json, ticket-pairs.json (keys beside the list kept; a file of another `v` refused, never written over);
  `drillCertBase()` carries a certificate of another interface's history rows on and keeps that file aside. New
  exact-shape writers do the same. **The page notices an update** (`testUpdateNotice`): `Office.setAgent()` (every
  answer with the messenger) → `updateNotice()`: the running agent's version ≠ `CONFIG.version` → one calm line under
  the top line (`#sso-update`) with «Reload» and «Later» (`office.update.later`, per version), never a forced reload.
  **The steps** (1.44): `where-files` (Ms. Whereabouts' files to Ms. Dustdevil's, put aside) and `staff-merged`
  (staff.json's merged desks) — both were done by their readers before; the web side keeps reading staff.json merged
  without writing, for the moment before the agent's start. **The page heals at once** (1.44, `testLookPage`):
  `Office.loadState()` asks for the new look even when a desk's render throws on the kept state (an older version's
  shape), then hands the error on. **Mr. Backupsy's setup after an engine update** (1.44, `testBackupReplan`):
  `backupSetupReplan()` in setup_get — the plan's `version` isn't the running engine's (compatible, nothing holding the
  lock) → `setup.sh --plan` once per engine version (the note `state/setup-replan.json` {from, to, at, plan} is written
  before it starts: a failed plan isn't retried at every look); no plan answered while it runs; the plan it made carries
  the message `replanned` (`setup.msg.replanned`). No shape sniffing in desk.js. **Unraid 8** (1.44, `testUnraidTested`):
  the Unraid the office was tested on is ONE constant, `OFFICE_UNRAID_TESTED` (major.minor, src/place.php — shared by
  web side and agent); the .plg's `max` is `<major>.99.99` of it (`officeUnraidMax()`, plugin/build.sh refuses another):
  Unraid moves a plugin beyond its max to `plugins-error` at boot, the nightly backups with it. The team lead's
  `unraid_tested` (recommended) is not in place on an Unraid newer than the tested major.minor or within the last minor
  before max — «look for an office release that names the new version before you update Unraid». Raise the constant
  only once tested, never `max` alone. **Notes on findings** (1.44, `testAckContent`): see the team lead's and the
  watchman's rows — what a finding or a posture tip says (its English texts) is part of what was noted; a text change
  in a release re-opens those notes once, so change a check's text only when what it says really changed.

## The desks

| id | name (en / de) | does |
|---|---|---|
| `snapshot` | Ms. Snapshotini / Frau Snapshotini | ZFS, btrfs and VM snapshots: create, delete with an estimate, rename, hold, unmount; schedules with retention (lib/snapshotplans.php: snapshots `uso-plan-<plan>-YYYYMMDD-HHMM` — up to 1.27 `auto-<plan>-…`, counted with them —, retention touches only those and never what matches the engine's names (`backupIsEngineSnap()`); `php agent.php job snapshot-plans` every 5 min — the plugin's cron line `job.sh snapshots`). **Plans are read through `snapPlans()` only** (1.44, `testSnapPlansTolerant`: one normalising reader — every key in its type or its default: keep `SNAPPLAN_KEEP`, no age limit, `enabled` true for the run as for a save, no cron line = never due; keys it doesn't know kept and carried on by `snapPlanSave()`; `snapPlanSaveAll()` keeps entries that are no plan of the office's and keys beside the list). **A target that is gone** (Benj deleted the share `drop`, 2026-10-07: its hourly plan warned every hour) is no failure of a run: `snapPlanTargets()` sorts the plan's targets into take / skipped (asleep) / gone, the run takes what exists and remembers the gone ones in the plan's state (`gone`: target => since, `snapPlanGone()`; a plan with only gone targets creates nothing, result `gone`); told once per plan and target (`notify.plan_gone*`, warning, when first seen gone — a further target is told again, one that comes back is forgotten and told again should it go once more; saving the plan clears it, every target was just found), real failures (zfs refused …) warn every run as before; the team lead gets `plan_target_gone` (recommended, one per plan and target, `snapPlanFindings()` — state files only); her page a chip «target gone» on the plan row. **Editing such a plan** (1.31): `planDialog` starts its selection without the gone targets (the picker can't show them, so they couldn't be unticked) and says so under the picker (`plan.gone_left_out`); `snapPlanSave` drops a gone target the plan already had (`snapPlanSaveTargets()`: logged, `dropped` in the answer) — a target nobody knows that the plan never had stays `unknown_target`, none left `plan_no_targets`. Her metrics count a run's result `gone` as failing (`uso_snapshot_plans_failing`, `uso_snapshot_plan_ok` 0): it creates nothing. `snapPlanRun()` takes stand-ins for scan/asleep/create/delete and `$GLOBALS['snapPlanFile'|'snapPlanStateFile']` point the files elsewhere — tests only (`testPlanGone`) **Sleeping ZFS pools** (Benj, 2026-10-07 — until then her `zfs list` ran over every pool, a sleeping one too): `snapshotReadZfs()` lists datasets and snapshots only on the awake pools — `zpool list` for the pools (the kernel's bookkeeping, no disk asked), then `zfs list … -r <pool>` for those `poolsBySleep()` (lib/mounts.php: disks.ini, a pool sleeps when any of its disks does; whether zfs would read a sleeping pool's disks depends on what the ARC holds — never risked) calls awake; a sleeping pool keeps what she last saw of it (`$old` — her last scan, at her start the state file): the pool `asleep` with `looked` (when; null = not since the agent started, its lists empty = not known, never «none»), its volumes and snapshots marked `asleep`, `zfs.asleep` the names. Her page greys such a pool's card (💤 asleep · as of …, `pool_asleep_*`), chips its rows («pool asleep»), names it in the bubble and the scan toast; «Wake sleeping disks» + Tour (`scan` with `wake`) lists it too (the switch and the menu name what sleeps, disks and pools). Actions on such a snapshot: `estimate` leaves it out (`asleep` counted, said in the dialog), delete/rename/hold/release/unmount refuse it (`pool_asleep`, `snapshotRefuseAsleep()`) unless the request carries `wake` — then the scan before the action lists the pool fresh (and the one after it); the page asks first and never on its own: the delete dialog's callout with a «Wake … and delete those too» box (unticked, only the awake ones go), the rename dialog's box, hold/release through `askWakeFor()`, unmount «Wake & unmount»; `create` sends `wake` for 💤 targets (the picker shows them, the toast says so). Plans: `snapPlanTargets()` — a target on a sleeping pool nobody listed is asleep, never gone; `wake` = the taken ones that sleep, the create gets `wake` with them (the plan takes sleeping targets on purpose, `skip_asleep` off). Tests: `$GLOBALS['snapshotHost']` (zfs, zpool, docker — paths, docker null = not asked), `$GLOBALS['disksIni']` (`disksIniFile()`), `testSleepingPools`. **A partner's copies** (stage 2): `snapshotPartnerMark()` gives datasets and snapshots under `<pool>/UnraidSecretaryOffice-partners` `partner` {id, name, gone, place} from pairs.json — chip «partner's copy» (source `partner:<id>`), never a target (`partner_dataset`: create, schedules; recursive creates skip them), delete/rename/hold/release refused (`partner_copy`) while the pair exists, her plans' retention skips them (`testSnapshotPartner`). |
| `backup` | Mr. Backupsy / Herr Backupsi | runs the engine in `backup/`: status, overview tiles (Kopia, containers, databases, VMs, partners, flash), history, protection (shares, then the VMs; rows unfold to their rules), restore help incl. "Onto a new server", setup assistant (`#/backup/setup`: 0 basics → 1 VMs → 2 apps → 3 other shares → 4 retention; a level per VM/app — nicht / lokal / lokal + Kopia, say "Kopia", never "offsite" — and setupDerive() turns it into share modes (locked in step 3), `docker|no_stop`, dumps and Kopia ignores for folders of apps/VMs that are only local; an app's further shares are never ticked unasked; "start anew" = `setup.sh --forget`; what is new since the last setup waits for a decision, engine 2.21 — see «New things stay local» — or gets the user's default (below); step 0 explains the backup place — Benj's five points (not appdata's pool, ZFS/btrfs for earlier nights, redundancy, one place without secondary storage, small), a link Shares → Add Share (`/Shares/Share?name=`, name `UnraidSecretaryOffice`; the office never makes the share) — and warns, never blocks, from `backupPlaceFacts()` in setup_get (codes same_pool / no_history / secondary / no_redundancy from the share's cfg, disks.ini and the plan's locations); the page re-plans when the setup opens with a plan older than a minute (`SETUP_REPLAN`), and a new plan keeps what the user chose and didn't apply (`setupDraftKeep()`: a key the engine still proposes as before keeps the user's value, levels and holds stay)). **The default** (die Vorgabe; Benj, 2026-10-07/08, engine 2.31 `[general] preset_new`): three of them — `auto` (my proposals; new things wait for a decision, the stored value meaning «no default of mine»), `local` (every share, app and VM «lokal»), `kopia` (all «lokal + Kopia»). ONE default for every kind. Chosen with a scope (`presetDialog()` → `presetScope()`, only with settings; a new server's cards at the top (`presetSection()`) always mean everything): «Apply to everything now» = `presetApply()` on every row as a click would (Kopia on/off, every share's mode, a VM raised from «not» gets freeze/pause proposed again, the flash and libvirt come along; the draft's choices go, the callout says so) / «Only to what is new — my settings stay» (the default with settings; `setupDraftKeep()` keeps the hand changes). Either way it is the default: `setup.choice` {kind, all, items} until Apply or «Start anew», the draft's `general|preset_new` (only when the plan carries `preset_new` — an older engine's plan: the choice works on the draft, no key), stored by Apply (setup.sh writes it when ≠ auto or there before). `setupDraftFromPlan()`: after the plan's proposals `presetApply(choice)` for what was there when chosen «for everything», then `presetApplyNew(presetNow(), setupNewItems(plan, setupSaved()))` on what that didn't cover — `setupNewItems()`: an app whose containers are all new (the plan's why), a VM with why new, a share settings.ini has no section for (not `renamed_from`), every `waiting` folder; none on a new server. `presetApplyNew()` is `presetApply()` per new row without its global side effects (Kopia never switched on or off, nothing set up changed, no flash/libvirt): a new app at the level and held by `presetHold(a, 0)` — under `local` stopped for the snapshot like any app that writes into what is backed up (decision 6), media servers run —, a new VM held (its `prepare = none` dropped → freeze/pause), a new share's mode, a new unowned folder `waitSet()` local/kopia; `kopia` with Kopia off = local (`kopia_off_new` said); under `kopia` a new share with why `big`/`size_unknown` (`PRESET_NEW_ASK`) stays «new — please decide» with a size chip (`newSizeChip()`); `presetKeep()` and `presetVmKeep()` hold as for every default (`presetKeep()` reads the plan's per-share flag `syslog` first — engine 2.32, Benj 2026-10-08: the share Unraid's syslog server writes into says why `syslog` only in a first plan, `previous` once set up, so up to 2.31 «everything local» would have switched it on again). So `setup.base` holds the default («Discard» goes back to it, `presetChanged()` false right after choosing), a re-plan gives a newcomer the default and keeps hand changes. The page: step 0's line «Default for new things: …» / «New things wait for your decision» with «Change …» (`presetLine()`, with settings), the head's «The default …» button, the chip on new rows of steps 1–3 and the waiting folders' head (`newChip()`: «new — default: … — change?» opens the dialog; «new — please decide» under auto), new shares in the apply dialog's «New» (`setupNewLines()`) whose head names the default (`setup.apply_new_default`), the bar counts them as changes (`presetChosen()`), its sub says «Start: …» / «new things: …» and «changed by you» (`presetStartText()`), the forget dialog that the default goes too. The run never reads the key; the terminal setup keeps it and proposes as always (phase 2). «local + Kopia» needs a Kopia container (`presetKopiaState()`: none → disabled with a link to the Consultant, else the team lead; Kopia off so far or a problem → said) and reckons the first upload (`firstUpload(modeOf, vmLevel)`: shares new to Kopia against the saved settings, sizes as far as the plan knows them, «Measure sizes» at hand; the VMs going along for the first time — with their share (not one below «local + Kopia» with its folder there: left out) or as a source of their own — by their disks' apparent size (the plan's `apparent`, engine 2.26: a sparse vdisk's holes on top of its share's measured size, said in `setup.preset.kopia_vms`), the time at `UPLOAD_MBIT` with the nights skipped meanwhile, the monthly cost). `testBackupPresets`. VMs (engine 2.16): `[vm "<name>"] prepare` = freeze (guest agent) / pause / shutdown (never forced off) / none for the seconds of the snapshot, released right after the snapshot holding their disks (`state/vms` for a killed run); `mode = off` and `retention` only for a VM in a dataset of its own (`VM_OWN_DS`); per-VM results in `status.json` `vms`. A source's **first upload** to Kopia (no ok result in a run since the Kopia container's repository.config was written — a new repository starts every source anew) is reckoned from its snapshot's size (`logicalreferenced` of this run's `@<snap>` datasets; a VM's own source by the apparent size of the files in its folders — `backupApparentSize()`, one lstat each, never a read, a sleeping place not looked at: Kopia reads a sparse vdisk whole, its holes as zeros — 1.6 TB for 21 GB of content on nostromo, 2026-10-07, while logicalreferenced said 382 GB) and the Kopia process's `rchar` rate between looks in RAM (`backupUpload()`, `state.upload`), never "late"; the setup's VM rows say what a first upload reads (chip `setup.vm_upload` from the plan's `apparent`, engine 2.26); the texts say Kopia *reads* ~rate/s, and what it really sent so far (`wchar`, `sent`) stands beside it. The stop button, its dialog and toast name the run going on (`runMode()`: the lock's note, then status.json — «Stop the tour» for a check, «Stop the dry run», a backup «Stop the run»); «Start a run» says a full backup without Kopia while `kopia_enabled` is off. **Check `kopia_autostart`** (Benj, 2026-10-07: the office only warns, never starts Kopia): while `[kopia] enabled` and for the container the settings name, whether it comes back by itself after a reboot or an array stop (Unraid stops every container then and starts only its autostart list — nostromo's Kopia stayed off, the run went without its offsite part, `kopia_running` noticed it 45 min later): Unraid's list `/var/lib/docker/unraid-autostart` (lines `name` or `name delay`, `houseAutostart()`), Docker's restart policy `always` (`unless-stopped` doesn't come back), or for a Compose stack's container Compose Manager's `autostart` of that stack (`houseComposeAutostart()`: the folder under its PROJECTS_FOLDER whose `project_name` is the label com.docker.compose.project; a stack it doesn't know, or no Compose Manager: nothing said) — `backupKopiaAutostart()`, a must like `kopia_running`, `testBackupKopiaAutostart`. **Partner offices** (engine 2.27, see the engine bullet): the setup's VM rows, share rows (the backup place's share and step 0: `general|partner_place`) get «also to <partner>» toggles per plan `partners[]` (`partnerChips()`: several allowed, disabled with `partner.why.<code>` when `partner_ok` is false or the unit is off), step 0 «Partners: <names>», the apply dialog the group «To partners» (`partnerApplyLines()`, the partner lists left out of the generic changes); the overview a tile «Partners» (state `partners`: last transfer per partner — units, size, Mbit/s, skipped/failed with `partner.why_short.<code>`), the run card a step «Partners» only in runs with `status.partner`, the Dashboard tile's sub line «to vault: appdata 12 GB» during the phase (`officeDashBackupPartner()`). **Sleeping pools** (engine 2.28, see the engine bullet): step 0 has the switch «Sleeping pools at night» (`asleepChoice()`, `general|asleep_pools`, only when the plan carries `asleep_pools`), share and VM rows a chip «pool asleep now» from the plan's `asleep_bases` (`asleepChip()`), a run's `asleep` (`asleepUnits()`) says «3 shares asleep — left out» on the overview's last run, the bubble and the history row, the Kopia tile counts `kopia_asleep` apart, a callout names shares left out 7 nights in a row (`asleep.long`) |
| `emby` | Jack Emby (the intern) | EmbyCache (`embycache/`, from github.com/helmi1987/embycache-for-unraid, extended: back to the origin disk via embycache_origin.json, the emptied folder stays on the disk as a signpost, separate limits for started films and series, deliberately skipped folders = empty mapping) and the gather "Consolidate folders" (`gather/`: one disk per film folder, keeps empty folders whose content is on the pool). Settings via EmbyCache's own save_config() (trial file first); the gather's ini written by Jack; API key never leaves the server; schedules: jobs `embycache` and `gather` (job.sh). **Never a real gather while someone watches Emby** (Benj, 2026-10-06): `embyWatching()` asks every server of EmbyCache's settings for `/Sessions` (PHP's curl, the key in memory only, 5 s); a session with a NowPlayingItem, also paused = watching; no HTTP answer = down → the run goes, logged; 401/403, another status, no JSON → not started (`emby_watch_key`, `emby_watch_answer`). From the page refused with who watches what on which device and when Emby last heard from it (`seen` = LastActivityDate, `embyWatchSeen()`; the page and his list of runs, never the log) (`emby_watching`), no override — the dialog names the gentler way (Benj, 2026-10-07): end the session left behind in Emby (Manage Emby Server → Dashboard → Now Playing → Stop) or on the device, the container only last; the scheduled wait and skip say the same; on schedule `embyGatherGate()` waits holding only its own lock (`emby-gather-wait.lock/.json` in RUN_DIR — nothing open on the pool), looks again every 15 min up to 2 h, then `skipped` in his list of runs (a second start while one waits adds nothing; a real gather from the page meanwhile or the array stopping ends the wait); during a real run `embyGatherWatch()` asks once a minute and writes `CONSOLIDATE_STOP` → the gather stops after its current folder (result `stopped`, exit 3, never its first real run). Dry runs are never asked about. **Taking over an earlier install** (`import_preview` / `import_apply`, a section on `#/emby/setup`, his menu): the user types the folder of the old EmbyCache and/or the old gather — Jack never searches (his detection of other copies, `embyForeignSchedules()`, stays apart); `embyImportFolder()` checks it before anything is read (absolute, no `.`/`..`, under `/mnt/user/<share>`, a pool, `/mnt/diskN` or `/boot/config/plugins/user.scripts/scripts/<name>`; its share's/pool's/disk's disks awake — refused `emby_import_asleep`, never woken; every part a real folder, only an exclusive share's own link followed; not his own folder); only `EMBY_IMPORT_FILES` are read there (plain, nlink 1, size-capped; consolidate.ini parsed by `embyImportIni()`, never sourced). EmbyCache: keys 7.3.0 doesn't know left out (names only), keys the old install lacks keep Jack's current value, or without one Jack's own default (`EMBY_JACK_DEFAULTS` — what his setup page would choose, e.g. `cleanup_tool` rsync; `max_resume_*` = the old `max_resume_items`; else embycache_lib.py's `DEFAULTS`), values that run or write elsewhere (`mover_bin`, `rsync_args`) or are the office's (`EMBY_IMPORT_FIXED`) are Jack's, the pool must be this server's; the old global mappings merged into each server's; the list and origins only as lines under `<pool>/<share>/` of an existing share, merged with Jack's (his origins win); EmbyCache's own save_config()+load_config() tried on a trial folder in the preview. The gather: BASE_DIRS (whole shares only), MIN_FREE_GB, DUP_CHECK into gather.json, the rest Jack's own. The preview never carries an API key (`embyImportScrub()` over every answer; «API key: found / Jack's / not found»); the apply needs its token (over the preview, what will be done and Jack's files) and first copies each of his files it replaces to `<file>.before-import-<time>` in his data folder. Afterwards he says: switch the old User Script / cron line off yourself, start with a dry run. Under the tools' tiles at the bottom a quiet credit to helmi1987, who wrote both tools (`credit()`, links to his two repositories, both GPL-3.0-or-later like our modified copies; a share of the tips goes to him — Benj's decision) |
| `logs` | Ms. Protocolli / Frau Protokolli | reads logs out loud (fixed source list in agent/desks/logs.php, ids only, never paths; `backup:latest` = the engine's latest.log); tail/follow by file offset, docker logs and dmesg re-read; lines go into the page as text only. A picker with a search field instead of a select; favourites in `Office.store` (defaults until the user stars/unstars one, "out of the box" brings them back); nothing is read until a log is chosen. Read only. Tour (`logs.tour`, open action; `php agent.php job logs-tour` in the background, polled from tick → `data/logs-tour.json`, part `tour`): /var/log fill and biggest files with growth, container log sizes by stat plus Docker LOG rotation, errors and warnings per source since the last tour (offset, rotation by inode, first tour 24 h; ≤4 MB per file, ≤3000 lines per running container), similar lines grouped (`logsNormalize`: numbers, addresses, ids, file names and paths — quoted, absolute, relative, Samba's `for <name> with NT_STATUS_…`); a line's own level beats words, the page's colouring uses the same patterns (tests compare them). Check `varlog`: /var/log >60 % recommended, >80 % required. Sources `partner:door` (`data/partner/door.log`), `partner:door.1` and `partner:door-ram` (what the door logged in RAM while the array was stopped; `testLogsPartner`). Routers' files in Unraid's syslog server folder (`watchnetLogFiles()`): `router:<sender>` and `router.1:<sender>` (not `<sender>.1`: an address may end in «.1»), group unraid, only while the server is on and the share's disks are awake; her tour counts their errors and warnings (not `.1`) |
| `watchman` | The Night Watchman / Der Nachtwächter (it Il guardiano notturno, fr Le veilleur de nuit, es El sereno) | calm, factual, few words; the office's security department, two parts on his page. **How secure does it stand?** — his posture tips (`watchmanPosture()`, every round, RAM and flash only, in `state.json` `posture`): the flash exported to guests, also only for reading (`flash`, sec.ini/sec_nfs.ini level ≥ secure: shadow and ssh/ lie there; link `/Main/Boot?name=flash`; the `public` tip leaves the flash out — other «secure» shares get no tip), shares guests may read, write and delete (sec.ini/sec_nfs.ini level public — user shares, disks, pools; links `/Shares/Share`, `/Shares/Disk`), Telnet (ident.cfg), UPnP (ident.cfg `USE_UPNP`, advice: the server opens router ports by itself), the WebGUI reachable from the internet (Unraid Connect's `configs/connect.json` `dynamicRemoteAccessType` ≠ DISABLED, info, with type and port), Unraid's FTP server (inetd.conf; not while Fix Common Problems is installed and `vsftpd.user_list` exists — then it is FCP's check), an API key that can do everything (`api_admin`, 2026-10-07: the ADMIN role, a `*` resource, or the right to make or change keys and permissions — the API's create and addRole ask only for that, so such a key can give itself ADMIN; from what the round saw of the keys, never asking the API; link `/Settings/ManagementAccess`, whose «API Keys» tab is where Unraid keeps them; signature = those keys' ids), the CPU's protection off (`mitigations=off`, open flaws from /sys; link Settings → Boot Parameters) with VMScape while VMs exist (`virsh list --all` — never a look into libvirt.img; libvirt not answering: the last round's word) or on (info), privileged containers (info). Each with why, a link into Unraid and «I know, thanks» kept on the server (`data/watchman/posture.json`, id → signature of what it is about and `text` (`watchmanPostureText()`: hash of `posture.<id>.title`/`.why`, 1.44 — an older note gets it at his next round); back when either changes, forgotten when the tip goes; `posture_ack` {id, on}). Advice, not findings: never in the book, never notified; the team lead gets one `hint` (`posture`, how many advice-level tips are open). Not FCP's checks (a root password, plugins CA doesn't know — FCP's `unknownPluginInstalled()`). **What changed?** — keeps the watch book (Wachbuch, registro di guardia, main courante, libro de guardia) and reports only what is DIFFERENT from normal. Read only. His first round after hiring is the baseline (also the login addresses in the syslog's history; hired again = a new baseline), nothing reported; then `php agent.php job watchman-round` every 5 min, started from his tick (a round lock in RUN_DIR; the merge into `data/watchman/` baseline/book/state/seen.json under a book lock, so «I know, thanks» never races a round): WebGUI/SSH logins from a new address and bursts of failures (WATCH_FAIL_BURST within WATCH_FAIL_WINDOW; syslog by offset, rotation by inode; names tried kept only when they are users), container rights as docker run flags (privileged, host network/PID/IPC, ports, caps, devices, docker.sock, /; a new container only with rights), plugins (`/var/log/plugins`, the `<PLUGIN pluginURL>` host, on code hosts with the owner), the flash (go as line hashes, /boot/extra, users, a hash per shadow field, authorized_keys fingerprints), shares newly open to guests (sec.ini/sec_nfs.ini); scheduled and auto-starting things (group `sched`, T1053): root's own crontab against `/etc/cron.d/root` (only lines of a cron line's shape — five time fields or an @keyword, then a command, `WATCH_CRON_LINE`; dcron's reload signal `cron.update` — `crontab -c <dir> -` writes the user's name there, crond deletes it — is never a crontab, in both folders; `cron_new`, `cron_twice` — in both, they run twice —, `cron_office` — the office's job.sh lines there; lines whose program went with its plugin are Ms. Dustdevil's (Ms. Whereabouts' up to 1.30), order not security (`cron_dead` was his up to 1.28; `watchmanLoad()` leaves out entries of kinds he no longer keeps); the spool file's mtime and the syslog lines ±2 min around it naming plugins/scripts/cron, scrubbed, as evidence; the undo command only as information — he never edits a crontab), other users' crontabs and `/etc/cron.d`'s other files (`cron_new`), the plugins' `.cron` files on the flash (`cron_file`; `cron_file_foreign` = folder of no installed plugin), User Scripts (`script_new`/`script_changed`: content hash, schedule.json), atd's queue (`at_job`: not marked `HOST_LAUNCH_MARK` by hostLaunch(), command after at's `cd … || {}` only — never its environment; a job that is exactly User Scripts' «Run in background» — `startBackground.php /tmp/user.scripts/tmpScripts/<name>/script` for an existing script, no other command, no LD_*/BASH_ENV/…, PATH and SHELL the system's — is `at_userscript`, a line noted by itself (`by` auto), once per job number; Unraid's own `sleep N; /usr/local/emhttp/webGui/scripts/reload_services` after an array start — exactly that, as root, with a clean environment, `watchmanAtUnraid()` — is never news, entries up to 1.30 about it are closed `by` unraid), notification agents (`notify_agent`, a salted hash only); stat first (size, mtime, ctime from seen.json), read only on change. **The office's own** (`watchmanOfficeLook()`, by the consultant's root-only record `data/advisor/installs.json` — folder 0700, file 0600 of root's, `{installs: [{t, kind: plugin|container, id, name, url|image}]}`, the newest 20 within 30 days, written by `advisorRecord()` before his plugin job and when he prepares a form; trusted only in exactly that shape, `WATCH_OFFICE_KEEP` 7 days): a new plugin of exactly that name whose `/var/log/plugins` link appeared within `WATCH_OFFICE_PLUGIN` (900 s) after his record, the `/etc/cron.d/<that plugin's name>` file made then (ctime), a new container with rights of exactly the recorded name and image, label `uso.installed-by=consultant` and created within `WATCH_OFFICE_FORM` (2 h) — adopted into the baseline and one line noted by himself (`by` office, `p.installed_by`), never told; anything else stays reported. Findings: the watch book (500 entries, noted ones 90 days), the team lead's `checks` (one recommended per kind; his «I know, thanks» on it counts as noted at the next round), the important kinds to `officeNotify()` (per kind once an hour; switch `notify_set` like the team lead's, default on — `state.json` `notify`; entries that come while it is off are `muted`, never told later). «I know, thanks» (`ack`, `ack_all`) adopts that state into the baseline; what gets safer adopts itself. **Data flow** (group `flow`, in his round, `watchmanFlowLook()` outside the book's lock, `watchmanFlowCompare()` inside): per client and file service (SMB 445/139, NFS 2049, SSH and the WebGUI on var.ini's `PORTSSH`/`PORT`/`PORTSSL`) the bytes the server delivered — `ss -tinH state established` through `hostNet()`, tcp_info `bytes_acked` (else `bytes_sent`) diffed per connection (local+peer address:port; a smaller counter = a new connection), loopback left out; SMB from `smbstatus -b --json` (the table as fallback): `smb_user` (important), `smb_client`, `smb_hour` (a session started at an hour of the week ±1 that machine never used, after its learning time); containers from `/proc/<State.Pid>/net/dev` (all but lo; any network type; one namespace counted once under its first name; the host's network can't be separated — listed); ZFS `written,used,snapshots_changed` of every dataset of the awake ZFS pools/array disks (disks.ini, `baseAsleep()`; a sleeping pool keeps its counters), summed per share `pool/share` (a new snapshot: written since it; snapshots changed and it grew: that round left out; `-` = never a snapshot → only growth shows). The last round's counters live in RAM (`RUN_DIR/watchman-flow-<hash>.json`, stale after 30 min = start anew; a part not looked at keeps its counters), the history aggregated in `data/watchman/flow.json` (hourly sums 14 days, past hours < 1 MB dropped, capped: 64 client/service pairs, 100 containers, 200 shares; forgotten after 30 days unseen). Unusual (`flow_client`, `flow_container`, `flow_written`, all important): learned (7 days after first seen) — this hour so far > 4 × max(the most at that hour of the week ±1 h, a quarter of the busiest hour, the «I know, thanks» floor) and > 2 GB; still learning — one round > 50 GB (a share: > 20 % of its size, ≥ 1 GB). One open entry per key; rounds that follow update it (bytes, minutes, `peak`), a later one is a new episode (count + 1). While the engine's lock is held (backup, check, dryrun, restore) the Kopia container's traffic (`[kopia] container`), what goes into the backup place's share (`UnraidSecretaryOffice` / `dumps_share`) and, during a restore, every write are the office's own (`o`, never learned or told); media servers (image/name emby, jellyfin, plex) are learned, never told. «I know, thanks» raises that one's normal (`baseline.flow.ack[<entry key>]` = its peak) or makes the SMB user/machine/hours known. Not possible (page help): which files were read (Samba full_audit — too much for /var/log), where data goes on the internet (`nf_conntrack_acct`, off; he never switches it on). **Gone** (`flow_gone`, important): what vanished between two rounds — per user share the drop of ZFS `referenced` of its datasets on the awake pools (`usedbysnapshots` rising = what its snapshots keep; pools asleep named; a dataset renamed into the storeroom or a storeroom dataset destroyed is no loss), per awake XFS/btrfs disk or pool the drop of its used space (statfs; the shares with a folder there named; left out while its `.btrfs-snap` changed in the last 30 min — pruned snapshots free old deletions); learned like written, while learning told at one round ≥ `WATCH_GONE_NEW` (100 GB) or ≥ `WATCH_GONE_PART` (10 %) of the share; the entry says who moved data over SMB/NFS/SSH in that round or `server` (nobody). Expected, never told: the engine's lock held, or `watchmanFlowMovers()` saw this round or the last the mover (`mover`, `age_mover`), `embycache_run.py`, `consolidate_master.sh`, `rsync --remove-source-files`, `rm` of a storeroom (not unbalanced: its web server runs all the time). `metrics` hook: open entries per kind, the last round, `uso_watchman_sent_bytes_total{service}`, `uso_watchman_written_bytes_total{share}` (top 8). **Snapshots that vanish** (group `snap`, `watchmanSnaps()` outside the book's lock, `watchmanSnapCompare()` inside; only `watchmanRun()` passes `snaps: true`): round against round `zfs list -H -p -t snapshot -o name,guid,userrefs -r <the awake ZFS pools>` (disks.ini like the data flow, never the boot pool; Docker's layer datasets out; ≤ `WATCH_SNAP_MAX`, a pool beyond is not compared) and the snapshot folders (`.btrfs-snap` + the engine's `btrfs_snap_dir`) of the awake btrfs disks and pools; `data/watchman/snaps.json` (rounds only: the lists per pool/disk with their time, what the office removed, the position in agent.log). A pool asleep or zfs not answering keeps its last list — never «gone». No news: Ms. Snapshotini's own record, root only (`data/snapshot/deletes.jsonl`: folder 0700, file 0600, JSON lines `{t, do: deleted|released|renamed, …}`, appended under a lock by `snapshotRecord()`, `.1` beyond 1 MB, a record others could write is set aside `.untrusted-<time>` and begun anew; `watchmanSnapRecord()`) and her lines in the office's log (`Deleted: <ds>@<a>,<b>`, `Deleted: <path> (btrfs)`, `Released: <ds>@<name>`, `Renamed: <where> <old> → <new>` — logLine() in snapshot.php; their shape is his interface too), both read by offset/inode, kept `WATCH_SNAP_OFFICE`; the record wins — log lines count only until it is there (snaps.json `record`), never once a record she kept isn't one; the engine's retention (its own names, a real backup run between the two lists — status.json/history.jsonl, never its log —, a newer one of its own still on that dataset/folder), renamed (the ZFS guid still there: her rename, Mr. Restori's and Ms. Dustdevil's dataset renames), the storeroom, learned series («I know, thanks» on `snap_gone` adopts its series — the name with numbers as `#` — into `baseline.snaps.series`: may go while a newer one of the series stays). The rest: `snap_gone` (important; one open entry per pool/disk, count = snapshots; datasets, names, series, held, the pool's `zpool history -l` lines naming them since the list before — read only then — and the syslog around, `WATCH_SNAP_EVIDENCE`); a hold released (userrefs fewer) without her `Released:` line: `snap_hold_released` (important). **The host itself** (group `host`, SOC 1.30; `watchmanHost()` outside the book's lock — only when `$paths` has `proc` —, `watchmanHostCompare()` inside, seen.json `host`, baseline `host`: users, listen, procs; first look = normal): `log_cleared` — syslog, wtmp, btmp, lastlog by inode and size round against round within one boot (`/proc/sys/kernel/random/boot_id`): smaller = emptied, a new inode = replaced unless the old one is now `<log>.N` or logrotate's date for it (`/var/lib/logrotate.status`) changed; `user_ram` — /etc/passwd + /etc/shadow (the password only as hash/none/locked) against the baseline: an account the flash doesn't have (`flash.users`; flash users are the flash watch's), a second UID 0, a system account that got a login shell or a password (safer adopts itself); `listen_new` — `ss -H -tlnp` (hostNet), loopback, docker-proxy and qemu-system left out, key `tcp:<port>` (the port is what is new: ss names Samba's smbd or smbd-scavenger), ports in ip_local_port_range as `<program>:*`; `proc_odd` — one readlink of `/proc/<pid>/exe` per process, `WATCH_ODD_EXE` (/tmp, /dev/shm, /var/tmp, /run, a hidden folder, memfd — not runc's `memfd:runc_cloned`), on the server or in a container (mount namespace ≠ 1's; the container by the PID namespace of its main process), key with `watchmanOddKey()` (an AppImage's `/tmp/.mount_<name>XXXXXX`, mktemp-like parts → `*`; nostromo's virtual-dsm runs `/run/host.bin` — learned). `door_new` — the ways in from outside Unraid's settings open, from the flash only (`watchmanHostDoors()`): ident.cfg `USE_SSH`/`PORTSSH` and `USE_UPNP`, Unraid Connect's `configs/connect.json` (`dynamicRemoteAccessType`, `wanport` — nothing else read), `configs/oidc.json` (per provider id its name and the issuer's host — never the client id), `/boot/config/wireguard/*.conf` (per tunnel a fingerprint of each peer's `PublicKey`, never the private key): and Unraid API's `configs/api.json` — only `sandbox` (the GraphQL playground and schema for whoever reaches /graphql), `extraOrigins` (per origin its scheme://host:port: other web pages may call the API with a logged-in browser's session) and `ssoSubIds` (Unraid.net accounts that may log in to the WebGUI, a fingerprint each like a peer), never another field (`watchmanApiDoors()`, keys `api_sandbox`, `api_origin:<origin>`, `api_sso`): one opened (on, another port or type, a new provider or issuer, a new tunnel or peer) is an entry, one closed or gone is the new normal by itself. **Unraid's API as a door** (2026-10-07, Benj's OK to the API concept's point 2): `api_key_new` / `api_key_changed` — the API's keys in `/boot/config/plugins/dynamix.my.servers/keys` (`watchmanApiKeys()`, flash only, stat first: a file whose size, mtime and ctime are as the last round saw — seen.json `host.api_files` — isn't read again; plain files whose name contains `.json` like the API loads them, ≤ 64 KB, ≤ 200): of each only `id` (the API's UUID shape), `name`, `roles` (upper case, as the API reads them) and the permissions as a set (`RESOURCE:ACTION`, the legacy forms normalised like its `normalizeLegacyAction()`) kept as a fingerprint (`perm`), their number and the first 8 in words, and `full` (`watchmanApiFull()`) — `watchmanApiKeyRead()` takes those four fields and drops the file's text and everything else right away: the key's value (never even a hash of it), `description`, `createdAt`. A new key, or one with a role it didn't have or another permission fingerprint (a fingerprint can't tell fewer from different — told), is an entry (important, T1098 Account Manipulation: a credential added to keep a way in; T1078 Valid Accounts would be its use, which he can't see — he never asks the API); revoked, renamed or fewer roles alone is normal by itself; back again is told again. The first look at the API after the update (a baseline or a mirror without `host.api`) is normal — its keys and the `api_` doors (`$apiFirst` in `watchmanHostCompare()`). `api_keys` and `api_cfg` are paths of their own (both or neither); the doors need ident.cfg like the others. He never creates, reads out or uses a key. All seven important. **For a SIEM** (switch `syslog_set`, `state.json` `syslog`, default off): after a round each new book entry (also those noted by himself) as one line `logger -t uso-watchman -p user.notice` — JSON `{v, id, kind, group, attack, important, noted, time, text}` (the entry in English, ≤ 700 chars), at most `WATCH_SYSLOG_MAX` a round, only when `$paths` has `logger` (never from the tests); Unraid's Remote syslog server sends it on; his own lines are never evidence (`WATCH_SYSLOG_OWN`). «What I keep an eye on» has the group «The server itself» (`watchmanHostSummary()`: known ports with their program, the open ways in, the API keys by name and roles, programs from odd places, how many accounts). **What may belong together** (a SOC's correlation, `watchmanChains()` / `watchmanChainsDue()`): important entries not noted, ≥ 2 groups, each first seen within `WATCH_CHAIN_WINDOW` (1 h) of the one before (sorted by first seen, ≤ 7 days old), that are a way in (`WATCH_CHAIN_ACCESS`: login_new_ip, login_failures, smb_user, door_new) plus something else, or damage of two sorts (`WATCH_CHAIN_IMPACT`: snapshots, written/gone, logs emptied) — a plugin installed (plugin, cron file, port at once) alone is none; one notification when a chain forms or grows (`state.json` chains: key = its first entry's id → entries told; the notify switch counts, off = never told later; quiet an hour like a kind, `notified.chain`), the page a callout at the top of the book and a chip «together». Every kind has its nearest MITRE ATT&CK technique (`WATCH_ATTACK`; the page's entry `attack`, a chip linking attack.mitre.org; a test checks every kind has one). The office's own cron file: lines exactly as `officeJobSetSchedule()` writes them (`watchmanOfficeCronLine()`: five cron fields + `officeJobCommand(<job>)`) are noted by himself (`by` schedule) — a changed time on a desk's page; anything else in that file stays an entry. **Grafana:** where the consultant saw Grafana running with the office's dashboard provisioned where it reads it (`advisorGrafanaDashboard()` — his state file, no docker call), the data flow's groups «who pulls» and «written into shares» link `<WebUI>/d/unraid-secretary-office?viewPanel=50|51` (`WATCH_GRAFANA_PANELS`; the tests check those panels show his metrics) — a link in a new tab, never embedded **Night shift** (2026-10-07, `watchmanNightRound()`, `php agent.php nightshift` from `agent.sh` in its own session, cwd /, umask 077): while the array isn't started — after `event/stopping`, and from boot (the .plg's `agent.sh start` picks it when fsState ≠ Started) until the first start — a round every WATCH_EVERY of his RAM/flash parts (`watchmanNightPaths()`: without sec/sec_nfs/share_cfg, zfs, libvirt, the office's records, the engine; `watchmanShares()` is null without its places; Docker down → no containers, programs are the server's; Docker up → not looked at), book/state/log in `WATCH_NIGHT_DIR` (RAM, 0700), `WATCH_NIGHT_LOCK` held while on (the agent's round waits for it; the night shift ends when the agent runs or the array is Started, and at once without a mirror — not hired, or no round yet: then it stays quiet). Its baseline: `watchmanMirrorWrite()` after every round (and ack/switches) — RAM `WATCH_MIRROR_RAM` all of it (with the syslog position incl. its boot id, failures, logins, seen sched/host, the open entries in full), flash `WATCH_MIRROR_FLASH` only when changed (`sum`) and at most every `WATCH_MIRROR_EVERY` (3600 s): no `pw`, crontab lines as hashes only, no times that move, open entries as id/kind/key/count/told only, the API keys by id, name, roles and their permissions' fingerprint (no value — the keys' files never had one taken); both dropped when he is let go (and the flash one by the .plg's remove). From the flash mirror (another boot) the night reads this boot's syslog from its start and takes passwords and ports from its first look (a password changed while off is the agent's find after the start). Reports like the day (same kinds, quiet hour carried in `notified`, notify switch, `officeNotifyLang()` from the flash). Handover (`watchmanNightHandover()`, first in `watchmanRun()`): new night entries get `night` (page chip «night shift», `state.night` → «The last round» says from/until), an entry open in the day's book when the night began (a `stub` with its count/last) or under the same key is only brought up to date (`watchmanNightInto()`), told/muted carried, the syslog position, fails, notified, chains, logins taken over; then the night's files go (a night of another hiring is dropped). **The array's lines:** `agent.sh array stopping|started` appends `<time> stop|start` to `WATCH_ARRAY_EVENTS` (RAM, newest 50); each round books those after `state.array_seen` as `array_stop`/`array_start` (group `array`, not important, T1489, noted by himself `by` array) with the WebGUI/SSH logins from `WATCH_ARRAY_BEFORE` before to `WATCH_ARRAY_AFTER` after (`state.logins`, `watchmanRecentLogins()`) — Unraid 7.3.2's syslog has no cmdStop/cmdStart lines (only «Stopping services…», `mdcmd (N): stop` …), so the event scripts are the clock. **The server's start** (`server_boot`, group `array`, not important, T1529, `watchmanBootLine()`, 2026-10-07): a reboot leaves no stop line (`WATCH_ARRAY_EVENTS` lies in RAM) — a round (day or night) that sees another boot id than `state.boot_seen` (up to 1.30 the syslog position's `boot`; a night begun from a mirror: the mirror's `boot`) books one plain line at the kernel's btime (`/proc/stat`, path `stat`) with the logins around it, noted by himself (`by` array), key `server_boot:<boot id>` (once per boot), never at his first round after hiring, nothing when the boot before is unknown; the handover carries `boot_seen`, so the night's line is the only one. The SIEM switch is `state.json` `siem` (up to 1.30 it shared `syslog` with the read position — `watchmanStateFix()` moves it). **Partner doors** (stage 2, `agent/lib/partnerlook.php`, `testWatchmanPartner`): the office's own `uso-partner:<id>` line (exactly `partnerDoorLine()`, the pair's `their_key`, authorized_keys written within `WATCH_PARTNER_PAIRED` 900 s after `paired`) is adopted with its key and noted by himself (`partner_paired`, `by` office), any other new line stays a new key; group `partner`, all important: `door_changed` (a known pair's line — from=, restrict gone, command, key, options; restrict added alone is safer), `door_key_moved` (sshd's fingerprint: the pair's key from another address or its address with another key, T1078), `door_refused` (≥ 3 within 60 s, `refused-<id>.json`); the door's transfers (`door-*.json`, the round after, a receive since) make the pairs' SSH and the writes into `<pool>/UnraidSecretaryOffice-partners` the office's own, the sender's phase `partner` too; `deletes.jsonl` (root only, the engine's names under a partners' place) is no `snap_gone`; posture tips `partner_wide|unknown|public` (advice) and `partner_friend` (info) link `#/caretaker`; «What I keep an eye on» lists the doors; the mirror carries `baseline.partner` for the night. **The network** (group `net`, stage 1 of the router SOC — UniFi only, briefs/brief-router-soc-stage1.md; `agent/lib/watchnet.php`, read only, `testWatchmanNet`, fixtures `tests/fixtures/router/`): the office never listens — the router sends to Unraid's own syslog server (`/boot/config/rsyslog.cfg`: `local_server`, `server_folder` = `/mnt/user/<share>`, `server_filename` `syslog-%FROMHOST-IP%.log` …, `log_rotation`, `remote_server`), which writes one file per sender into a share; `watchnetLook()` reads those files outside the book's lock (paths `rsyslog_cfg`, `shares_ini`, `disks_ini`, `var_ini`, `net_class`, `arp`, `array_events` — none in `watchmanNightPaths()`: the night shift never reads them, rsyslog writes nothing while the array is stopped) only while the array runs and the share's disks are awake (`watchnetBases()`: an exclusive share its pool, else useCache/cachePool/cachePool2 or the array's data disks; a share emhttp doesn't list is not read), per sender by offset and inode like his syslog (`watchnetPending()`: the rest of `.1` by inode, then the new file; positions in `data/watchman/net.json`; `WATCH_READ_MAX` shared in proportion to what waits — beyond: the newest part and a plain line in the book, kind `watch` with `p.too_much`, once a day per sender: set the gateway to «Blocked Traffic Only»), plain files only, the files of this server's own names never read (`watchnetServer()` `own`: every interface address — `net_get_interfaces()`, tests `$GLOBALS['watchnetIfaces']` —, 127.0.0.1, ::1, the `::ffff:` form, localhost, ident.cfg NAME — the loop of ⟦Remote syslog server⟧ pointing at itself), his own `uso-watchman` lines never counted, nothing ever written there. Lines (0644/0666 in a share: untrusted) through `watchnetParse()`: the header (local time, host), UniFi CEF (`CEF: ?0|` — a space after the colon on 10.6 —, header escapes `\|` `\\`, the extension's value runs to the next ` <key>=`: admin names, durations, aliases hold spaces; `UNIFIutcTime` = the event's time) or netfilter (`WATCHNET_TAG` `[ZONE-ACTION-RULE]` / `[ZONE-RULE-ACTION]`, action A/D/R, `IN OUT SRC DST PROTO SPT DPT`); anything else — and a line over 8 KB — is «other», counted, never an entry; the vendor from the line. `watchnetType()`: admin_login (544 «Network Accessed», category Audit), config (an admin's change; `area` firewall/nat/port_forward/policy by its words), client (400/402 … Connected: MAC, address, alias or host name), vpn, detection (Security), update, wan, blocked (netfilter D/R). Kinds (`watchnetCompare()` in the lock): `net_sender_new` (a new file, not important; the first round that sees the syslog server on is the baseline), `net_new_device` (a MAC never seen — learned at once, one entry per MAC; the entry's own `important` when it takes a known device's name, unless both MACs are made-up ones: phones rotate them — `watchmanImportant()` in notifications, chains and the page's `tell`), `net_spoof` (ident.cfg NAME or one of the server's addresses on a MAC that is neither the server's — /sys/class/net, the containers' from `WATCH_INSPECT`'s networks — nor noted), `net_router_login` (an admin or an admin's address not in the baseline, counts up), `net_firewall_change` (one open entry per router and area, counts up), `net_router_config` (one line a day per router, noted by himself `by` router while the admin is known), `net_vpn_login`, `net_ips_server` (src or dst one of the server's addresses; key signature + direction; «I know, thanks» learns an inbound signature, an outbound one is told again every time), `net_blocked_from_server` (SRC the server's, action D/R; key destination /24 (IPv6 /48) + protocol/port; learned per key), `net_log_silent` (a router's file — CEF or netfilter seen — not grown for > 3 × the longest gap of the last 7 days, at least 6 h, counted from the array's last start or the boot, while ARP has its address; closed by itself `by` router when a line comes) and the posture tip `router_clock` (the newest line's time — UTC when given — off its arrival, the file's mtime, by > 5 min; links the Consultant). A router's first lines (a sender without a position) are learned: devices, admins with their addresses, VPN users, blocked keys, inbound signatures, the server's MACs per name/address. «I know, thanks» (`watchnetAdopt()`): the MAC for the name/address (and as a device), the admin's address, a config admin, the VPN user's address, an inbound signature, a blocked key. Chains: `net_router_login`, `net_vpn_login`, `net_new_device`, `net_spoof` join `WATCH_CHAIN_ACCESS`, `net_blocked_from_server` the impact side (`exfil`); `watchnetChains()` joins a new device and a `login_new_ip`/`smb_client`/`smb_user` from its address within 10 min (also when the device isn't important). **The privacy rule** (hard-coded, asserted over every entry in `testWatchmanNet`): another client's address, MAC or name only in `net_new_device` and `net_spoof`; every other entry carries only the server's addresses and its subject (the admin's or VPN user's address, the IPS hit's other side, the blocked destination); the evidence (`watchnetEvidence()`, ≤ 300 chars) is rebuilt from the type's own fields, every other address «…», a MAC by its first half; detections on other clients and their blocks are counts (`days[<date>][<sender>]`); the «Whole LAN» switch (`net_lan_set`, `state.json` `net_lan`, default off) adds per MAC connections and detections — counts, gone when switched off. State: baseline `net` (senders, devices ≤ 500, self, admins ≤ 50, vpn ≤ 50, blocked ≤ 200, ips_in ≤ 200), `net.json` (pos, senders with meta/lines/last/gaps/skew, days 14, blocked counts ≤ 200, seen MACs, lan, look). The mirror carries `baseline.net` and, in RAM only, `net_pos` — no line's text. The SIEM line is his entry (`group: net`), never the router's line. «What I keep an eye on»: `watchnetSummary()` — the syslog server's state (off, listening — «Unraid listens, nothing arrives yet» —, asleep, the array), the senders (name and version from the CEF header, last line, lines a day, «not understood» %), counts, detections this week («no security detection seen yet» = a gap, not a silence), blocks with the top keys, what was dropped (updates, WAN). Metrics `uso_watchman_net_entries{kind}`, `uso_watchman_net_lines_total{sender}`, `uso_watchman_net_last_line_timestamp_seconds{sender}`; Grafana panel 52. |
| `cleanup` | Ms. Dustdevil / Frau Putzteufel (it Signora Spolverina, fr Madame Plumeau, es Señora Plumero) | knows every corner of the server and clears away what nobody uses — two parts on her page (`#/cleanup/where`, `#/cleanup/tidy`; the head's filter works in both, its «Tour» runs `where_scan` then `scan`, «wake sleeping disks» wakes all of them first). **Where is what** (Benj, 2026-10-07: up to 1.30 the desk `whereabouts`, Ms. Whereabouts / Frau Wasistwo — gone, she has to know where everything lies anyway): agent/lib/where.php (the `wa…` functions kept), loaded by cleanup.php; state `data/cleanup-where.json` (API part `where`), du sizes `cleanup-where-sizes.json` (part `where-sizes`, jobs in her tick — `clTickParts()` / `clTickEach()`: her jobs, the backup flag and the du jobs each in a try/catch of their own, a part that throws is said once and stops no other); actions `where_refresh` (the API asks it when her look is older than 600 s: desk.json `"parts": {"where": {"refresh_after": 600, "action": "where_refresh"}}`, `apiPart()` like `apiState()` — the server's clock, never the browser's, the 10 s wait, hired only; the page only reads the part, `fresh=1` asks regardless; 60 s debounce in the agent), `where_scan` (`wake`: `waWakeDisks()`), `where_measure`, `where_sizes` — the first and the third quiet in core.js `QUIET`; the migration step `where-files` takes over `whereabouts.json` / `whereabouts-sizes.json` once (`officeMigrateWhere()`, agent/lib/migrate.php: plain files of the data folder only, sizes merged — the later measurement wins; then renamed aside `<name>.before-<version>`; up to 1.43 her start did it and deleted them). Page: the block `Where` at the end of her desk.js (own names, the page's `query`), texts `where.*` (the storeroom's places are `stored.*`), per browser `cleanup.where.*` (Ms. Whereabouts' `whereabouts.*` taken over once); the bubble: what she knows, what lies around, what she noticed (not «folders nobody uses» / «templates without a container» — her rooms tell those, with sizes) and her advice. Staff: the migration step `staff-merged` rewrites staff.json once by `OFFICE_DESKS_MERGED` (whereabouts → cleanup, hired since the earlier; `officeMigrateStaff()`: src/staff.php's `officeStaffMerged()` under its `.staff.lock`, the list before kept as `staff.json.before-<version>`; up to 1.43 the web side wrote it), `officeStaff()` reads it merged without writing and the agent's `STAFF_MERGED` (house.php) counts her as cleanup until then; core.js `MOVED_DESKS` sends `#/whereabouts…` to `#/cleanup/where`. Fit: every server (`where` without Docker and VMs). What she knows: what is where and going on; "where things are" (config files, boot medium, VM files) with their backup protection; "If I were you …" — her operational advice (waAdvice() reads a few settings; the tips are built in desk.js, «I know, thanks» per browser with a signature, so a tip returns when the situation changes; nothing Fix Common Problems checks): system shares on the array, the mover, exclusive shares, compose builds, spin-down, old disks, no parity at all (`no_parity`), the parity check, UPS, syslog, cron lines whose program went with its plugin (`waCron()` `gone`/`program` — `waCronGone()`, never under /mnt; for root's own crontab the cleanup command as information), running Windows VMs whose guest agent doesn't answer (`vm_windows`: waVm's `agent` from `waVmAgent()` — libvirt's status file in RAM, never virsh or a disk; `waVmStop()` the VM and the disks' shutdown time-outs) — see «VMs at the array stop», VM disk files far bigger than what they hold (`vm_sparse`, Benj 2026-10-07: waVm's disks and overlay bases carry `bytes` (apparent) and `allocated` from `waFileSizes()`, one stat; `waSparseDisk()`: apparent ≥ `WA_SPARSE_RATIO` 4× allocated and ≥ `WA_SPARSE_GAP` 200 GB more — Kopia reads such a file whole at its first upload, the holes as zeros, 1.6 TB for 21 GB on nostromo; advice while one goes to Kopia, else good to know; the file line on her VM page notes it; `testBackupSparse`), VMs with a NIC of Unraid's «Network Model: virtio-net» (`vm_netmodel`, info, Benj 2026-10-08: no vhost, every packet through QEMU — 2.2 Gbit/s per stream on nostromo, 93 with «virtio»; `waVmNetModel()` from waVm's `networks`, `testWhereVmStop`). No security tips — they are the night watchman's; while he isn't hired one tip (`security`, info) says so and links the team lead. Her users/share access and SMB/NFS sections stay a plain directory, with a line linking his watch book when he is hired (`Office.desks.get('watchman').hired` — the staff list, not his code). **A disk being built** (Benj, 2026-10-08, nostromo's first parity build): Unraid keeps `status="DISK_INVALID"` on a parity disk until its first sync is through, and on a disk being rebuilt — `waBuilding()` (var.ini `mdResyncAction` while `mdResyncPos` > 0: «recon P/Q» parity, «recon <n>» a rebuild — every array disk still called invalid meanwhile —, «clear» the DISK_NEW disk; a check marks nobody, that is the array's summary; percent, the time to go as Unraid's statuscheck reckons it from `mdResyncDb`/`mdResyncDt`, paused) gives the device `building` {what, percent, eta, paused}; the disk row shows an accent chip «parity being built · 81 %» / «rebuilding …» in place of the red status (`buildingChip()`, the tip has the time to go) and the bad count leaves such a disk out; without a resync DISK_INVALID stays red. `testWhereBuilding`. Read only. **Tidying up:** clears away Docker templates, Compose stacks, appdata folders, stray my-*.xml elsewhere, what deleted VMs left behind (domains folders, NVRAM, TPM, snapshot lists, unused disk images; VMs without disks are only pointed out), switched-off User Scripts that lie around, Docker's leftovers. Never deletes right away: renames into `_UnraidSecretaryOffice-trash` on the same filesystem (ZFS datasets with `zfs rename` next to it), `manifest.json` per run, put back or empty; Docker leftovers can only be removed. Only rename, never copy; nothing while a backup runs. **Mr. Restori's leftovers** (room `leftovers`, per restore): only what his journals name (`data/restore/<id>/journal.json`, read only from his root-only folder, `clRestoreJournals()`) in exactly his shapes with the restore's own time (`clLeftoverUnits()`: `aside` entries `.aside-`/`.putback-`/`.restored-aside-<time>`, his `_UnraidSecretaryOffice-restore/<time>` on the flash and in libvirt.img, the safety dumps' `<place>/restore/<app>/<time>/`; copy steps' `.restored-<time>`) — never a scan by name; each looked at cheaply (`clLeftoverPlace()`: parts per pool/disk through /mnt/user by the share's cfg, sleeping ones named, never looked at; libvirt.img only mounted); what his «Put back» still needs (`clRestoreUndoable()`) is «his way back» (put away only with the in-use warning); into the storeroom on its own filesystem inside its share (`clLeftoverTrash()`: the share's top or a dataset of its own inside it, never above the share; datasets with `zfs rename` next to it, kind `leftover`, `restore/<hash>/<name>` in the run), back only to his places (`clLeftoverHome()`); what his journals name is his room's only — left out of appdata/domains/isos exactly by path (`clWithoutLeftovers()`); his job file written after her last look (`restore_newer`, a stat from tick, `looked` = her last tour) makes her page look again once. **A dataset's size** (leftovers, appdata/domains folders, the storeroom's parked datasets) is ZFS's `used` with what its snapshots hold (`clZfsSpace()`: one `zfs list` per tour naming only those datasets, all on awake pools) — find sees only live files (a VM folder set aside: «0 B» though 17 GB in its snapshots). Missing pictures: containers without a picture on the Docker page/Dashboard (found like DockerClient::getIcon: template by Name+Repository, else the label; docker.json / question.png); logos from the CA feed, `CL_ICON_TABLE` and checked guesses — dashboard-icons URLs via jsDelivr, never bundled; set in the user template's `<Icon>` or the Compose Manager override (shape-checked, refused otherwise), old file to the storeroom (kind `icon`, put back only while unchanged), Unraid's cache filled; `cleanupIconLoopRisk()` feeds the caretaker's 7.3.2 check, stand-in question.png (RAM); local candidates (dockerMan/images named like the app, AppleTimeMachine.png for Time Machine) go to the page as data: previews in the state (PNG/JPEG ≤ 1 MB); uploads: the browser makes a ≤ 256×256 PNG, kept in `/boot/config/plugins/unraid-secretary-office/icons/<container>.png` (≤ 512 KB, set as file://, put back removes it while unchanged); `icon_url` from the stack's main app (`clIconMainRank()`). **What partners left** (room `partners`, stage 2): `clPartners()` — `<pool>/UnraidSecretaryOffice-partners/<id>` on awake pools whose pair is gone from pairs.json (size `used`, what its and its units' snapshots hold), `why` transfer while a `door-*.json` names it; put away with `zfs rename` next to it (kind `partner`, the run's manifest in the flash's storeroom, `from` `/mnt/<dataset>`, back only by `clPartnerHome()`); of a pair still there each unit dataset `<pair>/<unit>` its `receive.units` no longer names (the Team Lead's «Keep less of …», 2026-10-08: category `dropped`, «<unit> of <name> — no longer kept», its `used` and `unit_snaps`; `why` transfer while a door transfer names that unit, `clPartnerUnitBusy()`), put away into the partners' place on its pool (`…-partners/_UnraidSecretaryOffice-trash-<run>-<unit>-<pair>`, out of the pair's dataset; back by `clPartnerHome()` / `clPartnerTrashOk()` into its pair's dataset); until then Ms. Snapshotini treats it as the pair's copy; «Where is what» lists the partners' places per pool and pair (`waPartners()`); `testCleanupPartner` |
| `advisor` | The Consultant / Der Berater | an external: whether the tools the office relies on are there (Fix Common Problems, Files Viewer, a Kopia container, Stream Viewer where Emby/Jellyfin/Plex runs; unbalanced optional, never suggested — it can get in the way of backups, EmbyCache and the gather), what they are good for, who needs them, how to install them by hand (ADVISOR_EXTERNALS in agent/desks/advisor.php, EXTERNALS in his desk.js). Optional monitoring (group `monitoring`, never counted as missing): Node Exporter (container recommended, ich777's plugin counts; its textfile collector reads `/mnt/addons/UnraidSecretaryOffice/metrics`, he shows whether it does) → Prometheus (ready prometheus.yml command, never overwrites) → Grafana; Loki `later` (for the night watchman). **Installs, second offer after the manual way** (preview + confirm, never over what is there, detection by image): plugins with Unraid's `plugin install <url>` as an atd job (URLs pinned in ADVISOR_EXTERNALS `plg`, output read back by the open action `job`; never unbalanced); containers through Unraid's own Add Container form — his template (`public/desks/advisor/templates/<id>.xml`, from the CA template, names as CA's: `kopia`, `Node-Exporter`, `prometheus`, `Grafana`, label `uso.installed-by=consultant`) filled in into `RUN_DIR/templates/` and the form opened with `xmlTemplate=default:<file>`; the user clicks Apply, Unraid writes `my-<Name>.xml`; refused while a container of that kind, that name or a `my-<Name>.xml` exists. Beforehand, only where nothing is: prometheus.yml; Grafana's provisioning (`GF_PATHS_PROVISIONING=/var/lib/grafana/provisioning` in his template; data source uid `uso-prometheus`, the office's dashboard from `monitoring/grafana-dashboard.json` with its input filled in, the empty folders `plugins` and `alerting` Grafana logs errors for — `ADVISOR_GRAFANA_EMPTY`; for an existing Grafana the same files plus the one template change; `GF_SECURITY_ADMIN_PASSWORD` optional, Benj 2026-10-06: without it admin/admin, Grafana asks for a new one at the first login — the page warns about admin/admin for every Grafana). What he installs he notes in his root-only record for the night watchman (`advisorRecord()`, see there). His page looks again when his state is older than a minute (desk.json `refresh_after` 60; his scan ≈ 40 ms on nostromo) and, for `ADVISOR_RELOOK_FOR` after he prepared a form, his tick looks every `ADVISOR_RELOOK_EVERY` s (`docker ps`, `advisorRelookDue()`) whether the container came, then scans once. Kept current afterwards (no confirmation, `advisorDashboardKeep()` at his scan and hourly while Grafana runs, stat-cached, logged once): only `<provisioning>/dashboards/uso/unraid-secretary-office.json`, only while it is a plain file in a real folder whose JSON has the uid `unraid-secretary-office`, only when it differs from `advisorDashboardJson()` — new file + rename, owner/group/mode kept; Grafana reloads it and never saves provisioned dashboards. The data source and provider yaml stay only-when-absent. Kopia: `/uso` (ro,slave) and `/uso-restore` (`<share>/restore/kopia`, rw), PUID/PGID 0; the repository assistant (S3 or a folder, create or connect) takes the keys and password only after an explicit confirmation, through RAM (see the web-side exception above) to `docker exec -i … sh -c` on Kopia's stdin, restarts the container once, keeps only the non-secret facts; the recovery sheet is made in the browser only. **Ransomware protection (S3 Object Lock):** before a new repository in S3 the assistant asks the bucket (`kopia_repo` with `step: probe` — the keys through RAM like the setup; `advisorObjectLock()`: one `GET ?object-lock`, AWS Signature V4 made in the agent (`advisorS3Sign()`), PHP's curl, HTTPS only, certificate checked, no proxy, no redirect; Amazon virtual-hosted and signed again for the region the bucket names, elsewhere path style): `enabled` → offers COMPLIANCE for N days (`ADVISOR_LOCK_*`: 30, 7–365; preselected — the bucket was made with it on purpose; with the cost note), `off` → where providers switch it on (a bucket made with it), `unsupported` (MEGA S4 answers 501; `ADVISOR_NO_LOCK` for an unclear no) → said plainly, no option, `unknown` → why (keys, denied, no_bucket, unreachable, other), no option. `lock_days` on the create: the bucket is asked again (not enabled → `ad_lock_off`, nothing created), Kopia gets `--retention-mode=COMPLIANCE --retention-period=<n>d`, then `kopia maintenance set --extend-object-locks=true`. Create or connect, `advisorKopiaLock()` reads the repository's lock (`repository status --json` → `blobRetention`, `maintenance info --json` → `extendObjectLocks`) for the done dialog and the recovery sheet (its line, and `--point-in-time` after an attack). «Install by hand» for Kopia ends with «Ransomware protection with Object Lock» (what, why, who offers it — not MEGA S4 —, how, the price, after an attack) and its part «versitygw: your own S3 server on a second Unraid» (`lock.vgw*`, 2026-10, replaces MinIO — its community edition is archived; versitygw's posix backend has Object Lock, verified in briefs/versitygw-findings.md: the container by hand through ⟦Add Container⟧, host network + VGW_PORT on the tunnel address, VGW_VERSIONING_DIR, TLS — `tailscale serve` or a reverse proxy, the probe wants a checkable certificate —, a user with role «user» and a bucket born with the lock per repository, no quota of its own), followed by the guide «Encrypted Kopia to a partner's server» (`partner.*`: the partner holds only encrypted blocks, the password and the recovery sheet stay here; WireGuard ⟦Server to server access⟧ recommended, Tailscale the easy one, he explains tunnels and never builds one; the first upload in the LAN, then carry the box; his uptime is the backup's, root at his place beats every lock, 3-2-1; the ZFS partner service as the other way) — `paragraphs()`/`copyLines()` in his desk.js, `testAdvisorPartnerGuide`. He never logs into a web UI or an HTTP API: files and command lines only. **The network** (group `network`, stage 1 of the router SOC): a new shape of entry in ADVISOR_EXTERNALS — **a setting he explains and never changes** (`setting`: `syslogserver`, his scan's `watchnetAdvisor()`: what rsyslog.cfg says, the loop of ⟦Remote syslog server⟧, a share that sleeps or is exported, where a share `syslog` of its own belongs (Benj, 2026-10-08: the guide says create one, ⟦Shares⟧ → ⟦Add Share⟧, never pick an existing share — `pools`: the pools that never spin down, `watchnetAlwaysOn()`, never the boot pool (a Boot slot's device); `array_ssd`: the array only when every data disk is an SSD; none → «every pool here spins down»), and while the share's disks are awake the senders with a file and its age; the steps with Unraid's labels, a link to ⟦Syslog Server⟧) and two `guide`s: `unifi` (Network 10.x: Settings → CyberSecure → Traffic Logging → Activity Logging (Syslog) → SIEM Server, the contents, «Blocked Traffic Only», Debug Logs off; one SIEM destination — Unraid or FireSight, not both; a «View Only» admin exists, the office holds no router secret; MikroTik/OPNsense/OpenWrt later, Fritz!Box cannot; opening it posts `network_seen` → `data/advisor/network.json`, the Team Lead's `syslog_off`) and `neighbours` (the neighbours note's paragraph — FireSight, Loki + Alloy, CrowdSec, a real SIEM — and who phones out). `networkEntry()` in his desk.js |
| `restore` | Mr. Restori / Herr Restori | brings apps and VMs back (calm restorer in white gloves, «Piano, piano»; never over something that is still there without asking, everything he replaces is put aside first, never deleted). Reads: per app/VM what comes back from the package (and earlier nights from the backup place's snapshots), local snapshots and Kopia, chips grouped «Package» / «Data» (a package in Kopia is not the data), ready-made commands, the Kopia guide, onto a new server; he reads the packages himself (`rsPackages`). Tile «Databases»: every dump and SQLite copy of all packages per app (`dbGroups()` in desk.js from the state's apps; one media server's copies are one entry, they go back together), where the package is kept (`package_protection`, `rsPackageProtection()`), «Restore…» opens the existing `db`/`sqlite` plans, earlier nights from `restore.versions` (which carries `sqlite` too). Restores: every restore is planned against a fresh look (`rsPlan` → preview: steps, what goes aside where, what stops how long, sizes — `du` in his tick on the source as `<path>/.` (a ZFS snapshot not mounted yet), allocated and apparent (sparse files said so); a ZFS source counts with its snapshot's logicalreferenced/referenced where the target doesn't compress; copies with rsync `--sparse`), confirmed with the plan's token (`rsStart`; a changed server = `restore_changed`), run by atd as `php agent.php job restore <id>` (`rsJob`). The job takes the engine's `state/lock` non-blocking (busy → journal `refused`, `restore_busy_<holder>`, exit 75), writes `state/lock-holder.json` `{holder: restore, mode: <kind>, what, pid, started}` (new file + rename, removed only while its pid), journals each step in `data/restore/<id>/` (plan.json, journal.json, log.txt, root only) and `data/restore-job.json` (page polls api part `job`, the Dashboard shows a row while its heartbeat is fresh). Stops at the first failed step; each step records its undo, «Put back» (`kind putback`) runs those newest first between the restore's own container handling — also a job, also puts aside (`.putback-<time>`); the restore's journal then says when and where its state went instead of «Afterwards». «Put back» is only the undo — the package's buttons say «Restore…». Kinds: `db` (safety dump into `<place>/restore/<app>/<time>/`, the app's other containers stop; Postgres the fresh way when the cluster's folder can be put aside — container stops, folder aside (dataset: `zfs rename`, new dataset with its local properties), starts on an empty folder, ready over TCP, dump in, tables checked; Immich only that way, its search_path line replaced while streaming; else in place after ending other connections; MariaDB/MongoDB in place; credentials only as variable names from `RS_ENV_VARS` in `sh -c` inside the container), `sqlite` (a media server's copies, -wal/-shm aside), `files` (a folder unit — or a whole share an app binds, all of it or chosen entries at its top, ≤ `RS_ENTRIES_MAX` — from a *moment*: snapshots grouped by run (the engine's, on all of a share's pools and disks at once, ZFS and btrfs alike) or by name (others, maybe on some parts only — the list says which, a part with content the moment doesn't cover is warned); the source is the union of the parts holding it (primary storage first: rsync with several sources, the first wins a name, like shfs shows it), the default the newest moment that holds something (a sleeping part: «wake», never «nothing there»; ticked, `rsWake()` reads one block from each disk of the share's sleeping parts and waits ≤ `RS_WAKE_TIMEOUT`, one that doesn't answer stays asleep — `restore_wake_failed`; the dialog keeps every choice across re-plans, each control sends only its own field); the target is the share, `/mnt/user/<share>/…`, so Unraid places it by the share's settings (an exclusive share: the link to its pool; room = primary + secondary from disks.ini less the minimum free space): copy to `<name>.restored-<time>` or swap — copy first, then stop its users (a whole share: what binds the share too), the live one aside through shfs (all parts at once), copy in; nothing there (an empty share, a folder that is gone or empty — an empty one goes aside too) → «put in place», the default; a ZFS dataset of its own on one pool stays one (zfs rename on its pool); refuses swap with datasets inside or an own mountpoint; never swaps or puts back a folder holding the disks (not CD-ROMs) of a VM that isn't shut off (`restore_vm_uses`; libvirt's disk sources however the path is written; the job's step `vms_off` looks again right before anything is replaced — he never stops a VM); a dataset of its own put aside takes its snapshots along (said so), they stay offered as moments `aside:<folder>@<snap>`; through /mnt/user any sleeping part of the share blocks without `wake`; a missing share blocks — Unraid creates shares, never he — with its old settings from the package's `server/shares/<share>.cfg` as information only (pool names may not exist here, said so)), «Shares it needs» per app and VM (there / empty / missing / asleep — emhttp's shares.ini and the top folder on the awake parts; data restores into a missing share are disabled, also listed in «Onto a new server»), `config` (templates/compose files that differ or are missing; aside on the flash into `/boot/config/_UnraidSecretaryOffice-restore/<time>/`, elsewhere `<file>.restored-aside-<time>`; never starts or recreates containers — says what to click), `vm` (XML, NVRAM, TPM only while shut off; aside in `/etc/libvirt/_UnraidSecretaryOffice-restore/<time>/`), `kopia` (into a writable rw mapping of the Kopia container named *restore*, as the server's UID like the engine's `kopia_x`; without one he explains the template path; the list shows what a snapshot holds, `rootEntry.summ`, not `stats.fileCount` — the files read anew). One restore at a time; Mr. Backupsy refuses (`restore_running`) and says so, Ms. Dustdevil refuses (`cleanup_restore_running`). His `fit` (`rsFit()`, state files only): `packages` (his last look, or last-run.json's `packages` when written after it) / `no_packages` (set up, none yet) / `with_backup` (ZFS/btrfs, nothing yet: hire with Mr. Backupsy) / `nothing` — never snapshots alone (his page has nothing without packages) **Sleeping ZFS pools** (2026-10-07 — until then his `zfs list` ran over every pool too): `rsContext()` → `rsContextZfs()` lists datasets and snapshots on the awake pools only (`poolsBySleep()`, `zfs list … -r <pool>`); a sleeping pool keeps what he last saw of it in a file of his own, `data/restore-zfs.json` (`rsKeptFile()`, written by `rsScan()` only — per pool `looked`, `mounts`, `snaps`; read back only in that shape), apart from the live lists: `rsLocate()` gives a sleeping ZFS place `kept` (`rsKeptLook()`: the dataset that held the path then, how many snapshots, the newest, `looked` — the page says «as of …: n snapshots» beside «asleep»), never a path into it; a restore from it needs «wake» as before, and once `rsWake()` answered the woken pools are listed into `$ctx` (`rsContextZfs($ctx, $pools)`); `state.pools_asleep` names them with when he last looked. Tests: `$GLOBALS['rs']['fs'|'zfs_bin'|'kept_file']`. **Drill** (stage 1 of the restore drill concept, 2026-10-08; `agent/desks/restore-drill.php`, functions only — the desk glob loads it before restore.php, which `require_once`s it: nothing at its top level may use restore.php's constants; `public/desks/restore/drill.js`, loaded by desk.js from next to itself and handed desk.js's helpers; section and tile «Drill», `#/restore/drill`): he proves on throwaway objects that the backups come back and writes a certificate. Per step: packages L1 (every app, VM, `server/`, `flash/`: each manifest file there with its bytes, .gz `gzip -t`, a dump's closing line, XML read with `LIBXML_NONET`, the VM's name/UUID, NVRAM, TPM, `tar -tzf` of the libvirt/flash archives; a kept older dump = `dump_old`; the first failure names the item), dumps L2 (from the local snapshot of the package's run — `drillLocal()`: `rsLocate()` + the moment `run:<run>`, a sleeping part «asleep» —, else the package; a throwaway `uso-drill-<id>-<n>` of the manifest's **image id**, built only by `drillContainerArgs()` and checked again by `drillArgsSafe()` before `docker run`: labels `uso.drill=<id>`, `uso.installed-by=restori-drill`, the office's icon, `--network none`, no restart, `--memory`/`--memory-swap`/`--cpus 2`/`--pids-limit 512`, `no-new-privileges`, small logs, the data dir and every image volume on `--tmpfs` (else Docker makes anonymous volumes), env only `DRILL_ENV_KEEP` plus its own `POSTGRES_HOST_AUTH_METHOD=trust` / random `MARIADB_ROOT_PASSWORD`, the manifest's entrypoint and command (Immich's preload), MariaDB `--max-allowed-packet=1G`; never a port, bind, volume, device, capability, app label or app login; then `rsDoReady` over 127.0.0.1 (`tcp` also for MariaDB now: its init server runs with skip-networking), `rsDoPlay` (method fresh) within a play budget, `rsDoVerify`, the extensions per database, the live database list read-only via `rsDbScript('databases')` in the app's container (setting `live_catalog`); RAM budget 25 % of MemAvailable, ≤ 8 GB — a dump needing more (uncompressed × 3 + 512 MB) is «not checked: too big for RAM»; removed right after its step), SQLite L1 (`sqlite3 -bail 'file:…?mode=ro&immutable=1'`: integrity_check, user_version, the main table — MediaItems, TypedBaseItems, BaseItems, metadata_items — against the live database `mode=ro`, setting `live_sqlite`), VM disks L1 (`drillQcow2()` reads the header itself, never qemu-img; every backing file inside a snapshot of the same run — a relative one next to the overlay, an absolute one via `drillLocal()`; a raw base: MBR/GPT, else not all zero in its first MiB), Kopia (`drillKopiaCmd()`: the server's uid, `KOPIA_CACHE_DIRECTORY`/`KOPIA_LOG_DIR` under `/tmp/uso-drill-<id>` in the container, `--log-dir`, `--disable-content-log`; `snapshot list` L0 (complete, failed files, age), `snapshot verify --sources=… --verify-files-percent=0`, a sample through `kopia show <obj>` streamed and hashed in PHP — directory objects walked as JSON (`drillKopiaEntries()`), an app's/VM's own source: its package (dumps first), a share: a few random files ≥ 1 KB — within `kopia_mb` (default 1024), compared with the local snapshot of the run its description names; all Kopia steps ≤ 30 min). The job: `drillPlan` (preview: counts, estimate, RAM, Kopia budget, next backup, deadline, asleep, live reads, too big; blockers `drill_array|drill_parity|drill_running|drill_deadline|drill_nothing` and `rsBusy()`) → `drillStart` (token) → atd `php agent.php job restore-drill <id>` (`drillJob`): refused (exit 75, history only) when the array isn't started, a parity check or rebuild runs (`mdResyncPos`), the deadline is near or the lock busy; the lock as `{holder: restore, mode: drill, what: drill}` (Mr. Backupsy says `notice.drilling`, Mr. Restori's page `notice.busy_drill`); the RAM marker `RUN_DIR/drill.open` for agent.sh's `drill_release` (which also ends a restore by `restore.open`, written by `rsJob`); `rsBeat()` → `rsWatch()` looks at var.ini every 2 s, also during long commands (`drillRunStoppable()`, `drillGzTail()`) (Stopping/Stopped → `rsStopWhy` `array_stopping` — restores too, their reason `restore_array_stopping`) and at `$GLOBALS['rsDeadline']` (the drill's: 90 min, ≤ the next backup − 15 min from `officeJobSchedule('backup')`; a play's own: `budget`); in its own process the job points `$GLOBALS['rs']['data'|'job_file']` to the drill's folder, so his restore helpers write into `data/restore-drill/<id>/` (plan.json, journal.json, log.txt, root only); `made` in the journal before each create (`drillMade()`, also `data/restore-drill/record.json` for the watchman, `{made: [{t, kind, name, image, id}]}`, 0600, newest 50 within 7 days — his adoption is stage 2); at the array stop the lock goes first and only the throwaways are removed. The sweeper `drillSweep($current, $deep)`: journals whose job is gone (interrupted) lose what they made; deep (a drill's start and end, every agent start) also every container with the label whose name, label and id pattern all match — never the drill going on; hourly in the tick only the journal folder. The certificate `data/restore-drill.json` (api part `drill`; `interface: 1`, `last` {id, started, ended, result passed|failed, scope, proven, warnings, failed, not_checked, asleep, items, egress}, `last_passed`, `items` [{kind, of, id, name, what, level, copy package|snapshot|kopia, run, state_time, result ok|warning|failed|not_checked|asleep, code (`drill.code.*`), params, seconds, at}], `lose` per app/VM {local, kopia, played, best}, `history`: the last 12, also refused, aborted and interrupted ones): passed = nothing failed — warnings and «not checked» said; an aborted drill only joins the history. The page polls `data/restore-drill-job.json` (api part `drill-job`). On his own (`drillTick`: once a minute, nothing read outside 00:00–07:00, only while hired): after a real run that ended ok/warnings within 6 h, `schedule` monthly (none this month yet) / weekly / off, packages ≥ 7 days (`history.jsonl`'s first line), one try per run (`data/restore-drill/auto.json`). Settings `data/restore-drill/settings.json` (`drill_set`: schedule, kopia_mb, live_catalog, live_sqlite). A failed drill: one `warning` (`notify.drill_failed`); a passed one stays quiet. Team Lead: `drill` (a passed drill within 60 days, once packages exist for 30 days) and `drill_failed`, both recommended, linking `#/restore/drill`. Mr. Backupsy's line (`backupDrillLine()`) and the Dashboard's row (`officeDashDrill()`, only when he isn't restoring) read the certificate only; metrics `uso_restore_drill_*`. Not yet (stages 2/3): dumps from Kopia, follow-up drills, the engine's holder `drill`/`lock-want`/sha256 per file, the watchman's adoption, Ms. Dustdevil's «what a drill left», the VM boot test. Tests: `testRestoreDrill` (fixtures `tests/fixtures/restore-drill/`: nostromo's manifests, selected keys, values scrubbed; `$GLOBALS['drill'][…]` for places, a `docker` stand-in and `base`). **Across servers** (partner offices stage 3, 2026-10-08; `agent/desks/restore-partner.php`, functions only like restore-drill.php — loaded before restore.php, which `require_once`s it): tiles «At <partner>» after «Databases», one per source — a pair I send units to (my key), or on a new server a ticket pair (`rspSources()`); what it keeps of mine comes from the door's `list <unit>` asked by the job `php agent.php job restore-partner-look` (`rspLookJob()`, one at a time, RUN_DIR pid), handed to atd from his tick (`rspTick()`: a source not asked for 6 h, 30 min after a silence, or «Look again» = `partner_look` → a file in RAM; never from a page request), kept in `data/partner/<id>/held.json` (root only, `looked`/`tried`/`reachable`/`why`, per unit the snapshots with used/referenced/creation; unreachable: what was known stays, «as of …»); his state's `partners` (`rspState()`), the chip «at <partner>» on app/VM rows whose shares or own VM unit a partner holds (`rspChips()`, `at_partner`). **Kind `partner`** (`rsPlanPartner()`): `{pair, unit, snap[, pool]}` → steps `pull` + `mount` (a VM: its package's place pulled along when the partner holds `place` — `note.partner_place_too` —, else `note.partner_no_package` «the package isn't at the partner — only the disks come back»); always a NEW dataset beside, never over (`rspTarget()`): a pair → `<the unit's dataset>.restored-<time>` (the dataset from the engine's `state/partner-sent.json`, else partnerUnits()), a ticket → `<pool>/<share>.restored-<time>` where that share exists as a dataset (the swap is the user's, `after.partner_swap` says how), else `<pool>/UnraidSecretaryOffice-restored/<unit>` (parent `mountpoint=none canmount=off`; Unraid makes shares, never he); sizes from the partner's `referenced` against the pool's avail. `rsDoPull()`: `ssh` (partnerSshArgs, the door's `send-back <unit> <snap>`) | `pv -n -b -i 2` | `mbuffer` | `zfs recv -s -u -o mountpoint=legacy -o canmount=noauto -x sharesmb -x sharenfs <ds>@<snap>` (writable; canmount/mountpoint set again after), the door's two JSON lines from ssh's stderr, progress `{done, total}` from pv and the door's `size` into the job file; an interrupted pull keeps its token here (`zfs recv -s`), the next plan of the same pull/unit/moment (`rspResumable()`, his journals) goes on with `send-back <unit> -t <token>` into the same dataset (`note.partner_resume`); the array stop ends it like his other jobs (rsWatch → the pipe's processes terminated, `pull_stopped`); its undo is `drop` (`rsDoDrop()`: only a name of his own — `.restored-<time>` or under UnraidSecretaryOffice-restored —, the guid the pull recorded, no dataset inside; unmounted, then `zfs destroy -r`; the journal's «Remove what was pulled…» runs it through «Put back»'s preview and token). `rsDoMount()`: `mount -t zfs -o ro,noatime` under `/mnt/addons/UnraidSecretaryOffice/restored/<partner>-<unit>-<time>` (`RSP_MOUNT_ROOT`); again at every agent start (`rspRemount()`), unmounted by agent.sh's `restored_release` at the array stop. What landed (`rspPulled()`: journals of kind partner, `pulled`, not dropped): moments «pulled from <partner>» for his files restores (`rspRestored()`, a Kopia-like moment with `partner`), a pulled place as an earlier night's packages (`rspPlaceSnaps()` in rsVersionList) and, with no place of its own (a new server), as his packages (`rsPlacePulled()`: read-only — safety dumps then go to `data/restore/safety`). «Onto a new server» shows a ticket's units, the place first, «Pull…» each (`ticketBlock()` in desk.js); his fit `partner_ticket`. Tests: `testRestorePartner`, `testPartnerTicket` (`$GLOBALS['rs']['restored_root'|'mounts_file']`, stand-ins for ssh/zfs/pv/mbuffer/mount). |
| `caretaker` | The Team Lead / Der Teamchef (until 1.23: the caretaker / der Hauswart; the id stays `caretaker`) | leads the team (hires, fires, suggests whom to hire); collects every desk's `checks` and what the office needs — among his own whether Unraid's API service answers (`api_down`, recommended: the office works without it, Unraid's bell — where its reports show too — and parts of its web UI don't; `caretakerApiUp()`: one keyless `isSSOEnabled` straight over `/var/run/unraid-api.sock` with PHP's curl in-process, no key, no nginx, ~2 ms, ≤ `CARETAKER_API_WAIT_MS` 2 s; only where `/etc/rc.d/rc.unraid-api` exists; the text says Management Access → «Unraid API Status» → «Restart API» or `unraid-api restart`; never in the night shift — his checks run in the agent only; `testCaretakerApi` with a stand-in API on a Unix socket); tells the user what is left to do; reports new red findings to Unraid's notifications (see Notifications); «I know, thanks» (`ack`/`unack`) puts aside recommended findings and hints — never musts — in `data/caretaker/acks.json`, keyed by sig = desk:id:hash(level, params without `days`/`size`, the desk's English `check.<id>`/`check.<id>_how` — 1.44, `testAckContent`: an update that changes what a finding says or recommends brings it back once, a plain version bump keeps it; notes from before, `v` 1, are taken over at the next tour as given for today's texts, `caretakerAckHas()` reads both); noted ones count nowhere (bubble, mood, reception, Dashboard tile); forgotten once in place or unseen for 30 days. Params that grow on their own must be named `days`/`size`. **Partner offices** (2026-10-07, stage 1a of briefs/uso-partner-zfs-plan.md): the pairing, the partner cards and the mutual watch are his — `public/desks/caretaker/partner.js` (loaded by desk.js, `Office.ctPartner`), actions `partner_add|accept|finish|end|ping|state` → agent/lib/partner.php, his state's `partners` (`partnerPublic()`: never a key or a block), the job `partner-ping` and the finding `partner_silent` — see «Partner offices» below. **What a pair covers changes without a new pairing** (2.29): the sender's ⋯ «Change what <host> sends…» (`partner_change`: units left out leave `send.units` at once — the copies there stay, the card says «no longer sent» —, new ones must be `ok` in `partnerUnits()` and are asked for through the door's `offer`; `send.wanted` = the choice, `send.units` = wanted ∩ the partner's agreed), the receiver's card a chip «<name> would also like to send: …» with «Keep it too» / «No» (`partner_wish`, `confirm` needed: `receive.units` grows — pool, quota, retention, window stay — or the wish file `data/partner/wishes/<id>.json` goes), the hint `partner_wish` (never red); the receiver's ⋯ «Keep less of <name>…» (`partner_keep_less`, `confirm` needed, at least one unit stays — else «End the partnership»; the copies stay, the door refuses the units left out); the mutual watch follows `status.agreed` (`send.units` = wanted ∩ agreed — a unit the partner stopped keeping is not asked for again by itself) and offers again only while `send.offered` is null (the wish as it stands hasn't reached the partner — unreachable, its array stopped). Not yet: Ms. Dustdevil listing a unit's copies the agreement no longer names (her rule knows ended pairs only), Mr. Restori's «At <partner>» lists `send.units` only (a unit no longer sent but still kept there isn't offered) **Restore tickets** (stage 3): «Start from a partner's copy…» (a new server: `partner_ticket_start` → its key, BLOCK-N), «Hand <name>'s copies to a new server…» on a card whose copies are kept here (`partner_ticket_make` → the ticket line, BLOCK-T and the code), «Paste the ticket» (`partner_ticket_finish` → a ticket pair), tickets on the cards with «End the ticket» (`partner_ticket_end`); the tidy (`partnerTicketsTidy()`) at the agent's start and once a day in his tick. **Unraid's syslog server for the router** (`watchnetChecks()`, agent/lib/watchnet.php — the flash, emhttp's ini files, `docker ps` and an inspect of a known syslog image only): `syslog_share_sleeps` (the folder's share has disks that spin down; required when it lives on the array only), `syslog_no_rotation` (required: off, or size × files > 2 GB), `syslog_share_public` (exported over SMB/NFS — the files are writable, sec.ini/sec_nfs.ini), `syslog_port_taken` (a container holds the syslog port: FireSight, Alloy, syslog-ng, Promtail, CrowdSec … named by image, `watchnetHolds514()`), `syslog_loop` (⟦Remote syslog server⟧ is one of this server's addresses or names), all while the server is on; `syslog_off` (hint: the night watchman hired and the Consultant's router guide opened, the server off). Links `#/advisor` while he is hired |

Desks know each other only through shared libraries (`backupProtection()`,
`finding()`) and links (`#/<desk>`) — each one must work on its own.

**Partner offices** (2026-10-07, stage 1a; the plan: briefs/uso-partner-zfs-plan.md — partners, never a master, never
automatic failover): two offices keep copies of each other's engine snapshots (`uso-backup-*`, ZFS datasets only:
shares that are a dataset of their own, VMs with one, the backup place's), each with a retention of its own. **The pairing**
(the Team Lead): «Add a partner…» makes the pair's id (8 hex), its key (`ssh-keygen -t ed25519`, private key
`/boot/config/plugins/unraid-secretary-office/partners/<id>.key` 0600) and BLOCK-A (base64 JSON: id, name, address chosen
from the server's own, port, the full sshd host keys, the pair key, trust, units) — an offer in `data/partner/pending.json`
for 7 days; the partner's «Accept a partner…» decides what it keeps (pool, quota, `d w m` retention, window, wake, which
units) and whether it sends too, writes the line after a dialog quoting it, answers with BLOCK-B and the SAFETY CODE
(`partnerSafetyCode()`: sha256 of both pair keys and both host-key sets, 6 digits); «Paste the partner's answer» shows the
same code, «They match» stores the pair and pings. Blocks are validated field by field (`partnerBlockDecode()`), a public
address is a warning, never a refusal. **The pairs file** `data/partner/pairs.json` (folder 0700, file 0600 root,
`writeAtomic()`; read back only in exactly its shape — `partnerPairValid()`, plan §3.2 plus `receive.units`, the units of
theirs this office agreed to keep), the partner's host keys pinned in `partners/<id>.known` (`[address]:port` for a port
≠ 22). **The line** in Unraid's `/boot/config/ssh/root/authorized_keys`: `restrict,from="<partner IP>",command="…/scripts/
partner-door.sh <id>" ssh-ed25519 … uso-partner:<id>` — only lines with that comment are ever written or removed, every
other byte stays, never through a link (`partnerAuthKeysEdit()`); a hostname is resolved for `from=` (Unraid's sshd
matches IPs); missing or changed → the card says «door closed/changed», never rewritten unasked. **The door**
(`plugin/scripts/partner-door.sh` → `agent/partner-door.php`, root, cwd /, umask 077, `env -i`): `SSH_ORIGINAL_COMMAND`
word by word (≤ 5 words of `[A-Za-z0-9._:-]`; `offer` up to 65), verbs `ping` (RAM and flash only — answers while the array is stopped, nothing
under /mnt), `status` (since 2.29 with `agreed` = receive.units and `wish`), `quota`, `list <unit>`, `resume <unit>`, `offer <unit>…` (2.29: the units not
agreed become the pair's wish, `data/partner/wishes/<id>.json` `{pair, units, time}` 0600, overwritten; answers `agreed` and `pending`; never
changes the agreement, never a refusal for a unit not agreed; a ticket can't), `recv <unit> <snap> [<from>|-t]`, `send-back <unit> <snap> [<from>]` / `send-back <unit> -t <token>` (stage 3, below);
units against the agreement, snapshots exactly `uso-backup-YYYYMMDD-HHMM`; `recv`: window, pool awake or `wake`, quota,
the chain (`need_full`, `exists`, `no_token`; a full stream onto an existing unit puts the old dataset aside —
`zfs rename <ds> <ds>.old-YYYYMMDD-HHMM`, never destroyed, put back when nothing was received, `aside` in the answer; a
resume token met by a call without `-t` is a stale partial receive: `zfs recv -A` first, logged), one per pair (flock `RUN_DIR/partner/<id>.lock`),
`mbuffer -q -s 128k -m 256M` → `zfs recv -s -u` into `<pool>/UnraidSecretaryOffice-partners/<id>/<unit ':'→'-'>` (parents
`mountpoint=none canmount=off`, quota on `<id>`; the first receive `-o mountpoint=legacy -o canmount=noauto -o readonly=on
-x sharesmb -x sharenfs`, later and resumed ones `-x mountpoint -x canmount -x sharesmb -x sharenfs` (a descendant a stream
brought could never carry its own mountpoint into the next boot; a resumed first receive gets the three properties set
after), never `-F`; the target is always `<ds>@<snap>` — ZFS then refuses a multi-snapshot stream (`-R`, `-I`: «cannot
specify snapshot name for multi-snapshot stream», tried on a throwaway pool), so the sender sends `-i`), JSON on stderr
before and after (`resumable` when a token was left), registered in `RUN_DIR/partner/door-<pid>.json`;
afterwards the receiver's retention (`partnerRetentionSelect()`: the engine's `zfs_prune_select()`, only `uso-backup-*`,
never the newest, never held) recorded in `data/partner/deletes.jsonl` and `received/<id>.json`. Refusals: a line in
`data/partner/door.log` (RAM when the data folder is away) and their times in `RUN_DIR/partner/refused-<id>.json` (the
night watchman's `door_refused`, stage 2); every knock touches `RUN_DIR/partner/heard-<id>`. **The client** is
`partnerSsh()` (the plan's ssh options, `PARTNER_SSH_OPTIONS`, the pair's key and known_hosts). **The mutual watch:**
`caretakerTick()` hands `php agent.php job partner-ping` to atd every 15 min while pairs exist and `state.json` is older
(one stat); it pings (then `status`), writes `data/partner/state.json`; a partner unheard for 6 h is `partner_silent`
(recommended, params stay the same within one silence), told once per silence and every 24 h (warning, with Tailscale's
word where it is), muted by «I know, thanks». **Hooks:** `agent.sh array stopping` → `partner_release` (SIGTERM to
registered door processes and their zfs/mbuffer, ≤ 5 s); the `.plg`'s remove takes the office's `uso-partner:` lines out of
authorized_keys (byte for byte) and `partners/` off the flash; the copies stay. Tests: `testPartnerPairing` (two offices,
ssh played by a stand-in that checks the pin and runs the forced command), `testPartnerDoor` (stand-ins for zfs, zpool,
mbuffer), `testPartnerWatch`, `testPartnerRelease`. Stage 1b (the engine's phase `partner`, Mr. Backupsy's rows) and stage
2 (the night watchman, Ms. Snapshotini, Ms. Dustdevil) build on this. **What the other desks do with it** (stage 2,
`agent/lib/partnerlook.php` — read-only looks at these files, never a key): the night watchman knows the door (the office's
own line noted, `door_changed`, `door_key_moved`, `door_refused`, transfers as the office's own data flow, `deletes.jsonl`
no `snap_gone`, posture tips), Ms. Snapshotini chips a partner's copies and keeps her hands off them, Ms. Dustdevil puts
away what an ended pair left, Ms. Protocolli reads `door.log`. **The way back** (stage 3, 2026-10-08): the door's `send-back <unit>
<snap> [<from>]` sends the pair's own copy (`zfs send -L -c [-i]` | mbuffer → stdout; only this pair's unit datasets, the snapshots
there, `need_full`/`no_snapshot`), `send-back <unit> -t <token>` resumes the puller's interrupted receive — the token comes from the
puller (its `zfs recv -s`), so the door runs `zfs send -nvP -t` first and sends only when its `toname` is exactly this unit's dataset
and an engine snapshot (`token_other`, `bad_token`; the request may be longer than 256 characters only in this form); JSON on stderr
before (`size`) and after, one transfer per pair (its lock), registered for `partner_release`, logged. Mr. Restori pulls with it (his
row). **Restore tickets** (`agent/lib/partner.php` «restore tickets»): a gone server's copies to a NEW server, two pastes and a code,
no secret in a block — the new server's «Start from a partner's copy…» makes its key and BLOCK-N `{v, block:"N", id, name, address,
pub_key}` (`data/partner/ticket-pending.json`, 7 days); the holder's card «Hand <name>'s copies to a new server…» writes
`data/partner/tickets.json` (`{v, tickets:[{id, of, name, address, from, key, created, expires, units}]}`, root only, exact shape),
THEN the line `restrict,expiry-time="<UTC>Z",from="…",command="…/partner-door.sh ticket-<id>" ssh-ed25519 … uso-ticket:<id>`
(sshd ends the key itself; optionally the gone server's own line out — `close_door`, its pair and copies stay) and answers with
BLOCK-T `{v, block:"T", id, name, of, address, port, host_keys, units, expires}` and the code (`partnerTicketCode()`: N's key and the
holder's host keys); the new server's «Paste the ticket» compares it and keeps a ticket pair (`data/partner/ticket-pairs.json`, kind
`ticket`: never sends, never receives, never in pairs.json — the engine and stage 2 never see it; `partnerTicketSsh()`). The door with
`ticket-<id>`: only `ping`, `list`, `send-back`, `quota` (else `ticket_verb`) on the units the ticket names of the pair it was given for
(`of`), until `expires` (`ticket_expired` with the server's `time`; a ticket's ping while the array is stopped stays RAM-only);
refusals counted under the ticket's id. Expired tickets go by themselves: `partnerTicketsTidy()` at the agent's start and daily (the
Team Lead's tick, a stat or two) takes the holder's expired lines (and `uso-ticket` lines no ticket names) out, and a new server's
expired ticket pairs with their key and pin; the `.plg`'s remove takes `uso-ticket` lines too. The night watchman adopts a ticket's
line written right after its ticket exactly as the Team Lead writes it (`partner_paired` with `kind: ticket`, `of`, `expires`), its
logins from its address are the office's own, its line gone is normal (`partnerLookTicketLines()`, `partnerLookTickets()`). Tests:
`testPartnerSendBack`, `testPartnerTicket`, `testWatchmanTicket`, `testRestorePartner`. Not built: «Stand in for <partner>».

**Security is the night watchman's** (2026-10): how secure the server stands (his posture tips) and what
changed (his watch book, the data flow). No other desk gives security advice — Ms. Dustdevil's tips in «Where is what»
(Ms. Whereabouts' up to 1.30) are operational (order, not security: a cron line of a removed plugin is hers) and point to him.

**Staff:** a fresh office has only the caretaker (desk.json `"always": true`).
Every other desk declares in the agent whether it suits the server
(`'fit' => fn () => fit(bool, why, params)`, texts `fit.<why>` in its own lang
file, told by the caretaker). The caretaker suggests whom to hire; hiring
(`office.hire`, src/staff.php → data/office/staff.json) shows
the desk in the tabs and at the reception, firing hides it again — data and
whatever it set up on the server stay (`fire_note` says what keeps running).
Unhired desks get no write actions (`not_hired`) and their checks don't count.
**Order** (Benj, 2026-10-07): «Change the order» at the reception (core.js `arrangeable()`: small cards in a row with big
▲ / ▼ buttons (`.order-arrow`, 44 px, the words as `data-tip` and `aria-label`, ▲ off on the first movable card, ▼ on
the last, the keyboard stays on the arrow it pressed), the team lead's card says why he stays first; the head's button
turns into «✓ Done» (filled) with «As at the start» before it and a hint under the welcome — every move saved at once,
so nothing to apply — `office.staff_order`, one request at a time, the newest after it, quiet; `Office.keepInPlace`
on the moved card. The selection bar at the bottom (the same two buttons) only while «✓ Done» in the head is scrolled
out of view — an IntersectionObserver on it, Unraid's sticky `#menu` and `--under` as margins — so a phone with many
desks always has them in reach and never two «✓ Done» on screen; without the observer the bar stays). Kept
in staff.json `"order"` (src/staff.php: ids of existing desks, never an `always` one, each once; `[]` = none);
`officeStaffOrderOf()` = the `always` desks, then that list, then the rest by desk.json's `order` (someone newly hired
joins at the end); `OFFICE_DESKS_MERGED` applies to it too (the one that took over at the earlier place). The page gets
`CONFIG.staff_order`; the reception's cards, the tabs (`Office.staffInOrder()`) and the team lead's «The team» (hired
first by `Office.deskRank()`, then candidates by desk.json's order) follow it. No `reception_order` any more. The
Dashboard tile is no list of the staff (fixed rows) and keeps its order. Ms. Dustdevil's default is 20 (right after the
team lead, where Ms. Whereabouts was).
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

**Reports** (Benj, 2026-10-08 — «Report a problem or a wish…», briefs/uso-feedback-concept.md; German «die
Rückmeldung», see «Words»): ⋯ → «Report a problem or a wish…» on every page (the desk shown pre-filled) and a quiet
`btn small plain` at the Team Lead's «The team» (the office as a whole) open `Office.reportDialog()` (core.js) — only
with an inbox (`CONFIG.report`). The agent answers `office.report_preview|report_send|reports` itself
(`agent/lib/report.php`, `officeAgentActions()`; api.php `OFFICE_AGENT_ACTIONS`: no desk, never «not hired»). Rules:
**never a request without the user's click on «Send»** — the preview asks nobody, it keeps exactly what goes in RAM
(`RUN_DIR/report/<token>.json`, 0600, 10 min) and the send takes only that (the words must be the previewed ones,
unticked parts dropped, one send per token — a second gets the first's answer); **the scrubber** (`reportScrub()`) over
every log line and error param — secrets → •••, mails, paths by structure (`logsNormalizePaths()` with a replacement;
Unraid's own shares stay, others ‹share-N›, pools ‹pool-N›), the server, partners, users, addresses, MACs, tokens,
UUIDs; idempotent; the user's own words never scrubbed, only hinted at; **the caps**: `REPORT_CAP_WEEK` (2) in 7 days
from `data/office/reports.json` (0600, `{v, reports:[{number, url, kind, title, desk, sent, rid}], closed_until}`, a
tolerant writer) before any request, a «closed» answer remembered a day — the Worker is binding; **the inbox**:
`OFFICE_FEEDBACK_URL` (src/place.php, shared), overridable by `FEEDBACK_URL="http://…"` in the plugin's .cfg
(`officeFeedbackUrl()`: scheme, host, port only; http only then) — curl through `hostNet()`, https only otherwise, 20 s,
no redirects, the body from a 0600 file; the Worker (dropnook/uso-support `feedback/`) answers by `error` — mapped to
`errors.report_closed|week|busy|refused|failed` + `report_offline`, `report_stale`, `report_incomplete`, never its
text; **the ID**: `sha256("uso-report:" + GUID)` in `data/office/report-id` (0600, made once) — never the supporter
ID. **A desk's log lines** come by its labels (`REPORT_LOG_LABELS`, the start of a line's text, plus «<id>:»): a new
`logLine()` of a desk starts with one of them (`testReport` greps every one). Tests: `testReport`, `testReportDialog`.

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
anxious, checks everything three times), Jack
Emby (the intern), Frau Protokolli (reads everything out, understands nothing),
Frau Putzteufel (sees dust everywhere, but never throws anything away at once —
"man weiss ja nie" — and knows every corner of the server, otherwise she couldn't clean; up to 1.30 that
knowledge was Frau Wasistwo's, the nosy gossip, whose desk went into hers), the team lead / der Teamchef (plain and friendly, knows his people), Herr Restori (calm restorer in white gloves, «Piano, piano», apprentice with Herr Backupsi), the consultant (an external,
consultant speak: "quick win", "best practice", the hour runs anyway). In Italian:
Signora Snapshotini, Signor Backupsi, Jack Emby (lo stagista),
Signora Protocolli, Signora Spolverina, Il capoufficio, Signor Restori, Il consulente
(addressing the user with "tu", like the German "du"). In French / Spanish:
Madame / Señora Snapshotini, Monsieur / Señor Backupsi, Madame Protocolli / Señora Protocoli, Madame Plumeau / Señora Plumero
(storeroom débarras / trastero), Le chef d'équipe / El jefe de equipo, Le
consultant / El consultor, Monsieur / Señor Restori, Jack Emby le stagiaire / el
becario (tu / tú). Greetings are lang keys
`greet.1…n` (`Office.greet` / `Office.withGreeting`); chatty bubbles may carry
character, warnings and errors stay plain and clear.

## Words (glossary)

The same thing has the same word on every desk and in every text that speaks of it (Benj, 2026-10-07: «Rundgang»,
not «Runde»; for Mr. Backupsy «Sicherung», not «Lauf»). Grammar follows the word (de *der* Rundgang, *die* Sicherung;
it *il* backup).

| Thing | en | de | it | fr | es | Desk |
|---|---|---|---|---|---|---|
| a desk's look around (button) · its toast | Tour · Tour done | Rundgang · Rundgang fertig | Giro · Giro finito | Tournée · Tournée terminée | Ronda · Ronda terminada | every desk (Mr. Restori's too) |
| the watchman's round (every 5 min) | round | der Rundgang, die Rundgänge | il giro, i giri | la ronde | la ronda | watchman, office |
| his button · his section | A round now · The last round | Jetzt Rundgang machen · Der letzte Rundgang | Un giro adesso · L'ultimo giro | Une ronde maintenant · La dernière ronde | Una ronda ahora · La última ronda | watchman |
| his book | watch book | das Wachbuch | il registro di guardia | la main courante | el libro de guardia | watchman, advisor |
| while the array is stopped | night shift | die Nachtschicht | il turno di notte | le service de nuit | el turno de noche | watchman, office |
| a backup run | run | die Sicherung, die Sicherungen | il backup, i backup | la sauvegarde | la copia de seguridad (short: la copia) | backup and all who speak of it |
| … in use | Start a run · last / next run · Stop the run · stopped | Sicherung starten · die letzte / nächste Sicherung · Sicherung abbrechen · abgebrochen | Avvia un backup · l'ultimo / il prossimo backup · Interrompi il backup · interrotto | Lancer une sauvegarde · la dernière / prochaine sauvegarde · Arrêter la sauvegarde · arrêtée | Iniciar una copia · la última / próxima copia · Detener la copia · detenida | backup, office (Dashboard) |
| the nightly one | the nightly backup | die nächtliche Sicherung | il backup notturno | la sauvegarde nocturne | la copia nocturna | backup, advisor |
| the check (mode `check`, the «Tour» button) | tour | der Rundgang | il giro | la tournée | la ronda | backup, restore |
| dry run | dry run | der Probelauf | la prova a secco | l'essai à blanc | la simulación | backup, restore, emby |
| backup.sh and co. | the (backup) engine | die (Backup-)Engine | il motore (di backup) | le moteur (de sauvegarde) | el motor (de copias) | backup, restore, watchman |
| where packages go | backup place | die Backup-Ablage | il deposito dei backup | l'emplacement des sauvegardes | el lugar de las copias | backup, restore, watchman |
| per app / VM | package | das Paket | il pacchetto | le paquet | el paquete | backup, restore |
| ZFS/btrfs/VM | snapshot | der Snapshot | lo snapshot | le snapshot | la instantánea | all |
| the offsite copy | Kopia | Kopia | Kopia | Kopia | Kopia | backup, restore, advisor |
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

German «Backup» stays for the whole and for what is kept (im Backup, Backups gehören nicht in appdata, Backup-Ablage,
Backup-Engine; «Eingerichtete Backups»); one run is a «Sicherung». «Probelauf» stays the engine's dry run — Mr. Restori's
drill is «die Übung». «Lauf» / esecuzione / exécution / ejecución stay for
Jack Emby's runs and Ms. Snapshotini's plan runs — they back nothing up, and «Lauf» pairs with «Probelauf».
**Unraid's own words (Benj, 2026-10-07):** the office speaks the browser's language, Unraid may run in another one. Every
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

* **Inside Unraid:** everything the office shows lives in `#sso` (src/page.php);
  office.css and every desk.css nest all their rules in `#sso{ … }` (CSS
  nesting), so nothing leaks out and, under the id, Unraid's rules don't leak
  in. A reset at the top (`#sso :where(p, button, input, … span.warn …){all:revert}`)
  undoes Unraid's styles for bare elements; it sits below every rule of ours.
  Don't use Unraid's `unapi` class (it switches on Tailwind utilities like
  `.grid`). Dialogs, menus, the selection bar and tips are appended to `#sso`,
  never to `body`. Links into Unraid's own pages (`/Docker`, `/Plugins` …) open in
  the same tab, like Unraid's own. The office speaks the browser's language
  (`navigator.languages`, the first it has; ⋯ → Language per browser, `Office.store` `lang`); Unraid's labels in
  its texts follow Unraid's language (`unraid_lang`, `unraid_words`; «Words»).
  Buttons inside Unraid: its theme's frame, plain letters (no capitals).
  Colours are tokens (`--ink`, `--surface` …) mixed from Unraid's theme
  variables (`--text-color`, `--background-color`, `--button-background` …), so
  black, white, azure and gray all work — never a hard-coded colour. Buttons,
  tabs and section title bars look like Unraid's (the `.in-unraid` rules; `#sso`
  always carries that class).
* **Theme switch (an experiment, Benj 2026-10-07 — may go again):** at the reception, right of the head, a segmented
  control Auto · Dark · Light (`public/assets/theme-switch.js`: `Office.theme.get/set/control()`, a radio group the arrow
  keys work; kept per browser as `Office.store` `theme` = localStorage `office.theme`, none = Auto). Auto = no
  `data-theme` on `#sso`, the office as Unraid's theme makes it. Dark / Light = `#sso[data-theme=dark|light]`:
  `public/assets/theme-switch.css` sets Unraid's own theme variables (`--text-color`, `--background-color`,
  `--button-background` … — the values of Unraid 7.3.2's `webGui/styles/themes/black.css` / `white.css`, copied as they
  are) on the office's element, so everything office.css and the desks mix from them follows by itself; `color-scheme`
  for native controls; the office paints its own background as a rounded box in Unraid's content area — Unraid's header,
  menu, footer and the Dashboard tile keep Unraid's theme, that's intended. src/page.php sets the attribute before the
  first paint (an inline script right inside `#sso`, from the same localStorage key). **Switching it off:**
  `OFFICE_THEME_SWITCH = false` in src/bootstrap.php — nothing of it shows or loads (the four places in page.php sit
  behind the constant, core.js asks `if (Office.theme)`). **Removing it for good:** delete theme-switch.css and .js, the
  constant, the four `theme-switch` places in src/page.php, the three `theme-switch` hooks in core.js (the reception's
  actions, the help line, the help's place for the search in `officePlaces()` — `arrangeable()` then makes its own
  `.deskhead-actions` as before), the strings `office.theme_*`
  and `help.theme_*` in the five lang files, `testThemeSwitch` in tests/run.php, the README paragraph and this bullet.
  `testThemeSwitch` checks the page with the constant on and off, the stylesheet's variables against Unraid's theme files
  on the host (every one the office uses, the same names for Dark and Light, Unraid's values) and the strings.
* **Show first, then look (Benj, 2026-10-07 — perf report levers 1 and 2):** a desk's state (and a part desk.json
  names) comes through `Office.loadState(id, {fresh, part}, took)` (core.js), never `Office.api.get({a: 'state'})`:
  `api.php` answers at once with what is kept (`apiLook()`: `age`, `stale` = older than refresh_after, `refreshing`
  while the agent looks again in the background — after the answer went out, `fastcgi_finish_request()`; one look per
  state at a time, its lock `look-<desk>[.<part>].lock` in `RUN_DIR`); the page then asks with `wait` and hands the new
  look to `took(j, true)` inside `Office.keepInPlace` once the user is calm (`Office.calm()`: no dialog or menu, no click
  under way, nobody typing in a field the next render builds anew — fields built once, like a desk's filter, carry
  `data-keep`); an answer older than what is shown is dropped. Only the desk shown looks: the reception's cards and the
  badges (`started()`) read `stored` (no agent call; the same question on its way is asked once, so each desk once per
  page start), a card older than 15 min (or its refresh_after) says «As of …» quietly (`office.as_of`), a desk's head
  too while no new look came within 1.5 s. `fresh` (Tour, «Look again», live views) waits for the look as before.
  **Actions on what a page shows** (a selection to delete, a plan's targets, «all», a run, a settings form) start with
  `await Office.freshState(id)` and look the thing up again by id — false (said: `office.stale_refused`) means no fresh
  look could be had: never act on a stale list. Tests: `testApiLook`, `testLookPage` (core.js under node).
* **Head:** `Office.deskHead(...)`, then right after it
  `Office.pageHelp(ID, [[term, text], …])` — "How to read this page", folded
  by default, remembered per desk. Explanations of labels, buttons and tiles go
  there, not into repeated legends on every list.
* **CSS names:** desk classes carry the desk's prefix (`jo-`, `lg-`, `bk-` …);
  never reuse a name office.css already styles (`.empty`, `.card`, `.row` …) for
  something else — a button with `.empty` got 34 px padding. **Never a level or state from data as a bare class**
  (`el('li', m.level)`): office.css's `.hint` is one line cut with «…» — the setup's hint messages were cut at 375 px
  (QA 2026-10-08); Mr. Backupsy's messages carry `bk-msg-<level>` (`msgClass()`). A desk.css grid that overrides
  office.css's `.kv` or a row's columns brings its own phone rule (`@media (max-width:640px)`: labels above values,
  a chip or buttons in `.bk-right` / `.rs-right` under the text).
* **Remembered per browser** (`Office.store`, prefix `office.`): filters,
  chosen tiles, favourites. Tests in the browser save and restore those keys —
  the user's own pane shares them.
* **Sections:** `Office.sectionHead(title, explanation, ...extras)` — a title
  bar (Unraid's inside Unraid) with counts, status and buttons on its right, the
  explanation right under it. No explanatory text floating on the right.
* **Tiles** (`button.card`): a click opens or filters; a click on the active
  tile closes it again — nothing open is a valid state. Remember the choice in
  `Office.store`.
* **Search (Benj, 2026-10-08 — phase 1, static places; phase 2, items; phase 3, texts):** the magnifier in the top line (`#sso-search`, made by core.js
  before `#sso-state`; nothing in page.php) or ⌘K / Ctrl+K while the office has the focus (or nothing outside `#sso` was
  clicked last — never `/`, never in Unraid's header) opens a palette under it (`#sso-palette`, role combobox/listbox:
  ↑↓, Enter = the highlighted/first, Esc, a click outside; phone: the screen's width). It finds **places** and, from the
  desks' states, **items** (below): each desk lists its own beside `Office.desk()` with ONE
  `Office.places(ID, [{kind, key, route, anchor, text, crumb}, …])` (kind desk | section | tile | step | setting | help |
  guide; key = the desk's lang key, like `T()`; route default `#/<id>`; anchor default the key) and marks them on the page:
  `{ place: '<anchor>' }` among `Office.sectionHead()`'s extras (passes through a desk's `section(title, sub, ...extra)`),
  else `Office.place('<anchor>', node)` (a literal or a template `` `tile.${x}` ``); a page-help term needs no mark —
  `pageHelp()` finds a listed `kind: 'help'` by its words (`'<id>-<page>'` takes the places of `#/<id>/<page>`). Every
  `sectionHead/section/sectionBox/setupSection(T('…'))` is a place (or named in the test's `$notPlaces` with why). A tile
  the search opens has a sub-route its `mount(root, sub)` takes (`#/restore/<section>`, `#/logs/varlog|docker|found`,
  `#/cleanup/tidy/<room>`, `#/cleanup/where/<corner>`, `#/backup/setup`, `#/emby/setup`) — the same choice a click makes
  (`Office.store`). `places.json` beside desk.json lists the same keys as `{"keys": […], "texts": […]}` (sorted, unique;
  texts below; the office's own: `public/assets/places.json` for `officePlaces()` in core.js): `api.php?a=places` sends only their words, in every
  language (≈ 10 KB gzip, cached a day by `v=<stamp>-<version>`), asked once on the first open — never with the first paint,
  never per keystroke; until then the office's language finds them. Matching in the browser: folded words (accents, ß → ss,
  ⟦labels⟧ in English and Unraid's words), prefix › inside › Damerau-Levenshtein (1 from 4 letters, 2 from 8; only while
  nothing matches as typed), kind weights, the desk shown first, desks not hired last (greyed; chosen: the Team Lead's «The
  team»), ≤ 12. A choice: `Office.goToPlace()` → the route, then `Office.reveal(anchor)` (waits ≤ 10 s for the page to draw
  it, opens `<details>` above it, scrolls under Unraid's menu, marks it `.place-hit`, focuses it; the user scrolling,
  clicking or typing ends it; not there: `search.not_there`). The same words twice on a desk are one result (a term's
  explanation joins the section). Tests: `testSearchPlaces` (registry vs places.json, keys ×5, routes, anchors marked,
  sections listed, the endpoint, the matcher under node).
  **Items (phase 2):** what a desk's STATE holds worth finding — ONE provider beside `Office.places()`:
  `Office.placesFrom(ID, (state, part) => [{text, sub, route, anchor, words}, …])`. Core calls it with every state (or part)
  that ARRIVES through `Office.loadState()` (`took`: the desk's own look, the stored states of the reception's cards and the
  badges, a later look; `tookLater` too; an older answer or the very state again: not) — when the page is idle after the
  state was drawn (`requestIdleCallback`, or at once when the palette asks), ≤ 2 ms a state (measured ≤ 0.5 on uso-test),
  never a request of its own, never per keystroke, no endpoint; it keeps only the answer (≤ 200 per state, text ≤ 80
  letters), never the state; a provider that throws costs its own items only. A state that comes another way is handed in
  with `Office.placesTook(ID, data, part)` (restore's drill.js: its certificate, part `drill`); one an action's answer
  brings (`state = j.state`) is not — the next look (≤ a minute) brings it. Each desk's items: its words (its text
  helpers: `T(…)`, `entryName()`, `dbName()`, `label()` …) — **never a raw log line, a secret (keys, passwords, API keys,
  fingerprints) or a path the page doesn't show** (the test greps for /boot, /mnt/, /var/, key-like strings; the only
  paths are the pages' own words: the watchman's «/boot/config/go changed», Mr. Backupsy's «Flash (/boot)»). **The
  anchor rule:** every item's anchor is on the page as `data-place` — `` Office.place(`<prefix>:${id}`, row) ``, or a
  row's key where the desk's row helper marks it (`unfoldingRow(key …)` in restore, `row({key})` in cleanup's «Where is
  what»: `Office.place(key, …)`) — or, reusing an id the row has, its `data-id` (`Office.reveal()` falls back to
  `[data-id="…"]`: the watchman's posture tips); what hides it opens through the route: `#/restore/<tile>` (also
  `partner:<id>`), `#/cleanup/tidy/<room>`, `#/cleanup/where/<corner>`, `#/watchman/entry/<id>` (the book shows rows down
  to it), `#/snapshot/datasets` (grouped by dataset, no pool picked — remembered like a click), `#/logs/read/<source>`
  (that log in the reader); one-off sub-routes clear themselves (`Office.subroute('')`). Inside a folded `.group.closed`
  `reveal()` shows the group's head. Ranking: `PLACE_WEIGHT.item` .75; a place met word by word (each word whole or at its
  start) before the items, an item before a place met only inside its words, typing errors only while nothing meets as
  typed; a place and an item at the same desk/route/anchor are one result (the Consultant's externals); items of a desk
  not hired are dropped; the chip `search.kind.item`, the line «n places · m items» (`search.results`, `search.items`).
  Per desk: the Team Lead the points still open (his lists' words, `finding:<sig>`), the team (`staff:<id>`), partner
  cards and tickets (partner.js: `partner:<id>`, `ticket:<id>`); the watchman the open entries (≤ 120, `entry:<id>`), the
  posture tips not known (data-id), his watch groups (`watch:<key>`, found also by the plugins', containers', shares' and
  User Scripts' names in them); Mr. Backupsy his main page's rows (`row:share|vm|app:<name>`, `row:flash`), the runs his
  history lists (`run:<started>`), the partners (`partner:<id>`) — the setup's own rows come from its plan (`setup_get`, not
  his state): not items; Mr. Restori apps, VMs, databases, partners' tiles, the drill's certificate; Ms. Snapshotini pools
  and disks (`pool:<name>`), plans (`plan:<id>`), datasets with snapshots (`ds:<vol>`, ≤ 150); Ms. Dustdevil what her rooms
  would clear away (`item:<id>`, ≤ 120) and from «Where is what» containers, disks, appdata folders (≤ 80); Ms. Protocolli
  every log by its name (anchor `reading`); the Consultant each external's state (`ext.<id>`); Jack Emby his servers
  (anchor `overview`), the shares, the runs (`run:<tool>:<started>`). Tests: `testSearchItems` (node, a fixture state per
  desk: none before a state, shape, caps, no secret/raw line/path, ≤ 2 ms, anchors marked in the code, the ranking, the
  refresh on a new and a later look, an older answer, a provider's slip, no request of its own).
  **Texts (phase 3):** a place's explanation (`text`) and a guide's paragraphs (`paras: '<prefix>'` — the lang keys
  `<prefix>.1, .2 …`; the Consultant's guides: `lock`, `lock.vgw`, `partner`, `dashboard`, `net.syslog|unifi|neighbours`,
  `install.<app>` — 16 guides, 83 paragraphs, each drawn as `Office.place('<prefix>.<n>', p|li)`) are its secondary words:
  a query that meets the place only through them (its own words as typed may meet the other query words; in a text a
  whole word or a word's start, inside a word from 6 letters, no typing errors) finds it
  below everything met by its own words (`PLACE_WEIGHT.text` .6, after typing errors too), once per place (its best text),
  the sentence as its line (≤ 80 letters around the first word met, «…» where cut, the words met `<mark>`ed — the palette
  marked nothing before); a paragraph's hit goes to the sentence (anchor `<prefix>.<n>`, the guide's own anchor its part,
  `<details>` above opened). The help terms' explanations and the office's `help.*_text` are found the same way.
  **Sizes (measured 2026-10-08, gzip level 1):** the words answer 13.9 KB (36.8 raw); the texts (269: 83 paragraphs, 186
  explanations) in five languages ≈ 166 KB more — over the 25 KB budget, so they never ride with it: the office's own
  language has them in its strings already (matched at once), English (31.1 KB, 74.9 raw) comes from
  `api.php?a=places&part=text` right after the words (an English office asks nothing), cached a day like them; the words
  answer carries no text. places.json `texts`: the `text` keys and `'<prefix>.*'` for paragraphs (`apiPlaceTexts()`).
  **Not drawn now (`shown`):** an entry may carry `shown: () => boolean` — the desk's own rule, asked when the search looks
  (after the providers read the states that came; a rule that throws counts as drawn; the desk's state not known yet:
  drawn) — false = not listed at all (nothing to go to; not greyed like a desk not hired). A page-help term of the same
  words stays listed then (the search's «one result» keeps it beside such a place: it is drawn). Marked: Ms. Dustdevil's
  rooms (`visible()`: VMs, scripts, what restores and partners left, icons, the Docker rooms), Mr. Backupsy's `now` /
  `overview` (a run or not), `drift` (something drifted), his sections at all (`state.found`), the setup's start cards (no
  settings yet), old Kopia sources, the rows only some plans have (ZFS, btrfs, libvirt, Kopia on — read from the plan once
  the setup loaded it), Jack Emby's overview / shares / pool (`configured`), the Team Lead's «Noted», the Consultant's
  monitoring and network sections and his dashboard. Checked and always drawn (none marked): Mr. Restori's tiles and
  sections (without packages they say so), the watchman's four sections, Ms. Snapshotini's, the Team Lead's others,
  Ms. Protocolli's sections. **`part`:** the anchor of the part a place lies in; `Office.reveal(anchor, {part})` brings
  the part into view after 1.5 s without the place (no mark, no focus) and, after 10 s, marks it before
  `search.not_there` — Ms. Dustdevil's tiles (`part.tidy` / `part.where`), Mr. Backupsy's setup rows (their step), Ms.
  Protocolli's tiles (`tour_section`: no tour yet), the Consultant's Kopia guides and dashboard. **Ms. Dustdevil's
  filter:** her `mount(root, sub)` empties it for `tidy/<room>` and `where/<corner>` — every search hit on her page comes
  through such a sub-route (`goToPlace()` mounts anew even on the same address); a hook of `reveal()` would teach core
  one desk's filter. Her parts (`where`, `tidy`) keep it. **Partner pairs:** the Team Lead's `partner:<id>` items' line
  names the way («Tower sends» / «Tower keeps», the card's `partner.i_send` / `i_keep`), two pairs with one partner
  differ. **The reveal keeps its place** (the phone offset, 11 px above the top at 375 px on nostromo): it put the place
  12 px under Unraid's menu (sticky on the desktop, static on a phone: then 12 px under the window's top), then — the
  likely cause, not seen in a browser yet — the page settled above it (the desk's head anew when the new look came,
  «As of …» going) and nobody followed (`look()` returned while the place was still connected); now for 6 s
  (`REVEAL_SETTLE`, was 2) every change of the page (MutationObserver, ResizeObserver, one check per frame) puts it back
  (`auto` once the smooth scroll ended, else the smooth one retargeted) — the user scrolling, clicking or typing ends it.
  Tests: `testSearchGuides` (paragraphs marked and alike in five languages, the endpoint's part and sizes, the words
  without texts, a German office: nothing before the first open, English after the words, a paragraph's sentence and
  marks, ranking, a term's explanation, the office's help, ≤ 2 ms; the jump, the settling, the phone, the part),
  `testSearchPlaces` (`shown` a rule, `part` an anchor marked, her rooms marked, a hidden place not listed),
  `testSearchItems` (the filter, the partner direction).
* **Rows** with one main action (unfold details, open a log): the whole row is
  clickable (`.row.unfolds`), except its own buttons, links, fields and
  elements with `data-own`. The "details" tooltip sits on the name only; every
  chip carries its own `title`. Lists that unfold offer "Unfold all".
* **Chip explanations:** a chip's `title` (or `data-tip` on anything) becomes
  a bubble (core.js `initTips`): on hover with a mouse, on click or tap
  everywhere. That click belongs to the chip and never folds the row under it.
  Chips that do something themselves (own `onclick`, `data-own`, inside a
  button/link/label) keep their click and explain on hover only; a button, link or field INSIDE an element with
  `data-tip` keeps its click too (1.36.1 — the watchman's posture rows carried `data-tip` as an id and their «I know,
  thanks» never fired). `data-tip` is the tooltip's hook only: an element's id goes into `data-id`.
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
  complete and honest. Docker stopped is «Docker isn't running» (`common.docker_off` in the office's lang; Ms.
  Dustdevil's `system.docker.up`, Mr. Backupsy's `containers.up` — the socket, like the looks that ask Docker), never
  «0 of 0 running» or nothing; no VMs is «No VMs».

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
  Dustdevil's `waExclusive()`, agent/lib/where.php). On nostromo most pool shares are (appdata, system, domains …). Code that refuses
  links must allow exactly this one — `/mnt/user/<share>` → `../<pool>/<share>` or `/mnt/<pool>/<share>`, the same
  share name, a real folder there: `officeUnraidPath()` in src/place.php, the one helper (the data folder of the agent
  and the web side, the Consultant, Ms. Dustdevil); `realpath()` gives the pool path, `is_dir()` follows the link,
  `lstat()`/`is_link()` don't. Going through `/mnt/user` costs shfs (FUSE) even then: about 50× the pool path per look.

* **shfs and flock (7.3.2, QA 2026-10-08 on USOPartner):** a flock taken through `/mnt/user/…` (FUSE) lies on the disk's
  or pool's file — /proc/locks names that one (`00:28:266`), never the inode stat() shows through /mnt/user. shfs numbers
  its inodes `(st_dev << 48) | st_ino` of the file behind (ZFS and btrfs, Tower and USOPartner: 11258999068426506 =
  0x28 << 48 | 266) — `flockHeld()` looks for both. The lock itself still works through shfs (FUSE hands it down).

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
  nginx compresses only `.js/.css/.woff` (`gzip off` for `.php`, 7.3.2) and PHP has `zlib.output_compression`
  and `output_buffering` off — so the API compresses its own answers (`apiSend()`, src/api.php, 2026-10-07): ≥ 1 KB
  and `Accept-Encoding: gzip` → gzip level 1 with `Content-Encoding`, `Vary: Accept-Encoding` and the Content-Length
  of what goes out, complete BEFORE `fastcgi_finish_request()` (the stale-state answer of answerThenLook()); −80 %
  of the reception's bytes (876 → 166 KB on nostromo), the strings 465 → 155 KB, ≈ 1.5 ms per 300 KB. Every answer
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
  `disks.ini`); `disk1` is not `disk10`. **Only a rotating disk is ever asleep for the office** (Benj, 2026-10-08):
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
  `docker exec`): it reads the files' own sizes (sparse holes too — a little past `logicalreferenced` for a share; for a VM far past it: Windows11_Gaming's 1.6 TB vdisk holding 21 GB was 2 TB of rchar in 2.7 h on 2026-10-07 while its snapshot said 382 GB — so a VM source is reckoned by its files' apparent size, engine 2.26). Not `wchar` (what it sent:
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
* **VMs at the array stop (7.3.2, `/etc/rc.d/rc.libvirt`):** paused VMs are resumed, every running VM gets `virsh shutdown`
  (libvirt uses the guest agent while its channel is `connected`, else the ACPI power button), Unraid waits up to domain.cfg
  `TIMEOUT` (Settings → VM Manager → VM shutdown time-out; empty = 60 s), then `virsh destroy`s what still runs — a hard
  power-off. An idle Windows with its display off ignores the power button: nostromo's array stop waited the full 180 s for a
  Windows 11 (2026-10-07). A running VM's live XML with the agent channel's `state` is libvirt's status file
  `/var/run/libvirt/qemu/<vm>.xml` (RAM, root 0600); `/etc/libvirt/qemu/<vm>.xml` has no state.
* **Emby sessions (4.10):** a device that went to sleep with something paused stays in `/Sessions` with its NowPlayingItem
  for hours (isp3's iPad, 10 h); `LastActivityDate` (UTC, seven digits of a second) is when Emby last heard from it. Its web
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
2. JS syntax (no Node on the dev Mac): `osascript -l JavaScript` with `new Function(src)`.
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
backup/                  the backup engine: backup.sh, setup.sh, lib/common.sh (data in data/unraid-backup)
embycache/               Jack Emby's EmbyCache (Python; data in data/embycache)
gather/                  Jack Emby's media gather, consolidate_master.sh (bash; data in data/gather)
monitoring/              grafana-dashboard.json: the office's dashboard to import (its numbers: lib/metrics.php)
(the support page OFFICE_SUPPORT_URL points to — a Cloudflare Worker: tips, supporter keys — lives in the private
                         repository dropnook/uso-support since 2026-10-08; its key format is src/supporter.php's)
plugin/                  the Unraid plugin: .plg template, build.sh, dev-sync.sh, scripts/ (agent.sh
                         service, job.sh for the cron file), event/ (started, stopping), images/,
                         SecretaryOffice.page (the office in Unraid), SecretaryOfficeButton.page
                         (the header button), SecretaryOfficeDashboard.page (the Dashboard tile),
                         README.md (the short text Unraid's Plugins list shows); images/ has the
                         plugin icon as PNG with its SVG source (rendered on the Mac via NSImage)
.github/workflows/       plugin.yml: builds and attaches .plg/.txz when a release is published
```
