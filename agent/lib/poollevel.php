<?php
declare(strict_types=1);

/*
 * The pool, only X (2026-10-14, the owner's rule — replaces the halves, borrowing, Y and the offset of 1.62) — shared by
 * Ms. Moverelli and Jack Emby (desks know each other only through libs). One number the user sets: X, her limit
 * (move_when_used_above, % of the whole pool).
 *
 *   size, used = zfs list -Hp used,avail <pool>          (the whole pool, as Smart Mover's pool_used_pct())
 *   M          = Σ refer of the movable shares' datasets (shareUseCache yes, primary = this pool, not skipped or without a
 *                trigger in her ini — poolLevelMovable(); a prefer share moves onto the pool, never off it: truth review T32)
 *   fixed      = used − M                                (never moves: pool-only shares, snapshots, overhead)
 *   jack       = Σ allocated bytes of the entries of EmbyCache's list on this pool (after compression, like refer;
 *                2026-10-15 — the sizes as jack_logical) — ALL of them, wherever they lie (2026-10-15, a user's report:
 *                his media share not movable — «no»/«only» — or its folder on the pool not its own dataset, e.g. made
 *                by his first copy; then his earlier files sat in `fixed`, his bytes were capped at M and the others
 *                showed 0). What lies outside M (jack_out) comes out of fixed: he gives it back himself, whatever
 *                Unraid's mover does with the share.
 *   others     = M − his part inside M                   (the other caching shares: downloads …)
 *   need       = max(0, used − X%·size)                  (what must go tonight)
 *
 * Above X Ms. Moverelli moves the oldest files (across the pool, never one on Jack Emby's list) to the array or the
 * secondary pool until it is back below X; the age rule always. Jack Emby gives back only what was watched and fetches
 * only up to X (EMBYCACHE_MIN_FREE_PERCENT = 100 − X) and his GB budget. Both in ONE night run (nightrun.php). fixed ≥ X:
 * she can never get below it (pool_never_moves); fixed + jack ≥ X: only Jack Emby's unwatched keeps it over X.
 * ZFS pools are measured exactly (refer per dataset). A btrfs or XFS pool (2026-10-15, poolLevelPlain()): size and used from
 * statfs as df counts them (Smart Mover's own measure there), M = the allocated bytes of the movable shares' folders on the
 * pool (du — only in the night run and in a background job at most once an hour; the pages and checks use the last stored
 * value, kept in her data folder), snapshots none counted (they stay in `fixed`) — `estimated` with its age says so on the
 * pages. A sleeping pool is not looked at (null) unless a run wakes it.
 */

const POOL_SNAP_HINT = 2;      // % of the pool in the movable shares' snapshots: the Team Lead's hint
const POOL_LIST_MAX  = 200000; // lines of EmbyCache's list read
const POOL_DU_AGE    = 3600;   // s a btrfs/xfs pool's stored du counts as fresh; older: the background job measures anew
const POOL_DU_EVERY  = 3600;   // s between two background jobs for the same pool
const POOL_DU_TIME   = 60;     // s the background job's du of a pool's movable share folders may take (longer: not stored)
const POOL_DU_NIGHT  = 600;    // s the night run's du may take

/**
 * The numbers (pure). x: % of the whole pool (0: no limit); jack: Jack Emby's bytes inside the movable shares' datasets
 * (more than M: capped), $jackOut: his bytes elsewhere on the pool (taken from what never moves). `need`: what must go
 * to be at X; `never`: what never moves reaches X; `jack_over`: what never moves and Jack Emby's bytes reach X together.
 * The keys after `free` are only for readers from before 2026-10-14 (agent/desks/jack.php until its rewrite): y = x,
 * no half, nothing borrowed.
 */
