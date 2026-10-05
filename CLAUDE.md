# Working on Unraid Secretary Office

Read README.md first — it explains the architecture and how a desk (secretary) is built.
This file holds the conventions and the checklist for changes.

## Two ways to run, one code

`src/place.php` decides by where the code lies (`officeIsPlugin()`):

* **Plugin** (the way users install it, since 1.14): code in RAM under
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
  by `event/stopping`). The data folder is `DATA_DIR` from
  `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`
  (default `<appdata>/UnraidSecretaryOffice/data`).
  PHP constants: `OFFICE_AS_PLUGIN` (web), `AS_PLUGIN` and `OFFICE_WEB` (agent).
* **Stack** (development, and old installs): the git clone in appdata run by
  `compose.yaml` — office container (php:apache) plus the privileged agent
  container that nsenter's into the host; data in `<clone>/data`.

Everything else (mailbox, desks, state files, engine interface) is the same.
Mode-specific code stays small and says why (`if (AS_PLUGIN)`), texts that
differ get a `_plugin` key or come from state (`schedule.via`).

## Conventions

* **English** in code, comments, commit messages, README. The UI is translated:
  every string lives in `public/lang/<code>.json` (office) or
  `public/desks/<id>/lang/<code>.json` (desk). `en`, `de` and `it` must always
  have the same keys — a new UI string needs all three; any other language may
  leave keys out (English fills in) but never has keys `en` lacks, and
  placeholders `{…}` and plurals match `en` (tests/run.php checks it). Unraid
  menu paths in `it` follow Unraid's Italian pack (github.com/unraid/lang-it_IT).
  Never hard-code UI text in JS or PHP.
* **No build step, no dependencies.** Plain PHP 8.4 (Unraid's own PHP runs both
  the page and the agent; the old Compose stack's office container has PHP 8.5
  — use nothing newer than 8.4), vanilla JS, one CSS file plus optional
  `desk.css`. The look follows levelnext Airdrop (tokens in `office.css :root`).
  The only "build" is the plugin package (`plugin/build.sh`).
* **The web side never touches the host** (even though Unraid's php-fpm runs as
  root). Everything that needs zfs, docker, /boot/config, /proc etc. goes
  through the agent (`Office.api.post('<desk>.<action>')` → `agent/desks/<desk>.php`).
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every
  action re-reads the current state and validates ids against it. Errors are
  `throw new Problem('key', [...])`; the UI translates `errors.<key>`
  (desk-specific keys in the desk's lang file).
* **Never wake sleeping disks on your own.** Check `sleepingDisks()` before
  reading array disks; offer an explicit "wake" option instead.
* **Nothing directly in /mnt** (Fix Common Problems): mounts go to
  `/mnt/addons/UnraidSecretaryOffice/…` (Unraid's place for add-on mounts, RAM),
  data the desks keep to the share `UnraidSecretaryOffice/<desk>/`.
* **Never block the array stop:** no sockets or open files in the pool; the agent
  keeps nothing open there.
* **Keep `tick` functions cheap** — they run every ~150 ms. Long work (like `du`)
  runs as a background process polled from `tick` (see whereabouts sizes).
* Desk lang keys `name` and `role` are the desk's title and subtitle — don't reuse them.
* **PIN:** every POST except `refresh` and the desk's `open_actions` (desk.json)
  needs an unlocked browser when a PIN is set. Only list actions there that
  read or measure. Who may write is decided in `src/auth.php` only.
* **Checks for the caretaker:** a desk that needs plugins, containers or
  settings registers `'checks'` returning `finding()`s (agent/lib/house.php):
  `required` only when the desk really can't work without it, otherwise
  `recommended`; `hint` for things to know. Texts: `check.<id>` states how it
  should be, `check.<id>_how` what the user does in Unraid to get there.
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
  As a plugin `agent-watch.cron` (written by `agent.sh start`, removed with the
  plugin) runs `job.sh watch` every 5 min: agent.json older than 70 s for 10 min
  with the array started → one alert, and a normal notification when it is back.
* **Jobs that must outlive the agent** (backup runs) go through the host's
  `atd` (`backupLaunch()` in agent/desks/backup.php), never as a child of the
  agent: `agent.sh stop` ends the agent's whole session (array stop, plugin
  update), and in the stack Docker kills the container's cgroup.
* **Schedules** (nightly backup, snapshot plans, EmbyCache, the gather) only through
  `officeJobSchedule()` / `officeJobSetSchedule()` (agent/lib/house.php): as a
  plugin a line in its cron file (`/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`,
  calling `scripts/job.sh <job>`, which runs only while the array is
  started), in the stack the User Scripts entry.
* **The backup engine** (`backup/`, bash, English since 2.13) runs on its own via the plugin's cron file (in the stack: User Scripts). Mr. Backupsy only uses
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
  the page, the cron file or User Scripts on schedule); a real run holds the
  other tool's lock, EmbyCache's real runs wait for the gather's first real
  run. The cleaner (`embycache_cleaner.py`) is left out on purpose: on a
  share whose primary is the pool it would take every new film for an orphan.
