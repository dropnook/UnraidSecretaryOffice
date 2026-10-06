<?php
declare(strict_types=1);

/*
 * Jack Emby — the intern who loves Emby (say it fast: "check Emby"). He looks
 * after two tools that ship with the office:
 *
 *   embycache/  EmbyCache (from github.com/helmi1987/embycache-for-unraid):
 *               puts what people are about to watch on the fast pool, so the
 *               array disks can sleep, and brings it back to the disk it came
 *               from once it's watched
 *   gather/     the media gather (from github.com/helmi1987/media-disk-gather-for-unraid):
 *               brings the files of a film or series folder together on one
 *               array disk; it runs once before EmbyCache's first real run
 *
 * Both keep their data apart, in data/embycache and data/gather (settings,
 * exclude list, origin list, lock, logs, status) — an office update never
 * touches them. EmbyCache's settings are written by its own save_config(),
 * the gather's consolidate.ini by Jack (values checked, every one quoted).
 *
 * Runs go through "php agent.php job embycache|gather <mode>": started by the
 * host's atd (from the page) or the office's cron file (on a schedule). The job keeps the two apart (never at the same time), records
 * how it went and keeps the output of the last run.
 *
 * The Emby API key stays on the server: it is never part of the state the
 * web page gets.
 */

const EMBY_LOG_TAIL = 96 * 1024;
const EMBY_MODES    = ['report' => ['--show-on-deck', '--compact'], 'dry' => [], 'run' => ['--run']];
const GATHER_MODES  = ['dry' => ['--dryrun'], 'run' => ['--run']];
const EMBY_SETTINGS = ['cache_path', 'cache_budget', 'number_episodes', 'movie_share_percent', 'max_episodes_per_series',
                       'max_resume_items', 'max_resume_movies', 'max_resume_series', 'max_favorite_series', 'use_next_up', 'min_free_percent', 'movie_mode',
                       'fill_tool', 'cleanup_tool', 'return_to_origin', 'array_source', 'array_path', 'user_path',
                       'array_disks_glob', 'create_share_root', 'mover_debug_level'];
const GATHER_LOCK   = '/var/run/consolidate_master.lock';     // the gather's own lock (consolidate_master.sh)
const EMBY_HISTORY  = 40;
const EMBY_IGNORED  = '#^/(config|metadata|transcoding-temp|cache|logs|var|boot|tmp)#';   // Emby's own folders, never media

define('EMBY_APP', OFFICE_DIR . '/embycache');
define('GATHER_APP', OFFICE_DIR . '/gather');
define('EMBY_DATA', DATA_DIR . '/embycache');
define('GATHER_DATA', DATA_DIR . '/gather');

desk('emby', [
    'fit'     => function (): array {
        $emby = embyContainers();
        return $emby ? fit(true, 'yes', ['name' => $emby[0]['name']]) : fit(false, 'no_emby');
    },
    'start'   => fn () => embyScan(),
    'actions' => [
        'refresh'      => fn (array $r) => ['ok' => true, 'state' => embyScan()],
        'connect'      => fn (array $r) => embyConnect(textField($r, 'url'), (string) ($r['api_key'] ?? '')),
        'save'         => fn (array $r) => embySave($r['settings'] ?? null),
        'gather_save'  => fn (array $r) => embyGatherSave($r['gather'] ?? null),
        'start_run'    => fn (array $r) => embyStart('embycache', textField($r, 'mode')),
        'gather_start' => fn (array $r) => embyStart('gather', textField($r, 'mode')),
        'schedule'     => fn (array $r) => embySetSchedule(textField($r, 'job'), $r['cron'] ?? null),
        'output'       => fn (array $r) => embyOutput(textField($r, 'tool')),
        'log'          => fn (array $r) => embyLog(textField($r, 'tool')),
        'import_preview' => fn (array $r) => embyImportPreview($r),     // taking over an earlier install: what would come over
        'import_apply'   => fn (array $r) => embyImportDo($r),          // … and doing it, with the preview's token
    ],
    'jobs'    => [
        'embycache' => fn (array $args) => embyJob('embycache', $args),
        'gather'    => fn (array $args) => embyJob('gather', $args),
    ],
    'checks'  => fn () => embyChecks(),
    'metrics' => fn (): array => embyMetrics(),
]);

/**
 * Jack Emby's numbers for Prometheus (lib/metrics.php, once a minute): what
 * EmbyCache keeps on the pool (his state, as of his last look) and how the
 * last real run of each tool went (his list of runs).
 */
function embyMetrics(?string $state = null, ?string $history = null): array
{
    $cache = metricsCached($state ?? deskFile('emby'), function (string $f): ?array {
        $j = readJson($f);
        return $j && !empty($j['configured']) && is_array($j['cache'] ?? null) ? ['files' => (int) ($j['cache']['files'] ?? 0), 'bytes' => (int) ($j['cache']['bytes'] ?? 0)] : null;
    });
    $runs = metricsCached($history ?? EMBY_DATA . '/office-history.json', function (string $f): array {
        $last = [];
        foreach ((array) (readJson($f)['runs'] ?? []) as $r) {       // newest first
            $tool = is_array($r) ? ($r['tool'] ?? null) : null;
            if (in_array($tool, ['embycache', 'gather'], true) && !isset($last[$tool]) && ($r['mode'] ?? '') === 'run' && ($r['result'] ?? '') !== 'refused') {
                $last[$tool] = ['ok' => ($r['result'] ?? '') === 'ok', 'finished' => (int) ($r['finished'] ?? 0)];
            }
        }
        return $last;
    });
    $out = [];
    if ($cache !== null) {
        $out[] = metricsGauge('uso_emby_cache_bytes', 'What EmbyCache keeps on the pool right now (as of Jack\'s last look)', $cache['bytes']);
        $out[] = metricsGauge('uso_emby_cache_files', 'Files EmbyCache keeps on the pool right now (as of Jack\'s last look)', $cache['files']);
    }
    $out[] = metricsGauge('uso_emby_last_run_ok', 'Whether the last real run of EmbyCache / the gather went well',
        array_map(fn ($tool) => [['tool' => $tool], $runs[$tool]['ok']], array_keys($runs)));
    $out[] = metricsGauge('uso_emby_last_run_end_timestamp_seconds', 'When the last real run of EmbyCache / the gather ended',
        array_map(fn ($tool) => [['tool' => $tool], $runs[$tool]['finished']], array_keys($runs)));
    return $out;
}

// ===================================================================== state

function embyScan(): array
{
    $settings = embyReadSettings();
    $gather = embyGatherSettings();
    $state = [
        'time'       => time(),
        'python'     => embyPython(),
        'emby'       => embyContainers(),
        'versions'   => embyVersions(),
        'configured' => $settings !== null && !empty($settings['instances']),
        'settings'   => $settings !== null ? embySettingsPublic($settings) : null,
        'shares'     => $settings !== null ? embyShares($settings) : [],
        'cache'      => embyCacheStats($settings),
        'pool'       => embyPoolUsage($settings),
        'gather'     => [
            'settings' => $gather,
            'ready'    => embyGatherReady(),
            'last'     => readJson(GATHER_DATA . '/last-real.json'),
        ],
        'jobs'       => ['embycache' => embyJobInfo('embycache'), 'gather' => embyJobInfo('gather')],
        'last'       => embyLastRun(),
        'history'    => array_slice(embyHistory(), 0, 20),
        'schedules'  => ['embycache' => officeJobSchedule('embycache'), 'gather' => officeJobSchedule('gather')],
        'foreign'    => embyForeignSchedules(),
        'pools'      => embyPools(),
        'share_info' => embyShareInfo(),
        'pool_dirs'  => embyPoolDirs(),
        'old_clone'  => is_dir(EMBY_DATA . '/app/.git'),
    ];
    writeAtomic(deskFile('emby'), jsonEncode($state));
    return $state;
}

function embyPython(): ?string
{
    [$exit, $out] = run(['python3', '--version'], 10);
    return $exit === 0 ? trim(str_replace('Python', '', $out)) : null;
}

/** The versions of the two tools that ship with the office */
function embyVersions(): array
{
    $lib = (string) @file_get_contents(EMBY_APP . '/embycache_lib.py', false, null, 0, 4096);
    $sh = (string) @file_get_contents(GATHER_APP . '/consolidate_master.sh', false, null, 0, 1024);
    return [
        'embycache' => preg_match('/__version__\s*=\s*"([^"]+)"/', $lib, $m) ? $m[1] : null,
        'gather'    => preg_match('/\((V[\d.]+)\)/', $sh, $m) ? $m[1] : null,
    ];
}

