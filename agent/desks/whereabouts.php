<?php
declare(strict_types=1);

/*
 * Ms. Whereabouts — knows where everything is and what is going on.
 *
 * One tour (scan) reads Unraid's configuration and the running system:
 * shares and where they live, the first folder level of pool shares, Docker
 * containers (templates and compose), compose projects, VMs, users and their
 * share access, SMB/NFS, user scripts and cron jobs, places that look like
 * backups, plugins. It only reads; nothing is changed.
 *
 * She never wakes sleeping disks on her own. Folder sizes (du) are measured
 * in the background and only when asked; ZFS datasets are known instantly.
 */

const WA_SHARES_DIR   = '/boot/config/shares';
const WA_SHARES_INI   = '/var/local/emhttp/shares.ini';
const WA_VAR_INI      = '/var/local/emhttp/var.ini';
const WA_TEMPLATES    = '/boot/config/plugins/dockerMan/templates-user';
const WA_AUTOSTART    = '/var/lib/docker/unraid-autostart';
const WA_COMPOSE      = '/boot/config/plugins/compose.manager/projects';
const WA_SCRIPTS      = '/boot/config/plugins/user.scripts/scripts';
const WA_SCRIPTS_JSON = '/boot/config/plugins/user.scripts/schedule.json';
const WA_SCRIPTS_TMP  = '/tmp/user.scripts';
const WA_PLUGINS      = '/boot/config/plugins';
const WA_LIBVIRT      = '/etc/libvirt';          // libvirt.img is mounted here while the VM service runs
const WA_FOLDER_LIMIT = 500;     // first-level entries per share
const WA_SCRIPT_BYTES = 8192;    // how much of each user script to show
const WA_DU_PARALLEL  = 2;

$GLOBALS['whereabouts'] = null;
$GLOBALS['waJobs'] = ['queue' => [], 'running' => []];

desk('whereabouts', [
    'start' => function (): void {
        $GLOBALS['whereabouts'] = readJson(deskFile('whereabouts'));
        whereaboutsScan();
    },
    'tick' => fn () => whereaboutsJobsTick(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => whereaboutsScan()],
        'scan'    => function (array $r): array {
            $woken = !empty($r['wake']) ? waWakeDisks() : null;
            $state = whereaboutsScan($woken !== null);
            logLine(sprintf('Whereabouts tour: %d shares, %d containers, %d scripts, %d ms%s',
                count($state['shares']), count($state['containers']), count($state['scripts']), $state['duration_ms'],
                $woken !== null ? sprintf(' (woke %d disks first)', count($woken)) : ''));
            $state['woken'] = $woken;
            return ['ok' => true, 'state' => $state];
        },
        'measure' => fn (array $r) => whereaboutsMeasure(idList($r, 'paths')),
        'sizes'   => fn (array $r) => ['ok' => true, 'sizes' => whereaboutsSizes()],
    ],
]);

// ===================================================================== tour

/** @param bool $awake the disks were just woken up: read everything, Unraid's spin state lags behind */
function whereaboutsScan(bool $awake = false): array
{
    $t0 = microtime(true);
    $roots = waStorageRoots();
    $asleep = $awake ? [] : sleepingDisks();
    $datasets = waZfsDatasets();

    $containers = waContainers();
    $compose = waComposeProjects($containers);
    $templates = waTemplates($containers);
    $vms = waVms($roots, $asleep);
    $scripts = waUserScripts($roots, $asleep);
    $backupScript = backupScriptState();

    // who uses which path — everything that points into a share
    $consumers = [];
    foreach ($containers as $c) {
        foreach ($c['mounts'] as $m) {
            $consumers[] = ['kind' => 'container', 'name' => $c['name'], 'path' => $m['source'], 'detail' => $m['dest']];
        }
    }
    foreach ($compose as $p) {
        foreach ($p['paths'] as $path) {
            $consumers[] = ['kind' => 'compose', 'name' => $p['name'], 'path' => $path, 'detail' => null];
        }
    }
    foreach ($templates as $t) {
        if (!$t['container']) {
            foreach ($t['paths'] as $path) {
                $consumers[] = ['kind' => 'template', 'name' => $t['name'], 'path' => $path, 'detail' => null];
            }
        }
    }
    foreach ($vms as $vm) {
        foreach ($vm['disks'] as $disk) {
            $consumers[] = ['kind' => 'vm', 'name' => $vm['name'], 'path' => $disk['source'], 'detail' => null];
        }
    }
    foreach ($scripts as $s) {
        foreach ($s['refs'] as $ref) {
            $consumers[] = ['kind' => 'script', 'name' => $s['name'], 'path' => $ref['path'], 'detail' => null];
        }
    }
    $backups = waBackups($containers, $scripts, $backupScript);
    foreach ($backups as $b) {
        foreach ($b['paths'] as $path) {
            $consumers[] = ['kind' => 'backup', 'name' => $b['title'], 'path' => $path, 'detail' => $b['kind']];
        }
    }

    $shares = waShares($roots, $asleep, $datasets, $consumers);
    $appdata = waAppdataShare();
    $folders = waFolders($shares, $roots, $asleep, $datasets, $consumers, $appdata);

    $state = [
        'time'        => time(),
        'duration_ms' => 0,
        'host'        => hostname(),
        'agent'       => AGENT_VERSION,
        'system'      => waSystem($containers, $vms, $scripts, $backupScript),
        'storage'     => array_map(fn ($r, $name) => ['name' => $name, 'fs' => $r['fs'], 'kind' => $r['kind'], 'asleep' => $asleep[$name] ?? false],
                                   $roots, array_keys($roots)),
        'shares'      => $shares,
        'appdata'     => $appdata,
        'folders'     => $folders,
        'containers'  => $containers,
        'compose'     => $compose,
        'templates'   => $templates,
        'vms'         => $vms,
        'users'       => waUsers(),
        'smb'         => waSmb(),
        'nfs'         => waNfs(),
        'scripts'     => $scripts,
        'cron'        => waCron(),
        'backups'     => $backups,
        'plugins'     => waPlugins(),
        'health'      => waHealth(),
        'license'     => waLicense(),
        'notices'     => waNotices(),
        'locations'   => waLocations($vms),
    ];
    $state['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
    $GLOBALS['whereabouts'] = $state;
    writeAtomic(deskFile('whereabouts'), jsonEncode($state));
    return $state;
}

/**
 * Wakes every sleeping disk — only when asked ("wake the disks" with the
 * tour). All at once: one block is read straight from each, in parallel, so
 * it takes as long as the slowest disk instead of the sum. Read only; Unraid
 * spins them down again after its own delay.
 *
 * @return list<string> the disks that were asleep
 */
function waWakeDisks(): array
{
    $commands = [];
    foreach (readCfg('/var/local/emhttp/disks.ini', true) as $section => $d) {
        $dev = $d['device'] ?? '';
        if (($d['spundown'] ?? '0') === '1' && preg_match('/^[a-z0-9]+$/', $dev) && file_exists("/dev/$dev")) {
            $commands[(string) ($d['name'] ?? $section)] = ['dd', "if=/dev/$dev", 'of=/dev/null', 'bs=4096', 'count=1', 'iflag=direct'];
        }
    }
    if ($commands) {
        runAll($commands, 90);
    }
    return array_keys($commands);
}

// --------------------------------------------------------------------- storage

/** Pools and array disks: /mnt/<name> mounted with a real filesystem */
function waStorageRoots(): array
{
    $roots = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && in_array($m['fs'], ['zfs', 'btrfs', 'xfs', 'ext4', 'reiserfs'], true)
            && !in_array($x[1], ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true)) {
            $roots[$x[1]] ??= ['fs' => $m['fs'], 'kind' => preg_match('/^disk\d+$/', $x[1]) ? 'disk' : 'pool'];
        }
    }
    ksort($roots, SORT_NATURAL);
    return $roots;
}

/** dataset name => [used, refer, mountpoint] */
function waZfsDatasets(): array
{
    $zfs = bin('zfs');
    if (!$zfs) {
        return [];
    }
    [$exit, $out] = run([$zfs, 'list', '-Hp', '-t', 'filesystem', '-o', 'name,used,refer,mountpoint'], 60);
    $datasets = [];
    if ($exit === 0) {
        foreach (rows($out) as $f) {
            if (count($f) >= 4 && $f[3] !== 'legacy') {
                $datasets[$f[0]] = ['used' => num($f[1]), 'refer' => num($f[2]), 'mount' => $f[3]];
            }
        }
    }
    return $datasets;
}

