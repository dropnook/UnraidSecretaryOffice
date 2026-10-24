<?php
declare(strict_types=1);

/*
 * What the server has: installed plugins, Docker containers, Unraid's own
 * settings. Desks use this for their checks (desk(..., ['checks' => …])),
 * the caretaker collects all checks and tells the user what is missing.
 */

const HOUSE_PLUGINS = '/boot/config/plugins';
const HOUSE_CACHE   = 20;          // seconds — the agent runs for weeks, one tour of checks shares one look
const HOUSE_DOCKER_WAIT  = 10;     // seconds a docker call of the house (houseDocker()) may take — every one in the agent's round
const HOUSE_DOCKER_QUIET = 60;     // after one that didn't answer, nor its short look: no docker call of theirs for so long (houseDockerSilent())
const HOUSE_DOCKER_LOOK  = 5;      // seconds `docker version` may take when it only looks whether Docker answers at all (houseDockerAnswers())
const HOUSE_DOCKER_AGAIN = 15;     // while Docker is silent, a page's request looks again once so long after the last look
const HOUSE_DOCKER_FRESH = 30;     // a longer docker call only when Docker answered within so long — else a short look first

/**
 * One finding of a check.
 *   level   required     the desk can't do (part of) its job without it
 *           recommended  helps, but not a must
 *           hint         worth knowing, nothing to fix
 *   ok      true / false / null (could not tell)
 *   link    where to fix it: in Unraid's web UI (plugins, apps, docker,
 *           userscripts, notifications, settings — the caretaker builds the
 *           URL) or a page of the office itself ("#/backup/setup")
 * The text comes from the desk's language file: check.<id> (what should be
 * so) and check.<id>_how (what to do when it isn't), both with $params.
 */
function finding(string $id, string $level, ?bool $ok, array $params = [], ?string $link = null): array
{
    return ['id' => $id, 'level' => $level, 'ok' => $ok, 'params' => $params, 'link' => $link];
}

/**
 * Review 2026-10-15: the office's root code reads from the data folder, so it and the folder above it must be root's and
 * writable by root alone — whoever else can write in the folder above (nobody, the owner appdata gives its folders) can
 * put a folder of their own in the data folder's place. The files in it keep their nobody:users. Never changed here:
 * the Team Lead shows the command. ok null: no data folder (the array stopped).
 *
 * @return array{ok: ?bool, paths: list<string>, cmd: string}
 */
function officeDataTrust(string $data, bool $home = true): array
{
    clearstatcache();
    if (!is_dir($data)) {
        return ['ok' => null, 'paths' => [], 'cmd' => ''];
    }
    $bad = [];
    // the folder above only where it is the office's own (officeHomeIsOwn(); the agent makes it root's at its start,
    // officeHomeOwn()) - a data folder elsewhere: only its own rules (owner 2026-10-15), the user's folder above is theirs
    foreach ($home ? [dirname($data), $data] : [$data] as $dir) {
        $st = @lstat($dir);
        if (!$st || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0022) || !($st['uid'] === 0 || ($st['uid'] === 99 && !($st['mode'] & 0200)))) {
            $bad[] = $dir;
        }
    }
    $q = implode(' ', array_map('escapeshellarg', $bad));
    return ['ok' => !$bad, 'paths' => $bad, 'cmd' => $bad ? "chown root:root $q && chmod go-w $q" : ''];
}

/**
 * Owner 2026-10-15 (review F2): the office's own folder above the data folder, <appdata>/UnraidSecretaryOffice, is
 * root:root 0755 - set at every agent start (and by the .plg's install), only that one folder, never what is in it (the
 * data files keep their owner). Only when it is the office's own (officeHomeIsOwn()); never through a link: the folder
 * is entered and the one entered is checked to be the one looked at (a link or a folder swapped in between is left
 * alone). What it did, for the log, or null (as it should be already, or nothing to do).
 */
function officeHomeOwn(string $home): ?string
{
    clearstatcache();
    $st = @lstat($home);
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || ($st['uid'] === 0 && $st['gid'] === 0 && ($st['mode'] & 07777) === 0755)) {
        return null;
    }
    $cwd = getcwd() ?: '/';
    if (!@chdir($home)) {
        return null;
    }
    $here = @lstat('.');
    $done = $here && $here['dev'] === $st['dev'] && $here['ino'] === $st['ino'] && @chown('.', 0) && @chgrp('.', 0) && @chmod('.', 0755);
    @chdir($cwd);
    return $done ? sprintf('%s made root:root 0755 (was %d:%d %o)', $home, $st['uid'], $st['gid'], $st['mode'] & 07777)
        : "$home could not be made root:root 0755";
}

/**
 * @return array<string, array{name:string, author:?string, version:?string}> installed plugins by .plg name
 * $fresh: never the cache — a look that must see a plugin registered a moment ago (the Consultant's scan, 2026-10-11:
 * his fresh look right after a plugin job read a list another caller had cached seconds before the registration)
 */
function housePlugins(bool $fresh = false): array
{
    static $cache = null, $at = 0;
    if (!$fresh && $cache !== null && time() >= $at && time() - $at < HOUSE_CACHE) {      // a clock stepped back: looked at anew
        return $cache;
    }
    $cache = [];
    $at = time();
    foreach (glob(HOUSE_PLUGINS . '/*.plg') ?: [] as $file) {
        $head = (string) @file_get_contents($file, false, null, 0, 4096);
        $entity = fn (string $key) => preg_match('/<!ENTITY\s+' . $key . '\s+"([^"]*)"/', $head, $m) ? $m[1] : null;
        $name = basename($file, '.plg');
        $cache[$name] = ['name' => $name, 'author' => $entity('author'), 'version' => $entity('version')];
    }
    return $cache;
}

function housePlugin(string $name): bool
{
    return isset(housePlugins()[$name]);
}

