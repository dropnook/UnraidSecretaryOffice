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
// release: everything on the exclude list back to the array — only when he is let go (agent/desks/emby-letgo.php)
const EMBY_MODES    = ['report' => ['--show-on-deck', '--compact'], 'dry' => [], 'run' => ['--run'], 'release' => ['--release']];
// measure: a dry run over the shares of the chosen libraries, only for what lies where (#8) — asked from the page only
const GATHER_MODES  = ['dry' => ['--dryrun'], 'run' => ['--run'], 'measure' => ['--dryrun']];
const GATHER_CACHE_ONLY = ['skip', 'most-free'];   // a folder only on the cache, with the cache switched on: leave it, or the disk with most free space
const EMBY_SIZES_SHARES = 200;     // shares kept in sizes.json
const EMBY_SIZES_ROOTS  = 64;      // disks/pools per share
const EMBY_POOL_GROUPS  = 5000;    // what lies on the pool, by film/series folder: the current state, all of it (a bound against a broken list only)
const EMBY_SETTINGS = ['cache_path', 'cache_budget', 'number_episodes', 'movie_share_percent', 'max_episodes_per_series',
                       'max_resume_items', 'max_resume_movies', 'max_resume_series', 'max_favorite_series', 'use_next_up', 'min_free_percent', 'movie_mode',
                       'return_to_origin', 'array_source', 'array_path', 'user_path',
                       'array_disks_glob', 'create_share_root', 'mover_debug_level'];
// both ways always rsync (Benj, 2026-10-10, after Unraid's mover took EmbyCache's films back): EmbyCache keeps its mover
// path for standalone users, Jack never selects it — not in his setup, not from an old settings file, not in what he hands it
const EMBY_TOOLS_RSYNC = ['fill_tool' => 'rsync', 'cleanup_tool' => 'rsync'];
const GATHER_LOCK   = '/var/run/consolidate_master.lock';     // the gather's own lock (consolidate_master.sh)
const EMBY_HISTORY  = 40;
const EMBY_IGNORED  = '#^/(config|metadata|transcoding-temp|cache|logs|var|boot|tmp)#';   // Emby's own folders, never media
// Benj's rule (2026-10-06): never a real gather while someone watches Emby
const EMBY_WATCH_TIMEOUT = 5;       // seconds: an Emby that hasn't answered by then counts as down (the run may go)
const EMBY_WATCH_PAGE    = 2;       // … asked from the page (in the agent's loop): shorter — the job itself asks again
const EMBY_WATCH_EVERY   = 900;     // a scheduled gather that finds someone watching looks again every 15 min …
const EMBY_WATCH_MAX     = 7200;    // … for up to 2 h, then that night is skipped
const EMBY_WATCH_DURING  = 60;      // during a real gather Emby is asked once a minute; someone watching = stop after the current folder
const EMBY_WATCH_SHOWN   = 10;      // at most this many watchers kept

define('EMBY_APP', OFFICE_DIR . '/embycache');
define('GATHER_APP', OFFICE_DIR . '/gather');
define('EMBY_DATA', DATA_DIR . '/embycache');
define('GATHER_DATA', DATA_DIR . '/gather');

