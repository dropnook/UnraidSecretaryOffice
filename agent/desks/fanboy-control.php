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
const FANBOY_REFUSED_MAX = 3;           // round 5: writes refused in a row before «the driver takes no values»
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
/*
 * The way down per kind (round 4, the owner): up is immediate always; down waits until the leading source is `gap` °C
 * under the temperature it went up at, holds `hold` seconds more, then steps `step` % a round (FANBOY_ROUND, 2 s). Disks
 * slow as ever (no hold, 1 % a round); a CPU or GPU quick but never a snap (6 s, then about 5 % a round, a gap wide
 * enough that a spiky CPU doesn't make the fan hunt); NVMe/SSD, RAM, board in between. «Sofort reagieren» (quick)
 * shortens hold and gap within the kind's profile and steps faster — it never removes the gap.
 */
const FANBOY_REACTS = ['balanced', 'quick'];
// what a user may set per kind (°C): «ab», «volle Drehzahl bei», «Grenze» within these, low < high < limit
const FANBOY_KIND_BOUNDS = ['disk' => [20, 70], 'ssd' => [20, 100], 'nvme' => [20, 100], 'cpu' => [20, 110], 'ram' => [20, 100],
    'gpu' => [20, 110], 'board' => [20, 100], 'other' => [0, 120]];
const FANBOY_DOWN_PROFILES = [
    'disk'  => ['balanced' => ['gap' => 2.0, 'hold' => 0.0, 'step' => 1.0], 'quick' => ['gap' => 1.0, 'hold' => 0.0, 'step' => 3.0]],
    'ssd'   => ['balanced' => ['gap' => 2.0, 'hold' => 4.0, 'step' => 2.0], 'quick' => ['gap' => 1.0, 'hold' => 2.0, 'step' => 4.0]],
    'nvme'  => ['balanced' => ['gap' => 2.0, 'hold' => 4.0, 'step' => 2.0], 'quick' => ['gap' => 1.0, 'hold' => 2.0, 'step' => 4.0]],
    'ram'   => ['balanced' => ['gap' => 2.0, 'hold' => 4.0, 'step' => 2.0], 'quick' => ['gap' => 1.0, 'hold' => 2.0, 'step' => 4.0]],
    'board' => ['balanced' => ['gap' => 2.0, 'hold' => 4.0, 'step' => 2.0], 'quick' => ['gap' => 1.0, 'hold' => 2.0, 'step' => 4.0]],
    'cpu'   => ['balanced' => ['gap' => 2.5, 'hold' => 6.0, 'step' => 5.0], 'quick' => ['gap' => 1.5, 'hold' => 3.0, 'step' => 8.0]],
    'gpu'   => ['balanced' => ['gap' => 2.5, 'hold' => 6.0, 'step' => 5.0], 'quick' => ['gap' => 1.5, 'hold' => 3.0, 'step' => 8.0]],
    'other' => ['balanced' => ['gap' => 2.0, 'hold' => 4.0, 'step' => 2.0], 'quick' => ['gap' => 1.0, 'hold' => 2.0, 'step' => 4.0]],
];

/** The way down for a zone now: the profile of its leading source's kind, balanced or quick */
function fanboyDownProfile(string $kind, string $react): array
{
    $p = FANBOY_DOWN_PROFILES[$kind] ?? FANBOY_DOWN_PROFILES['other'];
    return $p[in_array($react, FANBOY_REACTS, true) ? $react : 'balanced'];
}
const FANBOY_DISK_KINDS = ['disk', 'ssd'];                      // sources from Unraid's disks.ini (never asked, never woken)
const FANBOY_ROLES_BIOS = ['cpu', 'pump', 'unknown'];           // stay with the BIOS: never written again (a CPU cooler only with the owner's opt-in, below)
const FANBOY_ROLES_OURS = ['case', 'disks', 'ram', 'nvme', 'other'];
/*
 * The CPU cooler on the owner's own risk (round 4): role «cpu» plus a stored, dated confirmation (`cpu_own` {at}) — only
 * then is it his. Safeguards: its zone must hold a CPU temperature (else it stays the BIOS's), a floor of its own
 * (2026-11-16, the owner: «gemäss eingestellter Kurve» — the same floor rule as every fan of his: the lowest steady value
 * measured + margin, unmeasured the blind floor; round 4's fixed 40 % is gone) whenever it turns — round 12: it
 * may stand only with its own second tick, where its curve asks for nothing (fanboyCpuStand()) — given back to the
 * mainboard's automatic only (no automatic for the output: no opt-in). The alarm, the reaction, three control deaths:
 * as for every fan of his. Pumps never.
 */
const FANBOY_CPU_STAND_GAP = 5.0;       // round 12: a CPU cooler may stop only with the smoothed CPU this far under where its curve leaves 0 …
const FANBOY_CPU_STAND_AFTER = 60.0;    // … for this long without a break (it starts again when the smoothed CPU is back there)
const FANBOY_START_ABOVE = 5;           // round 12 fix: a standing fan starts this much above its measured start from standstill
const FANBOY_CPU_SMOOTH = 10.0;         // round 12 fix (the owner's CPU: single readings 50–67 °C every half minute at 38 °C idle):
                                        // the CPU judged by the median of its readings of the last 10 s — one spike moves it not

/** A CPU cooler the owner gave Fanboy, confirmed and dated */
function fanboyCpuOwn(array $f): bool
{
    return ($f['role'] ?? null) === 'cpu' && is_array($f['cpu_own'] ?? null) && is_int($f['cpu_own']['at'] ?? null);
}

/** A fan whose role makes it his: the roles of his own, or a CPU cooler given to him on the owner's risk */
function fanboyFanOurs(array $f): bool
{
    return in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) || fanboyCpuOwn($f);
}
const FANBOY_ALARMS = ['stands', 'bios_stands', 'hot', 'sensor', 'chip'];

// ===================================================================== curves and sources (pure)

/** A zone's own scale: low/high of its first source's kind (or the zone's own) */
function fanboyZoneScale(array $zone): array
{
    $kind = $zone['sources'][0]['kind'] ?? 'disk';
    // round 4: the user's values for this kind in this zone (the table «Temperaturen») before the kind's defaults
    $d = fanboyKindValues($zone, $kind);
    $lo = is_numeric($zone['low'] ?? null) ? (float) $zone['low'] : (float) $d['low'];
    $hi = is_numeric($zone['high'] ?? null) ? (float) $zone['high'] : (float) $d['high'];
    return [$lo, $hi > $lo ? $hi : $lo + 10];
}

/**
 * A kind's «ab» (low), «volle Drehzahl bei» (high) and «Grenze» (limit) in a zone: what the user set in its table
 * (`kinds`, round 4) over the kind's defaults — only numbers in shape, each on its own
 */
function fanboyKindValues(array $zone, string $kind): array
{
    $d = FANBOY_SOURCE_DEFAULTS[$kind] ?? FANBOY_SOURCE_DEFAULTS['other'];
    $own = is_array($zone['kinds'][$kind] ?? null) ? $zone['kinds'][$kind] : [];
    foreach (['low', 'high', 'limit'] as $k) {
        if (is_int($own[$k] ?? null) || is_float($own[$k] ?? null)) {
            $d[$k] = $own[$k];
        }
    }
    return $d;
}

