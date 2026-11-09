<?php
declare(strict_types=1);

/*
 * Jack Streamer: the plan in PHP (jack-plan.php) in the shadow of Python's MediaCache (2026-11-07, reworked 2026-11-08 after the
 * review). Python keeps moving the files. Asleep unless the flag file JACK_DATA/shadow.json exists (no button, no setting, no
 * migration): without it nothing runs, nothing shows, his state has no key for it, and a real run goes exactly as before (jackJob()
 * asks jackShadowOn() and nothing else). Only on the test servers and, by hand, nostromo. A later, separate step makes PHP live; there
 * is no second flag here. The Team Lead gets no check and no notification from this.
 *
 * When: inside a real run's own job (jackJob(), `embycache run` — also the joint run's `--nofill` step — and `release`), right BEFORE
 * MediaCache starts: after the run's gates passed and with everything the run holds, on the very state the run then acts on, with
 * exactly the run's environment (MEDIACACHE_NO_FILL, _KEEP_BYTES, _WATCHED_ONLY, _GIVE_BACK_FREE, _MIN_FREE_PERCENT, _RELEASE_THEMES …;
 * only the run's own files — result, stop, progress — left out). So it refuses nothing the real run doesn't refuse anyway (the review's
 * S6: nobody else is made to wait for the shadow; a second real run is refused by the first one's marker, shadow or not). It only makes
 * the run start later: at most JACK_SHADOW_SECONDS, and not one moment once the array stops, a stop is asked (his stop file) or
 * Unraid's mover starts — then it gives up at once («not compared»: stopped) and the run goes on as it would have.
 * A disk the plan may need asleep (jackShadowAsleep()): «not compared» (disk_asleep), never a wake.
 *
 * One set of answers: Python's dry twin of the run (mediacache_shadow.py) writes down every API answer, its clock and the pool's numbers
 * (the tape: RAM, 0600, no key on it), and PHP plans from exactly that tape. Both are processes of their own (jackShadowChild()):
 * stopped at once when the array stops, and neither keeps anything of the share open. A different plan is different logic, or a
 * different question (`request`). Files are looked at by both a moment apart: when the first pair differs it is done once more —
 * what shows again is «differ»; what doesn't is kept as `once` (a moment, or a server whose answer changed between the pairs),
 * never thrown away. Both sides failing (crash, config) is «error», never «the same». After the real run: one more plan with PHP's
 * own client against the live servers (`live`, said only), and the real run's own counts beside the plan's (`real`, said only).
 *
 * The result: JACK_DATA/shadow/<date>.json, one per date, kept 60 days; paths and person keys only, never a key: time, version, mode,
 * result (same | differ | once | error | not), why (when not), counts, differences / once [{kind, path, python, php}] (at most
 * JACK_SHADOW_MAX each), more, live, real; another comparison on the same date goes to `earlier` (at most fifty). Written only while the
 * array runs and JACK_DATA is there (never a folder under /mnt made by this). summary.json for his state; his page: a tile only with
 * the flag (desk.js), the differences by the part «shadow».
 */

const JACK_SHADOW_FLAG    = 'shadow.json';
const JACK_SHADOW_DIR     = 'shadow';
const JACK_SHADOW_KEEP    = 60;           // days
const JACK_SHADOW_MAX     = 200;          // differences kept per comparison
const JACK_SHADOW_EARLIER = 50;           // comparisons kept per date beside the last (a day of tests makes many)
const JACK_SHADOW_SECONDS = 900;          // s: the comparison before a real run at most — the run never starts later than that
const JACK_SHADOW_LIVE    = 300;          // s: the live look after the run at most
const JACK_SHADOW_LIST    = 20;           // dates with differences the part «shadow» shows
const JACK_SHADOW_RESULTS = ['same', 'differ', 'once', 'error', 'not'];

/** The flag: a file; absent = asleep. PHP acting (jack-run.php, its flag php.json) wakes it too: then Python plans dry beside PHP */
function jackShadowOn(?string $dir = null): bool
{
    $dir ??= JACK_DATA;
    return is_file("$dir/" . JACK_SHADOW_FLAG) || jackPhpOn($dir);
}

