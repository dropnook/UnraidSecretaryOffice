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
| 🧭 **Ms. Whereabouts** | Knows where everything is and what is going on — and knows better: **If I were you …** has her advice on keeping things in order (appdata/system/domains on the array, a mover without a schedule, disks that never sleep, old disks, a parity check long ago, no UPS, a syslog lost after a crash, Compose stacks that build their own image — Compose Manager's update can't, she gives the rebuild command, Unraid's exclusive shares — faster for shares on one pool: when to switch them on and what keeps a share from being one, cron lines whose program went with its plugin — with the command that tidies root's crontab), each with why, a link into Unraid and «I know, thanks». Security advice is the Night Watchman's — while he isn't hired, she says so once. Besides: shares and where they live, the first folder level of pool shares (and which folders nobody uses), containers (template or compose), compose projects, VMs, users and their share access, SMB/NFS and active sessions (what changes there, the Night Watchman sees), user scripts and cron jobs (with dead paths), places that look like backups, disks with temperature, SMART findings and fill level, Unraid's unread notifications, the license, plugins. **Where things are**: tiles for the places that matter when something breaks or moves — Unraid's configuration on the flash, Docker templates (XML), compose stacks with their .env, the VM configuration in libvirt.img (XML, NVRAM, TPM state), user scripts — each path with how Mr. Backupsy protects it. VMs with firmware, TPM, NVRAM, disks and their snapshot chain. Read only. |
| 💾 **Mr. Backupsy** | Runs the office's backup engine ([backup/](backup/README.md): consistent ZFS/btrfs snapshots, database dumps, Nextcloud maintenance mode, a package per app and VM, Kopia offsite — an app or VM you choose as a Kopia source of its own with its own retention): what a run is doing right now and when it will be done (estimated from earlier runs), how the last nights went, which share is protected how (Kopia offsite, local snapshot, not at all) and when it was last copied offsite, what changed on the server since the setup, whether a nightly run is scheduled, what each app's and VM's package holds (templates or compose files, dumps, consistent copies of the databases of media servers that keep running, the VM's XML, NVRAM and TPM state), and where everything lies (getting it back is Mr. Restori's job). Starts a full run, a run without Kopia, a dry run or a check, and stops a run cleanly. **Set up in the browser** (*Set up…*): he reads the server and shows his proposals with reasons — Kopia offsite, every share, which containers stop for the snapshots, database dumps and Nextcloud maintenance mode, retention, Kopia policies — you change what you like and apply; the same engine as `setup.sh` in a terminal checks and writes it. It warns when Nextcloud's or Immich's files lie in a share backed up less than the app, and offers to leave caches, transcodes and logs out of Kopia. **What is new stays local and keeps running until you decide:** a new folder in a share that goes to Kopia (say a Bitcoin node filling `appdata/bitcoin` with hundreds of GB) stays in the local snapshots only, a new container isn't stopped, a new VM isn't held — he tells you once, shows it on his page and in the setup as «new — please decide» (only local, or local + Kopia), and Apply lists it under «New». |
| 📦 **Mr. Restori** | Brings back what is gone — careful as a restorer in white gloves, «piano, piano». Organised by app and VM: what can come back from where, with dates — from Mr. Backupsy's package (templates or compose files and whether they are still on the server, database dumps, a media server's database copies; for a VM its XML, UEFI variables and TPM state; earlier nights' packages from the snapshots of the backup place), from the local ZFS and btrfs snapshots of its folders (where they lie, how many, the newest, datasets inside), and from Kopia (its own source or its share's, and when it last went there). Each row says what its chips refer to: the **package** (and whether Kopia has it, with the backup place's share) and the **data** (its folders' snapshots, Kopia, protection). **He brings things back himself**: a database from its dump (a safety dump of what is there first; Postgres into a fresh database with the old one kept aside as it is — the only way for Immich —, MariaDB and MongoDB in place), a media server's database copies, a folder — or a whole share an app binds, all of it or chosen folders at its top — from its local snapshots (a share on several pools and disks put together from all its parts, back onto the share so Unraid places it; copied next to it, or swapped in with seconds of downtime; a missing share he never creates: he says so and shows its old settings), with «Shares it needs» per app and VM, templates and compose files (what is there goes aside; he never starts or recreates a container — he says what to click), a VM's configuration (only while it is shut off), a Kopia snapshot into a writable folder of the Kopia container. Every restore shows first exactly what will happen — the steps, what goes aside where (nothing is ever deleted), which containers stop and for how long — and starts only once you confirm; it runs on its own on the server, one at a time, never while a backup runs (a backup that comes along is skipped and says why), and his **journal** shows every step; **Put back** undoes a restore, also one that failed. Ready-made commands with your names and paths too: databases with the credentials the dump was made with (Immich into a fresh database through its sed, Nextcloud with maintenance mode and a new data fingerprint), media server databases, templates and compose files, a single VM, all of libvirt.img; the apps' own backups as a second way; the way out of Kopia step by step with every source; and **onto a new server**: what comes back from where, what must not, and where it gets fiddly. Never wakes a sleeping disk unless you tick «wake» — then he wakes it and waits until it answers before he looks into its snapshots. |
| 🍿 **Jack Emby** | The intern ("check Emby"): fetches coffee, entertains everyone — and looks after [EmbyCache](https://github.com/helmi1987/embycache-for-unraid), which keeps what people are about to watch on the fast pool so the array disks can sleep, and brings it back to the very disk it came from once it's watched. Before that, the [media gather](https://github.com/helmi1987/media-disk-gather-for-unraid) ("Consolidate folders") brings every film folder together on one disk. Both ship with the office (`embycache/`, `gather/`); Jack sets them up (Emby API key, libraries, people, pool — films and series each their own way), shows whether your shares suit, runs them and schedules them. |
| 📝 **Ms. Protocolli** | Reads every log out loud — and sadly understands none of it. The office's own logs (agent, Mr. Backupsy's runs, Jack's EmbyCache), Unraid's (syslog, kernel, VMs, web UI, Samba, parity checks …), what every User Script printed last, and every container's docker logs. Last 100 to 10000 lines, live like `tail -f`, filter by text or errors and warnings only, copy or download. **Her tour** counts and groups (understanding stays someone else's job): how full `/var/log` is — Unraid's 128 MB RAM disk — with its biggest files and how much they grew since her last tour, every container's log size and whether Docker's log rotation limits it, and per log the lines that sound like an error or a warning since her last tour (the first time: the last 24 hours) — a line that names its own level goes by it, others by their words; similar lines (also when only a file or a path differs) as one entry with how often, first and last seen and the newest line, one click away from that line in the reader. The team lead hears from her when `/var/log` is over 60 % (recommended) or 80 % (still to do). Read only, from a fixed list; nothing wakes a disk. |
| 🏮 **The Night Watchman** | The office's security department — calm, few words. **How secure does it stand?** What he would set differently now: the flash open to guests — even only for reading (the password hashes and SSH keys lie there) —, shares open to everyone (also a disk or a pool), Telnet, Unraid's FTP server, the CPU's protection against Spectre-like flaws switched off (and VMScape while there are VMs) or on, privileged containers — each with why, a link into Unraid and *I know, thanks* (kept on the server for every browser; the tip comes back when what it is about changes). Advice, not alarms: no notification, and the Team Lead only hears how many. **What changed?** He keeps the watch book and tells you only what is different from normal. When you hire him he notes what is normal and reports nothing for it; then every five minutes, also with the office closed: a WebGUI or SSH login from an address he hasn't seen, a burst of failed logins (five from one address within ten minutes), a container that turned privileged, uses the host's network or processes, publishes new ports or got new capabilities, devices or the Docker socket (a new container only when it has such rights), a new plugin or one that takes its updates from somewhere else now, changes on the flash (`go`, `/boot/extra`, users, passwords, SSH keys), a share — or a disk, a pool, the flash — that guests reach without a password now, and what starts on its own as root: new lines in root's own crontab (`crontab -l`), lines that are in it *and* in Unraid's (`/etc/cron.d/root` — Unraid's cron reads both, so they run twice), the office's own schedule there (it belongs in its cron file only — an old copy starts a second backup) — each with the file's time and what the syslog said around it, to find out who wrote it —, the plugins' `.cron` files on the flash, User Scripts and their schedules, jobs in atd's queue that aren't the office's, and Unraid's notification agents. And the **data flow** — who pulls how much: per client and service (SMB, NFS, SSH, the WebGUI's File Manager) what the server sent, SMB's new users and machines and sessions at hours a machine never used, what each container sent (the office's own Kopia upload during its backup and media servers streaming are expected), what was written into each ZFS share since its snapshot (the pattern of ransomware) and what vanished from a share (deleted or moved away — on ZFS per share, on XFS/btrfs disks per disk; the mover, EmbyCache and the office's own work are expected; with who was connected over SMB, NFS or SSH then, or that it came from the server itself) — learned hour by hour for each of them, told only when far above what is normal at that time of the week (during the first week only clear cases); where Grafana runs with the office's dashboard (the Consultant sets it up), the data flow links its history there. And the **snapshots** — an attacker deletes them first: every round he compares the ZFS snapshots of the awake pools and the btrfs snapshot folders of the awake disks with the round before; what Ms. Snapshotini, the backup engine's retention or a rename removed is expected, everything else is told at once — how many, which datasets, the pool's `zpool history` (who ran `zfs destroy`, and when) and the syslog then —, also a hold released that Ms. Snapshotini didn't release; a pool asleep is compared once it is awake, and *I know, thanks* teaches him a retention of yours. He can't tell which files were read (that needs Samba's audit logging) nor where data goes on the internet (that needs the kernel's connection accounting, off on Unraid) — he switches neither on. Each goes into his watch book, to the team lead and, the important ones, to Unraid's notifications (per kind at most once an hour). *I know, thanks* makes it the new normal; what gets safer becomes normal by itself. He keeps no log lines (only the few around a crontab's change, cleaned of tokens), no text of `go` or of notification agents and no passwords — only addresses, names and fingerprints. He never changes a crontab: where something can be undone, the entry has the command for Unraid's terminal. Not Fix Common Problems' checks (a root password, plugins Community Applications doesn't know, the FTP server once it has users): his tips are what it doesn't look at, the watch book only what changed. Read only; nothing wakes a disk. |
| 🧹 **Ms. Dustdevil** | Clears away what nobody uses any more: Docker templates without a container, Compose stacks without containers, appdata folders nothing names (container mounts, templates, stacks, compose files, VMs, any file on the flash), what deleted VMs left behind (folders in `domains`, NVRAM files, TPM states, snapshot lists, disk images in `isos` no VM uses) switched-off User Scripts that lie around (pointing to paths that are gone, or unused since the reboot), stray `my-*.xml` outside Unraid's folder (take over or put away; backups are left out), VMs whose disks are gone (pointed out, removed in Unraid) and Docker's leftovers (dangling and unused images, volumes without a container, the build cache). And she straightens what hangs crooked: **missing pictures** — containers that show a question mark on Unraid's Docker page and Dashboard (none set, or one Unraid can't load) get a logo, found on your server (pictures in Unraid's `dockerMan/images` named like the app; Apple's Time Machine logo for Time Machine containers), in Community Applications on your server, in a table of well-known images (a database in an app's stack gets its database's logo) or guessed from the image's name and checked — addresses of the open [dashboard-icons](https://github.com/homarr-labs/dashboard-icons) collection, nothing is bundled. Or you upload your own: your browser turns it into a square PNG of at most 256 × 256 pixels, which she keeps on the flash in `/boot/config/plugins/unraid-secretary-office/icons/`. She sets it in the container's template (shown at once) or in its Compose Manager stack's override file (lasting from the next Compose Up; she never restarts anything), the old file goes into her storeroom first. On Unraid 7.3.2, whose pages reload a missing question.png endlessly, she offers a stand-in. A filter and a CSV export for every room. Nothing is deleted right away: it is renamed into `_UnraidSecretaryOffice-trash` on the same disk (ZFS datasets with `zfs rename`, snapshots included) and can be put back until you empty it. Measures in the background, never wakes a sleeping disk, changes nothing while a backup runs. |
| 💼 **The Consultant** | An external — he knows the tools the office relies on but doesn't make itself: [Fix Common Problems](https://github.com/unraid/fix.common.problems) (checks the server for common mistakes), [Files Viewer](https://github.com/Lazaros-Chalkidis/unraid-filesviewer) (files in the browser — the office has no file browser of its own on purpose), [Kopia](https://kopia.io/) (Mr. Backupsy hands it the offsite copies) and, where Emby, Jellyfin or Plex runs, [Stream Viewer](https://github.com/Lazaros-Chalkidis/unraid-streamviewer) (who watches what) — and, through gritted teeth, [unbalanced](https://github.com/jbrodriguez/unbalance) (moves files between array disks; can get in the way of backups and EmbyCache, so never suggested). Optional, free and self-hosted, the monitoring in the order it is set up: a [Node Exporter](https://github.com/prometheus/node_exporter) (measures the server; its textfile collector reads the folder the office will write its own numbers to, `/mnt/addons/UnraidSecretaryOffice/metrics`), [Prometheus](https://prometheus.io/) (keeps the numbers; a ready `prometheus.yml` to paste) and [Grafana](https://grafana.com/oss/grafana/) (draws them) — [Loki](https://grafana.com/oss/loki/) only named, for later. He tells whether they are there, what they are good for, who in the office needs them, and how to install them by hand — and, if you like, installs them himself: plugins through Unraid's own plugin manager (you see its output), containers by filling in Unraid's own *Add Container* form the way Apps does — you see every field and click Apply yourself; Prometheus gets its `prometheus.yml`, Grafana the Prometheus data source and the office's dashboard (provisioning files, no login, no clicking in Grafana), never over what is there. For Kopia he also sets up the repository (S3-compatible storage or a folder, new or existing) — only after he said plainly that this means typing your storage's keys and the repository password into the page, and you confirmed it: they reach Kopia through RAM only and the office keeps none of them. Whoever wants maximum security sets Kopia up by hand. **Ransomware protection:** before a new repository in S3 he asks your bucket whether it keeps S3 Object Lock and, when it does, offers it (compliance mode, 30 days by default — nothing Kopia uploads can then be deleted before that, not even with your keys; Kopia extends the locks of what it still needs), with the price said plainly; a bucket made without it, he says where providers switch it on; a provider without it (MEGA S4), he says so. For an existing repository he shows whether it uses Object Lock. His guide by hand explains it too: what, why, who offers it, how, and what it costs. At the end a printable **recovery sheet** with everything needed to get the backups back on a new server, made in your browser only — print it, keep it in a safe. Always with a preview first. |
| 👥 **The Team Lead** | Leads the team. Every desk tells him what it needs from the server; he adds what the office as a whole benefits from and lists what is missing — *still to do* (only you can do it, in Unraid), *recommended* (e.g. Fix Common Problems, Files Viewer, notifications by mail or push) and *good to know* (other backup tools, so nothing runs twice by accident) — each with a link into Unraid's web UI. A recommendation or note you already know about you put aside with *I know, thanks*: it moves to *Noted* at the end of his page and stops counting — in his bubble, his picture, at the reception and on the Dashboard tile (kept on the server, for every browser) — and comes back by itself when its situation changes (another version, another container) or when it was sorted out and turns up again; what is *still to do* can't be put aside. Especially helpful on a fresh server. Read only. |

