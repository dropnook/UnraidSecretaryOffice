<?php
declare(strict_types=1);

/*
 * Ms. Dustdevil — clears away what nobody uses any more.
 *
 * Three kinds of leftovers, best tidied in this order:
 *   templates  my-*.xml in dockerMan's templates-user without a container
 *   stacks     Compose Manager stacks without containers, or broken ones
 *   appdata    first-level folders of the appdata share that nothing names:
 *              container mounts, templates, stacks, compose files, VMs,
 *              anything on the flash (User Scripts, plugin settings …)
 *
 * Nothing is deleted right away. What the user clears away is renamed into a
 * trash folder (CL_TRASH) on the same filesystem it lives on — the flash for
 * templates and stacks, the pool itself for appdata — so putting away and
 * putting back is a rename, never a copy; a rename that fails is refused.
 * Every run gets a folder <stamp>/ with a manifest.json saying where each
 * thing came from. The trash on disk is the truth, there is no index that
 * could go stale. Emptying it is the only permanent step (in the background:
 * the run is renamed to <stamp>.purging first, so it is gone at once).
 * The old cleanup script's trash (CL_LEGACY) is shown and can be emptied, but
 * is never written to.
 *
 * Slow work runs in the background, polled from tick: searching the flash for
 * paths and measuring folders (file count, size, newest change). Sleeping
 * disks are never touched; nothing is changed while a backup runs.
 */

const CL_TEMPLATES    = '/boot/config/plugins/dockerMan/templates-user';
const CL_COMPOSE_CFG  = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const CL_COMPOSE_DEF  = '/boot/config/plugins/compose.manager/projects';
const CL_FLASH        = '/boot/config';
const CL_LIBVIRT      = '/etc/libvirt/qemu';
const CL_TRASH        = '_UnraidSecretaryOffice-trash';
const CL_LEGACY       = '_zumloeschen';            // trash of the old unraid-cleanup.sh
const CL_KINDS        = ['templates', 'compose', 'appdata'];
const CL_FRESH_DAYS   = 30;                        // a folder changed since then is only "check"
const CL_MEASURE_TTL  = 6 * 3600;                  // a candidate's measurement must be this fresh to be put away
const CL_FLASH_TTL    = 1800;                      // search the flash again after this
const CL_PARALLEL     = 2;                         // background jobs at once (purges don't wait)
const CL_FOLDER_LIMIT = 2000;
const CL_TEXT_MAX     = 256 * 1024;
const CL_COMPOSE_FILES  = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];
const CL_OVERRIDE_FILES = ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'];
// mentions on the flash that don't make a folder "used": caches, shell history, plugin installers
const CL_WEAK = '#^(history/|plugins-removed/|[^/]+\.plg$|plugins/[^/]+\.plg$|plugins/dockerMan/(buildx|templates|template-repos|images)/'
              . '|plugins/dynamix\.my\.servers/configs/docker\.organizer\.json$|plugins/compose\.manager/containers\.cache\.json$)#';

$GLOBALS['clState'] = null;
$GLOBALS['clCtx'] = ['roots' => [], 'asleep' => []];
$GLOBALS['clJobs'] = ['queue' => [], 'running' => []];
$GLOBALS['clPurgeTries'] = [];

desk('cleanup', [
    'fit'     => fn (): array => (readCfg('/boot/config/docker.cfg')['DOCKER_ENABLED'] ?? 'no') === 'yes'
                                 ? fit(true, 'yes') : fit(false, 'no_docker'),
    'start'   => fn () => clScan(),
    'tick'    => fn () => clJobsTick(),
    'checks'  => fn (): array => clChecks(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => clScan()],
        'scan'    => fn (array $r) => ['ok' => true, 'state' => clScan(!empty($r['wake']))],
        'measure' => fn (array $r) => clMeasure(idList($r, 'ids')),
        'detail'  => fn (array $r) => clDetail(textField($r, 'id')),
        'park'    => fn (array $r) => clPark(idList($r, 'ids'), !empty($r['force'])),
        'restore' => fn (array $r) => clRestore(idList($r, 'ids')),
        'purge'   => fn (array $r) => clPurge(idList($r, 'ids'), !empty($r['volumes']), !empty($r['images'])),
    ],
]);

// ===================================================================== tour

/**
 * Reads the server (quick part), queues the slow work, builds the state.
 * $jobs = false: only look (before changing something — no new search may
 * hold up the change that is about to happen).
 */
function clScan(bool $wake = false, bool $jobs = true): array
{
    $t0 = microtime(true);
    $roots = clRoots();
    $asleep = sleepingDisks();
    $appdata = clAppdataPlaces($roots, $asleep);
    if ($wake && $appdata['asleep']) {
        clWake($appdata['asleep']);
        $asleep = sleepingDisks();
        foreach ($appdata['asleep'] as $name) {
            $asleep[$name] = false;            // Unraid's bookkeeping lags behind
        }
        $appdata = clAppdataPlaces($roots, $asleep);
    }
    $GLOBALS['clCtx'] = ['roots' => $roots, 'asleep' => $asleep];

    $docker = clDocker();
    $cache = clCache();
    $raw = [
        'docker'    => $docker,
        'templates' => clTemplates($docker),
        'stacks'    => clStacks($docker, $cache),
        'appdata'   => $appdata + ['folders' => clAppdataFolders($appdata)],
        'compose'   => clForeignCompose($docker),
        'vms'       => clVms(),
        'trash'     => clTrashRuns($appdata),
    ];
    clSaveCache($cache);
    $GLOBALS['clRaw'] = $raw;

    $hired = $jobs && in_array('cleanup', staffHired(), true);
    if ($hired && time() - (int) ($cache['flash']['at'] ?? 0) > CL_FLASH_TTL) {
        clJobAdd('flash', 'flash', [['grep', '-roI', '--exclude-dir=' . CL_TRASH, '--exclude-dir=' . CL_LEGACY,
                                     '-e', '/mnt/[^"<>[:space:]]*', CL_FLASH]], 300, true);
    }
    $state = clBuild();
    if ($hired) {
        // candidates are kept measured (their newest change decides); used folders only when asked
        foreach ($state['appdata']['list'] as $f) {
            if ($f['category'] !== 'used') {
                foreach ($f['parts'] as $p) {
                    clMeasureQueue($p['path'], CL_MEASURE_TTL);
                }
            }
        }
        foreach ($state['trash']['runs'] as $run) {
            if (!$run['purging']) {
                clMeasureQueue($run['path'], PHP_INT_MAX);
            }
        }
    }
    foreach ($raw['trash'] as $run) {
        if ($run['purging'] && ($GLOBALS['clPurgeTries'][$run['path']] ?? 0) < 3) {
            clJobAdd('purge:' . $run['path'], 'purge', [['rm', '-rf', '--', $run['path']]], 0, true);
        }
    }
    $state = clBuild();
    $state['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
    clWrite($state);
    return $state;
}

/** Pools and array disks: /mnt/<name> mounted with a real filesystem */
function clRoots(): array
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

function clShareCfg(string $share): array
{
    static $cache = [];
    return $cache[$share] ??= readCfg("/boot/config/shares/$share.cfg");
}

/** May this path be looked at without waking a disk? */
function clSafe(string $path): bool
{
    if (!str_starts_with($path, '/mnt/')) {
        return true;
    }
    $ctx = $GLOBALS['clCtx'];
    if (!preg_match('#^/mnt/([^/]+)(?:/([^/]+))?#', $path, $m)) {
        return false;
    }
    if ($m[1] === 'user' || $m[1] === 'user0') {
        if (!isset($m[2])) {
            return false;
        }
        $cfg = clShareCfg($m[2]);
        $pool = $cfg['shareCachePool'] ?? '';
        return ($cfg['shareUseCache'] ?? 'no') === 'only' && isset($ctx['roots'][$pool]) && !($ctx['asleep'][$pool] ?? false);
    }
    return isset($ctx['roots'][$m[1]]) && !($ctx['asleep'][$m[1]] ?? false);
}

/** true / false, or null when looking would wake a disk */
function clExists(string $path): ?bool
{
    return clSafe($path) ? file_exists($path) : null;
}

/** Wakes the named array disks (one block read from each, in parallel) */
function clWake(array $names): void
{
    $commands = [];
    foreach (readCfg('/var/local/emhttp/disks.ini', true) as $section => $d) {
        $name = (string) ($d['name'] ?? $section);
        $dev = $d['device'] ?? '';
        if (in_array($name, $names, true) && preg_match('/^[a-z0-9]+$/', $dev) && file_exists("/dev/$dev")) {
            $commands[$name] = ['dd', "if=/dev/$dev", 'of=/dev/null', 'bs=4096', 'count=1', 'iflag=direct'];
        }
    }
    if ($commands) {
        runAll($commands, 90);
        logLine('Dustdevil woke ' . implode(', ', array_keys($commands)) . ' to look at appdata');
    }
}

// --------------------------------------------------------------------- docker

function clDocker(): array
{
    $out = ['ok' => false, 'compose' => false, 'containers' => [], 'images' => [], 'repos' => [], 'volumes' => []];
    $docker = bin('docker');
    if (!$docker || !file_exists('/var/run/docker.sock')) {
        return $out;
    }
    [$exit, $ids] = run([$docker, 'ps', '-aq', '--no-trunc'], 30);
    if ($exit !== 0) {
        return $out;
    }
    $ids = array_values(array_filter(array_map('trim', explode("\n", $ids))));
    $data = [];
    if ($ids) {
        [$exit, $json] = run(array_merge([$docker, 'inspect'], $ids), 60);
        $data = $exit === 0 ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return $out;
        }
    }
    foreach ($data as $c) {
        $name = ltrim((string) ($c['Name'] ?? ''), '/');
        $labels = (array) ($c['Config']['Labels'] ?? []);
        $binds = [];
        foreach ((array) ($c['Mounts'] ?? []) as $m) {
            if (($m['Type'] ?? '') === 'bind' && !empty($m['Source'])) {
                $binds[] = (string) $m['Source'];
            }
        }
        $out['containers'][$name] = [
            'name'      => $name,
            'state'     => (string) ($c['State']['Status'] ?? 'unknown'),
            'image'     => (string) ($c['Config']['Image'] ?? ''),
            'project'   => $labels['com.docker.compose.project'] ?? null,
            'files'     => array_values(array_filter(explode(',', (string) ($labels['com.docker.compose.project.config_files'] ?? '')))),
            'dockerman' => ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman',
            'binds'     => $binds,
        ];
    }

    [$exit, $list] = run([$docker, 'image', 'ls', '--no-trunc', '--format', "{{.ID}}\t{{.Repository}}\t{{.Tag}}"], 30);
    $byId = [];
    foreach ($exit === 0 ? rows($list) : [] as $f) {
        if (count($f) >= 3 && $f[1] !== '<none>' && $f[2] !== '<none>') {
            $out['images'][clNormImage("$f[1]:$f[2]")] = ['ref' => "$f[1]:$f[2]", 'id' => $f[0], 'bytes' => null, 'used_by' => []];
            $byId[$f[0]] = true;
        }
    }
    if ($byId) {
        [$exit, $sizes] = run(array_merge([$docker, 'image', 'inspect', '--format', "{{.Id}}\t{{.Size}}"], array_keys($byId)), 30);
        $size = [];
        foreach ($exit === 0 ? rows($sizes) : [] as $f) {
            if (count($f) >= 2) {
                $size[$f[0]] = (int) $f[1];
            }
        }
        foreach ($out['images'] as &$img) {
            $img['bytes'] = $size[$img['id']] ?? null;
        }
        unset($img);
    }
    // a container's image counts as there, even when its tag moved on
    foreach ($out['containers'] as $c) {
        $ref = clNormImage($c['image']);
        $out['images'][$ref] ??= ['ref' => $c['image'], 'id' => null, 'bytes' => null, 'used_by' => []];
        $out['images'][$ref]['used_by'][] = $c['name'];
    }
    foreach (array_keys($out['images']) as $ref) {
        $out['repos'][clRepo($ref)] = true;
    }

    [$exit, $vols] = run([$docker, 'volume', 'ls', '--format', "{{.Name}}\t{{.Label \"com.docker.compose.project\"}}"], 30);
    foreach ($exit === 0 ? rows($vols) : [] as $f) {
        $out['volumes'][$f[0]] = $f[1] ?? '';
    }
    $out['compose'] = run([$docker, 'compose', 'version'], 15)[0] === 0;
    $out['ok'] = true;
    return $out;
}

