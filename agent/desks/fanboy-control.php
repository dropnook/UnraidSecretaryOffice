<?php
declare(strict_types=1);

/*
 * Fanboy's control service («Regel-Dienst»): ONE process for all zones (`php agent.php job fanboy-control`, started by
 * the agent's tick in a session of its own — an agent restarting itself in place never touches it, so the fans never
 * flap then). It runs only while the array is started, he is hired, not hidden, set up and «taken over» (armed), and no
 * other known controller runs; otherwise every output goes back to the BIOS and the status says why.
 *
 * A round every FANBOY_ROUND seconds, all waits on the monotonic clock (fanboyRound() gets the clock from its caller —
 * the tests drive it with theirs):
 *   1. the outputs looked up anew through part 1's identity (chip + address + output, never hwmonN); the chip not
 *      there: looked for every 5 s for two minutes, then every minute, ONE notification after 15 minutes, then a quiet
 *      look every 5 minutes; «look now» (RUN_DIR/fanboy/look-now) at once; he takes over when it comes (one
 *      notification when the 15-minute one went) and reads the rpm back after the start (a fan that no longer follows
 *      its measured table is said, once)
 *   2. someone else's write (value or mode changed behind his back): that output given back to the BIOS, said once
 *   3. each zone: its heat sources read (Unraid's disks only from disks.ini — a sleeping disk counts as «asleep», never
 *      as cold, and is never asked; sensors from hwmon), each mapped from its own low/high onto the zone's, the warmest
 *      counts, the curve gives the zone's level (percent, or rpm for measured fans with feedback: by the table, then
 *      corrected until the rpm fits); up at once, down only FANBOY_GAP °C below where it went up and slowly; never under
 *      a fan's lowest steady value («off» only where the user allowed it)
 *   4. alarms — a fan that should turn stands (his or a BIOS one he watches), a temperature over its limit at full
 *      speed, a sensor that says nothing, the chip gone: every output he holds to full speed where he can still write
 *      (the others are back with the BIOS), in the status, one notification per alarm until it is over
 *   5. history: per zone a point a minute (warmest °C, level, rpm), FANBOY_HISTORY_MAX points
 */

const FANBOY_ROUND = 2.0;               // seconds between rounds
const FANBOY_GAP = 2.0;                 // °C below the temperature it went up at before it goes down
const FANBOY_DOWN_STEP = 1.0;           // percent a round at most going down (30 % a minute)
const FANBOY_DOWN_STEP_RPM = 15;        // rpm a round at most going down (rpm zones)
const FANBOY_FLOOR_MARGIN = 5;          // percent above the lowest steady value measured
const FANBOY_BLIND_FLOOR = 40;          // percent at least for a fan without rpm feedback or not measured
const FANBOY_KICK = 4.0;                // seconds at the start value when a fan starts from standing
const FANBOY_KICK_MAX = 2;              // kicks from standing without the fan turning — then no more: it stands (the alarm)
const FANBOY_STAND_AFTER = 15.0;        // seconds at 0 rpm while it should turn: it stands
const FANBOY_HOT_AFTER = 60.0;          // seconds over a limit at full speed: alarm
const FANBOY_SENSOR_AFTER = 30.0;       // seconds a sensor says nothing: alarm
const FANBOY_DISK_SENSOR_AFTER = 120.0; // seconds an awake disk has no temperature in disks.ini: alarm
const FANBOY_CLEAR_AFTER = 120.0;       // seconds without any alarm reason before the alarm is over
const FANBOY_VERIFY_AFTER = 12.0;       // seconds after the start: the rpm read back against the table
const FANBOY_DRIFT = 0.30;              // a measured fan off its table by more than this (and 250 rpm): said
const FANBOY_LOOK_FAST = 5.0;           // the chip missing: a look every 5 s …
const FANBOY_LOOK_FAST_FOR = 120.0;     // … for two minutes,
const FANBOY_LOOK_SLOW = 60.0;          // then every minute
const FANBOY_TELL_AFTER = 900.0;        // 15 minutes without the chip: one notification
const FANBOY_LOOK_QUIET = 300.0;        // after that a look every 5 minutes
const FANBOY_HISTORY_EVERY = 60.0;
const FANBOY_HISTORY_MAX = 1460;        // points per zone at most: 12 h of minutes + 60 h of 5-minute spans (720 + 720, a few at the seams)
const FANBOY_HISTORY_FULL = 43200;      // round 3 (owner): the last 12 hours a point a minute …
const FANBOY_HISTORY_THIN = 300;        // … older ones a point per 5 minutes (the span's warmest, highest level, highest rpm) …
const FANBOY_HISTORY_SPAN = 259200;     // … up to 3 days; older ones go (a zone removed: its history with them)
const FANBOY_HISTORY_PERIODS = [1, 3, 6, 12, 24, 72];      // hours the page may ask for
const FANBOY_HISTORY_KEEP = 600.0;      // the history into the data folder every 10 minutes (RAM every minute)
const FANBOY_RPM_TRIM_MAX = 15;         // percent an rpm zone's correction moves a fan at most
// a heat source's own scale by its kind: low = cool (the curve's start), high = needs all the air, limit = alarm
const FANBOY_SOURCE_DEFAULTS = [
    'disk'  => ['low' => 35, 'high' => 45, 'limit' => 52],      // a hard disk (rotating)
    'ssd'   => ['low' => 40, 'high' => 60, 'limit' => 70],      // an SSD or NVMe of Unraid's (disks.ini, not rotating) — round 4
    'nvme'  => ['low' => 40, 'high' => 65, 'limit' => 75],
    'cpu'   => ['low' => 45, 'high' => 80, 'limit' => 92],
    'ram'   => ['low' => 40, 'high' => 60, 'limit' => 75],
    'gpu'   => ['low' => 45, 'high' => 80, 'limit' => 90],
    'board' => ['low' => 35, 'high' => 55, 'limit' => 70],
    'other' => ['low' => 35, 'high' => 55, 'limit' => 75],
];
const FANBOY_PRESETS = ['quiet', 'normal', 'cool'];
const FANBOY_DISK_KINDS = ['disk', 'ssd'];                      // sources from Unraid's disks.ini (never asked, never woken)
const FANBOY_ROLES_BIOS = ['cpu', 'pump', 'unknown'];           // stay with the BIOS: never written again
const FANBOY_ROLES_OURS = ['case', 'disks', 'ram', 'nvme', 'other'];
const FANBOY_ALARMS = ['stands', 'bios_stands', 'hot', 'sensor', 'chip'];

// ===================================================================== curves and sources (pure)

/** A zone's own scale: low/high of its first source's kind (or the zone's own) */
function fanboyZoneScale(array $zone): array
{
    $kind = $zone['sources'][0]['kind'] ?? 'disk';
    $d = FANBOY_SOURCE_DEFAULTS[$kind] ?? FANBOY_SOURCE_DEFAULTS['other'];
    $lo = is_numeric($zone['low'] ?? null) ? (float) $zone['low'] : (float) $d['low'];
    $hi = is_numeric($zone['high'] ?? null) ? (float) $zone['high'] : (float) $d['high'];
    return [$lo, $hi > $lo ? $hi : $lo + 10];
}