A fresh office has only the team lead. He asks around who would suit your
server (no Emby, no Jack Emby; no ZFS or btrfs, no snapshots) and you hire whom
you need — or let them go again later; their data and settings stay.

More desks can join: each one is a module (see below).

The office speaks English, German, Italian, French and Spanish and follows
Unraid's language (English where it doesn't speak Unraid's); *⋯ → Language*
picks another one for your browser. Adding a language means adding JSON files.

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
  can call it *Office*, *USO* or a name of your own, and have an icon under
  *Settings → User Utilities* instead, as before, or only a button in
  Unraid's header), in Unraid's look and colour theme,
  served by Unraid behind its login. A tile on Unraid's Dashboard shows the
  essentials at a glance: whether the messenger (the agent) is in, the
  Team Lead's traffic light (open points only — what you noted with *I know,
  thanks* doesn't count), and Mr. Backupsy's last and next run (or whether
  he is backing up, checking or on a dry run right now).
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
| The look at the agent every 5 minutes | `…/agent-watch.cron` next to it |
| State, logs, the backup engine's settings | `appdata/UnraidSecretaryOffice/data/` (comes with the array) |
| Mr. Backupsy's packages per app and VM (templates, compose files, database dumps, VM configurations) | the share `UnraidSecretaryOffice`, one folder per desk (`backup/`); their history in that share's snapshots |
| Snapshot mounts for Kopia | `/mnt/addons/UnraidSecretaryOffice/` |
| The office's numbers for Prometheus (a few KB, rewritten every minute) | `/mnt/addons/UnraidSecretaryOffice/metrics/` |

