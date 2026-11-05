<?php
declare(strict_types=1);

/*
 * The «Map» of Ms. Dustdevil's «Storage» (2026-11-05, the owner: what GrandPerspective does for a disk, for one share):
 * every folder and file of a share as a rectangle by size. Read only: nothing is deleted, moved or opened for writing.
 *
 * A scan only on the user's click (the page's dialog asks when a disk may wake), never from her tick on its own: action
 * where_map → whereMapAsk() queues it, whereMapTick() starts one `php agent.php job cleanup-map-scan <share> <path>` at a
 * time and polls it (a child in the agent's session: «agent.sh stop» ends it with the array's stop, like a du; never
 * waited for). The job walks (whereMapWalk()) and writes DATA_DIR/cleanup-map/<share>.json; the tick then keeps its
 * numbers in cleanup-where-map.json (the part «where-map»: queue, running, per share at, took, nodes, bytes) and writes
 * the total into the sizes store under the share's measure path, the shape a du leaves: a scanned share needs no du.
 * `?a=part&desk=cleanup&part=where-map&share=<name>` serves one map (src/api.php).
 *
 * The file's shape: {v: 1, share, path, at, took, nodes, partial, tree}; a node {n, b, f, m, k, c?, r?}: name, bytes on
 * disk (as du counts them: blocks, a hard link once), files under it, newest mtime under it, kind (files only), children
 * by bytes (largest first), rest {f, b}: what was folded here («… n more»).
 *
 * How it stays small. On the way back up a folder keeps its WHERE_MAP_CHILDREN largest children, the others go into its
 * rest. With the total known whereMapFinish() folds the small files (whereMapSmall()), then bounds the whole tree at
 * WHERE_MAP_CAP nodes (harder: WHERE_MAP_HARD_*; at last the largest WHERE_MAP_CAP alone). While walking, what is held is
 * the open folders (depth × 2 × WHERE_MAP_CHILDREN) and the finished subtrees below them; past WHERE_MAP_LIVE nodes a
 * size floor rises (whereMapSqueeze()), so memory stays bounded whatever the share holds.
 *
 * One depth-first pass with lstat only: a symlink is never followed, `.zfs` never looked into (every look into
 * .zfs/snapshot automounts it), a mount never crossed — but for a ZFS child dataset of the share's own dataset
 * (whereMapCrossable()): on ZFS a VM's or a container's folder is often one, without it a share would look empty.
 */

