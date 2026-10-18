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
