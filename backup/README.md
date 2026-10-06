# unraid-backup — Mr. Backupsy's engine

Part of the [Unraid Secretary Office](../README.md): Mr. Backupsy shows and controls this engine in the browser — setting it up, scheduling it, starting and stopping runs, helping with restores. It also works without any web page: the office's plugin (or, in the Compose stack, User Scripts) starts it at night, `setup.sh` sets it up in a terminal.

A nightly backup for Unraid servers. It takes consistent **ZFS/btrfs snapshots** and **database dumps**, puts Nextcloud into **maintenance mode** for that, keeps a **package per app and VM** (templates or compose files, dumps, VM configuration) and — if you want — sends everything encrypted offsite with **Kopia**, an app or VM you choose as a Kopia source of its own with its own retention. Everything specific to your server lives in `settings.ini`, which `setup.sh` writes after asking you. The nightly run `backup.sh` reports every difference between the server and `settings.ini`, but never changes it on its own.

Version **2.20** (6 Oct 2026). The version is in the header of `setup.sh` and `backup.sh`, in `lib/common.sh` (`UB_VERSION`) and in every log.

---

## Requirements

- **Unraid 7.3 or newer** (tested on 7.3.2); **Unraid 8 is not supported** for now. Snapshots need pools or array disks on ZFS or btrfs; shares on XFS are backed up without a snapshot ("live").
- The office as a plugin schedules the nightly run itself. Only in the Compose stack the **User Scripts** plugin is needed for that.
- Optional: the **Compose Manager**, when stacks with databases are involved.
- Optional: a **Kopia** container (e.g. `imagegenius/kopia` from Community Apps) for offsite backups.
- `jq`, `flock`, `timeout` and friends come with Unraid; `setup.sh` checks for them.

## Installation