const WHERE_MAP_FILE        = 'cleanup-where-map.json';   // in DATA_DIR: the part «where-map»
const WHERE_MAP_DIR         = 'cleanup-map';              // in DATA_DIR: <share>.json, one map per share
const WHERE_MAP_JOB         = 'cleanup-map-scan';
const WHERE_MAP_V           = 1;
const WHERE_MAP_CHILDREN    = 48;
const WHERE_MAP_SMALL_SHARE = 0.002;                      // a file folded: under this part of the share …
const WHERE_MAP_SMALL_BYTES = 1048576;                    // … and under this size
const WHERE_MAP_CAP         = 30000;                      // nodes in a map
const WHERE_MAP_HARD_CHILDREN = 24;                       // over the cap: fewer children per folder
const WHERE_MAP_HARD_SHARE  = 0.005;                      // … and every file under this part of the share folded
const WHERE_MAP_LIVE        = 120000;                     // nodes held while walking, then the floor rises
const WHERE_MAP_FLOOR_FIRST = 65536;
const WHERE_MAP_MEMORY      = '512M';                     // the job's limit: WHERE_MAP_LIVE nodes and the hard links
const WHERE_MAP_LINKS       = 200000;                     // hard links remembered (more: counted again, as bytes only)
const WHERE_MAP_KINDS       = [
    'video'     => ['mkv', 'mp4', 'm4v', 'avi', 'mov', 'wmv', 'mpg', 'mpeg', 'ts', 'm2ts', 'mts', 'vob', 'webm', 'flv', 'ogv', '3gp', 'divx'],
    'audio'     => ['mp3', 'flac', 'wav', 'aac', 'm4a', 'm4b', 'ogg', 'oga', 'opus', 'wma', 'aiff', 'aif', 'alac', 'ape', 'dsf', 'mka'],
    'image'     => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'avif', 'bmp', 'tif', 'tiff', 'svg', 'ico', 'raw', 'cr2', 'cr3', 'nef', 'arw', 'dng', 'orf', 'rw2', 'psd', 'xcf'],
    'document'  => ['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp', 'md', 'csv', 'epub', 'mobi', 'azw3', 'pages', 'numbers', 'key', 'eml', 'msg', 'srt', 'sub', 'ass'],
    'archive'   => ['zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz', 'xz', 'txz', 'zst', '7z', 'rar', 'lz4', 'lzma', 'cab', 'deb', 'rpm', 'dmg', 'pkg', 'txz', 'par2', 'bak'],
    'diskimage' => ['iso', 'img', 'qcow2', 'qcow', 'vdi', 'vmdk', 'vhd', 'vhdx', 'ova', 'ovf', 'nvram', 'fd'],
    'database'  => ['db', 'db3', 'sqlite', 'sqlite3', 'sdb', 'mdb', 'accdb', 'frm', 'ibd', 'myd', 'myi', 'ibdata', 'wal', 'shm', 'rdb', 'aof', 'dump', 'sql', 'mv'],
    'code'      => ['php', 'js', 'mjs', 'ts', 'tsx', 'jsx', 'json', 'xml', 'yml', 'yaml', 'toml', 'conf', 'cfg', 'ini', 'env', 'sh', 'bash', 'py', 'rb', 'pl', 'go', 'c', 'h', 'cpp', 'hpp', 'rs', 'java', 'kt', 'cs', 'lua', 'css', 'scss', 'html', 'htm', 'log', 'map', 'lock', 'so', 'dll', 'exe', 'bin', 'jar', 'class', 'pyc', 'wasm'],
];

$GLOBALS['whereMapJobs'] = ['queue' => [], 'running' => []];

function whereMapStateFile(): string
{
    return $GLOBALS['whereMapStateFile'] ?? DATA_DIR . '/' . WHERE_MAP_FILE;
}

function whereMapDir(): string
{
    return $GLOBALS['whereMapDir'] ?? DATA_DIR . '/' . WHERE_MAP_DIR;
}

/** A share's name as a file's name: never a path, never hidden — the web side asks the same (src/api.php) */
function whereMapShareOk(string $share): bool
{
    return preg_match('/^[^\/\x00.][^\/\x00]{0,199}$/D', $share) === 1;
}

function whereMapFile(string $share): string
{
    if (!whereMapShareOk($share)) {
        throw new Problem('unknown_target', ['target' => $share]);
    }
    return whereMapDir() . "/$share.json";
}

// ===================================================================== kinds

/** A file's kind by its extension (a name without one, a link, a socket: other). The first list that names it wins. */
function whereMapKind(string $name): string
{
    static $byExt = null;
    if ($byExt === null) {
        $byExt = [];
        foreach (WHERE_MAP_KINDS as $kind => $exts) {
            foreach ($exts as $ext) {
                $byExt[$ext] ??= $kind;
            }
        }
    }
    $dot = strrpos($name, '.');
    if ($dot === false || $dot === 0) {
        return 'other';
    }
    return $byExt[strtolower(substr($name, $dot + 1))] ?? 'other';
}

// ===================================================================== the walk

/**
 * One depth-first pass over $root: the tree as it stands before whereMapFinish() ($root itself never followed if it is a
 * link), null when it is no folder.
 *
 * @param array<string, true> $cross mounts that may be entered (whereMapCrossable())
 * @return ?array{tree: array, partial: bool}
 */
