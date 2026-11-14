<?php
declare(strict_types=1);

/*
 * Fanboy's look at the hardware («Erkennen», part 1 of the desk): read only, cheap enough for every few seconds.
 *
 * What it reads, and nothing else:
 *   /sys/class/hwmon/hwmonN        the chips Linux shows: name, the device behind it (its path WITHOUT hwmonN — the number
 *                                  Linux hands out anew at every boot), the driver's module; pwmN (value, pwmN_enable, an
 *                                  automatic mode the chip has), fanN_input (rpm), tempN_input/tempN_label
 *   /sys/class/dmi/id              board_vendor, board_name (never a serial)
 *   /var/local/emhttp/disks.ini    Unraid's own disk temperatures and sleep — never smartctl, never drivetemp's files
 *                                  (reading those asks the disk), nothing that wakes a disk
 *   /dev/ipmi*, /sys/class/ipmi, /sys/devices/platform/ipmi_bmc.*   a BMC (IPMI board): its ids, firmware revision
 *   /boot/config/plugins           FanControl Plus (fanctrlplus/*.cfg with service="1"), Dynamix Auto Fan
 *                                  (dynamix.system.autofan/fan.cfg), the driver plugins (it87-driver.plg …)
 *   /sys/module, /proc/N/cmdline   a driver's module loaded; another controller's loop running
 *
 * NOTHING here writes — not to a fan, not to a file (tests/run.php greps the desk's files for a write to pwm).
 * The paths come from fanboyPaths(): the real ones, or — only from the tests — the same layout under a fake root
 * (tests/run.php fanboyFakeTree()); never from a request.
 */

const FANBOY_HWMON = '/sys/class/hwmon';
const FANBOY_NAME_RE = '/^[A-Za-z0-9_.:-]{1,48}$/D';        // a chip's name, a device's address, a module
// the driver plugins Fanboy knows (Unraid's plugin file => the modules they bring); he brings no driver himself
const FANBOY_DRIVER_PLUGINS = ['it87-driver' => ['it87'], 'nct6687-driver' => ['nct6687']];
// another controller: [its id => [plugin folder, its settings files (glob), what its loop's command line holds, its name]]
const FANBOY_CONTROLLERS = [
    'fancontrolplus' => ['fanctrlplus', '*.cfg', 'fanctrlplus', 'FanControl Plus'],
    'autofan'        => ['dynamix.system.autofan', 'fan.cfg', 'dynamix.system.autofan', 'Dynamix Auto Fan'],
];
const FANBOY_PROC_MAX = 4096;                                // processes looked at for another controller's loop
const FANBOY_TEMP_KINDS = [
    'cpu'   => '/^(coretemp|k10temp|k8temp|zenpower|via_cputemp|cpu_thermal|fam15h_power)$/',
    'nvme'  => '/^nvme$/',
    'ram'   => '/^(spd5118|jc42|ee1004)$/',
    'gpu'   => '/^(amdgpu|radeon|nouveau|i915|xe|nvidia)$/',
    'disk'  => '/^drivetemp$/',
    'board' => '/^(it\d{4}\w*|it87\w*|nct\d{4}\w*|f71\d{3}\w*|f81\d{3}\w*|w83\w+|gigabyte_wmi|asus_wmi_sensors|asus_ec_sensors|asusec\w*|'
             . 'dell_smm|thinkpad|acpitz|pch_\w+|sch5\d{3}\w*|nct6687|nct6683|lm\d{2,3}|adt7\w+|emc\d{4}\w*)$/',
];

/**
 * Where to look: the real paths, or the same layout under $root (the tests' fake tree).
 *
 * @return array<string, string>
 */
