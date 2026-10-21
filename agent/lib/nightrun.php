<?php
declare(strict_types=1);

/*
 * The night run (2026-10-13; the plan 2026-10-14: only X): Jack Emby and Ms. Moverelli work hand in hand as ONE run on
 * ONE schedule (the job `moverelli`, her cron line), serially:
 *
 *   check     the pool as it stands (poolLevel(): used, what never moves, Jack Emby's bytes, the others') and what
 *             waits with Jack Emby (his last status); nightPlan(): what must go to be below X
 *   free      Jack Emby first: he gives back only what was watched (EmbyCache, EMBYCACHE_NO_FILL=1 and
 *             EMBYCACHE_WATCHED_ONLY=1 — never by rank, no ping-pong); then Ms. Moverelli: the age rule always, then
 *             the oldest files across the pool (never one on Jack Emby's list) until there is room for his fetch below
 *             X (his GB come first: --fill-until = X − his fetch; what never moves is never pushed for him)
 *   recheck   the pool measured anew (downloads may have come in meanwhile): his room not there yet → her part again,
 *             at most NIGHT_ROUNDS_MAX rounds; nothing of the others' left → stop
 *   fill      Jack Emby fetches what waits, by his list, only up to X (EMBYCACHE_MIN_FREE_PERCENT = 100 − X) and his
 *             GB budget (always last)
 *   tell      the pool still over X: «target missed» at once — with why (what never moves, or Jack Emby's
 *             unwatched alone keeps it over X); at X but his budget crowds the others out, or appdata & co. and what he
 *             keeps leave him nothing new: a warning too (nightRoomTell())
 *
 * Helmi's tools are called unchanged otherwise; each keeps its own status, history and live panel. One lock: the
 * night's (night.lock in her data folder) — while it is held neither desk starts a run of its own; the runs inside
 * the night pass (nightInside()). Only she hired (Jack Emby not there): her part alone. No limit (X = 0): her part,
 * then Jack Emby's whole run with his own minimum free space. State: night.json (the last night).
 *
 * night.json's `phase` and `who` say where the work is (the card's highlight, core.js Office.nightSteps(); 2026-10-15):
 * `check` (who null) from before the first measure; `free` with who `jack` (his give-back) or `her` (her part); `recheck`
 * (who null) while the pool is measured anew — after his give-back (with its settle) and after her part; `fill` with who
 * `jack`; `done`. A stopped night: `stopped_in` (the phase) and `stopped_who` (whose step; additive — older files lack it).
 *
 * night.json's `picture` — the numbers for the live graphic (bytes; null where not measured):
 *   {pool, x, dest: {to: "array"|<pool>, size, used},                                (the array's from disks.ini, nothing woken)
 *    phases: {check|free|recheck|fill|done: {before: B, forecast: B, planned: P}},   (each phase as it began)
 *    files: [{name, bytes, from, to, who: "jack"|"her"}, …]}                         (the last NIGHT_FILES_MAX done)
 *   B = {size, fixed (pool-only shares + overhead), snapshots (the pool's snapshots, ZFS: snap_all — null: no figure, then
 *        inside fixed), others (the other caching shares), jack, free,
 *        jack_watched (of jack what was watched — goes back in this run; null: not known yet),
 *        jack_reserve (his room not yet filled: min(his GB budget, X − fixed) − jack; null: no budget — nightPictureReserve()),
 *        assumed (forecast only, true: what waits with him isn't known — no run of his yet — so he fills his reserve)}
 *   The check's jack_watched (and its planned jack_back) come from his free step: live from EmbyCache's plan
 *   (progress `back.bytes`, RAM — nightPictureWatched() in moverelliProgressState()) while it works, kept in night.json once
 *   it is done; Emby is never asked for the picture.
 *   P = {jack_back (null until his free step: what was watched), others_back, jack_fetch, until (bytes the pool may use
 *        before his fetch: X − jack_fetch)}
 * moverelliProgressState() adds the files of the tool at work (its live status) while a step runs.
 */

const NIGHT_ROUNDS_MAX = 3;          // her part + at most two more rounds after a recheck
const NIGHT_FILES_MAX  = 50;         // files kept in the picture
const NIGHT_PHASES     = ['check', 'free', 'recheck', 'fill', 'done'];

/** Inside a night run (this process holds the night): the runs it starts pass the night's lock */
function nightInside(?bool $set = null): bool
{
    static $inside = false;
    if ($set !== null) {
        $inside = $set;
    }
    return $inside;
}

/** Is a night run going (its lock held by someone)? */
function nightRunning(string $dir): bool
{
    return is_file("$dir/night.lock") && flockHeld("$dir/night.lock");
}

/** The last night (night.json), read tolerantly: {v, mode, by, started, finished, phase, round, who, plan, steps, result, …} */
function nightState(string $dir): ?array
{
    $n = readJson("$dir/night.json");
    if (!is_array($n) || ($n['v'] ?? null) !== 1) {
        return null;
    }
    $n['running'] = empty($n['finished']) && nightRunning($dir);
    return $n;
}

/**
 * The pool in the picture's shape (bytes): {size, fixed, snapshots, others, jack, free, jack_watched (null: not known)} —
 * null: not measured. The snapshots (ZFS, poolLevel()'s snap_all) apart from what never moves, never more than it; no
 * figure (btrfs, xfs, an older reader) — null, all of it in fixed.
 */
function nightPicture(?array $level, ?int $watched = null): ?array
{
    if ($level === null) {
        return null;
    }
    $snaps = is_int($level['snap_all'] ?? null) ? min(max(0, (int) $level['nm']), max(0, $level['snap_all'])) : null;
    return ['size' => (int) $level['size'], 'fixed' => (int) $level['nm'] - (int) $snaps, 'snapshots' => $snaps, 'others' => (int) $level['others'], 'jack' => (int) $level['jack'],
            'free' => max(0, (int) $level['size'] - (int) $level['used']),
            'jack_watched' => $watched === null ? null : min(max(0, (int) $level['jack']), max(0, $watched))];
}

