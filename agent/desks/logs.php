<?php
declare(strict_types=1);

/*
 * Ms. Protocolli — reads every log out loud. Understanding them is not her
 * job: she shows them, follows them like tail -f and lets the page filter.
 *
 * Only sources from a fixed list (logsSources()): the office's own logs,
 * Unraid's logs in /var/log (RAM — nothing wakes up), the flash's parity
 * history, User Scripts outputs and the containers' docker logs. A request
 * names a source id, never a path. Read only.
 *
 * Her tour ("tour": logsTourRun() in a process of its own, polled from tick)
 * counts and groups, it never judges:
 *   space    how full /var/log is (Unraid's RAM disk, 128 MB), its biggest
 *            files and how much they grew since the last tour; every
 *            container's docker log (only its size, by stat — never read
 *            here) and whether Docker's log rotation is set (docker.cfg, and
 *            per container the limit it was created with)
 *   errors   over her list (files in RAM, dmesg, running containers' docker
 *            logs): lines that look like an error or a warning (logsLevel()),
 *            similar ones as one kind (logsNormalize()) with a count,
 *            first/last seen and the newest line as a sample — since the last
 *            tour (by file offset, docker logs --since), on the first tour the
 *            last 24 hours. At most LOGS_TOUR_BYTES of a file, LOGS_TOUR_DOCKER
 *            lines of a container.
 * The result goes to data/logs-tour.json (the page reads it as the part
 * "tour"), with what the next tour needs (offsets, sizes) under "memory".
 * The team lead's check (/var/log's fill level) only asks statvfs.
 */

const LOGS_MAX_LINES = 10000;
const LOGS_MAX_BYTES = 8 * 1024 * 1024;     // read at most this much from the end of a file
const LOGS_MAX_CHUNK = 1024 * 1024;         // and at most this much new text per follow step

const LOGS_VARLOG        = '/var/log';
const LOGS_VARLOG_WARN   = 60;              // % full: recommended to look
const LOGS_VARLOG_FULL   = 80;              // % full: still to do
const LOGS_DOCKER_CFG    = '/boot/config/docker.cfg';
const LOGS_TOUR_BYTES    = 4 * 1024 * 1024; // a tour reads at most this much of a file (the newest part)
const LOGS_TOUR_DOCKER   = 3000;            // and at most this many lines of a container's docker logs
const LOGS_TOUR_FIRST    = 86400;           // the first tour looks back this far
const LOGS_TOUR_TOP      = 8;               // kinds of lines kept per source (the most frequent)
const LOGS_TOUR_KINDS    = 3000;            // kinds told apart per source while counting (beyond: "other")
const LOGS_TOUR_SAMPLE   = 400;             // bytes of a sample line
const LOGS_TOUR_FILES    = 12;              // biggest files of /var/log shown
const LOGS_TOUR_WALK     = 5000;            // files looked at in /var/log at most
const LOGS_TOUR_MAX_RUN  = 300;             // seconds: a tour that takes longer is stopped
const LOGS_TOUR_PARALLEL = 4;               // docker logs at once

/*
 * What looks like an error or a warning — she only recognises words (the
 * page colours lines the same way, public/desks/logs/desk.js):
 *   1. A line that names its own level goes by that name (in its first 300
 *      characters): level=warn, lvl=eror, "level":"info", [ERROR], [notice],
 *      | WARNING |, libvirt's ": error : ", the Unraid API's "[11:15:34 WARN",
 *      "PHP Warning:", and an upper-case word of its own (INF, WRN, ERR, LOG,
 *      INFO, WARNING, ERROR …) — error, err, eror, fatal, ftl, crit(ical),
 *      alert, emerg, panic, "fatal error", "parse error" count as error; warn,
 *      warning, wrn as warning; info, debug, notice, trace, log, verbose,
 *      deprecated not at all.
 *   2. Every other line by its words (case-insensitive, whole words):
 *      error   error, err, eror, fail, fails, failed, failure, fatal, panic,
 *              crit, critical, emerg, segfault, denied, oops, call trace,
 *              I/O error, traceback, exception, an exit code or status other
 *              than 0, and "Fehler" (Jack Emby's tools write German)
 *      warn    warn, warning, wrn, timeout, timed out, retry, retrying, "Warnung"
 *      Not counted (taken out before looking): "0 errors", "no warnings",
 *      "0 failed", "errors: 0", "failed=0", "error.log", "errors=remount-ro",
 *      and rsync's lists of files (">f+++++++++ name" — names, not news).
 *   3. dmesg on top: what the kernel itself marks as err (and worse) or warn.
 */
const LOGS_OWN_LEVEL = '/\b(?:level|lvl|severity)"?\s*[=:]\s*"?([a-z]+)|\[([a-z]+)\]|\|\s*([a-z]+)\s*\||:\s([a-z]+)\s:\s|^\[\d\d:\d\d:\d\d\s([a-z]+)\s|\bPHP (fatal error|parse error|warning|notice|deprecated)\b/i';
const LOGS_OWN_UPPER = '/(?:^|\s)(INF|WRN|ERR|DBG|FTL|TRC|LOG|INFO|WARN|WARNING|ERROR|DEBUG|FATAL|VERBOSE|NOTICE|CRITICAL)(?=[\s:\]])/';
const LOGS_LEVEL_NAMES = ['error' => 'error', 'err' => 'error', 'eror' => 'error', 'fatal' => 'error', 'ftl' => 'error', 'crit' => 'error',
                          'critical' => 'error', 'alert' => 'error', 'emerg' => 'error', 'panic' => 'error', 'fatal error' => 'error',
                          'parse error' => 'error', 'warn' => 'warn', 'warning' => 'warn', 'wrn' => 'warn',
                          'info' => '', 'inf' => '', 'debug' => '', 'dbug' => '', 'dbg' => '', 'notice' => '', 'trace' => '', 'trc' => '',
                          'log' => '', 'verbose' => '', 'deprecated' => ''];