/** Splits /mnt/<root>/<share>/<rest> — null for paths outside of shares */
function waShareOfPath(string $path, array $roots): ?array
{
    if (preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', rtrim($path, '/'), $m)
        && ($m[1] === 'user' || $m[1] === 'user0' || isset($roots[$m[1]]))) {
        return ['root' => $m[1], 'share' => $m[2], 'rest' => $m[3] ?? ''];
    }
    return null;
}

function waShares(array $roots, array $asleep, array $datasets, array $consumers): array
{
    $ini = readCfg(WA_SHARES_INI, true);
    $names = array_keys($ini);
    foreach (glob(WA_SHARES_DIR . '/*.cfg') ?: [] as $file) {
        $names[] = basename($file, '.cfg');
    }
    $names = array_values(array_unique($names));
    natcasesort($names);

    $shares = [];
    foreach ($names as $name) {
        $cfg = readCfg(WA_SHARES_DIR . "/$name.cfg");
        $i = $ini[$name] ?? [];
        $useCache = $cfg['shareUseCache'] ?? $i['useCache'] ?? 'no';
        $pool = $cfg['shareCachePool'] ?? $i['cachePool'] ?? '';
        $pool2 = $cfg['shareCachePool2'] ?? $i['cachePool2'] ?? '';
        $exclusive = ($i['exclusive'] ?? '') === 'yes';
        $userPath = "/mnt/user/$name";
        $real = $exclusive ? (@realpath($userPath) ?: $userPath) : null;

        // where its data may lie
        $primary = in_array($useCache, ['only', 'yes', 'prefer'], true) && $pool !== '' ? $pool : 'array';
        $secondary = match ($useCache) {
            'yes', 'prefer' => $pool2 !== '' ? $pool2 : 'array',
            default         => null,
        };
        $onPools = [];
        $onDisks = [];
        $unknown = [];
        foreach ($roots as $root => $r) {
            if ($asleep[$root] ?? false) {
                $unknown[] = $root;
                continue;
            }
            if (is_dir("/mnt/$root/$name")) {
                if ($r['kind'] === 'disk') {
                    $onDisks[] = $root;
                } else {
                    $onPools[] = $root;
                }
            }
        }

        $dataset = null;
        foreach (array_merge([$primary], $onPools) as $p) {
            if (isset($datasets["$p/$name"])) {
                $dataset = "$p/$name";
                break;
            }
        }

        $usedBy = [];
        foreach ($consumers as $c) {
            $s = waShareOfPath($c['path'], $roots);
            if ($s && $s['share'] === $name) {
                $usedBy[] = $c + ['rest' => $s['rest']];
            }
        }

        $shares[] = [
            'name'     => $name,
            'comment'  => $cfg['shareComment'] ?? $i['comment'] ?? '',
            'storage'  => [
                'use_cache' => $useCache,
                'primary'   => $primary,
                'secondary' => $secondary,
                'exclusive' => $exclusive,
                'real'      => $real,
                'pools'     => $onPools,
                'disks'     => $onDisks,
                'unknown'   => $useCache === 'only' ? [] : array_values(array_filter($unknown, fn ($r) => ($roots[$r]['kind'] ?? '') === 'disk')),
                'include'   => array_values(array_filter(explode(',', $cfg['shareInclude'] ?? ''))),
                'exclude'   => array_values(array_filter(explode(',', $cfg['shareExclude'] ?? ''))),
                'allocator' => $cfg['shareAllocator'] ?? null,
                'dataset'   => $dataset,
                'missing'   => $primary !== 'array' && !isset($roots[$primary]),   // points to a pool that doesn't exist
            ],
            'size'     => $dataset ? ['bytes' => $datasets[$dataset]['used'], 'source' => 'zfs'] : null,
            'measure'  => $real ?? $userPath,
            'smb'      => [
                'export'   => $cfg['shareExport'] ?? '-',
                'security' => $cfg['shareSecurity'] ?? 'public',
                'read'     => array_values(array_filter(explode(',', $cfg['shareReadList'] ?? ''))),
                'write'    => array_values(array_filter(explode(',', $cfg['shareWriteList'] ?? ''))),
                'timemachine_limit' => ($cfg['shareVolsizelimit'] ?? '') ?: null,
            ],
            'nfs'      => [
                'export'   => $cfg['shareExportNFS'] ?? '-',
                'security' => $cfg['shareSecurityNFS'] ?? 'public',
                'hosts'    => $cfg['shareHostListNFS'] ?? '',
            ],
            'has_cfg'  => is_file(WA_SHARES_DIR . "/$name.cfg"),
            'used_by'  => $usedBy,
        ];
    }
    return $shares;
}

/** The share Docker keeps its app data in (Settings → Docker) */
function waAppdataShare(): ?string
{
    $path = readCfg('/boot/config/docker.cfg')['DOCKER_APP_CONFIG_PATH'] ?? '/mnt/user/appdata/';
    return preg_match('#^/mnt/[^/]+/([^/]+)#', $path, $m) ? $m[1] : null;
}

/**
 * First folder level of shares that live on a single pool only (appdata,
 * system, domains …). Array shares are skipped: listing them wakes disks.
 */
function waFolders(array $shares, array $roots, array $asleep, array $datasets, array $consumers, ?string $appdata): array
{
    $result = [];
    foreach ($shares as $share) {
        $st = $share['storage'];
        if ($st['use_cache'] !== 'only' || $st['primary'] === 'array' || ($asleep[$st['primary']] ?? false)) {
            continue;
        }
        $base = $st['real'] ?? "/mnt/{$st['primary']}/{$share['name']}";
        $entries = @scandir($base);
        if ($entries === false) {
            continue;
        }
        $list = [];
        $files = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$base/$entry";
            if (!is_dir($full) || is_link($full)) {
                $files++;
                continue;
            }
            if (count($list) >= WA_FOLDER_LIMIT) {
                break;
            }
            $usedBy = [];
            foreach ($consumers as $c) {
                $s = waShareOfPath($c['path'], $roots);
                if ($s && $s['share'] === $share['name'] && $s['rest'] !== ''
                    && explode('/', $s['rest'])[0] === $entry) {
                    $usedBy[] = $c;
                }
            }
            $ds = "{$st['primary']}/{$share['name']}/$entry";
            $list[] = [
                'name'     => $entry,
                'path'     => "/mnt/user/{$share['name']}/$entry",
                'real'     => $full,
                'dataset'  => isset($datasets[$ds]) ? $ds : null,
                'size'     => isset($datasets[$ds]) ? ['bytes' => $datasets[$ds]['used'], 'source' => 'zfs'] : null,
                'modified' => @filemtime($full) ?: null,
                'used_by'  => $usedBy,
            ];
        }
        $result[] = [
            'share'   => $share['name'],
            'base'    => $base,
            'appdata' => $share['name'] === $appdata,
            'files'   => $files,
            'folders' => $list,
            'cut'     => count($list) >= WA_FOLDER_LIMIT,
        ];
    }
    return $result;
}

// --------------------------------------------------------------------- docker

