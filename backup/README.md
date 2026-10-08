# unraid-backup — Mr. Backupsy's engine

The nightly backup of the [Unraid Secretary Office](../README.md). Mr. Backupsy shows and controls it in the browser —
setting it up, scheduling it, starting and stopping runs, helping with restores. It also runs without any page: the
office's plugin starts it at night from its cron file, `setup.sh` sets it up in a terminal, and `bash <engine>/backup.sh`
runs it by hand.

Version **2.35** (9 Oct 2026). The version is in the header of `setup.sh` and `backup.sh`, in `lib/common.sh`
(`UB_VERSION`) and in every log.

The first part is for users of the office; the second, [For the terminal and for developers](#for-the-terminal-and-for-developers),
has the details.

# For users

## What a night does

1. VMs you set to *shut down* are asked to shut down and waited for (at most 5 minutes; one that doesn't is paused
   instead), while everything else still runs. Nextcloud goes into maintenance mode.
2. The apps that write into backed-up shares stop; their databases are dumped and checked. Each app and VM gets a fresh
   **package** in the backup place: its template or compose files, the dumps, a VM's XML, UEFI variables and TPM state.
3. VMs set to *freeze* or *pause* are held for the seconds of the snapshot; ZFS and btrfs **snapshots** freeze the shares,
   the packages and the VMs' disks. Then everything starts again — databases first.
4. To your **partner offices** (if any): the ZFS snapshots of what you ticked, only what changed since the last night.
5. **Kopia** (if on) uploads from the snapshots, encrypted: the flash, the apps, then the shares and VMs, the smallest
   first. A first upload of terabytes continues night after night and holds nothing else back.
6. Old snapshots go by your retention; a notification says how it went (failed or warnings always; a good night if you
   like).

Something new since the last setup is never sent to Kopia or stopped unasked: a new folder in a share stays in the local
snapshots only, a new container keeps running, a new VM isn't held, a new share isn't backed up yet — until you decide in
the setup, where it waits marked «new».

## What you decide in *Set up…*

Mr. Backupsy reads the server and proposes everything with reasons; you change what you like and *Apply*.

- **Kopia** on or off (without it everything stays on the server: good against deleting by mistake, not against fire,
  theft or a dead server).
- **Per VM, app and share** a level — *not*, *local*, *local + Kopia*; an app or VM at *local + Kopia* is a Kopia source
  of its own with its own retention. How each VM is held (*freeze*, *pause*, *shutdown*, *none*); which containers keep
  running; the database dumps and Nextcloud's maintenance mode.
- **Retention**: the local ZFS snapshots (days, weeks, months), btrfs days, Kopia's versions; folders Kopia leaves out
  (caches, transcodes, logs — offered, never ticked unasked).
- **The backup place**: a share of its own for the packages, by default `UnraidSecretaryOffice` — you create it in
  Unraid; step 0 says why it shouldn't share appdata's pool and why it wants snapshots.
- **The default** for what is new later: his proposals (new things wait for you), *everything local*, or *everything
  local + Kopia*.
- **Sleeping pools at night**: wake them (as before) or leave them out that night.
- **Partners**: which shares, VMs and the backup place also go to a partner office.

*Schedule…* sets the time of the nightly run. *Set up…* again whenever Mr. Backupsy reports differences; your
decisions are the defaults. Change rules and retention there, never in the KopiaUI: every run compares Kopia with the
settings and reports what was changed by hand.

## Where things land

- **The packages** in the backup place (`<share>/backup/apps/<app>/`, `vms/<vm>/`, `server/`, `flash/`), overwritten
  every night; their history lies in that share's snapshots. A dump that failed never replaces the last good one.
- **The snapshots** on each pool and disk, named `uso-backup-YYYYMMDD-HHMM`; only these are ever cleared away by the
  retention — other tools' snapshots and Ms. Snapshotini's stay.
- **Kopia's copies** in your repository, encrypted on the server before they leave it.
- **A partner's copy** at the partner, under its own retention; you can never delete it there.
- **Settings, state and logs** in the office's data folder, `unraid-backup/` (root only).

## Starting, stopping, getting back

- *Back up now…* starts a full run, one without Kopia, or a dry run (shows the plan, changes nothing); *Tour* checks
  the server against the settings in seconds. *Stop the run* ends a run cleanly: containers come back, mounts go.
- One run at a time: a run that finds the night before still uploading, the setup or a restore busy doesn't start —
  and says so (history, a notification). One run a minute: a second *Back up now* in the same minute is refused.
- Stopping the array ends a run at once and cleanly — nothing pruned, nothing started into the stopping array; Kopia
  continues the next night, and what the run had stopped is started right after the array starts again.
- Getting things back is Mr. Restori's job; Mr. Backupsy's *Getting things back* has the commands by hand
  ([Restoring](#restoring)).

---

# For the terminal and for developers

## Requirements

- **Unraid 7.3.2 or a newer 7.x** (the plugin's minimum); **Unraid 8 is not supported** for now. Snapshots need pools or array disks on ZFS or btrfs; shares on XFS are backed up without a snapshot ("live").
- The office's plugin schedules the nightly run itself (its cron file) — no User Scripts needed.
- Optional: the **Compose Manager**, when stacks with databases are involved.
- Optional: a **Kopia** container (e.g. `imagegenius/kopia` from Community Apps) for offsite backups.
- `jq`, `flock`, `timeout` and friends come with Unraid; `setup.sh` checks for them.

## Installation

1. Install the office (see the [README](../README.md)); the engine then lives in
   `/usr/local/emhttp/plugins/unraid-secretary-office/backup/`. Below, `<engine>` stands for that folder.
2. With Kopia: a Kopia container with [the one mapping](#the-kopia-container-the-one-mapping) and PUID/PGID 0, connected
   to its repository — in the KopiaUI, or by the Consultant (see [Kopia — optional](#kopia--optional)).
3. Mr. Backupsy → *Set up…*, then *Apply* — this writes `settings.ini`. In a terminal instead: `bash <engine>/setup.sh`,
   same checks, every step explained.
4. Mr. Backupsy → *Schedule…* (e.g. every night at 02:00): a line in the plugin's cron file
   `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cron`.
5. A dry run first: *Back up now… › Dry run*, or `UB_DRY_RUN=1 bash <engine>/backup.sh`.

Replacing an existing backup script: `setup.sh` warns when other User Scripts also take snapshots or run Kopia — switch
off their schedule there, otherwise both run. Kopia sources whose path no longer exists in the container can be set to
"manual"; after a successful new run they can also be deleted (in the terminal, confirmed by typing `DELETE`).

---

## What a run does

```
 1  load settings.ini, take inventory (pools, disks, shares, datasets, containers)
 2  check and report differences (new / renamed / gone / policies / mapping)
 3  VMs with prepare = shutdown: shut down and waited for, while everything else still runs
 4  Nextcloud → maintenance mode
 5  packages: per app its templates or compose files and docker inspect, server/ (images, share configs, settings.ini);
    consistent copies of the databases of media servers that keep running
 6  stop apps, then database dumps into the apps' packages (checked right away); ┐
    the app packages are swapped in                                              │
 7  stop databases → network containers; hold the VMs (freeze, pause), their     │ interruption
    packages (XML, NVRAM, TPM state) and server/libvirt.tar.gz, swapped in       │
 8  ZFS snapshots (atomic per pool), btrfs snapshots (per disk/pool) -           │
    they hold this run's packages too (since 2.28, if you choose so, without     │
    the pools that sleep - see Sleeping pools)                                   │
 9  start containers (databases first, "healthy"), maintenance off               ┘
 9b partners (since 2.27): this run's ZFS snapshots of the ticked units to each partner office -
    zfs send through its door (ssh), incremental where both have a snapshot in common
10  mount snapshots read-only: <mount_root>/<share>; the apps' and VMs'  ┐
    own sources joined under <mount_root>/.apps|.vms/<name>              │ only with
11  new folders of the shares stay out of Kopia until you decide;        │ Kopia
    Kopia backs up the flash, the apps, then the shares and the VMs,     ┘
    the smallest first, from the snapshots (Kopia keeps running)
12  unmount, clean up (ZFS d/w/m, btrfs days + emergency brake, logs; once: the run folders of engines before 2.18), notification
```

Only one run at a time: `backup.sh`, `setup.sh`, Mr. Restori's restores and his drill share a lock (`state/lock`). A run that finds it busy — the night before is still uploading to Kopia, the setup is looking at the server — doesn't happen, but is never lost silently: see [When the lock is busy](#when-the-lock-is-busy).

One run a minute (since 2.34): a run's id, its log (`logs/run-YYYYMMDD-HHMM.log`, `check-…`, `dryrun-…`) and its snapshots' name (`uso-backup-YYYYMMDD-HHMM`) carry the minute it started. A second run in the same minute would share them — ZFS refuses an existing snapshot name. So a run whose log of this minute is there already ends right after taking the lock, before anything else: one line `ERROR: a run of this minute was made already (…) - one run a minute: try again in n s` on stderr, exit `1`; the lock's time, `lock-holder.json`, `status.json`, `latest.log`, the history and the snapshots stay as the earlier run left them. Mr. Backupsy doesn't start such a run in the first place (`one_run_a_minute`).

If a run dies hard (crash, `kill -9`), the stopped containers, the Nextcloud maintenance mode and the held VMs are noted in `state/`. The next start of `backup.sh` or `setup.sh` restores them and says so — since 2.25 the containers in the order a run starts them (network containers, then databases, each waited for, then the apps), and a note keeps exactly what didn't come back (Docker or libvirt silent, a container or VM that doesn't start, a Nextcloud whose container doesn't run) for the next start, with a warning naming it. On a normal stop (Mr. Backupsy's *Stop the run*, User Scripts "Abort", SIGTERM) the `trap` does it right away. When **the array is stopped** during a run, the run notices it at once and ends cleanly without starting anything into the stopping array — what it leaves noted comes back right after the array start (`backup.sh --recover`) — see [When the array is stopped](#when-the-array-is-stopped-since-224).

## Files

```
/usr/local/emhttp/plugins/unraid-secretary-office/backup/
                            the engine (in RAM, unpacked at boot; by hand:
                            bash <engine>/backup.sh, its data from DATA_DIR or UB_DATA)
├── setup.sh                check, propose, write settings.ini, set up Kopia
├── backup.sh               the nightly run
└── lib/common.sh           shared functions

/mnt/user/appdata/UnraidSecretaryOffice/
└── data/unraid-backup/     its data (not in git, root only: 0700)
    ├── settings.ini        written by setup.sh (may be edited by hand)
    ├── state/              lock and who holds it, status for the office, differences, new folders, notes
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

Of the engine, the flash holds only its line in the plugin's cron file (and, for partner offices, the pairs' keys the Team Lead keeps in the plugin's `partners/`). The plugin's data folder is `DATA_DIR` in `/boot/config/plugins/unraid-secretary-office/unraid-secretary-office.cfg`; the engine reads it from there. The packages never live in appdata: they go to the backup place (`[general] dumps_share`) — the office's share `UnraidSecretaryOffice` (folder `backup/`), or any other share of its own (folder `unraid-backup/`). Create the share yourself (not on the pool of appdata, SMB export off, best on a ZFS or btrfs pool); the setup proposes it.

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
- **The repository connection is set up once, in the KopiaUI** (Repository › S3: endpoint, bucket, access key, secret key, repository password) — **or by the Consultant** in the office, who asks first and hands the keys to Kopia through RAM only (the office keeps none of them). The engine itself never asks for them: keys and passwords must never end up in scripts, logs or `settings.ini`. Kopia keeps them in its own config.
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
| Order | since 2.25 the flash first, then the apps (small, and what a restore needs first), then the shares and the VMs together, the smallest first — see [The order of the Kopia phase](#the-order-of-the-kopia-phase-since-225) (2.19–2.24: the apps, the shares, the VMs, the flash last) |

Why not `<container path>/appdata/nextcloud` as the source: Kopia merges the ignore rules of the policies on every parent path into a source and anchors them at the source's root — the app would also lose its own subfolder `mariadb/` because appdata leaves out the folder of another app called mariadb. A folder whose name begins with a dot is never taken for a share, so `.apps/…` only inherits the policy on `<mount_root>`, and an app, like a share, carries only what differs. (Not `@app`: Kopia reads a path with `@` and without `:` as `user@host`.)

### The order of the Kopia phase (since 2.25)

Small and important first: **the flash** (a few MB, and what a new server needs first), then **the apps' own sources** in their order (small, and what a restore needs first), then **the shares and the VMs' own sources together, the smallest first**. A first upload of terabytes can take longer than a day; Kopia keeps checkpoints, so each run continues it — and up to 2.24 everything queued behind it (the shares after it in settings.ini's order, the VMs, the flash) waited for days. On 2026-10-07 a 2.3 TB first upload of one share kept the flash, appdata and every VM from Kopia that way.

The size a share or VM source is expected to have comes from what is cheap and reliable: **Kopia's** — the size of its newest complete snapshot (what Kopia read then, its ignore rules applied), or of a **newer checkpoint** (an incomplete snapshot: an upload interrupted or still going on) when that is larger, as a lower bound; one `kopia snapshot list` per run — and the **server's**: ZFS's `referenced` for a share that is a dataset of its own (and its child datasets), the apparent size of a VM's disk files (since 2.26 — what they *are*, not what they take: Kopia reads a sparse vdisk whole, its holes as zeros, so a 1.6 TB vdisk holding 21 GB is 1.6 TB of reading at its first upload; up to 2.25 the allocated blocks put such a VM far too early) — **the larger of the two** when both are known, either alone when only one is. A complete snapshot alone can be stale: a share whose folders were all left out until the setup changed has a tiny complete snapshot while its first real upload of terabytes still goes on in checkpoints — by that size it would go first; ZFS's size says better, and where the server has none (an array share on XFS or btrfs, a folder in a pool's root dataset) the checkpoint does. The server's overestimates a share with big ignored parts, which only moves it later. A source of unknown size (a share in a folder of a pool's root dataset, on btrfs or XFS, never uploaded) goes last; equal sizes and the unknown keep their order (the shares as settings.ini lists them, then the VMs). The run's log names the order once in its plan (`Kopia order:`, `*` = the server's size was the larger, `?` = unknown); `status.json` `kopia.planned` is that order.

The setup offers rules for folders an app rebuilds itself — Plex `Cache/`, `Codecs/`, `Crash Reports/`, `Logs/`, `Updates/`; Jellyfin `cache/`, `transcodes/`, `log/`; Emby `cache/`, `transcoding-temp/`, `logs/`; Immich `thumbs/`, `encoded-video/` (rebuilding those takes hours) — only where the folder exists, never set unasked. It warns on an app's row when Nextcloud's data folder or Immich's `UPLOAD_LOCATION` lies in a share backed up less than the app: database and files would not match after a restore.

### Partner offices (since 2.27)

Two Secretary Offices can be **partners** — never master and slave, never an automatic failover: each keeps a copy of what the other sends, readable (not encrypted at rest — the partner can read it), with its own retention and quota; the sender can never delete anything there. The Team Lead pairs them (two text blocks to copy, a safety code to compare): `data/partner/pairs.json`, a key pair per pair on the flash, and on the receiving side a line in root's `authorized_keys` whose forced command is the office's **door** — a handful of verbs, nothing else. The engine only sends.

**What travels** — ZFS only, no rsync: a unit is one dataset of its own, sent with `zfs send`.

| Unit | Is | Not covered when |
|---|---|---|
| `place` | the backup place's share (`[general] dumps_share`) — the packages, dumps, `server/`, `flash/`: what a new server needs first | the share isn't one dataset of its own (on the test server it lies on an XFS array disk) |
| `share:<name>` | a share that is exactly one ZFS dataset of its own (on nostromo every pool share) | it lies on several pools or disks (`split`), is a folder in a pool's dataset (`not_dataset`), isn't on ZFS (`no_dataset`), or holds child datasets other than its VMs' own ones — `zfs send` without `-R` would leave them behind (`children`) |
| `vm:<name>` | a VM's own dataset (Unraid makes one per VM folder on ZFS) | it shares its dataset (`no_dataset`) or has disks in several datasets (`split`) |

A name travels as one word through the door: letters, digits, `.` `_` `-` (else `name`). A unit also needs this run's snapshot of its dataset (the share or VM is backed up, not `off`; else `not_snapshotted`), and the snapshots must carry the default prefix `uso-backup-` (the door takes nothing else; else `snap_prefix`).

**What the partner agreed to keep (since 2.29).** The partner keeps only the units its Team Lead agreed to (at the pairing, or later: the Team Lead's *Change what <host> sends…* asks through the door, the partner's Team Lead answers *Keep it too* or *No*); what it agreed to is the pair's `send.units` in `pairs.json`. `setup.sh` reads it with the rest of the pair (`partner_pairs_load`): a unit that is a dataset of its own but that no partner agreed to keep gets `partner_ok: false`, `partner_why: not_agreed` in the plan and a hint to ask at the Team Lead — its key (`partner = <id>`, `partner_place = <id>`) stays as decided, settings.ini is written as before. The run skips a unit its partner hasn't agreed to as `skipped`, `why: not_agreed` while it makes its plan — before the door is asked (until 2.28 the door refused it as `unit_not_agreed`, counted as refused); once the partner agrees, the unit goes along with the next run without another setup. Without `pairs.json`, or for a pair it doesn't name, nothing is skipped for an agreement — the door decides, as before. The backup place's share (its row in the plan has `place: true`) takes the agreement of the unit `place`, like `place_partner` (since 2.33; before, it looked for `share:<its name>` and said «not agreed» although the run sent it). Since 2.34 the plan's «not agreed» hints carry a `code` and `params` the office translates, and name the server as Unraid does (`/boot/config/ident.cfg`).

**The phase `partner`** — right after the snapshots and the apps' restart, before Kopia (a LAN or tunnel transfer ends in minutes to hours, Kopia's first upload can take days); its status phase is `partner`. Per partner (`[partner "<id>"]`, settings.ini's order):

1. `ping` — no answer within the time (ssh exit 255 — no connection, the host key, the key refused — or `UB_PARTNER_ASK` seconds, default 60): every unit `skipped` with `why: unreachable`. Its array stopped: `array_stopped`. The key or its `known_hosts` missing (`UB_PARTNER_DIR/<id>.key`, `<id>.known`): `no_key`.
2. Per unit — the backup place first, then the shares and VMs by size, the smallest first (ZFS's `referenced`: what `zfs send` moves — a sparse vdisk's holes are no blocks), unknown last:
   - `resume <unit>` — an earlier transfer an array stop or a lost link interrupted left a resume token at the receiver (`zfs recv -s`): it continues first, `zfs send -t <token> | … | ssh 'recv <unit> <snap> -t'` (the snapshot is read from the token, `zfs send -nv -t`; a token of a snapshot gone here can't continue — said, and sent anew).
   - `list <unit>` — what the partner holds; the newest `uso-backup-*` both have is the base of an incremental send. Gone here (the engine's retention destroyed it) but still at the partner: the bookmark `<dataset>#uso-partner-<id>` of the last snapshot sent is the base instead (`zfs send -i <dataset>#uso-partner-<id>`; the run remembers which snapshot that was in `state/partner-sent.json`). Neither — or a door that gives no list (a unit it never received) —: a full send (`"from": null` in the status; the `recv` gets the door's real answer). Already there: done, nothing sent.
   - `recv <unit> <snap> [<from>]` — `zfs send -L -c [-i <base>] <dataset>@<snap> | mbuffer -q -s 128k -m 256M | pv -n -b -i 10 [-L <bytes/s>] | ssh … 'recv …'` (mbuffer when there; pv when there — it counts what left, and keeps `rate_mbit`; never `zfs send -s`, which is `--skip-missing`). The door answers one JSON line on stderr before it reads (`{"ok":true}`, or a refusal) and one after (`{"ok":true,"bytes":n,"seconds":n}` or `{"ok":false,"why":"recv_failed","detail":"…"}`). `need_full` (no base there) → the same snapshot whole right after. `refused_window`, `refused_quota`, `refused_asleep` → `skipped`. Any other refusal, `recv_failed`, `send_failed`, `link_lost` (ssh dropped mid-stream — the next run continues it), `no_answer` → `failed`, a warning each.
   - after success the bookmark `<dataset>#uso-partner-<id>` moves to the snapshot sent (the old one destroyed first). The retention destroys snapshots, never bookmarks.

The ssh call (one place in the code, `partner_ssh_cmd`, so the Team Lead's ping uses the same options): `ssh -i <UB_PARTNER_DIR>/<id>.key -o IdentitiesOnly=yes -o BatchMode=yes -o UserKnownHostsFile=<UB_PARTNER_DIR>/<id>.known -o StrictHostKeyChecking=yes -o Ciphers=aes128-gcm@openssh.com,aes256-gcm@openssh.com,chacha20-poly1305@openssh.com -o Compression=no -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=4 -p <port> root@<address> '<verb> …'`.

**Warnings:** a partner unreachable is skipped quietly the first nights; the third night in a row (`UB_PARTNER_NIGHTS`) one warning, again at most once a day while it lasts (`state/partner-skips.json` counts the nights per partner; an answer starts them anew). `refused_quota` and `refused_window` (the partner's owner's settings): a warning at most once a day per partner. `refused_asleep`: a line in the log. A failed transfer: a warning each. Warnings make the run's result `warnings` and appear in its notification (a line «To <partner>: …» in its report). **The array stop** ends the phase like Kopia's: SIGTERM to the whole pipe (`zfs send`, mbuffer, pv, the ssh client); the receiver's `zfs recv -s` keeps what came as a resume token — `partner.interrupted`, the units not done `skipped` (`array_stopping`; a stop by hand: `signal`), never failed. A dry run names the plan (`Partner: <name> <- …`) and sends nothing; a check doesn't look at partners.

**The receiver** (the door, not the engine) keeps the copies under `<pool>/UnraidSecretaryOffice-partners/<pair id>/<unit with : → ->`, never mounted, never a share — see the office's partner plan.

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
| share without data (only in Unraid's config) | kopia | "still empty"; folders that appear later wait for your decision (only local until then, since 2.21) |
| everything else | kopia (or snapshot without Kopia) | |

In a share's container folders (e.g. `appdata`) the setup proposes to ignore the Kopia container's own folder. On request it shows the biggest folders, so you can exclude caches, blockchains or media. Each folder is measured for at most 60 seconds; what takes longer is listed first as "> 60 s", as that is almost always a very big folder.

In the share table, "Location" names pools and sums up several array disks, e.g. `cache + 3 disks`.

### New things stay local (since 2.21)

New things are backed up **only locally and without stopping**, on their own — Kopia, and stopping or pausing, only once you decide in Mr. Backupsy's setup (or in `setup.sh` in a terminal). Say you install a Bitcoin node and it fills `appdata/bitcoin` with hundreds of GB: the next night it is in the local snapshot, but nothing of it goes up to Kopia until you say so.

- **Folders:** when the setup is applied, every share that goes to Kopia records its top-level folders as `kopia_known` — the first time all that are there (and not left out), afterwards what was known plus what you send to Kopia. A top-level folder that is neither known nor left out (the share's `kopia_ignore`, the global `[kopia] ignore`, the folder of an app or VM with a Kopia source of its own, the backup place's own folder) is **new**: right before the upload the run looks at what Kopia is about to read (the mounted snapshot) and leaves the new folders out of the share's policy (`/<folder>/`, one rule per base for a split share; a character Kopia reads as a pattern becomes `?`). Once you decide, the setup records it — *local + Kopia* as `kopia_known`, *only local* as `kopia_ignore` — and the run takes its own rule away again (also when the folder is gone). If Kopia refuses the rule, that share is skipped for the night rather than uploaded unasked.
- **Told once:** the run logs it, writes `status.json` `new_local` and `state/new-local.json`, adds a drift note `new_waiting` (info — it doesn't make a run "with warnings") and sends one notification (normal) for the folders it sees for the first time, not every night. The setup lists them per share as waiting (`setup-plan.json` `shares[].waiting`).
- **Containers:** a container that is not in `[docker] known` (it came after the last setup) keeps running during the run instead of being stopped; the setup proposes it as *keep running*. **VMs** without a `[vm]` section aren't held (as before); the setup proposes `prepare = none` for them.
- **Apps and VMs with a Kopia source of their own** hold only what their `folder =` lines and their package name — nothing new reaches them without the setup.
- A share with very many folders at its top when it is first recorded (more than 500 — films, photos: a new folder there is the collection growing, not a new thing) gets `kopia_known = *`: every folder goes, new ones too, as before. List folders there by hand instead and new ones wait there too.
- **A default for new things** (since 2.31): `[general] preset_new = local` or `kopia` is Mr. Backupsy's *default* — his setup then proposes whatever is new at that level (a new app local and stopped for the snapshot like any, or also to Kopia; a new share over 500 GB or of unknown size still waits under `kopia`), marked «new — default: …». It is a proposal like any other: nothing changes for the run until the setup is applied, and `backup.sh` never reads the key. `setup.sh` in a terminal (also `--yes`) keeps the key but proposes as always — honouring it there is a later step.
- A share **without** any `kopia_known` line works as before 2.21: every folder goes to Kopia (drift note `known_missing`) until the setup is applied once. A share with a sleeping disk gets its first record at a setup when it is awake (the setup never wakes a disk). Only folders count: files at the top of a share go along as before.

### Sleeping pools (since 2.28)

A ZFS snapshot writes to its pool, so a pool whose disks sleep at the run's time is woken by it — every night, for a pool that otherwise sleeps all day (on one server a pool holding Time Machine and film shares, asleep all day, woke at 03:02 every night). The office's own desks never wake a sleeping pool; the engine offers the same choice, `[general] asleep_pools`. «Asleep» means a **rotating** disk Unraid spun down (`spundown="1"` and `rotational` not `"0"` in disks.ini, since 2.30): an SSD in standby wakes in milliseconds and is never left out — a pool of SSDs is always awake for the engine, a mixed pool sleeps when one of its HDDs does.

| Value | What happens |
|---|---|
| `wake` (default; also without the key) | as before: the snapshot wakes the pool, every share is backed up every night |
| `skip` | a pool that sleeps is **left out that night**: no snapshot of its datasets, no retention there (not even a `zfs list` of its snapshots), none of its shares mounted; the run's result stays `ok` — it is your choice |

How the run decides: when it makes its plan — right after the inventory, before anything is shut down, paused or stopped — it reads Unraid's `disks.ini` once (`spundown`; a pool sleeps when **any** of its disks does, an array disk is just itself). Nothing on the pool is asked to find out. Then, for each pool of the snapshot plan that sleeps:

- its datasets leave the snapshot call (the other pools' snapshot stays one atomic call per pool), and its shares are **left out**: a share with any part there — a share split over two pools keeps the snapshot of its awake part, but its Kopia source is skipped as a whole, since half a source would look like deleted files to Kopia;
- every **Kopia source** with a part there is skipped with why `asleep` — the share's, and an app's or VM's own source whose folder lies there (`status.json` `kopia.skipped` and `kopia.skipped_why`: skipped, not failed, not interrupted, and never in `kopia.planned`);
- a **VM** whose disks lie there isn't snapshotted that night, so it is **not** frozen, paused or shut down either (`vms[].done` = `asleep`) — nobody stops a VM for nothing;
- an **app** whose backed-up data all lies there keeps running (if it ran and wrote there, the pool wouldn't sleep) — `asleep.containers`;
- a **partner's** unit there is skipped with why `asleep` (no snapshot to send);
- a **btrfs** disk or pool that sleeps gets the same treatment where btrfs snapshots are planned (`snapshot_all` too): no snapshot, no retention, its browsing link stays as it was;
- a share Kopia reads **live** (XFS) from a disk that sleeps is skipped for Kopia too — reading it would wake the disk.

The retention also leaves alone every other pool and disk that sleeps at plan time (not in the snapshot plan): their snapshots wait for a night they are awake.

Never left out: **the backup place's pool** — the packages and dumps are the point of the run; if it sleeps, the run wakes it as always and says so in the log (`asleep.woken`) — and the pool of the engine's own data folder (the run's log is written there). Packages and dumps are not affected otherwise. The inventory's list of the datasets (one `zfs list` of the filesystems, the pools' mounted metadata) stays as before.

Said where you look: the log has one line per pool («ZFS hive: asleep - left out this run (asleep_pools = skip)»), `status.json` (and `last-run.json`, `history.jsonl`) carries `asleep` — `mode`, `pools`, `shares`, `units` (how many shares), `vms`, `containers`, `sources`, `woken`, `nights` — `state/last-run` a line `asleep=<shares>`, and the notification's summary «…, 3 shares asleep (left out)». With `wake` `asleep` is `null`.

**A share that is never awake at night** would never be backed up — and nobody would know. So `state/asleep.json` counts per share the nights in a row it was left out (two runs on one day count once); the `UB_ASLEEP_NIGHTS`-th night (7) warns, once per stretch (the run ends «with warnings», the text names the share and the nights, code `asleep_long`); a night the share is snapshotted — every night with `wake` — takes it out of the file. A dry run counts no night.

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
- containers that came after the last setup (not in `[docker] known`, since 2.21) — until you decide in the setup.

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
| `shutdown` | shut down cleanly before anything else stops, started again after | the safest, takes minutes; never forced off — if the guest doesn't shut down in 5 minutes it is paused for the snapshot instead |
| `none` | keeps running | its disks are only crash-consistent |

The setup proposes `freeze` where a guest agent answers (or is configured), `pause` otherwise. **Shutdowns come first** (since 2.22): before Nextcloud's maintenance mode and before any app stops, the run asks the VMs to shut down and waits for them while everything else still runs — the apps' interruption (`downtime_s`) never includes a guest that takes minutes or ignores the request (up to 2.21 they began together with stopping the apps, and the apps waited). They share one deadline: each VM gets `UB_VM_SHUTDOWN_TIMEOUT` seconds (300) from its shutdown request, all are watched together — three VMs that ignore the request cost five minutes, not fifteen. A VM still running gets the request again every `UB_VM_SHUTDOWN_RETRY` seconds (60): Windows swallows the first ACPI power button event while idle with the display off. Whichever isn't off by the deadline keeps running and is paused right before the snapshots (never forced off); one that went off meanwhile stays off and is started again like the others. Freezing and pausing come right before the snapshots, and each VM is released right after the snapshot that holds its disks (ZFS first, btrfs after). A run stopped while a VM is going down waits for it (until its deadline) and starts it again; a run killed in between (`state/vms`) is undone by the next start, like stopped containers. In `status.json` `vms`, `seconds` is how long a VM was held: a shut-down VM from its request until it is started again, a frozen or paused one from the freeze or pause. Each VM's package (`vms/<vm>/`: XML, NVRAM, TPM state) and the archive of all of libvirt.img (`server/libvirt.tar.gz`) are written after the VMs are held, so they match their disks in the snapshot.

**A VM's first upload to Kopia reads its disk files whole** — the empty part of a sparse vdisk as zeros: a 1.6 TB virtual disk holding 21 GB is 1.6 TB of reading, once (later runs skip a file that didn't change; a new repository starts anew). The local snapshots don't mind. Since 2.26 the plan (`setup.sh --plan`, `vms[].bytes` = what the files take, `vms[].apparent` = what they are) and the Kopia order reckon with it; a smaller virtual disk, or a qcow2 the guest trims, avoids the cost.

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
| `setup.sh --plan` | like `--yes`, but writes nothing: proposals, reasons (as codes), check results, the saved values and what *Apply* would change in settings.ini, to `state/setup-plan.json` (since 2.21 per share also `waiting`: its new folders — `dir`, `bytes`, `first_seen`; since 2.31 `preset_new`, the default for new things — the proposals themselves stay neutral, the office applies it; since 2.32 per share `syslog`: true when it is the folder Unraid's syslog server writes into, every plan — not only the first one's `why syslog`) |
| `setup.sh --apply=<file>` | lay decisions (JSON: settings.ini keys as in the plan → value or list) over the current values, then check, write and align policies like `--yes` |
| `setup.sh --forget` | start anew: `settings.ini`, the office's decisions and the last plan go to `state/reset-<time>/` (asks first, `--yes` doesn't); snapshots, dumps, Kopia and the history stay. Until the next apply `backup.sh` refuses to run. Since 2.27 the `[partner]` sections stay for the next setup (`state/partners-kept.ini`, gone again once settings.ini is written): they are the Team Lead's agreement — only the units ticked for them start anew |

`--plan`, `--apply` and `--forget` are the interface of Mr. Backupsy's setup page. With `--apply`, `kopia_known` in the decisions is taken as it is (a folder that is gone drops out); a share without it that goes to Kopia records all its folders, and a container not named in `docker|known` stays new. They report their progress in `state/setup-status.json` (`mode`, `result`, `written`, messages with step and level). With `--apply`, dumps and Nextclouds only count when they are in the decisions; old Kopia sources are only set to "manual" with `_retire_sources = yes`, nothing is ever deleted.

**Partners (since 2.27).** The `[partner "<id>"]` sections come from the Team Lead's pairs (`data/partner/pairs.json` next to the engine's data folder, `UB_PARTNER_PAIRS`; only pairs this office sends to — with `my_key` — and only in exactly the shape the office writes; `send.rate_mbit` becomes `rate_mbit`); a pair gone there drops its section and its units at the next Apply. Without that file the sections stay as settings.ini (or `--forget`) has them. The units' keys (`[share|vm "<n>"] partner`, `[general] partner_place`) are the user's, from the decisions — lists of ids, like `kopia_ignore`; kept only for a partner that has a section, a unit that is one dataset of its own and that is backed up. `--plan` never connects to a partner and carries `partners` (`[{id, name, address, port, rate_mbit, key: true when <id>.key and <id>.known are there, reachable: null, source: "pairs" | "settings"}]`), per share and per VM `partner` (the ids), `partner_ok`, `partner_why` (`not_dataset`, `split`, `no_dataset`, `children`, `name`, since 2.29 `not_agreed`; `null` when ok — `why` is the proposal's reason) and per share `place` (the backup place's share: its partners are `general|partner_place`), and `place_partner` (`{share, partner, partner_ok, partner_why}`). The office's decisions: `"share|appdata|partner": ["a1b2c3d4"]`, `"vm|Debian|partner": [...]`, `"general|partner_place": [...]` — an empty list unticks.

Environment: `UB_SETUP`, `UB_YES`, `UB_EXPLAIN`, `UB_SIZE_TIMEOUT` (seconds per share for `du`, 0 = don't measure), `UB_SETTINGS`, `UB_STRIPES`, `UB_KNOWN_MAX` (500: more folders at a share's top at its first record = a collection, `kopia_known = *`).

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
| `backup.sh --unmount` / `UB_MODE=unmount` | release all snapshot mounts (the plugin does it at the array stop — see below) |
| `backup.sh --recover` / `UB_MODE=recover` | since 2.25: bring back what an interrupted run left stopped, in maintenance mode or held (the plugin starts it right after the array start — see below) |
| `UB_NC_PREEXISTING=continue` | Nextcloud was already in maintenance mode: back up anyway, leave the mode **on** afterwards |
| `UB_NO_NOTIFY=1` | no Unraid notifications |
| `UB_NOTIFY_STAMP=<file>` | since 2.25: the stamp the engine shares with the office's notifications — Unraid names one `<event>-<second>`, a second one with the same event in that second overwrites it, so all who send as «Unraid Secretary Office» take turns under its flock, each after the second the last ended in (the plugin's `/var/run/unraid-secretary-office/notify.second`; empty outside the plugin: only the run's own guard; tests name their own) |
| `UB_KEEP_LATEST=1` | since 2.25, with `--unmount`: `logs/latest.log` keeps pointing at the last run's log (the plugin's array-stop hook sets it; by hand `--unmount` makes its own `unmount.log` the latest) |
| `UB_ARRAY_STOP=1` | since 2.25, with `--unmount`: the array is being stopped (the plugin's array-stop hook) — never waits for the lock, a mount still busy is detached at once (`umount -l`) |
| `UB_ASLEEP_NIGHTS` | since 2.28: after so many nights in a row left out asleep a share warns once (default 7, see [Sleeping pools](#sleeping-pools-since-228)) |
| `UB_PARTNER_DIR`, `UB_PARTNER_ASK`, `UB_PARTNER_NIGHTS` | since 2.27: the folder of the partners' keys and known_hosts (default the plugin's `partners/` on the flash), seconds for a short question to a partner's door (60), unreachable nights in a row before the warning (3) |
| `backup.sh --about` | name, version, interface, code and data folder as JSON |

Exit codes: `0` done (also with warnings), `1` failed or ended with errors (since 2.34 also: a run of this minute was made already — see «One run a minute» above), `2` unknown option, `3` stopped at once because the array was being stopped (since 2.24, see below), `75` not started because another run holds the lock (see below). `--recover`: `0` done or nothing to do, `1` something stays noted (Docker or libvirt didn't answer, a container or VM didn't start, a Nextcloud's container didn't run — the next run tries again), `3` the array is being stopped (not now), `75` the lock is busy.

### Status for other programs

For Mr. Backupsy in the office (and any other page), `backup.sh` writes its state with fixed English keys to `state/`. The texts in the log may change, these files don't; whoever reads them checks `interface` (currently `1`; 2.18 only added keys).

| File | Content |
|---|---|
| `status.json` | the running or last finished run (check, dry run, backup): `mode`, `phase` (since 2.22 `vm_shutdown` while the VMs shut down, before `maintenance`), `result` (`running`, `ok`, `warnings`, `errors`, `failed`, `aborted`), `message` (why it ended, a code the office translates as `backup.message.<code>` where it knows it: `signal` — stopped by hand —, since 2.24 `array_stopping` — the array was being stopped —, `dumps_<problem>`, `nc_datadir_readable`, `exit <n>`; otherwise the engine's own words), `pid`, times, interruption (`downtime_s`: from stopping the first app until all run again — since 2.22 without the VMs' shutdown, which comes before), differences, the Kopia plan with the current source and the result per source (since 2.19 an app's or VM's own source is named `app:<name>` / `vm:<name>`; `planned` is in the order of the Kopia phase — since 2.25 the flash, the apps, then the shares and VMs by expected size, see [The order of the Kopia phase](#the-order-of-the-kopia-phase-since-225); 2.19–2.24 apps, shares, VMs, flash; since 2.24 `kopia.skipped` — the planned sources an array stop left for the next run, never counted as failed — and `kopia.interrupted` — the source whose upload it interrupted, `null` if none; `kopia.state` is `""` until the run checked Kopia), per VM what the run did (`vms`: `prepare`, `done`, seconds held, `snapshot`), the new folders this run left out of Kopia (`new_local`, since 2.21: `share`, `folder`, `bytes`, `first_seen`; `null` when it didn't look, e.g. no Kopia), and the packages (`packages`, since 2.18: `base`, `written` — false in a dry run —, `written_bytes`, counts `apps`, `vms`, `errors`, `warnings`, `stale`, `kept`, `old_runs` with `old_runs_action` = `removed` / `would_remove` / `kept`, and `list`: per package `kind` (app, vm, flash), `name`, `folder`, `type` (compose, template, container, vm), `result` (ok, warnings, errors, planned, stale), `files`, `bytes`, `kept`, `stale`, `run`). `dump_bytes` is what the run wrote into the packages; since 2.27 `partner` — `null` when the run sends to no partner, else `partners` (`[{id, name}]`), `planned` (`[{id, unit}]` in the phase's order, the units that can't travel last), `current` (`{id, unit, since, bytes}` — `bytes` what left so far, updated every 30 s — or `null`), `done` (`[{id, unit, snap, from, bytes, seconds, mbit, resumed}]`: `from` the base snapshot, `null` = sent whole; `bytes` what left (pv's count, else the door's); `mbit` = bytes × 8 / seconds / 10⁶, one decimal; `resumed` = an interrupted transfer continued), `skipped` (`[{id, unit, why}]`), `failed` (`[{id, unit, why}]`), `interrupted` (`{id, unit}` or `null`) — and the counts `partner_ok`, `partner_failed`, `partner_skipped` at its top |
| `last-run.json` | the same for the last real backup run |
| `history.jsonl` | one line per real backup run, the last 200 — `packages` without `list`, `partner` without `planned` and `current` (since 2.27), `asleep` as in `status.json` (since 2.28); since 2.20 also a skipped backup run (`result` = `skipped`, see below) — whoever computes durations, downtimes or "the last run" from it leaves those out; since 2.24 a run the array stop ended (`result` = `aborted`, `message` = `array_stopping`, `kopia.skipped`) is a run like any — it never pruned anything |
| `skipped.json` | since 2.20: the last run that could not take the lock (any mode, see below) |
| `asleep.json` | since 2.28: per share the nights in a row it was left out because its pool slept — `{"shares": {"<share>": {"nights", "first", "last_day", "warned"}}}`; gone when no share is counting |
| `pruned.json` | since 2.21: the snapshots the engine's retention destroyed, run by run — see [Snapshots the engine removed](#snapshots-the-engine-removed) |
| `new-local.json` | since 2.21: the new folders the last runs left out of Kopia and still wait for a decision — `interface`, `version`, `run`, `time`, `folders`: `share`, `folder`, `bytes` (only where cheap — a ZFS dataset of its own —, else `null`), `first_seen` (unix seconds), `rules` (the ignore rules the run set). Written by real backup runs that reached Kopia; a folder that was decided meanwhile is still listed until the next such run — check it against `settings.ini` (`kopia_known`, `kopia_ignore`) |
| `lock-holder.json` | since 2.20: who holds the lock right now (see below) |
| `drift.json` | differences of the last check (`level`, `text`, since 2.18 `code` and `value` for the messages the office translates: `place_no_history`, `place_not_kopia`, `dumps_<problem>`; since 2.21 `new_waiting` (value `<share>/<folder>`), `known_missing` (value: the shares, comma-separated), `new_container`, `new_database`, `new_nextcloud`, `new_vm` (value: the name)), and per Kopia target whether its policy matches (`policies`: `kind` — `root`, `share`, `flash`, since 2.19 `app`, `vm` —, `share` (for kind share), `name` (since 2.19: the share, app or VM), `path`, `ok`, `skipped`, `differences` with `what`/`item`/`have`/`want`; `null` when not compared) |
| `setup-plan.json` | the last plan of `setup.sh --plan` |
| `setup-status.json` | progress and messages of `setup.sh --plan` / `--apply` (`level`, `step`, `text`; since 2.34 `code` and `params` for the hints the office says in its own words: `not_agreed_share`, `not_agreed_vm`, `not_agreed_place` — `name`, `partner`, `host` —, `place_not_agreed` — `host`; `setup-plan.json`'s `messages` likewise) |
| `partner-sent.json` | since 2.27: per partner and unit the last snapshot it got (`{"<id>": {"<unit>": {"snap", "dataset", "time"}}}`) — with the bookmark the base of the next incremental send once the retention took that snapshot here |
| `partner-skips.json` | since 2.27: per partner the nights in a row it didn't answer (`nights`, `first`, `last_day`) and the day each warning was last said (`warned`, `quota_warned`, `window_warned`) |
| `partners-kept.ini` | since 2.27: the `[partner]` sections `setup.sh --forget` kept for the next setup |

### Snapshots the engine removed

Since 2.21 every real backup run notes in `state/pruned.json` which snapshots its retention destroyed — ZFS (`[zfs] retention`, a share's or VM's own) and btrfs (`[btrfs] keep_days` and the emergency brake) —, so whoever watches the server (the office's night watchman) knows which vanished snapshots were the engine's without guessing or reading logs:

```json
{"interface": 1, "version": "2.21", "updated": 1791328000,
 "runs": [{"run": "20261007-0100", "time": 1791328000,
           "zfs": ["master/appdata@uso-backup-20260930-0100"], "btrfs": ["/mnt/disk4/.btrfs-snap/20260929-0100"]}]}
```

`runs` newest last, one entry per real run that reached its cleanup (empty lists when it removed nothing); the last 30 runs, none older than 30 days (`UB_PRUNED_RUNS`, `UB_PRUNED_DAYS`). Per run at most 1000 names per list (`UB_PRUNED_CAP`), what is left out counted in `zfs_more` / `btrfs_more`. Written as a new file + `mv`, in the root-only state folder. Only what the engine destroyed is listed — its own snapshots, exactly matched (never anything else).

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
| `holder` | a must: `backup` (backup.sh), `setup` (setup.sh), `restore` (Mr. Restori's restore jobs, and his drill with mode `drill`), any other short name for other holders |
| `pid` | a must: the process that holds the lock while it runs (the script itself, not a short-lived child) |
| `started` | unix seconds |
| `mode` | backup.sh: `backup` / `check` / `dryrun` / since 2.25 `recover` (no run — the office doesn't show it as one); setup.sh: `plan` / `apply` / `forget` / `check` / `kopia` / `interactive` / `auto` |
| `what` | what it works on, e.g. the app a restore brings back |
| `run` | backup.sh's run id `YYYYMMDD-HHMM` (setup.sh: its log's) |

A restore job writes e.g. `{"holder": "restore", "what": "nextcloud", "pid": 4711, "started": 1791300000}`. The note is never trusted blindly — the lock itself stays the truth: it counts only while the lock is held and its `pid` lives (for `backup` and `setup`, only while that process runs `backup.sh` / `setup.sh`); a missing, stale or unknown note means holder `other` — unless `status.json` names a run that is `running` and whose `pid` runs `backup.sh` (an engine before 2.20 writes no note). Open the lock without truncating it (`exec 9>>state/lock`, not `9>`): opening must not change it; the holder `touch`es it once it has the lock (the office still reads a run's start from its time).

Stopping: `SIGTERM` to the `pid` in `status.json` (Mr. Backupsy's *Stop the run* does that). The `trap` ends a running Kopia snapshot in the container cleanly (SIGINT), starts the stopped containers, switches maintenance mode off, unmounts and sets `result` to `aborted` (`message` `signal`).

### When the array is stopped (since 2.24)

Unraid writes `fsState="Stopping"` into `/var/local/emhttp/var.ini` the moment an array stop begins — minutes before it shuts down the VMs and Docker, long before it unmounts the pools (where the run's lock, its log and its mounts would hold it up). Up to 2.23 a run noticed nothing until Docker was gone, then failed every Kopia source left, pruned snapshots during the stop and sent an alert. Now it looks at var.ini at its safe points — every phase, before writing packages, before every dump, Kopia source and pruned dataset, while it waits for VMs or containers, and every 5 seconds while Kopia uploads — and when it reads `Stopping` (or `Stopped`) it ends at once:

- the Kopia snapshot going on is **interrupted inside the container** (SIGINT to the `kopia` process itself — ending only the `docker exec` client would leave it running): Kopia saves what it uploaded and a checkpoint, so **the next run continues** where it was; the sources not done are **skipped** (`kopia.skipped`), not failed;
- **no retention** (nothing is pruned during a stop — not in `pruned.json` either), no new snapshot, dump or package (the ones half built go, the last good packages stay);
- **nothing is started into the stopping array**: containers the run stopped stay stopped (Docker stops the rest now), a VM it shut down stays off — both noted in `state/stopped` and `state/vms` like a killed run's; right after the array start `backup.sh --recover` starts what Unraid's autostart didn't and switches Nextcloud's maintenance mode off (since 2.25 — see [Right after the array start](#right-after-the-array-start-since-225); up to 2.24 only the first run after the start did, often the next night). A frozen VM is thawed and a paused one resumed — a held guest can't shut down cleanly when libvirt asks. A Nextcloud whose container still runs leaves maintenance mode at once; one whose container the run stopped stays in it until after the array start (occ needs its container);
- everything of the engine mounted goes — its own mounts, also with `keep_mounts = yes`, and since 2.25 whatever lies under `mount_root`, `view_root` and its staging area, mounted by this run or not (an earlier run's `keep_mounts`, a killed run's); a mount still busy after the normal attempt (a Kopia in the container that didn't end within 60 s) is detached (`umount -l`): it is gone at once, its file system once its last user lets go — Docker ends the container in the stop. The lock and `lock-holder.json` are released — nothing of the run keeps a pool busy. A run, check or dry run that ends normally while the array is being stopped (it was past its last look) does the same on its way out;
- `status.json` and a `history.jsonl` line: `result` `aborted`, `message` `array_stopping`; **one notification, level normal** ("Backup stopped for the array stop — nothing is lost; the next run continues the Kopia upload"), never "errors" or an alert; exit code `3`.

Mounts left between runs (`keep_mounts = yes`, or a run killed hard) would keep a pool busy too: since 2.25 the plugin's `event/stopping` releases them when one of the engine's mounts is there — at most 10 s, never blocking the stop. Who holds the lock decides (`lock-holder.json`): a **live** `backup.sh` run, check or dry run releases everything itself on its way out (above) — it is left to it, after 2 s for it to end (it may be just past its last look at the array). Anyone else — nobody, `setup.sh`, a restore, a `--recover`, a note whose `pid` is gone or isn't `backup.sh`, an orphan of a killed run that inherited the lock (its `docker exec` of Kopia) — mounts nothing there: the hook runs `UB_KEEP_LATEST=1 UB_ARRAY_STOP=1 backup.sh --unmount`, which doesn't wait for the lock and detaches busy mounts at once (`latest.log` stays the last run's).

Between stopping the apps and the snapshots that means no snapshot this night — rather than snapshotting while Unraid is about to stop Docker and unmount the pools. `Started`, and Unraid's "Started, formatting/clearing" (`Formatting`, `Clearing` — a new disk is cleared for hours while the array runs), count as running — for the plugin too (since 2.25: the agent rather than the night shift, the nightly run from the cron file). While the array is being stopped `backup.sh` and `setup.sh` also leave an earlier run's notes alone (`recover_interrupted_run`). `UB_VAR_INI` names another var.ini (tests only).

### Right after the array start (since 2.25)

What a run the array stop ended left behind — containers it had stopped, Nextcloud in maintenance mode, VMs it shut down, noted in `state/stopped`, `state/maintenance`, `state/vms` — would otherwise wait for the next run, often the next night. The plugin's `event/started` looks at those three files (nothing else — emhttp waits for the event) and, when one is there, hands `backup.sh --recover` to atd like the office's other jobs on the host (with the office's mark, so the night watchman knows it). It

- takes the lock **without waiting**: when it is busy, the run (or setup) holding it brings the notes back at its own start — exit `75`, quietly: no `skipped.json`, no notification;
- does nothing while the array is being stopped (exit `3`, the notes stay);
- waits up to `UB_RECOVER_WAIT` seconds (300) for Docker — and libvirt, when a VM is noted and Unraid's VM service is on — to answer, then brings everything back through the same `recover_interrupted_run` a run uses at its start:
  - the containers in the order a run starts them: the network containers first (an app on `--network container:<vpn>` doesn't start before its provider), then the databases, each tier waited for (running, "healthy", at most `UB_RECOVER_READY` s, 120), then the apps — the note lists them in the order the run stopped them, which is the other way round;
  - a Nextcloud's maintenance mode off once its container runs: one the run stopped is started above, one Unraid's autostart is still starting is waited for (at most `UB_NC_RUN_WAIT` s, 120), then occ until it answers. A container that never runs keeps its note (never "done" without it); one that is gone can't be reached at all — its note goes, the warning says to check its maintenance mode by hand;
  - a VM back as the run left it: thawed while it runs, resumed while paused, started while shut off (in any other state, or gone, there is nothing to undo). With Unraid's VM service switched off (`domain.cfg` `SERVICE="disable"`) the VMs' note goes with a line in the log — nothing could start them, and libvirt is not waited for;
  - each note keeps exactly what didn't come back (a service still silent, a container or VM that didn't start, a Nextcloud whose container didn't run) — the next run (or start) tries that again (exit `1`);
  - its notification: «Aborted run repaired» when all came back — level normal when the notes are from a run the array stop ended (`status.json` says `aborted` / `array_stopping`), warning after a crash —, «Aborted run not fully repaired» (warning) naming what didn't;
- is **no run**: it needs no settings.ini and writes no `status.json`, `last-run.json` or `history.jsonl` line (the office keeps showing the run that left the notes; history holds runs that backed something up, or were skipped), `latest.log` stays that run's; its own log is `logs/recover.log`. Its `lock-holder.json` says `"holder": "backup", "mode": "recover"`; a backup run, check or dry run that finds it holding the lock waits for it (up to `UB_RECOVER_LOCK_WAIT`, 900 s) instead of being skipped.

With nothing noted, `backup.sh --recover` does nothing and writes nothing (exit `0`).

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
| new container / new database / new Nextcloud | hint / warning | new containers keep running until you decide (since 2.21; before: stopped), database without a dump |
| new folder in a share that goes to Kopia (since 2.21) | info | only in the local snapshot until you decide; one notification (normal) when first seen |
| a share that goes to Kopia without `kopia_known` (since 2.21) | info | every folder goes, new ones too, until the setup is applied once |
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
| `preset_new` | since 2.31: Mr. Backupsy's default for new things — `auto` (default: his proposals, new things wait for your decision, see [New things stay local](#new-things-stay-local-since-221)) / `local` / `kopia`. Only his setup applies it, to what is new, when you apply; `backup.sh` never reads it, `setup.sh` in a terminal keeps it and proposes as always. Written only when it is not `auto` or was there before; `--forget` puts it aside with settings.ini |
| `asleep_pools` | since 2.28: `wake` (default) = a pool that sleeps at the run's time is woken by its snapshot; `skip` = it is left out that night — see [Sleeping pools](#sleeping-pools-since-228). The setup writes the key only when it is `skip` or was there before |
| `partner_place` | since 2.27: the backup place's dataset goes to this partner too (the id of a `[partner]` section; repeatable) — only when the place is a dataset of its own |
| `keep_mounts` | `yes` = snapshots stay mounted until the next run (the plugin releases them at the array stop with `backup.sh --unmount`, since 2.25; outside the plugin a User Script "At Stopping of Array" does it) |
| `[zfs] retention` | `d w m`: daily, weekly, monthly |
| `[btrfs] keep_days`, `min_free_gb`, `snapshot_all` | retention, emergency brake (at most a tenth of the disk, `0` = off), snapshot all array disks |
| `[drift] ignore`, `remind_days` | share patterns not reported, reminder interval |
| `[docker] stop`, `no_stop`, `known` | `all`/`none`, exceptions, known containers |
| `[flash] mode` | `snapshot` (only /boot on ZFS) / `tar` (archive in `flash/`) / `off` |
| `[vm "<name>"]` | `mode` = `snapshot` / `off` (only for a VM in a dataset of its own), `prepare` = `freeze` / `pause` / `shutdown` / `none` (default `none`), `retention` = own ZFS retention `d w m` (only with a dataset of its own); since 2.19 `kopia`, `folder`, `kopia_retention`, `kopia_ignore` like `[app]`; since 2.27 `partner` = a partner's id its dataset goes to (repeatable) |
| `[partner "<id>"]` | since 2.27, a partner office the run sends to (the id: 8 hex digits, the same on both sides): `name`, `address` (IP or host name), `port` (default 22), `rate_mbit` (at most so many Mbit/s, `0` = unlimited). Written by the setup from the Team Lead's pairs, never secrets — the key and known_hosts are `/boot/config/plugins/unraid-secretary-office/partners/<id>.key` and `<id>.known` (`UB_PARTNER_DIR`). A unit naming a partner without a section is left out (said in the log), never an error |
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
| `kopia_known` | since 2.21: a top-level folder that goes to Kopia, `/<folder>/` (repeatable; `kopia_known =` alone = recorded, none yet; `kopia_known = *` = every folder, new ones too — a collection). Written by the setup; a folder neither known nor ignored is new and stays local until you decide. No line at all: every folder goes (as before 2.21) |
| `exclude_dataset` | child dataset neither snapshotted nor backed up |
| `partner` | since 2.27: the share's dataset goes to this partner too (repeatable) — only a share that is one dataset of its own |
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

Since 2.15 it runs as part of the plugin, from its cron file, on real Unraid 7.3.2 servers. **Not yet checked on real hardware:**
- `mount -t zfs` of snapshots as an overlay layer (a fallback to subfolders is built in),
- btrfs `-r` snapshots on encrypted disks,
- binding `/mnt/user/<share>` (FUSE).

So on every new server: *Set up…*, then a check and a dry run first.

No warranty: a backup can be faulty or incomplete, and you stay responsible for your data and your backup strategy — test a restore now and then and keep more than one copy (e.g. 3-2-1: three copies, two kinds of storage, one off site). See the [license](../README.md#license).

## Versions

- **2.35** – cfg_list readers no longer cut the pipe: a unit kept by two partners is never dropped from a run. Up to 2.34 the run looked up a partner's units through `grep -q` on a pipe; when grep stopped at the match while the list was still being written, the writer died of SIGPIPE and, under `pipefail`, the match counted as none — the unit was skipped for that partner that night (more likely the longer the list). Every reader that stops early now reads the whole list first; no `printf: write error: Broken pipe` lines in the log either. `setup.sh` likewise (a value already there could be added twice).
- **2.34** – One run a minute: a run whose log of this minute is there already ends right after taking the lock (an `ERROR` line, exit `1`), touching nothing of the earlier run — up to 2.33 a second «Back up now» in the same minute failed on the snapshot's name and left two history lines with one id. `setup.sh`: the partners step's «not agreed» hints go out as a `code` with `params`, and name the server as Unraid does (`ident.cfg`), not settings.ini's `[general] server`.
- **2.33** – The backup place's share takes the partner agreement of the unit `place` in the plan; up to 2.32 the setup said «not agreed» on it although the run sent it.
- **2.32** – The plan names Unraid's syslog share every time (`syslog: true` per share), so Mr. Backupsy's default «everything local» keeps it «not backed up» after a setup was applied.
- **2.31** – Mr. Backupsy's default for new things: `[general] preset_new = auto | local | kopia` — written from the setup's decisions, carried by the plan, put aside by `--forget`; only the office applies it, `backup.sh` never reads it. See [New things stay local](#new-things-stay-local-since-221).
- **2.30** – Sleeping pools: only a rotating disk Unraid spun down counts as asleep (`disks.ini` `spundown="1"` and `rotational` not `"0"`, `ub_asleep_load`); an SSD in standby wakes in milliseconds and is never left out, a mixed pool sleeps when one of its HDDs does (Benj, 2026-10-08).
- **2.29** – Partner offices: a unit the partner hasn't agreed to keep (`pairs.json` `send.units`) is `not_agreed` in the plan and skipped by the run before the door is asked.
- **2.28** – Sleeping pools: `[general] asleep_pools = skip` leaves a pool whose disks sleep at the run's time out of that night — no snapshot, no retention, no mount, its VMs not held, its Kopia sources and partner units skipped (why `asleep`); the backup place's pool is always woken; a share left out 7 nights in a row warns once. The default `wake` changes nothing. See [Sleeping pools](#sleeping-pools-since-228).
- **2.27** – Partner offices: the phase `partner` sends this run's ZFS snapshots of the units ticked for a partner through its door — incremental where both have a snapshot (or the bookmark of the last one sent), resumable, rate-limited; unreachable partners warn from the third night, the array stop interrupts it like Kopia's. `status.json` `partner`; the setup proposes the `[partner]` sections from the Team Lead's pairs and says per share and VM whether it can go (`partner_ok`, `partner_why`); `--forget` keeps the sections. Interface 1 (keys added). See [Partner offices](#partner-offices-since-227).
- **2.26** – The Kopia order reckons a VM's own source by its disk files' apparent size — Kopia reads a sparse vdisk whole, its holes as zeros (a 1.6 TB vdisk holding 21 GB was 2 TB of reading at its first upload). `setup.sh --plan` carries both sizes per VM (`vms[].bytes`, `vms[].apparent`).
- **2.25** – `backup.sh --recover`: what a run left stopped, in maintenance mode or held comes back right after the array start (the plugin hands it to atd), not only with the next run; containers come back in a run's order (network, databases, apps — each tier waited for) and a note keeps exactly what didn't, with a warning naming it. At the array stop nothing of the engine stays mounted, whoever holds the lock. The Kopia phase goes small and important first: the flash, the apps, then the shares and VMs by expected size. The engine's notifications take turns with the office's (one stamp in RAM). A flash snapshot that failed is a Kopia source not backed up (it was silently left out). See [Right after the array start](#right-after-the-array-start-since-225) and [The order of the Kopia phase](#the-order-of-the-kopia-phase-since-225).
- **2.24** – The run notices the array being stopped (var.ini `fsState` `Stopping`) at its safe points and every 5 seconds while Kopia uploads, and ends at once, cleanly: Kopia interrupted inside the container (the next run continues), the remaining sources skipped, nothing pruned, snapshotted or dumped, nothing started into the stopping array. Result `aborted`, message `array_stopping`, one normal notification, exit code 3 — up to 2.23 such a run failed every remaining Kopia source and sent an alert. See [When the array is stopped](#when-the-array-is-stopped-since-224).
- **2.23** – The btrfs emergency brake scales with the disk: below `[btrfs] min_free_gb` free — but at most a tenth of the disk, at least 1 GB (`0` switches it off) — the oldest snapshots of the engine go early, each with a warning. A disk with none of ours left to release is a line in the log only (a full disk is what Unraid's own disk warnings are for); before, small disks warned and notified at every run.
- **2.22** – VMs with `prepare = shutdown` go down before anything stops, while the apps still run (one deadline, the request again every 60 s); the apps' interruption (`downtime_s`) no longer includes waiting for a VM. `status.json` has the phase `vm_shutdown`. See [VMs](#vms).
- **2.21** – `state/pruned.json`: the snapshots the retention destroyed, run by run (the last 30 runs within 30 days).
- **2.21** – New things stay local and keep running until you decide: a share that goes to Kopia records its top-level folders (`kopia_known`); a folder nobody decided about stays out of Kopia, said once (log, `status.json` `new_local`, `state/new-local.json`, one notification); containers that came after the last setup keep running, VMs without settings aren't held. See [New things stay local](#new-things-stay-local-since-221).
- **2.20** – Names: what the office makes is called `uso-…` — ZFS snapshots `uso-backup-YYYYMMDD-HHMM` (the old default `unraidbackup-` still counts as the default and ages out), Kopia descriptions `uso-backup <run>`, the Container Path `/uso` shown to new setups. A run that finds the lock busy is never lost silently ([When the lock is busy](#when-the-lock-is-busy)); whoever holds it notes it in `state/lock-holder.json`. VMs with `prepare = shutdown` share one deadline and get the request again every 60 s. An app whose compose services build their own image gets `build/<service>/` in its package.
- **2.19** – Kopia per app and VM: an app or VM at «local + Kopia» is a Kopia source of its own with its own retention, its folders and package joined read-only under `<mount_root>/.apps|.vms/<name>`; the shares leave those parts out. Media servers that keep running get consistent copies of their SQLite databases; the apps' own backups are named in the manifest. See [Kopia per app and VM](#kopia-per-app-and-vm-since-219).
- **2.18** – Packages instead of run folders: per app and per VM a folder in the backup place with its small files, overwritten every run, built aside and swapped in only when complete; `server/` and `flash/` beside them. A failed dump keeps the last good one; the history lies in the backup place's snapshots; packages of apps and VMs left out are never deleted. `keep_runs` is gone. See [The packages](#the-packages).
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
