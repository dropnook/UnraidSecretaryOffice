<?php
declare(strict_types=1);

/*
 * The office's tests — run them on the Unraid server, with Unraid's own PHP:
 *
 *   php /mnt/user/appdata/UnraidSecretaryOffice/tests/run.php
 *
 * They change nothing on the server: what writes files works on copies in a
 * temporary folder. Three parts:
 *   logic    the tricky functions (cron, snapshot retention, Emby detection,
 *            the gather's settings, User Scripts schedules, the plugin's cron file,
 *            the menu bar's label, reports to Unraid's notifications, the team
 *            lead's «I know, thanks» and the Dashboard tile,
 *            Mr. Backupsy's packages and his Kopia per app and VM, Ms. Dustdevil's pictures,
 *            Mr. Restori's reader of the packages)
 *   hardening  the checks that keep requests, manifests, paths and links in
 *            bounds (PIN tries, safe writes, the mailbox, Ms. Dustdevil's
 *            manifests, Emby paths, anchored validators, the release link)
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

/**
 * Mr. Backupsy's packages (engine 2.18): the office's reader on a made-up backup place, and the
 * engine's own helpers (lib/common.sh section 8: folder names, old run folders, an interrupted swap)
 */
function testBackupPackages(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-packages-' . getmypid();
    $put = function (string $file, mixed $data) use ($tmp): void {
        @mkdir(dirname("$tmp/$file"), 0700, true);
        file_put_contents("$tmp/$file", is_string($data) ? $data : json_encode($data));
    };
    $put('server/run.json', ['run' => '20261006-0200', 'result' => 'ok', 'files' => [['path' => 'libvirt.tar.gz', 'bytes' => 9, 'run' => '20261005-0200']]]);
    $put('server/libvirt.tar.gz', 'x');
    $put('apps/immich/manifest.json', ['name' => 'immich', 'type' => 'compose', 'run' => '20261006-0200', 'result' => 'warnings',
        'compose' => ['manager_dir' => 'immich'],
        'containers' => [['name' => 'immich_postgres', 'image' => 'ghcr.io/immich-app/postgres:14', 'digests' => ['ghcr.io/immich-app/postgres@sha256:ab'], 'inspect' => ['Id' => 'x']]],
        'dumps' => [['container' => 'immich_postgres', 'type' => 'postgres', 'state' => 'failed', 'login' => 'user', 'user_var' => 'POSTGRES_USER',
                     'password_var' => 'POSTGRES_PASSWORD', 'client' => 'psql', 'secret' => 'never']],
        'files' => [['path' => 'db/postgres_immich_postgres.sql.gz', 'bytes' => 100, 'run' => '20261005-0200', 'what' => 'dump', 'container' => 'immich_postgres'],
                    ['path' => 'compose/docker-compose.yml', 'bytes' => 20, 'run' => '20261006-0200', 'what' => 'compose']]]);
    $put('apps/gone/manifest.json', ['name' => 'gone', 'type' => 'template', 'run' => '20261001-0200', 'files' => []]);
    $put('apps/.ub-old-20261006-0200-immich/manifest.json', ['name' => 'aside']);
    $put('vms/Win_11/manifest.json', ['name' => 'Win 11', 'run' => '20261006-0200', 'xml' => 'Win_11.xml', 'uuid' => 'u-1', 'autostart' => true,
        'files' => [['path' => 'Win_11.xml', 'bytes' => 5], ['path' => 'nvram/u-1_VARS-pure-efi-tpm.fd', 'bytes' => 7], ['path' => 'tpm/u-1/tpm2/tpm2-00.permall', 'bytes' => 3]]]);
    $put('flash/manifest.json', ['run' => '20261006-0200', 'files' => [['path' => 'flash.tar.gz', 'bytes' => 4, 'run' => '20261004-0200']]]);
    $put('flash/flash.tar.gz', 'flsh');
    $put('20261001-0200/db/mariadb_x_y.sql.gz', 'x');            // an old run folder: the next run clears it away
    $put('20261002-0200/notes.txt', 'mine');                       // holds something else: stays, not counted

    $p = backupPackagesRead($tmp);
    same('packages: the last run', '20261006-0200', $p['run']);
    same('packages: apps, hidden folders left out', ['gone', 'immich'], array_column($p['apps'], 'folder'));
    same('packages: an app of an earlier run is stale', [true, false], array_column($p['apps'], 'stale'));
    $immich = $p['apps'][1];
    $dump = array_values(array_filter($immich['files'], fn ($f) => $f['what'] === 'dump'))[0] ?? [];
    same('packages: a kept dump keeps its run', ['20261005-0200', 'immich_postgres'], [$dump['run'] ?? null, $dump['container'] ?? null]);
    same('packages: dump credentials as names only', ['container' => 'immich_postgres', 'type' => 'postgres', 'state' => 'failed', 'login' => 'user',
        'user_var' => 'POSTGRES_USER', 'password_var' => 'POSTGRES_PASSWORD', 'client' => 'psql'], $immich['dumps'][0] ?? null);
    same('packages: no docker inspect for the page', ['name', 'image', 'digest', 'template'], array_keys($immich['containers'][0] ?? []));
    same('packages: bytes from the manifest', 120, $immich['bytes']);
    $vm = $p['vms'][0] ?? [];
    same('packages: a VM with NVRAM and TPM', ['Win 11', ['u-1_VARS-pure-efi-tpm.fd'], true, true, false],
        [$vm['name'] ?? null, $vm['nvram'] ?? null, $vm['tpm'] ?? null, $vm['autostart'] ?? null, $vm['snapshotdb'] ?? null]);
    same('packages: the libvirt archive in server/', "$tmp/server/libvirt.tar.gz", $p['server']['libvirt'] ?? null);
    same('packages: the flash archive keeps its run', '20261004-0200', $p['flash']['run'] ?? null);
    same('packages: old run folders the engine clears away', 1, $p['old_runs']);
    exec('rm -rf ' . escapeshellarg($tmp));

    // the engine's helpers, in bash
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $sh = fn (string $script) => trim((string) shell_exec('bash -c ' . escapeshellarg("source $lib >/dev/null 2>&1; $script") . ' 2>&1'));
    same('engine: folder names', 'Windows_11__G__ming_|_hidden|immich|_|', $sh('printf "%s|" "$(pkg_folder "Windows 11 (Gäming)")" "$(pkg_folder ".hidden")" "$(pkg_folder immich)" "$(pkg_folder "")"'));
    $t = escapeshellarg(sys_get_temp_dir() . '/office-tests-engine-' . getmypid());
    same('engine: old run folders', '20261001-0200,20261003-0200', $sh("T=$t; mkdir -p \$T/20261001-0200/db \$T/20261002-0200 \$T/20261003-0200/manifest \$T/2026-x \$T/apps;"
        . ' echo x >$T/20261002-0200/mine.txt; : >$T/20261003-0200/libvirt.tar.gz; old_runs_list $T | xargs -n1 basename | paste -sd, -'));
    same('engine: an interrupted swap is put right', 'apps:a b|vms:v|left:0', $sh("T=$t; rm -rf \$T; mkdir -p \$T/apps/a \$T/apps/.ub-old-20261006-0200-a \$T/apps/.ub-old-20261006-0200-b"
        . ' $T/vms/.ub-old-20261006-0200-v $T/.ub-stage-20261006-0200/apps/a; touch $T/apps/a/new; pkg_recover $T >/dev/null;'
        . ' printf "apps:%s|vms:%s|left:%s" "$(ls $T/apps | paste -sd" " -)" "$(ls $T/vms)" "$(ls -A $T | grep -c "^\.ub-")"; [ -e $T/apps/a/new ] || echo " lost"'));
    exec('rm -rf ' . $t);
}

/**
 * Mr. Backupsy's Kopia per app and VM (engine 2.19): the engine's items, the rules the shares get for their
 * parts, the targets, the checks of settings.ini, sleeping disks, SQLite URIs; settings.ini of 2.18 (no
 * [app] sections) gives the very same rules as before; the office's reader of policies and packages.
 */