/** Emby containers with what the setup needs: address and the folders they see */
function embyContainers(): array
{
    $found = [];
    foreach (houseContainers() as $c) {
        if (!embyIsServerImage($c['image'])) {
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

/**
 * Is this the image of an Emby server? Only the last part of the image name
 * counts, without registry and tag: emby/embyserver, linuxserver/emby,
 * lscr.io/linuxserver/emby, binhex/arch-emby, embyserver_arm64v8 … — but not
 * tools around Emby such as EmbyCache or EmbyStat. Container names never
 * matter: they are the user's own.
 */
function embyIsServerImage(string $image): bool
{
    $name = strtolower(preg_replace('#[:@].*$#', '', basename(preg_replace('#@.*$#', '', $image))));
    return (bool) preg_match('/(^|[-_.])emby(server)?($|[-_.])/', $name);
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
            'library_types' => (array) ($s['library_types'] ?? []), 'valid_users' => $s['valid_users'] ?? []];
    foreach (EMBY_SETTINGS as $k) {
        $out[$k] = $s[$k] ?? null;
    }
    return $out;
}

// ===================================================================== shares

/** /boot/config/shares/<share>.cfg as key => value */
function embyShareCfg(string $share): array
{
    return preg_match('/^[\w.\- ]+$/uD', $share) ? readCfg("/boot/config/shares/$share.cfg") : [];
}

/** Every user share (by its configuration on the flash) */
function embyAllShares(): array
{
    $out = [];
    foreach (glob('/boot/config/shares/*.cfg') ?: [] as $f) {
        $out[] = basename($f, '.cfg');
    }
    sort($out);
    return $out;
}

/** Every share with where it lives (for the setup: does it suit the chosen pool?) */
function embyShareInfo(): array
{
    $out = [];
    foreach (embyAllShares() as $share) {
        $cfg = embyShareCfg($share);
        $out[$share] = ['use' => (string) ($cfg['shareUseCache'] ?? ''), 'primary' => (string) ($cfg['shareCachePool'] ?? ''),
                        'secondary' => (string) ($cfg['shareCachePool2'] ?? '')];
    }
    return $out;
}

/** The share folders at the top of each pool (EmbyCache needs the share's folder there — on ZFS a dataset) */
function embyPoolDirs(): array
{
    $out = [];
    foreach (embyPools() as $pool) {
        $out[$pool] = array_values(array_map('basename', glob("$pool/*", GLOB_ONLYDIR) ?: []));
    }
    return $out;
}

/** The shares behind the mapped library folders (/mnt/user/<share>/…) */
function embyMappedShares(array $settings): array
{
    $shares = [];
    $user = rtrim((string) ($settings['user_path'] ?? '/mnt/user'), '/');
    foreach ((array) ($settings['instances'] ?? []) as $i) {
        foreach (array_merge((array) ($settings['path_mappings'] ?? []), (array) ($i['path_mappings'] ?? [])) as $host) {
            if (is_string($host) && preg_match('#^' . preg_quote($user, '#') . '/([^/]+)#', $host, $m)) {
                $shares[$m[1]] = true;
            }
        }
    }
    ksort($shares);
    return array_keys($shares);
}

/**
 * Does a share suit EmbyCache with this pool? EmbyCache moves between the
 * array (/mnt/user0) and one pool: the share must have files on the array.
 *   ok          primary = the pool, secondary = array (the usual "Cache: yes")
 *   other_pool  secondary = array, but its primary is another pool
 *   array_only  array only: works, the way back best via rsync / the origin disk
 *   no_array    primary pool → secondary pool: never on the array, nothing to do
 *   pool_only   prefer/only: lives on a pool, the mover would pull everything back
 */
function embyShareFit(array $cfg, string $pool): string
{
    $use = strtolower((string) ($cfg['shareUseCache'] ?? ''));
    $primary = (string) ($cfg['shareCachePool'] ?? '');
    $secondary = (string) ($cfg['shareCachePool2'] ?? '');
    return match (true) {
        $use === 'no' || $use === ''             => 'array_only',
        $use === 'yes' && $secondary !== ''      => 'no_array',
        $use === 'yes' && $primary !== $pool     => 'other_pool',
        $use === 'yes'                           => 'ok',
        default                                  => 'pool_only',
    };
}

function embyShares(array $settings): array
{
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    $pool = basename($cache);
    $asleep = $cache !== '' && baseAsleep($pool, sleepingDisks());   // the caretaker looks every 30 min: don't wake the pool
    $out = [];
    foreach (embyMappedShares($settings) as $share) {
        $cfg = embyShareCfg($share);
        $out[] = [
            'share'     => $share,
            'use'       => (string) ($cfg['shareUseCache'] ?? ''),
            'primary'   => (string) ($cfg['shareCachePool'] ?? ''),
            'secondary' => (string) ($cfg['shareCachePool2'] ?? ''),
            'include'   => (string) ($cfg['shareInclude'] ?? ''),
            'fit'       => embyShareFit($cfg, $pool),
            // EmbyCache won't create it (ZFS: a dataset); null = the pool sleeps, not looked
            'root'      => $cache === '' ? false : ($asleep ? null : is_dir("$cache/$share")),
        ];
    }
    return $out;
}

// ===================================================================== what's on the pool

/** What EmbyCache keeps on the pool right now (its exclude list: absolute pool paths) */
function embyCacheStats(?array $settings): array
{
    $list = @file(EMBY_DATA . '/embycache_exclude.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $origin = readJson(EMBY_DATA . '/embycache_origin.json') ?? [];
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
        $groups[$key] ??= ['share' => $parts[0], 'title' => $parts[1] ?? $parts[0], 'files' => 0, 'bytes' => 0, 'origin' => []];
        $groups[$key]['files']++;
        $groups[$key]['bytes'] += $size;
        if (is_string($origin[$path] ?? null)) {
            $groups[$key]['origin'][$origin[$path]] = true;
        }
    }
    foreach ($groups as &$g) {
        $g['origin'] = array_keys($g['origin']);
    }
    unset($g);
    usort($groups, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
    return ['files' => count($list), 'bytes' => $bytes, 'groups' => array_slice(array_values($groups), 0, 200),
            'listed_at' => @filemtime(EMBY_DATA . '/embycache_exclude.txt') ?: null];
}

/**
 * How full the pool is, against what EmbyCache keeps free there. On ZFS the
 * pool's top folder only sees its own dataset (nearly empty, so "100 % free"):
 * the whole pool counts — its used + available space.
 */
function embyPoolUsage(?array $settings): ?array
{
    $cache = (string) ($settings['cache_path'] ?? '');
    if ($cache === '' || !is_dir($cache)) {
        return null;
    }
    $total = (float) @disk_total_space($cache);
    $free = (float) @disk_free_space($cache);
    foreach (mountTable() as $m) {
        if ($m['mount'] === rtrim($cache, '/') && $m['fs'] === 'zfs' && preg_match('#^[\w.-]+$#', $m['source'])) {
            [$exit, $out] = run(['zfs', 'list', '-Hp', '-o', 'used,avail', $m['source']], 10);
            $f = preg_split('/\s+/', trim($out));
            if ($exit === 0 && count($f) === 2 && is_numeric($f[0]) && is_numeric($f[1])) {
                $total = (float) $f[0] + (float) $f[1];
                $free = (float) $f[1];
            }
        }
    }
    return $total > 0 ? ['path' => $cache, 'total' => $total, 'free' => $free,
                         'free_percent' => round($free / $total * 100, 1), 'min_free' => $settings['min_free_percent'] ?? null] : null;
}

/** The last run from EmbyCache's own status file (written at the end of every run) */
function embyLastRun(): ?array
{
    return readJson(EMBY_DATA . '/status.json');
}

// ===================================================================== fetching from Emby (setup)

/**
 * Talks to an Emby server: does it answer, which libraries and users does it
 * have. Without a new key the stored one for this address is used, so the
 * page never needs to know it.
 */
function embyConnect(string $url, string $key): array
{
    $url = rtrim($url, '/');
    if (!preg_match('#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#D', $url)) {
        throw new Problem('emby_bad_url');
    }
    if ($key === '') {
        $key = embyStoredKey($url);
    }
    if ($key === '' || !preg_match('/^[A-Za-z0-9]{8,128}$/D', $key)) {
        throw new Problem('emby_need_key');
    }
    $info = embyApi($url, $key, '/System/Info');
    $libs = [];
    foreach ((array) embyApi($url, $key, '/Library/VirtualFolders') as $lib) {
        $locs = array_values(array_filter((array) ($lib['Locations'] ?? []), fn ($l) => !preg_match(EMBY_IGNORED, (string) $l)));
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

function embyStoredKey(string $url): string
{
    foreach (embyReadSettings()['instances'] ?? [] as $i) {
        if (rtrim((string) ($i['url'] ?? ''), '/') === rtrim($url, '/')) {
            return (string) ($i['api_key'] ?? '');
        }
    }
    return '';
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

// ===================================================================== saving the setup

/**
 * Takes the choices of the setup form, lays them over the current settings
 * and lets EmbyCache's own save_config() write them (it checks and fills in
 * its defaults). Its load_config() must accept the result.
 */
function embySave(mixed $in): array
{
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'settings']);
    }
    if (embyAnyRunning()) {
        throw new Problem('emby_running');
    }
    $cur = embyReadSettings() ?? [];
    $instances = [];
    foreach ((array) ($in['instances'] ?? []) as $i) {
        $url = rtrim((string) ($i['url'] ?? ''), '/');
        $key = trim((string) ($i['api_key'] ?? '')) ?: embyStoredKey($url);
        if (!preg_match('#^https?://\S+$#D', $url) || !preg_match('/^[A-Za-z0-9]{8,128}$/D', $key)) {
            throw new Problem('emby_need_key');
        }
        $maps = [];
        foreach ((array) ($i['path_mappings'] ?? []) as $from => $to) {
            if (is_string($from) && is_string($to) && embyMappingOk($from, $to)) {
                $maps[rtrim($from, '/')] = rtrim($to, '/');        // '' = this folder deliberately not cached
            }
        }
        $name = mb_substr(trim((string) ($i['servername'] ?? '')), 0, 60) ?: 'Emby' . (count($instances) + 1);
        $instances[] = ['servername' => $name, 'url' => $url, 'api_key' => $key, 'path_mappings' => $maps];
    }
    if (!$instances) {
        throw new Problem('emby_need_key');
    }
    $cfg = $cur;
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];
    $cfg['libraries'] = array_values(array_filter((array) ($in['libraries'] ?? []), 'is_string'));
    // what kind each chosen library is (films, series …) — only for the office's overview, EmbyCache ignores it
    $cfg['library_types'] = [];
    foreach ((array) ($in['library_types'] ?? []) as $name => $type) {
        if (is_string($name) && in_array($name, $cfg['libraries'], true) && is_string($type) && preg_match('/^[a-z]{0,20}$/D', $type)) {
            $cfg['library_types'][$name] = $type;
        }
    }
    // people: a list of ids, or {id: {budget: "300G"}} when some have a budget of their own
    $users = [];
    $budgets = (array) ($in['user_budgets'] ?? []);
    foreach ((array) ($in['valid_users'] ?? []) as $id) {
        if (is_string($id) && preg_match('/^[\w-]{1,64}$/D', $id)) {
            $b = trim((string) ($budgets[$id] ?? ''));
            if ($b !== '' && !preg_match('/^\d+(\.\d+)?\s*[KMGTP]?B?$/iD', $b)) {
                throw new Problem('emby_bad_size', ['value' => $b]);
            }
            $users[$id] = $b !== '' ? ['budget' => strtoupper(str_replace(' ', '', $b))] : (object) [];
        }
    }
    $cfg['valid_users'] = array_filter($users, fn ($u) => is_array($u)) ? $users : array_keys($users);
    foreach (EMBY_SETTINGS as $k) {
        if (array_key_exists($k, $in)) {
            $cfg[$k] = $in[$k];
        }
    }
    $cfg['cache_budget'] = strtoupper(str_replace(' ', '', (string) ($cfg['cache_budget'] ?? '')));
    if (!in_array($cfg['cache_path'] ?? '', embyPools(), true)) {
        throw new Problem('emby_bad_pool', ['path' => (string) ($cfg['cache_path'] ?? '')]);
    }
    // Unraid's views: fixed here, the page only shows them
    if (($cfg['array_path'] ?? '/mnt/user0') !== '/mnt/user0' || ($cfg['user_path'] ?? '/mnt/user') !== '/mnt/user'
        || ($cfg['array_disks_glob'] ?? '/mnt/disk[0-9]*') !== '/mnt/disk[0-9]*') {
        throw new Problem('emby_config', ['detail' => 'array_path / user_path / array_disks_glob']);
    }
    embyWriteSettings($cfg);
    if ($gather = embyGatherSettings()) {
        embyWriteGatherIni($gather, $cfg);             // follows the pool
    }
    logLine('Jack Emby: EmbyCache settings saved');
    return ['ok' => true, 'state' => embyScan()];
}

/**
 * EmbyCache's settings file in $dir, written by its own save_config(). save_config() writes
 * before load_config() checks: it writes a trial file (EMBYCACHE_CONFIG), and only once
 * EmbyCache accepts it is that put in place. The request goes through a new 0600 file in $tmp
 * (it holds the API key).
 */
function embyWriteSettings(array $cfg, string $dir = EMBY_DATA, string $tmp = RUN_DIR): void
{
    if (!is_file(EMBY_APP . '/embycache_lib.py')) {
        throw new Problem('emby_missing_tool', ['path' => EMBY_APP]);
    }
    embyDataDir($dir);
    @mkdir($tmp, 0700, true);
    $file = writeNewFile("$tmp/.emby-settings", json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0600);
    if ($file === null) {
        throw new Problem('emby_config', ['detail' => "cannot write in $tmp"]);
    }
    $trial = "$dir/.embycache_settings.trial.json";
    @unlink($trial);
    $py = 'import json, sys; sys.path.insert(0, sys.argv[1]); import embycache_lib as l; '
        . 'l.save_config(json.load(open(sys.argv[2], encoding="utf-8"))); l.load_config()';
    try {
        [$exit, , $err] = runEnv(['python3', '-c', $py, EMBY_APP, $file], ['EMBYCACHE_DIR' => $dir] + embyPyEnv() + ['EMBYCACHE_CONFIG' => $trial], 30);
    } finally {
        @unlink($file);
    }
    if ($exit !== 0 || !is_file($trial)) {
        @unlink($trial);
        $lines = array_filter(explode("\n", trim($err)));
        throw new Problem('emby_config', ['detail' => (string) end($lines)]);
    }
    chmod($trial, 0600);
    rename($trial, "$dir/embycache_settings.json");
}

/**
 * A path mapping: Emby's folder (absolute) => the share folder it is on this
 * server (/mnt/user/<share>/…, or '' = deliberately not cached). EmbyCache
 * moves files along these paths: no "..", ".", empty parts or control
 * characters, so a mapping can never point out of the shares.
 */
function embyMappingOk(string $from, string $to): bool
{
    $clean = function (string $path): bool {
        if (!str_starts_with($path, '/') || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', trim($path, '/')) as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                return false;
            }
        }
        return true;
    };
    return $clean($from) && ($to === '' || ($clean($to) && preg_match('#^/mnt/user/[^/]+#', $to)));
}

/** settings, exclude list and logs hold the API key and file names: root only */
function embyDataDir(string $dir): void
{
    @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    @chown($dir, 0);
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

/** What EmbyCache's Python always gets: its data folder, no bytecode next to the code, UTF-8 output */
function embyPyEnv(): array
{
    return ['EMBYCACHE_DIR' => EMBY_DATA, 'PYTHONDONTWRITEBYTECODE' => '1', 'PYTHONIOENCODING' => 'utf-8', 'PYTHONUNBUFFERED' => '1'];
}

// ===================================================================== the gather's settings

function embyGatherSettings(): ?array
{
    return readJson(GATHER_DATA . '/gather.json');
}

/** The gather's own settings (works without EmbyCache being set up): shares, minimum free space, duplicates */
function embyGatherSave(mixed $in): array
{
    if (embyAnyRunning()) {
        throw new Problem('emby_running');
    }
    embySaveGather(embyGatherCheck($in), embyReadSettings() ?? []);
    logLine('Jack Emby: gather settings saved');
    return ['ok' => true, 'state' => embyScan()];
}

/** The gather's settings, checked ($all: the server's shares) */
function embyGatherCheck(mixed $in, ?array $all = null): array
{
    $in = is_array($in) ? $in : [];
    $all ??= embyAllShares();
    $shares = array_values(array_unique(array_filter((array) ($in['shares'] ?? []), fn ($s) => is_string($s) && in_array($s, $all, true))));
    if (!$shares) {
        throw new Problem('emby_gather_no_share');
    }
    $min = $in['min_free_gb'] ?? 256;
    if (!is_numeric($min) || (int) $min < 0 || (int) $min > 100000) {
        throw new Problem('emby_bad_number', ['field' => 'min_free_gb']);
    }
    $dup = in_array($in['dup_check'] ?? 'size', ['size', 'cmp'], true) ? $in['dup_check'] ?? 'size' : 'size';
    return ['shares' => $shares, 'min_free_gb' => (int) $min, 'dup_check' => $dup];
}

function embySaveGather(array $gather, array $emby, string $gatherDir = GATHER_DATA, string $embyDir = EMBY_DATA): void
{
    embyDataDir($gatherDir);
    writeAtomic("$gatherDir/gather.json", jsonEncode($gather), 0600, 0, 0);
    embyWriteGatherIni($gather, $emby, $gatherDir, $embyDir);
}

/** The pools the gather leaves alone: those of its shares, and EmbyCache's */
function embyGatherPools(array $shares, array $emby, ?callable $shareCfg = null): array
{
    $shareCfg ??= fn (string $s): array => embyShareCfg($s);
    $pools = [];
    foreach ($shares as $share) {
        foreach (['shareCachePool', 'shareCachePool2'] as $k) {
            $p = (string) ($shareCfg($share)[$k] ?? '');
            if ($p !== '') {
                $pools[] = "/mnt/$p";
            }
        }
    }
    if (!empty($emby['cache_path'])) {
        $pools[] = rtrim((string) $emby['cache_path'], '/');
    }
    return array_values(array_unique($pools));
}

/** consolidate.ini from Jack's settings (written again before every run, so it follows the pool) */
function embyWriteGatherIni(array $gather, array $emby, string $gatherDir = GATHER_DATA, string $embyDir = EMBY_DATA): void
{
    $ini = embyGatherIni($gather, embyGatherPools($gather['shares'], $emby), "$gatherDir/consolidate.log", "$embyDir/embycache_exclude.txt");
    writeAtomic("$gatherDir/consolidate.ini", $ini, 0600, 0, 0);
}

/**
 * The text of consolidate.ini — a file bash sources: every value single
 * quoted, the shares and pools checked against what exists. Never
 * --include-cache: what EmbyCache keeps on the pool stays (its list is the
 * gather's exclude file, too).
 */
function embyGatherIni(array $gather, array $pools, string $log, string $exclude): string
{
    $q = fn (string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
    $dirs = [];
    foreach ($gather['shares'] as $share) {
        if (!preg_match('/^[\w.\- ]+$/uD', $share)) {
            throw new Problem('emby_bad_share', ['share' => $share]);
        }
        $dirs[] = $q("/mnt/user/$share");
    }
    $pools = array_values(array_filter($pools, fn ($p) => preg_match('#^/mnt/[a-z0-9_-]+$#', $p) && !preg_match('#^/mnt/(disk\d+|user0?|disks|remotes|addons)$#', $p)));
    return "# consolidate.ini - written by the Unraid Secretary Office (Jack Emby), change it there\n"
        . 'BASE_DIRS=(' . implode(' ', $dirs) . ")\n"
        . 'LOGFILE=' . $q($log) . "\n"
        . "ARRAY_PATTERN='/mnt/disk[0-9]*'\n"
        . 'CACHE_PATTERN=' . $q(implode(' ', $pools)) . "\n"
        . 'EXCLUDE_FILE=' . $q($exclude) . "\n"
        . "DRYRUN=true\n"
        . 'MIN_FREE_GB=' . (int) $gather['min_free_gb'] . "\n"
        . "CACHE_ONLY_TARGET='skip'\n"
        . 'DUP_CHECK=' . $q($gather['dup_check'] === 'cmp' ? 'cmp' : 'size') . "\n";
}

/** Has the gather brought the folders together at least once (a real run that went through)? */
function embyGatherReady(): bool
{
    $last = readJson(GATHER_DATA . '/last-real.json');
    return $last !== null && in_array($last['result'] ?? '', ['ok', 'errors'], true);
}

// ===================================================================== taking over an earlier install

/*
 * Someone who ran helmi1987's tools before (from a User Scripts folder, say) types the folder
 * where the old files lie; Jack never searches. The agent reads only the known files there —
 * plain files, size-capped — on the server: the Emby API key never goes to the page (the preview
 * says found / not found, and every answer is scrubbed of it). Preview first (what changes, what
 * is left out, what gets Jack's defaults, how many cached files and origins come over), then the
 * import with the preview's token. Before anything of Jack's is overwritten, a copy of it stays
 * next to it (<file>.before-import-<time>), so it can be undone by hand.
 */

const EMBY_IMPORT_FILES = [   // the only files read in the old folder: name, size cap
    'settings' => ['embycache_settings.json', 1048576],
    'exclude'  => ['embycache_exclude.txt', 16777216],
    'origin'   => ['embycache_origin.json', 16777216],
    'ini'      => ['consolidate.ini', 65536],
];
// set by Jack whatever the old file says (Unraid's views, the share configs); the mover only as EmbyCache finds it itself
const EMBY_IMPORT_FIXED = ['array_path' => '/mnt/user0', 'user_path' => '/mnt/user', 'array_disks_glob' => '/mnt/disk[0-9]*',
                           'shares_cfg_dir' => '/boot/config/shares'];
const EMBY_MOVER_BINS   = ['', '/usr/libexec/unraid/move', '/usr/local/sbin/move', '/usr/local/bin/move'];
// rsync options an imported rsync_args may hold: plain switches only (no -e, no files, no remote shell)
const EMBY_RSYNC_SHORT  = '/^-[aAXHSDglopPrtuvhxWcm]+$/D';
const EMBY_RSYNC_LONG   = ['--numeric-ids', '--sparse', '--hard-links', '--acls', '--xattrs', '--preallocate', '--whole-file', '--no-whole-file',
                           '--checksum', '--inplace', '--partial', '--progress', '--human-readable', '--times', '--perms', '--owner', '--group',
                           '--archive', '--verbose', '--quiet'];
const EMBY_IMPORT_PCT   = ['min_free_percent', 'movie_share_percent'];
const GATHER_IMPORT_KEYS = ['BASE_DIRS', 'MIN_FREE_GB', 'DUP_CHECK'];                     // what Jack's gather settings take over
const GATHER_IMPORT_JACK = ['LOGFILE', 'ARRAY_PATTERN', 'CACHE_PATTERN', 'EXCLUDE_FILE', 'DRYRUN', 'CACHE_ONLY_TARGET', 'MOVE_CACHE'];  // Jack's own

/** What the import looks at: Jack's folders and the server's pools, shares and sleeping disks (the tests pass their own) */
function embyImportContext(): array
{
    return ['emby_dir' => EMBY_DATA, 'gather_dir' => GATHER_DATA, 'tmp' => RUN_DIR, 'fs' => '',
            'pools' => embyPools(), 'shares' => embyAllShares(), 'asleep' => sleepingDisks(),
            'share_cfg' => fn (string $s): array => embyShareCfg($s), 'defaults' => null];
}

/** The two folders of a request ('' = not used) */
function embyImportFolders(array $r): array
{
    $out = [];
    foreach (['embycache', 'gather'] as $k) {
        $v = $r[$k] ?? '';
        if (!is_string($v) || strlen($v) > 1024) {
            throw new Problem('missing_field', ['field' => $k]);
        }
        $out[$k] = trim($v);
    }
    if ($out['embycache'] === '' && $out['gather'] === '') {
        throw new Problem('emby_import_nothing');
    }
    return $out;
}

function embyImportPreview(array $r): array
{
    $f = embyImportFolders($r);
    $plan = embyImportPlan($f['embycache'], $f['gather'], embyImportContext());
    return ['ok' => true, 'preview' => $plan['preview'] + ['running' => embyAnyRunning()]];
}

function embyImportDo(array $r): array
{
    $f = embyImportFolders($r);
    if (embyAnyRunning()) {
        throw new Problem('emby_running');
    }
    $ctx = embyImportContext();
    $plan = embyImportPlan($f['embycache'], $f['gather'], $ctx);
    if (!hash_equals($plan['preview']['token'], textField($r, 'token'))) {
        throw new Problem('emby_import_changed');
    }
    if (!$plan['preview']['ready']) {
        throw new Problem('emby_import_blocked');
    }
    $done = embyImportApply($plan['do'], $ctx);
    logLine('Jack Emby: took over an earlier install (' . implode(', ', array_keys(array_filter($plan['do']))) . ') from '
        . implode(', ', array_filter($f)) . ($done['backups'] ? '; copies of his own: .before-import-' . $done['stamp'] : ''));
    return ['ok' => true, 'done' => embyImportScrub($done, $plan['secrets']), 'state' => embyScan()];
}

/**
 * The folder typed for an earlier install, checked before anything in it is read: absolute, no
 * "." or "..", under /mnt/user/<share>, a pool, an array disk or User Scripts' folder on the
 * flash; the disks behind it awake (Jack never wakes one); every part a real folder — no link,
 * but Unraid's own of an exclusive share (/mnt/user/<share> → ../<pool>/<share>). Returns the
 * real folder to read from.
 */
function embyImportFolder(string $path, array $ctx): string
{
    $path = rtrim($path, '/');
    if (strlen($path) > 1024 || !preg_match('#^(/[^/\x00-\x1f\x7f]+)+$#D', $path)
        || array_intersect(explode('/', substr($path, 1)), ['.', '..'])) {
        throw new Problem('emby_import_folder', ['path' => $path]);
    }
    $pools = (array) $ctx['pools'];
    if (preg_match('#^/mnt/user/([^/]+)(?:/|$)#', $path, $m)) {
        if (!preg_match('/^[\w.\- ]+$/uD', $m[1])) {
            throw new Problem('emby_import_where', ['path' => $path]);
        }
        $bases = embyImportShareBases($m[1], $ctx);
    } elseif (preg_match('#^/mnt/(disk\d+)(?:/|$)#', $path, $m) || (preg_match('#^/mnt/([^/]+)(?:/|$)#', $path, $m) && in_array("/mnt/{$m[1]}", $pools, true))) {
        $bases = [$m[1]];
    } elseif (preg_match('#^/boot/config/plugins/user\.scripts/scripts/[^/]+(?:/|$)#', $path)) {
        $bases = [];                                    // the flash never sleeps
    } else {
        throw new Problem('emby_import_where', ['path' => $path]);
    }
    $sleeping = array_values(array_filter($bases, fn ($b) => baseAsleep((string) $b, (array) $ctx['asleep'])));
    if ($sleeping) {
        throw new Problem('emby_import_asleep', ['path' => $path, 'disks' => implode(', ', $sleeping)]);
    }
    $fs = (string) ($ctx['fs'] ?? '');
    $real = '';
    foreach (explode('/', substr($path, 1)) as $i => $part) {
        $next = "$real/$part";
        clearstatcache(true, $fs . $next);
        $st = @lstat($fs . $next);
        if ($st && ($st['mode'] & 0170000) === 0120000 && $i === 2 && str_starts_with($path, '/mnt/user/')) {
            // an exclusive share: exactly ../<pool>/<share> or /mnt/<pool>/<share>, an awake pool of this server
            $to = (string) @readlink($fs . $next);
            if (preg_match('#^(?:\.\./|/mnt/)([^/]+)/([^/]+)/?$#D', $to, $t) && $t[2] === $part && in_array("/mnt/{$t[1]}", $pools, true)) {
                if (baseAsleep($t[1], (array) $ctx['asleep'])) {
                    throw new Problem('emby_import_asleep', ['path' => $path, 'disks' => $t[1]]);
                }
                $next = "/mnt/{$t[1]}/$part";
                clearstatcache(true, $fs . $next);
                $st = @lstat($fs . $next);
            }
        }
        if (!$st) {
            throw new Problem('emby_import_missing', ['path' => $path]);
        }
        if (($st['mode'] & 0170000) === 0120000) {
            throw new Problem('emby_import_link', ['path' => $next]);
        }
        if (($st['mode'] & 0170000) !== 0040000) {
            throw new Problem('emby_import_missing', ['path' => $path]);
        }
        $real = $next;
    }
    foreach ([$ctx['emby_dir'], $ctx['gather_dir']] as $own) {
        if (realpath($fs . $real) === realpath((string) $own)) {
            throw new Problem('emby_import_own', ['path' => $path]);
        }
    }
    return $fs . $real;
}

/** The disks and pools a share may lie on (its config on the flash; never a look at the disks) */
function embyImportShareBases(string $share, array $ctx): array
{
    $cfg = ($ctx['share_cfg'])($share);
    $use = (string) ($cfg['shareUseCache'] ?? 'no');
    $bases = [];
    if ($use !== 'no') {
        foreach (['shareCachePool', 'shareCachePool2'] as $k) {
            if (($cfg[$k] ?? '') !== '') {
                $bases[] = (string) $cfg[$k];
            }
        }
    }
    if ($use === 'no' || ($use !== 'only' && ($cfg['shareCachePool2'] ?? '') === '')) {     // the array is its primary or secondary
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $bases = array_merge($bases, $include ?: array_filter(array_keys((array) $ctx['asleep']), fn ($d) => preg_match('/^disk\d+$/D', (string) $d)));
    }
    return array_values(array_unique(array_map('strval', $bases)));
}

/** One of the known files in the old folder: a plain file of its own (no link, no second hard link), at most $cap bytes; null when it isn't there */
function embyImportRead(string $dir, string $name, int $cap): ?string
{
    $file = "$dir/$name";
    clearstatcache(true, $file);
    $st = @lstat($file);
    if (!$st) {
        return null;
    }
    if (($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1) {
        throw new Problem('emby_import_file', ['file' => $name]);
    }
    if ($st['size'] > $cap) {
        throw new Problem('emby_import_big', ['file' => $name]);
    }
    $h = @fopen($file, 'rb');
    $fst = $h ? fstat($h) : false;
    if (!$h || !$fst || $fst['ino'] !== $st['ino'] || $fst['dev'] !== $st['dev'] || ($fst['mode'] & 0170000) !== 0100000) {
        if ($h) {
            fclose($h);
        }
        throw new Problem('emby_import_file', ['file' => $name]);
    }
    $data = (string) stream_get_contents($h, $cap + 1);
    fclose($h);
    if (strlen($data) > $cap) {
        throw new Problem('emby_import_big', ['file' => $name]);
    }
    return $data;
}

/** EmbyCache's own DEFAULTS (the version that ships with the office), asked from embycache_lib.py */
function embyImportDefaults(): array
{
    $py = 'import json, sys; sys.path.insert(0, sys.argv[1]); import embycache_lib as l; print(json.dumps(l.DEFAULTS))';
    [$exit, $out, $err] = runEnv(['python3', '-c', $py, EMBY_APP], embyPyEnv(), 30);
    $j = $exit === 0 ? json_decode(trim($out), true) : null;
    if (!is_array($j) || !array_key_exists('cache_path', $j)) {
        $lines = array_filter(explode("\n", trim((string) $err)));
        throw new Problem('emby_config', ['detail' => (string) (end($lines) ?: 'DEFAULTS')]);
    }
    return $j;
}

/**
 * What would come over from the old folders, against Jack's files now. Returns the preview (for
 * the page: no API key, the token over everything that will be done), what will be done (`do`,
 * agent only) and the secrets the answers are scrubbed of.
 */
function embyImportPlan(string $embyFolder, string $gatherFolder, array $ctx): array
{
    $ctx['defaults'] ??= embyImportDefaults();
    $secrets = [];
    $preview = ['embycache' => null, 'gather' => null];
    $do = ['embycache' => null, 'gather' => null];
    $current = readJson($ctx['emby_dir'] . '/embycache_settings.json');
    foreach ((array) ($current['instances'] ?? []) as $i) {
        if (is_array($i) && is_string($i['api_key'] ?? null) && $i['api_key'] !== '') {
            $secrets[] = $i['api_key'];
        }
    }
    if ($embyFolder !== '') {
        [$preview['embycache'], $do['embycache']] = embyImportEmbyCache(embyImportFolder($embyFolder, $ctx), $ctx, $current, $secrets);
        $preview['embycache']['folder'] = rtrim($embyFolder, '/');
    }
    if ($gatherFolder !== '') {
        $emby = $do['embycache']['settings'] ?? $current ?? [];
        [$preview['gather'], $do['gather']] = embyImportGather(embyImportFolder($gatherFolder, $ctx), $ctx, $emby);
        $preview['gather']['folder'] = rtrim($gatherFolder, '/');
    }
    if (!in_array(true, (array) ($preview['embycache']['found'] ?? []), true) && !($preview['gather']['found'] ?? false)) {
        throw new Problem('emby_import_none');
    }
    $preview['ready'] = $do['embycache'] !== null || $do['gather'] !== null;
    $preview = embyImportScrub($preview, $secrets);
    // the token: what the page saw, what will be done, and Jack's own files as they are now
    $mine = '';
    foreach ([$ctx['emby_dir'] . '/embycache_settings.json', $ctx['emby_dir'] . '/embycache_exclude.txt', $ctx['emby_dir'] . '/embycache_origin.json',
              $ctx['gather_dir'] . '/gather.json'] as $f) {
        $mine .= (string) @sha1_file($f) . '|';
    }
    $preview['token'] = sha1(jsonEncode($preview) . "\n" . sha1(jsonEncode($do)) . "\n" . $mine);
    return ['preview' => $preview, 'do' => $do, 'secrets' => $secrets];
}

/** Every secret value replaced, wherever it appears in an answer for the page */
function embyImportScrub(mixed $data, array $secrets): mixed
{
    $secrets = array_values(array_filter(array_unique($secrets), fn ($s) => is_string($s) && strlen($s) >= 4));
    if (!$secrets) {
        return $data;
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[is_string($k) ? str_replace($secrets, '•••', $k) : $k] = embyImportScrub($v, $secrets);
        }
        return $out;
    }
    return is_string($data) ? str_replace($secrets, '•••', $data) : $data;
}

/** An address as the page may see it: no user:password@, no query */
function embyImportMaskUrl(string $url): string
{
    return preg_replace(['#^(https?://)[^/@]*@#', '#\?.*$#s'], ['$1•••@', '?•••'], $url);
}

/** EmbyCache's part: settings, the list of what lies on the pool, where it came from */
function embyImportEmbyCache(string $dir, array $ctx, ?array $current, array &$secrets): array
{
    $files = [];
    foreach (['settings', 'exclude', 'origin'] as $k) {
        $files[$k] = embyImportRead($dir, EMBY_IMPORT_FILES[$k][0], EMBY_IMPORT_FILES[$k][1]);
    }
    $pv = ['found' => array_map(fn ($f) => $f !== null, $files), 'blockers' => [], 'warnings' => [], 'changes' => [], 'same' => 0,
           'defaults' => [], 'jack' => [], 'dropped' => [], 'instances' => [], 'users' => null, 'libraries' => null,
           'exclude' => null, 'origin' => null];
    $cfg = null;
    if ($files['settings'] !== null) {
        $old = json_decode($files['settings'], true);
        if (!is_array($old) || ($old && array_is_list($old))) {
            $pv['blockers'][] = ['key' => 'bad_json'];
        } else {
            $cfg = embyImportSettings($old, $ctx, $current, $pv, $secrets);
        }
    }
    // the list and the origins: checked against the pool of the settings Jack will have
    $target = $cfg ?? $current;
    $cache = rtrim((string) ($target['cache_path'] ?? ''), '/');
    $exclude = $origin = null;
    if ($files['exclude'] !== null || $files['origin'] !== null) {
        if ($target === null || $cache === '') {
            $pv['blockers'][] = ['key' => 'no_settings'];
        } else {
            $mine = array_values(array_filter(array_map('trim', explode("\n", (string) @file_get_contents($ctx['emby_dir'] . '/embycache_exclude.txt')))));
            $exclude = $mine;
            if ($files['exclude'] !== null) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $files['exclude'])), fn ($l) => $l !== ''));
                $ok = array_values(array_unique(array_filter($lines, fn ($l) => embyImportPathOk($l, $cache, $ctx['shares']))));
                $exclude = array_values(array_unique(array_merge($mine, $ok)));
                sort($exclude, SORT_STRING);
                $bad = array_values(array_filter($lines, fn ($l) => !embyImportPathOk($l, $cache, $ctx['shares'])));
                $pv['exclude'] = ['lines' => count($lines), 'ok' => count($ok), 'bad' => count($bad),
                                  'bad_sample' => array_map(fn ($l) => mb_substr($l, 0, 200), array_slice($bad, 0, 5)),
                                  'already' => count(array_intersect($ok, $mine)), 'mine' => count($mine), 'total' => count($exclude)];
            }
            $mineOrigin = readJson($ctx['emby_dir'] . '/embycache_origin.json') ?? [];
            if ($files['origin'] !== null) {
                $o = json_decode($files['origin'], true);
                $o = is_array($o) && !($o && array_is_list($o)) ? $o : [];
                $ok = [];
                foreach ($o as $path => $disk) {
                    if (is_string($disk) && preg_match('/^disk\d{1,3}$/D', $disk) && embyImportPathOk((string) $path, $cache, $ctx['shares'])) {
                        $ok[(string) $path] = $disk;
                    }
                }
                $origin = $mineOrigin + $ok;              // Jack's own entries win: they are newer
                ksort($origin, SORT_STRING);
                $pv['origin'] = ['entries' => count($o), 'ok' => count($ok), 'bad' => count($o) - count($ok),
                                 'already' => count(array_intersect_key($ok, $mineOrigin)), 'total' => count($origin),
                                 'unreadable' => !is_array(json_decode($files['origin'], true))];
            }
            $away = count(array_filter($mine, fn ($l) => !str_starts_with($l, "$cache/")));
            if ($away) {                                   // Jack's own list on another pool: EmbyCache won't bring those back
                $pv['warnings'][] = ['key' => 'pool_change', 'params' => ['n' => $away, 'pool' => $cache]];
            }
        }
    }
    if ($files['settings'] !== null && $files['exclude'] === null) {
        $pv['warnings'][] = ['key' => 'no_exclude'];
    }
    if ($cfg !== null && !$pv['blockers']) {
        $detail = embyImportTrial($cfg, $ctx);
        if ($detail !== null) {
            $pv['blockers'][] = ['key' => 'config', 'params' => ['detail' => $detail]];
        }
    }
    $pv['ok'] = !$pv['blockers'] && in_array(true, $pv['found'], true);
    $do = $pv['ok'] ? ['settings' => $cfg, 'exclude' => $files['exclude'] !== null ? $exclude : null, 'origin' => $files['origin'] !== null ? $origin : null] : null;
    return [$pv, $do];
}

