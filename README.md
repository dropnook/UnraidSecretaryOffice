# Unraid Secretary Office

A small office for your Unraid server. Everyone in it looks after one part of the
server, tells you what they noticed and — where it makes sense — lets you act on it.

| Desk | What they do |
|---|---|
| 📸 **Ms. Snapshot** | Every snapshot on the server: ZFS (all pools), btrfs (array disks and pools) and VM snapshots (Unraid's own list and libvirt). Create, delete with an estimate of the space freed, rename, hold/release, unmount. Knows Docker's image layers and leaves them alone, never wakes sleeping disks on her own, and detects a running [unraid-backup](https://github.com/vipermark2/Unraid-Backup-Script) run so its mounted snapshots stay untouched. |
| 🧭 **Ms. Whereabouts** | Knows where everything is and what is going on: shares and where they live, the first folder level of pool shares (and which folders nobody uses), containers (template or compose), compose projects, VMs, users and their share access, SMB/NFS and active sessions, user scripts and cron jobs (with dead paths), places that look like backups, disks with temperature, SMART findings and fill level, Unraid's unread notifications, the license, plugins. Read only. |

| 💾 **Mr. Backup** | Runs the [unraid-backup](https://github.com/vipermark2/Unraid-Backup-Script) script (2.5 or newer): what a run is doing right now and when it will be done (estimated from earlier runs), how the last nights went, which share is protected how (Kopia offsite, local snapshot, not at all) and when it was last copied offsite, what changed on the server since the setup, whether a nightly run is scheduled, and how to get things back (snapshot paths, Kopia, database dumps with ready-made restore commands). Starts a full run, a run without Kopia, a dry run or a check, and stops a run cleanly. |

More desks can join: each one is a module (see below).

The office speaks English and German and follows your device's language.
Adding a language means adding JSON files.

## How it works

```
 browser ──http──▶ office (php:apache, unprivileged)
                     │  data/mailbox/<id>.request   ▲  <id>.response
                     ▼                             │
                   agent (privileged, pid: host, nsenter → host)
                     └─ runs Unraid's own PHP on the host: zfs, btrfs, docker, virsh, /boot/config …
```

* The **office** container only serves the page and talks to the agent through a
  mailbox folder. It has no special rights.
* The **agent** container is privileged and shares the host's PID namespace. It
  `nsenter`s into the host's mount namespace and runs the PHP that ships with
  Unraid. Nothing is installed on the host. Docker starts it with the office,
  restarts it if it stops, and stops it before the array goes down.
* Deliberately no unix socket: a bound socket in the pool would keep it busy and
  Unraid could not stop the array.
* The agent only accepts the actions the desks define and checks every
  request against a fresh look at the system. Commands run without a shell.
* Long jobs that must outlive the agent (a backup run takes hours) are handed
  to the host's `atd`. Docker stops everything in a container's cgroup when the
  container stops; a job started by atd is not part of it.
* Sleeping disks are not woken up unless you ask (e.g. "Wake & scan", measuring
  the size of an array share).

## Installation

Requirements: Unraid 6.12 or newer (7.x recommended), the
**Compose Manager** plugin, a free IP on `br0` (or use a host port instead).

1. Put this repository somewhere on the server, e.g.
   ```bash
   git clone https://github.com/vipermark2/UnraidSecretaryOffice /mnt/user/appdata/UnraidSecretaryOffice
   ```
2. Create `.env` from `.env.example` and set at least `OFFICE_IP`.
3. In the Compose Manager, add a stack and point it at the folder instead of
   copying the file (the stack's `indirect` setting), or from a shell:
   ```bash
   cd /mnt/user/appdata/UnraidSecretaryOffice
   docker compose -p unraidsecretaryoffice up -d
   ```
4. Open `http://<OFFICE_IP>/`.

The green dot top left means the agent checked in within the last 70 seconds.

## Security

Keep the office in your LAN. Reading is open to everyone who can reach it.
Changing things (deleting snapshots, starting a backup …) can be protected
with a **PIN**: ⋯ → *Protect with a PIN*. A browser that entered it stays
unlocked for 12 hours; changing the PIN locks every browser again. Forgot it?
Delete `data/office/auth.json` on the server. All of this lives in
`src/auth.php`, which is also where a login at a reverse proxy (e.g. Authentik)
would plug in later.

Write requests need a custom header, so other web pages in your browser can't
trigger them (no CORS). The agent can do what root can do on the host — that is
what it is for — but it only offers the actions listed in `agent/desks/*.php`.

## Adding a desk

A desk is a set of files; nothing in the core needs to change.

```
agent/desks/<id>.php            what it does on the host
public/desks/<id>/desk.json     {"order": 40, "icon": "🧹", "refresh_after": 300, "open_actions": ["scan"]}
public/desks/<id>/desk.js       her desk in the web UI
public/desks/<id>/desk.css      optional
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

No build step, no dependencies. The code in this folder is live: the office
reads it on every request, and the agent restarts itself (after `php -l`) when
one of its files changes.

* Agent log: `data/agent.log` (also under ⋯ → Agent log)
* Run the agent by hand for debugging: stop the agent container, then on the host
  `php agent/agent.php run`
* `data/` holds runtime state only and is not part of the repository.

## License

Copyright (c) 2026 Benjamin Mueller

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).
