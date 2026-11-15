<?php
declare(strict_types=1);

/*
 * Fanboy's setup («Einrichten») as agent actions — the wizard's page comes later (part 3); here the steps, the state
 * they leave and what they write into his store (fanboy.php «the store»). Order: test each output UPWARD only (which
 * tach follows; none → «did something get louder?») → a role for each fan (CPU cooler, pump, «don't know»: the BIOS's
 * for good, never written again) → measure DOWNWARD only the fans given to him (table % ↔ rpm, lowest steady value,
 * start value; a stall: measuring stops, the fan goes back up) → zones (proposed, then saved) → a dry run that only says
 * what it would write → «take over» (armed: the control service starts).
 *
 * The two steps that write (test_up, measure) run as a process of their own (`php agent.php job fanboy-step`, a
 * session of its own) and hold their outputs through the write helper (fanboy-write.php) as kind «step»: whatever they
 * end by — done, «stop», the page gone (no step_alive for FANBOY_STEP_LEASE s), warm, TERM — they give every output
 * back to exactly its state before; killed hard, the agent's tick or the guard's cron line gives it back (holder gone).
 * One step at a time; never on an output the control holds.
 *
 *   RUN_DIR/fanboy/step.json   {id, kind: test_up|measure, fans: [ids], status: starting|running|done|stopped|failed,
 *                               why, pid, start, at, current, done: [ids], results: {id: …}, progress: {…}}
 *   RUN_DIR/fanboy/step.alive  the page's lease: hrtime() in ns (step_alive, every ≤ 20 s while a step runs)
 */

const FANBOY_STEP_LEASE = 45.0;         // seconds without the page's sign of life: the step stops, everything back
const FANBOY_STEP_MAX = 900.0;          // a step's whole time at most (15 minutes)
const FANBOY_UP_BASE = 2.0;             // test up: seconds of baseline
const FANBOY_UP_FOR = 8.0;              // … seconds at full speed at most
const FANBOY_UP_SETTLE = 8.0;           // … seconds at most for the fans to calm down before the next output
const FANBOY_READBACK_AFTER = 2.0;      // round 5: a write read back this long after — changed back: the BIOS keeps it
const FANBOY_MEASURE_STEPS = [90, 80, 70, 60, 50, 45, 40, 35, 30, 25, 20, 15, 10];
const FANBOY_SETTLE_MAX = 10.0;         // measure: seconds at most for a value to settle
const FANBOY_START_WAIT = 4.0;          // measure: seconds a start value gets to make a standing fan turn
const FANBOY_PLACES = ['front', 'rear', 'top', 'bottom', 'side'];
const FANBOY_COUNT_MAX = 8;             // fans on one connector at most (a hub): the page draws as many wheels
const FANBOY_ZONES_MAX = 8;
const FANBOY_SOURCES_MAX = 32;
const FANBOY_ID_RE = '/^[A-Za-z0-9_.:-]{1,80}:pwm\d{1,2}$/D';
/*
 * «Give back» for an output Fanboy found on a FIXED value someone else set (pwmN_enable 1 — on the owner's server another
 * plugin's 128): to the mainboard's automatic ('auto': the chip's own mode, only where the chip offers one) or back to
 * exactly that value ('fixed'). The owner's decision (2026-11-14): the automatic by default, the fixed value the other
 * choice in the setup; where the chip has no automatic mode the fixed value stays, and the page says so.
 */
const FANBOY_BACK_DEFAULT = 'auto';
const FANBOY_BACKS = ['auto', 'fixed'];
// the chip's own automatic mode (pwmN_enable) by driver, when the output doesn't show it right now (it87: 2 its curve;
// nct6775 and w83627ehf: 5 Smart Fan IV; f71882fg: 2) — the kernel's hwmon documentation of each driver
const FANBOY_AUTO_MODES = ['it87' => 2, 'nct6775' => 5, 'w83627ehf' => 5, 'f71882fg' => 2];
const FANBOY_COLOURS = ['auto', 'black', 'graphite', 'grey', 'white', 'slate', 'accent'];
const FANBOY_GUARD_CRON = '/boot/config/plugins/unraid-secretary-office/fanboy-guard.cron';

final class FanboyStepStop extends RuntimeException
{
}

// ===================================================================== the store, written

/**
 * Changes this board's entry of the store under a lock in RAM: $fn(entry) → entry. Other boards, unknown keys stay
 * (tolerant writer); the entry gets board and fans when it had none.
 */
