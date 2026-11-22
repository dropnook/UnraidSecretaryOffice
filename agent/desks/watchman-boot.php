<?php
declare(strict_types=1);

/*
 * The Night Watchman: what runs at the server's start (1.96, the concept's section 11 F «Was beim Start ausgeführt
 * wird»). Before this he looked at go, /boot/extra, the users, passwords and SSH keys on the flash; a plugin he saw only
 * once it had run as root. Now also what the flash holds for the NEXT start, and one file in RAM:
 *
 *   /boot/config/plugins/*.plg     Unraid installs every plugin lying there at each start (rc.local). A plugin file that
 *                                  is new or changed there while nothing installed it: `flash_boot`. A plugin installed
 *                                  or updated by the plugin manager (or at the start) is the plugin watch's business:
 *                                  it writes the file and links it in /var/log/plugins at the same time — the link no
 *                                  older than the file (or a plain file there with the same content) is that sign,
 *                                  quiet. Seen in two rounds in a row before it counts (an install half done is no news).
 *   /boot/config/stop              runs when the server shuts down (on Unraid: not verified)
 *   /boot/config/modprobe.d/*.conf can run a command whenever a driver loads (install/remove lines)
 *   the start line                 syslinux.cfg and, where there is one, GRUB's: the kernel's options (mitigations=off …)
 *                                  — the entry names the options added and removed
 *   /boot/config/smb-extra.conf    Samba can run a command as root at every connection (preexec and the like)
 *   /boot/bz*                      the kernel and its images: size and time only (never read). Changed while Unraid's
 *                                  own plugin (unRAIDServer.plg) got another version or /boot/changes.txt changed: an
 *                                  update, quiet; otherwise `flash_boot`. Two rounds in a row as for the plugins.
 *   /etc/ld.so.preload             (RAM) shouldn't exist on Unraid: whatever it names is loaded into every program. New
 *                                  or changed: `ld_preload` (the area «The server itself»).
 *
 * A file is read only when its size or time changed since the last round (the cron files' rule); each read is capped.
 * His first look is normal, like everything else. A file removed is safer: forgotten quietly. The night shift looks too
 * (the flash and /etc are its ground); while Unraid still installs the plugins at boot the plugin files aren't judged
 * (watchmanBootPluginsDone()). What he keeps: sizes, times and hashes — never a file's text, but the options of the start
 * line and the names in ld.so.preload, which the entry shows. Internal boot (Unraid 7.3, the flash as a pool): not
 * verified — the same paths.
 */

const WATCH_BOOT_PLG_MAX   = 500;            // plugin files on the flash looked at
const WATCH_BOOT_MOD_MAX   = 100;            // modprobe.d files
const WATCH_BOOT_READ      = 1048576;        // a file read at most this far (beyond: its first MB and its size)
const WATCH_BOOT_LINK_SLACK = 600;           // the plugin manager's link in /var/log/plugins may be this much older than the file it wrote
const WATCH_BOOT_OPTS_MAX  = 100;            // start options kept per file
const WATCH_BOOT_SHOW      = 20;             // options or names an entry shows, each …
const WATCH_BOOT_WIDTH     = 120;            // … cut at this many characters
const WATCH_BOOT_WAIT      = 2;              // rounds in a row a plugin or kernel file must be seen changed (an install or update half done is no news)
// Samba's parameters that run a program (smb.conf(5)): what smb-extra.conf's entry names when it has one
const WATCH_BOOT_SMB_EXEC = ['preexec', 'postexec', 'root preexec', 'root postexec', 'exec', 'magic script', 'add user script',
    'delete user script', 'add group script', 'delete group script', 'add user to group script', 'delete user from group script',
    'set primary group script', 'add machine script', 'rename user script', 'check password script', 'message command', 'print command',
    'lpq command', 'lprm command', 'dfree command', 'get quota command', 'set quota command', 'panic action', 'username map script',
    'addprinter command', 'deleteprinter command', 'addport command', 'enumports command', 'shutdown script', 'abort shutdown script'];