function testBackupKopiaItems(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-items-' . getmypid();
    @mkdir($tmp, 0700, true);
    $ini = "[general]\ndumps_share = UnraidSecretaryOffice\nmount_root = /mnt/addons/UnraidSecretaryOffice/snapshots\n"
         . "[kopia]\nenabled = yes\n[share \"appdata\"]\nmode = kopia\nkopia_ignore = /kopia/\n[share \"UnraidSecretaryOffice\"]\nmode = kopia\n";
    file_put_contents("$tmp/old.ini", $ini);
    file_put_contents("$tmp/new.ini", $ini
        . "[app \"nextcloud\"]\nkopia = yes\nfolder = appdata/nextcloud\nkopia_retention = 7 0 14 8 24 5\nkopia_ignore = /appdata/nextcloud/data/cache/\n"
        . "[app \"my app\"]\nkopia = yes\nfolder = appdata/myapp\n[app \"my_app\"]\nkopia = yes\n[app \"left\"]\nkopia = no\nfolder = appdata/left\n"
        . "[vm \"Win 11\"]\nmode = snapshot\nprepare = freeze\nkopia = yes\nfolder = domains/Win 11\n");
    file_put_contents("$tmp/bad.ini", $ini . "[app \"x\"]\nkopia = maybe\nfolder = appdata/../etc\nfolder = /appdata/x\nkopia_retention = 7 7\n");
    file_put_contents("$tmp/disks.ini", "[\"disk1\"]\nname=\"disk1\"\nspundown=\"0\"\n[\"disk10\"]\nname=\"disk10\"\nspundown=\"1\"\n"
        . "[\"master\"]\nname=\"master\"\nspundown=\"0\"\n[\"master2\"]\nname=\"master2\"\nspundown=\"1\"\n[\"ripley\"]\nname=\"ripley\"\nspundown=\"0\"\n");
    $lib = escapeshellarg(OFFICE_DIR . '/backup/lib/common.sh');
    $sh = function (string $file, string $script) use ($lib, $tmp): string {
        $pre = "UB_DATA=$tmp/data UB_DISKS_INI=$tmp/disks.ini; source $lib >/dev/null 2>&1; cfg_load $tmp/$file; cfg_validate >/dev/null; apply_settings;"
             . ' INV_METHOD[appdata]=snap; INV_LAYOUT[appdata]=single; INV_LOCS[appdata]="master|zfs|master/appdata|"$\'\\n\';'
             . ' INV_METHOD[UnraidSecretaryOffice]=snap; INV_LAYOUT[UnraidSecretaryOffice]=single; PLAN_KOPIA=(UnraidSecretaryOffice appdata); PLAN_FLASH=off;';
        return trim((string) shell_exec('bash -c ' . escapeshellarg("$pre $script") . ' 2>&1'));
    };
    $md5 = substr(md5('my_app'), 0, 6);
    same('items: apps first (sorted), then VMs; a name that comes out the same gets a suffix; kopia = no is none',
        "app|my app|my_app\napp|my_app|my_app-$md5\napp|nextcloud|nextcloud\nvm|Win 11|Win_11", $sh('new.ini', 'kopia_items'));
    same('items: an app\'s parts - its folders, then its package', "appdata|nextcloud\nUnraidSecretaryOffice|backup/apps/nextcloud",
        $sh('new.ini', 'kopia_item_parts app nextcloud'));
    same('items: a VM\'s package', "domains|Win 11\nUnraidSecretaryOffice|backup/vms/Win_11", $sh('new.ini', 'kopia_item_parts vm "Win 11"'));
    same('items: the share leaves their folders out, besides its own rules', "/kopia/\n/myapp/\n/nextcloud/", $sh('new.ini', 'kopia_want_ignores share appdata'));
    same('items: the backup place leaves their packages out', "/backup/apps/my_app/\n/backup/apps/nextcloud/\n/backup/vms/Win_11/",
        $sh('new.ini', 'kopia_want_ignores share UnraidSecretaryOffice'));
    same('items: a split share gets a rule per base', "/disk1/myapp/\n/disk1/nextcloud/\n/kopia/\n/master/myapp/\n/master/nextcloud/",
        $sh('new.ini', 'INV_LAYOUT[appdata]=split; INV_LOCS[appdata]="master|zfs|x|"$\'\\n\'"disk1|btrfs|/mnt/disk1|appdata"; kopia_want_ignores share appdata'));
    same('items: own rules and retention, or inherited', "/appdata/nextcloud/data/cache/|7 0 14 8 24 5|inherit inherit inherit inherit inherit inherit",
        $sh('new.ini', 'printf "%s|%s|%s" "$(kopia_want_ignores app nextcloud)" "$(kopia_want_retention app nextcloud)" "$(kopia_want_retention app "my app")"'));
    $root = '/mnt/addons/UnraidSecretaryOffice/snapshots';
    same('items: the targets', "root|$root|\nshare|$root/UnraidSecretaryOffice|UnraidSecretaryOffice\nshare|$root/appdata|appdata\n"
        . "app|$root/.apps/my_app|my app\napp|$root/.apps/my_app-$md5|my_app\napp|$root/.apps/nextcloud|nextcloud\nvm|$root/.vms/Win_11|Win 11",
        $sh('new.ini', 'kopia_targets'));
    same('items: none with Kopia off', '', $sh('new.ini', 'KOPIA_ENABLED=no; kopia_items'));
    same('settings.ini of 2.18: no items, the same rules and targets as before', "|/kopia/|root|$root|\nshare|$root/UnraidSecretaryOffice|UnraidSecretaryOffice\nshare|$root/appdata|appdata",
        $sh('old.ini', 'printf "%s|%s|%s" "$(kopia_items)" "$(kopia_want_ignores share appdata)" "$(kopia_targets)"'));
    same('items: settings.ini valid', '0', $sh('new.ini', 'echo ${#CFG_ERRORS[@]}'));
    same('items: bad keys found', '4', $sh('bad.ini', 'echo ${#CFG_ERRORS[@]}'));
    same('engine: sleeping disks (a pool sleeps with any of its disks, disk1 is not disk10)', 'master disk10',
        $sh('new.ini', 'for b in master disk1 disk10 ripley mast; do ub_base_asleep $b && printf "%s " $b; done'));
    same('engine: a path for an SQLite URI', '/a%20b/Plug-in%20Support/%C3%A4%3F%23.db', $sh('new.ini', 'uri_escape "/a b/Plug-in Support/ä?#.db"'));
    same('engine: a container path on the host', '/mnt/user/appdata/plex/Library/x|/mnt/user/Backups/Emby|1',
        $sh('new.ini', 'CT_BINDS[c]="/mnt/user/appdata/plex|/config|true"$\'\\n\'"/mnt/user/Backups/Emby|/config/backup|true";'
            . ' printf "%s|%s|" "$(ct_host_path c /config/Library/x)" "$(ct_host_path c /config/backup)"; ct_host_path c /data || echo 1'));
    exec('rm -rf ' . escapeshellarg($tmp));

    // the office: policies of apps and VMs, and what a package says about its databases and the app's own backups
    $pol = backupPolicies([['kind' => 'app', 'share' => '', 'name' => 'nextcloud', 'path' => '/backup-snapshots/.apps/nextcloud', 'ok' => true, 'differences' => []],
                           ['kind' => 'share', 'share' => 'appdata', 'path' => '/backup-snapshots/appdata', 'ok' => false, 'differences' => [['what' => 'ignore_missing', 'item' => '/nextcloud/']]],
                           ['kind' => 'odd', 'share' => 'x']]);
    same('policies: apps and VMs, the name (older engines: the share)', [['app', 'nextcloud'], ['share', 'appdata']], array_map(fn ($p) => [$p['kind'], $p['name']], $pol));
    $tmp = sys_get_temp_dir() . '/office-tests-items-pk-' . getmypid();
    @mkdir("$tmp/apps/emby", 0700, true);
    @mkdir("$tmp/server", 0700, true);
    file_put_contents("$tmp/server/run.json", json_encode(['run' => '20261006-0200']));
    file_put_contents("$tmp/apps/emby/manifest.json", json_encode(['name' => 'EmbyServer', 'type' => 'template', 'run' => '20261006-0200',
        'sqlite' => [['container' => 'EmbyServer', 'file' => 'db/sqlite_EmbyServer_library.db', 'source' => '/mnt/user/appdata/EmbyServer/data/library.db',
                      'path' => '/config/data/library.db', 'state' => 'unchanged', 'check' => 'ok', 'present' => true]],
        'own_backups' => [['kind' => 'emby', 'container' => 'EmbyServer', 'path' => '/mnt/user/Backups/EmbyServer', 'files' => 3, 'newest' => 1791200000, 'asleep' => false],
                          ['kind' => 'odd', 'path' => '/etc']],
        'files' => [['path' => 'db/sqlite_EmbyServer_library.db', 'bytes' => 9, 'run' => '20261006-0200', 'what' => 'sqlite', 'container' => 'EmbyServer']]]));
    $a = backupPackagesRead($tmp)['apps'][0] ?? [];
    same('packages: the SQLite copies', ['/config/data/library.db', 'unchanged', 'ok', true], [$a['sqlite'][0]['path'] ?? null, $a['sqlite'][0]['state'] ?? null, $a['sqlite'][0]['check'] ?? null, $a['sqlite'][0]['present'] ?? null]);
    same('packages: the app\'s own backups, known kinds only', [['emby', '/mnt/user/Backups/EmbyServer', 3]], array_map(fn ($o) => [$o['kind'], $o['path'], $o['files']], $a['own_backups'] ?? []));
    exec('rm -rf ' . escapeshellarg($tmp));
}