/**
 * All containers ($fresh: never the cache) — empty both when there are none and when Docker didn't answer (stopped,
 * silent): a caller that would say «none», «gone» or «missing» asks houseContainersKnown() instead
 *
 * @return array<string, array{name:string, image:string, running:bool}>
 */
function houseContainers(bool $fresh = false): array
{
    return houseContainersKnown($fresh) ?? [];
}

/**
 * The containers as houseContainers() gives them — or null when `docker ps` didn't answer (Docker stopped, silent): «we
 * don't know» is never «none» (truth review P3: an app «gone», «no media server», «not in the house» with Docker off).
 * The page says `common.docker_off` / `common.docker_silent` (houseDockerSilent()).
 *
 * @return array<string, array{name:string, image:string, running:bool}>|null
 */
function houseContainersKnown(bool $fresh = false): ?array
{
    static $cache = null, $ok = false, $at = 0;
    if (!$fresh && $cache !== null && time() >= $at && time() - $at < HOUSE_CACHE) {      // a clock stepped back: looked at anew
        return $ok ? $cache : null;
    }
    $cache = [];
    $at = time();
    [$exit, $out] = houseDocker(['ps', '-a', '--format', '{{.Names}}\t{{.Image}}\t{{.State}}']);
    $ok = $exit === 0;
    if ($ok) {
        foreach (rows($out) as $f) {
            if (count($f) >= 3) {
                $cache[$f[0]] = ['name' => $f[0], 'image' => $f[1], 'running' => $f[2] === 'running'];
            }
        }
    }
    return $ok ? $cache : null;
}

/** Docker's state for a page: 'up', 'off' (stopped: no answer) or 'silent' (switched on, no answer in time — B15) */
function houseDockerState(): string
{
    if (houseContainersKnown() !== null) {
        return 'up';
    }
    return houseDockerSilent() ? 'silent' : 'off';
}

/** docker inspect of one container, or null (also while Docker is silent: houseDockerSilent()) */
function houseInspect(string $name): ?array
{
    [$exit, $out] = houseDocker(['inspect', $name]);
    $j = $exit === 0 ? json_decode($out, true) : null;
    return is_array($j[0] ?? null) ? $j[0] : null;
}

/** The short look whether Docker answers: its server's version */
const HOUSE_DOCKER_VERSION = ['version', '--format', '{{.Server.Version}}'];

/**
 * A docker call of the house (houseContainers(), houseInspect() and every docker call that can run in the agent's own
 * round: the desks' looks, the page's requests): at most HOUSE_DOCKER_WAIT s — and once one didn't answer in time
 * (dockerd hung, stopped by a signal) and `docker version` then didn't either (houseDockerAnswers()), none for
 * HOUSE_DOCKER_QUIET s: a silent Docker costs the agent's round one short wait and one short look, not 20 s for every
 * caller (batch E B15, backup-sv-docker-hung-replan-run: a 140 s gap in the heartbeat, then 39 s on 1.70.2 from the
 * desks' own `docker ps`). One slow answer is no silence (a joint mover/media run, a backup: scenario M7
 * moverelli-jk-hired-list-run, 2026-10-22 — two minutes of «Docker doesn't answer» on every page while it worked): that
 * call failed, Docker didn't. Meanwhile they answer as Docker gave nothing (exit 124); callers that say so ask
 * houseDockerSilent() — in a page's request it looks again (houseDockerForPage()). A longer $wait (a page's
 * `docker logs`, a user's removal, Kopia in its container) only once Docker answered within HOUSE_DOCKER_FRESH s — else
 * `docker version` with the short wait first: a hung Docker never holds the agent for the long one. Before each wait the
 * agent's pulse (houseDockerPulse()). $docker: the tests' stand-in.
 *
 * @return array{0:int, 1:string, 2:string}
 */
function houseDocker(array $args, int $wait = HOUSE_DOCKER_WAIT, string $docker = 'docker'): array
{
    if (houseDockerSilent($docker)) {
        return [124, '', 'Docker does not answer (no new call for ' . HOUSE_DOCKER_QUIET . ' s after a time-out)'];
    }
    if ($wait > HOUSE_DOCKER_WAIT && !houseDockerAnsweredLately()) {
        $look = houseDockerCall($docker, HOUSE_DOCKER_VERSION, HOUSE_DOCKER_WAIT);
        if ($look[0] === 124) {
            return $look;
        }
    }
    return houseDockerCall($docker, $args, $wait);
}

/**
 * One docker call of houseDocker(): an answer ends the quiet time; a time-out starts it only when Docker doesn't say
 * its version either (houseDockerAnswers() — unless the call was that look itself)
 */
function houseDockerCall(string $docker, array $args, int $wait): array
{
    houseDockerPulse();
    $r = run([$docker, ...$args], $wait);
    if ($r[0] !== 124) {
        houseDockerHeard();
    } elseif ($args !== HOUSE_DOCKER_VERSION && houseDockerAnswers($docker)) {
        houseDockerHeard();         // one slow answer: this call failed, Docker didn't — no quiet time, nothing logged
    } else {
        $GLOBALS['houseDockerSilent'] = hrtime(true) / 1e9;          // the monotonic clock: a step never shortens or stretches it
        unset($GLOBALS['houseDockerAnswered']);
        if (function_exists('logLine')) {
            $look = $args === HOUSE_DOCKER_VERSION ? '' : ', docker version over ' . HOUSE_DOCKER_LOOK . ' s';
            logLine("Office: Docker doesn't answer (docker {$args[0]} took over {$wait} s{$look}) — no docker call of the house for " . HOUSE_DOCKER_QUIET . ' s');
        }
    }
    return $r;
}

