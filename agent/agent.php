#!/usr/bin/php
<?php
declare(strict_types=1);

/*
 * Unraid Secretary Office — agent
 *
 * The web UI only shows things; this agent does the work on the host (zfs,
 * btrfs, docker, Unraid's configuration). It runs on the PHP that ships with
 * Unraid, as a service of the plugin (see src/place.php): scripts/agent.sh
 * starts it (at install, boot and array start, through a small supervisor
 * that restarts it if it dies) and stops it when the array stops.
 *
 * Every secretary ("desk") is one file in agent/desks/. It registers the
 * actions it handles (see desk() in lib/util.php); the agent loads them all.
 *
 * Connection: a mailbox folder in the data directory. The web UI drops
 * <id>.request there, the agent picks it up within ~150 ms and writes
 * <id>.response next to it. Deliberately no unix socket: a bound socket keeps
 * the pool busy and Unraid could not stop the array any more.
 *
 *   php agent.php run       run in the foreground (what agent.sh does)
 *   php agent.php nightshift   the night watchman's night shift while the array is stopped (agent.sh nightshift):
 *                           RAM and flash only, never the data folder (watchmanNightRound() in desks/watchman.php)
 *   php agent.php status    is an agent running?
 *   php agent.php job snapshot-plans   run the snapshot schedules that are due (the plugin's cron file, via scripts/job.sh)
 *   php agent.php job <name> [args]    a job a desk registers ('jobs' in desk()), e.g. embycache, gather
 *
 * When one of its files changes, the running agent lints the new code and
 * restarts itself in place.
 *
 * Environment (tests only):
 *   OFFICE_DATA_DIR            another data folder than the plugin's DATA_DIR
 *   OFFICE_RUN_DIR             another folder in RAM than the office's (RUN_DIR, see officeRunDir())
 */

require dirname(__DIR__) . '/src/place.php';
require_once dirname(__DIR__) . '/src/words.php';     // Unraid's own words in the texts (shared with the web side)

const AGENT_VERSION = '1.53.1';
define('RUN_DIR', officeRunDir());          // RAM, root only (0700); the web side's officeRunDir()
const PID_FILE      = RUN_DIR . '/agent.pid';
const AGENT_HEARTBEAT = RUN_DIR . '/agent.json';     // who is at work; its mtime is the pulse (writeInfo(), agentPulse())
const DOORBELL      = RUN_DIR . '/doorbell';   // a FIFO the web side rings after dropping a request (doorbellOpen(), agentNap())
const TICK_US       = 150000;
const LOG_MAX       = 512 * 1024;
const FILE_UID      = 99;    // nobody:users, like everything else in appdata
const FILE_GID      = 100;
const WEB_UID       = 0;     // the web server's user: Unraid's php-fpm runs as root
// The array runs: Started — and Unraid's «Started, formatting/clearing» (fsState Formatting, Clearing: a new disk is
// formatted or cleared for hours while the array runs). One definition with scripts/agent.sh (ARRAY_RUNNING,
// array_started — which starts the agent or the night shift) and the backup engine (lib/common.sh array_stopping():
// only Stopping and Stopped end a run); tests/run.php compares them.
const ARRAY_RUNNING = ['Started', 'Formatting', 'Clearing'];

