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
const FANBOY_MEASURE_STEPS = [90, 80, 70, 60, 50, 45, 40, 35, 30, 25, 20, 15, 10];
const FANBOY_SETTLE_MAX = 10.0;         // measure: seconds at most for a value to settle
const FANBOY_START_WAIT = 4.0;          // measure: seconds a start value gets to make a standing fan turn
const FANBOY_PLACES = ['front', 'rear', 'top', 'bottom', 'side'];
const FANBOY_ZONES_MAX = 8;
const FANBOY_SOURCES_MAX = 32;
const FANBOY_ID_RE = '/^[A-Za-z0-9_.:-]{1,80}:pwm\d{1,2}$/D';

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
        $entry = is_array($raw['boards'][$key] ?? null) ? $raw['boards'][$key] : [];
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
        $out[$n] = fanboyReadInt("$dir/fan{$n}_input");
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
        while (($io['now'])() - $t0 < FANBOY_UP_FOR) {
            fanboyStepWait($io, 0.5);
            foreach (fanboySample($o) as $n => $r) {
                if ($r !== null && $r >= max($base[$n] * 1.15 + 100, 300)) {
                    $seen[$n] = ($seen[$n] ?? 0) + 1;
                }
            }
            if (($io['now'])() - $t0 >= 4 && $seen && min($seen) >= 2) {
                break;
            }
        }
        $follow = array_keys(array_filter($seen, fn ($k) => $k >= 2));
    } finally {
        fanboyGiveBack([$o['id']], 'step', $owner);
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
    $full = ($initial['enable'] ?? null) === 0 || (($initial['enable'] ?? 1) === 1 && ($initial['value'] ?? 0) >= 240);
    return ['found' => $follow ? 'tach' : ($full ? 'unclear' : 'ask'), 'tachs' => $follow, 'base' => $base];
}

