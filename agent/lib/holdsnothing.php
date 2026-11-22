<?php
declare(strict_types=1);

/*
 * «Holds nothing» — the self-check of what keeps working while the array is stopped («always on», 2026-11-22, step S2).
 *
 * Whatever of the office outlives the array's stop (Fanboy's control; the night shift) must never keep the array or a
 * pool from unmounting. What keeps one is not a file read for a moment but something HELD: a working directory, an open
 * file, a mapped file, a process of ours started from it — or a mount table of its own: on 2026-11-22 a php left behind
 * in a private mount namespace (a copy of the mount table made when it started) kept /etc/libvirt, and with it a pool,
 * busy with cwd / and no file open; the array's stop hung. A path's first letters say too little («/etc/libvirt» and
 * «/var/lib/docker» are loop mounts of an image ON a pool), so this looks at what the kernel says:
 *
 *   officeHoldBarred()   what must not be held, from the mount table (/proc/<pid>/mountinfo) and the loop devices
 *                        (/sys/block/loopN/loop/backing_file): every mount under /mnt/ that is no RAM filesystem (array
 *                        disks, pools, /mnt/user, unassigned and remote disks, a snapshot mounted under /mnt/addons), and
 *                        every mount of a loop device whose file lies under /mnt/. Mount points AND device numbers: a
 *                        btrfs file's device is not its mount's, a bind mount's path is not its device's.
 *   officeHoldLook()     what one process holds: its mount namespace (and init's), cwd, root, open files, mapped files —
 *                        each with its path and device, read from /proc only (never the files themselves: nothing is
 *                        opened, no disk woken).
 *   officeHoldJudge()    the two together: null (it holds nothing) or the first thing found, as a code and what it is —
 *                        namespace · cwd · root · file · map · no_look (the look itself failed: counts as held — the
 *                        caller gives its fans back rather than guess).
 *   officeHoldsNothing() a process and everything it started, judged.
 *
 * Needs nothing of the agent (plain PHP): the guard and the control load it alone. $proc/$sys: another root for the tests.
 */

const OFFICE_HOLD_UNDER = '/mnt/';
/** Filesystems that live in RAM or in the kernel: a mount of one under /mnt/ (Unassigned Devices' tmpfs folders) holds no disk */
const OFFICE_HOLD_RAM_FS = ['tmpfs', 'ramfs', 'devtmpfs', 'proc', 'sysfs', 'devpts', 'cgroup', 'cgroup2', 'mqueue', 'debugfs', 'tracefs', 'securityfs', 'pstore', 'bpf', 'configfs'];
const OFFICE_HOLD_READ_MAX = 1 << 20;       // bytes of a /proc file read at most (a mount table, a process's maps)
const OFFICE_HOLD_FDS_MAX = 4096;           // open files looked at at most
const OFFICE_HOLD_PROCS_MAX = 512;          // processes of one tree looked at at most

/** A mount table's octal escapes (\040 a space, \011, \012, \134) put back */
function officeHoldUnescape(string $s): string
{
    return (string) preg_replace_callback('/\\\\([0-7]{3})/', fn ($m) => chr((int) octdec($m[1])), $s);
}

/** A device number as the mount table writes it — «major:minor», decimal — from stat()'s st_dev (glibc's encoding) */
function officeHoldDev(int $dev): string
{
    $major = (($dev >> 8) & 0xfff) | (($dev >> 32) & 0xfffff000);
    $minor = ($dev & 0xff) | (($dev >> 12) & 0xffffff00);
    return "$major:$minor";
}

/**
 * What must not be held, as the process $pid sees it: {mounts: [mount point => device], devs: [device => mount point]}.
 * null: the mount table can't be read (no look).
 *
 * @return array{mounts: array<string, string>, devs: array<string, string>}|null
 */
