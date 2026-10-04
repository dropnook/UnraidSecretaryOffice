<?php
declare(strict_types=1);

/*
 * Mr. Backup — runs the backup engine in backup/ (unraid-backup).
 *
 * The engine works on its own: User Scripts starts it at night, it keeps its
 * settings in data/unraid-backup/settings.ini and writes its state to
 * data/unraid-backup/state/ (status.json, last-run.json, history.jsonl,
 * drift.json — see "Status fuer andere Programme" in backup/README.md).
 * Mr. Backup reads that, shows it, and starts, checks or stops runs.
 *
 * Runs are handed to the host's atd ("at now"). A process started by the
 * agent itself would live in the agent container's cgroup, and Docker kills
 * that whole cgroup when the container stops or restarts — a 10-hour backup
 * must not depend on the office.
 *
 * Older script versions without state/status.json are shown from their log
 * files (read only); starting needs interface 1 or newer.
 */

const BACKUP_INTERFACE   = 1;
const BACKUP_MODES       = ['backup' => [], 'nokopia' => ['--no-kopia'], 'dryrun' => ['--dry-run'], 'check' => ['--check']];
const BACKUP_LOG_NAME    = '/^(?:(run|check|dryrun|setup)-(\d{8})-(\d{4})\.log|unmount\.log)$/';
const BACKUP_LOG_BYTES   = 512 * 1024;
const BACKUP_HISTORY     = 60;           // runs shown
const BACKUP_SCHEDULE    = '/boot/config/plugins/user.scripts/schedule.json';
const BACKUP_USER_SCRIPT = 'unraid-secretary-office_backup';   // was unraid-backup (userScriptsMigrate)

$GLOBALS['backup'] = null;
$GLOBALS['backupLogCache'] = [];         // legacy log file => [mtime, parsed]

desk('backup', [
    'fit'     => function (): array {
        $fs = houseSnapshotFilesystems();
        if (!$fs['zfs'] && !$fs['btrfs']) {
            return fit(false, 'no_cow');
        }
        foreach (houseContainers() as $c) {
            if (preg_match('/kopia/i', $c['image'] . ' ' . $c['name'])) {
                return fit(true, 'yes', ['kopia' => $c['name']]);
            }
        }
        return fit(true, 'no_kopia');
    },
    'start' => function (): void {
        $GLOBALS['backup'] = readJson(deskFile('backup'));
        backupScan();
    },
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => backupScan()],
        'start'   => fn (array $r) => backupStart(textField($r, 'mode')),
        'abort'   => fn (array $r) => backupAbort(),
        'unmount' => fn (array $r) => backupUnmount(),
        'log'     => fn (array $r) => backupLog(textField($r, 'log')),
        'setup_plan'  => fn (array $r) => backupSetupPlan(!empty($r['measure'])),
        'setup_get'   => fn (array $r) => backupSetupGet(),
        'setup_apply' => fn (array $r) => backupSetupApply($r['decisions'] ?? null),
        'schedule'    => fn (array $r) => backupSetSchedule($r['cron'] ?? null),
    ],
    'checks' => fn () => backupChecks(),
]);

// ===================================================================== state

function backupScan(): array
{
    userScriptsMigrate();              // waits while a run uses the old entry; cheap otherwise
    backupUserScriptDescribe();
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    $state = ['time' => time(), 'found' => false, 'dir' => $dir, 'data' => $data, 'asleep' => false];

    if (backupHomeAsleep($data)) {
        // the script folder sits on a sleeping disk: keep what we knew
        $old = $GLOBALS['backup'] ?? [];
        $state = ['time' => time(), 'asleep' => true] + $old + $state;
        $GLOBALS['backup'] = $state;
        writeAtomic(deskFile('backup'), jsonEncode($state));
        return $state;
    }
    if (!is_file("$dir/backup.sh")) {
        $GLOBALS['backup'] = $state;
        writeAtomic(deskFile('backup'), jsonEncode($state));
        return $state;
    }

    $about = backupAbout($dir);
    $settings = backupReadSettings("$data/settings.ini");
    $setup = backupSetupStatus();
    // the lock is shared with setup.sh: while it plans or applies, no backup is running
    $running = flockHeld("$data/state/lock") && !$setup['running'];
    $status = readJson("$data/state/status.json");
    if ($status && ($status['interface'] ?? 0) < BACKUP_INTERFACE) {
        $status = null;
    }
    // status.json says "running" but nobody holds the lock: killed hard (kill -9, reboot)
    if ($status && ($status['result'] ?? '') === 'running' && !$running) {
        $status['result'] = 'failed';
        $status['message'] = 'interrupted';
        $status['interrupted'] = true;
    }

    $logs = backupLogs();
    $history = backupHistory($logs, $running ? ($status['run'] ?? null) : null);

    $state += [
        'version'    => $about['version'] ?? null,
        'interface'  => $about['interface'] ?? 0,
        'compatible' => ($about['interface'] ?? 0) >= BACKUP_INTERFACE,
        'settings_found' => is_file("$data/settings.ini"),
        'running'    => $running,
        'status'     => $status,
        'step'       => $running && !$status ? lastLogStep("$data/logs/latest.log") : null,
        'since'      => $running ? (@filemtime("$data/state/lock") ?: null) : null,
        'paused'     => $running ? backupPaused($data) : null,
        'history'    => $history,
        'estimates'  => backupEstimates($history),
        'drift'      => backupDrift(),
        'settings'   => backupSettingsSummary($settings),
        'shares'     => backupShares($settings, $history),
        'dumps'      => backupDumps(),
        'schedule'   => backupSchedule(),
        'logs'       => array_values(array_map(fn ($l) => ['name' => $l['name'], 'kind' => $l['kind'], 'time' => $l['time'], 'size' => $l['size']], $logs)),
        'mounted'    => backupMounted(backupSetting($settings, 'general', 'mount_root')),
        'setup'      => $setup,
    ];
    $state['found'] = true;
    $GLOBALS['backup'] = $state;
    writeAtomic(deskFile('backup'), jsonEncode($state));
    return $state;
}

