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
 *             the oldest files across the pool (never one on Jack Emby's list) until the pool is below X
 *   recheck   the pool measured anew (downloads, appdata may have grown meanwhile): still at/above X → her part again,
 *             at most NIGHT_ROUNDS_MAX rounds; nothing of the others' left → stop (only Jack Emby's unwatched is over X)
 *   fill      Jack Emby fetches what waits, by his list, only up to X (EMBYCACHE_MIN_FREE_PERCENT = 100 − X) and his
 *             GB budget (always last)
 *   tell      the pool still over X: «target missed» at once — with why (what never moves, or Jack Emby's
 *             unwatched alone keeps it over X: his budget is too big for this pool)
 *
 * Helmi's tools are called unchanged otherwise; each keeps its own status, history and live panel. One lock: the
 * night's (night.lock in her data folder) — while it is held neither desk starts a run of its own; the runs inside
 * the night pass (nightInside()). Only she hired (Jack Emby not there): her part alone. No limit (X = 0): her part,
 * then Jack Emby's whole run with his own minimum free space. State: night.json (the last night).
 *
 * night.json's `picture` — the numbers for the live graphic (bytes; null where not measured):
 *   {pool, x, dest: {to: "array"|<pool>, size, used},
 *    phases: {check|free|recheck|fill|done: {before: B, forecast: B, planned: P}},   (each phase as it began)
 *    files: [{name, bytes, from, to, who: "jack"|"her"}, …]}                         (the last NIGHT_FILES_MAX done)
 *   B = {size, fixed (pool-only shares + snapshots + overhead), others (the other caching shares), jack, free}
 *   P = {jack_back (null until his free step: what was watched), others_back, jack_fetch}
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

/** The pool in the picture's shape (bytes): {size, fixed, others, jack, free} — null: not measured */
function nightPicture(?array $level): ?array
{
    if ($level === null) {
        return null;
    }
    return ['size' => (int) $level['size'], 'fixed' => (int) $level['nm'], 'others' => (int) $level['others'], 'jack' => (int) $level['jack'],
            'free' => max(0, (int) $level['size'] - (int) $level['used'])];
}

/**
 * The plan of a night (pure). $level: poolLevel() of Jack Emby's pool (null: not measured — then only her part, no
 * numbers); $waiting: what waits with Jack Emby (his last status, bytes); $jackBack: what he gives back (what was
 * watched — known after his free step, null before: counted as 0). Jack Emby's give-back first, then the others'
 * oldest down to below X, then Jack Emby fetches up to X. {measured, x, size, used_pct, need, jack_back, others_back,
 * jack_fetch, wait, over (still at/above X after the plan), why (never | jack | null), never, before, forecast}
 */
function nightPlan(?array $level, int $waiting = 0, ?int $jackBack = null): array
{
    $waiting = max(0, $waiting);
    if ($level === null) {
        return ['measured' => false, 'x' => 0, 'size' => 0, 'used_pct' => 0.0, 'need' => 0, 'jack_back' => $jackBack, 'others_back' => 0,
                'jack_fetch' => 0, 'wait' => $waiting, 'over' => false, 'why' => null, 'never' => false, 'before' => null, 'forecast' => null];
    }
    $size = (int) $level['size'];
    $used = (int) $level['used'];
    $xb = (int) $level['x'] > 0 ? intdiv((int) $level['x'] * $size, 100) : 0;
    $back = max(0, min((int) ($jackBack ?? 0), (int) $level['jack']));
    $after = $used - $back;
    $others = $xb > 0 ? min((int) $level['others'], max(0, $after - $xb)) : 0;
    $after -= $others;
    $fetch = $xb > 0 ? min($waiting, max(0, $xb - $after)) : $waiting;
    $over = $xb > 0 && $after > $xb;            // at X is no miss (Jack Emby fills up to X exactly)
    $before = nightPicture($level);
    $forecast = ['size' => $size, 'fixed' => $before['fixed'], 'others' => $before['others'] - $others, 'jack' => $before['jack'] - $back + $fetch,
                 'free' => max(0, $size - $after - $fetch)];
    return ['measured' => true, 'x' => (int) $level['x'], 'size' => $size, 'used_pct' => (float) $level['used_pct'], 'need' => (int) $level['need'],
            'jack_back' => $jackBack, 'others_back' => $others, 'jack_fetch' => $fetch, 'wait' => $waiting - $fetch, 'over' => $over,
            'why' => !$over ? null : ((bool) $level['never'] ? 'never' : 'jack'), 'never' => (bool) $level['never'], 'before' => $before, 'forecast' => $forecast];
}

