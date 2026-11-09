<?php
declare(strict_types=1);

/*
 * Jack Streamer: MediaCache's RUN in PHP (2026-11-09). A port of the doing part of MediaCache by helmi1987 (GNU GPL v3 or later;
 * https://github.com/helmi1987/mediacache-for-unraid), from the office's copy mediacache/mediacache_run.py and mediacache_lib.py,
 * version 7.4.0 (2026-10-15) with its `USO` changes as of the office's 1.91.1: Runner (cleanup, cleanup_with_rsync, give_back's
 * execution, fill, copy_parallel, rsync_copy, fill_with_mover, track_mover, release, write_list, collect_themes, report, main),
 * Progress, Themes, Status and the lib's helpers that act (mkdir_like, remove_empty_*, ensure_share_root, run_mover, run_rsync,
 * acquire_lock, the exclude list, the themes state, write_progress, write_result). This file is GPL-3.0-or-later too; mediacache/README.md
 * says so. The plan itself is jack-plan.php's (jackPlanDecide()): one plan, the one the shadow compares. A later fix upstream or in the
 * Python copy is ported here by hand.
 *
 * Why: Python 3 for Unraid loses its maintainer's support on 2026-11-30. Asleep unless the flag file JACK_DATA/php.json exists: then
 * jackJob() starts THIS as the run's own process (`php jack-run.php --run|--release|--show-on-deck --compact|<dry>`, the same
 * environment, the same output file, the same exit codes: 0 ok, 3 stopped, 1 failed/busy/config, 2 a bad call) and Python only plans dry
 * beside it (jack-shadow.php). Before a real run the job hands over the tape the comparison planned from (`--tape`): this run plans from
 * exactly those answers, so the plan compared is the plan executed, and writes that plan down (`--plan-out`, RAM) for the night file.
 * Without a tape (nothing was compared: a disk asleep, Python failed …) it asks the servers itself.
 *
 * The same files in the same shapes as Python: MEDIACACHE_RESULT (status.json), MEDIACACHE_PROGRESS, mediacache_exclude.txt (its two
 * sections), mediacache_themes.json, mediacache.lock (the SAME flock: a Python and a PHP run never overlap), logs/mediacache.log with
 * Python's line format (its warnings and errors are the result's counts). Never worse than Python on safety: a source is deleted only
 * after rsync ended 0 and the sizes match; MEDIACACHE_STOP after the file(s) in flight; an incomplete plan never gives anything back
 * and makes no list where there was none; and, beyond Python: the array stopping (Unraid's var.ini: Stopping/Stopped) ends the
 * copy or move in flight at once (rsync and Unraid's move binary keep the source until their copy is whole) — result `stopped`.
 */

if (!interface_exists('JackPlanHost', false)) {        // on its own (the run's process): the plan's part and the office's util first
    if (!function_exists('run')) {
        if (!defined('FILE_UID')) {
            define('FILE_UID', 0);
            define('FILE_GID', 0);
        }
        require_once dirname(__DIR__) . '/lib/util.php';
    }
    require_once __DIR__ . '/jack-plan.php';
}

const JACK_RUN_SOURCE = '7.4.0 (2026-10-15)';   // the MediaCache this was ported from (its __version__)
const JACK_RUN_FLAG = 'php.json';
const JACK_RUN_THEME = 'theme.mp3';
const JACK_RUN_THEMES_MARK = '# USO themes: theme.mp3 kept on the pool for good (Unraid Secretary Office, Jack Streamer)';
const JACK_RUN_MOVERS = ['/usr/libexec/unraid/move', '/usr/local/sbin/move', '/usr/local/bin/move'];
const JACK_RUN_MOVER_PID = '/var/run/mover.pid';
const JACK_RUN_VAR_INI = '/var/local/emhttp/var.ini';
const JACK_RUN_LOG_MAX = 10 * 1024 * 1024;       // RotatingFileHandler: 10 MB x 20
const JACK_RUN_LOG_KEEP = 20;
const JACK_RUN_QUEUE_MAX = 5000;
const JACK_RUN_RECENT_MAX = 50;
const JACK_RUN_VIDEO_EXT = ['.mkv', '.mp4', '.m4v', '.avi', '.ts', '.m2ts', '.mts', '.iso', '.wmv', '.mov', '.mpg', '.mpeg', '.webm', '.flv',
                            '.vob', '.divx', '.ogm', '.3gp', '.rmvb', '.strm', '.img'];
const JACK_RUN_VANISHED_RE = '/rename "[^"\n]*" -> "[^"\n]*": No such file or directory/';
const JACK_RUN_VANISHED_SAID = 'Ein anderes Programm hat die halb kopierte Datei gelöscht (z. B. ein Sync mit --delete auf diesen Share) '
    . '– die Quelle ist unversehrt, der nächste Lauf versucht es wieder: ';
const JACK_RUN_PROGRESS_RE = '/^\s*([\d,.\']+)\s+(\d+)%\s+(\S+\/s)\s+(\d+:\d+:\d+)/';
const JACK_RUN_LEVELS = ['DEBUG' => 10, 'INFO' => 20, 'WARNING' => 30, 'ERROR' => 40];

/** The flag: a file; absent = Python runs, exactly as before */
function jackPhpOn(?string $dir = null): bool
{
    return is_file(($dir ?? JACK_DATA) . '/' . JACK_RUN_FLAG);
}

/** vanished(): rsync's temporary file was deleted in the target before its rename (code 23) */
function jackRunVanished(int $rc, string $out): bool
{
    return $rc === 23 && preg_match(JACK_RUN_VANISHED_RE, $out) === 1;
}

// ===================================================================== the log (Python's format: its readers stay as they are)

/** setup_logging() + LEVEL_COUNTER: stdout (the office's output file) and logs/mediacache.log, rotating 10 MB x 20 */
final class JackRunLog
{
    /** @var array{WARNING: int, ERROR: int} */
    public array $counts = ['WARNING' => 0, 'ERROR' => 0];

    private int $level;

    /** @var resource|null */
    private $out;

    public function __construct(private string $file, string $level = 'INFO', mixed $out = null)
    {
        $this->level = JACK_RUN_LEVELS[strtoupper($level)] ?? 20;
        $this->out = is_resource($out) ? $out : (defined('STDOUT') ? STDOUT : null);
        if ($file !== '' && !is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
    }

    public function line(string $level, string $message): void
    {
        $n = JACK_RUN_LEVELS[$level] ?? 20;
        if ($n < $this->level) {
            return;
        }
        if ($n >= 30) {
            $this->counts[$n >= 40 ? 'ERROR' : 'WARNING']++;
        }
        $t = microtime(true);
        $text = date('Y-m-d H:i:s', (int) $t) . ',' . sprintf('%03d', (int) (($t - floor($t)) * 1000)) . " | $level | $message\n";
        if ($this->out !== null) {
            @fwrite($this->out, $text);
        }
        if ($this->file === '') {
            return;
        }
        clearstatcache(true, $this->file);
        $size = @filesize($this->file);
        if ($size !== false && $size + strlen($text) >= JACK_RUN_LOG_MAX) {
            for ($i = JACK_RUN_LOG_KEEP - 1; $i >= 1; $i--) {
                if (is_file("$this->file.$i")) {
                    @rename("$this->file.$i", "$this->file." . ($i + 1));
                }
            }
            @rename($this->file, "$this->file.1");
        }
        @file_put_contents($this->file, $text, FILE_APPEND);
    }

    public function debug(string $m): void
    {
        $this->line('DEBUG', $m);
    }

    public function info(string $m): void
    {
        $this->line('INFO', $m);
    }

    public function warning(string $m): void
    {
        $this->line('WARNING', $m);
    }

    public function error(string $m): void
    {
        $this->line('ERROR', $m);
    }

    /** log.exception(): the error and where it came from */
    public function exception(string $m, Throwable $e): void
    {
        $this->line('ERROR', $m . "\n" . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    public function say(): Closure
    {
        return function (string $level, string $message): void {
            $this->line($level, $message);
        };
    }
}

/** print(): the report's own lines, plain */
function jackRunPrint(string $s): void
{
    if (defined('STDOUT')) {
        fwrite(STDOUT, "$s\n");
    }
}

// ===================================================================== where the answers come from

/**
 * The plan's answers: the tape the comparison planned from (or the live servers), its clock, and the pool's numbers as the plan saw them
 * (give-back). Once the plan stands ($live = true), the pool's numbers are read for real again: the fill measures what the cleanup freed.
 */
final class JackRunHost implements JackPlanHost
{
    public bool $live = false;

    public function __construct(public JackPlanHost $plan, private array $env)
    {
    }

    public function fetch(string $url, array $plain, array $key, string $token, mixed $timeout): string
    {
        return $this->plan->fetch($url, $plain, $key, $token, $timeout);
    }

    public function now(): float
    {
        return $this->plan->now();
    }

    public function poolUsage(string $path): array
    {
        return $this->live ? jackPlanPoolUsage($path, $this->env) : $this->plan->poolUsage($path);
    }
}

// ===================================================================== what the run touches (the lib's doing half)

/** The run's files, from its environment as Python's lib reads them at import */
final class JackRunPaths
{
    public string $base;

    public string $config;

    public string $exclude;

    public string $themes;

    public string $lock;

    public string $status;

    public string $logDir;

    public string $result;

    public string $progress;

    public string $stop;

    public function __construct(array $env)
    {
        $this->base = jpNorm(($env['MEDIACACHE_DIR'] ?? '') !== '' ? $env['MEDIACACHE_DIR'] : dirname(__DIR__, 2) . '/mediacache');
        $this->config = ($env['MEDIACACHE_CONFIG'] ?? '') !== '' ? $env['MEDIACACHE_CONFIG'] : "$this->base/mediacache_settings.json";
        $this->exclude = "$this->base/mediacache_exclude.txt";
        $this->themes = "$this->base/mediacache_themes.json";
        $this->lock = "$this->base/mediacache.lock";
        $this->status = ($env['MEDIACACHE_LIVE_STATUS'] ?? '') !== '' ? $env['MEDIACACHE_LIVE_STATUS'] : "$this->base/mediacache_status.json";
        $this->logDir = "$this->base/logs";
        $this->result = (string) ($env['MEDIACACHE_RESULT'] ?? '');
        $this->progress = (string) ($env['MEDIACACHE_PROGRESS'] ?? '');
        $this->stop = (string) ($env['MEDIACACHE_STOP'] ?? '');
    }
}

/** acquire_lock(): the exclusive flock on mediacache.lock Python takes — null when another run (Python's or PHP's) holds it */
function jackRunLock(string $file, JackRunLog $log): mixed
{
    $fh = @fopen($file, 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        if ($fh !== false) {
            fclose($fh);
        }
        $log->warning("Ein anderer MediaCache-Lauf ist aktiv ($file) – Abbruch");
        return null;
    }
    ftruncate($fh, 0);
    fwrite($fh, (string) getmypid());
    fflush($fh);
    return $fh;
}

/** _exclude_sections(): [entries, theme.mp3 after THEMES_MARK]; comments and empty lines are no entries */
function jackRunExcludeSections(string $file): array
{
    if (!file_exists($file)) {
        return [[], []];
    }
    $text = @file_get_contents($file);
    if ($text === false || !mb_check_encoding($text, 'UTF-8')) {
        jpCrash('the list: not readable or no UTF-8');     // as Python's read_text(): never «an empty list» for one that can't be read
    }
    $files = $themes = [];
    $into = 'files';
    foreach (preg_split('/\r\n|\n|\r|\x0b|\x0c|\x1c|\x1d|\x1e|\x{85}|\x{2028}|\x{2029}/u', $text) ?: [] as $line) {
        $line = jpStrip($line);
        if (str_starts_with($line, '#')) {
            if (str_starts_with($line, explode(':', JACK_RUN_THEMES_MARK)[0])) {
                $into = 'themes';
            }
            continue;
        }
        if ($line !== '') {
            if ($into === 'files') {
                $files["e$line"] = $line;
            } else {
                $themes["e$line"] = $line;
            }
        }
    }
    return [$files, $themes];
}

/** read_exclude(): every entry of both sections, keyed «e<entry>» */
function jackRunReadExclude(string $file): array
{
    [$files, $themes] = jackRunExcludeSections($file);
    return $files + $themes;
}

/** write_exclude(): atomic (Smart Mover never reads half a list); the theme.mp3 in their own section at the end, after THEMES_MARK */
function jackRunWriteExclude(string $file, array $paths, array $themes = []): void
{
    $themes = array_values(array_unique(array_map('strval', $themes)));
    $set = array_flip($themes);
    $lines = jpSorted(array_values(array_filter(array_values(array_unique(array_map('strval', $paths))), fn (string $p): bool => !isset($set[$p]))));
    if ($themes) {
        $lines = array_merge($lines, [JACK_RUN_THEMES_MARK], jpSorted($themes));
    }
    writeAtomic($file, $lines ? implode("\n", $lines) . "\n" : '', 0644, 0, 0);
}

/** read_themes_state(): only in its shape ({"v": 1, "items": {…}}), else null (the first collection) */
function jackRunReadThemes(string $file): ?array
{
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) && ($j['v'] ?? null) === 1 && is_array($j['items'] ?? null) ? $j : null;
}

function jackRunWriteThemes(string $file, array $data): void
{
    writeAtomic($file, jackRunJson($data) . "\n", 0600, 0, 0);
}

/** JSON as Python's json.dumps(…, ensure_ascii=False) reads back: a dict stays a dict, an empty one too */
function jackRunJson(mixed $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
}

/** mkdir_like(): the missing folders of $dst with owner, group and mode of the matching folders of $src; existing ones untouched */
function jackRunMkdirLike(string $dst, string $src, JackRunLog $log): void
{
    $missing = [];
    for ($d = jpNorm($dst), $s = jpNorm($src); !file_exists($d) && $d !== jpPyParent($d); $d = jpPyParent($d), $s = jpPyParent($s)) {
        $missing[] = [$d, $s];
    }
    foreach (array_reverse($missing) as [$d, $s]) {
        if (!@mkdir($d, 0777) && !is_dir($d)) {
            throw new RuntimeException("mkdir $d failed");
        }
        $st = @stat($s);
        if ($st === false || !@chown($d, (int) $st['uid']) || !@chgrp($d, (int) $st['gid']) || !@chmod($d, $st['mode'] & 07777)) {
            $log->warning("Owner/Rechte für $d nicht übernommen (" . ($st === false ? "$s nicht lesbar" : 'chown/chmod') . ')');
        }
    }
}

/** Path.parent of an absolute or relative path («/» stays «/») */
function jpPyParent(string $path): string
{
    [$root, $parts] = jpParts($path);
    array_pop($parts);
    $p = $root . implode('/', $parts);
    return $p === '' ? '.' : $p;
}

/** copy_owner_mode() */
function jackRunCopyOwnerMode(string $dst, string $src, JackRunLog $log): void
{
    $st = @stat($src);
    if ($st === false || !@chown($dst, (int) $st['uid']) || !@chgrp($dst, (int) $st['gid']) || !@chmod($dst, $st['mode'] & 07777)) {
        $log->warning("Owner/Rechte für $dst nicht übernommen (" . ($st === false ? "$src nicht lesbar" : 'chown/chmod') . ')');
    }
}

/** remove_empty_parents(): empty folders from $path up to (not) $stopRoot; the share's folder (depth 1) always stays */
function jackRunRemoveEmptyParents(string $path, string $stopRoot, JackRunLog $log, int $minDepth = 2): void
{
    for ($p = jpNorm($path); ; $p = jpPyParent($p)) {
        $rel = jpRelative($p, $stopRoot);
        if ($rel === null) {
            return;
        }
        $depth = $rel === '' ? 0 : count(explode('/', $rel));
        clearstatcache(true, $p);
        if ($depth < $minDepth || !is_dir($p) || is_link($p)) {
            return;
        }
        $names = @scandir($p);
        if ($names === false || count($names) > 2) {
            return;     // not empty (or not readable: Python's iterdir() would end the run here — the folder simply stays)
        }
        if (!@rmdir($p)) {
            $log->warning("Leerer Ordner konnte nicht entfernt werden: $p");
            return;
        }
        $log->info("Leeren Ordner entfernt: $p");
    }
}

/** remove_empty_on_disk(): after a file that came through /mnt/user0, its folder on the disk it lay on too — no other disk read */
function jackRunRemoveEmptyOnDisk(array $cfg, string $rel, string $disk, JackRunLog $log): void
{
    if ($disk === '' || $disk === '?') {
        return;
    }
    $root = jpJoin(jpPyParent(jpNorm($cfg['array_disks_glob'])), $disk);
    jackRunRemoveEmptyParents(jpPyParent(jpJoin($root, $rel)), $root, $log);
}

/** Locations.on_disk(): the real disk paths a file lies on (sorted) */
function jackRunOnDisk(array $cfg, string $rel): array
{
    $out = [];
    foreach (glob(jpJoin(jpNorm($cfg['array_disks_glob']), $rel)) ?: [] as $p) {
        if (file_exists($p)) {
            $out[] = $p;
        }
    }
    return jpSorted($out);
}

/** _disk_root(): /mnt/disk3/Filme/X/x.mkv -> /mnt/disk3; through /mnt/user0 the array path */
function jackRunDiskRoot(string $src, array $cfg, string $array, bool $fromDisk): string
{
    if (!$fromDisk) {
        return $array;
    }
    $depth = count(jpPyParts($cfg['array_disks_glob']));
    $parts = jpPyParts($src);
    if (count($parts) <= $depth) {
        return $array;
    }
    return jpNorm(implode('/', array_slice($parts, 0, $depth)));
}

/** zfs_dataset_exists() */
function jackRunZfsExists(string $name, array $env): bool
{
    [$exit, $out] = run([($env['MEDIACACHE_ZFS'] ?? '') ?: 'zfs', 'list', '-H', '-o', 'name', $name], 60);
    return $exit === 0 && trim((string) $out) === $name;
}

/**
 * ensure_share_root(): the share's folder on the pool as Unraid makes it — on ZFS the dataset <pool>/<share> (zfs create, inherited),
 * then owner and mode as /mnt/user0/<share>; only when the share may live on this pool (shareCachePool = it, shareUseCache yes/prefer/only).
 * btrfs/XFS: a folder (mkdir_like). Never a destroy or rename, never existing data touched; a dry run only says. Once per share and run.
 */
function jackRunShareRoot(array $cfg, string $cache, string $array, string $share, bool $runMode, JackRunLog $log, array &$state, array $env): bool
{
    if (array_key_exists("s$share", $state)) {
        return $state["s$share"];
    }
    $root = jpJoin($cache, $share);
    $mounts = jackPlanMounts($env);
    $pool = jackPlanMountOf($cache, $mounts);
    $fs = $pool[2] ?? '?';
    if (is_dir($root)) {
        if ($fs === 'zfs' && $pool !== null) {
            $own = jackPlanMountOf($root, $mounts);
            if ($own === null || $own[2] !== 'zfs' || rtrim($own[1], '/') !== rtrim($root, '/')) {
                $dataset = "$pool[0]/$share";
                if (jackRunZfsExists($dataset, $env)) {
                    $log->error("Dataset $dataset existiert, ist aber nicht unter $root eingehängt – Kopien landeten im Pool-Dataset statt im Share-Dataset. "
                        . "MediaCache ändert daran nichts (zfs mount prüfen), Dateien des Shares «{$share}» übersprungen");
                    return $state["s$share"] = false;
                }
                $log->warning("Share-Wurzel $root ist auf dem ZFS-Pool ein normaler Ordner im Dataset $pool[0], kein eigenes Dataset (keine eigenen "
                    . "Snapshots/Quota/Backups je Share; Unraid wandelt ihn nie um). MediaCache baut nichts um. Lösung: den Teil des Shares auf $cache leeren lassen "
                    . "(Mover bzw. MediaCache-Cleanup) und den leeren Ordner entfernen – der nächste Lauf legt dann das Dataset $pool[0]/$share an.");
            }
        }
        return $state["s$share"] = true;
    }
    if (empty($cfg['create_share_root'])) {
        $log->error("Share-Wurzel $root fehlt und create_share_root=false – Dateien des Shares «{$share}» übersprungen");
        return $state["s$share"] = false;
    }
    if (!in_array($fs, ['zfs', 'btrfs', 'xfs'], true)) {
        $log->error("Dateisystem von $cache unbekannt ($fs; Pool nicht eingehängt oder die Mounts nicht lesbar) – Share-Wurzel $root nicht angelegt, "
            . "Dateien des Shares «{$share}» übersprungen");
        return $state["s$share"] = false;
    }
    if ($fs !== 'zfs') {
        if ($runMode) {
            jackRunMkdirLike($root, jpJoin($array, $share), $log);
            $log->info("Share-Wurzel als Ordner angelegt ($fs): $root");
        } else {
            $log->info("PLAN: würde Ordner $root anlegen ($fs)");
        }
        return $state["s$share"] = true;
    }
    $poolName = jpName(jpNorm($cache));
    [$mode, $primary] = jackPlanShareMode($cfg, $share);
    if (!in_array($mode, ['yes', 'prefer', 'only'], true) || $primary !== $poolName) {
        $why = $mode === null ? 'keine Share-Konfiguration gefunden' : "shareUseCache=$mode, shareCachePool=$primary";
        $log->error("Share «{$share}» darf laut Share-Einstellung nicht auf den Pool «{$poolName}» ($why) – kein Dataset angelegt, Dateien dieses Shares "
            . "übersprungen. Share in Unraid auf Primary storage «{$poolName}» stellen oder Share aus den path_mappings nehmen.");
        return $state["s$share"] = false;
    }
    $dataset = "$pool[0]/$share";
    if (jackRunZfsExists($dataset, $env)) {
        $log->error("Dataset $dataset existiert, ist aber nicht unter $root eingehängt (Mountpoint/canmount prüfen) – MediaCache ändert daran nichts, "
            . "Dateien des Shares «{$share}» übersprungen");
        return $state["s$share"] = false;
    }
    if (!$runMode) {
        $log->info("PLAN: würde Dataset $dataset anlegen (Mountpoint $root, Owner/Rechte wie " . jpJoin($array, $share) . ')');
        return $state["s$share"] = true;
    }
    [$exit, $out, $err] = run([($env['MEDIACACHE_ZFS'] ?? '') ?: 'zfs', 'create', $dataset], 120) + [2 => ''];
    if ($exit !== 0) {
        $log->error("zfs create $dataset fehlgeschlagen: " . trim((string) ($err ?: $out)) . " – Dateien des Shares «{$share}» übersprungen");
        return $state["s$share"] = false;
    }
    clearstatcache(true, $root);
    if (!is_dir($root)) {
        $log->error("Dataset $dataset angelegt, aber nicht unter $root eingehängt (Mountpoint des Pools prüfen) – Dateien des Shares «{$share}» übersprungen");
        return $state["s$share"] = false;
    }
    jackRunCopyOwnerMode($root, jpJoin($array, $share), $log);
    $log->info("Dataset $dataset angelegt ($root), Owner/Rechte wie " . jpJoin($array, $share));
    return $state["s$share"] = true;
}

/** detect_mover_bin() */
function jackRunMoverBin(array $cfg): ?string
{
    if (jpTrue($cfg['mover_bin'] ?? '')) {
        return (string) $cfg['mover_bin'];
    }
    foreach (JACK_RUN_MOVERS as $c) {
        if (file_exists($c)) {
            return $c;
        }
    }
    return null;
}

/** unraid_mover_running(): the regular mover script at work (its pid file names a live process) */
function jackRunMoverRunning(string $pidFile = JACK_RUN_MOVER_PID): bool
{
    $pid = trim((string) @file_get_contents($pidFile));
    return ctype_digit($pid) && file_exists('/proc/' . (int) $pid);
}

/** summarize_mover_output(): each file's message from the move binary («No space left on device» …) */
function jackRunMoverMessages(string $stdout, array $paths): array
{
    $result = [];
    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $stdout)) as $line) {
        foreach ($paths as $p) {
            if ($p !== '' && str_contains($line, $p) && !isset($result["c$p"])) {
                $msg = trim(explode($p, $line, 2)[1], " :-");
                $result["c$p"] = $msg !== '' ? $msg : trim($line);
            }
        }
    }
    return $result;
}

