<?php
declare(strict_types=1);

/*
 * The night run (2026-10-13, the owner's decision): Jack Emby and Ms. Moverelli work hand in hand as ONE run on ONE
 * schedule (the job `moverelli`, her cron line), serially:
 *
 *   check     the pool as it stands (poolLevel(): used, what never moves, Jack Emby's bytes J, the downloads D, H)
 *             and what waits with Jack Emby (his last status)
 *   reckon    nightPlan(): need = used − Y; Jack Emby gives back only what he borrowed (over Y, never below H);
 *             Ms. Moverelli moves the age rule always and, from X on, only what the downloads borrowed over their half
 *   free      who must free more goes first, then the other — each only their part, one tool call each
 *             (EmbyCache with EMBYCACHE_NO_FILL=1; Smart Mover with CUSTOMMOVER_FILL_MAX_BYTES = her part)
 *   recheck   the pool measured anew (downloads, appdata may have grown meanwhile): still more than «nearly» over Y →
 *             reckon and free again, only the difference — at most NIGHT_ROUNDS_MAX rounds, never ping-pong
 *   fill      Jack Emby fetches what waits, round-robin per person, up to Y (always last)
 *   tell      the pool still at/above X: «target missed» at once
 *
 * Helmi's tools are called unchanged otherwise; each keeps its own status, history and live panel. One lock: the
 * night's (night.lock in her data folder) — while it is held neither desk starts a run of its own; the runs inside
 * the night pass (nightInside()). Only she hired (Jack Emby not there): her part alone, as 1.61. No limit (X = 0):
 * her part, then Jack Emby's whole run with his own minimum free space. State: night.json (the last night).
 */

const NIGHT_ROUNDS_MAX = 3;          // free + at most two more rounds after a recheck
const NIGHT_NEAR_MIN   = 1 << 30;    // «nearly reached»: at most 1 % of the pool, at least 1 GiB over Y
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

/** «Nearly reached»: what may stay over Y after a round — 1 % of the pool, at least 1 GiB */
function nightNear(int $size): int
{
    return max(intdiv(max(0, $size), 100), NIGHT_NEAR_MIN);
}

/**
 * The plan of a night (pure). $level: poolLevel() of Jack Emby's pool (null: not measured — then only her part, no
 * numbers); $waiting: what waits with Jack Emby (his last status, bytes); $age: what her age rule moves (bytes, 0:
 * unknown). {measured, need, back, goes, age, fill, wait, order: ['jack','her'] | ['her','jack'], near, done (need ≤
 * near), never, x, y, used_pct, half, half_d}
 */
function nightPlan(?array $level, int $waiting = 0, int $age = 0): array
{
    $waiting = max(0, $waiting);
    $age = max(0, $age);
    if ($level === null) {
        return ['measured' => false, 'need' => 0, 'back' => 0, 'goes' => 0, 'age' => $age, 'fill' => 0, 'wait' => $waiting,
                'order' => ['her', 'jack'], 'near' => 0, 'done' => true, 'never' => false, 'x' => 0, 'y' => 0, 'used_pct' => 0.0,
                'half' => 0, 'half_d' => 0, 'size' => 0];
    }
    $size = (int) $level['size'];
    $yb = (int) $level['y'] > 0 ? intdiv((int) $level['y'] * $size, 100) : 0;
    $need = (int) ($level['need'] ?? 0);
    $back = (int) $level['back'];
    $goes = (int) $level['goes'];
    // who must free more goes first; the age rule counts on her side
    $order = $back >= $goes + $age ? ['jack', 'her'] : ['her', 'jack'];
    $after = max(0, (int) $level['used'] - $back - $goes - $age);
    $fill = $yb > 0 ? min($waiting, max(0, $yb - $after)) : $waiting;
    $near = nightNear($size);
    return ['measured' => true, 'need' => $need, 'back' => $back, 'goes' => $goes, 'age' => $age, 'fill' => $fill,
            'wait' => $waiting - $fill, 'order' => $order, 'near' => $near, 'done' => $need <= $near, 'never' => (bool) $level['never'],
            'x' => (int) $level['x'], 'y' => (int) $level['y'], 'used_pct' => (float) $level['used_pct'],
            'half' => (int) $level['half'], 'half_d' => (int) ($level['half_d'] ?? $level['half']), 'size' => $size];
}

/** The «Tonight: …» line's params for the pages (state, not texts): what each gives back, moves, fetches, what waits */
function nightWords(array $plan): array
{
    return ['measured' => (bool) $plan['measured'], 'back' => (int) $plan['back'], 'goes' => (int) $plan['goes'], 'age' => (int) $plan['age'],
            'fill' => (int) $plan['fill'], 'wait' => (int) $plan['wait'], 'first' => $plan['order'][0] ?? 'her'];
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
        'notify'      => $n['notify'] ?? fn (string $subject, string $body): bool => officeNotify($subject, $body, 'warning', '', officeNotifyLink('#/moverelli')),
        'rounds'      => $n['rounds'] ?? NIGHT_ROUNDS_MAX,
        // Jack Emby's pool measured for her rules (the tests: a pool of their own)
        'level'       => $n['level'] ?? fn (array $g, array $hh, bool $wake): ?array => nightLevel($g, $hh, $wake),
    ];
}

