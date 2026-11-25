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
    // part 2 (2026-11-14, read in their sources on GitHub): PWM Fan steers from root's crontab (its scripts pwm-fan.sh,
    // pwm_hdd.sh, pwm_hdd_extra.sh — «running» = such a line in the crontab, or one of them running now; its settings
    // say «started» for each it scheduled); WAZ Cooling Control (md12xx.fancontrol) steers Dell MD1200/MD1220 shelves
    // (SES) and Dell PowerEdge chassis fans through IPMI — not a board's hwmon outputs. Part 3 (the coordinator,
    // 2026-11-14): it no longer holds Fanboy back — named as a hint only ('hint_only'); the guard against a foreign write
    // on his outputs stays the safety net
    'pwm-fan'                => ['name' => 'PWM Fan', 'author' => 'alturismo', 'kind' => 'controller',
                                 'controller' => ['pwm-fan', 'pwmfansettings', '#pwm-fan/scripts/(pwm-fan|pwm_hdd|pwm_hdd_extra)\.sh#'],
                                 'on' => [['cron_state', 'started'], ['hdd_cron_state', 'started'], ['hdd_extra_cron_state', 'started']],
                                 'cron' => '#pwm-fan/scripts/(pwm-fan|pwm_hdd|pwm_hdd_extra)\.sh#'],
    'md12xx.fancontrol'      => ['name' => 'WAZ Cooling Control', 'author' => 'TheIlluminate92', 'kind' => 'controller',
                                 'controller' => ['md12xx.fancontrol', '', '#md12xx\.fancontrol/(scripts/(controller|ipmi)-supervisor\.sh|include/(ipmi-)?controller\.php)#'],
                                 'hint_only' => true],
    // round 5 (forum 3.12): controllers that are no plugin but run in a container — seen by their process (every
    // container's process is in the host's /proc: what part 1 looks at anyway, never a docker call, never in the round)
    'coolercontrol'          => ['name' => 'CoolerControl', 'author' => 'Docker', 'kind' => 'container',
                                 'controller' => ['', '', '#(^|/)coolercontrold(\s|$)#']],
    'fan2go'                 => ['name' => 'fan2go', 'author' => 'Docker', 'kind' => 'container', 'controller' => ['', '', '#(^|/)fan2go(\s|$)#']],
    'liquidctl'              => ['name' => 'liquidctl', 'author' => 'Docker', 'kind' => 'container', 'controller' => ['', '', '#(^|/)liquidctl(\s|$)#']],
    'fanbridge'              => ['name' => 'FanBridge', 'author' => 'Docker', 'kind' => 'container', 'controller' => ['', '', '#fanbridge#i']],
];
// a hint by the board's maker, never a verdict: whose chip such boards mostly carry. 2026-11-20 (fan chip id) two more,
// each from the kernel's own lists: ASUS — Nuvoton NCT67xx, Unraid's nct6775, for some 300 boards through the board's
// WMI (nct6775-platform.c asus_wmi_boards[], asus_msi_boards[]); ASRock — Nuvoton (nct6683.c's five ASRock customer
// ids, nct6775-platform.c's ASRock case)
const FANBOY_BOARD_HINTS = ['/gigabyte/i' => ['ite', 'Gigabyte'], '/micro-star|\bmsi\b/i' => ['nuvoton', 'MSI'],
                            '/asustek|\basus\b/i' => ['nuvoton_asus', 'ASUS'], '/asrock/i' => ['nuvoton_any', 'ASRock']];
