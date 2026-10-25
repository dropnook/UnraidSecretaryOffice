# Scenario states (H2): what each state does, where it is proven

The states of `resilienz/zustaende.md` sections A, C, D, E, F as `state_<id>` / `undo_<id>` (id lower case, `-` → `_`),
sourced by `lib.sh` from `states-*.sh`. Low-level helpers (`ct_*`, `share_*`, `put_*`, `disk_*`, `pool_*`, services, runs)
sit next to them. `apps.sh` (re)makes the demo apps on Tower.

## Day-1 findings (verified on Tower and USOTest3VM, 7.4.0-rc.1, 2026-10-18)

1. **Making a share like Unraid does.** `emcmd` with the ⟦Shares⟧ form's fields does it all: `shareNameOrig=&shareName=<n>&
   shareUseCache=…&shareCachePool=…&shareCachePool2=…&…&cmdEditShare=Add Share` writes `/boot/config/shares/<n>.cfg`
   (Unraid's full field set) and `shares.ini` follows within a second. **Change**: the same with `shareNameOrig=<n>` and
   `cmdEditShare=Apply`. **Remove**: `shareNameOrig=<n>&shareName=<n>&cmdEditShare=Delete` removes the cfg, the shares.ini
   entry and the empty roots on every disk (a ZFS dataset too); a share with files is refused (HTTP 500, nothing removed).
   Where the root goes: `only` on a ZFS pool → a dataset `cache/<n>` at once, and 7.4 makes it **exclusive** by itself
   (symlink in /mnt/user) when no other disk has a root; `yes` → an empty folder on disk1 (!), the pool part appears
   (as a dataset on ZFS, made by shfs) with the first file written through /mnt/user. **7.4's emhttpd refuses every
   name outside `[A-Za-z0-9._-]`** («invalid share name», HTTP 500) — «Fotos Ärger» exists only as a hand-made top folder
   (shares.ini lists it at once, `hasCfg="no"`); an `Apply` on that name afterwards is accepted and writes its cfg.
   shfs refuses `mkdir /mnt/user/<new>` (EINVAL): the hand-made root goes on a disk/pool directly. → `share_make`.
2. **Old files for Moverelli.** Birth (`stat %W`) is kept by xfs, zfs and btrfs (all three show it). Setting the clock
   back for the moment of creation works on all three: ntpd stopped, `date -s @<now−40 d>`, files written, clock put back
   from `/proc/uptime` arithmetic (bash only — **Unraid has no `bc`**; a failed restore once left Tower 40 days back for
   4 s, hence the restore runs from an EXIT trap), ntpd started. Window ≈ 0.25 s for 3 files; `W`, `Z` and `Y` all old
   (`put_files … --age 40d` → `stat -c '%W %Y'` = now − 40 d on cache/zfs, disk1/xfs, disk2/zfs, USOTest3VM cache+disk1/btrfs,
   disk2/xfs). The window is held per batch (`put_files` refuses a batch above 4 GiB with `--age`). Caveat for the product:
   a file Unraid's mover moved to another disk is born again there (its birth is the move).
3. **Disk sleep in a VM: not possible.** `emcmd cmdSpindown=disk1` logs «spinning down /dev/sdb», `hdparm -y` is
   accepted, but QEMU's emulated SATA disk answers «active/idle» to every CHECK POWER MODE, so emhttpd keeps
   `spundown="0"`, `color="green-on"`; `mdcmd spindown 1` fails (EINVAL). A hand edit of disks.ini lasts < 10 s (emhttpd
   rewrites it every ~10 s). Nothing can be faked honestly for the office's sleeping branch → **DK-ASLEEP-* need a real
   rotating disk passed through (`needs hdd`)**. `disk_sleep` asks emhttpd and returns 2 when `spundown` doesn't become 1
   within 15 s. What a VM can check honestly: «nothing read the disk» by its I/O counters (`disk_reads <n>` =
   `/sys/block/<dev>/stat` reads) — for INV-NO-WAKE's other half, not for the asleep branch.
4. **Pool fill.** ZFS: `zfs create -o mountpoint=none -o refreservation=<avail − 200 MiB> cache/uso-fill` — instant, writes
   nothing, no share appears (no mountpoint); `zfs list` used/avail (what poollevel.php and Smart Mover read), `df` on every
   dataset, shares.ini `free` and disks.ini `fsFree` (≈ 10 s later) all show the pool full. btrfs (USOTest3VM cache):
   `fallocate -l <avail − 200 MiB> /mnt/cache/uso-fill.bin` — 0.02 s, `df`/statfs (what poollevel reads for btrfs/xfs)
   and disks.ini show 200 MiB free. xfs the same (`disk_fill 1`). A file in the pool's top folder is no share.

Other facts that bite: **both VMs are called «Tower»** (hostname and ident.cfg) — hosts go by IP (H1's `host_is`).
Tower has no `/var/lib/docker/unraid-autostart` (nothing starts by itself after Docker or the array restarts — every
undo that restarts Docker or the array starts again exactly what ran before). Unraid's own
`dynamix.docker.manager/scripts/rebuild_container <name>` re-creates a container from its template (no pull; leaves it
stopped unless in autostart) — the states' «recreate» uses it for templated containers.

Found while building (worth a scenario of their own):
- **Share cfgs are CRLF** (`/boot/config/shares/*.cfg`, share.cfg); docker/domain/dynamix.cfg are LF. Readers strip `\r`.
- **7.4 renames a share in its form** (`shareNameOrig=a&shareName=b&cmdEditShare=Apply`): `mv /mnt/user/a /mnt/user/b`,
  a ZFS dataset renamed with its snapshots, the cfg renamed — the real SH-RENAMED.
- **Unraid can't delete a ZFS share that has snapshots**: «Delete» unmounts, `zfs destroy` fails (HTTP 500), the
  dataset stays unmounted; a second «Delete» removes the cfg and leaves the unmounted dataset with its snapshots —
  with the engine's `@uso-backup-*` snapshots a user can't delete a backed-up share (product question for Backupsy).
  Right after files are removed `zfs destroy` can also find the dataset busy (same half-state); share_unuse retries.
- **⟦Permit exclusive shares⟧**: Tower yes, USOTest3VM no (share.cfg `shareUserExclusive`) — SH-EXCLUSIVE* need it.
- **A full ZFS pool breaks Docker on Tower** (its docker folder lives on cache): a pull during DK-POOL-FULL left 7 layer
  records without their overlay folder («failed to register layer … file exists» on every later pull) — H2 removed those
  broken records by hand. A scenario with DK-POOL-FULL on Tower must not pull images; the office's own Docker actions
  in that state are a real cell (Backupsy stops/starts containers).
- **job.sh starts a job once a minute** (`$RUN/job-<job>.minute`): `second_run` waits for a new minute.
- **The scenario lock leaks into daemons** started inside `flock … <cmd>` (the descriptor is inherited): `_st_bg`/`_st_clean`.
- Unraid's «Add Share» once failed on Tower with `mkdir /mnt/user/nextcloud: No medium found` (ENOMEDIUM) right after
  DK-POOL-FULL's undo; a retry a minute later worked — shfs needed a moment after the refreservation went.

## States

Self-test of one state on a VM (fingerprint before → state → undo → fingerprint; exit 0 green, 1 failed, 2 needs, 3 the
undo left something — the diff is printed):

```bash
flock /tmp/uso-scenarios.lock bash tools/scenarios/states-common.sh --self-test SH-YES
ST_SHOW='stat -c "%n %W %Y" /mnt/cache/uso-yes/*' ST_HOLD=30 flock … --self-test SH-YES    # look at it, hold it
```

The fingerprint (`_st_fp`, the states' own INV-LEFTOVER until H1's lands): containers (name, image, state, binds,
network, ports, compose project), volumes, networks, images, templates (md5), every share cfg + docker/domain/share/
dynamix cfg + parity history (md5), notification agents, Compose Manager projects, shares.ini (useCache, pools,
exclusive, hasCfg), every pool's and disk's top folders and appdata's, every `uso-*` share part in full (name, size,
type, mode), ZFS datasets (+ refreservation, mountpoint) and pool health, services (mdState, dockerd, libvirtd, atd, ntpd,
crond, dockerd stopped?), notify's mode, mover.pid, parity check running, /var/log free (10 MB steps), `uso-*` holder
processes. Container ids are reported, not compared (a re-created container can't get its id back).

Rules for whoever uses them: `state ID args` pushes `undo_<id> args` even when the state fails — every undo is idempotent
and undoes only what its stash notes. A state returns 0 only when the state is really there, 2 when the server lacks what
it needs (lib.sh then marks the scenario «skipped», exit 2, and still runs the undo; a scenario may also say `needs …` first, column «needs»). KEEP_STASH must be fresh per scenario (a failed undo's
notes would be taken again). Long-lived helpers (holders, watchers, the agent) start through `_st_bg`/`_st_clean`: no
inherited descriptor, so the scenario lock is never kept by a child (see «Other facts»).

Legend: **T** = run on Tower and undone with INV-LEFTOVER green, **3** = the same on USOTest3VM, **needs X** = written
against the expected layout, not runnable on today's VMs; *(2 on 3)* = the self-test answered «needs» there, correctly.

| State id | Helper / what it does | Proven |
|---|---|---|
| **A. Containers and stacks** | | |
| CT-RUNNING | nothing (the base) | T 3 |
| CT-STOPPED [names…] | `ct_note` + `ct_stop` (default EmbyServer); undo `ct_back` | T |
| CT-STOPPED-NC | Nextcloud + mariadb-nextcloud stopped | T |
| CT-STOPPED-DB | mariadb-nextcloud stopped | T |
| CT-STOPPED-PLAIN | EmbyServer stopped | T |
| CT-PAUSED [name] | `docker pause`; undo unpause | T |
| CT-GONE [name] | `ct_remove` (template into the stash); undo template back + Unraid's rebuild_container | T |
| CT-GONE-TEMPLATE-STAYS [name] | `ct_remove … keep-template`; undo rebuild_container | T |
| CT-IMAGE-GONE [name] | stopped, its image's tag removed (`docker rmi -f repo:tag`, the layers stay under the container); undo `docker tag <id> <repo:tag>` | T |
| CT-RENAMED-DOCKER [old new] | `docker rename` | T |
| CT-RENAMED-UNRAID [old new] | new template (Name changed), old container + template gone, rebuild_container of the new | T |
| CT-RECREATED [name] | rebuild_container (new id); undo: the noted state | T |
| CT-NEW | `ct_make uso-new` (alpine, appdata/uso-new) | T |
| CT-BINDS-ROOT [all\|share] | `uso-wide` binds /mnt/user (or a share) | T (all, share) |
| CT-APP-IS-DATASET [ds dir] | looks only: appdata/jellyfin a dataset, appdata/emby a folder (Tower's base) | T |
| CT-VOLUME-ONLY | `uso-vol` with the named volume usovol | T |
| CT-MANY [n=40] | uso-m1 … uso-m40 | T |
| CT-HEALTH-STARTING | `uso-sick`, health check `exit 1` | T |
| CT-AUTOSTART [names…] | Unraid's Docker autostart (`/var/lib/docker/unraid-autostart`) for these, default every running container, databases first; undo the file as it was | T4 |
| ST-STACK [project] | the Immich stack (compose labels); made by apps.sh when missing | T |
| ST-STACK-CM | a Compose Manager project of the stack | needs compose.manager (Tower has no `docker compose`) |
| ST-RENAMED [old new] | the members re-created with project label immich-new (apps.sh --project) | T |
| ST-MEMBER-STOPPED [member] | immich_redis stopped | T |
| ST-MEMBER-GONE [member] | immich_redis removed; undo apps.sh re-creates it | T |
| ST-CM-FILE-UNREADABLE [project unreadable\|gone] | the project's compose file chmod 000 / moved aside | needs compose.manager |
| **C. Shares** | | |
| SH-ARRAY [share n] | `share_make uso-arr no`, 50 files on disk1 + 50 on disk2 | T 3 |
| SH-POOL-ONLY | `uso-only` only cache, 10 files (exclusive by itself on Tower) | T 3 |
| SH-YES | `uso-yes` yes cache, 20 files 40 d old + 20 files 1 d old (birth too) | T 3 (ages: `stat` W, Y and Z 40 d / 1 d old) |
| SH-PREFER-ARRAY-FILES | `uso-prefer` prefer cache, 10 files on disk1 + 10 on disk2 | T 3 |
| SH-PREFER-POOL2 / SH-YES-POOL2 | secondary = pool `fast` | needs pool2 |
| SH-EXCLUSIVE | `uso-ex` only cache, exclusive (symlink) checked | T *(2 on 3: exclusive not permitted)* |
| SH-EXCLUSIVE-BROKEN | `uso-exb` exclusive, then a root with files on disk1 → shares.ini exclusive=no (array restart when Unraid looks only then) | T (7.4 drops exclusive at once on `cmdStatus=Apply`, no array restart needed) |
| SH-ZFS / SH-BTRFS / SH-XFS | a share whose part lies on that filesystem (pool: only; array disk: include=diskN) | SH-ZFS T, SH-BTRFS 3, SH-XFS T 3 |
| SH-MULTI-DISK | uso-arr/serie1 on disk1 and disk2 | T 3 |
| SH-RENAMED [a b] | Unraid's form renames uso-a → uso-b (cfg, dataset with snapshots) | T 3 |
| SH-DELETED [share] | parts with files renamed aside, Unraid's «Delete»; undo renames back, cfg back | T 3 |
| SH-CFG-ORPHAN [share] | every root aside, the cfg stays (Unraid stops listing it) | T 3 |
| SH-CFG-POOL-GONE [share pool] | cfg by hand `shareCachePool="big"` + array stop/start | 3 (two array restarts; shares.ini then says cachePool="big") |
| SH-OFFICE-SHARE-EXISTS [placed\|unplaced] | looks; unplaced: settings.ini `dumps_share =` (copy put back) | T 3 (placed 3, unplaced T 3) |
| SH-OFFICE-SHARE-MISSING | UnraidSecretaryOffice's roots and cfg aside | T (Kopia, which binds …/restore/kopia, stopped around it) 3 |
| SH-APPDATA-MISSING | appdata's roots and cfg aside, the agent stopped around it (Docker off only) | 3 *(needs Docker off)* |
| SH-NAMES [share] | share «Fotos Ärger» (hand-made root + Unraid's Apply) with `put_names` | T 3 |
| SH-100K [share n] | `put_many` 100 000 empty files in uso-yes/many | T 3 |
| SH-BIG-FILE [share GB=2] | `put_big` (dd of random data) | T 3 |
| SH-OPEN-FILE [share] | `open_file` /mnt/user/uso-yes/open.bin (holder `uso-open-hold`) | T 3 |
| SH-STOREROOM [share] | `storeroom_put`: .UnraidSecretaryOffice-trash/<run>/appdata/uso-old-app + her manifest | T 3 |
| **D. Disks and pools** | | |
| DK-ASLEEP-DISK1 / -DISK2 | `disk_sleep diskN` (emcmd cmdSpindown) | needs hdd (2 on T and 3) |
| DK-ASLEEP-POOL [pool=slow] | `disk_sleep <pool>` | needs hdd + a rotating pool |
| DK-POOL-FULL [pool leaveMiB] | `pool_fill`: ZFS refreservation dataset / btrfs fallocate | T (ZFS) 3 (btrfs) |
| DK-POOL-DEGRADED [pool] | `zpool offline` the mirror's second device; undo online + healthy | T *(2 on 3: btrfs)* |
| DK-ARRAY-DISK-FULL [n] | `disk_fill n` (xfs/btrfs fallocate, zfs refreservation) | T (disk1 xfs, disk2 zfs) 3 (disk1 btrfs, disk2 xfs) |
| DK-NO-ZFS | looks: no zpool | 3 |
| **E. Services and array** | | |
| SV-DOCKER-OFF [cfg] | `docker_off` (rc.docker stop; cfg: DOCKER_ENABLED="no") | T (both variants) |
| SV-DOCKER-HUNG | `docker_hang` (SIGSTOP dockerd) | T |
| SV-VM-OFF [cfg] | `vm_service_off` (rc.libvirt stop; cfg: SERVICE="disable") | T (both variants; Tower has no VM yet) |
| SV-ARRAY-STOPPED | `array_stop` / `array_start` (+ containers/VMs that ran) | T 3 |
| SV-ARRAY-STOP-MIDRUN [bk\|mv\|rs\|drill] | = RN-ARRAY-STOP-MIDRUN-<run> | see F |
| SV-ATD-OFF | `atd_off` | T 3 |
| SV-NOTIFY-OFF [bin\|agents] | `notify_off` | T (bin) 3 (bin, agents) |
| SV-LOG-FULL | `varlog_fill` | T 3 |
| **F. Interruptions and runs** | | |
| RN-ARRAY-STOP-MIDRUN-BK / -MV / -RS / -DRILL [delay timeout] | `midrun_arm … stop`: a watcher stops the array <delay> s after the run is at work | -BK 3 (fired 1 s into a real cron backup, array back, green); -MV/-RS/-DRILL: the same watcher, their process pattern not run yet (needs a Moverelli setup with files / a restore / a drill — wave 2 flows) |
| RN-HARD-RESET-MIDRUN [run delay] | `midrun_arm … reset` (sysrq b) | needs reboot (runner halves) |
| RN-REBOOT-CLEAN-MIDRUN [run delay] | `midrun_arm … reboot` | needs reboot (runner halves) |
| RN-TWO-RUNS [backup\|moverelli] | `second_run`: the cron's job started now; undo waits for its end | T 3 (backup); moverelli not run (no Moverelli setup on USOTest3VM) |
| RN-SETUP-WHILE-RUN | `second_run backup` + the engine's lock held | T 3 |
| RN-PLAN-OLD | settings.ini touched (only its time; put back) | T 3 |
| RN-AGENT-RESTART-MIDREQUEST | armed: agent.php touched when the next request lands in the mailbox; undo its time back + the agent's next restart waited for | 3 (fired on a real `office.ping`; the agent restarts in place — same pid, new `started`) |
| RN-PARITY-CHECK | `parity_start` (mdcmd check NOCORRECT) / `parity_stop` (+ parity-checks.log as before) | T 3 |
| RN-UNRAID-MOVER-RUNNING | `unraid_mover_fake_running` (mover.pid → `uso-fake-mover`) | T 3 |

**Not runnable on today's VMs** (written against the expected layout): DK-ASLEEP-DISK1/-DISK2/-POOL (`needs hdd`: a real
rotating disk passed through, for -POOL a pool `slow` on it), SH-PREFER-POOL2 / SH-YES-POOL2 (`needs pool2`: a second pool
`fast`), ST-STACK-CM / ST-CM-FILE-UNREADABLE (`needs compose`: the Compose Manager plugin and `docker compose` — not on
Tower), RN-HARD-RESET-MIDRUN / RN-REBOOT-CLEAN-MIDRUN (`needs reboot`: armed here, the runner's -pre/-post halves do the
rest). lib.sh knows the extra `needs` words `pool2`, `compose`, `exclusive`, `nodocker` (H1, dbfc035).

## apps.sh (Tower's demo apps)

`bash tools/scenarios/apps.sh all` makes what is missing (EmbyServer, Nextcloud + mariadb-nextcloud, the Immich stack),
`--recreate <app>` anew, `remove <container> [keep-template]`, `status`. Templates + Unraid's rebuild_container for the
single containers, labels for the Immich stack (named volume `immich_redis-data`, uploads bound at
appdata/immich/upload → /data), Nextcloud installed by `occ maintenance:install` (its image's own first-start install
left a half install twice). Needs the shares media, nextcloud, photos (made by «Add Share» when missing) and
scenarios.env with MARIADB_ROOT_PASSWORD, MARIADB_NC_PASSWORD, NEXTCLOUD_ADMIN_PASSWORD, IMMICH_DB_PASSWORD (set on
Tower). Plex, Jellyfin, kopia and Node-Exporter on Tower are not apps.sh's — left alone.

# Scenario states part 2 (H4): VMs, Kopia/offsite, time, install, mover environment

Sections B, G, I, J, K of `resilienz/zustaende.md`, in `states-vm.sh`, `states-kopia.sh`, `states-time.sh`,
`states-install.sh`, `states-mover.sh` (+ `vm-alpine.sh`), the same conventions as above (`state` pushes the undo, 0 made
· 1 failed · 2 needs, stash under `$KEEP_STASH/<id>/`). Each state also writes what H5's checks need to
`$KEEP/state-<id>.env` (`KEY=value` lines — the keys are in the table). The self-test's fingerprint learns these files'
parts through `_st_fp_add` (VMs and their XML, snapshotdb, loop devices; Kopia's repository, its sources, MinIO and its
quota; time zone, a set clock; the office's plugin/code/flash/authorized_keys/ini md5s; movers, plugins, User Scripts).

## Base additions (base.sh steps 4 and 5, Tower)

- **VMs:** `uso-vm-plain` (empty 1 GiB qcow2, no OS, no agent channel, 128 MiB) and `uso-vm-agent` (Alpine 3.22 +
  qemu-guest-agent, NAT network «default», 256 MiB), both **shut off**, folders `domains/uso-vm-*` (datasets on cache).
  `vm-alpine.sh` builds the agent's disk once from Alpine's cloud image (sha512 pinned) with a vfat CIDATA seed (no
  genisoimage on Unraid), cloud-init installs the agent, a second boot must answer `guest-ping`; cached as
  `scenarios-cache/iso/alpine-agent.qcow2` beside the data folder (base.sh --make sets `<data>.base` aside; ~170 MB, ~3 min to build, seconds to copy).
- **MinIO:** `uso-minio` (`pgsty/minio:RELEASE.2026-08-04T00-00-00Z` — MinIO's own images are gone from Docker Hub and
  quay.io, dl.min.io answers 410), port 9010, data `appdata/uso-minio`, bucket `uso` with Object Lock; user
  `uso-minio-root`, `MINIO_ROOT_PASSWORD` in scenarios.env (made when missing).
- **Kopia:** its template binds the image's VOLUMEs `/backups` and `/source` to `appdata/kopia/volumes/*` (`kopia_bind`,
  once): before, every re-made container got new anonymous volumes (the fingerprint can't come back). The repository
  stays `/config/test-repo` (filesystem); `kopia_use s3|s3-lock` switches to MinIO (prefix `plain/` or `lock/`,
  GOVERNANCE 7 d, `--extend-object-locks` on), always back by the undo. Secrets go into `docker exec -i` on stdin only.
- **USOTest3VM:** office 1.66.4 (the release), its base made anew (`base.sh --make`).
- **Fixture 1.60:** `scenarios-cache/fixtures/data-1.60.tar` (beside the data folder) on Tower and USOTest3VM (copy in the briefs folder
  `resilienz/fixtures/` — not in git: the repository is public). Made on **Tower**, not USOTest3VM: 1.60's engine (2.44)
  ends every plan with «Docker does not answer» where Docker is off.

## States

| State id | What it does · notes (`state-<id>.env`) | Proven |
|---|---|---|
| **B. VMs** | | |
| VM-RUNNING-AGENT | uso-vm-agent started, its agent answers · VM, VM_AGENT=yes | T |
| VM-RUNNING-NOAGENT | uso-vm-plain started · VM, VM_AGENT=no | T |
| VM-SHUTOFF [plain\|agent] [shutdown\|destroy] | started, then shut down (through the agent) / destroyed · VM, HOW | T (agent) |
| VM-PAUSED-BY-USER [plain\|agent] | `virsh suspend` (Unraid's ⟦Pause⟧) · VM, VM_PAUSED | T |
| VM-GONE [plain] | `virsh undefine --nvram`, folder and disk stay; undo defines the kept XML · VM, VM_FOLDER | T |
| VM-DISK-MISSING [plain] | its disk file moved into the stash (≤ 512 MiB) · VM, DISK | T |
| VM-DISK-ON-XFS | uso-vm-xfs, disk on share `uso-vmxfs` (array, disk1 xfs) · VM, SHARE, DISK, FS | T |
| VM-DISK-BLOCK | uso-vm-block, disk a loop device (type=block) · VM, DEV | T |
| VM-HOSTDEV | uso-vm-hostdev with a PCI hostdev at an address no device has (defined, never started) · VM, HOSTDEV | T |
| VM-SERVICE-OFF [cfg] | = SV-VM-OFF | T |
| VM-MANY-SNAPSHOTS [n=3] [plain] | Unraid's own `vm_snapshot()` n times: qcow2 overlays, snapshotdb · VM, SNAPSHOTS, OVERLAY | T |
| **G. Kopia and offsite** | | |
| KP-CONTAINER-STOPPED | `docker stop kopia` · CONTAINER | T |
| KP-CONTAINER-GONE | removed, template stays; undo: Unraid's rebuild_container · CONTAINER, TEMPLATE | T |
| KP-DISCONNECTED | `repository disconnect`, container restarted (its server without a repository) · WAS | T |
| KP-S3-UNREACHABLE | Kopia on MinIO (plain/), MinIO stopped · STORAGE, ENDPOINT | T |
| KP-S3-QUOTA-FULL [size=1MiB] | Kopia on MinIO, bucket hard quota · STORAGE, QUOTA | T |
| KP-OBJECT-LOCK [ok\|extend\|lapse\|late\|never] | Kopia on MinIO's locked repository; `backup.sh --kopia-lock`, then state/kopia-lock.json rewritten for the variant (time can't be made; the office's next look rewrites it) · VARIANT, LOCK_MODE, LOCK_PERIOD_S, EXPECT | T (all five) |
| KP-EMPTIED | the filesystem repository's files moved aside (the throwaway repositories of Tower and USOTest3VM only) · REPO | T 3 |
| KP-ORPHANS | three old sources `/uso/uso-gone-share`, `/uso/.apps/uso-gone-app`, `/uso/.vms/uso-gone-vm`, one snapshot each (`--override-source`) · SOURCES, IDENTITY | T |
| KP-LOCKED-REPO | Kopia's maintenance lock (`repository.config.mlock`) held by `uso-kopia-mlock` · MLOCK | T |
| KP-MAPPING-CHANGED [/uso2] | template's `/uso` target changed, container re-made · FROM, TO | T |
| **I. Time** | | |
| TM-UTC-BROKEN [zone] | /etc/localtime and ident.cfg → Europe/Zurich (missing on 7.4: UTC); USOTest3VM's base: only looked at · TZ_FILE, TZ_ABBR, BASE | T 3 |
| TM-DST [autumn\|spring] [zone] [minutes=2] | clock <minutes> before 2026-10-25 03:00 CEST (or 2027-03-28 02:00 CET; autumn 20 = 02:40 CEST, the repeated hour's first time); needs a zone with DST — a missing zone file (USOTest3VM) gets [zone] linked for the state · DST, ZONE, SWITCH_AT, CLOCK_SET | T *(2 on 3: UTC)*; 3 with `autumn Europe/Berlin` |
| TM-CLOCK-BACK [hours=2] | clock set back · CLOCK_SET, CLOCK_SET_AT | T 3 |
| TM-YEAR-ROLL [year] | clock 2026-12-31 23:58 local · CLOCK_SET | T |
| **J. Version and install** | | |
| OS-73 / OS-74 | looks only (Unraid's version) · UNRAID | OS-74 T; OS-73 needs 73 |
| IN-FRESH [latest\|vX.Y.Z] | plugin removed, data folder aside, flash folder emptied (scenarios.env stays), installed anew · FRESH | T |
| IN-UPDATE-PREV [previous] | the release before the newest installed on today's data folder · PLG, OFFICE, FROM, SOURCE | T |
| IN-DOWNGRADE [previous] | the same install (older on a newer's data folder) · PLG, OFFICE, FROM, SOURCE | T |
| IN-UPDATE-OLD [1.60] | v1.60.0 installed with the 1.60 fixture as data folder · FIXTURE | T |
| IN-TAKEOVER | User Scripts «unraid-backup» on a schedule, `snap_prefix = unraidbackup-`, `@unraidbackup-20260101-0300` on appdata (ZFS) · SCRIPT, SNAP_PREFIX, OLD_SNAPSHOT | T |
| IN-HANDEDIT-UNKNOWN, -CRLF, -BOM, -DUP, -GONE-SECTION, -ZERO-RET, -PRESET-BAD, -MODE-OFF-BY-HAND, -PERMS, -OWNER | settings.ini by hand (`ini_edit`) · VARIANT | T (all ten) |
| IN-INI-MISSING | settings.ini moved into the stash · SETTINGS | T |
| IN-MOVERELLI-INI-HANDEDIT | smart_mover.ini: `move_when_used_above=150`, `uso_foreign_key=1`, `mover_bin=` · X, FOREIGN, MOVER_BIN | T |
| **K. Jack Streamer and the movers** | | |
| JK-HIRED-LIST | hired, set up on Tower's Emby (its «MediaCache» key → scenarios.env EMBY_API_KEY, sent only on stdin), the 3 smallest media files moved disk → pool, his list as MediaCache's `write_exclude()` writes it, her ini naming it for media · JACK, LIST, LIST_FILES, SHARE, LIBRARIES, USERS | T |
| JK-LIST-PENDING | hired, not set up, her ini names his (missing) list · LIST, EXPECT=moverelli_jack_list_pending | T |
| JK-LIST-GONE | JK-HIRED-LIST + `mediacache_origin.json` (he ran), his list gone — **until his next look** (see findings) · LIST_GONE | T |
| MO-UNRAID-SCHEDULE-ON [cron] | Unraid's mover schedule through its form (emcmd); USOTest3VM's base: only looked at · SCHEDULE, WAS | T 3 |
| MO-TUNING [plg] | Mover Tuning (masterwishx fork, CA) + a schedule line in its cron file · TUNING, SCHEDULE | T |
| MO-FOREIGN-SCRIPT | User Scripts installed, «uso-mover-now» runs `mover start` at 15 2 * * * · SCRIPT, WHEN, EXPECT=foreign_mover | T |
| MO-OWN-RULES | her rules: uso-yes 30 (base), uso-prefer 14 + `*.part`, an empty share uso-mo-empty 5 · RULES, EMPTY_SHARE | T |
| MO-X-ZERO | her X 0 · X | T |

Not runnable today: OS-73 (`needs 73`: USOTest3VM on 7.3.3 later), VM-* on USOTest3VM (no VMs there by design).
Section H (partner) is batch G's.

## Findings while building (worth a scenario)

- **Unraid's VM snapshots** (`vm_snapshot()`): overlays are named `vdisk1.<name>qcow2` (no dot before qcow2), and Unraid
  drops libvirt's snapshot metadata once its own `snapshotdb/<vm>/snapshots.db` is written — `virsh snapshot-list` is
  empty; only the db and the disk's backing chain tell. The engine's «VM disk on an overlay» must read those.
- **rc.libvirt start rewrites a VM's metadata namespace** (`xmlns='unraid'` → `'http://unraid'`): XML hashes differ
  across a VM-service restart unless the VM was defined with Unraid's own namespace.
- **The kopia image's anonymous volumes** (`/backups`, `/source`): the Consultant's template binds neither; a
  re-made container (Unraid's Apply, an update) gets new empty volumes — a user whose filesystem repository sits at the
  image's default `/backups` loses sight of it on the next re-create (the data stays in a dangling volume).
- **A Kopia repository made with Object Lock has `extendObjectLocks` off** (Kopia 0.23): the office's
  `kopia_lock_extend` is the first thing such a user sees — KP-OBJECT-LOCK extend is the default, not a corner case.
- **MediaCache's dry run never writes his list** (only real runs and give-backs: `write_exclude` under `run_mode`) —
  zustaende.md's «ein MediaCache-Lauf (dry) → Liste» was a guess; JK-HIRED-LIST writes it as MediaCache would.
- **A gone list heals itself empty:** Jack Streamer's next look (`jackScan` → `jackListEnsure`) writes an EMPTY list when
  his settings are there — her next joint run then gives back everything he had fetched (no ERROR from Smart Mover).
- **1.60's engine can't plan with Docker off** («Docker does not answer», setup result failed) — an old install on a
  NAS without Docker never had a plan; IN-UPDATE-OLD on USOTest3VM uses Tower's fixture.
- **A clock that went forward and back** leaves files with mtimes in the future (agent.json, logs written meanwhile):
  `now − mtime` is negative afterwards — the heartbeat's «fresh» and every «last run … ago» should be checked after
  TM-YEAR-ROLL / TM-CLOCK-BACK.
- **Mover Tuning** (masterwishx fork) installs without a schedule line of its own until its settings are saved — MO-TUNING
  writes the line its page would.
