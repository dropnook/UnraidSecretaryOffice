<?php
declare(strict_types=1);

/*
 * Jack Streamer: the plan in PHP (jack-plan.php) in the shadow of Python's MediaCache (2026-11-07). Python keeps moving the files.
 * Asleep unless the flag file JACK_DATA/shadow.json exists (no button, no setting, no migration): without it nothing runs,
 * nothing shows, his state has no key for it. Only ever on nostromo and the test servers, by hand. A later, separate step makes PHP live;
 * there is no second flag here. The Team Lead gets no check and no notification from this.
 *
 * When: after a real run of MediaCache ended (never its `--nofill` step), jackJob() starts ONE job through atd (`php agent.php job
 * jack-shadow`): never in tick, never the agent's child. The job waits while another tool holds its lock (his, the gather's, Ms.
 * Moverelli's runs and the joint run, the backup engine's) and out of the backup's minute (nightorder.php). A disk the plan may need asleep:
 * «not compared tonight» (`disk_asleep`), never a difference and never a wake — needed are the array disks of his mapped shares and his
 * pool (jackShadowAsleep()); Python's own look at /mnt/disk* goes away inside mediacache_shadow.py where it only names a disk.
 *
 * One set of answers (the guarantee of decision 2): Python's dry run writes down every API answer, its clock and the pool's numbers as it
 * goes (mediacache_shadow.py, the tape, in RAM, 0600, no key on it, removed right after), and PHP plans from exactly that tape,
 * right afterwards, holding MediaCache's lock. So two looks can't differ: a different plan is different logic, or
 * a different question (`request`: a URL one side asked and the other didn't). Files are looked at by both, back to back: if the
 * tape's plans differ, the whole pair is done once more and only what shows again counts (a film that arrived between the two).
 * Then one more plan with PHP's own client against the live servers (`live`): that the client talks to them. It can differ by the moment,
 * so it is only said in the night file (its first three differences with it), never a difference.
 *
 * The result: JACK_DATA/shadow/<date>.json, one per night, small, kept 60 days; paths and person names only, never a key: time, version,
 * compared, why (when not), same, counts, differences [{kind, path, python, php}] (stable: no time in them, at most
 * JACK_SHADOW_MAX), more, live; another comparison on the same date goes to `earlier` (at most ten). summary.json for his
 * state, read with one file. His page: a tile only with the flag (desk.js), the differences by the part «shadow».
 */

const JACK_SHADOW_FLAG    = 'shadow.json';
const JACK_SHADOW_DIR     = 'shadow';
const JACK_SHADOW_JOB     = 'jack-shadow';
const JACK_SHADOW_KEEP    = 60;           // days
const JACK_SHADOW_MAX     = 200;          // differences kept per comparison
const JACK_SHADOW_EARLIER = 10;           // comparisons kept per date beside the last
const JACK_SHADOW_WAIT    = 21600;        // s: how long the job waits for the others' locks: then «not compared tonight»
const JACK_SHADOW_EVERY   = 15;           // s: how often a wait looks again
const JACK_SHADOW_SECONDS = 3600;         // s: Python's dry run at most
const JACK_SHADOW_LIST    = 20;           // nights with differences the part «shadow» shows

/** The flag: a file; absent = asleep */
function jackShadowOn(?string $dir = null): bool
{
    return is_file(($dir ?? JACK_DATA) . '/' . JACK_SHADOW_FLAG);
}

/** Where the job looks: the tests' stand-ins in $GLOBALS['jackShadowHost'] */
function jackShadowHost(?array $h = null): array
{
    $h ??= $GLOBALS['jackShadowHost'] ?? [];
    return $h + [
        'dir'    => JACK_DATA,
        'run'    => RUN_DIR . '/jack-shadow',
        'array'  => fn (): bool => arrayRunning(),
        'busy'   => fn (): ?string => jackShadowBusy(),
        'asleep' => fn (): array => jackShadowAsleep(jackReadSettings() ?? []),
        'env'    => fn (): array => jackShadowEnv(),
        'python' => fn (array $env, string $tape, string $plan, string $logs, callable $go): ?int => jackShadowPython($env, $tape, $plan, $logs, $go),
        'sleep'  => fn (int $s) => sleep($s),
        'now'    => fn (): int => time(),
        'version' => defined('AGENT_VERSION') ? (string) constant('AGENT_VERSION') : '?',
        'plex_tv' => JACK_PLAN_PLEX_TV,
    ];
}