/** Where the comparison looks: the tests' stand-ins in $GLOBALS['jackShadowHost'] */
function jackShadowHost(?array $h = null): array
{
    $h ??= $GLOBALS['jackShadowHost'] ?? [];
    $dir = $h['dir'] ?? JACK_DATA;
    $mover = ['at' => 0, 'on' => false];
    return $h + [
        'dir'    => $dir,
        'run'    => RUN_DIR . '/jack-shadow',
        'array'  => fn (): bool => arrayRunning(),
        // a stop asked (his stop file: the user, the night) or Unraid's mover at work — looked at every 5 s at most
        'stop'   => function () use ($dir, &$mover): bool {
            clearstatcache(true, "$dir/office-stop.json");
            if (is_file("$dir/office-stop.json")) {
                return true;
            }
            if (time() - $mover['at'] >= 5) {
                $mover = ['at' => time(), 'on' => jackMoverRunning(null, getmypid())];
            }
            return $mover['on'];
        },
        'asleep' => fn (): array => jackShadowAsleep(jackReadSettings($dir) ?? []),
        'python' => fn (array $env, string $tape, string $plan, string $logs, string $mode, callable $go): ?int
            => jackShadowChild(['python3', JACK_APP . '/mediacache_shadow.py', '--tape', $tape, '--plan', $plan, '--logs', $logs, '--mode', $mode], $env, "$logs/python-stderr.txt", $go, JACK_APP),
        'php'    => function (array $env, ?string $tape, string $out, string $mode, callable $go, string $plexTv) use (&$h): ?int {
            $run = (string) ($h['run'] ?? RUN_DIR . '/jack-shadow');
            $file = "$run/env-" . ($tape === null ? 'live' : 'tape') . '.json';
            writeAtomic($file, jsonEncode($env), 0600, 0, 0);
            return jackShadowChild(array_merge([PHP_BINARY, __DIR__ . '/jack-plan.php', '--env', $file, '--out', $out, '--mode', $mode, '--plex-tv', $plexTv],
                $tape !== null ? ['--tape', $tape] : []), ['PATH' => (string) ($env['PATH'] ?? '/usr/sbin:/usr/bin:/sbin:/bin')], "$run/php-stderr.txt", $go, '/');
        },
        'now'    => fn (): int => time(),
        'version' => defined('AGENT_VERSION') ? (string) constant('AGENT_VERSION') : '?',
        'plex_tv' => JACK_PLAN_PLEX_TV,
    ];
}

/**
 * A process of the comparison: its exit code, or null when $go() said no meanwhile (looked at once a second) — then it is ended at once
 * (SIGTERM, a second later SIGKILL): nothing waits for it.
 */
function jackShadowChild(array $cmd, array $env, string $err, callable $go, string $cwd): ?int
{
    $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $err, 'w']], $pipes, $cwd, $env);
    if (!is_resource($proc)) {
        return 127;
    }
    for ($look = 0; ($st = proc_get_status($proc))['running']; $look++) {
        if ($look % 5 === 0 && !$go()) {
            proc_terminate($proc);
            for ($i = 0; $i < 10 && proc_get_status($proc)['running']; $i++) {
                usleep(100_000);
            }
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc, 9);
            }
            proc_close($proc);
            return null;
        }
        usleep(200_000);
    }
    proc_close($proc);
    return (int) $st['exitcode'];
}

/**
 * Right before a real run (jackJob()): the comparison, or null without the flag. $mode: run | release; $env: exactly the run's.
 * Never throws: whatever goes wrong here is «not compared», never the run's.
 */