function officeHoldBarred(int|string $pid = 'self', string $proc = '/proc', string $sys = '/sys'): ?array
{
    $raw = @file_get_contents("$proc/$pid/mountinfo", false, null, 0, OFFICE_HOLD_READ_MAX);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $out = ['mounts' => [], 'devs' => []];
    $loops = [];
    foreach (explode("\n", $raw) as $line) {
        // <id> <parent> <major:minor> <root> <mount point> <options> [optional fields…] - <fstype> <source> <super options>
        $sep = strpos($line, ' - ');
        $f = $sep === false ? [] : explode(' ', substr($line, 0, $sep));
        $t = $sep === false ? [] : explode(' ', substr($line, $sep + 3));
        if (count($f) < 6 || count($t) < 2 || !preg_match('/^\d{1,9}:\d{1,9}$/D', $f[2])) {
            continue;
        }
        $at = officeHoldUnescape($f[4]);
        $barred = str_starts_with($at, OFFICE_HOLD_UNDER) && !in_array($t[0], OFFICE_HOLD_RAM_FS, true);
        if (!$barred && preg_match('#^/dev/(loop\d{1,4})$#D', officeHoldUnescape($t[1]), $m)) {
            // a loop device: where its file lies decides (libvirt.img and docker.img lie on a pool; Unraid's own
            // /usr and /lib come from the flash)
            $loops[$m[1]] ??= rtrim((string) @file_get_contents("$sys/block/{$m[1]}/loop/backing_file", false, null, 0, 4096), "\n");
            $barred = str_starts_with($loops[$m[1]], OFFICE_HOLD_UNDER);
        }
        if ($barred) {
            $out['mounts'][$at] = $f[2];
            $out['devs'][$f[2]] ??= $at;
        }
    }
    return $out;
}

/**
 * What the process $pid holds, from /proc alone:
 *   {ns, ns1: its mount namespace and init's («mnt:[4026531841]»; null: not readable),
 *    cwd, root: {path, dev}|null, files: [{fd, path, dev}], maps: [{path, dev}], ok: every part could be read}
 * Only what has a path («/…»): pipes, sockets and anonymous memory hold no filesystem. dev: «major:minor» or null.
 */
function officeHoldLook(int|string $pid = 'self', string $proc = '/proc'): array
{
    $dir = "$proc/$pid";
    $link = function (string $file): ?string {
        $l = @readlink($file);
        return is_string($l) && $l !== '' ? $l : null;
    };
    $dev = function (string $file): ?string {
        $st = @stat($file);                 // through the kernel's link: the open file itself, nothing is opened anew
        return is_array($st) ? officeHoldDev((int) $st['dev']) : null;
    };
    $clean = fn (string $path): string => str_ends_with($path, ' (deleted)') ? substr($path, 0, -10) : $path;
    $out = ['ns' => $link("$dir/ns/mnt"), 'ns1' => $link("$proc/1/ns/mnt"), 'cwd' => null, 'root' => null, 'files' => [], 'maps' => [], 'ok' => true];
    foreach (['cwd', 'root'] as $k) {
        $p = $link("$dir/$k");
        if ($p === null) {
            $out['ok'] = false;
        } else {
            $out[$k] = ['path' => $clean($p), 'dev' => $dev("$dir/$k")];
        }
    }
    $fds = @scandir("$dir/fd");
    if ($fds === false) {
        $out['ok'] = false;
    }
    foreach (array_slice($fds ?: [], 0, OFFICE_HOLD_FDS_MAX) as $n) {
        if (!ctype_digit((string) $n)) {
            continue;
        }
        $p = $link("$dir/fd/$n");           // gone meanwhile (the look's own handle on this folder): nothing held
        if ($p !== null && $p[0] === '/') {
            $out['files'][] = ['fd' => (int) $n, 'path' => $clean($p), 'dev' => $dev("$dir/fd/$n")];
        }
    }
    $maps = @file_get_contents("$dir/maps", false, null, 0, OFFICE_HOLD_READ_MAX);
    if (!is_string($maps)) {
        $out['ok'] = false;
    }
    $seen = [];
    foreach (explode("\n", (string) $maps) as $line) {
        // <from>-<to> <perms> <offset> <major:minor, hex> <inode> <path>
        if (preg_match('#^\S+ \S+ \S+ ([0-9a-f]{1,8}):([0-9a-f]{1,8}) (\d+)\s+(/.*)$#D', $line, $m) && $m[3] !== '0' && !isset($seen[$m[4]])) {
            $seen[$m[4]] = true;
            $out['maps'][] = ['path' => $clean($m[4]), 'dev' => hexdec($m[1]) . ':' . hexdec($m[2])];
        }
    }
    return $out;
}

/** Does $path lie on a barred mount (the mount point itself or below it)? The mount point, else null */
function officeHoldUnder(string $path, array $mounts): ?string
{
    $hit = null;
    foreach ($mounts as $at => $_) {
        $at = (string) $at;
        if (($path === $at || str_starts_with($path, rtrim($at, '/') . '/')) && ($hit === null || strlen($at) > strlen($hit))) {
            $hit = $at;
        }
    }
    return $hit;
}

