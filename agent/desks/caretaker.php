<?php
declare(strict_types=1);

/*
 * The Caretaker — looks after the house itself.
 *
 * Every desk can tell what it needs from the server (desk(..., ['checks' =>
 * …]), see finding() in lib/house.php). The caretaker collects all of that,
 * adds what the office as a whole needs or benefits from, and tells the user
 * what is missing and what is left to do by hand. He only reads — except
 * naming the office's entry in Unraid (⋯ → "Entry in Unraid"); whether a
 * newer release is out he asks GitHub (lib/officeupdate.php), Unraid's
 * plugin manager does the update.
 *
 * What turns red ("still to do") is also told to Unraid's notifications,
 * once, after it has stayed red for a while (see "Reports to Unraid" below);
 * so it reaches the user without a browser, he walks through the house every
 * 30 minutes on his own.
 *
 * Recommendations and notes the user already knows about can be put aside
 * («I know, thanks», see "Noted" below) — kept on the server, so his picture
 * and the Dashboard tile turn green too. Musts can't.
 */

const CARETAKER_UNRAID_MIN    = '6.12';
const CARETAKER_TOUR_EVERY    = 1800;      // without a browser (it asks every 5 minutes while open): a tour every 30 minutes …
const CARETAKER_TOUR_AFTER    = 600;       // … but not in the first 10 minutes after the agent started (the array settles)
const CARETAKER_NOTIFY_SETTLE = 1800;      // something new to do is told once it has stayed red this long (not a passing state)
const CARETAKER_WATCH_CRON    = '/boot/config/plugins/' . OFFICE_PLUGIN . '/agent-watch.cron';   // written by scripts/agent.sh
const CARETAKER_ACK_SIG       = '/^[a-z0-9_-]{1,40}:[a-z0-9_]{1,60}:[0-9a-f]{16}$/';   // desk:id:hash, see caretakerAckSig()
const CARETAKER_ACK_DRIFT     = ['days', 'size'];    // params that change by themselves (an age, a size) — not a new situation
const CARETAKER_ACK_KEEP      = 30 * 86400;          // a noted point that hasn't turned up for this long is forgotten
const CARETAKER_ACK_SEEN      = 86400;               // "still there" is written down at most once a day
const CARETAKER_PROM_ASK      = 300;                 // Prometheus is asked at most this often (the page refreshes every 5 minutes)
// Unraid's own API (7.2+): a node service on a Unix socket; its notification bell and parts of Unraid's web UI need it
const CARETAKER_API_RC        = '/etc/rc.d/rc.unraid-api';      // there: the API belongs to this Unraid
const CARETAKER_API_SOCK      = '/var/run/unraid-api.sock';
const CARETAKER_API_WAIT_MS   = 2000;                // it answers in 0.5–3 ms; nothing within 2 s is no answer
const CARETAKER_API_QUERY     = '{"query":"{ isSSOEnabled }"}';    // a question the API answers without a key (the login page asks it)

desk('caretaker', [
    'start'   => fn () => caretakerScan(),
    'tick'    => fn () => caretakerTick(),
    'actions' => [
        'refresh'       => fn (array $r) => ['ok' => true, 'state' => caretakerScan()],
        'office_check'  => fn (array $r) => ['ok' => true, 'state' => caretakerScan(true)],
        'menu_name'     => fn (array $r) => caretakerMenuName((string) ($r['name'] ?? ''), (string) ($r['place'] ?? 'menu')),
        'notify_set'    => fn (array $r) => caretakerNotifySet($r['on'] ?? null),
        'ack'           => fn (array $r) => caretakerAck($r['sig'] ?? null, true),
        'unack'         => fn (array $r) => caretakerAck($r['sig'] ?? null, false),
    ],
    'checks'  => fn () => caretakerChecks(),
    'metrics' => fn (): array => caretakerMetrics(readJson(deskFile('caretaker')), staffHired()),
]);

