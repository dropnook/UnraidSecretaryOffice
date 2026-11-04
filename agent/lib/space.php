<?php
declare(strict_types=1);

/*
 * «Storage» on Ms. Dustdevil's «Where is what» (2026-11-04, the owner: every pool, SSD, HDD and share with how full it
 * is, read only, drawn, with as many nerdy details as the server gives for free) — the part «space»
 * (DATA_DIR/cleanup-space.json), its history DATA_DIR/cleanup-space-history.jsonl (one line a day at most per pool, disk,
 * the array and the boot device; SPACE_HISTORY_DAYS kept).
 *
 * Read only, and only what is awake: disks.ini's numbers (Unraid keeps them without waking a disk — a sleeping disk keeps
 * them and nothing else is asked of it), zpool list / zpool status (in memory) for every pool, zfs list -r for the awake
 * ones only, btrfs filesystem usage for an awake btrfs pool, statvfs of Docker's own file system, `docker system df` at
 * most every SPACE_DOCKER_AGE s and only while what holds Docker's data is awake. Never a du of its own: a share's or a
 * folder's size comes from ZFS (its dataset) or a du the page asked for («where-sizes»), else it is «not measured» (null,
 * never 0). Ms. Moverelli's split of her pool comes from her state (her lib, her numbers), the snapshot counts from Ms.
 * Snapshotini's — their files, never their desks. No SMART call (smartctl wakes disks): temperatures from disks.ini.
 *
 * spaceRead() gathers (the host), spaceBuild() assembles (pure: the tests give it what spaceRead() would), and
 * spaceHistoryAdd() keeps the history.
 */

const SPACE_FILE          = 'cleanup-space.json';            // in DATA_DIR: the part «space»
const SPACE_HISTORY_FILE  = 'cleanup-space-history.jsonl';   // in DATA_DIR: one point a day per thing
const SPACE_HISTORY_DAYS  = 400;
const SPACE_EVERY         = 6 * 3600;   // her tick looks again when the last look is older (the history fills without a visit)
const SPACE_DOCKER_AGE    = 6 * 3600;   // `docker system df` walks every layer and volume: at most this often
const SPACE_DATASETS_MAX  = 300;        // datasets per pool in the part (the biggest; Docker's layers only counted)
const SPACE_TOP_FOLDERS   = 8;
const SPACE_POOLS_DIR     = '/boot/config/pools';
const SPACE_DOCKER_CFG    = '/boot/config/docker.cfg';
const SPACE_DOCKER_MOUNT  = '/var/lib/docker';

$GLOBALS['space'] = null;
$GLOBALS['spaceTried'] = 0;

function spaceFile(): string
{
    return $GLOBALS['spaceFile'] ?? DATA_DIR . '/' . SPACE_FILE;
}

function spaceHistoryFile(): string
{
    return $GLOBALS['spaceHistoryFile'] ?? DATA_DIR . '/' . SPACE_HISTORY_FILE;
}

/** At her start: the last look read (the next one comes with the page or her tick) */
function spaceStart(): void
{
    $GLOBALS['space'] = readJson(spaceFile());
}

/** Her look again when the last one is older than $age seconds (or there is none) — the page's «space_refresh» */
function spaceFresh(int $age): array
{
    $s = $GLOBALS['space'] ?? null;
    return is_array($s) && time() - (int) ($s['time'] ?? 0) < $age ? $s : spaceScan();
}

/**
 * Her tick (cheap: a time compared): once the last look is older than SPACE_EVERY a new one — without Docker's df (that
 * one only with the page) — so the history gets its point a day without a browser. A look that failed waits as long.
 */
function spaceTick(): void
{
    $last = max((int) ($GLOBALS['space']['time'] ?? 0), (int) $GLOBALS['spaceTried']);
    if (time() - $last < SPACE_EVERY || !arrayRunning()) {
        return;
    }
    $GLOBALS['spaceTried'] = time();
    spaceScan(false, false);
}

/** The look: gathered, assembled, its history point added, written — $awake: every disk was just woken (a tour with «wake») */
function spaceScan(bool $awake = false, bool $docker = true): array
{
    $t0 = hrtime(true);
    $space = spaceBuild(spaceRead($awake, $docker, $GLOBALS['space'] ?? null));
    $space['history'] = spaceHistoryAdd(spaceHistoryPoints($space), spaceHistoryFile(), $space['time']);
    $space['duration_ms'] = intdiv(hrtime(true) - $t0, 1000000);
    $GLOBALS['space'] = $space;
    writeAtomic(spaceFile(), jsonEncode($space));
    return $space;
}

// ===================================================================== gathering (the host)

/**
 * What the host says, as text and arrays — spaceBuild()'s input. Nothing here wakes a disk: zfs list only on the awake
 * pools, btrfs only on an awake btrfs pool, Docker's df only while its data's place is awake; $old: the last look (its
 * Docker df kept while young enough, or when $docker is false).
 */
