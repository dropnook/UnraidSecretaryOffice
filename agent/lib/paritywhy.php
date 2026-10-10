<?php
declare(strict_types=1);

/*
 * Why a parity check runs (2026-10-09: «a common Unraid side effect nobody understands») — the night watchman
 * notices and explains it in his watch book, the team lead carries the to-do while it applies (watchmanParityFinding()).
 * Read only: never a check started, paused or cancelled, never a time-out changed. What Unraid 7.3.3 does (read on a test server,
 * the parity reason report):
 *
 *   /boot/config/forcesync   emhttpd touches it when it starts the array (syslog «shcmd (N): touch /boot/config/forcesync»)
 *                            and removes it when the array stops; rc.6 removes it too («Clean shutdown») once the md
 *                            driver stopped. Still there at the next boot: emhttpd logs «unclean shutdown detected» as it
 *                            starts, and the array's start begins a parity check — again after every stop that isn't
 *                            clean (7.3.3: a correcting one, «mdcmd (N): check correct», mdResyncCorr 1). A stop whose
 *                            time-out ran out can still end clean: rc.6 kills every process, unmounts and stops the md
 *                            driver — a program holding an XFS/btrfs disk dies with it; an imported ZFS pool keeps the
 *                            driver busy («Unclean shutdown - Cannot stop md/unraid driver»).
 *   rc.local_shutdown        (run by rc.6) asks emhttpd to stop the array and waits up to var.ini shutdownTimeout (Settings
 *                            → Disk Settings → «Shutdown time-out», empty = 90 s): «Waiting up to N seconds for graceful
 *                            shutdown...». Ran out: «Forcing shutdown...», then /usr/local/sbin/diagnostics writes
 *                            /boot/logs/<name>-diagnostics-YYYYMMDD-HHMM.zip (its logs/syslog.txt: what emhttpd tried —
 *                            «umount: … target is busy», «cannot export '<pool>': pool is busy», «Retry unmounting disk
 *                            share(s)...»; fuser's list of programs is only named, its output goes nowhere).
 *   the stop's order         rc.docker: `docker stop --time=DOCKER_TIMEOUT` for all of Unraid's containers at once (Settings →
 *                            Docker → «Docker Stop Timeout», empty = 10 s), then up to 30 s for dockerd; rc.libvirt: each VM
 *                            asked, up to domain.cfg TIMEOUT (VM Manager → «VM shutdown time-out», empty = 60 s), then
 *                            «Forced shutting down VM: <name>»; then the disks are unmounted.
 *   /boot/logs/syslog-previous   rc.6 copies the syslog to /boot/logs/syslog at a shutdown (Settings → Syslog Server «Copy
 *                            syslog to boot drive on shutdown», rsyslog.cfg syslog_shutdown "" = Yes, the default; or the
 *                            mirror, syslog_flash); rc.M renames it at the next boot. There after a boot: the shutdown went
 *                            through rc.6. Not there while the copy is on (and no mirror): no rc.6 — a crash, power loss, a
 *                            hard reset.
 *   /boot/config/parity-checks.log   a line per finished operation: «2026 Oct  9 01:11:33|17|<speed>|0|0|check P|<size>».
 *   var.ini                  mdResyncAction («check P», «recon P», «clear»), mdResyncPos (> 0 while one runs or waits paused),
 *                            mdResync (0 = paused), sbSynced (when the last one began — a resume keeps it), sbSynced2.
 *   kernel lines             «mdcmd (N): start STOPPED» (the array's start), «mdcmd (N): check …», «md: recovery thread: check P».
 *   /boot/config/plugins/dynamix/parity-check.cron   Unraid's own schedule (Settings → Scheduler → Parity Check):
 *                            «<m> <h> <dom> <mon> <dow> … /usr/local/sbin/mdcmd check NOCORRECT …»; a cron start and a click
 *                            on ⟦Check⟧ write the same kernel line — Unraid never says who started one.
 *   Parity Check Tuning      itimpi's plugin, /boot/config/plugins/parity.check.tuning/: its .progress file (type|date|time|…:
 *                            SCHEDULED, MANUAL, AUTOMATIC, RESUME (RESTART) …), its .restart file (the array stopped during a
 *                            check — it resumes it at the next start), syslog tag «Parity Check Tuning» («restart to be
 *                            attempted», «Unclean shutdown detected»).
 *
 * Pure functions take what was read (the tests hand in fixtures); paritywhyLook() reads — RAM and the flash only, no disk
 * wakes up; the boot's syslog only while this boot's verdict or a new check waits for it.
 */

