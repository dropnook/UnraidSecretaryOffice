<?php
declare(strict_types=1);

/*
 * The office's own numbers for Prometheus — through a Node Exporter's
 * textfile collector, which reads every *.prom file in a folder and serves
 * it along with its own numbers (Prometheus fetches them from there).
 *
 * The folder is METRICS_HOST_DIR, below /mnt/addons — the place for add-on
 * mounts that Unassigned Devices makes (a 1 MB tmpfs, RAM, gone after a
 * reboot), shared with the backup engine's mount points. A fresh Unraid without
 * Unassigned Devices has no /mnt/addons: the agent then makes it as a plain
 * folder (root, 0755 — never a tmpfs or mount of its own; /mnt is RAM on Unraid).
 * The agent creates its folders at start (metricsStart()) and looks again
 * every minute before it writes from its loop (metricsTick()) — Unassigned
 * Devices installed later mounts its tmpfs over a plain /mnt/addons, and the
 * office's folder is made again below it:
 *
 *   uso_office.prom   the office itself (uso_office_info, when it wrote last)
 *   uso_<desk>.prom   what a hired desk reports through its hook
 *                     desk('<id>', ['metrics' => fn (): array => [family, …]])
 *
 * A family: ['name' => 'uso_…', 'type' => 'gauge'|'counter', 'help' => '…',
 * 'samples' => [[['label' => 'value', …], <number>], …]]. Hooks only read
 * state files (no zfs, no docker — this runs in the tick); names and labels
 * that don't fit Prometheus are left out, a name only once over all desks.
 * Each file is written through a temporary file in the same folder and a
 * rename (writeAtomic(): ".uso_x.prom.<random>.tmp" — never "*.prom"), so the
 * collector never reads half a file. All files together stay under
 * METRICS_MAX_BYTES: per-item series (the families with the most samples) go
 * first, said once in the agent log. The room is looked at before each write
 * too (metricsRoomNeed()): the folder's file system is small and not the
 * office's alone, so at least METRICS_ROOM_FLOOR stays free there — with
 * less, families go in the same order, said once; with too little for even
 * the office's own file nothing is written (and the office's files go).
 * Files of desks that were let go or no longer report are removed.
 *
 * OFFICE_METRICS_DIR in the environment points somewhere else (the tests).
 */

const METRICS_HOST_DIR    = '/mnt/addons/UnraidSecretaryOffice/metrics';
const METRICS_HOST_BASE   = '/mnt/addons';          // Unassigned Devices' tmpfs; without it a plain folder the office makes
const METRICS_EVERY       = 60;                     // seconds between two writes
const METRICS_MAX_BYTES   = 49152;                  // all files together (the tmpfs has 1 MB, shared; 16 KB before 1.95.0)
/*
 * Free space kept for the others where the folder lies: a quarter of Unassigned Devices' 1 MB tmpfs, five times the
 * most the office writes there (METRICS_MAX_BYTES), so the office never takes its last room; the backup engine's mount
 * points and Unassigned Devices' own need little but must always find some. A plain folder on Unraid's rootfs (RAM, GBs)
 * never comes near it.
 */
const METRICS_ROOM_FLOOR  = 262144;
const METRICS_PAGE        = 4096;                   // a tmpfs keeps whole pages: a file of 100 bytes takes 4 KB
const METRICS_MAX_SAMPLES = 500;                    // per family, before the size cap even looks
const METRICS_FRESH       = 300;                    // seconds: older files mean the agent stopped writing
const METRICS_NAME        = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';
const METRICS_AREA        = '/^[a-z][a-z0-9_]{0,40}$/D';
const METRICS_FILE        = '/^uso_[a-z][a-z0-9_]{0,40}\.prom$/D';
const METRICS_TMP         = '/^\.uso_[a-z][a-z0-9_]{0,40}\.prom\.[0-9a-f]+\.tmp$/D';
const METRICS_OFFICE      = 'office';               // the office's own file

/** The folder the numbers go to */
function metricsDir(): string
{
    $env = getenv('OFFICE_METRICS_DIR');
    return is_string($env) && str_starts_with($env, '/') && strlen($env) > 1 ? rtrim($env, '/') : METRICS_HOST_DIR;
}

