<?php
declare(strict_types=1);

/*
 * The Night Watchman's «too warm for hours» (1.96, the concept «Der Nachtwächter in 1.96», 11.3 D; kind `hw_hot` in the
 * area `hw`). Unraid tells a disk that crosses its own limit; nobody says «six hours just under it». Every round he reads
 * the sensors, counts the minutes each was over its kind's limit in the last 24 hours, and books a sensor once that time
 * reaches the setting — one entry a sensor and day, told once (WATCH_KINDS), a short peak is nothing.
 *
 * Sources — his own small reader, three drivers; Fanboy's reader (fanboy-detect.php) steers fans and stays his:
 *   NVMe   hwmon `nvme`: temp1_input (Composite). Reading it is an admin command (Get Log Page 02h): it wakes nothing —
 *          an NVMe doesn't spin down like a disk (nw196-new-4-hardware.md §5, §7)
 *   CPU    hwmon `coretemp` (each «Package id N», its own limit temp*_max = Tcontrol: where all cooling should run) and
 *          `k10temp` (Tdie else Tctl; its limit temp1_crit, the HTC throttle point, when the CPU has one — the driver's
 *          temp1_max is a fixed 70 °C, no limit of the CPU's: without temp1_crit an AMD CPU isn't judged)
 *   disks  /var/local/emhttp/disks.ini: Unraid's own temperature of a disk that is awake (array and pools, NVMe left to
 *          hwmon). A disk asleep, or spun down without a temperature, is skipped — never read: no `drivetemp` (reading it
 *          may reset some disks' spin-down timer: they would never sleep again — the kernel's docs), never smartctl.
 * The night shift: NVMe and CPU (RAM only); disks.ini is the day's (watchmanNightPaths() leaves `hot_disks` out).
 *
 * Settings (concept 3.1: `rules.json` set.hw, only what differs; a value outside its bounds counts as not set):
 * degrees and minutes per kind, the CPU's degrees are its own. Defaults (the concept's derivation, not a vendor's text):
 * NVMe 2 h in 24 over 70 °C (vendors: 0–70 °C operating), disks 2 h over 55 °C (Seagate: not sustained over 60 °C),
 * CPU 30 min over its own limit.
 * Kept: state.json `hot` — per sensor the spans over its limit in the last 24 hours, its last and highest reading.
 */

// set.hw key => [default, lowest, highest]
const WATCH_HOT_SET = [
    'hot_nvme_c'   => [70, 40, 95],
    'hot_nvme_min' => [120, 10, 1440],
    'hot_disk_c'   => [55, 35, 70],
    'hot_disk_min' => [120, 10, 1440],
    'hot_cpu_min'  => [30, 10, 1440],
];
const WATCH_HOT_WINDOW  = 86400;          // the minutes over are counted in the last 24 hours
const WATCH_HOT_STEP    = 2 * 300;        // a round counts for the time since the last one, at most this much (rounds missed: not guessed)
const WATCH_HOT_SENSORS = 64;             // sensors kept at most
const WATCH_HOT_KINDS   = ['nvme', 'disk', 'cpu'];

/** The settings: the defaults, a value from set.hw only as a whole number within its bounds */
function watchmanHotSettings(?array $set): array
{
    $out = [];
    foreach (WATCH_HOT_SET as $k => [$def, $lo, $hi]) {
        $v = $set[$k] ?? null;
        $out[$k] = is_int($v) && $v >= $lo && $v <= $hi ? $v : $def;
    }
    return $out;
}

/** set.hw of his rules file (concept 3.1: `{v: 1, set: {<area>: {<key>: <value>}}}`), only in exactly that shape — else none */
function watchmanHotSet(string $dir): ?array
{
    $r = readJson("$dir/rules.json");
    return is_array($r) && ($r['v'] ?? null) === 1 && is_array($r['set']['hw'] ?? null) ? $r['set']['hw'] : null;
}

/** A hwmon temperature file in °C (millidegrees), or null */
function watchmanHotMilli(string $file): ?int
{
    $v = @file_get_contents($file, false, null, 0, 16);
    return is_string($v) && preg_match('/^-?\d{1,7}\s*$/D', $v) ? (int) round(((int) trim($v)) / 1000) : null;
}

/**
 * The sensors now: list of {id, kind: nvme|disk|cpu, name, c, limit (the CPU's own; null for the others: theirs is the
 * setting)} — null when nothing could be read at all. $paths: `hwmon` (the folder), `hot_disks` (disks.ini; the night
 * has none). NVMe named by its pool's disk in disks.ini when it is one (nvme0 → «cache»), else by the controller.
 */
