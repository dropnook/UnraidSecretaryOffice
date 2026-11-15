<?php
declare(strict_types=1);

/*
 * Round 6, part 4 (the owner, 2026-11-15): «Show me this fan» — one output to 100 % for FANBOY_SHOW_FOR seconds, then
 * back exactly as it was (value and mode), so the user hears and sees which fan hangs on it. The test step's primitive
 * and its safety: only ever UP («more air never harms»), through the write helper's journal (the state before taken down
 * first, the holder a process of its own with a beat), given back exactly by the holder itself whatever ends it — the
 * agent's tick or the guard's cron line gives back when it can't (holder gone, hung). One at a time.
 *
 * Two ways, decided at the click:
 *   · an output the control holds (Fanboy steers it): the control shows it — its value to 100 % for the time, then the
 *     zone's curve takes it again (what «as it was» means for a fan he steers); the trim waits meanwhile
 *   · any other output: a process of its own (`php agent.php job fanboy-show`, a session of its own) holds it as a «step»
 *     in the journal, writes 100 %, waits, gives back exactly the state before, reads it back
 * Refused (each with its words): another controller steers, a setup step runs (test or measuring), a show runs, an alarm
 * holds (the control's), the output marked «doesn't obey», «the other way round» or «the driver takes no values».
 *
 *   RUN_DIR/fanboy/show.json   {id, fan, via: step|control, status: starting|running|done|failed, why, at, until, pid, start, ok}
 */

const FANBOY_SHOW_FOR = 5.0;            // seconds at 100 %
const FANBOY_SHOW_STALE = 20;           // seconds: a show still «starting» or «running» after this is over (its process gone)

function fanboyShowFile(): string
{
    return fanboyRunDir() . '/show.json';
}

/** The show as kept — one that says it runs but is older than FANBOY_SHOW_STALE (or its process gone) is over */
function fanboyShowRead(?int $wall = null): ?array
{
    $s = readJson(fanboyShowFile());
    if (!is_array($s) || !is_string($s['fan'] ?? null) || !is_string($s['status'] ?? null)) {
        return null;
    }
    if (in_array($s['status'], ['starting', 'running'], true)) {
        $age = ($wall ?? time()) - (int) ($s['at'] ?? 0);
        $dead = ($s['via'] ?? '') === 'step' && $s['status'] === 'running' && fanboyOwnerState(['pid' => $s['pid'] ?? 0, 'start' => $s['start'] ?? null]) === 'dead';
        if ($age > FANBOY_SHOW_STALE || $dead) {
            $s['status'] = 'failed';
            $s['why'] = 'interrupted';
        }
    }
    return $s;
}

function fanboyShowRunning(?int $wall = null): bool
{
    $s = fanboyShowRead($wall);
    return $s !== null && in_array($s['status'], ['starting', 'running'], true);
}

function fanboyShowWrite(array $s): void
{
    @mkdir(fanboyRunDir(), 0700, true);
    $s['at'] = time();
    writeAtomic(fanboyShowFile(), jsonEncode($s), 0644, 0, 0);
}

/**
 * The action fanboy.show {fan}: checked against what is there now, then handed to the control (it holds the output) or to
 * a process of its own. Returns at once — the page follows the state.
 */
function fanboyShowStart(array $r): array
{
    $id = textField($r, 'fan');
    if (fanboyShowRunning()) {
        throw new Problem('fanboy_show_running');
    }
    if (fanboyStepRunning()) {
        throw new Problem('fanboy_step_running');
    }
    $scan = fanboyDetect();
    if ($scan['situation']['code'] === 'other') {
        throw new Problem('fanboy_other', ['who' => implode(', ', $scan['situation']['names'] ?? [])]);
    }
    $o = fanboyOutputs($scan)[$id] ?? null;
    if ($o === null) {
        throw new Problem('fanboy_no_output', ['fan' => $id]);
    }
    $status = readJson(fanboyStatusFile());
    $controlRuns = fanboyControlPid() !== null;
    if ($controlRuns && is_array($status['alarm'] ?? null)) {
        throw new Problem('fanboy_show_alarm');
    }
    $f = (fanboyBoardRaw($scan['board']) ?? [])['fans'][$id] ?? null;
    $refuses = $controlRuns && is_array($status['refuses'] ?? null) && in_array($id, $status['refuses'], true);
    if ((is_array($f) && in_array($f['found'] ?? null, ['ignored', 'inverted'], true)) || $refuses) {
        throw new Problem('fanboy_show_not');
    }
    $owner = fanboyJournal()['outputs'][$id]['owner'] ?? null;
    $byControl = $controlRuns && is_array($owner) && ($owner['kind'] ?? '') === 'control' && fanboyOwnerState($owner) !== 'dead';
    if (!$byControl && is_array($owner) && fanboyOwnerState($owner) !== 'dead') {
        throw new Problem('fanboy_busy');               // someone of his holds it (a give-back on its way): a moment later
    }
    $show = ['id' => bin2hex(random_bytes(6)), 'fan' => $id, 'via' => $byControl ? 'control' : 'step', 'status' => 'starting', 'why' => null,
        'until' => null, 'pid' => null, 'start' => null, 'ok' => null, 'begun' => time(),
        'tachs' => is_array($f) ? array_values(array_filter(is_array($f['tachs'] ?? null) ? $f['tachs'] : [], 'is_int')) : []];
    fanboyShowWrite($show);
    if (!$byControl) {
        fanboyLaunch('fanboy-show');
    }
    logLine("Fanboy: «show me this fan» for $id on the user's click (" . ($byControl ? 'by the control' : 'a process of its own') . ')');
    return ['ok' => true, 'show' => $show, 'state' => fanboyScan()];
}

