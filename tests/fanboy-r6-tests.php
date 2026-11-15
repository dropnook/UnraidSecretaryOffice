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
        file_put_contents(fanboyStatusFile(), json_encode(['alarm' => ['code' => 'hot'], 'refuses' => [fanboyTId(2)]]));
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
        $o = fanboyDashWeb(['fanboy.json' => $state, 'office/staff.json' => ['hired' => ['fanboy' => 1]], 'fanboy/show.json' => '{}', 'fanboy/dashboard.json' => ['v' => 1, 'show' => 'speeds']], [], time());
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
    $test = substr($js, (int) strpos($js, 'function testCard('), 6000);       // round 8: the test's card (with its assignment)
    check('fanboy r7 cards: the state line is one element of its own with room for two lines',
        str_contains($test, "el('div', 'fb-card-line fb-card-state', line)") && str_contains($css, '.fb-card-state{min-height:calc(2 * 1.45em)}'));
    check('fanboy r7 cards: while a test runs every card keeps its button row — the button off (no card loses or gains a row)',
        str_contains($test, "} else if (live) {\n    const off = button(found ? T('test.again') : T('test.one'), T('test.wait_tip'), () => {});\n    off.disabled = true;\n    txt.appendChild(bar(off));")
        && str_contains($test, 'txt.appendChild(assignBox(id, f, live));') && str_contains((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.css'), '.fb-assign-note{min-height:calc(2 * 1.45em)}'));
    check('fanboy r7 cards: «show me» keeps its width — both labels in one grid cell, the other hidden',
        str_contains($js, "b.append(el('span', mine ? 'fb-show-label fb-show-off' : 'fb-show-label', T('show.button')), on);")
        && str_contains($css, '.fb-show{white-space:nowrap; display:inline-grid}') && str_contains($css, '.fb-show > .fb-show-off{visibility:hidden}'));
}

/**
 * F5: after F1, a full test of the six outputs on the it87 «full on» chip — an output where no tach follows stays at
 * full for FANBOY_UP_FOR (8 s: long enough to hear which fan it is), one with a tach at least 4 s; how long one output and
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
            . ' — at least FANBOY_UP_FOR (' . FANBOY_UP_FOR . ' s) less a look', count($quiet) === 3 && min($loud) >= FANBOY_UP_FOR - 0.5, json_encode($loud));
        $tach = array_map(fn ($id) => $atFull[(int) substr($id, -1)] ?? 0, array_keys(array_filter($found, fn ($f) => $f === 'tach')));
        check('fanboy r7 long enough: outputs a tach follows (' . count($tach) . ') at full ' . implode(', ', array_map(fn ($x) => round($x, 1) . ' s', $tach))
            . ' (≥ 8 s since round 8\'s G2); the six outputs took ' . round($clock) . ' s on the tests\' clock (one silent output ≈ ' . round(FANBOY_UP_BASE + FANBOY_UP_FOR) . ' s + its calm-down)',
            count($tach) === 3 && min($tach) >= FANBOY_UP_FOR - 0.5 && $clock < 6 * (FANBOY_UP_BASE + FANBOY_UP_FOR + FANBOY_UP_SETTLE));
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

/** G2: also with a tach following at once, the output stays at full for FANBOY_UP_FOR (a lagging fan is up and heard) */
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
        same('fanboy r8 long: tach 1 follows — still at full the whole ' . FANBOY_UP_FOR . ' s (here ' . $full . ' s), found, given back', [true, 'tach', [128, 1]],
            [$full >= FANBOY_UP_FOR - 0.5, $step['results'][fanboyTId(1)]['found'], fanboyTOut($t, 1)]);
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
                count($quiet1) * 0.5 >= FANBOY_UP_FOR - 0.5, (bool) array_filter($log, fn ($l) => $l[1][3] === 255 && $l[1][0] === 0 && $l[1][4] === 0)]);
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
        // a CPU cooler (its role, once known) is never stopped; an output that doesn't take 0 goes on
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $settings['fans'][fanboyTId(4)]['role'] = 'cpu';
        fanboyTStore($settings);
        $spec['revert'] = [5 => 1];
        $low = [];
        [$step] = fanboyR8Run($t, $spec, [1], true, function () use ($t, &$low): void {
            $low[] = [fanboyTOut($t, 4)[0], fanboyTOut($t, 2)[0]];
        });
        same('fanboy r8 guards: the CPU cooler (role) runs on as it is the whole time; the others stand; the test done', [true, true, 'done'],
            [!in_array(0, array_column($low, 0), true), in_array(0, array_column($low, 1), true), $step['status']]);
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
                file_put_contents(fanboyRunDir() . '/beat-' . getmypid(), (string) (hrtime(true) - 60e9));      // its beat 60 s old
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
    $card = substr($js, (int) strpos($js, 'function testCard('), 12000);
    $reach = ["act('role', { fan: id, role: v,", "act('answer', { fan: id, louder: false }", "act('role', { fan: id, role: f.role, count:", "act('role', { fan: id, role: f.role, place:",
        "act('fan_look', v.id ?", "act('fan_look', { fan: id, colour:", 'more.appendChild(backChoice(f, b));', 'more.appendChild(cpuPart(f));', "Office.api.post(`\${ID}.fan_name`", 'showButton(id, f)'];
    same('fanboy r8 assign: every action of the old roles step reachable from the test\'s card (role, nothing attached, count, place, model, colour, give back, CPU on own risk, name, show me)',
        [], array_values(array_filter($reach, fn ($x) => !str_contains($card, $x))));
    same('fanboy r8 assign: six steps — the old route …/setup/roles opens «Testen und zuordnen»; no roles step left', [true, false, false],
        [str_contains($js, "if (rest === 'roles') rest = 'test';"), str_contains($js, 'function rolesStep('), str_contains($js, "return 'roles'")]);
    foreach (['de' => '6', 'en' => '6', 'it' => '6', 'fr' => '6', 'es' => '6'] as $l => $n) {
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
        check('fanboy r8 assign: output 4 called the CPU cooler during the run — the next quiet phase (output 5) leaves it running', $low4 && !in_array(0, $low4, true));
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
        str_contains($js, "box.appendChild(nowLine());\n  stepEnded(box, s, 'test_up');") && str_contains($js, "s.appendChild(nowLine());")
        && !array_filter(['now.full', 'now.full_quiet', 'now.pause', 'now.kick', 'now.next', 'now.restore', 'now.safety', 'now.nothing'], fn ($k) => !str_contains($js, "'$k'")));
    check('fanboy r9 now: the card and row at full framed like the office\'s active tiles; «Alles stoppen» beside the line while anything runs',
        str_contains($css, '.fb-card-now, .fb-row-now{border-color:var(--accent); background:var(--accent-soft);') && str_contains($js, "act('stop_all', {}, b,"));
    check('fanboy r9 now: a click that can\'t go through says why (busy, the messenger away) — never silence',
        str_contains($js, "if (busy) { Office.toast(T('busy_wait'), true); return null; }") && str_contains($js, "if (!Office.agent.running) { Office.toast(t('errors.agent_away'), true); return null; }"));
    check('fanboy r9 fields: the cards\' selects and fields in the office\'s field look (input small), full width there',
        substr_count($js, "el('select', 'input small')") >= 4 && str_contains($js, "n = el('input', 'input small');") && str_contains($css, '.fb-assign .input{width:100%; flex:none}'));
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
        str_contains($js, "note = f.cpu_own ? T('assign.cpu_own', { date: Office.fmt.date(f.cpu_own) }) : T('assign.cpu_bios');") && str_contains($js, 'cpuAsk.delete(id);'));
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
    $his = ['alarm_stands', 'alarm_stands_alone', 'alarm_bios_stands', 'alarm_hot', 'alarm_sensor', 'alarm_chip', 'idle_stands', 'quiet_no_restart', 'not_back',
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

    // hidden means hidden: no row, no setting — the switch decides (the same rule as fanboyShown())
    $tmp = hardeningTmp('fanboy-r10nc');
    foreach (['off', 'on', 'link'] as $k) {
        @mkdir("$tmp/$k/fanboy", 0700, true);
    }
    file_put_contents("$tmp/on/fanboy/show.json", '');
    file_put_contents("$tmp/elsewhere.json", '');
    @symlink("$tmp/elsewhere.json", "$tmp/link/fanboy/show.json");
    same('fanboy r10 centre: the senders — without his switch (or a link) everyone but him, with it everyone',
        [array_values(array_diff(OFFICE_NOTIFY_DESKS, ['fanboy'])), OFFICE_NOTIFY_DESKS, array_values(array_diff(OFFICE_NOTIFY_DESKS, ['fanboy']))],
        [officeNotifyDesks("$tmp/off"), officeNotifyDesks("$tmp/on"), officeNotifyDesks("$tmp/link")]);
    same('fanboy r10 centre: … his desk.json unreadable: hidden (never shown by a mistake), the others there', [false, true],
        [in_array('fanboy', officeNotifyDesks("$tmp/on", "$tmp/no-web"), true), in_array('office', officeNotifyDesks("$tmp/on", "$tmp/no-web"), true)]);
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
        same('fanboy r10 centre: switch off — the page part has no row for him, nothing he sends', [false, false], [in_array('fanboy', $p['desks'], true), isset($p['sends']['fanboy'])]);
        same('fanboy r10 centre: … a setting for him refused, nothing written', ['bad_request', false], [fanboyR6Refused(fn () => officeNotifySave($req(['when' => 'summary']), $f)), is_file($f)]);
        file_put_contents(FANBOY_DIR . '/show.json', '{}');
        $p = officeNotifyPublic();
        same('fanboy r10 centre: switch on — his row, what he sends', [true, ['alert', 'warning', 'normal']], [in_array('fanboy', $p['desks'], true), $p['sends']['fanboy'] ?? null]);
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
        fanboyTell($c, 'alarm:hot:Gehäuse', 'alarm_hot', ['zone' => 'Gehäuse', 'source' => 'CPU', 'c' => 88], 'alert');
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
