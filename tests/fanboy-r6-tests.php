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
        fanboyShowMain(['now' => function () use (&$clock) { return $clock; }, 'sleep' => function (float $s) use (&$clock, &$seen, $t) {
            $clock += $s;
            $seen[] = fanboyTOut($t, 4);
            $seen[] = fanboyShowRead()['status'] ?? null;
        }]);
        same('fanboy r6 show: 100 % (fixed) while it is shown, «running» meanwhile, about 5 s', [[255, 1], 'running', 25],
            [$seen[0], $seen[1], count($seen) / 2]);
        $s = fanboyShowRead();
        $j = fanboyJournal()['outputs'][fanboyTId(4)] ?? [];
        same('fanboy r6 show: … then exactly as before — value 77, the chip\'s own automatic (2) — read back, nobody holds it, the show done', [[77, 2], 'done', true, true, 'show'],
            [fanboyTOut($t, 4), $s['status'], $s['ok'], array_key_exists('owner', $j) && $j['owner'] === null, $j['given']['why'] ?? null]);
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
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy r6 show control: the next round — 100 %, «running», the other fans of its zone as they were', [[255, 1], 'running', fanboyTOut($t, 5)],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], fanboyTOut($t, 5)]);
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy r6 show control: … after 5 s its curve\'s value again, the show done, noted', [$before, 'done', 'show'],
            [fanboyTOut($t, 4), fanboyShowRead()['status'], end($c['notes'])['code'] ?? null]);
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

/** ipmitool's `sensor` table as a Supermicro board prints it (invented values) */
const FANBOY_R6_SMC = <<<'TXT'
CPU Temp         | 41.000     | degrees C  | ok    | 0.000     | 0.000     | 0.000     | 90.000    | 95.000    | 95.000
FAN1             | 1400.000   | RPM        | ok    | 300.000   | 500.000   | 700.000   | 25300.000 | 25400.000 | 25500.000
FAN2             | 560.000    | RPM        | ok    | 300.000   | 500.000   | 700.000   | 25300.000 | 25400.000 | 25500.000
FAN3             | na         | RPM        | na    | na        | na        | na        | na        | na        | na
FANA             | 400.000    | RPM        | cr    | 300.000   | 500.000   | 700.000   | 25300.000 | 25400.000 | 25500.000
12V              | 12.192     | Volts      | ok    | 10.144    | 10.272    | 10.784    | 12.960    | 13.280    | 13.408
TXT;

/** … and as an ASRock Rack board (only LCR set, other names) */
const FANBOY_R6_ASR = <<<'TXT'
CPU_FAN1         | 900.000    | RPM        | ok    | na        | 300.000   | na        | na        | na        | na
FRNT_FAN1        | 1200.000   | RPM        | ok    | na        | 100.000   | na        | na        | na        | na
REAR_FAN1        | 330.000    | RPM        | ok    | na        | 300.000   | na        | na        | na        | na
MB Temp          | 34.000     | degrees C  | ok    | na        | na        | na        | na        | 80.000    | na
TXT;

/** A fake ipmitool in the tree: prints $out, exits $exit, sleeps $sleep first; every call (its arguments) noted */
function fanboyR6Ipmitool(array $t, string $out, int $exit = 0, int $sleep = 0): void
{
    @mkdir("{$t['root']}/usr/bin", 0700, true);
    file_put_contents("{$t['tmp']}/ipmi.out", $out . "\n");
    file_put_contents("{$t['root']}/usr/bin/ipmitool", "#!/bin/bash\necho \"\$*\" >> " . escapeshellarg("{$t['tmp']}/ipmi.calls") . "\n"
        . ($sleep ? "sleep $sleep\n" : '') . 'cat ' . escapeshellarg("{$t['tmp']}/ipmi.out") . "\nexit $exit\n");
    chmod("{$t['root']}/usr/bin/ipmitool", 0755);
}

/**
 * Part 2: the BMC's fan thresholds, read only — `ipmitool sensor` (never «thresh», never anything that sets) parsed for
 * Supermicro's and ASRock Rack's tables, «na» kept as no value, a fan within 20 % of its lower critical flagged; a tool
 * that hangs is cut off, one that fails says so; read at most once per 10 minutes, by a process of its own, never waited
 * for; the report carries it
 */
