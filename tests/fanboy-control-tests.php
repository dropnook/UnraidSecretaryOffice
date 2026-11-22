<?php
declare(strict_types=1);

/*
 * Fanboy part 2 — steering — against the fake hwmon tree only (tests/run.php fanboyFakeTree(); the fans answer through
 * tests/fanboy-responder.php). Required by tests/run.php; the tests are in its part `fanboy`.
 *
 * Every test here works in a tree of its own (OFFICE_FANBOY_ROOT names it to the code and to the processes it starts),
 * a journal of its own in the tests' RAM folder, and the repository's data/fanboy put aside for the time (his store,
 * the copy of the outputs' states, the drivers he loaded): fanboyT() before, fanboyTEnd() after (always — a finally).
 */

const FANBOY_T_BOOT = 'aaaaaaaa-0000-4000-8000-000000000001';
const FANBOY_T_CHIP = 'it8696_a0060607';
const FANBOY_T_ADDR = 'it87.2608';
const FANBOY_T_KEY = 'Example Boards Inc. | EB-Z100 PRO';

/** The fan id of output $n on the fake board */
function fanboyTId(int $n, string $address = FANBOY_T_ADDR): string
{
    return fanboyFanId(FANBOY_T_CHIP, $address, $n);
}

/**
 * A fake board to steer: the tree (the ITE chip at hwmon$n, $address), boot id, an array started, the env pointing at it,
 * the journal empty, data/fanboy aside, a notify stand-in (its calls in <tmp>/notify.log)
 */
function fanboyT(string $name, int $n = 10, string $address = FANBOY_T_ADDR, array $more = []): array
{
    $tmp = hardeningTmp("fanboy-$name");
    $more['files'] = ($more['files'] ?? []) + ['proc/cmdline' => "BOOT_IMAGE=/bzimage\n", 'proc/sys/kernel/random/boot_id' => FANBOY_T_BOOT . "\n",
        'var/local/emhttp/var.ini' => "fsState=\"Started\"\n"];
    $root = fanboyFakeTree("$tmp/root", fanboyFakeBoard($n, $address, $more));
    putenv("OFFICE_FANBOY_ROOT=$root");
    exec('rm -rf ' . escapeshellarg(TESTS_RUN_DIR . '/fanboy'));
    $data = dirname(FANBOY_DIR);
    if (is_dir(FANBOY_DIR) && !is_dir("$data/fanboy.tests-aside")) {
        rename(FANBOY_DIR, "$data/fanboy.tests-aside");
    }
    exec('rm -rf ' . escapeshellarg(FANBOY_DIR));
    @mkdir(FANBOY_DIR, 0700, true);
    file_put_contents("$tmp/notify", "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg("$tmp/notify.log") . "\n");
    chmod("$tmp/notify", 0755);
    $GLOBALS['fanboyTEnv'] ??= [getenv('OFFICE_NOTIFY_BIN'), getenv('OFFICE_NOTIFY_STAMP')];
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    putenv("OFFICE_NOTIFY_STAMP=$tmp/notify.second");
    $GLOBALS['fanboyLauncher'] = function (string $job) { $GLOBALS['fanboyLaunched'][] = $job; };
    $GLOBALS['fanboyLaunched'] = [];
    return ['tmp' => $tmp, 'root' => $root, 'hw' => "$root/sys/class/hwmon/hwmon$n", 'n' => $n];
}

function fanboyTEnd(): void
{
    putenv('OFFICE_FANBOY_ROOT');
    [$bin, $stamp] = $GLOBALS['fanboyTEnv'] ?? [false, false];
    putenv($bin === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$bin");
    putenv($stamp === false ? 'OFFICE_NOTIFY_STAMP' : "OFFICE_NOTIFY_STAMP=$stamp");
    unset($GLOBALS['fanboyLauncher'], $GLOBALS['fanboyNightNext'], $GLOBALS['fanboyNightWanted'], $GLOBALS['fanboyTEnv'], $GLOBALS['fanboyLaunched'], $GLOBALS['fanboyLaunchedAt'], $GLOBALS['fanboyMirrorSeen'], $GLOBALS['fanboyInitialDone'], $GLOBALS['fanboyHistorySyncNext']);
    exec('rm -rf ' . escapeshellarg(TESTS_RUN_DIR . '/fanboy') . ' ' . escapeshellarg(FANBOY_DIR));
    $data = dirname(FANBOY_DIR);
    if (is_dir("$data/fanboy.tests-aside")) {
        rename("$data/fanboy.tests-aside", FANBOY_DIR);
    }
}

/** An output's files now: [value, enable] */
function fanboyTOut(array $t, int $n): array
{
    $hw = "{$t['root']}/sys/class/hwmon/hwmon{$t['n']}";
    return [fanboyReadInt("$hw/pwm$n"), fanboyReadInt("$hw/pwm{$n}_enable")];
}

/** How the fake fans answer: pwm1 → tach 1 (the CPU cooler), pwm2 no tach, pwm3 empty, pwm4 → tach 4, pwm5 → tach 5 and 6 (a hub) */
function fanboyTSpec(int $n = 10, array $change = []): array
{
    $spec = ['hwmon' => "hwmon$n", 'fans' => [
        1 => ['output' => 1, 'max' => 1800, 'stall' => 0.15, 'start' => 0.25, 'lag' => 1.0],
        4 => ['output' => 4, 'max' => 1600, 'stall' => 0.18, 'start' => 0.3, 'lag' => 1.0],
        5 => ['output' => 5, 'max' => 1500, 'stall' => 0.2, 'start' => 0.3, 'lag' => 1.0],
        6 => ['output' => 5, 'max' => 1450, 'stall' => 0.2, 'start' => 0.3, 'lag' => 1.0],
    ]];
    foreach ($change as $tach => $c) {
        $spec['fans'][$tach] = $c + ($spec['fans'][$tach] ?? []);
    }
    return $spec;
}

/** The setup a user would have made on the fake board: CPU cooler (BIOS), a disk fan measured, a hub and a silent fan for the case */
function fanboyTSettings(string $address = FANBOY_T_ADDR, array $more = []): array
{
    // part 3: «give back» set to the fixed value for his fans here (the default is the mainboard's automatic since the
    // owner's decision) — part 2's tests read the outputs back exactly as before; testFanboyBack tries the automatic
    $fan = fn (int $n, array $x) => $x + ['chip' => FANBOY_T_CHIP, 'address' => $address, 'output' => $n, 'tach' => null, 'tachs' => []]
        + (in_array($x['role'] ?? null, ['case', 'disks'], true) ? ['back' => 'fixed'] : []);
    $nvme = FANBOY_T_NVME;
    return $more + [
        'board' => ['vendor' => 'Example Boards Inc.', 'model' => 'EB-Z100 PRO'],
        'fans' => [
            fanboyTId(1, $address) => $fan(1, ['tach' => 1, 'tachs' => [1], 'found' => 'tach', 'role' => 'cpu', 'name' => 'CPU']),
            fanboyTId(2, $address) => $fan(2, ['found' => 'silent', 'role' => 'case', 'name' => 'Top']),
            fanboyTId(3, $address) => $fan(3, ['found' => 'empty']),
            fanboyTId(4, $address) => $fan(4, ['tach' => 4, 'tachs' => [4], 'found' => 'tach', 'role' => 'disks', 'name' => 'Cage',
                'measured' => ['table' => [[100, 1600], [80, 1344], [60, 1088], [40, 832], [25, 640]], 'min_pct' => 25, 'start_pct' => 30, 'max_rpm' => 1600, 'at' => 1]]),
            fanboyTId(5, $address) => $fan(5, ['tach' => 5, 'tachs' => [5, 6], 'found' => 'tach', 'role' => 'case', 'name' => 'Front']),
        ],
        'zones' => [
            // (2026-11-20: the presets changed — the tests of the control keep the curve they were written on as the zone's own:
            // round 4's «normal» on the old disk scale 35…45 °C)
            ['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4, $address)], 'unit' => 'pct', 'target' => ['points' => [[35, 20], [40, 45], [45, 80], [48, 100]]],
             'sources' => [['kind' => 'disk', 'id' => 'parity'], ['kind' => 'disk', 'id' => 'disk1'], ['kind' => 'disk', 'id' => 'disk2']]],
            ['id' => 'case', 'name' => 'Case', 'fans' => [fanboyTId(5, $address), fanboyTId(2, $address)], 'unit' => 'pct', 'target' => ['preset' => 'quiet'],
             'sources' => [['kind' => 'nvme', 'id' => $nvme], ['kind' => 'ram', 'id' => 'spd5118@pci0000:00/0000:00:1f.4/i2c-0/0-0051#1']]],
        ],
        'armed' => true,
    ];
}
const FANBOY_T_NVME = 'nvme@pci0000:00/0000:00:06.1/0000:03:00.0/nvme#1';

function fanboyTStore(array $settings, string $key = FANBOY_T_KEY): void
{
    @mkdir(FANBOY_DIR, 0700, true);
    $raw = readJson(fanboyStoreFile()) ?? ['v' => 1, 'boards' => []];
    $raw['boards'][$key] = $settings;
    file_put_contents(fanboyStoreFile(), json_encode($raw, JSON_UNESCAPED_SLASHES));
}

/** disks.ini with these temperatures (null: asleep) for parity, disk1, disk2 — cache stays 40 */
function fanboyTDisks(array $t, ?int $parity, ?int $disk1, ?int $disk2): void
{
    $d = fn (string $name, string $type, string $dev, ?int $c) => "[$name]\nname=\"$name\"\ntype=\"$type\"\ndevice=\"$dev\"\nid=\"EXAMPLE_HDD_" . strtoupper($name) . "\"\ntemp=\"" . ($c ?? '*') . "\"\nspundown=\"" . ($c === null ? 1 : 0) . "\"\nrotational=\"1\"\nstatus=\"DISK_OK\"\n";
    file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $d('parity', 'Parity', 'sdb', $parity) . $d('disk1', 'Data', 'sdc', $disk1) . $d('disk2', 'Data', 'sdd', $disk2)
        . "[cache]\nname=\"cache\"\ntype=\"Cache\"\ndevice=\"nvme0n1\"\nid=\"EXAMPLE_NVME\"\ntemp=\"40\"\nspundown=\"0\"\nrotational=\"0\"\nstatus=\"DISK_OK\"\n");
}

/** Rounds of the control on the tests' clock ($now, seconds — moved FANBOY_ROUND each), the fans answering in between */
function fanboyTRounds(array &$c, float &$now, array $t, array $spec, int $rounds): void
{
    for ($i = 0; $i < $rounds; $i++) {
        fanboyRound($c, $now);
        fanboyFakeRespond($t['root'], $spec, FANBOY_ROUND);
        $now += FANBOY_ROUND;
    }
}

function fanboyTNotified(array $t): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents("{$t['tmp']}/notify.log"))));
}

