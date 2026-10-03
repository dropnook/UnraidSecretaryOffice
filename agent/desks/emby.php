<?php
declare(strict_types=1);

/*
 * Jack Emby — the intern who loves Emby (say it fast: "check Emby"). He looks after EmbyCache
 * (github.com/helmi1987/embycache-for-unraid): it keeps what people are about
 * to watch on the fast pool, so the array disks can sleep.
 *
 * EmbyCache is never changed here. Jack fetches it from its public
 * repository (git clone into data/embycache/app), updates it with a
 * fast-forward pull, and keeps its data apart (EMBYCACHE_DIR =
 * data/embycache: settings, exclude list, lock, logs) — an update never
 * touches the settings. Settings are written with EmbyCache's own
 * save_config(), runs are started through the host's atd.
 *
 * The Emby API key stays on the server: it is never part of the state the
 * web page gets.
 */

const EMBY_REPO     = 'https://github.com/helmi1987/embycache-for-unraid.git';
const EMBY_BRANCH   = 'main';
const EMBY_FILES    = ['embycache_lib.py', 'embycache_run.py', 'embycache_setup.py', 'embycache_cleaner.py'];
const EMBY_LOG_TAIL = 96 * 1024;
const EMBY_MODES    = ['report' => ['--show-on-deck', '--compact'], 'dry' => [], 'run' => ['--run']];
const EMBY_SETTINGS = ['cache_path', 'cache_budget', 'number_episodes', 'movie_share_percent', 'max_episodes_per_series',
                       'max_resume_items', 'max_favorite_series', 'use_next_up', 'min_free_percent', 'movie_mode',
                       'fill_tool', 'cleanup_tool'];

define('EMBY_DATA', DATA_DIR . '/embycache');
define('EMBY_APP', EMBY_DATA . '/app');
define('EMBY_UPDATE', DATA_DIR . '/emby-update.json');

desk('emby', [
    'start'   => fn () => embyScan(),
    'actions' => [
        'refresh'       => fn (array $r) => ['ok' => true, 'state' => embyScan()],
        'install'       => fn (array $r) => embyInstall(),
        'check_updates' => fn (array $r) => embyCheckUpdates(),
        'update'        => fn (array $r) => embyUpdate(),
        'connect'       => fn (array $r) => embyConnect(textField($r, 'url'), (string) ($r['api_key'] ?? '')),
        'save'          => fn (array $r) => embySave($r['settings'] ?? null),
        'start_run'     => fn (array $r) => embyStart(textField($r, 'mode')),
        'output'        => fn (array $r) => embyOutput(),
        'log'           => fn (array $r) => embyLog(),
    ],
    'checks'  => fn () => embyChecks(),
]);

// ===================================================================== state

function embyScan(): array
{
    $installed = is_file(EMBY_APP . '/embycache_run.py');
    $settings = embyReadSettings();
    $state = [
        'time'      => time(),
        'python'    => embyPython(),
        'emby'      => embyContainers(),
        'installed' => $installed,
        'version'   => $installed ? embyVersion() : null,
        'update'    => readJson(EMBY_UPDATE),
        'configured' => $settings !== null && !empty($settings['instances']),
        'settings'  => $settings !== null ? embySettingsPublic($settings) : null,
        'running'   => flockHeld(EMBY_DATA . '/embycache.lock'),
        'cache'     => embyCacheStats($settings),
        'last'      => embyLastRun(),
        'schedule'  => embySchedule(),
        'output'    => embyOutputInfo(),
        'pools'     => embyPools(),
        'data_dir'  => EMBY_DATA,
        'app_dir'   => EMBY_APP,
    ];
    writeAtomic(deskFile('emby'), jsonEncode($state));
    return $state;
}

function embyPython(): ?string
{
    [$exit, $out] = run(['python3', '--version'], 10);
    return $exit === 0 ? trim(str_replace('Python', '', $out)) : null;
}