* **Packages (engine 2.18):** the backup place holds one package per app
  (compose project or single container) and per VM — `apps/<app>/`,
  `vms/<vm>/`, plus `server/` and `flash/`, each with a `manifest.json` —
  built in `.ub-stage-<run>` and swapped in before the snapshots, overwritten
  every run (a failed dump keeps the last good one). Their history lies in the
  snapshots of the backup place's share, which must be at least `snapshot`.
  Packages are never deleted (stale ones stay). The office reads the manifests
  as part of the engine's interface; restore commands use the credentials the
  manifest names (variable names, never values). Under `set -o pipefail` a
  `while … | jq … || fallback` loop must not end on `[[ … ]] && cmd` (use `if`).
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
| `snapshot` | Ms. Snapshotini / Frau Snapshotini | ZFS, btrfs and VM snapshots: create, delete with an estimate, rename, hold, unmount; schedules with retention (lib/snapshotplans.php: snapshots `auto-<plan>-YYYYMMDD-HHMM`, retention touches only those; `php agent.php job snapshot-plans` every 5 min — the plugin's cron line `job.sh snapshots`, in the stack the User Scripts entry `unraid-secretary-office_snapshots`) |
| `whereabouts` | Ms. Whereabouts / Frau Wasistwo | what is where and going on; "where things are" (config files, boot medium, VM files) with their backup protection; "If I were you …" — her advice (waAdvice() reads a few settings; the tips are built in desk.js, «I know, thanks» per browser with a signature, so a tip returns when the situation changes; nothing Fix Common Problems checks). Read only |
| `backup` | Mr. Backupsy / Herr Backupsi | runs the engine in `backup/`: status, overview tiles (Kopia, containers, databases, VMs, flash), history, protection (shares, then the VMs; rows unfold to their rules), restore help incl. "Onto a new server", setup assistant (`#/backup/setup`: 0 basics → 1 VMs → 2 apps → 3 other shares → 4 retention; a level per VM/app — nicht / lokal / lokal + Kopia, say "Kopia", never "offsite" — and setupDerive() turns it into share modes (locked in step 3), `docker|no_stop`, dumps and Kopia ignores for folders of apps/VMs that are only local; an app's further shares are never ticked unasked; "start anew" = `setup.sh --forget`). VMs (engine 2.16): `[vm "<name>"] prepare` = freeze (guest agent) / pause / shutdown (never forced off) / none for the seconds of the snapshot, released right after the snapshot holding their disks (`state/vms` for a killed run); `mode = off` and `retention` only for a VM in a dataset of its own (`VM_OWN_DS`); per-VM results in `status.json` `vms` |
| `emby` | Jack Emby (the intern) | EmbyCache (`embycache/`, from github.com/helmi1987/embycache-for-unraid, extended: back to the origin disk via embycache_origin.json, the emptied folder stays on the disk as a signpost, separate limits for started films and series, deliberately skipped folders = empty mapping) and the gather "Consolidate folders" (`gather/`: one disk per film folder, keeps empty folders whose content is on the pool). Settings via EmbyCache's own save_config() (trial file first); the gather's ini written by Jack; API key never leaves the server; schedules: jobs `embycache` and `gather` (job.sh) |
| `logs` | Ms. Protocolli / Frau Protokolli | reads logs out loud (fixed source list in agent/desks/logs.php, ids only, never paths; `backup:latest` = the engine's latest.log); tail/follow by file offset, docker logs and dmesg re-read; lines go into the page as text only. A picker with a search field instead of a select; favourites in `Office.store` (defaults until the user stars/unstars one, "out of the box" brings them back); nothing is read until a log is chosen. Read only |
| `cleanup` | Ms. Dustdevil / Frau Putzteufel | clears away what nobody uses: Docker templates, Compose stacks, appdata folders, stray my-*.xml elsewhere, what deleted VMs left behind (domains folders, NVRAM, TPM, snapshot lists, unused disk images; VMs without disks are only pointed out), switched-off User Scripts that lie around, Docker's leftovers. Never deletes right away: renames into `_UnraidSecretaryOffice-trash` on the same filesystem (ZFS datasets with `zfs rename` next to it), `manifest.json` per run, put back or empty; Docker leftovers can only be removed. Only rename, never copy; nothing while a backup runs. Missing pictures: containers without a picture on the Docker page/Dashboard (found like DockerClient::getIcon: template by Name+Repository, else the label; docker.json / question.png); logos from the CA feed, `CL_ICON_TABLE` and checked guesses — dashboard-icons URLs via jsDelivr, never bundled; set in the user template's `<Icon>` or the Compose Manager override (shape-checked, refused otherwise), old file to the storeroom (kind `icon`, put back only while unchanged), Unraid's cache filled; `cleanupIconLoopRisk()` feeds the caretaker's 7.3.2 check, stand-in question.png (RAM) |
| `advisor` | The Consultant / Der Berater | an external: whether the tools the office relies on are there (Fix Common Problems, Files Viewer, a Kopia container, Stream Viewer where Emby/Jellyfin/Plex runs; unbalanced optional, never suggested — it can get in the way of backups, EmbyCache and the gather), what they are good for, who needs them, how to install them by hand (ADVISOR_EXTERNALS in agent/desks/advisor.php, EXTERNALS in his desk.js). Never installs, never scans. Read only |
| `restore` | Mr. Restori / Herr Restori | **in training** (desk.json `"training": true`): under contract, apprentice with Mr. Backupsy, cannot be hired yet (office.hire refuses `in_training`, officeHired never counts him); the caretaker shows him with a tip-jar button. Will bring apps and VMs back from Mr. Backupsy's packages (stage B), the snapshots and Kopia — the restore help moves to him then |
| `caretaker` | The Team Lead / Der Teamchef (until 1.23: the caretaker / der Hauswart; the id stays `caretaker`) | leads the team (hires, fires, suggests whom to hire); collects every desk's `checks` and what the office needs; tells the user what is left to do; reports new red findings to Unraid's notifications (see Notifications) |

**User Scripts entries** (only in the stack) of the office are always named `unraid-secretary-office_<what>` (US_PREFIX); renamed ones are moved once by `userScriptsMigrate()` (lib/house.php: folder, schedule, cron line, User Scripts Enhanced category). Descriptions in English: "Unraid Secretary Office - …". As a plugin, `officeJobsFromUserScripts()` hands their schedule over to the plugin's cron file once and removes them.

Desks know each other only through shared libraries (`backupProtection()`,
`finding()`) and links (`#/<desk>`) — each one must work on its own.

**Staff:** a fresh office has only the caretaker (desk.json `"always": true`).
Every other desk declares in the agent whether it suits the server
(`'fit' => fn () => fit(bool, why, params)`, texts `fit.<why>` in its own lang
file, told by the caretaker). The caretaker suggests whom to hire; hiring
(`office.hire`, src/staff.php → data/office/staff.json, PIN-protected) shows
the desk in the tabs and at the reception, firing hides it again — data and
whatever it set up on the server stay (`fire_note` says what keeps running).
Unhired desks get no write actions (`not_hired`) and their checks don't count.
After hiring, the tip jar (core.js `tipJar`, link `OFFICE_TIP_URL`) says hello.

**Pictures:** every desk has its own drawing, `public/desks/<id>/avatar.svg`
(64×64, flat, thick shapes, outlined where a light part meets a light theme;
the reception's is `assets/reception.svg`). `Office.deskIcon(id)` /
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
(addressing the user with "tu", like the German "du"). Greetings are lang keys
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
  never to `body`. Links into Unraid's own pages: same tab inside Unraid
  (`Office.config.in_unraid`), a new tab from the stack's page. The office
  follows Unraid's language (`unraid_lang`; ⋯ → Language per browser).
  Buttons inside Unraid: its theme's frame, plain letters (no capitals).
  Colours are tokens (`--ink`, `--surface` …): on a page of
  their own the office's, inside Unraid (`.in-unraid`) mixed from Unraid's theme
  variables (`--text-color`, `--background-color`, `--button-background` …), so
  black, white, azure and gray all work — never a hard-coded colour. Buttons,
  tabs and section title bars look like Unraid's there. The Compose stack keeps
  the office's own look (render_page()).
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

* The stack's agent container has **no network** (`network_mode: none`): read
  addresses from Unraid's config (`/var/local/emhttp/network.ini`), not `ip`;
  `hostNet()` runs a command in the host's network (directly as a plugin).
* **Unraid's web stack:** php-fpm runs as root; nginx guards everything with
  `auth_request` (the login), also `/plugins/…`; `local_prepend.php` (prepended
  to every PHP run, also CLI) chdir's to `/usr/local/emhttp`, sets the time
  zone and ends every POST without the right `csrf_token` (field or header
  `X-CSRF-Token`, value in `/var/local/emhttp/var.ini`) with an empty 200.
* **Plugin manager:** `.plg` versions compared with `strcmp`, `min`/`max` with
  `version_compare`; a `Run` script failing (exit ≠ 0) aborts the install.
  Every installed plugin is installed again at each boot (before the array).
  `.page` and `.plg` icons (`*.png`) are looked up in `<plugin>/images/`.
* **emhttp waits for event scripts** (`<plugin>/event/<event>`, executable):
  a slow one holds up the array start or stop. `started` = end of array
  start, `stopping` = start of array stop.
* `update_cron` builds root's crontab from `dynamix/*.cron` and the `*.cron`
  of **installed** plugins only (`/var/log/plugins/<name>.plg`).
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
  `name<folder>`); the office's entries are `unraid-secretary-office_<what>`.
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
* Kopia's sources are named by the **container path** (`/backup-snapshots/<share>`),
  not the host path: changing only the Host Path keeps their history; a new
  repository (bucket) starts every source with a full upload — no dedup across
  repositories.
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
* `docker top <container> -eo …` needs `pid` in the list (`-eo pid,args`),
  otherwise Docker only answers "Couldn't find PID field".

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
   Emby detection, User Scripts schedules, the plugin's cron file — on copies; strings: `en`/`de`/`it`
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
   must pass, and both modes must still work. Never run the plugin's agent
   and the stack's at the same time.

## Layout

```
agent/agent.php          loop, mailbox, desk loading, self-restart
agent/lib/*.php          shared helpers (util: run, writeAtomic, readCfg, Problem;
                         mounts; backupscript; house: plugins, containers, finding)
agent/desks/<id>.php     one desk each: desk('<id>', [...])
src/*.php                web side: bootstrap, mailbox client, desk/lang discovery, auth (PIN), API, page
public/assets/core.js    Office: i18n, routing, reception, API, PIN, dialog, menu, toast, fmt,
                         deskHead, pageHelp, sectionHead, backupChip
public/desks/<id>/       desk.json, desk.js, lang/*.json (and desk.css, avatar.svg)
data/                    runtime only (state per desk, mailbox, agent log, office/auth.json) — not in git
backup/                  the backup engine: backup.sh, setup.sh, lib/common.sh (data in data/unraid-backup)
embycache/               Jack Emby's EmbyCache (Python; data in data/embycache)
gather/                  Jack Emby's media gather, consolidate_master.sh (bash; data in data/gather)
plugin/                  the Unraid plugin: .plg template, build.sh, dev-sync.sh, scripts/ (agent.sh
                         service, job.sh for the cron file), event/ (started, stopping), images/,
                         SecretaryOffice.page (the office in Unraid), SecretaryOfficeButton.page
                         (the header button), SecretaryOfficeDashboard.page (the Dashboard tile),
                         README.md (the short text Unraid's Plugins list shows); images/ has the
                         plugin icon as PNG with its SVG source (rendered on the Mac via NSImage)
.github/workflows/       plugin.yml: builds and attaches .plg/.txz when a release is published
compose.yaml             the stack: office + agent services; settings in .env
```
