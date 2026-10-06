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
 * «I know, thanks»). The page reads data/watchman.json (watchmanPageState()).
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
];

// syslog lines: Unraid's "Oct  6 08:54:00 Tower …" (or an ISO time, if rsyslog is set so)
const WATCH_TIME_SYSLOG = '/^([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d\d:\d\d:\d\d)\s/';
const WATCH_TIME_ISO    = '/^(\d{4}-\d\d-\d\d)[T ](\d\d:\d\d:\d\d)(?:\.\d+)?(Z|[+-]\d\d:?\d\d)?\s/';
// the web login (dynamix/include/.login.php, my_logger → tag "webgui"): the address is the last " from …" (the name is the user's input)
const WATCH_WEB         = '/\swebgui:\s+(Successful|Unsuccessful) login user (.*) from (\S+?)\.?(?:\s|$)/i';
const WATCH_SSH_OK      = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Accepted (\S+) for (\S+) from (\S+) port \d+/';
const WATCH_SSH_FAIL    = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Failed (\S+) for (invalid user )?(\S+) from (\S+) port \d+/';
const WATCH_SSH_INVALID = '/\ssshd[\w-]*(?:\[\d+\])?:\s+Invalid user (.*) from (\S+) port \d+/';
// docker inspect: name, image, HostConfig and mounts as JSON (tabs and newlines inside are escaped)
const WATCH_INSPECT     = "{{json .Name}}\t{{json .Config.Image}}\t{{json .HostConfig}}\t{{json .Mounts}}";

