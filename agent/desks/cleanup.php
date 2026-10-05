<?php
declare(strict_types=1);

/*
 * Ms. Dustdevil — clears away what nobody uses any more.
 *
 * What she looks at, best tidied in this order:
 *   templates  my-*.xml in dockerMan's templates-user without a container;
 *              and stray my-*.xml elsewhere on the flash or the pools, which
 *              Unraid never reads (copies, older versions, or ones to take over)
 *   stacks     Compose Manager stacks without containers, or broken ones
 *   appdata    first-level folders of the appdata share that nothing names:
 *              container mounts, templates, stacks, compose files, VMs,
 *              anything on the flash (User Scripts, plugin settings …)
 *   vms        what deleted VMs left behind: folders in the domains share,
 *              NVRAM files, TPM states and snapshot lists without their VM,
 *              and disk images in the isos share that no VM uses
 *   scripts    User Scripts that are switched off and lie around: broken
 *              (no script file), pointing to paths that are gone, or not run
 *              since the reboot and unchanged for CL_FRESH_DAYS. Scheduled or
 *              running ones are never put away; the office's own never count
 *   docker     dangling and unused images, volumes without a container,
 *              the build cache — Docker can't rename these, so they can
 *              only be removed for good (images can be pulled again)
 *   icons      containers without a picture on Unraid's Docker page and
 *              Dashboard (none set, or one Unraid can't load): she finds a
 *              logo (Community Applications on this server, a table of
 *              well-known images, a checked guess — URLs of the open
 *              dashboard-icons collection, never bundled) and sets it in the
 *              container's template or its Compose Manager override file;
 *              the old file goes into the storeroom first
 *
 * Nothing is deleted right away. What the user clears away is renamed into a
 * trash folder (CL_TRASH, "the storeroom") on the same filesystem it lives
 * on — the flash for templates and stacks, the share on its pool for appdata,
 * domains, isos and stray templates, libvirt.img for NVRAM/TPM/snapshot lists
 * — so putting away and putting back is a rename, never a copy; a rename
 * that fails is refused. A folder that is a ZFS dataset of its own is renamed
 * with zfs rename next to the storeroom (CL_TRASH-<stamp>-<name>), snapshots
 * included. Every run gets a folder <stamp>/ with a manifest.json saying where
 * each thing came from; the trash on disk is the truth, there is no index that
 * could go stale. Emptying it is the only permanent step (in the background:
 * the run is renamed to <stamp>.purging first, so it is gone at once).
 * The old cleanup script's trash (CL_LEGACY) is shown and can be emptied, but
 * is never written to. VM definitions are never touched: a VM whose disks are
 * gone is pointed out, and removed on Unraid's VM page.
 *
 * Slow work runs in the background, polled from tick: searching the flash for
 * paths, looking for stray templates, measuring folders (file count, size on
 * disk, newest change), Docker's build cache. Sleeping disks are never touched
 * unless asked ("wake"); nothing is changed while a backup runs.
 */

const CL_TEMPLATES    = '/boot/config/plugins/dockerMan/templates-user';
const CL_COMPOSE_CFG  = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const CL_COMPOSE_DEF  = '/boot/config/plugins/compose.manager/projects';
const CL_FLASH        = '/boot/config';
const CL_LIBVIRT      = '/etc/libvirt';             // libvirt.img, mounted while the VM service runs
const CL_TRASH        = '_UnraidSecretaryOffice-trash';
const CL_LEGACY       = '_zumloeschen';            // trash of the old unraid-cleanup.sh
// folder in a trash run => kind of what is in it
const CL_KINDS        = ['templates' => 'template', 'compose' => 'stack', 'appdata' => 'appdata', 'vms' => 'domain', 'isos' => 'iso',
                         'nvram' => 'nvram', 'tpm' => 'tpm', 'snapshotdb' => 'snapshotdb', 'strays' => 'stray', 'userscripts' => 'userscript',
                         'icons' => 'icon'];
const CL_US_SCRIPTS   = US_DIR . '/scripts';
const CL_US_TMP       = '/tmp/user.scripts';         // running markers and last outputs (RAM: since the reboot)
const CL_STRAY_TTL    = 6 * 3600;                  // look for stray templates again after this (or when asked)
const CL_MEDIA        = '/\.(iso|img|qcow2|raw|vhdx?|vmdk|vdi|pat|dmg)$/i';   // what counts as a VM's disk image in the isos share
const CL_UUID         = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const CL_CACHE_TTL    = 1800;                      // ask Docker for its build cache again after this
const CL_CACHE_FORMAT = 2;                         // 2: sizes are blocks on disk (1 counted apparent sizes)
const CL_FRESH_DAYS   = 30;                        // a folder changed since then is only "check"
const CL_MEASURE_TTL  = 6 * 3600;                  // a candidate's measurement must be this fresh to be put away
const CL_FLASH_TTL    = 1800;                      // search the flash again after this
const CL_PARALLEL     = 2;                         // background jobs at once (purges don't wait)
const CL_FOLDER_LIMIT = 2000;
const CL_TEXT_MAX     = 256 * 1024;
const CL_COMPOSE_FILES  = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];
const CL_OVERRIDE_FILES = ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'];
// pictures: where Unraid's Docker page looks for them and keeps them (DockerClient.php, $dockerManPaths)
const CL_TEMPLATES_USB  = '/boot/config/plugins/dockerMan/templates';        // Unraid reads these too, after templates-user
const CL_DOCROOT        = '/usr/local/emhttp';
const CL_DM_JSON        = CL_DOCROOT . '/state/plugins/dynamix.docker.manager/docker.json';   // per container the picture it shows
const CL_DM_RAM         = CL_DOCROOT . '/state/plugins/dynamix.docker.manager/images';        // <name>-icon.png, in RAM
const CL_DM_DISK        = '/var/lib/docker/unraid/images';                                    // the copies that last
const CL_DM_WEB         = '/state/plugins/dynamix.docker.manager/images';
const CL_DM_FALLBACK    = CL_DOCROOT . '/plugins/dynamix.docker.manager/images/question.png';
const CL_DM_FALLBACK_WEB = '/plugins/dynamix.docker.manager/images/question.png';
const CL_LOOP_VERSIONS  = ['7.3.2'];         // their Docker page and Dashboard reload a missing question.png forever
const CL_STANDINS       = [CL_DOCROOT . '/plugins/compose.manager/images/question.png', CL_DOCROOT . '/plugins/dynamix/images/default.png'];
const CL_CA_TEMPLATES   = '/tmp/community.applications/tempFiles/templates_new.json';         // Community Applications' feed (RAM)
const CL_CA_PATTERN     = 's:4:"Name";|s:10:"Repository";s:[0-9]+:"[^"]*"|s:4:"Icon";s:[0-9]+:"[^"]*"|"(Name|Repository|Icon)": *"[^"]*"';
const CL_ICON_CDN       = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/';    // github.com/homarr-labs/dashboard-icons
const CL_ICON_LABEL     = 'net.unraid.docker.icon';
const CL_ICON_MAX       = 1 << 20;
const CL_ICON_OK_TTL    = 7 * 86400;         // a picture that loaded is checked again after this
const CL_ICON_BAD_TTL   = 86400;             // one that didn't, after this (or at a tour)
// well-known images (their name without registry and tag) => the collection's name for the logo;
// databases and caches first, so the one in an app's stack gets the database's logo, not the app's
const CL_ICON_TABLE = [
    '#(^|/)[\w.-]*(postgres|pgvecto|vectorchord|vchord|postgis|timescaledb)[\w.-]*$#' => 'postgres',
    '#(^|/)mariadb[\w.-]*$#'                       => 'mariadb',
    '#(^|/)(mysql|percona)[\w.-]*$#'               => 'mysql',
    '#(^|/)mongo(db)?[\w.-]*$#'                    => 'mongodb',
    '#(^|/)valkey[\w.-]*$#'                        => 'valkey',
    '#(^|/)(redis|keydb)[\w.-]*$#'                 => 'redis',
    '#(^|/)immich#'                                => 'immich',
    '#(^|/)nextcloud#'                             => 'nextcloud',
    '#(^|/)jellyfin$#'                             => 'jellyfin',
    '#^plexinc/|(^|/)(plex|pms-docker)$#'          => 'plex',
    '#^emby/|(^|/)(emby|embyserver[\w-]*|arch-emby)$#' => 'emby',
    '#(^|/)(httpd|apache2?)$#'                     => 'apache',
    '#(^|/)php$#'                                  => 'php',
    '#nginx-proxy-manager#'                        => 'nginx-proxy-manager',
    '#(^|/)nginx(-unprivileged)?$#'                => 'nginx',
    '#(^|/)home-?assistant$#'                      => 'home-assistant',
    '#(^|/)adguard-?home$#'                        => 'adguard-home',
    '#(^|/)pi-?hole$#'                             => 'pi-hole',
    '#(^|/)(eclipse-)?mosquitto$#'                 => 'mosquitto',
    '#(^|/)node-?red$#'                            => 'node-red',
    '#^onlyoffice/#'                               => 'onlyoffice',
    '#^vaultwarden/|(^|/)vaultwarden$#'            => 'vaultwarden',
    '#^goauthentik/#'                              => 'authentik',
    '#(^|/)portainer(-ce|-ee)?$#'                  => 'portainer',
    '#(^|/)rustdesk[\w-]*$#'                       => 'rustdesk',
    '#(^|/)wg-easy$#'                              => 'wireguard',
    '#(^|/)open-?webui$#'                          => 'open-webui',
    '#(^|/)paperless-?ngx$#'                       => 'paperless-ngx',
    '#(^|/)uptime-?kuma$#'                         => 'uptime-kuma',
    '#(^|/)(code-server|openvscode-server)$#'      => 'code-server',
    '#(^|/)qbittorrent[\w-]*$#'                    => 'qbittorrent',
    '#(^|/)bitcoin(d|-core)?$#'                    => 'bitcoin',
    '#(^|/)cloudflare[\w-]*$#'                     => 'cloudflare',
    '#(^|/)time-?machine$#'                        => 'apple',
    '#(^|/)virtual-dsm$#'                          => 'synology',
];
// words that say nothing about an app: never a guess of their own
const CL_ICON_GENERIC = ['docker', 'server', 'app', 'web', 'base', 'alpine', 'latest', 'image', 'service', 'open', 'unraid', 'arch', 'custom', 'test'];
// a stack's service that is its database or cache: its picture doesn't become the stack's
const CL_ICON_HELPERS = '/(^|[-_])(db|database|postgres|postgresql|pg|mariadb|mysql|mongo|mongodb|redis|valkey|cache|cron|worker)([-_]|$)/i';

// mentions on the flash that don't make a folder "used": caches, shell history, plugin installers
const CL_WEAK = '#^(history/|plugins-removed/|[^/]+\.plg$|plugins/[^/]+\.plg$|plugins/dockerMan/(buildx|templates|template-repos|images)/'
              . '|plugins/dynamix\.my\.servers/configs/docker\.organizer\.json$|plugins/compose\.manager/containers\.cache\.json$)#';

$GLOBALS['clState'] = null;
$GLOBALS['clCtx'] = ['roots' => [], 'asleep' => []];
$GLOBALS['clJobs'] = ['queue' => [], 'running' => []];
$GLOBALS['clPurgeTries'] = [];

desk('cleanup', [
    'fit'     => fn (): array => (readCfg('/boot/config/docker.cfg')['DOCKER_ENABLED'] ?? 'no') === 'yes'
                                 || (readCfg('/boot/config/domain.cfg')['SERVICE'] ?? 'disable') === 'enable'
                                 ? fit(true, 'yes') : fit(false, 'nothing'),
    'start'   => fn () => clScan(),
    'tick'    => fn () => clJobsTick(),
    'checks'  => fn (): array => clChecks(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => clScan()],
        'scan'    => fn (array $r) => ['ok' => true, 'state' => clScan(!empty($r['wake']), true, true)],
        'install' => fn (array $r) => clInstall(textField($r, 'id')),
        'measure' => fn (array $r) => clMeasure(idList($r, 'ids')),
        'detail'  => fn (array $r) => clDetail(textField($r, 'id')),
        'park'    => fn (array $r) => clPark(idList($r, 'ids'), !empty($r['force'])),
        'restore' => fn (array $r) => clRestore(idList($r, 'ids')),
        'purge'   => fn (array $r) => clPurge(idList($r, 'ids'), !empty($r['volumes']), !empty($r['images'])),
        'remove'  => fn (array $r) => clRemove(idList($r, 'ids')),
        'icons'   => fn (array $r) => clIconsApply(clIconItems($r)),
        'icon_fallback' => fn (array $r) => clIconFallback(),
    ],
]);

// ===================================================================== tour

/**
 * Reads the server (quick part), queues the slow work, builds the state.
 * $jobs = false: only look (before changing something — no new search may
 * hold up the change that is about to happen).
 */