/** Once, when the agent is ready: the folder, and a first write (the caretaker's start tour finds them fresh) */
function metricsStart(): void
{
    $GLOBALS['metricsNext'] = 0;
    metricsTick();
}

/** From the agent's loop (~150 ms): nearly always a comparison; once a minute the files */
function metricsTick(): void
{
    $now = time();
    if ($now < ($GLOBALS['metricsNext'] ?? 0)) {
        return;
    }
    $GLOBALS['metricsNext'] = $now + METRICS_EVERY;
    try {
        metricsWrite($now);
    } catch (Throwable $e) {
        metricsNote('write', 'Metrics: ' . $e->getMessage());
    }
}

/**
 * A line in the agent log — once per $key until metricsNoteClear() (a minute
 * apart the same trouble would fill the log)
 */
function metricsNote(string $key, string $text): void
{
    if (!isset($GLOBALS['metricsNoted'][$key])) {
        $GLOBALS['metricsNoted'][$key] = true;
        logLine($text);
    }
}

function metricsNoteClear(string $key): void
{
    unset($GLOBALS['metricsNoted'][$key]);
}

/**
 * The folder, made where it is missing: every part below the base a real
 * folder (no link) of the agent's own user. The base is /mnt/addons for the
 * real folder — made as a plain folder when nothing made it (metricsMakeBase(),
 * no Unassigned Devices); the base must be a real folder, never a link — and
 * the parent for another one (which must be there). $base: the tests' stand-in
 * for /mnt/addons. Cheap (an lstat per part): runs before every write, so a
 * folder that vanished (a tmpfs mounted over it) is made again; said once.
 */
function metricsEnsureDir(string $dir, ?string $base = null): bool
{
    $make = $base !== null || $dir === METRICS_HOST_DIR;
    $base ??= $dir === METRICS_HOST_DIR ? METRICS_HOST_BASE : dirname($dir);
    clearstatcache();
    $st = @lstat($base);
    if (!$st && $make) {
        metricsMakeBase($base);
        clearstatcache();
        $st = @lstat($base);
    }
    if (!$st || ($st['mode'] & 0170000) !== 0040000) {
        metricsNote('dir', "Metrics: $base isn't there or is no folder (a link?) — the office's numbers for Prometheus are not written");
        return false;
    }
    $path = $base;
    foreach (array_filter(explode('/', substr($dir, strlen($base))), 'strlen') as $part) {
        $path .= "/$part";
        if (!file_exists($path) && !is_link($path) && @mkdir($path, 0755)) {
            logLine("Metrics: created $path");
        }
        $st = @lstat($path);
        if (!$st || ($st['mode'] & 0170000) !== 0040000 || $st['uid'] !== posix_geteuid()) {
            metricsNote('dir', "Metrics: $path is no folder of the agent's own (a link? another owner?) — the office's numbers are not written");
            return false;
        }
    }
    metricsNoteClear('dir');
    return true;
}

/**
 * /mnt/addons where nothing made it (a server without Unassigned Devices, whose tmpfs it is): a plain
 * folder, root's own, 0755 (umask, no chmod) — never a mount of the office's own, nothing else made
 * there. Only inside a real folder (/mnt), never through a link. Said in the log when made.
 */
function metricsMakeBase(string $base): void
{
    $st = @lstat(dirname($base));
    if (!$st || ($st['mode'] & 0170000) !== 0040000) {
        return;
    }
    $old = umask(0022);
    try {
        $made = @mkdir($base, 0755);
    } finally {
        umask($old);
    }
    if ($made) {
        logLine("Metrics: created $base as a plain folder (nothing had made it — Unassigned Devices makes it a tmpfs)");
    }
}

/**
 * One round: every hired desk's numbers and the office's own into their
 * files, within the size cap; files nobody reports any more go. $areas and
 * $dir are there for the tests (area => families as a hook returns them).
 *
 * @return array{files: array<string,int>, dropped: list<string>}|null  file => bytes, families left out; null: not written
 */