/** Emby containers with what the setup needs: address and the folders they see */
function embyContainers(): array
{
    $found = [];
    foreach (houseContainers() as $c) {
        if (!preg_match('#(^|/)emby(server)?(:|$)|embyserver#i', $c['image'])) {
            continue;
        }
        $inspect = houseInspect($c['name']) ?? [];
        $ip = null;
        foreach ((array) ($inspect['NetworkSettings']['Networks'] ?? []) as $net) {
            $ip = ($net['IPAddress'] ?? '') ?: $ip;
        }
        $port = 8096;
        foreach ((array) ($inspect['HostConfig']['PortBindings']['8096/tcp'] ?? []) as $b) {
            if (!empty($b['HostPort'])) {
                $port = (int) $b['HostPort'];
                $ip = null;          // published on the host
            }
        }
        $mounts = [];
        foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
            $dest = (string) ($m['Destination'] ?? '');
            if ($dest !== '' && !preg_match('#^/(config|dev|tmp|cache|transcode)#', $dest)) {
                $mounts[$dest] = (string) ($m['Source'] ?? '');
            }
        }
        $found[] = ['name' => $c['name'], 'image' => $c['image'], 'running' => $c['running'],
                    'url' => 'http://' . ($ip ?? embyHostIp()) . ':' . $port, 'mounts' => $mounts];
    }
    return $found;
}

function embyHostIp(): string
{
    $url = houseGuiUrl() ?? 'http://127.0.0.1';
    return (string) parse_url($url, PHP_URL_HOST);
}

/** The pools EmbyCache could cache onto */
function embyPools(): array
{
    $pools = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !preg_match('/^(disk\d+|user0?|disks|remotes|addons|rootshare)$/', $x[1])
            && in_array($m['fs'], ['zfs', 'btrfs', 'xfs'], true)) {
            $pools[$m['mount']] = true;
        }
    }
    ksort($pools);
    return array_keys($pools);
}

function embyVersion(): array
{
    $lib = (string) @file_get_contents(EMBY_APP . '/embycache_lib.py', false, null, 0, 4096);
    [$exit, $out] = run(['git', '-C', EMBY_APP, 'log', '-1', '--format=%h%x09%ct%x09%s'], 10);
    [$h, $t, $s] = $exit === 0 ? explode("\t", trim($out), 3) + [null, null, null] : [null, null, null];
    [$e2, $dirty] = run(['git', '-C', EMBY_APP, 'status', '--porcelain', '--untracked-files=no'], 10);
    return [
        'version' => preg_match('/__version__\s*=\s*"([^"]+)"/', $lib, $m) ? $m[1] : null,
        'commit'  => $h,
        'time'    => $t ? (int) $t : null,
        'subject' => $s,
        'changed' => $e2 === 0 && trim($dirty) !== '',
    ];
}

function embyReadSettings(): ?array
{
    $j = json_decode((string) @file_get_contents(EMBY_DATA . '/embycache_settings.json'), true);
    return is_array($j) ? $j : null;
}

/** The settings as the page may see them — without the API keys */
function embySettingsPublic(array $s): array
{
    $instances = [];
    foreach ($s['instances'] ?? [] as $i) {
        $instances[] = ['servername' => (string) ($i['servername'] ?? ''), 'url' => (string) ($i['url'] ?? ''),
                        'has_key' => !empty($i['api_key']), 'path_mappings' => (array) ($i['path_mappings'] ?? [])];
    }
    $out = ['instances' => $instances, 'libraries' => array_values((array) ($s['libraries'] ?? [])),
            'valid_users' => $s['valid_users'] ?? []];
    foreach (EMBY_SETTINGS as $k) {
        $out[$k] = $s[$k] ?? null;
    }
    return $out;
}

