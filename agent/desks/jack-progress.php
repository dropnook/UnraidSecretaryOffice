<?php
declare(strict_types=1);

/*
 * Jack Emby: a live panel while EmbyCache runs for real (2026-10-10 — in place of the «Output (real run)» button,
 * like Mr. Backupsy's run progress). EmbyCache writes a small progress file when EMBYCACHE_PROGRESS is set (atomic, at
 * every file boundary; mediacache_run.py `Progress`): the phase (plan, back, fill, done), «back to the array» and per Emby
 * user what is planned and done (files, bytes), the file being copied with its target, its user and its reason. Jack's
 * job sets it for real runs (run, release) to a file in RAM (`RUN_DIR/jack-progress.json`) and, every
 * JACK_PROGRESS_EVERY seconds while EmbyCache copies, keeps a sample of the bytes moved so far — the files done plus the
 * part of the current one: rsync's temporary file `.<name>.XXXXXX` next to the target, one directory listing and one
 * lstat, read only (the pool or the disk is awake: it is being written) — in a second RAM file
 * (`RUN_DIR/jack-progress-speed.json`, the last JACK_PROGRESS_WINDOW seconds). The speed is the MEDIAN of the rates
 * between those samples (a stall or a burst doesn't swing it), there after the first step of 10 s (two samples);
 * every ETA is bytes / that speed. The bracket's: everything left. A user's (2026-10-10, the first real run on
 * a large server — «2 min» for 12 GB nothing of which had started while another's 49 GB file copied): EmbyCache fills one
 * file after another, all users mixed, in path order, and writes that queue (`queue` [[user index, bytes], …], `pos` =
 * how many are done or dropped): the bytes until the user's LAST remaining file is done — what is left of «back» while
 * it goes, plus every remaining queue entry up to and including that file, minus what of the current one is there.
 * Without a queue (none beyond 5000 files) their own remaining bytes. The bars: the one being copied first, then by
 * the queue position of their next file, finished ones at the end; a finished bar says how long it took (`took`: the
 * first file's start to the last one's end), the bracket how long the run has been going (`running`).
 *
 * «Stop after this file» (jack.stop, jack.php): the same stop request as the mover guard's (`office-stop.json` in his
 * data folder, EMBYCACHE_STOP) with why `user`; the part says it was asked (`stopping`).
 *
 * Several copies at once (2026-10-29, «copies at once per disk» in his setup — MediaCache's parallel_per_disk, the total
 * the array's disks × that): MediaCache's `active` lists every file in flight (`current` stays the first of them, for
 * older readers and Ms. Moverelli's card). Each has its part (its own temporary file), each sample keeps them all
 * ([rel, part] pairs), each bar shows its files in flight under it; stopped, the run ends after the files in flight. The
 * queue: a file done while an earlier one still copies is nobody's there ([-1, 0]), `pos` counts the done ones from the
 * front; a file in flight knows its place in the queue (`q`).
 *
 * A file several persons have on deck (2026-10-29): it counts at each of them (their bars' planned and done, their time
 * left) and is copied once — `fill` counts the fill as a whole (each file once) for the bracket, the queue names it at
 * the first, `shared` [[queue index, [user index, …]], …] at the others, a file in flight its `users` (all of them).
 *
 * His page asks the part `progress` (desk.json; the agent's action `progress`, two RAM reads, nothing written) every
 * 10 s while a real EmbyCache run goes, never otherwise. Both RAM files go when the run ends.
 */

const JACK_PROGRESS_EVERY  = 10;      // a sample of the bytes moved every 10 s while EmbyCache copies
const JACK_PROGRESS_WINDOW = 600;     // the speed: the median rate of the last 10 minutes
const JACK_PROGRESS_USERS  = 50;      // at most this many users' bars
const JACK_PROGRESS_SOURCES = ['resume', 'next_up', 'next_episode', 'favorite', 'other'];
const JACK_PROGRESS_QUEUE  = 5000;    // EmbyCache's fill queue: at most this many entries (more: none, each user's own estimate)
const JACK_PROGRESS_ACTIVE = 16;      // files in flight at once taken (the setup allows at most JACK_PARALLEL_TOTAL_MAX)