/** nginx, library/nginx, docker.io/nginx:latest … all the same image */
function clNormImage(string $image): string
{
    $i = strtolower(preg_replace('/@sha256:.*$/', '', trim($image)));
    $i = preg_replace(['#^(docker\.io|index\.docker\.io)/#', '#^library/#'], '', $i);
    $slash = strrpos($i, '/');
    return str_contains($slash === false ? $i : substr($i, $slash + 1), ':') ? $i : "$i:latest";
}

function clRepo(string $ref): string
{
    return preg_replace('#:[^:/]*$#', '', $ref);
}

/** yes / other_tag / no */
function clImageLocal(string $image, array $docker): string
{
    if ($image === '') {
        return 'no';
    }
    $ref = clNormImage($image);
    return isset($docker['images'][$ref]) ? 'yes' : (isset($docker['repos'][clRepo($ref)]) ? 'other_tag' : 'no');
}

/** Paths under /mnt in a text, without ":/container" or ":ro" behind them */
function clMntPaths(string $text): array
{
    preg_match_all('#/mnt/[^\s"\'<>,;|`]+#', $text, $m);
    $out = [];
    foreach ($m[0] as $p) {
        $p = rtrim(preg_replace('/:.*$/', '', $p), '/)]}');
        if ($p !== '') {
            $out[$p] = true;
        }
    }
    return array_keys($out);
}

// --------------------------------------------------------------------- templates

function clTemplates(array $docker): array
{
    $files = glob(CL_TEMPLATES . '/my-*.xml') ?: [];
    sort($files);
    $parsed = [];
    $best = [];
    foreach ($files as $f) {
        $xml = (string) @file_get_contents($f, false, null, 0, 1 << 20);
        $field = function (string $tag) use ($xml): string {
            return preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)) : '';
        };
        preg_match_all('#<Config\b[^>]*\bType="Path"[^>]*>([^<]*)</Config>#', $xml, $pm);
        $name = $field('Name');
        $parsed[$f] = [
            'name'      => $name,
            'image'     => $field('Repository'),
            'network'   => $field('Network'),
            'webui'     => $field('WebUI'),
            'support'   => $field('Support'),
            'installed' => (int) $field('DateInstalled') ?: null,
            'mtime'     => (int) @filemtime($f),
            'bytes'     => (int) @filesize($f),
            'counts'    => ['paths' => substr_count($xml, 'Type="Path"'), 'ports' => substr_count($xml, 'Type="Port"'),
                            'vars' => substr_count($xml, 'Type="Variable"')],
            'paths'     => array_values(array_unique(array_filter(array_map(fn ($p) => trim(html_entity_decode($p, ENT_QUOTES | ENT_XML1)), $pm[1]),
                                                                  fn ($p) => str_starts_with($p, '/')))),
            'mnt'       => clMntPaths(html_entity_decode($xml, ENT_QUOTES | ENT_XML1)),
        ];
        if ($name !== '' && (!isset($best[$name]) || $parsed[$f]['mtime'] > $parsed[$best[$name]]['mtime'])) {
            $best[$name] = $f;
        }
    }

    $out = [];
    foreach ($parsed as $f => $t) {
        $c = $t['name'] !== '' ? ($docker['containers'][$t['name']] ?? null) : null;
        $local = clImageLocal($t['image'], $docker);
        if (!$docker['ok']) {
            $category = 'unknown';
        } elseif ($t['name'] === '') {
            $category = 'noname';
        } elseif ($best[$t['name']] !== $f) {
            $category = 'duplicate';
        } elseif ($c) {
            $category = 'in_use';
        } else {
            $category = $local !== 'no' ? 'unused' : 'leftover';
        }
        $img = $docker['images'][clNormImage($t['image'])] ?? null;
        $out[] = [
            'id'        => 'template:' . basename($f),
            'file'      => basename($f),
            'path'      => $f,
            'category'  => $category,
            'container' => $c ? ['name' => $c['name'], 'state' => $c['state'],
                                 'origin' => $c['project'] ? 'compose' : ($c['dockerman'] ? 'dockerman' : 'other'), 'project' => $c['project']] : null,
            'image_local' => $local,
            'image_bytes' => $img['bytes'] ?? null,
            'image_used_by' => $img['used_by'] ?? [],
            'newer'     => $category === 'duplicate' ? basename($best[$t['name']]) : null,
            'paths'     => array_map(fn ($p) => ['path' => $p, 'exists' => clExists($p)], $t['paths']),
        ] + $t;
    }
    return $out;
}

// --------------------------------------------------------------------- compose stacks