const PARITYWHY_DIAG_RE      = '/^[A-Za-z0-9._-]{1,64}-diagnostics-\d{8}-\d{4}\.zip$/D';
const PARITYWHY_DIAG_BEFORE  = 900;         // diagnostics written this long before the boot (and after the boot before): the shutdown's
const PARITYWHY_AT_START     = 180;         // a check that began this close to the array's start began with it
const PARITYWHY_SETTLE       = 600;         // no check and no unclean line this long after the array's start: the stop was clean
const PARITYWHY_READ_MAX     = 4 * 1024 * 1024;     // per syslog file read for the verdict (its start matters)
const PARITYWHY_ZIP_MAX      = 64 * 1024 * 1024;    // a diagnostics zip larger than this is not opened
const PARITYWHY_LIST_MAX     = 6;           // names kept per kind of blocker
const PARITYWHY_MARGIN       = 30;          // seconds on top of the stops, for unmounting (the advice's margin)
const PARITYWHY_HISTORY      = 10;          // boots remembered (streak of unclean ones)
const PARITYWHY_PCT_DIR      = '/boot/config/plugins/parity.check.tuning';
// Unraid's and the plugin's own lines (Unraid 7.3.3, a test server 2026-10-09)
const PARITYWHY_UNCLEAN_RE   = '/\semhttpd(?:\[\d+\])?:\s+unclean shutdown detected\b/';
const PARITYWHY_START_RE     = '/\skernel:\s+mdcmd \(\d+\): start\b/';
const PARITYWHY_PCT_TAG      = '/\sParity (?:Check Tuning|Problem Assistant)(?:\[\d+\])?:\s/';
const PARITYWHY_PCT_RESTART  = '/restart to be attempted|Array operation restarted/i';
const PARITYWHY_PCT_UNCLEAN  = '/Unclean shutdown detected/i';
const PARITYWHY_FORCING_RE   = '/\src\.local_shutdown(?:\[\d+\])?:\s+Forcing shutdown/';
const PARITYWHY_WAITING_RE   = '/\src\.local_shutdown(?:\[\d+\])?:\s+Waiting up to (\d{1,6}) seconds for graceful shutdown/';
const PARITYWHY_BUSY_RES     = [
    '#umount:\s+(/mnt/[^\s:\'"]{1,200}):\s+target is busy#',                         // XFS, btrfs
    '#cannot unmount \'(/mnt/[^\s\'"]{1,200})\':\s+(?:pool or dataset|target) is busy#',   // a ZFS dataset
];
const PARITYWHY_POOL_BUSY_RE = '#cannot export \'([A-Za-z0-9_.-]{1,64})\':\s+pool is busy#';
const PARITYWHY_RETRY_RE     = '/\semhttpd(?:\[\d+\])?:\s+Retry unmounting (?:disk )?share/';
const PARITYWHY_VM_FORCED_RE = '/\src\.libvirt(?:\[\d+\])?:\s+Forced shutting down VM:\s+(\S.{0,63})$/';
const PARITYWHY_VM_WAIT_RE   = '/\src\.libvirt(?:\[\d+\])?:\s+Shutting down VM:\s+(\S.{0,63})$/';
const PARITYWHY_CT_STOP_RE   = '/\src\.docker(?:\[\d+\])?:\s+Stopping containers\.\.\./';
const PARITYWHY_CT_DONE_RE   = '/\src\.docker(?:\[\d+\])?:\s+Unraid managed containers stopped\./';
const PARITYWHY_DIE_RE       = '/\src\.(?:docker|libvirt)(?:\[\d+\])?:\s+(\S{1,40}) will not die/';

/** var.ini as far as the array's resync goes */
function paritywhyVar(array $var): array
{
    $int = fn (string $k): int => preg_match('/^-?\d{1,20}$/D', trim((string) ($var[$k] ?? ''))) ? (int) $var[$k] : 0;
    $pos = $int('mdResyncPos');
    $action = trim((string) ($var['mdResyncAction'] ?? ''));
    return [
        'started' => in_array((string) ($var['fsState'] ?? ''), ['Started', 'Formatting', 'Clearing'], true),
        'action'  => preg_match('/^[a-z][a-z0-9 ]{0,30}$/iD', $action) ? $action : '',
        'pos'     => $pos,
        'size'    => $int('mdResyncSize'),
        'running' => $pos > 0,
        'paused'  => $pos > 0 && isset($var['mdResync']) && $int('mdResync') === 0,
        'correct' => $int('mdResyncCorr') === 1,
        'synced'  => $int('sbSynced'),
        'synced2' => $int('sbSynced2'),
        'exit'    => $int('sbSyncExit'),
        'errors'  => $int('sbSyncErrs'),
    ];
}

/** What kind of array operation: a check (reads, compares), a rebuild/sync (builds parity or a disk), clearing a new disk */
function paritywhyKind(string $action): string
{
    $a = strtolower(trim($action));
    return match (true) {
        str_starts_with($a, 'check') => 'check',
        str_starts_with($a, 'recon') => 'rebuild',
        str_starts_with($a, 'clear') => 'clear',
        default                      => $a === '' ? 'none' : 'other',
    };
}

/**
 * /boot/config/parity-checks.log: end time|seconds|speed|exit|errors|action|size (older Unraid: without action and size).
 * @return list<array{end: int, start: int, seconds: int, exit: int, errors: int, action: string}>
 */
function paritywhyHistory(string $text): array
{
    $out = [];
    foreach (array_slice(explode("\n", $text), -200) as $line) {
        $f = explode('|', trim($line));
        if (count($f) < 5 || !preg_match('/^\d{4} [A-Z][a-z]{2} {1,2}\d{1,2} \d\d:\d\d:\d\d$/D', $f[0]) || !preg_match('/^\d{1,9}$/D', $f[1])) {
            continue;
        }
        $dt = DateTime::createFromFormat('!Y M j H:i:s', (string) preg_replace('/\s+/', ' ', $f[0]));     // local time, like Unraid writes it
        $err = DateTime::getLastErrors();
        if ($dt === false || ($err && ($err['warning_count'] || $err['error_count']))) {
            continue;
        }
        $end = $dt->getTimestamp();
        $out[] = ['end' => $end, 'start' => $end - (int) $f[1], 'seconds' => (int) $f[1], 'exit' => (int) $f[3], 'errors' => (int) $f[4],
                  'action' => isset($f[5]) && preg_match('/^[a-z][a-z0-9 ]{0,30}$/iD', trim($f[5])) ? trim($f[5]) : ''];
    }
    return $out;
}