const LOGS_NOT_COUNTED = '/^[<>ch.*][fdlsp][.+?a-z]{9,10}\s.*|\b(?:0|no|zero|without|keine?)\s+(?:errors?|warnings?|failures?|failed|fehler|warnungen)\b|\b(?:errors?|warnings?|failures?|failed|fehler|err|warn)\s*[=:]\s*(?:0|none|nil|null|false)\b|\berror\.log\b|\berrors=remount-ro\b/i';
const LOGS_ERROR = '/\b(?:error|err|eror|fail(?:s|ed|ure)?|fatal|panic|crit(?:ical)?|emerg|segfault|denied|oops|call trace|i\/o error|traceback|exception|fehler)\b|\bexit(?:ed)?(?: with)? (?:code|status)[ =:]*[1-9]/i';
const LOGS_WARN  = '/\b(?:warn(?:ing)?|wrn|timeout|timed out|retry|retrying|warnung)\b/i';

/*
 * A time at the start of a line (first/last seen, the first tour's 24 hours):
 * ISO-like (the office, the engine, EmbyCache, libvirt, nginx, Samba, docker
 * --timestamps), syslog's "Oct  5 22:52:00", dmesg -T's
 * "[Sun Oct  5 22:52:31 2026]", PHP's "[05-Oct-2026 20:52:40 Europe/Berlin]".
 */
const LOGS_TIME_ISO    = '/^\[?(\d{4})[-\/](\d\d)[-\/](\d\d)[T ](\d\d:\d\d:\d\d)(?:[.,]\d+)?(Z|\s?[+-]\d\d:?\d\d)?(?:,\s*\d+)?\]?\s*/';
const LOGS_TIME_SYSLOG = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s+/';
const LOGS_TIME_DMESG  = '/^\[(?:[A-Z][a-z]{2}\s+)?([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s+(\d{4})\]\s*/';
const LOGS_TIME_PHP    = '/^\[(\d\d)-([A-Z][a-z]{2})-(\d{4})\s+(\d\d:\d\d:\d\d)(?:\s+([A-Za-z_\/+-]+))?\]\s*/';

desk('logs', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'start'   => fn () => logsTourRecover(),
    'tick'    => fn () => logsTourTick(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => logsScan()],
        'read'    => fn (array $r) => logsRead(textField($r, 'source'), (int) ($r['lines'] ?? 500),
                                               isset($r['offset']) ? (int) $r['offset'] : null),
        'tour'    => fn (array $r) => logsTourStart(),
    ],
    'jobs'    => ['logs-tour' => fn (array $args) => logsTourRun()],
    'checks'  => fn (): array => logsChecks(),
]);

/**
 * Every source that can be read here: id => [group, label, kind, target, label param].
 * kind "file" (target: path) or "docker" (target: container) or "dmesg".
 */
function logsSources(): array
{
    $s = [];
    $file = function (string $id, string $group, string $label, string $path, string $param = '') use (&$s): void {
        if (is_file($path)) {
            $s[$id] = ['group' => $group, 'label' => $label, 'kind' => 'file', 'target' => $path, 'param' => $param];
        }
    };

    // the office
    $file('agent', 'office', 'agent', AGENT_LOG);
    $file('agent.1', 'office', 'agent_old', AGENT_LOG . '.1');
    $file('backup:latest', 'office', 'backup_latest', DATA_DIR . '/unraid-backup/logs/latest.log');   // the engine's link to its newest log
    $backupLogs = glob(DATA_DIR . '/unraid-backup/logs/*.log') ?: [];
    usort($backupLogs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice(array_filter($backupLogs, fn ($f) => basename($f) !== 'latest.log'), 0, 30) as $f) {
        $file('backup:' . basename($f), 'office', 'backup_log', $f, basename($f, '.log'));
    }
    $file('embycache', 'office', 'embycache', DATA_DIR . '/embycache/logs/embycache.log');
    $file('embycache-run', 'office', 'embycache_run', DATA_DIR . '/embycache/office-output.txt');
    $file('gather', 'office', 'gather', DATA_DIR . '/gather/consolidate.log');
    foreach (['UnraidSecretaryOffice', 'UnraidSecretaryOffice-Agent'] as $c) {
        $s["container:$c"] = ['group' => 'office', 'label' => 'container', 'kind' => 'docker', 'target' => $c, 'param' => $c];
    }

    // Unraid
    $file('syslog', 'unraid', 'syslog', '/var/log/syslog');
    $file('syslog.1', 'unraid', 'syslog_old', '/var/log/syslog.1');
    $s['dmesg'] = ['group' => 'unraid', 'label' => 'dmesg', 'kind' => 'dmesg', 'target' => '', 'param' => ''];
    $file('docker', 'unraid', 'docker', '/var/log/docker.log');
    $file('libvirt', 'unraid', 'libvirt', '/var/log/libvirt/libvirtd.log');
    foreach (glob('/var/log/libvirt/qemu/*.log') ?: [] as $f) {
        $file('vm:' . basename($f, '.log'), 'unraid', 'vm', $f, basename($f, '.log'));
    }
    $file('nginx', 'unraid', 'nginx', '/var/log/nginx/error.log');
    foreach (glob('/var/log/samba/log.*') ?: [] as $f) {
        $file('samba:' . basename($f), 'unraid', 'samba', $f, basename($f));
    }
    $file('php', 'unraid', 'php', '/var/log/phplog');
    $file('graphql', 'unraid', 'api', '/var/log/graphql-api.log');
    $file('mail', 'unraid', 'mail', '/var/log/maillog');
    $file('compose', 'unraid', 'compose', '/var/log/compose.manager.log');
    $file('parity', 'unraid', 'parity', '/boot/config/parity-checks.log');

    // User Scripts: what each script printed last
    foreach (glob('/tmp/user.scripts/tmpScripts/*/log.txt') ?: [] as $f) {
        $name = basename(dirname($f));
        $file('us:' . $name, 'userscripts', 'user_script', $f, $name);
    }

    // every container's docker logs
    foreach (houseContainers() as $c) {
        $s['container:' . $c['name']] ??= ['group' => 'containers', 'label' => 'container', 'kind' => 'docker', 'target' => $c['name'], 'param' => $c['name']];
    }
    return $s;
}