function spaceRead(bool $awake, bool $docker, ?array $old): array
{
    $disks = readCfg(disksIniFile(), true);
    $asleep = [];
    foreach ($disks as $section => $d) {
        $asleep[(string) ($d['name'] ?? $section)] = !$awake && diskAsleep($d);
    }
    $roots = waStorageRoots();
    $poolCfg = [];
    foreach (glob(SPACE_POOLS_DIR . '/*.cfg') ?: [] as $file) {
        $name = basename($file, '.cfg');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $name)) {
            $poolCfg[$name] = readCfg($file);
        }
    }

    // ZFS: every pool's own numbers (in memory), the datasets of the awake ones
    $zpool = bin('zpool');
    $zfs = bin('zfs');
    $zpoolList = $zpoolStatus = $zfsList = '';
    if ($zpool) {
        $r = runAll(['list' => [$zpool, 'list', '-Hp', '-o', 'name,size,alloc,free,frag,cap,dedup,health,ashift'],
                     'status' => [$zpool, 'status']], 30);
        $zpoolList = $r['list'][0] === 0 ? $r['list'][1] : '';
        $zpoolStatus = $r['status'][0] === 0 ? $r['status'][1] : '';
    }
    $names = array_values(array_filter(array_map(fn ($f) => $f[0] ?? '', rows($zpoolList)),
        fn ($n) => preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $n) === 1));
    $look = poolsBySleep($names, $asleep)['awake'];
    if ($zfs && $look) {
        [$exit, $out] = run([$zfs, 'list', '-Hp', '-t', 'filesystem,volume', '-o',
            'name,used,avail,refer,usedbysnapshots,usedbychildren,compressratio,recordsize,compression,mountpoint', '-r', ...$look], 60);
        $zfsList = $exit === 0 ? $out : '';
    }

    // btrfs: an awake pool's allocation (its chunks), read from the kernel through its mount
    $btrfs = [];
    $bin = bin('btrfs');
    foreach ($roots as $name => $r) {
        if ($bin && $r['fs'] === 'btrfs' && !baseAsleep((string) $name, $asleep)) {
            [$exit, $out] = run([$bin, 'filesystem', 'usage', '-b', "/mnt/$name"], 20);
            if ($exit === 0) {
                $btrfs[$name] = $out;
            }
        }
    }

    $where = $GLOBALS['where'] ?? readJson(whereStateFile()) ?? [];
    $datasets = [];
    foreach (spaceZfsRows($zfsList) as $ds) {
        if ($ds['mount'] !== null) {
            $datasets[$ds['name']] = ['used' => $ds['used'], 'refer' => $ds['refer'], 'mount' => $ds['mount']];
        }
    }
    $shares = array_values(array_filter(waShares($roots, $asleep, $datasets, []), fn ($s) => empty($s['stale_cfg'])));
    $shareCfg = [];
    foreach ($shares as $s) {
        $c = readCfg(WA_SHARES_DIR . "/{$s['name']}.cfg");
        $shareCfg[$s['name']] = ['split' => $c['shareSplitLevel'] ?? '', 'floor' => $c['shareFloor'] ?? ''];
    }

    $hired = staffHired();
    return [
        'time'       => time(),
        'disks'      => $disks,
        'asleep'     => $asleep,
        'health'     => waHealth(),
        'roots'      => $roots,
        'pool_cfg'   => $poolCfg,
        'zpool_list' => $zpoolList,
        'zpool_status' => $zpoolStatus,
        'zfs_list'   => $zfsList,
        'btrfs'      => $btrfs,
        'shares'     => $shares,
        'share_cfg'  => $shareCfg,
        'sizes'      => (readJson(whereSizesFile()) ?? [])['sizes'] ?? [],
        'where'      => ['folders' => $where['folders'] ?? [], 'vms' => $where['vms'] ?? [], 'appdata' => $where['appdata'] ?? null],
        'levels'     => in_array('moverelli', $hired, true) ? spaceLevels(readJson(deskFile('moverelli'))) : null,
        'snapshots'  => spaceSnapCounts(readJson(deskFile('snapshot'))),
        'docker'     => spaceDockerRead($shares, $asleep, $docker, $old['docker'] ?? null),
    ];
}

/** Ms. Moverelli's pools as her page draws them (moverelliLevels(): {pool: level + mine | null}) — with her state's time */
function spaceLevels(?array $state): ?array
{
    $levels = $state['levels'] ?? null;
    if (!is_array($levels)) {
        return null;
    }
    $out = [];
    foreach ($levels as $pool => $l) {
        if (is_array($l) && is_int($l['size'] ?? null) && is_int($l['used'] ?? null)) {
            $out[(string) $pool] = $l;
        }
    }
    return ['at' => is_int($state['time'] ?? null) ? $state['time'] : null, 'pools' => $out];
}

/** Ms. Snapshotini's last list (her state, never zfs again): {at, datasets: {ds: n}} — null without her look */
function spaceSnapCounts(?array $state): ?array
{
    $snaps = $state['zfs']['snapshots'] ?? null;
    if (!is_array($snaps)) {
        return null;
    }
    $count = [];
    foreach ($snaps as $s) {
        if (is_array($s) && is_string($s['ds'] ?? null)) {
            $count[$s['ds']] = ($count[$s['ds']] ?? 0) + 1;
        }
    }
    return ['at' => is_int($state['time'] ?? null) ? $state['time'] : null, 'datasets' => $count];
}

/**
 * Docker's own data: where Unraid keeps it (docker.cfg: an image file or a folder), its file system's size and use
 * (statvfs of /var/lib/docker — only while mounted, never the RAM disk under it), and `docker system df` (images,
 * containers' layers, volumes, build cache) — asked at most every SPACE_DOCKER_AGE s and only while every disk its
 * share lies on is awake (its volumes are walked); else the last answer, with its time.
 */