/** What EmbyCache keeps on the pool right now (its exclude list: absolute pool paths) */
function embyCacheStats(?array $settings): array
{
    $list = @file(EMBY_DATA . '/embycache_exclude.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $bytes = 0;
    $groups = [];
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    foreach ($list as $path) {
        $size = (int) @filesize($path);
        $bytes += $size;
        // group by share and the first folder below it (a film folder or a series)
        $rel = $cache !== '' && str_starts_with($path, "$cache/") ? substr($path, strlen($cache) + 1) : ltrim($path, '/');
        $parts = explode('/', $rel);
        $key = $parts[0] . '/' . ($parts[1] ?? '');
        $groups[$key] ??= ['share' => $parts[0], 'title' => $parts[1] ?? $parts[0], 'files' => 0, 'bytes' => 0];
        $groups[$key]['files']++;
        $groups[$key]['bytes'] += $size;
    }
    usort($groups, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
    return ['files' => count($list), 'bytes' => $bytes, 'groups' => array_slice(array_values($groups), 0, 200),
            'listed_at' => @filemtime(EMBY_DATA . '/embycache_exclude.txt') ?: null];
}

/** The last run from EmbyCache's log: when, what kind, the result lines */
function embyLastRun(): ?array
{
    $file = EMBY_DATA . '/logs/embycache.log';
    $size = (int) @filesize($file);
    if (!$size) {
        return null;
    }
    $h = fopen($file, 'r');
    fseek($h, max(0, $size - EMBY_LOG_TAIL));
    $tail = (string) stream_get_contents($h);
    fclose($h);
    $last = null;
    $results = [];
    $errors = 0;
    $warnings = 0;
    foreach (explode("\n", $tail) as $line) {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d),\d+ \| (\w+) \| (.*)$/', $line, $m)) {
            continue;
        }
        $last = strtotime($m[1]) ?: $last;
        if ($m[2] === 'ERROR') {
            $errors++;
        } elseif ($m[2] === 'WARNING') {
            $warnings++;
        }
        // the summary lines EmbyCache writes at the end of every run
        if (preg_match('/^(Ergebnis (Cleanup|Befüllen):|Dry-Run: )/u', $m[3])) {
            $results[] = trim($m[3]);
            if (str_starts_with($m[3], 'Dry-Run: ') || str_starts_with($m[3], 'Ergebnis Cleanup:')) {
                $results = [trim($m[3])];     // a new run starts its summary
                $errors = $warnings = 0;
            }
        }
    }
    return ['time' => $last, 'results' => $results, 'errors' => $errors, 'warnings' => $warnings];
}

/** A User Scripts entry that runs EmbyCache, and its schedule */
function embySchedule(): array
{
    $plans = (array) json_decode((string) @file_get_contents('/boot/config/plugins/user.scripts/schedule.json'), true);
    foreach (glob('/boot/config/plugins/user.scripts/scripts/*/script') ?: [] as $file) {
        $text = (string) @file_get_contents($file, false, null, 0, 8192);
        if (str_contains($text, 'embycache_run.py')) {
            $plan = $plans[$file] ?? [];
            $freq = (string) ($plan['frequency'] ?? 'disabled');
            return ['script' => basename(dirname($file)), 'frequency' => $freq, 'custom' => $plan['custom'] ?? null,
                    'enabled' => !in_array($freq, ['', 'disabled'], true), 'ours' => str_contains($text, EMBY_DATA)];
        }
    }
    return ['script' => null, 'enabled' => false];
}

// ===================================================================== fetching and updating

function embyInstall(): array
{
    if (is_dir(EMBY_APP . '/.git')) {
        throw new Problem('emby_installed');
    }
    embyDataDir();
    [$exit, , $err] = hostNet(['git', 'clone', '--quiet', '--branch', EMBY_BRANCH, EMBY_REPO, EMBY_APP], 180);
    if ($exit !== 0) {
        throw new Problem('emby_git', ['detail' => trim($err)]);
    }
    logLine('Jack Emby: fetched EmbyCache from ' . EMBY_REPO);
    @unlink(EMBY_UPDATE);
    return ['ok' => true, 'state' => embyScan()];
}

/** settings, exclude list and logs hold the API key and file names: root only */
function embyDataDir(): void
{
    @mkdir(EMBY_DATA, 0700, true);
    @chmod(EMBY_DATA, 0700);
    @chown(EMBY_DATA, 0);
}

