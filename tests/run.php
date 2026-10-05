<?php
declare(strict_types=1);

/*
 * The office's tests — run them on the Unraid server, with Unraid's own PHP:
 *
 *   php /mnt/user/appdata/UnraidSecretaryOffice/tests/run.php
 *
 * They change nothing on the server: what writes files works on copies in a
 * temporary folder. Two parts:
 *   logic    the tricky functions (cron, snapshot retention, Emby detection,
 *            the gather's settings, User Scripts schedules, the plugin's cron file,
 *            the menu bar's label, reports to Unraid's notifications)
 *   strings  German and English have the same keys, Italian has every English
 *            key, no language has keys English lacks, placeholders and plurals
 *            match English, and every text the code asks for exists (desk.js,
 *            checks, errors)
 * Exit code 0 when everything passes.
 */

define('AGENT_LIBRARY_ONLY', 1);
require dirname(__DIR__) . '/agent/agent.php';
date_default_timezone_set('Europe/Zurich');

$GLOBALS['results'] = ['pass' => 0, 'fail' => []];

function check(string $what, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['results']['pass']++;
    } else {
        $GLOBALS['results']['fail'][] = $what . ($detail !== '' ? " — $detail" : '');
    }
}

function same(string $what, mixed $expected, mixed $actual): void
{
    check($what, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

// ===================================================================== logic

function testCron(): void
{
    foreach (['0 3 * * *', '*/15 * * * *', '0 3 * * 1-6', '5,35 2 1 */2 7', '0-5/2 3 * * *'] as $c) {
        check("cron valid: $c", cronValid($c));
    }
    foreach (['61 3 * * *', '0 24 * * *', '0 3 0 * *', '0 3 * 13 *', '*/0 * * * *', '0 3 * * 8', '0 3 * *', '0 3 * * * rm -rf /', 'a b c d e'] as $c) {
        check("cron refused: $c", !cronValid($c));
    }
    $now = strtotime('2026-10-03 19:47:00');
    $cases = [
        '0 * * * *'    => ['2026-10-03 19:00', '2026-10-03 20:00'],
        '30 2 * * *'   => ['2026-10-03 02:30', '2026-10-04 02:30'],
        '0 3 * * 0'    => ['2026-09-27 03:00', '2026-10-04 03:00'],
        '*/15 * * * *' => ['2026-10-03 19:45', '2026-10-03 20:00'],
        '0 3 1 * *'    => ['2026-10-01 03:00', '2026-11-01 03:00'],
        '0 4 * * 1-5'  => ['2026-10-02 04:00', '2026-10-05 04:00'],
    ];
    foreach ($cases as $c => [$prev, $next]) {
        same("cron previous $c", $prev, date('Y-m-d H:i', (int) cronPrevious($c, $now)));
        same("cron next $c", $next, date('Y-m-d H:i', (int) cronNext($c, $now)));
    }
}

function testRetention(): void
{
    $now = strtotime('2026-10-03 19:47:00');
    $mk = fn (string $ds, string $name) => ['id' => "zfs:$ds@$name", 'fs' => 'zfs', 'ds' => $ds, 'vol' => "zfs:$ds", 'name' => $name, 'docker' => false];
    $all = [];
    for ($h = 0; $h < 30; $h++) {
        $all[] = $mk('master/appdata', snapPlanName('hourly', $now - $h * 3600));
        $all[] = $mk('master/appdata/immich', snapPlanName('hourly', $now - $h * 3600));
    }
    // others that must never go
    $all[] = $mk('master/appdata', 'unraidbackup-20261003-0200');
    $all[] = $mk('master/appdata', 'auto-hourly2-20261001-0100');
    $all[] = $mk('master/appdata', 'manuell-20261001-0100');
    $all[] = $mk('master/other', snapPlanName('hourly', $now - 99 * 3600));
    $all[] = ['id' => 'btrfs:/mnt/disk1/.btrfs-snap/20261001-0100', 'fs' => 'btrfs', 'ds' => '/mnt/disk1', 'vol' => 'btrfs:/mnt/disk1', 'name' => '20261001-0100', 'docker' => false];

    $plan = ['id' => 'hourly', 'keep' => 24, 'max_days' => 0, 'recursive' => true];
    $doomed = snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now);
    same('retention keep 24 recursive', 12, count($doomed));
    $foreign = array_filter($doomed, fn ($id) => !preg_match('/@auto-hourly-\d{8}-\d{4}$/', $id) || str_contains($id, 'master/other'));
    same('retention never touches other snapshots', [], array_values($foreign));
    $plan['recursive'] = false;
    same('retention keep 24 not recursive', 6, count(snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now)));
    $plan['keep'] = 100;
    $plan['max_days'] = 1;
    same('retention max 1 day', 5, count(snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now)));
    $plan['keep'] = 1;
    $plan['max_days'] = 0;
    $one = snapPlanDoomed($plan, ['zfs:master/appdata'], $all, $now);
    same('retention keep 1', 29, count($one));
    check('retention keeps the newest', !in_array('zfs:master/appdata@' . snapPlanName('hourly', $now), $one, true));
    check('plan name matches its pattern', (bool) preg_match(snapPlanPattern('hourly'), snapPlanName('hourly', $now)));
    check('pattern ignores a similar plan', !preg_match(snapPlanPattern('hourly'), 'auto-hourly2-20261001-0100'));
}