/** A source with its effective low/high/limit: its own, else its zone's for its kind, else the kind's defaults */
function fanboySourceValues(array $zone, array $source): array
{
    $k = fanboyKindValues($zone, $source['kind'] ?? 'other');
    foreach (['low', 'high', 'limit'] as $f) {
        if (!is_numeric($source[$f] ?? null)) {
            $source[$f] = $k[$f];
        }
    }
    return $source;
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
 * Round 13 (the owner: every kind its own curve): a kind's curve in that kind's own degrees — its own (`zone.curves.<kind>`:
 * points, or a preset on the kind's «ab»…«volle Drehzahl bei») or, without one, DERIVED from the zone's curve: the zone's
 * points moved from the zone's scale onto the kind's low…high — the same affine mapping fanboyMapped() made, so a zone
 * without `curves` asks exactly what it asked before. [points, own]
 */
function fanboyKindCurve(array $zone, string $kind): array
{
    $kv = fanboyKindValues($zone, $kind);
    [$kl, $kh] = [(float) $kv['low'], (float) $kv['high']];
    $own = is_array($zone['curves'] ?? null) ? ($zone['curves'][$kind] ?? null) : null;
    if (is_array($own)) {
        $points = $own['points'] ?? null;
        if (is_array($points) && count($points) >= 2) {
            usort($points, fn ($a, $b) => $a[0] <=> $b[0]);
            return [array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $points), true];
        }
        if (in_array($own['preset'] ?? null, FANBOY_PRESETS, true)) {
            return [fanboyPreset($own['preset'], $kl, $kh > $kl ? $kh : $kl + 10), true];
        }
    }
    [$lo, $hi] = fanboyZoneScale($zone);
    $curve = fanboyZoneCurve($zone);
    if ($kh <= $kl) {
        return [$curve, false];
    }
    return [array_map(fn ($p) => [$kl + ($p[0] - $lo) * ($kh - $kl) / ($hi - $lo), $p[1]], $curve), false];
}

/** Round 13: every kind of a zone's sources with its curve as it counts — {kind: {points, own}} (the page, the status) */
function fanboyZoneKindCurves(array $zone): array
{
    $out = [];
    foreach (is_array($zone['sources'] ?? null) ? $zone['sources'] : [] as $src) {
        $k = is_array($src) && is_string($src['kind'] ?? null) ? $src['kind'] : null;
        if ($k !== null && !isset($out[$k])) {
            [$points, $own] = fanboyKindCurve($zone, $k);
            $out[$k] = ['points' => $points, 'own' => $own];
        }
    }
    return $out;
}

/**
 * Round 13: a source's temperature on its kind's scale — a source with its own «ab»/«voll» is moved from those onto its
 * kind's (the simplest honest rule: its own values say where on its kind's curve it stands); else as it is
 */
function fanboyKindPosition(array $zone, array $source, float $t): float
{
    $sv = fanboySourceValues($zone, $source);
    $kv = fanboyKindValues($zone, $source['kind'] ?? 'other');
    [$sl, $sh, $kl, $kh] = [(float) $sv['low'], (float) $sv['high'], (float) $kv['low'], (float) $kv['high']];
    return $sh > $sl && $kh > $kl && ($sl !== $kl || $sh !== $kh) ? $kl + ($t - $sl) * ($kh - $kl) / ($sh - $sl) : $t;
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
const FANBOY_NET_KINDS = ['disk', 'ssd', 'nvme'];      // round 12 fix: what the net watches when no zone has it — disks, SSDs, NVMe
const FANBOY_NET_WARM_PCT = 60;         // round 12 fix: a source in no zone at its kind's «volle Drehzahl bei» (or Unraid's warning): every fan ≥ 60 % …
const FANBOY_NET_HOT_PCT = 100;         // … at its limit (or Unraid's critical): 100 %
const FANBOY_NET_GAP = 5.0;             // … over once every such source is this far under its warm value …
const FANBOY_NET_CLEAR = 120.0;         // … for this long

/**
 * Round 12 fix (the owner's first night: his SSD pool in no zone went from 33 to 50 °C while every fan stood): the net
 * for the heat sources he knows that none of his zones has ($c['loose'], never «other», never a sleeping disk). A source
 * at its kind's «volle Drehzahl bei» — or Unraid's own warning for that disk, whichever is lower — puts every fan he
 * steers at FANBOY_NET_WARM_PCT, at its limit (or Unraid's critical, the lower) at 100 %; over when every such source was
 * FANBOY_NET_GAP under its warm value for FANBOY_NET_CLEAR s. Said once (a warning; at the limit once more, an alert).
 * Returns the level now (0: none).
 */
function fanboyNet(array &$c, float $now): int
{
    $level = 0;
    $worst = null;
    $calm = true;
    foreach ($c['loose'] as $id => $l) {
        if (!is_float($l['c'] ?? null) && !is_int($l['c'] ?? null)) {
            continue;
        }
        $d = FANBOY_SOURCE_DEFAULTS[$l['kind']];
        $disk = $c['disks'][$id] ?? null;
        $warm = (float) min($d['high'], is_int($disk['warn'] ?? null) ? $disk['warn'] : PHP_INT_MAX);
        $limit = (float) min($d['limit'], is_int($disk['crit'] ?? null) ? $disk['crit'] : PHP_INT_MAX);
        $t = (float) $l['c'];
        $here = $t >= $limit ? FANBOY_NET_HOT_PCT : ($t >= $warm ? FANBOY_NET_WARM_PCT : 0);
        if ($t > $warm - FANBOY_NET_GAP) {
            $calm = false;
        }
        if ($here > 0 && ($worst === null || $here > $level || ($here === $level && $t - $warm > $worst['c'] - $worst['warm']))) {
            $worst = ['id' => $id, 'label' => $l['label'], 'kind' => $l['kind'], 'c' => $t, 'warm' => $warm, 'limit' => $limit];
        }
        $level = max($level, $here);
    }
    $n = $c['net'];
    if ($level > 0) {
        $first = $n === null;
        $c['net'] = ['level' => max($level, $n['level'] ?? 0), 'since' => $n['since'] ?? time(), 'source' => $worst, 'below_since' => null];
        if ($first) {
            fanboyNote($c, 'net', ['source' => $worst['label'], 'c' => (int) round($worst['c'])]);
            fanboyTell($c, 'net', 'net_warm', ['source' => $worst['label'], 'c' => (int) round($worst['c']), 'pct' => FANBOY_NET_WARM_PCT], 'warning');
        }
        if ($level >= FANBOY_NET_HOT_PCT) {
            fanboyTell($c, 'net_hot', 'net_hot', ['source' => $worst['label'], 'c' => (int) round($worst['c'])], 'alert');
        }
        return $c['net']['level'];
    }
    if ($n === null) {
        return 0;
    }
    if (!$calm) {
        $c['net']['below_since'] = null;
        return $n['level'];         // warm values gone, not yet 5 °C under: it holds
    }
    $c['net']['below_since'] ??= $now;
    if ($now - $c['net']['below_since'] >= FANBOY_NET_CLEAR) {
        fanboyNote($c, 'net_over', ['source' => $n['source']['label'] ?? '']);
        $c['net'] = null;
        fanboyUntell($c, 'net');
        return 0;
    }
    return $n['level'];
}

/** Round 12 fix: does the measured table reach this percent (between its lowest and highest step with an rpm)? */
function fanboyTableCovers(array $table, float $pct): bool
{
    $steps = array_column(array_values(array_filter($table, fn ($p) => is_array($p) && ($p[1] ?? 0) > 0)), 0);
    return $steps !== [] && $pct >= min($steps) - 0.5 && $pct <= max($steps) + 0.5;
}

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

/**
 * Every source's name for a text (notifications, the log) — exactly as the page names it (desk.js srcLabel()/labels()):
 * a disk «Disk 3» / «Parity» / its pool's name; a sensor its own label, two of the same label told apart by the pool
 * Unraid shows for that NVMe («Composite (cache)») else by a number («Composite · 2»); one without a label of its own
 * «Board sensor 3» (its kind and a running number of that kind) — never a chip's id (round 5, the VM run: «it8696_…
 * @platform/pcspkr#3 says nothing»). $lang: the notification's language.
 */
function fanboySourceNames(array $sources, ?string $lang = null): array
{
    $t = fn (string $key, array $p = []) => function_exists('officeNotifyText') ? officeNotifyText('fanboy', $key, $p, $lang ?? 'en') : strtr($key, []);
    $out = [];
    $count = [];
    $own = fn (array $x) => !in_array($x['kind'], FANBOY_DISK_KINDS, true) && ($x['label'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9_.-]+ #\d+$/D', $x['label']);
    foreach ($sources as $x) {
        if ($own($x)) {
            $count["{$x['kind']}|{$x['label']}"] = ($count["{$x['kind']}|{$x['label']}"] ?? 0) + 1;
        }
    }
    $seen = [];
    $kindN = [];
    // round 13b: the disks outside array and pools — the plugin's name, else «Nicht zugewiesen · 14,6 TB» (a number when alike)
    $size = function (int $b) use ($lang): string {
        $tb = $b / 1e12;
        $txt = $tb >= 1 ? number_format($tb, $tb >= 10 ? 1 : 2, ($lang ?? 'en') === 'en' ? '.' : ',', '') . ' TB' : number_format($b / 1e9, 0, '.', '') . ' GB';
        return $txt;
    };
    $alike = [];
    foreach ($sources as $x) {
        if (($x['group'] ?? null) === 'unassigned' && !is_string($x['name'] ?? null)) {
            $alike[$size((int) ($x['size'] ?? 0))] = ($alike[$size((int) ($x['size'] ?? 0))] ?? 0) + 1;
        }
    }
    $alikeN = [];
    foreach ($sources as $x) {
        if (($x['group'] ?? null) === 'unassigned') {
            if (is_string($x['name'] ?? null) && $x['name'] !== '') {
                $out[$x['id']] = $x['name'];
                continue;
            }
            $sz = $size((int) ($x['size'] ?? 0));
            $alikeN[$sz] = ($alikeN[$sz] ?? 0) + 1;
            $out[$x['id']] = $alike[$sz] > 1 ? $t('src.unassigned_n', ['size' => $sz, 'n' => $alikeN[$sz]]) : $t('src.unassigned', ['size' => $sz]);
            continue;
        }
        if (in_array($x['kind'], FANBOY_DISK_KINDS, true)) {
            $out[$x['id']] = preg_match('/^disk(\d+)$/D', $x['id'], $m) ? $t('src.disk', ['n' => $m[1]])
                : (preg_match('/^parity(\d*)$/D', $x['id'], $m) ? ($m[1] !== '' ? $t('src.parity_n', ['n' => $m[1]]) : $t('src.parity')) : $x['id']);
            continue;
        }
        $kind = $t(fanboyKindKey($x['kind']));
        if ($own($x)) {
            $k = "{$x['kind']}|{$x['label']}";
            $seen[$k] = ($seen[$k] ?? 0) + 1;
            $out[$x['id']] = $count[$k] < 2 ? $x['label']
                : (($x['pool'] ?? null) !== null ? $t('src.of', ['label' => $x['label'], 'pool' => $x['pool']]) : $t('src.nth', ['label' => $x['label'], 'n' => $seen[$k]]));
        } else {
            $kindN[$x['kind']] = ($kindN[$x['kind']] ?? 0) + 1;
            $out[$x['id']] = $t('src.sensor', ['kind' => $kind, 'n' => $kindN[$x['kind']]]);
        }
    }
    return $out;
}

/** A kind's word in his language files (each key written out: a test holds every key the code asks for to its file) */
function fanboyKindKey(string $kind): string
{
    return match ($kind) {
        'cpu' => 'kind.cpu', 'gpu' => 'kind.gpu', 'nvme' => 'kind.nvme', 'ram' => 'kind.ram', 'board' => 'kind.board',
        'disk' => 'kind.disk', 'ssd' => 'kind.ssd', default => 'kind.other',
    };
}

/** The Unraid disk an NVMe chip is (disks.ini's device «nvme0n1» for the chip's «…/nvme/nvme0»): its name, else null */
function fanboyNvmePool(array $chip, array $disks): ?string
{
    if (!preg_match('#/nvme/(nvme\d+)$#D', (string) ($chip['device'] ?? ''), $m)) {
        return null;
    }
    foreach ($disks as $d) {
        if (preg_match('/^' . preg_quote($m[1], '/') . 'n\d+$/D', (string) ($d['device'] ?? ''))) {
            return (string) $d['name'];
        }
    }
    return null;
}

/*
 * Round 6, part 5 (the owner, 2026-11-15): Unraid refreshes the disks' temperatures in disks.ini only every
 * poll_attributes seconds. Above FANBOY_POLL_LIVE a disk's value is a lower bound of the truth: for the zone logic (and
 * the watch, the measuring's «too warm») it counts FANBOY_POLL_ADD °C more per started FANBOY_POLL_SPAN of the interval,
 * at most FANBOY_POLL_ADD_MAX. Sleeping disks have no value (unchanged); SSD/NVMe read from hwmon are live (unchanged).
 */
const FANBOY_POLL_LIVE = 300;           // seconds: at or under this a disk value counts as it is
const FANBOY_POLL_SPAN = 600;           // seconds: … per started 10 minutes …
const FANBOY_POLL_ADD = 1;              // … 1 °C …
const FANBOY_POLL_ADD_MAX = 3;          // … at most 3 °C

/** The °C a disks.ini value counts more by Unraid's poll_attributes (null: unknown — nothing added) */
function fanboyPollAllowance(?int $poll): int
{
    if ($poll === null || $poll <= FANBOY_POLL_LIVE) {
        return 0;
    }
    return min(FANBOY_POLL_ADD_MAX, FANBOY_POLL_ADD * (int) ceil($poll / FANBOY_POLL_SPAN));
}

/** Per Unraid disk with a value now (awake): the allowance — [disk name => °C] (sleeping or no value: none) */
function fanboyDiskAllowance(?int $poll, array $disks): array
{
    $add = fanboyPollAllowance($poll);
    $out = [];
    foreach ($disks as $d) {
        if ($add > 0 && !$d['asleep'] && $d['temp'] !== null) {
            $out[$d['name']] = $add;
        }
    }
    return $out;
}

/** Every heat source the server offers now: Unraid's disks (by name) and every hwmon temperature (no drivetemp) */
function fanboySources(array $scan): array
{
    $out = [];
    foreach ($scan['disks'] ?? [] as $d) {
        // a rotating disk is «disk», an SSD/NVMe pool member «ssd»: their temperatures mean different things (round 4)
        $out[] = ['kind' => ($d['rotational'] ?? true) ? 'disk' : 'ssd', 'id' => $d['name'], 'label' => ($d['type'] ?? '') === 'unassigned' ? (string) ($d['label'] ?? '') : $d['name'],
                  'c' => $d['temp'], 'asleep' => $d['asleep']]
            // round 13b: a disk outside array and pools (devs.ini) — its own group on the page, the Unassigned Devices
            // plugin's name for it (or none: the page says «not assigned · size»), in use without a temperature
            + (($d['type'] ?? '') === 'unassigned' ? ['group' => 'unassigned', 'name' => $d['label'] ?? null, 'size' => $d['size'] ?? 0, 'in_use' => $d['in_use'] ?? false] : []);
    }
    foreach ($scan['chips'] ?? [] as $c) {
        if ($c['kind'] === 'disk') {
            continue;               // drivetemp asks the disk: never (Unraid's disks.ini says it)
        }
        foreach ($c['temps'] as $t) {
            $out[] = ['kind' => $c['kind'], 'id' => fanboySourceId($c, $t['n']), 'label' => $t['label'] !== '' ? $t['label'] : $c['name'] . " #{$t['n']}",
                      'c' => $t['c'], 'asleep' => false, 'chip' => $c['name'], 'hwmon' => $c['hwmon'], 'n' => $t['n'],
                      'pool' => $c['kind'] === 'nvme' ? fanboyNvmePool($c, $scan['disks'] ?? []) : null];
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
            if (!is_array($f) || isset($fans[$id]) || !fanboyFanOurs($f)
                || !in_array($f['found'] ?? null, ['tach', 'silent'], true) || fanboyStoredFan($f) === null) {
                continue;
            }
            $cpu = fanboyCpuOwn($f);
            if ($cpu && !in_array('cpu', array_column(array_filter(is_array($z['sources'] ?? null) ? $z['sources'] : [], 'is_array'), 'kind'), true)) {
                continue;           // a CPU cooler only with a CPU temperature in its zone — else it stays the BIOS's
            }
            $m = is_array($f['measured'] ?? null) && is_array($f['measured']['table'] ?? null) ? $f['measured'] : null;
            ['floor' => $floor, 'start' => $start, 'off_ok' => $offOk, 'off_why' => $offWhy] = fanboyFanPlan($f);
            // round 12 correction (the owner: «bis 60 Grad darf der Lüfter stehen bleiben» — his curve, not a second
            // threshold): «darf stehen» only where its curve can ask for nothing
            if ($offOk && fanboyCurveNeverZero($z, $cpu)) {
                [$offOk, $offWhy] = [false, 'curve_never_zero'];
            }
            $fans[$id] = [
                'id' => $id, 'zone' => $z['id'], 'chip' => $f['chip'], 'address' => $f['address'], 'output' => $f['output'],
                'tachs' => array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [$f['tach']], 'is_int')),
                'name' => is_string($f['name'] ?? null) ? $f['name'] : '',
                'table' => $m['table'] ?? null, 'floor' => $floor,
                // round 9 (H1): a fan that needed a kick to start at its value before starts above it; round 12: from
                // standstill as measured; round 14: a hub's margin (fanboyFanPlan())
                'start' => $start,
                'off_ok' => $offOk, 'off_why' => $offWhy, 'cpu' => $cpu,
                // «give back» (part 3): the owner's choice for a fan found on a fixed value — 'auto' gives the chip's mode
                'back' => $cpu ? 'auto' : (in_array($f['back'] ?? null, FANBOY_BACKS, true) ? $f['back'] : FANBOY_BACK_DEFAULT), 'auto' => null,
                'device' => null, 'pct' => null, 'value' => null, 'changed' => null, 'kick_until' => null, 'trim' => 0.0,
                'rpm' => null, 'zero_since' => null, 'kicks' => 0, 'gone' => false, 'foreign' => false, 'taken' => false, 'verified' => false,
                // round 12: standing on purpose — since when (wall clock), a CPU cooler's cool time and «ab» (°C)
                'standing' => false, 'stand_since' => null, 'cool_since' => null, 'stand_at' => null,
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
                'kinds' => is_array($z['kinds'] ?? null) ? $z['kinds'] : [],      // round 4: «Temperaturen» per kind
                'curves' => is_array($z['curves'] ?? null) ? $z['curves'] : [],   // round 13: a curve of its own per kind
                'by_kind' => [], 'leader' => null,
                'cur' => null, 'hold_t' => null, 'warmest' => null, 'hot_since' => null, 'level' => null, 'state' => [],
                'react' => in_array($z['react'] ?? null, FANBOY_REACTS, true) ? $z['react'] : 'balanced', 'below_since' => null,
            ];
        }
    }
    // the BIOS's fans he only watches (CPU cooler, pump with a tach): one standing is an alarm too
    $watch = [];
    foreach (is_array($settings['fans'] ?? null) ? $settings['fans'] : [] as $id => $f) {
        if (is_array($f) && !isset($fans[$id]) && in_array($f['role'] ?? null, ['cpu', 'pump'], true) && ($f['found'] ?? null) === 'tach' && fanboyStoredFan($f) !== null) {
            $watch[$id] = ['id' => $id, 'chip' => $f['chip'], 'address' => $f['address'], 'role' => $f['role'],
                'tachs' => array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [$f['tach']], 'is_int')),
                'name' => is_string($f['name'] ?? null) ? $f['name'] : '', 'zero_since' => null, 'rpm' => null];
        }
    }
    return [
        'key' => $boardKey, 'owner' => $owner, 'phase' => 'start', 'hold' => null, 'fans' => $fans, 'zones' => $zones, 'watch' => $watch,
        'chips' => [], 'sources' => [], 'alarm' => null, 'reasons' => [], 'clear_since' => null, 'told' => [], 'notes' => [], 'full_since' => [],
        'missing' => null, 'started' => null, 'last_hist' => null, 'last_keep' => null, 'hist' => [], 'look_now' => false,
        'disks_at' => null, 'disks' => [], 'scan_at' => null, 'events' => [], 'poll' => null, 'allow' => 0,
        'loose' => [], 'net' => null,         // round 12 fix: the heat sources in none of his zones, and the net for them
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
    // round 5 (forum 3.8): 65535 and the like are no speed — no reading (never «stands», never a speed)
    return array_map(fn ($n) => fanboySaneRpm(fanboyReadInt("$dir/fan{$n}_input")), $tachs);
}

