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
