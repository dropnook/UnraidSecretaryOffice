<?php
declare(strict_types=1);

/*
 * The Night Watchman — calm, factual, few words. He keeps a watch book and
 * tells only what is DIFFERENT from normal. Read only.
 *
 * When he is hired, his first round records what is normal now (the
 * baseline) and reports nothing for it. After that a round every WATCH_EVERY
 * seconds — `php agent.php job watchman-round`, a process of its own started
 * from his tick (like Ms. Protocolli's tour), also with the office closed —
 * compares what he sees with what is normal:
 *
 *   logins      Unraid's web logins (syslog "webgui: Successful login user …
 *               from <ip>" / "Unsuccessful login user … from <ip>.") and SSH
 *               ("Accepted …", "Failed …", "Invalid user …"): a login from an
 *               address he has not seen before; a burst of failures
 *               (WATCH_FAIL_BURST from one address within WATCH_FAIL_WINDOW).
 *               The syslog is read by offset, a rotation found by inode — no
 *               line is looked at twice. Names tried in failed logins are kept
 *               only when they are users of this server (somebody may type a
 *               password into the name field).
 *   containers  docker inspect of every container: newly privileged, host
 *               network/PID/IPC, new published ports, new capabilities and
 *               devices, the Docker socket or / mounted. A new container only
 *               counts when it has such rights (ports alone don't).
 *   plugins     /var/log/plugins/*.plg: a new plugin, or its pluginURL now
 *               pointing somewhere else (the host, on code hosts its owner)
 *   flash       /boot/config/go (line hashes only, never its text), files in
 *               /boot/extra, the users in /boot/config/passwd, a changed
 *               password (a hash of each user's shadow field — never the field
 *               itself), SSH keys in /boot/config/ssh/<user>/authorized_keys
 *               (/root/.ssh links there on Unraid 7)
 *   shares      emhttp's sec.ini / sec_nfs.ini (RAM: user shares, disks, pools
 *               and the flash, as Unraid applies them) with SMB/NFS switched on
 *               in /boot/config/share.cfg: a share now open to guests (secure =
 *               they read, public = they read and write)
 *   scheduled   what starts on its own as root (see "scheduled and
 *               auto-starting" below): root's own crontab next to Unraid's — new
 *               lines, lines in both (they run twice), the office's own lines
 *               there, programs gone, with the syslog around the file's time as
 *               evidence; the plugins' .cron files on the flash; User Scripts and
 *               their schedules; atd's queue; Unraid's notification agents
 *   data flow   who pulls how much (see "data flow" below): per client and file
 *               service (SMB, NFS, SSH, the WebGUI) the bytes the server sent,
 *               from the kernel's counters of the open connections (ss);
 *               SMB's users, machines and the hours they start sessions;
 *               per container what it sent (its network namespace's counters);
 *               per ZFS share what was written (ZFS `written`, the ransomware
 *               pattern) — hourly, learned per client, container and share,
 *               told only when far above what is normal at that time of the
 *               week; the office's own backup and restore are expected
 *
 * All of it lives in RAM or on the flash: no disk wakes up. What differs goes
 * into his watch book, to the team lead as 'checks' (recommended, one per
 * kind) and — the important kinds — right away to Unraid's notifications (per
 * kind at most one every WATCH_NOTIFY_QUIET, so a burst gives one message).
 * «I know, thanks» — on his page, or the team lead's on his finding — makes
 * that state the new normal. What gets safer (fewer rights, a share closed
 * again, a plugin removed) becomes normal by itself.
 *
 * Not Fix Common Problems' static checks: never "SSH is on" or "a weak
 * password", only changes.
 *
 * data/watchman/: baseline.json (what is normal), book.json (the watch book,
 * at most WATCH_BOOK_MAX entries, noted ones for WATCH_BOOK_DAYS), state.json
 * (syslog position, recent failures, the last round, notifications — small,
 * the tick and the metrics read it), seen.json (what the last round saw, for
 * «I know, thanks»), flow.json (the data flow's hourly history, aggregated —
 * never per connection; the last round's counters stay in RAM). The page reads
 * data/watchman.json (watchmanPageState()).
 */

const WATCH_EVERY        = 300;              // a round every 5 minutes
const WATCH_LOOK         = 20;               // the tick looks whether one is due this often (seconds)
const WATCH_MAX_RUN      = 240;              // a round that takes longer is stopped
const WATCH_FAIL_BURST   = 5;                // failed logins from one address …
const WATCH_FAIL_WINDOW  = 600;              // … within 10 minutes: a burst
const WATCH_NOTIFY_QUIET = 3600;             // per kind at most one notification an hour
const WATCH_BOOK_MAX     = 500;              // entries in the watch book
const WATCH_BOOK_DAYS    = 90;               // noted entries stay this long
const WATCH_FORGET       = 30 * 86400;       // what is gone (a plugin, a container, a share) stays known this long
const WATCH_READ_MAX     = 32 * 1024 * 1024; // syslog read in one round at most (then the newest part)
const WATCH_LEARN_MAX    = 16 * 1024 * 1024; // per file when he takes over the watch
const WATCH_IPS_MAX      = 500;              // login addresses he knows
const WATCH_TRACK_MAX    = 2000;             // addresses with recent failures followed
const WATCH_LIST_MAX     = 8;                // names and services kept per entry
const WATCH_ID           = '/^w[0-9a-f]{10}$/D';
const WATCH_CODE_HOSTS   = ['github.com', 'raw.githubusercontent.com', 'gitlab.com', 'codeberg.org', 'bitbucket.org'];

/** Every kind of entry: its group, and whether it goes to Unraid's notifications right away */
const WATCH_KINDS = [
    'login_new_ip'         => ['login', true],
    'login_failures'       => ['login', true],
    'container_new'        => ['container', true],
    'container_privileged' => ['container', true],
    'container_host'       => ['container', true],
    'container_ports'      => ['container', false],
    'container_rights'     => ['container', false],
    'plugin_new'           => ['plugin', true],
    'plugin_source'        => ['plugin', true],
    'flash_go'             => ['flash', true],
    'flash_extra'          => ['flash', true],
    'flash_user'           => ['flash', true],
    'flash_password'       => ['flash', true],
    'flash_ssh_key'        => ['flash', true],
    'share_public'         => ['share', true],
    'cron_new'             => ['sched', true],
    'cron_twice'           => ['sched', true],
    'cron_office'          => ['sched', true],
    'cron_dead'            => ['sched', false],
    'cron_file'            => ['sched', false],
    'cron_file_foreign'    => ['sched', true],
    'script_new'           => ['sched', false],
    'script_changed'       => ['sched', false],
    'at_job'               => ['sched', true],
    'at_userscript'        => ['sched', false],     // a User Script run in the background: noted by himself (watchmanAtUserScript())
    'notify_agent'         => ['sched', true],
    'flow_client'          => ['flow', true],
    'flow_container'       => ['flow', true],
    'flow_written'         => ['flow', true],
    'smb_user'             => ['flow', true],
    'smb_client'           => ['flow', false],
    'smb_hour'             => ['flow', false],
];

// syslog lines: Unraid's "Oct  6 08:54:00 Tower …" (or an ISO time, if rsyslog is set so)
const WATCH_TIME_SYSLOG = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s/';
const WATCH_TIME_ISO    = '/^(\d{4}-\d\d-\d\d)[T ](\d\d:\d\d:\d\d)(?:\.\d+)?(Z|[+-]\d\d:?\d\d)?\s/';
// the web login (dynamix/include/.login.php, my_logger → tag "webgui"): the address is the last " from …" (the name is the user's input)
const WATCH_WEB         = '/\swebgui:\s+(Successful|Unsuccessful) login user (.*) from (\S+?)\.?(?:\s|$)/i';
const WATCH_SSH_OK      = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Accepted (\S+) for (\S+) from (\S+) port \d+/';
const WATCH_SSH_FAIL    = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Failed (\S+) for (invalid user )?(\S+) from (\S+) port \d+/';
const WATCH_SSH_INVALID = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Invalid user (.*) from (\S+) port \d+/';
// docker inspect: name, image, HostConfig and mounts as JSON (tabs and newlines inside are escaped), the main process (data flow)
const WATCH_INSPECT     = "{{json .Name}}\t{{json .Config.Image}}\t{{json .HostConfig}}\t{{json .Mounts}}\t{{.State.Pid}}";

desk('watchman', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'start'   => fn () => watchmanRecover(),
    'tick'    => fn () => watchmanTick(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => watchmanPageState()],
        'round'   => fn (array $r) => watchmanRoundNow(),
        'ack'     => fn (array $r) => watchmanAck($r['id'] ?? null),
        'ack_all' => fn (array $r) => watchmanAck('*'),
        'notify_set' => fn (array $r) => watchmanNotifySet($r['on'] ?? null),
    ],
    'jobs'    => ['watchman-round' => fn (array $args) => watchmanRun()],
    'checks'  => fn (): array => watchmanChecks(),
    'metrics' => fn (): array => watchmanMetrics(),
]);

/** Where he reads — fixed; the tests hand in copies */
function watchmanPaths(): array
{
    return [
        'syslog'     => '/var/log/syslog',
        'plugins'    => '/var/log/plugins',
        'go'         => '/boot/config/go',
        'extra'      => '/boot/extra',
        'passwd'     => '/boot/config/passwd',
        'shadow'     => '/boot/config/shadow',
        'ssh'        => '/boot/config/ssh',
        'sec'        => '/var/local/emhttp/sec.ini',
        'sec_nfs'    => '/var/local/emhttp/sec_nfs.ini',
        'share_cfg'  => '/boot/config/share.cfg',
        'etc_passwd' => '/etc/passwd',
        'crontabs'   => '/var/spool/cron/crontabs',
        'cron_d'     => '/etc/cron.d',
        'cron_files' => '/boot/config/plugins',
        'userscripts' => '/boot/config/plugins/user.scripts',
        'atjobs'     => '/var/spool/atjobs',
        'agents'     => '/boot/config/plugins/dynamix/notifications/agents',
        'var_ini'    => '/var/local/emhttp/var.ini',
        'disks_ini'  => '/var/local/emhttp/disks.ini',
    ];
}

function watchmanDir(): string
{
    return DATA_DIR . '/watchman';
}

/** Since when he works here (data/office/staff.json), or null */
function watchmanHiredSince(): ?int
{
    $hired = (array) ((readJson(DATA_DIR . '/office/staff.json') ?? [])['hired'] ?? []);
    return isset($hired['watchman']) ? (int) $hired['watchman'] : null;
}

// ===================================================================== his files

/** @return array{baseline: ?array, book: list<array>, state: array} */
function watchmanLoad(?string $dir = null): array
{
    $dir ??= watchmanDir();
    $book = readJson("$dir/book.json");
    $entries = [];
    foreach ((array) ($book['entries'] ?? []) as $e) {
        if (is_array($e) && is_string($e['id'] ?? null) && is_string($e['kind'] ?? null)) {
            $entries[] = $e;
        }
    }
    return ['baseline' => readJson("$dir/baseline.json"), 'book' => $entries, 'state' => readJson("$dir/state.json") ?? []];
}

/** Writes what changed ($new: baseline, book, state; seen when given) */
function watchmanSave(string $dir, array $old, array $new): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @lchown($dir, FILE_UID);
        @lchgrp($dir, FILE_GID);
    }
    foreach (['baseline' => 'baseline.json', 'book' => 'book.json', 'state' => 'state.json', 'seen' => 'seen.json', 'flow' => 'flow.json'] as $k => $file) {
        if (!array_key_exists($k, $new) || $new[$k] === null || ($old[$k] ?? null) === $new[$k]) {
            continue;
        }
        writeAtomic("$dir/$file", jsonEncode($k === 'book' ? ['entries' => array_values($new[$k])] : $new[$k]));
    }
}

/** A lock of his own in RAM (one per data folder, so a test never waits for the real one) */
function watchmanLockFile(string $dir, string $what): string
{
    @mkdir(RUN_DIR, 0700, true);
    return RUN_DIR . "/watchman-$what-" . substr(md5($dir), 0, 8) . '.lock';
}

/** Runs $fn while holding his book (a round's merge, «I know, thanks»): a few milliseconds each */
function watchmanLocked(string $dir, callable $fn): mixed
{
    $h = @fopen(watchmanLockFile($dir, 'book'), 'c');
    if (!$h) {
        throw new Problem('watch_busy');
    }
    $until = microtime(true) + 10;
    while (!flock($h, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $until) {
            fclose($h);
            throw new Problem('watch_busy');
        }
        usleep(50000);
    }
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// ===================================================================== rounds: started from the tick

/** At the start: a round from before the agent restarted may still be on its way (it holds the round lock) */
function watchmanRecover(): void
{
    $GLOBALS['wmOrphan'] = watchmanRoundBusy(watchmanDir());
}

/** Is a round running? It holds its lock for as long as it runs */
function watchmanRoundBusy(string $dir): bool
{
    $h = @fopen(watchmanLockFile($dir, 'round'), 'c');
    if (!$h) {
        return false;
    }
    $free = flock($h, LOCK_SH | LOCK_NB);
    if ($free) {
        flock($h, LOCK_UN);
    }
    fclose($h);
    return !$free;
}

/**
 * Every ~150 ms: while a round runs, whether it is done (or takes too long);
 * otherwise every WATCH_LOOK seconds whether one is due — two small files.
 */
function watchmanTick(): void
{
    $job = $GLOBALS['wmRound'] ?? null;
    if ($job) {
        $status = proc_get_status($job['process']);
        if ($status['running']) {
            if (time() - $job['since'] > WATCH_MAX_RUN && empty($job['stopped'])) {
                proc_terminate($job['process']);
                $GLOBALS['wmRound']['stopped'] = true;
                logLine('Night watchman: his round took longer than ' . WATCH_MAX_RUN . ' s — stopped');
            }
            return;
        }
        proc_close($job['process']);
        $GLOBALS['wmRound'] = null;
        if ($status['exitcode'] > 0) {
            $err = trim((string) @file_get_contents(RUN_DIR . '/watchman-round.err', false, null, 0, 4096));
            logLine('Night watchman: round failed (exit ' . $status['exitcode'] . ')' . ($err !== '' ? ': ' . strtok($err, "\n") : ''));
        }
        return;
    }
    $now = time();
    if ($now < ($GLOBALS['wmNextLook'] ?? 0)) {
        return;
    }
    $GLOBALS['wmNextLook'] = $now + WATCH_LOOK;
    if (!empty($GLOBALS['wmOrphan'])) {
        $GLOBALS['wmOrphan'] = watchmanRoundBusy(watchmanDir());
        if ($GLOBALS['wmOrphan']) {
            return;
        }
    }
    $since = watchmanHiredSince();
    if ($since === null) {
        return;
    }
    $st = readJson(watchmanDir() . '/state.json') ?? [];
    $started = (int) ($GLOBALS['wmStarted'] ?? 0);
    $due = $now - max((int) ($st['round']['time'] ?? 0), $started) >= WATCH_EVERY;
    $anew = (int) ($st['hired'] ?? -1) !== $since && $now - $started >= 60;     // just hired (again): his first round right away
    if ($due || $anew) {
        watchmanStart();
    }
}

/** A round in a process of its own (niced), without the agent's open files */
function watchmanStart(): bool
{
    if (!empty($GLOBALS['wmRound']) || !empty($GLOBALS['wmOrphan'])) {
        return true;
    }
    @mkdir(RUN_DIR, 0700, true);
    $GLOBALS['wmStarted'] = time();
    $pipes = [];
    $process = @proc_open(['/bin/sh', '-c', 'exec "$@"' . closeInheritedFds(), 'sh',
                           bin('nice') ?? 'nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'watchman-round'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', RUN_DIR . '/watchman-round.err', 'w']], $pipes, '/');
    if (!is_resource($process)) {
        logLine('Night watchman: could not start his round');
        return false;
    }
    $GLOBALS['wmRound'] = ['process' => $process, 'since' => time()];
    return true;
}

