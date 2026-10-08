# Developing the Unraid Secretary Office

What the office does for its users is in the [README](../README.md). This file is the overview for contributors: how
it works inside, where everything lies, how a desk or a language is added, how to test and release.
[CLAUDE.md](../CLAUDE.md) is the deep reference — the conventions, every desk's internals, the server facts that bite
and the checklist for a change; where the two differ, CLAUDE.md is right. The backup engine has its own document:
[backup/README.md](../backup/README.md).

## How it works

```
 browser ──▶ Unraid's web server (nginx + PHP, behind the Unraid login)
               │  the office's page: /plugins/unraid-secretary-office/
               │  data/mailbox/<id>.request   ▲  <id>.response
               ▼                             │
             agent (a service of the plugin, on Unraid's own PHP)
               └─ zfs, btrfs, docker, virsh, /boot/config …
```

* **A plugin.** The code lies in RAM under `/usr/local/emhttp/plugins/unraid-secretary-office/`, unpacked from the
  package on the flash at every boot. `SecretaryOffice.page` puts the office into Unraid's menu bar (or, as chosen
  under *⋯ → Entry in Unraid*, under *Settings → User Utilities* or as a header button —
  `SecretaryOfficeButton.page`); `SecretaryOfficeDashboard.page` is the Dashboard tile. Unraid's nginx and php-fpm
  serve it behind Unraid's login.
* **The page only shows.** The **agent** (in the UI: the messenger) does the work on the host. It only accepts the
  actions the desks define and checks every request against a fresh look at the system; commands run without a shell.