define('OFFICE_DIR', dirname(__DIR__));
// the web files (desks/<id>/desk.json, lang/ …) lie at the top of the plugin's folder; the tests set the repository's public/
defined('OFFICE_WEB') || define('OFFICE_WEB', OFFICE_DIR);
// the night shift keeps nothing under /mnt: its log lies in RAM next to its book
define('NIGHT_MODE', PHP_SAPI === 'cli' && !defined('AGENT_LIBRARY_ONLY') && ($argv[1] ?? '') === 'nightshift');
// The data folder as the user set it (DATA_DIR in the plugin's .cfg, usually /mnt/user/appdata/…): what the user
// sees and what other programs get (the backup engine's UB_DATA, Ms. Dustdevil's «Where is what»). The agent itself
// reads and writes DATA_DIR: the same folder, on its pool directly when it lies in an exclusive share
// (officeUnraidPath() — past shfs; decided at every start = every array start, looked at again every minute,
// dataWayLook()). The night shift never looks under /mnt.
define('DATA_DIR_USER', rtrim(getenv('OFFICE_DATA_DIR') ?: officePluginDataDir(), '/'));
define('DATA_DIR', NIGHT_MODE ? DATA_DIR_USER : officeUnraidPath(DATA_DIR_USER));
define('MAILBOX', DATA_DIR . '/mailbox');
define('OFFICE_PRIVATE', DATA_DIR . '/office');
define('AGENT_INFO', DATA_DIR . '/agent.json');
define('AGENT_LOG', NIGHT_MODE ? RUN_DIR . '/nightshift/nightshift.log' : DATA_DIR . '/agent.log');

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/mounts.php';
require __DIR__ . '/lib/backupscript.php';
require __DIR__ . '/lib/house.php';
require __DIR__ . '/lib/snapshotplans.php';
require __DIR__ . '/lib/officeupdate.php';
require __DIR__ . '/lib/metrics.php';
require __DIR__ . '/lib/migrate.php';
require __DIR__ . '/lib/report.php';
require __DIR__ . '/lib/supporter.php';     // the key a tip made, asked of the support page (office.supporter_claim)
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
            // jobs the host runs on its own (the plugin's cron file, atd), e.g. Ms. Snapshotini's schedules
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
        case 'nightshift':
            return nightShift();
        case 'status':
            $pid = runningAgent();
            echo $pid ? "Agent is running (PID $pid).\n" : "Agent is not running.\n";
            return $pid ? 0 : 3;
    }
    fwrite(STDERR, "Usage: php agent.php run|nightshift|status|job <name>\n");
    return 2;
}

/**
 * The agent's PHP warnings, notices and deprecations (set_error_handler() in serve()): one line «PHP: <text> (<file>:<line>)»
 * in its log — what `@` silenced stays out (error_reporting() is lowered for that call). The same one at the same place
 * once an hour (a tick runs every 150 ms: a warning there would fill the log), then with how often it came meanwhile.
 * $log: the tests' own (else logLine()); $now likewise.
 */
function agentPhpError(int $no, string $text, string $file, int $line, ?callable $log = null, ?int $now = null): bool
{
    static $seen = [];
    if (!(error_reporting() & $no)) {
        return true;
    }
    $now ??= time();
    $key = "$no|$file|$line|$text";
    $was = $seen[$key] ?? null;
    if ($was !== null && $now - $was[0] < 3600) {
        $seen[$key][1]++;
        return true;
    }
    if (count($seen) > 200) {
        $seen = array_slice($seen, -100, null, true);     // bounded: the oldest go
    }
    $seen[$key] = [$now, 0];
    ($log ?? 'logLine')('PHP: ' . mb_substr($text, 0, 300) . ' (' . basename($file) . ":$line)" . ($was !== null && $was[1] > 0 ? " — {$was[1]} more within the hour before" : ''));
    return true;
}

function runningAgent(): ?int
{
    $pid = (int) @file_get_contents(PID_FILE);
    if ($pid <= 1 || !posix_kill($pid, 0)) {
        return null;
    }
    return str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php') ? $pid : null;
}

// ===================================================================== the night shift

/**
 * The night watchman's night shift (scripts/agent.sh nightshift): while the array isn't started — after an
 * array stop, and from boot until the first start — a round of his RAM and flash parts every WATCH_EVERY
 * (watchmanNightRound()). cwd /, nothing open under /mnt, its book and log in WATCH_NIGHT_DIR (RAM, 0700).
 * Ends when the array is started or the agent runs (never two of them: WATCH_NIGHT_LOCK), at SIGTERM
 * (agent.sh stops it before the agent starts), and at once without a mirror (he isn't hired, or had no
 * round yet): exit 0 — the supervisor in agent.sh doesn't start it again then.
 */