/** "round": one now (the page polls until round.running is false) */
function watchmanRoundNow(): array
{
    if (watchmanHiredSince() === null) {
        throw new Problem('not_hired', ['desk' => 'watchman']);
    }
    if (!watchmanStart()) {
        throw new Problem('watch_start_failed');
    }
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** php agent.php job watchman-round — one round (only one at a time, only while he works here) */
function watchmanRun(): int
{
    $since = watchmanHiredSince();
    if ($since === null) {
        return 0;
    }
    $dir = watchmanDir();
    $h = @fopen(watchmanLockFile($dir, 'round'), 'c');
    if (!$h || !flock($h, LOCK_EX | LOCK_NB)) {
        return 0;               // another round is on its way
    }
    try {
        $r = watchmanRound(watchmanPaths(), $dir, $since, flow: fn (?array $containers): array => watchmanFlowLook(watchmanPaths(), $containers));
    } catch (Throwable $e) {
        logLine('Night watchman: round failed: ' . $e->getMessage());
        try {
            watchmanLocked($dir, function () use ($dir, $e): void {
                $d = watchmanLoad($dir);
                $st = $d['state'];
                $st['round'] = ['time' => time(), 'failed' => true, 'error' => substr($e->getMessage(), 0, 300)] + (array) ($st['round'] ?? []);
                watchmanSave($dir, $d, ['state' => $st]);
            });
        } catch (Throwable) {
        }
        return 1;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    if ($r['fresh']) {
        logLine('Night watchman: took over the watch — ' . watchmanSummaryLine($r['summary']));
    }
    if ($r['added']) {
        $kinds = array_count_values($r['added']);
        logLine('Night watchman: ' . count($r['added']) . ' new in the watch book (' . implode(', ', array_map(fn ($k, $n) => "$k×$n", array_keys($kinds), $kinds)) . ')');
    }
    foreach ($r['told'] as $t) {
        logLine("Night watchman: Unraid's notifications " . ($t['sent'] ? 'told' : 'could not be told') . " about {$t['n']} × {$t['kind']}");
    }
    watchmanPageState();
    return 0;
}

// ===================================================================== a round

/**
 * One round: read what is new (outside the lock, it takes a moment), then —
 * holding the book — compare it with what is normal now and write it down.
 * $hired: since when he works here (another one than the baseline's: he
 * takes over the watch anew). $docker: a stand-in for docker inspect (tests);
 * $acks: the team lead's notes (tests); $notify false: tell nobody. $flow: the
 * data flow's look (watchmanFlowLook(), given the containers with their main
 * process); without it the data flow is left out.
 *
 * @return array{fresh: bool, added: list<string>, told: list<array>, summary: array}
 */
function watchmanRound(array $paths, string $dir, int $hired, ?int $now = null, ?callable $docker = null, bool $notify = true, ?string $acks = null,
                       ?callable $flow = null): array
{
    $t0 = microtime(true);
    $now ??= time();
    $snap = watchmanLoad($dir);
    $fresh = !is_array($snap['baseline']) || (int) ($snap['baseline']['hired'] ?? -1) !== $hired;
    $known = watchmanKnownUsers($paths['etc_passwd']);
    [$events, $pos, $read] = watchmanReadLogins($paths['syslog'], $fresh ? null : ($snap['state']['syslog'] ?? null), $fresh, $known, $now);
    $containers = $docker ? $docker() : watchmanContainers();
    $look = $flow ? $flow($containers) : null;
    if (is_array($containers)) {
        $containers = array_map(fn ($c) => array_diff_key((array) $c, ['pid' => true]), $containers);     // the process is the data flow's only
    }
    $seen = [
        'containers' => $containers,
        'plugins'    => watchmanPlugins($paths['plugins']),
        'flash'      => watchmanFlash($paths),
        'shares'     => watchmanShares($paths),
        'sched'      => watchmanSched($paths, (array) ((readJson("$dir/seen.json") ?? [])['sched'] ?? []), $now),
    ];

    return watchmanLocked($dir, function () use ($dir, $hired, $now, $fresh, $events, $pos, $read, $seen, $notify, $acks, $t0, $look): array {
        $old = watchmanLoad($dir);
        $old['seen'] = readJson("$dir/seen.json");
        $old['flow'] = readJson("$dir/flow.json");
        $b = $old['baseline'];
        $book = $old['book'];
        $st = $old['state'];
        // what Docker didn't answer this time stays as the last round saw it
        $observed = $seen;
        if ($observed['containers'] === null) {
            $observed['containers'] = $old['seen']['containers'] ?? null;
        }
        if ($observed['shares'] === null) {
            $observed['shares'] = $old['seen']['shares'] ?? null;
        }
        $added = $told = [];
        if ($fresh) {
            [$b, $book, $st] = watchmanTakeOver($hired, $events, $seen, $book, $st, $now);
        } else {
            // a part missing from his baseline (a file by hand, an older one): what he sees now is normal there
            $b['ips'] = (array) ($b['ips'] ?? []);
            $b['fail_ips'] = (array) ($b['fail_ips'] ?? []);
            $b['plugins'] = is_array($b['plugins'] ?? null) ? $b['plugins'] : array_map(fn ($p) => $p + ['seen' => $now], $seen['plugins']);
            $b['flash'] = is_array($b['flash'] ?? null) ? $b['flash'] + ['go' => null, 'extra' => [], 'users' => [], 'pw' => [], 'keys' => []] : $seen['flash'];
            $b['containers'] = is_array($b['containers'] ?? null) ? $b['containers'] : null;
            $b['shares'] = is_array($b['shares'] ?? null) ? $b['shares'] : null;
            $b['sched'] = is_array($b['sched'] ?? null) ? $b['sched'] : null;
            watchmanTeamLeadNotes($b, $book, is_array($old['seen']) ? $old['seen'] : $observed, $now, $acks);
            $added = array_merge(
                watchmanLogins($b, $book, $st, $events, false),
                watchmanContainersCompare($b['containers'], $seen['containers'], $book, $now),
                watchmanPluginsCompare($b['plugins'], $seen['plugins'], $book, $now),
                watchmanFlashCompare($b['flash'], $seen['flash'], $book, $now),
                watchmanSharesCompare($b['shares'], $seen['shares'], $book, $now),
                watchmanSchedCompare($b['sched'], $seen['sched'], $seen['plugins'], $book, $now),
            );
        }
        $flow = null;
        if ($look !== null) {
            // the data flow: the last round's counters (RAM) against this look; taken over anew, it starts learning anew
            $b['flow'] = $fresh ? null : (is_array($b['flow'] ?? null) ? $b['flow'] : null);
            if ($fresh) {
                $none = [];
                [, $flow, $counters] = watchmanFlowCompare($b['flow'], [], null, $look, $none, $now);
            } else {
                [$more, $flow, $counters] = watchmanFlowCompare($b['flow'], (array) ($old['flow'] ?? []), watchmanFlowCounters($dir, $now), $look, $book, $now);
                $added = array_merge($added, $more);
            }
            writeAtomic(watchmanFlowCountersFile($dir), jsonEncode($counters), 0600, 0, 0);
            $st['flow'] = watchmanFlowTotals($flow);
        }
        watchmanTidy($b, $st, $now);
        $book = watchmanPrune($book, $now);
        if (!$fresh) {
            $told = watchmanNotifyDue($book, $st, $now, $notify);
        }
        $st['hired'] = $hired;
        $st['syslog'] = $pos;
        $st['open'] = watchmanOpenCounts($book);
        $st['round'] = ['time' => $now, 'duration_ms' => (int) round((microtime(true) - $t0) * 1000), 'failed' => false,
                        'read' => $read['read'], 'skipped' => $read['skipped'], 'rotated' => $read['rotated'],
                        'docker' => $seen['containers'] !== null, 'shares' => $seen['shares'] !== null, 'flow' => $look !== null, 'added' => count($added)];
        watchmanSave($dir, $old, ['baseline' => $b, 'book' => $book, 'state' => $st, 'seen' => $observed, 'flow' => $flow]);
        return ['fresh' => $fresh, 'added' => array_values($added), 'told' => $told, 'summary' => watchmanCounts($b)];
    });
}

/**
 * The first round after hiring: everything he sees is normal — the login
 * addresses in the syslog's history too, and addresses that already had a
 * burst of failures. What was still open in his book from an earlier watch
 * is closed; one line says he took over.
 */
function watchmanTakeOver(int $hired, array $events, array $seen, array $book, array $st, int $now): array
{
    $b = ['hired' => $hired, 'time' => $now, 'ips' => [], 'fail_ips' => [], 'containers' => null, 'plugins' => [],
          'flash' => $seen['flash'], 'shares' => null, 'sched' => null];
    $st['fails'] = [];
    $st['notified'] = [];
    $none = [];
    watchmanLogins($b, $none, $st, $events, true);
    if ($seen['containers'] !== null) {
        $b['containers'] = array_map(fn ($c) => ['tokens' => $c['tokens'], 'seen' => $now], $seen['containers']);
    }
    $b['plugins'] = array_map(fn ($p) => $p + ['seen' => $now], $seen['plugins']);
    if ($seen['shares'] !== null) {
        $b['shares'] = array_map(fn ($s) => $s + ['seen' => $now], $seen['shares']);
    }
    watchmanSchedCompare($b['sched'], $seen['sched'] ?? null, $seen['plugins'], $none, $now);     // all of it normal
    foreach ($book as $i => $e) {
        if (watchmanOpen($e)) {
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'baseline';
        }
    }
    $book[] = watchmanEntry('watch', 'watch', $now, watchmanCounts($b));
    return [$b, $book, $st];
}

/** What he knows, counted (the "took over" line, the log) */
function watchmanCounts(array $b): array
{
    $containers = (array) ($b['containers'] ?? []);
    $shares = (array) ($b['shares'] ?? []);
    return [
        'ips'        => count((array) ($b['ips'] ?? [])),
        'containers' => count($containers),
        'special'    => count(array_filter($containers, fn ($c) => watchmanRights((array) ($c['tokens'] ?? [])) !== [])),
        'plugins'    => count((array) ($b['plugins'] ?? [])),
        'shares'     => count($shares),
        'open'       => count(array_filter($shares, fn ($s) => ($s['smb'] ?? 0) > 0 || ($s['nfs'] ?? 0) > 0)),
    ];
}

function watchmanSummaryLine(array $c): string
{
    return "{$c['ips']} login addresses, {$c['containers']} containers ({$c['special']} with special rights), "
         . "{$c['plugins']} plugins, {$c['shares']} shares ({$c['open']} open to guests)";
}

/** Keeps his memory small: old failure trackers, too many addresses, what is gone for long */
function watchmanTidy(array &$b, array &$st, int $now): void
{
    $fails = (array) ($st['fails'] ?? []);
    foreach ($fails as $ip => $tr) {
        if (!is_array($tr) || !$tr['t'] || max($tr['t']) < $now - WATCH_FAIL_WINDOW) {
            unset($fails[$ip]);
        }
    }
    if (count($fails) > WATCH_TRACK_MAX) {
        uasort($fails, fn ($x, $y) => max($y['t']) <=> max($x['t']));
        $fails = array_slice($fails, 0, WATCH_TRACK_MAX, true);
    }
    $st['fails'] = $fails;
    if (count((array) ($b['ips'] ?? [])) > WATCH_IPS_MAX) {
        uasort($b['ips'], fn ($x, $y) => ($y['last'] ?? 0) <=> ($x['last'] ?? 0));
        $b['ips'] = array_slice($b['ips'], 0, WATCH_IPS_MAX, true);
    }
    if (count((array) ($b['fail_ips'] ?? [])) > WATCH_IPS_MAX) {
        uasort($b['fail_ips'], fn ($x, $y) => ($y['last'] ?? 0) <=> ($x['last'] ?? 0));
        $b['fail_ips'] = array_slice($b['fail_ips'], 0, WATCH_IPS_MAX, true);
    }
}

// ===================================================================== the watch book

function watchmanEntry(string $kind, string $key, int $time, array $p, int $count = 1): array
{
    return ['id' => 'w' . bin2hex(random_bytes(5)), 'kind' => $kind, 'key' => $key, 'time' => $time, 'last' => $time,
            'count' => $count, 'p' => $p, 'noted' => null, 'by' => null, 'told' => null];
}

/** Not noted yet (the "took over" lines never are open) */
function watchmanOpen(array $e): bool
{
    return isset(WATCH_KINDS[$e['kind'] ?? '']) && empty($e['noted']);
}

/**
 * A state that differs (a container's rights, a plugin, a file): one open
 * entry per key — seen again, it is only brought up to date.
 * @return string|null  the kind when the entry is new
 */
function watchmanSet(array &$book, string $kind, string $key, int $now, array $p): ?string
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && watchmanOpen($e)) {
            if (($e['p'] ?? []) !== $p) {
                $book[$i]['p'] = $p;
                $book[$i]['last'] = $now;
            }
            return null;
        }
    }
    $book[] = watchmanEntry($kind, $key, $now, $p);
    return $kind;
}

/**
 * Something that happened (a login, failed logins): while its entry is open,
 * again counts up there.
 * @return string|null  the kind when the entry is new
 */
function watchmanBump(array &$book, string $kind, string $key, int $time, int $add, array $p): ?string
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && watchmanOpen($e)) {
            $book[$i]['count'] = (int) $e['count'] + $add;
            $book[$i]['last'] = max((int) $e['last'], $time);
            $book[$i]['time'] = min((int) $e['time'], $time);
            $book[$i]['p'] = watchmanMerge((array) $e['p'], $p);
            return null;
        }
    }
    $book[] = watchmanEntry($kind, $key, $time, $p, $add);
    return $kind;
}

/** Lists joined (at most WATCH_LIST_MAX), counts added, the rest from $b */
function watchmanMerge(array $a, array $b): array
{
    foreach ($b as $k => $v) {
        if (is_array($v)) {
            $a[$k] = array_slice(array_values(array_unique(array_merge((array) ($a[$k] ?? []), $v))), 0, WATCH_LIST_MAX);
        } elseif (is_int($v) && is_int($a[$k] ?? null)) {
            $a[$k] += $v;
        } else {
            $a[$k] = $v;
        }
    }
    return $a;
}

/** Noted entries go after WATCH_BOOK_DAYS; beyond WATCH_BOOK_MAX the oldest noted first, then the oldest */
function watchmanPrune(array $book, int $now): array
{
    $book = array_values(array_filter($book, fn ($e) => watchmanOpen($e) || $now - (int) ($e['noted'] ?? $e['last'] ?? 0) <= WATCH_BOOK_DAYS * 86400));
    while (count($book) > WATCH_BOOK_MAX) {
        $drop = 0;
        foreach ($book as $i => $e) {
            if (!watchmanOpen($e)) {
                $drop = $i;
                break;
            }
        }
        array_splice($book, $drop, 1);
    }
    return $book;
}

/** @return array<string, int>  kind => entries not noted yet */
function watchmanOpenCounts(array $book): array
{
    $n = [];
    foreach ($book as $e) {
        if (watchmanOpen($e)) {
            $n[$e['kind']] = ($n[$e['kind']] ?? 0) + 1;
        }
    }
    return $n;
}

// ===================================================================== logins

/** The names of this server's users (/etc/passwd): only those are kept from failed logins */
function watchmanKnownUsers(string $file): array
{
    $names = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $name = strtok($line, ':');
        if (is_string($name) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D', $name)) {
            $names[$name] = true;
        }
    }
    return $names;
}

/**
 * Login lines of the syslog since $pos ({ino, size}). Rotated meanwhile
 * (another inode, or shorter): the rest of the old file first (syslog.1 or
 * .2, found by its inode), then the new one from its start. $learn (taking
 * over the watch): syslog.1 and syslog, all of it. Without a position and not
 * learning: from now on. At most WATCH_READ_MAX / WATCH_LEARN_MAX of a file
 * (its newest part); only whole lines — an unfinished one waits.
 *
 * @return array{0: list<array>, 1: ?array, 2: array{read: int, skipped: int, rotated: bool}}  events, new position, what was read
 */
function watchmanReadLogins(string $syslog, ?array $pos, bool $learn, array $known, int $now): array
{
    $events = [];
    $info = ['read' => 0, 'skipped' => 0, 'rotated' => false];
    clearstatcache();
    $st = @stat($syslog);
    if (!$st) {
        return [[], $pos, $info];
    }
    $max = $learn ? WATCH_LEARN_MAX : WATCH_READ_MAX;
    $from = 0;
    if ($learn) {
        if (is_file("$syslog.1")) {
            watchmanReadFile("$syslog.1", 0, $max, $known, $now, $events, $info);
        }
    } elseif ($pos === null) {
        $from = (int) $st['size'];
    } elseif ((int) ($pos['ino'] ?? -1) === (int) $st['ino'] && (int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $st['size']) {
        $from = (int) $pos['size'];
    } else {
        $info['rotated'] = true;
        foreach (["$syslog.1", "$syslog.2"] as $older) {
            $o = @stat($older);
            if ($o && (int) $o['ino'] === (int) ($pos['ino'] ?? -1)) {
                if ((int) ($pos['size'] ?? PHP_INT_MAX) <= (int) $o['size']) {
                    watchmanReadFile($older, (int) $pos['size'], $max, $known, $now, $events, $info);
                }
                break;
            }
        }
    }
    $end = $from === (int) $st['size'] && !$learn && $pos === null
        ? $from
        : watchmanReadFile($syslog, $from, $max, $known, $now, $events, $info);
    return [$events, ['ino' => (int) $st['ino'], 'size' => $end], $info];
}

/** Whole lines of $path from $from (more than $max: the newest part), login lines as events; where the last whole line ends */
function watchmanReadFile(string $path, int $from, int $max, array $known, int $now, array &$events, array &$info): int
{
    $h = @fopen($path, 'r');
    if (!$h) {
        return $from;
    }
    $size = (int) (fstat($h)['size'] ?? 0);
    $start = max($from, $size - $max);
    if ($start > $from) {
        $info['skipped'] += $start - $from;
        fseek($h, $start - 1);
        if (fgetc($h) !== "\n") {
            fgets($h);              // began inside a line
        }
    } else {
        fseek($h, $start);
    }
    $at = (int) ftell($h);
    $first = $at;
    while (($line = fgets($h)) !== false) {
        if (!str_ends_with($line, "\n")) {
            break;                  // still being written: the next round reads it
        }
        $at += strlen($line);
        if (str_contains($line, 'sshd') || stripos($line, 'webgui') !== false) {
            $ev = watchmanParseLine(rtrim($line, "\r\n"), $known, $now);
            if ($ev) {
                $events[] = $ev;
            }
        }
    }
    fclose($h);
    $info['read'] += $at - $first;
    return $at;
}

/**
 * One syslog line → a login event, or null:
 * ['ok' => bool, 'service' => 'web'|'ssh:<method>'|'ssh', 'user' => ?string, 'ip' => string, 'time' => int].
 * Failed logins keep the name only when it is a user of this server
 * ($known); "Failed … for invalid user" is left out (its "Invalid user" line
 * counts, once per connection).
 */
function watchmanParseLine(string $line, array $known, int $now): ?array
{
    $ev = null;
    if (preg_match(WATCH_WEB, $line, $m)) {
        $ok = strcasecmp($m[1], 'Successful') === 0;
        $ev = ['ok' => $ok, 'service' => 'web', 'user' => $m[2], 'ip' => $m[3]];
    } elseif (preg_match(WATCH_SSH_OK, $line, $m)) {
        $ev = ['ok' => true, 'service' => 'ssh:' . strtolower($m[1]), 'user' => $m[2], 'ip' => $m[3]];
    } elseif (preg_match(WATCH_SSH_FAIL, $line, $m)) {
        if ($m[2] !== '') {
            return null;
        }
        $ev = ['ok' => false, 'service' => 'ssh:' . strtolower($m[1]), 'user' => $m[3], 'ip' => $m[4]];
    } elseif (preg_match(WATCH_SSH_INVALID, $line, $m)) {
        $ev = ['ok' => false, 'service' => 'ssh', 'user' => null, 'ip' => $m[2]];
    }
    if ($ev === null) {
        return null;
    }
    $ip = watchmanIp($ev['ip']);
    if ($ip === null) {
        return null;
    }
    $ev['ip'] = $ip;
    $user = $ev['user'];
    $ev['user'] = is_string($user) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.@-]{0,31}$/D', $user) && ($ev['ok'] || isset($known[$user])) ? $user : null;
    if (!preg_match('/^(?:web|ssh(?::[a-z0-9\/-]{1,40})?)$/D', $ev['service'])) {
        $ev['service'] = str_starts_with($ev['service'], 'ssh') ? 'ssh' : 'web';
    }
    $ev['time'] = watchmanLineTime($line, $now) ?? $now;
    return $ev;
}

/** An address as it is written everywhere: IPv4 plain (also from ::ffff:a.b.c.d), IPv6 short; null when it is none */
function watchmanIp(string $s): ?string
{
    $s = rtrim(trim($s, '[]'), '.');
    $s = preg_replace('/%[\w.-]+$/', '', $s) ?? $s;
    if (filter_var($s, FILTER_VALIDATE_IP) === false) {
        return null;
    }
    $bin = @inet_pton($s);
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    return inet_ntop($bin) ?: null;
}

/** The time at the start of a syslog line (no year in it: this year, or last year for December read in January) */
function watchmanLineTime(string $line, int $now): ?int
{
    if (preg_match(WATCH_TIME_SYSLOG, $line, $m)) {
        $year = (int) date('Y', $now);
        $t = strtotime("$m[1] $m[2] $year $m[3]");
        if ($t !== false && $t > $now + 86400) {
            $t = strtotime("$m[1] $m[2] " . ($year - 1) . " $m[3]");
        }
        return $t === false ? null : $t;
    }
    if (preg_match(WATCH_TIME_ISO, $line, $m)) {
        $t = strtotime("$m[1] $m[2]" . (isset($m[3]) && $m[3] !== '' ? " $m[3]" : ''));
        return $t === false ? null : $t;
    }
    return null;
}

/**
 * A failed login in its address's tracker: how many failures to add to a
 * burst — 0 while fewer than $burst within $window, all of the window's when
 * the burst starts, 1 for each one after it (until $window passes without one).
 */
function watchmanFailStep(array &$fails, array $ev, int $burst = WATCH_FAIL_BURST, int $window = WATCH_FAIL_WINDOW): int
{
    $ip = $ev['ip'];
    $t = (int) $ev['time'];
    $tr = is_array($fails[$ip] ?? null) ? $fails[$ip] : null;
    if ($tr === null || !$tr['t'] || $t - max($tr['t']) > $window) {
        $tr = ['t' => [], 'burst' => false, 'users' => [], 'services' => [], 'unknown' => 0];     // a pause: anew
    }
    $tr['t'] = array_values(array_filter($tr['t'], fn ($x) => $x > $t - $window));
    $tr['t'][] = $t;
    if ($ev['user'] !== null) {
        $tr['users'] = array_slice(array_values(array_unique(array_merge($tr['users'], [$ev['user']]))), 0, WATCH_LIST_MAX);
    } else {
        $tr['unknown']++;
    }
    $tr['services'] = array_slice(array_values(array_unique(array_merge($tr['services'], [$ev['service']]))), 0, WATCH_LIST_MAX);
    if ($tr['burst']) {
        $add = 1;
    } elseif (count($tr['t']) >= $burst) {
        $tr['burst'] = true;
        $add = count($tr['t']);
    } else {
        $add = 0;
    }
    $tr['t'] = array_slice($tr['t'], -$burst);           // enough to tell the next burst
    $fails[$ip] = $tr;
    return $add;
}

/**
 * The login events of a round. $learn (taking over): every address is
 * normal, so is one that had a burst. Otherwise: a login from an address he
 * doesn't know, a burst from one that isn't known for failing.
 * @return list<string>  the kinds of new entries
 */
function watchmanLogins(array &$b, array &$book, array &$st, array $events, bool $learn): array
{
    $added = [];
    $st['fails'] = (array) ($st['fails'] ?? []);
    foreach ($events as $ev) {
        $ip = $ev['ip'];
        $time = (int) $ev['time'];
        if ($ev['ok']) {
            if ($learn || isset($b['ips'][$ip])) {
                $k = $b['ips'][$ip] ?? ['first' => $time, 'last' => $time, 'users' => [], 'services' => []];
                $k['first'] = min((int) $k['first'], $time);
                $k['last'] = max((int) $k['last'], $time);
                $k['users'] = watchmanMerge(['x' => (array) $k['users']], ['x' => array_filter([$ev['user']])])['x'];
                $k['services'] = watchmanMerge(['x' => (array) $k['services']], ['x' => [$ev['service']]])['x'];
                $b['ips'][$ip] = $k;
                continue;
            }
            $added[] = watchmanBump($book, 'login_new_ip', "login_new_ip:$ip", $time, 1,
                ['ip' => $ip, 'users' => array_values(array_filter([$ev['user']])), 'services' => [$ev['service']]]);
            continue;
        }
        $prev = is_array($st['fails'][$ip] ?? null) ? $st['fails'][$ip] : null;
        $was = $prev && $prev['burst'] && $prev['t'] && $time - max($prev['t']) <= WATCH_FAIL_WINDOW;      // a burst going on
        $add = watchmanFailStep($st['fails'], $ev);
        if (!$add) {
            continue;
        }
        if ($learn || isset($b['fail_ips'][$ip])) {
            $k = $b['fail_ips'][$ip] ?? ['since' => $time, 'last' => $time, 'n' => 0, 'quiet' => 0];
            $k['last'] = max((int) $k['last'], $time);
            if ($learn) {
                $k['n'] += $add;
            } else {
                $k['quiet'] = (int) ($k['quiet'] ?? 0) + $add;      // known for failing: counted, not reported
            }
            $b['fail_ips'][$ip] = $k;
            continue;
        }
        $tr = $st['fails'][$ip];
        $p = $was ? ['ip' => $ip, 'users' => array_values(array_filter([$ev['user']])), 'services' => [$ev['service']], 'unknown' => $ev['user'] === null ? 1 : 0]
                  : ['ip' => $ip, 'users' => $tr['users'], 'services' => $tr['services'], 'unknown' => (int) $tr['unknown']];
        $added[] = watchmanBump($book, 'login_failures', "login_failures:$ip", $time, $add, $p);
    }
    return array_values(array_filter($added));
}