/**
 * The picture with what was watched known (his free step's plan, or its result): the check's pool gets its jack_watched,
 * its forecast none left (it all goes back), its planned jack_back — where it isn't known yet; the picture as it was else
 */
function nightPictureWatched(array $picture, int $watched): array
{
    $check = $picture['phases']['check'] ?? null;
    if (!is_array($check) || !is_array($check['before'] ?? null) || ($check['before']['jack_watched'] ?? null) !== null) {
        return $picture;
    }
    $watched = min(max(0, (int) ($check['before']['jack'] ?? 0)), max(0, $watched));
    $picture['phases']['check']['before']['jack_watched'] = $watched;
    if (is_array($check['forecast'] ?? null)) {
        $picture['phases']['check']['forecast']['jack_watched'] = 0;
    }
    if (is_array($check['planned'] ?? null) && ($check['planned']['jack_back'] ?? null) === null) {
        $picture['phases']['check']['planned']['jack_back'] = $watched;
    }
    return $picture;
}

/**
 * The plan of a night (pure; 2026-10-14: Jack Emby's GB first). $level: poolLevel() of Jack Emby's pool (null: not
 * measured — then only her part, no numbers); $waiting: what waits on his list (his last status, bytes — his budget caps
 * it already); $jackBack: what he gives back (what was watched — known after his free step, null before: counted as 0).
 * He keeps the rest (kept) and fetches what waits as far as X leaves room above what never moves and what he keeps
 * (fetch) — the fixed shares are never pushed for him. The others move down so that after his fetch the pool sits at X:
 * `until` = X − fetch (bytes the pool may use before his fetch; her fill's target). {measured, x, size, used_pct, need,
 * jack_back, others_back, jack_fetch, wait, until, others_room (what X leaves the others after Jack Emby), over (still
 * over X after the plan), why (never | jack | full | crowded | null), never, before, forecast}. why: never — what never
 * moves reaches X; full — it and what he keeps reach X: nothing new for him; crowded — his fetch leaves the others no
 * room below X; jack — over X only by what he keeps.
 */
function nightPlan(?array $level, int $waiting = 0, ?int $jackBack = null): array
{
    $waiting = max(0, $waiting);
    if ($level === null) {
        return ['measured' => false, 'x' => 0, 'size' => 0, 'used_pct' => 0.0, 'need' => 0, 'jack_back' => $jackBack, 'others_back' => 0,
                'jack_fetch' => 0, 'wait' => $waiting, 'until' => null, 'need_room' => 0, 'others_room' => null, 'over' => false, 'why' => null, 'never' => false,
                'before' => null, 'forecast' => null];
    }
    $size = (int) $level['size'];
    $xb = (int) $level['x'] > 0 ? intdiv((int) $level['x'] * $size, 100) : 0;
    $fixed = (int) $level['nm'];
    $kept = max(0, (int) $level['jack'] - max(0, (int) ($jackBack ?? 0)));
    $back = (int) $level['jack'] - $kept;
    $others = (int) $level['others'];
    // what waits is counted in file sizes; on the pool his files take their allocated bytes (compression): converted by
    // the ratio of what he keeps there (2026-10-15; nothing of his there yet, or no logical count: 1)
    $logical = (int) ($level['jack_logical'] ?? 0);
    $ratio = $logical > 0 && (int) $level['jack'] > 0 ? max(0.1, min(1.0, (int) $level['jack'] / $logical)) : 1.0;
    $needPool = (int) ceil($waiting * $ratio);
    $fetch = $xb > 0 ? min($needPool, max(0, $xb - $fixed - $kept)) : $needPool;
    $waitLeft = $needPool > 0 ? $waiting - min($waiting, (int) floor($fetch / $ratio)) : 0;
    $room = $xb > 0 ? $xb - $fixed - $kept - $fetch : null;
    $othersBack = $xb > 0 ? max(0, $others - max(0, $room)) : 0;
    $after = $fixed + $kept + $others - $othersBack + $fetch;
    $over = $xb > 0 && $after > $xb;
    $why = match (true) {
        $xb <= 0                                => null,
        (bool) $level['never']                  => 'never',
        $fixed + $kept > $xb                    => 'jack',
        $fixed + $kept >= $xb && $waiting > 0   => 'full',
        $room <= 0 && $fetch > 0 && $others > 0 => 'crowded',
        default                                 => null,
    };
    $before = nightPicture($level, $jackBack === null ? null : $back);
    // after the run nothing watched is left on the pool (all of it goes back) — once that is known
    $snaps = $before['snapshots'];
    $forecast = ['size' => $size, 'fixed' => $fixed - (int) $snaps, 'snapshots' => $snaps, 'others' => $others - $othersBack, 'jack' => $kept + $fetch, 'free' => max(0, $size - $after),
                 'jack_watched' => $jackBack === null ? null : 0];
    return ['measured' => true, 'x' => (int) $level['x'], 'size' => $size, 'used_pct' => (float) $level['used_pct'], 'need' => (int) $level['need'],
            'jack_back' => $jackBack === null ? null : $back, 'others_back' => $othersBack, 'jack_fetch' => $fetch, 'wait' => $waitLeft,
            'until' => $xb > 0 ? $xb - $fetch : null, 'need_room' => $xb > 0 ? max(0, (int) $level['used'] - ($xb - $fetch)) : 0, 'others_room' => $room, 'over' => $over, 'why' => $why, 'never' => (bool) $level['never'],
            'before' => $before, 'forecast' => $forecast];
}

