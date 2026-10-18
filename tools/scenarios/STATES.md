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

## States

Legend: **T** = run and undone on Tower with INV-LEFTOVER green, **3** = on USOTest3VM, **needs X** = implemented
against the expected layout, not run (missing hardware or a reboot the runner's halves do), **–** = not run yet.

| State id | Helper / what it does | Proven |
|---|---|---|
(filled in below as the states are verified)
