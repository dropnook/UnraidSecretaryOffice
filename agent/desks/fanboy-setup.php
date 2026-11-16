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
const FANBOY_UP_FOR = 60.0;             // … seconds at full speed at most (round 8, the owner: up to a minute — the user finds the fan
                                        // and ends it with «found — next»; only «inverted» ends it by itself)
const FANBOY_UP_MIN = 8.0;              // round 8: «found — next» before two looks agreed: full until they have, or this long
const FANBOY_UP_SETTLE = 8.0;           // … seconds at most for the fans to calm down before the next output
const FANBOY_READBACK_AFTER = 2.0;      // round 5: a write read back this long after — changed back: the BIOS keeps it
const FANBOY_MEASURE_STEPS = [90, 80, 70, 60, 50, 45, 40, 35, 30, 25, 20, 15, 10];
const FANBOY_SETTLE_MAX = 10.0;         // measure: seconds at most for a value to settle
const FANBOY_START_WAIT = 4.0;          // measure: seconds a start value gets to make a standing fan turn
const FANBOY_STOP_LOOK = 15.0;          // round 12: the standstill part — seconds at 0 for the fan to stop (else: a floor it keeps)
const FANBOY_STOP_START_MAX = 60;       // … the start from standstill looked for up to this percent, then the kick
const FANBOY_STOP_CPU_MAX = 70.0;       // round 12, a CPU cooler's standstill part: back to full at once at this CPU °C …
const FANBOY_STOP_CPU_RISE = 15.0;      // … or when the CPU rose this much during it (a missing reading: at once too) — the
                                        // rise by the smoothed CPU (round 12 fix: fanboyCpuGuard()), the 70 °C by any reading
const FANBOY_START_CONFIRM = 2;         // round 12 fix: a start from standstill found is tried this many times more
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
const FANBOY_LOOK_KEY_RE = '/^[a-z0-9-]{1,24}$/D';      // round 12: the frame's and wheel's colour, the blades' style (the page's keys)
const FANBOY_BLADES_MIN = 5;            // round 12: blades the user may give a fan
const FANBOY_BLADES_MAX = 11;
const FANBOY_GUARD_CRON = '/boot/config/plugins/unraid-secretary-office/fanboy-guard.cron';

class FanboyStepStop extends RuntimeException
{
}

/** Round 8 (G1): the quiet test stopped for a temperature — what, how warm (the step says it) */
final class FanboyQuietWarm extends FanboyStepStop
{
    public function __construct(public readonly array $what, string $why = 'quiet_warm')
    {
        parent::__construct($why);
    }
}

/*
 * Round 8 (G1, the owner, 2026-11-15 — the one exception to «never down»): the QUIET test. While one output X is tested
 * (or shown while he doesn't steer), every other output is stopped so that only X is heard: each taken like any take (its
 * state before in the journal), a fixed 0 written; X to full for up to FANBOY_UP_FOR (a minute; «found — next» ends it); then every one given
 * back exactly (value + mode, read back); then at least FANBOY_QUIET_PAUSE with everything as before, in which every tach
 * that turned before must turn again within FANBOY_QUIET_RESTART — one that doesn't: its output (or, its output unknown,
 * every output quieted on that chip) to full, an alert, the test stops. Never quieted: X, a CPU cooler or pump (by its
 * role, once known), an output found inverted, one whose driver takes no values, one without a mode file, one someone
 * else holds. Guards: refused while anything is warm or the CPU at FANBOY_QUIET_CPU_START; during the quiet phase every
 * look (≤ 0.5 s): the CPU at FANBOY_QUIET_CPU_STOP or FANBOY_QUIET_CPU_RISE over its start, or any place at its «needs
 * all the air» → everything back at once, the test stops naming it. A holder that dies or hangs, the page gone (45 s):
 * the existing ways give every quieted output back (journal holder kind «step»).
 */
const FANBOY_QUIET_PAUSE = 5.0;         // seconds at least with everything as before, between two outputs
const FANBOY_QUIET_RESTART = 10.0;      // seconds a fan that turned before has to turn again
const FANBOY_QUIET_CPU_START = 70.0;    // °C: the CPU this warm — no quiet test
const FANBOY_QUIET_CPU_STOP = 80.0;     // °C: the CPU this warm during it — everything back
const FANBOY_QUIET_CPU_RISE = 20.0;     // °C over the CPU's value at the quiet phase's start — everything back

/** Every tach input's rpm now: [chip@address#n => rpm|null] (the restart check's before and after) */
function fanboyAllTachs(): array
{
    $out = [];
    foreach (fanboyChips(fanboyPaths(fanboySysRoot() ?: null)['hwmon']) as $c) {
        if ($c['kind'] === 'gpu') {
            continue;
        }
        foreach ($c['fans'] as $f) {
            $out[$c['name'] . '@' . $c['address'] . '#' . $f['n']] = $f['rpm'];
        }
    }
    return $out;
}

/** The warmest CPU temperature now (°C), null when none says one */
function fanboyCpuNow(array $chips): ?float
{
    $max = null;
    foreach ($chips as $c) {
        if ($c['kind'] === 'cpu') {
            foreach ($c['temps'] as $t) {
                $max = $t['c'] === null ? $max : max($max ?? $t['c'], $t['c']);
            }
        }
    }
    return $max;
}

/**
 * Is it too warm for the quiet test? $cpuStart null: before it (anything warm, the CPU at FANBOY_QUIET_CPU_START);
 * a number: during it (the CPU at FANBOY_QUIET_CPU_STOP or FANBOY_QUIET_CPU_RISE over $cpuStart, anything warm).
 * [what (its name as the page says it), c] — or null
 */
function fanboyQuietWarm(?float $cpuStart = null): ?array
{
    $p = fanboyPaths(fanboySysRoot() ?: null);
    $chips = fanboyChips($p['hwmon']);
    $disks = fanboyDisks($p['disks'], fanboyDiskLimits($p));
    $cpu = fanboyCpuNow($chips);
    $lang = function_exists('officeNotifyLang') ? officeNotifyLang() : 'en';
    $cpuName = fn () => function_exists('officeNotifyText') ? officeNotifyText('fanboy', 'kind.cpu', [], $lang) : 'CPU';
    if ($cpu !== null && ($cpuStart === null ? $cpu >= FANBOY_QUIET_CPU_START : ($cpu >= FANBOY_QUIET_CPU_STOP || $cpu >= $cpuStart + FANBOY_QUIET_CPU_RISE))) {
        return ['what' => $cpuName(), 'c' => $cpu];
    }
    $warm = fanboyWarmNow($chips, $disks, fanboyDiskAllowance(fanboyPollAttributes($p), $disks), $lang);
    return $warm === null ? null : ['what' => $warm['name'], 'c' => $warm['c']];
}

/**
 * The outputs to stop while $x is tested: every other offered output — not a CPU cooler or pump (their role), not one
 * found inverted, not one whose driver takes no values (the control's last word), not one without a mode file
 */
function fanboyQuietOthers(string $x, array $outputs, ?array $settings): array
{
    $refuses = readJson(fanboyStatusFile())['refuses'] ?? [];
    $out = [];
    foreach ($outputs as $id => $o) {
        $f = $settings['fans'][$id] ?? null;
        if ($id === $x || in_array($f['role'] ?? null, ['cpu', 'pump'], true) || fanboyFound($f) === 'inverted'
            || (is_array($refuses) && in_array($id, $refuses, true)) || (fanboyOutputNow($o['device'], $o['output'])['enable'] ?? null) === null) {
            continue;
        }
        $out[$id] = $o;
    }
    return $out;
}

/** Stops the others: each taken (its state before in the journal) and set to a fixed 0 — those it couldn't take, left alone */
function fanboyQuietStop(array $others, array $owner, string $boardKey): array
{
    $held = [];
    foreach ($others as $id => $o) {
        if (fanboyTake($o, $owner, $boardKey) === null) {
            continue;                   // someone else holds it: as it is
        }
        $held[] = $id;
        fanboySet($id, 0, $owner);      // an output that doesn't take 0 (a PWM fan that only slows down): no error, no verdict
    }
    return $held;
}

/**
 * After a quiet phase (everything given back): at least FANBOY_QUIET_PAUSE as before, every tach that turned before
 * ($before) turning again within FANBOY_QUIET_RESTART. One that doesn't: its output — the one the setup paired it with,
 * else every output quieted on its chip — to full (unheld, fanboyFullNow()), an alert naming it, the test stops.
 */
/**
 * Round 8 (the owner): «Gefunden — weiter» — the user found the fan under test: the step's current output ends early
 * (the others back at once, the output itself once the looks agreed or FANBOY_UP_MIN passed). RUN_DIR/fanboy/step.next
 * holds {step, fan, heard}; read (and removed) by the step, written by the action `step_next {fan, heard?}`.
 * Round 11: tied to the step's id (a left-over file — the owner's server kept one after a run — never ends another run's
 * output) and removed when a step starts and ends; `heard` is the user's answer, stored with the verdict (fanboyHeard()).
 */
function fanboyStepNextFile(): string
{
    return fanboyRunDir() . '/step.next';
}

