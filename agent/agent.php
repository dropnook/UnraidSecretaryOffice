#!/usr/bin/php
<?php
declare(strict_types=1);

/*
 * Unraid Secretary Office — agent
 *
 * The web UI only shows things; this agent does the work on the host (zfs,
 * btrfs, docker, Unraid's configuration). It runs on the PHP that ships with
 * Unraid, one of two ways (see src/place.php):
 *
 *   plugin   a service of the plugin: scripts/agent.sh starts it (at install,
 *            boot and array start, through a small supervisor that restarts
 *            it if it dies) and stops it when the array stops
 *   stack    the "agent" service in compose.yaml: a privileged container in
 *            the host's PID namespace that nsenter's into the host's mount
 *            namespace
 *
 * Every secretary ("desk") is one file in agent/desks/. It registers the
 * actions it handles (see desk() in lib/util.php); the agent loads them all.
 *
 * Connection: a mailbox folder in the data directory. The web UI drops
 * <id>.request there, the agent picks it up within ~150 ms and writes
 * <id>.response next to it. Deliberately no unix socket: a bound socket keeps
 * the pool busy and Unraid could not stop the array any more.
 *
 *   php agent.php run       run in the foreground (what the container and agent.sh do)
 *   php agent.php status    is an agent running?
 *   php agent.php job snapshot-plans   run the snapshot schedules that are due (User Scripts calls this)
 *   php agent.php job <name> [args]    a job a desk registers ('jobs' in desk()), e.g. embycache, gather
 *
 * When one of its files changes, the running agent lints the new code and
 * restarts itself in place.
 *
 * Environment (both optional):
 *   OFFICE_DATA_DIR            data folder shared with the web UI (default: the plugin's
 *                              DATA_DIR, in the stack ../data)
 *   OFFICE_WEB_UID             uid of the web server (default: 0 — Unraid's php-fpm
 *                              runs as root —, in the stack 33, www-data in php:apache)
 */

const AGENT_VERSION = '1.27.0';
const RUN_DIR       = '/var/run/unraid-secretary-office';
const PID_FILE      = RUN_DIR . '/agent.pid';
const TICK_US       = 150000;
const LOG_MAX       = 512 * 1024;
const FILE_UID      = 99;    // nobody:users, like everything else in appdata
const FILE_GID      = 100;

require dirname(__DIR__) . '/src/place.php';

define('OFFICE_DIR', dirname(__DIR__));
define('AS_PLUGIN', officeIsPlugin(OFFICE_DIR));
define('OFFICE_WEB', AS_PLUGIN ? OFFICE_DIR : OFFICE_DIR . '/public');     // desks/<id>/desk.json & co.
define('DATA_DIR', rtrim(getenv('OFFICE_DATA_DIR') ?: (AS_PLUGIN ? officePluginDataDir() : OFFICE_DIR . '/data'), '/'));
define('MAILBOX', DATA_DIR . '/mailbox');
define('OFFICE_PRIVATE', DATA_DIR . '/office');
define('AGENT_INFO', DATA_DIR . '/agent.json');
define('AGENT_LOG', DATA_DIR . '/agent.log');
define('WEB_UID', (int) (getenv('OFFICE_WEB_UID') ?: (AS_PLUGIN ? 0 : 33)));

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/mounts.php';
require __DIR__ . '/lib/backupscript.php';
require __DIR__ . '/lib/house.php';
require __DIR__ . '/lib/snapshotplans.php';
require __DIR__ . '/lib/officeupdate.php';
require __DIR__ . '/lib/metrics.php';
foreach (glob(__DIR__ . '/desks/*.php') ?: [] as $deskFile) {
    require $deskFile;
}

$GLOBALS['started'] = time();

// ===================================================================== commands

