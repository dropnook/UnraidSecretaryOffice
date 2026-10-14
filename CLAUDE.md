# Working on Unraid Secretary Office

Read README.md and docs/DEVELOPMENT.md (architecture, adding a desk, tiles and lists, partner offices inside). This file holds
the **rules** — what holds, not how it came to be. Details: code comments, `tests/run.php` (a test per piece) and
`backup/README.md`. The full earlier version: `git show v1.62.0:CLAUDE.md` (build notes per helper: `v1.60.0`).

## Map: how it runs

* **Plugin only.** Code in RAM under `/usr/local/emhttp/plugins/unraid-secretary-office/` (built by `plugin/build.sh`), served
  by Unraid's nginx/php-fpm behind Unraid's login. `SecretaryOffice.page` (menu bar; or, by the ⋯ menu's `MENU_NAME`/
  `MENU_PLACE`, an icon under User Utilities or only `SecretaryOfficeButton.page` — loaded on every Unraid page: keep it a
  one-liner); `SecretaryOfficeDashboard.page` (src/dashboard.php: state files only).
* **The agent** (`scripts/agent.sh`; `event/started` starts, `event/stopping` stops) does everything that touches the host.
  `ARRAY_RUNNING` is defined in agent.sh, agent.php and the engine (a test compares them). Array not started → **night shift**
  (RAM and flash only, never both at once; detected by the pid in `WATCH_NIGHT_LOCK`, never the lock itself).
* **Data folder** `DATA_DIR` from `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg` (tests:
  `OFFICE_DATA_DIR`, `OFFICE_WEB`); in an exclusive share the pool path (`officeUnraidPath()`). RAM folder `officeRunDir()` =
  `/var/run/unraid-secretary-office` (`RUN_DIR`; tests `OFFICE_RUN_DIR`, never the live one).

```
agent/agent.php        loop, mailbox + doorbell, heartbeat, desks, self-restart; partner-door.php the partners' forced command
agent/lib/*.php        shared: util (run, writeAtomic, Problem, request readers), mounts (diskAsleep), house (finding,
                       officeNotify, officeJobSchedule), backupscript (backupProtection), migrate, metrics, partner(look),
                       where, report, import, watchnet, paritywhy, nightrun.php (the joint night run), poollevel.php (X/Y/H)
agent/desks/<id>.php   one desk each: desk('<id>', [...]) (+ moverelli-unraid/-import, restore-drill/-partner)
src/*.php              web side: bootstrap (versions), place (paths, URLs, OFFICE_UNRAID_TESTED), api, staff, page, words
public/assets/core.js  Office: t(), routing, api, loadState, deskHead, sectionHead, sections, filterBar, places, keepInPlace
public/lang/ desks/    office strings ×5 (lang/unraid/: Unraid's words); desks/<id>/: desk.json, desk.js, places.json, lang/
backup/ embycache/ gather/ smartmover/   the engine; Jack Emby's tools; Ms. Moverelli's Smart Mover (data in data/<tool>)
plugin/                .plg template, build.sh, dev-sync.sh, scripts/ (agent.sh, job.sh, partner-door.sh), event/, *.page
tests/ tools/          run.php, node tests, ui/ (harness, stubs, demo/); release.sh, ui-*.sh — never in the package. monitoring/:
                       grafana-dashboard.json; data/: runtime, not in git. The support page lives in a private repository.
```

## Rules
### Language and texts
* **English** in code, comments, commits, README. Every UI string lives in `public/lang/<code>.json` or
  `public/desks/<id>/lang/<code>.json`; **`en de it fr es` always have the same keys**, placeholders `{…}` and plurals as in
  `en` (tests check). A new string needs all five. **Never hard-code UI text in JS or PHP.**
* **Counts are plurals** `{"one": …, "other": …}` chosen by param `n` (`t()`, `officeNotifyText()`, `officeDashT()`), never
  «file(s)»; several counts → compose from `count.<what>` keys (`nOf()`), keep only the glue. Counts that never reach 1 may stay
  plain. French `one` covers 0 and 1: write `{n}` there. `testStrings`.
* Desk lang keys `name` and `role` are the desk's title and subtitle — don't reuse them.
* **No internal script names on the page** (`setup.sh`, `backup.sh` …): say what the person does. `testNoScriptNames`. Engine
  log lines and admin docs may name them.
* **Say what is really there:** detect it (boot stick or pool, licence on stick or TPM, a VM disk on an overlay) instead of
  describing the common case. Restore texts complete and honest. Docker stopped = «Docker isn't running» (`common.docker_off`),
  never «0 of 0 running»; no VMs = «No VMs»; a missing share = «The share X is missing».