/** Called at the end of a real run (jackJob()). Nothing happens without the flag; a failure here is never the run's */
function jackShadowAfterRun(string $tool, string $mode, int $exit, bool $noFill): void
{
    if ($tool !== 'embycache' || $mode !== 'run' || $noFill || !in_array($exit, [0, 3], true) || !jackShadowOn()) {
        return;
    }
    try {
        hostLaunch(JACK_SHADOW_JOB, [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', JACK_SHADOW_JOB]);
    } catch (Throwable $e) {
        fwrite(STDERR, "shadow: not started: {$e->getMessage()}\n");
    }
}

/** Who holds a lock right now, or the backup's minute: a code, null when nobody */
function jackShadowBusy(): ?string
{
    if (flockHeld(JACK_DATA . '/mediacache.lock') || flockHeld(GATHER_LOCK) || jackJobInfo('embycache')['running'] || jackJobInfo('gather')['running']) {
        return 'jack';
    }
    if (function_exists('moverelliBusy') && moverelliBusy()) {
        return 'moverelli';
    }
    if (backupLockHolder() !== null) {
        return 'backup';
    }
    $cron = officeJobSchedule('backup')['custom'] ?? null;
    $fields = is_string($cron) ? cronFields($cron) : null;
    $now = time();
    if ($fields !== null && (cronMatches($fields, $now) || cronMatches($fields, $now + 60))) {
        return 'backup_minute';
    }
    return null;
}

/**
 * The disks the plan may need that sleep: his pool, and the array disks of his mapped shares (a look through /mnt/user0 may touch
 * every disk of the share; all of them with `array_source: disk`). Their names, [] when all are awake.
 */
function jackShadowAsleep(array $settings, ?array $asleep = null, ?array $shares = null): array
{
    $asleep ??= sleepingDisks();
    $shares ??= readCfg(WA_SHARES_INI, true);
    $disks = array_values(array_filter(array_map('strval', array_keys($asleep)), fn (string $d): bool => (bool) preg_match('/^disk\d+$/D', $d)));
    $needed = [];
    $all = ($settings['array_source'] ?? 'user0') === 'disk';
    foreach ($all ? [] : jackMappedShares($settings) as $share) {
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

/** The environment of a dry run of his (as jackJob() gives it): both sides see the same limits */
function jackShadowEnv(): array
{
    $settings = jackReadSettings();
    return ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8'] + jackPyEnv() + jackPoolEnv($settings);
}

/** Python's dry run with its tape: its exit code, null when it was stopped ($go() false: the array stops, or too long) */
function jackShadowPython(array $env, string $tape, string $plan, string $logs, callable $go): ?int
{
    $proc = proc_open(['python3', JACK_APP . '/mediacache_shadow.py', '--tape', $tape, '--plan', $plan, '--logs', $logs],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$logs/shadow-stderr.txt", 'w']], $pipes, JACK_APP, $env);
    if (!is_resource($proc)) {
        return 127;
    }
    $until = hrtime(true) + JACK_SHADOW_SECONDS * 1_000_000_000;
    while (($st = proc_get_status($proc))['running']) {
        if (hrtime(true) > $until || !$go()) {
            proc_terminate($proc);
            proc_close($proc);
            return null;
        }
        usleep(200_000);
    }
    proc_close($proc);
    return (int) $st['exitcode'];
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
    if (!$out && $py !== $php) {
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
 * The two plans, entry by entry: stable, no time, only what differs. Both a failed outcome the same way: nothing to compare, the same.
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
function jackShadowCounts(array $py, ?JackPlanTape $tape): array
{
    $deck = $py['on_deck'] ?? [];
    return ['on_deck' => count($deck), 'on_deck_bytes' => array_sum(array_map(fn (array $f): int => (int) $f['size'], $deck)),
            'on_cache' => count(array_filter($deck, fn (array $f): bool => !empty($f['on_cache']))), 'persons' => count($py['persons'] ?? []),
            'fill' => count($py['fill']['rels'] ?? []), 'cleanup' => count($py['cleanup']['paths'] ?? []), 'give_back' => count($py['give_back']['paths'] ?? []),
            'requests' => $tape !== null ? $tape->asked : 0];
}

/** One pair: Python's dry run with its tape, PHP from the tape. Null with a code in $why: not compared */
function jackShadowPass(array $h, array $env, ?string &$why): ?array
{
    $run = (string) $h['run'];
    @mkdir($run, 0700, true);
    $tapeFile = "$run/tape.jsonl";
    $planFile = "$run/plan.json";
    @unlink($planFile);
    $until = hrtime(true) + JACK_SHADOW_SECONDS * 1_000_000_000;
    try {
        $go = fn (): bool => ($h['array'])() && hrtime(true) < $until;
        $exit = ($h['python'])($env + ['MEDIACACHE_DIR' => (string) $h['dir']], $tapeFile, $planFile, $run, $go);
        if ($exit === null) {
            $why = 'stopped';
            return null;
        }
        $py = readJson($planFile);
        if ($exit !== 0 || !is_array($py)) {
            $why = 'python_failed';
            return null;
        }
        if (($py['outcome'] ?? '') === 'busy') {
            $why = 'busy';
            return null;
        }
        $tape = JackPlanTape::read($tapeFile);
        if ($tape === null) {
            $why = 'python_failed';
            return null;
        }
        $lock = @fopen($h['dir'] . '/mediacache.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $why = 'busy';
            return null;
        }
        try {
            $php = jackPlanRun($env + ['MEDIACACHE_DIR' => (string) $h['dir']], $tape, (string) $h['plex_tv']);
            $php = json_decode((string) json_encode($php), true);
            $live = new JackPlanLive($env);
            $seen = json_decode((string) json_encode(jackPlanRun($env + ['MEDIACACHE_DIR' => (string) $h['dir']], $live, (string) $h['plex_tv'])), true);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $moved = jackShadowDiff($py, $seen);
        return ['counts' => jackShadowCounts($py, $tape), 'differences' => jackShadowDiff($py, $php, $tape->missing, $tape->left()), 'outcome' => $py['outcome'] ?? '',
                'live' => ['same' => $moved === [], 'asked' => $live->asked, 'failed' => $live->failed] + ($moved ? ['first' => array_slice($moved, 0, 3)] : [])];
    } finally {
        @unlink($tapeFile);
        @unlink($planFile);
    }
}

/**
 * The job: wait for the others, compare, write the night's file. Exit 0 whatever came out; a second job while one goes ends at once.
 */
function jackShadowJob(?array $h = null): int
{
    $h = jackShadowHost($h);
    if (!jackShadowOn((string) $h['dir'])) {
        return 0;
    }
    @mkdir((string) $h['run'], 0700, true);
    $single = @fopen($h['run'] . '/job.lock', 'c');
    if (!$single || !flock($single, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    @ini_set('memory_limit', '1024M');
    $why = jackShadowWait($h);
    $entry = null;
    $env = [];
    if ($why === null) {
        $asleep = ($h['asleep'])();
        if ($asleep) {
            $why = 'disk_asleep';
        } else {
            $env = ($h['env'])();
            $entry = jackShadowPass($h, $env, $why);
        }
    }
    if ($entry !== null && $entry['differences']) {
        // the files were looked at twice, a moment apart: only what shows again counts
        $again = ($h['asleep'])() ? null : jackShadowPass($h, $env, $why);
        $entry = $again === null ? null : $again + ['second_look' => true];
    }
    if ($entry !== null && ($h['busy'])() !== null) {
        $why = 'busy';            // someone started while the plans were made
        $entry = null;
    }
    jackShadowWrite((string) $h['dir'], jackShadowEntry($h, $entry, $why));
    flock($single, LOCK_UN);
    fclose($single);
    if ($why !== 'python_failed') {        // Python's log of the dry run stays in RAM only when it is the only word on what went wrong
        foreach (glob($h['run'] . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir((string) $h['run']);
    }
    return 0;
}

/** Waits while someone holds a lock: null when free, a code when not (the array stopped, too long) */
function jackShadowWait(array $h): ?string
{
    $until = hrtime(true) + JACK_SHADOW_WAIT * 1_000_000_000;
    while (($h['busy'])() !== null) {
        if (!($h['array'])()) {
            return 'stopped';
        }
        if (hrtime(true) > $until) {
            return 'busy';
        }
        ($h['sleep'])(JACK_SHADOW_EVERY);
    }
    return ($h['array'])() ? null : 'stopped';
}

function jackShadowEntry(array $h, ?array $pass, ?string $why): array
{
    $e = ['time' => (int) ($h['now'])(), 'version' => $h['version'], 'source' => JACK_PLAN_SOURCE];
    if ($pass === null) {
        return $e + ['compared' => false, 'why' => $why ?? 'stopped'];
    }
    $found = $pass['differences'];
    return $e + ['compared' => true, 'same' => !$found, 'counts' => $pass['counts'], 'outcome' => $pass['outcome']]
        + ($found ? ['differences' => array_slice($found, 0, JACK_SHADOW_MAX), 'more' => max(0, count($found) - JACK_SHADOW_MAX)] : [])
        + ['live' => $pass['live']] + (isset($pass['second_look']) ? ['second_look' => true] : []);
}

/** The night's file, the old ones gone after 60 days, the summary anew */
function jackShadowWrite(string $dir, array $entry): void
{
    $folder = $dir . '/' . JACK_SHADOW_DIR;
    @mkdir($folder, 0755, true);
    $date = date('Y-m-d', (int) $entry['time']);
    $file = "$folder/$date.json";
    $old = readJson($file);
    if (is_array($old) && ($old['date'] ?? '') === $date) {
        $earlier = array_values(array_filter((array) ($old['earlier'] ?? []), 'is_array'));
        unset($old['earlier'], $old['v'], $old['date']);
        $earlier[] = $old;
        $entry['earlier'] = array_slice($earlier, -JACK_SHADOW_EARLIER);
    }
    writeAtomic($file, jsonEncode(['v' => 1, 'date' => $date] + $entry), 0600, 0, 0);
    foreach (glob("$folder/????-??-??.json") ?: [] as $f) {
        $d = strtotime(basename($f, '.json') . ' 12:00:00');
        if ($d !== false && $d < (int) $entry['time'] - JACK_SHADOW_KEEP * 86400) {
            @unlink($f);
        }
    }
    writeAtomic("$folder/summary.json", jsonEncode(jackShadowCount($folder)), 0600, 0, 0);
}

/** A night counts once: with differences when any comparison of it had some, the same when any was and none had, else not compared */
function jackShadowCount(string $folder): array
{
    $same = $differ = $not = 0;
    $last = null;
    foreach (glob("$folder/????-??-??.json") ?: [] as $f) {
        $n = readJson($f);
        if (!is_array($n) || ($n['v'] ?? null) !== 1) {
            continue;
        }
        $all = array_merge([$n], array_values(array_filter((array) ($n['earlier'] ?? []), 'is_array')));
        $compared = array_filter($all, fn (array $e): bool => !empty($e['compared']));
        if (array_filter($compared, fn (array $e): bool => empty($e['same']))) {
            $differ++;
        } elseif ($compared) {
            $same++;
        } else {
            $not++;
        }
        $last = max($last ?? 0, (int) ($n['time'] ?? 0));
    }
    return ['same' => $same, 'differ' => $differ, 'not' => $not, 'last' => $last];
}

/** For his state: null without the flag (the key stays away), one small file with it */
function jackShadowSummary(?string $dir = null): ?array
{
    $dir ??= JACK_DATA;
    if (!jackShadowOn($dir)) {
        return null;
    }
    $s = readJson("$dir/" . JACK_SHADOW_DIR . '/summary.json');
    return ['same' => (int) ($s['same'] ?? 0), 'differ' => (int) ($s['differ'] ?? 0), 'not' => (int) ($s['not'] ?? 0), 'last' => isset($s['last']) ? (int) $s['last'] : null];
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
        if (!is_array($n) || ($n['v'] ?? null) !== 1) {
            continue;
        }
        foreach (array_merge([$n], array_values(array_filter((array) ($n['earlier'] ?? []), 'is_array'))) as $e) {
            if (!empty($e['compared']) && empty($e['same'])) {
                $nights[] = ['time' => (int) ($e['time'] ?? 0), 'more' => (int) ($e['more'] ?? 0), 'differences' => array_map(fn (array $d): array => [
                    'kind' => (string) ($d['kind'] ?? ''), 'path' => (string) ($d['path'] ?? ''),
                    'python' => jackShadowWord($d['python'] ?? null), 'php' => jackShadowWord($d['php'] ?? null)], array_values(array_filter((array) ($e['differences'] ?? []), 'is_array')))];
                if (count($nights) >= JACK_SHADOW_LIST) {
                    break 2;
                }
            }
        }
    }
    return ['ok' => true, 'state' => ['nights' => $nights, 'summary' => jackShadowSummary($dir)]];
}
