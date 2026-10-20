<?php
declare(strict_types=1);

/*
 * «Who woke my disks?» — Ms. Protocolli notices when a spinning disk wakes up and who asked for it (2026-10-20).
 * Functions only, in a file of their own beside her desk (the agent's desk glob loads it, logs.php require_once's it).
 * Read only, RAM only: disks.ini, /proc and the syslog — never a disk, never a walk or a stat under /mnt.
 *
 * Unraid logs a spin-down («emhttpd: spinning down /dev/sdX»), never the wake, so she watches herself, in her tick:
 *   every LOGS_WAKE_INI s   disks.ini (sleepingDisks()'s rule: spun down AND rotating — an SSD/NVMe never counts);
 *                           a rotating disk going asleep → awake there is a wake (logsWakeRecord())
 *   every LOGS_WAKE_LOOK s  only while a rotating disk sleeps: /proc/diskstats. A sleeping disk's reads/writes moving,
 *                           or a request in flight, means somebody is asking for it right now — and whoever asked
 *                           waits (state D) while it spins up. Then, at once, a capture (logsWakeCapture()): the
 *                           processes in state D with their files under /mnt, their container (cgroup), VM (qemu),
 *                           SMB (smbd), the office's job, Unraid's mover or the cron line above them. Capped by time
 *                           and count; a capture without a flip after it (a SMART look in standby) is let go.
 * Names that need a command (a container's name, an SMB client's machine) and what was due then (cron lines, containers
 * just started, Unraid's «read SMART /dev/sdX» as a second time) come afterwards from a short job of its own
 * (logsWakeNameRun(), `php agent.php job logs-wake-name`), never in the tick.
 *
 * data/logs-wakes.json {"v":1, "since", "wakes":[…], "totals":{disk: n}} — written only by her tick (the naming job
 * hands its answer over in RAM); the last LOGS_WAKE_KEEP seconds, at most LOGS_WAKE_MAX wakes; tolerant: entries and
 * keys it doesn't know are kept as they are. The sampler's memory (what slept at the last look, when that was) lies in
 * RAM (RUN_DIR/logs-wakes.json): a restarted agent still sees a flip, the array's stop forgets it.
 * A machine name or a path only on her page — never in the agent's log (a report carries its lines): the log says the
 * disk and the kind of cause.
 */

/*
 * How often she looks at /proc/diskstats while a rotating disk sleeps. A disk spins up in 6 s or more (often 10–15 s),
 * and whoever woke it waits in state D all that time: a look every 4 s lands inside every spin-up. Not every second
 * (owner, 2026-10-20): the agent naps between its deadlines, and a wake-up every second keeps the CPU out of its deep
 * sleep states for nothing.
 */
const LOGS_WAKE_LOOK      = 4;
const LOGS_WAKE_INI       = 8;            // disks.ini every second look (asleep → awake: the wake itself)
const LOGS_WAKE_INI_NONE  = 60;           // … every minute when there is no rotating disk at all
const LOGS_WAKE_AGAIN     = 20;           // one capture per disk in this many seconds at most
const LOGS_WAKE_MATCH     = 180;          // a capture counts for a wake noticed this many seconds later at most
const LOGS_WAKE_BUDGET_MS = 60;           // a capture stops after this many milliseconds (it says so: `cut`)
const LOGS_WAKE_TASKS     = 6000;         // … or this many processes and threads looked at
const LOGS_WAKE_FDS       = 4000;         // … or this many open files read
const LOGS_WAKE_D         = 20;           // processes in state D looked at closer, at most
const LOGS_WAKE_WHO       = 5;            // causes kept per wake
const LOGS_WAKE_FILES     = 5;            // files kept per cause
const LOGS_WAKE_PATH      = 300;          // bytes of a path or a command kept
const LOGS_WAKE_KEEP      = 7 * 86400;    // wakes kept this long
const LOGS_WAKE_MAX       = 1000;         // and this many at most
const LOGS_WAKE_LATE      = 6 * 3600;     // a flip after a longer gap (the agent away) is not counted: when, nobody knows
const LOGS_WAKE_NAME_RUN  = 60;           // seconds the naming job may take
const LOGS_WAKE_KINDS     = ['container', 'vm', 'smb', 'nfs', 'office', 'mover', 'cron', 'program'];
// the office's jobs (job.sh <job>, agent.php job <job>) => the desk that does them
const LOGS_WAKE_OFFICE    = ['backup' => 'backup', 'snapshots' => 'snapshot', 'embycache' => 'jack', 'gather' => 'jack',
                             'moverelli' => 'moverelli', 'restore' => 'restore', 'logs' => 'logs', 'cleanup' => 'cleanup',
                             'watchman' => 'watchman', 'snapshot' => 'snapshot', 'jack' => 'jack'];
