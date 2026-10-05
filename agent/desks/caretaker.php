<?php
declare(strict_types=1);

/*
 * The Caretaker — looks after the house itself.
 *
 * Every desk can tell what it needs from the server (desk(..., ['checks' =>
 * …]), see finding() in lib/house.php). The caretaker collects all of that,
 * adds what the office as a whole needs or benefits from, and tells the user
 * what is missing and what is left to do by hand. He only reads — except
 * updating the office itself when asked (lib/officeupdate.php) and naming
 * its entry in Unraid's menu bar (⋯ → "Name in the menu bar").
 *
 * What turns red ("still to do") is also told to Unraid's notifications,
 * once, after it has stayed red for a while (see "Reports to Unraid" below);
 * so it reaches the user without a browser, he walks through the house every
 * 30 minutes on his own.
 */

const CARETAKER_UNRAID_MIN    = '6.12';
const CARETAKER_TOUR_EVERY    = 1800;      // without a browser (it asks every 5 minutes while open): a tour every 30 minutes …
const CARETAKER_TOUR_AFTER    = 600;       // … but not in the first 10 minutes after the agent started (the array settles)
const CARETAKER_NOTIFY_SETTLE = 1800;      // something new to do is told once it has stayed red this long (not a passing state)
const CARETAKER_WATCH_CRON    = '/boot/config/plugins/' . OFFICE_PLUGIN . '/agent-watch.cron';   // written by scripts/agent.sh

desk('caretaker', [
    'start'   => fn () => caretakerScan(),
    'tick'    => fn () => caretakerTick(),
    'actions' => [
        'refresh'       => fn (array $r) => ['ok' => true, 'state' => caretakerScan()],
        'office_check'  => fn (array $r) => ['ok' => true, 'state' => caretakerScan(true)],
        'office_update' => fn (array $r) => officeUpdate(),
        'menu_name'     => fn (array $r) => caretakerMenuName((string) ($r['name'] ?? ''), (string) ($r['place'] ?? 'menu')),
        'notify_set'    => fn (array $r) => caretakerNotifySet($r['on'] ?? null),
    ],
    'checks'  => fn () => caretakerChecks(),
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
    $state = [
        'time'        => time(),
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'gui'         => houseGuiUrl(),
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
 * As a plugin: the look at the agent (scripts/agent.sh writes agent-watch.cron,
 * every 5 minutes job.sh watch) is in root's crontab. Unraid reads plugins'
 * cron files only when someone runs update_cron — not right after a fresh
 * install, so the caretaker sees to it.
 */
function caretakerWatchCron(): void
{
    if (!AS_PLUGIN || !is_file(CARETAKER_WATCH_CRON) || !is_link('/var/log/plugins/' . OFFICE_PLUGIN . '.plg')
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
        caretakerNotifyWrite($file, $data);
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

function caretakerNotifyWrite(string $file, array $data): void
{
    if (!is_dir(dirname($file))) {
        @mkdir(dirname($file), 0755, true);
        @chown(dirname($file), FILE_UID);
        @chgrp(dirname($file), FILE_GID);
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
    caretakerNotifyWrite($file, $data);
    logLine("Caretaker: reports to Unraid's notifications " . ($on ? 'on' : 'off'));
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
    if (!AS_PLUGIN) {           // the stack is run with it
        $out[] = finding('compose_manager', 'recommended', housePlugin('compose.manager'), [], 'apps');
    }
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

    if (!AS_PLUGIN) {           // as a plugin the Unraid login guards the office already
        $auth = readJson(DATA_DIR . '/office/auth.json') ?? [];
        $out[] = finding('pin', 'recommended', !empty($auth['pin_hash']));
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
        if (!str_starts_with($name, US_PREFIX) && !isset(US_RENAMED[$name]) && preg_match('/backup|sicherung/i', $name)) {
            $out[] = finding('other_backup_script', 'hint', null, ['name' => $name], 'userscripts');
        }
    }
    return $out;
}

/**
 * The office's entry in Unraid (plugin only): its label and where it shows —
 * in the menu bar or under Settings → User Utilities. MENU_NAME and
 * MENU_PLACE in the plugin's .cfg on the flash (defaults aren't written), and
 * right away the header of the page in RAM (src/place.php). Unraid shows it
 * on the next page load.
 */
function caretakerMenuName(string $name, string $place): array
{
    if (!AS_PLUGIN) {
        throw new Problem('menu_not_plugin');
    }
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