/**
 * Parity Check Tuning's records (its plugin installed): the .restart file and the .progress file's lines (type and time).
 * @return array{installed: bool, restart: bool, progress: list<array{type: string, t: int}>}
 */
function paritywhyPct(string $dir, bool $installed): array
{
    $out = ['installed' => $installed, 'restart' => false, 'progress' => []];
    if (!$installed || !is_dir($dir) || is_link($dir)) {
        return $out;
    }
    $out['restart'] = is_file("$dir/parity.check.tuning.restart");
    $file = "$dir/parity.check.tuning.progress";
    if (is_file($file) && !is_link($file) && (int) @filesize($file) < 1024 * 1024) {
        foreach (array_slice(explode("\n", (string) @file_get_contents($file)), -50) as $line) {
            $f = explode('|', trim($line));
            // type|date|time|…: a time that is an epoch where the plugin writes one, else its date (Y M d H:i:s)
            if (count($f) >= 3 && preg_match('/^[A-Z][A-Z ()]{2,30}$/D', $f[0]) && $f[0] !== 'TYPE') {
                $t = preg_match('/^\d{9,11}$/D', $f[2]) ? (int) $f[2] : (preg_match('/^\d{9,11}$/D', $f[1]) ? (int) $f[1] : (int) (strtotime($f[1]) ?: 0));
                $out['progress'][] = ['type' => $f[0], 't' => $t];
            }
        }
    }
    return $out;
}

/**
 * This boot's syslog, for the verdict: Unraid's «unclean shutdown detected», the array's first start, the plugin's restart
 * and its own unclean line. @return array{unclean: ?int, start: ?int, pct_restart: ?int, pct_unclean: ?int}
 */
function paritywhyBootLog(string $text, int $now): array
{
    $out = ['unclean' => null, 'start' => null, 'pct_restart' => null, 'pct_unclean' => null];
    foreach (explode("\n", $text) as $line) {
        if ($line === '' || str_contains($line, 'uso-watchman')) {
            continue;
        }
        if (preg_match(PARITYWHY_PCT_TAG, $line)) {
            if (preg_match(PARITYWHY_PCT_RESTART, $line)) {
                $out['pct_restart'] ??= watchmanLineTime($line, $now);
            } elseif (preg_match(PARITYWHY_PCT_UNCLEAN, $line)) {
                $out['pct_unclean'] ??= watchmanLineTime($line, $now) ?? $now;
            }
        } elseif (preg_match(PARITYWHY_UNCLEAN_RE, $line)) {
            $out['unclean'] ??= watchmanLineTime($line, $now) ?? $now;
        } elseif (preg_match(PARITYWHY_START_RE, $line)) {
            $out['start'] ??= watchmanLineTime($line, $now);
        }
    }
    return $out;
}

/**
 * What held the array at the stop, from a syslog of then (the diagnostics' logs/syslog.txt, or syslog-previous): whether
 * the time-out ran out (and which it was), the mounts and pools still busy, the retries, VMs switched off hard (or still
 * being waited for), how long Unraid's containers took to stop, a service that would not die.
 * @return array{forced: bool, timeout: ?int, busy: list<string>, retries: int, vms: list<string>, vms_waited: list<string>,
 *               containers_s: ?int, stuck: list<string>}
 */
function paritywhyBlockers(string $syslog, int $now): array
{
    $out = ['forced' => false, 'timeout' => null, 'busy' => [], 'retries' => 0, 'vms' => [], 'vms_waited' => [], 'containers_s' => null, 'stuck' => []];
    $add = function (string $k, string $v) use (&$out): void {
        $v = trim($v);
        if ($v !== '' && strlen($v) <= 200 && !in_array($v, $out[$k], true) && count($out[$k]) < PARITYWHY_LIST_MAX) {
            $out[$k][] = $v;
        }
    };
    // only the last stop counts: the lines after its last «Waiting up to …» (or the whole file when there is none)
    $lines = explode("\n", $syslog);
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (preg_match(PARITYWHY_WAITING_RE, $lines[$i], $m)) {
            $out['timeout'] = (int) $m[1];
            break;
        }
    }
    $ctStop = null;
    foreach ($lines as $line) {
        if (preg_match(PARITYWHY_FORCING_RE, $line)) {
            $out['forced'] = true;
        } elseif (preg_match(PARITYWHY_RETRY_RE, $line)) {
            $out['retries']++;
        } elseif (preg_match(PARITYWHY_POOL_BUSY_RE, $line, $m)) {
            $add('busy', '/mnt/' . $m[1]);
        } elseif (preg_match(PARITYWHY_VM_FORCED_RE, $line, $m)) {
            $add('vms', $m[1]);
        } elseif (preg_match(PARITYWHY_VM_WAIT_RE, $line, $m)) {
            $add('vms_waited', $m[1]);
        } elseif (preg_match(PARITYWHY_CT_STOP_RE, $line)) {
            $ctStop = watchmanLineTime($line, $now);
        } elseif (preg_match(PARITYWHY_CT_DONE_RE, $line) && $ctStop !== null && ($t = watchmanLineTime($line, $now)) !== null) {
            $out['containers_s'] = max(0, $t - $ctStop);
        } elseif (preg_match(PARITYWHY_DIE_RE, $line, $m)) {
            $add('stuck', $m[1]);
        } else {
            foreach (PARITYWHY_BUSY_RES as $re) {
                if (preg_match($re, $line, $m)) {
                    $add('busy', $m[1]);
                }
            }
        }
    }
    // a dataset under a pool already named says no more than the pool; the pool's own dataset path stays
    $pools = array_filter($out['busy'], fn ($b) => substr_count($b, '/') === 2);
    $out['busy'] = array_values(array_filter($out['busy'], function ($b) use ($pools) {
        foreach ($pools as $p) {
            if (str_starts_with($b, "$p/")) {
                return false;
            }
        }
        return true;
    }));
    return $out;
}