/** Jack Emby's pool measured for her rules (null: no limit, no pool of his, not ZFS, asleep and not to be woken) */
function nightLevel(array $g, array $h, bool $wake): ?array
{
    $pool = moverelliJackPool($h);
    if ($pool === null || (int) $g['move_when_used_above'] <= 0) {
        return null;
    }
    return poolLevel($pool, ['x' => (int) $g['move_when_used_above'], 'y' => (int) ($g['move_until_used_below'] ?? 0),
        'k' => (int) ($g['jack_min_percent'] ?? POOL_JACK_DEFAULT), 'shares_dir' => $h['shares_dir'], 'names' => moverelliShareNames($h['shares_dir'], $h),
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
          'jack' => $jack, 'pool' => $pool, 'plan' => nightWords($plan) + ['used_pct' => $plan['used_pct'], 'x' => $plan['x'], 'y' => $plan['y']],
          'steps' => [], 'result' => null] + ($waited > 0 ? ['waited' => $waited] : []);
    nightWrite($dir, $n);
    logLine("Night run ($mode): " . ($jack ? 'Jack Emby and Ms. Moverelli' : 'Ms. Moverelli alone') . ($plan['measured']
        ? " — pool $pool at {$plan['used_pct']} %, Y {$plan['y']} %: Jack Emby gives back " . poolLevelSize($plan['back'])
          . ', the downloads ' . poolLevelSize($plan['goes']) . ' (and the age rule), Jack Emby fetches up to ' . poolLevelSize($plan['fill'])
        : ''));
    $stopped = fn (): bool => is_file("$dir/night-stop.json");
    // one step: a tool called with its part; false = the night ends here (stopped, refused)
    $step = function (string $who, string $phase, array $p) use (&$n, $h, $dir, $mode, $pool, $stopped): bool {
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
        } else {
            $args = [$mode, '--night'];
            if ($p !== []) {
                $args[] = '--fill-max=' . max(0, (int) $p['goes']);
                if ($pool !== null) {
                    $args[] = '--fill-pool=' . $pool;
                }
            }
            $exit = ($h['her_job'])($args, $h);
            $last = moverelliHistory($dir)[0] ?? [];
        }
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
        // only she: her part as 1.61 (the age rule, from X the oldest until below Y)
        $go = $step('her', 'free', []);
    } elseif (!$real) {
        // the dry run: both tools dry with the same values — what each would do tonight
        $go = $step('jack', 'free', []) && $step('her', 'free', $plan['measured'] ? $plan : []);
    } elseif (!$plan['measured']) {
        // no limit (or the pool not measured): her part, then Jack Emby's whole run with his own minimum free space
        $go = $step('her', 'free', []) && $step('jack', 'fill', []);
    } else {
        foreach ($plan['order'] as $who) {
            if (!($go = $step($who, 'free', $who === 'her' ? $plan : []))) {
                break;
            }
        }
        // recheck: downloads or appdata may have grown while they freed — reckon and free again, only the difference
        for ($round = 2; $go && $round <= (int) $h['rounds']; $round++) {
            $n['phase'] = 'recheck';
            nightWrite($dir, $n);
            $level = ($h['level'])($g, $h, true);
            $again = nightPlan($level, nightWaiting($h));
            if (!$again['measured'] || $again['done']) {
                break;
            }
            if ($again['back'] + $again['goes'] <= 0) {
                $n['missed'] = true;      // nobody has anything borrowed left to give: the pool stays over Y
                logLine('Night run: the pool stays over Y — neither Jack Emby nor the downloads have anything borrowed left');
                break;
            }
            $n['round'] = $round;
            logLine("Night run: round $round — the pool grew meanwhile (" . poolLevelSize($again['need']) . ' over Y)');
            foreach ($again['order'] as $who) {
                if (($who === 'jack' ? $again['back'] : $again['goes']) <= 0) {
                    continue;
                }
                if (!($go = $step($who, 'free', $who === 'her' ? $again : []))) {
                    break;
                }
            }
        }
        // fill: Jack Emby fetches what waits, always last
        if ($go) {
            $go = $step('jack', 'fill', []);
        }
    }
    // tell: the pool still at/above X after the night (a real one that ran through)
    $end = $jack && $real ? ($h['level'])($g, $h, false) : null;
    if ($end !== null) {
        $n['end_pct'] = $end['used_pct'];
    }
    $n['result'] ??= 'ok';
    if ($go && $end !== null && $end['x'] > 0 && $end['used'] * 100 >= $end['x'] * $end['size']) {
        $n['result'] = 'missed';
        nightMissedTell($end, $h);
    }
    $n['phase'] = 'done';
    $n['who'] = null;
    $n['finished'] = time();
    nightWrite($dir, $n);
    logLine("Night run ($mode): {$n['result']}" . (isset($n['stopped_in']) ? " (in {$n['stopped_in']})" : '') . ($n['round'] > 1 ? ", {$n['round']} rounds" : ''));
    return match ($n['result']) { 'stopped' => 3, 'refused' => 1, default => 0 };
}

/** The pool still at/above X after a night: to Unraid's notifications at once (a warning) */
function nightMissedTell(array $end, array $h): bool
{
    $lang = officeNotifyLang();
    $p = ['pool' => (string) $end['pool'], 'now' => (int) floor($end['used_pct']), 'x' => (int) $end['x'], 'nm' => (int) floor($end['nm_pct'])];
    $ok = ($h['notify'])(officeNotifyText('moverelli', 'notify.night_subject', $p, $lang), officeNotifyText('moverelli', 'notify.night_missed', $p, $lang));
    logLine("Night run: pool {$p['pool']} still at {$p['now']} % (limit {$p['x']} %)" . ($ok ? " — told Unraid's notifications" : ''));
    return $ok;
}