function nightShift(): int
{
    chdir('/');
    umask(0077);
    @mkdir(WATCH_NIGHT_DIR, 0700, true);
    @chmod(WATCH_NIGHT_DIR, 0700);
    $lock = @fopen(WATCH_NIGHT_LOCK, 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "The night shift is on already.\n");
        return 0;
    }
    ftruncate($lock, 0);
    fwrite($lock, (string) getmypid());
    fflush($lock);
    $stop = false;
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, function () use (&$stop) { $stop = true; });
    }
    @proc_nice(10);
    $why = null;
    $next = 0;
    $rounds = 0;
    while (!$stop) {
        clearstatcache();
        $agent = runningAgent();
        if ($agent !== null && $agent !== getmypid()) {
            $why = 'the agent is at work';
            break;
        }
        if (arrayRunning()) {
            $why = 'the array is started — the agent takes over';
            break;
        }
        if (time() >= $next) {
            $next = time() + WATCH_EVERY;
            try {
                $r = watchmanNightRound(watchmanNightPaths());
                if ($r === null) {
                    $why = 'no mirror of his baseline (the night watchman isn\'t hired, or had no round yet) — no night shift';
                    break;
                }
                $rounds++;
                if ($r['begun'] ?? null) {
                    nightLog("Night shift begins (the night watchman's baseline from the mirror in " . ($r['begun']['from'] === 'ram' ? 'RAM' : 'the flash') . ')');
                }
                if ($r['added']) {
                    $kinds = array_count_values($r['added']);
                    nightLog(count($r['added']) . ' new in the night\'s book (' . implode(', ', array_map(fn ($k, $n) => "$k×$n", array_keys($kinds), $kinds)) . ')');
                }
                foreach ($r['told'] as $t) {
                    nightLog("Unraid's notifications " . ($t['sent'] ? 'told' : 'could not be told') . " about {$t['n']} × {$t['kind']}");
                }
            } catch (Throwable $e) {
                nightLog('Round failed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
            }
        }
        sleep(1);
    }
    nightLog('Night shift ends' . ($why !== null ? " ($why)" : '') . " after $rounds round" . ($rounds === 1 ? '' : 's'));
    flock($lock, LOCK_UN);
    fclose($lock);
    return 0;
}

/** Does the array run (ARRAY_RUNNING)? var.ini's fsState — another file for the tests */
function arrayRunning(string $varIni = '/var/local/emhttp/var.ini'): bool
{
    return in_array((string) (readCfg($varIni)['fsState'] ?? ''), ARRAY_RUNNING, true);
}

/** A line in the night shift's own log (RAM: WATCH_NIGHT_DIR/nightshift.log, the newest 256 KB) */
function nightLog(string $text): void
{
    $log = WATCH_NIGHT_DIR . '/nightshift.log';
    clearstatcache(true, $log);
    if (is_link($log)) {
        @unlink($log);
    }
    if (@filesize($log) > 256 * 1024) {
        @rename($log, "$log.1");
    }
    @file_put_contents($log, date('Y-m-d H:i:s') . '  ' . str_replace(["\r", "\n"], ['', ' | '], trim($text)) . "\n", FILE_APPEND);
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
    // Unraid's php.ini has error_reporting = 22517 (no warnings, notices or deprecations): the agent's «PHP:» lines
    // could never fire (QA 2026-10-08, finding 6). Everything is reported now — `@` still silences (PHP 8 lowers the
    // level for the call), and the same warning at the same place goes into the log once an hour (agentPhpError())
    error_reporting(E_ALL);
    set_error_handler('agentPhpError');

    $code = codeStamp();
    $ready = false;
    $lastPulse = $lastLook = $lastCleanup = 0;
    $bell = doorbellOpen();
    $nextTick = 0.0;

    while (!$stop) {
        clearstatcache();
        if (!is_dir(DATA_DIR) && !makeDataDir()) {     // array stopped: wait until it is back
            $ready = false;
            sleep(5);
            if (arrayRunning() && ($way = dataWayLook()) !== null) {
                restartInPlace($lock, "The data folder is reached another way now ($way)");
            }
            continue;
        }
        if (!$ready) {
            setUp();
            $ready = true;
        }

        processMailbox();
        // the rest of the round as ever — 150 ms after the last one ended —, however often the doorbell wakes the
        // agent in between
        if (microtime(true) < $nextTick) {
            agentNap($bell, $nextTick);
            continue;
        }
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
            agentPulse();
            $lastPulse = $now;
        }
        if ($now - $lastCleanup >= 60) {
            cleanUpMailbox();
            officeNotifyLangKeep();     // the office's language into RAM, for the night shift's notifications (lib/house.php)
            $lastCleanup = $now;
            if (($way = dataWayLook()) !== null) {
                restartInPlace($lock, "The data folder is reached another way now ($way)");
            }
        }
        if ($now - $lastLook >= 3) {
            $lastLook = $now;
            $new = codeStamp();
            if ($new !== $code) {
                $code = $new;
                if (codeIsValid()) {
                    restartInPlace($lock, 'Agent code changed');
                }
            }
        }
        $nextTick = microtime(true) + TICK_US / 1e6;
        agentNap($bell, $nextTick);     // until the next round — or the doorbell, whichever comes first
    }

    logLine('Agent stopped');
    writeInfo(false);
    doorbellClose($bell);
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink(PID_FILE);
    return 0;
}