/** Does Docker answer at all? `docker version` within HOUSE_DOCKER_LOOK s (the agent's pulse before the wait) */
function houseDockerAnswers(string $docker): bool
{
    houseDockerPulse();
    $GLOBALS['houseDockerLooked'] = hrtime(true) / 1e9;
    return run([$docker, ...HOUSE_DOCKER_VERSION], HOUSE_DOCKER_LOOK)[0] !== 124;
}

/** Docker answered: no quiet time, and a longer call may follow without the short look (HOUSE_DOCKER_FRESH) */
function houseDockerHeard(): void
{
    unset($GLOBALS['houseDockerSilent']);
    $GLOBALS['houseDockerAnswered'] = hrtime(true) / 1e9;
}

/** Did a docker call of the house come back in time within HOUSE_DOCKER_FRESH s? */
function houseDockerAnsweredLately(): bool
{
    $at = $GLOBALS['houseDockerAnswered'] ?? null;
    return is_float($at) && hrtime(true) / 1e9 - $at < HOUSE_DOCKER_FRESH;
}

/**
 * The agent's pulse before a docker call waits (only in the agent itself — its pid in PID_FILE —, never in a job, which
 * must not keep a gone agent's heartbeat alive): the heartbeat beats every 20 s after the round, so a round's one short
 * wait that began late in those 20 s would stretch the gap past them (scenarios' INV-TICK: 25 s)
 */
function houseDockerPulse(): void
{
    if (function_exists('agentPulse') && defined('PID_FILE') && (int) @file_get_contents(PID_FILE) === getmypid()) {
        agentPulse();
    }
}

/**
 * Did Docker not answer lately (a docker call of the house and its short look timed out within HOUSE_DOCKER_QUIET s)?
 * In a page's request (houseDockerForPage()) — never the agent's round — it looks again once HOUSE_DOCKER_AGAIN s after
 * the last look: Docker back, the page says so at once, not up to a minute later (B15's stale bubble); still silent,
 * the quiet time starts anew. A look costs one short wait (HOUSE_DOCKER_LOOK), at most one per HOUSE_DOCKER_AGAIN s.
 */
function houseDockerSilent(string $docker = 'docker'): bool
{
    $at = $GLOBALS['houseDockerSilent'] ?? null;
    if (!is_float($at) || hrtime(true) / 1e9 - $at >= HOUSE_DOCKER_QUIET) {
        return false;
    }
    $looked = max($at, (float) ($GLOBALS['houseDockerLooked'] ?? $at));
    if (empty($GLOBALS['houseDockerPage']) || hrtime(true) / 1e9 - $looked < HOUSE_DOCKER_AGAIN) {
        return true;
    }
    if (!houseDockerAnswers($docker)) {
        $GLOBALS['houseDockerSilent'] = hrtime(true) / 1e9;
        return true;
    }
    houseDockerHeard();
    return false;
}

/** A page's request (agent.php handle()): while it runs, a silent Docker may be looked at again (houseDockerSilent()) */
function houseDockerForPage(callable $f): mixed
{
    $GLOBALS['houseDockerPage'] = true;
    try {
        return $f();
    } finally {
        unset($GLOBALS['houseDockerPage']);
    }
}

const HOUSE_AUTOSTART        = '/var/lib/docker/unraid-autostart';                  // Unraid's Docker autostart list (RAM, in docker.img's folder)
const HOUSE_COMPOSE_CFG      = '/boot/config/plugins/compose.manager/compose.manager.cfg';
const HOUSE_COMPOSE_PROJECTS = '/boot/config/plugins/compose.manager/projects';     // Compose Manager's PROJECTS_FOLDER by default

/**
 * Unraid's Docker autostart list: what rc.docker starts, in this order, whenever Docker comes up (after a boot or
 * an array start — every container was stopped at the array stop). One line per template container, `name` or
 * `name delay` (the seconds Unraid waits after starting it; the Docker page's Autostart switch writes it).
 *
 * @return array<string, int> container name => delay in seconds
 */
function houseAutostart(string $file = HOUSE_AUTOSTART): array
{
    $list = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line)) ?: [];
        if (($parts[0] ?? '') !== '') {
            $list[$parts[0]] = (int) ($parts[1] ?? 0);
        }
    }
    return $list;
}

/**
 * Whether Compose Manager brings a stack up by itself when Docker starts (its docker_started event runs
 * `compose up` for every stack whose folder under PROJECTS_FOLDER has `autostart` = true): the folder whose
 * `project_name` — the containers' label com.docker.compose.project — is $project (older folders only have
 * `name`). null = Compose Manager doesn't know that stack, or isn't installed: nothing the office knows starts it.
 *
 * @param string|null $root  the projects folder (tests); null = Compose Manager's own setting
 */
function houseComposeAutostart(string $project, ?string $root = null): ?bool
{
    if ($root === null) {
        if (!housePlugin('compose.manager')) {
            return null;
        }
        $root = (string) (readCfg(HOUSE_COMPOSE_CFG)['PROJECTS_FOLDER'] ?? '');
        if ($root === '' || $root[0] !== '/') {
            $root = HOUSE_COMPOSE_PROJECTS;
        }
    }
    foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = trim((string) @file_get_contents("$dir/project_name"));
        if ($name === '') {
            $name = trim((string) @file_get_contents("$dir/name")) ?: basename($dir);
        }
        if (strcasecmp($name, $project) === 0) {                 // compose lower-cases a project's name
            return trim((string) @file_get_contents("$dir/autostart")) === 'true';
        }
    }
    return null;
}

/** The address of Unraid's own web UI (from Unraid's config), e.g. for the server's IP in a ready-made config */
function houseGuiUrl(): ?string
{
    $ident = readCfg('/boot/config/ident.cfg');
    $ssl = in_array(strtolower($ident['USE_SSL'] ?? 'no'), ['yes', 'auto'], true);
    $ip = null;
    foreach (@file('/var/local/emhttp/network.ini', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^IPADDR:0="(\d+\.\d+\.\d+\.\d+)"/', trim($line), $m)) {
            $ip = $m[1];          // first interface, first address: the one the web UI listens on
            break;
        }
    }
    if (!$ip) {
        return null;
    }
    $port = $ssl ? ($ident['PORTSSL'] ?? '443') : ($ident['PORT'] ?? '80');
    $default = $ssl ? '443' : '80';
    return ($ssl ? 'https://' : 'http://') . $ip . ($port !== $default ? ":$port" : '');
}