/**
 * The diagnostics rc.local_shutdown wrote at the shutdown before this boot: the newest <name>-diagnostics-*.zip in /boot/logs
 * whose time lies within PARITYWHY_DIAG_BEFORE before $btime and after $after (the boot before, when known — a stop that
 * ran out of time but still ended clean leaves one too). @return array{name: string, mtime: int, path: string, size: int}|null
 */
function paritywhyDiag(string $dir, int $btime, ?int $after = null): ?array
{
    $best = null;
    foreach (@scandir($dir) ?: [] as $f) {
        if (!preg_match(PARITYWHY_DIAG_RE, $f)) {
            continue;
        }
        $st = @lstat("$dir/$f");
        if (!$st || ($st['mode'] & 0170000) !== 0100000) {
            continue;
        }
        $t = (int) $st['mtime'];
        if ($t <= $btime + 60 && $t >= $btime - PARITYWHY_DIAG_BEFORE && ($after === null || $t > $after) && ($best === null || $t > $best['mtime'])) {
            $best = ['name' => $f, 'mtime' => $t, 'path' => "$dir/$f", 'size' => (int) $st['size']];
        }
    }
    return $best;
}

/** The syslog part of a diagnostics zip (its logs/syslog.txt; read in memory, never extracted) — '' when it can't be read */
function paritywhyDiagSyslog(array $diag): string
{
    if (($diag['size'] ?? 0) > PARITYWHY_ZIP_MAX || !class_exists('ZipArchive')) {
        return '';
    }
    $z = new ZipArchive();
    if ($z->open($diag['path'], ZipArchive::RDONLY) !== true) {
        return '';
    }
    $text = '';
    for ($i = 0; $i < min($z->numFiles, 2000); $i++) {
        $name = (string) $z->getNameIndex($i);
        if (preg_match('#^[^/]{1,120}/logs/syslog\.txt$#D', $name)) {
            $st = $z->statIndex($i);
            if (is_array($st) && (int) $st['size'] <= 4 * PARITYWHY_READ_MAX) {
                $text = (string) $z->getFromIndex($i);
            }
            break;
        }
    }
    $z->close();
    return substr($text, -PARITYWHY_READ_MAX);
}

/** A file of the flash read whole when it is a plain small file, else '' */
function paritywhyRead(string $file, int $max = PARITYWHY_READ_MAX, bool $tail = false): string
{
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file)) {
        return '';
    }
    $size = (int) @filesize($file);
    return (string) @file_get_contents($file, false, null, $tail ? max(0, $size - $max) : 0, $max);
}

/**
 * The times that decide whether a stop fits: Disk Settings «Shutdown time-out» (var.ini shutdownTimeout, empty = 90 s),
 * VM Manager «VM shutdown time-out» (domain.cfg TIMEOUT, empty = 60 s; waVmStop()) while VMs are on, Docker's «Docker Stop
 * Timeout» (docker.cfg DOCKER_TIMEOUT, empty = 10 s; all of Unraid's containers at once) while Docker is on — and what the
 * disk time-out should be at least: Docker + VMs + PARITYWHY_MARGIN (dockerd itself, up to 30 s more, mostly goes in a
 * second or two; the margin covers it and the unmounting).
 * @return array{disk: int, vm: int, docker: int, need: int, fits: bool}
 */
function paritywhyTimes(array $var, array $domain, array $docker): array
{
    $stop = waVmStop($domain, $var);
    $vmOn = ($domain['SERVICE'] ?? 'disable') === 'enable';
    $dockerOn = ($docker['DOCKER_ENABLED'] ?? 'no') === 'yes';
    $dt = trim((string) ($docker['DOCKER_TIMEOUT'] ?? ''));
    $ct = $dockerOn ? (preg_match('/^\d{1,5}$/D', $dt) && (int) $dt > 0 ? (int) $dt : 10) : 0;
    $vm = $vmOn ? $stop['timeout'] : 0;
    $need = $ct + $vm + PARITYWHY_MARGIN;
    return ['disk' => $stop['disk_timeout'], 'vm' => $vm, 'docker' => $ct, 'need' => $need, 'fits' => $stop['disk_timeout'] >= $need];
}

/** Does Unraid's own schedule (parity-check.cron) name the minute $t (or the one before: cron starts within it)? */
function paritywhyScheduled(string $cronFile, int $t): bool
{
    foreach (explode("\n", $cronFile) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = preg_split('/\s+/', $line, 6);
        if (count($parts) < 6 || !preg_match('#mdcmd\s+check\s+(?:NO)?CORRECT|parity_control\s+resume#', $parts[5])) {
            continue;
        }
        $f = cronFields(implode(' ', array_slice($parts, 0, 5)));
        if ($f && (cronMatches($f, $t) || cronMatches($f, $t - 60))) {
            return true;
        }
    }
    return false;
}

