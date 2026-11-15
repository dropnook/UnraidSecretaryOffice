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
            'fanboy/show.json' => '{}', 'fanboy/dashboard.json' => ['v' => 1, 'show' => 'speeds']], [], $wall);
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
        same('fanboy r6 show: 100 % (fixed) while it is shown, «running» meanwhile, up to 15 s (4b)', [[255, 1], 'running', true],
            [$seen[0], $seen[1], abs(count($seen) / 2 - 75) <= 1]);
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
        file_put_contents(fanboyStatusFile(), json_encode(['alarm' => ['code' => 'hot'], 'refuses' => [fanboyTId(2)]]));
        same('fanboy r6 show refused: during an alarm of the control', 'fanboy_show_alarm', $show(4));
        file_put_contents(fanboyStatusFile(), json_encode(['alarm' => null, 'refuses' => [fanboyTId(2)]]));
        same('fanboy r6 show refused: an output whose driver takes no values (the control\'s «refuses»)', 'fanboy_show_not', $show(2));
        @unlink(TESTS_RUN_DIR . '/fanboy/control.pid');
        @mkdir("{$t['root']}/boot/config/plugins/fanctrlplus", 0700, true);
        file_put_contents("{$t['root']}/boot/config/plugins/fanctrlplus/fan1.cfg", "service=\"1\"\n");
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
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy r6 show control: … still at 100 % after 12 s (up to 15 s, 4b), the others untouched', [[255, 1], 'running', $o0],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], $others()]);
        fanboyTRounds($c, $now, $t, $spec, 2);
        same('fanboy r6 show control: … after 15 s its curve\'s value again, the show done, noted', [$before, 'done', 'show'],
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
        // the measuring's «too warm» counts it too: 45 °C + 3 is within 5 °C of the hard disks' 52
        fanboyTDisks($t, 45, null, 30);
        same('fanboy r6 old disks: measuring stops «warm» by the value with the allowance (Parity 48 °C at 1800 s)', 48.0, fanboyStepWarm()['c'] ?? null);
        fanboyR6Poll($t, 60);
        same('fanboy r6 old disks: … not at 60 s (45 °C is under 47)', null, fanboyStepWarm());
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