// ===================================================================== processes: rsync and the move binary

/** One child (rsync or Unraid's move binary) polled without blocking; its output read as it comes (stdout and stderr as one for rsync) */
final class JackRunProc
{
    /** @var resource */
    private $proc;

    private array $pipes = [];

    private string $buf = '';

    private string $err = '';

    public string $out = '';

    private ?int $exit = null;

    private string $stdin = '';

    /** @param ?Closure $onLine fn (string $line): void — every line as it comes (rsync's progress) */
    private function __construct(private ?Closure $onLine)
    {
    }

    public static function start(array $cmd, ?string $stdin = null, bool $merge = true, ?Closure $onLine = null): ?self
    {
        $p = new self($onLine);
        $spec = [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $merge ? ['redirect', 1] : ['pipe', 'w']];
        $proc = @proc_open($cmd, $spec, $pipes, '/');
        if (!is_resource($proc)) {
            return null;
        }
        $p->proc = $proc;
        $p->pipes = $pipes;
        foreach ([1, 2] as $i) {
            if (isset($pipes[$i])) {
                stream_set_blocking($pipes[$i], false);
            }
        }
        if ($stdin !== null) {
            stream_set_blocking($pipes[0], false);
            $p->stdin = $stdin;
        }
        return $p;
    }

    /** Feeds stdin, reads what is there; true while it runs */
    public function poll(): bool
    {
        if ($this->stdin !== '' && isset($this->pipes[0])) {
            $n = @fwrite($this->pipes[0], $this->stdin);
            if ($n === false) {
                $this->stdin = '';
            } else {
                $this->stdin = (string) substr($this->stdin, $n);
            }
        }
        if ($this->stdin === '' && isset($this->pipes[0])) {
            fclose($this->pipes[0]);
            unset($this->pipes[0]);
        }
        $this->read();
        if ($this->exit === null) {
            $st = proc_get_status($this->proc);
            if (!$st['running']) {
                $this->exit = $st['exitcode'];
                $this->read();
                foreach ($this->pipes as $pipe) {
                    @fclose($pipe);
                }
                $this->pipes = [];
                proc_close($this->proc);
                if ($this->buf !== '') {
                    $this->handle($this->buf);
                    $this->buf = '';
                }
            }
        }
        return $this->exit === null;
    }

    private function read(): void
    {
        foreach ([1, 2] as $i) {
            if (!isset($this->pipes[$i])) {
                continue;
            }
            while (($chunk = @fread($this->pipes[$i], 65536)) !== false && $chunk !== '') {
                if ($i === 2) {
                    $this->err .= $chunk;
                    continue;
                }
                $parts = preg_split('/[\r\n]/', $this->buf . $chunk) ?: [];
                $this->buf = (string) array_pop($parts);
                foreach ($parts as $part) {
                    $this->handle($part);
                }
            }
        }
    }

    private function handle(string $raw): void
    {
        $this->out .= $raw . "\n";
        if ($this->onLine !== null) {
            ($this->onLine)($raw);
        }
    }

    public function exit(): int
    {
        return $this->exit ?? -1;
    }

    public function stderr(): string
    {
        return $this->err;
    }

    /** The array stops: ended now (SIGTERM, 2 s later SIGKILL) — rsync removes its temporary file, the source stays */
    public function kill(): void
    {
        if ($this->exit !== null) {
            return;
        }
        @proc_terminate($this->proc, 15);
        $until = hrtime(true) + 2_000_000_000;
        while ($this->poll() && hrtime(true) < $until) {
            usleep(50_000);
        }
        if ($this->exit === null) {
            @proc_terminate($this->proc, 9);
            while ($this->poll()) {
                usleep(50_000);
            }
        }
    }
}

// ===================================================================== MEDIACACHE_PROGRESS (mediacache_run.py `Progress`)

/** owners(): who wants a file — the first (f.user) first, then the others by their rank */
function jackRunOwners(JackPlanFile $f): array
{
    $rest = array_values(array_filter(array_map('strval', array_keys($f->ranks)), fn (string $k): bool => $k !== $f->user));
    usort($rest, fn (string $a, string $b): int => ($f->ranks[$a] <=> $f->ranks[$b]) ?: jpCmp($a, $b));
    return array_merge($f->user !== null ? [$f->user] : [], $rest);
}

/**
 * The live panel's file: only in a real run and only when MEDIACACHE_PROGRESS is set; rewritten at every file boundary (atomic). The
 * same fields as Python's, in its order: phase, back, fill, users, current/active, queue, shared, pos, recent, themes.
 */
final class JackRunProgress
{
    public array $data;

    /** @var array<string, int> person key => index in data[users] */
    private array $users = [];

    /** @var array<int, int> spl_object_id(file) => its place in the queue */
    private array $qidx = [];

    private array $passed = [];

    /** @var array<string, array> the files in work, in the order they began */
    private array $active = [];

    private float $themesWritten = 0.0;

    public function __construct(string $mode, private bool $on, private string $file)
    {
        $now = time();
        $this->data = ['v' => 1, 'mode' => $mode, 'phase' => 'plan', 'started' => $now, 'updated' => $now, 'back' => null, 'fill' => null, 'users' => [],
                       'current' => null, 'active' => [], 'queue' => null, 'shared' => [], 'pos' => 0, 'recent' => [], 'themes' => null];
        $this->write();
    }

    public function write(): void
    {
        if (!$this->on) {
            return;
        }
        $this->data['active'] = array_values($this->active);
        $this->data['current'] = $this->data['active'][0] ?? null;
        $this->data['updated'] = time();
        try {
            writeAtomic($this->file, jackRunJson($this->data) . "\n", 0600, 0, 0);
        } catch (Throwable) {
            // Python: an OSError here is passed over
        }
    }

    public function phase(string $phase): void
    {
        $b = $this->data['back'];
        if ($this->data['phase'] === 'back' && $phase !== 'back' && $b !== null && !empty($b['started']) && empty($b['ended'])) {
            $this->data['back']['ended'] = time();
        }
        $this->data['phase'] = $phase;
        $this->active = [];
        $this->write();
    }

    public function planBack(int $files, int $bytes): void
    {
        $this->data['back'] = ['files' => $files, 'bytes' => $bytes, 'done_files' => 0, 'done_bytes' => 0, 'started' => null, 'ended' => null];
    }