/** Where he looks for what runs at the start — the round's paths (watchmanPaths()); a missing one is not looked at */
function watchmanBootPaths(): array
{
    return [
        'flash_plg'  => '/boot/config/plugins',
        'flash_stop' => '/boot/config/stop',
        'modprobe_d' => '/boot/config/modprobe.d',
        'start_cfg'  => ['syslinux' => '/boot/syslinux/syslinux.cfg', 'grub' => '/boot/grub/grub.cfg'],
        'smb_extra'  => '/boot/config/smb-extra.conf',
        'boot_dir'   => '/boot',
        'os_changes' => '/boot/changes.txt',
        'ld_preload' => '/etc/ld.so.preload',
    ];
}

/**
 * A file's size and time, and — read only when those changed since $prev — a hash of its content (and what $more takes
 * from the text). Null: not a plain file (gone, a folder, a link).
 */
function watchmanBootFile(string $file, ?array $prev, ?callable $more = null): ?array
{
    clearstatcache(true, $file);
    $st = @lstat($file);
    if ($st === false || ($st['mode'] & 0170000) !== 0100000) {
        return null;
    }
    $f = ['size' => (int) $st['size'], 'time' => (int) $st['mtime']];
    if (is_array($prev) && (int) ($prev['size'] ?? -1) === $f['size'] && (int) ($prev['time'] ?? -1) === $f['time'] && is_string($prev['h'] ?? null)) {
        return $f + array_diff_key($prev, $f);           // as the last round read it
    }
    $text = @file_get_contents($file, false, null, 0, WATCH_BOOT_READ);
    if ($text === false) {
        return $f + ['h' => null];
    }
    $f['h'] = substr(hash('sha256', $text), 0, 16);
    if ($more !== null) {
        $f += $more($text);
    }
    unset($text);
    return $f;
}

/** The kernel options of a start file: every word of syslinux's `append` lines and GRUB's `linux` lines (sorted, unique) */
function watchmanBootOpts(string $text): array
{
    $opts = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $l) {
        if (preg_match('/^\s*(append|linux(?:efi)?)\s+(.*)$/i', $l, $m)) {
            $words = preg_split('/\s+/', trim($m[2])) ?: [];
            if (strtolower($m[1]) !== 'append') {
                array_shift($words);        // GRUB's linux line: the kernel's file first, then its options
            }
            foreach ($words as $w) {
                if ($w !== '' && count($opts) < WATCH_BOOT_OPTS_MAX) {
                    $opts[watchmanClean($w, WATCH_BOOT_WIDTH)] = true;
                }
            }
        }
    }
    $opts = array_keys($opts);
    sort($opts);
    return $opts;
}

/** Samba's parameters in smb-extra.conf that run a program (WATCH_BOOT_SMB_EXEC), by name */
function watchmanBootSmbExec(string $text): array
{
    $found = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $l) {
        if (preg_match('/^\s*([a-z][a-z ]*?)\s*=/i', $l, $m)) {
            $name = strtolower((string) preg_replace('/\s+/', ' ', $m[1]));
            if (in_array($name, WATCH_BOOT_SMB_EXEC, true)) {
                $found[$name] = true;
            }
        }
    }
    $found = array_keys($found);
    sort($found);
    return array_slice($found, 0, WATCH_BOOT_SHOW);
}

/** The names ld.so.preload lists (its words, comments left out), at most WATCH_BOOT_SHOW */
function watchmanBootPreloadNames(string $text): array
{
    $names = [];
    foreach (preg_split('/[\s:]+/', (string) preg_replace('/#[^\n]*/', '', $text)) ?: [] as $w) {
        if ($w !== '' && count($names) < WATCH_BOOT_SHOW) {
            $names[watchmanClean($w, WATCH_BOOT_WIDTH)] = true;
        }
    }
    return array_keys($names);
}

/**
 * What runs at the start, as it is now ($prev: the last round's look — a file the same as then isn't read again). Null:
 * none of its paths given (not looked at). `files`: id => {size, time, h, …}; ids plg:<name>, stop, modprobe:<file>,
 * start:syslinux|grub, smb_extra, kernel:<file>, preload. `looked`: the groups looked at (a group not looked at keeps what
 * was known). `installed`: per plugin in /var/log/plugins the time of its link (or plain file) and, for a plain file, its
 * hash. `os`: the version of Unraid's own plugin on the flash; `changes`: /boot/changes.txt's hash.
 */
