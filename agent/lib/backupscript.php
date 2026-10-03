<?php
declare(strict_types=1);

/*
 * The backup engine (unraid-backup), part of the office: code in backup/,
 * settings, state, logs and dumps in data/unraid-backup — the same rule as
 * UB_DATA in backup/lib/common.sh. Mr. Backup runs it (agent/desks/backup.php).
 *
 * While it runs it mounts its snapshots under <mount_root> so Kopia can read
 * them. Those must not be unmounted, deleted or renamed in the meantime.
 */

define('BACKUP_SCRIPT_DIR', OFFICE_DIR . '/backup');
define('BACKUP_DATA_DIR', OFFICE_DIR . '/data/unraid-backup');
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