function watchmanHotLook(array $paths): ?array
{
    $out = [];
    $read = false;
    $disks = [];
    $ini = isset($paths['hot_disks']) ? (string) $paths['hot_disks'] : '';
    if ($ini !== '' && is_file($ini) && !is_link($ini)) {
        $read = true;
        foreach (readCfg($ini, true) as $section => $d) {
            $disks[] = ['name' => (string) ($d['name'] ?? $section)] + $d;
        }
    }
    $nvmeName = function (string $ctrl) use ($disks): string {
        foreach ($disks as $d) {
            if (preg_match('/^' . preg_quote($ctrl, '/') . 'n\d+$/D', (string) ($d['device'] ?? '')) && preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $d['name'])) {
                return $d['name'];
            }
        }
        return $ctrl;
    };
    $dir = isset($paths['hwmon']) ? (string) $paths['hwmon'] : '';
    $mons = $dir !== '' ? (glob("$dir/hwmon*", GLOB_NOSORT) ?: []) : [];
    natsort($mons);
    foreach ($mons as $h) {
        $driver = trim((string) @file_get_contents("$h/name", false, null, 0, 64));
        if (!in_array($driver, ['nvme', 'coretemp', 'k10temp'], true)) {
            continue;                       // drivetemp among them: never read
        }
        $read = true;
        if ($driver === 'nvme') {
            $c = watchmanHotMilli("$h/temp1_input");
            $ctrl = basename((string) @readlink("$h/device"));
            if (!preg_match('/^nvme\d{1,3}$/D', $ctrl)) {
                $ctrl = basename($h);
            }
            if ($c !== null) {
                $name = $nvmeName($ctrl);
                $out[] = ['id' => "nvme:$name", 'kind' => 'nvme', 'name' => $name, 'c' => $c, 'limit' => null];
            }
            continue;
        }
        $labels = [];
        foreach (glob("$h/temp*_label") ?: [] as $f) {
            if (preg_match('/temp(\d{1,2})_label$/D', $f, $m)) {
                $labels[(int) $m[1]] = trim((string) @file_get_contents($f, false, null, 0, 64));
            }
        }
        if ($driver === 'coretemp') {
            foreach ($labels as $n => $label) {
                if (!preg_match('/^Package id (\d{1,3})$/D', $label, $m)) {
                    continue;
                }
                $c = watchmanHotMilli("$h/temp{$n}_input");
                $limit = watchmanHotMilli("$h/temp{$n}_max");
                if ($c !== null) {
                    $out[] = ['id' => "cpu:{$m[1]}", 'kind' => 'cpu', 'name' => (int) $m[1] === 0 ? 'CPU' : "CPU {$m[1]}", 'c' => $c,
                              'limit' => $limit !== null && $limit > 0 ? $limit : null];
                }
            }
            continue;
        }
        // k10temp: Tdie where it is, else Tctl (temp1); the limit only its HTC point (temp1_crit)
        $n = array_search('Tdie', $labels, true);
        $n = $n === false ? 1 : $n;
        $c = watchmanHotMilli("$h/temp{$n}_input");
        $limit = watchmanHotMilli("$h/temp1_crit");
        $idx = count(array_filter($out, fn ($s) => $s['kind'] === 'cpu'));
        if ($c !== null) {
            $out[] = ['id' => "cpu:$idx", 'kind' => 'cpu', 'name' => $idx === 0 ? 'CPU' : "CPU $idx", 'c' => $c, 'limit' => $limit !== null && $limit > 0 ? $limit : null];
        }
    }
    foreach ($disks as $d) {
        $type = (string) ($d['type'] ?? '');
        $dev = (string) ($d['device'] ?? '');
        if ($type === 'Flash' || $dev === '' || str_starts_with($dev, 'nvme') || str_starts_with((string) ($d['status'] ?? ''), 'DISK_NP')
            || !preg_match('/^[A-Za-z0-9_.-]{1,32}$/D', $d['name'])) {
            continue;
        }
        // asleep (a rotating disk spun down), or spun down without a temperature (an SSD in standby): nothing to say
        if (diskAsleep($d) || (($d['spundown'] ?? '0') === '1' && !ctype_digit((string) ($d['temp'] ?? ''))) || !preg_match('/^\d{1,3}$/D', (string) ($d['temp'] ?? ''))) {
            continue;
        }
        $out[] = ['id' => "disk:{$d['name']}", 'kind' => 'disk', 'name' => $d['name'], 'c' => (int) $d['temp'], 'limit' => null];
    }
    return $read ? array_slice($out, 0, WATCH_HOT_SENSORS) : null;
}

/** A sensor's limit: the setting of its kind (NVMe, disk), the CPU's own (null: not judged) */
function watchmanHotLimit(array $s, array $set): ?int
{
    return match ($s['kind']) {
        'nvme'  => $set['hot_nvme_c'],
        'disk'  => $set['hot_disk_c'],
        default => is_int($s['limit'] ?? null) ? $s['limit'] : null,
    };
}

/** Minutes the spans hold within the window ending $now */
function watchmanHotMinutes(array $spans, int $now): int
{
    $sec = 0;
    foreach ($spans as [$from, $to]) {
        $sec += max(0, min((int) $to, $now) - max((int) $from, $now - WATCH_HOT_WINDOW));
    }
    return intdiv($sec, 60);
}