function embyCheckUpdates(): array
{
    embyNeedApp();
    [$exit, , $err] = hostNet(['git', '-C', EMBY_APP, 'fetch', '--quiet', 'origin', EMBY_BRANCH], 120);
    if ($exit !== 0) {
        throw new Problem('emby_git', ['detail' => trim($err)]);
    }
    [, $count] = run(['git', '-C', EMBY_APP, 'rev-list', '--count', 'HEAD..FETCH_HEAD'], 10);
    [, $log] = run(['git', '-C', EMBY_APP, 'log', '--format=%h%x09%ct%x09%s', '-n', '20', 'HEAD..FETCH_HEAD'], 10);
    $commits = [];
    foreach (rows($log) as $f) {
        $commits[] = ['commit' => $f[0], 'time' => (int) ($f[1] ?? 0), 'subject' => $f[2] ?? ''];
    }
    $info = ['checked' => time(), 'behind' => (int) trim($count), 'commits' => $commits];
    writeAtomic(EMBY_UPDATE, jsonEncode($info));
    return ['ok' => true, 'state' => embyScan()];
}

function embyUpdate(): array
{
    embyNeedApp();
    if (flockHeld(EMBY_DATA . '/embycache.lock')) {
        throw new Problem('emby_running');
    }
    if (embyVersion()['changed']) {
        throw new Problem('emby_local_changes');    // somebody edited the code here — never overwrite that
    }
    [$exit, , $err] = hostNet(['git', '-C', EMBY_APP, 'pull', '--quiet', '--ff-only', 'origin', EMBY_BRANCH], 180);
    if ($exit !== 0) {
        throw new Problem('emby_git', ['detail' => trim($err)]);
    }
    logLine('Jack Emby: updated EmbyCache to ' . (embyVersion()['commit'] ?? '?'));
    @unlink(EMBY_UPDATE);
    return ['ok' => true, 'state' => embyScan()];
}

function embyNeedApp(): void
{
    if (!is_file(EMBY_APP . '/embycache_run.py')) {
        throw new Problem('emby_not_installed');
    }
}

// ===================================================================== setup

/**
 * Talks to an Emby server: does it answer, which libraries and users does it
 * have. Without a new key the stored one for this address is used, so the
 * page never needs to know it.
 */
function embyConnect(string $url, string $key): array
{
    $url = rtrim($url, '/');
    if (!preg_match('#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#', $url)) {
        throw new Problem('emby_bad_url');
    }
    if ($key === '') {
        foreach (embyReadSettings()['instances'] ?? [] as $i) {
            if (rtrim((string) ($i['url'] ?? ''), '/') === $url) {
                $key = (string) ($i['api_key'] ?? '');
            }
        }
    }
    if ($key === '' || !preg_match('/^[A-Za-z0-9]{8,128}$/', $key)) {
        throw new Problem('emby_need_key');
    }
    $info = embyApi($url, $key, '/System/Info');
    $libs = [];
    foreach ((array) embyApi($url, $key, '/Library/VirtualFolders') as $lib) {
        $locs = array_values(array_filter((array) ($lib['Locations'] ?? []), fn ($l) => !preg_match('#^/(config|metadata|transcoding-temp|cache|logs|var|boot|tmp)#', (string) $l)));
        if ($locs) {
            $libs[] = ['name' => (string) ($lib['Name'] ?? ''), 'type' => (string) ($lib['CollectionType'] ?? ''), 'locations' => $locs];
        }
    }
    $users = [];
    foreach ((array) embyApi($url, $key, '/Users') as $u) {
        $users[] = ['id' => (string) ($u['Id'] ?? ''), 'name' => (string) ($u['Name'] ?? ''), 'admin' => !empty($u['Policy']['IsAdministrator'])];
    }
    return ['ok' => true, 'server' => ['name' => (string) ($info['ServerName'] ?? ''), 'version' => (string) ($info['Version'] ?? '')],
            'libraries' => $libs, 'users' => $users];
}

/** One GET to the Emby API, through the host's network; the key goes in a header file, not on a command line */
function embyApi(string $url, string $key, string $path): mixed
{
    @mkdir(RUN_DIR, 0700, true);
    $headers = RUN_DIR . '/emby-headers.' . getmypid();
    file_put_contents($headers, "X-Emby-Token: $key\nAccept: application/json\n");
    chmod($headers, 0600);
    try {
        [$exit, $out, $err] = hostNet(['curl', '-s', '-S', '-f', '-m', '15', '-H', "@$headers", $url . $path], 20);
    } finally {
        @unlink($headers);
    }
    if ($exit !== 0) {
        throw new Problem(str_contains($err, '401') || str_contains($err, '403') ? 'emby_bad_key' : 'emby_unreachable',
            ['url' => $url, 'detail' => trim($err)]);
    }
    $j = json_decode($out, true);
    if (!is_array($j)) {
        throw new Problem('emby_unreachable', ['url' => $url, 'detail' => 'no JSON']);
    }
    return $j;
}