function fanboyPaths(?string $root = null): array
{
    $r = $root === null ? '' : rtrim($root, '/');
    return [
        'hwmon'    => $r . FANBOY_HWMON,
        'dmi'      => "$r/sys/class/dmi/id",
        'disks'    => $root === null ? disksIniFile() : "$r/var/local/emhttp/disks.ini",
        'dev'      => "$r/dev",
        'ipmi'     => "$r/sys/class/ipmi",
        'platform' => "$r/sys/devices/platform",
        'plugins'  => "$r/boot/config/plugins",
        'modules'  => "$r/sys/module",
        'proc'     => "$r/proc",
        'unraid'   => "$r/etc/unraid-version",
        'ipmitool' => $root === null ? '' : "$r/usr/bin/ipmitool",     // '' = found in the PATH (fanboyIpmiFans())
    ];
}

/** A short line of a sysfs or DMI file: trimmed, printable, at most 64 characters ('' when it isn't there) */
function fanboyLine(string $file): string
{
    $s = @file_get_contents($file, false, null, 0, 256);
    if (!is_string($s)) {
        return '';
    }
    $s = trim((string) preg_replace('/[^\x20-\x7e]+/', ' ', strtok($s . "\n", "\n") ?: ''));
    return mb_substr((string) preg_replace('/\s+/', ' ', $s), 0, 64);
}

/** An integer of a sysfs file, null when it isn't one (empty: a sensor that has nothing to say) */
function fanboyInt(string $file): ?int
{
    $s = @file_get_contents($file, false, null, 0, 32);
    return is_string($s) && preg_match('/^\s*(-?\d{1,12})\s*$/D', $s, $m) ? (int) $m[1] : null;
}

/** What a temperature of this chip is: cpu, nvme, ram, gpu, disk, board or other */
function fanboyKind(string $chip): string
{
    foreach (FANBOY_TEMP_KINDS as $kind => $re) {
        if (preg_match($re, $chip)) {
            return $kind;
        }
    }
    return 'other';
}

/**
 * One chip of /sys/class/hwmon: its name, the device behind it without the boot number, the module, its outputs,
 * tach inputs and temperatures. Null for what is no hwmon entry.
 */
function fanboyChip(string $hwmon, string $entry): ?array
{
    $dir = "$hwmon/$entry";
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) {
        return null;
    }
    $device = realpath("$dir/device") ?: null;
    // the attributes lie in hwmonN (today's drivers) or, for an old driver, in its device's folder
    $attr = is_file("$dir/name") ? $dir : ($device !== null && is_file("$device/name") ? $device : $dir);
    $name = fanboyLine("$attr/name");
    if (!preg_match(FANBOY_NAME_RE, $name)) {
        $name = '?';
    }
    // the stable address: the device's path under /sys/devices (pci0000:00/…/nvme1, platform/it87.2624), else the
    // hwmon folder's own parent — never the hwmonN part
    $where = $device ?? dirname(dirname($real));
    $pos = strrpos($where, '/devices/');
    $stable = $pos === false ? basename($where) : substr($where, $pos + 9);
    $driver = $device !== null ? realpath("$device/driver") : false;
    $module = $driver !== false ? (realpath("$driver/module") ?: null) : null;
    $files = @scandir($attr) ?: [];
    $outputs = $fans = $temps = [];
    foreach ($files as $f) {
        if (preg_match('/^pwm(\d{1,2})$/D', $f, $m)) {
            $n = (int) $m[1];
            $enable = fanboyInt("$attr/pwm{$n}_enable");
            $auto = ($enable !== null && $enable >= 2) || is_file("$attr/pwm{$n}_temp_sel")
                || (bool) array_filter($files, fn ($x) => str_starts_with($x, "pwm{$n}_auto_"));
            $value = fanboyInt("$attr/$f");
            $outputs[$n] = [
                'n'      => $n,
                'value'  => $value,
                'pct'    => $value === null ? null : (int) round(max(0, min(255, $value)) / 2.55),
                'enable' => $enable,
                'mode'   => $enable === null ? null : ($enable === 0 ? 'full' : ($enable === 1 ? 'manual' : 'auto')),
                'auto'   => $auto,
            ];
        } elseif (preg_match('/^fan(\d{1,2})_input$/D', $f, $m)) {
            $fans[(int) $m[1]] = ['n' => (int) $m[1], 'rpm' => fanboyInt("$attr/$f"), 'label' => fanboyLine("$attr/fan{$m[1]}_label")];
        } elseif (preg_match('/^temp(\d{1,3})_input$/D', $f, $m)) {
            $temps[(int) $m[1]] = ['n' => (int) $m[1], 'label' => fanboyLine("$attr/temp{$m[1]}_label")];
        }
    }
    $kind = fanboyKind($name);
    foreach ($temps as $n => $t) {
        // drivetemp asks the disk for its temperature: never read here (a sleeping disk would wake) — Unraid's own
        // disks.ini says it instead
        $raw = $kind === 'disk' ? null : fanboyInt("$attr/temp{$n}_input");
        $temps[$n]['c'] = $raw === null || $raw < -40000 || $raw > 200000 ? null : round($raw / 1000, 1);
    }
    ksort($outputs);
    ksort($fans);
    ksort($temps);
    return [
        'hwmon'   => $entry,                   // this boot's number: shown, never stored
        'name'    => $name,
        'device'  => $stable,
        'address' => basename($stable),
        'module'  => $module !== null ? basename($module) : null,
        'kind'    => $kind,
        'outputs' => array_values($outputs),
        'fans'    => array_values($fans),
        'temps'   => array_values($temps),
    ];
}

