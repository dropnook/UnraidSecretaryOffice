<?php
declare(strict_types=1);

/*
 * Fanboy: which fan chip, and what it needs (agent/desks/fanboy-chipid.php, 2026-11-20).
 *   testFanboyChipTable   every row of FANBOY_CHIPS well-formed and sourced; no id in two rows; the owner's chip
 *   testFanboyChipRead    the port sequence against emulated chips of each maker (written here from the kernel's
 *                         drivers, independent of the code under test), the exact bytes for a board without a chip,
 *                         a chip the BIOS left open stays open, the reasons not to read, and the real file mechanics
 *                         (one byte per read() and write(), only bytes 0x2e/0x2f/0x4e/0x4f touched)
 *   testFanboyChipState   what each finding means (Unraid's driver, a plugin, newer, unknown, nothing, held), the
 *                         search words, the Team Lead's line
 *   testFanboyChipReport  the small report: the chip's id and the board's name, nothing else of the server
 */

/**
 * Emulated Super-I/O chips on the configuration ports — how the chips behave by their kernel drivers' sequences:
 * waiting for their key on the index port (any other byte restarts the match), then index/data; SMSC and
 * Winbond/Nuvoton/Fintek leave on 0xaa written to the index port, ITE on register 0x02 ← 0x02. A chip `open` starts in
 * configuration mode (ITE's IT8792E/IT87952E, National's PC87xxx). Reads where no chip listens: 0xff.
 * Returns [io, emu] — emu->log every in and out, emu->chips their state afterwards.
 */
function fanboyChipEmu(array $chips): array
{
    $keys = ['smsc' => [0x55], 'winbond' => [0x87, 0x87], 'ite2e' => [0x87, 0x01, 0x55, 0x55], 'ite4e' => [0x87, 0x01, 0x55, 0xaa], 'open' => [-1]];
    $emu = new stdClass();
    $emu->log = [];
    $emu->chips = [];
    foreach ($chips as $c) {
        $emu->chips[] = (object) ($c + ['config' => (bool) ($c['open'] ?? false), 'pos' => 0, 'index' => 0, 'regs' => []]);
    }
    $key = fn (object $c): array => $keys[$c->family === 'ite' ? ($c->port === 0x4e ? 'ite4e' : 'ite2e') : $c->family];
    $io = [
        'out' => function (int $port, int $byte) use ($emu, $key): void {
            $emu->log[] = ['out', $port, $byte];
            foreach ($emu->chips as $c) {
                if ($port === $c->port) {
                    if ($c->config) {
                        if ($byte === 0xaa && in_array($c->family, ['smsc', 'winbond'], true)) {
                            $c->config = false;
                        } else {
                            $c->index = $byte;
                        }
                    } else {
                        $k = $key($c);
                        $c->pos = $byte === $k[$c->pos] ? $c->pos + 1 : ($byte === $k[0] ? 1 : 0);
                        if ($c->pos === count($k)) {
                            [$c->config, $c->pos] = [true, 0];
                        }
                    }
                } elseif ($port === $c->port + 1 && $c->config) {
                    if ($c->family === 'ite' && $c->index === 0x02 && ($byte & 0x02)) {
                        $c->config = false;
                    } else {
                        $c->regs[$c->index] = $byte;            // a write to a chip register — the code never makes one
                    }
                }
            }
        },
        'in' => function (int $port) use ($emu): int {
            $v = 0xff;
            foreach ($emu->chips as $c) {
                if ($port === $c->port + 1 && $c->config) {
                    $v = match ($c->index) { 0x20 => $c->id >> 8, 0x21 => $c->id & 0xff, default => 0x00 };
                }
            }
            $emu->log[] = ['in', $port, $v];
            return $v;
        },
    ];
    return [$io, $emu];
}

/** A board of the tests without a fan chip driver: CPU and NVMe only, /dev/port a file of 0xff; $spec on top */
function fanboyChipTree(string $name, array $spec = []): array
{
    $t = fanboyT("chip-$name");
    $base = [
        'board' => ['Gigabyte Technology Co., Ltd.', 'EB-Z100 PRO'],
        'chips' => [[0, 'nvme', 'pci0000:00/0000:00:1b.4/0000:82:00.0/nvme/nvme0', 'nvme', 'driver_up' => 2, 'temps' => [1 => [29850, 'Composite']]],
                    [1, 'coretemp', 'platform/coretemp.0', 'coretemp', 'temps' => [1 => [28000, 'Package id 0']]]],
        'files' => ['dev/port' => str_repeat("\xff", 0x100), 'proc/cmdline' => "BOOT_IMAGE=/bzimage\n"],
    ];
    $spec = array_replace($base, $spec, ['files' => ($spec['files'] ?? []) + $base['files']]);
    $root = fanboyFakeTree("{$t['tmp']}/chiproot", $spec);
    foreach ($spec['drop'] ?? [] as $f) {
        @unlink("$root/$f");
    }
    putenv("OFFICE_FANBOY_ROOT=$root");
    return $t + ['chiproot' => $root];
}