## Notifications

What needs you even when the office isn't open goes to Unraid's
notifications — the bell, and mail or push if you set them up under
*Settings → Notifications* (the Team Lead recommends it). All of them come
as the event *Unraid Secretary Office*:

* **Mr. Backupsy** (the backup engine): a run failed or ended with errors
  (alert) or warnings (warning), or went well (normal — can be switched off
  in his setup); a VM not resumed or started, a container not started,
  Nextcloud stuck in maintenance mode (alert); maintenance mode was already
  on, space running out, Kopia incomplete, an aborted run repaired,
  settings.ini out of date, a backup run skipped because the engine was busy
  — the night before still uploading, the setup or a restore (warning); a new
  folder that stays local until you decide (normal, once per folder).
* **Ms. Snapshotini**: a schedule had problems (warning).
* **Jack Emby**: a real EmbyCache or consolidating run failed or had
  problems (warning) — reports and trial runs stay quiet.
* **The Team Lead**: something new under *Still to do* that has stayed for
  half an hour (warning), once — again only if it was solved and came back,
  never an "all clear". He looks every 30 minutes, also with the office
  closed. A switch on his page turns this off.
* **The Night Watchman**: a login from a new address, a burst of failed logins, a new container with special rights, a
  container newly privileged or on the host, a new plugin or a moved plugin source, a change on the flash (`go`,
  `/boot/extra`, a new user, a changed password, a new SSH key), a share newly open to guests, new lines in root's own
  crontab, jobs that run twice (in both crontabs), the office's schedule in root's crontab, a `.cron` file of no
  installed plugin, a foreign job in atd's queue, a new or changed notification agent, a client or container sending
  far more than usual, much written into a share, much gone from a share, a new user on SMB, snapshots gone that the office
  didn't remove, a hold released not by Ms. Snapshotini (warning) — per kind
  at most once an hour, so a burst gives one message. A switch on his page turns this off (what comes
  meanwhile stays in his watch book).
