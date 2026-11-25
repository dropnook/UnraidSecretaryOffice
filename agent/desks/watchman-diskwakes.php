<?php
declare(strict_types=1);

/*
 * «Who woke my disks?» — the Night Watchman notices when a spinning disk wakes up and who asked for it (2026-10-20 at
 * Ms. Protocolli's desk; his since 2026-11-22, beside «What holds the array?» — both say who touches the disks).
 * Functions only, in a file of their own beside his desk (the agent's desk glob loads it, watchman.php require_once's
 * it): its name sorts before watchman.php in every locale (glob() sorts by strcoll — en_US ignores the «-», so the part
 * after it must sort before «php»: «diskwakes» does, «wakes» would not), or the glob would load it a second time after
 * watchman.php had. Who a process is: agent/lib/procwho.php (shared with arrayhold.php).
 * Read only. The tick and the capture read RAM only: disks.ini, /proc, /sys and the syslog — never a disk, never a walk or a
 * stat under /mnt. The one look at a disk is the trail (below): only the disk that just woke, only after the wake, only
 * from the naming job, bounded in time and entries.
 *
 * Unraid logs a spin-down («emhttpd: spinning down /dev/sdX»), never the wake, so he watches himself, in his tick:
 *   every WATCH_WAKE_INI s   disks.ini (sleepingDisks()'s rule: spun down AND rotating — an SSD/NVMe never counts);
 *                           a rotating disk going asleep → awake there is a wake (watchmanWakeRecord())
 *   every WATCH_WAKE_LOOK s  only while a rotating disk sleeps: /proc/diskstats. A sleeping disk's reads/writes moving,
 *                           or a request in flight, means somebody is asking for it right now — and whoever asked
 *                           waits (state D) while it spins up. Then, at once, a capture (watchmanWakeCapture()): the
 *                           processes in state D with their files under /mnt, their container (cgroup), VM (qemu),
 *                           SMB (smbd), the office's job, Unraid's mover or the cron line above them, or who started a
 *                           plain program (Unraid, its web page, a plugin, a user script, a terminal). Capped by time
 *                           and count; a capture without a flip after it (a SMART look in standby) is let go.
 * What a capture learnt on a real server (1.68.0): only Unraid's own look right after the wake was ever caught
 * (emhttpd's `smartctl -A`, `hdparm -C`) — so that is never a cause now, only said apart (`unraid`), and he looks further:
 *   - writes don't wait: the page cache takes them, the kernel's flush wakes the disk up to half a minute later, nobody
 *     in state D. So while a disk sleeps he keeps a short list of who has a file open FOR WRITING on it (renewed every
 *     WATCH_WAKE_HOLD s, budgeted like a capture) and reads only their write counters (/proc/<pid>/io) at every look; a
 *     counter that moved and a file offset that moved = «had written to it shortly before» (`wrote`);
 *   - through a user share shfs waits, not its client: shfs's own open files (/mnt/disk2/Docs/a.odt) say which disk a
 *     client's /mnt/user/Docs/a.odt lies on, so the client is named by that disk; a share's file shfs doesn't hold
 *     counts by the share's disks (shares.ini);
 *   - a capture that found nobody gets a second look WATCH_WAKE_AGAIN_LOOK s later (a short reader comes and goes).
 * Names that need a command (a container's name, an SMB client's machine) and what was due then (cron lines, containers
 * just started, Unraid's «read SMART /dev/sdX» as a second time) come afterwards from a short job of its own
 * (watchmanWakeNameRun(), `php agent.php job watchman-wake-name`), never in the tick.
 * The trail (2026-11-08: a script wrote a clip through /mnt/user and left within seconds — nobody in state D, never on
 * the writers' list): the same job names what was written on the woken disk itself around the wake, by folder
 * (watchmanWakeTrail()) — a hint, not a cause, and only while the disk is awake (looking then costs no further wake), only
 * for a wake of the last WATCH_WAKE_TRAIL_LATE s, never on another disk or one asleep again. btrfs keeps a transaction id
 * per change: the tick notes the disk's generation from /sys when it falls asleep (RAM), and `btrfs subvolume find-new`
 * lists only what came after (every writable subvolume; read-only snapshots and `.btrfs-snap` left out). XFS, ZFS and the
 * rest keep no such list: a walk of the woken disk's own mounts (`find -xdev`, `.zfs` pruned) for folders changed since
 * it slept, then their files changed since — bounded, cut and said so; a file changed in place in an old folder escapes
 * it. Left out: the office's own data folder and Unraid's `.btrfs-snap`.
 *
 * data/watchman/wakes.json {"v":1, "since", "wakes":[…], "totals":{disk: n}} (up to 1.95 Ms. Protocolli's data/logs-wakes.json,
 * moved once by the migration step `wakes-watchman`, lib/migrate.php) — written only by his tick (the naming job
 * hands its answer over in RAM); the last WATCH_WAKE_KEEP seconds, at most WATCH_WAKE_MAX wakes; tolerant: entries and
 * keys it doesn't know are kept as they are. The sampler's memory (what slept at the last look, when that was) lies in
 * RAM (RUN_DIR/watchman-wakes.json): a restarted agent still sees a flip, the array's stop forgets it.
 * His switch (owner, 2026-10-20: on unless switched off — wakes happen when nobody looks): `watch` in that file, set
 * only by his action `wakes_watch` (watchmanWakeWatchSet()). Off, his tick returns at once — no disks.ini, no diskstats, no
 * capture — and what he noticed stays; on again, he starts anew (no wake across the time he didn't look).
 * A machine name or a path only on his page — never in the agent's log (a report carries its lines): the log says the
 * disk and the kind of cause.
 */

/*
 * How often he looks at /proc/diskstats while a rotating disk sleeps. A disk spins up in 6 s or more (often 10–15 s),
 * and whoever woke it waits in state D all that time: a look every 4 s lands inside every spin-up. Not every second
 * (owner, 2026-10-20): the agent naps between its deadlines, and a wake-up every second keeps the CPU out of its deep
 * sleep states for nothing.
 */
const WATCH_WAKE_FILE      = 'watchman/wakes.json';    // below DATA_DIR (up to 1.95 Ms. Protocolli's logs-wakes.json: lib/migrate.php)
const WATCH_WAKE_MEM       = 'watchman-wakes.json';    // below RUN_DIR: the sampler's memory (up to 1.95 logs-wakes.json)
const WATCH_WAKE_LOOK      = 4;
const WATCH_WAKE_INI       = 8;            // disks.ini every second look (asleep → awake: the wake itself)
const WATCH_WAKE_INI_NONE  = 60;           // … every minute when there is no rotating disk at all
const WATCH_WAKE_AGAIN     = 20;           // one capture per disk in this many seconds at most
const WATCH_WAKE_MATCH     = 180;          // a capture counts for a wake noticed this many seconds later at most
const WATCH_WAKE_BUDGET_MS = 60;           // a capture stops after this many milliseconds (it says so: `cut`)
const WATCH_WAKE_TASKS     = 6000;         // … or this many processes and threads looked at
const WATCH_WAKE_D         = 20;           // processes in state D looked at closer, at most
const WATCH_WAKE_WHO       = 5;            // causes kept per wake
const WATCH_WAKE_FILES     = 5;            // files kept per cause
const WATCH_WAKE_KEEP      = 7 * 86400;    // wakes kept this long
const WATCH_WAKE_MAX       = 1000;         // and this many at most
const WATCH_WAKE_LATE      = 6 * 3600;     // a flip after a longer gap (the agent away) is not counted: when, nobody knows
const WATCH_WAKE_NAME_RUN  = 60;           // seconds the naming job may take
const WATCH_WAKE_KINDS     = ['container', 'vm', 'smb', 'nfs', 'office', 'mover', 'cron', 'program'];
const WATCH_WAKE_SYSLOG_BYTES = 1 << 20;  // the naming job reads at most this much of the syslog's end («read SMART» lines)
const WATCH_WAKE_SMART     = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d):(\d\d):(\d\d)\s+\S+\s+emhttpd: read SMART \/dev\/([a-z0-9]+)\s*$/';
const WATCH_WAKE_AGAIN_LOOK = 1;           // a capture that found nobody: one more look this many seconds later
const WATCH_WAKE_HOLD      = 60;           // the writers' list (files open for writing on a sleeping disk) renewed this often
const WATCH_WAKE_HOLDERS   = 30;           // … this many processes at most: their write counters read at every look
const WATCH_WAKE_SHFS      = 400;          // shfs's open files read at most (which disk a user share's file lies on)
// how sure, the surest first: waiting with a file on it · wrote to it · waiting with a share's file · a file open · waiting
const WATCH_WAKE_SURE      = ['sure' => 0, 'wrote' => 1, 'likely' => 2, 'open' => 3, 'maybe' => 4];
// who started a plain program: Unraid itself, its web page, a plugin, a user script, a terminal or SSH session
const WATCH_WAKE_BY        = ['unraid', 'web', 'plugin', 'user_script', 'session'];
// the trail: what was written on the woken disk around the wake (watchmanWakeTrail(), the naming job only)
const WATCH_WAKE_TRAIL_LATE  = 300;        // only for a wake this many seconds old at most (later writes would blur it)
const WATCH_WAKE_TRAIL_AFTER = 30;         // a file changed this long after the wake still counts (a copy going on)
const WATCH_WAKE_TRAIL_RUN   = 25;         // seconds all trails of one naming job may take (the job has WATCH_WAKE_NAME_RUN)
const WATCH_WAKE_TRAIL_FILES = 2000;       // files looked at (find-new's or a walk's), at most
const WATCH_WAKE_TRAIL_OUT   = 4 << 20;    // bytes of find-new's or find's answer read, at most
const WATCH_WAKE_TRAIL_DIRS  = 4;          // folders kept per wake (the earliest written first)
const WATCH_WAKE_TRAIL_WALK  = 300;        // changed folders a walk looks into, at most
const WATCH_WAKE_TRAIL_TREES = 16;         // btrfs subvolumes asked, at most
const WATCH_WAKE_TRAIL_GENS  = 4;          // btrfs without the generation from before the sleep: the last this many transactions