function watchmanBootLook(array $paths, ?array $prev = null): ?array
{
    $keys = ['flash_plg', 'flash_stop', 'modprobe_d', 'start_cfg', 'smb_extra', 'boot_dir', 'ld_preload'];
    if (!array_intersect_key($paths, array_flip($keys))) {
        return null;
    }
    $pf = is_array($prev['files'] ?? null) ? $prev['files'] : [];
    $files = [];
    $looked = [];
    $installed = [];
    $os = $changes = null;
    if (isset($paths['flash_plg'])) {
        $looked[] = 'plg';
        $n = 0;
        foreach (glob($paths['flash_plg'] . '/*.plg') ?: [] as $file) {
            $name = basename($file, '.plg');
            if (!preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $name) || ++$n > WATCH_BOOT_PLG_MAX) {
                continue;
            }
            $f = watchmanBootFile($file, $pf["plg:$name"] ?? null, fn (string $t): array => ['v' => watchmanPluginUrl(substr($t, 0, 65536))[1]]);
            if ($f !== null) {
                $files["plg:$name"] = $f;
            }
        }
        $os = $files['plg:unRAIDServer']['v'] ?? null;
        // what the plugin manager (or the start) installed: its link in /var/log/plugins, or a plain file there
        if (isset($paths['plugins'])) {
            foreach (glob($paths['plugins'] . '/*.plg') ?: [] as $file) {
                $name = basename($file, '.plg');
                $st = @lstat($file);
                if (!isset($files["plg:$name"]) || $st === false) {
                    continue;
                }
                $i = ['time' => (int) $st['mtime']];
                if (($st['mode'] & 0170000) === 0100000) {
                    $text = @file_get_contents($file, false, null, 0, WATCH_BOOT_READ);
                    $i['h'] = $text === false ? null : substr(hash('sha256', $text), 0, 16);
                    unset($text);
                }
                $installed[$name] = $i;
            }
        }
    }
    if (isset($paths['flash_stop'])) {
        $looked[] = 'stop';
        $f = watchmanBootFile((string) $paths['flash_stop'], $pf['stop'] ?? null);
        if ($f !== null) {
            $files['stop'] = $f;
        }
    }
    if (isset($paths['modprobe_d'])) {
        $looked[] = 'modprobe';
        $n = 0;
        foreach (glob($paths['modprobe_d'] . '/*.conf') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $name) || ++$n > WATCH_BOOT_MOD_MAX) {
                continue;
            }
            $f = watchmanBootFile($file, $pf["modprobe:$name"] ?? null);
            if ($f !== null) {
                $files["modprobe:$name"] = $f;
            }
        }
    }
    if (isset($paths['start_cfg'])) {
        $looked[] = 'start';
        foreach ((array) $paths['start_cfg'] as $which => $file) {
            $f = watchmanBootFile((string) $file, $pf["start:$which"] ?? null, fn (string $t): array => ['opts' => watchmanBootOpts($t)]);
            if ($f !== null) {
                $files["start:$which"] = $f;
            }
        }
    }
    if (isset($paths['smb_extra'])) {
        $looked[] = 'smb_extra';
        $f = watchmanBootFile((string) $paths['smb_extra'], $pf['smb_extra'] ?? null, fn (string $t): array => ['exec' => watchmanBootSmbExec($t)]);
        if ($f !== null) {
            $files['smb_extra'] = $f;
        }
    }
    if (isset($paths['boot_dir'])) {
        $looked[] = 'kernel';
        foreach (glob($paths['boot_dir'] . '/bz*') ?: [] as $file) {
            $name = basename($file);
            $st = @lstat($file);
            if (preg_match('/^bz[a-z0-9._-]{1,40}$/D', $name) && $st !== false && ($st['mode'] & 0170000) === 0100000) {
                $files["kernel:$name"] = ['size' => (int) $st['size'], 'time' => (int) $st['mtime'], 'h' => $st['size'] . ':' . $st['mtime']];
            }
        }
        if (isset($paths['os_changes'])) {
            $changes = watchmanBootFile((string) $paths['os_changes'], $prev['changes_file'] ?? null);
        }
    }
    if (isset($paths['ld_preload'])) {
        $looked[] = 'preload';
        $f = watchmanBootFile((string) $paths['ld_preload'], $pf['preload'] ?? null, fn (string $t): array => ['names' => watchmanBootPreloadNames($t)]);
        if ($f !== null) {
            $files['preload'] = $f;
        }
    }
    ksort($files);
    return ['files' => $files, 'looked' => $looked, 'installed' => $installed, 'os' => $os,
            'changes' => is_array($changes) ? ($changes['h'] ?? null) : null, 'changes_file' => $changes];
}