    /** @param list<JackPlanFile> $files in the fill's order */
    public function planFill(JackPlanner $planner, array $files): void
    {
        $todo = [];
        $once = [0, 0];
        foreach ($files as $f) {
            if (!$f->onCache) {
                $once[0]++;
                $once[1] += $f->size;
                foreach (jackRunOwners($f) as $k) {
                    $todo[$k] ??= [0, 0];
                    $todo[$k][0]++;
                    $todo[$k][1] += $f->size;
                }
            }
        }
        $this->data['fill'] = ['files' => $once[0], 'bytes' => $once[1], 'done_files' => 0, 'done_bytes' => 0, 'started' => null, 'ended' => null];
        foreach ($planner->users as $u) {
            if (isset($todo[$u->key])) {
                $this->users[$u->key] = count($this->data['users']);
                $this->data['users'][] = ['key' => $u->key, 'name' => $u->name, 'server' => implode(', ', $u->servers), 'files' => $todo[$u->key][0],
                    'bytes' => $todo[$u->key][1], 'done_files' => 0, 'done_bytes' => 0, 'started' => null, 'ended' => null];
            }
        }
        $order = array_values(array_filter($files, fn (JackPlanFile $f): bool => !$f->onCache));
        if (count($order) <= JACK_RUN_QUEUE_MAX) {
            $this->data['queue'] = [];
            $this->data['shared'] = [];
            foreach ($order as $i => $f) {
                $this->data['queue'][] = [$f->user !== null && isset($this->users[$f->user]) ? $this->users[$f->user] : -1, $f->size];
                $this->qidx[spl_object_id($f)] = $i;
                $own = jackRunOwners($f);
                if (count($own) > 1) {
                    $this->data['shared'][] = [$i, array_values(array_map(fn (string $k): int => $this->users[$k],
                        array_filter(array_slice($own, 1), fn (string $k): bool => isset($this->users[$k]))))];
                }
            }
        }
    }

    /** _entries(): the users that want the file (the first first) and the fill as a whole — as paths into data */
    private function entries(?JackPlanFile $f, string $phase = 'fill'): array
    {
        if ($f === null) {
            return $phase === 'back' && $this->data['back'] !== null ? [['back']] : [];
        }
        $out = [];
        foreach (jackRunOwners($f) as $k) {
            if (isset($this->users[$k])) {
                $out[] = ['users', $this->users[$k]];
            }
        }
        if ($this->data['fill'] !== null) {
            $out[] = ['fill'];
        }
        return $out;
    }

    private function &entry(array $path): array
    {
        if ($path[0] === 'users') {
            return $this->data['users'][$path[1]];
        }
        return $this->data[$path[0]];
    }

    private function passedFile(JackPlanFile $f, array $entries): void
    {
        $i = $this->qidx[spl_object_id($f)] ?? null;
        if ($i !== null && $this->data['queue'] !== null && !isset($this->passed[$i])) {
            $this->passed[$i] = true;
            $this->data['queue'][$i] = [-1, 0];
            if ($this->data['shared']) {
                $this->data['shared'] = array_values(array_filter($this->data['shared'], fn (array $x): bool => $x[0] !== $i));
            }
            while (isset($this->passed[$this->data['pos']])) {
                $this->data['pos']++;
            }
        }
        $now = time();
        foreach ($entries as $path) {
            $e = &$this->entry($path);
            if ($e['done_files'] >= $e['files'] && !empty($e['started']) && empty($e['ended'])) {
                $e['ended'] = $now;
            }
            unset($e);
        }
    }

    private static function key(?JackPlanFile $f, ?string $rel): string
    {
        return $f !== null ? 'f' . spl_object_id($f) : "back\0" . (string) $rel;
    }

    /** A file begins: who, what, where to; $why (a give-back over the limit): {title, ahead, who} */
    public function start(string $phase, string $rel, int $size, string $target, ?JackPlanFile $f = null, ?array $why = null): void
    {
        $now = time();
        foreach ($this->entries($f, $phase) as $path) {
            $e = &$this->entry($path);
            if (empty($e['started'])) {
                $e['started'] = $now;
            }
            unset($e);
        }
        $cur = ['phase' => $phase, 'rel' => $rel, 'size' => $size, 'target' => $target, 'user' => $f?->user, 'source' => $f?->source, 'title' => $f?->title, 'since' => $now];
        if ($f !== null) {
            $cur['users'] = jackRunOwners($f);
            if (isset($this->qidx[spl_object_id($f)])) {
                $cur['q'] = $this->qidx[spl_object_id($f)];
            }
        }
        if ($why !== null) {
            $cur['why'] = $why;
        }
        $this->active[self::key($f, $rel)] = $cur;
        $this->write();
    }

    /** _take(): the entry of the file in work — without a rel on the way back the first there */
    private function take(?JackPlanFile $f, ?string $rel): ?array
    {
        if ($f !== null || $rel !== null) {
            $k = self::key($f, $rel);
            $cur = $this->active[$k] ?? null;
            unset($this->active[$k]);
            return $cur;
        }
        foreach ($this->active as $k => $cur) {
            if (($cur['phase'] ?? null) === 'back') {
                unset($this->active[$k]);
                return $cur;
            }
        }
        return null;
    }

    /** A file arrived (back on the array, or $f: on the pool) */
    public function done(int $size, ?JackPlanFile $f = null, ?string $rel = null): void
    {
        $entries = $f !== null ? $this->entries($f) : ($this->data['back'] !== null ? [['back']] : []);
        foreach ($entries as $path) {
            $e = &$this->entry($path);
            $e['done_files']++;
            $e['done_bytes'] += $size;
            unset($e);
        }
        $cur = $this->take($f, $rel);
        if ($cur !== null) {
            $back = ($cur['phase'] ?? null) === 'back';
            $this->data['recent'][] = ['name' => $cur['rel'], 'bytes' => $size, 'from' => $back ? 'cache' : 'array', 'to' => $back ? 'array' : 'cache'];
            $this->data['recent'] = array_slice($this->data['recent'], -JACK_RUN_RECENT_MAX);
        }
        if ($f !== null) {
            $this->passedFile($f, $entries);
        }
        $this->write();
    }

    /** A planned file drops out (skipped or failed): no longer planned */
    public function drop(JackPlanFile $f, bool $write = true): void
    {
        $entries = $this->entries($f);
        foreach ($entries as $path) {
            $e = &$this->entry($path);
            $e['files'] = max($e['done_files'], $e['files'] - 1);
            $e['bytes'] = max($e['done_bytes'], $e['bytes'] - $f->size);
            unset($e);
        }
        $this->passedFile($f, $entries);
        unset($this->active[self::key($f, null)]);
        if ($write) {
            $this->write();
        }
    }

    public function planThemes(int $titles): void
    {
        $this->data['themes'] = ['titles' => $titles, 'looked' => 0, 'files' => 0, 'bytes' => 0, 'done_files' => 0, 'done_bytes' => 0, 'started' => time(), 'ended' => null];
        $this->write();
    }

    /** A title looked at; $found: its theme.mp3 lies on the array and is fetched. Written once a second at most */
    public function themeLooked(?int $found = null, bool $force = false): void
    {
        if ($this->data['themes'] === null) {
            return;
        }
        $this->data['themes']['looked']++;
        if ($found !== null) {
            $this->data['themes']['files']++;
            $this->data['themes']['bytes'] += $found;
        }
        if ($force || microtime(true) - $this->themesWritten >= 1) {
            $this->themesWritten = microtime(true);
            $this->write();
        }
    }

    public function themeStart(string $rel, int $size, string $target): void
    {
        $this->active["themes\0$rel"] = ['phase' => 'themes', 'rel' => $rel, 'size' => $size, 'target' => $target, 'user' => null, 'source' => null, 'title' => null, 'since' => time()];
        $this->write();
    }

    public function themeDone(string $rel, int $size, bool $ok): void
    {
        $cur = $this->active["themes\0$rel"] ?? null;
        unset($this->active["themes\0$rel"]);
        if ($this->data['themes'] !== null) {
            if ($ok) {
                $this->data['themes']['done_files']++;
                $this->data['themes']['done_bytes'] += $size;
            } else {
                $this->data['themes']['files'] = max($this->data['themes']['done_files'], $this->data['themes']['files'] - 1);
                $this->data['themes']['bytes'] = max($this->data['themes']['done_bytes'], $this->data['themes']['bytes'] - $size);
            }
        }
        if ($ok && $cur !== null) {
            $this->data['recent'][] = ['name' => $cur['rel'], 'bytes' => $size, 'from' => 'array', 'to' => 'cache'];
            $this->data['recent'] = array_slice($this->data['recent'], -JACK_RUN_RECENT_MAX);
        }
        $this->write();
    }

    public function themesEnd(): void
    {
        if ($this->data['themes'] !== null && empty($this->data['themes']['ended'])) {
            $this->data['themes']['ended'] = time();
        }
        $this->write();
    }

    /** A file of the way back stays on the pool (a duplicate, an error, a stop): no longer planned */
    public function dropBack(int $size, ?string $rel = null): void
    {
        if ($this->data['back'] !== null) {
            $this->data['back']['files'] = max($this->data['back']['done_files'], $this->data['back']['files'] - 1);
            $this->data['back']['bytes'] = max($this->data['back']['done_bytes'], $this->data['back']['bytes'] - $size);
        }
        $this->take(null, $rel);
    }
}

// ===================================================================== the live status (mediacache_lib.py `Status`; the office never reads it)

final class JackRunStatus
{
    public array $data;

    private bool $dirty = true;

    private float $lastLog;

    private float $lastWrite = 0.0;

    public function __construct(string $mode, private JackRunLog $log, private bool $enabled, private int $logInterval, private string $file)
    {
        $now = microtime(true);
        $this->lastLog = $now;
        $this->data = ['pid' => getmypid(), 'version' => JACK_RUN_SOURCE, 'mode' => $mode, 'state' => 'läuft', 'started' => $now, 'updated' => $now, 'phase' => 'Start',
                       'phase_started' => $now, 'files_total' => 0, 'bytes_total' => 0, 'files_done' => 0, 'files_failed' => 0, 'bytes_done' => 0, 'jobs' => [], 'recent' => []];
        if ($enabled) {
            $this->write();
        }
    }

    public function phase(string $name, int $files = 0, int $size = 0): void
    {
        $now = microtime(true);
        $this->data = ['phase' => $name, 'phase_started' => $now, 'files_total' => $files, 'bytes_total' => $size, 'files_done' => 0, 'files_failed' => 0,
                       'bytes_done' => 0, 'jobs' => []] + $this->data;
        $this->dirty = true;
        $this->lastLog = $now;
    }

    public function jobStart(string $key, string $rel, string $disk, int $size, int $index): void
    {
        $this->data['jobs'][$key] = ['rel' => $rel, 'disk' => $disk, 'size' => $size, 'done' => 0, 'speed' => '', 'eta' => '', 'started' => microtime(true), 'index' => $index];
        $this->dirty = true;
    }

    public function jobProgress(string $key, int $done, string $speed = '', string $eta = ''): void
    {
        if (isset($this->data['jobs'][$key])) {
            $j = &$this->data['jobs'][$key];
            $j['done'] = $j['size'] ? min($done, $j['size']) : $done;
            $j['speed'] = $speed;
            $j['eta'] = $eta;
            unset($j);
            $this->dirty = true;
        }
    }

    public function jobEnd(string $key, bool $ok): void
    {
        $job = $this->data['jobs'][$key] ?? null;
        if ($job === null) {
            return;
        }
        unset($this->data['jobs'][$key]);
        if ($ok) {
            $this->data['files_done']++;
            $this->data['bytes_done'] += $job['size'];
        } else {
            $this->data['files_failed']++;
        }
        array_unshift($this->data['recent'], ['rel' => $job['rel'], 'disk' => $job['disk'], 'size' => $job['size'], 'ok' => $ok,
            'seconds' => round(microtime(true) - $job['started'], 1)]);
        $this->data['recent'] = array_slice($this->data['recent'], 0, 10);
        $this->dirty = true;
    }

    /** The ticker's work (Python: a thread every 2 s): the file when changed, a progress line every status_log_interval while copying */
    public function tick(): void
    {
        $now = microtime(true);
        if ($now - $this->lastWrite >= 2) {
            if ($this->dirty && $this->enabled) {
                $this->write();
            }
            if ($this->logInterval && $this->data['jobs'] && $now - $this->lastLog >= $this->logInterval) {
                $this->lastLog = $now;
                $this->log->info($this->summary());
            }
        }
    }

    public function finish(string $state = 'fertig'): void
    {
        $this->data['state'] = $state;
        $this->data['phase'] = 'Ende';
        $this->data['jobs'] = [];
        $this->data['finished'] = microtime(true);
        if ($this->enabled) {
            $this->write();
        }
    }

    public function summary(): string
    {
        $d = $this->data;
        $done = $d['bytes_done'] + array_sum(array_map(fn (array $j): int => (int) $j['done'], $d['jobs']));
        $pct = $d['bytes_total'] ? sprintf(' (%.0f %%)', $done / $d['bytes_total'] * 100) : '';
        $elapsed = microtime(true) - $d['phase_started'];
        $rate = $elapsed > 0 ? $done / $elapsed : 0;
        $eta = '';
        if ($rate > 0 && $d['bytes_total'] > $done) {
            $s = (int) max(0, ($d['bytes_total'] - $done) / $rate);
            $eta = sprintf(', Rest ca. %d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
        }
        $active = implode(', ', array_map(fn (array $j): string => $j['disk'] . ': ' . jpName($j['rel']) . ' ' . ($j['size'] ? intdiv($j['done'] * 100, $j['size']) : 0) . ' %', $d['jobs']));
        return "Fortschritt {$d['phase']}: {$d['files_done']}/{$d['files_total']} Dateien, " . jpHuman($done) . ' von ' . jpHuman($d['bytes_total']) . $pct . ', '
            . jpHuman($rate) . '/s' . $eta . ($d['files_failed'] ? ", {$d['files_failed']} Fehler" : '') . ($active !== '' ? ' | aktiv (' . count($d['jobs']) . "): $active" : '');
    }

    private function write(): void
    {
        $this->lastWrite = microtime(true);
        $this->data['updated'] = $this->lastWrite;
        $this->dirty = false;
        try {
            $data = $this->data;
            $data['jobs'] = (object) $data['jobs'];
            writeAtomic($this->file, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT), 0644, 0, 0);
        } catch (Throwable) {
            // Python: debug only
        }
    }
}

// ===================================================================== theme.mp3 stays on the pool (mediacache_run.py `Themes`)

/** theme_time(): DateCreated (ISO 8601, UTC) or addedAt (Plex: seconds) as Unix time; null when it can't be read */
function jackRunThemeTime(mixed $v): ?int
{
    if (is_bool($v)) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return $v > 0 ? (int) $v : null;
    }
    if (is_string($v) && strlen($v) >= 19) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', substr($v, 0, 19), new DateTimeZone('UTC'));
        $e = DateTimeImmutable::getLastErrors();
        return $d !== false && ($e === false || ($e['warning_count'] === 0 && $e['error_count'] === 0)) ? $d->getTimestamp() : null;
    }
    return null;
}

/** Path.suffix: from the last dot of the name (not a leading one, not a trailing one) */
function jackRunSuffix(string $rel): string
{
    $name = jpName($rel);
    $i = strrpos($name, '.');
    return $i !== false && $i > 0 && $i < strlen($name) - 1 ? substr($name, $i) : '';
}

