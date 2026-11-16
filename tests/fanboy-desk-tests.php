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
    $waz_plg = ['md12xx.fancontrol.plg' => '<PLUGIN/>', 'fanctrlplus.plg' => '<PLUGIN/>'];      // round 7: installed
    $t = fanboyT('waz', 10, FANBOY_T_ADDR, ['procs' => [1 => '/sbin/init', 4321 => $waz], 'plugins' => fanboyFakeBoard()['plugins'] + ['md12xx.fancontrol.plg' => '<PLUGIN/>']]);
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
    $t = fanboyT('waz2', 10, FANBOY_T_ADDR, ['procs' => [1 => '/sbin/init', 4321 => $waz, 4322 => '/bin/bash /usr/local/emhttp/plugins/fanctrlplus/fanctrlplus_loop.sh'],
        'plugins' => fanboyFakeBoard()['plugins'] + $waz_plg]);
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
    // round 8 (the owner): the test and the roles in one step — six steps
    same('fanboy desk: the wizard\'s steps in part 2\'s order', "const STEPS = ['detect', 'test', 'measure', 'zones', 'dry', 'take'];",
        preg_match('/^const STEPS = .*$/m', $js, $m) ? $m[0] : null);
    preg_match('/const ALIVE_MS = (\d+);/', $js, $a);
    check('fanboy desk: a running step kept alive — step_alive every ALIVE_MS (≤ 20 s, well inside the 45 s lease), only while a page of his is shown, stopped when it goes',
        isset($a[1]) && (int) $a[1] <= 20000 && (int) $a[1] < FANBOY_STEP_LEASE * 1000
        && str_contains($js, "Office.api.post(`\${ID}.step_alive`, {})") && str_contains($js, 'alive = setInterval(beat, ALIVE_MS);')
        && str_contains($js, "if (!view || !stepRuns() || !Office.agent.running) return;") && preg_match('/unmount\(\) \{[^}]*stopAlive\(\);/s', $js) === 1);
    check('fanboy desk: a step can be stopped any time — the strip\'s «Stop everything» while a test, a show or the measuring runs, «Cancel» on the running row\'s panel',
        str_contains($js, "if (['test', 'show', 'measure'].includes(run.kind)) out.push(btn(T('stop_all'), 'btn danger', () => stopAll(), T('stop_all_tip')));")
        && str_contains($js, "send('step_stop', {}, { done: () => Office.toast(T('step.stopped_toast')) })") && str_contains($js, "if (under) out.push(btn(T('test.abort'), 'btn plain', () => stopAll(), T('step.stop_tip')));"));
    $at = substr($js, (int) strpos($js, 'function stepAtHand()'), 1400);
    $order = array_map(fn ($k) => strpos($at, "return '$k'"), ['detect', 'test', 'measure', 'zones']);
    check('fanboy desk: the step at hand — the first not done, in the wizard\'s order', !in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order == array_values(fanboyTSorted($order)));
    check('fanboy desk: the honest return — a step ended by the page gone or interrupted said plainly on both pages',
        str_contains($js, "['page_gone', 'interrupted'].includes(s2.why)") && str_contains($js, 'function endedText(s) {') && str_contains($js, "else if (!run.kind && endedText(s)) { line = endedText(s); warn = true; }"));
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
        && preg_match_all('/Office\.store\(\'([^\']+)\'/', $js, $kept) >= 1 && array_values(array_unique($kept[1])) === ['fanboy.period', 'fanboy.quiet']       /* round 8: the quiet test's switch, a choice */ && str_contains($js, 'bar.done(fits.length, all.length, sw.note());'));
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
/**
 * The setup thought through as one flow (2026-11-15, the owner's three tests on real hardware: cards «jump», a click was
 * swallowed, nothing said what runs, one fan stayed at full with no way back, «Back to the BIOS» in the wrong place, white
 * fields on the dark theme). Step 2 is a list and ONE panel built once and patched in place, with a strip that always
 * says what runs; every click is queued and every refusal said.
 */