// what a board's DMI says when its maker filled nothing in (fanboyBoard(); the fan chip's read: «no maker named»)
const FANBOY_DMI_PLACEHOLDER = '/^(to be filled by o\.e\.m\.|default string|not applicable|none|n\/a|system manufacturer|system product name|o\.e\.m\.|oem)$/i';
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
    $root ??= (function_exists('fanboySysRoot') && fanboySysRoot() !== '' ? fanboySysRoot() : null);     // the tests' tree, for a process of theirs
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
        'lockdown' => "$r/sys/kernel/security/lockdown",              // the fan chip's read (fanboy-chipid.php): locked down → none
        'cmdline'  => "$r/proc/cmdline",
        'unraid'   => "$r/etc/unraid-version",
        'var'      => "$r/var/local/emhttp/var.ini",                   // round 5: poll_attributes (how fresh the disks' temperatures are)
        'cpuinfo'  => "$r/proc/cpuinfo",
        'ipmitool' => $root === null ? '' : "$r/usr/bin/ipmitool",     // '' = found in the PATH (fanboyIpmiFans())
        'crontab'  => "$r/var/spool/cron/crontabs/root",
        // the driver doctor (fanboy-doctor.php, 2026-11-22): the kernel log ('' = dmesg; the tests: a file of their tree),
        // the PCI devices Intel VMD holds
        'dmesg'    => $root === null ? '' : "$r/dmesg",
        'vmd'      => "$r/sys/bus/pci/drivers/vmd",               // root's crontab (PWM Fan steers from there): read only
        // Unraid's disk temperature limits (read only): ⟦Settings⟧ → ⟦Disk Settings⟧ keeps them in dynamix.cfg [display]
        // (hot/max for hard disks, hotssd/maxssd for SSDs), its defaults in the webGui's default.cfg; a disk's own
        // (⟦Main⟧ → the disk → hotTemp/maxTemp) stand in disks.ini — the way 7.4's SmartInfo.php reads them
        'dynamix'  => "$r/boot/config/plugins/dynamix/dynamix.cfg",
        'dynamix_default' => "$r/usr/local/emhttp/plugins/dynamix/default.cfg",
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
 * tach inputs and temperatures. Null for what is no hwmon entry. $values false: the temperatures' values not read (their
 * `c` null) — the control's look «where is each sensor now?» (2026-11-25: one NVMe takes a second to answer a read)
 */