/**
 * «Weiter» asked for output $id of step $stepId (null: any step — the tests' own runs): null when not; else {heard:
 * bool|null}, the file removed. A file of another shape (round 8's plain id), another step or another output: not asked.
 */
function fanboyStepNextAsked(string $id, ?string $stepId = null): ?array
{
    $f = fanboyStepNextFile();
    $j = is_file($f) && !is_link($f) ? readJson($f) : null;
    if (!is_array($j) || ($j['fan'] ?? null) !== $id || !is_string($j['step'] ?? null) || ($stepId !== null && $j['step'] !== $stepId)) {
        return null;
    }
    @unlink($f);
    return ['heard' => is_bool($j['heard'] ?? null) ? $j['heard'] : null];
}

/**
 * Round 11: a verdict with the user's answer, applied as `answer` does — only where no tach followed (ask, unclear: a tach
 * that followed always wins); heard true: a fan without feedback (silent), false: nothing attached (empty)
 */
function fanboyHeard(string $found, ?bool $heard): string
{
    return $heard === null || !in_array($found, ['ask', 'unclear'], true) ? $found : ($heard ? 'silent' : 'empty');
}

/**
 * The action: for the output a running test has under test now — «weiter» and the answer for the step; for an output
 * this step already ended (its verdict stored — the click came during the settling or the pause after it) the answer is
 * stored at once (round 11: never lost); anything else quietly nothing
 */
function fanboyStepNext(array $r): array
{
    $id = textField($r, 'fan');
    $heard = ($r['heard'] ?? null) === null ? null : boolField($r, 'heard');      // round 11: optional; absent = as before
    $s = fanboyStepRead();
    if ($s !== null && $s['kind'] === 'test_up' && is_array($s['results'][$id] ?? null)) {
        if ($heard !== null) {
            fanboyStoreUpdate(function (array $e) use ($id, $heard): array {
                $f = $e['fans'][$id] ?? null;
                if (is_array($f) && ($found = fanboyFound($f)) !== null && fanboyHeard($found, $heard) !== $found) {
                    $e['fans'][$id]['found'] = fanboyHeard($found, $heard);
                    $e['fans'][$id]['why'] = null;
                    return $heard ? $e : fanboyZonesWithout($e, $id);
                }
                return $e;
            });
        }
    } elseif ($s !== null && in_array($s['kind'], ['test_up', 'hub_look'], true) && in_array($s['status'], ['starting', 'running'], true) && ($s['current'] ?? null) === $id) {
        @mkdir(fanboyRunDir(), 0700, true);
        writeAtomic(fanboyStepNextFile(), jsonEncode(['step' => (string) ($s['id'] ?? ''), 'fan' => $id, 'heard' => $heard]), 0600, 0, 0);
    }
    return ['ok' => true, 'step' => fanboyStepRead()];
}

const FANBOY_KICK_WAIT = 5.0;           // round 9: seconds a fan that turned before gets to start again by itself …
const FANBOY_KICK_FOR = 3.0;            // … then its output to full this long (the kick) …
const FANBOY_KICK_LOOK = 10.0;          // … and this long to turn after it (still not: full + an alert)

/** The tachs of $before that turned (≥ 200 rpm) and read under 100 now — a sentinel or nothing read is no value, never «stands» */
function fanboyStuckTachs(array $before): array
{
    $now = fanboyAllTachs();
    return array_keys(array_filter($before, fn ($rpm, $tid) => is_int($rpm) && $rpm >= 200 && is_int($now[$tid] ?? null) && $now[$tid] < 100, ARRAY_FILTER_USE_BOTH));
}

/** A tach's output(s): the one the setup paired it with, else every one of $candidates on its chip */
function fanboyTachOutputs(string $tid, array $candidates, ?array $settings): array
{
    $chipOf = fn (string $id) => (string) preg_replace('/:pwm\d+$/D', '', $id);
    [$chip, $n] = explode('#', $tid) + [1 => '0'];
    $chipKey = str_replace('@', ':', $chip);
    $paired = array_keys(array_filter(is_array($settings['fans'] ?? null) ? $settings['fans'] : [], fn ($f, $id) => is_array($f)
        && $chipOf((string) $id) === $chipKey && fanboyFound($f) === 'tach' && in_array((int) $n, is_array($f['tachs'] ?? null) ? $f['tachs'] : [], true), ARRAY_FILTER_USE_BOTH));
    return $paired ?: array_values(array_filter($candidates, fn ($id) => $chipOf((string) $id) === $chipKey));
}

/**
 * Round 9 (H1, the owner's server: a fan that KEEPS turning at 50 % but doesn't START at 50 % — it needs a kick): every
 * tach that turned before ($before) and still stands → its output to full for FANBOY_KICK_FOR, back exactly to its state
 * before, FANBOY_KICK_LOOK to turn. Turning: «needs a kick to start» kept for that fan (kick_start: the value it doesn't
 * start at — a hint for the control's start value). Returns the tachs still standing after the kick.
 */
function fanboyKickStart(array $stuck, array $candidates, array $owner, string $boardKey, array $io): array
{
    $settings = fanboyBoardRaw() ?? [];
    $outputs = fanboyOutputs(fanboyDetect(fanboySysRoot() ?: null));
    $byTach = [];
    foreach ($stuck as $tid) {
        $byTach[$tid] = array_values(array_filter(fanboyTachOutputs($tid, $candidates, $settings), fn ($id) => isset($outputs[$id])));
    }
    $ids = array_values(array_unique(array_merge(...array_values($byTach) ?: [[]])));
    if (!$ids) {
        return $stuck;
    }
    ($io['note'] ?? fn (array $p) => null)(['phase' => 'kick', 'until' => time() + (int) (FANBOY_KICK_FOR + FANBOY_KICK_LOOK)]);
    $taken = [];
    try {
        foreach ($ids as $id) {
            if (fanboyTake($outputs[$id], $owner, $boardKey) !== null && fanboySet($id, FANBOY_FULL, $owner) !== null) {
                $taken[] = $id;
            }
        }
        ($io['sleep'])(FANBOY_KICK_FOR);         // never cut short by a stop: a kick ends by itself
    } finally {
        fanboyGiveBack($taken, 'kick', $owner);              // exactly the state before — the value it keeps turning at
    }
    // round 12: judged at the END of the look — right after the kick a fan still coasts down from full (a fan that turns
    // only at full read «turning» at the first look and was left standing); started = still turning after FANBOY_KICK_LOOK
    $t = ($io['now'])();
    $left = array_keys($byTach);
    while (($io['now'])() - $t < FANBOY_KICK_LOOK) {
        ($io['sleep'])(0.5);
    }
    $before = array_intersect_key($GLOBALS['fanboyKickBefore'] ?? [], array_flip($left));
    $left = array_values(array_intersect($left, fanboyStuckTachs($before)));
    // started after the kick: a fan the setup knows remembers it (its card says so; the control starts it above that)
    $learned = [];
    foreach (array_diff(array_keys($byTach), $left) as $tid) {
        foreach (fanboyTachOutputs($tid, [], $settings) as $id) {
            $learned[$id] = fanboyJournal()['outputs'][$id]['initial']['value'] ?? null;
        }
        logLine("Fanboy: $tid started again only after a kick — it needs one to start from standing");
    }
    if ($learned) {
        fanboyStoreUpdate(function (array $e) use ($learned): array {
            foreach ($learned as $id => $value) {
                if (is_array($e['fans'][$id] ?? null)) {
                    $e['fans'][$id]['kick_start'] = ['at' => time(), 'value' => is_int($value) ? $value : null];
                }
            }
            return $e;
        });
    }
    return $left;
}

/**
 * After a quiet phase (everything given back): at least FANBOY_QUIET_PAUSE as before; a fan that turned before and
 * doesn't turn by FANBOY_KICK_WAIT gets a kick (fanboyKickStart(), round 9); one still standing after it: its output —
 * the one the setup paired it with, else every output quieted on its chip — to full (unheld, fanboyFullNow(): the page
 * shows it on the card, «back to the value before» undoes it), an alert naming it, the test stops.
 */
