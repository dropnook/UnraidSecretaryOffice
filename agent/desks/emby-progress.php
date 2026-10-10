<?php
declare(strict_types=1);

/*
 * Jack Emby: a live panel while EmbyCache runs for real (Benj, 2026-10-10 — in place of the «Output (real run)» button,
 * like Mr. Backupsy's run progress). EmbyCache writes a small progress file when EMBYCACHE_PROGRESS is set (atomic, at
 * every file boundary; embycache_run.py `Progress`): the phase (plan, back, fill, done), «back to the array» and per Emby
 * user what is planned and done (files, bytes), the file being copied with its target, its user and its reason. Jack's
 * job sets it for real runs (run, release) to a file in RAM (`RUN_DIR/emby-progress.json`) and, every
 * EMBY_PROGRESS_EVERY seconds while EmbyCache copies, keeps a sample of the bytes moved so far — the files done plus the
 * part of the current one: rsync's temporary file `.<name>.XXXXXX` next to the target, one directory listing and one
 * lstat, read only (the pool or the disk is awake: it is being written) — in a second RAM file
 * (`RUN_DIR/emby-progress-speed.json`, the last EMBY_PROGRESS_WINDOW seconds). The speed is the MEDIAN of the rates
 * between those samples (a stall or a burst doesn't swing it), there after the first step of 10 s (two samples);
 * every ETA is bytes / that speed. The bracket's: everything left. A user's (Benj, 2026-10-10, the first real run on
 * nostromo — «2 min» for 12 GB nothing of which had started while another's 49 GB file copied): EmbyCache fills one
 * file after another, all users mixed, in path order, and writes that queue (`queue` [[user index, bytes], …], `pos` =
 * how many are done or dropped): the bytes until the user's LAST remaining file is done — what is left of «back» while
 * it goes, plus every remaining queue entry up to and including that file, minus what of the current one is there.
 * Without a queue (none beyond 5000 files) their own remaining bytes. The bars: the one being copied first, then by
 * the queue position of their next file, finished ones at the end; a finished bar says how long it took (`took`: the
 * first file's start to the last one's end), the bracket how long the run has been going (`running`).
 *
 * «Stop after this file» (emby.stop, emby.php): the same stop request as the mover guard's (`office-stop.json` in his
 * data folder, EMBYCACHE_STOP) with why `user`; the part says it was asked (`stopping`).
 *
 * His page asks the part `progress` (desk.json; the agent's action `progress`, two RAM reads, nothing written) every
 * 10 s while a real EmbyCache run goes, never otherwise. Both RAM files go when the run ends.
 */

const EMBY_PROGRESS_EVERY  = 10;      // a sample of the bytes moved every 10 s while EmbyCache copies
const EMBY_PROGRESS_WINDOW = 600;     // the speed: the median rate of the last 10 minutes
const EMBY_PROGRESS_USERS  = 50;      // at most this many users' bars
const EMBY_PROGRESS_SOURCES = ['resume', 'next_up', 'next_episode', 'favorite', 'other'];
const EMBY_PROGRESS_QUEUE  = 5000;    // EmbyCache's fill queue: at most this many entries (more: none, each user's own estimate)

/** The two RAM files of a real run: EmbyCache's progress and the job's speed samples */
function embyProgressPaths(string $dir = RUN_DIR): array
{
    return ['file' => "$dir/emby-progress.json", 'samples' => "$dir/emby-progress-speed.json"];
}

/** A run starts or ends: neither file of a run before stays */
function embyProgressClear(array $paths): void
{
    foreach ($paths as $f) {
        if (is_file($f) && !is_link($f)) {
            @unlink($f);
        }
    }
}

/** A count as EmbyCache writes it: an int ≥ 0, else 0 */
function embyProgressInt(mixed $v): int
{
    return is_int($v) && $v >= 0 ? $v : (is_float($v) && $v >= 0 && $v < 1e18 ? (int) $v : 0);
}

/** A text of EmbyCache's for the page: a string, no control characters, capped */
function embyProgressText(mixed $v, int $max = 300): ?string
{
    return is_string($v) && $v !== '' ? mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $v), 0, $max) : null;
}

