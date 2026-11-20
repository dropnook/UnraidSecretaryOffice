<?php
declare(strict_types=1);

/*
 * «Read the fan chip's name» — which Super-I/O chip the board carries when no driver shows it, and what it needs
 * (Unraid's own driver, a driver plugin, a newer Unraid, or nothing that exists yet). Two parts:
 *
 * 1 THE READ (fanboyChipRead()): only on the user's click (the action chip_read), never in a look, a tick or a start.
 *   Root touching hardware: every byte written is in fanboySioKey()/fanboySioExit() below; the sources and why it is
 *   safe on a board without such a chip are in the hand-over notes (fanboy-chip-id-notes.md). In short, per port P in
 *   0x2e, 0x4e (data port D = P + 1), the id = registers 0x20/0x21 (index written to P, value read from D):
 *     0 open     no key at all — a chip the BIOS left in configuration mode answers so, and is LEFT SO (nothing more
 *                written: the kernel's it87 does the same; entering or leaving that mode upsets IT8790E/IT8792E/
 *                IT87952E — it87.c above it87_find()); National Semiconductor's chips need no key either
 *     1 smsc     P ← 0x55, the id, P ← 0xaa                       (sch56xx-common.c, smsc47m1.c)
 *     2 winbond  P ← 0x87 0x87, the id, P ← 0xaa                   (nct6775-platform.c, nct6683.c, w83627ehf.c,
 *                                                                   w83627hf.c, f71882fg.c, f71805f.c, vt1211.c)
 *     3 ite      P ← 0x87 0x01 0x55 0x55 (0xaa on 0x4e), the id, register 0x02 ← 0x02 (it87.c)
 *   The first family that answers (an id neither 0x0000 nor 0xffff) ends that port; the order is sensors-detect's
 *   (shortest key first: SMSC's 0x55 is the end of ITE's key). No logical device selected, no other register touched,
 *   nothing on I²C/SMBus. Every byte of it is also written by the drivers «Load drivers yourself» already tries.
 *   Not at all (nothing written) when /dev/port isn't there, the kernel is locked down, a driver that talks to these
 *   ports is loaded (the kernel guards its own access with request_muxed_region(), which no program can take), or
 *   /dev/port can't be opened. The result kept per board: <data folder>/fanboy/chip.json.
 *
 * 2 WHAT IT MEANS (FANBOY_CHIPS, fanboyChipState()): the id → the chip, its driver, whether Unraid's own kernel has it
 *   (6.18.60 on Unraid 7.4.0; 6.12.60 compared for 7.3.x — the same ids but one), else the driver plugin that adds it.
 *   Each row names its source; one row is from a test (IT8696E, the owner's board with the it87 plugin), the rest is
 *   from sources only — the page says «should».
 */

const FANBOY_CHIP_FILE = 'chip.json';
const FANBOY_SIO_PORTS = [0x2e, 0x4e];
// the drivers that talk to the Super-I/O ports (hwmon, and the watchdog/GPIO drivers of the same chips): one loaded →
// no read (Unraid 7.4.0 ships none of the watchdog/GPIO ones — read on Tower 2026-11-20; a plugin might)
const FANBOY_SIO_MODULES = ['it87', 'nct6775', 'nct6775_core', 'nct6683', 'nct6687', 'f71882fg', 'f71805f', 'w83627ehf', 'w83627hf',
                            'sch56xx_common', 'smsc47m1', 'smsc47b397', 'pc87360', 'pc87427', 'vt1211',
                            'it87_wdt', 'w83627hf_wdt', 'f71808e_wdt', 'gpio_it87', 'gpio_f7188x', 'gpio_winbond'];
const FANBOY_SIO_FAMILIES = ['open', 'smsc', 'winbond', 'ite'];

/*
 * The chips. ids: the id as read (0x20 high, 0x21 low) after `mask`; `key`: the family that opens it (a chip found
 * «open» matches any); `unraid`: what Unraid's own kernel driver does with it — steer (outputs it can set), read
 * (temperatures and speeds only), null (no driver of Unraid's knows the id); `since`: the kernel that first knows it
 * when 6.12.60 doesn't; `plugin`: the driver plugin (FANBOY_PLUGINS) that adds or steers it.
 * Sources: the kernel's drivers/hwmon/ at v6.18.60 and v6.12.60 (git.kernel.org stable), frankcrawford/it87 at bd7a566c
 * (what ich777's it87-driver built for 6.18.60-Unraid), Fred78290/nct6687d at 25421f52 (nct6687-driver, 6.18.60-Unraid).
 */