/** The list for the page (no paths of anything but files), a word for the reception, /var/log's fill level */
function logsScan(): array
{
    $list = [];
    foreach (logsSources() as $id => $src) {
        $list[] = ['id' => $id, 'group' => $src['group'], 'label' => $src['label'], 'param' => $src['param'],
                   'path' => $src['kind'] === 'file' ? $src['target'] : null,
                   'size' => $src['kind'] === 'file' ? (int) @filesize($src['target']) : null,
                   'time' => $src['kind'] === 'file' ? (@filemtime($src['target']) ?: null) : null];
    }
    // how loud is the syslog lately? (the last 1000 lines; she counts, she doesn't judge)
    $errors = $warnings = 0;
    foreach (logsTail('/var/log/syslog', 1000) as $line) {
        $level = logsLevel($line);
        $errors += $level === 'error' ? 1 : 0;
        $warnings += $level === 'warn' ? 1 : 0;
    }
    $state = ['time' => time(), 'sources' => $list, 'syslog' => ['errors' => $errors, 'warnings' => $warnings],
              'varlog' => logsVarlog()];
    writeAtomic(deskFile('logs'), jsonEncode($state));
    return $state;
}

/** error / warn / '' — by the line's own level or the word lists above (she doesn't know what they mean) */
function logsLevel(string $line): string
{
    $head = substr($line, 0, 300);
    foreach ([LOGS_OWN_LEVEL, LOGS_OWN_UPPER] as $pattern) {
        preg_match_all($pattern, $head, $hits, PREG_SET_ORDER);
        foreach ($hits as $hit) {
            $name = strtolower((string) end($hit));         // the alternative that matched is the last group filled
            if (isset(LOGS_LEVEL_NAMES[$name])) {
                return LOGS_LEVEL_NAMES[$name];
            }
        }
    }
    $text = preg_replace(LOGS_NOT_COUNTED, ' ', $line) ?? $line;
    if (preg_match(LOGS_ERROR, $text)) {
        return 'error';
    }
    return preg_match(LOGS_WARN, $text) ? 'warn' : '';
}

/** The last $n lines of a file, reading at most LOGS_MAX_BYTES from its end */
function logsTail(string $path, int $n): array
{
    $size = (int) @filesize($path);
    $h = @fopen($path, 'r');
    if (!$h || !$size) {
        return [];
    }
    $want = min($size, LOGS_MAX_BYTES, max(65536, $n * 400));
    fseek($h, $size - $want);
    $text = (string) fread($h, $want);
    fclose($h);
    $lines = explode("\n", rtrim($text, "\n"));
    if ($want < $size) {
        array_shift($lines);            // started in the middle of a line
    }
    return array_slice($lines, -$n);
}

/** Colour codes out (containers love them) — the reader and the tour clean lines the same way */
function logsClean(string $line): string
{
    return str_contains($line, "\e") ? (preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line) ?? $line) : $line;
}

/**
 * Read a source: the last $lines lines, or — with $offset for a file — only
 * what was added since (tail -f). A file that got shorter was rotated: then
 * the last lines again, with "reset".
 */