/** A preset as points on the zone's scale (percent) */
function fanboyPreset(string $preset, float $lo, float $hi): array
{
    $mid = ($lo + $hi) / 2;
    return match ($preset) {
        'quiet' => [[$lo, 0], [$mid, 30], [$hi, 60], [$hi + 5, 100]],
        'cool'  => [[$lo - 3, 35], [$mid - 3, 65], [$hi - 3, 100]],
        default => [[$lo, 20], [$mid, 45], [$hi, 80], [$hi + 3, 100]],
    };
}

/** The zone's curve: its own points, else its preset (normal) — [[°C, percent|rpm]…] sorted */
function fanboyZoneCurve(array $zone): array
{
    $points = $zone['target']['points'] ?? null;
    if (is_array($points) && count($points) >= 2) {
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);
        return array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $points);
    }
    [$lo, $hi] = fanboyZoneScale($zone);
    return fanboyPreset(in_array($zone['target']['preset'] ?? null, FANBOY_PRESETS, true) ? $zone['target']['preset'] : 'normal', $lo, $hi);
}

/** The curve at a temperature: linear between its points, flat beyond the ends */
function fanboyCurveAt(array $curve, float $t): float
{
    if ($t <= $curve[0][0]) {
        return $curve[0][1];
    }
    for ($i = 1, $n = count($curve); $i < $n; $i++) {
        if ($t <= $curve[$i][0]) {
            [$t0, $v0] = $curve[$i - 1];
            [$t1, $v1] = $curve[$i];
            return $t1 > $t0 ? $v0 + ($v1 - $v0) * ($t - $t0) / ($t1 - $t0) : $v1;
        }
    }
    return $curve[$n - 1][1];
}

/**
 * A source's temperature on the zone's scale: from its own low/high onto the zone's. A source of the zone's own kind
 * without a scale of its own counts as it is (a disk in a disk zone: 41 °C is 41 °C).
 */
function fanboyMapped(array $source, float $t, float $lo, float $hi, string $zoneKind = ''): float
{
    if ($source['kind'] === $zoneKind && !is_numeric($source['low'] ?? null) && !is_numeric($source['high'] ?? null)) {
        return $t;
    }
    $d = FANBOY_SOURCE_DEFAULTS[$source['kind']] ?? FANBOY_SOURCE_DEFAULTS['other'];
    $sl = is_numeric($source['low'] ?? null) ? (float) $source['low'] : (float) $d['low'];
    $sh = is_numeric($source['high'] ?? null) ? (float) $source['high'] : (float) $d['high'];
    return $sh > $sl ? $lo + ($t - $sl) * ($hi - $lo) / ($sh - $sl) : $t;
}

function fanboySourceLimit(array $source): float
{
    return is_numeric($source['limit'] ?? null) ? (float) $source['limit'] : (float) (FANBOY_SOURCE_DEFAULTS[$source['kind']] ?? FANBOY_SOURCE_DEFAULTS['other'])['limit'];
}

/** A measured fan's table [[pct, rpm]…]: the rpm at a percentage (linear), and the percentage for an rpm */
function fanboyTableRpm(array $table, float $pct): ?float
{
    $t = array_values(array_filter($table, fn ($p) => $p[1] > 0));
    usort($t, fn ($a, $b) => $a[0] <=> $b[0]);
    if (!$t) {
        return null;
    }
    if ($pct <= $t[0][0]) {
        return (float) $t[0][1];
    }
    foreach ($t as $i => $p) {
        if ($i > 0 && $pct <= $p[0]) {
            [$a, $ra] = $t[$i - 1];
            return $ra + ($p[1] - $ra) * ($pct - $a) / max(1, $p[0] - $a);
        }
    }
    return (float) end($t)[1];
}

function fanboyTablePct(array $table, float $rpm): ?float
{
    $t = array_values(array_filter($table, fn ($p) => $p[1] > 0));
    usort($t, fn ($a, $b) => $a[1] <=> $b[1]);
    if (!$t) {
        return null;
    }
    if ($rpm <= $t[0][1]) {
        return (float) $t[0][0];
    }
    foreach ($t as $i => $p) {
        if ($i > 0 && $rpm <= $p[1]) {
            [$a, $ra] = $t[$i - 1];
            return $a + ($p[0] - $a) * ($rpm - $ra) / max(1, $p[1] - $ra);
        }
    }
    return 100.0;
}

/** A source's id: «<chip name>@<device key>#<n>» — the device without a class device's boot-numbered tail (nvme/nvme2) */
function fanboySourceId(array $chip, int $n): string
{
    return $chip['name'] . '@' . fanboyDevKey($chip['device']) . "#$n";
}

function fanboyDevKey(string $device): string
{
    return (string) preg_replace('#/([a-z]+)/\1\d+$#D', '/$1', $device);
}

/** Every heat source the server offers now: Unraid's disks (by name) and every hwmon temperature (no drivetemp) */
function fanboySources(array $scan): array
{
    $out = [];
    foreach ($scan['disks'] ?? [] as $d) {
        // a rotating disk is «disk», an SSD/NVMe pool member «ssd»: their temperatures mean different things (round 4)
        $out[] = ['kind' => ($d['rotational'] ?? true) ? 'disk' : 'ssd', 'id' => $d['name'], 'label' => $d['name'], 'c' => $d['temp'], 'asleep' => $d['asleep']];
    }
    foreach ($scan['chips'] ?? [] as $c) {
        if ($c['kind'] === 'disk') {
            continue;               // drivetemp asks the disk: never (Unraid's disks.ini says it)
        }
        foreach ($c['temps'] as $t) {
            $out[] = ['kind' => $c['kind'], 'id' => fanboySourceId($c, $t['n']), 'label' => $t['label'] !== '' ? $t['label'] : $c['name'] . " #{$t['n']}",
                      'c' => $t['c'], 'asleep' => false, 'chip' => $c['name'], 'hwmon' => $c['hwmon'], 'n' => $t['n']];
        }
    }
    return $out;
}

// ===================================================================== the service's state

/**
 * A new control for this board's settings: its zones and fans (only those the setup gave him — roles of his own,
 * found with a tach or as «silent»), nothing taken yet.
 */
