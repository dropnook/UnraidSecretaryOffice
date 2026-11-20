<?php
declare(strict_types=1);

/*
 * Fanboy round 6 — five features the owner released on 2026-11-15, each against the fake hwmon tree (helpers from
 * tests/fanboy-control-tests.php: fanboyT(), the responder, the tests' clock):
 *   1 the fans he doesn't steer, watched (agent/desks/fanboy-watch.php) · 4 «Show me this fan» (fanboy-show.php) ·
 *   5 old disk temperatures (fanboyDiskAllowance()) · 2 the BMC's fan thresholds read (fanboy-ipmi.php) ·
 *   3 an NVIDIA card's temperature as a source (fanboy-gpu.php)
 */

/** One look of the watch on the tests' clocks ($now monotonic, $wall unix — both moved by $step each) */
function fanboyR6Watch(float &$now, int &$wall, int $looks = 1, float $step = 5.0): array
{
    $s = [];
    for ($i = 0; $i < $looks; $i++) {
        $s = fanboyIdleWatch($now, $wall);
        $now += $step;
        $wall += (int) $step;
    }
    return $s;
}

/** A tach input of the fake ITE chip set to $rpm (the watch only reads: no responder needed) */
function fanboyR6Tach(array $t, int $n, int|string $rpm): void
{
    file_put_contents("{$t['hw']}/fan{$n}_input", "$rpm\n");
}

function fanboyR6TachId(int $n): string
{
    return fanboyTachId(FANBOY_T_CHIP, FANBOY_T_ADDR, $n);
}

/**
 * Part 1: a fan seen turning that stands while everything is cool — learned «stops when cool», kept in his data, a log
 * line, nothing told; while something is warm — after 2 minutes ONE notification naming it as the page does, over when it
 * turns again, told again only after it turned 2 minutes; a restart (the watch called anew) tells nothing twice
 */
function testFanboyR6WatchLearn(): void
{
    $t = fanboyT('r6watch');
    try {
        fanboyTDisks($t, 35, 33, 34);
        [$now, $wall] = [1000.0, 1_800_000_000];
        $s = fanboyR6Watch($now, $wall);
        same('fanboy r6 watch: before any setup every turning tach input is watched — fan 4 turns', 'turning', $s['tachs'][fanboyR6TachId(4)]['state'] ?? null);
        same('fanboy r6 watch: … one never seen turning (fan 2 at 0 from the start) is an empty connector, never «stands»', 'never', $s['tachs'][fanboyR6TachId(2)]['state'] ?? null);
        check('fanboy r6 watch: … seeing it turn is kept in his data (a boot later it is still watched)',
            is_int((readJson(fanboyWatchFile())['boards'][FANBOY_T_KEY]['tachs'][fanboyR6TachId(4)]['seen'] ?? null)));
        // everything cool: it stands — learned, never told
        fanboyR6Tach($t, 4, 0);
        $s = fanboyR6Watch($now, $wall, 5);
        $kept = readJson(fanboyWatchFile())['boards'][FANBOY_T_KEY]['tachs'][fanboyR6TachId(4)] ?? [];
        same('fanboy r6 watch: standing while everything is cool — «stops when cool», learned per tach in his data, no notification',
            ['stops_cool', true, []], [$s['tachs'][fanboyR6TachId(4)]['state'], is_int($kept['quiet_stop'] ?? null), fanboyTNotified($t)]);
        // something warm (a disk at 50 °C, over the hard disks' warning 45): standing 2 minutes → one alert naming it
        fanboyTDisks($t, 50, 33, 34);
        $s = fanboyR6Watch($now, $wall, 20);
        same('fanboy r6 watch: standing while a disk is warm — not yet after 100 s', ['stands_warm', []], [$s['tachs'][fanboyR6TachId(4)]['state'], fanboyTNotified($t)]);
        $s = fanboyR6Watch($now, $wall, 5);
        same('fanboy r6 watch: … after 2 minutes ONE alert, its subject naming the fan as the page does', ['alarm', ['Fanboy: Fan input 4 stands while it is warm']],
            [$s['tachs'][fanboyR6TachId(4)]['state'], fanboyR5Subjects($t)]);
        check('fanboy r6 watch: … the text names the warm place as the page does (Parity at 50 °C)', str_contains(implode("\n", fanboyTNotified($t)), 'Parity is at 50'));
        // the agent restarts (the watch is called anew: its clocks and what it told are in RAM): nothing twice
        $s = fanboyR6Watch($now, $wall, 30);
        same('fanboy r6 watch: still standing, the watch called anew many times (a restart) — still the one notification', 1, count(fanboyTNotified($t)));
        // it turns again: over; standing again soon after (turned < 2 minutes): not told again
        fanboyR6Tach($t, 4, 1400);
        $s = fanboyR6Watch($now, $wall, 4);
        same('fanboy r6 watch: it turns again — the alarm is over', 'turning', $s['tachs'][fanboyR6TachId(4)]['state']);
        fanboyR6Tach($t, 4, 0);
        fanboyR6Watch($now, $wall, 30);
        same('fanboy r6 watch: … stands again after turning only 20 s — not told again (the alarms\' rule: over after 2 minutes without a reason)', 1, count(fanboyTNotified($t)));
        fanboyR6Tach($t, 4, 1400);
        fanboyR6Watch($now, $wall, 30);
        fanboyR6Tach($t, 4, 0);
        fanboyR6Watch($now, $wall, 30);
        same('fanboy r6 watch: … after turning 2½ minutes, standing warm again is told again', 2, count(fanboyTNotified($t)));
        // the page's state: the setting, each tach's state and what was learned
        $idle = fanboyIdleState();
        same('fanboy r6 watch: his state for the page — on, fan 4 in alarm with its name, «stops when cool» kept, the warm place',
            [true, 'alarm', 'Fan input 4', true, 'Parity'], [$idle['on'], $idle['tachs'][fanboyR6TachId(4)]['state'], $idle['tachs'][fanboyR6TachId(4)]['name'],
                is_int($idle['tachs'][fanboyR6TachId(4)]['quiet_stop']), $idle['warm']['name'] ?? null]);
        // the Dashboard tile: a fan that stands, said plainly
        $o = fanboyDashWeb(['fanboy.json' => ['idle' => $idle, 'time' => $wall, 'setup' => ['board' => FANBOY_T_KEY]], 'office/staff.json' => ['hired' => ['fanboy' => 1]],
            'fanboy/dashboard.json' => ['v' => 1, 'show' => 'speeds']], [], $wall);
        check('fanboy r6 watch: the Dashboard tile says «A fan stands: Fan input 4» and shows his alarm picture (' . json_encode($o['model']['banners'] ?? $o) . ')',
            in_array(['red', 'A fan stands: Fan input 4'], $o['model']['banners'] ?? [], true) && ($o['model']['mood'] ?? '') === 'alarm');
    } finally {
        fanboyTEnd();
    }
}

/**
 * Part 1: what is never «stands» or never his to tell — a sentinel rpm (no value), a fan the running control holds or
 * watches itself, any fan while a setup step runs; the CPU cooler and the pump keep round 4's stricter rule (told after
 * 15 s whatever the temperatures, never learned); the setting off: nothing
 */
function testFanboyR6WatchRules(): void
{
    $t = fanboyT('r6rules');
    try {
        fanboyTDisks($t, 50, 33, 34);           // warm all along
        [$now, $wall] = [1000.0, 1_800_000_000];
        fanboyR6Watch($now, $wall);
        // a sentinel (round 5): no value — never «stands», however long
        fanboyR6Tach($t, 5, 65535);
        $s = fanboyR6Watch($now, $wall, 40);
        same('fanboy r6 rules: 65535 rpm is no value — never «stands», nothing told', ['no_value', []], [$s['tachs'][fanboyR6TachId(5)]['state'], fanboyTNotified($t)]);
        fanboyR6Tach($t, 5, 1421);
        fanboyR6Watch($now, $wall);
        // the CPU cooler (a role of the setup, the BIOS's): told after 15 s although everything is cool, never learned
        fanboyTDisks($t, 35, 33, 34);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        fanboyR6Tach($t, 1, 0);
        $s = fanboyR6Watch($now, $wall, 4);
        $kept = readJson(fanboyWatchFile())['boards'][FANBOY_T_KEY]['tachs'][fanboyR6TachId(1)] ?? [];
        same('fanboy r6 rules: the CPU cooler standing while everything is cool — told after 15 s by round 4\'s rule, its name from the setup, never «stops when cool»',
            ['alarm', ['Fanboy: CPU stands (CPU cooler or pump)'], null], [$s['tachs'][fanboyR6TachId(1)]['state'], fanboyR5Subjects($t), $kept['quiet_stop'] ?? null]);
        fanboyR6Tach($t, 1, 1310);
        fanboyR6Watch($now, $wall, 30);
        // the control runs and holds the disk cage (tach 4) and watches the CPU cooler itself: those are its to tell
        @mkdir(TESTS_RUN_DIR . '/fanboy', 0700, true);
        file_put_contents(TESTS_RUN_DIR . '/fanboy/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        file_put_contents(fanboyStatusFile(), json_encode(['zones' => [['id' => 'disks', 'fans' => [['id' => fanboyTId(4), 'held' => true]]],
            ['id' => 'case', 'fans' => [['id' => fanboyTId(5), 'held' => false, 'foreign' => true]]]], 'watch' => [['id' => fanboyTId(1)]]]));
        fanboyTDisks($t, 50, 33, 34);
        fanboyR6Tach($t, 4, 0);
        fanboyR6Tach($t, 1, 0);
        fanboyR6Tach($t, 5, 0);
        $s = fanboyR6Watch($now, $wall, 30);
        same('fanboy r6 rules: while the control runs, the fans it holds and the BIOS fans it watches are its own to tell; one it gave back (someone else wrote it) is watched',
            ['steered', 'steered', 'alarm'], [$s['tachs'][fanboyR6TachId(4)]['state'], $s['tachs'][fanboyR6TachId(1)]['state'], $s['tachs'][fanboyR6TachId(5)]['state']]);
        same('fanboy r6 rules: … so the hub\'s tach 5 alone is told (by its setup name)', ['Fanboy: CPU stands (CPU cooler or pump)', 'Fanboy: Front stands while it is warm'], fanboyR5Subjects($t));
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        // a setup step moves fans on purpose: the watch pauses (no clock runs)
        foreach ([4, 1, 5] as $n) {
            fanboyR6Tach($t, $n, 1400);
        }
        fanboyR6Watch($now, $wall, 30);
        $before = count(fanboyTNotified($t));
        file_put_contents(fanboyStepFile(), json_encode(['kind' => 'measure', 'status' => 'running', 'pid' => getmypid(), 'start' => fanboyProcStart(getmypid()), 'at' => $wall]));
        fanboyR6Tach($t, 4, 0);
        $s = fanboyR6Watch($now, $wall, 40);
        same('fanboy r6 rules: a setup step running — paused, nothing told', ['paused', $before], [$s['tachs'][fanboyR6TachId(4)]['state'], count(fanboyTNotified($t))]);
        @unlink(fanboyStepFile());
        // the setting off: no state, nothing told, the RAM file gone; on again: back
        $r = fanboyWatchSet(['on' => false]);
        $s = fanboyR6Watch($now, $wall, 40);
        same('fanboy r6 rules: «watch fans I don\'t steer» off — nothing watched, nothing told, the state says off', [[], $before, false, false],
            [$s, count(fanboyTNotified($t)), $r['on'], $r['state']['idle']['on']]);
        same('fanboy r6 rules: … the setting kept in his data (not settings.json: the control would take every fan anew)', false, readJson(fanboyWatchFile())['on'] ?? null);
        check('fanboy r6 rules: … a wrong type never turns it off', (function () { try { fanboyWatchSet(['on' => 'yes']); return false; } catch (Problem $e) { return true; } })());
        fanboyWatchSet(['on' => true]);
        same('fanboy r6 rules: on again — watched', 'stands_warm', fanboyR6Watch($now, $wall)['tachs'][fanboyR6TachId(4)]['state']);
        // a graphics card's own fan is the card's business (it stops at idle by design): not watched
        same('fanboy r6 rules: the watch never writes a fan output (no write call in its file)', 0,
            preg_match('/\b(fanboySet|fanboyTake|fanboyWriteValue|fanboyWriteFile|fanboyGiveBack)\s*\(/', (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-watch.php')));
    } finally {
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        fanboyTEnd();
    }
}

/** Part 1: «warm» — a disk at its warning (Unraid's own for it, else its kind's), a sensor at its kind's «full speed at»; asleep never; «other» never */
function testFanboyR6WarmNow(): void
{
    $disk = fn (string $name, ?int $c, bool $rot = true, ?int $warn = null) => ['name' => $name, 'temp' => $c, 'asleep' => $c === null, 'rotational' => $rot, 'warn' => $warn, 'device' => 'sdx'];
    $chip = fn (string $name, string $kind, array $temps) => ['name' => $name, 'kind' => $kind, 'device' => "platform/$name.0", 'address' => "$name.0", 'hwmon' => 'hwmon1',
        'temps' => array_map(fn ($n, $c) => ['n' => $n, 'label' => '', 'c' => $c], array_keys($temps), $temps), 'fans' => [], 'outputs' => []];
    same('fanboy r6 warm: all cool — null', null, fanboyWarmNow([$chip('coretemp', 'cpu', [1 => 60.0])], [$disk('disk1', 40), $disk('cache', 55, false)], [], 'en'));
    same('fanboy r6 warm: a disk at its own warning (Unraid\'s hotTemp 40) counts', ['disk1', 41.0], array_values(array_intersect_key(
        fanboyWarmNow([], [$disk('disk1', 41, true, 40)], [], 'en') ?? [], ['id' => 1, 'c' => 1])));
    same('fanboy r6 warm: a sleeping disk is never warm; a sensor of unknown meaning («other») never', null,
        fanboyWarmNow([$chip('iwlwifi_1', 'other', [1 => 90.0])], [$disk('disk1', null)], [], 'en'));
    same('fanboy r6 warm: a CPU at its kind\'s «full speed at» (80 °C) is warm, named as the page names it', 'cpu',
        fanboyWarmNow([$chip('coretemp', 'cpu', [1 => 81.0])], [], [], 'en')['kind'] ?? null);
}

/** A Problem's key from a call, '' when it went through */
function fanboyR6Refused(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (Problem $e) {
        return (string) strtok($e->getMessage(), ' ');      // the key (its params follow as JSON)
    }
}

/**
 * Part 4: «Show me this fan» for an output nobody of his holds — a process of its own (here in this one, on the tests'
 * clock): 100 % while it lasts, then exactly the value AND mode before (the chip's own automatic too), read back, the
 * journal's holder gone; whatever ends it (an error half-way) gives back
 */
function testFanboyR6ShowStep(): void
{
    $t = fanboyT('r6show');
    try {
        fanboyTDisks($t, 35, 33, 34);
        file_put_contents("{$t['hw']}/pwm4", "77\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        $r = fanboyShowStart(['fan' => fanboyTId(4)]);
        same('fanboy r6 show: the click — a process of its own started, the show «starting» in RAM', [true, ['fanboy-show'], 'step', 'starting'],
            [$r['ok'], $GLOBALS['fanboyLaunched'], $r['show']['via'], $r['state']['show']['status'] ?? null]);
        same('fanboy r6 show: … a second click while it runs is refused', 'fanboy_show_running', fanboyR6Refused(fn () => fanboyShowStart(['fan' => fanboyTId(5)])));
        $clock = 0.0;
        $seen = [];
        // 4b (the owner asked): during a show every OTHER output stays exactly as it is — nothing else is written
        $others = fn () => array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 5, 6]);
        $before = $others();
        $mtimes = fn () => array_map(fn ($n) => [@filemtime("{$t['hw']}/pwm$n"), @filemtime("{$t['hw']}/pwm{$n}_enable")], [1, 2, 3, 5, 6]);
        clearstatcache();
        $m0 = $mtimes();
        $otherChanged = false;
        fanboyShowMain(['now' => function () use (&$clock) { return $clock; }, 'sleep' => function (float $s) use (&$clock, &$seen, $t, $others, $before, &$otherChanged) {
            $clock += $s;
            $seen[] = fanboyTOut($t, 4);
            $seen[] = fanboyShowRead()['status'] ?? null;
            $otherChanged = $otherChanged || $others() !== $before;
        }]);
        same('fanboy r6 show: 100 % (fixed) while it is shown, «running» meanwhile, up to a minute (round 8)', [[255, 1], 'running', true],
            [$seen[0], $seen[1], abs(count($seen) / 2 - FANBOY_SHOW_FOR / 0.2) <= 1]);
        clearstatcache();
        same('fanboy r6 show: … every other output exactly as it was all along — never stopped, never written (4b)', [false, $before, $m0], [$otherChanged, $others(), $mtimes()]);
        $s = fanboyShowRead();
        $j = fanboyJournal()['outputs'][fanboyTId(4)] ?? [];
        same('fanboy r6 show: … then exactly as before — value 77, the chip\'s own automatic (2) — read back, nobody holds it, the show done', [[77, 2], 'done', true, true, 'show'],
            [fanboyTOut($t, 4), $s['status'], $s['ok'], array_key_exists('owner', $j) && $j['owner'] === null, $j['given']['why'] ?? null]);
        // 4b: «Found it — stop» — ends it at once, given back exactly as after the time
        fanboyShowStart(['fan' => fanboyTId(4)]);
        $clock = 0.0;
        $n = 0;
        fanboyShowMain(['now' => function () use (&$clock) { return $clock; }, 'sleep' => function (float $s) use (&$clock, &$n) {
            $clock += $s;
            if (++$n === 10) {
                fanboyShowStop(['fan' => fanboyTId(4)]);
            }
        }]);
        $j = fanboyJournal()['outputs'][fanboyTId(4)] ?? [];
        same('fanboy r6 show: «Found it — stop» after 2 s — ended in that very look (0.2 s), exactly as before (77, its automatic), read back, said «stopped»',
            [10, [77, 2], 'done', 'stopped', true, null], [$n, fanboyTOut($t, 4), fanboyShowRead()['status'], fanboyShowRead()['why'], fanboyShowRead()['ok'], $j['owner'] ?? null]);
        same('fanboy r6 show: a stop for a fan without a show — quietly nothing (no error, the show as it was)', [true, 'done', 'stopped'],
            (function () { $r = fanboyShowStop(['fan' => fanboyTId(5)]); return [$r['ok'], $r['show']['status'] ?? null, $r['show']['why'] ?? null]; })());
        fanboyShowStart(['fan' => fanboyTId(4)]);
        fanboyShowStop(['fan' => fanboyTId(4)]);
        $clock = 0.0;
        fanboyShowMain(['now' => function () use (&$clock) { return $clock; }, 'sleep' => function (float $s) use (&$clock) { $clock += $s; }]);
        same('fanboy r6 show: stopped before it began — nothing taken, nothing written', [[77, 2], 'stopped'], [fanboyTOut($t, 4), fanboyShowRead()['why']]);
        // whatever ends it: an error half-way gives back too
        fanboyShowStart(['fan' => fanboyTId(5)]);
        $clock = 0.0;
        fanboyShowMain(['now' => function () use (&$clock) { return $clock; }, 'sleep' => function (float $s) use (&$clock) {
            $clock += $s;
            throw new RuntimeException('test: ended half-way');
        }]);
        same('fanboy r6 show: ended half-way by an error — given back exactly (128, fixed), said «failed»', [[128, 1], 'failed', true],
            [fanboyTOut($t, 5), fanboyShowRead()['status'], fanboyShowRead()['ok']]);
    } finally {
        fanboyTEnd();
    }
}

/** Part 4: refused with its words — another controller, a step, an alarm, an output that doesn't obey / works the other way round / gone */
function testFanboyR6ShowRefused(): void
{
    $t = fanboyT('r6refuse');
    try {
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $settings['fans'][fanboyTId(3)]['found'] = 'ignored';
        $settings['fans'][fanboyTId(3)]['why'] = 'taken_back';         // round 8: an «ignored» counts with its reason
        $settings['fans'][fanboyTId(6)] = ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 6, 'tach' => null, 'tachs' => [], 'found' => 'inverted'];
        fanboyTStore($settings);
        $show = fn (int $n) => fanboyR6Refused(fn () => fanboyShowStart(['fan' => fanboyTId($n)]));
        same('fanboy r6 show refused: «doesn\'t obey», «the other way round», an output not there', ['fanboy_show_not', 'fanboy_show_not', 'fanboy_no_output'],
            [$show(3), $show(6), fanboyR6Refused(fn () => fanboyShowStart(['fan' => fanboyTId(9)]))]);
        @mkdir(TESTS_RUN_DIR . '/fanboy', 0700, true);
        file_put_contents(fanboyStepFile(), json_encode(['kind' => 'measure', 'status' => 'running', 'pid' => getmypid(), 'start' => fanboyProcStart(getmypid()), 'at' => time()]));
        same('fanboy r6 show refused: while measuring (a setup step runs)', 'fanboy_step_running', $show(4));
        @unlink(fanboyStepFile());
        file_put_contents(TESTS_RUN_DIR . '/fanboy/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        file_put_contents(fanboyStatusFile(), json_encode(['alarm' => ['code' => 'sensor'], 'refuses' => [fanboyTId(2)]]));
        same('fanboy r6 show refused: during an alarm of the control', 'fanboy_show_alarm', $show(4));
        file_put_contents(fanboyStatusFile(), json_encode(['alarm' => null, 'refuses' => [fanboyTId(2)]]));
        same('fanboy r6 show refused: an output whose driver takes no values (the control\'s «refuses»)', 'fanboy_show_not', $show(2));
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        @mkdir("{$t['root']}/boot/config/plugins/fanctrlplus", 0700, true);
        file_put_contents("{$t['root']}/boot/config/plugins/fanctrlplus/fan1.cfg", "service=\"1\"\n");
        file_put_contents("{$t['root']}/boot/config/plugins/fanctrlplus.plg", "<PLUGIN/>\n");
        same('fanboy r6 show refused: another controller steers — its name in the words', ['fanboy_other', []], [$show(4), $GLOBALS['fanboyLaunched']]);
        same('fanboy r6 show refused: … nothing was started, no output written', [[128, 1], null], [fanboyTOut($t, 4), fanboyShowRead()]);
    } finally {
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        fanboyTEnd();
    }
}

/** Part 4: a fan Fanboy steers — the control shows it (100 % for 5 s, the trim waiting), then its curve takes it again */
function testFanboyR6ShowControl(): void
{
    $t = fanboyT('r6showc');
    try {
        fanboyTDisks($t, 35, 33, 34);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 8);
        $before = fanboyTOut($t, 4);
        @mkdir(TESTS_RUN_DIR . '/fanboy', 0700, true);
        file_put_contents(TESTS_RUN_DIR . '/fanboy/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        $r = fanboyShowStart(['fan' => fanboyTId(4)]);
        same('fanboy r6 show control: a fan he steers is shown by the control (no process of its own)', ['control', []], [$r['show']['via'], $GLOBALS['fanboyLaunched']]);
        $others = fn () => array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 5]);
        $o0 = $others();
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r6 show control: the next round — 100 %, «running», every other fan as it was', [[255, 1], 'running', $o0],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], $others()]);
        fanboyTRounds($c, $now, $t, $spec, (int) (FANBOY_SHOW_FOR / FANBOY_ROUND) - 2);
        same('fanboy r6 show control: … still at 100 % shortly before the minute (round 8), the others untouched', [[255, 1], 'running', $o0],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], $others()]);
        fanboyTRounds($c, $now, $t, $spec, 2);
        same('fanboy r6 show control: … after the minute its curve\'s value again, the show done, noted', [$before, 'done', 'show'],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], end($c['notes'])['code'] ?? null]);
        // 4b: «Found it — stop» — the control ends its show in its next round
        fanboyShowStart(['fan' => fanboyTId(4)]);
        fanboyTRounds($c, $now, $t, $spec, 2);
        fanboyShowStop(['fan' => fanboyTId(4)]);
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r6 show control: «Found it — stop» — the next round back on its curve, «stopped»', [$before, 'done', 'stopped'],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], fanboyShowRead()['why']]);
        fanboyLetGo($c, 'end');
    } finally {
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        fanboyTEnd();
    }
}

/** Unraid's poll_attributes in the fake tree's var.ini (null: not there) */
function fanboyR6Poll(array $t, ?int $poll): void
{
    file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Started\"\n" . ($poll === null ? '' : "poll_attributes=\"$poll\"\n"));
}

/**
 * Part 5: an old disk value is a lower bound — over 300 s of poll_attributes the zone logic counts +1 °C per started
 * 10 minutes, at most +3; at 300 s or less nothing changes; a sleeping disk stays without value; an NVMe read from hwmon
 * stays as it is (live). The status carries the allowance, the page's state and the report too.
 */
