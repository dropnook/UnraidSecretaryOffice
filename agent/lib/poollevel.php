<?php
declare(strict_types=1);

/*
 * The pool, only X (2026-10-14, the owner's rule — replaces the halves, borrowing, Y and the offset of 1.62) — shared by
 * Ms. Moverelli and Jack Emby (desks know each other only through libs). One number the user sets: X, her limit
 * (move_when_used_above, % of the whole pool).
 *
 *   size, used = zfs list -Hp used,avail <pool>          (the whole pool, as Smart Mover's pool_used_pct())
 *   M          = Σ refer of the movable shares' datasets (shareUseCache yes|prefer, primary = this pool)
 *   fixed      = used − M                                (never moves: pool-only shares, snapshots, overhead)
 *   jack       = Σ size of the entries of EmbyCache's list on this pool
 *   others     = M − jack                                (the other caching shares: downloads …)
 *   need       = max(0, used − X%·size)                  (what must go tonight)
 *
 * Above X Ms. Moverelli moves the oldest files (across the pool, never one on Jack Emby's list) to the array or the
 * secondary pool until it is back below X; the age rule always. Jack Emby gives back only what was watched and fetches
 * only up to X (EMBYCACHE_MIN_FREE_PERCENT = 100 − X) and his GB budget. Both in ONE night run (nightrun.php). fixed ≥ X:
 * she can never get below it (pool_never_moves); fixed + jack ≥ X: only Jack Emby's unwatched keeps it over X.
 * Only ZFS pools are measured here (refer per dataset); a sleeping pool is not looked at (null) unless a run wakes it.
 */

const POOL_SNAP_HINT = 2;      // % of the pool in the movable shares' snapshots: the Team Lead's hint
const POOL_LIST_MAX  = 200000; // lines of EmbyCache's list read

/**
 * The numbers (pure). x: % of the whole pool (0: no limit); jack: Jack Emby's bytes on the pool. `need`: what must go
 * to be at X; `never`: what never moves reaches X; `jack_over`: what never moves and Jack Emby's bytes reach X together.
 * The keys after `free` are only for readers from before 2026-10-14 (agent/desks/jack.php until its rewrite): y = x,
 * no half, nothing borrowed.
 */
function poolLevelMath(int $size, int $used, int $movable, int $x, int $jack): array
{
    $size = max(0, $size);
    $used = max(0, min($used, $size));
    $m = max(0, min($movable, $used));
    $nm = $used - $m;
    $jack = max(0, min($jack, $m));
    $xb = $x > 0 ? intdiv($x * $size, 100) : 0;
    $need = $x > 0 ? max(0, $used - $xb) : 0;
    $pct = fn (int $b): float => $size > 0 ? round($b * 100 / $size, 1) : 0.0;
    $never = $x > 0 && $size > 0 && $nm >= $xb;
    return [
        'size' => $size, 'used' => $used, 'movable' => $m, 'nm' => $nm, 'x' => $x, 'xb' => $xb, 'jack' => $jack, 'others' => $m - $jack,
        'need' => $need, 'used_pct' => $pct($used), 'nm_pct' => $pct($nm), 'never' => $never,
        'jack_over' => !$never && $x > 0 && $size > 0 && $nm + $jack >= $xb, 'free' => $size - $used,
        // older readers (see above)
        'y' => $x, 'k' => 0, 'room' => $x > 0 ? max(0, $xb - $nm) : 0, 'half' => 0, 'half_d' => $x > 0 ? max(0, $xb - $nm) : 0,
        'back' => 0, 'goes' => $need, 'downloads' => $m - $jack,
    ];
}

/** Bytes as the texts say them (1024 steps, like the page's fmt.size) */
function poolLevelSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $v = (float) max(0, $bytes);
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, $i === 0 ? 0 : ($v < 10 ? 1 : 0), '.', '') . ' ' . $units[$i];
}

/** Her rule from smart_mover.ini [GLOBAL]: {x} (+ y = x, k = 0 for older readers); null without an ini */
function poolLevelRules(string $ini): ?array
{
    if (!is_file($ini)) {
        return null;
    }
    // her ini's [GLOBAL] read line by line (never parse_ini_file: a path in it may hold what PHP's ini reads as syntax)
    $x = null;
    $in = false;
    foreach (preg_split('/\R/', (string) @file_get_contents($ini, false, null, 0, 1 << 20)) as $line) {
        $line = trim($line);
        if (preg_match('/^\[(.*)\]$/', $line, $m)) {
            $in = trim($m[1]) === 'GLOBAL';
        } elseif ($in && $x === null && preg_match('/^move_when_used_above\s*=\s*(.*)$/', $line, $m)) {
            $x = preg_match('/^\d{1,3}$/D', trim($m[1])) && (int) $m[1] <= 100 ? (int) $m[1] : 0;
        }
    }
    $x ??= 0;
    return ['x' => $x, 'y' => $x, 'k' => 0];
}