function testFanboyChipTable(): void
{
    $bad = [];
    $seen = [];
    $kinds = ['steer', 'read', null];
    foreach (FANBOY_CHIPS as $i => $row) {
        $at = "row $i";
        if (!in_array($row['mask'], [0xffff, 0xfff8, 0xfff0, 0xff00], true)) { $bad[] = "$at: mask"; }
        if (!in_array($row['key'], ['open', 'smsc', 'winbond', 'ite'], true)) { $bad[] = "$at: key"; }
        if (!in_array($row['maker'], ['ITE', 'Nuvoton', 'Winbond', 'Fintek', 'VIA', 'SMSC', 'National Semiconductor'], true)) { $bad[] = "$at: maker"; }
        if (!array_key_exists('unraid', $row) || !in_array($row['unraid'], $kinds, true)) { $bad[] = "$at: unraid"; }
        // Unraid's own: a module of the drivers «Load drivers yourself» tries (all checked with modinfo on 7.4.0)
        if ($row['unraid'] !== null && !in_array($row['module'], FANBOY_DRIVERS, true)) { $bad[] = "$at: module {$row['module']} not Unraid's"; }
        // no driver of Unraid's: then a driver plugin that brings this very module
        if ($row['unraid'] === null && (($row['plugin'] ?? null) === null || FANBOY_PLUGINS[$row['plugin']]['kind'] !== 'driver'
            || !in_array($row['module'], FANBOY_PLUGINS[$row['plugin']]['modules'], true))) { $bad[] = "$at: no driver anywhere"; }
        if (($row['plugin'] ?? null) !== null && (FANBOY_PLUGINS[$row['plugin']]['kind'] ?? '') !== 'driver') { $bad[] = "$at: plugin not a driver plugin"; }
        if (isset($row['since']) && !preg_match('/^\d+\.\d+$/D', $row['since'])) { $bad[] = "$at: since"; }
        // the source: a kernel file of the version Unraid ships, or a repository at the commit its plugin built
        if (!preg_match('/^(kernel 6\.18\.60 drivers\/hwmon\/[a-z0-9-]+\.c|[A-Za-z0-9]+\/[a-z0-9]+ [a-z0-9]+\.c at [0-9a-f]{8})/', (string) $row['src'])) { $bad[] = "$at: src"; }
        foreach ($row['ids'] as $id => $name) {
            if (!is_int($id) || $id < 0 || $id > 0xffff || ($id & $row['mask']) !== $id) { $bad[] = "$at: id " . dechex($id); }
            if (!preg_match('/^[A-Z][A-Z0-9]+[A-Za-z0-9\/-]*$/D', $name)) { $bad[] = "$at: name $name"; }
            // one id, one chip: no other row of a family that could open it matches it
            foreach ($seen as [$k2, $mask2, $id2, $name2]) {
                if (($k2 === $row['key'] || $k2 === 'open' || $row['key'] === 'open') && ($id & $mask2) === $id2 && $name2 !== $name) {
                    $bad[] = "$at: " . dechex($id) . " $name also $name2";
                }
            }
            $seen[] = [$row['key'], $row['mask'], $id, $name];
        }
    }
    same('fanboy chip table: every row well-formed and sourced (mask, key, maker, Unraid\'s driver or a driver plugin with the module, the source), no id twice', [], $bad);
    same('fanboy chip table: how many chips it knows, per maker', ['ITE' => 37, 'Nuvoton' => 16, 'Winbond' => 12, 'Fintek' => 20, 'VIA' => 1, 'SMSC' => 7, 'National Semiconductor' => 6],
        array_reduce(FANBOY_CHIPS, function ($a, $r) { $a[$r['maker']] = ($a[$r['maker']] ?? 0) + count($r['ids']); return $a; }, []));
    // the one row from a real board: the owner's IT8696E, which only the it87 plugin knows
    $own = fanboyChipLookup(0x8696, 'ite');
    same('fanboy chip table: 0x8696 = ITE IT8696E, no driver of Unraid\'s, the it87-driver plugin — the source says it was seen on a real board',
        ['IT8696E', 'ITE', null, 'it87-driver', true], [$own['name'], $own['maker'], $own['unraid'], $own['plugin'], str_contains($own['src'], 'real board')]);
    // masks: Nuvoton's revision bits; a Winbond W83627HF by its first byte; a family's key keeps another's id out
    same('fanboy chip table: masks and keys — NCT6798D with any revision, W83627HF by register 0x20, an ITE id through the SMSC key not ITE, an open chip any',
        ['NCT6798D', 'NCT6798D', 'W83627HF', null, 'IT8689E', 'PC87427', null],
        array_map(fn ($x) => fanboyChipLookup(...$x)['name'] ?? null, [[0xd428, 'winbond'], [0xd42b, 'winbond'], [0x5241, 'winbond'], [0x8689, 'smsc'], [0x8689, 'open'], [0xf2ff, 'open'], [0x1234, 'ite']]));
    // the same ids in 6.12.60 (Unraid 7.3.x) but F81968 — the one row with `since`
    same('fanboy chip table: one chip only from kernel 6.18 on (F81968)', [[0x1806 => 'F81968']],
        array_values(array_map(fn ($r) => $r['ids'], array_filter(FANBOY_CHIPS, fn ($r) => isset($r['since'])))));
    same('fanboy chip table: the kernel compared by major.minor', [true, true, false, false], [fanboyKernelAtLeast('6.18.60-Unraid', '6.18'), fanboyKernelAtLeast('7.1.2', '6.18'),
        fanboyKernelAtLeast('6.12.60-Unraid', '6.18'), fanboyKernelAtLeast('', '6.18')]);
}

