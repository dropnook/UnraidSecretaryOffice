<?php
declare(strict_types=1);

/*
 * Fanboy's own tile on Unraid's Dashboard (src/dashboard-fanboy.php, plugin/SecretaryOfficeDashboard.page) and his
 * setting «Show on the Dashboard» (agent/desks/fanboy-dash.php). The web side runs in a process of its own over a
 * plugin's layout (src/ beside the web files) with the invented demo states of tests/ui/demo/fanboy*.json; required by
 * tests/run.php, the tests in its part `fanboy`.
 */

/**
 * The web side in a process of its own: the data folder ($files: path => content, null = no data folder at all) and the
 * RAM folder ($run) as given, the clock $now. Answers the tile, the rows (en) and the model (en, de).
 */
function fanboyDashWeb(?array $files, array $run, int $now): array
{
    $tmp = hardeningTmp('fanboy-dash');
    @mkdir("$tmp/plugin/src", 0700, true);
    foreach (glob(OFFICE_DIR . '/src/*.php') ?: [] as $f) {
        copy($f, "$tmp/plugin/src/" . basename($f));
    }
    foreach (['assets', 'desks', 'lang'] as $d) {
        @symlink(OFFICE_WEB . "/$d", "$tmp/plugin/$d");
    }
    foreach ($files ?? [] as $path => $content) {
        @mkdir(dirname("$tmp/data/$path"), 0700, true);
        file_put_contents("$tmp/data/$path", is_string($content) ? $content : json_encode($content));
    }
    @mkdir("$tmp/run/fanboy", 0700, true);
    foreach ($run as $path => $content) {
        file_put_contents("$tmp/run/$path", is_string($content) ? $content : json_encode($content));
    }
    $src = var_export("$tmp/plugin/src", true);
    $code = 'define("OFFICE_IN_UNRAID", true); require ' . $src . ' . "/bootstrap.php"; require_once ' . $src . ' . "/page.php";'
        . ' require_once ' . $src . ' . "/dashboard.php"; require_once ' . $src . ' . "/dashboard-fanboy.php";'
        . ' $state = is_dir(OFFICE_DATA) ? officeReadJson(OFFICE_DATA . "/fanboy.json") : null; $status = officeReadJson(officeRunDir() . "/fanboy/control.json");'
        . ' $board = is_string($state["setup"]["board"] ?? null) ? $state["setup"]["board"] : (is_string($status["board"] ?? null) ? $status["board"] : "");'
        . ' $hist = officeFanboyDashGate() === "history" ? officeFanboyDashHistory($board, ' . $now . ') : null;'
        . ' echo json_encode(["gate" => officeFanboyDashGate(), "tile" => officeFanboyDashTile(), "rows" => officeFanboyDashRows("en", ' . $now . '), "hist" => $hist,'
        . ' "model" => officeFanboyDashModel(officeFanboyDashStrings("en"), $state, $status, "en", ' . $now . ', $hist),'
        . ' "de" => officeFanboyDashModel(officeFanboyDashStrings("de"), $state, $status, "de", ' . $now . ', $hist)]);';
    $p = proc_open(['php', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        ['OFFICE_DATA_DIR' => $files === null ? "$tmp/no-data" : "$tmp/data", 'OFFICE_RUN_DIR' => "$tmp/run", 'PATH' => getenv('PATH'),
         'HTTP_ACCEPT_LANGUAGE' => 'en']);
    $raw = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($p);
    hardeningRm($tmp);
    return (json_decode($raw, true) ?: ['raw' => $raw]) + ['err' => trim($err)];
}

/** A key's value, null as null — 'x' when it isn't there at all */
function fanboyDashKey(array $o, string $key): mixed
{
    return array_key_exists($key, $o) ? $o[$key] : 'x';
}

/** A demo state of tests/ui/demo as his data folder has it: hired, the setting as given ($oldSwitch: his hidden time's file too) */
function fanboyDashFiles(string $demo, mixed $show = 'speeds', bool $hired = true, bool $oldSwitch = false): array
{
    $files = ['fanboy.json' => (string) file_get_contents(OFFICE_DIR . "/tests/ui/demo/$demo.json"),
              'office/staff.json' => ['hired' => $hired ? ['fanboy' => 1, 'backup' => 1] : ['backup' => 1]]];
    if ($oldSwitch) {
        $files['fanboy/show.json'] = '{}';      // means nothing since 2026-11-16
    }
    if ($show !== null) {
        $files['fanboy/dashboard.json'] = is_string($show) && str_starts_with($show, '{') ? $show : ['v' => 1, 'show' => $show];
    }
    return $files;
}

/** The tile: there only while he is hired and it is picked (default «Off»); what it says for each demo state */
function testFanboyDashTile(): void
{
    $t0 = 1763100000;                   // the demo states' time
    $now = $t0 + 20;

    // when it is there at all
    $cases = [
        'not hired'                => fanboyDashFiles('fanboy', 'speeds', false),
        'not hired, his old switch file there' => fanboyDashFiles('fanboy', 'speeds', false, true),
        'switched off'             => fanboyDashFiles('fanboy', 'off'),
        'no setting (the default)' => fanboyDashFiles('fanboy', null),
        'an odd setting'           => fanboyDashFiles('fanboy', '{"v":1,"show":"on"}'),
        'a setting not a string'   => fanboyDashFiles('fanboy', '{"v":1,"show":true}'),
        'a broken file'            => fanboyDashFiles('fanboy', '{"v":1,"sh'),
    ];
    $got = [];
    foreach ($cases as $what => $files) {
        $o = fanboyDashWeb($files, [], $now);
        $got[$what] = [fanboyDashKey($o, 'gate'), $o['tile'] ?? 'x', $o['err']];
    }
    same('fanboy dash: no tile — not hired (his old switch file changes nothing), switched off, no setting, an odd one, a broken file', array_fill_keys(array_keys($cases), [null, '', '']), $got);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), [], $now);
    same('fanboy dash: hired, «speeds with history», no switch file — the tile there', ['history', true], [$o['gate'] ?? null, str_contains((string) ($o['tile'] ?? ''), 'id="db-sso-fanboy"')]);

    // steering two zones: who, one row per zone (not per fan), the fans unfolding, the watched CPU cooler
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), [], $now);
    $tile = (string) ($o['tile'] ?? '');
    $m = $o['model'] ?? [];
    same('fanboy dash: steering — the web side without a word', '', $o['err']);
    same('fanboy dash: steering — who, no banner, nothing stale, the two zones (warmest, level, speed), the CPU cooler watched',
        ['Fanboy · 2 zones', 'green', [], null,
         [['Plattenkäfig', 'warmest: Disk 2 at 41 °C', '57 %', '1018 rpm', 1], ['Gehäuse', 'warmest: Composite at 28 °C', '25 %', '905 rpm', 2]],      // (the demo's levels follow its curves since round 13's demo data)
         [['CPU-Kühler', '1310 rpm']]],
        [$m['who'] ?? null, $m['tone'] ?? null, $m['banners'] ?? null, fanboyDashKey($m, 'stale'),
         array_map(fn ($z) => [$z['name'], $z['warmest'], $z['level'], $z['speed'], count($z['fans'])], $m['zones'] ?? []), $m['watch'] ?? null]);
    same('fanboy dash: steering — German: «U/min», the decimal comma', ['Fanboy · 2 Zonen', '1018 U/min', 'am wärmsten: Disk 2 mit 41 °C'],
        [$o['de']['who'] ?? null, $o['de']['zones'][0]['speed'] ?? null, $o['de']['zones'][0]['warmest'] ?? null]);
    // (2026-11-20: the demo's fans have names and models — each zone's fans under it with their symbols; as before: testFanboyDashFans)
    check('fanboy dash: the tile — Unraid\'s tile markup (a tbody titled by his English name, the header, the link to his page), each zone with its fans, its own key on the page',
        str_starts_with($tile, '<tbody id="db-sso-fanboy" title="Fanboy">') && str_contains($tile, 'class="tile-header-main">Fanboy</h3>')
        && str_contains($tile, '#/fanboy"') && substr_count($tile, '<div class="sso-fb-zone sso-fb-zone-art') === 2
        && str_contains($tile, '<span class="sso-fb-fname">Deckel</span><span class="sso-fb-fval">no speed feedback · 40 %</span>'), $tile);

    same('fanboy dash: steering — his everyday picture, no alarm mood', ['', true, false],
        [$m['mood'] ?? null, str_contains($tile, '<img class="sso-fb-logo" src="/plugins/unraid-secretary-office/desks/fanboy/avatar.svg'), str_contains($tile, 'src="/plugins/unraid-secretary-office/desks/fanboy/avatar-alarm.svg')]);

    // the alarm first and plain; a fan that stands
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-alarm'), [], $now);
    $m = $o['model'] ?? [];
    same('fanboy dash: alarm — red, first, the owner\'s words (round 4: the fan and the other fans of its zone at 100 %); no second «stands» line for the same fan',
        [[['red', 'Alarm: Vorn reports no speed although it should turn. Fanboy sets it and the other fans of its zone to 100 % until it turns again, and then for 2 more minutes.']], true],
        [$m['banners'] ?? null, strpos((string) ($o['rows'] ?? ''), 'sso-fb-banner red-text') < strpos((string) ($o['rows'] ?? ''), 'class="sso-fb-zone')]);
    check('fanboy dash: alarm — the fan that stands red on its row, beside its symbol', str_contains((string) ($o['rows'] ?? ''),
        '<div class="sso-fb-fan sso-fb-fan-art red-text"><span class="sso-fb-sym"') && str_contains((string) ($o['rows'] ?? ''), '<span class="sso-fb-fname">Vorn</span><span class="sso-fb-fval">0 rpm · should turn · 100 %</span>'), (string) ($o['rows'] ?? ''));
    // the zones that fold (no fan with a look or a name): the alarm's zone open
    $o = fanboyDashWeb(['fanboy.json' => fanboyDashPlain(json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-alarm.json'), true))] + fanboyDashFiles('fanboy'), [], $now);
    $rows = (string) ($o['rows'] ?? '');
    same('fanboy dash: alarm — where it is: the case zone «alarm — at 100 %» in red and open, the disk cage steers on (57 %); the fan that stands marked on its row',
        [['57 %', 'alarm — at 100 %'], [false, true], true, true, true, false],
        [array_column($m['zones'] ?? [], 'level'), array_column($m['zones'] ?? [], 'full'), str_contains($rows, '<b class="red-text">alarm — at 100 %</b>'),
         str_contains($rows, '<div class="sso-fb-fan red-text"><span>Output 5</span><span>0 rpm · should turn · 100 %</span>'), str_contains($rows, 'data-zone="case" open>'),
         str_contains($rows, 'data-zone="disks" open>')]);
    same('fanboy dash: alarm — his alarm picture (as on his page), swapped back by the refresh', ['alarm', true, true],
        [$m['mood'] ?? null, str_contains((string) ($o['tile'] ?? ''), 'desks/fanboy/avatar-alarm.svg') && str_contains((string) ($o['tile'] ?? ''), '<img class="sso-fb-logo" src="'),
         str_contains($rows, 'data-mood="alarm"')]);
    $alone = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-alarm.json'), true);
    $alone['control']['alarm']['params']['alone'] = true;
    same('fanboy dash: alarm — a fan alone in its zone: the owner\'s words for it alone', 'Alarm: Vorn reports no speed although it should turn. Fanboy sets it to 100 % until it turns again, and then for 2 more minutes.',
        fanboyDashWeb(['fanboy.json' => $alone] + fanboyDashFiles('fanboy'), [], $now)['model']['banners'][0][1] ?? null);
    // 2026-11-16 (the owner): «… bis er wieder dreht, und danach noch {min} Minuten» — the number is the state's times.clear_after;
    // an agent without it: the tile's mirror (equal to the agent's constant)
    $five = $alone;
    $five['setup']['times']['clear_after'] = 300;
    $o5 = fanboyDashWeb(['fanboy.json' => $five] + fanboyDashFiles('fanboy'), [], $now);
    $none = $alone;
    unset($none['setup']['times']);
    $o0 = fanboyDashWeb(['fanboy.json' => $none] + fanboyDashFiles('fanboy'), [], $now);
    $sensor = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-alarm.json'), true);
    $sensor['control']['alarm'] = ['code' => 'sensor', 'params' => ['source' => 'Disk 2', 'zone' => 'Plattenkäfig'], 'since' => $now - 30];
    $os = fanboyDashWeb(['fanboy.json' => $sensor] + fanboyDashFiles('fanboy'), [], $now);
    same('fanboy dash: alarm — the minutes from the state (300 s: 5; none sent: the mirror\'s 2); a silent sensor «until it reports again, and then for 2 more minutes»; German «bis er wieder dreht, und danach noch 5 Minuten»',
        [true, true, 'Alarm: Disk 2 reports nothing. Fanboy sets the fans of «Plattenkäfig» to 100 % until it reports again, and then for 2 more minutes.', true, (int) FANBOY_CLEAR_AFTER],
        [str_ends_with((string) ($o5['model']['banners'][0][1] ?? ''), 'until it turns again, and then for 5 more minutes.'),
         str_ends_with((string) ($o0['model']['banners'][0][1] ?? ''), 'until it turns again, and then for 2 more minutes.'),
         $os['model']['banners'][0][1] ?? null,
         str_ends_with((string) ($o5['de']['banners'][0][1] ?? ''), 'bis er wieder dreht, und danach noch 5 Minuten.'),
         preg_match('/const FANBOY_DASH_CLEAR_AFTER = (\d+);/', (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php'), $mm) ? (int) $mm[1] : null]);
    $state = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy.json'), true);
    $state['control']['zones'][1]['fans'][0]['rpm'] = 0;
    $o = fanboyDashWeb(['fanboy.json' => $state] + fanboyDashFiles('fanboy'), [], $now);
    same('fanboy dash: a fan of his stands without an alarm yet — said plainly at the top', [['red', 'A fan stands: Vorn']], $o['model']['banners'] ?? null);

    // the chip missing, another controller, before the setup, the array stopped
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-missing'), ['fanboy/control.json' => ['board' => 'x', 'phase' => 'wait_chip', 'time' => $now - 5, 'who' => 'bios', 'why' => 'chip_missing',
        'zones' => [], 'missing' => ['since' => $t0 - 1200, 'why' => 'missing', 'told' => true]]], $now);
    $m = $o['model'] ?? [];
    same('fanboy dash: chip missing — who and why, the banner since when (the control\'s word: another board\'s status counts not)', ['The BIOS · the fan chip is missing', 'orange', 1],
        [$m['who'] ?? null, $m['tone'] ?? null, count($m['banners'] ?? [])]);
    check('fanboy dash: chip missing — plain words, no internal names', str_contains((string) ($m['banners'][0][1] ?? ''), 'fan chip') && !preg_match('/hwmon|pwm\d|it87/', (string) ($o['rows'] ?? '')), json_encode($m));
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-other'), [], $now);
    same('fanboy dash: another controller — its name, he holds back; the connectors that report a speed, counted as connectors', ['FanCtrl Plus · Fanboy holds back', [], '4 connectors report a speed · 878–1310 rpm', false],
        [$o['model']['who'] ?? null, $o['model']['zones'] ?? null, $o['model']['before'] ?? null, $o['model']['setup'] ?? null]);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-fresh'), [], $now);
    same('fanboy dash: before the setup — not set up yet, the connectors, a link to set him up', ['The BIOS · not set up yet', '4 connectors report a speed · 878–1310 rpm', true],
        [$o['model']['who'] ?? null, $o['model']['before'] ?? null, $o['model']['setup'] ?? null]);
    check('fanboy dash: before the setup — the link to his setup', str_contains((string) ($o['rows'] ?? ''), '#/fanboy/setup"'), (string) ($o['rows'] ?? ''));
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-array'), [], $now);
    same('fanboy dash: the array stopped (his last look) — the BIOS, why; the zones without a level of his', ['The BIOS · array stopped', 'orange', ['BIOS', 'BIOS']],
        [$o['model']['who'] ?? null, $o['model']['tone'] ?? null, array_column($o['model']['zones'] ?? [], 'level')]);
    // … and as it really is: the data folder away — the mirror in RAM says the tile was on, the control's last word why
    $ended = ['board' => 'x', 'phase' => 'ended', 'time' => $now - 300, 'who' => 'bios', 'why' => 'array_stopped', 'zones' => []];
    $o = fanboyDashWeb(null, ['fanboy/dash.json' => ['v' => 1, 'show' => 'speeds'], 'fanboy/control.json' => $ended], $now);
    same('fanboy dash: the data folder away — the mirror on: the tile, the array stopped, no numbers, nothing called stale', ['speeds', 'The BIOS · array stopped', [], null, null, ''],
        [$o['gate'] ?? null, $o['model']['who'] ?? null, $o['model']['zones'] ?? null, fanboyDashKey($o['model'] ?? [], 'before'), fanboyDashKey($o['model'] ?? [], 'stale'), $o['err']]);
    $off = [];
    foreach ([[], ['fanboy/dash.json' => ['v' => 1, 'show' => 'off']], ['fanboy/dash.json' => ['show' => 'speeds']], ['fanboy/dash.json' => ['v' => 1, 'show' => 'all']]] as $run) {
        $off[] = fanboyDashKey(fanboyDashWeb(null, $run + ['fanboy/control.json' => $ended], $now), 'gate');
    }
    same('fanboy dash: the data folder away — no mirror, off, not in its shape: no tile', [null, null, null, null], $off);

    // stale: his last look old — said since when, the numbers dimmed; the control's fresh status counts over an old look
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), [], $t0 + 600);
    check('fanboy dash: stale — since when, the rows dimmed', ($o['model']['stale'] ?? null) === $t0 && str_contains((string) ($o['rows'] ?? ''), 'class="sso-fb-stale">As of ')
        && str_contains((string) ($o['rows'] ?? ''), 'sso-fb-zone sso-fb-zone-art sso-fb-old'), json_encode($o['model'] ?? null) . ($o['rows'] ?? ''));
    $live = $state['control'];
    $live['zones'][0]['level'] = 55;
    $live['zones'][1]['fans'][0]['rpm'] = 1100;
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), ['fanboy/control.json' => array_merge($live, ['board' => $state['setup']['board'], 'phase' => 'run', 'time' => $t0 + 595])], $t0 + 600);
    same('fanboy dash: the control writes (RAM) — its numbers, nothing stale though the look is old', [null, '55 %', '1100 rpm', 'Fanboy · 2 zones'],
        [fanboyDashKey($o['model'] ?? [], 'stale'), $o['model']['zones'][0]['level'] ?? null, $o['model']['zones'][1]['speed'] ?? null, $o['model']['who'] ?? null]);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), ['fanboy/control.json' => array_merge($live, ['board' => $state['setup']['board'], 'phase' => 'run', 'time' => $t0 + 400])], $t0 + 600);
    same('fanboy dash: the control silent for minutes — stale since its last word', $t0 + 400, $o['model']['stale'] ?? null);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), ['fanboy/control.json' => ['board' => $state['setup']['board'], 'phase' => 'ended', 'why' => 'crashed', 'time' => $t0 + 10, 'who' => 'bios', 'zones' => []]], $t0 + 20);
    same('fanboy dash: the control ended after his last look — the BIOS again, said plainly', ['The BIOS · his control stopped', 'orange', 'orange'],
        [$o['model']['who'] ?? null, $o['model']['tone'] ?? null, $o['model']['banners'][0][0] ?? null]);

    // many zones, many fans: never wider, never a silly height — «+ n more»; names escaped
    $many = $state;
    $many['setup']['zones'] = [];
    $many['control']['zones'] = [];
    for ($i = 1; $i <= 6; $i++) {
        $fans = [];
        for ($k = 1; $k <= ($i === 1 ? 9 : 1); $k++) {
            $fans[] = ['id' => "it8696_a0060607:it87.2608:pwm$k", 'name' => "Fan <$k>", 'output' => $k, 'pct' => 30, 'rpm' => 700 + $k * 10, 'held' => true, 'foreign' => false];
        }
        $many['setup']['zones'][] = ['id' => "z$i", 'name' => "Zone <b>$i</b>", 'fans' => array_column($fans, 'id'), 'unit' => 'pct', 'sources' => []];
        $many['control']['zones'][] = ['id' => "z$i", 'name' => "Zone $i", 'unit' => 'pct', 'level' => 30, 'warmest' => null, 'asleep' => 0, 'fans' => $fans];
    }
    $o = fanboyDashWeb(['fanboy.json' => $many] + fanboyDashFiles('fanboy'), [], $now);
    $rows = (string) ($o['rows'] ?? '');
    // (the demo's fans have names: symbols — four fans a zone since 2026-11-20; without: six, testFanboyDashFans)
    same('fanboy dash: six zones, one with nine fans — four rows, «+ 2 more zones», four fans and «+ 5 more fans», the range',
        [4, 2, 4, 5, '710–790 rpm'], [count($o['model']['zones'] ?? []), $o['model']['more'] ?? null, count($o['model']['zones'][0]['fans'] ?? []),
         $o['model']['zones'][0]['fans_more'] ?? null, $o['model']['zones'][0]['speed'] ?? null]);
    check('fanboy dash: the links to his page, names escaped', str_contains($rows, '+ 2 more zones') && str_contains($rows, '+ 5 more fans') && str_contains($rows, 'Zone &lt;b&gt;1&lt;/b&gt;')
        && !str_contains($rows, '<b>1</b>'), $rows);
}