1. Install the office (see the [README](../README.md)); the engine then lives in `/usr/local/emhttp/plugins/unraid-secretary-office/backup/` (in the Compose stack: `/mnt/user/appdata/UnraidSecretaryOffice/backup/`). Below, `<engine>` stands for that folder.
2. With Kopia: set up the container and **connect it to the repository once in the KopiaUI** (see [Kopia — optional](#kopia--optional)), plus the [one mapping](#the-kopia-container-the-one-mapping) and PUID/PGID 0.
3. Mr. Backupsy → **Set up…**: he reads the server and proposes everything with reasons; change what you like, then *Apply*. This writes `settings.ini` (in the Compose stack it also creates the User Scripts entry `unraid-secretary-office_backup`).
   In a terminal instead: `bash <engine>/setup.sh` — same checks, every step explained.
4. Mr. Backupsy → **Schedule…**: e.g. every night at 02:00. The plugin writes it into its cron file `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron` (in the Compose stack: the User Scripts entry, Custom `0 2 * * *`).
5. A **dry run**: Mr. Backupsy → *Back up now… › Dry run*, or in a terminal `UB_DRY_RUN=1 bash <engine>/backup.sh`.
6. The first real run: *Back up now…*, or wait for the schedule.

Replacing an existing backup script: `setup.sh` warns when other User Scripts also take snapshots or run Kopia — switch off their schedule there, otherwise both run. Kopia sources whose path no longer exists in the container can be set to "manual"; after a successful new run they can also be deleted (in the terminal, confirmed by typing `DELETE`).

---

## What a run does

```
 1  load settings.ini, take inventory (pools, disks, shares, datasets, containers)
 2  check and report differences (new / renamed / gone / policies / mapping)
 3  Nextcloud → maintenance mode
 4  packages: per app its templates or compose files and docker inspect, server/ (images, share configs, settings.ini);
    consistent copies of the databases of media servers that keep running
 5  stop apps, then database dumps into the apps' packages (checked right away);   ┐
    the app packages are swapped in                                              │
 6  stop databases → network containers; hold the VMs, their packages (XML,      │ interruption
    NVRAM, TPM state) and server/libvirt.tar.gz, swapped in                       │
 7  ZFS snapshots (atomic per pool), btrfs snapshots (per disk/pool) -           │
    they hold this run's packages too                                            │
 8  start containers (databases first, "healthy"), maintenance off               ┘
 9  mount snapshots read-only: <mount_root>/<share>; the apps' and VMs'  ┐
    own sources joined under <mount_root>/.apps|.vms/<name>               │ only with
10  Kopia backs up the apps, each share, the VMs from the snapshots      ┘ Kopia
    (Kopia keeps running)
11  unmount, clean up (ZFS d/w/m, btrfs days + emergency brake, logs; once: the run folders of engines before 2.18), notification
```

Only one run at a time: `backup.sh`, `setup.sh` and Mr. Restori's restores share a lock (`state/lock`). A run that finds it busy — the night before is still uploading to Kopia, the setup is looking at the server — doesn't happen, but is never lost silently: see [When the lock is busy](#when-the-lock-is-busy).

If a run dies hard (crash, `kill -9`), the stopped containers and the Nextcloud maintenance mode are noted in `state/`. The next start of `backup.sh` or `setup.sh` restores both and says so. On a normal stop (Mr. Backupsy's *Stop the run*, User Scripts "Abort", SIGTERM) the `trap` does it right away.

## Files

```
/usr/local/emhttp/plugins/unraid-secretary-office/backup/
                            the engine (plugin: in RAM, unpacked at boot;
                            Compose stack: appdata/UnraidSecretaryOffice/backup)
├── setup.sh                check, propose, write settings.ini, set up Kopia
├── backup.sh               the nightly run
└── lib/common.sh           shared functions

/mnt/user/appdata/UnraidSecretaryOffice/
└── data/unraid-backup/     its data (not in git, root only: 0700)
    ├── settings.ini        written by setup.sh (may be edited by hand)
    ├── state/              lock and who holds it, status for the office, differences, notes
    └── logs/               run-*.log per run, check-/dryrun-/setup-*.log, latest.log

/mnt/user/UnraidSecretaryOffice/      the office's share: what the desks keep, one folder per desk
└── backup/                 the backup place (root only), overwritten by every run:
    ├── apps/<app>/         manifest.json, my-<name>.xml or compose/ (+ compose-files/, build/), db/ (+ db/sqlite_*.db), nextcloud/
    ├── vms/<vm>/           manifest.json, <vm>.xml, nvram/, tpm/<uuid>/, snapshotdb/
    ├── server/             run.json, settings.ini, shares/, docker lists, libvirt.tar.gz, …
    └── flash/              flash.tar.gz ([flash] mode = tar)

/mnt/addons/UnraidSecretaryOffice/    Unraid's place for add-on mounts (RAM, no data of its own)
├── snapshots/<share>       the snapshots, mounted read-only during a run (mount_root)
├── snapshots/.apps/<app>   an app's own Kopia source: read-only binds of its folders and package (also .vms/<vm>)
└── btrfs-snap/<disk>       symlinks for browsing the btrfs snapshots (view_root)
```

On the flash there is only one line in the plugin's cron file (in the Compose stack: the User Scripts entry, three lines). The plugin's data folder is `DATA_DIR` in `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`; the engine reads it from there. The packages never live in appdata: they go to the backup place (`[general] dumps_share`) — the office's share `UnraidSecretaryOffice` (folder `backup/`), or any other share of its own (folder `unraid-backup/`). Create the share yourself (not on the pool of appdata, SMB export off, best on a ZFS or btrfs pool); the setup proposes it.

### The packages

Every real run writes one package per app and per VM to the backup place — small files only, overwritten each night; the big data stays in the snapshots. The **history** of the packages lies in the snapshots of the backup place's own share: that share takes at least local snapshots (`snapshot`, or `kopia` = local snapshot + Kopia). The setup proposes it so and refuses `off`; a share that can't take snapshots (XFS) is reported (`place_no_history`) — its packages would only hold the newest state. Kopia reads the packages from that share's snapshot like any share.

| Package | Holds |
|---|---|
| `apps/<app>/` | An app is a compose project (named after it) or a single container (named after it); a database container of a project belongs to the project's app. `manifest.json` (per container: image, image id, digests, `docker inspect`; the dumps with the names of the variables they used for user and password, never their values; the files with the run each comes from; since 2.19 `sqlite` — the database copies of a media server, where each database lies and whether the copy is checked — and `own_backups` — the app's own backups, see below), the Unraid template(s) `my-<name>.xml`, or `compose/` (the Compose Manager's project folder, without its logs) and `compose-files/` (compose files and `.env` docker compose read elsewhere, e.g. an "indirect" stack), `build/<service>/` (since 2.20, for services that build their own image: the Dockerfile and the small files at the top of the build context's folder — ≤ 1 MB each, at most 100 files / 10 MB — unless `compose/` holds them already), `db/` (the dumps; `sqlite_<container>_<file>`: a media server's database copies), `nextcloud/` (`config.php`, occ lists) |
| `vms/<vm>/` | `<vm>.xml` (as libvirt keeps it), `nvram/` (UEFI variables, also those of its Unraid VM snapshots), `tpm/<uuid>/` (TPM state), `snapshotdb/` (Unraid's list of its VM snapshots), `manifest.json` (disks with paths, sizes and the snapshot of this run that holds them, the `prepare` used and how the VM was held while the package was written) |
| `server/` | what belongs to no app: `run.json` (which run wrote the packages, the result of each), `settings.ini`, `shares/*.cfg`, `docker-images.txt`, `docker-ps.txt`, ZFS/btrfs lists, `drift.txt`, `libvirt.tar.gz` (all of libvirt.img: every VM, the networks), templates no container uses (`docker-templates/`), Compose Manager projects without a container (`compose/`) |
| `flash/` | `flash.tar.gz` (`[flash] mode = tar`) |

- A package is built in `.ub-stage-<run>/` and swapped in only when complete (the one it replaces goes aside as `.ub-old-<run>-<folder>` for that moment). The app packages are swapped in after the dumps, the VM packages and `server/` while the VMs are held — all before the snapshots. A killed run's leftovers are put right by the next one.
- A dump that **failed or did not run** (the container was stopped) never replaces the last good one: that file comes along (a hard link where possible) and `manifest.json` says from which run it is. The same goes for `libvirt.tar.gz` and `flash.tar.gz`.
- Packages of apps and VMs a run leaves out — gone, `[docker] skip` for all of an app's containers (unless one has a dump), a VM with `mode = off` or whose disks lie only in shares that are `off` — are **never deleted**; status.json lists them as `stale`. Each VM's own configuration is packed whatever `[libvirt] mode` says; that setting only decides about the whole archive in `server/`.
- Root only: folders 0700, files 0600 — templates and `.env` files hold passwords.
- Up to 2.17 every run wrote a folder `<place>/<YYYYMMDD-HHMM>/` and kept `keep_runs` of them. The first 2.18 run removes them once all its packages are in place without errors (only folders named like a run, owned by root, holding nothing but `db/`, `manifest/` and the archives); a dry run lists them. If a dump of that first run fails, its last good dump is taken from the newest of those folders into the package first. Nothing of the engine lies directly in `/mnt`: Fix Common Problems would rightly complain. `UB_DATA` sets another data folder.

---

## Kopia — optional

Without Kopia everything stays on the server: local ZFS/btrfs snapshots and database dumps. That helps against accidental deletion and broken updates, not against fire, theft, a power surge or a dead server. *Set up…* (step 3 in a terminal) asks whether Kopia comes along and explains:

- **What Kopia does:** every night, right after the snapshots, Kopia reads the frozen states under `<mount_root>/<share>` and uploads only what is new. Files are split into blocks and deduplicated (across shares too), compressed if you like.
- **Encryption:** everything is encrypted on the server **before** it is uploaded (AES-256-GCM or ChaCha20-Poly1305, key from the repository password). The storage provider only sees unreadable blocks — neither contents nor file names.
- **Where to:** S3-compatible storage (e.g. Backblaze B2, Wasabi, MEGA S4, Hetzner, your own MinIO/Garage). Kopia also does SFTP, WebDAV, Azure, GCS or local.
- **Versions:** Kopia keeps states by its retention (e.g. 7 daily, 4 weekly, 12 monthly, 3 yearly) and clears away the rest itself.
- **You set up the repository connection once yourself, in the KopiaUI** (Repository › S3: endpoint, bucket, access key, secret key, repository password). The engine deliberately never asks for these: keys and passwords must never end up in scripts, logs or `settings.ini`. Kopia keeps them in its own config.
- **Without the repository password the backup cannot be restored** — not even by the provider. Keep the password separately (password manager and on paper).

Switching it on or off: *Set up…* again. With Kopia off, shares are only `snapshot` or `off`. Switch Kopia on later and the shares that were local are proposed for Kopia again. If `<mount_root>` is missing, it is created. `/mnt/addons` lives in RAM; after a reboot Docker creates the folder again when the Kopia container starts.

## The Kopia container: the one mapping

| | |
|---|---|
| Container Path | `/uso` (any path — Kopia names its sources after it: `/uso/<share>`, `/uso/.apps/<app>`, `/uso/.vms/<vm>`; see below) |
| Host Path | `/mnt/addons/UnraidSecretaryOffice/snapshots` (`mount_root`) |
| Access Mode | **Read Only – Slave** |

That is all `unraid-backup` needs. The engine reads the Container Path from the container's real mapping — `/uso` is only what new setups are shown (up to 2.19: `/backup-snapshots`, which keeps working). **Changing it later makes new Kopia sources:** in the same repository the content is deduplicated (nothing is uploaded twice), but every file is read once more, and the old sources keep their history under the old names until you remove them in Kopia. Up to 2.13 the host path was `/mnt/backup-snapshots`; change only the Host Path in the template, then *Set up… › Apply* — the Container Path and so the Kopia sources and their history stay. Until the template is changed, the setup keeps the old place. Further mappings are only needed for sources you maintain yourself in the KopiaUI; the setup lists such sources.

Each Kopia snapshot the engine makes carries the description `uso-backup <run>` (up to 2.19: `unraid-backup <run>`); Kopia's retention goes by time, not by description.

### Why "Read Only – Slave" and not just "Read Only"?

The engine starts Kopia itself, right after the snapshots (`docker exec <kopia> kopia snapshot create …`). That Kopia process runs **inside the container that is already running**. A container gets its own view of the mounts when it starts — a copy of that moment. The engine mounts the snapshots only at night, **after** the container started.

- **Read Only:** the container sees `<mount_root>` as it was at start: empty folders. Kopia would back up empty directories; you would have to stop Kopia every night, mount, and start it again.
- **Read Only – Slave:** new mounts and unmounts on the host are passed into the running container, in this direction only (host → container). Kopia sees the snapshots at once; the KopiaUI and maintenance keep running undisturbed.

"Read Only" protects the data, "Slave" makes the nightly mounts visible to Kopia. Unraid turns the setting into `-v …:ro,slave`.

Tested with Docker 29, kernel 6.18 and imagegenius/kopia 0.23:

| Case | In the container |
|---|---|
| mount that existed when the container started | read-only, sub-mounts too (Docker ≥ 25, kernel ≥ 5.12) |
| mount added later with `-o ro` (ZFS snapshot, overlay) | visible, read-only |
| bind mount added later, then `remount,ro` | visible, **writable** — the ro doesn't reach the container |
| bind made in a private area, set ro there, then `mount --move` | visible, read-only |
| unmount on the host | gone in the container too |

That is why `backup.sh` makes every bind mount (btrfs snapshots, live shares) in a private area first (`/run/unraid-backup-stage`), sets it read-only there and only then moves it to `<mount_root>/<share>`.

Before every source, `backup.sh` checks in the container's `/proc/self/mountinfo` that the mount is really visible there. If not, it skips that source instead of uploading an empty folder. The setup runs a live test: it briefly mounts a tiny tmpfs and checks whether the container sees it.

### Why PUID=0 / PGID=0 in the Kopia template?

Snapshots have to run as **root**, otherwise every file that only its owner may read is missing (Nextcloud data, database folders …). linuxserver-style images such as `imagegenius/kopia`, however, start the Kopia server as `abc` (UID 99). Every Kopia call — even a plain `repository status` — writes to cache and logs. Run as root, it leaves root-owned folders with mode 0700 there, and the server can't open the repository any more:

```
unable to complete ReadDir:/cache/metadata/f7 despite 10 retries: open /cache/metadata/f7: permission denied
```

The fix, once: **PUID = 0, PGID = 0** in the Kopia template. Server and engine then run as the same user, and root can read existing files anyway. The identity in the repository (`user@host`) stays the same — it is stored in the repository config, not tied to the Linux user.

As long as the server doesn't run as root, the engine calls Kopia only as its UID and **starts no snapshots** — it breaks nothing, and the message names the fix.

### Kopia per app and VM (since 2.19)

An app or VM that Mr. Backupsy's setup sets to **local + Kopia** is a Kopia source of its own, with its own retention:

| | |
|---|---|
| What goes there | its folders (an app's folders in container-data shares like `appdata/<app>`, a VM's folder in `domains`) and its package (`apps/<app>/` or `vms/<vm>/` in the backup place) |
| Kopia source | `<container path>/.apps/<app>` (`.vms/<vm>`), e.g. `/uso/.apps/nextcloud`; inside, every part lies where it lies on the server: `appdata/nextcloud/…`, `UnraidSecretaryOffice/backup/apps/nextcloud/…` |
| How | the shares' snapshots are mounted as usual (also a share that only stays local, when such a part lies in it); the parts are bound read-only from there to `<mount_root>/.apps/<app>/<share>/<path>` — child datasets below a folder one by one. Before Kopia starts, every bind is checked in the container's mountinfo like a share; a part that is empty in the snapshot is left out |
| The shares | leave those parts out of their own sources (rules the engine adds itself, e.g. `/nextcloud/` for appdata, `/backup/apps/nextcloud/` for the backup place) — nothing goes twice, and the app's retention holds. Same repository: what Kopia already has from the share's earlier snapshots is not uploaded again; the share's history stays |
| Retention | `kopia_retention` of the app or VM; without one its source inherits the policy on `<mount_root>` (`[kopia] keep_*`) |
| Order | the apps first (small, and what a restore needs first), then the shares, then the VMs (big disks), the flash last |

Why not `<container path>/appdata/nextcloud` as the source: Kopia merges the ignore rules of the policies on every parent path into a source and anchors them at the source's root — the app would also lose its own subfolder `mariadb/` because appdata leaves out the folder of another app called mariadb. A folder whose name begins with a dot is never taken for a share, so `.apps/…` only inherits the policy on `<mount_root>`, and an app, like a share, carries only what differs. (Not `@app`: Kopia reads a path with `@` and without `:` as `user@host`.)

The setup offers rules for folders an app rebuilds itself — Plex `Cache/`, `Codecs/`, `Crash Reports/`, `Logs/`, `Updates/`; Jellyfin `cache/`, `transcodes/`, `log/`; Emby `cache/`, `transcoding-temp/`, `logs/`; Immich `thumbs/`, `encoded-video/` (rebuilding those takes hours) — only where the folder exists, never set unasked. It warns on an app's row when Nextcloud's data folder or Immich's `UPLOAD_LOCATION` lies in a share backed up less than the app: database and files would not match after a restore.

---

## Shares

A share is the unit you decide about:

| Mode | Meaning |
|---|---|
| `kopia` | local snapshot + Kopia (offsite); Kopia source `<container path>/<share>` |
| `snapshot` | local snapshot only |
| `off` | nothing |

What the setup proposes for new shares:

| Share | Proposal | Why |
|---|---|---|
| `system` | off | Docker image/libvirt, recreated anyway (the VM configuration from libvirt.img is archived separately, see `[libvirt]`) |
| `domains` | snapshot (off without snapshots) | each VM is held for the seconds of the snapshot (see VMs below); read live, the disks of running VMs aren't consistent |
| Time Machine target | off | already holds other computers' backups. Recognised by the SMB export "Yes/Time Machine", by a Time Machine container (image with `timemachine` in its name) that binds the share, or by the name (`timemachine`, `time_machine`, `time-machine`, `time machine`) |
| the Kopia container's working folder | off | cache/tmp |
| container data (`appdata`, or a share with folders of at least three containers), even over 500 GB | kopia | not switched off because of its size. What is big there are usually single folders (caches, blockchains, media) — exclude those right after |
| other shares over 500 GB (or measuring takes too long) | off | decide deliberately |
| size unknown (no ZFS and not measured) | snapshot | nothing big goes offsite unasked — measure (*Measure sizes*) or decide |
| share without data (only in Unraid's config) | kopia | "still empty"; once there is data, it comes along |
| everything else | kopia (or snapshot without Kopia) | |

In a share's container folders (e.g. `appdata`) the setup proposes to ignore the Kopia container's own folder. On request it shows the biggest folders, so you can exclude caches, blockchains or media. Each folder is measured for at most 60 seconds; what takes longer is listed first as "> 60 s", as that is almost always a very big folder.

In the share table, "Location" names pools and sums up several array disks, e.g. `cache + 3 disks`.

### How a share is mounted

| Location | Method | Kopia sees |
|---|---|---|
| one ZFS dataset | snapshot mounted directly, child datasets below it | `<share>/…` |
| folder in the pool's root dataset | root snapshot mounted privately, subfolder bound read-only | `<share>/…` |
| one btrfs disk | disk snapshot `-r`, subfolder bound read-only | `<share>/…` |
| several bases (cache + array, several disks) | overlayfs over all snapshots, pool on top | `<share>/…` |
| several bases **and** child datasets | one subfolder per base (overlayfs shows no sub-mounts) | `<share>/<base>/…` |
| XFS and the like (no snapshots) | `/mnt/user/<share>` live, bound read-only | `<share>/…` |

---

## Containers

For the seconds of the snapshots, every running container that writes into backed-up shares is stopped. Only these keep running:

- Kopia,
- the office's own containers (they only write small files, never half of one),
- containers whose paths lie only in `off` shares or in folders Kopia ignores (stopping them would change nothing),
- containers you explicitly set to "keep running". If such a container writes into backed-up data, the setup marks it: its snapshot is only crash-consistent.

Order: apps first, then the database dumps, then the databases, last the network containers (e.g. a VPN whose network others use). Because the apps are already stopped, nobody writes during the dump: dump and files in the snapshot match — also for apps without a maintenance mode, such as Immich. The apps are stopped that much longer (usually seconds). Starting goes the other way round; databases only once they are "healthy".

Apps with their own SQLite database (Emby, Jellyfin, Plex, *arr …) have no dump; they are only clean when stopped for the snapshots. Media servers (Emby, Jellyfin, Plex) are still proposed to keep running, because stopping them would cut running streams; their database is then only crash-consistent in the snapshot — and Jellyfin's and Plex's own docs say to stop the server for a backup.

So since 2.19 a media server that keeps running gets a **consistent copy of its main databases** in its package: Emby `data/library.db`, `users.db`, `authentication.db`; Jellyfin `data/jellyfin.db` (and `library.db` of older versions); Plex `Plug-in Support/Databases/com.plexapp.plugins.library.db` and `…blobs.db` — found through the container's `/config` bind. The copy is made with SQLite's backup API (`sqlite3 'file:<path>?mode=ro' ".backup '<copy>'"`), read-only and through the very path the server uses (so locks and the WAL index are shared), then checked with `PRAGMA quick_check` (Plex's own SQLite extensions can keep Unraid's `sqlite3` from checking — then the copy is marked "not checked"). It is made while everything still runs, before the apps stop, so it doesn't lengthen the interruption; at most `UB_SQLITE_TIMEOUT` seconds (600) per database. A copy that fails or takes too long is a warning and keeps the last good one; a database that hasn't changed since keeps its last copy too (a hard link — no new blocks in the backup place's snapshots). Never on a sleeping disk. Note: every changed database is a new file each night — the backup place's snapshots keep one copy per night they hold.

**The apps' own backups** are named in the package's manifest (`own_backups`) and in the restore help as a second way: Emby's Backup & Restore plugin (its `BackupDirectory` from `plugins/configurations/MBBackup.xml`), Jellyfin 10.11 and newer (`backups/` in its data folder), Plex's database backups every three days next to its database, Immich `UPLOAD_LOCATION/backups` (daily database dumps, 14 kept). They are only in the backup if their share is.

## VMs

A VM's disks are files in a share (usually `domains`) and so in that share's snapshot — locally; whether they go offsite is the share's mode. What a VM adds is how it is treated while the snapshot is taken (`[vm "<name>"] prepare`):

| prepare | what happens | |
|---|---|---|
| `freeze` | the guest agent (qemu-guest-agent inside the VM) flushes and freezes its file systems for the seconds of the snapshot | the best result, the VM keeps running |
| `pause` | the VM stops for those seconds, no guest agent needed | like pulling the plug, but no write is cut in half |
| `shutdown` | shut down cleanly before, started again after | the safest, takes minutes; never forced off — if the guest doesn't shut down in 5 minutes it is paused instead |
| `none` | keeps running | its disks are only crash-consistent |

The setup proposes `freeze` where a guest agent answers (or is configured), `pause` otherwise. Shutdowns begin together with stopping the apps and share one deadline: each VM gets `UB_VM_SHUTDOWN_TIMEOUT` seconds (300) from its shutdown request, all are watched together — three VMs that ignore the request cost five minutes, not fifteen. A VM still running gets the request again every `UB_VM_SHUTDOWN_RETRY` seconds (60): Windows swallows the first ACPI power button event while idle with the display off. Whichever isn't off by the deadline is paused (never forced off). Freezing and pausing come right before the snapshots, and each VM is released right after the snapshot that holds its disks (ZFS first, btrfs after). A run killed in between (`state/vms`) is undone by the next start, like stopped containers. Each VM's package (`vms/<vm>/`: XML, NVRAM, TPM state) and the archive of all of libvirt.img (`server/libvirt.tar.gz`) are written after the VMs are held, so they match their disks in the snapshot.

On ZFS pools Unraid gives every VM folder a dataset of its own. Such a VM can be left out (`mode = off`) and can keep its snapshots longer or shorter than its share (`retention`). A VM whose disks share a dataset with the share or another VM always goes with the share's snapshot. A disk that is a whole device, or a file on a file system without snapshots, is not held by any snapshot — the setup and the nightly check say so.

## Databases

The setup looks into every container, whether created on its own or from a compose stack. It also reads the Compose Manager's stack files (`/boot/config/plugins/compose.manager/projects/*`, "indirect" stacks too, evaluated with `docker compose config` including `.env`). So it also finds database services of stacks that aren't running.

A database is recognised by three things:

1. **environment variables the server image sets itself** — `PG_MAJOR`, `MARIADB_VERSION`, `MONGO_VERSION`, `REDIS_VERSION` … This also catches renamed or derived images.
2. the **image name** (mariadb, mysql, postgres, pgvecto-rs, vectorchord, mongo, redis …),
3. the **default port** (3306, 5432, 27017, 6379).

Tools like phpMyAdmin, Adminer, pgAdmin or exporters don't count.

| Kind | Proposal |
|---|---|
| MariaDB/MySQL, Postgres, MongoDB | logical dump before the snapshot, checked right away (final line, number of tables, or `mongorestore --dryRun`) |
| Redis, Valkey, KeyDB, Memcached | no dump — caches; what is on disk is in the snapshot |
| InfluxDB, CouchDB, Elasticsearch … | snapshot of the stopped container |
| stack service without a container | warning: start the stack, run the setup again, then the dump is proposed |

The engine reads the credentials for the dumps at run time from the database container's environment (`MARIADB_ROOT_PASSWORD`, `POSTGRES_USER`, `MONGO_INITDB_ROOT_USERNAME` …). `settings.ini` holds no passwords.

The table also shows where the data lives. Marked red: `Volume:<path>!` (a Docker volume in Docker's own folder, not in the backup) and `in the container!` (gone with an image update). New database containers are reported at night as a difference.

Nextcloud containers (with `occ`) go into maintenance mode during dump and snapshot. If maintenance mode was already on, the run stops (`preexisting_maintenance = abort`); with `continue` it backs up anyway and leaves the mode on afterwards.

If several containers serve the same Nextcloud (e.g. `nextcloud-app` and `nextcloud-cron` with a shared `config.php`), the engine recognises them by their `instanceid` as **one** instance: the setup asks about them together, and `backup.sh` switches maintenance mode on and off through the first container only. If `occ` fails to switch it on, it tries three times and writes `occ`'s message to the log.

---

## setup.sh

Usually you never call it yourself: Mr. Backupsy's *Set up…* uses it (`--plan`, `--apply`). In a terminal (SSH or Unraid's web terminal — not User Scripts, which has no input) it explains every step; `UB_EXPLAIN=0` shortens that.

| Call | Effect |
|---|---|
| `setup.sh` | check everything, ask, write, set up Kopia |
| `setup.sh --check` | only check and report (incl. the live test of the mapping) |
| `setup.sh --kopia` | only the Kopia part, with the existing settings.ini (align policies) |
| `setup.sh --yes` | take every proposal without asking (also with `--kopia`) |
| `setup.sh --plan` | like `--yes`, but writes nothing: proposals, reasons (as codes), check results, the saved values and what *Apply* would change in settings.ini, to `state/setup-plan.json` |
| `setup.sh --apply=<file>` | lay decisions (JSON: settings.ini keys as in the plan → value or list) over the current values, then check, write and align policies like `--yes` |
| `setup.sh --forget` | start anew: `settings.ini`, the office's decisions and the last plan go to `state/reset-<time>/` (asks first, `--yes` doesn't); snapshots, dumps, Kopia and the history stay. Until the next apply `backup.sh` refuses to run |

`--plan`, `--apply` and `--forget` are the interface of Mr. Backupsy's setup page. They report their progress in `state/setup-status.json` (`mode`, `result`, `written`, messages with step and level). With `--apply`, dumps and Nextclouds only count when they are in the decisions; old Kopia sources are only set to "manual" with `_retire_sources = yes`, nothing is ever deleted.

Environment: `UB_SETUP`, `UB_YES`, `UB_EXPLAIN`, `UB_SIZE_TIMEOUT` (seconds per share for `du`, 0 = don't measure), `UB_SETTINGS`, `UB_STRIPES`.

In a terminal every step is a coloured bar, tables have an underlined header and every second row is slightly shaded. For the stripes, the setup asks the terminal for its background colour; if it doesn't answer, dark is assumed (as in Unraid's web terminal). `UB_STRIPES=light` or `dark` sets it, `UB_STRIPES=off` switches the stripes off. Logs have no colours.

Run the setup again whenever `backup.sh` reports differences; your existing decisions are the defaults. Renamed shares are recognised by their identity (ZFS GUID or inode), and their settings carry over.

What the setup never does: change container templates, connect Kopia to a repository, ask for credentials, delete snapshots or data. The only exception for deleting is an old Kopia source, when you explicitly confirm it in a terminal with `DELETE`.

## backup.sh

| Call | Effect |
|---|---|
| `backup.sh` | full run |
| `backup.sh --check` / `UB_MODE=check` | only check and report differences |
| `backup.sh --dry-run` / `UB_DRY_RUN=1` | show the plan, change nothing |
| `backup.sh --no-kopia` / `UB_SKIP_KOPIA=1` | dumps and snapshots yes, Kopia no |
| `backup.sh --unmount` / `UB_MODE=unmount` | release all snapshot mounts |
| `UB_NC_PREEXISTING=continue` | Nextcloud was already in maintenance mode: back up anyway, leave the mode **on** afterwards |
| `UB_NO_NOTIFY=1` | no Unraid notifications |
| `backup.sh --about` | name, version, interface, code and data folder as JSON |

Exit codes: `0` done (also with warnings), `1` failed or ended with errors, `2` unknown option, `75` not started because another run holds the lock (see below).

### Status for other programs

For Mr. Backupsy in the office (and any other page), `backup.sh` writes its state with fixed English keys to `state/`. The texts in the log may change, these files don't; whoever reads them checks `interface` (currently `1`; 2.18 only added keys).

| File | Content |
|---|---|
| `status.json` | the running or last finished run (check, dry run, backup): `mode`, `phase`, `result` (`running`, `ok`, `warnings`, `errors`, `failed`, `aborted`), `pid`, times, interruption, differences, the Kopia plan with the current source and the result per source (since 2.19 an app's or VM's own source is named `app:<name>` / `vm:<name>`, in the order apps, shares, VMs, flash), per VM what the run did (`vms`: `prepare`, `done`, seconds held, `snapshot`), and the packages (`packages`, since 2.18: `base`, `written` — false in a dry run —, `written_bytes`, counts `apps`, `vms`, `errors`, `warnings`, `stale`, `kept`, `old_runs` with `old_runs_action` = `removed` / `would_remove` / `kept`, and `list`: per package `kind` (app, vm, flash), `name`, `folder`, `type` (compose, template, container, vm), `result` (ok, warnings, errors, planned, stale), `files`, `bytes`, `kept`, `stale`, `run`). `dump_bytes` is what the run wrote into the packages |
| `last-run.json` | the same for the last real backup run |
| `history.jsonl` | one line per real backup run, the last 200 — `packages` without `list`; since 2.20 also a skipped backup run (`result` = `skipped`, see below) — whoever computes durations, downtimes or "the last run" from it leaves those out |
| `skipped.json` | since 2.20: the last run that could not take the lock (any mode, see below) |
| `lock-holder.json` | since 2.20: who holds the lock right now (see below) |
| `drift.json` | differences of the last check (`level`, `text`, since 2.18 `code` and `value` for the messages the office translates: `place_no_history`, `place_not_kopia`, `dumps_<problem>`), and per Kopia target whether its policy matches (`policies`: `kind` — `root`, `share`, `flash`, since 2.19 `app`, `vm` —, `share` (for kind share), `name` (since 2.19: the share, app or VM), `path`, `ok`, `skipped`, `differences` with `what`/`item`/`have`/`want`; `null` when not compared) |
| `setup-plan.json` | the last plan of `setup.sh --plan` |
| `setup-status.json` | progress and messages of `setup.sh --plan` / `--apply` |

### When the lock is busy

`state/lock` (an `flock`) is held by one run at a time. A run that can't take it — e.g. last night's run with a first Kopia upload of 2 TB is still going at 01:00 — **doesn't start, and leaves everything alone**: it loads no settings, mounts and pauses nothing, and never touches `status.json` (which describes the run going on) or `latest.log`. Instead it says so:

- `state/skipped.json` — the last skipped attempt, any mode: `mode` (`backup`, `check`, `dryrun`), `run` (its own run id), `pid`, `time` (= `started` = `finished`), `result` = `skipped`, `reason` (= `message`) and `holder`: `kind`, `mode`, `what`, `run`, `pid`, `started` of the run holding the lock, and when that is a run of this engine its `phase` and `current` Kopia source (from its `status.json`, read only).
- a line in `history.jsonl`, for a real backup run only — the same object (`errors`, `warnings`, `downtime_s` 0, no Kopia, no packages).
- a notification (level `warning`), for a real backup run only: which run was skipped and why ("the backup run started 2026-10-06 01:00 is still going (uploading to Kopia: appdata)"). A check or dry run is only ever started by hand — by the office, which shows the answer right away, or in a terminal; they just say so on stdout and in `skipped.json`.
- exit code `75` (EX_TEMPFAIL, "try again later") — cron and User Scripts don't care; a caller can tell "busy" from "failed". `setup.sh` answers a busy lock the same way (who holds it, exit 75) but writes no `skipped.json`.

The next scheduled run backs up as usual. `reason` is a code the office translates (`backup.message.<code>`): `skipped_busy_backup`, `skipped_busy_check`, `skipped_busy_dryrun` (a run of this engine, by its mode), `skipped_busy_setup`, `skipped_busy_restore`, `skipped_busy_other` (anybody else, or nobody said who).

**Who holds the lock — `state/lock-holder.json`.** Whoever takes the lock writes this note right after (a new file + `mv`, never by writing into the file) and removes it when done, if the note is still its own (same `pid`):

```json
{"holder": "backup", "mode": "backup", "what": "", "run": "20261006-0100", "pid": 168666, "started": 1791241202, "version": "2.20"}
```

| Key | |
|---|---|
| `holder` | a must: `backup` (backup.sh), `setup` (setup.sh), `restore` (Mr. Restori's restore jobs), any other short name for other holders |
| `pid` | a must: the process that holds the lock while it runs (the script itself, not a short-lived child) |
| `started` | unix seconds |
| `mode` | backup.sh: `backup` / `check` / `dryrun`; setup.sh: `plan` / `apply` / `forget` / `check` / `kopia` / `interactive` / `auto` |
| `what` | what it works on, e.g. the app a restore brings back |
| `run` | backup.sh's run id `YYYYMMDD-HHMM` (setup.sh: its log's) |

A restore job writes e.g. `{"holder": "restore", "what": "nextcloud", "pid": 4711, "started": 1791300000}`. The note is never trusted blindly — the lock itself stays the truth: it counts only while the lock is held and its `pid` lives (for `backup` and `setup`, only while that process runs `backup.sh` / `setup.sh`); a missing, stale or unknown note means holder `other` — unless `status.json` names a run that is `running` and whose `pid` runs `backup.sh` (an engine before 2.20 writes no note). Open the lock without truncating it (`exec 9>>state/lock`, not `9>`): opening must not change it; the holder `touch`es it once it has the lock (the office still reads a run's start from its time).

Stopping: `SIGTERM` to the `pid` in `status.json` (Mr. Backupsy's *Stop the run* does that). The `trap` ends a running Kopia snapshot in the container cleanly (SIGINT), starts the stopped containers, switches maintenance mode off, unmounts and sets `result` to `aborted`.

## What backup.sh reports (and doesn't change itself)

| Difference | Level | Consequence |
|---|---|---|
| new share | warning | not backed up until the setup ran |
| share renamed (same GUID/inode) | warning | the new name is only backed up after the setup |
| share gone | warning (for `off`: hint) | – |
| share only in Unraid's config, but had data | warning | data gone or moved? |
| share was empty at setup and has data now | hint | already backed up; the setup remembers its identity for renames |
| share now also lies elsewhere | hint | taken along automatically (overlay) |
| share can't take snapshots any more (e.g. XFS) | warning | Kopia reads it live |
| new container / new database / new Nextcloud | hint / warning | new containers are stopped, database without a dump |
| backup place doesn't go to Kopia / can't take snapshots | warning | the packages stay local / keep no history |
| Docker volumes of a new container | warning | they live in Docker's folder, not in the backup |
| Kopia policy differs | warning | `setup.sh --kopia` (or *Apply*) aligns it |
| Kopia policy lacks a share's ignore rule | **error** | this share doesn't go to Kopia (otherwise e.g. an excluded huge folder would be uploaded); the same for an app's or VM's own rules. A share's missing rule for an app's folder only differs (that folder goes twice) |
| an app's or VM's own source: a part in a share that takes no snapshot, a VM left out, an app or VM gone | warning / hint | that part is left out; what is left still goes there |
| mapping missing / without slave / server not root | **error** | Kopia is skipped, snapshots and dumps run |

The same message doesn't come every night: it is repeated when something changes, otherwise every `remind_days` days. `state/drift.txt` shows the current state.

---

## settings.ini

| Section / key | Meaning |
|---|---|
| `[general] mount_root` | snapshot folder for Kopia (`/mnt/addons/UnraidSecretaryOffice/snapshots`) |
| `view_root` | browse btrfs snapshots: `<view_root>/<disk>` (symlinks) |
| `snap_prefix` | ZFS snapshot prefix (default `uso-backup-`: snapshots `uso-backup-YYYYMMDD-HHMM`); only those are cleared away, other tools' snapshots stay untouched. The default up to 2.19, `unraidbackup-`, counts as the default: new snapshots get `uso-backup-`, and those named `unraidbackup-YYYYMMDD-HHMM` stay the engine's and age out by the same retention (one series per dataset; *Set up…* proposes writing the new prefix). A prefix of your own stays as it is, and alone. Matched exactly: the prefix, 8 digits, `-`, 4 digits — nothing more. Never `uso-plan-…` (Ms. Snapshotini's schedules) |
| `keep_logs` | logs to keep (`keep_runs`, the run folders of engines before 2.18, is accepted in old files and ignored) |
| `dumps_share` | the backup place's share (the packages, see above); its snapshots keep their history |
| `keep_mounts` | `yes` = snapshots stay mounted until the next run (then also a User Script "At Stopping of Array" with `backup.sh --unmount`) |
| `[zfs] retention` | `d w m`: daily, weekly, monthly |
| `[btrfs] keep_days`, `min_free_gb`, `snapshot_all` | retention, emergency brake, snapshot all array disks |
| `[drift] ignore`, `remind_days` | share patterns not reported, reminder interval |
| `[docker] stop`, `no_stop`, `known` | `all`/`none`, exceptions, known containers |
| `[flash] mode` | `snapshot` (only /boot on ZFS) / `tar` (archive in `flash/`) / `off` |
| `[vm "<name>"]` | `mode` = `snapshot` / `off` (only for a VM in a dataset of its own), `prepare` = `freeze` / `pause` / `shutdown` / `none` (default `none`), `retention` = own ZFS retention `d w m` (only with a dataset of its own); since 2.19 `kopia`, `folder`, `kopia_retention`, `kopia_ignore` like `[app]` |
| `[app "<name>"]` | since 2.19, an app (a compose project or a single container, by its name) as a Kopia source of its own: `kopia` = `yes`, `folder` = `<share>/<folder>` it keeps data in (repeatable), `kopia_retention` = `latest hourly daily weekly monthly annual` (missing = `[kopia] keep_*`), `kopia_ignore` = rule relative to its source, `/<share>/<folder>/…` (repeatable). Written by the setup only for apps at "local + Kopia"; with `--apply` only the decisions count |
| `[libvirt] mode` | `tar` (default) = all of libvirt.img (XML, NVRAM, TPM state of all VMs, networks) as `server/libvirt.tar.gz` / `off`; each VM's package is written either way |
| `[kopia] enabled` | `yes` = Kopia on, `no` = only local snapshots and dumps |
| `[kopia] keep_*`, `compression`, `ignore` | policy on `mount_root`, every share inherits it |
| `[nextcloud "<container>"] preexisting_maintenance` | `abort` / `continue` |
| `[dump "<container>"] type` | `mariadb` / `postgres` / `mongodb` |
| `[share "<name>"] mode` | `kopia` / `snapshot` / `off` |
| `retention` | ZFS retention for this share only |
| `kopia_retention` | Kopia retention for this share only: `latest hourly daily weekly monthly annual` |
| `kopia_ignore` | ignore rule relative to the share (repeatable) |
| `exclude_dataset` | child dataset neither snapshotted nor backed up |
| `method` | `auto` / `live` |

**Policies per share:** the Kopia policy on `<mount_root>` applies to every share. A share carries in Kopia only what differs (its own ignores, its own retention, and since 2.19 the folders of apps and VMs with a source of their own). The same for an app's or VM's own source (`.apps/<app>`, `.vms/<vm>`). Both are in `settings.ini` and set by the setup. The local ZFS retention (`retention`) is separate and only applies on the server.

**Change rules and retention in the office (or `settings.ini` + `setup.sh --kopia`), never in the KopiaUI.** The setup writes them into Kopia; every run compares Kopia with `settings.ini` and reports what was changed there by hand, and the next *Apply* sets it back. Kopia thins out old versions itself, following these policies. Mr. Backupsy shows per share what Kopia leaves out (its own rules and the inherited ones), what it keeps, and whether Kopia is really set up like that.

---

## Restoring

Mr. Backupsy's *Getting things back* shows the commands below ready-made, with your names and paths, and copy buttons. `<place>` is the backup place (e.g. `/mnt/user/UnraidSecretaryOffice/backup`); older states of the packages are in the snapshots of its share (or in Kopia).

- **Single files from yesterday:** ZFS under `/mnt/<pool>/<share>/.zfs/snapshot/uso-backup-…/` (older nights: `unraidbackup-…`), btrfs under `<view_root>/<disk>/<time>/<share>/` (`/mnt/addons/UnraidSecretaryOffice/btrfs-snap`).
- **From Kopia:** KopiaUI › source `<container path>/<share>` (e.g. `/uso/appdata`) › snapshot › Restore/Download. An app or VM with a source of its own: `<container path>/.apps/<app>` (`.vms/<vm>`) — inside, each part where it lies on the server (`appdata/<app>/…`, and its package `<share>/backup/apps/<app>/…`); its states before 2.19 are in the share's source. The container only sees read-only paths; for a restore straight onto the server, map a writable target for the time being (e.g. `/mnt/user/restore`).
- **Databases:** stop the app's other containers first, so only the database container runs (Nextcloud: its maintenance mode instead). The dump overwrites what the database holds. Use the credentials the dump was made with — `manifest.json` of the package names the variables (`dumps[].login`, `user_var`, `password_var`):
  - MariaDB/MySQL (root): `zcat <place>/apps/<app>/db/mariadb_<container>_<db>.sql.gz | docker exec -i <container> sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'` — made with the app's user (no root password set): `-u"$MARIADB_USER" -p"$MARIADB_PASSWORD"` instead (or the `MYSQL_*` variables; `mysql` in old images).
  - Postgres: `zcat <place>/apps/<app>/db/postgres_<container>.sql.gz | docker exec -i <container> sh -c 'PGPASSWORD="${POSTGRES_PASSWORD:-}" exec psql -X -U "${POSTGRES_USER:-postgres}" -d postgres'` — errors like "role … already exists" are harmless.
  - Immich's Postgres: into a fresh database only (all of Immich stopped, its database folder `DB_DATA_LOCATION` moved aside and empty, only the database container started), and through Immich's documented `sed "s/SELECT pg_catalog.set_config('search_path', '', false);/SELECT pg_catalog.set_config('search_path', 'public, pg_catalog', true);/g"` between `zcat` and `psql`, otherwise its vector extensions break; then start the rest. Immich can also restore its own automatic database backups (`UPLOAD_LOCATION/backups`): Administration › Maintenance › Restore database backup.
  - MongoDB: `docker exec -i <container> sh -c 'exec mongorestore --drop --archive --gzip -u "$MONGO_INITDB_ROOT_USERNAME" -p "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin' < <place>/apps/<app>/db/mongodb_<container>.archive.gz`
  - Nextcloud: `occ maintenance:mode --on` before, `--off` after; if the backup is older than what the sync clients have, then `occ maintenance:data-fingerprint` and `occ files:scan --all`, so the clients upload their newer files instead of deleting them. Its data folder should come from the same night's snapshot.
  - A database's folder from a snapshot only starts with the same major version of the image that wrote it (`manifest.json` records image and digest per container); a dump also goes into a newer version.
  - A media server's database copy (`db/sqlite_<container>_<file>`): stop the container, copy it over the database (`manifest.json` → `sqlite[].source`), give it the owner of its folder, remove the `-wal` and `-shm` next to it (they belong to the state being replaced), start the container: `docker stop <c> && cp <place>/apps/<app>/db/sqlite_<c>_library.db <source> && chown --reference=<its folder> <source> && chmod 0644 <source> && rm -f <source>-wal <source>-shm && docker start <c>`. Put back the copies of one server together.
  - The apps' own backups: Emby — the Backup & Restore plugin's page › Restore; Jellyfin 10.11+ — Dashboard › Backups › Restore; Plex — stop it, copy a `com.plexapp.plugins.library.db-<date>` over the database (remove `-wal`/`-shm`), start it; Immich — Administration › Maintenance › Restore database backup.
- **A single VM:** with the VM service running, copy its `nvram/<uuid>_VARS…fd` to `/etc/libvirt/qemu/nvram/` and its `tpm/<uuid>` to `/etc/libvirt/qemu/swtpm/tpm-states/`, then `virsh define <place>/vms/<vm>/<vm>.xml` (and `virsh autostart`). Its disks must be where the XML says; `manifest.json` names the snapshot holding them. Also works for a VM that is gone — its package stays.
- **All VMs at once:** `<place>/server/libvirt.tar.gz` holds what libvirt.img holds; unpack it into the image while the VM service is off.
- **Containers on a new server:** the templates `<place>/apps/*/my-*.xml` to `/boot/config/plugins/dockerMan/templates-user/`, each `compose/` folder to `/boot/config/plugins/compose.manager/projects/<its folder>/` (`manifest.json`: `compose.manager_dir`); which images and digests ran: `manifest.json` per app, `server/docker-images.txt`. The share settings: `server/shares/`.

---

## Tested

Tested in a recreated Unraid environment with:

- real Docker 29 and Docker Compose v5,
- imagegenius/kopia 0.23 (filesystem repository),
- MariaDB 11 (also under its own image name), Postgres 16, MongoDB 7, Redis 7,
- a running and a not-started compose stack,
- a Nextcloud stand-in, a VPN network container and a container with a Docker volume.

Played through with and without Kopia, and switching it on again. Mount propagation, overlayfs, read-only behaviour, stopping with SIGTERM and `kill -9`, renames and tampered policies ran for real; ZFS and btrfs were imitated by stand-in commands. The libvirt archive was restored into a fresh btrfs image (byte-identical, owners and modes kept).

**Not yet checked on real hardware:**
- `mount -t zfs` of snapshots as an overlay layer (a fallback to subfolders is built in),
- btrfs `-r` snapshots on encrypted disks,
- binding `/mnt/user/<share>` (FUSE),
- Unraid's `notify` and the User Scripts call.

So on every new server: *Set up…*, then a check and a dry run first.

## Versions

- **2.20** – Names: what the office makes is called `uso-…` (places keep the long name). ZFS snapshots are `uso-backup-YYYYMMDD-HHMM` (default `snap_prefix`); a settings.ini with the old default `unraidbackup-` counts as the default — new snapshots get the new name, the old ones stay the engine's and age out by the normal retention (matched exactly, never anything looser), and *Set up…* proposes writing the new prefix. A prefix of your own stays as it is. Kopia snapshots are described `uso-backup <run>`; new setups are shown the Container Path `/uso` (the engine always reads the real one). A run that finds the lock busy (the night before still uploading to Kopia, the setup, a restore) is never lost silently: it touches nothing of the run going on and says so in `state/skipped.json`, in `history.jsonl` (`result` `skipped`, reason `skipped_busy_<holder>`) and, for a real backup, in a notification (warning); exit code 75. Whoever holds the lock notes it in `state/lock-holder.json` — backup.sh, setup.sh, and the format is open for Mr. Restori's restores and others. VMs with `prepare = shutdown` share one deadline (the timeout from their shutdown request) instead of waiting one timeout after the other, and get the request again every 60 s while they run (Windows swallows the first one); a VM that refuses the request is paused right away. An app folder that is simply empty in the snapshot is a note in the log, no longer two warnings. An app whose compose services build their own image (`build:`, no registry has it) gets `build/<service>/` in its package: the Dockerfile and the small files of the build context (top level, ≤ 1 MB each, at most 100 files / 10 MB), unless `compose/` holds them already — a rebuild needs them.
- **2.19** – Kopia per app and VM: an app or VM at "local + Kopia" is a Kopia source of its own (`[app|vm "<name>"] kopia = yes`, `folder`, `kopia_retention`, `kopia_ignore`) — its folders and its package, joined read-only under `<mount_root>/.apps|.vms/<name>`, with its own retention; the shares leave those parts out, policies are written by the setup and compared every run (`drift.json` policies of kind `app` / `vm`, with a `name`), the Kopia phase goes apps, shares, VMs, flash. Same repository, so nothing already there is uploaded again; settings.ini without `[app]` sections behaves as before. Media servers that keep running get consistent copies of their SQLite databases in their package (backup API, checked, never on a sleeping disk, the last good copy kept). The apps' own backups (Emby's plugin, Jellyfin, Plex, Immich) are named in the manifest. The setup's plan names per container the media server, Kopia rules to offer for caches, transcodes, logs and thumbnails, and where Nextcloud and Immich keep their files.
- **2.18** – Packages instead of run folders: per app (a compose project or a single container) and per VM a folder in the backup place with its small files — templates or compose files, `docker inspect` with the image digests, the database dumps, Nextcloud's config, the VM's XML, NVRAM and TPM state — overwritten every run, built aside and swapped in only when complete, before the snapshots; `server/` holds what belongs to no app (with `libvirt.tar.gz`), `flash/` the flash archive. A dump that failed or did not run keeps the last good one, and the package says from which run and which credentials made it. The history lies in the snapshots of the backup place's share: the setup proposes it as at least a local snapshot and refuses `off`, the run reports a share that can't take snapshots. Packages of apps and VMs left out are never deleted (`stale`). `keep_runs` is gone (accepted in old files); the first run clears away the old run folders once all its packages are in place. `status.json` and `last-run.json` gain `packages`, `drift.json` items a `code`.
- **2.17** – `setup.sh --forget` starts the setup anew: `settings.ini`, the office's decisions and the last plan go to `state/reset-<time>/`; nothing backed up is touched (Mr. Backupsy: "Forget everything and start anew"). The share `domains` is proposed as a local snapshot (the VMs are held for it since 2.16) instead of off. `[docker] skip` lists apps the user does not want backed up: they keep running like `no_stop`, get no dump, and the office has Kopia leave their folders out. The plan lists the shares each container binds (`containers[].binds`). Datasets in Ms. Dustdevil's storeroom (`_UnraidSecretaryOffice-trash-*`) are never snapshotted.
- **2.16** – VMs: per VM how it is treated for the seconds of the snapshot (freeze through the guest agent, pause, shutdown, or as before none), released right after the snapshot that holds its disks; a VM in a dataset of its own can be left out or keep its own retention. The VM configuration archive is written while the VMs are held. `status.json` lists per VM what the run did (`vms`).
- **2.15** – Part of the office's Unraid plugin too: the engine then lies in RAM (`/usr/local/emhttp/plugins/unraid-secretary-office/backup`) and finds its data through the plugin's `DATA_DIR` (by default still `appdata/UnraidSecretaryOffice/data/unraid-backup`); the plugin's cron file starts the nightly run, so `setup.sh` creates no User Scripts entry there and no longer asks for the User Scripts plugin. In the Compose stack nothing changes.
- **2.14** – `backup.sh` and `setup.sh` are one block that bash reads completely before it starts: updating the engine while a run takes hours no longer breaks that run. `state/drift.json` also says per Kopia target whether its policy matches settings.ini (`policies`, differences as codes); Mr. Backupsy shows it per share together with the rules. Nothing of the engine directly in `/mnt` any more: the snapshots are mounted under `/mnt/addons/UnraidSecretaryOffice/snapshots`, the btrfs view lives in `…/btrfs-snap`; the office's share `UnraidSecretaryOffice` is proposed as the backup place (folder `backup/`), and a changed backup place is moved by the next run. The setup keeps the chosen backup place instead of guessing it again, and adds the global Kopia rule `_UnraidSecretaryOffice-trash*/`: Ms. Dustdevil's storeroom never goes offsite (its contents were backed up under their old path; the local snapshots keep them).
- **2.13** – The engine speaks English: terminal, logs, notifications, settings.ini comments and the code's comments. Log lines are marked `WARNING:` / `ERROR:`; questions in `setup.sh` take `y`/`n` (`j` still counts as yes). Nothing the office reads changed (interface 1).
- **2.12** – When Nextcloud refuses its maintenance mode because others can read its data folder (Unraid resets a share root to 0777 whenever share settings are saved), the run says so and how to fix it; the caretaker checks it during the day. The manifest reads btrfs from the kernel only (`--mounted`, time limit) instead of every device — a busy disk held it up for minutes.
- **2.11** – The User Scripts entry is called `unraid-secretary-office_backup`, like all of the office's entries; the old entry `unraid-backup` moves over with its schedule. The office's containers always keep running during a backup, like Kopia. `setup.sh --plan` also lists what *Apply* would change in settings.ini.
- **2.10** – The VM configuration from libvirt.img (XML, NVRAM, TPM state) goes into an archive with the dumps every night, and so offsite, without backing up the whole `system` share. Default even without an entry in settings.ini.
- **2.9** – New shares of unknown size (no ZFS, not measured) are only proposed locally instead of for Kopia. The default snapshot prefix is `unraidbackup-`. Media servers (Emby, Jellyfin, Plex) are proposed to keep running.
- **2.8** – Apps are stopped before the database dumps, not after: dumps and files in the snapshot match, also for apps without a maintenance mode (Immich & co.).
- **2.7** – `setup.sh --plan` and `--apply=<file>`: Mr. Backupsy's setup doesn't ask in a terminal but gets every proposal with reason codes as JSON and returns the decisions as JSON. The same checking and writing as in the terminal.
- **2.6** – Part of the Unraid Secretary Office: code in `backup/`, settings, state, logs and dumps in `data/unraid-backup/` (0700, `UB_DATA`). `--about` names both folders.
- **2.5** – `backup.sh` writes its state for other programs to `state/` (`status.json`, `last-run.json`, `history.jsonl`, `drift.json`), plus `--about`. Kopia runs in the background so stopping takes effect at once; the snapshot in the container is ended cleanly.
- **2.4** – Fix: when two containers served the same Nextcloud (app + cron, shared `config.php`), the second found the maintenance mode just switched on, took it for "already on" and stopped the run. Both are now recognised as one instance by their `instanceid`. Maintenance mode with up to three attempts, `occ`'s messages go to the log. `setup.sh` is easier to read: steps as bars, tables with a header and stripes, questions highlighted.
- **2.3** – Fix for `gone_by_id: bad array subscript`: a share only in Unraid's config without data broke the rename detection. Time Machine is also recognised by name and by a Time Machine container. Container data like `appdata` is no longer switched off because of its size. The "Location" column is shorter. `backup.sh` no longer reports an empty share that only exists in Unraid's config as gone every night. Folder measuring with a time limit shows "> 60 s" instead of a wrong zero.
- **2.2** – A general version without server-specific carry-overs. Time Machine shares are recognised. Snapshots of other tools stay untouched.
- **2.1** – Kopia optional (with an explanation of encryption, S3 and why you connect it yourself in the KopiaUI). `/mnt/backup-snapshots` is created. Databases are found in containers and compose stacks. MongoDB dumps.
- **2.0** – First version.
