<?php
declare(strict_types=1);

/*
 * Fanboy part 3 — the page a person sets him up and lives with, and the three decisions it brought (2026-11-14): «give
 * back» to the mainboard's automatic by default (the owner), the guard's cron line only while he is armed, WAZ Cooling
 * Control a hint only. Against the fake hwmon tree (tests/fanboy-control-tests.php fanboyT()); required by tests/run.php,
 * the tests in its part `fanboy`.
 */

/** «Give back»: the mainboard's automatic by default (where the chip has one), the fixed value the other choice */
function testFanboyBack(): void
{
    same('fanboy back: the default is ONE constant — the mainboard\'s automatic (the owner, 2026-11-14)', ['auto', 1],
        [FANBOY_BACK_DEFAULT, substr_count((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-setup.php'), 'const FANBOY_BACK_DEFAULT =')]);
    same('fanboy back: as it counts — nothing chosen: the default; automatic where the chip has none: the fixed value; a choice: itself',
        ['auto', 'fixed', 'fixed', 'auto'],
        [fanboyBackOf([], ['auto' => 2]), fanboyBackOf([], ['auto' => null]), fanboyBackOf(['back' => 'fixed'], ['auto' => 2]), fanboyBackOf(['back' => 'auto'], ['auto' => 5])]);
    $chip = fn (array $o, ?string $module = 'it87') => ['outputs' => [['n' => 1] + $o], 'module' => $module];
    same('fanboy back: the chip\'s automatic mode — none without one; the mode it shows now; the one kept from before; its driver\'s; an unknown driver: none',
        [null, 5, 5, 2, 5, null],
        [fanboyAutoMode($chip(['enable' => 1, 'auto' => false]), 1), fanboyAutoMode($chip(['enable' => 5, 'auto' => true], 'nct6775'), 1),
         fanboyAutoMode($chip(['enable' => 1, 'auto' => true], 'nct6683'), 1, ['enable' => 5]), fanboyAutoMode($chip(['enable' => 1, 'auto' => true]), 1),
         fanboyAutoMode($chip(['enable' => 1, 'auto' => true], 'nct6775'), 1), fanboyAutoMode($chip(['enable' => 1, 'auto' => true], 'nct6683'), 1)]);
    $t = fanboyT('back');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        unset($settings['fans'][fanboyTId(4)]['back']);         // the disk fan: nothing chosen — the default
        fanboyTStore($settings);
        $scan = fanboyDetect();
        $before = fanboyBefore($scan);
        same('fanboy back: before Fanboy — each output on the fixed value something set (128, enable 1), the chip\'s automatic 2 (it87)',
            [true, 50, 2, 'now'], [$before[fanboyTId(4)]['fixed'], $before[fanboyTId(4)]['pct'], $before[fanboyTId(4)]['auto'], $before[fanboyTId(4)]['from']]);
        // the control takes and gives back: the disk fan (default) to the chip's automatic, the front fan («fixed») to 128
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 0.0;
        fanboyTRounds($c, $now, $t, fanboyTSpec(), 3);
        $j = fanboyJournal()['outputs'];
        same('fanboy back: taken — the journal says where each goes back: the disk fan to the automatic (128, mode 2), the front fan exactly as before',
            [['value' => 128, 'enable' => 2], null, ['value' => 128, 'enable' => 1]], [$j[fanboyTId(4)]['back'] ?? 'none', array_key_exists('back', $j[fanboyTId(5)]) ? $j[fanboyTId(5)]['back'] : 'none', $j[fanboyTId(4)]['initial'] ?? null]);
        fanboyLetGo($c, 'end');
        same('fanboy back: given back — the disk fan to the chip\'s own curve (mode 2), the front fan to its fixed 128, nothing held',
            [[128, 2], [128, 1], []], [fanboyTOut($t, 4), fanboyTOut($t, 5), fanboyHeld()]);
        same('fanboy back: … and what was there before Fanboy stays known (the journal, this boot): fixed 128', [true, 'journal'],
            [fanboyBefore(fanboyDetect())[fanboyTId(4)]['fixed'], fanboyBefore(fanboyDetect())[fanboyTId(4)]['from']]);
        // a setup step never goes anywhere but exactly back (its take clears the choice)
        file_put_contents("{$t['hw']}/pwm4_enable", "1\n");
        $step = fanboyMe('step');
        fanboyTake(['id' => fanboyTId(4), 'chip' => FANBOY_T_CHIP, 'address' => FANBOY_T_ADDR, 'device' => 'platform/' . FANBOY_T_ADDR, 'output' => 4], $step, FANBOY_T_KEY);
        same('fanboy back: a setup step\'s take — back exactly (no automatic)', null, fanboyJournal()['outputs'][fanboyTId(4)]['back']);
        fanboyGiveBack(null, 'step', $step);
        // the action: «auto» or «fixed», read strictly
        $codes = [];
        foreach ([['fan' => fanboyTId(4), 'back' => 'bios'], ['fan' => fanboyTId(4), 'back' => 1], ['fan' => fanboyTId(3) . 'x', 'back' => 'fixed']] as $r) {
            try {
                fanboyBack($r);
                $codes[] = 'kept';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy back: refused — a choice not known, a number (never cast), an output not there', ['fanboy_bad_back', 'missing_field', 'fanboy_no_output'], $codes);
        same('fanboy back: «fixed» kept for the disk fan, shown in the setup', ['fixed', 'fixed'],
            [fanboyBack(['fan' => fanboyTId(4), 'back' => 'fixed'])['fan']['back'], array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], 'back', 'id')[fanboyTId(4)]]);
    } finally {
        fanboyTEnd();
    }
}

/** The guard's cron line only while he is armed: written at «Take over», gone after «Hand back», put right at the start */
function testFanboyGuardCron(): void
{
    $sh = (string) file_get_contents(OFFICE_DIR . '/plugin/scripts/agent.sh');
    $plg = (string) file_get_contents(OFFICE_DIR . '/plugin/unraid-secretary-office.plg');
    check('fanboy guard cron: agent-watch.cron (every server) no longer carries it; the .plg\'s removal takes his file away with the watch line',
        !str_contains($sh, 'FANBOY_LINE') && !str_contains($sh, 'fanboy-guard.php watch') && str_contains($plg, 'rm -f "$flash/agent-watch.cron" "$flash/fanboy-guard.cron"'));
    $t = fanboyT('guardcron');
    try {
        $flash = "{$t['root']}/boot/config/plugins/unraid-secretary-office";
        $file = "$flash/fanboy-guard.cron";
        $GLOBALS['fanboyCronApplied'] = 0;
        // no flash folder of the plugin: nothing written (never made here)
        fanboyGuardCron(true);
        same('fanboy guard cron: no plugin folder on the flash — nothing written', [false, 0], [is_file($file), $GLOBALS['fanboyCronApplied']]);
        mkdir($flash, 0700, true);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        fanboyStart();
        same('fanboy guard cron: set up, not armed — no line at the start', false, is_file($file));
        fanboyTakeOver();
        $line = (string) @file_get_contents($file);
        same('fanboy guard cron: «Take over» writes it — every minute, only when the journal names a holder, the guard\'s watch; crontab rebuilt once',
            [true, true, true, 1], [str_starts_with(explode("\n", $line)[1] ?? '', '* * * * * grep -qs \'"owner":{\' ' . fanboyRunDir() . '/journal.json && php '),
                str_contains($line, '/agent/fanboy-guard.php watch > /dev/null 2>&1'), fileperms($file) % 512 === 0600, $GLOBALS['fanboyCronApplied']]);
        fanboyStart();
        same('fanboy guard cron: armed — the start leaves it as it is (no rebuild)', 1, $GLOBALS['fanboyCronApplied']);
        fanboyHandBack();
        same('fanboy guard cron: «Hand back» takes it away', [false, 2], [is_file($file), $GLOBALS['fanboyCronApplied']]);
        // armed in the store but the file gone (the plugin removed and installed again): the start writes it again
        $settings['armed'] = true;
        fanboyTStore($settings);
        fanboyStart();
        same('fanboy guard cron: armed and no file — the start writes it', [true, 3], [is_file($file), $GLOBALS['fanboyCronApplied']]);
        $settings['armed'] = false;
        fanboyTStore($settings);
        fanboyStart();
        same('fanboy guard cron: not armed and a file left — the start takes it away', [false, 4], [is_file($file), $GLOBALS['fanboyCronApplied']]);
    } finally {
        unset($GLOBALS['fanboyCronApplied']);
        fanboyTEnd();
    }
}

/** WAZ Cooling Control steers shelf and IPMI fans: named as a hint only, it never holds Fanboy back */
function testFanboyWaz(): void
{
    $waz = '/bin/bash /usr/local/emhttp/plugins/md12xx.fancontrol/scripts/controller-supervisor.sh';
    $t = fanboyT('waz', 10, FANBOY_T_ADDR, ['procs' => [1 => '/sbin/init', 4321 => $waz]]);
    try {
        $scan = fanboyDetect();
        $tool = array_column($scan['tools'], null, 'id')['md12xx.fancontrol'] ?? [];
        same('fanboy waz: running — known as running (active), not blocking; the situation ok, the BIOS steers «not set up», no hold',
            [true, [['md12xx.fancontrol', true, false]], 'ok', ['who' => 'bios', 'why' => 'not_set_up'], [], false],
            [$tool['active'] ?? null, array_map(fn ($c) => [$c['id'], $c['running'], $c['blocks']], $scan['controllers']), $scan['situation']['code'], $scan['who'],
             fanboyBlocking($scan['controllers']), $scan['hints_open']]);
    } finally {
        fanboyTEnd();
    }
    $t = fanboyT('waz2', 10, FANBOY_T_ADDR, ['procs' => [1 => '/sbin/init', 4321 => $waz, 4322 => '/bin/bash /usr/local/emhttp/plugins/fanctrlplus/fanctrlplus_loop.sh']]);
    try {
        $scan = fanboyDetect();
        same('fanboy waz: beside FanCtrl Plus — FanCtrl Plus alone holds him back', ['other', ['FanCtrl Plus'], ['fanctrlplus']],
            [$scan['situation']['code'], $scan['situation']['names'] ?? null, array_column(fanboyBlocking($scan['controllers']), 'id')]);
    } finally {
        fanboyTEnd();
    }
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/en.json'), true);
    check('fanboy waz: the hints say it — it steers other fans, Fanboy beside it, the guard as the net; no longer among those that hold him back',
        str_contains($en['hints.waz'], 'Fanboy steers beside it') && str_contains($en['hints.waz'], 'gives that output back') && !str_contains($en['hints.clash.1'], 'WAZ'));
}

/** The curve view's history: the part `history` (desk.json), this board's zones only */
function testFanboyHistoryPart(): void
{
    $meta = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.json'), true);
    same('fanboy history: desk.json names the part (a look a minute) and its action', ['refresh_after' => 60, 'action' => 'history'], $meta['parts']['history'] ?? null);
    $t = fanboyT('history');
    try {
        @mkdir(fanboyRunDir(), 0700, true);
        file_put_contents(fanboyRunDir() . '/history.json', json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => 60,
            'zones' => ['disks' => [[1763100000, 38.5, 42, 1018], ['bad'], [1763100060, 39.5, 44, 1040]]]]));
        $h = fanboyHistoryPart(24, 1763100100);
        same('fanboy history: the points of this board (one not in shape left out), a point a minute, its own `now`', [[[1763100000, 38.5, 42, 1018], [1763100060, 39.5, 44, 1040]], 60, 1763100100, 24],
            [$h['zones']['disks'] ?? null, $h['every'], $h['now'], $h['hours']]);
        file_put_contents(fanboyRunDir() . '/history.json', json_encode(['v' => 1, 'board' => 'Other | Board', 'zones' => ['x' => [[1, 2, 3, 4]]]]));
        same('fanboy history: another board\'s history — nothing (an empty object for the page)', '{}', json_encode(fanboyHistoryPart(24, 1763100100)['zones']));
    } finally {
        fanboyTEnd();
    }
}

/** Measuring: the table grows in the step's state as it is measured (the page shows it), gone when the step ends */
function testFanboyMeasureProgress(): void
{
    $t = fanboyT('progress');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        unset($settings['fans'][fanboyTId(4)]['measured']);
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $io = fanboyTIo($t, $spec, $clock);
        $seen = [];
        $sleep = $io['sleep'];
        $io['sleep'] = function (float $s) use ($sleep, &$seen): void {
            $sleep($s);
            $p = readJson(fanboyStepFile())['progress'] ?? null;
            if (is_array($p) && is_array($p['table'] ?? null) && (!$seen || end($seen) !== count($p['table']))) {
                $seen[] = count($p['table']);
            }
        };
        $step = fanboyStepRun(['id' => 'p1', 'kind' => 'measure', 'fans' => [fanboyTId(4)]], $io);
        check('fanboy progress: while measuring the step\'s state carries the table so far — growing a row a step', count($seen) >= 5 && $seen === range($seen[0], $seen[0] + count($seen) - 1),
            implode(',', $seen));
        same('fanboy progress: done — no progress left, the result in the store', ['done', null, 20], [$step['status'], readJson(fanboyStepFile())['progress'] ?? null,
            fanboyBoardRaw()['fans'][fanboyTId(4)]['measured']['min_pct'] ?? null]);
    } finally {
        fanboyTEnd();
    }
}