function testEmby(): void
{
    foreach (['emby/embyserver:latest', 'emby/embyserver_arm64v8:4.8', 'linuxserver/emby', 'lscr.io/linuxserver/emby:latest',
              'ghcr.io/linuxserver/emby@sha256:abc', 'binhex/arch-emby:latest', 'registry:5000/emby-server:beta'] as $i) {
        check("emby server image: $i", embyIsServerImage($i));
    }
    foreach (['uping/embystat', 'helmi/embycache', 'jellyfin/jellyfin', 'lscr.io/linuxserver/jellyfin', 'kopia/kopia'] as $i) {
        check("not an emby server: $i", !embyIsServerImage($i));
    }

    // does a share suit EmbyCache with the pool "master"?
    foreach ([['ok', ['shareUseCache' => 'yes', 'shareCachePool' => 'master']],
              ['no_array', ['shareUseCache' => 'yes', 'shareCachePool' => 'hive', 'shareCachePool2' => 'ripley']],
              ['other_pool', ['shareUseCache' => 'yes', 'shareCachePool' => 'cache']],
              ['array_only', ['shareUseCache' => 'no']],
              ['pool_only', ['shareUseCache' => 'prefer', 'shareCachePool' => 'master']],
              ['pool_only', ['shareUseCache' => 'only', 'shareCachePool' => 'master']]] as [$want, $cfg]) {
        same('emby share fit: ' . json_encode($cfg), $want, embyShareFit($cfg, 'master'));
    }

    // the gather's consolidate.ini: bash sources it, so every value is quoted and checked
    $ini = embyGatherIni(['shares' => ['Filme', 'Meine Filme'], 'min_free_gb' => 256, 'dup_check' => 'size'],
        ['/mnt/master', '/mnt/disk1', '/mnt/user0', '/mnt/bad pool'], "/x/it's/consolidate.log", '/x/embycache_exclude.txt');
    check('gather ini: shares quoted', str_contains($ini, "BASE_DIRS=('/mnt/user/Filme' '/mnt/user/Meine Filme')\n"));
    same('gather ini: only real pools as cache', 1, preg_match("/^CACHE_PATTERN='\\/mnt\\/master'$/m", $ini));
    check('gather ini: never --include-cache, always dry by default', str_contains($ini, "CACHE_ONLY_TARGET='skip'") && str_contains($ini, "DRYRUN=true\n"));
    $tmp = sys_get_temp_dir() . '/office-tests-gather-' . getmypid() . '.ini';
    file_put_contents($tmp, $ini . 'echo "${BASE_DIRS[1]}|$MIN_FREE_GB|$LOGFILE"' . "\n");
    same('gather ini: bash reads it back', "/mnt/user/Meine Filme|256|/x/it's/consolidate.log", trim((string) shell_exec('bash ' . escapeshellarg($tmp))));
    unlink($tmp);
    try {
        embyGatherIni(['shares' => ['a$(reboot)'], 'min_free_gb' => 1, 'dup_check' => 'size'], [], '/l', '/e');
        check('gather ini refuses a strange share name', false);
    } catch (Problem $p) {
        same('gather ini refuses a strange share name', 'emby_bad_share', $p->key);
    }

    // run output for the page: progress lines collapsed, colour codes gone
    same('emby output: progress collapsed', "📂 Scanne: /mnt/user/Filme\n   🚚 [disk2 -> disk1] a.srt\nfertig",
        embyPlainOutput("   [1/2] 50% - A \e[K\r\r\e[K📂 Scanne: /mnt/user/Filme\n   [2/2] 100% - B \e[K\r   [2/2] 100% - B \e[K\r\n\r\e[K   🚚 [disk2 -> disk1] a.srt\n"
            . "2026-10-04 18:00:00,123 | INFO | fertig"));
}