// ===================================================================== containers

/** Every container's rights, as docker run flags; null when Docker doesn't answer */
function watchmanContainers(): ?array
{
    [$exit, $ids] = run(['docker', 'ps', '-aq', '--no-trunc'], 20);
    if ($exit !== 0) {
        return null;
    }
    $ids = array_slice(array_values(array_filter(explode("\n", trim($ids)), fn ($x) => (bool) preg_match('/^[0-9a-f]{12,64}$/D', $x))), 0, 1000);
    if (!$ids) {
        return [];
    }
    [$exit, $text] = run(array_merge(['docker', 'inspect', '--format', WATCH_INSPECT], $ids), 60);
    if (trim($text) === '') {
        return $exit === 0 ? [] : null;
    }
    $out = [];
    foreach (explode("\n", trim($text)) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 4) {
            continue;
        }
        $name = ltrim((string) json_decode($f[0]), '/');
        $hc = json_decode($f[2], true);
        $mounts = json_decode($f[3], true);
        if ($name === '' || !is_array($hc)) {
            continue;
        }
        $out[$name] = ['image' => (string) json_decode($f[1]), 'tokens' => watchmanContainerTokens($hc, is_array($mounts) ? $mounts : []),
                       'pid' => (int) ($f[4] ?? 0)];
    }
    ksort($out);
    return $out;
}

/**
 * What a container may do beyond the usual, as the flags of docker run:
 * --privileged, --network/--pid/--ipc=host, --cap-add=X, --device=/dev/x,
 * -v /var/run/docker.sock, -v /, -p [ip:]host:container/proto. Sorted.
 */
function watchmanContainerTokens(array $hc, array $mounts): array
{
    $t = [];
    if (!empty($hc['Privileged'])) {
        $t[] = '--privileged';
    }
    foreach (['NetworkMode' => '--network', 'PidMode' => '--pid', 'IpcMode' => '--ipc'] as $key => $flag) {
        if (($hc[$key] ?? '') === 'host') {
            $t[] = "$flag=host";
        }
    }
    foreach ((array) ($hc['CapAdd'] ?? []) as $cap) {
        if (is_string($cap) && preg_match('/^(?:CAP_)?([A-Z0-9_]{1,40})$/iD', $cap, $m)) {
            $t[] = '--cap-add=' . strtoupper($m[1]);
        }
    }
    foreach ((array) ($hc['Devices'] ?? []) as $d) {
        $path = is_array($d) ? ($d['PathOnHost'] ?? null) : null;
        if (is_string($path) && $path !== '') {
            $t[] = '--device=' . substr($path, 0, 200);
        }
    }
    foreach ((array) ($hc['PortBindings'] ?? []) as $port => $binds) {
        foreach ((array) $binds as $bind) {
            if (!is_array($bind)) {
                continue;
            }
            $ip = (string) ($bind['HostIp'] ?? '');
            $host = (string) ($bind['HostPort'] ?? '');
            $at = in_array($ip, ['', '0.0.0.0', '::'], true) ? '' : (str_contains($ip, ':') ? "[$ip]:" : "$ip:");
            $t[] = '-p ' . substr($at . ($host !== '' ? "$host:" : '') . $port, 0, 120);
        }
    }
    foreach ($mounts as $m) {
        $src = is_array($m) ? (string) ($m['Source'] ?? '') : '';
        if (in_array($src, ['/var/run/docker.sock', '/run/docker.sock'], true) || $src === '/') {
            $t[] = "-v $src";
        }
    }
    $t = array_values(array_unique($t));
    sort($t);
    return $t;
}

/** Rights, not just published ports */
function watchmanRights(array $tokens): array
{
    return array_values(array_filter($tokens, fn ($t) => !str_starts_with((string) $t, '-p ')));
}

function watchmanTokenKind(string $t): string
{
    if ($t === '--privileged') {
        return 'container_privileged';
    }
    if (in_array($t, ['--network=host', '--pid=host', '--ipc=host'], true)) {
        return 'container_host';
    }
    return str_starts_with($t, '-p ') ? 'container_ports' : 'container_rights';
}

/**
 * Containers against what is normal. A new one is normal unless it has
 * rights; a known one with new rights gets an entry per kind; rights that went
 * are the new normal. Gone ones are remembered for WATCH_FORGET.
 */
function watchmanContainersCompare(?array &$known, ?array $seen, array &$book, int $now): array
{
    if ($seen === null) {
        return [];                  // Docker didn't answer: nothing to compare
    }
    if ($known === null) {           // Docker wasn't there when he took over: what he sees now is normal
        $known = array_map(fn ($c) => ['tokens' => $c['tokens'], 'seen' => $now], $seen);
        return [];
    }
    $added = [];
    foreach ($seen as $name => $c) {
        $tokens = (array) $c['tokens'];
        if (!isset($known[$name])) {
            if (!watchmanRights($tokens)) {
                $known[$name] = ['tokens' => $tokens, 'seen' => $now];
                continue;
            }
            $added[] = watchmanSet($book, 'container_new', "container_new:$name", $now,
                ['name' => (string) $name, 'image' => (string) ($c['image'] ?? ''), 'tokens' => $tokens]);
            continue;
        }
        $was = (array) ($known[$name]['tokens'] ?? []);
        $known[$name] = ['tokens' => array_values(array_intersect($was, $tokens)), 'seen' => $now];
        $by = [];
        foreach (array_diff($tokens, $was) as $t) {
            $by[watchmanTokenKind($t)][] = $t;
        }
        foreach ($by as $kind => $list) {
            $added[] = watchmanSet($book, $kind, "$kind:$name", $now,
                ['name' => (string) $name, 'image' => (string) ($c['image'] ?? ''), 'tokens' => array_values($list)]);
        }
    }
    foreach ($known as $name => $k) {
        if (!isset($seen[$name]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$name]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== plugins

/** Installed plugins (/var/log/plugins): name => source (host, on code hosts with the owner), a hash of the URL, version */
function watchmanPlugins(string $dir): array
{
    $out = [];
    foreach (glob("$dir/*.plg") ?: [] as $file) {
        $name = basename($file, '.plg');
        if (!preg_match('/^[A-Za-z0-9._+-]{1,100}$/D', $name)) {
            continue;
        }
        $head = (string) @file_get_contents($file, false, null, 0, 65536);
        [$url, $version] = watchmanPluginUrl($head);
        $out[$name] = ['source' => watchmanSource($url), 'url' => $url === null ? null : substr(hash('sha256', $url), 0, 16),
                       'version' => $version];
        if (count($out) >= 500) {
            break;
        }
    }
    ksort($out);
    return $out;
}

/**
 * The plugin's pluginURL (the attribute of <PLUGIN>, where the plugin
 * manager looks for updates) and version, its &entities; resolved from the
 * file's own <!ENTITY> lines — nothing else is fetched.
 * @return array{0: ?string, 1: ?string}
 */
function watchmanPluginUrl(string $xml): array
{
    $entities = [];
    if (preg_match_all('/<!ENTITY\s+([A-Za-z_][\w.-]*)\s+(?:"([^"]*)"|\'([^\']*)\')\s*>/', $xml, $m, PREG_SET_ORDER)) {
        foreach ($m as $e) {
            $entities[$e[1]] ??= ($e[2] ?? '') !== '' ? $e[2] : ($e[3] ?? '');
        }
    }
    if (!preg_match('/<PLUGIN\b([^>]*)>/i', $xml, $tag)) {
        return [null, null];
    }
    $attr = function (string $name) use ($tag, $entities): ?string {
        if (!preg_match('/\b' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag[1], $a)) {
            return null;
        }
        $v = ($a[1] ?? '') !== '' ? $a[1] : ($a[2] ?? '');
        for ($i = 0; $i < 10 && str_contains($v, '&'); $i++) {
            $v = (string) preg_replace_callback('/&([A-Za-z_][\w.-]*);/', fn ($x) => $entities[$x[1]] ?? $x[0], $v);
        }
        $v = trim($v);
        return $v === '' || strlen($v) > 2000 ? null : $v;
    };
    return [$attr('pluginURL'), $attr('version')];
}

/** Where a plugin comes from, in a few words: the host, and on code hosts the owner ("raw.githubusercontent.com/unraid") */
function watchmanSource(?string $url): string
{
    if ($url === null) {
        return '';
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!preg_match('/^[a-z0-9.-]{1,253}$/D', $host)) {
        return '';
    }
    if (in_array($host, WATCH_CODE_HOSTS, true)) {
        $owner = explode('/', ltrim((string) parse_url($url, PHP_URL_PATH), '/'))[0] ?? '';
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $owner)) {
            return "$host/$owner";
        }
    }
    return $host;
}

/** Plugins against what is normal: a new one, or one whose source moved; a new version from the same place is normal */
function watchmanPluginsCompare(array &$known, array $seen, array &$book, int $now): array
{
    $added = [];
    foreach ($seen as $name => $p) {
        $k = $known[$name] ?? null;
        if ($k === null) {
            $added[] = watchmanSet($book, 'plugin_new', "plugin_new:$name", $now,
                ['name' => (string) $name, 'source' => $p['source'], 'version' => $p['version']]);
            continue;
        }
        if (($k['source'] ?? '') !== $p['source']) {
            $known[$name]['seen'] = $now;
            $added[] = watchmanSet($book, 'plugin_source', "plugin_source:$name", $now,
                ['name' => (string) $name, 'source' => $p['source'], 'old' => (string) ($k['source'] ?? '')]);
            continue;
        }
        $known[$name] = $p + ['seen' => $now];
    }
    foreach ($known as $name => $k) {
        if (!isset($seen[$name]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$name]);       // removed: gone after a while (an update doesn't make it new)
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== the flash

/**
 * What matters on the flash: go (a hash, and one per line — never its text),
 * the files in /boot/extra, the users, a hash per user's password field
 * (never the field), the SSH keys (fingerprint, type, comment).
 */
function watchmanFlash(array $paths): array
{
    $go = null;
    $text = @file_get_contents($paths['go'], false, null, 0, 262144);
    if ($text !== false) {
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $l) {
            $l = trim($l);
            if ($l !== '') {
                $lines[substr(sha1($l), 0, 12)] = true;
            }
        }
        $go = ['hash' => substr(hash('sha256', $text), 0, 16), 'lines' => array_keys($lines)];
    }
    unset($text);

    $extra = [];
    foreach (@scandir($paths['extra']) ?: [] as $f) {
        if ($f === '.' || $f === '..' || count($extra) >= 500) {
            continue;
        }
        $st = @lstat($paths['extra'] . "/$f");
        if ($st && ($st['mode'] & 0170000) === 0100000) {
            $extra[watchmanClean($f, 120)] = ['size' => (int) $st['size'], 'time' => (int) $st['mtime']];
        }
    }
    ksort($extra);

    $users = [];
    foreach (@file($paths['passwd'], FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $n = strtok($l, ':');
        if (is_string($n) && preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}\$?$/D', $n) && count($users) < 500) {
            $users[] = $n;
        }
    }
    $users = array_values(array_unique($users));
    $pw = [];
    foreach (@file($paths['shadow'], FILE_IGNORE_NEW_LINES) ?: [] as $l) {
        $f = explode(':', $l, 3);
        if (count($f) >= 2 && in_array($f[0], $users, true)) {
            $pw[$f[0]] = substr(hash('sha256', 'uso-watchman:' . $f[1]), 0, 16);
        }
        $f = null;
    }
    ksort($pw);

    $keys = [];
    foreach (glob($paths['ssh'] . '/*/authorized_keys') ?: [] as $file) {
        $user = basename(dirname($file));
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}$/D', $user)) {
            continue;
        }
        foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $k = watchmanSshKey($l);
            if ($k && count($keys[$user] ?? []) < 100) {
                $keys[$user][$k['fp']] = ['type' => $k['type'], 'comment' => $k['comment']];
            }
        }
    }
    ksort($keys);
    return ['go' => $go, 'extra' => $extra, 'users' => $users, 'pw' => $pw, 'keys' => $keys];
}

/** One line of authorized_keys (options in front are fine): type, fingerprint as ssh-keygen -l shows it, comment */
function watchmanSshKey(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || $line[0] === '#'
        || !preg_match('/(?:^|\s)((?:ssh|ecdsa|sk)-[a-z0-9@.-]{1,60})\s+([A-Za-z0-9+\/]{20,}={0,3})(?:\s+(.*))?$/D', $line, $m)) {
        return null;
    }
    $blob = base64_decode($m[2], true);
    if ($blob === false) {
        return null;
    }
    return ['type' => $m[1], 'fp' => 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='),
            'comment' => watchmanClean((string) ($m[3] ?? ''), 60)];
}

/** Printable, single line, at most $max characters */
function watchmanClean(string $s, int $max): string
{
    $s = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    return mb_substr(mb_convert_encoding($s, 'UTF-8', 'UTF-8'), 0, $max);
}