function waContainers(): array
{
    $docker = bin('docker');
    if (!$docker || !file_exists('/var/run/docker.sock')) {
        return [];
    }
    [$exit, $ids] = run([$docker, 'ps', '-aq', '--no-trunc'], 30);
    $ids = array_values(array_filter(array_map('trim', explode("\n", $ids))));
    if ($exit !== 0 || !$ids) {
        return [];
    }
    [$exit, $out] = run(array_merge([$docker, 'inspect'], $ids), 60);
    $data = json_decode($out, true);
    if ($exit !== 0 || !is_array($data)) {
        return [];
    }
    $autostart = [];
    foreach (@file(WA_AUTOSTART, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $name = strtok(trim($line), ' ');
        if ($name) {
            $autostart[$name] = true;
        }
    }

    $containers = [];
    foreach ($data as $c) {
        $name = ltrim((string) ($c['Name'] ?? ''), '/');
        $labels = $c['Config']['Labels'] ?? [];
        $project = $labels['com.docker.compose.project'] ?? null;
        $template = WA_TEMPLATES . "/my-$name.xml";
        $networks = [];
        foreach ((array) ($c['NetworkSettings']['Networks'] ?? []) as $net => $info) {
            $networks[] = ['name' => $net, 'ip' => ($info['IPAddress'] ?? '') ?: null];
        }
        $ports = [];
        foreach ((array) ($c['NetworkSettings']['Ports'] ?? []) as $port => $bindings) {
            foreach ((array) $bindings as $b) {
                $ports[] = ['container' => $port, 'host' => $b['HostPort'] ?? null, 'ip' => $b['HostIp'] ?? null];
            }
        }
        $mounts = [];
        foreach ((array) ($c['Mounts'] ?? []) as $m) {
            $mounts[] = [
                'type'   => $m['Type'] ?? 'bind',
                'source' => $m['Source'] ?? ($m['Name'] ?? ''),
                'dest'   => $m['Destination'] ?? '',
                'rw'     => (bool) ($m['RW'] ?? true),
            ];
        }
        $containers[] = [
            'name'       => $name,
            'image'      => $c['Config']['Image'] ?? '',
            'state'      => $c['State']['Status'] ?? 'unknown',
            'started'    => isset($c['State']['StartedAt']) ? (strtotime($c['State']['StartedAt']) ?: null) : null,
            'managed'    => $project ? 'compose' : (is_file($template) ? 'template' : 'other'),
            'template'   => is_file($template) ? $template : null,
            'compose'    => $project ? [
                'project' => $project,
                'files'   => array_values(array_filter(explode(',', $labels['com.docker.compose.project.config_files'] ?? ''))),
                'service' => $labels['com.docker.compose.service'] ?? null,
            ] : null,
            'autostart'  => isset($autostart[$name]),
            'restart'    => $c['HostConfig']['RestartPolicy']['Name'] ?? '',
            'privileged' => (bool) ($c['HostConfig']['Privileged'] ?? false),
            'network'    => $c['HostConfig']['NetworkMode'] ?? '',
            'networks'   => $networks,
            'ports'      => $ports,
            'mounts'     => $mounts,
            'webui'      => $labels['net.unraid.docker.webui'] ?? null,
            'icon'       => $labels['net.unraid.docker.icon'] ?? null,
        ];
    }
    usort($containers, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $containers;
}

function waComposeProjects(array $containers): array
{
    $projects = [];
    foreach (glob(WA_COMPOSE . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = trim((string) @file_get_contents("$dir/name")) ?: basename($dir);
        $project = trim((string) @file_get_contents("$dir/project_name")) ?: $name;
        $where = $dir;
        $indirect = trim((string) @file_get_contents("$dir/indirect"));
        if ($indirect !== '' && is_dir($indirect)) {
            $where = $indirect;
        }
        $files = [];
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml',
                  'compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yml', 'docker-compose.override.yaml'] as $f) {
            if (is_file("$where/$f")) {
                $files[] = "$where/$f";
            }
        }
        // host paths mentioned in the compose files (also when the stack is down)
        $paths = [];
        foreach ($files as $f) {
            preg_match_all('#(?<![\w$}])(/mnt/[^\s:"\'\#,\]]+)#', (string) @file_get_contents($f), $m);
            foreach ($m[1] as $p) {
                $paths[rtrim($p, '/')] = true;
            }
        }
        $members = [];
        foreach ($containers as $c) {
            if (($c['compose']['project'] ?? null) === $project) {
                $members[] = ['name' => $c['name'], 'state' => $c['state']];
            }
        }
        $projects[] = [
            'name'       => $name,
            'project'    => $project,
            'dir'        => $dir,
            'files'      => $files,
            'env'        => is_file("$where/.env"),
            'autostart'  => trim((string) @file_get_contents("$dir/autostart")) === 'true',
            'containers' => $members,
            'paths'      => array_keys($paths),
        ];
    }
    return $projects;
}

function waTemplates(array $containers): array
{
    $names = array_flip(array_column($containers, 'name'));
    $templates = [];
    foreach (glob(WA_TEMPLATES . '/*.xml') ?: [] as $file) {
        $xml = (string) @file_get_contents($file);
        $name = preg_match('#<Name>([^<]*)</Name>#', $xml, $m) ? html_entity_decode(trim($m[1])) : basename($file, '.xml');
        $repo = preg_match('#<Repository>([^<]*)</Repository>#', $xml, $m) ? html_entity_decode(trim($m[1])) : null;
        preg_match_all('#<Config[^>]*Type="Path"[^>]*>([^<]*)</Config>#', $xml, $m);
        $templates[] = [
            'file'      => $file,
            'name'      => $name,
            'image'     => $repo,
            'container' => isset($names[$name]),
            'paths'     => array_values(array_filter(array_map(fn ($p) => html_entity_decode(trim($p)), $m[1]))),
        ];
    }
    usort($templates, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $templates;
}

// --------------------------------------------------------------------- VMs

function waVms(array $roots, array $asleep): array
{
    $virsh = bin('virsh');
    if (!$virsh || !file_exists('/var/run/libvirt/libvirt-sock')) {
        return [];
    }
    [$exit, $out] = run([$virsh, 'list', '--all'], 20);
    if ($exit !== 0) {
        return [];
    }
    $snapCounts = [];
    foreach (glob(WA_LIBVIRT . '/qemu/snapshotdb/*/snapshots.db') ?: [] as $db) {
        $snapCounts[basename(dirname($db))] = count((array) json_decode((string) @file_get_contents($db), true));
    }
    $vms = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\s*(\d+|-)\s+(\S+)\s+(.+?)\s*$/', $line, $m) && $m[2] !== 'Name') {
            $vms[] = waVm($m[2], $m[3], $snapCounts[$m[2]] ?? 0, $roots, $asleep);
        }
    }
    return $vms;
}

/** Everything about one VM that matters for running it — and for moving it to another server */
function waVm(string $name, string $state, int $snapshots, array $roots, array $asleep): array
{
    $xmlFile = WA_LIBVIRT . "/qemu/$name.xml";
    $xml = (string) @file_get_contents($xmlFile);
    $dom = $xml !== '' ? @simplexml_load_string($xml) : false;
    $attr = fn ($node, string $a) => $node ? ((string) ($node[$a] ?? '')) ?: null : null;

    $uuid = $dom ? (string) $dom->uuid : null;
    $os = $dom ? $dom->os : null;
    $loader = $os && $os->loader ? (string) $os->loader : null;
    $nvram = $os && $os->nvram ? (string) $os->nvram : null;
    $template = null;
    if ($dom) {
        foreach ($dom->xpath('//*[local-name()="vmtemplate"]') ?: [] as $t) {
            $template = ['name' => $attr($t, 'name'), 'os' => $attr($t, 'os')];
        }
    }
    $osName = strtolower(($template['os'] ?? '') . ' ' . ($template['name'] ?? ''));
    $tpm = null;
    if ($dom && $dom->devices->tpm) {
        $t = $dom->devices->tpm;
        $dir = $uuid ? WA_LIBVIRT . "/qemu/swtpm/tpm-states/$uuid" : null;
        $tpm = ['model' => $attr($t, 'model'), 'version' => $attr($t->backend, 'version'),
                'state' => $dir && is_dir($dir) ? $dir : null];
    }
    $disks = [];
    if ($dom) {
        foreach ($dom->devices->disk as $d) {
            $src = $attr($d->source, 'file') ?? $attr($d->source, 'dev');
            if (!$src) {
                continue;
            }
            $readable = waPathExists($src, $roots, $asleep) === true;      // never wake a sleeping disk for this
            $disks[] = ['device' => $attr($d, 'device') ?? 'disk', 'source' => $src, 'target' => $attr($d->target, 'dev'),
                        'bus' => $attr($d->target, 'bus'), 'format' => $attr($d->driver, 'type'),
                        'bytes' => $readable ? (int) @filesize($src) : null, 'backup' => backupProtection($src),
                        'chain' => $readable && $attr($d->driver, 'type') === 'qcow2' ? waBackingChain($src) : []];
        }
    }
    $nets = [];
    if ($dom) {
        foreach ($dom->devices->interface as $i) {
            $nets[] = ['mac' => $attr($i->mac, 'address'), 'source' => $attr($i->source, 'bridge') ?? $attr($i->source, 'network') ?? $attr($i->source, 'dev'),
                       'model' => $attr($i->model, 'type')];
        }
    }
    $passthrough = $dom ? count($dom->devices->hostdev) : 0;
    $vnc = null;
    if ($dom) {
        foreach ($dom->devices->graphics as $g) {
            $vnc = ($attr($g, 'type') ?? 'vnc') . (($attr($g, 'port') ?? '-1') !== '-1' ? ':' . $attr($g, 'port') : '');
        }
    }
    // NVRAM copies the VM snapshots left behind: <uuid>S<time>_VARS…
    $nvramCopies = $uuid ? count(glob(WA_LIBVIRT . "/qemu/nvram/{$uuid}S*") ?: []) : 0;
    $memory = $dom && (string) $dom->memory !== '' ? (int) $dom->memory * (strtolower($attr($dom->memory, 'unit') ?? 'kib') === 'kib' ? 1024 : 1) : null;
    return [
        'name'        => $name,
        'state'       => $state,
        'running'     => $state === 'running',
        'autostart'   => is_link(WA_LIBVIRT . "/qemu/autostart/$name.xml") || is_file(WA_LIBVIRT . "/qemu/autostart/$name.xml"),
        'uuid'        => $uuid ?: null,
        'description' => $dom ? trim((string) $dom->description) ?: null : null,
        'os'          => str_contains($osName, 'windows') ? 'windows' : (preg_match('/linux|debian|ubuntu|fedora|arch|centos|alma|rocky|mint|nix/', $osName) ? 'linux' : ($template['os'] ?? null)),
        'template'    => $template['name'] ?? null,
        'machine'     => $os ? $attr($os->type, 'machine') : null,
        'firmware'    => $loader ? (str_contains($loader, 'OVMF') || str_contains($loader, 'ovmf') ? 'uefi' : 'other') : 'bios',
        'secure_boot' => $loader !== null && str_contains($loader, 'secboot'),
        'loader'      => $loader,
        'nvram'       => $nvram,
        'nvram_copies' => $nvramCopies,
        'tpm'         => $tpm,
        'xml'         => is_file($xmlFile) ? $xmlFile : null,
        'cpus'        => $dom && (string) $dom->vcpu !== '' ? (int) $dom->vcpu : null,
        'memory'      => $memory,
        'disks'       => $disks,
        'networks'    => $nets,
        'passthrough' => $passthrough,
        'graphics'    => $vnc,
        'snapshots'   => $snapshots,
        'config_backup' => backupProtection(WA_LIBVIRT),
    ];
}