/** Name, version and interface of the script ("backup.sh --about", since 2.5) */
function backupAbout(string $dir): array
{
    static $cache = [];
    $stamp = (int) @filemtime("$dir/lib/common.sh") . ':' . (int) @filemtime("$dir/backup.sh");
    if (($cache[$dir][0] ?? null) === $stamp) {
        return $cache[$dir][1];
    }
    $about = [];
    [$exit, $out] = run(['bash', "$dir/backup.sh", '--about'], 10);
    $j = $exit === 0 ? json_decode(trim($out), true) : null;
    if (is_array($j)) {
        $about = $j;
    } elseif (preg_match('/^UB_VERSION="([^"]+)"/m', (string) @file_get_contents("$dir/lib/common.sh"), $m)) {
        $about = ['version' => $m[1], 'interface' => 0];      // before 2.5
    }
    $cache[$dir] = [$stamp, $about];
    return $about;
}

/**
 * Is the script folder on a sleeping disk? Then reading it would wake the
 * disk up — every refresh. Only awake disks and pools are looked at.
 */
function backupHomeAsleep(string $dir): bool
{
    $asleep = sleepingDisks();
    if (!in_array(true, $asleep, true)) {
        return false;
    }
    if (preg_match('#^/mnt/([^/]+)/#', $dir, $m) && !in_array($m[1], ['user', 'user0'], true)) {
        return $asleep[$m[1]] ?? false;
    }
    if (!preg_match('#^/mnt/user0?/(.+)$#', $dir, $m)) {
        return false;
    }
    foreach (mountTable() as $mount) {
        if (preg_match('#^/mnt/([^/]+)$#', $mount['mount'], $x) && !in_array($x[1], ['user', 'user0', 'disks', 'remotes', 'addons', 'rootshare'], true)
            && !($asleep[$x[1]] ?? false) && is_dir("/mnt/{$x[1]}/{$m[1]}")) {
            return false;
        }
    }
    return true;
}

