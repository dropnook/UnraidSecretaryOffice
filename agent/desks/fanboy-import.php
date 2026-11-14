<?php
declare(strict_types=1);

/*
 * Moving over from FanCtrl Plus (ck9393; its fork FanCtrl Plus 2): its files read, a zone proposed — he never writes
 * there and never switches it off (the user does, in its settings; only then does Fanboy steer).
 *
 * Its files: /boot/config/plugins/fanctrlplus/fanctrlplus_<name>.cfg (FanCtrl Plus 2: fanctrlplus2/…), key="value"
 * lines (its include/update.fanctrlplus.php): custom, label, service ("1" on), controller (the output's path as it was
 * when saved — /sys/devices/platform/it87.2624/hwmon/hwmon10/pwm1: the hwmon number of THAT boot), pwm (its lowest
 * value, 0–255), max, idle (0–255 with every disk asleep), low/high (°C: pwm at low, max at high), interval
 * (minutes), disks (by-id names, comma-separated), cpu_enable, cpu_sensor, cpu_min_temp, cpu_max_temp.
 *
 * The output is found by its NUMBER on the fan chip there now (the address in the path counts when it matches a chip,
 * the hwmon number never); the disks by Unraid's own id in disks.ini (the by-id name less its «ata-»/«nvme-» prefix) —
 * the proposal names Unraid's disk names only, never a serial. Files with the same disks, temperatures and values make
 * one zone.
 */

const FANBOY_IMPORT_FOLDERS = ['fanctrlplus', 'fanctrlplus2'];
const FANBOY_IMPORT_MAX = 32;

/** One file of FanCtrl Plus, read (never written): its keys, or null when it isn't one */
function fanboyImportFile(string $f): ?array
{
    if (is_link($f) || !is_file($f) || filesize($f) > 16384) {
        return null;
    }
    $c = readCfg($f);
    return isset($c['controller']) ? $c : null;
}

/** A number of its file in [$lo, $hi], else null */
function fanboyImportInt(array $c, string $k, int $lo, int $hi): ?int
{
    $v = trim((string) ($c[$k] ?? ''));
    return preg_match('/^\d{1,5}$/D', $v) && (int) $v >= $lo && (int) $v <= $hi ? (int) $v : null;
}

/**
 * The proposal from FanCtrl Plus's files: {files, active, zones: [{name, fans: [fan id], outputs: [n], sources:
 * [{kind: disk, id: <name>}], target: {points}, unit: pct, from: [names], cpu}], unmatched: [what couldn't be placed]}
 */