/**
 * The files a qcow2 disk is built on (an overlay from a VM snapshot sits on
 * its base). Only the header is read; at most a few levels.
 */
function waBackingChain(string $file): array
{
    $chain = [];
    for ($i = 0; $i < 8; $i++) {
        $h = @fopen($file, 'rb');
        $head = $h ? (string) fread($h, 32) : '';
        if (strlen($head) < 20 || substr($head, 0, 4) !== "QFI\xfb") {
            $h && fclose($h);
            break;
        }
        $offset = unpack('J', substr($head, 8, 8))[1];
        $size = unpack('N', substr($head, 16, 4))[1];
        $name = null;
        if ($offset > 0 && $size > 0 && $size < 4096 && fseek($h, $offset) === 0) {
            $name = (string) fread($h, $size);
        }
        fclose($h);
        if (!$name) {
            break;
        }
        $next = $name[0] === '/' ? $name : dirname($file) . '/' . $name;
        $chain[] = ['path' => $next, 'exists' => file_exists($next), 'bytes' => is_file($next) ? (int) @filesize($next) : null,
                    'backup' => backupProtection($next)];
        $file = $next;
        if (!is_file($file)) {
            break;
        }
    }
    return $chain;
}

/**
 * What /boot really is: the classic USB stick (vfat), or — since Unraid 7 —
 * a boot pool on internal disks, e.g. a ZFS mirror over partitions of two
 * SSDs, started from their EFI partitions.
 */
function waBoot(): array
{
    $mount = null;
    foreach (mountTable() as $m) {
        if ($m['mount'] === '/boot') {
            $mount = $m;
        }
    }
    $var = readCfg(WA_VAR_INI);
    $boot = ['fs' => $mount['fs'] ?? null, 'source' => $mount['source'] ?? null, 'kind' => 'other', 'pool' => null,
             'layout' => null, 'state' => null, 'devices' => [], 'efi' => [],
             'guid' => $var['flashGUID'] ?? null, 'vendor' => trim(($var['flashVendor'] ?? '') . ' ' . ($var['flashProduct'] ?? '')) ?: null];
    // what the license is bound to: the TPM of this mainboard (Unraid 7) or the boot stick's GUID
    $reg = $var['regGUID'] ?? '';
    $boot['license'] = $reg === '' ? null
        : ($reg === ($var['tpmGUID'] ?? '') ? 'tpm' : ($reg === ($var['flashGUID'] ?? '') ? 'flash' : 'other'));
    $boot['license_file'] = $var['regFILE'] ?? null;
    $disk = function (string $dev): array {
        $part = basename($dev);
        $base = preg_replace('/(?<=[a-z])\d+$|(?<=\d)p\d+$/', '', $part);
        $sys = "/sys/block/$base";
        return ['dev' => $part, 'disk' => $base,
                'model' => trim((string) @file_get_contents("$sys/device/model")) ?: null,
                'bytes' => (int) @file_get_contents("$sys/size") * 512 ?: null,
                'usb' => str_contains((string) @realpath($sys), '/usb')];
    };
    if (($mount['fs'] ?? '') === 'zfs') {
        $boot['kind'] = 'pool';
        $boot['pool'] = strtok((string) $mount['source'], '/');
        [$exit, $out] = run(['zpool', 'status', '-P', $boot['pool']], 20);
        if ($exit === 0) {
            if (preg_match('/^\s*state:\s*(\S+)/m', $out, $x)) {
                $boot['state'] = $x[1];
            }
            if (preg_match('/^\s+(mirror|raidz\d?)-\d+\s/m', $out, $x)) {
                $boot['layout'] = $x[1];
            }
            preg_match_all('#^\s+(/dev/\S+)\s+(\S+)#m', $out, $x, PREG_SET_ORDER);
            foreach ($x as $d) {
                $boot['devices'][] = $disk($d[1]) + ['state' => $d[2]];
            }
            $boot['layout'] ??= count($boot['devices']) > 1 ? 'stripe' : 'single';
        }
        // the EFI partitions next to the pool members start the server
        foreach ($boot['devices'] as $d) {
            foreach (glob("/sys/block/{$d['disk']}/{$d['disk']}*") ?: [] as $p) {
                $dev = '/dev/' . basename($p);
                [$e, $label] = run(['blkid', '-s', 'LABEL', '-o', 'value', $dev], 5);
                if ($e === 0 && trim($label) === 'EFI') {
                    $boot['efi'][] = basename($p);
                }
            }
        }
    } elseif (($mount['fs'] ?? '') === 'vfat' && $mount['source']) {
        $d = $disk($mount['source']);
        $boot['kind'] = $d['usb'] ? 'usb' : 'internal';
        $boot['devices'][] = $d;
    }
    return $boot;
}

// --------------------------------------------------------------------- where things are

/**
 * The places that matter when something breaks or moves to another server:
 * Unraid's own configuration, Docker templates and compose stacks, the VM
 * configuration in libvirt.img, user scripts, this office. Each with its
 * backup protection (as Mr. Backup's engine would treat it).
 */