function testFanboyChipRead(): void
{
    // the bytes, written out as the notes' table says them — for a board where nothing answers (a VM like Tower)
    $none = [];
    foreach ([0x2e, 0x4e] as $p) {
        $d = $p + 1;
        $id = [['out', $p, 0x20], ['in', $d, 0xff], ['out', $p, 0x21], ['in', $d, 0xff]];
        $none = array_merge($none, $id,                                                       // 0 open: no key, no exit
            [['out', $p, 0x55]], $id, [['out', $p, 0xaa]],                                      // 1 SMSC
            [['out', $p, 0x87], ['out', $p, 0x87]], $id, [['out', $p, 0xaa]],                   // 2 Nuvoton/Winbond/Fintek/VIA
            [['out', $p, 0x87], ['out', $p, 0x01], ['out', $p, 0x55], ['out', $p, $p === 0x4e ? 0xaa : 0x55]], $id, [['out', $p, 0x02], ['out', $d, 0x02]]);   // 3 ITE
    }
    [$io, $emu] = fanboyChipEmu([]);
    $r = fanboySioScan($io);
    same('fanboy chip read: no chip — exactly these bytes, port by port, family by family (the notes\' table), nothing found', [$none, [[0x2e, null, null], [0x4e, null, null]]],
        [$emu->log, array_map(fn ($x) => [$x['port'], $x['id'], $x['via']], $r)]);
    same('fanboy chip read: … written only to 0x2e/0x4e, and to a data port only ITE\'s exit (register 0x02 ← 0x02)', [[0x2e, 0x4e], [[0x2f, 0x02], [0x4f, 0x02]]],
        [array_values(array_unique(array_map(fn ($x) => $x[1], array_filter($emu->log, fn ($x) => $x[0] === 'out' && in_array($x[1], [0x2e, 0x4e], true))))),
         array_values(array_map(fn ($x) => [$x[1], $x[2]], array_filter($emu->log, fn ($x) => $x[0] === 'out' && !in_array($x[1], [0x2e, 0x4e], true))))]);

    // chips of each maker: found by their own key, at their port, left as they were (in wait-for-key), no register written
    $cases = [
        'ITE IT8696E (the owner\'s kind)' => [[['port' => 0x2e, 'family' => 'ite', 'id' => 0x8696]], [[0x8696, 'ite'], [null, null]]],
        'ITE IT8689E + IT8792E left open at 0x4e (a Gigabyte pair)' => [[['port' => 0x2e, 'family' => 'ite', 'id' => 0x8689], ['port' => 0x4e, 'family' => 'ite', 'id' => 0x8733, 'open' => true]], [[0x8689, 'ite'], [0x8733, 'open']]],
        'Nuvoton NCT6798D' => [[['port' => 0x2e, 'family' => 'winbond', 'id' => 0xd42b]], [[0xd42b, 'winbond'], [null, null]]],
        'Nuvoton NCT6687D' => [[['port' => 0x4e, 'family' => 'winbond', 'id' => 0xd592]], [[null, null], [0xd592, 'winbond']]],
        'Fintek F71882FG at 0x4e' => [[['port' => 0x4e, 'family' => 'winbond', 'id' => 0x0541]], [[null, null], [0x0541, 'winbond']]],
        'Winbond W83627HF' => [[['port' => 0x2e, 'family' => 'winbond', 'id' => 0x5241]], [[0x5241, 'winbond'], [null, null]]],
        'SMSC SCH5627' => [[['port' => 0x2e, 'family' => 'smsc', 'id' => 0xc601]], [[0xc601, 'smsc'], [null, null]]],
        'National PC87427 (always open)' => [[['port' => 0x2e, 'family' => 'open', 'id' => 0xf201, 'open' => true]], [[0xf201, 'open'], [null, null]]],
    ];
    foreach ($cases as $what => [$chips, $want]) {
        [$io, $emu] = fanboyChipEmu($chips);
        $r = fanboySioScan($io);
        $after = array_map(fn ($c) => [$c->config, $c->regs], $emu->chips);
        $before = array_map(fn ($c) => [(bool) ($c['open'] ?? false), []], $chips);
        same("fanboy chip read: $what — found by its key, left as it was, no register written", [$want, $before],
            [array_map(fn ($x) => [$x['id'], $x['via']], $r), $after]);
    }
    // the chip left open: nothing but the two index writes on its port (no key, no exit — it87's rule)
    [$io, $emu] = fanboyChipEmu($cases['ITE IT8689E + IT8792E left open at 0x4e (a Gigabyte pair)'][0]);
    fanboySioScan($io);
    same('fanboy chip read: … the IT8792E left open at 0x4e gets the two index writes only', [[0x4e, 0x20], [0x4e, 0x21]],
        array_values(array_map(fn ($x) => [$x[1], $x[2]], array_filter($emu->log, fn ($x) => $x[0] === 'out' && in_array($x[1], [0x4e, 0x4f], true)))));
    // an SMSC chip: found under its own key, before ITE's key (whose last byte 0x55 would open it too) is ever written
    [$io, $emu] = fanboyChipEmu($cases['SMSC SCH5627'][0]);
    fanboySioScan($io);
    $on2e = array_values(array_map(fn ($x) => $x[2], array_filter($emu->log, fn ($x) => $x[0] === 'out' && $x[1] === 0x2e)));
    same('fanboy chip read: … SMSC at 0x2e: open, then its key and exit, nothing after it on that port', [0x20, 0x21, 0x55, 0x20, 0x21, 0xaa], $on2e);
    // a write failing after a key: the family's exit is still tried, and the read ends as «io»
    $n = 0;
    [$io0] = fanboyChipEmu([]);
    $log = [];
    $io = ['out' => function (int $p, int $b) use (&$n, &$log, $io0): void { $log[] = [$p, $b]; if (++$n === 4) { throw new Problem('fanboy_chip_io'); } ($io0['out'])($p, $b); }, 'in' => $io0['in']];
    $threw = false;
    try {
        fanboySioScan($io);
    } catch (Problem) {
        $threw = true;
    }
    same('fanboy chip read: a write that fails after the SMSC key — the exit 0xaa still written, the read stops', [true, [0x2e, 0xaa]], [$threw, end($log)]);

    // the action through the tests' tree: a fake chip, kept per board, logged
    $t = fanboyChipTree('read');
    [$io] = fanboyChipEmu([['port' => 0x2e, 'family' => 'ite', 'id' => 0x8696]]);
    $j = fanboyChipRead($io);
    $kept = fanboyChipKept(fanboyBoard(fanboyPaths()['dmi']));
    same('fanboy chip read: the click — read, kept for this board in the data folder (port, id, family)',
        ['read', [['port' => '0x2e', 'id' => '0x8696', 'via' => 'ite'], ['port' => '0x4e', 'id' => null, 'via' => null]], $kept['ports'], true],
        [$j['chip']['result'], $j['chip']['ports'], $j['chip']['ports'], is_file(FANBOY_DIR . '/' . FANBOY_CHIP_FILE)]);
    // a writer that keeps what it doesn't know; a reader that takes only the shape
    $f = json_decode((string) file_get_contents(FANBOY_DIR . '/' . FANBOY_CHIP_FILE), true);
    $f['boards']['Other | Board'] = ['result' => 'read', 'ports' => [['port' => '0x60', 'id' => 'x', 'via' => 'evil']]];
    $f['later'] = 1;
    file_put_contents(FANBOY_DIR . '/' . FANBOY_CHIP_FILE, json_encode($f));
    fanboyChipRead($io);
    $f = json_decode((string) file_get_contents(FANBOY_DIR . '/' . FANBOY_CHIP_FILE), true);
    same('fanboy chip read: chip.json — another board\'s entry and an unknown key kept; a port, id or family not in the shape never read',
        [true, 1, []], [isset($f['boards']['Other | Board']), $f['later'] ?? null, fanboyChipKept(['vendor' => 'Other', 'model' => 'Board'])['ports']]);
    fanboyTEnd();

    // the reasons not to read — nothing written to the port file in any of them
    $reasons = [
        'no /dev/port' => [['drop' => ['dev/port']], 'no_port'],
        'locked down' => [['files' => ['sys/kernel/security/lockdown' => "none [integrity] confidentiality\n"]], 'locked'],
        'lockdown none' => [['files' => ['sys/kernel/security/lockdown' => "[none] integrity confidentiality\n"]], 'read'],
        'a driver loaded (nct6775)' => [['modules' => ['nct6775']], 'held'],
        'a watchdog of the chip loaded' => [['modules' => ['it87_wdt']], 'held'],
    ];
    $got = [];
    foreach ($reasons as $what => [$spec, $want]) {
        $t = fanboyChipTree('why-' . md5($what), $spec);
        $port = "{$t['chiproot']}/dev/port";
        $md5 = is_file($port) ? md5_file($port) : null;
        [$io, $emu] = fanboyChipEmu([]);
        $j = fanboyChipRead($want === 'read' ? $io : null);
        $got[$what] = [$j['chip']['result'], $want === 'read' ? count($emu->log) > 0 : (is_file($port) ? md5_file($port) === $md5 : true)];
        fanboyTEnd();
    }
    same('fanboy chip read: not read — no /dev/port, locked down, a driver or watchdog of the chip loaded; «[none]» is no lockdown; the port file untouched each time',
        ['no /dev/port' => ['no_port', true], 'locked down' => ['locked', true], 'lockdown none' => ['read', true], 'a driver loaded (nct6775)' => ['held', true], 'a watchdog of the chip loaded' => ['held', true]], $got);
    // /dev/port there but can't be opened (a folder here): no_open
    $t = fanboyChipTree('noopen', ['drop' => ['dev/port']]);
    mkdir("{$t['chiproot']}/dev/port");
    same('fanboy chip read: /dev/port that can\'t be opened — no_open, kept so', 'no_open', fanboyChipRead()['chip']['result']);
    fanboyTEnd();
    // a link where /dev/port should be: never opened (nor followed)
    $t = fanboyChipTree('link', ['drop' => ['dev/port']]);
    file_put_contents("{$t['tmp']}/elsewhere", str_repeat("\xff", 0x100));
    symlink("{$t['tmp']}/elsewhere", "{$t['chiproot']}/dev/port");
    same('fanboy chip read: /dev/port a link — not opened (no_open), the file behind it untouched', ['no_open', str_repeat("\xff", 0x100)],
        [fanboyChipRead()['chip']['result'], file_get_contents("{$t['tmp']}/elsewhere")]);
    fanboyTEnd();
    // the real one must be a character device: a plain file at the real path is refused (only the tests' tree takes one)
    check('fanboy chip read: outside the tests\' tree only a character device is opened', str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-chipid.php'),
        "if (is_link(\$path) || (fanboySysRoot() === '' ? @filetype(\$path) !== 'char' : !is_file(\$path))) {"));

    // the real mechanics on a plain file standing for /dev/port: the whole sequence, nothing found (a file answers what
    // was written last — 0xff stays 0xff only where nothing was written); only the four bytes touched; and on Linux
    // one read() of one byte per in, one write() per out (PHP's read-ahead off — else one fread reads 8 KiB of ports)
    $t = fanboyChipTree('file');
    $file = "{$t['chiproot']}/dev/port";
    file_put_contents($file, str_repeat("\xff", 0x100));
    $dev = fanboyPortOpen($file);
    $calls = ['in' => 0, 'out' => 0];
    $io = ['out' => function (int $p, int $b) use ($dev, &$calls): void { $calls['out']++; ($dev['out'])($p, $b); },
           'in' => function (int $p) use ($dev, &$calls): int { $calls['in']++; return ($dev['in'])($p); }];
    $ioStat = function (): array {
        $s = (string) @file_get_contents('/proc/self/io');
        preg_match('/syscr: (\d+)/', $s, $r);
        preg_match('/syscw: (\d+)/', $s, $w);
        return [(int) ($r[1] ?? -1), (int) ($w[1] ?? -1)];
    };
    $a0 = $ioStat();
    $a1 = $ioStat();
    fanboySioScan($io);
    $a2 = $ioStat();
    ($dev['close'])();
    $after = (string) file_get_contents($file);
    $changed = [];
    for ($i = 0; $i < 0x100; $i++) {
        if ($after[$i] !== "\xff") {
            $changed[] = $i;
        }
    }
    same('fanboy chip read: through a real file — 38 writes and 16 reads, only bytes 0x2e, 0x2f, 0x4e, 0x4f changed', [['in' => 16, 'out' => 38], [0x2e, 0x2f, 0x4e, 0x4f]], [$calls, $changed]);
    if ($a0[0] >= 0) {
        $base = [$a1[0] - $a0[0], $a1[1] - $a0[1]];       // what reading /proc/self/io costs by itself
        same('fanboy chip read: … one read() per in and one write() per out (/proc/self/io syscr, syscw)', [16, 38], [$a2[0] - $a1[0] - $base[0], $a2[1] - $a1[1] - $base[1]]);
    }
    fanboyTEnd();

    // only on a click: no look, tick or start reads the chip; the one fwrite of the file is the port's
    $src = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-chipid.php'));
    $all = '';
    foreach (glob(OFFICE_DIR . '/agent/desks/fanboy*.php') ?: [] as $f) {
        $all .= (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($f));
    }
    check('fanboy chip read: fanboyChipRead() called only by the action chip_read, fanboySioScan() only by fanboyChipRead(); /dev/port opened in one place',
        substr_count($all, 'fanboyChipRead(') === 2 && str_contains($all, "'chip_read'     => fn (array \$r) => fanboyChipRead() +")
        && substr_count($all, 'fanboySioScan(') === 2 && substr_count($all, 'fanboyPortOpen(') === 2 && substr_count($src, 'fopen(') === 1
        && substr_count($src, 'fwrite(') === 1 && str_contains($src, 'stream_set_read_buffer($f, 0);') && str_contains($src, 'stream_set_write_buffer($f, 0);'));
}