* **The plugin**: the agent hasn't checked in for more than 10 minutes while
  the array runs (alert), and once it is back (normal).

The Team Lead, Jack Emby and the Night Watchman write in Unraid's language (English where the
office doesn't speak it); the engine and the plugin's look at the agent in English.

## Monitoring

Optional: with a Node Exporter, Prometheus and Grafana on the server (the
Consultant shows how to set them up, in that order), the office's own numbers
go there too. Once a minute the agent writes them as small text files to
`/mnt/addons/UnraidSecretaryOffice/metrics` (RAM, a few KB), which the Node
Exporter's textfile collector serves along with its own: Mr. Backupsy's last
run (when it ended, how it went, downtime, duration, Kopia sources, package
sizes), the last one that went well, a run going on now, runs skipped because
the engine was busy; Ms. Snapshotini's snapshots per pool and her plans; the
Team Lead's open points; the night watchman's findings and the data flow (bytes sent per file
service, written per ZFS share); Ms. Protocolli's last
tour, EmbyCache and Ms. Dustdevil's storeroom — all named `uso_…`.
[`monitoring/grafana-dashboard.json`](monitoring/grafana-dashboard.json) is a
ready dashboard (Grafana: *Dashboards → New → Import*, choose the Prometheus
data source). Where a Node Exporter or Prometheus runs, the Team Lead follows
the chain: the numbers are fresh, the Node Exporter reads their folder,
Prometheus answers and fetches the Node Exporter. Change Grafana's default
admin/admin before it can be reached from outside.

