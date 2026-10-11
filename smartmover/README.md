> **In the Unraid Secretary Office** this is the Smart Mover Ms. Moverelli looks after: taken from
> [helmi1987/custom-mover-for-unraid](https://github.com/helmi1987/custom-mover-for-unraid)
> (V7, commit `6382fb488ac4d7519742940afd191ec36e1656bf`, 2026-10-10 — with the ZFS-aware threshold) by Stefan Diethelm
> (helmi1987). Ms. Moverelli writes its settings (`data/moverelli/smart_mover.ini`, her own exclude lists in
> `data/moverelli/excludes/`), runs it (`--list-shares`, the dry run, `--run`) and schedules it; its data lives in
> `data/moverelli`. Only `custommover_run.sh` is taken over. The office's changes against that commit — each one
> switched on by an environment variable the office sets; without them the script behaves exactly as upstream:
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
>   (`state`: list, skip, prefer_off, error, no_source, below_threshold, none, dry, moved) and the counts (`files`,
>   `bytes`, `moved`, `left`). Counted as errors: a share skipped for a bad INI value, the move binary ending ≠ 0.
> * `CUSTOMMOVER_STOP` (a file): once it exists, no further share is started — never in the middle of a share's move
>   (one move call per share, as upstream); result `stopped`, exit 3, `stop_share` names the first share left out.
>   Ms. Moverelli writes it when Unraid's own mover starts during her run, or when the user asks on her page.
> * `CUSTOMMOVER_EXCLUDES_REQUIRED=1`: on a real run (`--run`) an exclude file that is missing skips its share with an
>   ERROR (counted in `errors`, state `error`) instead of a WARN — a list that is gone never lets its files move. Dry runs
>   still only warn. Ms. Moverelli sets it for real runs.
> * The status JSON's texts are made safe: invalid UTF-8 is dropped (`iconv -c`, when there), control characters and DEL
>   too.
> * Not taken over: `setup_custommover.sh` (Ms. Moverelli is the setup). The office never passes `--force` or
>   `--force-all`, and never `CUSTOMMOVER_IGNORE_MOVER`. The tests point `CUSTOMMOVER_ZFS` (upstream) at a stand-in.
>
> **`prefer` shares** (array → pool) are moved as upstream does: their source is `/mnt/user0/<share>` (or the second
> pool), which reads the array disks and may wake them. That is accepted here on purpose (the owner's decision): the
> Dynamix Cache Directories plugin keeps the folder structure in RAM, so the scan rarely spins a disk up. There is no
> sleep gate; `move_prefer_shares=no`, or `skip=yes` per share, leaves them out.
>
> **Licence:** the original is by Stefan Diethelm (helmi1987); this copy is published under GPL-3.0-or-later, as part
> of the office. (The upstream repository has no LICENSE file of its own at the commit above.)

# Smart Mover for Unraid (upstream README, as of 6382fb4)

**Smart Mover** is a wrapper around the official Unraid `move` binary. The stock mover moves *everything* a share's settings allow; Smart Mover filters by **age** and **exclude lists** first and then pipes the remaining paths to the very same binary – so allocation, split level, in-use checks and permissions are still handled by Unraid.

## Features

* **Correct direction per share** – read from `/boot/config/shares/<share>.cfg` at runtime:
  `shareUseCache="yes"` → pool → array (or secondary pool), `"prefer"` → array (or secondary pool) → pool, `"only"`/`"no"` → skipped.
* **Age filter** in days, based on `ctime` (default: the time the file landed on the pool, survives `rsync -a`) or `mtime` – global or per share. Not applied to `prefer` shares unless set per share.
* **Exclude lists** – one or more files per share plus global ones. Entries: a directory (protects the subtree), an exact file path (an [EmbyCache](https://github.com/helmi1987/embycache-for-unraid) exclude list works unchanged), a glob (`*.nfo`, `Season 0*`) or a plain substring. Comments (`#`, `;`), blank lines and CRLF are tolerated; `/mnt/user/<share>/…` and `/mnt/user0/<share>/…` paths are translated to the scanned source path.
* **Threshold** – `move_when_used_above=<percent>`: only move when the pool is fuller than X % (global or per share). Applies to `yes` shares (pool → array) only. On ZFS pools the whole pool counts (`zfs list` used/available), since `/mnt/<pool>` is only the pool's root dataset and `df` shows next to nothing there; other pools use `df`.
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

Globs without a `/` match the file or directory name, globs with a `/` match the full path. A trailing `/` is ignored.

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