function jackShadowBefore(string $mode, array $env, ?array $h = null): ?array
{
    $h = jackShadowHost($h);
    if (!jackShadowOn((string) $h['dir']) || !in_array($mode, ['run', 'release'], true)) {
        return null;
    }
    $ctx = ['mode' => $mode, 'time' => (int) ($h['now'])(), 'why' => null, 'pass' => null, 'once' => null, 'second' => null];
    try {
        $env = array_diff_key($env, array_flip(['MEDIACACHE_RESULT', 'MEDIACACHE_STOP', 'MEDIACACHE_PROGRESS'])) + ['MEDIACACHE_DIR' => (string) $h['dir']];
        $ctx['env'] = $env;
        $until = hrtime(true) + JACK_SHADOW_SECONDS * 1_000_000_000;
        $go = fn (): bool => ($h['array'])() && !($h['stop'])() && hrtime(true) < $until;
        if (!@is_dir((string) $h['run']) && !@mkdir((string) $h['run'], 0700)) {
            $ctx['why'] = 'stopped';
            return $ctx;
        }
        $asleep = fn (): array => (array) ($h['asleep'])();      // asked again each time: a disk may fall asleep meanwhile
        if ($asleep()) {
            $ctx['why'] = 'disk_asleep';
            return $ctx;
        }
        $why = null;
        $pass = jackShadowPass($h, $env, $mode, $go, $why);
        if ($pass === null || !$pass['differences'] || jackShadowBothFailed($pass)) {
            $ctx['pass'] = $pass;
            $ctx['why'] = $why;
            return $ctx;
        }
        // a difference: the pair once more — the files were looked at twice, a moment apart
        $ctx['once'] = $pass;
        $why = 'disk_asleep';
        $again = $asleep() ? null : jackShadowPass($h, $env, $mode, $go, $why);
        $ctx['second'] = $again === null ? $why : null;
        $ctx['pass'] = $again;
    } catch (Throwable $e) {
        $ctx = ['why' => 'stopped', 'pass' => null, 'once' => null] + $ctx;
        fwrite(STDERR, 'shadow: ' . $e->getMessage() . "\n");
    }
    return $ctx;
}

/**
 * PHP acts (php.json): what the real run in PHP gets from the comparison — the answers its plan was compared on (`--tape`, the last pair
 * that planned on both sides; none when nothing was compared: it asks the servers itself) and where it writes the plan it acted on
 * (`--plan-out`, RAM). The arguments for jack-run.php.
 */
function jackShadowHandoff(?array $ctx, ?array $h = null): array
{
    $h = jackShadowHost($h);
    $run = (string) $h['run'];
    if ($ctx === null || !is_dir($run)) {
        return [];
    }
    $compared = $ctx['pass'] ?? $ctx['once'] ?? null;
    $tape = "$run/tape-run.jsonl";
    return array_merge($compared !== null && is_file($tape) ? ['--tape', $tape] : [], ['--plan-out', "$run/executed.json"]);
}

/**
 * The plan the real run in PHP acted on beside the one compared with Python's (the same answers): everything the plan decides — the
 * fill's own numbers left out (the real run measures the pool again after the cleanup, the dry one counts the cleanup as freed).
 * [result: same | differ | none (no plan written: the run failed early), differences]
 */
function jackShadowExecuted(?array $compared, ?array $executed): array
{
    if ($compared === null || $executed === null) {
        return ['result' => 'none'];
    }
    $strip = function (array $p): array {
        unset($p['fill'], $p['sim_delta'], $p['give_back']['held']);
        return $p;
    };
    $d = jackShadowDiff($strip($compared), $strip($executed));
    return $d ? ['result' => 'differ', 'differences' => array_slice($d, 0, JACK_SHADOW_MAX), 'more' => max(0, count($d) - JACK_SHADOW_MAX)] : ['result' => 'same'];
}

/**
 * After the real run: PHP's own client against the live servers (said only), the run's own counts beside the plan's (said only), the
 * date's file. $status: the run's MEDIACACHE_RESULT. Never throws. Nothing written once the array stopped or his data folder is gone.
 * PHP acted (php.json): `engine` php, and `executed` — the plan it acted on beside the plan compared.
 */