require_once __DIR__ . '/../lib/watchnet.php';        // watchnetBases(): which disks and pools a user share lies on
require_once __DIR__ . '/../lib/procwho.php';         // who a process is (shared with «What holds the array?»)

function watchmanWakesFile(): string
{
    return $GLOBALS['watchmanWakesFile'] ?? DATA_DIR . '/' . WATCH_WAKE_FILE;
}

function watchmanWakeMemFile(): string
{
    return $GLOBALS['watchmanWakeMemFile'] ?? RUN_DIR . '/' . WATCH_WAKE_MEM;
}

/** /proc (tests: a tree of their own) */
function watchmanWakeProc(): string
{
    return $GLOBALS['watchmanWakeProc'] ?? '/proc';
}

/** Seconds on the monotonic clock: a clock stepped back never stops his looking */
function watchmanWakeMono(): float
{
    return $GLOBALS['watchmanWakeMonoNow'] ?? hrtime(true) / 1e9;
}

// ===================================================================== the disks

/**
 * The rotating disks of disks.ini (array, parity, pools — never the flash, never an SSD/NVMe), by name: their device
 * (sdX), asleep by diskAsleep() and where they are mounted (/mnt/disk3, a pool's /mnt/<pool>; parity: nowhere).
 *
 * @return array<string, array{dev: string, asleep: bool, mount: ?string}>
 */
function watchmanWakeDisks(?array $ini = null): array
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
        // an NVMe device is never a rotating one (its driver says rotational 0, Unraid writes that; never spun down): left
        // out by its name too, should a disks.ini ever lack the key — so a woken disk here is never an NVMe (watchmanWakeBystander())
        if (($v['rotational'] ?? '1') === '0' || $type === 'flash' || str_starts_with($dev, 'nvme') || !preg_match('/^[a-z][a-z0-9]{1,15}$/D', $dev)
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
function watchmanWakeDiskstats(?string $file = null): array
{
    $out = [];
    foreach (@file($file ?? watchmanWakeProc() . '/diskstats', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) >= 12 && preg_match('/^[a-z][a-z0-9]{1,15}$/D', $f[2])) {
            $out[$f[2]] = [(int) $f[3], (int) $f[7], (int) $f[11]];
        }
    }
    return $out;
}

// ===================================================================== the tick

/** The sampler's memory: from RAM at the first call (a restarted agent still knows what slept) */
function &watchmanWakeMem(): array
{
    if (!isset($GLOBALS['watchmanWake'])) {
        $m = readJson(watchmanWakeMemFile()) ?? [];
        $GLOBALS['watchmanWake'] = ['next' => -INF, 'ini_next' => -INF,
            'disks' => is_array($m['disks'] ?? null) ? $m['disks'] : null, 'look' => is_int($m['look'] ?? null) ? $m['look'] : null,
            'stats' => [], 'caught' => [], 'again' => [], 'job' => null, 'on' => watchmanWakesRead()['watch'],
            'hold' => [], 'hold_next' => -INF, 'hold_pos' => 0, 'window' => watchmanWakeWindow(), 'retry' => null];
    }
    return $GLOBALS['watchmanWake'];
}

/** His tick's part: nothing but a clock compare until the next look is due (see the top of the file) */
function watchmanWakeTick(): void
{
    $m = &watchmanWakeMem();
    if ($m['job'] !== null) {
        watchmanWakeNameDone();
    }
    if (!$m['on']) {
        return;                         // switched off: nothing looked at, nothing asked
    }
    $mono = watchmanWakeMono();
    if ($m['retry'] !== null && $mono >= $m['retry']['at']) {
        watchmanWakeRetry();
    }
    if ($mono < $m['next']) {
        return;
    }
    $m['next'] = $mono + WATCH_WAKE_LOOK;
    if ($mono >= $m['ini_next']) {
        watchmanWakeIniLook($mono);
    }
    if (!$m['disks'] || !in_array(true, array_column($m['disks'], 'asleep'), true)) {
        $m['stats'] = $m['hold'] = [];
        $m['hold_next'] = -INF;
        $m['retry'] = null;
        return;
    }
    watchmanWakeStatsLook();
}

/** disks.ini: what sleeps now — and what was asleep at the last look and is awake now (a wake) */
function watchmanWakeIniLook(float $mono): void
{
    $m = &watchmanWakeMem();
    $now = time();
    // stopped, or he isn't hired: nothing sleeps or wakes for him; the next start begins anew
    if (!arrayRunning($GLOBALS['watchmanWakeVarIni'] ?? '/var/local/emhttp/var.ini')
        || !in_array('watchman', staffHired($GLOBALS['agentStaffFile'] ?? null), true)) {
        $m['ini_next'] = $mono + WATCH_WAKE_INI_NONE;
        if ($m['disks'] !== null) {
            $m['disks'] = null;
            $m['caught'] = [];
            @unlink(watchmanWakeMemFile());
        }
        return;
    }
    $disks = watchmanWakeDisks();
    $m['ini_next'] = $mono + ($disks ? WATCH_WAKE_INI : WATCH_WAKE_INI_NONE);
    if ($disks && empty($m['kept'])) {          // since when he watches: kept once, at his first look
        $m['kept'] = true;
        $all = readJson(watchmanWakesFile());
        if (!is_int($all['since'] ?? null)) {
            watchmanWakesWrite(watchmanWakesRead());
        }
    }
    $before = $m['disks'];
    $woke = [];
    foreach ($disks as $name => $d) {
        if (!$d['asleep'] && !empty($before[$name]['asleep'])) {
            $woke[] = $name;
        }
    }
    $look = $m['look'];
    $changed = $before === null || array_map(fn ($d) => $d['asleep'], $disks) !== array_map(fn ($d) => !empty($d['asleep']), $before);
    // asleep since when, and (btrfs) its generation then — kept from the first look that saw it asleep (the trail's start)
    $m['disks'] = [];
    foreach ($disks as $name => $d) {
        $was = $before[$name] ?? null;
        $sleep = !$d['asleep'] ? [] : (!empty($was['asleep']) && is_int($was['since'] ?? null)
            ? ['since' => $was['since'], 'gen' => is_int($was['gen'] ?? null) ? $was['gen'] : null]
            : ['since' => $now, 'gen' => is_string($d['mount']) ? watchmanWakeBtrfsGen($d['mount']) : null]);
        $m['disks'][$name] = ['dev' => $d['dev'], 'asleep' => $d['asleep'], 'mount' => $d['mount']] + $sleep;
    }
    $m['look'] = $now;
    if ($woke && $look !== null && $now - $look <= WATCH_WAKE_LATE) {
        try {
            watchmanWakeRecord($woke, $look, $now, $disks, array_intersect_key((array) $before, array_flip($woke)));
        } catch (Throwable $e) {
            logLine('Night watchman: a wake could not be kept — ' . $e->getMessage());
        }
    }
    foreach ($m['caught'] as $name => $c) {
        if ($now - (int) $c['t'] > WATCH_WAKE_MATCH || in_array($name, $woke, true)) {
            unset($m['caught'][$name]);                 // used, or no wake came after it
        }
    }
    // in RAM: only when something changed — or now and then, so a restarted agent knows how old its memory is
    if ($changed || $woke || $now - (int) ($m['saved'] ?? 0) >= 300) {
        $m['saved'] = $now;
        @mkdir(dirname(watchmanWakeMemFile()), 0700, true);
        writeAtomic(watchmanWakeMemFile(), jsonEncode(['disks' => $m['disks'], 'look' => $now]), 0600, 0, 0);
    }
}

/**
 * /proc/diskstats for the sleeping disks: a sleeping disk asked for → a capture, at once (the writers' counters read
 * first); a capture that found nobody gets a second look WATCH_WAKE_AGAIN_LOOK s later
 */
function watchmanWakeStatsLook(): void
{
    $m = &watchmanWakeMem();
    watchmanWakeHoldLook(watchmanWakeMono());
    $stats = watchmanWakeDiskstats();
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
    $mono = watchmanWakeMono();
    $asked = array_values(array_filter($asked, fn (string $n): bool => $mono - ($m['again'][$n] ?? -INF) >= WATCH_WAKE_AGAIN));
    if (!$asked) {
        return;
    }
    $c = watchmanWakeCapture(null, ['hold' => $m['hold'], 'mono' => $mono, 'window' => $m['window'], 'mounts' => watchmanWakeMounts($asked)]);
    foreach ($asked as $name) {
        $m['again'][$name] = $mono;
        $m['caught'][$name] = $c;
    }
    // nobody caught with a file (only shfs, or somebody merely waiting — `maybe`): a second look
    if (!$c['wrote'] && !array_filter($c['who'], fn (array $w): bool => $w['prog'] !== 'shfs' && $w['files'])) {
        $m['retry'] = ['at' => $mono + WATCH_WAKE_AGAIN_LOOK, 'disks' => $asked];
    }
}