function whereMapWalk(string $root, string $name, array $cross = [], int $live = WHERE_MAP_LIVE): ?array
{
    $st = @lstat($root);
    if ($st === false || ($st['mode'] & 0170000) !== 0040000) {
        return null;
    }
    $w = ['live' => 1, 'floor' => 0, 'max' => $live, 'links' => [], 'partial' => false, 'cross' => $cross];
    $stack = [];
    whereMapEnter($stack, $name, $root, $st, $w);
    while (true) {
        $top = array_key_last($stack);
        $sub = array_pop($stack[$top]['subs']);
        if ($sub !== null) {
            $path = $stack[$top]['path'] . "/$sub";
            $st = @lstat($path);
            if ($st === false || ($st['mode'] & 0170000) !== 0040000) {      // gone (or swapped for a link) since it was listed
                $w['partial'] = $w['partial'] || $st === false;
            } elseif ($st['dev'] === $stack[$top]['dev'] || isset($w['cross'][$path])) {
                whereMapEnter($stack, $sub, $path, $st, $w);
            }
            continue;
        }
        $node = whereMapLeave(array_pop($stack));
        if (!$stack) {
            return ['tree' => $node, 'partial' => $w['partial']];
        }
        whereMapAdd($stack, $node, $w);
    }
}

/** A folder opened: its files counted at once (never more than twice the children held), its subfolders listed for later */
function whereMapEnter(array &$stack, string $name, string $path, array $st, array &$w): void
{
    $stack[] = ['n' => $name, 'path' => $path, 'dev' => $st['dev'], 'b' => max(0, $st['blocks']) * 512, 'f' => 0, 'm' => $st['mtime'],
                'kids' => [], 'r' => null, 'subs' => []];
    $w['live']++;
    $top = array_key_last($stack);
    $dir = @opendir($path);
    if ($dir === false) {
        $w['partial'] = true;
        return;
    }
    while (($entry = readdir($dir)) !== false) {
        if ($entry === '.' || $entry === '..' || $entry === '.zfs') {
            continue;
        }
        $st = @lstat("$path/$entry");
        if ($st === false) {
            $w['partial'] = true;
            continue;
        }
        if (($st['mode'] & 0170000) === 0040000) {
            $stack[$top]['subs'][] = $entry;
            continue;
        }
        $bytes = max(0, $st['blocks']) * 512;
        if ($st['nlink'] > 1 && ($st['mode'] & 0170000) === 0100000) {       // a hard link: its blocks once, as du counts
            $id = $st['dev'] . ':' . $st['ino'];
            if (isset($w['links'][$id])) {
                $bytes = 0;
            } elseif (count($w['links']) < WHERE_MAP_LINKS) {
                $w['links'][$id] = true;
            }
        }
        $w['live']++;
        whereMapAdd($stack, ['n' => $entry, 'b' => $bytes, 'f' => 1, 'm' => $st['mtime'],
            'k' => ($st['mode'] & 0170000) === 0100000 ? whereMapKind($entry) : 'other', 'x' => 1], $w);
    }
    closedir($dir);
}

/** A node into the folder on top: its numbers always, itself unless under the floor; the folder never holds more than twice its children */
function whereMapAdd(array &$stack, array $node, array &$w): void
{
    $top = array_key_last($stack);
    $stack[$top]['b'] += $node['b'];
    $stack[$top]['f'] += $node['f'];
    $stack[$top]['m'] = max($stack[$top]['m'], $node['m']);
    if ($node['b'] < $w['floor']) {
        $w['live'] -= $node['x'];
        whereMapFold($stack[$top]['r'], $node);
        return;
    }
    $stack[$top]['kids'][] = $node;
    if (count($stack[$top]['kids']) >= 2 * WHERE_MAP_CHILDREN) {
        $rest = $stack[$top]['r'];
        $stack[$top]['kids'] = whereMapTop($stack[$top]['kids'], WHERE_MAP_CHILDREN, $rest);
        $stack[$top]['r'] = $rest;
        $w['live'] = whereMapLiveCount($stack);
    }
    if ($w['live'] > $w['max']) {
        $w['live'] = whereMapLiveCount($stack);       // a folder that ended gave back what it folded
        whereMapSqueeze($stack, $w);
    }
}