/** The «Tonight: …» line's params for the pages (state, not texts): what the others give, what Jack Emby fetches, what waits */
function nightWords(array $plan): array
{
    return ['measured' => (bool) $plan['measured'], 'back' => $plan['jack_back'], 'goes' => (int) $plan['others_back'],
            'fill' => (int) $plan['jack_fetch'], 'wait' => (int) $plan['wait'], 'over' => (bool) $plan['over'], 'why' => $plan['why']];
}

/**
 * His reserved room in a plan's bars (pure; owner + helmi1987, 2026-10-15: his GB come first, so the picture shows his
 * whole place, not only what he holds): jack_reserve = min($budget, X − fixed — the whole pool without X) − his part,
 * never below 0; no budget → null (no reserve). $waitKnown false (no run of his yet, nothing known to wait): the forecast
 * assumes he fills his reserve up to his budget — the others making room as the plan would — marked `assumed`. Only the
 * picture: the plan's own numbers (her fill's target) stay as reckoned.
 */
function nightPictureReserve(array $plan, ?int $budget, bool $waitKnown = true): array
{
    $b = $plan['before'] ?? null;
    if (!is_array($b) || !is_array($plan['forecast'] ?? null)) {
        return $plan;
    }
    $size = (int) $b['size'];
    $fixed = (int) $b['fixed'] + (int) ($b['snapshots'] ?? 0);
    $xb = (int) ($plan['x'] ?? 0) > 0 ? intdiv((int) $plan['x'] * $size, 100) : 0;
    $cap = $budget === null || $budget <= 0 ? null : min($budget, max(0, ($xb > 0 ? $xb : $size) - $fixed));
    $reserve = fn (int $held): ?int => $cap === null ? null : max(0, $cap - $held);
    $f = $plan['forecast'];
    if (!$waitKnown && $cap !== null) {
        // he fills his reserve: what he keeps plus his fetch up to his cap — the others moved down as far as X needs
        $kept = max(0, (int) $b['jack'] - max(0, (int) ($plan['jack_back'] ?? 0)));
        $fetch = max(0, $cap - $kept);
        $room = $xb > 0 ? $xb - $fixed - $kept - $fetch : null;
        $others = (int) $b['others'] - ($xb > 0 ? max(0, (int) $b['others'] - max(0, $room)) : 0);
        $f = ['jack' => $kept + $fetch, 'others' => $others, 'free' => max(0, $size - ($fixed + $kept + $fetch + $others)), 'assumed' => true] + $f;
    }
    $plan['before']['jack_reserve'] = $reserve((int) $b['jack']);
    $f['jack_reserve'] = $reserve((int) $f['jack']);
    $plan['forecast'] = $f;
    return $plan;
}

/** One phase of the picture: the pool now, the plan's forecast and its planned bytes */
function nightPhasePicture(array $plan): array
{
    return ['before' => $plan['before'], 'forecast' => $plan['forecast'],
            'planned' => ['jack_back' => $plan['jack_back'], 'others_back' => (int) $plan['others_back'], 'jack_fetch' => (int) $plan['jack_fetch'],
                          'until' => $plan['until']]];
}

/**
 * The files a tool's status names as done (EmbyCache's progress `recent`, Smart Mover's status `recent`) in the
 * picture's shape: [{name, bytes, from, to, who}] — only entries in exactly their shape
 */
function nightFiles(mixed $recent, string $who): array
{
    $out = [];
    foreach (is_array($recent) ? array_slice(array_values($recent), -NIGHT_FILES_MAX) : [] as $f) {
        if (is_array($f) && is_string($f['name'] ?? null) && is_int($f['bytes'] ?? null) && is_string($f['from'] ?? null) && is_string($f['to'] ?? null)) {
            $out[] = ['name' => mb_substr($f['name'], 0, 300), 'bytes' => max(0, $f['bytes']), 'from' => mb_substr($f['from'], 0, 64),
                      'to' => mb_substr($f['to'], 0, 64), 'who' => $who];
        }
    }
    return $out;
}

/** Where her moves go from Jack Emby's pool: the secondary pool of its caching shares, else the array — with size/used when measured */
function nightDest(?string $pool, array $h): ?array
{
    if ($pool === null) {
        return null;
    }
    $to = 'array';
    foreach (moverelliShareNames($h['shares_dir'], $h) as $share) {
        $cfg = readCfg("$h[shares_dir]/$share.cfg");
        if (($cfg['shareUseCache'] ?? '') === 'yes' && (($cfg['shareCachePool'] ?? '') ?: 'cache') === $pool && ($cfg['shareCachePool2'] ?? '') !== '') {
            $to = (string) $cfg['shareCachePool2'];
            break;
        }
    }
    $size = $used = null;
    if ($to === 'array') {
        [$size, $used] = nightArrayLevel((string) ($h['disks_ini'] ?? disksIniFile()));
    } elseif (preg_match('/^[\w.\-]{1,64}$/D', $to) && !poolsBySleep([$to], $h['asleep'])['asleep']) {
        // a btrfs/xfs pool: statfs as df counts it (2026-10-15)
        $look = poolLevelLook($to, [], $h['zfs']) ?? poolLevelStatfs(rtrim((string) ($h['mnt'] ?? '/mnt'), '/') . "/$to", (string) ($h['stat'] ?? 'stat'));
        [$size, $used] = $look !== null ? [$look['size'], $look['used']] : [null, null];
    }
    return ['to' => $to, 'size' => $size, 'used' => $used];
}

/**
 * The array's size and use in bytes from Unraid's bookkeeping (disks.ini: the data disks' fsSize/fsUsed in KB, kept by
 * emhttp — no disk asked, none woken); [null, null] when no data disk says it (array stopped, an older disks.ini)
 */