/** The tile reads state files only and asks for texts that exist; the page puts it in a $mytiles key of its own */
function testFanboyDashSource(): void
{
    $src = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $src);
    preg_match_all('/\b(file_get_contents|fopen|file|glob|scandir|opendir|readfile|exec|shell_exec|system|passthru|popen|proc_open|askAgent|agentInfo|officeDashJobs|run|curl_init|parse_ini_file|is_file|is_dir|filemtime|stat)\s*\(/', $code, $m);
    same('fanboy dash: no read but his files — no hwmon, no disk, no agent, no command (is_dir of the data folder, is_file of his picture)',
        ['is_dir', 'is_file'], array_values(array_unique($m[1])));
    preg_match_all('/officeReadJson\(([^;]+?)\)(?:;| \?\?|\[| :|,)/', $code, $r);
    same('fanboy dash: the files it reads — the fans\' catalogue (shipped beside his page: a fan\'s look), the span (his setting, else the mirror), his setting, the mirror in RAM, his strings (the data folder away), his state, the control\'s status',
        ["OFFICE_PUBLIC . '/desks/fanboy/fans-catalog.json'", "OFFICE_DATA . '/fanboy/dashboard.json'", "officeRunDir() . '/fanboy/dash.json'",
         "(\$data ?? OFFICE_DATA) . '/fanboy/dashboard.json'", "officeRunDir() . '/fanboy/dash.json'", 'OFFICE_PUBLIC . "/desks/fanboy/lang/$code.json"',
         '$f', "OFFICE_DATA . '/fanboy.json'", "officeRunDir() . '/fanboy/control.json'"],
        array_map('trim', $r[1]));
    check('fanboy dash: … the one read through $f — the control\'s history, RAM first, else the data folder\'s copy',
        str_contains($code, "foreach ([officeRunDir() . '/fanboy/history.json', is_dir(OFFICE_DATA) ? OFFICE_DATA . '/fanboy/history.json' : ''] as \$f) {"), '');
    $page = (string) file_get_contents(OFFICE_DIR . '/plugin/SecretaryOfficeDashboard.page');
    check('fanboy dash: the Dashboard page — a key of its own beside the office\'s, only when there is a tile',
        str_contains($page, "\$mytiles['unraid-secretary-office']['column2'] = officeDashTile();")
        && str_contains($page, "if ((\$officeFanboyTile = officeFanboyDashTile()) !== '') {\n    \$mytiles['unraid-secretary-office-fanboy']['column2'] = \$officeFanboyTile;"), $page);
    $api = (string) file_get_contents(OFFICE_DIR . '/src/api.php');
    check('fanboy dash: its rows again through api.php?a=dash&part=fanboy — refused while the tile isn\'t there', str_contains($api, "apiText(\$_GET, 'part') === 'fanboy' ? apiDashFanboy(")
        && str_contains($api, "if (officeFanboyDashGate() === null) {"), '');
    // every text it asks for exists (in English; testStrings holds the five the same)
    $en = langFile(OFFICE_WEB . '/desks/fanboy/lang/en.json');
    preg_match_all("/officeDashT\\(\\\$s(?:trings)?, '([a-z0-9_.]+)'/", $src, $k);
    $missing = array_values(array_filter(array_unique($k[1]), fn ($key) => !str_ends_with($key, '.') && (!str_starts_with($key, 'fanboy.') || !isset($en[substr($key, 7)]))));
    check('fanboy dash: every text it asks for exists (' . count(array_unique($k[1])) . ')', $missing === [] && count($k[1]) > 15, json_encode($missing));
    $whys = array_filter(array_keys($en), fn ($key) => str_starts_with($key, 'dash.why.'));
    same('fanboy dash: a short why for each of the control\'s and his look\'s reasons the tile can meet', ['array_stopped', 'chip_missing', 'crashed', 'fixed', 'hand_back', 'hold',
        'ipmi', 'no_output', 'not_armed', 'not_set_up', 'other', 'released', 'starting', 'stopped'], fanboyTSorted(array_map(fn ($key) => substr($key, 9), $whys)));
    // the refresh: never faster than his state changes (the control every 10 s), only while the page is in view
    $js = substr($src, (int) strpos($src, '<script>'));
    check('fanboy dash: the refresh — 20 s while he steers, a minute else, not while the page is hidden, unfolded zones kept',
        str_contains($js, 'steers()?20000:60000') && str_contains($js, 'if(!document.hidden)load();') && !str_contains($js, 'setInterval') && str_contains($js, 'details[open]'), $js);
    check('fanboy dash: readable tones — on the dark themes the lighter steps of Unraid\'s palette, on the light ones the darker (text ≥ 4.5:1, the curve\'s line ≥ 3:1, measured by tools/fanboy/dashboard.mjs), Unraid\'s own elsewhere',
        str_contains($src, "html.Theme--black #db-sso-fanboy,html.Theme--gray #db-sso-fanboy{--sso-fb-red:var(--red-300);--sso-fb-green:var(--green-500);--sso-fb-orange:var(--orange-300);--sso-fb-link:var(--blue-700);--sso-fb-curve:var(--orange-300)}")
        && str_contains($src, "html.Theme--white #db-sso-fanboy,html.Theme--azure #db-sso-fanboy{--sso-fb-red:var(--red-900);--sso-fb-green:var(--green-900);")
        && str_contains($src, '.red-text{color:var(--sso-fb-red,var(--red-600))}')
        && str_contains($src, "--sso-fb-curve:color-mix(in srgb,var(--orange-400) 85%,var(--black))}"), '');
    check('fanboy dash: colours only Unraid\'s (its tone classes, its border variable) — no colour of its own',
        !preg_match('/#[0-9a-f]{3,6}\b|rgb\((?!128,128,128)/i', substr($src, (int) strpos($src, '<style>'), (int) strpos($src, '</style>') - (int) strpos($src, '<style>'))), '');
}