/** The second look: whoever came since goes into the capture it follows */
function watchmanWakeRetry(): void
{
    $m = &watchmanWakeMem();
    $disks = $m['retry']['disks'];
    $m['retry'] = null;
    $c = watchmanWakeCapture(null, ['hold' => $m['hold'], 'mono' => watchmanWakeMono(), 'window' => $m['window'], 'mounts' => watchmanWakeMounts($disks)]);
    foreach ($disks as $name) {
        if (isset($m['caught'][$name])) {
            $m['caught'][$name] = watchmanWakeMergeCaptures($m['caught'][$name], $c);
        }
    }
}

/** The mount points of these disks (his memory's): what a capture's «waiting with a file there» means */
function watchmanWakeMounts(array $names): array
{
    $m = &watchmanWakeMem();
    return array_values(array_unique(array_filter(array_map(fn (string $n): ?string => $m['disks'][$n]['mount'] ?? null, $names), 'is_string')));
}

/** Two looks at one wake as one capture: everybody of both, the first one's time */
function watchmanWakeMergeCaptures(array $a, array $b): array
{
    foreach (['who', 'open', 'wrote', 'unraid'] as $k) {
        $a[$k] = array_merge((array) ($a[$k] ?? []), (array) ($b[$k] ?? []));
    }
    $a['unraid'] = array_values(array_unique($a['unraid']));
    $a['cut'] = !empty($a['cut']) || !empty($b['cut']);
    $a['ms'] = (int) ($a['ms'] ?? 0) + (int) ($b['ms'] ?? 0);
    $a['looks'] = (int) ($a['looks'] ?? 1) + 1;
    return $a;
}

// ===================================================================== the writers

/**
 * How long written data may wait in the page cache before the kernel's flush writes it out (and wakes the disk): the
 * kernel's dirty_expire + dirty_writeback, two looks and a margin — 48 s with Linux's defaults
 */
function watchmanWakeWindow(?string $proc = null): int
{
    $proc ??= watchmanWakeProc();
    $cs = fn (string $f, int $default): int => is_numeric($v = trim((string) @file_get_contents("$proc/sys/vm/$f"))) ? (int) $v : $default;
    return max(30, min(600, intdiv($cs('dirty_expire_centisecs', 3000) + $cs('dirty_writeback_centisecs', 500), 100) + 2 * WATCH_WAKE_LOOK + 5));
}

/**
 * The writers' list while a disk sleeps: renewed every WATCH_WAKE_HOLD s (watchmanWakeHolders()), between that only every
 * holder's write counter is read (one small RAM file each). A holder whose file is closed or who ended is kept as long
 * as what it wrote may still wake the disk.
 */
function watchmanWakeHoldLook(float $mono): void
{
    $m = &watchmanWakeMem();
    $proc = watchmanWakeProc();
    if ($mono >= $m['hold_next']) {
        $m['hold_next'] = $mono + WATCH_WAKE_HOLD;
        $m['window'] = watchmanWakeWindow($proc);
        $bases = [];
        foreach ($m['disks'] as $d) {
            if (!empty($d['asleep']) && is_string($d['mount'] ?? null)) {
                $bases[basename($d['mount'])] = true;
            }
        }
        $h = watchmanWakeHolders($proc, array_keys($bases), (int) $m['hold_pos']);
        $m['hold_pos'] = $h['next'];
        $new = $h['hold'];
        foreach ($m['hold'] as $pid => $o) {
            $x = $new[$pid] ?? null;
            if ($x !== null && $x['prog'] === $o['prog'] && empty($o['gone'])) {
                // the same process: its counter against the last one, the offsets it had when first seen
                $new[$pid]['moved'] = $x['w'] > $o['w'] ? $mono : $o['moved'];
                $new[$pid]['pos'] = $o['pos'] + $x['pos'];
            } elseif ($x === null && !in_array($pid, $h['looked'], true) && empty($o['gone'])) {
                $new[$pid] = $o;                // not looked at this time (a scan cut short): kept as it was
            } elseif ($x === null && $o['moved'] !== null && $mono - $o['moved'] <= $m['window']) {
                $new[$pid] = ['gone' => true] + $o;
            }
        }
        $m['hold'] = array_slice($new, 0, 2 * WATCH_WAKE_HOLDERS, true);
        return;
    }
    foreach ($m['hold'] as $pid => $x) {
        if (!empty($x['gone'])) {
            if ($x['moved'] === null || $mono - $x['moved'] > $m['window']) {
                unset($m['hold'][$pid]);
            }
            continue;
        }
        $w = watchmanWakeIo($proc, (int) $pid);
        if ($w === null) {
            $m['hold'][$pid]['gone'] = true;
        } elseif ($w > $x['w']) {
            $m['hold'][$pid]['w'] = $w;
            $m['hold'][$pid]['moved'] = $mono;
        }
    }
}

/**
 * Who has a file open for writing on a sleeping disk — directly (/mnt/disk3/…, /mnt/<pool>/…) or through a user share
 * (by the disk shfs has the file on): what it is, those files, their offsets and its write counter. Budgeted like a
 * capture; a scan cut short goes on where it stopped the next time. /proc only.
 *
 * @param list<string> $bases the sleeping disks' and pools' folders under /mnt (disk3, cache)
 * @return array{hold: array<int, array>, next: int, looked: list<int>, cut: bool, ms: int}
 */
function watchmanWakeHolders(string $proc, array $bases, int $from = 0): array
{
    $t0 = hrtime(true);
    $self = getmypid();
    $pids = procwhoPids($proc);
    sort($pids);
    $i = 0;
    while ($i < count($pids) && $pids[$i] < $from) {
        $i++;
    }
    $pids = array_merge(array_slice($pids, $i), array_slice($pids, 0, $i));
    $keep = fn (string $p): bool => (bool) preg_match('#^/mnt/([^/]+)/#', $p, $b) && ($b[1] === 'user' || $b[1] === 'user0' || in_array($b[1], $bases, true));
    $fds = 0;
    $ns = [];
    $seen = [];
    $looked = [];
    $cut = false;
    $next = 0;
    foreach ($bases ? $pids : [] as $pid) {
        if ($fds >= PROCWHO_FDS || hrtime(true) - $t0 > WATCH_WAKE_BUDGET_MS * 1e6) {
            $cut = true;
            $next = $pid;
            break;
        }
        $looked[] = $pid;
        if ($pid !== $self && ($files = procwhoFiles($proc, $pid, $fds, $ns, $keep, WATCH_WAKE_SHFS, false))) {
            $seen[$pid] = $files;
        }
    }
    // shfs's own files are the user shares' files on the disks: which disk a client's /mnt/user file lies on
    $real = [];
    foreach ($seen as $pid => $files) {
        if ((procwhoStat("$proc/$pid/stat")[0] ?? '') === 'shfs') {
            $real += watchmanWakeShfsMap(array_keys($files));
            unset($seen[$pid]);
        }
    }
    $hold = [];
    foreach ($seen as $pid => $files) {
        $mine = $pos = [];
        foreach ($files as $p => $fd) {
            $p = watchmanWakeReal((string) $p, $real);
            if (!preg_match('#^/mnt/([^/]+)/#', $p, $b) || !in_array($b[1], $bases, true) || count($mine) >= WATCH_WAKE_FILES) {
                continue;
            }
            $info = watchmanWakeFdInfo($proc, $pid, $fd);
            if ($info !== null && ($info['flags'] & 3) !== 0) {        // O_WRONLY or O_RDWR
                $mine[$fd] = $p;
                $pos[$fd] = $info['pos'];
            }
        }
        if (!$mine) {
            continue;
        }
        if (count($hold) >= WATCH_WAKE_HOLDERS) {
            $cut = true;
            break;
        }
        $w = watchmanWakeIo($proc, $pid);
        $kind = $w === null ? null : procwhoKind($proc, $pid);
        if ($kind !== null) {
            $hold[$pid] = $kind + ['pid' => $pid, 'files' => array_values($mine), 'fds' => $mine, 'pos' => $pos, 'w' => $w, 'moved' => null];
        }
    }
    return ['hold' => $hold, 'next' => $next, 'looked' => $looked, 'cut' => $cut, 'ms' => (int) round((hrtime(true) - $t0) / 1e6)];
}

/** A process's write counter: bytes it had written for storage (/proc/<pid>/io write_bytes) — null when gone */
function watchmanWakeIo(string $proc, int $pid): ?int
{
    $s = @file_get_contents("$proc/$pid/io");
    return is_string($s) && preg_match('/^write_bytes:\s*(\d+)$/m', $s, $w) ? (int) $w[1] : null;
}

/** An open file's offset and how it was opened (/proc/<pid>/fdinfo/<fd>) — null when closed */
function watchmanWakeFdInfo(string $proc, int $pid, int $fd): ?array
{
    $s = @file_get_contents("$proc/$pid/fdinfo/$fd");
    if (!is_string($s) || !preg_match('/^pos:\s*(\d+)$/m', $s, $p) || !preg_match('/^flags:\s*([0-7]+)$/m', $s, $f)) {
        return null;
    }
    return ['pos' => (int) $p[1], 'flags' => (int) octdec($f[1])];
}

/** shfs's open files on the disks: a share's path inside (Docs/a.odt) => where it lies (/mnt/disk2/Docs/a.odt) */
function watchmanWakeShfsMap(array $paths): array
{
    $out = [];
    foreach ($paths as $p) {
        if (preg_match('#^/mnt/([^/]+)/(.+)$#', (string) $p, $r) && $r[1] !== 'user' && $r[1] !== 'user0') {
            $out[$r[2]] = (string) $p;
        }
    }
    return $out;
}