/** The flash against what is normal: go changed, a file in /boot/extra new or changed, a new user, a changed password, a new SSH key */
function watchmanFlashCompare(array &$known, array $seen, array &$book, int $now): array
{
    $added = [];
    $kg = $known['go'] ?? null;
    $sg = $seen['go'];
    if (($kg['hash'] ?? null) !== ($sg['hash'] ?? null)) {
        $was = (array) ($kg['lines'] ?? []);
        $is = (array) ($sg['lines'] ?? []);
        $added[] = watchmanSet($book, 'flash_go', 'flash_go', $now,
            ['added' => count(array_diff($is, $was)), 'removed' => count(array_diff($was, $is)), 'lines' => count($is), 'gone' => $sg === null]);
    }

    foreach ($seen['extra'] as $file => $f) {
        $k = $known['extra'][$file] ?? null;
        if ($k === null || (int) $k['size'] !== $f['size'] || (int) $k['time'] !== $f['time']) {
            $added[] = watchmanSet($book, 'flash_extra', "flash_extra:$file", $now,
                ['file' => (string) $file, 'size' => $f['size'], 'time' => $f['time'], 'new' => $k === null]);
        }
    }
    foreach (array_keys((array) ($known['extra'] ?? [])) as $file) {
        if (!isset($seen['extra'][$file])) {
            unset($known['extra'][$file]);
        }
    }

    $knownUsers = (array) ($known['users'] ?? []);
    foreach ($seen['users'] as $u) {
        if (!in_array($u, $knownUsers, true)) {
            $added[] = watchmanSet($book, 'flash_user', "flash_user:$u", $now, ['user' => $u]);
        }
    }
    $known['users'] = array_values(array_intersect($knownUsers, $seen['users']));
    foreach ($known['users'] as $u) {
        if (($known['pw'][$u] ?? null) !== ($seen['pw'][$u] ?? null)) {
            $added[] = watchmanSet($book, 'flash_password', "flash_password:$u", $now, ['user' => $u, '_h' => $seen['pw'][$u] ?? null]);
        }
    }
    foreach (array_keys((array) ($known['pw'] ?? [])) as $u) {
        if (!in_array($u, $known['users'], true)) {
            unset($known['pw'][$u]);
        }
    }

    foreach ($seen['keys'] as $user => $keys) {
        foreach ($keys as $fp => $k) {
            if (!isset($known['keys'][$user][$fp])) {
                $added[] = watchmanSet($book, 'flash_ssh_key', "flash_ssh_key:$user:$fp", $now,
                    ['user' => (string) $user, 'fp' => (string) $fp, 'type' => $k['type'], 'comment' => $k['comment']]);
            }
        }
    }
    foreach ((array) ($known['keys'] ?? []) as $user => $keys) {
        foreach (array_keys((array) $keys) as $fp) {
            if (!isset($seen['keys'][$user][$fp])) {
                unset($known['keys'][$user][$fp]);          // a key removed: safer, normal
            }
        }
        if (empty($known['keys'][$user])) {
            unset($known['keys'][$user]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== shares

/**
 * How open every share is to guests, as Unraid applies it (emhttp's sec.ini
 * and sec_nfs.ini, RAM): 0 not, 1 they read (secure), 2 they read and write
 * (public) — only when exported and SMB/NFS is switched on. Null when emhttp
 * has no list (it always has one while it runs).
 */
function watchmanShares(array $paths): ?array
{
    $smb = readCfg($paths['sec'], true);
    if (!$smb) {
        return null;
    }
    $nfs = readCfg($paths['sec_nfs'], true);
    $g = readCfg($paths['share_cfg']);
    $smbOn = ($g['shareSMBEnabled'] ?? 'yes') !== 'no';
    $nfsOn = ($g['shareNFSEnabled'] ?? 'no') === 'yes';
    $level = fn (array $s): int => match ((string) ($s['security'] ?? 'public')) { 'public' => 2, 'secure' => 1, default => 0 };
    $out = [];
    foreach ($smb as $name => $s) {
        if (!is_array($s) || !preg_match('/^[^\x00-\x1F\/\\\\]{1,100}$/D', (string) $name) || count($out) >= 1000) {
            continue;
        }
        $n = is_array($nfs[$name] ?? null) ? $nfs[$name] : [];
        $out[$name] = ['smb' => $smbOn && str_starts_with((string) ($s['export'] ?? '-'), 'e') ? $level($s) : 0,
                       'nfs' => $nfsOn && ($n['export'] ?? '-') === 'e' ? $level($n) : 0];
    }
    ksort($out);
    return $out;
}

/** Shares against what is normal: more open to guests than before is an entry; less open is the new normal */
function watchmanSharesCompare(?array &$known, ?array $seen, array &$book, int $now): array
{
    if ($seen === null) {
        return [];
    }
    if ($known === null) {
        $known = array_map(fn ($s) => $s + ['seen' => $now], $seen);
        return [];
    }
    $added = [];
    foreach ($seen as $share => $s) {
        $k = $known[$share] ?? null;
        foreach (['smb', 'nfs'] as $proto) {
            $was = (int) ($k[$proto] ?? 0);
            $is = (int) $s[$proto];
            if ($is > $was) {
                $added[] = watchmanSet($book, 'share_public', "share_public:$share:$proto", $now,
                    ['share' => (string) $share, 'proto' => $proto, 'level' => $is === 2 ? 'public' : 'secure']);
            } elseif ($k !== null && $is < $was) {
                $known[$share][$proto] = $is;
            }
        }
        if ($k !== null) {
            $known[$share]['seen'] = $now;
        } elseif (!$s['smb'] && !$s['nfs']) {
            $known[$share] = $s + ['seen' => $now];      // a new share, closed to guests: normal
        }
    }
    foreach ($known as $share => $k) {
        if (!isset($seen[$share]) && $now - (int) ($k['seen'] ?? $now) > WATCH_FORGET) {
            unset($known[$share]);
        }
    }
    return array_values(array_filter($added));
}

// ===================================================================== scheduled and auto-starting

/*
 * What starts on its own as root — where an intruder settles in (MITRE ATT&CK T1053) and where a
 * plugin can quietly double the server's jobs. RAM and flash only; a file is read again only when
 * its size or time moved (the last round's look, seen.json).
 *
 *   crontabs  root's own crontab (/var/spool/cron/crontabs/root: what `crontab -l` and `crontab -`
 *             use) next to Unraid's /etc/cron.d/root (update_cron builds it from the .cron files
 *             below). Unraid's crond reads both: a line in both runs twice. Reported: new lines,
 *             lines that run twice, the office's own lines (they belong in its cron file only — an
 *             old copy starts a second backup at its old time), lines whose program went with its
 *             plugin. With the evidence: the file's time and what the syslog said around it (who
 *             wrote it). New lines of other users' crontabs and /etc/cron.d's other files count too.
 *             He never changes a crontab — the fix stands in the entry as information.
 *   .cron     the plugins' cron files on the flash (what survives a reboot): a new file or new
 *             lines; one in the folder of no installed plugin counts more (update_cron leaves it
 *             out — until a plugin of that name comes)
 *   scripts   User Scripts: a new script, its content or schedule changed
 *   at        jobs waiting in atd's queue that aren't the office's own (hostLaunch() marks those); a User
 *             Script started «in the background» (the User Scripts plugin goes through `at NOW`) is only a
 *             line in the book, noted by itself — when the job is exactly that and nothing else
 *   agents    Unraid's notification agents: every file there runs as root with each notification.
 *             New or changed; their content holds tokens — only a fingerprint is kept.
 */
const WATCH_CRON_OFFICE   = '/plugins/' . OFFICE_PLUGIN . '/scripts/job.sh';
const WATCH_CRON_SAVE     = '/boot/config/crontab-root-before-cleanup.txt';
const WATCH_SCHED_MAX     = 500;            // lines, files, scripts, jobs — each
// User Scripts' «Run in background» (backgroundScript.sh): echo <launcher> "/tmp/…/tmpScripts/<name>/script" | at NOW -M
const WATCH_US_LAUNCHER   = '/usr/local/emhttp/plugins/user.scripts/startBackground.php';
const WATCH_US_TMP        = '/tmp/user.scripts/tmpScripts/';
// what such a job's environment must not set (it would run something else than the script), and where PATH may point
const WATCH_AT_ENV_BAD    = '/^(?:LD_\w*|BASH_ENV|ENV|BASH_FUNC_.*|PHPRC|PHP_INI_SCAN_DIR|PHP_\w*|PERL5OPT|PERL5LIB|PERLLIB|PYTHON\w*|RUBYOPT|RUBYLIB|NODE_OPTIONS|GCONV_PATH|GLIBC_TUNABLES|IFS|PS4|SHELLOPTS|BASHOPTS|PROMPT_COMMAND|CDPATH|GLOBIGNORE)$/D';
const WATCH_AT_PATH       = ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin'];
const WATCH_AT_SHELLS     = ['/bin/sh', '/bin/bash', '/usr/bin/sh', '/usr/bin/bash'];
const WATCH_EVIDENCE_SPAN = 120;            // syslog lines this many seconds around the crontab's time …
const WATCH_EVIDENCE_MAX  = 12;             // … at most so many, the closest
const WATCH_EVIDENCE_LINE = '#/plugins/|\bplugins?\b|crontab|update_cron|crond|\batd\b|\batq\b|\.(?:sh|php|py|plg|cron)\b|user\.scripts|unraid-secretary-office#i';

/** A short fingerprint */
function watchmanHash(string $s): string
{
    return substr(sha1($s), 0, 12);
}

/** @return list<string> a crontab's job lines, whitespace normalised (no comments, empty lines, VAR=value) */
function watchmanCronJobs(string $text): array
{
    $jobs = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim((string) preg_replace('/\s+/', ' ', $line));
        if ($line !== '' && $line[0] !== '#' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s?=/', $line)) {
            $jobs[] = $line;
        }
    }
    return $jobs;
}

/** A job line's command: what follows the five time fields (or @daily & co.) */
function watchmanCronCommand(string $line): string
{
    return preg_match('/^(?:@\w+|\S+ \S+ \S+ \S+ \S+) (.+)$/', trim((string) preg_replace('/\s+/', ' ', $line)), $m) ? $m[1] : '';
}

/**
 * The program a command starts, when it is an absolute path: its first word — or, behind an
 * interpreter or a wrapper (bash, php, nice …), the script it runs. Null when it can't be told
 * (a command name, `sh -c …`).
 */
function watchmanCronProgram(string $command): ?string
{
    preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $command, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
    $runners = ['sh', 'bash', 'dash', 'php', 'php-cgi', 'python', 'python3', 'perl', 'nice', 'ionice', 'nohup', 'timeout', 'env', 'exec'];
    foreach ($m as $t) {
        $word = (string) ($t[1] ?? $t[2] ?? $t[3] ?? '');
        if ($word === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $word) || preg_match('/^\d+[smhd]?$/D', $word)) {
            continue;                       // VAR=value, timeout's seconds
        }
        if ($word === '-c' || $word === '-r') {
            return null;                    // inline code
        }
        if ($word[0] === '-' || in_array(basename($word), $runners, true)) {
            continue;                       // an interpreter and its options
        }
        return str_starts_with($word, '/') ? $word : null;
    }
    return null;
}

/** The plugin whose program is gone (it lay in a plugin's folder), else null. Never under /mnt (a disk would wake). */
function watchmanCronGone(string $path, ?callable $exists = null): ?string
{
    if (str_starts_with($path, '/mnt/') || str_contains($path, '/../') || !preg_match('#^[A-Za-z0-9_./+@-]{1,300}$#D', $path)
        || !preg_match('#^(?:/usr/local/emhttp/plugins|/boot/config/plugins)/([A-Za-z0-9._+-]{1,100})/#', $path, $p)) {
        return null;
    }
    return ($exists ?? 'file_exists')($path) ? null : $p[1];
}

/** Secrets out of a line that is shown: a URL's path and user, values of password/token/key settings, long tokens */
function watchmanScrub(string $s): string
{
    $s = (string) preg_replace('#\b([a-z][a-z0-9+.-]{1,15}://)(?:[^\s/@\'"]*@)?([^\s/\'"?\#]+)[^\s\'"]*#i', '$1$2/…', $s);
    $s = (string) preg_replace('/\b([\w.-]*(?:pass|pwd|token|secret|key|auth|api)[\w.-]*)(=|:\s*)("[^"]*"|\'[^\']*\'|\S+)/i', '$1$2…', $s);
    $s = (string) preg_replace('/(\s(?:-u|--user)[\s=]+)\S+|(\s-p)(?=\S*[^A-Za-z\s])\S+/', '$1$2…', $s);    // curl -u user:pw, mysql -pSecret
    return (string) preg_replace('/[A-Za-z0-9_+=-]{28,}/', '…', $s);
}

/** A job line, short and without secrets: its time fields, its command without redirections and without the plugins' folders in front */
function watchmanCronShort(string $line): string
{
    $line = trim((string) preg_replace('/\s+/', ' ', $line));
    $cmd = watchmanCronCommand($line);
    $when = $cmd === '' ? '' : trim(substr($line, 0, strlen($line) - strlen($cmd)));
    $cmd = $cmd === '' ? $line : $cmd;
    $cmd = (string) preg_replace('/\s*(?:\d?>>?|&>>?)\s*\S+|\s+\d>&\d|\s*\|\s*logger\b.*$/', '', $cmd);
    $cmd = str_replace(['/usr/local/emhttp/plugins/', '/boot/config/plugins/'], '', $cmd);
    return mb_strimwidth(watchmanClean(trim($when . ' ' . watchmanScrub($cmd)), 400), 0, 110, '…');
}

/** @return array{lines:int, jobs:list<string>, _h:list<string>} lines (hash => short) as an entry's words */
function watchmanJobs(array $lines): array
{
    return ['lines' => count($lines), 'jobs' => array_slice(array_values($lines), 0, WATCH_LIST_MAX), '_h' => array_keys($lines)];
}

/** What the office would type to undo it (never runs it): a copy of root's crontab to the flash first */
function watchmanCronFix(string $kind, string $path = ''): string
{
    $save = 'crontab -l > ' . WATCH_CRON_SAVE . '; crontab -l | ';
    return match ($kind) {
        'cron_twice'  => $save . "grep -v -x -F -f <(grep -v '^#' /etc/cron.d/root | grep -v '^\\s*$') | crontab -",
        'cron_office' => $save . "grep -v -F '" . WATCH_CRON_OFFICE . "' | crontab -",
        'cron_dead'   => $save . "grep -v -F '$path' | crontab -",
        default       => '',
    };
}

/**
 * Everything scheduled and auto-starting, as this round sees it; parts whose place isn't in $paths
 * are null. $prev: the last round's look (a file whose size and time are the same isn't read again).
 */
function watchmanSched(array $paths, array $prev, int $now, ?callable $exists = null): array
{
    $crontab = watchmanCrontabs($paths, $exists);
    if ($crontab !== null) {
        $crontab['evidence'] = watchmanEvidence($paths['syslog'], $crontab['mtime'], $prev['crontab']['evidence'] ?? null, $now);
    }
    return [
        'crontab' => $crontab,
        'files'   => watchmanCronFiles($paths, (array) ($prev['files'] ?? [])),
        'scripts' => watchmanUserScripts($paths, (array) ($prev['scripts'] ?? [])),
        'at'      => watchmanAtJobs($paths, is_array($prev['at'] ?? null) ? $prev['at'] : null),
        'agents'  => watchmanAgents($paths, (array) ($prev['agents'] ?? [])),
    ];
}

/** The same file as the last round saw (size, time and change time alike)? Then it isn't read again. */
function watchmanSameFile(?array $prev, array $st): bool
{
    return is_array($prev) && (int) ($prev['m'] ?? -1) === (int) $st['mtime'] && (int) ($prev['c'] ?? -1) === (int) $st['ctime']
        && (int) ($prev['s'] ?? -1) === (int) $st['size'] && is_string($prev['h'] ?? null);
}

/** Is it a plain file (no link, no folder)? */
function watchmanPlain(string $file): ?array
{
    $st = @lstat($file);
    return $st && ($st['mode'] & 0170000) === 0100000 ? $st : null;
}

/**
 * The crontabs crond reads besides Unraid's /etc/cron.d/root: root's own (and other users',
 * /etc/cron.d's other files — their lines named by where they are) — against that one.
 *
 * @return array{mtime: ?int, lines: array<string,string>, twice: array<string,string>, office: array<string,string>,
 *               dead: array<string, array{plugin: string, job: string}>}|null  lines by hash => short
 */
function watchmanCrontabs(array $paths, ?callable $exists = null): ?array
{
    if (!isset($paths['crontabs'], $paths['cron_d'])) {
        return null;
    }
    $system = array_flip(watchmanCronJobs((string) @file_get_contents($paths['cron_d'] . '/root', false, null, 0, 1 << 20)));
    $sources = [];
    foreach (glob($paths['crontabs'] . '/*') ?: [] as $f) {
        if (preg_match('/^[a-z_][a-z0-9_.-]{0,31}$/D', basename($f)) && basename($f) !== 'cron.update') {
            $sources[] = [basename($f) === 'root' ? '' : basename($f) . ': ', $f, basename($f) === 'root'];
        }
    }
    foreach (glob($paths['cron_d'] . '/*') ?: [] as $f) {
        if (basename($f) !== 'root') {
            $sources[] = ['cron.d/' . watchmanClean(basename($f), 60) . ': ', $f, false];
        }
    }
    $out = ['mtime' => null, 'lines' => [], 'twice' => [], 'office' => [], 'dead' => []];
    foreach ($sources as [$label, $file, $root]) {
        $st = watchmanPlain($file);
        if (!$st) {
            continue;
        }
        if ($root) {
            $out['mtime'] = (int) $st['mtime'];
        }
        foreach (watchmanCronJobs((string) @file_get_contents($file, false, null, 0, 1 << 20)) as $line) {
            if (count($out['lines']) >= WATCH_SCHED_MAX) {
                break 2;
            }
            $h = watchmanHash($label . $line);
            $short = $label . watchmanCronShort($line);
            $out['lines'][$h] = $short;
            if (!$root) {
                continue;
            }
            if (str_contains($line, WATCH_CRON_OFFICE)) {
                $out['office'][$h] = $short;
            } elseif (isset($system[$line])) {
                $out['twice'][$h] = $short;
            }
            $program = watchmanCronProgram(watchmanCronCommand($line));
            $plugin = $program === null ? null : watchmanCronGone($program, $exists);
            if ($plugin !== null) {
                $out['dead'][$program] = ['plugin' => $plugin, 'job' => $short];
            }
        }
    }
    return $out;
}

/**
 * Who wrote root's crontab: the syslog lines around its time that name a plugin, a script or cron.
 * Looked up again only when its time moved, or the last look was before the span after it ended.
 *
 * @return array{mtime: int, lines: list<string>, complete: bool}|null
 */
function watchmanEvidence(string $syslog, ?int $mtime, ?array $prev, int $now): ?array
{
    if ($mtime === null) {
        return null;
    }
    if (is_array($prev) && (int) ($prev['mtime'] ?? -1) === $mtime && !empty($prev['complete'])) {
        return $prev;
    }
    return ['mtime' => $mtime, 'lines' => watchmanSyslogAround($syslog, $mtime, $now), 'complete' => $now - $mtime > WATCH_EVIDENCE_SPAN + 30];
}

/**
 * Syslog lines within WATCH_EVIDENCE_SPAN of $t (syslog.1, then syslog; found by halving, read
 * from there at most 4 MB) that name a plugin, a script or cron — never a login line (a password may
 * stand in it). The closest WATCH_EVIDENCE_MAX, by time, each as "HH:MM:SS process: text", scrubbed.
 *
 * @return list<string>
 */
function watchmanSyslogAround(string $syslog, int $t, int $now): array
{
    $hits = [];
    foreach (["$syslog.1", $syslog] as $file) {
        $h = @fopen($file, 'r');
        if (!$h) {
            continue;
        }
        $time = function (int $at) use ($h, $now): ?int {       // the time of the first whole line from $at
            fseek($h, $at);
            if ($at > 0) {
                fgets($h);
            }
            for ($i = 0; $i < 5 && ($l = fgets($h)) !== false; $i++) {
                $lt = watchmanLineTime($l, $now);
                if ($lt !== null) {
                    return $lt;
                }
            }
            return null;
        };
        $lo = 0;
        $hi = (int) (fstat($h)['size'] ?? 0);
        while ($hi - $lo > 65536) {
            $mid = intdiv($lo + $hi, 2);
            $lt = $time($mid);
            if ($lt === null || $lt >= $t - WATCH_EVIDENCE_SPAN) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        fseek($h, $lo);
        if ($lo > 0) {
            fgets($h);
        }
        for ($read = 0; $read < 4 << 20 && ($line = fgets($h)) !== false; $read += strlen($line)) {
            $lt = watchmanLineTime($line, $now);
            if ($lt === null || $lt < $t - WATCH_EVIDENCE_SPAN) {
                continue;
            }
            if ($lt > $t + WATCH_EVIDENCE_SPAN) {
                break;
            }
            if (!preg_match(WATCH_EVIDENCE_LINE, $line) || preg_match(WATCH_WEB, $line) || preg_match('/\ssshd[\w-]*(?:\[\d+\])?:/', $line)) {
                continue;
            }
            $text = (string) preg_replace(['/^[A-Z][a-z]{2}\s+\d{1,2}\s+(\d\d:\d\d:\d\d)\s+\S+\s+/', '/^\d{4}-\d\d-\d\d[T ](\d\d:\d\d:\d\d)\S*\s+\S+\s+/'], '$1 ', rtrim($line));
            $hits[] = [abs($lt - $t), $lt, mb_strimwidth(watchmanClean(watchmanScrub($text), 400), 0, 220, '…')];
        }
        fclose($h);
    }
    usort($hits, fn ($a, $b) => $a[0] <=> $b[0]);
    $hits = array_slice($hits, 0, WATCH_EVIDENCE_MAX);
    usort($hits, fn ($a, $b) => $a[1] <=> $b[1]);
    return array_values(array_unique(array_column($hits, 2)));
}

/** The plugins' cron files on the flash: "<plugin>/<file>" => size, time, fingerprint, its lines (hash => short) */
function watchmanCronFiles(array $paths, array $prev): ?array
{
    if (!isset($paths['cron_files'])) {
        return null;
    }
    $out = [];
    foreach (glob($paths['cron_files'] . '/*/*.cron') ?: [] as $file) {
        $name = basename(dirname($file)) . '/' . basename($file);
        $st = watchmanPlain($file);
        if (!$st || count($out) >= WATCH_SCHED_MAX || !preg_match('#^[A-Za-z0-9._+-]{1,100}/[^/\x00-\x1F]{1,120}$#D', $name)) {
            continue;
        }
        $p = $prev[$name] ?? null;
        if (watchmanSameFile($p, $st) && is_array($p['lines'] ?? null)) {
            $out[$name] = $p;
            continue;
        }
        $text = (string) @file_get_contents($file, false, null, 0, 1 << 20);
        $lines = [];
        foreach (array_slice(watchmanCronJobs($text), 0, WATCH_SCHED_MAX) as $l) {
            $lines[watchmanHash($l)] = watchmanCronShort($l);
        }
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => watchmanHash($text), 'lines' => $lines];
    }
    ksort($out);
    return $out;
}

/** User Scripts: name => size, time, fingerprint of its script, its schedule (a cron line, or User Scripts' word: daily, start …) */
function watchmanUserScripts(array $paths, array $prev): ?array
{
    if (!isset($paths['userscripts'])) {
        return null;
    }
    $dir = $paths['userscripts'];
    $schedule = [];
    foreach ((array) json_decode((string) @file_get_contents("$dir/schedule.json", false, null, 0, 1 << 20), true) as $key => $s) {
        if (is_string($key) && is_array($s)) {
            $f = (string) ($s['frequency'] ?? 'disabled');
            $schedule[basename(dirname($key))] = $f === 'custom' ? trim((string) ($s['custom'] ?? '')) : $f;
        }
    }
    $out = [];
    foreach (glob("$dir/scripts/*/script") ?: [] as $file) {
        $folder = basename(dirname($file));
        $name = watchmanClean($folder, 100);
        $st = watchmanPlain($file);
        if (!$st || count($out) >= WATCH_SCHED_MAX || $name === '') {
            continue;
        }
        $cron = watchmanClean($schedule[$folder] ?? 'disabled', 60) ?: 'disabled';
        $p = $prev[$name] ?? null;
        $h = watchmanSameFile($p, $st) ? $p['h'] : watchmanHash((string) @file_get_contents($file, false, null, 0, 4 << 20));
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => $h, 'cron' => $cron];
    }
    ksort($out);
    return $out;
}

/**
 * atd's queue: job file => whether it is the office's own (hostLaunch() marks it), when it is due,
 * whose (uid), what it runs (its first command, without the environment at puts in front: that may
 * hold secrets). Read again only when the folder changed.
 */
function watchmanAtJobs(array $paths, ?array $prev): ?array
{
    if (!isset($paths['atjobs'])) {
        return null;
    }
    $st = @stat($paths['atjobs']);
    if (!$st) {
        return ['m' => null, 'jobs' => []];
    }
    if (is_array($prev) && ($prev['m'] ?? null) === (int) $st['mtime'] && is_array($prev['jobs'] ?? null)) {
        return $prev;
    }
    $jobs = [];
    foreach (@scandir($paths['atjobs']) ?: [] as $f) {
        if (!preg_match('/^[a-zA-Z=][0-9a-f]{5}([0-9a-f]{8})$/D', $f, $m) || count($jobs) >= 200 || !watchmanPlain($paths['atjobs'] . "/$f")) {
            continue;
        }
        $text = (string) @file_get_contents($paths['atjobs'] . "/$f", false, null, 0, 65536);
        $ours = str_contains($text, "\n" . HOST_LAUNCH_MARK . "\n");
        $jobs[$f] = ['ours' => $ours, 'when' => hexdec($m[1]) * 60, 'uid' => preg_match('/^# atrun uid=(\d+)/m', $text, $u) ? (int) $u[1] : null,
                     'cmd' => $ours ? '' : watchmanAtCommand($text), 'us' => $ours ? null : watchmanAtUserScript($text, $paths['userscripts'] ?? null)];
    }
    ksort($jobs);
    return ['m' => (int) $st['mtime'], 'jobs' => $jobs];
}

/**
 * The User Script an at job runs «in the background» (User Scripts' backgroundScript.sh pipes
 * "startBackground.php /tmp/user.scripts/tmpScripts/<name>/script" into `at NOW`), or null. Only when
 * that is all the job does — one command, exactly that, for a script that exists in $dir/scripts/<name>/
 * (a name with no character the shell would act on) — and its environment can't make it run something
 * else (no LD_PRELOAD and the like, PATH and SHELL only the system's: at runs the commands with $SHELL).
 */
function watchmanAtUserScript(string $text, ?string $dir): ?string
{
    if ($dir === null) {
        return null;
    }
    $lines = explode("\n", rtrim($text, "\n"));
    $n = count($lines);
    // the head at writes: #!/bin/sh, # atrun …, # mail …, umask, the environment as NAME=value; export NAME
    for ($i = 0; $i < $n && !preg_match('/^cd\s.*\|\|\s*\{\s*$/', $lines[$i]); $i++) {
        $l = $lines[$i];
        if ($l === '' || $l[0] === '#' || preg_match('/^umask [0-7]+$/D', $l)) {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*); export \1$/D', $l, $m) || preg_match(WATCH_AT_ENV_BAD, $m[1])) {
            return null;            // anything else (a value over several lines too)
        }
        $value = stripslashes($m[2]);
        if (($m[1] === 'PATH' && array_diff(explode(':', $value), WATCH_AT_PATH)) || ($m[1] === 'SHELL' && !in_array($value, WATCH_AT_SHELLS, true))) {
            return null;
        }
    }
    for ($i++; $i < $n && trim($lines[$i]) !== '}'; $i++) {
        // the "cd … || { echo …; exit 1; }" at puts in front
    }
    $cmds = [];
    $delim = null;
    for ($i++; $i < $n; $i++) {
        $l = $lines[$i];
        if ($delim === null && !$cmds && preg_match("/^\\$\\{SHELL:-\\/bin\\/sh\\} << '(marcinDELIMITER[0-9a-f]+)'$/D", $l, $m)) {
            $delim = $m[1];
        } elseif ($delim !== null && $l === $delim) {
            $delim = '';
        } elseif (trim($l) !== '' && ltrim($l)[0] !== '#') {
            $cmds[] = $l;
        }
    }
    $head = WATCH_US_LAUNCHER . ' ' . WATCH_US_TMP;
    if (count($cmds) !== 1 || !str_starts_with($cmds[0], $head) || !str_ends_with($cmds[0], '/script')) {
        return null;
    }
    $name = substr($cmds[0], strlen($head), -strlen('/script'));
    if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 200 || !mb_check_encoding($name, 'UTF-8')
        || preg_match('/[\x00-\x1F\x7F\/;&|`$<>()\\\\\'"*?\[\]{}~#!]/', $name) || !is_file("$dir/scripts/$name/script")) {
        return null;
    }
    return $name;
}