/** userScriptSchedule against copies: only its own entry and line change */
function testUserScripts(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-' . getmypid();
    @mkdir("$tmp/user.scripts/scripts/demo", 0700, true);
    touch("$tmp/user.scripts/scripts/demo/script");
    $other = "$tmp/user.scripts/scripts/other/script";
    $json = json_encode([$other => ['script' => $other, 'frequency' => 'custom', 'id' => 'scheduleother', 'custom' => '1 12 * * *']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents("$tmp/user.scripts/schedule.json", $json);
    $cron = "# Generated cron schedule for user.scripts\n1 12 * * * " . US_START . " $other > /dev/null 2>&1\n\n";
    file_put_contents("$tmp/user.scripts/customSchedule.cron", $cron);
    $s = "$tmp/user.scripts/schedule.json";
    $c = "$tmp/user.scripts/customSchedule.cron";

    userScriptSchedule('demo', '0 3 * * *', $s, $c, null, false);
    $all = json_decode((string) file_get_contents($s), true);
    same('schedule: own entry set', '0 3 * * *', $all["$tmp/user.scripts/scripts/demo/script"]['custom'] ?? null);
    same('schedule: other entry untouched', '1 12 * * *', $all[$other]['custom'] ?? null);
    check('schedule: own cron line', str_contains((string) file_get_contents($c), "0 3 * * * " . US_START . " $tmp/user.scripts/scripts/demo/script "));
    check('schedule: other cron line kept', str_contains((string) file_get_contents($c), "1 12 * * * " . US_START . " $other "));

    userScriptSchedule('demo', null, $s, $c, null, false);
    $all = json_decode((string) file_get_contents($s), true);
    same('schedule off: frequency', 'disabled', $all["$tmp/user.scripts/scripts/demo/script"]['frequency'] ?? null);
    check('schedule off: own cron line gone', !str_contains((string) file_get_contents($c), 'scripts/demo/script'));
    check('schedule off: other cron line kept', str_contains((string) file_get_contents($c), $other));

    foreach (['0 3 * * * rm -rf /', '61 3 * * *'] as $bad) {
        try {
            userScriptSchedule('demo', $bad, $s, $c, null, false);
            check("schedule refuses $bad", false);
        } catch (Problem $p) {
            same("schedule refuses $bad", 'bad_cron', $p->key);
        }
    }
    try {
        userScriptSchedule('../x', '0 3 * * *', $s, $c, null, false);
        check('schedule refuses a bad name', false);
    } catch (Problem $p) {
        check('schedule refuses a bad name', true);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** The plugin's cron file against a copy: only the job's own line changes, the order stays */
function testOfficeCron(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-cron-' . getmypid();
    @mkdir($tmp, 0700, true);
    $file = "$tmp/office.cron";

    officeJobSetSchedule('snapshots', '*/5 * * * *', $file, false);
    officeJobSetSchedule('backup', '0 2 * * *', $file, false);
    same('office cron: both jobs', ['backup' => '0 2 * * *', 'snapshots' => '*/5 * * * *'], officeCronLines($file));
    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file)), fn ($l) => $l !== '' && $l[0] !== '#'));
    same('office cron: backup line first', '0 2 * * * ' . officeJobCommand('backup'), $lines[0] ?? null);

    officeJobSetSchedule('backup', '30  3 * * 1-5', $file, false);
    same('office cron: changed, spaces tidied', ['backup' => '30 3 * * 1-5', 'snapshots' => '*/5 * * * *'], officeCronLines($file));

    officeJobSetSchedule('gather', '0 4 * * 0', $file, false);
    officeJobSetSchedule('embycache', '5 * * * *', $file, false);
    same('office cron: Jack Emby\'s jobs beside them', ['backup' => '30 3 * * 1-5', 'snapshots' => '*/5 * * * *', 'embycache' => '5 * * * *', 'gather' => '0 4 * * 0'], officeCronLines($file));
    officeJobSetSchedule('embycache', null, $file, false);
    officeJobSetSchedule('gather', null, $file, false);

    officeJobSetSchedule('backup', null, $file, false);
    same('office cron: backup off, snapshots kept', ['snapshots' => '*/5 * * * *'], officeCronLines($file));
    officeJobSetSchedule('snapshots', null, $file, false);
    check('office cron: file gone when empty', !file_exists($file));

    foreach (['0 3 * * * rm -rf /', '61 3 * * *'] as $bad) {
        try {
            officeJobSetSchedule('backup', $bad, $file, false);
            check("office cron refuses $bad", false);
        } catch (Problem $p) {
            same("office cron refuses $bad", 'bad_cron', $p->key);
        }
    }
    try {
        officeJobSetSchedule('rm', '0 3 * * *', $file, false);
        check('office cron refuses an unknown job', false);
    } catch (Problem $p) {
        check('office cron refuses an unknown job', true);
    }
    // a line someone added by hand is not ours
    file_put_contents($file, "0 4 * * * bash /tmp/job.sh backup > /dev/null 2>&1\n");
    same('office cron: foreign line ignored', [], officeCronLines($file));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/** The office's label in Unraid's menu bar (src/place.php): what passes, and the page's Name= line on a copy */
function testMenuName(): void
{
    foreach (['Sekretariat', 'Office', 'USO', 'Mein Büro', 'Büro 2.0', 'A', 'R&D'] as $ok) {
        check("menu name ok: $ok", officeMenuNameValid($ok));
    }
    foreach (['', ' Office', 'Office ', 'x"y', '${HOME}', 'a\\b', "a\nb", '1234567890123456', 'end-', '<b>'] as $bad) {
        check('menu name refused: ' . json_encode($bad), !officeMenuNameValid($bad));
    }
    check('menu name: 15 characters (umlauts count once)', officeMenuNameValid('Büroküche Ölmü'));

    $tmp = sys_get_temp_dir() . '/office-tests-menu-' . getmypid();
    @mkdir($tmp, 0700, true);
    copy(dirname(__DIR__) . '/plugin/' . OFFICE_MENU_PAGE, "$tmp/" . OFFICE_MENU_PAGE);
    $before = (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE);
    check('menu page: default name in the repository', str_contains($before, "\nName=\"" . OFFICE_MENU_DEFAULT . "\"\n"));
    check('menu page: renamed', officeMenuPageApply($tmp, 'Büro & Co'));
    $after = (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE);
    same('menu page: only the Name= line changed', str_replace('Name="' . OFFICE_MENU_DEFAULT . '"', 'Name="Büro & Co"', $before), $after);
    same('menu page: Unraid reads the name', 'Büro & Co', parse_ini_string(explode("\n---\n", $after)[0])['Name'] ?? null);
    check('menu page: a bad name changes nothing', !officeMenuPageApply($tmp, 'x"y') && file_get_contents("$tmp/" . OFFICE_MENU_PAGE) === $after);

    // under Settings → User Utilities: Menu=, Title= and Icon=; back in the menu bar as it was
    check('menu page: to Settings', officeMenuPageApply($tmp, 'Büro & Co', 'settings'));
    $ini = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE))[0]);
    same('menu page: under Settings', ['Utilities', 'Büro & Co', 'Büro & Co', 'unraid-secretary-office.png', 'bell-o', 'f0a2'],
        [$ini['Menu'] ?? null, $ini['Name'] ?? null, $ini['Title'] ?? null, $ini['Icon'] ?? null, $ini['Tag'] ?? null, $ini['Code'] ?? null]);
    check('menu page: back to the menu bar', officeMenuPageApply($tmp, OFFICE_MENU_DEFAULT, 'menu'));
    same('menu page: as in the repository again', $before, file_get_contents("$tmp/" . OFFICE_MENU_PAGE));
    check('menu page: an unknown place changes nothing', !officeMenuPageApply($tmp, 'Office', 'dock'));
    same('menu url: menu bar', '/SecretaryOffice', officeMenuUrl('menu'));
    same('menu url: settings', '/Settings/SecretaryOffice', officeMenuUrl('settings'));

    // only a button in Unraid's header: the page without Menu=, the button page with it — and back
    copy(dirname(__DIR__) . '/plugin/' . OFFICE_BUTTON_PAGE, "$tmp/" . OFFICE_BUTTON_PAGE);
    $buttonBefore = (string) file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE);
    check('menu page: to a button', officeMenuPageApply($tmp, 'USO', 'button'));
    $ini = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_MENU_PAGE))[0]);
    $btn = parse_ini_string(explode("\n---\n", (string) file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE))[0]);
    same('menu page: no entry of its own as a button', [null, 'USO', 'USO'], [$ini['Menu'] ?? null, $ini['Name'] ?? null, $ini['Title'] ?? null]);
    same('button page: in the header', ['Buttons:90', 'USO', 'bell-o', '/SecretaryOffice'], [$btn['Menu'] ?? null, $btn['Title'] ?? null, $btn['Icon'] ?? null, $btn['Href'] ?? null]);
    same('menu url: button', '/SecretaryOffice', officeMenuUrl('button'));
    check('menu page: from the button back to the menu bar', officeMenuPageApply($tmp, OFFICE_MENU_DEFAULT, 'menu'));
    same('menu page: as in the repository after the button', $before, file_get_contents("$tmp/" . OFFICE_MENU_PAGE));
    same('button page: as in the repository again', $buttonBefore, file_get_contents("$tmp/" . OFFICE_BUTTON_PAGE));
    check('menu page: missing page', !officeMenuPageApply("$tmp/none", 'Office'));
    exec('rm -rf ' . escapeshellarg($tmp));
}