/** A line of the list or a key of the origins: a file under <pool>/<share>/ of this server, no "." or ".." */
function embyImportPathOk(string $path, string $cache, array $shares): bool
{
    if ($cache === '' || !str_starts_with($path, "$cache/") || strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8')
        || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        return false;
    }
    $parts = explode('/', substr($path, strlen($cache) + 1));
    if (count($parts) < 2 || !in_array($parts[0], $shares, true)) {
        return false;
    }
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/**
 * The old settings mapped onto EmbyCache's keys of the version that ships with the office: keys
 * it doesn't know are left out, keys it added get its defaults, values that aren't valid here or
 * belong to the office are Jack's. Fills the preview's lists; returns the settings to write.
 */
function embyImportSettings(array $old, array $ctx, ?array $current, array &$pv, array &$secrets): array
{
    $defaults = (array) $ctx['defaults'];
    $structured = ['instances', 'path_mappings', 'valid_users', 'libraries'];
    foreach (array_keys($old) as $k) {
        if (!array_key_exists($k, $defaults) && $k !== 'library_types') {
            $pv['dropped'][] = (string) $k;          // the name only: its value may be anything
        }
    }
    $cfg = [];
    foreach ($defaults as $k => $def) {
        if (in_array($k, $structured, true)) {
            continue;
        }
        if ($k === 'cache_path') {                   // the pool: one of this server's, never a default
            $v = is_string($old[$k] ?? null) ? rtrim($old[$k], '/') : '';
            if (!in_array($v, (array) $ctx['pools'], true)) {
                $pv['blockers'][] = ['key' => 'pool', 'params' => ['path' => mb_substr($v, 0, 200)]];
            }
            $cfg[$k] = $v;
            continue;
        }
        if (!array_key_exists($k, $old)) {
            $cfg[$k] = $def;
            $pv['defaults'][] = ['key' => $k, 'old' => $current !== null && array_key_exists($k, $current) ? embyImportShow($current[$k]) : null, 'value' => $def];
            continue;
        }
        $v = $old[$k];
        if (isset(EMBY_IMPORT_FIXED[$k])) {
            $cfg[$k] = EMBY_IMPORT_FIXED[$k];
            if (!is_string($v) || ($k === 'array_disks_glob' ? $v : rtrim($v, '/')) !== EMBY_IMPORT_FIXED[$k]) {
                $pv['jack'][] = ['key' => $k, 'old' => embyImportShow($v), 'new' => EMBY_IMPORT_FIXED[$k]];
            }
            continue;
        }
        if ($k === 'cache_budget' && is_string($v)) {
            $v = strtoupper(str_replace(' ', '', $v));
        }
        if (embyImportValueOk($k, $v, $def)) {
            $cfg[$k] = $v;
        } else {
            $cfg[$k] = $def;
            $pv['jack'][] = ['key' => $k, 'old' => embyImportShow($v), 'new' => $def];
        }
    }

    // servers: their mappings (the old global ones merged in, as EmbyCache does), the key stays here
    $global = is_array($old['path_mappings'] ?? null) ? $old['path_mappings'] : [];
    $instances = [];
    foreach (is_array($old['instances'] ?? null) ? $old['instances'] : [] as $n => $i) {
        if (!is_array($i)) {
            continue;
        }
        $url = rtrim(is_string($i['url'] ?? null) ? trim($i['url']) : '', '/');
        $name = mb_substr(trim(is_string($i['servername'] ?? null) ? $i['servername'] : ''), 0, 60) ?: 'Emby' . (count($instances) + 1);
        $key = is_string($i['api_key'] ?? null) ? trim($i['api_key']) : '';
        if ($key !== '') {
            $secrets[] = $key;                        // even one that isn't valid never goes to the page
        }
        $state = 'found';
        if (!preg_match('/^[A-Za-z0-9]{8,128}$/D', $key)) {
            $key = '';
            foreach ((array) ($current['instances'] ?? []) as $c) {
                if (is_array($c) && rtrim((string) ($c['url'] ?? ''), '/') === $url) {
                    $key = (string) ($c['api_key'] ?? '');
                }
            }
            $state = preg_match('/^[A-Za-z0-9]{8,128}$/D', $key) ? 'jack' : 'missing';
        }
        $maps = $bad = $show = [];
        // the old global mappings first, the server's own over them (as EmbyCache merges them)
        foreach (array_replace($global, is_array($i['path_mappings'] ?? null) ? $i['path_mappings'] : []) as $from => $to) {
            $from = rtrim((string) $from, '/');
            $to = is_string($to) ? rtrim($to, '/') : null;
            if ($from === '' || $to === null || !embyMappingOk($from, $to)) {
                $bad[] = mb_substr($from, 0, 200);
                continue;
            }
            $maps[$from] = $to;
            $show[] = ['from' => $from, 'to' => $to, 'there' => $to === '' ? true : embyImportThere($to, $ctx)];
        }
        $okUrl = (bool) preg_match('#^https?://\S+$#D', $url) && strlen($url) <= 500;
        $pv['instances'][] = ['servername' => $name, 'url' => embyImportMaskUrl(mb_substr($url, 0, 200)), 'key' => $state,
                              'mappings' => $show, 'bad_mappings' => $bad];
        if (!$okUrl) {
            $pv['blockers'][] = ['key' => 'bad_url', 'params' => ['server' => $name]];
        }
        if ($state === 'missing') {
            $pv['blockers'][] = ['key' => 'no_key', 'params' => ['server' => $name]];
        }
        $instances[] = ['servername' => $name, 'url' => $url, 'api_key' => $key, 'path_mappings' => $maps];
    }
    if (!$instances) {
        $pv['blockers'][] = ['key' => 'no_server'];
    }
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];

    // people: a list of ids, or {id: {budget}} when some have a budget of their own
    $vu = is_array($old['valid_users'] ?? null) ? $old['valid_users'] : [];
    $users = [];
    $badUsers = 0;
    foreach ($vu as $k => $v) {
        $id = array_is_list($vu) ? $v : $k;
        $opts = array_is_list($vu) ? null : $v;
        if (!(is_string($id) || is_int($id)) || !preg_match('/^[\w-]{1,64}$/D', (string) $id)) {
            $badUsers++;
            continue;
        }
        $b = is_array($opts) && is_string($opts['budget'] ?? null) ? strtoupper(str_replace(' ', '', $opts['budget'])) : '';
        if ($b !== '' && !preg_match('/^\d+(\.\d+)?[KMGTP]?B?$/D', $b)) {
            $badUsers++;
            $b = '';
        }
        $users[(string) $id] = $b !== '' ? ['budget' => $b] : (object) [];
    }
    $cfg['valid_users'] = array_filter($users, fn ($u) => is_array($u)) ? $users : array_map('strval', array_keys($users));
    $pv['users'] = ['n' => count($users), 'budgets' => count(array_filter($users, fn ($u) => is_array($u))), 'bad' => $badUsers];
    $cfg['libraries'] = array_values(array_filter(is_array($old['libraries'] ?? null) ? $old['libraries'] : [], fn ($l) => is_string($l) && strlen($l) <= 200));
    $pv['libraries'] = $cfg['libraries'];
    // what kind each library is: only the office's overview — kept from Jack's own where the name matches
    $cfg['library_types'] = array_intersect_key(is_array($current['library_types'] ?? null) ? $current['library_types'] : [], array_flip($cfg['libraries']));

    $listed = array_merge(['instances', 'path_mappings', 'valid_users', 'library_types', 'libraries'], array_column($pv['defaults'], 'key'), array_column($pv['jack'], 'key'));
    foreach ($cfg as $k => $v) {
        if (in_array($k, $listed, true)) {
            continue;                                  // shown with the servers, people, defaults or Jack's own
        }
        if ($current !== null && array_key_exists($k, $current) && $current[$k] === $v) {
            $pv['same']++;
        } else {
            $pv['changes'][] = ['key' => $k, 'old' => $current !== null && array_key_exists($k, $current) ? embyImportShow($current[$k]) : null, 'new' => embyImportShow($v)];
        }
    }
    return $cfg;
}

