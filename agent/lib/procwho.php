<?php
declare(strict_types=1);

/*
 * Who a process is, read from /proc — shared by the Night Watchman's two looks at the disks (2026-11-22, moved here from
 * the disk-wake capture so no desk file needs another's): «Who woke my disks?» (agent/desks/watchman-diskwakes.php) and
 * «What holds the array?» (agent/lib/arrayhold.php). Functions only, no state; read only: /proc's files and links
 * (readlink, never a file under /mnt opened or stat'ed — nothing here wakes a disk), and `smbstatus -p` (RAM).
 *
 *   procwhoPids()      the processes in /proc
 *   procwhoStat()      a process's (or task's) /proc/<pid>/stat: name, state, parent, kernel thread?, threads
 *   procwhoKind()      what it is: a container, a VM, SMB, NFS, the office's job (its desk), Unraid's mover, a cron line,
 *                      or a plain program with who started it (procwhoStarter(): Unraid, its web page, a plugin, a user
 *                      script, a terminal or SSH session)
 *   procwhoFiles()     its files (and working folder) under /mnt — a container's own paths taken back to the server's
 *                      (procwhoMounts(), procwhoHostPath())
 *   procwhoWho()       procwhoKind() + procwhoFiles() of one process
 *   procwhoSmb()       smbd's processes => the machine each serves (shown on a page only, never in the log)
 */

const PROCWHO_PATH   = 300;             // bytes of a path or a command kept
const PROCWHO_FDS    = 4000;            // open files read per look (the caller's counter): more and the look stops
const PROCWHO_FILES  = 20;              // files kept per process unless the caller asks for another number
// the office's jobs (job.sh <job>, agent.php job <job>) => the desk that does them
const PROCWHO_OFFICE = ['backup' => 'backup', 'snapshots' => 'snapshot', 'embycache' => 'jack', 'gather' => 'jack',
                        'moverelli' => 'moverelli', 'restore' => 'restore', 'logs' => 'logs', 'cleanup' => 'cleanup',
                        'watchman' => 'watchman', 'snapshot' => 'snapshot', 'jack' => 'jack'];

/** The processes in /proc, as scandir lists them */
function procwhoPids(string $proc): array
{
    $pids = [];
    foreach (@scandir($proc) ?: [] as $pid) {
        if (ctype_digit($pid)) {
            $pids[] = (int) $pid;
        }
    }
    return $pids;
}

/**
 * pid (comm) state ppid … of /proc/<pid>/stat (or a task's): [comm, state, ppid, kernel thread?, threads]
 *
 * @return array{0: string, 1: string, 2: int, 3: bool, 4: int}|null
 */
function procwhoStat(string $file): ?array
{
    $s = @file_get_contents($file);
    if (!is_string($s) || ($a = strpos($s, '(')) === false || ($b = strrpos($s, ')')) === false || $b < $a) {
        return null;
    }
    $f = explode(' ', trim(substr($s, $b + 2)));
    if (count($f) < 18) {
        return null;
    }
    return [substr($s, $a + 1, $b - $a - 1), $f[0], (int) $f[1], ((int) $f[6] & 0x00200000) !== 0, (int) $f[17]];
}

/** One process, closer: what it is (procwhoKind()) and its files under /mnt (procwhoFiles()). $onlyFiles: null for a process without such a file. */
function procwhoWho(string $proc, int $pid, int &$fds, array &$ns, bool $onlyFiles = false): ?array
{
    $files = array_map('strval', array_keys(procwhoFiles($proc, $pid, $fds, $ns)));
    if ($onlyFiles && !$files) {
        return null;
    }
    $kind = procwhoKind($proc, $pid);
    return $kind === null ? null : $kind + ['pid' => $pid, 'files' => $files];
}

/**
 * A process's files under /mnt (its folder too, unless $cwd is false): path => fd (-1: the folder) — a container's own
 * paths taken back to the server's through its mountinfo (procwhoHostPath()); only those $keep wants, $max at most.
 *
 * @return array<string, int>
 */