function fanboyControlNew(array $settings, string $boardKey, array $owner): array
{
    $fans = [];
    $zones = [];
    foreach (is_array($settings['zones'] ?? null) ? $settings['zones'] : [] as $z) {
        if (!is_array($z) || !is_string($z['id'] ?? null) || !is_array($z['fans'] ?? null)) {
            continue;
        }
        $ids = [];
        foreach ($z['fans'] as $id) {
            $f = is_string($id) ? ($settings['fans'][$id] ?? null) : null;
            if (!is_array($f) || isset($fans[$id]) || !in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true)
                || !in_array($f['found'] ?? null, ['tach', 'silent'], true) || fanboyStoredFan($f) === null) {
                continue;
            }
            $m = is_array($f['measured'] ?? null) && is_array($f['measured']['table'] ?? null) ? $f['measured'] : null;
            $hasTach = ($f['found'] ?? '') === 'tach';
            $floor = $m !== null && $hasTach && is_numeric($m['min_pct'] ?? null) ? min(100, (int) $m['min_pct'] + FANBOY_FLOOR_MARGIN) : FANBOY_BLIND_FLOOR;
            $fans[$id] = [
                'id' => $id, 'zone' => $z['id'], 'chip' => $f['chip'], 'address' => $f['address'], 'output' => $f['output'],
                'tachs' => array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [$f['tach']], 'is_int')),
                'name' => is_string($f['name'] ?? null) ? $f['name'] : '',
                'table' => $m['table'] ?? null, 'floor' => $floor,
                'start' => $m !== null && is_numeric($m['start_pct'] ?? null) ? (int) $m['start_pct'] : $floor,
                'off_ok' => ($f['off_ok'] ?? false) === true && $m !== null && $hasTach,
                // «give back» (part 3): the owner's choice for a fan found on a fixed value — 'auto' gives the chip's mode
                'back' => in_array($f['back'] ?? null, FANBOY_BACKS, true) ? $f['back'] : FANBOY_BACK_DEFAULT, 'auto' => null,
                'device' => null, 'pct' => null, 'value' => null, 'changed' => null, 'kick_until' => null, 'trim' => 0.0,
                'rpm' => null, 'zero_since' => null, 'kicks' => 0, 'gone' => false, 'foreign' => false, 'taken' => false, 'verified' => false,
            ];
            $ids[] = $id;
        }
        if ($ids) {
            $zones[$z['id']] = [
                'id' => $z['id'], 'name' => is_string($z['name'] ?? null) ? $z['name'] : $z['id'], 'fans' => $ids,
                'sources' => array_values(array_filter(is_array($z['sources'] ?? null) ? $z['sources'] : [], fn ($s) => is_array($s) && is_string($s['id'] ?? null) && is_string($s['kind'] ?? null))),
                'target' => is_array($z['target'] ?? null) ? $z['target'] : ['preset' => 'normal'],
                'unit' => ($z['unit'] ?? 'pct') === 'rpm' ? 'rpm' : 'pct',
                'low' => $z['low'] ?? null, 'high' => $z['high'] ?? null,
                'cur' => null, 'hold_t' => null, 'warmest' => null, 'hot_since' => null, 'level' => null, 'state' => [],
            ];
        }
    }
    // the BIOS's fans he only watches (CPU cooler, pump with a tach): one standing is an alarm too
    $watch = [];
    foreach (is_array($settings['fans'] ?? null) ? $settings['fans'] : [] as $id => $f) {
        if (is_array($f) && in_array($f['role'] ?? null, ['cpu', 'pump'], true) && ($f['found'] ?? null) === 'tach' && fanboyStoredFan($f) !== null) {
            $watch[$id] = ['id' => $id, 'chip' => $f['chip'], 'address' => $f['address'], 'role' => $f['role'],
                'tachs' => array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [$f['tach']], 'is_int')),
                'name' => is_string($f['name'] ?? null) ? $f['name'] : '', 'zero_since' => null, 'rpm' => null];
        }
    }
    return [
        'key' => $boardKey, 'owner' => $owner, 'phase' => 'start', 'hold' => null, 'fans' => $fans, 'zones' => $zones, 'watch' => $watch,
        'chips' => [], 'sources' => [], 'alarm' => null, 'reasons' => [], 'clear_since' => null, 'told' => [], 'notes' => [],
        'missing' => null, 'started' => null, 'last_hist' => null, 'last_keep' => null, 'hist' => [], 'look_now' => false,
        'disks_at' => null, 'disks' => [], 'scan_at' => null, 'events' => [],
    ];
}

/** A note for the status and the log, once per kind and fan (the last 20 kept) */
function fanboyNote(array &$c, string $code, array $params = []): void
{
    $c['events'][] = ['code' => $code, 'params' => $params, 'at' => time()];
    $c['notes'] = array_slice(array_merge($c['notes'], [['code' => $code, 'params' => $params, 'at' => time()]]), -20);
}

/**
 * One notification through the office's gate — once per $once until fanboyUntell() (an alarm over, the chip back).
 * The texts: notify.<key>_subject and notify.<key> in his language file.
 */
function fanboyTell(array &$c, string $once, string $key, array $params, string $level): void
{
    if (isset($c['told'][$once])) {
        return;
    }
    $c['told'][$once] = time();
    if (!function_exists('officeNotify')) {
        return;
    }
    $lang = function_exists('officeNotifyLang') ? officeNotifyLang() : 'en';
    officeNotify(officeNotifyText('fanboy', "notify.{$key}_subject", $params, $lang), officeNotifyText('fanboy', "notify.$key", $params, $lang),
        $level, '', officeNotifyLink('#/fanboy'), 'fanboy', $key);
}

function fanboyUntell(array &$c, string $prefix): void
{
    foreach (array_keys($c['told']) as $k) {
        if (str_starts_with($k, $prefix)) {
            unset($c['told'][$k]);
        }
    }
}

/** A fan's or source's name for a text: the user's, else «output N» */
function fanboyFanLabel(array $f): string
{
    return $f['name'] !== '' ? $f['name'] : $f['chip'] . ' pwm' . ($f['output'] ?? '?');
}

// ===================================================================== finding the outputs (every start, every look)

/**
 * Looks the zones' outputs up in a fresh scan through part 1's identity — all of them there: [true, chips by
 * address]; one missing: [false, why] (missing · ambiguous · no_output)
 */
function fanboyLocate(array &$c, array $scan): array
{
    $hwmon = fanboyPaths(fanboySysRoot() ?: null)['hwmon'];
    foreach ($c['fans'] as $id => $f) {
        $r = fanboyResolve(['chip' => $f['chip'], 'address' => $f['address'], 'output' => $f['output'], 'tach' => $f['tachs'][0] ?? null], $scan, $hwmon);
        if ($r['path'] === null) {
            return [false, $r['how']];
        }
        foreach ($scan['chips'] as $chip) {
            if ($chip['name'] === $f['chip'] && $chip['address'] === $r['address']) {
                $c['fans'][$id]['auto'] = ($f['back'] ?? null) === 'auto'
                    ? fanboyAutoMode($chip, $f['output'], fanboyInitialRead()['boards'][$c['key']][$id] ?? null) : null;
                $c['fans'][$id]['device'] = $chip['device'];
                $c['fans'][$id]['address_now'] = $chip['address'];
                $c['fans'][$id]['hwmon'] = $chip['hwmon'];
                if ($r['how'] === 'moved' && !isset($c['told']["moved:$id"])) {
                    $c['told']["moved:$id"] = time();
                    fanboyNote($c, 'moved', ['fan' => fanboyFanLabel($f), 'from' => $f['address'], 'to' => $chip['address']]);
                }
            }
        }
    }
    foreach ($c['watch'] as $id => $w) {
        foreach ($scan['chips'] as $chip) {
            if ($chip['name'] === $w['chip'] && ($chip['address'] === $w['address'] || count(array_filter($scan['chips'], fn ($x) => $x['name'] === $w['chip'])) === 1)) {
                $c['watch'][$id]['hwmon'] = $chip['hwmon'];
            }
        }
    }
    return [true, null];
}

