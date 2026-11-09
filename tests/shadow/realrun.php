<?php
declare(strict_types=1);

/*
 * The real runs side by side on real servers (2026-11-09, agent/desks/jack-run.php; docs/DEVELOPMENT.md «jack»). Needs python3, a test
 * server (Tower), the media servers named in a MediaCache settings file and the share they show; never in the package, never on
 * nostromo. For every case the same starting state twice — the files of the share where they lay (the pool, each disk: size, owner,
 * mode, mtime), the share's ZFS dataset on the pool or none, his list and themes state — once Python's mediacache_run.py --run
 * (tests/shadow/pyrun.py), once PHP's jack-run.php --run from the tape of Python's dry twin (as the office runs it with php.json); the
 * end states compared: which file lies where (the pool or which disk), its size, owner, mode and mtime, the folders, the dataset, the
 * list, the themes state, status.json and the progress (no times), the exit code. Between the two, and at the end, the share is put
 * back exactly as it was (moved back by rsync to the very disk, the dataset destroyed when it wasn't there — only when empty).
 *
 *   php tests/shadow/realrun.php <settings.json> <share> [name-part …]
 */
$root = dirname(__DIR__, 2);
if (!defined('FILE_UID')) {
    define('FILE_UID', 0);
    define('FILE_GID', 0);
}
require "$root/agent/lib/util.php";
define('JACK_DATA', '/nonexistent');
require "$root/agent/desks/jack-plan.php";

[$base, $share] = [json_decode((string) file_get_contents($argv[1]), true), $argv[2]];
$only = array_slice($argv, 3);
$app = "$root/mediacache";
$work = '/tmp/jrun-real';
$data = "$work/data";
$pool = '/mnt/cache';
$disks = array_values(array_filter(glob('/mnt/disk[0-9]*') ?: [], 'is_dir'));

function say(string $s): void
{
    echo $s, "\n";
    @ob_flush();
    flush();
}

/** Where every file and folder of the share lies now: [rel => [where, kind, size, mode, uid, gid, mtime]], and its dataset on the pool */
function layout(string $share, string $pool, array $disks): array
{
    $out = [];
    foreach (array_merge([$pool], $disks) as $r) {
        $top = "$r/$share";
        if (!is_dir($top)) {
            continue;
        }
        $where = basename($r);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($top, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $p = (string) $f;
            $st = lstat($p);
            $rel = substr($p, strlen($top) + 1);
            $out["$where:$rel"] = [$where, is_dir($p) ? 'dir' : 'file', is_dir($p) ? 0 : $st['size'], sprintf('%o', $st['mode'] & 07777), $st['uid'], $st['gid'], is_dir($p) ? 0 : $st['mtime']];
        }
    }
    ksort($out, SORT_STRING);
    [$exit, $o] = run(['zfs', 'list', '-H', '-o', 'name', basename($pool) . "/$share"], 30);
    return ['files' => $out, 'dataset' => $exit === 0 && trim((string) $o) !== ''];
}