function clScan(bool $wake = false, bool $jobs = true, bool $deep = false): array
{
    $t0 = microtime(true);
    $GLOBALS['clFresh'] = [];                    // share settings, ZFS mountpoints: read anew on every tour (the agent runs for weeks)
    $roots = clRoots();
    $asleep = sleepingDisks();
    $domain = readCfg('/boot/config/domain.cfg');
    $settings = [
        'appdata' => readCfg('/boot/config/docker.cfg')['DOCKER_APP_CONFIG_PATH'] ?? '/mnt/user/appdata/',
        'domains' => $domain['DOMAINDIR'] ?? '/mnt/user/domains/',
        'isos'    => $domain['MEDIADIR'] ?? '/mnt/user/isos/',
    ];
    $places = array_map(fn ($path) => clSharePlaces($path, $roots, $asleep), $settings);
    $sleeping = array_values(array_unique(array_merge(...array_column($places, 'asleep'))));
    if ($wake && $sleeping) {
        $woken = clWake($sleeping);
        $asleep = sleepingDisks();
        foreach ($woken as $name) {
            $asleep[$name] = false;            // Unraid's bookkeeping lags behind
        }
        $places = array_map(fn ($path) => clSharePlaces($path, $roots, $asleep), $settings);
    }
    $GLOBALS['clCtx'] = ['roots' => $roots, 'asleep' => $asleep];

    $docker = clDocker();
    $vms = clVmFacts();
    $cache = clCache();
    $raw = [
        'docker'    => $docker,
        'templates' => clTemplates($docker),
        'stacks'    => clStacks($docker, $cache),
        'strays'    => clStrays($cache, $docker),
        'appdata'   => $places['appdata'] + ['folders' => clShareFolders($places['appdata'])],
        'domains'   => $places['domains'] + ['folders' => $vms['enabled'] ? clShareFolders($places['domains']) : ['list' => [], 'files' => 0]],
        'isos'      => $places['isos'] + ['files' => $vms['enabled'] ? clMediaFiles($places['isos']) : []],
        'compose'   => clForeignCompose($docker),
        'scripts'   => clUserScripts(),
        'vms'       => $vms,
        'libvirt'   => clLibvirtOrphans($vms),
        'trash'     => clTrashRuns($places, $vms),
    ];
    $raw['icons'] = clIcons($docker, $raw['stacks']);
    clSaveCache($cache);
    $GLOBALS['clRaw'] = $raw;

    $hired = $jobs && in_array('cleanup', staffHired(), true);
    if ($hired && $raw['icons']) {
        clIconQueue($raw['icons'], $deep);
    }
    if ($hired && time() - (int) ($cache['flash']['at'] ?? 0) > CL_FLASH_TTL) {
        clJobAdd('flash', 'flash', [['grep', '-roI', '--exclude-dir=' . CL_TRASH, '--exclude-dir=' . CL_LEGACY,
                                     '-e', '/mnt/[^"<>[:space:]]*', CL_FLASH]], 300, true);
    }
    if ($hired && ($deep || time() - (int) ($cache['strays']['at'] ?? 0) > CL_STRAY_TTL)) {
        // stray templates: the flash, and the pools only three levels deep (<share>/<folder>/my-*.xml — deeper
        // a media pool lists every episode); never the array, never a pool with a sleeping disk
        $pools = [];
        foreach ($roots as $name => $r) {
            if ($r['kind'] === 'pool' && !clPoolAsleep($name, $asleep)) {
                $pools[] = "/mnt/$name";
            }
        }
        $find = fn (array $where, int $depth) => [array_merge(['nice', '-n', '10', 'find'], $where, ['-maxdepth', (string) $depth,
            '(', '-name', '.*', '-o', '-name', CL_TRASH . '*', '-o', '-name', CL_LEGACY, '-o', '-name', '*.sparsebundle', ')', '-prune',
            '-o', '-type', 'f', '-name', 'my-*.xml', '-print'])];
        clJobAdd('strays:flash', 'strays', $find(['/boot'], 6), 300);
        if ($pools) {
            clJobAdd('strays:pools', 'strays', $find($pools, 3), 600);
        }
    }
    if ($hired && $docker['ok'] && time() - (int) ($cache['build']['at'] ?? 0) > CL_CACHE_TTL) {
        clJobAdd('cache', 'cache', [['docker', 'system', 'df', '--format', "{{.Type}}\t{{.Size}}\t{{.Reclaimable}}"]], 120);
    }
    $state = clBuild();
    if ($hired) {
        // candidates are kept measured (their newest change decides); used folders only when asked
        foreach (array_merge($state['appdata']['list'], $state['vms']['list']) as $f) {
            if ($f['category'] !== 'used' && isset($f['parts'])) {
                foreach ($f['parts'] as $p) {
                    if (empty($p['file'])) {
                        clMeasureQueue($p['path'], CL_MEASURE_TTL);
                    }
                }
            }
        }
        foreach ($state['docker']['list'] as $e) {
            if ($e['kind'] === 'volume' && $e['category'] !== 'used' && $e['path']) {
                clMeasureQueue($e['path'], CL_MEASURE_TTL);
            }
        }
        foreach ($state['trash']['runs'] as $run) {
            if (!$run['purging']) {
                clMeasureQueue($run['path'], PHP_INT_MAX);
                foreach ($run['items'] as $it) {
                    if ($it['zfs_path']) {
                        clMeasureQueue($it['zfs_path'], PHP_INT_MAX);
                    }
                }
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
    return $GLOBALS['clFresh']['share'][$share] ??= readCfg("/boot/config/shares/$share.cfg");
}

/** A pool sleeps when any of its disks does (cache, cache2, cache3 …) */
function clPoolAsleep(string $pool, array $asleep): bool
{
    if (preg_match('/^disk\d+$/', $pool)) {
        return $asleep[$pool] ?? false;              // an array disk is just itself (disk1 is not disk10)
    }
    foreach ($asleep as $disk => $sleeping) {
        if ($sleeping && preg_match('/^' . preg_quote($pool, '/') . '\d*$/', (string) $disk)) {
            return true;
        }
    }
    return false;
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
        return ($cfg['shareUseCache'] ?? 'no') === 'only' && isset($ctx['roots'][$pool]) && !clPoolAsleep($pool, $ctx['asleep']);
    }
    return isset($ctx['roots'][$m[1]]) && !clPoolAsleep($m[1], $ctx['asleep']);
}

/** true / false, or null when looking would wake a disk */
function clExists(string $path): ?bool
{
    // a share that doesn't exist at all: gone, without asking any disk
    if (preg_match('#^/mnt/user0?/([^/]+)#', $path, $m)) {
        $GLOBALS['clFresh']['shares'] ??= array_keys(readCfg('/var/local/emhttp/shares.ini', true));
        if (!in_array($m[1], $GLOBALS['clFresh']['shares'], true) && !is_file("/boot/config/shares/{$m[1]}.cfg")) {
            return false;
        }
    }
    return clSafe($path) ? file_exists($path) : null;
}

/**
 * Wakes the named array disks and pools — every disk of a pool (cache, cache2 …) —
 * with one block read from each, in parallel. @return list<string> the disks woken
 */
function clWake(array $names): array
{
    $commands = [];
    foreach (readCfg('/var/local/emhttp/disks.ini', true) as $section => $d) {
        $name = (string) ($d['name'] ?? $section);
        $dev = $d['device'] ?? '';
        $wanted = false;
        foreach ($names as $n) {
            $wanted = $wanted || $name === $n || (!preg_match('/^disk\d+$/', $n) && preg_match('/^' . preg_quote($n, '/') . '\d*$/', $name));
        }
        if ($wanted && preg_match('/^[a-z0-9]+$/', $dev) && file_exists("/dev/$dev")) {
            $commands[$name] = ['dd', "if=/dev/$dev", 'of=/dev/null', 'bs=4096', 'count=1', 'iflag=direct'];
        }
    }
    if ($commands) {
        runAll($commands, 90);
        logLine('Dustdevil woke ' . implode(', ', array_keys($commands)) . ' to look at the shares on them');
    }
    return array_keys($commands);
}

// --------------------------------------------------------------------- docker

function clDocker(): array
{
    $out = ['ok' => false, 'enabled' => (readCfg('/boot/config/docker.cfg')['DOCKER_ENABLED'] ?? 'no') === 'yes', 'compose' => false,
            'containers' => [], 'images' => [], 'repos' => [], 'volumes' => [], 'by_id' => [], 'volume_info' => []];
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
        $volumes = [];
        foreach ((array) ($c['Mounts'] ?? []) as $m) {
            if (($m['Type'] ?? '') === 'bind' && !empty($m['Source'])) {
                $binds[] = (string) $m['Source'];
            } elseif (($m['Type'] ?? '') === 'volume' && !empty($m['Name'])) {
                $volumes[] = (string) $m['Name'];
            }
        }
        $out['containers'][$name] = [
            'name'      => $name,
            'state'     => (string) ($c['State']['Status'] ?? 'unknown'),
            'image'     => (string) ($c['Config']['Image'] ?? ''),
            'image_id'  => (string) ($c['Image'] ?? ''),
            'volumes'   => $volumes,
            'project'   => $labels['com.docker.compose.project'] ?? null,
            'files'     => array_values(array_filter(explode(',', (string) ($labels['com.docker.compose.project.config_files'] ?? '')))),
            'dockerman' => ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman',
            'binds'     => $binds,
            'icon'      => array_key_exists(CL_ICON_LABEL, $labels) ? (string) $labels[CL_ICON_LABEL] : null,
            'service'   => isset($labels['com.docker.compose.service']) ? (string) $labels['com.docker.compose.service'] : null,
        ];
    }

    // every top-level image (dangling ones too): id => its tags, size, age
    [$exit, $list] = run([$docker, 'image', 'ls', '--no-trunc', '--format', "{{.ID}}\t{{.Repository}}\t{{.Tag}}"], 30);
    foreach ($exit === 0 ? rows($list) : [] as $f) {
        if (count($f) < 3) {
            continue;
        }
        $out['by_id'][$f[0]] ??= ['id' => $f[0], 'refs' => [], 'bytes' => null, 'created' => null];
        if ($f[1] !== '<none>' && $f[2] !== '<none>') {
            $out['images'][clNormImage("$f[1]:$f[2]")] = ['ref' => "$f[1]:$f[2]", 'id' => $f[0], 'bytes' => null, 'used_by' => []];
            $out['by_id'][$f[0]]['refs'][] = "$f[1]:$f[2]";
        }
    }
    [$exit, $list] = run([$docker, 'image', 'ls', '--no-trunc', '--filter', 'dangling=true', '--format', '{{.ID}}'], 30);
    foreach ($exit === 0 ? rows($list) : [] as $f) {
        $out['by_id'][$f[0]] ??= ['id' => $f[0], 'refs' => [], 'bytes' => null, 'created' => null];
    }
    foreach ($data as $c) {                      // pinned by digest, these don't show up in "image ls" at all
        $id = (string) ($c['Image'] ?? '');
        if ($id !== '') {
            $out['by_id'][$id] ??= ['id' => $id, 'refs' => [], 'bytes' => null, 'created' => null];
        }
    }
    if ($out['by_id']) {
        [$exit, $sizes] = run(array_merge([$docker, 'image', 'inspect', '--format', "{{.Id}}\t{{.Size}}\t{{.Created}}"], array_keys($out['by_id'])), 30);
        foreach ($exit === 0 ? rows($sizes) : [] as $f) {
            if (count($f) >= 3 && isset($out['by_id'][$f[0]])) {
                $out['by_id'][$f[0]]['bytes'] = (int) $f[1];
                $out['by_id'][$f[0]]['created'] = strtotime($f[2]) ?: null;
            }
        }
        foreach ($out['images'] as &$img) {
            $img['bytes'] = $out['by_id'][$img['id']]['bytes'] ?? null;
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
    if ($out['volumes']) {
        [$exit, $json] = run(array_merge([$docker, 'volume', 'inspect'], array_map('strval', array_keys($out['volumes']))), 30);
        foreach ($exit === 0 ? (json_decode($json, true) ?: []) : [] as $v) {
            $labels = (array) ($v['Labels'] ?? []);
            $out['volume_info'][(string) $v['Name']] = [
                'path'      => (string) ($v['Mountpoint'] ?? ''),
                'created'   => strtotime((string) ($v['CreatedAt'] ?? '')) ?: null,
                'anonymous' => array_key_exists('com.docker.volume.anonymous', $labels) || preg_match('/^[0-9a-f]{64}$/', (string) $v['Name']) === 1,
                'project'   => $labels['com.docker.compose.project'] ?? null,
                'driver'    => (string) ($v['Driver'] ?? 'local'),
            ];
        }
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

/** One field of a template's XML */
function clXmlField(string $file, string $tag): string
{
    $xml = (string) @file_get_contents($file, false, null, 0, 1 << 20);
    return preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)) : '';
}

/**
 * Stray templates found by the background search, judged against Unraid's
 * own folder (like the cleanup script did):
 *   only_here    Unraid doesn't have it — take it over, or put it away
 *   copy         the same file is in Unraid's folder
 *   older        Unraid's folder has a newer version
 *   name_exists  Unraid's folder has a template with this <Name>
 *   newer        this one is newer than Unraid's — look yourself
 */
function clStrays(array $cache, array $docker): array
{
    $files = [];
    $names = [];
    foreach (glob(CL_TEMPLATES . '/my-*.xml') ?: [] as $f) {
        $files[basename($f)] = $f;
        $n = clXmlField($f, 'Name');
        if ($n !== '') {
            $names[$n] = $f;
        }
    }
    $out = [];
    foreach ($cache['strays']['paths'] ?? [] as $path) {
        if (!clSafe($path) || !is_file($path) || is_link($path)) {
            continue;
        }
        $base = basename($path);
        $name = clXmlField($path, 'Name');
        $canon = $files[$base] ?? null;
        if ($canon) {
            $loc = md5_file($canon) === md5_file($path) ? 'copy' : (filemtime($path) > filemtime($canon) ? 'newer' : 'older');
        } else {
            $loc = $name !== '' && isset($names[$name]) ? 'name_exists' : 'only_here';
        }
        $out[] = [
            'id' => "stray:$path", 'kind' => 'stray', 'file' => $base, 'name' => $name, 'path' => $path, 'dir' => dirname($path),
            'category' => "stray_$loc", 'loc' => $loc, 'canonical' => $canon ?? ($names[$name] ?? null),
            'image' => clXmlField($path, 'Repository'), 'container' => $name !== '' && isset($docker['containers'][$name]),
            'mtime' => (int) @filemtime($path), 'bytes' => (int) @filesize($path), 'why' => null, 'force' => false,
        ];
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['path'], $b['path']));
    return $out;
}

/** The storeroom for a stray template: on the flash, or in the share it lies in */
function clStrayTrash(string $path): string
{
    if (under($path, '/boot')) {
        return CL_FLASH . '/' . CL_TRASH;
    }
    if (preg_match('#^(/mnt/[^/]+/[^/]+)/#', $path, $m) && !preg_match('#^/mnt/(user0?|disks|remotes|addons|rootshare)/#', $path)) {
        return $m[1] . '/' . CL_TRASH;
    }
    throw new Problem('cleanup_move_failed', ['path' => $path, 'detail' => '']);
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
                $volumes[] = (string) $v;
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

// --------------------------------------------------------------------- User Scripts

/** The User Scripts plugin's scripts, with what tells whether they are still in use */
function clUserScripts(): array
{
    $up = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
    $out = ['installed' => is_dir(CL_US_SCRIPTS), 'boot' => $up > 0 ? time() - (int) $up : null, 'list' => []];
    $schedule = json_decode((string) @file_get_contents(US_SCHEDULE), true) ?: [];
    $now = time();
    foreach ($out['installed'] ? (glob(CL_US_SCRIPTS . '/*', GLOB_ONLYDIR) ?: []) : [] as $dir) {
        $id = basename($dir);
        $file = "$dir/script";
        $exists = is_file($file);
        $text = $exists ? (string) @file_get_contents($file, false, null, 0, 65536) : '';
        $plan = is_array($schedule[$file] ?? null) ? $schedule[$file] : [];
        $freq = (string) ($plan['frequency'] ?? 'disabled');
        $description = trim((string) @file_get_contents("$dir/description"));
        if ($description === '' && preg_match('/^#\s*description=(.*)$/m', $text, $m)) {
            $description = trim($m[1]);
        }
        // paths it names, comments left out (the same rule Ms. Whereabouts follows)
        preg_match_all('#(?<![\w$}])(/(?:mnt|boot)/[^\s"\'`;|&<>(){}$]+)#', preg_replace('/^\s*#.*$/m', '', $text), $pm);
        $paths = [];
        foreach (array_slice(array_unique(array_map(fn ($p) => rtrim($p, '/.,;:'), $pm[1])), 0, 40) as $p) {
            $paths[] = ['path' => $p, 'exists' => clExists($p)];
        }
        $dead = count(array_filter($paths, fn ($p) => $p['exists'] === false));
        $pid = (int) @file_get_contents(CL_US_TMP . "/running/$id");
        $running = $pid > 1 && is_dir("/proc/$pid");
        $lastRun = @filemtime(CL_US_TMP . "/tmpScripts/$id/log.txt") ?: null;
        $mtime = $exists ? (int) @filemtime($file) : (int) @filemtime($dir);
        $office = str_starts_with($id, US_PREFIX);
        $scheduled = $freq !== 'disabled' && $freq !== '';
        if (!$exists) {
            $category = 'broken';
        } elseif ($office || $scheduled || $running || $lastRun || $mtime > $now - CL_FRESH_DAYS * 86400) {
            $category = 'used';
        } else {
            $category = $dead ? 'dead' : 'idle';
        }
        $out['list'][] = [
            'id' => "userscript:$id", 'kind' => 'userscript', 'name' => trim((string) @file_get_contents("$dir/name")) ?: $id, 'folder' => $id,
            'dir' => $dir, 'path' => $file, 'exists' => $exists, 'description' => $description, 'category' => $category,
            'frequency' => $freq, 'cron' => trim((string) ($plan['custom'] ?? '')) ?: null, 'running' => $running, 'last_run' => $lastRun,
            'mtime' => $mtime, 'bytes' => $exists ? (int) @filesize($file) : 0, 'paths' => $paths, 'dead' => $dead, 'office' => $office,
            'why' => $office ? 'office' : ($running ? 'running' : ($scheduled ? 'scheduled' : null)), 'force' => false,
        ];
    }
    usort($out['list'], fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

// --------------------------------------------------------------------- pictures

/**
 * Every container and its picture, found the way Unraid's Docker page finds it
 * (DockerClient::getAllInfo() and getIcon(), 7.3): a template (templates-user,
 * then its default folder, by file name) whose <Name> is the container's name
 * and whose <Repository> its image gives its <Icon>; without one, the label
 * net.unraid.docker.icon. Unraid downloads that once into <name>-icon.png and
 * remembers the path in docker.json — question.png there means it found none
 * or couldn't load it. Read only; no network (pictures are checked in the
 * background, see clIconQueue()).
 *
 *   category  ok        Unraid shows a picture
 *             template  none, set in its user template (shown at once)
 *             compose   none, set in its Compose Manager override file
 *                       (lasting from the stack's next Compose Up)
 *             none      neither — only re-creating the container would help
 */
function clIcons(array $docker, array $stacks): array
{
    if (!$docker['ok'] || !$docker['containers']) {
        return [];
    }
    $templates = clIconTemplates();
    $shown = readJson(CL_DM_JSON) ?? [];
    $byProject = [];
    foreach ($stacks['list'] as $s) {
        foreach (array_merge([$s['project']], $s['alts']) as $p) {
            $byProject[$p] ??= $s;
        }
    }
    $ca = null;
    $out = [];
    foreach ($docker['containers'] as $name => $c) {
        $name = (string) $name;
        $tag = clImageTag($c['image']);
        $tpl = null;
        foreach ($templates as $t) {
            if ($t['name'] === $name && $t['repo'] !== '' && clImageTag($t['repo']) === $tag) {
                $tpl = $t;                         // the first one, like Unraid — even when its <Icon> is empty
                break;
            }
        }
        $value = $tpl && $tpl['icon'] !== '' ? $tpl['icon'] : (string) ($c['icon'] ?? '');
        $from = $tpl && $tpl['icon'] !== '' ? 'template' : ($value !== '' ? 'label' : null);

        // what Unraid shows now: the path it remembered, or (not looked yet) its copy or something it can download
        $web = is_string($shown[$name]['icon'] ?? null) ? $shown[$name]['icon'] : null;
        if ($web === null) {
            $pic = clIsPicture(CL_DM_RAM . "/$name-icon.png") || clIsPicture(CL_DM_DISK . "/$name-icon.png")
                || preg_match('#^(https?|file)://#i', $value) === 1;
        } else {
            $pic = $web !== '' && $web !== CL_DM_FALLBACK_WEB && clIsPicture(is_file($web) ? $web : CL_DOCROOT . $web);
        }

        $how = 'none';
        $why = null;
        $override = null;
        $stack = null;
        if ($tpl && dirname($tpl['path']) === CL_TEMPLATES && preg_match('/^my-.+\.xml$/', basename($tpl['path']))) {
            $how = 'template';
        } elseif ($c['project'] !== null && isset($byProject[$c['project']])) {
            $how = 'compose';
            $stack = $byProject[$c['project']];
            [$override, $why] = clIconOverride($stack, $c['service']);
        } else {
            $why = $tpl || $c['dockerman'] ? 'no_template' : ($c['project'] !== null ? 'no_stack' : 'plain');
        }

        $candidates = [];
        if (!$pic) {
            $local = clIconLocal($value);
            if ($local !== null) {
                $candidates[] = ['url' => $local, 'source' => 'local', 'detail' => substr($local, 7)];
            }
            [$exact, $loose] = clIconRepo($c['image']);
            $ca ??= clCaIcons();
            $hits = $ca['exact'][$exact] ?? $ca['loose'][$loose] ?? [];
            arsort($hits);                         // the picture most of its apps in the feed use first
            foreach (array_slice(array_keys($hits), 0, 2) as $url) {
                $candidates[] = ['url' => (string) $url, 'source' => 'ca', 'detail' => $exact];
            }
            $slug = clIconTableSlug($loose);
            if ($slug !== null) {
                $candidates[] = ['url' => CL_ICON_CDN . "$slug.png", 'source' => 'table', 'detail' => $slug];
            }
            foreach (clIconGuesses($loose) as $g) {
                $candidates[] = ['url' => CL_ICON_CDN . "$g.png", 'source' => 'guess', 'detail' => $g];
            }
            $seen = [];
            $candidates = array_values(array_filter($candidates, function (array $x) use (&$seen): bool {
                $new = !isset($seen[$x['url']]);
                $seen[$x['url']] = true;
                return $new;
            }));
        }
        $out[] = [
            'id'          => "icon:$name",
            'kind'        => 'icon',
            'name'        => $name,
            'image'       => $c['image'],
            'state'       => $c['state'],
            'category'    => $pic ? 'ok' : $how,
            'status'      => $pic ? 'ok' : ($value === '' ? 'missing' : 'broken'),
            'value'       => $value,
            'value_from'  => $from,
            'shown'       => $pic ? $web : null,
            'template'    => $tpl['path'] ?? null,
            'template_id' => $how === 'template' ? 'template:' . basename($tpl['path']) : null,
            'project'     => $stack['folder'] ?? null,
            'service'     => $c['service'],
            'override'    => $override,
            'path'        => $how === 'template' ? $tpl['path'] : $override,
            'why'         => $pic ? null : $why,
            'candidates'  => $candidates,
            'force'       => false,
        ];
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/** Every template Unraid reads, in the order it searches them (DockerTemplates::getTemplates('all')): file, Name, Repository, Icon */
function clIconTemplates(): array
{
    $out = [];
    foreach ([CL_TEMPLATES, CL_TEMPLATES_USB] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                                                RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($it as $path => $f) {
                if (!$f->isFile() || $f->getExtension() !== 'xml' || str_starts_with($f->getFilename(), '.')) {
                    continue;
                }
                $xml = (string) @file_get_contents($path, false, null, 0, 1 << 20);
                $field = fn (string $tag): string => preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $m)
                    ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)) : '';
                $out[] = ['path' => (string) $path, 'key' => $f->getBasename('.xml'), 'name' => $field('Name'),
                          'repo' => $field('Repository'), 'icon' => $field('Icon')];
                if (count($out) >= 5000) {
                    break 2;
                }
            }
        } catch (Throwable) {
            // unreadable: as far as we got
        }
    }
    usort($out, fn ($a, $b) => strnatcasecmp($a['key'], $b['key']));
    return $out;
}

/** DockerUtil::ensureImageTag(): how Unraid compares a template's <Repository> with a container's image */
function clImageTag(string $image): string
{
    if (str_starts_with($image, 'sha256:')) {
        return trim(substr($image, 7, 12)) . ':latest';
    }
    if (!str_contains($image, '/')) {
        return clImageTag('library/' . $image);
    }
    $repo = $image;
    $tag = '';
    if (preg_match('@^(.+/)*([^/:]+)(:[^:/]*)*$@', $image, $m) && count($m) >= 3) {
        $repo = ($m[1] ?? '') . $m[2];
        $tag = str_replace(':', '', $m[3] ?? '');
    }
    $tag = trim($tag);
    return trim($repo) . ':' . (empty($tag) ? 'latest' : $tag);
}

/**
 * An image's name to look it up by: [exact, loose] — lower case, without tag and
 * digest, without docker.io and library/; loose also without any registry
 * (ghcr.io/versity/versitygw and versity/versitygw are the same app)
 */
function clIconRepo(string $image): array
{
    $i = strtolower(trim(preg_replace('/@sha256:[0-9a-f]*$/i', '', $image)));
    $i = preg_replace(['#:[^:/]*$#', '#^(docker\.io|index\.docker\.io|registry-1\.docker\.io)/#', '#^library/#'], '', $i);
    $parts = explode('/', $i);
    $loose = count($parts) > 1 && preg_match('/[.:]|^localhost$/', $parts[0]) ? implode('/', array_slice($parts, 1)) : $i;
    return [$i, preg_replace('#^library/#', '', $loose)];
}

/** The logo the table knows for an image (by its loose name), or null */
function clIconTableSlug(string $loose): ?string
{
    foreach (CL_ICON_TABLE as $re => $slug) {
        if (preg_match($re, $loose)) {
            return $slug;
        }
    }
    return null;
}

/** Names the collection might have the logo under, guessed from the image's last part — only offered once they load */
function clIconGuesses(string $loose): array
{
    $last = basename($loose);
    $slug = fn (string $s): string => trim(preg_replace(['/_+/', '/[^a-z0-9-]+/', '/-+/'], ['-', '', '-'], strtolower($s)), '-');
    $short = $last;
    for ($i = 0; $i < 2; $i++) {
        $short = preg_replace('/[-_.](server|app|docker|oss|ce|ee|aio|web|ui|official|community|stable)$/i', '', $short);
    }
    $out = [$slug($last), $slug(preg_replace('/^(docker|arch|unraid)[-_.]/i', '', $short)), $slug(preg_split('/[-_.]/', $last)[0])];
    return array_values(array_unique(array_filter($out, fn ($x) => strlen($x) >= 3 && !in_array($x, CL_ICON_GENERIC, true))));
}

/** A picture on this server named by its path — Unraid's downloader only opens it as file:// — or null */
function clIconLocal(string $value): ?string
{
    $path = str_starts_with($value, 'file://') ? substr($value, 7) : $value;
    if (!preg_match('#^/(boot|mnt|usr/local/emhttp)/[^\s"\'<>]+$#', $path) || str_contains($path, '/../') || !clSafe($path)) {
        return null;
    }
    return is_file($path) && (int) @filesize($path) <= CL_ICON_MAX && clIsPicture($path, true) ? "file://$path" : null;
}

/** Does a file start like a picture a browser shows (PNG, JPEG, GIF, WebP)? $png: only PNG */
function clIsPicture(string $file, bool $png = false): bool
{
    $h = @fopen($file, 'rb');
    if (!$h) {
        return false;
    }
    $head = (string) fread($h, 12);
    fclose($h);
    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        return true;
    }
    return !$png && (str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, 'GIF8')
                     || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP'));
}

/**
 * Community Applications' app feed on this server (in RAM once its Apps page was
 * opened): image => the pictures its apps use, with how many use each. Read with
 * grep (the feed is ~30 MB), only again when the feed changes.
 */
function clCaIcons(): array
{
    $st = @stat(CL_CA_TEMPLATES);
    if (!$st) {
        return ['at' => null, 'exact' => [], 'loose' => []];
    }
    $sig = $st['mtime'] . ':' . $st['size'];
    if (($GLOBALS['clCa']['sig'] ?? null) !== $sig) {
        [$exit, $text] = run(['grep', '-oE', CL_CA_PATTERN, CL_CA_TEMPLATES], 30);
        $GLOBALS['clCa'] = clCaParse($exit <= 1 ? $text : '') + ['sig' => $sig, 'at' => (int) $st['mtime']];
    }
    return $GLOBALS['clCa'];
}

/**
 * The feed's Repository and Icon fields in order (PHP-serialized, or JSON when CA
 * writes it readable): an Icon belongs to the Repository before it, as long as no
 * other entry (Name) began in between. Only http(s) pictures that may be PNGs.
 */
function clCaParse(string $text): array
{
    $exact = [];
    $loose = [];
    $repo = null;
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^(?:s:\d+:"(Name|Repository|Icon)";(?:s:\d+:"([^"]*)")?|"(Name|Repository|Icon)": *"([^"]*)")$/', $line, $m)) {
            continue;
        }
        $key = $m[1] !== '' ? $m[1] : $m[3];
        $value = $m[1] !== '' ? ($m[2] ?? '') : str_replace('\/', '/', $m[4] ?? '');
        if ($key === 'Name') {
            $repo = null;
        } elseif ($key === 'Repository') {
            $repo = $value;
        } elseif ($repo !== null) {
            if (preg_match('#^https?://[^\s"\'<>\\\\]+$#i', $value) && !preg_match('#\.(svg|jpe?g|gif|webp|ico|bmp)(\?.*)?$#i', $value)) {
                [$e, $l] = clIconRepo($repo);
                $exact[$e][$value] = ($exact[$e][$value] ?? 0) + 1;
                $loose[$l][$value] = ($loose[$l][$value] ?? 0) + 1;
            }
            $repo = null;
        }
    }
    return ['exact' => $exact, 'loose' => $loose];
}

/**
 * Where a compose container's icon label goes: the override file Compose
 * Manager uses for the stack (OverrideInfo::resolveOverride(): <compose
 * file>.override.<ext>, or the old docker-compose.override.yml, in the stack's
 * folder). @return array{0: ?string, 1: ?string} the file, or why not
 */
function clIconOverride(array $s, ?string $service): array
{
    if ($service === null || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $service)) {
        return [null, 'no_service'];
    }
    if (!$s['reachable']) {
        return [null, 'asleep'];
    }
    if (!$s['file']) {
        return [null, 'no_service'];
    }
    if (strtolower(clMeta($s['dir'], 'labels_view_mode')) === 'advanced') {
        return [null, 'manual'];                   // "Override File Management: Manual" — the user's own file
    }
    $names = array_values(array_unique([clOverrideName($s['file']), 'docker-compose.override.yml']));
    if ($s['indirect'] !== null) {
        foreach ($names as $n) {
            if (is_file($s['src'] . "/$n")) {
                return [null, 'indirect_override'];  // Compose Manager takes that one, next to the compose file
            }
        }
    }
    // a service the compose file doesn't have would break the stack's next Compose Up
    $text = (string) @file_get_contents($s['file'], false, null, 0, 1 << 20);
    if (!preg_match('/^[ ]{2,8}["\']?' . preg_quote($service, '/') . '["\']?[ ]*:/m', $text)) {
        return [null, 'no_service'];
    }
    foreach ($names as $n) {
        if (is_file($s['dir'] . "/$n")) {
            return [$s['dir'] . "/$n", null];
        }
    }
    return [$s['dir'] . '/' . $names[0], null];    // none yet: made the way Compose Manager makes it
}