/**
 * Mr. Restori: his own reader of the packages (dumps with their database and the credentials' variable
 * names, the folders a container binds, templates, compose files, stale packages), share paths, database types.
 */
function testRestore(): void
{
    $tmp = sys_get_temp_dir() . '/office-tests-restore-' . getmypid();
    $put = function (string $file, mixed $data) use ($tmp): void {
        @mkdir(dirname("$tmp/$file"), 0700, true);
        file_put_contents("$tmp/$file", is_string($data) ? $data : json_encode($data));
    };
    $put('server/run.json', ['run' => '20261006-0200', 'result' => 'ok', 'files' => []]);
    $put('apps/nextcloud/manifest.json', ['name' => 'nextcloud', 'type' => 'compose', 'run' => '20261006-0200', 'result' => 'ok',
        'compose' => ['project' => 'nextcloud', 'manager_dir' => 'nextcloud', 'working_dir' => '/boot/x', 'config_files' => ['/boot/x/compose.yaml']],
        'containers' => [
            ['name' => 'nextcloud-db', 'image' => 'mariadb:11.4', 'digests' => ['mariadb@sha256:ab'],
             'inspect' => ['Config' => ['Env' => ['MARIADB_VERSION=11.4', 'MARIADB_PASSWORD=secret']],
                           'Mounts' => [['Type' => 'bind', 'Source' => '/mnt/user/appdata/nextcloud/db/', 'Destination' => '/var/lib/mysql', 'RW' => true],
                                        ['Type' => 'volume', 'Source' => '/var/lib/docker/volumes/x', 'Destination' => '/x']]]],
            ['name' => 'nextcloud-app', 'image' => 'nextcloud:31', 'digests' => [], 'inspect' => ['Config' => ['Env' => []], 'Mounts' => []]],
        ],
        'dumps' => [['container' => 'nextcloud-db', 'type' => 'mariadb', 'state' => 'failed', 'login' => 'user', 'user_var' => 'MARIADB_USER',
                     'password_var' => 'MARIADB_PASSWORD', 'client' => 'mariadb']],
        'nextcloud' => [['container' => 'nextcloud-app', 'occ' => '/var/www/html/occ', 'user' => 'www-data', 'same_as' => '']],
        'files' => [['path' => 'db/mariadb_nextcloud-db_nextcloud.sql.gz', 'bytes' => 100, 'run' => '20261005-0200', 'what' => 'dump', 'container' => 'nextcloud-db'],
                    ['path' => 'compose/compose.yaml', 'bytes' => 20, 'run' => '20261006-0200', 'what' => 'compose'],
                    ['path' => '../escape', 'bytes' => 1, 'run' => '20261006-0200', 'what' => 'other']]]);
    $put('apps/emby/manifest.json', ['name' => 'EmbyServer', 'type' => 'template', 'run' => '20261001-0200',
        'containers' => [['name' => 'EmbyServer', 'image' => 'emby/embyserver', 'template' => 'my-EmbyServer.xml']],
        'files' => [['path' => 'my-EmbyServer.xml', 'bytes' => 9, 'run' => '20261001-0200', 'what' => 'template']]]);
    $put('vms/Win/manifest.json', ['name' => 'Win', 'run' => '20261006-0200', 'xml' => 'Win.xml', 'uuid' => '43CD8087-9364-2C3D-EB36-29696480E7D8',
        'disks' => [['source' => '/mnt/user/domains/Win/vdisk1.img', 'snapshot' => 'master/domains/Win@unraidbackup-20261006-0200', 'share' => 'domains']],
        'files' => [['path' => 'Win.xml', 'bytes' => 5, 'what' => 'xml'], ['path' => 'nvram/43cd8087-9364-2c3d-eb36-29696480e7d8_VARS-pure-efi.fd', 'bytes' => 7, 'what' => 'nvram']]]);
    $put('vms/Win/Win.xml', '<domain><hostdev/><hostdev/></domain>');
    $p = rsPackages($tmp);
    same('restore: apps by name', ['EmbyServer', 'nextcloud'], array_column($p['apps'], 'name'));
    same('restore: a package of an earlier run is stale', [true, false], array_column($p['apps'], 'stale'));
    $nc = $p['apps'][1];
    same('restore: a kept dump with its database and the variables only', ['db/mariadb_nextcloud-db_nextcloud.sql.gz', 'nextcloud', true, 'user', 'MARIADB_USER', 'MARIADB_PASSWORD'],
        [$nc['dumps'][0]['file'] ?? null, $nc['dumps'][0]['db'] ?? null, $nc['dumps'][0]['kept'] ?? null, $nc['dumps'][0]['login'] ?? null,
         $nc['dumps'][0]['user_var'] ?? null, $nc['dumps'][0]['password_var'] ?? null]);
    same('restore: no secrets in the package as read', false, str_contains(json_encode($p), 'secret'));
    same('restore: binds only, without the trailing slash', [['source' => '/mnt/user/appdata/nextcloud/db', 'dest' => '/var/lib/mysql', 'rw' => true]], $nc['containers'][0]['binds']);
    same('restore: database types from env and image', ['mariadb', null], array_column($nc['containers'], 'db'));
    same('restore: compose files, no path out of the package', [['compose.yaml'], 2], [$nc['compose']['files'], count($nc['files'])]);
    same('restore: templates', ['my-EmbyServer.xml'], $p['apps'][0]['templates']);
    $vm = $p['vms'][0] ?? [];
    same('restore: a VM with its UUID, NVRAM and devices', ['43cd8087-9364-2c3d-eb36-29696480e7d8', ['43cd8087-9364-2c3d-eb36-29696480e7d8_VARS-pure-efi.fd'], 2, false],
        [$vm['uuid'] ?? null, $vm['nvram'] ?? null, $vm['hostdev'] ?? null, $vm['tpm'] ?? null]);
    exec('rm -rf ' . escapeshellarg($tmp));

    $ctx = ['fs' => ['master' => 'zfs', 'disk1' => 'btrfs']];
    same('restore: share paths', [['appdata', 'nextcloud/db', null], ['appdata', 'x', 'master'], null, null, null, ['domains', '', null]],
        [rsSharePath('/mnt/user/appdata/nextcloud/db/', $ctx), rsSharePath('/mnt/master/appdata/x', $ctx), rsSharePath('/mnt/disks/ud/x', $ctx),
         rsSharePath('/mnt/user/appdata/../etc', $ctx), rsSharePath('/mnt/user/.hidden/x', $ctx), rsSharePath('/mnt/user/domains', $ctx)]);
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
    foreach (['de_DE' => 'de', 'fr_FR' => 'fr', 'pt_BR' => 'en', '' => 'en', '../x' => 'en'] as $locale => $want) {
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

/**
 * The team lead's «I know, thanks»: what a finding's signature depends on,
 * which findings get marked (never a must), how the notes are kept tidy, the
 * file on a copy — and the Dashboard tile counting only what is open, and
 * saying what the engine is doing while it runs.
 */
function testCaretakerAcks(): void
{
    $f = fn (string $id, string $level, ?bool $ok, array $p = []) => finding($id, $level, $ok, $p);

    // the signature: what it is about — not the order of its params, not an age or a size growing
    $upd = fn (string $latest, string $level = 'recommended') => $f('office_update', $level, false, ['version' => '1.26', 'latest' => $latest]);
    $sig = caretakerAckSig('caretaker', $upd('1.27'));
    check('ack sig: shaped for the action', (bool) preg_match(CARETAKER_ACK_SIG, $sig), $sig);
    same('ack sig: params in another order', $sig, caretakerAckSig('caretaker', $f('office_update', 'recommended', false, ['latest' => '1.27', 'version' => '1.26'])));
    check('ack sig: another version — comes back', $sig !== caretakerAckSig('caretaker', $upd('1.28')));
    check('ack sig: another level — comes back', $sig !== caretakerAckSig('caretaker', $upd('1.27', 'required')));
    check('ack sig: another desk', $sig !== caretakerAckSig('backup', $upd('1.27')));
    $cont = fn (string $name) => caretakerAckSig('caretaker', $f('other_backup_container', 'hint', null, ['name' => $name, 'image' => 'duplicati']));
    check('ack sig: another container — comes back', $cont('a') !== $cont('b'));
    $old = fn (int $n, int $days, string $size) => caretakerAckSig('cleanup', $f('trash_old', 'recommended', false, ['n' => $n, 'days' => $days, 'size' => $size]));
    same('ack sig: the storeroom only getting older and bigger — the same', $old(2, 31, '2 GB'), $old(2, 32, '2.1 GB'));
    check('ack sig: one more old run — comes back', $old(2, 31, '2 GB') !== $old(3, 31, '2 GB'));

    // the marks: a noted recommendation or note counts no more; a must or something in place is never marked
    $t0 = 1_800_000_000;
    $checks = ['emby'      => [$f('schedule', 'recommended', false), $f('python', 'required', false)],
               'caretaker' => [$f('other_backup_container', 'hint', null, ['name' => 'duplicati', 'image' => 'x']), $f('community_apps', 'recommended', true)]];
    $s = fn (string $desk, int $i) => caretakerAckSig($desk, $checks[$desk][$i]);
    $note = fn (string $desk, string $id) => ['desk' => $desk, 'id' => $id, 'time' => $t0, 'seen' => $t0];
    $acks = [$s('emby', 0) => $note('emby', 'schedule'), $s('caretaker', 0) => $note('caretaker', 'other_backup_container'),
             $s('emby', 1) => $note('emby', 'python'), $s('caretaker', 1) => $note('caretaker', 'community_apps')];   // the last two can't be noted
    [$marked, $kept] = caretakerAckStep($checks, $acks, $t0 + 60);
    same('ack mark: every finding has its sig', [true, true, true, true],
        array_map(fn ($x) => preg_match(CARETAKER_ACK_SIG, (string) ($x['sig'] ?? '')) === 1, [...$marked['emby'], ...$marked['caretaker']]));
    same('ack mark: the noted recommendation and note', [true, true], [$marked['emby'][0]['acked'] ?? false, $marked['caretaker'][0]['acked'] ?? false]);
    same('ack mark: never a must', false, $marked['emby'][1]['acked'] ?? false);
    same('ack mark: nothing in place', false, $marked['caretaker'][1]['acked'] ?? false);
    same("ack mark: the reports to Unraid don't change", caretakerRed($checks), caretakerRed($marked));

    // tidy: still there → seen once a day; in place → forgotten (back again, shown again); not seen for a month → forgotten
    same('ack tidy: seen not rewritten within a day', $t0, $kept[$s('emby', 0)]['seen']);
    [, $kept] = caretakerAckStep($checks, $acks, $t0 + 86400);
    same('ack tidy: seen once a day', $t0 + 86400, $kept[$s('emby', 0)]['seen']);
    check('ack tidy: something in place — its note forgotten', !isset($kept[$s('caretaker', 1)]));
    $solved = $checks;
    $solved['emby'][0]['ok'] = true;
    [, $kept] = caretakerAckStep($solved, $acks, $t0 + 60);
    check('ack tidy: sorted out — forgotten', !isset($kept[$s('emby', 0)]));
    [$back] = caretakerAckStep($checks, $kept, $t0 + 120);
    same('ack tidy: turns up again — shown again', false, $back['emby'][0]['acked'] ?? false);
    $gone = ['emby' => [], 'caretaker' => []];
    [, $kept] = caretakerAckStep($gone, $acks, $t0 + 29 * 86400);
    check('ack tidy: not seen for a while (desk let go) — kept', isset($kept[$s('emby', 0)]));
    [, $kept] = caretakerAckStep($gone, $acks, $t0 + 31 * 86400);
    same('ack tidy: not seen for a month — forgotten', [], $kept);

    // the file, on a copy
    $tmp = sys_get_temp_dir() . '/office-tests-acks-' . getmypid();
    $file = "$tmp/caretaker/acks.json";
    caretakerAckApply($checks, $file, $t0);
    check('ack file: nothing noted — no file written', !is_file($file));
    caretakerWrite($file, ['acks' => [$s('emby', 0) => $note('emby', 'schedule'), '../x' => $note('emby', 'x'), $s('caretaker', 0) => 'odd']]);
    same('ack file: odd entries left out', [$s('emby', 0)], array_keys(caretakerAckRead($file)));
    $marked = caretakerAckApply($checks, $file, $t0 + 60);
    same('ack file: marked from the file', true, $marked['emby'][0]['acked'] ?? false);
    caretakerAckApply($solved, $file, $t0 + 120);
    same('ack file: sorted out — gone from the file', [], caretakerAckRead($file));
    exec('rm -rf ' . escapeshellarg($tmp));

    // the Dashboard tile (src/dashboard.php): only open points of desks that work here
    require_once OFFICE_DIR . '/src/dashboard.php';
    [$marked] = caretakerAckStep($checks, $acks, $t0);
    $all = ['emby' => 1, 'caretaker' => 1];
    same('dashboard: without notes — to do and recommended', [1, 1], officeDashCareCounts(['checks' => $checks], $all));
    same('dashboard: the noted recommendation counts no more', [1, 0], officeDashCareCounts(['checks' => $marked], $all));
    $forged = $checks;
    $forged['emby'][1]['acked'] = true;
    same('dashboard: a must always counts', [1, 1], officeDashCareCounts(['checks' => $forged], $all));
    same("dashboard: desks that don't work here don't count", [0, 0], officeDashCareCounts(['checks' => $checks], ['caretaker' => 1]));
    same('dashboard: no state', [0, 0], officeDashCareCounts([], $all));
    foreach ([[['result' => 'running', 'mode' => 'backup'], 'dash.bk_running'], [['result' => 'running', 'mode' => 'check'], 'dash.bk_checking'],
              [['result' => 'running', 'mode' => 'dryrun'], 'dash.bk_dryrun'], [['result' => 'ok', 'mode' => 'check'], 'dash.bk_running'],
              [null, 'dash.bk_running']] as [$status, $want]) {
        same('dashboard: the engine runs, status ' . json_encode($status), $want, officeDashBackupRunning(['running' => true, 'status' => $status]));
    }
}

/** Ms. Dustdevil's pictures: how Unraid matches templates, the logo lookup, and editing templates and override files on copies */
function testIcons(): void
{
    // DockerUtil::ensureImageTag(): a template's <Repository> and a container's image compare like this
    same('icon: image tag, official image', 'library/php:apache', clImageTag('php:apache'));
    same('icon: image tag, latest added', 'ghcr.io/imagegenius/kopia:latest', clImageTag('ghcr.io/imagegenius/kopia'));
    same('icon: image tag, registry port', 'registry:5000/team/app:latest', clImageTag('registry:5000/team/app'));
    same('icon: image tag, same image', clImageTag('library/redis:latest'), clImageTag('redis'));

    foreach ([['ghcr.io/immich-app/postgres:14-vectorchord0.4.3@sha256:bcf6', ['ghcr.io/immich-app/postgres', 'immich-app/postgres']],
              ['docker.io/valkey/valkey:9@sha256:c123', ['valkey/valkey', 'valkey/valkey']],
              ['mariadb:11.4', ['mariadb', 'mariadb']],
              ['lscr.io/linuxserver/mariadb', ['lscr.io/linuxserver/mariadb', 'linuxserver/mariadb']],
              ['registry:5000/Team/App:1', ['registry:5000/team/app', 'team/app']],
              ['library/nginx:alpine', ['nginx', 'nginx']]] as [$image, $want]) {
        same("icon: lookup names of $image", $want, clIconRepo($image));
    }
    foreach (['immich-app/immich-server' => 'immich', 'immich-app/immich-machine-learning' => 'immich', 'immich-app/postgres' => 'postgres',
              'tensorchord/pgvecto-rs' => 'postgres', 'tensorchord/vchord-postgres' => 'postgres', 'valkey/valkey' => 'valkey', 'redis' => 'redis',
              'mariadb' => 'mariadb', 'linuxserver/mariadb' => 'mariadb', 'mysql' => 'mysql', 'mongo' => 'mongodb', 'nextcloud' => 'nextcloud',
              'nextcloud-ocr' => 'nextcloud', 'nextcloud/all-in-one' => 'nextcloud', 'jellyfin/jellyfin' => 'jellyfin', 'plexinc/pms-docker' => 'plex',
              'linuxserver/plex' => 'plex', 'emby/embyserver' => 'emby', 'binhex/arch-emby' => 'emby', 'php' => 'php', 'httpd' => 'apache',
              'jc21/nginx-proxy-manager' => 'nginx-proxy-manager', 'homeassistant/home-assistant' => 'home-assistant',
              'vaultwarden/server' => 'vaultwarden', 'kopia/kopia' => null, 'uping/embystat' => null, 'linuxserver/jellyseerr' => null] as $loose => $want) {
        same("icon: table for $loose", $want, clIconTableSlug($loose));
    }
    foreach (CL_ICON_TABLE as $re => $slug) {
        check("icon: table entry $slug is a name of the collection", preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && @preg_match($re, '') !== false);
    }
    same('icon: guesses for immich ML', ['immich-machine-learning', 'immich'], clIconGuesses('immich-app/immich-machine-learning'));
    same('icon: guesses for sonarr', ['sonarr'], clIconGuesses('linuxserver/sonarr'));
    same('icon: guesses leave generic words out', ['arch-emby', 'emby'], clIconGuesses('binhex/arch-emby'));
    same('icon: guesses strip -server and docker-', ['docker-foo-server', 'foo'], clIconGuesses('x/docker-foo_server'));

    // Community Applications' feed: an Icon belongs to the Repository before it, unless another entry began
    $feed = implode("\n", ['s:4:"Name";', 's:10:"Repository";s:13:"valkey/valkey"', 's:4:"Icon";s:18:"https://x/vk-1.png"',
        's:4:"Name";', 's:4:"Name";', 's:10:"Repository";s:21:"lscr.io/linuxserver/a"', 's:4:"Name";',
        's:4:"Name";', 's:4:"Icon";s:19:"https://x/plug.png"',
        's:4:"Name";', 's:10:"Repository";s:20:"valkey/valkey:8-trix"', 's:4:"Icon";s:18:"https://x/vk-2.png"',
        's:4:"Name";', 's:10:"Repository";s:5:"redis"', 's:4:"Icon";s:17:"https://x/r.svg"',
        '"Name": "b"', '"Repository": "ghcr.io/versity/versitygw"', '"Icon": "https:\/\/x\/vgw.png"', '"Name": "App Data"']);
    $ca = clCaParse($feed);
    same('ca: pictures by image', ['https://x/vk-1.png' => 1, 'https://x/vk-2.png' => 1], $ca['exact']['valkey/valkey'] ?? null);
    check('ca: a plugin\'s picture never goes to the app before it', !isset($ca['exact']['lscr.io/linuxserver/a']));
    check('ca: svg left out', !isset($ca['exact']['redis']));
    same('ca: JSON feed, loose name', ['https://x/vgw.png' => 1], $ca['loose']['versity/versitygw'] ?? null);

    check('icon url ok', clIconUrlOk('https://example.com/a/b-1.png?x=1&y=2'));
    foreach (['file:///boot/x.png', 'ftp://x/y.png', 'https://x/a b.png', 'https://x/"a.png', "https://x/a\n.png", 'javascript:alert(1)', 'https://x/a<b>.png'] as $bad) {
        check('icon url refused: ' . json_encode($bad), !clIconUrlOk($bad));
    }
    same('icon: override name', 'compose.override.yaml', clOverrideName('/x/compose.yaml'));
    same('icon: override name, old style', 'docker-compose.override.yml', clOverrideName('/x/docker-compose.yml'));
    same('icon: home of a template', CL_TEMPLATES, clIconHome(CL_TEMPLATES . '/my-a.xml', '/r'));
    same('icon: home of an override file', '/r/p', clIconHome('/r/p/compose.override.yaml', '/r'));
    same('icon: no home elsewhere', '', clIconHome('/r/p/q/compose.override.yaml', '/r') . clIconHome('/tmp/my-a.xml', '/r') . clIconHome('/r/p/compose.yaml', '/r'));

    // a template: only its <Icon> changes
    $tpl = "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n  <Name>web</Name>\n  <Repository>php:apache</Repository>\n  <Registry/>\n  <Icon/>\n  <Config Name=\"x\" Type=\"Path\">/mnt/user/appdata/web</Config>\n</Container>\n";
    same('template: empty <Icon/> set', str_replace('<Icon/>', '<Icon>https://x/a.png?b=1&amp;c=2</Icon>', $tpl), clXmlSetIcon($tpl, 'https://x/a.png?b=1&c=2', 't'));
    $old = str_replace('<Icon/>', '<Icon>/boot/config/plugins/dockerMan/images/Apache.png</Icon>', $tpl);
    same('template: old <Icon> replaced', str_replace('<Icon/>', '<Icon>file:///boot/x.png</Icon>', $tpl), clXmlSetIcon($old, 'file:///boot/x.png', 't'));
    $none = str_replace("  <Icon/>\n", '', $tpl);
    same('template: <Icon> added after <Repository>', str_replace("</Repository>\n", "</Repository>\n  <Icon>https://x/a.png</Icon>\n", $none), clXmlSetIcon($none, 'https://x/a.png', 't'));
    foreach (['two icons' => str_replace('<Icon/>', "<Icon/>\n  <Icon>y</Icon>", $tpl), 'CDATA' => str_replace('<Icon/>', '<Icon><![CDATA[x]]></Icon>', $tpl),
              'no template' => "<foo/>\n"] as $what => $bad) {
        try {
            clXmlSetIcon($bad, 'https://x/a.png', 't');
            check("template refused: $what", false);
        } catch (Problem $p) {
            same("template refused: $what", 'cleanup_icon_shape', $p->key);
        }
    }

    // an override file, the shapes Compose Manager writes
    $set = ['database' => 'https://x/pg.png'];
    $cm = clOverrideTemplate();
    same('override: Compose Manager\'s new file', str_replace("services: {}\n", "services:\n  database:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/pg.png'\n", $cm),
        clOverrideSetIcons($cm, $set, 'o'));
    $immich = "services:\n  immich-server:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: ''\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell: ''\n"
            . "  database:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: ''   # empty\n      net.unraid.docker.webui: ''\n";
    $want = str_replace("      net.unraid.docker.icon: ''   # empty\n", "      net.unraid.docker.icon: 'https://x/pg.png'\n", $immich);
    $want = str_replace("      net.unraid.docker.icon: ''\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell", "      net.unraid.docker.icon: 'https://x/im''s.png'\n      net.unraid.docker.webui: ''\n      net.unraid.docker.shell", $want);
    same('override: only the icon lines change', $want, clOverrideSetIcons($immich, $set + ['immich-server' => "https://x/im's.png"], 'o'));
    $more = "version: '3'\nservices:\n  app:\n    environment:\n      - A=1\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      com.example.x: \"y\"\n  cron:\n    environment:\n      B: 2\n\n# end\nnetworks:\n  default: {}\n";
    same('override: added to labels, a new labels block, a new service', "version: '3'\nservices:\n  app:\n    environment:\n      - A=1\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/a.png'\n      com.example.x: \"y\"\n"
        . "  cron:\n    environment:\n      B: 2\n    labels:\n      net.unraid.docker.icon: 'https://x/c.png'\n  redis:\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/r.png'\n\n# end\nnetworks:\n  default: {}\n",
        clOverrideSetIcons($more, ['app' => 'https://x/a.png', 'cron' => 'https://x/c.png', 'redis' => 'https://x/r.png'], 'o'));
    same('override: labels: {} and CRLF', "services:\r\n  app:\r\n    labels:\r\n      net.unraid.docker.icon: 'https://x/a.png'\r\n",
        clOverrideSetIcons("services:\r\n  app:\r\n    labels: {}\r\n", ['app' => 'https://x/a.png'], 'o'));
    same('override: empty file', "services:\n  '1':\n    labels:\n      net.unraid.docker.managed: 'composeman'\n      net.unraid.docker.icon: 'https://x/a.png'\n",
        clOverrideSetIcons('', ['1' => 'https://x/a.png'], 'o'));
    foreach (['tab' => "services:\n\tapp:\n", 'four spaces' => "services:\n    app:\n      labels:\n", 'labels as a list' => "services:\n  app:\n    labels:\n      - \"a=b\"\n",
              'merge key' => "services:\n  app:\n    <<: *base\n", 'anchor' => "services:\n  app:\n    labels:\n      net.unraid.docker.icon: &i x\n",
              'flow services' => "services: {app: {}}\n", 'deeper label' => "services:\n  app:\n    labels:\n      a:\n        b: c\n",
              'block scalar' => "services:\n  app:\n    labels:\n      a: |\n        x\n", 'twice' => "services:\n  app: \nservices:\n"] as $what => $bad) {
        try {
            clOverrideSetIcons($bad, ['app' => 'https://x/a.png'], 'o');
            check("override refused: $what", false);
        } catch (Problem $p) {
            same("override refused: $what", 'cleanup_icon_shape', $p->key);
        }
    }

    // on copies: swap a template into a storeroom and back; a new override file and back; Unraid's cache
    $tmp = sys_get_temp_dir() . '/office-tests-icons-' . getmypid();
    @mkdir("$tmp/templates", 0700, true);
    @mkdir("$tmp/project", 0700, true);
    @mkdir("$tmp/ram", 0700, true);
    @mkdir("$tmp/disk", 0700, true);
    file_put_contents("$tmp/templates/my-web.xml", $old);
    $new = clXmlSetIcon($old, 'https://x/a.png', 't');
    clIconReplace("$tmp/templates/my-web.xml", $new, "$tmp/trash/run/icons/abc/my-web.xml");
    same('swap: the new template in place', $new, file_get_contents("$tmp/templates/my-web.xml"));
    same('swap: the old one in the storeroom', $old, file_get_contents("$tmp/trash/run/icons/abc/my-web.xml"));
    same('swap: no temp file left', [], glob("$tmp/templates/.*.tmp") ?: []);
    $m = ['from' => "$tmp/templates/my-web.xml", 'written' => md5($new), 'was' => 'there'];
    file_put_contents("$tmp/templates/my-web.xml", $new . ' ');
    try {
        clIconPutBack("$tmp/trash/run/icons/abc/my-web.xml", $m);
        check('put back refused once the template changed', false);
    } catch (Problem $p) {
        same('put back refused once the template changed', 'cleanup_icon_changed', $p->key);
    }
    file_put_contents("$tmp/templates/my-web.xml", $new);
    clIconPutBack("$tmp/trash/run/icons/abc/my-web.xml", $m);
    same('put back: the old template again', $old, file_get_contents("$tmp/templates/my-web.xml"));
    check('put back: out of the storeroom', !file_exists("$tmp/trash/run/icons/abc/my-web.xml"));

    $override = "$tmp/project/compose.override.yaml";
    $text = clOverrideSetIcons(clOverrideTemplate(), $set, 'o');
    clIconReplace($override, $text, "$tmp/trash/run/icons/def/compose.override.yaml", clOverrideTemplate());
    same('new override: written', $text, file_get_contents($override));
    same('new override: Compose Manager\'s empty one in the storeroom', clOverrideTemplate(), file_get_contents("$tmp/trash/run/icons/def/compose.override.yaml"));
    file_put_contents("$tmp/png", "\x89PNG\r\n\x1a\n" . str_repeat('x', 100));
    file_put_contents("$tmp/docker.json", json_encode(['other' => ['icon' => '/x.png', 'url' => 'u'], 'web' => ['icon' => CL_DM_FALLBACK_WEB, 'updated' => 'false']]));
    $cache = clIconSeed('web', "$tmp/png", ["$tmp/ram", "$tmp/disk"], "$tmp/docker.json", '/state/x');
    same('seed: both copies', ["$tmp/ram/web-icon.png", "$tmp/disk/web-icon.png"], array_keys($cache));
    $dj = json_decode((string) file_get_contents("$tmp/docker.json"), true);
    same('seed: docker.json points at it, the rest stays', ['other' => ['icon' => '/x.png', 'url' => 'u'], 'web' => ['icon' => '/state/x/web-icon.png', 'updated' => 'false']], $dj);
    same('seed: a strange name is refused', [], clIconSeed('../x', "$tmp/png", ["$tmp/ram"], "$tmp/docker.json"));
    file_put_contents("$tmp/project/icon_url", 'https://x/a.png');
    clIconPutBack("$tmp/trash/run/icons/def/compose.override.yaml", ['from' => $override, 'written' => md5($text), 'was' => 'missing', 'cache' => $cache,
        'icon_url' => ['path' => "$tmp/project/icon_url", 'md5' => md5('https://x/a.png'), 'was' => 'missing']], ["$tmp/ram", "$tmp/disk"]);
    check('put back: the new override file is gone again', !file_exists($override) && !file_exists("$tmp/trash/run/icons/def/compose.override.yaml"));
    check('put back: icon_url and the cache copies gone', !file_exists("$tmp/project/icon_url") && !file_exists("$tmp/ram/web-icon.png") && !file_exists("$tmp/disk/web-icon.png"));

    // what curl answered: a picture only with 200 and a PNG
    file_put_contents("$tmp/0.png", "\x89PNG\r\n\x1a\nxx");
    file_put_contents("$tmp/1.png", '<html>');
    $r = clIconFetchResults("1\t200\t6\ttext/html\n0\t200\t10\timage/png\n2\t404\t9\ttext/plain\n", ['https://a/0.png', 'https://a/1.png', 'https://a/2.png', 'https://a/3.png'], $tmp);
    ksort($r);
    same('fetch: what loads', ['https://a/0.png' => true, 'https://a/1.png' => false, 'https://a/2.png' => false, 'https://a/3.png' => false], array_map(fn ($x) => $x['ok'], $r));
    same('fetch: why not', [null, 'not_png', 'http_404', 'unreachable'], array_values(array_map(fn ($x) => $x['why'], [$r['https://a/0.png'], $r['https://a/1.png'], $r['https://a/2.png'], $r['https://a/3.png']])));
    $cmd = clIconFetchCommand(['https://a/0.png'], '/d');
    check('fetch: no shell globbing, http(s) only, no User-Agent', in_array('-g', $cmd, true) && in_array('=http,https', $cmd, true) && $cmd[array_search('-A', $cmd, true) + 1] === '');
    same('loop risk: its fields', ['version', 'affected', 'fallback_missing', 'containers', 'risk', 'standin'], array_keys(cleanupIconLoopRisk([])));

    // pictures in dockerMan/images named like the app — never by the first word of the container's name alone
    $files = ['Apache.png', 'AppleTimeMachine.png', 'emby.png', 'Nextcloud.png', 'cloud.png', 'redis.png', 'app.png'];
    foreach ([['mbentley/timemachine', 'TimeMachine_Benj', ['AppleTimeMachine.png']], ['emby/embyserver', 'EmbyServer', ['emby.png']],
              ['nextcloud-ocr', 'nextcloud-app', ['Nextcloud.png']], ['mariadb', 'nextcloud-db', []], ['redis', 'nextcloud-redis', ['redis.png']],
              ['php', 'CKW-Webseite', []], ['x/y', 'apache', ['Apache.png']]] as [$loose, $name, $want]) {
        same("icon folder: $name ($loose)", $want, clIconFolderMatches($loose, $name, $files));
    }
    check('icon: Time Machine by name or image', preg_match(CL_ICON_TIMEMACHINE, 'TimeMachine_Benj x/y') && preg_match(CL_ICON_TIMEMACHINE, 'b willtho89/samba-time_machine')
        && !preg_match(CL_ICON_TIMEMACHINE, 'machine timer'));
    same('icon: a safe file name', ['TimeMachine_Benj', 'a_b_c', 'container'], [clIconFileName('TimeMachine_Benj'), clIconFileName('../a b/c'), clIconFileName('..')]);

    // the stack's picture: its main app, never a database, machine learning only without a better one
    $immich = [['id' => 'icon:ml', 'name' => 'immich_machine_learning', 'service' => 'immich-machine-learning', 'image' => 'ghcr.io/immich-app/immich-machine-learning:v3', 'project' => 'immich'],
               ['id' => 'icon:pg', 'name' => 'immich_postgres', 'service' => 'database', 'image' => 'ghcr.io/immich-app/postgres:14', 'project' => 'immich'],
               ['id' => 'icon:sv', 'name' => 'immich_server', 'service' => 'immich-server', 'image' => 'ghcr.io/immich-app/immich-server:v3', 'project' => 'immich'],
               ['id' => 'icon:rd', 'name' => 'immich_redis', 'service' => 'redis', 'image' => 'valkey/valkey:9', 'project' => 'immich']];
    same('stack: ranks', [4, null, 1, null], array_map('clIconMainRank', $immich));
    $picked = fn (array $list) => clIconStackPick(array_map(fn ($e) => $e + ['pick' => 'https://x/' . $e['name'] . '.png'], $list), $immich)['name'] ?? null;
    same('stack: the server, not machine learning', 'immich_server', $picked($immich));
    same('stack: machine learning alone waits for the server', null, $picked([$immich[0]]));
    same('stack: a database alone never', null, $picked([$immich[1]]));
    same('stack: named like the project first', 'nextcloud', clIconStackPick([['id' => 'a', 'name' => 'nc-web', 'service' => 'web', 'image' => 'nginx', 'project' => 'nextcloud', 'pick' => 'https://x/w.png'],
        ['id' => 'b', 'name' => 'nextcloud', 'service' => 'nextcloud', 'image' => 'nextcloud', 'project' => 'nextcloud', 'pick' => 'https://x/n.png']], [])['name'] ?? null);
    same('stack: no picture from a file on the server', null, clIconStackPick([$immich[2] + ['pick' => 'file:///boot/x.png']], $immich));

    // an uploaded picture: a PNG, small, at most 512 x 512
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    check('upload: a 1x1 PNG', clIconUploadOk($png));
    check('upload: 1000 pixels wide refused', !clIconUploadOk(substr_replace($png, pack('N', 1000), 16, 4)));
    check('upload: a JPEG refused', !clIconUploadOk("\xFF\xD8\xFF\xE0" . substr($png, 4)));
    check('upload: too big refused', !clIconUploadOk($png . str_repeat("\0", CL_UPLOAD_MAX)));
    check('upload: cut off refused', !clIconUploadOk(substr($png, 0, -12)));
    $r = ['items' => [['id' => 'icon:web', 'upload' => base64_encode($png)], ['id' => 'icon:db', 'url' => ' https://x/a.png ']]];
    same('upload: items', ['icon:web' => ['url' => null, 'png' => $png], 'icon:db' => ['url' => 'https://x/a.png', 'png' => null]], clIconItems($r));
    try {
        clIconItems(['items' => [['id' => 'icon:web', 'upload' => 'not base64!']]]);
        check('upload: bad base64 refused', false);
    } catch (Problem $p) {
        same('upload: bad base64 refused', 'cleanup_icon_upload_bad', $p->key);
    }

    // kept in the office's icons folder: the same picture again reused, another one beside it; put back takes mine away while unchanged
    $icons = "$tmp/icons";
    $a = clIconStore('web', $png, $icons);
    same('store: new', ['path' => "$icons/web.png", 'created' => true, 'md5' => md5($png)], $a);
    same('store: the same again', ['path' => "$icons/web.png", 'created' => false, 'md5' => md5($png)], clIconStore('web', $png, $icons));
    $other = substr_replace($png, pack('N', 2), 16, 4);
    same('store: another beside it', "$icons/web-2.png", clIconStore('web', $other, $icons)['path']);
    same('store: found for the container again', ["$icons/web-2.png", "$icons/web.png"], clIconUploads('web', $icons));
    clIconUnstore("$icons/web-2.png", md5($png), $icons);
    check('unstore: a changed file stays', is_file("$icons/web-2.png"));
    clIconUnstore("$tmp/png", (string) md5_file("$tmp/png"), $icons);
    check('unstore: nothing outside the folder', is_file("$tmp/png"));
    file_put_contents("$tmp/templates/my-web.xml", $old);
    $new = clXmlSetIcon($old, "file://$icons/web.png", 't');
    clIconReplace("$tmp/templates/my-web.xml", $new, "$tmp/trash/run/icons/up/my-web.xml");
    clIconPutBack("$tmp/trash/run/icons/up/my-web.xml", ['from' => "$tmp/templates/my-web.xml", 'written' => md5($new), 'was' => 'there',
        'uploads' => [$a['path'] => $a['md5']]], ["$tmp/ram"], $icons);
    check('put back: the uploaded picture is gone, the other stays', !is_file("$icons/web.png") && is_file("$icons/web-2.png"));

    // previews: PNG and JPEG as data: addresses, within the budget, only files on the server
    file_put_contents("$tmp/p.png", $png);
    file_put_contents("$tmp/a.jpg", "\xFF\xD8\xFF\xE0xx");
    file_put_contents("$tmp/a.txt", 'hello');
    $p64 = 'data:image/png;base64,' . base64_encode($png);
    same('preview: PNG, JPEG, not text', [$p64, 'data:image/jpeg;base64,' . base64_encode("\xFF\xD8\xFF\xE0xx"), null],
        [clIconPreview("$tmp/p.png"), clIconPreview("$tmp/a.jpg"), clIconPreview("$tmp/a.txt")]);
    $room = [['category' => 'template', 'candidates' => [['url' => "file://$tmp/p.png"], ['url' => "file://$tmp/a.jpg"], ['url' => 'https://x/a.png']]],
             ['category' => 'ok', 'candidates' => [['url' => "file://$tmp/a.txt"]]]];
    same('previews: only files, within the budget', ["file://$tmp/p.png" => $p64, "file://$tmp/a.jpg" => null], clIconPreviews($room, strlen($p64) + 5));
    exec('rm -rf ' . escapeshellarg($tmp));
}

// ===================================================================== hardening

/** A temporary folder for a test, removed again by hardeningRm() */
function hardeningTmp(string $name): string
{
    $dir = sys_get_temp_dir() . "/uso-test-$name-" . getmypid();
    @mkdir($dir, 0700, true);
    return $dir;
}

function hardeningRm(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $n) {
        hardeningRm("$dir/$n");
    }
    @rmdir($dir);
}

/** A wrong current PIN when changing or removing it counts against the waiting time like a wrong one at unlocking */
function testPinTries(): void
{
    $dir = hardeningTmp('auth');
    @mkdir("$dir/office", 0700);
    defined('OFFICE_DATA') || define('OFFICE_DATA', $dir);
    if (OFFICE_DATA !== $dir) {
        check('PIN tries: test data folder', false, 'OFFICE_DATA is already ' . OFFICE_DATA);
        return;
    }
    if (!function_exists('officeReadJson')) {
        function officeReadJson(string $file): ?array
        {
            $data = json_decode((string) @file_get_contents($file), true);
            return is_array($data) ? $data : null;
        }
    }
    require_once OFFICE_DIR . '/src/auth.php';
    officeSetPin('2468', '');
    same('PIN set', 'pin', officeAuthMode());
    same('auth.json only for its owner', '600', substr(sprintf('%o', fileperms(officeAuthFile())), -3));
    $keys = [];
    for ($i = 0; $i < OFFICE_FREE_TRIES + 1; $i++) {
        try {
            officeSetPin('1357', '0000');
            $keys[] = 'changed';
        } catch (AuthProblem $e) {
            $keys[] = $e->key;
        }
    }
    same('wrong current PIN: refused, then waiting', array_merge(array_fill(0, OFFICE_FREE_TRIES, 'pin_wrong'), ['pin_wait']), $keys);
    same('wrong current PIN counted', OFFICE_FREE_TRIES, (int) (officeAuthRead()['failures'] ?? 0));
    try {
        officeUnlock('2468');
        $key = 'unlocked';
    } catch (AuthProblem $e) {
        $key = $e->key;
    }
    same('while waiting even the right PIN waits', 'pin_wait', $key);
    same('no temporary files left', [], glob("$dir/office/.auth.*.tmp") ?: []);
    hardeningRm($dir);
}

/** writeAtomic(): a link at the target or a file in the way is never written through; the mode is there from the start */
function testSafeWrites(): void
{
    $dir = hardeningTmp('write');
    $victim = "$dir/victim.txt";
    file_put_contents($victim, 'keep');
    symlink($victim, "$dir/state.json");
    writeAtomic("$dir/state.json", '{"ok":true}', 0600, 0, 0);
    same('writeAtomic: the victim behind a link is untouched', 'keep', file_get_contents($victim));
    check('writeAtomic: the link is replaced by a file', !is_link("$dir/state.json") && is_file("$dir/state.json"));
    same('writeAtomic: mode 0600', '600', substr(sprintf('%o', fileperms("$dir/state.json")), -3));
    writeAtomic("$dir/script", "#!/bin/bash\n", 0755, 0, 0);
    same('writeAtomic: a script keeps its execute bits', '755', substr(sprintf('%o', fileperms("$dir/script")), -3));
    same('writeAtomic: no temporary files left', [], glob("$dir/.*.tmp") ?: []);
    $tmp = writeNewFile("$dir/.x", 'a');
    check('writeNewFile: a new file of its own', $tmp !== null && is_file($tmp) && str_starts_with(basename($tmp), '.x.'));

    // the mailbox and data/office: a real folder of the web server's user, closed to others
    $box = "$dir/mailbox";
    mkdir($box, 0770);
    chown($box, WEB_UID);
    check('mailbox of the web server: accepted', privateDirOk($box));
    chmod($box, 0777);
    check('mailbox open to others: refused', !privateDirOk($box));
    chmod($box, 0770);
    chown($box, WEB_UID + 1);
    check("mailbox of another user: refused", !privateDirOk($box));
    symlink($box, "$dir/linkbox");
    check('mailbox through a link: refused', !privateDirOk("$dir/linkbox"));
    check('a link where a private folder belongs is left alone', !privateDirEnsure("$dir/linkbox", 0700, false) && is_link("$dir/linkbox"));
    check('a link where the mailbox belongs becomes a folder', privateDirEnsure("$dir/linkbox", 0770, true) && !is_link("$dir/linkbox"));
    same('the folder the link pointed to keeps its owner', WEB_UID + 1, fileowner($box));
    hardeningRm($dir);
}

/** Ms. Dustdevil's manifests lie in folders others may write to: only entries of her own shape count */
function testTrashManifest(): void
{
    $st = '20261005-120000';
    foreach ([['templates/my-app.xml', 'template'], ['compose/stack', 'stack'], ['appdata/foo', 'appdata'], ['vms/win11', 'domain'],
              ['strays/0a1b2c3d/my-x.xml', 'stray'], ['icons/0a1b2c3d/compose.override.yaml', 'icon'], ['nvram/abc_VARS.fd', 'nvram'],
              ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'appdata']] as [$as, $kind]) {
        check("manifest as accepted: $as", clTrashAsOk($as, $kind, $st));
    }
    foreach ([['../../../../boot/config/super.dat', 'template'], ['templates/../../x', 'template'], ['templates/./x', 'template'],
              ['/boot/config/go', 'template'], ['templates//x', 'template'], ['appdata/foo', 'template'], ['templates/a/b', 'template'],
              ['strays/x', 'stray'], ["templates/x\ny", 'template'], ['', 'template'], ['@cache/appdata', 'appdata'],
              ['@cache/appdata/_UnraidSecretaryOffice-trash-20990101-000000-foo', 'appdata'], ["@cache/appdata/_UnraidSecretaryOffice-trash-$st-foo", 'template'],
              ["@cache/../x/_UnraidSecretaryOffice-trash-$st-foo", 'appdata'], ['@cache', 'appdata']] as [$as, $kind]) {
        check('manifest as refused: ' . json_encode($as) . " ($kind)", !clTrashAsOk($as, $kind, $st));
    }
    check('manifest from: an absolute path', clTrashPathOk('/mnt/cache/appdata/foo'));
    foreach (['mnt/x', '/mnt/../boot', '/mnt/./x', '/mnt//x', "/mnt/x\n", '/'] as $p) {
        check('manifest from refused: ' . json_encode($p), !clTrashPathOk($p));
    }
    check('dataset name ok', clZfsNameOk('cache/appdata/foo bar'));
    check('dataset name with .. refused', !clZfsNameOk('cache/../foo'));

    $dir = hardeningTmp('trash');
    mkdir("$dir/run/appdata", 0755, true);
    mkdir("$dir/elsewhere", 0755);
    symlink("$dir/elsewhere", "$dir/run/strays");
    check('restore source inside the run: ok', clRunPathOk("$dir/run", 'appdata/foo'));
    check('restore source through a linked folder: refused', !clRunPathOk("$dir/run", 'strays/0a1b2c3d/my-x.xml'));
    hardeningRm($dir);
}

