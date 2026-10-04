<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/title-dark.svg">
    <img src=".github/title-light.svg" alt="Unraid Secretary Office" height="44">
  </picture>
</h1>

A small office for your Unraid server. Everyone in it looks after one part of the
server, tells you what they noticed and — where it makes sense — lets you act on it.

| Desk | What they do |
|---|---|
| 📸 **Ms. Snapshotini** | Every snapshot on the server: ZFS (all pools), btrfs (array disks and pools) and VM snapshots (Unraid's own list and libvirt). Create, delete with an estimate of the space freed, rename, hold/release, unmount. **Schedules**: snapshots at fixed times with a simple retention (keep the last N, optionally nothing older than X days) — only her own snapshots are ever cleared away; they run on the server even when the office isn't open. Knows Docker's image layers and leaves them alone, never wakes sleeping disks on her own, and detects a running backup so its mounted snapshots stay untouched. |
| 🧭 **Ms. Whereabouts** | Knows where everything is and what is going on: shares and where they live, the first folder level of pool shares (and which folders nobody uses), containers (template or compose), compose projects, VMs, users and their share access, SMB/NFS and active sessions, user scripts and cron jobs (with dead paths), places that look like backups, disks with temperature, SMART findings and fill level, Unraid's unread notifications, the license, plugins. **Where things are**: tiles for the places that matter when something breaks or moves — Unraid's configuration on the flash, Docker templates (XML), compose stacks with their .env, the VM configuration in libvirt.img (XML, NVRAM, TPM state), user scripts — each path with how Mr. Backupsy protects it. VMs with firmware, TPM, NVRAM, disks and their snapshot chain. Read only. |
| 💾 **Mr. Backupsy** | Runs the office's backup engine ([backup/](backup/README.md): consistent ZFS/btrfs snapshots, database dumps, Nextcloud maintenance mode, Kopia offsite): what a run is doing right now and when it will be done (estimated from earlier runs), how the last nights went, which share is protected how (Kopia offsite, local snapshot, not at all) and when it was last copied offsite, what changed on the server since the setup, whether a nightly run is scheduled, and how to get things back (snapshot paths, Kopia, database dumps with ready-made restore commands). Starts a full run, a run without Kopia, a dry run or a check, and stops a run cleanly. **Set up in the browser** (*Set up…*): he reads the server and shows his proposals with reasons — Kopia offsite, every share, which containers stop for the snapshots, database dumps and Nextcloud maintenance mode, retention, Kopia policies — you change what you like and apply; the same engine as `setup.sh` in a terminal checks and writes it. |
| 🍿 **Jack Emby** | The intern ("check Emby"): fetches coffee, entertains everyone — and looks after [EmbyCache](https://github.com/helmi1987/embycache-for-unraid), which keeps what people are about to watch on the fast pool so the array disks can sleep, and brings it back to the very disk it came from once it's watched. Before that, the [media gather](https://github.com/helmi1987/media-disk-gather-for-unraid) ("Consolidate folders") brings every film folder together on one disk. Both ship with the office (`embycache/`, `gather/`); Jack sets them up (Emby API key, libraries, people, pool — films and series each their own way), shows whether your shares suit, runs them and schedules them. |
| 📝 **Ms. Protocolli** | Reads every log out loud — and sadly understands none of it. The office's own logs (agent, Mr. Backupsy's runs, Jack's EmbyCache, the office containers), Unraid's (syslog, kernel, VMs, web UI, Samba, parity checks …), what every User Script printed last, and every container's docker logs. Last 100 to 10000 lines, live like `tail -f`, filter by text or errors and warnings only, copy or download. Read only, from a fixed list; nothing wakes a disk. |
| 🧹 **Ms. Dustdevil** | Clears away what nobody uses any more: Docker templates without a container, Compose stacks without containers, appdata folders nothing names (container mounts, templates, stacks, compose files, VMs, any file on the flash), what deleted VMs left behind (folders in `domains`, NVRAM files, TPM states, snapshot lists, disk images in `isos` no VM uses) switched-off User Scripts that lie around (pointing to paths that are gone, or unused since the reboot), stray `my-*.xml` outside Unraid's folder (take over or put away; backups are left out), VMs whose disks are gone (pointed out, removed in Unraid) and Docker's leftovers (dangling and unused images, volumes without a container, the build cache). A filter and a CSV export for every room. Nothing is deleted right away: it is renamed into `_UnraidSecretaryOffice-trash` on the same disk (ZFS datasets with `zfs rename`, snapshots included) and can be put back until you empty it. Measures in the background, never wakes a sleeping disk, changes nothing while a backup runs. |
| 💼 **The Consultant** | An external — he knows the tools the office relies on but doesn't make itself: [Fix Common Problems](https://github.com/unraid/fix.common.problems) (checks the server for common mistakes), [Files Viewer](https://github.com/Lazaros-Chalkidis/unraid-filesviewer) (files in the browser — the office has no file browser of its own on purpose), [Kopia](https://kopia.io/) (Mr. Backupsy hands it the offsite copies) and, where Emby, Jellyfin or Plex runs, [Stream Viewer](https://github.com/Lazaros-Chalkidis/unraid-streamviewer) (who watches what) — and, through gritted teeth, [unbalanced](https://github.com/jbrodriguez/unbalance) (moves files between array disks; can get in the way of backups and EmbyCache, so never suggested). He tells whether they are there, what they are good for, who in the office needs them, and how to install them by hand. Read only. |
| 🧰 **The Caretaker** | Looks after the house. Every desk tells him what it needs from the server; he adds what the office as a whole benefits from and lists what is missing — *still to do* (only you can do it, in Unraid), *recommended* (e.g. Fix Common Problems, Files Viewer, notifications by mail or push) and *good to know* (other backup tools, so nothing runs twice by accident) — each with a link into Unraid's web UI. Especially helpful on a fresh server. Read only. |

A fresh office has only the caretaker. He asks around who would suit your
server (no Emby, no Jack Emby; no ZFS or btrfs, no snapshots) and you hire whom
you need — or let them go again later; their data and settings stay.

More desks can join: each one is a module (see below).

The office speaks English and German and follows Unraid's language (English
where it doesn't speak Unraid's); *⋯ → Language* picks another one for your
browser. Adding a language means adding JSON files.

## How it works

```
 browser ──▶ Unraid's web server (nginx + PHP, behind the Unraid login)
               │  the office's page: /plugins/unraid-secretary-office/
               │  data/mailbox/<id>.request   ▲  <id>.response
               ▼                             │
             agent (a service of the plugin, on Unraid's own PHP)
               └─ zfs, btrfs, docker, virsh, /boot/config …
```

* The office is an Unraid **plugin**: a page in Unraid's menu bar
  (*Sekretariat*, between Apps and Tools — under *⋯ → Entry in Unraid* you
  can call it *Office*, *USO* or a name of your own, or have an icon under
  *Settings → User Utilities* instead, as before), in Unraid's look and colour theme,
  served by Unraid behind its login.
* The page only shows things. The **agent**, a small service of the plugin,
  does the work on the host. It only accepts the actions the desks define and
  checks every request against a fresh look at the system. Commands run
  without a shell.
* Page and agent talk through a mailbox folder. Deliberately no unix socket:
  a bound socket in the pool would keep it busy and Unraid could not stop the
  array.
* The agent starts with the array and stops within seconds when the array
  stops — it never holds it up. While the array is stopped, the page says so.
* Long jobs that must outlive the agent (a backup run takes hours) are handed
  to the host's `atd`.
* Sleeping disks are not woken up unless you ask (e.g. "Wake & scan", measuring
  the size of an array share).

Where things are:

| What | Where |
|---|---|
| The code (in RAM, unpacked at every boot) | `/usr/local/emhttp/plugins/unraid-secretary-office/` |
| The package and the one setting (`DATA_DIR`) | `/boot/config/plugins/unraid-secretary-office/` |
| The schedules (nightly backup, snapshot plans, EmbyCache, consolidating) | `…/unraid-secretary-office.cron` next to it, set in the office |
| State, logs, the backup engine's settings | `appdata/UnraidSecretaryOffice/data/` (comes with the array) |
| Database dumps, archives | the share `UnraidSecretaryOffice`, one folder per desk |
| Snapshot mounts for Kopia | `/mnt/addons/UnraidSecretaryOffice/` |

## Installation

Requirements: **Unraid 7.3 or newer** (built and tested on 7.3.2). **Unraid 8 is not
supported** for now — the plugin refuses to install there; support follows
once it is released and tested.

1. In *Apps* (Community Applications) search for **Secretary Office** and
   install it. Or by hand: *Plugins → Install Plugin*, paste
   ```
   https://github.com/vipermark2/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
   ```
2. Open it: *Sekretariat* in Unraid's menu bar (between Apps and Tools).
   The Caretaker welcomes you and suggests whom to hire. For backups:
   Mr. Backupsy → *Set up…*, then *Schedule…*. The Caretaker lists what is
   still missing.

The green dot next to *⋯* means the agent checked in within the last 70 seconds.

**Updating:** like any plugin, under *Plugins* (Unraid looks for updates; the
Caretaker also says when a new version is out). Your data stays. While a
backup runs the update refuses — try again when it is done.

**Removing:** *Plugins → Remove*. The code and the schedules go. Your data in
appdata, the share `UnraidSecretaryOffice`, the setting on the flash and
whatever the desks set up on the server (snapshots, Ms. Dustdevil's
storeroom) stay — delete them yourself if you don't want them any more.

### Moving over from the Compose stack

Up to version 1.13 the office ran as a Compose stack (two containers). The
plugin uses the same data folder, so nothing needs copying:

1. Make sure no backup is running. In the Compose Manager stop the stack
   `UnraidSecretaryOffice` (*Compose Down*). Never run both: the plugin's
   agent doesn't start while the stack's agent is running.
2. Install the plugin (above). It takes `appdata/UnraidSecretaryOffice/data`.
   Cloned the repository somewhere else? Put the path of its `data` folder into
   `DATA_DIR` in `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`,
   then `bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/agent.sh restart`.
3. The agent takes the nightly backup's and Ms. Snapshotini's schedules over
   from User Scripts: the entries `unraid-secretary-office_backup` and
   `…_snapshots` hand over their times and go away.
4. Remove the stack in the Compose Manager. Of the cloned repository only its
   `data` folder is still used.

The way back: remove the plugin and start the stack again; then Mr. Backupsy
→ *Set up… → Apply* creates the User Scripts entry again (set the time under
*Schedule…*), and saving one of Ms. Snapshotini's plans does the same for hers.

<details>
<summary>Without the plugin: the Compose stack (the old way, still works)</summary>

Also needs the **Compose Manager** plugin (or Compose Manager Plus) and a free
IP on `br0` (or a host port instead). The office container serves the page
(`php:apache`, no special rights, **no login** — keep it in your LAN and set a
PIN); the agent container is privileged, shares the host's PID namespace and
`nsenter`s into the host to run Unraid's PHP. The schedules are User Scripts
entries (needs the **User Scripts** plugin).

1. `git clone https://github.com/vipermark2/UnraidSecretaryOffice /mnt/user/appdata/UnraidSecretaryOffice`
2. Copy `.env.example` to `.env` and set at least `OFFICE_IP`. In the Compose
   Manager: *Add New Stack* → `UnraidSecretaryOffice` → gear → *indirect*:
   `/mnt/user/appdata/UnraidSecretaryOffice`, then *Compose Up*. (Without the
   Compose Manager: `docker compose -p unraidsecretaryoffice up -d` in the
   folder.)
3. Open `http://<the IP>/`.

Updating: the Caretaker offers *Update* (a `git pull`, refused if the code was
changed locally); when `compose.yaml` changed, *Compose Up* once more.
</details>

## Security

The office is part of Unraid's web UI: only who is logged in to Unraid gets
in, and every change carries Unraid's CSRF token, so other web pages in your
browser can't trigger anything. On top of that, changing things (deleting
snapshots, starting a backup …) can be protected with a **PIN**: ⋯ → *Protect
with a PIN*. A browser that entered it stays unlocked for 12 hours; changing
the PIN locks every browser again. In the same dialog, *Needed to look, too*
hides everything — shares, paths, containers, logs — from browsers without
the PIN. Forgot it? Delete `data/office/auth.json` in the data folder. All of
this lives in `src/auth.php`.