function metricsWrite(int $now, ?array $areas = null, ?string $dir = null, ?callable $free = null): ?array
{
    $dir ??= metricsDir();
    if (!metricsEnsureDir($dir)) {
        return null;
    }
    // the office's own names first, so no desk can take them; a desk called "office" is left out
    $areas = metricsClean([METRICS_OFFICE => metricsOffice($now, 0)] + ($areas ?? metricsCollect()));
    $office = metricsRender($areas[METRICS_OFFICE] ?? []);
    unset($areas[METRICS_OFFICE]);
    // the room: what is free where the folder lies, what our files there take now (they are replaced)
    $room = metricsRoom($dir, $free);
    $budget = METRICS_MAX_BYTES - strlen($office) - 8;    // the office's own file is never cut; room for a longer number of families left out
    $fits = $room === null ? null : fn (array $texts): bool => metricsRoomNeed([METRICS_OFFICE => $office . str_repeat(' ', 8)] + $texts, $room['old']) <= $room['spare'];
    [$texts, $dropped] = metricsFit($areas, $budget, $fits);
    $bySize = $fits === null ? count($dropped) : count(metricsFit($areas, $budget)[1]);   // the same order: the first ones went for the size
    if ($dropped) {
        $office = metricsRender(metricsClean([METRICS_OFFICE => metricsOffice($now, count($dropped))])[METRICS_OFFICE] ?? []);
    }
    $texts = [METRICS_OFFICE => $office] + $texts;
    $written = $room === null || metricsRoomNeed([METRICS_OFFICE => $office], $room['old']) <= $room['spare'];

    $why = array_fill_keys(array_slice($dropped, 0, $bySize), 'size') + array_fill_keys(array_slice($dropped, $bySize), 'room');
    $was = $GLOBALS['metricsDropped'] ?? [];
    $new = ['size' => [], 'room' => []];
    foreach ($why as $name => $reason) {
        if (($was[$name] ?? null) !== $reason) {
            $new[$reason][] = $name;
        }
    }
    if ($new['size']) {
        logLine(sprintf('Metrics: over %d KB — left out %s', intdiv(METRICS_MAX_BYTES, 1024), implode(', ', $new['size'])));
    }
    if ($new['room'] && $room !== null) {
        logLine(sprintf('Metrics: only %s free in %s (%d KB stay for others) — left out %s',
                        metricsKb($room['free']), $dir, intdiv(METRICS_ROOM_FLOOR, 1024), implode(', ', $new['room'])));
    }
    $GLOBALS['metricsDropped'] = $why;
    $GLOBALS['metricsLeftOut'] = ['size' => $bySize, 'room' => count($dropped) - $bySize, 'written' => $written, 'free' => $room['free'] ?? null];

    if (!$written) {
        // not even the office's own file has room: nothing written, ours removed (an old number would look fresh)
        metricsNote('room', sprintf('Metrics: only %s free in %s (%d KB stay for others) — the office\'s numbers are not written',
                                    metricsKb((int) $room['free']), $dir, intdiv(METRICS_ROOM_FLOOR, 1024)));
        metricsTidy($dir, [], $now);
        return ['files' => [], 'dropped' => $dropped];
    }
    metricsNoteClear('room');
    $files = [];
    foreach ($texts as $area => $text) {
        $file = "uso_$area.prom";
        writeAtomic("$dir/$file", $text, 0644, 0, 0);
        $files[$file] = strlen($text);
    }
    metricsTidy($dir, $files, $now);
    metricsNoteClear('write');
    return ['files' => $files, 'dropped' => $dropped];
}

/**
 * The room where the folder lies: free bytes (statfs; $free: the tests' stand-in, path => bytes|null), the sizes of our
 * files there now, and what the office may add at most (spare: free − METRICS_ROOM_FLOOR). Null when it can't be told
 * (no answer, or a file system that reports no size, like ramfs): then only the size cap counts.
 *
 * @return array{free:int, spare:int, old:array<string,int>}|null
 */