/** A time EmbyCache wrote (started, ended): a positive int, else null */
function embyProgressTime(mixed $v): ?int
{
    return is_int($v) && $v > 0 ? $v : null;
}

/**
 * EmbyCache's progress file, taken only in its shape (v 1): phase, started/updated, back {files, bytes, done_files,
 * done_bytes, started, ended}, users [{key, name, server, …}], current {phase, rel, size, target, user, source, title,
 * since}, queue [[user index, bytes], …] (indexes into the users taken here; -1 or one left out: nobody's) and pos
 * (0…its length) — a queue not in that shape is none. Null when there is none or it isn't that.
 */
function embyProgressRead(string $file): ?array
{
    if (!is_file($file) || is_link($file) || (int) @filesize($file) > 1048576) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !in_array($j['phase'] ?? null, ['plan', 'back', 'fill', 'done'], true)) {
        return null;
    }
    $count = fn (mixed $c): ?array => is_array($c) ? ['files' => embyProgressInt($c['files'] ?? 0), 'bytes' => embyProgressInt($c['bytes'] ?? 0),
        'done_files' => embyProgressInt($c['done_files'] ?? 0), 'done_bytes' => embyProgressInt($c['done_bytes'] ?? 0),
        'started' => embyProgressTime($c['started'] ?? null), 'ended' => embyProgressTime($c['ended'] ?? null)] : null;
    $users = [];
    $index = [];                         // EmbyCache's user index -> ours
    foreach (array_slice(is_array($j['users'] ?? null) ? array_values($j['users']) : [], 0, EMBY_PROGRESS_USERS) as $i => $u) {
        if (is_array($u) && is_string($u['key'] ?? null)) {
            $index[$i] = count($users);
            $users[] = ['key' => $u['key'], 'name' => embyProgressText($u['name'] ?? null, 100) ?? '?', 'server' => embyProgressText($u['server'] ?? null, 100) ?? '']
                     + $count($u);
        }
    }
    $queue = null;
    if (is_array($j['queue'] ?? null) && array_is_list($j['queue']) && count($j['queue']) <= EMBY_PROGRESS_QUEUE
        && is_int($j['pos'] ?? null) && $j['pos'] >= 0 && $j['pos'] <= count($j['queue'])) {
        $queue = [];
        foreach ($j['queue'] as $q) {
            if (!is_array($q) || count($q) !== 2 || !is_int($q[0] ?? null) || !is_int($q[1] ?? null) || $q[1] < 0) {
                $queue = null;
                break;
            }
            $queue[] = [$index[$q[0]] ?? -1, $q[1]];
        }
    }
    $cur = is_array($j['current'] ?? null) ? $j['current'] : null;
    $current = null;
    if ($cur !== null && in_array($cur['phase'] ?? null, ['back', 'fill'], true) && is_string($cur['rel'] ?? null) && is_string($cur['target'] ?? null)) {
        $current = ['phase' => $cur['phase'], 'rel' => embyProgressText($cur['rel'], 500) ?? '', 'size' => embyProgressInt($cur['size'] ?? 0),
                    'target' => $cur['target'], 'user' => is_string($cur['user'] ?? null) ? $cur['user'] : null,
                    'source' => in_array($cur['source'] ?? null, EMBY_PROGRESS_SOURCES, true) ? $cur['source'] : null,
                    'title' => embyProgressText($cur['title'] ?? null, 200), 'since' => embyProgressInt($cur['since'] ?? 0)];
    }
    return ['mode' => in_array($j['mode'] ?? null, ['run', 'release'], true) ? $j['mode'] : 'run', 'phase' => $j['phase'],
            'started' => embyProgressInt($j['started'] ?? 0), 'updated' => embyProgressInt($j['updated'] ?? 0),
            'back' => $count($j['back'] ?? null), 'users' => $users, 'current' => $current,
            'queue' => $queue, 'pos' => $queue !== null ? $j['pos'] : 0];
}