function caretakerScan(bool $checkUpdate = false): array
{
    $office = officeUpdateInfo($checkUpdate);
    $t0 = microtime(true);
    $checks = [];
    $staff = [];
    $hired = staffHired();
    foreach (desks() as $id => $desk) {
        // whom to hire: every desk that isn't working here yet says whether it would fit
        if ($desk['fit']) {
            try {
                $staff[$id] = ['hired' => in_array($id, $hired, true)] + ($desk['fit'])();
            } catch (Throwable $e) {
                logLine("$id: fit failed: " . $e->getMessage());
                $staff[$id] = ['hired' => in_array($id, $hired, true)] + fit(false, 'unknown', ['detail' => $e->getMessage()]);
            }
        }
        // what the office needs: only from those who work here
        if (!$desk['checks'] || !in_array($id, $hired, true)) {
            continue;
        }
        try {
            $checks[$id] = array_values(($desk['checks'])());
        } catch (Throwable $e) {
            logLine("$id: checks failed: " . $e->getMessage());
            $checks[$id] = [finding('checks_failed', 'hint', null, ['detail' => $e->getMessage()])];
        }
    }
    try {
        $notify = caretakerNotifyEvaluate($checks);
    } catch (Throwable $e) {
        logLine('Caretaker: reporting to Unraid failed: ' . $e->getMessage());
        $notify = readJson(caretakerNotifyFile()) ?? [];
    }
    try {
        $checks = caretakerAckApply($checks);       // every finding gets its sig, the noted ones "acked"
    } catch (Throwable $e) {
        logLine('Caretaker: reading what was noted failed: ' . $e->getMessage());
    }
    $state = [
        'time'        => time(),
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'checks'      => $checks,
        'staff'       => $staff,
        'office'      => $office,
        'hired'       => $hired,
        'notify'      => caretakerNotifyPublic($notify),
    ];
    writeAtomic(deskFile('caretaker'), jsonEncode($state));
    $GLOBALS['ctLastScan'] = time();
    return $state;
}

/**
 * Every ~150 ms — so nearly always just a comparison. Once a minute it looks
 * whether a tour is due: when nobody had the page open for 30 minutes (the
 * page asks for one every 5 minutes), he walks through the house himself.
 * The tour runs here in the agent, like the page's: the desks answer from
 * what they already know (a few dozen ms); in a process of its own every
 * desk would have to read the whole server again (Ms. Dustdevil's full scan).
 */
function caretakerTick(): void
{
    $now = time();
    if ($now < ($GLOBALS['ctNextLook'] ?? 0)) {
        return;
    }
    $GLOBALS['ctNextLook'] = $now + 60;
    if ($now - (int) ($GLOBALS['ctWatchLook'] ?? 0) >= CARETAKER_TOUR_EVERY) {
        $GLOBALS['ctWatchLook'] = $now;
        caretakerWatchCron();
    }
    if ($now - (int) ($GLOBALS['started'] ?? 0) >= CARETAKER_TOUR_AFTER && $now - (int) ($GLOBALS['ctLastScan'] ?? 0) >= CARETAKER_TOUR_EVERY) {
        $GLOBALS['ctLastScan'] = $now;          // a tour that fails isn't tried again every minute
        caretakerScan();
    }
}

/**
 * The look at the agent (scripts/agent.sh writes agent-watch.cron,
 * every 5 minutes job.sh watch) is in root's crontab. Unraid reads plugins'
 * cron files only when someone runs update_cron — not right after a fresh
 * install, so the caretaker sees to it.
 */
function caretakerWatchCron(): void
{
    if (!is_file(CARETAKER_WATCH_CRON) || !is_link('/var/log/plugins/' . OFFICE_PLUGIN . '.plg')
        || str_contains((string) @file_get_contents('/etc/cron.d/root'), '/scripts/job.sh watch')) {
        return;
    }
    run(['/bin/bash', '/usr/local/sbin/update_cron'], 30);     // its first line is no shebang
    logLine('Caretaker: the look at the agent is in the crontab again');
}

// ===================================================================== reports to Unraid

/*
 * When something "still to do" turns up (a required finding of a desk that
 * works here, known to be missing — "couldn't check" doesn't count), he tells
 * Unraid's notifications: once, as a warning (something to set up, nothing is
 * lost right now — alerts are the backup engine's, for failed runs), after it
 * has stayed red for CARETAKER_NOTIFY_SETTLE (no passing states: the array
 * starting, Docker restarting, a desk just hired), all that is new in one
 * notification. Solved, it is forgotten — back again, it is told again. No
 * "all clear". Switched off on his page, nothing is told; what turned red
 * meanwhile counts as told, so switching on brings no backlog.
 * State: data/caretaker/notify.json.
 */

function caretakerNotifyFile(): string
{
    return DATA_DIR . '/caretaker/notify.json';
}

/**
 * The red findings right now (the scan only asks desks that work here), each
 * under a key that stays the same while it is the same thing: desk, id and
 * what it is about (a container, a share, a path) — not counts or modes.
 *
 * @param array<string, list<array>> $checks  desk => findings, as caretakerScan() collects them
 * @return array<string, array{desk:string, id:string, params:array}>
 */