/** A fan's look (pictures only): a model from the catalogue or «other fan» by size, a colour — read strictly */
function testFanboyFanLook(): void
{
    $t = fanboyT('look');
    try {
        fanboyTStore(fanboyTSettings());
        $f = fanboyFanLook(['fan' => fanboyTId(4), 'model' => 'noctua-nf-a12x25-pwm', 'colour' => 'graphite'])['fan'];
        same('fanboy fan look: a catalogue model and a colour kept', [['id' => 'noctua-nf-a12x25-pwm', 'mm' => null, 'blades' => null], 'graphite'], [$f['model'], $f['colour']]);
        $f = fanboyFanLook(['fan' => fanboyTId(5), 'model' => '', 'mm' => 140, 'blades' => 9])['fan'];
        same('fanboy fan look: «other fan» by size and blades', ['id' => null, 'mm' => 140, 'blades' => 9], $f['model']);
        $f = fanboyFanLook(['fan' => fanboyTId(4), 'colour' => 'black'])['fan'];
        same('fanboy fan look: a colour alone leaves the model', ['noctua-nf-a12x25-pwm', 'black'], [$f['model']['id'], $f['colour']]);
        $codes = [];
        foreach ([['model' => 'Noctua NF-A12!'], ['mm' => 300], ['mm' => '120'], ['colour' => 'pink'], ['fan' => fanboyTId(3), 'colour' => 'black']] as $r) {
            try {
                fanboyFanLook($r + ['fan' => fanboyTId(4)]);
                $codes[] = 'kept';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy fan look: refused — a model id not in shape, a size out of range, a size as text, a colour not known, an empty connector',
            ['fanboy_bad_look', 'fanboy_bad_look', 'bad_request', 'fanboy_bad_look', 'fanboy_no_output'], $codes);
        $setup = array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id');
        same('fanboy fan look: the setup\'s state carries it (the pictures read it)', ['noctua-nf-a12x25-pwm', 'black', 140],
            [$setup[fanboyTId(4)]['model']['id'], $setup[fanboyTId(4)]['colour'], $setup[fanboyTId(5)]['model']['mm']]);
    } finally {
        fanboyTEnd();
    }
}

/** What the page promises: the wizard's order, the step kept alive, a step stoppable, the pictures, two pages, the states' shape */
function testFanboyDesk(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    same('fanboy desk: the wizard\'s steps in part 2\'s order', "const STEPS = ['detect', 'test', 'roles', 'measure', 'zones', 'dry', 'take'];",
        preg_match('/^const STEPS = .*$/m', $js, $m) ? $m[0] : null);
    preg_match('/const ALIVE_MS = (\d+);/', $js, $a);
    check('fanboy desk: a running step kept alive — step_alive every ALIVE_MS (≤ 20 s, well inside the 45 s lease), only while a page of his is shown, stopped when it goes',
        isset($a[1]) && (int) $a[1] <= 20000 && (int) $a[1] < FANBOY_STEP_LEASE * 1000
        && str_contains($js, "Office.api.post(`\${ID}.step_alive`, {})") && str_contains($js, 'alive = setInterval(beat, ALIVE_MS);')
        && str_contains($js, "if (!view || !stepRuns() || !Office.agent.running) return;") && preg_match('/unmount\(\) \{[^}]*stopAlive\(\);/s', $js) === 1);
    check('fanboy desk: a step can be stopped any time (step_stop beside every running step)', str_contains($js, "Office.api.post(`\${ID}.step_stop`, {})")
        && substr_count($js, "box.appendChild(runningLine(s, ") === 2);
    $at = substr($js, (int) strpos($js, 'function stepAtHand()'), 1400);
    $order = array_map(fn ($k) => strpos($at, "return '$k'"), ['detect', 'test', 'roles', 'measure', 'zones']);
    check('fanboy desk: the step at hand — the first not done, in the wizard\'s order', !in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order == array_values(fanboyTSorted($order)));
    check('fanboy desk: the honest return — a step ended by the page gone or interrupted said plainly on both pages',
        str_contains($js, "['page_gone', 'interrupted'].includes(s2.why)") && str_contains($js, "function stepEnded("));
    check('fanboy desk: fan-art.js loaded once beside desk.js (it brings fan-art.css), the picture kept across looks (update, never a new one)',
        str_contains($js, "fan-art.js\${q || '?'}") && str_contains($js, 'window.FanArt.styles();') && str_contains($js, 'if (!pic) pic = A.server(p, { texts, px: ') && str_contains($js, 'else pic.update(p);'));
    check('fanboy desk: two pages (overview, setup) — never more', str_contains($js, "const PAGES = () => [{ id: '', text: t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];"));
    check('fanboy desk: «give back» — the automatic preselected, the fixed value where the chip has none, said plainly',
        str_contains($js, "if (cur === 'auto' && noAuto) cur = 'fixed';") && str_contains($js, "T('back.no_auto_note'"));
    check('fanboy desk: no logo, no picture of a maker — their names as text only', !preg_match('/<img|\.png|\.svg|logo/i', $js));
    // round 2 (owner): the picture the hero, the lists short, the sections in reading order
    preg_match_all("/\{ id: '([a-z]+)', key: 'tile\.[a-z]+', place: 'tile\.[a-z]+', tile: [a-zA-Z]+, section: \(\) => ([a-zA-Z]+)\(\) \}/", $js, $tiles);
    same('fanboy desk: tile order = section order — the picture, today\'s curves, the fans, the heat sources, the hints',
        [['who', 'today', 'fans', 'hottest', 'hints'], ['serverSection', 'curvesSection', 'fansSection', 'sensorsSection', 'hintsSection']], [$tiles[1], $tiles[2]]);
    preg_match('/const PIC_PX = (\d+);/', $js, $px);
    check('fanboy desk: the server picture\'s wheels big (fan-art\'s default 46)', (int) ($px[1] ?? 0) >= 72);
    check('fanboy desk: the long lists — first what counts, the rest behind one fold each (keepFold: kept while the page lives, never per browser), the filter over both',
        str_contains($js, "moreFold('sensors-more', rest, T('sensors.more', { n: rest.length }), f.words.length > 0)") && str_contains($js, "moreFold('fans-more', rest, T('fans.more', { n: rest.length }), false)")
        && str_contains($js, "const det = keepFold(el('details', 'fb-more'), filtering ? `\${key}:filtered` : key, !!filtering);")
        && preg_match_all('/Office\.store\(\'([^\']+)\'/', $js, $kept) >= 1 && array_unique($kept[1]) === ['fanboy.period'] && str_contains($js, 'bar.done(fits.length, all.length, sw.note());'));
    // the demo states: the setup's keys as a real look on the fake tree has them
    $t = fanboyT('deskstate');
    try {
        fanboyTStore(fanboyTSettings());
        $real = array_keys(fanboySetupState(fanboyDetect(), fanboyBoardRaw()));
        $fan = array_keys(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'][0]);
    } finally {
        fanboyTEnd();
    }
    $bad = [];
    $files = glob(OFFICE_DIR . '/tests/ui/demo/fanboy*.json') ?: [];
    foreach ($files as $f) {
        $s = json_decode((string) file_get_contents($f), true);
        if (basename($f) === 'fanboy-history.json') {
            continue;
        }
        if (!is_array($s['setup'] ?? null) || array_keys($s['setup']) !== $real || array_filter($s['setup']['fans'], fn ($x) => array_keys($x) !== $fan)) {
            $bad[] = basename($f);
        }
    }
    same('fanboy desk: every demo state\'s setup in the shape fanboySetupState() gives (' . count($files) . ' files)', [], $bad);
    $names = array_map(fn ($f) => basename($f, '.json'), $files);
    same('fanboy desk: a demo state for each situation and each step of the wizard', [],
        array_values(array_diff(['fanboy', 'fanboy-alarm', 'fanboy-missing', 'fanboy-other', 'fanboy-waz', 'fanboy-ipmi', 'fanboy-nooutput', 'fanboy-array',
            'fanboy-fresh', 'fanboy-test', 'fanboy-ask', 'fanboy-roles', 'fanboy-measure', 'fanboy-zones', 'fanboy-ready', 'fanboy-gone', 'fanboy-history'], $names)));
}
function fanboyTSorted(array $a): array
{
    sort($a);
    return $a;
}

/** His words: five languages with the same keys, every key the page asks for there, plain where it is about safety, «du» */
function testFanboyDeskWords(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $l = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    preg_match_all("/\\bT\\('([a-z0-9_.-]+)'/", $js, $m);
    same('fanboy words: every key the page asks for is in en', [], array_values(array_unique(array_diff($m[1], array_keys($l['en'])))));
    same('fanboy words: the same keys in all five', [[], [], [], []],
        array_map(fn ($c) => array_values(array_diff(array_keys($l['en']), array_keys($l[$c]))), ['de', 'fr', 'it', 'es']));
    $internal = [];
    foreach ($l as $c => $strings) {
        foreach ($strings as $k => $v) {
            foreach ((array) $v as $text) {
                if (preg_match('/\bpwm\d|hwmon|\.json\b|\.cfg\b|\.php\b|\/sys\/|fan\d_input/i', (string) $text)) {
                    $internal[] = "$c:$k";
                }
            }
        }
    }
    same('fanboy words: no internal name on the page (outputs\' files, hwmon, file names) — those only in a row\'s technical detail', [], $internal);
    $plain = [];
    foreach ($l as $c => $strings) {
        foreach ($strings as $k => $v) {
            if (preg_match('/^(alarm\.|step\.ended\.|errors\.|control\.|situation\.|back\.|take\.hand_back|bubble\.alarm|notify\.)/', $k) && preg_match('/!(?!=)/', implode(' ', (array) $v))) {
                $plain[] = "$c:$k";
            }
        }
    }
    same('fanboy words: what is about safety stays plain (no exclamation: alarms, ended steps, errors, the control, giving back)', [], $plain);
    $sie = array_keys(array_filter($l['de'], fn ($v) => preg_match('/\b(Ihnen|Ihre[mnrs]?|Ihr)\b/', implode(' ', (array) $v))));
    same('fanboy words: German says «du»', [], $sie);
    same('fanboy words: the folds\' counts are plurals in all five', [true, true, true, true, true],
        array_map(fn ($c) => is_array($l[$c]['sensors.more'] ?? null) && is_array($l[$c]['fans.more'] ?? null), array_keys($l)));
    check('fanboy words: his bubbles may cheer (the setup\'s and the steering\'s)', str_contains($l['en']['bubble.testing'], '!') && str_contains($l['de']['bubble.ready'], '!'));
}

/**
 * Round 3 (the owner): 3 days of history, small and bounded — the last 12 hours a point a minute, older ones a 5-minute
 * span carrying its warmest, highest level and rpm (a peak never disappears), nothing older than 3 days, at most
 * FANBOY_HISTORY_MAX a zone; through a restart (the data folder's copy), an older file read as it is, a zone removed
 * gone after 3 days; the page asks for a period (hours, read strictly) — all on the tests' clock
 */
function testFanboyHistoryThin(): void
{
    $w0 = 1763100000;
    $minutes = fn (int $from, int $to, int $wall) => array_map(fn ($m) => [$wall - $m * 60, 30.0 + ($m % 7), 20 + ($m % 5), 600 + $m % 3], range($from, $to));
    $pts = $minutes(4 * 24 * 60, 0, $w0);                       // 4 days of minutes, oldest first … newest last
    $pts = array_reverse($pts);
    $pts[] = [$w0 - 40 * 3600 + 17, 51.5, 97, 1590];             // a peak in the older part, between two minutes
    usort($pts, fn ($a, $b) => $a[0] <=> $b[0]);
    $kept = fanboyHistoryThin($pts, $w0);
    $recent = array_filter($kept, fn ($p) => $p[0] >= $w0 - FANBOY_HISTORY_FULL);
    $older = array_filter($kept, fn ($p) => $p[0] < $w0 - FANBOY_HISTORY_FULL);
    same('fanboy history thin: 12 h of minutes as they are, 60 h of 5-minute spans, nothing older than 3 days — at most the bound', [721, 720, true, true],
        [count($recent), count($older), min(array_column($kept, 0)) >= $w0 - FANBOY_HISTORY_SPAN, count($kept) <= FANBOY_HISTORY_MAX]);
    $span = array_values(array_filter($older, fn ($p) => intdiv($p[0], 300) === intdiv($w0 - 40 * 3600 + 17, 300)))[0] ?? null;
    same('fanboy history thin: the peak survives in its span — warmest 51.5 °C, level 97 %, 1590 rpm', [51.5, 97, 1590], [$span[1] ?? null, $span[2] ?? null, $span[3] ?? null]);
    same('fanboy history thin: once more changes nothing (a span stays a span)', $kept, fanboyHistoryThin($kept, $w0));
    same('fanboy history thin: a point not in shape left out (3 values, a string time, nulls kept as null)', [[$w0, null, null, 400]],
        fanboyHistoryThin([[$w0, 30, 20], ['x', 30, 20, 400], [$w0, null, null, 400]], $w0));
    $t = fanboyT('historythin');
    try {
        // the control a minute at a time on the tests' clock for 3 days and a half: bounded, a zone removed lets go
        $c = fanboyControlNew(fanboyTSettings(), FANBOY_T_KEY, fanboyMe('control'));
        $c['hist']['gone'] = [[$w0 - 3600, 40.0, 50, 900]];       // a zone no longer in the settings
        $mono = 0.0;
        for ($m = 0; $m <= 84 * 60; $m++) {
            fanboyHistory($c, $mono, $w0 + $m * 60);
            $mono += 60.0;
        }
        same('fanboy history thin: the control for 3½ days — each zone 12 h of minutes + 60 h of spans (1441, within the bound), the removed zone gone after 3 days',
            [1441, 1441, true, false], [count($c['hist']['disks']), count($c['hist']['case']), count($c['hist']['disks']) <= FANBOY_HISTORY_MAX, isset($c['hist']['gone'])]);
        // a restart: the data folder's copy read back as kept
        @mkdir(fanboyRunDir(), 0700, true);
        fanboyHistoryKeep($c, true);
        @unlink(fanboyRunDir() . '/history.json');
        $wall = $w0 + 84 * 60 * 60;
        same('fanboy history thin: after a restart (RAM gone) the data folder\'s copy — the same points', $c['hist'], fanboyHistoryLoad(FANBOY_T_KEY, $wall));
        // an older file (a day of minutes, today's shape before round 3): read as it is, thinned on reading
        file_put_contents(FANBOY_DIR . '/history.json', json_encode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => 60,
            'zones' => ['disks' => array_reverse($minutes(1439, 0, $wall))]]));
        $old = fanboyHistoryLoad(FANBOY_T_KEY, $wall);
        same('fanboy history thin: an older file — a day of minutes: 12 h as they are, the older 12 h in 5-minute spans', [721, 144],
            [count(array_filter($old['disks'], fn ($p) => $p[0] >= $wall - FANBOY_HISTORY_FULL)), count(array_filter($old['disks'], fn ($p) => $p[0] < $wall - FANBOY_HISTORY_FULL))]);
        // the page's period: only its points go out
        $p1 = fanboyHistoryPart(1, $wall);
        same('fanboy history thin: the period asked for — 1 h: 61 points, every one inside the hour', [1, 61, true],
            [$p1['hours'], count($p1['zones']['disks']), min(array_column($p1['zones']['disks'], 0)) >= $wall - 3600]);
        $codes = [];
        foreach ([[], ['hours' => 72], ['hours' => '72'], ['hours' => 5], ['hours' => 0], ['hours' => 72.0]] as $r) {
            try {
                $codes[] = fanboyHistoryHours($r);
            } catch (Problem $e) {
                $codes[] = $e->key;
            }
        }
        same('fanboy history thin: the period read strictly — none: a day (the part); 72; text, a period not kept, 0, a float: refused',
            [24, 72, 'bad_request', 'fanboy_bad_period', 'bad_request', 'bad_request'], $codes);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    check('fanboy history thin: the page — one period for every zone (1 h … 3 days, default 6 h), remembered per browser (a choice), 3 days asked for on their own',
        str_contains($js, 'const PERIODS = [1, 3, 6, 12, 24, 72];') && str_contains($js, 'const PERIOD_DEFAULT = 6;') && str_contains($js, "Office.store('fanboy.period'")
        && str_contains($js, "Office.api.post(`\${ID}.history`, { hours: 72 })") && str_contains($js, "T('curves.since', { time: durationText(now - shown[0][0]) })"));
}

/** His picture: a case fan seen from the front (avatar.svg), its alarm variant, packed with the desk */
function testFanboyAvatar(): void
{
    $dir = OFFICE_WEB . '/desks/fanboy';
    $a = (string) @file_get_contents("$dir/avatar.svg");
    $b = (string) @file_get_contents("$dir/avatar-alarm.svg");
    check('fanboy avatar: avatar.svg — 64×64 like every desk\'s, a title saying what it shows, four corner holes, a hub, no text, no link, no script',
        str_contains($a, 'viewBox="0 0 64 64" width="64" height="64"') && str_contains($a, '<title>Fanboy: a case fan seen from the front</title>')
        && substr_count($a, '<circle cx="12.5"') + substr_count($a, '<circle cx="51.5"') === 4 && !preg_match('/<text|<a\b|<script|href=|on[a-z]+=/i', $a));
    check('fanboy avatar: avatar-alarm.svg — the same fan with the red mark (as the Night Watchman\'s alert), set while an alarm, a missing chip or a stopped control holds',
        str_starts_with(str_replace('an alarm at his fans', 'a case fan seen from the front', $b), rtrim(str_replace('</svg>', '', $a))) && str_contains($b, 'fill="#D9453B"')
        && str_contains((string) file_get_contents("$dir/desk.js"), "Office.setDeskMood(ID, (control().running && control().alarm) || ['chip_missing', 'crashed'].includes(who().why) ? 'alarm' : '');"));
    $build = (string) file_get_contents(OFFICE_DIR . '/plugin/build.sh');
    check('fanboy avatar: the package takes public/ whole (build.sh), so both drawings ship; the emoji in desk.json stays the fallback',
        (bool) preg_match('/\bpublic\b/', $build) && (json_decode((string) file_get_contents("$dir/desk.json"), true)['icon'] ?? '') === '🌀');
}

/** Several fans on one connector (round 3): how many, said in the roles step — stored, read strictly; the words count connectors */
function testFanboyCount(): void
{
    $t = fanboyT('count');
    try {
        fanboyTStore(fanboyTSettings());
        $id = fanboyTId(1);
        same('fanboy count: none stored — one fan on the connector', 1, array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], 'count', 'id')[$id]);
        fanboyRole(['fan' => $id, 'role' => 'cpu', 'name' => 'CPU', 'count' => 2]);
        fanboyRole(['fan' => $id, 'role' => 'cpu', 'name' => 'CPU cooler']);
        same('fanboy count: two fans on the CPU cooler\'s connector — kept when the role is saved again without it', [2, 'CPU cooler'],
            [fanboyBoardRaw()['fans'][$id]['count'] ?? null, fanboyBoardRaw()['fans'][$id]['name'] ?? null]);
        $codes = [];
        foreach ([['count' => '2'], ['count' => 0], ['count' => 9], ['count' => 2.0]] as $r) {
            try {
                fanboyRole(['fan' => $id, 'role' => 'cpu'] + $r);
                $codes[] = 'kept';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy count: read strictly — text, 0, 9, a float refused', ['bad_request', 'bad_request', 'fanboy_bad_count', 'bad_request'], $codes);
        same('fanboy count: the setup\'s state carries it (the picture draws as many wheels)', 2, array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], 'count', 'id')[$id]);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $l = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    check('fanboy count: the page — «How many fans on it?» sent with the role, as many wheels in the picture sharing one speed, the tile counting connectors',
        str_contains($js, "count: Number(count.value) }") && str_contains($js, 'fans: (z.fans || []).flatMap((id) => wheelsOf(id)),')
        && str_contains($js, "T('fans.reporting', { n: turning })") && !str_contains($js, "'fans.turning'"));
    same('fanboy count: «connectors report a speed» in five languages as a plural; the hints say what a Y-cable or hub hides', [true, true, true, true, true],
        array_map(fn ($c) => is_array($l[$c]['fans.reporting'] ?? null) && isset($l[$c]['hints.hub.1'], $l[$c]['hints.hub.2']), array_keys($l)));
    check('fanboy count: «4 Anschlüsse melden Drehzahl»', ($l['de']['fans.reporting']['other'] ?? '') === '{n} Anschlüsse melden Drehzahl');
}