function nightArrayLevel(string $file): array
{
    $size = $used = 0;
    foreach (is_file($file) ? readCfg($file, true) : [] as $section => $d) {
        if (is_array($d) && ($d['type'] ?? '') === 'Data' && preg_match('/^disk\d+$/D', (string) ($d['name'] ?? $section))
            && ctype_digit((string) ($d['fsSize'] ?? '')) && ctype_digit((string) ($d['fsUsed'] ?? '')) && (int) $d['fsSize'] > 0) {
            $size += (int) $d['fsSize'] * 1024;
            $used += min((int) $d['fsUsed'], (int) $d['fsSize']) * 1024;
        }
    }
    return $size > 0 ? [$size, $used] : [null, null];
}

/**
 * Where the night reads and writes, whom it calls — moverelliHost() plus the night's own (the tests: $GLOBALS['nightHost']).
 */
const NIGHT_OUTPUT = 'night-output.txt';     // in her data folder: every step's output of the last night, one after the other
const NIGHT_OUTPUT_MAX = 2 << 20;            // its size at most (the start of a long one kept, the rest cut)

/** Every step's output of the night so far: the step's header, then what its tool wrote (a step's own file is new each run) */
function nightOutputAdd(string $dir, string $head, string $text): void
{
    $all = (string) @file_get_contents("$dir/" . NIGHT_OUTPUT, false, null, 0, NIGHT_OUTPUT_MAX) . "===== $head =====\n" . rtrim($text) . "\n\n";
    writeAtomic("$dir/" . NIGHT_OUTPUT, substr($all, 0, NIGHT_OUTPUT_MAX), 0600, 0, 0);
}

function nightHost(?array $h = null): array
{
    $h ??= moverelliHost();
    $n = $GLOBALS['nightHost'] ?? [];
    // Jack Emby's records (his refusal's reason) — moverelliHost() carries them too: the night's own stand-in first
    $h = (isset($n['jack_runs']) ? ['jack_runs' => $n['jack_runs']] : []) + $h;
    return $h + [
        // Jack Emby takes part: hired and set up
        'jack_on'     => $n['jack_on'] ?? fn (): bool => function_exists('jackReadSettings') && ($h['jack_desk'])() !== null && (!function_exists('jackConfigured') || jackConfigured(jackReadSettings())) && jackReadSettings() !== null,
        'jack_job'    => $n['jack_job'] ?? fn (array $args): int => jackJob('embycache', $args),
        'her_job'     => $n['her_job'] ?? fn (array $args, array $hh): int => moverelliJob($args, $hh),
        'jack_status' => $n['jack_status'] ?? fn (): array => (array) (readJson(JACK_DATA . '/status.json') ?? []),
        // each step's own output (the tools write it anew each run): the night keeps all of them (night-output.txt)
        'jack_output' => $n['jack_output'] ?? fn (): string => defined('JACK_DATA') ? (string) @file_get_contents(JACK_DATA . '/office-output.txt', false, null, 0, NIGHT_OUTPUT_MAX) : '',
        'her_output'  => $n['her_output'] ?? fn (): string => (string) @file_get_contents($h['dir'] . '/office-output.txt', false, null, 0, NIGHT_OUTPUT_MAX),
        'jack_runs'   => $n['jack_runs'] ?? $h['jack_runs'],
        'jack_stop'   => $n['jack_stop'] ?? (defined('JACK_DATA') ? JACK_DATA . '/office-stop.json' : ''),
        // EmbyCache's live progress (RAM): the files his step did (`recent`) for the picture
        'jack_live'   => $n['jack_live'] ?? fn (): array => function_exists('jackProgressPaths') ? (array) (readJson(jackProgressPaths()['file']) ?? []) : [],
        // his GB budget in bytes (his settings' cache_budget: his reserved room in her picture) — null: none
        'jack_budget' => $n['jack_budget'] ?? fn (): ?int => function_exists('jackReadSettings') && function_exists('jackSizeBytes')
            && is_string((jackReadSettings() ?? [])['cache_budget'] ?? null) && ($b = jackSizeBytes((string) jackReadSettings()['cache_budget'])) > 0 ? $b : null,
        // his live panel's state (jack-progress.php: {real, progress}) — her card shows it while his step runs
        'jack_progress' => $n['jack_progress'] ?? fn (array $night): array => function_exists('jackNightProgress') ? jackNightProgress($night) : [],
        'notify'      => $n['notify'] ?? fn (string $subject, string $body, string $event = 'night'): bool
                             => officeNotify($subject, $body, 'warning', '', officeNotifyLink('#/moverelli'), 'moverelli', $event),
        'rounds'      => $n['rounds'] ?? NIGHT_ROUNDS_MAX,
        // seconds the pool gets after Jack Emby's give-back before it is measured (ZFS shows freed space a txg later)
        'settle'      => $n['settle'] ?? 6,
        // Jack Emby's pool measured for her rules (the tests: a pool of their own)
        'level'       => $n['level'] ?? fn (array $g, array $hh, bool $wake): ?array => nightLevel($g, $hh, $wake),
        // Unraid's bookkeeping of the disks: the array's size and use for the picture (the tests: a file of their own)
        'disks_ini'   => $n['disks_ini'] ?? disksIniFile(),
        // the order with the scheduled backup (nightorder.php's host; null: its own — the tests: stand-ins)
        'order'       => $n['order'] ?? null,
    ];
}

/**
 * The pool measured for her rule (null: no limit, no pool measured): Jack Emby's pool while he takes part (woken when $wake);
 * without him — or without a pool of his — the pool of her movable shares (nightHerPool(): never woken)
 */
function nightLevel(array $g, array $h, bool $wake): ?array
{
    if ((int) $g['move_when_used_above'] <= 0) {
        return null;
    }
    $jack = !isset($h['jack_on']) || (bool) ($h['jack_on'])();
    $pool = $jack ? moverelliJackPool($h) : null;
    if ($pool === null) {
        return nightHerPool($g, $h, $jack);
    }
    return poolLevel($pool, ['x' => (int) $g['move_when_used_above'], 'shares_dir' => $h['shares_dir'], 'names' => moverelliShareNames($h['shares_dir'], $h),
        'zfs' => $h['zfs'], 'mnt' => $h['mnt'], 'jack_list' => $h['jack_list_file'], 'asleep' => $h['asleep'], 'wake' => $wake, 'measure' => 'night']);
}

