<?php
declare(strict_types=1);

/*
 * Mr. Restori — brings back what is gone, from what Mr. Backupsy's engine keeps:
 *
 *   packages   the backup place (backupDumpsPath()): per app apps/<app>/ (templates or compose
 *              files, database dumps, a media server's database copies, docker inspect of its
 *              containers) and per VM vms/<vm>/ (XML, NVRAM, TPM state), each with a
 *              manifest.json (engine 2.18+). Older nights' packages lie in the snapshots of the
 *              backup place's own share.
 *   snapshots  the local ZFS and btrfs snapshots holding an app's or a VM's folders: ZFS
 *              <dataset mountpoint>/.zfs/snapshot/<snap>/…, btrfs /mnt/<disk>/<btrfs_snap_dir>/<snap>/<share>/…
 *   Kopia      the offsite copies: per share, and per app or VM with a source of its own
 *              (<container path>/.apps/<app>, .vms/<vm>)
 *
 * His page is organised by app and VM: what can come back from where, with dates. He reads
 * his own packages (rsPackages) — desks know each other only through shared libraries.
 *
 * Never on a sleeping disk: a share whose disk sleeps is not looked into (its snapshots are
 * named "asleep"), the backup place on a sleeping disk keeps what was read before.
 */

const RS_TEMPLATES    = '/boot/config/plugins/dockerMan/templates-user';
const RS_COMPOSE_CFG  = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const RS_COMPOSE_DEF  = '/boot/config/plugins/compose.manager/projects';
const RS_LIBVIRT      = '/etc/libvirt';
const RS_SNAPS_MAX    = 60;          // snapshots kept per place (newest first)
const RS_NOT_BASES    = ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'];
const RS_DB_TYPES     = ['mariadb', 'postgres', 'mongodb'];
const RS_UUID         = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

define('RS_DATA', DATA_DIR . '/restore');

$GLOBALS['rs'] = ['state' => null];

desk('restore', [
    'fit'     => fn (): array => rsFit(),
    'start'   => function (): void {
        $GLOBALS['rs']['state'] = readJson(deskFile('restore'));
        rsScan();
    },
    'actions' => [
        'refresh'  => fn (array $r) => ['ok' => true, 'state' => rsScan()],
        'versions' => fn (array $r) => rsVersions(textField($r, 'kind'), textField($r, 'id')),
    ],
]);

// ===================================================================== the server, once per look

/**
 * What every look needs: the file system of each pool and disk, the ZFS datasets with their
 * mountpoints and snapshots, which disks sleep, the engine's settings. One zfs call each.
 */
function rsContext(array $settings): array
{
    $fs = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !in_array($x[1], RS_NOT_BASES, true)) {
            $fs[$x[1]] = $m['fs'];
        }
    }
    $mounts = [];
    $snaps = [];
    if (in_array('zfs', $fs, true) && bin('zfs')) {
        $r = runAll([
            'ds'    => ['zfs', 'list', '-H', '-o', 'name,mountpoint', '-t', 'filesystem'],
            'snaps' => ['zfs', 'list', '-Hp', '-t', 'snapshot', '-o', 'name,creation', '-s', 'creation'],
        ], 60);
        foreach (rows($r['ds'][1]) as $f) {
            if (count($f) >= 2 && str_starts_with($f[1], '/mnt/')) {
                $mounts[rtrim($f[1], '/')] = $f[0];
            }
        }
        foreach (rows($r['snaps'][1]) as $f) {
            if (count($f) >= 2 && str_contains($f[0], '@')) {
                [$ds, $name] = explode('@', $f[0], 2);
                $snaps[$ds][] = ['name' => $name, 'time' => num($f[1])];
            }
        }
    }
    return [
        'fs'        => $fs,
        'zfs'       => $mounts,                 // mountpoint => dataset
        'snaps'     => $snaps,                  // dataset => [{name, time}] oldest first
        'asleep'    => sleepingDisks(),
        'prefix'    => (string) backupSetting($settings, 'general', 'snap_prefix', 'unraidbackup-'),
        'btrfs_dir' => (string) backupSetting($settings, 'general', 'btrfs_snap_dir', '.btrfs-snap'),
        'settings'  => $settings,
        'cfg'       => [],
    ];
}

/** Unraid's settings of a share (on the flash) */
function rsShareCfg(string $share, array &$ctx): array
{
    if (!preg_match('/^[\w .-]+$/', $share)) {
        return [];
    }
    return $ctx['cfg'][$share] ??= readCfg("/boot/config/shares/$share.cfg");
}

/**
 * The pools and disks a share may lie on: its pools, the array disks it may use (Unraid's share
 * config), and what the engine noted at the setup — looked up, never read from the disks.
 */