function spaceDockerRead(array $shares, array $asleep, bool $ask, ?array $old): ?array
{
    $cfg = readCfg(SPACE_DOCKER_CFG);
    if (($cfg['DOCKER_ENABLED'] ?? 'no') !== 'yes') {
        return null;
    }
    $path = (string) ($cfg['DOCKER_IMAGE_FILE'] ?? '');
    $mounted = null;
    foreach (mountTable() as $m) {
        if ($m['mount'] === SPACE_DOCKER_MOUNT) {
            $mounted = $m;
        }
    }
    $fs = null;
    if ($mounted) {
        $total = @disk_total_space(SPACE_DOCKER_MOUNT);
        $free = @disk_free_space(SPACE_DOCKER_MOUNT);
        $fs = is_float($total) && is_float($free) && $total > 0 ? ['fs' => $mounted['fs'], 'size' => (int) $total, 'used' => (int) ($total - $free)] : null;
    }
    // where its data lies: the share of the path, every disk or pool it may be on
    $places = [];
    if (preg_match('#^/mnt/([^/]+)/([^/]+)#', $path, $m)) {
        if ($m[1] === 'user' || $m[1] === 'user0') {
            foreach ($shares as $s) {
                if ($s['name'] === $m[2]) {
                    $places = array_merge($s['storage']['pools'], $s['storage']['disks'], $s['storage']['unknown']);
                }
            }
        } else {
            $places = [$m[1]];
        }
    }
    $sleeping = array_values(array_filter($places, fn ($p) => baseAsleep((string) $p, $asleep)));
    $df = is_array($old['df'] ?? null) ? $old['df'] : null;
    $young = $df !== null && time() - (int) ($df['at'] ?? 0) < SPACE_DOCKER_AGE;
    if ($ask && !$young && !$sleeping && file_exists('/var/run/docker.sock')) {
        [$exit, $out] = houseDocker(['system', 'df', '--format', '{{json .}}'], 30);
        if ($exit === 0) {
            $df = spaceDockerDf($out) + ['at' => time()];
        }
    }
    return ['mode' => ($cfg['DOCKER_IMAGE_TYPE'] ?? '') === 'folder' || str_ends_with($path, '/') ? 'folder' : 'image',
            'path' => $path, 'image_gb' => ctype_digit((string) ($cfg['DOCKER_IMAGE_SIZE'] ?? '')) ? (int) $cfg['DOCKER_IMAGE_SIZE'] : null,
            'fs' => $fs, 'df' => $df, 'asleep' => $sleeping];
}

// ===================================================================== parsing (pure)

/** `docker system df --format '{{json .}}'`: {images, containers, volumes, cache: {size, reclaimable, count, active}} */
function spaceDockerDf(string $text): array
{
    $out = [];
    $keys = ['Images' => 'images', 'Containers' => 'containers', 'Local Volumes' => 'volumes', 'Build Cache' => 'cache'];
    foreach (explode("\n", $text) as $line) {
        $j = json_decode(trim($line), true);
        $k = is_array($j) ? ($keys[$j['Type'] ?? ''] ?? null) : null;
        if ($k === null) {
            continue;
        }
        $out[$k] = ['size' => spaceHumanBytes((string) ($j['Size'] ?? '')), 'reclaimable' => spaceHumanBytes(preg_replace('/\s*\(.*$/', '', (string) ($j['Reclaimable'] ?? ''))),
                    'count' => ctype_digit((string) ($j['TotalCount'] ?? '')) ? (int) $j['TotalCount'] : null,
                    'active' => ctype_digit((string) ($j['Active'] ?? '')) ? (int) $j['Active'] : null];
    }
    return $out;
}

/** Docker's sizes («6.3GB», «512.5kB», «0B» — powers of 1000, its units.HumanSize) in bytes; null when it says none */
function spaceHumanBytes(string $s): ?int
{
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([kKMGTP]?)B$/D', trim($s), $m)) {
        return null;
    }
    $exp = ['' => 0, 'k' => 1, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4, 'P' => 5][$m[2]];
    return (int) round((float) $m[1] * 1000 ** $exp);
}

/** zpool list -Hp -o name,size,alloc,free,frag,cap,dedup,health,ashift → {pool: {...}} («-» = null) */
function spaceZpoolRows(string $text): array
{
    $out = [];
    $n = fn (string $v): ?int => ctype_digit($v) ? (int) $v : null;
    foreach (rows($text) as $f) {
        if (count($f) < 9) {
            continue;
        }
        $out[$f[0]] = ['size' => $n($f[1]), 'alloc' => $n($f[2]), 'free' => $n($f[3]), 'frag' => $n(rtrim($f[4], '%')), 'cap' => $n(rtrim($f[5], '%')),
                       'dedup' => is_numeric(rtrim($f[6], 'x')) ? (float) rtrim($f[6], 'x') : null, 'health' => $f[7], 'ashift' => $n($f[8])];
    }
    return $out;
}

/**
 * zpool status: each pool's last scan («scan:», and the line under it while one runs) — {pool: {what, state, at, errors,
 * took, repaired, percent}}: what scrub|resilver|none, state done|running|canceled|paused. The status shows only the
 * LAST scan: after a resilver the last scrub is not known from it (what = resilver).
 */