/** A folded node in a rest: never «0 more» — an empty folder counts as one */
function whereMapFold(?array &$rest, array $node): void
{
    $rest ??= ['f' => 0, 'b' => 0];
    $rest['f'] += max(1, $node['f']);
    $rest['b'] += $node['b'];
}

/**
 * The largest $keep of $kids (by bytes, then name), the others folded into $rest
 *
 * @return list<array>
 */
function whereMapTop(array $kids, int $keep, ?array &$rest): array
{
    usort($kids, fn (array $a, array $b) => [$b['b'], (string) $a['n']] <=> [$a['b'], (string) $b['n']]);
    foreach (array_splice($kids, $keep) as $node) {
        whereMapFold($rest, $node);
    }
    return $kids;
}

/** A folder done: its largest children kept, what a node needs and no more (its subtree's count in x) */
function whereMapLeave(array $frame): array
{
    $node = ['n' => $frame['n'], 'b' => $frame['b'], 'f' => $frame['f'], 'm' => $frame['m'], 'x' => 1];
    $rest = $frame['r'];
    $kids = whereMapTop($frame['kids'], WHERE_MAP_CHILDREN, $rest);
    foreach ($kids as $kid) {
        $node['x'] += $kid['x'];
    }
    if ($kids) {
        $node['c'] = $kids;
    }
    if ($rest !== null) {
        $node['r'] = $rest;
    }
    return $node;
}

/** What the walk holds right now: the open folders and everything kept under them */
function whereMapLiveCount(array $stack): int
{
    $live = count($stack);
    foreach ($stack as $frame) {
        foreach ($frame['kids'] as $kid) {
            $live += $kid['x'];
        }
    }
    return $live;
}

/** Too much held: the floor rises until half of the limit is left — what is under it folded, at once and from now on */
function whereMapSqueeze(array &$stack, array &$w): void
{
    while ($w['live'] > (int) ($w['max'] / 2)) {
        $w['floor'] = max($w['floor'] * 2, WHERE_MAP_FLOOR_FIRST);
        foreach ($stack as $i => $frame) {
            $rest = $frame['r'];
            $stack[$i]['kids'] = whereMapOverFloor($frame['kids'], $rest, $w['floor']);
            $stack[$i]['r'] = $rest;
        }
        $w['live'] = whereMapLiveCount($stack);
    }
}

/**
 * $kids at or over the floor, each cut the same way below; the others folded into $rest
 *
 * @return list<array>
 */
function whereMapOverFloor(array $kids, ?array &$rest, int $floor): array
{
    $keep = [];
    foreach ($kids as $kid) {
        if ($kid['b'] < $floor) {
            whereMapFold($rest, $kid);
            continue;
        }
        if (isset($kid['c'])) {
            $inner = $kid['r'] ?? null;
            $kid['c'] = whereMapOverFloor($kid['c'], $inner, $floor);
            $kid['x'] = 1 + array_sum(array_column($kid['c'], 'x'));
            if ($inner !== null) {
                $kid['r'] = $inner;
            }
            if (!$kid['c']) {
                unset($kid['c']);
            }
        }
        $keep[] = $kid;
    }
    return $keep;
}

// ===================================================================== the end of the walk: the rules on the known total

/**
 * Is this file too small for a node of its own? Both must hold: under the part of the share and under the size — a file of
 * a tiny share stays, and a film of a huge one (the part alone would fold everything up to 60 GB on a 30 TB share, and
 * the map would end at the folders). Nothing at all is never a node.
 */
function whereMapSmall(int $bytes, int $total): bool
{
    return $bytes === 0 || ($bytes < WHERE_MAP_SMALL_BYTES && $bytes < WHERE_MAP_SMALL_SHARE * $total);
}

/**
 * The rules on a finished walk: the small files folded, then the whole bounded at WHERE_MAP_CAP (harder rules, at last
 * only the largest nodes — a parent is never smaller than its child, the tree stays whole).
 *
 * @return array{0: array, 1: int} the tree in the file's shape, its nodes
 */