function backupSettingsSummary(array $s): array
{
    $one = fn (string $section, string $key, ?string $default = null) => backupSetting($s, $section, $key, $default);
    $kopia = [];
    foreach (['latest', 'hourly', 'daily', 'weekly', 'monthly', 'annual'] as $k) {
        $kopia[$k] = $one('kopia', "keep_$k");
    }
    $names = fn (string $type) => array_values(array_map(fn ($k) => substr($k, strlen($type) + 1),
        array_filter(array_keys($s), fn ($k) => str_starts_with($k, "$type|"))));
    return [
        'server'        => $one('general', 'server'),
        'mount_root'    => $one('general', 'mount_root'),
        'view_root'     => $one('general', 'view_root'),
        'snap_prefix'   => $one('general', 'snap_prefix', 'unraidbackup-'),
        'btrfs_dir'     => $one('general', 'btrfs_snap_dir', '.btrfs-snap'),
        'keep_runs'     => (int) $one('general', 'keep_runs', '7'),
        'dumps_share'   => $one('general', 'dumps_share'),
        'keep_mounts'   => $one('general', 'keep_mounts', 'no') === 'yes',
        'zfs_retention' => $one('zfs', 'retention'),
        'btrfs_days'    => $one('btrfs', 'keep_days'),
        'docker_stop'   => $one('docker', 'stop', 'all'),
        'no_stop'       => $s['docker']['no_stop'] ?? [],
        'flash'         => $one('flash', 'mode', 'off'),
        'libvirt'       => $one('libvirt', 'mode', 'tar'),
        'libvirt_img'   => readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? '/mnt/user/system/libvirt/libvirt.img',
        'kopia_enabled' => in_array(strtolower((string) $one('kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true),
        'kopia_container' => $one('kopia', 'container'),
        'kopia_keep'    => $kopia,
        'dumps'         => array_map(fn ($n) => ['container' => $n, 'type' => backupSetting($s, "dump|$n", 'type')], $names('dump')),
        'nextcloud'     => array_map(fn ($n) => ['container' => $n, 'preexisting' => backupSetting($s, "nextcloud|$n", 'preexisting_maintenance', 'abort')], $names('nextcloud')),
    ];
}

/** Shares as settings.ini knows them, with the last Kopia result of each */
function backupShares(array $s, array $history): array
{
    $last = [];
    foreach ($history as $run) {                       // newest first
        foreach ($run['kopia'] ?? [] as $k) {
            $last[$k['name']] ??= ['time' => $k['finished'] ?: $run['started'], 'ok' => $k['ok'], 'seconds' => $k['seconds']];
            if ($k['ok']) {
                $last[$k['name']]['good'] ??= $k['finished'] ?: $run['started'];
            }
        }
    }
    $shares = [];
    foreach ($s as $key => $values) {
        if (!str_starts_with($key, 'share|')) {
            continue;
        }
        $name = substr($key, 6);
        $shares[] = [
            'name'       => $name,
            'mode'       => backupSetting($s, $key, 'mode', 'off'),
            'method'     => backupSetting($s, $key, 'method', 'auto'),
            'retention'  => backupSetting($s, $key, 'retention'),
            'kopia_retention' => backupSetting($s, $key, 'kopia_retention'),
            'ignores'    => $values['kopia_ignore'] ?? [],
            'excluded'   => $values['exclude_dataset'] ?? [],
            'locations'  => array_values(array_filter(array_map('trim', explode(',', (string) backupSetting($s, $key, 'locations', ''))))),
            'last'       => $last[$name] ?? null,
        ];
    }
    if (backupSetting($s, 'flash', 'mode') === 'snapshot') {
        $shares[] = ['name' => 'flash', 'mode' => 'kopia', 'method' => 'flash', 'retention' => null, 'kopia_retention' => null,
                     'ignores' => $s['flash']['kopia_ignore'] ?? [], 'excluded' => [], 'locations' => ['boot'], 'last' => $last['flash'] ?? null, 'flash' => true];
    }
    usort($shares, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $shares;
}

// ===================================================================== history

/** @return array<string, array{name:string, kind:string, time:int, size:int, path:string}> newest first */
function backupLogs(): array
{
    $data = BACKUP_DATA_DIR;
    $logs = [];
    foreach (@scandir("$data/logs") ?: [] as $name) {
        if (!preg_match(BACKUP_LOG_NAME, $name, $m)) {
            continue;
        }
        $path = "$data/logs/$name";
        $time = isset($m[2]) && $m[2] !== ''
            ? (int) (DateTime::createFromFormat('Ymd-Hi', "$m[2]-$m[3]")?->getTimestamp() ?: 0)
            : (int) @filemtime($path);
        $logs[$name] = ['name' => $name, 'kind' => $m[1] ?? 'unmount', 'time' => $time, 'size' => (int) @filesize($path), 'path' => $path];
    }
    uasort($logs, fn ($a, $b) => $b['time'] <=> $a['time']);
    return $logs;
}

/**
 * Finished backup runs, newest first: from history.jsonl, and for runs of
 * older versions (or before 2.5) read from their logs.
 */
function backupHistory(array $logs, ?string $runningRun): array
{
    $data = BACKUP_DATA_DIR;
    $runs = [];
    foreach (@file("$data/state/history.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $j = json_decode($line, true);
        if (is_array($j) && !empty($j['run'])) {
            $runs[$j['run']] = backupRunFromStatus($j);
        }
    }
    foreach ($logs as $log) {
        if ($log['kind'] !== 'run') {
            continue;
        }
        $run = substr($log['name'], 4, 13);
        if (isset($runs[$run]) || $run === $runningRun) {
            continue;
        }
        $cache = $GLOBALS['backupLogCache'][$log['path']] ?? null;
        $stamp = $log['size'] . ':' . @filemtime($log['path']);
        if (!$cache || $cache[0] !== $stamp) {
            $cache = [$stamp, backupRunFromLog($log['path'], $run, $log['time'])];
            $GLOBALS['backupLogCache'][$log['path']] = $cache;
        }
        $runs[$run] = $cache[1];
    }
    usort($runs, fn ($a, $b) => $b['started'] <=> $a['started']);
    return array_slice($runs, 0, BACKUP_HISTORY);
}

function backupRunFromStatus(array $j): array
{
    return [
        'run'        => (string) $j['run'],
        'started'    => (int) ($j['started'] ?? 0),
        'finished'   => (int) ($j['finished'] ?? 0),
        'result'     => (string) ($j['result'] ?? 'failed'),
        'message'    => (string) ($j['message'] ?? ''),
        'errors'     => (int) ($j['errors'] ?? 0),
        'warnings'   => (int) ($j['warnings'] ?? 0),
        'downtime'   => (int) ($j['downtime_s'] ?? 0),
        'dump_bytes' => (int) ($j['dump_bytes'] ?? 0),
        'kopia'      => array_map(fn ($k) => ['name' => (string) $k['name'], 'ok' => (bool) $k['ok'], 'seconds' => (int) $k['seconds'], 'finished' => (int) ($k['finished'] ?? 0)],
                                  $j['kopia']['done'] ?? []),
        'kopia_first' => null,
        'log'        => (string) ($j['log'] ?? ''),
        'version'    => (string) ($j['version'] ?? ''),
        'source'     => 'status',
    ];
}

/**
 * A run of a script version without status files, from its log. The log
 * lines are German and only meant for people; this is a fallback, nothing
 * else depends on it.
 */
function backupRunFromLog(string $path, string $run, int $started): array
{
    $r = ['run' => $run, 'started' => $started, 'finished' => 0, 'result' => 'failed', 'message' => 'interrupted',
          'errors' => 0, 'warnings' => 0, 'downtime' => 0, 'dump_bytes' => 0, 'kopia' => [], 'kopia_first' => null,
          'log' => basename($path), 'version' => '', 'source' => 'log'];
    $h = @fopen($path, 'r');
    if (!$h) {
        return $r;
    }
    $current = null;
    $lastError = null;
    $last = $started;
    while (($line = fgets($h)) !== false) {
        if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)  (.*)$/', rtrim($line), $m)) {
            continue;
        }
        $t = strtotime($m[1]) ?: $last;
        $last = $t;
        $text = $m[2];
        if (preg_match('/^=+ \S+ (\S+) - backup /', $text, $x)) {
            $r['version'] = $x[1];
        } elseif (preg_match('/^Kopia: (\S+)  \(/', $text, $x)) {
            $current = [$x[1], $t];
            $r['kopia_first'] ??= $t;
        } elseif ($current && preg_match('/^\s+ok \((\d+) s\)/', $text, $x)) {
            $r['kopia'][] = ['name' => $current[0], 'ok' => true, 'seconds' => (int) $x[1], 'finished' => $t];
            $current = null;
        } elseif ($current && str_contains($text, "Kopia-Snapshot von '{$current[0]}' fehlgeschlagen")) {
            $r['kopia'][] = ['name' => $current[0], 'ok' => false, 'seconds' => $t - $current[1], 'finished' => $t];
            $current = null;
        } elseif (preg_match('/Unterbrechung (\d+) s$/', $text, $x)) {
            $r['downtime'] = (int) $x[1];
        } elseif (str_starts_with($text, 'FEHLER:')) {
            $r['errors']++;
            $lastError = trim(substr($text, 7));
        } elseif (str_starts_with($text, 'WARNUNG:')) {
            $r['warnings']++;
        } elseif (preg_match('/^Backup (erfolgreich|mit .*beendet)/', $text, $x)) {
            $r['finished'] = $t;
            $r['message'] = '';
            $r['result'] = str_contains($text, 'Fehler(n)') ? 'errors' : (str_contains($text, 'Warnung') ? 'warnings' : 'ok');
        }
    }
    fclose($h);
    if ($r['result'] === 'failed') {
        $r['finished'] = $last;
        $r['message'] = $lastError ?? 'interrupted';     // die() logs its reason as the last error
    }
    return $r;
}

/**
 * Typical durations from the last good runs, for "ready at about …":
 * per Kopia source and for the part before Kopia starts.
 */
function backupEstimates(array $history): array
{
    $sources = [];
    $before = [];
    $total = [];
    foreach ($history as $run) {
        if ($run['result'] === 'failed' || $run['result'] === 'aborted') {
            continue;
        }
        foreach ($run['kopia'] as $k) {
            if ($k['ok'] && count($sources[$k['name']] ?? []) < 5) {
                $sources[$k['name']][] = $k['seconds'];
            }
        }
        $first = $run['kopia_first'];
        if ($first === null && $run['kopia']) {
            $k0 = $run['kopia'][0];
            $first = $k0['finished'] - $k0['seconds'];
        }
        if ($first && count($before) < 5) {
            $before[] = max(0, $first - $run['started']);
        }
        if ($run['finished'] && count($total) < 5) {
            $total[] = $run['finished'] - $run['started'];
        }
    }
    $median = function (array $v): ?int {
        if (!$v) {
            return null;
        }
        sort($v);
        return (int) $v[intdiv(count($v), 2)];
    };
    return [
        'sources' => array_map($median, $sources),
        'before'  => $median($before),
        'total'   => $median($total),
    ];
}

// ===================================================================== more state

function backupDrift(): array
{
    $data = BACKUP_DATA_DIR;
    $j = readJson("$data/state/drift.json");
    if ($j) {
        return ['time' => (int) ($j['time'] ?? 0), 'items' => array_values(array_filter($j['items'] ?? [], 'is_array'))];
    }
    // before 2.5: drift.txt with German level words
    $items = [];
    foreach (@file("$data/state/drift.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^(FEHLER|WARNUNG|INFO)\s+(.*)$/', $line, $m)) {
            $items[] = ['level' => ['FEHLER' => 'error', 'WARNUNG' => 'warn', 'INFO' => 'info'][$m[1]], 'text' => $m[2]];
        }
    }
    return ['time' => (int) @filemtime("$data/state/drift.txt"), 'items' => $items];
}

/** Where the engine keeps dumps and archives: <dumps_share>/unraid-backup; the old folder in appdata until the first run moved them */
function backupDumpsDir(): string
{
    $settings = backupReadSettings(BACKUP_DATA_DIR . '/settings.ini');
    $share = backupSettingsSummary($settings)['dumps_share'] ?? null;
    $new = $share ? "/mnt/user/$share/unraid-backup" : null;
    $old = BACKUP_DATA_DIR . '/dumps';
    if ($new && is_dir($new)) {
        return $new;
    }
    return is_dir($old) ? $old : ($new ?? $old);
}

/** dumps/<run>/: database dumps (+ manifest, flash archive) of the last runs */
function backupDumps(): array
{
    $data = BACKUP_DATA_DIR;
    $dumps = [];
    $dir = backupDumpsDir();
    foreach (@scandir($dir, SCANDIR_SORT_DESCENDING) ?: [] as $run) {
        if (!preg_match('/^\d{8}-\d{4}$/', $run)) {
            continue;
        }
        $files = [];
        $bytes = 0;
        foreach (@scandir("$dir/$run/db") ?: [] as $f) {
            if ($f[0] === '.' || !is_file("$dir/$run/db/$f")) {
                continue;
            }
            $size = (int) @filesize("$dir/$run/db/$f");
            $bytes += $size;
            $files[] = ['name' => $f, 'bytes' => $size];
        }
        $flash = glob("$dir/$run/flash*.tar*") ?: [];
        $libvirt = is_file("$dir/$run/libvirt.tar.gz") ? "$dir/$run/libvirt.tar.gz" : null;
        $dumps[] = [
            'run'      => $run,
            'time'     => (int) (DateTime::createFromFormat('Ymd-Hi', $run)?->getTimestamp() ?: 0),
            'path'     => "$dir/$run",
            'files'    => $files,
            'bytes'    => $bytes,
            'manifest' => is_dir("$dir/$run/manifest"),
            'flash'    => $flash ? basename($flash[0]) : null,
            'libvirt'  => $libvirt,
            'libvirt_bytes' => $libvirt ? (int) @filesize($libvirt) : null,
            'libvirt_vms' => $libvirt ? backupLibvirtArchive($libvirt) : [],
        ];
    }
    return $dumps;
}

/**
 * The VMs in a libvirt archive (libvirt.tar.gz from the dumps): name, UUID,
 * and which of their files are in it — for ready-made restore commands.
 * Read once per archive (a few hundred KB).
 */
function backupLibvirtArchive(string $file): array
{
    static $cache = [];
    $stamp = $file . ':' . (int) @filemtime($file);
    if (isset($cache[$stamp])) {
        return $cache[$stamp];
    }
    [$exit, $list] = run(['tar', '-tzf', $file], 30);
    if ($exit !== 0) {
        return $cache[$stamp] = [];
    }
    $names = array_flip(array_map(fn ($l) => rtrim($l, '/'), explode("\n", trim($list))));
    $vms = [];
    foreach (array_keys($names) as $entry) {
        if (!preg_match('#^libvirt/qemu/([^/]+)\.xml$#', $entry, $m)) {
            continue;
        }
        [$e, $xml] = run(['tar', '-xzOf', $file, $entry], 20);
        $uuid = $e === 0 && preg_match('#<uuid>([0-9a-f-]{36})</uuid>#', $xml, $u) ? $u[1] : null;
        $nvram = $uuid ? array_values(array_filter(array_keys($names), fn ($n) => preg_match('#^libvirt/qemu/nvram/' . preg_quote($uuid, '#') . '_VARS[^/]*\.fd$#', $n))) : [];
        $vms[] = [
            'name'      => $m[1],
            'uuid'      => $uuid,
            'nvram'     => array_map('basename', $nvram),
            'tpm'       => $uuid && isset($names["libvirt/qemu/swtpm/tpm-states/$uuid"]),
            'autostart' => isset($names["libvirt/qemu/autostart/{$m[1]}.xml"]),
        ];
    }
    usort($vms, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $cache = [$stamp => $vms];
    return $vms;
}

/** The User Scripts entry setup.sh creates, and its schedule */
function backupSchedule(): array
{
    $script = "/boot/config/plugins/user.scripts/scripts/" . BACKUP_USER_SCRIPT . '/script';
    $result = ['script' => is_file($script), 'frequency' => null, 'custom' => null, 'enabled' => false];
    foreach ((array) json_decode((string) @file_get_contents(BACKUP_SCHEDULE), true) as $entry) {
        if (is_array($entry) && ($entry['script'] ?? '') === $script) {
            $result['frequency'] = (string) ($entry['frequency'] ?? '');
            $result['custom'] = (string) ($entry['custom'] ?? '');
            $result['enabled'] = !in_array($result['frequency'], ['', 'disabled'], true);
        }
    }
    return $result;
}

/**
 * The entry's description as the office writes it today (setup.sh writes the
 * same for new entries) — only the #description line, nothing else.
 */
function backupUserScriptDescribe(): void
{
    $file = US_DIR . '/scripts/' . BACKUP_USER_SCRIPT . '/script';
    $text = (string) @file_get_contents($file);
    if (!preg_match('#^exec "([^"]+)/backup\.sh"#m', $text, $m)) {
        return;
    }
    $line = "#description=Unraid Secretary Office - Mr. Backupsy's nightly backup: snapshots, database dumps, Kopia offsite. "
          . "Set up and scheduled in the office. Code: {$m[1]}, data: " . dirname($m[1]) . '/data/unraid-backup';
    $new = preg_replace('/^#description=.*$/m', $line, $text, 1);
    if ($new !== null && $new !== $text) {
        writeAtomic($file, $new, 0755, 0, 0);
    }
}

/**
 * A Nextcloud container's data folder on the host: 'datadirectory' from its
 * config.php (read through the container's own mounts), mapped back to the
 * host. Null when it can't be found out.
 */
function backupNextcloudDataDir(string $container): ?string
{
    $c = houseInspect($container);
    if (!$c) {
        return null;
    }
    $mounts = [];
    foreach ((array) ($c['Mounts'] ?? []) as $m) {
        if (!empty($m['Source']) && !empty($m['Destination'])) {
            $mounts[rtrim($m['Destination'], '/')] = rtrim($m['Source'], '/');
        }
    }
    $toHost = function (string $path) use ($mounts): ?string {
        $best = null;
        foreach ($mounts as $dest => $src) {
            if (($path === $dest || str_starts_with($path, "$dest/")) && ($best === null || strlen($dest) > strlen($best))) {
                $best = $dest;
            }
        }
        return $best === null ? null : $mounts[$best] . substr($path, strlen($best));
    };
    $config = $toHost('/var/www/html/config/config.php');
    $text = $config ? (string) @file_get_contents($config, false, null, 0, 65536) : '';
    $dir = preg_match("/'datadirectory'\s*=>\s*'([^']+)'/", $text, $m) ? rtrim($m[1], '/') : '/var/www/html/data';
    $host = $toHost($dir);
    return $host !== null && is_dir($host) ? $host : null;
}

/**
 * What a running backup has paused right now — the engine notes it in state/
 * so it can put things back after a crash: stopped containers (state/stopped,
 * gone once they run again) and Nextclouds in maintenance mode.
 */
function backupPaused(string $data): array
{
    $read = function (string $file): array {
        $list = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $list), fn ($x) => $x !== '' && preg_match('/^[\w.@-]{1,128}$/', $x))));
    };
    $stopped = $read("$data/state/stopped");
    $maint = $read("$data/state/maintenance");
    return [
        'stopped'           => $stopped,
        'stopped_since'     => $stopped ? (@filemtime("$data/state/stopped") ?: null) : null,
        'maintenance'       => $maint,
        'maintenance_since' => $maint ? (@filemtime("$data/state/maintenance") ?: null) : null,
    ];
}