const HOST_LAUNCH_MARK = '# written by the Unraid Secretary Office agent';     // in every job it hands to atd (the night watchman knows them by it)
const HOST_ATD_PID = '/var/run/atd.pid';         // atd's pid file (Unraid's rc.atd starts /usr/sbin/atd, which writes it)

/**
 * Does the host's atd run? `at` queues a job without it all the same (exit 0) and nothing starts until atd comes back —
 * the page said «Handed over — it should start any moment» for ever (batch D F3, scenario backup-sv-atd-off-run-apply).
 * The pid in atd's pid file whose process is called atd; else (no pid file, a stale one) any process called atd.
 * $GLOBALS['hostAtdLook'] = [pid file, proc folder]: the tests' stand-ins.
 */
function hostAtdUp(?string $pidFile = null, ?string $proc = null): bool
{
    [$pidFile, $proc] = [$pidFile ?? $GLOBALS['hostAtdLook'][0] ?? HOST_ATD_PID, $proc ?? $GLOBALS['hostAtdLook'][1] ?? '/proc'];
    $atd = fn (string $comm) => trim((string) @file_get_contents($comm, false, null, 0, 64)) === 'atd';
    $pid = trim((string) @file_get_contents($pidFile, false, null, 0, 32));
    if (preg_match('/^[1-9][0-9]{0,9}$/D', $pid) && $atd("$proc/$pid/comm")) {
        return true;
    }
    foreach (glob("$proc/[0-9]*/comm") ?: [] as $comm) {
        if ($atd($comm)) {
            return true;
        }
    }
    return false;
}

/**
 * Hands a command to the host's atd, so it lives on without the agent: a
 * process started by the agent itself would be stopped with it (agent.sh
 * stops the agent's whole session when the array stops or the plugin updates).
 * Scripts should be run through their interpreter (bash, python3): right
 * after an edit over SMB, Samba may still hold the file open ("Text file busy").
 *
 * @param string      $job     name of the job file in the run directory
 * @param list<string> $args   the command, no shell
 * @param array<string,string> $env  extra environment
 * @param string|null $output  file for stdout and stderr (default: discarded)
 */
function hostLaunch(string $job, array $args, array $env = [], ?string $output = null, string $cwd = '/'): void
{
    // the job file's name and the variables' names go into the script unquoted: only plain names
    if (!preg_match('/^[a-z][a-z0-9_-]{0,63}\z/', $job) || !$args) {
        throw new Problem('command_failed', ['detail' => "bad job $job"]);
    }
    // without atd nothing would start (at queues anyway): said now, and nothing left in its queue to start some day later
    if (!hostAtdUp()) {
        throw new Problem('atd_off');
    }
    $file = RUN_DIR . "/$job.sh";
    $line = implode(' ', array_map(fn ($a) => escapeshellarg((string) $a), $args));
    $exports = '';
    foreach ($env as $k => $v) {
        if (!is_string($k) || !preg_match('/^[A-Z_][A-Z0-9_]{0,63}\z/', $k)) {
            throw new Problem('command_failed', ['detail' => "bad variable $k"]);
        }
        $exports .= $k . '=' . escapeshellarg((string) $v) . "\nexport $k\n";
    }
    $out = $output !== null ? escapeshellarg($output) : '/dev/null';
    $script = "#!/bin/sh\n" . HOST_LAUNCH_MARK . "\n"
            . "PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin\nexport PATH\n$exports"
            . 'cd ' . escapeshellarg($cwd) . "\n"
            . "exec $line </dev/null >$out 2>&1\n";
    @mkdir(RUN_DIR, 0700, true);
    if (@file_put_contents($file, $script) === false) {
        throw new Problem('command_failed', ['detail' => "cannot write $file"]);
    }
    [$exit, , $err] = run(['at', '-M', '-f', $file, 'now'], 20);
    if ($exit !== 0) {
        throw new Problem('host_launch_failed', ['detail' => trim($err)]);
    }
}

const HOST_AT_SPOOL = '/var/spool/atjobs';      // atd's queue: a file a job, its name <queue><job number, 5 hex><due minute, 8 hex>
const HOST_AT_LATE  = 120;                       // seconds a job of the office's may lie ahead before it counts as stranded

/**
 * The office's own jobs stranded in atd's queue (scenario full run 2026-10-21/22, the F12/BF5 family): `at now` takes the
 * wall clock's minute when the job is queued — queued while the clock was set ahead (by hand, a scenario, a bad NTP), the
 * job waits in atd's queue for that moment once the clock is put back: a partner's look, the Team Lead's ping, the Kopia
 * watch for hours or days. The office only ever queues «now» (hostLaunch(), agent.sh), so a job of its own (the mark) due
 * more than HOST_AT_LATE ahead is one of those: queued anew for now (its own script, as hostLaunch() wrote it — what at
 * put around it stays out), then the old one taken out of the queue; another's job (Unraid's, a plugin's, a user's) and a
 * running one (`=`) are never touched. Looked at by the agent every few seconds: one scandir, a file read only for a job
 * that lies ahead. The job numbers queued anew; $spool, $now and $bins ([at, atrm]) the tests' stand-ins.
 *
 * @return list<int>
 */
