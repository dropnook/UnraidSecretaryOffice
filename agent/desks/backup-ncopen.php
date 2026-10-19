<?php
declare(strict_types=1);

/*
 * Nextcloud's data folder readable by others (engine 2.62, the warning nc_datadir_readable): Unraid resets every share's
 * root to 0777 nobody:users when a share is added or any share's settings are saved - unless the root belongs to the
 * group users and an owner other than nobody (7.4.0-rc.1). Nextcloud then refuses to work (its page too) and the run
 * goes on without its maintenance mode. One click closes it (2026-10-19): after a dialog that shows what changes, the
 * agent sets the folder's root - only the root, never what is inside - to Nextcloud's user, group users, 0770 (what
 * Nextcloud itself wants) and looks again.
 *
 *   - What: only a folder the engine named itself (the last run's warning_items: name, path, uid) - nothing from the
 *     page but the container's name; the action checks that the path is that Nextcloud's data folder now (its mounts
 *     and config.php, backupNextcloudDataDir()), a real folder (an exclusive share's /mnt/user link to its pool only).
 *   - Owner: Nextcloud's user as the engine found it (docker exec id -u). With nobody (99) Unraid would open it again
 *     at the next «Add Share» or «Apply» of a share - the dialog says so (`comes_back`).
 *   - Never on a sleeping disk: a folder whose disk or pool sleeps isn't looked at (not listed, the action refuses).
 *   - state.nc_open lists only folders still open now (one stat each), so the warning goes once it is closed.
 */

const BACKUP_NC_OPEN_MODE = 0770;            // what Nextcloud's checkDataDirectoryPermissions() sets itself
const BACKUP_NOBODY = 99;                    // Unraid's nobody: a root it owns is opened again (chmod 0777)

/** The group users' id (Unraid's 100) */
function backupUsersGid(): int
{
    $g = function_exists('posix_getgrnam') ? @posix_getgrnam('users') : false;
    return is_array($g) && is_int($g['gid'] ?? null) ? $g['gid'] : 100;
}

/** A folder named by the engine, in exactly its shape: /mnt/<base>/<path>, no dots, no control characters */
function backupNcPathOk(string $path, string $mnt = '/mnt'): bool
{
    $q = preg_quote($mnt, '#');
    return (bool) preg_match('#^' . $q . '/[^/\x00-\x1f]+(?:/[^/\x00-\x1f]+)+$#D', $path)
        && !preg_match('#/\.\.?(?:/|$)#', $path) && !preg_match("#^$q/(?:addons|disks|remotes|rootshare)/#", $path);
}

/** Where to look at and change the folder: an exclusive share's /mnt/user path as its pool path; null when it isn't a real folder */
function backupNcReal(string $path, string $mnt = '/mnt'): ?string
{
    $real = officeUnraidPath($path, $mnt);
    clearstatcache(true, $real);
    return !is_link($real) && is_dir($real) ? $real : null;
}

/** A folder's root as the dialog shows it: owner, group (their ids and names), the permission bits */
function backupNcStat(string $real): ?array
{
    clearstatcache(true, $real);
    $st = @lstat($real);
    if (!$st) {
        return null;
    }
    $user = function_exists('posix_getpwuid') ? @posix_getpwuid($st['uid']) : false;
    $group = function_exists('posix_getgrgid') ? @posix_getgrgid($st['gid']) : false;
    return ['uid' => $st['uid'], 'gid' => $st['gid'], 'user' => is_array($user) ? (string) $user['name'] : (string) $st['uid'],
            'group' => is_array($group) ? (string) $group['name'] : (string) $st['gid'], 'mode' => sprintf('%o', $st['mode'] & 0777)];
}

/**
 * The Nextcloud data folders the last run found readable by others and still open now - pure but for one stat each.
 * $run: the newest run (backupHistory()[0]); $asleep(path): does its disk sleep. One entry per folder:
 * {name, path, uid, now: backupNcStat(), after: {uid, gid, user, group, mode}, comes_back, share_root}
 */