function testEstimates(): void
{
    // newest first, like backupHistory()
    $run = fn (string $id, string $result, int $total, array $kopia) => ['run' => $id, 'started' => 1000, 'finished' => 1000 + $total,
        'result' => $result, 'kopia_first' => null,
        'kopia' => array_map(fn ($n, $s) => ['name' => $n, 'ok' => true, 'seconds' => $s, 'finished' => 1000 + $total], array_keys($kopia), $kopia)];
    $first = $run('first', 'warnings', 37632, ['appdata' => 21850]);
    $small = fn (string $id, int $t, int $a) => $run($id, 'ok', $t, ['appdata' => $a]);

    $e = backupEstimates([$small('b', 547, 86), $first]);
    same('estimate: after the first run the newest counts', 547, $e['total']);
    same('estimate: newest Kopia source', 86, $e['sources']['appdata'] ?? null);

    $e = backupEstimates([$run('x', 'failed', 17, []), $run('y', 'aborted', 464, []), $small('b', 547, 86), $first]);
    same('estimate: failed and aborted runs skipped', 547, $e['total']);

    $e = backupEstimates([$small('d', 600, 90), $small('c', 30000, 20000), $small('b', 547, 86), $first]);
    same('estimate: one slow run among three does not count', 600, $e['total']);
    same('estimate: median Kopia of the last three', 90, $e['sources']['appdata'] ?? null);

    $e = backupEstimates([]);
    same('estimate: nothing known', null, $e['total']);
}