/** The rpm of a fan's tach inputs now (this boot's hwmonN): [rpm…] — null for one that says nothing */
function fanboyTachs(?string $hwmon, array $tachs): array
{
    if ($hwmon === null) {
        return [];
    }
    $dir = fanboyPaths(fanboySysRoot() ?: null)['hwmon'] . "/$hwmon";
    return array_map(fn ($n) => fanboyReadInt("$dir/fan{$n}_input"), $tachs);
}

/** The zones' sources now: [id => {c, asleep, label, kind}] — disks from disks.ini (every 10 s), sensors every round */
function fanboyReadSources(array &$c, float $now): void
{
    $p = fanboyPaths(fanboySysRoot() ?: null);
    if ($c['disks_at'] === null || $now - $c['disks_at'] >= 10) {
        $c['disks'] = [];
        foreach (fanboyDisks($p['disks']) as $d) {
            $c['disks'][$d['name']] = $d;
        }
        $c['disks_at'] = $now;
        // a zone saved before round 4 calls an SSD pool member «disk»: on the SSD's scale and limit from now on
        foreach ($c['zones'] as $zid => $z) {
            foreach ($z['sources'] as $i => $s) {
                if ($s['kind'] === 'disk' && ($c['disks'][$s['id']]['rotational'] ?? true) === false) {
                    $c['zones'][$zid]['sources'][$i]['kind'] = 'ssd';
                    if (isset($c['sources'][$s['id']])) {
                        $c['sources'][$s['id']]['kind'] = 'ssd';
                    }
                }
            }
        }
    }
    if ($c['scan_at'] === null || $now - $c['scan_at'] >= 30 || array_filter($c['sources'], fn ($s) => $s['path'] === null && !in_array($s['kind'], FANBOY_DISK_KINDS, true))) {
        // where each sensor is this boot (looked up anew every 30 s and while one is missing)
        $chips = fanboyChips($p['hwmon']);
        $where = [];
        foreach ($chips as $chip) {
            foreach ($chip['temps'] as $t) {
                $where[fanboySourceId($chip, $t['n'])] = ['path' => "{$p['hwmon']}/{$chip['hwmon']}/temp{$t['n']}_input", 'label' => $t['label'], 'disk' => $chip['kind'] === 'disk'];
            }
        }
        foreach ($c['zones'] as $z) {
            foreach ($z['sources'] as $s) {
                $w = $where[$s['id']] ?? null;
                $c['sources'][$s['id']] = ['kind' => $s['kind'], 'path' => $w !== null && !$w['disk'] ? $w['path'] : null,
                    'label' => in_array($s['kind'], FANBOY_DISK_KINDS, true) ? $s['id'] : (($w['label'] ?? '') !== '' ? $w['label'] : $s['id'])] + ($c['sources'][$s['id']] ?? []);
            }
        }
        $c['scan_at'] = $now;
    }
    foreach ($c['sources'] as $id => $s) {
        if (in_array($s['kind'], FANBOY_DISK_KINDS, true)) {
            $d = $c['disks'][$id] ?? null;
            $c['sources'][$id]['asleep'] = $d !== null && $d['asleep'];
            $c['sources'][$id]['c'] = $d['temp'] ?? null;
            $c['sources'][$id]['there'] = $d !== null;
        } else {
            $raw = $s['path'] === null ? null : fanboyReadInt($s['path']);
            $c['sources'][$id]['asleep'] = false;
            $c['sources'][$id]['c'] = $raw === null || $raw < -40000 || $raw > 200000 ? null : round($raw / 1000, 1);
            $c['sources'][$id]['there'] = $s['path'] !== null;
        }
        // since when it says nothing (an asleep disk says nothing on purpose)
        $quiet = $c['sources'][$id]['c'] === null && !$c['sources'][$id]['asleep'];
        $c['sources'][$id]['quiet_since'] = $quiet ? ($s['quiet_since'] ?? $now) : null;
    }
}

// ===================================================================== one round

/** Takes every fan of the zones for the control (refused ones are tried again next round) */
function fanboyTakeAll(array &$c): void
{
    foreach ($c['fans'] as $id => $f) {
        if ($f['taken'] || $f['foreign'] || $f['device'] === null) {
            continue;
        }
        $t = fanboyTake(['id' => $id, 'chip' => $f['chip'], 'address' => $f['address_now'] ?? $f['address'], 'device' => $f['device'], 'output' => $f['output'],
            'auto' => $f['auto'] ?? null], $c['owner'], $c['key']);
        if ($t !== null) {
            $c['fans'][$id]['taken'] = true;
            $c['fans'][$id]['value'] = null;
        }
    }
}

/** Every output he holds: given back to the BIOS (read back) — why: stop · hold · chip · foreign … */
function fanboyLetGo(array &$c, string $why, ?array $ids = null): array
{
    $r = fanboyGiveBack($ids, $why, $c['owner']);
    foreach ($ids ?? array_keys($c['fans']) as $id) {
        if (isset($c['fans'][$id])) {
            $c['fans'][$id]['taken'] = false;
            $c['fans'][$id]['value'] = null;
            $c['fans'][$id]['pct'] = null;
        }
    }
    foreach ($r as $id => $x) {
        if (!$x['ok'] && $x['why'] !== 'gone') {
            fanboyNote($c, 'give_back_failed', ['fan' => isset($c['fans'][$id]) ? fanboyFanLabel($c['fans'][$id]) : $id]);
        }
    }
    return $r;
}

/**
 * One round of the control at $now (monotonic seconds). $c['hold']: a reason not to steer now (another controller):
 * everything back to the BIOS, nothing taken.
 */