function main(array $argv): int
{
    switch ($argv[1] ?? 'run') {
        case 'run':
            return serve();
        case 'job':
            // jobs the host runs on its own (User Scripts), e.g. Ms. Snapshotini's schedules
            if (($argv[2] ?? '') === 'snapshot-plans' && is_dir(DATA_DIR)) {
                return snapPlansRunDue();
            }
            foreach (desks() as $desk) {
                if (isset($desk['jobs'][$argv[2] ?? ''])) {
                    return is_dir(DATA_DIR) ? (int) $desk['jobs'][$argv[2]](array_slice($argv, 3)) : 0;
                }
            }
            fwrite(STDERR, "Usage: php agent.php job snapshot-plans|<a desk's job>\n");
            return 2;
        case 'status':
            $pid = runningAgent();
            echo $pid ? "Agent is running (PID $pid).\n" : "Agent is not running.\n";
            return $pid ? 0 : 3;
    }
    fwrite(STDERR, "Usage: php agent.php run|status|job <name>\n");
    return 2;
}

function runningAgent(): ?int
{
    $pid = (int) @file_get_contents(PID_FILE);
    if ($pid <= 1 || !posix_kill($pid, 0)) {
        return null;
    }
    return str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php') ? $pid : null;
}

// ===================================================================== service

function serve(): int
{
    chdir('/');     // keep nothing in the pool busy
    umask(0022);

    @mkdir(RUN_DIR, 0700, true);
    $lock = fopen(PID_FILE, 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another agent is already running.\n");
        sleep(30);      // don't let a restart policy spin
        return 1;
    }
    ftruncate($lock, 0);
    fwrite($lock, (string) getmypid());
    fflush($lock);

    $stop = false;
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, function () use (&$stop) { $stop = true; });
    }
    set_error_handler(function (int $no, string $text, string $file, int $line): bool {
        if (error_reporting() & $no) {      // things silenced with @ stay out of the log
            logLine("PHP: $text (" . basename($file) . ":$line)");
        }
        return true;
    });

    $code = codeStamp();
    $ready = false;
    $lastPulse = $lastLook = $lastCleanup = 0;

    while (!$stop) {
        clearstatcache();
        if (!is_dir(DATA_DIR) && !makeDataDir()) {     // array stopped: wait until it is back
            $ready = false;
            sleep(5);
            continue;
        }
        if (!$ready) {
            setUp();
            $ready = true;
        }

        processMailbox();
        foreach (desks() as $id => $desk) {
            if ($desk['tick']) {
                try {
                    ($desk['tick'])();
                } catch (Throwable $e) {
                    logLine("$id: " . $e->getMessage());
                }
            }
        }
        metricsTick();          // the office's numbers for Prometheus, once a minute (lib/metrics.php)

        $now = time();
        if ($now - $lastPulse >= 20) {
            @touch(AGENT_INFO);
            $lastPulse = $now;
        }
        if ($now - $lastCleanup >= 60) {
            cleanUpMailbox();
            $lastCleanup = $now;
        }
        if ($now - $lastLook >= 3) {
            $lastLook = $now;
            $new = codeStamp();
            if ($new !== $code) {
                $code = $new;
                if (codeIsValid()) {
                    logLine('Agent code changed — restarting');
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    pcntl_exec('/bin/sh', ['-c', 'exec "$@"' . closeInheritedFds(), 'sh', PHP_BINARY, __FILE__, 'run']);
                    exit(1);
                }
            }
        }
        usleep(TICK_US);
    }

    logLine('Agent stopped');
    if (is_dir(DATA_DIR)) {
        writeInfo(false);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink(PID_FILE);
    return 0;
}

/**
 * A fresh plugin: create the data folder once the array runs — but only in an
 * appdata that is there, never a new share on some disk.
 */
function makeDataDir(): bool
{
    if (!AS_PLUGIN || (readCfg('/var/local/emhttp/var.ini')['fsState'] ?? '') !== 'Started') {
        return false;
    }
    $appdata = dirname(DATA_DIR, 2);         // <appdata>/UnraidSecretaryOffice/data
    if (!is_dir($appdata) || !@mkdir(DATA_DIR, 0755, true)) {
        return false;
    }
    @lchown(dirname(DATA_DIR), FILE_UID);
    @lchgrp(dirname(DATA_DIR), FILE_GID);
    logLine('Created the data folder ' . DATA_DIR);
    return true;
}