function metricsRoom(string $dir, ?callable $free = null): ?array
{
    if ($free) {
        $bytes = $free($dir);
    } else {
        $total = @disk_total_space($dir);
        $bytes = is_float($total) && $total > 0 ? @disk_free_space($dir) : null;
    }
    if (!is_int($bytes) && !is_float($bytes)) {
        return null;
    }
    $old = [];
    clearstatcache();
    foreach (@scandir($dir) ?: [] as $name) {
        $st = preg_match(METRICS_FILE, $name) ? @lstat("$dir/$name") : false;
        if ($st && ($st['mode'] & 0170000) === 0100000) {
            $old[$name] = (int) $st['size'];
        }
    }
    return ['free' => (int) $bytes, 'spare' => (int) $bytes - METRICS_ROOM_FLOOR, 'old' => $old];
}

/**
 * What a write of $texts (area => text) needs beyond what the folder holds now, at its peak, in whole pages: the files
 * are replaced one by one, each through a temporary file beside the old one — so every file counts with the larger of
 * its old and new size, and the biggest new one once more (its temporary file while the old one is still there).
 *
 * @param array<string,int> $old  file => bytes now
 */
function metricsRoomNeed(array $texts, array $old): int
{
    $pages = fn (int $bytes): int => intdiv($bytes + METRICS_PAGE - 1, METRICS_PAGE) * METRICS_PAGE;
    $grow = $big = 0;
    foreach ($texts as $area => $text) {
        $n = $pages(strlen($text));
        $grow += max(0, $n - $pages($old["uso_$area.prom"] ?? 0));
        $big = max($big, $n);
    }
    return $grow + $big;
}

/** Bytes as the log says them: «180 KB», «0 KB» */
function metricsKb(int $bytes): string
{
    return intdiv(max(0, $bytes), 1024) . ' KB';
}

/**
 * What the last write left out, for the team lead: families for the size cap and for the room, whether the office's
 * numbers were written at all, and the free bytes where they lie (null: not told). Null before the first write (or in
 * another process).
 *
 * @return array{size:int, room:int, written:bool, free:?int}|null
 */
function metricsLeftOut(): ?array
{
    return $GLOBALS['metricsLeftOut'] ?? null;
}

/** Our files nobody wrote this round (a desk let go, a hook that stopped reporting) and temporary files left by a crash */
function metricsTidy(string $dir, array $files, int $now): void
{
    foreach (@scandir($dir) ?: [] as $name) {
        $path = "$dir/$name";
        if (preg_match(METRICS_FILE, $name) && !isset($files[$name])) {
            @unlink($path);
        } elseif (preg_match(METRICS_TMP, $name) && (int) @filemtime($path) < $now - 600) {
            @unlink($path);
        }
    }
}

/** The office's own numbers */
function metricsOffice(int $now, int $dropped): array
{
    return [
        metricsGauge('uso_office_info', 'The office\'s agent (1 while it writes these numbers), by its version', [[['version' => AGENT_VERSION], 1]]),
        metricsGauge('uso_office_agent_started_timestamp_seconds', 'When the office\'s agent started', (int) ($GLOBALS['started'] ?? $now)),
        metricsGauge('uso_metrics_written_timestamp_seconds', 'When the office last wrote its numbers (once a minute)', $now),
        metricsGauge('uso_metrics_dropped_families', 'Metric families left out to stay under the size cap of ' . METRICS_MAX_BYTES . ' bytes or to keep ' . METRICS_ROOM_FLOOR . ' bytes free where the files lie', $dropped),
        ...(function_exists('runDirMetrics') ? runDirMetrics() : []),     // its RAM folder and the room on /run (lib/rundir.php)
    ];
}