function jackShadowAfter(?array $ctx, array $status, ?array $h = null): void
{
    if ($ctx === null) {
        return;
    }
    $h = jackShadowHost($h);
    $running = fn (): bool => (bool) ($h['array'])();        // asked again each time
    try {
        $entry = jackShadowEntry($h, $ctx);
        if (jackPhpOn((string) $h['dir'])) {
            $entry['engine'] = 'php';
            $compared = $ctx['pass'] ?? $ctx['once'] ?? null;
            if ($compared !== null) {
                $executed = readJson($h['run'] . '/executed.json');
                $entry['executed'] = jackShadowExecuted(is_array($compared['php'] ?? null) ? $compared['php'] : null, $executed);
            }
        }
        if (($entry['result'] ?? '') !== 'not' && $running()) {
            $entry['real'] = jackShadowReal($ctx['pass']['py'] ?? $ctx['once']['py'] ?? [], $status);
            $until = hrtime(true) + JACK_SHADOW_LIVE * 1_000_000_000;
            $out = $h['run'] . '/live.json';
            $env = (array) ($ctx['env'] ?? []);
            $exit = ($h['php'])($env, null, $out, $ctx['mode'], fn (): bool => $running() && hrtime(true) < $until, (string) $h['plex_tv']);
            $live = $exit === 0 ? readJson($out) : null;
            $entry['live'] = is_array($live) ? ['outcome' => (string) ($live['plan']['outcome'] ?? ''), 'asked' => (int) ($live['asked'] ?? 0), 'failed' => (int) ($live['failed'] ?? 0)]
                : ['outcome' => $exit === null ? 'stopped' : 'failed'];
        }
        if ($running() && is_dir((string) $h['dir'])) {
            jackShadowWrite((string) $h['dir'], $entry);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'shadow: ' . $e->getMessage() . "\n");
    } finally {
        foreach (glob($h['run'] . '/*') ?: [] as $f) {
            if (($ctx['why'] ?? null) !== 'python_failed' || !str_ends_with($f, '.txt')) {    // Python's word stays (in RAM) when it is the only word
                @unlink($f);
            }
        }
        @rmdir((string) $h['run']);
    }
}

/** The plan's cleanup and fill beside what the real run counted (MEDIACACHE_RESULT): [plan, real] each — moved files change it */
function jackShadowReal(array $py, array $status): array
{
    $out = [];
    foreach (['cleanup' => count($py['cleanup']['paths'] ?? []), 'fill' => count($py['fill']['rels'] ?? [])] as $k => $planned) {
        if (is_array($status[$k] ?? null)) {
            $out[$k] = [$planned, (int) ($status[$k]['planned'] ?? 0)];
        }
    }
    return $out;
}

/**
 * The disks the plan may need that sleep: his pool, and the array disks of every share a mapping points into — through /mnt/user or
 * /mnt/user0 (a look there may touch every disk of the share; all of them with `array_source: disk`); a mapping onto the pool needs
 * only the pool. Their names, [] when all are awake.
 */
function jackShadowAsleep(array $settings, ?array $asleep = null, ?array $shares = null): array
{
    $asleep ??= sleepingDisks();
    $shares ??= readCfg(WA_SHARES_INI, true);
    $disks = array_values(array_filter(array_map('strval', array_keys($asleep)), fn (string $d): bool => (bool) preg_match('/^disk\d+$/D', $d)));
    $all = ($settings['array_source'] ?? 'user0') === 'disk';
    $mapped = [];
    $maps = (array) ($settings['path_mappings'] ?? []);
    foreach ((array) ($settings['instances'] ?? []) as $inst) {
        $maps = array_merge($maps, (array) (is_array($inst) ? ($inst['path_mappings'] ?? []) : []));
    }
    $roots = [rtrim((string) ($settings['user_path'] ?? '/mnt/user'), '/'), rtrim((string) ($settings['array_path'] ?? '/mnt/user0'), '/')];
    foreach ($maps as $host) {
        $host = is_array($host) ? (string) ($host[1] ?? '') : (string) $host;
        foreach ($roots as $root) {
            if ($root !== '' && str_starts_with($host, "$root/") && ($share = explode('/', substr($host, strlen($root) + 1))[0]) !== '') {
                $mapped[$share] = true;
            }
        }
    }
    $needed = [];
    foreach ($all ? [] : array_map('strval', array_keys($mapped)) as $share) {
        $i = $shares[$share] ?? [];
        if (($i['useCache'] ?? '') === 'only') {
            continue;
        }
        $include = array_filter(explode(',', (string) ($i['include'] ?? '')));
        $exclude = array_filter(explode(',', (string) ($i['exclude'] ?? '')));
        foreach ($disks as $d) {
            if ((!$include || in_array($d, $include, true)) && !in_array($d, $exclude, true)) {
                $needed[$d] = true;
            }
        }
    }
    $out = [];
    foreach ($all ? $disks : array_map('strval', array_keys($needed)) as $d) {
        if ($asleep[$d] ?? false) {
            $out[] = $d;
        }
    }
    sort($out, SORT_NATURAL);
    $pool = basename((string) ($settings['cache_path'] ?? ''));
    if ($pool !== '' && baseAsleep($pool, $asleep)) {
        $out[] = $pool;
    }
    return $out;
}

