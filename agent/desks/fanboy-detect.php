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
 *   /boot/config/plugins           FanCtrl Plus (fanctrlplus/*.cfg with service="1"; its fork fanctrlplus2), Dynamix
 *                                  System Autofan (dynamix.system.autofan/fan.cfg), the plugins he knows (FANBOY_PLUGINS:
 *                                  their .plg), Dynamix System Temp's sensors.conf (the names given there, read only)
 *   /proc/cmdline                  the start option acpi_enforce_resources=lax (set or not)
 *   /sys/module, /proc/N/cmdline   a driver's module loaded; another controller's loop running
 *
 * NOTHING here writes — not to a fan, not to a file (tests/run.php greps the desk's files for a write to pwm).
 * The paths come from fanboyPaths(): the real ones, or — only from the tests — the same layout under a fake root
 * (tests/run.php fanboyFakeTree()); never from a request.
 */

const FANBOY_HWMON = '/sys/class/hwmon';
const FANBOY_NAME_RE = '/^[A-Za-z0-9_.:-]{1,48}$/D';        // a chip's name, a device's address, a module
/*
 * The plugins Fanboy knows — he installs none of them, he only says which fits and what is there. Each name and author
 * exactly as Community Applications lists it: checked against its public feed (github.com/Squidly271/AppFeed,
 * applicationFeed.json, «last_updated» 2026-11-14 03:08) on 2026-11-14 — a plugin not found there isn't listed.
 *   id = its plugin file on the flash (/boot/config/plugins/<id>.plg)
 *   kind driver: modules it brings (loaded = /sys/module/<m>); sensors: Dynamix System Temp (finds and loads drivers,
 *   names sensors); controller: steers fans itself — [folder, its settings files (glob), its loop's command line (regex)]
 */
const FANBOY_PLUGINS = [
    'it87-driver'            => ['name' => 'ITE IT87 Driver', 'author' => 'ich777', 'kind' => 'driver', 'modules' => ['it87'], 'chip' => 'ite'],
    'nct6687-driver'         => ['name' => 'Nuvoton NCT6687 Driver', 'author' => 'ich777', 'kind' => 'driver', 'modules' => ['nct6687'], 'chip' => 'nuvoton'],
    'dynamix.system.temp'    => ['name' => 'Dynamix System Temp', 'author' => 'Bergware', 'kind' => 'sensors'],
    'fanctrlplus'            => ['name' => 'FanCtrl Plus', 'author' => 'ck9393', 'kind' => 'controller',
                                 'controller' => ['fanctrlplus', '*.cfg', '/fanctrlplus(?!2)/']],
    'fanctrlplus2'           => ['name' => 'FanCtrl Plus 2', 'author' => 'ck9393 / andrebrait', 'kind' => 'controller',
                                 'controller' => ['fanctrlplus2', '*.cfg', '/fanctrlplus2/']],
    'dynamix.system.autofan' => ['name' => 'Dynamix System Autofan', 'author' => 'Bergware/gfjardim', 'kind' => 'controller',
                                 'controller' => ['dynamix.system.autofan', 'fan.cfg', '/dynamix\.system\.autofan|\bautofan\b/']],
];
// a hint by the board's maker, never a verdict: whose chip such boards mostly carry
const FANBOY_BOARD_HINTS = ['/gigabyte/i' => ['ite', 'Gigabyte'], '/micro-star|\bmsi\b/i' => ['nuvoton', 'MSI']];
const FANBOY_SYSTEMP_CONF = 'dynamix.system.temp/sensors.conf';      // under the plugins folder: read, never written
const FANBOY_NAMES_MAX = 200;
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
        'cmdline'  => "$r/proc/cmdline",
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
    // the driver's module: on the device, or — for a class device (nvme0 under its PCI device) — on a parent, two up at most
    $module = null;
    for ($d = $device, $i = 0; $d !== null && $module === null && $i < 3; $d = dirname($d), $i++) {
        $driver = realpath("$d/driver");
        $module = $driver !== false ? (realpath("$driver/module") ?: null) : null;
    }
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
 * Another controller: FanCtrl Plus (or its fork), Dynamix System Autofan — its settings switched on (service="1") or its
 * loop running.
 *
 * @return list<array{id:string, name:string, settings:bool, running:bool}>
 */
function fanboyControllers(array $p): array
{
    $cmdlines = fanboyCmdlines($p['proc']);
    $out = [];
    foreach (FANBOY_PLUGINS as $id => $plugin) {
        if (!isset($plugin['controller'])) {
            continue;
        }
        [$folder, $files, $marker] = $plugin['controller'];
        $settings = false;
        foreach (glob($p['plugins'] . "/$folder/$files") ?: [] as $f) {
            if (!is_link($f) && is_file($f) && (readCfg($f)['service'] ?? '') === '1') {
                $settings = true;
                break;
            }
        }
        $running = (bool) array_filter($cmdlines, fn ($c) => preg_match($marker, $c) === 1);
        if ($settings || $running) {
            $out[] = ['id' => $id, 'name' => $plugin['name'], 'settings' => $settings, 'running' => $running];
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
    foreach (FANBOY_PLUGINS as $plugin => $info) {
        $modules = $info['modules'] ?? [];
        if (!$modules || !is_file($p['plugins'] . "/$plugin.plg")) {
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
 * What of the plugins Fanboy knows is there: installed (its .plg on the flash); a driver's modules loaded; a controller
 * steering now (fanboyControllers()). Every known plugin, in the catalogue's order.
 *
 * @return list<array{id:string, name:string, author:string, kind:string, installed:bool, loaded:?bool, active:?bool}>
 */
function fanboyTools(array $p, array $chips, array $controllers): array
{
    $seen = array_filter(array_column($chips, 'module'));
    $active = array_column($controllers, 'id');
    $out = [];
    foreach (FANBOY_PLUGINS as $id => $info) {
        $plg = $p['plugins'] . "/$id.plg";
        $modules = $info['modules'] ?? [];
        $out[] = [
            'id'        => $id,
            'name'      => $info['name'],
            'author'    => $info['author'],
            'kind'      => $info['kind'],
            'installed' => !is_link($plg) && is_file($plg),
            'loaded'    => $modules ? (bool) array_filter($modules, fn ($m) => is_dir($p['modules'] . "/$m") || in_array($m, $seen, true)) : null,
            'active'    => $info['kind'] === 'controller' ? in_array($id, $active, true) : null,
        ];
    }
    return $out;
}

/** The start option that lets a driver share the chip with the BIOS (acpi_enforce_resources=lax) — set at this boot? */
function fanboyAcpiLax(string $cmdline): bool
{
    return preg_match('/(^|\s)acpi_enforce_resources=lax(\s|$)/', (string) @file_get_contents($cmdline, false, null, 0, 4096)) === 1;
}

/**
 * The names the user gave in Dynamix System Temp (its sensors.conf, lm-sensors' words: `chip "<name>-<bus>-<addr>"`,
 * `label <feature> "<text>"`, `ignore <feature>`) for the chips seen now — suggestions for the later setup, read only.
 * A chip line matches a chip by its name (or `*`) and, on the isa bus, its address (it87.2624 = isa 0a40).
 *
 * @return list<array{chip:string, address:string, feature:string, label:?string, ignore:bool}>
 */
function fanboySensorNames(string $conf, array $chips): array
{
    $text = is_link($conf) ? false : @file_get_contents($conf, false, null, 0, 65536);
    if (!is_string($text)) {
        return [];
    }
    $out = [];
    $current = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim((string) preg_replace('/#.*$/', '', $line));
        if (preg_match('/^chip\s+(.+)$/', $line, $m)) {
            $current = [];
            preg_match_all('/"([^"]*)"|(\S+)/', $m[1], $pats, PREG_SET_ORDER);
            foreach ($pats as $pm) {
                $pat = ($pm[1] ?? '') !== '' ? $pm[1] : ($pm[2] ?? '');
                foreach ($chips as $c) {
                    if (fanboyChipMatches($pat, $c) && !in_array($c, $current, true)) {
                        $current[] = $c;
                    }
                }
            }
        } elseif ($current && preg_match('/^(label|ignore)\s+([a-z]+\d{1,3})(?:\s+"([^"]{0,64})")?\s*$/D', $line, $m)) {
            $label = $m[1] === 'label' ? trim((string) preg_replace('/[^\x20-\x7e\x{a0}-\x{17f}]+/u', ' ', $m[3] ?? '')) : '';
            foreach ($current as $c) {
                $out[] = ['chip' => $c['name'], 'address' => $c['address'], 'feature' => $m[2], 'label' => $label !== '' ? $label : null, 'ignore' => $m[1] === 'ignore'];
            }
        }
        if (count($out) >= FANBOY_NAMES_MAX) {
            break;
        }
    }
    return array_slice($out, 0, FANBOY_NAMES_MAX);
}

/** lm-sensors' chip pattern («it8696-isa-0a40», «coretemp-*», «*-isa-*») against a chip seen now */
function fanboyChipMatches(string $pat, array $chip): bool
{
    $parts = explode('-', $pat);
    $prefix = $parts[0];
    $bus = $parts[1] ?? '*';
    $addr = $parts[2] ?? '*';
    $name = $chip['name'];
    if ($prefix !== '*' && $prefix !== $name && !str_starts_with($name, "{$prefix}_")) {
        return false;
    }
    if ($bus === 'isa' && $addr !== '*' && ctype_xdigit($addr)) {
        return preg_match('/\.(\d+)$/D', $chip['address'], $m) === 1 && (int) $m[1] === hexdec($addr);
    }
    return true;
}

/**
 * Which plugins fit what Fanboy found — only those, never all: no output Linux shows → the driver plugins not yet
 * installed (with the board maker's hint only the one it points at) and Dynamix System Temp when it isn't there (it
 * finds and loads drivers); ok, other, ipmi, a driver plugin there but not loaded → none (the hints say what helps).
 *
 * @return list<string>  plugin ids
 */
function fanboySuggest(string $situation, array $tools, ?string $boardHint): array
{
    if ($situation !== 'no_output') {
        return [];
    }
    $missing = fn (string $kind) => array_column(array_filter($tools, fn ($t) => $t['kind'] === $kind && !$t['installed']), 'id');
    $drivers = $missing('driver');
    if ($boardHint !== null) {
        $hinted = array_values(array_filter($drivers, fn ($id) => (FANBOY_PLUGINS[$id]['chip'] ?? null) === $boardHint));
        $drivers = $hinted ?: $drivers;
    }
    return array_values(array_merge($drivers, $missing('sensors')));
}

/** The board maker's hint (FANBOY_BOARD_HINTS): [ite|nuvoton, the maker's short name] — or [null, null] */
function fanboyBoardHint(array $board): array
{
    foreach (FANBOY_BOARD_HINTS as $re => $hint) {
        if (preg_match($re, (string) ($board['vendor'] ?? ''))) {
            return $hint;
        }
    }
    return [null, null];
}

/**
 * The situation, the first that holds: another controller (outputs there, FanCtrl Plus or Dynamix System Autofan on) ·
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
    $board = fanboyBoard($p['dmi']);
    $situation = fanboySituation($chips, $controllers, $ipmi, $driversMissing);
    $tools = fanboyTools($p, $chips, $controllers);
    [$hint, $maker] = fanboyBoardHint($board);
    $fans = array_merge(...array_map(fn ($c) => $c['fans'], $chips) ?: [[]]);
    $turning = array_values(array_filter(array_column($fans, 'rpm'), fn ($r) => $r !== null && $r > 0));
    return [
        'v'           => 1,
        'time'        => $now ?? time(),
        'hwmon'       => is_dir($p['hwmon']),
        'board'       => $board,
        'kernel'      => $root === null ? php_uname('r') : 'test',
        'unraid'      => reportUnraidVersion(['unraid_version' => $p['unraid']]),
        'chips'       => $chips,
        'disks'       => $disks,
        'ipmi'        => $ipmi,
        'controllers' => $controllers,
        'drivers_missing' => $driversMissing,
        'situation'   => $situation,
        // the hints (round 2): what is there, what fits, the start option, the names given in System Temp
        'tools'       => $tools,
        'suggest'     => fanboySuggest($situation['code'], $tools, $hint),
        'board_hint'  => $hint,
        'board_maker' => $maker,
        'acpi_lax'    => fanboyAcpiLax($p['cmdline']),
        'names'       => fanboySensorNames($p['plugins'] . '/' . FANBOY_SYSTEMP_CONF, $chips),
        'hints_open'  => in_array($situation['code'], ['no_output', 'driver_missing', 'other', 'ipmi'], true),
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