function testFanboyR6OldDisks(): void
{
    same('fanboy r6 old disks: the allowance by poll_attributes (unknown, 30 s, 300, 301, 600, 601, 1200, 1201, 1800, a day)', [0, 0, 0, 1, 1, 2, 2, 3, 3, 3],
        array_map('fanboyPollAllowance', [null, 30, 300, 301, 600, 601, 1200, 1201, 1800, 86400]));
    $t = fanboyT('r6poll');
    try {
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $run = function (?int $poll, ?int $parity, ?int $disk1) use ($t, $settings, $spec): array {
            fanboyR6Poll($t, $poll);
            fanboyTDisks($t, $parity, $disk1, 38);
            $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
            $now = 1000.0;
            fanboyTRounds($c, $now, $t, $spec, 3);
            $out = [$c['zones']['disks']['warmest'], $c['sources']['parity']['c'], $c['sources']['disk1']['c'], $c['sources'][FANBOY_T_NVME]['c'],
                fanboyStatus($c)['disk_allow'], $c['zones']['disks']['cur']];
            fanboyLetGo($c, 'end');
            return $out;
        };
        $live = $run(300, 40, null);
        $old = $run(1800, 40, null);
        same('fanboy r6 old disks: at 300 s a disk counts as it is (40 °C), the status says nothing added', [40, ['poll' => 300, 'add' => 0]], [$live[1], $live[4]]);
        same('fanboy r6 old disks: at 1800 s the zone counts it 3 °C warmer (43 °C, its own value kept beside), the status says +3', [43, 43, 40, ['poll' => 1800, 'add' => 3]],
            [$old[1], $old[0]['c'], $old[0]['raw'] ?? null, $old[4]]);
        check('fanboy r6 old disks: … so the disk zone runs higher than with fresh values (' . $live[5] . ' → ' . $old[5] . ' %)', $old[5] > $live[5]);
        same('fanboy r6 old disks: a sleeping disk stays without value; the NVMe read from hwmon is live — unchanged', [null, null, $live[3]], [$old[2], $live[2], $old[3]]);
        same('fanboy r6 old disks: 700 s → +2 °C', 42, $run(700, 40, null)[1]);
        // the page's state and the report say it
        fanboyR6Poll($t, 1800);
        $scan = fanboyDetect();
        $state = fanboyScan();
        same('fanboy r6 old disks: his state carries the allowance beside poll (the hint\'s words)', [1800, 3], [$state['poll'], $state['disk_allow']]);
        check('fanboy r6 old disks: the report says what the zone logic adds', str_contains(fanboyReportText($scan), 'Disk temperatures polled every: 1800 s (poll_attributes); zone logic adds 3 degrees to disk values'));
        // the measuring's «too warm» counts it too: 47 °C + 3 is within 5 °C of the hard disks' 55 (since 2026-11-20)
        fanboyTDisks($t, 47, null, 30);
        same('fanboy r6 old disks: measuring stops «warm» by the value with the allowance (Parity 50 °C at 1800 s)', 50.0, fanboyStepWarm()['c'] ?? null);
        fanboyR6Poll($t, 60);
        same('fanboy r6 old disks: … not at 60 s (47 °C is under 50)', null, fanboyStepWarm());
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== round 7: the owner's first test on real hardware

/** The fake board with the it87 «full on» read-back on every output (a fixed 255 reads enable 0) */
function fanboyR7Spec(array $change = []): array
{
    $spec = fanboyTSpec(10, $change);
    $spec['full0'] = [1, 2, 3, 4, 5, 6];
    return $spec;
}

/**
 * F1 (Gigabyte Z890, it8696 on it87): after a fixed 255 the driver reads the mode back as 0 («full on») — the test step
 * took that for «the BIOS took it back», so every output came out «ignored» after 2 s at full. Now a fixed full reading
 * «full on» obeys: the outputs are found (tach, hub, ask), each at full long enough; given back exactly. A real take-back
 * (the chip's automatic, a value changed) is still «ignored».
 */
function testFanboyR7FullOnObeys(): void
{
    $t = fanboyT('r7full');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $run = function (array $spec, array $outs) use ($t, $settings): array {
            fanboyTStore($settings);
            @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
            fanboyTSettle($t, $spec);
            $clock = 0.0;
            $step = fanboyStepRun(['id' => 'r7' . mt_rand(), 'kind' => 'test_up', 'fans' => array_map('fanboyTId', $outs)], fanboyTIo($t, $spec, $clock));
            return [array_map(fn ($r) => [$r['found'], $r['tachs']], $step['results']), $clock];
        };
        [$found] = $run(fanboyR7Spec(), [1, 2, 4, 5]);
        same('fanboy r7 full on: a chip reading a fixed 255 back as mode 0 — the outputs obey: tach 1, «louder?», tach 4, the hub 5+6 (none «ignored»)',
            [fanboyTId(1) => ['tach', [1]], fanboyTId(2) => ['ask', []], fanboyTId(4) => ['tach', [4]], fanboyTId(5) => ['tach', [5, 6]]], $found);
        same('fanboy r7 full on: … each given back exactly (128, a fixed value — the mode 1 written again), nothing held',
            [[128, 1], [128, 1], [128, 1], [128, 1], []], [fanboyTOut($t, 1), fanboyTOut($t, 2), fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyHeld()]);
        // a real take-back is still one: the chip's automatic, or a value that changed
        $spec = fanboyR7Spec([4 => ['auto' => 1100]]);
        $spec['revert'] = [4 => 2];
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        same('fanboy r7 full on: … the mode going to the chip\'s automatic (2) is still «ignored»', [fanboyTId(4) => ['ignored', []]], $run($spec, [4])[0]);
        file_put_contents("{$t['hw']}/pwm4_enable", "1\n");
        same('fanboy r7 full on: the rule — 255 reading 255/0 or 255/1 obeys, 254/0 too (chips round); 255/2, 200/1 and 128/0 for 128 don\'t',
            [true, true, true, false, false, false, true, false],
            [fanboyObeys(255, ['value' => 255, 'enable' => 0]), fanboyObeys(255, ['value' => 255, 'enable' => 1]), fanboyObeys(255, ['value' => 254, 'enable' => 0]),
             fanboyObeys(255, ['value' => 255, 'enable' => 2]), fanboyObeys(255, ['value' => 200, 'enable' => 1]), fanboyObeys(128, ['value' => 128, 'enable' => 0]),
             fanboyObeys(128, ['value' => 129, 'enable' => null]), fanboyObeys(128, null)]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * F1: the control on such a chip — a zone at full for an alarm reads «full on» (mode 0): no «someone else changed a
 * fan», and when the alarm is over it comes down again (mode 1 written again); a give-back of a fixed full reads back as
 * it was; a real foreign write is still one
 */
function testFanboyR7ControlFullOn(): void
{
    $t = fanboyT('r7ctl');
    try {
        fanboyTDisks($t, 35, 33, 34);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyR7Spec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        // the case zone's RAM sensor says nothing: that zone to full for the alarm
        $ram = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:1f.4/i2c-0/0-0051/hwmon/hwmon*/temp1_input")[0];
        rename($ram, "$ram.away");
        fanboyTRounds($c, $now, $t, $spec, 25);
        same('fanboy r7 control: the case zone at full for an alarm — the chip reads «full on» (255/0), no foreign change noted, nothing given back',
            ['sensor', [255, 0], [], true], [$c['alarm']['code'] ?? null, fanboyTOut($t, 5), array_values(array_filter(array_column($c['notes'], 'code'), fn ($x) => $x === 'foreign')),
                $c['fans'][fanboyTId(5)]['taken']]);
        rename("$ram.away", $ram);
        fanboyTRounds($c, $now, $t, $spec, 90);
        $out = fanboyTOut($t, 5);
        same('fanboy r7 control: … the alarm over — it comes down again (a fixed value, mode 1 written again)', [null, 1, true], [$c['alarm'], $out[1], $out[0] < 255]);
        // someone else really writes: still told
        file_put_contents("{$t['hw']}/pwm4", "60\n");
        fanboyTRounds($c, $now, $t, $spec, 2);
        same('fanboy r7 control: a real foreign write (a value changed) is still told', true, in_array('foreign', array_column($c['notes'], 'code'), true));
        fanboyLetGo($c, 'end');
        // a give-back to a fixed full: reads «full on» — as it was, not «NOT read back»
        file_put_contents("{$t['hw']}/pwm6", "255\n");
        file_put_contents("{$t['hw']}/pwm6_enable", "1\n");
        $o = fanboyOutputs(fanboyDetect())[fanboyTId(6)];
        $me = fanboyMe('step');
        fanboyTake($o, $me);
        fanboySet(fanboyTId(6), 100, $me);
        $back = fanboyGiveBack([fanboyTId(6)], 'test', $me);
        fanboyFakeRespond($t['root'], $spec, 0.5);
        same('fanboy r7 control: giving back a fixed 255 on such a chip — read back as it was (ok), then «full on»', [true, [255, 0]], [$back[fanboyTId(6)]['ok'] ?? null, fanboyTOut($t, 6)]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * F3 (the owner's server after another controller was uninstalled): every output on a fixed 50 % (enable 1), nothing
 * steering, Fanboy not set up — not «the BIOS runs every fan» but «nobody steers», each output and its value listed;
 * outputs in the chip's own automatic: the BIOS; some fixed: the BIOS with those listed. The Dashboard says it too.
 */
function testFanboyR7Fixed(): void
{
    $t = fanboyT('r7fixed');
    try {
        $w = fanboyScan()['who'];
        same('fanboy r7 fixed: all six outputs on a fixed 50 % someone left, nothing steering — «nobody», why «fixed», each listed with its value',
            ['nobody', 'fixed', 6, [50]], [$w['who'], $w['why'], count($w['fixed'] ?? []), array_values(array_unique(array_column($w['fixed'] ?? [], 'pct')))]);
        file_put_contents("{$t['hw']}/pwm2_enable", "0\n");
        same('fanboy r7 fixed: «full on» (enable 0) is a fixed value too — 100 %', 100, array_column(fanboyScan()['who']['fixed'], 'pct', 'output')[2] ?? null);
        foreach ([1, 2, 3] as $n) {
            file_put_contents("{$t['hw']}/pwm{$n}_enable", "2\n");
        }
        $w = fanboyScan()['who'];
        same('fanboy r7 fixed: three in the chip\'s automatic, three fixed — the BIOS, the fixed ones listed', ['bios', 'not_set_up', [4, 5, 6]],
            [$w['who'], $w['why'], array_column($w['fixed'] ?? [], 'output')]);
        foreach ([4, 5, 6] as $n) {
            file_put_contents("{$t['hw']}/pwm{$n}_enable", "2\n");
        }
        $w = fanboyScan()['who'];
        same('fanboy r7 fixed: all in the automatic — the BIOS, nothing listed', ['bios', 'not_set_up', false], [$w['who'], $w['why'], isset($w['fixed'])]);
        // outputs the test found empty (nothing on them) don't count; a setup step moving outputs: never «nobody»
        foreach ([1, 2, 3, 4, 5, 6] as $n) {
            file_put_contents("{$t['hw']}/pwm{$n}_enable", $n <= 3 ? "2\n" : "1\n");
        }
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $settings['fans'][fanboyTId(6)] = ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 6, 'tach' => null, 'tachs' => [], 'found' => 'empty'];
        fanboyTStore($settings);
        same('fanboy r7 fixed: an output the test found empty is not counted', [4, 5], array_column(fanboyScan()['who']['fixed'] ?? [], 'output'));
        file_put_contents(fanboyStepFile(), json_encode(['kind' => 'test_up', 'status' => 'running', 'pid' => getmypid(), 'start' => fanboyProcStart(getmypid()), 'at' => time()]));
        same('fanboy r7 fixed: while a setup step runs — as before (no «nobody»)', [false, 'bios'], [isset(fanboyScan()['who']['fixed']), fanboyScan()['who']['who']]);
        @unlink(fanboyStepFile());
        // the Dashboard tile: «Nobody · left on a fixed value», orange
        $state = ['who' => ['who' => 'nobody', 'why' => 'fixed', 'fixed' => [['id' => fanboyTId(4), 'output' => 4, 'pct' => 50]]], 'time' => time(), 'setup' => ['board' => FANBOY_T_KEY]];
        $o = fanboyDashWeb(['fanboy.json' => $state, 'office/staff.json' => ['hired' => ['fanboy' => 1]], 'fanboy/dashboard.json' => ['v' => 1, 'show' => 'speeds']], [], time());
        same('fanboy r7 fixed: the Dashboard tile — «Nobody · left on a fixed value», orange', ['Nobody · left on a fixed value', 'orange'], [$o['model']['who'] ?? $o, $o['model']['tone'] ?? null]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * F4: a `fanboy.refresh` from a shell whose own command line held «fanctrl» and «autofan» (a grep pattern) made FanCtrl
 * Plus AND Dynamix System Autofan «running, blocks» though neither was installed. A controller now only by a process's
 * own program (its executable or its script) and, for a plugin's, only while the plugin is installed.
 */
function testFanboyR7NotAController(): void
{
    same('fanboy r7 programs: a process\'s own program — the executable; an interpreter\'s script; a shell\'s -c is the shell; env → the interpreter → its script',
        ['/usr/bin/grep', '/bin/bash /usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh', '/bin/bash', '/usr/bin/vi',
         '/usr/bin/php /usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php', 'python3 /usr/bin/liquidctl', '/usr/bin/php', ''],
        array_map('fanboyProgram', ["/usr/bin/grep\0-E\0fanctrl|autofan\0/proc/1/cmdline", "/bin/bash\0/usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh\0pwm1",
            "/bin/bash\0-c\0grep -E 'fanctrl|autofan' /proc/*/cmdline; /usr/local/emhttp/plugins/dynamix.system.autofan/scripts/autofan", "/usr/bin/vi\0/usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh",
            "/usr/bin/php\0-d\0memory_limit=64M\0/usr/local/emhttp/plugins/md12xx.fancontrol/include/controller.php", "/usr/bin/env\0python3\0/usr/bin/liquidctl\0status",
            "/usr/bin/php\0-r\0echo 'autofan fanctrlplus';", ""]));
    $look = function (array $procs, array $plugins = []): array {
        static $n = 0;
        $root = fanboyFakeTree(hardeningTmp('fanboy-r7ctl' . ++$n), fanboyFakeBoard(10, 'it87.2608', ['procs' => [1 => '/sbin/init'] + $procs, 'plugins' => fanboyFakeBoard()['plugins'] + $plugins]));
        $s = fanboyDetect($root);
        return [$s['situation']['code'], array_map(fn ($c) => [$c['id'], $c['running'], $c['blocks']], $s['controllers'])];
    };
    $shells = [4001 => "/bin/bash -c grep -E 'fanctrl|autofan' /proc/*/cmdline", 4002 => '/usr/bin/grep -E fanctrlplus|dynamix.system.autofan /var/log/syslog',
        4003 => '/usr/bin/vi /usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh', 4004 => '/usr/bin/curl -s http://x/fanboy.refresh?fanctrl=autofan'];
    same('fanboy r7 not a controller: a shell, a grep, an editor, a request naming them — nobody, neither installed', ['ok', []], $look($shells));
    $installed = ['fanctrlplus.plg' => '<PLUGIN/>', 'dynamix.system.autofan.plg' => '<PLUGIN/>'];
    same('fanboy r7 not a controller: … both installed but not switched on — the same processes are still no controller', ['ok', []], $look($shells, $installed));
    same('fanboy r7 not a controller: settings an uninstall left behind (service="1", no .plg) steer nothing', ['ok', []],
        $look([], ['fanctrlplus/pwm1.cfg' => "service=\"1\"\n", 'dynamix.system.autofan/fan.cfg' => "service=\"1\"\n"]));
    same('fanboy r7 not a controller: the real ones still are — FanCtrl Plus\'s loop script, Autofan\'s script run by php, a container\'s program (liquidctl)',
        ['other', [['fanctrlplus', true, true], ['dynamix.system.autofan', true, true], ['liquidctl', true, true]]],
        $look($shells + [5001 => '/bin/bash /usr/local/emhttp/plugins/fanctrlplus/scripts/fanctrlplus_loop.sh pwm1',
            5002 => '/usr/bin/php /usr/local/emhttp/plugins/dynamix.system.autofan/scripts/autofan', 5003 => '/usr/bin/python3 /usr/bin/liquidctl status'], $installed));
}

/**
 * F2 (the owner: «the grid moves under the mouse» during a test): a test card keeps its size while the test walks through
 * the outputs — its state line has room for two lines, its button row stays (off) while a test runs; «show me» keeps its
 * width when it turns into «Found it — stop» (both labels in one cell). Pictures: shots r7-2-test-before/after-*.
 */
function testFanboyR7CardsKeepSize(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $css = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.css');
    // fanboy-ux (2026-11-15): the cards became a list and one panel built once and patched in place (testFanboyUx) — what
    // F2 asked for holds there: a row has one height, the panel's sentence keeps room for two lines, its button row stays
    check('fanboy r7 cards: a row of the list has one height, the panel\'s state sentence room for two lines',
        preg_match('/\\.fb-item\\{[^}]*height:58px/', $css) === 1 && str_contains($css, '.fb-panel-line{margin:0; font-size:13.5px; line-height:1.45; color:var(--ink2); min-height:calc(2 * 1.45em)}'));
    check('fanboy r7 cards: while a test runs every panel keeps its button row (its room) and says under it why a button is off',
        str_contains($css, '.fb-panel-acts{display:flex; gap:8px; flex-wrap:wrap; align-items:center; min-height:34px}') && str_contains($js, "reason = T('panel.busy', { fan: run.id ? fanSay(run.id) : '' });"));
    // 2026-11-17: «show me» lives in the setup's panel only — the overview's rows change nothing
    check('fanboy r7 cards: «show me» only in the setup\'s panel — no button of it on the overview',
        substr_count($js, "T('show.button')") === 1 && str_contains($js, "if (showOnly) out.push(btn(T('show.button'), 'btn plain',")
        && !str_contains($js, 'function showButton(') && !str_contains($css, '.fb-show{'));
}

/**
 * F5: after F1, a full test of the six outputs on the it87 «full on» chip — an output where no tach follows stays at
 * full for FANBOY_T_ANSWER (8 s: long enough to hear which fan it is), one with a tach at least 4 s; how long one output and
 * all six take (on the tests' clock, the fake fans' lag 1 s) is said in the check's words
 */
function testFanboyR7TestLongEnough(): void
{
    $t = fanboyT('r7long');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR7Spec();
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $atFull = [];
        $io = fanboyTIo($t, $spec, $clock);
        $sleep = $io['sleep'];
        $io['sleep'] = function (float $s) use ($sleep, $t, &$atFull): void {
            foreach ([1, 2, 3, 4, 5, 6] as $n) {
                if (fanboyTOut($t, $n)[0] === 255) {
                    $atFull[$n] = ($atFull[$n] ?? 0) + $s;
                }
            }
            $sleep($s);
        };
        $step = fanboyStepRun(['id' => 'r7long', 'kind' => 'test_up', 'fans' => array_map('fanboyTId', [1, 2, 3, 4, 5, 6])], $io);
        $found = array_map(fn ($r) => $r['found'], $step['results']);
        $quiet = array_filter($found, fn ($f) => $f === 'ask');
        $loud = array_map(fn ($id) => $atFull[(int) substr($id, -1)] ?? 0, array_keys($quiet));
        check('fanboy r7 long enough: outputs where no tach follows (' . count($quiet) . ') each at full for ' . implode(', ', array_map(fn ($x) => round($x, 1) . ' s', $loud))
            . ' — at least FANBOY_T_ANSWER (' . FANBOY_T_ANSWER . ' s) less a look', count($quiet) === 3 && min($loud) >= FANBOY_T_ANSWER - 0.5, json_encode($loud));
        $tach = array_map(fn ($id) => $atFull[(int) substr($id, -1)] ?? 0, array_keys(array_filter($found, fn ($f) => $f === 'tach')));
        check('fanboy r7 long enough: outputs a tach follows (' . count($tach) . ') at full ' . implode(', ', array_map(fn ($x) => round($x, 1) . ' s', $tach))
            . ' (≥ 8 s since round 8\'s G2); the six outputs took ' . round($clock) . ' s on the tests\' clock (one silent output ≈ ' . round(FANBOY_UP_BASE + FANBOY_T_ANSWER) . ' s + its calm-down)',
            count($tach) === 3 && min($tach) >= FANBOY_T_ANSWER - 0.5 && $clock < 6 * (FANBOY_UP_BASE + FANBOY_T_ANSWER + FANBOY_UP_SETTLE));
        same('fanboy r7 long enough: … every output given back as it was', [[128, 1], [128, 1], [128, 1], [128, 1], [128, 1], [128, 1]], array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]));
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== round 8: the owner's second test on real hardware

/** The owner's board: output 1 → tach 1, 2 and 3 nothing on them (tachs read 0), 4 → 4, 5 → 5, 6 → 6 — every fan lagging */
function fanboyR8Spec(float $lag = 1.0): array
{
    $f = fn (int $out, int $max) => ['output' => $out, 'max' => $max, 'stall' => 0.15, 'start' => 0.25, 'lag' => $lag];
    return ['hwmon' => 'hwmon10', 'full0' => [1, 2, 3, 4, 5, 6], 'fans' => [1 => $f(1, 1906), 4 => $f(4, 1800), 5 => $f(5, 1800), 6 => $f(6, 1800)]];
}

/**
 * G3: «ignored» by round 5's second rule (a turning tach no output moves) was judged with the outputs not yet tested —
 * tested one by one in the owner's order, outputs 2 and 3 (nothing on them) became «the BIOS keeps it» because the fans
 * of 4–6 turned unpaired. Judged now only once every output of the chip has a verdict; an «ignored» saved without its
 * reason before this fix counts as not tested.
 */
function testFanboyR8IgnoredLate(): void
{
    $t = fanboyT('r8late');
    try {
        fanboyTDisks($t, 35, null, 33);
        file_put_contents("{$t['hw']}/fan2_input", "0\n");
        file_put_contents("{$t['hw']}/fan3_input", "0\n");
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $found = [];
        foreach ([1, 2, 3, 4, 5, 6] as $n) {           // the owner's clicks: each card's «test» in turn
            $clock = 0.0;
            $step = fanboyStepRun(['id' => "r8l$n", 'kind' => 'test_up', 'fans' => [fanboyTId($n)]], fanboyTIo($t, $spec, $clock));
            $found[$n] = $step['results'][fanboyTId($n)]['found'];
        }
        same('fanboy r8 late: tested one by one — 1 tach, 2 and 3 nothing followed («louder?», not «the BIOS keeps it»), 4/5/6 their tachs',
            [1 => 'tach', 2 => 'ask', 3 => 'ask', 4 => 'tach', 5 => 'tach', 6 => 'tach'], $found);
        same('fanboy r8 late: … so saved', ['ask', 'ask'], [fanboyBoardRaw()['fans'][fanboyTId(2)]['found'], fanboyBoardRaw()['fans'][fanboyTId(3)]['found']]);
        // the rule itself still holds once every output was tried: a fan turning that no output moves
        $all = fanboyUpIgnored(['a:x:pwm1' => ['found' => 'tach', 'tachs' => [1]], 'a:x:pwm2' => ['found' => 'ask']], ['a:x:pwm2' => ['base' => [1 => 900, 3 => 1200]]], ['a:x:pwm1', 'a:x:pwm2']);
        $early = fanboyUpIgnored(['a:x:pwm1' => ['found' => 'tach', 'tachs' => [1]], 'a:x:pwm2' => ['found' => 'ask']], ['a:x:pwm2' => ['base' => [1 => 900, 3 => 1200]]], ['a:x:pwm1', 'a:x:pwm2', 'a:x:pwm3']);
        same('fanboy r8 late: every output tried, tach 3 claimed by none — «ignored» (why «unpaired»); an output still untested — no verdict yet',
            [['ignored', 'unpaired'], 'ask'], [[$all['a:x:pwm2']['found'], $all['a:x:pwm2']['why'] ?? null], $early['a:x:pwm2']['found']]);
        // an «ignored» saved before this fix (no reason) shows as not tested; a taken-back one stays
        $settings = fanboyBoardRaw();
        $settings['fans'][fanboyTId(2)]['found'] = 'ignored';
        unset($settings['fans'][fanboyTId(2)]['why']);
        $settings['fans'][fanboyTId(3)]['found'] = 'ignored';
        $settings['fans'][fanboyTId(3)]['why'] = 'taken_back';
        fanboyTStore($settings);
        $fans = array_column(fanboyScan()['setup']['fans'], 'found', 'id');
        same('fanboy r8 late: an old «ignored» without its reason — not tested (the page offers the test); «taken back» stays «ignored»', [null, 'ignored'],
            [$fans[fanboyTId(2)], $fans[fanboyTId(3)]]);
    } finally {
        fanboyTEnd();
    }
}

/** G2: also with a tach following at once, the output stays at full for FANBOY_T_ANSWER (a lagging fan is up and heard) */
function testFanboyR8FullLongEnough(): void
{
    $t = fanboyT('r8long');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec(3.0);              // a fan that takes its time (the owner's: 1328 → 1906 rpm over ~5 s)
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $full = 0.0;
        $io = fanboyTIo($t, $spec, $clock);
        $sleep = $io['sleep'];
        $io['sleep'] = function (float $s) use ($sleep, $t, &$full): void {
            if (fanboyTOut($t, 1)[0] === 255) {
                $full += $s;
            }
            $sleep($s);
        };
        $step = fanboyStepRun(['id' => 'r8long', 'kind' => 'test_up', 'fans' => [fanboyTId(1)]], $io);
        same('fanboy r8 long: tach 1 follows — still at full the whole ' . FANBOY_T_ANSWER . ' s (here ' . $full . ' s), found, given back', [true, 'tach', [128, 1]],
            [$full >= FANBOY_T_ANSWER - 0.5, $step['results'][fanboyTId(1)]['found'], fanboyTOut($t, 1)]);
    } finally {
        fanboyTEnd();
    }
}

/** The fake coretemp's package temperature (°C) */
function fanboyR8Cpu(array $t, float $c): void
{
    file_put_contents(glob("{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon*/temp1_input")[0], (int) round($c * 1000) . "\n");
}

/** A quiet (or not) test step on the tests' clock; $each(clock) after every look: the test's own eyes */
function fanboyR8Run(array $t, array &$spec, array $outs, bool $quiet, ?callable $each = null, ?float $stopAt = null): array
{
    $clock = 0.0;
    $io = fanboyTIo($t, $spec, $clock, $stopAt);
    $sleep = $io['sleep'];
    $io['sleep'] = function (float $s) use ($sleep, $each, &$clock): void {
        $sleep($s);
        if ($each) {
            $each($clock);
        }
    };
    $step = fanboyStepRun(['id' => 'r8q' . mt_rand(), 'kind' => 'test_up', 'fans' => array_map('fanboyTId', $outs), 'quiet' => $quiet], $io);
    return [$step, $clock];
}

/**
 * G1 (the owner's decision, the one exception to «never down»): the quiet test — while output 1 is tested, every other
 * output stands (a fixed 0), output 1 at full the whole 10 s; afterwards everything exactly as before (value + mode), a
 * pause of at least 5 s with everything running before the next output; with the switch off no other output is written
 */
function testFanboyR8Quiet(): void
{
    $t = fanboyT('r8quiet');
    try {
        fanboyTDisks($t, 35, null, 33);
        file_put_contents("{$t['hw']}/pwm6_enable", "2\n");             // one in the chip's own automatic: back to it exactly
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        $log = [];
        [$step, $clock] = fanboyR8Run($t, $spec, [1, 4], true, function (float $clock) use ($t, &$log): void {
            $log[] = [$clock, array_map(fn ($n) => fanboyTOut($t, $n)[0], [1, 2, 3, 4, 5, 6])];
        });
        $quiet1 = array_values(array_filter($log, fn ($l) => $l[1][0] === 255 && $l[1][1] === 0));
        same('fanboy r8 quiet: no click — output 1 at full the whole minute while 2–6 stand (a fixed 0), found tach 1; then on by itself to output 4',
            ['done', 'tach', 'tach', true, true], [$step['status'], $step['results'][fanboyTId(1)]['found'], $step['results'][fanboyTId(4)]['found'],
                count($quiet1) * 0.5 >= FANBOY_T_ANSWER - 0.5, (bool) array_filter($log, fn ($l) => $l[1][3] === 255 && $l[1][0] === 0 && $l[1][4] === 0)]);
        same('fanboy r8 quiet: … afterwards every output exactly as before — value and mode (output 6 in its automatic again), nothing held',
            [$before, []], [array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // the pause between the two: everything as before for at least 5 s
        $end1 = max(array_column($quiet1, 0));
        $start2 = min(array_column(array_filter($log, fn ($l) => $l[1][3] === 255 && $l[1][0] === 0), 0));
        $between = array_filter($log, fn ($l) => $l[0] > $end1 && $l[0] < $start2);
        check('fanboy r8 quiet: between two outputs at least ' . FANBOY_QUIET_PAUSE . ' s with every output as before (here ' . ($start2 - $end1) . ' s)',
            $start2 - $end1 >= FANBOY_QUIET_PAUSE && $between && !array_filter($between, fn ($l) => in_array(0, $l[1], true) || in_array(255, $l[1], true)));
        // the switch off: no other output written — values, modes and the files' times as they were
        clearstatcache();
        $m0 = array_map(fn ($n) => @filemtime("{$t['hw']}/pwm$n") . '/' . @filemtime("{$t['hw']}/pwm{$n}_enable"), [2, 3, 4, 5, 6]);
        $others = false;
        fanboyR8Run($t, $spec, [1], false, function () use ($t, &$others, $before): void {
            $others = $others || array_map(fn ($n) => fanboyTOut($t, $n), [2, 3, 4, 5, 6]) !== array_slice($before, 1);
        });
        clearstatcache();
        same('fanboy r8 quiet: the switch off — no other output written, not for a moment (values, modes, file times)', [false, $m0],
            [$others, array_map(fn ($n) => @filemtime("{$t['hw']}/pwm$n") . '/' . @filemtime("{$t['hw']}/pwm{$n}_enable"), [2, 3, 4, 5, 6])]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * G1 guards: never started while warm (the CPU at 70 °C); a CPU rising during the quiet phase (20 °C over its start, or
 * 80 °C) — everything back at once, the test stops naming it; a CPU cooler (its role) is never stopped; an output that
 * doesn't take 0 is no error
 */
function testFanboyR8QuietGuards(): void
{
    $t = fanboyT('r8guard');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        fanboyR8Cpu($t, 72);
        same('fanboy r8 guards: the CPU at 72 °C — the quiet test refused (its words name the CPU); without the switch it may start',
            ['fanboy_quiet_warm', ''], [fanboyR6Refused(fn () => fanboyStepStart('test_up', [fanboyTId(1)], true)),
                fanboyR6Refused(function () { fanboyStepStart('test_up', [fanboyTId(1)], false); @unlink(fanboyStepFile()); })]);
        fanboyR8Cpu($t, 40);
        // the CPU climbs 25 °C during the quiet phase: everything back at once
        $at = null;
        [$step] = fanboyR8Run($t, $spec, [1, 4], true, function (float $clock) use ($t, &$at): void {
            if ($clock >= 6.0 && $at === null && fanboyTOut($t, 2)[0] === 0) {
                fanboyR8Cpu($t, 65);
                $at = $clock;
            }
        });
        same('fanboy r8 guards: the CPU 25 °C up during the quiet phase — stopped («quiet_warm», the CPU named, 65 °C), every output exactly as before, nothing held, output 4 never tried',
            ['stopped', 'quiet_warm', 'CPU', 65.0, $before, [], false], [$step['status'], $step['why'], $step['quiet_stop']['what'] ?? null, $step['quiet_stop']['c'] ?? null,
                array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld(), isset($step['results'][fanboyTId(4)])]);
        fanboyR8Cpu($t, 40);
        // 2026-11-20 (the owner: «there is a switch for stopping the others; that is how it must be»): a CPU cooler stands
        // too — whatever its role; an output that doesn't take 0 goes on
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $settings['fans'][fanboyTId(4)]['role'] = 'cpu';
        fanboyTStore($settings);
        $spec['revert'] = [5 => 1];
        $low = [];
        [$step] = fanboyR8Run($t, $spec, [1], true, function () use ($t, &$low): void {
            $low[] = [fanboyTOut($t, 4)[0], fanboyTOut($t, 2)[0]];
        });
        same('fanboy r8 guards: the switch on — the CPU cooler (role) stands too, the others stand; the test done', [true, true, 'done'],
            [in_array(0, array_column($low, 0), true), in_array(0, array_column($low, 1), true), $step['status']]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * G1: a fan that turned before and doesn't turn again after the quiet phase — its output (the one the setup paired it
 * with) to full and staying there, an alert naming it, the test stops; the step dying, hanging or the page going away
 * gives every quieted output back through the existing ways
 */
function testFanboyR8QuietSafety(): void
{
    $t = fanboyT('r8safe');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        // the disk cage's fan (output 4, tach 4) stops while it stands — and doesn't start again
        [$step] = fanboyR8Run($t, $spec, [1, 2], true, function () use ($t, &$spec): void {
            if (fanboyTOut($t, 4)[0] === 0) {
                $spec['fans'][4]['stuck'] = true;
            }
        });
        same('fanboy r8 safety: a fan that doesn\'t turn again — its output (4, paired with tach 4) to full and kept there, the test stopped naming it, output 2 never tried',
            ['stopped', 'no_restart', 255, false, true], [$step['status'], $step['why'], fanboyTOut($t, 4)[0], isset($step['results'][fanboyTId(2)]),
                str_contains($step['quiet_stop']['what'] ?? '', 'Cage')]);
        check('fanboy r8 safety: … an alert naming it', in_array('Fanboy: Cage didn\'t turn again', fanboyR5Subjects($t), true));
        unset($spec['fans'][4]['stuck']);
        file_put_contents("{$t['hw']}/pwm4", "128\n");
        fanboyTSettle($t, $spec);
        // the page gone (45 s rule) in the middle of a quiet phase: every quieted output back
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        [$step] = fanboyR8Run($t, $spec, [1], true, null, 6.0);
        same('fanboy r8 safety: the page gone during the quiet phase — stopped, every output back exactly, nothing held',
            ['stopped', 'page_gone', $before, []], [$step['status'], $step['why'], array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // a step that died (killed hard) or hangs, holding the quieted outputs: the agent's look gives them back
        $outs = fanboyOutputs(fanboyDetect());
        foreach (['dead' => ['kind' => 'step', 'pid' => 999999, 'start' => '1', 'since' => time()], 'hung' => fanboyMe('step')] as $how => $owner) {
            $held = fanboyQuietStop(fanboyQuietOthers(fanboyTId(1), $outs, fanboyBoardRaw()), $owner, FANBOY_T_KEY);
            if ($how === 'hung') {
                file_put_contents(fanboyRunDir() . '/beat-' . getmypid(), (string) (hrtime(true) - 60_000_000_000));      // its beat 60 s old (an integer: a float prints as 2.8E+14 on a server up for days)
            }
            $quietNow = array_map(fn ($n) => fanboyTOut($t, $n)[0], [2, 3, 4, 5, 6]);
            $back = fanboyWatch();
            same("fanboy r8 safety: a step $how while it held the others at 0 (" . implode(',', $quietNow) . ') — the agent\'s look gives every one back exactly',
                [count($held), $before, []], [count($back), array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        }
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 8 (the owner: up to a minute, the user ends it): «Gefunden — weiter» ends the output under test early — the
 * others back at once, the output once the looks agreed (or 8 s), the usual pause, then the next; «Abbrechen» ends the
 * whole run with everything back; the guard still stops a quiet phase at its 40th second
 */
function testFanboyR8FoundNext(): void
{
    $t = fanboyT('r8next');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        $log = [];
        $clicked = null;
        [$step, $clock] = fanboyR8Run($t, $spec, [1, 4], true, function (float $clock) use ($t, &$log, &$clicked): void {
            $log[] = [$clock, array_map(fn ($n) => fanboyTOut($t, $n)[0], [1, 2, 3, 4, 5, 6])];
            if ($clicked === null && $clock >= 12.0 && fanboyTOut($t, 1)[0] === 255) {
                $clicked = $clock;
                fanboyStepNext(['fan' => fanboyTId(1)]);
            }
        });
        $after = array_values(array_filter($log, fn ($l) => $l[0] > $clicked));
        $othersBack = min(array_column(array_filter($after, fn ($l) => $l[1][1] !== 0), 0)) - $clicked;
        $oneBack = min(array_column(array_filter($after, fn ($l) => $l[1][0] !== 255), 0)) - $clicked;
        same('fanboy r8 next: «Gefunden — weiter» at 12 s — the others back with the next look, output 1 at once (its looks had agreed), found tach 1; output 4 next, the run done',
            [true, true, 'tach', 'tach', 'done'], [$othersBack <= 0.5, $oneBack <= 1.0, $step['results'][fanboyTId(1)]['found'], $step['results'][fanboyTId(4)]['found'], $step['status']]);
        same('fanboy r8 next: … every output exactly as before, nothing held', [$before, []], [array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // a click before two looks agreed (a slow fan): the others back at once, the output on until they agree or 8 s
        $slow = fanboyR8Spec(6.0);
        $log = [];
        $clicked = null;
        fanboyR8Run($t, $slow, [1], true, function (float $clock) use ($t, &$log, &$clicked): void {
            $log[] = [$clock, array_map(fn ($n) => fanboyTOut($t, $n)[0], [1, 2, 3, 4, 5, 6])];
            if ($clicked === null && fanboyTOut($t, 1)[0] === 255 && fanboyTOut($t, 2)[0] === 0) {
                $clicked = $clock;
                fanboyStepNext(['fan' => fanboyTId(1)]);
            }
        });
        $full = array_column(array_filter($log, fn ($l) => $l[1][0] === 255), 0);
        $held = max($full) - min($full);
        check("fanboy r8 next: clicked at the first look of a slow fan — the others back at once, output 1 at full on until its looks agreed or 8 s (here $held s)",
            $held >= 1.0 && $held <= FANBOY_UP_MIN + 0.5 && !array_filter($log, fn ($l) => $l[0] > $clicked + 0.5 && $l[1][1] === 0));
        same('fanboy r8 next: a «found» for an output not under test — quietly nothing', [true, null], (function () { $r = fanboyStepNext(['fan' => fanboyTId(3)]); return [$r['ok'], is_file(fanboyStepNextFile()) ? 'x' : null]; })());
        // «Abbrechen» (the step asked to stop) half-way through a quiet phase: the whole run ends, everything back at once
        [$step] = fanboyR8Run($t, $spec, [1, 4], true, null, 30.0);
        same('fanboy r8 next: «Abbrechen» at 30 s — the run ended, output 4 never tried, every output exactly as before', ['stopped', false, $before, []],
            [$step['status'], isset($step['results'][fanboyTId(4)]), array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // the guard at the 40th second of a quiet phase
        $at = null;
        [$step] = fanboyR8Run($t, $spec, [1], true, function (float $clock) use ($t, &$at): void {
            if ($at === null && $clock >= 42.0 && fanboyTOut($t, 2)[0] === 0) {
                fanboyR8Cpu($t, 81);
                $at = $clock;
            }
        });
        fanboyR8Cpu($t, 28);
        same('fanboy r8 next: the CPU at 81 °C in the 40th second of the quiet phase — everything back at once, the test stopped naming it', ['quiet_warm', 81.0, $before, []],
            [$step['why'], $step['quiet_stop']['c'] ?? null, array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 8 (the owner: test and assign in ONE step): what the roles step did is on the test's card — name, role (incl.
 * «nothing attached», «leave it with the BIOS»), count, place, model, colour, «give back to», the CPU cooler on own risk —
 * saved at once, before or WHILE the output is tested; a save during a running test leaves every output as the test has
 * it; a CPU cooler or pump named during the run is not stopped by the following quiet phases; six steps everywhere
 */
function testFanboyR8Assign(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    // fanboy-ux (2026-11-15): the card is the output's panel now (panelMake + cpuBlock), its actions queued through send()
    $card = substr($js, $at = (int) strpos($js, 'function panelMake('), (int) strpos($js, 'function measurePanel(') - $at);   // the panel and its CPU block
    // 2026-11-20: one output, one pass, one save — role, count, place, model, colour and name go into the draft, «Speichern» sends them (fan_save)
    $reach = ['draft.role = k;', "send('fan_save', body)", 'draft.count = Number(count.value)', 'draft.place = place.value',
        'keep({ model: v.id,', 'keep(body);', 'backChoice(f, b)', 'cpuBlock(f, benchUpdate)', 'nameField(id, f,', "send('show', { fan: id,"];
    same('fanboy r8 assign: every action of the old roles step reachable from the output\'s panel (role, nothing attached, count, place, model, colour, give back, CPU on own risk, name, show me)',
        [], array_values(array_filter($reach, fn ($x) => !str_contains($card, $x))));
    same('fanboy r8 assign: six steps — the old route …/setup/roles opens «Testen und zuordnen»; no roles step left', [true, false, false],
        [str_contains($js, "if (rest === 'roles') rest = 'test';"), str_contains($js, 'function rolesStep('), str_contains($js, "return 'roles'")]);
    foreach (['de' => '5', 'en' => '5', 'it' => '5', 'fr' => '5', 'es' => '5'] as $l => $n) {       // five since 2026-11-16 (no dry-run step)
        $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$l.json"), true);
        check("fanboy r8 assign: $l — the steps counted as six (blocked: step $n), the roles step's keys gone", str_contains($j['step.blocked_steering'], $n) && !isset($j['step.roles']));
    }
    $t = fanboyT('r8assign');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        // before the test: a name, a role, «nothing attached», a look — each for an output not tested yet
        fanboyFanName(['fan' => fanboyTId(2), 'name' => 'Deckel hinten']);
        fanboyRole(['fan' => fanboyTId(5), 'role' => 'case']);
        fanboyAnswer(['fan' => fanboyTId(3), 'louder' => false]);
        fanboyFanLook(['fan' => fanboyTId(5), 'colour' => 'white']);
        $f = fanboyBoardRaw()['fans'];
        same('fanboy r8 assign: before any test — a name, a role, «nothing attached», a colour saved', ['Deckel hinten', 'case', 'empty', 'white'],
            [$f[fanboyTId(2)]['name'] ?? null, $f[fanboyTId(5)]['role'] ?? null, $f[fanboyTId(3)]['found'] ?? null, $f[fanboyTId(5)]['colour'] ?? null]);
        same('fanboy r8 assign: … a name without the field — refused (nothing turned off by a missing key)', 'missing_field', fanboyR6Refused(fn () => fanboyFanName(['fan' => fanboyTId(2)])));
        // during the quiet test of output 1: its name and role typed while it runs, output 4 called the CPU cooler
        $outsDuring = null;
        $low4 = [];
        [$step] = fanboyR8Run($t, $spec, [1, 5], true, function (float $clock) use ($t, &$outsDuring, &$low4): void {
            if ($outsDuring === null && fanboyTOut($t, 1)[0] === 255 && $clock >= 6) {
                $outsDuring = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
                fanboyFanName(['fan' => fanboyTId(1), 'name' => 'Hinten oben']);
                fanboyRole(['fan' => fanboyTId(1), 'role' => 'case', 'count' => 2]);
                fanboyRole(['fan' => fanboyTId(4), 'role' => 'cpu']);
                $after = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
                $outsDuring = $outsDuring === $after;
            }
            if (fanboyTOut($t, 5)[0] === 255) {
                $low4[] = fanboyTOut($t, 4)[0];
            }
        });
        $f = fanboyBoardRaw()['fans'];
        same('fanboy r8 assign: saved while output 1 was under test — no output touched by the saves, the test went on (tach 1), name, role and count kept through its verdict',
            [true, 'tach', 'Hinten oben', 'case', 2], [$outsDuring, $step['results'][fanboyTId(1)]['found'], $f[fanboyTId(1)]['name'] ?? null, $f[fanboyTId(1)]['role'] ?? null, $f[fanboyTId(1)]['count'] ?? null]);
        check('fanboy r8 assign: output 4 called the CPU cooler during the run — the next quiet phase (output 5) holds it still too (the switch says so, 2026-11-20)', $low4 && in_array(0, $low4, true));
        $fans = array_column(fanboyScan()['setup']['fans'], null, 'id');
        same('fanboy r8 assign: the page\'s state shows what was saved on the cards (names, roles)', ['Hinten oben', 'Deckel hinten', 'case', 'cpu'],
            [$fans[fanboyTId(1)]['name'], $fans[fanboyTId(2)]['name'], $fans[fanboyTId(5)]['role'], $fans[fanboyTId(4)]['role']]);
        // what never takes a role: an output found empty, one the BIOS keeps
        same('fanboy r8 assign: no role for «nothing attached» or an output not there', ['fanboy_no_output', 'fanboy_no_output'],
            [fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(3), 'role' => 'case'])), fanboyR6Refused(fn () => fanboyFanName(['fan' => fanboyTId(9), 'name' => 'x']))]);
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== round 9: the owner's third test on the real server

/**
 * H1: a fan that KEEPS turning at its value before (50 %) but doesn't START at it — after the quiet phase it stood; now it
 * gets a kick (full 3 s, back, 10 s to turn) before any verdict: it turns, nothing to full, the test goes on, and its card
 * learns «needs a kick to start»; one that never starts: full + alert as before
 */
function testFanboyR9Kick(): void
{
    $t = fanboyT('r9kick');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $spec = fanboyR8Spec();
        $spec['fans'][4]['start'] = 0.6;            // the disk cage's fan: turns on at 50 %, starts only at 60 %
        fanboyTSettle($t, $spec);
        check('fanboy r9 kick: the disk cage turns at its 50 % before the test', fanboyAllTachs()[fanboyTachId(FANBOY_T_CHIP, FANBOY_T_ADDR, 4)] > 500);
        [$step] = fanboyR8Run($t, $spec, [1, 2], true, function (float $clock) use ($t): void {
            if ($clock >= 12 && fanboyTOut($t, 1)[0] === 255 && fanboyTOut($t, 2)[0] === 0) {
                fanboyStepNext(['fan' => fanboyTId(1)]);
            }
        });
        same('fanboy r9 kick: it didn\'t start by itself after the quiet phase — kicked, it turns: no «no_restart», nothing at full, the run done, output 4 back at 128 and turning',
            ['done', null, [128, 1], true, []], [$step['status'], $step['why'], fanboyTOut($t, 4), fanboyAllTachs()[fanboyTachId(FANBOY_T_CHIP, FANBOY_T_ADDR, 4)] > 500, array_keys(fanboySafetyFull())]);
        same('fanboy r9 kick: … «needs a kick to start» kept for the disk cage (the value it doesn\'t start at)', 128, fanboyBoardRaw()['fans'][fanboyTId(4)]['kick_start']['value'] ?? null);
        check('fanboy r9 kick: … no alert', !in_array('Fanboy: Cage didn\'t turn again', fanboyR5Subjects($t), true));
        // one that never starts again: kicked, still standing — full and an alert, the test stops
        [$step] = fanboyR8Run($t, $spec, [1], true, function () use ($t, &$spec): void {
            if (fanboyTOut($t, 4)[0] === 0) {
                $spec['fans'][4]['stuck'] = true;
            }
        });
        same('fanboy r9 kick: a fan that doesn\'t start even after the kick — full, an alert, the test stopped', ['no_restart', 255, true],
            [$step['why'], fanboyTOut($t, 4)[0], in_array('Fanboy: Cage didn\'t turn again', fanboyR5Subjects($t), true)]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * H2: a fan Fanboy set to full for safety is visible as such (his state: why, the state before; never «a fixed value someone
 * left») and undone by «Zurück auf den Wert von vorher» — back to the state before, a kick when it doesn't start; the state
 * ends there (and when he takes the output); a fan that still doesn't start: full again, said
 */
function testFanboyR9Safety(): void
{
    $t = fanboyT('r9safe');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        [$step] = fanboyR8Run($t, $spec, [1], true, function () use ($t, &$spec): void {
            if (fanboyTOut($t, 4)[0] === 0) {
                $spec['fans'][4]['stuck'] = true;
            }
        });
        $state = fanboyScan();
        same('fanboy r9 safety: output 4 at full for safety — in his state (why «no_restart», its value before 128); not counted as «left by someone»',
            ['no_restart', 128, false], [$state['safety'][fanboyTId(4)]['why'] ?? null, $state['safety'][fanboyTId(4)]['before']['value'] ?? null,
                in_array(fanboyTId(4), array_column($state['who']['fixed'] ?? [], 'id'), true)]);
        // the fan works again but needs a kick: «back to the value before» — back at 128, kicked, turning, the state over
        unset($spec['fans'][4]['stuck']);
        $spec['fans'][4]['start'] = 0.6;
        fanboyTSettle($t, $spec);                      // at full it turns
        fanboyRestoreStart(['fan' => fanboyTId(4)]);
        same('fanboy r9 safety: the click starts its own process', ['fanboy-restore'], array_values(array_unique(array_filter($GLOBALS['fanboyLaunched'], fn ($j) => $j === 'fanboy-restore'))));
        $clock = 0.0;
        fanboyRestoreMain(fanboyTIo($t, $spec, $clock));
        fanboyTSettle($t, $spec, 3);
        same('fanboy r9 safety: «back to the value before» — output 4 at 128 again (a fixed value), turning after its kick, the state over, nothing held',
            ['done', [128, 1], true, [], []], [fanboyRestoreRead()['status'] ?? null, fanboyTOut($t, 4), fanboyAllTachs()[fanboyTachId(FANBOY_T_CHIP, FANBOY_T_ADDR, 4)] > 500,
                array_keys(fanboySafetyFull()), fanboyHeld()]);
        // a fan that still doesn't start: full again, said
        fanboyFullNow([fanboyTId(4)], 'no_restart');
        $spec['fans'][4]['stuck'] = true;
        fanboyTSettle($t, $spec);
        fanboyRestoreStart(['fan' => fanboyTId(4)]);
        $clock = 0.0;
        fanboyRestoreMain(fanboyTIo($t, $spec, $clock));
        same('fanboy r9 safety: still standing after the kick — full again for safety, the job says so', ['failed', 'no_restart', 255, [fanboyTId(4)]],
            [fanboyRestoreRead()['status'], fanboyRestoreRead()['why'], fanboyTOut($t, 4)[0], array_keys(fanboySafetyFull())]);
        // he takes it (a test, the control): the state ends
        $o = fanboyOutputs(fanboyDetect())[fanboyTId(4)];
        fanboyTake($o, fanboyMe('step'));
        fanboyGiveBack([fanboyTId(4)], 'test', fanboyMe('step'));
        same('fanboy r9 safety: taken by him again — the safety state is over', [], array_keys(fanboySafetyFull()));
        same('fanboy r9 safety: «back» for an output not at full for safety — quietly nothing', true, fanboyRestoreStart(['fan' => fanboyTId(2)])['ok']);
    } finally {
        fanboyTEnd();
    }
}

/**
 * H3/H4: the page always says what runs (a test's output at full, the pause, a kick, a show, a safety full — or nothing),
 * frames the card at full like the active tiles, a refused click always says why; «Alles stoppen» stops a test or show
 * and brings the safety fulls back; a test stopped half-way still kicks a fan that doesn't start (never left standing)
 */
function testFanboyR9Now(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $css = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.css');
    check('fanboy r9 now: one line says what runs (full, full with the others standing, pause with seconds, kick, show, back, safety, nothing) — above the test step and the fans list',
        // (fanboy-ux: the test step says it in its strip — stripUpdate(), testFanboyUx; the fans list keeps the line)
        str_contains($js, 'function stripMake() {') && str_contains($js, 'sw.el.insertBefore(strip.el, sw.body);')
        && !array_filter(['strip.test', 'strip.test_quiet', 'strip.show', 'strip.show_quiet', 'strip.pause', 'strip.pause_next', 'strip.kick', 'strip.restore', 'strip.measure', 'strip.safety', 'strip.idle'], fn ($k) => !str_contains($js, "'$k'")));
    check('fanboy r9 now: the card and row at full framed like the office\'s active tiles; «Alles stoppen» beside the line while anything runs',
        str_contains($css, '.fb-row-now{border-color:var(--accent); background:var(--accent-soft);') && str_contains($css, '.fb-live{position:relative; border-color:var(--accent);')
        && str_contains($js, "return send('stop_all', {}, { done: () => Office.toast(T('stop_all_toast')) });"));
    check('fanboy r9 now: a click that can\'t go through says why (busy, the messenger away) — never silence',
        str_contains($js, "if (busy) { Office.toast(T('busy_wait'), true); return null; }") && str_contains($js, "if (!Office.agent.running) { Office.toast(t('errors.agent_away'), true); return null; }"));
    check('fanboy r9 fields: the cards\' selects and fields in the office\'s field look (input small), full width there',
        // (fanboy-ux: one helper gives every field of the panel that look — inputEl())
        str_contains($js, "const n = el(tag, 'input small fb-input');") && substr_count($js, "inputEl('select')") >= 3 && str_contains($js, "n = inputEl('input', 'text');") && str_contains($css, '.fb-fields .fb-input{width:100%; flex:none}'));
    $t = fanboyT('r9now');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $spec = fanboyR8Spec();
        $spec['fans'][4]['start'] = 0.6;            // needs a kick
        fanboyTSettle($t, $spec);
        // the step says its phases for the page: full (with the others standing), then the pause, the kick
        $phases = [];
        // and «Abbrechen» at 20 s, in the middle of the quiet phase; the page's view: the step's state as written
        [$step] = fanboyR8Run($t, $spec, [1, 2], true, function () use (&$phases): void {
            $p = fanboyStepRead()['progress']['phase'] ?? null;
            if ($p !== null && end($phases) !== $p) {
                $phases[] = $p;
            }
        }, 20.0);
        same('fanboy r9 now: the phases said (full → pause → kick); stopped at 20 s — and still the fan that doesn\'t start alone was kicked: back at 128, turning, nothing at full',
            [['full', 'pause', 'kick'], 'stopped', [128, 1], true, []], [array_values(array_unique($phases)), $step['status'], fanboyTOut($t, 4),
                fanboyAllTachs()[fanboyTachId(FANBOY_T_CHIP, FANBOY_T_ADDR, 4)] > 500, array_keys(fanboySafetyFull())]);
        // «Alles stoppen» with a safety full and nothing else running: the tick starts «back to the value before» for all
        fanboyFullNow([fanboyTId(4)], 'no_restart');
        $GLOBALS['fanboyLaunched'] = [];
        @mkdir(TESTS_RUN_DIR . '/fanboy', 0700, true);
        file_put_contents(TESTS_RUN_DIR . '/fanboy/stop-all', (string) time());
        fanboyStopAllTick();
        same('fanboy r9 now: «Alles stoppen» — once nothing runs, every safety full goes back (its process started for all), the mark gone',
            [['fanboy-restore'], [fanboyTId(4)], false], [$GLOBALS['fanboyLaunched'], fanboyRestoreRead()['fans'] ?? null, is_file(TESTS_RUN_DIR . '/fanboy/stop-all')]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * H6: the CPU cooler — choosing the role never switches «Fanboy steers it» on: that needs the role first (another click)
 * and the ticked confirmation; the card says plainly which of the two it is
 */
function testFanboyR9CpuOwn(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    check('fanboy r9 cpu: the card says which — «regelt das BIOS» or «regelt Fanboy auf dein Risiko seit …»; a role click forgets a half-made choice',
        // (fanboy-ux: the panel's note says «regelt das BIOS», the CPU cooler's own block «von Fanboy geregelt … seit» with «Zurück ans BIOS»)
        str_contains($js, "hint = f && f.cpu_own ? '' : T('assign.cpu_bios');") && str_contains($js, "box.appendChild(para(T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) })));") && str_contains($js, 'cpuAsk.delete(id);'));
    $t = fanboyT('r9cpu');
    try {
        fanboyTStore(fanboyTSettings());
        $raw = fanboyBoardRaw();
        unset($raw['fans'][fanboyTId(1)]['role']);
        fanboyTStore($raw);
        same('fanboy r9 cpu: the role and «Fanboy steers it» in one click — refused', 'fanboy_cpu_role_first',
            fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu', 'cpu_own' => true, 'cpu_confirm' => true])));
        fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu']);
        same('fanboy r9 cpu: the role «CPU cooler» chosen — the BIOS steers it (no own-risk date)', [true, false], [isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['role']), isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['cpu_own'])]);
        same('fanboy r9 cpu: … «Fanboy steers it» without the tick — refused, still the BIOS\'s', ['fanboy_cpu_confirm', false],
            [fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu', 'cpu_own' => true])), isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['cpu_own'])]);
        fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu', 'count' => 1]);
        same('fanboy r9 cpu: another field\'s save (the count) keeps it the BIOS\'s', false, isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['cpu_own']));
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 10: Fanboy in the Team Lead's notification centre — a sender like the others (his row, his settings honoured by
 * the one gate), but only while his switch is on; his alarms are alerts: never held, never swallowed by the flood guard
 */
function testFanboyR10Notify(): void
{
    // the lists: the same in bash (notifygate.sh) as in PHP — over every event of his and the others' named ones
    $gate = OFFICE_DIR . '/backup/lib/notifygate.sh';
    $bash = fn (string $fn, string $pair): bool => trim((string) shell_exec('bash -c ' . escapeshellarg('source ' . escapeshellarg($gate) . "; $fn \"\$1\" \"\$2\" && echo y")
        . ' _ ' . implode(' ', array_map('escapeshellarg', explode(':', $pair, 2))))) === 'y';
    $his = ['alarm_stands', 'alarm_stands_alone', 'alarm_bios_stands', 'alarm_sensor', 'alarm_chip', 'idle_stands', 'quiet_no_restart', 'not_back',
            'foreign', 'drift', 'refuses', 'missing', 'missing_stuck', 'crashed', 'back'];
    $pairs = [...array_map(fn ($e) => "fanboy:$e", $his), 'backup:ok', 'backup:failed', 'backup:refused', 'jack:release_ok', 'office:agent_back', 'watchman:failed'];
    foreach (['ung_resolves' => 'officeNotifyResolves', 'ung_is_success' => 'officeNotifyIsSuccess', 'ung_fail_same' => 'officeNotifyFailSame'] as $b => $p) {
        same("fanboy r10 centre: $b (notifygate.sh) says what $p() says",
            array_values(array_filter($pairs, fn ($x) => $p(...explode(':', $x, 2)))), array_values(array_filter($pairs, fn ($x) => $bash($b, $x))));
    }
    same('fanboy r10 centre: «back» (the fan chip there again, he steers) resolves his; no success of his, no run failure', [['fanboy:back'], [], []],
        [array_values(array_filter($pairs, fn ($x) => str_starts_with($x, 'fanboy:') && officeNotifyResolves(...explode(':', $x, 2)))),
         array_values(array_filter($pairs, fn ($x) => str_starts_with($x, 'fanboy:') && officeNotifyIsSuccess(...explode(':', $x, 2)))),
         array_values(array_filter($pairs, fn ($x) => str_starts_with($x, 'fanboy:') && officeNotifyFailSame(...explode(':', $x, 2))))]);
    // what he sends: every level his senders name — and each event's words there (the notes list them)
    $src = '';
    foreach (glob(OFFICE_DIR . '/agent/desks/fanboy*.php') ?: [] as $f) {
        $src .= (string) file_get_contents($f);
    }
    preg_match_all("/fanboyTell(?:Once)?\\([^;]*'(alert|warning|normal)'\\);/", $src, $m);
    $levels = array_values(array_unique([...$m[1], ...(str_contains($src, "'alert', '', officeNotifyLink('#/fanboy'), 'fanboy', \$key);") ? ['alert'] : [])]));
    sort($levels);
    $sends = OFFICE_NOTIFY_SENDS['fanboy'] ?? [];
    sort($sends);
    same('fanboy r10 centre: his row says what he sends — alerts, warnings, notices (from his senders)', $sends, $levels);
    check('fanboy r10 centre: the guard sends nothing (the agent tells what it found)', !str_contains((string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php'), 'officeNotify('));
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/en.json'), true);
    check('fanboy r10 centre: each event of his has its words', !array_filter($his, fn ($e) => !isset($en["notify.{$e}_subject"], $en["notify.$e"])));
    same('fanboy r10 centre: his place in the list — after Jack Streamer, before Ms. Logs', [array_search('jack', OFFICE_NOTIFY_DESKS, true) + 1, array_search('logs', OFFICE_NOTIFY_DESKS, true) - 1],
        [array_search('fanboy', OFFICE_NOTIFY_DESKS, true), array_search('fanboy', OFFICE_NOTIFY_DESKS, true)]);

    // 2026-11-16 (he is out for everyone): a sender like the others — no switch decides; a desk without its desk.json: no row
    $tmp = hardeningTmp('fanboy-r10nc');
    same('fanboy r10 centre: the senders — everyone, him too', OFFICE_NOTIFY_DESKS, officeNotifyDesks());
    same('fanboy r10 centre: … a desk.json not there (another web folder): no row for it, the office\'s own there', [false, true],
        [in_array('fanboy', officeNotifyDesks("$tmp/no-web"), true), in_array('office', officeNotifyDesks("$tmp/no-web"), true)]);
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/caretaker/desk.js');
    check('fanboy r10 centre page: a row only for a colleague the page knows and has hired (his name and avatar like the others), his line under it',
        str_contains($js, "nc.desks.filter((d) => d === 'office' || (Office.desks.has(d) && Office.desks.get(d).hired))")
        && str_contains($js, "if (Office.has(`\${ID}.nc.note.\${id}`)) main.appendChild(el('div', 'row-detail', T('nc.note.' + id)));"));

    $t = fanboyT('r10nc');
    $homeWas = getenv('OFFICE_NOTIFY_HOME');
    $h = "$tmp/home";
    @mkdir($h, 0700, true);
    try {
        $req = fn (array $mine) => ['desks' => ['fanboy' => $mine + ['level' => 'all', 'success' => true, 'when' => 'now', 'at' => null]], 'at' => '08:00',
                                    'quiet' => ['on' => false, 'from' => '22:00', 'to' => '07:00'], 'all_well' => false];
        $f = "$tmp/notify.json";
        $p = officeNotifyPublic();
        same('fanboy r10 centre: his row, what he sends (no switch file in his data folder)', [true, ['alert', 'warning', 'normal']], [in_array('fanboy', $p['desks'], true), $p['sends']['fanboy'] ?? null]);
        officeNotifySave($req(['when' => 'summary', 'at' => '07:15']), $f);
        same('fanboy r10 centre: … his setting saved', ['all', 'summary', '07:15'], array_values(array_intersect_key(readJson($f)['desks']['fanboy'] ?? [], array_flip(['level', 'when', 'at']))));

        // the gate honours it: a warning held for his summary, an alert at once — through his own senders
        putenv("OFFICE_NOTIFY_HOME=$h");
        touch("$h/agent.json");                          // the messenger at work: he will send the summary
        officeNotifyCfgKeep(officeNotifySettings(readJson($f) ?? []), $h, null);
        $calls = fn (): array => array_values(array_filter(explode("\n", (string) @file_get_contents($t['tmp'] . '/notify.log'))));
        $drops = fn (): array => array_values(array_map(fn ($i) => [$i['event'], $i['level'], $i['fate'], $i['why']], officeNotifyDropsRead($h)));
        fanboyTellOnce('crashed', 'crashed', [], 'warning');
        same('fanboy r10 gate: «in the summary» — his warning held, nothing to Unraid', [[['crashed', 'warning', 'held', 'summary']], 0], [$drops(), count($calls())]);
        fanboyTellOnce('not_back:x', 'not_back', ['fan' => 'Vorn'], 'alert');
        same('fanboy r10 gate: … his alert at once', [['not_back', 'alert', 'sent', ''], 1], [$drops()[1] ?? null, count($calls())]);

        // the flood guard (right away again): five warnings of his this hour (his cap) — then different alarms within a minute: each goes
        officeNotifyCfgKeep(officeNotifySettings([]), $h, null);
        $now = time();
        for ($i = 1; $i <= NOTIFY_DESK_CAP; $i++) {
            officeNotifyGuardLine($h, 'sent', 'fanboy', 'warning', "w$i", $now - 60);
        }
        $c = ['told' => []];
        fanboyTell($c, 'alarm:stands:Vorn', 'alarm_stands', ['fan' => 'Vorn', 'zone' => 'Gehäuse'], 'alert');
        fanboyIdleTell('idle_stands', ['fan' => 'Deckel', 'source' => 'CPU', 'c' => 71]);
        fanboyTell($c, 'alarm:sensor:Gehäuse', 'alarm_sensor', ['zone' => 'Gehäuse', 'source' => 'CPU'], 'alert');
        $alerts = array_values(array_filter($calls(), fn ($l) => str_contains($l, ' -i alert')));
        same('fanboy r10 gate: past his cap of warnings, three different alarms within a minute — each one sent', [4, ['sent', 'sent', 'sent']],
            [count($alerts), array_column(array_slice($drops(), 2), 2)]);
        fanboyTell($c, 'drift:Vorn', 'drift', ['fan' => 'Vorn', 'rpm' => 500, 'want' => 900], 'warning');
        same('fanboy r10 gate: … a warning of his past the cap waits (collected, the summary)', ['drift', 'collected', 'flood'], [($d = $drops())[count($d) - 1][0], $d[count($d) - 1][2], $d[count($d) - 1][3]]);

        // «Nur Alarme»: warnings and notices dropped, alerts sent; «back» still resolves his
        officeNotifyCfgKeep(officeNotifySettings(['desks' => ['fanboy' => ['level' => 'alert']]]), $h, null);
        $before = count($calls());
        fanboyTell($c, 'refuses:Vorn', 'refuses', ['fan' => 'Vorn'], 'warning');
        fanboyTell($c, 'back', 'back', [], 'normal');
        fanboyTell($c, 'alarm:chip:', 'alarm_chip', [], 'alert');
        same('fanboy r10 gate: «alerts only» — his warning and notice not sent, his alarm sent', [['refuses', 'dropped'], ['back', 'dropped'], ['alarm_chip', 'sent'], 1],
            [...array_map(fn ($d) => [$d[0], $d[2]], array_slice($drops(), -3)), count($calls()) - $before]);
        check('fanboy r10 gate: … and «back» resolved his (a line in the guard)', (bool) array_filter(officeNotifyGuardRead($h), fn ($l) => $l[1] === 'resolved' && $l[2] === 'fanboy'));
    } finally {
        putenv($homeWas === false ? 'OFFICE_NOTIFY_HOME' : "OFFICE_NOTIFY_HOME=$homeWas");
        fanboyTEnd();
    }
}

/** Round 11: a test run of outputs $outs; at $clickAt (on its clock, while output $fan is at full) «weiter» with $req */
function fanboyR11Run(array $t, array &$spec, array $outs, ?float $clickAt, array $req, ?float $stopAt = null, ?callable $each = null): array
{
    $clicked = false;
    return fanboyR8Run($t, $spec, $outs, false, function (float $clock) use ($t, $clickAt, $req, &$clicked, $each): void {
        $n = (int) substr((string) $req['fan'], -1);
        if (!$clicked && $clickAt !== null && $clock >= $clickAt && fanboyTOut($t, $n)[0] === 255) {
            $clicked = true;
            fanboyStepNext($req);
        }
        if ($each) {
            $each($clock);
        }
    }, $stopAt)[0];
}

function fanboyR11Board(array $t): void
{
    file_put_contents("{$t['hw']}/fan2_input", "0\n");          // outputs 2 and 3: nothing attached that the chip could read
    file_put_contents("{$t['hw']}/fan3_input", "0\n");
}

/**
 * Round 11 (the owner lost two answers: the page waited for the verdict, then sent `answer`): `step_next {fan, heard}`
 * carries the answer and the step stores it WITH the verdict — a tach that followed wins; no tach: heard → a fan without
 * feedback, not heard → nothing attached, no `heard` → as before (ask); what the user entered meanwhile stays
 */
function testFanboyR11Heard(): void
{
    $t = fanboyT('r11heard');
    try {
        fanboyTDisks($t, 35, null, 33);
        fanboyR11Board($t);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $got = [];
        foreach ([1 => 'tach', 2 => 'none'] as $n => $kind) {
            foreach (['true' => true, 'false' => false, 'absent' => null] as $k => $heard) {
                $req = ['fan' => fanboyTId($n)] + ($heard === null ? [] : ['heard' => $heard]);
                fanboyStoreUpdate(function (array $e) use ($n): array {
                    unset($e['fans'][fanboyTId($n)]);          // each case on its own (never tested yet)
                    return $e;
                });
                $named = false;
                $step = fanboyR11Run($t, $spec, [$n], 12.0, $req, null, function (float $clock) use ($n, &$named): void {
                    if (!$named && $clock >= 5.0) {
                        $named = true;          // entered while it runs: the name and a role
                        fanboyFanName(['fan' => fanboyTId($n), 'name' => "Vorn $n"]);
                        fanboyRole(['fan' => fanboyTId($n), 'role' => 'case', 'count' => 2]);
                    }
                });
                $f = fanboyBoardRaw()['fans'][fanboyTId($n)] ?? [];
                $r = $step['results'][fanboyTId($n)] ?? [];
                $got["$kind/$k"] = [$f['found'] ?? null, $r['found'] ?? null, $r['looked'] ?? null, array_key_exists('heard', $r) ? $r['heard'] : 'x', $f['name'] ?? null, $f['role'] ?? null, $f['count'] ?? null,
                    is_int($f['tested_at'] ?? null)];
            }
        }
        same('fanboy r11 heard: tach × heard true/false/absent — «tach» always (the answer ignored); no tach — silent, empty, ask (as before); name, role, count kept',
            ['tach/true' => ['tach', 'tach', 'tach', true, 'Vorn 1', 'case', 2, true], 'tach/false' => ['tach', 'tach', 'tach', false, 'Vorn 1', 'case', 2, true],
             'tach/absent' => ['tach', 'tach', 'tach', null, 'Vorn 1', 'case', 2, true],
             'none/true' => ['silent', 'silent', 'ask', true, 'Vorn 2', 'case', 2, true], 'none/false' => ['empty', 'empty', 'ask', false, 'Vorn 2', 'case', 2, true],
             'none/absent' => ['ask', 'ask', 'ask', null, 'Vorn 2', 'case', 2, true]], $got);
        same('fanboy r11 heard: «heard» only true or false — a string refused', 'bad_request', fanboyR6Refused(fn () => fanboyStepNext(['fan' => fanboyTId(2), 'heard' => 'yes'])));

        // nothing attached: out of every zone, as `answer {louder: false}`
        fanboyStoreUpdate(function (array $e): array {
            $e['zones'] = [['id' => 'z1', 'name' => 'Gehäuse', 'fans' => [fanboyTId(1), fanboyTId(2)], 'sources' => []]];
            return $e;
        });
        fanboyR11Run($t, $spec, [2], 12.0, ['fan' => fanboyTId(2), 'heard' => false]);
        same('fanboy r11 heard: «nothing heard» — out of its zone, as the answer «no» does', [fanboyTId(1)], fanboyBoardRaw()['zones'][0]['fans'] ?? null);

        // the click in the pause after its full phase (the quiet test; the page still showed the buttons): never lost
        $done = false;
        [$step] = fanboyR8Run($t, $spec, [2], true, function () use (&$done): void {
            if (!$done && (fanboyStepRead()['progress']['phase'] ?? null) === 'pause') {
                $done = true;
                fanboyStepNext(['fan' => fanboyTId(2), 'heard' => true]);
            }
        });
        same('fanboy r11 heard: «found» clicked in the pause after it — stored with its verdict (silent), the file gone', ['silent', 'silent', false],
            [fanboyBoardRaw()['fans'][fanboyTId(2)]['found'] ?? null, $step['results'][fanboyTId(2)]['found'] ?? null, is_file(fanboyStepNextFile())]);
        // … and after the run: the answer for an output this step ended is stored at once
        fanboyR11Run($t, $spec, [2], null, ['fan' => fanboyTId(2)]);
        fanboyStepNext(['fan' => fanboyTId(2), 'heard' => false]);
        same('fanboy r11 heard: a late «nothing heard» for an output the step already ended — stored at once (empty)', 'empty', fanboyBoardRaw()['fans'][fanboyTId(2)]['found'] ?? null);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 11: a run the user ends always leaves a verdict for the output that was at full long enough — stopped, the page
 * gone, too warm — what the looks saw so far («tach», else «ask»), with tested_at; the owner's output 3 kept an old
 * «ignored» of round 7 after a 54-second run that ended in a stop
 */
function testFanboyR11StopVerdict(): void
{
    $t = fanboyT('r11stop');
    try {
        fanboyTDisks($t, 35, null, 33);
        fanboyR11Board($t);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        // the owner's store: output 3 «ignored» without a reason (before round 8), tested long ago
        fanboyStoreUpdate(function (array $e): array {
            $e['fans'][fanboyTId(3)] = ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 3, 'tach' => null, 'tachs' => [], 'found' => 'ignored', 'tested_at' => 1000];
            return $e;
        });
        $step = fanboyR11Run($t, $spec, [3, 4], null, ['fan' => fanboyTId(3)], 56.0);
        $f = fanboyBoardRaw()['fans'][fanboyTId(3)] ?? [];
        same('fanboy r11 stop: stopped after 54 s at full — output 3 keeps what its looks saw (ask) with a new tested_at; output 4 never tried',
            ['stopped', 'ask', true, true, false], [$step['status'], $f['found'] ?? null, ($f['tested_at'] ?? 0) > 1000, ($step['results'][fanboyTId(3)]['partial'] ?? false) === true,
                isset($step['results'][fanboyTId(4)])]);
        $step = fanboyR11Run($t, $spec, [1], null, ['fan' => fanboyTId(1)], 20.0);
        same('fanboy r11 stop: … a tach that followed before the stop — «tach»', ['stopped', 'tach', [1]],
            [$step['status'], fanboyBoardRaw()['fans'][fanboyTId(1)]['found'] ?? null, fanboyBoardRaw()['fans'][fanboyTId(1)]['tachs'] ?? null]);
        $step = fanboyR11Run($t, $spec, [2], 12.0, ['fan' => fanboyTId(2), 'heard' => true], 13.0);
        same('fanboy r11 stop: «found — next» and the stop at once — its answer stored with it (silent)', 'silent', fanboyBoardRaw()['fans'][fanboyTId(2)]['found'] ?? null);
        fanboyStoreUpdate(function (array $e): array {
            $e['fans'][fanboyTId(5)] = ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 5, 'tach' => null, 'tachs' => [], 'found' => 'ignored', 'tested_at' => 1000];
            return $e;
        });
        $step = fanboyR11Run($t, $spec, [5], null, ['fan' => fanboyTId(5)], 6.0);
        same('fanboy r11 stop: stopped before the looks\' minimum at full — no verdict (the old one stays)', ['stopped', 'ignored', 1000, false],
            [$step['status'], fanboyBoardRaw()['fans'][fanboyTId(5)]['found'] ?? null, fanboyBoardRaw()['fans'][fanboyTId(5)]['tested_at'] ?? null, isset($step['results'][fanboyTId(5)])]);
        same('fanboy r11 stop: … every output exactly as before, nothing held, no «weiter» left', [[128, 1], [128, 1], [128, 1], [], false],
            [fanboyTOut($t, 1), fanboyTOut($t, 3), fanboyTOut($t, 5), fanboyHeld(), is_file(fanboyStepNextFile())]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 11: RUN_DIR/fanboy/step.next — the owner's server kept one after a run (the last output's id). Tied to the step's
 * id: a left-over file (round 8's plain id, or another step's) never ends the next run's first output early; gone when a
 * step starts and ends
 */
function testFanboyR11StaleNext(): void
{
    $t = fanboyT('r11stale');
    try {
        fanboyTDisks($t, 35, null, 33);
        fanboyR11Board($t);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        foreach (['round 8\'s plain id' => fanboyTId(1), 'another step\'s' => jsonEncode(['step' => 'old', 'fan' => fanboyTId(1), 'heard' => false])] as $what => $content) {
            @mkdir(fanboyRunDir(), 0700, true);
            file_put_contents(fanboyStepNextFile(), $content);
            $log = [];
            $step = fanboyR11Run($t, $spec, [1], null, ['fan' => fanboyTId(1)], null, function (float $clock) use ($t, &$log): void {
                $log[] = [$clock, fanboyTOut($t, 1)[0]];
            });
            $full = array_column(array_filter($log, fn ($l) => $l[1] === 255), 0);
            same("fanboy r11 stale: a left-over step.next ($what) — output 1 still the whole minute at full, found tach, the file gone",
                [true, 'tach', false], [$full && max($full) - min($full) >= FANBOY_T_ANSWER - 1.0, $step['results'][fanboyTId(1)]['found'] ?? null, is_file(fanboyStepNextFile())]);
        }
        // a «weiter» for this step and output: the file carries the step's id
        fanboyR11Run($t, $spec, [2], 10.0, ['fan' => fanboyTId(2), 'heard' => true], null, function (float $clock): void {
            static $seen = false;
            if (!$seen && is_file(fanboyStepNextFile())) {
                $seen = true;
                $j = readJson(fanboyStepNextFile());
                same('fanboy r11 stale: «weiter» written as {step, fan, heard} — the running step\'s id', [fanboyStepRead()['id'] ?? 'x', fanboyTId(2), true],
                    [$j['step'] ?? null, $j['fan'] ?? null, $j['heard'] ?? null]);
            }
        });
    } finally {
        fanboyTEnd();
    }
}

/** Round 11: progress.next — in the pause after an output (the quiet test) the output that follows, null after the last */
function testFanboyR11ProgressNext(): void
{
    $t = fanboyT('r11next');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $pauses = [];
        $other = [];
        [$step] = fanboyR8Run($t, $spec, [1, 4], true, function () use (&$pauses, &$other): void {
            $p = fanboyStepRead()['progress'] ?? null;
            if (is_array($p) && ($p['phase'] ?? null) === 'pause') {
                $pauses[$p['id']] = array_key_exists('next', $p) ? $p['next'] : 'missing';
            } elseif (is_array($p)) {
                $other[] = array_key_exists('next', $p) ? $p['next'] : 'missing';
            }
        });
        same('fanboy r11 next: in the pause after output 1 the next is output 4; after output 4 (the last) none; outside a pause null',
            ['done', [fanboyTId(1) => fanboyTId(4), fanboyTId(4) => null], [null]], [$step['status'], $pauses, array_values(array_unique($other))]);
        // stopped in the middle of the quiet phase: the pause that still comes names no next (the run ends)
        $pauses = [];
        fanboyR8Run($t, $spec, [1, 4], true, function () use (&$pauses): void {
            $p = fanboyStepRead()['progress'] ?? null;
            if (is_array($p) && ($p['phase'] ?? null) === 'pause') {
                $pauses[$p['id']] = $p['next'] ?? null;
            }
        }, 30.0);
        same('fanboy r11 next: stopped at 30 s — its pause names no next output', [fanboyTId(1) => null], $pauses);
    } finally {
        fanboyTEnd();
    }
}

/** Round 12: the board — four fans of his (stops and starts at 35 %, needs the kick, a PWM floor, full only once stood), a CPU cooler of his */
function fanboyR12Spec(): array
{
    $f = fn (int $out, array $x) => $x + ['output' => $out, 'max' => 1500, 'stall' => 0.08, 'start' => 0.34, 'lag' => 1.0];
    return ['hwmon' => 'hwmon10', 'fans' => [1 => $f(1, []), 2 => $f(2, []), 4 => $f(4, ['start' => 0.7]), 5 => $f(5, ['floor' => 300]), 6 => $f(6, ['full_only' => true])]];
}

function fanboyR12Store(array $measured = []): void
{
    $fan = fn (int $n, array $x) => $x + ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => $n, 'tach' => $n, 'tachs' => [$n], 'found' => 'tach',
        'role' => 'disks', 'back' => 'fixed', 'name' => "Fan $n"] + (isset($measured[$n]) ? ['measured' => $measured[$n]] : []);
    fanboyTStore(['board' => ['vendor' => 'Example Boards Inc.', 'model' => 'EB-Z100 PRO'], 'fans' => [
        fanboyTId(1) => $fan(1, []), fanboyTId(2) => $fan(2, ['role' => 'cpu', 'cpu_own' => ['at' => 1700000000]]), fanboyTId(4) => $fan(4, []),
        fanboyTId(5) => $fan(5, []), fanboyTId(6) => $fan(6, []),
    ], 'zones' => [], 'armed' => true]);
}

/** One measuring step of output $n on the tests' clock — each sleep handed to $each (the output's value, the step's progress) */
function fanboyR12Measure(array $t, array &$spec, int|array $n, ?callable $each = null): array
{
    $clock = 0.0;
    $io = fanboyTIo($t, $spec, $clock);
    $sleep = $io['sleep'];
    $io['sleep'] = function (float $s) use ($sleep, $each, &$clock): void {
        $sleep($s);
        if ($each) {
            $each($clock);
        }
    };
    return fanboyStepRun(['id' => 'r12m' . mt_rand(), 'kind' => 'measure', 'fans' => array_map('fanboyTId', (array) $n)], $io);
}

/**
 * Round 12 (the owner: «beim Vermessen wird Stillstand nicht versucht?»): at the end of the measuring of a fan that may be
 * let stand — 0 for up to 15 s: stops or a floor; the start from standstill (5 % steps from its lowest steady value, up to
 * 60 %), else the kick; still not: never safe to stand. Always turning again and exactly as before after it.
 */
function testFanboyR12Standstill(): void
{
    $t = fanboyT('r12stand');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $phases = [];
        $step = fanboyR12Measure($t, $spec, 1, function () use (&$phases): void {
            $p = fanboyStepRead()['progress'] ?? null;
            $k = is_array($p) ? ($p['phase'] ?? 'table') . (isset($p['pct']) ? ":{$p['pct']}" : '') : null;
            if ($k !== null && end($phases) !== $k) {
                $phases[] = $k;
            }
        });
        $m = fanboyBoardRaw()['fans'][fanboyTId(1)]['measured'] ?? [];
        same('fanboy r12: a fan that stops and starts from standstill at 35 % — stops, start_from_stop 35, safe; its table as before (down to 10 %)',
            ['done', true, null, 35, null, true, 10], [$step['status'], $m['stops'] ?? null, (array_key_exists('floor_rpm', $m) ? $m['floor_rpm'] : 'x'), $m['start_from_stop'] ?? null, (array_key_exists('kick_start', $m) ? $m['kick_start'] : 'x'), $m['stops_safe'] ?? null, $m['min_pct'] ?? null]);
        same('fanboy r12: … the phases the page shows: the table, «Stillstand wird geprüft», «Anlauf wird gesucht: 10 … 35 %», then the pause (every fan turns again)',
            ['table', 'stop_check', 'start_find:10', 'start_find:15', 'start_find:20', 'start_find:25', 'start_find:30', 'start_find:35', 'start_confirm:35', 'pause'],
            array_values(array_filter($phases, fn ($p) => $p === 'table' || !str_starts_with($p, 'table'))));
        same('fanboy r12: … afterwards exactly as before (128 fixed), turning, nothing held', [[128, 1], true, []], [fanboyTOut($t, 1), fanboyReadInt("{$t['hw']}/fan1_input") > 300, fanboyHeld()]);

        $step = fanboyR12Measure($t, $spec, 4);
        $m = fanboyBoardRaw()['fans'][fanboyTId(4)]['measured'] ?? [];
        same('fanboy r12: one that starts only with the kick — no start up to 60 %, the kick (full, then 15 %): kick_start 15, safe',
            ['done', true, null, 15, true, [128, 1], true], [$step['status'], $m['stops'] ?? null, (array_key_exists('start_from_stop', $m) ? $m['start_from_stop'] : 'x'), $m['kick_start'] ?? null, $m['stops_safe'] ?? null,
                fanboyTOut($t, 4), fanboyReadInt("{$t['hw']}/fan4_input") > 300]);

        $step = fanboyR12Measure($t, $spec, 5);
        $m = fanboyBoardRaw()['fans'][fanboyTId(5)]['measured'] ?? [];
        same('fanboy r12: a PWM fan that never stops — at 0 it keeps ~300 rpm: stops false, floor_rpm, no start looked for',
            ['done', false, true, null, null, [128, 1]], [$step['status'], $m['stops'] ?? null, abs(($m['floor_rpm'] ?? 0) - 300) <= 30, (array_key_exists('start_from_stop', $m) ? $m['start_from_stop'] : 'x'), (array_key_exists('stops_safe', $m) ? $m['stops_safe'] : 'x'), fanboyTOut($t, 5)]);

        $step = fanboyR12Measure($t, $spec, 6);
        $m = fanboyBoardRaw()['fans'][fanboyTId(6)]['measured'] ?? [];
        same('fanboy r12: one that stops and turns again only at full — never safe to stand; it can\'t turn at its value before: full for safety, an alert, the step stopped',
            ['stopped', 'no_restart', true, false, null, [255], true, true],
            [$step['status'], $step['why'], $m['stops'] ?? null, $m['stops_safe'] ?? null, (array_key_exists('kick_start', $m) ? $m['kick_start'] : 'x'), [fanboyTOut($t, 6)[0]], isset(fanboySafetyFull()[fanboyTId(6)]),
                (bool) array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, 'alert'))]);

        // the owner's decision (2026-11-15): a CPU cooler he steers (cpu_own) gets the standstill part too
        $step = fanboyR12Measure($t, $spec, 2);
        $m = fanboyBoardRaw()['fans'][fanboyTId(2)]['measured'] ?? [];
        same('fanboy r12: the CPU cooler given to him (cpu_own) — measured, and the standstill part too (stops, starts at 35 %), back as before',
            ['done', 10, true, 35, true, [128, 1]], [$step['status'], $m['min_pct'] ?? null, $m['stops'] ?? null, $m['start_from_stop'] ?? null, $m['stops_safe'] ?? null, fanboyTOut($t, 2)]);
    } finally {
        fanboyTEnd();
    }
}

/** Round 12: too warm during the standstill part — full at once, then back; the table kept, the standstill unknown */
function testFanboyR12Warm(): void
{
    $t = fanboyT('r12warm');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $warmAt = null;
        $after = [];
        $cpu = "{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input";
        $step = fanboyR12Measure($t, $spec, 1, function (float $clock) use ($t, $cpu, &$warmAt, &$after): void {
            if ($warmAt === null && (fanboyStepRead()['progress']['phase'] ?? null) === 'stop_check' && fanboyTOut($t, 1)[0] === 0) {
                $warmAt = $clock;
                file_put_contents($cpu, "90000\n");
            } elseif ($warmAt !== null) {
                $after[] = [$clock - $warmAt, fanboyTOut($t, 1)[0]];
            }
        });
        file_put_contents($cpu, "28000\n");
        $m = fanboyBoardRaw()['fans'][fanboyTId(1)]['measured'] ?? [];
        $zeroAfter = array_filter($after, fn ($a) => $a[0] > 1.5 && $a[1] === 0);
        // 2026-11-20: the cut is the fan's result — the step goes on (here: done), the fan never stands
        same('fanboy r12 warm: CPU 90 °C while it stands (87 is the mark) — at once off 0 (within a look), the cut its result: the step done, the fan turning again exactly as before',
            ['done', 'warm', true, [], [128, 1], []], [$step['status'], $step['cuts'][0]['why'] ?? null, $warmAt !== null, array_values($zeroAfter), fanboyTOut($t, 1), fanboyHeld()]);
        same('fanboy r12 warm: … its table kept (down to 10 %), it never stands (stops_safe false, the cut kept)', [10, false, false, 'warm'],
            [$m['min_pct'] ?? null, array_key_exists('stops', $m), $m['stops_safe'] ?? null, $m['stand_cut']['why'] ?? null]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12: the control with «darf stehen» (off_ok) on each — only a fan that stops AND starts again safely stands; the
 * others keep their floor and the state says why; one from standing starts with its start from standstill (or the kick)
 */
function testFanboyR12Control(): void
{
    $t = fanboyT('r12ctl');
    try {
        $spec = fanboyR12Spec();
        $tbl = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1];
        $m = [1 => $tbl + ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true],
              4 => $tbl + ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => null, 'kick_start' => 15, 'stops_safe' => true],
              5 => $tbl + ['stops' => false, 'floor_rpm' => 300, 'start_from_stop' => null, 'kick_start' => null, 'stops_safe' => null],
              6 => $tbl + ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => null, 'kick_start' => null, 'stops_safe' => false],
              2 => $tbl];          // measured before round 12: unknown
        fanboyR12Store($m);
        $s = fanboyBoardRaw();
        foreach ([1, 2, 4, 5, 6] as $n) {
            $s['fans'][fanboyTId($n)]['off_ok'] = true;
            $s['fans'][fanboyTId($n)]['role'] = 'disks';
            unset($s['fans'][fanboyTId($n)]['cpu_own']);
        }
        $s['zones'] = [['id' => 'disks', 'name' => 'Disks', 'fans' => array_map('fanboyTId', [1, 2, 4, 5, 6]), 'unit' => 'pct',
            'target' => ['points' => [[30, 0], [40, 0], [45, 60], [50, 100]]], 'sources' => [['kind' => 'disk', 'id' => 'parity']]]];
        fanboyTStore($s);
        fanboyTDisks($t, 35, null, 35);
        fanboyTSettle($t, $spec);
        $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 4);
        $why = fn () => array_column(fanboyStatus($c)['zones'][0]['fans'] ?? [], 'off_why', 'id');
        same('fanboy r12 control: the curve says 0 — only the fans that stop and start again safely stand (35 %, kick); the PWM floor, the unsafe one and the old measurement keep their floor (15 %)',
            [0, 0, 38, 38, 38], [fanboyTOut($t, 1)[0], fanboyTOut($t, 4)[0], fanboyTOut($t, 5)[0], fanboyTOut($t, 6)[0], fanboyTOut($t, 2)[0]]);
        same('fanboy r12 control: … and the state says why for each', [fanboyTId(1) => null, fanboyTId(2) => 'measure_again', fanboyTId(4) => null, fanboyTId(5) => 'never_stops', fanboyTId(6) => 'not_safe'],
            (function () use ($why) { $w = $why(); ksort($w); return $w; })());
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy r12 control: … standing on purpose is no alarm', null, $c['alarm']);
        // warmer: each from standing with its start — 35 % for one, full (the kick) for the other — then its value; no alarm
        fanboyTDisks($t, 42, null, 35);
        $seen = [1 => [], 4 => []];
        for ($i = 0; $i < 12; $i++) {
            fanboyTRounds($c, $now, $t, $spec, 1);
            $seen[1][] = fanboyTOut($t, 1)[0];
            $seen[4][] = fanboyTOut($t, 4)[0];
        }
        same('fanboy r12 control: warmer — the 35 % fan started 5 % above its start (40 %: 102), the kick fan at full (255); both turning at their value then, no «stands»',
            [true, true, true, true, null], [in_array(102, $seen[1], true), in_array(255, $seen[4], true), fanboyReadInt("{$t['hw']}/fan1_input") > 300,
                fanboyReadInt("{$t['hw']}/fan4_input") > 300, $c['alarm']]);
        // (2026-11-20: the measuring's start wait is 12 s now — its own; whether the control's «stands» clock should allow for a
        // fan's measured start time is an open question to the owner, not built: the control's own numbers here)
        check('fanboy r12 control: the «stands» clock allows for the control\'s kicks — FANBOY_KICK × FANBOY_KICK_MAX fit in FANBOY_STAND_AFTER',
            FANBOY_KICK * FANBOY_KICK_MAX <= FANBOY_STAND_AFTER);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

const FANBOY_R12_CPU = 'coretemp@platform/coretemp.0#1';

/** The CPU's temperature on the fake board (temp1, its package); null: no reading at all (every CPU sensor gone) */
function fanboyR12CpuTemp(array $t, ?int $c): void
{
    $dir = "{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7";
    if ($c === null) {
        foreach (glob("$dir/temp*_input") ?: [] as $f) {
            rename($f, "$f.off");
        }
        return;
    }
    foreach (glob("$dir/temp*_input.off") ?: [] as $f) {
        rename($f, substr($f, 0, -4));
    }
    file_put_contents("$dir/temp1_input", ($c * 1000) . "\n");
}

/**
 * Round 12, the owner's decision (2026-11-15): «darf stehen» for a CPU cooler he steers needs its own tick
 * (cpu_off_confirm, kept with its time); never for a pump, never for a CPU cooler the BIOS steers; only once its
 * measuring found it stops and starts again safely
 */
function testFanboyR12CpuRole(): void
{
    $t = fanboyT('r12cpurole');
    try {
        $safe = ['table' => [[100, 1500], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
                 'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true];
        fanboyR12Store([2 => $safe, 6 => ['stops_safe' => false, 'start_from_stop' => null] + $safe]);
        $id = fanboyTId(2);
        $role = fn (array $r) => fanboyR6Refused(fn () => fanboyRole($r + ['fan' => $id, 'role' => 'cpu']));
        same('fanboy r12 cpu: «darf stehen» for his CPU cooler without the second tick — refused (fanboy_cpu_off_confirm), nothing stored',
            ['fanboy_cpu_off_confirm', false, false], [$role(['off_ok' => true]), fanboyBoardRaw()['fans'][$id]['off_ok'] ?? false, isset(fanboyBoardRaw()['fans'][$id]['cpu_off'])]);
        same('fanboy r12 cpu: … with it — stored, with its time', ['', true, true], [$role(['off_ok' => true, 'cpu_off_confirm' => true]),
            fanboyBoardRaw()['fans'][$id]['off_ok'] ?? null, is_int(fanboyBoardRaw()['fans'][$id]['cpu_off']['at'] ?? null)]);
        $at = fanboyBoardRaw()['fans'][$id]['cpu_off']['at'];
        same('fanboy r12 cpu: … another field\'s save (the count) keeps it without the tick; off again: the tick gone', ['', $at, '', false],
            [$role(['off_ok' => true, 'count' => 2]), fanboyBoardRaw()['fans'][$id]['cpu_off']['at'] ?? null, $role(['off_ok' => false]), isset(fanboyBoardRaw()['fans'][$id]['cpu_off'])]);
        same('fanboy r12 cpu: never for a pump', 'fanboy_off_pump', fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(6), 'role' => 'pump', 'off_ok' => true])));
        fanboyStoreUpdate(function (array $e) use ($id): array {
            unset($e['fans'][$id]['cpu_own']);          // the BIOS steers it
            return $e;
        });
        same('fanboy r12 cpu: never for a CPU cooler the BIOS steers', 'fanboy_cpu_off_bios', $role(['off_ok' => true, 'cpu_off_confirm' => true]));
        fanboyStoreUpdate(function (array $e) use ($id): array {
            $e['fans'][$id]['cpu_own'] = ['at' => 1700000000];
            $e['fans'][fanboyTId(6)]['role'] = 'cpu';
            $e['fans'][fanboyTId(6)]['cpu_own'] = ['at' => 1700000000];
            unset($e['fans'][fanboyTId(1)]['measured']);
            $e['fans'][fanboyTId(1)]['role'] = 'cpu';
            $e['fans'][fanboyTId(1)]['cpu_own'] = ['at' => 1700000000];
            return $e;
        });
        same('fanboy r12 cpu: not when its measuring found it unsafe to stand, nor when not measured', ['fanboy_off_not_safe', 'fanboy_off_needs_measure'],
            [fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(6), 'role' => 'cpu', 'off_ok' => true, 'cpu_off_confirm' => true])),
             fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu', 'off_ok' => true, 'cpu_off_confirm' => true]))]);
        // the control: off_ok stored without the tick (an older page, a hand-edited store) — not in effect, and why
        $plan = fanboyStandPlan(['off_ok' => true, 'role' => 'cpu', 'cpu_own' => ['at' => 1]], $safe, true, true, 40);
        same('fanboy r12 cpu: the control — «darf stehen» without the second tick is not in effect (why: cpu_off_confirm)', [false, 'cpu_off_confirm'], [$plan[0], $plan[1]]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12, 2026-11-20 (the owner: «die CPU verträgt auch 80 oder 90 Grad easy und würde eh selber drosseln»): a CPU
 * cooler's standstill part has the guard of every fan — a source at its limit less 5 °C (the CPU's 92 → 87) — and its own
 * only for no CPU reading at all. A cut is the cooler's RESULT: at full at once, its table kept, it never stands (where the
 * source began and ended, how long it stood), and the measuring goes on with the next output; the general guard before
 * the table still stops the step, its sentence true either side of the limit.
 */
function testFanboyR12CpuMeasure(): void
{
    $t = fanboyT('r12cpumeasure');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        $id2 = fanboyTId(2);
        // the CPU going to $c when output 2's standstill part begins; back to 28 once output 2 runs at full again
        $hot = function (?int $c, ?float &$at, array &$after) use ($t, $id2): callable {
            return function (float $clock) use ($t, $c, $id2, &$at, &$after): void {
                $st = fanboyStepRead() ?? [];
                if ($at === null && ($st['current'] ?? null) === $id2 && ($st['progress']['phase'] ?? null) === 'stop_check' && fanboyTOut($t, 2)[0] === 0) {
                    $at = $clock;
                    fanboyR12CpuTemp($t, $c);
                } elseif ($at !== null) {
                    if (($st['current'] ?? null) === $id2) {
                        $after[] = [$clock - $at, fanboyTOut($t, 2)[0]];
                    }
                    if (fanboyTOut($t, 2)[0] !== 0) {
                        fanboyR12CpuTemp($t, 28);        // the cooler turns again: the CPU cools
                    }
                }
            };
        };
        // 63 °C while it stands (the owner's server, where the old cut came at 55): no cut — the standstill part runs through
        fanboyR12CpuTemp($t, 28);
        fanboyR12Store();
        $at = null;
        $after = [];
        $step = fanboyR12Measure($t, $spec, 2, $hot(63, $at, $after));
        fanboyR12CpuTemp($t, 28);
        $m = fanboyBoardRaw()['fans'][$id2]['measured'] ?? [];
        same('fanboy r12 cpu measure: the CPU at 63 °C while its cooler stands — no cut (87 is the mark), the standstill part runs through, the step done',
            ['done', true, true, false, []], [$step['status'], $at !== null, array_key_exists('stops', $m), isset($m['stand_cut']), $step['cuts'] ?? []]);
        // 88 °C (92 − 5): cut — its result, the measuring goes on with output 4
        foreach (['88 °C' => [88, 'warm'], 'no reading' => [null, 'no_reading']] as $what => [$c, $why]) {
            fanboyR12CpuTemp($t, 28);
            fanboyR12Store();
            $at = null;
            $after = [];
            $step = fanboyR12Measure($t, $spec, [2, 4], $hot($c, $at, $after));
            fanboyR12CpuTemp($t, 28);
            $f = fanboyBoardRaw()['fans'];
            $m = $f[$id2]['measured'] ?? [];
            $cut = $step['cuts'][0] ?? [];
            same("fanboy r12 cpu measure: $what while its cooler stands — at full within 1.5 s, cut as its result: the table kept, it never stands; the step went on with output 4 and is done; every output back",
                [true, [], 10, false, $why, 'done', [$id2, fanboyTId(4)], true, [128, 1], [128, 1], []],
                [$at !== null, array_values(array_filter($after, fn ($a) => $a[0] > 1.5 && $a[1] === 0)), $m['min_pct'] ?? null, $m['stops_safe'] ?? null, $m['stand_cut']['why'] ?? null,
                    $step['status'], $step['done'], is_array($f[fanboyTId(4)]['measured'] ?? null), fanboyTOut($t, 2), fanboyTOut($t, 4), fanboyHeld()]);
            if ($c !== null) {
                same('fanboy r12 cpu measure: … the cut says where the CPU began and ended while it stood, its own limit, how long it stood',
                    [$id2, 'cpu', 28.0, 88.0, 92.0, true], [$cut['fan'] ?? null, $cut['kind'] ?? null, $cut['from'] ?? null, $cut['c'] ?? null, $cut['limit'] ?? null, is_int($cut['stood'] ?? null)]);
            }
        }
        // the general guard before the table: still stops the step, naming the source, its temperature and its limit
        fanboyR12Store();
        fanboyR12CpuTemp($t, 90);
        $step = fanboyR12Measure($t, $spec, [2, 4]);
        fanboyR12CpuTemp($t, 28);
        same('fanboy r12 cpu measure: the CPU at 90 °C from the start — the step stops «warm» (90 °C, its limit 92), nothing measured, every output back',
            ['stopped', 'warm', 90.0, 92.0, [], [128, 1], [128, 1]],
            [$step['status'], $step['why'], $step['warm']['c'] ?? null, $step['warm']['limit'] ?? null, $step['done'], fanboyTOut($t, 2), fanboyTOut($t, 4)]);
        check('fanboy r12 cpu measure: the round-12 cut at 70 °C or 15 °C over the lowest is gone', !defined('FANBOY_STOP_CPU_MAX') && !defined('FANBOY_STOP_CPU_RISE'));
        $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
        $l = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/de.json'), true);
        check('fanboy r12 cpu measure: the page says it true — the cut in the cooler\'s panel and the step\'s end line (from … to … °C while it stood; or its limit), the general stop «hatte … °C – Fanboys Schwelle fürs Vermessen ist … °C» (never «nahe an», never «Grenze» since 2026-11-20)',
            str_contains($js, "if (!st) return f && f.stand_cut ? cutLine(f.stand_cut) : '';") && str_contains($js, "if (cut) return T('step.ended.cut', { fan: fanSay(cut.fan), what: cutLine(cut) });")
            && $l['stand.cut_rise'] === 'Stillstand nicht bestanden: {where} stieg von {from} auf {c} °C, während er stand — er bleibt immer am Drehen.'
            && str_contains($l['step.ended.warm_what'], 'hatte {c} °C – Fanboys Schwelle fürs Vermessen ist {below} °C') && !str_contains($l['step.ended.warm_what'], 'nahe')
            && !str_contains($l['step.ended.warm_what'] . $l['stand.cut_at'], 'Grenze'));
    } finally {
        fanboyTEnd();
    }
}

/** A CPU cooler (output 2) given to him, may stand (both ticks, measured safe: starts at 35 %), in a zone with the CPU and a disk */
function fanboyR12CpuControl(array $t, array $spec): array
{
    $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
          'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true];
    fanboyR12Store([2 => $m]);
    $s = fanboyBoardRaw();
    $s['fans'][fanboyTId(2)] += ['off_ok' => true, 'cpu_off' => ['at' => 1700000001]];
    // round 12 correction: the curve decides where it may stand — 0 % up to 45 °C, then to 100 % at 80 °C (starts_at 45)
    $s['zones'] = [['id' => 'cpu', 'name' => 'CPU', 'fans' => [fanboyTId(2)], 'unit' => 'pct', 'target' => ['points' => [[30, 0], [45, 0], [80, 100]]],
        'sources' => [['kind' => 'cpu', 'id' => FANBOY_R12_CPU], ['kind' => 'disk', 'id' => 'parity']]]];
    fanboyTStore($s);
    fanboyTSettle($t, $spec);
    return fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
}

/**
 * Round 12: the control with a CPU cooler that may stand — it stops only after the CPU was 5 °C under its «ab» (45 °C)
 * for 60 s without a break; it starts at once (the next round) at «ab», when another source asks for air, or with no
 * CPU reading; when it turns, at least its floor (measured lowest + 5, as every fan of his)
 */
function testFanboyR12CpuControl(): void
{
    $t = fanboyT('r12cpuctl');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 30);
        $c = fanboyR12CpuControl($t, $spec);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 28);
        same('fanboy r12 cpu control: cool (30 °C, 15 under «ab») — turning at its floor (15 %: measured 10 + 5) for the first 56 s', [38, null], [fanboyTOut($t, 2)[0], $c['alarm']]);
        fanboyTRounds($c, $now, $t, $spec, 4);
        $fan = fn () => fanboyStatus($c)['zones'][0]['fans'][0] ?? [];
        same('fanboy r12 cpu control: … after 60 s cool without a break — it stands (0); the state: may stand, since when, starts again at 45 °C',
            [0, true, true, 45.0], [fanboyTOut($t, 2)[0], $fan()['may_stand'] ?? null, is_int($fan()['stand_since'] ?? null), $fan()['starts_at'] ?? null]);
        fanboyR12CpuTemp($t, 43);
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy r12 cpu control: 43 °C (under «ab») — it stays standing, no alarm', [0, null], [fanboyTOut($t, 2)[0], $c['alarm']]);
        fanboyR12CpuTemp($t, 45);
        fanboyTRounds($c, $now, $t, $spec, 3);
        same('fanboy r12 cpu control: 45 °C («ab») held — it starts as soon as the smoothed CPU (median of 10 s) is there, kicked at its start (40 %: 102)', true, fanboyTOut($t, 2)[0] >= 102);
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy r12 cpu control: … and turns (from standing at 40 %: it starts at 35 %)', true, fanboyReadInt("{$t['hw']}/fan2_input") > 300);
        // a break in the cool time starts the 60 s anew
        fanboyR12CpuTemp($t, 30);
        fanboyTRounds($c, $now, $t, $spec, 20);
        fanboyR12CpuTemp($t, 42);
        fanboyTRounds($c, $now, $t, $spec, 4);
        fanboyR12CpuTemp($t, 30);
        fanboyTRounds($c, $now, $t, $spec, 25);
        same('fanboy r12 cpu control: 40 s cool, 8 s at 42 °C (the smoothed CPU over «ab» − 5), 50 s cool — still turning (60 s without a break needed)', true, fanboyTOut($t, 2)[0] > 0);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('fanboy r12 cpu control: … 66 s without a break — it stands', 0, fanboyTOut($t, 2)[0]);
        fanboyTDisks($t, 40, null, 30);
        fanboyTRounds($c, $now, $t, $spec, 5);
        same('fanboy r12 cpu control: another source of its zone asks for air (the disk at 40 °C, over where its curve starts: 38.3) — it starts as soon as that value is read (disks every 10 s)',
            true, fanboyTOut($t, 2)[0] >= 102);
        fanboyTDisks($t, 30, null, 30);
        fanboyTRounds($c, $now, $t, $spec, 38);
        same('fanboy r12 cpu control: (standing again after 60 s cool)', 0, fanboyTOut($t, 2)[0]);
        fanboyR12CpuTemp($t, null);
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r12 cpu control: the CPU reading missing — it starts at once', true, fanboyTOut($t, 2)[0] >= 102);
        fanboyR12CpuTemp($t, 30);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12: Fanboy gone while a CPU cooler stands at 0 — each way it is given back to its state before (here as on the
 * owner's board: a fixed 50 %, 128) and how soon: the control stopped (itself, at once), killed (the agent's tick, ≤ 5 s),
 * hung (no beat for 10 s with an output at 0, not 30 — then the tick), the agent gone too (the guard's look; while an
 * output stands it looks every 20 s within its minute), stop/update (the guard's release); one that doesn't start at its
 * value before: full after the give-back check (10 s)
 */
function testFanboyR12CpuGone(): void
{
    $t = fanboyT('r12cpugone');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 30);
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        // 1. the control itself stopped (TERM, hand back, a hold): back at once
        $c = fanboyR12CpuControl($t, $spec);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 33);
        $standing = fanboyTOut($t, 2)[0];
        fanboyLetGo($c, 'end');
        same('fanboy r12 gone: the control ends (stopped) with the CPU cooler standing — back at once to the mainboard\'s automatic (round 4: a CPU cooler goes back to it)', [0, [128, 2], []],
            [$standing, fanboyTOut($t, 2), fanboyHeld()]);
        // a holder with output 2 standing at 0 — as the control leaves it
        $p = proc_open([PHP_BINARY, '-r', 'echo getmypid();'], [1 => ['pipe', 'w']], $pipes);
        $deadPid = (int) stream_get_contents($pipes[1]);
        proc_close($p);
        $stand = function (array $owner) use ($t): void {
            $me = fanboyMe('control');
            $o = fanboyOutputs(fanboyDetect())[fanboyTId(2)];
            fanboyTake($o, $me, FANBOY_T_KEY);
            fanboySet(fanboyTId(2), 0, $me);
            fanboyLocked(function (array $j) use ($owner): array {
                $j['outputs'][fanboyTId(2)]['owner'] = $owner;
                return [$j, null];
            });
            fanboyTSettle($t, fanboyR12Spec(), 4);
        };
        $dead = ['kind' => 'control', 'pid' => $deadPid, 'start' => '12345', 'since' => 0];
        // 2. killed hard, the agent there: its tick (every 5 s) gives it back at its first look
        $stand($dead);
        same('fanboy r12 gone: … killed hard — the agent\'s tick: back at its first look (≤ 5 s), the fan turning again', [[fanboyTId(2)], [128, 1]],
            [array_keys(fanboyWatch()), fanboyTOut($t, 2)]);
        // 3. hung: there, no beat — 12 s old: with an output at 0 that is hung (10 s), with none it would be alive (30 s)
        $me = fanboyMe('control');
        $stand($me);
        // judged on the test's own clock: «now» is the beat + 12 s exactly (never the machine's — a loaded server took longer
        // between writing and reading, and the 30-s side read «hung», 2026-11-16)
        $b = hrtime(true) - 12_000_000_000;
        file_put_contents(fanboyRunDir() . '/beat-' . getmypid(), (string) $b);
        $at = $b / 1e9 + 12.0;
        $j = fanboyJournal()['outputs'][fanboyTId(2)];
        same('fanboy r12 gone: … hung — a holder 12 s without a beat counts as hung while its output stands at 0 (10 s), as alive while it turns (30 s)',
            ['hung', 'alive'], [fanboyOwnerState($me, $at, fanboyStanding($j) ? FANBOY_OWNER_STALE_STANDING : FANBOY_OWNER_STALE), fanboyOwnerState($me, $at)]);
        fanboyBeat();
        fanboyGiveBack([fanboyTId(2)], 'test', null);
        // 4. the agent gone too: the guard's look (the cron line) gives it back; with nothing standing any more it ends at once
        $stand($dead);
        $t0 = microtime(true);
        $out = fanboyTGuard($t, $data, 'watch');
        same('fanboy r12 gone: … the agent gone too — the guard\'s look gives it back and ends (nothing stands any more)', [[128, 1], [], true],
            [fanboyTOut($t, 2), fanboyHeld(), microtime(true) - $t0 < 15], $out);
        $g = (string) file_get_contents(OFFICE_DIR . '/agent/fanboy-guard.php');
        check('fanboy r12 gone: … while an output stands the guard looks again every 20 s within its minute (0, 20, 40 s) — hung and standing: back within 30 s',
            str_contains($g, 'const FANBOY_GUARD_LOOKS = 3;') && str_contains($g, 'const FANBOY_GUARD_AGAIN = 20;') && str_contains($g, '|| !fanboyStandingHeld()')
            && FANBOY_OWNER_STALE_STANDING + 20 <= 30);
        // 5. stop, restart, update (agent.sh): the guard's release — every output back
        $stand($dead);
        $out = fanboyTGuard($t, $data, 'release');
        same('fanboy r12 gone: … stop/restart/update — the guard\'s release gives it back', [[128, 1], []], [fanboyTOut($t, 2), fanboyHeld()]);
        // 6. a CPU cooler that doesn't start at its value before (output 4's fan: only from 70 %): full after the give-back check
        $spec4 = fanboyR12Spec();
        file_put_contents("{$t['hw']}/pwm4", "255\n");           // turning before (it started at full), then at 128 as the BIOS has it
        fanboyTSettle($t, $spec4, 4);
        file_put_contents("{$t['hw']}/pwm4", "128\n");
        fanboyTSettle($t, $spec4, 4);
        $o4 = fanboyOutputs(fanboyDetect())[fanboyTId(4)];
        $me = fanboyMe('control');
        fanboyTake($o4 + ['paired' => [4]], $me, FANBOY_T_KEY);         // as the control takes it: its tach named (the check's «before»)
        fanboySet(fanboyTId(4), 0, $me);
        fanboyTSettle($t, $spec4, 6);
        fanboyLocked(function (array $j) use ($dead): array {
            $j['outputs'][fanboyTId(4)]['owner'] = $dead;
            return [$j, null];
        });
        fanboyWatch();
        fanboyTSettle($t, $spec4, 6);
        $standsAt128 = [fanboyTOut($t, 4), fanboyReadInt("{$t['hw']}/fan4_input")];
        $full = fanboyGivenCheck(time() + FANBOY_BACK_CHECK + 1);
        same('fanboy r12 gone: … one that doesn\'t start again at its value before (128) — the give-back check (10 s) sets it to full',
            [[[128, 1], 0], true, 255], [$standsAt128, isset($full[fanboyTId(4)]), fanboyTOut($t, 4)[0]]);
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * Round 12 (the owner): a fan's look — the model only proposes; frame colour, wheel colour, blade style and the blades'
 * number are the user's. Each field saves alone (not sent: unchanged), '' or null clears one; shapes checked; old data read
 */
function testFanboyR12Look(): void
{
    $t = fanboyT('r12look');
    try {
        fanboyR12Store();
        $id = fanboyTId(4);
        $look = fn (array $r) => fanboyFanLook($r + ['fan' => $id])['fan'];
        $look(['model' => 'noctua-nf-a12x25-pwm']);
        $look(['blades' => 9]);
        $look(['frame' => 'black']);
        $look(['wheel' => 'accent-blue']);
        $f = $look(['style' => 'sickle']);
        same('fanboy r12 look: each field alone — the model, the blades (with a model: stored, its own), frame, wheel, style; none sent changes another',
            [['id' => 'noctua-nf-a12x25-pwm', 'mm' => null, 'blades' => 9], 'black', 'accent-blue', 'sickle'], [$f['model'] ?? null, $f['frame'] ?? null, $f['wheel'] ?? null, $f['style'] ?? null]);
        $f = $look(['frame' => '', 'wheel' => null]);
        same('fanboy r12 look: \'\' or null clears one, the others stay', [false, false, 'sickle', 9], [isset($f['frame']), isset($f['wheel']), $f['style'] ?? null, $f['model']['blades'] ?? null]);
        $bad = [];
        foreach ([['frame' => 'Black'], ['wheel' => str_repeat('a', 25)], ['style' => 'a b'], ['style' => 5], ['blades' => 4], ['blades' => 12]] as $r) {
            $bad[] = fanboyR6Refused(fn () => $look($r));
        }
        same('fanboy r12 look: bad shapes refused (fanboy_bad_look) — capitals, too long, a space, not text, 4 or 12 blades', array_fill(0, 6, 'fanboy_bad_look'), $bad);
        // old data: a model with its blades and one colour (before round 12) — still read; the state carries the new keys
        fanboyStoreUpdate(function (array $e) use ($id): array {
            $e['fans'][$id]['model'] = ['id' => 'arctic-p14-pwm-pst', 'mm' => 140, 'blades' => 13];
            $e['fans'][$id]['colour'] = 'graphite';
            unset($e['fans'][$id]['frame'], $e['fans'][$id]['wheel'], $e['fans'][$id]['style']);
            $e['fans'][$id . 'x'] = 'not a fan';
            return $e;
        });
        $fans = array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id');
        same('fanboy r12 look: old data read as it is (model, 13 blades, one colour), the new keys null; the state\'s fan has them',
            [['id' => 'arctic-p14-pwm-pst', 'mm' => 140, 'blades' => 13], 'graphite', null, null, null], [$fans[$id]['model'] ?? null, $fans[$id]['colour'] ?? null,
                ...array_map(fn ($k) => array_key_exists($k, $fans[$id] ?? []) ? $fans[$id][$k] : 'x', ['frame', 'wheel', 'style'])]);
        $f = $look(['wheel' => 'white']);
        same('fanboy r12 look: … and a new field saved beside it keeps the old', [13, 'graphite', 'white'], [$f['model']['blades'] ?? null, $f['colour'] ?? null, $f['wheel'] ?? null]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12 fix (the owner's server: the CPU cooler's standstill part was cut after 2 s by a single reading of 53 °C):
 * the CPU idles at 38 °C with one reading in twenty at +20 °C — the standstill part completes; a real rise (sustained)
 * cuts it, and the cut is said (result and measurement: «Stillstand nicht geprüft: CPU …»)
 */
function testFanboyR12FixSpikes(): void
{
    $t = fanboyT('r12fixspikes');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $n = 0;
        $step = fanboyR12Measure($t, $spec, 2, function () use ($t, &$n): void {
            fanboyR12CpuTemp($t, ++$n % 20 === 0 ? 58 : 38);         // one reading in twenty at +20 °C
        });
        $m = fanboyBoardRaw()['fans'][fanboyTId(2)]['measured'] ?? [];
        same('fanboy r12 fix: a spiky CPU (38 °C, every 20th reading 58 °C) — the CPU cooler\'s standstill part completes: it stops, starts at 35 %, safe; nothing cut',
            ['done', true, 35, true, false], [$step['status'], $m['stops'] ?? null, $m['start_from_stop'] ?? null, $m['stops_safe'] ?? null, isset($m['stand_cut'])]);
        // a rise during it (38 → 55 °C held): 2026-11-20, no cut any more — the CPU's mark is its limit less 5 (87 °C)
        fanboyR12CpuTemp($t, 38);
        fanboyR12Store();
        $step = fanboyR12Measure($t, $spec, 2, function () use ($t): void {
            if ((fanboyStepRead()['progress']['phase'] ?? null) === 'stop_check') {
                fanboyR12CpuTemp($t, 55);
            }
        });
        fanboyR12CpuTemp($t, 28);
        $m = fanboyBoardRaw()['fans'][fanboyTId(2)]['measured'] ?? [];
        same('fanboy r12 fix: … a rise to 55 °C held — no cut (the round-12 rise rule is gone): the standstill part completes, the step done',
            ['done', true, false, []], [$step['status'], $m['stops'] ?? null, isset($m['stand_cut']), $step['cuts'] ?? []]);
        $s = array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id')[fanboyTId(2)];
        same('fanboy r12 fix: … the setup\'s fan carries its stand, no cut', [null, true], [$s['stand_cut'], is_array($s['stand'])]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12 fix: the control with the spiky CPU — the CPU cooler stands after the calm minute and stays standing through
 * spikes under the hard value (80 °C); a real rise to «ab» starts it as soon as the smoothed CPU is there, a single
 * reading at the hard value the same round; the state says what it goes by
 */
function testFanboyR12FixControl(): void
{
    $t = fanboyT('r12fixctl');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 30);
        $c = fanboyR12CpuControl($t, $spec);
        $now = 1000.0;
        $round = 0;
        $spiky = function (int $rounds, int $base) use (&$c, &$now, $t, $spec, &$round): array {
            $out = [];
            for ($i = 0; $i < $rounds; $i++) {
                fanboyR12CpuTemp($t, ++$round % 20 === 0 ? $base + 20 : $base);
                fanboyTRounds($c, $now, $t, $spec, 1);
                $out[] = fanboyTOut($t, 2)[0];
            }
            return $out;
        };
        $seen = $spiky(40, 30);
        same('fanboy r12 fix control: CPU 30 °C with a spike of 50 °C every 20th round — it stands after the calm minute (spikes don\'t restart the clock)',
            [true, 0], [in_array(38, array_slice($seen, 0, 25), true), end($seen)]);
        $seen = $spiky(60, 30);
        same('fanboy r12 fix control: … and stays standing through two minutes of such spikes (under the hard value, 80 °C)', [0], array_values(array_unique($seen)));
        $fan = fanboyStatus($c)['zones'][0]['fans'][0] ?? [];
        same('fanboy r12 fix control: the state says what it goes by — smoothed, peak, where its curve starts, the hard value', [30.0, 45.0, 80.0, true],
            [$fan['cpu_by']['smooth'] ?? null, $fan['cpu_by']['starts_at'] ?? null, $fan['cpu_by']['hard'] ?? null, is_float($fan['cpu_by']['peak'] ?? null) || is_int($fan['cpu_by']['peak'] ?? null)]);
        // a real rise: 46 °C held — it starts in the round the smoothed CPU reaches «ab»
        $at = null;
        for ($i = 0; $i < 6 && $at === null; $i++) {
            fanboyR12CpuTemp($t, 46);
            fanboyTRounds($c, $now, $t, $spec, 1);
            $by = $c['fans'][fanboyTId(2)]['cpu_by'];
            if (fanboyTOut($t, 2)[0] > 0) {
                $at = [$i + 1, $by['smooth'] >= 45];
            }
        }
        same('fanboy r12 fix control: a real rise (46 °C held) — it starts in the round its smoothed CPU reaches «ab» (within 3 rounds)', [true, true],
            [$at !== null && $at[0] <= 3, $at[1] ?? null]);
        $spiky(40, 30);
        same('fanboy r12 fix control: (standing again after the calm minute)', 0, fanboyTOut($t, 2)[0]);
        fanboyR12CpuTemp($t, 85);
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r12 fix control: a single reading at the hard value (85 °C ≥ 80) — it starts the same round', true, fanboyTOut($t, 2)[0] >= 102);
        fanboyR12CpuTemp($t, 30);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * Round 12 fix (the owner's case fan on output 5: start_from_stop 10 from one try, yet once it didn't start at 50 %): the
 * start from standstill confirmed — two more stops and starts at the value found; one fails: 5 % up and confirm again. A
 * fake fan that starts at its value (34 %) two times out of three: 35 % found, the third try fails → 40 %; tries kept;
 * the control starts it 5 % above (45 %)
 */
function testFanboyR12FixConfirm(): void
{
    $t = fanboyT('r12fixconfirm');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        $spec['fans'][1]['flaky'] = true;
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $phases = [];
        $step = fanboyR12Measure($t, $spec, 1, function () use (&$phases): void {
            $p = fanboyStepRead()['progress'] ?? null;
            $k = is_array($p) && in_array($p['phase'] ?? null, ['start_find', 'start_confirm'], true) ? $p['phase'] . ':' . $p['pct'] . (isset($p['try']) ? "/{$p['try']}" : '') : null;
            if ($k !== null && end($phases) !== $k) {
                $phases[] = $k;
            }
        });
        $m = fanboyBoardRaw()['fans'][fanboyTId(1)]['measured'] ?? [];
        same('fanboy r12 fix confirm: found at 35 %, confirmed once, the second confirm fails → 40 %, confirmed twice: start_from_stop 40, 5 tries passed, 1 failed',
            ['done', 40, ['passed' => 5, 'failed' => 1], true], [$step['status'], $m['start_from_stop'] ?? null, $m['start_tries'] ?? null, $m['stops_safe'] ?? null]);
        same('fanboy r12 fix confirm: … the phases the page shows', ['start_find:30', 'start_find:35', 'start_confirm:35/1', 'start_confirm:35/2', 'start_find:40', 'start_confirm:40/1', 'start_confirm:40/2'],
            array_slice($phases, array_search('start_find:30', $phases, true) ?: 0));
        same('fanboy r12 fix confirm: … back as before, turning', [[128, 1], true], [fanboyTOut($t, 1), fanboyReadInt("{$t['hw']}/fan1_input") > 300]);
        same('fanboy r12 fix confirm: the control starts a standing fan 5 % above it (45 %)', 45, fanboyStandPlan(['off_ok' => false], $m, true, false, 15)[2]);
    } finally {
        fanboyTEnd();
    }
}

/** Round 12 correction: the owner's CPU curve — 0 % up to 62 °C, then up to 100 % at 81 °C — on his CPU cooler (output 2, may stand) */
function fanboyR12CurveControl(array $t, array $spec, array $points = [[30, 0], [62, 0], [81, 100]]): array
{
    $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
          'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true, 'start_tries' => ['passed' => 3, 'failed' => 0]];
    fanboyR12Store([2 => $m]);
    $s = fanboyBoardRaw();
    $s['fans'][fanboyTId(2)] += ['off_ok' => true, 'cpu_off' => ['at' => 1700000001]];
    $s['zones'] = [['id' => 'cpu', 'name' => 'CPU', 'fans' => [fanboyTId(2)], 'unit' => 'pct', 'target' => ['points' => $points],
        'sources' => [['kind' => 'cpu', 'id' => FANBOY_R12_CPU]]]];
    fanboyTStore($s);
    fanboyTSettle($t, $spec);
    return fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
}

/**
 * Round 12 correction (the owner: «bis 60 Grad darf der Lüfter stehen bleiben» — the confirmation said 45/40): the curve is
 * the truth for standing — his curve gives starts_at 62, it stands under 57 for a minute, a spike to 60 doesn't start it,
 * 62 smoothed does, a reading at 81 (where the curve reaches 100 %) at once; a curve that never asks for nothing never
 * lets a fan stand (curve_never_zero)
 */
function testFanboyR12Curve(): void
{
    $t = fanboyT('r12curve');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 50);
        $c = fanboyR12CurveControl($t, $spec);
        $now = 1000.0;
        $fan = fn () => fanboyStatus($c)['zones'][0]['fans'][0] ?? [];
        fanboyTRounds($c, $now, $t, $spec, 2);
        same('fanboy r12 curve: his curve — starts at 62 °C (where it leaves 0 %), may stop under 57, the hard value 81 (its 100 %), smoothed over 10 s',
            [62.0, 57.0, 81.0, FANBOY_CPU_SMOOTH], [$fan()['starts_at'] ?? null, $fan()['stops_below'] ?? null, $fan()['hard'] ?? null, $fan()['window'] ?? null]);
        fanboyTRounds($c, $now, $t, $spec, 30);
        same('fanboy r12 curve: 50 °C for a minute (under 57) — it stands', 0, fanboyTOut($t, 2)[0]);
        fanboyR12CpuTemp($t, 60);
        fanboyTRounds($c, $now, $t, $spec, 1);
        fanboyR12CpuTemp($t, 50);
        fanboyTRounds($c, $now, $t, $spec, 3);
        same('fanboy r12 curve: one reading at 60 °C — still standing', 0, fanboyTOut($t, 2)[0]);
        fanboyR12CpuTemp($t, 59);
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy r12 curve: 59 °C held (under 62) — still standing (no second threshold: «ab» 45 doesn\'t count)', 0, fanboyTOut($t, 2)[0]);
        $at = null;
        for ($i = 0; $i < 6 && $at === null; $i++) {
            fanboyR12CpuTemp($t, 62);
            fanboyTRounds($c, $now, $t, $spec, 1);
            if (fanboyTOut($t, 2)[0] > 0) {
                $at = $c['fans'][fanboyTId(2)]['cpu_by']['smooth'];
            }
        }
        same('fanboy r12 curve: 62 °C held — it starts in the round the smoothed CPU reaches 62', 62.0, $at);
        fanboyR12CpuTemp($t, 50);
        fanboyTRounds($c, $now, $t, $spec, 36);
        same('fanboy r12 curve: (standing again after a calm minute)', 0, fanboyTOut($t, 2)[0]);
        fanboyR12CpuTemp($t, 81);
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r12 curve: one reading at 81 °C (the hard value) — it starts the same round', true, fanboyTOut($t, 2)[0] > 0);
        fanboyLetGo($c, 'end');
        // a curve that never asks for nothing (normal preset: 20 % at its start) — never stands
        $c = fanboyR12CurveControl($t, $spec, [[45, 20], [80, 100]]);
        same('fanboy r12 curve: a curve that never reaches 0 % — «darf stehen» not in effect (curve_never_zero), in the control and the setup',
            [false, 'curve_never_zero', 'curve_never_zero'], [$c['fans'][fanboyTId(2)]['off_ok'], $c['fans'][fanboyTId(2)]['off_why'],
                array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], 'off_why', 'id')[fanboyTId(2)] ?? null]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * Round 12 correction: the curve in the status is the one the control applies — a rising sweep of the CPU over his
 * curve: what is written equals the effective curve (0 where it stands, at least its measured floor where it turns)
 */
function testFanboyR12CurveEffective(): void
{
    $t = fanboyT('r12curveeff');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 40);
        $c = fanboyR12CurveControl($t, $spec);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 32);         // standing after the calm minute
        $eff = fanboyStatus($c)['zones'][0]['fans'][0]['curve'] ?? [];
        check('fanboy r12 curve effective: the status — 0 % up to 62 °C, then at least its floor 15 % (until the curve passes it, 64.85 °C), 100 % at 81',
            ['cpu', [[30, 0], [62, 0], [62, 15], [64.85, 15], [81, 100]]] == [$eff['kind'] ?? null, $eff['points'] ?? null], json_encode($eff));
        $off = [];
        foreach ([45, 52, 58, 61, 63, 66, 69, 71, 74, 77, 80] as $deg) {
            fanboyR12CpuTemp($t, $deg);
            fanboyTRounds($c, $now, $t, $spec, 7);         // the smoothing and a start's kick (at its start value) passed
            $want = (int) round(fanboyCurveAt($eff['points'], (float) $deg) * 2.55);
            if (abs(fanboyTOut($t, 2)[0] - $want) > 2) {
                $off[] = "$deg °C: wrote " . fanboyTOut($t, 2)[0] . ", the status says $want";
            }
        }
        same('fanboy r12 curve effective: a rising sweep 45…80 °C — what the control writes is the status\'s curve (± rounding)', [], $off);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * Round 12 correction: «the mainboard's automatic takes over» only once SEEN — a give-back to the chip's automatic that
 * read back as automatic with the fan turning at the 10-s check sets the board's auto_back_seen; a chip that ignores its
 * mode (the fan stops) or a give-back to a fixed value never does
 */
function testFanboyR12AutoSeen(): void
{
    $t = fanboyT('r12autoseen');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        unset($settings['fans'][fanboyTId(4)]['back']);
        fanboyTStore($settings);
        $case = function (array $spec, int $enable, string $back = 'auto') use ($t, $settings): ?array {
            $settings['fans'][fanboyTId(4)]['back'] = $back;
            @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
            file_put_contents("{$t['hw']}/pwm4", "30\n");
            file_put_contents("{$t['hw']}/pwm4_enable", "$enable\n");
            fanboyTSettle($t, $spec, 20);
            fanboyBeat();
            $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
            $now = 1000.0;
            fanboyTRounds($c, $now, $t, $spec, 10);
            fanboyLetGo($c, 'end');
            fanboyTSettle($t, $spec, 12);
            $at = fanboyJournal()['outputs'][fanboyTId(4)]['given']['at'];
            fanboyBackCheck($at + 12);
            return fanboySetupState(fanboyDetect(), fanboyBoardRaw())['auto_back_seen'];
        };
        same('fanboy r12 auto seen: never seen yet — null', null, fanboySetupState(fanboyDetect(), fanboyBoardRaw())['auto_back_seen']);
        same('fanboy r12 auto seen: a chip that ignores its own mode (the fan stops) — not seen', null, $case(fanboyTSpec(10, [4 => ['auto' => 1100, 'auto_dead' => true]]), 2));
        same('fanboy r12 auto seen: a give-back to the fixed value found (the owner\'s «back: fixed») — not seen', null, $case(fanboyTSpec(10, [4 => ['auto' => 1100]]), 1, 'fixed'));
        $seen = $case(fanboyTSpec(10, [4 => ['auto' => 1100]]), 2);
        same('fanboy r12 auto seen: given back to the automatic, read back so, the fan turning at the check — seen, kept for the board',
            [true, fanboyTId(4), $seen], [is_int($seen['at'] ?? null), $seen['output'] ?? null, fanboyBoardRaw()['auto_back_seen'] ?? null]);
        // the fallback the status says for an output: what it goes back to (the journal's), the seconds each way
        $fb = fanboyFallback(fanboyJournal()['outputs'][fanboyTId(4)], ['value' => 0]);
        same('fanboy r12 fallback: back to the chip\'s automatic (enable 2) as kept; standing: hung within 15 s, the guard alone within 30 s, a dead control within 5 s',
            [['value' => 30, 'enable' => 2, 'auto' => true], 15, 30, 5, null], [$fb['to'], $fb['within']['control_hung'], $fb['within']['guard_only'], $fb['within']['control_dead'], $fb['within']['agent_restart']]);
    } finally {
        fanboyTEnd();
    }
}

/** The owner's board as Unraid sees it: six array disks (parity, disk1–5), a pool of six SATA SSDs (ssd … ssd6); temperatures given */
function fanboyNetDisks(array $t, int $hdd, int $ssd, bool $ssdAsleep = false): void
{
    $d = fn (string $name, string $type, string $dev, ?int $c, string $rot) => "[$name]\nname=\"$name\"\ntype=\"$type\"\ndevice=\"$dev\"\nid=\"EXAMPLE_DISK_" . strtoupper($name) . "\"\ntemp=\"" . ($c ?? '*') . "\"\nspundown=\"" . ($c === null ? 1 : 0) . "\"\nrotational=\"$rot\"\nstatus=\"DISK_OK\"\n";
    $ini = $d('parity', 'Parity', 'sdb', $hdd, '1');
    foreach ([1, 2, 3, 4, 5] as $i) {
        $ini .= $d("disk$i", 'Data', 'sd' . chr(99 + $i), $hdd, '1');
    }
    foreach (['ssd', 'ssd2', 'ssd3', 'ssd4', 'ssd5', 'ssd6'] as $i => $n) {
        $ini .= $d($n, 'Cache', 'sd' . chr(105 + $i), $ssdAsleep ? null : $ssd, '0');
    }
    file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $ini);
}

/** His zones: «CPU» (the CPU package; the CPU cooler and one case fan), «HDD-Käfig» (the six array disks; two case fans); all case fans «darf stehen» */
function fanboyNetSettings(): array
{
    $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
          'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true];
    $fan = fn (int $n, array $x) => $x + ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => $n, 'tach' => $n, 'tachs' => [$n], 'found' => 'tach',
        'name' => "Fan $n", 'back' => 'fixed', 'measured' => $m];
    return ['board' => ['vendor' => 'Example Boards Inc.', 'model' => 'EB-Z100 PRO'], 'armed' => true, 'fans' => [
        fanboyTId(2) => $fan(2, ['role' => 'cpu', 'cpu_own' => ['at' => 1700000000]]),
        fanboyTId(1) => $fan(1, ['role' => 'case', 'off_ok' => true]),
        fanboyTId(4) => $fan(4, ['role' => 'disks', 'off_ok' => true]),
        fanboyTId(5) => $fan(5, ['role' => 'disks', 'off_ok' => true]),
    ], 'zones' => [
        ['id' => 'cpu', 'name' => 'CPU', 'fans' => [fanboyTId(2), fanboyTId(1)], 'unit' => 'pct', 'target' => ['points' => [[30, 0], [62, 0], [81, 100]]],
         'sources' => [['kind' => 'cpu', 'id' => FANBOY_R12_CPU]]],
        ['id' => 'hdd', 'name' => 'HDD-Käfig', 'fans' => [fanboyTId(4), fanboyTId(5)], 'unit' => 'pct', 'target' => ['points' => [[30, 0], [40, 0], [45, 60], [50, 100]]],
         'sources' => array_map(fn ($n) => ['kind' => 'disk', 'id' => $n], ['parity', 'disk1', 'disk2', 'disk3', 'disk4', 'disk5'])],
    ]];
}

/**
 * 2026-11-20 (Q3): a fan that takes longer to start from standing than the kick (4 s) — its measuring kept the longest
 * start that passed (start_secs) — is held at its start value that long + 2 s, and the «stands» clock waits for its
 * kicks (max(15 s, 2 × its kick + 5 s)). Fake fans with a soft start of 6 s and 10 s: no false «stands»; a fan that
 * truly never turns still alarms, at its own clock. Without start_secs: as before (4 s, 15 s).
 */
function testFanboyKickStartSecs(): void
{
    $t = fanboyT('kicksecs');
    try {
        fanboyR12CpuTemp($t, 40);
        $run = function (?float $secs, array $fan4) use ($t): array {
            $spec = fanboyR12Spec();
            $spec['fans'][4] = $fan4 + ['start' => 0.3] + $spec['fans'][4];
            $s = fanboyNetSettings();
            if ($secs !== null) {
                $s['fans'][fanboyTId(4)]['measured']['start_secs'] = $secs;
            }
            $s['zones'] = array_map(fn ($z) => ['react' => 'quick'] + $z, $s['zones']);      // the level at once: one start to watch
            fanboyNetDisks($t, 35, 33);
            fanboyTStore($s);
            fanboyTSettle($t, $spec, 30);
            fanboyBeat();
            $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
            $now = 1000.0;
            fanboyTRounds($c, $now, $t, $spec, 10);         // the cage cool: its fans stand on purpose
            $stood = $c['fans'][fanboyTId(4)]['rpm'];
            fanboyNetDisks($t, 42, 33);                     // 24 % asked — under its start value (40 %): a kick
            $zeroAt = $turnAt = $alarmAt = null;
            for ($i = 0; $i < 30; $i++) {
                fanboyTRounds($c, $now, $t, $spec, 1);
                $f = $c['fans'][fanboyTId(4)];
                $zeroAt ??= $f['zero_since'];
                if ($turnAt === null && ($f['rpm'] ?? 0) > 0 && $zeroAt !== null) {
                    $turnAt = $now - $zeroAt;
                }
                if ($alarmAt === null && ($c['alarm']['code'] ?? null) === 'stands' && $zeroAt !== null) {
                    $alarmAt = $now - $zeroAt;
                }
            }
            $f = $c['fans'][fanboyTId(4)];
            fanboyLetGo($c, 'end');
            return ['stood' => $stood, 'turn' => $turnAt, 'alarm' => $alarmAt, 'kick' => $f['kick_secs'], 'stand' => $f['stand_after']];
        };
        $r = $run(null, ['soft' => 6]);
        same('fanboy kick secs: without start_secs as before — kick 4 s, clock 15 s; a fan needing 6 s never gets them: a false «stands» (it turns only from the alarm\'s 100 %)',
            [0, 4.0, 15.0, true], [$r['stood'], $r['kick'], $r['stand'], $r['alarm'] !== null && ($r['turn'] === null || $r['turn'] > $r['alarm'])], json_encode($r));
        $r = $run(6.0, ['soft' => 6]);
        same('fanboy kick secs: start_secs 6 — kick 8 s, clock 21 s: it turns within its kick, no «stands»',
            [8.0, 21.0, true, null], [$r['kick'], $r['stand'], $r['turn'] !== null && $r['turn'] <= 8.0, $r['alarm']], json_encode($r));
        $r = $run(10.0, ['soft' => 10]);
        same('fanboy kick secs: start_secs 10 — kick 12 s, clock 29 s: it turns within its kick, no «stands»',
            [12.0, 29.0, true, null], [$r['kick'], $r['stand'], $r['turn'] !== null && $r['turn'] <= 12.0, $r['alarm']], json_encode($r));
        $r = $run(10.0, ['stuck' => true]);
        check('fanboy kick secs: start_secs 10 and truly stuck — still «stands», at its own clock: 29 s after it should have turned (+ a round) ('
            . json_encode($r) . ')', $r['turn'] === null && $r['alarm'] !== null && $r['alarm'] >= 29.0 && $r['alarm'] <= 29.0 + 2 * FANBOY_ROUND);
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * Round 12 fix (the owner's first night: «Hinten rechts folgt nicht mehr wie vermessen — 0 U/min, vermessen 300»): the
 * drift look never judges a fan standing on purpose, nor one starting or stopping (the settle time), nor at a value
 * outside its table — and still says a fan that really doesn't follow
 */
function testFanboyNetDrift(): void
{
    $t = fanboyT('netdrift');
    try {
        $spec = fanboyR12Spec();
        fanboyNetDisks($t, 35, 33);
        fanboyR12CpuTemp($t, 40);
        $s = fanboyNetSettings();
        fanboyTStore($s);
        fanboyTSettle($t, $spec);
        $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        $drifts = fn () => array_values(array_filter($c['notes'], fn ($n) => $n['code'] === 'drift'));
        fanboyTRounds($c, $now, $t, $spec, 20);
        same('fanboy net drift: the case fans stand on purpose (their curves ask nothing) — no «doesn\'t follow» after the start\'s look', [0, 0, []],
            [fanboyTOut($t, 4)[0], fanboyTOut($t, 5)[0], $drifts()]);
        fanboyNetDisks($t, 46, 33);                     // the cage warms: they start from standing …
        fanboyTRounds($c, $now, $t, $spec, 20);
        fanboyNetDisks($t, 35, 33);                     // … and stop again
        fanboyTRounds($c, $now, $t, $spec, 60);
        same('fanboy net drift: started from standing, then stopping — still none', [], $drifts());
        fanboyLetGo($c, 'end');
        // a fan that really doesn't follow its table (turning at 60 % of it) at a value its table covers — still said
        $slow = $spec;
        $slow['fans'][4]['max'] = 700;
        // (2026-11-20: Langsam leaves the fans standing at the end above; this fan needs 70 % to start from standing — 48 °C
        // asks 84 %, and up at once gives one steady value to compare)
        fanboyNetDisks($t, 48, 33);
        $s['zones'] = array_map(fn ($z) => ['react' => 'quick'] + $z, $s['zones']);
        fanboyTStore($s);
        $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
        fanboyTRounds($c, $now, $t, $slow, 25);
        same('fanboy net drift: a fan that really runs slower than measured, at a value its table covers — said once', 1,
            count(array_filter($c['notes'], fn ($n) => $n['code'] === 'drift' && str_contains(json_encode($n['params']), 'Fan 4'))));
        fanboyLetGo($c, 'end');
        same('fanboy net drift: the table covers 10–100 % only', [false, true, true, false], [fanboyTableCovers([[100, 1500], [10, 420]], 0), fanboyTableCovers([[100, 1500], [10, 420]], 10),
            fanboyTableCovers([[100, 1500], [10, 420]], 55), fanboyTableCovers([[100, 1500], [10, 420]], 5)]);
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * 2026-11-17 (the owner: «Hinten links folgt nicht mehr wie vermessen», a minute after he saved a zone's curve; the fan
 * was fine): a settings save while the control steers used to hand every output to the mainboard and take it again after
 * the round's look at the hardware — the board ran the fans its own way meanwhile, and the one look after a start (12 s
 * on) found a fan still running down from the board's speed. Now a reload keeps the fans that stay (no hand-over, their
 * values, their check), gives back only those that leave; and «doesn't follow» needs the fan off its table for
 * FANBOY_DRIFT_FOR at one value — a fan running down after a start is no drift, a fan really off its table still is.
 * The fake fans: the board's automatic runs them at full, they spin up in a second and coast down slowly (lag_down).
 */
function testFanboyReloadDrift(): void
{
    $t = fanboyT('reloaddrift');
    try {
        $spec = fanboyR12Spec();
        foreach ([4, 5] as $o) {
            $spec['fans'][$o] = ['output' => $o, 'max' => 2824, 'stall' => 0.08, 'start' => 0.25, 'lag' => 1.0, 'lag_down' => 8.0, 'auto' => 2824];
        }
        // his rear fans as measured (the fake's own curve: 2824 rpm × (0.2 + 0.8 × value)), both «darf stehen», back to the automatic
        $m = ['table' => [[100, 2824], [50, 1694], [20, 1017], [10, 790]], 'min_pct' => 14, 'start_pct' => 14, 'max_rpm' => 2824, 'at' => 1,
              'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 26, 'kick_start' => null, 'stops_safe' => true];
        $fan = fn (int $o, string $name) => ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => $o, 'tach' => $o, 'tachs' => [$o], 'found' => 'tach',
            'name' => $name, 'back' => 'auto', 'measured' => $m, 'role' => 'case', 'off_ok' => true];
        $settings = fn (array $pts, array $fans = []) => ['board' => ['vendor' => 'Example Boards Inc.', 'model' => 'EB-Z100 PRO'], 'armed' => true,
            'fans' => $fans + [fanboyTId(4) => $fan(4, 'Rear left'), fanboyTId(5) => $fan(5, 'Rear right')],
            'zones' => [['id' => 'hdd', 'name' => 'Disks', 'fans' => [fanboyTId(4), fanboyTId(5)], 'unit' => 'pct', 'react' => 'balanced', 'target' => ['points' => $pts],
                'sources' => array_map(fn ($x) => ['kind' => 'disk', 'id' => $x], ['parity', 'disk1', 'disk2', 'disk3', 'disk4', 'disk5'])]]];
        $old = $settings([[30, 0], [40, 0], [45, 60], [50, 100]]);
        $new = $settings([[39, 0], [43, 31], [46, 61], [47, 100]]);         // his curve after the save
        fanboyNetDisks($t, 41, 30);
        fanboyTStore($old);
        foreach ([4, 5] as $o) {
            file_put_contents("{$t['hw']}/pwm{$o}_enable", "2\n");          // the mainboard's automatic before the control
        }
        fanboyTSettle($t, $spec, 30);
        $drifts = fn (array $c) => array_values(array_filter($c['notes'], fn ($n) => $n['code'] === 'drift'));
        // off its table at a look: what the one look after a start compared (rpm against the table at the value written)
        $off = function (array $f): bool {
            $want = $f['value'] === null || $f['rpm'] === null ? null : fanboyTableRpm($f['table'], $f['value'] / 2.55);
            return $want !== null && abs($f['rpm'] - $want) > max(250, $want * FANBOY_DRIFT);
        };
        $run = function (array &$c, float &$now, int $rounds, array $spec, ?float &$firstLook = null, ?bool &$offThen = null) use ($t, $off): void {
            for ($i = 0; $i < $rounds; $i++) {
                fanboyRound($c, $now);
                $f = $c['fans'][fanboyTId(4)];
                if ($firstLook === null && $c['started'] !== null && $now - $c['started'] >= FANBOY_VERIFY_AFTER && $f['changed'] !== null && $now - $f['changed'] >= FANBOY_SETTLE_MAX) {
                    [$firstLook, $offThen] = [$now, $off($f)];
                }
                fanboyFakeRespond($t['root'], $spec, FANBOY_ROUND);
                $now += FANBOY_ROUND;
            }
        };

        // the start: the board had them at full; written 19 % (his floor), they coast down — off their table at the first look
        $owner = fanboyMe('control');
        $c = fanboyControlNew($old, FANBOY_T_KEY, $owner);
        $now = 1000.0;
        $look = $offAtLook = null;
        $run($c, $now, 60, $spec, $look, $offAtLook);
        same('fanboy reload: the start from the board\'s full speed — at the first look 12 s on still running down (the one look said «doesn\'t follow»), now no drift',
            [true, [], 48, true], [$offAtLook, $drifts($c), $c['fans'][fanboyTId(4)]['value'], $c['fans'][fanboyTId(4)]['verified']]);

        // the old way of a save, done by hand with the control's own steps: every output to the board, taken anew after the
        // round's look at the hardware (1.5 s of the board meanwhile) — at the look 12 s on the fan is off its table again:
        // the warning of 2026-11-17
        $o = $c;
        fanboyLetGo($o, 'settings');
        $o = ['hist' => $o['hist']] + fanboyControlNew($new, FANBOY_T_KEY, $owner);
        fanboyFakeRespond($t['root'], $spec, 1.5);
        $look = $offAtLook = null;
        $nowOld = $now;
        $run($o, $nowOld, 10, $spec, $look, $offAtLook);
        same('fanboy reload: the old way (all handed to the board and taken again) — the look 12 s after the save finds the fan off its table',
            [true, 'run'], [$offAtLook, $o['phase']]);
        fanboyLetGo($o, 'end');
        $c = fanboyControlNew($old, FANBOY_T_KEY, $owner);
        fanboyTSettle($t, $spec, 30);
        $run($c, $now, 60, $spec);
        same('fanboy reload: … steering again on the old settings (the set-up for the save), no drift', [[], true, 48], [$drifts($c), $c['fans'][fanboyTId(4)]['verified'], $c['fans'][fanboyTId(4)]['value']]);

        // the save now: nothing handed over — the outputs as written, the journal's holder the same, nothing given back
        $j = fanboyJournal()['outputs'];
        $level = $c['zones']['hdd']['cur'];
        fanboyTStore($new);
        fanboyControlReload($c, $new, FANBOY_T_KEY, $owner);
        fanboyFakeRespond($t['root'], $spec, 1.5);                         // the round's look at the hardware
        $j2 = fanboyJournal()['outputs'];
        $given = [$j[fanboyTId(4)]['given'], $j[fanboyTId(5)]['given']];     // the last give-back before (the set-up's), as it was
        same('fanboy reload: the save while he steers — the outputs stay as written (19 %, fixed), the same holder, nothing given back, its check kept, the zone\'s level kept',
            [[48, 1], [48, 1], $j[fanboyTId(4)]['owner'], $given, true, $level],
            [fanboyTOut($t, 4), fanboyTOut($t, 5), $j2[fanboyTId(4)]['owner'], [$j2[fanboyTId(4)]['given'], $j2[fanboyTId(5)]['given']],
             $c['fans'][fanboyTId(4)]['verified'], $c['zones']['hdd']['cur']]);
        $run($c, $now, 30, $spec);
        foreach ([1, 2, 3] as $i) {
            fanboyControlReload($c, $i % 2 ? $old : $new, FANBOY_T_KEY, $owner);
            fanboyFakeRespond($t['root'], $spec, 1.5);
            $run($c, $now, 10, $spec);
        }
        same('fanboy reload: … and saved three times more — the new curve steers, no drift, nothing given back',
            [[], 48, 48, $given], [$drifts($c), $c['fans'][fanboyTId(4)]['value'], $c['fans'][fanboyTId(5)]['value'],
             [fanboyJournal()['outputs'][fanboyTId(4)]['given'], fanboyJournal()['outputs'][fanboyTId(5)]['given']]]);

        // a fan that leaves (his role now the mainboard's): given back, alone; one measured anew is looked at again
        $leave = $settings([[39, 0], [43, 31], [46, 61], [47, 100]], [fanboyTId(5) => ['role' => 'unknown'] + $fan(5, 'Rear right'),
            fanboyTId(4) => ['measured' => ['table' => [[100, 2824], [50, 1694], [20, 1017], [10, 789]], 'at' => 2] + $m] + $fan(4, 'Rear left')]);
        fanboyControlReload($c, $leave, FANBOY_T_KEY, $owner);
        $j = fanboyJournal()['outputs'];
        same('fanboy reload: a fan that leaves is given back (to the board\'s automatic, why «settings»), the other stays; measured anew: its check again',
            [false, 'settings', 2, true, false, [48, 1]],
            [isset($c['fans'][fanboyTId(5)]), $j[fanboyTId(5)]['given']['why'] ?? null, fanboyTOut($t, 5)[1], $c['fans'][fanboyTId(4)]['taken'], $c['fans'][fanboyTId(4)]['verified'], fanboyTOut($t, 4)]);
        $run($c, $now, 20, $spec);
        same('fanboy reload: … looked at again, it follows', [[], true], [$drifts($c), $c['fans'][fanboyTId(4)]['verified']]);
        fanboyLetGo($c, 'end');

        // a fan that really doesn't follow its table (its fake turns at 60 % of the rpm measured), at 19 % already when the
        // control starts: still said — once, only after it stayed off for FANBOY_DRIFT_FOR at one value
        $slow = $spec;
        $slow['fans'][4]['max'] = 1700;
        foreach ([4, 5] as $o) {
            file_put_contents("{$t['hw']}/pwm$o", "48\n");
            file_put_contents("{$t['hw']}/pwm{$o}_enable", "1\n");
        }
        fanboyTSettle($t, $slow, 30);
        $c = fanboyControlNew($new, FANBOY_T_KEY, $owner);
        $look = $offAtLook = $first = null;
        for ($i = 0; $i < 40; $i++) {
            $run($c, $now, 1, $slow, $look, $offAtLook);
            $first ??= $drifts($c) ? $now - FANBOY_ROUND - $c['started'] : null;
        }
        $d = $drifts($c);
        same('fanboy reload: a fan really off its table — off at the first look, said once (its name, the rpm against the table)', [true, 1, 'Rear left', 596, 990],
            [$offAtLook, count($d), $d[0]['params']['fan'] ?? null, $d[0]['params']['rpm'] ?? null, $d[0]['params']['want'] ?? null]);
        check('fanboy reload: … said FANBOY_DRIFT_FOR after the first look, not before (' . var_export($first, true) . ' s after the start)',
            $first !== null && $look !== null && $first >= $look - $c['started'] + FANBOY_DRIFT_FOR && $first <= $look - $c['started'] + FANBOY_DRIFT_FOR + FANBOY_ROUND);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 12 fix (his SSD pool and NVMe in no zone, 33 → 50 °C with every fan standing): the setup says which sources are
 * in no zone; the control's net — at a source's «volle Drehzahl bei» (SSD 70 °C since 2026-11-20) every fan he steers ≥ 60 % (standing ones
 * start), at its limit (80 since 2026-11-20) 100 %, one notification, over after 2 minutes 5 °C under; a sleeping disk never counts
 */
function testFanboyNet(): void
{
    $t = fanboyT('net');
    try {
        $spec = fanboyR12Spec();
        fanboyNetDisks($t, 35, 33);
        fanboyR12CpuTemp($t, 40);
        $s = fanboyNetSettings();
        fanboyTStore($s);
        fanboyTSettle($t, $spec);
        $un = fanboySetupState(fanboyDetect(), fanboyBoardRaw())['uncovered'];
        $nvme = array_values(array_filter(array_column($un, 'id'), fn ($id) => str_starts_with($id, 'nvme@')));
        same('fanboy net: his layout — the six SSDs and the NVMe sensors are in no zone, nothing else (disks, SSDs, NVMe only); kind, name, °C',
            [['ssd', 'ssd2', 'ssd3', 'ssd4', 'ssd5', 'ssd6'], true, [], ['ssd', 33]],
            [array_slice(array_column($un, 'id'), 0, 6), in_array(FANBOY_T_NVME, $nvme, true), array_values(array_diff(array_column($un, 'id'), ['ssd', 'ssd2', 'ssd3', 'ssd4', 'ssd5', 'ssd6'], $nvme)),
             [$un[0]['kind'] ?? null, $un[0]['c'] ?? null]]);
        $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy net: all cool — the case fans stand, no net', [[0, 0, 0], null], [[fanboyTOut($t, 1)[0], fanboyTOut($t, 4)[0], fanboyTOut($t, 5)[0]], $c['net']]);
        fanboyNetDisks($t, 35, 69);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy net: the SSDs at 69 °C (under 70) — nothing yet', null, $c['net']);
        $n0 = count(fanboyTNotified($t));
        fanboyNetDisks($t, 35, 70);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy net: the SSDs at 70 °C (the SSD kind\'s «volle Drehzahl bei», 2026-11-20) — every fan he steers at least 60 %, the standing ones started; the state says which',
            [60, true, true, true, 'ssd'], [$c['net']['level'] ?? null, fanboyTOut($t, 1)[0] >= 153, fanboyTOut($t, 4)[0] >= 153, fanboyTOut($t, 5)[0] >= 153, $c['net']['source']['kind'] ?? null]);
        fanboyNetDisks($t, 35, 80);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy net: at 80 °C (its limit) — 100 %', [100, 255], [$c['net']['level'] ?? null, fanboyTOut($t, 4)[0]]);
        $said = array_values(array_filter(array_slice(fanboyTNotified($t), $n0), fn ($l) => str_contains($l, 'in no zone') || str_contains($l, 'in keiner Zone')));
        same('fanboy net: said once (warm), once more at its limit (an alert)', 2, count($said));
        fanboyNetDisks($t, 35, 66);
        fanboyTRounds($c, $now, $t, $spec, 70);
        same('fanboy net: 66 °C (not yet 5 under 70) — it holds', 100, $c['net']['level'] ?? null);
        fanboyNetDisks($t, 35, 64);
        fanboyTRounds($c, $now, $t, $spec, 70);
        same('fanboy net: 64 °C for 2 minutes — over, the case fans stand again', [null, 0], [$c['net'], fanboyTOut($t, 4)[0]]);
        fanboyNetDisks($t, 35, 75, true);
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy net: the SSDs asleep (Unraid: spun down) — never counted', [null, null], [$c['net'], (array_values(array_filter(fanboyStatus($c)['loose'], fn ($l) => $l['id'] === 'ssd'))[0] ?? ['c' => 'x'])['c']],
            json_encode(array_column(fanboyStatus($c)['loose'], 'id')));
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/** Round 12 fix: «take over» with fans that may stand while heat sources are in no zone — refused without an explicit yes */
function testFanboyNetTakeOver(): void
{
    $t = fanboyT('nettake');
    try {
        fanboyNetDisks($t, 35, 33);
        fanboyTStore(fanboyNetSettings() + ['armed' => false]);
        $s = fanboyBoardRaw();
        $s['armed'] = false;
        fanboyTStore($s);
        same('fanboy net take over: fans may stand and sources are in no zone — refused (fanboy_uncovered), not armed', ['fanboy_uncovered', false],
            [fanboyR6Refused(fn () => fanboyTakeOver([])), fanboyBoardRaw()['armed'] ?? null]);
        same('fanboy net take over: … with the explicit yes — armed', ['', true], [fanboyR6Refused(fn () => fanboyTakeOver(['uncovered_ok' => true])), fanboyBoardRaw()['armed'] ?? null]);
        $s = fanboyBoardRaw();
        $s['armed'] = false;
        foreach ($s['fans'] as $id => $f) {
            $s['fans'][$id]['off_ok'] = false;
        }
        fanboyTStore($s);
        same('fanboy net take over: no fan may stand — no question', '', fanboyR6Refused(fn () => fanboyTakeOver([])));
    } finally {
        fanboyTEnd();
    }
}

/** Round 13: a zone as the control keeps it (only what fanboyZoneRound() reads), and one round of it with these temperatures */
function fanboyR13Zone(array $z): array
{
    return $z + ['id' => 'z', 'name' => 'Z', 'fans' => [], 'unit' => 'pct', 'target' => ['preset' => 'normal'], 'low' => null, 'high' => null, 'kinds' => [],
        'curves' => [], 'react' => 'balanced', 'cur' => null, 'hold_t' => null, 'warmest' => null, 'hot_since' => null, 'below_since' => null, 'level' => null,
        'state' => [], 'by_kind' => [], 'leader' => null];
}

function fanboyR13Round(array &$c, array $temps, float $now): float
{
    foreach ($temps as $id => $t) {
        $c['sources'][$id] = ['kind' => 'x', 'label' => $id, 'c' => $t, 'asleep' => false, 'quiet_since' => null, 'path' => '/x'];
    }
    $reasons = [];
    fanboyZoneRound($c, 'z', $now, $reasons);
    return (float) $c['zones']['z']['cur'];
}

/** Round 12's zone computation (before round 13): every source laid onto the zone's scale, the zone's curve at the warmest */
function fanboyR13Old(array $z, array $temps): float
{
    [$lo, $hi] = fanboyZoneScale($z);
    $best = null;
    foreach ($z['sources'] as $s) {
        $s = fanboySourceValues($z, $s);
        if (isset($temps[$s['id']])) {
            $m = fanboyMapped($s, (float) $temps[$s['id']], $lo, $hi, $z['sources'][0]['kind'] ?? '');
            $best = max($best ?? $m, $m);
        }
    }
    $curve = fanboyZoneCurve($z);
    $raw = $best === null ? $curve[0][1] : fanboyCurveAt($curve, $best);
    return $z['unit'] === 'pct' ? max(0.0, min(100.0, $raw)) : $raw;
}

/**
 * Round 13 compat, 2026-11-20 (the goal per kind, the owner's presets): a zone without `curves` (an earlier set-up's) — each
 * kind on its zone's preset on the kind's own scale; the zone's own points moved from the zone's scale onto each kind's
 * (as before); stored «Temperaturen» and a source's own values steer nothing
 */
function testFanboyR13Compat(): void
{
    $src = fn (string $kind, string $id, array $more = []) => ['kind' => $kind, 'id' => $id] + $more;
    $z = fanboyR13Zone(['sources' => [$src('disk', 'd1'), $src('cpu', 'c1')]]);
    same('fanboy r13 compat: no curves, the zone «normal» — each kind its own Normal (disk 40→50, CPU 50→80), flagged not own',
        [false, false, [[40.0, 0], [50.0, 100]], [[50.0, 0], [80.0, 100]]],
        [fanboyKindCurve($z, 'disk')[1], fanboyKindCurve($z, 'cpu')[1], fanboyKindCurve($z, 'disk')[0], fanboyKindCurve($z, 'cpu')[0]]);
    $q = fanboyR13Zone(['sources' => [$src('cpu', 'c1'), $src('disk', 'd1')], 'target' => ['preset' => 'quiet'], 'kinds' => ['cpu' => ['low' => 40, 'high' => 75]], 'low' => 38]);
    same('fanboy r13 compat: the zone «quiet» — each kind its own Leise (CPU 60→90, disk 45→55); stored «Temperaturen» and the zone\'s own scale not read',
        [[[60.0, 0], [90.0, 100]], [[45.0, 0], [55.0, 100]]], [fanboyKindCurve($q, 'cpu')[0], fanboyKindCurve($q, 'disk')[0]]);
    $o = fanboyR13Zone(['sources' => [$src('disk', 'd1'), $src('cpu', 'c1')], 'target' => ['points' => [[40, 0], [45, 60], [50, 100]]]]);
    same('fanboy r13 compat: the zone\'s own points — the first kind\'s as they are, the others moved onto their own scale (disk 40…50 → CPU 50…80)',
        [[[40.0, 0.0], [45.0, 60.0], [50.0, 100.0]], [[50.0, 0.0], [65.0, 60.0], [80.0, 100.0]]], [fanboyKindCurve($o, 'disk')[0], fanboyKindCurve($o, 'cpu')[0]]);
}

/** Round 13: one kind with its own curve, the others derived; the highest ask wins; the status says each kind's curve, warmest and ask, and who leads */
function testFanboyR13Curves(): void
{
    $src = fn (string $kind, string $id) => ['kind' => $kind, 'id' => $id];
    $z = fanboyR13Zone(['sources' => [$src('disk', 'd1'), $src('disk', 'd2'), $src('cpu', 'c1')], 'curves' => ['cpu' => ['points' => [[40, 0], [60, 100]]]]]);
    $c = ['zones' => ['z' => $z], 'sources' => [], 'fans' => [], 'full_since' => []];
    $level = fanboyR13Round($c, ['d1' => 36, 'd2' => 44, 'c1' => 50], 0.0);
    same('fanboy r13: the CPU on its own curve (50 °C: 50 %), the disks on the zone\'s Normal (44 °C: 40 %) — the highest wins: 50 %, the CPU leads',
        [50.0, ['source' => 'c1', 'kind' => 'cpu'], ['source' => 'd2', 'c' => 44, 'value' => 40.0], ['source' => 'c1', 'c' => 50, 'value' => 50.0]],
        [$level, $c['zones']['z']['leader'], array_intersect_key($c['zones']['z']['by_kind']['disk'], array_flip(['source', 'c', 'value'])),
            array_intersect_key($c['zones']['z']['by_kind']['cpu'], array_flip(['source', 'c', 'value']))]);
    $c2 = ['zones' => ['z' => $z], 'sources' => [], 'fans' => [], 'full_since' => []];
    same('fanboy r13: … the disk warmer (48 °C asks 80 %) — the disks lead', [80.0, 'disk'], [round(fanboyR13Round($c2, ['d1' => 48, 'd2' => 40, 'c1' => 50], 0.0)), $c2['zones']['z']['leader']['kind']]);
    $z2 = fanboyR13Zone(['sources' => [$src('disk', 'd1'), $src('cpu', 'c1')], 'curves' => ['cpu' => ['preset' => 'quiet']]]);
    same('fanboy r13: a kind\'s own preset lies on its own scale (CPU Leise since 2026-11-20: 60 °C 0 %, 90 °C 100 %)', [[[60.0, 0], [90.0, 100]], true],
        fanboyKindCurve($z2, 'cpu'));
    // the status the page reads: every kind's curve (own or derived), its warmest and what it asks, the leader
    $st = ['zones' => [['id' => 'z', 'name' => 'Z', 'unit' => 'pct', 'cur' => 50.0, 'react' => 'balanced', 'warmest' => $c['zones']['z']['warmest'], 'state' => [],
        'fans' => [], 'sources' => $z['sources'], 'curves' => $z['curves'], 'kinds' => [], 'by_kind' => $c['zones']['z']['by_kind'], 'leader' => $c['zones']['z']['leader']]]];
    $zs = fanboyZoneKindCurves($z);
    same('fanboy r13 status: per kind its curve and whether it is its own', [['disk', 'cpu'], false, true], [array_keys($zs), $zs['disk']['own'], $zs['cpu']['own']]);
    check('fanboy r13 status: the control\'s status and the dry run carry curves, by_kind and leader per zone',
        str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php'), "'curves' => fanboyZoneKindCurves(\$z), 'by_kind' =>")
        && str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-setup.php'), "'curves' => fanboyZoneKindCurves(\$z), 'by_kind' =>"), json_encode($st['zones'][0]['leader']));

    // the way down follows the leading kind after a change of leader: the CPU (Langsam: 2 % a round) led high, then cooled —
    // the disks lead now, and it comes down by the disks' way (after 30 s, 1 % a round), not the CPU's
    $z3 = fanboyR13Zone(['sources' => [$src('cpu', 'c1'), $src('disk', 'd1')], 'curves' => ['cpu' => ['points' => [[40, 0], [60, 100]]]]]);
    $c3 = ['zones' => ['z' => $z3], 'sources' => [], 'fans' => [], 'full_since' => []];
    $now = 0.0;
    fanboyR13Round($c3, ['c1' => 56, 'd1' => 40], $now);           // the CPU asks 80 %, the disk 45 %
    $levels = [];
    for ($i = 0; $i < 21; $i++) {
        $now += 2.0;
        $levels[] = fanboyR13Round($c3, ['c1' => 30, 'd1' => 40], $now);
    }
    same('fanboy r13: the CPU led at 80 %, then cooled — the disk leads (45 %) and the zone comes down by the disks\' way: held 30 s, then 1 % a round',
        ['disk', [...array_fill(0, 15, 80.0), 79.0, 78.0, 77.0, 76.0, 75.0, 74.0]], [$c3['zones']['z']['leader']['kind'], $levels]);
}

/** Round 13: own curves refused — a kind the zone doesn't have, too few points, out of bounds, a preset in an rpm zone, a list */
function testFanboyR13Refused(): void
{
    $t = fanboyT('r13ref');
    try {
        $settings = fanboyTSettings();
        $zone = $settings['zones'][0];          // disks, its fan 4, three disk sources
        $check = function (array $curves, string $unit = 'pct') use ($zone, $settings): string {
            $z = ['curves' => $curves, 'unit' => $unit] + $zone;
            if ($unit === 'rpm') {
                $z['target'] = ['points' => [[30, 600], [45, 1400]]];
            }
            return fanboyR6Refused(fn () => fanboyZonesCheck([$z], fanboyDetect(), $settings));
        };
        same('fanboy r13 refused: a kind the zone has no source of, one point, 130 °C, a value over 100 %, a preset in an rpm zone, a list instead of kinds',
            array_fill(0, 6, 'fanboy_bad_zones'),
            [$check(['cpu' => ['points' => [[40, 0], [60, 100]]]]), $check(['disk' => ['points' => [[40, 0]]]]), $check(['disk' => ['points' => [[40, 0], [130, 100]]]]),
             $check(['disk' => ['points' => [[40, 0], [60, 101]]]]), $check(['disk' => ['preset' => 'quiet']], 'rpm'), $check([['points' => [[40, 0], [60, 100]]]])]);
        $ok = fanboyZonesCheck([['curves' => ['disk' => ['points' => [[35, 10], [45, 90]]]]] + $zone], fanboyDetect(), $settings)[0];
        check('fanboy r13: a good one stored for that kind only (the others stay derived); none sent: none stored',
            json_decode(json_encode($ok['curves']), true) == ['disk' => ['points' => [[35, 10], [45, 90]]]] && json_encode(fanboyZonesCheck([$zone], fanboyDetect(), $settings)[0]['curves']) === '{}',
            json_encode($ok['curves']));
    } finally {
        fanboyTEnd();
    }
}

/** Round 13b: devs.ini on the fake board — the disks outside array and pools (ids carry serials that must never leak) */
function fanboyDevsFixture(array $t): void
{
    $dev = fn (int $n, string $device, string $id, string $rot, string $down, string $temp, string $sectors) =>
        "[\"dev$n\"]\nname=\"dev$n\"\nid=\"$id\"\ndevice=\"$device\"\nsectors=\"$sectors\"\nsector_size=\"512\"\nrotational=\"$rot\"\nspundown=\"$down\"\ntemp=\"$temp\"\nnumReads=\"0\"\nnumWrites=\"0\"\nnumErrors=\"0\"\n";
    $big = '27344764928';           // 14.0 TB
    file_put_contents("{$t['root']}/var/local/emhttp/devs.ini",
        $dev(1, 'sdf', 'EXAMPLE_HDD_14TB_SERIALAAA111', '1', '0', '34', $big)
        . $dev(2, 'sdg', 'EXAMPLE_HDD_14TB_SERIALBBB222', '1', '1', '*', $big)
        . $dev(3, 'sdh', 'EXAMPLE_HDD_14TB_SERIALCCC333', '1', '1', '*', $big)
        . $dev(4, 'sdi', 'EXAMPLE_SSD_1TB_SERIALDDD444', '0', '0', '31', '1953525168')
        . $dev(5, 'nvme1n1', 'EXAMPLE_NVME_SERIALEEE555', '0', '0', '40', '1953525168'));
    @mkdir("{$t['root']}/var/state/unassigned.devices", 0700, true);
    file_put_contents("{$t['root']}/var/state/unassigned.devices/disk_names.json", json_encode(['sdi' => 'Scratch', 'EXAMPLE_GONE_SERIALFFF666' => 'Old']));
    foreach (['sdf' => 1000, 'sdg' => 2000, 'sdh' => 3000, 'sdi' => 4000] as $d => $io) {
        @mkdir("{$t['root']}/sys/block/$d", 0700, true);
        fanboyDevsIo($t, $d, $io);
    }
}

function fanboyDevsIo(array $t, string $dev, int $reads, int $inFlight = 0): void
{
    file_put_contents("{$t['root']}/sys/block/$dev/stat", sprintf("%8d %8d %8d %8d %8d %8d %8d %8d %8d %8d %8d\n", $reads, 0, $reads * 8, 10, 50, 0, 400, 5, $inFlight, 20, 15));
}

/**
 * Round 13b (the owner: «hier fehlen doch Festplatten — es hat ja auch Festplatten an VMs oder nicht zugewiesene»): the
 * disks outside array and pools from devs.ini — awake with a temperature, asleep, in use without one (its I/O counters
 * moved), an SSD, three alike, an NVMe left to its own sensor; a stable id that carries no serial; the plugin's name
 */
function testFanboyR13Devs(): void
{
    $t = fanboyT('r13devs');
    try {
        fanboyTDisks($t, 35, null, 33);
        fanboyDevsFixture($t);
        @unlink(fanboyRunDir() . '/devstat.json');
        $p = fanboyPaths();
        fanboyDisks($p['disks']);                       // the first look: the counters noted
        fanboyDevsIo($t, 'sdh', 3500);                  // a VM reads from sdh while Unraid takes it for spun down
        $un = array_values(array_filter(fanboyDisks($p['disks'], fanboyDiskLimits($p)), fn ($d) => $d['type'] === 'unassigned'));
        $by = array_column($un, null, 'device');
        same('fanboy r13 devs: four disks outside array and pools (the NVMe left to its own sensor) — awake 34 °C, asleep, in use without a temperature, an SSD',
            [['sdf', 'sdg', 'sdh', 'sdi'], [false, 34, false], [true, null, false], [false, null, true], [false, 31, false, false]],
            [array_column($un, 'device'), [$by['sdf']['asleep'], $by['sdf']['temp'], $by['sdf']['in_use']], [$by['sdg']['asleep'], $by['sdg']['temp'], $by['sdg']['in_use']],
             [$by['sdh']['asleep'], $by['sdh']['temp'], $by['sdh']['in_use']], [$by['sdi']['asleep'], $by['sdi']['temp'], $by['sdi']['in_use'], $by['sdi']['rotational']]]);
        same('fanboy r13 devs: a stable id from devs.ini\'s id (hashed: «ud-» + 12 hex), the same on every look; the plugin\'s name for the SSD',
            [true, fanboyDevId('EXAMPLE_HDD_14TB_SERIALAAA111'), 'Scratch', null], [(bool) preg_match('/^ud-[0-9a-f]{12}$/D', $by['sdf']['name']), $by['sdf']['name'], $by['sdi']['label'], $by['sdf']['label']]);
        $sources = array_values(array_filter(fanboySources(['disks' => fanboyDisks($p['disks']), 'chips' => []]), fn ($s) => ($s['group'] ?? null) === 'unassigned'));
        $names = fanboySourceNames($sources, 'de');
        same('fanboy r13 devs: their own group; the names — the plugin\'s, else «Nicht zugewiesen · 14,0 TB» with a number when alike; kinds disk/ssd',
            [['Nicht zugewiesen · 14,0 TB (1)', 'Nicht zugewiesen · 14,0 TB (2)', 'Nicht zugewiesen · 14,0 TB (3)', 'Scratch'], ['disk', 'disk', 'disk', 'ssd']],
            [array_values(array_map(fn ($s) => $names[$s['id']], $sources)), array_column($sources, 'kind')]);
        // nothing of a serial anywhere: the page's state, the setup's sources, the report, the RAM notes
        $state = jsonEncode(fanboyScan());
        $report = fanboyReportText($scan = fanboyDetect(), fanboyIpmiFans($scan));
        $ram = (string) @file_get_contents(fanboyRunDir() . '/devstat.json');
        same('fanboy r13 devs: no serial (nor the id) in the page\'s state, the report or the RAM notes', [false, false, false],
            [(bool) preg_match('/SERIAL|EXAMPLE_HDD/', $state), (bool) preg_match('/SERIAL|EXAMPLE_HDD/', $report), (bool) preg_match('/SERIAL|EXAMPLE_HDD/', $ram)]);
        check('fanboy r13 devs: … the report counts them', str_contains($report, 'Disks outside array and pools: 4 (3 rotating, 1 asleep, 1 in use without a temperature)'), $report);
        // the zones' proposal: the rotating ones where the array's disks go
        $settings = fanboyTSettings();
        $zones = fanboyZonesPropose(fanboyDetect(), $settings);
        $disksZone = array_column(array_values(array_filter($zones, fn ($z) => $z['id'] === 'disks'))[0]['sources'] ?? [], 'id');
        same('fanboy r13 devs: the proposal puts the rotating ones into the disks zone (the SSD not)', [true, true, true, false],
            [in_array($by['sdf']['name'], $disksZone, true), in_array($by['sdg']['name'], $disksZone, true), in_array($by['sdh']['name'], $disksZone, true), in_array($by['sdi']['name'], $disksZone, true)]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 13b: the control with disks outside array and pools in a zone — one awake counts (with part 5's allowance), one
 * asleep asks nothing, one in use without a temperature is said («in_use») and taken like a sensor that says nothing:
 * after its wait the zone at full (the safer way)
 */
function testFanboyR13DevsControl(): void
{
    $t = fanboyT('r13devsctl');
    try {
        fanboyTDisks($t, 30, null, 30);
        fanboyDevsFixture($t);
        @unlink(fanboyRunDir() . '/devstat.json');
        $spec = fanboyTSpec();
        $p = fanboyPaths();
        fanboyDisks($p['disks']);
        $ids = array_column(array_values(array_filter(fanboyDisks($p['disks']), fn ($d) => $d['type'] === 'unassigned')), 'name', 'device');
        $settings = fanboyTSettings();
        $settings['zones'] = [['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4)], 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => [['kind' => 'disk', 'id' => 'parity'], ['kind' => 'disk', 'id' => $ids['sdf']], ['kind' => 'disk', 'id' => $ids['sdg']]]]];
        fanboyTStore($settings);
        fanboyTSettle($t, $spec);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);
        $z = $c['zones']['disks'];
        same('fanboy r13 devs control: the awake one (34 °C) leads over parity (30), the asleep one asks nothing', [$ids['sdf'], 34, 'asleep'],
            [$z['warmest']['id'] ?? null, $z['warmest']['c'] ?? null, $z['state'][$ids['sdg']] ?? null]);
        // sdg is used by a VM now: its counters move, Unraid still says spun down — in use, no temperature
        $settings['zones'][0]['sources'][] = ['kind' => 'disk', 'id' => $ids['sdh']];
        fanboyLetGo($c, 'test');
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        for ($i = 0; $i < 70; $i++) {
            fanboyDevsIo($t, 'sdh', 3000 + $i * 50);
            fanboyTRounds($c, $now, $t, $spec, 1);
        }
        $st = fanboyStatus($c)['zones'][0];
        same('fanboy r13 devs control: a disk in use without a temperature — said («in_use»), and after its wait (120 s) the zone at full with the sensor alarm',
            [[$ids['sdh']], true, 'sensor', 255], [$st['in_use'] ?? null, $st['full'] ?? null, $c['alarm']['code'] ?? null, fanboyTOut($t, 4)[0]]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/** Round 14: output 1 as a hub — two fans on a Y-cable, the one that reports (tach 1) measured down to 10 %, starts from standstill at $start */
function fanboyR14Store(array $more = [], int $start = 35): void
{
    $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
          'stops' => true, 'floor_rpm' => null, 'start_from_stop' => $start, 'kick_start' => null, 'stops_safe' => true];
    fanboyR12Store([1 => $m]);
    fanboyStoreUpdate(function (array $e) use ($more): array {
        $e['fans'][fanboyTId(1)] = $more + ['count' => 2] + $e['fans'][fanboyTId(1)];
        return $e;
    });
}

/**
 * Round 14 (the owner: two fans on one Y-cable — two equal, two different?): several fans on one connector not said to
 * be alike — the floor min + 10 (at least 25 %), the start + 10, «darf stehen» only after the look said all start again
 */
function testFanboyR14Plan(): void
{
    $t = fanboyT('r14plan');
    try {
        fanboyR14Store(['off_ok' => true]);
        $f = fn () => fanboyBoardRaw()['fans'][fanboyTId(1)];
        $plan = fn (array $more = []) => array_intersect_key(fanboyFanPlan($more + $f()), array_flip(['floor', 'start', 'off_ok', 'off_why']));
        same('fanboy r14 plan: one fan — as measured (floor 15 %, start 40 %: 5 % over its start from standstill, may stand)', ['floor' => 15, 'start' => 40, 'off_ok' => true, 'off_why' => null], $plan(['count' => 1]));
        same('fanboy r14 plan: two fans, «alike» not said — floor 25 % (min + 10, at least 25), start 50 %, may not stand (mixed_hub)',
            ['floor' => 25, 'start' => 50, 'off_ok' => false, 'off_why' => 'mixed_hub'], $plan());
        same('fanboy r14 plan: … the same model on every plug (alike) — as measured', ['floor' => 15, 'start' => 40, 'off_ok' => true, 'off_why' => null], $plan(['alike' => true]));
        same('fanboy r14 plan: … the look said «not all turn» once (+10): floor 35 %; it said all start again (hub_ok): may stand',
            [35, ['off_ok' => true, 'off_why' => null]], [$plan(['hub_extra' => 10])['floor'], array_intersect_key($plan(['hub_ok' => ['at' => 1]]), array_flip(['off_ok', 'off_why']))]);
        same('fanboy r14 plan: … a no on the restart: never stand (hub_not_safe)', ['off_ok' => false, 'off_why' => 'hub_not_safe'],
            array_intersect_key($plan(['hub_ok' => ['at' => 1], 'hub_not_safe' => ['at' => 2]]), array_flip(['off_ok', 'off_why'])));
        // the role: «alike» saved alone (true, false, null = not said); another count or «alike» forgets what the look found
        fanboyStoreUpdate(function (array $e): array {
            $e['fans'][fanboyTId(1)]['hub_ok'] = ['at' => 5];
            $e['fans'][fanboyTId(1)]['hub_extra'] = 10;
            return $e;
        });
        $role = fn (array $r) => fanboyRole($r + ['fan' => fanboyTId(1), 'role' => 'disks', 'off_ok' => true])['fan'];
        $r1 = $role(['name' => 'Y']);
        $r2 = $role(['alike' => false]);
        same('fanboy r14 role: a name alone keeps «alike» and the look\'s finding; «alike» false said — stored, the finding forgotten',
            [[null, 5, 10], [false, null, null]], [[$r1['alike'] ?? null, $r1['hub_ok']['at'] ?? null, $r1['hub_extra'] ?? null], [$r2['alike'] ?? null, $r2['hub_ok']['at'] ?? null, $r2['hub_extra'] ?? null]]);
        same('fanboy r14 role: … null clears it; «alike» only as true/false/null', [false, 'bad_request'],
            [array_key_exists('alike', $role(['alike' => null])), fanboyR6Refused(fn () => $role(['alike' => 'yes']))]);
        $s = array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id')[fanboyTId(1)];
        same('fanboy r14 state: the setup\'s fan — alike, and the hub: mixed, the table one fan\'s, floor and start the control uses, may not stand',
            [null, ['mixed' => true, 'one_fan_table' => true, 'ok' => null, 'not_safe' => null, 'extra' => 0, 'floor' => 25, 'start' => 50], false, 'mixed_hub'],
            [$s['alike'], $s['hub'], $s['may_stand'], $s['off_why']]);
    } finally {
        fanboyTEnd();
    }
}

/** Round 14: a look run on the tests' clock — the user's eye answers from what the silent fan does (stalls under 30 %, starts at 45 %) */
function fanboyR14Look(array $t, array &$spec, ?callable $each = null): array
{
    $silent = true;             // it turns at the BIOS's 128 before
    $answered = [];
    $clock = 0.0;
    $io = fanboyTIo($t, $spec, $clock);
    $sleep = $io['sleep'];
    $io['sleep'] = function (float $s) use ($sleep, $t, &$silent, &$answered, &$clock, $each): void {
        $sleep($s);
        $d = fanboyTOut($t, 1)[0] / 255;
        $silent = $silent ? $d >= 0.30 : $d >= 0.45;
        $p = fanboyStepRead()['progress'] ?? null;
        $phase = is_array($p) ? ($p['phase'] ?? '') : '';
        if (in_array($phase, ['hub_turn', 'hub_start'], true) && !isset($answered[$phase]) && fanboyTOut($t, 1)[0] === (int) round($p['pct'] * 2.55)) {
            $answered[$phase] = $clock;
            fanboyStepNext(['fan' => fanboyTId(1), 'heard' => $silent && fanboyReadInt("{$t['hw']}/fan1_input") > 100]);
        }
        if ($each) {
            $each($clock, $phase);
        }
    };
    return fanboyStepRun(['id' => 'r14' . mt_rand(), 'kind' => 'hub_look', 'fans' => [fanboyTId(1)]], $io);
}

/**
 * Round 14: the look for the eye on the fake hub — at 25 % the silent fan stands («no»: the floor +10); at 35 % all turn,
 * from standing at 45 % all start again (hub_ok: «darf stehen» may take effect); with a start of 40 % it doesn't (never
 * stand); too warm → back at once; afterwards exactly as before
 */
function testFanboyR14Look(): void
{
    $t = fanboyT('r14look');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        fanboyR14Store(['off_ok' => true]);
        $step = fanboyR14Look($t, $spec);
        same('fanboy r14 look: at its floor (25 %) the silent fan stands — «no»: the floor 10 % higher next time, nothing else; back as before',
            ['done', 'floor_up', 25, 10, false, [128, 1], []], [$step['status'], $step['results'][fanboyTId(1)]['result'] ?? null, $step['results'][fanboyTId(1)]['floor'] ?? null,
                fanboyBoardRaw()['fans'][fanboyTId(1)]['hub_extra'] ?? null, isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['hub_ok']), fanboyTOut($t, 1), fanboyHeld()]);
        $phases = [];
        $step = fanboyR14Look($t, $spec, function (float $c, string $ph) use (&$phases): void {
            if ($ph !== '' && end($phases) !== $ph) {
                $phases[] = $ph;
            }
        });
        $f = fanboyBoardRaw()['fans'][fanboyTId(1)];
        same('fanboy r14 look: again — at 35 % all turn («yes»), 10 s at 0, at 50 % all start again («yes»): hub_ok; the phases; back as before, turning',
            ['ok', [35, 50], true, ['hub_turn', 'hub_stop', 'hub_start', 'pause'], [128, 1], true],
            [$step['results'][fanboyTId(1)]['result'] ?? null, [$step['results'][fanboyTId(1)]['floor'] ?? null, $step['results'][fanboyTId(1)]['start'] ?? null],
                is_int($f['hub_ok']['at'] ?? null), $phases, fanboyTOut($t, 1), fanboyReadInt("{$t['hw']}/fan1_input") > 300]);
        same('fanboy r14 look: … now «darf stehen» takes effect (the measured fan stops and starts safely, the margin stays)', ['floor' => 35, 'start' => 50, 'off_ok' => true, 'off_why' => null],
            array_intersect_key(fanboyFanPlan($f), array_flip(['floor', 'start', 'off_ok', 'off_why'])));
        // a start from standstill of 25 % measured: the look tries 40 % (+ 5 + 10) — the silent fan doesn't start: never stand
        fanboyR14Store(['off_ok' => true, 'hub_extra' => 10], 25);
        $step = fanboyR14Look($t, $spec);
        same('fanboy r14 look: started again at 40 % the silent fan stays — «no»: never stand (hub_not_safe)', ['not_safe', true, 'hub_not_safe'],
            [$step['results'][fanboyTId(1)]['result'] ?? null, is_int(fanboyBoardRaw()['fans'][fanboyTId(1)]['hub_not_safe']['at'] ?? null), fanboyFanPlan(fanboyBoardRaw()['fans'][fanboyTId(1)])['off_why']]);
        // too warm while it looks: full at once, back, nothing stored
        fanboyR14Store(['off_ok' => true]);
        $cpu = "{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input";
        $hot = false;
        $step = fanboyR14Look($t, $spec, function (float $c, string $ph) use ($cpu, &$hot): void {
            if (!$hot && $ph === 'hub_turn') {
                $hot = true;
                file_put_contents($cpu, "90000\n");
            }
        });
        file_put_contents($cpu, "28000\n");
        same('fanboy r14 look: too warm (CPU 90 °C) — stopped (warm), back as before, nothing stored', ['stopped', 'warm', [128, 1], [], false],
            [$step['status'], $step['why'], fanboyTOut($t, 1), fanboyHeld(), isset(fanboyBoardRaw()['fans'][fanboyTId(1)]['hub_extra'])]);
        // refused: one fan on it; a pump
        fanboyStoreUpdate(function (array $e): array {
            $e['fans'][fanboyTId(4)]['role'] = 'pump';
            $e['fans'][fanboyTId(4)]['count'] = 2;
            return $e;
        });
        same('fanboy r14 look: refused for a connector with one fan, and for a pump', ['fanboy_hub_one', 'fanboy_not_his'],
            [fanboyR6Refused(fn () => fanboyStepStart('hub_look', [fanboyTId(5)])), fanboyR6Refused(fn () => fanboyStepStart('hub_look', [fanboyTId(4)]))]);
    } finally {
        fanboyTEnd();
    }
}
/**
 * For the page worker (he mirrored the floor on the page): the setup's fan carries the numbers the control uses — its
 * floor, its start from standing, the curve as applied (equal to the control's status) and the fallback (planned before
 * a take, the journal's after it); a fan in no zone: its floor, no curve
 */
function testFanboySetupFanPlan(): void
{
    $t = fanboyT('setupplan');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 40);
        $c = fanboyR12CurveControl($t, $spec);
        $fans = fn () => array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id');
        $f = $fans()[fanboyTId(2)];
        same('fanboy setup plan: the CPU cooler — floor 15 % (measured 10 + 5, as every fan of his), start 40 % (35 + 5), the curve as the control applies it', [15, 40, true],
            [$f['floor'], $f['start'], $f['curve'] == fanboyFanCurve($c['zones']['cpu'], $c['fans'][fanboyTId(2)])]);
        same('fanboy setup plan: … before a take — the fallback planned: the chip\'s automatic for a CPU cooler of his, seconds while it turns and while it stands',
            ['planned', 5, 35, 15, 90, 30], [$f['fallback']['from'] ?? null, $f['fallback']['within']['control_dead'] ?? null, $f['fallback']['within']['control_hung'] ?? null,
                $f['fallback']['within_standing']['control_hung'] ?? null, $f['fallback']['within']['guard_only'] ?? null, $f['fallback']['within_standing']['guard_only'] ?? null]);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);
        $f = $fans()[fanboyTId(2)];
        $st = fanboyStatus($c)['zones'][0]['fans'][0];
        same('fanboy setup plan: … after the take — from the journal, the same as the control\'s status says', ['journal', $st['fallback']['to'], $st['curve']],
            [$f['fallback']['from'] ?? null, $f['fallback']['to'] ?? null, $f['curve']]);
        $other = $fans()[fanboyTId(4)];
        same('fanboy setup plan: a fan of his in no zone, not measured — its floor (40 %, the one for a fan not measured), no start, no curve', [FANBOY_BLIND_FLOOR, null, null],
            [$other['floor'], $other['start'], $other['curve']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}

/**
 * The Dashboard tile's span (2026-11-16, the owner: «durchklicken»): fanboy.dashboard {show?, hours?} — each alone, hours
 * one of 1 · 3 · 6 · 12 · 24 · 72, kept with the tile's setting and its mirror; the tile draws its curves for that span
 * and its label is a button to the next span (after 72 back to 1), its tip saying so
 */
function testFanboyDashHours(): void
{
    $t = fanboyT('dashhours');
    try {
        fanboyDashSet(['show' => 'history']);
        $a = fanboyDashSet(['hours' => 24]);
        same('fanboy dash hours: hours alone — kept, the setting «with history» stays, the mirror carries both', [24, 'history', ['v' => 1, 'show' => 'history', 'hours' => 24]],
            [$a['hours'], fanboyDashRead(), (function () { $m = json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true); return is_array($m) ? ['v' => $m['v'], 'show' => in_array('fanboy', staffHired(), true) ? $m['show'] : 'history', 'hours' => $m['hours']] : null; })()]);
        fanboyDashSet(['show' => 'speeds']);
        same('fanboy dash hours: show alone — the span stays', [24, 'speeds'], [fanboyDashHours(), fanboyDashRead()]);
        $bad = [];
        foreach ([['hours' => 5], ['hours' => '6'], ['hours' => 6.0], ['hours' => 0], ['hours' => 100], ['hours' => null], ['hours' => 6, 'show' => 'loud']] as $r) {
            $bad[] = fanboyR6Refused(fn () => fanboyDashSet($r));
        }
        same('fanboy dash hours: refused — 5, a string, a float, 0, 100, null; a good span with a bad show', array_fill(0, 7, 'bad_request'), $bad);
        same('fanboy dash hours: … nothing changed', [24, 'speeds'], [fanboyDashHours(), fanboyDashRead()]);
        file_put_contents(fanboyDashFile(), '{"v":1,"show":"history","hours":7}');
        same('fanboy dash hours: an odd value stored reads as 6', 6, fanboyDashHours());
    } finally {
        fanboyTEnd();
    }
    // the tile per span: points 30 min, 2 h, 5 h, 10 h, 20 h and 48 h old — each span shows the ones inside it
    $now = 1763100020;
    $state = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy.json'), true);
    $board = $state['setup']['board'];
    $pts = array_map(fn ($ago) => [$now - $ago, 40, 50, 800], [1800, 7200, 18000, 36000, 72000, 172800]);
    $seen = [];
    foreach ([1 => 3, 3 => 6, 6 => 12, 12 => 24, 24 => 72, 72 => 1] as $hours => $next) {
        $files = fanboyDashFiles('fanboy', 'history');
        $files['fanboy/dashboard.json'] = ['v' => 1, 'show' => 'history', 'hours' => $hours];
        $o = fanboyDashWeb($files, ['fanboy/history.json' => ['v' => 1, 'board' => $board, 'zones' => ['disks' => $pts]]], $now);
        $spans = $o['hist']['disks'] ?? [];
        $rows = (string) ($o['rows'] ?? '');
        $seen[$hours] = [count(array_filter($spans, fn ($p) => $p !== [null, null, null, null])),
            str_contains($rows, 'class="sso-fb-span" data-hours="' . $next . '" title="Change the span — next: last ' . $next . ' h">Last ' . $hours . ' h</button>')];
    }
    same('fanboy dash hours: the tile per span — the spans with points inside it (1 h: 1 … 24 h: 5; 72 h: 5, its spans are 3 h and the 30-min and 2-h points share one), the label a button to the next span (72 → 1) with its tip',
        [1 => [1, true], 3 => [2, true], 6 => [3, true], 12 => [4, true], 24 => [5, true], 72 => [5, true]], $seen);
    $tile = (string) ($o['tile'] ?? '');
    check('fanboy dash hours: a click posts fanboy.dashboard {hours} with Unraid\'s csrf token and X-Office, never toggles the zone, then the rows anew',
        str_contains($tile, 'closest("#sso-fb-dash .sso-fb-span")') && str_contains($tile, 'e.preventDefault();e.stopPropagation();') && str_contains($tile, 'hd["X-CSRF-Token"]=csrf_token')
        && str_contains($tile, '"X-Office":"1"') && str_contains($tile, 'a:"fanboy.dashboard",hours:parseInt(t.getAttribute("data-hours"),10)') && str_contains($tile, '.then(function(){load();})'));
    check('fanboy dash hours: the curve at the tile\'s width — the row a flex row of its own, the curve a full-width line under it, the label styled as a link',
        str_contains($tile, '.sso-fb-zone summary{display:block;') && str_contains($tile, '.sso-fb-row{display:flex;') && str_contains($tile, '.sso-fb-graph{display:flex;flex-direction:column;gap:2px;width:100%;')
        && str_contains($tile, '.sso-fb-span{background:none;border:0;') && str_contains($tile, 'cursor:pointer'));
}

/**
 * The page's sentence audit (2026-11-16): every number a sentence states comes from the state — `setup.times`, `limits`,
 * `restarts` — each tied here to its constant; the demo states give the same; the refusals and notifications carry
 * their numbers as params, and the texts that said what the code doesn't do say what it does
 */
function testFanboyTimes(): void
{
    $t = fanboyT('times');
    try {
        $s = fanboySetupState(fanboyDetect(), fanboyBoardRaw());
        $want = ['clear_after' => FANBOY_CLEAR_AFTER, 'up_hold' => FANBOY_UP_HOLD, 'up_hold_quiet' => FANBOY_UP_HOLD_QUIET, 'measure_max' => fanboyMeasureMaxSecs(), 'measure_usual' => fanboyMeasureUsualSecs(), 'up_min' => FANBOY_UP_MIN, 'show_for' => FANBOY_SHOW_FOR, 'lease' => FANBOY_STEP_LEASE,
            'tell_after' => FANBOY_TELL_AFTER, 'idle_warm_after' => FANBOY_IDLE_WARM_AFTER, 'stand_after' => FANBOY_STAND_AFTER, 'history_every' => FANBOY_HISTORY_EVERY,
            'history_periods' => FANBOY_HISTORY_PERIODS, 'poll_live' => FANBOY_POLL_LIVE, 'crash_pause' => FANBOY_CRASH_PAUSE, 'crash_window' => FANBOY_CRASH_WINDOW,
            'crash_max' => FANBOY_CRASH_MAX, 'net' => ['warm_pct' => FANBOY_NET_WARM_PCT, 'hot_pct' => FANBOY_NET_HOT_PCT, 'gap' => FANBOY_NET_GAP, 'clear' => FANBOY_NET_CLEAR]];
        check('fanboy times: setup.times — each from its constant', $s['times'] == $want, json_encode($s['times']));
        same('fanboy times: setup.limits — the name\'s and a connector\'s maximum, zones and sources at most',
            ['name_max' => FANBOY_NAME_MAX, 'count_max' => FANBOY_COUNT_MAX, 'zones_max' => FANBOY_ZONES_MAX, 'sources_max' => FANBOY_SOURCES_MAX], $s['limits']);
        $demo = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy.json'), true)['setup'] ?? [];
        check('fanboy times: … the demo states give the same numbers (the page\'s pictures)', ($demo['times'] ?? null) == $want && ($demo['limits'] ?? null) == $s['limits'], json_encode($demo['times'] ?? null));
        $was = $GLOBALS['fanboyCrashes'] ?? null;
        $now = fanboyMono();
        $GLOBALS['fanboyCrashes'] = [$now - 60, $now - 30];
        $two = fanboySetupState(fanboyDetect(), fanboyBoardRaw())['restarts'];
        $GLOBALS['fanboyCrashes'][] = $now - 5;
        $three = fanboySetupState(fanboyDetect(), fanboyBoardRaw())['restarts'];
        $GLOBALS['fanboyCrashes'] = [$now - FANBOY_CRASH_WINDOW - 10, $now - FANBOY_CRASH_WINDOW - 5, $now - FANBOY_CRASH_WINDOW - 1];
        $old = fanboySetupState(fanboyDetect(), fanboyBoardRaw())['restarts'];
        $GLOBALS['fanboyCrashes'] = $was;
        same('fanboy times: setup.restarts — two deaths in the window: started again; the third: given up (not by itself any more); deaths outside the window don\'t count',
            [[2, false], [3, true], [0, false]], [[$two['deaths'], $two['given_up']], [$three['deaths'], $three['given_up']], [$old['deaths'], $old['given_up']]]);
        same('fanboy times: the refusals carry their numbers — a name too long ({max}), too many fans on a connector ({max})',
            ['fanboy_bad_name', 'fanboy_bad_count'], [fanboyR6Refused(fn () => fanboyFanName(['fan' => fanboyTId(1), 'name' => str_repeat('a', FANBOY_NAME_MAX + 1)])),
                fanboyR6Refused(fn () => fanboyRole(['fan' => fanboyTId(5), 'role' => 'case', 'count' => FANBOY_COUNT_MAX + 1]))]);
        try {
            fanboyFanName(['fan' => fanboyTId(1), 'name' => str_repeat('a', FANBOY_NAME_MAX + 1)]);
        } catch (Problem $p) {
            same('fanboy times: … the name\'s maximum as a param', ['max' => FANBOY_NAME_MAX], $p->params);
        }
    } finally {
        fanboyTEnd();
    }
    // the words: each number a placeholder fed from its constant, in all five languages
    $src = '';
    foreach (glob(OFFICE_DIR . '/agent/desks/fanboy*.php') ?: [] as $f) {
        $src .= (string) file_get_contents($f);
    }
    check('fanboy times: the alarms, the chip missing, the watch, the net, the crash and the check pass their numbers from their constants',
        str_contains($src, "\$what + ['min' => (int) round(FANBOY_CLEAR_AFTER / 60)]") && str_contains($src, "['min' => (int) round(FANBOY_TELL_AFTER / 60)]")
        && str_contains($src, "'min' => (int) round(FANBOY_IDLE_WARM_AFTER / 60)") && str_contains($src, "'pct' => FANBOY_NET_WARM_PCT")
        && str_contains($src, "['pause' => (int) FANBOY_CRASH_PAUSE, 'max' => FANBOY_CRASH_MAX, 'window' => (int) round(FANBOY_CRASH_WINDOW / 60)]")
        && str_contains($src, "finding('fanboy_alarm', 'recommended', \$running ? !\$alarm : null, ['min' => (int) round(FANBOY_CLEAR_AFTER / 60)]"));
    $holes = [];
    $need = ['alarm.stands' => '{min}', 'alarm.stands_alone' => '{min}', 'alarm.sensor' => '{min}', 'notify.alarm_stands' => '{min}', 'notify.alarm_stands_alone' => '{min}', 'notify.alarm_sensor' => '{min}', 'notify.missing' => '{min}', 'notify.missing_stuck' => '{min}',
        'notify.idle_stands' => '{min}', 'notify.net_warm' => '{pct}', 'notify.crashed' => '{window}', 'check.fanboy_alarm_how' => '{min}', 'errors.fanboy_bad_name' => '{max}', 'errors.fanboy_bad_count' => '{max}'];
    foreach (['en', 'de', 'it', 'fr', 'es'] as $code) {
        $l = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$code.json"), true);
        foreach ($need as $k => $ph) {
            if (!str_contains((string) ($l[$k] ?? ''), $ph) || preg_match('/\b(15|2|60|40|8)\s?(min|Min|%|Zeichen|characters)/u', (string) ($l[$k] ?? ''))) {
                $holes[] = "$code $k";
            }
        }
    }
    same('fanboy times: … and the words take them (no number written in)', [], $holes);
    // 2026-11-16 (the owner): the alarm sentences «… bis er wieder dreht, und danach noch {min} Minuten» — the page's banner and
    // the Dashboard tile take the minutes from the state's times.clear_after (an older agent: their mirrors, equal to the constant)
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $dash = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    check('fanboy times: the alarm banner on his page and on the Dashboard tile name the minutes from the state\'s times.clear_after',
        str_contains($js, "min: Math.round(timesNow().clear_after / 60) }), { alarm: c.alarm.code });")
        && str_contains($dash, "(\$setup['times']['clear_after'] ?? null) : null) ?? FANBOY_DASH_CLEAR_AFTER;")
        && str_contains($dash, "\$params['min'] = (string) (int) round(\$clear / 60);")
        && str_contains($dash, 'const FANBOY_DASH_CLEAR_AFTER = ' . (int) FANBOY_CLEAR_AFTER . ';'));
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/en.json'), true);
    check('fanboy times: the texts say what the code does — no «gave it back to the BIOS» (it gives back as set: the state before or the automatic), no «a restart gives them to the BIOS» as a fact, no 100 % for an alarm in general, no «never writes» for an output a quiet test stops',
        !str_contains($en['notify.foreign'], 'back to the BIOS') && !str_contains($en['notify.refuses'], 'back to the BIOS') && !str_contains($en['notify.crashed'], 'as it was before him')
        && str_contains($en['notify.alarm_chip'], 'not checked') && str_contains($en['notify.not_back'], 'not checked') && !str_contains($en['errors.fanboy_show_alarm'], '100 %')
        && !str_contains($en['errors.fanboy_show_not'], 'never writes') && str_contains($en['check.fanboy_alarm_how'], 'Fanboy only watches it'));
}

/** The owner's HDD cage: six array disks and his pool's two SSDs («mother», «mother2»), the SSDs as Unraid reports them */
function fanboySsdDisks(array $t, string $down, string $temp): void
{
    $d = fn (string $name, string $type, string $dev, string $c, string $sd, string $rot) => "[$name]\nname=\"$name\"\ntype=\"$type\"\ndevice=\"$dev\"\nid=\"EXAMPLE_" . strtoupper($name) . "\"\ntemp=\"$c\"\nspundown=\"$sd\"\nrotational=\"$rot\"\nspindownDelay=\"-1\"\nstatus=\"DISK_OK\"\n";
    $ini = $d('parity', 'Parity', 'sdb', '33', '0', '1');
    foreach ([1, 2, 3, 4, 5] as $i) {
        $ini .= $d("disk$i", 'Data', 'sd' . chr(99 + $i), '33', '0', '1');
    }
    $ini .= $d('mother', 'Cache', 'sdi', $temp, $down, '0') . $d('mother2', 'Cache', 'sdj', $temp, $down, '0');
    file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $ini);
}

/**
 * 2026-11-16 (the owner's first night: «alarm_sensor (source mother, zone HDD-Käfig)» twice, the zone at 100 % for ten minutes
 * each): an SSD Unraid has put into standby (spundown 1, no temperature) is asleep for Fanboy — no demand, no alarm, over an
 * hour; the same SSD awake without a temperature is a sensor that says nothing (the alarm as before); an NVMe from hwmon as
 * before; the office's own diskAsleep() unchanged
 */
function testFanboySsdSleeps(): void
{
    $t = fanboyT('ssdsleep');
    try {
        $spec = fanboyR12Spec();
        fanboySsdDisks($t, '1', '*');
        $s = fanboyNetSettings();
        $s['zones'][1]['sources'][] = ['kind' => 'ssd', 'id' => 'mother'];
        $s['zones'][1]['sources'][] = ['kind' => 'ssd', 'id' => 'mother2'];
        fanboyTStore($s);
        fanboyTSettle($t, $spec);
        $by = array_column(fanboyDisks(fanboyPaths()['disks']), null, 'name');
        same('fanboy ssd: Unraid\'s standby for an SSD (spundown 1, temp «*») — asleep for Fanboy, no temperature; the office\'s diskAsleep() still says awake (wake-ups: unchanged)',
            [true, null, false], [$by['mother']['asleep'], $by['mother']['temp'], diskAsleep(['spundown' => '1', 'rotational' => '0'])]);
        $src = array_column(fanboySources(['disks' => array_values($by), 'chips' => []]), null, 'id');
        same('fanboy ssd: … the setup\'s source list says it sleeps', [true, true], [$src['mother']['asleep'], $src['mother2']['asleep']]);
        $c = fanboyControlNew($s, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 1800);
        same('fanboy ssd: an hour with both SSDs in standby — no alarm, the zone not at full, both «asleep»', [null, false, 'asleep', 'asleep'],
            [$c['alarm'], isset($c['full_since']['hdd']), $c['zones']['hdd']['state']['mother'] ?? null, $c['zones']['hdd']['state']['mother2'] ?? null]);
        // awake (spundown 0) without a temperature: a sensor that says nothing — the alarm as before, after its wait
        fanboySsdDisks($t, '0', '*');
        fanboyTRounds($c, $now, $t, $spec, (int) ceil((FANBOY_DISK_SENSOR_AFTER + 30) / FANBOY_ROUND));
        same('fanboy ssd: the same SSD awake without a temperature — a silent sensor: the «sensor» alarm, the zone at full', ['sensor', true],
            [$c['alarm']['code'] ?? null, isset($c['full_since']['hdd'])]);
        fanboyLetGo($c, 'end');
        // a rotating disk spun down: asleep as before; an NVMe from hwmon: never «asleep» (its own sensor)
        $hdd = fanboyDiskSleeps(['spundown' => '1', 'rotational' => '1', 'temp' => '*']);
        $nvme = array_values(array_filter(fanboySources(fanboyDetect()), fn ($x) => $x['kind'] === 'nvme'));
        same('fanboy ssd: a rotating disk spun down — asleep as before; the NVMe sensors (hwmon) never asleep', [true, true, []],
            [$hdd, $nvme !== [], array_values(array_filter($nvme, fn ($x) => $x['asleep']))]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * 2026-11-16 (the owner: «nein, gemäss eingestellter Kurve»): a CPU cooler of his has no fixed 40 % — his curve (0 % up to
 * 62 °C, 40 % at 77, 100 % at 81) with a cooler measured down to 10 %: it stands below 62, turns with its measured floor
 * (15 %) until the curve asks more, then the curve; a rising sweep writes exactly the status's curve, never under 15 % while it turns
 */
function testFanboyCpuFloorCurve(): void
{
    $t = fanboyT('cpufloorcurve');
    try {
        $spec = fanboyR12Spec();
        fanboyTDisks($t, 30, null, 30);
        fanboyR12CpuTemp($t, 40);
        $c = fanboyR12CurveControl($t, $spec, [[30, 0], [62, 0], [77, 40], [81, 100]]);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 32);
        $eff = fanboyStatus($c)['zones'][0]['fans'][0]['curve'] ?? [];
        check('fanboy cpu floor: the status — 0 % up to 62 °C, then its measured floor 15 % until the curve passes it (67.63 °C), 40 % at 77, 100 % at 81',
            ['cpu', 15, [[30, 0], [62, 0], [62, 15], [67.63, 15], [77, 40], [81, 100]]] == [$eff['kind'] ?? null, $c['fans'][fanboyTId(2)]['floor'], $eff['points'] ?? null], json_encode($eff));
        $off = [];
        $low = [];
        foreach ([50, 58, 61, 63, 65, 67, 69, 72, 75, 77, 79, 81, 84] as $deg) {
            fanboyR12CpuTemp($t, $deg);
            fanboyTRounds($c, $now, $t, $spec, 20);        // the smoothing, a start's kick and Langsam's 4 % a round passed
            $v = fanboyTOut($t, 2)[0];
            $want = (int) round(fanboyCurveAt($eff['points'], (float) $deg) * 2.55);
            if (abs($v - $want) > 2) {
                $off[] = "$deg °C: wrote $v, the status says $want";
            }
            if ($v > 0 && $v < (int) round(15 * 2.55)) {
                $low[] = "$deg °C: $v";
            }
        }
        same('fanboy cpu floor: a rising sweep 50…84 °C — what the control writes is the status\'s curve (± rounding)', [], $off);
        same('fanboy cpu floor: … never under its measured floor (15 %) while it turns', [], $low);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyR12CpuTemp($t, 28);
        fanboyTEnd();
    }
}