/** Sets the nightly run in User Scripts (cron) or switches it off (null / '') */
function backupSetSchedule(mixed $cron): array
{
    $cron = is_string($cron) && trim($cron) !== '' ? trim($cron) : null;
    $live = userScriptSchedule(BACKUP_USER_SCRIPT, $cron);
    logLine('Mr. Backup: schedule ' . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => backupScan()];
}

/** Mounts the script left under its mount root (keep_mounts, or a run that died) */
function backupMounted(?string $root): array
{
    if (!$root) {
        return [];
    }
    $found = [];
    foreach (mountTable() as $m) {
        if (under($m['mount'], $root) && $m['mount'] !== rtrim($root, '/')) {
            $found[] = $m['mount'];
        }
    }
    return $found;
}

// ===================================================================== actions

function backupCheckReady(): string
{
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh")) {
        throw new Problem('backup_missing', ['dir' => $dir]);
    }
    if ((backupAbout($dir)['interface'] ?? 0) < BACKUP_INTERFACE) {
        throw new Problem('backup_too_old', ['version' => backupAbout($dir)['version'] ?? '?']);
    }
    if (flockHeld("$data/state/lock")) {
        throw new Problem(backupSetupStatus()['running'] ? 'setup_running' : 'backup_running');
    }
    return $dir;
}