function hostAtRequeue(?string $spool = null, ?int $now = null, array $bins = ['at', 'atrm']): array
{
    $spool ??= HOST_AT_SPOOL;
    $now ??= time();
    $done = [];
    foreach (@scandir($spool) ?: [] as $f) {
        if (!preg_match('/^[a-z]([0-9a-f]{5})([0-9a-f]{8})$/D', $f, $m) || hexdec($m[2]) * 60 <= $now + HOST_AT_LATE
            || !is_file("$spool/$f") || is_link("$spool/$f")) {
            continue;
        }
        $text = (string) @file_get_contents("$spool/$f", false, null, 0, 65536);
        $from = strpos($text, "#!/bin/sh\n" . HOST_LAUNCH_MARK . "\n");
        if ($from === false || $from === 0) {
            continue;               // not the office's (at's own header comes first in every job file)
        }
        $file = RUN_DIR . '/at-requeue.sh';
        @mkdir(RUN_DIR, 0700, true);
        // what follows the office's script (at's closing line) is never reached: the script ends in exec
        if (@file_put_contents($file, substr($text, $from)) === false) {
            continue;
        }
        [$exit] = run([$bins[0], '-M', '-f', $file, 'now'], 20);
        @unlink($file);
        if ($exit !== 0) {
            continue;               // the old one stays: late is better than lost
        }
        $job = (int) hexdec($m[1]);
        run([$bins[1], (string) $job], 20);
        logLine('Office: job ' . $job . ' in atd\'s queue was due ' . date('Y-m-d H:i', (int) hexdec($m[2]) * 60)
            . ' (queued while the clock was ahead) - queued anew for now');
        $done[] = $job;
    }
    return $done;
}

/**
 * Runs a command that needs the network (curl, git, notify's mail and push) right away. The agent
 * runs on the host, so this is run() — kept as the one name for "this goes out to the network".
 */
function hostNet(array $command, int $timeout = 60): array
{
    return run($command, $timeout);
}

// ===================================================================== Unraid's notifications

/*
 * What the office has to tell even when nobody looks at the page goes to
 * Unraid's notifications (the bell, and mail or push as set under Settings →
 * Notifications), in the style of the backup engine (ub_notify in
 * backup/lib/common.sh): the event "Unraid Secretary Office", the subject
 * behind "Unraid Secretary Office: " (Unraid puts the server's name in front).
 * Texts come from the desks' language files, in Unraid's language when the
 * office speaks it (officeNotifyText(), officeNotifyLang()).
 */
const OFFICE_NOTIFY_BIN   = '/usr/local/emhttp/webGui/scripts/notify';
const OFFICE_NOTIFY_EVENT = 'Unraid Secretary Office';
// the second the last notification of the event ended in — shared by the agent, its night shift, scripts/agent.sh and
// the backup engine (RAM; see officeNotify())
const OFFICE_NOTIFY_STAMP = RUN_DIR . '/notify.second';
const OFFICE_NOTIFY_LOCK_WAIT = 10;     // seconds waited at most for another's notification to end — then it goes anyway
// the same subject in Unraid's notifications within so many seconds — from any version of the office, any of its
// processes — is not told again (officeNotifyToldLately())
const OFFICE_NOTIFY_SAME = 120;
const OFFICE_NOTIFY_CFG  = '/boot/config/plugins/dynamix/dynamix.cfg';     // Settings → Notifications: [notify] path
// the language the office was last used in (written by the page, src/desks.php) — and its copy in RAM for the night shift
define('OFFICE_LANG_FILE', OFFICE_PRIVATE . '/lang.json');
const OFFICE_NOTIFY_LANG_RAM = RUN_DIR . '/notify.lang';

/**
 * Sends one notification. $level normal|warning|alert; $message the long
 * text (its lines become Unraid's "\n", which mail, push agents and the
 * archive turn into line breaks); $link where a click leads (officeNotifyLink()).
 * $desk and $event say who tells what (`backup`, `drill_failed`): the Team Lead's notification centre
 * (lib/notifycenter.php) decides through its gate — send now, hold for his summary, count only; $gate false for what
 * the centre sends itself (its summaries, the test). True when it went — or the centre took it (held, counted, not
 * wanted): the caller's «told».
 * Does nothing without Unraid's notify script. OFFICE_NOTIFY_BIN in the
 * environment points to a stand-in, OFFICE_NOTIFY_STAMP to another shared
 * stamp, OFFICE_NOTIFY_HOME to the centre's folder — for the tests only.
 */