function fanboyQuietRestart(array $before, array $quieted, array $io, ?array $owner = null, string $boardKey = ''): void
{
    ($io['note'] ?? fn (array $p) => null)(['phase' => 'pause', 'until' => time() + (int) FANBOY_QUIET_PAUSE]);
    $GLOBALS['fanboyKickBefore'] = $before;
    $t1 = ($io['now'])();
    $stuck = [];
    while (true) {
        $stuck = fanboyStuckTachs($before);
        $elapsed = ($io['now'])() - $t1;
        if (!$stuck && $elapsed >= FANBOY_QUIET_PAUSE) {
            return;
        }
        if ($stuck && $elapsed >= FANBOY_KICK_WAIT) {
            break;
        }
        ($io['sleep'])(0.5);                    // round 9: the pause is never cut short by a stop — every fan must turn again
    }
    if ($owner !== null) {
        $stuck = fanboyKickStart($stuck, $quieted, $owner, $boardKey, $io);
        if (!$stuck) {
            ($io['sleep'])(1.0);
            return;
        }
    }
    $settings = fanboyBoardRaw() ?? [];
    $full = [];
    $names = fanboyTachNames(fanboyChips(fanboyPaths(fanboySysRoot() ?: null)['hwmon']), $settings);
    foreach ($stuck as $tid) {
        $ids = fanboyTachOutputs($tid, $quieted, $settings);
        $full = array_merge($full, $ids);
        logLine("Fanboy: after the quiet test {$names[$tid]} didn't turn again, not even after a kick — " . ($ids ? implode(', ', $ids) . ' set to full speed' : 'no output of it known'));
        fanboyTellOnce("no_restart:$tid", 'quiet_no_restart', ['fan' => $names[$tid] ?? $tid], 'alert');
    }
    fanboyFullNow(array_values(array_unique($full)), 'no_restart');
    throw new FanboyQuietWarm(['what' => implode(', ', array_map(fn ($tid) => $names[$tid] ?? $tid, $stuck)), 'c' => null], 'no_restart');
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
function fanboyTestUp(array $o, array $owner, array $io, string $boardKey, bool $quiet = false, ?array &$partial = null): array
{
    $partial = null;
    // round 8 (G1): the quiet test — never while anything is warm; what turned before, for the restart check after
    $tachsBefore = [];
    $others = [];
    if ($quiet) {
        if (($warm = fanboyQuietWarm()) !== null) {
            throw new FanboyQuietWarm($warm);
        }
        $tachsBefore = fanboyAllTachs();
        $others = fanboyQuietOthers($o['id'], fanboyOutputs(fanboyDetect(fanboySysRoot() ?: null)), fanboyBoardRaw());
    }
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
    $quieted = [];
    $quietedAll = [];
    $cpuStart = null;
    $next = false;
    $heard = null;                          // round 11: the user's answer with «weiter» (step.next is tied to step and output)
    $io0 = $io;
    $stepId = is_string($io['step_id'] ?? null) ? $io['step_id'] : null;
    try {
        if ($quiet) {
            $cpuStart = fanboyCpuNow(fanboyChips(fanboyPaths(fanboySysRoot() ?: null)['hwmon']));
            $quieted = fanboyQuietStop($others, $owner, $boardKey);
            $quietedAll = $quieted;
        }
        if (fanboySet($o['id'], FANBOY_FULL, $owner) === null) {
            return ['found' => 'busy', 'tachs' => []];
        }
        // round 9 (H3): what runs now, for the page — this output at full (the others standing in a quiet test)
        ($io['note'] ?? fn (array $p) => null)(['phase' => 'full', 'quiet' => $quiet, 'until' => time() + (int) FANBOY_UP_FOR]);
        $t0 = ($io['now'])();
        $seen = [];
        $fell = [];
        $checked = false;
        while (($io['now'])() - $t0 < FANBOY_UP_FOR) {
            fanboyStepWait($io, 0.5);
            // round 8: «found — next»: the others back at once; this one once the looks agreed (or FANBOY_UP_MIN)
            if (!$next && ($asked = fanboyStepNextAsked($o['id'], $stepId)) !== null) {
                $next = true;
                $heard = $asked['heard'];
                if ($quieted) {
                    fanboyGiveBack($quieted, 'step', $owner);
                    $quieted = [];
                }
            }
            if ($next && ((($io['now'])() - $t0 >= FANBOY_UP_MIN) || ($seen && max($seen) >= 2))) {
                break;
            }
            // round 8 (G1): every look of the quiet phase — too warm: everything back at once (finally), the test stops
            if ($quiet && ($warm = fanboyQuietWarm($cpuStart ?? -INF)) !== null) {
                throw new FanboyQuietWarm($warm);
            }
            // round 5 (forum 3.2): about 2 s after the write, read it back — a value or mode changed back is the BIOS
            // taking the connector back: «doesn't obey», never «empty», never steered
            if (!$checked && ($io['now'])() - $t0 >= FANBOY_READBACK_AFTER) {
                $checked = true;
                $now = fanboyOutputNow($o['device'], $o['output']);
                // round 7: «full on» (enable 0 with 255 — the it87 driver's read-back of a fixed full) obeys; only a
                // value that changed or the chip's automatic is taken back
                if ($now !== null && !fanboyObeys(FANBOY_FULL, $now)) {
                    $taken_back = true;
                    break;
                }
            }
            foreach (fanboySample($o) as $n => $r) {
                if ($r !== null && $r >= max($base[$n] * 1.15 + 100, 300)) {
                    $seen[$n] = ($seen[$n] ?? 0) + 1;
                }
                // round 5 (forum 3.11): full makes it FALL clearly — the output works the other way round (not judged in the
                // quiet test: the other fans stopping fall on purpose)
                if (!$quiet && $r !== null && $base[$n] >= 300 && $r <= $base[$n] * 0.6) {
                    $fell[$n] = ($fell[$n] ?? 0) + 1;
                }
            }
            // round 8 (G2, the owner's fans lag several seconds): a tach that follows no longer ends it early — the output
            // stays at full for FANBOY_UP_FOR so the fan is up and heard; the looks go on meanwhile. Only a fan that FALLS
            // (the output works the other way round) ends it at once
            if (($io['now'])() - $t0 >= 4 && !$seen && $fell && min($fell) >= 2) {
                break;
            }
        }
        $follow = array_keys(array_filter($seen, fn ($k) => $k >= 2));
        $inverted = $follow ? [] : array_keys(array_filter($fell, fn ($k) => $k >= 2));
    } catch (Throwable $e) {
        // round 11: ended half-way after the looks' minimum at full — what they saw so far is its verdict (stored by the step)
        if (isset($t0) && ($io['now'])() - $t0 >= FANBOY_UP_MIN) {
            $seenNow = array_keys(array_filter($seen ?? [], fn ($k) => $k >= 2));
            sort($seenNow);
            $wasFull = ($initial['enable'] ?? null) === 0 || (($initial['enable'] ?? 1) === 1 && ($initial['value'] ?? 0) >= 240);
            $partial = ['found' => $seenNow ? 'tach' : ($wasFull ? 'unclear' : 'ask'), 'tachs' => $seenNow, 'base' => $base, 'heard' => $heard, 'partial' => true];
        }
        // round 9: stopped half-way (Abbrechen, the page gone, too warm, an error): everything back at once (below), and
        // still every fan that stood must turn again — the kick, else full + alert — before the stop goes on
        fanboyGiveBack([$o['id'], ...$quieted], 'step', $owner);
        if ($quiet && $quietedAll) {
            $io['note'] = fn (array $p) => ($io0['note'] ?? fn (array $p) => null)($p + ['last' => true]);      // the run ends: no next
        }
        if ($quiet && $quietedAll) {
            try {
                fanboyQuietRestart($tachsBefore, $quietedAll, $io, $owner, $boardKey);
            } catch (FanboyQuietWarm $r) {
                throw $r;                       // a fan that didn't start again says more than why the test stopped
            }
        }
        throw $e;
    } finally {
        fanboyGiveBack([$o['id'], ...$quieted], 'step', $owner);      // at once — an inverted output stopped its fan; the quieted ones
    }
    if (!empty($taken_back)) {
        return ['found' => 'ignored', 'tachs' => [], 'base' => $base, 'why' => 'taken_back', 'heard' => $heard];
    }
    sort($follow);
    sort($inverted);
    $full = ($initial['enable'] ?? null) === 0 || (($initial['enable'] ?? 1) === 1 && ($initial['value'] ?? 0) >= 240);
    $result = $inverted ? ['found' => 'inverted', 'tachs' => $inverted, 'base' => $base]
        : ['found' => $follow ? 'tach' : ($full ? 'unclear' : 'ask'), 'tachs' => $follow, 'base' => $base];
    $result['heard'] = $heard;
    // round 11: the looks are done — a stop while it settles or in the pause (a fan that doesn't start again) keeps it
    $partial = $result;
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
    // round 8 (G1): after a quiet phase, the pause — every fan that turned before turns again, or its output to full
    if ($quiet && $quietedAll) {
        fanboyQuietRestart($tachsBefore, $quietedAll, $io, $owner, $boardKey);
    }
    return $result;
}

/**
 * Round 5 (forum 3.2): after a test up — a tach of a chip that turned before and that no output of it moves is a fan
 * whose connector the BIOS keeps: an output of that chip where nothing followed is «doesn't obey» (`ignored`), not
 * «did something get louder?» (ask only when nothing turns). $fans: the store's fans; returns them with the change.
 * Round 8 (G3, the owner's server: tested one by one, outputs 2 and 3 became «the BIOS keeps it» because the fans of 4–6,
 * not tested yet, turned unpaired): judged only when EVERY output of that chip has been tested (its verdict saved) —
 * a turning tach is unpaired only when all the others were tried and none claimed it. $outputs: the chip's outputs now.
 */
function fanboyUpIgnored(array $fans, array $results, ?array $outputs = null): array
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
    $untested = [];
    foreach ($outputs ?? [] as $oid) {
        if (fanboyFound($fans[$oid] ?? null) === null) {
            $untested[$chipOf((string) $oid)] = true;
        }
    }
    foreach ($fans as $id => $f) {
        $chip = $chipOf((string) $id);
        if (is_array($f) && ($f['found'] ?? null) === 'ask' && isset($results[$id]) && !isset($untested[$chip]) && array_diff_key($turning[$chip] ?? [], $paired[$chip] ?? [])) {
            $fans[$id]['found'] = 'ignored';
            $fans[$id]['why'] = 'unpaired';         // round 8: a verdict of its own (fanboyFound() knows it)
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
    $add = fanboyPollAllowance(fanboyPollAttributes($p));       // round 6: an old disk value is a lower bound
    foreach (fanboyDisks($p['disks']) as $d) {
        $w = $d['asleep'] ? null : $check($d['name'], $d['rotational'] ? 'disk' : 'ssd', $d['name'], $d['temp'] === null ? null : (float) ($d['temp'] + $add));
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

/**
 * Round 5 (forum 3.2): a value written that reads back otherwise a few seconds later — the BIOS took the connector back.
 * Round 7: judged by fanboyObeys() against the value asked ($value): «full on» after a fixed 255 is no take-back.
 */
function fanboyTakenBack(array $o, int $value, ?array $wrote): bool
{
    $now = fanboyOutputNow($o['device'], $o['output']);
    return $wrote !== null && $now !== null && !fanboyObeys($value, $now);
}

/**
 * Measure a fan DOWNWARD (only one given to him, with feedback): full speed, then FANBOY_MEASURE_STEPS down; at a
 * stall measuring stops and the fan goes back up — in 5 % steps from there until it turns (its start value; none by
 * the last steady + 20 %: full speed). Then exactly its state before. Too warm, «stop», the page gone: back at once.
 * Round 12 ($standstill: a fan that may be let stand — never a CPU cooler or pump): then the standstill part
 * (fanboyStandstill()), and the restart check of the quiet test after it. $partial: the measurement as far as it got
 * once the table is complete (the step stores it when the standstill part or the restart check ends the run).
 */
function fanboyMeasure(array $o, array $tachs, array $owner, array $io, string $boardKey, bool $standstill = false, ?array &$partial = null, bool $cpu = false): array
{
    $partial = null;
    $tachsBefore = $standstill ? fanboyAllTachs() : [];
    if (fanboyTake($o, $owner, $boardKey) === null) {
        return ['result' => 'busy'];
    }
    $stood = false;
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
            if (fanboyTakenBack($o, (int) round($pct * 2.55), $wrote)) {
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
        $r = ['result' => 'ok', 'table' => $table, 'min_pct' => $steady, 'start_pct' => max($start, $steady), 'max_rpm' => $max,
              'stalled_at' => $stalledAt];
        if (!$standstill) {
            return $r;
        }
        $partial = $r;
        $stood = true;
        $stop = fanboyStandstill($o, $tachs, $owner, $io, $steady, $table, $cpu);
        if (isset($stop['warm'])) {
            // round 12 fix: cut — said in the result and kept with the measurement («Stillstand nicht geprüft: CPU 53 °C»)
            $cut = ['why' => 'warm', 'source' => $stop['warm']['source'] ?? null, 'c' => $stop['warm']['c'] ?? null, 'at' => time()];
            return ['result' => 'warm', 'warm' => $stop['warm'], 'table' => $table, 'measured' => $r + ['stand_cut' => $cut], 'stand_cut' => $cut];
        }
        $r += $stop;
        $partial = $r;
    } catch (Throwable $e) {
        if ($stood) {
            fanboySet($o['id'], FANBOY_FULL, $owner);       // stopped in the standstill part: turning first, then back (below)
        }
        throw $e;
    } finally {
        fanboyGiveBack([$o['id']], 'step', $owner);
        // round 12: it stood on purpose — every fan that turned before turns again (the kick, else full + an alert), however
        // the run ends; the pause is never cut short by a stop (fanboyQuietRestart(), round 9)
        if ($stood) {
            fanboyQuietRestart($tachsBefore, [$o['id']], $io, $owner, $boardKey);
        }
    }
    return $r;
}

/**
 * Round 12 (the owner: «Stillstand wird nicht versucht? Ideal für HDD-Lüfter»): the output to 0 for up to FANBOY_STOP_LOOK
 * — does the fan stop (stops) or keep a floor (a PWM fan that never stops: floor_rpm)? One that stops: the start from
 * standstill from $min upward in 5 % steps, each FANBOY_START_WAIT (start_from_stop); none by FANBOY_STOP_START_MAX: the
 * kick — full for FANBOY_KICK_FOR, then $min + 5 % (kick_start: the value it holds at); still not: stops_safe false (it
 * must never be let stand). Ends with the fan at full (turning); the caller gives it back. Too warm: full at once, ['warm'].
 */
function fanboyStandstill(array $o, array $tachs, array $owner, array $io, int $min, array $table, bool $cpu = false): array
{
    // round 12 (the owner: a CPU cooler under cpu_own may stand): its own guard (fanboyCpuGuard(): smoothed, round 12 fix)
    $cpuGuard = $cpu ? fanboyCpuGuard($io) : null;
    $note = fn (array $p) => ($io['note'] ?? fn (array $p) => null)(['table' => $table] + $p);
    $turns = function () use ($o, $tachs): array {
        $s = fanboySample($o);
        $r = array_map(fn ($n) => $s[$n] ?? 0, $tachs);
        return [min($r) >= 100, (int) round(array_sum($r) / max(1, count($r)))];
    };
    $warm = function () use ($o, $owner, $cpuGuard): ?array {
        $w = fanboyStepWarm(fanboyBoardRaw()) ?? ($cpuGuard !== null ? $cpuGuard() : null);
        if ($w !== null) {
            fanboySet($o['id'], FANBOY_FULL, $owner);
        }
        return $w;
    };
    // 1. standstill: 0 for up to FANBOY_STOP_LOOK — two looks under 100 rpm: it stops; else the rpm it keeps
    $note(['phase' => 'stop_check', 'until' => time() + (int) FANBOY_STOP_LOOK]);
    fanboySet($o['id'], 0, $owner);
    $t0 = ($io['now'])();
    $zero = 0;
    $rpms = [];
    while (($io['now'])() - $t0 < FANBOY_STOP_LOOK) {
        fanboyStepWait($io, 1.0);
        if (($w = $warm()) !== null) {
            return ['warm' => $w];
        }
        [$turning, $rpm] = $turns();
        $zero = $turning ? 0 : $zero + 1;
        $rpms[] = $rpm;
        if ($zero >= 2) {
            break;
        }
    }
    if ($zero < 2) {
        fanboySet($o['id'], FANBOY_FULL, $owner);
        $last = array_slice($rpms, -3);
        return ['stops' => false, 'floor_rpm' => $last ? (int) round(array_sum($last) / count($last)) : null, 'start_from_stop' => null, 'kick_start' => null,
                'stops_safe' => null];
    }
    // one start from standing at $p: FANBOY_START_WAIT for two looks turning — true, false, or ['warm' => …]
    $tryStart = function (int $p) use ($o, $owner, $io, $warm, $turns): bool|array {
        fanboySet($o['id'], (int) round($p * 2.55), $owner);
        $t1 = ($io['now'])();
        $ok = 0;
        while (($io['now'])() - $t1 < FANBOY_START_WAIT) {
            fanboyStepWait($io, 0.5);
            if (($w = $warm()) !== null) {
                return ['warm' => $w];
            }
            $ok = $turns()[0] ? $ok + 1 : 0;
            if ($ok >= 2) {
                return true;
            }
        }
        return false;
    };
    // standing again: 0 until two looks under 100 rpm (FANBOY_STOP_LOOK at most) — true, false, or ['warm' => …]
    $stopAgain = function () use ($o, $owner, $io, $warm, $turns): bool|array {
        fanboySet($o['id'], 0, $owner);
        $t1 = ($io['now'])();
        $zero = 0;
        while (($io['now'])() - $t1 < FANBOY_STOP_LOOK) {
            fanboyStepWait($io, 1.0);
            if (($w = $warm()) !== null) {
                return ['warm' => $w];
            }
            $zero = $turns()[0] ? 0 : $zero + 1;
            if ($zero >= 2) {
                return true;
            }
        }
        return false;
    };
    // 2. the start from standstill: $min upward, each value FANBOY_START_WAIT — two looks turning: found; round 12 fix (the
    // owner's fan on output 5 started at 10 % once, and once not at 50 %): confirmed by FANBOY_START_CONFIRM more stops and
    // starts at that value — one fails: 5 % up and confirm again
    $passed = 0;
    $failed = 0;
    for ($p = max(5, $min); $p <= FANBOY_STOP_START_MAX; $p += 5) {
        $note(['phase' => 'start_find', 'pct' => $p]);
        $r = $tryStart($p);
        if (is_array($r)) {
            return $r;
        }
        if ($r !== true) {
            continue;
        }
        $passed++;
        $sure = true;
        for ($i = 1; $i <= FANBOY_START_CONFIRM; $i++) {
            $note(['phase' => 'start_confirm', 'pct' => $p, 'try' => $i, 'of' => FANBOY_START_CONFIRM]);
            $st = $stopAgain();
            $r = is_array($st) ? $st : $tryStart($p);
            if (is_array($r)) {
                return $r;
            }
            if ($st !== true || $r !== true) {
                $failed++;
                $sure = false;
                break;
            }
            $passed++;
        }
        if ($sure) {
            fanboySet($o['id'], FANBOY_FULL, $owner);
            return ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => $p, 'kick_start' => null, 'stops_safe' => true,
                    'start_tries' => ['passed' => $passed, 'failed' => $failed]];
        }
    }
    // 3. the kick: full for FANBOY_KICK_FOR, then $min + 5 — turning there: it starts with the kick
    $hold = min(100, $min + 5);
    $note(['phase' => 'kick', 'pct' => $hold]);
    fanboySet($o['id'], FANBOY_FULL, $owner);
    $t2 = ($io['now'])();
    while (($io['now'])() - $t2 < FANBOY_KICK_FOR) {
        fanboyStepWait($io, 0.5);
    }
    fanboySet($o['id'], (int) round($hold * 2.55), $owner);
    $t3 = ($io['now'])();
    $ok = 0;
    $stand = 0;
    while (($io['now'])() - $t3 < FANBOY_KICK_LOOK) {
        fanboyStepWait($io, 0.5);
        if (($w = $warm()) !== null) {
            return ['warm' => $w];
        }
        $turning = $turns()[0];
        $ok = $turning ? $ok + 1 : 0;
        $stand = $turning ? 0 : $stand + 1;
        if ($stand >= 2) {
            break;                      // it stood again under the kick's value
        }
    }
    fanboySet($o['id'], FANBOY_FULL, $owner);
    $safe = $stand < 2 && $ok >= 2;
    return ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => null, 'kick_start' => $safe ? $hold : null, 'stops_safe' => $safe,
            'start_tries' => ['passed' => $passed, 'failed' => $failed]];
}

/**
 * Round 12 fix (the owner's CPU idles at 36–40 °C with single readings of 50–67 °C every half minute — the old guard cut
 * the standstill part after 2 s): a CPU cooler's guard while it stands for a test — full at once on a single reading at
 * FANBOY_STOP_CPU_MAX or none at all, or when the smoothed CPU (median of 10 s, fanboyCpuSmooth()) rose
 * FANBOY_STOP_CPU_RISE over its lowest during the test. A closure: null (go on) or what it found.
 */
function fanboyCpuGuard(array $io): callable
{
    $hist = [];
    $low = null;
    $look = function () use (&$hist, &$low, $io): ?array {
        $c = fanboyCpuNow(fanboyChips(fanboyPaths(fanboySysRoot() ?: null)['hwmon']));
        $s = fanboyCpuSmooth($hist, $c, ($io['now'])());
        if ($s['smooth'] !== null) {
            $low = $low === null ? $s['smooth'] : min($low, $s['smooth']);
        }
        if ($c === null || $c >= FANBOY_STOP_CPU_MAX) {
            return ['source' => 'CPU', 'kind' => 'cpu', 'c' => $c, 'smooth' => $s['smooth'], 'limit' => FANBOY_STOP_CPU_MAX];
        }
        if ($low !== null && $s['smooth'] !== null && $s['smooth'] - $low >= FANBOY_STOP_CPU_RISE) {
            return ['source' => 'CPU', 'kind' => 'cpu', 'c' => $c, 'smooth' => $s['smooth'], 'limit' => $low + FANBOY_STOP_CPU_RISE];
        }
        return null;
    };
    $look();            // where it begins
    return $look;
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
    @unlink(fanboyStepNextFile());          // round 11: nothing of an earlier step counts here
    $io['step_id'] = is_string($step['id'] ?? null) ? $step['id'] : null;
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
            // round 11: in the pause after it, `next` — the output that follows (null on the last, or when the run ends)
            $io['note'] = function (array $p) use (&$step, $id): void {
                $i = array_search($id, $step['fans'], true);
                $next = ($p['phase'] ?? null) === 'pause' && empty($p['last']) && is_int($i) ? ($step['fans'][$i + 1] ?? null) : null;
                unset($p['last']);
                $step['progress'] = ['id' => $id] + $p + ['next' => $next];
                fanboyStepWrite($step);
            };
            $step['progress'] = null;
            if ($step['kind'] === 'hub_look') {
                $f = fanboyBoardRaw($board)['fans'][$id] ?? null;
                if (!is_array($f) || !fanboyFanOurs($f) || ($f['found'] ?? null) !== 'tach') {
                    $step['results'][$id] = ['result' => 'not_his'];
                    continue;
                }
                $r = fanboyHubLook($o, $f, $owner, $io, $key, $io['step_id']);
                $step['results'][$id] = $r;
                fanboyHubStore($id, $r, $board);
                if ($r['result'] === 'warm') {
                    $step['warm'] = $r['warm'];
                    throw new FanboyStepStop('warm');
                }
            } elseif ($step['kind'] === 'test_up') {
                $partial = null;
                try {
                    $r = fanboyTestUp($o, $owner, $io, $key, ($step['quiet'] ?? false) === true, $partial);
                } catch (Throwable $e) {
                    // round 11: however it ended — a stop, the page gone, too warm, a fan that didn't start again — an output
                    // that was at full long enough keeps what its looks saw (never nothing), then the stop goes on
                    if (is_array($partial)) {
                        $step['results'][$id] = fanboyUpStore($id, $o, $partial, $board, $io['step_id']);
                        $step['done'][] = $id;
                    }
                    throw $e;
                }
                $step['results'][$id] = $r['found'] === 'busy' ? $r : fanboyUpStore($id, $o, $r, $board, $io['step_id']);
            } else {
                $f = fanboyBoardRaw($board)['fans'][$id] ?? null;
                $tachs = is_array($f) ? array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')) : [];
                if (!is_array($f) || !fanboyFanOurs($f) || ($f['found'] ?? null) !== 'tach' || !$tachs) {
                    $step['results'][$id] = ['result' => 'not_his'];
                    continue;
                }
                // round 12: the standstill part for a fan that may be let stand — his own roles, and (the owner's decision,
                // 2026-11-15) a CPU cooler he steers on the owner's risk, with its own CPU guard; never a pump, never the BIOS's
                $cpuFan = fanboyCpuOwn($f);
                $standstill = in_array($f['role'] ?? null, FANBOY_ROLES_OURS, true) || $cpuFan;
                $partial = null;
                try {
                    $r = fanboyMeasure($o, $tachs, $owner, $io, $key, $standstill, $partial, $cpuFan);
                } catch (Throwable $e) {
                    if (is_array($partial)) {           // the table is complete: kept, whatever ended the standstill part
                        if (!array_key_exists('stops', $partial)) {
                            $partial['stand_cut'] = ['why' => $e instanceof FanboyStepStop ? $e->getMessage() : 'error', 'source' => null, 'c' => null, 'at' => time()];
                        }
                        fanboyMeasuredStore($id, $partial, $board);
                        $step['results'][$id] = $partial + ['partial' => true];
                    }
                    throw $e;
                }
                $step['results'][$id] = $r;
                if ($r['result'] === 'warm' && is_array($r['measured'] ?? null)) {
                    fanboyMeasuredStore($id, $r['measured'], $board);         // too warm in the standstill part: the table stays
                }
                if ($r['result'] === 'ignored') {
                    fanboyStoreUpdate(function (array $e) use ($id): array {
                        if (is_array($e['fans'][$id] ?? null)) {
                            $e['fans'][$id]['found'] = 'ignored';         // never steered: out of every zone
                            $e['fans'][$id]['why'] = 'taken_back';
                            $e = fanboyZonesWithout($e, $id);
                        }
                        return $e;
                    }, $board);
                }
                if ($r['result'] === 'ok' || $r['result'] === 'no_turn') {
                    fanboyMeasuredStore($id, $r, $board);
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
            $outIds = array_keys(fanboyOutputs(fanboyDetect(fanboySysRoot() ?: null)));
            $e = fanboyStoreUpdate(function (array $e) use ($step, $outIds): array {
                $e['fans'] = fanboyUpIgnored(is_array($e['fans'] ?? null) ? $e['fans'] : [], $step['results'], $outIds);
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
        if ($e instanceof FanboyQuietWarm) {
            $step['quiet_stop'] = $e->what;         // round 8: which temperature (or which fan didn't turn again)
        }
    } catch (Throwable $e) {
        $step['status'] = 'failed';
        $step['why'] = 'error';
        logLine('Fanboy: the step failed: ' . $e->getMessage());
    } finally {
        $back = fanboyGiveBack(null, 'step', $owner);       // nothing of this step held any more, whatever ended it
        @unlink(fanboyStepNextFile());                      // round 11: never left for the next run
        $step['current'] = null;
        fanboyStepWrite($step);
        logLine("Fanboy: setup step {$step['kind']} {$step['status']}" . ($step['why'] ? " ({$step['why']})" : '') . ' — ' . count($step['done']) . ' of ' . count($step['fans'])
            . ' outputs' . ($back ? ', ' . count($back) . ' given back at the end' : ''));
    }
    return $step;
}

/**
 * A test's verdict into the store (round 11: with the user's answer in the same write — a «weiter» that came while it
 * settled or paused is read here too): what the looks saw, `heard` applied (fanboyHeard()), `tested_at`; name, role,
 * place, count entered meanwhile stay. The result for the step's state: found as stored, `looked` what the looks said.
 */
function fanboyUpStore(string $id, array $o, array $r, array $board, ?string $stepId): array
{
    $late = fanboyStepNextAsked($id, $stepId);
    $heard = is_bool($r['heard'] ?? null) ? $r['heard'] : (is_bool($late['heard'] ?? null) ? $late['heard'] : null);
    $found = fanboyHeard($r['found'], $heard);
    fanboyStoreUpdate(function (array $e) use ($id, $o, $r, $found): array {
        $f = is_array($e['fans'][$id] ?? null) ? $e['fans'][$id] : [];
        $f = ['chip' => $o['chip'], 'address' => $o['address'], 'output' => $o['output'], 'tach' => $r['tachs'][0] ?? null,
              'tachs' => $r['tachs'], 'found' => $found, 'why' => $r['why'] ?? null, 'tested_at' => time()] + $f;
        $e['fans'][$id] = $f;
        // round 5: «doesn't obey» and «the other way round» are never steered — out of every zone; round 11: nor «nothing attached»
        return in_array($found, ['ignored', 'inverted', 'empty'], true) ? fanboyZonesWithout($e, $id) : $e;
    }, $board);
    return ['found' => $found, 'looked' => $r['found'], 'heard' => $heard] + $r;
}

/**
 * A measurement into the store: table, lowest steady value, start value, max rpm, when — round 12: and the standstill
 * part's keys when it ran (stops, floor_rpm, start_from_stop, kick_start, stops_safe; none: not tried — «unknown»)
 */
function fanboyMeasuredStore(string $id, array $r, array $board): void
{
    fanboyStoreUpdate(function (array $e) use ($id, $r): array {
        if (is_array($e['fans'][$id] ?? null)) {
            $m = null;
            if (($r['result'] ?? null) === 'ok') {
                $m = ['table' => $r['table'], 'min_pct' => $r['min_pct'], 'start_pct' => $r['start_pct'], 'max_rpm' => $r['max_rpm'], 'at' => time()];
                if (array_key_exists('stops', $r)) {
                    $m += array_intersect_key($r, array_flip(['stops', 'floor_rpm', 'start_from_stop', 'kick_start', 'stops_safe', 'start_tries']));
                } elseif (is_array($r['stand_cut'] ?? null)) {
                    $m['stand_cut'] = $r['stand_cut'];          // round 12 fix: the standstill part was cut — why, by what
                }
            }
            $e['fans'][$id]['measured'] = $m;
            $e['fans'][$id]['no_turn'] = ($r['result'] ?? null) === 'no_turn';
        }
        return $e;
    }, $board);
}

const FANBOY_HUB_LOOK_FOR = 60.0;       // round 14: the look for the eye — each question held up to a minute …
const FANBOY_HUB_STAND = 10.0;          // … and this long at 0 before «do all of them start again?»

/**
 * Round 14: the look for the eye at a connector with several fans (one reports, the others are only seen): the output at
 * the floor the control would use, held until the user answers «drehen alle?» (`step_next {fan, heard}` — heard true:
 * all turn) or a minute passed; then 0 for FANBOY_HUB_STAND s and the start value the control would use, held until
 * «laufen alle wieder an?» is answered. Guards as the quiet test's: too warm (and a CPU cooler's own: 70 °C, +15 °C, no
 * reading) → full at once, back. Afterwards exactly as before, with the restart check (the kick, else full + an alert).
 * Result: ok (both yes) · floor_up (not all turn) · not_safe (not all start again) · no_answer · warm · busy.
 */
function fanboyHubLook(array $o, array $f, array $owner, array $io, string $boardKey, ?string $stepId): array
{
    $plan = fanboyFanPlan(['alike' => false] + $f);          // the hub's margin, whatever «alike» says
    [$floor, $start] = [(int) $plan['floor'], (int) $plan['start']];
    $before = fanboyAllTachs();
    if (fanboyTake($o, $owner, $boardKey) === null) {
        return ['result' => 'busy'];
    }
    // a CPU cooler under cpu_own: its own guard, smoothed (round 12 fix: fanboyCpuGuard())
    $cpuGuard = fanboyCpuOwn($f) ? fanboyCpuGuard($io) : null;
    $warm = function () use ($o, $owner, $cpuGuard): ?array {
        $w = fanboyStepWarm(fanboyBoardRaw()) ?? ($cpuGuard !== null ? $cpuGuard() : null);
        if ($w !== null) {
            fanboySet($o['id'], FANBOY_FULL, $owner);
        }
        return $w;
    };
    $note = fn (array $p) => ($io['note'] ?? fn (array $p) => null)($p);
    // one question: the value held until the answer (true/false), a minute without one (null), too warm (['warm' => …])
    $ask = function (string $phase, int $pct) use ($o, $owner, $io, $note, $warm, $stepId): mixed {
        $note(['phase' => $phase, 'pct' => $pct, 'until' => time() + (int) FANBOY_HUB_LOOK_FOR]);
        fanboySet($o['id'], (int) round($pct * 2.55), $owner);
        $t0 = ($io['now'])();
        while (($io['now'])() - $t0 < FANBOY_HUB_LOOK_FOR) {
            fanboyStepWait($io, 0.5);
            if (($w = $warm()) !== null) {
                return ['warm' => $w];
            }
            $a = fanboyStepNextAsked($o['id'], $stepId);
            if ($a !== null && is_bool($a['heard'])) {
                return $a['heard'];
            }
        }
        return null;
    };
    $out = ['floor' => $floor, 'start' => $start];
    $stood = false;
    try {
        $turn = $ask('hub_turn', $floor);
        if (is_array($turn)) {
            return ['result' => 'warm', 'warm' => $turn['warm']] + $out;
        }
        if ($turn !== true) {
            return ['result' => $turn === false ? 'floor_up' : 'no_answer'] + $out;
        }
        $note(['phase' => 'hub_stop', 'until' => time() + (int) FANBOY_HUB_STAND]);
        $stood = true;
        fanboySet($o['id'], 0, $owner);
        $t1 = ($io['now'])();
        while (($io['now'])() - $t1 < FANBOY_HUB_STAND) {
            fanboyStepWait($io, 0.5);
            if (($w = $warm()) !== null) {
                return ['result' => 'warm', 'warm' => $w] + $out;
            }
        }
        $restart = $ask('hub_start', $start);
        if (is_array($restart)) {
            return ['result' => 'warm', 'warm' => $restart['warm']] + $out;
        }
        fanboySet($o['id'], FANBOY_FULL, $owner);
        return ['result' => $restart === true ? 'ok' : ($restart === false ? 'not_safe' : 'no_answer')] + $out;
    } catch (Throwable $e) {
        fanboySet($o['id'], FANBOY_FULL, $owner);
        throw $e;
    } finally {
        fanboyGiveBack([$o['id']], 'step', $owner);
        if ($stood) {
            fanboyQuietRestart($before, [$o['id']], $io, $owner, $boardKey);
        }
    }
}

/** Round 14: the action `hub_look {fan}` — the look for the eye as a setup step (the page answers with step_next {fan, heard}) */
function fanboyHubLookStart(array $r): array
{
    return fanboyStepStart('hub_look', [textField($r, 'fan')]);
}

/** Round 14: what the look found, into the store — ok: hub_ok; floor_up: the floor a margin higher; not_safe: never stand */
function fanboyHubStore(string $id, array $r, array $board): void
{
    if (!in_array($r['result'] ?? null, ['ok', 'floor_up', 'not_safe'], true)) {
        return;
    }
    fanboyStoreUpdate(function (array $e) use ($id, $r): array {
        if (!is_array($e['fans'][$id] ?? null)) {
            return $e;
        }
        $f = $e['fans'][$id];
        if ($r['result'] === 'ok') {
            $f['hub_ok'] = ['at' => time()];
            unset($f['hub_not_safe']);
        } elseif ($r['result'] === 'floor_up') {
            $f['hub_extra'] = min(FANBOY_HUB_EXTRA_MAX, (is_int($f['hub_extra'] ?? null) ? $f['hub_extra'] : 0) + FANBOY_HUB_MARGIN);
            unset($f['hub_ok']);
        } else {
            $f['hub_not_safe'] = ['at' => time()];
            unset($f['hub_ok']);
        }
        $e['fans'][$id] = $f;
        return $e;
    }, $board);
}

/** The step process: `php agent.php job fanboy-step` — the step RUN_DIR/fanboy/step.json describes, once */
function fanboyStepMain(): int
{
    $lock = @fopen(fanboyRunDir() . '/step.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $step = readJson(fanboyStepFile());
    if (!is_array($step) || ($step['status'] ?? '') !== 'starting' || !in_array($step['kind'] ?? '', ['test_up', 'measure', 'hub_look'], true) || !is_array($step['fans'] ?? null)) {
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
function fanboyStepStart(string $kind, array $ids, bool $quiet = false): array
{
    if (fanboyStepRunning()) {
        throw new Problem('fanboy_step_running');
    }
    if (function_exists('fanboyShowRunning') && fanboyShowRunning()) {
        throw new Problem('fanboy_show_running');
    }
    // round 8 (G1): the quiet test never starts while anything is warm (the CPU at 70 °C, a place at its «full speed at»)
    if ($kind === 'test_up' && $quiet && ($warm = fanboyQuietWarm()) !== null) {
        throw new Problem('fanboy_quiet_warm', ['what' => $warm['what'], 'c' => (int) round($warm['c'] ?? 0)]);
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
        // round 14: the look for the eye — one of his (a CPU cooler only under cpu_own, never a pump) with several fans
        // on it and a tach (the restart check needs one)
        if ($kind === 'hub_look' && (!is_array($f) || !fanboyFanOurs($f) || ($f['found'] ?? null) !== 'tach')) {
            throw new Problem('fanboy_not_his', ['fan' => $id]);
        }
        if ($kind === 'hub_look' && (!is_int($f['count'] ?? null) || $f['count'] < 2 || count($ids) !== 1)) {
            throw new Problem('fanboy_hub_one');
        }
    }
    $step = ['id' => bin2hex(random_bytes(6)), 'kind' => $kind, 'fans' => array_values($ids), 'status' => 'starting', 'pid' => null, 'start' => null,
             'done' => [], 'results' => [], 'why' => null, 'begun' => time(), 'quiet' => $kind === 'test_up' && $quiet];
    fanboyStepAliveTouch();
    fanboyStepWrite($step);
    fanboyLaunch('fanboy-step');
    return ['ok' => true, 'step' => $step];
}

/**
 * Round 9 (H4): «Alles stoppen» — a running setup step and a show asked to end (each gives everything back itself, with
 * the kick for a fan that doesn't start), and every output he set to full for safety back to its state before as soon as
 * nothing else runs (the agent's tick starts that: RUN_DIR/fanboy/stop-all)
 */
function fanboyStopAll(): array
{
    fanboyStepStopAction();
    $show = fanboyShowRead();
    if ($show !== null && in_array($show['status'], ['starting', 'running'], true)) {
        fanboyShowStop(['fan' => $show['fan']]);
    }
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyRunDir() . '/stop-all', (string) time(), 0600, 0, 0);
    fanboyStopAllTick();
    logLine('Fanboy: «stop everything» on the user\'s click');
    return ['ok' => true, 'state' => fanboyScan()];
}

/** The tick's half of «Alles stoppen»: once nothing of the setup runs, the safety fulls back (one launch), the mark gone */
function fanboyStopAllTick(): void
{
    $f = fanboyRunDir() . '/stop-all';
    if (!is_file($f)) {
        return;
    }
    if (time() - (int) @file_get_contents($f) > 300) {
        @unlink($f);                    // 5 minutes: it was meant for then, not for later
        return;
    }
    if (fanboyStepRunning() || fanboyShowRunning() || fanboyRestoreRunning()) {
        return;
    }
    @unlink($f);
    if (fanboySafetyFull()) {
        fanboyRestoreStart(['all' => true, 'fan' => '']);
    }
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
/**
 * Round 8 (the owner: test and assign in ONE step): an offered output's entry in the store, made when it has none yet —
 * so its name, role or «nothing attached» can be saved before or while it is tested ($outputs: what is there now)
 */
function fanboyFanEnsure(array $e, string $id, array $outputs): array
{
    if (!is_array($e['fans'][$id] ?? null)) {
        $o = $outputs[$id] ?? null;
        if ($o === null) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        $e['fans'][$id] = ['chip' => $o['chip'], 'address' => $o['address'], 'output' => $o['output'], 'tach' => null, 'tachs' => []];
    }
    return $e;
}

/** Round 8: «Wo sitzt er?» — a fan's name alone, saved at once (any offered output, tested or not, during its test too) */
function fanboyFanName(array $r): array
{
    $id = textField($r, 'fan');
    if (!array_key_exists('name', $r)) {
        throw new Problem('missing_field', ['field' => 'name']);       // an empty name clears it; none sent changes nothing
    }
    $name = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', optText($r, 'name')));
    if (mb_strlen($name) > 40) {
        throw new Problem('fanboy_bad_name');
    }
    $outputs = fanboyOutputs(fanboyDetect());
    $e = fanboyStoreUpdate(function (array $e) use ($id, $name, $outputs): array {
        $e = fanboyFanEnsure($e, $id, $outputs);
        $e['fans'][$id]['name'] = $name;
        return $e;
    });
    return ['ok' => true, 'fan' => $e['fans'][$id]];
}

function fanboyAnswer(array $r): array
{
    $id = textField($r, 'fan');
    $louder = boolField($r, 'louder');
    $outputs = fanboyOutputs(fanboyDetect());
    $e = fanboyStoreUpdate(function (array $e) use ($id, $louder, $outputs): array {
        // round 8: «nothing attached» may be said before the test too (the merged step's role choice)
        if (!$louder && !is_array($e['fans'][$id] ?? null)) {
            $e = fanboyFanEnsure($e, $id, $outputs);
        }
        $found = fanboyFound($e['fans'][$id] ?? null);
        if (!is_array($e['fans'][$id] ?? null) || !(in_array($found, ['ask', 'silent', 'empty', 'unclear'], true) || ($found === null && !$louder))) {
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
    // round 12 (the owner, 2026-11-15): a CPU cooler he steers may stand — only with its own tick, apart from cpu_confirm
    $cpuOffConfirm = optBool($r, 'cpu_off_confirm', false);
    // round 3: how many fans hang on this one connector (a Y-cable, a hub) — 1 … 8; not sent: as it was
    $count = optInt($r, 'count', null, 1);
    // round 14: several fans on it the same model? true / false (different or unknown) / null (not said); not sent: as it was
    $hasAlike = array_key_exists('alike', $r);
    $alike = $hasAlike && $r['alike'] !== null ? boolField($r, 'alike') : null;
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
    // round 8 (the owner: test and assign in one step): a role may be given before or while the output is tested — not to
    // one found empty, «doesn't obey» or «the other way round»; name and place only when sent (each field saves alone)
    $outputs = fanboyOutputs(fanboyDetect());
    $hasName = array_key_exists('name', $r);
    $hasPlace = array_key_exists('place', $r);
    $e = fanboyStoreUpdate(function (array $e) use ($id, $role, $name, $place, $offOk, $count, $cpuOwn, $outputs, $hasName, $hasPlace, $cpuOffConfirm, $hasAlike, $alike): array {
        $e = fanboyFanEnsure($e, $id, $outputs);
        $f = $e['fans'][$id];
        // round 9 (H6): «Fanboy steers the CPU cooler» never in the same click that makes it the CPU cooler — first the role,
        // then the own-risk choice with its tick
        if ($cpuOwn === true && ($f['role'] ?? null) !== 'cpu') {
            throw new Problem('fanboy_cpu_role_first');
        }
        if (!in_array(fanboyFound($f), [null, 'ask', 'tach', 'silent', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        if ($role !== 'cpu' || $cpuOwn === false) {
            unset($f['cpu_own']);
        } elseif ($cpuOwn === true && !fanboyCpuOwn($f)) {
            $f['cpu_own'] = ['at' => time()];
        }
        if ($offOk && $role === 'pump') {
            throw new Problem('fanboy_off_pump');                 // a pump never stands
        }
        if ($offOk && $role === 'cpu' && !fanboyCpuOwn($f)) {
            throw new Problem('fanboy_cpu_off_bios');             // a CPU cooler the BIOS steers is not his to stop
        }
        if ($offOk && !is_array($f['measured'] ?? null)) {
            throw new Problem('fanboy_off_needs_measure');        // «off» only for a fan measured with feedback
        }
        // round 12: a CPU cooler may stand only when its measuring found it stops and starts again safely, and with the
        // second tick (kept with its time, like cpu_own — another field's save keeps it)
        $cpuOff = $offOk && $role === 'cpu' && fanboyCpuOwn($f);
        if ($cpuOff && ($f['measured']['stops_safe'] ?? null) !== true) {
            throw new Problem('fanboy_off_not_safe');
        }
        if ($cpuOff && !$cpuOffConfirm && !is_int($f['cpu_off']['at'] ?? null)) {
            throw new Problem('fanboy_cpu_off_confirm');
        }
        if ($cpuOff) {
            $f['cpu_off'] = is_int($f['cpu_off']['at'] ?? null) ? $f['cpu_off'] : ['at' => time()];
        } else {
            unset($f['cpu_off']);
        }
        $f['role'] = $role;
        if ($hasName) {
            $f['name'] = $name;
        }
        if ($hasPlace) {
            $f['place'] = $place !== '' ? $place : null;
        }
        $f['off_ok'] = $offOk && (in_array($role, FANBOY_ROLES_OURS, true) || $cpuOff);
        // round 14: another number of fans on it, or another «alike»: what the look for the eye found counts no more
        $hubChanged = ($count !== null && $count !== (is_int($f['count'] ?? null) ? $f['count'] : 1)) || ($hasAlike && $alike !== ($f['alike'] ?? null));
        if ($count !== null) {
            $f['count'] = $count;
        }
        if ($hasAlike) {
            if ($alike === null) {
                unset($f['alike']);
            } else {
                $f['alike'] = $alike;
            }
        }
        if ($hubChanged) {
            unset($f['hub_ok'], $f['hub_not_safe'], $f['hub_extra']);
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
        $target = fanboyTargetCheck($z['target'] ?? ['preset' => 'normal'], $unit, 'target');
        // round 13: a curve of its own per kind — only kinds the zone has, each checked as the zone's curve; a kind not
        // sent stays derived from the zone's curve
        $curves = [];
        $rawCurves = $z['curves'] ?? [];
        if (!is_array($rawCurves) || ($rawCurves !== [] && array_is_list($rawCurves))) {
            throw new Problem('fanboy_bad_zones', ['field' => 'curves']);
        }
        foreach ($rawCurves as $kind => $cv) {
            if (!is_string($kind) || !in_array($kind, array_column($sources, 'kind'), true)) {
                throw new Problem('fanboy_bad_zones', ['field' => 'curves']);
            }
            $curves[$kind] = fanboyTargetCheck($cv, $unit, 'curves');
        }
        // round 4: how he reacts — «balanced» (the default) or «quick»; read strictly, absent = balanced
        $react = $z['react'] ?? 'balanced';
        if (!in_array($react, FANBOY_REACTS, true)) {
            throw new Problem('fanboy_bad_zones', ['field' => 'react']);
        }
        $out[$z['id']] = ['id' => $z['id'], 'name' => $name, 'fans' => $z['fans'], 'sources' => array_values($sources),
            'target' => $target, 'unit' => $unit, 'low' => fanboyNum($z, 'low', 0, 110), 'high' => fanboyNum($z, 'high', 0, 120), 'react' => $react,
            'kinds' => $kinds ?: new stdClass(), 'curves' => $curves ?: new stdClass()];
    }
    return array_values($out);
}

/**
 * A curve as the page sends it — the zone's (`target`) or one kind's (round 13, `curves.<kind>`): 2–8 points [°C 0–120,
 * value 0–100 % or 0–10000 rpm], or a preset (percent zones only). $field: the refusal's field.
 */
function fanboyTargetCheck(mixed $t, string $unit, string $field): array
{
    if (!is_array($t)) {
        throw new Problem('fanboy_bad_zones', ['field' => $field]);
    }
    if (isset($t['points'])) {
        if (!is_array($t['points']) || !array_is_list($t['points']) || count($t['points']) < 2 || count($t['points']) > 8) {
            throw new Problem('fanboy_bad_zones', ['field' => $field === 'target' ? 'points' : $field]);
        }
        $pts = [];
        foreach ($t['points'] as $p) {
            if (!is_array($p) || !array_is_list($p) || count($p) !== 2) {
                throw new Problem('fanboy_bad_zones', ['field' => $field === 'target' ? 'points' : $field]);
            }
            $tc = fanboyNum(['t' => $p[0]], 't', 0, 120);
            $v = fanboyNum(['v' => $p[1]], 'v', 0, $unit === 'pct' ? 100 : 10000);
            $pts[] = [$tc, $v];
        }
        return ['points' => $pts];
    }
    if (!in_array($t['preset'] ?? null, FANBOY_PRESETS, true) || $unit === 'rpm') {
        throw new Problem('fanboy_bad_zones', ['field' => $field === 'target' ? 'preset' : $field]);
    }
    return ['preset' => $t['preset']];
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
            // round 13: as the control: every kind's curve, its warmest source and what it asks for, who leads
            'curves' => fanboyZoneKindCurves($z), 'by_kind' => ($z['by_kind'] ?? []) ?: new stdClass(), 'leader' => $z['leader'] ?? null,
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
/**
 * Round 12 fix (the owner's SSD pool and NVMe pools were in no zone): the disks, SSDs and NVMe he knows that none of the
 * zones has (FANBOY_NET_KINDS — the net watches the same) with their names and temperatures ([] when every one is in a zone)
 */
function fanboyUncovered(array $scan, ?array $settings): array
{
    $in = [];
    foreach (is_array($settings['zones'] ?? null) ? $settings['zones'] : [] as $z) {
        foreach (is_array($z['sources'] ?? null) ? $z['sources'] : [] as $src) {
            if (is_array($src) && is_string($src['id'] ?? null)) {
                $in[$src['id']] = true;
            }
        }
    }
    $sources = fanboySources($scan);
    $names = fanboySourceNames($sources, function_exists('officeNotifyLang') ? officeNotifyLang() : 'en');
    $out = [];
    foreach ($sources as $src) {
        if (!isset($in[$src['id']]) && in_array($src['kind'], FANBOY_NET_KINDS, true)) {
            $out[] = ['kind' => $src['kind'], 'id' => $src['id'], 'name' => $names[$src['id']] ?? $src['id'], 'c' => $src['c'], 'asleep' => $src['asleep']];
        }
    }
    return $out;
}

function fanboyTakeOver(array $r = []): array
{
    $scan = fanboyDetect();
    if ($scan['situation']['code'] === 'other') {
        throw new Problem('fanboy_other', ['who' => implode(', ', $scan['situation']['names'] ?? [])]);
    }
    if (fanboyStepRunning()) {
        throw new Problem('fanboy_step_running');
    }
    $settings = fanboyBoardRaw($scan['board']) ?? [];
    $plan = fanboyControlNew($settings, '', ['pid' => 0]);
    if (!$plan['zones']) {
        throw new Problem('fanboy_no_zones');
    }
    // round 12 fix: fans that may stand while heat sources are in no zone — only with an explicit yes
    $uncovered = fanboyUncovered($scan, $settings);
    if ($uncovered && array_filter($plan['fans'], fn ($f) => $f['off_ok'] === true) && !optBool($r, 'uncovered_ok', false)) {
        throw new Problem('fanboy_uncovered', ['sources' => implode(', ', array_column($uncovered, 'name'))]);
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
    $outputs = fanboyOutputs($scan);
    $e = fanboyStoreUpdate(function (array $e) use ($id, $back, $outputs): array {
        $e = fanboyFanEnsure($e, $id, $outputs);           // round 8: on the test's card, before it is tested too
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
    if ($mm !== 0 && ($mm < 40 || $mm > 200)) {
        throw new Problem('fanboy_bad_look', ['field' => 'size']);
    }
    if ($blades !== 0 && ($blades < FANBOY_BLADES_MIN || $blades > FANBOY_BLADES_MAX)) {
        throw new Problem('fanboy_bad_look', ['field' => 'blades']);
    }
    $colour = optText($r, 'colour');
    if ($colour !== '' && !in_array($colour, FANBOY_COLOURS, true)) {
        throw new Problem('fanboy_bad_look', ['field' => 'colour']);
    }
    // round 12 (the owner): the model only proposes — the frame's colour, the wheel's colour and the blades' style are
    // the user's, each a key of the page's lists (the agent checks the shape only); '' or null clears one
    $own = [];
    foreach (['frame', 'wheel', 'style'] as $k) {
        if (array_key_exists($k, $r)) {
            $v = $r[$k];
            if ($v !== null && (!is_string($v) || ($v !== '' && !preg_match(FANBOY_LOOK_KEY_RE, $v)))) {
                throw new Problem('fanboy_bad_look', ['field' => $k]);
            }
            $own[$k] = $v === '' ? null : $v;
        }
    }
    $outputs = fanboyOutputs(fanboyDetect());
    $e = fanboyStoreUpdate(function (array $e) use ($id, $model, $mm, $blades, $colour, $r, $outputs, $own): array {
        $e = fanboyFanEnsure($e, $id, $outputs);           // round 8: on the test's card, before or while it is tested too
        $f = $e['fans'][$id];
        if (!in_array(fanboyFound($f), [null, 'ask', 'tach', 'silent', 'unclear'], true)) {
            throw new Problem('fanboy_no_output', ['fan' => $id]);
        }
        // round 12: each field saves alone (not sent: as it was) — the blades' number with a model too (it overrides the model's)
        $was = is_array($f['model'] ?? null) ? $f['model'] : ['id' => null, 'mm' => null, 'blades' => null];
        foreach (['model' => ['id', $model !== '' ? $model : null], 'mm' => ['mm', $mm ?: null], 'blades' => ['blades', $blades ?: null]] as $field => [$key, $value]) {
            if (array_key_exists($field, $r)) {
                $was[$key] = $value;
                $f['model'] = $was;
            }
        }
        if ($colour !== '') {
            $f['colour'] = $colour;
        }
        foreach ($own as $k => $v) {
            if ($v === null) {
                unset($f[$k]);
            } else {
                $f[$k] = $v;
            }
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
