# unraid-backup — Mr. Backupsy's engine

Part of the [Unraid Secretary Office](../README.md): Mr. Backupsy shows and controls this engine in the browser — setting it up, scheduling it, starting and stopping runs, helping with restores. It also works without any web page: User Scripts starts it at night, `setup.sh` sets it up in a terminal.

A nightly backup for Unraid servers. It takes consistent **ZFS/btrfs snapshots** and **database dumps**, puts Nextcloud into **maintenance mode** for that, and — if you want — sends everything encrypted offsite with **Kopia**. Everything specific to your server lives in `settings.ini`, which `setup.sh` writes after asking you. The nightly run `backup.sh` reports every difference between the server and `settings.ini`, but never changes it on its own.

Version **2.13** (4 Oct 2026). The version is in the header of `setup.sh` and `backup.sh`, in `lib/common.sh` (`UB_VERSION`) and in every log.

---

## Requirements

- Unraid 6.12 or newer. Snapshots need pools or array disks on ZFS or btrfs; shares on XFS are backed up without a snapshot ("live").
- The **User Scripts** plugin for the schedule.
- Optional: the **Compose Manager**, when stacks with databases are involved.
- Optional: a **Kopia** container (e.g. `imagegenius/kopia` from Community Apps) for offsite backups.
- `jq`, `flock`, `timeout` and friends come with Unraid; `setup.sh` checks for them.

## Installation