/** The setting: off by default, three values, the field read strictly, kept beside his settings (not in them), the mirror */
function testFanboyDashSetting(): void
{
    $t = fanboyT('dash');
    try {
        same('fanboy dash setting: none yet — off', 'off', fanboyDashRead());
        $codes = [];
        foreach ([[], ['show' => 1], ['show' => true], ['show' => ['speeds']], ['show' => ''], ['show' => 'on'], ['show' => 'Speeds']] as $r) {
            try {
                fanboyDashSet($r);
                $codes[] = 'kept';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy dash setting: refused — missing, a number, a switch, a list, empty, a word not known, another case', ['missing_field', 'missing_field', 'missing_field', 'missing_field', 'missing_field', 'bad_request', 'bad_request'], $codes);
        same('fanboy dash setting: … and nothing kept', [false, 'off'], [is_file(fanboyDashFile()), fanboyDashRead()]);
        $store = @filemtime(fanboyStoreFile());
        file_put_contents(fanboyDashFile(), json_encode(['v' => 1, 'show' => 'off', 'later' => ['x' => 1]]));
        $a = fanboyDashSet(['show' => 'history']);
        $hired = in_array('fanboy', staffHired(), true);
        $kept = json_decode((string) file_get_contents(fanboyDashFile()), true);
        same('fanboy dash setting: «speeds with history» kept, a key it doesn\'t know kept, his state carries it, the mirror in RAM',
            [true, 'history', 'history', ['x' => 1], 'history', ['v' => 1, 'show' => $hired ? 'history' : 'off', 'hours' => 6], 0600],
            [$a['ok'], $a['show'], $kept['show'] ?? null, $kept['later'] ?? null, $a['state']['dashboard'] ?? null,
             json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true), fileperms(fanboyDashFile()) & 0777]);
        same('fanboy dash setting: his settings (settings.json) untouched — the control takes every fan anew when they change', $store, @filemtime(fanboyStoreFile()));
        fanboyDashSet(['show' => 'speeds']);
        same('fanboy dash setting: speeds', 'speeds', fanboyDashRead());
        $read = [];
        foreach (['{"v":1,"show":"loud"}', '{"v":1,"show":1}', '[1,2]', '{"v":1,"sh', '{"show":"history"}'] as $raw) {
            file_put_contents(fanboyDashFile(), $raw);
            $read[] = fanboyDashRead();
        }
        same('fanboy dash setting: read tolerantly — a word not known, a number, another shape, a broken file: off; a file without its v still counts', ['off', 'off', 'off', 'off', 'history'], $read);
        same('fanboy dash setting: not hired — the mirror off (the tile goes with him)', ['history', ['v' => 1, 'show' => 'off', 'hours' => 6]],
            [fanboyDashState(false), json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true)]);
        same('fanboy dash setting: hired again — the mirror on', ['v' => 1, 'show' => 'history', 'hours' => 6], (fn () => [fanboyDashState(true), json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true)][1])());
        // the page: one choice of three under the server picture, sent as the action wants it
        $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
        check('fanboy dash setting: his page — three choices (off by default), the agent\'s action, office-wide (never per browser)',
            str_contains($js, "const DASH_SHOWS = ['off', 'speeds', 'history'];") && str_contains($js, "act('dashboard', { show: k }, b)")
            && str_contains($js, ": 'off';") && !preg_match('/Office\.store\([^)]*dash/', $js) && str_contains($js, 's.append(dashChoice(), watchChoice());'), '');
    } finally {
        fanboyTEnd();
    }
}