/** A user share's path by the disk shfs has it on — unchanged when shfs doesn't hold it */
function watchmanWakeReal(string $path, array $real): string
{
    return preg_match('#^/mnt/user0?/(.+)$#', $path, $r) && isset($real[$r[1]]) ? $real[$r[1]] : $path;
}

// ===================================================================== the capture

/**
 * Who waits for a disk right now: every process with a task in state D (uninterruptible — the spin-up), looked at
 * closer (procwhoWho()) — Unraid's own look at a disk that just woke (watchmanWakeReaction()) only said apart (`unraid`),
 * who reads only what never reaches a rotating disk (watchmanWakeBystander()) left out. shfs and, as a fallback, whoever
 * holds a file open on a mounted disk only while nobody was caught waiting WITH a file — on the disks asked for
 * ($ctx['mounts']; not given: anywhere under /mnt) or in a user share: somebody merely in state D (`maybe` — a slow sensor
 * read is state D too, 2026-11-25) never hides them. A user share's file by the
 * disk shfs has it on. The writers' list ($ctx: hold, mono, window — watchmanWakeHoldLook()) gives who wrote shortly
 * before (`wrote`; a counter that moved but no offset: `open`). Stops after WATCH_WAKE_BUDGET_MS, WATCH_WAKE_TASKS tasks
 * or PROCWHO_FDS files (`cut`). Reads /proc only.
 *
 * @return array{t: int, ms: int, cut: bool, who: list<array>, open: list<array>, wrote: list<array>, unraid: list<string>}
 */
function watchmanWakeCapture(?string $proc = null, array $ctx = []): array
{
    $proc ??= watchmanWakeProc();
    $t0 = hrtime(true);
    $over = fn (): bool => hrtime(true) - $t0 > WATCH_WAKE_BUDGET_MS * 1e6;
    $self = getmypid();
    $tasks = 0;
    $cut = false;
    $waiting = [];
    $shfs = [];
    $pids = procwhoPids($proc);
    foreach ($pids as $pid) {
        if ($tasks >= WATCH_WAKE_TASKS || $over()) {
            $cut = true;
            break;
        }
        $tasks++;
        $st = procwhoStat("$proc/$pid/stat");
        if ($st === null || $pid === $self) {
            continue;
        }
        if ($st[0] === 'shfs' && !$st[3]) {
            $shfs[] = $pid;
        }
        $d = $st[1] === 'D';
        if (!$d && $st[4] > 1) {
            foreach (@scandir("$proc/$pid/task") ?: [] as $tid) {
                if (!ctype_digit($tid) || (int) $tid === $pid) {
                    continue;
                }
                $tasks++;
                $t = procwhoStat("$proc/$pid/task/$tid/stat");
                if ($t !== null && $t[1] === 'D') {
                    $d = true;
                    break;
                }
            }
        }
        // kernel threads tell nobody's name — but nfsd is the NFS server answering a client
        if ($d && (!$st[3] || $st[0] === 'nfsd') && count($waiting) < WATCH_WAKE_D) {
            $waiting[] = $pid;
        }
    }
    $fds = 0;
    $ns = [];
    // shfs's own open files: which disk a user share's file lies on
    $real = [];
    foreach ($shfs as $pid) {
        $real += watchmanWakeShfsMap(array_keys(procwhoFiles($proc, $pid, $fds, $ns, null, WATCH_WAKE_SHFS, false)));
    }
    $byDisk = function (array $w) use ($real): array {
        $w['files'] = array_values(array_unique(array_map(fn (string $p): string => watchmanWakeReal($p, $real), $w['files'])));
        return $w;
    };
    $mounts = isset($ctx['mounts']) ? (array) $ctx['mounts'] : null;
    $firm = function (array $w) use ($mounts): bool {
        foreach ($w['files'] as $p) {
            if ($mounts === null || preg_match('#^/mnt/user0?/#', $p)) {
                return true;
            }
            foreach ($mounts as $mt) {
                if ($p === $mt || str_starts_with($p, "$mt/")) {
                    return true;
                }
            }
        }
        return false;
    };
    $people = $through = $unraid = [];
    $found = false;                             // somebody caught waiting with a file there
    foreach ($waiting as $pid) {
        $w = procwhoWho($proc, $pid, $fds, $ns);
        if ($w === null || watchmanWakeBystander($w)) {
            continue;
        }
        if (watchmanWakeReaction($w)) {
            $unraid[] = $w['prog'];             // Unraid looking at the disk that woke: its answer, never the cause
        } elseif ($w['prog'] === 'shfs') {
            $through[] = $w;                    // shfs waits on behalf of whoever used a user share: a share, not a person
        } else {
            $people[] = $w = $byDisk($w);
            $found = $found || $firm($w);
        }
    }
    // the writers' list: a write counter that moved shortly before and a file offset that moved (or the file closed since)
    $mono = (float) ($ctx['mono'] ?? watchmanWakeMono());
    $wrote = $open = [];
    foreach ((array) ($ctx['hold'] ?? []) as $pid => $h) {
        if (($h['moved'] ?? null) === null || $mono - $h['moved'] > (int) ($ctx['window'] ?? 60)) {
            continue;
        }
        $moved = !empty($h['gone']);
        foreach ($moved ? [] : $h['fds'] as $fd => $p) {
            $i = watchmanWakeFdInfo($proc, (int) $pid, (int) $fd);
            if ($i === null || $i['pos'] !== ($h['pos'][$fd] ?? null)) {
                $moved = true;
                break;
            }
        }
        $w = array_diff_key($h, array_flip(['fds', 'pos', 'w', 'moved', 'gone']));
        if ($moved) {
            $wrote[] = $w;
        } else {
            $open[] = $w;                       // it wrote — somewhere else, as far as its files there show
        }
    }
    if (!$found) {
        // nobody caught waiting with a file: who holds a file open on a disk (the D task may have come and gone)
        foreach ($pids as $pid) {
            if ($fds >= PROCWHO_FDS || $over()) {
                $cut = true;
                break;
            }
            if ($pid === $self || in_array($pid, $waiting, true) || in_array($pid, $shfs, true)) {
                continue;
            }
            $w = procwhoWho($proc, $pid, $fds, $ns, true);
            if ($w !== null && $w['files']) {
                $open[] = $byDisk($w);
            }
        }
    }
    return ['t' => time(), 'ms' => (int) round((hrtime(true) - $t0) / 1e6), 'cut' => $cut, 'who' => $found ? $people : array_merge($people, $through), 'open' => $open,
            'wrote' => $wrote, 'unraid' => array_values(array_unique($unraid))];
}

/**
 * Unraid's own look at a disk — its answer to a wake, never the cause: emhttpd itself and the Unraid API, `hdparm -C`
 * (asks the power state only), smartctl in standby mode (never wakes a disk), smartctl/hdparm/sdspin (not «up») that
 * Unraid started (emhttpd reads SMART once it sees a disk awake). $smartThen: an entry of an older agent (no `by`) at a
 * wake Unraid read SMART for — its smartctl was that read.
 * `sensors` (lm-sensors, run by Unraid's dashboard and plugins) reads hwmon only — board, CPU, NVMe, RAM: it reaches a
 * rotating disk only through the `drivetemp` driver, so without a drivetemp hwmon it is never a cause either (2026-11-08:
 * caught in state D at a wake on a server without drivetemp, «maybe»); entries of older agents read the same way.
 */
function watchmanWakeReaction(array $w, bool $smartThen = false): bool
{
    if (($w['kind'] ?? '') !== 'program') {
        return false;
    }
    $prog = (string) ($w['prog'] ?? '');
    $cmd = (string) ($w['cmd'] ?? '');
    $by = $w['by'] ?? null;
    return $prog === 'emhttpd' || str_contains($cmd, 'unraid-api')
        || ($prog === 'hdparm' && preg_match('/(?:^|\s)-C(?:\s|$)/', $cmd))
        || ($prog === 'smartctl' && preg_match('/(?:^|\s)(?:-n\s*|--nocheck[= ]?)(?:standby|sleep|idle)\b/', $cmd))
        || ($by === 'unraid' && in_array($prog, ['smartctl', 'hdparm'], true))
        || ($by === 'unraid' && $prog === 'sdspin' && !preg_match('/\sup(?:\s|$)/', $cmd))
        || ($by === null && $smartThen && $prog === 'smartctl')
        || ($prog === 'sensors' && !watchmanWakeDrivetemp());
}

/**
 * Who reads only what can never reach a rotating disk — never a cause, and not Unraid's look either (left out, said nowhere;
 * 2026-11-25, nostromo: every wake of the day named Fanboy's control, `maybe`, and once `nvme get-feature`):
 *   - Fanboy's control service (`php agent.php ramjob fanboy-control`, only that process — never a child it starts): it
 *     holds no file under /mnt (it runs without the data folder), reads hwmon and Unraid's disks.ini (RAM) and writes the
 *     fan outputs. It sits in state D for a reason of its own: an NVMe's hwmon temperature can take a second to answer
 *     (a Corsair MP600 PRO: 1.04 s every read). It never reads a drivetemp hwmon (fanboyChip()); still, as for `sensors`,
 *     with a drivetemp hwmon here it stays what it was (a `maybe`).
 *   - the `nvme` command line tool (nvme-cli, run by Unraid and plugins): it sends admin commands to NVMe devices only,
 *     and a woken disk here is never an NVMe (watchmanWakeDisks(): only rotating ones, never an nvme device).
 * Entries of older agents read the same way (watchmanWakeShaped()).
 */