function procwhoFiles(string $proc, int $pid, int &$fds, array &$ns, ?callable $keep = null, int $max = PROCWHO_FILES, bool $cwd = true): array
{
    $files = [];
    $nsOf = (string) @readlink("$proc/$pid/ns/mnt");
    $map = $nsOf !== '' && $nsOf !== (string) @readlink("$proc/1/ns/mnt") ? ($ns[$nsOf] ??= procwhoMounts($proc, $pid)) : null;
    $host = $map !== null ? ($ns['host'] ??= procwhoMounts($proc, 1)) : null;
    $add = function (string $p, int $fd) use (&$files, $map, $host, $keep, $max): void {
        if ($map !== null) {
            $p = procwhoHostPath($p, $map, $host) ?? '';
        }
        $p = mb_strcut($p, 0, PROCWHO_PATH, 'UTF-8');
        if (str_starts_with($p, '/mnt/') && !isset($files[$p]) && count($files) < $max && ($keep === null || $keep($p))) {
            $files[$p] = $fd;
        }
    };
    $dir = $cwd ? @readlink("$proc/$pid/cwd") : false;
    if (is_string($dir)) {
        $add($dir, -1);
    }
    foreach (@scandir("$proc/$pid/fd") ?: [] as $fd) {
        if (!ctype_digit($fd)) {
            continue;
        }
        if (++$fds > PROCWHO_FDS) {
            break;
        }
        $p = @readlink("$proc/$pid/fd/$fd");
        if (is_string($p) && $p !== '' && $p[0] === '/') {
            $add(preg_replace('/ \(deleted\)$/', '', $p), (int) $fd);
        }
    }
    return $files;
}

/**
 * Who started a plain program, by one process above it: [by, name] — a user script (User Scripts' copy of it), a plugin
 * (its folder; Unraid's own dynamix ones are Unraid), Unraid (emhttpd, its API), Unraid's web page (php-fpm, nginx), a
 * terminal or SSH session; ['', ''] when this one says nothing
 */
function procwhoStarter(string $comm, string $line): array
{
    if (preg_match('#/(?:tmp/user\.scripts/tmpScripts|boot/config/plugins/user\.scripts/scripts)/([^/\s]{1,100})/script#', $line, $u)) {
        return ['user_script', $u[1]];
    }
    if (preg_match('#/(?:usr/local/emhttp|boot/config)/plugins/([A-Za-z0-9._-]{1,80})/#', $line, $p)) {
        return str_starts_with(strtolower($p[1]), 'dynamix') ? ['unraid', ''] : ['plugin', $p[1]];
    }
    if ($comm === 'emhttpd' || str_contains($line, 'unraid-api')) {
        return ['unraid', ''];
    }
    if (str_starts_with($comm, 'php-fpm') || $comm === 'nginx') {
        return ['web', ''];
    }
    if (in_array($comm, ['sshd', 'sshd-session', 'ttyd', 'login', 'screen', 'SCREEN'], true) || str_starts_with($comm, 'tmux')) {
        return ['session', ''];
    }
    return ['', ''];
}

/**
 * What a process is, by itself and the processes above it: [kind, prog, …] — kind container (cgroup: docker's id), vm
 * (qemu: its guest name), smb (smbd), nfs (nfsd), office (the office's job: its desk), mover (Unraid's mover), cron
 * (crond above it: the command), program — with who started it (`by`, `byname`: the first process above it that says,
 * procwhoStarter()). Null: gone meanwhile.
 */