const LOGS_WAKE_SMART     = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d):(\d\d):(\d\d)\s+\S+\s+emhttpd: read SMART \/dev\/([a-z0-9]+)\s*$/';

function logsWakesFile(): string
{
    return $GLOBALS['logsWakesFile'] ?? DATA_DIR . '/logs-wakes.json';
}

function logsWakeMemFile(): string
{
    return $GLOBALS['logsWakeMemFile'] ?? RUN_DIR . '/logs-wakes.json';
}

/** /proc (tests: a tree of their own) */
function logsWakeProc(): string
{
    return $GLOBALS['logsWakeProc'] ?? '/proc';
}

/** Seconds on the monotonic clock: a clock stepped back never stops her looking */
function logsWakeMono(): float
{
    return $GLOBALS['logsWakeMonoNow'] ?? hrtime(true) / 1e9;
}

// ===================================================================== the disks

/**
 * The rotating disks of disks.ini (array, parity, pools — never the flash, never an SSD/NVMe), by name: their device
 * (sdX), asleep by diskAsleep() and where they are mounted (/mnt/disk3, a pool's /mnt/<pool>; parity: nowhere).
 *
 * @return array<string, array{dev: string, asleep: bool, mount: ?string}>
 */
function logsWakeDisks(?array $ini = null): array
{
    $ini ??= readCfg(disksIniFile(), true);
    $pools = [];
    foreach ($ini as $section => $v) {
        if (is_array($v) && strcasecmp((string) ($v['type'] ?? ''), 'Cache') === 0) {
            $pools[(string) ($v['name'] ?? $section)] = true;
        }
    }
    $out = [];
    foreach ($ini as $section => $v) {
        if (!is_array($v)) {
            continue;
        }
        $name = (string) ($v['name'] ?? $section);
        $dev = (string) ($v['device'] ?? '');
        $type = strtolower((string) ($v['type'] ?? ''));
        if (($v['rotational'] ?? '1') === '0' || $type === 'flash' || !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $dev)
            || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $name)) {
            continue;
        }
        $mount = null;
        if (preg_match('/^disk\d+$/D', $name)) {
            $mount = "/mnt/$name";
        } elseif ($type === 'cache') {
            $base = preg_replace('/\d+$/', '', $name);       // cache2 is a member of the pool cache (as baseAsleep())
            $mount = '/mnt/' . ($base !== '' && isset($pools[$base]) ? $base : $name);
        }
        $out[$name] = ['dev' => $dev, 'asleep' => diskAsleep($v), 'mount' => $mount];
    }
    return $out;
}

/**
 * /proc/diskstats: device => [reads completed, writes completed, in flight] (RAM, nothing asked of a disk)
 *
 * @return array<string, array{0: int, 1: int, 2: int}>
 */
function logsWakeDiskstats(?string $file = null): array
{
    $out = [];
    foreach (@file($file ?? logsWakeProc() . '/diskstats', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 12 && preg_match('/^[a-z][a-z0-9]{1,15}$/D', $f[2])) {
            $out[$f[2]] = [(int) $f[3], (int) $f[7], (int) $f[11]];
        }
    }
    return $out;
}

// ===================================================================== the tick

/** The sampler's memory: from RAM at the first call (a restarted agent still knows what slept) */
function &logsWakeMem(): array
{
    if (!isset($GLOBALS['logsWake'])) {
        $m = readJson(logsWakeMemFile()) ?? [];
        $GLOBALS['logsWake'] = ['next' => -INF, 'ini_next' => -INF,
            'disks' => is_array($m['disks'] ?? null) ? $m['disks'] : null, 'look' => is_int($m['look'] ?? null) ? $m['look'] : null,
            'stats' => [], 'caught' => [], 'again' => [], 'job' => null];
    }
    return $GLOBALS['logsWake'];
}

/** Her tick's part: nothing but a clock compare until the next look is due (see the top of the file) */
function logsWakeTick(): void
{
    $m = &logsWakeMem();
    if ($m['job'] !== null) {
        logsWakeNameDone();
    }
    $mono = logsWakeMono();
    if ($mono < $m['next']) {
        return;
    }
    $m['next'] = $mono + LOGS_WAKE_LOOK;
    if ($mono >= $m['ini_next']) {
        logsWakeIniLook($mono);
    }
    if (!$m['disks'] || !in_array(true, array_column($m['disks'], 'asleep'), true)) {
        $m['stats'] = [];
        return;
    }
    logsWakeStatsLook();
}

