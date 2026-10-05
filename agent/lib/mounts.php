<?php
declare(strict_types=1);

/*
 * What is mounted where — read from /proc only, never from the disks, so a
 * sleeping array disk stays asleep.
 */

/**
 * All mounts as the host sees them, in /proc/self/mountinfo order. Whatever
 * was mounted later comes further down and may build on earlier mounts.
 *
 * @return list<array{dev:string, root:string, mount:string, fs:string, source:string, options:string}>
 */
function mountTable(): array
{
    $table = [];
    foreach (@file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
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
        ];
    }
    return $table;
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

/** Disk name => asleep? From Unraid's own bookkeeping, without asking the disk. */
function sleepingDisks(): array
{
    $result = [];
    foreach (readCfg('/var/local/emhttp/disks.ini', true) as $section => $values) {
        $result[(string) ($values['name'] ?? $section)] = ($values['spundown'] ?? '0') === '1';
    }
    return $result;
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