/** theme_disk(): the disk a theme.mp3 lies on — Unraid's system.LOCATION through /mnt/user (no other disk asked), else the disk paths */
function jackRunThemeDisk(array $cfg, string $user, string $rel): string
{
    [$exit, $out] = run(['getfattr', '--only-values', '-n', 'system.LOCATION', jpJoin($user, $rel)], 10);
    $name = trim((string) $out, "\0 \n");
    if ($exit === 0 && preg_match('/^disk\d+$/D', $name)) {
        return $name;
    }
    $disks = jackRunOnDisk($cfg, $rel);
    return $disks ? jackPlanDiskName($disks[0], $cfg) : '?';
}

final class JackRunThemes
{
    public bool $on;

    public array $state;

    public array $items;

    public bool $first;

    /** @var array<string, true> the pool paths of the theme.mp3 that lie on the pool now (the list's section) */
    public array $paths = [];

    public array $result;

    public function __construct(private JackRunner $r)
    {
        $this->on = ($r->cfg['themes'] ?? false) === true;
        $this->state = $this->on ? (jackRunReadThemes($r->paths->themes) ?? []) : [];
        $this->items = is_array($this->state['items'] ?? null) ? $this->state['items'] : [];
        $this->first = empty($this->state['done']);
        $this->result = ['on' => $this->on];
    }

    /** _entry(): an entry of the state only in its shape, else null (looked at anew) */
    private static function entry(mixed $v): ?array
    {
        return is_array($v) && array_is_list($v) && count($v) === 5 && is_string($v[0]) && is_string($v[1]) && is_string($v[2])
            && in_array($v[2], ['pool', 'none', 'flat', 'wait'], true) && is_int($v[3]) && is_int($v[4]) ? $v : null;
    }

    /** _folder(): [path rel, folder rel, flat] of an item — null: not mapped (or deliberately not cached). $look false: no disk looked at */
    private function folder(JackPlanLocations $loc, array $item, bool $look): ?array
    {
        $raw = $item['Path'] ?? '';
        $rel = is_string($raw) && $raw !== '' ? $loc->relFromDocker($raw) : null;
        if ($rel === null || $rel === '') {
            return null;
        }
        if (($item['Type'] ?? null) === 'Series') {
            return [$rel, $rel, false];
        }
        if (in_array(strtolower(jackRunSuffix($rel)), JACK_RUN_VIDEO_EXT, true)) {
            return [$rel, jpParent($rel), $loc->isLibraryRoot(jpParent($rel))];
        }
        if (!$look) {
            return [$rel, jpParent($rel), false];
        }
        $folder = is_dir($loc->onCache($rel)) || is_dir($loc->onArray($rel)) ? $rel : jpParent($rel);
        return [$rel, $folder, $folder !== $rel && $loc->isLibraryRoot($folder)];
    }

    private static function due(string $rel, ?int $dc, JackPlanLocations $loc, ?array $prev): bool
    {
        if ($prev === null || $prev[0] !== $rel || $prev[2] === 'wait') {
            return true;
        }
        if ($dc !== null && $dc > $prev[3]) {
            return true;
        }
        return $prev[2] === 'pool' && !is_file($loc->onCache(jpJoin($prev[1], JACK_RUN_THEME)));
    }

    /** scan(): the items of every server (their database only) — what must be looked at; what stays as it is into the state at once */
    public function scan(): array
    {
        $due = $failed = $seen = $keep = [];
        $cfg = $this->r->cfg;
        foreach ($cfg['instances'] as $inst) {
            $name = jpStr($inst['servername']);
            $api = JackPlanServer::forInstance($inst, $cfg['api_timeout'], $this->r->liveHost(), $this->r->plexTv);
            $loc = new JackPlanLocations($cfg, $inst['_mappings'], $inst['_skip']);
            try {
                $items = $api->libraryItems();
            } catch (JackPlanApiError $e) {
                $this->r->log->error("[$name] Filme und Serien nicht abrufbar (theme.mp3): {$e->getMessage()} – ihr Stand bleibt");
                $failed[] = $name;
                foreach ($this->items as $k => $v) {
                    if (str_starts_with((string) $k, "$name|")) {
                        $keep[(string) $k] = $v;
                    }
                }
                continue;
            }
            foreach ($items as $item) {
                $key = "$name|" . jpStr($item['Id'] ?? '');
                if (!jpTrue($item['Id'] ?? '') || isset($seen[$key]) || ($item['LocationType'] ?? null) === 'Virtual') {
                    continue;
                }
                $seen[$key] = true;
                $prev = self::entry($this->items[$key] ?? null);
                $dc = jackRunThemeTime($item['DateCreated'] ?? null);
                if (!jpTrue($item['Path'] ?? '') && ($item['Type'] ?? null) === 'Series') {
                    // Plex: a series' folder only when it is needed (one question per series)
                    if ($prev !== null && $prev[2] !== 'wait' && !($dc !== null && $dc > $prev[3])
                            && ($prev[2] !== 'pool' || is_file($loc->onCache(jpJoin($prev[1], JACK_RUN_THEME))))) {
                        $keep[$key] = $prev;
                        continue;
                    }
                    try {
                        $item['Path'] = $api->themeFolder($item);
                    } catch (JackPlanApiError $e) {
                        $this->r->log->warning("[$name] Ordner der Serie " . jpStr($item['Id'] ?? '') . " nicht abrufbar: {$e->getMessage()}");
                        continue;
                    }
                }
                $got = $this->folder($loc, $item, false);
                if ($got === null) {
                    continue;
                }
                $rel = $got[0];
                if ($got[2]) {                      // flat in the library's folder (by its extension): no look
                    $keep[$key] = $prev !== null && $prev[0] === $rel ? $prev : [$rel, $got[1], 'flat', time(), 0];
                    continue;
                }
                if (self::due($rel, $dc, $loc, $prev)) {
                    $due[] = [$key, $loc, $item, $rel, $prev, $dc];
                } else {
                    $keep[$key] = $prev;
                }
            }
        }
        $this->items = $keep;
        return [$due, $failed];
    }

    /** plan(): what the next real run would look at (dry run, report) — no disk, no state written */
    public function plan(array $due): void
    {
        $shares = [];
        foreach ($due as $d) {
            $shares[explode('/', $d[3])[0]] = true;
        }
        $shares = jpSorted(array_map('strval', array_keys($shares)));
        $this->result += ['first' => $this->first, 'to_look' => count($due), 'shares' => $shares, 'on_pool' => $this->state['on_pool'] ?? ['files' => 0, 'bytes' => 0]];
        if ($due) {
            $this->r->log->info('theme.mp3: der nächste scharfe Lauf schaut ' . count($due) . ' Titel an'
                . ($this->first ? ' – zum ersten Mal: jeder Ordner wird einmal gelesen (weckt jede Disk dieser Shares einmal)' : '') . ' (' . implode(', ', $shares) . ')');
        }
    }

    /** collect(): the real run — look, then fetch; the state and $paths after it */
    public function collect(array $due, array $failed): void
    {
        $r = $this->r;
        $now = time();
        $looks = $fetch = $keys = [];
        $looked = $stopped = 0;
        $r->progress->planThemes(count($due));
        $r->status->phase('theme.mp3 (Array -> Cache)', count($due), 0);
        usort($due, fn (array $a, array $b): int => jpCmp($a[3], $b[3]));
        foreach ($due as [$key, $loc, $item]) {
            if ($r->halt()) {
                $stopped = count($due) - $looked;
                break;
            }
            [$rel, $folder, $flat] = $this->folder($loc, $item, true);
            $looked++;
            if ($flat) {
                $this->items[$key] = [$rel, $folder, 'flat', $now, 0];
                $r->progress->themeLooked();
                continue;
            }
            $trel = jpJoin($folder, JACK_RUN_THEME);
            if (!isset($looks["t$trel"])) {
                $cacheP = $loc->onCache($trel);
                if (is_file($cacheP)) {                  // on the pool already: the array is not looked at
                    $looks["t$trel"] = ['pool', (int) @filesize($cacheP)];
                    $this->paths[$cacheP] = true;
                } else {
                    $src = $loc->onArray($trel);
                    $size = is_file($src) ? @filesize($src) : false;
                    $looks["t$trel"] = $size !== false ? ['wait', (int) $size] : ['none', 0];
                    if ($looks["t$trel"][0] === 'wait') {
                        $fetch["t$trel"] = [$loc, $looks["t$trel"][1]];
                    }
                }
                $r->progress->themeLooked($looks["t$trel"][0] === 'wait' ? $looks["t$trel"][1] : null);
            } else {
                $r->progress->themeLooked();
            }
            [$t, $size] = $looks["t$trel"];
            $this->items[$key] = [$rel, $folder, $t, $now, $size];
            $keys["t$trel"][] = $key;
        }
        $r->progress->write();
        $fetched = $fetchedBytes = $failedN = $waiting = 0;
        $disks = $roots = [];
        $minFree = (float) $r->cfg['min_free_percent'];
        uksort($fetch, fn (string $a, string $b): int => jpCmp($a, $b));
        foreach ($fetch as $k => [$loc, $size]) {
            $trel = substr($k, 1);
            if ($r->halt()) {
                $r->progress->themeDone($trel, $size, false);
                $waiting++;
                continue;
            }
            [$ok, $disk] = $this->fetchOne($loc, $trel, $size, $roots, $minFree);
            if ($ok === true) {
                $fetched++;
                $fetchedBytes += $size;
                if ($disk !== null && $disk !== '?') {
                    $disks[$disk] = true;
                }
                foreach ($keys[$k] ?? [] as $key) {
                    $this->items[$key][2] = 'pool';
                }
            } elseif ($ok === null) {
                $waiting++;
            } else {
                $failedN++;
            }
        }
        $r->progress->themesEnd();
        $done = !$stopped && !$r->stopped;
        $onPool = $this->onPool();
        $state = ['v' => 1, 'first' => jpTrue($this->state['first'] ?? null) ? $this->state['first'] : $now, 'done' => !empty($this->state['done']) || ($done && !$failed),
                  'collected' => $done ? $now : ($this->state['collected'] ?? null), 'items' => (object) $this->items, 'on_pool' => $onPool];
        jackRunWriteThemes($r->paths->themes, $state);
        $disks = jpSorted(array_map('strval', array_keys($disks)));
        $this->result = array_merge($this->result, ['first' => $this->first, 'to_look' => count($due), 'looked' => $looked, 'fetched' => $fetched,
            'fetched_bytes' => $fetchedBytes, 'disks' => $disks, 'waiting' => $waiting, 'failed' => $failedN, 'servers_failed' => $failed, 'on_pool' => $onPool,
            'stopped' => (bool) ($stopped || $r->stopped)]);
        $r->log->info("theme.mp3: $looked von " . count($due) . " Titeln angeschaut, $fetched geholt (" . jpHuman($fetchedBytes) . ')'
            . ($disks ? ' von ' . count($disks) . ' Disk(s): ' . implode(', ', $disks) : '') . " – auf dem Cache: {$onPool['files']} (" . jpHuman($onPool['bytes']) . ')');
    }

    /** fetch_one(): a theme.mp3 Array -> Cache the fill's way — [true, disk] fetched, [null] waits (free space, share root), [false] an error */
    private function fetchOne(JackPlanLocations $loc, string $trel, int $size, array &$roots, float $minFree): array
    {
        $r = $this->r;
        $src = $loc->onArray($trel);
        $dst = $loc->onCache($trel);
        $share = explode('/', $trel)[0];
        if (!jackRunShareRoot($r->cfg, $loc->cache, $loc->array, $share, true, $r->log, $roots, $r->env)) {
            $r->log->warning("[theme.mp3 WARTET: Share-Wurzel] $trel");
            $r->progress->themeDone($trel, $size, false);
            return [null, null];
        }
        $after = jackPlanFreeAfter($loc->cache, $size, 0, $r->host);
        if (is_dir(jpJoin($loc->cache, $share))) {
            $after = min($after, jackPlanFreeAfter(jpJoin($loc->cache, $share), $size, 0, $r->host));
        }
        if ($after < $minFree) {
            $r->log->warning(sprintf('[theme.mp3 WARTET: Freiplatz] %s – danach nur %.1f %% frei, Minimum %s %%', $trel, $after, jpFloatG($minFree)));
            $r->progress->themeDone($trel, $size, false);
            return [null, null];
        }
        $disk = jackRunThemeDisk($r->cfg, $loc->user, $trel);
        try {
            jackRunMkdirLike(jpPyParent($dst), jpPyParent($src), $r->log);
        } catch (RuntimeException $e) {
            $r->log->error("Ordner nicht angelegt: {$e->getMessage()}");
            $r->progress->themeDone($trel, $size, false);
            return [false, null];
        }
        $r->progress->themeStart($trel, $size, $dst);
        $r->log->info(sprintf('[theme.mp3 -> CACHE] %-7s %10s  %s', $disk, jpHuman($size), $trel));
        [$rc, $out] = $r->rsyncCopyBlocking($r->rsyncCmd($src, $dst), $dst, $trel);
        if ($rc !== 0) {
            $r->log->error(jackRunVanished($rc, $out) ? JACK_RUN_VANISHED_SAID . $trel : "rsync-Fehler (Code $rc) bei $trel: " . trim($out));
            $r->progress->themeDone($trel, $size, false);
            return [false, null];
        }
        clearstatcache();
        $a = @filesize($dst);
        $b = @filesize($src);
        if ($a === false || $b === false || $a !== $b) {
            $r->log->error("Grösse stimmt nicht überein nach rsync, Quelle bleibt: $trel");
            $r->progress->themeDone($trel, $size, false);
            return [false, null];
        }
        if (!@unlink($src)) {
            $r->log->error("Quelle konnte nicht gelöscht werden (Datei liegt jetzt doppelt!): $src");
            $r->progress->themeDone($trel, $size, false);
            return [false, null];
        }
        $this->paths[$dst] = true;            // in the list, even when something goes wrong after it
        jackRunRemoveEmptyOnDisk($r->cfg, $trel, $disk, $r->log);
        jackRunRemoveEmptyParents(jpPyParent($src), $loc->array, $r->log);
        $r->progress->themeDone($trel, $size, true);
        return [true, $disk];
    }

    /** _on_pool(): what lies on the pool now (each theme.mp3 once, also when two servers name it); $paths too */
    private function onPool(): array
    {
        $cache = jpNorm($this->r->cfg['cache_path']);
        $sizes = [];
        foreach ($this->items as $v) {
            if (is_array($v) && ($v[2] ?? null) === 'pool') {
                $sizes[jpJoin(jpJoin($cache, (string) $v[1]), JACK_RUN_THEME)] = (int) $v[4];
            }
        }
        foreach ($sizes as $p => $_) {
            $this->paths[(string) $p] = true;
        }
        return ['files' => count($sizes), 'bytes' => array_sum($sizes)];
    }

    /** off(): switched off — the state goes (switched on, the first collection begins again); the section comes back by this run's cleanup */
    public function off(): void
    {
        try {
            if (file_exists($this->r->paths->themes)) {
                jackRunWriteThemes($this->r->paths->themes, ['v' => 1, 'items' => new stdClass(), 'off' => time()]);
            }
        } catch (Throwable $e) {
            $this->r->log->warning("theme.mp3: Stand nicht zurückgesetzt ({$e->getMessage()})");
        }
    }
}

// ===================================================================== the run (mediacache_run.py `Runner`)

final class JackRunner
{
    public bool $runMode;