function fanboyRound(array &$c, float $now): void
{
    if ($c['hold'] !== null) {
        if (array_filter($c['fans'], fn ($f) => $f['taken'])) {
            fanboyLetGo($c, 'hold');
        }
        $c['phase'] = 'hold';
        return;
    }
    // 1. the outputs: found (again)?
    if ($c['phase'] === 'start' || $c['phase'] === 'hold' || $c['phase'] === 'wait_chip') {
        if ($c['phase'] === 'wait_chip' && !$c['look_now'] && $now < $c['missing']['next']) {
            fanboyMissingTell($c, $now);
            return;
        }
        $c['look_now'] = false;
        [$ok, $why] = fanboyLocate($c, fanboyDetect(fanboySysRoot() ?: null));
        if (!$ok) {
            fanboyMissing($c, $now, $why);
            return;
        }
        if ($c['missing'] !== null) {
            if (isset($c['told']['missing'])) {
                fanboyTell($c, 'back', 'back', [], 'normal');      // he steers after all: said once, as the 15 minutes were
            }
            fanboyNote($c, 'chip_back', ['minutes' => (int) round(($now - $c['missing']['since']) / 60)]);
            fanboyUntell($c, 'missing');
            fanboyUntell($c, 'back');
        }
        $c['missing'] = null;
        $c['phase'] = 'run';
        $c['started'] = $now;
        $c['scan_at'] = null;
        foreach ($c['fans'] as $id => $f) {
            $c['fans'][$id]['verified'] = false;
        }
    }
    fanboyTakeAll($c);
    // 2. someone else's write: that output back to the BIOS, said once
    foreach (fanboyForeign($c['owner']) as $id => $x) {
        fanboyLetGo($c, 'foreign', [$id]);
        $c['fans'][$id]['foreign'] = true;
        $label = fanboyFanLabel($c['fans'][$id]);
        fanboyNote($c, 'foreign', ['fan' => $label]);
        fanboyTell($c, "foreign:$id", 'foreign', ['fan' => $label], 'warning');
    }
    // 3. what is there now: rpm, sources, the chip
    $gone = [];
    foreach ($c['fans'] as $id => $f) {
        if (!$f['taken']) {
            continue;
        }
        if ($f['device'] === null || fanboyOutputFiles($f['device'], $f['output']) === null) {
            $gone[] = $id;
            continue;
        }
        $rpms = fanboyTachs($f['hwmon'] ?? null, $f['tachs']);
        $c['fans'][$id]['rpms'] = $rpms;
        $c['fans'][$id]['rpm'] = $rpms && !in_array(null, $rpms, true) ? (int) round(array_sum($rpms) / count($rpms)) : null;
    }
    if ($gone) {
        // the chip gone: what can't be written any more is the BIOS's (nothing to write — said); the rest to full
        fanboyLetGo($c, 'chip', $gone);
        $c['reasons']['chip'] = ['fan' => fanboyFanLabel($c['fans'][$gone[0]])];
        fanboyAlarm($c, $now, ['chip' => $c['reasons']['chip']]);
        fanboyFull($c);
        fanboyLetGo($c, 'chip');
        fanboyMissing($c, $now, 'missing');
        return;
    }
    fanboyReadSources($c, $now);
    foreach ($c['watch'] as $id => $w) {
        $r = fanboyTachs($w['hwmon'] ?? null, $w['tachs']);
        $zero = $r && in_array(0, $r, true);
        $c['watch'][$id]['rpm'] = $r ? $r[0] : null;
        $c['watch'][$id]['zero_since'] = $zero ? ($w['zero_since'] ?? $now) : null;
    }
    // 4. each zone's level, each fan's value
    $reasons = [];
    foreach ($c['zones'] as $zid => $z) {
        fanboyZoneRound($c, $zid, $now, $reasons);
    }
    foreach ($c['watch'] as $w) {
        if ($w['zero_since'] !== null && $now - $w['zero_since'] >= FANBOY_STAND_AFTER) {
            $reasons['bios_stands'] = ['fan' => $w['name'] !== '' ? $w['name'] : $w['chip'] . ' fan' . ($w['tachs'][0] ?? '?')];
        }
    }
    fanboyAlarm($c, $now, $reasons);
    if ($c['alarm'] !== null) {
        fanboyFull($c);
    } else {
        foreach ($c['fans'] as $id => $f) {
            if ($f['taken'] && $f['pct'] !== null) {
                fanboyApply($c, $id, $now);
            }
        }
    }
    // 5. read back after the start: does each measured fan still follow its table?
    if ($c['started'] !== null && $now - $c['started'] >= FANBOY_VERIFY_AFTER) {
        foreach ($c['fans'] as $id => $f) {
            if (!$f['taken'] || $f['verified'] || $f['table'] === null || $f['rpm'] === null || $f['pct'] === null || $f['kick_until'] !== null) {
                continue;
            }
            $c['fans'][$id]['verified'] = true;
            $want = fanboyTableRpm($f['table'], (float) $f['pct']);
            if ($want !== null && abs($f['rpm'] - $want) > max(250, $want * FANBOY_DRIFT)) {
                $label = fanboyFanLabel($f);
                fanboyNote($c, 'drift', ['fan' => $label, 'rpm' => $f['rpm'], 'want' => (int) round($want)]);
                fanboyTell($c, "drift:$id", 'drift', ['fan' => $label, 'rpm' => $f['rpm'], 'want' => (int) round($want)], 'warning');
            }
        }
    }
    fanboyHistory($c, $now);
}

/** A zone's round: its warmest source, the curve, up at once / down slowly, each fan's percentage; alarm reasons */
function fanboyZoneRound(array &$c, string $zid, float $now, array &$reasons): void
{
    $z = $c['zones'][$zid];
    [$lo, $hi] = fanboyZoneScale($z);
    $warmest = null;
    $hot = false;
    $states = [];
    foreach ($z['sources'] as $s) {
        $r = $c['sources'][$s['id']] ?? null;
        if ($r === null) {
            continue;
        }
        if ($r['asleep']) {
            $states[$s['id']] = 'asleep';
            continue;
        }
        if ($r['c'] === null) {
            $states[$s['id']] = 'quiet';
            $after = in_array($s['kind'], FANBOY_DISK_KINDS, true) ? FANBOY_DISK_SENSOR_AFTER : FANBOY_SENSOR_AFTER;
            if ($r['quiet_since'] !== null && $now - $r['quiet_since'] >= $after) {
                $reasons['sensor'] = ['source' => $r['label'], 'zone' => $z['name']];
            }
            continue;
        }
        $states[$s['id']] = 'ok';
        $m = fanboyMapped($s, (float) $r['c'], $lo, $hi, $z['sources'][0]['kind'] ?? '');
        if ($warmest === null || $m > $warmest['mapped']) {
            $warmest = ['id' => $s['id'], 'label' => $r['label'], 'c' => $r['c'], 'mapped' => round($m, 1), 'kind' => $s['kind']];
        }
        if ($r['c'] >= fanboySourceLimit($s)) {
            $hot = true;
            $hotWhat = ['source' => $r['label'], 'c' => $r['c'], 'zone' => $z['name']];
        }
    }
    $curve = fanboyZoneCurve($z);
    // nothing warm to go by (all asleep, none): the curve's start — its quiet level
    $raw = $warmest === null ? $curve[0][1] : fanboyCurveAt($curve, $warmest['mapped']);
    if ($z['unit'] === 'pct') {
        $raw = max(0.0, min(100.0, $raw));
    }
    $cur = $z['cur'];
    $t = $warmest['mapped'] ?? $lo;
    if ($hot) {
        $cur = $z['unit'] === 'pct' ? 100.0 : max($raw, $cur ?? $raw);     // over a limit: full now (each fan below)
        $z['hot_since'] ??= $now;
        if ($now - $z['hot_since'] >= FANBOY_HOT_AFTER) {
            $reasons['hot'] = $hotWhat ?? [];
        }
    } else {
        $z['hot_since'] = null;
        if ($cur === null || $raw >= $cur) {
            $cur = $raw;                                            // up at once
            $z['hold_t'] = $t;
        } elseif ($t <= ($z['hold_t'] ?? $t) - FANBOY_GAP) {
            $cur = max($raw, $cur - ($z['unit'] === 'pct' ? FANBOY_DOWN_STEP : FANBOY_DOWN_STEP_RPM));   // down slowly
        }
    }
    $z['cur'] = $cur;
    $z['warmest'] = $warmest;
    $z['state'] = $states;
    $c['zones'][$zid] = $z;
    // each fan's percentage
    foreach ($z['fans'] as $id) {
        $f = $c['fans'][$id];
        if (!$f['taken']) {
            continue;
        }
        if ($z['unit'] === 'rpm' && !$hot) {
            $pct = $f['table'] !== null ? (fanboyTablePct($f['table'], $cur) ?? 100.0) : 100.0;
            // correct until the rpm fits: a fan settled for 6 s, more than 4 % off — a percent a round, ±FANBOY_RPM_TRIM_MAX
            if ($f['table'] !== null && $f['rpm'] !== null && $f['changed'] !== null && $now - $f['changed'] >= 6 && $cur > 0) {
                $err = ($cur - $f['rpm']) / $cur;
                if (abs($err) > 0.04) {
                    $c['fans'][$id]['trim'] = max(-FANBOY_RPM_TRIM_MAX, min(FANBOY_RPM_TRIM_MAX, $f['trim'] + ($err > 0 ? 1 : -1)));
                }
            }
            $pct += $c['fans'][$id]['trim'];
        } else {
            $pct = $z['unit'] === 'pct' || $hot ? ($hot ? 100.0 : $cur) : 100.0;
        }
        $pct = max(0.0, min(100.0, $pct));
        if ($pct < 0.5 && $f['off_ok']) {
            $pct = 0.0;
        } else {
            $pct = max($pct, (float) $f['floor']);
        }
        $c['fans'][$id]['pct'] = (int) round($pct);
        // it stands though it should turn: every one of its tach inputs counted (one fan of two on a hub too). The clock runs
        // ACROSS kicks (2026-11-15: a floor under the start value kicked a blocked fan for ever, each kick resetting the
        // clock — no alarm); it stops only when the fan turns, and then the kicks are counted from nought again
        $rpms = $f['rpms'] ?? [];
        $zero = $f['tachs'] && $rpms && in_array(0, $rpms, true) && $c['fans'][$id]['pct'] > 0;
        $c['fans'][$id]['zero_since'] = $zero ? ($f['zero_since'] ?? $now) : null;
        if ($rpms && !in_array(0, $rpms, true) && !in_array(null, $rpms, true)) {
            $c['fans'][$id]['kicks'] = 0;
        }
        if ($zero && $now - $c['fans'][$id]['zero_since'] >= FANBOY_STAND_AFTER) {
            $reasons['stands'] = ['fan' => fanboyFanLabel($f), 'zone' => $z['name']];
        }
    }
}