function procwhoKind(string $proc, int $pid): ?array
{
    $st = procwhoStat("$proc/$pid/stat");
    if ($st === null) {
        return null;
    }
    $prog = mb_strcut($st[0], 0, 64, 'UTF-8');
    $cmd = fn (int $p): string => trim(str_replace("\0", ' ', (string) @file_get_contents("$proc/$p/cmdline", false, null, 0, 4096)));
    $own = $cmd($pid);
    $base = ['prog' => $prog, 'cmd' => mb_strcut($own, 0, PROCWHO_PATH, 'UTF-8')];
    if (preg_match('#/docker[/-]([0-9a-f]{12,64})(?:\.scope)?\b#', (string) @file_get_contents("$proc/$pid/cgroup", false, null, 0, 8192), $c)) {
        return ['kind' => 'container', 'id' => substr($c[1], 0, 64)] + $base;
    }
    if (str_starts_with($prog, 'qemu') && preg_match('/-name\s+(?:guest=)?([^,\s]{1,100})/', $own, $g)) {
        return ['kind' => 'vm', 'name' => $g[1]] + $base;
    }
    if ($prog === 'smbd' || str_starts_with($prog, 'smbd[')) {
        return ['kind' => 'smb'] + $base;
    }
    if ($st[3] && $prog === 'nfsd') {
        return ['kind' => 'nfs'] + $base;
    }
    // above it: the office's job, Unraid's mover, cron — or the first process that says who started it
    $p = $pid;
    $child = $own;
    $by = ['', ''];
    for ($i = 0; $i < 12 && $p > 1; $i++) {
        $line = $p === $pid ? $own : $cmd($p);
        if (preg_match('#/plugins/unraid-secretary-office/#', $line)) {
            $job = preg_match('#(?:job\.sh|agent\.php job)\s+([a-z][a-z0-9-]{0,40})#', $line, $j) ? $j[1]
                : (preg_match('#/(backup|smartmover|mediacache|gather)/#', $line, $j) ? ['backup' => 'backup', 'smartmover' => 'moverelli',
                    'mediacache' => 'embycache', 'gather' => 'gather'][$j[1]] : '');
            $desk = PROCWHO_OFFICE[$job] ?? PROCWHO_OFFICE[strtok($job, '-') ?: ''] ?? '';
            return ['kind' => 'office', 'desk' => $desk] + $base;
        }
        if (preg_match('#(?:^|/|\s)(?:mover|age_mover)(?:\s|$)#', $line)) {
            return ['kind' => 'mover'] + $base;
        }
        $pst = $p === $pid ? $st : procwhoStat("$proc/$p/stat");
        if ($pst === null) {
            break;
        }
        if ($p !== $pid && $pst[0] === 'crond') {
            // the cron line's command: what crond started, without the shell it started it with
            $line = preg_replace('#^(?:/bin/|/usr/bin/)?(?:ba)?sh -c #', '', $child);
            return ['kind' => 'cron', 'name' => mb_strcut($line, 0, PROCWHO_PATH, 'UTF-8')] + $base;
        }
        $by = procwhoStarter($pst[0], $line);
        if ($by[0] !== '') {
            break;
        }
        $child = $line;
        $p = $pst[2];
    }
    return ['kind' => 'program', 'by' => $by[0], 'byname' => mb_strcut($by[1], 0, 100, 'UTF-8')] + $base;
}

/**
 * A process's mounts from /proc/<pid>/mountinfo: [mount point, root inside its filesystem, major:minor], the longest
 * mount point first
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function procwhoMounts(string $proc, int $pid): array
{
    $out = [];
    foreach (@file("$proc/$pid/mountinfo", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(' ', $line);
        if (count($f) >= 5) {
            $out[] = [mountUnescape($f[4]), mountUnescape($f[3]), $f[2]];
        }
    }
    usort($out, fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0]));
    return $out;
}

/**
 * A path as a container sees it, as the server sees it: the container's mount it lies under (/media ← the server's
 * /mnt/user/Movies, the same filesystem by major:minor, its root inside it) — null when no mount of the server's has it
 */
function procwhoHostPath(string $path, array $mounts, array $host): ?string
{
    foreach ($mounts as [$point, $root, $dev]) {
        if ($path !== $point && !str_starts_with($path, rtrim($point, '/') . '/')) {
            continue;
        }
        $inside = rtrim($root, '/') . substr($path, strlen(rtrim($point, '/')));
        foreach ($host as [$hPoint, $hRoot, $hDev]) {
            if ($hDev !== $dev) {
                continue;
            }
            $r = rtrim($hRoot, '/');
            if ($r === '' || $inside === $r || str_starts_with($inside, "$r/")) {
                return rtrim($hPoint, '/') . substr($inside, strlen($r)) ?: '/';
            }
        }
        return null;
    }
    return null;
}

/** smbd's processes => the machine each serves (smbstatus -p; shown on a page only, never in the log) */
function procwhoSmb(): array
{
    if (bin('smbstatus') === null) {
        return [];
    }
    [$exit, $text] = run(['smbstatus', '-p'], 20);
    $out = [];
    foreach ($exit === 0 ? explode("\n", (string) $text) : [] as $line) {
        // PID  Username  Group  Machine  Protocol Version  Encryption  Signing
        if (preg_match('/^\s*(\d+)\s+\S+\s+\S+\s+(\S+)/', $line, $p)) {
            $out[$p[1]] = mb_strcut($p[2], 0, 120, 'UTF-8');
        }
    }
    return $out;
}
