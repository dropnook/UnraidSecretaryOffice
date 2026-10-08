<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/title-dark.svg">
    <img src=".github/title-light.svg" alt="Unraid Secretary Office" height="44">
  </picture>
</h1>

A small office inside your Unraid server's web UI. Each member of staff looks after one part of the server — the
nightly backup, getting things back, snapshots, security, tidying up, the logs — tells you what they noticed and,
where it makes sense, lets you act on it. Before anything changes they show you what will happen and ask. It is a
plugin: no container, no account, no cloud of ours, nothing locked.

![The reception: every desk's news at a glance](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/reception.png)

## The staff

| | Desk | What they do for you |
|---|---|---|
| 👥 | **The Team Lead** | Suggests whom to hire for your server and tells you what is left to do — *still to do*, *recommended*, *good to know* — each with a link into Unraid. Pairs partner offices. |
| 💾 | **Mr. Backupsy** | Sets up and runs the nightly backup: snapshots, database dumps, a package per app and VM, optional encrypted offsite copies with Kopia. Shows what a run is doing, how the last nights went and which share is protected how. |
| 📦 | **Mr. Restori** | Brings back databases, folders, whole shares, templates and VM configurations — from the packages, the local snapshots, Kopia or a partner office. Puts aside what he replaces, never deletes it, and can undo every restore. Practises restores on his own. |
| 📸 | **Ms. Snapshotini** | Every ZFS, btrfs and VM snapshot on the server: create, delete with an estimate of the space freed, rename, hold. Schedules with a simple retention that only ever clears away her own. |
| 🏮 | **The Night Watchman** | Says how securely the server stands, and keeps a watch book of what is different from normal — logins, containers, plugins, the flash, schedules, data flow, vanished snapshots. Changes nothing himself. |
| 🧹 | **Ms. Dustdevil** | Knows where everything lies and gives advice on keeping it in order. Clears away what nobody uses — templates, stacks, appdata folders, Docker's leftovers — into a storeroom first, never deleting at once. Gives containers without a picture a logo. |
| 📝 | **Ms. Protocolli** | Reads every log out loud — Unraid's, the office's, every container's. Her tour counts and groups the errors and warnings and watches how full `/var/log` is. |
| 🍿 | **Jack Emby** | The intern. Looks after helmi1987's EmbyCache (what you watch next waits on the fast pool, the array disks sleep) and the media gather (one disk per film folder). Never gathers while someone watches. |
| 💼 | **The Consultant** | Knows the tools the office relies on — Kopia, Fix Common Problems, Files Viewer, Prometheus, Grafana — and installs them with you through Unraid's own forms. Sets up the Kopia repository if you like, and prints a recovery sheet. |

A fresh office has only the Team Lead. He looks at your server and suggests whom to hire (no Emby, no Jack Emby; no
ZFS or btrfs, no Ms. Snapshotini). Hire whom you need, let them go later — their data and settings stay; Mr. Restori
asks to come together with Mr. Backupsy, one button hires both. *Change the order* at the reception puts the desks in
the order you like.

![Ms. Snapshotini's page](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/snapshot.png)

## What happens, end to end

### The nightly backup

At the time you chose under Mr. Backupsy's *Schedule…*, the backup engine starts on the server — the office needn't
be open. VMs you set to shut down go down first; Nextcloud goes into maintenance mode; the apps that write into
backed-up shares stop briefly while their databases are dumped. Each app and VM gets a fresh **package** in the backup
place (templates or compose files, the dumps, the VM's configuration). The VMs you chose are frozen or paused for a
few seconds while ZFS and btrfs **snapshots** freeze the shares, the packages and the VMs' disks; then everything starts
again. Next the snapshots of what you ticked go to your **partner office**, then **Kopia** uploads from the snapshots,
encrypted — the flash first, then the apps, then the shares and VMs, the smallest first. Old snapshots go by your
retention. Something new — a folder in a share that goes to Kopia, an app, a VM — stays local and keeps running until
you decide in the setup; a new share waits for the setup too. A failed run, or one with warnings, lands in Unraid's
notifications; stopping the array ends a run cleanly, and what it had stopped comes back right after the array starts.

![Mr. Backupsy's overview](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup.png)

### Getting something back

Mr. Restori lists every app and VM with what can come back from where, with dates: its package, the local snapshots
of its folders, Kopia, a partner office. Choose a database, a folder, a share, the templates or a VM's configuration,
and he shows exactly what will happen — the steps, which containers stop and for how long, what goes aside where. Only
when you confirm does he start, on the server, one restore at a time and never during a backup. His journal shows every
step; *Put back* undoes a restore, also one that failed. He never starts or recreates a container — he says what to
click. For the rest there are ready-made commands with your names and paths, and a guide *onto a new server*.

![Mr. Restori's page](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/restore.png)

### The restore drill

Once a month (or every week, or only when you press *Practise now…*), at night after a backup that went well, Mr.
Restori proves that what Mr. Backupsy keeps really comes back. He reads every package in full, plays the database
dumps into throwaway containers without network, checks the media servers' database copies and the VMs' disks, and
streams a sample back from Kopia to compare with the local snapshot. Each app and VM gets a **certificate** — which
level is proven, from which copy, when. The drill wakes no disk, never runs during a parity check, ends well before
the next backup and cleans up after itself. A drill that couldn't prove everything tells you what, and where to fix
it.

### The Night Watchman's round

When you hire him he notes what is normal. From then on he walks his round every five minutes, office open or not, and
writes down only what is different: a login from an address he hasn't seen, a burst of failed logins, a container that
got special rights, a new plugin, a change on the flash, a new cron line, a share newly open to guests, a client or
container sending far more than usual, snapshots gone that nobody in the office removed. Each entry names its MITRE
ATT&CK technique; the important ones go to Unraid's notifications. *I know, thanks* makes it the new normal. While the
array is stopped — also while an encrypted array waits for its key — his night shift keeps watch from RAM and the
flash.

![The Night Watchman's watch book](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman.png)

### The search

The magnifier at the top right, or ⌘K / Ctrl+K inside the office, finds a desk, a section, a tile, a setting, a step
of a setup or one of the Consultant's guides — in all five languages at once, forgiving a typo. It also finds what the
desks know right now: a share, an app, a VM, a database, an open point, an entry of the watch book, a partner office.
Choose one and the office takes you there, opens it and marks the place. It all runs in your browser.

![The search](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/search.png)

## Install

Unraid **7.3.2 or a newer 7.x**. Unraid 8 is not supported yet; the plugin refuses to install there. Before you update Unraid itself, look for an office release that names the new version — Unraid switches off a plugin that doesn't at its first boot, and the nightly backups stop with it; the Team Lead reminds you once your Unraid is newer than the one the office was tested on.

1. *Plugins → Install Plugin* and paste
   ```
   https://github.com/dropnook/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
   ```
2. Open **Sekretariat** in Unraid's menu bar. The Team Lead welcomes you and suggests whom to hire.
3. For backups: Mr. Backupsy → *Set up…* (he reads the server and proposes everything with reasons; change what you
   like, then *Apply*), then *Schedule…*. The Team Lead lists what is still missing.

*⋯ → Entry in Unraid* renames the menu entry or moves it under *Settings → User Utilities* or to a button in Unraid's
header. A tile on Unraid's Dashboard shows the essentials: the messenger, the Team Lead's open points, the last and
next backup. The green dot beside *⋯* means the messenger (the office's agent) checked in within the last 70 seconds.

![Mr. Backupsy's setup](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-setup.png)

## Updates and removal

**Updating** works like any plugin, under *Plugins*; the Team Lead also says when a new version is out. While a
backup, a restore or drill, or one of Jack Emby's runs is going on, the update refuses — try again when it is done.
The new version is unpacked beside the running one and checked first: a download that can't be unpacked leaves the
office as it was. Your data stays, and the office brings it up to date at its first start, putting aside what it
rewrites. A page left open says so, with a button to reload. After an update of the backup engine Mr. Backupsy's setup looks at the server anew once, by itself, and says so. A recommendation you put aside with «I know, thanks» comes back once when an update changes what it says.

**Removing** (*Plugins → Remove*, refused like an update while such a run is going on) takes the code away and lets
go of what the office had mounted. Your schedules are put aside on the flash and come back when you install the office
again. Partnerships with other offices end — pair again after a new install. Your data in appdata, the share
`UnraidSecretaryOffice`, the settings on the flash, your snapshots and Ms. Dustdevil's storeroom stay; delete them
yourself if you don't want them any more.

## What it keeps where

| What | Where |
|---|---|
| Settings, state and logs of every desk, the backup engine's settings | the data folder, `appdata/UnraidSecretaryOffice/data` |
| Mr. Backupsy's packages (templates, compose files, dumps, VM configurations) | the backup place: a share of its own, by default `UnraidSecretaryOffice` (you create it; the setup explains why and where) — their history in its snapshots |
| The plugin package, where the data folder is, the schedules, partner keys, uploaded container pictures | the flash, `/boot/config/plugins/unraid-secretary-office/` |
| The code, the messenger's sign of life, the snapshots mounted during a run, the numbers for Prometheus | RAM — nothing of the office lands on the pool every few seconds, so a pool of hard disks can sleep |

Nothing leaves the server unless you set it up: Kopia to the storage you chose, partner offices to the partner. The
office itself only asks GitHub once a day whether a new version is out, and checks logo addresses for containers
without a picture — and sends a report only when you send one yourself (below). No account, no telemetry. The desks never wake a sleeping disk unless you ask; the nightly
backup does, unless you tell it to leave sleeping pools out.

![Ms. Dustdevil's «Where is what»](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/cleanup-where.png)

## Languages and themes

The office speaks English, German, Italian, French and Spanish — your browser's language, English where it doesn't
speak yours; *⋯ → Language* picks another one. Where it tells you what to click in Unraid, Unraid's menus read as
Unraid shows them, in the language Unraid runs in. It takes Unraid's theme; *Auto · Dark · Light* at the reception
gives the office's own area the look of Unraid's black or white theme, and a text-size switch *A · A · A* beside it
makes it larger — both in your browser only.

## Security

- The office is part of Unraid's web UI: only who is logged in to Unraid gets in, and every change carries Unraid's
  CSRF token. Whoever is logged in to Unraid is root anyway, so there is no PIN of its own — keep Unraid logged in only
  on devices you trust. What the office adds are previews and confirmations against mistakes.
- The page only shows. The messenger does the work on the host — only the actions the desks define, each checked
  against a fresh look at the server, commands without a shell.
- Nothing is deleted at once: Ms. Dustdevil's storeroom, Mr. Restori's things put aside.
- Secrets stay put: Kopia's keys reach Kopia through RAM only, the Emby API key never reaches the page, the dumps name
  password variables, never their values.
- A partner office gets a door, not a login: a key that can only hand over its copies, fetch them back and ask how
  the office is — no shell, no path, nothing deleted.

What was checked and what is still open: [HARDENING.md](HARDENING.md).

## Partner offices

Two Unraid servers with the office — yours, a family member's, a friend's — can keep each other's nights. Paired at
the Team Lead (two blocks to paste, one safety code to compare), each night the ZFS snapshots of what you tick go to
the partner — after the first night only what changed — through SSH; the partner keeps them with its own retention and
you can never delete them there. Mr. Restori brings them back, also onto a new server when the old one is gone. ZFS
only, and the partner can read the copies — for a friend, Kopia is the encrypted way.

## Notifications

What needs you while the office is closed goes to Unraid's notifications (the bell, and mail or push if you set them up
under *Settings → Notifications*), as *Unraid Secretary Office*: a backup run that failed or had warnings, a drill
that couldn't prove a restore, a schedule's problem, a new point on the Team Lead's list after half an hour, the
Night Watchman's new entries (each kind at most once an hour), the messenger silent for ten minutes. The desks write
in the language you last used the office in; the backup engine writes English.

## Monitoring

With a Node Exporter, Prometheus and Grafana on the server (the Consultant sets them up with you), the office's own
numbers go there too, with a ready Grafana dashboard. Details: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md#monitoring).

**For developers:** how it works inside, adding a desk or a language, tests and releases —
[docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).

## Support

The office is free and complete — nothing is locked, now or later. If it is useful to you, a tip is welcome: the tip
jar in the office (the Team Lead's *Tips & pay rise*) or [PayPal](https://paypal.me/dropnook); a share goes to
helmi1987 (see below). If the tips ever exceed what our work costs, we give the rest to animal shelters that urgently
need financial support. Questions and bugs: [GitHub issues](https://github.com/dropnook/UnraidSecretaryOffice/issues),
or without an account from inside the office (*⋯ → Report a problem or a wish…*, above).

## Reporting a problem or a wish

No GitHub account needed: *⋯ → Report a problem or a wish…* on any page (or the button at the Team Lead's *The team*).
Say what it is — a problem, a wish or a question — which desk it is about, a title and a few words. *Show what will
be sent…* then lists
everything that would go, part by part: your words as you typed them, the office's and Unraid's versions, the
languages, which desks you hired, the desk's last error, and its last lines from the messenger's log — cleaned of share,
pool, server, partner and user names, paths, addresses, MAC addresses, e-mail addresses and keys (you see what was
hidden). Untick what you'd rather keep. Nothing leaves the server before you click *Send*.

It goes to the office's makers, into a private inbox on GitHub — not public; the makers, GitHub and Cloudflare (who
carries it) can read it. Each office can send two reports a week; you get a number, and *Your reports* keeps the list.
To talk something over with others, use the
[GitHub issues](https://github.com/dropnook/UnraidSecretaryOffice/issues) (and the forum thread, once there is one —
the dialog links it then).

## Thanks

Jack Emby's two tools are the work of **[helmi1987](https://github.com/helmi1987)**:
[EmbyCache](https://github.com/helmi1987/embycache-for-unraid) and
[media-disk-gather](https://github.com/helmi1987/media-disk-gather-for-unraid) ("Consolidate folders"). Both are under
the GNU GPL v3 (`GPL-3.0-or-later`, as his READMEs say); they ship with the office in `embycache/` and `gather/`,
modified and likewise GPL-3.0-or-later — what we changed is listed at the top of their READMEs. Thank you, helmi1987 —
a share of the tips goes to him.

## License

Copyright (c) 2026 Benjamin Mueller

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).

**No warranty for your backups either.** Mr. Backupsy and the backup engine do their best, but a backup can be faulty
or incomplete — a dump that failed, a share left out, a repository that can't be opened any more. You stay responsible
for your data and your backup strategy: test a restore now and then (Mr. Restori's drill helps), and keep more than one
copy, for example 3-2-1 (three copies, on two kinds of storage, one of them off site).