/** Writes a fan's percentage when its value changed: from standing, its start value for FANBOY_KICK seconds first */
function fanboyApply(array &$c, string $id, float $now): void
{
    $f = $c['fans'][$id];
    $pct = $f['pct'];
    if ($f['kick_until'] !== null && $now < $f['kick_until']) {
        $pct = max($pct, $f['start']);
    } elseif ($f['kick_until'] !== null) {
        $c['fans'][$id]['kick_until'] = null;
    } elseif ($pct > 0 && $pct < $f['start'] && ($f['value'] === 0 || ($f['rpm'] === 0 && $f['tachs'])) && ($f['kicks'] ?? 0) < FANBOY_KICK_MAX) {
        $c['fans'][$id]['kick_until'] = $now + FANBOY_KICK;       // standing (off, or stalled): kicked at its start value
        $c['fans'][$id]['kicks'] = ($f['kicks'] ?? 0) + 1;         // … a few times at most: then it stands, said by the alarm
        $pct = max($pct, $f['start']);
    }
    $value = (int) round($pct * 2.55);
    if ($value === $f['value']) {
        return;
    }
    $back = fanboySet($id, $value, $c['owner']);
    if ($back !== null) {
        $c['fans'][$id]['value'] = $value;
        $c['fans'][$id]['changed'] = $now;
    }
}

/** Every output he holds to full speed (the alarm) */
function fanboyFull(array &$c): void
{
    foreach ($c['fans'] as $id => $f) {
        if ($f['taken'] && $f['value'] !== FANBOY_FULL && fanboySet($id, FANBOY_FULL, $c['owner']) !== null) {
            $c['fans'][$id]['value'] = FANBOY_FULL;
            $c['fans'][$id]['pct'] = 100;
        }
    }
}

/**
 * The alarm: on with the first reason (each reason told once — stands, bios_stands, hot, sensor, chip), over only after
 * FANBOY_CLEAR_AFTER seconds without any
 */
function fanboyAlarm(array &$c, float $now, array $reasons): void
{
    foreach ($reasons as $code => $what) {
        if ($c['alarm'] === null) {
            $c['alarm'] = ['code' => $code, 'params' => $what, 'since' => time(), 'mono' => $now];
            fanboyNote($c, "alarm_$code", $what);
        }
        fanboyTell($c, "alarm:$code", "alarm_$code", $what, 'alert');
    }
    $c['reasons'] = $reasons;
    if ($reasons) {
        $c['clear_since'] = null;
    } elseif ($c['alarm'] !== null) {
        $c['clear_since'] ??= $now;
        if ($now - $c['clear_since'] >= FANBOY_CLEAR_AFTER) {
            fanboyNote($c, 'alarm_over', ['code' => $c['alarm']['code']]);
            $c['alarm'] = null;
            $c['clear_since'] = null;
            fanboyUntell($c, 'alarm:');
        }
    }
}

/** The chip isn't there: everything back with the BIOS, the next look planned (5 s, then 1 min, after 15 min every 5 min) */
function fanboyMissing(array &$c, float $now, ?string $why): void
{
    if (array_filter($c['fans'], fn ($f) => $f['taken'])) {
        fanboyLetGo($c, 'chip');
    }
    $c['missing'] ??= ['since' => $now, 'why' => $why, 'at' => time()];
    $c['missing']['why'] = $why;
    $c['phase'] = 'wait_chip';
    $age = $now - $c['missing']['since'];
    $c['missing']['next'] = $now + ($age < FANBOY_LOOK_FAST_FOR ? FANBOY_LOOK_FAST : ($age < FANBOY_TELL_AFTER ? FANBOY_LOOK_SLOW : FANBOY_LOOK_QUIET));
    fanboyMissingTell($c, $now);
}

function fanboyMissingTell(array &$c, float $now): void
{
    if ($c['missing'] !== null && $now - $c['missing']['since'] >= FANBOY_TELL_AFTER && !isset($c['told']['missing'])) {
        fanboyNote($c, 'missing_told', ['minutes' => (int) floor(($now - $c['missing']['since']) / 60)]);
        fanboyTell($c, 'missing', 'missing', [], 'warning');
    }
}

/**
 * A point a minute per zone: [time, warmest °C, level %, rpm]; then every zone's history thinned and bounded
 * (fanboyHistoryThin()) — a zone no longer there keeps its points until they are 3 days old, then goes. $wall: the tests'
 * clock (unix seconds), else now.
 */