// ===================================================================== notifications

/**
 * Reports to Unraid's notifications: the caretaker's transitions (new red →
 * told once it stayed, same → quiet, solved and back → told again), with a
 * stand-in notify in a temporary folder; Jack Emby's choice of what to tell.
 */
function testNotify(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-notify-' . getmypid();
    @mkdir($tmp, 0700, true);
    $log = "$tmp/notified";
    file_put_contents("$tmp/notify", "#!/bin/bash\nfor a in \"\$@\"; do printf '%s\\x1f' \"\$a\"; done >> " . escapeshellarg($log) . "\necho >> " . escapeshellarg($log) . "\n");
    chmod("$tmp/notify", 0755);
    $before = getenv('OFFICE_NOTIFY_BIN');
    putenv("OFFICE_NOTIFY_BIN=$tmp/notify");
    $calls = function () use ($log): array {
        $out = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents($log))) as $line) {
            $args = explode("\x1f", rtrim($line, "\x1f"));
            $o = [];
            for ($i = 0; $i + 1 < count($args); $i += 2) {
                $o[$args[$i]] = $args[$i + 1];
            }
            $out[] = $o;
        }
        return $out;
    };

    // which findings are red: required and known to be missing, one key per thing
    $f = fn (string $id, string $level, ?bool $ok, array $p = []) => finding($id, $level, $ok, $p);
    $red = caretakerRed(['backup' => [$f('setup', 'required', true), $f('schedule', 'required', false), $f('kopia_repo', 'required', null),
                                      $f('drift', 'recommended', false), $f('kopia_running', 'required', false, ['name' => 'kopia'])],
                         'emby'   => [$f('python', 'required', false)]]);
    same('notify: red findings', ['backup:schedule:', 'backup:kopia_running:kopia', 'emby:python:'], array_keys($red));

    // the steps by themselves
    $t0 = 1_800_000_000;
    $one = ['backup:schedule:' => ['desk' => 'backup', 'id' => 'schedule', 'params' => []]];
    [$tr, $tell] = caretakerNotifyStep([], $one, $t0, true, 1800);
    same('notify step: new — waits', [0, $t0, null], [count($tell), $tr['backup:schedule:']['since'], $tr['backup:schedule:']['told']]);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1799, true, 1800);
    same('notify step: not yet half an hour', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1800, true, 1800);
    same('notify step: stayed — told', ['schedule'], array_column($tell, 'id'));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 9000, true, 1800);
    same('notify step: same — quiet', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, [], $t0 + 9100, true, 1800);
    same('notify step: solved — forgotten', [], $tr);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 9200, true, 1800);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 11000, true, 1800);
    same('notify step: back — told again', ['schedule'], array_column($tell, 'id'));
    [$tr, $tell] = caretakerNotifyStep([], $one, $t0, false, 1800);
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 1800, false, 1800);
    same('notify step: switched off — nothing told', 0, count($tell));
    [$tr, $tell] = caretakerNotifyStep($tr, $one, $t0 + 3600, true, 1800);
    same('notify step: switched on again — no backlog', 0, count($tell));

    // the whole way, with the state file and the stand-in notify
    $file = "$tmp/caretaker/notify.json";
    $checks = ['backup' => [$f('schedule', 'required', false)], 'emby' => [$f('python', 'required', true)]];
    caretakerNotifyEvaluate($checks, $file, $t0, 'de');
    same('notify: a new red finding waits', 0, count($calls()));
    check('notify: state written', is_file($file));
    caretakerNotifyEvaluate($checks, $file, $t0 + 1800, 'de');
    $c = $calls();
    same('notify: told after half an hour', 1, count($c));
    same('notify: subject in Unraid\'s language', 'Unraid Secretary Office: Etwas Neues zu erledigen', $c[0]['-s'] ?? null);
    same('notify: event as the engine\'s', 'Unraid Secretary Office', $c[0]['-e'] ?? null);
    same('notify: a warning', 'warning', $c[0]['-i'] ?? null);
    check('notify: what is missing in the bell', str_contains($c[0]['-d'] ?? '', 'Herr Backupsi — Ein nächtliches Backup ist geplant'), $c[0]['-d'] ?? '');
    check('notify: long text with what to do, lines as Unraid\'s \n', str_contains($c[0]['-m'] ?? '', 'Herr Backupsi → Zeitplan')
        && str_contains($c[0]['-m'] ?? '', '\n') && !str_contains($c[0]['-m'] ?? '', "\n"), $c[0]['-m'] ?? '');
    $page = caretakerNotifyPublic(readJson($file) ?? []);
    same('notify: the last report for the page', [true, ['schedule']], [$page['on'], array_column($page['last']['items'] ?? [], 'id')]);
    caretakerNotifyEvaluate($checks, $file, $t0 + 5400, 'de');
    same('notify: same finding — quiet', 1, count($calls()));
    caretakerNotifyEvaluate(['backup' => [$f('schedule', 'required', true)]], $file, $t0 + 6000, 'de');
    same('notify: solved — no all clear', 1, count($calls()));
    $two = ['backup' => [$f('schedule', 'required', false)], 'emby' => [$f('python', 'required', false)]];
    caretakerNotifyEvaluate($two, $file, $t0 + 6100, 'en');
    caretakerNotifyEvaluate($two, $file, $t0 + 7900, 'en');
    $c = $calls();
    same('notify: back and a second one — one notification', 2, count($c));
    same('notify: both in it (English)', 'Unraid Secretary Office: 2 new things to do', $c[1]['-s'] ?? null);
    check('notify: both listed', str_contains($c[1]['-d'] ?? '', 'Mr. Backupsy — A nightly backup is scheduled')
        && str_contains($c[1]['-d'] ?? '', 'Jack Emby — Python 3'), $c[1]['-d'] ?? '');
    $data = readJson($file) ?? [];
    $data['on'] = false;
    file_put_contents($file, json_encode($data));
    $other = ['backup' => [$f('dumps_share', 'required', false, ['share' => ''])]];
    caretakerNotifyEvaluate($other, $file, $t0 + 8000, 'en');
    caretakerNotifyEvaluate($other, $file, $t0 + 9800, 'en');
    same('notify: switched off — quiet', 2, count($calls()));
    same('notify: switched off — the page knows', false, caretakerNotifyPublic(readJson($file) ?? [])['on']);

    // Unraid's language, when the office speaks it
    foreach (['de_DE' => 'de', 'fr_FR' => 'en', '' => 'en', '../x' => 'en'] as $locale => $want) {
        file_put_contents("$tmp/dynamix.cfg", "[display]\nlocale=\"$locale\"\n[notify]\nalert=\"1\"\n");
        same("notify language for locale '$locale'", $want, officeNotifyLang("$tmp/dynamix.cfg"));
    }
    same('notify text: plural and placeholder', '3 neue Dinge zu erledigen', officeNotifyText('caretaker', 'notify.subject', ['n' => 3], 'de'));
    same('notify text: unknown key', '', officeNotifyText('caretaker', 'notify.nothing', [], 'de'));
    putenv("OFFICE_NOTIFY_BIN=$tmp/none");
    check('notify: no notify script — nothing, no error', !officeNotify('x', 'y'));

    // Jack Emby: only real runs that went wrong
    foreach ([['run', 'failed', [], 'failed'], ['run', 'aborted', [], 'aborted'], ['run', 'config', [], 'config'],
              ['run', 'errors', [], 'errors'], ['run', 'ok', ['errors' => 2], 'errors'], ['run', 'ok', ['errors' => 0], null],
              ['run', 'busy', [], null], ['run', 'refused', [], null], ['dry', 'failed', [], null], ['report', 'failed', [], null]] as [$mode, $result, $status, $want]) {
        same("emby notify: $mode $result " . json_encode($status), $want, embyNotifyOutcome($mode, $result, $status));
    }

    // every notification text the code asks for by name exists in English
    foreach (array_merge(glob(OFFICE_DIR . '/agent/desks/*.php') ?: [], glob(OFFICE_DIR . '/agent/lib/*.php') ?: []) as $php) {
        preg_match_all("/officeNotifyText\\(\\s*'([a-z]+)',\\s*'([a-z0-9_.]+)'/", (string) file_get_contents($php), $m, PREG_SET_ORDER);
        foreach ($m as [, $desk, $key]) {
            check(basename($php) . " asks for notification text $desk.$key", officeNotifyText($desk, $key, ['n' => 2], 'en') !== '');
        }
    }

    putenv($before === false ? 'OFFICE_NOTIFY_BIN' : "OFFICE_NOTIFY_BIN=$before");
    exec('rm -rf ' . escapeshellarg($tmp));
}