function watchmanWakeBystander(array $w): bool
{
    $prog = (string) ($w['prog'] ?? '');
    $cmd = (string) ($w['cmd'] ?? '');
    if ($prog === 'nvme' && preg_match('#^(?:/\S*/)?nvme(?:\s|$)#', $cmd)) {
        return true;
    }
    return ($w['kind'] ?? '') === 'office' && preg_match('#/agent/agent\.php ramjob fanboy-control$#D', $cmd) === 1 && !watchmanWakeDrivetemp();
}

/** A `drivetemp` hwmon here (/sys/class/hwmon/hwmonN/name — RAM): only then can reading the sensors touch a rotating disk */
function watchmanWakeDrivetemp(?string $sys = null): bool
{
    static $known = [];
    $sys ??= watchmanWakeSys();
    $mono = hrtime(true) / 1e9;
    if (!isset($known[$sys]) || $mono - $known[$sys][0] > 300) {        // modules come and go rarely: looked at every 5 min
        $found = false;
        foreach (glob("$sys/class/hwmon/*/name", GLOB_NOSORT) ?: [] as $f) {
            if (trim((string) @file_get_contents($f)) === 'drivetemp') {
                $found = true;
                break;
            }
        }
        $known[$sys] = [$mono, $found];
    }
    return $known[$sys][1];
}

// ===================================================================== a wake

/**
 * Disks that woke between two looks at disks.ini: one wake (they woke together — parity with a data disk), its time
 * and its likely causes from the captures before it; named afterwards by the naming job.
 *
 * @param list<string> $woke
 * @param array<string, array> $slept the woken disks as his memory had them asleep (since, gen: the trail's start)
 */
function watchmanWakeRecord(array $woke, int $from, int $to, array $disks, array $slept = []): void
{
    $m = &watchmanWakeMem();
    $caught = [];
    foreach ($woke as $name) {
        $c = $m['caught'][$name] ?? null;
        if ($c !== null && (int) $c['t'] >= $from - WATCH_WAKE_MATCH && (int) $c['t'] <= $to) {
            $caught[] = $c;
        }
    }
    $mounts = array_values(array_filter(array_map(fn (string $n): ?string => $disks[$n]['mount'] ?? null, $woke)));
    $time = $caught ? min(array_column($caught, 't')) : $to;
    $wake = ['id' => bin2hex(random_bytes(6)), 'time' => $time, 'from' => min($from, $time),     // caught before his last look
             'disks' => $woke, 'how' => $caught ? 'caught' : 'noticed', 'who' => watchmanWakeCauses($caught, $mounts, watchmanWakeShareBases()),
             'cut' => (bool) array_filter(array_column($caught, 'cut')), 'named' => false,
             'unraid' => (bool) array_filter($caught, fn (array $c): bool => !empty($c['unraid']))];
    // since when each slept and its btrfs generation then: where the naming job's trail starts
    foreach ($woke as $name) {
        if (is_int($slept[$name]['since'] ?? null)) {
            $wake['asleep'][$name] = ['since' => $slept[$name]['since'], 'gen' => is_int($slept[$name]['gen'] ?? null) ? $slept[$name]['gen'] : null];
        }
    }
    $all = watchmanWakesRead();
    $all['wakes'][] = $wake;
    foreach ($woke as $name) {
        $all['totals'][$name] = (int) ($all['totals'][$name] ?? 0) + 1;
    }
    watchmanWakesWrite($all);
    $first = $wake['who'][0] ?? null;
    logLine(sprintf('Night watchman: %s woke up — %s', implode(' + ', $woke), $first ? $first['kind'] . ' (' . $first['sure'] . ')' : 'not found out'));
    watchmanWakeNameStart();
}

/**
 * The causes of a wake, the surest first: caught waiting with a file on the woken disk (sure), had written to a file
 * on it shortly before (wrote), waiting with a file in a user share that can lie on it (likely — shfs reaches the disk
 * for it), waiting without a file under /mnt (maybe); and whoever had a file open on it or in a share on it (open).
 * Unraid's own look is never one, nor who reads only what never reaches a rotating disk (watchmanWakeBystander()); shfs
 * only when nobody else is named better than `maybe`. One per process, WATCH_WAKE_WHO at most.
 *
 * @param list<array> $caught  captures
 * @param list<string> $mounts the woken disks' mount points
 * @param array<string, list<string>>|null $shares a user share => the disks and pools it lies on (null: not known)
 */
function watchmanWakeCauses(array $caught, array $mounts, ?array $shares = null): array
{
    $on = function (string $p) use ($mounts): bool {
        foreach ($mounts as $mt) {
            if ($p === $mt || str_starts_with($p, "$mt/")) {
                return true;
            }
        }
        return false;
    };
    $woken = array_map('basename', $mounts);
    // a user share's file: in a share on a woken disk (true), elsewhere (false), not known (null)
    $inShare = function (string $p) use ($woken, $shares): ?bool {
        if (!preg_match('#^/mnt/user0?/([^/]+)/#', $p, $s)) {
            return false;
        }
        return $shares === null || !isset($shares[$s[1]]) ? null : (bool) array_intersect($shares[$s[1]], $woken);
    };
    $rank = WATCH_WAKE_SURE;
    $out = [];
    foreach ($caught as $c) {
        foreach (['who', 'wrote', 'open'] as $list) {
            foreach ((array) ($c[$list] ?? []) as $w) {
                if (watchmanWakeReaction($w) || watchmanWakeBystander($w)) {
                    continue;
                }
                $mine = array_values(array_filter($w['files'], $on));
                $share = array_values(array_filter($w['files'], fn (string $p): bool => $list === 'who' ? $inShare($p) !== false : $inShare($p) === true));
                $sure = match ($list) {
                    'who'   => $mine ? 'sure' : ($share ? 'likely' : 'maybe'),
                    'wrote' => $mine ? 'wrote' : null,
                    'open'  => $mine || $share ? 'open' : null,
                };
                if ($sure === null) {
                    continue;
                }
                $files = array_slice(array_merge($mine, $share), 0, WATCH_WAKE_FILES);
                $key = $w['pid'] . '/' . $w['prog'];
                if (isset($out[$key]) && $rank[$out[$key]['sure']] <= $rank[$sure]) {
                    continue;
                }
                $out[$key] = array_intersect_key($w, array_flip(['kind', 'prog', 'cmd', 'id', 'name', 'desk', 'pid', 'by', 'byname'])) + ['sure' => $sure, 'files' => $files];
            }
        }
    }
    $out = array_values($out);
    // shfs (a user share was used) only while nobody else is named better than `maybe` (merely waiting: last, never instead)
    if (array_filter($out, fn (array $w): bool => $w['prog'] !== 'shfs' && $w['sure'] !== 'maybe')) {
        $out = array_values(array_filter($out, fn (array $w): bool => $w['prog'] !== 'shfs'));
    }
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
    return array_slice($out, 0, WATCH_WAKE_WHO);
}

/** Every user share => the disks and pools it lies on (shares.ini, disks.ini: RAM) — null when Unraid lists none */
function watchmanWakeShareBases(): ?array
{
    $shares = readCfg($GLOBALS['watchmanWakeSharesIni'] ?? OFFICE_SHARES_INI, true);
    if (!$shares) {
        return null;
    }
    $disks = readCfg(disksIniFile(), true);
    $out = [];
    foreach ($shares as $name => $s) {
        if (is_array($s)) {
            $out[(string) $name] = watchnetBases('/mnt/user/' . $name, $shares, $disks)['bases'];
        }
    }
    return $out;
}

// ===================================================================== the file

/** data/watchman/wakes.json as kept — tolerant: unknown keys and entries stay as they are */
function watchmanWakesRead(?string $file = null): array
{
    $j = readJson($file ?? watchmanWakesFile()) ?? [];
    $j['v'] = 1;
    $j['wakes'] = is_array($j['wakes'] ?? null) && array_is_list($j['wakes']) ? $j['wakes'] : [];
    $j['totals'] = is_array($j['totals'] ?? null) ? $j['totals'] : [];
    $j['since'] = is_int($j['since'] ?? null) ? $j['since'] : time();
    $j['watch'] = ($j['watch'] ?? true) !== false;      // on unless switched off
    return $j;
}

/** His switch: on or off (an action of his — the page sends `on`, read with boolField()); either way he starts anew */
function watchmanWakeWatchSet(bool $on): array
{
    $all = watchmanWakesRead();
    $all['watch'] = $on;
    if ($on) {
        $all['since'] = time();             // watching from now on
    }
    watchmanWakesWrite($all);
    $m = &watchmanWakeMem();
    $m['on'] = $on;
    $m['disks'] = null;
    $m['look'] = null;
    $m['stats'] = $m['caught'] = $m['again'] = $m['hold'] = [];
    $m['retry'] = null;
    $m['next'] = $m['ini_next'] = $m['hold_next'] = -INF;
    @unlink(watchmanWakeMemFile());
    logLine('Night watchman: «Who woke my disks?» switched ' . ($on ? 'on' : 'off'));
    return ['ok' => true];
}

/** Only the last WATCH_WAKE_KEEP seconds, WATCH_WAKE_MAX at most (an entry without a time he can read stays) */
function watchmanWakesWrite(array $all, ?string $file = null): void
{
    $old = time() - WATCH_WAKE_KEEP;
    $all['wakes'] = array_slice(array_values(array_filter($all['wakes'],
        fn ($w): bool => !is_array($w) || !is_int($w['time'] ?? null) || $w['time'] >= $old)), -WATCH_WAKE_MAX);
    $file ??= watchmanWakesFile();
    @mkdir(dirname($file), 0755, true);     // his folder: there once he is hired, here also before his first round
    writeAtomic($file, jsonEncode($all));
}