function logsRead(string $id, int $lines, ?int $offset): array
{
    $src = logsSources()[$id] ?? null;
    if (!$src) {
        throw new Problem('logs_unknown', ['source' => $id]);
    }
    $lines = max(10, min(LOGS_MAX_LINES, $lines));

    if ($src['kind'] === 'file') {
        clearstatcache(true, $src['target']);
        $size = (int) @filesize($src['target']);
        if ($offset !== null && $offset <= $size) {
            $h = @fopen($src['target'], 'r');
            if (!$h) {
                throw new Problem('logs_unreadable', ['source' => $id]);
            }
            $len = min($size - $offset, LOGS_MAX_CHUNK);
            fseek($h, $size - $len);      // more than a chunk? then the newest part
            $text = $len > 0 ? (string) fread($h, $len) : '';
            fclose($h);
            // only whole lines; the rest comes with the next step
            $cut = strrpos($text, "\n");
            $text = $cut === false ? '' : substr($text, 0, $cut + 1);
            $new = $text === '' ? [] : explode("\n", rtrim($text, "\n"));
            return ['ok' => true, 'lines' => array_map('logsClean', $new), 'offset' => $size - $len + strlen($text),
                    'size' => $size, 'time' => @filemtime($src['target']) ?: null, 'follow' => 'append'];
        }
        $tail = logsTail($src['target'], $lines);
        return ['ok' => true, 'lines' => array_map('logsClean', $tail), 'offset' => $size, 'size' => $size,
                'time' => @filemtime($src['target']) ?: null, 'follow' => 'append', 'reset' => $offset !== null];
    }

    if ($src['kind'] === 'dmesg') {
        [$exit, $out, $err] = run(['dmesg', '-T'], 20);
        if ($exit !== 0) {
            throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
        }
        $all = explode("\n", rtrim($out, "\n"));
        return ['ok' => true, 'lines' => array_map('logsClean', array_slice($all, -$lines)), 'follow' => 'replace'];
    }

    // docker logs: stdout and stderr, merged by their timestamps
    [$exit, $out, $err] = run(['docker', 'logs', '--tail', (string) $lines, '--timestamps', $src['target']], 30);
    if ($exit !== 0 && trim($out) === '') {
        throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
    }
    return ['ok' => true, 'lines' => array_map('logsClean', array_slice(logsDockerLines($out, $err), -$lines)), 'follow' => 'replace'];
}

/** docker logs --timestamps: stdout and stderr as one list, in the order of their timestamps */
function logsDockerLines(string $out, string $err): array
{
    $all = array_merge(array_filter(explode("\n", $out), 'strlen'), array_filter(explode("\n", $err), 'strlen'));
    sort($all, SORT_STRING);
    return $all;
}

// ===================================================================== /var/log and the team lead

/** How full /var/log is — statvfs and the mount table only, cheap enough for every check */
function logsVarlog(): array
{
    $total = @disk_total_space(LOGS_VARLOG);
    $free = @disk_free_space(LOGS_VARLOG);
    if (!$total || $free === false) {
        return ['ok' => false];
    }
    $fs = null;
    foreach (mountTable() as $m) {
        if ($m['mount'] === LOGS_VARLOG) {
            $fs = $m['fs'];
        }
    }
    $used = (int) ($total - $free);
    return ['ok' => true, 'own' => $fs !== null, 'fs' => $fs, 'total' => (int) $total, 'used' => $used,
            'pct' => (int) round($used * 100 / $total), 'warn' => LOGS_VARLOG_WARN, 'full' => LOGS_VARLOG_FULL];
}

/** For the team lead: /var/log over LOGS_VARLOG_FULL % is still to do, over LOGS_VARLOG_WARN % recommended */
function logsChecks(?array $v = null): array
{
    $v ??= logsVarlog();
    if (!$v['ok'] || !$v['own']) {
        return [];          // not a filesystem of its own (not Unraid's RAM disk): nothing that runs full by itself
    }
    $params = ['pct' => $v['pct'], 'used' => logsHuman($v['used']), 'total' => logsHuman($v['total'])];
    if ($v['pct'] >= LOGS_VARLOG_FULL) {
        return [finding('varlog', 'required', false, $params, '#/logs')];
    }
    if ($v['pct'] >= LOGS_VARLOG_WARN) {
        return [finding('varlog', 'recommended', false, $params, '#/logs')];
    }
    return [finding('varlog', 'required', true, $params, '#/logs')];
}

function logsHuman(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $x = (float) $bytes;
    while ($x >= 1024 && $i < count($units) - 1) {
        $x /= 1024;
        $i++;
    }
    return ($i ? number_format($x, $x < 10 ? 1 : 0, '.', '') : (string) $bytes) . ' ' . $units[$i];
}

// ===================================================================== the tour: running it

function logsTourFile(): string
{
    return DATA_DIR . '/logs-tour.json';
}

function logsTourErr(): string
{
    return RUN_DIR . '/logs-tour.err';
}

/** "tour": start one in the background (unless one runs); the page polls the part "tour" until job.running is false */
function logsTourStart(): array
{
    $tour = readJson(logsTourFile()) ?? [];
    if (!empty($GLOBALS['logsTour']) || !empty($GLOBALS['logsTourOrphan'])) {
        return ['ok' => true, 'tour' => $tour];
    }
    $tour['job'] = ['running' => true, 'since' => time(), 'pid' => null];
    writeAtomic(logsTourFile(), jsonEncode($tour));
    @mkdir(RUN_DIR, 0700, true);
    $pipes = [];
    // a process of its own (niced), without the agent's open files (its lock among them)
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'logs-tour'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', logsTourErr(), 'w']], $pipes, '/');
    if (!is_resource($process)) {
        logsTourFailed($tour['job']);
        throw new Problem('logs_tour_failed');
    }
    $GLOBALS['logsTour'] = ['process' => $process, 'since' => time()];
    $tour['job']['pid'] = proc_get_status($process)['pid'];
    writeAtomic(logsTourFile(), jsonEncode($tour));
    return ['ok' => true, 'tour' => $tour];
}