/**
 * Takes the choices of the setup form, lays them over the current settings
 * and lets EmbyCache's own save_config() write them (it checks and fills in
 * its defaults). Its load_config() must accept the result.
 */
function embySave(mixed $in): array
{
    embyNeedApp();
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'settings']);
    }
    $cur = embyReadSettings() ?? [];
    $old = [];
    foreach ($cur['instances'] ?? [] as $i) {
        $old[rtrim((string) ($i['url'] ?? ''), '/')] = (string) ($i['api_key'] ?? '');
    }
    $instances = [];
    foreach ((array) ($in['instances'] ?? []) as $i) {
        $url = rtrim((string) ($i['url'] ?? ''), '/');
        $key = (string) ($i['api_key'] ?? '') ?: ($old[$url] ?? '');
        if (!preg_match('#^https?://\S+$#', $url) || !preg_match('/^[A-Za-z0-9]{8,128}$/', $key)) {
            throw new Problem('emby_need_key');
        }
        $maps = [];
        foreach ((array) ($i['path_mappings'] ?? []) as $from => $to) {
            if (is_string($from) && is_string($to) && str_starts_with($from, '/') && str_starts_with($to, '/mnt/')) {
                $maps[$from] = rtrim($to, '/');
            }
        }
        $instances[] = ['servername' => substr(trim((string) ($i['servername'] ?? 'Emby')), 0, 60) ?: 'Emby',
                        'url' => $url, 'api_key' => $key, 'path_mappings' => $maps];
    }
    if (!$instances) {
        throw new Problem('emby_need_key');
    }
    $cfg = $cur;
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];
    $cfg['libraries'] = array_values(array_filter((array) ($in['libraries'] ?? []), 'is_string'));
    $users = (array) ($in['valid_users'] ?? []);
    $cfg['valid_users'] = array_is_list($users) ? array_values(array_filter($users, 'is_string')) : $users;
    foreach (EMBY_SETTINGS as $k) {
        if (array_key_exists($k, $in)) {
            $cfg[$k] = $in[$k];
        }
    }
    if (!in_array($cfg['cache_path'] ?? '', embyPools(), true)) {
        throw new Problem('emby_bad_pool', ['path' => (string) ($cfg['cache_path'] ?? '')]);
    }

    embyDataDir();
    // save_config() writes before load_config() checks: let it write a trial file
    // (EMBYCACHE_CONFIG) and only put that in place once EmbyCache accepts it
    $in = RUN_DIR . '/emby-settings.' . getmypid() . '.json';
    $trial = EMBY_DATA . '/.embycache_settings.trial.json';
    file_put_contents($in, json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    chmod($in, 0600);
    @unlink($trial);
    $py = 'import json, sys; sys.path.insert(0, sys.argv[1]); import embycache_lib as l; '
        . 'l.save_config(json.load(open(sys.argv[2], encoding="utf-8"))); l.load_config()';
    try {
        [$exit, , $err] = runEnv(['python3', '-c', $py, EMBY_APP, $in], ['EMBYCACHE_DIR' => EMBY_DATA, 'EMBYCACHE_CONFIG' => $trial], 30);
    } finally {
        @unlink($in);
    }
    if ($exit !== 0 || !is_file($trial)) {
        @unlink($trial);
        $lines = array_filter(explode("\n", trim($err)));
        throw new Problem('emby_config', ['detail' => (string) end($lines)]);
    }
    chmod($trial, 0600);
    rename($trial, EMBY_DATA . '/embycache_settings.json');
    logLine('Jack Emby: EmbyCache settings saved');
    return ['ok' => true, 'state' => embyScan()];
}

/** run() with a few extra environment variables */
function runEnv(array $command, array $env, int $timeout = 60): array
{
    $vars = [];
    foreach ($env as $k => $v) {
        $vars[] = "$k=$v";
    }
    return run(array_merge(['env'], $vars, $command), $timeout);
}