/** The chips under the hwmon folder, in its order (hwmon0, hwmon1 … hwmon10) */
function fanboyChips(string $hwmon): array
{
    $entries = array_values(array_filter(@scandir($hwmon) ?: [], fn ($e) => preg_match('/^hwmon\d{1,4}$/D', $e) === 1));
    natsort($entries);
    $chips = [];
    foreach ($entries as $e) {
        if (($c = fanboyChip($hwmon, $e)) !== null) {
            $chips[] = $c;
        }
    }
    return $chips;
}

/** The board: DMI's vendor and model (never a serial) — '' each when the board says nothing */
function fanboyBoard(string $dmi): array
{
    $clean = fn (string $s) => preg_match('/^(to be filled by o\.e\.m\.|default string|not applicable|none|n\/a|system manufacturer|system product name)$/i', $s)
        ? '' : str_replace('/', '-', $s);
    return ['vendor' => $clean(fanboyLine("$dmi/board_vendor")), 'model' => $clean(fanboyLine("$dmi/board_name"))];
}

/** Unraid's disks from disks.ini (its bookkeeping, no disk asked): name, role, temperature (null asleep or unknown), asleep */
function fanboyDisks(string $ini): array
{
    $out = [];
    foreach (readCfg($ini, true) as $section => $d) {
        $type = (string) ($d['type'] ?? '');
        if ($type === 'Flash' || ($d['device'] ?? '') === '' || str_starts_with((string) ($d['status'] ?? ''), 'DISK_NP')) {
            continue;
        }
        $asleep = diskAsleep($d);
        $out[] = [
            'name'       => (string) ($d['name'] ?? $section),
            'type'       => $type === 'Parity' ? 'parity' : ($type === 'Data' ? 'data' : 'pool'),
            'rotational' => ($d['rotational'] ?? '1') !== '0',
            'asleep'     => $asleep,
            'temp'       => !$asleep && ctype_digit((string) ($d['temp'] ?? '')) ? (int) $d['temp'] : null,
        ];
    }
    return $out;
}

