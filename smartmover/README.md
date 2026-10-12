> **In the Unraid Secretary Office** this is the Smart Mover Ms. Moverelli looks after: taken from
> [helmi1987/custom-mover-for-unraid](https://github.com/helmi1987/custom-mover-for-unraid)
> (V7, commit `3bee44803cc4425b80aa70d20e63765e1baff592`, 2026-10-11) by helmi1987. Ms. Moverelli writes its settings
> (`data/moverelli/smart_mover.ini`, her own exclude lists in `data/moverelli/excludes/`), runs it (`--list-shares`, the
> dry run, `--run`) and schedules it; its data lives in `data/moverelli`. Only `custommover_run.sh` is taken over. The
> office's changes against that commit — each one switched on by an environment variable the office sets (the stale
> .cfg skip and the fill below excepted); without them the script behaves exactly as upstream:
>
> * `CUSTOMMOVER_LOG`: the log file (over the INI's `log_file`) — the code lies in RAM in the office, the log in its data
>   folder.
> * `CUSTOMMOVER_LOCK`: the lock file (default still `.custommover.lock` next to the script, which in the office is RAM
>   and gone at every boot).
> * `CUSTOMMOVER_MOVER_BIN`: the move binary (over the INI's `mover_bin`) — for the tests' stand-in; the office itself
>   leaves it to the script's own search (`/usr/libexec/unraid/move`, `/usr/local/sbin/move`, `/usr/local/bin/move`).
> * `CUSTOMMOVER_STATUS`: a JSON file written when the script ends (also on a configuration error, a busy lock or the
>   stock mover running): `mode` (list, dry, run), `result` (ok, errors, config, busy, stopped, failed), `exit`,
>   `started`/`finished`, the totals (`files`, `bytes`, `moved`, `left`, `errors`), `message`, `stop_share` and per share
>   (`shares`) its mode, direction, effective rules (`min_age`, `age_stat`, `threshold`, `excludes`), what it did
>   (`state`: list, skip, prefer_off, error, no_source, none, dry, moved) and the counts (`files`,
>   `bytes`, `moved`, `left`); with the fill (below) also `pool`, `until` (the target), `pool_used` (the pool when the
>   fill looked), `pool_now` (the last reading), `fill` and `reached` — the live status carries the same for its share. Counted as errors: a share skipped for a bad INI value, the move binary ending ≠ 0.
> * `CUSTOMMOVER_STOP` (a file): once it exists, no further share is started — never in the middle of a share's move
>   (one move call per share, as upstream); result `stopped`, exit 3, `stop_share` names the first share left out.
>   Ms. Moverelli writes it when Unraid's own mover starts during her run, or when the user asks on her page.
> * `CUSTOMMOVER_EXCLUDES_REQUIRED=1`: on a real run (`--run`) an exclude file that is missing skips its share with an
>   ERROR (counted in `errors`, state `error`) instead of a WARN — a list that is gone never lets its files move. Dry runs
>   still only warn. Ms. Moverelli sets it for real runs.
> * `CUSTOMMOVER_DISKS_INI` (emhttpd's disks.ini): on a real run a share that reaches the array names its array disks
>   that sleep (its `shareInclude`, else all, less `shareExclude`) in one INFO line — a prefer share before its scan of
>   `/mnt/user0`, a share moving to the array before the move binary starts. Nothing else changes.
> * Always: the share's own path in an exclude entry (`/mnt/user/<share>/…`, `/mnt/user0/<share>/…`) is replaced quoted —
>   a share name with `[ ] * ?` is no pattern.
> * One file at a time (2026-10-12, with `CUSTOMMOVER_STOP` set; marked «office: one file at a time» in the script):
>   a real run hands Unraid's move binary one file per call instead of the share's whole list — the move binary reads
>   its stdin line by line and moves as it reads, but only one call per file lets the office know when a file is done.
>   The stop file is looked at before each file: the file in flight always finishes, no further one starts («Stop after
>   this file»). There is no harder stop: Unraid's move catches TERM and finishes the file in flight anyway (seen
>   2026-10-13), so the office never signals it.
>   While the run goes, `CUSTOMMOVER_STATUS` says how far it is (result `running`: the shares it will look at, the phase,
>   the share and file in flight, files and bytes done); the final status names the last file (`stop_file`). Without
>   `CUSTOMMOVER_STOP` the script behaves as upstream (the whole list at once).
> * **The fill — a change of meaning for helmi1987 to look at** (always, no variable, 2026-10-12, the owner's
>   decision; marked «USO «fill»» in the script): upstream, `move_when_used_above` is a *gate* — below it nothing moves.
>   Here the age rule **always** moves (what is older than `min_age`, however full the pool is), and
>   `move_when_used_above=X` is the *trigger* of a second step: is a `yes` share's pool still at/above X % after the age
>   step, the rest of the share — never an excluded file — goes **oldest first** (by `age_stat`, whatever its age), one
>   file per call, the pool re-read after each file (`pool_used_pct()`: ZFS whole pool, else `df`; the one place a later
>   rule can budget excluded bytes), until it is below the new key `move_until_used_below=Y` (Y < X; empty/0: below X;
>   Y ≥ X skips the share with an ERROR). Shares on the same pool share the target: once below, later shares fill
>   nothing (said in the log). A dry run estimates the step from the files' sizes. Not with `--force`/`--force-all`.
>   `CUSTOMMOVER_DF` points `df` at a stand-in for the tests (like `CUSTOMMOVER_ZFS`). The office's setup requires Y
>   once X is set; an old ini with X and no Y gets Y = X − 10 proposed (her setup) or set (her import).
> * Leftovers and counts (2026-10-12/13, seen live): Unraid's move writes `<dest>/<share>/<rel>.partial` and renames it
>   at the end. Before a real run moves a share's files, a leftover `.partial` (a power cut, a crash) on the share's awake
>   destinations is removed — only while its source `<src>/<rel>` still exists, only that exact name, never a file under
>   its final name (`office_partial_leftovers`). After a stop the files of the share not started count as `left`; the
>   live status carries `errors` and `warnings` (WARN lines) for the run card.
> * **The cake** (2026-10-12, Ms. Moverelli's pool budget for Jack Emby's EmbyCache; marked «USO «cake»»; each variable a
>   comma list, unset: as before). `CUSTOMMOVER_POOL_OFFSET_BYTES=<pool>:<bytes>` is added to the pool's used bytes in
>   every reading of `pool_used_pct()` (may be negative: Jack's slice minus what his list holds — his slice counts as
>   full whether he uses it or not), said in the log («I see it at 44 % – Jack Emby's slice counted whole»); the
>   trigger, the target and the re-reading after each file all see it. `CUSTOMMOVER_FILL_TARGET=<pool>:<pct>` replaces
>   the fill's target for that pool (the office: what never moves plus Jack's slice reach Y — the fill goes only down to
>   below X). `CUSTOMMOVER_FILL_OFF=<pool>`: no fill there (what never moves is above X; the age rule still moves).
>   `CUSTOMMOVER_FILL_ALL=<pool>`: the emergency (≥ 95 % used) — every non-excluded file of that pool's `yes` shares
>   goes, whatever its age and the trigger. Nothing else changes: the age rule, oldest first, the exclude lists.
> * Always (no variable, 2026-10-12, helmi1987's finding): a `<share>.cfg` whose share is gone — no `/mnt/user/<share>`
>   (under `CUSTOMMOVER_MNT`); a share removed by hand leaves its .cfg on the flash — is skipped with an INFO line
>   («skip <share>: no such share (stale .cfg)»), never listed or moved. Not upstream as of `68ee9ce`.
> * The status JSON's texts are made safe: invalid UTF-8 is dropped (`iconv -c`, when there), control characters and DEL
>   too.
> * Upstream changes taken over since the first copy (each also named at the top of the script):
>   `6382fb4` — the threshold on a ZFS pool measures the whole pool (`zfs list -Hp -o used,avail`), not `df` on
>   `/mnt/<pool>`, which shows next to nothing there; `3bee448` — an absolute exclude entry is matched literally
>   (`find_literal` escapes `\ [ ] * ?`, so `Film (2020) [1080p].mkv` protects exactly that file): the office's own fix
>   (0e620a7), now upstream in helmi1987's wording. Left out: `558e171` (the setup wizard asks for `max_list` — Ms.
>   Moverelli writes it herself, default 50) and `68ee9ce` (.gitignore, file modes).
> * Not taken over: `setup_custommover.sh` (Ms. Moverelli is the setup). The office never passes `--force` or
>   `--force-all`, and never `CUSTOMMOVER_IGNORE_MOVER`. The tests point `CUSTOMMOVER_ZFS` (upstream) at a stand-in.
>
> **`prefer` shares** (array → pool) are moved as upstream does: their source is `/mnt/user0/<share>` (or the second
> pool), which reads the array disks and may wake them. That is accepted here on purpose (the owner's decision): the
> Dynamix Cache Directories plugin keeps the folder structure in RAM, so the scan rarely spins a disk up. There is no
> sleep gate; `move_prefer_shares=no`, or `skip=yes` per share, leaves them out.
>
> **Licence:** the original is by helmi1987; this copy is published under GPL-3.0-or-later, as part
> of the office. (The upstream repository has no LICENSE file of its own at the commit above.)

# Smart Mover for Unraid (upstream README, as of 3bee448)

**Smart Mover** is a wrapper around the official Unraid `move` binary. The stock mover moves *everything* a share's settings allow; Smart Mover filters by **age** and **exclude lists** first and then pipes the remaining paths to the very same binary – so allocation, split level, in-use checks and permissions are still handled by Unraid.

## Features

* **Correct direction per share** – read from `/boot/config/shares/<share>.cfg` at runtime:
  `shareUseCache="yes"` → pool → array (or secondary pool), `"prefer"` → array (or secondary pool) → pool, `"only"`/`"no"` → skipped.
* **Age filter** in days, based on `ctime` (default: the time the file landed on the pool, survives `rsync -a`) or `mtime` – global or per share. Not applied to `prefer` shares unless set per share.
* **Exclude lists** – one or more files per share plus global ones. Entries: a directory (protects the subtree), an exact file path (an [EmbyCache](https://github.com/helmi1987/embycache-for-unraid) exclude list works unchanged), a glob (`*.nfo`, `Season 0*`) or a plain substring. Comments (`#`, `;`), blank lines and CRLF are tolerated; `/mnt/user/<share>/…` and `/mnt/user0/<share>/…` paths are translated to the scanned source path.
* **Threshold** – `move_when_used_above=<percent>`: only move when the pool is fuller than X % (global or per share; *in the office: the fill's trigger, see above*). Applies to `yes` shares (pool → array) only. On ZFS pools the whole pool counts (`zfs list` used/available), since `/mnt/<pool>` is only the pool's root dataset and `df` shows next to nothing there; other pools use `df`.
* **Verification** – after the move the script checks what is still on the source side (in use, or already present at the destination) and reports it.
* **Cleanup** – empty directories left behind by moved files are removed up to (never including) the share root.
* **Safe by default** – dry-run unless `--run`; `flock` against parallel runs (`.custommover.lock` next to the scripts); refuses to start while the stock mover is running.
* **Log rotation** – the log is rotated at 10 MB, up to 20 old files (`smart_mover.log.1` … `.20`).
* No dependencies beyond bash, findutils and coreutils.

## Installation

1. Copy `custommover_run.sh` and `setup_custommover.sh` to a folder on the array or a pool, e.g. `/mnt/user/system/scripts/custommover/`, and make them executable.
2. Run the wizard: `./setup_custommover.sh` – it writes `smart_mover.ini` **next to the scripts**.
3. Check what was discovered: `./custommover_run.sh --list-shares`
4. Dry-run: `./custommover_run.sh` (prints every file that would move; full list in the log)
5. Go live, e.g. as a User Script or cron job: `/mnt/user/system/scripts/custommover/custommover_run.sh --run`

Set the stock mover schedule to the longest interval (or disable it via Mover Tuning) – otherwise both movers work on the same shares.

## Configuration (`smart_mover.ini`)

```ini
[GLOBAL]
# empty = auto-detect
mover_bin=/usr/libexec/unraid/move
log_file=/mnt/user/system/scripts/custommover/smart_mover.log
# days; 0 = move immediately
min_age=30
# ctime | mtime
age_stat=ctime
# percent; 0 = always
move_when_used_above=0
# also bring 'prefer' shares back to the pool (like the stock mover)
move_prefer_shares=yes
# 0-3, passed as -d to the move binary (shown with CUSTOMMOVER_LOG_LEVEL=DEBUG)
mover_debug=0
# dry-run lines shown on the console
max_list=50
global_excludes=/mnt/user/system/excludes/global.txt

[Filme]
min_age=14
excludes=/mnt/user/system/scripts/embycache/embycache_exclude.txt

[Downloads]
min_age=0
move_when_used_above=80

[appdata]
skip=yes
```

Defaults if a GLOBAL key is missing: `mover_bin` auto-detected (`/usr/libexec/unraid/move`, `/usr/local/sbin/move`, `/usr/local/bin/move`), `log_file=<script dir>/smart_mover.log`, `min_age=0`, `age_stat=ctime`, `move_when_used_above=0`, `move_prefer_shares=yes`, `mover_debug=0`, `max_list=50`. `global_excludes` is a comma separated list.

Per-share keys: `min_age`, `age_stat`, `excludes` (comma separated, in addition to `global_excludes`), `move_when_used_above`, `skip=yes`. The section name is the share name; paths are never stored. Comments go on their own line (`#` or `;`), not behind a value. Values from the INI can be overridden with environment variables, see `--help`.

### Exclude file format

```
# keep metadata on the pool
*.nfo
# keep a whole folder
/mnt/user/Filme/Kids
# exact files – e.g. EmbyCache's list
/mnt/cache/Serien/Show/Season 01/Show - S01E03.mkv
# substring anywhere in the path (legacy)
Trailers
```

Globs without a `/` match the file or directory name, globs with a `/` match the full path. A trailing `/` is ignored. Entries starting with `/` are taken literally: `[ ] * ?` are part of the name, so `Film (2020) [1080p].mkv` protects exactly that file.

## Usage

| Option | Effect |
| --- | --- |
| *(none)* | Dry-run. Shows what would move, touches nothing. |
| `--run` | Move for real. |
| `--force` | Ignore the age filter and the threshold; exclude lists still apply. |
| `--force-all` | Ignore age, threshold **and** exclude lists. |
| `--share "A,B"` | Only these shares. |
| `--list-shares` | Show discovered shares, direction and effective settings. |
| `--help` | Full help including environment variables. |

Environment variables (CLI flags take precedence): `CUSTOMMOVER_MODE` (dry/run), `CUSTOMMOVER_FORCE` (age/all), `CUSTOMMOVER_SHARES`, `CUSTOMMOVER_INI`, `CUSTOMMOVER_LOG_LEVEL` (DEBUG shows the mover's own output), `CUSTOMMOVER_MOVER_DEBUG` (overrides `mover_debug`), `CUSTOMMOVER_IGNORE_MOVER=1`, `CUSTOMMOVER_SHARES_DIR`, `CUSTOMMOVER_MNT` and `CUSTOMMOVER_ZFS` (for tests; default `/boot/config/shares`, `/mnt` and `zfs`). The setup wizard honours `CUSTOMMOVER_INI` and `CUSTOMMOVER_SHARES_DIR`.

Exit codes: 0 ok, 1 configuration error / unknown option / move binary missing, 2 another Smart Mover or the stock mover is running. Errors in a single share are logged and that share is skipped; the run still ends with 0.

## Notes

* **ctime vs mtime.** `ctime` is the inode change time – it is set when the file is created on the pool and cannot be carried over from the source, which makes it the right measure for "how long has this been on the cache". It is also reset by `chmod`/`chown` (e.g. Unraid's *New Permissions* tool or a container fixing ownership), which makes the file look new again. Use `mtime` for shares where that happens regularly.
* **`prefer` shares** are moved array → pool, exactly like the stock mover does. If you only want pool → array behaviour, set `move_prefer_shares=no`.
* **Symlinks** are moved like files. File names containing a newline are skipped with a WARN (the move binary reads one path per line).
* **Files that stay behind** after a live run are listed as WARN (first 10): the move binary skips files that are open (`fuser`) and files that already exist at the destination. Nothing is ever deleted by this script except empty directories.
* **Together with EmbyCache:** point a share's `excludes=` at `embycache_exclude.txt` and the cached media is never moved back by this script – no Mover Tuning needed.