/** A wake as the page reads it — exactly its shape, else left out */
function watchmanWakeShaped(mixed $w): ?array
{
    if (!is_array($w) || !is_string($w['id'] ?? null) || !is_int($w['time'] ?? null) || !is_array($w['disks'] ?? null)
        || !in_array($w['how'] ?? null, ['caught', 'noticed', 'syslog'], true) || !is_array($w['who'] ?? null)) {
        return null;
    }
    $who = [];
    $unraid = !empty($w['unraid']);
    foreach ($w['who'] as $c) {
        if (is_array($c) && in_array($c['kind'] ?? null, WATCH_WAKE_KINDS, true) && is_string($c['prog'] ?? null)
            && isset(WATCH_WAKE_SURE[$c['sure'] ?? ''])) {
            if (watchmanWakeBystander($c)) {
                continue;                   // Fanboy's control, `nvme` (an older agent kept them): never a cause, said nowhere
            }
            // Unraid's own look, as an older agent (1.68.0, no `by`) kept it: said apart, never the cause
            if (watchmanWakeReaction($c, !array_key_exists('by', $c) && is_int($w['smart'] ?? null))) {
                $unraid = true;
                continue;
            }
            $who[] = ['kind' => $c['kind'], 'prog' => $c['prog'], 'sure' => $c['sure'],
                      'name' => is_string($c['name'] ?? null) ? $c['name'] : '', 'desk' => is_string($c['desk'] ?? null) ? $c['desk'] : '',
                      'cmd' => is_string($c['cmd'] ?? null) ? $c['cmd'] : '',
                      'by' => in_array($c['by'] ?? null, WATCH_WAKE_BY, true) ? $c['by'] : '', 'byname' => is_string($c['byname'] ?? null) ? $c['byname'] : '',
                      'files' => array_values(array_filter((array) ($c['files'] ?? []), 'is_string'))];
        }
    }
    $strings = fn (string $k): array => array_values(array_filter((array) ($w[$k] ?? []), 'is_string'));
    return ['id' => $w['id'], 'time' => $w['time'], 'from' => is_int($w['from'] ?? null) ? $w['from'] : $w['time'],
            'disks' => array_values(array_filter($w['disks'], 'is_string')), 'how' => $w['how'], 'who' => $who,
            'cut' => !empty($w['cut']), 'named' => !empty($w['named']), 'cron' => $strings('cron'), 'started' => $strings('started'),
            'smart' => is_int($w['smart'] ?? null) ? $w['smart'] : null, 'unraid' => $unraid, 'trail' => watchmanWakeTrailShaped($w['trail'] ?? null)];
}

/** For his state: the rotating disks there are (no tile without one), since when he watches, the wakes */
function watchmanWakesForPage(?array $disks = null): array
{
    $all = watchmanWakesRead();
    $disks ??= watchmanWakeDisks();
    return ['hdds' => array_keys($disks), 'since' => $all['since'], 'look' => WATCH_WAKE_LOOK, 'on' => $all['watch'],
            'wakes' => array_values(array_filter(array_map('watchmanWakeShaped', $all['wakes'])))];
}

/** His metric: wakes per disk since he started counting (a counter: never less, whatever the file keeps) */
function watchmanWakeMetrics(?string $file = null): array
{
    $totals = metricsCached($file ?? watchmanWakesFile(), fn (string $f): array => (array) ((readJson($f) ?? [])['totals'] ?? []));
    $samples = [];
    foreach ($totals as $disk => $n) {
        if (is_int($n) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', (string) $disk)) {
            $samples[] = [['disk' => (string) $disk], $n];
        }
    }
    return $samples ? [['name' => 'uso_watchman_disk_wakes_total', 'type' => 'counter',
        'help' => 'Spinning disks woken up, as the Night Watchman noticed (since counting started)', 'samples' => $samples]] : [];
}

// ===================================================================== the trail (what was written on the woken disk)

/** /sys (tests: a tree of their own) */
function watchmanWakeSys(): string
{
    return $GLOBALS['watchmanWakeSys'] ?? '/sys';
}

/**
 * What is mounted at exactly this mount point, as the server's first process sees it (/proc/1/mountinfo — RAM):
 * [type, source, root inside its filesystem] — null when nothing is
 *
 * @return array{type: string, source: string, root: string}|null
 */
function watchmanWakeFsOf(string $mount, ?string $proc = null): ?array
{
    foreach (watchmanWakeFsList($proc) as $f) {
        if ($f['point'] === $mount) {
            return ['type' => $f['type'], 'source' => $f['source'], 'root' => $f['root']];
        }
    }
    return null;
}

/**
 * Every mount of the server's first process: point, type, source, root (/proc/1/mountinfo: «… root point opts … - type
 * source super-opts»)
 *
 * @return list<array{point: string, type: string, source: string, root: string}>
 */
function watchmanWakeFsList(?string $proc = null): array
{
    $out = [];
    foreach (@file(($proc ?? watchmanWakeProc()) . '/1/mountinfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $f = explode(' ', $line);
        $dash = array_search('-', $f, true);
        if (count($f) >= 5 && $dash !== false && $dash >= 6 && isset($f[$dash + 2])) {
            $out[] = ['point' => mountUnescape($f[4]), 'type' => $f[$dash + 1], 'source' => mountUnescape($f[$dash + 2]), 'root' => mountUnescape($f[3])];
        }
    }
    return $out;
}

/**
 * A btrfs filesystem's generation (its newest transaction id) from /sys/fs/btrfs/<fsid>/generation — RAM, never the
 * disk: found by the device mounted there (/dev/md4p1, an encrypted /dev/mapper/md4p1 by its dm-N). Null: not btrfs or
 * not to be found.
 */
function watchmanWakeBtrfsGen(string $mount, ?string $proc = null, ?string $sys = null): ?int
{
    $fs = watchmanWakeFsOf($mount, $proc);
    if ($fs === null || $fs['type'] !== 'btrfs' || !str_starts_with($fs['source'], '/dev/')) {
        return null;
    }
    $sys ??= watchmanWakeSys();
    $dev = basename((string) (@realpath($fs['source']) ?: $fs['source']));
    if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $dev)) {
        return null;
    }
    foreach (glob("$sys/fs/btrfs/*/devices/$dev", GLOB_NOSORT) ?: [] as $link) {
        $g = trim((string) @file_get_contents(dirname($link, 2) . '/generation'));
        if (ctype_digit($g)) {
            return (int) $g;
        }
    }
    return null;
}

/**
 * A command whose answer can be long, bounded: at most $max bytes of its output and $seconds — then it is ended
 * (`cut`). No shell, stderr dropped.
 *
 * @param list<string> $cmd
 * @return array{0: int, 1: string, 2: bool} exit code, output, cut
 */
function watchmanWakeRunCapped(array $cmd, float $seconds, int $max): array
{
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $pipes = [];
    $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/', $env);
    if (!is_resource($p)) {
        return [127, '', false];
    }
    stream_set_blocking($pipes[1], false);
    $out = '';
    $cut = false;
    $deadline = hrtime(true) + (int) ($seconds * 1e9);
    while (!feof($pipes[1])) {
        if (strlen($out) >= $max || hrtime(true) > $deadline) {
            $cut = true;
            break;
        }
        $r = [$pipes[1]];
        $w = $e = null;
        @stream_select($r, $w, $e, 0, 200000);
        $chunk = fread($pipes[1], 65536);
        if (is_string($chunk)) {
            $out .= $chunk;
        }
    }
    if ($cut) {
        proc_terminate($p, 9);
    }
    fclose($pipes[1]);
    $code = proc_close($p);
    return [$cut ? 124 : $code, substr($out, 0, $max), $cut];
}

/**
 * btrfs: the files written after the generation $gen (`btrfs subvolume find-new <tree> <gen>` lists only file extents
 * of newer transactions — inclusive, so the caller passes the one after the sleep's), in the mounted tree and every
 * writable subvolume below it that changed since (read-only ones — snapshots — and `.btrfs-snap` left out). Without
 * $gen: the last WATCH_WAKE_TRAIL_GENS transactions. Path (absolute) => bytes of its new extents.
 *
 * @param callable(list<string>, float, int): array{0: int, 1: string, 2: bool} $run
 * @return array{files: array<string, int>, cut: bool}
 */