/** The two RAM files of a real run: EmbyCache's progress and the job's speed samples */
function jackProgressPaths(string $dir = RUN_DIR): array
{
    return ['file' => "$dir/jack-progress.json", 'samples' => "$dir/jack-progress-speed.json"];
}

/** A run starts or ends: neither file of a run before stays */
function jackProgressClear(array $paths): void
{
    foreach ($paths as $f) {
        if (is_file($f) && !is_link($f)) {
            @unlink($f);
        }
    }
}

/** A count as EmbyCache writes it: an int ≥ 0, else 0 */
function jackProgressInt(mixed $v): int
{
    return is_int($v) && $v >= 0 ? $v : (is_float($v) && $v >= 0 && $v < 1e18 ? (int) $v : 0);
}

/** A text of EmbyCache's for the page: a string, no control characters, capped */
function jackProgressText(mixed $v, int $max = 300): ?string
{
    return is_string($v) && $v !== '' ? mb_substr((string) preg_replace('/[\x00-\x1f\x7f]/u', ' ', $v), 0, $max) : null;
}

/**
 * Why a file goes back over his limit (jack-phase2, office block in EmbyCache): {title, ahead, who} — what it is, how far
 * ahead it lies for whoever watches it soonest (0 started, 1 next up, n the n-th episode ahead) and who queues it; null
 * when there is none or it isn't that. Also the items of the status's `give_back`.
 */
function jackGiveBackWhy(mixed $w): ?array
{
    if (!is_array($w) || !is_int($w['ahead'] ?? null) || $w['ahead'] < 0) {
        return null;
    }
    $title = jackProgressText($w['title'] ?? null, 200);
    return $title === null ? null : ['title' => $title, 'ahead' => min($w['ahead'], 9999), 'who' => jackProgressText($w['who'] ?? null, 200) ?? ''];
}

/** A time EmbyCache wrote (started, ended): a positive int, else null */
function jackProgressTime(mixed $v): ?int
{
    return is_int($v) && $v > 0 ? $v : null;
}

/** A file in flight as MediaCache writes it (`current`, each of `active`), only in its shape: else null */
function jackProgressFile(mixed $cur): ?array
{
    if (!is_array($cur) || !in_array($cur['phase'] ?? null, ['back', 'fill'], true) || !is_string($cur['rel'] ?? null) || !is_string($cur['target'] ?? null)) {
        return null;
    }
    return ['phase' => $cur['phase'], 'rel' => jackProgressText($cur['rel'], 500) ?? '', 'size' => jackProgressInt($cur['size'] ?? 0),
            'target' => $cur['target'], 'user' => is_string($cur['user'] ?? null) ? $cur['user'] : null,
            'source' => in_array($cur['source'] ?? null, JACK_PROGRESS_SOURCES, true) ? $cur['source'] : null,
            'title' => jackProgressText($cur['title'] ?? null, 200), 'since' => jackProgressInt($cur['since'] ?? 0),
            'why' => $cur['phase'] === 'back' ? jackGiveBackWhy($cur['why'] ?? null) : null,
            'q' => is_int($cur['q'] ?? null) && $cur['q'] >= 0 ? $cur['q'] : null,
            'users' => array_values(array_filter(is_array($cur['users'] ?? null) ? array_slice($cur['users'], 0, JACK_PROGRESS_USERS) : [], 'is_string'))];
}

/**
 * EmbyCache's progress file, taken only in its shape (v 1): phase, started/updated, back {files, bytes, done_files,
 * done_bytes, started, ended}, users [{key, name, server, …}], current {phase, rel, size, target, user, source, title,
 * since}, active [the same, + q] (every file in flight; none there: `current` alone), queue [[user index, bytes], …]
 * (indexes into the users taken here; -1 or one left out: nobody's) and pos (0…its length) — a queue not in that shape
 * is none. Null when there is none or it isn't that. `current`: the first in flight.
 */