function testFanboyUx(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $css = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.css');
    check('fanboy ux: step 2 built once and patched in place — the frame drawn anew only when it changed, a look updates the list and the panel where they stand',
        str_contains($js, "function testStep() {\n  if (!bench) bench = benchMake();\n  else bench.update();\n  return bench.el;\n}")
        && str_contains($js, "function measureStep() {\n  if (!mbench) mbench = measureMake();\n  else mbench.update();\n  return mbench.el;\n}")
        && str_contains($js, "if (f !== drawn) { drawn = f; tilesDrawn = tilesSig(); bannerDrawn = bannerSig(); paint(); return; }")
        && str_contains($js, "[bench, mbench].forEach((b) => { if (b && b.el.isConnected) b.update(); });") && str_contains($js, "const rest = ['test', 'measure'].includes(active) ? null : [busy, su.fans,")
        && preg_match('/unmount\(\) \{.*?\[bench, mbench, strip\]\.forEach\(\(b\) => \{ if \(b\) b\.destroy\(\); \}\);\s+bench = mbench = strip = null;/s', $js) === 1);
    $send = substr($js, (int) strpos($js, 'function send(action, body, opts) {'), 1500);
    check('fanboy ux: no click is dropped — the bench\'s actions queue behind each other (no busy gate), a refusal is said in the strip and stays (and as the toast)',
        !str_contains($send, 'if (busy') && str_contains($send, 'const job = sending.then(async () => {') && str_contains($send, "said = { text: T('strip.refused', { why: text }), warn: true };")
        && str_contains($send, 'Office.toast(text, true);') && str_contains($js, "else if (sends > 0) line = T('busy_wait');"));
    $strip = substr($js, (int) strpos($js, 'function stripMake() {'), 6500);
    $says = ['strip.idle', 'strip.pause_next', 'strip.pause', 'strip.kick', 'strip.restore', 'strip.test_quiet', 'strip.test', 'strip.show_quiet', 'strip.show', 'strip.measure', 'strip.starting', 'strip.safety', 'strip.keep_open'];
    same('fanboy ux: the strip says what runs at every moment (nothing, a test with the others standing or not, the pause with its seconds, «show me», measuring, safety-full) — and «Stop everything» while anything runs',
        [[], true, true], [array_values(array_filter($says, fn ($k) => !str_contains($strip, "'$k'"))), str_contains($strip, "if (['test', 'show', 'measure'].includes(run.kind)) out.push(btn(T('stop_all'), 'btn danger', () => stopAll(), T('stop_all_tip')));"),
            str_contains($strip, "root.classList.toggle('fb-strip-stuck', !!run.kind);") && str_contains($css, '.fb-strip-stuck{position:sticky;')]);
    check('fanboy ux: ONE strip for the page — above every step of the setup, on the overview while it has something to say (a show, a test left running, a safety full)',
        str_contains($js, "if (!strip) strip = stripMake();\n      ssw.el.insertBefore(strip.el, ssw.body);") && str_contains($js, "if (state && stripNeeded()) {\n    if (!strip) strip = stripMake();\n    sw.el.insertBefore(strip.el, sw.body);")
        && !str_contains($js, 'function nowLine('));
    check('fanboy ux: nothing jumps — the strip two lines high from the start, a row one height, the panel\'s sentence, buttons and reason each with their own room',
        preg_match('/\.fb-strip\{[^}]*min-height:66px/', $css) === 1 && str_contains($css, '.fb-strip-note{margin:0; font-size:12.5px; line-height:1.45; color:var(--muted); min-height:1.45em}')
        && preg_match('/\.fb-item\{[^}]*\n?[^}]*height:58px/', $css) === 1 && str_contains($css, 'min-height:calc(2 * 1.45em)}') && str_contains($css, '.fb-panel-acts{display:flex; gap:8px; flex-wrap:wrap; align-items:center; min-height:34px}')
        && str_contains($css, '.fb-panel-why{margin:0; font-size:13px; line-height:1.45; color:var(--ink); min-height:1.45em}'));
    check('fanboy ux: the output that runs is framed like the office\'s active tile (a ring and a glow from the accent), in the list and on its panel',
        str_contains($css, ".fb-live{position:relative; border-color:var(--accent);\n    box-shadow:0 0 0 2px color-mix(in srgb, var(--accent) 55%, transparent), 0 0 18px 2px color-mix(in srgb, var(--accent) 38%, transparent)}")
        && str_contains($js, "it.el.classList.toggle('fb-live', !!r.live);") && str_contains($js, "live: run.id === id && !run.pause && (run.kind === 'test' || run.kind === 'show'),") && str_contains($js, "root.classList.toggle('fb-live', here);"));
    $panel = substr($js, (int) strpos($js, 'function panelMake('), 16000);
    check('fanboy ux: while an output runs the user answers on its panel — «found» or «heard nothing» (the later question «did it get louder?» only after a minute nobody answered), «cancel»',
        // (round 11: the answer travels with «weiter» and the agent stores it with the verdict — the page sends nothing later)
        str_contains($panel, "send('step_next', { fan: id, heard });") && str_contains($panel, '() => next(true),') && str_contains($panel, '() => next(false),')
        && !str_contains($js, 'settleHeard') && str_contains($panel, "btn(T('ask.yes'), 'btn', () => send('answer', { fan: id, louder: true }),"));
    check('fanboy ux: a button that can\'t act is never dead — set back, and a click says why (toast and strip); the line under the buttons says it too (another output runs, the pause, another controller, he steers, the messenger away)',
        str_contains($panel, "reason = T('panel.busy', { fan: run.id ? fanSay(run.id) : '' });") && str_contains($panel, "reason = T('panel.pause');") && str_contains($panel, 'else if (!run.kind && noTest) reason = noTest;')
        && str_contains($panel, "else if (away) reason = T('strip.away');") && str_contains($panel, 'setText(why, reason);')
        && str_contains($js, "b.onclick = () => { Office.toast(reason, true); say(reason); };") && str_contains($panel, "if (no) offBtn(b, reason || T('strip.away'));")
        && !preg_match('/\b(place|count)\.disabled = [^;]*roleSet/', $js) && str_contains($js, 'if (why.length) offBtn(b, why[0]);'));
    check('fanboy ux: where he sits and how many can be filled at any time — kept on the page and sent with the role once he picks it',
        str_contains($panel, 'else { wantPlace = place.value; update(); }') && str_contains($panel, 'else { wantCount = Number(count.value); update(); }')
        && str_contains($panel, "const extra = { ...(wantPlace !== null ? { place: wantPlace } : {}), ...(wantCount !== null ? { count: wantCount } : {}) };") && str_contains($panel, "hint = T('panel.kept');"));
    check('fanboy ux: waiting is seen — the wave in the strip from the click until the state shows it (a test beginning, an output ending, everything stopping, a show beginning), a quicker look meanwhile',
        str_contains($js, "expect({ now: T('strip.starting'), done: () => !!runNow().kind });") && str_contains($js, "expect({ note: T('strip.stopping'), done: () => !runNow().kind });")
        && str_contains($js, "expect({ note: T('strip.ending'), ending: id, done: () => { const r = runNow(); return r.id !== id || !!r.pause || !r.kind; } });")
        && str_contains($js, "root.classList.toggle('running', mood === 'run' || waits);") && str_contains($js, 'timer = setTimeout(() => load(false), expectNow() ? 1000 :'));
    check('fanboy ux: ONE banner element for the page, moved to the page shown — a second one can\'t exist (the same red text stood twice on the owner\'s server)',
        substr_count($js, "el('div', 'fb-banner')") === 1 && !preg_match('/banner = (null|sw\.el|ssw\.el)/', $js) && str_contains($js, 'if (banner.parentNode !== ssw.el || banner.nextSibling !== ssw.body) ssw.el.insertBefore(banner, ssw.body);'));
    $tool = (string) file_get_contents(OFFICE_DIR . '/tools/fanboy/desk.mjs');
    check('fanboy ux: the picture tool looks for a callout text twice in every demo state — also after a visit to the other page and back — and for exactly one banner element',
        str_contains($tool, 'the same callout twice after a visit to the other page and back') && str_contains($tool, "if (banners !== 1) faults.push("));
    check('fanboy ux: how a run ended is said only while it is news (seen running on this page, or within 90 s) — and «set to 100 %» never from a past run (only `safety` says an output stands at 100 %)',
        str_contains($js, 'const freshEnd = (x) => !!x && ((!!x.id && ranHere.has(x.id)) || (num(S().time) !== null && num(x.at) !== null && S().time - x.at <= FRESH_S));')
        && str_contains($js, "|| !freshEnd(s)) return '';") && str_contains($js, "if (why === 'no_restart') return '';") && str_contains($js, "if (!sh || showRuns() || !freshEnd(sh)) return '';")
        && !preg_match("/'no_restart'\]\.includes\(sh\.why\)/", $js));
    check('fanboy ux: once a fan has a name the name is the title and its output the small line under it — in rows, panels, the strip, tips and lists («Name (Output 4)» in a sentence); the output never disappears',
        str_contains($js, "return f && f.name ? T('fan.say', { name: f.name, out: outName(id) }) : outName(id);") && str_contains($js, "setText(under, f && f.name ? outName(id) : '');")
        && str_contains($js, "return { name: fanName(id), title: fanSay(id), sub: underName(id,") && str_contains($js, "T(run.quiet ? 'strip.test_quiet' : 'strip.test', { fan: fanSay(run.id) })")
        && str_contains($js, "id, label: fanName(id), sub: underName(id, cf && cf.stand_since && held ?"));
    check('fanboy ux: round 12 on the page — the measuring\'s standstill phases in the strip and on the fan\'s panel, what it found in a sentence, «may stand still» by the measuring\'s proof (the agent\'s `stand`), chosen-but-not-in-effect said beside the box',
        str_contains($js, "r.part = { phase: p.phase, pct: num(p.pct), left: secsTo(p.until) };") && str_contains($js, "T(`strip.m.\${pt.phase}`, { fan: fanSay(run.id), pct: pt.pct ?? '–' })")
        && str_contains($js, 'function standLine(f) {') && str_contains($js, "if (!st) return T('zones.stand_again');") && str_contains($js, "if (st.stops_safe !== true) return T('zones.stand_unsafe');")
        && str_contains($js, "if (f.off_ok && f.off_why && has(`off_why.\${f.off_why}`))") && str_contains($js, 'body: cpuStandWords(f),'));
    $de = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/de.json'), true);
    check('fanboy ux: the trail\'s words are the same in the list and the next bar (not tested · question open · role missing · the role · nothing attached); the CPU cooler says who steers it',
        str_contains($js, "T('next.item', { fan: fanSay(id), why: T({ test: 'label.untested', ask: 'label.ask', role: 'label.what' }[w]) })")
        && str_contains($js, "return f.cpu_own ? ['warn', T('label.cpu_own'),") && $de['label.ask'] === 'Frage offen' && $de['label.what'] === 'Rolle fehlt' && $de['label.untested'] === 'nicht getestet');
    check('fanboy ux: «may stand still» is offered only where it can be — else the words why stand in its place; the CPU cooler on own risk only behind its own confirmation; a choice that can\'t be snaps back with its reason',
        str_contains($js, 'const no = standWhy(f);') && str_contains($js, "if (no) row.appendChild(el('span', 'fb-stand-no', no));") && str_contains($js, 'await save({ cpu_off_confirm: true });')
        && str_contains($js, "if (f.role === 'pump' || (f.role === 'cpu' && !f.cpu_own)) return T('zones.stand_never');") && str_contains($js, "snapBack(unit, 'pct', T('zones.unit_off'))") && str_contains($js, "snapBack(choose, 'own', T('zones.preset_pct'))"));
    check('fanboy ux: set to full for safety — said on the output\'s panel and in the strip, each with the way back',
        str_contains($panel, "btn(T('safety.back'), 'btn', () => send('safety_back', { fan: id }), T('safety.back_tip', { pct: safetyPct(sf) }));") && str_contains($strip, "send('safety_back', { fan: run.safety[0].id, all: true })")
        && str_contains($js, "if (sx) return ['warn', T('safety.chip'),") && str_contains($panel, "waits ? el('p', 'fb-safety-wait', T('safety.wait')) : null"));
    $cpu = substr($js, (int) strpos($js, 'function cpuBlock('), 2600);
    check('fanboy ux: the CPU cooler\'s block stands right under «What is it?» (never behind a fold) — given to Fanboy: since when, and «Back to the BIOS» there',
        str_contains($panel, 'root.append(head, safety, what, cpu, fields, note, look, back);') && str_contains($cpu, "box.appendChild(para(T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) })));")
        && str_contains($cpu, "btn(T('cpu.back'), 'btn', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: false }),") && str_contains($cpu, "cpu_own: true, cpu_confirm: tick.checked }"));
    check('fanboy ux: selects and text fields in the office\'s field look (office.css .input — never the browser\'s white box)',
        str_contains($js, "const n = el(tag, 'input small fb-input');") && str_contains($js, "n = inputEl('input', 'text');") && str_contains($panel, "const place = inputEl('select');")
        && str_contains($panel, "pk.el.querySelectorAll('select').forEach((x) => { x.classList.add('input', 'small', 'fb-input'); });"));
    check('fanboy ux: the wheels — fast at full, standing while Fanboy holds them («stands» on the big one), else as the fan turns',
        str_contains($js, "if (full) return { ...base, rpm: max, state: 'run' };") && str_contains($js, "if (run.stopped.includes(id)) return { ...base, rpm: 0, state: 'run', badge: T('wheel.stands') };"));
    check('fanboy ux: while the setup holds outputs the page never says «the BIOS steers every fan»',
        str_contains($js, "!(page === 'setup' && (stepRuns() || showRuns() || Object.keys(S().safety || {}).length))"));
    $l = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    preg_match_all("/T\\(`([a-z_.]+)\\.\\$\\{/", $js, $pre);
    $need = ['quiet.hint_1', 'quiet.hint_2', 'quiet.hint_3', 'what.tip.his', 'what.tip.cpu', 'what.tip.pump', 'what.tip.unknown', 'safety.no_restart', 'safety.not_back', 'safety.chip',
        'next.open_test', 'next.open_ask', 'next.open_role', 'test.found_done', 'test.none_next', 'test.none_done', 'label.now', 'label.stands', 'label.waits', 'strip.left', 'strip.since'];
    same('fanboy ux: the keys the page builds by name are there in all five languages', array_fill_keys(array_keys($l), []), array_map(fn ($s) => array_values(array_filter($need, fn ($k) => !isset($s[$k]))), $l));
    same('fanboy ux: the three honest hints at the quiet switch (de): not every board stops fully, the CPU cooler stands too until it is known, Fanboy stops when something gets warm',
        [true, true, true], [str_contains($l['de']['quiet.hint_1'], 'Nicht jedes Mainboard'), str_contains($l['de']['quiet.hint_2'], 'CPU-Kühler'), str_contains($l['de']['quiet.hint_3'], 'warm')]);
    check('fanboy ux: the same parts on every step — steps 2 and 3 on listPanel() (list, one panel, next bar), the zones as a list and one zone\'s panel, rows to read in steps 1 and 6, each step\'s next bar with ONE primary button',
        substr_count($js, 'listPanel({') === 2 && str_contains($js, "const b = el('button', 'fb-item fb-item-plain');") && substr_count($js, 'readRow(') >= 4 && substr_count($js, 'nextBar(') >= 6
        && str_contains($js, "out.push(goButton('measure', !open.length && !run.kind));") && str_contains($js, "out.push(goButton('zones', !open.length && !run.kind));"));
    check('fanboy ux: a click answers within a blink — the clicked button carries the wave until the agent has answered (send() and act()), a picked role is pressed at once',
        str_contains($js, "b.onclick = () => { clicked = b; on(b); clicked = null; };") && str_contains($js, 'const done = working(clicked);') && str_contains($js, 'const worked = working(b);')
        && str_contains($js, "roleBtns.forEach((b, r) => b.setAttribute('aria-pressed', r === k ? 'true' : 'false'));") && str_contains($js, "const cur = picked || (found === 'empty' ? 'empty' : (f && f.role) || '');"));
    $art = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.js');
    check('fanboy ux: the colour beside a model is honest — «like the model» by default, any other paints the whole fan (frame, blades, hub: «my copy is black»), the preview shows it at once',
        str_contains($art, "const edition = !!(look && colour !== 'auto' && colour !== 'accent');") && str_contains($art, "'--fan-frame': edition ? null : look.frame,")
        && str_contains($art, "colour(c) { colour = pick(c, COLOURS, 'auto'); look2(); }") && str_contains($js, "colour.onchange = () => { if (pk.colour) pk.colour(colour.value); send('fan_look', { fan: id, colour: colour.value }, { quietly: true }); };")
        && str_contains($js, "setText(colour.options[0], isModel ? T('colour.model') : T('colour.auto'));"));
    check('fanboy ux: no text of the page speaks of a «card» or of «More» any more (de, en)', !preg_match('/\bKarte\b|«Mehr»/u', implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $l['de'])))
        && !preg_match('/(?<!graphics )\bcards?\b|«More»/i', implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $l['en']))));
    // the owner, 2026-11-15: «kein AI-Slop, der nicht stimmt» — no sentence names a number the state doesn't give
    check('fanboy ux: the CPU cooler\'s «may stand» confirmation is built from the state only — no temperature unless the control reports it (starts_at), no seconds, no claim about the mainboard\'s automatic',
        str_contains($js, "out.push(cf && control().running && num(cf.starts_at) !== null ? T('cpustand.starts', { c: fmt.number(cf.starts_at) }) : T('cpustand.starts_later'));")
        && str_contains($js, "out.push(T('cpustand.gone_unknown'));") && !isset($de['zones.cpu_stand_text']) && !preg_match('/\d/', $de['cpustand.what'] . $de['cpustand.starts_later'] . $de['cpustand.gone_unknown']));
    $control = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php');
    preg_match('/const FLOOR_MARGIN = (\d+);/', $js, $fm);
    preg_match('/const BLIND_FLOOR = (\d+);/', $js, $bf);
    preg_match('/const FANBOY_FLOOR_MARGIN = (\d+);/', $control, $afm);
    preg_match('/const FANBOY_BLIND_FLOOR = (\d+);/', $control, $abf);
    same('fanboy ux: the page\'s floors are the control\'s (lowest steady value + margin, the blind floor)', [$afm[1] ?? null, $abf[1] ?? null], [$fm[1] ?? '', $bf[1] ?? '']);
    check('fanboy ux: the curve chart draws what the control applies for the zone\'s first fan — its floor, and standing only where it may and the curve asks for nothing — and says it in a sentence of those numbers',
        str_contains($js, "apply: rpmUnit ? undefined : (v) => (stands && v < 0.5 ? 0 : Math.max(v, floorPct)),") && str_contains($js, "const stands = !!lead.off_ok && !standWhy(lead) && !isCpu;")
        && str_contains($js, "const says = para(curveWords(pts, floorPct, stands, isCpu && !!lead.off_ok), 'fb-curve-says');") && str_contains($art, "const applied = (v) => (typeof o.apply === 'function' ? o.apply(v) : v);")
        && str_contains($art, "line.setAttribute('class', 'fan-curve-line fan-curve-wish');"));
    check('fanboy ux: sentences that take numbers from the state are marked (data-nums) and the picture tool compares each with the demo state it shows',
        substr_count($js, '.dataset.nums = JSON.stringify(') >= 3 && str_contains($tool, "if (want !== x.v) faults.push(") && str_contains($tool, 'is not in its sentence'));
    check('fanboy ux: one button size per row — every button of the setup is the office\'s .btn (the fill tells primary from secondary); no «small» beside a full one',
        !preg_match('/btn small/', substr($js, (int) strpos($js, 'function stripMake() {'), (int) strpos($js, '// ------------------------------------------------------------------ the page') - (int) strpos($js, 'function stripMake() {'))));
    same('fanboy ux: a demo state for each moment of step 2', [], array_values(array_filter(['fanboy-uxrun', 'fanboy-uxpause', 'fanboy-uxfound', 'fanboy-uxsafety', 'fanboy-uxopen'],
        fn ($n) => !is_file(OFFICE_DIR . "/tests/ui/demo/$n.json"))));
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
    check('fanboy avatar: avatar-alarm.svg — the same fan with the red mark (as the Night Watchman\'s alert), set while an alarm, a missing or gone chip or a stopped control holds',
        str_starts_with(str_replace('an alarm at his fans', 'a case fan seen from the front', $b), rtrim(str_replace('</svg>', '', $a))) && str_contains($b, 'fill="#D9453B"')
        // round 6: … or a fan he doesn't steer that stands (his watch told it)
        && str_contains((string) file_get_contents("$dir/desk.js"), "Office.setDeskMood(ID, (control().running && control().alarm) || ['chip_missing', 'chip_gone', 'crashed'].includes(who().why)\n"
            . "    || (idle().on && Object.values(idle().tachs || {}).some((x) => x && x.state === 'alarm')) ? 'alarm' : '');"));
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
        str_contains($js, "count: Number(count.value)") && str_contains($js, 'fans: (z.fans || []).flatMap((id) => wheelsOf(id)),')
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
        check('fanboy stands kick: … in the words for a fan alone in its zone («… sets it to 100 % until it reports its speed again»)',
            (bool) array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, 'sets it to 100 % until it reports its speed again')));
        same('fanboy stands kick: … the other zone («Case») keeps steering by its curve during the alarm', [102, 102], [fanboyTOut($t, 5)[0], fanboyTOut($t, 2)[0]]);
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