/** One pair: Python's dry twin with its tape, PHP from the tape (each a process of its own). Null with a code in $why: not compared */
function jackShadowPass(array $h, array $env, string $mode, callable $go, ?string &$why): ?array
{
    $run = (string) $h['run'];
    $tape = "$run/tape.jsonl";
    $plan = "$run/plan.json";
    $out = "$run/php.json";
    @unlink($plan);
    @unlink($out);
    try {
        $exit = ($h['python'])($env, $tape, $plan, $run, $mode, $go);
        if ($exit === null) {
            $why = 'stopped';
            return null;
        }
        $py = readJson($plan);
        if ($exit !== 0 || !is_array($py)) {
            $why = 'python_failed';
            return null;
        }
        if (($py['outcome'] ?? '') === 'busy') {
            $why = 'busy';
            return null;
        }
        $exit = ($h['php'])($env, $tape, $out, $mode, $go, (string) $h['plex_tv']);
        if ($exit === null) {
            $why = 'stopped';
            return null;
        }
        $php = readJson($out);
        if ($exit !== 0 || !is_array($php) || !is_array($php['plan'] ?? null)) {
            $why = 'php_failed';
            return null;
        }
        // PHP acts (php.json): this pair's answers are the ones the real run plans from — the plan compared is the plan executed
        if (jackPhpOn((string) $h['dir']) && is_file($tape)) {
            @rename($tape, "$run/tape-run.jsonl");
        }
        return ['py' => $py, 'php' => $php['plan'], 'outcome' => [$py['outcome'] ?? '', $php['plan']['outcome'] ?? ''], 'requests' => (int) ($php['asked'] ?? 0),
                'differences' => jackShadowDiff($py, $php['plan'], (array) ($php['missing'] ?? []), (array) ($php['left'] ?? []))];
    } finally {
        @unlink($tape);
        @unlink($plan);
        @unlink($out);
    }
}

/** Both sides ended the same way, and not well (crash, config): nothing was compared */
function jackShadowBothFailed(array $pass): bool
{
    [$po, $qo] = $pass['outcome'];
    return $po !== 'ok' && $po === $qo;
}

/** What a comparison came to: result same | differ | once | error | not */
function jackShadowEntry(array $h, array $ctx): array
{
    $e = ['time' => (int) ($ctx['time'] ?? ($h['now'])()), 'version' => $h['version'], 'source' => JACK_PLAN_SOURCE, 'mode' => (string) ($ctx['mode'] ?? 'run')];
    $pass = $ctx['pass'] ?? null;
    $once = $ctx['once'] ?? null;
    $cut = fn (array $d): array => array_slice($d, 0, JACK_SHADOW_MAX);
    if ($once !== null) {
        // the first pair differed: what the second shows again is «differ», what it doesn't stays as «once»
        $base = $pass ?? $once;
        $e += ['counts' => jackShadowCounts($base['py'], (int) $base['requests'])];
        $again = $pass !== null ? $pass['differences'] : [];
        $first = array_values(array_filter($once['differences'], fn (array $d): bool => !in_array($d, $again, true)));
        if ($again) {
            $e += ['result' => 'differ', 'differences' => $cut($again), 'more' => max(0, count($again) - JACK_SHADOW_MAX)];
        } else {
            $e += ['result' => 'once'] + ($ctx['second'] !== null ? ['second' => $ctx['second']] : []);
        }
        return $e + ($first ? ['once' => $cut($first)] : []);
    }
    if ($pass === null) {
        return $e + ['result' => 'not', 'why' => (string) ($ctx['why'] ?? 'stopped')];
    }
    $e += ['counts' => jackShadowCounts($pass['py'], (int) $pass['requests'])];
    if (jackShadowBothFailed($pass)) {
        return $e + ['result' => 'error', 'outcome' => ['python' => $pass['outcome'][0], 'php' => $pass['outcome'][1]]];      // nothing was compared
    }
    return $e + ($pass['differences'] ? ['result' => 'differ', 'differences' => $cut($pass['differences']), 'more' => max(0, count($pass['differences']) - JACK_SHADOW_MAX)]
        : ['result' => 'same']);
}