function jackProgressRead(string $file): ?array
{
    if (!is_file($file) || is_link($file) || (int) @filesize($file) > 1048576) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j) || ($j['v'] ?? null) !== 1 || !in_array($j['phase'] ?? null, ['plan', 'back', 'fill', 'done'], true)) {
        return null;
    }
    $count = fn (mixed $c): ?array => is_array($c) ? ['files' => jackProgressInt($c['files'] ?? 0), 'bytes' => jackProgressInt($c['bytes'] ?? 0),
        'done_files' => jackProgressInt($c['done_files'] ?? 0), 'done_bytes' => jackProgressInt($c['done_bytes'] ?? 0),
        'started' => jackProgressTime($c['started'] ?? null), 'ended' => jackProgressTime($c['ended'] ?? null)] : null;
    $users = [];
    $index = [];                         // EmbyCache's user index -> ours
    foreach (array_slice(is_array($j['users'] ?? null) ? array_values($j['users']) : [], 0, JACK_PROGRESS_USERS) as $i => $u) {
        if (is_array($u) && is_string($u['key'] ?? null)) {
            $index[$i] = count($users);
            $users[] = ['key' => $u['key'], 'name' => jackProgressText($u['name'] ?? null, 100) ?? '?', 'server' => jackProgressText($u['server'] ?? null, 100) ?? '']
                     + $count($u);
        }
    }
    $queue = null;
    if (is_array($j['queue'] ?? null) && array_is_list($j['queue']) && count($j['queue']) <= JACK_PROGRESS_QUEUE
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
    // a file of several persons: the others at its place in the queue (indexes into the users taken here)
    $shared = [];
    foreach ($queue !== null && is_array($j['shared'] ?? null) ? $j['shared'] : [] as $x) {
        if (is_array($x) && count($x) === 2 && is_int($x[0] ?? null) && isset($queue[$x[0]]) && is_array($x[1] ?? null)) {
            $others = array_values(array_filter(array_map(fn ($i) => is_int($i) ? ($index[$i] ?? null) : null, $x[1]), 'is_int'));
            if ($others) {
                $shared[$x[0]] = $others;
            }
        }
    }
    // every file in flight (an older MediaCache: its one `current`)
    $active = [];
    $list = is_array($j['active'] ?? null) && array_is_list($j['active']) ? $j['active'] : [$j['current'] ?? null];
    foreach (array_slice($list, 0, JACK_PROGRESS_ACTIVE) as $a) {
        if (($f = jackProgressFile($a)) !== null) {
            $active[] = $f;
        }
    }
    return ['mode' => in_array($j['mode'] ?? null, ['run', 'release'], true) ? $j['mode'] : 'run', 'phase' => $j['phase'],
            'started' => jackProgressInt($j['started'] ?? 0), 'updated' => jackProgressInt($j['updated'] ?? 0),
            'back' => $count($j['back'] ?? null), 'fill' => $count($j['fill'] ?? null), 'users' => $users, 'current' => $active[0] ?? null,
            'active' => $active, 'queue' => $queue, 'shared' => $shared, 'pos' => $queue !== null ? $j['pos'] : 0];
}

/**
 * How much of the current file is there: rsync writes into `.<name>.XXXXXX` next to the target and renames it at the
 * end (with --inplace the target itself grows). One directory listing and one lstat of the target's folder — read only;
 * the folder lies on the pool or the disk being written to. A target that isn't a plain path under /mnt ($root, the
 * tests' own): 0.
 */
function jackProgressPartial(?array $cur, string $root = '/mnt/'): int
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
function jackProgressMoved(array $p, int $partial): int
{
    $n = (int) ($p['back']['done_bytes'] ?? 0) + $partial;
    foreach ($p['users'] as $u) {
        $n += $u['done_bytes'];
    }
    return $n;
}

/**
 * The job's samples, only in their shape: [[time, bytes moved, the first file's rel, the parts together, [[rel, part], …]
 * of every file in flight], …] of this run (an older job's four: the one file's part alone)
 */