/**
 * The warmest spots per kind (round 3): hard disks and SSD/NVMe apart, Unraid's limits (a disk's own, else the global one
 * of its kind — dynamix.cfg over the webGui's defaults; read only), the lead by its limit, a sleeping disk asleep, no
 * limit made up
 */
function testFanboyHottestKinds(): void
{
    $ini = fn (array $d) => implode('', array_map(fn ($x) => "[{$x[0]}]\nname=\"{$x[0]}\"\ntype=\"{$x[1]}\"\ndevice=\"{$x[2]}\"\nid=\"EXAMPLE_" . strtoupper($x[0]) . "\"\ntemp=\"{$x[3]}\"\n"
        . 'spundown="' . ($x[3] === '*' ? 1 : 0) . "\"\nrotational=\"{$x[4]}\"\nstatus=\"DISK_OK\"\n" . ($x[5] ?? ''), $d));
    $t = fanboyT('hotkinds', 10, FANBOY_T_ADDR, ['files' => [
        'usr/local/emhttp/plugins/dynamix/default.cfg' => "[display]\nhot=\"45\"\nmax=\"55\"\nhotssd=\"60\"\nmaxssd=\"70\"\n",
        'boot/config/plugins/dynamix/dynamix.cfg' => "[display]\ntheme=\"black\"\nhot=\"42\"\nmaxssd=\"\"\n"]]);
    try {
        file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $ini([['parity', 'Parity', 'sdb', '41', '1'], ['disk1', 'Data', 'sdc', '*', '1'],
            ['disk2', 'Data', 'sdd', '39', '1', "hotTemp=\"38\"\nmaxTemp=\"48\"\n"], ['cache', 'Cache', 'nvme0n1', '50', '0']]));
        same('fanboy hottest: Unraid\'s limits — the user\'s global HDD warning (42) over the default, the rest the defaults (an empty value: the default)',
            ['hot' => 42, 'max' => 55, 'hotssd' => 60, 'maxssd' => 70], fanboyDiskLimits(fanboyPaths()));
        $s = fanboyDetect();
        same('fanboy hottest: each disk\'s limits — its own (disk2: 38/48), else the global of its kind (HDD 42/55, SSD 60/70)',
            [['parity', 42, 55], ['disk1', 42, 55], ['disk2', 38, 48], ['cache', 60, 70]], array_map(fn ($d) => [$d['name'], $d['warn'], $d['crit']], $s['disks']));
        $k = array_column($s['hottest_kinds'], null, 'kind');
        same('fanboy hottest: per kind — HDD the warmest of the rotating ones (parity 41 °C, one asleep), SSD/NVMe apart (the NVMe pool 50 °C)',
            [['parity', 41, 1], ['cache', 50]], [[$k['hdd']['warmest']['disk'], $k['hdd']['warmest']['c'], $k['hdd']['asleep']], [$k['ssd']['warmest']['disk'], $k['ssd']['warmest']['c']]]);
        same('fanboy hottest: the lead — not the hottest number but the one over its limit: disk2 at 39 °C over its own 38; the NVMe at 50 far from 60',
            [39, 'disk2', 38, 1, 'warn'], array_values(array_intersect_key($s['hottest_lead'], array_flip(['disk', 'c', 'warn', 'margin', 'over']))));
        same('fanboy hottest: the CPU, the board, the RAM — shown, never judged (no limit made up)', [null, null, null], [$k['cpu']['near'], $k['board']['near'], $k['ram']['near']]);
        same('fanboy hottest: the old `hottest` stays (an older page reads it)', 50.0, (float) $s['hottest']['c']);
        // every hard disk asleep: the kind says so, never cold
        file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $ini([['parity', 'Parity', 'sdb', '*', '1'], ['disk1', 'Data', 'sdc', '*', '1']]));
        $k = array_column(fanboyDetect()['hottest_kinds'], null, 'kind');
        same('fanboy hottest: all hard disks asleep — no warmest, two asleep, nothing near a limit', [null, 2, null], [$k['hdd']['warmest'], $k['hdd']['asleep'], $k['hdd']['near']]);
        // no limits known (no dynamix files): no judgement at all
        unlink("{$t['root']}/usr/local/emhttp/plugins/dynamix/default.cfg");
        unlink("{$t['root']}/boot/config/plugins/dynamix/dynamix.cfg");
        file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", $ini([['parity', 'Parity', 'sdb', '47', '1']]));
        $s = fanboyDetect();
        same('fanboy hottest: no limit known anywhere — the disk\'s value, no warning made up, no lead', [47, null, null], [$s['hottest_kinds'][0]['warmest']['c'], $s['disks'][0]['warn'], $s['hottest_lead']]);
    } finally {
        fanboyTEnd();
    }
    $en = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/en.json'), true);
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    check('fanboy hottest: the tile — plural, the lead by its limit (Unraid\'s or the user\'s), «All calm» without colour when nothing is near',
        $en['tile.hottest'] === 'Warmest spots' && str_contains($js, 'return lead && lead.c - lead.limit >= -LEAD_NEAR ? lead : null;')
        && str_contains($js, "if (!lead) return Office.stat({ label: T('tile.hottest'), value: T('hottest.calm'), sub: kinds.slice(0, 4).map(line), ...a });"));
}