/** disks.ini: what sleeps now — and what was asleep at the last look and is awake now (a wake) */
function logsWakeIniLook(float $mono): void
{
    $m = &logsWakeMem();
    $now = time();
    // stopped, or she isn't hired: nothing sleeps or wakes for her; the next start begins anew
    if (!arrayRunning($GLOBALS['logsWakeVarIni'] ?? '/var/local/emhttp/var.ini')
        || !in_array('logs', staffHired($GLOBALS['agentStaffFile'] ?? null), true)) {
        $m['ini_next'] = $mono + LOGS_WAKE_INI_NONE;
        if ($m['disks'] !== null) {
            $m['disks'] = null;
            $m['caught'] = [];
            @unlink(logsWakeMemFile());
        }
        return;
    }
    $disks = logsWakeDisks();
    $m['ini_next'] = $mono + ($disks ? LOGS_WAKE_INI : LOGS_WAKE_INI_NONE);
    $before = $m['disks'];
    $woke = [];
    foreach ($disks as $name => $d) {
        if (!$d['asleep'] && !empty($before[$name]['asleep'])) {
            $woke[] = $name;
        }
    }
    $look = $m['look'];
    $changed = $before === null || array_map(fn ($d) => $d['asleep'], $disks) !== array_map(fn ($d) => !empty($d['asleep']), $before);
    $m['disks'] = array_map(fn (array $d): array => ['dev' => $d['dev'], 'asleep' => $d['asleep'], 'mount' => $d['mount']], $disks);
    $m['look'] = $now;
    if ($woke && $look !== null && $now - $look <= LOGS_WAKE_LATE) {
        try {
            logsWakeRecord($woke, $look, $now, $disks);
        } catch (Throwable $e) {
            logLine('Ms. Protocolli: a wake could not be kept — ' . $e->getMessage());
        }
    }
    foreach ($m['caught'] as $name => $c) {
        if ($now - (int) $c['t'] > LOGS_WAKE_MATCH || in_array($name, $woke, true)) {
            unset($m['caught'][$name]);                 // used, or no wake came after it
        }
    }
    // in RAM: only when something changed — or now and then, so a restarted agent knows how old its memory is
    if ($changed || $woke || $now - (int) ($m['saved'] ?? 0) >= 300) {
        $m['saved'] = $now;
        @mkdir(dirname(logsWakeMemFile()), 0700, true);
        writeAtomic(logsWakeMemFile(), jsonEncode(['disks' => $m['disks'], 'look' => $now]), 0600, 0, 0);
    }
}

/** /proc/diskstats for the sleeping disks: a sleeping disk asked for → a capture, at once */
function logsWakeStatsLook(): void
{
    $m = &logsWakeMem();
    $stats = logsWakeDiskstats();
    $asked = [];
    $keep = [];
    foreach ($m['disks'] as $name => $d) {
        if (empty($d['asleep']) || !isset($stats[$d['dev']])) {
            continue;
        }
        $now = $stats[$d['dev']];
        $was = $m['stats'][$d['dev']] ?? null;
        $keep[$d['dev']] = $now;
        if ($now[2] > 0 || ($was !== null && ($now[0] !== $was[0] || $now[1] !== $was[1]))) {
            $asked[] = $name;
        }
    }
    $m['stats'] = $keep;
    $mono = logsWakeMono();
    $asked = array_values(array_filter($asked, fn (string $n): bool => $mono - ($m['again'][$n] ?? -INF) >= LOGS_WAKE_AGAIN));
    if (!$asked) {
        return;
    }
    $c = logsWakeCapture();
    foreach ($asked as $name) {
        $m['again'][$name] = $mono;
        $m['caught'][$name] = $c;
    }
}

// ===================================================================== the capture

/**
 * pid (comm) state ppid … of /proc/<pid>/stat (or a task's): [comm, state, ppid, kernel thread?, threads]
 *
 * @return array{0: string, 1: string, 2: int, 3: bool, 4: int}|null
 */
function logsWakeStat(string $file): ?array
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

/**
 * Who waits for a disk right now: every process with a task in state D (uninterruptible — the spin-up), looked at
 * closer (logsWakeWho()); without one, whoever holds a file open on a mounted disk. Stops after LOGS_WAKE_BUDGET_MS,
 * LOGS_WAKE_TASKS tasks or LOGS_WAKE_FDS files (`cut`). Reads /proc only.
 *
 * @return array{t: int, ms: int, cut: bool, who: list<array>, open: list<array>}
 */
