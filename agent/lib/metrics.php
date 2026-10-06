<?php
declare(strict_types=1);

/*
 * The office's own numbers for Prometheus — through a Node Exporter's
 * textfile collector, which reads every *.prom file in a folder and serves
 * it along with its own numbers (Prometheus fetches them from there).
 *
 * The folder is METRICS_HOST_DIR, in Unraid's place for add-on mounts — a
 * 1 MB tmpfs (RAM, gone after a reboot) shared with the backup engine's mount
 * points. The agent creates it at start (metricsStart(), never /mnt/addons itself) and
 * writes once a minute from its loop (metricsTick()):
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
 * first, said once in the agent log. Files of desks that were let go or no
 * longer report are removed.
 *
 * OFFICE_METRICS_DIR in the environment points somewhere else (the tests).
 */

const METRICS_HOST_DIR    = '/mnt/addons/UnraidSecretaryOffice/metrics';
const METRICS_HOST_BASE   = '/mnt/addons';          // Unraid's: never created by the office
const METRICS_EVERY       = 60;                     // seconds between two writes
const METRICS_MAX_BYTES   = 16384;                  // all files together (the tmpfs has 1 MB, shared)
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
 * folder (no link) of the agent's own user. The base is /mnt/addons (Unraid's
 * tmpfs) for the real folder — if that isn't there, nothing is written — and
 * the parent for another one.
 */
function metricsEnsureDir(string $dir): bool
{
    $base = $dir === METRICS_HOST_DIR ? METRICS_HOST_BASE : dirname($dir);
    clearstatcache();
    $st = @lstat($base);
    if (!$st || ($st['mode'] & 0170000) !== 0040000) {
        metricsNote('dir', "Metrics: $base isn't there — the office's numbers for Prometheus are not written");
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
 * One round: every hired desk's numbers and the office's own into their
 * files, within the size cap; files nobody reports any more go. $areas and
 * $dir are there for the tests (area => families as a hook returns them).
 *
 * @return array{files: array<string,int>, dropped: list<string>}|null  file => bytes, families left out; null: not written
 */
function metricsWrite(int $now, ?array $areas = null, ?string $dir = null): ?array
{
    $dir ??= metricsDir();
    if (!metricsEnsureDir($dir)) {
        return null;
    }
    // the office's own names first, so no desk can take them; a desk called "office" is left out
    $areas = metricsClean([METRICS_OFFICE => metricsOffice($now, 0)] + ($areas ?? metricsCollect()));
    $office = metricsRender($areas[METRICS_OFFICE] ?? []);
    unset($areas[METRICS_OFFICE]);
    // the office's own file is never cut; room for a longer number of families left out
    [$texts, $dropped] = metricsFit($areas, METRICS_MAX_BYTES - strlen($office) - 8);
    if ($dropped) {
        $office = metricsRender(metricsClean([METRICS_OFFICE => metricsOffice($now, count($dropped))])[METRICS_OFFICE] ?? []);
    }
    $texts = [METRICS_OFFICE => $office] + $texts;

    $new = array_diff($dropped, array_keys($GLOBALS['metricsDropped'] ?? []));
    if ($new) {
        logLine(sprintf('Metrics: over %d KB — left out %s', intdiv(METRICS_MAX_BYTES, 1024), implode(', ', $new)));
    }
    $GLOBALS['metricsDropped'] = array_fill_keys($dropped, true);

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
        metricsGauge('uso_metrics_dropped_families', 'Metric families left out to stay under the size cap of ' . METRICS_MAX_BYTES . ' bytes', $dropped),
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
 * All areas within $budget bytes: while too big, the family with the most
 * series (per-item ones: package sizes, per pool …) goes first; when only
 * single numbers are left, the biggest area as a whole.
 *
 * @return array{0: array<string,string>, 1: list<string>}  area => text, the names left out
 */
function metricsFit(array $areas, int $budget): array
{
    $dropped = [];
    while (true) {
        $texts = array_filter(array_map('metricsRender', $areas), 'strlen');
        if (!$texts || array_sum(array_map('strlen', $texts)) <= $budget) {
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