/** Round 3: no text twice on a page, the plain line to switch the others off, the FanCtrl Plus import gone */
function testFanboyRound3Words(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $l = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    same('fanboy round 3: the banner\'s texts once — another controller only in the banner; a step that can\'t run says its own short line',
        [1, 0, 2], [substr_count($js, "T('situation.other'"), substr_count($js, "T('step.steering')"), substr_count($js, "T('step.blocked_other')")]);
    check('fanboy round 3: the owner\'s words — «Deaktiviere die anderen Lüftersteuerungen bitte zuerst.», «Please switch off the other fan controls first.»',
        str_ends_with($l['de']['hints.clash.1'], 'Deaktiviere die anderen Lüftersteuerungen bitte zuerst.') && str_ends_with($l['en']['hints.clash.1'], 'Please switch off the other fan controls first.'));
    $clumsy = [];
    foreach ($l as $c => $strings) {
        foreach ($strings as $k => $v) {
            if (preg_match('/selbst aus|switch it off yourself|arrête-le toi-même|désactive-le d\'abord toi-même|lo spegni tu|lo apagas tú/i', implode(' ', (array) $v))) {
                $clumsy[] = "$c:$k";
            }
        }
    }
    same('fanboy round 3: the clumsy «you switch it off yourself» nowhere any more', [], $clumsy);
    $agent = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy.php');
    $keys = array_filter(array_keys($l['en']), fn ($k) => str_starts_with($k, 'import.') || str_starts_with($k, 'zones.import'));
    check('fanboy round 3: the FanCtrl Plus import gone — no action, no file, no part of the state, no strings, nothing on the page',
        !is_file(OFFICE_DIR . '/agent/desks/fanboy-import.php') && !str_contains($agent, "'import'") && !str_contains($agent, 'fanboyImport') && !$keys
        && !preg_match('/\bimport(Block|AsZones)\b|S\(\)\.import/', $js));
}