function logsWakeCapture(?string $proc = null): array
{
    $proc ??= logsWakeProc();
    $t0 = hrtime(true);
    $over = fn (): bool => hrtime(true) - $t0 > LOGS_WAKE_BUDGET_MS * 1e6;
    $self = getmypid();
    $tasks = 0;
    $cut = false;
    $waiting = [];
    $pids = [];
    foreach (@scandir($proc) ?: [] as $pid) {
        if (ctype_digit($pid)) {
            $pids[] = (int) $pid;
        }
    }
    foreach ($pids as $pid) {
        if ($tasks >= LOGS_WAKE_TASKS || $over()) {
            $cut = true;
            break;
        }
        $tasks++;
        $st = logsWakeStat("$proc/$pid/stat");
        if ($st === null || $pid === $self) {
            continue;
        }
        $d = $st[1] === 'D';
        if (!$d && $st[4] > 1) {
            foreach (@scandir("$proc/$pid/task") ?: [] as $tid) {
                if (!ctype_digit($tid) || (int) $tid === $pid) {
                    continue;
                }
                $tasks++;
                $t = logsWakeStat("$proc/$pid/task/$tid/stat");
                if ($t !== null && $t[1] === 'D') {
                    $d = true;
                    break;
                }
            }
        }
        // kernel threads tell nobody's name — but nfsd is the NFS server answering a client
        if ($d && (!$st[3] || $st[0] === 'nfsd') && count($waiting) < LOGS_WAKE_D) {
            $waiting[] = $pid;
        }
    }
    $fds = 0;
    $ns = [];
    $who = [];
    foreach ($waiting as $pid) {
        $w = logsWakeWho($proc, $pid, $fds, $ns);
        if ($w !== null) {
            $who[] = $w;
        }
    }
    // shfs waits for the disk on behalf of whoever used a user share: it names a share, not a person
    $people = array_values(array_filter($who, fn (array $w): bool => $w['prog'] !== 'shfs'));
    $open = [];
    if (!$people) {
        // nobody caught waiting: who holds a file open on a disk (the D task may have come and gone)
        foreach ($pids as $pid) {
            if ($fds >= LOGS_WAKE_FDS || $over()) {
                $cut = true;
                break;
            }
            if ($pid === $self || in_array($pid, $waiting, true)) {
                continue;
            }
            $w = logsWakeWho($proc, $pid, $fds, $ns, true);
            if ($w !== null && $w['files']) {
                $open[] = $w;
            }
        }
    }
    return ['t' => time(), 'ms' => (int) round((hrtime(true) - $t0) / 1e6), 'cut' => $cut, 'who' => $people ?: $who, 'open' => $open];
}

/**
 * One process, closer: what it is (logsWakeKind()) and its files under /mnt — a container's own paths taken back to
 * the server's through its mountinfo (logsWakeHostPath()). $onlyFiles: null for a process without such a file.
 */
function logsWakeWho(string $proc, int $pid, int &$fds, array &$ns, bool $onlyFiles = false): ?array
{
    $files = [];
    $nsOf = (string) @readlink("$proc/$pid/ns/mnt");
    $map = $nsOf !== '' && $nsOf !== (string) @readlink("$proc/1/ns/mnt") ? ($ns[$nsOf] ??= logsWakeMounts($proc, $pid)) : null;
    $host = $map !== null ? ($ns['host'] ??= logsWakeMounts($proc, 1)) : null;
    $add = function (string $p) use (&$files, $map, $host): void {
        if ($map !== null) {
            $p = logsWakeHostPath($p, $map, $host) ?? '';
        }
        if (str_starts_with($p, '/mnt/') && !in_array($p, $files, true) && count($files) < LOGS_WAKE_FILES * 4) {
            $files[] = mb_strcut($p, 0, LOGS_WAKE_PATH, 'UTF-8');
        }
    };
    $cwd = @readlink("$proc/$pid/cwd");
    if (is_string($cwd)) {
        $add($cwd);
    }
    $list = @scandir("$proc/$pid/fd") ?: [];
    foreach ($list as $fd) {
        if (!ctype_digit($fd)) {
            continue;
        }
        if (++$fds > LOGS_WAKE_FDS) {
            break;
        }
        $p = @readlink("$proc/$pid/fd/$fd");
        if (is_string($p) && $p !== '' && $p[0] === '/') {
            $add(preg_replace('/ \(deleted\)$/', '', $p));
        }
    }
    if ($onlyFiles && !$files) {
        return null;
    }
    $kind = logsWakeKind($proc, $pid);
    return $kind === null ? null : $kind + ['pid' => $pid, 'files' => $files];
}

/**
 * What a process is, by itself and the processes above it: [kind, prog, …] — kind container (cgroup: docker's id), vm
 * (qemu: its guest name), smb (smbd), nfs (nfsd), office (the office's job: its desk), mover (Unraid's mover), cron
 * (crond above it: the command), program. Null: gone meanwhile.
 */