/**
 * The doorbell: a FIFO in RUN_DIR (RAM, root only), so a request in the mailbox is picked up at once instead of at
 * the agent's next round (on average 100 ms later: the agent napped 150 ms between rounds, the page looked for the
 * answer every 50 ms). The web side writes a byte after dropping a request (src/mailbox.php agentRing()); the agent
 * waits on it between its rounds with the round's time as the limit (agentNap()) — no ring, no doorbell: as before.
 * Made anew at every start: whatever lies there goes (an old doorbell — a FIFO keeps no bytes once nobody has it
 * open —, a file, a link), then mkfifo 0600 with umask 077. Opened for reading and writing (never waits for a writer,
 * never sees an end), non-blocking, unbuffered — and only when the open handle is exactly the FIFO just made (lstat,
 * then fstat: the same inode, the agent's own). Nothing in the pool: the array stop never waits for it.
 *
 * @return resource|null  null: no doorbell (logged) — the agent looks every 150 ms, the page waits that much longer
 */
function doorbellOpen()
{
    @mkdir(RUN_DIR, 0700, true);
    clearstatcache(true, DOORBELL);
    if (@lstat(DOORBELL) !== false && !@unlink(DOORBELL)) {
        logLine('The doorbell ' . DOORBELL . ' could not be made anew — requests wait for the next round (≤ 150 ms)');
        return null;
    }
    $old = umask(0077);
    $made = function_exists('posix_mkfifo') && @posix_mkfifo(DOORBELL, 0600);
    umask($old);
    clearstatcache(true, DOORBELL);
    $st = $made ? @lstat(DOORBELL) : false;
    $bell = $st && ($st['mode'] & 0170000) === 0010000 && $st['uid'] === posix_geteuid() ? @fopen(DOORBELL, 'r+') : false;
    $fs = $bell ? @fstat($bell) : false;
    if (!$fs || $fs['dev'] !== $st['dev'] || $fs['ino'] !== $st['ino'] || !stream_set_blocking($bell, false)) {
        if ($bell) {
            fclose($bell);
        }
        logLine('No doorbell at ' . DOORBELL . ' — requests wait for the next round (≤ 150 ms)');
        return null;
    }
    stream_set_read_buffer($bell, 0);
    return $bell;
}

/** At the stop: the doorbell goes (a page that rings now rings nobody — and nothing waits for an answer then) */
function doorbellClose($bell): void
{
    if (!is_resource($bell)) {
        return;
    }
    $fs = @fstat($bell);
    clearstatcache(true, DOORBELL);
    $st = @lstat(DOORBELL);
    fclose($bell);
    if ($fs && $st && $st['dev'] === $fs['dev'] && $st['ino'] === $fs['ino']) {
        @unlink(DOORBELL);              // only its own: never another agent's
    }
}