/** Every tick: nothing to do unless a tour runs — then a look whether it is done (or takes too long) */
function logsTourTick(): void
{
    $job = $GLOBALS['logsTour'] ?? null;
    if (!$job) {
        $pid = $GLOBALS['logsTourOrphan'] ?? null;      // a tour started before the agent restarted
        if ($pid && time() >= ($GLOBALS['logsTourOrphanLook'] ?? 0)) {
            $GLOBALS['logsTourOrphanLook'] = time() + 5;
            if (!logsTourAlive($pid)) {
                $GLOBALS['logsTourOrphan'] = null;
                logsTourRecover();
            }
        }
        return;
    }
    $status = proc_get_status($job['process']);
    if ($status['running']) {
        if (time() - $job['since'] > LOGS_TOUR_MAX_RUN && empty($job['stopped'])) {
            proc_terminate($job['process']);
            $GLOBALS['logsTour']['stopped'] = true;
            logLine('Ms. Protocolli: her tour took longer than ' . LOGS_TOUR_MAX_RUN . ' s — stopped');
        }
        return;
    }
    proc_close($job['process']);
    $GLOBALS['logsTour'] = null;
    $tour = readJson(logsTourFile()) ?? [];
    if (!empty($tour['job']['running'])) {          // it ended without writing its result
        $err = trim((string) @file_get_contents(logsTourErr(), false, null, 0, 4096));
        logLine('Ms. Protocolli: tour failed (exit ' . $status['exitcode'] . ')' . ($err !== '' ? ': ' . strtok($err, "\n") : ''));
        logsTourFailed($tour['job']);
        return;
    }
    logLine(sprintf('Ms. Protocolli: tour done in %d ms (%d errors, %d warnings)',
        (int) ($tour['duration_ms'] ?? 0), (int) ($tour['errors'] ?? 0), (int) ($tour['warnings'] ?? 0)));
}

/** At the start: a tour that was running when the agent stopped is either still running (it writes its result) or gone */
function logsTourRecover(): void
{
    $tour = readJson(logsTourFile());
    if (empty($tour['job']['running'])) {
        return;
    }
    $pid = (int) ($tour['job']['pid'] ?? 0);
    if (logsTourAlive($pid)) {
        $GLOBALS['logsTourOrphan'] = $pid;
        return;
    }
    logsTourFailed($tour['job']);
}

function logsTourAlive(int $pid): bool
{
    return $pid > 1 && @posix_kill($pid, 0) && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'logs-tour');
}

/** The last result stays; only the job is marked as failed */
function logsTourFailed(array $job): void
{
    $tour = readJson(logsTourFile()) ?? [];
    $tour['job'] = ['running' => false, 'since' => $job['since'] ?? null, 'ended' => time(), 'failed' => true];
    writeAtomic(logsTourFile(), jsonEncode($tour));
}

// ===================================================================== the tour: what she does

/** php agent.php job logs-tour — the tour itself, in a process of its own (started by "tour") */
function logsTourRun(): int
{
    $t0 = microtime(true);
    $prev = readJson(logsTourFile()) ?? [];
    $mem = is_array($prev['memory'] ?? null) ? $prev['memory'] : [];
    $now = time();
    $first = !$mem || empty($prev['time']);
    $since = $first ? $now - LOGS_TOUR_FIRST : (int) $prev['time'];
    $sources = logsSources();
    $next = ['files' => [], 'sizes' => [], 'containers' => []];

    $varlog = logsTourVarlog($mem['sizes'] ?? [], $next['sizes'], $sources);
    $docker = logsTourDocker($mem['containers'] ?? [], $next['containers']);

    $found = [];
    $containers = [];
    $running = array_column(houseContainers(), 'running', 'name');
    foreach ($sources as $id => $src) {
        if ($src['kind'] === 'file' && logsTourWanted($id)) {
            $found[] = logsTourFileSource($id, $src, $mem['files'][$id] ?? null, $first ? $since : null, $since, $sources, $next['files']);
        } elseif ($src['kind'] === 'dmesg') {
            $found[] = logsTourDmesg($since);
        } elseif ($src['kind'] === 'docker' && !empty($running[$src['target']])) {
            $containers[] = $id;        // only running containers
        }
    }
    foreach (array_chunk($containers, LOGS_TOUR_PARALLEL) as $chunk) {
        $commands = [];
        foreach ($chunk as $id) {
            $commands[$id] = ['docker', 'logs', '--since', (string) $since, '--tail', (string) LOGS_TOUR_DOCKER, '--timestamps', $sources[$id]['target']];
        }
        foreach (runAll($commands, 60) as $id => [$exit, $out, $err]) {
            $acc = logsTally();
            if ($exit === 0) {
                $lines = array_map('logsClean', logsDockerLines($out, $err));
                logsTallyLines($acc, $lines, $id, null);
                $acc['lines'] = count($lines);
                $acc['partial'] = count($lines) >= LOGS_TOUR_DOCKER;
            } else {
                $acc['unreadable'] = true;
            }
            $found[] = logsTourEntry($id, $sources[$id], $acc);
        }
    }
    usort($found, fn ($a, $b) => [$b['errors'], $b['warnings'], $a['id']] <=> [$a['errors'], $a['warnings'], $b['id']]);

    $tour = [
        'time'        => $now,
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'since'       => $since,
        'first'       => $first,
        'varlog'      => $varlog,
        'docker'      => $docker,
        'sources'     => $found,
        'errors'      => array_sum(array_column($found, 'errors')),
        'warnings'    => array_sum(array_column($found, 'warnings')),
        'job'         => ['running' => false, 'since' => $prev['job']['since'] ?? $now, 'ended' => time(), 'failed' => false],
        'memory'      => $next,
    ];
    writeAtomic(logsTourFile(), jsonEncode($tour));
    return 0;
}