function caretakerRed(array $checks): array
{
    $red = [];
    foreach ($checks as $desk => $list) {
        foreach ((array) $list as $f) {
            if (($f['level'] ?? '') !== 'required' || ($f['ok'] ?? null) !== false) {
                continue;
            }
            $p = (array) ($f['params'] ?? []);
            $about = $p['name'] ?? $p['share'] ?? $p['path'] ?? '';
            $red[$desk . ':' . $f['id'] . ':' . (is_scalar($about) ? (string) $about : '')] = ['desk' => (string) $desk, 'id' => (string) $f['id'], 'params' => $p];
        }
    }
    return $red;
}

/**
 * One step: what was being followed (key => since, told, desk, id, params)
 * and what is red now → what to follow from now on, and what to tell now.
 *
 * @return array{0: array<string, array>, 1: list<array>}
 */
function caretakerNotifyStep(array $tracked, array $red, int $now, bool $on, int $settle = CARETAKER_NOTIFY_SETTLE): array
{
    $next = [];
    $tell = [];
    foreach ($red as $key => $f) {
        $old = is_array($tracked[$key] ?? null) ? $tracked[$key] : [];
        $t = ['since' => (int) ($old['since'] ?? $now), 'told' => isset($old['told']) ? (int) $old['told'] : null] + $f;
        if ($t['told'] === null && $now - $t['since'] >= $settle) {
            $t['told'] = $now;
            if ($on) {
                $tell[] = $t;
            }
        }
        $next[$key] = $t;
    }
    return [$next, $tell];
}

/**
 * After every tour: follows the red findings and tells Unraid what is new.
 * $file, $now and $lang are there for the tests.
 */
function caretakerNotifyEvaluate(array $checks, ?string $file = null, ?int $now = null, ?string $lang = null): array
{
    $real = $file === null;                 // a test's copy stays out of the agent's log
    $file ??= caretakerNotifyFile();
    $now ??= time();
    $old = readJson($file) ?? [];
    $data = $old + ['on' => true, 'red' => [], 'last' => null];
    [$data['red'], $tell] = caretakerNotifyStep((array) $data['red'], caretakerRed($checks), $now, $data['on'] !== false);
    if ($tell) {
        $sent = caretakerNotifySend($tell, $lang ?? officeNotifyLang());
        $data['last'] = ['time' => $now, 'sent' => $sent,
                         'items' => array_map(fn ($t) => ['desk' => $t['desk'], 'id' => $t['id'], 'params' => $t['params']], $tell)];
        if ($real) {
            logLine('Caretaker: ' . ($sent ? 'told' : 'could not tell') . " Unraid's notifications about " . count($tell) . ' new thing(s) to do: '
                . implode(', ', array_map(fn ($t) => "{$t['desk']}.{$t['id']}", $tell)));
        }
    }
    if ($data !== $old) {
        caretakerWrite($file, $data);
    }
    return $data;
}

/** One notification for everything new: the short list in the bell, each with what to do in the long text */
function caretakerNotifySend(array $items, string $lang): bool
{
    $titles = [];
    $lines = [];
    foreach ($items as $f) {
        $who = officeNotifyText($f['desk'], 'name', [], $lang) ?: $f['desk'];
        $what = officeNotifyText($f['desk'], "check.{$f['id']}", $f['params'], $lang) ?: $f['id'];
        $how = officeNotifyText($f['desk'], "check.{$f['id']}_how", $f['params'], $lang);
        $titles[] = "$who — $what";
        $lines[] = "• $who — $what" . ($how !== '' ? "\n  $how" : '');
    }
    $n = count($items);
    $list = implode('; ', array_slice($titles, 0, 3)) . ($n > 3 ? ' ' . officeNotifyText('caretaker', 'notify.more', ['n' => $n - 3], $lang) : '');
    return officeNotify(
        officeNotifyText('caretaker', 'notify.subject', ['n' => $n], $lang),
        officeNotifyText('caretaker', 'notify.description', ['list' => $list], $lang),
        'warning',
        implode("\n", $lines) . "\n\n" . officeNotifyText('caretaker', 'notify.footer', [], $lang),
        officeNotifyLink('#/caretaker'),
    );
}

/** A file of his own in data/caretaker (notify.json, acks.json) */
function caretakerWrite(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
        @lchown(dirname($file), FILE_UID);
        @lchgrp(dirname($file), FILE_GID);
    }
    writeAtomic($file, jsonEncode($data));
}