/** compose.yaml → compose.override.yaml (Compose Manager's OverrideInfo::buildComputedNameForPath()) */
function clOverrideName(string $composeFile): string
{
    $base = basename($composeFile);
    $name = preg_replace('/(\.[^.]+)$/', '.override$1', $base);
    return is_string($name) && $name !== '' && $name !== $base ? $name : "$base.override";
}

/** What Compose Manager writes into a new stack's override file (OverrideInfo::buildTemplateContent()) */
function clOverrideTemplate(): string
{
    return "# Override file for UI labels (icon, webui, shell)\n"
         . "# This file is managed by Compose Manager\n"
         . "#\n"
         . "# Manual example:\n"
         . "# services:\n"
         . "#   my-service:\n"
         . "#     labels:\n"
         . "#       net.unraid.docker.icon: https://icon-url\n"
         . "#       net.unraid.docker.webui: https://service-url\n"
         . "#       net.unraid.docker.shell: /bin/bash\n"
         . "services: {}\n";
}

/** A command in the host's network — hostNet() for background jobs (the stack's agent has no network of its own) */
function clNet(array $command): array
{
    $own = @readlink('/proc/self/ns/net');
    return $own !== false && $own === @readlink('/proc/1/ns/net') ? $command : array_merge(['nsenter', '--target', '1', '--net', '--'], $command);
}

/**
 * Fetches pictures the way Unraid's downloader will (no User-Agent, redirects
 * followed), at most CL_ICON_MAX each, into $dir/<n>.png — one curl, four at a time
 */