/** The fans settled to what the outputs ask (the responder for $seconds on its own) */
function fanboyTSettle(array $t, array $spec, float $seconds = 10): void
{
    for ($s = 0; $s < $seconds; $s += 0.5) {
        fanboyFakeRespond($t['root'], $spec, 0.5);
    }
}

// ===================================================================== the write helper

function testFanboyWrite(): void
{
    $t = fanboyT('write');
    try {
        $hw = $t['hw'];
        $root = $t['root'];
        $id = fanboyTId(4);
        $fan = ['id' => $id, 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => 4];
        // the one write refuses everything but pwmN / pwmN_enable directly in a hwmonN of the root, there already, no link
        file_put_contents("$root/elsewhere", "7\n");
        symlink("$root/elsewhere", "$hw/pwm7");
        $refused = [
            fanboyWriteFile("$hw/fan4_input", 5), fanboyWriteFile("$hw/pwm4_freq", 5), fanboyWriteFile("$root/elsewhere", 5),
            fanboyWriteFile("$hw/../hwmon10/pwm4", 5), fanboyWriteFile("$hw/pwm9", 5), fanboyWriteFile("$hw/pwm7", 5),
            fanboyWriteFile("/sys/class/hwmon/hwmon0/pwm1", 5), fanboyWriteFile("$hw/pwm4", 256), fanboyWriteFile("$hw/pwm4", -1),
        ];
        same('fanboy write: refused — a tach file, another attribute, a file elsewhere, «..», a pwm not there, a link, the real tree from the tests, out of 0–255',
            array_fill(0, 9, false), $refused);
        same('fanboy write: … nothing created, nothing written through the link', [false, "7\n"], [file_exists("$hw/pwm9"), file_get_contents("$root/elsewhere")]);
        unlink("$hw/pwm7");
        // the paths found anew from the device, never a stored hwmonN
        same('fanboy write: an output\'s files from its device and number', ["$root/sys/class/hwmon/hwmon10/pwm4", "$root/sys/class/hwmon/hwmon10/pwm4_enable"],
            fanboyOutputFiles('platform/' . FANBOY_T_ADDR, 4));
        same('fanboy write: no output for a device that isn\'t there, «..» or a number too big', [null, null, null],
            [fanboyOutputFiles('platform/it87.9999', 4), fanboyOutputFiles('platform/../x', 4), fanboyOutputFiles('platform/' . FANBOY_T_ADDR, 100)]);

        // take: the state before taken down (RAM and the data folder's copy), then a value, read back
        $me = fanboyMe('control');
        fanboyBeat();
        $taken = fanboyTake($fan, $me, FANBOY_T_KEY);
        same('fanboy write: taken — the state before him noted (value 128, enable 1: a fixed value)', [true, ['value' => 128, 'enable' => 1]], [$taken['first'], $taken['initial']]);
        same('fanboy write: … and in the data folder\'s copy, per board', ['value' => 128, 'enable' => 1],
            array_intersect_key(fanboyInitialRead()['boards'][FANBOY_T_KEY][$id] ?? [], ['value' => 1, 'enable' => 1]));
        same('fanboy write: a value written and read back', [['value' => 200, 'enable' => 1], [200, 1]], [fanboySet($id, 200, $me), fanboyTOut($t, 4)]);
        same('fanboy write: a second take by the same holder keeps the first state (never his own value as «before»)', ['value' => 128, 'enable' => 1],
            fanboyTake($fan, $me, FANBOY_T_KEY)['initial']);
        same('fanboy write: while he holds it nobody else can take it, nor write it', [null, null],
            [fanboyTake($fan, ['kind' => 'step', 'pid' => 1, 'start' => fanboyProcStart(1), 'since' => 0], FANBOY_T_KEY),
             fanboySet($id, 10, ['kind' => 'step', 'pid' => 999999, 'start' => '1'])]);
        // someone else's write: the value or the mode behind his back
        same('fanboy write: nobody else wrote — nothing foreign', [], fanboyForeign($me));
        file_put_contents("$hw/pwm4_enable", "2\n");
        same('fanboy write: the mode changed behind his back — foreign', [$id], array_keys(fanboyForeign($me)));
        // back: exactly the state before, read back
        $back = fanboyGiveBack([$id], 'test', $me);
        same('fanboy write: given back — 128 as a fixed value again, read back, nobody holds it', [true, [128, 1], []],
            [$back[$id]['ok'] ?? null, fanboyTOut($t, 4), fanboyHeld()]);

        // an output the chip steered by its own curve (enable 2 at 90): the value back first, then the mode
        file_put_contents("$hw/pwm2", "90\n");
        file_put_contents("$hw/pwm2_enable", "2\n");
        $fan2 = ['id' => fanboyTId(2), 'output' => 2] + $fan;
        fanboyTake($fan2, $me, FANBOY_T_KEY);
        fanboySet(fanboyTId(2), 255, $me);
        same('fanboy write: an output of the chip\'s curve set to a fixed value (enable 1 first)', [255, 1], fanboyTOut($t, 2));
        fanboyGiveBack(null, 'test', $me);
        same('fanboy write: … and back: value 90, then the chip\'s mode 2 — exactly the number it had', [90, 2], fanboyTOut($t, 2));
        // a mode that doesn't read back (a chip refusing): retried once, then said
        $ro = fanboyWriteState('platform/' . FANBOY_T_ADDR, 3, ['value' => 50, 'enable' => 7]);
        same('fanboy write: a state read back as written: ok', true, $ro['ok']);

        // a holder gone (its process ended) or hung (no beat): its outputs given back by whoever looks
        $p = proc_open([PHP_BINARY, '-r', 'echo getmypid();'], [1 => ['pipe', 'w']], $pipes);
        $deadPid = (int) stream_get_contents($pipes[1]);
        proc_close($p);
        $dead = ['kind' => 'control', 'pid' => $deadPid, 'start' => '12345', 'since' => 0];
        $own = function (array $who) use ($id): void {
            fanboyLocked(function (array $j) use ($id, $who): array {
                $j['outputs'][$id]['owner'] = $who;
                return [$j, null];
            });
        };
        $own($me);
        file_put_contents("$hw/pwm4", "33\n");
        same('fanboy write: a holder alive (beating) is left alone by the watch', [[], 'alive', [33, 1]], [fanboyGiveBack(null, 'watch', null, true), fanboyOwnerState($me), fanboyTOut($t, 4)]);
        $own($dead);
        $w = fanboyGiveBack(null, 'holder_gone', null, true);
        same('fanboy write: a holder gone — its output given back by the watch (128, fixed), said as «dead»', [[$id], 'dead', [128, 1]],
            [array_keys($w), $w[$id]['holder'] ?? null, fanboyTOut($t, 4)]);
        $b = hrtime(true) - 40_000_000_000;         // an integer (a float prints as 2.8E+14 on a server up for days: no beat at all)
        file_put_contents(fanboyRunDir() . '/beat-' . $me['pid'], (string) $b);
        same('fanboy write: a holder without a beat for 40 s — hung; with one 20 s old — alive (the test\'s own clock)', ['hung', 'alive'],
            [fanboyOwnerState($me, $b / 1e9 + 40.0), fanboyOwnerState($me, $b / 1e9 + 20.0)]);
        fanboyBeat();

        // another boot: the journal is no journal (the BIOS set everything anew)
        file_put_contents("$root/proc/sys/kernel/random/boot_id", "bbbbbbbb-0000-4000-8000-000000000002\n");
        same('fanboy write: after a boot the journal counts for nothing', [], fanboyJournal()['outputs']);
        file_put_contents("$root/proc/sys/kernel/random/boot_id", FANBOY_T_BOOT . "\n");
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== the control: zones, curves, up fast / down slowly

function testFanboyControl(): void
{
    $t = fanboyT('control');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        same('fanboy control: his fans only — the disk fan, the hub, the silent one (the CPU cooler and the empty connector not); the CPU cooler watched',
            [[fanboyTId(4), fanboyTId(5), fanboyTId(2)], [fanboyTId(1)]], [array_keys($c['fans']), array_keys($c['watch'])]);
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);
        // disks: parity 35 °C (disk1 asleep, disk2 33) → normal 20 % → floor: measured 25 % + 5 = 30 %; case: NVMe cool → quiet 0 % → 40 % (no table)
        same('fanboy control: steering — the disk fan at its floor (30 % = 77), hub and silent fan at 40 % (102) without a table, all fixed values',
            ['run', [77, 1], [102, 1], [102, 1]], [$c['phase'], fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyTOut($t, 2)]);
        same('fanboy control: the CPU cooler and the empty connector untouched (128, fixed — the BIOS\'s)', [[128, 1], [128, 1]], [fanboyTOut($t, 1), fanboyTOut($t, 3)]);
        $z = $c['zones']['disks'];
        same('fanboy control: the sleeping disk counts as asleep — not as cold; the warmest is the parity', [['parity' => 'ok', 'disk1' => 'asleep', 'disk2' => 'ok'], 'parity'],
            [$z['state'], $z['warmest']['id']]);
        $st = fanboyStatus($c);
        same('fanboy control: his status — Fanboy steers, two zones, no alarm', ['fanboy', null, 2, null], [$st['who'], $st['why'], count($st['zones']), $st['alarm']]);

        // Langsam (the default, 2026-11-20): disk2 at 44 °C asks 73 % — up 2 % a round from the next look at disks.ini (every 10 s)
        fanboyTDisks($t, 35, null, 44);
        fanboyTRounds($c, $now, $t, $spec, 6);
        $slow = fanboyTOut($t, 4)[0];
        check("fanboy control: warmer — Langsam: up 2 % a round, not at once (after 12 s: $slow, under 40 % = 102)", $slow >= 77 && $slow < 102 && $c['zones']['disks']['warmest']['id'] === 'disk2');
        fanboyTRounds($c, $now, $t, $spec, 30);
        same('fanboy control: … there within a minute (44 °C: 73 % = 186), not beyond', 186, fanboyTOut($t, 4)[0]);
        // 43 °C: still within the gap — stays
        fanboyTDisks($t, 35, null, 43);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy control: a degree cooler, within the gap of 3 °C — stays (no hunting)', 186, fanboyTOut($t, 4)[0]);
        // 35 °C: down — after 30 s, a percent a round
        fanboyTDisks($t, 35, null, 35);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy control: cooler by more than the gap — held at first (30 s)', 186, fanboyTOut($t, 4)[0]);
        fanboyTRounds($c, $now, $t, $spec, 15);
        $after = fanboyTOut($t, 4)[0];
        check("fanboy control: … then down slowly, a percent a round (after 42 s: $after, between 73 % and 63 %)", $after < 186 && $after >= 160);
        fanboyTRounds($c, $now, $t, $spec, 60);
        same('fanboy control: … until it reaches the floor again (77), never below', 77, fanboyTOut($t, 4)[0]);
        // nothing warm to go by: every disk asleep → the curve's start (its quiet level), floor 30 %
        fanboyTDisks($t, null, null, null);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('fanboy control: every disk asleep — the quiet level (its floor), no alarm', [77, null, 3], [fanboyTOut($t, 4)[0], $c['alarm'], count(array_filter($c['zones']['disks']['state'], fn ($s) => $s === 'asleep'))]);

        // «off» allowed for the measured fan: below its floor it may stand — and it starts again with its start value first
        $s2 = $settings;
        $s2['fans'][fanboyTId(4)]['off_ok'] = true;
        // round 12: standing only for a fan whose measuring found it stops and starts again (here at 30 %)
        $s2['fans'][fanboyTId(4)]['measured'] += ['stops' => true, 'floor_rpm' => null, 'start_from_stop' => 30, 'kick_start' => null, 'stops_safe' => true];
        $s2['zones'][0]['target'] = ['points' => [[30, 0], [40, 0], [45, 60], [50, 100]]];
        fanboyLetGo($c, 'test');
        $c = fanboyControlNew($s2, FANBOY_T_KEY, fanboyMe('control'));
        fanboyTDisks($t, 35, null, 35);
        fanboyTRounds($c, $now, $t, $spec, 3);
        same('fanboy control: «off» allowed and the curve says 0 — the fan stands (0)', 0, fanboyTOut($t, 4)[0]);
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy control: … standing on purpose is no alarm', null, $c['alarm']);
        fanboyTDisks($t, 35, null, 42);
        fanboyTRounds($c, $now, $t, $spec, 6);
        $kicked = $c['fans'][fanboyTId(4)]['kick_until'] !== null || fanboyTOut($t, 4)[0] >= 77;
        check('fanboy control: … warmer: it starts with its start value (30 %) at least', $kicked);
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy control: … then its curve (42 °C: 24 %, its floor 30 %)', 77, fanboyTOut($t, 4)[0]);

        // rpm: a zone in rpm (measured fan): steered by the table, corrected until the rpm fits
        fanboyLetGo($c, 'test');
        $s3 = $settings;
        $s3['zones'] = [['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4)], 'unit' => 'rpm', 'target' => ['points' => [[30, 1000], [50, 1000]]],
                         'sources' => [['kind' => 'disk', 'id' => 'parity']]]];
        $c = fanboyControlNew($s3, FANBOY_T_KEY, fanboyMe('control'));
        $spec2 = fanboyTSpec(10, [4 => ['max' => 1450]]);          // the fan turns slower than measured: the correction makes up for it
        fanboyTRounds($c, $now, $t, $spec2, 40);
        $rpm = fanboyReadInt("{$t['hw']}/fan4_input");
        check("fanboy control: an rpm zone — 1000 rpm asked, the fan slower than its table: corrected to $rpm rpm (within 5 %)", abs($rpm - 1000) <= 50);
        fanboyLetGo($c, 'end');
        same('fanboy control: let go — every output as before him', [[128, 1], [128, 1], [128, 1]], [fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyTOut($t, 2)]);

        // the history: a point a minute per zone, bounded
        $h = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        for ($i = 0; $i < FANBOY_HISTORY_MAX + 30; $i++) {
            fanboyHistory($h, 5000.0 + $i * FANBOY_HISTORY_EVERY);
        }
        same('fanboy control: the history — a point a minute per zone, never more than the bound (round 3: 12 h of minutes, then 5-minute spans)', [FANBOY_HISTORY_MAX, FANBOY_HISTORY_MAX, 4],
            [count($h['hist']['disks']), count($h['hist']['case']), count($h['hist']['disks'][0])]);
        fanboyHistory($h, 5000.0 + (FANBOY_HISTORY_MAX + 30) * FANBOY_HISTORY_EVERY - 10);
        same('fanboy control: … never two in the same minute', FANBOY_HISTORY_MAX, count($h['hist']['disks']));
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== alarms: stands, hot, sensor, someone else, another controller

function testFanboyAlarms(): void
{
    $t = fanboyT('alarms');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 2000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);

        // a fan stands though it should turn (one fan of the hub): after 15 s ITS zone at 100 % (round 4, the owner: the hub
        // and the silent top fan of «Case»), the other zone steers on, told once
        $stuck = fanboyTSpec(10, [6 => ['stuck' => true]]);
        fanboyTRounds($c, $now, $t, $stuck, 4);
        same('fanboy alarms: one fan of the hub stands — not yet (under 15 s)', null, $c['alarm']);
        fanboyTRounds($c, $now, $t, $stuck, 6);
        same('fanboy alarms: … 15 s — alarm «stands», its zone at 100 % (hub and top fan: 255), the disk zone keeps its level, the CPU cooler not his',
            ['stands', 'Front', [77, 1], [255, 1], [255, 1], [128, 1]],
            [$c['alarm']['code'] ?? null, $c['alarm']['params']['fan'] ?? null, fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyTOut($t, 2), fanboyTOut($t, 1)]);
        fanboyTRounds($c, $now, $t, $stuck, 10);
        same('fanboy alarms: … told once (an alert), however long it stands', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i alert'))));
        fanboyTRounds($c, $now, $t, $spec, 30);
        same('fanboy alarms: it turns again — its zone still at 100 % for 2 minutes', [255, 'stands'], [fanboyTOut($t, 5)[0], $c['alarm']['code'] ?? null]);
        fanboyTRounds($c, $now, $t, $spec, 40);
        same('fanboy alarms: … then over: the curve again', [null, 77, 102], [$c['alarm'], fanboyTOut($t, 4)[0], fanboyTOut($t, 5)[0]]);

        // a BIOS fan he only watches stands (the CPU cooler): an alarm told — no output of his driven for it (round 4); the
        // cooler itself untouched
        $cpu = fanboyTSpec(10, [1 => ['stuck' => true]]);
        fanboyTRounds($c, $now, $t, $cpu, 12);
        same('fanboy alarms: the CPU cooler stands — alarm «bios_stands» told, his zones steer on, the cooler\'s output untouched',
            ['bios_stands', 77, 102, [128, 1]], [$c['alarm']['code'] ?? null, fanboyTOut($t, 4)[0], fanboyTOut($t, 5)[0], fanboyTOut($t, 1)]);
        fanboyTRounds($c, $now, $t, $spec, 70);
        same('fanboy alarms: … over two minutes after it turns again', null, $c['alarm']);

        // 2026-11-20 (the owner: no «Grenze» — the curve decides alone): the parity far over the disks' old limit (55 °C) on
        // the zone's curve — the curve's value (100 % from 48 °C), and no alarm however long
        fanboyTDisks($t, 58, null, 33);
        fanboyTRounds($c, $now, $t, $spec, 50);
        same('fanboy alarms: the parity at 58 °C (over the disks\' old limit of 55) — the curve\'s 100 %, reached by its way up, and no alarm after 100 s', [255, null], [fanboyTOut($t, 4)[0], $c['alarm']]);
        fanboyTDisks($t, 35, null, 33);
        fanboyTRounds($c, $now, $t, $spec, 120);
        same('fanboy alarms: … cool again: the zone back at its floor', [null, 77], [$c['alarm'], fanboyTOut($t, 4)[0]]);

        // a sensor that says nothing (the NVMe's file gone): 30 s, then alarm «sensor»
        $nv = glob("{$t['root']}/sys/devices/pci0000:00/0000:00:06.1/0000:03:00.0/nvme/*/hwmon/hwmon*/temp1_input")[0];
        rename($nv, "$nv.away");
        fanboyTRounds($c, $now, $t, $spec, 10);
        same('fanboy alarms: the NVMe says nothing — under 30 s: no alarm', null, $c['alarm']);
        fanboyTRounds($c, $now, $t, $spec, 8);
        same('fanboy alarms: … 30 s — alarm «sensor», its zone («Case») at 100 %, the disk zone keeps its level', ['sensor', 255, 77], [$c['alarm']['code'] ?? null, fanboyTOut($t, 5)[0], fanboyTOut($t, 4)[0]]);
        rename("$nv.away", $nv);
        fanboyTRounds($c, $now, $t, $spec, 80);
        same('fanboy alarms: … back: over', null, $c['alarm']);

        // someone else writes an output he holds: that output given back to the BIOS, told once, never taken again here
        file_put_contents("{$t['hw']}/pwm5_enable", "2\n");
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy alarms: someone else changed the hub\'s mode — given back (128, fixed: its state before him), not his any more',
            [[128, 1], false, true], [fanboyTOut($t, 5), $c['fans'][fanboyTId(5)]['taken'], $c['fans'][fanboyTId(5)]['foreign']]);
        file_put_contents("{$t['hw']}/pwm5", "60\n");
        fanboyTRounds($c, $now, $t, $spec, 5);
        same('fanboy alarms: … whatever the other one writes now stays (he doesn\'t fight)', [60, 1], fanboyTOut($t, 5));
        same('fanboy alarms: … told once (a warning)', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning'))));
        // another controller runs: everything back to the BIOS, held
        $c['hold'] = 'other';
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy alarms: another controller runs — every output back, nothing held, the status says why',
            [[128, 1], [128, 1], [], 'bios', 'other'], [fanboyTOut($t, 4), fanboyTOut($t, 2), fanboyHeld(), fanboyStatus($c)['who'], fanboyStatus($c)['why']]);
        // the chip gone while steering: nothing can be written there — back with the BIOS, alarm «chip», looked for again
        $c['hold'] = null;
        fanboyTRounds($c, $now, $t, $spec, 2);
        exec('rm -rf ' . escapeshellarg("{$t['root']}/sys/class/hwmon/hwmon10") . ' ' . escapeshellarg("{$t['root']}/sys/devices/platform/" . FANBOY_T_ADDR));
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy alarms: the chip gone — alarm «chip», nothing held, waiting for it (round 5: «chip_gone» — the fans stay where he left them)', ['chip', [], 'wait_chip', 'chip_gone'],
            [$c['alarm']['code'] ?? null, fanboyHeld(), $c['phase'], fanboyStatus($c)['why']]);
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== the chip missing: retried, told once after 15 minutes

function testFanboyChipMissing(): void
{
    // a tree without the fan chip (the driver missing after a kernel update)
    $board = fanboyFakeBoard();
    $noChip = array_values(array_filter($board['chips'], fn ($c) => $c[1] !== FANBOY_T_CHIP));
    $t = fanboyT('missing', 10, FANBOY_T_ADDR, ['chips' => $noChip]);
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 0.0;
        $looks = [];
        $detects = 0;
        while ($now <= 16 * 60) {
            $was = $c['missing']['next'] ?? -1;
            fanboyRound($c, $now);
            if (($c['missing']['next'] ?? -1) !== $was) {
                $looks[] = $now;
            }
            $now += 1.0;
        }
        $gaps = [];
        for ($i = 1; $i < count($looks); $i++) {
            $gaps[] = $looks[$i] - $looks[$i - 1];
        }
        same('fanboy missing: no chip — waiting, nothing held, the status says why', ['wait_chip', 'missing', [], 'bios', 'chip_missing'],
            [$c['phase'], $c['missing']['why'], fanboyHeld(), fanboyStatus($c)['who'], fanboyStatus($c)['why']]);
        same('fanboy missing: looked for every 5 s for two minutes, then every minute', [5.0, 5.0, 60.0],
            [$gaps[0], $gaps[22] ?? null, $gaps[30] ?? null]);
        $told = array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning'));
        same('fanboy missing: after 15 minutes ONE notification (on the tests\' clock), not more', 1, count($told));
        $quiet = [];
        while ($now <= 40 * 60) {
            $was = $c['missing']['next'];
            fanboyRound($c, $now);
            if ($c['missing']['next'] !== $was) {
                $quiet[] = $now;
            }
            $now += 1.0;
        }
        same('fanboy missing: … still one after 40 minutes; a quiet look every 5 minutes', [1, [300.0, 300.0]],
            [count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning'))), [$quiet[2] - $quiet[1], $quiet[3] - $quiet[2]]]);
        // «look now»: at once
        $c['look_now'] = true;
        $before = $c['missing']['next'];
        fanboyRound($c, $now);
        check('fanboy missing: «look now» looks at once (and plans the next look)', $c['missing']['next'] > $now && $c['missing']['next'] !== $before);
        // the driver comes (the plugin's update) — the chip at another hwmon number, another address even: found, taken over, told once
        $t2 = fanboyT('missing-back', 12, 'it87.2624');
        fanboyTDisks($t2, 35, null, 33);
        fanboyTStore($settings);
        putenv("OFFICE_NOTIFY_BIN={$t['tmp']}/notify");
        $c['look_now'] = true;
        fanboyRound($c, $now);
        same('fanboy missing: the chip came (hwmon12, address it87.2624 — the only one of its name): steering, the move noted',
            ['run', [77, 1], true], [$c['phase'], fanboyTOut($t2, 4), (bool) array_filter($c['notes'], fn ($n) => $n['code'] === 'moved')]);
        same('fanboy missing: … told once that he steers now (normal)', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i normal'))));
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== boards: the number changes, another board, back again

function testFanboyBoards(): void
{
    $t = fanboyT('boards', 12);        // this boot the chip is hwmon12 (the store never knew a number)
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 0.0;
        fanboyBeat();
        fanboyTRounds($c, $now, $t, fanboyTSpec(12), 3);
        same('fanboy boards: the chip at another number this boot — found by chip and address, steered there', ['run', [77, 1]], [$c['phase'], fanboyTOut($t, 4)]);
        fanboyLetGo($c, 'end');
        // another board: none of the settings counts; nothing is discarded
        $other = fanboyT('boards-other', 10, FANBOY_T_ADDR, ['board' => ['Other Maker', 'OM-1']]);
        fanboyTStore($settings);
        $before = (string) file_get_contents(fanboyStoreFile());
        same('fanboy boards: another board — no settings here (a new setup), the old board\'s kept', [null, 1], [fanboyBoardRaw(), fanboySetupState(fanboyDetect(), null)['others']]);
        fanboyStoreUpdate(function (array $e): array {
            $e['fans']['x:y:pwm1'] = ['chip' => 'x', 'address' => 'y', 'output' => 1, 'tach' => null, 'found' => 'ask'];
            return $e;
        });
        $store = readJson(fanboyStoreFile());
        same('fanboy boards: … a setup on the new board leaves the old one exactly as it was', [json_decode($before, true)['boards'][FANBOY_T_KEY], ['x:y:pwm1']],
            [$store['boards'][FANBOY_T_KEY], array_keys($store['boards']['Other Maker | OM-1']['fans'])]);
        // the old board back
        putenv("OFFICE_FANBOY_ROOT={$t['root']}");
        same('fanboy boards: the old board back — its settings count again', [true, 2], [fanboyBoardRaw()['armed'] ?? null, count(fanboyBoardRaw()['zones'] ?? [])]);
        unset($other);
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== the setup's steps (test up, measure down) on the tests' clock

/** A step's clock, sleep and signs on the tests' clock; the fans answer while it «sleeps»; $stopAt: the page gone then */
/**
 * The tests' step io: their clock, the fake fans answering, the page gone at $stopAt — and a user who answers «next»
 * (nothing said about hearing) after FANBOY_T_ANSWER s at full when no step.next of the test's own came first (a test
 * stays at full until it is answered since 2026-11-20); $answerAfter null: a user who never answers
 */
const FANBOY_T_ANSWER = 60.0;
function fanboyTIo(array $t, array &$spec, float &$clock, ?float $stopAt = null, ?float $answerAfter = FANBOY_T_ANSWER): array
{
    return [
        'now'    => function () use (&$clock): float { return $clock; },
        'sleep'  => function (float $s) use (&$clock, $t, &$spec): void { $clock += $s; fanboyFakeRespond($t['root'], $spec, $s); },
        'ok'     => function () use (&$clock, $stopAt): ?string { return $stopAt !== null && $clock >= $stopAt ? 'page_gone' : null; },
        'note'   => fn (array $p) => null,
        'answer' => fn (string $id, ?string $stepId, float $held): ?array => fanboyStepNextAsked($id, $stepId)
            ?? ($answerAfter !== null && $held >= $answerAfter ? ['heard' => null] : null),
    ];
}

function testFanboySteps(): void
{
    $t = fanboyT('steps');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyTSpec();
        file_put_contents("{$t['hw']}/pwm6", "255\n");            // output 6 runs at full already: up can show nothing
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $io = fanboyTIo($t, $spec, $clock);
        $ids = array_map('fanboyTId', [1, 2, 3, 4, 5, 6]);
        $step = fanboyStepRun(['id' => 's1', 'kind' => 'test_up', 'fans' => $ids], $io);
        $found = array_map(fn ($r) => [$r['found'], $r['tachs']], $step['results']);
        same('fanboy steps: test up — output 1 → tach 1, 2 and 3: none (ask), 4 → tach 4, 5 → tach 5 and 6 (a hub), 6 ran at full already (unclear)',
            [$ids[0] => ['tach', [1]], $ids[1] => ['ask', []], $ids[2] => ['ask', []], $ids[3] => ['tach', [4]], $ids[4] => ['tach', [5, 6]], $ids[5] => ['unclear', []]], $found);
        same('fanboy steps: … every output exactly as before (128 fixed; 6: 255), nothing held, the step done',
            [[128, 1], [128, 1], [128, 1], [128, 1], [128, 1], [255, 1], [], 'done'],
            [fanboyTOut($t, 1), fanboyTOut($t, 2), fanboyTOut($t, 3), fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyTOut($t, 6), fanboyHeld(), $step['status']]);
        $fans = fanboyBoardRaw()['fans'];
        same('fanboy steps: … kept in the store per output (chip, address, output, the tach that follows, all of them)',
            [['tach' => 5, 'tachs' => [5, 6], 'found' => 'tach'], 'ask'],
            [array_intersect_key($fans[$ids[4]], ['found' => 1, 'tach' => 1, 'tachs' => 1]), $fans[$ids[1]]['found']]);

        // «did something get louder?» yes: a fan without feedback; no: the connector is empty
        same('fanboy steps: louder? yes — silent; no — empty', ['silent', 'empty'],
            [fanboyAnswer(['fan' => $ids[1], 'louder' => true])['fan']['found'], fanboyAnswer(['fan' => $ids[2], 'louder' => false])['fan']['found']]);
        try {
            fanboyAnswer(['fan' => $ids[1], 'louder' => 'yes']);
            check('fanboy steps: «louder» only as true/false', false);
        } catch (Problem $p) {
            same('fanboy steps: «louder» only as true/false — a string refused', 'bad_request', $p->key);
        }
        // roles: CPU cooler stays the BIOS's; measuring it refused; the disk fan given to him
        fanboyRole(['fan' => $ids[0], 'role' => 'cpu', 'name' => 'CPU']);
        fanboyRole(['fan' => $ids[3], 'role' => 'disks', 'name' => 'Cage', 'place' => 'front']);
        fanboyRole(['fan' => $ids[4], 'role' => 'case', 'name' => 'Front', 'place' => 'front']);
        $refused = [];
        foreach ([['fan' => $ids[3], 'role' => 'boss'], ['fan' => $ids[3], 'role' => 'case', 'place' => 'middle'], ['fan' => $ids[3], 'role' => 'case', 'off_ok' => true],
                  ['fan' => $ids[2], 'role' => 'case'], ['fan' => $ids[3], 'role' => 'case', 'name' => str_repeat('x', 41)]] as $r) {
            try {
                fanboyRole($r);
                $refused[] = 'kept';
            } catch (Problem $p) {
                $refused[] = $p->key;
            }
        }
        same('fanboy steps: roles refused — unknown, a place not known, «off» before measuring, an empty connector, a name too long',
            ['fanboy_bad_role', 'fanboy_bad_place', 'fanboy_off_needs_measure', 'fanboy_no_output', 'fanboy_bad_name'], $refused);
        $codes = [];
        foreach ([[$ids[0]], [$ids[1]]] as $fl) {
            try {
                fanboyStepStart('measure', $fl);
                $codes[] = 'started';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy steps: measuring down refused for the CPU cooler and for a fan without feedback', ['fanboy_not_his', 'fanboy_not_his'], $codes);
        same('fanboy steps: … nothing started for them', [], $GLOBALS['fanboyLaunched']);

        // measure the disk fan: full, then down; it stalls at 15 % — measuring stops, back up: it starts again at 30 %
        $step = fanboyStepRun(['id' => 's2', 'kind' => 'measure', 'fans' => [$ids[3]]], $io);
        $m = $step['results'][$ids[3]];
        same('fanboy steps: measured — table from 100 % down, lowest steady 20 %, stalled at 15 %, start value 30 %', ['ok', 20, 15, 30, 1600],
            [$m['result'], $m['min_pct'] ?? null, $m['stalled_at'] ?? null, $m['start_pct'] ?? null, $m['max_rpm'] ?? null]);
        same('fanboy steps: … the table: % ↔ rpm, falling (100 %: 1600, 80 %: 1344, 20 %: 576)', [[100, 1600], [80, 1344], [20, 576]],
            array_values(array_filter($m['table'], fn ($p) => in_array($p[0], [100, 80, 20], true))));
        same('fanboy steps: … the fan back exactly as before (128, fixed), nothing held', [[128, 1], []], [fanboyTOut($t, 4), fanboyHeld()]);
        same('fanboy steps: … kept in the store', [20, 30], [fanboyBoardRaw()['fans'][$ids[3]]['measured']['min_pct'] ?? null, fanboyBoardRaw()['fans'][$ids[3]]['measured']['start_pct'] ?? null]);
        // the page gone in the middle: everything back at once, the step «stopped»
        $clock = 0.0;
        $io2 = fanboyTIo($t, $spec, $clock, 20.0);
        $step = fanboyStepRun(['id' => 's3', 'kind' => 'measure', 'fans' => [$ids[3]]], $io2);
        same('fanboy steps: the page gone while measuring — stopped, the fan back (128, fixed) at once, nothing held, the earlier result kept',
            ['stopped', 'page_gone', [128, 1], [], 20], [$step['status'], $step['why'], fanboyTOut($t, 4), fanboyHeld(), fanboyBoardRaw()['fans'][$ids[3]]['measured']['min_pct'] ?? null]);
        // too warm: stops, back
        $clock = 0.0;
        file_put_contents("{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input", "90000\n");
        $step = fanboyStepRun(['id' => 's4', 'kind' => 'measure', 'fans' => [$ids[3]]], fanboyTIo($t, $spec, $clock));
        same('fanboy steps: too warm while measuring (CPU 90 °C) — stopped, the fan back', ['stopped', 'warm', [128, 1], []], [$step['status'], $step['why'], fanboyTOut($t, 4), fanboyHeld()]);
        file_put_contents("{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input", "28000\n");
        // a step beside the control: refused; a second step: refused
        file_put_contents(fanboyRunDir() . '/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        $codes = [];
        try {
            fanboyStepStart('test_up', [$ids[4]]);
        } catch (Problem $p) {
            $codes[] = $p->key;
        }
        unlink(fanboyRunDir() . '/control.pid');
        fanboyStepWrite(['kind' => 'test_up', 'fans' => [], 'status' => 'running', 'pid' => getmypid(), 'start' => fanboyProcStart(getmypid())]);
        try {
            fanboyStepStart('test_up', [$ids[4]]);
        } catch (Problem $p) {
            $codes[] = $p->key;
        }
        same('fanboy steps: never beside the control, never two at once', ['fanboy_steering', 'fanboy_step_running'], $codes);
        // a step whose process is gone: «stopped (interrupted)»
        fanboyStepWrite(['kind' => 'measure', 'fans' => [], 'status' => 'running', 'pid' => 999999, 'start' => '1']);
        same('fanboy steps: a step whose process is gone reads as interrupted', ['stopped', 'interrupted'], [fanboyStepRead()['status'], fanboyStepRead()['why']]);
        @unlink(fanboyStepFile());
        $r = fanboyStepStart('test_up', [$ids[4]]);
        same('fanboy steps: a step started — written as «starting», its process launched, the page\'s lease fresh', ['starting', ['fanboy-step'], true],
            [$r['step']['status'], $GLOBALS['fanboyLaunched'], fanboyStepAlive()]);
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== zones: proposed, checked, saved; the dry run; take over

function testFanboyZones(): void
{
    $t = fanboyT('zones');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        unset($settings['zones'], $settings['armed']);
        fanboyTStore($settings);
        $scan = fanboyDetect();
        $prop = fanboyZonesPropose($scan, $settings);
        // round 4: the hard disks in «disks», the NVMe pool (cache: not rotating) in «case» on the SSD's own scale
        same('fanboy zones: proposed — «disks» (the disk fan, the hard disks), «case» (hub and silent fan, the SSD pool, NVMe, RAM, board)',
            [['disks', [fanboyTId(4)], ['parity', 'disk1', 'disk2']], ['case', [fanboyTId(2), fanboyTId(5)]]],
            [[$prop[0]['id'], $prop[0]['fans'], array_column($prop[0]['sources'], 'id')], [$prop[1]['id'], array_values(array_diff($prop[1]['fans'], []))]]);
        check('fanboy zones: … the case zone\'s sources: the SSD pool, NVMe, RAM, the board — no CPU, no hard disk', !array_diff(array_unique(array_column($prop[1]['sources'], 'kind')), ['ssd', 'nvme', 'ram', 'board'])
            && in_array('cache', array_column($prop[1]['sources'], 'id'), true));
        $good = [['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4)], 'sources' => [['id' => 'parity'], ['id' => 'disk2', 'limit' => 50]], 'unit' => 'pct', 'target' => ['preset' => 'quiet']]];
        same('fanboy zones: a zone checked — the kind from the source; a source\'s own limit not kept (2026-11-20: each kind\'s own)', [['kind' => 'disk', 'id' => 'disk2', 'low' => null, 'high' => null, 'limit' => null]],
            [fanboyZonesCheck($good, $scan, $settings)[0]['sources'][1]]);
        $bad = [
            'id'      => [['id' => 'Disks!'] + $good[0]],
            'cpu fan' => [['fans' => [fanboyTId(1)]] + $good[0]],
            'twice'   => [$good[0], ['id' => 'two', 'name' => 'Two'] + $good[0]],
            'source'  => [['sources' => [['id' => 'disk9']]] + $good[0]],
            'drivetemp' => [['sources' => [['id' => 'drivetemp@x#1']]] + $good[0]],
            'string'  => [['target' => ['points' => [[30, '20'], [50, 100]]]] + $good[0]],
            'points'  => [['target' => ['points' => [[40, 50]]]] + $good[0]],
            'rpm'     => [['unit' => 'rpm', 'target' => ['points' => [[30, 800], [50, 1500]]], 'fans' => [fanboyTId(5)]] + $good[0]],
            'preset'  => [['target' => ['preset' => 'loud']] + $good[0]],
            'no name' => [['name' => ' '] + $good[0]],
        ];
        $why = [];
        foreach ($bad as $k => $z) {
            try {
                fanboyZonesCheck($z, $scan, $settings);
                $why[$k] = 'kept';
            } catch (Problem $p) {
                $why[$k] = $p->key;
            }
        }
        same('fanboy zones: refused — a bad id, the CPU cooler, a fan twice, a source not there, drivetemp, a number as text, one point, rpm without measuring, a preset not known, no name (2026-11-20: what a page can send, each with its own sentence)',
            array_merge(array_fill_keys(array_keys($bad), 'fanboy_bad_zones'), ['rpm' => 'fanboy_rpm_needs_measure', 'cpu fan' => 'fanboy_zone_fan_gone', 'twice' => 'fanboy_zone_fan_twice',
                'source' => 'fanboy_zone_source_gone', 'drivetemp' => 'fanboy_zone_source_gone', 'no name' => 'fanboy_zone_name']), $why);
        fanboyZonesSave(['zones' => $good]);
        same('fanboy zones: saved', ['disks'], array_column(fanboyBoardRaw()['zones'], 'id'));
        // the dry run: what he would write — nothing written, nothing held
        $before = fanboyTreeHash($t['root']);
        $dry = fanboyDryRun()['dry'];
        same('fanboy zones: the dry run — would write 30 % (77) to the disk fan (quiet 0 % → its floor), says what the output has now; writes nothing',
            [true, 30, 77, ['value' => 128, 'enable' => 1], $before, []],
            [$dry['found'], $dry['zones'][0]['fans'][0]['pct'], $dry['zones'][0]['fans'][0]['value'], $dry['zones'][0]['fans'][0]['now'], fanboyTreeHash($t['root']), fanboyHeld()]);
        // take over: armed, the control launched; refused while another controller runs
        fanboyTakeOver();
        same('fanboy zones: taken over — armed, the control started', [true, ['fanboy-control']], [fanboyBoardRaw()['armed'], $GLOBALS['fanboyLaunched']]);
        @mkdir("{$t['root']}/boot/config/plugins/fanctrlplus", 0700, true);
        file_put_contents("{$t['root']}/boot/config/plugins/fanctrlplus/fanctrlplus_x.cfg", "service=\"1\"\ncontroller=\"/sys/x/pwm1\"\n");
        file_put_contents("{$t['root']}/boot/config/plugins/fanctrlplus.plg", "<PLUGIN/>\n");      // round 7: installed
        try {
            fanboyTakeOver();
            $code = 'kept';
        } catch (Problem $p) {
            $code = $p->key;
        }
        same('fanboy zones: … refused while FanCtrl Plus is switched on (only one steers)', 'fanboy_other', $code);
        unlink("{$t['root']}/boot/config/plugins/fanctrlplus/fanctrlplus_x.cfg");
        $h = fanboyHandBack();
        same('fanboy zones: handed back — not armed', [true, false], [$h['ok'], fanboyBoardRaw()['armed']]);
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== real processes: killed hard, hung, released, the array stopping

/** A php of the tests' own running $call after loading the agent as a library (its env: this test's tree, data, RAM) */
function fanboyTProc(array $t, string $call, string $data)
{
    $code = 'define("AGENT_LIBRARY_ONLY", 1); define("OFFICE_WEB", ' . var_export(OFFICE_WEB, true) . '); require ' . var_export(OFFICE_DIR . '/agent/agent.php', true) . "; exit($call);";
    $env = ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'OFFICE_NOTIFY_BIN' => "{$t['tmp']}/notify",
            'OFFICE_NOTIFY_STAMP' => "{$t['tmp']}/notify.second", 'PATH' => (string) getenv('PATH'), 'TZ' => 'UTC'];
    return proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$t['tmp']}/proc.out", 'a'], 2 => ['file', "{$t['tmp']}/proc.out", 'a']], $pipes, '/', $env);
}

/** Waits (real time, monotonic) until $ok() or $seconds passed */
function fanboyTWait(callable $ok, float $seconds): bool
{
    $until = hrtime(true) + $seconds * 1e9;
    while (hrtime(true) < $until) {
        clearstatcache();
        if ($ok()) {
            return true;
        }
        usleep(100000);
    }
    return $ok();
}

/** The guard as agent.sh and the cron line run it */
function fanboyTGuard(array $t, string $data, string $cmd, string ...$more): string
{
    $p = proc_open([PHP_BINARY, OFFICE_DIR . '/agent/fanboy-guard.php', $cmd, ...$more], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/',
        ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'PATH' => (string) getenv('PATH')]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);
    return (string) $out;
}

function testFanboyProcesses(): void
{
    $t = fanboyT('procs');
    $responder = null;
    $procs = [];
    try {
        fanboyTDisks($t, 35, null, 33);
        // a data folder of the processes' own: he is hired there, his settings armed
        $data = "{$t['tmp']}/data";
        @mkdir("$data/fanboy", 0700, true);
        @mkdir("$data/office", 0700, true);
        file_put_contents("$data/office/staff.json", json_encode(['hired' => ['fanboy' => ['at' => 1]]]));
        file_put_contents("$data/fanboy/settings.json", json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => fanboyTSettings()]], JSON_UNESCAPED_SLASHES));
        file_put_contents("{$t['tmp']}/spec.json", json_encode(fanboyTSpec()));
        $responder = proc_open([PHP_BINARY, OFFICE_DIR . '/tests/fanboy-responder.php', $t['root'], "{$t['tmp']}/spec.json"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $start = function () use ($t, $data, &$procs): ?int {
            @unlink(fanboyRunDir() . '/control.pid');
            $procs[] = fanboyTProc($t, 'fanboyControlMain()', $data);
            $ok = fanboyTWait(fn () => fanboyControlPid() !== null && fanboyTOut($t, 4)[0] === 77 && count(fanboyHeld()) === 3, 15);
            return $ok ? fanboyControlPid() : null;
        };
        // 1. killed hard — the guard's cron look gives everything back (the agent gone too)
        $pid = $start();
        check('fanboy procs: the control runs in a process of its own and steers (77 on the disk fan)' . ($pid ? '' : ': ' . @file_get_contents("{$t['tmp']}/proc.out")), $pid !== null);
        // an agent restarting itself (in place, or its supervisor starting it anew): its tick finds the running control and
        // touches nothing; a second start of the control ends at once (one at a time) — no fan flaps
        $journal = (string) file_get_contents(fanboyRunDir() . '/journal.json');
        $w = fanboyWatch();
        $second = fanboyTProc($t, 'fanboyControlMain()', $data);
        $secondEnded = fanboyTWait(fn () => !proc_get_status($second)['running'], 10);
        proc_close($second);
        same('fanboy procs: an agent restart — the tick leaves the running control alone, a second control ends at once, nothing written',
            [[], true, $pid, [77, 1], $journal], [$w, $secondEnded, fanboyControlPid(), fanboyTOut($t, 4), (string) file_get_contents(fanboyRunDir() . '/journal.json')]);
        posix_kill((int) $pid, 9);
        fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        $out = fanboyTGuard($t, $data, 'watch');
        same('fanboy procs: killed hard — the guard\'s look (the cron line, every minute) gives every output back, read back',
            [[128, 1], [128, 1], [128, 1], []], [fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyTOut($t, 2), fanboyHeld()]);
        same('fanboy procs: … the page\'s status says: the BIOS, the control crashed', ['bios', 'crashed'],
            [readJson(fanboyStatusFile())['who'] ?? null, readJson(fanboyStatusFile())['why'] ?? null]);
        check('fanboy procs: … a line for the agent\'s log' . " ($out)", str_contains((string) @file_get_contents("$data/agent.log"), 'Fanboy: a holder was gone'));
        // 2. killed hard — the agent's tick gives it back (within 5 s)
        $pid = $start();
        posix_kill((int) $pid, 9);
        fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        $w = fanboyWatch();
        same('fanboy procs: killed hard — the agent\'s tick gives it back the same way', [3, [128, 1], []], [count($w), fanboyTOut($t, 4), fanboyHeld()]);
        // 3. hung: there, but no beat for 40 s — ended for good, then given back
        $pid = $start();
        posix_kill((int) $pid, SIGSTOP);
        file_put_contents(fanboyRunDir() . "/beat-$pid", (string) (hrtime(true) - 40_000_000_000));     // an integer (see above)
        fanboyTGuard($t, $data, 'watch');
        $gone = fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        same('fanboy procs: hung (stopped, no beat for 40 s) — ended for good, every output back', [true, [128, 1], []], [$gone, fanboyTOut($t, 4), fanboyHeld()]);
        // 4. agent.sh stop|release: the guard asks the control to end; it gives back itself; read back
        $pid = $start();
        $out = fanboyTGuard($t, $data, 'release');
        $gone = fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        same('fanboy procs: released (stop, update, removal, the array stopping) — the control ends, every output back, read back',
            [true, [128, 1], [128, 1], [], 'bios', 'released'], [$gone, fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyHeld(), readJson(fanboyStatusFile())['who'] ?? null, readJson(fanboyStatusFile())['why'] ?? null]);
        check("fanboy procs: … said for agent.sh's log: 3 outputs back ($out)", str_contains($out, 'released — 3 outputs back with the BIOS'));
        // 4b. (2026-11-22) the array's stop names its reason: the same release, the status says «the array is stopped»
        $pid = $start();
        $out = fanboyTGuard($t, $data, 'release', 'array_stopping');
        $gone = fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        same('fanboy procs: released at the array\'s stop — the control ends, every output back, the status says the array is stopped; the log line as ever',
            [true, [128, 1], [128, 1], [], 'bios', 'array_stopped', 'ended', true],
            [$gone, fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyHeld(), readJson(fanboyStatusFile())['who'] ?? null, readJson(fanboyStatusFile())['why'] ?? null,
             readJson(fanboyStatusFile())['phase'] ?? null, str_contains($out, 'released — 3 outputs back with the BIOS')]);
        // … any other word after «release» is no reason: released as ever (an older agent.sh passes none)
        $pid = $start();
        fanboyTGuard($t, $data, 'release', 'whatever');
        fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 5);
        same('fanboy procs: … an unknown word after «release»: released as ever', ['bios', 'released', []],
            [readJson(fanboyStatusFile())['who'] ?? null, readJson(fanboyStatusFile())['why'] ?? null, fanboyHeld()]);
        // 5. the array stops: the control sees it (within 10 s) and ends, giving back
        $pid = $start();
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Stopped\"\n");
        $gone = fanboyTWait(fn () => !is_dir("/proc/$pid") || str_contains((string) @file_get_contents("/proc/$pid/stat"), ') Z'), 15);
        same('fanboy procs: the array stopped — the control ends by itself, every output back; the BIOS runs them and the status says why',
            [true, [128, 1], [], 'array_stopped'], [$gone, fanboyTOut($t, 4), fanboyHeld(), readJson(fanboyStatusFile())['why'] ?? null]);
        file_put_contents("{$t['root']}/var/local/emhttp/var.ini", "fsState=\"Started\"\n");
        // 6. measuring, killed hard in the middle: the guard gives the fan back
        $fans = fanboyTSettings()['fans'];
        file_put_contents("$data/fanboy/settings.json", json_encode(['v' => 1, 'boards' => [FANBOY_T_KEY => ['fans' => $fans] + fanboyTSettings()]], JSON_UNESCAPED_SLASHES));
        fanboyStepAliveTouch();
        fanboyStepWrite(['id' => 'p1', 'kind' => 'measure', 'fans' => [fanboyTId(4)], 'status' => 'starting', 'pid' => null, 'start' => null, 'done' => [], 'results' => [], 'why' => null]);
        $procs[] = fanboyTProc($t, 'fanboyStepMain()', $data);
        $held = fanboyTWait(fn () => fanboyHeld() === [fanboyTId(4)] && fanboyTOut($t, 4)[0] !== 128, 15);
        $step = fanboyStepRead();
        check('fanboy procs: measuring in a process of its own — it holds the disk fan', $held && is_int($step['pid'] ?? null));
        posix_kill((int) $step['pid'], 9);
        fanboyTWait(fn () => !is_dir("/proc/{$step['pid']}") || str_contains((string) @file_get_contents("/proc/{$step['pid']}/stat"), ') Z'), 5);
        fanboyTGuard($t, $data, 'watch');
        same('fanboy procs: measuring killed hard — the fan back (128, fixed), the step reads «stopped (interrupted)»',
            [[128, 1], [], 'stopped', 'interrupted'], [fanboyTOut($t, 4), fanboyHeld(), fanboyStepRead()['status'] ?? null, fanboyStepRead()['why'] ?? null]);
        // 7. measuring, stopped by TERM (agent.sh stop): it gives back itself
        fanboyStepWrite(['id' => 'p2', 'kind' => 'measure', 'fans' => [fanboyTId(4)], 'status' => 'starting', 'pid' => null, 'start' => null, 'done' => [], 'results' => [], 'why' => null]);
        fanboyStepAliveTouch();
        $procs[] = fanboyTProc($t, 'fanboyStepMain()', $data);
        fanboyTWait(fn () => fanboyHeld() === [fanboyTId(4)], 15);
        fanboyTGuard($t, $data, 'release');
        fanboyTWait(fn () => (fanboyStepRead()['status'] ?? '') === 'stopped', 5);
        same('fanboy procs: measuring asked to end (TERM) — it gives the fan back itself and says «stopped»', [[128, 1], [], 'stopped', 'stopped'],
            [fanboyTOut($t, 4), fanboyHeld(), fanboyStepRead()['status'] ?? null, fanboyStepRead()['why'] ?? null]);
    } finally {
        foreach ($procs as $p) {
            if (is_resource($p)) {
                $s = proc_get_status($p);
                if ($s['running']) {
                    posix_kill($s['pid'], SIGCONT);
                    posix_kill($s['pid'], 9);
                }
                proc_close($p);
            }
        }
        @touch("{$t['root']}/responder.stop");
        if (is_resource($responder)) {
            proc_close($responder);
        }
        fanboyTEnd();
    }
}

// ===================================================================== «load drivers»: the list, refused, unloaded, remembered, undone

function testFanboyDrivers(): void
{
    // the fan chip there in the tree but not shown (its driver not loaded): the stand-in modprobe shows it on «it87»
    $t = fanboyT('drivers');
    try {
        $root = $t['root'];
        rename("$root/sys/class/hwmon/hwmon10", "$root/staged-hwmon10");
        exec('rm -rf ' . escapeshellarg("$root/sys/module/it87"));
        $log = "{$t['tmp']}/modprobe.log";
        file_put_contents("{$t['tmp']}/modprobe", "#!/bin/bash\nroot=" . escapeshellarg($root) . "\necho \"\$*\" >> " . escapeshellarg($log) . <<<'SH'

if [ "$1" = "-r" ]; then
    rm -rf "$root/sys/module/$2"
    [ "$2" = it87 ] && [ -L "$root/sys/class/hwmon/hwmon10" ] && mv "$root/sys/class/hwmon/hwmon10" "$root/staged-hwmon10"
    exit 0
fi
mode=$(cat "$root/mode-$1" 2>/dev/null || echo nochip)
case "$mode" in
    nochip) echo "modprobe: ERROR: could not insert '$1': No such device" >&2; exit 1 ;;
    nochipdeps) mkdir -p "$root/sys/module/${1}_core"; echo "modprobe: ERROR: could not insert '$1': No such device" >&2; exit 1 ;;
    acpi)   echo "modprobe: ERROR: could not insert '$1': Device or resource busy" >&2; exit 1 ;;
    nooutput) mkdir -p "$root/sys/module/$1"; exit 0 ;;
    outputs) mkdir -p "$root/sys/module/$1"; mv "$root/staged-hwmon10" "$root/sys/class/hwmon/hwmon10"; exit 0 ;;
esac
SH);
        chmod("{$t['tmp']}/modprobe", 0755);
        putenv("OFFICE_MODPROBE_BIN={$t['tmp']}/modprobe");
        file_put_contents("$root/mode-nct6775", 'nooutput');
        file_put_contents("$root/mode-it87", 'outputs');
        file_put_contents("$root/mode-nct6683", 'nochipdeps');
        same('fanboy drivers: before — no output shown', 0, fanboyDetect()['counts']['outputs']);
        $r = fanboyDriversLoad();
        same('fanboy drivers: tried in the list\'s order — nct6775 loads but brings no output (unloaded again), it87 brings 6: kept, the rest not tried',
            [['nct6775', 'no_output'], ['it87', 'kept']], array_map(fn ($s) => [$s['module'], $s['result']], $r['steps']));
        same('fanboy drivers: … modprobe called: nct6775, -r nct6775, it87', ['nct6775', '-r nct6775', 'it87'], array_values(array_filter(explode("\n", (string) file_get_contents($log)))));
        // as seen on test4 (Unraid 7.4.0): a refused try leaves its helper loaded — unloaded again
        file_put_contents($log, '');
        unlink("$root/mode-nct6775");
        rename("$root/sys/class/hwmon/hwmon10", "$root/staged-hwmon10");
        exec('rm -rf ' . escapeshellarg("$root/sys/module/it87"));
        file_put_contents("$root/mode-it87", 'nochip');
        fanboyDriversLoad();
        same('fanboy drivers: a refused try that pulled in a helper (nct6683_core) — the helper unloaded again, nothing new left loaded',
            [true, false], [in_array('-r nct6683_core', explode("\n", (string) file_get_contents($log)), true), is_dir("$root/sys/module/nct6683_core")]);
        file_put_contents("$root/mode-it87", 'outputs');
        unlink("$root/mode-nct6683");
        file_put_contents("$root/mode-nct6775", 'nooutput');
        fanboyDriversLoad();
        same('fanboy drivers: … remembered for this board, the outputs there now', [['it87'], 6], [fanboyDriversRead()['boards'][FANBOY_T_KEY]['modules'] ?? null, fanboyDetect()['counts']['outputs']]);
        try {
            fanboyDriversLoad();
            $code = 'kept';
        } catch (Problem $p) {
            $code = $p->key;
        }
        same('fanboy drivers: outputs shown already — nothing to load', 'fanboy_drivers_there', $code);
        // at the agent's start: loaded again when it isn't
        exec('rm -rf ' . escapeshellarg("$root/sys/module/it87"));
        rename("$root/sys/class/hwmon/hwmon10", "$root/staged-hwmon10");
        same('fanboy drivers: at the agent\'s start what helped is loaded again', [['it87' => true], 6], [fanboyDriversBoot(), fanboyDetect()['counts']['outputs']]);
        // undo: refused while he steers; else unloaded and forgotten
        file_put_contents(fanboyRunDir() . '/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        try {
            fanboyDriversUndo();
            $code = 'kept';
        } catch (Problem $p) {
            $code = $p->key;
        }
        unlink(fanboyRunDir() . '/control.pid');
        same('fanboy drivers: undo refused while he steers', 'fanboy_steering', $code);
        $u = fanboyDriversUndo();
        same('fanboy drivers: undo — it87 unloaded, forgotten, no output any more', [['it87' => true], [], 0],
            [$u['unloaded'], fanboyDriversRead()['boards'][FANBOY_T_KEY]['modules'] ?? [], fanboyDetect()['counts']['outputs']]);
        // the BIOS keeps the chip: said, nothing else done
        file_put_contents("$root/mode-it87", 'acpi');
        file_put_contents("$root/mode-nct6775", 'nochip');
        $r = fanboyDriversLoad();
        same('fanboy drivers: the kernel refuses (Device or resource busy) — «acpi» said, nothing kept, every other driver: no chip',
            [true, null, 'acpi', 'no_chip', count(FANBOY_DRIVERS)], [$r['acpi'], $r['kept'], $r['steps'][1]['result'], $r['steps'][0]['result'], count($r['steps'])]);
        same('fanboy drivers: … nothing written to the flash (no file under boot/config but the tree\'s own)', ['dynamix.system.temp', 'dynamix.system.temp.plg', 'it87-driver.plg'],
            array_values(array_diff(scandir("$root/boot/config/plugins"), ['.', '..'])));
        putenv('OFFICE_MODPROBE_BIN');
        // the list itself: every one in Unraid's own kernel (on an Unraid host: modinfo)
        if (is_file('/etc/unraid-version') && bin('modinfo') !== null) {
            $missing = array_values(array_filter(FANBOY_DRIVERS, fn ($m) => run(['modinfo', '-F', 'filename', $m], 10)[0] !== 0));
            same('fanboy drivers: every driver of the list is in this Unraid\'s kernel (modinfo)', [], $missing);
        }
    } finally {
        putenv('OFFICE_MODPROBE_BIN');
        fanboyTEnd();
    }
}

// ===================================================================== agent.sh: every stop gives back; the cron line

function testFanboyStopScripts(): void
{
    $sh = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh');
    check('fanboy stop: stop() ends with fanboy_release (stop, restart, the plugin\'s update)', preg_match('/^stop\(\) \{\n    night_stop\n    agent_stop\n    fanboy_release\n\}/m', $sh) === 1);
    check('fanboy stop: release() (removal, the array stopping) goes through stop()', preg_match('/^release\(\) \{\n    stop\b/m', $sh) === 1 && str_contains($sh, "        release             # everything the office holds on a pool"));
    // part 3: the guard's line is no longer in agent-watch.cron on every server — a file of his own, only while armed
    // (testFanboyGuardCron)
    check('fanboy stop: agent-watch.cron has the watch line only (the guard\'s line moved to fanboy-guard.cron, only while armed)',
        str_contains($sh, 'want="# Unraid Secretary Office - is the agent at work? (written by scripts/agent.sh)"$\'\n\'"$WATCH_LINE"' . "\n") && !str_contains($sh, 'FANBOY_LINE'));
    $t = fanboyT('stopsh');
    try {
        // the cron line's look: only a journal that names a holder — the holder a sleeping process of the test's (it gets the TERM)
        $sleeper = proc_open(['sleep', '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $spid = proc_get_status($sleeper)['pid'];
        $holder = ['kind' => 'control', 'pid' => $spid, 'start' => fanboyProcStart($spid), 'since' => time()];
        fanboyBeat($spid);
        fanboyTake(['id' => fanboyTId(4), 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => 4], $holder, FANBOY_T_KEY);
        fanboySet(fanboyTId(4), 250, $holder);
        $grep = fn () => run(['bash', '-c', 'grep -qs \'"owner":{\' "$1"', 'x', fanboyRunDir() . '/journal.json'], 10)[0];
        same('fanboy stop: the cron line\'s grep finds a holder', [0, [250, 1]], [$grep(), fanboyTOut($t, 4)]);
        // agent.sh's fanboy_release for real (its function, the repository's guard, this test's RAM folder and tree)
        $data = "{$t['tmp']}/data";
        @mkdir($data, 0700, true);
        $p = proc_open(['bash', '-c', 'source "$1"; DIR="$2"; RUN="$3"; FANBOY_JOURNAL="$3/fanboy/journal.json"; LOG="$4"; fanboy_release',
            'x', OFFICE_DIR . '/plugin/scripts/agent.sh', OFFICE_DIR, TESTS_RUN_DIR, "{$t['tmp']}/agent.sh.log"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes, '/', ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'PATH' => (string) getenv('PATH')]);
        proc_close($p);
        $s = proc_get_status($sleeper);
        same('fanboy stop: agent.sh\'s fanboy_release — the holder asked to end (TERM), every output back (128, fixed), nothing held, a line in its log', [false, [128, 1], [], true],
            [$s['running'], fanboyTOut($t, 4), fanboyHeld(), str_contains((string) @file_get_contents("{$t['tmp']}/agent.sh.log"), 'released — 1 outputs back with the BIOS')]);
        proc_close($sleeper);
        same('fanboy stop: … and after it the cron line\'s grep finds nobody', 1, $grep());
        // 2026-11-22: at the array's stop (array_event sets STOP_WHY) the guard hears why — the status says «the array is stopped»
        fanboyStatusWrite(['v' => 1, 'pid' => 4242, 'who' => 'fanboy', 'why' => null, 'phase' => 'steering']);
        $why = function (string $set) use ($t, $data): ?string {
            $p = proc_open(['bash', '-c', 'source "$1"; DIR="$2"; RUN="$3"; FANBOY_JOURNAL="$3/fanboy/journal.json"; LOG="$4"; ' . $set . ' fanboy_release',
                'x', OFFICE_DIR . '/plugin/scripts/agent.sh', OFFICE_DIR, TESTS_RUN_DIR, "{$t['tmp']}/agent.sh.log"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes, '/', ['OFFICE_DATA_DIR' => $data, 'OFFICE_RUN_DIR' => TESTS_RUN_DIR, 'OFFICE_FANBOY_ROOT' => $t['root'], 'PATH' => (string) getenv('PATH')]);
            proc_close($p);
            return readJson(fanboyStatusFile())['why'] ?? null;
        };
        // (2026-11-22, «always on»: at the array's stop the control steers on — agent.sh calls the guard's release-steps
        // there, which touches no status while no control must end; testAlwaysOnThroughStop tries it with holders)
        same('fanboy stop: fanboy_release at the array\'s stop leaves the control\'s status alone (release-steps); a plain stop (update, restart) says «released»',
            [null, 'released'], [$why('STOP_WHY=array_stopping;'), $why('')]);
        check('fanboy stop: array_event names the reason for everything release() calls; fanboy_release takes the guard\'s release-steps for it', str_contains($sh, "        local STOP_WHY=array_stopping   #")
            && str_contains($sh, '[[ "${STOP_WHY:-}" == array_stopping ]] && cmd=release-steps'));
    } finally {
        fanboyTEnd();
    }
}

// ===================================================================== the state the page reads, checks, metrics

function testFanboyState(): void
{
    $t = fanboyT('state');
    try {
        fanboyTDisks($t, 35, null, 33);
        // round 7: the outputs in the chip's automatic, as the BIOS leaves them (on a fixed value someone left nobody
        // steers — testFanboyR7Fixed)
        foreach ([1, 2, 3, 4, 5, 6] as $n) {
            file_put_contents("{$t['hw']}/pwm{$n}_enable", "2\n");
        }
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $scan = fanboyDetect();
        $control = fanboyControlState($settings);
        same('fanboy state: armed, the control not running and never ran — the BIOS, «starting»', ['bios', 'starting'], array_values(fanboyWhoNow($scan, $control, $settings)));
        $s2 = $settings;
        $s2['armed'] = false;
        same('fanboy state: set up, not taken over — the BIOS, «not taken over»', ['bios', 'not_armed'], array_values(fanboyWhoNow($scan, fanboyControlState($s2), $s2)));
        same('fanboy state: nothing set up — part 1\'s answer', ['bios', 'not_set_up'], array_values(fanboyWhoNow($scan, fanboyControlState(null), null)));
        // the control running and steering (its pid file names this process)
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 0.0;
        fanboyTRounds($c, $now, $t, fanboyTSpec(), 2);
        fanboyStatusWrite(fanboyStatus($c));
        file_put_contents(fanboyRunDir() . '/control.pid', getmypid() . ' ' . fanboyProcStart(getmypid()));
        $control = fanboyControlState($settings);
        same('fanboy state: steering — Fanboy, his two zones', ['who' => 'fanboy', 'why' => 'steers', 'zones' => 2], fanboyWhoNow($scan, $control, $settings));
        same('fanboy state: checks — he steers, no alarm', [['fanboy_steers', true], ['fanboy_alarm', true]],
            array_map(fn ($f) => [$f['id'], $f['ok']], fanboyChecks($settings, readJson(fanboyStatusFile()))));
        $m = fanboyMetrics(readJson(fanboyStatusFile()), true);
        same('fanboy state: metrics — steering, no alarm, each zone\'s level and warmest, each curve of a zone with several, each fan\'s rpm',
            ['uso_fanboy_steering', 'uso_fanboy_alarm', 'uso_fanboy_zone_level_percent', 'uso_fanboy_zone_temperature_celsius', 'uso_fanboy_curve_temperature_celsius', 'uso_fanboy_fan_rpm'], array_column($m, 'name'));
        same('fanboy state: no checks before he took over', [], fanboyChecks($s2, null));
        // the setup's state for the page: fans, zones, outputs, stored fans not there now, other boards
        $setup = fanboySetupState($scan, $settings);
        same('fanboy state: the setup — 5 fans known, 2 zones, 6 outputs now, none missing, no other board', [5, 2, 6, [], 0],
            [count($setup['fans']), count($setup['zones']), count($setup['outputs']), $setup['missing'], $setup['others']]);
        // the crash said: the status of a control that died without ending
        unlink(fanboyRunDir() . '/control.pid');
        same('fanboy state: a control that died without ending — «crashed»', ['bios', 'crashed'], [fanboyControlState($settings)['who'], fanboyControlState($settings)['why']]);
        fanboyLetGo($c, 'end');
        // the agent's tick: the control started when it should run (hired, armed, the array started) — here not hired: nothing
        fanboyEnsureControl(100.0);
        same('fanboy state: the tick starts no control for a desk not hired', [], $GLOBALS['fanboyLaunched']);
        // the tick when hired (his desk there for the time, a staff list of the test's): it starts the control; a control that
        // dies is started again after 30 s, three deaths in 30 minutes and the BIOS keeps the fans; told once per run of deaths
        $keepDesk = $GLOBALS['desks']['fanboy'] ?? null;
        $GLOBALS['desks']['fanboy'] = ['actions' => [], 'start' => null, 'tick' => null, 'checks' => null, 'fit' => null, 'metrics' => null];
        file_put_contents("{$t['tmp']}/staff.json", json_encode(['hired' => ['fanboy' => ['at' => 1]]]));
        $GLOBALS['agentStaffFile'] = "{$t['tmp']}/staff.json";
        @unlink(fanboyStatusFile());
        $launched = [];
        $dead = function (int $pid) {
            fanboyStatusWrite(['v' => 1, 'pid' => $pid, 'who' => 'bios', 'why' => 'crashed', 'phase' => 'ended']);     // as the guard's look leaves it
        };
        fanboyEnsureControl(10.0);
        $launched[] = count($GLOBALS['fanboyLaunched']);
        $dead(4242);
        fanboyEnsureControl(20.0);
        $launched[] = count($GLOBALS['fanboyLaunched']);
        fanboyEnsureControl(51.0);
        $launched[] = count($GLOBALS['fanboyLaunched']);
        $dead(4243);
        fanboyEnsureControl(100.0);
        fanboyEnsureControl(131.0);
        $launched[] = count($GLOBALS['fanboyLaunched']);
        $dead(4244);
        fanboyEnsureControl(200.0);
        fanboyEnsureControl(231.0);
        fanboyEnsureControl(1000.0);
        $launched[] = count($GLOBALS['fanboyLaunched']);
        same('fanboy state: the tick — started; died (found by the guard first): not again for 30 s, then again; a third death in 30 minutes: not again',
            [1, 1, 2, 3, 3], $launched);
        same('fanboy state: … told once for the run of deaths (a warning)', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning'))));
        fanboyEnsureControl(200.0 + FANBOY_CRASH_WINDOW + 1);
        $dead(4245);
        fanboyEnsureControl(200.0 + FANBOY_CRASH_WINDOW + 2);
        same('fanboy state: … calm for 30 minutes: started again, and a new death is told again', [4, 2],
            [count($GLOBALS['fanboyLaunched']), count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i warning')))]);
        unset($GLOBALS['desks']['fanboy'], $GLOBALS['agentStaffFile']);
        if ($keepDesk !== null) {
            $GLOBALS['desks']['fanboy'] = $keepDesk;
        }
        // the page's stub state carries the part-2 keys (the click test draws it)
        $stub = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/states/fanboy.json'), true);
        check('fanboy state: the page\'s stub state has control, setup, drivers', isset($stub['control'], $stub['setup'], $stub['drivers']));
    } finally {
        fanboyTEnd();
    }
}