function fanboyChip(string $hwmon, string $entry, bool $values = true): ?array
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
            $fans[(int) $m[1]] = ['n' => (int) $m[1], 'rpm' => fanboySaneRpm(fanboyInt("$attr/$f")), 'label' => fanboyLine("$attr/fan{$m[1]}_label")];
        } elseif (preg_match('/^temp(\d{1,3})_input$/D', $f, $m)) {
            $temps[(int) $m[1]] = ['n' => (int) $m[1], 'label' => fanboyLine("$attr/temp{$m[1]}_label")];
        }
    }
    $kind = fanboyKind($name);
    foreach ($temps as $n => $t) {
        // drivetemp asks the disk for its temperature: never read here (a sleeping disk would wake) — Unraid's own
        // disks.ini says it instead
        $raw = $kind === 'disk' || !$values ? null : fanboyInt("$attr/temp{$n}_input");
        $temps[$n]['c'] = fanboySaneTemp($raw);
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

/** An rpm read as it counts: a sentinel (≥ FANBOY_RPM_SANE) or a negative number is no reading */
function fanboySaneRpm(?int $rpm): ?int
{
    return $rpm === null || $rpm < 0 || $rpm >= FANBOY_RPM_SANE ? null : $rpm;
}

/**
 * A temperature read as it counts (°C from millidegrees): 0, −128, 127 and anything at or over 125 °C are what broken
 * or unwired sensors report (forum 3.8: an NCT6776 CPUTIN reading nonsense) — «says nothing», never «too warm»
 */
function fanboySaneTemp(?int $milli): ?float
{
    if ($milli === null) {
        return null;
    }
    $c = round($milli / 1000, 1);
    return $c == 0.0 || $c <= -128.0 || $c >= 125.0 ? null : $c;
}

/** The chips under the hwmon folder, in its order (hwmon0, hwmon1 … hwmon10); $values false: no temperature read (fanboyChip()) */
function fanboyChips(string $hwmon, bool $values = true): array
{
    $entries = array_values(array_filter(@scandir($hwmon) ?: [], fn ($e) => preg_match('/^hwmon\d{1,4}$/D', $e) === 1));
    natsort($entries);
    $chips = [];
    foreach ($entries as $e) {
        if (($c = fanboyChip($hwmon, $e, $values)) !== null) {
            $chips[] = $c;
        }
    }
    return $chips;
}

/** The board: DMI's vendor and model (never a serial) — '' each when the board says nothing */
function fanboyBoard(string $dmi): array
{
    $clean = fn (string $s) => preg_match(FANBOY_DMI_PLACEHOLDER, $s)
        ? '' : str_replace('/', '-', $s);
    $board = ['vendor' => $clean(fanboyLine("$dmi/board_vendor")), 'model' => $clean(fanboyLine("$dmi/board_name"))];
    if ($board['vendor'] === '' && $board['model'] === '') {
        // round 5 (forum 3.13): DMI says nothing about the board — its product name and its fan chip with the chip's
        // address tell this one from another (fanboyBoardKey(); the key « | » of before stays readable)
        $chip = null;
        foreach (fanboyChips(str_replace('/sys/class/dmi/id', FANBOY_HWMON, $dmi)) as $c) {
            if (fanboyOffers($c)) {
                $chip = $c['name'] . '@' . $c['address'];
                break;
            }
        }
        $board['product'] = $clean(fanboyLine("$dmi/product_name"));
        $board['chip'] = $chip;
    }
    return $board;
}

/** A virtual machine (round 5, forum 3.16): DMI's system vendor, else the CPU's hypervisor flag — a hint only, never a block */
function fanboyVm(array $p): bool
{
    $vendor = fanboyLine($p['dmi'] . '/sys_vendor');
    if (preg_match('/qemu|vmware|microsoft corporation|xen|innotek|virtualbox|parallels|bochs|kvm/i', $vendor)) {
        return true;
    }
    $info = is_link($p['cpuinfo']) ? false : @file_get_contents($p['cpuinfo'], false, null, 0, 65536);
    return is_string($info) && preg_match('/^flags\s*:.*\bhypervisor\b/m', $info) === 1;
}

/** Unraid's poll_attributes (seconds between its looks at the disks' SMART values — their temperatures too), null: unknown */
function fanboyPollAttributes(array $p): ?int
{
    $v = readCfg($p['var'])['poll_attributes'] ?? null;
    return is_string($v) && preg_match('/^\d{1,6}$/D', $v) ? (int) $v : null;
}

/**
 * Two drivers on one chip (round 5, forum 3.10): nct6683 and nct6687 both loaded, or two hwmon devices with fan inputs
 * at the same I/O address — such a chip takes no values reliably: no steering of it, a hint. The chips' names.
 */
function fanboyTwins(array $p, array $chips): array
{
    $loaded = fn (string $m) => is_dir($p['modules'] . "/$m");
    $both = $loaded('nct6683') && $loaded('nct6687');
    $at = [];
    foreach ($chips as $c) {
        if ($c['fans'] && preg_match('#^platform/[a-z0-9_]+\.(\d+)$#D', $c['device'], $m)) {
            $at[$m[1]][] = $c['name'];
        }
    }
    $out = [];
    foreach ($chips as $c) {
        $port = preg_match('#^platform/[a-z0-9_]+\.(\d+)$#D', $c['device'], $m) ? $m[1] : null;
        if (($both && in_array($c['module'], ['nct6683', 'nct6687'], true)) || ($port !== null && count($at[$port] ?? []) > 1 && $c['fans'])) {
            $out[] = $c['name'];
        }
    }
    return array_values(array_unique($out));
}

/**
 * Unraid's disks from disks.ini (its bookkeeping, no disk asked): name, role, temperature (null asleep or unknown), asleep.
 * Round 13b (the owner: «hier fehlen doch Festplatten»): and the disks outside array and pools from devs.ini beside it
 * (fanboyDevDisks(): a VM's disks, unassigned ones) — the same rules, RAM only.
 */
function fanboyDisks(string $ini, ?array $limits = null): array
{
    return array_merge(fanboyArrayDisks($ini, $limits), fanboyDevDisks($ini, $limits));
}

/**
 * Fanboy's own rule for a disk as a heat source (2026-11-16, the owner's first night: his pool's two SSDs went to standby
 * after 30 idle minutes — Unraid: spundown="1", temp="*" — and the office's «only a rotating disk sleeps» made them silent
 * sensors: two false alarms, the zone at 100 % for ten minutes each): Unraid says spun down and gives no temperature →
 * asleep (no demand, no alarm), rotating or not; a rotating disk spun down counts as asleep as before. A silent sensor is
 * only a disk Unraid calls awake (spundown="0") without a temperature. The office-wide diskAsleep() (wake-ups, every other
 * desk) stays as it is.
 */
function fanboyDiskSleeps(array $d): bool
{
    $down = ($d['spundown'] ?? '0') === '1';
    return $down && (($d['rotational'] ?? '1') !== '0' || !ctype_digit((string) ($d['temp'] ?? '')));
}

/**
 * A USB stick (2026-11-22, the owner's server: an unassigned Samsung flash drive offered as a disk heat source and named in
 * «in no zone» — it never has a temperature): removable and on USB in disks.ini or devs.ini — never a heat source. A USB
 * hard disk in an enclosure (removable="0") stays one.
 */
function fanboyUsbStick(array $d): bool
{
    return ($d['removable'] ?? '0') === '1' && strtolower(trim((string) ($d['transport'] ?? ''))) === 'usb';
}

/**
 * The ids a saved zone may still name for a USB stick (fanboyUsbStick(), the flash too): dropped quietly where zones are
 * read, shown and saved — never a refusal, never an alarm
 *
 * @return list<string>
 */
function fanboyUsbStickIds(string $ini): array
{
    $ids = [];
    foreach (is_file($ini) ? readCfg($ini, true) : [] as $section => $d) {
        if (fanboyUsbStick($d) || ($d['type'] ?? '') === 'Flash') {
            $ids[] = (string) ($d['name'] ?? $section);
        }
    }
    $devs = dirname($ini) . '/devs.ini';
    foreach (is_file($devs) && !is_link($devs) ? readCfg($devs, true) : [] as $d) {
        if ((fanboyUsbStick($d) || ($d['name'] ?? '') === 'flash') && (string) ($d['id'] ?? '') !== '') {
            $ids[] = fanboyDevId((string) $d['id']);
        }
    }
    return array_values(array_unique($ids));
}

/** A saved zone's sources without a USB stick's (fanboyUsbStickIds()) — the rest as it was */
function fanboyZoneSourcesClean(array $zone, array $sticks): array
{
    if ($sticks && is_array($zone['sources'] ?? null)) {
        $zone['sources'] = array_values(array_filter($zone['sources'], fn ($s) => !is_array($s) || !in_array($s['id'] ?? null, $sticks, true)));
    }
    return $zone;
}

/** The disks of array and pools (disks.ini) */
function fanboyArrayDisks(string $ini, ?array $limits = null): array
{
    $out = [];
    $deg = fn ($v) => is_string($v) && preg_match('/^\d{1,3}$/D', $v) ? (int) $v : null;
    foreach (readCfg($ini, true) as $section => $d) {
        $type = (string) ($d['type'] ?? '');
        if ($type === 'Flash' || ($d['device'] ?? '') === '' || str_starts_with((string) ($d['status'] ?? ''), 'DISK_NP') || fanboyUsbStick($d)) {
            continue;
        }
        $asleep = fanboyDiskSleeps($d);
        $out[] = [
            'name'       => (string) ($d['name'] ?? $section),
            'type'       => $type === 'Parity' ? 'parity' : ($type === 'Data' ? 'data' : 'pool'),
            'rotational' => ($d['rotational'] ?? '1') !== '0',
            'asleep'     => $asleep,
            'temp'       => !$asleep && ctype_digit((string) ($d['temp'] ?? '')) ? (int) $d['temp'] : null,
            'device'     => (string) $d['device'],     // round 5: an NVMe chip's pool by it (nvme0 ↔ nvme0n1) — never shown
        ];
        if ($limits !== null) {
            // Unraid's warning and critical temperature for this disk: its own, else the global one of its kind
            $ssd = ($d['rotational'] ?? '1') === '0';
            $out[count($out) - 1] += [
                'warn' => $deg($d['hotTemp'] ?? null) ?? $limits[$ssd ? 'hotssd' : 'hot'] ?? null,
                'crit' => $deg($d['maxTemp'] ?? null) ?? $limits[$ssd ? 'maxssd' : 'max'] ?? null,
            ];
        }
    }
    return $out;
}


/**
 * A stable id for a disk outside array and pools from devs.ini's `id` (model and serial — never shown, never stored):
 * «ud-» and 12 hex of its hash; sdX moves between boots, the id doesn't
 */
function fanboyDevId(string $id): string
{
    return 'ud-' . substr(hash('sha256', "fanboy-dev:$id"), 0, 12);
}

/**
 * Round 13b: Unraid's disks outside array and pools — /var/local/emhttp/devs.ini beside disks.ini (RAM, written by
 * emhttp; never a command, never a disk asked or woken). Each in fanboyArrayDisks()'s shape, type «unassigned», name =
 * fanboyDevId(), plus `label` (the Unassigned Devices plugin's own name for it, when it has one: its
 * /var/state/unassigned.devices/disk_names.json, {sdX: name}, read only and optional), `size` (bytes), `in_use`.
 * Asleep when emhttp says spun down or there is no temperature — unless its I/O counters in sysfs moved (fanboyDevInUse():
 * a VM may use a disk Unraid takes for spun down): then not asleep, no temperature, in_use (a zone takes it like a
 * sensor that says nothing). An NVMe here is left out: its own sensor (the nvme chip) says its temperature already.
 */
function fanboyDevDisks(string $ini, ?array $limits = null): array
{
    $devs = dirname($ini) . '/devs.ini';
    if (!is_file($devs) || is_link($devs)) {
        return [];
    }
    // the tree around it (the tests' fake one, or the host's): only when disks.ini lies where Unraid keeps it
    $root = str_ends_with($ini, '/var/local/emhttp/disks.ini') ? substr($ini, 0, -strlen('/var/local/emhttp/disks.ini')) : null;
    $ud = $root === null ? null : "$root/var/state/unassigned.devices/disk_names.json";
    $names = $ud !== null && is_file($ud) && !is_link($ud) && (int) @filesize($ud) <= 65536 ? (readJson($ud) ?? []) : [];
    $deg = fn ($v) => is_string($v) && preg_match('/^\d{1,3}$/D', $v) ? (int) $v : null;
    $out = [];
    foreach (readCfg($devs, true) as $d) {
        $dev = (string) ($d['device'] ?? '');
        $id = (string) ($d['id'] ?? '');
        // (a USB stick — removable, on USB — or the flash: no heat source, fanboyUsbStick())
        if (!preg_match('/^[a-z][a-z0-9]{0,15}$/D', $dev) || $id === '' || str_starts_with($dev, 'nvme') || fanboyUsbStick($d) || ($d['name'] ?? '') === 'flash') {
            continue;
        }
        $rot = ($d['rotational'] ?? '1') !== '0';
        $temp = ctype_digit((string) ($d['temp'] ?? '')) ? (int) $d['temp'] : null;
        // the same rule as the array's (fanboyDiskSleeps()): spun down and no temperature → asleep; awake without one → a
        // silent sensor (no temperature, not asleep)
        $down = fanboyDiskSleeps($d);
        // the I/O counters' memory lives with the watch (fanboy-watch.php: this file writes nothing)
        $inUse = $root !== null && function_exists('fanboyDevInUse') && fanboyDevInUse("$root/sys/block/$dev/stat", $dev);
        $asleep = $down && !$inUse;
        // the plugin's name for it: a short printable text, never the id (a name that is the serial says nothing anyway)
        $label = is_string($names[$dev] ?? null) ? trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $names[$dev])) : '';
        $label = $label !== '' && mb_strlen($label) <= 40 && !str_contains($id, $label) ? $label : null;
        $sectors = ctype_digit((string) ($d['sectors'] ?? '')) ? (int) $d['sectors'] : 0;
        $sector = ctype_digit((string) ($d['sector_size'] ?? '')) ? (int) $d['sector_size'] : 512;
        $out[] = [
            'name' => fanboyDevId($id), 'type' => 'unassigned', 'rotational' => $rot, 'asleep' => $asleep,
            'temp' => !$down ? $temp : null, 'device' => $dev, 'label' => $label, 'size' => $sectors * $sector, 'in_use' => $inUse && $down,
        ];
        if ($limits !== null) {
            $out[count($out) - 1] += ['warn' => $deg($d['hotTemp'] ?? null) ?? $limits[$rot ? 'hot' : 'hotssd'] ?? null,
                                      'crit' => $deg($d['maxTemp'] ?? null) ?? $limits[$rot ? 'max' : 'maxssd'] ?? null];
        }
    }
    return $out;
}

