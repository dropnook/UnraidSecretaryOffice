# Developing the Unraid Secretary Office

What the office does for its users is in the [README](../README.md) and, desk by desk, the [guide](GUIDE.md). This file is the overview for contributors: how
it works inside, where everything lies, how a desk or a language is added, how to test and release.
[CLAUDE.md](../CLAUDE.md) holds the rules in short — the map, the hard rules and the checklist for a change; «The rules
in detail» at the end of this file has them in full (each desk, the words, the UI conventions, the server facts).
The detailed build notes up to 1.60.0 (every helper and test by name) are in that file's earlier shape: `git show v1.60.0:CLAUDE.md`. The backup engine has its own document:
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
* **Long jobs outlive the agent.** Backup runs, restores, the drill, Jack Streamer's and Ms. Moverelli's runs go to the host's `atd`, never
  as children of the agent (`agent.sh stop` ends the agent's whole session). The restore drill plays dumps — also ones
  read back from Kopia into RAM — in throwaway containers without network and writes a certificate
  (`agent/desks/restore-drill.php`; what an interrupted one left, Ms. Dustdevil removes through the drill's sweeper).
* **Schedules** (the nightly backup, Ms. Snapshotini's plans, MediaCache, the gather, the Smart Mover) are lines in the plugin's cron file
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
| Ms. Dustdevil's storeroom | `.UnraidSecretaryOffice-trash` (hidden; up to issue #7 `_UnraidSecretaryOffice-trash`, still read and moved over once) on the same disk or pool as what was put away |
| What Mr. Restori replaced on the flash or in libvirt.img | `/boot/config/.UnraidSecretaryOffice-restore/<time>/`, `/etc/libvirt/.UnraidSecretaryOffice-restore/<time>/` (hidden; formerly `_UnraidSecretaryOffice-restore`) |

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
├── restore/, restore-drill/   Mr. Restori's journals, the drill's runs, certificates and its record of throwaways
├── mediacache/, gather/   Jack Streamer's tools: their settings, lists and status files (embycache.before-1.65.0: the old one, kept)
├── moverelli/             Ms. Moverelli's Smart Mover: smart_mover.ini, excludes/, its log and status, her runs
└── advisor/, caretaker/, cleanup/, snapshot/, watchman/   the desks' own folders
```

## Updates and migrations

Users update through Unraid's plugin manager and read no notes, so every version starts on any older version's data
folder. The agent keeps `data/office.json` and runs the migrations at its start, before any desk reads state:
`officeMigrateSteps()` in `agent/lib/migrate.php` is **the one place** for them — each step idempotent, logged, never
deleting (what it rewrites goes aside as `<name>.before-<version>`), tried again at the next start when it failed. New
keys are additive and read with a default; keys a reader doesn't know are kept; no rename of a key, file, path or name
without a step that rewrites it. The `.plg` refuses an update or a removal while a backup run or setup, a restore or
drill, one of Jack Streamer's runs or one of Ms. Moverelli's is active; it unpacks the new version beside the running one, checks it, then swaps
the folders (`testPlgGuard`, `testPlgInstall`, `testPlgRemove`). Details: CLAUDE.md «Updates».

## Adding a desk

A desk is a set of files; nothing in the core needs to change.

```
agent/desks/<id>.php            what it does on the host
public/desks/<id>/desk.json     {"order": 40, "icon": "🧹", "refresh_after": 300}
                                ("order": its place in a fresh office; the user may set another at the reception;
                                "with": "<id>" names a colleague it needs — «Hire together with …»)
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

Only a hired desk acts: for one that isn't (or is still in training) the agent answers `refresh` and refuses every
other action with `not_hired` (`agentDeskMayAct()`), the web side too.

On the web side it registers with `Office.desk({ id, mount(root), poll(), reception() })` and uses `Office.api`,
`Office.dialog`, `Office.menu`, `Office.toast`, `Office.fmt` and `Office.scope('<id>')` for its strings. It reads its
state with `Office.loadState(id, { fresh }, took)` and asks `await Office.freshState(id)` before it acts on what its
page shows. A desk that can clear away what it kept when it is let go adds `letGo(box)` to its registration: the
let-go dialog (`Office.fireDialog()`) hands it a box and calls its `before()` while the desk is still hired — Mr.
Backupsy's «Also clear away what he kept here» (`agent/desks/backup-letgo.php`).

**For the search** it lists its places beside `Office.desk()` — `Office.places(id, [{ kind: 'section', key: 'history' },
{ kind: 'tile', key: 'tile.apps', route: '#/<id>/apps' }, { kind: 'help', key: 'help.x', text: 'help.x_text' }, …])` —
marks them on the page (`Office.place('<anchor>', node)`, or `{ place: '<anchor>' }` among `Office.sectionHead()`'s
extras) and lists the same keys in `places.json` (`{"keys": […], "texts": […]}`). A place drawn only in some states
says so (`shown: () => …`, `part: '<anchor>'`). What its state holds worth finding it names with one provider —
`Office.placesFrom(id, (state, part) => [{ text, sub, route, anchor }, …])`, called with every state
`Office.loadState()` brings (≤ 200 items, its own words, never a log line, a secret or a path the page doesn't show).
`php tests/run.php testSearchPlaces testSearchItems testSearchGuides` says what is missing. See the existing desks.

## Tiles and lists

Every desk's tiles and lists behave the same way, and they are built with core.js's helpers — never with filter code
or tile markup of the desk's own. **A tile is a section** (tiles v2, 2026-10-11): the row of tiles is the page's table of
contents, each tile stands for one section under it and carries its summary (a number, a state). A click shows that
section alone under the row; a second click shows the whole page again, every section in the row's order. A tile can
also *filter* its section (a number tile that counts what it shows: «VMs» shows the protection list with only the VMs).
The active tile glows and says «Open» or «Filter on»; after the click its section is in view (a phone scrolls its head to
the top, sticky, with «◂ All»). `Office.sections()` does all of that — the desk only says which tiles and which sections
there are. A list gets one `Office.filterBar` under its section head — the filter words, maybe an «only …» switch and a
select — and the bar's note line is the one place that says what is filtered («The list shows only: [Empty ×] · 3 of 12
fit — Show all»). What the user chose (a section tile, a corner, a pool, a select) is kept per browser; filter words,
«only …» switches and filter tiles never are — a new visit starts with the whole list. A tile is also a place of the
search with a sub-route (`#/<desk>/<tile>`) that sets it exactly like a click. A desk with two pages (its setup) shows
them as two tabs in its head (`Office.deskHead(desk, {…, pages})`) — two at most: everything else is a section. «UI conventions
in full» below has the whole rule.

A minimal desk with a list of boxes and three tiles — «Boxes» shows the list, «Empty» shows it filtered to the empty
boxes, «Labels» shows a short section of its own (the shape of Mr. Backupsy's protection tiles):

```js
const ID = 'shelf';
const T = Office.scope(ID);
const { el } = Office;
let state = { boxes: [] };
let view = null;
let bar = null;                     // the list's bar: built once per visit, held across renders (it keeps its text and focus)

const boxes = () => state.boxes || [];
const sw = Office.sections({
  id: ID,                           // kept as shelf.tile, routes #/shelf/<tile>, places tile.<tile>
  grid: 'stats',
  default: '',                      // the start tile: '' = the whole page
  tiles: [
    { id: 'boxes', key: 'stat.boxes', section: 'list',
      tile: (a) => Office.stat({ label: T('stat.boxes'), value: String(boxes().length), ...a }) },
    { id: 'empty', key: 'stat.empty', section: 'list', filters: T('stat.empty'),        // a filter tile: never kept
      tile: (a) => Office.stat({ label: T('stat.empty'), value: String(boxes().filter((b) => !b.items).length), ...a }) },
    { id: 'labels', key: 'stat.labels', section: () => labels(),
      tile: (a) => Office.stat({ label: T('stat.labels'), value: String(boxes().filter((b) => b.label).length), ...a }) },
  ],
  sections: { list: () => list() }, // shared by «Boxes» and «Empty»: once on the whole page
});

function listBar() {
  bar = bar || Office.filterBar({ key: 'shelf.list', label: T('list.filter_label'),
    only: { text: T('list.only_labelled') }, onChange: () => sw.render() });
  return bar;
}

function list() {
  const s = el('section', 'section');
  const f = listBar().read();
  s.append(Office.sectionHead(T('list.title'), T('list.sub'), { place: 'list.title' }), bar.el);
  const emptyOnly = sw.active() === 'empty';
  const fits = boxes().filter((b) => (!emptyOnly || !b.items) && (!f.only || b.label)
    && Office.fits(f.words, [b.name, b.label ? T('chip.labelled') : '']));
  fits.forEach((b) => s.appendChild(el('div', 'row', b.name)));
  bar.done(fits.length, boxes().length, { ...sw.note('list'), onlyAvail: boxes().some((b) => b.label) });
  return s;
}

function labels() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('labels.title'), T('labels.sub'), { place: 'labels.title' }));
  boxes().filter((b) => b.label).forEach((b) => s.appendChild(el('div', 'row', `${b.name} · ${b.label}`)));
  return s;
}

function render() {
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: T('bubble') });
  view.replaceChildren(head, sw.el);  // hints (callouts) above the row; nothing of a section outside sw
  sw.render();
}

Office.desk({
  id: ID,
  mount(root, sub) {
    view = root;
    listBar().reset();                // a new visit: no words, no switch
    sw.mount(sub);                    // #/shelf/empty (the search) picks the tile like a click, the address back to #/shelf
    render();
    Office.loadState(ID, {}, (j) => { if (j.ok && j.state) state = j.state; if (view) render(); });
  },
  unmount() { view = null; },
});

Office.places(ID, [
  { kind: 'section', key: 'list.title', route: '#/shelf/boxes' },
  { kind: 'section', key: 'labels.title', route: '#/shelf/labels' },
  ...sw.places(),                     // a kind 'tile' place per tile with a key
]);
```

`places.json` beside it lists the same keys (each in all five `lang/*.json`):

```json
{ "keys": ["labels.title", "list.title", "stat.boxes", "stat.empty", "stat.labels"], "texts": [] }
```

A new tile or list, checked off:

* a tile is a section: it hangs on `Office.sections()` with the section it shows (`section`), a filter tile with
  `filters` (the words of the note line); nothing of a section is drawn outside the switcher, nothing is `hidden`;
* the builder spreads the helper's attributes (`tile: (a) => Office.stat({…, ...a})`) — the click, `aria-pressed`,
  `aria-controls`, `data-filters`, the mark; never a `div` with `role="button"`;
* the desk's filter state comes from `sw.active()` (or `onPick`), the bar's note line from `sw.note('<section>')`; the
  bar's `onChange` draws with `sw.render()`;
* no `scrollIntoView` and no jump of the desk's own — the switcher keeps the row in place and makes the one move (a jump
  a link asks for — to a row, a dialog's error line — carries `/* reveal */` on its line);
* the list's words matched with `Office.fits(f.words, …)` — no folding of the desk's own; `bar.done()` after the rows;
* every tile a place with a sub-route (`sw.places()`, the keys in all five languages and in `places.json`);
* `Office.store` keeps a choice (the section tile, a select's `<key>.pick`), never a filter: `mount()` empties the bar
  with `bar.reset()`;
* `bash tools/ui-clicks.sh --only <desk>` clicks every tile twice (a stub state in `tests/ui/states/` that gives each tile
  something to show) and checks the glow, the section alone and in the window, the whole page when off;
  `bash tools/ui-tiles.sh --only <desk>` makes the before/after pictures (1440 and 390) to look at;
  `php tests/run.php testTilesConvention` reads every desk for the rules above (a desk on the switcher goes into
  `SECTIONS_DESKS` in tests/run.php).
- A switch inside a section that changes a tile's line (hide, sort): call `sw.row()` after the section's redraw — the
  row alone, at once; `sw.render()` would draw the section a second time.


## Adding a language

Copy `public/lang/en.json` to `public/lang/<code>.json` and translate it (set `_meta.name` and `_meta.locale`), and do
the same for each desk's `lang/en.json`. Anything not translated falls back to English (en, de, it, fr and es must
always have the same keys). Plurals use `{"one": …, "other": …}` and whatever categories your language has; dates and
"3 hours ago" come from the browser. Unraid's own labels stay as tokens `⟦Settings⟧` (the English label, exactly as in
`en.json`): the office shows them as Unraid does in the language Unraid runs in, from `public/lang/unraid/<code>.json`
— taken from Unraid's language pack (github.com/unraid/lang-…); a language Unraid runs in without such a file shows
them in English. Details in «Words» below.

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
  never the live agent. PHP syntax with `php -l`, shell with `bash -n`; JS syntax goes through `osascript -l JavaScript`
  (CLAUDE.md, «Checklist for a change») — Node is needed only for the browser tools below.
* **Click test:** `bash tools/ui-clicks.sh` on the Mac (node, playwright-core and a Chrome; a headless browser with a
  throwaway profile, never one with your sign-ins) builds every desk page from the clone's files with stub states
  (`tests/ui/`) and clicks every section, row, group and tile that opens or closes — both themes, 1440 and 375 px; it
  must stay as clicked, no console error, no horizontal scrolling. ≈ 2 min, exit ≠ 0 on any fault; `--only <route>,…`.
* **Screenshots:** `bash tools/ui-shots.sh` — the same harness with invented family data (`tests/ui/demo/`, never a
  real server's names) inside Unraid's look; `--moments` makes the product page's moments (black, 1280 px, de/en) with
  a contact sheet, `--moments --themes black,white` each of them in Unraid's black and white theme. The README's
  and the guide's pictures in `docs/screenshots/` are the English moments (the team picture `team-*.webp` comes from the product page), `<name>-dark.png` and `<name>-light.png` in a `<picture>`
  that follows the reader's theme (the names without a theme are the dark ones, for the Community Applications listing).
* **Reports to the makers** (*⋯ → Report a problem or a wish…*): the inbox is `OFFICE_FEEDBACK_URL` in
  `src/place.php` (shared by the web side and the agent; `''` hides the feature), a Cloudflare Worker in the maintainer's private
  repository (`feedback/`, its SETUP.md has the request's exact shape) that opens an issue in
  a private inbox repository. To test against a Worker of your own (e.g. `wrangler dev` on your machine) put
  `FEEDBACK_URL="http://<host>:<port>"` into `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
  (scheme, host and port only; read at every send, no restart) and remove the line afterwards. The dialog's other ways:
  `OFFICE_ISSUES_URL` and `OFFICE_FORUM_URL` (`''` = not shown) in `src/bootstrap.php`. Tests: `testReport` (scrubber,
  label map, a stand-in inbox with `php -S`), `testReportDialog` (node). **Unraid's diagnostics** (a tick in the dialog,
  never pre-ticked or remembered): the preview runs `/usr/local/sbin/diagnostics <zip>` — exactly Tools → Diagnostics
  with «Anonymize» (never `-a`) — through atd into RAM (`office.report_diag` polled; a sleeping disk refuses without the
  user's «wake»; Unraid's copy in `/boot/logs` removed); after the report the raw zip goes to `<inbox>/api/diag` (headers
  X-Office, X-Rid) — the inbox keeps it at most 10 days behind an unguessable link in the private issue; an older or
  switched-off inbox answers 404/`off` and the report stands. Unraid's «anonymized» keeps
  the server's name, local IPs, disk serials and the logs — the dialog says so. Tests: `testReportDiagnostics`,
  `testReportDialogDiagnostics`.
* **The package:** `bash plugin/build.sh <office-version>` builds `dist/unraid-secretary-office-<date>.txz` and
  `dist/unraid-secretary-office.plg`; it refuses when `OFFICE_VERSION` (src/bootstrap.php) and `AGENT_VERSION`
  (agent/agent.php) don't match the version. The plugin's own version is a date (Unraid compares with `strcmp`).
* **A release** is a tag `v<version>` on `main` with English notes for users; publishing it runs
  `.github/workflows/plugin.yml`, which builds and attaches the `.plg` and the `.txz`. Maintainers make it with
  `tools/release.sh <version> --dry`, then without `--dry` — bump, build, the click test, the suite on a test server,
  the commit, `gh release create`, the Action, the release's assets, then the servers one after another (the partner
  test server as the canary, watched 5 min, then the test server, then the main one), stopping at the first red step
  (a red build, click test or suite, or Ctrl-C before the commit, puts the version lines back). `--no-main` stops
  before the main server, `--servers-only` does only the server part of a release that is out already. If the
  release's Action run fails, run it by hand for the tag — `gh workflow run plugin.yml -f tag=v<version>` — and go on
  with `--servers-only` once it is green. The checklist around it is the release playbook (`release-playbook.md` in the
  maintainers' notes). The engine's own version lives in
  `backup.sh`, `setup.sh`, `lib/common.sh` and `backup/README.md`.

## Monitoring

Once a minute the agent writes the office's numbers as small text files to `/mnt/addons/UnraidSecretaryOffice/metrics`
(RAM): `uso_<desk>.prom` per hired desk that reports any, plus `uso_office.prom`, all together at most 16 KB (the
families with the most series go first, counted in `uso_metrics_dropped_families`). A Node Exporter's textfile
collector serves them along with its own. What is there: Mr. Backupsy's last run (when it ended, how it went,
downtime, duration, Kopia sources, package sizes), the last one that went well, a run going on, runs skipped because
the engine was busy; Ms. Snapshotini's snapshots per pool and her plans; the Team Lead's open points; Mr. Restori's
last drill; the Night Watchman's findings and the data flow (bytes per file service, written per ZFS share, the
router's lines per sender); Ms. Protocolli's last tour, MediaCache and Ms. Dustdevil's storeroom — all named `uso_…`.

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
  (normal); a partner office that got nothing or not all of a run — unreachable, its array stopped, a refusal, a
  failed transfer — said in that same run, no nights of grace (part of the run's warning, and in his bubble); a share left out 7 nights in a row because its pool slept (warning, once).
* **Ms. Snapshotini:** a schedule had problems (warning); a schedule's target is gone — once per target (warning).
* **Jack Streamer:** a real MediaCache or gather run failed or had problems (warning) — reports and dry runs stay quiet.
* **Ms. Moverelli:** a real Smart Mover run failed or had problems (warning); one stopped because Unraid's mover started
  (normal) — lists, dry runs and a stop asked on her page stay quiet.
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
Units are datasets only: a share that is a dataset of its own, a VM with one, the backup place. **The Team Lead only
pairs** (since 1.60): the receiver's consent is the pairing — its rules (pool, quota, retention, wake) at *Accept a
partner…*, its door takes any unit of the pair up to the quota, at any time; what a server sends is the level
*local + partner* per share and VM in Mr. Backupsy's setup (settings.ini `partner = <id>`). A unit the receiver never
saw before is chipped «new» on its card; one nothing came of for 30 days «no longer comes» (Ms. Dustdevil offers to put
it away). Every 15 minutes each office asks its partners how they are; a partner office up to 1.59 keeps only what its
Team Lead agreed to, so the mutual watch offers it the units through its door until it is updated.

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

The Night Watchman can read what a router says about this server — UniFi gateways and MikroTik routers (RouterOS 7). The office never listens
itself: the router sends its log to Unraid's own syslog server (*Settings → Network Services → Syslog Server*, one file
per sender in a share of its own on a pool that never sleeps), and he reads those files each round — never while the
array is stopped, never a disk woken, nothing written there, no router password, never a word to the router. The
Consultant shows the setting and the router's side. Only what concerns this server becomes an entry in the group *The
network*: a new sender, a device never seen on the LAN, someone using the server's name or address with another MAC, a
router or VPN login not seen before, a change to the firewall, NAT or port forwarding, intrusion detections against this
server or from it, the router's log going silent — and from a MikroTik bursts of failed router logins, a port losing
its link (told when it flaps), the internet away (told when long) and a restart without proper shutdown (with the
server's own start: a power loss). Another device's address or name appears only in an entry about that device. The Team Lead checks the syslog server's setup, Ms. Protocolli reads the routers' files, Mr. Backupsy proposes
their share «not backed up». The lines are untrusted input: see HARDENING.md.

## The rules in detail

[CLAUDE.md](../CLAUDE.md) keeps the rules a change must know in short; here they are in full — each desk's rules, the words
table, the UI conventions, the engine's guarantees, the server facts and how several chats work together. Both hold;
where one seems to say less, the fuller one here is meant.

### The desks

Desks know each other only through shared libraries (`backupProtection()`, `finding()`, `partnerlook.php`, `nightrun.php`,
`poollevel.php`) and links (`#/<desk>`) — each must work on its own. Entry tests: `testSnapshot*`, `testPlanGone`,
`testSleepingPools`, `testBackup*`, `testSetup*`, `testJack*`, `testMoverelli*`, `testNight*`, `testPoolLevel`, `testHireNeeds`,
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
* **`jack` — Jack Streamer (the intern).** MediaCache (Emby, Jellyfin, Plex — several servers at once; 1.65.0, before EmbyCache:
  step `mediacache` moved data/embycache, the job id stays `embycache`) + «Consolidate folders». Settings via MediaCache's own `save_config()`;
  **the API key never leaves the server. Rsync both ways, always** (`JACK_TOOLS_RSYNC`). **He works only under Ms. Moverelli**
  (desk.json `"needs"`: hired only with/after her, she leaves only after him; real runs only her way, `jackMoverRule()`).
  Unraid's mover schedule is switched off only through Unraid's form via `emcmd` (tests: `OFFICE_EMCMD_BIN`); **he never writes
  into Mover Tuning.** A mover at work stops his run after the current file; never side by side with her. **Never a real gather
  while someone watches Emby** (`jackWatching()`; dry runs never ask). Let go: both his schedules off, Unraid's mover stays off.
  Import: the user types the folder, he never searches, preview without API key, copies aside first. «Last run» from his runs,
  not status.json. **His setup: a normal «Set up» tab with tiles, GB budget only** (the total = the hard cap, ≥ 20 GB a person,
  counts are defaults inside it; the rule in plain sentences); without a budget «not set up yet» (`jackConfigured()`, no
  migration) and not in the night run.
* **`moverelli` — Ms. Moverelli.** Smart Mover beside Unraid's mover (7.3.2+; her ini, every value checked). **Unraid's own
  mover goes off with her setup:** refused unless the page's consent `mover_off: true` comes along; the old schedule noted once,
  **her let-go puts it back** through the same form (only `MOVERELLI_UNRAID_CRON` shapes). **Never a hand-written cron line.**
  Real runs only while no other mover has a schedule of its own (other scripts' mover calls named, never changed); never beside
  Unraid's mover or Jack Streamer's runs. While he is hired her ini keeps MediaCache's list out for his shares; a named list not
  yet created refuses a real run (scheduled refusals: one counted line, never notified); her setup proposes his list for all
  shares in the form only. Import: an unreadable protecting list blocks it, never silently disappears. **The night run**
  (agent/lib/nightrun.php): he and she = ONE run on ONE schedule (her cron line → `nightRun()`): check → reckon (`nightPlan()`)
  → free (his watched, then her oldest) → recheck (≤ 3 rounds) → fill (him last, only up to X) → tell; one lock `night.lock`;
  «Stop after this file» stops the night; both pages show `Office.nightCard()` — its highlight follows the work (night.json
  `phase` + `who`: measure · his give-back · measure again · her part · his fetch, `Office.nightSteps()`), his panel real by the
  night's word (`jackNightProgress()`); night.json `picture` = the live graphic's numbers; his refusal never stops her part (reason shown). **The pool** (agent/lib/poollevel.php): **one limit X**, nothing
  else. He gives back only what was watched (`MEDIACACHE_WATCHED_ONLY`, never by rank); **his GB come first**: she moves the
  other caching shares' files oldest first across the pool (age = the younger of birth/ctime and mtime, `age_stat=newest`; never
  an excluded file, his list never) until his fetch ends at X (`--fill-until` = X − fetch), the age rule always; pool-only
  shares and whatever never moves are never pushed (they and what he keeps reach X → he fetches nothing new). Too big a budget
  or unwatched over X: told.
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
  on them» required; Unraid's diagnostics only when ticked, ≤ 10 MB, sent after the report, deleted here at once. `testReport*`.
* **Characters:** Frau Snapshotini (Italian); Herr Backupsi (nervous, checks three times — never «scatterbrained»); Jack
  Streamer (the intern, only under Frau Moverelli's eye, says so with a grin); Frau Protokolli (reads everything, understands
  nothing); Frau Putzteufel (dust everywhere, never throws away at once, knows every corner); der Teamchef (plain, friendly);
  Herr Restori (calm, white gloves, «Piano, piano»); der Berater (consultant speak); Frau Moverelli (removals lady, «Jack!
  Finger weg vom Pool»). Names per language = each desk's `name` key (Frau Moverelli: «tu» in it/fr, «tú» in es). **Always «Jack
  Streamer», never just «Jack»** (de genitive «Jack Streamers Liste»); what he prepares is never just «films» — «what Jack
  Streamer laid out (from his libraries)» / «was Jack Streamer bereitgelegt hat (aus seinen Bibliotheken)». Chatty bubbles may
  carry character, **warnings and errors stay plain**. Reception button via lang key `visit`, else «Visit {name}». Titles small
  in it/es running text, capitalised in fr.
* **Jack Streamer's id** is `jack` (desks/jack*.php, public/desks/jack/, `#/jack`, `JACK_*`; `#/emby…` leads there, step
  `jack-id`); MediaCache, gather, Smart Mover and `MEDIACACHE_*` keep their names (the job id `embycache` too). **The desks speak for themselves:** their
  tool names only in the thanks, the import of an earlier install and log titles — never the actor in a UI text.

### Words

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
| Jack Streamer's run · Ms. Snapshotini's plan · its run | run · real run · dry run · schedule · run | der Lauf · echter Lauf · Probelauf · der Zeitplan · der Lauf | l'esecuzione · esecuzione vera · prova a secco · la pianificazione · l'esecuzione | l'exécution · exécution réelle · essai à blanc · la planification · l'exécution | la ejecución · ejecución real · simulación · la programación · la ejecución |
| a ZFS hold | Hold · held · Release hold | Schützen · geschützt · Schutz aufheben | Proteggi · protetto · Togli la protezione | Protéger · protégé · Lever la protection | Proteger · protegida · Quitar la protección |
| thank-you levels | a coffee for the team · a round for everyone · cake for the whole office · a pay rise and the donations | ein Kaffee fürs Team · eine Runde für alle · Kuchen fürs ganze Büro (deliberately) · Lohnerhöhung und Spenden | un caffè per il team · un giro per tutti · una torta per tutta la segreteria · un aumento e le donazioni | un café pour l'équipe · une tournée pour tout le monde · du gâteau pour tout le secrétariat · une augmentation et les dons | un café para el equipo · una ronda para todos · pastel para toda la secretaría · un aumento de sueldo y las donaciones |
| share · disk · /boot · Docker template · a log · dataset · Emby's key | share · disk · the flash (a physical stick stays a stick) · template · log · dataset · API key | der Share · die Disk (not Platte) · der Flash · das Template (not Vorlage) · das Protokoll (not Log) · das Dataset · der API-Schlüssel | la condivisione · il disco · l'unità flash · il template · il log (registro = watch book) · il dataset · la chiave API | le partage · le disque · la flash · le modèle · le journal · le dataset · la clé API | el recurso compartido · el disco · el flash · la plantilla · el registro · el dataset · la clave API |
| Unraid's bell · user → makers · menu item | notifications · report · Report a problem or a wish… | die Benachrichtigungen (one sent: eine Meldung) · die Rückmeldung · Problem oder Wunsch mitteilen… | le notifiche · la segnalazione · Segnala un problema o un desiderio… | les notifications · le signalement · Signaler un problème ou un souhait… | las notificaciones · el comentario · Contar un problema o un deseo… |
| partner · pair · its key line · code | partner office · pair · the door · safety code | das Partner-Sekretariat · verbinden · die Tür · der Sicherheitscode | la segreteria partner · abbinare · la porta · il codice di sicurezza | le secrétariat partenaire · appairer · la porte · le code de sécurité | la secretaría asociada (el socio) · emparejar · la puerta · el código de seguridad |
| kept at partner · pull back · new server's start · temp door | At <partner> · Bring back · Start from a partner's copy… · ticket | Beim Partner <name> · Vom Partner zurückholen · Von der Kopie eines Partners starten… · das Ticket | Presso <partner> · Recupera · Parti dalla copia di un partner… · il ticket | Chez <partenaire> · Rapatrier · Partir de la copie d'un partenaire… · le ticket | En <socio> · Traer · Empezar desde la copia de un socio… · el ticket |

* **Kopia** is the tool (container, repository, KopiaUI, policies, its errors), never the place — the place is «offsite». First
  time on a page: «offsite (encrypted with Kopia: S3, Backblaze B2, SFTP, WebDAV, a NAS …)»; partners are offsite too but say
  «to the partner». Internal keys (`mode = kopia`) stay.
* **Nights:** a backup can run any time, so «a night» never stands for one run or its snapshot/package. «Night» stays only
  in a name: the Night Watchman and his night shift; code names (`{nights}`, `night.json`, `--night`) and engine lines keep
  theirs. No other run happens «at night» (owner, 2026-10-16): a run is **on schedule** (with its time when the text knows
  it) or **started here**; Ms. Moverelli's and Jack Streamer's run is **the joint run** (de «der gemeinsame Lauf»), «tonight»
  is «the next run», «Every day at» (not «Every night at»); the drill says its window (00:00–07:00) and its day
  (`testNoNightWords`). The role says «the backup», the drill never «nightly». German «Lauf» only for Jack Streamer's and plan
  runs; Mr. Restori's drill is «die Übung», never «Probelauf».
* **Unraid's own words:** every label of Unraid's UI a text names (menu tabs, tiles, buttons, fields, values, paths like
  `⟦Settings⟧ → ⟦User Utilities⟧ → Fix Common Problems`, «the ⟦Main⟧ page») is a token `⟦English label⟧` exactly as English
  Unraid shows it, **in all five languages**; it reads in Unraid's language (`unraid_lang`), resolved by `t()`,
  `officeNotifyText()`, `officeDashT()` via src/words.php. A placeholder never goes inside a token. Dictionaries
  `public/lang/unraid/<code>.json` from Unraid's language packs (the page's file where Unraid shows it; `_meta` names pack and
  commit; pack quirks kept; an untranslated label maps to itself). **Not tokens:** other software and untranslated plugins (Fix
  Common Problems, User Scripts, Compose Manager, Emby, Kopia, Grafana), the office's own words, values Unraid never translates,
  prose about the Docker page or the Dashboard. **Adding a label:** `⟦label⟧` in all five languages (words around it that still
  read when it stays English), then in each dictionary; `testUnraidWords` (none missing, none unused).

### UI conventions in full

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

### The backup engine: what holds

* **What holds** (details backup/README.md): one run at a time behind `state/lock` (`backupLockHolder()`; the office only looks
  via `flockHeld()`, never takes it; busy → `skipped.json`, exit 75). **One real backup a minute** (names carry the minute,
  `uso-backup-YYYYMMDD-HHMM`; old `unraidbackup-` recognised, exact — `backupIsEngineSnap()`); tours/dry runs/setups log by the
  second. **Backups never in appdata** (`dumps_share` = the office's share, else refuse). **Packages** (`apps/`, `vms/`,
  `server/`, `flash/`, each with `manifest.json`) never deleted; also inside `domains/<vm>/.uso-backup/` and the app's folders
  (`testBackupInside`). **Kopia** per app/VM; never a source under another's policy path, never `@` in a source path. **New
  things stay local until the user decides** (`preset_new`; only Apply makes a default so). **VMs**: `prepare =
  shutdown|freeze|pause|none`, never forced off, a late VM paused (warning). **Array stop ends a run at once** (`aborted`, exit
  3, shown as stopped, never failed); `--recover` at the next start. **Sleeping pools** `wake|skip` (skip = left out and said,
  never failed; backup place and data folder pools always woken). **The retention never thins a partner's copies or the
  storeroom** (`ub_prune_own`). **Partner phase:** one dataset per unit, `partner_ssh_cmd()` the one place for ssh options, never `zfs send -s`; what a partner didn't get is a warning in that run (`testPartnerAtOnce`).
  Fixtures run on the tests' clock (checklist 3).


### Server facts in full

* **Which disk a file is on:** `getfattr -n system.LOCATION` (`…LOCATIONS`: all) on a /mnt/user path — no look at other disks.
  `shareSplitLevel` in `/boot/config/shares/<share>.cfg` (top level only: files follow an existing, even empty folder —
  Jack Streamer keeps them as signposts).
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

### Several chats at once (workers and coordinator)

One chat is the **coordinator**, the others **workers** — one per task (not per desk), at most two or three, closed when done.
* **Workers:** a git worktree of their own on a local disk, branch `task/<short-name>` (never the server share's copy); they
  commit and push their branch, run the checks they can, report what they did and what is untested — **never merge into
  `main`, run `dev-sync.sh`, release, start backup runs or apply settings** on nostromo. Fresh workers after a few rounds.
* **The coordinator** owns `main`, the working copy and the server: reviews, merges, full checklist, `dev-sync.sh`, releases —
  only it deploys, and **after every deploy clicks the real flows on nostromo itself** (first setup, «von vorn», every tab to
  the bottom also at ~1280×720, cancel/back, dialogs on dialogs) before telling the owner it is done.
* **Shared files** (`core.js`, `office.css`, `src/`, `agent/lib/`, `backup/`, CLAUDE.md): one chat at a time, as the
  coordinator says; a desk's own folder belongs to the task working on it.
* **Test servers** Tower, USOPartner, USOTest3VM are throw-away (anything allowed); only nostromo is real. USOPartner stays on
  the last release (the canary) — only `tools/release.sh` updates it, never dev-sync.
* Tests that write (`setup.sh --apply`, runs) touch the real Kopia repository and containers even with another `UB_DATA`: only
  with Kopia off in the test decisions, and only by the coordinator.

### Tests on the clock

The checklist for a change, steps 3 and 8, in full:

3. **Suite** on the host: `php tests/run.php` — must end with 0 failed. `php tests/run.php <part|test> …` runs only those
   (unknown name: exit 2). Tests use their own RAM folder (a child with its own env gets `'OFFICE_RUN_DIR' => TESTS_RUN_DIR`) in
   a mount namespace — never the live agent's (`testLiveRunUntouched`). **Engine fixtures run on the tests' clock**
   (`testsClock($bin, $clock)`, starting 03:00 tomorrow): `testsClockRun()` before every run, `testsClockNight()` for a night
   later, `testsClockNow()` for the engine's «now» (never `time()`); a new `date` form → add it to `testsClock()`; end with
   `testsClockUnsupported($clock) === ''`, output through `testsClockRan()`. **Never remove logs between runs to dodge the
   minute.**
8. **Page changed?** `bash tools/ui-clicks.sh` on the Mac (headless Chrome with a throwaway profile — never a browser with
   sign-ins; stub states in `tests/ui/states/`, a new state shape goes there): every control clicked twice, both themes, 1440
   and 375 px; exit ≠ 0 on any failure; `--only <route>,…`, `-v`. `tools/release.sh` runs it before the suite. **Screenshots**
   for product page/forum: `bash tools/ui-shots.sh` (demo data in `tests/ui/demo/` — invented family data only; `--moments`,
   `--themes black,white`); prepare steps stay language-neutral (`Office.t(…)`, not German selectors).