    public int $toCache = 0;

    public int $toArray = 0;

    public int $copiedBytes = 0;

    public int $movedBackBytes = 0;

    public int $fillPlanned = 0;

    public int $cleanupPlanned = 0;

    public int $copied = 0;

    public int $movedBack = 0;

    public array $result;

    public bool $stopped = false;

    /** beyond Python: the array is being stopped — what is in flight ends at once */
    public bool $arrayStop = false;

    public JackRunProgress $progress;

    public JackRunStatus $status;

    public JackRunThemes $themes;

    public bool $keepThemes;

    public ?int $keep = null;

    public int $held = 0;

    public int $givenBack = 0;

    public int $givenBackBytes = 0;

    public int $waiting = 0;

    public int $waitingBytes = 0;

    public array $givenList = [];

    public bool $officePool;

    public int $fillFailures = 0;

    public array $failedFiles = ['fill' => 0, 'back' => 0, 'vanished' => 0];

    public array $failedList = [];

    public int $retried = 0;

    /** the plan as it was acted on (--plan-out): jackPlanDoc() of the decisions and the real fill */
    public ?array $planDoc = null;

    /** @var list<JackRunProc> what is in flight (the array stop ends them) */
    private array $procs = [];

    private int $arrayLooked = 0;

    private ?JackPlanHost $live = null;

    /** @param ?JackPlanHost $tape the comparison's tape (or null: the live servers) */
    public function __construct(public array $cfg, public string $mode, public array $env, public JackRunPaths $paths, public JackRunLog $log,
                                ?JackPlanHost $tape = null, public ?array $userFilter = null, public bool $showFiles = true,
                                public string $plexTv = JACK_PLAN_PLEX_TV, public string $varIni = JACK_RUN_VAR_INI, public string $moverPid = JACK_RUN_MOVER_PID)
    {
        $this->runMode = in_array($mode, ['run', 'release'], true);
        $this->result = ['mode' => $mode];
        $this->host = new JackRunHost($tape ?? $this->liveHost(), $env);
        $this->progress = new JackRunProgress($mode, $this->runMode && $paths->progress !== '', $paths->progress);
        $this->keep = jackPlanKeepBytes($env);
        $watchedOnly = ($env['MEDIACACHE_WATCHED_ONLY'] ?? '') === '1';
        $giveBackFree = ($env['MEDIACACHE_GIVE_BACK_FREE'] ?? '') === '1' && !$watchedOnly && (float) $cfg['min_free_percent'] > 0;
        $this->officePool = $giveBackFree || $watchedOnly;
        $this->status = new JackRunStatus($mode, $log, $this->runMode, (int) $cfg['status_log_interval'], $paths->status);
        $this->themes = new JackRunThemes($this);
        $this->keepThemes = $this->themes->on && !($mode === 'release' && ($env['MEDIACACHE_RELEASE_THEMES'] ?? '') === '1');
    }

    public JackRunHost $host;

    /** The live servers (the theme.mp3 collector, a run without a tape) */
    public function liveHost(): JackPlanHost
    {
        return $this->live ??= new JackPlanLive($this->env);
    }

    private function say(): Closure
    {
        return $this->log->say();
    }

    // ----------------------------------------------------------------- stop, array

    /** halt(): a stop asked (MEDIACACHE_STOP; only in a real run; said once) — or the array stopping */
    public function halt(): bool
    {
        if (!$this->stopped && $this->runMode && $this->paths->stop !== '') {
            clearstatcache(true, $this->paths->stop);
            if (file_exists($this->paths->stop)) {
                $this->stopped = true;
                $this->log->warning('Anhalten verlangt (MEDIACACHE_STOP – das Office: der Unraid-Mover startete, oder auf Wunsch): '
                    . 'nach der aktuellen Datei ist Schluss, was noch auf dem Cache liegt, bleibt geschützt');
            }
        }
        return $this->stopped || $this->arrayStopping();
    }

    /** var.ini: Stopping or Stopped — looked at once a second at most; then the run ends at once (stopped) */
    public function arrayStopping(bool $now = false): bool
    {
        if ($this->arrayStop || !$this->runMode) {
            return $this->arrayStop;
        }
        $t = hrtime(true);
        if (!$now && $t - $this->arrayLooked < 1_000_000_000) {
            return false;
        }
        $this->arrayLooked = $t;
        $ini = @parse_ini_file($this->varIni, false, INI_SCANNER_RAW);
        $fs = is_array($ini) ? trim((string) ($ini['fsState'] ?? ''), '"') : '';
        if (in_array($fs, ['Stopping', 'Stopped'], true)) {
            $this->arrayStop = $this->stopped = true;
            $this->log->warning('Das Array wird gestoppt – was gerade kopiert oder verschoben wird, endet jetzt (die Quelle bleibt), '
                . 'was noch auf dem Cache liegt, bleibt geschützt');
            foreach ($this->procs as $p) {
                $p->kill();
            }
        }
        return $this->arrayStop;
    }

    // ----------------------------------------------------------------- rsync

    public function rsyncCmd(string $src, string $dst): array
    {
        return array_merge(['rsync'], array_map('strval', (array) $this->cfg['rsync_args']), jpTrue($this->cfg['copy_progress']) ? ['--info=progress2'] : [], [$src, $dst]);
    }

    /** run_rsync()'s start: output read as it comes; progress lines go to $onProgress, the rest is the message */
    public function rsyncStart(array $cmd, ?Closure $onProgress = null): ?JackRunProc
    {
        $p = JackRunProc::start($cmd, null, true, function (string $line) use ($onProgress): void {
            if ($onProgress !== null && preg_match(JACK_RUN_PROGRESS_RE, trim($line), $m)) {
                $onProgress((int) (preg_replace('/\D/', '', $m[1]) ?: 0), $m[3], $m[4]);
            }
        });
        if ($p !== null) {
            $this->procs[] = $p;
        }
        return $p;
    }

    /** The message of a finished rsync: its lines without the progress lines */
    public static function rsyncMessages(JackRunProc $p): string
    {
        $out = [];
        foreach (explode("\n", $p->out) as $line) {
            $line = trim($line);
            if ($line !== '' && !preg_match(JACK_RUN_PROGRESS_RE, $line)) {
                $out[] = $line;
            }
        }
        return implode("\n", $out);
    }

    private function forget(JackRunProc $p): void
    {
        $this->procs = array_values(array_filter($this->procs, fn (JackRunProc $x): bool => $x !== $p));
    }

    /** rsync_copy() for one file at a time (the rsync cleanup, theme.mp3): once, and once more when its temporary file vanished */
    public function rsyncCopyBlocking(array $cmd, string $dst, string $rel, ?Closure $onProgress = null): array
    {
        for ($try = 0; ; $try++) {
            $p = $this->rsyncStart($cmd, $onProgress);
            if ($p === null) {
                return [127, 'rsync konnte nicht gestartet werden'];
            }
            while ($p->poll()) {
                $this->arrayStopping();
                $this->status->tick();
                usleep(100_000);
            }
            $this->forget($p);
            $rc = $p->exit();
            $out = self::rsyncMessages($p);
            clearstatcache(true, $dst);
            if ($try === 0 && jackRunVanished($rc, $out) && !file_exists($dst) && !$this->halt()) {
                $this->log->warning("Die Kopie von $rel verschwand im Ziel vor dem Umbenennen (ein anderes Programm hat sie gelöscht, "
                    . 'z. B. ein Abgleich mit --delete) – noch ein Versuch');
                $this->retried++;
                continue;
            }
            return [$rc, $out];
        }
    }

    /** failed(): a file not copied or not brought back — counted per file; «vanished» apart */
    public function failed(string $phase, string $rel, ?string $why = null): void
    {
        $this->failedFiles[$phase]++;
        if ($why === 'vanished') {
            $this->failedFiles['vanished']++;
        }
        if (count($this->failedList) < 20) {
            $this->failedList[] = ['phase' => $phase, 'rel' => $rel] + ($why !== null ? ['why' => $why] : []);
        }
    }

    // ----------------------------------------------------------------- the move binary

    /**
     * run_mover() with track_mover(): the paths piped into Unraid's move binary; every 2 s which sources are gone (the file in work, its
     * progress); [rc, stdout, stderr]. $items: [source, rel, size, targets(rel)] in the binary's order.
     */
    private function runMover(string $mover, array $paths, array $items, string $label): array
    {
        $cmd = [$mover];
        if ((int) $this->cfg['mover_debug_level'] > 0) {
            $cmd = array_merge($cmd, ['-d', (string) (int) $this->cfg['mover_debug_level']]);
        }
        $this->log->debug('Mover-Aufruf: ' . implode(' ', $cmd) . ' mit ' . count($paths) . ' Pfaden');
        $p = JackRunProc::start($cmd, implode("\n", $paths) . "\n", false);
        if ($p === null) {
            $this->log->error("move-Binary konnte nicht gestartet werden ($mover)");
            return [1, '', 'start failed'];
        }
        $this->procs[] = $p;
        $count = count($items);
        $pending = [];
        foreach ($items as $i => $it) {
            $pending[$i + 1] = $it;
        }
        $current = null;
        $skipped = [];
        $lastDone = 0;
        $tCurrent = microtime(true);
        $next = hrtime(true) + 2_000_000_000;
        while (true) {
            $running = $p->poll();
            if ($running && hrtime(true) < $next) {
                $this->arrayStopping();
                $this->status->tick();
                usleep(100_000);
                continue;
            }
            $next = hrtime(true) + 2_000_000_000;
            $finished = !$running;
            clearstatcache();
            foreach ($pending as $i => [$src, $rel, $size]) {
                if (file_exists($src)) {
                    continue;
                }
                $key = "m$i";
                if ($current !== $key) {
                    $this->status->jobStart($key, $rel, 'move', $size, $i);
                }
                $this->status->jobEnd($key, true);
                $secs = $current === $key ? microtime(true) - $tCurrent : 0;
                $this->log->info(sprintf('[%d/%d] %s fertig %10s', $i, $count, $label, jpHuman($size)) . ($secs ? sprintf(' in %.0f s', $secs) : '') . "  $rel");
                if ($current === $key) {
                    $current = null;
                }
                $lastDone = max($lastDone, $i);
                unset($pending[$i]);
            }
            if ($finished) {
                foreach ($pending as $i => [, $rel, $size]) {
                    $key = "m$i";
                    if (isset($skipped[$key])) {
                        continue;
                    }
                    if ($current !== $key) {
                        $this->status->jobStart($key, $rel, 'move', $size, $i);
                    }
                    $this->status->jobEnd($key, false);
                }
                break;
            }
            if (!$pending) {
                // all moved, the binary still ends: wait for it
                while ($p->poll()) {
                    $this->arrayStopping();
                    usleep(100_000);
                }
                break;
            }
            $i = null;
            foreach ($pending as $j => $_) {
                if ($j > $lastDone) {
                    $i = $j;
                    break;
                }
            }
            $i ??= array_key_first($pending);
            [, $rel, $size, $targets] = $pending[$i];
            $key = "m$i";
            if ($current !== $key) {
                if ($current !== null) {
                    $skipped[$current] = true;
                    $this->status->jobEnd($current, false);
                }
                $current = $key;
                $tCurrent = microtime(true);
                $this->status->jobStart($key, $rel, 'move', $size, $i);
                $this->log->info(sprintf('[%d/%d] %s läuft %10s  %s', $i, $count, $label, jpHuman($size), $rel));
            }
            $done = 0;
            foreach ($targets($rel) as $t) {
                if (is_file($t)) {
                    $done = max($done, (int) @filesize($t));
                }
            }
            $this->status->jobProgress($key, $done);
        }
        $this->forget($p);
        foreach (explode("\n", trim($p->out . $p->stderr())) as $line) {
            if ($line !== '') {
                $this->log->debug("mover: $line");
            }
        }
        if ($p->exit() !== 0) {
            $this->log->error('move-Binary endete mit Code ' . $p->exit());
        }
        return [$p->exit(), $p->out, $p->stderr()];
    }

    // ----------------------------------------------------------------- back to the array

    /** cleanup_with_rsync(): Cache -> Array by rsync into /mnt/user0 (shfs picks the disk), the source deleted only after the check */
    private function cleanupWithRsync(JackPlanLocations $loc, array $candidates, array $sizes, array &$protected, array $backWhy): void
    {
        $failures = 0;
        $this->status->phase('Cleanup (Cache -> Array, rsync)', count($candidates), array_sum($sizes));
        foreach ($candidates as $n => $p) {
            $i = $n + 1;
            $size = (int) ($sizes["c$p"] ?? 0);
            if ($this->halt()) {
                $protected["p$p"] = $p;           // stays on the pool and in the list
                $this->progress->dropBack($size);
                continue;
            }
            $rel = (string) jpRelative($p, $loc->cache);
            $dst = $loc->onArray($rel);
            if ($failures >= 3) {
                $this->log->error('Drei rsync-Fehler in Folge – Cleanup abgebrochen, Ursache im Log prüfen');
                $protected["p$p"] = $p;
                $this->progress->dropBack($size);
                continue;
            }
            clearstatcache(true, $dst);
            if (file_exists($dst)) {
                $this->log->warning("Ziel existiert schon auf dem Array (Duplikat?), Datei bleibt auf dem Cache: $rel");
                $protected["p$p"] = $p;
                $this->progress->dropBack($size);
                continue;
            }
            try {
                jackRunMkdirLike(jpPyParent($dst), jpPyParent($p), $this->log);
            } catch (RuntimeException $e) {
                $this->log->error("Ordner nicht angelegt: {$e->getMessage()}");
                $this->failed('back', $rel);
                $protected["p$p"] = $p;
                $this->progress->dropBack($size);
                continue;
            }
            $key = "$i:$rel";
            $this->log->info(sprintf('[%d/%d] -> ARRAY %10s  %s', $i, count($candidates), jpHuman($size), $rel));
            $this->status->jobStart($key, $rel, 'Array', $size, $i);
            $cmd = $this->rsyncCmd($p, $dst);
            $this->log->debug('rsync-Aufruf (Cleanup): ' . implode(' ', $cmd));
            $this->progress->start('back', $rel, $size, $dst, null, $backWhy["c$p"] ?? null);
            [$rc, $out] = $this->rsyncCopyBlocking($cmd, $dst, $rel, fn (int $done, string $speed, string $eta) => $this->status->jobProgress($key, $done, $speed, $eta));
            if ($rc !== 0) {
                $failures++;
                if ($this->arrayStop) {
                    $this->log->warning("Zurückbringen beim Stoppen des Arrays abgebrochen, Datei bleibt auf dem Cache: $rel");
                } elseif (jackRunVanished($rc, $out)) {
                    $this->log->error(JACK_RUN_VANISHED_SAID . $rel);
                    $this->failed('back', $rel, 'vanished');
                } else {
                    $this->log->error("rsync-Fehler (Code $rc) bei $rel: " . trim($out));
                    $this->failed('back', $rel);
                }
                $protected["p$p"] = $p;
                $this->status->jobEnd($key, false);
                $this->progress->dropBack($size);
                continue;
            }
            $failures = 0;
            clearstatcache();
            $a = @filesize($dst);
            $b = @filesize($p);
            if ($a === false || $b === false || $a !== $b) {
                $this->log->error("Grösse stimmt nicht überein nach rsync, Datei bleibt auf dem Cache: $rel");
                $this->failed('back', $rel);
                $protected["p$p"] = $p;
                $this->status->jobEnd($key, false);
                $this->progress->dropBack($size);
                continue;
            }
            if (!@unlink($p)) {
                $this->log->error("Quelle konnte nicht gelöscht werden (Datei liegt jetzt doppelt!): $p");
                $this->failed('back', $rel);
                $protected["p$p"] = $p;
                $this->status->jobEnd($key, false);
                $this->progress->dropBack($size);
                continue;
            }
            $this->status->jobEnd($key, true);
            $this->movedBack++;
            $this->movedBackBytes += $size;
            $this->progress->done($size);
            jackRunRemoveEmptyParents(jpPyParent($p), $loc->cache, $this->log);
        }
    }

