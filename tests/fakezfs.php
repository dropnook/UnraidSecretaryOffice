<?php
// A small stand-in for zfs, mount and umount (by its name) for Mr. Restori's swap and libvirt tests (task/restore-gaps):
// datasets in zfs.json beside it — {ds: {guid, mountpoint (null: from its parent), canmount, mounted, token, snaps: []}};
// a pool's root mounts at /mnt/<pool>. Every call in calls.log. busy.json: datasets whose umount fails (a file open).
$dir = __DIR__;
$me = basename($argv[0]);
$a = array_slice($argv, 1);
file_put_contents("$dir/calls.log", json_encode(array_merge([$me], $a)) . "\n", FILE_APPEND);
$st = json_decode((string) @file_get_contents("$dir/zfs.json"), true) ?: [];
$busy = json_decode((string) @file_get_contents("$dir/busy.json"), true) ?: [];
$save = function () use (&$st, $dir) { file_put_contents("$dir/zfs.json", json_encode($st)); };
$no = function (string $d) { fwrite(STDERR, "cannot open '$d': dataset does not exist\n"); exit(1); };
$mp = function (string $ds) use (&$st, &$mp): string {
    $v = $st[$ds]['mountpoint'] ?? null;
    if ($v !== null) { return $v; }
    $parent = str_contains($ds, '/') ? substr($ds, 0, (int) strrpos($ds, '/')) : null;
    if ($parent === null) { return "/mnt/$ds"; }
    $pm = $mp($parent);
    return $pm === 'legacy' || $pm === 'none' ? $pm : $pm . '/' . basename($ds);
};
if ($me === 'mount' || $me === 'umount') {        // mount -t zfs -o ro… <ds> <path> / umount <path>: the table in mounts
    $lines = array_values(array_filter(explode("\n", (string) @file_get_contents("$dir/mounts"))));
    if ($me === 'mount') { $lines[] = $a[4] . ' ' . $a[5] . ' zfs ro 0 0'; }
    else { $lines = array_values(array_filter($lines, fn ($l) => explode(' ', $l)[1] !== $a[0])); }
    file_put_contents("$dir/mounts", implode("\n", $lines) . "\n");
    exit(0);
}
$cmd = $a[0] ?? '';
$rest = array_values(array_filter(array_slice($a, 1), fn ($x) => $x[0] !== '-'));
switch ($cmd) {
    case 'list':
        $snap = in_array('snapshot', $a, true);
        $ds = end($a);
        if (!isset($st[$ds])) { $no($ds); }
        if ($snap) { foreach ($st[$ds]['snaps'] ?? [] as $s) { echo "$ds@$s\n"; } exit(0); }
        foreach (array_keys($st) as $n) { if ($n === $ds || (in_array('-r', $a, true) && str_starts_with($n, "$ds/"))) { echo "$n\n"; } }
        exit(0);
    case 'get':
        $ds = end($a);
        $prop = $a[count($a) - 2];
        if (!isset($st[$ds])) { $no($ds); }
        $v = match ($prop) {
            'guid' => (string) ($st[$ds]['guid'] ?? '1'),
            'mountpoint' => $mp($ds),
            'mounted' => !empty($st[$ds]['mounted']) ? 'yes' : 'no',
            'receive_resume_token' => $st[$ds]['token'] ?? '-',
            'canmount' => $st[$ds]['canmount'] ?? 'on',
            'written' => (string) ($st[$ds]['written'] ?? '0'),
            'readonly' => $st[$ds]['readonly'] ?? 'off',
            default => '-',
        };
        $src = $prop === 'mountpoint' ? (($st[$ds]['mountpoint'] ?? null) !== null ? 'local' : (str_contains($ds, '/') ? 'inherited from ' . explode('/', $ds)[0] : 'default')) : '-';
        echo in_array('value,source', $a, true) ? "$v\t$src\n" : "$v\n";
        exit(0);
    case 'rename':
        [$from, $to] = $rest;
        // watch: a file whose content names a file to copy at each rename (what the journal held right before it)
        if (is_file("$dir/watch")) { @copy(trim((string) file_get_contents("$dir/watch")), "$dir/at-rename-" . count(glob("$dir/at-rename-*") ?: []) . '.json'); }
        if (!isset($st[$from])) { $no($from); }
        if (isset($st[$to])) { fwrite(STDERR, "cannot rename to '$to': dataset already exists\n"); exit(1); }
        if (in_array($from, $busy, true) && !empty($st[$from]['mounted'])) { fwrite(STDERR, "cannot unmount '$from': pool or dataset is busy\n"); exit(1); }
        foreach (array_keys($st) as $n) {
            if ($n === $from || str_starts_with($n, "$from/")) { $st[$to . substr($n, strlen($from))] = $st[$n]; unset($st[$n]); }
        }
        $save();
        exit(0);
    case 'inherit':
        [$prop, $ds] = $rest;
        if (!isset($st[$ds])) { $no($ds); }
        if ($prop === 'mountpoint') { $st[$ds]['mountpoint'] = null; } else { unset($st[$ds][$prop]); }
        $save();
        exit(0);
    case 'set':
        [$kv, $ds] = $rest;
        if (!isset($st[$ds])) { $no($ds); }
        [$k, $v] = explode('=', $kv, 2);
        if ($k === 'mountpoint') {
            if (in_array($ds, $busy, true) && !empty($st[$ds]['mounted'])) { fwrite(STDERR, "cannot unmount: busy\n"); exit(1); }
            $st[$ds]['mountpoint'] = $v;
            if ($v === 'legacy') { $st[$ds]['mounted'] = false; }
        } else {
            $st[$ds][$k] = $v;
        }
        $save();
        exit(0);
    case 'mount':
        $ds = $rest[0];
        if (!isset($st[$ds])) { $no($ds); }
        if ($mp($ds) === 'legacy' || ($st[$ds]['canmount'] ?? 'on') !== 'on') { fwrite(STDERR, "cannot mount '$ds': legacy or canmount\n"); exit(1); }
        $st[$ds]['mounted'] = true;
        $save();
        exit(0);
    case 'create':                               // zfs create <ds>: mounted where its parent puts it; made under root (a file naming it) too
        $ds = end($a);
        $parent = str_contains($ds, '/') ? substr($ds, 0, (int) strrpos($ds, '/')) : '';
        if (isset($st[$ds])) { fwrite(STDERR, "cannot create '$ds': dataset already exists\n"); exit(1); }
        if (!isset($st[$parent])) { fwrite(STDERR, "cannot create '$ds': parent does not exist\n"); exit(1); }
        $st[$ds] = ['guid' => (string) random_int(100, 999999), 'mounted' => true];
        if (is_file("$dir/root")) { @mkdir(trim((string) file_get_contents("$dir/root")) . '/' . $ds, 0755, true); }
        $save();
        exit(0);
    case 'destroy':                              // zfs destroy -r <ds>: it and what lies in it go (noted, so a test can see what was destroyed)
        $ds = $rest[0];
        if (!isset($st[$ds])) { $no($ds); }
        foreach (array_keys($st) as $n) { if ($n === $ds || str_starts_with($n, "$ds/")) { unset($st[$n]); } }
        file_put_contents("$dir/destroyed.log", "$ds\n", FILE_APPEND);
        $save();
        exit(0);
    case 'umount':
        $ds = $rest[0];
        if (in_array($ds, $busy, true)) { fwrite(STDERR, "cannot unmount '$ds': pool or dataset is busy\n"); exit(1); }
        $st[$ds]['mounted'] = false;
        $save();
        exit(0);
}
fwrite(STDERR, "fakezfs: $cmd not known\n");
exit(2);