/** The date's file, the old ones gone after 60 days, the summary anew. Only into JACK_DATA as it is: its folder made without `recursive` */
function jackShadowWrite(string $dir, array $entry): void
{
    $folder = $dir . '/' . JACK_SHADOW_DIR;
    if (!is_dir($dir) || (!is_dir($folder) && !@mkdir($folder, 0700))) {
        return;
    }
    $date = date('Y-m-d', (int) $entry['time']);
    $file = "$folder/$date.json";
    $old = readJson($file);
    if (is_array($old) && ($old['date'] ?? '') === $date) {
        $earlier = array_values(array_filter((array) ($old['earlier'] ?? []), 'is_array'));
        unset($old['earlier'], $old['v'], $old['date']);
        $earlier[] = $old;
        $entry['earlier'] = array_slice($earlier, -JACK_SHADOW_EARLIER);
    }
    writeAtomic($file, jsonEncode(['v' => 2, 'date' => $date] + $entry), 0600, 0, 0);
    foreach (glob("$folder/????-??-??.json") ?: [] as $f) {
        $d = strtotime(basename($f, '.json') . ' 12:00:00');
        if ($d !== false && $d < (int) $entry['time'] - JACK_SHADOW_KEEP * 86400) {
            @unlink($f);
        }
    }
    writeAtomic("$folder/summary.json", jsonEncode(jackShadowCount($folder)), 0600, 0, 0);
}

/** One result of a comparison (also an older file's: v 1 had compared/same) */
function jackShadowResult(array $e): string
{
    if (in_array($e['result'] ?? null, JACK_SHADOW_RESULTS, true)) {
        return $e['result'];
    }
    return empty($e['compared']) ? 'not' : (empty($e['same']) ? 'differ' : 'same');
}

/** A date counts once, by its worst comparison: differ, error, once, same, not */
function jackShadowCount(string $folder): array
{
    $n = array_fill_keys(JACK_SHADOW_RESULTS, 0);
    $last = null;
    foreach (glob("$folder/????-??-??.json") ?: [] as $f) {
        $d = readJson($f);
        if (!is_array($d) || !in_array($d['v'] ?? null, [1, 2], true)) {
            continue;
        }
        $results = array_map('jackShadowResult', array_merge([$d], array_values(array_filter((array) ($d['earlier'] ?? []), 'is_array'))));
        foreach (['differ', 'error', 'once', 'same', 'not'] as $r) {
            if (in_array($r, $results, true)) {
                $n[$r]++;
                break;
            }
        }
        $last = max($last ?? 0, (int) ($d['time'] ?? 0));
    }
    return $n + ['last' => $last];
}

/** For his state: null without the flag (the key stays away), one small file with it */
function jackShadowSummary(?string $dir = null): ?array
{
    $dir ??= JACK_DATA;
    if (!jackShadowOn($dir)) {
        return null;
    }
    $s = readJson("$dir/" . JACK_SHADOW_DIR . '/summary.json');
    $out = [];
    foreach (JACK_SHADOW_RESULTS as $r) {
        $out[$r] = (int) ($s[$r] ?? 0);
    }
    return $out + ['last' => isset($s['last']) ? (int) $s['last'] : null, 'php' => jackPhpOn($dir)];
}

// ===================================================================== the comparison

/**
 * What differs between two lists: an entry one side has, or, when both have the same, the first place where their order differs.
 *
 * @return list<array{kind: string, path: string, python: mixed, php: mixed}>
 */
function jackShadowListDiff(string $kind, array $py, array $php): array
{
    $out = [];
    $inPy = array_flip($py);
    $inPhp = array_flip($php);
    foreach ($py as $x) {
        if (!isset($inPhp[$x])) {
            $out[] = ['kind' => $kind, 'path' => (string) $x, 'python' => true, 'php' => false];
        }
    }
    foreach ($php as $x) {
        if (!isset($inPy[$x])) {
            $out[] = ['kind' => $kind, 'path' => (string) $x, 'python' => false, 'php' => true];
        }
    }
    if (!$out && count($py) !== count($php)) {
        $out[] = ['kind' => "$kind.count", 'path' => '', 'python' => count($py), 'php' => count($php)];      // the same entries, some twice
    } elseif (!$out && $py !== $php) {
        foreach ($py as $i => $x) {
            if ($php[$i] !== $x) {
                $out[] = ['kind' => "$kind.order", 'path' => (string) $x, 'python' => $i, 'php' => $inPhp[$x]];
                break;
            }
        }
    }
    return $out;
}