/** A value as the preview shows it (strings capped; lists and objects as JSON) */
function embyImportShow(mixed $v): mixed
{
    if (is_string($v)) {
        return mb_substr($v, 0, 200);
    }
    if (is_array($v) || is_object($v)) {
        return mb_substr(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 0, 200);
    }
    return $v;
}

/** Is an old value valid for EmbyCache here? Its type as the default's, the few choices it knows, nothing that runs or writes elsewhere */
function embyImportValueOk(string $key, mixed $v, mixed $def): bool
{
    return match (true) {
        $key === 'mover_bin'      => in_array($v, EMBY_MOVER_BINS, true),
        $key === 'rsync_args'     => is_array($v) && array_is_list($v) && $v && count($v) <= 20
                                     && !array_filter($v, fn ($a) => !is_string($a) || (!preg_match(EMBY_RSYNC_SHORT, $a) && !in_array($a, EMBY_RSYNC_LONG, true))),
        $key === 'cache_budget'   => is_string($v) && ($v === '' || (bool) preg_match('/^\d+(\.\d+)?[KMGTP]?B?$/D', $v)),
        $key === 'movie_mode'     => in_array($v, ['folder', 'file'], true),
        in_array($key, ['fill_tool', 'cleanup_tool'], true) => in_array($v, ['rsync', 'mover'], true),
        $key === 'array_source'   => in_array($v, ['user0', 'disk'], true),
        $key === 'mover_debug_level' => is_int($v) && $v >= 0 && $v <= 3,
        in_array($key, EMBY_IMPORT_PCT, true) => (is_int($v) || is_float($v)) && $v >= 0 && $v <= 100,
        is_bool($def)             => is_bool($v),
        $def === null             => $v === null || (is_int($v) && $v >= 0 && $v <= 100000),
        is_int($def), is_float($def) => (is_int($v) || is_float($v)) && $v >= 0 && $v <= 100000,
        is_string($def)           => is_string($v) && strlen($v) <= 200 && !preg_match('/[\x00-\x1f\x7f]/', $v),
        default                   => false,
    };
}