* **The mailbox.** Page and agent talk through a folder in the data folder — deliberately no unix socket: a bound
  socket in the pool would keep it busy and Unraid could not stop the array. After dropping a request the page rings
  the agent's **doorbell** (a FIFO in RAM), so a request costs a few milliseconds on top of the agent's own work.
  Secrets the user types for the agent (the Consultant's Kopia setup) never go into the mailbox: they wait in a 0600
  file in RAM, and the request carries only its name.
* **The heartbeat lives in RAM** (`/var/run/unraid-secretary-office/agent.json`, its mtime the pulse every 20 s), so
  nothing of the office lands on the pool every few seconds and a pool of hard disks can spin down.
* **The data folder** is `DATA_DIR` from `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
  (default `<appdata>/UnraidSecretaryOffice/data`). When it lies in an exclusive share the office reaches it on the pool
  directly, past Unraid's user share layer — many times faster for every look.
* **Answers go out gzip-compressed** (`api.php`, from 1 KB): what counts over a VPN or Unraid Connect.
* **Array start and stop.** The agent starts with the array (`event/started`) and stops within seconds when it stops
  (`event/stopping`) — it never holds the array up. While the array isn't started (after a stop; from boot until the
  first start) `scripts/agent.sh` runs the Night Watchman's **night shift** instead: RAM and the flash only, never
  anything under `/mnt`. The page and the Dashboard tile say so.
* **Long jobs outlive the agent.** Backup runs, restores, the drill and Jack Emby's runs go to the host's `atd`, never
  as children of the agent (`agent.sh stop` ends the agent's whole session).
* **Schedules** (the nightly backup, Ms. Snapshotini's plans, EmbyCache, the gather) are lines in the plugin's cron file
  `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`, calling `scripts/job.sh <job>`, which runs
  only while the array is started. `agent-watch.cron` next to it looks at the heartbeat every 5 minutes.
* **The backup engine** (`backup/`: `backup.sh`, `setup.sh`, `lib/common.sh`, bash) runs on its own from the cron file.
  Mr. Backupsy uses only its interface: `backup.sh --about`, `state/status.json` and its siblings, `setup.sh --plan` /
  `--apply`. Reasons go out as codes the office translates; the office never parses log lines for anything new.
* **Sleeping disks** are never woken unless the user asks (`sleepingDisks()`, `disks.ini`).

## Where things are

| What | Where |
|---|---|
| The code (RAM, unpacked at every boot) | `/usr/local/emhttp/plugins/unraid-secretary-office/` |
| The package and the one setting (`DATA_DIR`; also the menu entry's `MENU_NAME`, `MENU_PLACE`) | `/boot/config/plugins/unraid-secretary-office/` |
| The schedules · the look at the agent every 5 minutes | `…/unraid-secretary-office.cron` · `…/agent-watch.cron` next to it |
| The Night Watchman's baseline for the night shift after a reboot (addresses, names, fingerprints — no passwords; at most hourly) | `…/watchman-mirror.json` next to it; in RAM under `/var/run/unraid-secretary-office/` |
| Uploaded container pictures (Ms. Dustdevil) | `…/icons/<container>.png` next to it |
| State, logs, the backup engine's settings | the data folder, `appdata/UnraidSecretaryOffice/data/` (comes with the array) |
| The agent's sign of life, its doorbell, locks, the secrets' inbox (RAM, root only) | `/var/run/unraid-secretary-office/` |
| Mr. Backupsy's packages per app and VM | the backup place, e.g. the share `UnraidSecretaryOffice`, folder `backup/`; their history in that share's snapshots |
| Snapshot mounts for Kopia (during a run) | `/mnt/addons/UnraidSecretaryOffice/snapshots/` |
| The office's numbers for Prometheus (a few KB, every minute) | `/mnt/addons/UnraidSecretaryOffice/metrics/` |
| Partner offices: this server's key per partner, the partner's pinned host key | `/boot/config/plugins/unraid-secretary-office/partners/` |
| Partner offices: the line that lets a partner in (only lines ending `uso-partner:<id>`) | `/boot/config/ssh/root/authorized_keys` (Unraid's file; other lines untouched) |
| A partner's copies kept here | `<pool>/UnraidSecretaryOffice-partners/<id>/…` (datasets, never mounted, never shared) |
| A restore ticket given here (a gone server's copies for a new server, 7 days) | its line in `authorized_keys` (ending `uso-ticket:<id>`), `data/partner/tickets.json` |
| What Mr. Restori pulled back from a partner | a new dataset beside the original (`<dataset>.restored-<time>`; on a new server `<pool>/UnraidSecretaryOffice-restored/<unit>`), mounted read-only under `/mnt/addons/UnraidSecretaryOffice/restored/` |
| Ms. Dustdevil's storeroom | `_UnraidSecretaryOffice-trash` on the same disk or pool as what was put away |

## The data folder

Runtime only, never in the repository (the repository's `data/` is what the tests use).

```
data/
├── office.json            the version marker: which version runs here, since when, which migrations are done
├── agent.json, agent.log  the agent's record (written at start and stop only) and its log (⋯ → Agent log)
├── <desk>.json            each desk's state; <desk>-<part>.json an extra part (desk.json "parts")
├── mailbox/               requests from the page, answers from the agent
├── office/                who is hired and in which order, the language for notifications
├── unraid-backup/         the backup engine: settings.ini, state/, logs/ (root only)
├── partner/               the Team Lead's pairs, tickets, the door's log (root only)
├── restore/, restore-drill/   Mr. Restori's journals, the drill's runs and certificates
├── embycache/, gather/    Jack Emby's tools: their settings, lists and status files
└── advisor/, caretaker/, cleanup/, snapshot/, watchman/   the desks' own folders
```

## Updates and migrations

Users update through Unraid's plugin manager and read no notes, so every version starts on any older version's data
folder. The agent keeps `data/office.json` and runs the migrations at its start, before any desk reads state:
`officeMigrateSteps()` in `agent/lib/migrate.php` is **the one place** for them — each step idempotent, logged, never
deleting (what it rewrites goes aside as `<name>.before-<version>`), tried again at the next start when it failed. New
keys are additive and read with a default; keys a reader doesn't know are kept; no rename of a key, file, path or name
without a step that rewrites it. The `.plg` refuses an update or a removal while a backup run or setup, a restore or
drill, or one of Jack Emby's runs is active; it unpacks the new version beside the running one, checks it, then swaps
the folders (`testPlgGuard`, `testPlgInstall`, `testPlgRemove`). Details: CLAUDE.md «Updates».

## Adding a desk

A desk is a set of files; nothing in the core needs to change.

```
agent/desks/<id>.php            what it does on the host
public/desks/<id>/desk.json     {"order": 40, "icon": "🧹", "refresh_after": 300}
                                ("order": its place in a fresh office; the user may set another at the reception)
public/desks/<id>/desk.js       its desk in the web UI
public/desks/<id>/desk.css      optional, its rules nested in #sso{ … } like office.css
public/desks/<id>/avatar.svg    optional, its picture (64×64, readable on dark and light; else the emoji)
public/desks/<id>/lang/en.json  its strings ("name", "role", …), and the same keys in de, it, fr, es
public/desks/<id>/places.json   the lang keys of its places for the search, and of their texts
```

On the agent side it registers its actions:

```php
desk('cleaner', [
    'start'   => fn () => cleanerScan(),          // once, when the agent starts
    'tick'    => fn () => null,                   // every ~150 ms, keep it cheap
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => cleanerScan()],
    ],
]);
```

Its state goes to `data/<id>.json` (`writeAtomic(deskFile('cleaner'), …)`); the office serves it at
`api.php?a=state&desk=cleaner` at once and, when it is older than `refresh_after`, asks for `cleaner.refresh` in the
background (`fresh=1` waits for it; the reception only reads what is kept). An extra state file
`data/<id>-<part>.json` (`?a=part&desk=<id>&part=<part>`) is kept fresh the same way when desk.json names it:
`"parts": {"<part>": {"refresh_after": 600, "action": "<action>"}}`. Errors are thrown as `new Problem('key', [...])`
and translated in the UI (`errors.<key>`). Request fields are read with `textField()`, `boolField()` and their
siblings in `agent/lib/util.php`, never cast. A desk can tell the Team Lead what it needs (`'checks' => fn () => [...]`,
built with `finding()` from `agent/lib/house.php`, texts `check.<id>` and `check.<id>_how`) and report numbers for
Prometheus (`'metrics' => …`, see below).

On the web side it registers with `Office.desk({ id, mount(root), poll(), reception() })` and uses `Office.api`,
`Office.dialog`, `Office.menu`, `Office.toast`, `Office.fmt` and `Office.scope('<id>')` for its strings. It reads its
state with `Office.loadState(id, { fresh }, took)` and asks `await Office.freshState(id)` before it acts on what its
page shows.

**For the search** it lists its places beside `Office.desk()` — `Office.places(id, [{ kind: 'section', key: 'history' },
{ kind: 'tile', key: 'tile.apps', route: '#/<id>/apps' }, { kind: 'help', key: 'help.x', text: 'help.x_text' }, …])` —
marks them on the page (`Office.place('<anchor>', node)`, or `{ place: '<anchor>' }` among `Office.sectionHead()`'s
extras) and lists the same keys in `places.json` (`{"keys": […], "texts": […]}`). A place drawn only in some states
says so (`shown: () => …`, `part: '<anchor>'`). What its state holds worth finding it names with one provider —
`Office.placesFrom(id, (state, part) => [{ text, sub, route, anchor }, …])`, called with every state
`Office.loadState()` brings (≤ 200 items, its own words, never a log line, a secret or a path the page doesn't show).
`php tests/run.php testSearchPlaces testSearchItems testSearchGuides` says what is missing. See the existing desks.

## Adding a language

Copy `public/lang/en.json` to `public/lang/<code>.json` and translate it (set `_meta.name` and `_meta.locale`), and do
the same for each desk's `lang/en.json`. Anything not translated falls back to English (en, de, it, fr and es must
always have the same keys). Plurals use `{"one": …, "other": …}` and whatever categories your language has; dates and
"3 hours ago" come from the browser. Unraid's own labels stay as tokens `⟦Settings⟧` (the English label, exactly as in
`en.json`): the office shows them as Unraid does in the language Unraid runs in, from `public/lang/unraid/<code>.json`
— taken from Unraid's language pack (github.com/unraid/lang-…); a language Unraid runs in without such a file shows
them in English. Details in CLAUDE.md, «Words».

## Development

No build step, no dependencies: plain PHP 8.4 (Unraid's own), vanilla JS, one CSS file. Work in a git clone and put it
into the installed plugin with `bash plugin/dev-sync.sh` on the host: it copies the working copy into the plugin in RAM —
live until the next reboot or plugin update; the page reads its files on every request, and the agent restarts itself
(after `php -l`) when one of its files changes ("Agent code changed" in `data/agent.log`). While a backup run is
active, `backup/` is left alone.

* **Agent log:** `data/agent.log` (also under *⋯ → Agent log*).
* **Running the agent by hand:** stop the service
  (`bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/agent.sh stop`), then
  `php /usr/local/emhttp/plugins/unraid-secretary-office/agent/agent.php run` — never two agents.
* **Tests:** on an Unraid host, `php tests/run.php` in a copy of the clone — must end with 0 failed. Parts `logic`,
  `strings`, `hardening`, or single tests by name (`php tests/run.php testStrings`). They use the clone's `data/` and
  `public/`, a RAM folder of their own and stand-ins for zfs, docker, ssh and Kopia — never the plugin's data folder,
  never the live agent. PHP syntax with `php -l`, shell with `bash -n`; there is no Node on the dev Mac, so JS syntax
  goes through `osascript -l JavaScript` (CLAUDE.md, «Checklist for a change»).
* **Reports to the makers** (*⋯ → Report a problem or a wish…*): the inbox is `OFFICE_FEEDBACK_URL` in
  `src/place.php` (shared by the web side and the agent; `''` hides the feature), a Cloudflare Worker in the private
  repository `dropnook/uso-support` (`feedback/`, its SETUP.md has the request's exact shape) that opens an issue in
  the private `dropnook/uso-inbox`. To test against a Worker of your own (e.g. `wrangler dev` on your machine) put
  `FEEDBACK_URL="http://<host>:<port>"` into `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
  (scheme, host and port only; read at every send, no restart) and remove the line afterwards. The dialog's other ways:
  `OFFICE_ISSUES_URL` and `OFFICE_FORUM_URL` (`''` = not shown) in `src/bootstrap.php`. Tests: `testReport` (scrubber,
  label map, a stand-in inbox with `php -S`), `testReportDialog` (node).