function backupNcOpenList(?array $run, callable $asleep, string $mnt = '/mnt'): array
{
    $out = [];
    $seen = [];
    foreach ((array) ($run['warning_items'] ?? []) as $w) {
        $p = is_array($w) && ($w['code'] ?? '') === 'nc_datadir_readable' && is_array($w['params'] ?? null) ? $w['params'] : null;
        $name = (string) ($p['name'] ?? '');
        $path = rtrim((string) ($p['path'] ?? ''), '/');
        $uid = (string) ($p['uid'] ?? '');
        if (!preg_match('/^[\w.@-]{1,128}$/D', $name) || !backupNcPathOk($path, $mnt) || !preg_match('/^[1-9]\d{0,5}$/D', $uid) || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        if ($asleep($path) || ($real = backupNcReal($path, $mnt)) === null || ($now = backupNcStat($real)) === null || (octdec($now['mode']) & 0007) === 0) {
            continue;
        }
        $out[] = ['name' => $name, 'path' => $path, 'uid' => (int) $uid, 'now' => $now, 'after' => backupNcAfter((int) $uid),
                  'comes_back' => (int) $uid === BACKUP_NOBODY, 'share_root' => (bool) preg_match('#^' . preg_quote($mnt, '#') . '/[^/]+/[^/]+$#D', $path)];
    }
    return $out;
}

/** What the folder's root becomes: Nextcloud's user, group users, 0770 */
function backupNcAfter(int $uid): array
{
    $user = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : false;
    return ['uid' => $uid, 'gid' => backupUsersGid(), 'user' => is_array($user) ? (string) $user['name'] : (string) $uid, 'group' => 'users',
            'mode' => sprintf('%o', BACKUP_NC_OPEN_MODE)];
}

/** For backupScan(): the open folders of the newest run (never a sleeping disk) */
function backupNcOpen(array $history, array $settings): array
{
    $disks = sleepingDisks();
    return backupNcOpenList($history[0] ?? null, fn (string $p) => backupPathAsleep($p, $settings, $disks), $GLOBALS['ncOpenMnt'] ?? '/mnt');
}

/**
 * «Close the folder»: the named Nextcloud's open data folder (looked up again in a fresh look), its root set to
 * Nextcloud's user, group users, 0770 - never recursive - and looked at again. {ok, name, path, before, after}
 */
function backupNcClose(array $r): array
{
    $name = textField($r, 'name');
    if (!preg_match('/^[\w.@-]{1,128}$/D', $name)) {
        throw new Problem('bad_request');
    }
    // the tests hand in the look ($GLOBALS['ncOpenList'], ['ncOpenAsleep'], ['ncOpenDataDir'], ['ncOpenMnt'])
    $test = isset($GLOBALS['ncOpenList']);
    $item = null;
    foreach ($test ? $GLOBALS['ncOpenList'] : (backupScan()['nc_open'] ?? []) as $o) {
        if ($o['name'] === $name) {
            $item = $o;
        }
    }
    if ($item === null) {
        throw new Problem('nc_open_gone', ['name' => $name]);
    }
    $mnt = $GLOBALS['ncOpenMnt'] ?? '/mnt';
    $settings = $test ? [] : backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    if ($test ? !empty($GLOBALS['ncOpenAsleep']) : backupPathAsleep($item['path'], $settings, sleepingDisks())) {
        throw new Problem('nc_open_asleep', ['name' => $name]);
    }
    // the engine's path is this Nextcloud's data folder now (its mounts and config.php) - nothing else is ever changed
    $dir = isset($GLOBALS['ncOpenDataDir']) ? ($GLOBALS['ncOpenDataDir'])($name) : backupNextcloudDataDir($name, $sleeps, $settings);
    $real = backupNcReal($item['path'], $mnt);
    if ($dir === null || rtrim($dir, '/') !== $item['path'] || $real === null) {
        throw new Problem('nc_open_not_data', ['name' => $name, 'path' => $item['path']]);
    }
    $before = backupNcStat($real);
    $after = $item['after'];
    $ok = @chown($real, $after['uid']) && @chgrp($real, $after['gid']) && @chmod($real, BACKUP_NC_OPEN_MODE);
    $now = backupNcStat($real);
    $closed = $now !== null && $now['uid'] === $after['uid'] && $now['gid'] === $after['gid'] && $now['mode'] === $after['mode'];
    logLine(sprintf("Mr. Backup: Nextcloud '%s' data folder %s closed: %s:%s %s -> %s:%s %s%s", $name, $item['path'], $before['uid'] ?? '?', $before['gid'] ?? '?',
        $before['mode'] ?? '?', $now['uid'] ?? '?', $now['gid'] ?? '?', $now['mode'] ?? '?', $ok && $closed ? '' : ' (FAILED)'));
    if (!$ok || !$closed) {
        throw new Problem('nc_open_failed', ['name' => $name, 'path' => $item['path']]);
    }
    return ['ok' => true, 'name' => $name, 'path' => $item['path'], 'before' => $before, 'after' => $now, 'state' => $test ? null : backupScan()];
}