function setUp(): void
{
    dataDirTighten();
    mailboxEnsure();
    foreach (glob(MAILBOX . '/*') ?: [] as $old) {
        @unlink($old);
    }
    // the office's own files (PIN): only the web server may read them
    privateDirEnsure(OFFICE_PRIVATE, 0700, false);
    writeInfo(true);
    try {
        userScriptsMigrate();          // the office's User Scripts entries under their current names
        officeJobsFromUserScripts();   // moved to the plugin: their schedules go into its cron file
    } catch (Throwable $e) {
        logLine('User Scripts migration: ' . $e->getMessage());
    }
    logLine('Agent started (v' . AGENT_VERSION . ', PID ' . getmypid() . ', desks: ' . implode(', ', array_keys(desks())) . ')');
    metricsStart();         // its folder in /mnt/addons and a first write, before the caretaker's start tour looks
    foreach (desks() as $id => $desk) {
        if ($desk['start']) {
            try {
                ($desk['start'])();
            } catch (Throwable $e) {
                logLine("$id: start failed: " . $e->getMessage());
            }
        }
    }
}

/**
 * The data folder: nobody but its owner may create, rename or replace things
 * in it — the mailbox and the state files lie there, and the agent (root)
 * writes into it. Unraid's "New Permissions" or a copy can leave it open to
 * everyone; then group and others lose write access (the owner keeps his).
 */
function dataDirTighten(): void
{
    clearstatcache(true, DATA_DIR);
    $st = @lstat(DATA_DIR);
    if ($st && ($st['mode'] & 0170000) === 0040000 && ($st['mode'] & 0022) && @chmod(DATA_DIR, $st['mode'] & 07755)) {
        logLine(sprintf('The data folder was writable by group or others (%o): now %o', $st['mode'] & 07777, $st['mode'] & 07755));
    }
}

/**
 * Is $dir a real folder (no link) of the web server's user that others can't
 * get into? The mailbox and data/office must be.
 */
function privateDirOk(string $dir): bool
{
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    return $st !== false && ($st['mode'] & 0170000) === 0040000 && $st['uid'] === WEB_UID && ($st['mode'] & 0007) === 0;
}

/**
 * Makes $dir a folder of the web server's user with $mode. Never through a
 * link: chown/chmod would change whatever it points to. A link where the
 * mailbox belongs is replaced by a folder ($replaceLink); at data/office it is
 * left alone and reported (the PIN may lie behind it).
 */
function privateDirEnsure(string $dir, int $mode, bool $replaceLink): bool
{
    clearstatcache(true, $dir);
    if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
        if (!$replaceLink || !@unlink($dir)) {
            logLine("$dir is no folder of its own (a link?) — left alone, fix it by hand");
            return false;
        }
        logLine("$dir was no folder of its own (a link?) — replaced by a folder");
    }
    if (!is_dir($dir) && !@mkdir($dir, $mode)) {
        return false;
    }
    if (!is_link($dir)) {
        @lchown($dir, WEB_UID);
        @lchgrp($dir, WEB_UID);
        @chmod($dir, $mode);
    }
    return privateDirOk($dir);
}

function mailboxEnsure(): bool
{
    $ok = privateDirEnsure(MAILBOX, 0770, true);
    if (!$ok) {
        logLine('The mailbox ' . MAILBOX . ' is not the web server\'s own folder — requests are ignored until it is');
    }
    return $GLOBALS['mailboxOk'] = $ok;
}

function writeInfo(bool $running): void
{
    writeAtomic(AGENT_INFO, jsonEncode([
        'running' => $running,
        'version' => AGENT_VERSION,
        'pid'     => getmypid(),
        'started' => $GLOBALS['started'],
        'host'    => hostname(),
        'desks'   => array_keys(desks()),
    ]));
}