/** Hands a backup engine command to the host's atd (see hostLaunch()) */
function backupLaunch(array $args, array $env = []): void
{
    hostLaunch('backup-job', array_merge(['/bin/bash'], $args), $env);
}

function backupStart(string $mode): array
{
    if (!isset(BACKUP_MODES[$mode])) {
        throw new Problem('unknown_target', ['target' => $mode]);
    }
    $dir = backupCheckReady();
    $data = BACKUP_DATA_DIR;
    if (!is_file("$data/settings.ini")) {
        throw new Problem('backup_no_settings');
    }
    $before = (int) (readJson("$data/state/status.json")['started'] ?? 0);
    backupLaunch(array_merge([$dir . '/backup.sh'], BACKUP_MODES[$mode]));
    logLine("Backup: started backup.sh ($mode) via at");

    // wait a moment until it took the lock and wrote its status
    $seen = false;
    for ($i = 0; $i < 40 && !$seen; $i++) {
        usleep(250000);
        clearstatcache();
        $status = readJson("$data/state/status.json");
        $seen = (int) ($status['started'] ?? 0) > $before;
    }
    return ['ok' => true, 'started' => $seen, 'state' => backupScan()];
}

/** SIGTERM to the running backup.sh — its trap cleans up (Kopia, containers, mounts) */
function backupAbort(): array
{
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!flockHeld("$data/state/lock")) {
        throw new Problem('backup_not_running');
    }
    $pid = (int) (readJson("$data/state/status.json")['pid'] ?? 0);
    if (!backupIsScript($pid, $dir)) {
        $pid = 0;
        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $proc) {
            $p = (int) basename($proc);
            if (backupIsScript($p, $dir) && !backupIsScript((int) backupParent($p), $dir)) {
                $pid = $p;
                break;
            }
        }
    }
    if (!$pid) {
        throw new Problem('backup_no_process');
    }
    if (!posix_kill($pid, SIGTERM)) {
        throw new Problem('command_failed', ['detail' => "kill $pid"]);
    }
    logLine("Backup: sent SIGTERM to backup.sh (PID $pid)");
    usleep(500000);
    return ['ok' => true, 'pid' => $pid, 'state' => backupScan()];
}