/** The share back exactly as in $l: every file to its very place (rsync -aAX, then the source gone), folders as they were, the dataset */
function putBack(array $l, string $share, string $pool, array $disks): void
{
    $now = layout($share, $pool, $disks);
    $want = [];
    foreach ($l['files'] as $k => $e) {
        if ($e[1] === 'file') {
            $want[substr($k, strlen($e[0]) + 1)] = $e;
        }
    }
    foreach ($now['files'] as $k => $e) {
        if ($e[1] !== 'file') {
            continue;
        }
        $rel = substr($k, strlen($e[0]) + 1);
        $w = $want[$rel] ?? null;
        $src = "/mnt/$e[0]/$share/$rel";
        if ($w === null) {
            fwrite(STDERR, "put back: $src was not there at the start — left\n");
            continue;
        }
        if ($w[0] === $e[0]) {
            continue;
        }
        $dst = "/mnt/$w[0]/$share/$rel";
        @mkdir(dirname($dst), 0777, true);
        [$x] = run(['rsync', '-aAX', '--numeric-ids', $src, $dst], 3600);
        if ($x !== 0 || filesize($dst) !== filesize($src)) {
            fwrite(STDERR, "put back: rsync $src failed\n");
            exit(3);
        }
        unlink($src);
    }
    // folders: what wasn't there goes (bottom up, only when empty), what was is there with its owner and mode
    $now = layout($share, $pool, $disks);
    $dirs = array_reverse(array_keys(array_filter($now['files'], fn (array $e): bool => $e[1] === 'dir')));
    foreach ($dirs as $k) {
        if (!isset($l['files'][$k])) {
            @rmdir('/mnt/' . str_replace(':', "/$share/", $k));
        }
    }
    foreach ($l['files'] as $k => $e) {
        $p = '/mnt/' . str_replace(':', "/$share/", $k);
        if ($e[1] === 'dir') {
            @mkdir($p, 0777, true);
            chmod($p, octdec($e[3]));
            chown($p, $e[4]);
            chgrp($p, $e[5]);
        }
    }
    $ds = basename($pool) . "/$share";
    if (!$l['dataset'] && layout($share, $pool, $disks)['dataset']) {
        [, $o] = run(['find', "$pool/$share", '-mindepth', '1'], 60);
        if (trim((string) $o) !== '') {
            fwrite(STDERR, "put back: $pool/$share not empty — the dataset stays:\n$o\n");
            exit(3);
        }
        run(['zfs', 'destroy', $ds], 120);
    } elseif ($l['dataset'] && !layout($share, $pool, $disks)['dataset']) {
        run(['zfs', 'create', $ds], 120);
    }
    // ZFS frees what was deleted a moment later: wait until the pool's numbers stand still (both runs must see the same pool)
    run(['zpool', 'sync', basename($pool)], 60);
    for ($i = 0, $last = null; $i < 30; $i++) {
        [, $o] = run(['zfs', 'list', '-Hp', '-o', 'used,avail', basename($pool)], 30);
        if ($o === $last) {
            break;
        }
        $last = $o;
        sleep(2);
    }
    $again = layout($share, $pool, $disks);
    if ($again !== $l) {
        $d = [];
        foreach (array_unique(array_merge(array_keys($l['files']), array_keys($again['files']))) as $k) {
            if (($l['files'][$k] ?? null) !== ($again['files'][$k] ?? null)) {
                $d[] = "$k: " . json_encode($l['files'][$k] ?? null) . ' / ' . json_encode($again['files'][$k] ?? null);
            }
        }
        if ($l['dataset'] !== $again['dataset']) {
            $d[] = 'dataset';
        }
        if ($d) {
            fwrite(STDERR, "put back: not as it was:\n" . implode("\n", array_slice($d, 0, 20)) . "\n");
            exit(3);
        }
    }
}

function noTimes(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    foreach (['started', 'finished', 'updated', 'ended', 'since', 'version', 'engine'] as $k) {
        if (array_key_exists($k, $v) && !array_is_list($v)) {
            $v[$k] = in_array($k, ['version', 'engine'], true) ? null : ($v[$k] === null ? null : 'T');
        }
    }
    return array_map('noTimes', $v);
}

function stateDiff(array $a, array $b, string $path = ''): array
{
    $out = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        $p = $path === '' ? (string) $k : "$path.$k";
        $x = $a[$k] ?? null;
        $y = $b[$k] ?? null;
        if (is_array($x) && is_array($y) && !array_is_list($x) && !array_is_list($y)) {
            array_push($out, ...stateDiff($x, $y, $p));
        } elseif ($x !== $y) {
            $out[] = [$p, $x, $y];
        }
    }
    return $out;
}

