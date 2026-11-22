<?php
declare(strict_types=1);

/*
 * Fanboy's driver doctor (agent/desks/fanboy-doctor.php, 2026-11-22): each sign present → its hint and its report line,
 * absent → nothing. Fake trees and fake kernel-log files only (tests/run.php fanboyFakeTree(); the tree's «dmesg» file) —
 * never the real log, /sys or a real port.
 */

/** The doctor's signs on a fake tree: [codes, signs, scan, report] */
function fanboyDoctorLook(array $spec, string $name, array $fans = [], ?array $chip = null): array
{
    $root = fanboyFakeTree(hardeningTmp("fanboy-doctor-$name"), $spec);
    $scan = fanboyDetect($root);
    $signs = fanboyDoctor($scan, fanboyPaths($root), $fans, $chip);
    $scan['doctor'] = $signs;
    return [array_column($signs, 'code'), $signs, $scan, fanboyReportText($scan), $root];
}

function testFanboyDoctor(): void
{
    $board = fanboyFakeBoard(10);
    $noIte = $board;
    $noIte['chips'] = array_values(array_filter($board['chips'], fn ($c) => $c[1] !== 'it8696_a0060607'));
    $log = fn (string ...$lines) => ['files' => ['dmesg' => implode("\n", $lines) . "\n"] + ($board['files'] ?? [])];
    $iteConflict = ['[    3.101] it87: Found IT8689E chip at 0xa40, revision 1',
        '[    3.102] ACPI Warning: SystemIO range 0x0000000000000A45-0x0000000000000A46 conflicts with OpRegion 0x0000000000000A45-0x0000000000000A46 (\GSA1.SIO1) (20230628/utaddress-204)',
        '[    3.102] ACPI: OSL: Resource conflict; ACPI support missing from driver?'];
    $nctConflict = ['[    2.901] ACPI Warning: SystemIO range 0x0000000000000295-0x0000000000000296 conflicts with OpRegion 0x0000000000000290-0x0000000000000299 (\_GPE.HWM) (20230628/utaddress-204)',
        '[    2.901] ACPI: OSL: Resource conflict; ACPI support missing from driver?'];
    $smbus = ['[    1.501] ACPI Warning: SystemIO range 0x000000000000EFA0-0x000000000000EFBF conflicts with OpRegion 0x000000000000EFA0-0x000000000000EFAF (\_SB.PCI0.SBUS.SMBI) (20230628/utaddress-204)',
        '[    1.501] ACPI: OSL: Resource conflict; ACPI support missing from driver?'];

    // the board as it is: nothing said; the kernel log read from the tree's file, never dmesg
    [$codes, , , $text, $root] = fanboyDoctorLook($board, 'clean');
    $before = fanboyTreeHash($root);
    fanboyDoctor(fanboyDetect($root), fanboyPaths($root));
    same('fanboy doctor: the fake board as it is — no sign, no hint line in the report; the tests\' log is a file of the tree (never dmesg); the look writes nothing',
        [[], false, "$root/dmesg", $before], [$codes, str_contains($text, 'Hint:'), fanboyPaths($root)['dmesg'], fanboyTreeHash($root)]);

    // 1 ACPI's resource conflict at the fan chip
    $cmd = fn (string $c) => ['files' => ['dmesg' => implode("\n", $iteConflict) . "\n", 'proc/cmdline' => "BOOT_IMAGE=/bzimage $c\n"]];
    $got = [];
    foreach (['ite' => $log(...$iteConflict), 'nct' => $log(...$nctConflict), 'smbus only' => $log(...$smbus), 'lax set' => $cmd('acpi_enforce_resources=lax'),
              'it87 option set' => $cmd('it87.ignore_resource_conflict=1')] as $what => $more) {
        [, $signs, , $text] = fanboyDoctorLook(array_replace($board, $more), 'acpi-' . md5($what));
        $s = array_values(array_filter($signs, fn ($x) => $x['code'] === 'acpi_conflict'))[0] ?? null;
        $got[$what] = $s === null ? null : [$s['ite'], str_contains($text, "\nHint: ACPI resource conflict at the fan chip in the kernel log" . ($s['ite'] ? ' (ITE)' : '') . ', no start option set')];
    }
    same('fanboy doctor: ACPI conflict — at an ITE chip (ITE, the it87 option), at a chip at 0x290 (not ITE: acpi_enforce_resources=lax), never for an SMBus conflict alone, nothing once either option is set; one report line',
        ['ite' => [true, true], 'nct' => [false, true], 'smbus only' => null, 'lax set' => null, 'it87 option set' => null], $got);

    // 2 a fan chip driver loaded without a chip (the plugin by name, installed or not); the log's «No such device»
    $loose = array_replace($noIte, ['modules' => ['it87']]);
    [$c1, $s1, , $t1] = fanboyDoctorLook($loose, 'nobound-plg');
    $loose['plugins'] = array_diff_key($board['plugins'], ['it87-driver.plg' => 1]);
    [, $s2] = fanboyDoctorLook($loose, 'nobound');
    [, $s3, , $t3] = fanboyDoctorLook(array_replace($noIte, $log("[   12.300] modprobe: ERROR: could not insert 'nct6775': No such device")), 'nodev');
    [$c4] = fanboyDoctorLook(array_replace($board, ['modules' => ['it87']]), 'bound');
    $nb = fn ($signs) => array_map(fn ($x) => [$x['module'], $x['plugin'], $x['installed']], array_values(array_filter($signs, fn ($x) => $x['code'] === 'not_bound')));
    same('fanboy doctor: a driver without a chip — it87 loaded, none bound (its plugin, installed / not); the log\'s «No such device» for nct6775 (no plugin named); it87 bound to the ITE chip — nothing',
        [[['it87', 'it87-driver', true]], [['it87', 'it87-driver', false]], [['nct6775', null, false]], false, true, true],
        [$nb($s1), $nb($s2), $nb($s3), in_array('not_bound', $c4, true),
         str_contains($t1, "\nHint: driver it87 loaded without a chip, plugin it87-driver installed"), str_contains($t3, "\nHint: driver nct6775 loaded without a chip\n")]);
    // … the chip read naming an NCT6687D: the NCT6687 plugin for nct6683 loaded without a chip
    [, $s5] = fanboyDoctorLook(array_replace($noIte, ['modules' => ['nct6683']]), 'nct-chip', [], ['chip' => ['name' => 'NCT6683D/NCT6686D/NCT6687D', 'maker' => 'Nuvoton']]);
    same('fanboy doctor: … nct6683 without a chip where the chip read names an NCT6687D — the NCT6687 plugin', [['nct6683', 'nct6687-driver', false]], $nb($s5));

    // 3 the read-only nct6683
    $msi = $noIte;
    $msi['chips'][] = [11, 'nct6683', 'platform/nct6683.2592', 'nct6683', 'pwm' => [1 => [128, null, false]], 'fans' => [1 => 900], 'temps' => [1 => [30000, '']]];
    [, $s6, , $t6] = fanboyDoctorLook($msi, 'ro');
    $msi2 = $msi;
    $msi2['modules'] = ['nct6687'];
    [$c7] = fanboyDoctorLook($msi2, 'ro-plugin');
    same('fanboy doctor: nct6683 bound to the chip — read only, the NCT6687 plugin (not installed), a report line; with nct6687 loaded too — nothing',
        [[['read_only', false]], true, false],
        [array_map(fn ($x) => [$x['code'], $x['installed']], array_values(array_filter($s6, fn ($x) => $x['code'] === 'read_only'))),
         str_contains($t6, "\nHint: read-only driver nct6683, plugin nct6687-driver not installed\n"), in_array('read_only', $c7, true)]);

    // 4 a BMC: seen (its IPMI lines in the report already: no hint line) or only its driver loaded (a hint line)
    [, $s8, , $t8] = fanboyDoctorLook(array_replace($noIte, ['ipmi' => ['manufacturer_id' => '10876', 'product_id' => '2398', 'firmware_revision' => '1.74']]), 'bmc');
    [, $s9, , $t9] = fanboyDoctorLook(array_replace($board, ['modules' => ['ipmi_si', 'ipmi_devintf']]), 'bmc-mod');
    $bmc = fn ($signs) => array_map(fn ($x) => $x['seen'], array_values(array_filter($signs, fn ($x) => $x['code'] === 'bmc')));
    same('fanboy doctor: a BMC — seen (the report\'s IPMI lines say it; no hint line), only ipmi_si/ipmi_devintf loaded (a hint line); none — nothing',
        [[true], false, [false], true, []], [$bmc($s8), str_contains($t8, 'Hint: BMC'), $bmc($s9), str_contains($t9, "\nHint: BMC driver loaded (ipmi), no BMC seen\n"), $bmc(fanboyDoctorLook($board, 'nobmc')[1])]);

    // 5 DDR5's SPD write lock
    [$c10, , , $t10] = fanboyDoctorLook(array_replace($board, $log('[    4.200] i801_smbus 0000:00:1f.4: SPD Write Disable is set')), 'spd');
    same('fanboy doctor: «SPD Write Disable is set» in the kernel log — the hint and its line', [true, true], [in_array('spd_wd', $c10, true), str_contains($t10, "\nHint: SPD Write Disable is set (kernel log)\n")]);

    // 6 Intel VMD holding a device, and no NVMe temperature (with NVMe temperatures: nothing)
    $vmd = ['files' => ['sys/bus/pci/drivers/vmd/0000:00:0e.0' => '', 'sys/bus/pci/drivers/vmd/bind' => '']];
    $noNvme = $board;
    $noNvme['chips'] = array_values(array_filter($board['chips'], fn ($c) => $c[1] !== 'nvme'));
    [$c11, , , $t11] = fanboyDoctorLook(array_replace($noNvme, $vmd), 'vmd');
    [$c12] = fanboyDoctorLook(array_replace($board, $vmd), 'vmd-nvme');
    [$c13] = fanboyDoctorLook($noNvme, 'novmd');
    same('fanboy doctor: Intel VMD holding a device and no NVMe temperature — the hint and its line; NVMe temperatures there, or no VMD — nothing',
        [true, true, false, false], [in_array('vmd', $c11, true), str_contains($t11, "\nHint: Intel VMD active, no NVMe temperature\n"), in_array('vmd', $c12, true), in_array('vmd', $c13, true)]);

    // 7 the kernel locked down
    $lock = fn (string $l) => ['files' => ['sys/kernel/security/lockdown' => "$l\n"]];
    [, $s14, , $t14] = fanboyDoctorLook(array_replace($board, $lock('none [integrity] confidentiality')), 'lock');
    [$c15] = fanboyDoctorLook(array_replace($board, $lock('[none] integrity confidentiality')), 'nolock');
    same('fanboy doctor: lockdown [integrity] — the hint with its mode and a line; [none] — nothing',
        [['integrity'], true, false], [array_column(array_values(array_filter($s14, fn ($x) => $x['code'] === 'lockdown')), 'mode'), str_contains($t14, "\nHint: kernel lockdown integrity\n"), in_array('lockdown', $c15, true)]);

    // 8 an output in DC mode whose test wasn't clean (pwmN_mode 0; inverted, ignored by the BIOS) — never for a clean test or PWM mode
    $id3 = fanboyFanId('it8696_a0060607', 'it87.2608', 3);
    $id4 = fanboyFanId('it8696_a0060607', 'it87.2608', 4);
    $modes = fn (int $m3, int $m4) => ['files' => ['sys/devices/platform/it87.2608/hwmon/hwmon10/pwm3_mode' => "$m3\n", 'sys/devices/platform/it87.2608/hwmon/hwmon10/pwm4_mode' => "$m4\n"]];
    $fans = [$id3 => ['found' => 'inverted'], $id4 => ['found' => 'ignored', 'why' => 'taken_back']];
    [, $s16, , $t16] = fanboyDoctorLook(array_replace($board, $modes(0, 0)), 'dc', $fans);
    [$c17] = fanboyDoctorLook(array_replace($board, $modes(0, 0)), 'dc-clean', [$id3 => ['found' => 'tach'], $id4 => ['found' => 'silent']]);
    [$c18] = fanboyDoctorLook(array_replace($board, $modes(1, 1)), 'pwm', $fans);
    same('fanboy doctor: DC mode where the test wasn\'t clean (inverted, ignored) — both outputs and a line; a clean test or PWM mode — nothing',
        [[[$id3, $id4]], true, false, false],
        [array_column(array_values(array_filter($s16, fn ($x) => $x['code'] === 'dc_mode')), 'fans'), str_contains($t16, "\nHint: DC mode where the test was not clean: pwm3, pwm4\n"),
         in_array('dc_mode', $c17, true), in_array('dc_mode', $c18, true)]);

    // 9 AMD: Tctl beside Tdie
    $amd = $board;
    $amd['chips'] = array_values(array_filter($board['chips'], fn ($c) => $c[1] !== 'coretemp'));
    $amd['chips'][] = [12, 'k10temp', 'pci0000:00/0000:00:18.3', 'k10temp', 'temps' => [1 => [52000, 'Tctl'], 2 => [42000, 'Tdie']]];
    [$c19, , , $t19] = fanboyDoctorLook($amd, 'amd');
    $amd['chips'][count($amd['chips']) - 1]['temps'] = [1 => [52000, 'Tctl']];
    [$c20] = fanboyDoctorLook($amd, 'amd-tctl');
    same('fanboy doctor: k10temp with Tctl and Tdie — the sign (the source list says it) and a line; Tctl alone — nothing',
        [true, true, false], [in_array('tctl', $c19, true), str_contains($t19, "\nHint: CPU shows Tctl and Tdie (k10temp)\n"), in_array('tctl', $c20, true)]);

    // the report: never a path or a /sys word in the hint lines (everything at once)
    $all = array_replace($msi, $log(...[...$iteConflict, '[    4.200] i801_smbus 0000:00:1f.4: SPD Write Disable is set']), ['modules' => ['it87', 'ipmi_si']]);
    $all['files'] += $vmd['files'] + $lock('none [integrity] confidentiality')['files'];     // (VMD: NVMe temperatures there — nothing)
    [$cAll, , , $tAll] = fanboyDoctorLook($all, 'all');
    $hints = implode("\n", array_filter(explode("\n", $tAll), fn ($l) => str_starts_with($l, 'Hint:')));
    same('fanboy doctor: the report\'s hint lines — one per sign, no path, no /sys word',
        [6, false], [substr_count($hints, 'Hint:'), (bool) preg_match('~/|hwmon|devices|platform~', $hints)]);
    check('fanboy doctor: … all signs at once seen (' . implode(', ', $cAll) . ')', ['acpi_conflict', 'not_bound', 'read_only', 'bmc', 'spd_wd', 'lockdown'] === $cAll);

    // the agent: in fanboyScan(), with his stored fans and the chip state; the kernel log at most every 5 minutes (or when
    // a driver came or went), only its lines that matter kept; read only (run(['dmesg']) the one command)
    $src = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-doctor.php');
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
    check('fanboy doctor: in the scan; dmesg its one command, kept in memory (300 s, or a driver came or went), only the lines that matter',
        str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy.php'), "\$state['doctor'] = fanboyDoctor(\$state, null, is_array(\$settings['fans'] ?? null) ? \$settings['fans'] : [], \$state['chip']);")
        && substr_count($code, 'run(') === 1 && str_contains($code, "run(['dmesg'], 10, 16 << 20)") && str_contains($src, 'const FANBOY_DMESG_EVERY = 300;')
        && str_contains($code, "\$memo['mods'] !== \$mods || \$now - \$memo['at'] > FANBOY_DMESG_EVERY * 1e9"));

    // the page: step 1 says the signs (both of its branches), the IPMI banner the BMC's part (never twice), AMD's two
    // values in the source list, the BIOS checklist in the hints' fold; the chip's «locked» text says why
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    check('fanboy doctor page: doctorPart() in both branches of step 1; bmc left out under the IPMI banner, which says its part; tctl in the source list (titles and a line); the checklist an ordered list',
        substr_count($js, 'const doc = doctorPart();') === 2 && str_contains($js, "x.code !== 'tctl' && !(x.code === 'bmc' && situation() === 'ipmi')")
        && str_contains($js, "p.appendChild(el('p', 'fb-hint-text', T('situation.ipmi_more')));")
        && str_contains($js, "T(s.label === 'Tctl' ? 'src.tctl' : 'src.tdie')") && str_contains($js, "T('zones.src_tctl_tdie')")
        && str_contains($js, "const check = el('ol', 'fb-hint-list fb-bios-check');") && str_contains($js, "has(`hints.bios_check.\${i}`)"));
    $l = [];
    foreach (['en', 'de', 'fr', 'es', 'it'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    $t = fn ($x, $k) => is_array($x[$k] ?? null) ? implode(' ', $x[$k]) : (string) ($x[$k] ?? '');
    same('fanboy doctor words: in all five — the start options with ⟦Boot Parameters⟧ and the risk; the plugins by placeholder; nct6683; IPMI Tools and the thresholds; SPD Write Disable; VMD before installing; Secure Boot (the chip\'s «locked» too); DC with 4-pin/3-pin and no Auto; Tctl/Tdie; eight BIOS points and the makers\' menus',
        array_fill_keys(array_keys($l), array_fill(0, 10, true)),
        array_map(fn ($x) => [
            str_contains($t($x, 'doctor.acpi_conflict_ite'), 'it87.ignore_resource_conflict=1') && str_contains($t($x, 'doctor.acpi_conflict'), 'acpi_enforce_resources=lax')
                && str_contains($t($x, 'doctor.acpi_conflict_ite'), '⟦Boot Parameters⟧') && str_contains($t($x, 'doctor.acpi_conflict'), '⟦Boot Parameters⟧') && str_contains($t($x, 'doctor.acpi_conflict'), 'append'),
            !array_filter(['doctor.not_bound', 'doctor.not_bound_plugin', 'doctor.not_bound_installed'], fn ($k) => !str_contains($t($x, $k), '{module}'))
                && str_contains($t($x, 'doctor.not_bound_plugin'), '{plugin}') && str_contains($t($x, 'doctor.read_only'), '{plugin}') && str_contains($t($x, 'doctor.read_only'), 'nct6683'),
            str_contains($t($x, 'doctor.bmc'), 'IPMI Tools') && str_contains($t($x, 'situation.ipmi_more'), 'IPMI Tools') && str_contains($t($x, 'situation.ipmi_more'), 'BMC'),
            str_contains($t($x, 'doctor.spd_wd'), 'SPD Write Disable') && str_contains($t($x, 'doctor.spd_wd'), 'DDR5'),
            str_contains($t($x, 'doctor.vmd'), 'VMD') && str_contains($t($x, 'doctor.vmd'), 'RAID'),
            str_contains($t($x, 'doctor.lockdown'), 'Secure Boot') && str_contains($t($x, 'doctor.lockdown'), '{mode}') && str_contains($t($x, 'chip.blocked.locked'), 'Secure Boot'),
            is_array($x['doctor.dc_mode'] ?? null) && str_contains($t($x, 'doctor.dc_mode'), 'PWM') && str_contains($t($x, 'doctor.dc_mode'), '«Auto»') && str_contains($x['doctor.dc_mode']['other'], '{n}'),
            str_contains($t($x, 'src.tctl'), 'Tctl') && str_contains($t($x, 'src.tdie'), 'Tdie') && str_contains($t($x, 'zones.src_tctl_tdie'), 'Tdie'),
            isset($x['hints.bios_check.8']) && !isset($x['hints.bios_check.9']) && !isset($x['hints.bios.1']) && isset($x['hints.bios.2']),
            !array_filter(['Q-Fan Configuration', 'Smart Fan 5', 'Smart Fan 6', 'Hardware Monitor', 'H/W Monitor', 'Supermicro'], fn ($w) => !str_contains($t($x, 'hints.bios_where'), $w))], $l));
    check('fanboy doctor words: the risk said plainly in English and German (unexpected reboots, not the default)',
        str_contains($l['en']['doctor.acpi_conflict_ite'], 'unexpected reboots') && str_contains($l['en']['doctor.acpi_conflict_ite'], "isn't the default")
        && str_contains($l['de']['doctor.acpi_conflict_ite'], 'unerwartete Neustarts') && str_contains($l['de']['doctor.acpi_conflict_ite'], 'nicht die Vorgabe'));
}

/**
 * 2026-11-22 (the owner's server: an unassigned USB flash drive offered as a disk heat source, named «in no zone»): a device
 * removable AND on USB is no heat source — not in the sources, not uncovered, not proposed; a USB hard disk (removable="0")
 * stays one. A saved zone still naming the stick: shown, run and saved without it, quietly. devs.ini shaped like the
 * owner's (four 16 TB disks as dev1–4, the stick as dev5; serials invented), a USB hard disk as dev6.
 */
function testFanboyUsbStick(): void
{
    $t = fanboyT('usbstick');
    try {
        $dev = fn (int $n, string $device, string $id, string $transport, string $removable, string $temp) =>
            "[\"dev$n\"]\nname=\"dev$n\"\nid=\"$id\"\ndevice=\"$device\"\nsectors=\"31251759104\"\nsector_size=\"512\"\nrotational=\"1\"\nspundown=\"0\"\ntemp=\"$temp\"\n"
            . "transport=\"$transport\"\nremovable=\"$removable\"\nnumReads=\"0\"\nnumWrites=\"0\"\nnumErrors=\"0\"\n";
        file_put_contents("{$t['root']}/var/local/emhttp/devs.ini",
            $dev(1, 'sdb', 'ST16000NM001G-2KK103_EXAMPLE0001', 'sata', '0', '33') . $dev(2, 'sdc', 'ST16000NM001G-2KK103_EXAMPLE0002', 'sata', '0', '34')
            . $dev(3, 'sdd', 'ST16000NM001G-2KK103_EXAMPLE0003', 'sata', '0', '33') . $dev(4, 'sde', 'ST16000NM001G-2KK103_EXAMPLE0004', 'sata', '0', '35')
            . $dev(5, 'sdf', 'Samsung_Flash_Drive_FIT_0000000000000-0:0', 'usb', '1', '*')
            . $dev(6, 'sdg', 'EXAMPLE_USB_HDD_8TB_SERIAL0006', 'usb', '0', '31'));
        $stick = fanboyDevId('Samsung_Flash_Drive_FIT_0000000000000-0:0');
        $hdd = fanboyDevId('EXAMPLE_USB_HDD_8TB_SERIAL0006');
        $scan = fanboyDetect();
        $ids = array_column(fanboySources($scan), 'id');
        $settings = fanboyTSettings();
        unset($settings['zones'], $settings['armed']);
        fanboyTStore($settings);
        $prop = fanboyZonesPropose($scan, $settings);
        $proposed = array_merge(...array_map(fn ($z) => array_column($z['sources'], 'id'), $prop));
        $loose = array_column(fanboySetupState($scan, $settings)['uncovered'], 'id');
        same('fanboy usb stick: the four 16 TB disks and the USB hard disk (removable="0") are heat sources; the stick (removable, USB) is not — not in the sources, not proposed, not «in no zone» (the ids a zone may still name: the flash and the stick)',
            [4, true, false, false, false, ['flash', $stick]],
            [count(array_intersect($ids, array_map(fn ($n) => fanboyDevId("ST16000NM001G-2KK103_EXAMPLE000$n"), [1, 2, 3, 4]))), in_array($hdd, $ids, true),
             in_array($stick, $ids, true), in_array($stick, $proposed, true), in_array($stick, $loose, true), fanboyUsbStickIds(fanboyPaths()['disks'])]);

        // a zone saved before, still naming the stick: shown without it, saved without it (never refused), run without it
        $zone = ['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4)], 'sources' => [['id' => 'parity', 'kind' => 'disk'], ['id' => $stick, 'kind' => 'disk']],
                 'unit' => 'pct', 'target' => ['preset' => 'normal']];
        $checked = fanboyZonesCheck([$zone], $scan, $settings);
        $settings['zones'] = [$zone];
        fanboyTStore($settings);
        $shown = fanboySetupState($scan, $settings)['zones'][0]['sources'] ?? null;
        $run = fanboyControlNew($settings, fanboyBoardKey($scan['board']), ['pid' => 1])['zones']['disks']['sources'] ?? null;
        same('fanboy usb stick: a saved zone still naming the stick — saved without it (no refusal), shown without it, the control reads without it',
            [['parity'], [['id' => 'parity', 'kind' => 'disk']], ['parity']],
            [array_column($checked[0]['sources'], 'id'), $shown, $run === null ? null : array_column($run, 'id')]);
    } finally {
        fanboyTEnd();
    }
    // the flash in disks.ini and a stick in the array (disks.ini removable + usb): neither a source
    $root = fanboyFakeTree(hardeningTmp('fanboy-usbstick-array'), array_replace(fanboyFakeBoard(10), ['disks' =>
        "[disk1]\nname=\"disk1\"\ntype=\"Data\"\ndevice=\"sdh\"\nid=\"Example_USB_Stick_0001\"\ntemp=\"*\"\nspundown=\"0\"\nrotational=\"1\"\ntransport=\"usb\"\nremovable=\"1\"\nstatus=\"DISK_OK\"\n"
        . "[disk2]\nname=\"disk2\"\ntype=\"Data\"\ndevice=\"sdi\"\nid=\"EXAMPLE_HDD_0002\"\ntemp=\"33\"\nspundown=\"0\"\nrotational=\"1\"\ntransport=\"sata\"\nremovable=\"0\"\nstatus=\"DISK_OK\"\n"
        . "[flash]\nname=\"flash\"\ntype=\"Flash\"\ndevice=\"sda\"\nid=\"EXAMPLE_FLASH\"\ntransport=\"usb\"\nremovable=\"1\"\n"]));
    same('fanboy usb stick: in the array too — a stick as disk1 is no source, disk2 is; the flash never', [['disk2'], ['disk1', 'flash']],
        [array_column(fanboyDisks("$root/var/local/emhttp/disks.ini"), 'name'), fanboyUsbStickIds("$root/var/local/emhttp/disks.ini")]);
}
