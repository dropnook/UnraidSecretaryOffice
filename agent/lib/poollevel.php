<?php
declare(strict_types=1);

/*
 * The pool, simply (2026-10-13, the owner's rule — replaces the cake) — shared by Ms. Moverelli and Jack Emby (desks know
 * each other only through libs). One number the user sets: X, her limit (move_when_used_above). Y = X − offset (the
 * offset, default 10, under «Advanced»; written into her ini as move_until_used_below, never asked as a number of its own).
 *
 *   size, used = zfs list -Hp used,avail <pool>          (the whole pool, as Smart Mover's pool_used_pct())
 *   M          = Σ refer of the movable shares' datasets (shareUseCache yes|prefer, primary = this pool)
 *   NM         = used − M                                (never moves: pool-only shares, snapshots, overhead)
 *   R          = max(0, Y%·size − NM)                    (the room below Y the movable shares share)
 *   H          = R · k / 100                             (k = jack_min_percent, default 50: half each — Jack's floor)
 *   J          = Σ size of the entries of EmbyCache's list on this pool
 *
 * Jack Emby fills up to Y (EMBYCACHE_MIN_FREE_PERCENT = 100 − Y — borrowing what the downloads don't need) and
 * gives back above Y, the farthest ahead first, but never below H (EMBYCACHE_KEEP_BYTES = H). Ms. Moverelli: the age rule
 * always, at/above X the oldest downloads down to their half. Both in ONE night run (nightrun.php): check, reckon, free
 * (who must free more first), recheck, fill (Jack Emby last). NM ≥ Y: nothing to share (pool_never_moves).
 * Only ZFS pools are measured here (refer per dataset); a sleeping pool is not looked at (null) unless a run wakes it.
 */

const POOL_SNAP_HINT      = 2;      // % of the pool in the movable shares' snapshots: the Team Lead's hint
const POOL_LIST_MAX       = 200000; // lines of EmbyCache's list read
const POOL_OFFSET_DEFAULT = 10;     // X − Y when her ini has no Y yet
const POOL_JACK_DEFAULT   = 50;     // jack_min_percent: Jack's guaranteed share of R

/**
 * The numbers (pure). x, y: % of the whole pool (0: no limit); k: Jack's guaranteed % of R; jack: J. `back`: what Jack
 * gives back the next night (over Y, never below H); `goes`: what of the downloads goes then because the pool is
 * at/above X (only what they borrowed over their half, at most what is still needed to reach Y); `need`: used − Y; `never`: what never moves reaches Y — nothing to share.
 */
function poolLevelMath(int $size, int $used, int $movable, int $x, int $y, int $k, int $jack): array
{
    $size = max(0, $size);
    $used = max(0, min($used, $size));
    $m = max(0, min($movable, $used));
    $nm = $used - $m;
    $jack = max(0, min($jack, $m));
    $yb = $y > 0 ? intdiv($y * $size, 100) : 0;
    $room = $y > 0 ? max(0, $yb - $nm) : 0;
    $half = intdiv($room * max(0, min(100, $k)), 100);
    // the night run (agent/lib/nightrun.php): what must go so the pool stands at Y; Jack Emby gives back only what he
    // borrowed (over Y, never below H); the downloads give only what they borrowed over their half, and only from X on —
    // the air Y…X belongs to the day's downloads
    $need = $y > 0 ? max(0, $used - $yb) : 0;
    $back = $y > 0 && $used > $yb ? min(max(0, $jack - $half), $need) : 0;
    $goes = $x > 0 && $y > 0 && $used * 100 >= $x * $size ? min(max(0, $m - $jack - ($room - $half)), $need - $back) : 0;
    $pct = fn (int $b): float => $size > 0 ? round($b * 100 / $size, 1) : 0.0;
    return [
        'size' => $size, 'used' => $used, 'movable' => $m, 'nm' => $nm, 'x' => $x, 'y' => $y, 'k' => $k,
        'room' => $room, 'half' => $half, 'half_d' => $room - $half, 'jack' => $jack, 'downloads' => $m - $jack, 'need' => $need, 'back' => $back, 'goes' => $goes,
        'used_pct' => $pct($used), 'nm_pct' => $pct($nm), 'never' => $y > 0 && $size > 0 && $nm >= $yb,
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

/** Her rules from smart_mover.ini [GLOBAL]: {x, y, k}; Y missing under a limit: X − 10; null without an ini */
function poolLevelRules(string $ini): ?array
{
    if (!is_file($ini)) {
        return null;
    }
    // her ini's [GLOBAL] read line by line (never parse_ini_file: a path in it may hold what PHP's ini reads as syntax)
    $g = [];
    $in = false;
    foreach (preg_split('/\R/', (string) @file_get_contents($ini, false, null, 0, 1 << 20)) as $line) {
        $line = trim($line);
        if (preg_match('/^\[(.*)\]$/', $line, $m)) {
            $in = trim($m[1]) === 'GLOBAL';
        } elseif ($in && preg_match('/^(\w+)\s*=\s*(.*)$/', $line, $m)) {
            $g[$m[1]] ??= $m[2];
        }
    }
    $int = fn (string $k, int $max): ?int => isset($g[$k]) && preg_match('/^\d{1,3}$/D', trim((string) $g[$k])) && (int) $g[$k] <= $max ? (int) $g[$k] : null;
    $x = $int('move_when_used_above', 100) ?? 0;
    $y = $int('move_until_used_below', 99) ?? 0;
    if ($x > 0 && ($y <= 0 || $y >= $x)) {
        $y = max(1, $x - POOL_OFFSET_DEFAULT);
    }
    return ['x' => $x, 'y' => $x > 0 ? $y : 0, 'k' => $int('jack_min_percent', 100) ?? POOL_JACK_DEFAULT];
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
 * and not to be woken). $o: x, y, k, shares_dir, names (the shares that exist), zfs, mnt, jack_list (EmbyCache's list,
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
         + poolLevelMath($look['size'], $look['used'], $look['movable'], (int) $o['x'], (int) $o['y'], (int) ($o['k'] ?? POOL_JACK_DEFAULT), $jack);
}

/**
 * Jack Emby's view (his launch and his page): her rules from her ini, his pool (cache_path /mnt/<pool>) and what
 * EmbyCache is told — {rules, level (null: not measured), env}. env while she has a limit: EMBYCACHE_MIN_FREE_PERCENT =
 * 100 − Y (he fills up to Y and gives back above it) and, once his pool is measured, EMBYCACHE_KEEP_BYTES = H (never
 * below his half). null: she isn't set up (no ini). $o: ini, cache_path, jack_list, shares_dir, names, zfs, mnt, asleep, wake.
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
        $env['EMBYCACHE_MIN_FREE_PERCENT'] = (string) (100 - $rules['y']);
        $mnt = rtrim($o['mnt'] ?? '/mnt', '/');
        $pool = preg_match('#^' . preg_quote($mnt, '#') . '/([\w.\-]{1,64})/?$#D', (string) ($o['cache_path'] ?? ''), $m) ? $m[1] : null;
        if ($pool !== null && $pool !== 'user' && $pool !== 'user0') {
            $level = poolLevel($pool, $rules + $o);
        }
        if ($level !== null) {
            $env['EMBYCACHE_KEEP_BYTES'] = (string) $level['half'];
        }
    }
    return ['rules' => $rules, 'level' => $level, 'env' => $env];
}
