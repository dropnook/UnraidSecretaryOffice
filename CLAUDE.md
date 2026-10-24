# Working on Unraid Secretary Office

Read README.md (short), docs/GUIDE.md (each desk) and docs/DEVELOPMENT.md (architecture, adding a desk, tiles and lists,
partner offices inside). This file holds **rules only, in short** — the same rules in full (each desk, the words table, UI,
server facts): docs/DEVELOPMENT.md «The rules in detail»; details in code comments, `tests/run.php` and `backup/README.md`;
earlier versions: `git show v1.62.0:CLAUDE.md` (build notes: `v1.60.0`).

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
                       where, report, import, watchnet, paritywhy, nightrun.php (the joint night run), poollevel.php (limit X)
agent/desks/<id>.php   one desk each: desk('<id>', [...]) (+ moverelli-unraid/-import, restore-drill/-partner)
src/*.php              web side: bootstrap (versions), place (paths, URLs, OFFICE_UNRAID_TESTED), api, staff, page, words
public/assets/core.js  Office: t(), routing, api, loadState, deskHead, sectionHead, sections, filterBar, places, keepInPlace
public/lang/ desks/    office strings ×5 (lang/unraid/: Unraid's words); desks/<id>/: desk.json, desk.js, places.json, lang/
backup/ mediacache/ gather/ smartmover/  the engine; Jack Streamer's tools; Ms. Moverelli's Smart Mover (data in data/<tool>)
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
  tokens in `office.css :root`. The only build is `plugin/build.sh` — one exception: the USO network's helper `helper/`
  (Go, reproducible, pinned by SHA-256 in usonet.php and usonet.sh, never in the package; docs/DEVELOPMENT.md).
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
  office says which and why. One exception: Unraid's standard shares appdata/system when never there, and the office's own
  share UnraidSecretaryOffice as the backup place (Mr. Backupsy's first step) — only on the user's click (the welcome, the
  Team Lead's card, «Create UnraidSecretaryOffice…»), by Unraid's own «Add Share» (agent/lib/firsthome.php); never on its
  own, never another name.
* **Never block the array stop:** nothing kept open in the pool (doorbell, heartbeat in `RUN_DIR`); the night shift never opens
  anything under `/mnt` (cwd `/`).
* **Never delete right away** what the user may want back (storeroom, set-aside). Backups keep copies on purpose (the office's
  share, anything called «backup», `my-*.xml` in a backup's templates) — never leftovers.
* **Nothing private public:** demo data and every public picture invented — private home videos (Familienfilme/Familienserien),
  never a commercial title, real names, paths or addresses; public texts (site, README, forum) say what is, never its history;
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
* **Notifications** only via `officeNotify()` (naming its desk and event) — every sender behind the Team Lead's ONE gate
  (`notifycenter.php` = `notifygate.sh`, the same decision; alerts never held; settings only in his notification centre): subject prefix `Unraid Secretary Office`; `warning` = something to fix, `alert`
  only when something is at risk now; texts `notify.*` via `officeNotifyText()` in the office's last language
  (`officeNotifyLang()`); tests use `OFFICE_NOTIFY_BIN`. All senders (agent, night shift, agent.sh `tell`, engine `ub_notify`)
  share the stamp `RUN_DIR/notify.second` (Unraid drops a second one of the same event in the same second). `-m` line breaks are
  a literal `\n`; never a real newline in `-d`.
* **Heartbeat in RAM** (`RUN_DIR/agent.json`, every 20 s); `agentUp()` is the one rule for «at work»; a request waiting while
  the agent is away is answered `agent_away` after 4 s; action buttons disabled while `Office.agent.running` is false.
  `agent-watch.cron` alerts after 10 min of a stale heartbeat with the array started.
* **Jobs that must outlive the agent** (backup runs, restores, drills, let-gos, Jack Streamer's and Ms. Moverelli's runs) go
  through `atd` (`hostLaunch()`, `backupLaunch()`), never as the agent's child.
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
* **What holds** (in full: docs/DEVELOPMENT.md «The backup engine: what holds», backup/README.md): one run at a time behind
  `state/lock` (the office only looks via `flockHeld()`); one real backup a minute (`uso-backup-YYYYMMDD-HHMM`); backups never in
  appdata; packages never deleted; new things stay local until the user decides; VMs never forced off; array stop ends a run at
  once (stopped, never failed); the retention never thins a partner's copies or the storeroom; `partner_ssh_cmd()` the one place
  for ssh options. Fixtures run on the tests' clock (checklist 3).

### Jack Streamer's tools
`mediacache/` (MediaCache by helmi1987: Emby, Jellyfin, Plex; job id still `embycache`) and `gather/` ship with the office; our changes kept small and listed at the top of their
README.md; their data apart (`MEDIACACHE_DIR`, `CONSOLIDATE_CONFIG`); they report through status files, never parsed log lines.
**MediaCache MOVES files** — test with report and dry runs. Runs via `php agent.php job embycache|gather <mode>`; a real run
holds the other tool's lock. The cleaner is left out on purpose. Smart Mover (`smartmover/`, helmi1987) likewise: office hooks
`CUSTOMMOVER_*` listed in smartmover/README.md, changes marked `USO`.

### Versions and releases
`OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION` (agent/agent.php) move together; tag `v<version>`. The plugin's version
is a date (`2026.10.05`, a letter for a second one that day; `strcmp`), chosen by `.github/workflows/plugin.yml`; `build.sh`
refuses mismatching versions. Release only via `tools/release.sh <version>` from a clean main (runs click test + suite), English
notes for users; afterwards check the `.plg` got a new version. Tested Unraid = ONE constant `OFFICE_UNRAID_TESTED`
(src/place.php); .plg `max` = `<major>.99.99` of it, raised only once tested (`testUnraidTested`). Commits carry no
Co-Authored-By line. README.md stays short; the per-desk detail lives in docs/GUIDE.md.

### Updates
Users update through the plugin manager and read no notes: **every version starts on any older version's data folder, and an
older on a newer's.** `officeMigrateStart()` (agent/lib/migrate.php, before any desk reads state) is **the one place for
migrations**: steps `['id','version','run']`, idempotent, logged, never deleting (`<name>.before-<version>`). New keys additive,
read with a default (PHP and desk.js — the first state can be the old agent's); unknown keys kept on rewrite; **no rename** of a
key, file, path, cron command, door path, snapshot prefix, Kopia source path or desk id without a step and readers of both;
exact-shape files (pairs.json, the watchman's mirror, the drill's certificate, storeroom manifests) change only with a new
`v`/`interface`; engine defaults for settings.ini keys don't change. **Tolerant writers:** readers use only entries in exactly
their shape, writers keep entries they don't know (`testPartnerTolerant`). **Install swaps** (`testPlgInstall`): unpacked
beside, checked first; update/remove refused while a run, setup, restore, drill or Jack Streamer's/Ms. Moverelli's run is active
(one `pgrep -f` pattern, `testPlgGuard`); remove runs `agent.sh release`, puts the cron file aside (`officeCronBack()`), removes
the office's authorized_keys lines. A new agent version → «Reload» offered, never forced (`testLookPage`).

## The desks

**Each desk's full rules: docs/DEVELOPMENT.md «The desks» — read your desk's entry before touching it.** Desks know each other
only through shared libraries (`backupProtection()`, `finding()`, `partnerlook.php`, `nightrun.php`, `poollevel.php`) and links
(`#/<desk>`) — each must work on its own. The hardest rules:

* **`snapshot` Ms. Snapshotini:** retention touches only her plans' `uso-plan-…`, never the engine's; an action on a sleeping
  pool refuses without `wake`; a partner's copies are never a target.
* **`backup` Mr. Backupsy:** the engine through its interface only; setup levels per share/VM/app; no place → the setup
  is that one step; **the office never makes the share** but UnraidSecretaryOffice on its button; Apply sends only what was
  chosen; never starts Kopia.
* **`jack` Jack Streamer:** works only under Ms. Moverelli (`"needs"`); the API key never leaves the server; rsync both ways;
  never a real gather while someone watches Emby; never writes into Mover Tuning; Unraid's mover only via its form (`emcmd`).
* **`moverelli` Ms. Moverelli:** Unraid's mover off only with the page's consent `mover_off: true`, her let-go puts it back;
  never a hand-written cron line; the night run (nightrun.php) and the pool's **one limit X** (poollevel.php).
* **`logs`, `watchman`, `cleanup`'s «Where is what»: read only.** Only the watchman gives security advice; API keys never shown.
* **`cleanup` Ms. Dustdevil:** never deletes right away (hidden storeroom, `manifest.json`, put back); only she writes there.
* **`advisor` the Consultant:** the user clicks Apply in Unraid's form; never logs into a web UI or HTTP API; Kopia secrets only
  after explicit confirmation, through RAM.
* **`restore` Mr. Restori:** never over something still there without asking; what is replaced is put aside first; throwaway
  drill containers only via `drillContainerArgs()` + `drillArgsSafe()`.
* **`caretaker` the Team Lead:** hires/fires, collects checks; «I know, thanks» never for musts; he only pairs partners.
* **Partner offices:** only `uso-partner:`/`uso-ticket:` lines in authorized_keys are touched; the door receives with
  `zfs recv -s -u`, never `-F`; nothing destroyed.
* **USO network** (the office's own Tailscale node, agent/lib/usonet.php, scripts/usonet.sh): only the network underneath —
  the pairing unchanged, the partner code asks only `usonetWrap()`/`usonet_peer`; nothing of the host's network, the user's
  Tailscale or Unraid's sshd touched; the helper pinned by SHA-256; the auth key a secret; leave/remove leaves nothing.
* **Staff:** unhired desks get no write actions (`testAgentHired`); firing hides a desk, data stays. **Supporter key:** nothing
  ever locked, never a modal. **Reports:** never a request without the user's click on «Send», `reportScrub()` over all.
* **Characters and names:** each desk's `name` key; **always «Jack Streamer», never just «Jack»**; chatty bubbles may carry
  character, warnings and errors stay plain; the desks speak for themselves (tool names only in thanks, import, log titles).

## Words

**The same thing has the same word on every desk** — the table (backup run, tour, package, offsite, restore, drill, hold,
partner …, ×5 languages) is in docs/DEVELOPMENT.md «Words»: **read it before writing any UI string.** In short: the office =
das Sekretariat / la segreteria / le secrétariat / la secretaría (never Büro); Kopia is the tool, the place is «offsite»; no
run happens «at night» (on schedule or started here; Ms. Moverelli and Jack Streamer: «the joint run»; only the Night Watchman
keeps the night); **Unraid's own labels** are tokens `⟦English label⟧` in all five languages, listed in
`public/lang/unraid/<code>.json` (`testUnraidWords`), never a placeholder inside a token.

## UI conventions (for all new code)

Full text: docs/DEVELOPMENT.md «UI conventions in full» and «Tiles and lists» (APIs).
* **Inside Unraid:** everything in `#sso`, CSS nested in `#sso{ … }`, dialogs/menus appended to `#sso` — **never `body`**;
  colours only as tokens (`--ink`, `--surface` …) — **never a hard-coded colour**; `testThemeSwitch`, `testSizeSwitch`.
* **The wave** `el('span','spin')` wherever something runs — **never a circle spinner or GIF**.
* **Show first, then look:** state only via `Office.loadState()`; an action starts with `await Office.freshState(id)` and looks
  the thing up again by id (`office.stale_refused`).
* **Head:** `Office.deskHead(desk, {bubble, actions, pages})`, `Office.pageHelp()`; **two tabs = two pages, at most two**;
  sections via `Office.sectionHead()`.
* **Tiles — a tile is a section.** Every desk uses `Office.sections()`, every list's bar is `Office.filterBar`; a click shows
  that section alone, second click = whole page; the filter bar's note line is the one place that says what is filtered; every
  tile is a place `#/<desk>/<tile>`. `testSections`, `testFilterBar`, `testTilesConvention`, `tools/ui-tiles.sh`.
* **Remembered per browser** (`Office.store`): choices only — never filter words, «only …» switches or filter tiles.
* **CSS names** carry the desk's prefix; never reuse an office.css name; never a level/state from data as a bare class.
* **Search:** ONE `Office.places()` and ONE `Office.placesFrom()` per desk; never a log line, a secret or an unshown path.
* **Rows:** the whole row clicks (`.row.unfolds`), every chip its own `title`. **Folding never makes the page jump**
  (`Office.keepInPlace()`); `<details>` via `keepFold()`. Backup protection via `Office.backupChip(level, note)`.

## Server facts that bite

More (cron, VMs, Docker, Kopia, containers, data flow, logins, Add Container form): docs/DEVELOPMENT.md «Server facts in full».
* **Which disk a file is on:** `getfattr -n system.LOCATION` on a /mnt/user path — no look at other disks.
* **ZFS:** a pool's top folder sees only its own dataset (fullness via `zfs list -Hp -o used,avail <pool>`); every share and VM
  folder in `domains` is a dataset — `rename()` can't move them, use `zfs rename`.
* **Shares:** `shareUseCache` no = array, only = pool, yes/prefer = primary + secondary; `/var/local/emhttp/shares.ini` lists
  every share, `disks.ini` has `fsFree` without touching a disk. **Exclusive shares:** `/mnt/user/<share>` is a symlink to the
  pool — code that refuses links allows exactly this one via `officeUnraidPath()`; /mnt/user costs ~50× the pool path.
* **shfs:** a flock through /mnt/user lies on the backing file — `flockHeld()` looks for both; `rename()` through /mnt/user
  renames on every part.
* **Disk tunables** are read-only for the office. **Web stack:** php-fpm as root; nginx `auth_request` guards everything; a POST
  without the right `csrf_token` ends with an empty 200; nginx doesn't gzip `.php`.
* **emhttp waits for event scripts** (`started`, `stopping`) — keep them fast. A failing `Run` script aborts a plugin install;
  `.plg` versions via `strcmp`. **Header button pages** run in every Unraid page's `<head>` — a line or two only.
* **Cron:** root's crontab is rebuilt in RAM from `*.cron` files (`crontab -e` edits vanish at reboot).
* **Kopia:** **never `maintenance run --full --safety=none` while anything can write, never drop deleted contents by hand.**
* **7.3.2:** a container without icon makes the Dashboard/Docker page loop on `question.png` (/var/log full in minutes).
* **Unraid's mover:** «Disabled» = `shareMoverSchedule=""`; its form sends only `shareMoverSchedule`, `shareMoverLogging`,
  `changeMover=Apply` (via emcmd).

## Several chats at once

One **coordinator** owns `main`, the server and releases; **workers** (one per task) work in a worktree on `task/<name>`,
commit and push, never merge, dev-sync, release or apply settings on nostromo. Shared files (`core.js`, `office.css`, `src/`,
`agent/lib/`, `backup/`, CLAUDE.md) one chat at a time. **Test servers** Tower, USOPartner, USOTest3VM are throw-away; only
nostromo is real; USOPartner stays on the last release (only `tools/release.sh` updates it). Full text: docs/DEVELOPMENT.md
«Several chats at once».

## Checklist for a change

1. **PHP syntax** on the host: `php -l` for every file in `agent/`, `src/`, `public/*.php`; `bash -n` for `plugin/scripts/*`,
   `plugin/event/*`, `backup/`.
2. **JS syntax:** `node --check <file>` (or `osascript -l JavaScript` with `new Function(src)`).
3. **Suite** on the host: `php tests/run.php` — must end with 0 failed; `php tests/run.php <part|test> …` runs only those.
   Tests use their own RAM folder, never the live agent's. **Engine fixtures run on the tests' clock** (`testsClock()`,
   docs/DEVELOPMENT.md «Tests on the clock»); never `time()`, **never remove logs between runs to dodge the minute.**
4. On the server (coordinator): `bash plugin/dev-sync.sh`; the agent restarts itself on changed files (`data/agent.log`).
5. Reload the page for real. Phone width 375 px: no horizontal scrolling; check black and white themes (swap the theme CSS in a
   test tab — **never change the user's setting**).
6. Destructive actions only on throwaway objects, then clean up; never wake sleeping disks, start backup runs or apply settings
   on a real server just to test.
7. Plugin changed (paths, scripts, `.plg`)? `bash plugin/build.sh <version>` must pass.
8. **Page changed?** `bash tools/ui-clicks.sh` on the Mac (headless Chrome, throwaway profile; stub states in
   `tests/ui/states/`): exit ≠ 0 on any failure; `--only <route>,…`. Screenshots: `bash tools/ui-shots.sh` (invented demo data
   only; prepare steps language-neutral).
