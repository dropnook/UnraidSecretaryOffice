# Working on Unraid Secretary Office

Read README.md first — it explains the architecture and how a secretary (desk) is built.
This file holds the conventions and the checklist for changes.

## Conventions

* **English** in code, comments, commit messages, README. The UI is translated:
  every string lives in `public/lang/<code>.json` (office) or
  `public/desks/<id>/lang/<code>.json` (desk). `en` and `de` must always have the
  same keys. Never hard-code UI text in JS or PHP.
* **No build step, no dependencies.** Plain PHP 8.4 (agent runs on Unraid's PHP)
  and PHP 8.5 in the office container, vanilla JS, one CSS file plus optional
  `desk.css`. The look follows levelnext Airdrop (tokens in `office.css :root`).
* **The office container never touches the host.** Everything that needs zfs,
  docker, /boot/config, /proc etc. goes through the agent (`Office.api.post('<desk>.<action>')`
  → `agent/desks/<desk>.php`).
* **Agent safety:** commands via `run()`/`runAll()` (array form, no shell). Every
  action re-reads the current state and validates ids against it. Errors are
  `throw new Problem('key', [...])`; the UI translates `errors.<key>`
  (desk-specific keys in the desk's lang file).
* **Never wake sleeping disks on your own.** Check `sleepingDisks()` before
  reading array disks; offer an explicit "wake" option instead.
* **Never block the array stop:** no sockets or open files in the pool; the agent
  keeps nothing open there.
* **Keep `tick` functions cheap** — they run every ~150 ms. Long work (like `du`)
  runs as a background process polled from `tick` (see whereabouts sizes).
* Desk lang keys `name` and `role` are the desk's title and subtitle — don't reuse them.

## Checklist for a change

1. PHP syntax: on the host `php -l` for every file in `agent/`; in the office
   container `docker exec UnraidSecretaryOffice php -l /var/www/src/<file>`.
2. JS syntax (no Node on the dev Mac): `osascript -l JavaScript` with `new Function(src)`.
3. JSON valid and `en`/`de` keys identical (a short python check is enough).
4. The agent restarts itself when its files change — watch `data/agent.log`
   ("Agent code changed — restarting"); a syntax error keeps the old code running.
5. Reload the page for real (changing only the `#` part of the URL doesn't reload).
6. Test destructive actions only on throwaway objects, then clean up.

## Layout

```
agent/agent.php          loop, mailbox, desk loading, self-restart
agent/lib/*.php          shared helpers (util: run, writeAtomic, readCfg, Problem;
                         mounts; backupscript)
agent/desks/<id>.php     one secretary each: desk('<id>', [...])
src/*.php                web side: bootstrap, mailbox client, desk/lang discovery, API, page
public/assets/core.js    Office: i18n, routing, reception, API, dialog, menu, toast, fmt
public/desks/<id>/       desk.json, desk.js, lang/*.json (and desk.css)
data/                    runtime only (state per desk, mailbox, agent log) — not in git
compose.yaml             office + agent services; settings in .env
```