The agent can do what root can do on the host — that is what it is for — but
it only offers the actions listed in `agent/desks/*.php`.

## Adding a desk

A desk is a set of files; nothing in the core needs to change.

```
agent/desks/<id>.php            what it does on the host
public/desks/<id>/desk.json     {"order": 40, "icon": "🧹", "refresh_after": 300, "open_actions": ["scan"]}
                                ("reception_order" places it differently at the reception)
public/desks/<id>/desk.js       her desk in the web UI
public/desks/<id>/desk.css      optional, its rules nested in #sso{ … } like office.css
public/desks/<id>/lang/en.json  her strings ("name", "role", …), plus other languages
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

Her state goes to `data/<id>.json` (`writeAtomic(deskFile('cleaner'), …)`); the
office serves it at `api.php?a=state&desk=cleaner` and asks for `cleaner.refresh`
when it is older than `refresh_after`. Errors are thrown as
`new Problem('key', [...])` and translated in the UI (`errors.<key>`).

A desk can also tell the caretaker what it needs (`'checks' => fn () => [...]`,
built with `finding()` from `agent/lib/house.php`, texts as `check.<id>` and
`check.<id>_how` in its language files).

`open_actions` lists the actions anybody may call even when a PIN is set
(only reading or measuring); `refresh` always is. Everything else needs an
unlocked browser.

On the web side it registers with `Office.desk({ id, mount(root), poll(), reception() })`
and uses `Office.api`, `Office.dialog`, `Office.menu`, `Office.toast`, `Office.fmt`
and `Office.scope('<id>')` for its strings. See the existing desks.

## Adding a language

Copy `public/lang/en.json` to `public/lang/<code>.json` and translate it
(set `_meta.name` and `_meta.locale`), and do the same for each desk's
`lang/en.json`. Anything not translated falls back to English. Plurals use
`{"one": …, "other": …}` (and whatever categories your language has),
dates and "3 hours ago" come from the browser.

## Development

No build step, no dependencies. Work in a git clone on the server and run it
as the Compose stack (see above): the office reads its code on every request,
and the agent restarts itself (after `php -l`) when one of its files changes.
Stop the plugin's agent first (`bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/agent.sh stop`)
— never two agents.

* Agent log: `data/agent.log` (also under ⋯ → Agent log)
* Run the agent by hand for debugging: stop the agent (container or plugin
  service), then on the host `php agent/agent.php run`
* Tests: on the Unraid host `php tests/run.php` — the tricky logic (cron,
  snapshot retention, Emby detection, the gather's settings, User Scripts
  schedules, the plugin's cron file; on copies only) and every text in both
  languages. It changes nothing on the server.
* The server runs the plugin? `bash plugin/dev-sync.sh` (on the host) copies
  the working copy into the installed plugin in RAM — live until the next
  reboot or plugin update; the agent restarts itself. Never start the stack
  next to the plugin.
* The plugin package: `bash plugin/build.sh <version>` builds
  `dist/unraid-secretary-office-<date>.txz` and `dist/unraid-secretary-office.plg`.
  Publishing a release (tag `v<version>`) does the same on GitHub and
  attaches both (`.github/workflows/plugin.yml`).
* `data/` holds runtime state only and is not part of the repository —
  including `data/unraid-backup/` (the backup engine's settings, state and
  logs; root only) and Jack Emby's `data/embycache/` and `data/gather/`.

## License

Copyright (c) 2026 Benjamin Mueller

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).
