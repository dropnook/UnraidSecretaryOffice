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
 */

const CARETAKER_UNRAID_MIN = '6.12';

desk('caretaker', [
    'start'   => fn () => caretakerScan(),
    'actions' => [
        'refresh'       => fn (array $r) => ['ok' => true, 'state' => caretakerScan()],
        'office_check'  => fn (array $r) => ['ok' => true, 'state' => caretakerScan(true)],
        'office_update' => fn (array $r) => officeUpdate(),
        'menu_name'     => fn (array $r) => caretakerMenuName((string) ($r['name'] ?? ''), (string) ($r['place'] ?? 'menu')),
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
    $state = [
        'time'        => time(),
        'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        'gui'         => houseGuiUrl(),
        'checks'      => $checks,
        'staff'       => $staff,
        'office'      => $office,
        'hired'       => $hired,
    ];
    writeAtomic(deskFile('caretaker'), jsonEncode($state));
    return $state;
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
    if (!isset(OFFICE_MENU_PLACES[$place])) {
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