    /** log_grouped(): one head line per series or film folder, its files indented below */
    private function logGrouped(string $label, array $listing): void
    {
        $groups = [];
        foreach ($listing as [$rel, $size]) {
            $groups[jackPlanGroupKey($rel)][] = [$rel, $size];
        }
        foreach ($groups as $key => $items) {
            $this->log->info("[$label] $key: " . count($items) . ' Datei' . (count($items) !== 1 ? 'en' : '') . ', ' . jpHuman(array_sum(array_column($items, 1))));
            foreach ($items as [$rel, $size]) {
                $this->log->info(sprintf('      %10s  %s', jpHuman($size), $rel));
            }
        }
    }

    /** cleanup(): the candidates the decisions chose (jackPlanCleanup()) -> the move binary (or rsync), Cache -> Array */
    private function cleanup(JackPlanLocations $loc, array $candidates, int $toArray, array $sizes, array &$protected, array $backWhy): void
    {
        $this->toArray = $toArray;
        $this->cleanupPlanned = count($candidates);
        if (!$candidates) {
            return;
        }
        $listing = array_map(fn (string $p): array => [(string) jpRelative($p, $loc->cache), (int) ($sizes["c$p"] ?? 0)], $candidates);
        $this->logGrouped($this->runMode ? 'MOVE -> ARRAY' : 'PLAN: -> ARRAY', $listing);
        $this->log->info('Cleanup: ' . count($candidates) . ' Dateien (' . jpHuman($toArray) . ') vom Cache aufs Array');
        if (!$this->runMode) {
            return;
        }
        $this->progress->planBack(count($candidates), $toArray);
        $this->progress->phase('back');
        if ($this->halt()) {
            foreach ($candidates as $p) {
                $protected["p$p"] = $p;
                $this->progress->dropBack((int) ($sizes["c$p"] ?? 0));
            }
            $this->progress->write();
            return;
        }
        if ($this->cfg['cleanup_tool'] === 'rsync') {
            $this->log->info('Cleanup-Werkzeug: rsync nach ' . $loc->array);
            $this->cleanupWithRsync($loc, $candidates, $sizes, $protected, $backWhy);
            return;
        }
        $mover = jackRunMoverBin($this->cfg);
        if ($mover === null) {
            $this->log->error('Kein Unraid move-Binary gefunden (mover_bin in der Config setzen) – Cleanup übersprungen');
            foreach ($candidates as $p) {
                $protected["p$p"] = $p;
                $this->progress->dropBack((int) ($sizes["c$p"] ?? 0));
            }
            $this->progress->write();
            return;
        }
        $this->status->phase('Cleanup (Cache -> Array, move-Binary)', count($candidates), $toArray);
        $tracked = array_map(fn (string $p): array => [$p, (string) jpRelative($p, $loc->cache), (int) ($sizes["c$p"] ?? 0),
            fn (string $rel): array => jackRunOnDisk($this->cfg, $rel)], $candidates);
        // with a progress or a stop file (the office) one file per call: the progress names the file in work, «stop after this file» holds
        $perFile = $this->paths->progress !== '' || $this->paths->stop !== '';
        $batches = $perFile ? array_map(fn (array $t): array => [$t], $tracked) : [$tracked];
        $stdout = $stderr = '';
        $asked = [];
        foreach ($batches as $batch) {
            if ($perFile && $this->halt()) {
                break;
            }
            $paths = array_map(fn (array $t): string => $t[0], $batch);
            if ($perFile) {
                $this->progress->start('back', $batch[0][1], $batch[0][2], '', null, $backWhy['c' . $paths[0]] ?? null);
            }
            [, $out, $err] = $this->runMover($mover, $paths, $batch, '-> ARRAY');
            $stdout .= "$out\n";
            $stderr .= "$err\n";
            foreach ($paths as $p) {
                $asked["c$p"] = true;
            }
            if ($perFile) {
                $p = $paths[0];
                clearstatcache(true, $p);
                if (file_exists($p)) {
                    $this->progress->dropBack((int) ($sizes["c$p"] ?? 0));
                } else {
                    $this->progress->done((int) ($sizes["c$p"] ?? 0));
                }
            }
            if ($this->arrayStop) {
                break;
            }
        }
        $messages = jackRunMoverMessages($stdout . "\n" . $stderr, $candidates);
        $leftBy = [];
        clearstatcache();
        foreach ($candidates as $p) {
            if (!isset($asked["c$p"])) {           // stopped before its turn: stays on the pool and in the list
                $protected["p$p"] = $p;
                $this->progress->dropBack((int) ($sizes["c$p"] ?? 0));
                continue;
            }
            if (file_exists($p)) {
                $protected["p$p"] = $p;
                if (!$perFile) {
                    $this->progress->dropBack((int) ($sizes["c$p"] ?? 0));
                }
                $reason = $messages["c$p"] ?? 'keine Meldung vom Mover (in Benutzung oder Ziel existiert bereits)';
                $leftBy[$reason][] = $p;
            } else {
                $this->movedBack++;
                $this->movedBackBytes += (int) ($sizes["c$p"] ?? 0);
                if (!$perFile) {
                    $this->progress->done((int) ($sizes["c$p"] ?? 0));
                }
                jackRunRemoveEmptyParents(jpPyParent($p), $loc->cache, $this->log);
            }
        }
        foreach ($leftBy as $reason => $paths) {
            $reason = (string) $reason;
            if ($this->arrayStop) {
                $this->log->warning(count($paths) . ' Dateien nicht verschoben – das Array wird gestoppt');
                continue;
            }
            $this->log->warning(count($paths) . " Dateien nicht verschoben – Mover: $reason");
            foreach (array_slice($paths, 0, 3) as $p) {
                $this->log->warning("      $p");
            }
            if (count($paths) > 3) {
                $this->log->warning('      … und ' . (count($paths) - 3) . ' weitere (alle im DEBUG-Log)');
            }
            foreach (array_slice($paths, 3) as $p) {
                $this->log->debug("      $p");
            }
            if (str_contains($reason, 'No space left')) {
                $hits = array_filter(explode("\n", $stdout), fn (string $l): bool => str_contains($l, 'create_parent') && str_contains($l, $loc->cache));
                $this->log->error($hits ? 'Der Mover wollte das Ziel AUF DEM CACHE anlegen – für diesen Share ist der Cache sein Ziel (Share-Einstellung/Standardwerte). '
                    . 'Entweder Share auf Cache: Yes (Mover Cache → Array) stellen oder cleanup_tool=rsync verwenden.'
                    : 'Ziel voll: der Mover findet auf dem Array keinen Platz (Share-Einstellung «Minimum free space», Allocation/Split-Level oder eingeschlossene Disks prüfen).');
            }
        }
    }

    // ----------------------------------------------------------------- to the pool

    /**
     * fill(): the plan's files from the array to the pool, in its order (round robin over the persons by rank) — rsync (the source deleted
     * only after the check) or the move binary. First planned (the free space with every planned copy), then copied, one by one or in
     * parallel per disk. Returns what the fill planned, in the plan's shape.
     */
    private function fill(JackPlanLocations $loc, array $files, callable $playing): array
    {
        $cfg = $this->cfg;
        $fromDisk = $cfg['array_source'] === 'disk';
        $minFree = (float) $cfg['min_free_percent'];
        $useMover = $cfg['fill_tool'] === 'mover';
        $label = $useMover ? 'MOVE' : 'COPY';
        $jobs = $rels = $dropped = [];
        $pending = 0;
        $lastGroup = null;
        $roots = [];
        foreach ($files as $f) {
            if ($f->onCache) {
                continue;
            }
            if ($playing($f->rel)) {
                $this->log->info("[SKIP: läuft gerade] $f->rel");
                $dropped[] = $f->rel;
                $this->progress->drop($f);
                continue;
            }
            $share = explode('/', $f->rel)[0];
            $shareRoot = jpJoin($loc->cache, $share);
            try {
                $rootOk = jackRunShareRoot($cfg, $loc->cache, $loc->array, $share, true, $this->log, $roots, $this->env);
            } catch (RuntimeException $e) {
                $this->log->error("Share-Wurzel nicht angelegt: {$e->getMessage()}");
                $rootOk = $roots["s$share"] = false;
            }
            if (!$rootOk) {
                $this->log->warning("[SKIP: Share-Wurzel] $f->rel");
                $dropped[] = $f->rel;
                $this->progress->drop($f);
                continue;
            }
            $after = jackPlanFreeAfter($loc->cache, $f->size, -$pending, $this->host);
            if (is_dir($shareRoot)) {
                $after = min($after, jackPlanFreeAfter($shareRoot, $f->size, -$pending, $this->host));
            }
            if ($after < $minFree) {
                $this->log->warning(sprintf('[SKIP: Freiplatz] %s (%s) – danach nur %.1f %% frei, Minimum %s %% (geplante Kopien eingerechnet)',
                    $f->rel, jpHuman($f->size), $after, jpFloatG($minFree)));
                $this->waiting++;
                $this->waitingBytes += $f->size;
                $dropped[] = $f->rel;
                $this->progress->drop($f);
                continue;
            }
            $disks = jackRunOnDisk($cfg, $f->rel);
            if (count($disks) > 1) {
                $this->log->warning('Datei liegt auf mehreren Disks (Duplikat im Array!): ' . implode(', ', $disks) . ' – nehme die erste');
            }
            $src = $fromDisk && $disks ? $disks[0] : $loc->onArray($f->rel);
            if (!is_file($src)) {
                $this->log->warning("Quelle nicht gefunden: $src");
                $dropped[] = $f->rel;
                $this->progress->drop($f);
                continue;
            }
            $disk = $disks ? jackPlanDiskName($disks[0], $cfg) : '?';
            $this->toCache += $f->size;
            $this->fillPlanned++;
            $this->held += $f->size;
            $key = jackPlanGroupKey($f->rel);
            if ($key !== $lastGroup) {
                $this->log->info("[$label -> CACHE] $key   (" . jpStr($f->title) . ')');
                $lastGroup = $key;
            }
            $this->log->info(sprintf('      %10s  %-7s %s', jpHuman($f->size), $disk, $f->rel));
            $pending += $f->size;
            $rels[] = $f->rel;
            $jobs[] = [$f, $src, $disk];
        }
        $planned = ['rels' => $rels, 'dropped' => $dropped];
        $perDisk = (int) $cfg['parallel_per_disk'];
        $total = (int) $cfg['parallel_total'];
        if (!$useMover && ($perDisk > 1 || $total !== 1)) {
            $nDisks = count(array_unique(array_map(fn (array $j): string => $j[2], $jobs))) ?: 1;
            $limit = $total === 0 ? $perDisk * $nDisks : min($total, $perDisk * $nDisks);
            $this->log->info("Parallel: $perDisk pro Disk, " . ($total === 0 ? 'ohne Gesamtlimit' : "höchstens $total gesamt") . " – $nDisks Quell-Disk(s), also bis $limit Kopien gleichzeitig");
        }
        if (!$jobs) {
            return $planned;
        }
        if ($useMover) {
            if (!$this->halt()) {
                $this->fillWithMover($loc, $jobs);
            }
            return $planned;
        }
        $this->status->phase('Befüllen (Array -> Cache)', count($jobs), array_sum(array_map(fn (array $j): int => $j[0]->size, $jobs)));
        $this->copyAll($loc, $jobs, $perDisk, $total, $perDisk === 1 && $total === 1);
        $this->log->info($this->status->summary());
        return $planned;
    }

    /**
     * copy_parallel() and the one-by-one loop as one: per source disk a queue, at most $perDisk copies each and $total in all (0 = no limit);
     * a stop asked: no new copy begins, those in flight end; three rsync errors in a row: likewise. The array stopping ends them at once.
     */
    private function copyAll(JackPlanLocations $loc, array $jobs, int $perDisk, int $total, bool $oneByOne): void
    {
        $queues = [];
        foreach ($jobs as $n => [$f, $src, $disk]) {
            $queues[$disk][] = [$f, $src, $disk, $n + 1];
        }
        $count = count($jobs);
        $flight = [];
        $stop = false;
        $perDiskNow = [];
        while (true) {
            // start what may start: the disk whose next file comes first in the plan
            while (!$stop) {
                if ($total > 0 && count($flight) >= $total) {
                    break;
                }
                $pick = null;
                foreach ($queues as $disk => $q) {
                    if ($q && ($perDiskNow[$disk] ?? 0) < $perDisk && ($pick === null || $q[0][3] < $queues[$pick][0][3])) {
                        $pick = $disk;
                    }
                }
                if ($pick === null) {
                    break;
                }
                if ($this->halt()) {
                    $stop = true;
                    break;
                }
                if ($oneByOne && $this->fillFailures >= 3) {
                    $this->log->error('Drei rsync-Fehler in Folge – Befüllen abgebrochen, Ursache im Log prüfen');
                    $stop = true;
                    break;
                }
                $job = array_shift($queues[$pick]);
                $c = $this->copyStart($loc, $job, $count);
                if ($c === null) {
                    continue;               // ended before a copy began (its folder could not be made)
                }
                $flight[] = $c;
                $perDiskNow[$pick] = ($perDiskNow[$pick] ?? 0) + 1;
            }
            if (!$flight) {
                break;
            }
            $busy = false;
            foreach ($flight as $i => $c) {
                if ($c['proc'] !== null && $c['proc']->poll()) {
                    $busy = true;
                    continue;
                }
                if ($this->copyEnd($loc, $flight[$i], $count)) {
                    $busy = true;           // tried once more: still in flight
                    continue;
                }
                $perDiskNow[$c['disk']]--;
                unset($flight[$i]);
                if (!$oneByOne && $this->fillFailures >= 3 && !$stop) {
                    $stop = true;
                    $this->log->error('Drei rsync-Fehler in Folge – Befüllen abgebrochen (laufende Kopien werden fertig), Ursache im Log prüfen');
                }
            }
            $flight = array_values($flight);
            $this->arrayStopping();
            $this->status->tick();
            if ($busy) {
                usleep(100_000);
            }
        }
    }