/** The «Tonight: …» line's params for the pages (state, not texts): what the others give, what Jack Emby fetches, what waits */
function nightWords(array $plan): array
{
    return ['measured' => (bool) $plan['measured'], 'back' => $plan['jack_back'], 'goes' => (int) $plan['others_back'],
            'fill' => (int) $plan['jack_fetch'], 'wait' => (int) $plan['wait'], 'over' => (bool) $plan['over'], 'why' => $plan['why']];
}

/** One phase of the picture: the pool now, the plan's forecast and its planned bytes */
function nightPhasePicture(array $plan): array
{
    return ['before' => $plan['before'], 'forecast' => $plan['forecast'],
            'planned' => ['jack_back' => $plan['jack_back'], 'others_back' => (int) $plan['others_back'], 'jack_fetch' => (int) $plan['jack_fetch']]];
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
    if ($to !== 'array' && preg_match('/^[\w.\-]{1,64}$/D', $to) && !poolsBySleep([$to], $h['asleep'])['asleep']) {
        $look = poolLevelLook($to, [], $h['zfs']);
        [$size, $used] = $look !== null ? [$look['size'], $look['used']] : [null, null];
    }
    return ['to' => $to, 'size' => $size, 'used' => $used];
}

/**
 * Where the night reads and writes, whom it calls — moverelliHost() plus the night's own (the tests: $GLOBALS['nightHost']).
 */
function nightHost(?array $h = null): array
{
    $h ??= moverelliHost();
    $n = $GLOBALS['nightHost'] ?? [];
    return $h + [
        // Jack Emby takes part: hired and set up
        'jack_on'     => $n['jack_on'] ?? fn (): bool => function_exists('embyReadSettings') && ($h['emby'])() !== null && embyReadSettings() !== null,
        'emby_job'    => $n['emby_job'] ?? fn (array $args): int => embyJob('embycache', $args),
        'her_job'     => $n['her_job'] ?? fn (array $args, array $hh): int => moverelliJob($args, $hh),
        'emby_status' => $n['emby_status'] ?? fn (): array => (array) (readJson(EMBY_DATA . '/status.json') ?? []),
        'emby_runs'   => $n['emby_runs'] ?? $h['emby_runs'],
        'emby_stop'   => $n['emby_stop'] ?? (defined('EMBY_DATA') ? EMBY_DATA . '/office-stop.json' : ''),
        // EmbyCache's live progress (RAM): the files his step did (`recent`) for the picture
        'emby_live'   => $n['emby_live'] ?? fn (): array => function_exists('embyProgressPaths') ? (array) (readJson(embyProgressPaths()['file']) ?? []) : [],
        'notify'      => $n['notify'] ?? fn (string $subject, string $body): bool => officeNotify($subject, $body, 'warning', '', officeNotifyLink('#/moverelli')),
        'rounds'      => $n['rounds'] ?? NIGHT_ROUNDS_MAX,
        // Jack Emby's pool measured for her rules (the tests: a pool of their own)
        'level'       => $n['level'] ?? fn (array $g, array $hh, bool $wake): ?array => nightLevel($g, $hh, $wake),
    ];
}

/** Jack Emby's pool measured for her rule (null: no limit, no pool of his, not ZFS, asleep and not to be woken) */
function nightLevel(array $g, array $h, bool $wake): ?array
{
    $pool = moverelliJackPool($h);
    if ($pool === null || (int) $g['move_when_used_above'] <= 0) {
        return null;
    }
    return poolLevel($pool, ['x' => (int) $g['move_when_used_above'], 'shares_dir' => $h['shares_dir'], 'names' => moverelliShareNames($h['shares_dir'], $h),
        'zfs' => $h['zfs'], 'mnt' => $h['mnt'], 'jack_list' => $h['jack_list_file'], 'asleep' => $h['asleep'], 'wake' => $wake]);
}