/** Every hired desk's hook: area (desk id) => what it returned */
function metricsCollect(): array
{
    $areas = [];
    $hired = staffHired();
    foreach (desks() as $id => $desk) {
        if (empty($desk['metrics']) || !in_array($id, $hired, true)) {
            continue;
        }
        try {
            $areas[$id] = ($desk['metrics'])();
            metricsNoteClear("hook:$id");
        } catch (Throwable $e) {
            metricsNote("hook:$id", "Metrics: $id: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }
    return $areas;
}

/** A gauge family; $samples a number for one series without labels, or [[labels, number], …] */
function metricsGauge(string $name, string $help, int|float|bool|array $samples): array
{
    return ['name' => $name, 'type' => 'gauge', 'help' => $help, 'samples' => is_array($samples) ? $samples : [[[], $samples]]];
}

/**
 * Only what Prometheus takes, in a fixed order: per area its families with a
 * valid name (uso_…, each name once over all areas — the first one wins),
 * type and at least one sample; per family the labels of its first sample
 * (every series of a family must have the same label names), sorted; values
 * numbers. What doesn't fit is left out, said once in the log.
 *
 * @return array<string, list<array{name:string, type:string, help:string, samples:list<array{0:array<string,string>, 1:string}>}>>
 */
function metricsClean(array $areas): array
{
    $out = [];
    $seen = [];
    foreach ($areas as $area => $families) {
        if (!is_string($area) || !preg_match(METRICS_AREA, $area) || !is_array($families)) {
            metricsNote('area:' . substr((string) $area, 0, 40), 'Metrics: left out the area ' . json_encode((string) $area) . ' (not a desk id, or no list)');
            continue;
        }
        foreach ($families as $f) {
            $name = is_array($f) && is_string($f['name'] ?? null) ? $f['name'] : '';
            $type = is_array($f) ? ($f['type'] ?? null) : null;
            if (!preg_match(METRICS_NAME, $name) || !str_starts_with($name, 'uso_') || !in_array($type, ['gauge', 'counter'], true)
                || !is_array($f['samples'] ?? null)) {
                metricsNote("family:$area:" . substr($name, 0, 80), "Metrics: $area: left out " . json_encode(substr($name, 0, 80)) . ' (needs a name uso_…, gauge or counter, samples)');
                continue;
            }
            if (isset($seen[$name])) {
                metricsNote("twice:$name", "Metrics: $area: left out $name — {$seen[$name]} has it already");
                continue;
            }
            $samples = metricsSamples($f['samples'], "$area: $name");
            if (!$samples) {
                continue;                  // nothing to say right now (or nothing valid)
            }
            $seen[$name] = $area;
            $out[$area][] = ['name' => $name, 'type' => $type, 'help' => is_string($f['help'] ?? null) ? $f['help'] : '', 'samples' => $samples];
        }
    }
    return $out;
}

/** @return list<array{0:array<string,string>, 1:string}> */
function metricsSamples(array $raw, string $where): array
{
    $samples = [];
    $keys = null;
    $series = [];
    foreach ($raw as $s) {
        if (count($samples) >= METRICS_MAX_SAMPLES) {
            metricsNote("many:$where", "Metrics: $where: more than " . METRICS_MAX_SAMPLES . ' series — the rest left out');
            break;
        }
        $labels = is_array($s) ? ($s[0] ?? null) : null;
        $value = is_array($s) ? ($s[1] ?? null) : null;
        if (!is_array($labels) || !(is_int($value) || is_float($value) || is_bool($value))) {
            metricsNote("sample:$where", "Metrics: $where: a series without labels and a number left out");
            continue;
        }
        $clean = [];
        foreach ($labels as $k => $v) {
            if (!is_string($k) || !preg_match(METRICS_NAME, $k) || str_starts_with($k, '__') || !(is_string($v) || is_int($v) || is_float($v))) {
                metricsNote("label:$where", "Metrics: $where: a series with an odd label left out");
                continue 2;
            }
            $clean[$k] = metricsLabelValue((string) $v);
        }
        ksort($clean);
        $keys ??= array_keys($clean);
        $id = jsonEncode($clean);
        if (array_keys($clean) !== $keys || isset($series[$id])) {
            metricsNote("labels:$where", "Metrics: $where: a series with other label names, or one twice, left out");
            continue;
        }
        $series[$id] = true;
        $samples[] = [$clean, metricsNumber(is_bool($value) ? (int) $value : $value)];
    }
    return $samples;
}

/** A label value as UTF-8 without control characters, at most 200 bytes */
function metricsLabelValue(string $v): string
{
    if (!mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    }
    $v = (string) preg_replace('/[\x00-\x09\x0b-\x1f\x7f]/', ' ', $v);
    return strlen($v) > 200 ? mb_strcut($v, 0, 200, 'UTF-8') : $v;
}

/** A number as Prometheus writes it */
function metricsNumber(int|float $v): string
{
    if (is_int($v)) {
        return (string) $v;
    }
    if (is_nan($v)) {
        return 'NaN';
    }
    if (is_infinite($v)) {
        return $v > 0 ? '+Inf' : '-Inf';
    }
    return (string) $v;
}

/** Families in Prometheus' text format (# HELP, # TYPE, one line per series) */
function metricsRender(array $families): string
{
    $text = '';
    foreach ($families as $f) {
        $help = str_replace(['\\', "\n"], ['\\\\', '\\n'], metricsLabelValue(str_replace("\r", '', $f['help'])));
        $text .= "# HELP {$f['name']} $help\n# TYPE {$f['name']} {$f['type']}\n";
        foreach ($f['samples'] as [$labels, $value]) {
            $pairs = [];
            foreach ($labels as $k => $v) {
                $pairs[] = $k . '="' . str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $v) . '"';
            }
            $text .= $f['name'] . ($pairs ? '{' . implode(',', $pairs) . '}' : '') . " $value\n";
        }
    }
    return $text;
}

/**
 * All areas within $budget bytes (and, given $fits, as long as it says no
 * for area => text): while too big, the family with the most
 * series (per-item ones: package sizes, per pool …) goes first; when only
 * single numbers are left, the biggest area as a whole. The order never
 * depends on the budget: a smaller one leaves out more of the same list.
 *
 * @return array{0: array<string,string>, 1: list<string>}  area => text, the names left out
 */
function metricsFit(array $areas, int $budget, ?callable $fits = null): array
{
    $dropped = [];
    while (true) {
        $texts = array_filter(array_map('metricsRender', $areas), 'strlen');
        if (!$texts || (array_sum(array_map('strlen', $texts)) <= $budget && ($fits === null || $fits($texts)))) {
            return [$texts, $dropped];
        }
        $pick = null;
        foreach ($areas as $area => $families) {
            foreach ($families as $i => $f) {
                $rank = [count($f['samples']), strlen(metricsRender([$f]))];
                if ($rank[0] > 1 && ($pick === null || $rank > $pick[2])) {
                    $pick = [$area, $i, $rank];
                }
            }
        }
        if ($pick !== null) {
            $dropped[] = $areas[$pick[0]][$pick[1]]['name'];
            unset($areas[$pick[0]][$pick[1]]);
            continue;
        }
        arsort($texts);
        $area = (string) array_key_first($texts);
        array_push($dropped, ...array_column($areas[$area], 'name'));
        unset($areas[$area]);
    }
}

/**
 * Numbers worked out from a state file, again only when the file changed
 * (inode, size, time): a big state file isn't read every minute for nothing.
 * $build gets the path (the file may be missing).
 */
function metricsCached(string $file, callable $build): mixed
{
    clearstatcache(true, $file);
    $st = @stat($file);
    $key = $st ? "{$st['ino']}:{$st['size']}:{$st['mtime']}" : '-';
    $hit = $GLOBALS['metricsCache'][$file] ?? null;
    if ($hit !== null && $hit[0] === $key) {
        return $hit[1];
    }
    $value = $build($file);
    $GLOBALS['metricsCache'][$file] = [$key, $value];
    return $value;
}

/**
 * Are the office's numbers there and fresh? true / false — for the team
 * lead: the office's own file (written every minute) younger than METRICS_FRESH
 */
function metricsFresh(?string $dir = null, ?int $now = null): bool
{
    $file = ($dir ?? metricsDir()) . '/uso_' . METRICS_OFFICE . '.prom';
    clearstatcache(true, $file);
    $st = @lstat($file);
    return $st !== false && ($st['mode'] & 0170000) === 0100000 && ($now ?? time()) - $st['mtime'] <= METRICS_FRESH;
}
