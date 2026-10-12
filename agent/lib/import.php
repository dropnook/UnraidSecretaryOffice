<?php
declare(strict_types=1);

/*
 * Taking over an earlier install (shared by Jack Emby's EmbyCache/gather import and Ms. Moverelli's Smart Mover import).
 * The user types the folder where the old files lie — a desk never searches. Before anything there is read the folder
 * is checked (importFolder()): absolute, no "." or "..", under /mnt/user/<share>, a pool, an array disk or User
 * Scripts' folder on the flash; the disks behind it awake (nobody here wakes one); every part a real folder — no link,
 * but Unraid's own of an exclusive share (/mnt/user/<share> → ../<pool>/<share>); not one of the desk's own folders.
 * Then only known files are read there (importRead(): plain, one hard link, size-capped). Before a desk overwrites a
 * file of its own, a copy stays next to it (importBackup(): <file>.before-import-<time>).
 *
 * The refusals are Problems named after the desk: "<prefix>_folder|where|asleep|missing|link|own|file|big" — each desk
 * has the texts in its lang files (Jack: emby_import, Ms. Moverelli: moverelli_import).
 *
 * $ctx: pools (["/mnt/<pool>", …]), asleep (sleepingDisks()), share_cfg (fn(share): its cfg as key => value), fs (a
 * prefix for the tests' fake tree, '' on the server), own (the desk's folders that are never read from), own_below
 * (optional: folders never read from, nor anything below them — the office's data folder for Ms. Moverelli).
 */

/** The folder typed for an earlier install, checked before anything in it is read. Returns the real folder to read from. */
function importFolder(string $path, array $ctx, string $prefix): string
{
    $path = rtrim($path, '/');
    if (strlen($path) > 1024 || !preg_match('#^(/[^/\x00-\x1f\x7f]+)+$#D', $path)
        || array_intersect(explode('/', substr($path, 1)), ['.', '..'])) {
        throw new Problem("{$prefix}_folder", ['path' => $path]);
    }
    $pools = (array) $ctx['pools'];
    if (preg_match('#^/mnt/user/([^/]+)(?:/|$)#', $path, $m)) {
        if (!preg_match('/^[\w.\- ]+$/uD', $m[1])) {
            throw new Problem("{$prefix}_where", ['path' => $path]);
        }
        $bases = importShareBases($m[1], $ctx);
    } elseif (preg_match('#^/mnt/(disk\d+)(?:/|$)#', $path, $m) || (preg_match('#^/mnt/([^/]+)(?:/|$)#', $path, $m) && in_array("/mnt/{$m[1]}", $pools, true))) {
        $bases = [$m[1]];
    } elseif (preg_match('#^/boot/config/plugins/user\.scripts/scripts/[^/]+(?:/|$)#', $path)) {
        $bases = [];                                    // the flash never sleeps
    } else {
        throw new Problem("{$prefix}_where", ['path' => $path]);
    }
    $sleeping = array_values(array_filter($bases, fn ($b) => baseAsleep((string) $b, (array) $ctx['asleep'])));
    if ($sleeping) {
        throw new Problem("{$prefix}_asleep", ['path' => $path, 'disks' => implode(', ', $sleeping)]);
    }
    $fs = (string) ($ctx['fs'] ?? '');
    $real = '';
    foreach (explode('/', substr($path, 1)) as $i => $part) {
        $next = "$real/$part";
        clearstatcache(true, $fs . $next);
        $st = @lstat($fs . $next);
        if ($st && ($st['mode'] & 0170000) === 0120000 && $i === 2 && str_starts_with($path, '/mnt/user/')) {
            // an exclusive share: exactly ../<pool>/<share> or /mnt/<pool>/<share>, an awake pool of this server
            $to = (string) @readlink($fs . $next);
            if (preg_match('#^(?:\.\./|/mnt/)([^/]+)/([^/]+)/?$#D', $to, $t) && $t[2] === $part && in_array("/mnt/{$t[1]}", $pools, true)) {
                if (baseAsleep($t[1], (array) $ctx['asleep'])) {
                    throw new Problem("{$prefix}_asleep", ['path' => $path, 'disks' => $t[1]]);
                }
                $next = "/mnt/{$t[1]}/$part";
                clearstatcache(true, $fs . $next);
                $st = @lstat($fs . $next);
            }
        }
        if (!$st) {
            throw new Problem("{$prefix}_missing", ['path' => $path]);
        }
        if (($st['mode'] & 0170000) === 0120000) {
            throw new Problem("{$prefix}_link", ['path' => $next]);
        }
        if (($st['mode'] & 0170000) !== 0040000) {
            throw new Problem("{$prefix}_missing", ['path' => $path]);
        }
        $real = $next;
    }
    $here = realpath($fs . $real);
    foreach ((array) ($ctx['own'] ?? []) as $own) {
        $mine = realpath((string) $own);
        if ($mine !== false && $here === $mine) {
            throw new Problem("{$prefix}_own", ['path' => $path]);
        }
    }
    foreach ((array) ($ctx['own_below'] ?? []) as $own) {
        $mine = realpath((string) $own);
        if ($mine !== false && $here !== false && ($here === $mine || str_starts_with($here, rtrim($mine, '/') . '/'))) {
            throw new Problem("{$prefix}_own", ['path' => $path]);
        }
    }
    return $fs . $real;
}