function logsWakeKind(string $proc, int $pid): ?array
{
    $st = logsWakeStat("$proc/$pid/stat");
    if ($st === null) {
        return null;
    }
    $prog = mb_strcut($st[0], 0, 64, 'UTF-8');
    $cmd = fn (int $p): string => trim(str_replace("\0", ' ', (string) @file_get_contents("$proc/$p/cmdline", false, null, 0, 4096)));
    $own = $cmd($pid);
    $base = ['prog' => $prog, 'cmd' => mb_strcut($own, 0, LOGS_WAKE_PATH, 'UTF-8')];
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
    // above it: the office's job, Unraid's mover, cron
    $p = $pid;
    $child = $own;
    for ($i = 0; $i < 12 && $p > 1; $i++) {
        $line = $p === $pid ? $own : $cmd($p);
        if (preg_match('#/plugins/unraid-secretary-office/#', $line)) {
            $job = preg_match('#(?:job\.sh|agent\.php job)\s+([a-z][a-z0-9-]{0,40})#', $line, $j) ? $j[1]
                : (preg_match('#/(backup|smartmover|mediacache|gather)/#', $line, $j) ? ['backup' => 'backup', 'smartmover' => 'moverelli',
                    'mediacache' => 'embycache', 'gather' => 'gather'][$j[1]] : '');
            $desk = LOGS_WAKE_OFFICE[$job] ?? LOGS_WAKE_OFFICE[strtok($job, '-') ?: ''] ?? '';
            return ['kind' => 'office', 'desk' => $desk] + $base;
        }
        if (preg_match('#(?:^|/|\s)(?:mover|age_mover)(?:\s|$)#', $line)) {
            return ['kind' => 'mover'] + $base;
        }
        $pst = $p === $pid ? $st : logsWakeStat("$proc/$p/stat");
        if ($pst === null) {
            break;
        }
        if ($p !== $pid && $pst[0] === 'crond') {
            // the cron line's command: what crond started, without the shell it started it with
            $line = preg_replace('#^(?:/bin/|/usr/bin/)?(?:ba)?sh -c #', '', $child);
            return ['kind' => 'cron', 'name' => mb_strcut($line, 0, LOGS_WAKE_PATH, 'UTF-8')] + $base;
        }
        $child = $line;
        $p = $pst[2];
    }
    return ['kind' => 'program'] + $base;
}

/**
 * A process's mounts from /proc/<pid>/mountinfo: [mount point, root inside its filesystem, major:minor], the longest
 * mount point first
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function logsWakeMounts(string $proc, int $pid): array
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
function logsWakeHostPath(string $path, array $mounts, array $host): ?string
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

// ===================================================================== a wake

/**
 * Disks that woke between two looks at disks.ini: one wake (they woke together — parity with a data disk), its time
 * and its likely causes from the captures before it; named afterwards by the naming job.
 *
 * @param list<string> $woke
 */
function logsWakeRecord(array $woke, int $from, int $to, array $disks): void
{
    $m = &logsWakeMem();
    $caught = [];
    foreach ($woke as $name) {
        $c = $m['caught'][$name] ?? null;
        if ($c !== null && (int) $c['t'] >= $from - LOGS_WAKE_MATCH && (int) $c['t'] <= $to) {
            $caught[] = $c;
        }
    }
    $mounts = array_values(array_filter(array_map(fn (string $n): ?string => $disks[$n]['mount'] ?? null, $woke)));
    $wake = ['id' => bin2hex(random_bytes(6)), 'time' => $caught ? min(array_column($caught, 't')) : $to, 'from' => $from,
             'disks' => $woke, 'how' => $caught ? 'caught' : 'noticed', 'who' => logsWakeCauses($caught, $mounts),
             'cut' => (bool) array_filter(array_column($caught, 'cut')), 'named' => false];
    $all = logsWakesRead();
    $all['wakes'][] = $wake;
    foreach ($woke as $name) {
        $all['totals'][$name] = (int) ($all['totals'][$name] ?? 0) + 1;
    }
    logsWakesWrite($all);
    $first = $wake['who'][0] ?? null;
    logLine(sprintf('Ms. Protocolli: %s woke up — %s', implode(' + ', $woke), $first ? $first['kind'] . ' (' . $first['sure'] . ')' : 'not found out'));
    logsWakeNameStart();
}

/**
 * The causes of a wake, the surest first: caught waiting with a file on the woken disk (sure), with a file in a user
 * share (likely — shfs reaches the disk for it), waiting without a file under /mnt (maybe); without anybody waiting,
 * whoever had a file open on the woken disk (open). One per process, LOGS_WAKE_WHO at most.
 *
 * @param list<array> $caught  captures
 * @param list<string> $mounts the woken disks' mount points
 */