/** Is a mapping's host folder there on this server? null = its disks sleep, not looked at */
function embyImportThere(string $host, array $ctx): ?bool
{
    if (!preg_match('#^/mnt/user/([^/]+)(/.*)?$#D', $host, $m) || !in_array($m[1], (array) $ctx['shares'], true)) {
        return false;
    }
    if (($m[2] ?? '') === '') {
        return true;                                   // the share itself: its config is on the flash
    }
    foreach (embyImportShareBases($m[1], $ctx) as $b) {
        if (baseAsleep($b, (array) $ctx['asleep'])) {
            return null;
        }
    }
    return is_dir(($ctx['fs'] ?? '') . $host);
}

/** Would EmbyCache accept these settings? Its own save_config() + load_config() on a trial folder; null = yes, else why not */
function embyImportTrial(array $cfg, array $ctx): ?string
{
    $dir = ($ctx['tmp'] ?? RUN_DIR) . '/emby-import-trial.' . bin2hex(random_bytes(4));
    try {
        embyWriteSettings($cfg, $dir, (string) ($ctx['tmp'] ?? RUN_DIR));
        return null;
    } catch (Problem $p) {
        return (string) ($p->params['detail'] ?? $p->key);
    } finally {
        @unlink("$dir/embycache_settings.json");
        @unlink("$dir/.embycache_settings.trial.json");
        @rmdir($dir);
    }
}