desk('emby', [
    'fit'     => function (): array {
        $emby = embyContainers();
        if (!$emby) {
            return fit(false, 'no_emby');
        }
        // real runs only while Unraid's mover schedule is «Disabled» (emby-mover.php) — his list he enters into Mover Tuning (if any) once hired
        $rule = embyMoverRule(false);
        return fit(true, !$rule['ok'] && $rule['why'] !== 'tuning_list' ? 'yes_dry' : 'yes', ['name' => $emby[0]['name']]);
    },
    'start'   => fn () => embyScan(),
    'actions' => [
        'refresh'      => fn (array $r) => ['ok' => true, 'state' => embyScan()],
        'connect'      => fn (array $r) => embyConnect(textField($r, 'url'), optText($r, 'api_key')),
        'save'         => fn (array $r) => embySave($r['settings'] ?? null),
        'gather_save'  => fn (array $r) => embyGatherSave($r['gather'] ?? null),
        'start_run'    => fn (array $r) => embyStart('embycache', textField($r, 'mode')),
        'gather_start' => fn (array $r) => embyStart('gather', textField($r, 'mode')),
        'schedule'     => fn (array $r) => embySetSchedule(textField($r, 'job'), cronField($r)),
        'output'       => fn (array $r) => embyOutput(textField($r, 'tool')),
        'log'          => fn (array $r) => embyLog(textField($r, 'tool')),
        'import_preview' => fn (array $r) => embyImportPreview($r),     // taking over an earlier install: what would come over
        'import_apply'   => fn (array $r) => embyImportDo($r),          // … and doing it, with the preview's token
        // his let-go dialog (agent/desks/emby-letgo.php): the look, then — still hired — schedules off and the tick's release
        'letgo_look'     => fn (array $r) => embyLetGoLook(),
        'letgo'          => fn (array $r) => embyLetGo($r),
        'letgo_seen'     => fn (array $r) => embyLetGoSeen(),
        // «Switch the mover schedule off…» (emby-mover.php): Unraid's own ⟦Mover schedule⟧ «Disabled», as its form sets it
        'mover_off'      => fn (array $r) => embyMoverOff($r) + ['state' => embyScan()],
        // the part «progress» (desk.json): the live panel while EmbyCache runs for real (emby-progress.php) — two RAM files
        'progress'       => fn (array $r) => ['ok' => true, 'state' => embyProgressState()],
        // «Stop after this file» in that panel: EmbyCache's stop request with why `user` (emby-progress.php)
        'stop'           => fn (array $r) => ['ok' => true, 'asked' => embyStopAsk(), 'state' => embyScan()],
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
            // runs that never started (refused, skipped while someone watched Emby) are no runs; one stopped for a watcher went well
            if (in_array($tool, ['embycache', 'gather'], true) && !isset($last[$tool]) && ($r['mode'] ?? '') === 'run'
                && !in_array($r['result'] ?? '', ['refused', 'skipped'], true)) {
                $last[$tool] = ['ok' => in_array($r['result'] ?? '', ['ok', 'stopped'], true), 'finished' => (int) ($r['finished'] ?? 0)];
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
    $jobs = ['embycache' => embyJobInfo('embycache'), 'gather' => embyJobInfo('gather')];
    $state = [
        'time'       => time(),
        'python'     => embyPython(),
        'emby'       => embyContainers(),
        'versions'   => embyVersions(),
        'configured' => $settings !== null && !empty($settings['instances']),
        'settings'   => $settings !== null ? embySettingsPublic($settings) : null,
        'shares'     => $settings !== null ? embySharesSized(embyShares($settings)) : [],
        'cache'      => embyCacheStats($settings),
        'pool'       => embyPoolUsage($settings),
        'gather'     => [
            'settings' => $gather,
            'ready'    => embyGatherReady(),
            'last'     => readJson(GATHER_DATA . '/last-real.json'),
            'waiting'  => embyGatherWaiting(),
        ],
        'jobs'       => $jobs,
        'progress'   => ($prog = embyProgressState($jobs['embycache']))['progress'],     // a real EmbyCache run: the live panel (emby-progress.php)
        'stopping'   => $prog['stopping'],         // … asked to stop after the file it is on (why: user, mover)
        'last'       => embyLastRun(),
        'history'    => array_slice(embyHistory(), 0, 20),
        'schedules'  => ['embycache' => officeJobSchedule('embycache'), 'gather' => officeJobSchedule('gather')],
        'foreign'    => embyForeignSchedules(),
        'pools'      => embyPools(),
        'share_info' => embyShareInfo(),
        'pool_dirs'  => embyPoolDirs(),
        'old_clone'  => is_dir(EMBY_DATA . '/app/.git'),
        // Unraid's mover: may real runs go (its schedule «Disabled»; Mover Tuning, if installed, with his list — entered here when it isn't), is it at work
        'mover'      => embyMoverState(),
        'waiting'    => ['embycache' => embyRunWaiting('embycache'), 'gather' => embyRunWaiting('gather')],
        'letgo'      => embyLetGoNote(),       // hired again: what he switched off when he was let go (said once)
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

function embyReadSettings(string $dir = EMBY_DATA): ?array
{
    $j = json_decode((string) @file_get_contents("$dir/embycache_settings.json"), true);
    return is_array($j) ? embyToolsRsync($j) : null;
}

/** EmbyCache's settings as Jack uses them: an old 'mover' (or none — EmbyCache's own default) reads as rsync; the next save writes it so */
function embyToolsRsync(array $s): array
{
    return array_replace($s, EMBY_TOOLS_RSYNC);
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

/**
 * The share rows with what lies where (#8): the gather's last numbers per share — any run that
 * indexed it, dry or real or a measurement; a share a later run didn't cover keeps its older
 * numbers with their date — and ZFS's own count of the share's dataset on its awake pools.
 */
function embySharesSized(array $rows, ?array $sizes = null, ?array $live = null): array
{
    $sizes ??= embyGatherSizes(readJson(GATHER_DATA . '/sizes.json'), readJson(GATHER_DATA . '/status.json'));
    $live ??= embyShareLive($rows);
    foreach ($rows as &$row) {
        $row['sizes'] = embyShareSized($sizes, (string) $row['share']);
        $row['live'] = $live[$row['share']] ?? [];
    }
    unset($row);
    return $rows;
}

/**
 * The kept numbers ($kept: sizes.json — `{v: 1, shares: {<share>: {at, mode, roots: {<disk>: {bytes, files}}}}}`)
 * with a gather's status on top ($status: its `sizes` / `sizes_at`): every share the run indexed gets the
 * run's numbers (when newer than the kept ones), the others keep theirs. Only exactly that shape is taken, from either side.
 */
function embyGatherSizes(?array $kept, ?array $status, ?string $mode = null): array
{
    $out = ['v' => 1, 'shares' => []];
    $take = function (mixed $roots): ?array {
        if (!is_array($roots) || count($roots) > EMBY_SIZES_ROOTS) {
            return null;
        }
        $ok = [];
        foreach ($roots as $name => $r) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $name) || !is_array($r)
                || !is_int($r['bytes'] ?? null) || !is_int($r['files'] ?? null) || $r['bytes'] < 0 || $r['files'] < 0) {
                return null;
            }
            $ok[$name] = ['bytes' => $r['bytes'], 'files' => $r['files']];
        }
        return $ok;
    };
    $shareOk = fn (mixed $s): bool => is_string($s) && (bool) preg_match('/^[\w.\- ]{1,100}$/uD', $s);
    if (($kept['v'] ?? null) === 1 && is_array($kept['shares'] ?? null)) {
        foreach ($kept['shares'] as $share => $e) {
            $roots = $take($e['roots'] ?? null);
            if ($shareOk($share) && $roots !== null && is_int($e['at'] ?? null) && is_string($e['mode'] ?? null)) {
                $out['shares'][$share] = ['at' => $e['at'], 'mode' => $e['mode'], 'roots' => $roots];
            }
        }
    }
    $at = $status['sizes_at'] ?? null;
    if (is_array($status['sizes'] ?? null) && is_int($at) && $at > 0) {
        $mode ??= ($status['mode'] ?? '') === 'run' ? 'run' : 'dry';
        foreach ($status['sizes'] as $share => $roots) {
            $roots = $take($roots);
            if ($shareOk($share) && $roots !== null && $at > ($out['shares'][$share]['at'] ?? 0)) {
                $out['shares'][$share] = ['at' => $at, 'mode' => $mode, 'roots' => $roots];
            }
        }
    }
    if (count($out['shares']) > EMBY_SIZES_SHARES) {
        uasort($out['shares'], fn ($a, $b) => $b['at'] <=> $a['at']);
        $out['shares'] = array_slice($out['shares'], 0, EMBY_SIZES_SHARES, true);
    }
    ksort($out['shares']);
    return $out;
}

/** After a gather: its numbers into sizes.json (merged with the kept ones) */
function embyGatherSizesKeep(array $status, string $mode, string $dir = GATHER_DATA): void
{
    if (!isset($status['sizes'])) {
        return;
    }
    $file = "$dir/sizes.json";
    writeAtomic($file, jsonEncode(embyGatherSizes(readJson($file), $status, $mode)), 0600, 0, 0);
}

/** One share's numbers for the page: the places holding files, the biggest first; null = never measured */
function embyShareSized(array $sizes, string $share): ?array
{
    $e = $sizes['shares'][$share] ?? null;
    if (!is_array($e)) {
        return null;
    }
    $roots = [];
    foreach ($e['roots'] as $name => $r) {
        if ($r['files'] > 0) {
            $roots[] = ['name' => (string) $name, 'bytes' => $r['bytes'], 'files' => $r['files']];
        }
    }
    usort($roots, fn ($a, $b) => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);
    return ['at' => $e['at'], 'mode' => $e['mode'], 'roots' => $roots];
}

/**
 * ZFS's own count, right now, of each share's dataset on the pools the share lives on (`used`:
 * with what its snapshots and datasets below hold) — one `zfs list -d 1` per pool, only on awake
 * pools; btrfs has no cheap count (a du would read every folder), so none there.
 */
function embyShareLive(array $rows, ?array $mounts = null, ?array $asleep = null, ?callable $zfs = null): array
{
    $mounts ??= mountTable();
    $asleep ??= sleepingDisks();
    $zfs ??= function (string $pool): string {
        [$exit, $out] = run(['zfs', 'list', '-Hp', '-o', 'name,used', '-d', '1', $pool], 10);
        return $exit === 0 ? $out : '';
    };
    $pools = [];
    foreach ($mounts as $m) {
        if ($m['fs'] === 'zfs' && preg_match('#^/mnt/([a-z0-9_-]+)$#D', $m['mount'], $x) && preg_match('/^[A-Za-z0-9_.-]+$/D', $m['source'])
            && !preg_match('/^(disk\d+|user0?|disks|remotes|addons|rootshare)$/D', $x[1])) {
            $pools[$x[1]] = $m['source'];
        }
    }
    $want = [];
    foreach ($rows as $row) {
        if (strtolower((string) ($row['use'] ?? '')) === 'no') {
            continue;
        }
        foreach ([(string) ($row['primary'] ?? ''), (string) ($row['secondary'] ?? '')] as $pool) {
            if ($pool !== '' && isset($pools[$pool]) && !baseAsleep($pool, $asleep)) {
                $want[$pool][] = (string) $row['share'];
            }
        }
    }
    $out = [];
    foreach ($want as $pool => $shares) {
        $used = [];
        foreach (preg_split('/\R/', $zfs($pools[$pool])) as $line) {
            $f = explode("\t", $line);
            if (count($f) === 2 && ctype_digit($f[1])) {
                $used[$f[0]] = (int) $f[1];
            }
        }
        foreach ($shares as $share) {
            if (isset($used[$pools[$pool] . '/' . $share])) {
                $out[$share][] = ['pool' => $pool, 'used' => $used[$pools[$pool] . '/' . $share]];
            }
        }
    }
    return $out;
}

/** The shares a measurement reads: those behind the chosen libraries, as far as they exist */
function embyMeasureShares(?array $settings = null, ?array $all = null): array
{
    $settings ??= embyReadSettings();
    return $settings ? array_values(array_intersect(embyMappedShares($settings), $all ?? embyAllShares())) : [];
}

/** The measurement's own ini (the gather's consolidate.ini stays as it is): the libraries' shares, their pools and EmbyCache's */
function embyWriteMeasureIni(array $shares, array $emby, string $gatherDir = GATHER_DATA, string $embyDir = EMBY_DATA): void
{
    $g = embyGatherSettings() ?? [];
    $set = ['shares' => $shares, 'min_free_gb' => (int) ($g['min_free_gb'] ?? 256), 'dup_check' => 'size', 'move_cache' => false];   // measuring moves nothing
    writeAtomic("$gatherDir/measure.ini", embyGatherIni($set, embyGatherPools($shares, $emby), "$gatherDir/consolidate.log", "$embyDir/embycache_exclude.txt"), 0600, 0, 0);
}

// ===================================================================== what's on the pool

/**
 * What EmbyCache keeps on the pool right now (its exclude list: absolute pool paths), by film or series folder:
 * files, bytes, the disks they go back to and `since` — when the newest of them came onto the pool (its ctime: the
 * copy made it; read with the size, one stat). Newest first; the page filters and pages them.
 */
function embyCacheStats(?array $settings, string $dir = EMBY_DATA): array
{
    $list = @file("$dir/embycache_exclude.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $origin = readJson("$dir/embycache_origin.json") ?? [];
    $bytes = 0;
    $groups = [];
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    foreach ($list as $path) {
        $size = (int) @filesize($path);
        $since = (int) @filectime($path);          // PHP's stat cache: the same stat as the size
        $bytes += $size;
        // group by share and the first folder below it (a film folder or a series)
        $rel = $cache !== '' && str_starts_with($path, "$cache/") ? substr($path, strlen($cache) + 1) : ltrim($path, '/');
        $parts = explode('/', $rel);
        $key = $parts[0] . '/' . ($parts[1] ?? '');
        $groups[$key] ??= ['share' => $parts[0], 'title' => $parts[1] ?? $parts[0], 'files' => 0, 'bytes' => 0, 'origin' => [], 'since' => null];
        $groups[$key]['files']++;
        $groups[$key]['bytes'] += $size;
        if ($since > 0 && $since > (int) $groups[$key]['since']) {
            $groups[$key]['since'] = $since;
        }
        if (is_string($origin[$path] ?? null)) {
            $groups[$key]['origin'][$origin[$path]] = true;
        }
    }
    foreach ($groups as &$g) {
        $g['origin'] = array_keys($g['origin']);
    }
    unset($g);
    usort($groups, fn ($a, $b) => [(int) $b['since'], $a['title']] <=> [(int) $a['since'], $b['title']]);
    return ['files' => count($list), 'bytes' => $bytes, 'groups' => array_slice(array_values($groups), 0, EMBY_POOL_GROUPS),
            'listed_at' => @filemtime("$dir/embycache_exclude.txt") ?: null];
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
        if (!is_array($i)) {
            throw new Problem('bad_request');
        }
        $url = rtrim(optText($i, 'url'), '/');
        $key = trim(optText($i, 'api_key')) ?: embyStoredKey($url);
        if (!preg_match('#^https?://\S+$#D', $url) || !preg_match('/^[A-Za-z0-9]{8,128}$/D', $key)) {
            throw new Problem('emby_need_key');
        }
        $maps = [];
        foreach ((array) ($i['path_mappings'] ?? []) as $from => $to) {
            if (is_string($from) && is_string($to) && embyMappingOk($from, $to)) {
                $maps[rtrim($from, '/')] = rtrim($to, '/');        // '' = this folder deliberately not cached
            }
        }
        $name = mb_substr(trim(optText($i, 'servername')), 0, 60) ?: 'Emby' . (count($instances) + 1);
        $instances[] = ['servername' => $name, 'url' => $url, 'api_key' => $key, 'path_mappings' => $maps];
    }
    if (!$instances) {
        throw new Problem('emby_need_key');
    }
    $cfg = $cur;
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];
    // the page sends the whole form: a list missing would empty it unasked (QA 2026-10-08, finding 5)
    foreach (['libraries', 'library_types', 'valid_users', 'user_budgets'] as $k) {
        if (!is_array($in[$k] ?? null)) {
            throw new Problem('bad_request');
        }
    }
    $cfg['libraries'] = array_values(array_filter($in['libraries'], 'is_string'));
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
            $b = trim(optText($budgets, $id));
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
    $cfg = embyToolsRsync($cfg);                       // a fill_tool/cleanup_tool the request carries is ignored: always rsync
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
    $cfg = embyToolsRsync($cfg);                       // whatever writes Jack's settings (his setup, the import): rsync both ways
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

/** What EmbyCache's Python always gets: its data folder, no bytecode next to the code, UTF-8 output — and rsync both ways (over any settings file) */
function embyPyEnv(): array
{
    return ['EMBYCACHE_DIR' => EMBY_DATA, 'PYTHONDONTWRITEBYTECODE' => '1', 'PYTHONIOENCODING' => 'utf-8', 'PYTHONUNBUFFERED' => '1',
            'EMBYCACHE_FILL_TOOL' => EMBY_TOOLS_RSYNC['fill_tool'], 'EMBYCACHE_CLEANUP_TOOL' => EMBY_TOOLS_RSYNC['cleanup_tool']];
}

// ===================================================================== the gather's settings

function embyGatherSettings(): ?array
{
    return readJson(GATHER_DATA . '/gather.json');
}

/** The gather's own settings (works without EmbyCache being set up): shares, minimum free space, duplicates, the cache (#14) */
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
    // both said: a missing one would put the default back unasked (QA 2026-10-08, finding 5)
    $min = $in['min_free_gb'] ?? null;
    if (!(is_int($min) || (is_string($min) && preg_match('/^\d{1,6}$/D', $min))) || (int) $min < 0 || (int) $min > 100000) {
        throw new Problem('emby_bad_number', ['field' => 'min_free_gb']);
    }
    if (!in_array($in['dup_check'] ?? null, ['size', 'cmp'], true)) {
        throw new Problem('bad_request');
    }
    // the cache switch (#14) and what happens to folders only on the cache: said too, like the others
    if (!is_bool($in['move_cache'] ?? null) || !in_array($in['cache_only_target'] ?? null, GATHER_CACHE_ONLY, true)) {
        throw new Problem('bad_request');
    }
    return ['shares' => $shares, 'min_free_gb' => (int) $min, 'dup_check' => $in['dup_check'],
            'move_cache' => $in['move_cache'], 'cache_only_target' => $in['cache_only_target']];
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
 * quoted, the shares and pools checked against what exists. The cache only
 * when switched on (`move_cache`, #14; a gather.json from before it: off):
 * then the folders' files on the cache come to their disk, folders only on
 * the cache stay or go to the disk with most free space (`cache_only_target`).
 * What EmbyCache keeps on the pool always stays: its list is the gather's
 * exclude file.
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
        . 'MOVE_CACHE=' . (($gather['move_cache'] ?? false) === true ? 'true' : 'false') . "\n"
        . 'CACHE_ONLY_TARGET=' . $q(($gather['cache_only_target'] ?? '') === 'most-free' ? 'most-free' : 'skip') . "\n"
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
// set by Jack whatever the old file says (Unraid's views, the share configs, rsync both ways); the mover only as EmbyCache finds it itself
const EMBY_IMPORT_FIXED = ['array_path' => '/mnt/user0', 'user_path' => '/mnt/user', 'array_disks_glob' => '/mnt/disk[0-9]*',
                           'shares_cfg_dir' => '/boot/config/shares'] + EMBY_TOOLS_RSYNC;
const EMBY_MOVER_BINS   = ['', '/usr/libexec/unraid/move', '/usr/local/sbin/move', '/usr/local/bin/move'];
// rsync options an imported rsync_args may hold: plain switches only (no -e, no files, no remote shell)
const EMBY_RSYNC_SHORT  = '/^-[aAXHSDglopPrtuvhxWcm]+$/D';
const EMBY_RSYNC_LONG   = ['--numeric-ids', '--sparse', '--hard-links', '--acls', '--xattrs', '--preallocate', '--whole-file', '--no-whole-file',
                           '--checksum', '--inplace', '--partial', '--progress', '--human-readable', '--times', '--perms', '--owner', '--group',
                           '--archive', '--verbose', '--quiet'];
const EMBY_IMPORT_PCT   = ['min_free_percent', 'movie_share_percent'];
// a key the old install doesn't have and Jack has no value for: what Jack's setup page would choose
// (desk.js initForm()/saveSetup()), where it differs from EmbyCache's DEFAULTS; max_resume_movies/_series: max_resume_items
const EMBY_JACK_DEFAULTS = ['cleanup_tool' => 'rsync', 'fill_tool' => 'rsync', 'array_source' => 'user0', 'return_to_origin' => true,
                            'movie_mode' => 'folder', 'create_share_root' => false, 'mover_debug_level' => 0, 'number_episodes' => 3,
                            'movie_share_percent' => 50, 'max_episodes_per_series' => 0, 'max_favorite_series' => 10, 'use_next_up' => true,
                            'min_free_percent' => 20];
const GATHER_IMPORT_KEYS = ['BASE_DIRS', 'MIN_FREE_GB', 'DUP_CHECK', 'MOVE_CACHE', 'CACHE_ONLY_TARGET'];  // what Jack's gather settings take over
const GATHER_IMPORT_JACK = ['LOGFILE', 'ARRAY_PATTERN', 'CACHE_PATTERN', 'EXCLUDE_FILE', 'DRYRUN'];      // Jack's own

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
 * it doesn't know are left out; keys the old install doesn't have keep Jack's current value, or —
 * when he has none — Jack's own default (EMBY_JACK_DEFAULTS, as his setup page would choose);
 * values that aren't valid here or belong to the office are Jack's. Fills the preview's lists;
 * returns the settings to write.
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
            $mine = $current[$k] ?? null;
            $kept = $mine !== null && !isset(EMBY_IMPORT_FIXED[$k]) && embyImportValueOk($k, $mine, $def);
            $cfg[$k] = $kept ? $mine : embyImportJackDefault($k, $def, $old);
            $pv['defaults'][] = ['key' => $k, 'kept' => $kept, 'value' => embyImportShow($cfg[$k])];
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

/** Jack's own default for a key: what his setup page would choose, else EmbyCache's DEFAULTS */
function embyImportJackDefault(string $key, mixed $def, array $old): mixed
{
    if (isset(EMBY_IMPORT_FIXED[$key])) {
        return EMBY_IMPORT_FIXED[$key];
    }
    if (in_array($key, ['max_resume_movies', 'max_resume_series'], true)) {
        $items = $old['max_resume_items'] ?? null;
        return is_int($items) && $items >= 0 && $items <= 100000 ? $items : 10;
    }
    return array_key_exists($key, EMBY_JACK_DEFAULTS) ? EMBY_JACK_DEFAULTS[$key] : $def;
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
        isset(EMBY_TOOLS_RSYNC[$key]) => $v === 'rsync',          // never 'mover' (Jack never selects Unraid's move binary)
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

/** The gather's part: consolidate.ini onto Jack's gather settings (shares, free space, duplicates, the cache) */
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
    if ($cur !== null) {                                // a gather.json from before the cache switch: off, «leave them»
        $cur += ['move_cache' => false, 'cache_only_target' => 'skip'];
    }
    $new = ['shares' => [], 'min_free_gb' => (int) ($cur['min_free_gb'] ?? 256), 'dup_check' => ($cur['dup_check'] ?? 'size') === 'cmp' ? 'cmp' : 'size',
            'move_cache' => ($cur['move_cache'] ?? false) === true, 'cache_only_target' => ($cur['cache_only_target'] ?? '') === 'most-free' ? 'most-free' : 'skip'];
    $jack = ['LOGFILE' => $ctx['gather_dir'] . '/consolidate.log', 'ARRAY_PATTERN' => '/mnt/disk[0-9]*', 'CACHE_PATTERN' => null,
             'EXCLUDE_FILE' => $ctx['emby_dir'] . '/embycache_exclude.txt', 'DRYRUN' => 'true'];
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
    // MOVE_CACHE: the old install's own ini (its setup never wrote it — «--include-cache» on its command line isn't read)
    foreach (['MIN_FREE_GB' => 'min_free_gb', 'DUP_CHECK' => 'dup_check', 'MOVE_CACHE' => 'move_cache', 'CACHE_ONLY_TARGET' => 'cache_only_target'] as $ini => $key) {
        if (!array_key_exists($ini, $vars)) {
            continue;
        }
        $v = $vars[$ini];
        $ok = match ($key) {
            'min_free_gb'       => is_string($v) && preg_match('/^\d{1,6}$/D', $v) && (int) $v <= 100000,
            'dup_check'         => in_array($v, ['size', 'cmp'], true),
            'move_cache'        => in_array($v, ['true', 'false'], true),
            'cache_only_target' => in_array($v, GATHER_CACHE_ONLY, true),
        };
        if ($ok) {
            $new[$key] = match ($key) { 'min_free_gb' => (int) $v, 'move_cache' => $v === 'true', default => $v };
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

// ===================================================================== who watches Emby (before and during a real gather)

/*
 * Benj's rule (2026-10-06): Jack never starts a real gather while someone watches Emby — it moves
 * files across the array disks and wakes every one of them. Before each real run he asks every
 * Emby server of EmbyCache's settings for its /Sessions (the key stays in this process: PHP's
 * curl, a header in memory — never a command line, a log or the page's state). A session with a
 * NowPlayingItem (also paused) = someone watches — with its LastActivityDate (`seen`), so the page
 * can show a session left behind (a device asleep with a film paused, hours ago) and how to end it
 * in Emby. Emby that doesn't answer (refused, timeout) =
 * down: the run may go, said in the log. Emby that answers but not usably (401/403, another
 * status, no JSON) = don't start, say why. Dry runs are never asked about.
 */

/**
 * One GET of <url>/Sessions: HTTP status, body (capped), curl's error number and text.
 *
 * @return array{status: int, body: string, errno: int, error: string}
 */
function embyWatchFetch(string $url, string $key, int $timeout = EMBY_WATCH_TIMEOUT): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'errno' => -1, 'error' => 'no curl in PHP'];
    }
    $body = '';
    $c = curl_init(rtrim($url, '/') . '/Sessions');
    curl_setopt_array($c, [
        CURLOPT_HTTPHEADER     => ["X-Emby-Token: $key", 'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_USERAGENT      => 'unraid-secretary-office',
        CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$body): int {
            if (strlen($body) > 8 * 1024 * 1024) {
                return 0;                       // far more than sessions ever are: stop reading (no JSON then)
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    curl_setopt($c, defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS,
        defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : (CURLPROTO_HTTP | CURLPROTO_HTTPS));
    curl_exec($c);
    $out = ['status' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => $body, 'errno' => curl_errno($c), 'error' => curl_error($c)];
    unset($c);
    return $out;
}

/**
 * One server's answer, judged: free | watching (who: user, title, device, client, paused, seen) | down |
 * error (why: emby_watch_key, emby_watch_answer).
 *
 * @param array{status: int, body: string, errno: int, error: string} $r
 */
function embyWatchJudge(array $r): array
{
    $status = (int) ($r['status'] ?? 0);
    if ($status === 0) {
        // no HTTP answer at all: refused, timed out, name unknown, connection dropped — Emby is down
        $errno = (int) ($r['errno'] ?? 0);
        if (in_array($errno, [6, 7, 28, 52, 55, 56], true)) {
            return ['state' => 'down', 'detail' => embyWatchText((string) ($r['error'] ?? ''), 200)];
        }
        return ['state' => 'error', 'why' => 'emby_watch_answer', 'detail' => embyWatchText((string) ($r['error'] ?? '') ?: "curl $errno", 200)];
    }
    if ($status === 401 || $status === 403) {
        return ['state' => 'error', 'why' => 'emby_watch_key', 'detail' => "HTTP $status"];
    }
    if ($status !== 200) {
        return ['state' => 'error', 'why' => 'emby_watch_answer', 'detail' => "HTTP $status"];
    }
    $j = json_decode((string) ($r['body'] ?? ''), true);
    if (!is_array($j) || !array_is_list($j)) {
        return ['state' => 'error', 'why' => 'emby_watch_answer', 'detail' => 'no JSON'];
    }
    $who = [];
    foreach ($j as $s) {
        $np = is_array($s) ? ($s['NowPlayingItem'] ?? null) : null;
        if (!is_array($np) || !$np) {
            continue;
        }
        $title = embyWatchText((string) ($np['Name'] ?? ''));
        $series = embyWatchText((string) ($np['SeriesName'] ?? ''));
        if ($series !== '') {
            $se = is_int($np['ParentIndexNumber'] ?? null) && is_int($np['IndexNumber'] ?? null)
                ? sprintf('S%02dE%02d ', $np['ParentIndexNumber'], $np['IndexNumber']) : '';
            $title = trim("$series – $se$title");
        }
        $who[] = ['user' => embyWatchText((string) ($s['UserName'] ?? '')), 'title' => $title,
                  'device' => embyWatchText((string) ($s['DeviceName'] ?? '')), 'client' => embyWatchText((string) ($s['Client'] ?? '')),
                  'paused' => !empty($s['PlayState']['IsPaused']), 'seen' => embyWatchSeen($s['LastActivityDate'] ?? null)];
    }
    return $who ? ['state' => 'watching', 'who' => array_slice($who, 0, EMBY_WATCH_SHOWN)] : ['state' => 'free'];
}

/**
 * When Emby last heard from a session (its LastActivityDate, e.g. «2026-10-06T21:37:12.8805824Z»)
 * as a Unix time, or null: a device that went to sleep with a film paused stays «watching» for hours
 * — the page says how long ago that was. Only a full date with its zone counts; Emby's empty date
 * (year 1) is none; a clock ahead of ours is now. For the page and the list of runs, not the log.
 */
function embyWatchSeen(mixed $date, ?int $now = null): ?int
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})$/D', $date)) {
        return null;
    }
    // PHP's parser takes microseconds at most: Emby's seven digits of a second are cut to six
    $t = strtotime((string) preg_replace('/(\.\d{6})\d+/', '$1', $date));
    if ($t === false || $t < 946684800) {
        return null;
    }
    return min($t, $now ?? time());
}

/** A name from Emby as plain, short text (no control characters) */
function embyWatchText(string $s, int $max = 120): string
{
    $s = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($s, 'UTF-8')));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Who watches Emby right now, over every server in EmbyCache's settings: watching (anyone on any
 * server) > error (a server answered unusably) > free (one answered: nobody) > down (none answered);
 * unknown = no server with a key to ask. Never carries the key.
 *
 * @return array{state: string, who: list<array>, why?: string, detail?: string, url?: string, time: int}
 */
function embyWatching(?array $settings = null, ?callable $fetch = null): array
{
    $settings ??= embyReadSettings() ?? [];
    $fetch ??= 'embyWatchFetch';
    $seen = [];
    foreach ((array) ($settings['instances'] ?? []) as $i) {
        $url = rtrim((string) ($i['url'] ?? ''), '/');
        $key = (string) ($i['api_key'] ?? '');
        if (!preg_match('#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#D', $url) || $key === '') {
            continue;
        }
        $seen[] = embyWatchJudge($fetch($url, $key)) + ['url' => $url];
    }
    $time = time();
    $who = array_merge(...array_map(fn ($s) => $s['who'] ?? [], $seen ?: [[]]));
    if ($who) {
        return ['state' => 'watching', 'who' => array_slice($who, 0, EMBY_WATCH_SHOWN), 'time' => $time];
    }
    foreach (['error', 'free', 'down'] as $state) {
        foreach ($seen as $s) {
            if ($s['state'] === $state) {
                return ['state' => $state, 'who' => []] + array_intersect_key($s, ['why' => 1, 'detail' => 1, 'url' => 1]) + ['time' => $time];
            }
        }
    }
    return ['state' => 'unknown', 'who' => [], 'time' => $time];
}

/** «isp3: Film (iPad, paused); …» — for the office's log only */
function embyWatchersLine(array $who): string
{
    return implode('; ', array_map(fn ($w) => ($w['user'] ?: '?') . ': ' . ($w['title'] ?: '?')
        . ' (' . ($w['device'] ?: $w['client'] ?: '?') . ($w['paused'] ? ', paused' : '') . ')', $who));
}

/** A look that stops a run from the page: someone watches, or Emby's answer can't be used */
function embyWatchProblem(array $look): ?Problem
{
    return match ($look['state']) {
        'watching' => new Problem('emby_watching', ['who' => $look['who']]),
        'error'    => new Problem($look['why'] ?? 'emby_watch_answer', ['url' => $look['url'] ?? '', 'detail' => $look['detail'] ?? '']),
        default    => null,
    };
}

/**
 * Before a real run (php agent.php job gather run, embycache run|release): may it start? Unraid's mover at work (any
 * real run — emby-mover.php) or, before a real gather, someone watching Emby: from the page (or the let-go) → refused.
 * On schedule → wait: look again every EMBY_WATCH_EVERY (mover: EMBY_MOVER_EVERY) seconds up to EMBY_WATCH_MAX
 * (EMBY_MOVER_MAX), then skip. While waiting it holds only its own wait lock (never the gather's or EmbyCache's) and
 * shows itself in emby-<tool>-wait.json (`why`: watching | mover) — both in RAM (RUN_DIR), so a wait of hours keeps
 * nothing open on the pool; a second scheduled start meanwhile adds nothing (result `already`). A real run of this tool
 * that started meanwhile (from the page) ends the wait, so does the array stopping (`array`), so does its schedule being
 * switched off meanwhile (`off` — Jack let go).
 * $o: look, mover, sleep, now, array, scheduled (callables), watch (ask Emby's watchers: a real gather), dir (the tool's
 * data), waitdir, every, max — for the tests.
 *
 * @return array{go: bool, result?: string, why?: string, look: array, waited: int, lock: mixed}
 *   go true: start now; `lock` is the wait lock (or null) — release it with embyWaitEnd() once the run shows as running
 */
function embyRunGate(string $tool, string $by, array $o = []): array
{
    $watch = (bool) ($o['watch'] ?? ($tool === 'gather'));
    $ask = $o['look'] ?? fn () => embyWatching();
    $mover = $o['mover'] ?? fn (): bool => embyMoverRunning();
    $look = function () use ($watch, $ask, $mover): array {
        if ($mover()) {
            return ['state' => 'mover', 'who' => []];
        }
        return $watch ? $ask() : ['state' => 'free', 'who' => []];
    };
    $sleep = $o['sleep'] ?? fn (int $s) => sleep($s);
    $now = $o['now'] ?? fn () => time();
    $dir = $o['dir'] ?? embyToolDir($tool);
    $waitDir = $o['waitdir'] ?? RUN_DIR;
    $started = $o['array'] ?? fn () => (readCfg('/var/local/emhttp/var.ini')['fsState'] ?? '') === 'Started';
    $scheduled = $o['scheduled'] ?? fn () => officeJobSchedule($tool)['enabled'];
    $blocked = fn (array $w): bool => in_array($w['state'], ['watching', 'mover'], true);
    $whyOf = fn (array $w): string => $w['state'] === 'mover' ? 'emby_mover_running' : 'emby_watching';

    $w = $look();
    if (!$blocked($w)) {
        return embyGateVerdict($w, 0, null);
    }
    if ($by !== 'schedule') {
        return ['go' => false, 'result' => 'refused', 'why' => $whyOf($w), 'look' => $w, 'waited' => 0, 'lock' => null];
    }
    @mkdir($waitDir, 0700, true);
    $lock = @fopen("$waitDir/emby-$tool-wait.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return ['go' => false, 'result' => 'already', 'look' => $w, 'waited' => 0, 'lock' => null];
    }
    $since = $now();
    while (true) {
        $mov = $w['state'] === 'mover';
        $until = $since + (int) ($o['max'] ?? ($mov ? EMBY_MOVER_MAX : EMBY_WATCH_MAX));
        $next = min($now() + (int) ($o['every'] ?? ($mov ? EMBY_MOVER_EVERY : EMBY_WATCH_EVERY)), $until);
        writeAtomic("$waitDir/emby-$tool-wait.json", jsonEncode(['pid' => getmypid(), 'since' => $since, 'until' => $until, 'next' => $next,
                                                                 'looked' => $now(), 'who' => $w['who'], 'why' => $mov ? 'mover' : 'watching']), 0600, 0, 0);
        if ($now() >= $until) {
            embyWaitEnd($lock, $waitDir, $tool);
            return ['go' => false, 'result' => 'skipped', 'why' => $whyOf($w), 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $sleep(max(1, $next - $now()));
        if (!$started()) {
            embyWaitEnd($lock, $waitDir, $tool);     // the array stopped meanwhile: no data folder, no run now
            return ['go' => false, 'result' => 'array', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        if (!$scheduled()) {
            embyWaitEnd($lock, $waitDir, $tool);     // its schedule was switched off meanwhile (Jack let go): no run now
            return ['go' => false, 'result' => 'off', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $run = readJson("$dir/office-run.json") ?? [];
        if (($run['mode'] ?? '') === 'run' && (int) ($run['started'] ?? 0) >= $since) {
            embyWaitEnd($lock, $waitDir, $tool);     // a real run started from the page meanwhile: this one is done
            return ['go' => false, 'result' => 'meanwhile', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $w = $look();
        if (!$blocked($w)) {
            $v = embyGateVerdict($w, $now() - $since, $lock);
            if (!$v['go']) {
                embyWaitEnd($lock, $waitDir, $tool);
                $v['lock'] = null;
            }
            return $v;
        }
    }
}

/** A real gather's gate (embyRunGate() for the gather) — kept by its name */
function embyGatherGate(string $by, array $o = []): array
{
    return embyRunGate('gather', $by, $o);
}

/** The look that isn't «watching»: free/down/unknown → go; error → refused, why */
function embyGateVerdict(array $w, int $waited, mixed $lock): array
{
    if ($w['state'] === 'error') {
        return ['go' => false, 'result' => 'refused', 'why' => $w['why'] ?? 'emby_watch_answer', 'look' => $w, 'waited' => $waited, 'lock' => $lock];
    }
    return ['go' => true, 'look' => $w, 'waited' => $waited, 'lock' => $lock];
}

/** The wait is over: its file goes, its lock is let go */
function embyWaitEnd(mixed $lock, string $waitDir = RUN_DIR, string $tool = 'gather'): void
{
    if ($lock === null) {
        return;                            // no wait: nothing of a wait to remove (another start may be waiting)
    }
    @unlink("$waitDir/emby-$tool-wait.json");
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** A scheduled gather waiting for Emby to be free or the mover to finish (for the page), or null */
function embyGatherWaiting(string $waitDir = RUN_DIR): ?array
{
    return embyRunWaiting('gather', $waitDir);
}

/** A scheduled run of $tool that waits (why: watching | mover), or null */
function embyRunWaiting(string $tool, string $waitDir = RUN_DIR): ?array
{
    $w = readJson("$waitDir/emby-$tool-wait.json");
    if (!$w || !flockHeld("$waitDir/emby-$tool-wait.lock")) {
        return null;                       // a wait whose job is gone (reboot, killed) is no wait
    }
    return array_intersect_key($w, ['since' => 1, 'until' => 1, 'next' => 1, 'looked' => 1, 'who' => 1]) + ['why' => ($w['why'] ?? '') === 'mover' ? 'mover' : 'watching'];
}

/**
 * While a real run goes: is Unraid's mover at work? every $moverEvery seconds (any real run — emby-mover.php); and,
 * with $watchers (a real gather), Emby every $every seconds. The mover started, or someone watching → the stop request
 * ($stopFile: CONSOLIDATE_STOP, EMBYCACHE_STOP) is written once and the tool ends after the folder (the gather) or the
 * file (EmbyCache) it is on (exit 3, result `stopped`). Emby down or an unusable answer changes nothing during a run.
 * A stop request it didn't write (his panel's «Stop after this file», emby.stop) is noticed too and its why taken
 * (`user`). Returns the exit code, why it was asked to stop and who was watching then. $tick (EmbyCache's live panel)
 * is asked every half second.
 *
 * @param resource $proc
 * @return array{exit: int, stopped_for: ?array, stopped_why: ?string}
 */
function embyRunWatch($proc, string $stopFile, bool $watchers, ?callable $look = null, ?callable $mover = null,
                      int $every = EMBY_WATCH_DURING, int $moverEvery = EMBY_MOVER_LOOK, ?callable $tick = null): array
{
    $look ??= fn () => embyWatching();
    $mover ??= fn (): bool => embyMoverRunning(null, getmypid());
    $asked = null;
    $why = null;
    $last = $lastMover = time();
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            proc_close($proc);
            return ['exit' => (int) $st['exitcode'], 'stopped_for' => $why === 'watching' ? $asked : null, 'stopped_why' => $why];
        }
        if ($why === null) {
            clearstatcache(true, $stopFile);
            if (is_file($stopFile)) {         // asked from elsewhere (his panel): its why
                $j = json_decode((string) @file_get_contents($stopFile, false, null, 0, 65536), true);
                $why = in_array($j['why'] ?? null, ['mover', 'watching'], true) ? $j['why'] : 'user';
                $asked = $why === 'watching' && is_array($j['who'] ?? null) ? $j['who'] : null;
                logLine('Jack Emby: asked to stop (' . $why . ') — the ' . ($watchers ? 'gather stops after the current folder' : 'run stops after the current file'));
            }
        }
        if ($why === null && time() - $lastMover >= $moverEvery) {
            $lastMover = time();
            try {
                $on = $mover();
            } catch (Throwable) {
                $on = false;
            }
            if ($on) {
                $why = 'mover';
                writeAtomic($stopFile, jsonEncode(['time' => time(), 'why' => 'mover']), 0600, 0, 0);
                logLine('Jack Emby: Unraid\'s mover started — the ' . ($watchers ? 'gather stops after the current folder' : 'run stops after the current file'));
            }
        }
        if ($watchers && $why === null && time() - $last >= $every) {
            $last = time();
            try {
                $w = $look();
            } catch (Throwable) {
                $w = ['state' => 'error'];
            }
            if ($w['state'] === 'watching') {
                $why = 'watching';
                $asked = $w['who'];
                writeAtomic($stopFile, jsonEncode(['time' => time(), 'who' => $asked, 'why' => 'watching']), 0600, 0, 0);
                logLine('Jack Emby: someone started watching Emby — the gather stops after the current folder (' . embyWatchersLine($asked) . ')');
            }
        }
        if ($tick !== null) {
            $tick();                    // EmbyCache's live panel: a speed sample every 10 s (emby-progress.php)
        }
        usleep(500000);
    }
}

/** While a real gather runs (embyRunWatch() with Emby's watchers) — kept by its name */
function embyGatherWatch($proc, string $stopFile, ?callable $look = null, int $every = EMBY_WATCH_DURING, ?callable $mover = null): array
{
    return embyRunWatch($proc, $stopFile, true, $look, $mover, $every);
}

// ===================================================================== runs

/**
 * Starts a run from the page: through the host's atd as "php agent.php job
 * <tool> <mode> --office", so it lives on without the agent. A real gather
 * first asks Emby who watches (Benj's rule) — no override: whoever really
 * wants it ends that session in Emby (its dashboard's Stop, or on the device)
 * or stops the Emby service himself.
 */
function embyStart(string $tool, string $mode): array
{
    if ($mode === 'release') {
        throw new Problem('unknown_target', ['target' => $mode]);      // only his let-go brings everything back (emby.letgo)
    }
    embyRunCheck($tool, $mode);
    if ($mode === 'run' && embyMoverRunning()) {
        logLine("Jack Emby: $tool (run) not started — Unraid's mover is at work");
        throw new Problem('emby_mover_running');
    }
    if ($tool === 'gather' && in_array($mode, ['run', 'measure'], true)) {      // a measurement reads every disk of the shares: not while someone watches either
        // asked in the agent's own loop: a short look (an Emby that is slow counts as down here — the job asks again, fully)
        $look = embyWatching(null, fn (string $url, string $key) => embyWatchFetch($url, $key, EMBY_WATCH_PAGE));
        if ($p = embyWatchProblem($look)) {
            logLine("Jack Emby: gather ($mode) not started — " . ($look['state'] === 'watching' ? 'someone watches Emby: ' . embyWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            throw $p;
        }
    }
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
        if ($mode === 'measure') {
            if (!embyMeasureShares()) {
                throw new Problem('emby_measure_none');
            }
        } elseif (!embyGatherSettings() || !embyGatherSettings()['shares']) {
            throw new Problem('emby_gather_not_configured');
        }
    }
    if (embyJobInfo('embycache')['running'] || embyJobInfo('gather')['running']
        || flockHeld(EMBY_DATA . '/embycache.lock') || flockHeld(GATHER_LOCK)) {
        throw new Problem('emby_running');
    }
    // real runs only while Unraid's mover schedule is «Disabled» (emby-mover.php) — and Mover Tuning, if installed, keeps his list
    if ($mode === 'run' && ($p = embyMoverProblem(embyMoverRule()))) {
        throw $p;
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
    $by = in_array('--letgo', $args, true) ? 'letgo' : (in_array('--office', $args, true) ? 'office' : 'schedule');
    $args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
    $mode = $args[0] ?? 'run';
    $dir = embyToolDir($tool);
    try {
        embyRunCheck($tool, $mode);
    } catch (Problem $p) {
        if (!in_array($p->key, ['emby_not_configured', 'emby_gather_not_configured', 'emby_measure_none'], true)) {
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + array_intersect_key($p->params, ['schedule' => 1, 'shares' => 1, 'file' => 1, 'detail' => 1]));
            if (str_starts_with($p->key, 'emby_mover_')) {
                logLine("Jack Emby: $tool ($mode) not started — the mover could take EmbyCache's files ($p->key); only dry runs go");
            }
        }
        if ($mode === 'release') {
            embyReleaseRefusedTell($p->key);
        }
        fwrite(STDERR, "$tool: not started ($p->key)\n");
        return 1;
    }
    // a real run: never while Unraid's mover is at work; a real gather never while someone watches Emby (Benj's rule) —
    // asked before any lock is taken
    $wait = null;
    $note = [];
    $real = ($tool === 'gather' && $mode === 'run') || ($tool === 'embycache' && in_array($mode, ['run', 'release'], true));
    if ($real) {
        $asked = time();
        $gate = embyRunGate($tool, $by);
        $look = $gate['look'];
        if (!$gate['go']) {
            return embyGateRefused($gate, $tool, $mode, $by, $asked);
        }
        $wait = $gate['lock'];
        if ($gate['waited'] > 0) {
            $note['waited'] = $gate['waited'];
        }
        if (in_array($look['state'], ['down', 'unknown'], true)) {
            $note['emby'] = $look['state'];
            logLine('Jack Emby: ' . ($look['state'] === 'down' ? "Emby doesn't answer (" . ($look['detail'] ?? '') . ')' : 'no Emby server to ask')
                . ' — the gather goes ahead');
        }
        try {
            embyRunCheck($tool, $mode);           // after a wait: still nothing else at work?
        } catch (Problem $p) {
            embyWaitEnd($wait, RUN_DIR, $tool);
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key] + $note);
            if ($mode === 'release') {
                embyReleaseRefusedTell($p->key);
            }
            return 1;
        }
    }
    // a measurement from the page: asked again here, fully (Emby down = it goes, like a real gather)
    if ($tool === 'gather' && $mode === 'measure') {
        $look = embyWatching();
        if ($p = embyWatchProblem($look)) {
            logLine('Jack Emby: measuring not started — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . embyWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + ($p->key === 'emby_watching' ? ['who' => $look['who']] : ['detail' => (string) ($look['detail'] ?? '')]));
            return 1;
        }
    }
    // bringing everything back when he is let go (emby-letgo.php): never while someone watches — asked again here, fully
    if ($tool === 'embycache' && $mode === 'release') {
        $look = embyWatching();
        if ($p = embyLetGoWatchProblem($look)) {
            logLine('Jack Emby: the films are not brought back — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . embyWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + ($look['state'] === 'watching' ? ['who' => $look['who']] : ['detail' => (string) ($look['detail'] ?? '')]));
            embyReleaseRefusedTell($p->key);
            return 1;
        }
    }
    // the other tool's lock, held for the whole run, so it can't start meanwhile (a measurement too: EmbyCache moves files)
    $hold = null;
    if (in_array($mode, ['run', 'measure', 'release'], true)) {
        $hold = @fopen($tool === 'gather' ? EMBY_DATA . '/embycache.lock' : GATHER_LOCK, 'c');
        if (!$hold || !flock($hold, LOCK_EX | LOCK_NB)) {
            embyWaitEnd($wait, RUN_DIR, $tool);
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => 'emby_running']);
            if ($mode === 'release') {
                embyReleaseRefusedTell('emby_running');
            }
            return 1;
        }
    }
    embyDataDir($dir);
    $started = time();
    $run = ['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $started, 'pid' => getmypid()];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    embyWaitEnd($wait, RUN_DIR, $tool);          // the run shows as running now: a second start is refused by that
    @unlink("$dir/status.json");
    @unlink("$dir/office-stop.json");

    $progress = null;                    // a real EmbyCache run: its two RAM files for the live panel
    if ($tool === 'gather') {
        if ($mode === 'measure') {
            embyWriteMeasureIni(embyMeasureShares(), embyReadSettings() ?? []);
        } else {
            embyWriteGatherIni(embyGatherSettings() ?? [], embyReadSettings() ?? []);
        }
        $cmd = array_merge(['bash', GATHER_APP . '/consolidate_master.sh'], GATHER_MODES[$mode]);
        $env = ['CONSOLIDATE_CONFIG' => $mode === 'measure' ? "$dir/measure.ini" : "$dir/consolidate.ini", 'CONSOLIDATE_STATUS' => "$dir/status.json"]
             + ($mode === 'run' ? ['CONSOLIDATE_STOP' => "$dir/office-stop.json"] : []);
        $cwd = '/';
    } else {
        $cmd = array_merge(['python3', EMBY_APP . '/embycache_run.py'], EMBY_MODES[$mode]);
        $env = embyPyEnv() + ['EMBYCACHE_STATUS' => "$dir/status.json"] + ($real ? ['EMBYCACHE_STOP' => "$dir/office-stop.json"] : []);
        $cwd = EMBY_APP;
        if ($real) {                     // the live panel on his page (emby-progress.php): EmbyCache's progress in RAM
            $progress = embyProgressPaths();
            embyProgressClear($progress);
            $env['EMBYCACHE_PROGRESS'] = $progress['file'];
        }
    }
    $out = fopen("$dir/office-output.txt", 'w');
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8'] + $env;
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, $cwd, $env);
    $stoppedFor = $stoppedWhy = null;
    if (!is_resource($proc)) {
        $exit = 127;
    } elseif ($real) {
        // stops after a folder (the gather) or a file (EmbyCache) when the mover starts — or someone starts watching (the gather)
        ['exit' => $exit, 'stopped_for' => $stoppedFor, 'stopped_why' => $stoppedWhy] = embyRunWatch($proc, "$dir/office-stop.json", $tool === 'gather',
            tick: $progress !== null ? embyProgressTicker($progress) : null);
    } else {
        $exit = proc_close($proc);
    }
    fclose($out);
    @unlink("$dir/office-stop.json");
    if ($progress !== null) {
        embyProgressClear($progress);   // the panel goes with the run
    }
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
    if ($tool === 'gather') {
        try {
            embyGatherSizesKeep($status, $mode, $dir);       // what lies where, for his page (#8)
        } catch (Throwable $e) {
            fwrite(STDERR, "gather: sizes not kept: {$e->getMessage()}\n");
        }
    }
    if ($mode === 'release') {
        $c = (array) ($status['cleanup'] ?? []);
        logLine('Jack Emby: brought ' . (int) ($c['done'] ?? 0) . ' of ' . (int) ($c['planned'] ?? 0) . ' files back to the array ('
            . $result . ', ' . (int) ($status['protected'] ?? 0) . ' still on the pool)');
    }
    if ($result === 'stopped') {
        $note['why'] = in_array($stoppedWhy, ['mover', 'user'], true) ? $stoppedWhy : 'watching';
        if ($note['why'] === 'watching') {
            $note['who'] = $stoppedFor ?? [];
        }
        logLine('Jack Emby: ' . ($tool === 'gather' ? 'the gather stopped after ' . (int) ($status['folders_done'] ?? 0) . ' of ' . (int) ($status['folders'] ?? 0) . ' folders'
            : "EmbyCache ($mode) stopped after the file it was on") . ' — ' . match ($note['why']) {
                'mover' => "Unraid's mover started", 'user' => 'as asked on his page', default => 'someone watches Emby' });
    }
    embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $started, 'finished' => time(),
                  'exit' => $exit, 'result' => $result, 'status' => embyStatusShort($status)] + $note);
    try {
        embyNotify($tool, $mode, $result, $status, $exit, $note['why'] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, "$tool: notification failed: {$e->getMessage()}\n");
    }
    return $exit;
}

/**
 * A real run that doesn't start because of its gate (embyRunGate()): refused (Unraid's mover at work or someone watches,
 * from the page or the let-go; Emby's answer unusable), skipped (blocked for the gate's whole wait on schedule), or
 * nothing new (another scheduled start already waits; a real run from the page went meanwhile; the array stopped; the
 * schedule switched off). Logged; refused and skipped also in Jack's list of runs, with who watched — the page says it
 * was skipped and why; a refused release (his let-go) is told to Unraid's notifications too.
 */
function embyGateRefused(array $gate, string $tool, string $mode, string $by, int $asked): int
{
    $look = $gate['look'];
    $who = $look['who'] ?? [];
    $name = $tool === 'gather' ? 'gather' : 'EmbyCache';
    switch ($gate['result']) {
        case 'already':
            logLine("Jack Emby: a scheduled $tool run already waits — nothing new");
            return 0;
        case 'meanwhile':
            logLine("Jack Emby: the scheduled $tool run stops waiting — a real run was started from the page meanwhile");
            return 0;
        case 'array':
            logLine("Jack Emby: the scheduled $tool run stops waiting — the array was stopped");
            return 0;
        case 'off':
            logLine("Jack Emby: the scheduled $tool run stops waiting — its schedule was switched off");
            return 0;
        case 'skipped':
            $what = ($gate['why'] ?? '') === 'emby_mover_running' ? "Unraid's mover was at work" : 'someone watched Emby';
            logLine("Jack Emby: the scheduled $tool run is skipped — $what for " . intdiv($gate['waited'], 60) . ' min'
                . ($who ? ' (' . embyWatchersLine($who) . ')' : ''));
            fwrite(STDERR, "$tool: skipped ({$gate['why']})\n");
            embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $asked, 'finished' => time(), 'result' => 'skipped',
                          'why' => $gate['why'], 'who' => $who, 'waited' => $gate['waited']]);
            return 0;
    }
    $why = (string) ($gate['why'] ?? 'emby_watching');
    logLine("Jack Emby: $name ($mode) not started — " . match ($why) {
        'emby_watching'      => 'someone watches Emby: ' . embyWatchersLine($who),
        'emby_mover_running' => "Unraid's mover is at work",
        default              => "Emby's answer: $why " . ($look['detail'] ?? ''),
    });
    fwrite(STDERR, "$tool: not started ($why)\n");
    embyRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $asked, 'finished' => time(), 'result' => 'refused', 'why' => $why]
        + ($why === 'emby_watching' ? ['who' => $who] : ($why === 'emby_mover_running' ? [] : ['detail' => (string) ($look['detail'] ?? '')]))
        + ($gate['waited'] > 0 ? ['waited' => $gate['waited']] : []));
    if ($mode === 'release') {
        embyReleaseRefusedTell($why);
    }
    return 1;
}

/**
 * How a real run ended, if Unraid should hear of it: failed, aborted, config
 * (EmbyCache didn't accept its settings) or errors (done, with problems).
 * Report and dry runs stay quiet, so do runs that never started (refused)
 * and EmbyCache's "busy" (another EmbyCache was at work). A real run stopped
 * because Unraid's mover started ($why `mover`) is told (`stopped_mover`, normal), so is one the user asked to stop
 * on his page ($why `user`: `stopped_user`, normal).
 */
function embyNotifyOutcome(string $mode, string $result, array $status, ?string $why = null): ?string
{
    if ($result === 'stopped' && in_array($why, ['mover', 'user'], true) && in_array($mode, ['run', 'release'], true)) {
        return "stopped_$why";           // the mover started, or the user asked on his page: a normal note, no failure
    }
    if ($mode === 'release') {
        // bringing everything back when he was let go: his page is gone, so a good end is told too (`ok`, normal)
        if (in_array($result, ['failed', 'aborted', 'config', 'busy'], true)) {
            return $result;
        }
        return $result === 'errors' || (int) ($status['errors'] ?? 0) > 0 ? 'errors' : ($result === 'ok' ? 'ok' : 'failed');
    }
    if ($mode !== 'run') {
        return null;
    }
    if (in_array($result, ['failed', 'aborted', 'config'], true)) {
        return $result;
    }
    // a gather stopped because someone started watching Emby is no failure — only its errors are told
    return $result === 'errors' || (in_array($result, ['ok', 'stopped'], true) && (int) ($status['errors'] ?? 0) > 0) ? 'errors' : null;
}

/** A real run that went wrong goes to Unraid's notifications (warning) — from the tool's status file, never its log lines */
function embyNotify(string $tool, string $mode, string $result, array $status, int $exit, ?string $why = null): bool
{
    $outcome = embyNotifyOutcome($mode, $result, $status, $why);
    if ($outcome === null) {
        return false;
    }
    $lang = officeNotifyLang();
    $problems = (int) ($status['errors'] ?? 0)
              + ($tool === 'gather' ? (int) ($status['conflicts'] ?? 0) + (int) ($status['full'] ?? 0) + (int) ($status['dirs_failed'] ?? 0) : 0);
    $message = trim((string) ($status['message'] ?? ''));
    $c = (array) ($status['cleanup'] ?? []);
    if (in_array($outcome, ['stopped_mover', 'stopped_user'], true)) {
        $f = (array) ($status['fill'] ?? []);
        $detail = officeNotifyText('emby', "notify.$outcome" . ($tool === 'gather' ? '_gather' : ($mode === 'release' ? '_release' : '')),
            $tool === 'gather' ? ['done' => (int) ($status['folders_done'] ?? 0), 'total' => (int) ($status['folders'] ?? 0)]
                               : ['n' => (int) ($c['done'] ?? 0) + (int) ($f['done'] ?? 0)], $lang)
                . ($problems > 0 ? ' ' . officeNotifyText('emby', 'notify.problems', ['n' => $problems], $lang) : '');
    } elseif ($mode === 'release' && $status && in_array($outcome, ['ok', 'errors'], true)) {
        $left = (int) ($status['protected'] ?? 0);
        $detail = officeNotifyText('emby', 'notify.released', ['n' => (int) ($c['done'] ?? 0)], $lang)
                . ($left > 0 ? ' ' . officeNotifyText('emby', 'notify.released_left', ['n' => $left], $lang) : '')
                . ($problems > 0 ? ' ' . officeNotifyText('emby', 'notify.problems', ['n' => $problems], $lang) : '');
    } elseif (!$status) {
        $detail = officeNotifyText('emby', 'notify.no_status', ['exit' => $exit], $lang);
    } elseif ($message !== '' && $message !== 'Signal') {
        $detail = $message;
    } else {
        $detail = $problems > 0 ? officeNotifyText('emby', 'notify.problems', ['n' => $problems], $lang) : '';
    }
    $release = $mode === 'release';
    $sent = officeNotify(
        officeNotifyText('emby', $release ? 'notify.subject_release' : 'notify.subject', ['tool' => officeNotifyText('emby', "notify.tool.$tool", [], $lang),
                                                     'result' => officeNotifyText('emby', "result.$outcome", [], $lang)], $lang),
        trim($detail . ($release ? '' : ' ' . officeNotifyText('emby', 'notify.see', [], $lang))),
        ($release && $outcome === 'ok') || in_array($outcome, ['stopped_mover', 'stopped_user'], true) ? 'normal' : 'warning', '', $release ? null : officeNotifyLink('#/emby'));
    if ($sent) {
        logLine("Jack Emby: told Unraid's notifications — $tool ($mode) $outcome");
    }
    return $sent;
}

/**
 * Bringing the films back didn't start (someone watches, a run going…): he was let go, his page is gone — Unraid's
 * notifications say so (warning), with the reason in words.
 */
function embyReleaseRefusedTell(string $why): void
{
    try {
        $lang = officeNotifyLang();
        officeNotify(
            officeNotifyText('emby', 'notify.subject_release', ['tool' => officeNotifyText('emby', 'notify.tool.embycache', [], $lang),
                                                                 'result' => officeNotifyText('emby', 'result.refused', [], $lang)], $lang),
            officeNotifyText('emby', "errors.$why", [], $lang) . ' ' . officeNotifyText('emby', 'notify.release_stay', [], $lang), 'warning');
    } catch (Throwable $e) {
        fwrite(STDERR, "embycache: notification failed: {$e->getMessage()}\n");
    }
}

/** A run's status for his list of runs: the sizes stay in sizes.json, the list keeps how many shares were measured */
function embyStatusShort(array $status): array
{
    if (is_array($status['sizes'] ?? null)) {
        $status['measured'] = count($status['sizes']);
    }
    unset($status['sizes'], $status['sizes_at']);
    return $status;
}

/** Jack's own list of runs (both tools, newest first) */
function embyHistory(string $dir = EMBY_DATA): array
{
    return (array) (readJson("$dir/office-history.json")['runs'] ?? []);
}

/** A run (or one that didn't start) into his list of runs; $dir: the tests' */
function embyRemember(array $entry, string $dir = EMBY_DATA): void
{
    embyDataDir($dir);
    $lock = fopen("$dir/office-history.lock", 'c');
    flock($lock, LOCK_EX);
    $runs = embyHistory($dir);
    $top = $runs[0] ?? null;
    // a scheduled run refused again for the same reason (the mover's rule, every hour): one line, counted — not 40
    if (($entry['result'] ?? '') === 'refused' && ($entry['by'] ?? '') === 'schedule' && is_array($top)
        && array_intersect_key($top, array_flip(['tool', 'mode', 'by', 'result', 'why'])) == array_intersect_key($entry, array_flip(['tool', 'mode', 'by', 'result', 'why']))) {
        $entry += ['first' => (int) ($top['first'] ?? $top['started'] ?? 0), 'times' => (int) ($top['times'] ?? 1) + 1];
        array_shift($runs);
    }
    $runs = array_slice(array_merge([$entry], $runs), 0, EMBY_HISTORY);
    writeAtomic("$dir/office-history.json", jsonEncode(['runs' => $runs]), 0600, 0, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** One of his two tools — anything else is refused, never read as EmbyCache (QA 2026-10-08, finding 14: «../x» answered ok) */
function embyToolKnown(string $tool): void
{
    if (!in_array($tool, ['embycache', 'gather'], true)) {
        throw new Problem('unknown_target', ['target' => mb_substr($tool, 0, 40)]);
    }
}

/** The output of the last run of a tool, as plain text */
function embyOutput(string $tool): array
{
    embyToolKnown($tool);
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
    embyToolKnown($tool);
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
function embySetSchedule(string $job, ?string $cron): array
{
    if (!in_array($job, ['embycache', 'gather'], true)) {
        throw new Problem('unknown_target', ['target' => $job]);
    }
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
    if ($cron !== null) {
        embyLetGoSeen();        // switched on again: his page needn't say any more that the let-go switched it off
    }
    return ['ok' => true, 'live' => $live, 'state' => embyScan()];
}

/**
 * Other things that start EmbyCache or the gather on their own: the user's
 * User Scripts entries and cron lines. They would run beside Jack's schedule,
 * outside his locks. Only what really runs counts (inbox #9, 1.51.0: a line the
 * user had commented out kept the warning up): a script's or a cron file's
 * comment lines and `: <<'EOF'` blocks are no call (embyActiveText()), a User
 * Script whose schedule is off (none, «disabled», «custom» without a line) is
 * listed but not `enabled`. Read anew on every look at his state (embyScan():
 * his tour, every refresh, after each action) and by the caretaker's check.
 *
 * $usDir, $cronGlob, $officeCron: the places (the tests).
 */
function embyForeignSchedules(string $usDir = US_DIR, string $cronGlob = '/boot/config/plugins/*/*.cron', string $officeCron = OFFICE_CRON): array
{
    $found = [];
    // User Scripts keeps its schedules by the script's path; looked up by its folder like the watchman does
    $plans = [];
    foreach ((array) json_decode((string) @file_get_contents("$usDir/schedule.json", false, null, 0, 1 << 20), true) as $key => $plan) {
        if (is_string($key) && is_array($plan)) {
            $plans[basename(dirname($key))] = $plan;
        }
    }
    foreach (glob("$usDir/scripts/*/script") ?: [] as $file) {
        $name = basename(dirname($file));
        $text = embyActiveText((string) @file_get_contents($file, false, null, 0, 65536));
        $tool = str_contains($text, 'embycache_run.py') ? 'embycache' : (str_contains($text, 'consolidate_master.sh') ? 'gather' : null);
        if ($tool) {
            $plan = $plans[$name] ?? [];
            $freq = (string) ($plan['frequency'] ?? 'disabled');
            $on = !in_array($freq, ['', 'disabled'], true) && ($freq !== 'custom' || trim((string) ($plan['custom'] ?? '')) !== '');
            $found[] = ['tool' => $tool, 'where' => "User Scripts: $name", 'enabled' => $on];
        }
    }
    foreach (glob($cronGlob) ?: [] as $file) {
        if ($file === $officeCron) {
            continue;
        }
        foreach (explode("\n", embyActiveText((string) @file_get_contents($file, false, null, 0, 1 << 20))) as $line) {
            if (preg_match('/embycache_run\.py|consolidate_master\.sh/', $line, $m) && !str_contains($line, $usDir)) {
                $found[] = ['tool' => str_starts_with($m[0], 'embycache') ? 'embycache' : 'gather', 'where' => $file, 'enabled' => true];
            }
        }
    }
    return $found;
}

/**
 * A shell script's or a cron file's lines that may run something: comment lines (`#` after optional blanks) and the
 * blocks people comment out with `: <<'EOF'` … `EOF` (any word, quoted or not, also `<<-`) left out. A `#` later in
 * a line is left alone (it may lie in quotes).
 */
function embyActiveText(string $text): string
{
    $out = [];
    $until = null;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if ($until !== null) {
            if (trim($line) === $until) {
                $until = null;
            }
            continue;
        }
        if (preg_match('/^\s*:\s*<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1\s*$/D', $line, $m)) {
            $until = $m[2];
            continue;
        }
        if (preg_match('/^\s*(#|$)/', $line)) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

// ===================================================================== checks (for the caretaker)

/**
 * The Team Lead's point about the mover: `mover` (Unraid's own schedule must be «Disabled» — his page has the switch;
 * ok once it is and nothing of Mover Tuning's takes his files), else, with Unraid's schedule off, what keeps Mover
 * Tuning's own schedule from leaving his files alone: `mover_tuning` (his list couldn't be entered), `mover_force` (its
 * forced move on a schedule), `mover_override` (a share's override). Required: without it no real run goes. Links his
 * page.
 */
function embyMoverFinding(array $rule): array
{
    $id = match ($rule['why']) {
        'tuning_list'     => 'mover_tuning',
        'tuning_force'    => 'mover_force',
        'tuning_override' => 'mover_override',
        default           => 'mover',
    };
    $t = $rule['tuning'];
    return finding($id, 'required', $rule['ok'], array_filter([
        'file'     => $id === 'mover_tuning' ? (string) ($t['file'] ?? '') : null,
        'detail'   => $id === 'mover_tuning' ? (string) ($t['error'] ?? '') : null,
        'shares'   => $id === 'mover_override' ? implode(', ', $t['overrides'] ?? []) : null,
    ], fn ($v) => $v !== null), '#/emby');
}

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
    // Unraid's mover schedule «Disabled» (and Mover Tuning, if any, keeping his list), else only dry runs go (emby-mover.php): one point
    $out[] = embyMoverFinding(embyMoverRule());
    return $out;
}