/**
 * Why the operation that began at $f['start'] runs — from the evidence only:
 *   rebuild   a disk or the parity being built, or a new disk cleared (not a check at all)
 *   tuning    Parity Check Tuning resumed one it kept across a stop or reboot
 *   unclean   it began with the array's first start after Unraid found the stop before unclean
 *   schedule  Unraid's schedule names that minute (or the plugin marked it so)
 *   manual    the plugin saw it started by hand (Unraid itself never says who started one)
 *   unknown   none of these
 */
function paritywhyReason(array $f): string
{
    $start = (int) $f['start'];
    $kind = paritywhyKind((string) ($f['action'] ?? ''));
    if ($kind === 'rebuild' || $kind === 'clear') {
        return 'rebuild';
    }
    $near = fn (?int $t, int $span): bool => $t !== null && abs($t - $start) <= $span;
    $pctNear = function (string $type) use ($f, $near): bool {
        foreach ((array) ($f['pct']['progress'] ?? []) as $p) {
            if ($p['type'] === $type && $near($p['t'], 300)) {
                return true;
            }
        }
        return false;
    };
    if ($near($f['log']['pct_restart'] ?? null, 600) || $pctNear('RESUME (RESTART)')) {
        return 'tuning';
    }
    $arrayStart = $f['array_start'] ?? null;
    $atStart = $arrayStart !== null && $start >= $arrayStart - 60 && $start - $arrayStart <= PARITYWHY_AT_START;
    if ($atStart && (!empty($f['unclean']) || $pctNear('AUTOMATIC'))) {
        return 'unclean';
    }
    if ($pctNear('SCHEDULED') || paritywhyScheduled((string) ($f['cron'] ?? ''), $start)) {
        return 'schedule';
    }
    if ($pctNear('MANUAL')) {
        return 'manual';
    }
    return 'unknown';
}

/**
 * How the stop before this boot ended, from what Unraid left: `timeout` — rc.local_shutdown's time-out ran out (its
 * diagnostics, or «Forcing shutdown» in the kept syslog); `crash` — the copy at shutdown is on (no mirror) and there is no
 * kept syslog of then: rc.6 never ran (a crash, power loss, a hard reset); `late` — rc.6 ran and the array stopped in
 * time by its words, yet the md driver didn't stop after it; `unknown` — nothing kept that says.
 */
function paritywhyStop(?array $diag, string $previous, bool $copyOn, bool $mirror, int $now): array
{
    $from = $diag !== null ? 'diag' : ($previous !== '' ? 'previous' : 'none');
    $text = $diag !== null ? (string) ($diag['syslog'] ?? '') : $previous;
    $b = $text !== '' ? paritywhyBlockers($text, $now) : null;
    if ($diag !== null || ($b !== null && $b['forced'])) {
        $stop = 'timeout';
    } elseif ($previous === '' && $copyOn && !$mirror) {
        $stop = 'crash';
    } elseif ($previous !== '') {
        $stop = 'late';
    } else {
        $stop = 'unknown';
    }
    return ['stop' => $stop, 'from' => $from, 'blockers' => $b];
}

/**
 * What the office says held the array, in names only (no words of a language): the busy mounts and pools, the VMs
 * switched off hard, services that would not die — «/mnt/disk1, VM «Win11»». '' when nothing is known.
 */
function paritywhyHeld(?array $b): string
{
    if ($b === null) {
        return '';
    }
    $out = array_merge($b['busy'] ?? [], array_map(fn ($v) => "VM «{$v}»", $b['vms'] ?? []), array_map(fn ($s) => $s, $b['stuck'] ?? []));
    return implode(', ', array_slice($out, 0, 8));
}

// ===================================================================== the night watchman's look, verdict and words

/**
 * His round's look (outside the book's lock): var.ini each round; the rest only while this boot's verdict is still to
 * be made or a new operation began — this boot's syslog (the rotated part first), the flash's parity history, Unraid's
 * schedule, Parity Check Tuning's records, and after an unclean stop what Unraid left of it. Null: not looked at (the
 * night shift has no parity paths) or the array isn't started.
 */