* **The package:** `bash plugin/build.sh <office-version>` builds `dist/unraid-secretary-office-<date>.txz` and
  `dist/unraid-secretary-office.plg`; it refuses when `OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION`
  (agent/agent.php) don't match the version. The plugin's own version is a date (Unraid compares with `strcmp`).
* **A release:** tag `v<version>` on `main` (`gh release create v<version> --target main --title "Version x.y"
  --notes-file <notes>`, notes in English, for users). Publishing it runs `.github/workflows/plugin.yml`, which builds
  and attaches the `.plg` and the `.txz`. The engine's own version lives in `backup.sh`, `setup.sh`,
  `lib/common.sh` and `backup/README.md`.
* **Releasing (maintainers):** `tools/release.sh <version> --dry`, then without `--dry` — bump, suite, tag, the Action, then
  the test servers and the main one, stopping at the first red step; the checklist around it is the release playbook
  (`release-playbook.md` in the maintainers' notes).

## Monitoring

Once a minute the agent writes the office's numbers as small text files to `/mnt/addons/UnraidSecretaryOffice/metrics`
(RAM): `uso_<desk>.prom` per hired desk that reports any, plus `uso_office.prom`, all together at most 16 KB (the
families with the most series go first, counted in `uso_metrics_dropped_families`). A Node Exporter's textfile
collector serves them along with its own. What is there: Mr. Backupsy's last run (when it ended, how it went,
downtime, duration, Kopia sources, package sizes), the last one that went well, a run going on, runs skipped because
the engine was busy; Ms. Snapshotini's snapshots per pool and her plans; the Team Lead's open points; Mr. Restori's
last drill; the Night Watchman's findings and the data flow (bytes per file service, written per ZFS share, the
router's lines per sender); Ms. Protocolli's last tour, EmbyCache and Ms. Dustdevil's storeroom — all named `uso_…`.