/**
 * The two plans, entry by entry: stable, no time, only what differs. Both failed the same way: no entry here — jackShadowEntry() says «error».
 * $asked: from the tape, what PHP asked beyond it ($missing) and what Python asked and PHP didn't ($left).
 *
 * @return list<array{kind: string, path: string, python: mixed, php: mixed}>
 */
function jackShadowDiff(array $py, array $php, array $missing = [], array $left = []): array
{
    $out = [];
    $add = function (string $kind, string $path, mixed $p, mixed $q) use (&$out): void {
        $out[] = ['kind' => $kind, 'path' => $path, 'python' => $p, 'php' => $q];
    };
    foreach ($left as $url => $n) {
        $add('request', (string) $url, $n, 0);
    }
    foreach ($missing as $url => $n) {
        $add('request', (string) $url, 0, $n);
    }
    if (($py['outcome'] ?? null) !== ($php['outcome'] ?? null)) {
        $add('outcome', '', $py['outcome'] ?? null, $php['outcome'] ?? null);
        return $out;
    }
    if (($py['outcome'] ?? null) !== 'ok') {
        return $out;
    }
    foreach (['incomplete', 'errors', 'sessions', 'not_in_budget', 'sim_delta'] as $k) {
        if (($py[$k] ?? null) !== ($php[$k] ?? null)) {
            $add('run', $k, $py[$k] ?? null, $php[$k] ?? null);
        }
    }
    foreach ([['give_back', ['files', 'bytes', 'held', 'keep', 'items']], ['cleanup', ['bytes']], ['fill', ['bytes', 'waiting', 'waiting_bytes']]] as [$part, $keys]) {
        foreach ($keys as $k) {
            if (($py[$part][$k] ?? null) !== ($php[$part][$k] ?? null)) {
                $add('run', "$part.$k", $py[$part][$k] ?? null, $php[$part][$k] ?? null);
            }
        }
    }
    foreach ([['give_back', $py['give_back']['paths'] ?? [], $php['give_back']['paths'] ?? []], ['cleanup', $py['cleanup']['paths'] ?? [], $php['cleanup']['paths'] ?? []],
              ['protected', $py['protected'] ?? [], $php['protected'] ?? []], ['fill', $py['fill']['rels'] ?? [], $php['fill']['rels'] ?? []],
              ['dropped', $py['fill']['dropped'] ?? [], $php['fill']['dropped'] ?? []], ['give_back_order', $py['give_back_order'] ?? [], $php['give_back_order'] ?? []]] as [$kind, $a, $b]) {
        array_push($out, ...jackShadowListDiff($kind, $a, $b));
    }
    // the plan itself: every file, and the order of the fill
    $rels = fn (array $d): array => array_map(fn (array $f): string => (string) $f['rel'], $d['on_deck'] ?? []);
    array_push($out, ...jackShadowListDiff('on_deck', $rels($py), $rels($php)));
    $byRel = [];
    foreach ($php['on_deck'] ?? [] as $f) {
        $byRel['f' . $f['rel']] = $f;
    }
    foreach ($py['on_deck'] ?? [] as $f) {
        $o = $byRel['f' . $f['rel']] ?? null;
        if ($o === null) {
            continue;
        }
        $p = $q = [];
        foreach ($f as $k => $v) {
            if ($v !== ($o[$k] ?? null)) {
                $p[$k] = $v;
                $q[$k] = $o[$k] ?? null;
            }
        }
        if ($p) {
            $add('file', (string) $f['rel'], $p, $q);
        }
    }
    // each person: their numbers, what is selected and what is left out, in order
    $keys = fn (array $d): array => array_map(fn (array $u): string => (string) $u['key'], $d['persons'] ?? []);
    array_push($out, ...jackShadowListDiff('person', $keys($py), $keys($php)));
    $persons = [];
    foreach ($php['persons'] ?? [] as $u) {
        $persons['k' . $u['key']] = $u;
    }
    $firstFile = fn (array $g): string => (string) ($g['files'][0] ?? $g['chain'] ?? '');
    foreach ($py['persons'] ?? [] as $u) {
        $o = $persons['k' . $u['key']] ?? null;
        if ($o === null) {
            continue;
        }
        foreach (['name', 'uids', 'servers', 'budget', 'bytes', 'movie_bytes', 'series_bytes', 'exhausted'] as $k) {
            if (($u[$k] ?? null) !== ($o[$k] ?? null)) {
                $add('person', "{$u['key']}.$k", $u[$k] ?? null, $o[$k] ?? null);
            }
        }
        foreach (['selected', 'skipped'] as $k) {
            $a = array_map($firstFile, $u[$k] ?? []);
            $b = array_map($firstFile, $o[$k] ?? []);
            array_push($out, ...jackShadowListDiff($k, $a, $b));
            if ($a === $b && ($u[$k] ?? []) !== ($o[$k] ?? [])) {
                foreach ($u[$k] ?? [] as $i => $g) {
                    if ($g !== ($o[$k][$i] ?? null)) {
                        $add("$k.group", $firstFile($g), $g, $o[$k][$i] ?? null);
                    }
                }
            }
        }
    }
    return $out;
}

