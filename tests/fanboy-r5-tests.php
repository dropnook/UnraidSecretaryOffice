<?php
declare(strict_types=1);

/*
 * Fanboy round 5 — the VM run's findings (part A) and the forum's (part B), each with a test that failed on 21aaa618.
 * Helpers from tests/fanboy-control-tests.php (the fake tree, the responder, the tests' clock).
 */

/** The subject (-s "…") of each notification the stand-in got */
function fanboyR5Subjects(array $t): array
{
    return array_values(array_filter(array_map(fn ($l) => preg_match('/-s .*?(Fanboy.*?) -d /', $l, $m) ? $m[1] : null, fanboyTNotified($t))));
}

/**
 * A1 (VM run, finding 3): two foreign writes 10 s apart were ONE notification — the subject named no fan, and Unraid's
 * duplicate guard (same subject within its window) swallowed the second. Each now names its fan; a second fan standing
 * while the first one's alarm is on is told too.
 */
function testFanboyR5Subjects(): void
{
    $t = fanboyT('r5subj');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        // a fan stands (the disk cage), then a fan of the other zone (one of the hub's) while that alarm is on
        fanboyTRounds($c, $now, $t, fanboyTSpec(10, [4 => ['stuck' => true]]), 10);
        fanboyTRounds($c, $now, $t, fanboyTSpec(10, [4 => ['stuck' => true], 6 => ['stuck' => true]]), 10);
        $alerts = array_values(array_filter(fanboyR5Subjects($t), fn ($s) => str_contains($s, 'stand')));
        same('fanboy r5 subjects: two fans standing one after the other — two alerts, each subject with its fan',
            ['Fanboy: Cage stands', 'Fanboy: Front stands'], $alerts);
        fanboyTRounds($c, $now, $t, $spec, 80);
        // someone else writes two outputs one after the other: two warnings, two subjects
        file_put_contents("{$t['hw']}/pwm4", "60\n");
        fanboyTRounds($c, $now, $t, $spec, 5);
        file_put_contents("{$t['hw']}/pwm5_enable", "2\n");
        fanboyTRounds($c, $now, $t, $spec, 2);
        $foreign = array_values(array_filter(fanboyR5Subjects($t), fn ($s) => str_contains($s, 'someone else')));
        same('fanboy r5 subjects: two foreign writes — two warnings, each subject naming its fan (Unraid\'s guard keys on the subject)',
            ['Fanboy: someone else changed Cage', 'Fanboy: someone else changed Front'], $foreign);
        fanboyLetGo($c, 'end');
        // every subject that can follow itself carries the name in all five languages
        foreach (['de', 'en', 'fr', 'it', 'es'] as $l) {
            $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$l.json"), true);
            foreach (['foreign' => '{fan}', 'alarm_stands' => '{fan}', 'alarm_stands_alone' => '{fan}', 'alarm_bios_stands' => '{fan}', 'alarm_sensor' => '{source}', 'drift' => '{fan}', 'alarm_hot' => '{zone}'] as $k => $ph) {
                check("fanboy r5 subjects: $l notify.{$k}_subject names it ($ph)", str_contains((string) ($j["notify.{$k}_subject"] ?? ''), $ph));
            }
        }
    } finally {
        fanboyTEnd();
    }
}

/**
 * A2 (VM run, finding 4): the guard wrote agent.log in PHP's own zone, the agent in glibc's (agentZone()) — an hour
 * apart in the same second. The guard now takes the agent's zone: fanboyZone() is agentZone().
 */