    /** copy_one()'s start: the folder like the source's, the progress, rsync started; null when it ended right away */
    private function copyStart(JackPlanLocations $loc, array $job, int $count): ?array
    {
        [$f, $src, $disk, $index] = $job;
        $dst = $loc->onCache($f->rel);
        $key = "$index:$f->rel";
        $this->log->info(sprintf('[%d/%d] Start  %-7s %10s  %s', $index, $count, $disk, jpHuman($f->size), $f->rel));
        $this->status->jobStart($key, $f->rel, $disk, $f->size, $index);
        $c = ['f' => $f, 'src' => $src, 'disk' => $disk, 'index' => $index, 'dst' => $dst, 'key' => $key, 't0' => microtime(true), 'try' => 0, 'proc' => null];
        try {
            jackRunMkdirLike(jpPyParent($dst), jpPyParent($src), $this->log);
        } catch (RuntimeException $e) {
            $this->log->error("Ordner nicht angelegt, Quelle bleibt auf dem Array: {$e->getMessage()}");
            $this->failed('fill', $f->rel);
            $this->status->jobEnd($key, false);
            $this->progress->drop($f);
            return null;
        }
        $c['cmd'] = $this->rsyncCmd($src, $dst);
        $this->log->debug('rsync-Aufruf: ' . implode(' ', $c['cmd']));
        $this->progress->start('fill', $f->rel, $f->size, $dst, $f);
        $c['proc'] = $this->rsyncStart($c['cmd'], fn (int $done, string $speed, string $eta) => $this->status->jobProgress($key, $done, $speed, $eta));
        return $c;
    }

    /** copy_one()'s end: once more when its temporary file vanished (true: in flight again), else the check, the source gone, tidied */
    private function copyEnd(JackPlanLocations $loc, array &$c, int $count): bool
    {
        $f = $c['f'];
        $p = $c['proc'];
        $rc = $p === null ? 127 : $p->exit();
        $out = $p === null ? 'rsync konnte nicht gestartet werden' : self::rsyncMessages($p);
        if ($p !== null) {
            $this->forget($p);
        }
        clearstatcache(true, $c['dst']);
        if ($c['try'] === 0 && jackRunVanished($rc, $out) && !file_exists($c['dst']) && !$this->halt()) {
            $this->log->warning("Die Kopie von $f->rel verschwand im Ziel vor dem Umbenennen (ein anderes Programm hat sie gelöscht, "
                . 'z. B. ein Abgleich mit --delete) – noch ein Versuch');
            $this->retried++;
            $c['try'] = 1;
            $key = $c['key'];
            $c['proc'] = $this->rsyncStart($c['cmd'], fn (int $done, string $speed, string $eta) => $this->status->jobProgress($key, $done, $speed, $eta));
            return true;
        }
        $ok = false;
        $src = $c['src'];
        $dst = $c['dst'];
        if ($rc !== 0) {
            if ($this->arrayStop) {
                $this->log->warning("Kopie beim Stoppen des Arrays abgebrochen, Quelle bleibt auf dem Array: $f->rel");
            } else {
                $gone = jackRunVanished($rc, $out);
                $this->fillFailures++;
                $this->failed('fill', $f->rel, $gone ? 'vanished' : null);
                if ($gone) {
                    $this->log->error(JACK_RUN_VANISHED_SAID . $f->rel);
                } else {
                    $this->log->error("rsync-Fehler (Code $rc) bei $f->rel: " . trim($out));
                    $this->log->error('Quelle bleibt auf dem Array; Ziel prüfen, sonst liegt die Datei doppelt');
                }
            }
        } else {
            $this->fillFailures = 0;
            clearstatcache();
            $a = @filesize($dst);
            $b = @filesize($src);
            if ($a === false || $b === false || $a !== $b) {
                $this->log->error("Grösse stimmt nicht überein nach rsync, Quelle bleibt: $f->rel");
                $this->failed('fill', $f->rel);
            } elseif (!@unlink($src)) {
                $this->log->error("Quelle konnte nicht gelöscht werden (Datei liegt jetzt doppelt!): $src");
                $this->failed('fill', $f->rel);
            } else {
                $this->copied++;
                $this->copiedBytes += $f->size;
                $f->onCache = true;
                $ok = true;
                if ($src === $loc->onArray($f->rel)) {     // through /mnt/user0: on the disk it lay on too
                    jackRunRemoveEmptyOnDisk($this->cfg, $f->rel, $c['disk'], $this->log);
                }
                jackRunRemoveEmptyParents(jpPyParent($src), jackRunDiskRoot($src, $this->cfg, $loc->array, $src !== $loc->onArray($f->rel)), $this->log);
            }
        }
        $this->status->jobEnd($c['key'], $ok);
        if ($ok) {
            $this->progress->done($f->size, $f);
            $secs = microtime(true) - $c['t0'];
            $this->log->info(sprintf('[%d/%d] Fertig %-7s %10s in %.0f s (%s/s)  %s', $c['index'], $count, $c['disk'], jpHuman($f->size), $secs,
                jpHuman($secs > 0 ? $f->size / $secs : 0), $f->rel));
        } else {
            $this->progress->drop($f);
        }
        return false;
    }

    /** fill_with_mover(): Array -> Cache through the move binary (one call for all), then the result checked */
    private function fillWithMover(JackPlanLocations $loc, array $jobs): void
    {
        $mover = jackRunMoverBin($this->cfg);
        if ($mover === null) {
            $this->log->error('Kein Unraid move-Binary gefunden (mover_bin in der Config setzen) – nichts kopiert');
            return;
        }
        $bytes = array_sum(array_map(fn (array $j): int => $j[0]->size, $jobs));
        $this->log->info('Befüllen: ' . count($jobs) . ' Dateien (' . jpHuman($bytes) . ') per move-Binary Array -> Cache');
        $this->status->phase('Befüllen (Array -> Cache, move-Binary)', count($jobs), $bytes);
        $tracked = array_map(fn (array $j): array => [$j[1], $j[0]->rel, $j[0]->size, fn (string $rel): array => [$loc->onCache($rel)]], $jobs);
        $onDisk = [];
        foreach ($jobs as [$f, $src]) {
            if ($src === $loc->onArray($f->rel)) {
                $d = jackRunOnDisk($this->cfg, $f->rel);
                $onDisk["f$f->rel"] = $d ? jackPlanDiskName($d[0], $this->cfg) : '?';
            }
        }
        $this->runMover($mover, array_map(fn (array $j): string => $j[1], $jobs), $tracked, '-> CACHE');
        clearstatcache();
        foreach ($jobs as [$f, $src]) {
            $dst = $loc->onCache($f->rel);
            if (file_exists($dst) && !file_exists($src)) {
                $this->copied++;
                $this->copiedBytes += $f->size;
                $this->progress->done($f->size, $f);
                $f->onCache = true;
                if (isset($onDisk["f$f->rel"])) {
                    jackRunRemoveEmptyOnDisk($this->cfg, $f->rel, $onDisk["f$f->rel"], $this->log);
                }
                jackRunRemoveEmptyParents(jpPyParent($src), jackRunDiskRoot($src, $this->cfg, $loc->array, $src !== $loc->onArray($f->rel)), $this->log);
            } elseif (file_exists($dst) && file_exists($src)) {
                $this->log->error("Datei liegt jetzt doppelt (Quelle nicht entfernt): $f->rel");
                $this->progress->drop($f);
            } else {
                $this->log->warning("Nicht auf den Cache verschoben (in Benutzung, oder das Binary kennt keinen Pool für diesen Share?): $f->rel");
                $this->progress->drop($f);
            }
        }
        if ($this->copied === 0) {
            $this->log->warning('Das move-Binary hat nichts auf den Cache verschoben – fill_tool=rsync verwenden oder mover_debug_level=1 setzen und Log prüfen');
        }
    }

    // ----------------------------------------------------------------- the report

    private static function location(JackPlanGroup $g): string
    {
        $on = count(array_filter($g->files, fn (JackPlanFile $f): bool => $f->onCache));
        return $on === count($g->files) ? 'CACHE' : ($on === 0 ? 'ARRAY' : 'TEILS');
    }

    /** render_groups(): films one by one, series as a block with their episodes in order */
    private static function renderGroups(array $groups, string $mark, bool $showFiles, string $indent = '    '): void
    {
        $chains = [];
        foreach ($groups as $g) {
            $chains[$g->chain][] = $g;
        }
        $nFiles = fn (JackPlanGroup $g): string => count($g->files) . ' Datei' . (count($g->files) !== 1 ? 'en' : '');
        foreach ($chains as $chainGroups) {
            $first = $chainGroups[0];
            if (!jpTrue($first->series) && $first->series === null) {
                foreach ($chainGroups as $g) {
                    jackRunPrint("$indent$mark " . jpStr($g->title) . '   [' . self::location($g) . '] ' . jpHuman($g->size) . ', ' . $nFiles($g) . "   ($g->source)");
                    foreach ($showFiles ? $g->files : [] as $f) {
                        jackRunPrint("$indent  $mark [" . ($f->onCache ? 'CACHE' : 'ARRAY') . '] ' . sprintf('%10s', jpHuman($f->size)) . "  $f->rel");
                    }
                }
                continue;
            }
            $locs = array_unique(array_map(fn (JackPlanGroup $g): string => self::location($g), $chainGroups));
            $total = array_sum(array_map(fn (JackPlanGroup $g): int => $g->size, $chainGroups));
            jackRunPrint("$indent$mark " . jpStr($first->series) . "   ($first->source)  " . count($chainGroups) . ' Folge' . (count($chainGroups) !== 1 ? 'n' : '') . ', '
                . jpHuman($total) . '  [' . (count($locs) === 1 ? reset($locs) : 'TEILS') . ']');
            foreach ($chainGroups as $g) {
                jackRunPrint("$indent  $mark " . jpStr($g->title) . '   [' . self::location($g) . '] ' . jpHuman($g->size) . ', ' . $nFiles($g) . "   ($g->source)");
                foreach ($showFiles ? $g->files : [] as $f) {
                    jackRunPrint("$indent    $mark [" . ($f->onCache ? 'CACHE' : 'ARRAY') . '] ' . sprintf('%10s', jpHuman($f->size)) . "  $f->rel");
                }
            }
        }
    }

    /** report(): on deck per person — films, then series with their episodes, then what is beyond the budget */
    private function report(JackPlanner $planner): void
    {
        $wanted = [];
        foreach ($this->userFilter ?? [] as $u) {
            if (trim($u) !== '') {
                $wanted[mb_strtolower(trim($u))] = true;
            }
        }
        $users = array_values($planner->users);
        usort($users, fn (JackPlanUser $a, JackPlanUser $b): int => jpCmp(implode(', ', $a->servers), implode(', ', $b->servers)) ?: jpCmp(jpLower(jpStr($a->name)), jpLower(jpStr($b->name))));
        if ($wanted) {
            $users = array_values(array_filter($users, function (JackPlanUser $u) use ($wanted): bool {
                foreach ($u->uids as $i) {
                    if (isset($wanted[mb_strtolower(jpStr($i))])) {
                        return true;
                    }
                }
                return isset($wanted[mb_strtolower(jpStr($u->name))]);
            }));
            if (!$users) {
                jackRunPrint("\nKein Benutzer passt auf: " . implode(', ', jpSorted(array_map('strval', array_keys($wanted)))));
                jackRunPrint('Bekannt: ' . implode(', ', array_map(fn (JackPlanUser $u): string => jpStr($u->name) . ' (' . jpStr($u->uids[0] ?? '') . ')', $planner->users)));
                return;
            }
        }
        $shown = [];
        foreach ($users as $u) {
            $head = '=== ' . jpStr($u->name) . ' @ ' . implode(', ', $u->servers) . ' ===';
            if ($planner->budgetMode && $u->hasCandidates()) {
                $head .= '  Budget: ' . jpHuman($u->bytes) . ' von ' . jpHuman((float) $u->budget) . ' (Filme ' . jpHuman($u->movieBytes) . ', Serien ' . jpHuman($u->seriesBytes) . ')';
            }
            jackRunPrint("\n$head");
            if (!$u->hasCandidates()) {
                jackRunPrint('  (nichts on deck)');
                continue;
            }
            $movies = array_values(array_filter($u->selected, fn (JackPlanGroup $g): bool => $g->category === 'movie'));
            $series = array_values(array_filter($u->selected, fn (JackPlanGroup $g): bool => $g->category !== 'movie'));
            if ($movies) {
                jackRunPrint('  Filme: ' . count($movies) . ' Einträge, ' . jpHuman(array_sum(array_map(fn (JackPlanGroup $g): int => $g->size, $movies))));
                self::renderGroups($movies, '•', $this->showFiles);
            }
            if ($series) {
                $n = count(array_unique(array_map(fn (JackPlanGroup $g): string => $g->chain, $series)));
                jackRunPrint("  Serien: $n Serie" . ($n !== 1 ? 'n' : '') . ', ' . count($series) . ' Folgen, ' . jpHuman(array_sum(array_map(fn (JackPlanGroup $g): int => $g->size, $series))));
                self::renderGroups($series, '•', $this->showFiles);
            }
            if ($u->skipped) {
                jackRunPrint('  Nicht im Budget: ' . count($u->skipped) . ' Einträge, ' . jpHuman(array_sum(array_map(fn (JackPlanGroup $g): int => $g->size, $u->skipped)))
                    . '  (bleiben auf dem Array bzw. werden zurückgeräumt)');
                self::renderGroups($u->skipped, '✗', $this->showFiles);
            }
            foreach ($u->selected as $g) {
                foreach ($g->files as $f) {
                    $shown["f$f->rel"] = true;
                }
            }
        }
        $files = $wanted ? array_values(array_filter($planner->files, fn (JackPlanFile $f): bool => isset($shown["f$f->rel"]))) : array_values($planner->files);
        $onC = array_sum(array_map(fn (JackPlanFile $f): int => $f->onCache ? $f->size : 0, $files));
        $onA = array_sum(array_map(fn (JackPlanFile $f): int => $f->onCache ? 0 : $f->size, $files));
        jackRunPrint("\n" . ($wanted ? 'Auswahl' : 'Gesamt') . ': ' . count($files) . ' Dateien – auf dem Cache ' . jpHuman($onC) . ', noch auf dem Array ' . jpHuman($onA)
            . ', zusammen ' . jpHuman($onC + $onA));
        if (!$wanted && $planner->budgetMode) {
            jackRunPrint('Budget: ' . jpHuman((int) $this->cfg['cache_budget_bytes']) . ' für ' . count(array_filter($planner->users, fn (JackPlanUser $u): bool => $u->hasCandidates()))
                . ' aktive Benutzer');
        }
        jackRunPrint('Exclude-Liste: ' . $this->paths->exclude . ' (' . count(jackRunReadExclude($this->paths->exclude)) . " Einträge)\n");
    }

    // ----------------------------------------------------------------- the run

    public function execute(): int
    {
        $state = 'abgebrochen (Fehler)';
        try {
            $rc = $this->executeInner();
            $state = 'fertig';
            return $rc;
        } finally {
            $this->status->finish($state);
        }
    }