function spaceScans(string $text): array
{
    $out = [];
    $pool = null;
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*pool:\s*(\S+)\s*$/', $line, $m)) {
            $pool = $m[1];
            continue;
        }
        if ($pool === null || !preg_match('/^\s*scan:\s*(.+)$/', $line, $m)) {
            continue;
        }
        $s = trim($m[1]);
        $scan = ['what' => 'none', 'state' => null, 'at' => null, 'errors' => null, 'took' => null, 'repaired' => null, 'percent' => null];
        if (preg_match('/^(scrub|resilver)\w*\s+(?:repaired\s+)?(\S+)\s+in\s+(?:(\d+)\s+days?\s+)?(\d+):(\d\d):(\d\d)\s+with\s+(\d+)\s+errors?\s+on\s+(.+)$/', $s, $x)) {
            // «scrub repaired 0B in 00:01:23 with 0 errors on …», «resilvered 252K in 00:00:00 with 0 errors on …»
            $scan = ['what' => $x[1], 'state' => 'done', 'at' => spaceZfsTime($x[8]), 'errors' => (int) $x[7],
                     'took' => (int) $x[3] * 86400 + (int) $x[4] * 3600 + (int) $x[5] * 60 + (int) $x[6], 'repaired' => $x[2], 'percent' => 100.0];
        } elseif (preg_match('/^(scrub|resilver)\s+in progress since\s+(.+)$/', $s, $x)) {
            $next = implode(' ', array_slice($lines, $i + 1, 3));
            $scan = ['what' => $x[1], 'state' => 'running', 'at' => spaceZfsTime($x[2]), 'errors' => null, 'took' => null, 'repaired' => null,
                     'percent' => preg_match('/(\d+(?:\.\d+)?)%\s+done/', $next, $p) ? (float) $p[1] : null];
        } elseif (preg_match('/^(scrub|resilver)\s+(canceled|paused)\s+(?:on|since)\s+(.+)$/', $s, $x)) {
            $scan = ['what' => $x[1], 'state' => $x[2], 'at' => spaceZfsTime($x[3]), 'errors' => null, 'took' => null, 'repaired' => null, 'percent' => null];
        }
        $out[$pool] = $scan;
        $pool = null;
    }
    return $out;
}

/** zpool status' time («Sun Oct 18 18:39:19 2026») — null when it reads otherwise */
function spaceZfsTime(string $s): ?int
{
    $d = DateTime::createFromFormat('D M j H:i:s Y', (string) preg_replace('/\s+/', ' ', trim($s)));
    return $d ? $d->getTimestamp() : null;
}

/** zfs list -Hp name,used,avail,refer,usedbysnapshots,usedbychildren,compressratio,recordsize,compression,mountpoint */
function spaceZfsRows(string $text): array
{
    $out = [];
    $n = fn (string $v): ?int => ctype_digit($v) ? (int) $v : null;
    foreach (rows($text) as $f) {
        if (count($f) < 10) {
            continue;
        }
        $out[] = ['name' => $f[0], 'used' => (int) $n($f[1]), 'avail' => $n($f[2]), 'refer' => (int) $n($f[3]), 'snaps' => (int) $n($f[4]),
                  'children' => (int) $n($f[5]), 'ratio' => is_numeric(rtrim($f[6], 'x')) ? (float) rtrim($f[6], 'x') : null,
                  'recordsize' => $n($f[7]), 'compression' => $f[8] !== '-' ? $f[8] : null,
                  'mount' => str_starts_with($f[9], '/') ? $f[9] : null, 'legacy' => $f[9] === 'legacy'];
    }
    return $out;
}

/** A dataset Docker's zfs driver made for a layer (<…>/<64 hex>[-init], legacy): counted, never listed */
function spaceDockerLayer(array $ds): bool
{
    return $ds['legacy'] && preg_match('#/[0-9a-f]{64}(-init)?$#D', $ds['name']) === 1;
}

/**
 * btrfs filesystem usage -b: {size, allocated, unallocated, used, free, data: {profile, size, used}, metadata: {…},
 * system: {…}} — what the chunks hold, the estimated free (with the profile's ratio)
 */
function spaceBtrfsUsage(string $text): ?array
{
    $get = fn (string $label): ?int => preg_match('/^\s*' . preg_quote($label, '/') . ':\s+(\d+)/m', $text, $m) ? (int) $m[1] : null;
    $size = $get('Device size');
    if ($size === null) {
        return null;
    }
    $out = ['size' => $size, 'allocated' => $get('Device allocated'), 'unallocated' => $get('Device unallocated'), 'used' => $get('Used'),
            'free' => $get('Free (estimated)'), 'data' => null, 'metadata' => null, 'system' => null];
    if (preg_match_all('/^(Data|Metadata|System),([A-Za-z0-9]+):\s*Size:(\d+),\s*Used:(\d+)/m', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $x) {
            $out[strtolower($x[1])] = ['profile' => strtolower($x[2]), 'size' => (int) $x[3], 'used' => (int) $x[4]];
        }
    }
    return $out;
}