/** For the page: switched on?, can Unraid be told at all, the last report */
function caretakerNotifyPublic(array $data): array
{
    return [
        'on'        => ($data['on'] ?? true) !== false,
        'available' => is_executable(OFFICE_NOTIFY_BIN),
        'waiting'   => count(array_filter((array) ($data['red'] ?? []), fn ($t) => is_array($t) && ($t['told'] ?? null) === null)),
        'last'      => is_array($data['last'] ?? null) ? $data['last'] : null,
    ];
}

/** The switch on his page: report to Unraid's notifications or not */
function caretakerNotifySet(mixed $on): array
{
    if (!is_bool($on)) {
        throw new Problem('bad_request');
    }
    $file = caretakerNotifyFile();
    $data = readJson($file) ?? [];
    $data['on'] = $on;
    caretakerWrite($file, $data);
    logLine("Caretaker: reports to Unraid's notifications " . ($on ? 'on' : 'off'));
    return ['ok' => true, 'state' => caretakerScan()];
}

// ===================================================================== noted («I know, thanks»)

/*
 * A recommendation or a note the user already knows about can be put aside:
 * it moves to "Noted" on his page and no longer counts — not in his bubble,
 * his picture, the reception or the Dashboard tile. Kept on the server
 * (data/caretaker/acks.json), because his picture and the tile are worked out
 * from his state file, the same for every browser. Each under the finding's
 * signature: desk, id, level and params (without those that change by
 * themselves, CARETAKER_ACK_DRIFT) — so it comes back as soon as its situation
 * changes (another version, another container). Once it is in place the note
 * is forgotten: back again, it is shown again (like the reports to Unraid).
 * One that doesn't turn up at all any more (its desk let go, a passing state)
 * is kept for CARETAKER_ACK_KEEP. Musts ("required") are never put aside —
 * they are what Unraid's notifications are about, and they stay red.
 */

function caretakerAckFile(): string
{
    return DATA_DIR . '/caretaker/acks.json';
}

/** What a finding is about, as one short key: desk:id:hash of its level and params */
function caretakerAckSig(string $desk, array $f): string
{
    $p = array_diff_key((array) ($f['params'] ?? []), array_flip(CARETAKER_ACK_DRIFT));
    ksort($p);
    return $desk . ':' . (string) ($f['id'] ?? '') . ':' . substr(sha1(jsonEncode([(string) ($f['level'] ?? ''), $p])), 0, 16);
}

/** Can it be put aside? Recommendations and notes that aren't in place */
function caretakerAckable(array $f): bool
{
    return in_array($f['level'] ?? '', ['recommended', 'hint'], true) && ($f['ok'] ?? null) !== true;
}

/** @return array<string, array{desk:string, id:string, time:int, seen:int}> sig => note, from the file (anything odd left out) */
function caretakerAckRead(string $file): array
{
    $out = [];
    foreach ((array) ((readJson($file) ?? [])['acks'] ?? []) as $sig => $a) {
        if (is_string($sig) && preg_match(CARETAKER_ACK_SIG, $sig) && is_array($a)) {
            $out[$sig] = ['desk' => (string) ($a['desk'] ?? ''), 'id' => (string) ($a['id'] ?? ''),
                          'time' => (int) ($a['time'] ?? 0), 'seen' => (int) ($a['seen'] ?? 0)];
        }
    }
    return $out;
}

/**
 * One tour: every finding gets its sig, the noted ones "acked" (never a must:
 * the level is part of the sig, and only those that can be put aside are
 * marked); a note whose point is in place now is forgotten, one still there
 * marked as seen (once a day), one gone for CARETAKER_ACK_KEEP dropped.
 *
 * @param array<string, list<array>> $checks  desk => findings, as caretakerScan() collects them
 * @return array{0: array<string, list<array>>, 1: array<string, array>}  the checks marked, the notes from now on
 */
function caretakerAckStep(array $checks, array $acks, int $now): array
{
    $present = $done = [];
    foreach ($checks as $desk => $list) {
        foreach ((array) $list as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $sig = caretakerAckSig((string) $desk, $f);
            $checks[$desk][$i]['sig'] = $sig;
            if (isset($acks[$sig]) && caretakerAckable($f)) {
                $checks[$desk][$i]['acked'] = true;
                $present[$sig] = true;
            } elseif (($f['ok'] ?? null) === true) {
                $done[$sig] = true;
            }
        }
    }
    foreach ($acks as $sig => $a) {
        if (isset($present[$sig])) {
            if ($now - $a['seen'] >= CARETAKER_ACK_SEEN) {
                $acks[$sig]['seen'] = $now;
            }
        } elseif (isset($done[$sig]) || $now - $a['seen'] > CARETAKER_ACK_KEEP) {
            unset($acks[$sig]);
        }
    }
    return [$checks, $acks];
}