function testFanboyChipState(): void
{
    // a chip read on a board with no output: what it needs, the next step's data, the words
    $state = function (string $name, array $chips, array $spec = [], ?callable $scanFix = null): array {
        $t = fanboyChipTree("state-$name", $spec);
        if ($chips !== [false]) {                       // [false]: not read
            fanboyChipRead(fanboyChipEmu($chips)[0]);
        }
        $scan = fanboyDetect(fanboySysRoot());
        if ($scanFix) {
            $scan = $scanFix($scan);
        }
        $s = fanboyChipState($scan) + ['situation' => $scan['situation']['code'], 'fit' => fanboyFit($scan)];
        fanboyTEnd();
        return $s;
    };
    $w = fn (array $s) => array_map(fn ($x) => "{$x['kind']}:{$x['w']}", $s['words']);

    $s = $state('ite', [['port' => 0x2e, 'family' => 'ite', 'id' => 0x8696]]);
    same('fanboy chip state: IT8696E on a Gigabyte board — no output, needs the it87 plugin (not installed), no restart; the words: chip, driver, plugin, board',
        ['no_output', 'plugin', 'IT8696E', ['id' => 'it87-driver', 'name' => 'ITE IT87 Driver', 'author' => 'ich777', 'installed' => false], false,
         ['chip:ITE IT8696E', 'driver:it87', 'plugin:ITE IT87 Driver', 'board:Gigabyte EB-Z100 PRO']],
        [$s['situation'], $s['need'], $s['chip']['name'], $s['plugin'], $s['restart'], $w($s)]);
    same('fanboy chip state: … the Team Lead says it too (no_output_plugin with the chip and the plugin)',
        ['no_output_plugin', ['chip' => 'ITE IT8696E', 'module' => 'it87', 'plugin' => 'ITE IT87 Driver', 'author' => 'ich777']], [$s['fit']['why'], $s['fit']['params']]);

    $s = $state('nct6798', [['port' => 0x2e, 'family' => 'winbond', 'id' => 0xd42b]], ['board' => ['ASUSTeK COMPUTER INC.', 'EXAMPLE B550-F']]);
    same('fanboy chip state: NCT6798D — Unraid\'s own nct6775 should steer it (the «Load drivers» click); the Team Lead: no_output_unraid',
        ['unraid', 'nct6775', null, 'no_output_unraid', ['chip:Nuvoton NCT6798D', 'driver:nct6775', 'board:ASUS EXAMPLE B550-F']],
        [$s['need'], $s['chip']['module'], $s['plugin'], $s['fit']['why'], $w($s)]);

    $s = $state('nct6687', [['port' => 0x4e, 'family' => 'winbond', 'id' => 0xd592]], ['board' => ['Micro-Star International Co., Ltd.', 'EXAMPLE B650']]);
    same('fanboy chip state: NCT6687D — Unraid\'s nct6683 only reads it: the nct6687 plugin', ['plugin', 'read', 'nct6687-driver', false], [$s['need'], $s['chip']['unraid'], $s['plugin']['id'], $s['restart']]);

    // held by Unraid's nct6683 (loaded, no output: its outputs are read only) — the plugin, and a restart may be needed
    $s = $state('held6683', [false], ['modules' => ['nct6683']]);
    same('fanboy chip state: nct6683 loaded, not read yet — the button not offered (a driver talks to the chip), what is loaded named', [false, 'held', ['nct6683'], 'not_read'],
        [$s['can'], $s['blocked'], $s['loaded'], $s['need']]);
    $t = fanboyChipTree('held6683b', ['modules' => ['nct6683']]);
    fanboyChipRead();
    $s = fanboyChipState(fanboyDetect(fanboySysRoot()));
    fanboyTEnd();
    same('fanboy chip state: … clicked anyway (an older page): held → the nct6687 plugin, a restart may be needed (its .plg blacklists nct6683 for later boots)',
        ['plugin', 'nct6687-driver', true], [$s['need'], $s['plugin']['id'], $s['restart']]);
    $t = fanboyChipTree('heldit87b', ['modules' => ['it87']]);
    fanboyChipRead();
    $s = fanboyChipState(fanboyDetect(fanboySysRoot()));
    fanboyTEnd();
    same('fanboy chip state: it87 loaded with no output — held, said with the module (the hints say why: often the BIOS keeps the chip)', ['held', ['it87']], [$s['need'], $s['kept']['modules']]);

    $s = $state('newer', [['port' => 0x2e, 'family' => 'winbond', 'id' => 0x1806]], [], fn ($x) => ['kernel' => '6.12.60-Unraid'] + $x);
    $s2 = $state('newer2', [['port' => 0x2e, 'family' => 'winbond', 'id' => 0x1806]], [], fn ($x) => ['kernel' => '6.18.60-Unraid'] + $x);
    same('fanboy chip state: F81968 — on kernel 6.12 «newer», on 6.18 Unraid\'s own', ['newer', 'unraid'], [$s['need'], $s2['need']]);

    $s = $state('unknown', [['port' => 0x2e, 'family' => 'ite', 'id' => 0x8612]]);
    same('fanboy chip state: an id no row knows — unknown, the id the word to search', ['unknown', '0x8612', null, ['chip:Super-I/O 0x8612', 'board:Gigabyte EB-Z100 PRO']],
        [$s['need'], $s['chip']['id'], $s['chip']['name'], $w($s)]);
    $s = $state('known-first', [['port' => 0x2e, 'family' => 'ite', 'id' => 0x8612], ['port' => 0x4e, 'family' => 'ite', 'id' => 0x8689]]);
    same('fanboy chip state: an unknown chip at 0x2e and a known one at 0x4e — the known one named', ['plugin', 'IT8689E', '0x4e'], [$s['need'], $s['chip']['name'], $s['chip']['port']]);
    $s = $state('nothing', []);
    same('fanboy chip state: nothing answered — nothing; the words from the board maker\'s hint (Gigabyte: ITE)',
        ['nothing', ['chip:ITE', 'driver:it87', 'plugin:ITE IT87 Driver', 'board:Gigabyte EB-Z100 PRO']], [$s['need'], $w($s)]);
    $s = $state('notread', [false], ['board' => ['ASRock', 'EXAMPLE X570']]);
    same('fanboy chip state: not read yet, an ASRock board — the button offered, the words from the hint (Nuvoton)',
        [true, 'not_read', ['chip:Nuvoton', 'driver:nct6775', 'driver:nct6683', 'plugin:Nuvoton NCT6687 Driver', 'board:ASRock EXAMPLE X570'], 'no_output'],
        [$s['can'], $s['need'], $w($s), $s['fit']['why']]);
    $s = $state('noport', [false], ['drop' => ['dev/port'], 'board' => ['Example Boards Inc.', 'EB-1']]);
    same('fanboy chip state: no /dev/port, a maker without a hint — not offered, the board\'s name the only word', [false, 'no_port', ['board:Example Boards Inc. EB-1']],
        [$s['can'], $s['blocked'], $w($s)]);
    // the board hints: the two new makers with their sources (detect.php), the old ones unchanged
    same('fanboy chip state: the board hints — Gigabyte ITE, MSI Nuvoton, ASUS Nuvoton through nct6775, ASRock Nuvoton, another none',
        [['ite', 'Gigabyte'], ['nuvoton', 'MSI'], ['nuvoton_asus', 'ASUS'], ['nuvoton_any', 'ASRock'], [null, null]],
        array_map(fn ($v) => fanboyBoardHint(['vendor' => $v]), ['Gigabyte Technology Co., Ltd.', 'Micro-Star International Co., Ltd.', 'ASUSTeK COMPUTER INC.', 'ASRock', 'Example Boards Inc.']));
    same('fanboy chip state: every hint has its words', [], array_values(array_diff(array_map(fn ($h) => $h[0], FANBOY_BOARD_HINTS), array_keys(FANBOY_HINT_WORDS))));

    // the page: the part in the «no output» callout, the button beside «Load drivers», each need its sentence, the
    // plugin's step through ⟦Apps⟧ (a link, nothing installed), the report for what isn't known
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/en.json'), true);
    // (the callout stands above the setup's steps too: the button once there, not again in the drivers' block)
    check('fanboy chip page: in the «no output» callout (overview and setup), the button once, through act(); Unraid\'s driver loaded from there; the plugin via ⟦Apps⟧; the chip report its own action',
        str_contains($js, "if (S().chip) p.appendChild(chipPart());") && substr_count($js, 'chipReadButton(') === 3
        && str_contains($js, "if (need === 'unraid' && !((S().drivers || {}).mine || []).length")
        && str_contains($js, "act('chip_read', {}, b,") && str_contains($js, "Office.api.post(`\${ID}.chip_report`, {})") && substr_count($js, "Office.safeHref('/Apps')") === 2);
    $missing = [];
    foreach (['unraid', 'plugin', 'plugin_read', 'newer', 'read_only', 'unknown', 'nothing', 'held'] as $n) {
        if (!isset($en["chip.need.$n"])) { $missing[] = "chip.need.$n"; }
    }
    foreach (['no_port', 'locked', 'held', 'no_open', 'io'] as $n) {
        if (!isset($en["chip.blocked.$n"])) { $missing[] = "chip.blocked.$n"; }
    }
    foreach (['chip', 'driver', 'plugin', 'board'] as $n) {
        if (!isset($en["chip.word.$n"])) { $missing[] = "chip.word.$n"; }
    }
    same('fanboy chip page: a sentence for every need, every reason not to read and every kind of word', [], $missing);
    check('fanboy chip page: what comes from sources says «should» (Unraid\'s driver, the plugin, a newer Unraid) — never a promise',
        str_contains($en['chip.need.unraid'], 'should') && str_contains($en['chip.need.plugin'], 'should') && str_contains($en['chip.need.plugin_read'], 'should')
        && str_contains($en['chip.need.newer'], 'should') && str_contains($en['fit.no_output_plugin'], 'should') && str_contains($en['bubble.no_output_plugin'], 'should'));
}

