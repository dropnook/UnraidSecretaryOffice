<?php
declare(strict_types=1);

/*
 * The backup engine (unraid-backup), part of the office: code in backup/,
 * settings, state and logs in data/unraid-backup — the same rule as UB_DATA in
 * backup/lib/common.sh; the packages of apps and VMs in the backup place
 * (backupDumpsPath()). Mr. Backup runs it (agent/desks/backup.php).
 *
 * While it runs it mounts its snapshots under <mount_root> so Kopia can read
 * them. Those must not be unmounted, deleted or renamed in the meantime.
 */

define('BACKUP_SCRIPT_DIR', OFFICE_DIR . '/backup');
define('BACKUP_DATA_DIR', DATA_DIR . '/unraid-backup');
define('BACKUP_OFFICE_SHARE', 'UnraidSecretaryOffice');   // the office's share: one folder per desk (lib/common.sh UB_OFFICE_SHARE)
define('BACKUP_DESK_DIR', 'backup');
const BACKUP_STAGE_DIR = '/run/unraid-backup-stage';   // its private staging area

/**
 * Is the backup script running right now? It holds an flock on state/lock for
 * the whole run. We only look that up in /proc/locks and never take the lock
 * ourselves — a run starting at that very moment would give up otherwise.
 */
function backupScriptState(): array
{
    $state = ['found' => false, 'running' => false, 'since' => null, 'step' => null,
              'prefix' => null, 'mount_root' => null, 'keep_mounts' => false, 'dir' => null, 'settings' => []];
    $dir = BACKUP_SCRIPT_DIR;
    $data = BACKUP_DATA_DIR;
    if (!is_file("$dir/backup.sh")) {
        return $state;
    }
    $general = readCfg("$data/settings.ini", true)['general'] ?? [];
    $state['found'] = true;
    $state['dir'] = BACKUP_SCRIPT_DIR;
    $state['prefix'] = $general['snap_prefix'] ?? null;
    $state['mount_root'] = $general['mount_root'] ?? null;
    $state['keep_mounts'] = ($general['keep_mounts'] ?? 'no') === 'yes';
    $state['settings'] = $general;
    if (flockHeld("$data/state/lock")) {
        $state['running'] = true;
        $state['since'] = @filemtime("$data/state/lock") ?: null;   // "exec 9>" truncates it on every start
        $state['step'] = lastLogStep("$data/logs/latest.log");
    }
    return $state;
}

/**
 * The backup place in a share — the same rule as the engine's dumps_path(): <share>/unraid-backup,
 * in the office's share <share>/backup. Since engine 2.18 it holds the packages (apps/, vms/,
 * server/, flash/), before that one folder per run.
 */
function backupDumpsPath(string $share): ?string
{
    if ($share === '') {
        return null;
    }
    return $share === BACKUP_OFFICE_SHARE ? "/mnt/user/$share/" . BACKUP_DESK_DIR : "/mnt/user/$share/unraid-backup";
}

/** Where the backup script mounts things */
function backupScriptRoots(array $state): array
{
    return array_values(array_filter([$state['mount_root'] ?? null, BACKUP_STAGE_DIR]));
}

function inBackupRoots(string $path, array $roots): bool
{
    foreach ($roots as $root) {
        if (under($path, $root)) {
            return true;
        }
    }
    return false;
}

/**
 * Does anybody hold an flock on this file? The PID in /proc/locks is the one
 * that took the lock — it may be long gone while backup.sh keeps the inherited
 * handle. So only the entry itself counts.
 */
function flockHeld(string $file): bool
{
    $st = @stat($file);
    if (!$st) {
        return false;
    }
    $d = $st['dev'];
    $id = sprintf('%02x:%02x:%d', (($d >> 8) & 0xfff) | (($d >> 32) & ~0xfff), ($d & 0xff) | (($d >> 12) & ~0xff), $st['ino']);
    foreach (@file('/proc/locks', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\d+:\s+FLOCK\s+\S+\s+\S+\s+\d+\s+(\S+)\s/', $line, $m) && $m[1] === $id) {
            return true;
        }
    }
    return false;
}

/** Last main line of the running backup's log */
function lastLogStep(string $log): ?string
{
    $h = @fopen($log, 'r');
    if (!$h) {
        return null;
    }
    fseek($h, max(0, (int) @filesize($log) - 8192));
    $tail = (string) stream_get_contents($h);
    fclose($h);
    $step = null;
    foreach (explode("\n", $tail) as $line) {
        if (preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  (\S.*)$/', $line, $m)) {
            $step = preg_replace('/\s+/', ' ', trim($m[1]));
        }
    }
    return $step;
}