/** Too warm to go on measuring: a CPU, NVMe or awake disk at its limit less 5 °C */
function fanboyStepWarm(): ?string
{
    $p = fanboyPaths(fanboySysRoot() ?: null);
    foreach (fanboyChips($p['hwmon']) as $c) {
        if (in_array($c['kind'], ['cpu', 'nvme'], true)) {
            foreach ($c['temps'] as $t) {
                if ($t['c'] !== null && $t['c'] >= FANBOY_SOURCE_DEFAULTS[$c['kind']]['limit'] - 5) {
                    return "{$c['name']} {$t['c']} °C";
                }
            }
        }
    }
    foreach (fanboyDisks($p['disks']) as $d) {
        if (!$d['asleep'] && $d['temp'] !== null && $d['temp'] >= FANBOY_SOURCE_DEFAULTS['disk']['limit'] - 5) {
            return "{$d['name']} {$d['temp']} °C";
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
            if (($warm = fanboyStepWarm()) !== null) {
                fanboySet($o['id'], FANBOY_FULL, $owner);
                return ['result' => 'warm', 'warm' => $warm, 'table' => $table];
            }
            fanboySet($o['id'], (int) round($pct * 2.55), $owner);
            [$rpm, $stall] = fanboySettle($o, $tachs, $io);
            if ($stall) {
                $stalledAt = $pct;
                break;
            }
            $table[] = [$pct, $rpm];
            $steady = $pct;
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
            if ($step['kind'] === 'test_up') {
                $r = fanboyTestUp($o, $owner, $io, $key);
                $step['results'][$id] = $r;
                if ($r['found'] !== 'busy') {
                    fanboyStoreUpdate(function (array $e) use ($id, $o, $r): array {
                        $f = is_array($e['fans'][$id] ?? null) ? $e['fans'][$id] : [];
                        $f = ['chip' => $o['chip'], 'address' => $o['address'], 'output' => $o['output'], 'tach' => $r['tachs'][0] ?? null,
                              'tachs' => $r['tachs'], 'found' => $r['found'], 'tested_at' => time()] + $f;
                        $e['fans'][$id] = $f;
                        return $e;
                    }, $board);
                }
            } else {
                $f = fanboyBoardRaw($board)['fans'][$id] ?? null;
                $tachs = is_array($f) ? array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')) : [];
                if (!is_array($f) || !in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) || ($f['found'] ?? null) !== 'tach' || !$tachs) {
                    $step['results'][$id] = ['result' => 'not_his'];
                    continue;
                }
                $r = fanboyMeasure($o, $tachs, $owner, $io, $key);
                $step['results'][$id] = $r;
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
                    throw new FanboyStepStop('warm');
                }
            }
            $step['done'][] = $id;
            fanboyStepWrite($step);
        }
        $step['status'] = 'done';
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
        if ($kind === 'measure' && (!is_array($f) || !in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) || ($f['found'] ?? null) !== 'tach')) {
            throw new Problem('fanboy_not_his', ['fan' => $id]);        // CPU cooler, pump, «don't know», no feedback: never down
        }
        if ($kind === 'test_up' && is_array($f) && in_array($f['role'] ?? null, FANBOY_ROLES_BIOS, true) && ($f['role'] ?? null) !== 'unknown') {
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

/** A role (and name, place, «off allowed»): CPU cooler, pump, «don't know» stay with the BIOS — out of every zone */
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
    $e = fanboyStoreUpdate(function (array $e) use ($id, $role, $name, $place, $offOk): array {
        $f = $e['fans'][$id] ?? null;
        if (!is_array($f) || !in_array($f['found'] ?? null, ['tach', 'silent', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        if ($offOk && !is_array($f['measured'] ?? null)) {
            throw new Problem('fanboy_off_needs_measure');        // «off» only for a fan measured with feedback
        }
        $f['role'] = $role;
        $f['name'] = $name;
        $f['place'] = $place !== '' ? $place : null;
        $f['off_ok'] = $offOk && in_array($role, FANBOY_ROLES_OURS, true);
        $e['fans'][$id] = $f;
        return in_array($role, FANBOY_ROLES_BIOS, true) ? fanboyZonesWithout($e, $id) : $e;
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
        fn ($f) => is_array($f) && in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) && in_array($f['found'] ?? null, ['tach', 'silent'], true));
    $disks = array_keys(array_filter($fans, fn ($f) => $f['role'] === 'disks'));
    $case = array_keys(array_filter($fans, fn ($f) => $f['role'] !== 'disks'));
    $sources = fanboySources($scan);
    $zones = [];
    if ($disks) {
        $zones[] = ['id' => 'disks', 'kind' => 'disks', 'name' => '', 'fans' => $disks, 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => array_values(array_map(fn ($s) => ['kind' => 'disk', 'id' => $s['id']], array_filter($sources, fn ($s) => $s['kind'] === 'disk')))];
    }
    if ($case) {
        $kinds = $disks ? ['nvme', 'ram', 'board'] : ['disk', 'nvme', 'ram', 'board'];
        $zones[] = ['id' => 'case', 'kind' => 'case', 'name' => '', 'fans' => $case, 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => array_values(array_map(fn ($s) => ['kind' => $s['kind'], 'id' => $s['id']], array_filter($sources, fn ($s) => in_array($s['kind'], $kinds, true))))];
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
            if (!is_array($f) || !in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) || !in_array($f['found'] ?? null, ['tach', 'silent'], true) || isset($used[$id])) {
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
        $out[$z['id']] = ['id' => $z['id'], 'name' => $name, 'fans' => array_values($z['fans']), 'sources' => array_values($sources),
            'target' => $target, 'unit' => $unit, 'low' => fanboyNum($z, 'low', 0, 110), 'high' => fanboyNum($z, 'high', 0, 120)];
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
    logLine('Fanboy: handed back to the BIOS on the user\'s click (' . count($back) . ' outputs)');
    return ['ok' => true, 'given' => count($back)];
}