function officeNotify(string $subject, string $description, string $level = 'normal', string $message = '', ?string $link = null,
                      string $desk = 'office', string $event = '', bool $gate = true): bool
{
    static $last = 0;
    $bin = getenv('OFFICE_NOTIFY_BIN') ?: OFFICE_NOTIFY_BIN;
    if (!is_executable($bin)) {
        return false;
    }
    // with a stand-in notify (the tests) the plugin's RAM stamp is never touched: only the one they name, else this process's
    $stamp = (string) (getenv('OFFICE_NOTIFY_STAMP') ?: (getenv('OFFICE_NOTIFY_BIN') ? '' : OFFICE_NOTIFY_STAMP));
    $flat = fn (string $s) => trim((string) preg_replace('/\s+/u', ' ', $s));
    $level = in_array($level, ['normal', 'warning', 'alert'], true) ? $level : 'normal';
    $args = [$bin, '-e', OFFICE_NOTIFY_EVENT, '-s', OFFICE_NOTIFY_EVENT . ': ' . $flat($subject), '-d', $flat($description), '-i', $level];
    if (trim($message) !== '') {
        $args = [...$args, '-m', str_replace(["\r\n", "\r", "\n"], '\n', trim($message))];
    }
    if ($link !== null && $link !== '') {
        $args = [...$args, '-l', $link];
    }
    // told a moment ago already (batch E O1: the update from 1.60 stopped the old agent while its watchman's round was
    // telling — the round was gone before it noted «told», the new agent's first round told it all again)
    $told = officeNotifyToldLately($args[4], officeNotifyDir(), time());
    if ($told !== null) {
        if (function_exists('logLine')) {
            logLine("Office: not told again: «{$args[4]}» — Unraid has it from {$told} s ago");
        }
        return true;
    }
    // Unraid names a notification after its event and the second its script reads the clock — somewhere between
    // our call's start and its end: one more in that second would overwrite it (the night shift lost one of three
    // sent in a row on 2026-10-07). $last is the second the last call ENDED in; a new one starts only after it.
    // The agent, the night shift, scripts/agent.sh and the backup engine send with the same event: the second lies in
    // a stamp in RAM they all share, read and written under its flock (held through the call, so they take turns;
    // waited for at most OFFICE_NOTIFY_LOCK_WAIT s, then the call goes anyway). No RAM folder: this process's alone.
    $h = officeNotifyStampOpen($stamp);
    if ($h) {
        rewind($h);
        $shared = trim((string) stream_get_contents($h, 32));
        if (preg_match('/^\d{1,12}$/D', $shared)) {
            $last = max($last, (int) $shared);
        }
    }
    $call = function (array $a) use (&$last): bool {
        if (time() <= $last) {
            usleep((int) ((1 - fmod(microtime(true), 1)) * 1e6) + 10000);
        }
        [$exit] = hostNet($a, 30);
        $last = time();
        return $exit === 0;
    };
    // the centre's gate (lib/notifycenter.php), under the same flock as the engine's and agent.sh's: decision and call in one turn
    $event = preg_match('/^[a-z0-9_]{0,40}$/D', $event) ? $event : '';
    $g = $gate && function_exists('officeNotifyGate') ? officeNotifyGate($desk, $event, $level, $flat($subject), $flat($description)) : null;
    $fate = $g['fate'] ?? 'sent';
    $ok = true;
    if ($fate === 'sent') {
        $ok = $call($args);
        $fate = $ok ? 'sent' : 'failed';
        if ($g) {
            officeNotifyGuardLine($g['home'], 'sent', $desk, $level, $g['key']);
        }
    }
    if ($g && $g['notice'] !== '') {        // past a cap: ONE notice, the rest collected for a summary
        [$ns, $nd] = officeNotifyFloodText($g['notice'], $g['due'], officeNotifyLang());
        $call([$bin, '-e', OFFICE_NOTIFY_EVENT, '-s', OFFICE_NOTIFY_EVENT . ': ' . $flat($ns), '-d', $flat($nd), '-i', 'warning',
               '-l', officeNotifyLink('#/caretaker/notify')]);
        officeNotifyGuardLine($g['home'], 'flood', $g['notice'], 'warning', '-');
    }
    if ($g) {
        officeNotifyDrop($g['home'], ['desk' => $desk, 'event' => $event, 'level' => $level, 'fate' => $fate, 'why' => $g['why'], 'due' => $g['due'],
            'key' => $g['key'], 'subject' => $flat($subject), 'description' => $flat($description), 'message' => trim($message), 'link' => (string) $link]);
        if (function_exists('logLine') && $fate !== 'sent') {
            logLine("Notifications: «{$args[4]}» ($desk, $level) — $fate" . ($g['why'] !== '' ? " ({$g['why']})" : ''));
        }
    }
    $GLOBALS['officeNotifyFate'] = $fate;
    if ($h) {
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, "$last\n");
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }
    return $ok;
}

/**
 * Unraid's notification folder (Settings → Notifications' storage path, else /tmp/notifications); null: none to look
 * at — a stand-in notify (the tests) looks only at the folder OFFICE_NOTIFY_DIR names.
 */
function officeNotifyDir(): ?string
{
    if (getenv('OFFICE_NOTIFY_BIN')) {
        return getenv('OFFICE_NOTIFY_DIR') ?: null;
    }
    $path = rtrim((string) ((@parse_ini_file(OFFICE_NOTIFY_CFG, true, INI_SCANNER_RAW) ?: [])['notify']['path'] ?? ''), '/');
    $path = $path !== '' && $path[0] === '/' ? trim($path, '"') : '/tmp/notifications';
    return is_dir($path) ? $path : null;
}

/**
 * Has Unraid a notification of the office with exactly this subject from the last OFFICE_NOTIFY_SAME s (unread or
 * archived; by its file name's second, so older ones aren't read)? Its age in seconds, else null.
 */
function officeNotifyToldLately(string $subject, ?string $dir, int $now): ?int
{
    if ($dir === null) {
        return null;
    }
    $prefix = str_replace(' ', '_', OFFICE_NOTIFY_EVENT) . '_';
    foreach (['unread', 'archive'] as $sub) {
        foreach (glob("$dir/$sub/$prefix*.notify") ?: [] as $file) {
            if (!preg_match('/_(\d{9,12})\.notify$/D', $file, $m) || $now - (int) $m[1] > OFFICE_NOTIFY_SAME || (int) $m[1] > $now + 5) {
                continue;
            }
            $text = (string) @file_get_contents($file, false, null, 0, 65536);
            if (preg_match('/^subject="?(.*?)"?$/m', $text, $sm) && $sm[1] === $subject) {
                return max(0, $now - (int) $m[1]);
            }
        }
    }
    return null;
}

/**
 * The shared stamp of officeNotify(), opened (close-on-exec: the notify script never inherits it) and locked — waited
 * for at most OFFICE_NOTIFY_LOCK_WAIT s. null: no stamp ('' or its folder missing, a link), or the lock not had in
 * time (then the stamp isn't written either: the holder does that).
 *
 * @return resource|null
 */
function officeNotifyStampOpen(string $stamp): mixed
{
    if ($stamp === '' || !is_dir(dirname($stamp)) || is_link($stamp)) {
        return null;
    }
    $old = umask(0077);
    $h = @fopen($stamp, 'c+e');
    umask($old);
    if (!$h) {
        return null;
    }
    $until = microtime(true) + OFFICE_NOTIFY_LOCK_WAIT;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $until) {
            fclose($h);
            return null;
        }
        usleep(50000);
    }
    return $h;
}