function fanboyHistory(array &$c, float $now, ?int $wall = null): void
{
    if ($c['last_hist'] !== null && $now - $c['last_hist'] < FANBOY_HISTORY_EVERY) {
        return;
    }
    $c['last_hist'] = $now;
    $wall ??= time();
    foreach ($c['zones'] as $zid => $z) {
        $fans = array_filter(array_map(fn ($id) => $c['fans'][$id], $z['fans']), fn ($f) => $f['taken']);
        $rpms = array_filter(array_column($fans, 'rpm'), 'is_int');
        $pcts = array_filter(array_column($fans, 'pct'), 'is_int');
        $c['hist'][$zid][] = [$wall, $z['warmest']['c'] ?? null, $pcts ? (int) round(array_sum($pcts) / count($pcts)) : null, $rpms ? (int) round(array_sum($rpms) / count($rpms)) : null];
    }
    foreach ($c['hist'] as $zid => $pts) {
        $c['hist'][$zid] = fanboyHistoryThin($pts, $wall);
        if (!$c['hist'][$zid] && !isset($c['zones'][$zid])) {
            unset($c['hist'][$zid]);
        }
    }
}

/** The larger of two values that may be missing (null) */
function fanboyMaxOf(mixed $a, mixed $b): mixed
{
    return $a === null ? $b : ($b === null ? $a : max($a, $b));
}

/**
 * One zone's history kept small: points of the last 12 hours as they are (a minute each), older ones folded into
 * 5-minute spans — the span's latest time, its warmest temperature, its highest level and rpm (a peak never disappears) —,
 * nothing older than 3 days, at most FANBOY_HISTORY_MAX points. Only points in exactly their shape; a span folded once
 * stays as it is (idempotent: run it every minute, on a file of today's shape or an older one).
 */
function fanboyHistoryThin(array $pts, int $wall): array
{
    $num = fn ($v) => is_int($v) || is_float($v) ? $v : null;
    $full = $spans = [];
    foreach ($pts as $p) {
        if (!is_array($p) || !array_is_list($p) || count($p) !== 4 || !is_int($p[0]) || $p[0] < $wall - FANBOY_HISTORY_SPAN) {
            continue;
        }
        $p = [$p[0], $num($p[1]), $num($p[2]), $num($p[3])];
        if ($p[0] >= $wall - FANBOY_HISTORY_FULL) {
            $full[] = $p;
            continue;
        }
        $k = intdiv($p[0], FANBOY_HISTORY_THIN);
        $b = $spans[$k] ?? null;
        $spans[$k] = $b === null ? $p : [max($b[0], $p[0]), fanboyMaxOf($b[1], $p[1]), fanboyMaxOf($b[2], $p[2]), fanboyMaxOf($b[3], $p[3])];
    }
    ksort($spans);
    usort($full, fn ($a, $b) => $a[0] <=> $b[0]);
    return array_slice([...array_values($spans), ...$full], -FANBOY_HISTORY_MAX);
}

// ===================================================================== the status (RAM) the page and the agent read

function fanboyStatusFile(): string
{
    return fanboyRunDir() . '/control.json';
}

/** What the control says about itself: who steers and why, the zones, the fans, the alarm, the notes */
function fanboyStatus(array $c, ?string $exitWhy = null): array
{
    $steering = $exitWhy === null && $c['phase'] === 'run' && (bool) array_filter($c['fans'], fn ($f) => $f['taken']);
    $why = $exitWhy ?? match ($c['phase']) {
        'run' => $c['alarm'] !== null ? 'alarm' : null,
        'hold' => $c['hold'] ?? 'hold',
        'wait_chip' => 'chip_missing',
        default => 'starting',
    };
    $zones = [];
    foreach ($c['zones'] as $z) {
        $zones[] = ['id' => $z['id'], 'name' => $z['name'], 'unit' => $z['unit'], 'level' => $z['cur'] === null ? null : round($z['cur'], 1),
            'warmest' => $z['warmest'], 'asleep' => count(array_filter($z['state'], fn ($s) => $s === 'asleep')),
            'fans' => array_map(fn ($id) => ['id' => $id, 'name' => $c['fans'][$id]['name'], 'output' => $c['fans'][$id]['output'],
                'pct' => $c['fans'][$id]['taken'] ? $c['fans'][$id]['pct'] : null, 'rpm' => $c['fans'][$id]['rpm'] ?? null,
                'held' => $c['fans'][$id]['taken'], 'foreign' => $c['fans'][$id]['foreign']], $z['fans'])];
    }
    return [
        'v' => 1, 'pid' => getmypid(), 'time' => time(), 'mono' => fanboyMono(), 'board' => $c['key'],
        'who' => $steering ? 'fanboy' : 'bios', 'why' => $why, 'phase' => $exitWhy !== null ? 'ended' : $c['phase'],
        'zones' => $zones, 'alarm' => $c['alarm'], 'reasons' => array_keys($c['reasons']),
        'missing' => $c['missing'] === null ? null : ['since' => $c['missing']['at'], 'why' => $c['missing']['why'], 'told' => isset($c['told']['missing'])],
        'watch' => array_values(array_map(fn ($w) => ['id' => $w['id'], 'name' => $w['name'], 'role' => $w['role'], 'rpm' => $w['rpm']], $c['watch'])),
        'notes' => $c['notes'],
        // since when each fan (and each watched BIOS fan) stands though it should turn — on the monotonic clock, so a
        // control started again goes on counting instead of starting from nought (three restarts in a row still alarm)
        'stand_since' => array_filter(array_map(fn ($f) => $f['zero_since'], $c['fans']) + array_map(fn ($w) => $w['zero_since'], $c['watch']), fn ($v) => $v !== null),
    ];
}

function fanboyStatusWrite(array $status): void
{
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyStatusFile(), jsonEncode($status), 0644, 0, 0);
}

/** The history kept: RAM every minute, the data folder every 10 minutes and at the end */
function fanboyHistoryKeep(array $c, bool $toData): void
{
    $j = jsonEncode(['v' => 1, 'board' => $c['key'], 'every' => FANBOY_HISTORY_EVERY, 'zones' => $c['hist']]);
    writeAtomic(fanboyRunDir() . '/history.json', $j, 0644, 0, 0);
    if ($toData && defined('FANBOY_DIR') && is_dir(FANBOY_DIR)) {
        writeAtomic(FANBOY_DIR . '/history.json', $j);
    }
}

/**
 * The history at the start: the RAM one (this boot), else the data folder's — only this board's, only well-formed points,
 * thinned and bounded as it is kept (a file of an older shape — a day of minutes — read as it is). $wall: the tests' clock.
 */
function fanboyHistoryLoad(string $board, ?int $wall = null): array
{
    foreach ([fanboyRunDir() . '/history.json', defined('FANBOY_DIR') ? FANBOY_DIR . '/history.json' : ''] as $f) {
        $j = $f === '' ? null : readJson($f);
        if (is_array($j) && ($j['board'] ?? null) === $board && is_array($j['zones'] ?? null)) {
            $out = [];
            foreach ($j['zones'] as $zid => $pts) {
                if (is_string($zid) && is_array($pts) && ($kept = fanboyHistoryThin($pts, $wall ?? time()))) {
                    $out[$zid] = $kept;
                }
            }
            return $out;
        }
    }
    return [];
}