/**
 * Round 4, safety (2026-11-15, found by the real agent on a test VM): a blocked fan whose floor lies under its start value
 * (measured lowest 25 % → floor 30 %, start 40 %) was kicked to 40 % and back for ever, each kick resetting the «stands»
 * clock — no alarm. The suite missed it: every stands test ran with floor ≥ start (the hub unmeasured: floor = start
 * = 40 %; the disk fan floor 30 = start 30), so no kick happened. Now: the clock runs across kicks, two kicks at most.
 */
function testFanboyStandsKick(): void
{
    $t = fanboyT('standskick');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['fans'][fanboyTId(4)]['measured']['start_pct'] = 40;       // floor 25 + 5 = 30 < start 40
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        same('fanboy stands kick: the case the suite never had — the disk fan\'s floor under its start value', [30, 40], [$c['fans'][fanboyTId(4)]['floor'], $c['fans'][fanboyTId(4)]['start']]);
        $now = 3000.0;
        fanboyTRounds($c, $now, $t, $spec, 3);
        $stuck = fanboyTSpec(10, [4 => ['stuck' => true]]);
        $written = [];
        $alarmAt = null;
        for ($i = 0; $i < 40 && $alarmAt === null; $i++) {
            fanboyTRounds($c, $now, $t, $stuck, 1);
            $written[] = fanboyTOut($t, 4)[0];
            if ($c['alarm'] !== null) {
                $alarmAt = $i * FANBOY_ROUND;
            }
        }
        $kicks = 0;
        for ($i = 1; $i < count($written); $i++) {
            $kicks += $written[$i] === 102 && $written[$i - 1] !== 102 ? 1 : 0;      // 40 % = 102
        }
        check('fanboy stands kick: blocked — alarm «stands» within FANBOY_STAND_AFTER and a margin (' . ($alarmAt ?? 'never') . ' s)',
            $alarmAt !== null && $alarmAt <= FANBOY_STAND_AFTER + 2 * FANBOY_ROUND && ($c['alarm']['code'] ?? null) === 'stands');
        same('fanboy stands kick: … kicked twice at most (no flapping 30 % ↔ 40 % in what was written), then full speed', [true, 255],
            [$kicks <= FANBOY_KICK_MAX, fanboyTOut($t, 4)[0]]);
        fanboyTRounds($c, $now, $t, $stuck, 20);
        same('fanboy stands kick: … exactly one notification however long it stands', 1, count(array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, '-i alert'))));
        // freed: it turns — the alarm clears after FANBOY_CLEAR_AFTER, the curve again
        fanboyTRounds($c, $now, $t, $spec, (int) ceil(FANBOY_CLEAR_AFTER / FANBOY_ROUND) - 2);
        same('fanboy stands kick: freed — still in alarm before FANBOY_CLEAR_AFTER', 'stands', $c['alarm']['code'] ?? null);
        fanboyTRounds($c, $now, $t, $spec, 6);
        same('fanboy stands kick: … then over, the kicks counted from nought again', [null, 0], [$c['alarm'], $c['fans'][fanboyTId(4)]['kicks']]);
        fanboyLetGo($c, 'end');

        // a control started again three times while the fan stays blocked: the clock goes on, the third one alarms
        $rounds = (int) ceil(8 / FANBOY_ROUND);          // 8 s each: alone never 15 s, carried the third passes it
        $alarmed = [];
        for ($k = 0; $k < 3; $k++) {
            $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
            fanboyStandCarry($c, readJson(fanboyStatusFile()), $now);
            fanboyTRounds($c, $now, $t, $stuck, $rounds);
            fanboyStatusWrite(fanboyStatus($c));
            $alarmed[] = $c['alarm']['code'] ?? null;
            fanboyLetGo($c, 'restart');
        }
        same('fanboy stands kick: three restarts of 8 s each with the fan blocked — the clock carried, the third alarms', [null, null, 'stands'], $alarmed);
    } finally {
        fanboyTEnd();
    }
}