/**
 * «Speeds with history»: a small curve per zone from the control's history (RAM, else the data folder's copy; only this
 * board's, only points in fanboyHistoryThin()'s shape), a handful of spans with their peaks; round 3's connectors with
 * their count, round 4's CPU cooler under Fanboy shown as his
 */
function testFanboyDashHistory(): void
{
    $t0 = 1763100000;
    $now = $t0 + 20;
    $state = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy.json'), true);
    $board = $state['setup']['board'];
    $days = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/post/fanboy.history.json'), true)['state']['zones'];
    $part = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/post/fanboy.history.json'), true)['state'];
    $file = ['v' => 1, 'board' => $board, 'every' => 60, 'zones' => $days, 'src' => $part['src'], 'leads' => $part['leads']];
    $raw = json_encode($file);

    $o = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), ['fanboy/history.json' => $file], $now);
    $rows = (string) ($o['rows'] ?? '');
    $h = $o['hist'] ?? [];
    same('fanboy dash history: three days in RAM — per zone 24 spans of the last 6 hours, nothing more', [['case', 'cpu', 'disks'], [24, 24, 24]],
        [fanboyTSorted(array_keys($h)), array_map('count', array_values($h))]);
    // each span its peaks: the same as folding the raw points by hand — and who led at its warmest moment (2026-11-17)
    $want = array_fill(0, 24, [null, null, null, null]);
    foreach ($days['disks'] as $i => $p) {
        if ($p[0] > $now - 21600 && $p[0] <= $now) {
            $k = intdiv($p[0] - ($now - 21600) - 1, 900);
            $lead = $part['src'][$part['leads']['disks'][$i]] ?? null;
            $want[$k] = [max((float) $p[1], $want[$k][0] ?? (float) $p[1]), max((float) $p[2], $want[$k][1] ?? (float) $p[2]), max($p[3], $want[$k][2] ?? $p[3]),
                (float) $p[1] >= ($want[$k][0] ?? -1e9) ? $lead : $want[$k][3]];
        }
    }
    same('fanboy dash history: each span its warmest °C, highest level and rpm (a peak never disappears), the source that led at its warmest moment',
        json_decode(json_encode($want), true), $h['disks'] ?? null);
    check('fanboy dash history: … the demo\'s leaders are in it', count(array_filter(array_column($h['disks'] ?? [], 3))) >= 20);
    check('fanboy dash history: the rows — a curve per zone (inline SVG, no script, no link), its caption, small enough for the page (only spans, never the file)',
        substr_count($rows, '<svg class="sso-fb-curve"') === 2 && !preg_match('/<script|href="[^"]*svg|\son[a-z]+=/i', substr($rows, (int) strpos($rows, '<svg')))
        && str_contains($rows, 'class="sso-fb-span" data-hours="12" title="Change the span — next: last 12 h">Last 6 h</button>') && str_contains($rows, 'class="sso-fb-key-temp">') && str_contains($rows, 'class="sso-fb-key-lvl">level ')
        && strlen($rows) < strlen($raw) / 10 && strlen($rows) < 16000, strlen($rows) . ' of ' . strlen($raw) . ': ' . $rows);
    // the marker (2026-11-17): a span's words each — the level, the fans' speed, who led with the °C — in the markup, read
    // by the tile's script on the pointer alone; its times from data-from/data-span; the line in the SVG
    preg_match_all('/<span class="sso-fb-graph" data-from="(\d+)" data-span="(\d+)" data-m="([^"]*)">/', $rows, $g);
    $marks = json_decode(html_entity_decode($g[3][0] ?? '', ENT_QUOTES), true);
    $k = 23;
    $sp = $h['disks'][$k];
    $where = 'Disk ' . substr((string) $sp[3], 4);
    same('fanboy dash history: the marker\'s data — per zone the window\'s start and its span, 24 words, the last span\'s as the history has it',
        [[(string) ($now - 21600), (string) ($now - 21600)], ['900', '900'], 24, sprintf('%d %% · %d rpm · %s at %s °C', $sp[1], $sp[2], $where, rtrim(rtrim(number_format($sp[0], 1, '.', ''), '0'), '.'))],
        [$g[1], $g[2], count($marks ?? []), $marks[$k] ?? null]);
    check('fanboy dash history: … a line to mark in each curve, a readout in each caption, hidden until the pointer is on the curve',
        substr_count($rows, '<line class="sso-fb-mark" x1="0" x2="0" y1="0" y2="36"/></svg>') === 2 && substr_count($rows, '<small><span class="sso-fb-read"></span>') === 2);
    $tileSrc = (string) ($o['tile'] ?? '');
    $script = substr($tileSrc, (int) strpos($tileSrc, '<script>'));
    check('fanboy dash history: the marker\'s script — only on the tile\'s own box (nothing runs with the pointer elsewhere), no request, no timer of its own',
        str_contains($script, 'var bx=box();if(bx){bx.addEventListener("pointermove",mark);bx.addEventListener("pointerdown",mark);')
        && !preg_match('/(document|window)\.addEventListener\("pointer/', $script) && substr_count($script, 'fetch(') === 2 && substr_count($script, 'setTimeout(') === 1
        && str_contains($tileSrc, '#db-sso-fanboy .sso-fb-mark{display:none;') && str_contains($tileSrc, '#db-sso-fanboy .sso-fb-on small>*{display:none}'), '');
    // the spans without leaders (an older agent's file): «warmest spot»
    $o2 = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), ['fanboy/history.json' => ['v' => 1, 'board' => $board, 'every' => 60, 'zones' => $days]], $now);
    $m2 = $o2['model']['zones'][0]['curve']['marks'] ?? [];
    check('fanboy dash history: a file without leaders (an older agent) — the same curve, the marker says «warmest spot»',
        ($o2['model']['zones'][0]['curve']['svg'] ?? 0) === ($o['model']['zones'][0]['curve']['svg'] ?? 1) && str_contains((string) ($m2[23] ?? ''), ' · warmest spot at '), json_encode($m2[23] ?? null));
    $cap = $o['de']['zones'][0]['curve'] ?? [];
    check('fanboy dash history: the caption in German — the decimal comma never, whole degrees and %', (bool) preg_match('/^\d+–\d+ °C$|^\d+ °C$/u', (string) ($cap['temp'] ?? ''))
        && (bool) preg_match('/^Stufe \d+(–\d+)? %$/u', (string) ($cap['level'] ?? '')), json_encode($cap));

    // a gap stays a gap; a point alone a dot; odd points dropped as fanboyHistoryThin() drops them
    $from = $now - 21600;
    $pts = [[$from + 100, 30, 40, 600], [$from + 1000, 31, 41, 610], [$from + 9000, 35, 50, 700], [$from + 20000, 33, 45, 650], [$from + 20950, 34.5, 47, 660],
            [$from + 300, 'x', 40, 600], [$from + 400, 30, 40], ['t' => $from + 500], [(string) ($from + 600), 30, 40, 600], [$from - 100, 99, 99, 999], [$now + 100, 99, 99, 999]];
    $keptThin = array_column(fanboyHistoryThin($pts, $now), 0);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), ['fanboy/history.json' => ['v' => 1, 'board' => $board, 'zones' => ['disks' => $pts]]], $now);
    $d = $o['hist']['disks'] ?? [];
    same('fanboy dash history: points read in exactly the shape the control keeps — what fanboyHistoryThin() drops, dropped (a value not a number: null); outside the 6 hours: not in a span',
        json_decode(json_encode([[$from - 100, $from + 100, $from + 300, $from + 1000, $from + 9000, $from + 20000, $from + 20950, $now + 100], [30.0, 40.0, 600, null], [31.0, 41.0, 610, null], [35.0, 50.0, 700, null], [34.5, 47.0, 660, null], 19]), true),
        [$keptThin, $d[0] ?? null, $d[1] ?? null, $d[9] ?? null, $d[23] ?? null, count(array_filter($d, fn ($p) => $p === [null, null, null, null]))]);
    $svg = (string) ($o['model']['zones'][0]['curve']['svg'] ?? '');
    same('fanboy dash history: gaps stay gaps — three pieces of level, two temperature lines and a dot', [3, 2, 1, 3],
        [substr_count($svg, 'class="sso-fb-lvl"'), substr_count($svg, 'class="sso-fb-temp"'), substr_count($svg, 'class="sso-fb-dot"'), substr_count($svg, 'class="sso-fb-lvl-line"')]);
    same('fanboy dash history: a zone with nothing in the window — no curve, the other still has one', [null, true],
        [$o['model']['zones'][1]['curve'] ?? null, isset($o['model']['zones'][0]['curve']['svg'])]);

    // which file: RAM first, the data folder's copy else, another board's never; «speeds» never draws one
    $other = ['v' => 1, 'board' => 'Another Board Ltd. | X1', 'zones' => $days];
    $older = ['v' => 1, 'board' => $board, 'zones' => ['disks' => [[$now - 600, 44, 60, 800]]]];
    $got = [];
    foreach ([[['fanboy/history.json' => $file], []], [[], ['fanboy/history.json' => $older]], [['fanboy/history.json' => $other], ['fanboy/history.json' => $older]],
              [['fanboy/history.json' => $other], []], [['fanboy/history.json' => '{"v":1,"bo'], []], [['fanboy/history.json' => ['v' => 2] + $file], []]] as [$ram, $data]) {
        $x = fanboyDashWeb(fanboyDashFiles('fanboy', 'history') + $data, $ram, $now);
        $got[] = [array_keys($x['hist'] ?? []), $x['model']['no_curve'] ?? null];
    }
    same('fanboy dash history: RAM\'s · the data folder\'s · another board\'s in RAM: the data folder\'s · another board\'s only: none · broken · another v: none',
        [[['disks', 'case', 'cpu'], false], [['disks'], false], [['disks'], false], [[], true], [[], true], [[], true]], $got);
    $x = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), [], $now);
    check('fanboy dash history: no history yet — one plain line, no empty curves', ($x['model']['no_curve'] ?? null) === true
        && str_contains((string) ($x['rows'] ?? ''), 'No curve yet — Fanboy records one while he steers.') && !str_contains((string) ($x['rows'] ?? ''), '<svg'), (string) ($x['rows'] ?? ''));
    $x = fanboyDashWeb(fanboyDashFiles('fanboy', 'speeds'), ['fanboy/history.json' => $file], $now);
    same('fanboy dash history: «speeds» — no curve though there is a history', [null, false, false],
        [fanboyDashKey($x, 'hist'), str_contains((string) ($x['rows'] ?? ''), '<svg'), $x['model']['no_curve'] ?? null]);
    // the array stopped: the data folder away — the RAM history still draws (the mirror says «with history»)
    $x = fanboyDashWeb(null, ['fanboy/dash.json' => ['v' => 1, 'show' => 'history'], 'fanboy/history.json' => $file,
        'fanboy/control.json' => ['board' => $board, 'phase' => 'ended', 'time' => $now - 300, 'who' => 'bios', 'why' => 'array_stopped', 'zones' => []]], $now);
    same('fanboy dash history: the data folder away — only RAM read, no error', ['history', ''], [$x['gate'] ?? null, $x['err']]);

    // round 3: several fans on one connector — said on the fan's row; round 4: a CPU cooler given to him is his, not watched
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-cpu'), [], $now);
    $m = $o['model'] ?? [];
    same('fanboy dash: the CPU cooler under Fanboy (on the owner\'s risk) — in his CPU zone, its two fans on one connector said, not among the watched ones',
        ['Fanboy · 3 zones', ['Plattenkäfig', 'Gehäuse', 'CPU'], [['CPU-Kühler · 2 fans on it', '740 rpm · 40 %', false]], []],
        [$m['who'] ?? null, array_column($m['zones'] ?? [], 'name'), $m['zones'][2]['fans'] ?? null, $m['watch'] ?? null]);
    $bios = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-cpu.json'), true);
    unset($bios['control']);
    $bios['who'] = ['who' => 'bios', 'why' => 'hand_back'];
    $o = fanboyDashWeb(['fanboy.json' => $bios] + fanboyDashFiles('fanboy-cpu'), [], $now);
    same('fanboy dash: … handed back to the BIOS — still in its zone (his setup), still not «watched»; the German count',
        [['CPU-Kühler · 2 fans on it', '740 rpm', false], [], 'CPU-Kühler · 2 Lüfter daran'],
        [$o['model']['zones'][2]['fans'][0] ?? null, $o['model']['watch'] ?? null, $o['de']['zones'][2]['fans'][0][0] ?? null]);
}