/**
 * Which of her files the tour reads: every log of its own. Older copies
 * (syslog.1, agent.log.1) come with their file when it was rotated since the
 * last tour, Mr. Backupsy's older runs aren't news, the parity history on
 * the flash has no error lines.
 */
function logsTourWanted(string $id): bool
{
    return !in_array($id, ['agent.1', 'syslog.1', 'parity'], true) && !preg_match('/^backup:(?!latest$)/', $id);
}

/** An empty tally: counts, the kinds of lines (normalised => group) and what didn't fit */
function logsTally(): array
{
    return ['errors' => 0, 'warnings' => 0, 'groups' => [], 'other' => 0, 'lines' => 0, 'partial' => false,
            'skipped' => 0, 'rotated' => false, 'unreadable' => false];
}

/**
 * Count the lines that look like an error or a warning. $after: only lines
 * with a time after it (lines without one count too); $kernel: line => level
 * the kernel gave it (dmesg). The newest line of a kind is its sample; "back"
 * is how many lines followed it in what was read (to find it in the reader).
 */
function logsTallyLines(array &$acc, array $lines, string $src, ?int $after, array $kernel = []): void
{
    $n = count($lines);
    foreach ($lines as $i => $line) {
        $level = logsLevel($line);
        if ($level !== 'error' && isset($kernel[$line])) {
            $level = $kernel[$line];
        }
        if ($level === '') {
            continue;
        }
        [$time, $prefix] = logsLineTime($line);
        if ($after !== null && $time !== null && $time <= $after) {
            continue;
        }
        $acc[$level === 'error' ? 'errors' : 'warnings']++;
        $key = md5(logsNormalize($line, $prefix));
        if (!isset($acc['groups'][$key])) {
            if (count($acc['groups']) >= LOGS_TOUR_KINDS) {
                $acc['other']++;
                continue;
            }
            $acc['groups'][$key] = ['level' => $level, 'count' => 0, 'first' => null, 'last' => null];
        }
        $g = &$acc['groups'][$key];
        $g['count']++;
        if ($level === 'error') {
            $g['level'] = 'error';
        }
        if ($time !== null) {
            $g['first'] ??= $time;
            $g['last'] = $time;
        }
        $g['line'] = $line;
        $g['skip'] = $prefix;           // its time: the page shows the rest in the row, the whole line unfolded
        $g['src'] = $src;
        $g['back'] = $n - 1 - $i;
        unset($g);
    }
}

/** What the page gets of a source: its counts and the most frequent kinds of lines, each with its newest line */
function logsTourEntry(string $id, array $src, array $acc): array
{
    $groups = array_values($acc['groups']);
    usort($groups, fn ($a, $b) => [$b['count'], $b['last'] ?? 0] <=> [$a['count'], $a['last'] ?? 0]);
    $top = [];
    foreach (array_slice($groups, 0, LOGS_TOUR_TOP) as $g) {
        [$g['sample'], $g['cut']] = logsCut($g['line'], LOGS_TOUR_SAMPLE);
        $g['key'] = substr(md5($id . "\n" . $g['line']), 0, 12);
        unset($g['line']);
        $top[] = $g;
    }
    return ['id' => $id, 'group' => $src['group'], 'label' => $src['label'], 'param' => $src['param'], 'kind' => $src['kind'],
            'errors' => $acc['errors'], 'warnings' => $acc['warnings'], 'lines' => $acc['lines'],
            'kinds' => count($groups), 'other' => $acc['other'], 'partial' => $acc['partial'], 'skipped' => $acc['skipped'],
            'rotated' => $acc['rotated'], 'unreadable' => $acc['unreadable'], 'groups' => $top];
}

/**
 * A file since the last tour: from where it ended then (same inode, not
 * shorter) — or, when it was rotated, the rest of the old file (now
 * "<file>.1", found by its inode) and the new one from its start. The first
 * tour reads the newest part and counts the last 24 hours. Never more than
 * LOGS_TOUR_BYTES per file.
 */
function logsTourFileSource(string $id, array $src, ?array $last, ?int $after, int $since, array $sources, array &$memory): array
{
    $acc = logsTally();
    $path = $src['target'];
    clearstatcache(true, $path);
    $st = @stat($path);
    if (!$st) {
        $acc['unreadable'] = true;
        return logsTourEntry($id, $src, $acc);
    }
    $from = 0;
    if ($last !== null) {
        if ((int) ($last['ino'] ?? -1) === (int) $st['ino'] && (int) ($last['size'] ?? PHP_INT_MAX) <= $st['size']) {
            $from = (int) $last['size'];
        } else {
            $acc['rotated'] = true;
            $after = $since;            // whatever is in the new file: only what came after the last tour
            $old = @stat("$path.1");
            if ($old && (int) $old['ino'] === (int) ($last['ino'] ?? -1) && (int) ($last['size'] ?? PHP_INT_MAX) <= $old['size']) {
                $sibling = $id;         // in the reader: syslog.1, agent.1 — if it lists it
                foreach ($sources as $sid => $s) {
                    if ($s['kind'] === 'file' && $s['target'] === "$path.1") {
                        $sibling = (string) $sid;
                    }
                }
                [$lines, , $partial, $skipped] = logsTourSlice("$path.1", (int) $last['size'], (int) $old['size']);
                logsTallyLines($acc, $lines, $sibling, null);
                $acc['lines'] += count($lines);
                $acc['partial'] = $partial;
                $acc['skipped'] += $skipped;
            }
        }
    }
    [$lines, $end, $partial, $skipped] = logsTourSlice($path, $from, (int) $st['size']);
    logsTallyLines($acc, $lines, $id, $after);
    $acc['lines'] += count($lines);
    if ($last !== null) {                // the first tour reads the newest part on purpose
        $acc['partial'] = $acc['partial'] || $partial;
        $acc['skipped'] += $skipped;
    }
    $memory[$id] = ['ino' => (int) $st['ino'], 'size' => $end];
    return logsTourEntry($id, $src, $acc);
}