/** An at job's first command: after the "cd … || { … }" at puts in front (never the environment above it) */
function watchmanAtCommand(string $text): string
{
    $lines = explode("\n", $text);
    $start = null;
    foreach ($lines as $i => $l) {
        if (preg_match('/^cd\s.*\|\|\s*\{\s*$/', $l)) {
            $start = $i;
        } elseif ($start !== null && trim($l) === '}') {
            $start = $i + 1;
            break;
        }
    }
    foreach (array_slice($lines, $start ?? count($lines)) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || preg_match('/^\$\{SHELL[^}]*\}\s*<</', $l)) {
            continue;
        }
        return mb_strimwidth(watchmanClean(watchmanScrub($l), 400), 0, 110, '…');
    }
    return '';
}

/** Unraid's notification agents (every file in their folder runs): name => size, time, a fingerprint (never the content: tokens) */
function watchmanAgents(array $paths, array $prev): ?array
{
    if (!isset($paths['agents'])) {
        return null;
    }
    $out = [];
    foreach (@scandir($paths['agents']) ?: [] as $f) {
        $file = $paths['agents'] . "/$f";
        $st = @lstat($file);
        if ($f === '.' || $f === '..' || !$st || count($out) >= 100) {
            continue;
        }
        $name = watchmanClean($f, 80);
        $p = $prev[$name] ?? null;
        if (watchmanSameFile($p, $st)) {
            $out[$name] = $p;
            continue;
        }
        $what = is_link($file) ? 'link:' . (string) @readlink($file) : (string) @file_get_contents($file, false, null, 0, 1 << 20);
        $out[$name] = ['m' => (int) $st['mtime'], 'c' => (int) $st['ctime'], 's' => (int) $st['size'], 'h' => substr(hash('sha256', 'uso-watchman:' . $what), 0, 16)];
    }
    ksort($out);
    return $out;
}

/**
 * Scheduled and auto-starting things against what is normal ($known: the baseline's part; a part
 * it doesn't have yet is learned as it is now). What went is the new normal (back again, it is
 * told again); $installed: the installed plugins (a .cron of none of them counts more).
 *
 * @return list<string>  the kinds of new entries
 */
function watchmanSchedCompare(?array &$known, ?array $seen, array $installed, array &$book, int $now): array
{
    if ($seen === null) {
        return [];
    }
    $known = is_array($known) ? $known : [];
    $added = [];

    $c = $seen['crontab'] ?? null;
    if (is_array($c)) {
        $k = $known['crontab'] ?? null;
        if (!is_array($k)) {
            // jobs that run twice and stray copies of the office's own lines are never "normal": not
            // learned here, so the next round reports them even when they were there before he came
            $known['crontab'] = ['lines' => $c['lines'], 'twice' => [], 'office' => [], 'dead' => array_map(fn ($d) => $d['plugin'], $c['dead'])];
        } else {
            $k += ['lines' => [], 'twice' => [], 'office' => [], 'dead' => []];
            $ev = ['mtime' => $c['mtime'], 'evidence' => (array) ($c['evidence']['lines'] ?? [])];
            $lists = [
                'cron_office' => array_diff_key($c['office'], (array) $k['office']),
                'cron_twice'  => array_diff_key($c['twice'], (array) $k['twice']),
                'cron_new'    => array_diff_key($c['lines'], $c['twice'], $c['office'], (array) $k['lines']),
            ];
            foreach ($lists as $kind => $list) {
                if ($list) {
                    $added[] = watchmanSet($book, $kind, $kind, $now, watchmanJobs($list) + $ev);
                }
            }
            foreach ($c['dead'] as $path => $d) {
                if (!isset($k['dead'][$path])) {
                    $added[] = watchmanSet($book, 'cron_dead', "cron_dead:$path", $now, ['path' => (string) $path, 'plugin' => $d['plugin'], 'job' => $d['job']]);
                }
            }
            $known['crontab'] = ['lines' => array_intersect_key((array) $k['lines'], $c['lines']), 'twice' => array_intersect_key((array) $k['twice'], $c['twice']),
                                 'office' => array_intersect_key((array) $k['office'], $c['office']), 'dead' => array_intersect_key((array) $k['dead'], $c['dead'])];
        }
    }

    $files = $seen['files'] ?? null;
    if (is_array($files)) {
        if (!is_array($known['files'] ?? null)) {
            $known['files'] = array_map(fn ($f) => ['h' => $f['h'], 'lines' => $f['lines']], $files);
        } else {
            foreach ($files as $name => $f) {
                $k = $known['files'][$name] ?? null;
                if ($k !== null && $k['h'] === $f['h']) {
                    continue;
                }
                $new = $k === null ? $f['lines'] : array_diff_key($f['lines'], (array) $k['lines']);
                if (!$new) {
                    $known['files'][$name] = ['h' => $f['h'], 'lines' => $f['lines']];      // lines gone, or no lines at all: normal
                    continue;
                }
                $plugin = (string) strtok((string) $name, '/');
                $kind = $plugin === 'dynamix' || isset($installed[$plugin]) ? 'cron_file' : 'cron_file_foreign';
                $added[] = watchmanSet($book, $kind, "$kind:$name", $now,
                    ['file' => (string) $name, 'plugin' => $plugin, 'new' => $k === null, 'office' => $plugin === OFFICE_PLUGIN] + watchmanJobs($new) + ['_f' => $f['h']]);
            }
            $known['files'] = array_intersect_key($known['files'], $files);
        }
    }

    $scripts = $seen['scripts'] ?? null;
    if (is_array($scripts)) {
        if (!is_array($known['scripts'] ?? null)) {
            $known['scripts'] = array_map(fn ($s) => ['h' => $s['h'], 'cron' => $s['cron']], $scripts);
        } else {
            foreach ($scripts as $name => $s) {
                $k = $known['scripts'][$name] ?? null;
                if ($k === null) {
                    $added[] = watchmanSet($book, 'script_new', "script_new:$name", $now, ['name' => (string) $name, 'cron' => $s['cron'], '_f' => $s['h']]);
                    continue;
                }
                $content = $k['h'] !== $s['h'];
                if (!$content && $k['cron'] === $s['cron']) {
                    continue;
                }
                if (!$content && $s['cron'] === 'disabled') {
                    $known['scripts'][$name]['cron'] = 'disabled';      // switched off: safer, normal
                    continue;
                }
                $added[] = watchmanSet($book, 'script_changed', "script_changed:$name", $now,
                    ['name' => (string) $name, 'content' => $content, 'cron' => $s['cron'], 'old' => (string) $k['cron'], '_f' => $s['h']]);
            }
            $known['scripts'] = array_intersect_key($known['scripts'], $scripts);
        }
    }

    $at = $seen['at'] ?? null;
    if (is_array($at)) {
        $foreign = array_filter((array) $at['jobs'], fn ($j) => empty($j['ours']));
        foreach ($foreign as $f => $j) {
            if (is_string($j['us'] ?? null) && $j['us'] !== '') {
                unset($foreign[$f]);
                watchmanAtUserScriptNote($book, (string) $f, $j, $now);      // nothing to tell: a line in the book, noted
            }
        }
        if (!is_array($known['at'] ?? null)) {
            $known['at'] = array_fill_keys(array_keys($foreign), true);
        } else {
            foreach ($foreign as $f => $j) {
                if (!isset($known['at'][$f])) {
                    $added[] = watchmanSet($book, 'at_job', "at_job:$f", $now, ['job' => (string) $f, 'when' => (int) $j['when'], 'cmd' => (string) $j['cmd'], 'uid' => $j['uid']]);
                }
            }
            $known['at'] = array_intersect_key($known['at'], $foreign);
        }
    }

    $agents = $seen['agents'] ?? null;
    if (is_array($agents)) {
        if (!is_array($known['agents'] ?? null)) {
            $known['agents'] = array_map(fn ($a) => $a['h'], $agents);
        } else {
            foreach ($agents as $name => $a) {
                $k = $known['agents'][$name] ?? null;
                if ($k !== $a['h']) {
                    $added[] = watchmanSet($book, 'notify_agent', "notify_agent:$name", $now, ['name' => (string) $name, 'new' => $k === null, '_f' => $a['h']]);
                }
            }
            $known['agents'] = array_intersect_key($known['agents'], $agents);
        }
    }
    return array_values(array_filter($added));
}

/**
 * A User Script started «in the background»: a line in the book, noted by itself (never open, never
 * told, nothing for the team lead) — once per job, which keeps its number while atd runs it
 * (a<number> waiting, =<number> running).
 */
function watchmanAtUserScriptNote(array &$book, string $job, array $j, int $now): void
{
    $key = 'at_userscript:' . substr($job, 1);
    foreach ($book as $e) {
        if (($e['key'] ?? '') === $key) {
            return;
        }
    }
    $e = watchmanEntry('at_userscript', $key, $now, ['job' => $job, 'name' => watchmanClean((string) $j['us'], 100), 'when' => (int) ($j['when'] ?? 0),
                                                     'uid' => $j['uid'] ?? null]);
    $e['noted'] = $now;
    $e['by'] = 'auto';
    $book[] = $e;
}

/** «I know, thanks» on one of these kinds: what it names, as the last round saw it, becomes normal */
function watchmanSchedAdopt(array &$b, string $kind, array $p, array $seen): void
{
    $s = (array) ($seen['sched'] ?? []);
    $b['sched'] = is_array($b['sched'] ?? null) ? $b['sched'] : [];
    $part = fn (string $k, array $empty) => is_array($b['sched'][$k] ?? null) ? $b['sched'][$k] : $empty;
    $h = array_flip(array_map('strval', (array) ($p['_h'] ?? [])));
    switch ($kind) {
        case 'cron_new':
        case 'cron_twice':
        case 'cron_office':
            $field = ['cron_new' => 'lines', 'cron_twice' => 'twice', 'cron_office' => 'office'][$kind];
            $c = $part('crontab', ['lines' => [], 'twice' => [], 'office' => [], 'dead' => []]);
            $c[$field] = (array) ($c[$field] ?? []) + array_intersect_key((array) ($s['crontab'][$field] ?? []), $h);
            $b['sched']['crontab'] = $c;
            break;
        case 'cron_dead':
            $path = (string) ($p['path'] ?? '');
            if (isset($s['crontab']['dead'][$path])) {
                $c = $part('crontab', ['lines' => [], 'twice' => [], 'office' => [], 'dead' => []]);
                $c['dead'][$path] = (string) ($p['plugin'] ?? '');
                $b['sched']['crontab'] = $c;
            }
            break;
        case 'cron_file':
        case 'cron_file_foreign':
            $f = $s['files'][$p['file'] ?? ''] ?? null;
            if (is_array($f)) {
                $b['sched']['files'] = $part('files', []);
                $b['sched']['files'][(string) $p['file']] = ['h' => $f['h'], 'lines' => $f['lines']];
            }
            break;
        case 'script_new':
        case 'script_changed':
            $x = $s['scripts'][$p['name'] ?? ''] ?? null;
            if (is_array($x)) {
                $b['sched']['scripts'] = $part('scripts', []);
                $b['sched']['scripts'][(string) $p['name']] = ['h' => $x['h'], 'cron' => $x['cron']];
            }
            break;
        case 'at_job':
            if (isset($s['at']['jobs'][$p['job'] ?? ''])) {
                $b['sched']['at'] = $part('at', []);
                $b['sched']['at'][(string) $p['job']] = true;
            }
            break;
        case 'notify_agent':
            $a = $s['agents'][$p['name'] ?? ''] ?? null;
            if (is_array($a)) {
                $b['sched']['agents'] = $part('agents', []);
                $b['sched']['agents'][(string) $p['name']] = $a['h'];
            }
            break;
    }
}

// ===================================================================== data flow

/*
 * Who pulls how much — read only, cheap, from what the kernel and Samba already count; nothing is
 * switched on for it (no Samba auditing, no conntrack accounting) and no disk wakes up.
 *
 *   clients     the established TCP connections of the server's file services (ss -tin: SMB 445/139,
 *               NFS 2049, SSH with SFTP/scp/rsync, the WebGUI with its File Manager — the ports from
 *               var.ini): the bytes each connection delivered (tcp_info bytes_acked, else bytes_sent),
 *               diffed against the same connection in the last round, summed per client address and
 *               service. A connection that opened and closed between two rounds is lost.
 *   SMB         smbstatus -b: a new user, a new machine, a session started at an hour of the week
 *               that machine never used (after its learning time)
 *   containers  per container what its network namespace sent (/proc/<pid>/net/dev, every kind of
 *               network: bridge, macvlan, ipvlan); containers on the host's network can't be told
 *               apart from the server itself; containers sharing one namespace count once
 *   shares      per share of an awake ZFS pool (or ZFS array disk) the bytes written (ZFS `written`
 *               of its datasets: since their latest snapshot — rewritten files count, which is what
 *               encrypting ransomware does; without a snapshot only growth shows)
 *
 * Learned per client/service, container and share: hourly sums for WATCH_FLOW_KEEP; unusual is an
 * hour far above (WATCH_FLOW_FACTOR) what it was at that time of the week (±1 h) or a quarter of its
 * busiest hour, and at least WATCH_FLOW_MIN — only after WATCH_FLOW_LEARN; before that only very clear
 * cases (one round over WATCH_FLOW_NEW, a share's written over WATCH_FLOW_PART of its size). «I know,
 * thanks» raises that one's normal (`ack`). While the engine's lock is held (a backup, a check, a
 * restore) the office's own work is kept apart (`o`), never learned, never told: the Kopia container's
 * traffic, what is written into the backup place's share (packages, dumps), during a restore all that
 * is written. What its snapshots do to `written` is no write at all (a new one: counted from there; one
 * deleted: that round is left out). Media servers stream — that is their job: learned, shown, never told.
 */
const WATCH_FLOW_LEARN    = 7 * 86400;          // learning time per client, container, share
const WATCH_FLOW_KEEP     = 14 * 86400;         // hourly sums kept this long
const WATCH_FLOW_FACTOR   = 4;                  // unusual: more than this many times the normal at this time of the week …
const WATCH_FLOW_MIN      = 2 * 1024 ** 3;      // … and at least this much in the hour (2 GB)
const WATCH_FLOW_NEW      = 50 * 1024 ** 3;     // still learning: one round over this (50 GB) …
const WATCH_FLOW_PART     = 0.2;                // … or into a share over this part of its size,
const WATCH_FLOW_WRITE    = 1024 ** 3;          //     at least this much (1 GB)
const WATCH_FLOW_TINY     = 1024 ** 2;          // a past hour under this (1 MB) isn't kept
const WATCH_FLOW_GOING    = 2 * WATCH_EVERY + 120;   // rounds this close: the same pull going on
const WATCH_FLOW_STALE    = 1800;               // counters older than this: start counting anew
const WATCH_FLOW_CLIENTS  = 64;                 // client/service pairs followed
const WATCH_FLOW_CTS      = 100;                // containers followed
const WATCH_FLOW_SHARES   = 200;                // shares followed
const WATCH_FLOW_CONNS    = 5000;               // connections whose counters are kept until the next round (RAM)
const WATCH_FLOW_DATASETS = 5000;               // ZFS datasets likewise
const WATCH_FLOW_SMB_MAX  = 200;                // SMB users and machines known
const WATCH_FLOW_TOP      = 8;                  // shares in the metrics
const WATCH_FLOW_MEDIA    = '/(?:^|[\/_.:-])(?:emby|jellyfin|plex)/i';
const WATCH_FLOW_OFFICE   = ['backup', 'check', 'dryrun', 'restore'];     // holders of the engine's lock whose traffic is the office's own
const WATCH_FLOW_SERVICES = ['smb' => 'SMB', 'nfs' => 'NFS', 'ssh' => 'SSH', 'web' => 'WebGUI'];

/** The counters of the last round (RAM: they mean nothing after a reboot), per data folder */
function watchmanFlowCountersFile(string $dir): string
{
    @mkdir(RUN_DIR, 0700, true);
    return RUN_DIR . '/watchman-flow-' . substr(md5($dir), 0, 8) . '.json';
}

/** The last round's counters, or null (none, or too old to diff against) */
function watchmanFlowCounters(string $dir, int $now): ?array
{
    $c = readJson(watchmanFlowCountersFile($dir));
    $t = (int) ($c['time'] ?? 0);
    return $c !== null && $t > 0 && $t < $now && $now - $t <= WATCH_FLOW_STALE ? $c : null;
}

/** The ports of the file services: SMB, NFS, SSH and the WebGUI as var.ini has them */
function watchmanFlowPorts(array $var): array
{
    $ports = [445 => 'smb', 139 => 'smb', 2049 => 'nfs'];
    foreach (['PORTSSH' => ['ssh', 22], 'PORT' => ['web', 80], 'PORTSSL' => ['web', 443]] as $key => [$svc, $default]) {
        $p = (int) ($var[$key] ?? $default);
        $ports[$p >= 1 && $p <= 65535 ? $p : $default] ??= $svc;
    }
    ksort($ports);
    return $ports;
}

/**
 * What the data flow sees now (outside the book's lock): the connections, SMB's sessions, every
 * running container's sent bytes, the awake ZFS shares' written bytes, who holds the engine's lock.
 * A part that can't be looked at is null; the page says so.
 */
function watchmanFlowLook(array $paths, ?array $containers): array
{
    $var = readCfg($paths['var_ini']);
    $ports = watchmanFlowPorts($var);
    $conns = null;
    if (bin('ss') !== null) {
        $filter = '( ' . implode(' or ', array_map(fn ($p) => "sport = :$p", array_keys($ports))) . ' )';
        [$exit, $out] = hostNet(['ss', '-tinH', 'state', 'established', $filter], 20);
        $conns = $exit === 0 ? watchmanSsParse($out, $ports) : null;
    }
    $smb = null;
    if (($var['shareSMBEnabled'] ?? 'yes') === 'no') {
        $smb = ['on' => false, 'sessions' => []];
    } elseif (bin('smbstatus') !== null) {
        [, $out] = run(['smbstatus', '-b', '--json'], 20);
        $sessions = watchmanSmbParse($out);
        if ($sessions === null) {
            [, $out] = run(['smbstatus', '-b'], 20);      // a Samba without --json
            $sessions = watchmanSmbParse($out);
        }
        $smb = $sessions === null ? null : ['on' => true, 'sessions' => $sessions];
    }
    $cts = null;
    if (is_array($containers)) {
        $host = (string) @readlink('/proc/1/ns/net');
        $cts = [];
        foreach ($containers as $name => $c) {
            $pid = (int) ($c['pid'] ?? 0);
            if ($pid <= 1) {
                continue;               // not running
            }
            $ns = (string) @readlink("/proc/$pid/ns/net");
            $tx = watchmanNetDevTx((string) @file_get_contents("/proc/$pid/net/dev"));
            if ($ns === '' || $tx === null) {
                continue;
            }
            $cts[(string) $name] = ['pid' => $pid, 'ns' => $ns, 'tx' => $tx, 'host' => $host !== '' && $ns === $host, 'image' => (string) ($c['image'] ?? '')];
        }
    }
    $holder = function_exists('backupLockHolder') ? backupLockHolder() : null;
    $settings = function_exists('backupReadSettings') ? backupReadSettings(BACKUP_DATA_DIR . '/settings.ini') : [];
    $kopia = (string) backupSetting($settings, 'kopia', 'container', '');
    $place = (string) backupSetting($settings, 'general', 'dumps_share', '');
    return ['conns' => $conns, 'smb' => $smb, 'containers' => $cts, 'zfs' => watchmanFlowZfs($paths), 'nfs' => ($var['shareNFSEnabled'] ?? 'no') === 'yes',
            'holder' => $holder['holder'] ?? null, 'kopia' => $kopia !== '' ? $kopia : null,
            'office_shares' => array_values(array_unique(array_filter([BACKUP_OFFICE_SHARE, $place])))];
}

/**
 * ZFS `written`, `used` and `snapshots_changed` of every dataset of the awake ZFS pools and ZFS array
 * disks (disks.ini: a pool sleeps when any of its disks does — those are never asked). Null: no ZFS here.
 * @return array{datasets: array<string, array{w:int, u:int, s:?int}>, pools: list<string>, asleep: list<string>}|null
 */