function logsWakeCauses(array $caught, array $mounts): array
{
    $on = function (string $p) use ($mounts): bool {
        foreach ($mounts as $mt) {
            if ($p === $mt || str_starts_with($p, "$mt/")) {
                return true;
            }
        }
        return false;
    };
    $rank = ['sure' => 0, 'likely' => 1, 'open' => 2, 'maybe' => 3];
    $out = [];
    foreach ($caught as $c) {
        foreach ([['who', true], ['open', false]] as [$list, $waiting]) {
            foreach ((array) ($c[$list] ?? []) as $w) {
                $mine = array_values(array_filter($w['files'], $on));
                $share = array_values(array_filter($w['files'], fn (string $p): bool => (bool) preg_match('#^/mnt/user0?/#', $p)));
                if (!$waiting && !$mine) {
                    continue;
                }
                $sure = $waiting ? ($mine ? 'sure' : ($share ? 'likely' : 'maybe')) : 'open';
                $files = array_slice(array_merge($mine, $share), 0, LOGS_WAKE_FILES);
                $key = $w['pid'] . '/' . $w['prog'];
                if (isset($out[$key]) && $rank[$out[$key]['sure']] <= $rank[$sure]) {
                    continue;
                }
                $out[$key] = array_intersect_key($w, array_flip(['kind', 'prog', 'cmd', 'id', 'name', 'desk', 'pid'])) + ['sure' => $sure, 'files' => $files];
            }
        }
    }
    $out = array_values($out);
    usort($out, fn (array $a, array $b): int => $rank[$a['sure']] <=> $rank[$b['sure']]);
    // the same thing more than once (Plex's threads, two smbd of one client): once, with its surest look
    $seen = [];
    $out = array_values(array_filter($out, function (array $w) use (&$seen): bool {
        $k = $w['kind'] . '|' . ($w['id'] ?? $w['name'] ?? $w['desk'] ?? '') . '|' . ($w['kind'] === 'smb' ? $w['pid'] : $w['prog']);
        if (isset($seen[$k])) {
            return false;
        }
        return $seen[$k] = true;
    }));
    return array_slice($out, 0, LOGS_WAKE_WHO);
}

// ===================================================================== the file

/** data/logs-wakes.json as kept — tolerant: unknown keys and entries stay as they are */
function logsWakesRead(?string $file = null): array
{
    $j = readJson($file ?? logsWakesFile()) ?? [];
    $j['v'] = 1;
    $j['wakes'] = is_array($j['wakes'] ?? null) && array_is_list($j['wakes']) ? $j['wakes'] : [];
    $j['totals'] = is_array($j['totals'] ?? null) ? $j['totals'] : [];
    $j['since'] = is_int($j['since'] ?? null) ? $j['since'] : time();
    return $j;
}

/** Only the last LOGS_WAKE_KEEP seconds, LOGS_WAKE_MAX at most (an entry without a time she can read stays) */
function logsWakesWrite(array $all, ?string $file = null): void
{
    $old = time() - LOGS_WAKE_KEEP;
    $all['wakes'] = array_slice(array_values(array_filter($all['wakes'],
        fn ($w): bool => !is_array($w) || !is_int($w['time'] ?? null) || $w['time'] >= $old)), -LOGS_WAKE_MAX);
    writeAtomic($file ?? logsWakesFile(), jsonEncode($all));
}

/** A wake as the page reads it — exactly its shape, else left out */
function logsWakeShaped(mixed $w): ?array
{
    if (!is_array($w) || !is_string($w['id'] ?? null) || !is_int($w['time'] ?? null) || !is_array($w['disks'] ?? null)
        || !in_array($w['how'] ?? null, ['caught', 'noticed', 'syslog'], true) || !is_array($w['who'] ?? null)) {
        return null;
    }
    $who = [];
    foreach ($w['who'] as $c) {
        if (is_array($c) && in_array($c['kind'] ?? null, LOGS_WAKE_KINDS, true) && is_string($c['prog'] ?? null)
            && in_array($c['sure'] ?? null, ['sure', 'likely', 'open', 'maybe'], true)) {
            $who[] = ['kind' => $c['kind'], 'prog' => $c['prog'], 'sure' => $c['sure'],
                      'name' => is_string($c['name'] ?? null) ? $c['name'] : '', 'desk' => is_string($c['desk'] ?? null) ? $c['desk'] : '',
                      'cmd' => is_string($c['cmd'] ?? null) ? $c['cmd'] : '',
                      'files' => array_values(array_filter((array) ($c['files'] ?? []), 'is_string'))];
        }
    }
    $strings = fn (string $k): array => array_values(array_filter((array) ($w[$k] ?? []), 'is_string'));
    return ['id' => $w['id'], 'time' => $w['time'], 'from' => is_int($w['from'] ?? null) ? $w['from'] : $w['time'],
            'disks' => array_values(array_filter($w['disks'], 'is_string')), 'how' => $w['how'], 'who' => $who,
            'cut' => !empty($w['cut']), 'named' => !empty($w['named']), 'cron' => $strings('cron'), 'started' => $strings('started'),
            'smart' => is_int($w['smart'] ?? null) ? $w['smart'] : null];
}

/** For her state: the rotating disks there are (no tile without one), since when she watches, the wakes */
function logsWakesForPage(?array $disks = null): array
{
    $all = logsWakesRead();
    $disks ??= logsWakeDisks();
    return ['hdds' => array_keys($disks), 'since' => $all['since'], 'look' => LOGS_WAKE_LOOK,
            'wakes' => array_values(array_filter(array_map('logsWakeShaped', $all['wakes'])))];
}