function clIconFetchCommand(array $urls, string $dir): array
{
    $cmd = ['curl', '-sS', '-g', '-L', '--max-redirs', '3', '--proto', '=http,https', '--proto-redir', '=http,https', '-A', '',
            '--max-filesize', (string) CL_ICON_MAX, '--connect-timeout', '5', '-m', '15', '--create-dirs', '--parallel', '--parallel-max', '4',
            '-w', "%{urlnum}\t%{http_code}\t%{size_download}\t%{content_type}\n"];
    foreach (array_values($urls) as $i => $url) {
        array_push($cmd, '-o', "$dir/$i.png", '--url', $url);
    }
    return $cmd;
}

/** curl's lines from clIconFetchCommand(): url => [ok, why, file] — a picture only when it answered 200 with a PNG */
function clIconFetchResults(string $out, array $urls, string $dir): array
{
    $urls = array_values($urls);
    $res = [];
    foreach (explode("\n", $out) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 3 || !ctype_digit($f[0]) || !isset($urls[(int) $f[0]])) {
            continue;
        }
        $file = "$dir/{$f[0]}.png";
        $code = (int) $f[1];
        $why = match (true) {
            $code === 0                                       => 'unreachable',
            $code !== 200                                     => "http_$code",
            (int) @filesize($file) > CL_ICON_MAX               => 'too_big',
            !clIsPicture($file, true)                         => 'not_png',
            default                                           => null,
        };
        $res[$urls[(int) $f[0]]] = ['ok' => $why === null, 'why' => $why, 'file' => $why === null ? $file : null];
    }
    foreach ($urls as $url) {
        $res[$url] ??= ['ok' => false, 'why' => 'unreachable', 'file' => null];
    }
    return $res;
}

/** The fetched pictures' folder in RAM, gone again */
function clIconTidy(string $dir): void
{
    if (str_starts_with($dir, RUN_DIR . '/cleanup-icons')) {
        array_map('unlink', glob("$dir/*.png") ?: []);
        @rmdir($dir);
    }
}

/** Checks in the background whether the pictures found can be loaded (each again after a while; at a tour the ones that didn't) */
function clIconQueue(array $icons, bool $again): void
{
    $checked = clCache()['icons'] ?? [];
    $now = time();
    $urls = [];
    foreach ($icons as $e) {
        foreach ($e['candidates'] as $c) {
            $r = $checked[$c['url']] ?? null;
            if ($c['source'] !== 'local' && ($r === null || $now - (int) $r['at'] > ($r['ok'] ? CL_ICON_OK_TTL : ($again ? 0 : CL_ICON_BAD_TTL)))) {
                $urls[$c['url']] = true;
            }
        }
    }
    if ($urls) {
        $urls = array_slice(array_keys($urls), 0, 40);
        $dir = RUN_DIR . '/cleanup-icons-check';
        clJobAdd('icons', 'icons', [clNet(clIconFetchCommand($urls, $dir))], 120, true, ['urls' => $urls, 'dir' => $dir]);
    }
}

/** The room as the page shows it: each picture found with whether it loads, the first one that does as the suggestion */
function clIconState(array $list, array $cache, callable $pending): array
{
    $checked = $cache['icons'] ?? [];
    foreach ($list as &$e) {
        $candidates = [];
        foreach ($e['candidates'] as $c) {
            $r = $c['source'] === 'local' ? ['ok' => true] : ($checked[$c['url']] ?? null);
            $status = $r === null ? 'pending' : ($r['ok'] ? 'ok' : 'bad');
            if ($status !== 'bad') {
                $candidates[] = $c + ['status' => $status];
            }
        }
        $e['candidates'] = $candidates;
        $ok = array_values(array_filter($candidates, fn ($c) => $c['status'] === 'ok'));
        $e['suggest'] = $ok[0]['url'] ?? null;
        $e['checking'] = count($ok) < count($candidates);
    }
    unset($e);
    return ['list' => $list, 'loop' => cleanupIconLoopRisk($list), 'checking' => $pending('icons'),
            'ca_at' => @filemtime(CL_CA_TEMPLATES) ?: null, 'collection' => 'homarr-labs/dashboard-icons'];
}

/**
 * Unraid 7.3.2's Docker page and Dashboard show question.png for a container
 * without a picture — a file 7.3.2 doesn't ship — and their onerror loads it
 * again forever (~1000 requests/s, /var/log full in minutes). Tied to the
 * versions known to do it, so it goes quiet once Unraid fixes it. Also for
 * the caretaker; $list: the room's containers (else from docker.json).
 */
function cleanupIconLoopRisk(?array $list = null): array
{
    $version = preg_match('/^version="?([^"\s]+)"?/m', (string) @file_get_contents('/etc/unraid-version'), $m) ? $m[1] : '';
    $affected = in_array($version, CL_LOOP_VERSIONS, true);
    $missing = $affected && !is_file(CL_DM_FALLBACK);
    $n = 0;
    if ($affected) {
        $list ??= $GLOBALS['clState']['icons']['list'] ?? null;
        if (is_array($list)) {
            $n = count(array_filter($list, fn ($e) => $e['category'] !== 'ok'));
        } else {
            $names = houseContainers();
            foreach (readJson(CL_DM_JSON) ?? [] as $name => $info) {
                $n += isset($names[$name]) && in_array($info['icon'] ?? '', ['', CL_DM_FALLBACK_WEB], true) ? 1 : 0;
            }
        }
    }
    return ['version' => $version, 'affected' => $affected, 'fallback_missing' => $missing, 'containers' => $n,
            'risk' => $missing && $n > 0, 'standin' => clIconStandIn() !== null];
}

/** A picture Unraid or one of its plugins ships, to stand in for the missing question.png */
function clIconStandIn(): ?string
{
    foreach (CL_STANDINS as $f) {
        if (is_file($f) && clIsPicture($f, true)) {
            return $f;
        }
    }
    return null;
}

// --------------------------------------------------------------------- VMs

/** The VMs libvirt knows (its XML files), only while the VM service runs — without it nothing about VMs can be told */
function clVmFacts(): array
{
    $enabled = (readCfg('/boot/config/domain.cfg')['SERVICE'] ?? 'disable') === 'enable';
    $mounted = false;
    foreach (mountTable() as $m) {
        $mounted = $mounted || $m['mount'] === CL_LIBVIRT;
    }
    $out = ['enabled' => $enabled, 'ok' => $enabled && $mounted && is_dir(CL_LIBVIRT . '/qemu'), 'vms' => []];
    $states = [];
    if ($out['ok'] && bin('virsh')) {
        [$exit, $list] = run(['virsh', 'list', '--all'], 20);
        foreach ($exit === 0 ? explode("\n", $list) : [] as $line) {
            if (preg_match('/^\s*(\d+|-)\s+(.+?)\s{2,}(\S.*?)\s*$/', $line, $m)) {
                $states[$m[2]] = $m[3];
            }
        }
    }
    foreach ($out['ok'] ? (glob(CL_LIBVIRT . '/qemu/*.xml') ?: []) : [] as $f) {
        $xml = (string) @file_get_contents($f, false, null, 0, 1 << 20);
        // its disk and CD files: <disk type='file' device='disk|cdrom'> … <source file='…'/>
        $files = [];
        preg_match_all('#<disk\b[^>]*\btype=[\'"]file[\'"][^>]*\bdevice=[\'"](disk|cdrom)[\'"][^>]*>(.*?)</disk>#s', $xml, $dm, PREG_SET_ORDER);
        foreach ($dm as $d) {
            if (preg_match('#<source\b[^>]*\bfile=[\'"]([^\'"]+)[\'"]#', $d[2], $src)) {
                $files[] = ['device' => $d[1], 'path' => html_entity_decode($src[1], ENT_QUOTES | ENT_XML1)];
            }
        }
        $name = preg_match('#<name>([^<]+)</name>#', $xml, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1) : basename($f, '.xml');
        $out['vms'][$name] = [
            'name'  => $name,
            'uuid'  => preg_match('#<uuid>\s*(' . CL_UUID . ')\s*</uuid>#i', $xml, $m) ? strtolower($m[1]) : null,
            'nvram' => preg_match('#<nvram[^>]*>([^<]+)</nvram>#', $xml, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1)) : null,
            'mnt'   => clMntPaths(html_entity_decode($xml, ENT_QUOTES | ENT_XML1)),
            'files' => $files,
            'state' => $states[$name] ?? null,
        ];
    }
    return $out;
}

/**
 * What deleted VMs left in libvirt.img: NVRAM files and TPM states of a UUID
 * no VM has, snapshot lists of a name no VM has. NVRAM copies of an existing
 * VM's snapshots belong to its snapshots (Ms. Snapshotini), not here.
 */
function clLibvirtOrphans(array $vms): array
{
    if (!$vms['ok']) {
        return [];
    }
    $uuids = [];
    $nvrams = [];
    foreach ($vms['vms'] as $v) {
        if ($v['uuid']) {
            $uuids[$v['uuid']] = true;
        }
        if ($v['nvram']) {
            $nvrams[$v['nvram']] = true;
        }
    }
    $out = [];
    foreach (glob(CL_LIBVIRT . '/qemu/nvram/*') ?: [] as $f) {
        if (is_file($f) && !isset($nvrams[$f]) && preg_match('/^(' . CL_UUID . ')(S\d{14})?_VARS/i', basename($f), $m) && !isset($uuids[strtolower($m[1])])) {
            $out[] = ['kind' => 'nvram', 'name' => basename($f), 'path' => $f, 'uuid' => strtolower($m[1]), 'snapshot' => !empty($m[2]),
                      'bytes' => (int) @filesize($f), 'mtime' => (int) @filemtime($f)];
        }
    }
    foreach (glob(CL_LIBVIRT . '/qemu/swtpm/tpm-states/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (preg_match('/^' . CL_UUID . '$/i', basename($d)) && !isset($uuids[strtolower(basename($d))]) && !is_link($d)) {
            $out[] = ['kind' => 'tpm', 'name' => basename($d), 'path' => $d, 'uuid' => strtolower(basename($d)), 'snapshot' => false,
                      'bytes' => clDirBytes($d), 'mtime' => clNewest($d)];
        }
    }
    foreach (glob(CL_LIBVIRT . '/qemu/snapshotdb/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (!isset($vms['vms'][basename($d)]) && !is_link($d)) {
            $out[] = ['kind' => 'snapshotdb', 'name' => basename($d), 'path' => $d, 'uuid' => null, 'snapshot' => false,
                      'bytes' => clDirBytes($d), 'mtime' => clNewest($d)];
        }
    }
    return $out;
}

/** Newest change in a small folder */
function clNewest(string $dir): int
{
    $t = (int) @filemtime($dir);
    foreach (glob("$dir/*") ?: [] as $f) {
        $t = max($t, (int) @filemtime($f));
    }
    return $t;
}

/** Disk images at the first level of the isos share (other files there are the user's business) */
function clMediaFiles(array $isos): array
{
    $files = [];
    foreach ($isos['places'] as $root => $place) {
        foreach (@scandir($place['path']) ?: [] as $name) {
            $full = $place['path'] . "/$name";
            if ($name[0] === '.' || !preg_match(CL_MEDIA, $name) || is_link($full) || !is_file($full)) {
                continue;
            }
            $st = @stat($full);
            $files[$name]['name'] = $name;
            $files[$name]['parts'][] = ['root' => $root, 'path' => $full, 'dataset' => null, 'file' => true,
                                        'mtime' => (int) ($st['mtime'] ?? 0), 'bytes' => (int) ($st['blocks'] ?? 0) * 512];
        }
    }
    uksort($files, 'strnatcasecmp');
    return array_values($files);
}

// --------------------------------------------------------------------- appdata

/** Where a share lives (from Unraid's setting, e.g. /mnt/user/appdata/): one path per pool or awake array disk that holds it */
function clSharePlaces(string $path, array $roots, array $asleep): array
{
    $share = preg_match('#^/mnt/[^/]+/([^/]+)#', $path, $m) ? $m[1] : basename(rtrim($path, '/'));
    $array = (clShareCfg($share)['shareUseCache'] ?? 'no') !== 'only';
    $places = [];
    $sleeping = [];
    foreach ($roots as $name => $r) {
        if ($r['kind'] === 'disk' && !$array) {
            continue;
        }
        if (clPoolAsleep($name, $asleep)) {
            $sleeping[] = $name;
            continue;
        }
        if (is_dir("/mnt/$name/$share")) {
            $places[$name] = ['root' => $name, 'path' => "/mnt/$name/$share", 'fs' => $r['fs']];
        }
    }
    return ['share' => $share, 'setting' => rtrim($path, '/'), 'places' => $places, 'asleep' => $sleeping];
}

/**
 * A dataset can go into the trash with zfs rename when it sits right under the
 * share's own dataset and its mountpoint is inherited (so it moves along)
 */
function clZfsMovable(string $ds, ?string $parent): bool
{
    if (!isset($GLOBALS['clFresh']['zfs'])) {
        $GLOBALS['clFresh']['zfs'] = [];
        [$exit, $out] = run(['zfs', 'get', '-H', '-o', 'name,source', '-t', 'filesystem', 'mountpoint'], 30);
        foreach ($exit === 0 ? rows($out) : [] as $f) {
            $GLOBALS['clFresh']['zfs'][$f[0]] = $f[1] ?? '';
        }
    }
    $sources = $GLOBALS['clFresh']['zfs'];
    return $parent !== null && dirname($ds) === $parent && preg_match('/^(inherited|default)/', $sources[$ds] ?? 'local') === 1;
}