const FANBOY_CHIPS = [
    // ITE — kernel it87.c *_DEVID and it87_find()
    ['ids' => [0x8705 => 'IT8705F', 0x8712 => 'IT8712F', 0x8716 => 'IT8716F', 0x8718 => 'IT8718F', 0x8720 => 'IT8720F', 0x8721 => 'IT8721F',
               0x8726 => 'IT8726F', 0x8728 => 'IT8728F', 0x8732 => 'IT8732F', 0x8733 => 'IT8792E/IT8795E', 0x8771 => 'IT8771E', 0x8772 => 'IT8772E',
               0x8781 => 'IT8781F', 0x8782 => 'IT8782F', 0x8783 => 'IT8783E', 0x8786 => 'IT8786E', 0x8790 => 'IT8790E', 0x8603 => 'IT8603E',
               0x8620 => 'IT8620E', 0x8622 => 'IT8622E', 0x8623 => 'IT8623E', 0x8628 => 'IT8628E', 0x8695 => 'IT87952E'],
     'mask' => 0xffff, 'key' => 'ite', 'maker' => 'ITE', 'module' => 'it87', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/it87.c'],
    // ITE — only the newer it87 the plugin brings
    ['ids' => [0x8736 => 'IT8736F', 0x8738 => 'IT8738E', 0x8785 => 'IT8785E', 0x8606 => 'IT8606E', 0x8607 => 'IT8607E', 0x8613 => 'IT8613E',
               0x8625 => 'IT8625E', 0x8655 => 'IT8655E', 0x8665 => 'IT8665E', 0x8686 => 'IT8686E', 0x8688 => 'IT8688E', 0x8689 => 'IT8689E',
               0x8696 => 'IT8696E', 0x8698 => 'IT8698E'],
     'mask' => 0xffff, 'key' => 'ite', 'maker' => 'ITE', 'module' => 'it87', 'unraid' => null, 'plugin' => 'it87-driver',
     'src' => 'frankcrawford/it87 it87.c at bd7a566c (it87-driver 6.18.60-Unraid); IT8696E seen on a real board with the plugin'],
    // Nuvoton — kernel nct6775-platform.c (SIO_ID_MASK 0xFFF8)
    ['ids' => [0xb470 => 'NCT6775F', 0xc330 => 'NCT6776D/F', 0xc560 => 'NCT6779D', 0xc800 => 'NCT6791D', 0xc910 => 'NCT6792D', 0xd120 => 'NCT6793D',
               0xd350 => 'NCT6795D', 0xd420 => 'NCT6796D', 0xd450 => 'NCT6797D', 0xd428 => 'NCT6798D', 0xd800 => 'NCT6796D-S/NCT6799D-R',
               0xc450 => 'NCT6106D', 0xd280 => 'NCT6116D'],
     'mask' => 0xfff8, 'key' => 'winbond', 'maker' => 'Nuvoton', 'module' => 'nct6775', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/nct6775-platform.c'],
    // Nuvoton — kernel nct6683.c (mask 0xFFF0): its outputs read only (writable on MiTAC boards only), bound only for
    // known board makers; the nct6687 plugin steers all three
    ['ids' => [0xc730 => 'NCT6683D', 0xd440 => 'NCT6686D', 0xd590 => 'NCT6687D'],
     'mask' => 0xfff0, 'key' => 'winbond', 'maker' => 'Nuvoton', 'module' => 'nct6683', 'unraid' => 'read', 'plugin' => 'nct6687-driver',
     'src' => 'kernel 6.18.60 drivers/hwmon/nct6683.c (nct6683_pwm_is_visible); Fred78290/nct6687d nct6687.c at 25421f52'],
    // Winbond — kernel w83627ehf.c (mask 0xFFF0) and w83627hf.c (register 0x20 only)
    ['ids' => [0x8850 => 'W83627EHF', 0x8860 => 'W83627EHG', 0xa020 => 'W83627DHG', 0xb070 => 'W83627DHG-P', 0xa230 => 'W83627UHG',
               0xa510 => 'W83667HG', 0xb350 => 'W83667HG-B'],
     'mask' => 0xfff0, 'key' => 'winbond', 'maker' => 'Winbond', 'module' => 'w83627ehf', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/w83627ehf.c'],
    ['ids' => [0x5200 => 'W83627HF', 0x8200 => 'W83627THF', 0x6000 => 'W83697HF', 0x7000 => 'W83637HF', 0x8500 => 'W83687THF'],
     'mask' => 0xff00, 'key' => 'winbond', 'maker' => 'Winbond', 'module' => 'w83627hf', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/w83627hf.c'],
    // Fintek — kernel f71882fg.c and f71805f.c (manufacturer 0x1934 not read: the ids don't meet another family's)
    ['ids' => [0x0901 => 'F71808E', 0x1001 => 'F71808A', 0x0507 => 'F71858FG', 0x0903 => 'F71858AD', 0x0601 => 'F71862FG', 0x1106 => 'F71868A',
               0x0814 => 'F71869', 0x1007 => 'F71869A', 0x0541 => 'F71882FG', 0x0723 => 'F71889FG', 0x0909 => 'F71889ED', 0x1005 => 'F71889A',
               0x0581 => 'F8000', 0x1210 => 'F81768D', 0x0704 => 'F81865F', 0x1010 => 'F81866A', 0x1502 => 'F81966'],
     'mask' => 0xffff, 'key' => 'winbond', 'maker' => 'Fintek', 'module' => 'f71882fg', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/f71882fg.c'],
    ['ids' => [0x1806 => 'F81968'],
     'mask' => 0xffff, 'key' => 'winbond', 'maker' => 'Fintek', 'module' => 'f71882fg', 'unraid' => 'steer', 'since' => '6.18', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/f71882fg.c (not in 6.12.60)'],
    ['ids' => [0x0406 => 'F71805F', 0x0341 => 'F71872F'],
     'mask' => 0xffff, 'key' => 'winbond', 'maker' => 'Fintek', 'module' => 'f71805f', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/f71805f.c'],
    // VIA — kernel vt1211.c (register 0x20)
    ['ids' => [0x3c00 => 'VT1211'], 'mask' => 0xff00, 'key' => 'winbond', 'maker' => 'VIA', 'module' => 'vt1211', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/vt1211.c'],
    // SMSC — kernel sch56xx-common.c (sch5627 has no pwm output, sch5636 none at all; sch5636.c reads a Fujitsu id) and smsc47m1.c
    ['ids' => [0xc600 => 'SCH5627'], 'mask' => 0xff00, 'key' => 'smsc', 'maker' => 'SMSC', 'module' => 'sch5627', 'unraid' => 'read', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/sch56xx-common.c, sch5627.c (pwm_auto_channels_temp only)'],
    ['ids' => [0xc700 => 'SCH5636'], 'mask' => 0xff00, 'key' => 'smsc', 'maker' => 'SMSC', 'module' => 'sch5636', 'unraid' => 'read', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/sch56xx-common.c, sch5636.c (no pwm)'],
    ['ids' => [0x5100 => 'LPC47B27x', 0x5900 => 'LPC47M10x/LPC47M112/LPC47M13x', 0x5f00 => 'LPC47M14x', 0x6000 => 'LPC47M15x/LPC47M192/LPC47M997', 0x6b00 => 'LPC47M292'],
     'mask' => 0xff00, 'key' => 'smsc', 'maker' => 'SMSC', 'module' => 'smsc47m1', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/smsc47m1.c'],
    // National Semiconductor — no key (pc87360.c, pc87427.c: register 0x20)
    ['ids' => [0xe100 => 'PC87360', 0xe800 => 'PC87363', 0xe400 => 'PC87364', 0xe500 => 'PC87365', 0xe900 => 'PC87366'],
     'mask' => 0xff00, 'key' => 'open', 'maker' => 'National Semiconductor', 'module' => 'pc87360', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/pc87360.c'],
    ['ids' => [0xf200 => 'PC87427'], 'mask' => 0xff00, 'key' => 'open', 'maker' => 'National Semiconductor', 'module' => 'pc87427', 'unraid' => 'steer', 'plugin' => null,
     'src' => 'kernel 6.18.60 drivers/hwmon/pc87427.c'],
];

// ===================================================================== 1 the read

/** The key a family writes to the index port P before the id (open: none) — the bytes of the table above */
function fanboySioKey(string $family, int $port): array
{
    return match ($family) {
        'smsc'    => [0x55],
        'winbond' => [0x87, 0x87],
        'ite'     => [0x87, 0x01, 0x55, $port === 0x4e ? 0xaa : 0x55],
        default   => [],
    };
}

/** What a family writes to leave configuration mode: [[port, byte] …] — open: nothing (a chip found open stays open) */
function fanboySioExit(string $family, int $port): array
{
    return match ($family) {
        'smsc', 'winbond' => [[$port, 0xaa]],
        'ite'             => [[$port, 0x02], [$port + 1, 0x02]],
        default           => [],
    };
}

/**
 * The read itself, through $io = ['out' => fn (int $port, int $byte), 'in' => fn (int $port): int]: per port the
 * families in their order, the first that answers ends the port. [{port, id (int|null), via (family|null)}]
 */
function fanboySioScan(array $io): array
{
    $out = $io['out'];
    $in = $io['in'];
    $result = [];
    foreach (FANBOY_SIO_PORTS as $p) {
        $found = ['port' => $p, 'id' => null, 'via' => null];
        foreach (FANBOY_SIO_FAMILIES as $family) {
            $id = null;
            try {
                foreach (fanboySioKey($family, $p) as $b) {
                    $out($p, $b);
                }
                $out($p, 0x20);
                $hi = $in($p + 1);
                $out($p, 0x21);
                $id = ($hi << 8) | $in($p + 1);
            } finally {
                // a family that opened nothing is closed again as its driver does; one that answered too — except open
                foreach (fanboySioExit($family, $p) as [$port, $b]) {
                    $out($port, $b);
                }
            }
            if ($id !== 0x0000 && $id !== 0xffff) {
                $found = ['port' => $p, 'id' => $id, 'via' => $family];
                break;
            }
        }
        $result[] = $found;
    }
    return $result;
}

/** /dev/port (the tests' tree: a regular file in its dev/) — byte by byte, PHP's buffers off: one read() of ONE port per in */
function fanboyPortOpen(string $path): ?array
{
    // never through a link; the real one must be the kernel's character device (the tests' tree: a plain file)
    clearstatcache();
    if (is_link($path) || (fanboySysRoot() === '' ? @filetype($path) !== 'char' : !is_file($path))) {
        return null;
    }
    $f = @fopen($path, 'r+b');
    if ($f === false) {
        return null;
    }
    // without these a fread($f, 1) would read ahead 8 KiB of I/O ports, and a write could wait in a buffer
    stream_set_read_buffer($f, 0);
    stream_set_write_buffer($f, 0);
    $out = function (int $port, int $byte) use ($f): void {
        if (fseek($f, $port) !== 0 || fwrite($f, chr($byte & 0xff)) !== 1) {
            throw new Problem('fanboy_chip_io');
        }
    };
    $in = function (int $port) use ($f): int {
        if (fseek($f, $port) !== 0) {
            throw new Problem('fanboy_chip_io');
        }
        $c = fread($f, 1);
        if (!is_string($c) || strlen($c) !== 1) {
            throw new Problem('fanboy_chip_io');
        }
        return ord($c);
    };
    return ['out' => $out, 'in' => $in, 'close' => fn () => fclose($f)];
}

function fanboyPortPath(): string
{
    return fanboyPaths()['dev'] . '/port';
}

/** The Super-I/O drivers loaded now (a read beside one is never made) */
function fanboySioModulesLoaded(?array $p = null): array
{
    $p ??= fanboyPaths();
    return array_values(array_filter(FANBOY_SIO_MODULES, fn ($m) => is_dir($p['modules'] . "/$m")));
}

/** Locked down (integrity or confidentiality): /dev/port is closed to root then */
function fanboyLockedDown(?array $p = null): bool
{
    $p ??= fanboyPaths();
    $s = (string) @file_get_contents($p['lockdown'], false, null, 0, 256);
    return preg_match('/\[(integrity|confidentiality)\]/', $s) === 1;
}

/** Why a read can't be made now ('' = it can): no_port · locked · held (a driver loaded) */
function fanboyChipBlocked(?array $p = null): string
{
    $p ??= fanboyPaths();
    $port = fanboyPortPath();
    if (!file_exists($port)) {
        return 'no_port';
    }
    if (fanboyLockedDown($p)) {
        return 'locked';
    }
    return fanboySioModulesLoaded($p) ? 'held' : '';
}

/**
 * The action: on the user's click only. $io from the tests only (a fake chip); the agent opens /dev/port. Kept per
 * board, logged; returns what was kept.
 */
function fanboyChipRead(?array $io = null): array
{
    $p = fanboyPaths();
    $board = fanboyBoard($p['dmi']);
    $blocked = fanboyChipBlocked($p);
    $ports = [];
    $result = $blocked;
    if ($blocked === '') {
        $dev = $io ?? fanboyPortOpen(fanboyPortPath());
        if ($dev === null) {
            $result = 'no_open';
        } else {
            try {
                $ports = fanboySioScan($dev);
                $result = 'read';
            } catch (Problem) {
                $result = 'io';
            } finally {
                if (isset($dev['close'])) {
                    ($dev['close'])();
                }
            }
        }
    }
    $kept = ['at' => time(), 'result' => $result,
             'ports' => array_map(fn ($x) => ['port' => sprintf('0x%02x', $x['port']), 'id' => $x['id'] === null ? null : sprintf('0x%04x', $x['id']), 'via' => $x['via']], $ports),
             'modules' => $result === 'held' ? fanboySioModulesLoaded($p) : []];
    $j = fanboyChipFileRead();
    $j['boards'][fanboyBoardKey($board)] = $kept;
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic(FANBOY_DIR . '/' . FANBOY_CHIP_FILE, jsonEncode($j), 0600);
    logLine('Fanboy: fan chip read on the user\'s click — ' . ($result === 'read'
        ? implode(', ', array_map(fn ($x) => $x['port'] . ' ' . ($x['id'] ?? 'nothing') . ($x['via'] ? " ({$x['via']})" : ''), $kept['ports']))
        : $result . ($kept['modules'] ? ' (' . implode(', ', $kept['modules']) . ')' : '')));
    return ['ok' => true, 'chip' => $kept];
}

/** chip.json: {v: 1, boards: {<board key>: {at, result, ports: [{port, id, via}], modules}}} — only entries in that shape used */
function fanboyChipFileRead(): array
{
    $j = readJson(FANBOY_DIR . '/' . FANBOY_CHIP_FILE);
    $j = is_array($j) ? $j : [];
    $j['v'] = 1;
    $j['boards'] = is_array($j['boards'] ?? null) ? $j['boards'] : [];
    return $j;
}

/** What was read on this board (null: not yet, or not in the shape) */
function fanboyChipKept(array $board): ?array
{
    $k = fanboyChipFileRead()['boards'][fanboyBoardKey($board)] ?? null;
    if (!is_array($k) || !is_string($k['result'] ?? null) || !preg_match('/^[a-z_]{1,16}$/D', $k['result'])) {
        return null;
    }
    $ports = [];
    foreach (is_array($k['ports'] ?? null) ? $k['ports'] : [] as $x) {
        if (is_array($x) && is_string($x['port'] ?? null) && preg_match('/^0x(2e|4e)$/D', $x['port'])
            && ($x['id'] === null || (is_string($x['id']) && preg_match('/^0x[0-9a-f]{4}$/D', $x['id'])))
            && ($x['via'] === null || in_array($x['via'], FANBOY_SIO_FAMILIES, true))) {
            $ports[] = ['port' => $x['port'], 'id' => $x['id'], 'via' => $x['via']];
        }
    }
    $mods = array_values(array_filter(is_array($k['modules'] ?? null) ? $k['modules'] : [], fn ($m) => is_string($m) && in_array($m, FANBOY_SIO_MODULES, true)));
    return ['at' => is_int($k['at'] ?? null) ? $k['at'] : null, 'result' => $k['result'], 'ports' => $ports, 'modules' => $mods];
}

// ===================================================================== 2 what it means

/** A chip of the table for an id read through $via: [name, row] — null when no row knows it */
function fanboyChipLookup(int $id, ?string $via): ?array
{
    foreach (FANBOY_CHIPS as $row) {
        foreach ($row['ids'] as $rid => $name) {
            if (($id & $row['mask']) === $rid && ($via === 'open' || $via === $row['key'] || $via === null)) {
                return ['name' => $name] + $row;
            }
        }
    }
    return null;
}

/** «6.18.60-Unraid» ≥ «6.18»? (an unreadable kernel: no) */
function fanboyKernelAtLeast(string $kernel, string $since): bool
{
    return preg_match('/^(\d+)\.(\d+)/', $kernel, $m) === 1 && version_compare("{$m[1]}.{$m[2]}", $since, '>=');
}

/**
 * What the page says in «no output»: what was read, which chip, what it needs. `need`:
 *   unraid     Unraid's own driver should steer it — the «Load drivers yourself» click
 *   plugin     a driver plugin should (its id, name, author, installed) — Unraid's has none for it, or only reads it
 *   newer      Unraid's driver knows it from a newer kernel on
 *   read_only  a driver for it shows temperatures and speeds only; nothing to steer that is known
 *   unknown    an id no row knows — the search words and the report
 *   nothing    no chip answered on either port
 *   held       not read: a driver is loaded (named), it shows no output either
 *   blocked    not read: no /dev/port, locked down, couldn't be opened
 *   not_read   not read yet (the button)
 * plus `words`: what the user can search for himself (names, never a translated text), `restart`: a restart of Unraid
 * may be needed after the plugin (nct6683 loaded beside nct6687-driver — its .plg blacklists nct6683 for later boots).
 */
function fanboyChipState(array $scan): array
{
    $board = is_array($scan['board'] ?? null) ? $scan['board'] : [];
    $kept = fanboyChipKept($board);
    $loaded = fanboySioModulesLoaded();
    $blocked = fanboyChipBlocked();
    $out = ['kept' => $kept, 'can' => $blocked === '', 'blocked' => $blocked, 'loaded' => $loaded, 'need' => 'not_read', 'chip' => null, 'plugin' => null,
            'restart' => false, 'words' => []];
    $chip = null;
    if ($kept !== null && $kept['result'] === 'read') {
        foreach ($kept['ports'] as $x) {
            if ($x['id'] !== null) {
                $row = fanboyChipLookup((int) hexdec($x['id']), $x['via']);
                $chip = ['id' => $x['id'], 'port' => $x['port'], 'via' => $x['via']] + ($row === null ? ['name' => null] : [
                    'name' => $row['name'], 'maker' => $row['maker'], 'module' => $row['module'], 'unraid' => $row['unraid'],
                    'since' => $row['since'] ?? null, 'plugin_id' => $row['plugin'], 'src' => $row['src']]);
                if ($row !== null) {
                    break;                                  // a known chip before an unknown one on the other port
                }
            }
        }
        $out['need'] = $chip === null ? 'nothing' : ($chip['name'] === null ? 'unknown' : '');
    } elseif ($kept !== null) {
        $out['need'] = $kept['result'] === 'held' ? 'held' : 'blocked';
    }
    if ($chip !== null && $chip['name'] !== null) {
        $kernel = (string) ($scan['kernel'] ?? '');
        $out['need'] = match (true) {
            $chip['unraid'] === 'steer' && ($chip['since'] === null || $kernel === 'test' || fanboyKernelAtLeast($kernel, $chip['since'])) => 'unraid',
            $chip['plugin_id'] !== null => 'plugin',
            $chip['unraid'] === 'steer' => 'newer',
            default => 'read_only',
        };
    }
    // held by nct6683 (Unraid's own, outputs read only): the NCT6687 plugin is what steers that chip
    if ($out['need'] === 'held' && in_array('nct6683', $kept['modules'] ?? [], true)) {
        $out['need'] = 'plugin';
        $chip = ['id' => null, 'port' => null, 'via' => null, 'name' => 'NCT6683D/NCT6686D/NCT6687D', 'maker' => 'Nuvoton', 'module' => 'nct6683',
                 'unraid' => 'read', 'since' => null, 'plugin_id' => 'nct6687-driver', 'src' => (string) fanboyChipLookup(0xd590, 'winbond')['src']];
    }
    $out['chip'] = $chip;
    if ($out['need'] === 'plugin') {
        $id = (string) $chip['plugin_id'];
        $tool = array_values(array_filter($scan['tools'] ?? [], fn ($t) => ($t['id'] ?? '') === $id))[0] ?? null;
        $out['plugin'] = ['id' => $id, 'name' => FANBOY_PLUGINS[$id]['name'], 'author' => FANBOY_PLUGINS[$id]['author'], 'installed' => (bool) ($tool['installed'] ?? false)];
        $out['restart'] = $id === 'nct6687-driver' && in_array('nct6683', $loaded, true);
    }
    $out['words'] = fanboyChipWords($scan, $chip, $out['plugin']);
    return $out;
}

/**
 * The words the user can search for himself — in ⟦Apps⟧ the plugin's exact name, in the forum the chip's and the
 * board's names: from the chip when it is known, else from the board maker's hint (FANBOY_BOARD_HINTS).
 * [{w: the word, kind: chip|driver|plugin|board}]
 */
function fanboyChipWords(array $scan, ?array $chip, ?array $plugin): array
{
    $w = [];
    $add = function (string $word, string $kind) use (&$w): void {
        $word = trim($word);
        if ($word !== '' && !in_array($word, array_column($w, 'w'), true)) {
            $w[] = ['w' => $word, 'kind' => $kind];
        }
    };
    if ($chip !== null && $chip['name'] !== null) {
        $add(($chip['maker'] ?? '') . ' ' . $chip['name'], 'chip');
        $add((string) $chip['module'], 'driver');
        if ($plugin !== null) {
            $add($plugin['name'], 'plugin');
        }
    } elseif ($chip !== null) {
        $add('Super-I/O ' . $chip['id'], 'chip');
    } else {
        $hint = $scan['board_hint'] ?? null;
        foreach (FANBOY_HINT_WORDS[$hint] ?? [] as [$word, $kind]) {
            $add($word, $kind);
        }
    }
    $b = $scan['board'] ?? [];
    $model = trim((string) ($b['model'] ?? ''));
    if ($model !== '') {
        $add(trim(((string) ($scan['board_maker'] ?? '') ?: (string) ($b['vendor'] ?? '')) . ' ' . $model), 'board');
    }
    return $w;
}

/** The words a board maker's hint gives when the chip isn't known (the hint's sources: FANBOY_BOARD_HINTS) */
const FANBOY_HINT_WORDS = [
    'ite'          => [['ITE', 'chip'], ['it87', 'driver'], ['ITE IT87 Driver', 'plugin']],
    'nuvoton'      => [['Nuvoton NCT6687', 'chip'], ['nct6687', 'driver'], ['Nuvoton NCT6687 Driver', 'plugin']],
    'nuvoton_asus' => [['Nuvoton NCT67', 'chip'], ['nct6775', 'driver']],
    'nuvoton_any'  => [['Nuvoton', 'chip'], ['nct6775', 'driver'], ['nct6683', 'driver'], ['Nuvoton NCT6687 Driver', 'plugin']],
];

/** For the makers' report: one line, ids and names only */
function fanboyChipReportLine(array $state): string
{
    $k = $state['kept'] ?? null;
    if ($k === null) {
        return 'Fan chip: not read';
    }
    if ($k['result'] !== 'read') {
        return 'Fan chip: not read (' . $k['result'] . ($k['modules'] ? ', ' . implode(' ', $k['modules']) . ' loaded' : '') . ')';
    }
    $ports = implode(', ', array_map(fn ($x) => $x['port'] . ' ' . ($x['id'] === null ? 'nothing' : $x['id'] . ' ' . $x['via']), $k['ports']));
    $c = $state['chip'] ?? null;
    return "Fan chip: $ports" . ($c !== null && $c['name'] !== null ? " is {$c['maker']} {$c['name']}, needs {$state['need']}" : ($c !== null ? ', not in the table' : ''));
}

/**
 * «Send the chip's id» — for a chip the table doesn't know (or none answered): the board's maker and model, kernel and
 * Unraid version, the situation and the chip line. Nothing else of the server: no name, no serial, no disk, no path.
 * English, plain characters only (as the full report: testFanboyReport's rules).
 */
function fanboyChipReportText(array $scan): string
{
    $clean = fn ($s) => trim((string) preg_replace('/[^A-Za-z0-9 ,.()_+:#-]+/', ' ', (string) $s));
    $b = $scan['board'] ?? [];
    $chip = is_array($scan['chip'] ?? null) ? $scan['chip'] : fanboyChipState($scan);
    return implode("\n", [
        'Fanboy fan chip report v1',
        'Board: ' . ($clean($b['vendor'] ?? '') ?: '?') . ' - ' . ($clean($b['model'] ?? '') ?: '?'),
        'Kernel: ' . ($clean($scan['kernel'] ?? '') ?: '?') . ' · Unraid: ' . ($clean($scan['unraid'] ?? '') ?: '?'),
        'Situation: ' . $clean($scan['situation']['code'] ?? '?') . ($scan['vm'] ?? false ? ' (virtual machine)' : ''),
        $clean(fanboyChipReportLine($chip)),
    ]);
}