/** disks.ini for the round-4 kind tests: [name, type, device, temp ('*' asleep), rotational] */
function fanboyTDisksKinds(array $t, array $disks): void
{
    file_put_contents("{$t['root']}/var/local/emhttp/disks.ini", implode('', array_map(fn ($x) => "[{$x[0]}]\nname=\"{$x[0]}\"\ntype=\"{$x[1]}\"\ndevice=\"{$x[2]}\"\n"
        . 'id="EXAMPLE_' . strtoupper($x[0]) . "\"\ntemp=\"{$x[3]}\"\nspundown=\"" . ($x[3] === '*' ? 1 : 0) . "\"\nrotational=\"{$x[4]}\"\nstatus=\"DISK_OK\"\n", $disks)));
}

/**
 * Round 4 (the real agent on a test VM): an NVMe pool at an ordinary 50 °C stopped every measuring as «warm» — every
 * disk was held to the hard disks' 52 − 5 °C. Now each source by its own kind and limit (a zone's own once set), and
 * the step says which source, how warm and the limit.
 */
function testFanboyNvmeWarm(): void
{
    $t = fanboyT('nvmewarm');
    try {
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '35', '1'], ['disk1', 'Data', 'sdc', '*', '1'], ['cache', 'Cache', 'nvme0n1', '50', '0']]);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        unset($settings['fans'][fanboyTId(4)]['measured']);
        fanboyTStore($settings);
        same('fanboy nvme warm: the NVMe pool at 50 °C, the hard disks at 35 — nothing too warm (the SSD\'s limit 70)', null, fanboyStepWarm($settings));
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $step = fanboyStepRun(['id' => 'n1', 'kind' => 'measure', 'fans' => [fanboyTId(4)]], fanboyTIo($t, $spec, $clock));
        same('fanboy nvme warm: … measuring runs through: a table, a lowest steady value', ['done', 'ok', 20],
            [$step['status'], $step['results'][fanboyTId(4)]['result'] ?? null, $step['results'][fanboyTId(4)]['min_pct'] ?? null]);
        // the user's own, lower limit for the pool in a zone: it counts — and the step says which, how warm, the limit
        $s2 = $settings;
        $s2['zones'][1]['sources'][] = ['kind' => 'ssd', 'id' => 'cache', 'limit' => 52];
        fanboyTStore($s2);
        same('fanboy nvme warm: a zone\'s own limit for the pool (52) — too warm at 50 (52 − 5)', ['source' => 'cache', 'kind' => 'ssd', 'c' => 50.0, 'limit' => 52.0], fanboyStepWarm($s2));
        $clock = 0.0;
        $step = fanboyStepRun(['id' => 'n2', 'kind' => 'measure', 'fans' => [fanboyTId(4)]], fanboyTIo($t, $spec, $clock));
        same('fanboy nvme warm: … the step stops «warm» and names it', ['stopped', 'warm', 'cache', 50.0, 52.0, [128, 1]],
            [$step['status'], $step['why'], $step['warm']['source'] ?? null, $step['warm']['c'] ?? null, $step['warm']['limit'] ?? null, fanboyTOut($t, 4)]);
        fanboyTStore($settings);
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '35', '1'], ['cache', 'Cache', 'nvme0n1', '66', '0']]);
        same('fanboy nvme warm: the pool at 66 °C — close to the SSD limit (70 − 5)', ['cache', 'ssd', 70.0], array_values(array_intersect_key(fanboyStepWarm($settings) ?? [], array_flip(['source', 'kind', 'limit']))));
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '48', '1'], ['cache', 'Cache', 'nvme0n1', '50', '0']]);
        same('fanboy nvme warm: a hard disk at 48 °C — its own kind\'s limit (52 − 5)', ['parity', 'disk', 52.0], array_values(array_intersect_key(fanboyStepWarm($settings) ?? [], array_flip(['source', 'kind', 'limit']))));
    } finally {
        fanboyTEnd();
    }
}