/** The gather's part: consolidate.ini onto Jack's gather settings (shares, free space, duplicates) */
function embyImportGather(string $dir, array $ctx, array $emby): array
{
    $text = embyImportRead($dir, EMBY_IMPORT_FILES['ini'][0], EMBY_IMPORT_FILES['ini'][1]);
    $pv = ['found' => $text !== null, 'blockers' => [], 'warnings' => [], 'changes' => [], 'jack' => [], 'dropped' => [],
           'shares' => [], 'dropped_shares' => [], 'strange' => 0];
    if ($text === null) {
        return [$pv + ['ok' => false], null];
    }
    [$vars, $pv['strange']] = embyImportIni($text);
    $cur = readJson($ctx['gather_dir'] . '/gather.json');
    $new = ['shares' => [], 'min_free_gb' => (int) ($cur['min_free_gb'] ?? 256), 'dup_check' => ($cur['dup_check'] ?? 'size') === 'cmp' ? 'cmp' : 'size'];
    $jack = ['LOGFILE' => $ctx['gather_dir'] . '/consolidate.log', 'ARRAY_PATTERN' => '/mnt/disk[0-9]*', 'CACHE_PATTERN' => null,
             'EXCLUDE_FILE' => $ctx['emby_dir'] . '/embycache_exclude.txt', 'DRYRUN' => 'true', 'CACHE_ONLY_TARGET' => 'skip', 'MOVE_CACHE' => 'false'];
    foreach ($vars as $k => $v) {
        if (in_array($k, GATHER_IMPORT_KEYS, true)) {
            continue;
        }
        if (!in_array($k, GATHER_IMPORT_JACK, true)) {
            $pv['dropped'][] = $k;
            continue;
        }
        $old = is_array($v) ? implode(' ', $v) : $v;
        if ($k === 'EXCLUDE_FILE' && $old !== '' && $old !== $jack[$k]) {
            $pv['warnings'][] = ['key' => 'gather_exclude', 'params' => ['path' => mb_substr($old, 0, 200)]];
        }
        if ($k !== 'CACHE_PATTERN' && $old !== $jack[$k]) {
            $pv['jack'][] = ['key' => $k, 'old' => mb_substr($old, 0, 200), 'new' => $jack[$k]];
        }
    }
    foreach (is_array($vars['BASE_DIRS'] ?? null) ? $vars['BASE_DIRS'] : (isset($vars['BASE_DIRS']) ? [$vars['BASE_DIRS']] : []) as $base) {
        $base = rtrim($base, '/');
        if (preg_match('#^/mnt/user/([\w.\- ]+)$#uD', $base, $m) && in_array($m[1], (array) $ctx['shares'], true)) {
            $new['shares'][] = $m[1];
        } else {
            $pv['dropped_shares'][] = ['path' => mb_substr($base, 0, 200),
                                       'why' => preg_match('#^/mnt/user/([\w.\- ]+)$#uD', $base) ? 'missing' : (str_starts_with($base, '/mnt/user/') ? 'not_share' : 'outside')];
        }
    }
    $new['shares'] = array_values(array_unique($new['shares']));
    foreach (['MIN_FREE_GB' => 'min_free_gb', 'DUP_CHECK' => 'dup_check'] as $ini => $key) {
        if (!array_key_exists($ini, $vars)) {
            continue;
        }
        $v = $vars[$ini];
        $ok = $key === 'min_free_gb' ? is_string($v) && preg_match('/^\d{1,6}$/D', $v) && (int) $v <= 100000 : in_array($v, ['size', 'cmp'], true);
        if ($ok) {
            $new[$key] = $key === 'min_free_gb' ? (int) $v : $v;
        } else {
            $pv['jack'][] = ['key' => $ini, 'old' => embyImportShow($v), 'new' => $new[$key]];
        }
    }
    $pools = embyGatherPools($new['shares'], $emby, $ctx['share_cfg']);
    $oldCache = $vars['CACHE_PATTERN'] ?? null;
    if ($oldCache !== null && (is_array($oldCache) ? implode(' ', $oldCache) : $oldCache) !== implode(' ', $pools)) {
        $pv['jack'][] = ['key' => 'CACHE_PATTERN', 'old' => mb_substr(is_array($oldCache) ? implode(' ', $oldCache) : $oldCache, 0, 200), 'new' => implode(' ', $pools)];
    }
    $pv['shares'] = $new['shares'];
    if (!$new['shares']) {
        $pv['blockers'][] = ['key' => 'no_share'];
    }
    foreach ($new as $k => $v) {
        $was = $cur[$k] ?? null;
        if ($was !== $v) {
            $pv['changes'][] = ['key' => $k, 'old' => $was === null ? null : embyImportShow($was), 'new' => embyImportShow($v)];
        }
    }
    $pv['ok'] = !$pv['blockers'];
    return [$pv, $pv['ok'] ? embyGatherCheck($new, (array) $ctx['shares']) : null];
}