/**
 * Whole lines of $path from byte $from to $size — at most LOGS_TOUR_BYTES
 * (then the newest part) — and where the last whole line ends.
 * @return array{0: list<string>, 1: int, 2: bool, 3: int}  lines, end, partial, bytes skipped
 */
function logsTourSlice(string $path, int $from, int $size): array
{
    $start = max($from, $size - LOGS_TOUR_BYTES);
    $h = $size > $start ? @fopen($path, 'r') : false;
    if (!$h) {
        return [[], $from, false, 0];
    }
    fseek($h, $start);
    $text = (string) fread($h, $size - $start);
    fclose($h);
    $cut = strrpos($text, "\n");
    if ($cut === false) {               // no whole line yet: the next tour starts here again
        return [[], $start, $start > $from, $start - $from];
    }
    $lines = explode("\n", substr($text, 0, $cut));
    if ($start > $from) {
        array_shift($lines);            // began inside a line
    }
    return [array_map('logsClean', $lines), $start + $cut + 1, $start > $from, $start - $from];
}

/** dmesg -T (as the reader shows it), with the kernel's own levels on top of the words */
function logsTourDmesg(int $since): array
{
    $src = ['group' => 'unraid', 'label' => 'dmesg', 'kind' => 'dmesg', 'target' => '', 'param' => ''];
    $acc = logsTally();
    $r = runAll(['all' => ['dmesg', '-T'], 'error' => ['dmesg', '-T', '--level=emerg,alert,crit,err'], 'warn' => ['dmesg', '-T', '--level=warn']], 20);
    if ($r['all'][0] !== 0) {
        $acc['unreadable'] = true;
        return logsTourEntry('dmesg', $src, $acc);
    }
    $kernel = [];
    foreach (['warn', 'error'] as $level) {
        foreach ($r[$level][0] === 0 ? explode("\n", $r[$level][1]) : [] as $line) {
            if ($line !== '') {
                $kernel[logsClean($line)] = $level;
            }
        }
    }
    // only what came since: the newest lines (dmesg is in order, so "back" stays right for the reader)
    $lines = array_values(array_filter(array_map('logsClean', explode("\n", rtrim($r['all'][1], "\n"))),
        fn ($l) => (logsLineTime($l)[0] ?? PHP_INT_MAX) > $since));
    logsTallyLines($acc, $lines, 'dmesg', null, $kernel);
    $acc['lines'] = count($lines);
    return logsTourEntry('dmesg', $src, $acc);
}

/** /var/log: how full, the biggest files and how much they grew since the last tour (sizes by lstat; links stay out) */
function logsTourVarlog(array $before, array &$memory, array $sources): array
{
    $byPath = [];
    foreach ($sources as $id => $s) {
        if ($s['kind'] === 'file') {
            $byPath[$s['target']] ??= $id;
        }
    }
    $root = @lstat(LOGS_VARLOG);
    $files = [];
    $sum = 0;
    $stack = $root ? [LOGS_VARLOG] : [];
    while ($stack && count($files) < LOGS_TOUR_WALK) {
        $dir = array_pop($stack);
        foreach (@scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = "$dir/$name";
            $st = @lstat($path);
            if (!$st) {
                continue;
            }
            $type = $st['mode'] & 0170000;
            if ($type === 0040000 && $st['dev'] === $root['dev']) {
                $stack[] = $path;
            }
            if ($type !== 0100000 || count($files) >= LOGS_TOUR_WALK) {
                continue;               // folders, links (packages → /var/lib/pkgtools) and the like
            }
            $files[] = ['path' => $path, 'size' => (int) $st['size'], 'time' => (int) $st['mtime']];
            $sum += (int) $st['size'];
            $memory[$path] = (int) $st['size'];
        }
    }
    usort($files, fn ($a, $b) => [$b['size'], $a['path']] <=> [$a['size'], $b['path']]);
    $top = [];
    foreach (array_slice($files, 0, LOGS_TOUR_FILES) as $f) {
        $f['grew'] = isset($before[$f['path']]) ? $f['size'] - (int) $before[$f['path']] : null;
        $f['source'] = $byPath[$f['path']] ?? null;
        $top[] = $f;
    }
    return logsVarlog() + ['files' => $top, 'count' => count($files), 'sum' => $sum];
}

/**
 * Docker's container logs: per container the size of its log file(s) by
 * stat (never read here), how much they grew since the last tour, and the
 * limit it was created with; Unraid's setting (Settings → Docker → Docker
 * LOG rotation) from docker.cfg. Only while Docker answers.
 */