/**
 * How much of the current file is there: rsync writes into `.<name>.XXXXXX` next to the target and renames it at the
 * end (with --inplace the target itself grows). One directory listing and one lstat of the target's folder — read only;
 * the folder lies on the pool or the disk being written to. A target that isn't a plain path under /mnt ($root, the
 * tests' own): 0.
 */
function embyProgressPartial(?array $cur, string $root = '/mnt/'): int
{
    if ($cur === null) {
        return 0;
    }
    $target = (string) $cur['target'];
    if (!str_starts_with($target, $root) || preg_match('/[\x00-\x1f]/', $target) || preg_match('#/\.\.?(/|$)#', $target)) {
        return 0;
    }
    $dir = dirname($target);
    $name = basename($target);
    $best = 0;
    foreach (@scandir($dir) ?: [] as $e) {
        if (strlen($e) === strlen($name) + 8 && str_starts_with($e, ".$name.") && preg_match('/^[A-Za-z0-9]{6}$/D', substr($e, -6))) {
            $st = @lstat("$dir/$e");
            if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
                $best = max($best, (int) $st['size']);
            }
        }
    }
    if ($best === 0 && ($st = @lstat($target)) !== false && ($st['mode'] & 0170000) === 0100000) {
        $best = (int) $st['size'];                 // --inplace, or rsync just renamed it
    }
    return min($best, (int) $cur['size']);
}

/** The bytes moved so far: back and every user's done, plus what of the current file is there */
function embyProgressMoved(array $p, int $partial): int
{
    $n = (int) ($p['back']['done_bytes'] ?? 0) + $partial;
    foreach ($p['users'] as $u) {
        $n += $u['done_bytes'];
    }
    return $n;
}