function whereMapFinish(array $tree): array
{
    $total = $tree['b'];
    $tree = whereMapPrune($tree, fn (array $n): bool => whereMapSmall($n['b'], $total), WHERE_MAP_CHILDREN);
    if ($tree['x'] > WHERE_MAP_CAP) {
        $tree = whereMapPrune($tree, fn (array $n): bool => $n['b'] < WHERE_MAP_SMALL_BYTES || $n['b'] < WHERE_MAP_HARD_SHARE * $total, WHERE_MAP_HARD_CHILDREN);
    }
    if ($tree['x'] > WHERE_MAP_CAP) {
        $sizes = [];
        whereMapSizes($tree, $sizes);
        rsort($sizes);
        $floor = $sizes[WHERE_MAP_CAP - 1];           // the root isn't in the list: this many nodes and it, ties all folded
        $rest = $tree['r'] ?? null;
        $tree['c'] = whereMapOverFloor($tree['c'] ?? [], $rest, $floor + 1);
        if ($rest !== null) {
            $tree['r'] = $rest;
        }
        $tree['x'] = 1 + array_sum(array_column($tree['c'], 'x'));
    }
    $nodes = $tree['x'];
    return [whereMapClean($tree), $nodes];
}

/** One pass: small files folded, the largest $children kept, at every level; x counted anew */
function whereMapPrune(array $node, callable $small, int $children): array
{
    $rest = $node['r'] ?? null;
    $keep = [];
    foreach ($node['c'] ?? [] as $kid) {
        if (isset($kid['k']) && $small($kid)) {
            whereMapFold($rest, $kid);
        } else {
            $keep[] = isset($kid['k']) ? $kid : whereMapPrune($kid, $small, $children);
        }
    }
    $keep = whereMapTop($keep, $children, $rest);
    $node['x'] = 1 + array_sum(array_column($keep, 'x'));
    unset($node['c'], $node['r']);
    if ($keep) {
        $node['c'] = $keep;
    }
    if ($rest !== null) {
        $node['r'] = $rest;
    }
    return $node;
}

/** @param list<int> $sizes */
function whereMapSizes(array $node, array &$sizes): void
{
    foreach ($node['c'] ?? [] as $kid) {
        $sizes[] = $kid['b'];
        whereMapSizes($kid, $sizes);
    }
}

/** The file's shape: x gone, children largest first */
function whereMapClean(array $node): array
{
    unset($node['x']);
    if (isset($node['c'])) {
        $none = null;
        $node['c'] = whereMapTop(array_map('whereMapClean', $node['c']), PHP_INT_MAX, $none);
    }
    return $node;
}

// ===================================================================== mounts

/**
 * The mounts under $root that may be entered: ZFS child datasets of the dataset $root lies on (a mountinfo as text; the
 * pool's own other datasets — Docker's layers — and everything else stay out).
 *
 * @return array<string, true>
 */
function whereMapCrossable(string $mountinfo, string $root): array
{
    $mounts = [];
    foreach (explode("\n", $mountinfo) as $line) {
        // 36 35 98:0 / /mnt/cache/appdata rw,noatime shared:1 - zfs cache/appdata rw,xattr
        $sides = explode(' - ', $line, 2);
        $left = explode(' ', $sides[0]);
        $right = explode(' ', $sides[1] ?? '');
        if (count($left) < 5 || count($right) < 2) {
            continue;
        }
        $mounts[] = ['at' => stripcslashes($left[4]), 'fs' => $right[0], 'source' => stripcslashes($right[1])];
    }
    $own = null;
    foreach ($mounts as $m) {
        if (($m['at'] === $root || str_starts_with($root, $m['at'] . '/')) && strlen($m['at']) >= strlen($own['at'] ?? '')) {
            $own = $m;
        }
    }
    $cross = [];
    if ($own === null || $own['fs'] !== 'zfs') {
        return $cross;
    }
    foreach ($mounts as $m) {
        if ($m['fs'] === 'zfs' && str_starts_with($m['at'], "$root/") && str_starts_with($m['source'], $own['source'] . '/')) {
            $cross[$m['at']] = true;
        }
    }
    return $cross;
}