/** A state whose fans have neither a look nor a name (as before 2026-11-20: the tile without symbols) */
function fanboyDashPlain(array $s): array
{
    foreach ($s['setup']['fans'] as &$f) {
        $f = ['model' => null, 'colour' => null, 'frame' => null, 'wheel' => null, 'style' => null, 'name' => ''] + $f;
    }
    unset($f);
    foreach ($s['control']['zones'] ?? [] as $i => $z) {
        foreach ($z['fans'] ?? [] as $k => $f) {
            $s['control']['zones'][$i]['fans'][$k]['name'] = '';
        }
    }
    return $s;
}

/**
 * A demo state for the fans on the tile (2026-11-20, the owner: «the fans I configured, with their symbol and speed»): the
 * demo «fanboy» with four fans of different looks in two zones — the disk cage's Noctua (its own beige), the case's be
 * quiet! and a Phanteks painted white on top, a Corsair at the rear that may stand — and the CPU cooler the BIOS keeps.
 * $edit changes it further.
 */
function fanboyDashFansState(?callable $edit = null): array
{
    $s = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy.json'), true);
    $id = static fn (int $n): string => "it8696_a0060607:it87.2608:pwm$n";
    $set = static function (int $n, array $more) use (&$s, $id): void {
        foreach ($s['setup']['fans'] as &$f) {
            if ($f['id'] === $id($n)) {
                $f = $more + $f;
            }
        }
    };
    $set(4, ['model' => ['id' => 'noctua-nf-a12x25-pwm', 'mm' => null, 'blades' => null], 'colour' => null]);
    $set(5, ['model' => ['id' => 'be-quiet-silent-wings-4-140mm-pwm', 'mm' => null, 'blades' => null], 'colour' => null]);
    $set(2, ['model' => ['id' => 'phanteks-t30-120', 'mm' => null, 'blades' => null], 'colour' => null, 'wheel' => 'white', 'found' => 'tach', 'tachs' => [2]]);
    $set(6, ['model' => ['id' => 'corsair-icue-ml120-rgb-elite', 'mm' => null, 'blades' => null], 'found' => 'tach', 'tachs' => [3], 'role' => 'case', 'off_ok' => true, 'name' => 'Hinten']);
    foreach ($s['setup']['zones'] as &$z) {
        if ($z['id'] === 'case') {
            $z['fans'] = [$id(5), $id(2), $id(6)];
        }
    }
    unset($z);
    foreach ($s['control']['zones'] as &$z) {
        if ($z['id'] === 'case') {
            $z['fans'][1]['rpm'] = 760;
            $z['fans'][] = ['id' => $id(6), 'name' => 'Hinten', 'output' => 6, 'pct' => 0, 'rpm' => 0, 'held' => true, 'foreign' => false,
                'may_stand' => true, 'stand_since' => 1763099400, 'starts_at' => null];
        }
    }
    unset($z);
    return $edit !== null ? $edit($s) : $s;
}