function paritywhyLook(array $paths, ?array $prev, string $boot, ?int $btime, int $now): ?array
{
    if (!isset($paths['parity_log'], $paths['var_ini'])) {
        return null;
    }
    $var = paritywhyVar(readCfg((string) $paths['var_ini']));
    if (!$var['started']) {
        return null;
    }
    $prev = (array) $prev;
    $decided = ($prev['verdict']['boot'] ?? null) === $boot && $boot !== '';
    $known = array_key_exists('synced', $prev) ? (int) $prev['synced'] : null;
    $newOp = $var['synced'] > 0 && $var['synced'] !== $known;
    $look = ['var' => $var, 'boot' => $boot, 'btime' => $btime, 'quiet' => $decided && !$newOp];
    if ($look['quiet']) {
        return $look;
    }
    $syslog = (string) ($paths['syslog'] ?? '');
    $text = '';
    $old = "$syslog.1";
    clearstatcache(true, $old);
    if ($syslog !== '' && is_file($old) && !is_link($old) && ($btime === null || (int) @filemtime($old) >= $btime)) {
        $text = paritywhyRead($old);
    }
    $text .= "\n" . ($syslog !== '' ? paritywhyRead($syslog) : '');
    $log = paritywhyBootLog($text, $now);
    $start = $log['start'];
    if ($start === null && isset($paths['array_events'])) {
        foreach (watchmanArrayEvents((string) $paths['array_events']) as [$t, $what]) {
            if ($what === 'start' && ($btime === null || $t >= $btime)) {
                $start = $t;
                break;
            }
        }
    }
    $look += [
        'log'         => $log,
        'array_start' => $start,
        'history'     => paritywhyHistory(paritywhyRead((string) $paths['parity_log'], 65536, true)),
        'cron'        => paritywhyRead((string) ($paths['parity_cron'] ?? ''), 16384),
        'pct'         => paritywhyPct((string) ($paths['pct_dir'] ?? PARITYWHY_PCT_DIR), isset($paths['plugins'])
                             && is_file((string) $paths['plugins'] . '/parity.check.tuning.plg')),
        'unclean'     => null,
    ];
    // the verdict about the stop before this boot: Unraid's line (or the plugin's), or an operation that began with the
    // array's first start (the line rotated away); clean once the array runs PARITYWHY_SETTLE without either
    $withArray = $start !== null && $var['synced'] >= $start - 60 && $var['synced'] - $start <= PARITYWHY_AT_START
        && paritywhyKind($var['action']) === 'check' && ($log['pct_restart'] === null || abs($log['pct_restart'] - $var['synced']) > 600);
    if (!$decided) {
        if ($log['unclean'] !== null || $log['pct_unclean'] !== null || $withArray) {
            $look['unclean'] = true;
            $look['evidence'] = $log['unclean'] !== null ? 'unraid' : ($log['pct_unclean'] !== null ? 'tuning' : 'with_array');
        } elseif ($now - ($start ?? $btime ?? $now) >= PARITYWHY_SETTLE) {
            $look['unclean'] = false;
        }
    }
    if ($look['unclean'] === true) {
        $logs = (string) ($paths['boot_logs'] ?? '/boot/logs');
        $before = isset($prev['verdict']['btime']) && ($prev['verdict']['boot'] ?? null) !== $boot ? (int) $prev['verdict']['btime'] : null;
        $diag = $btime !== null ? paritywhyDiag($logs, $btime, $before) : null;
        if ($diag !== null) {
            $diag['syslog'] = paritywhyDiagSyslog($diag);
        }
        $prevFile = "$logs/syslog-previous";
        clearstatcache(true, $prevFile);
        $previous = is_file($prevFile) && !is_link($prevFile) && ($btime === null || (int) @filemtime($prevFile) <= $btime + 60)
            ? paritywhyRead($prevFile, PARITYWHY_READ_MAX, true) : '';
        $rs = readCfg((string) ($paths['rsyslog_cfg'] ?? '/boot/config/rsyslog.cfg'));
        $copyOn = ($rs['syslog_shutdown'] ?? '') === '';
        $mirror = ($rs['syslog_flash'] ?? '') !== '';
        $look['stop'] = paritywhyStop($diag, $previous, $copyOn, $mirror, $now);
        $look['stop']['diag'] = $diag['name'] ?? null;
        $look['kept'] = $copyOn || $mirror || trim((string) ($rs['remote_server'] ?? '')) !== '';
        $look['times'] = paritywhyTimes(readCfg((string) $paths['var_ini']), readCfg((string) ($paths['domain_cfg'] ?? '/boot/config/domain.cfg')),
            readCfg((string) ($paths['docker_cfg'] ?? '/boot/config/docker.cfg')));
    }
    return $look;
}

/**
 * In the book's lock: this boot's verdict (once), an entry for an operation that began since the last look (once per
 * start, noted by himself — a plain line with its reason), the team lead's to-do (on after an unclean stop, gone once a
 * later stop was clean: a clean boot, or the array stopped and started again), and the end of an operation written into
 * its entry. $fresh: his first round after hiring — remembered only. $tell(array $verdict): the notification.
 * @return list<string>  the kinds written
 */