function testFanboyR5GuardZone(): void
{
    $tmp = hardeningTmp('fanboy-r5zone');
    @symlink('/usr/share/zoneinfo/Pacific/Kiritimati', "$tmp/real");
    @symlink('/usr/share/zoneinfo/Europe/Nowhere', "$tmp/missing");
    $links = ['/etc/localtime', "$tmp/real", "$tmp/missing", "$tmp/none"];
    same('fanboy r5 zone: fanboyZone() is agentZone() for the server\'s link, a real zone, a missing zone file, no link',
        array_map(fn ($l) => agentZone($l), $links), array_map(fn ($l) => fanboyZone($l), $links));
    // the guard as agent.sh runs it, PHP's own zone set far away: its line in the agent's zone
    $t = fanboyT('r5zone');
    try {
        @mkdir(TESTS_RUN_DIR . '/fanboy', 0700, true);
        file_put_contents(TESTS_RUN_DIR . '/fanboy/journal.json', json_encode(['v' => 1, 'boot' => FANBOY_T_BOOT, 'outputs' => new stdClass()]));
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        $p = proc_open([PHP_BINARY, '-d', 'date.timezone=Pacific/Kiritimati', OFFICE_DIR . '/agent/fanboy-guard.php', 'release'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, '/', ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'PATH' => (string) getenv('PATH')]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        $shown = preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) Fanboy: released/m', (string) $out, $m) ? new DateTime($m[1], new DateTimeZone(agentZone())) : null;
        check('fanboy r5 zone: the guard\'s line is in the agent\'s zone (' . agentZone() . '), not PHP\'s own (' . trim((string) $out) . ')',
            $shown !== null && abs($shown->getTimestamp() - time()) < 120);
    } finally {
        fanboyTEnd();
    }
}

/**
 * A3 (VM run, finding 5): an rpm zone's correction walked to −15 while an alarm held the zone at full, and the zone ran
 * under its curve for 1½ minutes after. Round 4's per-zone alarm (fanboyFullOne() clears «changed») already stops it;
 * round 5 holds the trim explicitly while the zone is at full — this test keeps both.
 */