/**
 * Unraid's global disk temperature limits, °C: hot/max (hard disks), hotssd/maxssd (SSDs) — dynamix.cfg [display] over
 * the webGui's defaults; a value not set or not a number: the default's; none at all: null (never a number made up)
 */
function fanboyDiskLimits(array $p): array
{
    $out = [];
    foreach ([$p['dynamix_default'], $p['dynamix']] as $f) {
        $display = is_file($f) && !is_link($f) ? (readCfg($f, true)['display'] ?? []) : [];
        foreach (['hot', 'max', 'hotssd', 'maxssd'] as $k) {
            if (is_string($display[$k] ?? null) && preg_match('/^\d{1,3}$/D', $display[$k])) {
                $out[$k] = (int) $display[$k];
            }
        }
    }
    return $out + ['hot' => null, 'max' => null, 'hotssd' => null, 'maxssd' => null];
}

const FANBOY_HOT_KINDS = ['hdd', 'ssd', 'cpu', 'board', 'ram', 'other'];
/**
 * The warmest place per kind (round 3, the owner: one «warmest» over hard disks, NVMe and a CPU compares what can't be
 * compared): hdd (rotating disks), ssd (SSDs, NVMe), cpu, board, ram, other — each its warmest with its value and whose,
 * the one of the kind nearest to (or over) its limit (`near`, only where a limit is known: Unraid's for disks), how many
 * sleep. A sleeping disk has no value and counts as asleep, never as cold.
 */