/** A BMC (an IPMI board): whether there is one, and its ids as the kernel shows them (null: none) */
function fanboyIpmi(array $p): ?array
{
    $dev = glob($p['dev'] . '/ipmi[0-9]*') ?: [];
    $class = array_values(array_filter(@scandir($p['ipmi']) ?: [], fn ($e) => $e[0] !== '.'));
    $bmc = glob($p['platform'] . '/ipmi_bmc.*') ?: [];
    if (!$dev && !$class && !$bmc) {
        return null;
    }
    $ids = [];
    foreach (['manufacturer_id', 'product_id', 'firmware_revision', 'ipmi_version', 'device_id'] as $f) {
        $v = $bmc ? fanboyLine($bmc[0] . "/$f") : '';
        $ids[$f] = preg_match('/^[0-9A-Za-z.x ]{1,24}$/D', $v) ? $v : null;
    }
    return ['bmc' => (bool) $bmc] + $ids;
}

/**
 * Another controller: FanControl Plus, Dynamix Auto Fan — its settings switched on (service="1") or its loop running.
 *
 * @return list<array{id:string, name:string, settings:bool, running:bool}>
 */
function fanboyControllers(array $p): array
{
    $cmdlines = fanboyCmdlines($p['proc']);
    $out = [];
    foreach (FANBOY_CONTROLLERS as $id => [$folder, $files, $marker, $name]) {
        $settings = false;
        foreach (glob($p['plugins'] . "/$folder/$files") ?: [] as $f) {
            if (!is_link($f) && is_file($f) && (readCfg($f)['service'] ?? '') === '1') {
                $settings = true;
                break;
            }
        }
        $running = (bool) array_filter($cmdlines, fn ($c) => str_contains($c, $marker));
        if ($settings || $running) {
            $out[] = ['id' => $id, 'name' => $name, 'settings' => $settings, 'running' => $running];
        }
    }
    return $out;
}

/** Every process's command line (spaces for its NULs), the agent's own left out */
function fanboyCmdlines(string $proc): array
{
    $out = [];
    $me = (string) getmypid();
    foreach (array_slice(@scandir($proc) ?: [], 0, FANBOY_PROC_MAX) as $pid) {
        if (ctype_digit($pid) && $pid !== $me) {
            $c = @file_get_contents("$proc/$pid/cmdline", false, null, 0, 1024);
            if (is_string($c) && $c !== '') {
                $out[] = str_replace("\0", ' ', $c);
            }
        }
    }
    return $out;
}

/** The driver plugins installed whose module isn't loaded: [plugin => [modules]] */
function fanboyDriversMissing(array $p, array $chips): array
{
    $seen = array_filter(array_column($chips, 'module'));
    $out = [];
    foreach (FANBOY_DRIVER_PLUGINS as $plugin => $modules) {
        if (!is_file($p['plugins'] . "/$plugin.plg")) {
            continue;
        }
        $missing = array_values(array_filter($modules, fn ($m) => !is_dir($p['modules'] . "/$m") && !in_array($m, $seen, true)));
        if ($missing) {
            $out[$plugin] = $missing;
        }
    }
    return $out;
}

/** A chip whose outputs Fanboy could ever offer: no graphics card's (its own fan stays the card's) */
function fanboyOffers(array $chip): bool
{
    return $chip['outputs'] && $chip['kind'] !== 'gpu';
}

/**
 * The situation, the first that holds: another controller (outputs there, FanControl Plus or Dynamix Auto Fan on) ·
 * ok · ipmi (no output Linux shows, a BMC there) · driver_missing (a driver plugin installed, its module not loaded) ·
 * no_output.
 */
function fanboySituation(array $chips, array $controllers, ?array $ipmi, array $driversMissing): array
{
    $outputs = array_sum(array_map(fn ($c) => fanboyOffers($c) ? count($c['outputs']) : 0, $chips));
    if ($outputs > 0) {
        return $controllers ? ['code' => 'other', 'who' => array_column($controllers, 'id'), 'names' => array_column($controllers, 'name')] : ['code' => 'ok'];
    }
    if ($ipmi !== null) {
        return ['code' => 'ipmi'];
    }
    if ($driversMissing) {
        return ['code' => 'driver_missing', 'plugins' => array_keys($driversMissing), 'modules' => array_merge(...array_values($driversMissing))];
    }
    return ['code' => 'no_output'];
}