/** What a comparison counts */
function jackShadowCounts(array $py, int $requests): array
{
    $deck = $py['on_deck'] ?? [];
    return ['on_deck' => count($deck), 'on_deck_bytes' => array_sum(array_map(fn (array $f): int => (int) $f['size'], $deck)),
            'on_cache' => count(array_filter($deck, fn (array $f): bool => !empty($f['on_cache']))), 'persons' => count($py['persons'] ?? []),
            'fill' => count($py['fill']['rels'] ?? []), 'waiting' => (int) ($py['fill']['waiting'] ?? 0), 'cleanup' => count($py['cleanup']['paths'] ?? []),
            'protected' => count($py['protected'] ?? []), 'give_back' => count($py['give_back']['paths'] ?? []), 'requests' => $requests];
}

/** What one side planned, as the page shows it */
function jackShadowWord(mixed $v): string
{
    return is_string($v) ? $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** The part «shadow»: the last nights with differences, newest first; each only what the page shows */
function jackShadowList(?string $dir = null): array
{
    $dir ??= JACK_DATA;
    if (!jackShadowOn($dir)) {
        return ['ok' => true, 'state' => null];
    }
    $nights = [];
    $files = glob("$dir/" . JACK_SHADOW_DIR . '/????-??-??.json') ?: [];
    rsort($files, SORT_STRING);
    foreach ($files as $f) {
        $n = readJson($f);
        if (!is_array($n) || !in_array($n['v'] ?? null, [1, 2], true)) {
            continue;
        }
        foreach (array_merge([$n], array_reverse(array_values(array_filter((array) ($n['earlier'] ?? []), 'is_array')))) as $e) {
            $result = jackShadowResult($e);
            if (!in_array($result, ['differ', 'once', 'error'], true)) {
                continue;
            }
            $rows = $result === 'error' ? [['kind' => 'outcome', 'path' => '', 'python' => $e['outcome']['python'] ?? '', 'php' => $e['outcome']['php'] ?? '']]
                : array_merge((array) ($e['differences'] ?? []), (array) ($e['once'] ?? []));
            $nights[] = ['time' => (int) ($e['time'] ?? 0), 'result' => $result, 'mode' => (string) ($e['mode'] ?? 'run'), 'more' => (int) ($e['more'] ?? 0),
                'differences' => array_map(fn (array $d): array => ['kind' => (string) ($d['kind'] ?? ''), 'path' => (string) ($d['path'] ?? ''),
                    'python' => jackShadowWord($d['python'] ?? null), 'php' => jackShadowWord($d['php'] ?? null)], array_values(array_filter($rows, 'is_array')))];
            if (count($nights) >= JACK_SHADOW_LIST) {
                break 2;
            }
        }
    }
    return ['ok' => true, 'state' => ['nights' => $nights, 'summary' => jackShadowSummary($dir)]];
}