// ===================================================================== the job

/** php agent.php job cleanup-map-scan <share> <path> (whereMapTick() starts it): the walk, its file. 0 = written. */
function whereMapJob(array $args): int
{
    $share = (string) ($args[0] ?? '');
    $path = (string) ($args[1] ?? '');
    if ($path === '' || !whereMapShareOk($share)) {
        return 2;
    }
    ini_set('memory_limit', WHERE_MAP_MEMORY);
    $t0 = hrtime(true);
    $walk = whereMapWalk($path, $share, whereMapCrossable((string) @file_get_contents('/proc/self/mountinfo'), $path));
    if ($walk === null) {
        return 3;
    }
    [$tree, $nodes] = whereMapFinish($walk['tree']);
    $dir = whereMapDir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return 4;
    }
    writeAtomic(whereMapFile($share), jsonEncode(['v' => WHERE_MAP_V, 'share' => $share, 'path' => $path, 'at' => time(),
        'took' => intdiv(hrtime(true) - $t0, 1000000000), 'nodes' => $nodes, 'partial' => $walk['partial'], 'tree' => $tree]));
    return 0;
}

// ===================================================================== the queue

/** @return array<string, string> measure path → share, from her last tour */
function whereMapShares(): array
{
    $out = [];
    foreach ($GLOBALS['where']['shares'] ?? [] as $s) {
        $out[$s['measure']] = $s['name'];
    }
    return $out;
}

/** What the page polls: queue and running as the du's lists of paths, what the last scan of each share left */
function whereMapStatus(): array
{
    $saved = readJson(whereMapStateFile()) ?? [];
    return ['queue' => array_keys($GLOBALS['whereMapJobs']['queue']), 'running' => array_keys($GLOBALS['whereMapJobs']['running']),
            'maps' => $saved['maps'] ?? [], 'failed' => $saved['failed'] ?? []];
}

function whereMapSave(array $maps = [], array $failed = []): void
{
    $saved = readJson(whereMapStateFile()) ?? [];
    $jobs = [];
    foreach ($GLOBALS['whereMapJobs']['running'] as $path => $job) {
        $jobs[$path] = ['share' => $job['share'], 'pid' => $job['pid'], 'since' => $job['since']];
    }
    $keepFailed = array_diff_key($saved['failed'] ?? [], $maps);
    writeAtomic(whereMapStateFile(), jsonEncode(['v' => WHERE_MAP_V] + [
        'queue'   => array_keys($GLOBALS['whereMapJobs']['queue']),
        'running' => array_keys($GLOBALS['whereMapJobs']['running']),
        'jobs'    => $jobs,
        'maps'    => $maps + ($saved['maps'] ?? []),
        'failed'  => $failed + $keepFailed,
    ] + $saved));
}

/** Action where_map: scans asked for, on the user's click — only shares of her last tour, none already asked, none whose folder is gone */
function whereMapAsk(array $paths): array
{
    $shares = whereMapShares();
    foreach ($paths as $path) {
        if (!isset($shares[$path])) {
            throw new Problem('unknown_target', ['target' => $path]);
        }
    }
    foreach ($paths as $path) {
        if (isset($GLOBALS['whereMapJobs']['running'][$path]) || isset($GLOBALS['whereMapJobs']['queue'][$path])) {
            throw new Problem('where_map_busy', ['share' => $shares[$path]]);
        }
        clearstatcache(true, $path);
        if (!is_dir($path)) {
            throw new Problem('share_missing', ['share' => $shares[$path]]);
        }
    }
    foreach ($paths as $path) {
        $GLOBALS['whereMapJobs']['queue'][$path] = $shares[$path];
    }
    whereMapSave();
    return ['ok' => true, 'map' => whereMapStatus()];
}