function waLocations(array $vms): array
{
    $item = function (string $id, string $path, array $extra = []): array {
        $exists = file_exists($path);
        $count = null;
        if ($exists && is_dir($path) && isset($extra['glob'])) {
            $count = count(glob("$path/{$extra['glob']}") ?: []);
        }
        unset($extra['glob']);
        return ['id' => $id, 'path' => $path, 'exists' => $exists, 'dir' => $exists && is_dir($path),
                'bytes' => $exists && is_file($path) ? (int) @filesize($path) : null, 'count' => $count,
                'backup' => backupProtection($path)] + $extra;
    };
    $docker = readCfg('/boot/config/docker.cfg');
    $domain = readCfg('/boot/config/domain.cfg');
    $groups = [];

    $groups[] = ['id' => 'unraid', 'boot' => waBoot(), 'items' => array_values(array_filter([
        $item('flash_config', '/boot/config'),
        $item('super_dat', '/boot/config/super.dat'),
        $item('disk_cfg', '/boot/config/disk.cfg'),
        $item('network_cfg', '/boot/config/network.cfg'),
        $item('ident_cfg', '/boot/config/ident.cfg'),
        $item('share_cfgs', WA_SHARES_DIR, ['glob' => '*.cfg']),
        $item('users', '/boot/config/passwd'),
        $item('smb_users', '/boot/config/smbpasswd'),
        is_dir('/boot/config/ssh') ? $item('ssh', '/boot/config/ssh') : null,
        is_dir('/boot/config/wireguard') ? $item('wireguard', '/boot/config/wireguard', ['glob' => '*.conf']) : null,
        $item('plugins', WA_PLUGINS, ['glob' => '*.plg']),
        $item('syslog', '/var/log/syslog', ['ram' => true]),
    ]))];

    $templates = $item('docker_templates', WA_TEMPLATES, ['glob' => 'my-*.xml']);
    $templates['files'] = array_map('basename', glob(WA_TEMPLATES . '/my-*.xml') ?: []);
    $groups[] = ['id' => 'docker', 'items' => array_values(array_filter([
        $templates,
        $item('docker_cfg', '/boot/config/docker.cfg'),
        !empty($docker['DOCKER_IMAGE_FILE']) ? $item('docker_image', rtrim($docker['DOCKER_IMAGE_FILE'], '/'),
            ['note' => ($docker['DOCKER_IMAGE_TYPE'] ?? '') === 'folder' ? 'folder' : 'image']) : null,
        !empty($docker['DOCKER_APP_CONFIG_PATH']) ? $item('appdata', rtrim($docker['DOCKER_APP_CONFIG_PATH'], '/')) : null,
    ]))];

    $stacks = [];
    foreach (glob(WA_COMPOSE . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $indirect = trim((string) @file_get_contents("$dir/indirect"));
        $where = $indirect !== '' && is_dir($indirect) ? $indirect : $dir;
        $files = [];
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml', 'compose.override.yaml',
                  'compose.override.yml', 'docker-compose.override.yml', 'docker-compose.override.yaml', '.env'] as $f) {
            if (is_file("$where/$f")) {
                $files[] = ['path' => "$where/$f", 'backup' => backupProtection("$where/$f")];
            }
        }
        $stacks[] = ['name' => trim((string) @file_get_contents("$dir/name")) ?: basename($dir), 'dir' => $dir,
                     'indirect' => $indirect !== '' ? $indirect : null, 'files' => $files, 'backup' => backupProtection($dir)];
    }
    $groups[] = ['id' => 'compose', 'items' => [$item('compose_projects', WA_COMPOSE, ['glob' => '*'])], 'stacks' => $stacks];

    $groups[] = ['id' => 'vms', 'items' => array_values(array_filter([
        $item('domain_cfg', '/boot/config/domain.cfg'),
        !empty($domain['IMAGE_FILE']) ? $item('libvirt_img', $domain['IMAGE_FILE'], ['mounted' => WA_LIBVIRT]) : null,
        $item('vm_xml', WA_LIBVIRT . '/qemu', ['glob' => '*.xml']),
        $item('vm_nvram', WA_LIBVIRT . '/qemu/nvram', ['glob' => '*_VARS*.fd']),
        $item('vm_tpm', WA_LIBVIRT . '/qemu/swtpm/tpm-states', ['glob' => '*']),
        $item('vm_snapshotdb', WA_LIBVIRT . '/qemu/snapshotdb', ['glob' => '*']),
        !empty($domain['DOMAINDIR']) ? $item('vm_domains', rtrim($domain['DOMAINDIR'], '/')) : null,
        !empty($domain['MEDIADIR']) ? $item('vm_isos', rtrim($domain['MEDIADIR'], '/')) : null,
    ])), 'vms' => count($vms)];

    $groups[] = ['id' => 'scripts', 'items' => [
        $item('user_scripts', WA_SCRIPTS, ['glob' => '*']),
        $item('user_scripts_schedule', WA_SCRIPTS_JSON),
    ]];

    $groups[] = ['id' => 'office', 'items' => array_values(array_filter([
        $item('office_dir', OFFICE_DIR),
        $item('office_data', DATA_DIR),
        is_dir(BACKUP_DATA_DIR) ? $item('backup_data', BACKUP_DATA_DIR) : null,
        is_dir(BACKUP_DATA_DIR . '/dumps') ? $item('backup_dumps', BACKUP_DATA_DIR . '/dumps', ['glob' => '[0-9]*']) : null,
    ]))];
    return $groups;
}

// --------------------------------------------------------------------- users & network shares

function waUsers(): array
{
    $smb = [];
    foreach (@file('/boot/config/smbpasswd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $name = explode(':', $line)[0] ?? '';
        if ($name !== '') {
            $smb[$name] = true;
        }
    }
    $users = [];
    foreach (@file('/etc/passwd', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(':', $line);
        if (count($f) >= 5 && (int) $f[2] >= 1000 && (int) $f[2] < 60000) {
            $users[] = ['name' => $f[0], 'uid' => (int) $f[2], 'description' => $f[4], 'smb' => isset($smb[$f[0]])];
        }
    }
    return $users;
}

function waSmb(): array
{
    $ident = readCfg('/boot/config/ident.cfg');
    $share = readCfg('/boot/config/share.cfg');
    $extra = (string) @file_get_contents('/boot/config/smb-extra.conf', false, null, 0, 8192);
    $custom = json_decode((string) @file_get_contents(WA_PLUGINS . '/custom.smb.shares/shares.json'), true);

    $sessions = [];
    $smbstatus = bin('smbstatus');
    if ($smbstatus) {
        [$exit, $out] = run([$smbstatus, '-j'], 20);
        $data = $exit === 0 ? json_decode($out, true) : null;
        if (is_array($data)) {
            foreach ((array) ($data['sessions'] ?? []) as $id => $s) {
                $sessions[$id] = [
                    'user'    => $s['username'] ?? '?',
                    'machine' => $s['remote_machine'] ?? '?',
                    'since'   => isset($s['creation_time']) ? (strtotime($s['creation_time']) ?: null) : null,
                    'dialect' => $s['session_dialect'] ?? null,
                    'shares'  => [],
                ];
            }
            foreach ((array) ($data['tcons'] ?? []) as $t) {
                $sid = $t['session_id'] ?? null;
                if ($sid !== null && isset($sessions[$sid])) {
                    $sessions[$sid]['shares'][] = ['share' => $t['service'] ?? '?',
                        'since' => isset($t['connected_at']) ? (strtotime($t['connected_at']) ?: null) : null];
                }
            }
        }
    }
    return [
        'enabled'   => ($share['shareSMBEnabled'] ?? 'yes') !== 'no',
        'workgroup' => $ident['WORKGROUP'] ?? null,
        'security'  => $ident['SECURITY'] ?? null,
        'fruit'     => ($ident['enableFruit'] ?? '') === 'yes',
        'netbios'   => ($ident['USE_NETBIOS'] ?? '') === 'yes',
        'wsd'       => ($ident['USE_WSD'] ?? '') === 'yes',
        'extra'     => trim($extra),
        'custom'    => is_array($custom) ? array_values($custom) : [],
        'sessions'  => array_values($sessions),
    ];
}

function waNfs(): array
{
    $share = readCfg('/boot/config/share.cfg');
    $exports = [];
    foreach (@file('/etc/exports', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#') {
            $exports[] = $line;
        }
    }
    return ['enabled' => ($share['shareNFSEnabled'] ?? 'no') === 'yes', 'exports' => $exports];
}

// --------------------------------------------------------------------- scripts & cron

/**
 * Does a path exist? Only looked at where no disk wakes up: /boot, pools and
 * exclusive shares. Anything else (array disks, wildcards) stays null = unknown.
 */
function waPathExists(string $path, array $roots, array $asleep): ?bool
{
    if (str_contains($path, '*') || str_contains($path, '$')) {
        return null;
    }
    if (str_starts_with($path, '/boot/')) {
        return file_exists($path);
    }
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)#', $path, $m)) {
        return null;
    }
    if ($m[1] === 'user') {
        $link = @readlink("/mnt/user/{$m[2]}");     // exclusive shares are symlinks to their pool
        if ($link === false || !preg_match('#^\.\./([^/]+)/#', $link . '/', $p) || ($asleep[$p[1]] ?? false)) {
            return null;
        }
        return file_exists($path);
    }
    if (($roots[$m[1]]['kind'] ?? '') === 'pool' && !($asleep[$m[1]] ?? false)) {
        return file_exists($path);
    }
    return null;
}