1. Install the office (see the [README](../README.md)); the engine then lives in `/mnt/user/appdata/UnraidSecretaryOffice/backup/`.
2. With Kopia: set up the container and **connect it to the repository once in the KopiaUI** (see [Kopia — optional](#kopia--optional)), plus the [one mapping](#the-kopia-container-the-one-mapping) and PUID/PGID 0.
3. Mr. Backupsy → **Set up…**: he reads the server and proposes everything with reasons; change what you like, then *Apply*. This writes `settings.ini` and creates the User Scripts entry `unraid-secretary-office_backup`.
   In a terminal instead: `/mnt/user/appdata/UnraidSecretaryOffice/backup/setup.sh` — same checks, every step explained.
4. Mr. Backupsy → **Schedule…**: e.g. every night at 02:00. (Or in Settings › User Scripts at the entry `unraid-secretary-office_backup`, Custom `0 2 * * *`.)
5. A **dry run**: Mr. Backupsy → *Back up now… › Dry run*, or in a terminal `UB_DRY_RUN=1 /mnt/user/appdata/UnraidSecretaryOffice/backup/backup.sh`.
6. The first real run: *Back up now…*, or wait for the schedule.

Replacing an existing backup script: `setup.sh` warns when other User Scripts also take snapshots or run Kopia — switch off their schedule there, otherwise both run. Kopia sources whose path no longer exists in the container can be set to "manual"; after a successful new run they can also be deleted (in the terminal, confirmed by typing `DELETE`).

---

## What a run does

```
 1  load settings.ini, take inventory (pools, disks, shares, datasets, containers)
 2  check and report differences (new / renamed / gone / policies / mapping)
 3  Nextcloud → maintenance mode
 4  manifest (images, templates, share configs, settings.ini, differences)
 5  stop apps, then database dumps (checked right away)                 ┐
 6  stop databases → network containers                                │ interruption
 7  ZFS snapshots (atomic per pool), btrfs snapshots (per disk/pool)    │
 8  start containers (databases first, "healthy"), maintenance off      ┘
 9  mount snapshots read-only: /mnt/backup-snapshots/<share>            ┐ only with
10  Kopia backs up each share from its snapshot (Kopia keeps running)   ┘ Kopia
11  unmount, clean up (ZFS d/w/m, btrfs days + emergency brake, dumps, logs), notification
```

If a run dies hard (crash, `kill -9`), the stopped containers and the Nextcloud maintenance mode are noted in `state/`. The next start of `backup.sh` or `setup.sh` restores both and says so. On a normal stop (Mr. Backupsy's *Stop the run*, User Scripts "Abort", SIGTERM) the `trap` does it right away.

## Files

```
/mnt/user/appdata/UnraidSecretaryOffice/
├── backup/                 the engine (in git)
│   ├── setup.sh            check, propose, write settings.ini, set up Kopia
│   ├── backup.sh           the nightly run
│   └── lib/common.sh       shared functions
└── data/unraid-backup/     its data (not in git, root only: 0700)
    ├── settings.ini        written by setup.sh (may be edited by hand)
    ├── state/              lock, status for the office, differences, notes
    ├── logs/               run-*.log per run, check-/dryrun-/setup-*.log, latest.log
    └── dumps/<time>/       database dumps, manifest, libvirt.tar.gz, flash.tar.gz if any
```

On the flash there is only the User Scripts entry (three lines). The dumps live in the data folder, so in the share `appdata`; `backup.sh` warns if that share itself isn't backed up. `UB_DATA` sets another data folder.

---

## Kopia — optional

Without Kopia everything stays on the server: local ZFS/btrfs snapshots and database dumps. That helps against accidental deletion and broken updates, not against fire, theft, a power surge or a dead server. *Set up…* (step 3 in a terminal) asks whether Kopia comes along and explains:

- **What Kopia does:** every night, right after the snapshots, Kopia reads the frozen states under `/mnt/backup-snapshots/<share>` and uploads only what is new. Files are split into blocks and deduplicated (across shares too), compressed if you like.
- **Encryption:** everything is encrypted on the server **before** it is uploaded (AES-256-GCM or ChaCha20-Poly1305, key from the repository password). The storage provider only sees unreadable blocks — neither contents nor file names.
- **Where to:** S3-compatible storage (e.g. Backblaze B2, Wasabi, MEGA S4, Hetzner, your own MinIO/Garage). Kopia also does SFTP, WebDAV, Azure, GCS or local.
- **Versions:** Kopia keeps states by its retention (e.g. 7 daily, 4 weekly, 12 monthly, 3 yearly) and clears away the rest itself.
- **You set up the repository connection once yourself, in the KopiaUI** (Repository › S3: endpoint, bucket, access key, secret key, repository password). The engine deliberately never asks for these: keys and passwords must never end up in scripts, logs or `settings.ini`. Kopia keeps them in its own config.
- **Without the repository password the backup cannot be restored** — not even by the provider. Keep the password separately (password manager and on paper).

Switching it on or off: *Set up…* again. With Kopia off, shares are only `snapshot` or `off`. Switch Kopia on later and the shares that were local are proposed for Kopia again. If `/mnt/backup-snapshots` is missing, it is created. Unraid keeps `/mnt` in RAM; after a reboot Docker creates the folder again when the Kopia container starts.

## The Kopia container: the one mapping

| | |
|---|---|
| Container Path | `/mnt/backup-snapshots` |
| Host Path | `/mnt/backup-snapshots` |
| Access Mode | **Read Only – Slave** |

That is all `unraid-backup` needs. Further mappings are only needed for sources you maintain yourself in the KopiaUI; the setup lists such sources.

### Why "Read Only – Slave" and not just "Read Only"?

The engine starts Kopia itself, right after the snapshots (`docker exec <kopia> kopia snapshot create …`). That Kopia process runs **inside the container that is already running**. A container gets its own view of the mounts when it starts — a copy of that moment. The engine mounts the snapshots only at night, **after** the container started.

- **Read Only:** the container sees `/mnt/backup-snapshots` as it was at start: empty folders. Kopia would back up empty directories; you would have to stop Kopia every night, mount, and start it again.
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

That is why `backup.sh` makes every bind mount (btrfs snapshots, live shares) in a private area first (`/run/unraid-backup-stage`), sets it read-only there and only then moves it to `/mnt/backup-snapshots/<share>`.

Before every source, `backup.sh` checks in the container's `/proc/self/mountinfo` that the mount is really visible there. If not, it skips that source instead of uploading an empty folder. The setup runs a live test: it briefly mounts a tiny tmpfs and checks whether the container sees it.

### Why PUID=0 / PGID=0 in the Kopia template?

Snapshots have to run as **root**, otherwise every file that only its owner may read is missing (Nextcloud data, database folders …). linuxserver-style images such as `imagegenius/kopia`, however, start the Kopia server as `abc` (UID 99). Every Kopia call — even a plain `repository status` — writes to cache and logs. Run as root, it leaves root-owned folders with mode 0700 there, and the server can't open the repository any more:

```
unable to complete ReadDir:/cache/metadata/f7 despite 10 retries: open /cache/metadata/f7: permission denied
```

The fix, once: **PUID = 0, PGID = 0** in the Kopia template. Server and engine then run as the same user, and root can read existing files anyway. The identity in the repository (`user@host`) stays the same — it is stored in the repository config, not tied to the Linux user.

As long as the server doesn't run as root, the engine calls Kopia only as its UID and **starts no snapshots** — it breaks nothing, and the message names the fix.

---

## Shares

A share is the unit you decide about:

| Mode | Meaning |
|---|---|
| `kopia` | local snapshot + Kopia (offsite); Kopia source `/mnt/backup-snapshots/<share>` |
| `snapshot` | local snapshot only |
| `off` | nothing |

What the setup proposes for new shares:

| Share | Proposal | Why |
|---|---|---|
| `system` | off | Docker image/libvirt, recreated anyway (the VM configuration from libvirt.img is archived separately, see `[libvirt]`) |
| `domains` | off | vdisks of running VMs aren't consistent; back up VMs separately |
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

Apps with their own SQLite database (Emby, Jellyfin, Plex, *arr …) have no dump; they are only clean when stopped for the snapshots. Media servers (Emby, Jellyfin, Plex) are still proposed to keep running, because stopping them would cut running streams; their database is then only crash-consistent in the snapshot, which is usually enough for SQLite.

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

`--plan` and `--apply` are the interface of Mr. Backupsy's setup page. Both report their progress in `state/setup-status.json` (`mode`, `result`, `written`, messages with step and level). With `--apply`, dumps and Nextclouds only count when they are in the decisions; old Kopia sources are only set to "manual" with `_retire_sources = yes`, nothing is ever deleted.

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

### Status for other programs

For Mr. Backupsy in the office (and any other page), `backup.sh` writes its state with fixed English keys to `state/`. The texts in the log may change, these files don't; whoever reads them checks `interface` (currently `1`).

| File | Content |
|---|---|
| `status.json` | the running or last finished run (check, dry run, backup): `mode`, `phase`, `result` (`running`, `ok`, `warnings`, `errors`, `failed`, `aborted`), `pid`, times, interruption, differences, the Kopia plan with the current source and the result per source |
| `last-run.json` | the same for the last real backup run |
| `history.jsonl` | one line per real backup run, the last 200 |
| `drift.json` | differences of the last check (`level`, `text`) |
| `setup-plan.json` | the last plan of `setup.sh --plan` |
| `setup-status.json` | progress and messages of `setup.sh --plan` / `--apply` |

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
| Docker volumes of a new container | warning | they live in Docker's folder, not in the backup |
| Kopia policy differs | warning | `setup.sh --kopia` (or *Apply*) aligns it |
| Kopia policy lacks a share's ignore rule | **error** | this share doesn't go to Kopia (otherwise e.g. an excluded huge folder would be uploaded) |
| mapping missing / without slave / server not root | **error** | Kopia is skipped, snapshots and dumps run |

The same message doesn't come every night: it is repeated when something changes, otherwise every `remind_days` days. `state/drift.txt` shows the current state.

---

## settings.ini

| Section / key | Meaning |
|---|---|
| `[general] mount_root` | snapshot folder for Kopia (`/mnt/backup-snapshots`) |
| `view_root` | browse btrfs snapshots: `<view_root>/<disk>` (symlinks) |
| `snap_prefix` | ZFS snapshot prefix (default `unraidbackup-`); only those are cleared away, other tools' snapshots stay untouched |
| `keep_runs`, `keep_logs` | dump folders and logs to keep |
| `keep_mounts` | `yes` = snapshots stay mounted until the next run (then also a User Script "At Stopping of Array" with `backup.sh --unmount`) |
| `[zfs] retention` | `d w m`: daily, weekly, monthly |
| `[btrfs] keep_days`, `min_free_gb`, `snapshot_all` | retention, emergency brake, snapshot all array disks |
| `[drift] ignore`, `remind_days` | share patterns not reported, reminder interval |
| `[docker] stop`, `no_stop`, `known` | `all`/`none`, exceptions, known containers |
| `[flash] mode` | `snapshot` (only /boot on ZFS) / `tar` / `off` |
| `[libvirt] mode` | `tar` (default) = the content of libvirt.img (XML, NVRAM, TPM state of all VMs) as `libvirt.tar.gz` with the dumps / `off` |
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

**Policies per share:** the Kopia policy on `/mnt/backup-snapshots` applies to every share. A share carries in Kopia only what differs (its own ignores, its own retention). Both are in `settings.ini` and set by the setup. The local ZFS retention (`retention`) is separate and only applies on the server.

---

## Restoring

Mr. Backupsy's *Getting things back* shows the commands below ready-made, with your names and paths, and copy buttons.

- **Single files from yesterday:** ZFS under `/mnt/<pool>/<share>/.zfs/snapshot/unraidbackup-…/`, btrfs under `/mnt/btrfs-snap/<disk>/<time>/<share>/`.
- **From Kopia:** KopiaUI › source `/mnt/backup-snapshots/<share>` › snapshot › Restore/Download. The container only sees read-only paths; for a restore straight onto the server, map a writable target for the time being (e.g. `/mnt/user/restore`).
- **MariaDB/MySQL:**
  `zcat dumps/<time>/db/mariadb_<container>_<db>.sql.gz | docker exec -i <container> sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'`
- **Postgres:**
  `zcat dumps/<time>/db/postgres_<container>.sql.gz | docker exec -i <container> psql -U postgres -d postgres`
- **MongoDB:**
  `docker exec -i <container> sh -c 'mongorestore --drop --archive --gzip -u "$MONGO_INITDB_ROOT_USERNAME" -p "$MONGO_INITDB_ROOT_PASSWORD" --authenticationDatabase admin' < dumps/<time>/db/mongodb_<container>.archive.gz`
- **VM configuration:** `dumps/<time>/libvirt.tar.gz` holds what libvirt.img holds (each VM's XML, NVRAM and TPM state). Mr. Backupsy lists the VMs in it and shows the steps.
- **Which image versions ran:** `dumps/<time>/manifest/docker-images.txt`; templates and compose projects lie next to it.

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