/**
 * Her start: a scan still walking from before a restart of the agent is taken up again (its pid and its job's name), one
 * gone is forgotten — and what only waited is dropped: a scan starts on a click, never later on its own.
 */
function whereMapStart(): void
{
    $saved = readJson(whereMapStateFile());
    if ($saved === null) {
        return;
    }
    $GLOBALS['whereMapJobs'] = ['queue' => [], 'running' => []];
    foreach ($saved['jobs'] ?? [] as $path => $job) {
        if (is_array($job) && is_int($job['pid'] ?? null) && is_string($job['share'] ?? null) && whereMapPidWalks($job['pid'])) {
            $GLOBALS['whereMapJobs']['running'][$path] = ['share' => $job['share'], 'pid' => $job['pid'], 'since' => (int) ($job['since'] ?? 0), 'process' => null];
        }
    }
    if (($saved['queue'] ?? []) !== [] || ($saved['running'] ?? []) !== array_keys($GLOBALS['whereMapJobs']['running'])) {
        whereMapSave();
    }
}

function whereMapPidWalks(int $pid): bool
{
    return str_contains((string) @file_get_contents("/proc/$pid/cmdline"), WHERE_MAP_JOB);
}

/** @return list<string> */
function whereMapCommand(string $share, string $path): array
{
    return $GLOBALS['whereMapCommand'] ?? ['nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', WHERE_MAP_JOB, $share, $path];
}

/** Her tick: nothing to do unless a scan was asked for; a finished job's numbers taken over, the next one started (one at a time) */
function whereMapTick(): void
{
    $jobs = &$GLOBALS['whereMapJobs'];
    if (!$jobs['queue'] && !$jobs['running']) {
        return;
    }
    $changed = false;
    $maps = $failed = [];
    foreach ($jobs['running'] as $path => $job) {
        if ($job['process'] !== null ? proc_get_status($job['process'])['running'] : whereMapPidWalks($job['pid'])) {
            continue;
        }
        if ($job['process'] !== null) {
            proc_close($job['process']);
        }
        unset($jobs['running'][$path]);
        $changed = true;
        whereMapEnded($path, $job, $maps, $failed);
    }
    if (!$jobs['running'] && $jobs['queue']) {
        $path = (string) array_key_first($jobs['queue']);
        $share = $jobs['queue'][$path];
        unset($jobs['queue'][$path]);
        $changed = true;
        $pipes = [];
        $process = proc_open(whereMapCommand($share, $path), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
        if (is_resource($process)) {
            $jobs['running'][$path] = ['share' => $share, 'pid' => proc_get_status($process)['pid'], 'since' => time(), 'process' => $process];
        } else {
            $failed[$share] = ['at' => time()];
        }
    }
    if ($changed) {
        whereMapSave($maps, $failed);
    }
}

/** A job over: its file newer than its start is a map — its numbers kept, its total in the sizes store; else the scan failed */
function whereMapEnded(string $path, array $job, array &$maps, array &$failed): void
{
    $share = $job['share'];
    $map = readJson(whereMapFile($share));
    if (!is_array($map) || (int) ($map['at'] ?? 0) < $job['since'] || !is_int($map['tree']['b'] ?? null)) {
        $failed[$share] = ['at' => time()];
        logLine("Scan of $share for the map ended without a map");
        return;
    }
    $partial = !empty($map['partial']);
    $maps[$share] = ['path' => $path, 'at' => $map['at'], 'took' => $map['took'] ?? null, 'nodes' => $map['nodes'] ?? null, 'bytes' => $map['tree']['b'], 'partial' => $partial];
    whereSaveSizes([$path => ['bytes' => $map['tree']['b'], 'at' => $map['at'], 'seconds' => $map['took'] ?? null, 'partial' => $partial]]);
    logLine(sprintf('Scanned %s for the map: %s in %s nodes, %d s', $share, number_format($map['tree']['b']), number_format((int) ($map['nodes'] ?? 0)), (int) ($map['took'] ?? 0)));
}