function logsTourDocker(array $before, array &$memory): array
{
    $cfg = readCfg(LOGS_DOCKER_CFG);
    $rotation = ['on' => strtolower($cfg['DOCKER_LOG_ROTATION'] ?? '') === 'yes',
                 'size' => ($cfg['DOCKER_LOG_SIZE'] ?? '') !== '' ? $cfg['DOCKER_LOG_SIZE'] : null,
                 'files' => ($cfg['DOCKER_LOG_FILES'] ?? '') !== '' ? $cfg['DOCKER_LOG_FILES'] : null];
    $out = ['ok' => false, 'enabled' => strtolower($cfg['DOCKER_ENABLED'] ?? 'yes') !== 'no', 'rotation' => $rotation,
            'root' => null, 'containers' => [], 'total' => 0];
    [$exit, $ids] = run(['docker', 'ps', '-aq', '--no-trunc'], 20);
    if ($exit !== 0) {
        return $out;
    }
    $out['ok'] = true;
    $ids = array_slice(array_values(array_filter(explode("\n", trim($ids)), fn ($x) => (bool) preg_match('/^[0-9a-f]{12,64}$/', $x))), 0, 500);
    if (!$ids) {
        return $out;
    }
    [, $text] = run(array_merge(['docker', 'inspect', '--format',
        "{{json .Name}}\t{{json .State.Status}}\t{{json .LogPath}}\t{{json .HostConfig.LogConfig}}"], $ids), 30);
    foreach (explode("\n", trim($text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 4) {
            continue;
        }
        $name = ltrim((string) json_decode($f[0]), '/');
        $log = (string) json_decode($f[2]);
        $config = json_decode($f[3], true);
        $opts = is_array($config['Config'] ?? null) ? $config['Config'] : [];
        $size = null;
        if ($log !== '' && $log[0] === '/' && !str_contains($log, '/../')) {
            $size = 0;
            foreach (array_merge([$log], glob($log . '.*') ?: []) as $p) {      // rotated parts: <id>-json.log.1, .2.gz …
                $size += (int) @filesize($p);
            }
            $out['root'] ??= preg_replace('#/containers/[^/]+/[^/]+$#', '', $log);
            $memory[$name] = $size;
        }
        $out['containers'][] = [
            'name'     => $name,
            'running'  => json_decode($f[1]) === 'running',
            'driver'   => is_array($config) ? (string) ($config['Type'] ?? '') : '',
            'size'     => $size,
            'grew'     => $size !== null && isset($before[$name]) ? $size - (int) $before[$name] : null,
            'max_size' => is_string($opts['max-size'] ?? null) ? $opts['max-size'] : null,
            'max_file' => is_string($opts['max-file'] ?? null) ? $opts['max-file'] : null,
        ];
        $out['total'] += (int) $size;
    }
    usort($out['containers'], fn ($a, $b) => [$b['size'] ?? -1, $a['name']] <=> [$a['size'] ?? -1, $b['name']]);
    return $out;
}

// ===================================================================== the tour: lines

/**
 * The time at the start of a line, if it has one she knows, and how long
 * that part is (it stays out of the comparison).
 * @return array{0: ?int, 1: int}
 */
function logsLineTime(string $line): array
{
    $t = false;
    if (preg_match(LOGS_TIME_ISO, $line, $m)) {
        $t = strtotime("$m[1]-$m[2]-$m[3] $m[4]" . (isset($m[5]) && $m[5] !== '' ? ' ' . trim($m[5]) : ''));
    } elseif (preg_match(LOGS_TIME_SYSLOG, $line, $m)) {
        $t = strtotime("$m[1] $m[2] $m[3]");
        if ($t !== false && $t > time() + 86400) {          // no year in the syslog: December's lines read in January
            $t = strtotime("$m[1] $m[2] " . ((int) date('Y') - 1) . " $m[3]");
        }
    } elseif (preg_match(LOGS_TIME_DMESG, $line, $m)) {
        $t = strtotime("$m[1] $m[2] $m[4] $m[3]");
    } elseif (preg_match(LOGS_TIME_PHP, $line, $m)) {
        $t = strtotime("$m[1]-$m[2]-$m[3] $m[4]" . (isset($m[5]) && $m[5] !== '' ? " $m[5]" : ''));
    } else {
        return [null, 0];
    }
    return [$t === false ? null : $t, strlen($m[0])];
}

/**
 * The line as she compares it: without its leading time; UUIDs, MAC and IP
 * addresses, hex ids, and then every number replaced by a placeholder;
 * lower case, spaces squeezed, the first 240 characters. Lines that only
 * differ there are one kind.
 */
function logsNormalize(string $line, int $prefix = 0): string
{
    $s = substr($line, $prefix);
    $s = preg_replace([
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',     // UUIDs
        '/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i',                                     // MAC addresses
        '/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b/',                                // IPv4 (and a port)
        '/\b[0-9a-f]{1,4}(?::[0-9a-f]{0,4}){2,7}\b/i',                               // IPv6
        '/\b0x[0-9a-f]+\b/i',                                                        // hex numbers
        '/\b(?=[0-9a-f]*\d)(?=[0-9a-f]*[a-f])[0-9a-f]{8,}\b/i',                      // hex ids (containers, hashes)
        '/\d+/',                                                                     // every other number: times, PIDs, sizes
        '/\s+/',
    ], ['<uuid>', '<mac>', '<ip>', '<ip>', '<hex>', '<hex>', '#', ' '], $s) ?? $s;
    return substr(strtolower(trim($s)), 0, 240);
}

/**
 * At most $max bytes, not ending inside a UTF-8 character (a sample is the
 * beginning of the line the reader shows).
 * @return array{0: string, 1: bool}
 */
function logsCut(string $s, int $max): array
{
    if (strlen($s) <= $max) {
        return [$s, false];
    }
    $t = substr($s, 0, $max);
    return [preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $t) ?? $t, true];
}