    private function executeInner(): int
    {
        $cfg = $this->cfg;
        $this->log->info('=== MediaCache ' . JACK_RUN_SOURCE . ' (PHP) – Modus: ' . strtoupper($this->mode) . ' ===');
        if (jackRunMoverRunning($this->moverPid)) {
            $this->log->warning('Der reguläre Unraid-Mover läuft gerade – Cleanup könnte mit ihm kollidieren (nur Log-Meldungen, kein Datenrisiko)');
        }
        if ($this->mode === 'release') {
            return $this->release();
        }
        $noFill = ($this->env['MEDIACACHE_NO_FILL'] ?? '') === '1';
        $d = jackPlanDecide($cfg, $this->env, $this->host, $this->plexTv, $this->say(), $this->mode !== 'report');
        $files = $d['files'];
        $this->result['on_deck'] = ['files' => count($files), 'bytes' => array_sum(array_map(fn (JackPlanFile $f): int => $f->size, $files)),
                                    'on_cache' => count(array_filter($files, fn (JackPlanFile $f): bool => $f->onCache))];
        if ($d['not_in_budget'] !== null) {
            $this->result['not_in_budget'] = $d['not_in_budget'];
        }
        if ($this->mode === 'report') {
            $this->report($d['planner']);
            $this->collectThemes(true);
            $this->result['themes'] = $this->themes->result;
            return 0;
        }
        if (!$noFill) {
            $this->progress->planFill($d['planner'], $files);
        }
        $loc = $d['loc'];
        $protected = $d['protected'];
        $this->held = $d['held'];
        $this->givenBack = $d['given_back'];
        $this->givenBackBytes = $d['given_back_bytes'];
        $this->givenList = $d['given_list'];
        $this->host->live = true;            // the plan stands: from here on the pool's numbers are measured for real
        $incomplete = $d['incomplete'];
        $fill = ['rels' => [], 'dropped' => []];
        $simDelta = $d['cleanup'] ? $d['to_array'] : 0;
        if (!$this->runMode) {
            if (!$incomplete) {
                $this->cleanup($loc, $d['cleanup'], $d['to_array'], $d['cleanup_sizes'], $protected, $d['back_why']);
            }
            $dry = jackPlanDryFill($d, $this->host, $this->say(),
                fn (string $share, array &$roots): bool => jackRunShareRoot($cfg, $loc->cache, $loc->array, $share, false, $this->log, $roots, $this->env));
            $this->toCache = $dry['bytes'];
            $this->fillPlanned = count($dry['rels']);
            $this->held = $dry['held'];
            $this->waiting = $dry['waiting'];
            $this->waitingBytes = $dry['waiting_bytes'];
            if ($noFill) {
                $this->log->info("Nur freigeben (MEDIACACHE_NO_FILL): nichts geholt – $this->waiting Dateien (" . jpHuman($this->waitingBytes) . ') warten auf dem Array');
            }
            $this->collectThemes(true);
            $this->log->info("Dry-Run: $this->cleanupPlanned Dateien (" . jpHuman($this->toArray) . ") würden aufs Array, $this->fillPlanned Dateien ("
                . jpHuman($this->toCache) . ') auf den Cache – nichts verändert');
            $this->planDoc = jackPlanDoc($d, $dry);
            $this->resultEnd($protected, $incomplete);
            return 0;
        }
        try {
            if (!$incomplete) {
                $this->cleanup($loc, $d['cleanup'], $d['to_array'], $d['cleanup_sizes'], $protected, $d['back_why']);
            }
            if ($noFill) {
                clearstatcache();
                foreach ($files as $f) {
                    if (!file_exists($loc->onCache($f->rel))) {
                        $this->waiting++;
                        $this->waitingBytes += $f->size;
                    }
                }
                $this->log->info("Nur freigeben (MEDIACACHE_NO_FILL): nichts geholt – $this->waiting Dateien (" . jpHuman($this->waitingBytes) . ') warten auf dem Array');
            } else {
                $this->progress->phase('fill');
                $fill = $this->fill($loc, $files, $d['playing']);
            }
            // theme.mp3: after the fill — only when the list is written afterwards (else a fetched theme.mp3 would stand in no list)
            $this->collectThemes(!($incomplete && !file_exists($this->paths->exclude)));
        } finally {
            $this->progress->phase('done');
            clearstatcache();
            $onCache = [];
            foreach ($files as $f) {
                if (file_exists($loc->onCache($f->rel))) {
                    $onCache[] = $loc->onCache($f->rel);
                }
            }
            if ($incomplete && !file_exists($this->paths->exclude)) {
                // no list yet and the plan incomplete: what lies on the pool is unknown, an empty list would give it all free — none written
                $this->log->warning('Planung unvollständig und noch keine Exclude-Liste – sie wird nicht angelegt; der nächste vollständige Lauf schreibt sie');
            } else {
                $this->writeList(array_merge($onCache, array_values($protected)));
            }
            $this->planDoc = jackPlanDoc($d, ['rels' => $fill['rels'], 'dropped' => $fill['dropped'], 'bytes' => $this->toCache, 'waiting' => $this->waiting,
                'waiting_bytes' => $this->waitingBytes, 'held' => $this->held, 'sim_delta' => $simDelta - $this->toCache]);
        }
        $excl = jackRunReadExclude($this->paths->exclude);
        $this->log->info("Ergebnis Cleanup:  $this->movedBack von $this->cleanupPlanned Dateien aufs Array verschoben (" . jpHuman($this->movedBackBytes) . ' von ' . jpHuman($this->toArray) . ')');
        $this->log->info("Ergebnis Befüllen: $this->copied von $this->fillPlanned Dateien auf den Cache kopiert (" . jpHuman($this->copiedBytes) . ' von ' . jpHuman($this->toCache) . ')');
        $this->log->info('Exclude-Liste: ' . count($excl) . ' Einträge' . ($protected ? ', davon ' . count($protected) . ' geschützt (laufen gerade oder konnten nicht verschoben werden)' : ''));
        $this->resultEnd($protected, $incomplete);
        if ($this->stopped) {
            $this->log->info('Angehalten nach der aktuellen Datei (MEDIACACHE_STOP) – der nächste Lauf macht weiter');
        }
        return $this->stopped ? 3 : 0;
    }

    private function resultEnd(array $protected, bool $incomplete): void
    {
        $this->result['cleanup'] = ['planned' => $this->cleanupPlanned, 'done' => $this->movedBack, 'bytes_planned' => $this->toArray, 'bytes_done' => $this->movedBackBytes];
        $this->result['fill'] = ['planned' => $this->fillPlanned, 'done' => $this->copied, 'bytes_planned' => $this->toCache, 'bytes_done' => $this->copiedBytes];
        $this->result['protected'] = count($protected);
        $this->result['incomplete'] = $incomplete;
        $this->result['failed'] = $this->failedFiles + ['files' => $this->failedList, 'retried' => $this->retried];
        $this->result['themes'] = $this->themes->result;
        if ($this->officePool) {
            $this->result['pool'] = ['keep' => $this->keep, 'held' => $this->held, 'waiting' => $this->waiting, 'waiting_bytes' => $this->waitingBytes];
            $this->result['give_back'] = ['files' => $this->givenBack, 'bytes' => $this->givenBackBytes, 'items' => $this->givenList];
        }
    }

    /** release(): everything back to the array — the cleanup over the whole list, no fill; what plays stays. The list then holds what stays */
    private function release(): int
    {
        $d = jackPlanReleaseDecide($this->cfg, $this->env, $this->host, $this->plexTv, $this->say());
        $this->host->live = true;
        $previous = jackRunReadExclude($this->paths->exclude);
        $protected = $d['protected'];
        $left = [];
        try {
            $this->cleanup($d['loc'], $d['cleanup'], $d['to_array'], $d['cleanup_sizes'], $protected, []);
        } finally {
            $this->progress->phase('done');
            clearstatcache();
            foreach ($previous as $p) {
                if (file_exists(jpNorm($p))) {
                    $left[] = $p;
                }
            }
            if ($left || file_exists($this->paths->exclude)) {     // no list before: none made, not even an empty one
                $this->writeList($left);
            }
            if (!$this->keepThemes) {
                $this->themes->off();
            }
            $this->planDoc = jackPlanReleaseDoc($d);
        }
        $this->log->info("Ergebnis Zurückbringen: $this->movedBack von $this->cleanupPlanned Dateien aufs Array verschoben (" . jpHuman($this->movedBackBytes) . ' von ' . jpHuman($this->toArray) . ')');
        $this->log->info('Exclude-Liste: ' . count($left) . ' Einträge' . ($left ? ' (liegen weiter auf dem Cache)' : ''));
        $this->result['cleanup'] = ['planned' => $this->cleanupPlanned, 'done' => $this->movedBack, 'bytes_planned' => $this->toArray, 'bytes_done' => $this->movedBackBytes];
        $this->result['fill'] = ['planned' => 0, 'done' => 0, 'bytes_planned' => 0, 'bytes_done' => 0];
        $this->result['protected'] = count($left);
        $this->result['incomplete'] = !$d['sessions_ok'];
        $this->result['failed'] = $this->failedFiles + ['files' => $this->failedList, 'retried' => $this->retried];
        $this->result['themes'] = $this->themes->result + ['kept' => $this->keepThemes];
        return $this->stopped ? 3 : 0;
    }

    /** write_list(): with `themes` every theme.mp3 on the pool in its own section, and what the collector fetched or found */
    private function writeList(array $paths): void
    {
        $listed = [];
        foreach ($paths as $p) {
            $listed[(string) $p] = true;
        }
        if (!$this->keepThemes) {
            jackRunWriteExclude($this->paths->exclude, array_keys($listed));
            return;
        }
        clearstatcache();
        foreach ($this->themes->paths as $p => $_) {
            if (is_file((string) $p)) {
                $listed[(string) $p] = true;
            }
        }
        $all = array_map('strval', array_keys($listed));
        jackRunWriteExclude($this->paths->exclude, $all, array_values(array_filter($all, fn (string $p): bool => jpName($p) === JACK_RUN_THEME)));
    }

    /** collect_themes(): dry run and report say what the next real run would look at; the real run (never --release, never NO_FILL, never
     *  after a stop) looks and fetches. Switched off, a real run resets the state. An error here never holds the run up */
    private function collectThemes(bool $listWritten): void
    {
        if (!$this->themes->on) {
            if ($this->mode === 'run') {
                $this->themes->off();
            }
            return;
        }
        try {
            if (in_array($this->mode, ['dry', 'report'], true)) {
                $this->themes->plan($this->themes->scan()[0]);
            } elseif ($this->mode === 'run' && ($this->env['MEDIACACHE_NO_FILL'] ?? '') !== '1' && $listWritten && !$this->halt()) {
                [$due, $failed] = $this->themes->scan();
                $this->progress->phase('themes');
                $this->themes->collect($due, $failed);
            }
        } catch (Throwable $e) {
            $this->log->exception("theme.mp3: Sammeln abgebrochen ({$e->getMessage()}) – der nächste Lauf versucht es wieder", $e);
            $this->themes->result['error'] = true;
        }
    }
}

/** write_result(): the run's result to MEDIACACHE_RESULT (atomic), when set */
function jackRunWriteResult(JackRunPaths $paths, JackRunLog $log, array $data): void
{
    if ($paths->result === '') {
        return;
    }
    $data += ['version' => JACK_RUN_SOURCE, 'engine' => 'php'];
    $data['version'] = JACK_RUN_SOURCE;
    $data['warnings'] = $log->counts['WARNING'];
    $data['errors'] = $log->counts['ERROR'];
    try {
        writeAtomic($paths->result, jackRunJson($data) . "\n", 0644, 0, 0);
    } catch (Throwable) {
        // Python: an OSError here is passed over
    }
}

/**
 * main(): `php jack-run.php [--run | --release | --show-on-deck [--compact] [--user a,b]] [--tape <file>] [--plan-out <file>]` with
 * MediaCache's environment. Exit 0 ok, 3 stopped, 1 failed (config, busy, an error), 2 a bad call.
 *
 * @param array<string, string> $env
 */
function jackRunMain(array $argv, array $env): int
{
    $flags = [];
    $opt = [];
    for ($i = 1; $i < count($argv); $i++) {
        $a = (string) $argv[$i];
        if (in_array($a, ['--tape', '--plan-out', '--user', '--plex-tv'], true) && isset($argv[$i + 1])) {
            $opt[$a] = (string) $argv[++$i];
        } else {
            $flags[$a] = true;
        }
    }
    $paths = new JackRunPaths($env);
    $log = new JackRunLog("$paths->logDir/mediacache.log", ($env['MEDIACACHE_LOG_LEVEL'] ?? '') ?: 'INFO');
    if (isset($flags['--status'])) {
        $log->error('--status: in der PHP-Fassung nicht vorhanden (das Office liest den Fortschritt aus MEDIACACHE_PROGRESS)');
        return 2;
    }
    $mode = strtolower(($env['MEDIACACHE_MODE'] ?? '') ?: 'dry');
    $mode = isset($flags['--release']) ? 'release' : (isset($flags['--run']) ? 'run' : (isset($flags['--show-on-deck']) ? 'report' : $mode));
    if (!in_array($mode, ['dry', 'report', 'run', 'release'], true)) {
        $log->error("Ungültiger Modus: $mode");
        return 2;
    }
    $filter = $opt['--user'] ?? ($env['MEDIACACHE_REPORT_USER'] ?? null);
    if ($filter !== null && $filter !== '' && $mode !== 'report') {
        $log->error('--user filtert nur die Anzeige und ist deshalb nur mit --show-on-deck erlaubt – ein Lauf für einzelne Benutzer würde die Dateien der anderen zurückräumen');
        return 2;
    }
    $started = time();
    $tape = null;
    if (isset($opt['--tape'])) {
        $tape = JackPlanTape::read($opt['--tape']);
        if ($tape === null) {
            $log->warning('Die Antworten des Vergleichs (--tape) sind nicht lesbar – der Lauf fragt die Medienserver selbst');
        }
    }
    jackPlanPython($tape?->python ?? [3, 11]);
    try {
        $cfg = jackPlanConfig($paths->config, $env);
        jackPlanKeepBytes($env);
    } catch (JackPlanConfigError $e) {
        $log->error('Konfiguration: ' . $e->getMessage());
        jackRunWriteResult($paths, $log, ['mode' => $mode, 'result' => 'config', 'message' => $e->getMessage(), 'started' => $started, 'finished' => time()]);
        return 1;
    } catch (Throwable $e) {
        $log->exception('Unerwarteter Fehler', $e);
        return 1;
    }
    $lock = jackRunLock($paths->lock, $log);
    if ($lock === null) {
        jackRunWriteResult($paths, $log, ['mode' => $mode, 'result' => 'busy', 'started' => $started, 'finished' => time()]);
        return 1;
    }
    $runner = null;
    $result = 'failed';
    try {
        $runner = new JackRunner($cfg, $mode, $env, $paths, $log, $tape, $filter !== null && $filter !== '' ? explode(',', $filter) : null, !isset($flags['--compact']),
            $opt['--plex-tv'] ?? JACK_PLAN_PLEX_TV, ($env['JACK_RUN_VAR_INI'] ?? '') ?: JACK_RUN_VAR_INI, ($env['JACK_RUN_MOVER_PID'] ?? '') ?: JACK_RUN_MOVER_PID);
        $code = $runner->execute();
        $result = $runner->stopped ? 'stopped' : 'ok';
        return $code;
    } catch (Throwable $e) {
        $log->exception('Unerwarteter Fehler', $e);
        return 1;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        jackRunWriteResult($paths, $log, ($runner?->result ?? ['mode' => $mode]) + ['result' => $result, 'started' => $started, 'finished' => time()]);
        if ($runner?->planDoc !== null && isset($opt['--plan-out'])) {
            try {
                writeAtomic($opt['--plan-out'], jackRunJson($runner->planDoc), 0600, 0, 0);
            } catch (Throwable) {
                // only the night file's word is missing then
            }
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    error_reporting(E_ALL);
    chdir('/');
    // the server's time zone (Python's log lines are local time; its environment has no TZ)
    $zone = preg_replace('#^.*/zoneinfo/#', '', (string) @readlink('/etc/localtime'));
    date_default_timezone_set(in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
    $env = array_map('strval', getenv());
    exit(jackRunMain($argv, $env));
}