/**
 * Waits until $until (microtime) or until the doorbell rings, whichever comes first; what rang is read away (the
 * bytes mean nothing but «look now»). A signal ends the wait too (the loop then looks at its stop flag). Without a
 * doorbell: a plain nap. True when it rang.
 */
function agentNap($bell, float $until): bool
{
    $us = (int) max(0, ($until - microtime(true)) * 1e6);
    if (!is_resource($bell)) {
        if ($us > 0) {
            usleep($us);
        }
        return false;
    }
    $read = [$bell];
    $w = $e = null;
    if (@stream_select($read, $w, $e, intdiv($us, 1000000), $us % 1000000) < 1) {
        return false;
    }
    for ($i = 0; $i < 16 && ($bytes = @fread($bell, 4096)) !== false && $bytes !== ''; $i++) {
    }
    return true;
}

/** Starts this agent anew in its own process (its pid stays): new code, or another way to its data folder */
function restartInPlace($lock, string $why): never
{
    logLine("$why — restarting");
    flock($lock, LOCK_UN);
    fclose($lock);
    pcntl_exec('/bin/sh', ['-c', 'exec "$@"' . closeInheritedFds(), 'sh', PHP_BINARY, __FILE__, 'run']);
    exit(1);
}

/**
 * Is the data folder reached another way than at the start? Exclusivity changes only with the array stopped, and
 * the agent starts anew at every array start — so normally never; but should an agent outlive an array stop (or the
 * share's link change under it), the way it decided at its start (DATA_DIR: on the pool past shfs, or through
 * /mnt/user) is looked at again every minute (one lstat and a readlink, officeUnraidPath()). Said only when seen
 * twice in a row (a minute apart), so a passing hiccup of shfs never restarts it: "<then> → <now>", else null.
 */
function dataWayLook(): ?string
{
    $now = officeUnraidPath(DATA_DIR_USER);
    if ($now === DATA_DIR) {
        $GLOBALS['dataWayOff'] = 0;
        return null;
    }
    $GLOBALS['dataWayOff'] = ($GLOBALS['dataWayOff'] ?? 0) + 1;
    return $GLOBALS['dataWayOff'] >= 2 ? DATA_DIR . ' → ' . $now : null;
}

/**
 * A fresh plugin: create the data folder once the array runs — but only in an
 * appdata that is there, never a new share on some disk.
 */
