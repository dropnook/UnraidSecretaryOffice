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
        . ' echo json_encode(["gate" => officeFanboyDashGate(), "tile" => officeFanboyDashTile(), "rows" => officeFanboyDashRows("en", ' . $now . '),'
        . ' "model" => officeFanboyDashModel(officeFanboyDashStrings("en"), $state, $status, "en", ' . $now . '),'
        . ' "de" => officeFanboyDashModel(officeFanboyDashStrings("de"), $state, $status, "de", ' . $now . ')]);';
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

/** A demo state of tests/ui/demo as his data folder has it: switched on, hired, the setting as given */
function fanboyDashFiles(string $demo, mixed $show = 'speeds', bool $hired = true, bool $shown = true): array
{
    $files = ['fanboy.json' => (string) file_get_contents(OFFICE_DIR . "/tests/ui/demo/$demo.json"),
              'office/staff.json' => ['hired' => $hired ? ['fanboy' => 1, 'backup' => 1] : ['backup' => 1]]];
    if ($shown) {
        $files['fanboy/show.json'] = '{}';
    }
    if ($show !== null) {
        $files['fanboy/dashboard.json'] = is_string($show) && str_starts_with($show, '{') ? $show : ['v' => 1, 'show' => $show];
    }
    return $files;
}

/** The tile: there only while he is shown, hired and switched on; what it says for each demo state */
function testFanboyDashTile(): void
{
    $t0 = 1763100000;                   // the demo states' time
    $now = $t0 + 20;

    // when it is there at all
    $cases = [
        'hidden (no switch file)'  => fanboyDashFiles('fanboy', 'speeds', true, false),
        'not hired'                => fanboyDashFiles('fanboy', 'speeds', false),
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
    same('fanboy dash: no tile — hidden, not hired, switched off, no setting, an odd one, a broken file', array_fill_keys(array_keys($cases), [null, '', '']), $got);
    $o = fanboyDashWeb(fanboyDashFiles('fanboy', 'history'), [], $now);
    same('fanboy dash: «speeds with history» — the tile there', ['history', true], [$o['gate'] ?? null, str_contains((string) ($o['tile'] ?? ''), 'id="db-sso-fanboy"')]);

    // steering two zones: who, one row per zone (not per fan), the fans unfolding, the watched CPU cooler
    $o = fanboyDashWeb(fanboyDashFiles('fanboy'), [], $now);
    $tile = (string) ($o['tile'] ?? '');
    $m = $o['model'] ?? [];
    same('fanboy dash: steering — the web side without a word', '', $o['err']);
    same('fanboy dash: steering — who, no banner, nothing stale, the two zones (warmest, level, speed), the CPU cooler watched',
        ['Fanboy · 2 zones', 'green', [], null,
         [['Plattenkäfig', 'warmest: Parity at 38 °C', '42 %', '1018 rpm', 1], ['Gehäuse', 'warmest: Composite at 28 °C', '40 %', '905 rpm', 2]],
         [['CPU-Kühler', '1310 rpm']]],
        [$m['who'] ?? null, $m['tone'] ?? null, $m['banners'] ?? null, fanboyDashKey($m, 'stale'),
         array_map(fn ($z) => [$z['name'], $z['warmest'], $z['level'], $z['speed'], count($z['fans'])], $m['zones'] ?? []), $m['watch'] ?? null]);
    same('fanboy dash: steering — German: «U/min», the decimal comma', ['Fanboy · 2 Zonen', '1018 U/min', 'am wärmsten: Parität mit 38 °C'],
        [$o['de']['who'] ?? null, $o['de']['zones'][0]['speed'] ?? null, $o['de']['zones'][0]['warmest'] ?? null]);
    check('fanboy dash: the tile — Unraid\'s tile markup (a tbody titled by his English name, the header, the link to his page), each zone a fold, its own key on the page',
        str_starts_with($tile, '<tbody id="db-sso-fanboy" title="Fanboy">') && str_contains($tile, 'class="tile-header-main">Fanboy</h3>')
        && str_contains($tile, '#/fanboy"') && substr_count($tile, '<details class="sso-fb-zone') === 2 && str_contains($tile, '<div class="sso-fb-fan"><span>Deckel</span><span>no speed feedback · 40 %</span>'), $tile);

    // the alarm first and plain; a fan that stands
    $o = fanboyDashWeb(fanboyDashFiles('fanboy-alarm'), [], $now);
    $m = $o['model'] ?? [];
    same('fanboy dash: alarm — red, first, the alarm\'s own words; no second «stands» line for the same fan', [[['red', 'Alarm: Vorn stands although it should turn. Every fan Fanboy holds runs at full speed until it turns again.']], true],
        [$m['banners'] ?? null, strpos((string) ($o['rows'] ?? ''), 'sso-fb-banner red-text') < strpos((string) ($o['rows'] ?? ''), '<details')]);
    same('fanboy dash: alarm — the demo consistent: every fan he holds at full; the one that stands marked on its row, its zone open', [['100 %', '100 %'], true, true],
        [array_column($m['zones'] ?? [], 'level'), str_contains((string) ($o['rows'] ?? ''), '<div class="sso-fb-fan red-text"><span>Vorn</span><span>0 rpm · should turn · 100 %</span>'),
         str_contains((string) ($o['rows'] ?? ''), 'data-zone="case" open>')]);
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
        && str_contains((string) ($o['rows'] ?? ''), 'sso-fb-zone sso-fb-old'), json_encode($o['model'] ?? null) . ($o['rows'] ?? ''));
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
    same('fanboy dash: six zones, one with nine fans — four rows, «+ 2 more zones», six fans and «+ 3 more fans», the range',
        [4, 2, 6, 3, '710–790 rpm'], [count($o['model']['zones'] ?? []), $o['model']['more'] ?? null, count($o['model']['zones'][0]['fans'] ?? []),
         $o['model']['zones'][0]['fans_more'] ?? null, $o['model']['zones'][0]['speed'] ?? null]);
    check('fanboy dash: the links to his page, names escaped', str_contains($rows, '+ 2 more zones') && str_contains($rows, '+ 3 more fans') && str_contains($rows, 'Zone &lt;b&gt;1&lt;/b&gt;')
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
    same('fanboy dash: the files it reads — his setting, the mirror in RAM, his strings (the data folder away), his state, the control\'s status',
        ["(\$data ?? OFFICE_DATA) . '/fanboy/dashboard.json'", "officeRunDir() . '/fanboy/dash.json'", 'OFFICE_PUBLIC . "/desks/fanboy/lang/$code.json"',
         "OFFICE_DATA . '/fanboy.json'", "officeRunDir() . '/fanboy/control.json'"],
        array_map('trim', $r[1]));
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
    same('fanboy dash: a short why for each of the control\'s and his look\'s reasons the tile can meet', ['array_stopped', 'chip_missing', 'crashed', 'hand_back', 'hold',
        'ipmi', 'no_output', 'not_armed', 'not_set_up', 'other', 'released', 'starting', 'stopped'], fanboyTSorted(array_map(fn ($key) => substr($key, 9), $whys)));
    // the refresh: never faster than his state changes (the control every 10 s), only while the page is in view
    $js = substr($src, (int) strpos($src, '<script>'));
    check('fanboy dash: the refresh — 20 s while he steers, a minute else, not while the page is hidden, unfolded zones kept',
        str_contains($js, 'steers()?20000:60000') && str_contains($js, 'if(!document.hidden)load();') && !str_contains($js, 'setInterval') && str_contains($js, 'details[open]'), $js);
    check('fanboy dash: readable tones — on the dark themes the lighter steps of Unraid\'s palette, on the light ones the darker (≥ 4.5:1, measured by tools/fanboy/dashboard.mjs), Unraid\'s own elsewhere',
        str_contains($src, "html.Theme--black #db-sso-fanboy,html.Theme--gray #db-sso-fanboy{--sso-fb-red:var(--red-300);--sso-fb-green:var(--green-500);--sso-fb-orange:var(--orange-300);--sso-fb-link:var(--blue-700)}")
        && str_contains($src, "html.Theme--white #db-sso-fanboy,html.Theme--azure #db-sso-fanboy{--sso-fb-red:var(--red-900);--sso-fb-green:var(--green-900);")
        && str_contains($src, '.red-text{color:var(--sso-fb-red,var(--red-600))}'), '');
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
            [true, 'history', 'history', ['x' => 1], 'history', ['v' => 1, 'show' => $hired ? 'history' : 'off'], 0600],
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
        same('fanboy dash setting: not hired — the mirror off (the tile goes with him)', ['history', ['v' => 1, 'show' => 'off']],
            [fanboyDashState(false), json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true)]);
        same('fanboy dash setting: hired again — the mirror on', ['v' => 1, 'show' => 'history'], (fn () => [fanboyDashState(true), json_decode((string) @file_get_contents(fanboyRunDir() . '/dash.json'), true)][1])());
        // the page: one choice of three under the server picture, sent as the action wants it
        $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
        check('fanboy dash setting: his page — three choices (off by default), the agent\'s action, office-wide (never per browser)',
            str_contains($js, "const DASH_SHOWS = ['off', 'speeds', 'history'];") && str_contains($js, "act('dashboard', { show: k }, b)")
            && str_contains($js, ": 'off';") && !preg_match('/Office\.store\([^)]*dash/', $js) && str_contains($js, 's.appendChild(dashChoice());'), '');
    } finally {
        fanboyTEnd();
    }
}