## Installation

Requirements: **Unraid 7.3.2 or a newer 7.x** (built and tested on 7.3.2; older versions are
refused at install). **Unraid 8 is not supported** for now — the plugin refuses to install there; support follows
once it is released and tested.

1. In *Apps* (Community Applications) search for **Secretary Office** and
   install it. Or by hand: *Plugins → Install Plugin*, paste
   ```
   https://github.com/vipermark2/UnraidSecretaryOffice/releases/latest/download/unraid-secretary-office.plg
   ```
2. Open it: *Sekretariat* in Unraid's menu bar (between Apps and Tools).
   The Team Lead welcomes you and suggests whom to hire. For backups:
   Mr. Backupsy → *Set up…*, then *Schedule…*. The Team Lead lists what is
   still missing.

The green dot next to *⋯* means the agent checked in within the last 70 seconds.

**Updating:** like any plugin, under *Plugins* (Unraid looks for updates; the
Team Lead also says when a new version is out). Your data stays. While a
backup runs the update refuses — try again when it is done.

**Removing:** *Plugins → Remove*. The code and the schedules go. Your data in
appdata, the share `UnraidSecretaryOffice`, the setting on the flash and
whatever the desks set up on the server (snapshots, Ms. Dustdevil's
storeroom) stay — delete them yourself if you don't want them any more.

## Security