/** Her metric: wakes per disk since she started counting (a counter: never less, whatever the file keeps) */
function logsWakeMetrics(?string $file = null): array
{
    $totals = metricsCached($file ?? logsWakesFile(), fn (string $f): array => (array) ((readJson($f) ?? [])['totals'] ?? []));
    $samples = [];
    foreach ($totals as $disk => $n) {
        if (is_int($n) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', (string) $disk)) {
            $samples[] = [['disk' => (string) $disk], $n];
        }
    }
    return $samples ? [['name' => 'uso_logs_disk_wakes_total', 'type' => 'counter',
        'help' => 'Spinning disks woken up, as Ms. Protocolli noticed (since she started counting)', 'samples' => $samples]] : [];
}

// ===================================================================== naming afterwards (a job of its own)

/** The naming job, unless one runs (the next one names what is still unnamed) */
function logsWakeNameStart(): void
{
    $m = &logsWakeMem();
    if ($m['job'] !== null || !empty($GLOBALS['logsWakeNoJob'])) {        // tests name by calling logsWakeNameRun()
        return;
    }
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'logs-wake-name'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (is_resource($process)) {
        $m['job'] = ['process' => $process, 'since' => time()];
    }
}

/** Every tick while the naming job runs: done? Its answer (RAM) goes into the wakes (only her tick writes the file) */
function logsWakeNameDone(): void
{
    $m = &logsWakeMem();
    $status = proc_get_status($m['job']['process']);
    if ($status['running']) {
        if (time() - $m['job']['since'] > LOGS_WAKE_NAME_RUN) {
            proc_terminate($m['job']['process']);
        }
        return;
    }
    proc_close($m['job']['process']);
    $m['job'] = null;
    logsWakeNameMerge();
}

function logsWakeNamesFile(): string
{
    return $GLOBALS['logsWakeNamesFile'] ?? RUN_DIR . '/logs-wake-names.json';
}

/** The naming job's answer into the wakes it names (an answer for a wake that went is let go) */
function logsWakeNameMerge(): void
{
    $names = readJson(logsWakeNamesFile());
    @unlink(logsWakeNamesFile());
    if (!$names) {
        return;
    }
    $all = logsWakesRead();
    foreach ($all['wakes'] as &$w) {
        $n = is_array($w) && is_string($w['id'] ?? null) ? ($names[$w['id']] ?? null) : null;
        if (!is_array($n)) {
            continue;
        }
        foreach ($w['who'] as &$c) {
            if (($c['kind'] ?? '') === 'container' && isset($n['containers'][$c['id'] ?? ''])) {
                $c['name'] = (string) $n['containers'][$c['id']];
            } elseif (($c['kind'] ?? '') === 'smb' && isset($n['smb'][(string) ($c['pid'] ?? '')])) {
                $c['name'] = (string) $n['smb'][(string) $c['pid']];
            }
        }
        unset($c);
        $w['cron'] = array_values(array_filter((array) ($n['cron'] ?? []), 'is_string'));
        $w['started'] = array_values(array_filter((array) ($n['started'] ?? []), 'is_string'));
        if (is_int($n['smart'] ?? null)) {
            $w['smart'] = $n['smart'];
            if ($w['how'] === 'noticed' && $n['smart'] >= (int) $w['from'] - 60 && $n['smart'] <= (int) $w['time']) {
                $w['time'] = $n['smart'];          // nothing caught: Unraid's «read SMART» says when better than her look
                $w['how'] = 'syslog';
            }
        }
        $w['named'] = true;
    }
    unset($w);
    logsWakesWrite($all);
}

/**
 * php agent.php job logs-wake-name: names for the unnamed wakes, into RAM (logsWakeNamesFile()). $o (tests): what
 * Docker, smbstatus, the cron files and the syslog would say — docker, smb, cron, syslog.
 */
function logsWakeNameRun(array $o = []): int
{
    $wakes = array_values(array_filter(logsWakesRead()['wakes'], fn ($w): bool => is_array($w) && empty($w['named'])
        && is_string($w['id'] ?? null) && is_int($w['time'] ?? null)));
    if (!$wakes) {
        return 0;
    }
    $docker = $o['docker'] ?? logsWakeDocker();
    $smb = null;
    if (array_filter($wakes, fn (array $w): bool => in_array('smb', array_column((array) $w['who'], 'kind'), true))) {
        $smb = $o['smb'] ?? logsWakeSmb();
    }
    $cron = $o['cron'] ?? logsWakeCronLines();
    $syslog = $o['syslog'] ?? logsTail('/var/log/syslog', 2000);
    $out = readJson(logsWakeNamesFile()) ?? [];
    foreach ($wakes as $w) {
        $devs = array_values(array_filter(array_map(fn (string $d): string => (string) (logsWakeMem()['disks'][$d]['dev'] ?? ''), (array) $w['disks'])));
        $out[$w['id']] = [
            'containers' => $docker['names'],
            'smb' => $smb ?? [],
            'cron' => logsWakeCronDue($cron, (int) $w['time']),
            'started' => array_values(array_map(fn (array $c): string => $c[0],
                array_filter($docker['started'], fn (array $c): bool => $c[1] >= (int) $w['time'] - 120 && $c[1] <= (int) $w['time'] + 10))),
            'smart' => logsWakeSmartTime($syslog, $devs, (int) $w['from'] - 60, (int) $w['time'] + 120),
        ];
    }
    writeAtomic(logsWakeNamesFile(), jsonEncode($out), 0600, 0, 0);
    return 0;
}

