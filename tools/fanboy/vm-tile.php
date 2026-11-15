<?php
// tools/fanboy/vm-tile.php — Fanboy's Dashboard tile rendered as SecretaryOfficeDashboard.page does, with PHP held to the
// office's own folders (the plugin, its RAM folder, its data folder: open_basedir, set after loading) and no way to run
// a command (vm-run.sh starts it with disable_functions). Every look outside those folders is caught and printed:
//   tile: shown|empty (<bytes>)   rows: <bytes>   zones: <names>   curves: <n>
//   outside: <paths or none>      forbidden: <paths under /sys, /dev, /proc, /mnt/disk*, or none>
// Dev only, test3 only (vm-run.sh round4).
declare(strict_types=1);

const P = '/usr/local/emhttp/plugins/unraid-secretary-office';
define('OFFICE_IN_UNRAID', true);
require_once P . '/src/bootstrap.php';
require_once P . '/src/page.php';
require_once P . '/src/dashboard.php';
require_once P . '/src/dashboard-fanboy.php';

$outside = [];
set_error_handler(function (int $no, string $msg) use (&$outside): bool {
    if (preg_match('/open_basedir restriction in effect\. File\((.*?)\)/', $msg, $m)) {
        $outside[$m[1]] = true;
    } else {
        $outside['php: ' . $msg] = true;
    }
    return true;
});
$dirs = array_unique(array_filter([P, officeRunDir(), OFFICE_DATA, realpath(OFFICE_DATA) ?: null, realpath(officeRunDir()) ?: null]));
ini_set('open_basedir', implode(':', $dirs));
try {
    $tile = officeFanboyDashTile();
    $rows = officeFanboyDashRows('en');
} catch (Throwable $e) {
    $outside['threw: ' . get_class($e) . ' ' . $e->getMessage()] = true;
    $tile = $rows = '';
}
restore_error_handler();
$forbidden = array_filter(array_keys($outside), fn ($p) => preg_match('#^(/sys|/dev|/proc|/mnt/disk|php: .*(exec|proc_open|popen|system)|threw)#', $p));
preg_match_all('/<(?:svg|polyline|path)\b/', $tile, $curves);
echo 'tile: ' . ($tile === '' ? 'empty' : 'shown') . ' (' . strlen($tile) . ")\n";
echo 'rows: ' . strlen($rows) . "\n";
echo 'zones: ' . (preg_match_all('/(Disk cage|Case|Rear)/', strip_tags($tile), $z) ? implode(', ', array_unique($z[1])) : 'none') . "\n";
echo 'curves: ' . count($curves[0]) . "\n";
echo 'folders: ' . implode(' ', $dirs) . "\n";
echo 'outside: ' . ($outside ? implode(' ', array_keys($outside)) : 'none') . "\n";
echo 'forbidden: ' . ($forbidden ? implode(' ', $forbidden) : 'none') . "\n";