function clComposeRoot(): string
{
    $v = trim((string) (readCfg(CL_COMPOSE_CFG)['PROJECTS_FOLDER'] ?? ''));
    return rtrim($v !== '' ? $v : CL_COMPOSE_DEF, '/');
}

/** The same as compose_manager_sanitize_project_name() in Compose Manager (Plus) */
function clProjectName(string $s): string
{
    $s = strtolower(trim(str_replace("\n", ' ', $s)));
    $s = preg_replace(['/[^a-z0-9_-]/', '/_+/', '/-+/', '/^[_-]+/', '/[_-]+$/'], ['_', '_', '-', '', ''], $s);
    return $s !== '' ? $s : 'compose';
}

function clMeta(string $dir, string $file): string
{
    return trim((string) @file_get_contents("$dir/$file", false, null, 0, 65536));
}

function clEnvVars(?string $file): array
{
    $vars = [];
    foreach ($file ? (@file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) {
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $vars[$m[1]] = $v;
        }
    }
    return $vars;
}

/** ${VAR}, ${VAR:-default}, ${VAR-default} — only for stacks "docker compose config" can't read */
function clResolveVars(string $s, array $vars): string
{
    return preg_replace_callback('/\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?-)([^}]*))?\}/', function (array $m) use ($vars): string {
        $v = $vars[$m[1]] ?? null;
        $op = $m[2] ?? '';
        if (($op === ':-' && ($v === null || $v === '')) || ($op === '-' && $v === null)) {
            return $m[3] ?? '';
        }
        return (string) $v;
    }, $s);
}

function clStacks(array $docker, array &$cache): array
{
    $root = clComposeRoot();
    $out = ['root' => $root, 'exists' => is_dir($root), 'list' => []];
    if (!$out['exists']) {
        return $out;
    }
    $stacks = [];
    $resolve = [];
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $d) {
        $folder = basename($d);
        if ($folder[0] === '.' || $folder === CL_TRASH || $folder === CL_LEGACY || str_starts_with($folder, '_quarantaene-')) {
            continue;
        }
        $display = clMeta($d, 'name') ?: $folder;
        $project = clMeta($d, 'project_name') ?: clProjectName($folder);
        $alts = array_values(array_unique(array_diff([clProjectName($folder), clProjectName(clMeta($d, 'name') ?: $folder)], [$project])));

        $indirect = clMeta($d, 'indirect');
        $src = $d;
        $file = null;
        $reachable = true;
        if ($indirect !== '') {
            if (!clSafe($indirect)) {
                $reachable = false;
            } elseif (clMeta($d, 'indirect_mode') === 'file' || is_file($indirect)) {
                $src = dirname($indirect);
                $file = is_file($indirect) ? $indirect : null;
            } else {
                $src = $indirect;
            }
        }
        if ($reachable && $file === null) {
            foreach (CL_COMPOSE_FILES as $n) {
                if (is_file("$src/$n")) {
                    $file = "$src/$n";
                    break;
                }
            }
        }
        $env = null;
        $envPath = clMeta($d, 'envpath');
        if ($envPath !== '') {
            $envPath = str_starts_with($envPath, '/') ? $envPath : "$d/$envPath";
            $env = clSafe($envPath) && is_file($envPath) ? $envPath : null;
        }
        if ($env === null && $reachable && is_file("$src/.env")) {
            $env = "$src/.env";
        }
        $override = null;
        foreach ($reachable ? CL_OVERRIDE_FILES : [] as $n) {
            if ($src !== $d && is_file("$src/$n")) {
                $override = "$src/$n";
                break;
            }
            if (is_file("$d/$n")) {
                $override = "$d/$n";
                break;
            }
        }

        $members = [];
        foreach ($docker['containers'] as $c) {
            if ($c['project'] !== null && ($c['project'] === $project || in_array($c['project'], $alts, true))) {
                $members[] = ['name' => $c['name'], 'state' => $c['state']];
            }
        }
        $sig = md5(json_encode(array_map(fn ($f) => $f ? [$f, @filemtime($f), @filesize($f)] : null, [$file, $override, $env])));
        $stacks[$folder] = [
            'id'        => "stack:$folder",
            'folder'    => $folder,
            'dir'       => $d,
            'name'      => $display,
            'project'   => $project,
            'alts'      => $alts,
            'indirect'  => $indirect !== '' ? $indirect : null,
            'reachable' => $reachable,
            'src'       => $src,
            'file'      => $file,
            'override'  => $override,
            'env'       => $env,
            'autostart' => clMeta($d, 'autostart') === 'true',
            'containers' => $members,
            'bytes'     => clDirBytes($d),
            'mtime'     => $file ? (int) @filemtime($file) : (int) @filemtime($d),
            'sig'       => $sig,
        ];
        if ($file && $docker['compose'] && ($cache['compose'][$folder]['sig'] ?? null) !== $sig) {
            $resolve[$folder] = array_merge(['docker'], clComposeArgs($stacks[$folder]), ['config']);
        }
    }
    // "docker compose config" resolves paths and images the way compose itself does (with the .env)
    foreach ($resolve ? runAll($resolve, 20) : [] as $folder => [$exit, $text]) {
        $cache['compose'][$folder] = $exit === 0 && trim($text) !== ''
            ? ['sig' => $stacks[$folder]['sig'], 'ok' => true, 'paths' => clMntPaths($text), 'images' => clImageLines($text)]
            : ['sig' => $stacks[$folder]['sig'], 'ok' => false, 'paths' => [], 'images' => []];
    }
    $cache['compose'] = array_intersect_key($cache['compose'] ?? [], $stacks);

    foreach ($stacks as $folder => $s) {
        $resolved = ($cache['compose'][$folder]['ok'] ?? false) && ($cache['compose'][$folder]['sig'] ?? '') === $s['sig'];
        if ($resolved) {
            $paths = $cache['compose'][$folder]['paths'];
            $images = $cache['compose'][$folder]['images'];
        } else {
            $vars = clEnvVars($s['env']);
            $text = '';
            foreach ([$s['file'], $s['override']] as $f) {
                $text .= $f ? (string) @file_get_contents($f, false, null, 0, 1 << 20) . "\n" : '';
            }
            if (!$s['file'] && $s['reachable']) {
                foreach (glob($s['dir'] . '/*') ?: [] as $f) {
                    $text .= is_file($f) ? (string) @file_get_contents($f, false, null, 0, 65536) . "\n" : '';
                }
            }
            $text = clResolveVars(preg_replace('/(^|\s)#.*$/m', '$1', $text), $vars);     // comments are no use
            $paths = clMntPaths($text . "\n" . implode("\n", $vars));
            $images = clImageLines($text);
        }
        $volumes = [];
        foreach ($docker['volumes'] as $v => $proj) {
            if ($proj !== '' && ($proj === $s['project'] || in_array($proj, $s['alts'], true))) {
                $volumes[] = $v;
            }
        }
        $imgs = array_map(fn ($i) => ['ref' => $i, 'local' => clImageLocal($i, $docker),
                                      'bytes' => $docker['images'][clNormImage($i)]['bytes'] ?? null], $images);
        $local = count(array_filter($imgs, fn ($i) => $i['local'] !== 'no'));
        if (!$docker['ok'] || !$s['reachable']) {
            $category = 'unknown';
        } elseif (!$s['file']) {
            $category = 'broken';
        } elseif ($s['containers']) {
            $category = 'in_use';
        } elseif ($local || $volumes) {
            $category = 'unused';
        } else {
            $category = 'leftover';
        }
        $out['list'][] = $s + [
            'category' => $category,
            'resolved' => $resolved,
            'paths'    => $paths,
            'images'   => $imgs,
            'volumes'  => $volumes,
        ];
    }
    return $out;
}

function clImageLines(string $text): array
{
    preg_match_all('/^\s*image:\s*["\']?([^"\'\s#]+)/m', $text, $m);
    return array_values(array_unique($m[1]));
}

/** The arguments Compose Manager uses for a stack */
function clComposeArgs(array $s): array
{
    $args = ['compose', '--project-directory', $s['src'], '-f', $s['file']];
    if ($s['override']) {
        array_push($args, '-f', $s['override']);
    }
    if ($s['env']) {
        array_push($args, '--env-file', $s['env']);
    }
    array_push($args, '-p', $s['project']);
    return $args;
}

/** Size of a small folder (a stack's), counted in PHP */
function clDirBytes(string $dir): int
{
    $bytes = 0;
    $n = 0;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $bytes += $f->getSize();
            }
            if (++$n > 5000) {
                break;
            }
        }
    } catch (Throwable) {
        // unreadable: as far as we got
    }
    return $bytes;
}

