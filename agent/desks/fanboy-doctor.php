<?php
declare(strict_types=1);

/*
 * Fanboy's «driver doctor» (2026-11-22, the owner's research): a hint only where he sees its sign — read only, never a
 * change, never a disk woken. What it reads, and nothing else:
 *   the kernel log            `dmesg` through run() (the tests: a file in their fake tree), at most every
 *                             FANBOY_DMESG_EVERY seconds — or when a driver module came or went; only the lines asked
 *                             for below are kept (FANBOY_DMESG_KEEP)
 *   /sys/module               a fan chip's driver loaded (FANBOY_DOCTOR_DRIVERS), the BMC's (ipmi_si, ipmi_devintf)
 *   /sys/bus/pci/drivers/vmd  Intel VMD holding a PCI device (an NVMe behind it)
 *   /sys/kernel/security/lockdown, /proc/cmdline, hwmonN/pwmN_mode   (fanboyPaths())
 * Each sign: ['code' => …, + its facts]; the page says it (desk.js doctorPart()), the makers' report has one line each
 * (fanboyDoctorLines()). Wording on the page careful: «often», «some» — never a promise.
 */

const FANBOY_DMESG_EVERY = 300;
const FANBOY_DMESG_KEEP = 600;
const FANBOY_DMESG_LINE = '/ACPI|resource conflict|No such device|SPD Write Disable|\b(it87|nct67\d\d|f71882fg|w83627\w*)\b/i';
// the fan chip drivers whose «loaded, no chip» means: the chip is newer than the driver (its plugin, where one fits)
const FANBOY_DOCTOR_DRIVERS = ['it87' => 'it87-driver', 'nct6775' => null, 'nct6683' => null, 'nct6687' => 'nct6687-driver', 'f71882fg' => null,
                               'w83627ehf' => null, 'w83627hf' => null];
// a Super-I/O's hardware-monitor window as ACPI's conflict line names it (0x290 …, ITE's 0xa20 … 0xa60)
const FANBOY_SIO_WINDOWS = [[0x200, 0x2ff], [0xa00, 0xaff]];

/**
 * The kernel log's lines that matter here. $p['dmesg']: '' = the real one (dmesg, kept in memory between looks), else
 * a file of the tests' tree.
 *
 * @return list<string>
 */
function fanboyKernelLog(array $p): array
{
    static $memo = null;
    $keep = function (string $text): array {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if ($line !== '' && preg_match(FANBOY_DMESG_LINE, $line)) {
                $out[] = substr($line, 0, 400);
            }
        }
        return array_slice($out, -FANBOY_DMESG_KEEP);
    };
    if ($p['dmesg'] !== '') {
        return is_file($p['dmesg']) && !is_link($p['dmesg']) ? $keep((string) @file_get_contents($p['dmesg'], false, null, 0, 4 << 20)) : [];
    }
    $mods = implode(',', array_filter(array_merge(array_keys(FANBOY_DOCTOR_DRIVERS), ['ipmi_si', 'ipmi_devintf', 'spd5118', 'ee1004']),
        fn ($m) => is_dir($p['modules'] . "/$m")));
    $now = hrtime(true);
    if ($memo === null || $memo['mods'] !== $mods || $now - $memo['at'] > FANBOY_DMESG_EVERY * 1e9) {
        [$code, $out] = run(['dmesg'], 10, 16 << 20);
        $memo = ['at' => $now, 'mods' => $mods, 'lines' => $code === 0 ? $keep($out) : []];
    }
    return $memo['lines'];
}

/** A start option set at this boot (the whole word) */
function fanboyCmdlineHas(array $p, string $opt): bool
{
    $s = (string) @file_get_contents($p['cmdline'], false, null, 0, 4096);
    return preg_match('/(^|\s)' . preg_quote($opt, '/') . '(\s|$)/', $s) === 1;
}

/**
 * ACPI's «resource conflict» at the fan chip: the OSL line, and either a range in a Super-I/O window or a fan chip
 * driver named in the lines around it (an SMBus conflict — 0xefa0 … — is no fan chip's). null: none seen.
 *
 * @param list<string> $log
 * @return array{ite: bool}|null
 */