function watchmanWakeTrailBtrfs(string $mount, ?int $gen, callable $run, int $deadline, string $root = '/'): array
{
    $left = fn (): float => max(0.0, ($deadline - hrtime(true)) / 1e9);
    $btrfs = bin('btrfs') ?? 'btrfs';
    if ($gen === null) {
        [, $text] = $run([$btrfs, 'subvolume', 'find-new', $mount, '999999999999'], min(10.0, $left()), 4096);
        if (!preg_match('/transid marker was (\d+)/', $text, $mk)) {
            return ['files' => [], 'cut' => false];
        }
        $gen = max(1, (int) $mk[1] - WATCH_WAKE_TRAIL_GENS + 1);
    }
    $trees = [$mount];
    if ($root === '/') {                // the top of the filesystem mounted here: the subvolumes' paths are below it
        [$e1, $all] = $run([$btrfs, 'subvolume', 'list', $mount], min(10.0, $left()), 1 << 20);
        [$e2, $ro] = $run([$btrfs, 'subvolume', 'list', '-r', $mount], min(10.0, $left()), 1 << 20);
        $readonly = [];
        foreach (explode("\n", $e2 === 0 ? $ro : '') as $line) {
            if (preg_match('/^ID (\d+) /', $line, $id)) {
                $readonly[$id[1]] = true;
            }
        }
        foreach (explode("\n", $e1 === 0 ? $all : '') as $line) {
            if (!preg_match('/^ID (\d+) gen (\d+) top level \d+ path (.+)$/D', $line, $sv) || isset($readonly[$sv[1]]) || (int) $sv[2] < $gen
                || in_array('.btrfs-snap', explode('/', $sv[3]), true) || in_array('..', explode('/', $sv[3]), true)) {
                continue;
            }
            if (count($trees) >= WATCH_WAKE_TRAIL_TREES) {
                break;
            }
            $trees[] = "$mount/{$sv[3]}";
        }
    }
    $files = [];
    $cut = false;
    foreach ($trees as $tree) {
        if ($left() <= 0) {
            $cut = true;
            break;
        }
        [$exit, $text, $c] = $run([$btrfs, 'subvolume', 'find-new', $tree, (string) $gen], $left(), WATCH_WAKE_TRAIL_OUT);
        $cut = $cut || $c;
        if ($exit !== 0 && !$c) {
            continue;
        }
        // inode 261 file offset 0 len 8192 disk start 13733888 offset 0 gen 10 flags NONE Musik-Videos/a b "c".mkv
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/^inode \d+ file offset \d+ len (\d+) disk start \d+ offset \d+ gen \d+ flags \S+ (.+)$/D', $line, $x)
                || $x[2][0] === '/' || in_array('..', explode('/', $x[2]), true)) {
                continue;
            }
            $p = "$tree/{$x[2]}";
            if (!isset($files[$p]) && count($files) >= WATCH_WAKE_TRAIL_FILES) {
                $cut = true;
                break 2;
            }
            $files[$p] = ($files[$p] ?? 0) + (int) $x[1];
        }
    }
    return ['files' => $files, 'cut' => $cut];
}

/**
 * XFS, ZFS and the rest keep no list of what changed: the folders under the woken disk's mounts changed since $since
 * (`find -xdev … -type d -newerct` — find knows a folder by its entry, so only folders are looked at closer; `.zfs` and
 * `.btrfs-snap` pruned, a ZFS disk's own datasets each a mount of their own), then their files changed since. Bounded
 * (WATCH_WAKE_TRAIL_WALK folders, WATCH_WAKE_TRAIL_FILES files, the deadline): `cut`. A file changed in place in a folder
 * that didn't change escapes it. Path (absolute) => [bytes, ctime].
 *
 * @param callable(list<string>, float, int): array{0: int, 1: string, 2: bool} $run
 * @return array{files: array<string, array{0: int, 1: int}>, cut: bool}
 */
function watchmanWakeTrailWalk(string $mount, int $since, callable $run, int $deadline, ?string $proc = null): array
{
    $left = fn (): float => max(0.0, ($deadline - hrtime(true)) / 1e9);
    $roots = [$mount];
    $fs = watchmanWakeFsOf($mount, $proc);
    if ($fs !== null && $fs['type'] === 'zfs') {
        foreach (watchmanWakeFsList($proc) as $f) {
            if ($f['type'] === 'zfs' && str_starts_with($f['point'], "$mount/") && str_starts_with($f['source'], $fs['source'] . '/')
                && !str_contains($f['point'] . '/', '/.zfs/') && count($roots) < WATCH_WAKE_TRAIL_TREES) {
                $roots[] = $f['point'];
            }
        }
    }
    $dirs = [];
    $cut = false;
    foreach ($roots as $root) {
        if ($left() <= 0) {
            $cut = true;
            break;
        }
        [, $text, $c] = $run(['find', $root, '-xdev', '(', '-name', '.zfs', '-o', '-name', '.btrfs-snap', ')', '-prune', '-o',
            '-type', 'd', '-newerct', "@$since", '-print0'], $left(), WATCH_WAKE_TRAIL_OUT);
        $cut = $cut || $c;
        foreach (explode("\0", $text) as $d) {
            if ($d !== '' && $d[0] === '/') {
                $dirs[$d] = true;
            }
        }
    }
    if (count($dirs) > WATCH_WAKE_TRAIL_WALK) {
        $dirs = array_slice($dirs, 0, WATCH_WAKE_TRAIL_WALK, true);
        $cut = true;
    }
    $files = [];
    foreach (array_keys($dirs) as $d) {
        if ($left() <= 0) {
            $cut = true;
            break;
        }
        foreach (@scandir((string) $d) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            if (count($files) >= WATCH_WAKE_TRAIL_FILES) {
                $cut = true;
                break 2;
            }
            $st = @lstat("$d/$e");
            if (is_array($st) && ($st['mode'] & 0170000) === 0100000 && $st['ctime'] >= $since) {
                $files["$d/$e"] = [(int) $st['size'], (int) $st['ctime']];
            }
        }
    }
    return ['files' => $files, 'cut' => $cut];
}

/**
 * What was written on the woken disks around a wake, by folder (the naming job, never the tick): only disks awake now
 * (every member of a pool), only for a wake of the last WATCH_WAKE_TRAIL_LATE s; btrfs by its transactions since the
 * sleep, the rest by a bounded walk (see the top of the file). Left out: `.btrfs-snap`, `.zfs` and the office's own
 * data folder ($o['exclude']: absolute paths as /mnt/user/… or on a disk). A file changed more than
 * WATCH_WAKE_TRAIL_AFTER s after the wake is a later one. At most WATCH_WAKE_TRAIL_DIRS folders, the earliest written
 * first: {how: btrfs|walk|both, cut, dirs: [{disk, dir, n, bytes, first, last}], more (folders), files (all)}.
 * Null: nothing that could be looked at (parity, a disk asleep again, a wake too old).
 *
 * @param array<string, array{dev: string, asleep: bool, mount: ?string}> $disks disks.ini now (watchmanWakeDisks())
 * @param array{run?: callable, now?: int, deadline?: int, exclude?: list<string>, proc?: string} $o
 */
function watchmanWakeTrail(array $w, array $disks, array $o = []): ?array
{
    $now = $o['now'] ?? time();
    $time = (int) ($w['time'] ?? 0);
    if ($now - $time > WATCH_WAKE_TRAIL_LATE || $time > $now + 60) {
        return null;
    }
    $run = $o['run'] ?? 'watchmanWakeRunCapped';
    $deadline = $o['deadline'] ?? hrtime(true) + WATCH_WAKE_TRAIL_RUN * 1_000_000_000;
    $proc = $o['proc'] ?? null;
    $mounts = [];
    foreach ((array) ($w['disks'] ?? []) as $name) {
        $mt = $disks[$name]['mount'] ?? null;
        if (!is_string($mt) || !empty($disks[$name]['asleep'])) {
            continue;
        }
        // a pool: every rotating member awake (one asleep — a look would wake it)
        if (array_filter($disks, fn (array $d): bool => $d['mount'] === $mt && $d['asleep'])) {
            continue;
        }
        $slept = $w['asleep'][$name] ?? [];
        $since = is_int($slept['since'] ?? null) ? $slept['since'] : (int) ($w['from'] ?? $time) - watchmanWakeWindow();
        $gen = is_int($slept['gen'] ?? null) ? $slept['gen'] : null;
        // a pool's members woke together: its mount once, from the earliest sleep
        $mounts[$mt] = isset($mounts[$mt]) ? ['since' => min($mounts[$mt]['since'], $since), 'gen' => $mounts[$mt]['gen'] ?? $gen] : ['since' => $since, 'gen' => $gen];
    }
    if (!$mounts) {
        return null;
    }
    // the office's own data folder, as a path inside a share or a disk (Mediathek/x for /mnt/user/Mediathek/x)
    $skip = [];
    foreach ($o['exclude'] ?? [] as $x) {
        if (preg_match('#^/mnt/(?:user0?|[A-Za-z0-9_.-]+)/(.+?)/*$#D', (string) $x, $r)) {
            $skip[] = $r[1];
        }
    }
    $hows = [];
    $cut = false;
    $groups = [];
    $all = 0;
    foreach ($mounts as $mt => $s) {
        $fs = watchmanWakeFsOf($mt, $proc);
        if ($fs === null) {
            continue;
        }
        if ($fs['type'] === 'btrfs') {
            $hows['btrfs'] = true;
            $t = watchmanWakeTrailBtrfs($mt, $s['gen'] === null ? null : $s['gen'] + 1, $run, $deadline, $fs['root']);
            $files = [];
            foreach ($t['files'] as $p => $bytes) {
                $files[$p] = [$bytes, null];
            }
        } else {
            $hows['walk'] = true;
            $t = watchmanWakeTrailWalk($mt, $s['since'], $run, $deadline, $proc);
            $files = $t['files'];
        }
        $cut = $cut || $t['cut'];
        $stats = 0;
        foreach ($files as $p => [$bytes, $ctime]) {
            $rel = substr((string) $p, strlen($mt) + 1);
            $parts = explode('/', $rel);
            if ($rel === '' || in_array('.btrfs-snap', $parts, true) || in_array('.zfs', $parts, true)
                || array_filter($skip, fn (string $k): bool => $rel === $k || str_starts_with($rel, "$k/"))) {
                continue;
            }
            if ($ctime === null && $stats++ < WATCH_WAKE_TRAIL_FILES) {
                $st = @lstat((string) $p);          // the disk is awake: its time and size
                if (is_array($st)) {
                    $ctime = (int) $st['ctime'];
                    $bytes = min($bytes, (int) $st['size']);
                }
            }
            if ($ctime !== null && $ctime > $time + WATCH_WAKE_TRAIL_AFTER) {
                continue;                           // written later: not what woke it
            }
            $dir = dirname($rel);
            $key = basename($mt) . '|' . $dir;
            $g = $groups[$key] ?? ['disk' => basename($mt), 'dir' => mb_strcut($dir === '.' ? '' : $dir, 0, PROCWHO_PATH, 'UTF-8'),
                                    'n' => 0, 'bytes' => 0, 'first' => null, 'last' => null];
            $g['n']++;
            $g['bytes'] += $bytes;
            if ($ctime !== null) {
                $g['first'] = $g['first'] === null ? $ctime : min($g['first'], $ctime);
                $g['last'] = $g['last'] === null ? $ctime : max($g['last'], $ctime);
            }
            $groups[$key] = $g;
            $all++;
        }
    }
    if (!$hows) {
        return null;
    }
    $groups = array_values($groups);
    usort($groups, fn (array $a, array $b): int => [$a['first'] ?? PHP_INT_MAX, -$a['n']] <=> [$b['first'] ?? PHP_INT_MAX, -$b['n']]);
    return ['how' => count($hows) > 1 ? 'both' : array_key_first($hows), 'cut' => $cut, 'dirs' => array_slice($groups, 0, WATCH_WAKE_TRAIL_DIRS),
            'more' => max(0, count($groups) - WATCH_WAKE_TRAIL_DIRS), 'files' => $all];
}