/** Jack Emby's path mappings and EmbyCache's paths never lead out of the shares */
function testEmbyPaths(): void
{
    foreach ([['/media/movies', '/mnt/user/Filme'], ['/media/tv/', '/mnt/user/Serien/TV'], ['/media/skip', '']] as [$from, $to]) {
        check("mapping accepted: $from => $to", embyMappingOk($from, $to));
    }
    foreach ([['/media', '/mnt/user/../../boot'], ['/media', '/mnt/user/Filme/../../../etc'], ['media', '/mnt/user/Filme'],
              ['/media/../x', '/mnt/user/Filme'], ['/media', '/mnt/cache/Filme'], ['/media', "/mnt/user/Filme\n"], ['/media', '/mnt/user/']] as [$from, $to]) {
        check('mapping refused: ' . json_encode([$from, $to]), !embyMappingOk($from, $to));
    }
    [$exit] = run(['python3', '--version'], 10);
    if ($exit !== 0) {
        return;                                    // no Python on this server: EmbyCache can't run here either
    }
    $py = 'import sys; sys.path.insert(0, sys.argv[1]); import embycache_lib as l; '
        . 'loc = l.Locations({"cache_path": "/mnt/cache", "array_path": "/mnt/user0", "user_path": "/mnt/user", "array_disks_glob": "/mnt/disk[0-9]*"}, '
        . '{"/media/movies": "/mnt/user/Filme"}); '
        . 'print(loc.rel_from_docker("/media/movies/A/a.mkv"), loc.rel_from_docker("/media/movies/../../../../boot/config/go"), '
        . 'loc.rel_from_docker("/media/movies/A/../../Other/x"))';
    [$exit, $out] = run(['env', 'EMBYCACHE_DIR=' . sys_get_temp_dir(), 'PYTHONDONTWRITEBYTECODE=1', 'python3', '-c', $py, OFFICE_DIR . '/embycache'], 30);
    same('EmbyCache: paths with ".." are skipped', 'Filme/A/a.mkv None None', trim($out));
}