/** The movable shares of a pool (pure on the cfgs): shareUseCache yes|prefer with this pool as their primary */
function poolLevelMovable(string $pool, string $sharesDir, array $names): array
{
    $out = [];
    foreach ($names as $share) {
        $cfg = readCfg("$sharesDir/$share.cfg");
        $primary = (string) ($cfg['shareCachePool'] ?? '');
        if (in_array((string) ($cfg['shareUseCache'] ?? ''), ['yes', 'prefer'], true) && ($primary !== '' ? $primary : 'cache') === $pool) {
            $out[] = (string) $share;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/** The pool from ZFS: {size, used, movable (Σ refer), snapshots (Σ usedbysnapshots)} of the shares' datasets; null: no ZFS pool */
function poolLevelLook(string $pool, array $shares, string $zfs = 'zfs'): ?array
{
    if (!preg_match('/^[\w.\-]{1,64}$/D', $pool)) {
        return null;
    }
    [$exit, $out] = run([$zfs, 'list', '-Hp', '-r', '-d', '1', '-o', 'name,used,avail,refer,usedbysnapshots', $pool], 20);
    if ($exit !== 0) {
        return null;
    }
    $rows = [];
    foreach (explode("\n", trim((string) $out)) as $line) {
        $f = explode("\t", $line);
        if (count($f) === 5 && ctype_digit($f[1]) && ctype_digit($f[2])) {
            $rows[$f[0]] = ['used' => (int) $f[1], 'avail' => (int) $f[2], 'refer' => ctype_digit($f[3]) ? (int) $f[3] : 0, 'snap' => ctype_digit($f[4]) ? (int) $f[4] : 0];
        }
    }
    if (!isset($rows[$pool]) || $rows[$pool]['used'] + $rows[$pool]['avail'] <= 0) {
        return null;
    }
    $m = $snap = 0;
    foreach ($shares as $s) {
        $m += $rows["$pool/$s"]['refer'] ?? 0;
        $snap += $rows["$pool/$s"]['snap'] ?? 0;
    }
    return ['size' => $rows[$pool]['used'] + $rows[$pool]['avail'], 'used' => $rows[$pool]['used'], 'movable' => $m, 'snapshots' => $snap];
}

/** J: the sizes of the entries of EmbyCache's list that lie on this pool (a file that is gone: 0) */
function poolLevelJackBytes(?string $list, string $pool, string $mnt = '/mnt'): int
{
    if ($list === null || !is_file($list)) {
        return 0;
    }
    $fh = @fopen($list, 'r');
    if (!$fh) {
        return 0;
    }
    $pre = rtrim($mnt, '/') . "/$pool/";
    $sum = 0;
    for ($i = 0; $i < POOL_LIST_MAX && ($line = fgets($fh)) !== false; $i++) {
        $p = rtrim($line, "\r\n");
        if (str_starts_with($p, $pre) && !str_contains($p, '/../')) {
            clearstatcache(true, $p);
            $st = @lstat($p);
            if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
                $sum += (int) $st['size'];
            }
        }
    }
    fclose($fh);
    return $sum;
}

/**
 * One pool as it stands: poolLevelMath() with its movable shares and snapshots — or null (no ZFS pool, the pool asleep
 * and not to be woken). $o: x, shares_dir, names (the shares that exist), zfs, mnt, jack_list (EmbyCache's list,
 * null: none), asleep (sleepingDisks()' map, null: asked now), wake (a run is about to wake it anyway).
 */
function poolLevel(string $pool, array $o): ?array
{
    if (!($o['wake'] ?? false) && poolsBySleep([$pool], $o['asleep'] ?? null)['asleep']) {
        return null;
    }
    $shares = poolLevelMovable($pool, $o['shares_dir'], $o['names']);
    $look = poolLevelLook($pool, $shares, $o['zfs'] ?? 'zfs');
    if ($look === null) {
        return null;
    }
    $jack = poolLevelJackBytes($o['jack_list'] ?? null, $pool, $o['mnt'] ?? '/mnt');
    return ['pool' => $pool, 'shares' => $shares, 'snapshots' => $look['snapshots'],
            'snap_pct' => $look['size'] > 0 ? round($look['snapshots'] * 100 / $look['size'], 1) : 0.0]
         + poolLevelMath($look['size'], $look['used'], $look['movable'], (int) $o['x'], $jack);
}

/**
 * Jack Emby's view (his launch and his page): her rule from her ini, his pool (cache_path /mnt/<pool>) and what
 * EmbyCache is told — {rules, level (null: not measured), env}. env while she has a limit: EMBYCACHE_MIN_FREE_PERCENT =
 * 100 − X (he fetches only up to X) and EMBYCACHE_WATCHED_ONLY = 1 (he gives back only what was watched — never by
 * rank, whatever the pool: no ping-pong with Ms. Moverelli). null: she isn't set up (no ini). $o: ini, cache_path,
 * jack_list, shares_dir, names, zfs, mnt, asleep, wake.
 */
function poolLevelJack(array $o): ?array
{
    $rules = poolLevelRules($o['ini']);
    if ($rules === null) {
        return null;
    }
    $env = [];
    $level = null;
    if ($rules['x'] > 0) {
        $env = ['EMBYCACHE_MIN_FREE_PERCENT' => (string) (100 - $rules['x']), 'EMBYCACHE_WATCHED_ONLY' => '1'];
        $mnt = rtrim($o['mnt'] ?? '/mnt', '/');
        $pool = preg_match('#^' . preg_quote($mnt, '#') . '/([\w.\-]{1,64})/?$#D', (string) ($o['cache_path'] ?? ''), $m) ? $m[1] : null;
        if ($pool !== null && $pool !== 'user' && $pool !== 'user0') {
            $level = poolLevel($pool, ['x' => $rules['x']] + $o);
        }
    }
    return ['rules' => $rules, 'level' => $level, 'env' => $env];
}