/** The warmest place: a temperature of a chip or an awake disk — null when nothing says one */
function fanboyHottest(array $chips, array $disks): ?array
{
    $best = null;
    foreach ($chips as $c) {
        foreach ($c['temps'] as $t) {
            if ($t['c'] !== null && ($best === null || $t['c'] > $best['c'])) {
                $best = ['c' => $t['c'], 'kind' => $c['kind'], 'chip' => $c['name'], 'label' => $t['label'], 'n' => $t['n'], 'disk' => null];
            }
        }
    }
    foreach ($disks as $d) {
        if ($d['temp'] !== null && ($best === null || $d['temp'] > $best['c'])) {
            $best = ['c' => (float) $d['temp'], 'kind' => 'disk', 'chip' => null, 'label' => '', 'n' => null, 'disk' => $d['name']];
        }
    }
    return $best;
}

/**
 * Fanboy's whole look: the board, the chips, Unraid's disks, a BMC, other controllers, the situation, the warmest place.
 * $root only from the tests (their fake tree); the agent calls it without.
 */
function fanboyDetect(?string $root = null, ?int $now = null): array
{
    $p = fanboyPaths($root);
    $chips = fanboyChips($p['hwmon']);
    $disks = fanboyDisks($p['disks']);
    $ipmi = fanboyIpmi($p);
    $controllers = fanboyControllers($p);
    $driversMissing = fanboyDriversMissing($p, $chips);
    $fans = array_merge(...array_map(fn ($c) => $c['fans'], $chips) ?: [[]]);
    $turning = array_values(array_filter(array_column($fans, 'rpm'), fn ($r) => $r !== null && $r > 0));
    return [
        'v'           => 1,
        'time'        => $now ?? time(),
        'hwmon'       => is_dir($p['hwmon']),
        'board'       => fanboyBoard($p['dmi']),
        'kernel'      => $root === null ? php_uname('r') : 'test',
        'unraid'      => reportUnraidVersion(['unraid_version' => $p['unraid']]),
        'chips'       => $chips,
        'disks'       => $disks,
        'ipmi'        => $ipmi,
        'controllers' => $controllers,
        'drivers_missing' => $driversMissing,
        'situation'   => fanboySituation($chips, $controllers, $ipmi, $driversMissing),
        'counts'      => [
            'outputs' => array_sum(array_map(fn ($c) => fanboyOffers($c) ? count($c['outputs']) : 0, $chips)),
            'fans'    => count($fans),
            'turning' => count($turning),
            'zero'    => count(array_filter(array_column($fans, 'rpm'), fn ($r) => $r === 0)),      // 0 rpm (nothing said: not counted)
            'rpm_min' => $turning ? min($turning) : null,
            'rpm_max' => $turning ? max($turning) : null,
            'temps'   => array_sum(array_map(fn ($c) => count($c['temps']), $chips)),
        ],
        'hottest'     => fanboyHottest($chips, $disks),
        // who steers the fans now — part 1: never Fanboy (the BIOS; another plugin; a BMC), with the reason
        'who'         => fanboyWho($controllers, $ipmi, $chips),
    ];
}

/** Who steers now and why (part 1: Fanboy never) */
function fanboyWho(array $controllers, ?array $ipmi, array $chips): array
{
    if ($controllers && array_filter($chips, 'fanboyOffers')) {
        return ['who' => $controllers[0]['id'], 'name' => $controllers[0]['name'], 'why' => 'other'];
    }
    if ($ipmi !== null && !array_filter($chips, 'fanboyOffers')) {
        return ['who' => 'bmc', 'why' => 'ipmi'];
    }
    return ['who' => 'bios', 'why' => array_filter($chips, 'fanboyOffers') ? 'not_set_up' : 'no_output'];
}