/** The fans on the tile in «speeds»: each with its symbol (the look his page draws), its name and its speed — once the user gave one a look or a name */
function testFanboyDashFans(): void
{
    $now = 1763100000 + 20;
    $web = static fn (array $state, string $show = 'speeds', array $run = []): array => fanboyDashWeb(['fanboy.json' => $state] + fanboyDashFiles('fanboy', $show), $run, $now);
    $art = static fn (?array $a): ?array => $a === null ? null : [$a['look']['frame'] ?? null, $a['look']['blades'] ?? null, $a['look']['style'] ?? null, $a['mm'], $a['state'], $a['rpm']];

    // fans with a look: the model's own, the user's colour over it; his page's state; the zone's line, each fan under it
    $o = $web(fanboyDashFansState());
    $m = $o['model'] ?? [];
    same('fanboy dash fans: with a look — no error, symbols on', ['', true], [$o['err'] ?? null, $m['art'] ?? null]);
    same('fanboy dash fans: each fan\'s symbol — the Noctua beige and brown, the be quiet! black, the Phanteks grey with the user\'s white wheel, the Corsair frosted and standing (still)',
        [['beige', 'brown', 'swept-narrow', 120, 'run', 1018]], array_map($art, $m['zones'][0]['art'] ?? []));
    same('fanboy dash fans: … the case zone', [['black', 'charcoal', 'sickle', 140, 'run', 905], ['grey', 'white', 'broad', 120, 'run', 760], ['black', 'frost', 'swept-wide', 120, 'run', 0]],
        array_map($art, $m['zones'][1]['art'] ?? []));
    same('fanboy dash fans: the speed range of the picture — what he measured before the catalogue (1600 rpm, 25 % = 640 rpm), the catalogue else (Phanteks: 0 = may stand, so none)',
        [[640.0, 1600.0], [null, 3000.0]], array_map(static fn (array $p): array => array_map(static fn ($v) => is_int($v) ? (float) $v : $v, $p),
            [[fanboyDashKey($m['zones'][0]['art'][0] ?? [], 'min'), fanboyDashKey($m['zones'][0]['art'][0] ?? [], 'max')], [fanboyDashKey($m['zones'][1]['art'][1] ?? [], 'min'), fanboyDashKey($m['zones'][1]['art'][1] ?? [], 'max')]]));
    $rows = (string) ($o['rows'] ?? '');
    check('fanboy dash fans: the rows — a zone line each (no folding, no speed range: each fan says its own), every fan with its symbol\'s box, name and speed',
        substr_count($rows, 'class="sso-fb-zone sso-fb-zone-art') === 2 && !str_contains($rows, '<details') && substr_count($rows, 'class="sso-fb-sym" data-fan="') === 5
        && str_contains($rows, '<span class="sso-fb-fname">Vorn</span><span class="sso-fb-fval">905 rpm · 35 %</span>') && !str_contains($rows, '0–905 rpm'), $rows);
    check('fanboy dash fans: the symbols\' data — no internal name of an output (a short key instead)', !preg_match('/hwmon|pwm\d|it87/', $rows)
        && (bool) preg_match('/^[0-9a-f]{10}$/D', (string) ($m['zones'][0]['art'][0]['id'] ?? '')), $rows);
    // standing on purpose: says so, never «0 rpm», no alarm
    same('fanboy dash fans: one that may stand and stands — «stands — it may», no red, no banner', [['Hinten', 'stands — it may', false], [], 'steht — er darf'],
        [$m['zones'][1]['fans'][2] ?? null, $m['banners'] ?? null, $o['de']['zones'][1]['fans'][2][1] ?? null]);
    $o = $web(fanboyDashFansState(static function (array $s): array {
        $s['control']['zones'][1]['fans'][2]['starts_at'] = 45;
        return $s;
    }));
    same('fanboy dash fans: … a CPU cooler\'s kind: from when it runs again', 'stands (it may) · runs again from 45 °C', $o['model']['zones'][1]['fans'][2][1] ?? null);
    // standing though it should turn: today's alarm words, the red ring
    $o = $web(fanboyDashFansState(static function (array $s): array {
        $s['control']['zones'][1]['fans'][0]['rpm'] = 0;
        return $s;
    }));
    same('fanboy dash fans: one that should turn and stands — the alarm\'s words, red, its symbol the red ring, the banner',
        [['Vorn', '0 rpm · should turn · 35 %', true], 'stopped', [['red', 'A fan stands: Vorn']]],
        [$o['model']['zones'][1]['fans'][0] ?? null, $o['model']['zones'][1]['art'][0]['state'] ?? null, $o['model']['banners'] ?? null]);
    check('fanboy dash fans: … its row red', str_contains((string) ($o['rows'] ?? ''), '<div class="sso-fb-fan sso-fb-fan-art red-text"><span class="sso-fb-sym"'), (string) ($o['rows'] ?? ''));

    // the BIOS's own, watched: on a row of its own with its symbol only with a look and a name of the user's
    $o = $web(fanboyDashFansState());
    same('fanboy dash fans: the CPU cooler the BIOS keeps (a model, a name) — its symbol, the BIOS\'s dotted frame, its row under «watched»',
        [['CPU-Kühler', '1310 rpm'], ['graphite', 'graphite', 'swept-narrow', 120, 'bios', 1310]], [$o['model']['watch'][0] ?? null, $art($o['model']['watch_art'][0] ?? null)]);
    check('fanboy dash fans: … the line «watched» without it, its row below', str_contains((string) ($o['rows'] ?? ''), '<span>With the BIOS, watched:</span></div><div class="sso-fb-watched">'), (string) ($o['rows'] ?? ''));
    foreach (['no name' => ['name' => ''], 'no look' => ['model' => null, 'colour' => null]] as $what => $more) {
        $o = $web(fanboyDashFansState(static function (array $s) use ($more): array {
            $s['setup']['fans'][0] = $more + $s['setup']['fans'][0];
            return $s;
        }));
        check("fanboy dash fans: the CPU cooler with $what — in the one line as before", ($o['model']['watch_art'] ?? null) === [null]
            && str_contains((string) ($o['rows'] ?? ''), '<span>With the BIOS, watched:</span><span>CPU-Kühler 1310 rpm</span></div>') && !str_contains((string) ($o['rows'] ?? ''), 'sso-fb-watched'), (string) ($o['rows'] ?? ''));
    }

    // no fan with a look or a name: as before — folding zones, the speed range, no symbol
    $plain = 'fanboyDashPlain';
    $o = $web(fanboyDashFansState($plain));
    $rows = (string) ($o['rows'] ?? '');
    check('fanboy dash fans: no fan with a look or a name — the tile as before (folding zones, the speed range, no symbol)', ($o['model']['art'] ?? null) === false
        && substr_count($rows, '<details class="sso-fb-zone"') === 2 && str_contains($rows, '0–905 rpm') && !str_contains($rows, 'sso-fb-sym') && !str_contains($rows, 'sso-fb-watched'), $rows);
    // a name alone counts: the neutral wheel, as his page draws «other fan»
    $o = $web(fanboyDashFansState(static function (array $s) use ($plain): array {
        $s = $plain($s);
        $s['setup']['fans'][3]['name'] = 'Käfig';
        return $s;
    }));
    same('fanboy dash fans: a name alone — symbols on, the neutral wheel (no look, 7 blades)', [true, null, 7], [$o['model']['art'] ?? null, fanboyDashKey($o['model']['zones'][0]['art'][0] ?? [], 'look'), $o['model']['zones'][0]['art'][0]['blades'] ?? null]);

    // many fans (2026-11-20, the owner): four a zone with symbols — one that stands though it should turn always among them,
    // else his page's order, shown in that order; four zones; the rest «+ n more»
    $many = static fn (bool $alarm, bool $plain = false): array => $web(fanboyDashFansState(static function (array $s) use ($alarm, $plain): array {
        $fans = [];
        $live = [];
        for ($k = 1; $k <= 9; $k++) {
            $fans[] = "it8696_a0060607:it87.2608:pwm$k";
            $live[] = ['id' => "it8696_a0060607:it87.2608:pwm$k", 'name' => "Käfig $k", 'output' => $k, 'pct' => 40, 'rpm' => $alarm && $k === 7 ? 0 : 700 + $k, 'held' => true, 'foreign' => false];
        }
        $s['setup']['zones'] = [];
        $s['control']['zones'] = [];
        for ($i = 0; $i < 6; $i++) {
            $s['setup']['zones'][] = ['id' => "z$i", 'name' => "Zone $i", 'fans' => $i === 0 ? $fans : [$fans[$i]], 'unit' => 'pct', 'sources' => []];
            $s['control']['zones'][] = ['id' => "z$i", 'name' => "Zone $i", 'unit' => 'pct', 'level' => 40, 'warmest' => null, 'fans' => $i === 0 ? $live : [$live[$i]]];
        }
        return $plain ? fanboyDashPlain($s) : $s;
    }));
    $o = $many(false);
    $rows = (string) ($o['rows'] ?? '');
    same('fanboy dash fans: many — four fans with their symbols (the first four of his page), «+ 5 more fans», four zones, «+ 2 more zones»',
        [['Käfig 1', 'Käfig 2', 'Käfig 3', 'Käfig 4'], 4, 5, 4, 2],
        [array_column($o['model']['zones'][0]['fans'] ?? [], 0), count($o['model']['zones'][0]['art'] ?? []), $o['model']['zones'][0]['fans_more'] ?? null, count($o['model']['zones'] ?? []), $o['model']['more'] ?? null]);
    check('fanboy dash fans: … the rows: 8 symbols (4 + one each in three zones + the watched CPU cooler), both «more» links', substr_count($rows, 'class="sso-fb-sym" data-fan="') === 8
        && str_contains($rows, '+ 5 more fans') && str_contains($rows, '+ 2 more zones'), $rows);
    $o = $many(true);
    same('fanboy dash fans: many, the seventh stands though it should turn — among the four (in his page\'s order), red, its ring',
        [['Käfig 1', 'Käfig 2', 'Käfig 3', 'Käfig 7'], [false, false, false, true], 'stopped', 5],
        [array_column($o['model']['zones'][0]['fans'] ?? [], 0), array_column($o['model']['zones'][0]['fans'] ?? [], 2), $o['model']['zones'][0]['art'][3]['state'] ?? null, $o['model']['zones'][0]['fans_more'] ?? null]);
    $o = $many(false, true);
    same('fanboy dash fans: many without looks or names — the folded list as before: the first six, «+ 3 more fans»', [false, 6, 3],
        [$o['model']['art'] ?? null, count($o['model']['zones'][0]['fans'] ?? []), $o['model']['zones'][0]['fans_more'] ?? null]);

    // an older state: fans without the look's keys, a control's fans without the standstill's — read with defaults
    $o = $web(fanboyDashFansState(static function (array $s): array {
        foreach ($s['setup']['fans'] as &$f) {
            $f = array_diff_key($f, ['model' => 1, 'colour' => 1, 'frame' => 1, 'wheel' => 1, 'style' => 1, 'measured' => 1, 'found' => 1, 'cpu_own' => 1, 'count' => 1]);
        }
        unset($f);
        foreach ($s['control']['zones'] as &$z) {
            foreach ($z['fans'] as &$f) {
                $f = array_diff_key($f, ['stand_since' => 1, 'starts_at' => 1, 'may_stand' => 1]);
            }
        }
        return $s;
    }));
    same('fanboy dash fans: an older state (no look\'s keys, no standstill\'s) — no error, names alone: the neutral wheels, a standing fan at 0 % plain',
        ['', true, [null, 7, 120], ['Hinten', '0 rpm · 0 %', false]],
        [$o['err'] ?? null, $o['model']['art'] ?? null, [fanboyDashKey($o['model']['zones'][0]['art'][0] ?? [], 'look'), $o['model']['zones'][0]['art'][0]['blades'] ?? null, $o['model']['zones'][0]['art'][0]['mm'] ?? null],
         $o['model']['zones'][1]['fans'][2] ?? null]);
    // a catalogue entry not known (a model of a later version): the user's colours over the neutral look, else the neutral wheel
    $o = $web(fanboyDashFansState(static function (array $s): array {
        $s['setup']['fans'][3]['model'] = ['id' => 'maker-later-fan', 'mm' => 140, 'blades' => 9];
        return $s;
    }));
    same('fanboy dash fans: a model the catalogue doesn\'t know — its size and blades from the setup, the neutral wheel', [null, 140, 9],
        [fanboyDashKey($o['model']['zones'][0]['art'][0] ?? [], 'look'), $o['model']['zones'][0]['art'][0]['mm'] ?? null, $o['model']['zones'][0]['art'][0]['blades'] ?? null]);

    // «with history» (2026-11-20, the owner: the variant built): the zones fold as before, the curves keep their room; the
    // unfolded list under each curve has the fans with their symbols (the same four); the watched in their line as before
    $o = $web(fanboyDashFansState(), 'history');
    $rows = (string) ($o['rows'] ?? '');
    check('fanboy dash fans: «with history» — the zones fold, their fans with symbols in the fold, the watched in one line',
        ($o['model']['art'] ?? null) === true && substr_count($rows, '<details class="sso-fb-zone"') === 2 && !str_contains($rows, 'sso-fb-zone-art')
        && substr_count($rows, 'class="sso-fb-sym" data-fan="') === 4 && str_contains($rows, '</summary><div class="sso-fb-fan sso-fb-fan-art"><span class="sso-fb-sym"')
        && str_contains($rows, '<span>With the BIOS, watched:</span><span>CPU-Kühler 1310 rpm</span></div>') && !str_contains($rows, 'sso-fb-watched'), $rows);
    $o = $web(fanboyDashFansState($plain), 'history');
    check('fanboy dash fans: «with history» without looks or names — as before', ($o['model']['art'] ?? null) === false && !str_contains((string) ($o['rows'] ?? ''), 'sso-fb-sym'), (string) ($o['rows'] ?? ''));
    $o = fanboyDashWeb(null, ['fanboy/dash.json' => ['v' => 1, 'show' => 'speeds'], 'fanboy/control.json' => ['phase' => 'ended', 'why' => 'array_stopped', 'who' => 'bios', 'time' => $now - 300, 'zones' => []]], $now);
    same('fanboy dash fans: the data folder away — no symbols, no error', [false, ''], [$o['model']['art'] ?? null, $o['err'] ?? null]);

    // the drawing: his page's fan-art.js, loaded only when a row asks for a symbol; no timer of its own; kept over refreshes
    $src = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    $js = substr($src, (int) strpos($src, '<script>'));
    check('fanboy dash fans: the script — fan-art.js once and only for a symbol, FanArt.wheel() without air at the tile\'s size, each wheel kept by its fan (update) and let go (destroy), drawn again after every refresh, no timer of its own',
        str_contains($js, "officeDashAsset('desks/fanboy/fan-art.js')") && str_contains($js, 'if(!asked&&l.length){asked=true;') && str_contains($js, 'f.air=false')
        && str_contains($js, 'f.px=\' . FANBOY_DASH_SYM_PX . \'') && str_contains($js, 'w.update(f)') && str_contains($js, 'old[k].destroy()') && str_contains($js, 'mood();syms();')
        && !str_contains($js, 'setInterval') && !str_contains($js, 'requestAnimationFrame'), $js);
    check('fanboy dash fans: fan-art.js rests where nothing is seen — off screen, a hidden tab, reduced motion — and turns by transform only',
        (bool) preg_match('/const go = m\.seen && !document\.hidden && !reduce\.matches;/', (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.js')), '');
}