/** Validators end at the end of the string: "$" alone would let a trailing newline through into a name, a file or a command */
function testAnchors(): void
{
    check('snapshot name ok', preg_match(SNAPSHOT_NAME, 'manual-20261005') === 1);
    check('snapshot name with a trailing newline refused', preg_match(SNAPSHOT_NAME, "manual-20261005\n") === 0);
    check('menu name ok', officeMenuNameValid('Office'));
    check('menu name with a trailing newline refused', !officeMenuNameValid("Office\n"));
    check('picture address ok', clIconUrlOk('https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/emby.png'));
    check('picture address with a trailing newline refused', !clIconUrlOk("https://example.com/a.png\n"));
    check('plan id with a trailing newline refused', preg_match(SNAPPLAN_ID, "daily\n") === 0);
}

/** The release the caretaker links to: a version number and a page of the office's repository on GitHub, nothing else */
function testUpdateClean(): void
{
    $ok = officeUpdateClean(['latest' => '1.26.0', 'url' => 'https://github.com/' . OFFICE_REPO . '/releases/tag/v1.26.0']);
    same('release kept', ['1.26.0', 'https://github.com/' . OFFICE_REPO . '/releases/tag/v1.26.0'], [$ok['latest'] ?? null, $ok['url']]);
    $bad = officeUpdateClean(['latest' => "99\n", 'url' => 'javascript:alert(1)']);
    same('release with odd values', [null, ''], [$bad['latest'] ?? null, $bad['url']]);
    same('a link elsewhere is dropped', '', officeUpdateClean(['url' => 'https://github.com.evil.example/x'])['url']);
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
    $complete = ['de', 'it', 'fr', 'es'];   // need every English key in every set; other languages may leave keys out (English fills in)
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

/** Ms. Dustdevil squares pictures that aren't square (Unraid would squeeze them) */
function testIconSquare(): void
{
    if (!function_exists('imagecreatetruecolor')) {
        return;
    }
    $tmp = sys_get_temp_dir() . '/office-tests-square-' . getmypid();
    @mkdir($tmp, 0700, true);
    foreach (['wide' => [1123, 512], 'tall' => [100, 300], 'square' => [200, 200], 'small' => [60, 30]] as $n => [$w, $h]) {
        $im = imagecreatetruecolor($w, $h);
        imagepng($im, "$tmp/$n.png");
        $sq = clIconSquare("$tmp/$n.png");
        if ($n === 'square') {
            same('icon square: a square picture stays', null, $sq);
            continue;
        }
        $size = is_string($sq) ? getimagesizefromstring($sq) : null;
        $want = min(CL_SQUARE_SIDE, max($w, $h));
        same("icon square: $n becomes {$want}x{$want}", [$want, $want], $size ? [$size[0], $size[1]] : null);
    }
    same('icon square: not a picture', null, clIconSquare(__FILE__));
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
}

// ===================================================================== run

$parts = ['logic' => ['testCron', 'testRetention', 'testEmby', 'testUserScripts', 'testOfficeCron', 'testMenuName', 'testEstimates', 'testNotify', 'testCaretakerAcks',
                      'testBackupPackages', 'testBackupKopiaItems', 'testIcons', 'testIconSquare', 'testRestore'],
          'hardening' => ['testPinTries', 'testSafeWrites', 'testTrashManifest', 'testEmbyPaths', 'testAnchors', 'testUpdateClean'],
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