function testFanboyR6Ipmi(): void
{
    $smc = fanboyIpmiParse(FANBOY_R6_SMC);
    same('fanboy r6 ipmi: Supermicro\'s table — the fans only (RPM), «na» no value, FAN2 (560 over LCR 500) near, FANA (400) under',
        [['FAN1', 1400, 300, 500, 700, null], ['FAN2', 560, 300, 500, 700, 'near'], ['FAN3', null, null, null, null, null], ['FANA', 400, 300, 500, 700, 'below']],
        array_map(fn ($f) => [$f['name'], $f['rpm'], $f['lnr'], $f['lcr'], $f['lnc'], $f['flag']], $smc));
    same('fanboy r6 ipmi: ASRock Rack\'s table — only LCR set: REAR_FAN1 330 over 300 near, the others fine',
        [['CPU_FAN1', null], ['FRNT_FAN1', null], ['REAR_FAN1', 'near']], array_map(fn ($f) => [$f['name'], $f['flag']], fanboyIpmiParse(FANBOY_R6_ASR)));
    same('fanboy r6 ipmi: an odd table (a line with a slash in its name, too few columns) gives nothing', [], fanboyIpmiParse("a/b | 1 | RPM | ok | 1 | 1 | 1 | 1 | 1 | 1\nFAN1 | 100 | RPM\n"));
    $t = fanboyT('r6ipmi', 10, FANBOY_T_ADDR, ['ipmi' => ['manufacturer_id' => '10876', 'product_id' => '2398', 'firmware_revision' => '1.74']]);
    try {
        $scan = fanboyDetect();
        same('fanboy r6 ipmi: no ipmitool — nothing read, nothing started', [null, []], [fanboyIpmiSensors($scan, 100.0), $GLOBALS['fanboyLaunched']]);
        fanboyR6Ipmitool($t, FANBOY_R6_SMC);
        $s = fanboyIpmiSensors($scan, 100.0);
        same('fanboy r6 ipmi: never read — the reading process started, not waited for (the page: «reading»)', [['fanboy-ipmi'], null, true], [$GLOBALS['fanboyLaunched'], $s['at'], $s['reading']]);
        fanboyIpmiSensors($scan, 130.0);
        same('fanboy r6 ipmi: … asked again 30 s later: not started twice', ['fanboy-ipmi'], $GLOBALS['fanboyLaunched']);
        fanboyIpmiJob();
        fanboyIpmiJob();
        $s = fanboyIpmiSensors($scan, 200.0);
        same('fanboy r6 ipmi: the process read once (`ipmitool sensor`, nothing else) — a second run within 10 minutes reads nothing', [['sensor'], true, 4, false, ['fanboy-ipmi']],
            [file("{$t['tmp']}/ipmi.calls", FILE_IGNORE_NEW_LINES), $s['ok'], count($s['fans']), $s['reading'], $GLOBALS['fanboyLaunched']]);
        $report = fanboyReportText($scan, ['FAN1']);
        check('fanboy r6 ipmi: the report names each fan\'s speed beside its lower thresholds and the flag, no «/» in it',
            str_contains($report, '  FAN2: 560 rpm (LNR 300, LCR 500, LNC 700) - within 20 percent of its lower critical') && str_contains($report, '  FAN3: no reading (LNR na, LCR na, LNC na)')
            && !str_contains($report, '/'));
        // stale after 10 minutes: read again
        $kept = readJson(fanboyIpmiCacheFile());
        $kept['at'] -= FANBOY_IPMI_EVERY;
        file_put_contents(fanboyIpmiCacheFile(), json_encode($kept));
        fanboyIpmiSensors($scan, 300.0);
        fanboyR6Ipmitool($t, 'ipmitool: could not open device', 1);
        fanboyIpmiJob();
        same('fanboy r6 ipmi: 10 minutes later read again; a tool that fails — said («error»), nothing made up', [['fanboy-ipmi', 'fanboy-ipmi'], false, 'error', []],
            [$GLOBALS['fanboyLaunched'], readJson(fanboyIpmiCacheFile())['ok'], readJson(fanboyIpmiCacheFile())['why'], readJson(fanboyIpmiCacheFile())['fans']]);
        fanboyR6Ipmitool($t, FANBOY_R6_SMC, 0, 30);
        $t0 = hrtime(true);
        $got = fanboyIpmiRead(1);
        same('fanboy r6 ipmi: a BMC that hangs — cut off at the timeout (here 1 s), «timeout»', [false, 'timeout', true], [$got['ok'], $got['why'], (hrtime(true) - $t0) / 1e9 < 5]);
        same('fanboy r6 ipmi: the reading has the hard timeout of 10 s', 10, FANBOY_IPMI_TIMEOUT);
        // read only: the one command of the file is `ipmitool sensor` — never thresh, never anything that sets
        $src = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-ipmi.php'));
        same('fanboy r6 ipmi: one run([$tool, \'sensor\']) in the file, no argument «thresh», «raw», «set», «event», «reset», «chassis», «mc», «lan», «user» anywhere in it',
            [1, 1, 0, 0], [substr_count($src, 'run(['), substr_count($src, "run([\$tool, 'sensor']"), preg_match_all('/[\'"](thresh|raw|set|event|reset|chassis|mc|lan|user)[\'"]/', $src), substr_count($src, 'fanboyWriteFile')]);
        check('fanboy r6 ipmi: the control never reads the BMC (no ipmi in its round)', !str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php'), 'fanboyIpmi'));
    } finally {
        fanboyTEnd();
    }
}

const FANBOY_R6_GPU_A = 'GPU-1a2b3c4d-0000-4000-8000-00000000000a';
const FANBOY_R6_GPU_B = 'GPU-1a2b3c4d-0000-4000-8000-00000000000b';

/**
 * A fake nvidia-smi in the tree (and the driver «loaded»): what it does is read from <tmp>/gpu.mode each call —
 * ok (two cards), one (card B only), none («No devices were found», exit 6), na (card A without a number), hot (card A
 * at 86 °C), slow (sleeps 10 s), error (exit 9). Its calls (arguments) noted.
 */
function fanboyR6NvidiaSmi(array $t, string $mode): void
{
    @mkdir("{$t['root']}/usr/bin", 0700, true);
    @mkdir("{$t['root']}/sys/module/nvidia", 0700, true);
    file_put_contents("{$t['tmp']}/gpu.mode", $mode);
    $a = FANBOY_R6_GPU_A;
    $b = FANBOY_R6_GPU_B;
    file_put_contents("{$t['root']}/usr/bin/nvidia-smi", "#!/bin/bash\necho \"\$*\" >> " . escapeshellarg("{$t['tmp']}/gpu.calls") . "\n"
        . 'case "$(cat ' . escapeshellarg("{$t['tmp']}/gpu.mode") . ")\" in\n"
        . "  ok) echo '$a, NVIDIA GeForce RTX 3060, 45'; echo '$b, NVIDIA T400 4GB, 38' ;;\n"
        . "  one) echo '$b, NVIDIA T400 4GB, 38' ;;\n"
        . "  none) echo 'No devices were found'; exit 6 ;;\n"
        . "  na) echo '$a, NVIDIA GeForce RTX 3060, [N/A]'; echo '$b, NVIDIA T400 4GB, 38' ;;\n"
        . "  hot) echo '$a, NVIDIA GeForce RTX 3060, 86'; echo '$b, NVIDIA T400 4GB, 38' ;;\n"
        . "  slow) sleep 10 ;;\n"
        . "  *) echo 'NVIDIA-SMI has failed: no communication with the NVIDIA driver.'; exit 9 ;;\n"
        . "esac\n");
    chmod("{$t['root']}/usr/bin/nvidia-smi", 0755);
}

/** Polls until the running nvidia-smi answered (real time, at most $max s): true when an answer came */
function fanboyR6GpuWait(array &$g, float $now, float $max = 5.0): bool
{
    $until = hrtime(true) + $max * 1e9;
    while (hrtime(true) < $until) {
        if (fanboyGpuPoll($g, $now)) {
            return true;
        }
        if (!is_resource($g['proc'])) {
            return false;
        }
        usleep(10000);
    }
    return false;
}

/**
 * Part 3: nvidia-smi asked in a process of its own — the poll never waits (a slow one is cut off after 3 s), at most
 * every 10 s, after three failures in a row once a minute; its table read (two cards, «[N/A]», «No devices were found»);
 * only where nvidia-smi is there AND the driver is loaded
 */
function testFanboyR6GpuPoll(): void
{
    same('fanboy r6 gpu: nvidia-smi\'s lines — uuid, name (a comma in it kept), the number; «[N/A]» none', [
        [FANBOY_R6_GPU_A => ['uuid' => FANBOY_R6_GPU_A, 'name' => 'NVIDIA GeForce RTX 3060', 'c' => 45.0], FANBOY_R6_GPU_B => ['uuid' => FANBOY_R6_GPU_B, 'name' => 'NVIDIA T400, 4GB', 'c' => null]], []],
        [fanboyGpuParse(FANBOY_R6_GPU_A . ", NVIDIA GeForce RTX 3060, 45\n" . FANBOY_R6_GPU_B . ", NVIDIA T400, 4GB, [N/A]\n"), fanboyGpuParse("No devices were found\n")]);
    $t = fanboyT('r6gpu');
    try {
        fanboyR6NvidiaSmi($t, 'ok');
        exec('rm -rf ' . escapeshellarg("{$t['root']}/sys/module/nvidia"));
        $g = fanboyGpuNew();
        fanboyGpuPoll($g, 100.0);
        same('fanboy r6 gpu: nvidia-smi there but the driver not loaded — never started', [null, 'none', false], [$g['proc'], $g['why'], is_file("{$t['tmp']}/gpu.calls")]);
        fanboyR6NvidiaSmi($t, 'ok');
        $g = fanboyGpuNew();
        $t0 = hrtime(true);
        fanboyGpuPoll($g, 100.0);
        $ms = (hrtime(true) - $t0) / 1e6;
        same('fanboy r6 gpu: started — the poll returns at once (' . round($ms) . ' ms), the process runs on its own', [true, true], [is_resource($g['proc']), $ms < 200]);
        same('fanboy r6 gpu: … its answer: two cards with their numbers, asked with exactly the brief\'s query', [true, [45.0, 38.0], ['--query-gpu=uuid,name,temperature.gpu --format=csv,noheader,nounits']],
            [fanboyR6GpuWait($g, 100.0), array_column($g['cards'], 'c'), file("{$t['tmp']}/gpu.calls", FILE_IGNORE_NEW_LINES)]);
        fanboyGpuPoll($g, 105.0);
        same('fanboy r6 gpu: asked again 5 s later? no — at most every 10 s', false, is_resource($g['proc']));
        // slow: cut off after 3 s, never waited for
        fanboyR6NvidiaSmi($t, 'slow');
        fanboyGpuPoll($g, 110.0);
        $t0 = hrtime(true);
        $waited = 0.0;
        while (!fanboyGpuPoll($g, 110.0) && (hrtime(true) - $t0) / 1e9 < 8) {
            $t1 = hrtime(true);
            usleep(50000);
            $waited = max($waited, (hrtime(true) - $t1) / 1e9);
        }
        $took = (hrtime(true) - $t0) / 1e9;
        same('fanboy r6 gpu: one that hangs is cut off after about 3 s («timeout», one failure, the cards known kept without numbers)',
            [true, 'timeout', 1, [null, null]], [$took > 2.5 && $took < 4.5, $g['why'], $g['fails'], array_column($g['cards'], 'c')]);
        // three failures in a row: once a minute
        fanboyR6NvidiaSmi($t, 'error');
        foreach ([120.0, 130.0] as $at) {
            fanboyGpuPoll($g, $at);
            fanboyR6GpuWait($g, $at);
        }
        same('fanboy r6 gpu: three failures in a row (error, exit 9) — then asked only once a minute', [3, 'error', 190.0], [$g['fails'], $g['why'], $g['next']]);
        fanboyGpuPoll($g, 150.0);
        same('fanboy r6 gpu: … 20 s later: not asked', false, is_resource($g['proc']));
        fanboyR6NvidiaSmi($t, 'none');
        fanboyGpuPoll($g, 190.0);
        fanboyR6GpuWait($g, 190.0);
        same('fanboy r6 gpu: «No devices were found» (exit 6) is an answer: none listed, failures reset', [true, [], 0, 'none_listed'], [$g['ok'], $g['listed'], $g['fails'], $g['why']]);
        fanboyGpuStop($g);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Part 3: a card as a zone's source — its number counts (kind gpu, its own scale); handed to a VM (vfio-pci, nvidia-smi
 * no longer lists it): «away», no alarm, the zone goes on with its other sources; listed without a number for 60 s: the
 * usual «sensor says nothing»; a zone of cards only is refused; the control starts nvidia-smi only when a zone counts on it
 */
function testFanboyR6GpuZone(): void
{
    $t = fanboyT('r6gpuz');
    try {
        fanboyTDisks($t, 35, 33, 34);
        fanboyR6NvidiaSmi($t, 'hot');
        $settings = fanboyTSettings();
        $settings['zones'][1]['sources'][] = ['kind' => 'gpu', 'id' => FANBOY_GPU_PREFIX . FANBOY_R6_GPU_A];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        $rounds = function (int $n) use (&$c, &$now, $t, $spec): void {
            for ($i = 0; $i < $n; $i++) {
                fanboyRound($c, $now);
                fanboyR6GpuWait($c['gpu'], $now);
                fanboyFakeRespond($t['root'], $spec, FANBOY_ROUND);
                $now += FANBOY_ROUND;
            }
        };
        $rounds(4);
        same('fanboy r6 gpu zone: the card at 86 °C leads the case zone (kind gpu, named as nvidia-smi names it), the zone up',
            [FANBOY_GPU_PREFIX . FANBOY_R6_GPU_A, 86.0, 'NVIDIA GeForce RTX 3060', true], [$c['zones']['case']['warmest']['id'] ?? null, $c['zones']['case']['warmest']['c'] ?? null,
                $c['zones']['case']['warmest']['label'] ?? null, $c['zones']['case']['cur'] > 60]);
        // handed to a VM: bound to vfio-pci, nvidia-smi lists only card B
        @mkdir("{$t['root']}/sys/bus/pci/drivers/vfio-pci/0000:01:00.0", 0700, true);
        file_put_contents("{$t['root']}/sys/bus/pci/drivers/vfio-pci/0000:01:00.0/vendor", "0x10de\n");
        fanboyR6NvidiaSmi($t, 'one');
        $rounds(40);
        same('fanboy r6 gpu zone: the card handed to a VM — «away» (vfio), no alarm in 80 s, the zone on its other sources (the NVMe leads)',
            ['vfio', 'away', null, 'nvme'], [$c['sources'][FANBOY_GPU_PREFIX . FANBOY_R6_GPU_A]['away'] ?? null, $c['zones']['case']['state'][FANBOY_GPU_PREFIX . FANBOY_R6_GPU_A] ?? null,
                $c['alarm'], $c['zones']['case']['warmest']['kind'] ?? null]);
        same('fanboy r6 gpu zone: … the status counts it away', 1, fanboyStatus($c)['zones'][1]['away'] ?? null);
        // listed again, but without a number: «says nothing» — after 60 s (not the hwmon sensors' 30 s) the usual alarm
        fanboyR6NvidiaSmi($t, 'na');
        $rounds(20);
        same('fanboy r6 gpu zone: listed without a number for 40 s — not yet', null, $c['alarm']);
        $rounds(14);
        same('fanboy r6 gpu zone: … after 60 s the usual «sensor says nothing», its zone to 100 %', ['sensor', 'NVIDIA GeForce RTX 3060', 'case'],
            [$c['alarm']['code'] ?? null, $c['alarm']['params']['source'] ?? null, $c['alarm']['params']['zid'] ?? null]);
        fanboyLetGo($c, 'end');
        fanboyGpuStop($c['gpu']);
        // a control without a card in its zones never starts nvidia-smi
        @unlink("{$t['tmp']}/gpu.calls");
        $plain = fanboyControlNew(fanboyTSettings(), FANBOY_T_KEY, fanboyMe('control'));
        for ($i = 0; $i < 10; $i++) {
            fanboyRound($plain, $now);
            $now += FANBOY_ROUND;
        }
        same('fanboy r6 gpu zone: zones without a card — nvidia-smi never asked by the control', [false, false], [$plain['gpu_used'], is_file("{$t['tmp']}/gpu.calls")]);
        fanboyLetGo($plain, 'end');
        // the setup: a card is a source to choose (his look reads the last answer); a zone of cards only is refused
        fanboyR6NvidiaSmi($t, 'ok');
        $g = fanboyGpuNew();
        fanboyGpuPoll($g, 1.0);
        fanboyR6GpuWait($g, 1.0);
        fanboyGpuKeep($g);
        $scan = fanboyDetect();
        $ids = array_column(array_filter(fanboySources($scan), fn ($s) => $s['kind'] === 'gpu'), 'id');
        same('fanboy r6 gpu zone: his look lists both cards as heat sources', [FANBOY_GPU_PREFIX . FANBOY_R6_GPU_A, FANBOY_GPU_PREFIX . FANBOY_R6_GPU_B], $ids);
        $zone = fn (array $sources) => [['id' => 'case', 'name' => 'Case', 'fans' => [fanboyTId(5)], 'unit' => 'pct', 'target' => ['preset' => 'normal'], 'sources' => $sources]];
        same('fanboy r6 gpu zone: a zone of NVIDIA cards only — refused; with a disk beside — kept', ['fanboy_gpu_only', 2],
            [fanboyR6Refused(fn () => fanboyZonesCheck($zone([['id' => $ids[0]], ['id' => $ids[1]]]), $scan, fanboyTSettings())),
             count(fanboyZonesCheck($zone([['id' => $ids[0]], ['id' => 'parity']]), $scan, fanboyTSettings())[0]['sources'])]);
    } finally {
        fanboyTEnd();
    }
}