/** His data folder as the case starts: the settings, and the list and themes state of $state (or none) */
function startData(string $data, array $settings, ?array $state): void
{
    exec('rm -rf ' . escapeshellarg($data));
    mkdir("$data/logs", 0700, true);
    file_put_contents("$data/mediacache_settings.json", json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    chmod("$data/mediacache_settings.json", 0600);
    foreach ($state ?? [] as $f => $text) {
        if ($text !== null) {
            file_put_contents("$data/$f", $text);
        }
    }
}

function dataState(string $data): array
{
    $themes = json_decode((string) @file_get_contents("$data/mediacache_themes.json"), true);
    if (is_array($themes)) {
        unset($themes['first'], $themes['collected'], $themes['off']);
        foreach ((array) ($themes['items'] ?? []) as $k => $v) {
            if (is_array($v) && isset($v[3])) {
                $themes['items'][$k][3] = 0;
            }
        }
    }
    return ['list' => @file_get_contents("$data/mediacache_exclude.txt"), 'themes' => $themes];
}

/** One real run: [exit, layout, data, status, progress, output] */
function realRun(string $who, string $mode, array $env, string $work, string $data, string $app, string $root, string $share, string $pool, array $disks): array
{
    foreach (['status', 'progress', 'live', 'plan'] as $f) {
        @unlink("$work/$who-$f.json");
    }
    $full = $env + ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8', 'PYTHONDONTWRITEBYTECODE' => '1',
        'PYTHONIOENCODING' => 'utf-8', 'PYTHONUNBUFFERED' => '1', 'MEDIACACHE_DIR' => $data, 'MEDIACACHE_RESULT' => "$work/$who-status.json", 'MEDIACACHE_PROGRESS' => "$work/$who-progress.json",
        'MEDIACACHE_STOP' => "$work/stop.json", 'MEDIACACHE_LIVE_STATUS' => "$work/$who-live.json", 'MEDIACACHE_RSYNC_ARGS' => '-aAX --numeric-ids'];
    $flag = $mode === 'release' ? '--release' : '--run';
    if ($who === 'python') {
        $cmd = ['python3', __DIR__ . '/pyrun.py', $app, $flag];
        $cwd = $app;
    } else {
        $tape = "$work/tape.jsonl";
        @unlink($tape);
        $twin = array_diff_key($full, array_flip(['MEDIACACHE_RESULT', 'MEDIACACHE_STOP', 'MEDIACACHE_PROGRESS']));
        $p = proc_open(['python3', "$app/mediacache_shadow.py", '--tape', $tape, '--plan', "$work/twin.json", '--logs', "$work/twin-logs", '--mode', $mode],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "$work/twin.err", 'w']], $pipes, $app, $twin);
        proc_close($p);
        $cmd = array_merge([PHP_BINARY, "$root/agent/desks/jack-run.php", $flag, '--plan-out', "$work/$who-plan.json"], is_file($tape) ? ['--tape', $tape] : []);
        $cwd = '/';
    }
    $t0 = hrtime(true);
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$work/$who.out", 'w'], 2 => ['file', "$work/$who.out", 'a']], $pipes, $cwd, $full);
    $exit = proc_close($p);
    $secs = (hrtime(true) - $t0) / 1e9;
    return [$exit, layout($share, $pool, $disks), dataState($data), noTimes(json_decode((string) @file_get_contents("$work/$who-status.json"), true)),
            noTimes(json_decode((string) @file_get_contents("$work/$who-progress.json"), true)), (string) @file_get_contents("$work/$who.out"), $secs];
}

// ---------------------------------------------------------------- the cases
@mkdir($work, 0700, true);
$settings = fn (array $over = []): array => $over + $base;
$byType = fn (array $types): array => array_values(array_filter($base['instances'], fn (array $i): bool => in_array($i['type'], $types, true)));
$mapsOff = function (array $inst, string $sub): array {
    $m = (array) $inst['path_mappings'];
    $m[array_key_first(array_filter($m, fn ($h) => str_ends_with((string) $h, "/$sub")))] = '';
    $inst['path_mappings'] = $m;
    return $inst;
};
// theme.mp3 in three film folders and a series folder on the first disk (taken away again at the end): the collector has work
$themes = [];
foreach (array_slice(glob("$disks[0]/$share/*/*", GLOB_ONLYDIR) ?: [], 0, 4) as $folder) {
    if (!is_file("$folder/theme.mp3")) {
        file_put_contents("$folder/theme.mp3", str_repeat("\xff\xfb\x90\x00", 4096));
        chown("$folder/theme.mp3", 99);
        chgrp("$folder/theme.mp3", 100);
        touch("$folder/theme.mp3", 1700000000);
        $themes[] = substr("$folder/theme.mp3", strlen("$disks[0]/$share/"));
    }
}
$cold = layout($share, $pool, $disks);
say(sprintf('start: %d files of «%s» on %s, its dataset on the pool: %s', count(array_filter($cold['files'], fn ($e) => $e[1] === 'file')), $share,
    implode(', ', array_unique(array_map(fn ($e) => $e[0], $cold['files']))), $cold['dataset'] ? 'yes' : 'no'));