function backupIsScript(int $pid, string $dir): bool
{
    if ($pid <= 1) {
        return false;
    }
    $args = explode("\0", (string) @file_get_contents("/proc/$pid/cmdline"));
    foreach (array_slice($args, 0, 3) as $arg) {
        if ($arg === "$dir/backup.sh" || ($arg !== '' && @realpath($arg) === @realpath("$dir/backup.sh"))) {
            return true;
        }
    }
    return false;
}

function backupParent(int $pid): int
{
    return preg_match('/^PPid:\s+(\d+)/m', (string) @file_get_contents("/proc/$pid/status"), $m) ? (int) $m[1] : 0;
}

/** Releases leftover snapshot mounts ("backup.sh --unmount") — never during a run */
function backupUnmount(): array
{
    $dir = backupCheckReady();
    $root = backupSetting(backupReadSettings(BACKUP_DATA_DIR . '/settings.ini'), 'general', 'mount_root');
    if (!backupMounted($root)) {
        return ['ok' => true, 'state' => backupScan()];
    }
    backupLaunch([$dir . '/backup.sh', '--unmount']);
    logLine('Backup: started backup.sh --unmount via at');
    for ($i = 0; $i < 60 && backupMounted($root); $i++) {
        usleep(500000);
    }
    $left = backupMounted($root);
    if ($left) {
        throw new Problem('backup_unmount_left', ['n' => count($left), 'path' => $left[0]]);
    }
    return ['ok' => true, 'state' => backupScan()];
}