function jackProgressSamples(string $file, int $started): array
{
    $j = is_file($file) && !is_link($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (!is_array($j) || ($j['run'] ?? null) !== $started || !is_array($j['samples'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($j['samples'] as $s) {
        if (!is_array($s) || !in_array(count($s), [4, 5], true) || !is_int($s[0]) || !is_int($s[1]) || !(is_string($s[2]) || $s[2] === null) || !is_int($s[3])) {
            continue;
        }
        $parts = [];
        foreach (count($s) === 5 && is_array($s[4]) ? $s[4] : ($s[2] !== null ? [[$s[2], $s[3]]] : []) as $pp) {
            if (is_array($pp) && count($pp) === 2 && is_string($pp[0] ?? null) && is_int($pp[1] ?? null) && $pp[1] >= 0) {
                $parts[] = [$pp[0], $pp[1]];
            }
        }
        $out[] = [$s[0], $s[1], $s[2], $s[3], $parts];
    }
    return $out;
}

/**
 * One sample (the job, every JACK_PROGRESS_EVERY s while the run goes): only while EmbyCache copies (back, fill), the
 * samples of the last JACK_PROGRESS_WINDOW (and the one before, for the first rate) kept in RAM. True when one was taken.
 */
function jackProgressSample(array $paths, ?int $now = null, ?callable $partial = null): bool
{
    $now ??= time();
    $p = jackProgressRead($paths['file']);
    if ($p === null || !in_array($p['phase'], ['back', 'fill'], true)) {
        return false;
    }
    $parts = [];
    foreach ($p['active'] as $f) {
        $parts[] = [$f['rel'], (int) ($partial ?? 'jackProgressPartial')($f)];
    }
    $part = array_sum(array_column($parts, 1));
    $samples = jackProgressSamples($paths['samples'], $p['started']);
    $samples[] = [$now, jackProgressMoved($p, $part), $p['current']['rel'] ?? null, $part, $parts];
    $keep = [];
    foreach ($samples as $i => $s) {
        // the window, and the newest sample before it (the first rate inside it starts there)
        if ($s[0] >= $now - JACK_PROGRESS_WINDOW || (isset($samples[$i + 1]) && $samples[$i + 1][0] >= $now - JACK_PROGRESS_WINDOW)) {
            $keep[] = $s;
        }
    }
    writeAtomic($paths['samples'], jsonEncode(['run' => $p['started'], 'samples' => array_slice($keep, -200)]), 0600, 0, 0);
    return true;
}

/** The job's ticker for jackRunWatch(): a sample every JACK_PROGRESS_EVERY s once EmbyCache copies (asked every half second) */
function jackProgressTicker(array $paths, ?callable $now = null): callable
{
    $now ??= fn (): int => time();
    $next = 0;
    return function () use ($paths, $now, &$next): void {
        $t = $now();
        if ($t < $next) {
            return;
        }
        try {
            $took = jackProgressSample($paths, $t);
        } catch (Throwable) {
            $took = false;
        }
        $next = $t + ($took ? JACK_PROGRESS_EVERY : 2);      // not copying yet: look again soon, so the first step starts at once
    };
}

/**
 * Bytes per second: the median of the rates between consecutive samples of the last JACK_PROGRESS_WINDOW s (a step
 * that began up to one JACK_PROGRESS_EVERY before it counts; a longer gap — the job was away — doesn't); null before two
 */
function jackProgressSpeed(array $samples, int $now): ?float
{
    $rates = [];
    for ($i = 1; $i < count($samples); $i++) {
        [$a, $b] = [$samples[$i - 1], $samples[$i]];
        if ($b[0] > $a[0] && $a[0] >= $now - JACK_PROGRESS_WINDOW - JACK_PROGRESS_EVERY) {
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
 * been going) and the files in flight, each under its bar with its reason (`files` on the bar; `current` and `files` at
 * the top: the first and all, for Ms. Moverelli's card). A file's part from the newest sample, while that sample has it
 * and is not older than the file's start (else 0 — the next sample brings it). Seconds left: null without a speed. A
 * user's: until their last remaining file in EmbyCache's queue is done (see the top), the parts in flight up to it
 * already there. The users: the ones being copied, then by their next file's place in the queue, finished ones at the end.
 */
function jackProgressView(array $p, array $samples, int $now, bool $stopping = false): array
{
    $speed = jackProgressSpeed($samples, $now);
    $last = $samples ? $samples[count($samples) - 1] : null;
    $active = $p['active'] ?? ($p['current'] !== null ? [$p['current']] : []);
    $have = [];
    foreach ($last !== null ? ($last[4] ?? [[$last[2], $last[3]]]) : [] as [$rel, $n]) {
        $have[$rel] = $n;
    }
    // each file in flight as the page shows it (no target path), with its part
    $files = [];
    foreach ($active as $f) {
        $part = $last !== null && isset($have[$f['rel']]) && $last[0] >= $f['since']
            ? min((int) $have[$f['rel']], $f['size']) : 0;
        $files[] = ['phase' => $f['phase'], 'rel' => $f['rel'], 'size' => $f['size'], 'done' => $part, 'source' => $f['source'],
                    'title' => $f['title'], 'why' => $f['why'] ?? null, 'user' => $f['user'], 'users' => $f['users'] ?? [], 'q' => $f['q'] ?? null];
    }
    $mine = fn (callable $is): array => array_values(array_filter($files, $is));
    $show = fn (array $fs): array => array_map(fn (array $f): array => array_diff_key($f, ['user' => 1, 'users' => 1, 'q' => 1]), $fs);
    $sum = fn (array $fs, string $k): int => array_sum(array_column($fs, $k));
    $eta = fn (int $left): ?int => $speed !== null && $speed > 0 ? (int) ceil($left / $speed) : null;
    $took = fn (array $c): ?int => $c['started'] !== null && $c['ended'] !== null ? max(0, $c['ended'] - $c['started']) : null;
    $bar = function (array $c, array $fs) use ($eta, $took, $sum, $show): array {
        $done = min($c['bytes'], $c['done_bytes'] + $sum($fs, 'done'));
        $left = $c['files'] > $c['done_files'];
        return ['files' => $c['files'], 'bytes' => $c['bytes'], 'done_files' => $c['done_files'], 'done_bytes' => $done,
                'eta' => $left ? $eta(max(0, $c['bytes'] - $done)) : 0, 'took' => $left ? null : $took($c), 'here' => (bool) $fs,
                'in_flight' => $show($fs)];
    };
    $backFiles = $mine(fn (array $f): bool => $f['phase'] === 'back');
    $back = $p['back'] !== null ? $bar($p['back'], $backFiles) + ['ended' => in_array($p['phase'], ['fill', 'done'], true)] : null;
    // the fill's queue: the bytes from now until each user's last remaining file is done (and their next file's place)
    $backLeft = $back !== null && !$back['ended'] ? max(0, $back['bytes'] - $back['done_bytes']) : 0;
    $fillFiles = $mine(fn (array $f): bool => $f['phase'] === 'fill');
    $until = $next = $lastAt = [];
    if ($p['queue'] !== null) {
        $sumQ = 0;
        for ($i = $p['pos'], $n = count($p['queue']); $i < $n; $i++) {
            [$ui, $b] = $p['queue'][$i];
            $sumQ += $b;
            foreach (array_merge($ui >= 0 ? [$ui] : [], $p['shared'][$i] ?? []) as $who) {
                $until[$who] = $sumQ;
                $lastAt[$who] = $i;
                $next[$who] ??= $i;
            }
        }
    }
    $users = [];
    foreach ($p['users'] as $k => $u) {
        $fs = $mine(fn (array $f): bool => $f['phase'] === 'fill' && ($f['user'] === $u['key'] || in_array($u['key'], $f['users'], true)));
        $row = ['name' => $u['name'], 'server' => $u['server']] + $bar($u, $fs);
        if ($u['files'] > $u['done_files'] && isset($until[$k])) {
            // what of the files in flight up to their last one is there already (one without a place: counted, as before)
            $there = $sum(array_filter($fillFiles, fn (array $f): bool => $f['q'] === null || $f['q'] <= $lastAt[$k]), 'done');
            $row['eta'] = $eta($backLeft + max(0, $until[$k] - $there));
        }
        $users[] = ['row' => $row, 'k' => $k, 'left' => $u['files'] > $u['done_files'], 'next' => $next[$k] ?? PHP_INT_MAX];
    }
    usort($users, fn (array $a, array $b): int => [!$a['left'], !$a['row']['here'], $a['next'], $a['k']] <=> [!$b['left'], !$b['row']['here'], $b['next'], $b['k']]);
    $users = array_column($users, 'row');
    // the bracket: back and the fill as a whole (a file of several persons once); an older MediaCache without `fill`: the bars
    $total = ['files' => 0, 'bytes' => 0, 'done_files' => 0, 'done_bytes' => 0];
    foreach (array_merge($back !== null ? [$back] : [], ($p['fill'] ?? null) !== null ? [$bar($p['fill'], $fillFiles)] : $users) as $c) {
        foreach ($total as $k => $_) {
            $total[$k] += $c[$k];
        }
    }
    $total['eta'] = $total['files'] > $total['done_files'] ? $eta(max(0, $total['bytes'] - $total['done_bytes'])) : 0;
    $total['running'] = $p['started'] > 0 ? max(0, $now - $p['started']) : null;
    // asked to stop (the button, the mover): only the files in flight still count (2026-10-10) — the bars they belong to
    // and the total end with them, the others are `halted` (not in this run any more)
    if ($stopping) {
        $curEta = $files ? $eta(max(0, $sum($files, 'size') - $sum($files, 'done'))) : 0;
        $halt = function (array $c) use ($curEta): array {
            if ($c['here']) {
                $c['eta'] = $curEta;
            } elseif ($c['files'] > $c['done_files']) {
                $c['eta'] = null;
                $c['halted'] = true;
            }
            return $c;
        };
        if ($back !== null && !$back['ended']) {
            $back = $halt($back);
        }
        $users = array_map($halt, $users);
        $total['eta'] = $curEta;
    }
    $shown = $show($files);
    return ['mode' => $p['mode'], 'phase' => $p['phase'], 'started' => $p['started'], 'back' => $back, 'users' => $users, 'total' => $total,
            'speed' => $speed !== null ? (int) round($speed) : null, 'current' => $shown[0] ?? null, 'files' => $shown];
}

/** «Stop after this file» asked — the stop request in his data folder (EMBYCACHE_STOP): its why (user, mover, watching), else null */
function jackProgressStopping(string $file): ?string
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
function jackProgressState(?array $job = null, ?array $paths = null, ?int $now = null, ?string $stopFile = null): array
{
    $now ??= time();
    $job ??= jackJobInfo('embycache');
    $paths ??= jackProgressPaths();
    $real = !empty($job['running']) && in_array($job['mode'] ?? '', ['run', 'release'], true);
    $p = $real ? jackProgressRead($paths['file']) : null;
    return ['time' => $now, 'running' => !empty($job['running']), 'mode' => (string) ($job['mode'] ?? ''), 'real' => $real,
            'stopping' => $stopping = ($real ? jackProgressStopping($stopFile ?? JACK_DATA . '/office-stop.json') : null),
            'progress' => $p !== null ? jackProgressView($p, jackProgressSamples($paths['samples'], $p['started']), $now, $stopping !== null) : null];
}

/**
 * His step of the night at work (night.json: a real night, who `jack`, free|fill — the caller looked): his live view by
 * the night's word, never by his job's marker alone (2026-10-15: the owner saw her card say «Jack Streamer is at work —
 * each file on his page» for his whole give-back, no panel). A progress file older than this night (a run before that
 * didn't clear it) is none: «working out what goes where» until EmbyCache writes this run's.
 */
function jackNightProgress(array $night, ?array $paths = null, ?int $now = null, ?string $stopFile = null): array
{
    $s = jackProgressState(['running' => true, 'mode' => 'run'], $paths, $now, $stopFile);
    $from = (int) ($s['progress']['started'] ?? 0);
    if ($from > 0 && $from < (int) ($night['started'] ?? 0)) {
        $s['progress'] = null;
    }
    return $s;
}

/**
 * «Stop after this file» (his panel; 2026-10-10): only while a real EmbyCache run goes (run, release) — the same
 * stop request the mover guard writes (`office-stop.json`, EMBYCACHE_STOP), why `user`, once; EmbyCache ends after the
 * file it is on (exit 3, result `stopped`), jackRunWatch() takes the why from the file. Already asked: nothing new.
 * Returns whether it was written now.
 */
function jackStopAsk(?array $job = null, ?string $file = null): bool
{
    $job ??= jackJobInfo('embycache');
    $file ??= JACK_DATA . '/office-stop.json';
    if (empty($job['running']) || !in_array($job['mode'] ?? '', ['run', 'release'], true)) {
        throw new Problem('jack_stop_none');
    }
    clearstatcache(true, $file);
    if (file_exists($file) || is_link($file)) {
        return false;
    }
    writeAtomic($file, jsonEncode(['time' => time(), 'why' => 'user']), 0600, 0, 0);
    logLine('Jack Streamer: asked MediaCache (' . $job['mode'] . ') to stop after the file it is on — from his page');
    return true;
}
