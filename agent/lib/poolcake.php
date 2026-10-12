<?php
declare(strict_types=1);

/*
 * The pool as a cake (2026-10-12, the owner's rule) — shared by Ms. Moverelli and Jack Emby (desks know each other only
 * through libs). X and Y are % of the WHOLE pool (the same number as Unraid's Main); n is Jack's share of the cake:
 *
 *   size, used = zfs list -Hp used,avail <pool>          (the whole pool, as Smart Mover's pool_used_pct())
 *   M          = Σ refer of the movable shares' datasets (shareUseCache yes|prefer, primary = this pool)
 *   NM         = used − M                                (never moves: pool-only shares, snapshots, overhead)
 *   C          = max(0, X%·size − NM)                    (the cake: the movable shares' room under X)
 *   j          = n%·C                                    (Jack's slice; the rest of C is room for new things)
 *   J          = Σ size of the entries of EmbyCache's list on this pool
 *   eff        = used − J + j                            (her reading: Jack's slice counted whole, filled or not)
 *
 * She fills when eff ≥ X down to eff < Y (Smart Mover's hook CUSTOMMOVER_POOL_OFFSET_BYTES = j − J); Jack fills while
 * J + size ≤ j (EMBYCACHE_BUDGET_BYTES = j) and gives back what is over j once J > j + 5 % (hysteresis). Bounds per run:
 * used ≥ 95 % → everything movable goes and Jack gives back all; NM ≥ X → no fill (moving helps nothing); NM + j ≥ Y →
 * the fill only down to below X. Her setup refuses targets she can never reach (Y ≤ NM + 5 %, NM ≥ X, NM + j ≥ Y).
 * Only ZFS pools have a cake (refer per dataset); a sleeping pool is not looked at (null) unless a run is about to wake it.
 */

const POOLCAKE_FULL      = 95;    // % used: the emergency — everything movable goes, Jack gives back all
const POOLCAKE_GAP       = 5;     // her target Y must lie this many % above what never moves (setup)
const POOLCAKE_HYST      = 5;     // Jack gives back only once J is this many % over his slice
const POOLCAKE_SNAP_HINT = 2;     // % of the pool in the movable shares' snapshots: the Team Lead's hint
const POOLCAKE_LIST_MAX  = 200000; // lines of EmbyCache's list read

/**
 * The cake's numbers (pure). $n null: Jack's slice not set (not hired, another pool, or her ini from before the cake):
 * no slice, no offset — she reads the pool as it is. Bytes are ints; `case`: full | none | target | ok (the run's
 * bound, in this order); `fill`: what Smart Mover is told (all | off | target X | normal); `budget`: Jack's
 * EMBYCACHE_BUDGET_BYTES (null: none).
 */
function poolCakeMath(int $size, int $used, int $movable, int $x, int $y, ?int $n, int $jack): array
{
    $size = max(0, $size);
    $used = max(0, min($used, $size));
    $m = max(0, min($movable, $used));
    $nm = $used - $m;
    $c = $x > 0 ? max(0, intdiv($x * $size, 100) - $nm) : 0;
    $j = $n !== null ? intdiv(max(0, min(100, $n)) * $c, 100) : 0;
    $jack = max(0, $jack);
    $eff = $n !== null ? max(0, $used - $jack + $j) : $used;
    $floor = $nm + $j;
    $pct = fn (int $b): float => $size > 0 ? round($b * 100 / $size, 1) : 0.0;
    $case = match (true) {
        $size > 0 && $used * 100 >= POOLCAKE_FULL * $size  => 'full',
        $x > 0 && $nm * 100 >= $x * $size                  => 'none',
        $x > 0 && $y > 0 && $floor * 100 >= $y * $size     => 'target',
        default                                            => 'ok',
    };
    return [
        'size' => $size, 'used' => $used, 'movable' => $m, 'nm' => $nm, 'cake' => $c, 'x' => $x, 'y' => $y, 'n' => $n,
        'slice' => $j, 'jack' => $jack, 'eff' => $eff, 'floor' => $floor, 'rest' => max(0, $c - $j), 'new' => max(0, $m - $jack),
        'used_pct' => $pct($used), 'nm_pct' => $pct($nm), 'eff_pct' => $pct($eff), 'floor_pct' => $pct($floor),
        'case' => $case,
        // what she tells Smart Mover for this pool: the offset (j − J) on its reading, and the fill's bound
        'offset' => $n !== null ? $j - $jack : 0,
        'fill' => match ($case) { 'full' => 'all', 'none' => 'off', 'target' => 'target', default => 'normal' },
        // what Jack is told: his slice (0 in the emergency: he gives back all); over it by more than 5 %: he gives back
        'budget' => $n === null ? null : ($case === 'full' ? 0 : $j),
        'excess' => $n !== null && $jack * 100 > $j * (100 + POOLCAKE_HYST) ? $jack - $j : 0,
    ];
}

/** Jack's slice proposed from what he holds today: ceil(J / C · 100), up to the next 5, at least 10; null without a cake */
function poolCakeProposal(int $jack, int $cake): ?int
{
    if ($cake <= 0) {
        return null;
    }
    $p = (int) ceil($jack * 100 / $cake);
    return min(100, max(10, (int) (ceil($p / 5) * 5)));
}

/**
 * Her setup's check for one pool's cake (computed with the NEW X, Y, n): null when the target can be reached, else
 * [problem key, params] — what never moves above X (moverelli_cake_none), Y not above it by 5 % (moverelli_cake_target,
 * `min` the lowest Y that works), or Jack's slice on top of it reaching Y (moverelli_cake_slice).
 */