function waUserScripts(array $roots, array $asleep): array
{
    $schedule = json_decode((string) @file_get_contents(WA_SCRIPTS_JSON), true) ?: [];
    $scripts = [];
    foreach (glob(WA_SCRIPTS . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $id = basename($dir);
        $file = "$dir/script";
        $text = (string) @file_get_contents($file, false, null, 0, WA_SCRIPT_BYTES);
        $plan = $schedule[$file] ?? [];
        $description = trim((string) @file_get_contents("$dir/description"));
        if ($description === '' && preg_match('/^#\s*description=(.*)$/m', $text, $m)) {
            $description = trim($m[1]);
        }
        preg_match_all('#(?<![\w$}])(/(?:mnt|boot)/[^\s"\'`;|&<>(){}$]+)#', $text, $m);
        $refs = [];
        foreach (array_unique(array_map(fn ($p) => rtrim($p, '/.,;:'), $m[1])) as $path) {
            $refs[] = ['path' => $path, 'exists' => waPathExists($path, $roots, $asleep)];
        }

        $running = false;
        $pid = (int) @file_get_contents(WA_SCRIPTS_TMP . "/running/$id");
        if ($pid > 1 && is_dir("/proc/$pid")) {
            $running = true;
        }
        $log = WA_SCRIPTS_TMP . "/tmpScripts/$id/log.txt";
        $scripts[] = [
            'id'          => $id,
            'name'        => trim((string) @file_get_contents("$dir/name")) ?: $id,
            'description' => $description,
            'file'        => $file,
            'size'        => (int) @filesize($file),
            'text'        => $text,
            'cut'         => @filesize($file) > WA_SCRIPT_BYTES,
            'frequency'   => $plan['frequency'] ?? 'disabled',
            'cron'        => trim((string) ($plan['custom'] ?? '')) ?: null,
            'running'     => $running,
            'stale'       => !$running && is_file(WA_SCRIPTS_TMP . "/running/$id"),
            'last_run'    => @filemtime($log) ?: null,
            'refs'        => array_slice($refs, 0, 40),
            'missing'     => count(array_filter($refs, fn ($r) => $r['exists'] === false)),
        ];
    }
    usort($scripts, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $scripts;
}

function waCron(): array
{
    $jobs = [];
    $parse = function (string $source, string $text) use (&$jobs): void {
        $title = null;
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                $title = null;
                continue;
            }
            if ($line[0] === '#') {
                if (preg_match('/^#\s*(?:Generated\s+)?(.+?)(?:\s+schedule)?:?\s*$/i', $line, $m)) {
                    $title = $m[1];
                }
                continue;
            }
            if (preg_match('/^(@\w+)\s+(.+)$/', $line, $m)) {
                $jobs[] = ['source' => $source, 'title' => $title, 'schedule' => $m[1], 'command' => $m[2]];
            } elseif (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+)$/', $line, $m)) {
                $jobs[] = ['source' => $source, 'title' => $title, 'schedule' => $m[1], 'command' => $m[2]];
            }
            $title = null;
        }
    };
    [$exit, $out] = run(['crontab', '-l'], 10);
    if ($exit === 0) {
        $parse('crontab', $out);
    }
    foreach (glob('/etc/cron.d/*') ?: [] as $file) {
        $parse($file, (string) @file_get_contents($file));
    }
    return $jobs;
}

// --------------------------------------------------------------------- backups

/** Places that look like backups — configured ones and educated guesses */
function waBackups(array $containers, array $scripts, array $backupScript): array
{
    $found = [];

    if ($backupScript['found']) {
        $s = $backupScript['settings'];
        $found[] = ['kind' => 'unraid-backup', 'title' => 'unraid-backup',
            'paths' => array_values(array_filter([$backupScript['dir'], $backupScript['mount_root']])),
            'detail' => array_filter([
                'running'   => $backupScript['running'],
                'since'     => $backupScript['since'],
                'step'      => $backupScript['step'],
                'prefix'    => $backupScript['prefix'],
                'retention' => $s['retention'] ?? null,
            ], fn ($v) => $v !== null && $v !== false)];
    }

    $ab = json_decode((string) @file_get_contents(WA_PLUGINS . '/appdata.backup/config.json'), true);
    if (is_array($ab)) {
        $found[] = ['kind' => 'appdata.backup', 'title' => 'Appdata Backup',
            'paths' => array_values(array_filter([$ab['destination'] ?? null])),
            'detail' => array_filter([
                'sources'   => is_array($ab['allowedSources'] ?? null) ? implode(', ', $ab['allowedSources']) : ($ab['allowedSources'] ?? null),
                'frequency' => $ab['backupFrequency'] ?? null,
                'keep_min'  => $ab['keepMinBackups'] ?? null,
                'max_days'  => $ab['deleteBackupsOlderThan'] ?? null,
            ])];
    }

    $vb = readCfg(WA_PLUGINS . '/vmbackup/user.cfg');
    if ($vb) {
        $found[] = ['kind' => 'vmbackup', 'title' => 'VM Backup',
            'paths' => array_values(array_filter([$vb['backup_location'] ?? null])),
            'detail' => array_filter([
                'enabled' => ($vb['enabled'] ?? '0') === '1' ? 'yes' : 'no',
                'vms'     => ($vb['backup_all_vms'] ?? '0') === '1' ? '*' : ($vb['vms_to_backup'] ?? null),
                'keep'    => $vb['number_of_backups_to_keep'] ?? null,
            ])];
    }

    foreach ($containers as $c) {
        $image = strtolower($c['image']);
        $kind = null;
        if (str_contains($image, 'kopia')) {
            $kind = 'kopia';
        } elseif (str_contains($image, 'timemachine')) {
            $kind = 'timemachine';
        } elseif (preg_match('/restic|borg|duplicati|duplicacy|rclone|urbackup|syncthing/', $image)) {
            $kind = 'backup-app';
        } elseif (preg_match('/backup/i', $c['name'])) {
            $kind = 'container';
        }
        if ($kind) {
            $paths = [];
            foreach ($c['mounts'] as $m) {
                if (str_starts_with($m['source'], '/mnt/')) {
                    $paths[] = $m['source'];
                }
            }
            $found[] = ['kind' => $kind, 'title' => $c['name'], 'paths' => $paths, 'detail' => ['image' => $c['image'], 'state' => $c['state']]];
        }
    }

    foreach ($scripts as $s) {
        if (preg_match('/backup|sicherung|rsync|kopia|restic|borg|rclone/i', $s['name'])) {
            $found[] = ['kind' => 'script', 'title' => $s['name'],
                'paths' => array_values(array_filter(array_column($s['refs'], 'path'), fn ($p) => str_starts_with($p, '/mnt/'))),
                'detail' => array_filter(['frequency' => $s['frequency'], 'cron' => $s['cron']])];
        }
    }

    foreach (array_merge(glob(WA_SHARES_DIR . '/*.cfg') ?: []) as $file) {
        $name = basename($file, '.cfg');
        if (preg_match('/backup|sicherung|time.?machine/i', $name)) {
            $found[] = ['kind' => 'share', 'title' => $name, 'paths' => ["/mnt/user/$name"], 'detail' => []];
        }
    }

    $snapshots = readJson(deskFile('snapshot'));
    if ($snapshots) {
        $n = 0;
        foreach (array_merge($snapshots['zfs']['snapshots'] ?? [], $snapshots['btrfs']['snapshots'] ?? []) as $s) {
            $n += empty($s['docker']) ? 1 : 0;
        }
        $found[] = ['kind' => 'snapshots', 'title' => 'Ms. Snapshot', 'paths' => [], 'detail' => ['count' => $n]];
    }
    return $found;
}

// --------------------------------------------------------------------- health: temperatures, SMART, fill level

const WA_SMART_DIR   = '/var/local/emhttp/smart';
const WA_SMART_BAD   = [5, 187, 197, 198];     // reallocated, uncorrectable, pending, offline uncorrectable
const WA_SMART_WATCH = [5, 187, 188, 197, 198, 199];

/**
 * Every disk Unraid knows (array, pools, boot, unassigned devices) with its
 * temperature against the threshold that applies to it, SMART findings and
 * how full it is. Only Unraid's own cached values are read — no disk wakes up.
 */