// ===================================================================== runs

/** report (what's up next), dry (plan, change nothing) or run (move files) — through the host's atd */
function embyStart(string $mode): array
{
    embyNeedApp();
    if (!isset(EMBY_MODES[$mode])) {
        throw new Problem('unknown_target', ['target' => $mode]);
    }
    if (!embyReadSettings()) {
        throw new Problem('emby_not_configured');
    }
    if (flockHeld(EMBY_DATA . '/embycache.lock')) {
        throw new Problem('emby_running');
    }
    $out = EMBY_DATA . '/office-output.txt';
    @unlink($out);
    writeAtomic(EMBY_DATA . '/office-output.json', jsonEncode(['mode' => $mode, 'started' => time()]), 0600, 0, 0);
    hostLaunch('emby-job', array_merge(['python3', EMBY_APP . '/embycache_run.py'], EMBY_MODES[$mode]),
        ['EMBYCACHE_DIR' => EMBY_DATA], $out, EMBY_APP);
    logLine("Jack Emby: started EmbyCache ($mode) via at");
    usleep(800000);
    return ['ok' => true, 'state' => embyScan()];
}

function embyOutputInfo(): ?array
{
    $info = readJson(EMBY_DATA . '/office-output.json');
    if (!$info) {
        return null;
    }
    $file = EMBY_DATA . '/office-output.txt';
    $info['size'] = (int) @filesize($file);
    $info['updated'] = @filemtime($file) ?: null;
    // done when nothing holds EmbyCache's lock any more and the output stopped growing
    $info['running'] = flockHeld(EMBY_DATA . '/embycache.lock') || (time() - (int) ($info['updated'] ?? $info['started']) < 3 && time() - $info['started'] < 15);
    return $info;
}

/** The output of the last run started here */
function embyOutput(): array
{
    $info = embyOutputInfo();
    $text = (string) @file_get_contents(EMBY_DATA . '/office-output.txt', false, null, 0, 2 * 1024 * 1024);
    // the report prints the log format too: keep only the message part
    $text = preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d,\d+ \| (?:INFO|DEBUG) \| /m', '', $text);
    return ['ok' => true, 'info' => $info, 'text' => $text];
}

function embyLog(): array
{
    $file = EMBY_DATA . '/logs/embycache.log';
    $size = (int) @filesize($file);
    $h = @fopen($file, 'r');
    if (!$h) {
        return ['ok' => true, 'text' => ''];
    }
    fseek($h, max(0, $size - 512 * 1024));
    $text = (string) stream_get_contents($h);
    fclose($h);
    return ['ok' => true, 'text' => $text, 'cut' => $size > 512 * 1024];
}

// ===================================================================== checks (for the caretaker)

function embyChecks(): array
{
    $out = [];
    $python = embyPython();
    $emby = embyContainers();
    $out[] = finding('emby_container', 'recommended', (bool) $emby, [], 'docker');
    if (!$emby) {
        return $out;                       // without Emby nothing else matters for Jack
    }
    $out[] = finding('python', 'required', $python !== null, [], 'apps');
    $out[] = finding('installed', 'required', is_file(EMBY_APP . '/embycache_run.py'), [], '#/emby');
    if (!is_file(EMBY_APP . '/embycache_run.py')) {
        return $out;
    }
    $settings = embyReadSettings();
    $out[] = finding('configured', 'required', $settings !== null && !empty($settings['instances']), [], '#/emby/setup');
    if (!$settings) {
        return $out;
    }
    $sched = embySchedule();
    $out[] = finding('schedule', 'recommended', $sched['enabled'], ['script' => (string) ($sched['script'] ?? '')], 'userscripts');
    // Mover Tuning should leave EmbyCache's files on the pool alone
    $tuning = (string) @file_get_contents('/boot/config/plugins/ca.mover.tuning/ca.mover.tuning.cfg');
    $out[] = finding('mover_tuning', 'recommended', str_contains($tuning, 'embycache_exclude.txt'),
        ['file' => EMBY_DATA . '/embycache_exclude.txt'], 'settings');
    return $out;
}