function paritywhyCompare(array &$book, array &$st, ?array $look, array $logins, int $now, bool $fresh, ?string $arrayEvents, ?callable $tell = null): array
{
    $p = is_array($st['parity'] ?? null) ? $st['parity'] : [];
    $added = [];
    // the array stopped and started again in the boot of the unclean stop's verdict, after it: that stop was clean (the event
    // scripts' lines lie in RAM — a start of another boot is that boot's verdict's)
    if (is_array($p['todo'] ?? null) && $arrayEvents !== null && $look !== null && ($p['todo']['boot'] ?? null) === $look['boot']) {
        foreach (watchmanArrayEvents($arrayEvents) as [$t, $what]) {
            if ($what === 'start' && $t > (int) ($p['todo']['array_start'] ?? $p['todo']['t'] ?? $now) + 60 && $t > (int) ($p['todo']['t'] ?? 0)) {
                $p['todo'] = null;
                $p['cleared'] = ['t' => $t, 'by' => 'array'];
                $p['history'][] = ['boot' => (string) ($p['verdict']['boot'] ?? '') . '#array', 'unclean' => false, 't' => $t];    // ends a streak
                $p['history'] = array_slice((array) $p['history'], -PARITYWHY_HISTORY);
                break;
            }
        }
    }
    if ($look === null) {
        $st['parity'] = $p ?: null;
        return [];
    }
    $var = $look['var'];
    $boot = (string) $look['boot'];
    // the end of the last operation into its entry (parity-checks.log's line is the flash's; var.ini says it as well)
    if ($var['synced'] > 0 && $var['synced2'] >= $var['synced'] && !$var['running']) {
        foreach ($book as &$e) {
            if (($e['key'] ?? '') === 'parity_check:' . $var['synced'] && !isset($e['p']['end'])) {
                $e['p']['end'] = $var['synced2'];
                $e['p']['exit'] = $var['exit'];
                $e['p']['errors'] = $var['errors'];
                $e['last'] = max((int) $e['last'], $var['synced2']);
            }
        }
        unset($e);
    }
    if (!empty($look['quiet'])) {
        $st['parity'] = $p;
        return [];
    }
    // this boot's verdict
    if (($look['unclean'] ?? null) !== null && ($p['verdict']['boot'] ?? null) !== $boot) {
        $hist = array_values(array_filter((array) ($p['history'] ?? []), fn ($h) => is_array($h) && ($h['boot'] ?? null) !== $boot));
        $hist[] = ['boot' => $boot, 'unclean' => (bool) $look['unclean'], 't' => (int) ($look['btime'] ?? $now)];
        $hist = array_slice($hist, -PARITYWHY_HISTORY);
        $streak = 0;
        for ($i = count($hist) - 1; $i >= 0 && $hist[$i]['unclean']; $i--) {
            $streak++;
        }
        $v = ['boot' => $boot, 't' => $now, 'unclean' => (bool) $look['unclean'], 'btime' => $look['btime'], 'array_start' => $look['array_start'] ?? null];
        if ($look['unclean'] && $fresh) {
            $v['unclean_seen'] = true;          // his first round after hiring: remembered, no to-do, nobody told
        } elseif ($look['unclean']) {
            $s = (array) ($look['stop'] ?? []);
            $b = is_array($s['blockers'] ?? null) ? $s['blockers'] : null;
            $v += ['evidence' => (string) ($look['evidence'] ?? ''), 'stop' => (string) ($s['stop'] ?? 'unknown'), 'from' => (string) ($s['from'] ?? 'none'),
                   'diag' => $s['diag'] ?? null, 'kept' => !empty($look['kept']), 'streak' => $streak, 'times' => $look['times'] ?? null,
                   'timeout' => (int) ($b['timeout'] ?? ($look['times']['disk'] ?? 90)), 'held' => paritywhyHeld($b),
                   'busy' => (array) ($b['busy'] ?? []), 'vms' => (array) ($b['vms'] ?? []), 'containers_s' => $b['containers_s'] ?? null,
                   'retries' => (int) ($b['retries'] ?? 0)];
            $p['todo'] = $v;
            $p['cleared'] = null;
            if (!$fresh && $tell !== null) {
                $p['told'] = $tell($v) ? $now : false;
            }
        } elseif (is_array($p['todo'] ?? null)) {
            $p['todo'] = null;
            $p['cleared'] = ['t' => $now, 'by' => 'boot'];
        }
        $p['verdict'] = $v;
        $p['history'] = $hist;
    }
    // an operation that began since the last look: one plain line with its reason
    $synced = $var['synced'];
    $known = array_key_exists('synced', $p) ? (int) $p['synced'] : null;
    if ($synced > 0 && $synced !== $known) {
        $key = "parity_check:$synced";
        $there = (bool) array_filter($book, fn ($e) => ($e['key'] ?? '') === $key);
        $ours = !$fresh && !$there && ($known !== null || ($look['btime'] !== null && $synced >= (int) $look['btime'] - 60));
        if ($ours) {
            $reason = paritywhyReason(['start' => $synced, 'action' => $var['action'], 'log' => $look['log'] ?? [], 'pct' => $look['pct'] ?? [],
                'array_start' => $look['array_start'] ?? null, 'cron' => $look['cron'] ?? '',
                'unclean' => ($p['verdict']['boot'] ?? null) === $boot && !empty($p['verdict']['unclean'])]);
            $ep = ['reason' => $reason, 'action' => $var['action'], 'correct' => $var['correct']];
            if ($reason === 'unclean' && is_array($p['verdict'] ?? null)) {
                foreach (['stop', 'from', 'diag', 'kept', 'streak', 'timeout', 'held', 'busy', 'vms', 'evidence'] as $k) {
                    $ep[$k] = $p['verdict'][$k] ?? null;
                }
            } elseif ($reason === 'unknown' || $reason === 'manual') {
                $ep['logins'] = watchmanLoginsAround(['logins' => $logins], $synced);
            }
            $e = watchmanEntry('parity_check', $key, $synced, $ep);
            $e['noted'] = $now;
            $e['by'] = 'parity';
            if (!$var['running'] && $var['synced2'] >= $synced) {
                $e['p'] += ['end' => $var['synced2'], 'exit' => $var['exit'], 'errors' => $var['errors']];
            }
            $book[] = $e;
            $added[] = 'parity_check';
        }
        $p['synced'] = $synced;
    } elseif ($known === null) {
        $p['synced'] = $synced;
    }
    $st['parity'] = $p;
    return $added;
}