function waHealth(): array
{
    $dyn = readCfg('/boot/config/plugins/dynamix/dynamix.cfg', true)['display'] ?? [];   // thresholds live in [display]
    $diskCfg = readCfg('/boot/config/disk.cfg');
    $smartOne = readCfg('/boot/config/smart-one.cfg', true);
    $smartAll = readCfg('/boot/config/smart-all.cfg');
    $var = readCfg(WA_VAR_INI);
    $limits = [
        'hdd_hot'  => (int) ($dyn['hot'] ?? 45),    'hdd_max' => (int) ($dyn['max'] ?? 55),
        'ssd_hot'  => (int) ($dyn['hotssd'] ?? 60), 'ssd_max' => (int) ($dyn['maxssd'] ?? 70),
        'warning'  => (int) ($dyn['warning'] ?? 70), 'critical' => (int) ($dyn['critical'] ?? 90),
    ];
    $watchAll = waSmartList($smartAll['smEvents'] ?? '') ?: WA_SMART_WATCH;

    $devices = [];
    $byDevice = [];
    $paritySlots = 0;
    $parityPresent = 0;
    $sources = [['array', readCfg('/var/local/emhttp/disks.ini', true)], ['unassigned', readCfg('/var/local/emhttp/devs.ini', true)]];
    foreach ($sources as [$origin, $entries]) {
        foreach ($entries as $name => $d) {
            $type = $d['type'] ?? ($origin === 'unassigned' ? 'Unassigned' : '');
            if ($type === 'Parity') {
                $paritySlots++;
                $parityPresent += ($d['device'] ?? '') !== '' ? 1 : 0;
            }
            $dev = $d['device'] ?? '';
            if ($dev === '') {
                continue;
            }
            if (isset($byDevice[$dev])) {               // the same disk in two roles (e.g. pool and boot)
                $devices[$byDevice[$dev]]['roles'][] = $name;
                continue;
            }
            $id = $d['id'] ?? '';
            $rotational = ($d['rotational'] ?? '1') === '1';
            $one = $smartOne[$id] ?? [];
            $hot = (int) ($one['hotTemp'] ?? 0) ?: ($rotational ? $limits['hdd_hot'] : $limits['ssd_hot']);
            $max = (int) ($one['maxTemp'] ?? 0) ?: ($rotational ? $limits['hdd_max'] : $limits['ssd_max']);
            $temp = ctype_digit($d['temp'] ?? '') ? (int) $d['temp'] : null;

            // fill level and its thresholds: per array disk, per pool, global
            $size = num($d['fsSize'] ?? '');
            $free = num($d['fsFree'] ?? '');
            $pool = readCfg("/boot/config/pools/$name.cfg");
            $idx = $d['idx'] ?? '';
            $warn = (int) (($pool['diskWarning'] ?? '') ?: ($diskCfg["diskWarning.$idx"] ?? '') ?: $limits['warning']);
            $crit = (int) (($pool['diskCritical'] ?? '') ?: ($diskCfg["diskCritical.$idx"] ?? '') ?: $limits['critical']);

            $watch = waSmartList($one['smEvents'] ?? '') ?: $watchAll;
            $byDevice[$dev] = count($devices);
            $devices[] = [
                'name'       => $name,
                'roles'      => [],
                'origin'     => $origin,
                'type'       => $type,
                'device'     => $dev,
                'id'         => $id,
                'transport'  => $d['transport'] ?? '',
                'rotational' => $rotational,
                'asleep'     => ($d['spundown'] ?? '0') === '1',
                'status'     => $d['status'] ?? null,
                'errors'     => num($d['numErrors'] ?? '0'),
                'temp'       => $temp,
                'hot'        => $hot,
                'max'        => $max,
                'fill'       => $size > 0 ? round(($size - $free) / $size * 100, 1) : null,
                'size'       => $size > 0 ? $size * 1024 : null,
                'warn'       => $warn,
                'crit'       => $crit,
                'smart'      => waSmart(WA_SMART_DIR . "/$name", $watch),
            ];
        }
    }
    return [
        'limits'  => $limits,
        'devices' => $devices,
        'parity'  => [
            'slots'   => $paritySlots,
            'present' => $parityPresent,
            'checked' => num($var['sbSynced'] ?? '0') ?: null,
            'errors'  => num($var['sbSyncErrs'] ?? '0'),
        ],
    ];
}

/** "5|187|188" → [5, 187, 188] */
function waSmartList(string $value): array
{
    return array_values(array_map('intval', array_filter(preg_split('/[|,\s]+/', $value), 'ctype_digit')));
}

/** Unraid's cached smartctl output: ATA attributes or the NVMe health log */
function waSmart(string $file, array $watch): ?array
{
    $text = (string) @file_get_contents($file);
    if ($text === '') {
        return null;
    }
    $smart = ['read' => @filemtime($file) ?: null, 'kind' => null, 'hours' => null, 'attributes' => [], 'nvme' => [], 'problems' => []];
    if (preg_match_all('/^\s*(\d+)\s+(\S+)\s+0x[0-9a-f]+\s+(\d+)\s+(\d+)\s+(\d+)\s+\S+\s+\S+\s+(\S+)\s+(.*)$/m', $text, $rows, PREG_SET_ORDER)) {
        $smart['kind'] = 'ata';
        foreach ($rows as [, $id, $name, $value, $worst, $thresh, $failed, $raw]) {
            $id = (int) $id;
            $rawNum = preg_match('/^\s*(\d+)/', $raw, $x) ? (int) $x[1] : 0;
            $smart['attributes'][] = ['id' => $id, 'name' => $name, 'value' => (int) $value, 'worst' => (int) $worst,
                'thresh' => (int) $thresh, 'failed' => $failed !== '-' ? $failed : null, 'raw' => trim($raw)];
            if ($id === 9) {
                $smart['hours'] = $rawNum;
            }
            if ($failed !== '-') {
                $smart['problems'][] = ['level' => 'bad', 'key' => 'failing', 'id' => $id, 'name' => $name, 'raw' => trim($raw)];
            } elseif (in_array($id, $watch, true) && $rawNum > 0) {
                $smart['problems'][] = ['level' => in_array($id, WA_SMART_BAD, true) ? 'bad' : 'notice', 'key' => 'attribute',
                    'id' => $id, 'name' => $name, 'raw' => trim($raw)];
            }
        }
    }
    if (preg_match_all('/^([A-Za-z][A-Za-z .\/-]+?):\s+(.+)$/m', $text, $lines, PREG_SET_ORDER)) {
        $n = [];
        foreach ($lines as [, $k, $v]) {
            $n[trim($k)] = trim($v);
        }
        if (isset($n['Critical Warning']) || isset($n['Percentage Used'])) {
            $smart['kind'] = 'nvme';
            $int = fn (string $k) => isset($n[$k]) ? (int) str_replace([',', '%'], '', $n[$k]) : null;
            $smart['nvme'] = [
                'critical_warning' => $n['Critical Warning'] ?? null,
                'spare'            => $int('Available Spare'),
                'spare_threshold'  => $int('Available Spare Threshold'),
                'used'             => $int('Percentage Used'),
                'media_errors'     => $int('Media and Data Integrity Errors'),
                'unsafe_shutdowns' => $int('Unsafe Shutdowns'),
                'written'          => $n['Data Units Written'] ?? null,
            ];
            $smart['hours'] = $int('Power On Hours');
            $nv = $smart['nvme'];
            if ($nv['critical_warning'] !== null && hexdec($nv['critical_warning']) !== 0) {
                $smart['problems'][] = ['level' => 'bad', 'key' => 'nvme_warning', 'raw' => $nv['critical_warning']];
            }
            if ($nv['media_errors']) {
                $smart['problems'][] = ['level' => 'bad', 'key' => 'nvme_media', 'raw' => (string) $nv['media_errors']];
            }
            if ($nv['spare'] !== null && $nv['spare_threshold'] !== null && $nv['spare'] <= $nv['spare_threshold']) {
                $smart['problems'][] = ['level' => 'bad', 'key' => 'nvme_spare', 'raw' => "{$nv['spare']}%"];
            }
            if ($nv['used'] !== null && $nv['used'] >= 80) {
                $smart['problems'][] = ['level' => $nv['used'] >= 100 ? 'bad' : 'notice', 'key' => 'nvme_worn', 'raw' => "{$nv['used']}%"];
            }
        }
    }
    return $smart;
}