/** Where a click on a notification leads: a page of the office ("#/caretaker") inside Unraid */
function officeNotifyLink(string $hash = ''): string
{
    return officeMenuUrl(officeMenuPlace()) . $hash;
}

/**
 * The language of the notifications: the one the office was last used in (the page keeps it in data/office/lang.json,
 * src/desks.php officeLangRemember()), else Unraid's (Settings → Display Settings) when the office speaks it, else
 * English. The night shift opens nothing under /mnt: it reads the copy in RAM the agent keeps (officeNotifyLangKeep()) —
 * none after a reboot, then Unraid's.
 */
function officeNotifyLang(string $cfg = OFFICE_UNRAID_CFG, ?string $seen = null): string
{
    $lang = officeNotifyLangSeen($seen ?? (NIGHT_MODE ? OFFICE_NOTIFY_LANG_RAM : OFFICE_LANG_FILE));
    if ($lang !== null) {
        return $lang;
    }
    $code = officeUnraidLang($cfg);
    return officeNotifyLangOk($code) ? $code : 'en';
}

/** A language the office speaks (a file in lang/) */
function officeNotifyLangOk(string $code): bool
{
    return preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/D', $code) === 1 && is_file(OFFICE_WEB . "/lang/$code.json");
}

/** The language kept in lang.json (or its copy in RAM), null when there is none the office speaks */
function officeNotifyLangSeen(string $file): ?string
{
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || (int) @filesize($file) > 4096) {
        return null;
    }
    $lang = (readJson($file) ?? [])['lang'] ?? null;
    return is_string($lang) && officeNotifyLangOk($lang) ? $lang : null;
}

/**
 * The agent, once a minute: the language the office was last used in into RAM, for the night shift (which never
 * opens the data folder). Read only when lang.json changed, written only when it differs; gone with lang.json.
 */
function officeNotifyLangKeep(string $file = OFFICE_LANG_FILE, string $ram = OFFICE_NOTIFY_LANG_RAM): void
{
    static $stamp = [];
    clearstatcache(true, $file);
    $now = (string) @filemtime($file) . ':' . (string) @filesize($file);
    if (($stamp[$file] ?? null) === $now && ($now === ':' || is_file($ram))) {
        return;
    }
    $stamp[$file] = $now;
    $lang = officeNotifyLangSeen($file);
    if ($lang === null) {
        if (is_file($ram) && !is_link($ram)) {
            @unlink($ram);
        }
        return;
    }
    if (officeNotifyLangSeen($ram) !== $lang && is_dir(dirname($ram))) {
        try {
            writeAtomic($ram, jsonEncode(['lang' => $lang]), 0600, 0, 0);
        } catch (Throwable $e) {
            logLine('Could not keep the language for the night shift: ' . $e->getMessage());
        }
    }
}

/**
 * A text for a notification from a desk's language file ('' = the office's
 * own): in $lang, else English, else ''. {placeholders} from $params, plurals
 * ({"one": …, "other": …}) by $params['n']; Unraid's labels (⟦Settings⟧, src/words.php)
 * as Unraid shows them in the language it runs in — $unraid, else Unraid's own.
 */
function officeNotifyText(string $desk, string $key, array $params = [], string $lang = 'en', ?string $unraid = null): string
{
    if ($desk !== '' && !preg_match('/^[a-z][a-z0-9_-]*$/', $desk)) {
        return '';
    }
    if (!preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $lang)) {
        $lang = 'en';
    }
    $base = OFFICE_WEB . ($desk === '' ? '' : "/desks/$desk") . '/lang';
    $text = null;
    foreach (array_unique([$lang, 'en']) as $code) {
        $text = (readJson("$base/$code.json") ?? [])[$key] ?? null;
        if ($text !== null) {
            break;
        }
    }
    if (is_array($text)) {
        $text = (($params['n'] ?? null) === 1 ? ($text['one'] ?? null) : null) ?? $text['other'] ?? '';
    }
    $text = officeUnraidResolve(is_string($text) ? $text : '', officeUnraidWords($unraid ?? officeUnraidLang(), OFFICE_WEB));
    return (string) preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $text);
}

// ===================================================================== cron

/** The User Scripts plugin's places on the flash — the user's scripts (Ms. Dustdevil, Jack Emby look at them) */
const US_DIR      = '/boot/config/plugins/user.scripts';
const US_SCHEDULE = US_DIR . '/schedule.json';

/** A cron expression of five plain fields (as crond and User Scripts' "Custom" take it) */
function cronValid(string $cron): bool
{
    $f = preg_split('/\s+/', trim($cron));
    if (count($f) !== 5) {
        return false;
    }
    // minute, hour, day of month, month, day of week (0 and 7 = Sunday)
    foreach ([[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]] as $i => [$lo, $hi]) {
        foreach (explode(',', $f[$i]) as $part) {
            if (!preg_match('#^(\*|(\d+)(-(\d+))?)(/(\d+))?$#', $part, $m)) {
                return false;
            }
            foreach ([$m[2] ?? '', $m[4] ?? ''] as $n) {
                if ($n !== '' && ((int) $n < $lo || (int) $n > $hi)) {
                    return false;
                }
            }
            if (isset($m[6]) && $m[6] !== '' && ((int) $m[6] < 1 || (int) $m[6] > $hi)) {
                return false;
            }
        }
    }
    return true;
}

// ===================================================================== the office's schedules

/*
 * Jobs run on a schedule even when nobody has the office open: Mr.
 * Backupsy's nightly run, Ms. Snapshotini's plans (every 5 minutes), Jack
 * Emby's EmbyCache and media gather, and Ms. Moverelli's Smart Mover.
 * The office writes them into its own cron file on the flash — Unraid adds
 * every installed plugin's *.cron to root's crontab (update_cron) — and
 * scripts/job.sh runs them only while the array is started.
 */