function testFanboyChipReport(): void
{
    $t = fanboyChipTree('report', ['board' => ['Gigabyte Technology Co., Ltd.', 'EB-Z100/PRO']]);
    [$io] = fanboyChipEmu([['port' => 0x2e, 'family' => 'ite', 'id' => 0x8612]]);
    fanboyChipRead($io);
    $scan = fanboyDetect(fanboySysRoot());
    $text = fanboyChipReportText($scan);
    same('fanboy chip report: five lines — what it is, the board, kernel and Unraid, the situation, the chip (ports, ids, the family\'s key)',
        ['Fanboy fan chip report v1', 'Board: Gigabyte Technology Co., Ltd. - EB-Z100-PRO', 'Kernel: test · Unraid: 7.3.2', 'Situation: no_output',
         'Fan chip: 0x2e 0x8612 ite, 0x4e nothing, not in the table'], explode("\n", $text));
    check('fanboy chip report: no «/» (the wish form\'s rule), nothing of the server beyond them (no disk, no serial, no hostname, no path)',
        !str_contains($text, '/') && !preg_match('/EXAMPLE_|disk|nvme|serial|hostname|\/mnt|\/boot/i', $text));
    $full = fanboyReportText($scan);
    check('fanboy chip report: the makers\' full report carries the chip line too', str_contains($full, "\nFan chip: 0x2e 0x8612 ite, 0x4e nothing, not in the table"));
    fanboyTEnd();
    $t = fanboyChipTree('report2');
    [$io] = fanboyChipEmu([['port' => 0x2e, 'family' => 'winbond', 'id' => 0xd800]]);
    fanboyChipRead($io);
    same('fanboy chip report: a known chip — named with what it needs, the «/» of its name cleaned',
        'Fan chip: 0x2e 0xd800 winbond, 0x4e nothing is Nuvoton NCT6796D-S NCT6799D-R, needs unraid', explode("\n", fanboyChipReportText(fanboyDetect(fanboySysRoot())))[4]);
    fanboyTEnd();
    same('fanboy chip report: not read — said so', 'Fan chip: not read', fanboyChipReportLine(['kept' => null]));
}