function fanboyAcpiConflict(array $log): ?array
{
    $seen = false;
    $ite = false;
    foreach ($log as $i => $line) {
        if (!preg_match('/ACPI: OSL: Resource conflict|resource conflict.*ACPI support missing from driver/i', $line)) {
            continue;
        }
        $near = array_slice($log, max(0, $i - 3), 7);
        $hit = false;
        foreach ($near as $n) {
            if (preg_match('/SystemIO range 0x0*([0-9a-f]+)-0x0*([0-9a-f]+)/i', $n, $m)) {
                $lo = (int) hexdec($m[1]);
                foreach (FANBOY_SIO_WINDOWS as [$a, $b]) {
                    if ($lo >= $a && $lo <= $b) {
                        $hit = true;
                        $ite = $ite || $a === 0xa00;
                    }
                }
            }
            if (preg_match('/\b(it87|nct67\d\d|f71882fg|w83627\w*)\b/i', $n, $m)) {
                $hit = true;
                $ite = $ite || strtolower($m[1]) === 'it87';
            }
        }
        $seen = $seen || $hit;
    }
    return $seen ? ['ite' => $ite] : null;
}

/**
 * The signs. $scan: fanboyDetect()'s; $fans: his stored fans by id (the test's verdicts); $chip: fanboyChipState()'s
 * (null: not looked at — the report).
 *
 * @return list<array<string, mixed>>
 */
function fanboyDoctor(array $scan, ?array $p = null, array $fans = [], ?array $chip = null): array
{
    $p ??= fanboyPaths();
    $log = fanboyKernelLog($p);
    $chips = $scan['chips'] ?? [];
    $loaded = fn (string $m) => is_dir($p['modules'] . "/$m");
    $tool = fn (string $id) => (bool) array_filter($scan['tools'] ?? [], fn ($t) => ($t['id'] ?? '') === $id && !empty($t['installed']));
    $out = [];
    // 1 the BIOS reserves the chip (ACPI): the start option, unless one is set already
    $conflict = fanboyAcpiConflict($log);
    if ($conflict !== null && !fanboyCmdlineHas($p, 'acpi_enforce_resources=lax') && !fanboyCmdlineHas($p, 'it87.ignore_resource_conflict=1')) {
        $ite = $conflict['ite'] || ($scan['board_hint'] ?? null) === 'ite' || (($chip['chip'] ?? null)['maker'] ?? null) === 'ITE';
        $out[] = ['code' => 'acpi_conflict', 'ite' => $ite];
    }
    // 2 a fan chip driver loaded with no chip bound to it (or the kernel log's «No such device» for it): the chip is newer
    foreach (FANBOY_DOCTOR_DRIVERS as $m => $plugin) {
        $bound = (bool) array_filter($chips, fn ($c) => ($c['module'] ?? null) === $m);
        $nodev = (bool) array_filter($log, fn ($l) => preg_match('/\b' . $m . '\b.*No such device/i', $l) === 1);
        if (!$bound && ($loaded($m) || $nodev)) {
            $name = (string) (($chip['chip'] ?? null)['name'] ?? '');
            $plugin ??= str_starts_with($m, 'nct') && preg_match('/NCT668[367]/', $name) ? 'nct6687-driver' : null;
            $out[] = ['code' => 'not_bound', 'module' => $m, 'plugin' => $plugin, 'installed' => $plugin !== null && $tool($plugin)];
        }
    }
    // 3 the read-only driver: nct6683 shows the fans of NCT6687D/6686D boards but writes nothing
    if (array_filter($chips, fn ($c) => $c['name'] === 'nct6683' || ($c['module'] ?? null) === 'nct6683') && !$loaded('nct6687')) {
        $out[] = ['code' => 'read_only', 'module' => 'nct6683', 'installed' => $tool('nct6687-driver')];
    }
    // 4 a BMC: the fans are its (the page merges this into its IPMI banner where that one stands)
    if (($scan['ipmi'] ?? null) !== null || $loaded('ipmi_si') || $loaded('ipmi_devintf')) {
        $out[] = ['code' => 'bmc', 'seen' => ($scan['ipmi'] ?? null) !== null];
    }
    // 5 DDR5's SPD hub write-protected by the BIOS: the RAM's sensors limited or absent
    if (array_filter($log, fn ($l) => stripos($l, 'SPD Write Disable is set') !== false)) {
        $out[] = ['code' => 'spd_wd'];
    }
    // 6 NVMe behind Intel VMD, and no NVMe temperature: that's why
    $vmd = array_filter(@scandir($p['vmd']) ?: [], fn ($e) => preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $e) === 1);
    if ($vmd && !array_filter($chips, fn ($c) => $c['kind'] === 'nvme' && $c['temps'])) {
        $out[] = ['code' => 'vmd'];
    }
    // 7 the kernel locked down (Secure Boot, mostly): outside drivers and the chip's read refused
    if (fanboyLockedDown($p)) {
        preg_match('/\[(\w+)\]/', (string) @file_get_contents($p['lockdown'], false, null, 0, 256), $m);
        $out[] = ['code' => 'lockdown', 'mode' => $m[1] ?? ''];
    }
    // 8 an output in DC mode whose test didn't come out clean (ignored by the BIOS, the wrong way round, unclear)
    $dc = [];
    foreach ($chips as $c) {
        foreach ($c['outputs'] as $o) {
            $id = fanboyFanId($c['name'], $c['address'], $o['n']);
            if (in_array(fanboyFound($fans[$id] ?? null), ['ignored', 'inverted', 'unclear'], true) && fanboyReadInt($p['hwmon'] . "/{$c['hwmon']}/pwm{$o['n']}_mode") === 0) {
                $dc[] = $id;
            }
        }
    }
    if ($dc) {
        $out[] = ['code' => 'dc_mode', 'fans' => $dc];
    }
    // 9 AMD: k10temp's Tctl (a control value, with an offset on some CPUs) beside Tdie (the measured one)
    foreach ($chips as $c) {
        $labels = array_column($c['temps'], 'label');
        if ($c['name'] === 'k10temp' && in_array('Tctl', $labels, true) && in_array('Tdie', $labels, true)) {
            $out[] = ['code' => 'tctl'];
            break;
        }
    }
    return $out;
}