/**
 * Her pool when Jack Emby's isn't the night's (moverelliLevels(), as her setup measures — a sleeping pool not looked at):
 * the one with the most used % over X, else the largest measured; null: none measured. Jack Emby's bytes only while he
 * takes part.
 */
function nightHerPool(array $g, array $h, bool $jack): ?array
{
    // a btrfs/xfs pool's folders measured now (the night's du, not the stored one)
    $levels = array_filter(moverelliLevels($g, ['pool_measure' => 'night'] + ($jack ? $h : ['jack_cache' => fn () => null] + $h)));
    if (!$levels) {
        return null;
    }
    uasort($levels, fn (array $a, array $b): int => [$b['used_pct'] > $b['x'] ? $b['used_pct'] - $b['x'] : -1, $b['size']]
        <=> [$a['used_pct'] > $a['x'] ? $a['used_pct'] - $a['x'] : -1, $a['size']]);
    $level = reset($levels);
    unset($level['mine']);
    return $level;
}

/** What waits with Jack Emby (his last run's status, bytes) */
function nightWaiting(array $h): int
{
    $p = (array) (($h['jack_status'])()['pool'] ?? []);
    return max(0, (int) ($p['waiting_bytes'] ?? 0));
}

/** Is what waits with Jack Emby known (his last status has it — false before his first run)? */
function nightWaitKnown(array $h): bool
{
    $p = (($h['jack_status'])()['pool'] ?? null);
    return is_array($p) && is_int($p['waiting_bytes'] ?? null);
}

/** The night's state file, written anew at every step */
function nightWrite(string $dir, array $n): void
{
    writeAtomic("$dir/night.json", jsonEncode($n), 0600, 0, 0);
}

/**
 * «Stop after this file» for the whole night: the night's stop file and the stop file of the tool at work (Jack Emby's
 * EmbyCache or her Smart Mover) — the file in flight finishes, no further step starts. false: no night going.
 */
function nightStopAsk(?array $h = null): bool
{
    $h = nightHost($h);
    $dir = $h['dir'];
    $n = nightState($dir);
    if ($n === null || !$n['running'] || is_file("$dir/night-stop.json")) {
        return false;
    }
    $stop = jsonEncode(['time' => time(), 'why' => 'user']);
    writeAtomic("$dir/night-stop.json", $stop, 0600, 0, 0);
    $tool = ($n['who'] ?? '') === 'jack' ? (string) $h['jack_stop'] : "$dir/office-stop.json";
    if ($tool !== '' && ($n['who'] ?? '') !== '') {
        writeAtomic($tool, $stop, 0600, 0, 0);
    }
    logLine('Night run: asked to stop — the file in flight finishes, no further step starts');
    return true;
}

/**
 * The job itself: `php agent.php job moverelli [run|dry] [--office]` (her cron line, her page's and Jack Emby's
 * «Run now»). A real run waits for Unraid's mover like her scheduled runs did; then: check, reckon, free, recheck,
 * fill, tell. Returns 0 (done, also «target missed»), 1 (refused / a step refused), 3 (stopped).
 */
function nightRun(array $args, ?array $h = null): int
{
    $h = nightHost($h);
    $dir = $h['dir'];
    $by = in_array('--office', $args, true) ? 'office' : 'schedule';
    $args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
    $mode = ($args[0] ?? 'run') === 'dry' ? 'dry' : 'run';
    if (!($h['hired'])()) {
        logLine("Night run ($mode) not started — Ms. Moverelli isn't hired (a schedule left behind does nothing)");
        fwrite(STDERR, "night: not started (not hired)\n");
        return 1;
    }
    $refused = function (string $why, array $params = []) use ($mode, $by, $dir, $h): int {
        moverelliRemember(['mode' => $mode, 'by' => $by, 'night' => true, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $why]
            + (($h['jack_on'])() ? [] : ['jack' => false]) + moverelliRefusedParams($params), $dir);
        logLine("Night run ($mode) not started ($why)");
        fwrite(STDERR, "night: not started ($why)\n");
        return 1;
    };
    try {
        moverelliRunCheck($mode, $h);
    } catch (Problem $p) {
        if ($p->key === 'moverelli_not_configured') {
            fwrite(STDERR, "night: not started ($p->key)\n");
            return 1;
        }
        return $refused($p->key, $p->params);
    }
    if (nightRunning($dir)) {
        return $refused('moverelli_running');
    }
    $wait = null;
    $waited = 0;
    $order = null;
    if ($mode === 'run') {
        // a backup doing its local part (nightorder.php): on schedule she waits until it is done, from a page refused
        $oh = nightOrderHost($h['order']);
        if (($oh['backup_holds'])()) {
            if ($by !== 'schedule') {
                return $refused('moverelli_backup_running');
            }
            logLine('Night run: a backup runs — waiting until its local part is done');
            $w = nightOrderWait($oh, $oh['backup_holds']);
            if ($w === null) {
                logLine('Night run: the scheduled run stops waiting for the backup (the array stopped)');
                return 0;
            }
            $order = ['code' => 'waited_backup', 'seconds' => $w];
            logLine('Night run: waited ' . nightOrderSpan($w) . ' for Mr. Backupsy');
        }
        // a scheduled night waits for Unraid's mover (as her scheduled runs always did); from a page: refused at once
        $gate = jackRunGate('moverelli', $by, ['watch' => false, 'dir' => $dir, 'waitdir' => $h['waitdir'],
            'mover' => fn (): bool => ($h['mover_running'])(), 'scheduled' => $h['scheduled']] + $h['gate']);
        if (!$gate['go']) {
            if (in_array($gate['result'], ['refused', 'skipped'], true)) {
                moverelliRemember(['mode' => $mode, 'by' => $by, 'night' => true, 'started' => time(), 'finished' => time(), 'result' => $gate['result'],
                                   'why' => 'moverelli_mover_running'] + ($gate['waited'] > 0 ? ['waited' => $gate['waited']] : []), $dir);
                logLine('Night run ' . ($gate['result'] === 'skipped' ? 'skipped' : 'not started') . " — Unraid's mover is at work");
                return $gate['result'] === 'skipped' ? 0 : 1;
            }
            logLine("Night run: the scheduled run stops waiting ({$gate['result']})");
            return 0;
        }
        $wait = $gate['lock'];
        $waited = (int) $gate['waited'];
    }
    moverelliDataDir($dir);
    // the start lock both desks share: none of theirs going, then the night's lock — one at a time
    $movers = jackMoversLock((int) $h['lock_wait']);
    $hold = null;
    if ($movers !== null && !($h['jack_busy'])() && !moverelliBusy($dir)) {
        $hold = @fopen("$dir/night.lock", 'c');
        if ($hold && !flock($hold, LOCK_EX | LOCK_NB)) {
            fclose($hold);
            $hold = null;
        }
    }
    jackMoversUnlock($movers);
    jackWaitEnd($wait, $h['waitdir'], 'moverelli');
    if ($hold === null) {
        return $refused($movers === null || ($h['jack_busy'])() ? 'moverelli_jack_running' : 'moverelli_running');
    }
    nightInside(true);
    @unlink("$dir/night-stop.json");
    try {
        return nightSteps($mode, $by, $waited, $h, $order);
    } finally {
        nightInside(false);
        @unlink("$dir/night-stop.json");
        flock($hold, LOCK_UN);
        fclose($hold);
    }
}