[`monitoring/grafana-dashboard.json`](../monitoring/grafana-dashboard.json) is a ready dashboard (Grafana:
*Dashboards → New → Import*, choose the Prometheus data source); the Consultant provisions it when he installs
Grafana. Where a Node Exporter or Prometheus runs, the Team Lead follows the chain: the numbers are fresh, the Node
Exporter reads their folder, Prometheus answers and fetches the Node Exporter. A desk adds its own families with
`'metrics' => fn (): array => [...]` (`agent/lib/metrics.php`): state files only, like `tick`; renaming a metric means
changing the dashboard too. Change Grafana's default admin/admin before it can be reached from outside.

## Notifications in detail

Everything goes through `officeNotify()` (agent/lib/house.php) as the event *Unraid Secretary Office*; `warning` for
something to fix, `alert` only when something is at risk now. The office's texts come in the language the office was
last used in (`data/office/lang.json`; until then Unraid's), Unraid's menus in them in Unraid's language; the engine
and the plugin's look at the agent write English.

* **Mr. Backupsy** (the engine): a run failed or ended with errors (alert) or warnings (warning), or went well (normal,
  can be switched off in his setup); a VM not resumed or started, a container not started, Nextcloud stuck in
  maintenance mode (alert); maintenance mode already on, space running out, Kopia incomplete, an aborted run repaired,
  a run skipped because the engine was busy (warning); a new folder that stays local until you decide (normal, once
  per folder); a run the array stop ended (normal) and what it left stopped brought back after the array start
  (normal); a partner office silent for the third night, refused for its quota or window (once a day), or a transfer
  that failed — part of the run's warning; a share left out 7 nights in a row because its pool slept (warning, once).
* **Ms. Snapshotini:** a schedule had problems (warning); a schedule's target is gone — once per target (warning).
* **Jack Emby:** a real EmbyCache or gather run failed or had problems (warning) — reports and dry runs stay quiet.
* **Mr. Restori:** a drill couldn't prove that everything comes back (warning), once per drill.
* **The Team Lead:** something new under *Still to do* that stayed for half an hour (warning), once — again only if it
  was solved and came back. He looks every 30 minutes, also with the office closed; a switch on his page turns it off.
  A partner office silent for 6 hours (warning), once per silence and again every 24 hours.
* **The Night Watchman:** every new entry of his watch book that matters (warning), per kind at most once an hour —
  also from the night shift. A switch on his page turns it off (the watch book keeps them).
* **The plugin:** the agent hasn't checked in for 10 minutes while the array runs (alert), and once it is back
  (normal).

## Partner offices inside

Two offices are **partners**: each keeps copies of the other's ZFS snapshots with a retention and quota of its own; the
sender can never delete them there. No master, no automatic failover. **Pairing** is at the Team Lead: *Add a
partner…* gives a block to paste at the other office, its *Accept a partner…* answers with a block and a **safety
code** — the same code on both pages means nobody changed a block on its way; no block carries a secret. The partner
then writes one line into `authorized_keys`: a key that can only reach the office's **door** (`restrict`, `from=` the
partner's address, a forced command — `agent/partner-door.php`): it may receive into its own datasets, list and send
back its own copies and say how the office is; it can't browse the server, run a command or delete the history kept of
its copies. The way is always SSH; the copies at the partner are plain (for a friend, Kopia is the encrypted way).
Units are datasets only: a share that is a dataset of its own, a VM with one, the backup place. Every 15 minutes each
office asks its partners how they are. *Change what <host> sends…* asks the partner through its door; the partner's
Team Lead answers *Keep it too* or *No*.

The nightly sending is the engine's phase `partner` (backup/README.md, «Partner offices»). **Bringing it back:** Mr.
Restori's tile *At <partner>* lists per unit the partner's snapshots; *Bring back…* pulls one through the door into a
new dataset beside the original, mounted read-only for his restores; an interrupted pull continues; *Remove what was
pulled…* destroys exactly that dataset. **When a server is gone**, a new one starts at the Team Lead with *Start from
a partner's copy…*; the partner answers with a **ticket** — a door line for 7 days that only lists and sends back the
gone server's copies — and Mr. Restori's *Onto a new server* pulls the backup place first, then the shares and VMs.
The Night Watchman knows the door's line and its refusals, Ms. Snapshotini keeps her hands off a partner's copies, Ms.
Dustdevil puts away what an ended partnership left, Ms. Protocolli reads the door's log. The security of the door:
[HARDENING.md](../HARDENING.md).

## The network

The Night Watchman can read what a router says about this server — UniFi gateways for now. The office never listens
itself: the router sends its log to Unraid's own syslog server (*Settings → Network Services → Syslog Server*, one file
per sender in a share of its own on a pool that never sleeps), and he reads those files each round — never while the
array is stopped, never a disk woken, nothing written there, no router password, never a word to the router. The
Consultant shows the setting and the router's side. Only what concerns this server becomes an entry in the group *The
network*: a new sender, a device never seen on the LAN, someone using the server's name or address with another MAC, a
router or VPN login not seen before, a change to the firewall, NAT or port forwarding, intrusion detections against this
server or from it, the router's log going silent. Another device's address or name appears only in an entry about that
device. The Team Lead checks the syslog server's setup, Ms. Protocolli reads the routers' files, Mr. Backupsy proposes
their share «not backed up». The lines are untrusted input: see HARDENING.md.