/** The disks and pools a share may lie on (its config on the flash; never a look at the disks) */
function importShareBases(string $share, array $ctx): array
{
    $cfg = ($ctx['share_cfg'])($share);
    $use = (string) ($cfg['shareUseCache'] ?? 'no');
    $bases = [];
    if ($use !== 'no') {
        foreach (['shareCachePool', 'shareCachePool2'] as $k) {
            if (($cfg[$k] ?? '') !== '') {
                $bases[] = (string) $cfg[$k];
            }
        }
    }
    if ($use === 'no' || ($use !== 'only' && ($cfg['shareCachePool2'] ?? '') === '')) {     // the array is its primary or secondary
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $bases = array_merge($bases, $include ?: array_filter(array_keys((array) $ctx['asleep']), fn ($d) => preg_match('/^disk\d+$/D', (string) $d)));
    }
    return array_values(array_unique(array_map('strval', $bases)));
}

/** One known file in the old folder: a plain file of its own (no link, no second hard link), at most $cap bytes; null when it isn't there */
function importRead(string $dir, string $name, int $cap, string $prefix): ?string
{
    $h = importOpen($dir, $name, $cap, $prefix);
    if ($h === null) {
        return null;
    }
    $data = (string) stream_get_contents($h, $cap + 1);
    fclose($h);
    if (strlen($data) > $cap) {
        throw new Problem("{$prefix}_big", ['file' => $name]);
    }
    return $data;
}

/**
 * The same checks as importRead(), the file opened for reading (the caller reads it — line by line — and closes it,
 * keeping to $cap itself: the file may grow meanwhile); null when it isn't there.
 *
 * @return resource|null
 */
function importOpen(string $dir, string $name, int $cap, string $prefix)
{
    $file = "$dir/$name";
    clearstatcache(true, $file);
    $st = @lstat($file);
    if (!$st) {
        return null;
    }
    if (($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1) {
        throw new Problem("{$prefix}_file", ['file' => $name]);
    }
    if ($st['size'] > $cap) {
        throw new Problem("{$prefix}_big", ['file' => $name]);
    }
    $h = @fopen($file, 'rb');
    $fst = $h ? fstat($h) : false;
    if (!$h || !$fst || $fst['ino'] !== $st['ino'] || $fst['dev'] !== $st['dev'] || ($fst['mode'] & 0170000) !== 0100000) {
        if ($h) {
            fclose($h);
        }
        throw new Problem("{$prefix}_file", ['file' => $name]);
    }
    return $h;
}

/** A copy of each of these files of the desk's that exists (plain files only), named <file>.before-import-<stamp>, next to it */
function importBackup(string $dir, array $names, string $stamp): array
{
    $made = [];
    foreach (array_keys($names) as $name) {
        $file = "$dir/$name";
        clearstatcache(true, $file);
        $st = @lstat($file);
        if (!$st || ($st['mode'] & 0170000) !== 0100000) {
            continue;
        }
        $copy = "$file.before-import-$stamp";
        writeAtomic($copy, (string) file_get_contents($file), 0600, 0, 0);
        $made[] = $copy;
    }
    return $made;
}