/** First-level folders of a share, over all places it lives on */
function clShareFolders(array $share): array
{
    $mounts = [];
    foreach (mountTable() as $m) {
        $mounts[$m['mount']] = $m['source'];
    }
    $folders = [];
    $files = 0;
    foreach ($share['places'] as $root => $place) {
        foreach (@scandir($place['path']) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || str_starts_with($name, CL_TRASH) || $name === CL_LEGACY || str_starts_with($name, '_quarantaene-')) {
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
            $ds = $mounts[$full] ?? null;               // its own filesystem: rename(2) can't move it, zfs rename can
            $folders[$name]['name'] = $name;
            $folders[$name]['parts'][] = [
                'root'    => $root,
                'path'    => $full,
                'dataset' => $ds,
                'zfs'     => $ds !== null && clZfsMovable($ds, $mounts[$place['path']] ?? null),
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
    $pending = fn (string $key): bool => isset($jobs['running'][$key]) || isset($jobs['queue'][$key]);
    $docker = $raw['docker'];
    $vmf = $raw['vms'];
    $flash = $cache['flash'] ?? null;
    $searched = $flash !== null && !$pending('flash');
    $refs = clRefList($raw, $flash);

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
    $vmNamed = [];
    foreach ($vmf['vms'] as $v) {
        $vmNamed[strtolower($v['name'])] = ['kind' => 'vm', 'name' => $v['name']];
    }

    $ad = $raw['appdata'];
    [$adRefs, $mounters] = clTopRefs($refs, $ad['share']);
    $complete = $searched && $docker['ok'];
    $folders = clFolderEntries($ad['folders']['list'], $adRefs, $named, 'appdata', $docker['ok'], $complete, $cache, $pending);

    // VMs: what deleted VMs left behind (a stopped Docker can't name these, unless it is switched off anyway)
    $vmItems = [];
    $vmComplete = $searched && $vmf['ok'] && ($docker['ok'] || !$docker['enabled']);
    if ($vmf['enabled']) {
        // VMs that are still set up, but whose disk files are gone: removed in Unraid (that takes NVRAM and TPM along)
        foreach ($vmf['vms'] as $v) {
            $disks = [];
            foreach ($v['files'] as $file) {
                $disks[] = $file + ['exists' => clExists($file['path'])];
            }
            $missing = array_filter($disks, fn ($d) => $d['device'] === 'disk' && $d['exists'] === false);
            if ($missing) {
                $vmItems[] = ['id' => 'vmdef:' . $v['name'], 'kind' => 'vmdef', 'name' => $v['name'], 'category' => 'broken',
                              'state' => $v['state'], 'uuid' => $v['uuid'], 'disks' => $disks,
                              'missing' => count($missing), 'total' => count(array_filter($disks, fn ($d) => $d['device'] === 'disk')),
                              'bytes' => null, 'used_by' => [], 'notes' => [], 'why' => 'in_unraid', 'force' => false];
            }
        }
        foreach ($raw['libvirt'] as $o) {
            $vmItems[] = $o + ['id' => "{$o['kind']}:{$o['name']}", 'category' => 'orphan', 'used_by' => [], 'notes' => [],
                               'why' => $vmf['ok'] ? null : 'vm_off', 'force' => false];
        }
        [$domRefs] = clTopRefs($refs, $raw['domains']['share']);
        $vmItems = array_merge($vmItems, clFolderEntries($raw['domains']['folders']['list'], $domRefs, $vmNamed, 'domain', $vmf['ok'], $vmComplete, $cache, $pending));
        [$isoRefs] = clTopRefs($refs, $raw['isos']['share']);
        foreach (clFolderEntries($raw['isos']['files'], $isoRefs, [], 'iso', $vmf['ok'], $vmComplete, $cache, $pending) as $e) {
            $e['category'] = in_array($e['category'], ['unused', 'check'], true) ? 'media' : $e['category'];
            $vmItems[] = $e;
        }
    }

    $templates = array_map(function (array $t) use ($docker): array {
        unset($t['mnt']);
        $t['kind'] = 'template';
        $t['why'] = !$docker['ok'] ? 'docker_down' : null;
        $t['force'] = $t['category'] === 'in_use';
        return $t;
    }, $raw['templates']);

    $stacks = array_map(function (array $s) use ($docker): array {
        unset($s['sig']);
        $s['kind'] = 'stack';
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
        foreach ($run['items'] as $it) {             // parked datasets lie next to the run folder
            if ($it['zfs_path'] && $run['bytes'] !== null) {
                $ds = $cache['sizes'][$it['zfs_path']] ?? null;
                $run['bytes'] = $ds && empty($ds['error']) ? $run['bytes'] + $ds['bytes'] : null;
                $run['measuring'] = $run['measuring'] || $pending('measure:' . $it['zfs_path']);
            }
        }
        $trashBytes = $trashBytes === null || ($run['bytes'] === null && !$run['purging']) ? null : $trashBytes + (int) $run['bytes'];
        $runs[] = $run;
    }
    usort($runs, fn ($a, $b) => $b['time'] <=> $a['time']);

    $keys = array_keys($jobs['running'] + $jobs['queue']);
    $state = [
        'time'      => time(),
        'duration_ms' => $GLOBALS['clState']['duration_ms'] ?? 0,
        'host'      => hostname(),
        'docker'    => [
            'ok'         => $docker['ok'],
            'enabled'    => $docker['enabled'],
            'compose'    => $docker['compose'],
            'containers' => count($docker['containers']),
            'cache_at'   => $cache['build']['at'] ?? null,
            'list'       => clDockerEntries($raw, $cache, $pending),
        ],
        'backup_running' => backupScriptState()['running'],
        'templates' => ['dir' => CL_TEMPLATES, 'list' => array_merge($templates, $raw['strays']),
                        'strays_at' => $cache['strays']['at'] ?? null, 'strays_searching' => $pending('strays:flash') || $pending('strays:pools'),
                        'strays_skipped' => $cache['strays']['skipped'] ?? 0, 'strays_skipped_dirs' => $cache['strays']['skipped_dirs'] ?? []],
        'stacks'    => ['root' => $raw['stacks']['root'], 'exists' => $raw['stacks']['exists'], 'list' => $stacks],
        'scripts'   => $raw['scripts'] + ['dir' => CL_US_SCRIPTS],
        'appdata'   => [
            'share'    => $ad['share'],
            'places'   => array_values($ad['places']),
            'asleep'   => $ad['asleep'],
            'mounters' => $mounters,
            'complete' => $complete,
            'flash_at' => $flash['at'] ?? null,
            'list'     => $folders,
        ],
        'vms'       => [
            'enabled'  => $vmf['enabled'],
            'ok'       => $vmf['ok'],
            'count'    => count($vmf['vms']),
            'domains'  => ['share' => $raw['domains']['share'], 'places' => array_values($raw['domains']['places']), 'asleep' => $raw['domains']['asleep']],
            'isos'     => ['share' => $raw['isos']['share'], 'places' => array_values($raw['isos']['places']), 'asleep' => $raw['isos']['asleep']],
            'complete' => $vmComplete,
            'gui'      => houseGuiUrl(),
            'list'     => $vmItems,
        ],
        'trash'     => ['runs' => $runs, 'bytes' => $trashBytes],
        'icons'     => clIconState($raw['icons'] ?? [], $cache, $pending),
        'jobs'      => [
            'busy'      => count($keys),
            'flash'     => $pending('flash'),
            'measuring' => count(array_filter($keys, fn ($k) => str_starts_with($k, 'measure:'))),
            'purging'   => count(array_filter($keys, fn ($k) => str_starts_with($k, 'purge:'))),
        ],
    ];
    $GLOBALS['clState'] = $state;
    return $state;
}

/** Every path something names: [path, kind, name, weak] — containers, templates, stacks, compose files, VMs, the flash */
function clRefList(array $raw, ?array $flash): array
{
    $refs = [];
    foreach ($raw['docker']['containers'] as $c) {
        foreach ($c['binds'] as $b) {
            $refs[] = [$b, 'container', $c['name'], false];
        }
    }
    foreach ($raw['templates'] as $t) {
        foreach ($t['mnt'] as $p) {
            $refs[] = [$p, 'template', $t['file'], false];
        }
    }
    $managed = [];
    foreach ($raw['stacks']['list'] as $s) {
        foreach (array_merge([$s['project']], $s['alts']) as $p) {
            $managed[$p] = true;
        }
        foreach (array_merge($s['paths'], array_filter([$s['src'], $s['file'], $s['env'], $s['indirect']])) as $p) {
            $refs[] = [$p, 'stack', $s['folder'], false];
        }
    }
    $refs[] = [$raw['stacks']['root'], 'stack', basename($raw['stacks']['root']), false];
    foreach ($raw['compose'] as $project => $files) {
        foreach (isset($managed[$project]) ? [] : $files as $paths) {
            foreach ($paths as $p) {
                $refs[] = [$p, 'compose', (string) $project, false];
            }
        }
    }
    foreach ($raw['vms']['vms'] as $v) {
        foreach ($v['mnt'] as $p) {
            $refs[] = [$p, 'vm', $v['name'], false];
        }
    }
    foreach ($flash['lines'] ?? [] as [$file, $p]) {
        if (under($file, CL_TEMPLATES) || under($file, $raw['stacks']['root'])) {
            continue;                                  // counted above, with their names
        }
        $rel = substr($file, strlen(CL_FLASH) + 1);
        $refs[] = [$p, 'flash', $rel, (bool) preg_match(CL_WEAK, $rel)];
    }
    return $refs;
}

/** Who names which first-level entry of a share; and the containers that mount the whole share (they say nothing) */
function clTopRefs(array $refs, string $share): array
{
    $roots = $GLOBALS['clCtx']['roots'];
    $tops = [];
    $mounters = [];
    foreach ($refs as [$path, $kind, $name, $weak]) {
        if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/([^/]+))?#', rtrim($path, '/'), $m)
            || !($m[1] === 'user' || $m[1] === 'user0' || isset($roots[$m[1]])) || $m[2] !== $share) {
            continue;
        }
        $top = $m[3] ?? '';
        if ($top === '') {
            if ($kind === 'container') {
                $mounters[$name] = true;
            }
            continue;
        }
        if (str_starts_with($top, CL_TRASH) || $top === CL_LEGACY || str_starts_with($top, '_quarantaene-')) {
            continue;
        }
        $tops[$top]["$kind:$name"] ??= ['kind' => $kind, 'name' => $name, 'weak' => $weak];
        $tops[$top]["$kind:$name"]['weak'] = $tops[$top]["$kind:$name"]['weak'] && $weak;
    }
    return [$tops, array_keys($mounters)];
}

/**
 * First-level entries of a share (appdata and domains folders, disk images in
 * isos) with who names them, how big they are and what they are:
 *   used     something names it
 *   check    nothing does, but it has the same name as something, is only
 *            mentioned in caches, or was changed in the last CL_FRESH_DAYS
 *   unused   nothing names it
 *   unknown  can't tell ($known false: Docker or the VM service doesn't answer)
 */
function clFolderEntries(array $list, array $tops, array $named, string $kind, bool $known, bool $complete, array $cache, callable $pending): array
{
    $now = time();
    $out = [];
    foreach ($list as $f) {
        $by = array_values($tops[$f['name']] ?? []);
        $strong = array_values(array_filter($by, fn ($r) => !$r['weak']));
        $parts = [];
        $m = ['bytes' => 0, 'files' => 0, 'newest' => 0, 'top' => [], 'at' => PHP_INT_MAX, 'measured' => true, 'measuring' => false, 'partial' => false];
        foreach ($f['parts'] as $p) {
            if (!empty($p['file'])) {
                $size = ['bytes' => $p['bytes'], 'files' => 1, 'newest' => $p['mtime'], 'top' => [], 'at' => $now];
                $busy = false;
            } else {
                $size = $cache['sizes'][$p['path']] ?? null;
                $busy = $pending('measure:' . $p['path']);
            }
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
        $category = !$known ? 'unknown' : ($strong ? 'used' : ($notes ? 'check' : 'unused'));

        $why = null;
        if ($category === 'unknown') {
            $why = $kind === 'appdata' ? 'docker_down' : 'vm_off';
        } elseif (array_filter($f['parts'], fn ($p) => $p['dataset'] && !$p['zfs'])) {
            $why = 'dataset';
        } elseif (!$complete) {
            $why = 'checking';
        } elseif ($m['measuring']) {
            $why = 'measuring';
        } elseif ($category !== 'used' && (!$m['measured'] || $m['at'] < $now - CL_MEASURE_TTL)) {
            $why = 'measure_first';             // its newest change decides whether it is only "check"
        }
        $out[] = [
            'id'       => "$kind:" . $f['name'],
            'kind'     => $kind,
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
    return $out;
}

/** Docker's own leftovers: images no container uses (dangling or tagged), volumes no container mounts, the build cache */
function clDockerEntries(array $raw, array $cache, callable $pending): array
{
    $d = $raw['docker'];
    if (!$d['ok']) {
        return [];
    }
    $imageUsers = [];
    $volumeUsers = [];
    foreach ($d['containers'] as $c) {
        $imageUsers[$c['image_id']][] = $c['name'];
        foreach ($c['volumes'] as $v) {
            $volumeUsers[$v][] = $c['name'];
        }
    }
    // templates and stacks that name an image would need it again to be set up
    $namedBy = [];
    foreach ($raw['templates'] as $t) {
        if ($t['image'] !== '') {
            $namedBy[clNormImage($t['image'])]["template:{$t['file']}"] = ['kind' => 'template', 'name' => $t['file']];
        }
    }
    $stackOf = [];
    foreach ($raw['stacks']['list'] as $s) {
        foreach ($s['images'] as $i) {
            $namedBy[clNormImage($i['ref'])]["stack:{$s['folder']}"] = ['kind' => 'stack', 'name' => $s['folder']];
        }
        foreach (array_merge([$s['project']], $s['alts']) as $p) {
            $stackOf[$p] = $s['folder'];
        }
    }
    $users = fn (array $names) => array_map(fn ($n) => ['kind' => 'container', 'name' => $n, 'weak' => false], $names);

    $out = [];
    foreach ($d['by_id'] as $id => $img) {
        $by = $imageUsers[$id] ?? [];
        $names = [];
        foreach ($img['refs'] as $r) {
            $names += $namedBy[clNormImage($r)] ?? [];
        }
        $out[] = [
            'id' => "image:$id", 'kind' => 'image', 'image_id' => $id, 'refs' => $img['refs'],
            'name' => $img['refs'][0] ?? ($by ? $d['containers'][$by[0]]['image'] : substr(preg_replace('/^sha256:/', '', $id), 0, 12)),
            'category' => $by ? 'used' : ($img['refs'] ? 'unused' : 'dangling'),
            'bytes' => $img['bytes'], 'created' => $img['created'], 'path' => null,
            'used_by' => $users($by), 'notes' => $names ? [['why' => 'named_by', 'names' => array_values($names)]] : [],
            'why' => null, 'force' => false,
        ];
    }
    foreach ($d['volumes'] as $name => $_) {
        $name = (string) $name;
        $info = $d['volume_info'][$name] ?? ['path' => '', 'created' => null, 'anonymous' => false, 'project' => null, 'driver' => 'local'];
        $by = $volumeUsers[$name] ?? [];
        $size = $info['path'] !== '' ? ($cache['sizes'][$info['path']] ?? null) : null;
        $ok = $size && empty($size['error']);
        $notes = [];
        if ($info['project'] !== null) {
            $notes[] = ['why' => 'stack', 'name' => $stackOf[$info['project']] ?? $info['project'], 'exists' => isset($stackOf[$info['project']])];
        }
        $out[] = [
            'id' => "volume:$name", 'kind' => 'volume', 'name' => $name,
            'category' => $by ? 'used' : 'volume', 'anonymous' => $info['anonymous'], 'project' => $info['project'], 'driver' => $info['driver'],
            'bytes' => $ok ? $size['bytes'] : null, 'files' => $ok ? $size['files'] : null, 'newest' => $ok ? $size['newest'] : null,
            'measuring' => $info['path'] !== '' && $pending('measure:' . $info['path']),
            'created' => $info['created'], 'path' => $info['path'] !== '' ? $info['path'] : null,
            'used_by' => $users($by), 'notes' => $notes, 'why' => null, 'force' => false,
        ];
    }
    $b = $cache['build'] ?? null;
    if ($b && $b['reclaimable'] > 0) {
        $out[] = ['id' => 'cache:build', 'kind' => 'cache', 'name' => 'build cache', 'category' => 'cache', 'bytes' => $b['reclaimable'],
                  'total' => $b['size'], 'created' => null, 'path' => null, 'used_by' => [], 'notes' => [], 'why' => null, 'force' => false];
    }
    return $out;
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

/**
 * The trash folders: the flash (templates, stacks there), next to a compose
 * root elsewhere, in appdata, domains and isos on each pool, and in libvirt.img
 */
function clTrashRoots(array $places, array $vms): array
{
    $roots = [CL_FLASH . '/' . CL_TRASH => 'flash'];
    $compose = clComposeRoot();
    if (!under($compose, '/boot')) {
        $roots[dirname($compose) . '/' . CL_TRASH] = 'compose';
    }
    foreach ($places as $where => $share) {
        foreach ($share['places'] as $p) {
            $roots[$p['path'] . '/' . CL_TRASH] ??= $where;
        }
    }
    if ($vms['ok']) {
        $roots[CL_LIBVIRT . '/' . CL_TRASH] = 'libvirt';
    }
    // stray templates go into the storeroom of whatever share they lie in
    $ctx = $GLOBALS['clCtx'];
    foreach ($ctx['roots'] as $name => $r) {
        if ($r['kind'] === 'pool' && !clPoolAsleep($name, $ctx['asleep'])) {
            foreach (glob("/mnt/$name/*/" . CL_TRASH, GLOB_ONLYDIR) ?: [] as $d) {
                $roots[$d] ??= 'share';
            }
        }
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
        if (!clPoolAsleep($name, $ctx['asleep'])) {
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

/**
 * An "as" of a manifest entry as Ms. Dustdevil writes it: "<kind folder>/<name>"
 * (strays and icons one folder deeper), inside the run folder, the folder
 * matching the kind — or "@<pool>/…/_UnraidSecretaryOffice-trash-<this run>-<name>"
 * for a dataset. Nothing with "..", ".", empty parts or control characters.
 */
function clTrashAsOk(string $as, string $kind, string $stamp): bool
{
    if (str_starts_with($as, '@')) {
        $ds = substr($as, 1);
        return clZfsNameOk($ds) && str_contains($ds, '/') && str_starts_with(basename($ds), CL_TRASH . '-' . $stamp . '-')
            && in_array($kind, ['appdata', 'domain', 'iso'], true);
    }
    if ($as === '' || strlen($as) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $as)) {
        return false;
    }
    $parts = explode('/', $as);
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    $deep = in_array($parts[0], ['strays', 'icons'], true);
    return (CL_KINDS[$parts[0]] ?? null) === $kind && count($parts) === ($deep ? 3 : 2);
}

/** An absolute path without "..", ".", empty parts or control characters (a manifest's "from") */
function clTrashPathOk(string $path): bool
{
    if (!str_starts_with($path, '/') || strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        return false;
    }
    foreach (explode('/', substr($path, 1)) as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/** A ZFS dataset name: <pool>/<child>…, ZFS's own characters, no "." or ".." parts */
function clZfsNameOk(string $name): bool
{
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9_.:/ -]{0,250}\z#', $name)) {
        return false;
    }
    foreach (explode('/', $name) as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/** Every folder between the run folder and an entry is a real folder, no link (else a rename would reach elsewhere) */
function clRunPathOk(string $runPath, string $as): bool
{
    if (is_link($runPath) || !is_dir($runPath)) {
        return false;
    }
    $parts = explode('/', $as);
    array_pop($parts);
    $at = $runPath;
    foreach ($parts as $p) {
        $at .= "/$p";
        if (is_link($at) || !is_dir($at)) {
            return false;
        }
    }
    return true;
}

function clTrashRuns(array $places, array $vms): array
{
    $runs = [];
    $datasets = [];
    foreach (mountTable() as $m) {
        $datasets[$m['source']] = $m['mount'];
    }
    foreach (clTrashRoots($places, $vms) as $root => $where) {
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
            $runStamp = preg_replace('/\.purging$/', '', $stamp);
            foreach ((array) ($manifest['items'] ?? []) as $it) {
                // the manifest lies in a folder others may write to: only entries of the shape Ms. Dustdevil writes count
                if (!is_array($it) || !is_string($it['as'] ?? null) || !in_array($it['kind'] ?? '', CL_KINDS, true)
                    || !clTrashAsOk($it['as'], $it['kind'], $runStamp)) {
                    continue;
                }
                $known[$it['as']] = true;
                $known[dirname($it['as'])] = true;            // strays/<folder hash>/
                $zfs = str_starts_with($it['as'], '@') ? substr($it['as'], 1) : null;
                $items[] = [
                    'id'      => "$path|{$it['as']}",
                    'kind'    => $it['kind'],
                    'name'    => (string) ($it['name'] ?? basename($it['as'])),
                    'label'   => (string) ($it['label'] ?? ''),
                    'from'    => is_string($it['from'] ?? null) && clTrashPathOk($it['from']) ? $it['from'] : null,
                    'as'      => $it['as'],
                    'dataset' => $zfs !== null && is_string($it['dataset'] ?? null) && clZfsNameOk($it['dataset']) ? $it['dataset'] : null,
                    'zfs'     => $zfs,
                    'zfs_path' => $zfs !== null ? ($datasets[$zfs] ?? null) : null,
                    'present' => $zfs !== null ? isset($datasets[$zfs]) : file_exists("$path/{$it['as']}"),
                    'volumes' => array_values(array_filter((array) ($it['volumes'] ?? []), fn ($v) => is_string($v) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}\z/', $v))),
                    'images'  => array_values(array_filter((array) ($it['images'] ?? []), fn ($v) => is_string($v) && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/:@-]{0,511}\z#', $v))),
                ];
            }
            // whatever is in there without a manifest entry (shown, can't go back)
            foreach (CL_KINDS as $dir => $kind) {
                foreach (@scandir("$path/$dir") ?: [] as $n) {
                    if ($n !== '.' && $n !== '..' && !isset($known["$dir/$n"])) {
                        $items[] = ['id' => "$path|$dir/$n", 'kind' => $kind,
                                    'name' => $n, 'label' => '', 'from' => null, 'as' => "$dir/$n", 'present' => true, 'volumes' => [], 'images' => [], 'dataset' => null, 'zfs' => null, 'zfs_path' => null];
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
                                            'from' => null, 'as' => "$n/$x", 'present' => true, 'volumes' => [], 'images' => [], 'dataset' => null, 'zfs' => null, 'zfs_path' => null];
                            }
                        }
                    } else {
                        $items[] = ['id' => "$path|$n", 'kind' => 'appdata', 'name' => $n, 'label' => '', 'from' => null, 'as' => $n,
                                    'present' => true, 'volumes' => [], 'images' => [], 'dataset' => null, 'zfs' => null, 'zfs_path' => null];
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
    if (is_link($root) || is_link(dirname($root))) {
        throw new Problem('cleanup_trash_failed', ['path' => $root]);      // a link would lead the rename elsewhere
    }
    if (!is_dir($root)) {
        if (!@mkdir($root, 0775)) {
            throw new Problem('cleanup_trash_failed', ['path' => $root]);
        }
        @lchown($root, FILE_UID);
        @lchgrp($root, FILE_GID);
    }
    $stamp = date('Ymd-His');
    for ($i = 2; file_exists("$root/$stamp") || file_exists("$root/$stamp.purging"); $i++) {
        $stamp = date('Ymd-His') . "-$i";
    }
    if (!@mkdir("$root/$stamp", 0775)) {
        throw new Problem('cleanup_trash_failed', ['path' => "$root/$stamp"]);
    }
    @lchown("$root/$stamp", FILE_UID);
    @lchgrp("$root/$stamp", FILE_GID);
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
    foreach (array_merge(glob("$path/strays/*", GLOB_ONLYDIR) ?: [], glob("$path/icons/*", GLOB_ONLYDIR) ?: []) as $d) {
        @rmdir($d);
    }
    foreach (array_keys(CL_KINDS) as $dir) {
        @rmdir("$path/$dir");
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
    foreach (array_merge($state['templates']['list'], $state['stacks']['list'], $state['scripts']['list']) as $e) {
        $all[$e['id']] = $e;
    }
    foreach (array_merge($state['appdata']['list'], $state['vms']['list']) as $f) {
        $all[$f['id']] = $f;
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
                'vm_off'        => new Problem('cleanup_vm_off', $p),
                'in_unraid'     => new Problem('cleanup_in_unraid', $p),
                'office'        => new Problem('cleanup_office_script', $p),
                'running'       => new Problem('cleanup_running', $p),
                'scheduled'     => new Problem('cleanup_scheduled', $p),
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
            } elseif ($e['kind'] === 'userscript') {
                $r = $run(CL_FLASH . '/' . CL_TRASH);
                $as = 'userscripts/' . $e['folder'];
                clMove($e['dir'], $runs[$r]['path'] . "/$as");
                $runs[$r]['items'][] = ['kind' => 'userscript', 'name' => $e['folder'], 'label' => $e['name'], 'from' => $e['dir'], 'as' => $as];
            } elseif ($e['kind'] === 'stray') {
                $r = $run(clStrayTrash($e['path']));
                $as = clStrayAs($e['path']);
                clMove($e['path'], $runs[$r]['path'] . "/$as");
                $runs[$r]['items'][] = ['kind' => 'stray', 'name' => $e['file'], 'label' => $e['name'], 'from' => $e['path'], 'as' => $as];
            } elseif (in_array($e['kind'], ['nvram', 'tpm', 'snapshotdb'], true)) {
                $r = $run(CL_LIBVIRT . '/' . CL_TRASH);
                $as = $e['kind'] . '/' . $e['name'];
                clMove($e['path'], $runs[$r]['path'] . "/$as");
                $runs[$r]['items'][] = ['kind' => $e['kind'], 'name' => $e['name'], 'label' => $e['uuid'] ?? '', 'from' => $e['path'], 'as' => $as,
                                        'bytes' => $e['bytes']];
            } else {
                // appdata and domains folders, disk images: each part on its own pool or disk
                $dir = array_search($e['kind'], CL_KINDS, true);
                foreach ($e['parts'] as $p) {
                    $r = $run(dirname($p['path']) . '/' . CL_TRASH);
                    if ($p['dataset']) {
                        // a dataset can't go into a folder: it is renamed next to the trash, its manifest entry says so ("@")
                        $to = dirname($p['dataset']) . '/' . CL_TRASH . '-' . $runs[$r]['stamp'] . '-' . basename($p['dataset']);
                        clZfsRename($p['dataset'], $to, $p['path']);
                        $as = "@$to";
                    } else {
                        $as = "$dir/" . $e['name'];
                        clMove($p['path'], $runs[$r]['path'] . "/$as");
                    }
                    $runs[$r]['items'][] = ['kind' => $e['kind'], 'name' => $e['name'], 'label' => $p['root'], 'from' => $p['path'], 'as' => $as,
                                            'dataset' => $p['dataset'], 'bytes' => $p['bytes']];
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

/** Where a stray template goes in a run: strays from different folders may have the same name */
function clStrayAs(string $path): string
{
    return 'strays/' . substr(md5(dirname($path)), 0, 8) . '/' . basename($path);
}

/**
 * Takes a stray template over into Unraid's folder: a version already there
 * goes into the storeroom first, the stray is copied in (a few KB — across
 * filesystems), then the stray itself goes into the storeroom where it lies.
 * All of it can be put back.
 */
function clInstall(string $id): array
{
    $state = clScan(false, false);
    clGuard($state);
    $e = clIndex($state)[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
    if ($e['kind'] !== 'stray' || !in_array($e['loc'], ['only_here', 'newer'], true)) {
        throw new Problem('cleanup_not_needed', ['name' => $e['file'] ?? $id]);
    }
    $target = CL_TEMPLATES . '/' . $e['file'];
    $runs = [];
    $replaced = false;
    try {
        if (file_exists($target)) {
            $runs['flash'] = clRunCreate(CL_FLASH . '/' . CL_TRASH);
            clMove($target, $runs['flash']['path'] . '/templates/' . $e['file']);
            $replaced = true;
            $runs['flash']['items'][] = ['kind' => 'template', 'name' => $e['file'], 'label' => $e['name'], 'from' => $target, 'as' => 'templates/' . $e['file']];
            clManifestWrite($runs['flash']);
        }
        $tmp = CL_TEMPLATES . '/.' . $e['file'] . '.' . getmypid() . '.tmp';
        if (!@copy($e['path'], $tmp) || !@rename($tmp, $target)) {
            @unlink($tmp);
            if ($replaced && @rename($runs['flash']['path'] . '/templates/' . $e['file'], $target)) {
                $runs['flash']['items'] = [];        // put back as it was
            }
            throw new Problem('cleanup_copy_failed', ['path' => $e['path']]);
        }
        $root = clStrayTrash($e['path']);
        $key = $root === CL_FLASH . '/' . CL_TRASH ? 'flash' : 'share';
        $runs[$key] ??= clRunCreate($root);
        $as = clStrayAs($e['path']);
        clMove($e['path'], $runs[$key]['path'] . "/$as");
        $runs[$key]['items'][] = ['kind' => 'stray', 'name' => $e['file'], 'label' => $e['name'], 'from' => $e['path'], 'as' => $as];
        clManifestWrite($runs[$key]);
        logLine("Dustdevil took over {$e['path']} into Unraid's templates" . ($replaced ? ' (the older one is in the storeroom)' : ''));
    } finally {
        foreach ($runs as $x) {
            if (!$x['items']) {
                clRunTidy($x['path'], $x['root']);
            }
        }
    }
    return ['ok' => true, 'replaced' => $replaced, 'state' => clScan()];
}

/** What the user calls it: the template's file, the stack's folder, the name */
function clLabel(array $e): string
{
    return match ($e['kind']) {
        'template', 'stray' => $e['file'],
        'stack', 'userscript' => $e['folder'],
        default    => $e['name'],
    };
}

/** zfs rename (its snapshots go along); the empty folder ZFS may leave at the old mountpoint goes too */
function clZfsRename(string $from, string $to, string $oldPath): void
{
    [$exit, , $err] = run(['zfs', 'rename', $from, $to], 120);
    if ($exit !== 0) {
        throw new Problem('cleanup_move_failed', ['path' => $from, 'detail' => trim($err)]);
    }
    if (is_dir($oldPath) && !array_diff(@scandir($oldPath) ?: [], ['.', '..'])) {
        @rmdir($oldPath);
    }
}

/** Rename only — the trash is on the same filesystem; anything else is refused, never copied */
function clMove(string $from, string $to): void
{
    if (file_exists($to)) {
        throw new Problem('cleanup_target_exists', ['path' => $to]);
    }
    $dir = dirname($to);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            throw new Problem('cleanup_trash_failed', ['path' => $dir]);
        }
        foreach ([$dir, dirname($dir)] as $d) {           // like the run folder: nobody:users
            @lchown($d, FILE_UID);
            @lchgrp($d, FILE_GID);
        }
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
            'template'   => CL_TEMPLATES,
            'stack'      => $state['stacks']['root'],
            'nvram'      => CL_LIBVIRT . '/qemu/nvram',
            'tpm'        => CL_LIBVIRT . '/qemu/swtpm/tpm-states',
            'snapshotdb' => CL_LIBVIRT . '/qemu/snapshotdb',
            'userscript' => CL_US_SCRIPTS,
            'icon'       => clIconHome($it['from'], $state['stacks']['root']),
            'stray'      => preg_match('#/my-[^/]+\.xml$#', $it['from'])
                            && under($it['from'], under($run['root'], '/boot') ? '/boot' : dirname($run['root'])) ? dirname($it['from']) : '',
            default      => dirname($run['root']),          // appdata, domains, isos: the share on that pool
        };
        $zfs = $it['zfs'];
        if (dirname($it['from']) !== $home || basename($it['from']) !== basename($zfs !== null ? (string) $it['dataset'] : $it['as'])
            || ($zfs !== null && (!$it['dataset'] || dirname($it['dataset']) !== dirname($zfs)))) {
            throw new Problem('cleanup_no_way_back', ['name' => $it['name']]);
        }
        if ($zfs === null && !clRunPathOk($run['path'], $it['as'])) {
            throw new Problem('cleanup_no_way_back', ['name' => $it['name']]);
        }
        try {
            if ($zfs !== null && is_dir($it['from']) && !array_diff(@scandir($it['from']) ?: [], ['.', '..'])) {
                @rmdir($it['from']);               // the empty mountpoint folder ZFS left behind
            }
            if (file_exists($it['from']) && $it['kind'] !== 'icon') {        // a picture's file was changed, not moved: it is there
                throw new Problem('cleanup_target_exists', ['path' => $it['from']]);
            }
            if (!is_dir($home)) {
                throw new Problem('cleanup_no_home', ['path' => $home]);
            }
            if ($it['kind'] === 'icon') {
                $entry = [];
                foreach ((array) ((readJson($run['path'] . '/manifest.json') ?? [])['items'] ?? []) as $m) {
                    if (is_array($m) && ($m['as'] ?? null) === $it['as']) {
                        $entry = $m;
                    }
                }
                clIconPutBack($run['path'] . '/' . $it['as'], ['from' => $it['from']] + $entry);
            } elseif ($zfs !== null) {
                [$exit, , $err] = run(['zfs', 'rename', $zfs, $it['dataset']], 120);
                if ($exit !== 0) {
                    throw new Problem('cleanup_move_failed', ['path' => $it['from'], 'detail' => trim($err)]);
                }
            } elseif (!@rename($run['path'] . '/' . $it['as'], $it['from'])) {
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
            if ($it['kind'] === 'stray') {             // back in the list right away, not only after the next search
                $cache = clCache();
                $cache['strays']['paths'] = array_values(array_unique(array_merge($cache['strays']['paths'] ?? [], [$it['from']])));
                clSaveCache($cache);
            }
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
        // datasets of the run first (with their snapshots); the run's folder only when they are gone
        foreach ($run['items'] as $it) {
            if ($it['zfs'] !== null && $it['present'] && str_contains(basename($it['zfs']), CL_TRASH . '-')) {
                [$exit, , $err] = run(['zfs', 'destroy', '-r', $it['zfs']], 600);
                if ($exit !== 0) {
                    $results[] = ['id' => $run['id'], 'ok' => false, 'error' => ['key' => 'cleanup_destroy_failed', 'params' => ['name' => $it['zfs'], 'detail' => trim($err)]]];
                    continue 2;
                }
                logLine("Dustdevil destroyed {$it['zfs']}");
            }
        }
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

/** Docker's leftovers can't be put away (Docker can't rename them): removed for good, never forced */
function clRemove(array $ids): array
{
    $state = clScan(false, false);
    clGuard($state);
    $all = [];
    foreach ($state['docker']['list'] as $e) {
        $all[$e['id']] = $e;
    }
    $todo = [];
    foreach ($ids as $id) {
        $e = $all[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
        if ($e['category'] === 'used') {
            throw new Problem('cleanup_in_use', ['name' => $e['name']]);
        }
        $todo[] = $e;
    }
    $docker = bin('docker') ?? throw new Problem('cleanup_docker_down', ['name' => $todo[0]['name'] ?? '']);
    $results = [];
    foreach ($todo as $e) {
        // a tagged image goes by its tags (removing the id would refuse while it has several), a dangling one by its id
        $command = match ($e['kind']) {
            'image'  => array_merge([$docker, 'image', 'rm'], $e['refs'] ?: [$e['image_id']]),
            'volume' => [$docker, 'volume', 'rm', $e['name']],
            'cache'  => [$docker, 'builder', 'prune', '-f'],
        };
        [$exit, , $err] = run($command, 300);
        if ($exit === 0) {
            $results[] = ['id' => $e['id'], 'ok' => true];
            logLine("Dustdevil removed Docker's {$e['kind']} {$e['name']}" . ($e['bytes'] ? ' (' . clHuman($e['bytes']) . ')' : ''));
        } else {
            $results[] = ['id' => $e['id'], 'ok' => false,
                          'error' => ['key' => 'cleanup_docker_failed', 'params' => ['name' => $e['name'], 'detail' => trim(substr($err, -300))]]];
        }
    }
    $cache = clCache();
    unset($cache['build']);                    // ask Docker again
    clSaveCache($cache);
    return ['ok' => true, 'results' => $results, 'state' => clScan()];
}

/** Measures folders, volumes or trash runs again, when asked */
function clMeasure(array $ids): array
{
    $state = $GLOBALS['clState'] ?? clScan();
    $paths = [];
    foreach (array_merge($state['appdata']['list'], $state['vms']['list']) as $f) {
        if (in_array($f['id'], $ids, true)) {
            foreach ($f['parts'] ?? [] as $p) {
                if (empty($p['file'])) {
                    $paths[] = $p['path'];
                }
            }
        }
    }
    foreach ($state['docker']['list'] as $e) {
        if (in_array($e['id'], $ids, true) && $e['kind'] === 'volume' && $e['path']) {
            $paths[] = $e['path'];
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
        'template', 'stray' => [$e['path']],
        'userscript' => $e['exists'] ? [$e['path']] : [],
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

// --------------------------------------------------------------------- pictures

/** The pictures the user chose: id => URL */
function clIconItems(array $r): array
{
    $items = $r['items'] ?? null;
    if (!is_array($items) || !$items || count($items) > 500) {
        throw new Problem('no_selection');
    }
    $out = [];
    foreach ($items as $it) {
        $id = is_array($it) ? ($it['id'] ?? null) : null;
        $url = is_array($it) ? ($it['url'] ?? null) : null;
        if (!is_string($id) || !is_string($url) || $id === '' || strlen($id) > 300 || strlen($url) > 1000) {
            throw new Problem('invalid_selection');
        }
        $out[$id] = trim($url);
    }
    return $out;
}

/** A picture address the user typed: http(s), nothing that could break the XML or YAML it goes into */
function clIconUrlOk(string $url): bool
{
    return strlen($url) <= 500 && preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[^\s"\'<>\\\\`{}|^]*)?$#', $url) === 1;
}

/**
 * Hangs the chosen pictures: each picture is fetched and checked first (a real
 * PNG, at most 1 MB) — nothing is written for a container whose picture isn't.
 * Then per file: the container's user template gets its <Icon>, or the stack's
 * override file the icon label for those services (and Compose Manager's
 * icon_url, if it has none). The old file goes into the storeroom (kind
 * "icon"), so "put back" undoes it. Finally Unraid's cache gets the picture,
 * so it shows right away. Nothing is restarted or re-created.
 */
function clIconsApply(array $items): array
{
    $state = clScan(false, false);
    clGuard($state);
    $all = array_column($state['icons']['list'] ?? [], null, 'id');
    $todo = [];
    foreach ($items as $id => $url) {
        $e = $all[$id] ?? throw new Problem('unknown_target', ['target' => $id]);
        $p = ['name' => $e['name']];
        if ($e['category'] === 'ok') {
            throw new Problem('cleanup_icon_has', $p);
        }
        if ($e['why'] !== null || $e['category'] === 'none') {
            throw match ($e['why']) {
                'asleep'            => new Problem('cleanup_asleep', $p),
                'manual'            => new Problem('cleanup_icon_manual', $p),
                'indirect_override' => new Problem('cleanup_icon_indirect', $p),
                'no_service'        => new Problem('cleanup_icon_no_service', $p),
                default             => new Problem('cleanup_icon_no_way', $p),
            };
        }
        // one of the pictures found (a local one only as found), or an address the user typed
        if (!in_array($url, array_column($e['candidates'], 'url'), true) && !clIconUrlOk($url)) {
            throw new Problem('cleanup_icon_bad_url', ['url' => $url]);
        }
        $todo[$id] = $e + ['pick' => $url];
    }

    $web = array_values(array_unique(array_filter(array_column($todo, 'pick'), fn ($u) => !str_starts_with($u, 'file://'))));
    $dir = RUN_DIR . '/cleanup-icons-' . getmypid();
    $got = [];
    if ($web) {
        [, $out] = run(clNet(clIconFetchCommand($web, $dir)), 60);
        $got = clIconFetchResults($out, $web, $dir);
    }
    $results = [];
    $groups = [];
    foreach ($todo as $id => $e) {
        if (str_starts_with($e['pick'], 'file://')) {
            $file = substr($e['pick'], 7);
            $got[$e['pick']] = clIconLocal($e['pick']) !== null ? ['ok' => true, 'file' => $file] : ['ok' => false];
        }
        if (!($got[$e['pick']]['ok'] ?? false)) {
            $results[] = ['id' => $id, 'ok' => false, 'error' => ['key' => 'cleanup_icon_fetch_failed', 'params' => ['name' => $e['name'], 'url' => $e['pick']]]];
            continue;
        }
        $groups[$e['path']][] = $e + ['png' => $got[$e['pick']]['file']];
    }

    $root = $state['stacks']['root'];
    $runs = [];
    try {
        foreach ($groups as $path => $list) {
            $first = $list[0];
            try {
                $old = is_file($path) ? @file_get_contents($path, false, null, 0, 1 << 20) : null;
                if ($old === false || ($old === null && $first['category'] === 'template')) {
                    throw new Problem('cleanup_icon_write_failed', ['path' => $path]);
                }
                if ($first['category'] === 'template') {
                    $new = clXmlSetIcon($old, $first['pick'], $path);
                    $trash = CL_FLASH . '/' . CL_TRASH;
                } else {
                    $new = clOverrideSetIcons($old ?? clOverrideTemplate(), array_column($list, 'pick', 'service'), $path);
                    $trash = under($root, '/boot') ? CL_FLASH . '/' . CL_TRASH : dirname($root) . '/' . CL_TRASH;
                }
                $runs[$trash] ??= clRunCreate($trash);
                $as = 'icons/' . substr(md5(dirname($path)), 0, 8) . '/' . basename($path);
                clIconReplace($path, $new, $runs[$trash]['path'] . "/$as", $old === null ? clOverrideTemplate() : null);
                $item = ['kind' => 'icon', 'name' => basename($path), 'label' => implode(', ', array_column($list, 'name')), 'from' => $path,
                         'as' => $as, 'written' => md5($new), 'was' => $old === null ? 'missing' : 'there',
                         'icons' => array_column($list, 'pick', 'name'), 'cache' => []];
                foreach ($list as $e) {
                    $item['cache'] += clIconSeed($e['name'], $e['png']);
                }
                if ($first['category'] === 'compose') {
                    $item['icon_url'] = clIconUrlFile(dirname($path), $list);
                }
                $runs[$trash]['items'][] = $item;
                clManifestWrite($runs[$trash]);
                foreach ($list as $e) {
                    $results[] = ['id' => $e['id'], 'ok' => true, 'how' => $e['category']];
                }
                logLine("Dustdevil hung pictures for {$item['label']} ($path; the old file is in the storeroom)");
            } catch (Problem $p) {
                foreach ($list as $e) {
                    $results[] = ['id' => $e['id'], 'ok' => false, 'error' => $p->toArray()];
                }
                logLine("Dustdevil could not hang pictures in $path: " . $p->getMessage());
            }
        }
    } finally {
        clIconTidy($dir);
        foreach ($runs as $x) {
            if (!$x['items']) {
                clRunTidy($x['path'], $x['root']);
            }
        }
    }
    return ['ok' => true, 'results' => $results, 'state' => clScan()];
}

/**
 * The template with its <Icon> set — only that element changes (inserted after
 * <Repository> if it has none). Refuses a file that doesn't look like Unraid's.
 */
function clXmlSetIcon(string $xml, string $url, string $where): string
{
    $shape = new Problem('cleanup_icon_shape', ['path' => $where]);
    if (!preg_match('#<Container\b#', $xml) || !preg_match('#</Container>\s*$#', $xml)) {
        throw $shape;
    }
    $element = '<Icon>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Icon>';
    $plain = '#<Icon\s*/>|<Icon>[^<]*</Icon>#';
    $n = preg_match_all($plain, $xml);
    if ($n > 1 || preg_match_all('#<Icon\b#', $xml) !== $n) {
        throw $shape;                              // two of them, attributes, CDATA …
    }
    if ($n === 1) {
        return preg_replace_callback($plain, fn () => $element, $xml, 1);
    }
    if (!preg_match('#^([ \t]*)<Repository>[^<]*</Repository>[ \t]*(\r?\n)#m', $xml, $m, PREG_OFFSET_CAPTURE)) {
        throw $shape;
    }
    $at = $m[0][1] + strlen($m[0][0]);
    return substr($xml, 0, $at) . $m[1][0] . $element . $m[2][0] . substr($xml, $at);
}

/** A YAML mapping key, without its quotes */
function clYamlKey(string $key): string
{
    $q = $key[0] ?? '';
    if (strlen($key) >= 2 && ($q === '"' || $q === "'") && $key[-1] === $q) {
        return $q === "'" ? str_replace("''", "'", substr($key, 1, -1)) : stripcslashes(substr($key, 1, -1));
    }
    return $key;
}

/**
 * Sets net.unraid.docker.icon for some services in a Compose Manager override
 * file, in the shape Compose Manager writes it (js-yaml: two spaces, single
 * quotes): an existing icon line gets the new value, a service without one gets
 * the line in its labels, a service the file doesn't have yet gets the block
 * Compose Manager would give it. Everything else stays as it is. No YAML
 * library: what isn't that plain shape (tabs, other indentation, flow style
 * other than "services: {}" or "labels: {}", anchors, tags, labels as a list)
 * is refused rather than guessed.
 *
 * @param array<string, string> $icons service => picture URL
 */
function clOverrideSetIcons(string $text, array $icons, string $where): string
{
    $shape = fn (): Problem => new Problem('cleanup_icon_shape', ['path' => $where]);
    $eol = str_contains($text, "\r\n") ? "\r\n" : "\n";
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    if (end($lines) === '') {
        array_pop($lines);
    }
    $quote = fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
    $key = '(\'[^\']*\'|"[^"]*"|[A-Za-z0-9_.\/-]+)';
    $plainValue = fn (string $v): bool => $v === '' || $v[0] === '#' || preg_match('/^(\'([^\']|\'\')*\'|"([^"\\\\]|\\\\.)*"|[^&*!|>{}\[\]\'"#%@`\s][^#]*?)(\s+#.*)?$/', $v) === 1;

    $services = null;          // line of "services:"
    $flow = false;             // "services: {}"
    $blocks = [];              // service => [start, end, labels line, labels flow?, icon line, managed line, last label line]
    $top = null;
    $svc = null;
    $sub = null;
    $last = null;              // last line that belongs to services
    foreach ($lines as $i => $line) {
        if (str_contains($line, "\t")) {
            throw $shape();
        }
        if (trim($line) === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        $indent = strlen($line) - strlen(ltrim($line, ' '));
        $body = rtrim(substr($line, $indent));
        if ($indent === 0) {
            if (!preg_match('/^' . $key . '\s*:(.*)$/', $body, $m)) {
                throw $shape();
            }
            $top = clYamlKey($m[1]);
            $svc = $sub = null;
            if ($top === 'services') {
                $rest = trim(preg_replace('/(^|\s)#.*$/', '', $m[2]));
                if ($services !== null || ($rest !== '' && $rest !== '{}')) {
                    throw $shape();
                }
                $services = $last = $i;
                $flow = $rest === '{}';
            }
            continue;
        }
        if ($top !== 'services') {
            continue;                              // networks, volumes, x-… stay as they are
        }
        if ($flow) {
            throw $shape();
        }
        $last = $i;
        if ($indent === 2) {
            if (!preg_match('/^' . $key . '\s*:\s*(#.*)?$/', $body, $m)) {
                throw $shape();
            }
            $svc = clYamlKey($m[1]);
            $sub = null;
            if (isset($blocks[$svc])) {
                throw $shape();
            }
            $blocks[$svc] = ['start' => $i, 'end' => $i, 'labels' => null, 'flow' => false, 'icon' => null, 'managed' => null, 'last' => null];
            continue;
        }
        if ($svc === null || $indent < 4 || $indent % 2) {
            throw $shape();
        }
        $blocks[$svc]['end'] = $i;
        if ($indent === 4) {
            if (!preg_match('/^' . $key . '\s*:(.*)$/', $body, $m) || clYamlKey($m[1]) === '<<') {
                throw $shape();
            }
            $sub = clYamlKey($m[1]);
            if ($sub === 'labels') {
                $rest = trim(preg_replace('/(^|\s)#.*$/', '', $m[2]));
                if ($blocks[$svc]['labels'] !== null || ($rest !== '' && $rest !== '{}')) {
                    throw $shape();
                }
                $blocks[$svc]['labels'] = $blocks[$svc]['last'] = $i;
                $blocks[$svc]['flow'] = $rest === '{}';
            }
            continue;
        }
        if ($sub !== 'labels') {
            continue;                              // environment, volumes … of the service: not mine
        }
        // a label: "      key: value", nothing deeper, no list
        if ($indent !== 6 || $blocks[$svc]['flow'] || !preg_match('/^' . $key . ':(?:\s+(.*))?$/', $body, $m)
            || clYamlKey($m[1]) === '<<' || !$plainValue($m[2] ?? '')) {
            throw $shape();
        }
        $blocks[$svc]['last'] = $i;
        if (clYamlKey($m[1]) === CL_ICON_LABEL) {
            $blocks[$svc]['icon'] = ['line' => $i, 'key' => $m[1]];
        } elseif (clYamlKey($m[1]) === 'net.unraid.docker.managed') {
            $blocks[$svc]['managed'] = $i;
        }
    }

    $replace = [];             // line => new line
    $after = [];               // line => lines to add after it (-1: at the end)
    $added = [];               // services the file doesn't have yet
    foreach ($icons as $service => $url) {
        $service = (string) $service;
        $label = '      ' . CL_ICON_LABEL . ': ' . $quote($url);
        $b = $blocks[$service] ?? null;
        if ($b === null) {
            $name = preg_match('/^(\d+(\.\d+)?|y|n|yes|no|true|false|null|on|off|~)$/i', $service) ? $quote($service) : $service;
            array_push($added, "  $name:", '    labels:', "      net.unraid.docker.managed: 'composeman'", $label);
        } elseif ($b['icon'] !== null) {
            $replace[$b['icon']['line']] = '      ' . $b['icon']['key'] . ': ' . $quote($url);
        } elseif ($b['labels'] !== null && $b['flow']) {
            $replace[$b['labels']] = '    labels:';
            $after[$b['labels']][] = $label;
        } elseif ($b['labels'] !== null) {
            $after[$b['managed'] ?? $b['labels']][] = $label;
        } else {
            $after[$b['end']] = array_merge($after[$b['end']] ?? [], ['    labels:', $label]);
        }
    }
    if ($added) {
        if ($services === null) {
            $after[-1] = array_merge(['services:'], $added);
        } elseif ($flow) {
            $replace[$services] = 'services:';
            $after[$services] = $added;
        } else {
            $after[$last] = array_merge($after[$last] ?? [], $added);
        }
    }
    $out = [];
    foreach ($lines as $i => $line) {
        $out[] = $replace[$i] ?? $line;
        foreach ($after[$i] ?? [] as $l) {
            $out[] = $l;
        }
    }
    foreach ($after[-1] ?? [] as $l) {
        $out[] = $l;
    }
    return implode($eol, $out) . $eol;
}

/**
 * Swaps a small file for its new version: the new one is written next to it
 * first, the old one is renamed into the storeroom ($stash), then the new one
 * takes its place — on the flash one small write and two renames. When there
 * was none, the storeroom gets $placeholder (what Compose Manager starts a
 * stack's override file with), so the lot shows what it is about.
 */
function clIconReplace(string $target, string $content, string $stash, ?string $placeholder = null): void
{
    // a new file of our own: a stack's folder may lie in a share others can write to
    $tmp = writeNewFile(dirname($target) . '/.' . basename($target), $content, is_file($target) ? (fileperms($target) & 0777) : 0644);
    if ($tmp === null) {
        throw new Problem('cleanup_icon_write_failed', ['path' => $target]);
    }
    try {
        if ($placeholder === null) {
            clMove($target, $stash);
        } else {
            if (file_exists($target) || file_exists($stash)) {
                throw new Problem('cleanup_target_exists', ['path' => file_exists($target) ? $target : $stash]);
            }
            if ((!is_dir(dirname($stash)) && !@mkdir(dirname($stash), 0775, true)) || @file_put_contents($stash, $placeholder) === false) {
                throw new Problem('cleanup_trash_failed', ['path' => dirname($stash)]);
            }
        }
    } catch (Problem $p) {
        @unlink($tmp);
        throw $p;
    }
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        if ($placeholder === null) {
            @rename($stash, $target);             // as it was
        } else {
            @unlink($stash);
        }
        throw new Problem('cleanup_icon_write_failed', ['path' => $target]);
    }
}

/**
 * Puts a picture into Unraid's cache, so its Docker page and Dashboard show it
 * now (until the container is updated, when Unraid fetches it anew): both
 * copies of <name>-icon.png, and the path in docker.json — like Compose
 * Manager does it. Unraid's folders are never created. @return array<string, string> file => md5
 */
function clIconSeed(string $name, string $png, array $dirs = [CL_DM_RAM, CL_DM_DISK], string $json = CL_DM_JSON, string $web = CL_DM_WEB): array
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/', $name) || !clIsPicture($png, true)) {
        return [];
    }
    $done = [];
    $sum = (string) md5_file($png);
    foreach ($dirs as $dir) {
        $tmp = "$dir/.$name-icon.png." . getmypid();
        if (is_dir($dir) && @copy($png, $tmp) && @chmod($tmp, 0644) && @rename($tmp, "$dir/$name-icon.png")) {
            $done["$dir/$name-icon.png"] = $sum;
        } else {
            @unlink($tmp);
        }
    }
    if (isset($done[$dirs[0] . "/$name-icon.png"]) && is_dir(dirname($json))) {
        clIconPoint($json, $name, "$web/$name-icon.png");
    }
    return $done;
}

/** docker.json: the container's picture is this one (Unraid keeps what it remembers as long as the file is there) */
function clIconPoint(string $json, string $name, string $web): void
{
    $h = @fopen($json, 'c+');
    if (!$h) {
        return;
    }
    if (flock($h, LOCK_EX)) {
        $raw = (string) stream_get_contents($h);
        $info = trim($raw) === '' ? [] : json_decode($raw, true);
        if (is_array($info) && ($info[$name]['icon'] ?? null) !== $web) {     // a file Unraid is just writing: left alone
            $info[$name] = (is_array($info[$name] ?? null) ? $info[$name] : []);
            $info[$name]['icon'] = $web;
            rewind($h);
            ftruncate($h, 0);
            fwrite($h, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($h);
        }
        flock($h, LOCK_UN);
    }
    fclose($h);
}

/**
 * Compose Manager's own picture for the stack (in its list), when it has none:
 * the stack's app, not its database — only when the app is among them.
 * @return ?array what to undo: path, md5, was (missing / empty)
 */
function clIconUrlFile(string $dir, array $list): ?array
{
    $file = "$dir/icon_url";
    $was = is_file($file) ? trim((string) @file_get_contents($file, false, null, 0, 4096)) : null;
    if ($was !== null && $was !== '') {
        return null;
    }
    foreach ($list as $e) {
        if (!str_starts_with($e['pick'], 'file://') && !preg_match(CL_ICON_HELPERS, (string) $e['service'])) {
            if (@file_put_contents($file, $e['pick']) === false) {
                return null;
            }
            return ['path' => $file, 'md5' => md5($e['pick']), 'was' => $was === null ? 'missing' : 'empty'];
        }
    }
    return null;
}

/** Where a picture's file may be put back to: a user template, or the override file of a stack in Compose Manager's folder */
function clIconHome(string $from, string $composeRoot): string
{
    if (dirname($from) === CL_TEMPLATES && preg_match('/^my-[^\/]+\.xml$/', basename($from))) {
        return CL_TEMPLATES;
    }
    return in_array(basename($from), CL_OVERRIDE_FILES, true) && dirname($from, 2) === $composeRoot ? dirname($from) : '';
}

/**
 * Puts a picture's file back: the old version replaces the one I wrote — only
 * while that is unchanged (Unraid rewrites a template whenever the container
 * is edited; that wouldn't be mine to throw away). What I added beside it goes
 * too, as far as nobody changed it: icon_url, the copies in Unraid's cache.
 */
function clIconPutBack(string $stash, array $m, array $dirs = [CL_DM_RAM, CL_DM_DISK]): void
{
    $from = (string) $m['from'];
    if (!is_file($from) || md5_file($from) !== ($m['written'] ?? null)) {
        throw new Problem('cleanup_icon_changed', ['path' => $from]);
    }
    if (($m['was'] ?? 'there') === 'missing') {
        if (!@unlink($from)) {
            throw new Problem('cleanup_move_failed', ['path' => $from, 'detail' => error_get_last()['message'] ?? '']);
        }
        @unlink($stash);
    } elseif (!@rename($stash, $from)) {
        throw new Problem('cleanup_move_failed', ['path' => $from, 'detail' => error_get_last()['message'] ?? '']);
    }
    $u = $m['icon_url'] ?? null;
    if (is_array($u) && is_string($u['path'] ?? null) && $u['path'] === dirname($from) . '/icon_url'
        && is_file($u['path']) && md5_file($u['path']) === ($u['md5'] ?? null)) {
        ($u['was'] ?? '') === 'empty' ? @file_put_contents($u['path'], '') : @unlink($u['path']);
    }
    foreach ((array) ($m['cache'] ?? []) as $file => $sum) {
        if (in_array(dirname((string) $file), $dirs, true) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*-icon\.png$/', basename((string) $file))
            && is_file((string) $file) && md5_file((string) $file) === $sum) {
            @unlink((string) $file);
        }
    }
}

/** Unraid 7.3.2: a stand-in for the question.png it doesn't ship, in RAM (gone after a reboot) — ends the reload loop */
function clIconFallback(): array
{
    $risk = cleanupIconLoopRisk();
    if (!$risk['affected'] || !$risk['fallback_missing']) {
        throw new Problem('cleanup_icon_fallback_not_needed');
    }
    $src = clIconStandIn() ?? throw new Problem('cleanup_icon_fallback_failed', ['path' => CL_DM_FALLBACK]);
    $dir = dirname(CL_DM_FALLBACK);
    $tmp = "$dir/.question.png." . getmypid();
    if (!is_dir($dir) || !@copy($src, $tmp) || !@chmod($tmp, 0644) || !@rename($tmp, CL_DM_FALLBACK)) {
        @unlink($tmp);
        throw new Problem('cleanup_icon_fallback_failed', ['path' => CL_DM_FALLBACK]);
    }
    logLine("Dustdevil put a stand-in for Unraid's missing question.png in place (from $src; RAM, gone after a reboot)");
    return ['ok' => true, 'state' => clScan()];
}

// ===================================================================== checks

function clChecks(): array
{
    $s = $GLOBALS['clState'] ?? clScan();
    $out = [];
    if ($s['docker']['enabled'] ?? true) {
        $out[] = finding('docker', 'required', $s['docker']['ok'] ?? null, [], 'docker');
    }
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
    if (!isset($GLOBALS['clCache'])) {
        $cache = readJson(clCacheFile()) ?? [];
        if (($cache['format'] ?? 1) !== CL_CACHE_FORMAT) {
            $cache = ['format' => CL_CACHE_FORMAT];       // measured differently before: measure again
        }
        $GLOBALS['clCache'] = $cache + ['flash' => null, 'sizes' => [], 'compose' => [], 'icons' => []];
    }
    return $GLOBALS['clCache'];
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
    // files, bytes on disk (blocks: a sparse vdisk counts what it really takes) and newest change;
    // and the five newest files (by tab: time, 512-byte blocks, path)
    $awk = 'BEGIN{FS="\t"} {c++; s+=$2*512; t=int($1); if (t>m) m=t;'
         . ' for (i=1; i<=5; i++) if (!(i in T) || t>T[i]) { for (j=5; j>i; j--) if ((j-1) in T) { T[j]=T[j-1]; P[j]=P[j-1] } T[i]=t; P[i]=$3; break } }'
         . ' END{printf "%d\t%.0f\t%d\n", c, s, m; for (i=1; i<=5; i++) if (i in T) printf "%d\t%s\n", T[i], P[i]}';
    $nice = bin('ionice') ? ['nice', '-n', '10', 'ionice', '-c', '3'] : ['nice', '-n', '10'];
    clJobAdd('measure:' . $path, 'measure', [
        array_merge($nice, ['find', $path, '-xdev', '-type', 'f', '-printf', "%T@\t%b\t%P\n"]),
        ['awk', $awk],
    ], 3600);
}

/** $first: purges and the flash search don't wait behind measurements; $extra goes along to clJobDone() */
function clJobAdd(string $key, string $type, array $pipeline, int $timeout, bool $first = false, array $extra = []): void
{
    $jobs = &$GLOBALS['clJobs'];
    if (isset($jobs['running'][$key]) || isset($jobs['queue'][$key])) {
        return;
    }
    $job = ['key' => $key, 'type' => $type, 'pipeline' => $pipeline, 'timeout' => $timeout, 'first' => $first] + $extra;
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
    $out = RUN_DIR . '/cleanup-' . getmypid() . '-' . md5($job['key']) . '.out';
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
    } elseif ($job['type'] === 'strays') {
        // backups (the office's own, anything called backup) hold copies on purpose: never strays
        $paths = [];
        $skipped = [];
        foreach (explode("\n", trim($text)) as $p) {
            if ($p === '' || under($p, CL_TEMPLATES) || under($p, CL_FLASH . '/plugins/dockerMan/templates')) {
                continue;
            }
            if (preg_match('#^/mnt/[^/]+/' . preg_quote(BACKUP_OFFICE_SHARE, '#') . '/#', $p) || preg_match('#/[^/]*backup[^/]*/#i', $p)) {
                $skipped[dirname($p)] = ($skipped[dirname($p)] ?? 0) + 1;
                continue;
            }
            $paths[] = $p;
        }
        // two searches (flash, pools): each keeps its own findings, together they are the list
        $which = substr($key, 7);
        $cache['strays']['found'][$which] = ['paths' => $paths, 'skipped' => $skipped];
        $all = array_values($cache['strays']['found']);
        $skippedAll = array_merge(...array_column($all, 'skipped'));
        $cache['strays']['at'] = time();
        $cache['strays']['paths'] = array_values(array_unique(array_merge(...array_column($all, 'paths'))));
        $cache['strays']['skipped'] = array_sum($skippedAll);
        $cache['strays']['skipped_dirs'] = array_slice(array_keys($skippedAll), 0, 10);
        logLine(sprintf('Dustdevil looked for stray templates (%s): %d found, %d in backups left out (%d s)', $which, count($paths), array_sum($skipped), $took));
    } elseif ($job['type'] === 'cache') {
        // "Build Cache  237.2MB  95.67MB" — Docker counts in powers of 1000
        $bytes = function (string $s): int {
            $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5];
            return preg_match('/([\d.]+)\s*([kKMGTP]?)B/', $s, $m) ? (int) round((float) $m[1] * 1000 ** $power[strtoupper($m[2])]) : 0;
        };
        $cache['build'] = ['at' => time(), 'size' => 0, 'reclaimable' => 0];    // also when Docker says nothing: ask again only later
        foreach (rows($text) as $f) {
            if (count($f) >= 3 && stripos($f[0], 'build') === 0) {
                $cache['build'] = ['at' => time(), 'size' => $bytes($f[1]), 'reclaimable' => $bytes($f[2])];
            }
        }
    } elseif ($job['type'] === 'icons') {
        // pictures checked the way Unraid's downloader will fetch them later
        $now = time();
        $good = 0;
        foreach (clIconFetchResults($text, $job['urls'], $job['dir']) as $url => $r) {
            $cache['icons'][$url] = ['at' => $now, 'ok' => $r['ok'], 'why' => $r['why']];
            $good += $r['ok'] ? 1 : 0;
        }
        foreach ($cache['icons'] as $url => $r) {
            if ($now - (int) ($r['at'] ?? 0) > 30 * 86400) {
                unset($cache['icons'][$url]);
            }
        }
        clIconTidy($job['dir']);
        logLine(sprintf('Dustdevil checked %d pictures: %d load (%d s)', count($job['urls']), $good, $took));
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