function poolLevelMath(int $size, int $used, int $movable, int $x, int $jack, int $jackOut = 0): array
{
    $size = max(0, $size);
    $used = max(0, min($used, $size));
    $m = max(0, min($movable, $used));
    $jackIn = max(0, min($jack, $m));
    $jackOut = max(0, min($jackOut + max(0, $jack - $jackIn), $used - $m));
    $nm = $used - $m - $jackOut;
    $jack = $jackIn + $jackOut;
    $xb = $x > 0 ? intdiv($x * $size, 100) : 0;
    $need = $x > 0 ? max(0, $used - $xb) : 0;
    $pct = fn (int $b): float => $size > 0 ? round($b * 100 / $size, 1) : 0.0;
    $never = $x > 0 && $size > 0 && $nm >= $xb;
    return [
        'size' => $size, 'used' => $used, 'movable' => $m, 'nm' => $nm, 'x' => $x, 'xb' => $xb, 'jack' => $jack, 'jack_out' => $jackOut, 'others' => $m - $jackIn,
        'need' => $need, 'used_pct' => $pct($used), 'nm_pct' => $pct($nm), 'never' => $never,
        'jack_over' => !$never && $x > 0 && $size > 0 && $nm + $jack >= $xb, 'free' => $size - $used,
        // older readers (see above)
        'y' => $x, 'k' => 0, 'room' => $x > 0 ? max(0, $xb - $nm) : 0, 'half' => 0, 'half_d' => $x > 0 ? max(0, $xb - $nm) : 0,
        'back' => 0, 'goes' => $need, 'downloads' => $m - $jackIn,
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

/**
 * The shares her ini keeps out of the fill (smart_mover.ini, read as poolLevelRules() reads it): `skip = yes`, or a
 * move_when_used_above of their own of 0 (Smart Mover's fill takes a share only with a trigger above 0) — [name, …]
 */
function poolLevelNoFill(?string $ini): array
{
    if ($ini === null || !is_file($ini)) {
        return [];
    }
    $out = [];
    $sec = null;
    $seen = [];
    foreach (preg_split('/\R/', (string) @file_get_contents($ini, false, null, 0, 1 << 20)) as $line) {
        $line = trim($line);
        if (preg_match('/^\[(.*)\]$/', $line, $m)) {
            $sec = trim($m[1]) === 'GLOBAL' ? null : trim($m[1]);
        } elseif ($sec !== null && preg_match('/^(skip|move_when_used_above)\s*=\s*(.*)$/', $line, $m) && !isset($seen["$sec|$m[1]"])) {
            $seen["$sec|$m[1]"] = true;         // the first value of a key counts (get_ini_val)
            $v = trim($m[2]);
            if (($m[1] === 'skip' && $v === 'yes') || ($m[1] === 'move_when_used_above' && preg_match('/^0+$/D', $v))) {
                $out[$sec] = true;
            }
        }
    }
    return array_map('strval', array_keys($out));
}

/**
 * The movable shares of a pool (pure on the cfgs and her ini): shareUseCache yes with this pool as their primary — the
 * shares her fill can take off the pool above X (truth review T32: a prefer share moves onto the pool, never off it;
 * a share her ini skips or gives no trigger never leaves it by the fill — poolLevelNoFill())
 */
function poolLevelMovable(string $pool, string $sharesDir, array $names, ?string $ini = null): array
{
    $out = [];
    $noFill = array_flip(poolLevelNoFill($ini));
    foreach ($names as $share) {
        $cfg = readCfg("$sharesDir/$share.cfg");
        $primary = (string) ($cfg['shareCachePool'] ?? '');
        if ((string) ($cfg['shareUseCache'] ?? '') === 'yes' && ($primary !== '' ? $primary : 'cache') === $pool && !isset($noFill[(string) $share])) {
            $out[] = (string) $share;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/**
 * The pool from ZFS: {size, used, movable (Σ refer), snapshots (Σ usedbysnapshots)} of the shares' datasets, datasets (the
 * shares of $shares that are a dataset of their own — only their bytes are in movable), snap_all
 * (Σ usedbysnapshots of the pool's own dataset and every one directly below it — her picture's black part; a deeper
 * dataset's own snapshots stay in «appdata & co.»); null: no ZFS pool
 */
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
    $sets = [];
    $all = array_sum(array_column($rows, 'snap'));
    foreach ($shares as $s) {
        if (isset($rows["$pool/$s"])) {
            $sets[] = (string) $s;
            $m += $rows["$pool/$s"]['refer'];
            $snap += $rows["$pool/$s"]['snap'];
        }
    }
    return ['size' => $rows[$pool]['used'] + $rows[$pool]['avail'], 'used' => $rows[$pool]['used'], 'movable' => $m, 'snapshots' => $snap, 'snap_all' => $all,
            'datasets' => $sets];
}

/**
 * A pool that isn't ZFS (btrfs, xfs): {fs, size, used} from statfs of its mount as df counts it (used = blocks − free,
 * size = used + avail — Smart Mover's pool_used_pct() there); null: no btrfs/xfs mounted there (ZFS, or not mounted: the
 * empty folder lies on the root file system). statfs reads the superblock in memory — no disk woken.
 */
function poolLevelStatfs(string $path, string $stat = 'stat'): ?array
{
    [$exit, $out] = run([$stat, '-f', '-c', '%T %S %b %f %a', $path], 10);
    if ($exit !== 0 || !preg_match('/^(btrfs|xfs) (\d{1,12}) (\d{1,20}) (\d{1,20}) (\d{1,20})$/D', trim((string) $out), $m)) {
        return null;
    }
    $used = max(0, (int) $m[3] - (int) $m[4]) * (int) $m[2];
    $size = $used + (int) $m[5] * (int) $m[2];
    return $size > 0 ? ['fs' => $m[1], 'size' => $size, 'used' => $used] : null;
}

/**
 * The movable shares' folders on a btrfs/xfs pool measured now: {share: allocated bytes} (du -s -B1 -x, nice; a folder
 * not there: 0), stored in $store (her data folder: it outlives a reboot) with the time — null: a du failed or took
 * longer than $time s (nothing stored; never a wrong M). Only the night run (POOL_DU_NIGHT) and the background job
 * (poolLevelDuJob(), POOL_DU_TIME) call it — never a page or a check.
 */
function poolLevelFolderBytes(string $root, array $shares, string $store, int $time, string $du = 'du'): ?array
{
    $out = $todo = [];
    foreach ($shares as $s) {
        $path = $root . $s;
        if (!is_dir($path) || is_link($path)) {
            $out[$s] = 0;
        } else {
            $todo[$s] = ['nice', '-n', '10', $du, '-s', '-B1', '-x', $path];
        }
    }
    foreach ($todo ? runAll($todo, $time) : [] as $s => [$exit, $text]) {
        if ($exit !== 0 || !preg_match('/^(\d+)\s/', (string) $text, $m)) {
            return null;
        }
        $out[$s] = (int) $m[1];
    }
    ksort($out, SORT_STRING);
    $saved = poolLevelDuRead($store);
    // only the folders measured (one made later is not in it: measured again before it counts)
    $saved['pools'][basename($root)] = ['at' => time(), 'dirs' => array_intersect_key($out, $todo) ?: new stdClass()];
    poolLevelDuWrite($store, $saved);
    return $out;
}

/** The stored du ({pools: {pool: {at, dirs: {share: bytes}}}, asked: {pool: time}}), read tolerantly */
function poolLevelDuRead(string $store): array
{
    $d = readJson($store) ?? [];
    $d['pools'] = is_array($d['pools'] ?? null) ? $d['pools'] : [];
    $d['asked'] = is_array($d['asked'] ?? null) ? $d['asked'] : [];
    return $d;
}

function poolLevelDuWrite(string $store, array $d): void
{
    $d['asked'] = $d['asked'] ?: new stdClass();
    try {
        @mkdir(dirname($store), 0700, true);
        writeAtomic($store, jsonEncode($d));
    } catch (Throwable) {
        // not stored: measured again next time
    }
}

/**
 * The last stored du of a pool's movable shares: {bytes: {share: bytes}, at} — null: none yet, or one of $shares (with a
 * folder on the pool) not in it (a share made movable since). Never in the future.
 */
function poolLevelDuStored(string $store, string $pool, string $root, array $shares): ?array
{
    $p = poolLevelDuRead($store)['pools'][$pool] ?? null;
    if (!is_array($p) || !is_int($p['at'] ?? null) || $p['at'] > time() || !is_array($p['dirs'] ?? null)) {
        return null;
    }
    $out = [];
    foreach ($shares as $s) {
        $b = $p['dirs'][$s] ?? null;
        if (is_int($b) && $b >= 0) {
            $out[$s] = $b;
        } elseif (!is_dir($root . $s) || is_link($root . $s)) {
            $out[$s] = 0;
        } else {
            return null;
        }
    }
    return ['bytes' => $out, 'at' => $p['at']];
}

/**
 * The background job asked for (a page or a check found the stored du missing or older than POOL_DU_AGE): at most once
 * per POOL_DU_EVERY s per pool — noted in the store before it starts. $launch(pool); true: started now.
 */
function poolLevelDuAsk(string $store, string $pool, callable $launch): bool
{
    $d = poolLevelDuRead($store);
    $last = $d['asked'][$pool] ?? null;
    if (is_int($last) && $last > time() - POOL_DU_EVERY && $last <= time()) {
        return false;
    }
    $d['asked'][$pool] = time();
    poolLevelDuWrite($store, $d);
    try {
        $launch($pool);
    } catch (Throwable) {
        return false;
    }
    return true;
}

/** Where the du is stored: her data folder (outlives a reboot) — the tests: $o['du_cache'] */
function poolLevelDuStore(array $o): string
{
    return (string) ($o['du_cache'] ?? (defined('DATA_DIR') ? DATA_DIR : sys_get_temp_dir()) . '/moverelli/pool-du.json');
}

/**
 * poolLevelLook() for a btrfs/xfs pool: the same shape (datasets = the movable shares with a folder on the pool — their
 * bytes are M), snapshots 0 and snap_all null (none counted: they stay in what never moves), `estimated` and `measured_at`
 * (the du's time) — null: not such a pool, or no du stored yet (`wait`: the background job asked for). $o: mnt, stat, du,
 * du_cache, measure ('night': du now, else the stored value — older than POOL_DU_AGE or none: the background job via
 * du_launch(pool)).
 */
function poolLevelPlain(string $pool, array $shares, array $o): ?array
{
    if (!preg_match('/^[\w.\-]{1,64}$/D', $pool)) {
        return null;
    }
    $root = rtrim($o['mnt'] ?? '/mnt', '/') . "/$pool/";
    $fs = poolLevelStatfs(rtrim($root, '/'), $o['stat'] ?? 'stat');
    if ($fs === null) {
        return null;
    }
    $store = poolLevelDuStore($o);
    if (($o['measure'] ?? null) === 'night') {
        $bytes = poolLevelFolderBytes($root, $shares, $store, POOL_DU_NIGHT, $o['du'] ?? 'du');
        $at = time();
    } else {
        $stored = poolLevelDuStored($store, $pool, $root, $shares);
        if ($stored === null || $stored['at'] < time() - POOL_DU_AGE) {
            poolLevelDuAsk($store, $pool, $o['du_launch'] ?? fn (string $p) => hostLaunch('pool-du', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'pool-du', $p]));
        }
        [$bytes, $at] = $stored === null ? [null, null] : [$stored['bytes'], $stored['at']];
    }
    if ($bytes === null) {
        return null;
    }
    return ['size' => $fs['size'], 'used' => $fs['used'], 'movable' => array_sum($bytes), 'snapshots' => 0, 'snap_all' => null,
            'datasets' => array_values(array_filter($shares, fn ($s) => is_dir($root . $s) && !is_link($root . $s))), 'fs' => $fs['fs'], 'estimated' => true,
            'measured_at' => $at];
}

/**
 * A btrfs/xfs pool waiting for its first du (her setup: «not measured yet» — the background job is on its way); false for
 * ZFS, a pool measured, or not mounted
 */
function poolLevelWaiting(string $pool, array $o): bool
{
    if (!preg_match('/^[\w.\-]{1,64}$/D', $pool)) {
        return false;
    }
    $root = rtrim($o['mnt'] ?? '/mnt', '/') . "/$pool/";
    return poolLevelStatfs(rtrim($root, '/'), $o['stat'] ?? 'stat') !== null
        && poolLevelDuStored(poolLevelDuStore($o), $pool, $root, poolLevelMovable($pool, $o['shares_dir'], $o['names'], $o['ini'] ?? null)) === null;
}

/**
 * The background job (php agent.php job pool-du <pool>, via atd): the pool's movable shares' folders measured (at most
 * POOL_DU_TIME s) and stored — never while the pool sleeps. 0: stored.
 */
function poolLevelDuJob(string $pool, array $o): int
{
    if (!preg_match('/^[\w.\-]{1,64}$/D', $pool) || poolsBySleep([$pool], $o['asleep'] ?? null)['asleep']) {
        return 1;
    }
    $root = rtrim($o['mnt'] ?? '/mnt', '/') . "/$pool/";
    if (poolLevelStatfs(rtrim($root, '/'), $o['stat'] ?? 'stat') === null) {
        return 1;
    }
    $bytes = poolLevelFolderBytes($root, poolLevelMovable($pool, $o['shares_dir'], $o['names'], $o['ini'] ?? null), poolLevelDuStore($o), POOL_DU_TIME, $o['du'] ?? 'du');
    return $bytes === null ? 1 : 0;
}

/**
 * J: what the entries of EmbyCache's list that lie on this pool take there (a file that is gone: 0) — in the pool's own
 * bytes (2026-10-15: allocated, st_blocks — ZFS counts them after compression, like `refer`; never above the size), and
 * their sizes (logical): [physical, logical]. The ratio converts what waits on the array into pool bytes (nightPlan()).
 * $inside (the shares whose dataset is counted as movable): a third value, the physical bytes of the entries in them.
 */
function poolLevelJackBytes(?string $list, string $pool, string $mnt = '/mnt', ?array $inside = null): array
{
    $none = $inside === null ? [0, 0] : [0, 0, 0];
    if ($list === null || !is_file($list)) {
        return $none;
    }
    $fh = @fopen($list, 'r');
    if (!$fh) {
        return $none;
    }
    $pre = rtrim($mnt, '/') . "/$pool/";
    $in = array_fill_keys(array_map('strval', $inside ?? []), true);
    $sum = $logical = $sumIn = 0;
    for ($i = 0; $i < POOL_LIST_MAX && ($line = fgets($fh)) !== false; $i++) {
        $p = rtrim($line, "\r\n");
        if (str_starts_with($p, $pre) && !str_contains($p, '/../')) {
            clearstatcache(true, $p);
            $st = @lstat($p);
            if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
                $bytes = poolLevelFileBytes((int) $st['size'], (int) $st['blocks']);
                $logical += (int) $st['size'];
                $sum += $bytes;
                if (isset($in[strstr(substr($p, strlen($pre)), '/', true) ?: ''])) {
                    $sumIn += $bytes;
                }
            }
        }
    }
    fclose($fh);
    return $inside === null ? [$sum, $logical] : [$sum, $logical, $sumIn];
}

/** A file's bytes on its pool: its allocated blocks (512 each; ZFS after compression), never more than its size */
function poolLevelFileBytes(int $size, int $blocks): int
{
    return $blocks >= 0 ? min(max(0, $size), $blocks * 512) : max(0, $size);
}

/**
 * One pool as it stands: poolLevelMath() with its movable shares and snapshots — or null (neither ZFS nor btrfs/xfs, not
 * measured, the pool asleep and not to be woken). $o: x, shares_dir, names (the shares that exist), zfs, mnt, jack_list
 * (EmbyCache's list, null: none), ini (her smart_mover.ini: the shares it keeps out of the fill), asleep (sleepingDisks()'
 * map, null: asked now), wake (a run is about to wake it anyway),
 * measure ('night': a btrfs/xfs pool's folders measured now), stat, du, du_cache, du_launch (poolLevelPlain()).
 * `estimated` and `measured_at`: not ZFS (only there).
 */
function poolLevel(string $pool, array $o): ?array
{
    if (!($o['wake'] ?? false) && poolsBySleep([$pool], $o['asleep'] ?? null)['asleep']) {
        return null;
    }
    $shares = poolLevelMovable($pool, $o['shares_dir'], $o['names'], $o['ini'] ?? null);
    $look = poolLevelLook($pool, $shares, $o['zfs'] ?? 'zfs') ?? poolLevelPlain($pool, $shares, $o);
    if ($look === null) {
        return null;
    }
    // his bytes inside the movable datasets (in M) and the rest of them (outside: in what never moves until taken out)
    [$jack, $jackLogical, $jackIn] = poolLevelJackBytes($o['jack_list'] ?? null, $pool, $o['mnt'] ?? '/mnt', $look['datasets']);
    // a movable share whose folder on the pool is no dataset of its own (a plain folder in the pool's dataset): its bytes
    // count as never moving — her page says so (inbox #14)
    $root = rtrim($o['mnt'] ?? '/mnt', '/') . "/$pool/";
    $folders = array_values(array_filter(array_diff($shares, $look['datasets']), fn ($s) => is_dir($root . $s) && !is_link($root . $s)));
    return (isset($look['estimated']) ? ['estimated' => true, 'fs' => $look['fs'], 'measured_at' => $look['measured_at']] : []) + ['pool' => $pool, 'shares' => $shares, 'folders' => $folders, 'snapshots' => $look['snapshots'], 'snap_all' => $look['snap_all'], 'jack_logical' => $jackLogical,
            'snap_pct' => $look['size'] > 0 ? round($look['snapshots'] * 100 / $look['size'], 1) : 0.0]
         + poolLevelMath($look['size'], $look['used'], $look['movable'], (int) $o['x'], $jackIn, $jack - $jackIn);
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
        $env = ['MEDIACACHE_MIN_FREE_PERCENT' => (string) (100 - $rules['x']), 'MEDIACACHE_WATCHED_ONLY' => '1'];
        $mnt = rtrim($o['mnt'] ?? '/mnt', '/');
        $pool = preg_match('#^' . preg_quote($mnt, '#') . '/([\w.\-]{1,64})/?$#D', (string) ($o['cache_path'] ?? ''), $m) ? $m[1] : null;
        if ($pool !== null && $pool !== 'user' && $pool !== 'user0') {
            $level = poolLevel($pool, ['x' => $rules['x']] + $o);
        }
    }
    return ['rules' => $rules, 'level' => $level, 'env' => $env];
}