// ===================================================================== the process

/**
 * Why the control may not steer now — the one place that decides (the process every 10 s, the agent's tick):
 *   array_stopped · hidden · not_hired · not_set_up · not_armed (exit) — other (stay, give back, look again)
 */
function fanboyHoldReason(?array $settings): ?string
{
    if (function_exists('arrayRunning') && !arrayRunning(fanboyVarIni())) {
        return 'array_stopped';
    }
    if (!fanboyShown()) {
        return 'hidden';
    }
    if (!in_array('fanboy', staffHired(), true)) {
        return 'not_hired';
    }
    if ($settings === null || !fanboyControlNew($settings, '', ['pid' => 0])['zones']) {
        return 'not_set_up';
    }
    if (($settings['armed'] ?? false) !== true) {
        return 'not_armed';
    }
    if (fanboyBlocking(fanboyControllers(fanboyPaths(fanboySysRoot() ?: null)))) {
        return 'other';
    }
    return null;
}

/** Unraid's var.ini (the array's state) — in the tests' tree when they name one */
function fanboyVarIni(): string
{
    return fanboySysRoot() . '/var/local/emhttp/var.ini';
}

/** This board's raw settings (the store as written — zones, fans, armed), null when there are none */
function fanboyBoardRaw(?array $board = null): ?array
{
    $board ??= fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $raw = readJson(fanboyStoreFile());
    $b = $raw['boards'][fanboyBoardKey($board)] ?? null;
    return is_array($b) ? $b : null;
}

/**
 * The control process: `php agent.php job fanboy-control`. One at a time (a flock in RAM held for its life). Ends —
 * every output given back first, read back — on TERM/INT/HUP, when the array stops, he is hidden, fired, or not
 * armed any more; holds (everything with the BIOS) while another controller runs.
 */
function fanboyControlMain(): int
{
    $dir = fanboyRunDir();
    @mkdir($dir, 0700, true);
    $lock = @fopen("$dir/control.lock", 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;                       // one runs already
    }
    $GLOBALS['fanboyStop'] = false;
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
        pcntl_signal($sig, function () { $GLOBALS['fanboyStop'] = true; });
    }
    $owner = fanboyMe('control');
    writeAtomic("$dir/control.pid", $owner['pid'] . ' ' . $owner['start'], 0600, 0, 0);
    $board = fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $key = fanboyBoardKey($board);
    $settings = fanboyBoardRaw($board);
    $why = fanboyHoldReason($settings);
    $c = fanboyControlNew($settings ?? [], $key, $owner);
    $c['hist'] = fanboyHistoryLoad($key);
    fanboyStandCarry($c, readJson(fanboyStatusFile()), fanboyMono());
    $stamp = @filemtime(fanboyStoreFile());
    $lastHold = fanboyMono();
    $exit = in_array($why, ['other', null], true) ? null : $why;
    logLine('Fanboy: the control started (PID ' . $owner['pid'] . ', ' . count($c['zones']) . ' zones, ' . count($c['fans']) . ' fans)');
    try {
        while (!fanboyStopAsked() && $exit === null) {
            $now = fanboyMono();
            fanboyBeat();
            if ($now - $lastHold >= 10) {
                $lastHold = $now;
                clearstatcache();
                $new = @filemtime(fanboyStoreFile());
                if ($new !== $stamp) {          // the settings changed (zones, roles, a hand-back): taken anew
                    $stamp = $new;
                    $settings = fanboyBoardRaw($board);
                    $why = fanboyHoldReason($settings);
                    if ($why === null || $why === 'other') {
                        fanboyLetGo($c, 'settings');
                        $hist = $c['hist'];
                        $c = fanboyControlNew($settings ?? [], $key, $owner);
                        $c['hist'] = $hist;
                    }
                } else {
                    $why = fanboyHoldReason($settings);
                }
                if ($why !== null && $why !== 'other') {
                    $exit = $why;
                    break;
                }
                $c['hold'] = $why;
            }
            if (is_file("$dir/look-now")) {
                @unlink("$dir/look-now");
                $c['look_now'] = true;
            }
            fanboyRound($c, $now);
            fanboyControlLog($c);
            fanboyStatusWrite(fanboyStatus($c));
            if ($c['last_hist'] === $now) {
                $toData = $c['last_keep'] === null || $now - $c['last_keep'] >= FANBOY_HISTORY_KEEP;
                fanboyHistoryKeep($c, $toData);
                if ($toData) {
                    $c['last_keep'] = $now;
                }
            }
            $until = $now + FANBOY_ROUND;
            while (!fanboyStopAsked() && fanboyMono() < $until && !is_file("$dir/look-now")) {
                usleep(200000);
            }
        }
    } finally {
        $r = fanboyLetGo($c, $exit ?? 'stop');
        $bad = array_keys(array_filter($r, fn ($x) => !$x['ok'] && $x['why'] !== 'gone'));
        fanboyControlLog($c);
        logLine('Fanboy: the control ended (' . ($exit ?? 'stop') . ') — ' . count($r) . ' outputs back with the BIOS'
            . ($bad ? ', NOT read back as before: ' . implode(', ', $bad) : ''));
        fanboyStatusWrite(fanboyStatus($c, $exit ?? 'stopped'));
        fanboyHistoryKeep($c, true);
        @unlink("$dir/beat-" . $owner['pid']);
        @unlink("$dir/control.pid");
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return 0;
}

/**
 * A standing fan's clock from the control before (its status in RAM), when that is fresh (≤ 10 minutes on the monotonic
 * clock): a fan blocked across restarts alarms after FANBOY_STAND_AFTER all told, not anew each time
 */
function fanboyStandCarry(array &$c, ?array $status, float $now): void
{
    foreach (is_array($status['stand_since'] ?? null) ? $status['stand_since'] : [] as $id => $since) {
        if (!is_string($id) || !(is_float($since) || is_int($since)) || $since > $now || $now - $since > 600) {
            continue;
        }
        if (isset($c['fans'][$id])) {
            $c['fans'][$id]['zero_since'] = (float) $since;
        } elseif (isset($c['watch'][$id])) {
            $c['watch'][$id]['zero_since'] = (float) $since;
        }
    }
}

/** Was the control asked to end (TERM, INT, HUP — its signal handler)? */
function fanboyStopAsked(): bool
{
    return ($GLOBALS['fanboyStop'] ?? false) === true;
}

/** The control's notes into the agent's log (each once) */
function fanboyControlLog(array &$c): void
{
    foreach ($c['events'] as $e) {
        $p = $e['params'];
        $what = implode(', ', array_map(fn ($k, $v) => "$k " . (is_scalar($v) ? $v : json_encode($v)), array_keys($p), $p));
        logLine("Fanboy: {$e['code']}" . ($what !== '' ? " ($what)" : ''));
    }
    $c['events'] = [];
}