/**
 * settings.ini: [section] or [type "name"] (key "type|name"), key = value,
 * keys may repeat (lists). Values are lists only where that makes sense.
 */
function backupReadSettings(string $file): array
{
    $result = [];
    $section = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (preg_match('/^\[\s*([A-Za-z0-9_-]+)(?:\s+"(.*)")?\s*\]$/', $line, $m)) {
            $section = isset($m[2]) ? "$m[1]|$m[2]" : $m[1];
            $result[$section] ??= [];
            continue;
        }
        if ($section !== null && preg_match('/^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/', $line, $m)) {
            $result[$section][$m[1]][] = $m[2];
        }
    }
    return $result;
}

function backupSetting(array $settings, string $section, string $key, ?string $default = null): ?string
{
    $values = $settings[$section][$key] ?? [];
    return $values ? end($values) : $default;
}

/**
 * How is a path protected by the backup? For any desk that shows paths.
 *   offsite  local snapshot + Kopia      local  local snapshot (or dump) only
 *   none     not backed up               null   no backup set up (or can't tell)
 * Follows the same rules as the engine: share mode, Kopia ignore rules,
 * flash mode for /boot, and /etc/libvirt lives in libvirt.img.
 */
function backupProtection(string $path, int $depth = 0): ?string
{
    static $cache = [];
    $file = BACKUP_DATA_DIR . '/settings.ini';
    $stamp = (int) @filemtime($file);
    if (($cache['stamp'] ?? null) !== $stamp) {
        $cache = ['stamp' => $stamp, 'settings' => $stamp ? backupReadSettings($file) : []];
    }
    $s = $cache['settings'];
    if (!$s || $depth > 3) {
        return null;
    }
    $kopia = in_array(strtolower((string) backupSetting($s, 'kopia', 'enabled', 'no')), ['yes', 'ja', '1', 'true'], true);
    $path = rtrim($path, '/');

    // archives and packages lie in the backup place: protected like its share
    $place = backupDumpsPath((string) backupSetting($s, 'general', 'dumps_share', ''));
    if ($path === '/boot' || str_starts_with($path, '/boot/')) {
        return match (backupSetting($s, 'flash', 'mode', 'off')) {
            'snapshot' => $kopia ? 'offsite' : 'local',
            'tar'      => $place ? backupProtection($place, $depth + 1) : 'none',
            default    => 'none',
        };
    }
    if ($path === '/etc/libvirt' || str_starts_with($path, '/etc/libvirt/')) {
        // the engine puts every VM's configuration into its package and all of libvirt.img into server/
        // (libvirt mode tar, the default); else only the image's share counts
        if ($place && backupSetting($s, 'libvirt', 'mode', 'tar') === 'tar') {
            return backupProtection($place, $depth + 1);
        }
        $img = readCfg('/boot/config/domain.cfg')['IMAGE_FILE'] ?? null;
        return $img ? backupProtection($img, $depth + 1) : null;
    }
    // /mnt/user/<share>/…, /mnt/<pool or disk>/<share>/…, or a resolved exclusive share
    if (!preg_match('#^/mnt/([^/]+)/([^/]+)(?:/(.*))?$#', $path, $m) || in_array($m[1], ['disks', 'remotes', 'addons'], true)) {
        return str_starts_with($path, '/mnt/') ? null : 'none';
    }
    $share = $m[2];
    $rel = $m[3] ?? '';
    // a folder of an app or VM with a Kopia source of its own (engine 2.19) goes offsite there,
    // whatever its share does - as long as the share takes a snapshot to read it from
    if ($kopia && $rel !== '' && backupSetting($s, "share|$share", 'mode', 'off') !== 'off') {
        foreach ($s as $section => $keys) {
            if (preg_match('/^(app|vm)\|/', $section) && ($keys['kopia'] ?? []) && end($keys['kopia']) === 'yes') {
                foreach ($keys['folder'] ?? [] as $f) {
                    if ($f === "$share/$rel" || str_starts_with("$share/$rel", rtrim($f, '/') . '/')) {
                        return 'offsite';
                    }
                }
            }
        }
    }
    $mode = backupSetting($s, "share|$share", 'mode');
    if ($mode === null || $mode === 'off') {
        return 'none';
    }
    if ($mode === 'snapshot' || !$kopia) {
        return 'local';
    }
    foreach ($s["share|$share"]['kopia_ignore'] ?? [] as $rule) {
        $r = trim($rule, '/');
        if (str_starts_with($rule, '/') && $r !== '' && !preg_match('/[*?\[]/', $r) && ($rel === $r || str_starts_with($rel, "$r/"))) {
            return 'local';
        }
    }
    return 'offsite';
}