$usage = function () use ($pool): array {
    [, $o] = run(['zfs', 'list', '-Hp', '-o', 'used,avail', basename($pool)], 30);
    [$u, $a] = array_map('intval', preg_split('/\s+/', trim((string) $o)) ?: [0, 0]);
    return [$u + $a, $a];
};

// a warm start: what a first run left (Python's), as the second run of a night finds it
$warm = null;
$warmData = null;
$makeWarm = function (array $s) use (&$warm, &$warmData, $data, $work, $app, $root, $share, $pool, $disks, $cold): void {
    putBack($cold, $share, $pool, $disks);
    startData($data, $s, null);
    @unlink("$work/stop.json");
    realRun('python', 'run', ['MEDIACACHE_GIVE_BACK_FREE' => '1'], $work, $data, $app, $root, $share, $pool, $disks);
    $warm = layout($share, $pool, $disks);
    $warmData = ['mediacache_exclude.txt' => @file_get_contents("$data/mediacache_exclude.txt"), 'mediacache_themes.json' => @file_get_contents("$data/mediacache_themes.json") ?: null];
    say(sprintf('warm start: %d files on the pool', count(array_filter($warm['files'], fn ($e) => $e[1] === 'file' && $e[0] === basename($pool)))));
};

$cases = [
    'emby alone: from the array, the share\'s dataset made' => ['settings' => ['instances' => $byType(['emby'])]],
    'jellyfin alone: from the array' => ['settings' => ['instances' => $byType(['jellyfin'])]],
    'plex alone: from the array' => ['settings' => ['instances' => $byType(['plex'])]],
    'all three: from the array, budget 2 GB' => ['settings' => ['cache_budget' => '2G']],
    'all three: count mode' => ['settings' => ['cache_budget' => '']],
    'all three: parallel, one per disk' => ['settings' => ['cache_budget' => '2G'], 'env' => ['MEDIACACHE_PARALLEL_TOTAL' => '2']],
    'all three: array_source disk' => ['settings' => ['cache_budget' => '2G', 'array_source' => 'disk']],
    'all three: Familienserien deliberately not cached (an empty mapping)' => ['settings' => ['cache_budget' => '2G', 'instances' => array_map(fn ($i) => $mapsOff($i, 'Familienserien'), $base['instances'])]],
    'all three: budget too small' => ['settings' => ['cache_budget' => '100M']],
    'all three: min free — the fill stops' => ['settings' => ['cache_budget' => '20G'], 'env' => ['MEDIACACHE_MIN_FREE_PERCENT' => 'FILLSTOP']],
    'warm: the budget halved — what is beyond goes back (move binary)' => ['warm' => true, 'settings' => ['cache_budget' => 'HALF']],
    'warm: the budget halved, cleanup by rsync' => ['warm' => true, 'settings' => ['cache_budget' => 'HALF', 'cleanup_tool' => 'rsync']],
    'warm: give back by rank (min free), his half kept' => ['warm' => true, 'env' => ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => 'GIVEBACK', 'MEDIACACHE_KEEP_BYTES' => 'HALF']],
    'warm: give back by rank (min free)' => ['warm' => true, 'env' => ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => 'GIVEBACK']],
    'warm: the joint run\'s first step (no fill, give back)' => ['warm' => true, 'settings' => ['cache_budget' => 'HALF'], 'env' => ['MEDIACACHE_NO_FILL' => '1', 'MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => 'GIVEBACK']],
    'warm: watched only' => ['warm' => true, 'settings' => ['cache_budget' => 'HALF'], 'env' => ['MEDIACACHE_WATCHED_ONLY' => '1', 'MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => 'GIVEBACK']],
    'warm: --release, everything back' => ['warm' => true, 'mode' => 'release'],
    'warm: --release with themes, they stay' => ['warm' => true, 'mode' => 'release', 'settings' => ['themes' => true], 'warmSettings' => ['themes' => true]],
    'warm: --release with themes and RELEASE_THEMES, they go too' => ['warm' => true, 'mode' => 'release', 'settings' => ['themes' => true], 'warmSettings' => ['themes' => true], 'env' => ['MEDIACACHE_RELEASE_THEMES' => '1']],
    'warm: themes switched off — the next run brings them back' => ['warm' => true, 'warmSettings' => ['themes' => true]],
    'warm: the stop file there: stopped at once' => ['warm' => true, 'settings' => ['cache_budget' => 'HALF'], 'stop' => true],
    'warm: a second run, nothing to do' => ['warm' => true],
    'themes on: from the array (theme.mp3 collected)' => ['settings' => ['cache_budget' => '1G', 'themes' => true]],
];
$failed = $ran = 0;
foreach ($cases as $name => $c) {
    if ($only && !array_filter($only, fn (string $o): bool => str_contains($name, $o))) {
        continue;
    }
    $s = $settings($c['settings'] ?? []);
    if (!empty($c['warm'])) {
        $makeWarm($settings(($c['warmSettings'] ?? []) + ['cache_budget' => '2G']));
        $start = $warm;
        $startState = $warmData;
    } else {
        $start = $cold;
        $startState = null;
    }
    // the pool's limit at this start: GIVEBACK = about 400 MB above what is free, FILLSTOP = a gigabyte left for the fill
    putBack($start, $share, $pool, $disks);
    [$total, $free] = $usage();
    // what lies on the pool at this start; GIVEBACK: the pool's limit just over half of it, FILLSTOP: half of the fill fits, HALF: half of it
    $held = array_sum(array_map(fn ($e) => $e[1] === 'file' && $e[0] === basename($pool) ? $e[2] : 0, $start['files']));
    $half = max(1, intdiv($held, 2));
    $env = array_map(fn ($v) => match ($v) { 'GIVEBACK' => sprintf('%.6f', ($free + $half) / $total * 100), 'FILLSTOP' => sprintf('%.6f', ($free - 12 * 2 ** 20) / $total * 100),
        'HALF' => (string) $half, default => $v }, $c['env'] ?? []);
    if (($s['cache_budget'] ?? null) === 'HALF') {
        $s['cache_budget'] = (string) $half;
    }
    $mode = $c['mode'] ?? 'run';
    $one = function (string $who) use ($c, $s, $start, $startState, $mode, $env, $work, $data, $app, $root, $share, $pool, $disks): array {
        putBack($start, $share, $pool, $disks);
        startData($data, $s, $startState);
        @unlink("$work/stop.json");
        if (!empty($c['stop'])) {
            file_put_contents("$work/stop.json", '{}');
        }
        return realRun($who, $mode, $env, $work, $data, $app, $root, $share, $pool, $disks);
    };
    $py = $one('python');
    $php = $one('php');
    $ran++;
    $diff = [];
    if ($py[0] !== $php[0]) {
        $diff[] = ['exit', $py[0], $php[0]];
    }
    array_push($diff, ...stateDiff(['files' => $py[1], 'data' => $py[2], 'status' => $py[3], 'progress' => $py[4]], ['files' => $php[1], 'data' => $php[2], 'status' => $php[3], 'progress' => $php[4]]));
    $onPool = count(array_filter($py[1]['files'], fn ($e) => $e[1] === 'file' && $e[0] === basename($pool)));
    say(($diff ? 'DIFF  ' : 'same  ') . sprintf('%-70s exit %d %-7s back %3s fill %3s on the pool %3d  (%.0f s / %.0f s)', $name, $py[0], $py[3]['result'] ?? '?',
        $py[3]['cleanup']['done'] ?? '-', $py[3]['fill']['done'] ?? '-', $onPool, $py[6], $php[6]));
    foreach (array_slice($diff, 0, 12) as [$p, $x, $y]) {
        say("      $p\n        python " . json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n        php    " . json_encode($y, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    if ($diff) {
        $failed++;
        @mkdir("$work/kept");
        file_put_contents("$work/kept/" . substr(md5($name), 0, 8) . '.txt', "== $name\n== python\n$py[5]\n== php\n$php[5]\n");
    }
}
putBack($cold, $share, $pool, $disks);
foreach ($themes as $rel) {
    @unlink("$disks[0]/$share/$rel");
}
exec('rm -rf ' . escapeshellarg($data) . ' ' . escapeshellarg("$work/tape.jsonl"));      // the settings hold the servers' keys
say(($failed ? "$failed of $ran DIFFER" : "all $ran the same") . ' — the share put back as it was');
exit($failed ? 1 : 0);