/**
 * This round's readings against what he keeps ($st['hot']): a sensor over its limit adds the time since the last round
 * (at most WATCH_HOT_STEP) to its spans; spans older than 24 hours go, a sensor not seen for 24 hours too. Once a
 * sensor's minutes over reach its setting: `hw_hot` (key hw_hot:<sensor>) — while that entry is open it is only brought
 * up to date; noted, the next comes no sooner than the next day. $fresh (taken over anew): only counted.
 *
 * @return list<string>  the kinds booked new
 */
function watchmanHotCompare(array &$book, array &$st, ?array $look, array $set, int $now, bool $fresh): array
{
    if ($look === null) {
        return [];
    }
    $hot = is_array($st['hot'] ?? null) ? $st['hot'] : [];
    $last = is_int($hot['time'] ?? null) && $hot['time'] < $now ? $hot['time'] : null;
    $dt = $last === null ? 0 : min($now - $last, WATCH_HOT_STEP);
    $keep = [];
    foreach ((array) ($hot['sensors'] ?? []) as $id => $k) {
        if (is_array($k) && is_int($k['seen'] ?? null) && $k['seen'] > $now - WATCH_HOT_WINDOW) {
            $keep[(string) $id] = $k;
        }
    }
    $added = [];
    foreach ($look as $s) {
        $id = (string) $s['id'];
        $k = $keep[$id] ?? ['spans' => []];
        $limit = watchmanHotLimit($s, $set);
        $spans = array_values(array_filter((array) ($k['spans'] ?? []), fn ($x) => is_array($x) && count($x) === 2 && (int) $x[1] > $now - WATCH_HOT_WINDOW));
        if ($limit !== null && $s['c'] > $limit && $dt > 0) {
            $n = count($spans);
            if ($n && (int) $spans[$n - 1][1] >= $now - $dt) {
                $spans[$n - 1][1] = $now;
            } else {
                $spans[] = [$now - $dt, $now];
            }
        }
        $peak = $s['c'];
        if ($spans && is_int($k['peak'] ?? null) && (int) ($k['peak_at'] ?? 0) > $now - WATCH_HOT_WINDOW) {
            $peak = max($peak, $k['peak']);
        }
        $keep[$id] = ['kind' => $s['kind'], 'name' => $s['name'], 'c' => $s['c'], 'limit' => $limit, 'seen' => $now, 'spans' => $spans,
                      'peak' => $peak, 'peak_at' => $peak === $s['c'] ? $now : (int) ($k['peak_at'] ?? $now)];
        $minutes = watchmanHotMinutes($spans, $now);
        $need = $set['hot_' . $s['kind'] . '_min'] ?? null;
        if ($fresh || $limit === null || !is_int($need) || $minutes < $need) {
            continue;
        }
        $p = ['sensor' => $id, 'kind' => $s['kind'], 'name' => $s['name'], 'c' => $s['c'], 'peak' => $peak, 'limit' => $limit, 'minutes' => $minutes];
        $key = "hw_hot:$id";
        $today = date('Y-m-d', $now);
        $open = null;
        $sameDay = false;
        foreach ($book as $i => $e) {
            if (($e['key'] ?? '') !== $key) {
                continue;
            }
            if (watchmanOpen($e)) {
                $open = $i;
            } elseif (date('Y-m-d', (int) $e['time']) === $today) {
                $sameDay = true;
            }
        }
        if ($open !== null) {
            $book[$open]['p'] = $p;
            $book[$open]['last'] = $now;
        } elseif (!$sameDay) {
            $book[] = watchmanEntry('hw_hot', $key, $now, $p);
            $added[] = 'hw_hot';
        }
    }
    if (count($keep) > WATCH_HOT_SENSORS) {
        uasort($keep, fn ($a, $b) => (int) $b['seen'] <=> (int) $a['seen']);
        $keep = array_slice($keep, 0, WATCH_HOT_SENSORS, true);
    }
    $st['hot'] = ['time' => $now, 'sensors' => $keep];
    return $added;
}

/** An entry's words: {name, c, peak, limit, time} — `time` the minutes over as «2 h 10 min» */
function watchmanHotText(array $p): array
{
    $min = (int) ($p['minutes'] ?? 0);
    return ['name' => (string) ($p['name'] ?? ''), 'c' => (string) (int) ($p['c'] ?? 0), 'peak' => (string) (int) ($p['peak'] ?? 0),
            'limit' => (string) (int) ($p['limit'] ?? 0), 'time' => sprintf('%d h %02d min', intdiv($min, 60), $min % 60)];
}

/** Its words' shape: the sensor's kind (entry.hw_hot_nvme, …_disk, …_cpu) */
function watchmanHotVariant(array $e): ?string
{
    $k = $e['p']['kind'] ?? null;
    return ($e['kind'] ?? '') === 'hw_hot' && in_array($k, WATCH_HOT_KINDS, true) ? "hw_hot_$k" : null;
}
