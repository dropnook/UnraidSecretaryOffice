<h1>
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/title-dark.svg">
    <img src=".github/title-light.svg" alt="Unraid Secretary Office" height="44">
  </picture>
</h1>

A small office inside your Unraid server's web UI. Each member of staff looks after one part of the server — the
nightly backup, getting things back, snapshots, security, tidying up, the logs — tells you what they noticed and,
where it makes sense, lets you act on it. Before anything changes they show you what will happen and ask. Two servers
with the office can keep each other's backups as partner offices, and the Night Watchman also reads what a UniFi
or MikroTik router says about the server. It is a plugin: no container, no account, no cloud of ours, nothing locked.

![The reception: the whole team at a glance](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/reception.png)

## The staff

| | Desk | What they do for you |
|---|---|---|
| 👥 | **The Team Lead** | Suggests whom to hire for your server and tells you what is left to do — *still to do*, *recommended*, *good to know* — each with a link into Unraid. Pairs partner offices and hands a gone server's copies to a new one. |
| 💾 | **Mr. Backupsy** | Sets up and runs the nightly backup: snapshots, database dumps, a package per app and VM, optional encrypted offsite copies with Kopia — per share, app and VM: not backed up, local, or local + offsite. Shows what a run is doing, how the last runs went and which share is protected how. |
| 📦 | **Mr. Restori** | Brings back databases, folders, whole shares, templates and VM configurations — from the packages, the local snapshots, the offsite copy (Kopia) or a partner office. Puts aside what he replaces, never deletes it, and can undo every restore. Practises restores on his own: the restore drill. |
| 📸 | **Ms. Snapshotini** | Every ZFS, btrfs and VM snapshot on the server: create, delete with an estimate of the space freed, rename, hold. Schedules with a simple retention that only ever clears away her own. The entries a deleted VM leaves in Unraid's snapshot list — Unraid's VM page can't remove them — she takes out once nothing of them is left on disk (into the storeroom; put back any time). |
| 🏮 | **The Night Watchman** | Says how securely the server stands, and keeps a watch book of what is different from normal — logins, containers, plugins, the flash, schedules, data flow, vanished snapshots, and what your router reports. Changes nothing himself. |
| 🧹 | **Ms. Dustdevil** | Knows where everything lies and gives advice on keeping it in order. Clears away what nobody uses — templates, stacks, appdata folders, Docker's leftovers — into a storeroom first, never deleting at once (Docker's volumes are copied in before Docker removes them; only images and the build cache, which can be downloaded again, and volumes mounted from elsewhere go for good). Gives containers without a picture a logo. |
| 📝 | **Ms. Protocolli** | Reads every log out loud — Unraid's, the office's, every User Script's and container's, the router's. Her tour counts and groups the errors and warnings and watches how full `/var/log` is. |
| 🍿 | **Jack Emby** | The intern. Looks after helmi1987's EmbyCache (what you watch next waits on the fast pool, the array disks sleep) and the media gather (one disk per film folder; on request it also fetches the folders' files from the cache — never what EmbyCache keeps there). Never gathers while someone watches. While EmbyCache runs for real, a bar per person shows what is coming for them, how far it is and when their last file will be there (EmbyCache copies everyone's files in one queue), finished parts how long they took; *Stop after this file* ends the run gently — the next run goes on. Shows how much of each library lies on which disk or pool, with the date it was measured. Runs for real only while Unraid's own mover schedule is *Disabled* — otherwise dry runs only; his page switches it off for you (*Switch the mover schedule off…*, after a confirm), or you set it in Mover Settings yourself. *Move now* by hand stays possible: the mover starting during a run stops it after the current file. Mover Tuning is optional; if it is installed, Jack enters EmbyCache's list there himself, so its own schedule leaves the films alone too. |
| 💼 | **The Consultant** | Knows the tools the office relies on — Kopia, Fix Common Problems, Files Viewer, Stream Viewer, Mover Tuning, unbalanced, Node Exporter, Prometheus, Grafana — and installs them with you, a preview first. Sets up the Kopia repository if you like and prints a recovery sheet; shows how the router's log reaches Unraid (UniFi, MikroTik). |

A fresh office has only the Team Lead. He looks at your server and suggests whom to hire (no Emby, no Jack Emby; no
ZFS or btrfs, no Ms. Snapshotini). Hire whom you need, let them go later — their data and settings stay; Mr. Restori
asks to come together with Mr. Backupsy, and *Hire together with Mr. Backupsy* hires both. Mr. Backupsy's *Let
go* offers «Also clear away what he kept here»: his packages go to Ms. Dustdevil's storeroom and his own local
snapshots are deleted — his settings and the offsite copies (in your storage and at the partners) stay. Letting Jack Emby go switches off his
two schedules (a run that is going finishes); «Also bring the prepared films back to the array» moves what EmbyCache
keeps on the pool back, the way its cleanup does (never while someone watches), and «Also take my list out of Mover
Tuning again» (on by default) puts Mover Tuning's two settings back as they were before him; Unraid's mover schedule
stays off (switch it on again in Mover Settings if you like). Hired
again, his settings are as they were and the schedules stay off until you switch them on. *Change the order* at the
reception puts the desks in the order you like.

![Ms. Snapshotini: one snapshot in detail, why «used» and «new since» differ](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/snapshot.png)

## What happens, end to end

### The scheduled backup

At the time you chose under Mr. Backupsy's *Schedule…*, the backup engine starts on the server — the office needn't be
open. VMs you set to shut down go down first; Nextcloud goes into maintenance mode; the apps that write into backed-up
shares stop briefly while their databases are dumped. Each app and VM gets a fresh **package** in the backup place
(templates or compose files, the dumps, the VM's configuration). The VMs you chose are frozen or paused for a few
seconds while ZFS and btrfs **snapshots** freeze the shares, the packages and the VMs' disks; then everything starts
again. Next the snapshots of what you ticked go to your **partner office**, then the **offsite** copy: Kopia uploads
from the snapshots, encrypted, to your storage (S3, Backblaze B2, SFTP, WebDAV, a NAS …) — the flash first, then the
apps, then the shares and VMs, the smallest first. Old snapshots go by your retention. A new folder simply goes with its share — offsite when
the share goes offsite; only in appdata and domains (the shares Unraid keeps apps and VMs in) it follows the app or VM it
belongs to. Something new — an app, a VM, a folder there of no app or VM going offsite — stays local and keeps running
until you decide in the setup; a new share waits for the setup too. A failed run, or one with warnings, lands in Unraid's notifications. *Stop the run* ends a run
cleanly, and its card says what it still waits for (apps stopping, a VM, the offsite upload); stopping the array ends
it cleanly too, and what it had stopped comes back right after the array starts.

![Mr. Backupsy: a run going on — its steps, its offsite sources, how long still](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup.png)

### Getting something back

Mr. Restori lists every app and VM with what can come back from where, with dates: its package, the local snapshots of
its folders, the offsite copy (Kopia), a partner office. Choose a database, a folder, a share, the templates or a VM's
configuration, and he shows exactly what will happen — the steps, which containers stop and for how long, what goes
aside where. Only when you confirm does he start, on the server, one restore at a time and never during a backup.
«Restored» lists every restore with its steps and what went aside where; *Put back* undoes one, also one that failed.
He never starts or recreates a container — he says what to click. For the rest there are ready-made commands with your
names and paths, and a guide *onto a new server*.

### The restore drill

Once a month (or every week in the night you choose, or only when you press *Practise now…*), at night after a backup
that went well, Mr. Restori proves that what Mr. Backupsy keeps really comes back. He reads every package in full,
plays the database dumps into throwaway containers without network, checks the media servers' database copies and the
VMs' disks, and streams a sample back from the offsite copy (as many GB as you allow) to compare with the local
snapshot — where an app's package goes offsite, one of its dumps is read back from there and played too. Each app and
VM gets a **certificate**: which level is proven, from which copy, when; a step that failed shows its error. The drill
wakes no disk, never runs during a parity check, ends well before the next backup and cleans up after itself (what an
interrupted one left, Ms. Dustdevil clears away). What it left unchecked comes first the next time.

![Mr. Restori: the drill passed — the level per app, the databases played back](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/restore-drill.png)

### The Night Watchman's round

When you hire him he learns what is normal (his *On watch since* tile says until when). From then on he walks his
round every five minutes, office open or not, and writes down only what is different: a login from an address he
hasn't seen, a burst of failed logins, a container that got special rights, a new plugin, a change on the flash, a new
cron line, a share newly open to guests, a client or container sending far more than usual, snapshots gone that nobody
in the office removed. Each entry names its MITRE ATT&CK technique; the important ones go to Unraid's notifications.
*I know, thanks* makes it the new normal. While the array is stopped — also while an encrypted array waits for its key
— his night shift keeps watch from RAM and the flash.

**The router** (UniFi gateways and MikroTik routers with RouterOS 7): it sends its log to Unraid's own syslog
server — the Consultant shows both sides, for a MikroTik the lines to paste — and he reads it on his round: router and
VPN logins not seen before, firewall, NAT or port-forwarding changes, intrusion detections at this server, a new device
on the LAN or one using the server's name or address, the router's log gone silent. A MikroTik also tells him of bursts
of failed router logins, a port losing its link again and again, the internet away (a short outage is a plain line, a
long one is told once it is over) and a restart without proper shutdown — at the same time as the server's own start,
a power loss for both. No router password, never a word to the router; other devices only as new ones, the rest are
counts.

**Why a parity check runs:** each one gets a plain line with its reason — on schedule, resumed by Parity Check Tuning,
by hand (when that plugin saw it), a disk rebuild (no check at all), or after an unclean stop: the array didn't stop
within the *Shutdown time-out* at the last shutdown (what held it up — a busy disk, a VM switched off hard — as far as
Unraid's diagnostics or its kept syslog show), or the server went off without shutting down. Unraid then checks the
parity at every start until a stop is clean again; he says so once, and the Team Lead lists which time-out to raise
(Docker + VMs + a margin) until a later stop is clean. He never starts, pauses or cancels a check.

![The Night Watchman's watch book: what was different from normal](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/watchman.png)

### The search

The magnifier at the top right, or ⌘K / Ctrl+K inside the office, finds a desk, a section, a tile, a setting, a step
of a setup or one of the Consultant's guides — in all five languages at once, forgiving a typo. It also finds what the
desks know right now: a share, an app, a VM, a database, an open point, an entry of the watch book, a partner office,
what Ms. Dustdevil would clear away and where it lies. Choose one and the office takes you there, opens it and marks
the place. It all runs in your browser.

## Install

Unraid **7.3.2 or a newer 7.x**; Unraid 8 is not supported yet, the plugin refuses to install there. Snapshots need
pools or array disks on ZFS or btrfs (shares on XFS are backed up without one); partner offices need ZFS. Before you
update Unraid itself, look for an office release that names the new version — Unraid switches off a plugin that
doesn't at its first boot, and the scheduled backups stop with it; the Team Lead reminds you once your Unraid is newer
than the one the office was tested on.

1. *Plugins → Install Plugin* and paste
   ```
   https://github.com/dropnook/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
   ```
2. Open **Sekretariat** in Unraid's menu bar. The Team Lead welcomes you and suggests whom to hire.
3. For backups: Mr. Backupsy → *Set up…* (he reads the server and proposes everything with reasons; change what you
   like — the bar counts his proposals and your changes, *Leave out* declines one of his — then *Apply*), then
   *Schedule…*. The Team Lead lists what is still missing.

*⋯ → Entry in Unraid* renames the menu entry or moves it under *Settings → User Utilities* or to a button in Unraid's
header. A tile on Unraid's Dashboard shows the essentials: the messenger, the Team Lead's open points, the last and
next backup. The green dot beside *⋯* means the messenger (the office's agent) checked in within the last 70 seconds.

![Mr. Backupsy's setup: «Apply the settings?» — 1 proposal of mine · 1 change of yours](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/backup-setup.png)

## Updates and removal

**Updating** works like any plugin, under *Plugins*; the Team Lead also says when a new version is out. While a
backup, a restore or drill, or one of Jack Emby's runs is going on, the update refuses — try again when it is done. The
new version is unpacked and checked beside the running one; your data stays and is brought up to date at the first
start, what is rewritten put aside. A page left open offers to reload; a recommendation you put aside with «I know,
thanks» comes back once when an update changes what it says.

**Removing** (*Plugins → Remove*, refused like an update while such a run is going on) takes the code away and lets
go of what the office had mounted. Your schedules are put aside on the flash and come back when you install the office
again. Partnerships with other offices end — pair again after a new install. Your data in appdata, the share
`UnraidSecretaryOffice`, the settings on the flash, your snapshots and Ms. Dustdevil's storeroom (the hidden folders
`.UnraidSecretaryOffice-trash`, before `_UnraidSecretaryOffice-trash`) stay; delete them yourself if you don't want them
any more.

## What it keeps where

| What | Where |
|---|---|
| Settings, state and logs of every desk, the backup engine's settings | the data folder, `appdata/UnraidSecretaryOffice/data` |
| Mr. Backupsy's packages (templates, compose files, dumps, VM configurations) | the backup place: a share of its own, by default `UnraidSecretaryOffice` (you create it; the setup explains why and where) — their history in its snapshots |
| The plugin package, where the data folder is, the schedules, partner keys, uploaded container pictures | the flash, `/boot/config/plugins/unraid-secretary-office/` |
| The code, the messenger's sign of life, the snapshots mounted during a run, the numbers for Prometheus | RAM — nothing of the office lands on the pool every few seconds, so a pool of hard disks can sleep |

Nothing leaves the server unless you set it up: the offsite copy (Kopia) to the storage you chose, partner offices to
the partner. The office itself only asks GitHub once a day whether a new version is out, checks logo addresses for
containers without a picture, and sends a report only when you send one yourself (below) — and, when you open the list
of your reports, asks where they stand. No account, no telemetry. The desks never wake a sleeping disk unless you ask;
the scheduled backup does, unless you tell it to leave sleeping pools out.

![Ms. Dustdevil's «Where is what»: the places that matter and how they are protected](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/cleanup-where.png)

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
- What is tidied up or replaced is put aside first, never deleted at once: Ms. Dustdevil's storeroom, Mr. Restori's
  things put aside.
- Secrets stay put: Kopia's keys reach Kopia through RAM only, the Emby API key never reaches the page, the dumps name
  password variables, never their values.
- A partner office gets a door, not a login: a key that can only hand over its copies, fetch them back and ask how
  the office is — no shell, no path, nothing deleted.

What was checked and what is still open: [HARDENING.md](HARDENING.md).

![Ms. Dustdevil's storeroom: nothing is thrown away at once](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/storeroom.png)

## Partner offices

Two Unraid servers with the office — yours, a family member's, a friend's — can keep each other's backups. Paired at
the Team Lead (two blocks to paste, one safety code to compare), with every backup the ZFS snapshots of what you tick go to
the partner — after the first one only what changed — through SSH; the partner keeps them with its own retention and
you can never delete them there. Mr. Restori's *At <partner>* brings them back into a new dataset beside the original.
When a server is gone, the new one asks at its Team Lead (*Start from a partner's copy…*) and the partner answers with
a **restore ticket** — a door for seven days that only hands over those copies; Mr. Restori's *Onto a new server*
pulls the backup place first, then the shares and VMs. ZFS only, and the partner can read the copies — for a friend,
Kopia is the encrypted way.

![The Team Lead: a partner office at the grandparents', copies both ways](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/partner.png)

## Notifications

What needs you while the office is closed goes to Unraid's notifications (the bell, and mail or push if you set them up
under *Settings → Notifications*), as *Unraid Secretary Office*: a backup run that failed or had warnings, a drill
that couldn't prove a restore, a schedule's problem, a new point on the Team Lead's list after half an hour, the
Night Watchman's new entries (each kind at most once an hour) and a parity check after an unclean stop, the messenger silent
for ten minutes. The desks write
in the language you last used the office in; the backup engine writes English.

## Monitoring

With a Node Exporter, Prometheus and Grafana on the server (the Consultant sets them up with you), the office's own
numbers go there too, with a ready Grafana dashboard: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md#monitoring). For
developers — how it works inside, adding a desk or a language, tests and releases — the same document.

## Support

The office is free and complete — nothing is locked, now or later. If it is useful to you, a tip is welcome: the tip
jar in the office (the Team Lead's *☕ Tips & pay rise*) opens the [tip page](https://tip.uso.dropnook.app/) (PayPal)
with your server's ID; after the tip the office fetches your supporter key by itself (the page shows it too, to paste
under *Enter key…*). A share goes to helmi1987 (see below). The key unlocks nothing — it stops the reminders and shows a
thank-you on the Team Lead's plate, which only you see, a little office story: ☕ one coffee for the team (any amount),
☕☕ a round for everyone (from 20), 🍰 a cake for the whole office (from 50), 🥂 the whole team toasting a pay rise and
the donations (from 100; USD, EUR or CHF). Every key is kept; each picture shows once — never a count. If the tips ever exceed what our work costs, we give the
rest to animal shelters that urgently need financial support. Questions and bugs:
[GitHub issues](https://github.com/dropnook/UnraidSecretaryOffice/issues), or without an account from inside the office
(below).

## Reporting a problem or a wish

No GitHub account needed: *⋯ → Report a problem or a wish…* on any page (or the button at the Team Lead's *The team*).
Say what it is — a problem, a wish or a question — which desk it is about, a title and a few words. *Show what will be
sent…* then lists everything that would go, part by part: your words as you typed them, the office's and Unraid's
versions, the languages, which desks you hired, the desk's last error, and its last lines from the messenger's log —
cleaned of share, pool, server, partner and user names, paths, addresses, MAC addresses, e-mail addresses and keys
(you see what was hidden). Untick what you'd rather keep. Up to three pictures can go along — the office redraws them,
without location or other metadata. Nothing leaves the server before you click *Send*.

It goes to the office's makers, into a private inbox on GitHub — not public; the makers, GitHub and Cloudflare (who
carries it) can read it. Each office can send 25 reports a day, and *Your reports* keeps the list: where each one
stands — received, looked at, done — and, once the makers opened a public GitHub issue for it, a link to it
(«Issue #…»). For that the office asks the inbox when you open the dialog (each report at most once an hour), with
nothing but the reports' numbers in the inbox and the office's report ID.

![«Your reports»: a wish, built and public as Issue #14](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/report.png)

## Special thanks

Jack Emby's two tools are the work of **[helmi1987](https://github.com/helmi1987)**:
[EmbyCache](https://github.com/helmi1987/embycache-for-unraid) and
[media-disk-gather](https://github.com/helmi1987/media-disk-gather-for-unraid) ("Consolidate folders"), both
GPL-3.0-or-later. They ship with the office in `embycache/` and `gather/`, modified and under the same licence — what
we changed is listed at the top of their READMEs. helmi1987 also tested the office early and sent the reports that made it
better. Thank you, helmi1987 — a share of the tips goes to him.

![Jack Emby live: EmbyCache gets ready for each person what they watch next](https://raw.githubusercontent.com/dropnook/UnraidSecretaryOffice/main/docs/screenshots/emby-live.png)

## License

Copyright (c) 2026 Benjamin Mueller

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).

**No warranty for your backups either.** Mr. Backupsy and the backup engine do their best, but a backup can be faulty
or incomplete — a dump that failed, a share left out, a repository that can't be opened any more. You stay responsible
for your data and your backup strategy: test a restore now and then (Mr. Restori's drill helps), and keep more than
one copy, for example 3-2-1 (three copies, on two kinds of storage, one of them off site).