const OFFICE_CRON = '/boot/config/plugins/' . OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cron';
const OFFICE_JOBS = ['backup', 'snapshots', 'embycache', 'gather', 'moverelli'];     // in this order in the cron file

function officeJobCommand(string $job): string
{
    return 'bash ' . OFFICE_DIR . "/scripts/job.sh $job > /dev/null 2>&1";
}

/** @return array<string,string>  job => cron, as the plugin's cron file has them */
function officeCronLines(string $file = OFFICE_CRON): array
{
    $found = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        $line = trim($line);
        foreach (OFFICE_JOBS as $job) {
            if ($line !== '' && $line[0] !== '#' && str_ends_with($line, ' ' . officeJobCommand($job))) {
                $found[$job] = implode(' ', array_slice(preg_split('/\s+/', $line), 0, 5));
            }
        }
    }
    return $found;
}

/**
 * A job's schedule: its line in the plugin's cron file; `next` = when it runs next (unix, cronNext() of
 * lib/snapshotplans.php — agent.php loads both), null while off or for a line cron can't read; $file for the tests
 */
function officeJobSchedule(string $job, string $file = OFFICE_CRON): array
{
    $cron = officeCronLines($file)[$job] ?? null;
    return ['script' => true, 'frequency' => $cron !== null ? 'custom' : 'disabled', 'custom' => $cron, 'enabled' => $cron !== null,
        'next' => $cron !== null ? cronNext($cron, time()) : null];
}

/**
 * Sets (cron) or switches off (null) a job's schedule: its line in the cron
 * file, the others stay; $file and $apply (update_cron) are there for the
 * tests.
 *
 * @return bool  whether the live crontab has it that way afterwards
 */
function officeJobSetSchedule(string $job, ?string $cron, string $file = OFFICE_CRON, bool $apply = true): bool
{
    if (!in_array($job, OFFICE_JOBS, true)) {
        throw new Problem('unknown_target', ['target' => $job]);
    }
    if ($cron !== null) {
        $cron = preg_replace('/\s+/', ' ', trim($cron));
        if (!cronValid($cron)) {
            throw new Problem('bad_cron', ['cron' => $cron]);
        }
    }
    $lines = officeCronLines($file);
    if ($cron === null) {
        unset($lines[$job]);
    } else {
        $lines[$job] = $cron;
    }
    if ($lines) {
        $text = "# Unraid Secretary Office - written by the office, change it there\n";
        foreach (OFFICE_JOBS as $j) {
            if (isset($lines[$j])) {
                $text .= $lines[$j] . ' ' . officeJobCommand($j) . "\n";
            }
        }
        writeAtomic($file, $text, 0600, 0, 0);
    } else {
        @unlink($file);
    }
    if (!$apply) {
        return true;
    }
    [$exit, , $err] = run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);   // its first line is no shebang
    if ($exit !== 0) {
        throw new Problem('command_failed', ['detail' => 'update_cron: ' . trim($err)]);
    }
    return ($cron !== null) === str_contains((string) @file_get_contents('/etc/cron.d/root'), officeJobCommand($job));
}

// ===================================================================== staff

// desks that went into another one: old id => the desk that does their work now — the same as
// OFFICE_DESKS_MERGED in src/staff.php; the migration step `staff-merged` (lib/migrate.php) rewrites staff.json once
// (tests compare the two)
const STAFF_MERGED = ['whereabouts' => 'cleanup', 'emby' => 'jack'];        // 2026-10: Ms. Whereabouts' work is Ms. Dustdevil's;
                                                                     // 2026-10-14: Jack Streamer's desk id emby → jack

/**
 * The desks that work in the office: data/office/staff.json (written by the
 * web part, see src/staff.php) plus those that are always there. A desk that
 * went into another one counts as that one until the migration step `staff-merged` rewrote the list.
 * $file: another staff list (tests).
 *
 * @return list<string>
 */
function staffHired(?string $file = null): array
{
    $hired = staffMergedIds(array_keys((array) ((readJson($file ?? DATA_DIR . '/office/staff.json') ?? [])['hired'] ?? [])), desks());
    foreach (array_keys(desks()) as $id) {
        if (!empty(readJson(OFFICE_WEB . "/desks/$id/desk.json")['always'])) {
            $hired[] = $id;
        }
    }
    return array_values(array_unique(array_filter($hired, fn ($id) => is_string($id) && isset(desks()[$id]))));
}

/** Hired ids with each desk that went into another one (and is gone) as that one — STAFF_MERGED */
function staffMergedIds(array $ids, array $desks): array
{
    return array_map(fn ($id) => is_string($id) && !isset($desks[$id]) && isset(STAFF_MERGED[$id]) ? STAFF_MERGED[$id] : $id, $ids);
}

/**
 * Would a desk fit this server? A desk's 'fit' says so:
 * ['ok' => bool, 'why' => code, 'params' => [...]] — the texts are the desk's
 * own (fit.<why>), told by the caretaker when he suggests whom to hire.
 */
function fit(bool $ok, string $why, array $params = []): array
{
    return ['ok' => $ok, 'why' => $why, 'params' => $params];
}

/** Pools and disks that can take snapshots (ZFS datasets mounted under /mnt, btrfs under /mnt) */
function houseSnapshotFilesystems(): array
{
    $found = ['zfs' => [], 'btrfs' => []];
    foreach (mountTable() as $m) {
        if (isset($found[$m['fs']]) && preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !preg_match('/^(user0?|disks|remotes|addons|rootshare)$/', $x[1])) {
            $found[$m['fs']][$x[1]] = true;
        }
    }
    return ['zfs' => array_keys($found['zfs']), 'btrfs' => array_keys($found['btrfs'])];
}