function watchmanFlowZfs(array $paths): ?array
{
    if (bin('zfs') === null) {
        return null;
    }
    $disks = readCfg($paths['disks_ini'], true);
    $sleep = [];
    foreach ($disks as $section => $v) {
        $sleep[(string) ($v['name'] ?? $section)] = ($v['spundown'] ?? '0') === '1';
    }
    $pools = $asleep = [];
    foreach ($disks as $section => $v) {
        $name = (string) ($v['name'] ?? $section);
        if (!str_contains(strtolower((string) ($v['fsType'] ?? '')), 'zfs') || in_array($v['type'] ?? '', ['Boot', 'Flash'], true)
            || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $name)) {
            continue;
        }
        if (baseAsleep($name, $sleep)) {
            $asleep[] = $name;
        } else {
            $pools[] = $name;
        }
    }
    if (!$pools) {
        return ['datasets' => [], 'pools' => [], 'asleep' => $asleep];
    }
    $cmd = ['zfs', 'get', '-H', '-p', '-o', 'name,property,value', '-t', 'filesystem,volume', '-r', 'written,used,snapshots_changed', ...$pools];
    [$exit, $out] = run($cmd, 60);
    if ($exit !== 0 && !str_contains($out, "\twritten\t")) {
        $cmd[10] = 'written,used';          // an older ZFS without snapshots_changed
        [, $out] = run($cmd, 60);
    }
    return ['datasets' => watchmanZfsParse($out), 'pools' => $pools, 'asleep' => $asleep];
}

/** "[::ffff:192.0.2.7]:445", "192.0.2.7:445", "[2001:db8::7%br0]:445" → [address, port], or null */
function watchmanAddrPort(string $s): ?array
{
    $at = strrpos($s, ':');
    if ($at === false || !ctype_digit(substr($s, $at + 1))) {
        return null;
    }
    $ip = watchmanIp(substr($s, 0, $at));
    $port = (int) substr($s, $at + 1);
    return $ip === null || $port < 1 || $port > 65535 ? null : [$ip, $port];
}

/**
 * ss -tinH state established '( sport = :445 or … )': per connection the server's address and port,
 * the client's, its service and what the server sent (bytes_acked — what the client got, without
 * retransmissions — else bytes_sent) and received.
 * @return list<array{local:string, lport:int, peer:string, pport:int, service:string, sent:int, rcvd:int}>
 */
function watchmanSsParse(string $text, array $ports): array
{
    $out = [];
    $cur = null;
    foreach (explode("\n", $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        if ($line[0] !== ' ' && $line[0] !== "\t") {
            $cur = null;
            if (count($out) < WATCH_FLOW_CONNS && preg_match('/^(?:[A-Z][A-Z-]*\s+)?\d+\s+\d+\s+(\S+)\s+(\S+)/', $line, $m)) {
                $l = watchmanAddrPort($m[1]);
                $p = watchmanAddrPort($m[2]);
                if ($l !== null && $p !== null && isset($ports[$l[1]])) {
                    $cur = count($out);
                    $out[] = ['local' => $l[0], 'lport' => $l[1], 'peer' => $p[0], 'pport' => $p[1], 'service' => $ports[$l[1]], 'sent' => 0, 'rcvd' => 0, '_a' => null];
                }
            }
        }
        if ($cur === null) {
            continue;
        }
        foreach (['bytes_acked' => '_a', 'bytes_sent' => 'sent', 'bytes_received' => 'rcvd'] as $field => $k) {
            if (preg_match('/\b' . $field . ':(\d+)/', $line, $m)) {
                $out[$cur][$k] = (int) $m[1];
            }
        }
    }
    foreach ($out as $i => $c) {
        if ($c['_a'] !== null) {
            $out[$i]['sent'] = $c['_a'];
        }
        unset($out[$i]['_a']);
    }
    return $out;
}

/**
 * smbstatus -b --json (Samba 4.16+), or the plain table of an older one: per session its id, user,
 * the client's address, its machine name (what Samba knows), when it started (null: not known).
 * Null when it is neither.
 * @return list<array{id:string, user:string, ip:string, machine:string, start:?int}>|null
 */
function watchmanSmbParse(string $text): ?array
{
    $clean = fn (mixed $s, int $max) => watchmanClean(is_string($s) ? $s : '', $max);
    $j = json_decode($text, true);
    if (is_array($j) && array_key_exists('sessions', $j)) {
        $out = [];
        foreach ((array) $j['sessions'] as $id => $s) {
            if (!is_array($s) || count($out) >= 1000) {
                continue;
            }
            $ip = preg_match('/^ipv[46]:(.+):\d+$/D', (string) ($s['hostname'] ?? ''), $m) ? watchmanIp($m[1]) : watchmanIp((string) ($s['remote_machine'] ?? ''));
            if ($ip === null) {
                continue;
            }
            $start = is_string($s['creation_time'] ?? null) ? strtotime($s['creation_time']) : false;
            $out[] = ['id' => $clean((string) ($s['session_id'] ?? $id), 40), 'user' => $clean($s['username'] ?? '', 64) ?: '?', 'ip' => $ip,
                      'machine' => $clean($s['remote_machine'] ?? '', 64), 'start' => $start === false ? null : $start];
        }
        return $out;
    }
    if (!preg_match('/^PID\s+Username\s+Group\s+Machine/m', $text)) {
        return null;
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (count($out) < 1000 && preg_match('/^\s*(\d+)\s+(\S+)\s+(\S+)\s+(.+?)\s+\(ipv[46]:(.+):\d+\)/', $line, $m) && ($ip = watchmanIp($m[5])) !== null) {
            $out[] = ['id' => 'pid' . $m[1], 'user' => $clean($m[2], 64), 'ip' => $ip, 'machine' => $clean($m[4], 64), 'start' => null];
        }
    }
    return $out;
}

/** /proc/<pid>/net/dev: the bytes sent by every interface but lo, or null */
function watchmanNetDevTx(string $text): ?int
{
    $tx = null;
    foreach (explode("\n", $text) as $line) {
        if (!preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $m) || $m[1] === 'lo') {
            continue;
        }
        $f = preg_split('/\s+/', trim($m[2])) ?: [];
        if (count($f) >= 9 && ctype_digit($f[8])) {
            $tx = ($tx ?? 0) + (int) $f[8];
        }
    }
    return $tx;
}

/** zfs get -Hp -o name,property,value written,used,snapshots_changed → dataset => [w, u, s (null: never a snapshot, or not known)] */
function watchmanZfsParse(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $f = explode("\t", $line);
        if (count($f) !== 3 || !preg_match('#^[A-Za-z0-9_.:-]+(?:/[^\x00-\x1F@/]+)*$#D', $f[0])) {
            continue;
        }
        if (!isset($out[$f[0]]) && count($out) >= WATCH_FLOW_DATASETS) {
            continue;
        }
        $out[$f[0]] ??= ['w' => 0, 'u' => 0, 's' => null];
        $v = trim($f[2]);
        match ($f[1]) {
            'written' => $out[$f[0]]['w'] = ctype_digit($v) ? (int) $v : 0,
            'used'    => $out[$f[0]]['u'] = ctype_digit($v) ? (int) $v : 0,
            'snapshots_changed' => $out[$f[0]]['s'] = ctype_digit($v) ? (int) $v : null,
            default   => null,
        };
    }
    return $out;
}

/** Monday 00:00 = 0 … Sunday 23:00 = 167, in the server's time */
function watchmanHourOfWeek(int $t): int
{
    return ((int) date('N', $t) - 1) * 24 + (int) date('G', $t);
}

/** Is $h within $span hours (around the week) of one of $hours? */
function watchmanHourNear(array $hours, int $h, int $span = 1): bool
{
    foreach ($hours as $x) {
        $d = abs((int) $x - $h) % 168;
        if (min($d, 168 - $d) <= $span) {
            return true;
        }
    }
    return false;
}

/**
 * What is normal per hour now, from hourly sums (hour index => bytes; the hour going on left out):
 * the most at this time of the week (±1 h), or a quarter of the busiest hour, whichever is more.
 */
function watchmanFlowUsual(array $hours, int $now): int
{
    static $week = [];           // hour index => hour of the week (the same hours for every series)
    $cur = intdiv($now, 3600);
    $how = watchmanHourOfWeek($now);
    $same = $max = 0;
    foreach ($hours as $idx => $bytes) {
        if ((int) $idx === $cur) {
            continue;
        }
        $max = max($max, (int) $bytes);
        if (count($week) > 4096) {
            $week = [];
        }
        if ((int) $bytes > $same && watchmanHourNear([$week[(int) $idx] ??= watchmanHourOfWeek((int) $idx * 3600)], $how)) {
            $same = (int) $bytes;
        }
    }
    return max($same, intdiv($max, 4));
}

/**
 * Is it unusual? Learned (WATCH_FLOW_LEARN since first seen): this hour so far over FACTOR × its
 * normal (and over $floor). Still learning: only one round over $clear (and over FACTOR × what «I
 * know, thanks» made normal).
 * @return array{hour:int, usual:int, limit:int, learning:bool}|null
 */
function watchmanFlowJudge(array $s, int $round, int $now, int $ack, int $floor, int $clear): ?array
{
    $hour = (int) ($s['h'][intdiv($now, 3600)] ?? 0);
    if ($now - (int) ($s['first'] ?? $now) >= WATCH_FLOW_LEARN) {
        $normal = max(watchmanFlowUsual((array) ($s['h'] ?? []), $now), $ack);
        $limit = max($floor, WATCH_FLOW_FACTOR * $normal);
        return $hour > $limit ? ['hour' => $hour, 'usual' => $normal, 'limit' => $limit, 'learning' => false] : null;
    }
    $limit = max($clear, WATCH_FLOW_FACTOR * $ack);
    return $round > $limit ? ['hour' => $hour, 'usual' => $ack, 'limit' => $limit, 'learning' => true] : null;
}

/** A series (client/service, container, share), new */
function watchmanFlowSeries(int $now, array $more = []): array
{
    return $more + ['first' => $now, 'last' => 0, 'h' => [], 'o' => [], 'run' => null];
}

/**
 * Bytes of a round into its hour ('h' learned, 'o' the office's own work) and into the pull going on
 * ('run': from the round before its first, as long as every round has some).
 */
function watchmanFlowAdd(array &$s, int $bytes, int $now, ?int $prevTime, bool $office): void
{
    $idx = intdiv($now, 3600);
    $part = $office ? 'o' : 'h';
    $s[$part] = (array) ($s[$part] ?? []);
    $s[$part][$idx] = (int) ($s[$part][$idx] ?? 0) + $bytes;
    $s['last'] = $now;
    if ($office) {
        $s['run'] = null;           // the office's own work is no pull of anybody's
        return;
    }
    $run = is_array($s['run'] ?? null) ? $s['run'] : null;
    if ($run !== null && $prevTime !== null && (int) $run['last'] >= $prevTime) {
        $run['bytes'] = (int) $run['bytes'] + $bytes;
        $run['last'] = $now;
    } else {
        $run = ['from' => $prevTime ?? $now, 'bytes' => $bytes, 'last' => $now];
    }
    $s['run'] = $run;
}

/**
 * An unusual pull (or one going on) in the book: one open entry per key. A round right after the
 * entry's last one brings it up to date (the same pull, still going); a later unusual one starts a
 * new episode in it (count + 1). $who: what it is about (ip/service, name, share).
 */
function watchmanFlowNote(array &$book, string $kind, string $key, int $now, array $run, int $hour, ?array $judged, array $who): ?string
{
    $minutes = max(1, (int) ceil(($now - (int) $run['from']) / 60));
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') !== $key || !watchmanOpen($e)) {
            continue;
        }
        $p = (array) $e['p'];
        if ($now - (int) $e['last'] <= WATCH_FLOW_GOING) {
            $p = $who + $p;
            $p['bytes'] = (int) $run['bytes'];
            $p['minutes'] = $minutes;
            $p['peak'] = max((int) ($p['peak'] ?? 0), $hour, (int) $run['bytes']);
            $book[$i]['p'] = $p;
            $book[$i]['last'] = $now;
            return null;
        }
        if ($judged === null) {
            return null;
        }
        $book[$i]['count'] = (int) $e['count'] + 1;
        $book[$i]['p'] = $who + ['bytes' => (int) $run['bytes'], 'minutes' => $minutes, 'usual' => $judged['usual'], 'limit' => $judged['limit'],
                                 'learning' => $judged['learning'], 'peak' => max((int) ($p['peak'] ?? 0), $judged['hour'], (int) $run['bytes'])];
        $book[$i]['last'] = $now;
        return null;
    }
    if ($judged === null) {
        return null;
    }
    $book[] = watchmanEntry($kind, $key, $now, $who + ['bytes' => (int) $run['bytes'], 'minutes' => $minutes, 'usual' => $judged['usual'],
        'limit' => $judged['limit'], 'learning' => $judged['learning'], 'peak' => max($judged['hour'], (int) $run['bytes'])]);
    return $kind;
}

/**
 * The data flow of a round: the look against the last round's counters and against what is normal.
 * $bf: the baseline's part (since when, SMB's users and machines and the hours they start sessions,
 * what «I know, thanks» made normal — null: he starts watching, the first look is counters only);
 * $flow: flow.json (hourly sums); $prev: the last round's counters, or null.
 *
 * @return array{0: list<string>, 1: array, 2: array}  kinds added, flow.json, the counters for the next round
 */