function fanboyHottestKinds(array $chips, array $disks): array
{
    $cand = [];
    foreach ($disks as $d) {
        $cand[] = ['kind' => $d['rotational'] ? 'hdd' : 'ssd', 'c' => $d['temp'], 'label' => '', 'disk' => $d['name'], 'chip' => null, 'n' => null,
            'asleep' => $d['asleep'], 'warn' => $d['warn'] ?? null, 'crit' => $d['crit'] ?? null];
    }
    foreach ($chips as $c) {
        if ($c['kind'] === 'disk') {
            continue;                   // drivetemp: never read (Unraid's disks.ini says it)
        }
        $kind = match ($c['kind']) { 'nvme' => 'ssd', 'cpu' => 'cpu', 'board' => 'board', 'ram' => 'ram', default => 'other' };
        foreach ($c['temps'] as $t) {
            $cand[] = ['kind' => $kind, 'c' => $t['c'], 'label' => $t['label'], 'disk' => null, 'chip' => $c['name'], 'n' => $t['n'], 'asleep' => false, 'warn' => null, 'crit' => null];
        }
    }
    $out = [];
    foreach (FANBOY_HOT_KINDS as $kind) {
        $of = array_values(array_filter($cand, fn ($x) => $x['kind'] === $kind));
        if (!$of) {
            continue;
        }
        $warm = null;
        $near = null;
        foreach ($of as $x) {
            if ($x['c'] !== null && ($warm === null || $x['c'] > $warm['c'])) {
                $warm = $x;
            }
            if ($x['c'] !== null && $x['warn'] !== null && ($near === null || $x['c'] - $x['warn'] > $near['c'] - $near['warn'])) {
                $near = $x;
            }
        }
        $out[] = ['kind' => $kind, 'warmest' => $warm, 'near' => $near === null ? null : $near + [
            'margin' => $near['c'] - $near['warn'], 'over' => $near['crit'] !== null && $near['c'] >= $near['crit'] ? 'crit' : ($near['c'] >= $near['warn'] ? 'warn' : null)],
            'count' => count($of), 'asleep' => count(array_filter($of, fn ($x) => $x['asleep']))];
    }
    return $out;
}

