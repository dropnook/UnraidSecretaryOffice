<?php
// Lab only: one office action through the agent's mailbox, as the page sends it (root CLI on the test server).
//   php ask.php <desk.action> ['<json body>' | @file.json] [wait seconds]
$dir = '/usr/local/emhttp/plugins/unraid-secretary-office';
require "$dir/src/bootstrap.php";
require_once "$dir/src/mailbox.php";
$arg = $argv[2] ?? '{}';
$data = json_decode(str_starts_with($arg, '@') ? (string) file_get_contents(substr($arg, 1)) : $arg, true, 32);
if (!is_array($data)) { fwrite(STDERR, "bad json\n"); exit(2); }
if ($argv[1] === 'office.hire' || $argv[1] === 'office.fire') {        // the web side's own, as api.php does
    require_once "$dir/src/staff.php";
    $r = officeStaffAction($argv[1], $data);
} else {
    $r = askAgent($argv[1], $data, (float) ($argv[3] ?? 120));
}
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