### Build and code
* **No build step, no dependencies.** PHP 8.4 (Unraid's — nothing newer), vanilla JS, office.css + optional `desk.css`; look
  tokens in `office.css :root`. The only build is `plugin/build.sh`.
* **The web side never touches the host** (php-fpm is root anyway). zfs, docker, /boot/config, /proc → the agent
  (`Office.api.post('<desk>.<action>')` → `agent/desks/<desk>.php`). Exceptions: night-shift RAM state; secrets typed for the
  agent go through `apiSecretStash()` (0600 file in the RAM inbox, never the mailbox on the pool; only `OFFICE_SECRET_ACTIONS`
  carry a `secret`); reading the heartbeat (`agentRecord()`); ringing the **doorbell** FIFO `RUN_DIR/doorbell` (`agentRing()`) —
  never a signal or a process.
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every action re-reads current state and validates
  ids against it. Errors: `throw new Problem('key', [...])` → UI `errors.<key>`. **Request fields are read, never cast:**
  `textField()`, `optText()`, `idList()`, `boolField()`, `cronField()` (web: `apiText()`) — never `(string) ($r['x'] ?? '')`; a
  missing key or wrong type never turns a setting «off». Agent runs `E_ALL`. `testRequestTypes`, `testStrictSettings`.
* **Hardening:** files only via `writeAtomic()`/`writeNewFile()` (never through a symlink); mailbox read only while a real
  folder of the web user (`privateDirOk()`); validators feeding names/files/commands end with `$/D`; links from state files only
  via `Office.safeHref()`; manifests read back from shares trusted only in exactly the office's shape. HARDENING.md.
* **No lock of its own:** Unraid's login + `csrf_token` (+ `X-Office`/Origin) guard everything; whoever is logged in is root.
  Previews and confirmations yes — **never a PIN or second login**.
* **Keep `tick` cheap** (~150 ms); long work = a background process polled from `tick`.
* Every API answer goes through `answer()`/`answerThenLook()` (gzip done there, before `fastcgi_finish_request()`) — never echo
  JSON elsewhere. `testApiGzip`.

### Data, disks, shares
* **Never wake sleeping disks on your own.** Check `sleepingDisks()` before reading array disks; offer an explicit «wake». Only
  a **rotating** disk counts as asleep (`diskAsleep()`, engine `ub_asleep_load`; `rotational` missing = rotating); a pool sleeps
  when any of its HDDs does (`cache`, `cache2` … in disks.ini; `disk1` is not `disk10`). Every reader of disks.ini goes through
  those helpers. A path through /mnt/user may touch every disk of the share — check all its parts first.
* **Nothing directly in /mnt** (Fix Common Problems): mounts under `/mnt/addons/UnraidSecretaryOffice/…` (RAM), desk data in the
  share `UnraidSecretaryOffice/<desk>/`. **The office never creates (or deletes) a share** — the user does (⟦Shares⟧); the
  office says which and why.
* **Never block the array stop:** nothing kept open in the pool (doorbell, heartbeat in `RUN_DIR`); the night shift never opens
  anything under `/mnt` (cwd `/`).
* **Never delete right away** what the user may want back (storeroom, set-aside). Backups keep copies on purpose (the office's
  share, anything called «backup», `my-*.xml` in a backup's templates) — never leftovers.
* **Nothing private public:** demo/screenshot data invented only (never a real server's names, paths, addresses, titles);
  reports scrubbed (`reportScrub()`); API keys, passwords, key values never leave the server or land in state/logs.

### Agent services
* **Checks for the Team Lead:** a desk registers `'checks'` returning `finding()`s (house.php): `required` only when the desk
  can't work without it, else `recommended`; `hint` for things to know. `check.<id>` = how it should be, `check.<id>_how` = what
  the user does. Every 30 min without a browser: cheap, state files, never waking a disk (sleeping → `ok = null`). Change a
  check's text only when its meaning changed (acks hash it, `testAckContent`).
* **Metrics** (`agent/lib/metrics.php`): `'metrics' => fn (): array => [...]`, families `uso_<desk>_…`, gauge|counter, few label
  values, state files only, once a minute for hired desks; files in `/mnt/addons/UnraidSecretaryOffice/metrics`, all ≤
  `METRICS_MAX_BYTES` (16 KB). Names unique over desks, times `…_timestamp_seconds`, no `_count/_sum/_bucket` on a gauge;
  renaming one changes `monitoring/grafana-dashboard.json` too.
* **Notifications** only via `officeNotify()`: subject prefix `Unraid Secretary Office`; `warning` = something to fix, `alert`
  only when something is at risk now; texts `notify.*` via `officeNotifyText()` in the office's last language
  (`officeNotifyLang()`); tests use `OFFICE_NOTIFY_BIN`. All senders (agent, night shift, agent.sh `tell`, engine `ub_notify`)
  share the stamp `RUN_DIR/notify.second` (Unraid drops a second one of the same event in the same second). `-m` line breaks are
  a literal `\n`; never a real newline in `-d`.
* **Heartbeat in RAM** (`RUN_DIR/agent.json`, every 20 s); `agentUp()` is the one rule for «at work»; a request waiting while
  the agent is away is answered `agent_away` after 4 s; action buttons disabled while `Office.agent.running` is false.
  `agent-watch.cron` alerts after 10 min of a stale heartbeat with the array started.
* **Jobs that must outlive the agent** (backup runs, restores, drills, let-gos, Jack Emby's and Ms. Moverelli's runs) go through
  `atd` (`hostLaunch()`, `backupLaunch()`), never as the agent's child.
* **Schedules** only via `officeJobSchedule()`/`officeJobSetSchedule()`: a line in the plugin's cron file calling
  `scripts/job.sh <job>` (runs only with the array started). **Never User Scripts** for the office's jobs (they are the user's).
  Never «fix» root's crontab from the office.

### The backup engine (`backup/`, bash)
* Runs on its own via the plugin's cron file. **Mr. Backupsy uses only its interface** (`backup.sh --about`, `state/status.json`
  & co., `setup.sh --plan|--apply` with `setup-plan.json`/`setup-status.json`). **Never parse its log lines for anything new —
  extend the interface:** reasons as codes (`die_code`, `why` → `backup.message.<code>`), setup hints/warnings
  (`hint_as`/`wrn_as` → `setup.msg.<code>` ×5, `testSetupMessages`), drift notes (`drift_add` → `drift_code.<code>` ×5,
  `testDriftCodes`). Engine notifications and drift.txt stay English.
* Server name from ident.cfg (`ub_host_name()`), never settings.ini. Version `UB_VERSION` in backup.sh, setup.sh, lib/common.sh
  and backup/README.md + a CHANGELOG line.
* backup.sh and setup.sh are one `{ … }` block (code goes inside). **Replace files with a new file + `mv`, never write into
  them;** after a `mv` on the host compare md5 on both sides before committing (SMB may show old content).
* A reader that stops early (`grep -q`, `break`) never reads a line-writing helper through a pipe or `< <( )` (SIGPIPE under
  pipefail) — use `$( )`; `testBackupEpipe`. Under pipefail a `while … | jq … || fallback` loop never ends on `[[ … ]] && cmd`
  (use `if`).
* **What holds** (details backup/README.md): one run at a time behind `state/lock` (`backupLockHolder()`; the office only looks
  via `flockHeld()`, never takes it; busy → `skipped.json`, exit 75). **One real backup a minute** (names carry the minute,
  `uso-backup-YYYYMMDD-HHMM`; old `unraidbackup-` recognised, exact — `backupIsEngineSnap()`); tours/dry runs/setups log by the
  second. **Backups never in appdata** (`dumps_share` = the office's share, else refuse). **Packages** (`apps/`, `vms/`,
  `server/`, `flash/`, each with `manifest.json`) never deleted; also inside `domains/<vm>/.uso-backup/` and the app's folders
  (`testBackupInside`). **Kopia** per app/VM; never a source under another's policy path, never `@` in a source path. **New
  things stay local until the user decides** (`preset_new`; only Apply makes a default so). **VMs**: `prepare =
  shutdown|freeze|pause|none`, never forced off, a late VM paused (warning). **Array stop ends a run at once** (`aborted`, exit
  3, shown as stopped, never failed); `--recover` at the next start. **Sleeping pools** `wake|skip` (skip = left out and said,
  never failed; backup place and data folder pools always woken). **Partner phase:** one dataset per unit, `partner_ssh_cmd()`
  the one place for ssh options, never `zfs send -s`; what a partner didn't get is a warning in that run (`testPartnerAtOnce`).
  Fixtures run on the tests' clock (checklist 3).

### Jack Emby's tools
`embycache/` (EmbyCache by helmi1987) and `gather/` ship with the office; our changes kept small and listed at the top of their
README.md; their data apart (`EMBYCACHE_DIR`, `CONSOLIDATE_CONFIG`); they report through status files, never parsed log lines.
**EmbyCache MOVES files** — test with report and dry runs. Runs via `php agent.php job embycache|gather <mode>`; a real run
holds the other tool's lock. The cleaner is left out on purpose. Smart Mover (`smartmover/`, helmi1987) likewise: office hooks
`CUSTOMMOVER_*` listed in smartmover/README.md, changes marked `USO`.

### Versions and releases
`OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION` (agent/agent.php) move together; tag `v<version>`. The plugin's version
is a date (`2026.10.05`, a letter for a second one that day; `strcmp`), chosen by `.github/workflows/plugin.yml`; `build.sh`
refuses mismatching versions. Release only via `tools/release.sh <version>` from a clean main (runs click test + suite), English
notes for users; afterwards check the `.plg` got a new version. Tested Unraid = ONE constant `OFFICE_UNRAID_TESTED`
(src/place.php); .plg `max` = `<major>.99.99` of it, raised only once tested (`testUnraidTested`).

### Updates
Users update through the plugin manager and read no notes: **every version starts on any older version's data folder, and an
older on a newer's.** `officeMigrateStart()` (agent/lib/migrate.php, before any desk reads state) is **the one place for
migrations**: steps `['id','version','run']`, idempotent, logged, never deleting (`<name>.before-<version>`). New keys additive,
read with a default (PHP and desk.js — the first state can be the old agent's); unknown keys kept on rewrite; **no rename** of a
key, file, path, cron command, door path, snapshot prefix, Kopia source path or desk id without a step and readers of both;
exact-shape files (pairs.json, the watchman's mirror, the drill's certificate, storeroom manifests) change only with a new
`v`/`interface`; engine defaults for settings.ini keys don't change. **Tolerant writers:** readers use only entries in exactly
their shape, writers keep entries they don't know (`testPartnerTolerant`). **Install swaps** (`testPlgInstall`): unpacked
beside, checked first; update/remove refused while a run, setup, restore, drill or Jack Emby's/Ms. Moverelli's run is active
(one `pgrep -f` pattern, `testPlgGuard`); remove runs `agent.sh release`, puts the cron file aside (`officeCronBack()`), removes
the office's authorized_keys lines. A new agent version → «Reload» offered, never forced (`testLookPage`).

## The desks

Desks know each other only through shared libraries (`backupProtection()`, `finding()`, `partnerlook.php`, `nightrun.php`,
`poollevel.php`) and links (`#/<desk>`) — each must work on its own. Entry tests: `testSnapshot*`, `testPlanGone`,
`testSleepingPools`, `testBackup*`, `testSetup*`, `testEmby*`, `testMoverelli*`, `testNight*`, `testPoolLevel`, `testHireNeeds`,
`testLogs*`, `testWatchman*`, `testParityWhy`, `testCleanup*`, `testWhere*`, `testHiddenStoreroom`, `testAdvisor*`,
`testRestore*`, `testCaretaker*`, `testAckContent`, `testPartner*`.

* **`snapshot` — Ms. Snapshotini.** ZFS/btrfs/VM snapshots and plans (lib/snapshotplans.php, `uso-plan-<plan>-…`; retention
  touches only those, never the engine's; plans read only via `snapPlans()`; a gone target is no failure). Lists awake ZFS pools
  only; **an action on a sleeping pool refuses unless the request carries `wake`** (the page asks). A partner's copies are never
  a target, never deleted/renamed/held while the pair exists. Ids only in her scans' shape.
* **`backup` — Mr. Backupsy.** The engine through its interface only. A real backup asked within the minute of the last is
  queued for the next; a tour, dry run or setup never waits. **Setup:** a level per share/VM/app (off · local · local + partner
  · local + offsite · local + partner + offsite; partner levels only with a pair and a dataset of its own); an app's further
  shares never ticked unasked; a re-plan keeps hand changes. No backup place → opens on «Basics», says what to create — **the
  office never makes the share**; place facts warn, never block. Apply sends only what was chosen and lists exactly what
  changes; a plan older than settings.ini is refused; a failed plan shows its errors. **Warns about Kopia autostart, never
  starts Kopia.**
* **`emby` — Jack Emby (the intern).** EmbyCache + «Consolidate folders». Settings via EmbyCache's own `save_config()`; **the
  API key never leaves the server. Rsync both ways, always** (`EMBY_TOOLS_RSYNC`). **He works only under Ms. Moverelli**
  (desk.json `"needs"`: hired only with/after her, she leaves only after him; real runs only her way, `embyMoverRule()`).
  Unraid's mover schedule is switched off only through Unraid's form via `emcmd` (tests: `OFFICE_EMCMD_BIN`); **he never writes
  into Mover Tuning.** A mover at work stops his run after the current file; never side by side with her. **Never a real gather
  while someone watches Emby** (`embyWatching()`; dry runs never ask). Let go: both his schedules off, Unraid's mover stays off.
  Import: the user types the folder, he never searches, preview without API key, copies aside first. «Last run» from his runs,
  not status.json.
* **`moverelli` — Ms. Moverelli.** Smart Mover beside Unraid's mover (7.3.2+; her ini, every value checked). **Unraid's own
  mover goes off with her setup:** refused unless the page's consent `mover_off: true` comes along; the old schedule noted once,
  **her let-go puts it back** through the same form (only `MOVERELLI_UNRAID_CRON` shapes). **Never a hand-written cron line.**
  Real runs only while no other mover has a schedule of its own (other scripts' mover calls named, never changed); never beside
  Unraid's mover or Jack Emby's runs. While Jack Emby is hired her ini keeps EmbyCache's list out for his shares; a named list
  not yet created refuses a real run (scheduled refusals fold into one counted line, never notified); her setup proposes his
  list for all shares in the form only. Import: a protecting list that can't be read blocks it, never silently disappears. **The
  night run** (agent/lib/nightrun.php): Jack Emby and she = ONE run on ONE schedule (her cron line → `nightRun()`): check →
  reckon (`nightPlan()`) → free (Jack Emby's watched, then her oldest) → recheck (≤ 3 rounds) → fill (Jack Emby last, only up
  to X) → tell; one lock `night.lock`; «Stop after this file» stops the night; both pages show `Office.nightCard()`; night.json
  `picture` = the live graphic's numbers. **The pool** (agent/lib/poollevel.php, 2026-10-14): **only X** — no Y, offset, halves
  or borrowing. Jack Emby gives back only what was watched (`EMBYCACHE_WATCHED_ONLY`, never by rank); **his GB come first**:
  she moves the other caching shares' oldest (across the pool, younger of birth/mtime; never an excluded file, his list never)
  until his fetch ends at X (`--fill-until` = X − fetch), the age rule always; what never moves is never pushed for him (it and
  what he keeps reach X → he fetches nothing new, told). His budget crowding the others out, or over X by his unwatched: told.
* **`logs` — Ms. Protocolli.** Reads logs from a fixed source list (ids only, never paths); lines into the page as text only;
  nothing read until chosen; routers' files only while their disks are awake. **Read only.**
* **`watchman` — The Night Watchman.** **The office's security department — no other desk gives security advice. Read only,
  never a command that changes anything.** Posture tips (never in the book or notified) and the watch book (what differs from
  normal; the office's own installs/drills adopted, never told). API keys: id, name, roles, a fingerprint — **never the key**;
  night mirror without passwords or key values. **Privacy rule:** another client's address/MAC/name only in
  `net_new_device`/`net_spoof`. Every kind has a MITRE technique.
* **`cleanup` — Ms. Dustdevil.** «Where is what» (operational advice, **never security**; read only) and «Tidying up» (what a
  drill or restore left: only what his journals name). A ZFS ARC tip only for a PCI-passthrough VM. **Never deletes right
  away:** renames into the hidden `.UnraidSecretaryOffice-trash` on the same filesystem (datasets via `zfs rename`),
  `manifest.json` per run, put back or empty; Docker images/build cache can only be removed; nothing while a backup runs; things
  in use can't be chosen. Old `_…-trash`/`_…-restore` names read for good, never written (`storeroomIn()`, `officeHiddenNow()`).
  Other desks list storerooms as what they are; **only she writes there.**
* **`advisor` — The Consultant.** Whether the needed tools are there (unbalanced never suggested); monitoring stack (keeps only
  the office's dashboard file current). Plugins via `plugin install <url>` (pinned) as atd job; containers via Unraid's Add
  Container form — **the user clicks Apply**; refused while one of that kind/name exists; recorded for the watchman. Object Lock
  probe HTTPS only. Kopia secrets only after explicit confirmation, through RAM to `docker exec -i` stdin; recovery sheet only
  in the browser. **Never logs into a web UI or HTTP API.** Network settings he explains, never changes.
* **`restore` — Mr. Restori.** **Never over something still there without asking; everything replaced is put aside first, never
  deleted.** Planned against a fresh look, confirmed with the plan's token, run by atd under the engine's lock, journaled, stops
  at the first failed step; «Put back» = the recorded undo only. Lists only awake ZFS pools. A missing share blocks; never a
  folder holding a running VM's disks; never starts containers; credentials only as variable names; partner pulls into a NEW
  dataset beside, never a `zfs clone`. **The drill:** throwaway containers only via `drillContainerArgs()` + `drillArgsSafe()`
  (`--network none`, no ports/binds/caps; too big = «not checked», never failed); refused during a parity check; a failed drill
  = one warning. Partner look from his tick, never a page request.
* **`caretaker` — The Team Lead** (id stays `caretaker`). Hires, fires (no «Hire» while a needed colleague can't come),
  suggests, collects all checks, reports new red findings once. «I know, thanks» puts aside recommended findings and hints —
  **never musts**; params that grow on their own must be named `days`/`size`. **He only pairs partners**; what is sent is Mr.
  Backupsy's level.
* **Partner offices** (agent/lib/partner.php; docs/DEVELOPMENT.md «Partner offices inside»): two offices keep each other's
  engine snapshots (ZFS datasets only) — never a master, never automatic failover. `pairs.json` read only in exactly its shape;
  its door always wakes the pool (`wake` written true, never asked); other desks read partner files read-only.
  **authorized_keys:** only lines commented `uso-partner:<id>` / `uso-ticket:<id>` are written or removed, every other byte
  stays, never through a link; missing/changed → «door closed/changed», never rewritten unasked. The door (`env -i`, verbs word
  by word) receives with `zfs recv -s -u`, never `-F`, never mounted; an existing unit is put aside, never destroyed. `ping`
  reads RAM and flash only. Tickets never send or receive and are never in pairs.json. The .plg remove takes the lines and
  `partners/` out; copies stay.
* **Pictures:** each desk has `avatar.svg` (64×64, flat); **never an emoji where a drawing exists**.
* **Staff:** a fresh office has only the Team Lead; every desk declares `'fit'`. Firing hides a desk — data and what it set up
  stay. **Unhired desks get no write actions** (web side and agent, `testAgentHired`). Tips: a share to helmi1987, beyond the
  work's cost to animal shelters.
* **Supporter key** (`src/supporter.php`): **nothing ever locked** — it only silences reminders. The GUID never leaves the
  server; the page never gets a key; one ask 7 days after first seen, **never a modal**; claims by the agent, never the browser;
  the private key only on the maintainer's Mac.
* **Reports** (`agent/lib/report.php`): **never a request without the user's click on «Send»**; the send takes only the preview
  kept in RAM; `reportScrub()` over every log line and param (the user's own words never scrubbed); https only; report ID ≠
  supporter ID; the page never gets the private inbox's number or link; pictures redrawn without metadata, tick «nothing private
  on them» required. `testReport*`.
* **Characters:** Frau Snapshotini (Italian); Herr Backupsi (nervous, checks three times — never «scatterbrained»); Jack Emby
  (the intern, only under Frau Moverelli's eye, says so with a grin); Frau Protokolli (reads everything, understands nothing);
  Frau Putzteufel (dust everywhere, never throws away at once, knows every corner); der Teamchef (plain, friendly); Herr Restori
  (calm, white gloves, «Piano, piano»); der Berater (consultant speak); Frau Moverelli (removals lady, «Jack! Finger weg vom
  Pool»). Their it/fr/es names are each desk's `name` key (Frau Moverelli: «tu» in it/fr, «tú» in es). **In every text «Jack
  Emby», never just «Jack»**; what he prepares is never just «films» — «what Jack Emby laid out (from his libraries)» / «was
  Jack Emby bereitgelegt hat (aus seinen Bibliotheken)». Chatty bubbles may carry character, **warnings and errors stay plain**.
  Reception button via lang key `visit`, else «Visit {name}». Titles small in it/es running text, capitalised in fr.
* **Jack Streamer** (2026-10-14, his German name): German texts say «Jack Streamer», always in full (never
  «Jack»); a normal name, so the genitive where it reads better («Jack Streamers Liste»). His and Ms. Moverelli's bubbles
  (`bubble.*`, `greet.*`) and en/it/fr/es keep «Jack Emby» until the release renames them. Ids, routes, files, env vars,
  EmbyCache stay.

## Words

The same thing has the same word on every desk and text; grammar follows the word (de *der* Rundgang, *das* Backup).

| Thing | en | de | it | fr | es |
|---|---|---|---|---|---|
| a desk's look around · toast | Tour · Tour done | Rundgang · Rundgang fertig | Giro · Giro finito | Tournée · Tournée terminée | Ronda · Ronda terminada |
| watchman's round · button · section · watch book · array stopped | round · A round now · The last round · watch book · night shift | der Rundgang · Jetzt Rundgang machen · Der letzte Rundgang · das Wachbuch · die Nachtschicht | il giro · Un giro adesso · L'ultimo giro · il registro di guardia · il turno di notte | la ronde · Une ronde maintenant · La dernière ronde · la main courante · le service de nuit | la ronda · Una ronda ahora · La última ronda · el libro de guardia · el turno de noche |
| a backup run · … in use | run · Start a run · last/next run · Stop the run · stopped | das Backup (verb sichern) · Backup starten · das letzte/nächste Backup · Backup abbrechen · abgebrochen | il backup · Avvia un backup · l'ultimo/il prossimo backup · Interrompi il backup · interrotto | la sauvegarde · Lancer une sauvegarde · la dernière/prochaine sauvegarde · Arrêter la sauvegarde · arrêtée | la copia de seguridad (la copia) · Iniciar una copia · la última/próxima copia · Detener la copia · detenida |
| nightly (only the default schedule) · any other time · one run or what it left (never «a night») | the nightly backup · the scheduled backup · a backup · earlier backups · {nights} backups in a row | das nächtliche · das geplante Backup · das Backup · frühere Stände · {nights} Backups in Folge | il backup notturno · pianificato · il backup · i backup precedenti · {nights} backup di fila | la sauvegarde nocturne · planifiée · la sauvegarde · les sauvegardes précédentes · {nights} sauvegardes d'affilée | la copia nocturna · programada · la copia · las copias anteriores · {nights} copias seguidas |
| per app / VM · ZFS/btrfs/VM · check mode (Tour button) · dry run · backup.sh & co. · where packages go | package · snapshot · tour · dry run · the (backup) engine · backup place | das Paket · der Snapshot · der Rundgang · der Probelauf · die (Backup-)Engine · die Backup-Ablage | il pacchetto · lo snapshot · il giro · la prova a secco · il motore (di backup) · il deposito dei backup | le paquet · le snapshot · la tournée · l'essai à blanc · le moteur (de sauvegarde) · l'emplacement des sauvegardes | el paquete · la instantánea · la ronda · la simulación · el motor (de copias) · el lugar de las copias |
| where a backup lies · levels · Basics switch | offsite · off / local / local + partner / local + offsite / local + partner + offsite · Back up offsite (with Kopia) | ausser Haus · aus / lokal / lokal + Partner / lokal + ausser Haus / lokal + Partner + ausser Haus · Ausser Haus sichern (mit Kopia) | fuori sede · off / locale / locale + partner / locale + fuori sede / locale + partner + fuori sede · Backup fuori sede (con Kopia) | hors site · off / local / local + partenaire / local + hors site / local + partenaire + hors site · Sauvegarder hors site (avec Kopia) | fuera de casa · off / local / local + socio / local + fuera de casa / local + socio + fuera de casa · Copiar fuera de casa (con Kopia) |
| the office · search · what it finds · the agent | the office · the search · place · the messenger (only the office's help adds «(agent)») | das Sekretariat (not Büro) · die Suche · der Ort · der Hausbote | la segreteria (not ufficio) · la ricerca · il luogo · il fattorino | le secrétariat (not bureau) · la recherche · l'endroit · le coursier | la secretaría (not oficina) · la búsqueda · el lugar · el mensajero |
| Mr. Backupsy's setup · `preset_new` · scope | the setup · «Set up…» · Apply · the default · Apply to everything now / Only to what is new — my settings stay | die Einrichtung · «Einrichten…» · Übernehmen · die Vorgabe · Auf alles anwenden / Nur auf Neues — meine Einstellungen bleiben | la configurazione · «Configura…» · Applica · l'impostazione predefinita · Applica a tutto adesso / Solo a ciò che è nuovo — le mie impostazioni restano | la configuration · «Configurer…» · Appliquer · le réglage par défaut · Appliquer à tout maintenant / Seulement à ce qui est nouveau — mes réglages restent | la configuración · «Configurar…» · Aplicar · el ajuste predeterminado · Aplicar a todo ahora / Solo a lo nuevo — mis ajustes se quedan |
| a restore · drill · button · what a drill writes · what it practises on | restore, bring back · drill · Practise now… · certificate · throwaway (container) | die Rückholung, zurückholen (dump: zurückspielen) · die Übung · Jetzt üben… · das Zeugnis · der Wegwerf-Container | il ripristino · l'esercitazione · Esercitati ora… · il certificato · il contenitore usa e getta | la restauration · l'exercice · S'exercer maintenant… · l'attestation · le conteneur jetable | la restauración · el simulacro · Practicar ahora… · el certificado · el contenedor desechable |
| Jack Emby's run · Ms. Snapshotini's plan · its run | run · real run · dry run · schedule · run | der Lauf · echter Lauf · Probelauf · der Zeitplan · der Lauf | l'esecuzione · esecuzione vera · prova a secco · la pianificazione · l'esecuzione | l'exécution · exécution réelle · essai à blanc · la planification · l'exécution | la ejecución · ejecución real · simulación · la programación · la ejecución |
| a ZFS hold | Hold · held · Release hold | Schützen · geschützt · Schutz aufheben | Proteggi · protetto · Togli la protezione | Protéger · protégé · Lever la protection | Proteger · protegida · Quitar la protección |
| thank-you levels | a coffee for the team · a round for everyone · cake for the whole office · a pay rise and the donations | ein Kaffee fürs Team · eine Runde für alle · Kuchen fürs ganze Büro (deliberately) · Lohnerhöhung und Spenden | un caffè per il team · un giro per tutti · una torta per tutta la segreteria · un aumento e le donazioni | un café pour l'équipe · une tournée pour tout le monde · du gâteau pour tout le secrétariat · une augmentation et les dons | un café para el equipo · una ronda para todos · pastel para toda la secretaría · un aumento de sueldo y las donaciones |
| share · disk · /boot · Docker template · a log · dataset · Emby's key | share · disk · the flash (a physical stick stays a stick) · template · log · dataset · API key | der Share · die Disk (not Platte) · der Flash · das Template (not Vorlage) · das Protokoll (not Log) · das Dataset · der API-Schlüssel | la condivisione · il disco · l'unità flash · il template · il log (registro = watch book) · il dataset · la chiave API | le partage · le disque · la flash · le modèle · le journal · le dataset · la clé API | el recurso compartido · el disco · el flash · la plantilla · el registro · el dataset · la clave API |
| Unraid's bell · user → makers · menu item | notifications · report · Report a problem or a wish… | die Benachrichtigungen (one sent: eine Meldung) · die Rückmeldung · Problem oder Wunsch mitteilen… | le notifiche · la segnalazione · Segnala un problema o un desiderio… | les notifications · le signalement · Signaler un problème ou un souhait… | las notificaciones · el comentario · Contar un problema o un deseo… |
| partner · pair · its key line · code | partner office · pair · the door · safety code | das Partner-Sekretariat · verbinden · die Tür · der Sicherheitscode | la segreteria partner · abbinare · la porta · il codice di sicurezza | le secrétariat partenaire · appairer · la porte · le code de sécurité | la secretaría asociada (el socio) · emparejar · la puerta · el código de seguridad |
| kept at partner · pull back · new server's start · temp door | At <partner> · Bring back · Start from a partner's copy… · ticket | Beim Partner <name> · Vom Partner zurückholen · Von der Kopie eines Partners starten… · das Ticket | Presso <partner> · Recupera · Parti dalla copia di un partner… · il ticket | Chez <partenaire> · Rapatrier · Partir de la copie d'un partenaire… · le ticket | En <socio> · Traer · Empezar desde la copia de un socio… · el ticket |

* **Kopia** is the tool (container, repository, KopiaUI, policies, its errors), never the place — the place is «offsite». First
  time on a page: «offsite (encrypted with Kopia: S3, Backblaze B2, SFTP, WebDAV, a NAS …)»; partners are offsite too but say
  «to the partner». Internal keys (`mode = kopia`) stay.
* **Nights:** a backup can run any time, so «a night» never stands for one run or its snapshot/package. «Night» stays where
  literal or a name: the Night Watchman, «at night», «Every day at» (not «Every night at»), the drill's window (00:00–07:00,
  «right after a nightly backup»), Immich's nightly dump, Jack Emby's night run; code names (`{nights}`) and engine lines keep
  theirs. The role says «the backup», the drill never «nightly». German «Lauf» only for Jack Emby's and plan runs; Mr. Restori's
  drill is «die Übung», never «Probelauf».
* **Unraid's own words:** every label of Unraid's UI a text names (menu tabs, tiles, buttons, fields, values, paths like
  `⟦Settings⟧ → ⟦User Utilities⟧ → Fix Common Problems`, «the ⟦Main⟧ page») is a token `⟦English label⟧` exactly as English
  Unraid shows it, **in all five languages**; it reads in Unraid's language (`unraid_lang`), resolved by `t()`,
  `officeNotifyText()`, `officeDashT()` via src/words.php. A placeholder never goes inside a token. Dictionaries
  `public/lang/unraid/<code>.json` from Unraid's language packs (the page's file where Unraid shows it; `_meta` names pack and
  commit; pack quirks kept; an untranslated label maps to itself). **Not tokens:** other software and untranslated plugins (Fix
  Common Problems, User Scripts, Compose Manager, Emby, Kopia, Grafana), the office's own words, values Unraid never translates,
  prose about the Docker page or the Dashboard. **Adding a label:** `⟦label⟧` in all five languages (words around it that still
  read when it stays English), then in each dictionary; `testUnraidWords` (none missing, none unused).

## UI conventions (for all new code)

* **Inside Unraid:** everything lives in `#sso` (always `.in-unraid`); all CSS nested in `#sso{ … }`; dialogs, menus, tips
  appended to `#sso`, **never `body`**; no Unraid `unapi` class; links into Unraid open in the same tab. Colours are tokens
  (`--ink`, `--surface` …) mixed from Unraid's theme variables — **never a hard-coded colour**. Theme/size switches:
  `Office.theme`, `Office.size` (a new small prose class goes into a `--floor` group in size-switch.css); `testThemeSwitch`,
  `testSizeSwitch`.
* **The wave** `el('span','spin')` (Unraid's loading mark) wherever something runs and the user needn't wait (still under
  `prefers-reduced-motion`) — **never a circle spinner or GIF**.
* **Show first, then look:** state only via `Office.loadState(id, {fresh, part}, took)`, never `Office.api.get({a:'state'})`;
  only the shown desk looks. **An action on what a page shows** starts with `await Office.freshState(id)` and looks the thing up
  again by id — never acts on a stale list (`office.stale_refused`). `testApiLook`, `testLookPage`.
* **Head:** `Office.deskHead(desk, {bubble, actions, pages})` (no role line), then `Office.pageHelp()` for explanations — not
  repeated legends. **Two tabs = two pages, at most two**; everything else is a section/tile; no crumb, no «← Back».
  `testDeskPages`. **Sections:** `Office.sectionHead(title, explanation, ...extras)`; no explanatory text floating right.
* **Tiles — a tile is a section.** The row of tiles is the page's table of contents; a tile without a section goes. A click
  shows **that section alone** (others gone, not folded; a filter tile shows it filtered); **nothing active = whole page**;
  second click = nothing active; one start tile or none; active = `.tile-on` + `.tile-mark` + `aria-pressed`; the section is
  brought into view once (`keepInPlace(row)` first); `sw.pick(id, 'start')` (a desk's own opening) is never kept. Hints above
  the row stay. Tiles are real `<button>`s (`.stat`/`.card`). **What is filtered is said in one place:** the filter bar's note
  line (`bar.done()`), never in a section head. Every tile is a place with a sub-route `#/<desk>/<tile>`. A switch that changes
  what a tile says redraws the row (`sw.row()`). **Every desk uses the switcher** `Office.sections()` (`SECTIONS_DESKS`; no own
  `renderTiles`/`renderSection`, no `hidden` on a section); **every list's bar is `Office.filterBar`** (built once per visit).
  APIs: docs/DEVELOPMENT.md «Tiles and lists». Tests: `testSections`, `testFilterBar`, **`testTilesConvention`** (no
  `role="button"`, no `scrollIntoView(` without `/* reveal */`, no own folding, filter tiles are places, no filter keys stored),
  `tools/ui-clicks.sh`, `bash tools/ui-tiles.sh --only <route>` (before/after pictures — how a desk proves its tiles).
* **Remembered per browser** (`Office.store`, prefix `office.`): choices only (`<desk>.tile`, a pool, `<key>.pick`, favourites)
  — **never** filter words, «only …» switches or filter tiles.
* **CSS names:** desk classes carry the desk's prefix (`bk-`, `lg-` …); never reuse an office.css name (`.empty`, `.card`,
  `.row`, `.hint` …); **never a level/state from data as a bare class**; a grid overriding `.kv` or row columns brings its own
  `@media (max-width:640px)` rule.
* **Search:** each desk lists its places with ONE `Office.places(ID, [...])` and marks them; every section head is a place (or
  in the test's `$notPlaces` with why); `places.json` lists the same keys; words fetched once on first open, never with the
  first paint or per keystroke. Items: ONE `Office.placesFrom()` (≤ 2 ms, ≤ 200, no request of its own); **never a raw log line,
  a secret or a path the page doesn't show**; every item's anchor is on the page or opens via its route.
  `testSearchPlaces/Items`.
* **Rows** with one main action: the whole row clicks (`.row.unfolds`) except own buttons/links/fields/`data-own`; tooltip on
  the name; every chip has its own `title`; «Unfold all»; the name takes Enter/Space (`testSetupUnfold`). Chip tips
  (`title`/`data-tip`) on hover or tap; that click never folds the row; chips that act themselves explain on hover only; ids go
  into `data-id`, not `data-tip`.
* **Folding never makes the page jump:** every fold/unfold/re-render after a toggle inside `Office.keepInPlace(anchor, change)`
  (selection lists: only the checkbox selects). **What the user opened stays open:** `<details>` via `keepFold()`, never a bare
  `el('details', …)` (`testDetailsKept`).
* Subheadings in a list = a tinted bar (`var(--surface2)`), rows indented — never just bold text. Long lists fold one kind and
  day into a counted row, «Show n more»; a deep link clears a filter that hides its entry.
* **Backup protection** always via `Office.backupChip(level)` from `backupProtection()`.

## Server facts that bite

* **Which disk a file is on:** `getfattr -n system.LOCATION` (`…LOCATIONS`: all) on a /mnt/user path — no look at other disks.
  `shareSplitLevel` in `/boot/config/shares/<share>.cfg` (top level only: files follow an existing, even empty folder — Jack
  Emby keeps them as signposts).
* **ZFS:** a pool's top folder sees only its own dataset — fullness via `zfs list -Hp -o used,avail <pool>`. Every share and
  each VM folder in `domains` is a dataset: `rename()` can't move them, use `zfs rename`. `written` = since the latest snapshot.
* **Share storage (cfg):** `shareUseCache` no = array, only = pool `shareCachePool`, yes/prefer = primary `shareCachePool` +
  secondary `shareCachePool2` (empty = array; mover primary→secondary for yes, back for prefer); `shareFloor` in KB.
  `/var/local/emhttp/shares.ini` lists every share (`exclusive`), `disks.ini` has `fsFree` (KB) without touching a disk,
  `sec.ini`/`sec_nfs.ini` the exports and security as applied.
* **Exclusive shares** (`shareUserExclusive`, changeable only with the array stopped): `/mnt/user/<share>` is a **symlink**
  `../<pool>/<share>` (primary a pool, no secondary, top folder on that one volume only; decided at array start). Code that
  refuses links must allow exactly this one, via `officeUnraidPath()`. /mnt/user costs ~50× the pool path per look.
* **shfs:** a flock through /mnt/user lies on the backing file (inode `(st_dev << 48) | st_ino`) — `flockHeld()` looks for both.
  `rename()` through /mnt/user renames on every part (no copy); new folders land on the primary; a dataset of its own is renamed
  with `zfs rename`, never through shfs.
* **Disk tunables** are read-only for the office (`md_write_method`, `zfs_arc_max` — 0 = unlimited is right).
* **Web stack:** php-fpm as root; nginx `auth_request` guards everything incl. `/plugins/…`; `local_prepend.php` chdirs to
  `/usr/local/emhttp` and ends every POST without the right `csrf_token` (field or `X-CSRF-Token`, from var.ini) with an empty
  200; nginx doesn't gzip `.php`.
* **emhttp waits for event scripts** (`started`, `stopping`): a slow one holds up array start/stop. **Plugin manager:** `.plg`
  versions via `strcmp`, `min`/`max` via `version_compare`; a failing `Run` script aborts the install; every plugin is
  reinstalled at each boot (before the array); registered only after its install script (its cron file isn't picked up during a
  fresh install); icons from `<plugin>/images/`.
* **Header button pages** (`Menu="Buttons:…"`): body evaluated in `<head>` of every Unraid page — a line or two of
  `<style>`/`<script>` only. FA 4.7 has no bell: the office paints its bell as a CSS mask (fallback `building-o`); a Tasks
  page's sidebar glyph stays `f0f7` — never a bell (that's Unraid's notifications).
* **Cron:** `update_cron` builds root's crontab in RAM from `dynamix/*.cron` + installed plugins' `*.cron` (`crontab -e` edits
  vanish at reboot; no shebang — run via `bash`). dcron also reads `/var/spool/cron/crontabs/root`; plugins writing it with
  `crontab -l | … | crontab -` can duplicate the system crontab (jobs twice — `job.sh` skips a second start in the same minute).
  atd queue `/var/spool/atjobs`; notification agents in `/boot/config/plugins/dynamix/notifications/agents/`.
* **VMs:** XML, NVRAM, TPM live in `libvirt.img` (`/etc/libvirt`); live state in `/var/run/libvirt/qemu/<vm>.xml`. Array stop:
  paused resumed, `virsh shutdown`, wait `TIMEOUT` (60 s), then `virsh destroy`.
* **Saving share settings resets a share's root to `0777 nobody:users`** unless its group is `users` (Nextcloud refuses `occ`;
  fix: `33:100`, `0750`).
* **7.3.2:** a container without icon makes the Dashboard/Docker page request a missing `question.png` endlessly (/var/log full
  in minutes) — keep the Dashboard open only briefly in tests, watch `df /var/log`.
* **Small ones:** scripts edited over SMB may be «Text file busy» — start them via `bash <script>`. `btrfs filesystem show`
  without args reads every device raw — use `--mounted`; a btrfs snapshot of a busy disk can take minutes (bash traps run after
  the current command). User Scripts' last run is known only since reboot (`/tmp/user.scripts/tmpScripts/<name>/`). Media pools:
  never search deeper than 3 levels, prune `*.sparsebundle`, never the array. Emby: a sleeping device can stay in `/Sessions`
  for hours — `LastActivityDate` tells. Grafana: provisioning via `GF_PATHS_PROVISIONING`, the admin password counts only at
  first start.
* **Docker:** digest-pinned images aren't in `docker image ls` (use `docker inspect`); `docker system df` takes seconds — not in
  a refresh; `docker top -eo` needs `pid`; log rotation applies only to new containers. Postgres' first start inits on the
  socket only — wait for TCP; a bind mount resolves at start (stop, swap, start).
* **Kopia:** a full maintenance over S3 can run many hours and never resumes — **never `maintenance run --full --safety=none`
  while anything can write, never drop deleted contents by hand.** Sources are named by the **container path** (a new Container
  Path = new sources; a new repository = full upload). Upload progress = `rchar` of its process (a VM by apparent size).
  imagegenius: htpasswd only at first start; restart after a CLI connect. Object Lock only at `repository create`;
  `--extend-object-locks` needs the period > full interval + 1 day; probe: 200 on / 404 none / 501 unsupported / 403 bad keys.
* **Container pictures:** Unraid's downloader needs `file://` for local paths, sends no User-Agent, never looks again once a
  path is in `docker.json`. Compose Manager `labels_view_mode=advanced` = hands off. CA's `templates_new.json` (~30 MB,
  PHP-serialized): grep, never unserialize.
* `/mnt/addons` is Unassigned Devices' 1 MB tmpfs; without UD the office makes a plain folder; metrics there stay a few KB.
* **Data flow:** `ss -tin`, a container's `/proc/<pid>/net/dev`, `smbstatus -b --json`; no conntrack bytes, no file-access
  logging. **Logins:** `webgui: (Un)successful login user <name> from <ip>` (`<name>` may be a typed password; 3 failures lock
  15 min); sshd as `sshd-session`. `/root/.ssh` → `/boot/config/ssh/root`.
* **Add Container form** (`?xmlTemplate=<type>:<file>`): Apply writes `my-<Name>.xml` and **removes an existing container of
  that name**; unknown path modes become rw (`ro,rslave` → rw!); `TemplateURL` updates are off in 7.3.2.
* **Unraid's mover (7.3.3):** «Disabled» = `shareMoverSchedule=""`, no `dynamix/mover.cron` (emhttpd removes it at each array
  start); the form sends only `shareMoverSchedule`, `shareMoverLogging`, `changeMover=Apply` (via emcmd). It moves everything
  (no list), `/var/run/mover.pid`. Mover Tuning has its own cron, but ⟦Move now⟧/Main's Move/⟦Apply⟧ run Unraid's; a share
  override counts only with `moverOverride="yes"`.

## Several chats at once (workers and coordinator)

One chat is the **coordinator**, the others **workers** — one per task (not per desk), at most two or three, closed when done.
* **Workers:** a git worktree of their own on a local disk, branch `task/<short-name>` — never the working copy on the server
  share. They commit and push their branch, run the checks they can (`php -l` via ssh, JS syntax, `php tests/run.php` on a copy)
  and report what they did and what is untested. **They never merge into `main`, never run `dev-sync.sh`, never release, never
  start backup runs or apply settings.**
* **The coordinator** owns `main`, the working copy and the server: reviews, merges, full checklist, `dev-sync.sh`, page check,
  releases — only the coordinator deploys. **After every deploy the coordinator clicks the real flows on nostromo itself**
  (first setup, reset/«von vorn», every step to the bottom also at ~1280×720, Abbrechen/Zurück, dialogs inside dialogs) before
  telling the owner it is done; worker briefs carry that flow list for their demo page. A worker gets replaced by a fresh one
  after a few rounds (a long chain gets sloppy).
* **Shared files** (`core.js`, `office.css`, `src/`, `agent/lib/`, `backup/`, CLAUDE.md) are changed by one chat at a time, as
  the coordinator says; a desk's own folder belongs to the task working on it.
* Tests that write (`setup.sh --apply`, runs) touch the real Kopia repository and containers even with another `UB_DATA`: only
  with Kopia off in the test decisions, and only by the coordinator.

## Checklist for a change

1. **PHP syntax** on the host: `php -l` for every file in `agent/`, `src/`, `public/*.php`; `bash -n` for `plugin/scripts/*`,
   `plugin/event/*`, `backup/`.
2. **JS syntax:** `node --check <file>` (or `osascript -l JavaScript` with `new Function(src)`).
3. **Suite** on the host: `php tests/run.php` — must end with 0 failed. `php tests/run.php <part|test> …` runs only those
   (unknown name: exit 2). Tests use their own RAM folder (a child with its own env gets `'OFFICE_RUN_DIR' => TESTS_RUN_DIR`) in
   a mount namespace — never the live agent's (`testLiveRunUntouched`). **Engine fixtures run on the tests' clock**
   (`testsClock($bin, $clock)`, starting 03:00 tomorrow): `testsClockRun()` before every run, `testsClockNight()` for a night
   later, `testsClockNow()` for the engine's «now» (never `time()`); a new `date` form → add it to `testsClock()`; end with
   `testsClockUnsupported($clock) === ''`, output through `testsClockRan()`. **Never remove logs between runs to dodge the
   minute.**
4. On the server (coordinator): `bash plugin/dev-sync.sh` puts the working copy into the plugin (leaves backup/ alone while a
   run is active); the agent restarts itself on changed files (watch `data/agent.log`; a syntax error keeps the old code).
5. Reload the page for real (a `#` change doesn't reload). Phone width 375 px: no horizontal scrolling; inside Unraid check
   black and white themes (swap the theme CSS in a test tab — **never change the user's setting**).
6. Destructive actions only on throwaway objects, then clean up; never wake sleeping disks, start backup runs or apply settings
   on a real server just to test.
7. Plugin changed (paths, scripts, `.plg`)? `bash plugin/build.sh <version>` must pass.
8. **Page changed?** `bash tools/ui-clicks.sh` on the Mac (headless Chrome with a throwaway profile — never a browser with
   sign-ins; stub states in `tests/ui/states/`, a new state shape goes there): every control clicked twice, both themes, 1440
   and 375 px; exit ≠ 0 on any failure; `--only <route>,…`, `-v`. `tools/release.sh` runs it before the suite. **Screenshots**
   for product page/forum: `bash tools/ui-shots.sh` (demo data in `tests/ui/demo/` — invented family data only; `--moments`,
   `--themes black,white`); prepare steps stay language-neutral (`Office.t(…)`, not German selectors).