/** The job's samples, only in their shape: [[time, bytes moved, the current file's rel, its part], …] of this run */
function embyProgressSamples(string $file, int $started): array
{
    $j = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (!is_array($j) || ($j['run'] ?? null) !== $started || !is_array($j['samples'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($j['samples'] as $s) {
        if (is_array($s) && count($s) === 4 && is_int($s[0]) && is_int($s[1]) && (is_string($s[2]) || $s[2] === null) && is_int($s[3])) {
            $out[] = $s;
        }
    }
    return $out;
}

/**
 * One sample (the job, every EMBY_PROGRESS_EVERY s while the run goes): only while EmbyCache copies (back, fill), the
 * samples of the last EMBY_PROGRESS_WINDOW (and the one before, for the first rate) kept in RAM. True when one was taken.
 */
function embyProgressSample(array $paths, ?int $now = null, ?callable $partial = null): bool
{
    $now ??= time();
    $p = embyProgressRead($paths['file']);
    if ($p === null || !in_array($p['phase'], ['back', 'fill'], true)) {
        return false;
    }
    $part = ($partial ?? 'embyProgressPartial')($p['current']);
    $samples = embyProgressSamples($paths['samples'], $p['started']);
    $samples[] = [$now, embyProgressMoved($p, $part), $p['current']['rel'] ?? null, $part];
    $keep = [];
    foreach ($samples as $i => $s) {
        // the window, and the newest sample before it (the first rate inside it starts there)
        if ($s[0] >= $now - EMBY_PROGRESS_WINDOW || (isset($samples[$i + 1]) && $samples[$i + 1][0] >= $now - EMBY_PROGRESS_WINDOW)) {
            $keep[] = $s;
        }
    }
    writeAtomic($paths['samples'], jsonEncode(['run' => $p['started'], 'samples' => array_slice($keep, -200)]), 0600, 0, 0);
    return true;
}

/** The job's ticker for embyRunWatch(): a sample every EMBY_PROGRESS_EVERY s once EmbyCache copies (asked every half second) */
function embyProgressTicker(array $paths, ?callable $now = null): callable
{
    $now ??= fn (): int => time();
    $next = 0;
    return function () use ($paths, $now, &$next): void {
        $t = $now();
        if ($t < $next) {
            return;
        }
        try {
            $took = embyProgressSample($paths, $t);
        } catch (Throwable) {
            $took = false;
        }
        $next = $t + ($took ? EMBY_PROGRESS_EVERY : 2);      // not copying yet: look again soon, so the first step starts at once
    };
}

/**
 * Bytes per second: the median of the rates between consecutive samples of the last EMBY_PROGRESS_WINDOW s (a step
 * that began up to one EMBY_PROGRESS_EVERY before it counts; a longer gap — the job was away — doesn't); null before two
 */
function embyProgressSpeed(array $samples, int $now): ?float
{
    $rates = [];
    for ($i = 1; $i < count($samples); $i++) {
        [$a, $b] = [$samples[$i - 1], $samples[$i]];
        if ($b[0] > $a[0] && $a[0] >= $now - EMBY_PROGRESS_WINDOW - EMBY_PROGRESS_EVERY) {
            $rates[] = max(0, $b[1] - $a[1]) / ($b[0] - $a[0]);        // a failed copy's part gone: no negative rate
        }
    }
    if (!$rates) {
        return null;
    }
    sort($rates);
    $n = count($rates);
    return $n % 2 ? $rates[intdiv($n, 2)] : ($rates[$n / 2 - 1] + $rates[$n / 2]) / 2;
}

/**
 * What the page draws: the bars (back, per user), the bracket over all of them (total, speed, ETA, how long the run has
 * been going) and the file being copied under its bar with its reason. The current file's part from the newest sample,
 * while that sample is of this file and newer than EmbyCache's last word (else 0 — the next sample brings it). Seconds
 * left: null without a speed. A user's: until their last remaining file in EmbyCache's queue is done (see the top).
 * The users: the one being copied, then by their next file's place in the queue, finished ones at the end.
 */
function embyProgressView(array $p, array $samples, int $now): array
{
    $speed = embyProgressSpeed($samples, $now);
    $last = $samples ? $samples[count($samples) - 1] : null;
    $cur = $p['current'];
    $part = $cur !== null && $last !== null && $last[2] === $cur['rel'] && $last[0] >= $p['updated'] ? min($last[3], $cur['size']) : 0;
    $eta = fn (int $left): ?int => $speed !== null && $speed > 0 ? (int) ceil($left / $speed) : null;
    $took = fn (array $c): ?int => $c['started'] !== null && $c['ended'] !== null ? max(0, $c['ended'] - $c['started']) : null;
    $bar = function (array $c, bool $mine) use ($part, $eta, $took): array {
        $done = min($c['bytes'], $c['done_bytes'] + ($mine ? $part : 0));
        $left = $c['files'] > $c['done_files'];
        return ['files' => $c['files'], 'bytes' => $c['bytes'], 'done_files' => $c['done_files'], 'done_bytes' => $done,
                'eta' => $left ? $eta(max(0, $c['bytes'] - $done)) : 0, 'took' => $left ? null : $took($c)];
    };
    // the file being copied: under the bar it belongs to (`here`) — «back to the array», or its user's
    $shown = $cur === null ? null : ['phase' => $cur['phase'], 'rel' => $cur['rel'], 'size' => $cur['size'], 'done' => $part,
                                     'source' => $cur['source'], 'title' => $cur['title']];
    $inBack = ($cur['phase'] ?? '') === 'back';
    $back = $p['back'] !== null ? $bar($p['back'], $inBack) + ['ended' => in_array($p['phase'], ['fill', 'done'], true), 'here' => $inBack] : null;
    // the fill's queue: the bytes from now until each user's last remaining file is done (and their next file's place)
    $backLeft = $back !== null && !$back['ended'] ? max(0, $back['bytes'] - $back['done_bytes']) : 0;
    $fillPart = ($cur['phase'] ?? '') === 'fill' ? $part : 0;
    $until = $next = [];
    if ($p['queue'] !== null) {
        $sum = 0;
        for ($i = $p['pos'], $n = count($p['queue']); $i < $n; $i++) {
            [$ui, $b] = $p['queue'][$i];
            $sum += $b;
            if ($ui >= 0) {
                $until[$ui] = $sum;
                $next[$ui] ??= $i;
            }
        }
    }
    $users = [];
    foreach ($p['users'] as $k => $u) {
        $mine = ($cur['phase'] ?? '') === 'fill' && ($cur['user'] ?? null) === $u['key'];
        $row = ['name' => $u['name'], 'server' => $u['server']] + $bar($u, $mine) + ['here' => $mine];
        if ($u['files'] > $u['done_files'] && isset($until[$k])) {
            $row['eta'] = $eta($backLeft + max(0, $until[$k] - $fillPart));
        }
        $users[] = ['row' => $row, 'k' => $k, 'left' => $u['files'] > $u['done_files'], 'next' => $next[$k] ?? PHP_INT_MAX];
    }
    usort($users, fn (array $a, array $b): int => [!$a['left'], !$a['row']['here'], $a['next'], $a['k']] <=> [!$b['left'], !$b['row']['here'], $b['next'], $b['k']]);
    $users = array_column($users, 'row');
    $total = ['files' => 0, 'bytes' => 0, 'done_files' => 0, 'done_bytes' => 0];
    foreach (array_merge($back !== null ? [$back] : [], $users) as $c) {
        foreach ($total as $k => $_) {
            $total[$k] += $c[$k];
        }
    }
    $total['eta'] = $total['files'] > $total['done_files'] ? $eta(max(0, $total['bytes'] - $total['done_bytes'])) : 0;
    $total['running'] = $p['started'] > 0 ? max(0, $now - $p['started']) : null;
    return ['mode' => $p['mode'], 'phase' => $p['phase'], 'started' => $p['started'], 'back' => $back, 'users' => $users, 'total' => $total,
            'speed' => $speed !== null ? (int) round($speed) : null, 'current' => $shown];
}

/** «Stop after this file» asked — the stop request in his data folder (EMBYCACHE_STOP): its why (user, mover, watching), else null */
function embyProgressStopping(string $file): ?string
{
    clearstatcache(true, $file);
    if (!is_file($file) || is_link($file)) {
        return null;
    }
    $why = (json_decode((string) @file_get_contents($file, false, null, 0, 65536), true) ?: [])['why'] ?? null;
    return in_array($why, ['mover', 'watching'], true) ? $why : 'user';
}

/**
 * The part `progress` (his page, every 10 s while a real EmbyCache run goes): whether it runs and, while it does, the
 * view and whether it was asked to stop — two RAM files, his office-run.json and a look for the stop request beside
 * it; nothing written, nothing on a disk asked.
 */
function embyProgressState(?array $job = null, ?array $paths = null, ?int $now = null, ?string $stopFile = null): array
{
    $now ??= time();
    $job ??= embyJobInfo('embycache');
    $paths ??= embyProgressPaths();
    $real = !empty($job['running']) && in_array($job['mode'] ?? '', ['run', 'release'], true);
    $p = $real ? embyProgressRead($paths['file']) : null;
    return ['time' => $now, 'running' => !empty($job['running']), 'mode' => (string) ($job['mode'] ?? ''), 'real' => $real,
            'stopping' => $real ? embyProgressStopping($stopFile ?? EMBY_DATA . '/office-stop.json') : null,
            'progress' => $p !== null ? embyProgressView($p, embyProgressSamples($paths['samples'], $p['started']), $now) : null];
}

/**
 * «Stop after this file» (his panel; Benj, 2026-10-10): only while a real EmbyCache run goes (run, release) — the same
 * stop request the mover guard writes (`office-stop.json`, EMBYCACHE_STOP), why `user`, once; EmbyCache ends after the
 * file it is on (exit 3, result `stopped`), embyRunWatch() takes the why from the file. Already asked: nothing new.
 * Returns whether it was written now.
 */
function embyStopAsk(?array $job = null, ?string $file = null): bool
{
    $job ??= embyJobInfo('embycache');
    $file ??= EMBY_DATA . '/office-stop.json';
    if (empty($job['running']) || !in_array($job['mode'] ?? '', ['run', 'release'], true)) {
        throw new Problem('emby_stop_none');
    }
    clearstatcache(true, $file);
    if (file_exists($file) || is_link($file)) {
        return false;
    }
    writeAtomic($file, jsonEncode(['time' => time(), 'why' => 'user']), 0600, 0, 0);
    logLine('Jack Emby: asked EmbyCache (' . $job['mode'] . ') to stop after the file it is on — from his page');
    return true;
}