The office is part of Unraid's web UI: only who is logged in to Unraid gets
in, and every change carries Unraid's CSRF token, so other web pages in your
browser can't trigger anything. Whoever is logged in to Unraid is root on the
server anyway — with or without the office — so the office has no PIN or login
of its own: keep Unraid logged in only on devices you trust. What it adds are
previews and confirmations against mistakes: before anything changes it shows
what will happen and asks (a restore or the Kopia setup also wants you to tick
that you have read it).

The agent can do what root can do on the host — that is what it is for — but
it only offers the actions listed in `agent/desks/*.php`.

## Adding a desk

A desk is a set of files; nothing in the core needs to change.

```
agent/desks/<id>.php            what it does on the host
public/desks/<id>/desk.json     {"order": 40, "icon": "🧹", "refresh_after": 300}
                                ("reception_order" places it differently at the reception)
public/desks/<id>/desk.js       her desk in the web UI
public/desks/<id>/desk.css      optional, its rules nested in #sso{ … } like office.css
public/desks/<id>/avatar.svg    optional, her picture (64×64, readable on dark and light; else the emoji)
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

A desk can also tell the team lead what it needs (`'checks' => fn () => [...]`,
built with `finding()` from `agent/lib/house.php`, texts as `check.<id>` and
`check.<id>_how` in its language files).

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

No build step, no dependencies. Work in a git clone (e.g. on the server in
appdata) and put it into the installed plugin with `bash plugin/dev-sync.sh`
(on the host): it copies the working copy into the plugin in RAM — live until
the next reboot or plugin update; the page reads its files on every request,
and the agent restarts itself (after `php -l`) when one of its files changes.

* Agent log: `data/agent.log` (also under ⋯ → Agent log)
* Run the agent by hand for debugging: stop the service
  (`bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/agent.sh stop`),
  then on the host `php /usr/local/emhttp/plugins/unraid-secretary-office/agent/agent.php run`
  — never two agents.
* Tests: on the Unraid host `php tests/run.php` in the clone — the tricky logic
  (cron, snapshot retention, Emby detection, the gather's settings, the
  plugin's cron file; on copies only) and every text in every language. They
  use the clone's `data/` and `public/`, never the plugin's data folder, and
  change nothing on the server.
* The plugin package: `bash plugin/build.sh <version>` builds
  `dist/unraid-secretary-office-<date>.txz` and `dist/unraid-secretary-office.plg`.
  Publishing a release (tag `v<version>`) does the same on GitHub and
  attaches both (`.github/workflows/plugin.yml`).
* The data folder (`DATA_DIR`, by default `appdata/UnraidSecretaryOffice/data`)
  holds runtime state only and is not part of the repository — including
  `unraid-backup/` (the backup engine's settings, state and logs; root only)
  and Jack Emby's `embycache/` and `gather/`.

## Support

The office is free and complete — nothing is locked, now or later. If it is useful to you, a tip
is welcome: the tip jar in the office (the Team Lead's *Tips & pay rise*) or
[PayPal](https://paypal.me/vipermark2); a share goes to helmi1987 (see below). If the tips ever exceed what our work
costs, we give the rest to animal shelters that urgently need financial support. With a tip you
get a **supporter key** — the tip jar says how; it shows the server ID the key is made for.
The key unlocks nothing — it just says thank you: the office stops reminding you of the tip jar
and the Team Lead shows a small thank-you. Backups, restores and every desk work exactly the same
with or without it.

## Thanks

Jack Emby's two tools are the work of **[helmi1987](https://github.com/helmi1987)**:
[EmbyCache](https://github.com/helmi1987/embycache-for-unraid) and
[media-disk-gather](https://github.com/helmi1987/media-disk-gather-for-unraid) ("Consolidate
folders"). They ship with the office in `embycache/` and `gather/`; what we changed is listed at
the top of their READMEs. Thank you, helmi1987 — a share of the tips (the tip jar in the office)
goes to him.

## License

Copyright (c) 2026 Benjamin Mueller

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. It is distributed WITHOUT ANY WARRANTY; see [LICENSE](LICENSE).

**No warranty for your backups either.** Mr. Backupsy and the backup engine do their
best, but a backup can be faulty or incomplete — a dump that failed, a share left
out, a repository that can't be opened any more. You stay responsible for your data
and your backup strategy: test a restore now and then, and keep more than one copy,
for example 3-2-1 (three copies, on two kinds of storage, one of them off site).