/** shareFloor: kilobytes (Unraid's default), or a number with its unit (KB … TB, 1000 steps as Unraid's form) — bytes, null when unset */
function spaceFloor(string $v): ?int
{
    $v = trim($v);
    if ($v === '' || $v === '0') {
        return null;
    }
    if (ctype_digit($v)) {
        return (int) $v * 1024;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT])B?$/iD', $v, $m)) {
        return (int) round((float) $m[1] * 1000 ** (strpos('KMGT', strtoupper($m[2])) + 1));
    }
    return null;
}

// ===================================================================== assembling (pure)

/**
 * The part «space» from what spaceRead() gathered: devices, pools, the array, the boot device, shares, Docker, VMs'
 * disks, appdata — every number with where it comes from; nothing of a sleeping disk but Unraid's own numbers.
 */
function spaceBuild(array $raw): array
{
    $now = (int) $raw['time'];
    $disks = $raw['disks'];
    $asleep = $raw['asleep'];
    $health = $raw['health'] ?? [];
    $byName = [];
    foreach ($health['devices'] ?? [] as $h) {
        $byName[$h['name']] = $h;
    }
    $zpools = spaceZpoolRows($raw['zpool_list']);
    $scans = spaceScans($raw['zpool_status']);
    $rows = spaceZfsRows($raw['zfs_list']);
    $snapCount = $raw['snapshots']['datasets'] ?? null;
    $redund = array_column($health['pools'] ?? [], null, 'name');

    // pools: Unraid's (a cfg), what is mounted as one, what ZFS knows — never an array disk (diskN) or the flash
    $poolNames = array_keys($raw['pool_cfg']);
    foreach ($raw['roots'] as $name => $r) {
        if ($r['kind'] === 'pool') {
            $poolNames[] = (string) $name;
        }
    }
    foreach (array_keys($zpools) as $name) {
        $poolNames[] = (string) $name;
    }
    $poolNames = array_values(array_unique(array_filter($poolNames, fn ($n) => !waArrayDiskPool($n) && $n !== 'flash')));
    natcasesort($poolNames);
    $poolNames = array_values($poolNames);

    // which pool a device belongs to: the longest pool name it starts with, then digits only (cache, cache2 …)
    $poolOf = function (string $dev) use ($poolNames): ?string {
        $best = null;
        foreach ($poolNames as $p) {
            if (preg_match('/^' . preg_quote($p, '/') . '\d*$/D', $dev) && ($best === null || strlen($p) > strlen($best))) {
                $best = $p;
            }
        }
        return $best;
    };

    // the datasets per pool (Docker's layers counted apart)
    $tree = [];
    foreach ($rows as $ds) {
        $pool = explode('/', $ds['name'])[0];
        $tree[$pool] ??= ['root' => null, 'list' => [], 'layers' => 0, 'layer_bytes' => 0];
        if ($ds['name'] === $pool) {
            $tree[$pool]['root'] = $ds;
        }
        if (spaceDockerLayer($ds)) {
            $tree[$pool]['layers']++;
            $tree[$pool]['layer_bytes'] += $ds['used'];
            continue;
        }
        $tree[$pool]['list'][] = $ds;
    }
    $zfsBlock = function (string $pool) use ($zpools, $scans, $tree, $snapCount): ?array {
        if (!isset($zpools[$pool])) {
            return null;
        }
        $t = $tree[$pool] ?? null;
        $list = $t['list'] ?? [];
        usort($list, fn ($a, $b) => $b['used'] <=> $a['used']);
        $cut = count($list) > SPACE_DATASETS_MAX;
        $list = array_slice($list, 0, SPACE_DATASETS_MAX);
        usort($list, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $poolSnaps = null;
        if ($snapCount !== null) {
            $poolSnaps = 0;
            foreach ($snapCount as $ds => $n) {
                if ($ds === $pool || str_starts_with($ds, "$pool/")) {
                    $poolSnaps += $n;
                }
            }
        }
        return $zpools[$pool] + [
            'scan'        => $scans[$pool] ?? null,
            'looked'      => $t !== null,               // its datasets read (awake)
            'ratio'       => $t['root']['ratio'] ?? null,
            'recordsize'  => $t['root']['recordsize'] ?? null,
            'compression' => $t['root']['compression'] ?? null,
            'snaps'       => $t !== null ? array_sum(array_column($t['list'], 'snaps')) : null,
            'snapshots'   => $poolSnaps,
            'datasets'    => array_map(fn ($d) => ['name' => $d['name'], 'used' => $d['used'], 'refer' => $d['refer'], 'snaps' => $d['snaps'],
                'children' => $d['children'], 'ratio' => $d['ratio'], 'recordsize' => $d['recordsize'], 'compression' => $d['compression'],
                'mount' => $d['mount'], 'snapshots' => $snapCount[$d['name']] ?? null], $list),
            'datasets_cut' => $cut,
            'layers'      => $t['layers'] ?? 0,
            'layer_bytes' => $t['layer_bytes'] ?? 0,
        ];
    };

    $kb = fn ($v): ?int => is_string($v) && ctype_digit($v) ? (int) $v * 1024 : null;

    // the devices of disks.ini (array, pools, boot) with what Where already reads of each (temperature limits, thresholds)
    $devices = [];
    foreach ($disks as $section => $d) {
        $name = (string) ($d['name'] ?? $section);
        $dev = (string) ($d['device'] ?? '');
        if ($dev === '') {
            continue;
        }
        $type = (string) ($d['type'] ?? '');
        $h = $byName[$name] ?? [];
        $size = $kb($d['fsSize'] ?? null);
        $free = $kb($d['fsFree'] ?? null);
        $role = match (true) {
            $type === 'Parity' => 'parity',
            $type === 'Data'   => 'data',
            $type === 'Flash'  => 'boot',
            default            => 'pool',
        };
        $devices[] = [
            'name'       => $name,
            'type'       => $type,
            'role'       => $role,
            'pool'       => $role === 'pool' ? $poolOf($name) : null,
            'device'     => $dev,
            'id'         => (string) ($d['id'] ?? ''),
            'transport'  => (string) ($d['transport'] ?? ''),
            'rotational' => ($d['rotational'] ?? '1') !== '0',
            'asleep'     => $asleep[$name] ?? false,
            'status'     => $d['status'] ?? null,
            'errors'     => ctype_digit((string) ($d['numErrors'] ?? '')) ? (int) $d['numErrors'] : 0,
            'temp'       => ctype_digit((string) ($d['temp'] ?? '')) ? (int) $d['temp'] : null,
            'hot'        => $h['hot'] ?? null,
            'max'        => $h['max'] ?? null,
            'warn'       => $h['warn'] ?? null,
            'crit'       => $h['crit'] ?? null,
            'disk_size'  => $kb($d['size'] ?? null),
            'fs'         => ($d['fsType'] ?? '') !== '' ? (string) $d['fsType'] : null,
            'size'       => $size,
            'used'       => $size !== null && $free !== null ? max(0, $size - $free) : null,
            'free'       => $free,
            'fill'       => $size && $free !== null ? round(($size - $free) / $size * 100, 1) : null,
            'reads'      => ctype_digit((string) ($d['numReads'] ?? '')) ? (int) $d['numReads'] : null,
            'writes'     => ctype_digit((string) ($d['numWrites'] ?? '')) ? (int) $d['numWrites'] : null,
            'shares'     => [],
            'maybe'      => [],
            'zfs'        => $role === 'data' && ($d['fsType'] ?? '') === 'zfs' ? $zfsBlock($name) : null,
        ];
    }
    $devIdx = array_flip(array_column($devices, 'name'));

    // shares: their bytes and where they lie
    $sizes = $raw['sizes'];
    $dsByName = array_column($rows, null, 'name');
    $folders = array_column($raw['where']['folders'] ?? [], null, 'share');
    $shares = [];
    $poolShares = [];
    foreach ($raw['shares'] as $s) {
        $st = $s['storage'];
        $name = $s['name'];
        $on = array_merge($st['pools'], $st['disks']);
        $parts = [];
        $missing = [];
        foreach ($on as $root) {
            $ds = $dsByName["$root/$name"] ?? null;
            $kind = in_array($root, $st['disks'], true) ? 'disk' : 'pool';
            if ($ds) {
                $parts[] = ['root' => $root, 'kind' => $kind, 'bytes' => $ds['used'], 'snaps' => $ds['snaps'], 'refer' => $ds['refer']];
            } else {
                $parts[] = ['root' => $root, 'kind' => $kind, 'bytes' => null, 'snaps' => null, 'refer' => null];
                $missing[] = $root;
            }
            if ($kind === 'disk' && isset($devIdx[$root])) {
                $devices[$devIdx[$root]]['shares'][] = $name;
            } elseif ($kind === 'pool') {
                $poolShares[$root][] = $name;
            }
        }
        // a sleeping disk the share may lie on (its include/exclude, the array as its primary or secondary)
        foreach ($st['unknown'] as $root) {
            if (isset($devIdx[$root])) {
                $devices[$devIdx[$root]]['maybe'][] = $name;
            }
        }
        $du = $sizes[$s['measure']] ?? null;
        $duOk = is_array($du) && is_int($du['bytes'] ?? null);
        $known = array_filter($parts, fn ($p) => $p['bytes'] !== null);
        $complete = !$missing && !$st['unknown'];
        if (!$on && !$st['unknown']) {
            [$bytes, $source] = [0, 'empty'];                     // no folder on any disk or pool: nothing there
        } elseif ($known && $complete) {
            [$bytes, $source] = [array_sum(array_column($known, 'bytes')), 'zfs'];
        } elseif ($duOk) {
            [$bytes, $source] = [(int) $du['bytes'], 'du'];
        } elseif ($known) {
            [$bytes, $source] = [array_sum(array_column($known, 'bytes')), 'part'];   // at least: the parts ZFS knows
        } else {
            [$bytes, $source] = [null, null];                     // not measured — never 0
        }
        $top = [];
        foreach (($folders[$name]['folders'] ?? []) as $f) {
            $b = $f['size']['bytes'] ?? null;
            $m = $sizes[$f['real']] ?? null;
            if (is_int($b)) {
                $top[] = ['name' => $f['name'], 'bytes' => $b, 'source' => 'zfs'];
            } elseif (is_array($m) && is_int($m['bytes'] ?? null)) {
                $top[] = ['name' => $f['name'], 'bytes' => $m['bytes'], 'source' => 'du', 'at' => $m['at'] ?? null];
            }
        }
        usort($top, fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        $cfg = $raw['share_cfg'][$name] ?? [];
        $shares[] = [
            'name'      => $name,
            'measure'   => $s['measure'],                 // the path a du of the page measures («where-sizes»)
            'use_cache' => $st['use_cache'],
            'primary'   => $st['primary'],
            'secondary' => $st['secondary'],
            'exclusive' => $st['exclusive'],
            'allocator' => $st['allocator'],
            'split'     => ($cfg['split'] ?? '') !== '' ? (string) $cfg['split'] : null,
            'floor'     => spaceFloor((string) ($cfg['floor'] ?? '')),
            'include'   => $st['include'],
            'exclude'   => $st['exclude'],
            'missing'   => $st['missing'],
            'parts'     => $parts,
            'unknown'   => $st['unknown'],
            'bytes'     => $bytes,
            'snaps'     => $known ? array_sum(array_column($known, 'snaps')) : null,
            'source'    => $source,
            'at'        => $source === 'du' ? ($du['at'] ?? null) : null,
            'unmeasured' => $source === 'part' ? array_values(array_merge($missing, $st['unknown'])) : [],
            'top'       => array_slice($top, 0, SPACE_TOP_FOLDERS),
        ];
    }

    // the pools
    $levels = $raw['levels']['pools'] ?? [];
    $pools = [];
    foreach ($poolNames as $name) {
        $cfg = $raw['pool_cfg'][$name] ?? [];
        $members = array_values(array_map(fn ($d) => $d['name'], array_filter($devices, fn ($d) => $d['pool'] === $name)));
        $first = $devIdx[$name] ?? null;
        $h = $byName[$name] ?? [];
        $sleeping = baseAsleep($name, $asleep);
        $z = $zfsBlock($name);
        $root = $tree[$name]['root'] ?? null;
        $fs = $raw['roots'][$name]['fs'] ?? (($cfg['diskFsType'] ?? '') ?: ($z ? 'zfs' : ($first !== null ? $devices[$first]['fs'] : null)));
        // its size and use: ZFS from the pool's own dataset (as Ms. Moverelli's page), else Unraid's numbers (disks.ini)
        if ($root && $root['avail'] !== null) {
            [$size, $used, $source] = [$root['used'] + $root['avail'], $root['used'], 'zfs'];
        } elseif ($first !== null && $devices[$first]['size'] !== null) {
            [$size, $used, $source] = [$devices[$first]['size'], $devices[$first]['used'], 'unraid'];
        } else {
            [$size, $used, $source] = [null, null, null];
        }
        $btrfs = isset($raw['btrfs'][$name]) ? spaceBtrfsUsage($raw['btrfs'][$name]) : null;
        $level = $levels[$name] ?? null;
        $pools[] = [
            'name'     => $name,
            'fs'       => $fs,
            'asleep'   => $sleeping,
            'members'  => $members,
            'profile'  => ($cfg['diskFsProfile'] ?? '') !== '' ? (string) $cfg['diskFsProfile'] : null,
            'width'    => ctype_digit((string) ($cfg['diskFsWidth'] ?? '')) ? (int) $cfg['diskFsWidth'] : null,
            'groups'   => ctype_digit((string) ($cfg['diskFsGroups'] ?? '')) ? (int) $cfg['diskFsGroups'] : null,
            'layout'   => $redund[$name]['layout'] ?? null,
            'tolerates' => $redund[$name]['tolerates'] ?? null,
            'size'     => $size,
            'used'     => $used,
            'free'     => $size !== null && $used !== null ? max(0, $size - $used) : null,
            'fill'     => $size ? round($used / $size * 100, 1) : null,
            'source'   => $source,
            'warn'     => $h['warn'] ?? null,
            'crit'     => $h['crit'] ?? null,
            'shares'   => $poolShares[$name] ?? [],
            'zfs'      => $z,
            'btrfs'    => $btrfs,
            'managed'  => $level === null ? null : [
                'size' => $level['size'], 'used' => $level['used'], 'fixed' => (int) ($level['nm'] ?? 0), 'movable' => (int) ($level['movable'] ?? 0),
                'jack' => (int) ($level['jack'] ?? 0), 'others' => (int) ($level['others'] ?? 0), 'x' => (int) ($level['x'] ?? 0),
                'mine' => !empty($level['mine']), 'estimated' => !empty($level['estimated']), 'at' => $raw['levels']['at'] ?? null],
        ];
    }

    // the array: its data disks together (Unraid's numbers — a sleeping disk's too), parity apart
    $data = array_values(array_filter($devices, fn ($d) => $d['role'] === 'data'));
    $measured = array_values(array_filter($data, fn ($d) => $d['size'] !== null));
    $array = $data ? [
        'disks'  => count($data),
        'size'   => $measured ? array_sum(array_column($measured, 'size')) : null,
        'used'   => $measured ? array_sum(array_column($measured, 'used')) : null,
        'free'   => $measured ? array_sum(array_column($measured, 'free')) : null,
        'asleep' => count(array_filter($data, fn ($d) => $d['asleep'])),
        'parity' => array_values(array_map(fn ($d) => $d['name'], array_filter($devices, fn ($d) => $d['role'] === 'parity'))),
    ] : null;
    if ($array && $array['size']) {
        $array['fill'] = round($array['used'] / $array['size'] * 100, 1);
    }

    // VMs' disks (Where's look: apparent and allocated), appdata's folders (ZFS or a du asked for)
    $vms = [];
    foreach ($raw['where']['vms'] ?? [] as $vm) {
        $list = [];
        foreach ($vm['disks'] ?? [] as $d) {
            if (($d['device'] ?? 'disk') === 'disk' && is_string($d['source'] ?? null)) {
                $list[] = ['source' => $d['source'], 'bytes' => $d['bytes'] ?? null, 'allocated' => $d['allocated'] ?? null, 'format' => $d['format'] ?? null];
            }
        }
        if ($list) {
            $vms[] = ['name' => (string) $vm['name'], 'running' => !empty($vm['running']), 'disks' => $list];
        }
    }
    $appdataShare = $raw['where']['appdata'] ?? null;
    $appdata = [];
    foreach (($folders[$appdataShare]['folders'] ?? []) as $f) {
        $b = $f['size']['bytes'] ?? null;
        $m = $sizes[$f['real']] ?? null;
        $bytes = is_int($b) ? $b : (is_array($m) && is_int($m['bytes'] ?? null) ? $m['bytes'] : null);
        $users = array_values(array_unique(array_map(fn ($u) => (string) $u['name'], array_filter($f['used_by'] ?? [], fn ($u) => in_array($u['kind'] ?? '', ['container', 'compose'], true)))));
        $appdata[] = ['name' => $f['name'], 'bytes' => $bytes, 'source' => is_int($b) ? 'zfs' : ($bytes !== null ? 'du' : null), 'containers' => $users];
    }
    usort($appdata, fn ($a, $b) => ($b['bytes'] ?? -1) <=> ($a['bytes'] ?? -1) ?: strnatcasecmp($a['name'], $b['name']));

    return [
        'v'           => 1,
        'time'        => $now,
        'duration_ms' => 0,
        'host'        => hostname(),
        'agent'       => AGENT_VERSION,
        'limits'      => $health['limits'] ?? null,
        'devices'     => $devices,
        'pools'       => $pools,
        'array'       => $array,
        'boot'        => ($b = array_values(array_filter($devices, fn ($d) => $d['role'] === 'boot'))[0] ?? null) ? $b['name'] : null,
        'shares'      => $shares,
        'docker'      => $raw['docker'],
        'vms'         => $vms,
        'appdata'     => ['share' => $appdataShare, 'folders' => array_slice($appdata, 0, 40), 'total' => count($appdata)],
        'snapshots_at' => $raw['snapshots']['at'] ?? null,
        'asleep'      => array_values(array_map('strval', array_keys(array_filter($asleep)))),
    ];
}

// ===================================================================== the history

/** What the history keeps of a look: {key: [used, size]} — each pool, the array, each data disk, the boot device */
function spaceHistoryPoints(array $space): array
{
    $points = [];
    foreach ($space['pools'] as $p) {
        if ($p['used'] !== null && $p['size']) {
            $points['pool:' . $p['name']] = [$p['used'], $p['size']];
        }
    }
    if ($space['array'] && $space['array']['size']) {
        $points['array'] = [$space['array']['used'], $space['array']['size']];
    }
    foreach ($space['devices'] as $d) {
        if (in_array($d['role'], ['data', 'boot'], true) && $d['used'] !== null && $d['size']) {
            $points['disk:' . $d['name']] = [$d['used'], $d['size']];
        }
    }
    return $points;
}

/**
 * The history (one JSON line {t, k, u, s} per point): the points added — one a day at most per thing (the day's first
 * look), lines older than SPACE_HISTORY_DAYS dropped, the file written anew only when it changed (writeAtomic: never
 * through a link) — and the series returned for the page: {key: [[t, used, size], …]} oldest first.
 *
 * @param array<string, array{0: int, 1: int}> $points
 * @return array<string, list<array{0: int, 1: int, 2: int}>>
 */
function spaceHistoryAdd(array $points, string $file, int $now): array
{
    $series = [];
    $changed = false;
    $since = $now - SPACE_HISTORY_DAYS * 86400;
    $text = is_file($file) && !is_link($file) ? (string) @file_get_contents($file, false, null, 0, 16 << 20) : '';
    foreach (explode("\n", $text) as $line) {
        $j = $line !== '' ? json_decode($line, true) : null;
        if (!is_array($j) || !is_int($j['t'] ?? null) || !is_string($j['k'] ?? null) || !is_int($j['u'] ?? null) || !is_int($j['s'] ?? null)) {
            $changed = $changed || $line !== '';          // a broken line goes
            continue;
        }
        if ($j['t'] < $since) {
            $changed = true;
            continue;
        }
        $series[$j['k']][] = [$j['t'], $j['u'], $j['s']];
    }
    $day = date('Y-m-d', $now);
    foreach ($points as $key => [$used, $size]) {
        $list = $series[$key] ?? [];
        $last = $list ? $list[count($list) - 1] : null;
        if ($last !== null && date('Y-m-d', $last[0]) === $day) {
            continue;
        }
        $series[$key][] = [$now, (int) $used, (int) $size];
        $changed = true;
    }
    if ($changed) {
        $out = '';
        foreach ($series as $key => $list) {
            foreach ($list as [$t, $u, $s]) {
                $out .= json_encode(['t' => $t, 'k' => (string) $key, 'u' => $u, 's' => $s], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
        writeAtomic($file, $out);
    }
    foreach ($series as &$list) {
        usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
    }
    return $series;
}