/** What Jack Emby's free step gave back (his status: what was watched — done in a real run, planned in a dry one) */
function nightJackBack(array $h, bool $real): int
{
    $c = (array) ((($h['jack_status'])())['cleanup'] ?? []);
    return max(0, (int) ($c[$real ? 'bytes_done' : 'bytes_planned'] ?? 0));
}

/** What Jack Emby's dry step would fetch (his status) */
function nightDryFetch(array $h): int
{
    return max(0, (int) (((array) ((($h['jack_status'])())['fill'] ?? []))['bytes_planned'] ?? 0));
}

/** The steps of a night (inside its lock) — see nightRun() */
function nightSteps(string $mode, string $by, int $waited, array $h, ?array $order = null): int
{
    $dir = $h['dir'];
    $real = $mode === 'run';
    $g = (moverelliSettings($dir) ?? moverelliDefaults())['global'];
    $jack = (bool) ($h['jack_on'])();
    $started = time();
    // «Measure» on the card while the pool is measured (it may wake the pool: seconds): the night as it begins, the plan
    // and the picture follow once measured (2026-10-15 — the step highlight sits where the work is)
    nightWrite($dir, ['v' => 1, 'mode' => $mode, 'by' => $by, 'pid' => getmypid(), 'started' => $started, 'phase' => 'check', 'round' => 1, 'who' => null,
                      'jack' => $jack, 'steps' => [], 'result' => null] + ($waited > 0 ? ['waited' => $waited] : []) + ($order !== null ? ['order' => $order] : []));
    writeAtomic("$dir/" . NIGHT_OUTPUT, '', 0600, 0, 0);     // this night's steps, from the first
    // the pool measured: Jack Emby's while he takes part, else the pool of her shares (her picture on every night)
    $level = ($h['level'])($g, $h, $real && $jack);
    $plan = nightPlan($level, $jack ? nightWaiting($h) : 0);
    $pool = $level['pool'] ?? ($jack ? moverelliJackPool($h) : null);
    // his GB budget (his reserved room in the picture) — only while he takes part
    $budget = $jack ? ($h['jack_budget'])() : null;
    $pic = fn (array $p, bool $end = false): array => nightPhasePicture(nightPictureReserve($p, $budget, $end || !$jack || nightWaitKnown($h)));
    $n = ['v' => 1, 'mode' => $mode, 'by' => $by, 'pid' => getmypid(), 'started' => $started, 'phase' => 'check', 'round' => 1, 'who' => null,
          'jack' => $jack, 'pool' => $pool, 'plan' => nightWords($plan) + ['used_pct' => $plan['used_pct'], 'x' => $plan['x']],
          'picture' => ['pool' => $pool, 'x' => $plan['x'], 'dest' => $plan['measured'] ? nightDest($pool, $h) : null]
                     // a btrfs/xfs pool: its numbers estimated (poolLevelPlain()) — the picture says so
                     + (!empty($level['estimated']) ? ['estimated' => true] : []) + [
                        'phases' => ['check' => $pic($plan)], 'files' => []],
          'steps' => [], 'result' => null] + ($waited > 0 ? ['waited' => $waited] : []) + ($order !== null ? ['order' => $order] : []);
    nightWrite($dir, $n);
    logLine("Night run ($mode): " . ($jack ? 'Jack Streamer and Ms. Moverelli' : 'Ms. Moverelli alone') . ($plan['measured']
        ? " — pool $pool at {$plan['used_pct']} %, X {$plan['x']} %: " . ($jack ? 'Jack Streamer gives back what was watched, the others about ' : 'about ')
          . poolLevelSize($plan['others_back']) . ' (and the age rule)' . ($jack ? ', Jack Streamer fetches up to ' . poolLevelSize($plan['jack_fetch']) : '')
        : ''));
    $stopped = fn (): bool => is_file("$dir/night-stop.json");
    // the picture: a phase as it begins (the pool measured anew where the step needs it)
    $phase = function (string $ph, array $p) use (&$n, $pic): void {
        if ($p['measured']) {
            $n['picture']['phases'][$ph] = $pic($p, $ph === 'done');
        }
    };
    // one step: a tool called with its part; false = the night ends here (stopped, refused)
    // who started the night (its schedule or the page) goes along to both: their run lists say it, never «at night»
    $from = $by === 'office' ? ['--office'] : [];
    $step = function (string $who, string $phase, array $extra = []) use (&$n, $h, $dir, $mode, $stopped, $jack, $from): bool {
        if ($stopped()) {
            $n['result'] = 'stopped';
            $n['stopped_in'] = $phase;
            $n['stopped_who'] = $who;
            return false;
        }
        $n['phase'] = $phase;
        $n['who'] = $who;
        nightWrite($dir, $n);
        $counts = [];
        if ($who === 'jack') {
            $args = array_merge([$mode, '--night'], $from);
            if ($phase !== 'fill' && $mode === 'run') {
                $args[] = '--nofill';
            }
            $exit = ($h['jack_job'])($args);
            $last = ($h['jack_runs'])()[0] ?? [];
            $recent = (($h['jack_live'])())['recent'] ?? [];
            // what his step did (his status), kept with the step: his page sums the night's give-back and fetch
            $st = ($h['jack_status'])();
            foreach (['cleanup', 'fill'] as $k) {
                if (is_array($st[$k] ?? null)) {
                    $counts[$k] = array_map('intval', array_intersect_key($st[$k], array_flip(['planned', 'done', 'bytes_planned', 'bytes_done'])));
                }
            }
            nightOutputAdd($dir, "Jack Streamer · $phase · round {$n['round']}", ($h['jack_output'])());
        } else {
            // without Jack Emby (not taking part, or his step refused tonight): her part alone, her record says why
            $out = isset($n['jack_out']) ? ['--solo', '--jack-out=' . $n['jack_out']] : ['--solo'];
            $exit = ($h['her_job'])(array_merge([$mode, '--night'], $from, $jack && !isset($n['jack_out']) ? [] : $out, $extra), $h);
            $last = moverelliHistory($dir)[0] ?? [];
            $recent = (readJson("$dir/status.json") ?? [])['recent'] ?? [];
            nightOutputAdd($dir, "Ms. Moverelli · $phase · round {$n['round']}", ($h['her_output'])());
        }
        $n['picture']['files'] = array_slice(array_merge($n['picture']['files'], nightFiles($recent, $who)), -NIGHT_FILES_MAX);
        $result = (string) ($last['result'] ?? ($exit === 0 ? 'ok' : 'failed'));
        $n['steps'][] = ['who' => $who, 'phase' => $phase, 'round' => $n['round'], 'exit' => $exit, 'result' => $result] + $counts;
        $n['who'] = null;
        nightWrite($dir, $n);
        if ($exit === 3 || $result === 'stopped' || $stopped()) {
            $n['result'] = 'stopped';
            $n['stopped_in'] = $phase;
            $n['stopped_who'] = $who;
            return false;
        }
        if ($who === 'jack' && in_array($result, ['refused', 'skipped'], true)) {
            // Jack Emby refused (not set up, «consolidate folders» first, someone watching …): the night goes on with her part
            // alone — said on both pages with his reason (his record's code), never the whole night refused
            $why = (string) ($last['why'] ?? '');
            $n['jack_out'] = preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $why) ? $why : 'jack_refused';
            logLine("Night run: Jack Streamer not in it tonight ({$n['jack_out']}) — Ms. Moverelli's part alone");
            nightWrite($dir, $n);
            return true;
        }
        if (in_array($result, ['refused', 'skipped'], true)) {
            $n['result'] = 'refused';
            $n['refused_by'] = $who;
            return false;
        }
        return true;
    };
    // her part with Jack Emby's room: her fill goes until the pool uses at most X − his fetch (only on his pool)
    $room = fn (array $p): array => $p['measured'] && $p['until'] !== null && $pool !== null ? ['--fill-until=' . $p['until'], '--fill-pool=' . $pool] : [];
    $go = true;
    $roomWhy = null;
    if (!$jack) {
        // only she: her part (the age rule, from X the oldest until below X)
        $go = $step('her', 'free');
    } elseif (!$plan['measured']) {
        // no limit (or the pool not measured): her part, then Jack Emby's whole run with his own minimum free space
        $go = $step('her', 'free') && ($real ? $step('jack', 'fill') : $step('jack', 'free'));
    } else {
        // free: Jack Emby gives back what was watched, then she moves the others' oldest down so his fetch ends at X
        $go = $step('jack', 'free');
    }
    if ($jack && $plan['measured'] && $go && isset($n['jack_out'])) {
        // Jack Emby refused his first step: her part alone (the age rule, from X the oldest until below X), no room kept for him
        $alone = nightPlan($level, 0);
        $n['plan'] = nightWords($alone) + ['used_pct' => $alone['used_pct'], 'x' => $alone['x']];
        $go = $step('her', 'free');
    } elseif ($jack && $plan['measured']) {
        $back = $go ? nightJackBack($h, $real) : 0;
        if ($go && $real) {
            // the pool measured anew after his give-back (its settle included): «Measure again» on the card, nobody at work
            $n['phase'] = 'recheck';
            nightWrite($dir, $n);
        }
        if ($go) {
            // what was watched, now known: the check's pool split (the picture's «before»)
            $n['picture'] = nightPictureWatched($n['picture'], $back);
        }
        if ($go && $real && $back > 0 && (int) $h['settle'] > 0) {
            sleep(min(30, (int) $h['settle']));     // what he gave back shows in the pool's reading a txg later (R2, 2026-10-15)
        }
        // a real run measures the pool anew (his give-back is in it, kept as a number); a dry run reckons it from his dry answer,
        // what his dry step would fetch standing for what waits
        $plan = $real ? ['jack_back' => $back] + nightPlan(($h['level'])($g, $h, true), nightWaiting($h), 0) : nightPlan($level, nightDryFetch($h), $back);
        $phase('free', $plan);
        $roomWhy = $plan['why'];                    // the reckoning's word on the room (before she moved anything)
        $n['plan'] = nightWords($plan) + ['used_pct' => $plan['used_pct'], 'x' => $plan['x']];
        $go = $go && $step('her', 'free', $room($plan));
        // recheck: downloads may have come in while they moved — her part again while the pool leaves Jack Emby too little room
        for ($round = 2; $go && $real && $round <= (int) $h['rounds']; $round++) {
            $n['phase'] = 'recheck';
            nightWrite($dir, $n);
            $again = nightPlan(($h['level'])($g, $h, true), nightWaiting($h), 0);
            if (!$again['measured'] || $again['need_room'] <= 0) {
                $plan = $again['measured'] ? $again : $plan;
                break;
            }
            $phase('recheck', $again);
            $plan = $again;
            if (((readJson("$dir/status.json") ?? [])['fill_held'] ?? false) === true) {
                // her fill moved what the pool was over, yet the pool didn't get freer (snapshots keep what moved): another
                // round would move as much again for nothing (R2, 2026-10-15)
                $n['missed'] = true;
                logLine('Night run: her part moved what the pool was over, but it stays as full (snapshots keep what moved) — no further round');
                break;
            }
            if ($again['others_back'] <= 0) {
                $n['missed'] = $again['need'] > 0;      // nothing of the others' left to move
                logLine('Night run: nothing of the others\' left to move' . ($again['why'] !== null ? " ({$again['why']})" : ''));
                break;
            }
            $n['round'] = $round;
            logLine("Night run: round $round — the pool grew meanwhile (" . poolLevelSize($again['need_room']) . ' over the room Jack Streamer needs)');
            $go = $step('her', 'free', $room($again));
        }
        // fill: Jack Emby fetches what waits, only up to X — always last
        if ($go) {
            if ($real) {
                $plan = nightPlan(($h['level'])($g, $h, true), nightWaiting($h), 0);
            }
            $phase('fill', $plan);
            $go = $real ? $step('jack', 'fill') : true;     // a dry run: his dry free step showed his fetch already
        }
    }
    // the pool after a real night (her picture's «after», with Jack Emby or without); told: still over X (only the joint
    // night — her own part alone tells through her fill's notification)
    $end = $real ? ($h['level'])($g, $h, false) : null;
    if ($end !== null) {
        $n['end_pct'] = $end['used_pct'];
        $phase('done', nightPlan($end, 0, 0));
    }
    $n['result'] ??= 'ok';
    $with = $jack && !isset($n['jack_out']);
    // over X (Jack Emby fills up to X exactly — at X is no miss)
    if ($go && $with && $end !== null && $end['x'] > 0 && $end['need'] > 0) {
        $n['result'] = 'missed';
        $n['why'] = $end['never'] ? 'never' : ($end['jack_over'] ? 'jack' : null);
        nightMissedTell($end, $h);
    } elseif ($go && $with && $end !== null && in_array($roomWhy, ['full', 'crowded'], true)) {
        // at X, but Jack Emby's GB crowd the others out (or what never moves and what he keeps leave him nothing new): told
        $n['why'] = $roomWhy;
        nightRoomTell($roomWhy, $end, $h);
    }
    $n['phase'] = 'done';
    $n['who'] = null;
    $n['finished'] = time();
    nightWrite($dir, $n);
    logLine("Night run ($mode): {$n['result']}" . (isset($n['jack_out']) ? " (without Jack Streamer: {$n['jack_out']})" : '') . (isset($n['stopped_in']) ? " (in {$n['stopped_in']})" : '') . ($n['round'] > 1 ? ", {$n['round']} rounds" : ''));
    return match ($n['result']) { 'stopped' => 3, 'refused' => 1, default => 0 };
}