/** A trail as the page reads it — exactly its shape, else none */
function watchmanWakeTrailShaped(mixed $t): ?array
{
    if (!is_array($t) || !in_array($t['how'] ?? null, ['btrfs', 'walk', 'both'], true) || !is_array($t['dirs'] ?? null)) {
        return null;
    }
    $dirs = [];
    foreach ($t['dirs'] as $d) {
        if (is_array($d) && is_string($d['disk'] ?? null) && is_string($d['dir'] ?? null) && is_int($d['n'] ?? null) && is_int($d['bytes'] ?? null)
            && count($dirs) < WATCH_WAKE_TRAIL_DIRS) {
            $dirs[] = ['disk' => $d['disk'], 'dir' => $d['dir'], 'n' => $d['n'], 'bytes' => $d['bytes'],
                       'first' => is_int($d['first'] ?? null) ? $d['first'] : null, 'last' => is_int($d['last'] ?? null) ? $d['last'] : null];
        }
    }
    return ['how' => $t['how'], 'cut' => !empty($t['cut']), 'dirs' => $dirs, 'more' => is_int($t['more'] ?? null) ? $t['more'] : 0];
}

// ===================================================================== naming afterwards (a job of its own)

/** The naming job, unless one runs (the next one names what is still unnamed) */
function watchmanWakeNameStart(): void
{
    $m = &watchmanWakeMem();
    if ($m['job'] !== null || !empty($GLOBALS['watchmanWakeNoJob'])) {        // tests name by calling watchmanWakeNameRun()
        return;
    }
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'watchman-wake-name'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (is_resource($process)) {
        $m['job'] = ['process' => $process, 'since' => time()];
    }
}

/** Every tick while the naming job runs: done? Its answer (RAM) goes into the wakes (only his tick writes the file) */
function watchmanWakeNameDone(): void
{
    $m = &watchmanWakeMem();
    $status = proc_get_status($m['job']['process']);
    if ($status['running']) {
        if (time() - $m['job']['since'] > WATCH_WAKE_NAME_RUN) {
            proc_terminate($m['job']['process']);
        }
        return;
    }
    proc_close($m['job']['process']);
    $m['job'] = null;
    watchmanWakeNameMerge();
}

function watchmanWakeNamesFile(): string
{
    return $GLOBALS['watchmanWakeNamesFile'] ?? RUN_DIR . '/watchman-wake-names.json';
}

/** The naming job's answer into the wakes it names (an answer for a wake that went is let go) */
function watchmanWakeNameMerge(): void
{
    $names = readJson(watchmanWakeNamesFile());
    @unlink(watchmanWakeNamesFile());
    if (!$names) {
        return;
    }
    $all = watchmanWakesRead();
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
                $w['time'] = $n['smart'];          // nothing caught: Unraid's «read SMART» says when better than his look
                $w['how'] = 'syslog';
            }
        }
        if (is_array($n['trail'] ?? null)) {
            $w['trail'] = $n['trail'];
        }
        $w['named'] = true;
    }
    unset($w);
    watchmanWakesWrite($all);
}

/**
 * php agent.php job watchman-wake-name: names for the unnamed wakes and their trails, into RAM (watchmanWakeNamesFile()). $o
 * (tests): what Docker, smbstatus, the cron files, the syslog and disks.ini would say — docker, smb, cron, syslog, disks —
 * and the trail's options (watchmanWakeTrail(): run, now, proc).
 */
function watchmanWakeNameRun(array $o = []): int
{
    $wakes = array_values(array_filter(watchmanWakesRead()['wakes'], fn ($w): bool => is_array($w) && empty($w['named'])
        && is_string($w['id'] ?? null) && is_int($w['time'] ?? null)));
    if (!$wakes) {
        return 0;
    }
    $docker = $o['docker'] ?? watchmanWakeDocker();
    $smb = null;
    if (array_filter($wakes, fn (array $w): bool => in_array('smb', array_column((array) $w['who'], 'kind'), true))) {
        $smb = $o['smb'] ?? procwhoSmb();
    }
    $cron = $o['cron'] ?? watchmanWakeCronLines();
    $syslog = $o['syslog'] ?? watchmanWakeSyslog(2000);
    $out = readJson(watchmanWakeNamesFile()) ?? [];
    // the trails: disks.ini now (a disk asleep again is never looked at), one deadline for them all
    $disksNow = $o['disks'] ?? watchmanWakeDisks();
    $trail = ($o['trail'] ?? []) + ['deadline' => hrtime(true) + WATCH_WAKE_TRAIL_RUN * 1_000_000_000,
                                    'exclude' => array_values(array_filter([defined('DATA_DIR') ? (string) DATA_DIR : '', defined('DATA_DIR_USER') ? (string) DATA_DIR_USER : '']))];
    foreach ($wakes as $w) {
        $devs = array_values(array_filter(array_map(fn (string $d): string => (string) (watchmanWakeMem()['disks'][$d]['dev'] ?? ''), (array) $w['disks'])));
        $out[$w['id']] = [
            'containers' => $docker['names'],
            'smb' => $smb ?? [],
            'cron' => watchmanWakeCronDue($cron, (int) $w['time']),
            'started' => array_values(array_map(fn (array $c): string => $c[0],
                array_filter($docker['started'], fn (array $c): bool => $c[1] >= (int) $w['time'] - 120 && $c[1] <= (int) $w['time'] + 10))),
            'smart' => watchmanWakeSmartTime($syslog, $devs, (int) $w['from'] - 60, (int) $w['time'] + 120),
            'trail' => watchmanWakeTrail($w, $disksNow, $trail),
        ];
    }
    writeAtomic(watchmanWakeNamesFile(), jsonEncode($out), 0600, 0, 0);
    return 0;
}

/** Docker's containers: id => name, and when the running ones started ([name, time]) — nothing when Docker is off */
function watchmanWakeDocker(): array
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

/** The syslog's last $n lines (RAM: /var/log), read from its end — at most WATCH_WAKE_SYSLOG_BYTES */
function watchmanWakeSyslog(int $n, string $path = '/var/log/syslog'): array
{
    $size = (int) @filesize($path);
    $h = $size > 0 ? @fopen($path, 'r') : false;
    if (!$h) {
        return [];
    }
    $want = min($size, WATCH_WAKE_SYSLOG_BYTES, max(65536, $n * 400));
    fseek($h, $size - $want);
    $text = (string) fread($h, $want);
    fclose($h);
    $lines = explode("\n", rtrim($text, "\n"));
    if ($want < $size) {
        array_shift($lines);            // started in the middle of a line
    }
    return array_slice($lines, -$n);
}

/** Root's cron lines: its crontab and /etc/cron.d (Unraid's plugins' lines are there) — [cron, command] */
function watchmanWakeCronLines(array $files = []): array
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
function watchmanWakeCronDue(array $lines, int $t): array
{
    $out = [];
    foreach ($lines as [$cron, $cmd]) {
        $f = cronFields($cron);
        if ($f === null || count($f[0]) * count($f[1]) > 4 * 24 || !(cronMatches($f, $t) || cronMatches($f, $t - 60))) {
            continue;
        }
        $out[] = preg_match('#/plugins/unraid-secretary-office/scripts/job\.sh ([a-z]+)#', $cmd, $j) ? 'office:' . $j[1]
            : mb_strcut(preg_replace('#\s*>\s*/dev/null.*$|\s+2>&1.*$#', '', $cmd), 0, PROCWHO_PATH, 'UTF-8');
        if (count($out) >= 3) {
            break;
        }
    }
    return array_values(array_unique($out));
}

/** Unraid's «emhttpd: read SMART /dev/sdX» after a wake: its time (the syslog's year: this one's) — null when none */
function watchmanWakeSmartTime(array $lines, array $devs, int $from, int $to): ?int
{
    $best = null;
    foreach ($lines as $line) {
        if (!preg_match(WATCH_WAKE_SMART, $line, $s) || !in_array($s[6], $devs, true)) {
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