function rsShareBases(string $share, array &$ctx): array
{
    $bases = array_filter(array_map('trim', explode(',', (string) backupSetting($ctx['settings'], "share|$share", 'locations', ''))));
    $cfg = rsShareCfg($share, $ctx);
    foreach (['shareCachePool', 'shareCachePool2'] as $k) {
        if (($cfg[$k] ?? '') !== '' && ($cfg['shareUseCache'] ?? 'no') !== 'no') {
            $bases[] = $cfg[$k];
        }
    }
    if (!$cfg || ($cfg['shareUseCache'] ?? 'no') !== 'only') {
        $include = array_filter(array_map('trim', explode(',', (string) ($cfg['shareInclude'] ?? ''))));
        $exclude = array_filter(array_map('trim', explode(',', (string) ($cfg['shareExclude'] ?? ''))));
        $disks = $include ?: array_filter(array_keys($ctx['fs']), fn ($d) => preg_match('/^disk\d+$/', (string) $d));
        $bases = array_merge($bases, array_diff($disks, $exclude));
    }
    return array_values(array_unique(array_filter($bases, fn ($b) => isset($ctx['fs'][$b]))));
}

/** A path under /mnt as [share, rel] (/mnt/user/<share>/<rel> or /mnt/<pool or disk>/<share>/<rel>), null otherwise */
function rsSharePath(string $path, array $ctx): ?array
{
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', rtrim($path, '/'), $m)) {
        return null;
    }
    if (!in_array($m[1], ['user', 'user0'], true) && !isset($ctx['fs'][$m[1]])) {
        return null;
    }
    $rel = trim($m[3] ?? '', '/');
    if ($m[2] === '' || $m[2][0] === '.' || str_contains("/$rel/", '/../') || str_contains("/$rel/", '/./')) {
        return null;
    }
    return [$m[2], $rel, in_array($m[1], ['user', 'user0'], true) ? null : $m[1]];
}

/**
 * Where a share path really lies and which local snapshots hold it: per pool or disk the live
 * path (/mnt/<base>/<share>/<rel>), whether it is there, and its snapshots, newest first. ZFS:
 * the dataset holding it (a folder may be a dataset of its own; datasets inside it are named —
 * a copy from the parent's snapshot would miss them); btrfs: the disk's snapshot folders that
 * hold it. A sleeping base is named, never looked into.
 */
function rsLocate(string $path, array &$ctx): array
{
    $sp = rsSharePath($path, $ctx);
    if (!$sp) {
        return [];
    }
    [$share, $rel, $only] = $sp;
    $bases = $only !== null ? [$only] : rsShareBases($share, $ctx);
    $places = [];
    foreach ($bases as $base) {
        $live = "/mnt/$base/$share" . ($rel !== '' ? "/$rel" : '');
        $p = ['base' => $base, 'fs' => $ctx['fs'][$base] ?? '', 'live' => $live, 'asleep' => baseAsleep($base, $ctx['asleep']),
              'exists' => false, 'dataset' => null, 'own_dataset' => false, 'inner' => [], 'snaps' => []];
        if ($p['asleep']) {
            $places[] = $p;
            continue;
        }
        clearstatcache(true, $live);
        $p['exists'] = file_exists($live);
        if (!$p['exists'] && !is_dir("/mnt/$base/$share")) {
            continue;                                   // the share doesn't lie there at all
        }
        if ($p['fs'] === 'zfs') {
            $best = null;
            foreach ($ctx['zfs'] as $mp => $ds) {
                if (under($live, $mp) && ($best === null || strlen($mp) > strlen($best))) {
                    $best = $mp;
                }
            }
            if ($best !== null) {
                $ds = $ctx['zfs'][$best];
                $p['dataset'] = $ds;
                $p['own_dataset'] = $best === $live;
                foreach ($ctx['zfs'] as $mp => $child) {
                    if ($mp !== $live && under($mp, $live)) {
                        $p['inner'][] = $child;
                    }
                }
                $inside = substr($live, strlen($best));
                foreach (array_reverse($ctx['snaps'][$ds] ?? []) as $s) {
                    $p['snaps'][] = ['id' => "$ds@{$s['name']}", 'name' => $s['name'], 'time' => $s['time'],
                                     'path' => "$best/.zfs/snapshot/{$s['name']}$inside", 'ours' => str_starts_with($s['name'], $ctx['prefix'])];
                    if (count($p['snaps']) >= RS_SNAPS_MAX) {
                        break;
                    }
                }
            }
        } elseif ($p['fs'] === 'btrfs') {
            $dir = "/mnt/$base/" . $ctx['btrfs_dir'];
            $names = array_filter(@scandir($dir, SCANDIR_SORT_DESCENDING) ?: [], fn ($n) => $n[0] !== '.');
            foreach ($names as $n) {
                $in = "$dir/$n/$share" . ($rel !== '' ? "/$rel" : '');
                if (!file_exists($in)) {
                    continue;
                }
                $t = preg_match('/(\d{4})(\d\d)(\d\d)-(\d\d)(\d\d)/', $n, $z)
                    ? (int) mktime((int) $z[4], (int) $z[5], 0, (int) $z[2], (int) $z[3], (int) $z[1]) : (int) @filemtime("$dir/$n");
                $p['snaps'][] = ['id' => "$base:$n", 'name' => $n, 'time' => $t, 'path' => $in, 'ours' => (bool) preg_match('/^\d{8}-\d{4}$/', $n)];
                if (count($p['snaps']) >= RS_SNAPS_MAX) {
                    break;
                }
            }
            usort($p['snaps'], fn ($a, $b) => $b['time'] <=> $a['time']);
        }
        $places[] = $p;
    }
    return $places;
}