/** Compose projects started from files outside Compose Manager: what they mention */
function clForeignCompose(array $docker): array
{
    $root = clComposeRoot();
    $found = [];
    foreach ($docker['containers'] as $c) {
        foreach ($c['files'] as $f) {
            if ($c['project'] === null || under($f, $root) || isset($found[$c['project']][$f]) || !clSafe($f) || !is_file($f)) {
                continue;
            }
            $text = (string) @file_get_contents($f, false, null, 0, 1 << 20);
            foreach (array_merge([dirname($f) . '/.env'], glob(dirname($f) . '/*.env') ?: []) as $e) {
                $text .= is_file($e) ? "\n" . (string) @file_get_contents($e, false, null, 0, 65536) : '';
            }
            $found[$c['project']][$f] = array_merge(clMntPaths($text), [dirname($f)]);
        }
    }
    return $found;
}

function clVms(): array
{
    $vms = [];
    foreach (glob(CL_LIBVIRT . '/*.xml') ?: [] as $f) {
        $vms[basename($f, '.xml')] = clMntPaths((string) @file_get_contents($f, false, null, 0, 1 << 20));
    }
    return $vms;
}

// --------------------------------------------------------------------- appdata

/** Where the appdata share lives: one path per pool or awake array disk that holds it */
function clAppdataPlaces(array $roots, array $asleep): array
{
    $path = readCfg('/boot/config/docker.cfg')['DOCKER_APP_CONFIG_PATH'] ?? '/mnt/user/appdata/';
    $share = preg_match('#^/mnt/[^/]+/([^/]+)#', $path, $m) ? $m[1] : 'appdata';
    $array = (clShareCfg($share)['shareUseCache'] ?? 'no') !== 'only';
    $places = [];
    $sleeping = [];
    foreach ($roots as $name => $r) {
        if ($r['kind'] === 'disk' && !$array) {
            continue;
        }
        if ($asleep[$name] ?? false) {
            $sleeping[] = $name;
            continue;
        }
        if (is_dir("/mnt/$name/$share")) {
            $places[$name] = ['root' => $name, 'path' => "/mnt/$name/$share", 'fs' => $r['fs']];
        }
    }
    return ['share' => $share, 'setting' => rtrim($path, '/'), 'places' => $places, 'asleep' => $sleeping];
}

function clAppdataFolders(array $appdata): array
{
    $mounts = [];
    foreach (mountTable() as $m) {
        $mounts[$m['mount']] = $m['source'];
    }
    $folders = [];
    $files = 0;
    foreach ($appdata['places'] as $root => $place) {
        foreach (@scandir($place['path']) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || $name === CL_TRASH || $name === CL_LEGACY || str_starts_with($name, '_quarantaene-')) {
                continue;
            }
            $full = $place['path'] . "/$name";
            if (is_link($full) || !is_dir($full)) {
                $files++;
                continue;
            }
            if (count($folders) >= CL_FOLDER_LIMIT && !isset($folders[$name])) {
                continue;
            }
            $folders[$name]['name'] = $name;
            $folders[$name]['parts'][] = [
                'root'    => $root,
                'path'    => $full,
                'dataset' => isset($mounts[$full]) ? $mounts[$full] : null,     // its own filesystem: rename can't move it
                'mtime'   => (int) @filemtime($full),
            ];
        }
    }
    uksort($folders, 'strnatcasecmp');
    return ['list' => array_values($folders), 'files' => $files];
}

// ===================================================================== build