// ===================================================================== strings

function langFile(string $file): array
{
    $j = json_decode((string) @file_get_contents($file), true);
    if (!is_array($j)) {
        check("valid JSON: $file", false);
        return [];
    }
    unset($j['_meta']);
    return $j;
}

/** The {name} placeholders of a text or of all forms of a plural, sorted */
function langPlaceholders(mixed $v): array
{
    $found = [];
    foreach (is_array($v) ? $v : [$v] as $s) {
        if (is_string($s) && preg_match_all('/\{(\w+)\}/', $s, $m)) {
            $found = array_merge($found, $m[1]);
        }
    }
    $found = array_values(array_unique($found));
    sort($found);
    return $found;
}

/** A plural: {"one": …, "other": …} with plural categories only (Intl.PluralRules), "other" always there */
function langPluralOk(mixed $v): bool
{
    if (!is_array($v) || !isset($v['other'])) {
        return false;
    }
    foreach ($v as $cat => $s) {
        if (!in_array($cat, ['zero', 'one', 'two', 'few', 'many', 'other'], true) || !is_string($s)) {
            return false;
        }
    }
    return true;
}

function testStrings(): void
{
    $pub = OFFICE_DIR . '/public';
    $sets = ['' => "$pub/lang"];
    foreach (glob("$pub/desks/*/lang") ?: [] as $dir) {
        $sets[basename(dirname($dir))] = $dir;
    }
    $complete = ['de', 'it'];   // need every English key in every set; other languages may leave keys out (English fills in)
    $en = [];
    foreach ($sets as $desk => $dir) {
        $de = langFile("$dir/de.json");
        $e = langFile("$dir/en.json");
        $where = $desk === '' ? 'office' : $desk;
        same("$where: only in German", [], array_values(array_diff(array_keys($de), array_keys($e))));
        same("$where: only in English", [], array_values(array_diff(array_keys($e), array_keys($de))));
        foreach ($e as $k => $v) {
            $en[$desk === '' ? $k : "$desk.$k"] = true;
        }
        same("$where: English plurals well-formed", [], array_keys(array_filter($e, fn ($v) => is_array($v) && !langPluralOk($v))));

        // every other language: no keys English lacks, the same placeholders, plurals where English has them
        $codes = array_map(fn ($f) => basename($f, '.json'), glob("$dir/*.json") ?: []);
        foreach (array_diff(array_unique(array_merge($complete, $codes)), ['en']) as $code) {
            $l = $code === 'de' ? $de : langFile("$dir/$code.json");   // a missing file of a complete language fails here
            if ($code !== 'de') {   // German's keys are compared above
                same("$where/$code: keys English lacks", [], array_values(array_diff(array_keys($l), array_keys($e))));
                if (in_array($code, $complete, true)) {
                    same("$where/$code: English keys missing", [], array_values(array_diff(array_keys($e), array_keys($l))));
                }
            }
            $shape = $placeholders = [];
            foreach ($l as $k => $v) {
                if (!array_key_exists($k, $e)) {
                    continue;
                }
                if (is_array($e[$k]) ? !langPluralOk($v) : !is_string($v)) {
                    $shape[] = $k;
                }
                if (langPlaceholders($v) !== langPlaceholders($e[$k])) {
                    $placeholders[] = $k;
                }
            }
            same("$where/$code: plurals and texts shaped like English", [], $shape);
            same("$where/$code: placeholders like English", [], $placeholders);
        }
    }
    foreach (glob("$pub/lang/*.json") ?: [] as $file) {
        $meta = json_decode((string) @file_get_contents($file), true)['_meta'] ?? [];
        check(basename($file) . ': _meta has name and locale', is_string($meta['name'] ?? null) && $meta['name'] !== ''
            && is_string($meta['locale'] ?? null) && $meta['locale'] !== '');
    }

    // texts asked for by the pages: T('key') in a desk, t('key') / Office.t('key') anywhere
    foreach (glob("$pub/desks/*/desk.js") ?: [] as $file) {
        $desk = basename(dirname($file));
        $js = (string) file_get_contents($file);
        preg_match_all("/(?<![.\\w])T\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $js, $m);
        foreach (array_unique($m[1]) as $k) {
            check("$desk/desk.js asks for $desk.$k", isset($en["$desk.$k"]));
        }
        preg_match_all("/\\bOffice\\.t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/i", $js, $m);
        foreach (array_unique($m[1]) as $k) {
            check("$desk/desk.js asks for $k", isset($en[$k]));
        }
    }
    $core = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents("$pub/assets/core.js"));   // not in comments
    preg_match_all("/(?<![.\\w])t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $core, $m);
    foreach (array_unique($m[1]) as $k) {
        check("core.js asks for $k", isset($en[$k]));
    }

    // the caretaker shows every finding as <desk>.check.<id> (and _how, if any)
    $sources = [];
    foreach (glob(OFFICE_DIR . '/agent/desks/*.php') ?: [] as $file) {
        $sources[basename($file, '.php')][] = $file;
    }
    $sources['snapshot'][] = OFFICE_DIR . '/agent/lib/snapshotplans.php';
    foreach ($sources as $desk => $files) {
        foreach ($files as $file) {
            preg_match_all("/\\bfinding\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
            foreach (array_unique($m[1]) as $id) {
                check("$desk: text for check '$id'", isset($en["$desk.check.$id"]));
            }
        }
    }

    // every Problem key has a text, at a desk or in the office
    $errors = [];
    foreach ($en as $k => $_) {
        if (preg_match('/(?:^|\.)errors\.([a-z0-9_]+)$/', $k, $x)) {
            $errors[$x[1]] = true;
        }
    }
    foreach (array_merge(glob(OFFICE_DIR . '/agent/*.php') ?: [], glob(OFFICE_DIR . '/agent/lib/*.php') ?: [], glob(OFFICE_DIR . '/agent/desks/*.php') ?: [],
                         glob(OFFICE_DIR . '/src/*.php') ?: []) as $file) {
        preg_match_all("/new (?:Problem|AuthProblem)\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
        foreach (array_unique($m[1]) as $key) {
            check(basename($file) . ": text for error '$key'", isset($errors[$key]));
        }
    }
}

// ===================================================================== run

$parts = ['logic' => ['testCron', 'testRetention', 'testEmby', 'testUserScripts', 'testOfficeCron', 'testMenuName', 'testEstimates', 'testNotify'],
          'strings' => ['testStrings']];
$only = $argv[1] ?? '';
foreach ($parts as $name => $fns) {
    if ($only === '' || $only === $name) {
        foreach ($fns as $t) {
            $t();
        }
    }
}
$fail = $GLOBALS['results']['fail'];
foreach ($fail as $f) {
    echo "FAIL  $f\n";
}
printf("%d passed, %d failed\n", $GLOBALS['results']['pass'], count($fail));
exit($fail ? 1 : 0);