/** All agent files (and src/place.php, shared with the web side), so any change triggers a restart */
function codeFiles(): array
{
    $files = array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/lib/*.php') ?: [], glob(__DIR__ . '/desks/*.php') ?: [],
        [dirname(__DIR__) . '/src/place.php']);
    sort($files);
    return $files;
}

function codeStamp(): string
{
    $stamp = '';
    foreach (codeFiles() as $file) {
        clearstatcache(true, $file);
        $stamp .= $file . ':' . @filemtime($file) . ':' . @filesize($file) . ';';
    }
    return $stamp;
}

function codeIsValid(): bool
{
    foreach (codeFiles() as $file) {
        [$exit, , $err] = run([PHP_BINARY, '-l', $file], 30);
        if ($exit !== 0) {
            logLine('New agent code has errors, keeping the old one: ' . trim($err));
            return false;
        }
    }
    return true;
}

/**
 * PHP opens its script without close-on-exec, so a new image would inherit it.
 * Close everything from fd 3 on when restarting.
 */
function closeInheritedFds(): string
{
    $s = '';
    for ($fd = 3; $fd < 64; $fd++) {
        $s .= " $fd>&-";
    }
    return $s;
}

// ===================================================================== mailbox

function processMailbox(): void
{
    // only the web server's own folder (checked anew every time: a cheap lstat)
    if (!privateDirOk(MAILBOX)) {
        if ($GLOBALS['mailboxOk'] ?? true) {
            mailboxEnsure();
        }
        return;
    }
    $GLOBALS['mailboxOk'] = true;
    $names = @scandir(MAILBOX);
    if (!$names) {
        return;
    }
    $requests = [];
    foreach ($names as $name) {
        if (preg_match('/^([a-f0-9]{32})\.request$/', $name, $m)) {
            $requests[$m[1]] = (int) @filemtime(MAILBOX . "/$name");
        }
    }
    asort($requests);
    foreach (array_keys($requests) as $id) {
        $path = MAILBOX . "/$id.request";
        // a plain file the web server (or root) wrote — no link, nobody else's
        $st = @lstat($path);
        if (!$st || ($st['mode'] & 0170000) !== 0100000 || !in_array($st['uid'], [0, WEB_UID], true)) {
            @unlink($path);
            logLine("Mailbox: ignored $id.request (not a plain file of the web server)");
            continue;
        }
        $raw = @file_get_contents($path, false, null, 0, 1 << 20);
        @unlink($path);
        if ($raw === false) {
            continue;
        }
        $response = handle($raw);
        try {
            writeAtomic(MAILBOX . "/$id.response", jsonEncode($response), 0640, WEB_UID, WEB_UID);
        } catch (Throwable $e) {
            logLine('Could not deliver a response: ' . $e->getMessage());
        }
    }
}

function handle(string $raw): array
{
    try {
        $request = json_decode($raw, true, 16);
        if (!is_array($request) || !is_string($request['action'] ?? null)) {
            throw new Problem('bad_request');
        }
        [$deskId, $action] = explode('.', $request['action'], 2) + [1 => ''];
        if ($deskId === 'office' && $action === 'ping') {
            return ['ok' => true, 'version' => AGENT_VERSION, 'host' => hostname()];
        }
        $handler = desks()[$deskId]['actions'][$action] ?? null;
        if (!$handler) {
            throw new Problem('unknown_action', ['action' => $request['action']]);
        }
        return $handler($request);
    } catch (Problem $p) {
        return ['ok' => false, 'error' => $p->toArray()];
    } catch (Throwable $e) {
        logLine('Error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        return ['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]];
    }
}

function cleanUpMailbox(): void
{
    dataDirTighten();
    if (!privateDirOk(MAILBOX)) {
        mailboxEnsure();
        return;
    }
    $limit = time() - 600;
    foreach (glob(MAILBOX . '/{*,.*.tmp}', GLOB_BRACE) ?: [] as $file) {
        if (is_file($file) && @filemtime($file) < $limit) {
            @unlink($file);
        }
    }
}

// =====================================================================

if (PHP_SAPI === 'cli' && !defined('AGENT_LIBRARY_ONLY')) {
    $zone = preg_replace('#^.*/zoneinfo/#', '', (string) @readlink('/etc/localtime'));
    date_default_timezone_set(in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
    exit(main($argv));
}