/** The group of a file's id (what `looked` names) */
function watchmanBootGroup(string $id): string
{
    return explode(':', $id, 2)[0];
}

/** What he keeps of a file: size, time, hash and what an entry compares (the start options) */
function watchmanBootKeep(array $f): array
{
    return array_intersect_key($f, ['size' => 1, 'time' => 1, 'h' => 1, 'opts' => 1, 'v' => 1]);
}

/**
 * What runs at the start against what is normal ($known: the baseline's flash.boot — files, os, changes, wait). New or
 * changed: `flash_boot` (ld.so.preload: `ld_preload`), one open entry per file. A plugin file the plugin manager (or the
 * start) installed, kernel files of an Unraid update: normal, quiet. A plugin or kernel file must be seen changed in
 * WATCH_BOOT_WAIT rounds in a row (`wait`). Removed: forgotten. A group not looked at this round keeps what was known.
 * A baseline without this part (an older office's, a file by hand): what he sees now is normal.
 */
function watchmanBootCompare(?array &$known, ?array $seen, array &$book, int $now): array
{
    if ($seen === null) {
        return [];
    }
    $looked = array_flip((array) ($seen['looked'] ?? []));
    if (!is_array($known) || !is_array($known['files'] ?? null)) {
        $known = ['files' => array_map('watchmanBootKeep', (array) $seen['files']), 'os' => $seen['os'] ?? null, 'changes' => $seen['changes'] ?? null,
                  'wait' => []];
        return [];
    }
    $known['wait'] = is_array($known['wait'] ?? null) ? $known['wait'] : [];
    $osMoved = isset($looked['kernel']) && (($known['os'] ?? null) !== ($seen['os'] ?? null) || ($known['changes'] ?? null) !== ($seen['changes'] ?? null));
    $added = [];
    $waiting = [];
    foreach ((array) $seen['files'] as $id => $f) {
        $id = (string) $id;
        $k = is_array($known['files'][$id] ?? null) ? $known['files'][$id] : null;
        if ($k !== null && ($k['h'] ?? null) === ($f['h'] ?? null)) {
            $known['files'][$id] = watchmanBootKeep($f);       // the same content (a time touched): as it is now
            continue;
        }
        $group = watchmanBootGroup($id);
        if ($group === 'plg' || $group === 'kernel') {
            $name = $group === 'plg' ? substr($id, 4) : '';
            $inst = $seen['installed'][$name] ?? null;
            $quiet = $group === 'plg'
                ? is_array($inst) && (isset($inst['h']) ? $inst['h'] === ($f['h'] ?? null) : (int) $inst['time'] >= (int) $f['time'] - WATCH_BOOT_LINK_SLACK)
                : $osMoved;
            if ($quiet) {
                $known['files'][$id] = watchmanBootKeep($f);    // installed or updated as Unraid does it: the plugin watch's (or an update)
                continue;
            }
            $w = is_array($known['wait'][$id] ?? null) && ($known['wait'][$id]['h'] ?? null) === ($f['h'] ?? null) ? (int) $known['wait'][$id]['n'] + 1 : 1;
            if ($w < WATCH_BOOT_WAIT) {
                $waiting[$id] = ['h' => $f['h'] ?? null, 'n' => $w];
                continue;
            }
            $waiting[$id] = ['h' => $f['h'] ?? null, 'n' => $w];
        }
        $added[] = watchmanBootEntry($book, $id, $f, $k, isset($seen['installed'][substr($id, 4)]), $now);
    }
    // what is gone is safer: forgotten (only in the groups looked at)
    foreach (array_keys((array) $known['files']) as $id) {
        if (!isset($seen['files'][$id]) && isset($looked[watchmanBootGroup((string) $id)])) {
            unset($known['files'][$id]);
        }
    }
    foreach ($known['wait'] as $id => $w) {
        if (!isset($looked[watchmanBootGroup((string) $id)])) {
            $waiting[$id] ??= $w;            // a group not looked at this round: its waits stay as they were
        }
    }
    $known['wait'] = $waiting;
    if (isset($looked['kernel'])) {
        $known['os'] = $seen['os'] ?? null;
        $known['changes'] = $seen['changes'] ?? null;
    }
    return array_values(array_filter($added));
}