/** Everything the page shows, from the last tour plus what the background work found */
function clBuild(): array
{
    $raw = $GLOBALS['clRaw'] ?? null;
    if (!$raw) {
        return $GLOBALS['clState'] ?? [];
    }
    $cache = clCache();
    $jobs = $GLOBALS['clJobs'];
    $pending = fn (string $key) => isset($jobs['running'][$key]) || isset($jobs['queue'][$key]);
    $docker = $raw['docker'];
    $ad = $raw['appdata'];
    $share = $ad['share'];
    $roots = $GLOBALS['clCtx']['roots'];

    // who names which top folder of the appdata share
    $refs = [];
    $mounters = [];
    $add = function (string $path, string $kind, string $name, bool $weak = false) use (&$refs, &$mounters, $share, $roots): void {
        if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/([^/]+))?#', rtrim($path, '/'), $m)
            || !($m[1] === 'user' || $m[1] === 'user0' || isset($roots[$m[1]])) || $m[2] !== $share) {
            return;
        }
        $top = $m[3] ?? '';
        if ($top === '') {
            if ($kind === 'container') {
                $mounters[$name] = true;               // the whole share (Krusader, backup tools): says nothing about one folder
            }
            return;
        }
        if ($top === CL_TRASH || $top === CL_LEGACY || str_starts_with($top, '_quarantaene-')) {
            return;
        }
        $refs[$top]["$kind:$name"] ??= ['kind' => $kind, 'name' => $name, 'weak' => $weak];
        $refs[$top]["$kind:$name"]['weak'] = $refs[$top]["$kind:$name"]['weak'] && $weak;
    };
    foreach ($docker['containers'] as $c) {
        foreach ($c['binds'] as $b) {
            $add($b, 'container', $c['name']);
        }
    }
    foreach ($raw['templates'] as $t) {
        foreach ($t['mnt'] as $p) {
            $add($p, 'template', $t['file']);
        }
    }
    $managed = [];
    foreach ($raw['stacks']['list'] as $s) {
        $managed[$s['project']] = true;
        foreach ($s['alts'] as $a) {
            $managed[$a] = true;
        }
        foreach (array_merge($s['paths'], array_filter([$s['src'], $s['file'], $s['env'], $s['indirect']])) as $p) {
            $add($p, 'stack', $s['folder']);
        }
    }
    $add($raw['stacks']['root'], 'stack', basename($raw['stacks']['root']));
    foreach ($raw['compose'] as $project => $files) {
        foreach (isset($managed[$project]) ? [] : $files as $paths) {
            foreach ($paths as $p) {
                $add($p, 'compose', $project);
            }
        }
    }
    foreach ($raw['vms'] as $vm => $paths) {
        foreach ($paths as $p) {
            $add($p, 'vm', $vm);
        }
    }
    $flash = $cache['flash'] ?? null;
    $composeRoot = $raw['stacks']['root'];
    foreach ($flash['lines'] ?? [] as [$file, $p]) {
        if (under($file, CL_TEMPLATES) || under($file, $composeRoot)) {
            continue;                                  // counted above, with their names
        }
        $rel = substr($file, strlen(CL_FLASH) + 1);
        $add($p, 'flash', $rel, (bool) preg_match(CL_WEAK, $rel));
    }
    $complete = $flash !== null && !$pending('flash') && $docker['ok'];

    // names that suggest a folder belongs to something, even when nothing mounts it
    $named = [];
    foreach ($docker['containers'] as $c) {
        $named[strtolower($c['name'])] ??= ['kind' => 'container', 'name' => $c['name']];
    }
    foreach ($raw['templates'] as $t) {
        if ($t['name'] !== '') {
            $named[strtolower($t['name'])] ??= ['kind' => 'template', 'name' => $t['file']];
        }
    }
    foreach ($raw['stacks']['list'] as $s) {
        $named[strtolower($s['folder'])] ??= ['kind' => 'stack', 'name' => $s['folder']];
        $named[strtolower($s['project'])] ??= ['kind' => 'stack', 'name' => $s['folder']];
    }

    $folders = [];
    $now = time();
    foreach ($ad['folders']['list'] as $f) {
        $by = array_values($refs[$f['name']] ?? []);
        $strong = array_values(array_filter($by, fn ($r) => !$r['weak']));
        $parts = [];
        $m = ['bytes' => 0, 'files' => 0, 'newest' => 0, 'top' => [], 'at' => PHP_INT_MAX, 'measured' => true, 'measuring' => false, 'partial' => false];
        foreach ($f['parts'] as $p) {
            $size = $cache['sizes'][$p['path']] ?? null;
            $busy = $pending('measure:' . $p['path']);
            $ok = $size && empty($size['error']);
            $m['measured'] = $m['measured'] && $ok;
            $m['measuring'] = $m['measuring'] || $busy;
            $m['partial'] = $m['partial'] || !empty($size['partial']);
            if ($ok) {
                $m['bytes'] += $size['bytes'];
                $m['files'] += $size['files'];
                $m['newest'] = max($m['newest'], $size['newest'] ?: $p['mtime']);
                $m['at'] = min($m['at'], $size['at']);
                foreach ($size['top'] as [$t, $rel]) {
                    $m['top'][] = [$t, count($f['parts']) > 1 ? $p['root'] . ": $rel" : $rel];
                }
            }
            $parts[] = $p + ['bytes' => $ok ? $size['bytes'] : null, 'backup' => backupProtection($p['path'])];
        }
        usort($m['top'], fn ($a, $b) => $b[0] <=> $a[0]);
        $m['top'] = array_slice($m['top'], 0, 5);

        $notes = [];
        $same = $strong ? null : ($named[strtolower($f['name'])] ?? null);
        if ($same) {
            $notes[] = ['why' => 'same_name'] + $same;
        }
        if ($by && !$strong) {
            $notes[] = ['why' => 'weak', 'names' => array_column($by, 'name')];
        }
        if (!$strong && $m['measured'] && $m['newest'] > $now - CL_FRESH_DAYS * 86400) {
            $notes[] = ['why' => 'fresh', 'days' => intdiv($now - $m['newest'], 86400)];
        }
        $category = !$docker['ok'] ? 'unknown' : ($strong ? 'used' : ($notes ? 'check' : 'unused'));

        $why = null;
        if ($category === 'unknown') {
            $why = 'docker_down';
        } elseif (array_filter($f['parts'], fn ($p) => $p['dataset'])) {
            $why = 'dataset';
        } elseif (!$complete) {
            $why = 'checking';
        } elseif ($m['measuring']) {
            $why = 'measuring';
        } elseif ($category !== 'used' && (!$m['measured'] || $m['at'] < $now - CL_MEASURE_TTL)) {
            $why = 'measure_first';             // its newest change decides whether it is only "check"
        }
        $folders[] = [
            'id'       => 'appdata:' . $f['name'],
            'name'     => $f['name'],
            'category' => $category,
            'notes'    => $notes,
            'used_by'  => array_slice($by, 0, 25),
            'used_more' => max(0, count($by) - 25),
            'parts'    => $parts,
            'bytes'    => $m['measured'] ? $m['bytes'] : null,
            'files'    => $m['measured'] ? $m['files'] : null,
            'newest'   => $m['measured'] ? $m['newest'] : null,
            'top'      => $m['top'],
            'measured_at' => $m['measured'] ? $m['at'] : null,
            'measuring' => $m['measuring'],
            'partial'  => $m['partial'],
            'why'      => $why,
            'force'    => $category === 'used',
        ];
    }

    $templates = array_map(function (array $t) use ($docker): array {
        unset($t['mnt']);
        $t['why'] = !$docker['ok'] ? 'docker_down' : null;
        $t['force'] = $t['category'] === 'in_use';
        return $t;
    }, $raw['templates']);

    $stacks = array_map(function (array $s) use ($docker): array {
        unset($s['sig']);
        $s['why'] = !$docker['ok'] ? 'docker_down' : (!$s['reachable'] ? 'asleep'
                  : ($s['containers'] && (!$s['file'] || !$docker['compose']) ? 'no_compose' : null));
        $s['force'] = $s['category'] === 'in_use';
        return $s;
    }, $raw['stacks']['list']);

    $runs = [];
    $trashBytes = 0;
    foreach ($raw['trash'] as $run) {
        $size = $cache['sizes'][$run['path']] ?? null;
        $run['bytes'] = is_file($run['path']) ? (int) @filesize($run['path']) : ($size && empty($size['error']) ? $size['bytes'] : null);
        $run['measuring'] = $pending('measure:' . $run['path']);
        $trashBytes = $trashBytes === null || ($run['bytes'] === null && !$run['purging']) ? null : $trashBytes + (int) $run['bytes'];
        $runs[] = $run;
    }
    usort($runs, fn ($a, $b) => $b['time'] <=> $a['time']);

    $backup = backupScriptState();
    $state = [
        'time'      => time(),
        'duration_ms' => $GLOBALS['clState']['duration_ms'] ?? 0,
        'host'      => hostname(),
        'docker'    => $docker['ok'],
        'compose'   => $docker['compose'],
        'containers' => count($docker['containers']),
        'images'    => count(array_filter($docker['images'], fn ($i) => $i['id'] !== null)),
        'backup_running' => $backup['running'],
        'templates' => ['dir' => CL_TEMPLATES, 'list' => $templates],
        'stacks'    => ['root' => $raw['stacks']['root'], 'exists' => $raw['stacks']['exists'], 'list' => $stacks],
        'appdata'   => [
            'share'    => $share,
            'setting'  => $ad['setting'],
            'places'   => array_values($ad['places']),
            'asleep'   => $ad['asleep'],
            'mounters' => array_keys($mounters),
            'files'    => $ad['folders']['files'],
            'complete' => $complete,
            'flash_at' => $flash['at'] ?? null,
            'list'     => $folders,
        ],
        'trash'     => ['runs' => $runs, 'bytes' => $trashBytes],
        'jobs'      => [
            'busy'      => count($jobs['running']) + count($jobs['queue']),
            'flash'     => $pending('flash'),
            'measuring' => count(array_filter(array_keys($jobs['running'] + $jobs['queue']), fn ($k) => str_starts_with($k, 'measure:'))),
            'purging'   => count(array_filter(array_keys($jobs['running'] + $jobs['queue']), fn ($k) => str_starts_with($k, 'purge:'))),
        ],
    ];
    $GLOBALS['clState'] = $state;
    return $state;
}

function clWrite(array $state): void
{
    try {
        writeAtomic(deskFile('cleanup'), jsonEncode($state));
    } catch (Throwable $e) {
        logLine('Dustdevil: ' . $e->getMessage());
    }
}

// ===================================================================== trash

/** The trash folders: the flash (templates, stacks there), next to a compose root elsewhere, and in appdata on each pool */
function clTrashRoots(array $appdata): array
{
    $roots = [CL_FLASH . '/' . CL_TRASH => 'flash'];
    $compose = clComposeRoot();
    if (!under($compose, '/boot')) {
        $roots[dirname($compose) . '/' . CL_TRASH] = 'compose';
    }
    foreach ($appdata['places'] as $p) {
        $roots[$p['path'] . '/' . CL_TRASH] = 'appdata';
    }
    return $roots;
}

/** The old script's trash: on the flash, next to the compose root, in a share's top folder on awake pools and disks */
function clLegacyRoots(): array
{
    $found = [CL_FLASH . '/' . CL_LEGACY => true];
    $compose = clComposeRoot();
    if (!under($compose, '/boot')) {
        $found[dirname($compose) . '/' . CL_LEGACY] = true;
    }
    $ctx = $GLOBALS['clCtx'];
    foreach ($ctx['roots'] as $name => $_) {
        if (!($ctx['asleep'][$name] ?? false)) {
            foreach (glob("/mnt/$name/*/" . CL_LEGACY, GLOB_ONLYDIR) ?: [] as $d) {
                $found[$d] = true;
            }
        }
    }
    return array_keys(array_filter($found, fn ($_, $d) => is_dir($d) && !is_link($d), ARRAY_FILTER_USE_BOTH));
}

function clStampTime(string $stamp, string $path): int
{
    $t = preg_match('/^(\d{8}-\d{6})/', $stamp, $m) ? DateTime::createFromFormat('Ymd-His', $m[1]) : false;
    return $t ? $t->getTimestamp() : (int) @filemtime($path);
}