function poolCakeSetupProblem(array $cake, string $pool): ?array
{
    if ($cake['x'] <= 0 || $cake['size'] <= 0) {
        return null;
    }
    $nm = (int) floor($cake['nm_pct']);
    if ($cake['nm'] * 100 >= $cake['x'] * $cake['size']) {
        return ['moverelli_cake_none', ['pool' => $pool, 'nm' => $nm, 'x' => $cake['x']]];
    }
    if ($cake['y'] > 0 && $cake['y'] <= $cake['nm_pct'] + POOLCAKE_GAP) {
        return ['moverelli_cake_target', ['pool' => $pool, 'nm' => $nm, 'y' => $cake['y'], 'min' => (int) floor($cake['nm_pct'] + POOLCAKE_GAP) + 1]];
    }
    if ($cake['y'] > 0 && $cake['floor'] * 100 >= $cake['y'] * $cake['size']) {
        return ['moverelli_cake_slice', ['pool' => $pool, 'nm' => $nm, 'y' => $cake['y'], 'j' => poolCakeSize($cake['slice'])]];
    }
    return null;
}

/** Bytes as the texts say them (1024 steps, like the page's fmt.size) */
function poolCakeSize(int $bytes): string
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

/** Her rules from smart_mover.ini [GLOBAL]: {x, y, n} (n null: no jack_slice_percent yet); null without an ini */
function poolCakeRules(string $ini): ?array
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
    return ['x' => $int('move_when_used_above', 100) ?? 0, 'y' => $int('move_until_used_below', 99) ?? 0, 'n' => $int('jack_slice_percent', 100)];
}

/** The movable shares of a pool (pure on the cfgs): shareUseCache yes|prefer with this pool as their primary */
function poolCakeMovable(string $pool, string $sharesDir, array $names): array
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
function poolCakeLook(string $pool, array $shares, string $zfs = 'zfs'): ?array
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
function poolCakeJackBytes(?string $list, string $pool, string $mnt = '/mnt'): int
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
    for ($i = 0; $i < POOLCAKE_LIST_MAX && ($line = fgets($fh)) !== false; $i++) {
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
 * One pool's cake as it stands: poolCakeMath() with its movable shares, its snapshots and the proposal for Jack's slice
 * — or null (no ZFS pool, the pool asleep and not to be woken). $o: x, y, n (null: no slice), shares_dir, names (the
 * shares that exist), zfs, mnt, jack_list (EmbyCache's list, null: none), asleep (sleepingDisks()' map, null: asked
 * now), wake (a run is about to wake it anyway: look even while it sleeps).
 */
function poolCake(string $pool, array $o): ?array
{
    if (!($o['wake'] ?? false) && poolsBySleep([$pool], $o['asleep'] ?? null)['asleep']) {
        return null;
    }
    $shares = poolCakeMovable($pool, $o['shares_dir'], $o['names']);
    $look = poolCakeLook($pool, $shares, $o['zfs'] ?? 'zfs');
    if ($look === null) {
        return null;
    }
    $jack = poolCakeJackBytes($o['jack_list'] ?? null, $pool, $o['mnt'] ?? '/mnt');
    $cake = poolCakeMath($look['size'], $look['used'], $look['movable'], (int) $o['x'], (int) $o['y'], $o['n'] ?? null, $jack);
    $any = poolCakeMath($look['size'], $look['used'], $look['movable'], (int) $o['x'], (int) $o['y'], 0, $jack);
    return ['pool' => $pool, 'shares' => $shares, 'snapshots' => $look['snapshots'],
            'snap_pct' => $look['size'] > 0 ? round($look['snapshots'] * 100 / $look['size'], 1) : 0.0,
            'proposal' => ($o['jack_list'] ?? null) !== null ? poolCakeProposal($jack, $any['cake']) : null] + $cake;
}

/**
 * Jack Emby's view (his launch and his page): her rules from her ini, the cake of his pool (cache_path /mnt/<pool>) and
 * what EmbyCache is told — {rules, cake (null: none), env}. env: EMBYCACHE_MIN_FREE_PERCENT = 100 − X while she has a
 * limit (the hard bound; his own setting only without one), EMBYCACHE_BUDGET_BYTES = his slice once she set it. null:
 * she isn't set up (no ini). $o: ini, cache_path, jack_list, shares_dir, names, zfs, mnt, asleep, wake.
 */
function poolCakeJack(array $o): ?array
{
    $rules = poolCakeRules($o['ini']);
    if ($rules === null) {
        return null;
    }
    $env = [];
    $cake = null;
    if ($rules['x'] > 0) {
        $env['EMBYCACHE_MIN_FREE_PERCENT'] = (string) (100 - $rules['x']);
        $mnt = rtrim($o['mnt'] ?? '/mnt', '/');
        $pool = preg_match('#^' . preg_quote($mnt, '#') . '/([\w.\-]{1,64})/?$#D', (string) ($o['cache_path'] ?? ''), $m) ? $m[1] : null;
        if ($pool !== null && $pool !== 'user' && $pool !== 'user0') {
            $cake = poolCake($pool, ['x' => $rules['x'], 'y' => $rules['y'], 'n' => $rules['n']] + $o);
        }
        if ($cake !== null && $cake['budget'] !== null) {
            $env['EMBYCACHE_BUDGET_BYTES'] = (string) $cake['budget'];
        }
    }
    return ['rules' => $rules, 'cake' => $cake, 'env' => $env];
}