function makeDataDir(): bool
{
    if (!arrayRunning()) {
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
    // the office's own files (who works here): only the web server may read them
    privateDirEnsure(OFFICE_PRIVATE, 0700, false);
    // the data folder's version (office.json) and what an update has to change in it — before anything reads state and
    // before writeInfo() puts this version into agent.json (which tells the version before) — lib/migrate.php
    officeMigrateStart();
    // the schedules a remove put aside, back after a new install — before Ms. Snapshotini's start writes her line
    officeCronBack();
    writeInfo(true);
    // up to 1.27 a PIN could guard changes (office/auth.json, left as it is): said once in the log
    $pinGone = 'The office has no PIN any more: Unraid\'s login guards it (office/auth.json is no longer read)';
    if (!empty(readJson(OFFICE_PRIVATE . '/auth.json')['pin_hash']) && !str_contains((string) @file_get_contents(AGENT_LOG), $pinGone)) {
        logLine($pinGone);
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
 * left alone and reported (staff.json may lie behind it).
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

/**
 * Who is at work — running, version, pid, start time, host, desks: in RAM (AGENT_HEARTBEAT; its mtime is the pulse,
 * agentPulse() — the page's green dot, the web side's agentInfo() and askAgent(), agent.sh's watch) and in the data
 * folder (AGENT_INFO, for whoever looks there), that one written only when its content changes: at the start and the
 * stop, never by the pulse — nothing of the agent lands on the pool every 20 s, an appdata pool of HDDs may sleep.
 */
function writeInfo(bool $running): void
{
    $info = jsonEncode([
        'running' => $running,
        'version' => AGENT_VERSION,
        'pid'     => getmypid(),
        'started' => $GLOBALS['started'],
        'host'    => hostname(),
        'desks'   => array_keys(desks()),
    ]);
    try {
        @mkdir(RUN_DIR, 0700, true);
        writeAtomic(AGENT_HEARTBEAT, $info, 0644, 0, 0);
    } catch (Throwable $e) {
        logLine('The heartbeat in RAM could not be written: ' . $e->getMessage());
    }
    clearstatcache(true, AGENT_INFO);
    if (is_dir(DATA_DIR) && @file_get_contents(AGENT_INFO, false, null, 0, 65536) !== $info) {
        try {
            writeAtomic(AGENT_INFO, $info);
        } catch (Throwable $e) {
            logLine('agent.json could not be written: ' . $e->getMessage());
        }
    }
}

/** The pulse, every 20 s: the heartbeat's mtime in RAM (a plain file only — never touched through a link; else written anew) */
function agentPulse(): void
{
    clearstatcache(true, AGENT_HEARTBEAT);
    $st = @lstat(AGENT_HEARTBEAT);
    if (!$st || ($st['mode'] & 0170000) !== 0100000 || !@touch(AGENT_HEARTBEAT)) {
        writeInfo(true);
    }
}

/**
 * All agent files (and src/place.php, src/words.php, shared with the web side; src/staff.php, whose merge the migration
 * step `staff-merged` uses), so any change triggers a restart
 */
function codeFiles(): array
{
    $files = array_merge(glob(__DIR__ . '/*.php') ?: [], glob(__DIR__ . '/lib/*.php') ?: [], glob(__DIR__ . '/desks/*.php') ?: [],
        [dirname(__DIR__) . '/src/place.php', dirname(__DIR__) . '/src/words.php', dirname(__DIR__) . '/src/staff.php',
        dirname(__DIR__) . '/src/supporter.php', dirname(__DIR__) . '/src/reportimage.php']);
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
        // the office's own actions the agent answers (lib/report.php: «Report a problem or a wish…»)
        $handler = $deskId === 'office' ? (officeAgentActions()[$action] ?? null) : (desks()[$deskId]['actions'][$action] ?? null);
        if (!$handler) {
            throw new Problem('unknown_action', ['action' => $request['action']]);
        }
        if ($deskId !== 'office' && !agentDeskMayAct($deskId, $action)) {
            throw new Problem('not_hired', ['desk' => $deskId]);
        }
        return $handler($request);
    } catch (Problem $p) {
        return ['ok' => false, 'error' => $p->toArray()];
    } catch (Throwable $e) {
        logLine('Error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        return ['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]];
    }
}

/*
 * A desk that doesn't work here — not hired (data/office/staff.json), or still in training (desk.json `training`: nobody
 * can hire it) — answers its look and nothing else: `refresh`, which the reception and the Team Lead's «who could
 * come» ask of every desk. Everything else — a switch (the watchman's SIEM and whole-LAN), a schedule, a desk's
 * settings, a plan, a start, a deletion — is refused (not_hired), as the web side (src/api.php) refuses it before it
 * ever reaches the mailbox (QA 2026-10-08, note 1: the agent switched an unhired watchman's SIEM on). A desk's `fit`
 * is no action — the Team Lead asks it in the agent. The desks always there (the Team Lead) always act.
 */
const AGENT_UNHIRED_ACTIONS = ['refresh'];

function agentDeskMayAct(string $deskId, string $action, ?string $staffFile = null): bool
{
    if (in_array($action, AGENT_UNHIRED_ACTIONS, true)) {
        return true;
    }
    if (!empty(readJson(OFFICE_WEB . "/desks/$deskId/desk.json")['training'])) {
        return false;
    }
    return in_array($deskId, staffHired($staffFile ?? ($GLOBALS['agentStaffFile'] ?? null)), true);
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