/**
 * A look judged against what is barred: null — it holds nothing — or the first thing found:
 *   {why: no_look | namespace | cwd | root | file | map, what: the path (or the namespaces), mount: the mount it lies on}
 * A look that could not be made, or no list of what is barred, is «no_look»: never «fine».
 */
function officeHoldJudge(array $look, ?array $barred): ?array
{
    if ($barred === null || empty($look['ok']) || !is_string($look['ns'] ?? null) || !is_string($look['ns1'] ?? null)) {
        return ['why' => 'no_look', 'what' => $barred === null ? 'mountinfo' : (!is_string($look['ns'] ?? null) || !is_string($look['ns1'] ?? null) ? 'ns/mnt' : 'proc'), 'mount' => null];
    }
    if ($look['ns'] !== $look['ns1']) {
        // a mount table of its own: it may keep any mount alive, whatever its cwd and files say
        return ['why' => 'namespace', 'what' => $look['ns'] . ' ≠ ' . $look['ns1'], 'mount' => null];
    }
    $on = function (?array $x) use ($barred): ?string {
        if ($x === null) {
            return null;
        }
        return officeHoldUnder((string) ($x['path'] ?? ''), $barred['mounts']) ?? (is_string($x['dev'] ?? null) ? ($barred['devs'][$x['dev']] ?? null) : null);
    };
    foreach (['cwd', 'root'] as $k) {
        if (($m = $on($look[$k] ?? null)) !== null) {
            return ['why' => $k, 'what' => (string) $look[$k]['path'], 'mount' => $m];
        }
    }
    foreach (['files' => 'file', 'maps' => 'map'] as $k => $why) {
        foreach ($look[$k] ?? [] as $x) {
            if (($m = $on($x)) !== null) {
                return ['why' => $why, 'what' => (string) $x['path'], 'mount' => $m];
            }
        }
    }
    return null;
}

/**
 * $pid and every process it started (children, their children …), from each process's stat (field 4: its parent) —
 * bounded, cycles impossible. Only $pid when the table can't be read.
 *
 * @return list<int>
 */
function officeHoldTree(int $pid, string $proc = '/proc'): array
{
    $kids = [];
    foreach (@scandir($proc) ?: [] as $e) {
        if (!ctype_digit((string) $e)) {
            continue;
        }
        $s = @file_get_contents("$proc/$e/stat", false, null, 0, 1024);
        // «<pid> (<name, any characters>) <state> <ppid> …»: the fields after the LAST «)»
        if (is_string($s) && ($p = strrpos($s, ')')) !== false && preg_match('/^\s*\S+\s+(\d+)/', substr($s, $p + 1), $m)) {
            $kids[(int) $m[1]][] = (int) $e;
        }
    }
    $out = [$pid];
    for ($i = 0; $i < count($out) && count($out) < OFFICE_HOLD_PROCS_MAX; $i++) {
        foreach ($kids[$out[$i]] ?? [] as $k) {
            if (!in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
    }
    return array_slice($out, 0, OFFICE_HOLD_PROCS_MAX);
}

/**
 * Does $pid (this process when null) with everything it started hold nothing on the array or a pool? null — nothing — or
 * what was found first: officeHoldJudge()'s answer with `pid`. A child that ended between the list and its look holds
 * nothing (only $pid itself must be lookable).
 */
function officeHoldsNothing(?int $pid = null, string $proc = '/proc', string $sys = '/sys'): ?array
{
    $pid ??= (int) getmypid();
    $barred = officeHoldBarred($pid, $proc, $sys);
    foreach (officeHoldTree($pid, $proc) as $p) {
        $look = officeHoldLook($p, $proc);
        if ($p !== $pid && !$look['ok'] && !is_dir("$proc/$p")) {
            continue;
        }
        if (($found = officeHoldJudge($look, $barred)) !== null) {
            return $found + ['pid' => $p];
        }
    }
    return null;
}

/** One line for a log: what officeHoldsNothing() found */
function officeHoldSay(array $found): string
{
    $pid = isset($found['pid']) ? " (PID {$found['pid']})" : '';
    return match ($found['why'] ?? '') {
        'namespace' => "a mount namespace of its own$pid: {$found['what']}",
        'cwd'       => "its working directory$pid is {$found['what']} on {$found['mount']}",
        'root'      => "its root$pid is {$found['what']} on {$found['mount']}",
        'file'      => "an open file$pid: {$found['what']} on {$found['mount']}",
        'map'       => "a mapped file$pid: {$found['what']} on {$found['mount']}",
        default     => "the look itself failed$pid ({$found['what']})",
    };
}