/** After every tour: marks the noted findings, keeps the file tidy. $file and $now are there for the tests. */
function caretakerAckApply(array $checks, ?string $file = null, ?int $now = null): array
{
    $file ??= caretakerAckFile();
    $old = caretakerAckRead($file);
    [$checks, $acks] = caretakerAckStep($checks, $old, $now ?? time());
    if ($acks !== $old) {
        try {
            caretakerWrite($file, ['acks' => $acks]);
        } catch (Throwable $e) {             // the marks still count; tidied on the next tour
            logLine('Caretaker: could not write what was noted: ' . $e->getMessage());
        }
    }
    return $checks;
}

/** «I know, thanks» ($on) or «Bring back» for one finding, by the sig the page got with it */
function caretakerAck(mixed $sig, bool $on): array
{
    if (!is_string($sig) || !preg_match(CARETAKER_ACK_SIG, $sig)) {
        throw new Problem('bad_request');
    }
    $file = caretakerAckFile();
    if ($on) {
        $found = null;
        foreach (caretakerScan()['checks'] as $desk => $list) {     // as things are right now, only desks that work here
            foreach ($list as $f) {
                if (($f['sig'] ?? null) === $sig) {
                    $found = ['desk' => (string) $desk] + $f;
                }
            }
        }
        if ($found === null || ($found['ok'] ?? null) === true) {
            throw new Problem('ack_gone');
        }
        if (!caretakerAckable($found)) {
            throw new Problem('ack_required');
        }
        $acks = caretakerAckRead($file);
        $acks[$sig] = ['desk' => $found['desk'], 'id' => (string) $found['id'], 'time' => time(), 'seen' => time()];
        $what = "{$found['desk']}.{$found['id']}";
    } else {
        $acks = caretakerAckRead($file);
        if (!isset($acks[$sig])) {
            return ['ok' => true, 'state' => caretakerScan()];     // already back (another browser)
        }
        $what = "{$acks[$sig]['desk']}.{$acks[$sig]['id']}";
        unset($acks[$sig]);
    }
    caretakerWrite($file, ['acks' => $acks]);
    logLine("Caretaker: $what " . ($on ? 'noted («I know, thanks»)' : 'brought back'));
    return ['ok' => true, 'state' => caretakerScan()];
}

/** What the office as a whole needs or benefits from */
function caretakerChecks(): array
{
    $out = [];
    $version = preg_match('/version="([^"]+)"/', (string) @file_get_contents('/etc/unraid-version'), $m) ? $m[1] : null;
    $out[] = finding('unraid', 'required', $version === null ? null : version_compare($version, CARETAKER_UNRAID_MIN, '>='),
        ['version' => $version ?? '?', 'min' => CARETAKER_UNRAID_MIN]);

    // the office itself: a newer release?
    $office = officeUpdateInfo();
    $out[] = finding('office_update', 'recommended', $office['newer'] ? false : true,
        ['version' => AGENT_VERSION, 'latest' => (string) ($office['latest'] ?? '')], '#/caretaker');

    $out[] = finding('community_apps', 'recommended', housePlugin('community.applications'), [], 'plugins');
    if (!in_array('advisor', staffHired(), true)) {      // once hired, the consultant looks after them
        $out[] = finding('fix_common_problems', 'recommended', housePlugin('fix.common.problems'), [], 'apps');
        $out[] = finding('files_viewer', 'recommended', housePlugin('filesviewer'), [], 'apps');
    }

    // do Unraid's notifications (backup errors, disks …) reach anybody?
    $cfg = readCfg('/boot/config/plugins/dynamix/dynamix.cfg', true);
    $alert = (int) ($cfg['notify']['alert'] ?? 0);
    $mail = ($alert & 2) && !empty($cfg['ssmtp']['server']) && !empty($cfg['ssmtp']['RcptTo']);
    $agents = ($alert & 4) && (glob('/boot/config/plugins/dynamix/notifications/agents/*.sh') ?: []);
    $out[] = finding('notifications', 'recommended', $mail || $agents, [], 'notifications');
    $subject = trim((string) ($cfg['ssmtp']['Subject'] ?? ''));
    if ($mail && $subject !== '' && stripos($subject, hostname()) === false) {
        $out[] = finding('mail_subject', 'hint', false, ['subject' => $subject, 'host' => hostname()], 'notifications');
    }
    // Unraid's own API service: its notification bell (the office's reports show there too) and parts of its web UI need it
    if ($api = caretakerApiFinding(caretakerApiUp())) {
        $out[] = $api;
    }

    // Unraid 7.3.2: a container without a picture makes its Docker page and Dashboard flood /var/log
    if (function_exists('cleanupIconLoopRisk')) {
        $loop = cleanupIconLoopRisk();
        if ($loop['affected']) {
            $out[] = finding('icon_loop', 'recommended', !$loop['risk'], ['version' => $loop['version'], 'n' => $loop['containers']],
                in_array('cleanup', staffHired(), true) ? '#/cleanup/tidy' : 'docker');      // her rooms: «Tidying up»
        }
    }

    // other backup tools: worth knowing, so nothing runs twice by accident
    foreach (housePlugins() as $p) {
        if (preg_match('/backup/i', $p['name'])) {
            $out[] = finding('other_backup_plugin', 'hint', null, ['name' => $p['name']], 'plugins');
        }
    }
    foreach (houseContainers() as $c) {
        if (preg_match('/backup|duplicati|restic|borg|urbackup|duplicacy/i', $c['name'] . ' ' . $c['image'])) {
            $out[] = finding('other_backup_container', 'hint', null, ['name' => $c['name'], 'image' => $c['image']], 'docker');
        }
    }
    foreach (glob('/boot/config/plugins/user.scripts/scripts/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        if (preg_match('/backup|sicherung/i', $name)) {
            $out[] = finding('other_backup_script', 'hint', null, ['name' => $name], 'userscripts');
        }
    }
    array_push($out, ...caretakerMonitoringChecks());
    return $out;
}