/**
 * The report's lines for the signs (one each; never a path, a serial or a name given)
 *
 * @param list<array<string, mixed>> $signs
 * @return list<string>
 */
function fanboyDoctorLines(array $signs, array $scan): array
{
    $lines = [];
    foreach ($signs as $s) {
        $line = match ($s['code']) {
            'acpi_conflict' => 'Hint: ACPI resource conflict at the fan chip in the kernel log' . ($s['ite'] ? ' (ITE)' : '') . ', no start option set',
            'not_bound'     => 'Hint: driver ' . $s['module'] . ' loaded without a chip' . ($s['plugin'] ? ', plugin ' . $s['plugin'] . ($s['installed'] ? ' installed' : ' not installed') : ''),
            'read_only'     => 'Hint: read-only driver nct6683, plugin nct6687-driver ' . ($s['installed'] ? 'installed' : 'not installed'),
            'bmc'           => ($scan['ipmi'] ?? null) !== null ? null : 'Hint: BMC driver loaded (ipmi), no BMC seen',
            'spd_wd'        => 'Hint: SPD Write Disable is set (kernel log)',
            'vmd'           => 'Hint: Intel VMD active, no NVMe temperature',
            'lockdown'      => 'Hint: kernel lockdown ' . (preg_match('/^\w{1,20}$/D', (string) $s['mode']) ? $s['mode'] : '?'),
            'dc_mode'       => 'Hint: DC mode where the test was not clean: ' . implode(', ', array_map(fn ($id) => (string) preg_replace('/^.*:/', '', (string) $id), $s['fans'])),
            'tctl'          => 'Hint: CPU shows Tctl and Tdie (k10temp)',
            default         => null,
        };
        if ($line !== null) {
            $lines[] = $line;
        }
    }
    return $lines;
}