function backupLog(string $name): array
{
    $logs = backupLogs();
    if (!isset($logs[$name])) {
        throw new Problem('unknown_target', ['target' => $name]);
    }
    $path = $logs[$name]['path'];
    $size = (int) @filesize($path);
    $h = @fopen($path, 'r');
    if (!$h) {
        throw new Problem('command_failed', ['detail' => "cannot read $name"]);
    }
    if ($size > BACKUP_LOG_BYTES) {
        fseek($h, $size - BACKUP_LOG_BYTES);
        fgets($h);              // start at a full line
    }
    $text = (string) stream_get_contents($h);
    fclose($h);
    return ['ok' => true, 'name' => $name, 'size' => $size, 'cut' => $size > BACKUP_LOG_BYTES, 'text' => $text];
}

// ===================================================================== checks (for the caretaker)

/** What Mr. Backup needs from the server, and what the user still has to do */
function backupChecks(): array
{
    $data = BACKUP_DATA_DIR;
    $out = [];
    $settings = backupReadSettings("$data/settings.ini");
    $summary = backupSettingsSummary($settings);
    $kopiaOn = $summary['kopia_enabled'];

    $out[] = finding('user_scripts', 'required', housePlugin('user.scripts'), [], 'apps');
    $out[] = finding('setup', 'required', is_file("$data/settings.ini"), ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    $schedule = backupSchedule();
    $out[] = finding('user_script', 'required', $schedule['script'], ['path' => BACKUP_SCRIPT_DIR . '/setup.sh'], '#/backup/setup');
    if ($schedule['script']) {
        $out[] = finding('schedule', 'required', $schedule['enabled'], [], '#/backup/schedule');
    }

    // Kopia: a must for offsite copies, otherwise a recommendation
    $level = $kopiaOn ? 'required' : 'recommended';
    $containers = houseContainers();
    $name = $summary['kopia_container'];
    if (!$name || !isset($containers[$name])) {
        $name = null;
        foreach ($containers as $c) {
            if (stripos($c['image'], 'kopia') !== false) {
                $name = $c['name'];
                break;
            }
        }
    }
    $out[] = finding('kopia', $level, $name !== null, ['name' => $name ?? 'kopia'], 'apps');
    if ($name !== null) {
        $c = $containers[$name];
        $out[] = finding('kopia_running', $level, $c['running'], ['name' => $name], 'docker');
        $inspect = houseInspect($name);
        if ($inspect) {
            $env = [];
            foreach ($inspect['Config']['Env'] ?? [] as $e) {
                [$k, $v] = explode('=', $e, 2) + [1 => ''];
                $env[$k] = $v;
            }
            // linuxserver-style images run the server as PUID; without PUID the image decides (often root)
            $asRoot = !isset($env['PUID']) || $env['PUID'] === '0';
            $out[] = finding('kopia_root', $level, $asRoot, ['name' => $name, 'puid' => $env['PUID'] ?? '–'], 'docker');
            $root = rtrim($summary['mount_root'] ?: '/mnt/backup-snapshots', '/');
            $mapping = null;
            foreach ($inspect['Mounts'] ?? [] as $m) {
                if (rtrim((string) ($m['Source'] ?? ''), '/') === $root) {
                    $mapping = $m;
                }
            }
            $ok = $mapping && empty($mapping['RW']) && in_array($mapping['Propagation'] ?? '', ['slave', 'rslave'], true);
            $out[] = finding('kopia_mapping', $level, $ok, ['name' => $name, 'path' => $root,
                'now' => $mapping ? (($mapping['RW'] ? 'rw' : 'ro') . ',' . ($mapping['Propagation'] ?: 'private')) : '–'], 'docker');
        }
        if ($kopiaOn) {
            // the engine finds out whether the repository answers; we read its last word
            $status = readJson("$data/state/status.json");
            $state = $status['kopia']['state'] ?? null;
            $out[] = finding('kopia_repo', 'required', $state === null ? null : $state === 'yes', ['name' => $name]);
        }
    }

    if (is_file("$data/settings.ini")) {
        $drift = array_filter(backupDrift()['items'], fn ($d) => in_array($d['level'] ?? '', ['warn', 'error'], true));
        $out[] = finding('drift', 'recommended', !$drift, ['n' => count($drift)], '#/backup/setup');

        // dumps and archives in their own backup share, never in appdata — without one the engine refuses to run
        $ds = (string) ($summary['dumps_share'] ?? '');
        $dsBad = $ds === '' || in_array(strtolower($ds), ['appdata', 'system', 'domains'], true) || !is_dir("/mnt/user/$ds");
        $out[] = finding('dumps_share', 'required', !$dsBad, ['share' => $ds], '#/backup/setup');
        if (!$dsBad && $kopiaOn) {
            $mode = backupSetting($settings, "share|$ds", 'mode', 'off');
            $out[] = finding('dumps_offsite', 'recommended', $mode === 'kopia', ['share' => $ds, 'mode' => $mode], '#/backup/setup');
        }

        // Nextcloud refuses to work (and so its maintenance mode) when others can read its data folder —
        // and Unraid resets a share's root to 0777 whenever share settings are saved
        $seen = [];
        foreach ($summary['nextcloud'] as $nc) {
            $dir = backupNextcloudDataDir($nc['container']);
            if ($dir !== null && !isset($seen[$dir])) {     // app and cron of one Nextcloud share it
                $seen[$dir] = true;
                $mode = @fileperms($dir);
                $out[] = finding('nextcloud_datadir', 'required', $mode === false ? null : ($mode & 0007) === 0,
                    ['name' => $nc['container'], 'path' => $dir, 'mode' => $mode === false ? '?' : sprintf('%o', $mode & 0777)], 'userscripts');
            }
        }

        // the backup stops running containers for its snapshots — the office too, unless told otherwise
        $office = array_values(array_filter(array_keys($containers), fn ($n) => str_starts_with($n, 'UnraidSecretaryOffice')));
        if ($office && $summary['docker_stop'] !== 'none') {
            $stopped = array_values(array_diff($office, $summary['no_stop']));
            $out[] = finding('office_keeps_running', 'recommended', !$stopped, ['names' => implode(', ', $stopped)], '#/backup/setup');
        }
    }

    return $out;
}

// ===================================================================== setup (setup.sh --plan / --apply)

const BACKUP_SETUP_KEYS = ['mode', 'retention', 'kopia_retention', 'method', 'kopia_ignore', 'exclude_dataset'];

/** setup.sh's progress (state/setup-status.json), without the messages */
function backupSetupStatus(): array
{
    $data = BACKUP_DATA_DIR;
    $s = readJson("$data/state/setup-status.json");
    $running = $s && ($s['result'] ?? '') === 'running' && posix_kill((int) ($s['pid'] ?? 0), 0)
        && flockHeld("$data/state/lock");
    if ($s && ($s['result'] ?? '') === 'running' && !$running) {
        $s['result'] = 'failed';               // killed hard
    }
    return [
        'running' => $running,
        'mode'    => $s['mode'] ?? null,
        'result'  => $s['result'] ?? null,
        'started' => $s['started'] ?? null,
        'finished' => $s['finished'] ?? null,
        'written' => $s['written'] ?? false,
        'errors'  => $s['errors'] ?? 0,
        'warnings' => $s['warnings'] ?? 0,
        'plan_time' => @filemtime("$data/state/setup-plan.json") ?: null,
    ];
}

/** Looks at the server and makes proposals — writes nothing but state/setup-plan.json */
function backupSetupPlan(bool $measure): array
{
    $dir = backupCheckReady();
    backupLaunch(["$dir/setup.sh", '--plan'], ['UB_SIZE_TIMEOUT' => $measure ? 120 : 0]);
    logLine('Backup: setup.sh --plan started via at' . ($measure ? ' (measuring sizes)' : ''));
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/** The last plan plus progress and messages of the last plan/apply */
function backupSetupGet(): array
{
    $data = BACKUP_DATA_DIR;
    return [
        'ok'     => true,
        'status' => backupSetupStatus(),
        'run'    => readJson("$data/state/setup-status.json"),
        'plan'   => readJson("$data/state/setup-plan.json"),
    ];
}

/**
 * Applies the user's decisions: settings.ini keys as in the plan's P. Only
 * keys the plan knows (or per-share/-database keys of things it listed) get
 * through; setup.sh validates the values again before it writes.
 */
function backupSetupApply(mixed $decisions): array
{
    $dir = backupCheckReady();
    $data = BACKUP_DATA_DIR;
    $plan = readJson("$data/state/setup-plan.json");
    if (!$plan || !is_array($decisions) || !$decisions || array_is_list($decisions) || count($decisions) > 5000) {
        throw new Problem('setup_bad_decisions', ['detail' => $plan ? 'decisions' : 'no plan']);
    }
    $shares = array_column($plan['shares'] ?? [], 'name');
    $dbs = array_column(array_filter($plan['databases'] ?? [], fn ($d) => !empty($d['dumpable'])), 'container');
    $ncs = array_merge(...array_map(fn ($n) => $n['members'] ?? [], $plan['nextcloud'] ?? []) ?: [[]]);
    $clean = [];
    foreach ($decisions as $key => $value) {
        $key = (string) $key;
        $ok = $key === '_retire_sources' || array_key_exists($key, $plan['P'] ?? [])
            || (preg_match('/^share\|(.+)\|([a-z_]+)$/', $key, $m) && in_array($m[1], $shares, true) && in_array($m[2], BACKUP_SETUP_KEYS, true))
            || (preg_match('/^dump\|(.+)\|type$/', $key, $m) && in_array($m[1], $dbs, true))
            || (preg_match('/^nextcloud\|(.+)\|preexisting_maintenance$/', $key, $m) && in_array($m[1], $ncs, true));
        $plain = fn ($v) => is_string($v) && strlen($v) <= 500 && !preg_match('/[\x00-\x1f]/', $v);
        $valid = $plain($value) || (is_array($value) && array_is_list($value) && count($value) <= 1000 && !in_array(false, array_map($plain, $value), true));
        if (!$ok || !$valid) {
            throw new Problem('setup_bad_decisions', ['detail' => $key]);
        }
        $clean[$key] = $value;
    }
    $file = "$data/state/setup-decisions.json";
    writeAtomic($file, jsonEncode($clean), 0600, 0, 0);
    backupLaunch(["$dir/setup.sh", "--apply=$file"], ['UB_SIZE_TIMEOUT' => 0]);
    logLine('Backup: setup.sh --apply started via at (' . count($clean) . ' decisions)');
    return ['ok' => true, 'started' => backupSetupWait(), 'state' => backupScan()];
}

/** Until setup.sh reports that it runs (a moment), or gives up */
function backupSetupWait(): bool
{
    $data = BACKUP_DATA_DIR;
    $before = time() - 1;
    for ($i = 0; $i < 40; $i++) {
        usleep(250000);
        clearstatcache();
        $s = readJson("$data/state/setup-status.json");
        if ((int) ($s['started'] ?? 0) >= $before) {
            return true;
        }
    }
    return false;
}