/** What waits with Jack Emby (his last run's status, bytes) */
function nightWaiting(array $h): int
{
    $p = (array) (($h['emby_status'])()['pool'] ?? []);
    return max(0, (int) ($p['waiting_bytes'] ?? 0));
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
    $tool = ($n['who'] ?? '') === 'jack' ? (string) $h['emby_stop'] : "$dir/office-stop.json";
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
    $refused = function (string $why, array $params = []) use ($mode, $by, $dir): int {
        moverelliRemember(['mode' => $mode, 'by' => $by, 'night' => true, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $why]
            + array_intersect_key($params, ['schedule' => 1, 'shares' => 1, 'version' => 1, 'file' => 1]), $dir);
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
    if ($mode === 'run') {
        // a scheduled night waits for Unraid's mover (as her scheduled runs always did); from a page: refused at once
        $gate = embyRunGate('moverelli', $by, ['watch' => false, 'dir' => $dir, 'waitdir' => $h['waitdir'],
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
    $movers = embyMoversLock((int) $h['lock_wait']);
    $hold = null;
    if ($movers !== null && !($h['jack_busy'])() && !moverelliBusy($dir)) {
        $hold = @fopen("$dir/night.lock", 'c');
        if ($hold && !flock($hold, LOCK_EX | LOCK_NB)) {
            fclose($hold);
            $hold = null;
        }
    }
    embyMoversUnlock($movers);
    embyWaitEnd($wait, $h['waitdir'], 'moverelli');
    if ($hold === null) {
        return $refused($movers === null || ($h['jack_busy'])() ? 'moverelli_emby_running' : 'moverelli_running');
    }
    nightInside(true);
    @unlink("$dir/night-stop.json");
    try {
        return nightSteps($mode, $by, $waited, $h);
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
    $c = (array) ((($h['emby_status'])())['cleanup'] ?? []);
    return max(0, (int) ($c[$real ? 'bytes_done' : 'bytes_planned'] ?? 0));
}

/** What Jack Emby's dry step would fetch (his status) */
function nightDryFetch(array $h): int
{
    return max(0, (int) (((array) ((($h['emby_status'])())['fill'] ?? []))['bytes_planned'] ?? 0));
}

/** The steps of a night (inside its lock) — see nightRun() */
function nightSteps(string $mode, string $by, int $waited, array $h): int
{
    $dir = $h['dir'];
    $real = $mode === 'run';
    $g = (moverelliSettings($dir) ?? moverelliDefaults())['global'];
    $jack = (bool) ($h['jack_on'])();
    $level = $jack ? ($h['level'])($g, $h, $real) : null;
    $plan = nightPlan($level, $jack ? nightWaiting($h) : 0);
    $pool = $level['pool'] ?? ($jack ? moverelliJackPool($h) : null);
    $n = ['v' => 1, 'mode' => $mode, 'by' => $by, 'pid' => getmypid(), 'started' => time(), 'phase' => 'check', 'round' => 1, 'who' => null,
          'jack' => $jack, 'pool' => $pool, 'plan' => nightWords($plan) + ['used_pct' => $plan['used_pct'], 'x' => $plan['x']],
          'picture' => ['pool' => $pool, 'x' => $plan['x'], 'dest' => $plan['measured'] ? nightDest($pool, $h) : null,
                        'phases' => ['check' => nightPhasePicture($plan)], 'files' => []],
          'steps' => [], 'result' => null] + ($waited > 0 ? ['waited' => $waited] : []);
    nightWrite($dir, $n);
    logLine("Night run ($mode): " . ($jack ? 'Jack Emby and Ms. Moverelli' : 'Ms. Moverelli alone') . ($plan['measured']
        ? " — pool $pool at {$plan['used_pct']} %, X {$plan['x']} %: Jack Emby gives back what was watched, the others about "
          . poolLevelSize($plan['others_back']) . ' (and the age rule), Jack Emby fetches up to ' . poolLevelSize($plan['jack_fetch'])
        : ''));
    $stopped = fn (): bool => is_file("$dir/night-stop.json");
    // the picture: a phase as it begins (the pool measured anew where the step needs it)
    $phase = function (string $ph, array $p) use (&$n): void {
        if ($p['measured']) {
            $n['picture']['phases'][$ph] = nightPhasePicture($p);
        }
    };
    // one step: a tool called with its part; false = the night ends here (stopped, refused)
    $step = function (string $who, string $phase) use (&$n, $h, $dir, $mode, $stopped): bool {
        if ($stopped()) {
            $n['result'] = 'stopped';
            $n['stopped_in'] = $phase;
            return false;
        }
        $n['phase'] = $phase;
        $n['who'] = $who;
        nightWrite($dir, $n);
        if ($who === 'jack') {
            $args = [$mode, '--night'];
            if ($phase !== 'fill' && $mode === 'run') {
                $args[] = '--nofill';
            }
            $exit = ($h['emby_job'])($args);
            $last = ($h['emby_runs'])()[0] ?? [];
            $recent = (($h['emby_live'])())['recent'] ?? [];
        } else {
            $exit = ($h['her_job'])([$mode, '--night'], $h);
            $last = moverelliHistory($dir)[0] ?? [];
            $recent = (readJson("$dir/status.json") ?? [])['recent'] ?? [];
        }
        $n['picture']['files'] = array_slice(array_merge($n['picture']['files'], nightFiles($recent, $who)), -NIGHT_FILES_MAX);
        $result = (string) ($last['result'] ?? ($exit === 0 ? 'ok' : 'failed'));
        $n['steps'][] = ['who' => $who, 'phase' => $phase, 'round' => $n['round'], 'exit' => $exit, 'result' => $result];
        $n['who'] = null;
        nightWrite($dir, $n);
        if ($exit === 3 || $result === 'stopped' || $stopped()) {
            $n['result'] = 'stopped';
            $n['stopped_in'] = $phase;
            return false;
        }
        if (in_array($result, ['refused', 'skipped'], true)) {
            $n['result'] = 'refused';
            $n['refused_by'] = $who;
            return false;
        }
        return true;
    };
    $go = true;
    if (!$jack) {
        // only she: her part (the age rule, from X the oldest until below X)
        $go = $step('her', 'free');
    } elseif (!$plan['measured']) {
        // no limit (or the pool not measured): her part, then Jack Emby's whole run with his own minimum free space
        $go = $step('her', 'free') && ($real ? $step('jack', 'fill') : $step('jack', 'free'));
    } else {
        // free: Jack Emby gives back what was watched, then she moves the others' oldest down to below X
        $go = $step('jack', 'free');
        $back = $go ? nightJackBack($h, $real) : 0;
        // a real run measures the pool anew (his give-back is in it, kept as a number); a dry run reckons it from his dry answer,
        // what his dry step would fetch standing for what waits
        $plan = $real ? ['jack_back' => $back] + nightPlan(($h['level'])($g, $h, true), nightWaiting($h), 0) : nightPlan($level, nightDryFetch($h), $back);
        $phase('free', $plan);
        $n['plan'] = nightWords($plan) + ['used_pct' => $plan['used_pct'], 'x' => $plan['x']];
        $go = $go && $step('her', 'free');
        // recheck: downloads may have come in while they moved — her part again while the pool is at/above X
        for ($round = 2; $go && $real && $round <= (int) $h['rounds']; $round++) {
            $n['phase'] = 'recheck';
            nightWrite($dir, $n);
            $again = nightPlan(($h['level'])($g, $h, true), nightWaiting($h), 0);
            if (!$again['measured'] || $again['need'] <= 0) {
                $plan = $again['measured'] ? $again : $plan;
                break;
            }
            $phase('recheck', $again);
            $plan = $again;
            if ($again['others_back'] <= 0) {
                $n['missed'] = true;      // nothing of the others' left to move: what never moves and Jack Emby's unwatched keep it over X
                logLine('Night run: the pool stays at/above X — nothing of the others\' left to move' . ($again['why'] === 'jack' ? ' (only Jack Emby\'s unwatched)' : ''));
                break;
            }
            $n['round'] = $round;
            logLine("Night run: round $round — the pool grew meanwhile (" . poolLevelSize($again['need']) . ' over X)');
            $go = $step('her', 'free');
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
    // tell: the pool still over X after the night (a real one that ran through)
    $end = $jack && $real ? ($h['level'])($g, $h, false) : null;
    if ($end !== null) {
        $n['end_pct'] = $end['used_pct'];
        $phase('done', nightPlan($end, 0, 0));
    }
    $n['result'] ??= 'ok';
    // over X (Jack Emby fills up to X exactly — at X is no miss)
    if ($go && $end !== null && $end['x'] > 0 && $end['need'] > 0) {
        $n['result'] = 'missed';
        $n['why'] = $end['never'] ? 'never' : ($end['jack_over'] ? 'jack' : null);
        nightMissedTell($end, $h);
    }
    $n['phase'] = 'done';
    $n['who'] = null;
    $n['finished'] = time();
    nightWrite($dir, $n);
    logLine("Night run ($mode): {$n['result']}" . (isset($n['stopped_in']) ? " (in {$n['stopped_in']})" : '') . ($n['round'] > 1 ? ", {$n['round']} rounds" : ''));
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
    $ok = ($h['notify'])(officeNotifyText('moverelli', 'notify.night_subject', $p, $lang), officeNotifyText('moverelli', $key, $p, $lang));
    logLine("Night run: pool {$p['pool']} still at {$p['now']} % (limit {$p['x']} %)" . ($key === 'notify.night_missed_jack' ? ' — only Jack Emby\'s unwatched is over it' : '')
        . ($ok ? " — told Unraid's notifications" : ''));
    return $ok;
}