function watchmanFlowCompare(?array &$bf, array $flow, ?array $prev, array $look, array &$book, int $now): array
{
    $start = !is_array($bf);
    if ($start) {
        $bf = ['since' => $now, 'smb_users' => [], 'smb_clients' => [], 'ack' => []];
        $flow = [];
        $prev = null;
    }
    $bf += ['since' => $now, 'smb_users' => [], 'smb_clients' => [], 'ack' => []];
    $flow += ['since' => (int) $bf['since'], 'clients' => [], 'containers' => [], 'shares' => [], 'totals' => ['sent' => [], 'written' => []]];
    $office = in_array($look['holder'] ?? null, WATCH_FLOW_OFFICE, true);
    $restore = ($look['holder'] ?? null) === 'restore';
    $officeShares = array_flip(array_map('strval', (array) ($look['office_shares'] ?? [])));
    $prevTime = $prev === null ? null : (int) $prev['time'];
    $ack = fn (string $key): int => (int) ($bf['ack'][$key]['bytes'] ?? 0);
    $added = [];
    $next = ['time' => $now, 'conns' => null, 'cts' => null, 'ds' => null, 'smb' => null];

    // SMB: users, machines, the hours they start sessions
    $names = [];
    $smb = $look['smb'] ?? null;
    if (is_array($smb) && !empty($smb['on'])) {
        $next['smb'] = [];
        $seenIds = array_flip(array_map('strval', (array) ($prev['smb'] ?? [])));
        foreach ((array) $smb['sessions'] as $s) {
            $next['smb'][] = $s['id'];
            $ip = $s['ip'];
            $user = $s['user'];
            $machine = $s['machine'] !== '' && $s['machine'] !== $ip ? $s['machine'] : '';
            if ($machine !== '') {
                $names[$ip] = $machine;
            }
            if (!isset($bf['smb_users'][$user])) {
                if ($start) {
                    $bf['smb_users'][$user] = $now;
                } else {
                    $added[] = watchmanSet($book, 'smb_user', "smb_user:$user", $now, ['user' => $user, 'ip' => $ip, 'machine' => $machine]);
                }
            }
            $how = watchmanHourOfWeek($s['start'] ?? $now);
            $c = $bf['smb_clients'][$ip] ?? null;
            if (!is_array($c)) {
                if ($start) {
                    $bf['smb_clients'][$ip] = ['first' => $now, 'last' => $now, 'hours' => [$how], 'name' => $machine];
                } else {
                    $added[] = watchmanSet($book, 'smb_client', "smb_client:$ip", $now, ['ip' => $ip, 'users' => [$user], 'machine' => $machine]);
                }
                continue;
            }
            $bf['smb_clients'][$ip]['last'] = $now;
            if ($machine !== '') {
                $bf['smb_clients'][$ip]['name'] = $machine;
            }
            // a session that started since the last round
            if ($prev === null || !is_array($prev['smb'] ?? null) || isset($seenIds[$s['id']]) || ($s['start'] !== null && $s['start'] <= $prevTime - 60)) {
                continue;
            }
            $hours = array_map('intval', (array) ($c['hours'] ?? []));
            if ($now - (int) $c['first'] < WATCH_FLOW_LEARN) {
                if (!in_array($how, $hours, true) && count($hours) < 168) {
                    $hours[] = $how;
                    sort($hours);
                    $bf['smb_clients'][$ip]['hours'] = $hours;
                }
            } elseif (!watchmanHourNear($hours, $how)) {
                $added[] = watchmanBump($book, 'smb_hour', "smb_hour:$ip", (int) ($s['start'] ?? $now), 1,
                    ['ip' => $ip, 'users' => [$user], 'hours' => [$how], 'machine' => $machine]);
            }
        }
    }

    // who pulls how much: per client and service
    $conns = $look['conns'] ?? null;
    if (is_array($conns)) {
        $next['conns'] = [];
        $was = $prev['conns'] ?? null;
        $sum = [];
        foreach ($conns as $c) {
            $peer = (string) $c['peer'];
            if (str_starts_with($peer, '127.') || $peer === '::1' || count($next['conns']) >= WATCH_FLOW_CONNS) {
                continue;               // the server talking to itself
            }
            $k = "$c[local]:$c[lport]>$peer:$c[pport]";
            $next['conns'][$k] = (int) $c['sent'];
            if (!is_array($was)) {
                continue;               // the first look: counters only
            }
            $before = $was[$k] ?? null;
            $d = $before !== null && $c['sent'] >= $before ? $c['sent'] - (int) $before : (int) $c['sent'];   // new since (or a new one on the same ports)
            if ($d > 0) {
                $sum["$peer|$c[service]"] = ($sum["$peer|$c[service]"] ?? 0) + $d;
            }
        }
        foreach ($sum as $key => $d) {
            [$ip, $svc] = explode('|', (string) $key, 2);
            $flow['totals']['sent'][$svc] = (int) ($flow['totals']['sent'][$svc] ?? 0) + $d;
            $s = is_array($flow['clients'][$key] ?? null) ? $flow['clients'][$key] : watchmanFlowSeries($now);
            watchmanFlowAdd($s, $d, $now, $prevTime, false);
            if (isset($names[$ip])) {
                $s['name'] = $names[$ip];
            }
            $flow['clients'][$key] = $s;
            $j = watchmanFlowJudge($s, $d, $now, $ack("flow_client:$key"), WATCH_FLOW_MIN, WATCH_FLOW_NEW);
            $added[] = watchmanFlowNote($book, 'flow_client', "flow_client:$key", $now, $s['run'], (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                ['ip' => $ip, 'service' => $svc, 'machine' => (string) ($s['name'] ?? '')]);
        }
    }

    // containers: what each network namespace sent
    $cts = $look['containers'] ?? null;
    $host = [];
    if (is_array($cts)) {
        $next['cts'] = [];
        $was = $prev['cts'] ?? null;
        $byNs = [];
        foreach ($cts as $name => $c) {
            if (!empty($c['host'])) {
                $host[] = (string) $name;
            } else {
                $byNs[$c['ns']][] = (string) $name;
            }
        }
        foreach ($byNs as $names2) {
            sort($names2);
            $name = $names2[0];         // containers sharing one namespace: counted once, under the first name
            $c = $cts[$name];
            $next['cts'][$name] = [(int) $c['pid'], (int) $c['tx']];
            $s = is_array($flow['containers'][$name] ?? null) ? $flow['containers'][$name] : watchmanFlowSeries($now);
            $s['image'] = mb_substr((string) $c['image'], 0, 120);
            $s['with'] = array_slice($names2, 1, WATCH_LIST_MAX);
            $s['seen'] = $now;
            $media = (bool) preg_match(WATCH_FLOW_MEDIA, $s['image'] . ' ' . $name);
            $kopia = ($look['kopia'] ?? null) !== null ? $name === $look['kopia'] : (bool) preg_match('/kopia/i', $s['image']);
            $s['media'] = $media;
            $s['kopia'] = $kopia;
            if (is_array($was)) {
                $before = $was[$name] ?? null;
                $d = is_array($before) && (int) $before[0] === (int) $c['pid'] && $c['tx'] >= (int) $before[1] ? $c['tx'] - (int) $before[1] : (int) $c['tx'];
                if ($d > 0) {
                    $mine = $office && $kopia;      // Kopia uploading the office's backup
                    watchmanFlowAdd($s, $d, $now, $prevTime, $mine);
                    if (!$mine && !$media) {
                        $j = watchmanFlowJudge($s, $d, $now, $ack("flow_container:$name"), WATCH_FLOW_MIN, WATCH_FLOW_NEW);
                        $added[] = watchmanFlowNote($book, 'flow_container', "flow_container:$name", $now, $s['run'], (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                            ['name' => $name, 'image' => $s['image']]);
                    }
                }
            }
            $flow['containers'][$name] = $s;
        }
    }

    // shares: what was written into the datasets of each ZFS share
    $z = $look['zfs'] ?? null;
    if (is_array($z)) {
        $next['ds'] = [];
        $was = $prev['ds'] ?? null;
        $asleep = array_flip((array) $z['asleep']);
        if (is_array($was)) {
            foreach ($was as $ds => $v) {           // a sleeping pool keeps its counters until it wakes
                if (isset($asleep[strtok((string) $ds, '/')])) {
                    $next['ds'][$ds] = $v;
                }
            }
        }
        $per = [];
        foreach ((array) $z['datasets'] as $ds => $v) {
            $parts = explode('/', (string) $ds, 3);
            if (count($parts) < 2) {
                continue;               // the pool's own top: no share
            }
            $share = "$parts[0]/$parts[1]";
            $next['ds'][$ds] = [(int) $v['w'], $v['s']];
            $a = $per[$share] ?? ['d' => 0, 'w' => 0, 'u' => 0, 's' => null, 'snap' => false];
            $a['w'] += (int) $v['w'];
            if (count($parts) === 2) {
                $a['u'] = (int) $v['u'];
                $a['s'] = $v['s'];
            }
            $a['snap'] = $a['snap'] || $v['s'] !== null;
            if (is_array($was) && is_array($was[$ds] ?? null)) {
                [$pw, $ps] = $was[$ds];
                if ($ps === $v['s']) {
                    $a['d'] += max(0, (int) $v['w'] - (int) $pw);
                } elseif ((int) $v['w'] < (int) $pw) {
                    $a['d'] += (int) $v['w'];       // a new snapshot: what was written since it
                }                                    // snapshots went and it grew: can't be told — this round left out
            }
            $per[$share] = $a;
        }
        foreach ($per as $share => $a) {
            if (!isset($flow['shares'][$share]) && count($flow['shares']) >= WATCH_FLOW_SHARES) {
                continue;
            }
            $s = is_array($flow['shares'][$share] ?? null) ? $flow['shares'][$share] : watchmanFlowSeries($now);
            $s['w'] = $a['w'];
            $s['u'] = $a['u'];
            $s['s'] = $a['s'];
            $s['snap'] = $a['snap'];
            $s['seen'] = $now;
            if ($a['d'] > 0) {
                $flow['totals']['written'][$share] = (int) ($flow['totals']['written'][$share] ?? 0) + $a['d'];
                $mine = $office && ($restore || isset($officeShares[explode('/', (string) $share, 2)[1]]));      // the engine's packages and dumps, a restore
                watchmanFlowAdd($s, $a['d'], $now, $prevTime, $mine);
                if (!$mine) {
                    $clear = max(WATCH_FLOW_WRITE, (int) ($a['u'] * WATCH_FLOW_PART));
                    $j = watchmanFlowJudge($s, $a['d'], $now, $ack("flow_written:$share"), WATCH_FLOW_MIN, $clear);
                    $run = (array) $s['run'];
                    $added[] = watchmanFlowNote($book, 'flow_written', "flow_written:$share", $now, $run, (int) ($s['h'][intdiv($now, 3600)] ?? 0), $j,
                        ['share' => $share, 'pct' => $a['u'] > 0 ? (int) min(999, round(100 * (int) $run['bytes'] / $a['u'])) : 0]);
                }
            }
            $flow['shares'][$share] = $s;
        }
    }

    foreach (['conns', 'cts', 'ds', 'smb'] as $part) {
        if ($next[$part] === null) {
            $next[$part] = $prev[$part] ?? null;       // not looked at this time: the last counters stay (counted on next time)
        }
    }
    $flow['last'] = $now;
    $flow['can'] = ['ss' => is_array($conns), 'smb' => is_array($smb) ? (!empty($smb['on']) ? 'on' : 'off') : null, 'nfs' => !empty($look['nfs']),
                    'docker' => is_array($cts), 'host' => array_slice($host, 0, 50),
                    'zfs' => is_array($z) ? ['pools' => array_values((array) $z['pools']), 'asleep' => array_values((array) $z['asleep'])] : null,
                    'office' => $office ? (string) $look['holder'] : null];
    watchmanFlowTidy($bf, $flow, $now);
    return [array_values(array_filter($added)), $flow, $next];
}

/** Keeps the data flow small: hours beyond WATCH_FLOW_KEEP and tiny past hours go, what is long gone is forgotten, the lists capped */
function watchmanFlowTidy(array &$bf, array &$flow, int $now): void
{
    $oldest = intdiv($now - WATCH_FLOW_KEEP, 3600);
    $cur = intdiv($now, 3600);
    foreach (['clients' => WATCH_FLOW_CLIENTS, 'containers' => WATCH_FLOW_CTS, 'shares' => WATCH_FLOW_SHARES] as $part => $cap) {
        $list = (array) ($flow[$part] ?? []);
        foreach ($list as $key => $s) {
            foreach (['h', 'o'] as $hk) {
                $s[$hk] = array_filter((array) ($s[$hk] ?? []), fn ($b, $idx) => (int) $idx >= $oldest && ((int) $idx >= $cur || (int) $b >= WATCH_FLOW_TINY),
                                       ARRAY_FILTER_USE_BOTH);
            }
            $seen = max((int) ($s['last'] ?? 0), (int) ($s['seen'] ?? 0), (int) ($s['first'] ?? 0));
            if ($now - $seen > WATCH_FORGET) {
                unset($list[$key]);
                continue;
            }
            $list[$key] = $s;
        }
        if (count($list) > $cap) {
            uasort($list, fn ($x, $y) => max((int) ($y['last'] ?? 0), (int) ($y['seen'] ?? 0)) <=> max((int) ($x['last'] ?? 0), (int) ($x['seen'] ?? 0)));
            $list = array_slice($list, 0, $cap, true);
        }
        $flow[$part] = $list;
    }
    $parts = ['flow_client' => 'clients', 'flow_container' => 'containers', 'flow_written' => 'shares'];
    foreach ((array) $bf['ack'] as $key => $a) {
        [$kind, $what] = array_pad(explode(':', (string) $key, 2), 2, '');
        if (!isset($parts[$kind], $flow[$parts[$kind]][$what])) {
            unset($bf['ack'][$key]);                // what it was about is forgotten
        }
    }
    foreach (['smb_users' => null, 'smb_clients' => 'last'] as $k => $field) {
        $list = (array) $bf[$k];
        if (count($list) > WATCH_FLOW_SMB_MAX) {
            uasort($list, fn ($x, $y) => ($field ? (int) ($y[$field] ?? 0) : (int) $y) <=> ($field ? (int) ($x[$field] ?? 0) : (int) $x));
            $list = array_slice($list, 0, WATCH_FLOW_SMB_MAX, true);
        }
        $bf[$k] = $list;
    }
    $written = (array) ($flow['totals']['written'] ?? []);
    $flow['totals']['written'] = array_intersect_key($written, (array) $flow['shares']);
}

/** For the metrics (state.json): bytes sent per service, written per share (the WATCH_FLOW_TOP most) since he watches */
function watchmanFlowTotals(array $flow): array
{
    $sent = [];
    foreach (array_keys(WATCH_FLOW_SERVICES) as $svc) {
        $sent[$svc] = (int) ($flow['totals']['sent'][$svc] ?? 0);
    }
    $written = array_map('intval', (array) ($flow['totals']['written'] ?? []));
    arsort($written);
    return ['sent' => $sent, 'written' => array_slice($written, 0, WATCH_FLOW_TOP, true)];
}

/** "38 GB" — like the page's fmt.size (1024, one decimal under 10) */
function watchmanSize(int $bytes, string $lang = 'en'): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $v = (float) max(0, $bytes);
    $i = 0;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return number_format($v, $i === 0 ? 0 : ($v < 10 ? 1 : 0), $lang === 'en' ? '.' : ',', '') . ' ' . $units[$i];
}

/** Hours of the week as "Mon 03:00" (English day names; the page writes them in the browser's language) */
function watchmanHourNames(array $hours): string
{
    return implode(', ', array_map(fn ($h) => date('D', strtotime('2024-01-01 +' . intdiv((int) $h, 24) . ' days')) . sprintf(' %02d:00', (int) $h % 24),
                                   array_slice($hours, 0, WATCH_LIST_MAX)));
}

/** What is normal, in words of $lang (null: left out — the page writes it itself) */
function watchmanFlowUsualText(array $p, string $kind, ?string $lang): string
{
    if ($lang === null) {
        return '';
    }
    if (!empty($p['learning'])) {
        return $kind === 'flow_written'
            ? officeNotifyText('watchman', 'flow.learning_share', ['pct' => (int) ($p['pct'] ?? 0)], $lang)
            : officeNotifyText('watchman', 'flow.learning', ['size' => watchmanSize((int) ($p['limit'] ?? 0), $lang)], $lang);
    }
    return (int) ($p['usual'] ?? 0) > 0
        ? officeNotifyText('watchman', 'flow.usual', ['size' => watchmanSize((int) $p['usual'], $lang)], $lang)
        : officeNotifyText('watchman', 'flow.usual_none', [], $lang);
}

/** "What I keep an eye on" of the data flow: per client and service, container, share the last 24 h and what is normal now */
function watchmanFlowSummary(?array $bf, ?array $flow, int $now): ?array
{
    if (!is_array($bf) || !is_array($flow)) {
        return null;
    }
    $day = intdiv($now - 86400, 3600);
    $sum = fn (array $h): int => array_sum(array_filter(array_map('intval', $h), fn ($idx) => (int) $idx > $day, ARRAY_FILTER_USE_KEY));
    $learning = fn (array $s): ?int => $now - (int) ($s['first'] ?? $now) < WATCH_FLOW_LEARN ? intdiv($now - (int) ($s['first'] ?? $now), 86400) : null;
    $clients = [];
    foreach ((array) ($flow['clients'] ?? []) as $key => $s) {
        [$ip, $svc] = array_pad(explode('|', (string) $key, 2), 2, '');
        $h = (array) ($s['h'] ?? []);
        $clients[] = ['ip' => $ip, 'service' => $svc, 'name' => (string) ($s['name'] ?? ''), 'day' => $sum($h), 'usual' => watchmanFlowUsual($h, $now),
                      'peak' => $h ? max(array_map('intval', $h)) : 0, 'learning' => $learning($s), 'last' => (int) ($s['last'] ?? 0),
                      'ack' => (int) ($bf['ack']["flow_client:$key"]['bytes'] ?? 0)];
    }
    usort($clients, fn ($x, $y) => [$y['day'], $y['last']] <=> [$x['day'], $x['last']]);
    $cts = [];
    foreach ((array) ($flow['containers'] ?? []) as $name => $s) {
        $h = (array) ($s['h'] ?? []);
        $cts[] = ['name' => (string) $name, 'image' => (string) ($s['image'] ?? ''), 'day' => $sum($h) + $sum((array) ($s['o'] ?? [])), 'office' => $sum((array) ($s['o'] ?? [])),
                  'usual' => watchmanFlowUsual($h, $now), 'media' => !empty($s['media']), 'kopia' => !empty($s['kopia']), 'with' => (array) ($s['with'] ?? []),
                  'learning' => $learning($s), 'running' => (int) ($s['seen'] ?? 0) >= (int) ($flow['last'] ?? 0)];
    }
    usort($cts, fn ($x, $y) => [$y['day'], $x['name']] <=> [$x['day'], $y['name']]);
    $shares = [];
    foreach ((array) ($flow['shares'] ?? []) as $share => $s) {
        $h = (array) ($s['h'] ?? []);
        $shares[] = ['share' => (string) $share, 'day' => $sum($h) + $sum((array) ($s['o'] ?? [])), 'office' => $sum((array) ($s['o'] ?? [])),
                     'written' => (int) ($s['w'] ?? 0), 'used' => (int) ($s['u'] ?? 0), 'snap' => isset($s['s']) ? (int) $s['s'] : null, 'snapshots' => !empty($s['snap']),
                     'usual' => watchmanFlowUsual($h, $now), 'learning' => $learning($s), 'seen' => (int) ($s['seen'] ?? 0)];
    }
    usort($shares, fn ($x, $y) => [$y['day'], $x['share']] <=> [$x['day'], $y['share']]);
    $smbClients = [];
    foreach ((array) ($bf['smb_clients'] ?? []) as $ip => $c) {
        $smbClients[] = ['ip' => (string) $ip, 'name' => (string) ($c['name'] ?? ''), 'hours' => count((array) ($c['hours'] ?? [])),
                         'learning' => $learning($c), 'last' => (int) ($c['last'] ?? 0)];
    }
    usort($smbClients, fn ($x, $y) => $y['last'] <=> $x['last']);
    $users = array_map('strval', array_keys((array) ($bf['smb_users'] ?? [])));
    sort($users);
    return [
        'since'      => (int) ($bf['since'] ?? $now),
        'days'       => intdiv($now - (int) ($bf['since'] ?? $now), 86400),
        'learn'      => intdiv(WATCH_FLOW_LEARN, 86400),
        'last'       => (int) ($flow['last'] ?? 0),
        'can'        => (array) ($flow['can'] ?? []),
        'clients'    => array_slice($clients, 0, 40),
        'containers' => array_slice($cts, 0, 15),
        'idle'       => max(0, count($cts) - 15),
        'shares'     => array_slice($shares, 0, 60),
        'smb'        => ['users' => array_slice($users, 0, 60), 'clients' => array_slice($smbClients, 0, 40)],
        'limits'     => ['factor' => WATCH_FLOW_FACTOR, 'min' => WATCH_FLOW_MIN, 'new' => WATCH_FLOW_NEW, 'part' => (int) round(WATCH_FLOW_PART * 100),
                         'keep' => intdiv(WATCH_FLOW_KEEP, 86400)],
    ];
}

// ===================================================================== «I know, thanks»

/**
 * The state an entry tells of becomes normal: an address known, a container's
 * new rights, a plugin and its source, go, a file, a user, a password, a key,
 * a share open to guests — as the last round saw it ($seen). What is gone
 * meanwhile isn't adopted (if it comes back, it is told again).
 */
function watchmanAdopt(array &$b, array $e, array $seen, int $now): void
{
    $p = (array) ($e['p'] ?? []);
    $kind = (string) $e['kind'];
    $name = (string) ($p['name'] ?? '');
    switch ($kind) {
        case 'login_new_ip':
            $k = $b['ips'][$p['ip']] ?? ['first' => (int) $e['time'], 'last' => 0, 'users' => [], 'services' => []];
            $k['last'] = max((int) $k['last'], (int) $e['last']);
            $k['users'] = watchmanMerge(['x' => (array) $k['users']], ['x' => (array) ($p['users'] ?? [])])['x'];
            $k['services'] = watchmanMerge(['x' => (array) $k['services']], ['x' => (array) ($p['services'] ?? [])])['x'];
            $b['ips'][$p['ip']] = $k;
            break;
        case 'login_failures':
            $b['fail_ips'][$p['ip']] = ['since' => $now, 'last' => (int) $e['last'], 'n' => (int) $e['count'], 'quiet' => 0];
            break;
        case 'container_new':
            $b['containers'] ??= [];
            if (isset($seen['containers'][$name])) {
                $b['containers'][$name] = ['tokens' => (array) $seen['containers'][$name]['tokens'], 'seen' => $now];
            }
            break;
        case 'container_privileged':
        case 'container_host':
        case 'container_ports':
        case 'container_rights':
            $b['containers'] ??= [];
            $current = (array) ($seen['containers'][$name]['tokens'] ?? []);
            $was = (array) ($b['containers'][$name]['tokens'] ?? []);
            $tokens = array_values(array_unique(array_merge($was, array_intersect((array) ($p['tokens'] ?? []), $current))));
            sort($tokens);
            $b['containers'][$name] = ['tokens' => $tokens, 'seen' => $now];
            break;
        case 'plugin_new':
        case 'plugin_source':
            if (isset($seen['plugins'][$name])) {
                $b['plugins'][$name] = $seen['plugins'][$name] + ['seen' => $now];
            }
            break;
        case 'flash_go':
            $b['flash']['go'] = $seen['flash']['go'] ?? null;
            break;
        case 'flash_extra':
            $file = (string) ($p['file'] ?? '');
            if (isset($seen['flash']['extra'][$file])) {
                $b['flash']['extra'][$file] = $seen['flash']['extra'][$file];
            } else {
                unset($b['flash']['extra'][$file]);
            }
            break;
        case 'flash_user':
        case 'flash_password':
            $u = (string) ($p['user'] ?? '');
            if (in_array($u, (array) ($seen['flash']['users'] ?? []), true)) {
                $b['flash']['users'] = array_values(array_unique(array_merge((array) ($b['flash']['users'] ?? []), [$u])));
                $b['flash']['pw'][$u] = $seen['flash']['pw'][$u] ?? null;
            }
            break;
        case 'flash_ssh_key':
            $k = $seen['flash']['keys'][$p['user'] ?? ''][$p['fp'] ?? ''] ?? null;
            if ($k !== null) {
                $b['flash']['keys'][$p['user']][$p['fp']] = $k;
            }
            break;
        case 'share_public':
            $share = (string) ($p['share'] ?? '');
            $proto = ($p['proto'] ?? '') === 'nfs' ? 'nfs' : 'smb';
            $b['shares'] ??= [];
            $b['shares'][$share] ??= ['smb' => 0, 'nfs' => 0, 'seen' => $now];
            $b['shares'][$share][$proto] = (int) ($seen['shares'][$share][$proto] ?? 0);
            break;
        case 'flow_client':
        case 'flow_container':
        case 'flow_written':
        case 'smb_user':
        case 'smb_client':
        case 'smb_hour':
            watchmanFlowAdopt($b, $e, $now);
            break;
        default:
            if ((WATCH_KINDS[$kind][0] ?? '') === 'sched') {
                watchmanSchedAdopt($b, $kind, $p, $seen);
            }
    }
}

/**
 * «I know, thanks» on the data flow: that much is normal for this client, container or share from
 * now on (its normal raised to the most it pulled in an hour, never lowered); the SMB user, the
 * machine, the hours are known.
 */
function watchmanFlowAdopt(array &$b, array $e, int $now): void
{
    if (!is_array($b['flow'] ?? null)) {
        return;                     // the data flow was started anew meanwhile: nothing to raise
    }
    $p = (array) ($e['p'] ?? []);
    $kind = (string) $e['kind'];
    $f = &$b['flow'];
    switch ($kind) {
        case 'flow_client':
        case 'flow_container':
        case 'flow_written':
            $key = (string) $e['key'];
            $f['ack'][$key] = ['bytes' => max((int) ($f['ack'][$key]['bytes'] ?? 0), (int) ($p['peak'] ?? 0)), 'time' => $now];
            break;
        case 'smb_user':
            $f['smb_users'][(string) ($p['user'] ?? '?')] = $now;
            break;
        case 'smb_client':
        case 'smb_hour':
            $ip = (string) ($p['ip'] ?? '');
            if ($ip === '') {
                break;
            }
            $c = is_array($f['smb_clients'][$ip] ?? null) ? $f['smb_clients'][$ip] : ['first' => $now, 'last' => $now, 'hours' => [], 'name' => (string) ($p['machine'] ?? '')];
            $hours = array_values(array_unique(array_map('intval', array_merge((array) $c['hours'], (array) ($p['hours'] ?? [])))));
            sort($hours);
            $c['hours'] = $hours;
            $f['smb_clients'][$ip] = $c;
            break;
    }
}

/** «I know, thanks» for one entry (its id) or all open ones ('*') */
function watchmanAck(mixed $id, ?string $dir = null, ?int $now = null, bool $page = true): array
{
    $all = $id === '*';
    if (!$all && (!is_string($id) || !preg_match(WATCH_ID, $id))) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    $now ??= time();
    $noted = watchmanLocked($dir, function () use ($dir, $id, $all, $now): array {
        $d = watchmanLoad($dir);
        if (!is_array($d['baseline'])) {
            throw new Problem('watch_gone');
        }
        $seen = readJson("$dir/seen.json") ?? [];
        $b = $d['baseline'];
        $book = $d['book'];
        $noted = [];
        foreach ($book as $i => $e) {
            if (!watchmanOpen($e) || (!$all && $e['id'] !== $id)) {
                continue;
            }
            watchmanAdopt($b, $e, $seen, $now);
            $book[$i]['noted'] = $now;
            $book[$i]['by'] = 'page';
            $noted[] = $e['kind'];
        }
        if (!$noted && !$all) {
            throw new Problem('watch_gone');
        }
        $st = $d['state'];
        $st['open'] = watchmanOpenCounts($book);
        watchmanSave($dir, $d, ['baseline' => $b, 'book' => $book, 'state' => $st]);
        return $noted;
    });
    if (!$page) {
        return ['ok' => true, 'noted' => count($noted)];
    }
    if ($noted) {
        logLine('Night watchman: ' . count($noted) . ' noted («I know, thanks»): ' . implode(', ', array_unique($noted)));
    }
    return ['ok' => true, 'noted' => count($noted), 'state' => watchmanPageState()];
}

/**
 * What the team lead put aside («I know, thanks» on one of his findings)
 * counts here too: every entry that finding stood for is noted. Only while
 * the finding is still the same (its signature): a newer entry since makes it
 * another one.
 */
function watchmanTeamLeadNotes(array &$b, array &$book, array $seen, int $now, ?string $acksFile = null): void
{
    if (!function_exists('caretakerAckRead') || !function_exists('caretakerAckSig') || !function_exists('caretakerAckFile')) {
        return;
    }
    $acks = caretakerAckRead($acksFile ?? caretakerAckFile());
    if (!$acks) {
        return;
    }
    foreach (watchmanFindings($book) as $f) {
        if (!isset($acks[caretakerAckSig('watchman', $f)])) {
            continue;
        }
        foreach ($book as $i => $e) {
            if (watchmanOpen($e) && $e['kind'] === $f['id']) {
                watchmanAdopt($b, $e, $seen, $now);
                $book[$i]['noted'] = $now;
                $book[$i]['by'] = 'teamlead';
            }
        }
    }
}

// ===================================================================== texts, the team lead, notifications

/**
 * The words an entry's texts take (entry.<kind>, check.<kind>, notify.<kind>):
 * only names, addresses and numbers — the same in every language; with $lang
 * (notifications) also the data flow's "what is normal" in that language.
 */
function watchmanText(array $e, ?string $lang = null): array
{
    $p = (array) ($e['p'] ?? []);
    $services = implode(', ', watchmanServiceNames((array) ($p['services'] ?? [])));
    $list = fn (string $k) => implode(', ', array_map('strval', (array) ($p[$k] ?? [])));
    $few = array_map('strval', array_slice((array) ($p['jobs'] ?? []), 0, 3));
    $jobs = implode(' · ', $few) . ((int) ($p['lines'] ?? 0) > count($few) ? ' · …' : '');
    return match ((string) $e['kind']) {
        'login_new_ip'   => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?', 'service' => $services],
        'login_failures' => ['ip' => (string) ($p['ip'] ?? ''), 'service' => $services],
        'container_new', 'container_privileged', 'container_host', 'container_ports', 'container_rights'
                         => ['name' => (string) ($p['name'] ?? ''), 'rights' => implode(' ', (array) ($p['tokens'] ?? []))],
        'plugin_new'     => ['name' => (string) ($p['name'] ?? ''), 'source' => (string) ($p['source'] ?? '') ?: '–'],
        'plugin_source'  => ['name' => (string) ($p['name'] ?? ''), 'source' => (string) ($p['source'] ?? '') ?: '–', 'old' => (string) ($p['old'] ?? '') ?: '–'],
        'flash_go'       => ['added' => (int) ($p['added'] ?? 0), 'removed' => (int) ($p['removed'] ?? 0)],
        'flash_extra'    => ['file' => (string) ($p['file'] ?? '')],
        'flash_user', 'flash_password' => ['user' => (string) ($p['user'] ?? '')],
        'flash_ssh_key'  => ['user' => (string) ($p['user'] ?? ''), 'key' => (string) ($p['comment'] ?? '') ?: substr((string) ($p['fp'] ?? ''), 0, 19)],
        'share_public'   => ['share' => (string) ($p['share'] ?? ''), 'proto' => strtoupper((string) ($p['proto'] ?? ''))],
        'cron_new', 'cron_twice', 'cron_office'
                         => ['lines' => (int) ($p['lines'] ?? 0), 'jobs' => $jobs, 'fix' => watchmanCronFix((string) $e['kind'])],
        'cron_dead'      => ['path' => (string) ($p['path'] ?? ''), 'plugin' => (string) ($p['plugin'] ?? ''),
                             'fix' => watchmanCronFix('cron_dead', (string) ($p['path'] ?? ''))],
        'cron_file', 'cron_file_foreign'
                         => ['file' => (string) ($p['file'] ?? ''), 'plugin' => (string) ($p['plugin'] ?? ''), 'lines' => (int) ($p['lines'] ?? 0), 'jobs' => $jobs],
        'script_new', 'script_changed' => ['name' => (string) ($p['name'] ?? ''), 'cron' => (string) ($p['cron'] ?? '')],
        'at_job'         => ['cmd' => (string) ($p['cmd'] ?? '') ?: '?', 'when' => date('Y-m-d H:i', (int) ($p['when'] ?? 0))],
        'at_userscript'  => ['name' => (string) ($p['name'] ?? ''), 'when' => date('Y-m-d H:i', (int) ($p['when'] ?? 0))],
        'notify_agent'   => ['name' => (string) ($p['name'] ?? '')],
        'flow_client'    => ['ip' => (string) ($p['ip'] ?? ''), 'service' => WATCH_FLOW_SERVICES[$p['service'] ?? ''] ?? (string) ($p['service'] ?? ''),
                             'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'), 'minutes' => (int) ($p['minutes'] ?? 0),
                             'usual' => watchmanFlowUsualText($p, 'flow_client', $lang)],
        'flow_container' => ['name' => (string) ($p['name'] ?? ''), 'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'),
                             'minutes' => (int) ($p['minutes'] ?? 0), 'usual' => watchmanFlowUsualText($p, 'flow_container', $lang)],
        'flow_written'   => ['share' => (string) ($p['share'] ?? ''), 'size' => watchmanSize((int) ($p['bytes'] ?? 0), $lang ?? 'en'),
                             'minutes' => (int) ($p['minutes'] ?? 0), 'usual' => watchmanFlowUsualText($p, 'flow_written', $lang)],
        'smb_user'       => ['user' => (string) ($p['user'] ?? ''), 'ip' => (string) ($p['ip'] ?? '')],
        'smb_client'     => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?'],
        'smb_hour'       => ['ip' => (string) ($p['ip'] ?? ''), 'user' => $list('users') ?: '?', 'hours' => watchmanHourNames((array) ($p['hours'] ?? []))],
        'watch'          => array_map('intval', $p),
        default          => [],
    };
}