/**
 * consolidate.ini as consolidate_master.sh would see it — read, never sourced: NAME=value lines
 * with '…', "…" (no $ or `) or plain words, NAME=( … ) for a list (over several lines too);
 * comments and empty lines skipped. Returns [name => value or list, lines not understood].
 */
function embyImportIni(string $text): array
{
    $vars = [];
    $strange = 0;
    $lines = preg_split('/\r?\n/', $text);
    for ($l = 0; $l < count($lines); $l++) {
        $line = trim($lines[$l]);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/sD', $line, $m)) {
            $strange++;
            continue;
        }
        $value = $m[2];
        if (str_starts_with($value, '(')) {
            $value = substr($value, 1);
            while (($words = embyImportWords($value, true)) === false && $l + 1 < count($lines) && strlen($value) < 65536) {
                $value .= "\n" . $lines[++$l];
            }
        } else {
            $words = embyImportWords($value, false);
        }
        if (!is_array($words)) {
            $strange++;
            continue;
        }
        $vars[$m[1]] = str_starts_with($m[2], '(') ? $words : ($words[0] ?? '');
    }
    return [$vars, $strange];
}

/**
 * Shell words without any expansion: '…' as is, "…" with \ escapes (a $ or ` in it = refused),
 * \x, plain characters. A list ends at ")"; after the value only blanks or a comment.
 * Returns the words, false when a list or a quote isn't closed yet, null when refused.
 */
function embyImportWords(string $s, bool $list): array|false|null
{
    $words = [];
    $cur = null;
    $n = strlen($s);
    for ($i = 0; $i < $n;) {
        $c = $s[$i];
        if ($c === "'") {
            $j = strpos($s, "'", $i + 1);
            if ($j === false) {
                return $list ? false : null;
            }
            $cur = ($cur ?? '') . substr($s, $i + 1, $j - $i - 1);
            $i = $j + 1;
        } elseif ($c === '"') {
            $buf = '';
            for ($i++; $i < $n && $s[$i] !== '"'; $i++) {
                if ($s[$i] === '$' || $s[$i] === '`') {
                    return null;
                }
                if ($s[$i] === '\\' && $i + 1 < $n && str_contains("\"\\\$`\n", $s[$i + 1])) {
                    $i++;
                }
                $buf .= $s[$i];
            }
            if ($i >= $n) {
                return $list ? false : null;
            }
            $cur = ($cur ?? '') . $buf;
            $i++;
        } elseif ($c === '\\' && $i + 1 < $n) {
            $cur = ($cur ?? '') . $s[$i + 1];
            $i += 2;
        } elseif ($c === ' ' || $c === "\t" || $c === "\n") {
            if ($cur !== null) {
                $words[] = $cur;
                $cur = null;
            }
            if (!$list && $c !== "\n") {           // after a value: only a comment may follow
                $rest = ltrim(substr($s, $i));
                return $rest === '' || $rest[0] === '#' ? $words : null;
            }
            $i++;
        } elseif ($list && $c === '#' && $cur === null) {
            $nl = strpos($s, "\n", $i);
            if ($nl === false) {
                return false;
            }
            $i = $nl;
        } elseif ($list && $c === ')') {
            if ($cur !== null) {
                $words[] = $cur;
            }
            $rest = ltrim(substr($s, $i + 1));
            return $rest === '' || $rest[0] === '#' ? $words : null;
        } elseif (str_contains('$`;&|<>()', $c) || ($list && (str_contains('*?[{', $c) || ($c === '~' && $cur === null)))) {
            return null;                               // something bash would run or expand
        } else {
            $cur = ($cur ?? '') . $c;
            $i++;
        }
    }
    if ($list) {
        return false;
    }
    if ($cur !== null) {
        $words[] = $cur;
    }
    return $words ?: [''];
}

/**
 * Does what the preview showed: a copy of each of Jack's files first (<file>.before-import-<time>,
 * next to it), then EmbyCache's settings (its own save_config(), trial first), the list and the
 * origins merged, the gather's settings — and the gather's ini follows EmbyCache's pool.
 */
function embyImportApply(array $do, array $ctx): array
{
    $stamp = date('Ymd-His');
    $backups = [];
    if ($do['embycache']) {
        $e = $do['embycache'];
        embyDataDir($ctx['emby_dir']);
        $backups = embyImportBackup($ctx['emby_dir'], array_filter(['embycache_settings.json' => $e['settings'] !== null,
            'embycache_exclude.txt' => $e['exclude'] !== null, 'embycache_origin.json' => $e['origin'] !== null]), $stamp);
        if ($e['settings'] !== null) {
            embyWriteSettings($e['settings'], $ctx['emby_dir'], (string) ($ctx['tmp'] ?? RUN_DIR));
        }
        if ($e['exclude'] !== null) {
            writeAtomic($ctx['emby_dir'] . '/embycache_exclude.txt', $e['exclude'] ? implode("\n", $e['exclude']) . "\n" : '', 0600, 0, 0);
        }
        if ($e['origin'] !== null) {
            writeAtomic($ctx['emby_dir'] . '/embycache_origin.json',
                json_encode((object) $e['origin'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 0600, 0, 0);
        }
    }
    $emby = readJson($ctx['emby_dir'] . '/embycache_settings.json') ?? [];
    if ($do['gather']) {
        $backups = array_merge($backups, embyImportBackup($ctx['gather_dir'], ['gather.json' => true, 'consolidate.ini' => true], $stamp));
        embySaveGather($do['gather'], $emby, $ctx['gather_dir'], $ctx['emby_dir']);
    } elseif ($do['embycache'] && ($g = readJson($ctx['gather_dir'] . '/gather.json')) && !empty($g['shares'])) {
        embyWriteGatherIni($g, $emby, $ctx['gather_dir'], $ctx['emby_dir']);     // follows the pool, like Jack's own save
    }
    return ['stamp' => $stamp, 'backups' => $backups, 'embycache' => $do['embycache'] !== null, 'gather' => $do['gather'] !== null,
            'settings' => ($do['embycache']['settings'] ?? null) !== null,
            'exclude' => isset($do['embycache']['exclude']) ? count($do['embycache']['exclude']) : null,
            'origin' => isset($do['embycache']['origin']) ? count($do['embycache']['origin']) : null];
}

/** A copy of each of these files of Jack's that exists (plain files only), named <file>.before-import-<time> */
function embyImportBackup(string $dir, array $names, string $stamp): array
{
    $made = [];
    foreach (array_keys($names) as $name) {
        $file = "$dir/$name";
        clearstatcache(true, $file);
        $st = @lstat($file);
        if (!$st || ($st['mode'] & 0170000) !== 0100000) {
            continue;
        }
        $copy = "$file.before-import-$stamp";
        writeAtomic($copy, (string) file_get_contents($file), 0600, 0, 0);
        $made[] = $copy;
    }
    return $made;
}

// ===================================================================== runs

/**
 * Starts a run from the page: through the host's atd as "php agent.php job
 * <tool> <mode> --office", so it lives on without the agent.
 */
function embyStart(string $tool, string $mode): array
{
    embyRunCheck($tool, $mode);
    hostLaunch("emby-$tool", [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', $tool, $mode, '--office']);
    logLine("Jack Emby: started $tool ($mode) via at");
    usleep(800000);
    return ['ok' => true, 'state' => embyScan()];
}

/** May this run start now? (also asked again by the job itself) */
function embyRunCheck(string $tool, string $mode): void
{
    if ($tool === 'embycache') {
        if (!isset(EMBY_MODES[$mode])) {
            throw new Problem('unknown_target', ['target' => $mode]);
        }
        if (!embyReadSettings()) {
            throw new Problem('emby_not_configured');
        }
        if ($mode === 'run' && !embyGatherReady()) {
            throw new Problem('emby_gather_first');
        }
    } else {
        if (!isset(GATHER_MODES[$mode])) {
            throw new Problem('unknown_target', ['target' => $mode]);
        }
        if (!embyGatherSettings() || !embyGatherSettings()['shares']) {
            throw new Problem('emby_gather_not_configured');
        }
    }
    if (embyJobInfo('embycache')['running'] || embyJobInfo('gather')['running']
        || flockHeld(EMBY_DATA . '/embycache.lock') || flockHeld(GATHER_LOCK)) {
        throw new Problem('emby_running');
    }
}

function embyAnyRunning(): bool
{
    return embyJobInfo('embycache')['running'] || embyJobInfo('gather')['running']
        || flockHeld(EMBY_DATA . '/embycache.lock') || flockHeld(GATHER_LOCK);
}

function embyToolDir(string $tool): string
{
    return $tool === 'gather' ? GATHER_DATA : EMBY_DATA;
}

/** The run started last (by the office or a schedule): mode, who, when, still at work? */
function embyJobInfo(string $tool): array
{
    $info = readJson(embyToolDir($tool) . '/office-run.json') ?? [];
    $pid = (int) ($info['pid'] ?? 0);
    $info['running'] = empty($info['finished']) && $pid > 1 && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php');
    $out = embyToolDir($tool) . '/office-output.txt';
    $info['size'] = (int) @filesize($out);
    $info['updated'] = @filemtime($out) ?: null;
    return $info;
}

/**
 * "php agent.php job embycache|gather [mode] [--office]" — what the cron file
 * (job.sh) and the page's runs call. Never both at once: a real
 * run holds the other tool's lock while it works. EmbyCache's real run waits
 * for the gather's first one. Returns the tool's exit code.
 */
function embyJob(string $tool, array $args): int
{
    $by = in_array('--office', $args, true) ? 'office' : 'schedule';
    $args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
    $mode = $args[0] ?? 'run';
    $dir = embyToolDir($tool);
    try {
        embyRunCheck($tool, $mode);
    } catch (Problem $p) {
        if ($p->key !== 'emby_not_configured' && $p->key !== 'emby_gather_not_configured') {
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]);
        }
        fwrite(STDERR, "$tool: not started ($p->key)\n");
        return 1;
    }
    // the other tool's lock, held for the whole run, so it can't start meanwhile
    $hold = null;
    if ($mode === 'run') {
        $hold = @fopen($tool === 'gather' ? EMBY_DATA . '/embycache.lock' : GATHER_LOCK, 'c');
        if (!$hold || !flock($hold, LOCK_EX | LOCK_NB)) {
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => 'emby_running']);
            return 1;
        }
    }
    embyDataDir($dir);
    $started = time();
    $run = ['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $started, 'pid' => getmypid()];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    @unlink("$dir/status.json");

    if ($tool === 'gather') {
        embyWriteGatherIni(embyGatherSettings() ?? [], embyReadSettings() ?? []);
        $cmd = array_merge(['bash', GATHER_APP . '/consolidate_master.sh'], GATHER_MODES[$mode]);
        $env = ['CONSOLIDATE_CONFIG' => "$dir/consolidate.ini", 'CONSOLIDATE_STATUS' => "$dir/status.json"];
        $cwd = '/';
    } else {
        $cmd = array_merge(['python3', EMBY_APP . '/embycache_run.py'], EMBY_MODES[$mode]);
        $env = embyPyEnv() + ['EMBYCACHE_STATUS' => "$dir/status.json"];
        $cwd = EMBY_APP;
    }
    $out = fopen("$dir/office-output.txt", 'w');
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8'] + $env;
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, $cwd, $env);
    $exit = is_resource($proc) ? proc_close($proc) : 127;
    fclose($out);
    if ($hold) {
        flock($hold, LOCK_UN);
        fclose($hold);
    }
    $status = readJson("$dir/status.json") ?? [];
    $run += ['finished' => time(), 'exit' => $exit];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    $result = (string) ($status['result'] ?? ($exit === 0 ? 'ok' : 'failed'));
    if ($tool === 'gather' && $mode === 'run' && in_array($result, ['ok', 'errors'], true)) {
        writeAtomic("$dir/last-real.json", jsonEncode($status + ['by' => $by]), 0600, 0, 0);
    }
    embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $started, 'finished' => time(),
                  'exit' => $exit, 'result' => $result, 'status' => $status]);
    try {
        embyNotify($tool, $mode, $result, $status, $exit);
    } catch (Throwable $e) {
        fwrite(STDERR, "$tool: notification failed: {$e->getMessage()}\n");
    }
    return $exit;
}