function clTrashRuns(array $appdata): array
{
    $runs = [];
    foreach (clTrashRoots($appdata) as $root => $where) {
        if (!is_dir($root) || is_link($root)) {
            continue;
        }
        foreach (@scandir($root) ?: [] as $stamp) {
            $path = "$root/$stamp";
            if (!preg_match('/^\d{8}-\d{6}(-\d+)?(\.purging)?$/', $stamp) || !is_dir($path) || is_link($path)) {
                continue;
            }
            $purging = str_ends_with($stamp, '.purging');
            $manifest = readJson("$path/manifest.json") ?? [];
            $items = [];
            $known = [];
            foreach ((array) ($manifest['items'] ?? []) as $it) {
                if (!is_array($it) || !is_string($it['as'] ?? null) || !in_array($it['kind'] ?? '', ['template', 'stack', 'appdata'], true)) {
                    continue;
                }
                $known[$it['as']] = true;
                $items[] = [
                    'id'      => "$path|{$it['as']}",
                    'kind'    => $it['kind'],
                    'name'    => (string) ($it['name'] ?? basename($it['as'])),
                    'label'   => (string) ($it['label'] ?? ''),
                    'from'    => is_string($it['from'] ?? null) ? $it['from'] : null,
                    'as'      => $it['as'],
                    'present' => file_exists("$path/{$it['as']}"),
                    'volumes' => array_values(array_filter((array) ($it['volumes'] ?? []), 'is_string')),
                    'images'  => array_values(array_filter((array) ($it['images'] ?? []), 'is_string')),
                ];
            }
            // whatever is in there without a manifest entry (shown, can't go back)
            foreach (CL_KINDS as $kind) {
                foreach (@scandir("$path/$kind") ?: [] as $n) {
                    if ($n !== '.' && $n !== '..' && !isset($known["$kind/$n"])) {
                        $items[] = ['id' => "$path|$kind/$n", 'kind' => ['templates' => 'template', 'compose' => 'stack', 'appdata' => 'appdata'][$kind],
                                    'name' => $n, 'label' => '', 'from' => null, 'as' => "$kind/$n", 'present' => true, 'volumes' => [], 'images' => []];
                    }
                }
            }
            $runs[] = ['id' => $path, 'path' => $path, 'root' => $root, 'where' => $where, 'legacy' => false, 'purging' => $purging,
                       'stamp' => preg_replace('/\.purging$/', '', $stamp), 'time' => (int) ($manifest['time'] ?? clStampTime($stamp, $path)),
                       'items' => $items];
        }
    }
    foreach (clLegacyRoots() as $root) {
        foreach (@scandir($root) ?: [] as $stamp) {
            $path = "$root/$stamp";
            if ($stamp === '.' || $stamp === '..' || is_link($path)) {
                continue;
            }
            $purging = str_ends_with($stamp, '.purging');
            $items = [];
            if (is_dir($path) && !$purging) {
                foreach (@scandir($path) ?: [] as $n) {
                    if ($n === '.' || $n === '..') {
                        continue;
                    }
                    if (in_array($n, ['templates', 'compose'], true) && is_dir("$path/$n")) {
                        foreach (@scandir("$path/$n") ?: [] as $x) {
                            if ($x !== '.' && $x !== '..') {
                                $items[] = ['id' => "$path|$n/$x", 'kind' => $n === 'templates' ? 'template' : 'stack', 'name' => $x, 'label' => '',
                                            'from' => null, 'as' => "$n/$x", 'present' => true, 'volumes' => [], 'images' => []];
                            }
                        }
                    } else {
                        $items[] = ['id' => "$path|$n", 'kind' => 'appdata', 'name' => $n, 'label' => '', 'from' => null, 'as' => $n,
                                    'present' => true, 'volumes' => [], 'images' => []];
                    }
                }
            }
            $runs[] = ['id' => $path, 'path' => $path, 'root' => $root, 'where' => 'legacy', 'legacy' => true, 'purging' => $purging,
                       'stamp' => preg_replace('/\.purging$/', '', $stamp), 'time' => clStampTime($stamp, $path), 'items' => $items];
        }
    }
    return $runs;
}

/** A new run folder in a trash root (created on the same filesystem as what goes in) */
function clRunCreate(string $root): array
{
    if (!is_dir($root)) {
        if (!@mkdir($root, 0775)) {
            throw new Problem('cleanup_trash_failed', ['path' => $root]);
        }
        @chown($root, FILE_UID);
        @chgrp($root, FILE_GID);
    }
    $stamp = date('Ymd-His');
    for ($i = 2; file_exists("$root/$stamp") || file_exists("$root/$stamp.purging"); $i++) {
        $stamp = date('Ymd-His') . "-$i";
    }
    if (!@mkdir("$root/$stamp", 0775)) {
        throw new Problem('cleanup_trash_failed', ['path' => "$root/$stamp"]);
    }
    @chown("$root/$stamp", FILE_UID);
    @chgrp("$root/$stamp", FILE_GID);
    return ['root' => $root, 'path' => "$root/$stamp", 'stamp' => $stamp, 'time' => time(), 'items' => []];
}