function testFanboyR5TrimHeld(): void
{
    $t = fanboyT('r5trim');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['zones'][0]['unit'] = 'rpm';
        $settings['zones'][0]['target'] = ['points' => [[30, 700], [50, 1300]]];
        $settings['zones'][0]['sources'][] = ['kind' => 'nvme', 'id' => FANBOY_T_NVME];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 30);
        $trim = $c['fans'][fanboyTId(4)]['trim'];
        // the NVMe says nothing: the disk zone at full for the alarm (fan 4 at 1600 rpm where ~800 are wanted)
        $nv = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:06.1/0000:03:00.0/nvme/*/hwmon/hwmon*/temp1_input")[0];
        rename($nv, "$nv.away");
        fanboyTRounds($c, $now, $t, $spec, 40);
        same('fanboy r5 trim: the zone at full for «sensor» — the rpm correction held where it was', ['sensor', 255, $trim],
            [$c['alarm']['code'] ?? null, fanboyTOut($t, 4)[0], $c['fans'][fanboyTId(4)]['trim']]);
        rename("$nv.away", $nv);
        fanboyTRounds($c, $now, $t, $spec, 70);
        same('fanboy r5 trim: … after the alarm the zone on its curve again, the correction as before it', [null, $trim], [$c['alarm'], $c['fans'][fanboyTId(4)]['trim']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * A4 (VM run, finding 6): a board sensor without a label was named by its internal id in the notification; two NVMe
 * «Composite» weren't told apart. Now as the page names them.
 */
function testFanboyR5Names(): void
{
    $src = fn (string $kind, string $id, string $label, ?string $pool = null) => ['kind' => $kind, 'id' => $id, 'label' => $label, 'pool' => $pool];
    $names = fanboySourceNames([$src('disk', 'parity', 'parity'), $src('disk', 'disk3', 'disk3'), $src('ssd', 'cache', 'cache'),
        $src('board', 'it8696@platform/it87.2608#1', 'it8696 #1'), $src('board', 'it8696@platform/it87.2608#3', 'it8696 #3'),
        $src('nvme', 'nvme@a#1', 'Composite', 'cache'), $src('nvme', 'nvme@b#1', 'Composite', 'cache2'),
        $src('ram', 'spd@x#1', 'spd5118 #1'), $src('ram', 'spd@y#1', 'spd5118 #1'), $src('nvme', 'nvme@c#2', 'Sensor 1'), $src('nvme', 'nvme@d#2', 'Sensor 1')], 'en');
    same('fanboy r5 names: as the page — disks, a pool, board sensors by kind and number, two «Composite» by their pools, by a number without one',
        ['Parity', 'Disk 3', 'cache', 'Board sensor 1', 'Board sensor 2', 'Composite (cache)', 'Composite (cache2)', 'RAM sensor 1', 'RAM sensor 2', 'Sensor 1 · 1', 'Sensor 1 · 2'],
        array_values($names));
    $t = fanboyT('r5names');
    try {
        fanboyTDisks($t, 35, null, 33);
        $scan = fanboyDetect();
        same('fanboy r5 names: the fake NVMe chip is Unraid\'s «cache» (disks.ini device nvme0n1)', 'cache',
            array_column(fanboySources($scan), 'pool', 'id')[FANBOY_T_NVME] ?? null);
        // a board sensor of his chip in a zone goes silent: the alert names it as the page does, never by its id
        $board = fanboySourceId(fanboyChipNamed($scan, FANBOY_T_CHIP), 2);
        $settings = fanboyTSettings();
        $settings['zones'][1]['sources'] = [['kind' => 'board', 'id' => $board]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);
        rename("{$t['hw']}/temp2_input", "{$t['hw']}/temp2_input.away");
        fanboyTRounds($c, $now, $t, $spec, 20);
        $alert = implode("\n", array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i alert')));
        $page = fanboySourceNames(fanboySources($scan), 'en')[$board];
        check("fanboy r5 names: «sensor says nothing» names it as the page does («{$page}»), no chip id ($alert)",
            str_starts_with($page, 'Board sensor ') && str_contains($alert, "Fanboy: $page says nothing") && !str_contains($alert, '@') && !str_contains($alert, FANBOY_T_CHIP));
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * A5 (VM run, finding 7): the chip going away while he steers — the alert said «the BIOS runs the fans», the page said
 * «bios». They stay on the values he wrote last, nobody steers: said so (who «nobody»), the alert at once with the true
 * words and what to do, after 15 minutes only what is new. The chip missing from the start (a boot) keeps «the BIOS».
 */
function testFanboyR5ChipGone(): void
{
    $t = fanboyT('r5chip');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        exec('rm -rf ' . escapeshellarg("{$t['root']}/sys/class/hwmon/hwmon10") . ' ' . escapeshellarg("{$t['root']}/sys/devices/platform/" . FANBOY_T_ADDR));
        fanboyRound($c, $now);
        $st = fanboyStatus($c);
        same('fanboy r5 chip gone: nobody steers (not «the BIOS») — why «chip_gone»', ['nobody', 'chip_gone'], [$st['who'], $st['why']]);
        same('fanboy r5 chip gone: … the page\'s «who» says so too', ['nobody', 'chip_gone'],
            array_values(fanboyWhoNow(['who' => ['who' => 'bios']], ['running' => true] + $st, $settings)));
        $alerts = array_values(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i alert')));
        check('fanboy r5 chip gone: ONE alert at once, with the true words (the fans stay, nobody steers) — no «BIOS runs the fans» (' . ($alerts[0] ?? '') . ')',
            count($alerts) === 1 && str_contains($alerts[0], 'Nobody steers now') && !str_contains($alerts[0], 'BIOS runs'));
        for ($i = 0; $i < 16 * 60; $i++) {
            $now += 1.0;
            fanboyRound($c, $now);
        }
        $warn = array_values(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning')));
        check('fanboy r5 chip gone: after 15 minutes one warning with only what is new — no BIOS claim (' . ($warn[0] ?? '') . ')',
            count($warn) === 1 && str_contains($warn[0], 'still missing') && !str_contains($warn[0], 'BIOS'));
        // a control started again meanwhile (the agent restarted): still «nobody»
        $c2 = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        fanboyStandCarry($c2, $st, $now);
        fanboyRound($c2, $now);
        same('fanboy r5 chip gone: … carried over a restart of the control', ['nobody', 'chip_gone'], [fanboyStatus($c2)['who'], fanboyStatus($c2)['why']]);
    } finally {
        fanboyTEnd();
    }
    // the boot case: no chip from the start — the BIOS has them (as before)
    $board = fanboyFakeBoard();
    $t = fanboyT('r5boot', 10, FANBOY_T_ADDR, ['chips' => array_values(array_filter($board['chips'], fn ($x) => $x[1] !== FANBOY_T_CHIP))]);
    try {
        fanboyTDisks($t, 35, null, 33);
        fanboyTStore(fanboyTSettings());
        fanboyBeat();
        $c = fanboyControlNew(fanboyTSettings(), FANBOY_T_KEY, fanboyMe('control'));
        $now = 0.0;
        fanboyRound($c, $now);
        same('fanboy r5 chip gone: missing from the start (a boot) — «the BIOS», «chip_missing»', ['bios', 'chip_missing'], [fanboyStatus($c)['who'], fanboyStatus($c)['why']]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * A6 (VM run, finding 8): the chip's address moved (one chip of that name): the outputs were found again («moved»), its
 * sensors in the zones were not — «sensor says nothing», every fan of that zone at full. Now mapped like the outputs;
 * the ids stored stay as they are.
 */
function testFanboyR5Moved(): void
{
    $t = fanboyT('r5moved', 10, 'it87.2624');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();               // stored with the old address, it87.2608
        $old = FANBOY_T_CHIP . '@platform/' . FANBOY_T_ADDR . '#2';
        $settings['zones'][1]['sources'] = [['kind' => 'board', 'id' => $old]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 25);
        same('fanboy r5 moved: the chip at another address — its sensor found by the stored id, the zone steers, no alarm',
            [31.0, null, 'run', true], [$c['sources'][$old]['c'] ?? null, $c['alarm'], $c['phase'], $c['fans'][fanboyTId(5)]['taken']]);
        same('fanboy r5 moved: … the stored id unchanged', $old, fanboyBoardRaw()['zones'][1]['sources'][0]['id']);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * A7 (VM run, finding 9): «doesn't follow as measured» while an alarm held the fans at full (compared at the curve's
 * value). Round 4's fanboyFullOne() sets the percentage to 100; round 5 compares at the value written and never while
 * the zone is at full for an alarm.
 */
function testFanboyR5NoDriftAtFull(): void
{
    $t = fanboyT('r5drift');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['zones'][0]['sources'][] = ['kind' => 'nvme', 'id' => FANBOY_T_NVME];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        $nv = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:06.1/0000:03:00.0/nvme/*/hwmon/hwmon*/temp1_input")[0];
        rename($nv, "$nv.away");            // silent from the start: the disk zone at full once the alarm comes
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        $c['fans'][fanboyTId(4)]['pct'] = 30;       // what the VM run saw: a percentage left from the curve
        fanboyTRounds($c, $now, $t, $spec, 30);
        same('fanboy r5 drift: the zone at full for «sensor» — no «doesn\'t follow as measured»', ['sensor', 0],
            [$c['alarm']['code'] ?? null, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, 'as measured')))]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * B1/B2 (forum 3.2, 3.11): «doesn't obey» is not «empty» — a write the BIOS takes back (read back 2 s later), or a fan
 * turning that no output moves; and an output working the other way round. Both given back at once, never steered.
 */
function testFanboyR5Obeys(): void
{
    $t = fanboyT('r5obey');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        // round 8 (G3): the «unpaired» rule needs every output of the chip tested — output 6 found empty before
        $settings['fans'][fanboyTId(6)] = ['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 6, 'tach' => null, 'tachs' => [], 'found' => 'empty'];
        fanboyTStore($settings);
        $run = function (array $spec, array $outs) use ($t, $settings): array {
            fanboyTStore($settings);                              // each case from the same setup, a new boot's journal
            @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
            fanboyTSettle($t, $spec);
            $clock = 0.0;
            $step = fanboyStepRun(['id' => 'o' . mt_rand(), 'kind' => 'test_up', 'fans' => array_map('fanboyTId', $outs)], fanboyTIo($t, $spec, $clock));
            return array_map(fn ($r) => $r['found'], $step['results']);
        };
        // the BIOS puts output 4's mode back after every write: read back 2 s later — «doesn't obey»
        $spec = fanboyTSpec(10, [4 => ['auto' => 1100]]);
        $spec['revert'] = [4 => 2];
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        same('fanboy r5 obeys: the BIOS takes the mode back — «ignored» (not «ask», not «empty»)', [fanboyTId(4) => 'ignored'], $run($spec, [4]));
        same('fanboy r5 obeys: … stored so, out of every zone, never in the control', ['ignored', false, false],
            [fanboyBoardRaw()['fans'][fanboyTId(4)]['found'], in_array(fanboyTId(4), array_merge(...array_column(fanboyBoardRaw()['zones'], 'fans')), true),
             isset(fanboyControlNew(fanboyBoardRaw(), FANBOY_T_KEY, ['pid' => 0])['fans'][fanboyTId(4)])]);
        same('fanboy r5 obeys: … given back to its mode (2, the chip\'s own curve)', 2, fanboyTOut($t, 4)[1]);
        // the write stands, but its fan turns on by the BIOS's curve and no output moves it: no «louder?», «ignored»
        file_put_contents("{$t['hw']}/pwm4", "128\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "1\n");
        $spec = fanboyTSpec(10, [4 => ['bios' => true, 'auto' => 1100]]);
        same('fanboy r5 obeys: a fan turning that no output moves — the output where nothing followed is «ignored»', [fanboyTId(4) => 'ignored'], $run($spec, [4]));
        // nothing turning anywhere unpaired: «ask» as before (the silent fan, the empty connector)
        same('fanboy r5 obeys: … nothing unpaired turning: outputs 2, 3 «ask» as before', [fanboyTId(2) => 'ask', fanboyTId(3) => 'ask'], $run(fanboyTSpec(), [2, 3]));
        // the other way round: full makes its fan fall — «inverted», given back at once
        file_put_contents("{$t['hw']}/pwm4", "128\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "1\n");
        $spec = fanboyTSpec(10, [4 => ['inverted' => true]]);
        same('fanboy r5 obeys: full makes it slower — «inverted»', [fanboyTId(4) => 'inverted'], $run($spec, [4]));
        same('fanboy r5 obeys: … given back as it was, nothing held, never steered', [[128, 1], [], false],
            [fanboyTOut($t, 4), fanboyHeld(), isset(fanboyControlNew(fanboyBoardRaw(), FANBOY_T_KEY, ['pid' => 0])['fans'][fanboyTId(4)])]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * B3 (forum 3.4, it87 issue #56): give back safely — value, then mode; 10 s later the fan against what the mainboard ran
 * it at before; clearly slower and not rising: full speed, fixed, one alert, the journal says it. A normal give-back
 * stays quiet; a fan the BIOS keeps standing (0 before) is fine.
 */
function testFanboyR5GiveBackSafe(): void
{
    $t = fanboyT('r5back');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        unset($settings['fans'][fanboyTId(4)]['back']);
        fanboyTStore($settings);
        file_put_contents("{$t['hw']}/pwm4", "30\n");             // the mainboard's own curve, its register low
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        $case = function (array $after, ?array $before = null) use ($t, $settings): array {
            @unlink(TESTS_RUN_DIR . '/fanboy/journal.json');
            @unlink(fanboyRunDir() . '/told.json');
            $before ??= fanboyTSpec(10, [4 => ['auto' => 1100]]);
            fanboyTSettle($t, $before, 20);
            fanboyBeat();
            $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
            $now = 1000.0;
            fanboyTRounds($c, $now, $t, $before, 10);
            fanboyLetGo($c, 'end');
            $order = fanboyTOut($t, 4);
            fanboyTSettle($t, $after, 12);
            $at = fanboyJournal()['outputs'][fanboyTId(4)]['given']['at'];
            $early = fanboyBackCheck($at + 5);
            $full = fanboyBackCheck($at + 12);
            return [$order, array_keys($early), array_keys($full), fanboyTOut($t, 4), fanboyJournal()['outputs'][fanboyTId(4)]['given']['full']['before'] ?? null];
        };
        $n0 = count(fanboyTNotified($t));
        same('fanboy r5 back: a mainboard that takes it back — its value then its mode (30, 2), looked at after 10 s, quiet',
            [[30, 2], [], [], [30, 2], null], $case(fanboyTSpec(10, [4 => ['auto' => 1100]])));
        same('fanboy r5 back: … no notification', $n0, count(fanboyTNotified($t)));
        file_put_contents("{$t['hw']}/pwm4", "30\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        same('fanboy r5 back: a chip that ignores its own mode (it87 #56) — the fan stops: after 10 s full speed, fixed, in the journal',
            [[30, 2], [], [fanboyTId(4)], [255, 1], 1100], $case(fanboyTSpec(10, [4 => ['auto' => 1100, 'auto_dead' => true]])));
        $alerts = array_values(array_filter(array_slice(fanboyTNotified($t), $n0), fn ($l) => str_contains($l, '-i alert')));
        check('fanboy r5 back: … one alert naming it (' . ($alerts[0] ?? '') . ')', count($alerts) === 1 && str_contains($alerts[0], "didn't take Cage back"));
        // a fan the BIOS keeps standing (fan stop): 0 before, 0 after — fine
        file_put_contents("{$t['hw']}/pwm4", "30\n");
        file_put_contents("{$t['hw']}/pwm4_enable", "2\n");
        same('fanboy r5 back: a fan the BIOS kept standing before (0 rpm) — nothing done', [[30, 2], [], [], [30, 2], null],
            $case(fanboyTSpec(10, [4 => ['auto' => 0]]), fanboyTSpec(10, [4 => ['auto' => 0]])));
    } finally {
        fanboyTEnd();
    }
}

/**
 * B4 (forum 3.8): readings that are no readings — rpm 65535 (≥ 20 000) and temperatures 0, −128, 127, ≥ 125 °C are
 * «says nothing», never a speed or «too warm»; one 0 rpm among good readings never trips «stands» (it needs 15 s).
 */
function testFanboyR5Sane(): void
{
    same('fanboy r5 sane: rpm — 1200 is a speed, 0 too; 65535, 20000, −1 are none',
        [1200, 0, null, null, null, null], array_map('fanboySaneRpm', [1200, 0, 65535, 20000, -1, null]));
    same('fanboy r5 sane: °C — 45.5 and −5 are temperatures; 0, −128, 127, 125, 200 are none',
        [45.5, -5.0, null, null, null, null, null], array_map('fanboySaneTemp', [45500, -5000, 0, -128000, 127000, 125000, 200000]));
    $t = fanboyT('r5sane');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        // one 0 among good readings (an outlier, as an IT8688E shows): nothing
        file_put_contents("{$t['hw']}/fan4_input", "0\n");
        fanboyRound($c, $now);
        $now += FANBOY_ROUND;
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy r5 sane: one 0 rpm among good readings — no alarm, its clock back to nought', [null, null], [$c['alarm'], $c['fans'][fanboyTId(4)]['zero_since']]);
        // 65535 on the hub's second input for half a minute: no reading — never «stands»
        $hub = fanboyTSpec(10, [6 => ['stuck' => true]]);
        for ($i = 0; $i < 15; $i++) {
            fanboyFakeRespond($t['root'], $hub, FANBOY_ROUND);
            file_put_contents("{$t['hw']}/fan6_input", "65535\n");
            fanboyRound($c, $now);
            $now += FANBOY_ROUND;
        }
        same('fanboy r5 sane: 65535 for 30 s — no speed, never «stands»', [null, null], [$c['alarm'], $c['fans'][fanboyTId(5)]['rpm']]);
        // the NVMe reports 127 °C (a broken sensor): «says nothing», never «too warm» at full
        $nv = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:06.1/0000:03:00.0/nvme/*/hwmon/hwmon*/temp1_input")[0];
        file_put_contents($nv, "127000\n");
        fanboyTRounds($c, $now, $t, $spec, 3);
        same('fanboy r5 sane: an NVMe at «127 °C» — no reading (no «too warm»)', [null, null], [$c['sources'][FANBOY_T_NVME]['c'], $c['alarm']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * B5 (forum 3.9): a driver that refuses every write (EIO/EACCES; here: the pwm file a link — the write helper refuses
 * it the same way). Before round 5: tried again every round, silently. Now after 3 in a row: that output back as far as
 * it goes, never taken again by this control, one warning, a note, a line in the report.
 */
function testFanboyR5Refused(): void
{
    $t = fanboyT('r5refuse');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        // the driver takes no values for output 4 from now on
        rename("{$t['hw']}/pwm4", "{$t['hw']}/pwm4.real");
        symlink("{$t['hw']}/pwm4.real", "{$t['hw']}/pwm4");
        fanboyTDisks($t, 44, null, 33);             // warmer: the disk zone wants more — writes needed
        fanboyTRounds($c, $now, $t, $spec, 8);
        $st = fanboyStatus($c);
        same('fanboy r5 refused: three refused writes — not his any more (never taken again), the status and a note say it',
            [false, true, [fanboyTId(4)], 'refuses'], [$c['fans'][fanboyTId(4)]['taken'], $c['fans'][fanboyTId(4)]['refuses'] ?? false, $st['refuses'], end($st['notes'])['code'] ?? null]);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('fanboy r5 refused: … one warning naming it', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, 'takes no values for Cage'))));
        fanboyStatusWrite($st);
        check('fanboy r5 refused: … a line in the report', str_contains(fanboyReportText(fanboyDetect()), 'Driver refused values (EIO/EACCES) for: ' . fanboyTId(4)));
        same('fanboy r5 refused: … the other zone steers on', true, $c['fans'][fanboyTId(5)]['taken']);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * B6–B10, B12 (forum 3.1/3.3, 3.10, 3.12, 3.13, 3.16, 3.18): what part 1 sees anew — Unraid's poll_attributes, a virtual
 * machine (a hint only), two drivers on one chip (not offered), controllers in containers by their process, a board
 * DMI says nothing about (product + chip; the old key read), and the report's new lines.
 */
function testFanboyR5Detect(): void
{
    $board = fanboyFakeBoard();
    $chips = array_merge($board['chips'], [
        [11, 'nct6687', 'platform/nct6687.2592', 'nct6687', 'pwm' => [1 => [100, 1, true]], 'fans' => [1 => 900]],
        [12, 'nct6683', 'platform/nct6683.2592', 'nct6683', 'pwm' => [1 => [100, 1, true]], 'fans' => [1 => 900]]]);
    $t = fanboyT('r5detect', 10, FANBOY_T_ADDR, ['chips' => $chips, 'board' => ['Default string', 'To be filled by O.E.M.'],
        'files' => ['var/local/emhttp/var.ini' => "fsState=\"Started\"\npoll_attributes=\"1800\"\n", 'sys/class/dmi/id/sys_vendor' => "QEMU\n",
            'sys/class/dmi/id/product_name' => "Example Box 4\n", 'proc/cmdline' => "BOOT_IMAGE=/bzimage\n",
            'proc/sys/kernel/random/boot_id' => FANBOY_T_BOOT . "\n", 'boot/config/plugins/it87-driver.plg' => '<?xml version="1.0"?><PLUGIN name="it87-driver" version="2026.09.15"/>'],
        'procs' => [1 => '/sbin/init', 4321 => "/usr/bin/coolercontrold\0--config\0/etc/cc"]]);
    try {
        fanboyTDisks($t, 35, null, 33);
        $scan = fanboyDetect();
        same('fanboy r5 detect: poll_attributes 1800 s, a virtual machine (QEMU), two drivers on one chip (nct6683 + nct6687)',
            [1800, true, ['nct6687', 'nct6683']], [$scan['poll'], $scan['vm'], $scan['twins']]);
        same('fanboy r5 detect: … the twins\' outputs not offered — only the ITE chip\'s', [FANBOY_T_CHIP], array_values(array_unique(array_column(fanboyOutputs($scan), 'chip'))));
        same('fanboy r5 detect: CoolerControl in a container, seen by its process — another controller, he holds back', ['coolercontrol', true, 'other'],
            [$scan['controllers'][0]['id'] ?? null, $scan['controllers'][0]['blocks'] ?? null, $scan['situation']['code']]);
        $key = fanboyBoardKey($scan['board']);
        same('fanboy r5 detect: DMI says nothing of the board — keyed by product and fan chip with its address', ' | Example Box 4 | ' . FANBOY_T_CHIP . '@' . FANBOY_T_ADDR, $key);
        // what was kept under the old key « | » stays readable, and the first write keeps it under the new key
        @mkdir(FANBOY_DIR, 0700, true);
        file_put_contents(fanboyStoreFile(), json_encode(['v' => 1, 'boards' => [' | ' => fanboyTSettings()]], JSON_UNESCAPED_SLASHES));
        same('fanboy r5 detect: … the settings under the old key « | » read', 'Disks', fanboyBoardRaw()['zones'][0]['name'] ?? null);
        fanboyStoreUpdate(fn (array $e) => $e);
        $raw = readJson(fanboyStoreFile());
        same('fanboy r5 detect: … written under the new key, the old one kept as it was', [true, true], [isset($raw['boards'][$key]['zones']), isset($raw['boards'][' | ']['zones'])]);
        $report = fanboyReportText($scan);
        foreach (['Disk temperatures polled every: 1800 s (poll_attributes)', 'Virtual machine: yes', 'Two drivers on one chip: nct6687, nct6683',
                  'Driver plugin it87-driver: version 2026.09.15', '  pwm4: obeys', '  pwm3: empty'] as $line) {
            check("fanboy r5 detect: the report says «{$line}»", str_contains($report, $line));
        }
    } finally {
        fanboyTEnd();
    }
    $t = fanboyT('r5detect2');
    try {
        $scan = fanboyDetect();
        same('fanboy r5 detect: a board as usual — no VM, no twins, poll_attributes unknown, the old key', [false, [], null, FANBOY_T_KEY],
            [$scan['vm'], $scan['twins'], $scan['poll'], fanboyBoardKey($scan['board'])]);
    } finally {
        fanboyTEnd();
    }
}

/**
 * B11 (forum 3.6): a zone with NVMe/board/CPU sources never goes to its rest value only because its disks sleep (all
 * disks asleep, NVMe 60 °C); the rest value of a zone with sleeping disks only is never under a fan's floor.
 */
function testFanboyR5DisksAsleep(): void
{
    $t = fanboyT('r5asleep');
    try {
        fanboyTDisks($t, null, null, null);              // every disk asleep
        $nv = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:06.1/0000:03:00.0/nvme/*/hwmon/hwmon*/temp1_input")[0];
        file_put_contents($nv, "60000\n");
        $settings = fanboyTSettings();
        $settings['zones'][0]['sources'][] = ['kind' => 'nvme', 'id' => FANBOY_T_NVME];      // the disk zone with the NVMe pool too
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 6);
        $rest = fanboyZoneCurve($c['zones']['disks'])[0][1];
        same('fanboy r5 asleep: every disk asleep, the NVMe at 60 °C — the zone led by the NVMe, not its rest value',
            [FANBOY_T_NVME, true], [$c['zones']['disks']['warmest']['id'] ?? null, $c['zones']['disks']['cur'] > $rest]);
        // the disks only, all asleep: its rest value — never under the disk fan's floor (measured 25 % + 5)
        $settings['zones'][0]['sources'] = array_slice($settings['zones'][0]['sources'], 0, 3);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy r5 asleep: the disks only, all asleep — its rest value, the fan at its floor (30 %), not under it',
            [null, 30], [$c['zones']['disks']['warmest'], $c['fans'][fanboyTId(4)]['pct']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * B13 (forum 3.7, 1.16): two chips of one driver at different addresses (a Gigabyte IT8689 + IT87952) — each output its
 * own id, both steered; two chips of the SAME name with a stored address that moved: ambiguous, never guessed.
 */
function testFanboyR5TwoChips(): void
{
    $board = fanboyFakeBoard();
    $second = [11, 'it87952', 'platform/it87.2624', 'it87', 'pwm' => [1 => [128, 1, true], 2 => [128, 1, true]], 'fans' => [1 => 1100, 2 => 0]];
    $t = fanboyT('r5two', 10, FANBOY_T_ADDR, ['chips' => array_merge($board['chips'], [$second])]);
    try {
        fanboyTDisks($t, 35, null, 33);
        $scan = fanboyDetect();
        $ids = array_keys(fanboyOutputs($scan));
        check('fanboy r5 two chips: both chips of the it87 driver offered, each output its own id (' . implode(', ', $ids) . ')',
            in_array(fanboyTId(4), $ids, true) && in_array(fanboyFanId('it87952', 'it87.2624', 1), $ids, true) && count($ids) === 8);
        $settings = fanboyTSettings();
        $other = fanboyFanId('it87952', 'it87.2624', 1);
        $settings['fans'][$other] = ['chip' => 'it87952', 'address' => 'it87.2624', 'output' => 1, 'tach' => 1, 'tachs' => [1], 'found' => 'tach', 'role' => 'case', 'name' => 'Rear', 'back' => 'fixed'];
        $settings['zones'][1]['fans'][] = $other;
        fanboyTStore($settings);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyRound($c, $now);
        same('fanboy r5 two chips: … a zone with outputs of both — both taken, steered', ['run', true, true],
            [$c['phase'], $c['fans'][fanboyTId(5)]['taken'], $c['fans'][$other]['taken']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
    $twin = [11, FANBOY_T_CHIP, 'platform/it87.2624', 'it87', 'pwm' => [1 => [128, 1, true]], 'fans' => [1 => 1100]];
    $t = fanboyT('r5same', 10, FANBOY_T_ADDR, ['chips' => array_merge($board['chips'], [$twin])]);
    try {
        $scan = fanboyDetect();
        $hw = fanboyPaths()['hwmon'];
        same('fanboy r5 two chips: the same name twice — the stored address there: here; an address of before gone: ambiguous, never guessed',
            ['here', 'ambiguous'], [fanboyResolve(['chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'output' => 1, 'tach' => 1], $scan, $hw)['how'],
                fanboyResolve(['chip' => FANBOY_T_CHIP, 'address' => 'it87.2700', 'output' => 1, 'tach' => 1], $scan, $hw)['how']]);
    } finally {
        fanboyTEnd();
    }
}