/**
 * How a real run ended, if Unraid should hear of it: failed, aborted, config
 * (EmbyCache didn't accept its settings) or errors (done, with problems).
 * Report and dry runs stay quiet, so do runs that never started (refused)
 * and EmbyCache's "busy" (another EmbyCache was at work).
 */
function embyNotifyOutcome(string $mode, string $result, array $status): ?string
{
    if ($mode !== 'run') {
        return null;
    }
    if (in_array($result, ['failed', 'aborted', 'config'], true)) {
        return $result;
    }
    return $result === 'errors' || ($result === 'ok' && (int) ($status['errors'] ?? 0) > 0) ? 'errors' : null;
}

/** A real run that went wrong goes to Unraid's notifications (warning) — from the tool's status file, never its log lines */
function embyNotify(string $tool, string $mode, string $result, array $status, int $exit): bool
{
    $outcome = embyNotifyOutcome($mode, $result, $status);
    if ($outcome === null) {
        return false;
    }
    $lang = officeNotifyLang();
    $problems = (int) ($status['errors'] ?? 0)
              + ($tool === 'gather' ? (int) ($status['conflicts'] ?? 0) + (int) ($status['full'] ?? 0) + (int) ($status['dirs_failed'] ?? 0) : 0);
    $message = trim((string) ($status['message'] ?? ''));
    if (!$status) {
        $detail = officeNotifyText('emby', 'notify.no_status', ['exit' => $exit], $lang);
    } elseif ($message !== '' && $message !== 'Signal') {
        $detail = $message;
    } else {
        $detail = $problems > 0 ? officeNotifyText('emby', 'notify.problems', ['n' => $problems], $lang) : '';
    }
    $sent = officeNotify(
        officeNotifyText('emby', 'notify.subject', ['tool' => officeNotifyText('emby', "notify.tool.$tool", [], $lang),
                                                     'result' => officeNotifyText('emby', "result.$outcome", [], $lang)], $lang),
        trim($detail . ' ' . officeNotifyText('emby', 'notify.see', [], $lang)),
        'warning', '', officeNotifyLink('#/emby'));
    if ($sent) {
        logLine("Jack Emby: told Unraid's notifications — $tool ($mode) $outcome");
    }
    return $sent;
}

/** Jack's own list of runs (both tools, newest first) */
function embyHistory(): array
{
    return (array) (readJson(EMBY_DATA . '/office-history.json')['runs'] ?? []);
}

function embyRemember(array $entry): void
{
    embyDataDir(EMBY_DATA);
    $lock = fopen(EMBY_DATA . '/office-history.lock', 'c');
    flock($lock, LOCK_EX);
    $runs = array_slice(array_merge([$entry], embyHistory()), 0, EMBY_HISTORY);
    writeAtomic(EMBY_DATA . '/office-history.json', jsonEncode(['runs' => $runs]), 0600, 0, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** The output of the last run of a tool, as plain text */
function embyOutput(string $tool): array
{
    $tool = $tool === 'gather' ? 'gather' : 'embycache';
    $text = (string) @file_get_contents(embyToolDir($tool) . '/office-output.txt', false, null, 0, 2 * 1024 * 1024);
    return ['ok' => true, 'info' => embyJobInfo($tool), 'text' => embyPlainOutput($text)];
}

/** Terminal output as text: progress lines (\r) collapsed, colour/erase codes and the log prefix gone */
function embyPlainOutput(string $text): string
{
    $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        $parts = explode("\r", $line);
        $last = '';
        foreach (array_reverse($parts) as $p) {
            if (trim($p) !== '') {
                $last = $p;
                break;
            }
        }
        if (count($parts) > 1 && $last !== '' && preg_match('/^\s*\[\d+\/\d+\] \d+%/', $last)) {
            continue;                                  // a progress line that was overwritten
        }
        $lines[] = $last;
    }
    return preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d,\d+ \| (?:INFO|DEBUG) \| /m', '', implode("\n", $lines));
}

function embyLog(string $tool): array
{
    $file = $tool === 'gather' ? GATHER_DATA . '/consolidate.log' : EMBY_DATA . '/logs/embycache.log';
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

// ===================================================================== schedules

/** EmbyCache's and the gather's schedule: a line in the office's cron file */
function embySetSchedule(string $job, mixed $cron): array
{
    if (!in_array($job, ['embycache', 'gather'], true)) {
        throw new Problem('unknown_target', ['target' => $job]);
    }
    $cron = is_string($cron) && trim($cron) !== '' ? trim($cron) : null;
    if ($cron !== null) {
        if ($job === 'embycache' && !embyReadSettings()) {
            throw new Problem('emby_not_configured');
        }
        if ($job === 'gather' && !(embyGatherSettings()['shares'] ?? [])) {
            throw new Problem('emby_gather_not_configured');
        }
    }
    $live = officeJobSetSchedule($job, $cron);
    logLine("Jack Emby: $job schedule " . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => embyScan()];
}

/**
 * Other things that start EmbyCache or the gather on their own: the user's
 * User Scripts entries and cron lines. They would run beside Jack's schedule,
 * outside his locks.
 */
function embyForeignSchedules(): array
{
    $found = [];
    $plans = (array) json_decode((string) @file_get_contents(US_SCHEDULE), true);
    foreach (glob(US_DIR . '/scripts/*/script') ?: [] as $file) {
        $name = basename(dirname($file));
        $text = (string) @file_get_contents($file, false, null, 0, 16384);
        $tool = str_contains($text, 'embycache_run.py') ? 'embycache' : (str_contains($text, 'consolidate_master.sh') ? 'gather' : null);
        if ($tool) {
            $freq = (string) ($plans[$file]['frequency'] ?? 'disabled');
            $found[] = ['tool' => $tool, 'where' => "User Scripts: $name", 'enabled' => !in_array($freq, ['', 'disabled'], true)];
        }
    }
    foreach (glob('/boot/config/plugins/*/*.cron') ?: [] as $file) {
        if ($file === OFFICE_CRON) {
            continue;
        }
        foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
            if ($line !== '' && $line[0] !== '#' && preg_match('/embycache_run\.py|consolidate_master\.sh/', $line, $m)
                && !str_contains($line, US_DIR)) {
                $found[] = ['tool' => str_starts_with($m[0], 'embycache') ? 'embycache' : 'gather', 'where' => $file, 'enabled' => true];
            }
        }
    }
    return $found;
}

// ===================================================================== checks (for the caretaker)

function embyChecks(): array
{
    $out = [];
    $emby = embyContainers();
    $out[] = finding('emby_container', 'recommended', (bool) $emby, [], 'docker');
    if (!$emby) {
        return $out;                       // without Emby nothing else matters for Jack
    }
    $out[] = finding('python', 'required', embyPython() !== null, [], 'apps');
    $settings = embyReadSettings();
    $out[] = finding('configured', 'required', $settings !== null && !empty($settings['instances']), [], '#/emby/setup');
    if (!$settings) {
        return $out;
    }
    $shares = embyShares($settings);
    $missing = array_column(array_filter($shares, fn ($s) => $s['root'] === false), 'share');
    $unseen = array_filter($shares, fn ($s) => $s['root'] === null);
    $out[] = finding('share_root', 'required', $missing ? false : ($unseen ? null : true), ['shares' => implode(', ', $missing), 'pool' => (string) ($settings['cache_path'] ?? '')], '#/emby');
    $unfit = array_column(array_filter($shares, fn ($s) => !in_array($s['fit'], ['ok', 'array_only'], true)), 'share');
    $out[] = finding('share_fit', 'recommended', !$unfit, ['shares' => implode(', ', $unfit)], '#/emby');
    $out[] = finding('gather_done', 'required', embyGatherReady(), [], '#/emby');
    $out[] = finding('schedule', 'recommended', officeJobSchedule('embycache')['enabled'], [], '#/emby/schedule');
    $out[] = finding('gather_schedule', 'recommended', officeJobSchedule('gather')['enabled'], [], '#/emby/gather-schedule');
    $foreign = array_filter(embyForeignSchedules(), fn ($f) => $f['enabled']);
    $out[] = finding('foreign', 'recommended', !$foreign, ['where' => implode(', ', array_column($foreign, 'where'))], 'userscripts');
    // Mover Tuning should leave EmbyCache's files on the pool alone
    $tuning = (string) @file_get_contents('/boot/config/plugins/ca.mover.tuning/ca.mover.tuning.cfg');
    $out[] = finding('mover_tuning', 'recommended', str_contains($tuning, 'embycache_exclude.txt'),
        ['file' => EMBY_DATA . '/embycache_exclude.txt'], 'settings');
    return $out;
}