/**
 * The show's process: `php agent.php job fanboy-show` — the show RUN_DIR/fanboy/show.json describes, once. $io: the tests'
 * clock and sleep (now, sleep); the real ones otherwise.
 */
function fanboyShowMain(?array $io = null): int
{
    @mkdir(fanboyRunDir(), 0700, true);
    $lock = @fopen(fanboyRunDir() . '/show.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    $show = readJson(fanboyShowFile());
    if (!is_array($show) || ($show['status'] ?? '') !== 'starting' || ($show['via'] ?? '') !== 'step' || !is_string($show['fan'] ?? null)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return 0;
    }
    $stop = ['now' => false];
    if ($io === null) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT, SIGHUP] as $sig) {
            pcntl_signal($sig, function () use (&$stop) { $stop['now'] = true; });
        }
    }
    $io ??= ['now' => fn (): float => fanboyMono(), 'sleep' => function (float $s): void { usleep((int) round($s * 1e6)); }];
    $owner = fanboyMe('step');
    $id = $show['fan'];
    $board = fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi']);
    fanboyBeat();
    $back = [];
    try {
        $o = fanboyOutputs(fanboyDetect(fanboySysRoot() ?: null))[$id] ?? null;
        if ($o === null) {
            $show['status'] = 'failed';
            $show['why'] = 'not_there';
            return 0;
        }
        // its tach inputs: the journal notes how fast it ran before (round 5's check after a give-back)
        $o['paired'] = is_array($show['tachs'] ?? null) ? $show['tachs'] : [];
        if (fanboyTake($o, $owner, fanboyBoardKey($board)) === null || fanboySet($id, FANBOY_FULL, $owner) === null) {
            $show['status'] = 'failed';
            $show['why'] = 'busy';
            return 0;
        }
        $show['pid'] = $owner['pid'];
        $show['start'] = $owner['start'];
        $show['status'] = 'running';
        $show['until'] = time() + (int) FANBOY_SHOW_FOR;
        fanboyShowWrite($show);
        $t0 = ($io['now'])();
        while (!$stop['now'] && ($io['now'])() - $t0 < FANBOY_SHOW_FOR) {
            ($io['sleep'])(0.2);
            fanboyBeat();
        }
        $show['status'] = 'done';
    } catch (Throwable $e) {
        $show['status'] = 'failed';
        $show['why'] = 'error';
        logLine('Fanboy: «show me this fan» failed: ' . $e->getMessage());
    } finally {
        $back = fanboyGiveBack([$id], 'show', $owner);         // exactly the state before, read back — whatever ended it
        $show['ok'] = $back ? (bool) ($back[$id]['ok'] ?? false) : null;
        if ($back && !($back[$id]['ok'] ?? false)) {
            $show['why'] = 'give_back';
        }
        fanboyShowWrite($show);
        logLine("Fanboy: «show me this fan» $id {$show['status']}" . ($show['why'] ? " ({$show['why']})" : '') . ($back ? ', given back' . (($back[$id]['ok'] ?? false) ? ' as it was' : ' — NOT read back as before') : ''));
        @unlink(fanboyRunDir() . '/beat-' . getmypid());
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return 0;
}

/**
 * The control's side (each round): a show asked of it for an output it holds — taken up, its end noted. Returns the
 * output id to hold at 100 % this round, null for none.
 */
function fanboyShowControl(array &$c, float $now): ?string
{
    if (isset($c['show']) && $now >= $c['show']['until']) {
        $s = readJson(fanboyShowFile());
        if (is_array($s) && ($s['id'] ?? null) === $c['show']['req']) {
            $s['status'] = 'done';
            $s['ok'] = true;
            fanboyShowWrite($s);
        }
        $id = $c['show']['fan'];
        if (isset($c['fans'][$id])) {
            $c['fans'][$id]['changed'] = $now;          // the curve takes it again now (written by fanboyApply())
        }
        unset($c['show']);
        return null;
    }
    if (isset($c['show'])) {
        return ($c['fans'][$c['show']['fan']]['taken'] ?? false) === true ? $c['show']['fan'] : null;
    }
    if (!is_file(fanboyShowFile())) {
        return null;
    }
    $s = readJson(fanboyShowFile());
    if (!is_array($s) || ($s['via'] ?? '') !== 'control' || ($s['status'] ?? '') !== 'starting' || !is_string($s['fan'] ?? null)
        || time() - (int) ($s['at'] ?? 0) > FANBOY_SHOW_STALE) {
        return null;
    }
    $id = $s['fan'];
    if (!($c['fans'][$id]['taken'] ?? false) || $c['alarm'] !== null) {
        $s['status'] = 'failed';
        $s['why'] = $c['alarm'] !== null ? 'alarm' : 'busy';
        fanboyShowWrite($s);
        return null;
    }
    $c['show'] = ['fan' => $id, 'req' => $s['id'] ?? null, 'until' => $now + FANBOY_SHOW_FOR];
    $s['status'] = 'running';
    $s['until'] = time() + (int) FANBOY_SHOW_FOR;
    $s['pid'] = $c['owner']['pid'] ?? null;
    fanboyShowWrite($s);
    fanboyNote($c, 'show', ['fan' => fanboyFanLabel($c['fans'][$id])]);
    return $id;
}

/** The control holds a fan at 100 % while it is shown: written once, the trim and the read-back wait (changed = null) */
function fanboyShowFull(array &$c, string $id): void
{
    if ($c['fans'][$id]['value'] !== FANBOY_FULL && fanboySet($id, FANBOY_FULL, $c['owner']) !== null) {
        $c['fans'][$id]['value'] = FANBOY_FULL;
    }
    $c['fans'][$id]['changed'] = null;
}