function fanboyImport(array $scan, ?string $plugins = null): array
{
    $plugins ??= fanboyPaths(fanboySysRoot() ?: null)['plugins'];
    $chips = array_values(array_filter($scan['chips'] ?? [], 'fanboyOffers'));
    $ids = [];         // Unraid's id of each disk → its name (disks.ini, read by part 1 without the id: read here)
    foreach (readCfg(fanboyPaths(fanboySysRoot() ?: null)['disks'], true) as $section => $d) {
        if (is_array($d) && ($d['id'] ?? '') !== '') {
            $ids[(string) $d['id']] = (string) ($d['name'] ?? $section);
        }
    }
    $groups = [];
    $unmatched = [];
    $files = 0;
    $active = false;
    foreach (FANBOY_IMPORT_FOLDERS as $folder) {
        foreach (array_slice(glob("$plugins/$folder/{$folder}_*.cfg") ?: [], 0, FANBOY_IMPORT_MAX) as $f) {
            $c = fanboyImportFile($f);
            if ($c === null) {
                continue;
            }
            $files++;
            $active = $active || ($c['service'] ?? '') === '1';
            $name = trim((string) preg_replace('/[^A-Za-z0-9_ -]+/', '', (string) ($c['custom'] ?? basename($f, '.cfg'))));
            // the output: its number; the chip: the one at that address, else the only one with that number
            if (!preg_match('#^/sys/devices/(.+)/hwmon/hwmon\d+/pwm(\d{1,2})$#D', (string) $c['controller'], $m)) {
                $unmatched[] = ['from' => $name, 'why' => 'path'];
                continue;
            }
            $address = basename($m[1]);
            $n = (int) $m[2];
            $with = array_values(array_filter($chips, fn ($x) => in_array($n, array_column($x['outputs'], 'n'), true)));
            $at = array_values(array_filter($with, fn ($x) => $x['address'] === $address));
            $chip = $at[0] ?? (count($with) === 1 ? $with[0] : null);
            if ($chip === null) {
                $unmatched[] = ['from' => $name, 'why' => $with ? 'ambiguous' : 'no_output', 'output' => $n];
                continue;
            }
            $disks = [];
            foreach (array_filter(array_map('trim', explode(',', (string) ($c['disks'] ?? '')))) as $byId) {
                $id = (string) preg_replace('/^(ata|nvme|scsi|usb|wwn)-/', '', $byId);
                $id = (string) preg_replace('/-part\d+$/', '', $id);
                if (isset($ids[$id])) {
                    $disks[] = $ids[$id];
                } else {
                    $unmatched[] = ['from' => $name, 'why' => 'disk'];      // never the by-id name: it carries a serial
                }
            }
            sort($disks);
            $low = fanboyImportInt($c, 'low', 0, 100);
            $high = fanboyImportInt($c, 'high', 0, 120);
            $min = fanboyImportInt($c, 'pwm', 0, 255);
            $max = fanboyImportInt($c, 'max', 0, 255) ?? 255;
            $idle = fanboyImportInt($c, 'idle', 0, 255);
            $cpu = ($c['cpu_enable'] ?? '') === '1' ? [fanboyImportInt($c, 'cpu_min_temp', 0, 110), fanboyImportInt($c, 'cpu_max_temp', 0, 120)] : null;
            $key = json_encode([$disks, $low, $high, $min, $max, $idle, $cpu]);
            $groups[$key] ??= ['disks' => $disks, 'low' => $low, 'high' => $high, 'min' => $min, 'max' => $max, 'idle' => $idle, 'cpu' => $cpu, 'fans' => [], 'outputs' => [], 'from' => []];
            $groups[$key]['fans'][] = fanboyFanId($chip['name'], $chip['address'], $n);
            $groups[$key]['outputs'][] = $n;
            $groups[$key]['from'][] = $name;
        }
    }
    $zones = [];
    $i = 0;
    foreach ($groups as $g) {
        $i++;
        $pct = fn (?int $v) => $v === null ? null : (int) round($v / 2.55);
        $points = $g['low'] !== null && $g['high'] !== null && $g['high'] > $g['low']
            ? [[$g['low'], $pct($g['min']) ?? 0], [$g['high'], $pct($g['max']) ?? 100]] : null;
        $sources = array_map(fn ($d) => ['kind' => 'disk', 'id' => $d], $g['disks']);
        if ($g['cpu'] !== null && $g['cpu'][0] !== null && $g['cpu'][1] !== null && $g['cpu'][1] > $g['cpu'][0]) {
            foreach ($scan['chips'] as $c) {
                if ($c['kind'] === 'cpu' && $c['temps']) {
                    $sources[] = ['kind' => 'cpu', 'id' => fanboySourceId($c, $c['temps'][0]['n']), 'low' => $g['cpu'][0], 'high' => $g['cpu'][1]];
                    break;
                }
            }
        }
        $zones[] = ['id' => 'fcp' . $i, 'name' => $g['from'][0], 'fans' => $g['fans'], 'outputs' => $g['outputs'], 'sources' => $sources,
            'target' => $points !== null ? ['points' => $points] : ['preset' => 'normal'], 'unit' => 'pct', 'from' => $g['from'],
            'idle_pct' => $pct($g['idle'])];
    }
    return ['files' => $files, 'active' => $active, 'zones' => $zones, 'unmatched' => $unmatched];
}