/** An entry's reason in $lang (notifications, the SIEM line): the page writes it itself (desk.js parityWhy()) */
function paritywhyWhy(array $p, string $lang): string
{
    $reason = (string) ($p['reason'] ?? 'unknown');
    $t = fn (string $k, array $a = []) => officeNotifyText('watchman', $k, $a, $lang);
    if ($reason !== 'unclean') {
        $params = ['action' => (string) ($p['action'] ?? '')];
        if ($reason === 'unknown' || $reason === 'manual') {
            $params['who'] = watchmanArrayWho((array) ($p['logins'] ?? []));
        }
        return $t('parity.why.' . (in_array($reason, ['schedule', 'tuning', 'manual', 'rebuild'], true) ? $reason : 'unknown'), $params);
    }
    $stop = in_array($p['stop'] ?? '', ['timeout', 'late', 'crash'], true) ? $p['stop'] : 'unknown';
    $out = $t("parity.why.unclean_$stop", ['timeout' => (int) ($p['timeout'] ?? 90)]);
    if ($stop === 'timeout') {
        $out .= ($p['held'] ?? '') !== '' ? $t('parity.held', ['held' => (string) $p['held']]) : $t('parity.held_none');
    }
    if (empty($p['kept']) && in_array($stop, ['timeout', 'unknown'], true) && ($p['from'] ?? 'none') !== 'previous' && ($p['held'] ?? '') === '') {
        $out .= $t('parity.keep_syslog');
    }
    if ((int) ($p['streak'] ?? 0) >= 2) {
        $out .= $t('parity.repeat', ['n' => (int) $p['streak']]);
    }
    return $out;
}

/**
 * The notification after an unclean stop (normal: nothing is at risk, it explains) — in the readable layout: a headline,
 * a blank line, sections with an upper-case title and one item per line. Its own keys (parity.notify.*).
 */
function paritywhyNotify(array $v, string $lang): bool
{
    $t = fn (string $k, array $a = []) => officeNotifyText('watchman', $k, $a, $lang);
    $n = max(1, (int) ($v['streak'] ?? 1));
    $stop = in_array($v['stop'] ?? '', ['timeout', 'late', 'crash'], true) ? $v['stop'] : 'unknown';
    $times = (array) ($v['times'] ?? []) + ['disk' => 90, 'vm' => 0, 'docker' => 0, 'need' => 0];
    $lines = [$t('parity.notify.head', ['n' => $n]), ''];
    $lines[] = $t('parity.notify.what_title');
    $lines[] = '  ' . $t("parity.notify.what_$stop", ['timeout' => (int) ($v['timeout'] ?? $times['disk'])]);
    if ($n >= 2) {
        $lines[] = '  ' . $t('parity.notify.repeat', ['n' => $n]);
    }
    if ($stop === 'timeout' || $stop === 'late') {
        $lines[] = '';
        $lines[] = $t('parity.notify.held_title');
        $held = array_merge(array_map(fn ($b) => $t('parity.notify.held_busy', ['path' => $b]), (array) ($v['busy'] ?? [])), array_map(fn ($vm) => $t('parity.notify.held_vm', ['name' => $vm, 'timeout' => $times['vm']]), (array) ($v['vms'] ?? [])));
        if (isset($v['containers_s']) && $v['containers_s'] !== null && $times['docker'] > 0 && $v['containers_s'] >= $times['docker']) {
            $held[] = $t('parity.notify.held_containers', ['s' => (int) $v['containers_s']]);
        }
        foreach ($held ?: [$t(!empty($v['kept']) || ($v['from'] ?? 'none') !== 'none' ? 'parity.notify.held_none' : 'parity.notify.held_unseen')] as $h) {
            $lines[] = '  ' . $h;
        }
    }
    $lines[] = '';
    $lines[] = $t('parity.notify.fix_title');
    if ($stop === 'crash') {
        $lines[] = '  ' . $t('parity.notify.fix_crash');
    } else {
        $lines[] = '  ' . $t('parity.notify.fix_timeout', ['need' => $times['need'], 'disk' => $times['disk'], 'docker' => $times['docker'],
                                                          'vm' => $times['vm'], 'margin' => PARITYWHY_MARGIN]);
        $lines[] = '  ' . $t('parity.notify.fix_before');
    }
    if (empty($v['kept'])) {
        $lines[] = '  ' . $t('parity.notify.fix_syslog');
    }
    $lines[] = '';
    $lines[] = $t('parity.notify.footer');
    return officeNotify($t('parity.notify.subject', ['n' => $n]), $t("parity.notify.desc_$stop", ['timeout' => (int) ($v['timeout'] ?? $times['disk'])]),
        'normal', implode("\n", $lines), officeNotifyLink('#/caretaker'));
}

/**
 * The team lead's to-do while the last stop was unclean (his finding — the watchman hired; state file only): what to
 * change, with the numbers. Null: nothing to do.
 */
function paritywhyFinding(array $st): ?array
{
    $v = $st['parity']['todo'] ?? null;
    if (!is_array($v)) {
        return null;
    }
    $times = (array) ($v['times'] ?? []) + ['disk' => 90, 'vm' => 0, 'docker' => 0, 'need' => 0];
    $crash = ($v['stop'] ?? '') === 'crash';
    return finding($crash ? 'parity_crash' : 'parity_unclean', 'recommended', false,
        ['n' => max(1, (int) ($v['streak'] ?? 1)), 'held' => (string) ($v['held'] ?? '') ?: '–', 'disk' => (int) $times['disk'], 'need' => (int) $times['need'],
         'docker' => (int) $times['docker'], 'vm' => (int) $times['vm'], 'margin' => PARITYWHY_MARGIN],
        $crash ? 'ups' : 'disks');
}