function clManifestWrite(array $run): void
{
    writeAtomic($run['path'] . '/manifest.json', json_encode([
        'written_by' => 'Unraid Secretary Office (Ms. Dustdevil)',
        'note'       => 'Put back by renaming each "as" (relative to this folder) to "from".',
        'version'    => 1,
        'time'       => $run['time'],
        'host'       => hostname(),
        'items'      => $run['items'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** Removes a run folder that holds nothing any more (and its trash root, if empty) */
function clRunTidy(string $path, string $root): void
{
    foreach (CL_KINDS as $kind) {
        @rmdir("$path/$kind");
    }
    $left = array_diff(@scandir($path) ?: [], ['.', '..', 'manifest.json']);
    if (!$left) {
        @unlink("$path/manifest.json");
        @rmdir($path);
    }
    @rmdir($root);
}

// ===================================================================== actions

/** No changes while a backup runs (it may be reading what would move) */
function clGuard(array $state): void
{
    if ($state['backup_running'] ?? false) {
        throw new Problem('cleanup_backup_running');
    }
}

/** @return array<string, array> the state's entries by id */
function clIndex(array $state): array
{
    $all = [];
    foreach ($state['templates']['list'] as $t) {
        $all[$t['id']] = ['kind' => 'template'] + $t;
    }
    foreach ($state['stacks']['list'] as $s) {
        $all[$s['id']] = ['kind' => 'stack'] + $s;
    }
    foreach ($state['appdata']['list'] as $f) {
        $all[$f['id']] = ['kind' => 'appdata'] + $f;
    }
    return $all;
}

/** Puts templates, stacks or appdata folders into the trash */
function clPark(array $ids, bool $force): array
{
    $state = clScan(false, false);
    clGuard($state);
    $all = clIndex($state);
    $todo = [];
    foreach ($ids as $id) {
        $e = $all[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
        $p = ['name' => clLabel($e)];
        if ($e['why'] !== null) {
            throw match ($e['why']) {
                'docker_down'   => new Problem('cleanup_docker_down', $p),
                'asleep'        => new Problem('cleanup_asleep', $p),
                'no_compose'    => new Problem('cleanup_no_compose', $p),
                'dataset'       => new Problem('cleanup_dataset', $p),
                'checking'      => new Problem('cleanup_checking', $p),
                'measuring'     => new Problem('cleanup_measuring', $p),
                default         => new Problem('cleanup_measure_first', $p),
            };
        }
        if ($e['force'] && !$force) {
            throw new Problem('cleanup_in_use', $p);
        }
        $todo[] = $e;
    }

    $runs = [];
    $results = [];
    $run = function (string $root) use (&$runs): string {
        $runs[$root] ??= clRunCreate($root);
        return $root;
    };
    foreach ($todo as $e) {
        try {
            if ($e['kind'] === 'template') {
                $r = $run(CL_FLASH . '/' . CL_TRASH);
                $as = 'templates/' . $e['file'];
                clMove($e['path'], $runs[$r]['path'] . "/$as");
                $runs[$r]['items'][] = ['kind' => 'template', 'name' => $e['file'], 'label' => $e['name'], 'from' => $e['path'], 'as' => $as,
                                        'image' => $e['image']];
            } elseif ($e['kind'] === 'stack') {
                if ($e['containers']) {
                    [$exit, , $err] = run(array_merge(['docker'], clComposeArgs($e), ['down']), 180);
                    if ($exit !== 0) {
                        throw new Problem('cleanup_down_failed', ['name' => $e['folder'], 'detail' => trim(substr($err, -300))]);
                    }
                }
                $root = $state['stacks']['root'];
                $r = $run(under($root, '/boot') ? CL_FLASH . '/' . CL_TRASH : dirname($root) . '/' . CL_TRASH);
                $as = 'compose/' . $e['folder'];
                clMove($e['dir'], $runs[$r]['path'] . "/$as");
                $runs[$r]['items'][] = ['kind' => 'stack', 'name' => $e['folder'], 'label' => $e['name'], 'from' => $e['dir'], 'as' => $as,
                                        'project' => $e['project'], 'indirect' => $e['indirect'], 'was_running' => count($e['containers']),
                                        'volumes' => $e['volumes'], 'images' => array_column($e['images'], 'ref')];
            } else {
                foreach ($e['parts'] as $p) {
                    $r = $run(dirname($p['path']) . '/' . CL_TRASH);
                    $as = 'appdata/' . $e['name'];
                    clMove($p['path'], $runs[$r]['path'] . "/$as");
                    $runs[$r]['items'][] = ['kind' => 'appdata', 'name' => $e['name'], 'label' => $p['root'], 'from' => $p['path'], 'as' => $as,
                                            'bytes' => $p['bytes']];
                    clManifestWrite($runs[$r]);
                }
            }
            foreach ($runs as $x) {
                if ($x['items']) {
                    clManifestWrite($x);
                }
            }
            $results[] = ['id' => $e['id'], 'ok' => true];
            logLine("Dustdevil put away {$e['id']}" . ($e['force'] ? ' (was still in use)' : ''));
        } catch (Problem $p) {
            $results[] = ['id' => $e['id'], 'ok' => false, 'error' => $p->toArray()];
            logLine("Dustdevil could not put away {$e['id']}: " . $p->getMessage());
        }
    }
    foreach ($runs as $x) {
        if (!$x['items']) {
            clRunTidy($x['path'], $x['root']);
        }
    }
    return ['ok' => true, 'results' => $results, 'state' => clScan()];
}

/** What the user calls it: the template's file, the stack's folder, the folder */
function clLabel(array $e): string
{
    return match ($e['kind']) {
        'template' => $e['file'],
        'stack'    => $e['folder'],
        default    => $e['name'],
    };
}

/** Rename only — the trash is on the same filesystem; anything else is refused, never copied */
function clMove(string $from, string $to): void
{
    if (file_exists($to)) {
        throw new Problem('cleanup_target_exists', ['path' => $to]);
    }
    $dir = dirname($to);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new Problem('cleanup_trash_failed', ['path' => $dir]);
    }
    if (!@rename($from, $to)) {
        @rmdir($dir);
        $err = error_get_last()['message'] ?? '';
        throw new Problem('cleanup_move_failed', ['path' => $from, 'detail' => preg_replace('/^rename\([^)]*\):\s*/', '', $err)]);
    }
}

/** Puts things from the trash back where they came from */
function clRestore(array $ids): array
{
    $state = clScan(false, false);
    clGuard($state);
    $items = [];
    foreach ($state['trash']['runs'] as $run) {
        foreach ($run['items'] as $it) {
            $items[$it['id']] = ['run' => $run, 'item' => $it];
        }
    }
    $results = [];
    foreach ($ids as $id) {
        $x = $items[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
        $run = $x['run'];
        $it = $x['item'];
        if ($run['legacy'] || $run['purging'] || !$it['from'] || !$it['present']) {
            throw new Problem('cleanup_no_way_back', ['name' => $it['name']]);
        }
        // only to where such a thing belongs — a manifest is a file anybody with root could edit
        $home = match ($it['kind']) {
            'template' => CL_TEMPLATES,
            'stack'    => $state['stacks']['root'],
            'appdata'  => dirname($run['root']),
        };
        if (dirname($it['from']) !== $home || basename($it['from']) !== basename($it['as'])) {
            throw new Problem('cleanup_no_way_back', ['name' => $it['name']]);
        }
        try {
            if (file_exists($it['from'])) {
                throw new Problem('cleanup_target_exists', ['path' => $it['from']]);
            }
            if (!is_dir($home)) {
                throw new Problem('cleanup_no_home', ['path' => $home]);
            }
            if (!@rename($run['path'] . '/' . $it['as'], $it['from'])) {
                throw new Problem('cleanup_move_failed', ['path' => $it['from'], 'detail' => error_get_last()['message'] ?? '']);
            }
            $manifest = readJson($run['path'] . '/manifest.json') ?? [];
            $left = array_values(array_filter((array) ($manifest['items'] ?? []), fn ($m) => ($m['as'] ?? null) !== $it['as']));
            if ($left) {
                clManifestWrite(['path' => $run['path'], 'time' => (int) ($manifest['time'] ?? $run['time']), 'items' => $left]);
            } else {
                clRunTidy($run['path'], $run['root']);
            }
            clForgetSize($run['path']);
            $results[] = ['id' => $id, 'ok' => true, 'kind' => $it['kind']];
            logLine("Dustdevil put back {$it['from']}");
        } catch (Problem $p) {
            $results[] = ['id' => $id, 'ok' => false, 'error' => $p->toArray()];
        }
    }
    return ['ok' => true, 'results' => $results, 'state' => clScan()];
}

/** Empties trash runs for good (in the background); for stacks also their volumes and images, if asked */
function clPurge(array $ids, bool $volumes, bool $images): array
{
    $state = clScan(false, false);
    clGuard($state);
    $runs = [];
    foreach ($state['trash']['runs'] as $run) {
        $runs[$run['id']] = $run;
    }
    $todo = [];
    foreach ($ids as $id) {
        $run = $runs[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
        if (!$run['purging']) {
            $todo[] = $run;
        }
    }
    $results = [];
    $docker = bin('docker');
    $known = $GLOBALS['clRaw']['docker'] ?? ['volumes' => [], 'images' => []];
    foreach ($todo as $run) {
        $done = ['id' => $run['id'], 'ok' => true, 'volumes' => [], 'images' => []];
        if (!@rename($run['path'], $run['path'] . '.purging')) {
            $results[] = ['id' => $run['id'], 'ok' => false, 'error' => ['key' => 'cleanup_move_failed', 'params' => ['path' => $run['path'], 'detail' => '']]];
            continue;
        }
        clForgetSize($run['path']);
        clJobAdd('purge:' . $run['path'] . '.purging', 'purge', [['rm', '-rf', '--', $run['path'] . '.purging']], 0, true);
        logLine("Dustdevil empties {$run['path']}");
        // what the stacks in it left in Docker — docker refuses what a container still uses
        foreach ($run['items'] as $it) {
            if ($it['kind'] !== 'stack' || !$docker) {
                continue;
            }
            foreach ($volumes ? $it['volumes'] : [] as $v) {
                $done['volumes'][] = isset($known['volumes'][$v])
                    ? ['name' => $v, 'ok' => run([$docker, 'volume', 'rm', $v], 60)[0] === 0]
                    : ['name' => $v, 'ok' => true, 'absent' => true];
            }
            foreach ($images ? $it['images'] : [] as $i) {
                $done['images'][] = ($known['images'][clNormImage($i)]['id'] ?? null) !== null
                    ? ['name' => $i, 'ok' => run([$docker, 'image', 'rm', $i], 120)[0] === 0]
                    : ['name' => $i, 'ok' => true, 'absent' => true];
            }
        }
        $results[] = $done;
    }
    return ['ok' => true, 'results' => $results, 'state' => clScan()];
}

/** Measures folders (appdata) or trash runs again, when asked */
function clMeasure(array $ids): array
{
    $state = $GLOBALS['clState'] ?? clScan();
    $paths = [];
    foreach ($state['appdata']['list'] as $f) {
        if (in_array($f['id'], $ids, true)) {
            foreach ($f['parts'] as $p) {
                $paths[] = $p['path'];
            }
        }
    }
    foreach ($state['trash']['runs'] as $r) {
        if (in_array($r['id'], $ids, true) && !$r['purging']) {
            $paths[] = $r['path'];
        }
    }
    if (!$paths) {
        throw new Problem('unknown_target', ['target' => implode(', ', array_slice($ids, 0, 3))]);
    }
    foreach ($paths as $p) {
        clMeasureQueue($p, 0);
    }
    $state = clBuild();
    clWrite($state);
    return ['ok' => true, 'state' => $state];
}

/** A template's XML or a stack's compose files, to read (never the .env: it holds secrets) */
function clDetail(string $id): array
{
    $all = clIndex($GLOBALS['clState'] ?? clScan());
    $e = $all[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
    $files = match ($e['kind']) {
        'template' => [$e['path']],
        'stack'    => array_values(array_filter([$e['file'], $e['override']])),
        default    => [],
    };
    $out = [];
    foreach ($files as $f) {
        $text = (string) @file_get_contents($f, false, null, 0, CL_TEXT_MAX + 1);
        $out[] = ['path' => $f, 'text' => substr($text, 0, CL_TEXT_MAX), 'cut' => strlen($text) > CL_TEXT_MAX];
    }
    return ['ok' => true, 'files' => $out, 'env' => $e['kind'] === 'stack' ? $e['env'] : null];
}

// ===================================================================== checks

function clChecks(): array
{
    $s = $GLOBALS['clState'] ?? clScan();
    $out = [finding('docker', 'required', $s['docker'] ?? null, [], 'docker')];
    $old = array_filter($s['trash']['runs'] ?? [], fn ($r) => !$r['purging'] && $r['time'] < time() - CL_FRESH_DAYS * 86400);
    if ($old) {
        $oldest = min(array_column($old, 'time'));
        $bytes = array_sum(array_map(fn ($r) => (int) $r['bytes'], $old));
        $out[] = finding('trash_old', 'recommended', false,
            ['n' => count($old), 'days' => intdiv(time() - $oldest, 86400), 'size' => clHuman($bytes)], '#/cleanup');
    } else {
        $out[] = finding('trash_old', 'recommended', true, ['n' => 0, 'days' => 0, 'size' => ''], '#/cleanup');
    }
    return $out;
}

function clHuman(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    $x = (float) $bytes;
    while ($x >= 1024 && $i < count($units) - 1) {
        $x /= 1024;
        $i++;
    }
    return ($i ? number_format($x, 1, '.', '') : (string) $bytes) . ' ' . $units[$i];
}

// ===================================================================== background work

function clCacheFile(): string
{
    return DATA_DIR . '/cleanup/cache.json';
}

function clCache(): array
{
    return $GLOBALS['clCache'] ??= (readJson(clCacheFile()) ?? []) + ['flash' => null, 'sizes' => [], 'compose' => []];
}

function clSaveCache(array $cache): void
{
    $GLOBALS['clCache'] = $cache;
    try {
        if (!is_dir(DATA_DIR . '/cleanup')) {
            @mkdir(DATA_DIR . '/cleanup', 0700);
        }
        writeAtomic(clCacheFile(), jsonEncode($cache), 0600, 0, 0);
    } catch (Throwable $e) {
        logLine('Dustdevil: ' . $e->getMessage());
    }
}

function clForgetSize(string $path): void
{
    $cache = clCache();
    unset($cache['sizes'][$path]);
    clSaveCache($cache);
}

/** Queue measuring a path unless its last measurement is younger than $ttl */
function clMeasureQueue(string $path, int $ttl): void
{
    $size = clCache()['sizes'][$path] ?? null;
    if ($size && time() - (int) ($size['at'] ?? 0) < $ttl) {
        return;
    }
    if (!clSafe($path) || !is_dir($path)) {
        return;
    }
    // files, bytes and newest change; and the five newest files (by tab: time, size, path)
    $awk = 'BEGIN{FS="\t"} {c++; s+=$2; t=int($1); if (t>m) m=t;'
         . ' for (i=1; i<=5; i++) if (!(i in T) || t>T[i]) { for (j=5; j>i; j--) if ((j-1) in T) { T[j]=T[j-1]; P[j]=P[j-1] } T[i]=t; P[i]=$3; break } }'
         . ' END{printf "%d\t%.0f\t%d\n", c, s, m; for (i=1; i<=5; i++) if (i in T) printf "%d\t%s\n", T[i], P[i]}';
    $nice = bin('ionice') ? ['nice', '-n', '10', 'ionice', '-c', '3'] : ['nice', '-n', '10'];
    clJobAdd('measure:' . $path, 'measure', [
        array_merge($nice, ['find', $path, '-xdev', '-type', 'f', '-printf', "%T@\t%s\t%P\n"]),
        ['awk', $awk],
    ], 3600);
}

/** $first: purges and the flash search don't wait behind measurements */
function clJobAdd(string $key, string $type, array $pipeline, int $timeout, bool $first = false): void
{
    $jobs = &$GLOBALS['clJobs'];
    if (isset($jobs['running'][$key]) || isset($jobs['queue'][$key])) {
        return;
    }
    $job = ['key' => $key, 'type' => $type, 'pipeline' => $pipeline, 'timeout' => $timeout, 'first' => $first];
    $jobs['queue'] = $first ? [$key => $job] + $jobs['queue'] : $jobs['queue'] + [$key => $job];
}

function clJobsTick(): void
{
    $jobs = &$GLOBALS['clJobs'];
    if (!$jobs['running'] && !$jobs['queue']) {
        return;
    }
    $changed = false;
    foreach ($jobs['running'] as $key => $job) {
        $alive = false;
        foreach ($job['procs'] as $i => $p) {
            if ($job['exit'][$i] !== null) {
                continue;
            }
            $st = proc_get_status($p);
            if ($st['running']) {
                $alive = true;
            } else {
                $jobs['running'][$key]['exit'][$i] = $st['exitcode'];
            }
        }
        $killed = $alive && $job['timeout'] && time() - $job['since'] > $job['timeout'];
        if ($alive && !$killed) {
            continue;
        }
        foreach ($job['procs'] as $p) {
            if ($killed) {
                proc_terminate($p, 9);
            }
            proc_close($p);
        }
        clJobDone($jobs['running'][$key], $killed);
        @unlink($job['out']);
        unset($jobs['running'][$key]);
        $changed = true;
    }
    $limit = CL_PARALLEL;
    foreach ($jobs['queue'] as $key => $job) {
        $busy = count(array_filter($jobs['running'], fn ($j) => !$j['first']));
        if (!$job['first'] && $busy >= $limit) {
            continue;
        }
        unset($jobs['queue'][$key]);
        $started = clJobStart($job);
        if ($started) {
            $jobs['running'][$key] = $started;
        } elseif ($job['type'] === 'measure') {
            $cache = clCache();
            $cache['sizes'][substr($key, 8)] = ['at' => time(), 'error' => true];
            clSaveCache($cache);
        }
        $changed = true;
    }
    if ($changed && isset($GLOBALS['clRaw'])) {
        clWrite(clBuild());
    }
}

/** Starts a pipeline (no shell): each command's output goes into the next one, the last one's into a file in RAM */
function clJobStart(array $job): ?array
{
    @mkdir(RUN_DIR, 0700, true);
    $out = RUN_DIR . '/cleanup-' . md5($job['key']) . '.out';
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $procs = [];
    $in = ['file', '/dev/null', 'r'];
    $n = count($job['pipeline']);
    foreach ($job['pipeline'] as $i => $cmd) {
        $last = $i === $n - 1;
        $pipes = [];
        $p = @proc_open($cmd, [0 => $in, 1 => $last ? ['file', $out, 'w'] : ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', $env);
        if (is_resource($in)) {
            fclose($in);                         // the next command has it now
        }
        if (!is_resource($p)) {
            foreach ($procs as $q) {
                proc_terminate($q, 9);
                proc_close($q);
            }
            logLine("Dustdevil could not start {$job['key']}");
            return null;
        }
        $procs[] = $p;
        $in = $last ? null : $pipes[1];
    }
    return $job + ['procs' => $procs, 'exit' => array_fill(0, $n, null), 'out' => $out, 'since' => time()];
}

function clJobDone(array $job, bool $killed): void
{
    $cache = clCache();
    $key = $job['key'];
    $text = (string) @file_get_contents($job['out']);
    $took = time() - $job['since'];
    if ($job['type'] === 'flash') {
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $at = strpos($line, ':/mnt/');
            if ($at !== false) {
                $lines[] = [substr($line, 0, $at), rtrim(preg_replace('/:.*$/', '', substr($line, $at + 1)), '/\'),;')];
            }
        }
        // grep: 0 found, 1 nothing found, 2 some files unreadable — all fine as long as it wasn't stopped
        $cache['flash'] = ['at' => time(), 'ok' => !$killed, 'lines' => $lines];
        logLine(sprintf('Dustdevil searched the flash: %d mentions of /mnt in %d s%s', count($lines), $took, $killed ? ' (stopped, too slow)' : ''));
    } elseif ($job['type'] === 'measure') {
        $path = substr($key, 8);
        $rows = explode("\n", trim($text));
        $head = explode("\t", (string) array_shift($rows));
        if (count($head) >= 3 && ctype_digit($head[0])) {
            $top = [];
            foreach ($rows as $r) {
                $f = explode("\t", $r, 2);
                if (count($f) === 2 && ctype_digit($f[0])) {
                    $top[] = [(int) $f[0], $f[1]];
                }
            }
            $cache['sizes'][$path] = ['at' => time(), 'files' => (int) $head[0], 'bytes' => (int) $head[1], 'newest' => (int) $head[2],
                                      'top' => $top, 'partial' => $killed || ($job['exit'][0] ?? 0) !== 0, 'seconds' => $took];
        } else {
            $cache['sizes'][$path] = ['at' => time(), 'error' => true];
        }
    } elseif ($job['type'] === 'purge') {
        $path = substr($key, 6);
        if (file_exists($path)) {
            $GLOBALS['clPurgeTries'][$path] = ($GLOBALS['clPurgeTries'][$path] ?? 0) + 1;
            logLine("Dustdevil could not empty $path completely");
        } else {
            logLine("Dustdevil emptied $path ($took s)");
            @rmdir(dirname($path));                // the trash root, if that was the last run
        }
    }
    // forget measurements of what is gone
    foreach (array_keys($cache['sizes']) as $p) {
        if (!str_starts_with($p, '/mnt/') || clSafe($p)) {
            if (!file_exists($p)) {
                unset($cache['sizes'][$p]);
            }
        }
    }
    clSaveCache($cache);
}