/**
 * The pool still at/above X after a night: to Unraid's notifications at once (a warning) — with why when it is
 * Jack Emby's unwatched alone (his budget is too big for this pool)
 */
function nightMissedTell(array $end, array $h): bool
{
    $lang = officeNotifyLang();
    $p = ['pool' => (string) $end['pool'], 'now' => (int) floor($end['used_pct']), 'x' => (int) $end['x'], 'nm' => (int) floor($end['nm_pct'])];
    $key = !$end['never'] && $end['jack_over'] ? 'notify.night_missed_jack' : 'notify.night_missed';
    $ok = ($h['notify'])(officeNotifyText('moverelli', 'notify.night_subject', $p, $lang), officeNotifyText('moverelli', $key, $p, $lang), 'night_missed');
    logLine("Night run: pool {$p['pool']} still at {$p['now']} % (limit {$p['x']} %)" . ($key === 'notify.night_missed_jack' ? ' — only Jack Streamer\'s unwatched is over it' : '')
        . ($ok ? " — told Unraid's notifications" : ''));
    return $ok;
}

/**
 * The night reached X, but the room is wrong (a warning at once): `full` — what never moves (appdata & co.) and what Jack
 * Emby keeps fill the pool up to X: he can fetch nothing new; `crowded` — his budget leaves the other caching shares no
 * room below X: the day's downloads all land above X
 */
function nightRoomTell(string $why, array $end, array $h): bool
{
    $lang = officeNotifyLang();
    $p = ['pool' => (string) $end['pool'], 'x' => (int) $end['x'], 'jack' => poolLevelSize((int) $end['jack'])];
    $ok = ($h['notify'])(officeNotifyText('moverelli', 'notify.night_room_subject', $p, $lang), officeNotifyText('moverelli', "notify.night_$why", $p, $lang), 'night_room');
    logLine("Night run: pool {$p['pool']} — " . ($why === 'full' ? 'appdata & co. and what Jack Streamer keeps fill it up to X: nothing new for him'
        : 'Jack Streamer\'s budget leaves the other shares no room below X') . ($ok ? " — told Unraid's notifications" : ''));
    return $ok;
}
