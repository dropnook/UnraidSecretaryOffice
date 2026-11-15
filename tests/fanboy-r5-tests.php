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
