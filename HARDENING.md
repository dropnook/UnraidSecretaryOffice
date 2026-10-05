# Hardening before the first Community Applications release

Review of the office's own code (web side, mailbox, agent, desks, root jobs,
outgoing requests) on branch `task/hardening`. Each change is a commit
`Hardening: …`; tests are in `tests/run.php hardening`.

## Changed

| File | What is enforced now |
|---|---|
| `src/auth.php` | A wrong *current* PIN when changing or removing the PIN counts against the waiting time like a wrong PIN at unlocking (`officeAuthFailed()`). Before, `office.pin` allowed unlimited guesses. `auth.json` is written via an exclusively created tmp (random name) that is `0600` before it takes the name. |
| `agent/lib/util.php` | `writeAtomic()` creates its tmp exclusively (`fopen 'x'`, random name, mode set by umask from the start, owner via `lchown`), so a file or link planted beforehand is never written through; `rename()` replaces a link at the target. New `writeNewFile()`. `logLine()` removes a link where `agent.log` belongs instead of appending through it, strips CR. |
| `agent/agent.php` | Mailbox: every round checks (lstat) that it is a real folder of the web server's user, closed to others — otherwise requests wait until it is set up again. A request is only read if it is a plain file owned by root or the web server's user (no link). The mailbox and `data/office` are never chown'ed/chmod'ed through a link. The data folder loses group/other write access (start + every minute). |
| `agent/desks/cleanup.php` | Storeroom manifests (in group-writable run folders in shares) count only in Ms. Dustdevil's own shape: `as` = `<kind folder>/<name>` inside the run (no `..`, `.`, empty parts, control chars; folder matching the kind) or `@<dataset>` named `_UnraidSecretaryOffice-trash-<this run>-…`; `from`/`dataset` clean; stack volumes/images look like Docker names. Restore refuses when a folder between run and entry is a link; no run is created in a linked trash folder. The override file written into a stack's folder goes through `writeNewFile()`. Folders created in shares get their owner via `lchown`. |
| `embycache/embycache_lib.py` | `Locations.to_rel()` skips any path with a `..` part (from Emby's API or a mapping) — EmbyCache moves files (rsync, then delete source) along these paths. Listed in `embycache/README.md`. |
| `agent/desks/emby.php` | Path mappings only with clean absolute paths, host side under `/mnt/user/<share>` (`embyMappingOk()`). URL, API key, user id checks end at the end of the string. |
| several (snapshot, snapshotplans, house, backup, cleanup, emby, `src/api.php`, `src/place.php`) | Input validators carry the `D` modifier: PCRE's `$` also matches before a final newline, which then reached names, files or commands (e.g. a btrfs snapshot folder). |
| `public/assets/core.js` | `Office.safeHref(url)`: only `#…`, `/…` (not `//`) and `http(s)://` — else `null`. |
| `public/desks/{caretaker,advisor,whereabouts,cleanup}/desk.js` | Links built from state files (release link, Unraid address, links into Unraid, VM page) only through `Office.safeHref()`. The state files are `nobody:users` and read by the page straight from disk; a `javascript:` value would have run in Unraid's page on a click. |
| `agent/lib/officeupdate.php` | Release cache cleaned on write and read (version number; link only to `https://github.com/vipermark2/UnraidSecretaryOffice/…`); curl https only, ≤ 1 MB. `officeGit()` (stack update, root on a clone others may own) runs with `core.hooksPath=/dev/null`, `core.fsmonitor=false`, `protocol.ext.allow=never`. Files an update touched get their owner via `lchown`. |
| `agent/lib/house.php` | `hostLaunch()`: job file name and environment variable names must be plain names (they go unquoted into the atd script). |

## Checked and found fine

- **Web entry points (plugin):** everything under `/plugins/unraid-secretary-office/` is behind nginx `auth_request`; every POST needs Unraid's `csrf_token` (`local_prepend.php`); Unraid's session cookie is `SameSite=Strict`. The API changes nothing on GET (`state&fresh=1` only refreshes). POSTs also need `X-Office: 1` + JSON (CORS preflight) and a matching `Origin` — that is the stack's CSRF guard. Body ≤ 1 MiB, JSON depth 16. `agent/agent.php` runs nothing under a web SAPI (`PHP_SAPI === 'cli'` guard; desks only register functions).
- **PIN:** bcrypt (`password_hash`), HMAC-signed unlock cookie (`HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS) bound to the PIN hash (a new PIN locks every browser), 12 h, exponential waiting after 5 wrong tries. Hiring/firing and `office.read` need an unlocked browser.
- **open_actions** (no PIN): backup `log`, `setup_get`, `setup_plan`; cleanup `scan`, `measure`; emby `log`, `output`; logs `read`; snapshot `scan`, `estimate` (`zfs destroy -n`); whereabouts `scan`, `measure`, `sizes`. All read or measure (see recommendations for `setup_plan` and `wake`).
- **Commands:** all via `run()`/`runAll()`/`proc_open` array form; the only `sh -c` is the agent's own restart (`exec "$@"`, no values). `escapeshellarg` only for atd scripts and User Scripts files. Ids are re-validated against a fresh scan (snapshots, plans, cleanup entries, setup decisions against the plan, logs by id never path, Docker names from `docker ps`).
- **Settings/cron/ini writers:** cron expressions (`cronValid`) admit only digits, `*`, `,`, `-`, `/`; consolidate.ini values single-quoted and checked against existing shares/pools; setup decisions: keys from the plan, values without control characters, setup.sh validates again; the menu label is limited to letters/digits/` .&+_-`; templates get `htmlspecialchars` (XML), override files single-quoted YAML with a refused-if-unusual parser.
- **Pages:** no `innerHTML`/`insertAdjacentHTML` with data anywhere (only `= ''` to clear); everything is built with `textContent` (`Office.el`), tooltips via `textContent`, dialogs take text or nodes. The config JSON in the page uses `JSON_HEX_TAG|JSON_HEX_AMP`; the Dashboard tile's HTML is `htmlspecialchars`-escaped server-side.
- **Secrets:** the Emby API key never reaches a state file or the page (`embySettingsPublic`), travels to curl in a 0600 header file in `/var/run/unraid-secretary-office` (0700) and to Emby as a header, never in a URL. State files hold only variable *names* of database passwords. `data/unraid-backup`, `data/embycache`, `data/gather`, `data/office` are 0700; the backup place is root-only 0700. `.env` files are never shown (only their path).
- **Root jobs:** `job.sh`, `agent.sh`, event scripts, the `.plg` install/remove: fixed paths, all variables quoted, notification arguments as an array, temp files only in root-only places (`/var/run/…` 0700, `/var/log`, the flash). atd scripts quote every argument.
- **Outgoing network:** release check https only; icon fetches `--proto =http,https --proto-redir =http,https --max-redirs 3 --max-filesize 1 MB`, timeouts, PNG magic checked; user-typed picture addresses http(s) only, `file://` only for candidates the office found itself (local PNG under `/boot`, `/mnt`, `/usr/local/emhttp`, no `/../`, awake disk). Emby: no redirects followed by curl.
- **Backup engine / gather** (spot checks only, shared files left alone): `docker exec … sh -c` only with variable names from fixed lists; logs carry variable names, not passwords; the gather quotes paths and uses `rsync -a` (links copied as links).

## Recommended, not changed

- **State files owned by root instead of `nobody:users`.** `writeAtomic()` still defaults to `FILE_UID` 99. Anyone writing as `nobody` (guest on a public appdata share, PUID-99 containers that map appdata) can change `data/*.json`, which the page displays and a few agent paths read back (snapshot plans, notify state, release cache — now validated). Root-owned 0644 files would close this; left as is because "nobody:users like everything in appdata" is a deliberate convention.
- **`<appdata>/UnraidSecretaryOffice` owned by nobody** (`makeDataDir()`): whoever writes as `nobody` can rename `data` and put another folder there. The mailbox checks now stop requests from such a folder, but the PIN file (`data/office`) would be the swapped one — in the plugin that only removes the optional PIN (Unraid's login stays). Suggest root ownership for that folder on fresh installs.
- **`setup_plan` as an open action:** a viewer without the PIN can start `setup.sh --plan` (it takes the engine's lock for a while and may measure sizes). Consider requiring the PIN unless a plan is already there.
- **`wake` on open actions** (snapshot/cleanup/whereabouts `scan`): a viewer without the PIN can spin up sleeping disks. Harmless, but could require the PIN when `wake` is set.
- **PIN lockout is global**, not per client: someone in the LAN can keep the owner waiting (≤ 15 min per try). Acceptable for a LAN tool; per-IP counting would need a store.
- **Stack mode is inherently "write access to the clone = root"**: the agent runs the clone's PHP as root and restarts on change. Documented, not fixable in code; the plugin doesn't have this.
- **EmbyCache's urllib follows redirects** and forwards `X-Emby-Token` to the redirect target (also another host). Only a concern if the configured Emby URL redirects; a no-redirect opener would close it (EmbyCache code, left as is).
- **VM names into `settings.ini`** (`[vm "<name>"]` in setup.sh): libvirt allows quotes; setup.sh's cross-check (`cfg_validate`) refuses a broken file, but an explicit name check in the engine would be cleaner (engine is shared, not touched here).

## Note for deploying

`dataDirTighten()` removes group/other write access from the data folder at
agent start. On a development server where `data/` is the clone's folder
(e.g. `drwxrwxrwx benj:users`) it becomes `drwxr-xr-x`: the owner keeps
access, other SMB users don't. Nothing of the office needs more (the stack's
web container only writes into `mailbox/` and `office/`, which it owns).