function fanboyStoreUpdate(callable $fn, ?array $board = null): array
{
    $board ??= fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $key = fanboyBoardKey($board);
    @mkdir(fanboyRunDir(), 0700, true);
    $lock = @fopen(fanboyRunDir() . '/settings.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new Problem('fanboy_busy');
    }
    try {
        $raw = readJson(fanboyStoreFile()) ?? [];
        $raw['v'] ??= 1;
        $raw['boards'] = is_array($raw['boards'] ?? null) ? $raw['boards'] : [];
        $entry = is_array($raw['boards'][$key] ?? null) ? $raw['boards'][$key]
            : (isset($board['chip']) && is_array($raw['boards'][' | '] ?? null) ? $raw['boards'][' | '] : []);     // round 5: the old key « | » read, kept as it was
        $entry['board'] = $board;
        $entry['fans'] = is_array($entry['fans'] ?? null) ? $entry['fans'] : [];
        $entry = $fn($entry);
        $raw['boards'][$key] = $entry;
        @mkdir(dirname(fanboyStoreFile()), 0700, true);
        writeAtomic(fanboyStoreFile(), jsonEncode($raw), 0600);
        return $entry;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ===================================================================== the step's state in RAM

function fanboyStepFile(): string
{
    return fanboyRunDir() . '/step.json';
}

/**
 * The step as kept — one that says it runs but whose process is gone is «stopped» (interrupted: killed, the agent
 * stopped; its outputs are given back by the guard).
 */
function fanboyStepRead(): ?array
{
    $s = readJson(fanboyStepFile());
    if (!is_array($s) || !is_string($s['kind'] ?? null)) {
        return null;
    }
    if (in_array($s['status'] ?? '', ['starting', 'running'], true)) {
        $owner = ['pid' => $s['pid'] ?? 0, 'start' => $s['start'] ?? null];
        $age = time() - (int) ($s['at'] ?? 0);
        if (($s['status'] === 'running' || $age > 30) && fanboyOwnerState($owner) === 'dead') {
            $s['status'] = 'stopped';
            $s['why'] = 'interrupted';
        }
    }
    return $s;
}

function fanboyStepRunning(): bool
{
    $s = fanboyStepRead();
    return $s !== null && in_array($s['status'], ['starting', 'running'], true);
}

function fanboyStepWrite(array $s): void
{
    @mkdir(fanboyRunDir(), 0700, true);
    $s['at'] = time();
    writeAtomic(fanboyStepFile(), jsonEncode($s), 0644, 0, 0);
}

/** The page's sign of life while a step runs (step_alive, every start of a step) */
function fanboyStepAliveTouch(): void
{
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/step.alive', (string) hrtime(true), 0600, 0, 0);
}

function fanboyStepAlive(?float $now = null): bool
{
    $raw = trim((string) @file_get_contents(fanboyRunDir() . '/step.alive', false, null, 0, 32));
    return ctype_digit($raw) && ($now ?? fanboyMono()) - (int) $raw / 1e9 <= FANBOY_STEP_LEASE;
}

/** The real clock, sleep and signs for a step (the tests hand their own: a clock of theirs, fans that answer) */
function fanboyStepIo(array &$stop): array
{
    $started = fanboyMono();
    return [
        'now'   => fn (): float => fanboyMono(),
        'sleep' => function (float $s): void { usleep((int) round($s * 1e6)); fanboyBeat(); },
        'ok'    => function () use (&$stop, $started): ?string {
            return $stop['now'] ? 'stopped' : (!fanboyStepAlive() ? 'page_gone' : (fanboyMono() - $started > FANBOY_STEP_MAX ? 'too_long' : null));
        },
        'note'  => fn (array $p) => null,
    ];
}

// ===================================================================== what can be tested, measured

/** The outputs he could test now: [fan id => {id, chip, address, device, hwmon, output, tachs: [n…]}] (no graphics card's) */
function fanboyOutputs(array $scan): array
{
    $out = [];
    foreach ($scan['chips'] as $c) {
        if (!fanboyOffers($c)) {
            continue;
        }
        foreach ($c['outputs'] as $o) {
            $id = fanboyFanId($c['name'], $c['address'], $o['n']);
            $out[$id] = ['id' => $id, 'chip' => $c['name'], 'address' => $c['address'], 'device' => $c['device'], 'hwmon' => $c['hwmon'],
                'output' => $o['n'], 'tachs' => array_column($c['fans'], 'n')];
        }
    }
    return $out;
}

/** Samples a chip's tach inputs: [n => rpm|null] */
function fanboySample(array $o): array
{
    $dir = fanboyPaths(fanboySysRoot() ?: null)['hwmon'] . '/' . $o['hwmon'];
    $out = [];
    foreach ($o['tachs'] as $n) {
        $out[$n] = fanboySaneRpm(fanboyReadInt("$dir/fan{$n}_input"));
    }
    return $out;
}

/** Waits $s seconds on the step's clock, stopping when it must (stop, page gone, too long) */
function fanboyStepWait(array $io, float $s): void
{
    if (($why = ($io['ok'])()) !== null) {
        throw new FanboyStepStop($why);
    }
    ($io['sleep'])($s);
}

/**
 * Test one output UPWARD: a baseline of its chip's tach inputs, the output to full speed for up to FANBOY_UP_FOR s,
 * which inputs follow (≥ 15 % + 100 rpm over their baseline, at least 300 rpm), then exactly its state before, and the
 * fans calmed down before the next one. Result: found tach (one or more inputs — a hub), ask (none followed: «did
 * something get louder?»), unclear (it ran at full speed already — up shows nothing), busy (someone holds it).
 */
function fanboyTestUp(array $o, array $owner, array $io, string $boardKey): array
{
    $base = [];
    for ($i = 0; $i < 4; $i++) {
        foreach (fanboySample($o) as $n => $r) {
            $base[$n][] = $r ?? 0;
        }
        fanboyStepWait($io, FANBOY_UP_BASE / 4);
    }
    $base = array_map(function ($v) { sort($v); return $v[intdiv(count($v), 2)]; }, $base);
    $taken = fanboyTake($o, $owner, $boardKey);
    if ($taken === null) {
        return ['found' => 'busy', 'tachs' => []];
    }
    $initial = $taken['initial'];
    $follow = [];
    try {
        if (fanboySet($o['id'], FANBOY_FULL, $owner) === null) {
            return ['found' => 'busy', 'tachs' => []];
        }
        $t0 = ($io['now'])();
        $seen = [];
        $fell = [];
        $checked = false;
        while (($io['now'])() - $t0 < FANBOY_UP_FOR) {
            fanboyStepWait($io, 0.5);
            // round 5 (forum 3.2): about 2 s after the write, read it back — a value or mode changed back is the BIOS
            // taking the connector back: «doesn't obey», never «empty», never steered
            if (!$checked && ($io['now'])() - $t0 >= FANBOY_READBACK_AFTER) {
                $checked = true;
                $now = fanboyOutputNow($o['device'], $o['output']);
                if ($now !== null && ($now['value'] !== FANBOY_FULL || ($now['enable'] !== null && $now['enable'] !== 1))) {
                    $taken_back = true;
                    break;
                }
            }
            foreach (fanboySample($o) as $n => $r) {
                if ($r !== null && $r >= max($base[$n] * 1.15 + 100, 300)) {
                    $seen[$n] = ($seen[$n] ?? 0) + 1;
                }
                // round 5 (forum 3.11): full makes it FALL clearly — the output works the other way round
                if ($r !== null && $base[$n] >= 300 && $r <= $base[$n] * 0.6) {
                    $fell[$n] = ($fell[$n] ?? 0) + 1;
                }
            }
            if (($io['now'])() - $t0 >= 4 && $seen && min($seen) >= 2) {
                break;
            }
            if (($io['now'])() - $t0 >= 4 && !$seen && $fell && min($fell) >= 2) {
                break;
            }
        }
        $follow = array_keys(array_filter($seen, fn ($k) => $k >= 2));
        $inverted = $follow ? [] : array_keys(array_filter($fell, fn ($k) => $k >= 2));
    } finally {
        fanboyGiveBack([$o['id']], 'step', $owner);         // at once — an inverted output stopped its fan
    }
    if (!empty($taken_back)) {
        return ['found' => 'ignored', 'tachs' => [], 'base' => $base, 'why' => 'taken_back'];
    }
    // calm again (the next output's baseline must not see this one's run-down)
    $t1 = ($io['now'])();
    while (($io['now'])() - $t1 < FANBOY_UP_SETTLE) {
        $calm = true;
        foreach (fanboySample($o) as $n => $r) {
            $calm = $calm && ($r === null || $r <= $base[$n] * 1.1 + 100);
        }
        if ($calm) {
            break;
        }
        fanboyStepWait($io, 0.5);
    }
    sort($follow);
    sort($inverted);
    if ($inverted) {
        return ['found' => 'inverted', 'tachs' => $inverted, 'base' => $base];
    }
    $full = ($initial['enable'] ?? null) === 0 || (($initial['enable'] ?? 1) === 1 && ($initial['value'] ?? 0) >= 240);
    return ['found' => $follow ? 'tach' : ($full ? 'unclear' : 'ask'), 'tachs' => $follow, 'base' => $base];
}

/**
 * Round 5 (forum 3.2): after a test up — a tach of a chip that turned before and that no output of it moves is a fan
 * whose connector the BIOS keeps: an output of that chip where nothing followed is «doesn't obey» (`ignored`), not
 * «did something get louder?» (ask only when nothing turns). $fans: the store's fans; returns them with the change.
 */
function fanboyUpIgnored(array $fans, array $results): array
{
    $chipOf = fn (string $id) => (string) preg_replace('/:pwm\d+$/D', '', $id);
    $paired = [];
    $turning = [];
    foreach ($fans as $id => $f) {
        if (is_array($f) && in_array($f['found'] ?? null, ['tach', 'inverted'], true)) {
            foreach (is_array($f['tachs'] ?? null) ? $f['tachs'] : [] as $n) {
                $paired[$chipOf((string) $id)][$n] = true;
            }
        }
    }
    foreach ($results as $id => $r) {
        foreach (is_array($r['base'] ?? null) ? $r['base'] : [] as $n => $rpm) {
            if (is_int($rpm) && $rpm >= 200) {
                $turning[$chipOf((string) $id)][$n] = true;
            }
        }
    }
    foreach ($fans as $id => $f) {
        $chip = $chipOf((string) $id);
        if (is_array($f) && ($f['found'] ?? null) === 'ask' && isset($results[$id]) && array_diff_key($turning[$chip] ?? [], $paired[$chip] ?? [])) {
            $fans[$id]['found'] = 'ignored';
        }
    }
    return $fans;
}

/**
 * Too warm to go on measuring: a source at its limit less 5 °C — each by its OWN kind and limit (round 4: an NVMe pool
 * at 50 °C is ordinary; the hard disks' 52 °C held it to 47 and no fan could ever be measured): a zone's own limit for it
 * once set, else its kind's (hard disk, SSD/NVMe, CPU). Which source, its temperature and the limit — or null.
 */
function fanboyStepWarm(?array $settings = null): ?array
{
    $p = fanboyPaths(fanboySysRoot() ?: null);
    $own = [];
    foreach (is_array($settings['zones'] ?? null) ? $settings['zones'] : [] as $z) {
        foreach (is_array($z['sources'] ?? null) ? $z['sources'] : [] as $src) {
            // the source's own limit, or its zone's for its kind (the table «Temperaturen»), once set
            $k = is_string($src['kind'] ?? null) && is_numeric($z['kinds'][$src['kind']]['limit'] ?? null) ? (float) $z['kinds'][$src['kind']]['limit'] : null;
            $l = is_numeric($src['limit'] ?? null) ? (float) $src['limit'] : $k;
            if (is_string($src['id'] ?? null) && $l !== null) {
                $own[$src['id']] = min($l, $own[$src['id']] ?? INF);
            }
        }
    }
    $check = function (string $id, string $kind, string $label, ?float $c) use ($own): ?array {
        $limit = $own[$id] ?? (float) (FANBOY_SOURCE_DEFAULTS[$kind] ?? FANBOY_SOURCE_DEFAULTS['other'])['limit'];
        return $c !== null && $c >= $limit - 5 ? ['source' => $label, 'kind' => $kind, 'c' => $c, 'limit' => $limit] : null;
    };
    foreach (fanboyChips($p['hwmon']) as $chip) {
        if (in_array($chip['kind'], ['cpu', 'nvme'], true)) {
            foreach ($chip['temps'] as $t) {
                $w = $check(fanboySourceId($chip, $t['n']), $chip['kind'], $t['label'] !== '' ? $t['label'] : $chip['name'] . " #{$t['n']}", $t['c'] === null ? null : (float) $t['c']);
                if ($w !== null) {
                    return $w;
                }
            }
        }
    }
    foreach (fanboyDisks($p['disks']) as $d) {
        $w = $d['asleep'] ? null : $check($d['name'], $d['rotational'] ? 'disk' : 'ssd', $d['name'], $d['temp'] === null ? null : (float) $d['temp']);
        if ($w !== null) {
            return $w;
        }
    }
    return null;
}

/**
 * Waits for a value to settle: 3 samples a second apart within 3 % (at most FANBOY_SETTLE_MAX s). [rpm, stalled]:
 * stalled = one of the fan's inputs reads 0 (or under 100 rpm) twice in a row.
 */
function fanboySettle(array $o, array $tachs, array $io): array
{
    $last = [];
    $zero = 0;
    $t0 = ($io['now'])();
    while (($io['now'])() - $t0 < FANBOY_SETTLE_MAX) {
        fanboyStepWait($io, 1.0);
        $s = fanboySample($o);
        $r = array_map(fn ($n) => $s[$n] ?? 0, $tachs);
        $zero = min($r) < 100 ? $zero + 1 : 0;
        if ($zero >= 2) {
            return [0, true];
        }
        $last[] = (int) round(array_sum($r) / max(1, count($r)));
        $last = array_slice($last, -3);
        if (count($last) === 3 && max($last) - min($last) <= max(30, 0.03 * max($last))) {
            return [(int) round(array_sum($last) / 3), false];
        }
    }
    return [$last ? (int) end($last) : 0, false];
}

/** Round 5 (forum 3.2): a value written that reads back otherwise a few seconds later — the BIOS took the connector back */
function fanboyTakenBack(array $o, ?array $wrote): bool
{
    $now = fanboyOutputNow($o['device'], $o['output']);
    return $wrote !== null && $now !== null && ($now['enable'] !== $wrote['enable'] || $now['value'] === null || abs($now['value'] - (int) $wrote['value']) > 3);
}

/**
 * Measure a fan DOWNWARD (only one given to him, with feedback): full speed, then FANBOY_MEASURE_STEPS down; at a
 * stall measuring stops and the fan goes back up — in 5 % steps from there until it turns (its start value; none by
 * the last steady + 20 %: full speed). Then exactly its state before. Too warm, «stop», the page gone: back at once.
 */
function fanboyMeasure(array $o, array $tachs, array $owner, array $io, string $boardKey): array
{
    if (fanboyTake($o, $owner, $boardKey) === null) {
        return ['result' => 'busy'];
    }
    try {
        fanboySet($o['id'], FANBOY_FULL, $owner);
        [$max, $stall] = fanboySettle($o, $tachs, $io);
        if ($stall || $max <= 0) {
            return ['result' => 'no_turn'];
        }
        $table = [[100, $max]];
        $steady = 100;
        $stalledAt = null;
        foreach (FANBOY_MEASURE_STEPS as $pct) {
            if (($warm = fanboyStepWarm(fanboyBoardRaw())) !== null) {
                fanboySet($o['id'], FANBOY_FULL, $owner);
                return ['result' => 'warm', 'warm' => $warm, 'table' => $table];
            }
            $wrote = fanboySet($o['id'], (int) round($pct * 2.55), $owner);
            [$rpm, $stall] = fanboySettle($o, $tachs, $io);
            if (fanboyTakenBack($o, $wrote)) {
                return ['result' => 'ignored', 'table' => $table];      // round 5: the BIOS took the value back
            }
            if ($stall) {
                $stalledAt = $pct;
                break;
            }
            $table[] = [$pct, $rpm];
            $steady = $pct;
            ($io['note'] ?? fn (array $p) => null)(['table' => $table]);       // the page shows the table as it grows
        }
        $start = $steady;
        if ($stalledAt !== null) {
            // back up from the stall: the first value that makes it turn again is its start value
            $start = null;
            for ($p = $stalledAt + 5; $p <= min(100, $steady + 20); $p += 5) {
                fanboySet($o['id'], (int) round($p * 2.55), $owner);
                $t0 = ($io['now'])();
                while (($io['now'])() - $t0 < FANBOY_START_WAIT) {
                    fanboyStepWait($io, 0.5);
                    $s = fanboySample($o);
                    if (min(array_map(fn ($n) => $s[$n] ?? 0, $tachs)) >= 100) {
                        $start = $p;
                        break 2;
                    }
                }
            }
            fanboySet($o['id'], FANBOY_FULL, $owner);
            $start ??= min(100, $steady + 20);
        }
        return ['result' => 'ok', 'table' => $table, 'min_pct' => $steady, 'start_pct' => max($start, $steady), 'max_rpm' => $max,
                'stalled_at' => $stalledAt];
    } finally {
        fanboyGiveBack([$o['id']], 'step', $owner);
    }
}

/**
 * Runs a step (in its own process; the tests call it with their clock): each fan in turn, the results into the store
 * and the step's state. Whatever ends it, every output it held is given back (fanboyTestUp()/fanboyMeasure() finally).
 */
function fanboyStepRun(array $step, array $io): array
{
    $owner = fanboyMe('step');
    $board = fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    $key = fanboyBoardKey($board);
    $step = ['status' => 'running', 'pid' => $owner['pid'], 'start' => $owner['start'], 'done' => [], 'results' => [], 'why' => null] + $step;
    fanboyStepWrite($step);
    try {
        foreach ($step['fans'] as $id) {
            $scan = fanboyDetect(fanboySysRoot() ?: null);
            $o = fanboyOutputs($scan)[$id] ?? null;
            $step['current'] = $id;
            fanboyStepWrite($step);
            if ($o === null) {
                $step['results'][$id] = ['result' => 'not_there'];
                continue;
            }
            // what a step says while it works (the measure's table so far) into the step's state, for the page
            $io['note'] = function (array $p) use (&$step, $id): void {
                $step['progress'] = ['id' => $id] + $p;
                fanboyStepWrite($step);
            };
            $step['progress'] = null;
            if ($step['kind'] === 'test_up') {
                $r = fanboyTestUp($o, $owner, $io, $key);
                $step['results'][$id] = $r;
                if ($r['found'] !== 'busy') {
                    fanboyStoreUpdate(function (array $e) use ($id, $o, $r): array {
                        $f = is_array($e['fans'][$id] ?? null) ? $e['fans'][$id] : [];
                        $f = ['chip' => $o['chip'], 'address' => $o['address'], 'output' => $o['output'], 'tach' => $r['tachs'][0] ?? null,
                              'tachs' => $r['tachs'], 'found' => $r['found'], 'tested_at' => time()] + $f;
                        $e['fans'][$id] = $f;
                        // round 5: «doesn't obey» and «the other way round» are never steered — out of every zone
                        return in_array($r['found'], ['ignored', 'inverted'], true) ? fanboyZonesWithout($e, $id) : $e;
                    }, $board);
                }
            } else {
                $f = fanboyBoardRaw($board)['fans'][$id] ?? null;
                $tachs = is_array($f) ? array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')) : [];
                if (!is_array($f) || !fanboyFanOurs($f) || ($f['found'] ?? null) !== 'tach' || !$tachs) {
                    $step['results'][$id] = ['result' => 'not_his'];
                    continue;
                }
                $r = fanboyMeasure($o, $tachs, $owner, $io, $key);
                $step['results'][$id] = $r;
                if ($r['result'] === 'ignored') {
                    fanboyStoreUpdate(function (array $e) use ($id): array {
                        if (is_array($e['fans'][$id] ?? null)) {
                            $e['fans'][$id]['found'] = 'ignored';         // never steered: out of every zone
                            $e = fanboyZonesWithout($e, $id);
                        }
                        return $e;
                    }, $board);
                }
                if ($r['result'] === 'ok' || $r['result'] === 'no_turn') {
                    fanboyStoreUpdate(function (array $e) use ($id, $r): array {
                        if (is_array($e['fans'][$id] ?? null)) {
                            $e['fans'][$id]['measured'] = $r['result'] === 'ok'
                                ? ['table' => $r['table'], 'min_pct' => $r['min_pct'], 'start_pct' => $r['start_pct'], 'max_rpm' => $r['max_rpm'], 'at' => time()]
                                : null;
                            $e['fans'][$id]['no_turn'] = $r['result'] === 'no_turn';
                        }
                        return $e;
                    }, $board);
                }
                if ($r['result'] === 'warm') {
                    $step['warm'] = $r['warm'];          // which source, how warm, its limit — the page says it
                    throw new FanboyStepStop('warm');
                }
            }
            $step['done'][] = $id;
            fanboyStepWrite($step);
        }
        if ($step['kind'] === 'test_up') {
            $e = fanboyStoreUpdate(function (array $e) use ($step): array {
                $e['fans'] = fanboyUpIgnored(is_array($e['fans'] ?? null) ? $e['fans'] : [], $step['results']);
                return $e;
            }, $board);
            foreach ($step['results'] as $id => $r) {
                if (($r['found'] ?? null) === 'ask' && ($e['fans'][$id]['found'] ?? null) === 'ignored') {
                    $step['results'][$id]['found'] = 'ignored';
                }
            }
        }
        $step['status'] = 'done';
        $step['progress'] = null;
    } catch (FanboyStepStop $e) {
        $step['status'] = 'stopped';
        $step['why'] = $e->getMessage();
    } catch (Throwable $e) {
        $step['status'] = 'failed';
        $step['why'] = 'error';
        logLine('Fanboy: the step failed: ' . $e->getMessage());
    } finally {
        $back = fanboyGiveBack(null, 'step', $owner);       // nothing of this step held any more, whatever ended it
        $step['current'] = null;
        fanboyStepWrite($step);
        logLine("Fanboy: setup step {$step['kind']} {$step['status']}" . ($step['why'] ? " ({$step['why']})" : '') . ' — ' . count($step['done']) . ' of ' . count($step['fans'])
            . ' outputs' . ($back ? ', ' . count($back) . ' given back at the end' : ''));
    }
    return $step;
}

/** The step process: `php agent.php job fanboy-step` — the step RUN_DIR/fanboy/step.json describes, once */
function fanboyStepMain(): int
{
    $lock = @fopen(fanboyRunDir() . '/step.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $step = readJson(fanboyStepFile());
    if (!is_array($step) || ($step['status'] ?? '') !== 'starting' || !in_array($step['kind'] ?? '', ['test_up', 'measure'], true) || !is_array($step['fans'] ?? null)) {
        return 0;
    }
    $stop = ['now' => false];
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
        pcntl_signal($sig, function () use (&$stop) { $stop['now'] = true; });
    }
    fanboyBeat();
    fanboyStepRun($step, fanboyStepIo($stop));
    @unlink(fanboyRunDir() . '/beat-' . getmypid());
    flock($lock, LOCK_UN);
    fclose($lock);
    return 0;
}

// ===================================================================== the actions

/** A step started: checked, written as «starting», its process launched */
function fanboyStepStart(string $kind, array $ids): array
{
    if (fanboyStepRunning()) {
        throw new Problem('fanboy_step_running');
    }
    if (fanboyControlPid() !== null) {
        throw new Problem('fanboy_steering');            // hand back first: never a step beside the control
    }
    $scan = fanboyDetect();
    if ($scan['situation']['code'] === 'other') {
        throw new Problem('fanboy_other', ['who' => implode(', ', $scan['situation']['names'] ?? [])]);
    }
    $outputs = fanboyOutputs($scan);
    $settings = fanboyBoardRaw($scan['board']) ?? [];
    foreach ($ids as $id) {
        if (!isset($outputs[$id])) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        $f = $settings['fans'][$id] ?? null;
        if ($kind === 'measure' && (!is_array($f) || !fanboyFanOurs($f) || ($f['found'] ?? null) !== 'tach')) {
            throw new Problem('fanboy_not_his', ['fan' => $id]);        // CPU cooler (not given to him), pump, «don't know», no feedback: never down
        }
        if ($kind === 'test_up' && is_array($f) && in_array($f['role'] ?? null, FANBOY_ROLES_BIOS, true) && ($f['role'] ?? null) !== 'unknown' && !fanboyCpuOwn($f)) {
            throw new Problem('fanboy_not_his', ['fan' => $id]);        // a known CPU cooler or pump: no more tests
        }
    }
    $step = ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'fans' => array_values($ids), 'status' => 'starting', 'pid' => null, 'start' => null,
             'done' => [], 'results' => [], 'why' => null, 'begun' => time()];
    fanboyStepAliveTouch();
    fanboyStepWrite($step);
    fanboyLaunch('fanboy-step');
    return ['ok' => true, 'step' => $step];
}

/** «Stop»: the step's process asked to end (it gives everything back itself; the guard does when it can't) */
function fanboyStepStopAction(): array
{
    $s = fanboyStepRead();
    if ($s !== null && in_array($s['status'], ['starting', 'running'], true) && is_int($s['pid'] ?? null)
        && fanboyOwnerState(['pid' => $s['pid'], 'start' => $s['start'] ?? null]) !== 'dead') {
        @posix_kill($s['pid'], SIGTERM);
    }
    return ['ok' => true, 'step' => fanboyStepRead()];
}

/** «Did something get louder?» — yes: a fan without feedback; no: the connector is empty (it stays the BIOS's, hidden) */
function fanboyAnswer(array $r): array
{
    $id = textField($r, 'fan');
    $louder = boolField($r, 'louder');
    $e = fanboyStoreUpdate(function (array $e) use ($id, $louder): array {
        if (!is_array($e['fans'][$id] ?? null) || !in_array($e['fans'][$id]['found'] ?? null, ['ask', 'silent', 'empty', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        $e['fans'][$id]['found'] = $louder ? 'silent' : 'empty';
        if (!$louder) {
            $e = fanboyZonesWithout($e, $id);
        }
        return $e;
    });
    return ['ok' => true, 'fan' => $e['fans'][$id]];
}

/**
 * A role (and name, place, «off allowed»): CPU cooler, pump, «don't know» stay with the BIOS — out of every zone. Round 4:
 * the CPU cooler «Fanboy regelt ihn» only with `cpu_own` AND the ticked `cpu_confirm`, and only where the output has a
 * mainboard automatic to give it back to; stored with its date (kept while it stays so). `cpu_own` not sent: as it was.
 */
function fanboyRole(array $r): array
{
    $id = textField($r, 'fan');
    $role = textField($r, 'role');
    if (!in_array($role, array_merge(FANBOY_ROLES_BIOS, FANBOY_ROLES_OURS), true)) {
        throw new Problem('fanboy_bad_role');
    }
    $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', optText($r, 'name')));
    if (mb_strlen($name) > 40) {
        throw new Problem('fanboy_bad_name');
    }
    $place = optText($r, 'place');
    if ($place !== '' && !in_array($place, FANBOY_PLACES, true)) {
        throw new Problem('fanboy_bad_place');
    }
    $offOk = optBool($r, 'off_ok', false);
    // round 3: how many fans hang on this one connector (a Y-cable, a hub) — 1 … 8; not sent: as it was
    $count = optInt($r, 'count', null, 1);
    if ($count !== null && $count > FANBOY_COUNT_MAX) {
        throw new Problem('fanboy_bad_count');
    }
    $cpuOwn = array_key_exists('cpu_own', $r) ? optBool($r, 'cpu_own') : null;
    if ($cpuOwn === true && $role !== 'cpu') {
        throw new Problem('fanboy_bad_role');
    }
    if ($cpuOwn === true && !optBool($r, 'cpu_confirm')) {
        throw new Problem('fanboy_cpu_confirm');            // the owner ticks that he knows what it means
    }
    $auto = null;
    if ($cpuOwn === true) {
        $scan = fanboyDetect();
        $auto = fanboyBefore($scan)[$id]['auto'] ?? null;
        if ($auto === null) {
            throw new Problem('fanboy_cpu_no_auto');        // nothing to give it back to but a fixed value: it stays the BIOS's
        }
    }
    $e = fanboyStoreUpdate(function (array $e) use ($id, $role, $name, $place, $offOk, $count, $cpuOwn): array {
        $f = $e['fans'][$id] ?? null;
        if (!is_array($f) || !in_array($f['found'] ?? null, ['tach', 'silent', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        if ($role !== 'cpu' || $cpuOwn === false) {
            unset($f['cpu_own']);
        } elseif ($cpuOwn === true && !fanboyCpuOwn($f)) {
            $f['cpu_own'] = ['at' => time()];
        }
        if ($offOk && !is_array($f['measured'] ?? null)) {
            throw new Problem('fanboy_off_needs_measure');        // «off» only for a fan measured with feedback
        }
        $f['role'] = $role;
        $f['name'] = $name;
        $f['place'] = $place !== '' ? $place : null;
        $f['off_ok'] = $offOk && in_array($role, FANBOY_ROLES_OURS, true);
        if ($count !== null) {
            $f['count'] = $count;
        }
        $e['fans'][$id] = $f;
        return in_array($role, FANBOY_ROLES_BIOS, true) && !fanboyCpuOwn($f) ? fanboyZonesWithout($e, $id) : $e;
    });
    return ['ok' => true, 'fan' => $e['fans'][$id]];
}

function fanboyZonesWithout(array $e, string $id): array
{
    foreach (is_array($e['zones'] ?? null) ? $e['zones'] : [] as $i => $z) {
        if (is_array($z) && is_array($z['fans'] ?? null)) {
            $e['zones'][$i]['fans'] = array_values(array_filter($z['fans'], fn ($x) => $x !== $id));
        }
    }
    return $e;
}

/**
 * Zones proposed from the roles and places: the fans for the disks → «disks» (every Unraid disk), the rest → «case»
 * (NVMe, RAM, the board's sensors). Not stored: the page shows it, the user changes it, zones_save keeps it.
 */
function fanboyZonesPropose(array $scan, array $settings): array
{
    $fans = array_filter(is_array($settings['fans'] ?? null) ? $settings['fans'] : [],
        fn ($f) => is_array($f) && fanboyFanOurs($f) && in_array($f['found'] ?? null, ['tach', 'silent'], true));
    $disks = array_keys(array_filter($fans, fn ($f) => $f['role'] === 'disks'));
    $cpu = array_keys(array_filter($fans, fn ($f) => $f['role'] === 'cpu'));
    $case = array_keys(array_filter($fans, fn ($f) => !in_array($f['role'], ['disks', 'cpu'], true)));
    $sources = fanboySources($scan);
    $zones = [];
    if ($disks) {
        $zones[] = ['id' => 'disks', 'kind' => 'disks', 'name' => '', 'fans' => $disks, 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => array_values(array_map(fn ($s) => ['kind' => 'disk', 'id' => $s['id']], array_filter($sources, fn ($s) => $s['kind'] === 'disk')))];     // hard disks only: an SSD/NVMe pool is warm at rest
    }
    if ($case) {
        $kinds = $disks ? ['ssd', 'nvme', 'ram', 'board'] : ['disk', 'ssd', 'nvme', 'ram', 'board'];
        $zones[] = ['id' => 'case', 'kind' => 'case', 'name' => '', 'fans' => $case, 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => array_values(array_map(fn ($s) => ['kind' => $s['kind'], 'id' => $s['id']], array_filter($sources, fn ($s) => in_array($s['kind'], $kinds, true))))];
    }
    // round 4: a CPU cooler given to him — a zone of its own on the CPU's temperatures (none: not proposed, it stays the BIOS's)
    $cpuSources = array_values(array_map(fn ($s) => ['kind' => 'cpu', 'id' => $s['id']], array_filter($sources, fn ($s) => $s['kind'] === 'cpu')));
    if ($cpu && $cpuSources) {
        $zones[] = ['id' => 'cpu', 'kind' => 'cpu', 'name' => '', 'fans' => $cpu, 'unit' => 'pct', 'target' => ['preset' => 'normal'], 'sources' => $cpuSources];
    }
    return $zones;
}

/** A number of a zone from the request: int or float in [$min, $max], else null when absent — a wrong type refused */
function fanboyNum(array $a, string $k, float $min, float $max): ?float
{
    if (!array_key_exists($k, $a) || $a[$k] === null) {
        return null;
    }
    if ((!is_int($a[$k]) && !is_float($a[$k])) || $a[$k] < $min || $a[$k] > $max) {
        throw new Problem('fanboy_bad_zones', ['field' => $k]);
    }
    return (float) $a[$k];
}

/**
 * The zones the page sends, checked against what is there now: ids, names, fans (his, each in one zone), sources (each
 * a source the server shows now), limits, the target (a preset or 2–8 points), the unit (rpm only when every fan is
 * measured with feedback). Anything in another shape: refused, nothing kept.
 */
function fanboyZonesCheck(mixed $zones, array $scan, array $settings): array
{
    if (!is_array($zones) || !array_is_list($zones) || count($zones) > FANBOY_ZONES_MAX) {
        throw new Problem('fanboy_bad_zones', ['field' => 'zones']);
    }
    $known = array_column(fanboySources($scan), null, 'id');
    $used = [];
    $out = [];
    foreach ($zones as $z) {
        if (!is_array($z) || !is_string($z['id'] ?? null) || !preg_match('/^[a-z0-9_-]{1,24}$/D', $z['id']) || isset($out[$z['id']])
            || !is_string($z['name'] ?? null) || !is_array($z['fans'] ?? null) || !array_is_list($z['fans']) || !is_array($z['sources'] ?? null) || !array_is_list($z['sources'])) {
            throw new Problem('fanboy_bad_zones', ['field' => 'zone']);
        }
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $z['name']));
        if ($name === '' || mb_strlen($name) > 40) {
            throw new Problem('fanboy_bad_zones', ['field' => 'name']);
        }
        $unit = $z['unit'] ?? 'pct';
        if (!in_array($unit, ['pct', 'rpm'], true)) {
            throw new Problem('fanboy_bad_zones', ['field' => 'unit']);
        }
        foreach ($z['fans'] as $id) {
            $f = is_string($id) ? ($settings['fans'][$id] ?? null) : null;
            if (!is_array($f) || !fanboyFanOurs($f) || !in_array($f['found'] ?? null, ['tach', 'silent'], true) || isset($used[$id])) {
                throw new Problem('fanboy_bad_zones', ['field' => 'fans']);
            }
            if ($unit === 'rpm' && (($f['found'] ?? null) !== 'tach' || !is_array($f['measured'] ?? null))) {
                throw new Problem('fanboy_rpm_needs_measure');
            }
            $used[$id] = true;
        }
        if (!$z['fans'] || !$z['sources'] || count($z['sources']) > FANBOY_SOURCES_MAX) {
            throw new Problem('fanboy_bad_zones', ['field' => 'sources']);
        }
        $sources = [];
        foreach ($z['sources'] as $s) {
            if (!is_array($s) || !is_string($s['id'] ?? null) || !isset($known[$s['id']]) || isset($sources[$s['id']])) {
                throw new Problem('fanboy_bad_zones', ['field' => 'source']);
            }
            $low = fanboyNum($s, 'low', 0, 110);
            $high = fanboyNum($s, 'high', 0, 120);
            $limit = fanboyNum($s, 'limit', 20, 120);
            if ($low !== null && $high !== null && $high <= $low) {
                throw new Problem('fanboy_bad_zones', ['field' => 'high']);
            }
            $sources[$s['id']] = ['kind' => $known[$s['id']]['kind'], 'id' => $s['id'], 'low' => $low, 'high' => $high, 'limit' => $limit];
        }
        // round 4: a CPU cooler given to him only in a zone with a CPU temperature
        if (!in_array('cpu', array_column($sources, 'kind'), true)
            && array_filter($z['fans'], fn ($id) => fanboyCpuOwn($settings['fans'][$id]))) {
            throw new Problem('fanboy_cpu_zone_needs_cpu');
        }
        // round 4: «Temperaturen» per kind — {kind: {low?, high?, limit?}}, only kinds the zone has, numbers within the
        // kind's bounds, and with each source's own values: low < high < limit (the defaults filling the gaps)
        $kinds = [];
        $raw = $z['kinds'] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new Problem('fanboy_bad_zones', ['field' => 'kinds']);
        }
        foreach ($raw as $kind => $v) {
            if (!is_string($kind) || !isset(FANBOY_KIND_BOUNDS[$kind]) || !is_array($v) || !in_array($kind, array_column($sources, 'kind'), true)) {
                throw new Problem('fanboy_bad_zones', ['field' => 'kinds']);
            }
            [$lo, $hi] = FANBOY_KIND_BOUNDS[$kind];
            $kinds[$kind] = array_filter(['low' => fanboyNum($v, 'low', $lo, $hi), 'high' => fanboyNum($v, 'high', $lo, $hi), 'limit' => fanboyNum($v, 'limit', $lo, $hi)],
                fn ($x) => $x !== null);
        }
        foreach ($sources as $src) {
            $e = fanboySourceValues(['kinds' => $kinds], $src);
            if (!($e['low'] < $e['high'] && $e['high'] < $e['limit'])) {
                throw new Problem('fanboy_bad_temps', ['source' => $src['id'], 'low' => $e['low'], 'high' => $e['high'], 'limit' => $e['limit']]);
            }
        }
        $t = $z['target'] ?? ['preset' => 'normal'];
        if (!is_array($t)) {
            throw new Problem('fanboy_bad_zones', ['field' => 'target']);
        }
        if (isset($t['points'])) {
            if (!is_array($t['points']) || !array_is_list($t['points']) || count($t['points']) < 2 || count($t['points']) > 8) {
                throw new Problem('fanboy_bad_zones', ['field' => 'points']);
            }
            $pts = [];
            foreach ($t['points'] as $p) {
                if (!is_array($p) || !array_is_list($p) || count($p) !== 2) {
                    throw new Problem('fanboy_bad_zones', ['field' => 'points']);
                }
                $tc = fanboyNum(['t' => $p[0]], 't', 0, 120);
                $v = fanboyNum(['v' => $p[1]], 'v', 0, $unit === 'pct' ? 100 : 10000);
                $pts[] = [$tc, $v];
            }
            $target = ['points' => $pts];
        } else {
            if (!in_array($t['preset'] ?? null, FANBOY_PRESETS, true) || $unit === 'rpm') {
                throw new Problem('fanboy_bad_zones', ['field' => 'preset']);
            }
            $target = ['preset' => $t['preset']];
        }
        // round 4: how he reacts — «balanced» (the default) or «quick»; read strictly, absent = balanced
        $react = $z['react'] ?? 'balanced';
        if (!in_array($react, FANBOY_REACTS, true)) {
            throw new Problem('fanboy_bad_zones', ['field' => 'react']);
        }
        $out[$z['id']] = ['id' => $z['id'], 'name' => $name, 'fans' => $z['fans'], 'sources' => array_values($sources),
            'target' => $target, 'unit' => $unit, 'low' => fanboyNum($z, 'low', 0, 110), 'high' => fanboyNum($z, 'high', 0, 120), 'react' => $react,
            'kinds' => $kinds ?: new stdClass()];
    }
    return array_values($out);
}

function fanboyZonesSave(array $r): array
{
    $scan = fanboyDetect();
    $settings = fanboyBoardRaw($scan['board']) ?? [];
    $zones = fanboyZonesCheck($r['zones'] ?? null, $scan, $settings);
    $e = fanboyStoreUpdate(function (array $e) use ($zones): array {
        $e['zones'] = $zones;
        return $e;
    }, $scan['board']);
    return ['ok' => true, 'zones' => $e['zones']];
}

/**
 * The dry run: what the control WOULD write now — each zone's warmest source, its level, each fan's percentage and
 * value beside what the output has now. Nothing taken, nothing written.
 */
function fanboyDryRun(): array
{
    $scan = fanboyDetect();
    $settings = fanboyBoardRaw($scan['board']) ?? [];
    $c = fanboyControlNew($settings, fanboyBoardKey($scan['board']), ['kind' => 'dry', 'pid' => 0, 'start' => null]);
    if (!$c['zones']) {
        throw new Problem('fanboy_no_zones');
    }
    [$ok, $why] = fanboyLocate($c, $scan);
    $now = fanboyMono();
    fanboyReadSources($c, $now);
    $out = [];
    foreach ($c['zones'] as $zid => $z) {
        foreach ($z['fans'] as $id) {
            $c['fans'][$id]['taken'] = $ok;          // only in this copy: so the zone's round computes each fan
            $c['fans'][$id]['rpms'] = fanboyTachs($c['fans'][$id]['hwmon'] ?? null, $c['fans'][$id]['tachs']);
        }
        $reasons = [];
        fanboyZoneRound($c, $zid, $now, $reasons);
        $z = $c['zones'][$zid];
        $out[] = ['id' => $zid, 'name' => $z['name'], 'unit' => $z['unit'], 'level' => $z['cur'] === null ? null : round($z['cur'], 1), 'warmest' => $z['warmest'],
            'react' => $z['react'], 'down' => fanboyDownProfile($z['warmest']['kind'] ?? ($z['sources'][0]['kind'] ?? 'disk'), $z['react']),
            'asleep' => count(array_filter($z['state'], fn ($s) => $s === 'asleep')), 'quiet' => count(array_filter($z['state'], fn ($s) => $s === 'quiet')),
            'fans' => array_map(function ($id) use ($c) {
                $f = $c['fans'][$id];
                $now = $f['device'] !== null ? fanboyOutputNow($f['device'], $f['output']) : null;
                return ['id' => $id, 'name' => $f['name'], 'output' => $f['output'], 'pct' => $f['pct'], 'value' => $f['pct'] === null ? null : (int) round($f['pct'] * 2.55),
                    'now' => $now, 'floor' => $f['floor']];
            }, $z['fans'])];
    }
    return ['ok' => true, 'dry' => ['found' => $ok, 'why' => $why, 'zones' => $out, 'time' => time()]];
}

/** «Take over»: armed — the control starts now (zones there, no other controller, no step running) */
function fanboyTakeOver(): array
{
    $scan = fanboyDetect();
    if ($scan['situation']['code'] === 'other') {
        throw new Problem('fanboy_other', ['who' => implode(', ', $scan['situation']['names'] ?? [])]);
    }
    if (fanboyStepRunning()) {
        throw new Problem('fanboy_step_running');
    }
    $settings = fanboyBoardRaw($scan['board']) ?? [];
    if (!fanboyControlNew($settings, '', ['pid' => 0])['zones']) {
        throw new Problem('fanboy_no_zones');
    }
    fanboyStoreUpdate(function (array $e): array {
        $e['armed'] = true;
        $e['armed_at'] = time();
        return $e;
    }, $scan['board']);
    fanboyGuardCron(true);
    $GLOBALS['fanboyCrashes'] = [];
    fanboyLaunch('fanboy-control');
    logLine('Fanboy: taken over on the user\'s click');
    return ['ok' => true];
}

/** «Hand back»: not armed — the control ends and gives every output back (read back), at once */
function fanboyHandBack(): array
{
    fanboyStoreUpdate(function (array $e): array {
        $e['armed'] = false;
        return $e;
    });
    $back = fanboyControlStop('hand_back');
    fanboyGuardCron(false);
    logLine('Fanboy: handed back to the BIOS on the user\'s click (' . count($back) . ' outputs)');
    return ['ok' => true, 'given' => count($back)];
}

// ===================================================================== part 3: give back, a fan's look, the guard's line

/**
 * The chip's own automatic mode for an output — only where the chip offers one (part 1's `auto`): the mode it shows now
 * (≥ 2), the one kept from before Fanboy on this board, else its driver's (FANBOY_AUTO_MODES). null: none known.
 */
function fanboyAutoMode(array $chip, int $output, ?array $kept = null): ?int
{
    $o = array_values(array_filter($chip['outputs'] ?? [], fn ($x) => ($x['n'] ?? null) === $output))[0] ?? null;
    if ($o === null || ($o['auto'] ?? false) !== true) {
        return null;
    }
    if (is_int($o['enable'] ?? null) && $o['enable'] >= 2 && $o['enable'] <= 9) {
        return $o['enable'];
    }
    if (is_int($kept['enable'] ?? null) && $kept['enable'] >= 2 && $kept['enable'] <= 9) {
        return $kept['enable'];
    }
    return FANBOY_AUTO_MODES[$chip['module'] ?? ''] ?? null;
}

/**
 * What each output had before Fanboy, for the page: from this boot's journal (taken down at his first write), else as it
 * is now when nobody of his holds it, else the copy kept in the data folder — {value, pct, enable, mode, fixed, auto, from}
 * (fixed: a value someone set by hand, enable 1; auto: the chip's own mode number to give back to, null when it has none)
 */
function fanboyBefore(array $scan, ?array $journal = null): array
{
    $journal ??= fanboyJournal();
    $kept = fanboyInitialRead()['boards'][fanboyBoardKey($scan['board'])] ?? [];
    $out = [];
    foreach ($scan['chips'] as $c) {
        if (!fanboyOffers($c)) {
            continue;
        }
        foreach ($c['outputs'] as $o) {
            $id = fanboyFanId($c['name'], $c['address'], $o['n']);
            $j = $journal['outputs'][$id] ?? null;
            $k = is_array($kept[$id] ?? null) ? $kept[$id] : null;
            [$from, $st] = is_array($j['initial'] ?? null) ? ['journal', $j['initial']]
                : (!is_array($j['owner'] ?? null) ? ['now', ['value' => $o['value'], 'enable' => $o['enable']]] : ['kept', $k ?? []]);
            $v = is_int($st['value'] ?? null) ? $st['value'] : null;
            $e = is_int($st['enable'] ?? null) ? $st['enable'] : null;
            $out[$id] = ['value' => $v, 'pct' => $v === null ? null : (int) round(max(0, min(255, $v)) / 2.55), 'enable' => $e,
                'mode' => $e === null ? null : ($e === 0 ? 'full' : ($e === 1 ? 'manual' : 'auto')), 'fixed' => $e === 1,
                'auto' => fanboyAutoMode($c, $o['n'], $k), 'from' => $from];
        }
    }
    return $out;
}

/**
 * A fan's «give back» as it counts: the owner's choice, else FANBOY_BACK_DEFAULT; 'auto' where the chip has no automatic
 * mode for the output: the fixed value (said on the page)
 */
function fanboyBackOf(array $fan, array $before): string
{
    $b = in_array($fan['back'] ?? null, FANBOY_BACKS, true) ? $fan['back'] : FANBOY_BACK_DEFAULT;
    return $b === 'auto' && ($before['auto'] ?? null) === null ? 'fixed' : $b;
}

/** «Give back to»: the mainboard's automatic or this fixed value — for a fan found on a fixed value */
function fanboyBack(array $r): array
{
    $id = textField($r, 'fan');
    $back = textField($r, 'back');
    if (!in_array($back, FANBOY_BACKS, true)) {
        throw new Problem('fanboy_bad_back');
    }
    $scan = fanboyDetect();
    $before = fanboyBefore($scan)[$id] ?? null;
    if ($before === null) {
        throw new Problem('fanboy_no_output', ['fan' => $id]);
    }
    if ($back === 'auto' && $before['auto'] === null) {
        throw new Problem('fanboy_no_auto');            // the chip has no automatic mode for this output
    }
    $e = fanboyStoreUpdate(function (array $e) use ($id, $back): array {
        if (!is_array($e['fans'][$id] ?? null)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        $e['fans'][$id]['back'] = $back;
        return $e;
    }, $scan['board']);
    return ['ok' => true, 'fan' => $e['fans'][$id]];
}

/**
 * A fan's look for the pictures (no steering depends on it): its model from the catalogue (an id) or «another fan» by
 * size and blades, its wheel's colour. What Fanboy measured always counts before the catalogue.
 */
function fanboyFanLook(array $r): array
{
    $id = textField($r, 'fan');
    $model = optText($r, 'model');
    if ($model !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $model)) {
        throw new Problem('fanboy_bad_look', ['field' => 'model']);
    }
    $mm = optInt($r, 'mm', 0);
    $blades = optInt($r, 'blades', 0);
    if (($mm !== 0 && ($mm < 40 || $mm > 200)) || ($blades !== 0 && ($blades < 3 || $blades > 15))) {
        throw new Problem('fanboy_bad_look', ['field' => 'size']);
    }
    $colour = optText($r, 'colour');
    if ($colour !== '' && !in_array($colour, FANBOY_COLOURS, true)) {
        throw new Problem('fanboy_bad_look', ['field' => 'colour']);
    }
    $e = fanboyStoreUpdate(function (array $e) use ($id, $model, $mm, $blades, $colour, $r): array {
        $f = $e['fans'][$id] ?? null;
        if (!is_array($f) || !in_array($f['found'] ?? null, ['tach', 'silent', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        if (array_key_exists('model', $r) || array_key_exists('mm', $r) || array_key_exists('blades', $r)) {
            $f['model'] = ['id' => $model !== '' ? $model : null, 'mm' => $mm ?: null, 'blades' => $blades ?: null];
        }
        if ($colour !== '') {
            $f['colour'] = $colour;
        }
        $e['fans'][$id] = $f;
        return $e;
    });
    return ['ok' => true, 'fan' => $e['fans'][$id]];
}

/** The guard's cron line's file: on the flash (Unraid adds every plugin's *.cron to root's crontab) — the tests' tree in a test */
function fanboyGuardCronFile(): string
{
    return fanboySysRoot() . FANBOY_GUARD_CRON;
}

/**
 * The guard's cron line (agent/fanboy-guard.php watch, every minute, only when the journal names a holder): there only
 * while Fanboy is armed — written at «Take over», gone at «Hand back», put right at every agent start. Unarmed nothing
 * of his holds an output but a setup step, and a step gives back by itself (its lease ends 45 s after the page is gone),
 * the agent's tick and its supervisor give back for one killed hard. A server where he is hidden never has the line.
 */
function fanboyGuardCron(bool $on): void
{
    $file = fanboyGuardCronFile();
    $line = "# Unraid Secretary Office - Fanboy's guard, only while he steers (written by the agent)\n"
        . "* * * * * grep -qs '\"owner\":{' " . fanboyRunDir() . '/journal.json && php ' . OFFICE_DIR . "/agent/fanboy-guard.php watch > /dev/null 2>&1\n";
    $there = !is_link($file) && is_file($file);
    if ($on ? $there && @file_get_contents($file) === $line : !$there) {
        return;
    }
    if (!is_dir(dirname($file))) {
        return;                         // no flash folder of the plugin (never made here)
    }
    if ($on) {
        writeAtomic($file, $line, 0600, 0, 0);
    } else {
        @unlink($file);
    }
    if (fanboySysRoot() !== '') {
        $GLOBALS['fanboyCronApplied'] = ($GLOBALS['fanboyCronApplied'] ?? 0) + 1;      // the tests' tree: no crontab
        return;
    }
    [$exit, , $err] = run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);        // its first line is no shebang
    if ($exit !== 0) {
        logLine('Fanboy: update_cron failed: ' . trim($err));
    }
}