/** The entry for a file new or changed ($k: as it was known, null: new) — `ld_preload` for ld.so.preload, else `flash_boot` */
function watchmanBootEntry(array &$book, string $id, array $f, ?array $k, bool $installed, int $now): ?string
{
    if ($id === 'preload') {
        return watchmanSet($book, 'ld_preload', 'ld_preload', $now, ['names' => (array) ($f['names'] ?? []), 'new' => $k === null, '_h' => $f['h'] ?? null]);
    }
    $group = watchmanBootGroup($id);
    $name = explode(':', $id, 2)[1] ?? '';
    $p = ['what' => $group, 'file' => match ($group) {
              'plg' => "$name.plg", 'stop' => 'stop', 'modprobe' => $name, 'start' => $name === 'grub' ? 'grub.cfg' : 'syslinux.cfg',
              'smb_extra' => 'smb-extra.conf', default => $name,
          }, 'new' => $k === null, '_h' => $f['h'] ?? null];
    if ($group === 'plg') {
        $p['version'] = is_string($f['v'] ?? null) ? watchmanClean($f['v'], 60) : null;
        $p['installed'] = $installed;
    } elseif ($group === 'start') {
        $was = (array) ($k['opts'] ?? []);
        $is = (array) ($f['opts'] ?? []);
        $p['added'] = array_slice(array_values(array_diff($is, $was)), 0, WATCH_BOOT_SHOW);
        $p['removed'] = array_slice(array_values(array_diff($was, $is)), 0, WATCH_BOOT_SHOW);
    } elseif ($group === 'smb_extra') {
        $p['exec'] = (array) ($f['exec'] ?? []);
    }
    return watchmanSet($book, 'flash_boot', "flash_boot:$id", $now, $p);
}

/** «I know, thanks» on flash_boot or ld_preload: the file as the last round saw it is normal (gone meanwhile: forgotten) */
function watchmanBootAdopt(array &$b, array $e, array $seen): void
{
    $id = $e['kind'] === 'ld_preload' ? 'preload' : substr((string) ($e['key'] ?? ''), strlen('flash_boot:'));
    if ($id === '' || !is_array($b['flash']['boot']['files'] ?? null)) {
        return;
    }
    $f = $seen['flash']['boot']['files'][$id] ?? null;
    if (is_array($f)) {
        $b['flash']['boot']['files'][$id] = watchmanBootKeep($f);
    } else {
        unset($b['flash']['boot']['files'][$id]);
    }
    unset($b['flash']['boot']['wait'][$id]);
}

/** An entry's words (entry.<kind>, check.<kind>, notify.<kind>): the file, the plugin's version, the options, the names */
function watchmanBootText(array $e): array
{
    $p = (array) ($e['p'] ?? []);
    $list = fn (string $k) => implode(' ', array_map('strval', (array) ($p[$k] ?? [])));
    if ($e['kind'] === 'ld_preload') {
        return ['names' => implode(', ', array_map('strval', (array) ($p['names'] ?? []))) ?: '–'];
    }
    return ['file' => (string) ($p['file'] ?? ''), 'version' => (string) ($p['version'] ?? '') ?: '–',
            'added' => $list('added') ?: '–', 'removed' => $list('removed') ?: '–',
            'exec' => implode(', ', array_map('strval', (array) ($p['exec'] ?? []))) ?: '–'];
}

/** The shape of a flash_boot entry's words: entry.flash_boot_<what> (a plugin new or installed, changed; the start line …) */
function watchmanBootVariant(array $e): ?string
{
    if (($e['kind'] ?? '') !== 'flash_boot') {
        return null;
    }
    $what = (string) ($e['p']['what'] ?? '');
    if ($what === 'plg') {
        return 'flash_boot_' . (!empty($e['p']['installed']) ? 'plg_changed' : 'plg');
    }
    return in_array($what, ['stop', 'modprobe', 'start', 'smb_extra', 'kernel'], true) ? "flash_boot_$what" : null;
}