/** Round 4: the zones proposed by kind — hard disks on their scale, an SSD/NVMe pool in the case zone; at rest no zone at full */
function testFanboyProposeKinds(): void
{
    $t = fanboyT('proposekinds');
    try {
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '35', '1'], ['disk1', 'Data', 'sdc', '35', '1'], ['cache', 'Cache', 'nvme0n1', '50', '0']]);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        unset($settings['zones']);
        fanboyTStore($settings);
        $scan = fanboyDetect();
        $zones = fanboyZonesPropose($scan, fanboyBoardRaw());
        $by = array_column($zones, null, 'id');
        same('fanboy propose kinds: the disk zone — the hard disks only; the pool (SSD/NVMe) in the case zone as «ssd»', [['parity', 'disk1'], ['ssd', 'cache']],
            [array_column($by['disks']['sources'], 'id'), array_values(array_filter(array_map(fn ($s) => $s['id'] === 'cache' ? [$s['kind'], $s['id']] : null, $by['case']['sources'])))[0] ?? null]);
        fanboyZonesSave(['zones' => array_map(fn ($z) => ['id' => $z['id'], 'name' => $z['id'], 'fans' => $z['fans'], 'sources' => array_map(fn ($s) => ['id' => $s['id']], $z['sources']),
            'unit' => 'pct', 'target' => ['preset' => 'normal']], $zones)]);
        $dry = fanboyDryRun()['dry'];
        $levels = array_column($dry['zones'], 'level', 'id');
        check('fanboy propose kinds: at rest (disks 35 °C, the pool 50 °C) no zone asks for full speed (' . json_encode($levels) . ')',
            count($levels) === 2 && max($levels) < 80);
        same('fanboy propose kinds: … the case zone led by the pool on the SSD\'s own scale', 'cache', array_column($dry['zones'], 'warmest', 'id')['case']['id'] ?? null);
    } finally {
        fanboyTEnd();
    }
}