/** Which kind leads: the place nearest to (or over) its limit — null where no limit is known */
function fanboyHottestLead(array $kinds): ?array
{
    $lead = null;
    foreach ($kinds as $k) {
        if ($k['near'] !== null && ($lead === null || $k['near']['margin'] > $lead['margin'])) {
            $lead = $k['near'];
        }
    }
    return $lead;
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
 * loop running. Round 7 (F4, a shell whose grep named «fanctrl» and «autofan» made both «running»): a plugin's controller
 * only while its plugin is installed (its .plg on the flash — settings left behind by an uninstall don't count), and
 * «running» only by a process's OWN program (its executable, or the script an interpreter runs — fanboyPrograms()),
 * never by a command line that merely names it (a grep, an editor, a shell's -c).
 *
 * blocks: false for a controller of other fans than the board's outputs (WAZ Cooling Control: shelves, IPMI) — named,
 * never holding Fanboy back (fanboyBlocking()).
 *
 * targets: the /sys paths its settings name (`controller`, `fan` …), missing: those of them that aren't there (2026-11-21, a
 * user's report: Dynamix Autofan running against /sys/class/hwmon/hwmon2/pwm1 on a board where hwmon2 is a temperature-only
 * chip) — read only, its settings never touched.
 *
 * @return list<array{id:string, name:string, settings:bool, running:bool, blocks:bool, targets:list<string>, missing:list<string>}>
 */
function fanboyControllers(array $p): array
{
    $programs = fanboyPrograms($p['proc']);
    $out = [];
    foreach (FANBOY_PLUGINS as $id => $plugin) {
        if (!isset($plugin['controller'])) {
            continue;
        }
        $plg = $p['plugins'] . "/$id.plg";
        if ($plugin['kind'] === 'controller' && (is_link($plg) || !is_file($plg))) {
            continue;                   // not installed: whatever it left behind steers nothing
        }
        [$folder, $files, $marker] = $plugin['controller'];
        $settings = false;
        $targets = [];
        foreach ($files === '' ? [] : (glob($p['plugins'] . "/$folder/$files") ?: []) as $f) {
            $cfg = !is_link($f) && is_file($f) ? readCfg($f) : [];
            foreach (['controller', 'fan'] as $k) {
                $v = trim((string) ($cfg[$k] ?? ''));
                if (preg_match('~^/sys/[A-Za-z0-9_./:-]+$~', $v) && !str_contains($v, '..')) {
                    $targets[] = $v;
                }
            }
            foreach ($plugin['on'] ?? [['service', '1']] as [$k, $v]) {
                $settings = $settings || ($cfg[$k] ?? '') === $v;
            }
            if ($settings) {
                break;
            }
        }
        $running = (bool) array_filter($programs, fn ($c) => preg_match($marker, $c) === 1)
            || (isset($plugin['cron']) && fanboyCronNames($p['crontab'], $plugin['cron']));
        if ($settings || $running) {
            $targets = array_values(array_unique($targets));
            $root = substr($p['hwmon'], 0, -strlen(FANBOY_HWMON));          // '' on the server, the tests' tree in a test
            $out[] = ['id' => $id, 'name' => $plugin['name'], 'settings' => $settings, 'running' => $running, 'blocks' => ($plugin['hint_only'] ?? false) !== true,
                      'targets' => $targets, 'missing' => array_values(array_filter($targets, fn ($t) => !file_exists($root . $t)))];
        }
    }
    return $out;
}

/** The controllers that keep Fanboy from steering: those of the board's outputs (not a hint-only one) */
function fanboyBlocking(array $controllers): array
{
    return array_values(array_filter($controllers, fn ($c) => ($c['blocks'] ?? true) === true));
}

/** Does a line of root's crontab (not a comment) name what $re matches? Read only, at most 64 KiB */
function fanboyCronNames(string $crontab, string $re): bool
{
    $text = is_link($crontab) ? false : @file_get_contents($crontab, false, null, 0, 65536);
    foreach (is_string($text) ? explode("\n", $text) : [] as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && preg_match($re, $line) === 1) {
            return true;
        }
    }
    return false;
}

/** Interpreters whose program is the script they run (a shell, php, python, perl, node, env) */
const FANBOY_INTERPRETERS = '/^(env|sh|bash|dash|ash|zsh|busybox|php[0-9.]*(-cli)?|python[0-9.]*|perl[0-9.]*|node|nodejs|ruby)$/D';

/**
 * A process's own program from its command line (arguments split at NUL): its executable — or, for an interpreter, the
 * script it runs (the first argument not an option, past further interpreters: «env python3 /x/y»). A shell given code
 * with -c runs no script: its program is the shell itself (whatever the code names). '' for an empty command line.
 */
function fanboyProgram(string $cmdline): string
{
    $args = array_values(array_filter(explode("\0", $cmdline), fn ($a) => $a !== ''));
    if (!$args) {
        return '';
    }
    $prog = $args[0];
    if (!preg_match(FANBOY_INTERPRETERS, basename($prog))) {
        return $prog;
    }
    for ($i = 1, $n = count($args); $i < $n; $i++) {
        $a = $args[$i];
        if ($a === '-c' || $a === '-e' || $a === '-r') {
            return $prog;               // inline code: no script of its own
        }
        if ($a[0] === '-' || str_contains($a, '=')) {
            continue;                   // an option (php -d x=y …), an env assignment
        }
        if (preg_match(FANBOY_INTERPRETERS, basename($a))) {
            $prog = $a;                 // env → the interpreter it starts
            continue;
        }
        return "$prog $a";
    }
    return $prog;
}

/** Every process's own program (fanboyProgram()), the agent's own process left out */
function fanboyPrograms(string $proc): array
{
    $out = [];
    $me = (string) getmypid();
    foreach (array_slice(@scandir($proc) ?: [], 0, FANBOY_PROC_MAX) as $pid) {
        if (ctype_digit($pid) && $pid !== $me) {
            $c = @file_get_contents("$proc/$pid/cmdline", false, null, 0, 1024);
            if (is_string($c) && ($prog = fanboyProgram($c)) !== '') {
                $out[] = $prog;
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
    return $chip['outputs'] && $chip['kind'] !== 'gpu' && empty($chip['twin']);
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
            'active'    => in_array($info['kind'], ['controller', 'container'], true) ? in_array($id, $active, true) : null,
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
    // round 5: a chip with two drivers on it is no chip to steer (its outputs not offered)
    $twins = fanboyTwins($p, $chips);
    foreach ($chips as $i => $c) {
        $chips[$i]['twin'] = in_array($c['name'], $twins, true);
    }
    $disks = fanboyDisks($p['disks'], fanboyDiskLimits($p));
    $ipmi = fanboyIpmi($p);
    $controllers = fanboyControllers($p);
    $driversMissing = fanboyDriversMissing($p, $chips);
    $board = fanboyBoard($p['dmi']);
    $situation = fanboySituation($chips, fanboyBlocking($controllers), $ipmi, $driversMissing);
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
        // round 5: how fresh Unraid keeps the disks' temperatures (seconds), a virtual machine, chips with two drivers
        'poll'        => fanboyPollAttributes($p),
        'vm'          => fanboyVm($p),
        'twins'       => $twins,
        'names'       => fanboySensorNames($p['plugins'] . '/' . FANBOY_SYSTEMP_CONF, $chips),
        'hints_open'  => in_array($situation['code'], ['no_output', 'driver_missing', 'other', 'ipmi'], true),
        // 2026-11-21 (a user's report): no driver of a fan chip loaded at all — no chip shows a fan output or a fan input
        // (a graphics card's own fan aside, and acpi_fan: the firmware's word on one fan, there with no fan chip driver at
        // all — the demo board's look); the page says it plainly and the way to one
        'no_fan_chip' => !array_filter($chips, fn ($c) => $c['kind'] !== 'gpu' && $c['name'] !== 'acpi_fan' && ($c['outputs'] || $c['fans'])),
        // 2026-11-21 (the owner: «Treiber sind immer mühsam … auch für die Fühler»): the kinds of temperature a server
        // usually has that no chip shows — board (a sensor chip of the board; acpitz, the firmware's thermal zone, isn't
        // one) and cpu (coretemp, k10temp …); facts of the scan only (RAM sensors aren't knowable: never said)
        'temps_missing' => array_values(array_filter(['board', 'cpu'], fn ($k) => !array_filter($chips, fn ($c) => $c['kind'] === $k && $c['temps'] && $c['name'] !== 'acpitz'))),
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
        // round 3: the warmest per kind and which leads (by its limit, not its number) — `hottest` stays for an older page
        'hottest_kinds' => $kinds = fanboyHottestKinds($chips, $disks),
        'hottest_lead'  => fanboyHottestLead($kinds),
        // who steers the fans now — part 1: never Fanboy (the BIOS; another plugin; a BMC), with the reason
        'who'         => fanboyWho(fanboyBlocking($controllers), $ipmi, $chips),
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