/** Docker's containers: id => name, and when the running ones started ([name, time]) — nothing when Docker is off */
function logsWakeDocker(): array
{
    $out = ['names' => [], 'started' => []];
    if (bin('docker') === null || !file_exists('/var/run/docker.sock')) {
        return $out;
    }
    [$exit, $text] = run(['docker', 'ps', '-a', '--no-trunc', '--format', '{{.ID}}\t{{.Names}}\t{{.State}}'], 20);
    $running = [];
    foreach ($exit === 0 ? explode("\n", trim((string) $text)) : [] as $line) {
        $f = explode("\t", $line);
        if (count($f) === 3 && preg_match('/^[0-9a-f]{64}$/D', $f[0])) {
            $out['names'][$f[0]] = $f[1];
            $out['names'][substr($f[0], 0, 12)] = $f[1];
            if ($f[2] === 'running') {
                $running[] = $f[0];
            }
        }
    }
    if ($running) {
        [$exit, $text] = run(array_merge(['docker', 'inspect', '--format', '{{.Name}}|{{.State.StartedAt}}'], array_slice($running, 0, 200)), 20);
        foreach ($exit === 0 ? explode("\n", trim((string) $text)) : [] as $line) {
            [$name, $at] = explode('|', $line, 2) + ['', ''];
            $t = strtotime(preg_replace('/\.\d+/', '', $at));
            if ($t !== false && $name !== '') {
                $out['started'][] = [ltrim($name, '/'), $t];
            }
        }
    }
    return $out;
}

/** smbd's processes => the machine each serves (smbstatus -p; shown on her page only) */
function logsWakeSmb(): array
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

/** Root's cron lines: its crontab and /etc/cron.d (Unraid's plugins' lines are there) — [cron, command] */
function logsWakeCronLines(array $files = []): array
{
    $files = $files ?: array_merge(['/var/spool/cron/crontabs/root'], glob('/etc/cron.d/*') ?: []);
    $out = [];
    foreach ($files as $f) {
        foreach (@file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*((?:\S+\s+){4}\S+)\s+(.+)$/', $line, $c) && $line[0] !== '#' && cronValid($c[1])) {
                $out[] = [$c[1], trim($c[2])];
            }
        }
    }
    return $out;
}

/**
 * The cron lines due in the wake's minute or the one before — not the ones due every few minutes (they would have kept
 * the disk awake: they don't wake it). The office's own lines by its job (job.sh <job>), the rest by their command.
 */
function logsWakeCronDue(array $lines, int $t): array
{
    $out = [];
    foreach ($lines as [$cron, $cmd]) {
        $f = cronFields($cron);
        if ($f === null || count($f[0]) * count($f[1]) > 4 * 24 || !(cronMatches($f, $t) || cronMatches($f, $t - 60))) {
            continue;
        }
        $out[] = preg_match('#/plugins/unraid-secretary-office/scripts/job\.sh ([a-z]+)#', $cmd, $j) ? 'office:' . $j[1]
            : mb_strcut(preg_replace('#\s*>\s*/dev/null.*$|\s+2>&1.*$#', '', $cmd), 0, LOGS_WAKE_PATH, 'UTF-8');
        if (count($out) >= 3) {
            break;
        }
    }
    return array_values(array_unique($out));
}

/** Unraid's «emhttpd: read SMART /dev/sdX» after a wake: its time (the syslog's year: this one's) — null when none */
function logsWakeSmartTime(array $lines, array $devs, int $from, int $to): ?int
{
    $best = null;
    foreach ($lines as $line) {
        if (!preg_match(LOGS_WAKE_SMART, $line, $s) || !in_array($s[6], $devs, true)) {
            continue;
        }
        $t = strtotime("{$s[2]} {$s[1]} " . date('Y', $to) . " {$s[3]}:{$s[4]}:{$s[5]}");
        if ($t !== false && $t > $to + 86400) {
            $t = strtotime('-1 year', $t);          // a line from December read in January
        }
        if ($t !== false && $t >= $from && $t <= $to && ($best === null || $t < $best)) {
            $best = $t;
        }
    }
    return $best;
}