/**
 * Round 4 (the owner): how he reacts — up at once always; the way down by the kind that leads the zone. A CPU zone: a
 * spike up at once, back down after a hold of 6 s at about 5 % a round, never a snap, no hunting with a spiky CPU;
 * «quick» shortens hold and gap. A disk zone as before (no hold, 1 % a round — part 2's tests unchanged).
 */
function testFanboyReact(): void
{
    $t = fanboyT('react');
    try {
        fanboyTDisks($t, 35, null, 33);
        $cpuSrc = 'coretemp@platform/coretemp.0#1';
        $cpuFile = "{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input";
        $settings = fanboyTSettings();
        $settings['zones'] = [['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [fanboyTId(5)], 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => [['kind' => 'cpu', 'id' => $cpuSrc]]]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        same('fanboy react: a zone saved without a choice reacts «balanced»; the CPU profile — gap 2.5 °C, hold 6 s, 5 % a round',
            ['balanced', ['gap' => 2.5, 'hold' => 6.0, 'step' => 5.0]], [fanboyControlNew($settings, FANBOY_T_KEY, ['pid' => 0])['zones']['cpuz']['react'], fanboyDownProfile('cpu', 'balanced')]);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 5000.0;
        file_put_contents($cpuFile, "45000\n");
        fanboyTRounds($c, $now, $t, $spec, 3);
        $rest = $c['zones']['cpuz']['cur'];
        file_put_contents($cpuFile, "80000\n");
        fanboyTRounds($c, $now, $t, $spec, 1);
        same('fanboy react: a CPU spike to 80 °C — up at once (one round: 20 % → 80 %)', [20.0, 80.0], [round($rest, 1), round($c['zones']['cpuz']['cur'], 1)]);
        file_put_contents($cpuFile, "45000\n");
        $levels = [];
        for ($i = 0; $i < 14; $i++) {
            fanboyTRounds($c, $now, $t, $spec, 1);
            $levels[] = (int) round($c['zones']['cpuz']['cur']);
        }
        same('fanboy react: … cooled — held for 6 s (3 rounds), then down about 5 % a round, never a snap, back at rest within ~20 s',
            [80, 80, 80, 75, 70, 65, 60, 55, 50, 45, 40, 35, 30, 25], $levels);
        // a spiky CPU around 60 °C: up at once to the highest, never down again inside the gap — no hunting
        fanboyTRounds($c, $now, $t, $spec, 10);
        $written = [];
        foreach ([60000, 61500, 59500, 61500, 59500, 61000, 59600, 61500, 60000, 59500] as $mc) {
            file_put_contents($cpuFile, "$mc\n");
            fanboyTRounds($c, $now, $t, $spec, 1);
            $written[] = fanboyTOut($t, 5)[0];
        }
        $changes = 0;
        for ($i = 1; $i < count($written); $i++) {
            $changes += $written[$i] !== $written[$i - 1] ? 1 : 0;
        }
        check('fanboy react: … a spiky CPU (59.5 ↔ 61.5 °C) — the fan up once, then steady: no hunting (' . implode(',', $written) . ')', $changes <= 1);
        // «quick»: the hold halved (3 s), steps of 8 %
        fanboyLetGo($c, 'end');
        $settings['zones'][0]['react'] = 'quick';
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        file_put_contents($cpuFile, "80000\n");
        fanboyTRounds($c, $now, $t, $spec, 2);
        file_put_contents($cpuFile, "45000\n");
        $levels = [];
        for ($i = 0; $i < 4; $i++) {
            fanboyTRounds($c, $now, $t, $spec, 1);
            $levels[] = (int) round($c['zones']['cpuz']['cur']);
        }
        same('fanboy react: «Sofort reagieren» — held 3 s (2 rounds), then 8 % a round', [80, 80, 72, 64], $levels);
        fanboyLetGo($c, 'end');
        $codes = [];
        foreach (['fast', 1, null] as $r) {
            try {
                $z = ['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [fanboyTId(5)], 'sources' => [['id' => $cpuSrc]], 'unit' => 'pct', 'target' => ['preset' => 'normal']];
                if ($r !== null) {
                    $z['react'] = $r;
                }
                $codes[] = fanboyZonesCheck([$z], fanboyDetect(), $settings)[0]['react'];
            } catch (Problem $p) {
                $codes[] = $p->key . ':' . ($p->params['field'] ?? '');
            }
        }
        same('fanboy react: read strictly — an unknown word and a number refused, none: «balanced»', ['fanboy_bad_zones:react', 'fanboy_bad_zones:react', 'balanced'], $codes);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 4 (the owner): the degrees settable per zone — «ab», «volle Drehzahl bei», «Grenze» per kind the zone has, a
 * source's own values over its kind's; read strictly (numbers only, the kind's sane bounds, only kinds present),
 * low < high < limit for every source with the defaults filling the gaps; a limit under today's temperature is allowed
 * (the page says it). The control steers by them.
 */
function testFanboyTemps(): void
{
    $t = fanboyT('temps');
    try {
        fanboyTDisks($t, 35, null, 33);
        $cpuSrc = 'coretemp@platform/coretemp.0#1';
        file_put_contents("{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input", "60000\n");
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $scan = fanboyDetect();
        $zone = fn (array $kinds, array $src = []) => ['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [fanboyTId(5)], 'unit' => 'pct',
            'target' => ['preset' => 'normal'], 'sources' => [['id' => $cpuSrc] + $src], 'kinds' => $kinds];
        $try = function (array $z) use ($scan, $settings) {
            try {
                return fanboyZonesCheck([$z], $scan, $settings)[0]['kinds'];
            } catch (Problem $p) {
                return $p->key . ':' . ($p->params['field'] ?? $p->params['source'] ?? '');
            }
        };
        same('fanboy temps: the CPU\'s own degrees kept; none sent — an empty object (the defaults)',
            ['{"cpu":{"low":50,"high":70,"limit":90}}', '{}'],
            [json_encode($try($zone(['cpu' => ['low' => 50, 'high' => 70, 'limit' => 90]]))), json_encode($try($zone([])))]);
        same('fanboy temps: refused — a kind the zone hasn\'t, a list, a text number, beyond the kind\'s bounds, low over high, a source\'s own low over its kind\'s high',
            ['fanboy_bad_zones:kinds', 'fanboy_bad_zones:kinds', 'fanboy_bad_zones:low', 'fanboy_bad_zones:limit', 'fanboy_bad_temps:' . $cpuSrc, 'fanboy_bad_temps:' . $cpuSrc],
            [$try($zone(['disk' => ['low' => 30]])), $try($zone([['low' => 30]])), $try($zone(['cpu' => ['low' => '50']])), $try($zone(['cpu' => ['limit' => 130]])),
                $try($zone(['cpu' => ['low' => 80, 'high' => 70]])), $try($zone(['cpu' => ['high' => 60]], ['low' => 65]))]);
        same('fanboy temps: a limit under today\'s 60 °C is allowed (the page says «liegt schon darüber»)', ['cpu' => ['low' => 40.0, 'high' => 50.0, 'limit' => 55.0]],
            $try($zone(['cpu' => ['low' => 40, 'high' => 50, 'limit' => 55]])));
        // the control steers by them: the same 60 °C — on the defaults (45…80) part way, with «volle Drehzahl bei» 58 at full
        $level = function (array $kinds) use ($zone) {
            fanboyZonesSave(['zones' => [$zone($kinds)]]);
            return array_column(fanboyDryRun()['dry']['zones'], 'level', 'id')['cpuz'] ?? null;
        };
        $plain = $level([]);
        $own = $level(['cpu' => ['low' => 40, 'high' => 58]]);
        check("fanboy temps: CPU at 60 °C — on the defaults part way ($plain %), «volle Drehzahl bei» 58 °C: higher ($own %)", $plain < 80 && $own > $plain);
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 4 (the owner): the CPU cooler «Fanboy regelt ihn» — only with the stored, dated, ticked confirmation; never
 * written without it. Its zone must hold a CPU temperature; a floor of its own (40 %, never «off»); given back to the
 * mainboard's automatic only; its standing drives its zone to 100 %; the reaction not forced; pumps never; said in the
 * report without names.
 */
function testFanboyCpuOwn(): void
{
    $t = fanboyT('cpuown');
    try {
        fanboyTDisks($t, 35, null, 33);
        $cpuSrc = 'coretemp@platform/coretemp.0#1';
        file_put_contents("{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input", "40000\n");
        $cpuId = fanboyTId(1);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        $cpuZone = ['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [$cpuId], 'unit' => 'pct', 'target' => ['preset' => 'quiet'], 'sources' => [['kind' => 'cpu', 'id' => $cpuSrc]]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        // without the confirmation: never his — refused in a zone, never written by the control even if a zone names it
        $codes = [];
        foreach ([['cpu_own' => true], ['cpu_own' => true, 'cpu_confirm' => false], ['cpu_own' => 'yes', 'cpu_confirm' => true]] as $r) {
            try {
                fanboyRole(['fan' => $cpuId, 'role' => 'cpu', 'name' => 'CPU'] + $r);
                $codes[] = 'stored';
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        try {
            fanboyRole(['fan' => fanboyTId(5), 'role' => 'pump', 'cpu_own' => true, 'cpu_confirm' => true]);
            $codes[] = 'stored';
        } catch (Problem $p) {
            $codes[] = $p->key;
        }
        same('fanboy cpu own: refused — no tick, the tick false, a text for yes, a pump', ['fanboy_cpu_confirm', 'fanboy_cpu_confirm', 'bad_request', 'fanboy_bad_role'], $codes);
        same('fanboy cpu own: … nothing stored', null, fanboyBoardRaw()['fans'][$cpuId]['cpu_own'] ?? null);
        try {
            fanboyZonesCheck([$cpuZone], fanboyDetect(), fanboyBoardRaw());
            $code = 'accepted';
        } catch (Problem $p) {
            $code = $p->key . ':' . ($p->params['field'] ?? '');
        }
        same('fanboy cpu own: without the confirmation the CPU cooler in a zone is refused', 'fanboy_bad_zones:fans', $code);
        $hand = $settings;
        $hand['zones'][] = $cpuZone;
        fanboyBeat();
        $c = fanboyControlNew($hand, FANBOY_T_KEY, fanboyMe('control'));
        $now = 1000.0;
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy cpu own: … a zone written by hand naming it: never written without the confirmation (still the BIOS\'s 128, fixed), watched',
            [false, [128, 1], true], [isset($c['fans'][$cpuId]), fanboyTOut($t, 1), isset($c['watch'][$cpuId])]);
        fanboyLetGo($c, 'end');

        // with the tick: stored with its date; shown in the setup's state and the report (no names)
        $before = time();
        fanboyRole(['fan' => $cpuId, 'role' => 'cpu', 'name' => 'CPU', 'cpu_own' => true, 'cpu_confirm' => true]);
        $at = fanboyBoardRaw()['fans'][$cpuId]['cpu_own']['at'] ?? null;
        check('fanboy cpu own: the tick — stored with its date', is_int($at) && $at >= $before);
        fanboyRole(['fan' => $cpuId, 'role' => 'cpu', 'name' => 'CPU cooler']);
        same('fanboy cpu own: … a role saved again without the choice keeps it (and its date)', $at, fanboyBoardRaw()['fans'][$cpuId]['cpu_own']['at'] ?? null);
        $scan = fanboyDetect();
        $st = array_column(fanboySetupState($scan, fanboyBoardRaw())['fans'], 'cpu_own', 'id');
        same('fanboy cpu own: … in the setup\'s state: since when; the cases fans none', [$at, null], [$st[$cpuId], $st[fanboyTId(5)]]);
        $report = fanboyReportText($scan);
        check('fanboy cpu own: … the report says it, without names', str_contains($report, 'CPU cooler: steered by Fanboy on the owner\'s own risk since ' . gmdate('Y-m-d', $at))
            && !str_contains($report, 'CPU cooler]'));
        // a zone: only with a CPU temperature; proposed as a zone of its own on the CPU's temperatures
        $codes = [];
        foreach ([['kind' => 'disk', 'id' => 'parity'], ['kind' => 'cpu', 'id' => $cpuSrc]] as $src) {
            try {
                $codes[] = fanboyZonesCheck([['sources' => [$src]] + $cpuZone], $scan, fanboyBoardRaw())[0]['fans'];
            } catch (Problem $p) {
                $codes[] = $p->key;
            }
        }
        same('fanboy cpu own: its zone needs a CPU temperature — refused with the disks only, taken with the CPU', ['fanboy_cpu_zone_needs_cpu', [$cpuId]], $codes);
        $prop = array_column(fanboyZonesPropose($scan, fanboyBoardRaw()), null, 'id');
        same('fanboy cpu own: … proposed: a zone «cpu» with it and the CPU\'s temperatures only', [[$cpuId], ['cpu']],
            [$prop['cpu']['fans'] ?? null, array_values(array_unique(array_column($prop['cpu']['sources'] ?? [], 'kind')))]);

        // the control: his now — a floor of 40 % at a cool CPU on «quiet» (0 %), never off (even «off» stored), balanced
        $settings = fanboyBoardRaw();
        $settings['fans'][$cpuId]['off_ok'] = true;
        $settings['fans'][$cpuId]['back'] = 'fixed';
        $settings['fans'][$cpuId]['measured'] = ['table' => [[100, 1800], [50, 900], [20, 360]], 'min_pct' => 20, 'start_pct' => 25, 'max_rpm' => 1800, 'at' => 1];
        $settings['zones'][] = $cpuZone;
        fanboyTStore($settings);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        same('fanboy cpu own: in the control — his, floor 40 % (measured 20 + 5 is less), never off, given back to the automatic, balanced, not watched',
            [true, 40, false, 'auto', 'balanced', false],
            [isset($c['fans'][$cpuId]), $c['fans'][$cpuId]['floor'] ?? null, $c['fans'][$cpuId]['off_ok'] ?? null, $c['fans'][$cpuId]['back'] ?? null, $c['zones']['cpuz']['react'] ?? null, isset($c['watch'][$cpuId])]);
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy cpu own: … CPU 40 °C on «quiet»: 40 % (102), never under', 102, fanboyTOut($t, 1)[0]);
        $level = fanboyTOut($t, 4)[0];
        // it stands: ITS zone at 100 % (it alone: itself), the other zones steer on
        $stuck = fanboyTSpec(10, [1 => ['stuck' => true]]);
        fanboyTRounds($c, $now, $t, $stuck, 10);
        same('fanboy cpu own: it stands — alarm «stands» (alone in its zone), the cooler at 100 %, the disk zone keeps its level',
            ['stands', true, [255, 1], $level], [$c['alarm']['code'] ?? null, $c['alarm']['params']['alone'] ?? null, fanboyTOut($t, 1), fanboyTOut($t, 4)[0]]);
        fanboyTRounds($c, $now, $t, $spec, 80);
        fanboyLetGo($c, 'end');
        same('fanboy cpu own: given back — to the mainboard\'s automatic (mode 2), never a fixed value, though «fixed» was stored', 2, fanboyTOut($t, 1)[1]);
        // the zone without a CPU temperature (written by hand): it stays the BIOS's
        $settings['zones'][2]['sources'] = [['kind' => 'disk', 'id' => 'parity']];
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        same('fanboy cpu own: its zone written by hand without a CPU temperature — not taken, watched', [false, true], [isset($c['fans'][$cpuId]), isset($c['watch'][$cpuId])]);
        // back to the BIOS: the choice gone, out of every zone
        fanboyRole(['fan' => $cpuId, 'role' => 'cpu', 'name' => 'CPU', 'cpu_own' => false]);
        $raw = fanboyBoardRaw();
        same('fanboy cpu own: «Zurück ans BIOS» — the confirmation gone, out of every zone', [null, false],
            [$raw['fans'][$cpuId]['cpu_own'] ?? null, in_array($cpuId, array_merge(...array_map(fn ($z) => $z['fans'], $raw['zones'])), true)]);
    } finally {
        fanboyTEnd();
    }
}

/** Round 4: the page draws the presets on a zone's own degrees — its presetsOn() is exactly fanboyPreset() */
function testFanboyPresetsMirror(): void
{
    $php = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php');
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    preg_match_all("/'(quiet|cool)'\s*=>\s*(\[\[.*?\]\]),|default\s*=>\s*(\[\[.*?\]\]),/", $php, $m, PREG_SET_ORDER);
    $arms = [];
    foreach ($m as $x) {
        $arms[$x[1] !== '' ? $x[1] : 'normal'] = str_replace('$', '', $x[2] !== '' ? $x[2] : $x[3]);
    }
    same('fanboy presets mirror: fanboyPreset() has quiet, normal, cool', ['quiet', 'cool', 'normal'], array_keys($arms));
    foreach ($arms as $p => $pts) {
        check("fanboy presets mirror: desk.js presetsOn() draws «{$p}» as the agent steers it ($pts)", str_contains($js, "$p: $pts"));
    }
}