/** "SSH (publickey)", "WebGUI" — names, not words */
function watchmanServiceName(string $s): string
{
    if ($s === 'web') {
        return 'WebGUI';
    }
    return str_starts_with($s, 'ssh:') ? 'SSH (' . substr($s, 4) . ')' : 'SSH';
}

/** The names of a list of services; a bare "SSH" (an invalid user, no method) only when no SSH method is named */
function watchmanServiceNames(array $services): array
{
    $names = array_values(array_unique(array_map(fn ($s) => watchmanServiceName((string) $s), $services)));
    $methods = array_filter($names, fn ($n) => str_starts_with($n, 'SSH ('));
    return array_values(array_filter($names, fn ($n) => $n !== 'SSH' || !$methods));
}

/**
 * For the team lead: one recommended finding per kind with open entries —
 * how many, and the newest's words (another entry, another finding: his «I
 * know, thanks» then counts for what he saw).
 */
function watchmanFindings(array $book): array
{
    $by = [];
    foreach ($book as $e) {
        if (watchmanOpen($e)) {
            $by[$e['kind']][] = $e;
        }
    }
    $out = [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        if (empty($by[$kind])) {
            continue;
        }
        $list = $by[$kind];
        usort($list, fn ($a, $b) => [(int) $a['last'], $a['id']] <=> [(int) $b['last'], $b['id']]);
        // what moves while a pull goes on stays out (his «I know, thanks» there must hold); a size may grow (CARETAKER_ACK_DRIFT)
        $out[] = finding($kind, 'recommended', false, ['n' => count($list)] + array_diff_key(watchmanText(end($list)), ['minutes' => 1, 'usual' => 1]), '#/watchman');
    }
    return $out;
}

function watchmanChecks(?string $dir = null): array
{
    $d = watchmanLoad($dir);
    if (!is_array($d['baseline'])) {
        return [];                  // his first round is still to come
    }
    return watchmanFindings($d['book']) ?: [finding('quiet', 'recommended', true, [], '#/watchman')];
}

/**
 * The important kinds go to Unraid's notifications right away — per kind
 * one message for what is new, then quiet for WATCH_NOTIFY_QUIET (what comes
 * meanwhile is told together after it). Noted entries are never told.
 * @return list<array{kind: string, n: int, sent: bool}>
 */
function watchmanNotifyDue(array &$book, array &$st, int $now, bool $send, ?string $lang = null): array
{
    $told = [];
    $on = ($st['notify'] ?? true) !== false;
    foreach (WATCH_KINDS as $kind => [, $important]) {
        if (!$important) {
            continue;
        }
        $new = [];
        foreach ($book as $i => $e) {
            if ($e['kind'] === $kind && watchmanOpen($e) && empty($e['told']) && empty($e['muted'])) {
                $new[] = $i;
            }
        }
        if (!$on) {
            foreach ($new as $i) {
                $book[$i]['muted'] = $now;      // switched off: never told, also not once switched on again
            }
            continue;
        }
        if (!$new || $now - (int) ($st['notified'][$kind] ?? 0) < WATCH_NOTIFY_QUIET) {
            continue;
        }
        $sent = $send && watchmanNotifySend($kind, array_map(fn ($i) => $book[$i], $new), $lang ?? officeNotifyLang());
        foreach ($new as $i) {
            $book[$i]['told'] = $now;
        }
        $st['notified'][$kind] = $now;
        $told[] = ['kind' => $kind, 'n' => count($new), 'sent' => $sent];
    }
    if ($told) {
        $st['last_notify'] = ['time' => $now, 'items' => $told];
    }
    return $told;
}

/** The switch on his page (like the team lead's): report to Unraid's notifications or not — default on */
function watchmanNotifySet(mixed $on, ?string $dir = null, bool $page = true): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    watchmanLocked($dir, function () use ($dir, $on): void {
        $d = watchmanLoad($dir);
        $st = $d['state'];
        $st['notify'] = $on;
        watchmanSave($dir, $d, ['state' => $st]);
    });
    if (!$page) {
        return ['ok' => true];
    }
    logLine("Night watchman: reports to Unraid's notifications " . ($on ? 'on' : 'off'));
    return ['ok' => true, 'state' => watchmanPageState()];
}

/** One notification for a kind: the bell's line, the newest in its words, each entry with its time */
function watchmanNotifySend(string $kind, array $entries, string $lang): bool
{
    usort($entries, fn ($a, $b) => (int) $b['last'] <=> (int) $a['last']);
    $n = count($entries);
    $params = ['n' => $n] + watchmanText($entries[0], $lang);
    $lines = [];
    foreach (array_slice($entries, 0, 10) as $e) {
        $lines[] = '• ' . officeNotifyText('watchman', "entry.$kind", ['n' => (int) $e['count']] + watchmanText($e, $lang), $lang)
                 . ' — ' . date('Y-m-d H:i', (int) $e['last']);
    }
    if ($n > 10) {
        $lines[] = officeNotifyText('watchman', 'notify.more', ['n' => $n - 10], $lang);
    }
    return officeNotify(
        officeNotifyText('watchman', "notify.$kind", $params, $lang),
        officeNotifyText('watchman', "check.$kind", $params, $lang),
        'warning',
        implode("\n", $lines) . "\n\n" . officeNotifyText('watchman', 'notify.footer', [], $lang),
        officeNotifyLink('#/watchman'),
    );
}

/** For the office's metrics (Prometheus): open entries per kind, when the last round ended — from his state file only */
function watchmanMetrics(?string $dir = null): array
{
    if ($dir === null && watchmanHiredSince() === null) {
        return [];
    }
    $st = readJson(($dir ?? watchmanDir()) . '/state.json') ?? [];
    $samples = [];
    foreach (array_keys(WATCH_KINDS) as $kind) {
        $samples[] = [['kind' => $kind], (int) ($st['open'][$kind] ?? 0)];
    }
    $last = (int) ($st['round']['time'] ?? 0);
    $flow = (array) ($st['flow'] ?? []);
    $sent = $written = [];
    foreach ((array) ($flow['sent'] ?? []) as $svc => $bytes) {
        if (isset(WATCH_FLOW_SERVICES[$svc])) {
            $sent[] = [['service' => (string) $svc], (int) $bytes];
        }
    }
    foreach (array_slice((array) ($flow['written'] ?? []), 0, WATCH_FLOW_TOP, true) as $share => $bytes) {
        $written[] = [['share' => (string) $share], (int) $bytes];
    }
    return [
        ['name' => 'uso_watchman_open_findings', 'type' => 'gauge', 'help' => 'Night watchman findings not noted yet', 'samples' => $samples],
        ['name' => 'uso_watchman_last_round_timestamp_seconds', 'type' => 'gauge', 'help' => 'When the night watchman last finished a round (unix time)',
         'samples' => $last > 0 ? [[[], $last]] : []],
        ['name' => 'uso_watchman_sent_bytes_total', 'type' => 'counter',
         'help' => 'Bytes the server sent to clients per file service (SMB, NFS, SSH, WebGUI) since the night watchman watches the data flow', 'samples' => $sent],
        ['name' => 'uso_watchman_written_bytes_total', 'type' => 'counter',
         'help' => 'Bytes written into ZFS shares since the night watchman watches the data flow (the ' . WATCH_FLOW_TOP . ' shares with the most)', 'samples' => $written],
    ];
}

// ===================================================================== the page

/**
 * What the page shows (data/watchman.json): the last and next round, the
 * watch book (newest first), what he keeps an eye on. Never the internals
 * (positions, hashes).
 */
function watchmanPageState(?string $dir = null, ?int $now = null, bool $write = true): array
{
    $dir ??= watchmanDir();
    $now ??= time();
    $d = watchmanLoad($dir);
    $b = $d['baseline'];
    $st = $d['state'];
    $since = $write ? watchmanHiredSince() : (int) ($b['hired'] ?? 0);
    $last = isset($st['round']['time']) ? (int) $st['round']['time'] : null;
    $book = [];
    foreach ($d['book'] as $e) {
        $book[] = ['id' => $e['id'], 'kind' => $e['kind'], 'group' => WATCH_KINDS[$e['kind']][0] ?? 'watch', 'tell' => WATCH_KINDS[$e['kind']][1] ?? false,
                   'time' => (int) $e['time'], 'last' => (int) $e['last'], 'count' => (int) $e['count'],
                   'open' => watchmanOpen($e), 't' => watchmanText($e),
                   'p' => array_filter((array) ($e['p'] ?? []), fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
                   'noted' => $e['noted'] ?? null, 'by' => $e['by'] ?? null, 'told' => $e['told'] ?? null, 'muted' => $e['muted'] ?? null];
    }
    usort($book, fn ($x, $y) => [$y['last'], $y['time']] <=> [$x['last'], $x['time']]);
    $onWatch = is_array($b) && $since !== null && (int) ($b['hired'] ?? -1) === $since;
    $state = [
        'time'     => $now,
        'hired'    => $since !== null,
        'round'    => [
            'last'        => $last,
            'next'        => $since === null ? null : max($now, ($last ?? $now) + WATCH_EVERY),
            'running'     => !empty($GLOBALS['wmRound']) || !empty($GLOBALS['wmOrphan']),
            'failed'      => !empty($st['round']['failed']),
            'duration_ms' => (int) ($st['round']['duration_ms'] ?? 0),
            'skipped'     => (int) ($st['round']['skipped'] ?? 0),
            'docker'      => $st['round']['docker'] ?? null,
            'shares'      => $st['round']['shares'] ?? null,
        ],
        'on_watch' => $onWatch ? (int) $b['time'] : null,
        'open'     => watchmanOpenCounts($d['book']),
        'book'     => $book,
        'watch'    => $onWatch ? watchmanSummary($b, readJson("$dir/seen.json")) : null,
        'flow'     => $onWatch ? watchmanFlowSummary(is_array($b['flow'] ?? null) ? $b['flow'] : null, readJson("$dir/flow.json"), $now) : null,
        'notified' => $st['last_notify'] ?? null,
        'notify'   => ['on' => ($st['notify'] ?? true) !== false, 'available' => is_executable(OFFICE_NOTIFY_BIN)],
        'limits'   => ['every' => WATCH_EVERY, 'burst' => WATCH_FAIL_BURST, 'window' => WATCH_FAIL_WINDOW,
                       'quiet' => WATCH_NOTIFY_QUIET, 'keep' => WATCH_BOOK_MAX, 'days' => WATCH_BOOK_DAYS],
    ];
    if ($write && is_dir(DATA_DIR)) {
        writeAtomic(deskFile('watchman'), jsonEncode($state));
    }
    return $state;
}

/**
 * "What I keep an eye on": the baseline, summarised — of the containers, plugins and shares only what
 * is there now ($seen: what the last round saw). Gone ones stay in his memory for WATCH_FORGET, so
 * one that comes back (a Compose Down and Up, an update, a reinstall) is compared with what it was,
 * not reported as new; but they are no longer counted or listed.
 */
function watchmanSummary(array $b, ?array $seen = null): array
{
    $there = function (string $part) use ($b, $seen): array {
        $known = is_array($b[$part] ?? null) ? $b[$part] : [];
        return is_array($seen[$part] ?? null) ? array_intersect_key($known, $seen[$part]) : $known;
    };
    $ips = [];
    foreach ((array) ($b['ips'] ?? []) as $ip => $k) {
        $ips[] = ['ip' => (string) $ip, 'first' => (int) ($k['first'] ?? 0), 'last' => (int) ($k['last'] ?? 0),
                  'users' => (array) ($k['users'] ?? []), 'services' => watchmanServiceNames((array) ($k['services'] ?? []))];
    }
    usort($ips, fn ($x, $y) => [$y['last'], $x['ip']] <=> [$x['last'], $y['ip']]);
    $fails = [];
    foreach ((array) ($b['fail_ips'] ?? []) as $ip => $k) {
        $fails[] = ['ip' => (string) $ip, 'since' => (int) ($k['since'] ?? 0), 'last' => (int) ($k['last'] ?? 0),
                    'n' => (int) ($k['n'] ?? 0), 'quiet' => (int) ($k['quiet'] ?? 0)];
    }
    usort($fails, fn ($x, $y) => $y['last'] <=> $x['last']);
    $containers = null;
    if (is_array($b['containers'] ?? null)) {
        $special = [];
        $now = $there('containers');
        foreach ($now as $name => $c) {
            if ((array) ($c['tokens'] ?? [])) {
                $special[] = ['name' => (string) $name, 'tokens' => (array) $c['tokens'], 'rights' => watchmanRights((array) $c['tokens']) !== []];
            }
        }
        $containers = ['count' => count($now), 'special' => $special];
    }
    $plugins = [];
    foreach ($there('plugins') as $name => $p) {
        $plugins[] = ['name' => (string) $name, 'source' => (string) ($p['source'] ?? ''), 'version' => $p['version'] ?? null];
    }
    $f = (array) ($b['flash'] ?? []);
    $keys = [];
    foreach ((array) ($f['keys'] ?? []) as $user => $list) {
        foreach ((array) $list as $fp => $k) {
            $keys[] = ['user' => (string) $user, 'fp' => (string) $fp, 'type' => (string) ($k['type'] ?? ''), 'comment' => (string) ($k['comment'] ?? '')];
        }
    }
    $extra = [];
    foreach ((array) ($f['extra'] ?? []) as $file => $x) {
        $extra[] = ['file' => (string) $file, 'size' => (int) ($x['size'] ?? 0), 'time' => (int) ($x['time'] ?? 0)];
    }
    $shares = null;
    if (is_array($b['shares'] ?? null)) {
        $open = [];
        $now = $there('shares');
        foreach ($now as $share => $s) {
            if (($s['smb'] ?? 0) > 0 || ($s['nfs'] ?? 0) > 0) {
                $open[] = ['share' => (string) $share, 'smb' => (int) ($s['smb'] ?? 0), 'nfs' => (int) ($s['nfs'] ?? 0)];
            }
        }
        $shares = ['count' => count($now), 'open' => $open];
    }
    return [
        'ips'        => $ips,
        'fail_ips'   => $fails,
        'containers' => $containers,
        'plugins'    => $plugins,
        'flash'      => ['go' => is_array($f['go'] ?? null) ? ['lines' => count((array) $f['go']['lines'])] : null,
                         'extra' => $extra, 'users' => array_values((array) ($f['users'] ?? [])), 'keys' => $keys],
        'shares'     => $shares,
        'sched'      => watchmanSchedSummary(is_array($b['sched'] ?? null) ? $b['sched'] : null),
    ];
}

/** "What I keep an eye on" of what starts on its own: root's crontab lines, the .cron files, User Scripts, at, agents */
function watchmanSchedSummary(?array $s): ?array
{
    if ($s === null) {
        return null;
    }
    $c = is_array($s['crontab'] ?? null) ? $s['crontab'] : null;
    $files = [];
    foreach ((array) ($s['files'] ?? []) as $name => $f) {
        $files[] = ['file' => (string) $name, 'lines' => count((array) ($f['lines'] ?? []))];
    }
    $scripts = [];
    foreach ((array) ($s['scripts'] ?? []) as $name => $x) {
        $scripts[] = ['name' => (string) $name, 'cron' => (string) ($x['cron'] ?? '')];
    }
    return [
        'crontab' => $c === null ? null : array_slice(array_values(array_unique(array_merge(array_values((array) $c['lines']), array_values((array) $c['twice']),
                                                                                         array_values((array) $c['office'])))), 0, 100),
        'twice'   => $c === null ? 0 : count((array) $c['twice']) + count((array) $c['office']),
        'files'   => is_array($s['files'] ?? null) ? $files : null,
        'scripts' => is_array($s['scripts'] ?? null) ? $scripts : null,
        'at'      => is_array($s['at'] ?? null) ? count($s['at']) : null,
        'agents'  => is_array($s['agents'] ?? null) ? array_map('strval', array_keys($s['agents'])) : null,
    ];
}
