<?php
declare(strict_types=1);

/*
 * What is mounted where — read from /proc only, never from the disks, so a
 * sleeping array disk stays asleep.
 */

/** How many /proc/<pid> entries snapshotMountsEverywhere() looks at, at most: it runs in the watchman's round and the array-hold look */
const SNAPSHOT_WALK_PIDS = 4096;

/**
 * All mounts as the host sees them, in /proc/self/mountinfo order. Whatever
 * was mounted later comes further down and may build on earlier mounts.
 *
 * @return list<array{dev:string, root:string, mount:string, fs:string, source:string, options:string, tags:list<string>}>
 */
function mountTable(): array
{
    return mountInfoParse((string) @file_get_contents('/proc/self/mountinfo'));
}

/**
 * One mountinfo text (of any process: /proc/<pid>/mountinfo shows that process's mount namespace) as mountTable() gives
 * it; `tags`: the optional fields (shared:N, master:N — how mounts propagate between namespaces)
 */
function mountInfoParse(string $text): array
{
    $table = [];
    foreach (explode("\n", $text) as $line) {
        $parts = explode(' - ', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $a = explode(' ', $parts[0]);
        $b = explode(' ', $parts[1], 3);
        if (count($a) < 5 || count($b) < 3) {
            continue;
        }
        $table[] = [
            'dev'     => $a[2],
            'root'    => mountUnescape($a[3]),
            'mount'   => mountUnescape($a[4]),
            'fs'      => $b[0],
            'source'  => mountUnescape($b[1]),
            'options' => $b[2],
            'tags'    => array_slice($a, 6),
        ];
    }
    return $table;
}

/**
 * Every ZFS snapshot mounted right now (fstype zfs, source <pool>/<dataset>@<name>) — on the host (/proc/1/mountinfo),
 * inside each running container's own mount namespace (/proc/<its main pid>/mountinfo) and in every other mount namespace
 * in /proc (an LXC container, an `unshare`d process, a compose sidecar Docker's list misses: `where` process, `comm` the
 * name of the pid read). A look into `.zfs/snapshot/<name>` from there can leave the snapshot mounted there only: the host
 * sees nothing, `zfs destroy` answers «dataset is busy» (2026-10-31). Read from /proc only: nothing under /mnt opened, no
 * disk woken; one readlink per pid, one mountinfo per namespace (the lowest pid that shows it), SNAPSHOT_WALK_PIDS pids
 * at most. A container or process whose /proc entry is gone between the list and the read is left out; one that shares the
 * host's mount namespace counts as the host; a copy of a host mount that only propagated into a namespace (master:N of
 * the host's shared:N) is the host's — it goes when that goes. While Docker doesn't answer, a container's namespace is
 * seen by the walk as a process.
 * $containers: [{name, pid}] (null: the running ones from Docker, dockerRunningPids()); $proc: the tests' copy.
 *
 * @return list<array{ds: string, name: string, where: string, container: ?string, pid: int, path: string, auto: bool, comm?: string}>
 *         `auto`: mounted at <something>/.zfs/snapshot/<name> (an automount), else mounted somewhere by hand
 */
function snapshotMountsEverywhere(?array $containers = null, string $proc = '/proc'): array
{
    $hostNs = @readlink("$proc/1/ns/mnt");
    $out = [];
    $shared = [];
    foreach (mountInfoParse((string) @file_get_contents("$proc/1/mountinfo")) as $m) {
        if (($s = snapshotMountOf($m)) !== null) {
            $out[] = $s + ['where' => 'host', 'container' => null, 'pid' => 1];
            foreach ($m['tags'] as $t) {
                if (str_starts_with($t, 'shared:')) {
                    $shared[$m['source'] . "\0" . substr($t, 7)] = true;
                }
            }
        }
    }
    // one namespace's snapshot mounts, without the host's own that only propagated into it
    $own = function (string $text) use ($shared): array {
        $mine = [];
        foreach (mountInfoParse($text) as $m) {
            if (($s = snapshotMountOf($m)) === null) {
                continue;
            }
            foreach ($m['tags'] as $t) {
                if (str_starts_with($t, 'master:') && isset($shared[$m['source'] . "\0" . substr($t, 7)])) {
                    continue 2;
                }
            }
            $mine[] = $s;
        }
        return $mine;
    };
    $seen = $hostNs !== false ? [$hostNs => true] : [];
    foreach ($containers ?? dockerRunningPids() as $c) {
        $pid = (int) ($c['pid'] ?? 0);
        if ($pid <= 1 || !is_string($c['name'] ?? null)) {
            continue;
        }
        $ns = @readlink("$proc/$pid/ns/mnt");
        if ($ns !== false && isset($seen[$ns])) {
            continue;                   // the host's namespace, or one already read
        }
        $text = @file_get_contents("$proc/$pid/mountinfo");
        if ($text === false) {
            continue;                   // stopped in between
        }
        if ($ns !== false) {
            $seen[$ns] = true;
        }
        foreach ($own($text) as $s) {
            $out[] = $s + ['where' => 'container', 'container' => $c['name'], 'pid' => $pid];
        }
    }
    foreach (snapshotWalkPids($proc) as $pid) {
        $ns = @readlink("$proc/$pid/ns/mnt");
        if ($pid === 1 || $ns === false || isset($seen[$ns])) {
            continue;                   // the host, gone or a zombie, or a namespace already read
        }
        $text = @file_get_contents("$proc/$pid/mountinfo");
        if ($text === false) {
            continue;                   // ended in between: the next pid of this namespace, if there is one, is read
        }
        $mine = $own($text);
        $comm = $mine ? @file_get_contents("$proc/$pid/comm") : '';
        if ($comm === false) {
            continue;                   // the same
        }
        $seen[$ns] = true;
        foreach ($mine as $s) {
            $out[] = $s + ['where' => 'process', 'container' => null, 'pid' => $pid, 'comm' => snapshotCommName($comm)];
        }
    }
    return array_map(fn (array $x): array => ['ds' => $x['ds'], 'name' => $x['name'], 'where' => $x['where'], 'container' => $x['container'],
                                              'pid' => $x['pid'], 'path' => $x['path'], 'auto' => $x['auto']]
        + (isset($x['comm']) ? ['comm' => $x['comm']] : []), $out);
}

/**
 * The numeric entries of /proc, lowest first — SNAPSHOT_WALK_PIDS at most (reading stops there, no error)
 *
 * @return list<int>
 */
function snapshotWalkPids(string $proc): array
{
    $dir = @opendir($proc);
    if ($dir === false) {
        return [];
    }
    $pids = [];
    while (count($pids) < SNAPSHOT_WALK_PIDS && ($e = readdir($dir)) !== false) {
        if (preg_match('/^[1-9]\d{0,9}$/D', $e)) {
            $pids[] = (int) $e;
        }
    }
    closedir($dir);
    sort($pids);
    return $pids;
}

/** A process's name as the page may say it: /proc/<pid>/comm trimmed, printable ASCII only, 32 characters at most */
function snapshotCommName(string $comm): string
{
    $name = substr((string) preg_replace('/[^\x20-\x7e]/', '?', trim($comm)), 0, 32);
    return $name !== '' ? $name : '?';
}

/** A mountinfo entry of a ZFS snapshot: {ds, name, path, auto} — else null */
function snapshotMountOf(array $m): ?array
{
    if ($m['fs'] !== 'zfs' || !preg_match('#^([A-Za-z0-9][^@\n]{0,1000})@([^@/\n]{1,255})$#D', $m['source'], $x)) {
        return null;
    }
    return ['ds' => $x[1], 'name' => $x[2], 'path' => $m['mount'], 'auto' => snapshotAutoPath($m['mount'], $x[2])];
}

/** Is $path an automount of the snapshot $name: exactly <something>/.zfs/snapshot/<name>, nothing climbing up? */
function snapshotAutoPath(string $path, string $name): bool
{
    return (bool) preg_match('#^(/[^/\0\n]+)+/\.zfs/snapshot/' . preg_quote($name, '#') . '$#D', $path)
        && !preg_match('#/\.\.?(/|$)#', $path);
}

/**
 * The running containers' main processes: [{name, pid}] — one `docker ps` and one `docker inspect` for all of them
 * (through houseDocker(): a silent Docker costs one short wait); [] when Docker isn't running or doesn't answer
 */
function dockerRunningPids(): array
{
    $docker = function (array $args): array {
        return function_exists('houseDocker') ? houseDocker($args) : run(['docker', ...$args], 20);
    };
    if (!file_exists('/var/run/docker.sock')) {
        return [];
    }
    [$exit, $ids] = $docker(['ps', '-q', '--no-trunc']);
    $ids = $exit === 0 ? array_slice(array_values(array_filter(explode("\n", trim((string) $ids)),
        fn (string $x): bool => (bool) preg_match('/^[0-9a-f]{12,64}$/D', $x))), 0, 500) : [];
    if (!$ids) {
        return [];
    }
    [, $text] = $docker(['inspect', '--format', "{{.Name}}\t{{.State.Pid}}", ...$ids]);
    $out = [];
    foreach (explode("\n", trim((string) $text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) === 2 && preg_match('/^[1-9]\d{0,9}$/D', $f[1])) {
            $out[] = ['name' => mb_strcut(ltrim($f[0], '/'), 0, 200, 'UTF-8'), 'pid' => (int) $f[1]];
        }
    }
    return $out;
}

/** mountinfo writes spaces and friends as \040 */
function mountUnescape(string $s): string
{
    return preg_replace_callback('/\\\\([0-7]{3})/', fn ($m) => chr(octdec($m[1])), $s);
}

/** Paths an overlay mount is built on (lower/upper/data dirs) */
function overlayLayers(string $options): array
{
    $layers = [];
    if (preg_match_all('/(?:^|,)(?:lowerdir\+?|upperdir|datadir\+?)=((?:\\\\.|[^,\\\\])*)/', $options, $m)) {
        foreach ($m[1] as $value) {
            foreach (preg_split('/(?<!\\\\):/', $value) as $p) {
                if ($p !== '') {
                    $layers[] = mountUnescape(str_replace(['\\:', '\\,'], [':', ','], $p));
                }
            }
        }
    }
    return $layers;
}

/** Whole btrfs filesystems under /mnt: mount point => name (disk1, cache …) */
function btrfsDevices(): array
{
    $devices = [];
    $seen = [];
    foreach (@file('/proc/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(' ', $line);
        if (count($f) < 4 || $f[2] !== 'btrfs') {
            continue;
        }
        $dev = $f[0];
        $mount = str_replace('\040', ' ', $f[1]);
        if (str_starts_with($dev, '/dev/loop') || !str_starts_with($mount, '/mnt/') || isset($seen[$dev])) {
            continue;
        }
        if (!preg_match('/(^|,)subvolid=5(,|$)/', $f[3])) {
            continue;   // a mounted subvolume, e.g. a snapshot mounted by a backup script
        }
        $seen[$dev] = true;
        $devices[$mount] = basename($mount);
    }
    return $devices;
}

/** Device (major:minor) => mount point of the whole btrfs disk */
function btrfsDisks(array $table): array
{
    $devices = btrfsDevices();
    $disks = [];
    foreach ($table as $m) {
        if ($m['fs'] === 'btrfs' && $m['root'] === '/' && isset($devices[$m['mount']])) {
            $disks[$m['dev']] ??= $m['mount'];
        }
    }
    return $disks;
}

/** Which snapshot is behind a mount? zfs: a source with @, btrfs: subvol= of a snapshot */
function snapshotOfMount(array $m, array $btrfsDisks): ?string
{
    if ($m['fs'] === 'zfs' && str_contains($m['source'], '@')) {
        return 'zfs:' . $m['source'];
    }
    if ($m['fs'] === 'btrfs' && isset($btrfsDisks[$m['dev']])
        && preg_match('#(?:^|,)subvol=(/[^,]+)#', $m['options'], $o) && $o[1] !== '/') {
        return 'btrfs:' . rtrim($btrfsDisks[$m['dev']], '/') . $o[1];
    }
    return null;
}

/** Unraid's bookkeeping of the disks (tests point it elsewhere) */
function disksIniFile(): string
{
    return $GLOBALS['disksIni'] ?? '/var/local/emhttp/disks.ini';
}

/** Disk name => asleep? From Unraid's own bookkeeping, without asking the disk. */
function sleepingDisks(): array
{
    $result = [];
    foreach (readCfg(disksIniFile(), true) as $section => $values) {
        $result[(string) ($values['name'] ?? $section)] = diskAsleep($values);
    }
    return $result;
}

/**
 * One disk of disks.ini: asleep for the office only when Unraid spun it down AND it rotates (2026-10-08). Unraid
 * puts SATA SSDs into standby too (`spundown="1"`, `rotational="0"`), but an SSD wakes in milliseconds and wears nothing
 * worth sparing — treating it as asleep would only leave it out (a night of `asleep_pools = skip`, a snapshot list) for
 * nothing. `rotational` missing (older disks.ini) = a rotating disk, as before. The engine's `ub_asleep_load` says the same.
 */
function diskAsleep(array $values): bool
{
    return ($values['spundown'] ?? '0') === '1' && ($values['rotational'] ?? '1') !== '0';
}

/**
 * Pools and disks sorted by sleep: of the names given (ZFS pools from `zpool list`, the bases under /mnt …),
 * which are awake and which sleep (baseAsleep: a pool sleeps when any of its disks does). A name disks.ini
 * doesn't know (a pool imported by hand) counts as awake — nobody keeps its disks asleep. Shared by Ms.
 * Snapshotini and Mr. Restori, who list ZFS datasets and snapshots only on the awake ones.
 *
 * @param list<string>             $names
 * @param array<string, bool>|null $asleep  sleepingDisks() (read when null)
 * @return array{awake: list<string>, asleep: list<string>}
 */
function poolsBySleep(array $names, ?array $asleep = null): array
{
    $asleep ??= sleepingDisks();
    $out = ['awake' => [], 'asleep' => []];
    foreach ($names as $name) {
        $out[baseAsleep((string) $name, $asleep) ? 'asleep' : 'awake'][] = (string) $name;
    }
    return $out;
}

/** Does a disk or pool sleep? A pool sleeps when any of its disks does (cache, cache2 …). */
function baseAsleep(string $base, array $asleep): bool
{
    if (preg_match('/^disk\d+$/', $base)) {
        return $asleep[$base] ?? false;              // an array disk is just itself (disk1 is not disk10)
    }
    foreach ($asleep as $disk => $sleeping) {
        if ($sleeping && preg_match('/^' . preg_quote($base, '/') . '\d*$/', (string) $disk)) {
            return true;
        }
    }
    return false;
}