/** The zones' sources now: [id => {c, asleep, label, kind}] — disks from disks.ini (every 10 s), sensors every round */
function fanboyReadSources(array &$c, float $now): void
{
    $p = fanboyPaths(fanboySysRoot() ?: null);
    if ($c['disks_at'] === null || $now - $c['disks_at'] >= 10) {
        $c['disks'] = [];
        foreach (fanboyDisks($p['disks'], fanboyDiskLimits($p)) as $d) {      // round 12 fix: with Unraid's warning per disk (the net)
            $c['disks'][$d['name']] = $d;
        }
        $c['disks_at'] = $now;
        $c['poll'] = fanboyPollAttributes($p);
        $c['allow'] = fanboyPollAllowance($c['poll']);         // round 6: an old disk value counts more (a lower bound)
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
        $byName = [];
        foreach ($chips as $chip) {
            $byName[$chip['name']][] = $chip;
            foreach ($chip['temps'] as $t) {
                $where[fanboySourceId($chip, $t['n'])] = ['path' => "{$p['hwmon']}/{$chip['hwmon']}/temp{$t['n']}_input", 'label' => $t['label'], 'disk' => $chip['kind'] === 'disk'];
            }
        }
        // the names a text gives them, as the page does (round 5: never a chip's id), in the notifications' language
        $names = fanboySourceNames(fanboySources(['chips' => $chips, 'disks' => array_values($c['disks'])]), function_exists('officeNotifyLang') ? officeNotifyLang() : 'en');
        foreach ($c['zones'] as $z) {
            foreach ($z['sources'] as $s) {
                $w = $where[$s['id']] ?? null;
                $now_id = $s['id'];
                // round 5 (the VM run): the chip's address moved — exactly one chip of that name: its sensor there (the id
                // stored stays as it is: «moved», as the outputs are found again)
                if ($w === null && preg_match('/^(.+)@.+#(\d+)$/D', $s['id'], $m) && count($byName[$m[1]] ?? []) === 1) {
                    $now_id = fanboySourceId($byName[$m[1]][0], (int) $m[2]);
                    $w = $where[$now_id] ?? null;
                }
                $c['sources'][$s['id']] = ['kind' => $s['kind'], 'path' => $w !== null && !$w['disk'] ? $w['path'] : null,
                    // a sensor gone from the chip's list keeps the name it had; one never seen: its kind's word, never its id
                    'label' => $names[$now_id] ?? $names[$s['id']] ?? $c['sources'][$s['id']]['label']
                        ?? (function_exists('officeNotifyText') ? officeNotifyText('fanboy', fanboyKindKey($s['kind']), [], function_exists('officeNotifyLang') ? officeNotifyLang() : 'en') : $s['kind'])]
                    + ($c['sources'][$s['id']] ?? []);
            }
        }
        // round 12 fix (the owner's SSD pool in no zone went from 33 to 50 °C with every fan standing): every source he knows
        // that no zone has — watched by the net (fanboyNet()); never kind «other»
        $inZones = [];
        foreach ($c['zones'] as $z) {
            foreach ($z['sources'] as $s) {
                $inZones[$s['id']] = true;
            }
        }
        $all = fanboySources(['chips' => $chips, 'disks' => array_values($c['disks'])]);
        $loose = [];
        foreach ($all as $s) {
            if (isset($inZones[$s['id']]) || !in_array($s['kind'], FANBOY_NET_KINDS, true)) {
                continue;
            }
            $w = $where[$s['id']] ?? null;
            $loose[$s['id']] = ['kind' => $s['kind'], 'label' => $names[$s['id']] ?? $s['id'],
                'path' => in_array($s['kind'], FANBOY_DISK_KINDS, true) ? null : ($w !== null && !$w['disk'] ? $w['path'] : null),
                'c' => $c['loose'][$s['id']]['c'] ?? null];
        }
        $c['loose'] = $loose;
        $c['scan_at'] = $now;
    }
    // round 12 fix: their temperatures now — hwmon every round, Unraid's disks as disks.ini says (asleep: none)
    foreach ($c['loose'] as $id => $l) {
        if (in_array($l['kind'], FANBOY_DISK_KINDS, true)) {
            $d = $c['disks'][$id] ?? null;
            $c['loose'][$id]['c'] = $d === null || $d['asleep'] || $d['temp'] === null ? null : $d['temp'] + ($c['allow'] ?? 0);
        } else {
            $c['loose'][$id]['c'] = $l['path'] === null ? null : fanboySaneTemp(fanboyReadInt($l['path']));
        }
    }
    foreach ($c['sources'] as $id => $s) {
        if (in_array($s['kind'], FANBOY_DISK_KINDS, true)) {
            $d = $c['disks'][$id] ?? null;
            $c['sources'][$id]['asleep'] = $d !== null && $d['asleep'];
            $c['sources'][$id]['raw'] = $d['temp'] ?? null;
            $c['sources'][$id]['c'] = ($d['temp'] ?? null) === null ? null : $d['temp'] + ($c['allow'] ?? 0);
            $c['sources'][$id]['there'] = $d !== null;
            $c['sources'][$id]['in_use'] = ($d['in_use'] ?? false) === true;       // round 13b: in use, no temperature
        } else {
            $raw = $s['path'] === null ? null : fanboyReadInt($s['path']);
            $c['sources'][$id]['asleep'] = false;
            $c['sources'][$id]['c'] = fanboySaneTemp($raw);
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
        if ($f['taken'] || $f['foreign'] || !empty($f['refuses']) || $f['device'] === null) {
            continue;
        }
        $t = fanboyTake(['id' => $id, 'chip' => $f['chip'], 'address' => $f['address_now'] ?? $f['address'], 'device' => $f['device'], 'output' => $f['output'], 'paired' => $f['tachs'],
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
        // round 5 (the VM run): the outputs stay on the values he wrote last — nobody can write them, no curve runs; not
        // «the BIOS has them» (that is only true when the chip was missing from the start, after a boot)
        $c['missing']['stuck'] = true;
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
    // a zone with an alarm reason at 100 % until FANBOY_CLEAR_AFTER without one (a fan standing, too warm at full, a
    // sensor silent); every other zone steers on — a watched BIOS fan standing drives nothing (told only)
    $full = fanboyFullZones($c, $now);
    // round 12 fix: a heat source in none of his zones warm — every fan he steers at least the net's level (a standing one starts)
    $net = fanboyNet($c, $now);
    if ($net > 0) {
        foreach ($c['fans'] as $id => $f) {
            if ($f['taken'] && $f['pct'] !== null && $f['pct'] < $net) {
                $c['fans'][$id]['pct'] = $net;
                $c['fans'][$id]['stand_since'] = null;
            }
        }
    }
    // round 6: «show me this fan» asked of the control for a fan it holds — 100 % for a few seconds, then its curve again
    $shown = function_exists('fanboyShowControl') ? fanboyShowControl($c, $now) : null;
    foreach ($c['fans'] as $id => $f) {
        if (!$f['taken']) {
            $c['fans'][$id]['stand_since'] = null;
            continue;
        }
        if (isset($full[$f['zone']])) {
            fanboyFullOne($c, $id);
        } elseif ($id === $shown) {
            fanboyShowFull($c, $id);
        } elseif ($f['pct'] !== null) {
            fanboyApply($c, $id, $now);
        }
    }
    // 5. read back after the start: does each measured fan still follow its table?
    if ($c['started'] !== null && $now - $c['started'] >= FANBOY_VERIFY_AFTER) {
        foreach ($c['fans'] as $id => $f) {
            // round 5 (the VM run): compared at the value written (not the zone's percentage), never while an alarm holds
            // its zone at full — a fan at full is no news. Round 12 fix (the owner's first night: three «doesn't follow»
            // for fans he held at 0 on purpose): never a fan standing on purpose (written 0), never within a start, kick or
            // stop (FANBOY_SETTLE_MAX after the last write, as the measuring waits), never at a value outside the measured
            // table (under its lowest or over its highest step there is no rpm to expect)
            if (!$f['taken'] || $f['verified'] || $f['table'] === null || $f['rpm'] === null || $f['value'] === null || $f['kick_until'] !== null
                || $f['value'] === 0 || ($f['stand_since'] ?? null) !== null
                || isset($full[$f['zone']]) || ($f['changed'] !== null && $now - $f['changed'] < FANBOY_SETTLE_MAX)
                || !fanboyTableCovers($f['table'], $f['value'] / 2.55)) {
                continue;
            }
            $c['fans'][$id]['verified'] = true;
            $want = fanboyTableRpm($f['table'], $f['value'] / 2.55);
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
    $warmestAsk = -INF;
    $kindCurves = [];
    $byKind = [];
    $hot = false;
    $states = [];
    foreach ($z['sources'] as $s) {
        $s = fanboySourceValues($z, $s);        // its own values, else the zone's for its kind, else the kind's
        $r = $c['sources'][$s['id']] ?? null;
        if ($r === null) {
            continue;
        }
        if ($r['asleep']) {
            $states[$s['id']] = 'asleep';
            continue;
        }
        if ($r['c'] === null) {
            // round 13b: a disk outside array and pools in use without a temperature (a VM's) — said so, and taken like a
            // sensor that says nothing: past its wait the zone at full (the safer way)
            $states[$s['id']] = !empty($r['in_use']) ? 'in_use' : 'quiet';
            $after = in_array($s['kind'], FANBOY_DISK_KINDS, true) ? FANBOY_DISK_SENSOR_AFTER : FANBOY_SENSOR_AFTER;
            if ($r['quiet_since'] !== null && $now - $r['quiet_since'] >= $after) {
                $reasons['sensor'] = ['source' => $r['label'], 'zone' => $z['name'], 'zid' => $zid];
                $c['full_since'][$zid] = $now;
            }
            continue;
        }
        $states[$s['id']] = 'ok';
        $m = fanboyMapped($s, (float) $r['c'], $lo, $hi, $z['sources'][0]['kind'] ?? '');
        // round 13: what it asks for on its kind's own curve; the zone follows the source that asks the most (the leader)
        $curveK = $kindCurves[$s['kind']] ??= fanboyKindCurve($z, $s['kind'])[0];
        $pos = fanboyKindPosition($z, $s, (float) $r['c']);
        $ask = fanboyCurveAt($curveK, $pos);
        $w = ['id' => $s['id'], 'label' => $r['label'], 'c' => $r['c'], 'mapped' => round($m, 1), 'kind' => $s['kind'], 'value' => round($ask, 1), 'pos' => $pos]
            + (isset($r['raw']) && $r['raw'] !== $r['c'] ? ['raw' => $r['raw']] : []);      // round 6: the disk's own value, before the allowance
        if ($warmest === null || $ask > $warmestAsk || ($ask === $warmestAsk && $m > $warmest['mapped'])) {
            $warmest = $w;
            $warmestAsk = $ask;
        }
        if (!isset($byKind[$s['kind']]) || $ask > $byKind[$s['kind']]['ask'] || ($ask === $byKind[$s['kind']]['ask'] && $r['c'] > $byKind[$s['kind']]['c'])) {
            $byKind[$s['kind']] = ['source' => $s['id'], 'label' => $r['label'], 'c' => $r['c'], 'value' => round($ask, 1), 'ask' => $ask];
        }
        if ($r['c'] >= fanboySourceLimit($s)) {
            $hot = true;
            $hotWhat = ['source' => $r['label'], 'c' => $r['c'], 'zone' => $z['name']];
        }
    }
    // nothing warm to go by (all asleep, none): the curve's start — its quiet level (the first kind's, as the zone's was)
    $raw = $warmest === null ? fanboyKindCurve($z, $z['sources'][0]['kind'] ?? 'disk')[0][0][1] : $warmestAsk;
    if ($z['unit'] === 'pct') {
        $raw = max(0.0, min(100.0, $raw));
    }
    $cur = $z['cur'];
    $t = $warmest['mapped'] ?? $lo;
    // round 13: with a curve of its own for some kind, the zone's common scale says nothing about who asks more — the way
    // down then goes by the leader itself: a leader that did not push the level up (the one that did cooled off) lets it
    // come down at once (by its kind's way), the same leader once it is its kind's gap under where it pushed. Without own
    // curves: as before (the warmest on the zone's scale against the temperature the level went up at).
    $ownCurves = !empty($z['curves']);
    $pos = $warmest['pos'] ?? null;
    $hold = function () use (&$z, $t, $warmest, $pos): void {
        $z['hold_t'] = $t;
        $z['hold_src'] = $warmest['id'] ?? null;
        $z['hold_pos'] = $pos;
    };
    if ($hot) {
        $cur = $z['unit'] === 'pct' ? 100.0 : max($raw, $cur ?? $raw);     // over a limit: full now (each fan below)
        $hold();                    // went up at this temperature: the way down starts once it is FANBOY_GAP under it again
        $z['hot_since'] ??= $now;
        if ($now - $z['hot_since'] >= FANBOY_HOT_AFTER) {
            $reasons['hot'] = ($hotWhat ?? []) + ['zid' => $zid];
            $c['full_since'][$zid] = $now;
        }
    } else {
        $z['hot_since'] = null;
        $down = fanboyDownProfile($warmest['kind'] ?? ($z['sources'][0]['kind'] ?? 'disk'), $z['react']);
        $cooler = $ownCurves
            ? (($warmest['id'] ?? null) !== ($z['hold_src'] ?? null) || $pos === null || $pos <= ($z['hold_pos'] ?? $pos) - $down['gap'])
            : $t <= ($z['hold_t'] ?? $t) - $down['gap'];
        if ($cur === null || $raw >= $cur) {
            $cur = $raw;                                            // up at once
            $hold();
            $z['below_since'] = null;
        } elseif ($cooler) {
            // down by the leading source's kind: after its hold, its step a round (rpm zones: the same share in rpm)
            $z['below_since'] ??= $now;
            if ($now - $z['below_since'] >= $down['hold']) {
                $cur = max($raw, $cur - ($z['unit'] === 'pct' ? $down['step'] : $down['step'] * FANBOY_DOWN_STEP_RPM / FANBOY_DOWN_STEP));
            }
        } else {
            $z['below_since'] = null;
        }
    }
    $z['cur'] = $cur;
    $z['warmest'] = $warmest;
    $z['state'] = $states;
    // round 13: each kind's warmest source (the one asking the most of its kind) and what it asks for; who leads now
    $z['by_kind'] = array_map(fn ($k) => array_diff_key($k, ['ask' => 1]), $byKind);
    if (is_array($z['warmest'])) {
        unset($z['warmest']['pos']);
    }
    $z['leader'] = $warmest === null ? null : ['source' => $warmest['id'], 'kind' => $warmest['kind']];
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
            // never while an alarm holds this zone at 100 % (round 5, the VM run: the trim walked down to −15 at full speed
            // and the zone ran under its curve for 1½ minutes after the alarm)
            $atFull = isset($c['full_since'][$zid]) && $now - $c['full_since'][$zid] < FANBOY_CLEAR_AFTER;
            if (!$atFull && $f['table'] !== null && $f['rpm'] !== null && $f['changed'] !== null && $now - $f['changed'] >= 6 && $cur > 0) {
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
        if ($f['off_ok'] && $f['cpu']) {
            // round 12: a CPU cooler that may stand — by the CPU itself, read every round, never by the curve's way down
            $pct = fanboyCpuStand($c, $z, $id, $now, $hot || isset($c['full_since'][$zid])) ? 0.0 : max($pct, (float) $f['floor']);
        } elseif ($pct < 0.5 && $f['off_ok']) {
            $pct = 0.0;
        } else {
            $pct = max($pct, (float) $f['floor']);
        }
        // round 12: standing on purpose since when (the page says it)
        $c['fans'][$id]['stand_since'] = $pct === 0.0 ? ($c['fans'][$id]['stand_since'] ?? time()) : null;
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
            // round 4 (the owner): the alarm acts where it is — this fan and the other fans of ITS zone to 100 %
            $reasons['stands'] = ['fan' => fanboyFanLabel($f), 'zone' => $z['name'], 'zid' => $zid, 'alone' => count($z['fans']) === 1];
            $c['full_since'][$zid] = $now;
        }
    }
}

const FANBOY_HUB_MARGIN = 10;           // round 14: several fans on one connector, not the same model — the floor and start this much higher
const FANBOY_HUB_FLOOR_MIN = 25;        // … the floor never under this
const FANBOY_HUB_EXTRA_MAX = 50;        // … and a «no, not all turn» in the look raises it by the margin again, this much at most

/**
 * A fan's plan for the control (and the hub look): its floor, its start value from standing, «darf stehen» in effect and
 * why not — round 4's floor (min + 5, a CPU cooler 40), round 9's kick start, round 12's standstill, round 14's hub margin
 */
function fanboyFanPlan(array $f): array
{
    $m = is_array($f['measured'] ?? null) && is_array($f['measured']['table'] ?? null) ? $f['measured'] : null;
    $hasTach = ($f['found'] ?? '') === 'tach';
    $cpu = fanboyCpuOwn($f);
    $floor = $m !== null && $hasTach && is_numeric($m['min_pct'] ?? null) ? min(100, (int) $m['min_pct'] + FANBOY_FLOOR_MARGIN) : FANBOY_BLIND_FLOOR;
    [$offOk, $offWhy, $start] = fanboyStandPlan($f, $m, $hasTach, $cpu, $floor);
    [$floor, $start, $hubWhy] = fanboyHubPlan($f, $m, $floor, $start);
    if ($offOk && $hubWhy !== null) {
        [$offOk, $offWhy] = [false, $hubWhy];
    }
    $start = max($start, is_int($f['kick_start']['value'] ?? null) ? min(100, (int) ceil($f['kick_start']['value'] / 2.55) + FANBOY_FLOOR_MARGIN) : 0);
    return ['floor' => $floor, 'start' => $start, 'off_ok' => $offOk, 'off_why' => $offWhy, 'hub' => $hubWhy];
}

/**
 * Round 14 (the owner: two fans on one Y-cable — what if they differ?): a connector with several fans (count > 1) not
 * said to be the same model (`alike` not true) — the table is the one that reports; the others may stall higher and
 * start later: the floor min_pct + FANBOY_HUB_MARGIN (at least FANBOY_HUB_FLOOR_MIN), plus what the look added
 * (`hub_extra`), the start value + FANBOY_HUB_MARGIN; «darf stehen» only after the look for the eye said all of them
 * start again (`hub_ok`) — never after a no (`hub_not_safe`). [floor, start, why «darf stehen» isn't in effect|null]
 */
function fanboyHubPlan(array $f, ?array $m, int $floor, int $start): array
{
    $count = is_int($f['count'] ?? null) ? $f['count'] : 1;
    if ($count <= 1 || ($f['alike'] ?? null) === true) {
        return [$floor, $start, null];
    }
    $extra = is_int($f['hub_extra'] ?? null) ? max(0, min(FANBOY_HUB_EXTRA_MAX, $f['hub_extra'])) : 0;
    $base = $m !== null && is_numeric($m['min_pct'] ?? null) && ($f['found'] ?? '') === 'tach' ? (int) $m['min_pct'] + FANBOY_HUB_MARGIN : $floor;
    $floor = min(100, max(FANBOY_HUB_FLOOR_MIN, $base) + $extra);
    $why = is_int($f['hub_not_safe']['at'] ?? null) ? 'hub_not_safe' : (is_int($f['hub_ok']['at'] ?? null) ? null : 'mixed_hub');
    return [$floor, min(100, $start + FANBOY_HUB_MARGIN), $why];
}

/**
 * Round 12: «darf stehen» and the start from standing, from the measurement's standstill part. [off_ok in effect, why
 * not (null | not_measured | measure_again: measured before round 12 | never_stops: a floor it keeps | not_safe: it
 * doesn't start again), the start value]. Standing allowed only when it stops AND starts again safely; a fan that
 * stops starts with start_from_stop, else (the kick, or not safe) with full for FANBOY_KICK, then its value.
 */
function fanboyStandPlan(array $f, ?array $m, bool $hasTach, bool $cpu, int $floor): array
{
    $start = $m !== null && is_numeric($m['start_pct'] ?? null) ? (int) $m['start_pct'] : $floor;
    if ($m !== null && ($m['stops'] ?? null) === true) {
        // round 12 fix: a little above the value its measuring confirmed (a fan that sometimes starts badly)
        $start = is_int($m['start_from_stop'] ?? null) ? max(1, min(100, $m['start_from_stop'] + FANBOY_START_ABOVE)) : 100;
    }
    $why = null;
    if (($f['off_ok'] ?? false) !== true) {
        return [false, null, $start];
    }
    if ($cpu && !is_int($f['cpu_off']['at'] ?? null)) {
        $why = 'cpu_off_confirm';           // round 12: a CPU cooler stands only with its own tick (role … cpu_off_confirm)
    } elseif ($m === null || !$hasTach) {
        $why = 'not_measured';
    } elseif (!array_key_exists('stops', $m)) {
        $why = 'measure_again';
    } elseif ($m['stops'] !== true) {
        $why = 'never_stops';
    } elseif (($m['stops_safe'] ?? null) !== true) {
        $why = 'not_safe';
    }
    return [$why === null, $why, $start];
}

/**
 * Round 12 correction: where a curve leaves 0 — the last temperature at which it asks for nothing (< 0.5) before it asks
 * for something. never_zero: it asks for something even at its lowest end (a fan on it never stands); start null and not
 * never_zero: it never asks for anything (all its points under 0.5).
 */
function fanboyCurveStart(array $curve): array
{
    if ($curve === [] || $curve[0][1] >= 0.5) {
        return ['start' => null, 'never_zero' => $curve !== []];
    }
    for ($i = 0, $n = count($curve); $i + 1 < $n; $i++) {
        if ($curve[$i][1] < 0.5 && $curve[$i + 1][1] >= 0.5) {
            return ['start' => (float) $curve[$i][0], 'never_zero' => false];
        }
    }
    return ['start' => null, 'never_zero' => false];
}

/** Round 12 correction: the temperature at which a curve first reaches its highest value (100 % as drawn) — the hard value */
function fanboyCurveFull(array $curve): ?float
{
    if ($curve === []) {
        return null;
    }
    $max = max(array_column($curve, 1));
    foreach ($curve as $p) {
        if ($p[1] >= $max - 0.5) {
            return (float) $p[0];
        }
    }
    return null;
}

/** Round 12 correction, with round 13: the CPU's curve in its own degrees — the CPU kind's own curve or the one derived */
function fanboyCpuCurve(array $z): array
{
    return fanboyKindCurve($z, 'cpu')[0];
}

/** Round 12 correction, with round 13: what a source asks for at a temperature — on its kind's curve, from where it stands on it */
function fanboySourceAsk(array $z, array $s, float $t): float
{
    return fanboyCurveAt(fanboyKindCurve($z, $s['kind'] ?? 'other')[0], fanboyKindPosition($z, $s, $t));
}

/**
 * Round 12 correction: can this zone's curve ask for nothing for this fan? A CPU cooler: its CPU curve; another fan: the
 * zone asks for nothing only when every kind of its sources does (round 13: a kind whose curve never reaches 0 asks as
 * long as a source of it is awake — never zero only when every kind's curve never reaches 0)
 */
function fanboyCurveNeverZero(array $z, bool $cpu): bool
{
    if ($cpu) {
        return fanboyCurveStart(fanboyCpuCurve($z))['never_zero'];
    }
    $kinds = array_values(array_unique(array_filter(array_map(fn ($s) => is_array($s) ? ($s['kind'] ?? null) : null, is_array($z['sources'] ?? null) ? $z['sources'] : []), 'is_string')));
    return $kinds !== [] && !array_filter($kinds, fn ($k) => !fanboyCurveStart(fanboyKindCurve($z, $k)[0])['never_zero']);
}

/**
 * Round 12 correction: a fan's curve as the control really applies it — 0 where it may stand and the curve asks for
 * nothing, else at least its floor: [[°C, %]…] (two points at one temperature: a step), on the curve's own degrees
 */
function fanboyEffectiveCurve(array $curve, float $floor, bool $offOk, ?float $standBelow = null): array
{
    if ($curve === []) {
        return [];
    }
    // where it stands: a case fan where the curve asks for nothing (< 0.5 %, the control's rule), a CPU cooler under the
    // temperature at which its curve leaves 0 ($standBelow — fanboyCpuStand() starts it there)
    $stands = fn (float $t) => $offOk && ($standBelow !== null ? $t < $standBelow : fanboyCurveAt($curve, $t) < 0.5);
    $f = fn (float $t) => $stands($t) ? 0.0 : max(fanboyCurveAt($curve, $t), $floor);
    $ts = array_column($curve, 0);
    if ($standBelow !== null) {
        $ts[] = $standBelow;
    }
    for ($i = 0; $i + 1 < count($curve); $i++) {
        [[$t0, $v0], [$t1, $v1]] = [$curve[$i], $curve[$i + 1]];
        foreach ($standBelow !== null ? [$floor] : [$floor, 0.5] as $level) {
            if ($t1 > $t0 && ($v0 - $level) * ($v1 - $level) < 0) {
                $ts[] = $t0 + ($level - $v0) * ($t1 - $t0) / ($v1 - $v0);
            }
        }
    }
    sort($ts);
    $out = [];
    foreach (array_unique(array_map(fn ($t) => round($t, 2), $ts)) as $t) {
        [$l, $r] = [round($f($t - 1e-6), 1), round($f($t + 1e-6), 1)];
        $out[] = [$t, $l];
        if ($r !== $l) {
            $out[] = [$t, $r];
        }
    }
    return $out;
}

/**
 * Round 12 correction: if he is not there — what this output goes back to (as the journal keeps it for THIS output: the
 * chip's automatic or the value found, read back) and within how many seconds at most each way it is given back (upper
 * bounds from the constants that drive it; testFanboyR12CpuGone shows each way works). The output standing at 0 now: the
 * shorter «hung» and the guard's 20-s looks apply.
 */
/** A fan's floor as the control uses it (fanboyFanPlan(): round 4's floor — a CPU cooler's the same —, round 14's hub margin) */
function fanboyFloorOf(array $f): int
{
    return (int) fanboyFanPlan($f)['floor'];
}

/**
 * The curve as the control applies it to one of its fans ($fan: the control's fan — cpu, floor, off_ok) in zone $z:
 * {kind: the degrees it is in, points} (fanboyEffectiveCurve()); an rpm zone: null
 */
function fanboyFanCurve(array $z, array $fan): ?array
{
    if (($z['unit'] ?? 'pct') !== 'pct') {
        return null;
    }
    $off = ($fan['off_ok'] ?? false) === true;
    if ($fan['cpu'] ?? false) {
        $curve = fanboyCpuCurve($z);
        return ['kind' => 'cpu', 'points' => fanboyEffectiveCurve($curve, (float) $fan['floor'], $off, fanboyCurveStart($curve)['start'])];
    }
    $kind = $z['sources'][0]['kind'] ?? 'disk';
    return ['kind' => $kind, 'points' => fanboyEffectiveCurve(fanboyKindCurve($z, $kind)[0], (float) $fan['floor'], $off)];
}

function fanboyFallback(?array $o, array $f): ?array
{
    if ($o === null) {
        return null;
    }
    $to = is_array($o['back'] ?? null) ? $o['back'] : (is_array($o['initial'] ?? null) ? $o['initial'] : null);
    $standing = ($f['value'] ?? null) === 0;
    $tick = defined('FANBOY_TICK_EVERY') ? (int) constant('FANBOY_TICK_EVERY') : 5;
    return [
        'to' => $to === null ? null : ['value' => $to['value'] ?? null, 'enable' => $to['enable'] ?? null, 'auto' => is_int($to['enable'] ?? null) && $to['enable'] >= 2],
        'within' => [
            'stopped'       => 0,                       // the control ends (stop, hand back, a hold): it gives back itself
            'control_dead'  => $tick,                   // killed hard: the agent's tick
            'control_hung'  => ($standing ? FANBOY_OWNER_STALE_STANDING : FANBOY_OWNER_STALE) + $tick,
            'agent_restart' => null,                    // the control runs on (a process of its own): nothing is given back
            'agent_stop'    => 3,                       // agent.sh stop/restart/update → the guard's release (TERM, 3 s)
            'guard_only'    => $standing ? 20 + FANBOY_OWNER_STALE_STANDING : 60 + FANBOY_OWNER_STALE,     // the agent gone too
        ],
        'check' => FANBOY_BACK_CHECK,                   // seconds after a give-back: a fan clearly slower than before → full
    ];
}

/**
 * Round 12 fix: a CPU's readings over the last FANBOY_CPU_SMOOTH s ($hist: [[time, °C]…], kept by the caller) — the
 * median (a single spike doesn't move it) and the peak. $c null: no reading this time (not added).
 */
function fanboyCpuSmooth(array &$hist, ?float $c, float $now): array
{
    if ($c !== null) {
        $hist[] = [$now, $c];
    }
    $hist = array_values(array_filter($hist, fn ($h) => $now - $h[0] < FANBOY_CPU_SMOOTH));
    $v = array_column($hist, 1);
    sort($v);
    $n = count($v);
    $median = $n === 0 ? null : ($n % 2 === 1 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    return ['smooth' => $median === null ? null : round($median, 1), 'peak' => $n ? max($v) : null, 'now' => $c];
}

/**
 * Round 12, corrected (the owner drew his CPU curve 0 % up to 62 °C: «bis 60 Grad darf der Lüfter stehen bleiben» — no
 * second threshold): does a CPU cooler of his that may stand stand this round? By its curve (fanboyCpuCurve()) and the
 * smoothed CPU (median of 10 s, fanboyCpuSmooth()): it turns when the smoothed CPU reaches where the curve leaves 0
 * (starts_at), at once on any single reading at the hard value (where the curve reaches its 100 %), when a reading is
 * missing, when another source of its zone asks for air on the curve, in an alarm or over a limit; it may stop after the
 * smoothed CPU was 5 °C under starts_at for 60 s (spikes under the hard value don't break that minute).
 */
function fanboyCpuStand(array &$c, array $z, string $id, float $now, bool $alarm): bool
{
    $cpuC = null;
    $missing = false;
    $ask = $alarm;
    foreach ($z['sources'] as $s) {
        $r = $c['sources'][$s['id']] ?? null;
        $ok = $r !== null && !$r['asleep'] && $r['c'] !== null;
        if ($s['kind'] === 'cpu') {
            $missing = $missing || !$ok;
            $cpuC = $ok ? max($cpuC ?? (float) $r['c'], (float) $r['c']) : $cpuC;
        } elseif ($ok && fanboySourceAsk($z, $s, (float) $r['c']) >= 0.5) {
            $ask = true;                // another source asks for air on the curve
        }
    }
    $curve = fanboyCpuCurve($z);
    $cs = fanboyCurveStart($curve);
    $start = $cs['start'];
    $hard = fanboyCurveFull($curve) ?? (float) fanboyKindValues($z, 'cpu')['high'];
    $f = &$c['fans'][$id];
    $f['cpu_hist'] ??= [];
    $sm = fanboyCpuSmooth($f['cpu_hist'], $missing ? null : $cpuC, $now);
    $f['stand_at'] = $start;
    $f['cpu_by'] = ['smooth' => $sm['smooth'], 'peak' => $sm['peak'], 'now' => $missing ? null : $cpuC, 'starts_at' => $start,
        'stops_below' => $start === null ? null : $start - FANBOY_CPU_STAND_GAP, 'hard' => $hard, 'window' => FANBOY_CPU_SMOOTH,
        'cool' => FANBOY_CPU_STAND_AFTER];
    if ($ask || $missing || $cpuC === null || $cs['never_zero'] || $cpuC >= $hard || $sm['smooth'] === null || ($start !== null && $sm['smooth'] >= $start)) {
        $f['cool_since'] = null;
        $f['standing'] = false;
    } elseif ($start === null || $sm['smooth'] <= $start - FANBOY_CPU_STAND_GAP) {
        $f['cool_since'] ??= $now;
        if ($now - $f['cool_since'] >= FANBOY_CPU_STAND_AFTER) {
            $f['standing'] = true;
        }
    } else {
        $f['cool_since'] = null;
    }
    return $f['standing'];
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
        $c['fans'][$id]['refused'] = 0;
    } else {
        fanboyRefused($c, $id);
    }
}

/**
 * Round 5 (forum 3.9): a write the driver refuses (EIO, EACCES — seen after 7.3.3 with nct6687, and with two drivers on
 * one chip) — before, it was tried again every round, silently, the fan left where it was. FANBOY_REFUSED_MAX in a row:
 * «the driver takes no values» — that output back to the BIOS as far as it goes, never taken again by this control,
 * one notification, a note (the report's line).
 */
function fanboyRefused(array &$c, string $id): void
{
    $f = $c['fans'][$id];
    if ($f['device'] === null || fanboyOutputFiles($f['device'], $f['output']) === null) {
        return;                     // gone, not refused: the chip's own path
    }
    $c['fans'][$id]['refused'] = ($f['refused'] ?? 0) + 1;
    if ($c['fans'][$id]['refused'] < FANBOY_REFUSED_MAX) {
        return;
    }
    fanboyLetGo($c, 'refused', [$id]);
    $c['fans'][$id]['refuses'] = true;
    $label = fanboyFanLabel($f);
    fanboyNote($c, 'refuses', ['fan' => $label]);
    fanboyTell($c, "refuses:$id", 'refuses', ['fan' => $label], 'warning');
}

/** The zones at 100 % for an alarm now: a reason in the last FANBOY_CLEAR_AFTER seconds — [zone id => true] */
function fanboyFullZones(array &$c, float $now): array
{
    $out = [];
    foreach ($c['full_since'] ?? [] as $zid => $at) {
        if ($now - $at < FANBOY_CLEAR_AFTER && isset($c['zones'][$zid])) {
            $out[$zid] = true;
        } else {
            unset($c['full_since'][$zid]);
        }
    }
    return $out;
}

/** One output to 100 % (its zone's alarm): written once, kept */
function fanboyFullOne(array &$c, string $id): void
{
    $f = $c['fans'][$id];
    if ($f['value'] !== FANBOY_FULL) {
        if (fanboySet($id, FANBOY_FULL, $c['owner']) !== null) {
            $c['fans'][$id]['value'] = FANBOY_FULL;
            $c['fans'][$id]['changed'] = null;
        } else {
            fanboyRefused($c, $id);
        }
    }
    $c['fans'][$id]['pct'] = 100;
}

/** Every output he holds to full speed (the chip going: what can still be written) */
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
        // a standing fan alone in its zone: «… stellt ihn auf 100 %» (the words without «the other fans of its zone»)
        $key = $code === 'stands' && !empty($what['alone']) ? 'alarm_stands_alone' : "alarm_$code";
        if ($c['alarm'] === null) {
            $c['alarm'] = ['code' => $code, 'params' => $what, 'since' => time(), 'mono' => $now];
            fanboyNote($c, "alarm_$code", $what);
        }
        // once per thing (round 5: a second fan standing, a second sensor silent is told too — its name in the subject,
        // so Unraid's duplicate guard of the same subject doesn't swallow it either)
        $who = (string) ($what['fan'] ?? $what['source'] ?? $what['zone'] ?? '');
        // the numbers the words say, from the code (the zone stays at full FANBOY_CLEAR_AFTER after the reason ended)
        fanboyTell($c, "alarm:$code:$who", $key, $what + ['min' => (int) round(FANBOY_CLEAR_AFTER / 60)], 'alert');
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
    $c['missing'] ??= ['since' => $now, 'why' => $why, 'at' => time(), 'stuck' => (bool) ($c['stuck_carry'] ?? false)];
    unset($c['stuck_carry']);
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
        // gone while he steered: only what is new (still gone, the driver?) — the alert at the moment said the rest
        fanboyTell($c, 'missing', !empty($c['missing']['stuck']) ? 'missing_stuck' : 'missing', ['min' => (int) round(FANBOY_TELL_AFTER / 60)], 'warning');
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
        'wait_chip' => !empty($c['missing']['stuck']) ? 'chip_gone' : 'chip_missing',
        default => 'starting',
    };
    $zones = [];
    $journal = function_exists('fanboyJournal') ? fanboyJournal()['outputs'] : [];
    foreach ($c['zones'] as $z) {
        $zones[] = ['id' => $z['id'], 'name' => $z['name'], 'unit' => $z['unit'], 'level' => $z['cur'] === null ? null : round($z['cur'], 1),
            'full' => isset($c['full_since'][$z['id']]),         // round 4: this zone at 100 % for an alarm
            'react' => $z['react'], 'down' => fanboyDownProfile($z['warmest']['kind'] ?? ($z['sources'][0]['kind'] ?? 'disk'), $z['react']),
            'warmest' => $z['warmest'], 'asleep' => count(array_filter($z['state'], fn ($s) => $s === 'asleep')),
            'in_use' => array_keys(array_filter($z['state'], fn ($s) => $s === 'in_use')),        // round 13b: in use, temperature unknown
            // round 13: every kind's curve as it counts now (own or derived), its warmest source and what it asks, who leads
            'curves' => fanboyZoneKindCurves($z), 'by_kind' => ($z['by_kind'] ?? []) ?: new stdClass(), 'leader' => $z['leader'] ?? null,
            'fans' => array_map(fn ($id) => ['id' => $id, 'name' => $c['fans'][$id]['name'], 'output' => $c['fans'][$id]['output'],
                'pct' => $c['fans'][$id]['taken'] ? $c['fans'][$id]['pct'] : null, 'rpm' => $c['fans'][$id]['rpm'] ?? null,
                'held' => $c['fans'][$id]['taken'], 'foreign' => $c['fans'][$id]['foreign'],
                // round 12: «darf stehen» chosen but not in effect — why (measure_again, never_stops, not_safe, not_measured,
                // cpu_off_confirm); in effect: may_stand, standing since when, a CPU cooler's «starts again at» °C
                'off_why' => $c['fans'][$id]['off_why'] ?? null, 'may_stand' => ($c['fans'][$id]['off_ok'] ?? false) === true,
                'stand_since' => $c['fans'][$id]['stand_since'] ?? null,
                // round 12 correction: a CPU cooler that may stand — from its curve: where it starts (°C), under what it may
                // stop, the hard single-reading value, the smoothing window (s) — and what it goes by now (cpu_by)
                'starts_at' => ($cs = ($c['fans'][$id]['cpu'] ?? false) && ($c['fans'][$id]['off_ok'] ?? false) ? fanboyCurveStart(fanboyCpuCurve($z)) : null)['start'] ?? null,
                'stops_below' => isset($cs['start']) ? $cs['start'] - FANBOY_CPU_STAND_GAP : null,
                'hard' => $cs !== null ? (fanboyCurveFull(fanboyCpuCurve($z)) ?? (float) fanboyKindValues($z, 'cpu')['high']) : null,
                'window' => $cs !== null ? FANBOY_CPU_SMOOTH : null,
                'cpu_by' => $c['fans'][$id]['cpu_by'] ?? null,
                // the curve as the control applies it to this fan (stand, floor): {kind: its degrees, points}; rpm zones: null
                'curve' => fanboyFanCurve($z, $c['fans'][$id]),
                // round 13: every kind of the zone — the fan follows the highest of these (it stands only where all of them are 0)
                'curves' => $z['unit'] !== 'pct' ? null : array_map(fn ($kc) => fanboyEffectiveCurve($kc['points'], (float) $c['fans'][$id]['floor'], ($c['fans'][$id]['off_ok'] ?? false) === true),
                    fanboyZoneKindCurves($z)),
                'fallback' => fanboyFallback($journal[$id] ?? null, $c['fans'][$id])], $z['fans'])];
    }
    return [
        'v' => 1, 'pid' => getmypid(), 'time' => time(), 'mono' => fanboyMono(), 'board' => $c['key'],
        // round 5: «nobody» — the chip went while he steered, the fans stay where he left them (neither he nor the BIOS)
        'who' => $steering ? 'fanboy' : ($exitWhy === null && $c['phase'] === 'wait_chip' && !empty($c['missing']['stuck']) ? 'nobody' : 'bios'), 'why' => $why, 'phase' => $exitWhy !== null ? 'ended' : $c['phase'],
        'zones' => $zones, 'alarm' => $c['alarm'], 'reasons' => array_keys($c['reasons']),
        'missing' => $c['missing'] === null ? null : ['since' => $c['missing']['at'], 'why' => $c['missing']['why'], 'told' => isset($c['told']['missing'])],
        'watch' => array_values(array_map(fn ($w) => ['id' => $w['id'], 'name' => $w['name'], 'role' => $w['role'], 'rpm' => $w['rpm']], $c['watch'])),
        'notes' => $c['notes'],
        // round 6: how old Unraid's disk values can be and what the zone logic adds to them (°C)
        'disk_allow' => ['poll' => $c['poll'] ?? null, 'add' => $c['allow'] ?? 0],
        'refuses' => array_keys(array_filter($c['fans'], fn ($f) => !empty($f['refuses']))),     // round 5: the driver takes no values
        // round 12 fix: the net for the sources in no zone — {level %, since, source {id, label, kind, c, warm, limit}, below_since}|null
        'net' => $c['net'] === null ? null : array_diff_key($c['net'], ['below_since' => 1]) + ['clearing' => $c['net']['below_since'] !== null],
        'loose' => array_values(array_map(fn ($id, $l) => ['id' => $id, 'kind' => $l['kind'], 'label' => $l['label'], 'c' => $l['c']], array_keys($c['loose']), $c['loose'])),
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
    // round 5: a board DMI says nothing about was kept under « | » before — read there until written under its new key
    $b = $raw['boards'][fanboyBoardKey($board)] ?? (isset($board['chip']) ? ($raw['boards'][' | '] ?? null) : null);
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
    // round 5: the chip went while the last control steered — the fans still sit where it left them, «nobody» goes on
    if (($status['why'] ?? null) === 'chip_gone') {
        $c['stuck_carry'] = true;
    }
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