// ===================================================================== the engine and its place

/** The engine: there at all, set up, busy (a run or its setup hold its lock), its Kopia */
function rsEngine(array $settings): array
{
    $data = BACKUP_DATA_DIR;
    $kopia = in_array(strtolower((string) backupSetting($settings, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    return [
        'found'    => is_file(BACKUP_SCRIPT_DIR . '/backup.sh'),
        'settings' => is_file("$data/settings.ini"),
        'busy'     => flockHeld("$data/state/lock"),
        'kopia'    => $kopia,
        'kopia_container' => (string) backupSetting($settings, 'kopia', 'container', ''),
        'mount_root' => (string) backupSetting($settings, 'general', 'mount_root', '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/snapshots'),
        'view_root'  => (string) backupSetting($settings, 'general', 'view_root', '/mnt/addons/' . BACKUP_OFFICE_SHARE . '/btrfs-snap'),
        'prefix'     => (string) backupSetting($settings, 'general', 'snap_prefix', 'unraidbackup-'),
        'flash'      => (string) backupSetting($settings, 'flash', 'mode', 'off'),
    ];
}

/** The backup place: where, whether its disk sleeps, and the local snapshots of its share (older packages) */
function rsPlace(array $settings, array &$ctx): array
{
    $share = (string) backupSetting($settings, 'general', 'dumps_share', '');
    $base = backupDumpsPath($share);
    $out = ['share' => $share, 'base' => $base, 'asleep' => false, 'found' => false, 'places' => []];
    if ($base === null) {
        return $out;
    }
    $out['places'] = rsLocate($base, $ctx);
    $out['asleep'] = (bool) array_filter($out['places'], fn ($p) => $p['asleep']);
    $out['found'] = !$out['asleep'] && (is_dir("$base/apps") || is_dir("$base/vms") || is_file("$base/server/run.json"));
    return $out;
}

/** The engine's history: per Kopia source when it last went well (history.jsonl, newest last) */
function rsKopiaLast(): array
{
    $last = [];
    $lines = @file(BACKUP_DATA_DIR . '/state/history.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_slice($lines, -60) as $line) {
        $j = json_decode($line, true);
        foreach (is_array($j) ? (array) ($j['kopia']['done'] ?? []) : [] as $k) {
            if (is_array($k) && !empty($k['ok']) && is_string($k['name'] ?? null)) {
                $last[$k['name']] = max($last[$k['name']] ?? 0, (int) ($k['finished'] ?? 0) ?: (int) ($j['started'] ?? 0));
            }
        }
    }
    return $last;
}

/**
 * The Kopia container and where it sees the snapshots: the container path of mount_root (Kopia
 * names its sources after it), and a writable folder mapped for restores, if there is one.
 */
function rsKopia(array $engine, array $settings): array
{
    $out = ['enabled' => $engine['kopia'], 'container' => null, 'running' => false, 'root' => null, 'restore' => null];
    if (!$engine['kopia']) {
        return $out;
    }
    $all = houseContainers();
    $name = $engine['kopia_container'];
    if ($name === '' || !isset($all[$name])) {
        $name = '';
        foreach ($all as $c) {
            if (stripos($c['image'], 'kopia') !== false) {
                $name = $c['name'];
                break;
            }
        }
    }
    foreach ((array) (readJson(BACKUP_DATA_DIR . '/state/drift.json')['policies'] ?? []) as $p) {
        if (is_array($p) && ($p['kind'] ?? '') === 'root' && is_string($p['path'] ?? null) && $p['path'] !== '') {
            $out['root'] = rtrim($p['path'], '/');
        }
    }
    if ($name === '') {
        return $out;
    }
    $out['container'] = $name;
    $out['running'] = !empty($all[$name]['running']);
    $inspect = houseInspect($name);
    foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
        $src = rtrim((string) ($m['Source'] ?? ''), '/');
        $dst = rtrim((string) ($m['Destination'] ?? ''), '/');
        if ($src === rtrim($engine['mount_root'], '/')) {
            $out['root'] ??= $dst;
        } elseif (!empty($m['RW']) && ($m['Type'] ?? '') === 'bind' && str_starts_with($src, '/mnt/')
            && (stripos($dst, 'restore') !== false || stripos(basename($src), 'restore') !== false)) {
            $out['restore'] = ['source' => $src, 'dest' => $dst];
        }
    }
    $out['root'] ??= '/backup-snapshots';
    return $out;
}

// ===================================================================== the packages

/**
 * The packages of a backup place (engine 2.18+), the way Mr. Restori needs them: per app its
 * containers with the folders they bind, files, dumps with the credentials' variable names,
 * templates, compose files; per VM its XML, NVRAM, TPM state and disks. Cached by mtimes.
 */
function rsPackages(string $base): array
{
    static $cache = [];
    $stamp = implode(':', array_map(fn ($p) => (int) @filemtime("$base/$p"), ['', 'apps', 'vms', 'server', 'server/run.json', 'flash']));
    if (($cache[$base][0] ?? null) === $stamp) {
        return $cache[$base][1];
    }
    $server = readJson("$base/server/run.json");
    $last = is_string($server['run'] ?? null) ? $server['run'] : '';
    $out = ['run' => $last ?: null, 'time' => rsRunTime($last), 'apps' => [], 'vms' => [], 'server' => null, 'flash' => null];
    foreach (['apps', 'vms'] as $sub) {
        foreach (@scandir("$base/$sub") ?: [] as $folder) {
            if ($folder[0] === '.' || !is_dir("$base/$sub/$folder")) {
                continue;
            }
            $m = readJson("$base/$sub/$folder/manifest.json");
            if (!$m) {
                continue;
            }
            $p = $sub === 'apps' ? rsAppPackage($m, $folder, "$base/$sub/$folder") : rsVmPackage($m, $folder, "$base/$sub/$folder");
            $p['stale'] = $last !== '' && $p['run'] !== '' && strcmp($p['run'], $last) < 0;
            $out[$sub][] = $p;
        }
    }
    usort($out['apps'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($out['vms'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    if ($server) {
        $files = rsFiles($server);
        $lv = array_values(array_filter($files, fn ($f) => $f['path'] === 'libvirt.tar.gz'))[0] ?? null;
        $out['server'] = [
            'path'      => "$base/server",
            'libvirt'   => $lv && is_file("$base/server/libvirt.tar.gz") ? "$base/server/libvirt.tar.gz" : null,
            'libvirt_time' => $lv['time'] ?? null,
            'libvirt_bytes' => $lv['bytes'] ?? null,
            'templates' => count(array_filter($files, fn ($f) => str_starts_with($f['path'], 'docker-templates/'))),
            'compose'   => array_values(array_unique(array_filter(array_map(fn ($f) => explode('/', $f['path'])[1] ?? '',
                               array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose/')))))),
            'shares'    => is_dir("$base/server/shares") ? "$base/server/shares" : null,
        ];
    }
    $flash = readJson("$base/flash/manifest.json");
    if ($flash && is_file("$base/flash/flash.tar.gz")) {
        $f = array_values(array_filter(rsFiles($flash), fn ($x) => $x['path'] === 'flash.tar.gz'))[0] ?? null;
        $out['flash'] = ['path' => "$base/flash/flash.tar.gz", 'time' => $f['time'] ?? rsRunTime((string) ($flash['run'] ?? '')),
                         'bytes' => (int) @filesize("$base/flash/flash.tar.gz")];
    }
    $cache = [$base => [$stamp, $out]];
    return $out;
}

function rsRunTime(string $run): ?int
{
    return preg_match('/^\d{8}-\d{4}$/', $run) ? ((DateTime::createFromFormat('Ymd-Hi', $run)?->getTimestamp()) ?: null) : null;
}

function rsStr(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** The files of a manifest: path, bytes, which run wrote it (a kept dump keeps its run), what it is */
function rsFiles(array $m): array
{
    $out = [];
    foreach ((array) ($m['files'] ?? []) as $f) {
        if (!is_array($f) || !is_string($f['path'] ?? null) || str_contains('/' . $f['path'] . '/', '/../')) {
            continue;
        }
        $run = rsStr($f['run'] ?? '');
        $out[] = ['path' => $f['path'], 'bytes' => (int) ($f['bytes'] ?? 0), 'run' => $run, 'time' => rsRunTime($run),
                  'what' => rsStr($f['what'] ?? ''), 'container' => rsStr($f['container'] ?? '')];
    }
    return $out;
}

/** The database type of a container, from its image and its environment (as the engine's setup reads it) */
function rsDbType(array $c): ?string
{
    $env = implode("\n", array_filter((array) ($c['inspect']['Config']['Env'] ?? []), 'is_string'));
    $image = strtolower(rsStr($c['image'] ?? ''));
    if (preg_match('/^(MARIADB_VERSION|MYSQL_VERSION|MARIADB_MAJOR|MYSQL_MAJOR)=/m', $env) || preg_match('#(^|/)(mariadb|mysql|percona)#', $image)) {
        return 'mariadb';
    }
    if (preg_match('/^(PG_MAJOR|PG_VERSION)=/m', $env) || preg_match('#(postgres|pgvecto|vectorchord|postgis|timescale)#', $image)) {
        return 'postgres';
    }
    if (preg_match('/^MONGO_VERSION=/m', $env) || preg_match('#(^|/)mongo#', $image)) {
        return 'mongodb';
    }
    if (preg_match('/^(REDIS_VERSION|VALKEY_VERSION)=/m', $env) || preg_match('#(^|/)(redis|valkey|keydb|memcached)#', $image)) {
        return 'cache';
    }
    return null;
}

function rsAppPackage(array $m, string $folder, string $path): array
{
    $files = rsFiles($m);
    $containers = [];
    foreach ((array) ($m['containers'] ?? []) as $c) {
        if (!is_array($c) || rsStr($c['name'] ?? '') === '') {
            continue;
        }
        $binds = [];
        foreach ((array) ($c['inspect']['Mounts'] ?? []) as $b) {
            if (is_array($b) && ($b['Type'] ?? '') === 'bind' && is_string($b['Source'] ?? null)) {
                $binds[] = ['source' => rtrim($b['Source'], '/'), 'dest' => rsStr($b['Destination'] ?? ''), 'rw' => !empty($b['RW'])];
            }
        }
        $containers[] = [
            'name'     => rsStr($c['name']),
            'image'    => rsStr($c['image'] ?? ''),
            'digest'   => rsStr(((array) ($c['digests'] ?? []))[0] ?? ''),
            'template' => rsStr($c['template'] ?? ''),
            'service'  => rsStr($c['service'] ?? ''),
            'db'       => rsDbType($c),
            'binds'    => $binds,
        ];
    }
    $byPath = [];
    foreach ($files as $f) {
        $byPath[$f['path']] = $f;
    }
    $dumps = [];
    foreach ((array) ($m['dumps'] ?? []) as $d) {
        if (!is_array($d) || !in_array($d['type'] ?? '', RS_DB_TYPES, true)) {
            continue;
        }
        $c = rsStr($d['container'] ?? '');
        foreach ($files as $f) {
            if ($f['what'] === 'dump' && $f['container'] === $c) {
                $dumps[] = ['file' => $f['path'], 'container' => $c, 'type' => $d['type'], 'bytes' => $f['bytes'], 'run' => $f['run'],
                            'time' => $f['time'], 'kept' => $f['run'] !== '' && $f['run'] !== rsStr($m['run'] ?? ''),
                            'state' => rsStr($d['state'] ?? ''), 'login' => rsStr($d['login'] ?? ''), 'user_var' => rsStr($d['user_var'] ?? ''),
                            'password_var' => rsStr($d['password_var'] ?? ''), 'client' => rsStr($d['client'] ?? ''),
                            'db' => $d['type'] === 'mariadb' && preg_match('/^db\/mariadb_' . preg_quote($c, '/') . '_(.+)\.sql\.gz$/', $f['path'], $x) ? $x[1] : null];
            }
        }
    }
    $compose = is_array($m['compose'] ?? null) ? [
        'project'      => rsStr($m['compose']['project'] ?? ''),
        'dir'          => rsStr($m['compose']['manager_dir'] ?? ''),
        'working_dir'  => rsStr($m['compose']['working_dir'] ?? ''),
        'config_files' => array_values(array_filter((array) ($m['compose']['config_files'] ?? []), 'is_string')),
        'files'        => array_values(array_map(fn ($f) => substr($f['path'], 8), array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose/')))),
        'indirect'     => (bool) array_filter($files, fn ($f) => str_starts_with($f['path'], 'compose-files/')),
    ] : null;
    $run = rsStr($m['run'] ?? '');
    return [
        'id'         => $folder,
        'name'       => rsStr($m['name'] ?? '') ?: $folder,
        'type'       => rsStr($m['type'] ?? ''),
        'run'        => $run,
        'time'       => rsRunTime($run),
        'result'     => rsStr($m['result'] ?? ''),
        'path'       => $path,
        'containers' => $containers,
        'files'      => $files,
        'dumps'      => $dumps,
        'templates'  => array_values(array_map(fn ($f) => $f['path'], array_filter($files, fn ($f) => $f['what'] === 'template' && !str_contains($f['path'], '/')))),
        'compose'    => $compose,
        'nextcloud'  => array_values(array_map(fn ($n) => ['container' => rsStr($n['container'] ?? ''), 'occ' => rsStr($n['occ'] ?? '') ?: '/var/www/html/occ',
                            'user' => rsStr($n['user'] ?? '') ?: 'www-data', 'same_as' => rsStr($n['same_as'] ?? '')],
                            array_filter((array) ($m['nextcloud'] ?? []), 'is_array'))),
        'sqlite'     => array_values(array_map(fn ($q) => ['container' => rsStr($q['container'] ?? ''), 'file' => rsStr($q['file'] ?? ''),
                            'source' => rsStr($q['source'] ?? ''), 'check' => rsStr($q['check'] ?? ''),
                            'bytes' => (int) ($byPath[rsStr($q['file'] ?? '')]['bytes'] ?? 0), 'time' => $byPath[rsStr($q['file'] ?? '')]['time'] ?? null,
                            'kept' => ($byPath[rsStr($q['file'] ?? '')]['run'] ?? $run) !== $run],
                            array_filter((array) ($m['sqlite'] ?? []), fn ($q) => is_array($q) && !empty($q['present'])))),
        'own_backups' => array_values(array_map(fn ($o) => ['kind' => rsStr($o['kind'] ?? ''), 'container' => rsStr($o['container'] ?? ''),
                            'path' => rsStr($o['path'] ?? ''), 'files' => (int) ($o['files'] ?? 0), 'newest' => (int) ($o['newest'] ?? 0), 'asleep' => !empty($o['asleep'])],
                            array_filter((array) ($m['own_backups'] ?? []), fn ($o) => is_array($o) && in_array($o['kind'] ?? '', ['emby', 'jellyfin', 'plex', 'immich'], true)))),
    ];
}

function rsVmPackage(array $m, string $folder, string $path): array
{
    $files = rsFiles($m);
    $run = rsStr($m['run'] ?? '');
    $uuid = preg_match('/^' . RS_UUID . '$/i', rsStr($m['uuid'] ?? '')) ? strtolower(rsStr($m['uuid'])) : '';
    $xml = rsStr($m['xml'] ?? '');
    $xml = $xml !== '' && !str_contains($xml, '/') && is_file("$path/$xml") ? $xml : '';
    return [
        'id'        => $folder,
        'name'      => rsStr($m['name'] ?? '') ?: $folder,
        'run'       => $run,
        'time'      => rsRunTime($run),
        'result'    => rsStr($m['result'] ?? ''),
        'path'      => $path,
        'xml'       => $xml,
        'uuid'      => $uuid,
        'autostart' => !empty($m['autostart']),
        'prepare'   => rsStr($m['prepare'] ?? ''),
        'nvram'     => array_values(array_map(fn ($f) => basename($f['path']), array_filter($files, fn ($f) => $f['what'] === 'nvram' && substr_count($f['path'], '/') === 1))),
        'tpm'       => $uuid !== '' && is_dir("$path/tpm/$uuid"),
        'snapshotdb' => (bool) array_filter($files, fn ($f) => $f['path'] === 'snapshotdb/snapshots.db'),
        'hostdev'   => $xml !== '' ? substr_count((string) @file_get_contents("$path/$xml", false, null, 0, 1 << 20), '<hostdev') : 0,
        'disks'     => array_values(array_map(fn ($d) => ['target' => rsStr($d['target'] ?? ''), 'source' => rsStr($d['source'] ?? ''),
                           'snapshot' => rsStr($d['snapshot'] ?? ''), 'bytes' => isset($d['bytes']) ? (int) $d['bytes'] : null, 'share' => rsStr($d['share'] ?? '')],
                           array_filter((array) ($m['disks'] ?? []), 'is_array'))),
        'files'     => $files,
    ];
}

// ===================================================================== what is on the server now

/** The compose manager's projects folder */
function rsComposeRoot(): string
{
    $v = trim((string) (readCfg(RS_COMPOSE_CFG)['PROJECTS_FOLDER'] ?? ''));
    return rtrim($v !== '' ? $v : RS_COMPOSE_DEF, '/');
}

/** Is a file in place, and the same as the package's? same | differs | missing */
function rsCompare(string $package, string $live): string
{
    clearstatcache(true, $live);
    if (!is_file($live)) {
        return file_exists($live) ? 'differs' : 'missing';
    }
    return @filesize($package) === @filesize($live) && @md5_file($package) === @md5_file($live) ? 'same' : 'differs';
}

/** The VMs libvirt knows right now: name => state ('running', 'shut off' …); null while the VM service is off */
function rsVmStates(): ?array
{
    if (!is_dir(RS_LIBVIRT . '/qemu') || !bin('virsh')) {
        return null;
    }
    [$exit, $out] = run(['virsh', 'list', '--all'], 20);
    if ($exit !== 0) {
        return null;
    }
    $states = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\s*(\d+|-)\s+(.+?)\s{2,}(\S.*?)\s*$/', $line, $m) && $m[2] !== 'Name') {
            $states[$m[2]] = $m[3];
        }
    }
    return $states;
}

/**
 * The folders of an app as restore units: the first folder below a share that one of its
 * containers binds (appdata/<app>, a photo folder in another share …). A whole share bound as
 * it is (a media library) is named, but is no unit: single files come back through Ms. Snapshotini
 * or Kopia.
 */
function rsAppFolders(array $app, array &$ctx): array
{
    $units = [];
    $shares = [];
    $placeShare = (string) backupSetting($ctx['settings'], 'general', 'dumps_share', '');
    foreach ($app['containers'] as $c) {
        foreach ($c['binds'] as $b) {
            $sp = rsSharePath($b['source'], $ctx);
            if (!$sp || $sp[0] === $placeShare || $sp[0] === BACKUP_OFFICE_SHARE) {
                continue;                           // not in a share, or the backup place's / the office's own share
            }
            [$share, $rel] = $sp;
            if ($rel === '') {
                $shares[$share][$c['name']] = true;
                continue;
            }
            $first = explode('/', $rel)[0];
            $key = "/mnt/user/$share/$first";
            $units[$key] ??= ['path' => $key, 'share' => $share, 'rel' => $first, 'containers' => []];
            $units[$key]['containers'][$c['name']] = true;
        }
    }
    $out = [];
    foreach ($units as $u) {
        $out[] = rsFolder($u['path'], array_keys($u['containers']), $ctx);
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
    $whole = [];
    foreach ($shares as $share => $cts) {
        $whole[] = ['share' => $share, 'containers' => array_keys($cts), 'protection' => backupProtection("/mnt/user/$share")];
    }
    return [$out, $whole];
}

/** A folder as the page shows it: where it lies, its protection, its local snapshots */
function rsFolder(string $path, array $containers, array &$ctx): array
{
    $places = rsLocate($path, $ctx);
    $snaps = array_merge(...array_map(fn ($p) => $p['snaps'], $places) ?: [[]]);
    return [
        'path'       => $path,
        'containers' => $containers,
        'protection' => backupProtection($path),
        'exists'     => (bool) array_filter($places, fn ($p) => $p['exists']),
        'asleep'     => (bool) array_filter($places, fn ($p) => $p['asleep']),
        'places'     => array_map(fn ($p) => array_diff_key($p, ['snaps' => 0]) + ['count' => count($p['snaps']), 'latest' => $p['snaps'][0] ?? null], $places),
        'snaps'      => count($snaps),
        'newest'     => $snaps ? max(array_column($snaps, 'time')) : null,
        'oldest'     => $snaps ? min(array_column($snaps, 'time')) : null,
    ];
}

// ===================================================================== state

function rsScan(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $engine = rsEngine($settings);
    $place = rsPlace($settings, $ctx);
    $old = $GLOBALS['rs']['state'] ?? [];
    $state = [
        'time'     => time(),
        'host'     => hostname(),
        'engine'   => $engine,
        'place'    => ['share' => $place['share'], 'base' => $place['base'], 'asleep' => $place['asleep'], 'found' => $place['found'],
                       'snaps' => array_sum(array_map(fn ($p) => count($p['snaps']), $place['places']))],
        'kopia'    => rsKopia($engine, $settings),
        'compose_root' => rsComposeRoot(),
        'templates_dir' => RS_TEMPLATES,
        'libvirt_img' => readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? '/mnt/user/system/libvirt/libvirt.img',
        'vm_service' => is_dir(RS_LIBVIRT . '/qemu'),
        'shares'   => [],
        'apps'     => [],
        'vms'      => [],
        'server'   => null,
        'flash'    => null,
        'run'      => null,
        'run_time' => null,
    ];
    if ($place['asleep']) {
        // the backup place's disk sleeps: what was read before
        foreach (['apps', 'vms', 'server', 'flash', 'run', 'run_time', 'shares'] as $k) {
            $state[$k] = $old[$k] ?? $state[$k];
        }
        return rsWrite($state);
    }
    $pk = $place['found'] ? rsPackages($place['base']) : null;
    $kopiaLast = rsKopiaLast();
    $containers = houseContainers();
    $vmStates = rsVmStates();
    $kopiaShare = fn (string $share) => $engine['kopia'] && backupSetting($settings, "share|$share", 'mode', 'off') === 'kopia';
    $own = fn (string $kind, string $name) => $engine['kopia'] && backupSetting($settings, "$kind|$name", 'kopia', 'no') === 'yes';
    foreach ($pk['apps'] ?? [] as $a) {
        [$folders, $whole] = rsAppFolders($a, $ctx);
        foreach ($a['containers'] as &$c) {
            $c['now'] = isset($containers[$c['name']]) ? ($containers[$c['name']]['running'] ? 'running' : 'stopped') : 'missing';
            unset($c['binds']);
        }
        unset($c);
        $sources = [];
        if ($own('app', $a['name'])) {
            $sources[] = ['source' => '.apps/' . $a['id'], 'own' => true, 'last' => $kopiaLast['app:' . $a['name']] ?? null];
        }
        // its folders go with their shares unless the app has a source of its own; whole shares it binds always do
        $names = array_merge($sources ? [] : array_map(fn ($f) => explode('/', $f['path'])[3] ?? '', $folders), array_column($whole, 'share'));
        foreach (array_unique(array_filter($names)) as $share) {
            if ($kopiaShare($share)) {
                $sources[] = ['source' => $share, 'own' => false, 'last' => $kopiaLast[$share] ?? null];
            }
        }
        $a['templates'] = array_map(fn ($t) => ['file' => $t, 'now' => rsCompare("{$a['path']}/$t", RS_TEMPLATES . "/$t")], $a['templates']);
        if ($a['compose']) {
            $root = $state['compose_root'];
            $dir = $a['compose']['dir'];
            $a['compose']['now'] = array_map(fn ($f) => ['file' => $f, 'now' => $dir !== '' ? rsCompare("{$a['path']}/compose/$f", "$root/$dir/$f") : 'missing'],
                $a['compose']['files']);
        }
        $a['immich'] = (bool) array_filter($a['containers'], fn ($c) => stripos($c['image'], 'immich') !== false);
        $a['db_only'] = $a['containers'] && !array_filter($a['containers'], fn ($c) => !in_array($c['db'], ['mariadb', 'postgres', 'mongodb', 'cache'], true));
        $a['present'] = (bool) array_filter($a['containers'], fn ($c) => $c['now'] !== 'missing');
        $a['folders'] = $folders;
        $a['shares'] = $whole;
        $a['kopia'] = $sources;
        $a['files'] = array_values(array_filter($a['files'], fn ($f) => $f['what'] !== 'error'));
        $state['apps'][] = $a;
    }
    foreach ($pk['vms'] ?? [] as $v) {
        $units = [];
        foreach ($v['disks'] as $d) {
            $sp = rsSharePath($d['source'], $ctx);
            if ($sp && $sp[1] !== '') {
                $first = explode('/', $sp[1])[0];
                $units["/mnt/user/{$sp[0]}/$first"] = true;
            }
        }
        $v['folders'] = array_map(fn ($p) => rsFolder($p, [], $ctx), array_keys($units));
        $v['state'] = $vmStates === null ? null : ($vmStates[$v['name']] ?? 'missing');
        $sources = [];
        if ($own('vm', $v['name'])) {
            $sources[] = ['source' => '.vms/' . $v['id'], 'own' => true, 'last' => $kopiaLast['vm:' . $v['name']] ?? null];
        }
        foreach (array_unique(array_map(fn ($f) => explode('/', $f['path'])[3] ?? '', $v['folders'])) as $share) {
            if ($share !== '' && $kopiaShare($share) && !$sources) {
                $sources[] = ['source' => $share, 'own' => false, 'last' => $kopiaLast[$share] ?? null];
            }
        }
        $v['kopia'] = $sources;
        unset($v['files']);
        $state['vms'][] = $v;
    }
    $state['server'] = $pk['server'] ?? null;
    $state['flash'] = $pk['flash'] ?? null;
    $state['run'] = $pk['run'] ?? null;
    $state['run_time'] = $pk['time'] ?? null;
    // the shares as the engine backs them up, for the Kopia guide
    foreach ($settings as $key => $_) {
        if (str_starts_with($key, 'share|')) {
            $name = substr($key, 6);
            $state['shares'][] = ['name' => $name, 'mode' => backupSetting($settings, $key, 'mode', 'off'), 'last' => $kopiaLast[$name] ?? null];
        }
    }
    if ($engine['flash'] === 'snapshot') {
        $state['shares'][] = ['name' => '_flash', 'mode' => 'kopia', 'last' => $kopiaLast['flash'] ?? null, 'flash' => true];
    }
    usort($state['shares'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return rsWrite($state);
}

function rsWrite(array $state): array
{
    $GLOBALS['rs']['state'] = $state;
    writeAtomic(deskFile('restore'), jsonEncode($state));
    return $state;
}

// ===================================================================== older packages

/**
 * The package of an app or VM as earlier nights left it: from the local snapshots of the backup
 * place's share (newest first) — each with its run and, for an app, its dumps. Read on demand
 * (the page asks when a row unfolds); never on a sleeping disk.
 */
function rsVersions(string $kind, string $id): array
{
    if (!in_array($kind, ['app', 'vm'], true)) {
        throw new Problem('unknown_target', ['target' => $kind]);
    }
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $ctx = rsContext($settings);
    $place = rsPlace($settings, $ctx);
    if ($place['asleep']) {
        throw new Problem('restore_place_asleep');
    }
    $list = $place['found'] ? rsPackages($place['base'])[$kind . 's'] : [];
    $current = array_values(array_filter($list, fn ($p) => $p['id'] === $id))[0] ?? null;
    if (!$current) {
        throw new Problem('unknown_target', ['target' => $id]);
    }
    return ['ok' => true, 'kind' => $kind, 'id' => $id, 'versions' => rsVersionList($place, $kind, $id, $current['run'])];
}

/** @return list<array{snap:string, time:int, run:string, run_time:?int, path:string, dumps:list<array>, files:int}> */
function rsVersionList(array $place, string $kind, string $id, string $currentRun): array
{
    $out = [];
    $seen = [$currentRun => true];
    foreach ($place['places'] as $p) {
        foreach ($p['snaps'] as $s) {
            $dir = "{$s['path']}/{$kind}s/$id";
            $m = is_file("$dir/manifest.json") ? readJson("$dir/manifest.json") : null;
            if (!$m) {
                continue;
            }
            $pkg = $kind === 'app' ? rsAppPackage($m, $id, $dir) : rsVmPackage($m, $id, $dir);
            // the same package as a newer state (nothing new was written that night) is listed once
            $key = $pkg['run'] . ':' . implode(',', array_map(fn ($f) => $f['path'] . '@' . $f['run'], $pkg['files']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['snap' => $s['id'], 'time' => $s['time'], 'run' => $pkg['run'], 'run_time' => $pkg['time'], 'path' => $dir,
                      'dumps' => $kind === 'app' ? array_map(fn ($d) => array_intersect_key($d, array_flip(['file', 'container', 'type', 'bytes', 'time', 'kept'])), $pkg['dumps']) : [],
                      'files' => count($pkg['files'])];
        }
    }
    usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

// ===================================================================== hiring

/** Suits a server whose backups he can bring back: packages in the backup place, or local snapshots */
function rsFit(): array
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $base = backupDumpsPath((string) backupSetting($settings, 'general', 'dumps_share', ''));
    $state = $GLOBALS['rs']['state'] ?? null;
    $n = count($state['apps'] ?? []) + count($state['vms'] ?? []);
    if ($base !== null && $n) {
        return fit(true, 'packages', ['n' => $n]);
    }
    $fs = houseSnapshotFilesystems();
    if ($fs['zfs'] || $fs['btrfs']) {
        return fit(true, 'snapshots', ['places' => implode(', ', array_merge($fs['zfs'], $fs['btrfs']))]);
    }
    return fit(false, 'nothing');
}