/** Unread Unraid notifications (the bell in the web GUI) */
function waNotices(): array
{
    $list = [];
    foreach (glob('/tmp/notifications/unread/*.notify') ?: [] as $file) {
        $n = readCfg($file);
        $list[] = [
            'time'        => num($n['timestamp'] ?? '0') ?: (@filemtime($file) ?: null),
            'event'       => $n['event'] ?? '',
            'subject'     => $n['subject'] ?? basename($file, '.notify'),
            'description' => $n['description'] ?? '',
            'importance'  => $n['importance'] ?? 'normal',
            'link'        => $n['link'] ?? null,
        ];
    }
    usort($list, fn ($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
    return array_slice($list, 0, 200);
}

/** The Unraid license, as far as it is visible on the server */
function waLicense(): array
{
    $v = readCfg(WA_VAR_INI);
    $guid = $v['flashGUID'] ?? '';
    $used = 0;
    foreach (['/var/local/emhttp/disks.ini', '/var/local/emhttp/devs.ini'] as $file) {
        foreach (readCfg($file, true) as $d) {
            if (($d['device'] ?? '') !== '' && ($d['type'] ?? '') !== 'Flash') {
                $used++;
            }
        }
    }
    return [
        'type'    => ($v['regTy'] ?? '') ?: null,
        'to'      => ($v['regTo'] ?? '') ?: null,
        'since'   => num($v['regTm'] ?? '') ?: null,
        'expires' => num($v['regExp'] ?? '') ?: null,
        'limit'   => ($v['regDevs'] ?? '') !== '' ? (int) $v['regDevs'] : null,
        'devices' => $used,
        'guid'    => strlen($guid) > 8 ? substr($guid, 0, 4) . '…' . substr($guid, -4) : ($guid ?: null),
        'check'   => trim(($v['regCheck'] ?? '') . ' ' . ($v['regFlashCheck'] ?? '')) ?: null,
    ];
}

// --------------------------------------------------------------------- plugins & system

function waPlugins(): array
{
    $plugins = [];
    foreach (glob(WA_PLUGINS . '/*.plg') ?: [] as $file) {
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        $entity = function (string $key) use ($head): ?string {
            return preg_match('/<!ENTITY\s+' . $key . '\s+"([^"]*)"/i', $head, $m) ? $m[1] : null;
        };
        $version = $entity('version');
        if ($version === null && preg_match('/<PLUGIN[^>]*\sversion="([^"&]*)"/i', $head, $m)) {
            $version = $m[1];
        }
        $plugins[] = [
            'name'    => $entity('name') ?? basename($file, '.plg'),
            'file'    => basename($file),
            'version' => $version,
            'author'  => $entity('author'),
        ];
    }
    usort($plugins, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $plugins;
}

function waSystem(array $containers, array $vms, array $scripts, array $backupScript): array
{
    $var = readCfg(WA_VAR_INI);
    $scrubs = [];
    $zpool = bin('zpool');
    if ($zpool) {
        [$exit, $out] = run([$zpool, 'status'], 20);
        $pool = null;
        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^\s*pool:\s*(\S+)/', $line, $m)) {
                $pool = $m[1];
            } elseif ($pool && preg_match('/^\s*scan:\s*(.*in progress.*)$/', $line, $m)) {
                $scrubs[] = ['pool' => $pool, 'text' => trim($m[1])];
            }
        }
    }
    $pos = (int) ($var['mdResyncPos'] ?? 0);
    $size = (int) ($var['mdResyncSize'] ?? 0);
    return [
        'name'      => $var['NAME'] ?? hostname(),
        'unraid'    => $var['version'] ?? null,
        'uptime'    => (int) (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0],
        'load'      => sys_getloadavg() ?: null,
        'array'     => [
            'state'    => $var['mdState'] ?? null,
            'fs'       => $var['fsState'] ?? null,
            'resync'   => $pos > 0 ? ['action' => $var['mdResyncAction'] ?? null, 'percent' => $size ? round($pos / $size * 100, 1) : null] : null,
        ],
        'mover'     => ($var['shareMoverActive'] ?? 'no') === 'yes',
        'scrubs'    => $scrubs,
        'docker'    => ['running' => count(array_filter($containers, fn ($c) => $c['state'] === 'running')), 'total' => count($containers)],
        'vms'       => ['running' => count(array_filter($vms, fn ($v) => $v['running'])), 'total' => count($vms)],
        'scripts'   => array_values(array_map(fn ($s) => $s['name'], array_filter($scripts, fn ($s) => $s['running']))),
        'backup'    => ['running' => $backupScript['running'], 'since' => $backupScript['since'], 'step' => $backupScript['step']],
    ];
}

// ===================================================================== sizes (du in the background)

function whereaboutsSizesFile(): string
{
    return DATA_DIR . '/whereabouts-sizes.json';
}

function whereaboutsSizes(): array
{
    $saved = readJson(whereaboutsSizesFile()) ?? [];
    return [
        'sizes'   => $saved['sizes'] ?? [],
        'queue'   => array_values($GLOBALS['waJobs']['queue']),
        'running' => array_keys($GLOBALS['waJobs']['running']),
    ];
}

/** Paths that may be measured: shares and folders from the last tour */
function whereaboutsMeasurable(): array
{
    $allowed = [];
    $state = $GLOBALS['whereabouts'] ?? [];
    foreach ($state['shares'] ?? [] as $s) {
        $allowed[$s['measure']] = true;
    }
    foreach ($state['folders'] ?? [] as $f) {
        foreach ($f['folders'] as $folder) {
            $allowed[$folder['real']] = true;
        }
    }
    return $allowed;
}

function whereaboutsMeasure(array $paths): array
{
    $allowed = whereaboutsMeasurable();
    foreach ($paths as $path) {
        if (!isset($allowed[$path])) {
            throw new Problem('unknown_target', ['target' => $path]);
        }
    }
    foreach ($paths as $path) {
        if (!isset($GLOBALS['waJobs']['running'][$path]) && !in_array($path, $GLOBALS['waJobs']['queue'], true)) {
            $GLOBALS['waJobs']['queue'][] = $path;
        }
    }
    whereaboutsSaveSizes(null);
    return ['ok' => true, 'sizes' => whereaboutsSizes()];
}

function whereaboutsJobsTick(): void
{
    $jobs = &$GLOBALS['waJobs'];
    $changed = null;
    foreach ($jobs['running'] as $path => $job) {
        $status = proc_get_status($job['process']);
        $out = stream_get_contents($job['out']);
        if ($out !== false) {
            $jobs['running'][$path]['buffer'] .= $out;
        }
        if ($status['running']) {
            continue;
        }
        $buffer = $jobs['running'][$path]['buffer'] . (string) stream_get_contents($job['out']);
        fclose($job['out']);
        proc_close($job['process']);
        $bytes = preg_match('/^(\d+)\s/', $buffer, $m) ? (int) $m[1] : null;
        $changed[$path] = ['bytes' => $bytes, 'at' => time(), 'seconds' => time() - $job['since'],
                           'partial' => $status['exitcode'] !== 0];
        unset($jobs['running'][$path]);
        logLine(sprintf('Measured %s: %s in %d s', $path, $bytes === null ? '?' : number_format($bytes), time() - $job['since']));
    }
    while (count($jobs['running']) < WA_DU_PARALLEL && $jobs['queue']) {
        $path = array_shift($jobs['queue']);
        $pipes = [];
        $process = proc_open(['nice', '-n', '10', 'du', '-s', '-B1', '-x', $path],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
        if (!is_resource($process)) {
            continue;
        }
        stream_set_blocking($pipes[1], false);
        $jobs['running'][$path] = ['process' => $process, 'out' => $pipes[1], 'since' => time(), 'buffer' => ''];
        $changed ??= [];
    }
    if ($changed !== null) {
        whereaboutsSaveSizes($changed);
    }
}

function whereaboutsSaveSizes(?array $changed): void
{
    $saved = readJson(whereaboutsSizesFile()) ?? [];
    $sizes = $saved['sizes'] ?? [];
    foreach ($changed ?? [] as $path => $size) {
        $sizes[$path] = $size;
    }
    writeAtomic(whereaboutsSizesFile(), jsonEncode([
        'sizes'   => $sizes,
        'queue'   => array_values($GLOBALS['waJobs']['queue']),
        'running' => array_keys($GLOBALS['waJobs']['running']),
    ]));
}