desk('watchman', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'start'   => fn () => watchmanRecover(),
    'tick'    => fn () => watchmanTick(),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => watchmanPageState()],
        'round'   => fn (array $r) => watchmanRoundNow(),
        'ack'     => fn (array $r) => watchmanAck($r['id'] ?? null),
        'ack_all' => fn (array $r) => watchmanAck('*'),
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
    foreach (['baseline' => 'baseline.json', 'book' => 'book.json', 'state' => 'state.json', 'seen' => 'seen.json'] as $k => $file) {
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
        $r = watchmanRound(watchmanPaths(), $dir, $since);
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
 * $acks: the team lead's notes (tests); $notify false: tell nobody.
 *
 * @return array{fresh: bool, added: list<string>, told: list<array>, summary: array}
 */
function watchmanRound(array $paths, string $dir, int $hired, ?int $now = null, ?callable $docker = null, bool $notify = true, ?string $acks = null): array
{
    $t0 = microtime(true);
    $now ??= time();
    $snap = watchmanLoad($dir);
    $fresh = !is_array($snap['baseline']) || (int) ($snap['baseline']['hired'] ?? -1) !== $hired;
    $known = watchmanKnownUsers($paths['etc_passwd']);
    [$events, $pos, $read] = watchmanReadLogins($paths['syslog'], $fresh ? null : ($snap['state']['syslog'] ?? null), $fresh, $known, $now);
    $seen = [
        'containers' => $docker ? $docker() : watchmanContainers(),
        'plugins'    => watchmanPlugins($paths['plugins']),
        'flash'      => watchmanFlash($paths),
        'shares'     => watchmanShares($paths),
    ];

    return watchmanLocked($dir, function () use ($dir, $hired, $now, $fresh, $events, $pos, $read, $seen, $notify, $acks, $t0): array {
        $old = watchmanLoad($dir);
        $old['seen'] = readJson("$dir/seen.json");
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
            watchmanTeamLeadNotes($b, $book, is_array($old['seen']) ? $old['seen'] : $observed, $now, $acks);
            $added = array_merge(
                watchmanLogins($b, $book, $st, $events, false),
                watchmanContainersCompare($b['containers'], $seen['containers'], $book, $now),
                watchmanPluginsCompare($b['plugins'], $seen['plugins'], $book, $now),
                watchmanFlashCompare($b['flash'], $seen['flash'], $book, $now),
                watchmanSharesCompare($b['shares'], $seen['shares'], $book, $now),
            );
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
                        'docker' => $seen['containers'] !== null, 'shares' => $seen['shares'] !== null, 'added' => count($added)];
        watchmanSave($dir, $old, ['baseline' => $b, 'book' => $book, 'state' => $st, 'seen' => $observed]);
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
          'flash' => $seen['flash'], 'shares' => null];
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
        $out[$name] = ['image' => (string) json_decode($f[1]), 'tokens' => watchmanContainerTokens($hc, is_array($mounts) ? $mounts : [])];
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
 * only names, addresses and numbers — the same in every language.
 */
function watchmanText(array $e): array
{
    $p = (array) ($e['p'] ?? []);
    $services = implode(', ', watchmanServiceNames((array) ($p['services'] ?? [])));
    $list = fn (string $k) => implode(', ', array_map('strval', (array) ($p[$k] ?? [])));
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
        $out[] = finding($kind, 'recommended', false, ['n' => count($list)] + watchmanText(end($list)), '#/watchman');
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
    foreach (WATCH_KINDS as $kind => [, $important]) {
        if (!$important) {
            continue;
        }
        $new = [];
        foreach ($book as $i => $e) {
            if ($e['kind'] === $kind && watchmanOpen($e) && empty($e['told'])) {
                $new[] = $i;
            }
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

/** One notification for a kind: the bell's line, the newest in its words, each entry with its time */
function watchmanNotifySend(string $kind, array $entries, string $lang): bool
{
    usort($entries, fn ($a, $b) => (int) $b['last'] <=> (int) $a['last']);
    $n = count($entries);
    $params = ['n' => $n] + watchmanText($entries[0]);
    $lines = [];
    foreach (array_slice($entries, 0, 10) as $e) {
        $lines[] = '• ' . officeNotifyText('watchman', "entry.$kind", ['n' => (int) $e['count']] + watchmanText($e), $lang)
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
    return [
        ['name' => 'uso_watchman_open_findings', 'type' => 'gauge', 'help' => 'Night watchman findings not noted yet', 'samples' => $samples],
        ['name' => 'uso_watchman_last_round_timestamp_seconds', 'type' => 'gauge', 'help' => 'When the night watchman last finished a round (unix time)',
         'samples' => $last > 0 ? [[[], $last]] : []],
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
                   'noted' => $e['noted'] ?? null, 'by' => $e['by'] ?? null, 'told' => $e['told'] ?? null];
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
        'watch'    => $onWatch ? watchmanSummary($b) : null,
        'notified' => $st['last_notify'] ?? null,
        'limits'   => ['every' => WATCH_EVERY, 'burst' => WATCH_FAIL_BURST, 'window' => WATCH_FAIL_WINDOW,
                       'quiet' => WATCH_NOTIFY_QUIET, 'keep' => WATCH_BOOK_MAX, 'days' => WATCH_BOOK_DAYS],
    ];
    if ($write && is_dir(DATA_DIR)) {
        writeAtomic(deskFile('watchman'), jsonEncode($state));
    }
    return $state;
}

/** "What I keep an eye on": the baseline, summarised */
function watchmanSummary(array $b): array
{
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
        foreach ($b['containers'] as $name => $c) {
            if ((array) ($c['tokens'] ?? [])) {
                $special[] = ['name' => (string) $name, 'tokens' => (array) $c['tokens'], 'rights' => watchmanRights((array) $c['tokens']) !== []];
            }
        }
        $containers = ['count' => count($b['containers']), 'special' => $special];
    }
    $plugins = [];
    foreach ((array) ($b['plugins'] ?? []) as $name => $p) {
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
        foreach ($b['shares'] as $share => $s) {
            if (($s['smb'] ?? 0) > 0 || ($s['nfs'] ?? 0) > 0) {
                $open[] = ['share' => (string) $share, 'smb' => (int) ($s['smb'] ?? 0), 'nfs' => (int) ($s['nfs'] ?? 0)];
            }
        }
        $shares = ['count' => count($b['shares']), 'open' => $open];
    }
    return [
        'ips'        => $ips,
        'fail_ips'   => $fails,
        'containers' => $containers,
        'plugins'    => $plugins,
        'flash'      => ['go' => is_array($f['go'] ?? null) ? ['lines' => count((array) $f['go']['lines'])] : null,
                         'extra' => $extra, 'users' => array_values((array) ($f['users'] ?? [])), 'keys' => $keys],
        'shares'     => $shares,
    ];
}