/**
 * Recommended, not required: the office works without the API — but Unraid's own bell, where the office's reports show
 * too, doesn't (mail and push agents still go: Unraid's notify script sends those itself). Null: nothing to say.
 */
function caretakerApiFinding(?bool $up): ?array
{
    return $up === null ? null : finding('api_down', 'recommended', $up, [], 'management');
}

/**
 * Is Unraid's API service running? One question it answers without a key — `isSSOEnabled`, which Unraid's login page
 * asks too — straight over its Unix socket (PHP's curl in-process: no key, no header but the content type, no nginx,
 * no network; ~2 ms, at most CARETAKER_API_WAIT_MS). Never in the night shift (the team lead's checks run in the agent
 * only). Null: no API on this Unraid (before 7.2 without the Connect plugin) or no curl — nothing to say.
 */
function caretakerApiUp(string $sock = CARETAKER_API_SOCK, string $rc = CARETAKER_API_RC, int $waitMs = CARETAKER_API_WAIT_MS): ?bool
{
    if (!is_file($rc) || !function_exists('curl_init')) {
        return null;
    }
    clearstatcache(true, $sock);
    $st = @lstat($sock);
    if (!$st || ($st['mode'] & 0170000) !== 0140000) {
        return false;                       // no socket: the service isn't there
    }
    $body = '';
    $c = curl_init('http://localhost/graphql');
    curl_setopt_array($c, [
        CURLOPT_UNIX_SOCKET_PATH => $sock,
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => CARETAKER_API_QUERY,
        CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
        CURLOPT_FOLLOWLOCATION    => false,
        CURLOPT_PROXY             => '',
        CURLOPT_NOPROXY           => '*',
        CURLOPT_CONNECTTIMEOUT_MS => min(1000, $waitMs),
        CURLOPT_TIMEOUT_MS        => $waitMs,
        CURLOPT_WRITEFUNCTION     => function ($c, string $chunk) use (&$body): int {
            if (strlen($body) > 65536) {
                return 0;                   // far more than the answer is: stop reading (no JSON then)
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    curl_exec($c);
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    unset($c);
    $j = $status === 200 ? json_decode($body, true) : null;
    return is_array($j) && is_bool($j['data']['isSSOEnabled'] ?? null);
}

// ===================================================================== monitoring

/*
 * Once monitoring is in use here — a Node Exporter or a Prometheus, found the
 * consultant's way (ADVISOR_EXTERNALS) —, he follows the office's numbers
 * along the chain: fresh in their folder (lib/metrics.php) → the Node
 * Exporter reads that folder → Prometheus answers → it has an "up" target for
 * the Node Exporter. Without either of them nothing is missing. He never
 * writes prometheus.yml: the consultant has one ready to copy.
 */
function caretakerMonitoringChecks(): array
{
    if (!function_exists('advisorFindContainer')) {
        return [];
    }
    $containers = houseContainers();
    $node = null;
    if (housePlugin(ADVISOR_EXTERNALS['nodeexporter']['plugin'])) {
        $node = ['kind' => 'plugin', 'there' => true, 'name' => 'Prometheus Node Exporter'];
    } elseif ($c = advisorFindContainer(ADVISOR_EXTERNALS['nodeexporter'], $containers)) {
        $node = ['kind' => 'container', 'there' => true, 'name' => $c['name']];
    }
    $prom = advisorFindContainer(ADVISOR_EXTERNALS['prometheus'], $containers);
    if ($node === null && $prom === null) {
        return [];
    }
    $advisor = in_array('advisor', staffHired(), true) ? '#/advisor' : 'docker';
    $dir = metricsDir();
    $out = [finding('metrics_written', 'recommended', metricsFresh($dir), ['dir' => $dir])];
    if ($node !== null) {
        $out[] = finding('metrics_textfile', 'recommended',
            advisorNodeTextfile($node, $node['kind'] === 'container' ? houseInspect($node['name']) : null), ['name' => $node['name'], 'dir' => METRICS_HOST_DIR], $advisor);
    }
    if ($prom === null) {
        $out[] = finding('metrics_no_prometheus', 'hint', null, [], $advisor);
        return $out;
    }
    $p = caretakerPrometheus($prom);
    $out[] = finding('metrics_prometheus', 'recommended', $p['ready'], ['name' => $prom['name']], 'docker');
    if ($p['ready'] && $p['node'] === 'none') {
        $ip = preg_match('#^https?://([^/:]+)#', (string) houseGuiUrl(), $m) ? $m[1] : '<server-ip>';
        $out[] = finding('metrics_node_job', 'recommended', false, ['target' => "$ip:9100"], $advisor);
    } elseif ($p['ready'] && $p['node'] !== null) {
        $out[] = finding('metrics_node_target', 'recommended', $p['node']['up'], ['target' => $p['node']['target']]);
    }
    return $out;
}

/**
 * Asks the Prometheus container (in the host's network, seconds at most;
 * at most every CARETAKER_PROM_ASK) whether it is ready and how its target
 * for the Node Exporter is.
 *
 * @param array{name:string, running:bool} $c
 * @return array{ready:?bool, node:'none'|array{up:bool, target:string}|null}  ready null: couldn't tell; node null: not asked or an odd answer
 */
function caretakerPrometheus(array $c): array
{
    if (!$c['running']) {
        return ['ready' => false, 'node' => null];
    }
    $cached = $GLOBALS['ctProm'][$c['name']] ?? null;
    if ($cached !== null && time() - $cached['at'] < CARETAKER_PROM_ASK) {
        return $cached['result'];
    }
    $where = caretakerPrometheusUrl(houseInspect($c['name']));
    $result = ['ready' => null, 'node' => null];
    if ($where !== null) {
        [$url, $own] = $where;
        [$exit, $code] = hostNet(['curl', '-s', '-m', '3', '-o', '/dev/null', '-w', '%{http_code}', "$url/-/ready"], 10);
        $ready = $exit === 0 && trim($code) === '200';
        // on an address of its own (br0) the host may not be allowed to reach it: that is "couldn't tell"
        $result['ready'] = $ready ? true : ($own && $exit !== 0 ? null : false);
        if ($ready) {
            [$exit, $body] = hostNet(['curl', '-s', '-m', '5', "$url/api/v1/targets?state=active"], 10);
            $result['node'] = $exit === 0 ? caretakerNodeTarget(json_decode($body, true)) : null;
        }
    }
    $GLOBALS['ctProm'][$c['name']] = ['at' => time(), 'result' => $result];
    return $result;
}

/**
 * Where the host reaches Prometheus, from docker inspect: its port (9090, or
 * --web.listen-address), published on the host or in the host's network →
 * 127.0.0.1; otherwise the container's own address (own = true).
 *
 * @return array{0:string, 1:bool}|null  the address, whether it is the container's own
 */
function caretakerPrometheusUrl(?array $i): ?array
{
    if ($i === null) {
        return null;
    }
    $port = 9090;
    foreach ((array) ($i['Args'] ?? []) as $a) {
        if (is_string($a) && preg_match('/^--web\.listen-address=\S*:(\d{1,5})$/D', $a, $m)) {
            $port = (int) $m[1];
        }
    }
    if (($i['HostConfig']['NetworkMode'] ?? '') === 'host') {
        return ["http://127.0.0.1:$port", false];
    }
    foreach ((array) ($i['NetworkSettings']['Ports']["$port/tcp"] ?? []) as $b) {
        $host = (string) ($b['HostIp'] ?? '');
        $hostPort = (string) ($b['HostPort'] ?? '');
        if (preg_match('/^\d{1,5}$/D', $hostPort) && (in_array($host, ['', '0.0.0.0', '::'], true) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))) {
            return ['http://' . (in_array($host, ['', '0.0.0.0', '::'], true) ? '127.0.0.1' : $host) . ":$hostPort", false];
        }
    }
    foreach ((array) ($i['NetworkSettings']['Networks'] ?? []) as $net) {
        $ip = (string) ($net['IPAddress'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ["http://$ip:$port", true];
        }
    }
    return null;
}

/**
 * The Node Exporter's target in Prometheus' /api/v1/targets: a job named
 * like "node" or an address on port 9100 — up when one of them is.
 *
 * @return 'none'|array{up:bool, target:string}|null  null: not Prometheus' answer
 */
function caretakerNodeTarget(mixed $j): string|array|null
{
    if (!is_array($j) || ($j['status'] ?? '') !== 'success' || !is_array($j['data']['activeTargets'] ?? null)) {
        return null;
    }
    $found = null;
    foreach ($j['data']['activeTargets'] as $t) {
        if (!is_array($t)) {
            continue;
        }
        $job = (string) ($t['labels']['job'] ?? $t['scrapePool'] ?? '');
        $url = (string) ($t['scrapeUrl'] ?? '');
        if (!preg_match('/node/i', $job) && parse_url($url, PHP_URL_PORT) !== 9100) {
            continue;
        }
        $up = ($t['health'] ?? '') === 'up';
        $target = substr((string) ($t['labels']['instance'] ?? parse_url($url, PHP_URL_HOST) ?? ''), 0, 100);
        if ($found === null || $up && !$found['up']) {
            $found = ['up' => $up, 'target' => $target];
        }
    }
    return $found ?? 'none';
}

/**
 * The team lead's numbers for Prometheus (lib/metrics.php, once a minute):
 * open findings by level as the Dashboard tile counts them — only desks that
 * work here, not in place, not put aside («I know, thanks»); hints too.
 */
function caretakerMetrics(?array $state, array $hired): array
{
    if (!$state) {
        return [];
    }
    $hired = array_flip($hired);
    $open = ['required' => 0, 'recommended' => 0, 'hint' => 0];
    foreach ((array) ($state['checks'] ?? []) as $desk => $list) {
        if (!isset($hired[$desk])) {
            continue;
        }
        foreach ((array) $list as $f) {
            $level = is_array($f) ? (string) ($f['level'] ?? '') : '';
            if (isset($open[$level]) && ($f['ok'] ?? null) !== true && empty($f['acked'])) {
                $open[$level]++;
            }
        }
    }
    return [
        metricsGauge('uso_caretaker_open_findings', 'The team lead\'s open points by level: still to do (required), recommended, good to know (hint) — without what was put aside',
            array_map(fn ($level) => [['level' => $level], $open[$level]], array_keys($open))),
        metricsGauge('uso_caretaker_checked_timestamp_seconds', 'When the team lead last walked through the house (the numbers above are from then)', (int) ($state['time'] ?? 0)),
    ];
}

/**
 * The office's entry in Unraid: its label and where it shows —
 * in the menu bar or under Settings → User Utilities. MENU_NAME and
 * MENU_PLACE in the plugin's .cfg on the flash (defaults aren't written), and
 * right away the header of the page in RAM (src/place.php). Unraid shows it
 * on the next page load.
 */
function caretakerMenuName(string $name, string $place): array
{
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (!officeMenuNameValid($name)) {
        throw new Problem('menu_name_bad', ['max' => OFFICE_MENU_MAX]);
    }
    if (!array_key_exists($place, OFFICE_MENU_PLACES)) {
        throw new Problem('bad_request');
    }
    $lines = is_file(OFFICE_PLUGIN_CFG) ? (file(OFFICE_PLUGIN_CFG, FILE_IGNORE_NEW_LINES) ?: []) : [];
    $lines = array_values(array_filter($lines, fn ($l) => !preg_match('/^\s*MENU_(NAME|PLACE)\s*=/', $l)));
    if ($name !== OFFICE_MENU_DEFAULT) {
        $lines[] = 'MENU_NAME="' . $name . '"';
    }
    if ($place !== 'menu') {
        $lines[] = 'MENU_PLACE="' . $place . '"';
    }
    writeAtomic(OFFICE_PLUGIN_CFG, implode("\n", $lines) . "\n", 0644, 0, 0);
    if (!officeMenuPageApply(OFFICE_DIR, $name, $place)) {
        throw new Problem('menu_page_failed');
    }
    logLine("Menu entry: $name ($place)");
    return ['ok' => true, 'name' => $name, 'place' => $place, 'url' => officeMenuUrl($place)];
}
