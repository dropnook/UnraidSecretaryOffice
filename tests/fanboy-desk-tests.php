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
    same('fanboy desk: the wizard\'s steps in part 2\'s order (five since 2026-11-16: the dry run is a preview inside «take over»)', "const STEPS = ['detect', 'test', 'measure', 'zones', 'take'];",
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
    same('fanboy desk: tile order = section order — what is now (the picture, the fans), what was (each zone\'s history), every sensor',
        [['now', 'history', 'sensors'], ['nowSection', 'historySection', 'sensorsSection']], [$tiles[1], $tiles[2]]);
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
    $panel = substr($js, $at = (int) strpos($js, 'function panelMake('), (int) strpos($js, 'function cpuBlock(') - $at);   // the whole panel, however long
    check('fanboy ux: while an output runs the user answers on its panel — «found» or «heard nothing» (the later question «did it get louder?» only after a minute nobody answered), «cancel»',
        // (round 11: the answer travels with «weiter» and the agent stores it with the verdict — the page sends nothing later)
        str_contains($panel, "send('step_next', { fan: id, heard });") && str_contains($panel, '() => next(true),') && str_contains($panel, '() => next(false),')
        && !str_contains($js, 'settleHeard') && str_contains($panel, "btn(T('ask.yes'), 'btn', () => send('answer', { fan: id, louder: true }),"));
    check('fanboy ux: a button that can\'t act is never dead — set back, and a click says why (toast and strip); the line under the buttons says it too (another output runs, the pause, another controller, he steers, the messenger away)',
        str_contains($panel, "reason = T('panel.busy', { fan: run.id ? fanSay(run.id) : '' });") && str_contains($panel, "reason = T('panel.pause');") && str_contains($panel, 'else if (!run.kind && noTest && !showOnly) reason = noTest;')
        && str_contains($panel, "else if (away) reason = T('strip.away');") && str_contains($panel, "swap(why, [reason, toRun], () => (toRun ? [reason, ' ', btn(T('panel.to_run', { fan: fanSay(toRun) }), 'fb-link', () => benchSelect(toRun), T('panel.to_run_tip'))] : [reason]));")
        && str_contains($js, "b.onclick = () => { Office.toast(reason, true); say(reason); };") && str_contains($panel, "if (no) offBtn(b, reason || T('strip.away'));")
        && !preg_match('/\b(place|count)\.disabled = [^;]*roleSet/', $js) && str_contains($js, 'if (why.length) offBtn(b, why[0]);'));
    check('fanboy ux: where he sits and how many can be filled at any time — kept in the output\'s draft, sent with «Speichern» in one request (2026-11-20)',
        str_contains($panel, 'place.onchange = () => { draft.place = place.value; update(); };') && str_contains($panel, 'count.onchange = () => { draft.count = Number(count.value); update(); };')
        && str_contains($panel, "const placeNow = draft.place ?? ((f && f.place) || '');") && !str_contains($panel, 'wantPlace'));
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
        && str_contains($js, "if (f.off_ok && f.off_why && has(`off_why.\${f.off_why}`))") && str_contains($js, 'body: cpuStandWords(f, z),'));
    $de = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/lang/de.json'), true);
    check('fanboy ux: the trail\'s words are the same in the list and the next bar (not tested · question open · role missing · the role · nothing attached); the CPU cooler says who steers it',
        str_contains($js, "T('next.item', { fan: fanSay(id), why: T({ test: 'label.untested', ask: 'label.ask', role: 'label.what' }[w]) })")
        && str_contains($js, "return f.cpu_own ? ['warn', T('label.cpu_own'),") && $de['label.ask'] === 'Frage offen' && $de['label.what'] === 'Rolle fehlt' && $de['label.untested'] === 'nicht getestet');
    check('fanboy ux: «may stand still» is offered only where it can be — else the words why stand in its place; the CPU cooler on own risk only behind its own confirmation; a choice that can\'t be snaps back with its reason',
        str_contains($js, 'const no = standWhy(f);') && str_contains($js, "row.appendChild(el('span', 'fb-stand-no', no));") && str_contains($js, 'changeFan(id, { off_ok: true, cpu_off_confirm: true });')
        && str_contains($js, "if (f.role === 'pump' || (f.role === 'cpu' && !f.cpu_own)) return T('zones.stand_never');") && str_contains($js, "snapBack(unit, 'pct', T('zones.unit_off'))") && str_contains($js, "snapBack(choose, 'own', T('zones.preset_pct'))"));
    check('fanboy ux: set to full for safety — said on the output\'s panel and in the strip, each with the way back',
        str_contains($panel, "btn(T('safety.back'), 'btn', () => send('safety_back', { fan: id }), T('safety.back_tip', { pct: safetyPct(sf) }));") && str_contains($strip, "send('safety_back', { fan: run.safety[0].id, all: true })")
        && str_contains($js, "if (sx) return ['warn', T('safety.chip'),") && str_contains($panel, "waits ? el('p', 'fb-safety-wait', T('safety.wait')) : null"));
    $cpu = substr($js, (int) strpos($js, 'function cpuBlock('), 2600);
    check('fanboy ux: the CPU cooler\'s block stands right under «What is it?» (never behind a fold) — given to Fanboy: since when, and «Back to the BIOS» there',
        str_contains($panel, 'root.append(head, safety, what, cpu, fields, alike, hubSays, note, look, back, saveBar);') && str_contains($cpu, "box.appendChild(para(T('cpu.own_since', { date: Office.fmt.date(f.cpu_own) })));")
        && str_contains($cpu, "btn(T('cpu.back'), 'btn', () => send('role', { fan: f.id, role: 'cpu', name: f.name || '', place: f.place || '', cpu_own: false }),") && str_contains($cpu, "cpu_own: true, cpu_confirm: tick.checked }"));
    check('fanboy ux: selects and text fields in the office\'s field look (office.css .input — never the browser\'s white box)',
        str_contains($js, "const n = el(tag, 'input small fb-input');") && str_contains($js, "n = inputEl('input', 'text');") && str_contains($panel, "const place = inputEl('select');")
        && str_contains($panel, "pk.el.querySelectorAll('select').forEach((x) => { x.classList.add('input', 'small', 'fb-input'); });"));
    check('fanboy ux: the wheels — fast at full, standing while Fanboy holds them («stands» on the big one), else as the fan turns',
        str_contains($js, "if (full) return { ...base, rpm: max, state: 'run' };") && str_contains($js, "if (run.stopped.includes(id)) return { ...base, rpm: 0, state: 'run', badge: T('wheel.stands') };"));
    check('fanboy ux: while the setup holds outputs the page never says «the BIOS steers every fan»',
        str_contains($js, "page === 'setup' && !(stepRuns() || showRuns() || Object.keys(S().safety || {}).length)"));
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
        && str_contains($js, "draft.role = k;\n    update();") && str_contains($js, "const cur = draft.role ?? (found === 'empty' ? 'empty' : (f && f.role) || '');"));
    $art = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.js');
    // round 12, the look's four controls (the owner: «Farbe soll für Lüfter-Blätter und Lüfter-Gehäuse separat gewählt werden können,
    // ebenfalls Art und Anzahl der Blätter. Modellauswahl macht nur typischen Vorschlag»)
    check('fanboy ux look: the fold is «Aussehen (fürs Bild)» with four controls of his own — frame colour, blades\' colour, blade shape, number of blades — and the «Reverse-Blade» tick (2026-11-20), beside the model',
        str_contains($js, "rows.append(rowOf(T('look.frame'), frame.el), rowOf(T('look.wheel'), wheel.el), rowOf(T('look.style'), kinds), rowOf(T('look.blades'), count), revRow);")
        && str_contains($js, "const frame = swatches('frame'), wheel = swatches('wheel');") && str_contains($js, "const countBtns = [5, 6, 7, 8, 9, 10, 11].map(")
        && ($l['de']['look.fold'] ?? '') === 'Aussehen (fürs Bild)' && !str_contains($js, "inputEl('select');\n      COLOURS"));
    check('fanboy ux look: colours are swatches painted with the wheel\'s own colour tokens (named in title and aria-label), blade shapes are buttons with a tiny drawn sample and a plain name',
        str_contains($js, "b.style.background = `var(--fan-k-\${name})`;") && str_contains($js, "b.setAttribute('aria-label', T(`colour.\${name}`));")
        && str_contains($js, "const w = A.wheel({ px: 38, air: false, rpm: 0 });") && str_contains($js, "b.append(w.el, el('span', '', T(`style.\${st}`)));")
        && str_contains((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.css'), '.fan-wheel, .fan-k{'));
    $pageList = fn (string $name): array => preg_match("/const $name = \\[([^\\]]+)\\];/", $js, $m) ? array_map(fn ($x) => trim($x, " '"), explode(',', $m[1])) : [];
    $artList = fn (string $name): array => preg_match("/const $name = \\[([^\\]]+)\\];/", $art, $m) ? array_map(fn ($x) => trim($x, " '"), explode(',', $m[1])) : [];
    same('fanboy ux look: the page offers exactly the colours the wheel can paint', $artList('LOOK_COLOURS'), $pageList('LOOK_COLOURS'));
    same('fanboy ux look: … and exactly the blade shapes it can draw', $artList('STYLES'), $pageList('LOOK_STYLES'));
    $missing = [];
    foreach (['en', 'de', 'it', 'fr', 'es'] as $code) {
        foreach (array_merge(array_map(fn ($c) => "colour.$c", $pageList('LOOK_COLOURS')), array_map(fn ($c) => "style.$c", $pageList('LOOK_STYLES'))) as $key) {
            if (!is_string($l[$code][$key] ?? null) || $l[$code][$key] === '') {
                $missing[] = "$code:$key";
            }
        }
    }
    same('fanboy ux look: every colour and every blade shape has its plain name in all five languages', [], $missing);
    check('fanboy ux look: the agent stores these keys as the page sends them (frame, wheel, style: a key of the page\'s lists; the blades\' number 5–11)',
        count(array_filter(array_merge($pageList('LOOK_COLOURS'), $pageList('LOOK_STYLES')), fn ($k) => !preg_match(FANBOY_LOOK_KEY_RE, $k))) === 0
        && FANBOY_BLADES_MIN === 5 && FANBOY_BLADES_MAX === 11);
    check('fanboy ux look: the model only proposes — its look is the base, his frame, blades\' colour, shape and number lie on top; the one colour of before counts for frame and blades',
        str_contains($js, "let look = frame || wheel || style ? { ...(base || NEUTRAL_LOOK), ...(frame ? { frame } : {}), ...(wheel ? { blades: wheel, hub: wheel } : {}), ...(style ? { style } : {}) } : base;")
        && str_contains($js, "const old = f && LOOK_COLOURS.includes(f.colour) ? f.colour : null;") && str_contains($js, "const blades = num(m.blades) ?? ((c && c.blades) || (look ? null : 7));"));
    check('fanboy ux look: each control goes into the output\'s draft and shows at once (2026-11-20: sent with «Speichern», never alone); the old one colour is carried over as frame and blades, never lost by another click',
        str_contains($js, "const body = { [key]: value };") && str_contains($js, "if (key !== 'frame' && !f0.frame) { body.frame = old; want.frame = old; }") && str_contains($js, "if (key !== 'wheel' && !f0.wheel) { body.wheel = old; want.wheel = old; }")
        && str_contains($js, "want[key] = value;\n      keep(body);") && str_contains($js, "const keep = (fields) => { draft.look = { ...(draft.look || {}), ...fields }; paint(); update(); };")
        && !str_contains($js, "send('fan_look'"));
    check('fanboy ux look: another model proposes anew (his four go, one click brings them and the model back); «Zurück zum Vorschlag» resets the four; the chip says which it is',
        str_contains($js, "keep({ model: v.id, frame: '', wheel: '', style: '', blades: 0, reverse: null, colour: 'auto' });")
        && str_contains($js, "keep({ model: (u.model && u.model.id) || '', frame: u.frame, wheel: u.wheel, style: u.style, blades: u.blades, reverse: u.reverse });")
        && str_contains($js, "btn(T('look.reset'), 'btn plain', () => { undo = null; Object.assign(want, { frame: '', wheel: '', style: '', blades: 0, reverse: null }); keep({ frame: '', wheel: '', style: '', blades: 0, reverse: null, colour: 'auto' }); }, T('look.reset_tip'))")
        && str_contains($js, "chipOfText(mine ? 'accent' : 'quiet', mine ? T('look.changed') : T('look.proposal'),")
        && ($l['de']['look.proposal'] ?? '') === 'Vorschlag für dieses Modell' && ($l['de']['look.reset'] ?? '') === 'Zurück zum Vorschlag');
    check('fanboy ux look: the picker shows the model\'s facts only (no wheel, no blades\' number of its own), the fold\'s one preview is the fan as it will be drawn; no data-sheet speeds any more (2026-11-20: Fanboy measures them)',
        str_contains($js, "preview: false, texts: pickerTexts(),") && str_contains($art, "if (o.preview === false) look.append(facts); else look.append(wh.el, facts);")
        && str_contains($art, "fBlades.hidden = !other || o.preview === false;")
        && !str_contains($js, 'roles.model_off') && !str_contains($js, 'pick.range') && !str_contains($js, 'num(c.rpm_max)'));
    // round 12 fix, the net: heat sources no zone covers — said in the zones step and at «Übernehmen», the net's state in the banner
    same('fanboy ux uncovered: while he changes zones the page uses the agent\'s rule on the draft — the kinds the net watches', FANBOY_NET_KINDS,
        preg_match("/const NET_KINDS = \\[([^\\]]+)\\];/", $js, $m) ? array_map(fn ($x) => trim($x, " '"), explode(',', $m[1])) : []);
    check('fanboy ux uncovered: for the kept zones the list is the agent\'s (setup.uncovered); the zones step says it above the zones with one click per source into the zone shown',
        str_contains($js, "if (!zdraft || !zones) return (setup().uncovered || []).map(") && str_contains($js, "return all.filter((x) => NET_KINDS.includes(x.kind) && !inZones.has(x.id));")
        && str_contains($js, "const loose = uncoveredNow(zones);") && str_contains($js, "...loose.map((x) => btn(srcLabel(x), 'btn plain', () => into([x.id]), T('zones.uncovered_in_tip'))),")
        && str_contains($js, "const text = el('p', '', T(pct === null ? 'zones.uncovered' : 'zones.uncovered_pct', { list: loose.map(srcSay).join(', '), pct }));"));
    check('fanboy ux uncovered: at «Übernehmen» the same list; where a fan of his may stand (the state\'s may_stand) the dialog asks plainly and only that yes sends uncovered_ok',
        str_contains($js, "const loose = (su.zones || []).length ? uncoveredNow() : [];") && str_contains($js, "const standing = (su.fans || []).filter((f) => f.may_stand === true && mine.has(f.id));")
        && str_contains($js, "const sure = loose.length && standing.length;") && str_contains($js, "await act('take_over', sure ? { uncovered_ok: true } : {}, b2,")
        && str_contains($js, "T(sure ? 'take.uncovered_go' : 'take.go')") && substr_count($js, 'uncovered_ok') === 1);
    check('fanboy ux uncovered: the sentence claims no number of its own — the temperatures are the state\'s (marked for the numbers check), the net\'s levels are not named',
        !preg_match('/\d/', (string) ($l['de']['zones.uncovered'] ?? '1')) && !preg_match('/\d/', (string) ($l['de']['take.uncovered_confirm'] ?? '1'))
        && str_contains($js, "map((x) => ({ v: Math.round(x.c), from: ['setup', 'sources', `=\${x.id}`, 'c'], via: 'round' })),"));
    check('fanboy ux net: while the net holds the fans up, the banner says which source, how warm and the level — all three from control.net',
        str_contains($js, "const net = c.running && c.net && c.net.source && num(c.net.level) !== null ? c.net : null;")
        && str_contains($js, "p.dataset.nums = JSON.stringify([{ v: net.level, from: ['control', 'net', 'level'] },")
        && str_contains((string) ($l['de']['net.on'] ?? ''), '{source} {c} °C') && str_contains((string) ($l['de']['net.on'] ?? ''), '{level} %')
        && str_contains($js, "control().net ? [control().net.level, control().net.source] : 0"));
    $demoNet = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/ui/demo/fanboy-uxnet.json'), true);
    check('fanboy ux net: the notes «net» and «net_over» have their words (the agent\'s params: source, c); the demo state shows the net as the agent reports it',
        str_contains((string) ($l['de']['note.net'] ?? ''), '{source}') && str_contains((string) ($l['de']['note.net'] ?? ''), '{c}') && str_contains((string) ($l['de']['note.net_over'] ?? ''), '{source}')
        && array_keys($demoNet['control']['net'] ?? []) === ['level', 'since', 'source', 'clearing'] && array_keys($demoNet['control']['net']['source']) === ['id', 'label', 'kind', 'c', 'warm', 'limit']);
    // exact degrees on the curve (2026-11-16, the owner): each point says its values, the scale is fine enough to aim, every point can be typed
    check('fanboy ux curve: every point carries its exact values beside it («43 °C · 31 %») — where there is room, the active one always; never on another point, clear of the finger while dragged',
        str_contains($art, 'function labelsPaint() {') && str_contains($art, "const box = free || (i === active ? places[0] : null);") && str_contains($art, "const far = held ? 22 : 0;")
        && str_contains($art, "!dotsAt.some((q, j) => j !== i && hit(k, q))") && str_contains($js, "label: (x, v) => (rpmUnit ? T('curve.label_rpm', { t: degrees(x), v: fmt.number(v) }) : T('curve.label_pct', { t: degrees(x),")
        && ($l['de']['curve.label_pct'] ?? '') === '{t} °C · {v} %');
    check('fanboy ux curve: the degrees\' scale is as fine as its numbers have room (5 °C on a disk\'s scale), with a tick for every single degree; the pointer reads whole degrees',
        str_contains($art, "const every = [1, 2, 5, 10, 20].find((n) => n * perDeg >= 42) || 20;") && str_contains($art, "class: 'fan-curve-minor'")
        && str_contains($art, "const t = clamp(Math.round(tAt(px)), tMin, tMax);"));
    check('fanboy ux curve: each point can be typed (degrees and value) — whole numbers, kept between its neighbours, shown as it counts; a field he is typing in is never overwritten and keeps the focus through the page\'s redraw',
        str_contains($art, "pts[k] = which === 't' ? fit(k, n, q.v) : fit(k, q.t, n);") && str_contains($art, "said = tx.clamped(k, which, by,") && str_contains($art, "inp.value = String(which === 't' ? pts[k].t : pts[k].v);")
        && str_contains($art, "if (document.activeElement !== inp && inp.value !== want) inp.value = want;") && str_contains($art, "setTimeout(() => emit(o.onChange), 0);")
        && str_contains($js, "fields: true,") && str_contains($js, "onField: (inp) => { inp.classList.add('input', 'small', 'fb-input'); inp.dataset.fbKeep = '1'; },")
        && str_contains($js, "curveFocus = a && a.dataset && a.dataset.axis && c.el.contains(a) ?"));
    check('fanboy ux curve: the live mark says the degrees as they are read (a tenth where the sensor gives one)',
        str_contains($js, "nowText: nowC !== null ? T('curves.now', { c: degrees(nowC) }) : ''"));
    // round 13 on the page: ONE chart per zone, a curve per kind of its heat sources
    $block = preg_match('~// >>> kind curves.*?\n(.*?)// <<< kind curves~s', $js, $m) ? $m[1] : '';
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    $zonesFor = [
        ['id' => 'a', 'unit' => 'pct', 'target' => ['preset' => 'normal'], 'sources' => [['id' => 'disk1', 'kind' => 'disk'], ['id' => 'n1', 'kind' => 'nvme'], ['id' => 'c1', 'kind' => 'cpu']], 'kinds' => ['disk' => ['high' => 44]]],
        ['id' => 'b', 'unit' => 'pct', 'target' => ['points' => [[30, 25], [40, 40], [50, 70], [60, 100]]], 'sources' => [['id' => 'n1', 'kind' => 'nvme'], ['id' => 'r1', 'kind' => 'ram']], 'kinds' => ['nvme' => ['low' => 38, 'high' => 60]]],
        ['id' => 'c', 'unit' => 'pct', 'target' => ['preset' => 'quiet'], 'low' => 30, 'high' => 50, 'sources' => [['id' => 'c1', 'kind' => 'cpu'], ['id' => 'disk1', 'kind' => 'disk']],
            'curves' => ['disk' => ['points' => [[40, 10], [30, 0], [50, 100]]], 'cpu' => ['preset' => 'cool']]],
        ['id' => 'd', 'unit' => 'pct', 'target' => ['preset' => 'cool'], 'sources' => [['id' => 's1', 'kind' => 'ssd'], ['id' => 'o1', 'kind' => 'other']], 'kinds' => ['other' => ['low' => 50, 'high' => 50]]],
    ];
    if ($block === '') {
        check('fanboy ux r13: the page\'s block «kind curves» is there', false);
    } elseif ($node === '') {
        skip('fanboy ux r13: the page\'s kind curves against the agent\'s (needs node)');
    } else {
        $want = [];
        foreach ($zonesFor as $z) {
            foreach (array_values(array_unique(array_column($z['sources'], 'kind'))) as $k) {
                [$points, $own] = fanboyKindCurve($z, $k);
                $want[$z['id'] . ':' . $k] = ['points' => array_map(fn ($p) => [round($p[0], 6), round($p[1], 6)], $points), 'own' => $own];
            }
        }
        $tmp = sys_get_temp_dir() . '/uso-fanboy-kc-' . getmypid() . '.js';
        file_put_contents($tmp, $block . "\nconst zones = " . json_encode($zonesFor) . ";\nconst scales = " . json_encode(FANBOY_SOURCE_DEFAULTS) . ";\n"
            . "const out = {};\nconst r = (x) => Math.round(x * 1e6) / 1e6;\nfor (const z of zones) for (const k of [...new Set(z.sources.map((x) => x.kind))]) { const c = kindCurveOf(z, k, scales); out[z.id + ':' + k] = { points: c.points.map((p) => [r(p[0]), r(p[1])]), own: c.own }; }\nconsole.log(JSON.stringify(out));\n");
        $got = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'), true);
        @unlink($tmp);
        same('fanboy ux r13: the page works out each kind\'s curve exactly as the agent (own points, own preset, derived from the zone\'s preset or points, the zone\'s own scale, a kind without a span)',
            json_decode((string) json_encode($want), true), $got);
    }
    check('fanboy ux r13: one chart per zone — the kind in front with its handles, the other kinds\' curves faded behind it, one scale of degrees for all',
        str_contains($js, "const others = all.filter((x) => x.k !== K).map((x) => ({ points: x.pts.map((p) => ({ t: p[0], v: p[1] })) }));") && str_contains($js, "const temps = all.flatMap((x) => x.pts.map((p) => p[0]));")
        && str_contains($art, "othersG.appendChild(svg('polyline', { class: 'fan-curve-other', points: xy.join(' ') }));") && str_contains($js, "b.onclick = () => { if (kindSel.get(z.id) !== k) { kindSel.set(z.id, k); drawn = ''; redraw(); } };"));
    // 2026-11-20 (the owner: «immer pro Wärmequelle»): the goal per kind — a preset locked, «Eigene Kurve» his last own one
    check('fanboy ux r13: the goal is the kind\'s — a preset\'s points locked; a change is that kind\'s own curve; «Eigene Kurve» brings back his kept one (else the curve shown); a preset keeps it beside; saving writes every kind\'s choice; no «Wieder der Zone folgen»',
        str_contains($js, "const locked = !rpmUnit && choice !== 'own';") && str_contains($js, "wheel: false, locked,")
        && str_contains($js, "zs[i].curves = { ...(zs[i].curves || {}), [K]: { points: p.map((x) => [x.t, x.v]) } };")
        && str_contains($js, "if (choose.value === 'own') cv[K] = { points: fourPoints(kept || now) };") && str_contains($js, "else cv[K] = { preset: choose.value, ...(was === 'own' ? { own: now } : kept ? { own: kept } : {}) };")
        && str_contains($js, "curves2[k] = { preset: choice, ...(kept ? { own: round(kept) } : {}) };") && !str_contains($js, "T('curve.kind_follow')"));
    check('fanboy ux r13: what each kind asks for and which one the fans follow comes from the control\'s state (by_kind, leader) — marked for the numbers check, never worked out on the page',
        str_contains($js, "const byKind = (cz && cz.by_kind) || {};") && str_contains($js, "const leadKind = cz && cz.leader && kinds.includes(cz.leader.kind) ? cz.leader.kind : null;")
        && str_contains($js, "follows.dataset.nums = JSON.stringify([{ v: led.c, from: ['control', 'zones', `=\${z.id}`, 'by_kind', leadKind, 'c'] },")
        && str_contains((string) ($l['de']['curve.follows'] ?? ''), 'Die Lüfter folgen gerade: {kind} · {source} {c} °C → {value}'));
    check('fanboy ux r13: a curve is shown exactly as it counts (a derived point at 39.5 °C stays 39.5) and becomes whole numbers only once he changes it',
        str_contains($art, ".map((p) => ({ t: clamp(p.t, tMin, tMax), v: clamp(p.v, vMin, vMax) }))") && str_contains($art, "function whole() {") && substr_count($art, 'whole();') >= 3
        && str_contains($js, "const degrees = (c) => fmt.number(c, Number.isInteger(c) ? 0 : 1);"));
    check('fanboy ux r13: several kinds — a fan stands only while no kind asks (a kind whose curve never reaches 0 is named); the CPU cooler on own risk goes by the CPU\'s curve alone',
        str_contains($js, "const neverKind = !isCpu && multi ? kinds.find((k) => curveRule(curveOf(k).points).start === null) : null;") && str_contains($js, "const cpuElse = isCpu && K !== 'cpu';")
        && str_contains($js, "return kindCurveOf(zoneKinded(z), 'cpu', setup().scales || {}).points;"));
    check('fanboy ux: a fan\'s floor is the agent\'s own number where the state has it (the setup\'s plan: the hub\'s margin included), the mirrored rule only for an older agent',
        str_contains($js, "if (f && num(f.floor) !== null) return f.floor;"));
    // the sentence audit (2026-11-16; side-session notes «fanboy-ux-sentence-audit.md»): what was wrong is worded as the agent
    // does it, and every literal number left in a text is tied to the agent's constant here — change one, and this fails
    check('fanboy ux audit: «a zone stands at 100 %» only while one does — the alarm for a fan the BIOS steers has its own words (tile, bubble); «show me» during an alarm claims no 100 %',
        str_contains($js, "const alarmFull = () => (control().zones || []).some((z) => z.full === true);") && str_contains($js, "T(alarmFull() ? 'bubble.alarm' : 'bubble.alarm_watch')")
        && isset($l['de']['why.alarm_watch'], $l['de']['bubble.alarm_watch']) && !str_contains($l['de']['show.off.alarm'], '100') && !str_contains($l['de']['errors.fanboy_show_alarm'], '100'));
    check('fanboy ux audit: the chip alarm does not say the BIOS steers (nobody does: «chip_gone»)', !str_contains($l['de']['alarm.chip'], 'BIOS') && !str_contains($l['en']['alarm.chip'], 'BIOS'));
    $exact = array_filter(['step.take_sub', 'take.hand_back_tip', 'take.hand_back_confirm', 'take.hand_back_text', 'take.given_back', 'why.stopped', 'why.hand_back', 'why.crashed', 'control.crashed'],
        fn ($k) => (bool) preg_match('/genau so|wie (er )?vor ihm/u', (string) ($l['de'][$k] ?? '')));
    same('fanboy ux audit: «hand back» is never «exactly as before» — a fixed value goes to the board\'s automatic by default (FANBOY_BACK_DEFAULT), the texts name both ways', [[], 'auto', false],
        [array_values($exact), FANBOY_BACK_DEFAULT, str_contains(implode(' ', (array) $l['de']['take.confirm']), 'genau so')]);
    check('fanboy ux audit: the CPU cooler is not «always with the BIOS» nor «never off» (cpu_own, cpu_off_confirm); an «unknown» output is «not steered», never «never touched / never written to»',
        !str_contains($l['de']['cpu.risk_4'], 'schaltet ihn nie aus') && !str_contains($l['de']['help.who_text'], 'CPU-Kühler und Pumpen bleiben immer') && !str_contains($l['de']['hints.bios.2'], 'Der CPU-Kühler und eine Pumpe bleiben')
        && !preg_match('/fasst ihn nie an|schreibt (Fanboy )?nie/u', implode(' ', [$l['de']['roles.bios_note.unknown'], $l['de']['take.bios_tip'], $l['de']['label.bios_tip'], $l['de']['show.off.ignored'], $l['de']['errors.fanboy_show_not']])));
    check('fanboy ux audit: a restart of Unraid «usually» lets the BIOS steer again (the board\'s doing, nothing Fanboy can prove); a foreign change is given back «as it was before him», not «to the BIOS»',
        !preg_match('/Ein Neustart von Unraid gibt/u', implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $l['de']))) && !str_contains($l['de']['note.foreign'], 'ans BIOS') && !str_contains($l['de']['out.foreign_title'], 'ans BIOS'));
    check('fanboy ux audit: he «installs nothing; loads a driver only on your click»; the detect step «only reads»; System Temp\'s names are shown, not proposed',
        str_contains($l['de']['hints.sub'], 'nur auf deinen Klick') && str_contains($l['de']['step.detect_sub'], 'Dieser Schritt liest nur') && !str_contains($l['de']['hints.names_count'], 'schlägt'));
    // 2026-11-16 (task/fanboy-truth2: the state's times / limits / restarts): the sentences take their times from the state — the
    // page's wrapper T() adds them to every text; what is left as a literal (the help texts the search shows without params,
    // «a point a minute», the look's minute) stays tied to the agent's constant here
    $timesJs = preg_match('/const TIMES = \{([^}]+)\};/', $js, $m) ? $m[1] : '';
    $mirror = [];
    foreach (['up_hold' => FANBOY_UP_HOLD, 'up_hold_quiet' => FANBOY_UP_HOLD_QUIET, 'measure_max' => fanboyMeasureMaxSecs(), 'measure_usual' => fanboyMeasureUsualSecs(), 'show_for' => FANBOY_SHOW_FOR, 'lease' => FANBOY_STEP_LEASE, 'tell_after' => FANBOY_TELL_AFTER, 'idle_warm_after' => FANBOY_IDLE_WARM_AFTER, 'poll_live' => FANBOY_POLL_LIVE, 'clear_after' => FANBOY_CLEAR_AFTER] as $k => $v) {
        if (!preg_match('/\b' . $k . ': ' . (int) $v . '\b/', $timesJs)) {
            $mirror[] = $k;
        }
    }
    same('fanboy ux audit: the page\'s mirror of the agent\'s times (for an agent that doesn\'t send them yet) equals the agent\'s constants', [], $mirror);
    check('fanboy ux audit: every sentence that names a time takes it from the state\'s times — a test and «show me» at full, the page\'s lease in the strip, the minutes until he tells (no literal left there)',
        str_contains($js, "try { return { ...TIMES, ...(state.setup.times || {}) }; } catch (e) { return TIMES; }")
        && str_contains($js, "return T0(k, { hold_min: Math.round(tm.up_hold / 60), hold_quiet_min: Math.round(tm.up_hold_quiet / 60), measure_min: Math.ceil(tm.measure_max / 60), measure_usual_min: Math.round(tm.measure_usual / 60), show_s: tm.show_for, lease_s: tm.lease, tell_min: Math.round(tm.tell_after / 60), idle_min: Math.round(tm.idle_warm_after / 60), ...(p || {}) });")
        && str_contains($l['de']['step.test_sub'], 'nach {hold_min} Minuten ({hold_quiet_min},') && str_contains($l['de']['show.tip'], 'bis zu {show_s} Sekunden') && str_contains($l['de']['strip.keep_open'], 'nach {lease_s} Sekunden')
        && str_starts_with($l['de']['note.missing_told'], '{tell_min} Minuten') && str_contains($l['de']['watch.on_title'], 'nach {idle_min} Minuten') && str_contains($l['de']['strip.since'], 'höchstens {max} s')
        && str_contains($js, "T('strip.since', { s: secs, max: most })") && !preg_match('/\d/', str_replace('100 %', '', implode(' ', [$l['de']['step.test_sub'], $l['de']['strip.keep_open'], $l['de']['watch.on_title'], $l['de']['note.missing_told']]))));
    same('fanboy ux audit: the literals left are the agent\'s — the help texts (shown by the search without params): a test in the quiet mode three minutes at most without an answer, a show a minute, the 45 s lease; a point a minute; a name\'s and a connector\'s maximum come with the refusal',
        [180.0, 60.0, true, true, true],
        [FANBOY_UP_HOLD_QUIET, FANBOY_SHOW_FOR,
            str_contains($l['de']['help.safe_text'], 'nach ' . (int) FANBOY_STEP_LEASE . ' Sekunden') && str_contains($l['de']['help.quiet_text'], 'höchstens drei Minuten') && str_contains($l['de']['help.show_text'], 'bis zu einer Minute'),
            FANBOY_HISTORY_EVERY === 60.0 && str_contains($l['de']['curves.history_none'], 'einem Punkt pro Minute'),
            str_contains($l['de']['errors.fanboy_bad_count'], 'Zwischen 1 und {max}') && str_contains($l['en']['errors.fanboy_bad_count'], '{max}') && str_contains($l['de']['errors.fanboy_bad_name'], '{max}')]);
    check('fanboy ux audit: the limits the page offers are the state\'s (fans on a connector, zones); the crashed control says when it is started again and when not any more from the state\'s times and restarts — «given up» has its own words',
        str_contains($js, "n <= (num((setup().limits || {}).count_max) ?? 8)") && str_contains($js, "zones.length < (num((setup().limits || {}).zones_max) ?? 8)")
        && str_contains($js, "T(rs.given_up ? 'control.crashed_given_up' : 'control.crashed_n', { pause: tm.crash_pause, max: rs.max, window: Math.round(rs.window / 60) })")
        && isset($l['de']['control.crashed_given_up'], $l['de']['control.crashed_n']) && FANBOY_COUNT_MAX === 8);
    check('fanboy ux uncovered: the level the net holds the fans at is said from the state (times.net.warm_pct), marked for the numbers check; without the key: no number',
        str_contains($js, "T(pct === null ? 'zones.uncovered' : 'zones.uncovered_pct', { list: loose.map(srcSay).join(', '), pct })") && str_contains($js, "[{ v: pct, from: ['setup', 'times', 'net', 'warm_pct'] }]")
        && str_contains($l['de']['zones.uncovered_pct'], 'mindestens {pct} %'));
    same('fanboy ux audit: the spans the history offers are the agent\'s', FANBOY_HISTORY_PERIODS, preg_match('/const PERIODS = \[([^\]]+)\];/', $js, $m) ? array_map('intval', explode(',', $m[1])) : []);
    // round 13b on the page: the disks outside array and pools
    $devs = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/ui/demo/fanboy-uxdevs.json'), true);
    check('fanboy ux r13b: disks outside array and pools are a group of their own among a zone\'s heat sources («Nicht zugewiesen / an VMs»), named by the plugin\'s name or «Nicht zugewiesen · size» — never by their id',
        str_contains($js, "[...kinds.slice(0, 2), 'unassigned', ...kinds.slice(2)].forEach((k) => {") && str_contains($js, "if (/^ud-/.test(s.id)) return udLabel(s.id);")
        && str_contains($js, "return nth ? T('src.unassigned_n', { size, n: nth }) : T('src.unassigned', { size });")
        && ($l['de']['src_group.unassigned'] ?? '') === 'Nicht zugewiesen / an VMs');
    check('fanboy ux r13b: a disk in use without a temperature says so («in Gebrauch, Temperatur unbekannt») — among the heat sources, in the sensors\' list, in sentences; the help says what Unraid cannot see',
        str_contains($js, "const srcReads = (x) => (num(x.c) !== null ? T('temp', { c: fmt.number(x.c) }) : x.in_use ? T('src.in_use') : x.asleep ? T('pic.asleep') : '');")
        && str_contains($js, "if (x.in_use) meta.appendChild(chipOfText('warn', T('src.in_use'), T('src.in_use_title')));") && ($l['de']['src.in_use'] ?? '') === 'in Gebrauch, Temperatur unbekannt'
        && str_contains((string) ($l['de']['help.vm_disks_text'] ?? ''), 'als Ganzes an eine VM durchgereicht'));
    $udSrc = array_values(array_filter($devs['setup']['sources'] ?? [], fn ($x) => ($x['group'] ?? null) === 'unassigned'));
    check('fanboy ux r13b: the demo state carries such disks as the agent lists them (group, name|null, size, in_use) and no serial',
        count($udSrc) === 3 && array_keys($udSrc[0]) === ['kind', 'id', 'label', 'c', 'asleep', 'group', 'name', 'size', 'in_use'] && $udSrc[2]['in_use'] === true && $udSrc[1]['name'] === null
        && count(array_filter($udSrc, fn ($x) => !preg_match('/^ud-[0-9a-f]{12}$/D', $x['id']))) === 0);
    // round 14 on the page: several fans on one connector
    check('fanboy ux r14: step 2 asks «Gleiche Lüfter an allen Steckern?» once a connector has several fans (the state\'s hub) — yes / no, different / don\'t know, saved alone with the role',
        str_contains($js, "[['yes', true], ['no', false], ['unknown', null]].forEach(([k, v]) => {") && str_contains($js, "const b = btn(T(`hub.alike_\${k}`), 'fb-pick', () => { draft.alike = v; update(); }, T(`hub.alike_\${k}_tip`));")
        && str_contains($js, "const hub = roleSet && !never && cur !== 'empty' && f.hub ? f.hub : null;") && ($l['de']['hub.alike'] ?? '') === 'Gleiche Lüfter an allen Steckern?');
    check('fanboy ux r14: the margin for unlike fans is said in the agent\'s own numbers (hub.floor, hub.start — marked for the numbers check), whose table it is too',
        substr_count($js, "{ v: hub.floor, from: ['setup', 'fans', `=\${id}`, 'hub', 'floor'] }, { v: hub.start, from: ['setup', 'fans', `=\${id}`, 'hub', 'start'] }") === 2
        && str_contains((string) ($l['de']['hub.mixed'] ?? ''), 'Minimum {floor} %, Anlauf {start} %') && str_contains((string) ($l['de']['hub.table'] ?? ''), 'der seine Drehzahl meldet'));
    check('fanboy ux r14: «Mit den Augen prüfen» runs the agent\'s hub_look; its questions (do all turn, all stand, do all start again) are answered in the fan\'s panel with step_next {heard}; the strip and the bubble say the look, not a test',
        str_contains($js, "send('hub_look', { fan: id, ...warmBody() });") && str_contains($js, "btn(T('hub.yes'), 'btn', () => send('step_next', { fan: id, heard: true }), T('hub.yes_tip'))")
        && str_contains($js, "btn(T('hub.no'), 'btn plain', () => send('step_next', { fan: id, heard: false }), T('hub.no_tip'))") && str_contains($js, "const answer = part && part.phase !== 'hub_stop';")
        && str_contains($js, "r.kind = s.kind === 'measure' ? 'measure' : s.kind === 'hub_look' ? 'hub' : 'test';") && str_contains($js, "T(`strip.h.\${run.part.phase}`,")
        && str_contains($js, "step().kind === 'hub_look' ? T('bubble.hub_look')"));
    $hubKeys = [];
    foreach (['hub_turn', 'hub_stop', 'hub_start'] as $ph) {
        foreach (["hub.ask.$ph", "strip.h.$ph"] as $k) {
            if (!is_string($l['de'][$k] ?? null)) {
                $hubKeys[] = $k;
            }
        }
    }
    foreach (['ok', 'floor_up', 'not_safe', 'no_answer', 'warm'] as $res) {
        if (!is_string($l['de']["hub.result.$res"] ?? null)) {
            $hubKeys[] = "hub.result.$res";
        }
    }
    same('fanboy ux r14: every phase of the look and every result has its words; «no answer within a minute» is the agent\'s minute', [[], 60.0, true],
        [$hubKeys, FANBOY_HUB_LOOK_FOR, str_contains($l['de']['hub.result.no_answer'], 'innert einer Minute')]);
    check('fanboy ux r14: what the look found is the state\'s (hub.ok, hub.not_safe, hub.extra); «darf stehen» waits for it (zones: the reason; the agent\'s off_why mixed_hub / hub_not_safe in words)',
        str_contains($js, "out.push(para(hub.not_safe ? T('hub.not_safe') : hub.ok ? T('hub.ok') : T('hub.unchecked')));") && str_contains($js, "if (f.hub && f.hub.mixed && f.hub.not_safe) return T('zones.stand_hub_no');")
        && str_contains($js, "if (f.hub && f.hub.mixed && !f.hub.ok) return T('zones.stand_hub');") && isset($l['de']['off_why.mixed_hub'], $l['de']['off_why.hub_not_safe']));
    check('fanboy ux r14: where the zones say a hub fan may not stand yet, the way out is right there — «Es sind gleiche Lüfter» (into the zones draft, saved with «Speichern») or «Mit den Augen prüfen» (step 3 opened at this fan, which the list also follows while the look runs)',
        str_contains($js, "if (f.hub && f.hub.mixed && !f.hub.ok && !f.hub.not_safe && no === T('zones.stand_hub')) {") && str_contains($js, "() => changeFan(id, { alike: true }),")
        && str_contains($js, "setupSwitcher().pick('measure'); drawn = ''; redraw(); if (mbench) mbench.select(id);") && str_contains($js, "follow: (run) => (run.kind === 'measure' || run.kind === 'hub' ? run.id : ''),"));
    check('fanboy ux uncovered: a calm hint, not an alarm (never the warn look); an unassigned disk with a name like «dev1» says what it is outside its group («Nicht zugewiesen · dev1»)',
        str_contains($js, "const p = el('div', 'callout fb-callout fb-uncovered');") && !str_contains($js, "'callout warn fb-callout fb-uncovered'")
        && str_contains($js, "if (name) return bare ? name : T('src.unassigned_name', { name });") && ($l['de']['src.unassigned_name'] ?? '') === 'Nicht zugewiesen · {name}');
    check('fanboy ux: no text of the page speaks of a «card» or of «More» any more (de, en)', !preg_match('/\bKarte\b|«Mehr»/u', implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $l['de'])))
        && !preg_match('/(?<!graphics )\bcards?\b|«More»/i', implode(' ', array_map(fn ($v) => implode(' ', (array) $v), $l['en']))));
    // the owner, 2026-11-15: «kein AI-Slop, der nicht stimmt» — no sentence names a number the state doesn't give
    check('fanboy ux: the CPU cooler\'s «may stand» confirmation is built from his curve by the control\'s rule and from the state — the degrees are the curve\'s, the 5 °C and the minute the agent\'s constants; that the mainboard\'s automatic takes over only where the board showed it (auto_back_seen); no sentence carries a number of its own',
        str_contains($js, "out.push(r.start !== null ? T('cpustand.curve', { start: r.start, hard: r.hard, below: r.start - CPU_STAND_GAP, cool: CPU_STAND_AFTER }) : T('cpustand.never_zero'));")
        && str_contains($js, "out.push(seen && num(seen.at) !== null ? T('cpustand.gone_seen', { date: Office.fmt.date(seen.at) }) : T('cpustand.gone_unseen'));") && !isset($de['zones.cpu_stand_text'])
        && !preg_match('/\d/', preg_replace('/\{\w+\}/', '', $de['cpustand.what'] . $de['cpustand.curve'] . $de['cpustand.gone_seen'] . $de['cpustand.gone_unseen'] . $de['cpustand.hang'] . $de['curve.says_cpu'])));
    $ctl = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php');
    preg_match('/const CPU_STAND_GAP = (\d+);/', $js, $pg);
    preg_match('/const CPU_STAND_AFTER = (\d+);/', $js, $pa);
    preg_match('/const FANBOY_CPU_STAND_GAP = ([\d.]+);/', $ctl, $ag);
    preg_match('/const FANBOY_CPU_STAND_AFTER = ([\d.]+);/', $ctl, $aa);
    same('fanboy ux: the page\'s numbers for the CPU cooler\'s standstill are the control\'s (5 °C under its start, a minute)', [(float) ($ag[1] ?? -1), (float) ($aa[1] ?? -1)], [(float) ($pg[1] ?? -2), (float) ($pa[1] ?? -2)]);
    $control = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php');
    preg_match('/const FLOOR_MARGIN = (\d+);/', $js, $fm);
    preg_match('/const BLIND_FLOOR = (\d+);/', $js, $bf);
    preg_match('/const FANBOY_FLOOR_MARGIN = (\d+);/', $control, $afm);
    preg_match('/const FANBOY_BLIND_FLOOR = (\d+);/', $control, $abf);
    same('fanboy ux: the page\'s floors are the control\'s (lowest steady value + margin, the blind floor)', [$afm[1] ?? null, $abf[1] ?? null], [$fm[1] ?? '', $bf[1] ?? '']);
    check('fanboy ux: the curve chart draws what the control applies for the zone\'s first fan — its floor, and standing only where it may and the curve asks for nothing — and says it in a sentence of those numbers',
        str_contains($js, "apply: rpmUnit ? undefined : (v) => (stands && v < 0.5 ? 0 : Math.max(v, floorPct)),") && str_contains($js, "const stands = !!lead.off_ok && !standWhy(lead) && !cpuElse;")
        && str_contains($js, "const says = para(curveWords(pts, floorPct, stands, isCpu, {") && str_contains($art, "const applied = (v) => (typeof o.apply === 'function' ? o.apply(v) : v);")
        && str_contains($art, "line.setAttribute('class', 'fan-curve-line fan-curve-wish');"));
    check('fanboy ux: sentences that take numbers from the state are marked (data-nums) and the picture tool compares each with the demo state it shows',
        substr_count($js, '.dataset.nums = JSON.stringify(') >= 3 && str_contains($tool, "if (want !== x.v) faults.push(") && str_contains($tool, 'is not in its sentence'));
    check('fanboy ux: one button size per row — every button of the setup is the office\'s .btn (the fill tells primary from secondary); no «small» beside a full one',
        !preg_match('/btn small/', substr($js, (int) strpos($js, 'function stripMake() {'), (int) strpos($js, '// ------------------------------------------------------------------ the page') - (int) strpos($js, 'function stripMake() {'))));
    check('fanboy ux: five steps — no dry-run step; what he would set right now is asked for by «take over» itself (dry_run reads only) and shown compact before the button; the old route …/setup/dry lands there',
        !str_contains($js, 'function dryStep(') && str_contains($js, "if (rest === 'dry') rest = 'take';") && str_contains($js, "dryAsk(false); box.appendChild(dryPreview());")
        && str_contains($js, "Office.api.post(`\${ID}.dry_run`, {}).then((j) => {") && !isset($de['step.dry']) && str_contains($de['help.setup_text'], 'Fünf Schritte') && str_contains($de['step.blocked_steering'], 'Schritt 5'));
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
    check('fanboy count: the page — «How many fans on it?» sent with the role when changed (2026-11-20: only what the draft changed), as many wheels in the picture sharing one speed, the tile counting connectors',
        str_contains($js, "draft.count = Number(count.value)") && str_contains($js, "if (draft.count !== undefined) body.count = draft.count;") && str_contains($js, 'fans: (z.fans || []).flatMap((id) => wheelsOf(id)),')
        && str_contains($js, "if (Number(c.turning)) out.push(T('now.fans', { n: Number(c.turning),") && !str_contains($js, "'fans.turning'"));
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
    // 2026-11-17: the tile «Warmest spots» went; its lead (the place nearest to its limit) is a line of the tile «Now»
    check('fanboy hottest: the tile «Now» — the lead by its limit (Unraid\'s or the user\'s), in colour once over it, nothing when nothing is near',
        $en['tile.now'] === 'Now' && str_contains($js, 'return lead && lead.c - lead.limit >= -LEAD_NEAR ? lead : null;')
        && str_contains($js, 'const hot = state ? hottestWords() : null;') && str_contains($js, "|| fans.stands || !!(hot && hot.over);"));
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
        check('fanboy stands kick: … in the words for a fan alone in its zone («… sets it to 100 % until it turns again, and then for 2 more minutes»)',
            (bool) array_filter(fanboyTNotified($t), fn ($l) => str_contains($l, 'sets it to 100 % until it turns again, and then for ' . (int) round(FANBOY_CLEAR_AFTER / 60) . ' more minutes')));
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
        same('fanboy nvme warm: the NVMe pool at 50 °C, the hard disks at 35 — nothing too warm (the SSD\'s limit 80)', null, fanboyStepWarm($settings));
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        $step = fanboyStepRun(['id' => 'n1', 'kind' => 'measure', 'fans' => [fanboyTId(4)]], fanboyTIo($t, $spec, $clock));
        same('fanboy nvme warm: … measuring runs through: a table, a lowest steady value', ['done', 'ok', 20],
            [$step['status'], $step['results'][fanboyTId(4)]['result'] ?? null, $step['results'][fanboyTId(4)]['min_pct'] ?? null]);
        // an earlier set-up's own, lower limit for the pool in a zone (52): not read any more (2026-11-20) — the SSD's own 80
        $s2 = $settings;
        $s2['zones'][1]['sources'][] = ['kind' => 'ssd', 'id' => 'cache', 'limit' => 52];
        fanboyTStore($s2);
        same('fanboy nvme warm: an earlier set-up\'s own limit for the pool (52) — not read: at 50 nothing too warm', null, fanboyStepWarm($s2));
        // the pool at 76 °C (80 − 5): too warm — the step stops «warm» and names it
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '35', '1'], ['cache', 'Cache', 'nvme0n1', '76', '0']]);
        $clock = 0.0;
        $step = fanboyStepRun(['id' => 'n2', 'kind' => 'measure', 'fans' => [fanboyTId(4)]], fanboyTIo($t, $spec, $clock));
        same('fanboy nvme warm: … at 76 °C the step stops «warm» and names it', ['stopped', 'warm', 'cache', 76.0, 80.0, [128, 1]],
            [$step['status'], $step['why'], $step['warm']['source'] ?? null, $step['warm']['c'] ?? null, $step['warm']['limit'] ?? null, fanboyTOut($t, 4)]);
        fanboyTStore($settings);
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '35', '1'], ['cache', 'Cache', 'nvme0n1', '76', '0']]);
        same('fanboy nvme warm: the pool at 76 °C — close to the SSD limit (80 − 5, since 2026-11-20)', ['cache', 'ssd', 80.0], array_values(array_intersect_key(fanboyStepWarm($settings) ?? [], array_flip(['source', 'kind', 'limit']))));
        fanboyTDisksKinds($t, [['parity', 'Parity', 'sdb', '51', '1'], ['cache', 'Cache', 'nvme0n1', '50', '0']]);
        same('fanboy nvme warm: a hard disk at 51 °C — its own kind\'s limit (55 − 5)', ['parity', 'disk', 55.0], array_values(array_intersect_key(fanboyStepWarm($settings) ?? [], array_flip(['source', 'kind', 'limit']))));
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
 * How he reacts, by the kind that leads the zone (round 4; 2026-11-20 the owner: «Langsam» / «Schnell reagieren»). Langsam
 * (stored «balanced»): a CPU up 4 % a round, down 3 °C under the highest after 30 s at 2 % a round, no hunting with a spiky CPU. Schnell (quick): round 4's way — up at once, a short hold, bigger steps.
 */
function testFanboyReact(): void
{
    $t = fanboyT('react');
    try {
        fanboyTDisks($t, 35, null, 33);
        $cpuSrc = 'coretemp@platform/coretemp.0#1';
        $cpuFile = "{$t['root']}/sys/devices/platform/coretemp.0/hwmon/hwmon7/temp1_input";
        $settings = fanboyTSettings();
        // (2026-11-20: the presets changed — the curve this test was written on, round 4's CPU «normal», as the zone's own)
        $settings['zones'] = [['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [fanboyTId(5)], 'unit' => 'pct', 'target' => ['points' => [[45, 20], [62.5, 45], [80, 80], [83, 100]]],
            'sources' => [['kind' => 'cpu', 'id' => $cpuSrc]]]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        same('fanboy react: a zone saved without a choice reacts «Langsam» (stored «balanced»); the CPU\'s — up 4 % a round, down 3 °C under the highest, after 30 s, 2 % a round',
            ['balanced', ['up' => 4.0, 'gap' => 3.0, 'hold' => 30.0, 'step' => 2.0]], [fanboyControlNew($settings, FANBOY_T_KEY, ['pid' => 0])['zones']['cpuz']['react'], fanboyDownProfile('cpu', 'balanced')]);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 5000.0;
        file_put_contents($cpuFile, "45000\n");
        fanboyTRounds($c, $now, $t, $spec, 3);
        $rest = $c['zones']['cpuz']['cur'];
        file_put_contents($cpuFile, "80000\n");
        $levels = [];
        for ($i = 0; $i < 16; $i++) {
            fanboyTRounds($c, $now, $t, $spec, 1);
            $levels[] = (int) round($c['zones']['cpuz']['cur']);
        }
        same('fanboy react: Langsam — a CPU at 80 °C (the curve: 80 %) — up 4 % a round from 20 %, there after 15 rounds (30 s), not beyond',
            [20, 24, 28, 32, 36, 40, 44, 48, 52, 56, 60, 64, 68, 72, 76, 80, 80], [(int) round($rest), ...$levels]);
        file_put_contents($cpuFile, "45000\n");
        $levels = [];
        for ($i = 0; $i < 18; $i++) {
            fanboyTRounds($c, $now, $t, $spec, 1);
            $levels[] = (int) round($c['zones']['cpuz']['cur']);
        }
        same('fanboy react: … cooled to 45 °C — held for 30 s (15 rounds), then down 2 % a round', [...array_fill(0, 15, 80), 78, 76, 74], $levels);
        // a spiky CPU around 60 °C: never down again inside the 3 °C — no hunting
        file_put_contents($cpuFile, "61500\n");
        fanboyTRounds($c, $now, $t, $spec, 70);
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
        check('fanboy react: … a spiky CPU (59.5 ↔ 61.5 °C) — the fan steady: no hunting (' . implode(',', $written) . ')', $changes <= 1);
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
        same('fanboy react: «Schnell reagieren» — up at once, held 3 s (2 rounds), then 8 % a round', [80, 80, 72, 64], $levels);
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
 * Round 4 (the owner) gave each zone «Temperaturen» per kind and a source values of its own; 2026-11-20 (the owner: «ganzer
 * Bereich kann weg, weil man oben jede Wärmequelle einstellen kann und auch muss» — and the single source «auch weg»):
 * each kind's low, high and limit are its own (FANBOY_SOURCE_DEFAULTS). What an earlier set-up stored there is not read
 * (the control steers as without it) and not kept by the next save; an older page sending it is not refused.
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
        $zone = fn (array $kinds, array $src = []) => ['id' => 'cpuz', 'name' => 'CPU zone', 'fans' => [fanboyTId(5)], 'unit' => 'pct',
            'target' => ['preset' => 'normal'], 'sources' => [['id' => $cpuSrc] + $src], 'kinds' => $kinds];
        $saved = fanboyZonesCheck([$zone(['cpu' => ['low' => 40, 'high' => 58, 'limit' => 70]], ['low' => 30, 'high' => 50, 'limit' => 60])], fanboyDetect(), $settings)[0];
        same('fanboy temps: an older page\'s «Temperaturen» and a source\'s own values — not refused, not kept (the shape stays empty)',
            ['{}', null, null, null], [json_encode($saved['kinds']), $saved['sources'][0]['low'], $saved['sources'][0]['high'], $saved['sources'][0]['limit']]);
        // stored by an earlier set-up (written straight into the store): the control steers as without them
        $level = function (array $kinds, array $src = []) use ($zone, $settings) {
            $s = $settings;
            $z = $zone($kinds, $src);
            $z['sources'][0]['kind'] = 'cpu';
            $s['zones'] = [$z];
            fanboyTStore($s);
            return array_column(fanboyDryRun()['dry']['zones'], 'level', 'id')['cpuz'] ?? null;
        };
        same('fanboy temps: an earlier set-up\'s values (CPU «volle Drehzahl bei» 58, the source\'s own 30…50) steer nothing — the CPU at 60 °C asks what Normal asks on the CPU\'s own scale (50…80: 33.3 %)',
            [33.3, 33.3, 33.3], [round((float) $level([]), 1), round((float) $level(['cpu' => ['low' => 40, 'high' => 58]]), 1), round((float) $level([], ['low' => 30, 'high' => 50, 'limit' => 55]), 1)]);
        same('fanboy temps: the limits are the kinds\' own — disk 55, SSD and NVMe 80, CPU 92, GPU 90, RAM 75, board 70, other 75',
            ['disk' => 55, 'ssd' => 80, 'nvme' => 80, 'cpu' => 92, 'gpu' => 90, 'ram' => 75, 'board' => 70, 'other' => 75],
            array_map(fn ($k) => FANBOY_SOURCE_DEFAULTS[$k]['limit'], array_combine(['disk', 'ssd', 'nvme', 'cpu', 'gpu', 'ram', 'board', 'other'], ['disk', 'ssd', 'nvme', 'cpu', 'gpu', 'ram', 'board', 'other'])));
    } finally {
        fanboyTEnd();
    }
}

/**
 * Round 4 (the owner): the CPU cooler «Fanboy regelt ihn» — only with the stored, dated, ticked confirmation; never
 * written without it. Its zone must hold a CPU temperature; a floor of its own (since 2026-11-16 the rule of every fan: measured lowest + 5); given back to the
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
        same('fanboy cpu own: without the confirmation the CPU cooler in a zone is refused (not his: named)', 'fanboy_zone_fan_gone:', $code);
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

        // the control: his now — its measured floor (25 %) at a cool CPU on «quiet» (0 %), never off (even «off» stored), balanced
        $settings = fanboyBoardRaw();
        $settings['fans'][$cpuId]['off_ok'] = true;
        $settings['fans'][$cpuId]['back'] = 'fixed';
        $settings['fans'][$cpuId]['measured'] = ['table' => [[100, 1800], [50, 900], [20, 360]], 'min_pct' => 20, 'start_pct' => 25, 'max_rpm' => 1800, 'at' => 1];
        $settings['zones'][] = $cpuZone;
        fanboyTStore($settings);
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        // 2026-11-16 (the owner: «gemäss eingestellter Kurve»): the same floor rule as every fan of his — round 4's 40 % is gone
        same('fanboy cpu own: in the control — his, floor 25 % (measured 20 + 5, as for every fan of his), never off, given back to the automatic, balanced, not watched',
            [true, 25, false, 'auto', 'balanced', false],
            [isset($c['fans'][$cpuId]), $c['fans'][$cpuId]['floor'] ?? null, $c['fans'][$cpuId]['off_ok'] ?? null, $c['fans'][$cpuId]['back'] ?? null, $c['zones']['cpuz']['react'] ?? null, isset($c['watch'][$cpuId])]);
        fanboyTRounds($c, $now, $t, $spec, 4);
        same('fanboy cpu own: … CPU 40 °C on «quiet»: its floor 25 % (64), never under', 64, fanboyTOut($t, 1)[0]);
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

/**
 * Round 4: the page draws the presets as the agent steers them — 2026-11-20 (the owner's table): each kind's own, Normal
 * from low to high, Leise shifted warmer by the step (its 100 % never above the limit), Kühl cooler; every one 0 → 100 %,
 * Kühl < Normal < Leise in degrees at every percent (testFanboyR13 runs the page's block against the agent's in node)
 */
function testFanboyPresetsMirror(): void
{
    $php = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-control.php');
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    check('fanboy presets mirror: fanboyPreset() and the page\'s kcPreset() — the same three straight lines',
        str_contains($php, "'quiet' => [[\$lo + \$step, 0], [max(\$lo + \$step + 1, min(\$hi + \$step, \$limit ?? INF)), 100]],")
        && str_contains($php, "'cool'  => [[\$lo - \$step, 0], [\$hi - \$step, 100]],") && str_contains($php, "default => [[\$lo, 0], [\$hi, 100]],")
        && str_contains($js, "if (preset === 'quiet') return [[lo + step, 0], [Math.max(lo + step + 1, Math.min(h + step, kcNum(limit) ? limit : Infinity)), 100]];")
        && str_contains($js, "if (preset === 'cool') return [[lo - step, 0], [h - step, 100]];") && str_contains($js, "return [[lo, 0], [h, 100]];"));
    $table = ['disk' => [[35, 45], [40, 50], [45, 55]], 'ssd' => [[40, 60], [50, 70], [60, 80]], 'nvme' => [[40, 60], [50, 70], [60, 80]], 'cpu' => [[40, 70], [50, 80], [60, 90]],
        'gpu' => [[40, 70], [50, 80], [60, 90]], 'ram' => [[45, 65], [50, 70], [55, 75]], 'board' => [[40, 60], [45, 65], [50, 70]], 'other' => [[45, 65], [50, 70], [55, 75]]];
    $got = [];
    $bad = [];
    foreach (array_keys($table) as $k) {
        $c = fanboyKindPreset('cool', $k);
        $n = fanboyKindPreset('normal', $k);
        $q = fanboyKindPreset('quiet', $k);
        $got[$k] = [[$c[0][0], end($c)[0]], [$n[0][0], end($n)[0]], [$q[0][0], end($q)[0]]];
        foreach ([$c, $n, $q] as $pts) {
            if ($pts[0][1] !== 0 || end($pts)[1] !== 100 || end($pts)[0] > FANBOY_SOURCE_DEFAULTS[$k]['limit']) {
                $bad[] = "$k ends";
            }
        }
        for ($pct = 0; $pct <= 100; $pct += 5) {       // the temperature where each reaches $pct: Kühl < Normal < Leise
            $at = fn (array $p) => $p[0][0] + ($p[1][0] - $p[0][0]) * $pct / 100;
            if (!($at($c) < $at($n) && $at($n) < $at($q))) {
                $bad[] = "$k at $pct %";
            }
        }
    }
    same('fanboy presets mirror: the owner\'s table — per kind Kühl, Normal, Leise from 0 % to 100 % (°C)', $table, array_map(fn ($r) => array_map(fn ($p) => array_map('intval', $p), $r), $got));
    same('fanboy presets mirror: … every preset 0 → 100 %, never past the kind\'s limit, Kühl < Normal < Leise at every percent', [], $bad);
}

/**
 * 2026-11-17 (fanboy-overview): the overview is what is and what was, nothing to set up — three tiles («Now», «History»,
 * «Sensors»), the old routes landing on them, no button on it that changes a fan or a setting; the hints under the
 * setup's first step, the settings a tile of the setup; the search and the Dashboard tile's links follow
 */
function testFanboyOverview(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $places = json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/places.json'), true);
    $l = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $c) {
        $l[$c] = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$c.json"), true);
    }
    $at = fn (string $from, int $len) => substr($js, (int) strpos($js, $from), $len);
    preg_match_all("/\{ id: '([a-z]+)', key: 'tile\.[a-z]+'/", $js, $tiles);
    same('fanboy overview: three tiles — now, history, sensors', ['now', 'history', 'sensors'], $tiles[1]);
    same('fanboy overview: the old routes land on the new tiles (who, fans, hottest → now; today → history)',
        1, preg_match("/const OLD_TILES = \{ who: 'now', fans: 'now', hottest: 'now', today: 'history' \};/", $js));
    check('fanboy overview: … the old «hints» route opens the setup\'s first step; the tiles\' sub-routes go through the map',
        str_contains($js, "if (String(sub || '') === 'hints') sub = 'setup/detect';") && str_contains($js, "sw.mount(OLD_TILES[String(sub || '')] || sub);"));
    // nothing on the overview that changes anything: its sections' builders hold no act(), send(), btn() or button()
    $fn = function (string $name) use ($js): string {          // a function's body, up to its closing brace at the line's start
        $i = strpos($js, "function $name(");
        return $i === false ? '' : substr($js, $i, (int) strpos($js, "\n}\n", $i) - $i);
    };
    $ov = $fn('nowSection') . $fn('notSteeringLine') . $fn('fansPart') . $fn('outputRow') . $fn('historySection');
    check('fanboy overview: «Now», its fans list and «History» hold no action — no act(), send(), button() or btn()',
        !preg_match('/\b(act|send|button|btn)\(/', $ov), $ov);
    check('fanboy overview: no «Show me this fan», no «Change setup», no settings on the overview',
        !str_contains($js, 'function showButton(') && !isset($l['en']['server.change']) && !str_contains($ov, 'dashChoice') && !str_contains($ov, 'watchChoice')
        && !str_contains($ov, 'T(\'show.button\')') && !str_contains($ov, "setup/zones"));
    check('fanboy overview: what stays is state — the banner (alarm, net, nobody/fixed, safety with its way back), the strip while something runs',
        str_contains($js, "if (banner.parentNode !== sw.el) sw.el.insertBefore(banner, sw.body);") && str_contains($js, "T('safety.banner'")
        && str_contains($js, "if (state && stripNeeded()) {") && str_contains($js, "T('net.on'"));
    check('fanboy overview: «Fanboy steers … in n zones» only in the setup; not steering: one plain line in «Now» with the way to the setup',
        str_contains($js, "} else if (s === 'ok' && !banner.children.length && page === 'setup' &&") && str_contains($js, "const go = el('a', 'fb-now-go', T('page.setup'));")
        && str_contains($js, "go.href = `#/\${ID}/setup`;") && str_contains($js, "if (!state || steers() || who().who !== 'bios' || situation() !== 'ok' || who().why === 'fixed'"));
    // the setup: the hints under the first step, the settings a tile after the steps
    check('fanboy overview: the hints under the setup\'s first step; the settings (Dashboard, watching) a tile of the setup',
        substr_count($js, 'box.appendChild(hintsBlock());') === 2 && !str_contains($js, 'function hintsSection(')
        && str_contains($js, "{ id: 'settings', key: 'settings.title', place: 'setup.tile.settings', tile: settingsTile, section: () => settingsSection() }")
        && str_contains($js, "wants: (sub) => STEPS.includes(sub) || sub === 'settings',") && str_contains($js, 's.append(dashChoice(), watchChoice());'));
    // the search: every place where it is now; places.json the same keys as Office.places()
    $pl = $at('Office.places(ID, [', 4000);
    preg_match_all("/key: '([a-z0-9_.]+)'/", $pl, $keys);
    preg_match_all("/text: '([a-z0-9_.]+)'/", $pl, $texts);
    $want = array_values(array_unique([...$keys[1], 'tile.now', 'tile.history', 'tile.sensors', ...array_map(fn ($k) => "step.$k", ['detect', 'test', 'measure', 'zones', 'take'])]));
    sort($want);
    $tx = array_values(array_unique($texts[1]));
    sort($tx);
    same('fanboy overview: places.json lists the keys Office.places() names (and the tiles\')', [$want, $tx], [$places['keys'] ?? null, $places['texts'] ?? null]);
    same('fanboy overview: no place on an old route', 0, preg_match('~#/\$\{ID\}/(who|today|fans|hottest|hints)`~', $js));
    check('fanboy overview: the hints\' and the settings\' places open the setup',
        str_contains($pl, "{ kind: 'section', key: 'hints.title', ...SETUP_ROUTE, route: `#/\${ID}/setup/detect` }")
        && str_contains($pl, "{ kind: 'setting', key: 'dash.setting', ...SETUP_ROUTE, route: `#/\${ID}/setup/settings` }")
        && str_contains($pl, "{ kind: 'setting', key: 'watch.setting', ...SETUP_ROUTE, route: `#/\${ID}/setup/settings` }")
        && str_contains($pl, "{ kind: 'help', key: 'help.show', text: 'help.show_text', ...SETUP_ROUTE },"));
    // words: the old tiles' names gone in all five, the new ones there
    same('fanboy overview: the old tiles\' words gone, the new ones in all five', [[], []], [
        array_values(array_filter(['tile.who', 'tile.today', 'tile.fans', 'tile.hottest', 'tile.hints', 'server.title'], fn ($k) => isset($l['en'][$k]) || isset($l['de'][$k]))),
        array_values(array_filter(['tile.now', 'tile.history', 'tile.sensors', 'now.title', 'settings.title', 'now.not_set_up', 'now.not_armed'], fn ($k) => count(array_filter($l, fn ($x) => isset($x[$k]))) !== 5))]);
    same('fanboy overview: German — «Aktuell», «Verlauf», «Fühler», «Einstellungen»', ['Aktuell', 'Verlauf', 'Fühler', 'Einstellungen'],
        [$l['de']['tile.now'], $l['de']['tile.history'], $l['de']['tile.sensors'], $l['de']['settings.title']]);
    // «History»'s marker: one moment for every zone's chart, by the pointer, a tap or the arrow keys; its readout above the
    // chart, each number marked with the history point it comes from (tools/fanboy/desk.mjs compares them with the part)
    $chart = $fn('historyChart');
    check('fanboy overview: the marker — one moment for all zones (markAll), the chart under the pointer exactly at its point, the others at theirs nearest to it',
        str_contains($js, 'function markAll(t) {') && str_contains($js, 'charts.forEach((c) => c.show(t));') && str_contains($chart, 'markAll(i < 0 ? null : shown[i][0]);')
        && str_contains($chart, "at = p && Math.abs(p[0] - t) <= MARK_NEAR(p, now, full) ? i : -1;"));
    check('fanboy overview: … pointer (mouse: gone when it leaves), touch (a tap beside the charts lets go), keyboard (arrows, Page, Home/End, Escape)',
        str_contains($chart, "s.addEventListener('pointermove', fromPointer);") && str_contains($chart, "if (e.pointerType === 'mouse') markAll(null);")
        && str_contains($js, "if (markAt !== null && !(e.target.closest && e.target.closest('.fb-hist-chart'))) markAll(null);")
        && str_contains($chart, "tabindex: '0'") && str_contains($chart, "{ ArrowLeft: -1, ArrowRight: 1, PageUp: -10, PageDown: 10 }[e.key]") && str_contains($chart, "if (e.key === 'Escape') { markAll(null); return; }"));
    check('fanboy overview: … the readout above the chart, never on it; its numbers marked from the history point; the source that led by name, «warmest spot» without one',
        str_contains($chart, 'box.append(read, s);') && str_contains($chart, "from: ['@hist', 'zones', zid, q[5], k]") && str_contains($chart, 'if (q[4]) read.dataset.lead = q[4];')
        && str_contains($js, "return x ? T('hist.lead', { where: srcLabel(x), c: degrees(c) }) : T('hist.lead_none', { c: degrees(c) });")
        && str_contains($js, "const leads = h && h.leads && Array.isArray(h.leads[zid]) && h.leads[zid].length === pts.length ? h.leads[zid] : null;"));
    check('fanboy overview: … no marker: the chart as it is with a line saying how; another period: none marked',
        str_contains($chart, "read.textContent = t === null ? T('hist.read_hint') : T('hist.read_none', { time: fmt.time(t) });") && str_contains($js, "markAt = null;                // another period: no moment marked"));
    // the Dashboard tile's links: the overview's tile «Now»
    $dash = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    check('fanboy overview: the Dashboard tile links «Now», never an old route', substr_count($dash, "\$h(\$url . '/now')") === 2 && !preg_match("~'/(who|fans|today|hottest|hints)'~", $dash));
}

/**
 * 2026-11-17 (fanboy-overview): a history point says which source led — the fifth value in RAM [time, °C, level, rpm,
 * source id]; a 5-minute span keeps the leader of its warmest moment; the files and the page's part keep points of four
 * (every older reader takes them) with the leaders beside them (`src` once each, `leads` per zone a point); bounded as
 * before — 3 days of three zones with long source ids, the leaders cost a small part of the file
 */
function testFanboyHistoryLeads(): void
{
    $w0 = 1763100000;
    $old = $w0 - 20 * 3600;                         // in the 5-minute spans
    $k = intdiv($old, 300) * 300;
    $pts = [[$k + 10, 40.2, 30, 800, 'parity'], [$k + 70, 44.5, 20, 700, 'disk2'], [$k + 130, 42.5, 50, 900, 'disk1'],
            [$k + 310, 41.3, 30, 800, 'disk1'], [$k + 370, 41.3, 31, 810, 'parity'],             // a tie: the later one's
            [$k + 610, 39.2, 30, 800], [$k + 670, 39.5, 30, 800, 'disk3'],                     // no leader, then one
            [$w0 - 60, 41.5, 57, 1018, 'disk2'], [$w0, 41.25, 57, 1018]];                      // minutes as they are
    $kept = fanboyHistoryThin($pts, $w0);
    same('fanboy history leads: a span keeps the leader of its warmest moment (a tie: the later one), its peaks as before; minutes as they are, with or without one',
        [[$k + 130, 44.5, 50, 900, 'disk2'], [$k + 370, 41.3, 31, 810, 'parity'], [$k + 670, 39.5, 30, 800, 'disk3'], [$w0 - 60, 41.5, 57, 1018, 'disk2'], [$w0, 41.25, 57, 1018]], $kept);
    same('fanboy history leads: thinned once more — nothing changes', $kept, fanboyHistoryThin($kept, $w0));
    same('fanboy history leads: a fifth that is no source id (a number, empty, a control character, too long) — the point left out', [[$w0, 41.0, 57, 1018, 'disk2']],
        fanboyHistoryThin([[$w0, 41.0, 57, 1018, 7], [$w0, 41.0, 57, 1018, ''], [$w0, 41.0, 57, 1018, "disk\n2"], [$w0, 41.0, 57, 1018, str_repeat('x', 201)], [$w0, 41.0, 57, 1018, 'disk2']], $w0));
    // the shape on file: points of four, the leaders beside them — and back
    $hist = ['disks' => $kept, 'case' => [[$w0, 27.9, 25, 905]]];
    $pack = fanboyHistoryPack($hist);
    same('fanboy history leads: packed — every point of four (an older reader\'s shape), each id once, an entry a point (null: none), a zone without a leader without the list',
        [[4], ['disk2', 'parity', 'disk3'], [0, 1, 2, 0, null], false],
        [array_values(array_unique(array_map('count', [...$pack['zones']['disks'], ...$pack['zones']['case']]))), $pack['src'], $pack['leads']['disks'], isset($pack['leads']['case'])]);
    same('fanboy history leads: unpacked — the same points', $hist, fanboyHistoryUnpack(json_decode(json_encode($pack), true)));
    $bad = json_decode(json_encode($pack), true);
    $bad['leads']['disks'] = [0, 1];                                   // not an entry a point
    $bad2 = json_decode(json_encode($pack), true);
    $bad2['leads']['disks'] = [9, 'x', 1, 0, null];                     // an index out of `src`, a word
    same('fanboy history leads: leaders that don\'t fit the points exactly — the points without them; an index not in `src` or not a number — that point without one',
        [array_map(fn ($p) => array_slice($p, 0, 4), $kept), [array_slice($kept[0], 0, 4), array_slice($kept[1], 0, 4), [...array_slice($kept[2], 0, 4), 'parity'], [...array_slice($kept[3], 0, 4), 'disk2'], $kept[4]]],
        [fanboyHistoryUnpack($bad)['disks'], fanboyHistoryUnpack($bad2)['disks']]);
    $t = fanboyT('historyleads');
    try {
        // the control: the warmest's id with every point; no warmest (all asleep): none
        $c = fanboyControlNew(fanboyTSettings(), FANBOY_T_KEY, fanboyMe('control'));
        $zids = array_keys($c['zones']);
        $c['zones'][$zids[0]]['warmest'] = ['id' => 'disk2', 'label' => 'disk2', 'c' => 41.5, 'kind' => 'disk'];
        $c['zones'][$zids[1]]['warmest'] = null;
        fanboyHistory($c, 0.0, $w0);
        same('fanboy history leads: the control — the warmest\'s id the fifth value; a zone with nothing warm: four', ['disk2', 4],
            [end($c['hist'][$zids[0]])[4] ?? null, count(end($c['hist'][$zids[1]]))]);
        // kept and read back: the file in the older shape plus the two keys; RAM gone, the data folder's copy the same
        @mkdir(fanboyRunDir(), 0700, true);
        fanboyHistoryKeep($c, true);
        $file = json_decode((string) file_get_contents(FANBOY_DIR . '/history.json'), true);
        same('fanboy history leads: on file — points of four, `src`, `leads`; read back the same', [[4], ['disk2'], true],
            [array_values(array_unique(array_map('count', array_merge(...array_values($file['zones']))))), $file['src'], $c['hist'] == fanboyHistoryLoad(FANBOY_T_KEY, $w0)]);
        $part = fanboyHistoryPart(1, $w0);
        same('fanboy history leads: the page\'s part — the same packing', [[$w0, 41.5, null, null], ['disk2'], [0]],
            [$part['zones'][$zids[0]][0] ?? null, $part['src'], $part['leads'][$zids[0]] ?? null]);
        // the size: 3 days, three zones, the leader changing among 30 long ids — bounded as before, the leaders a small part
        $ids = array_map(fn ($i) => "it8696_a0060607@platform/it87.2608/very/long/device/path#$i", range(1, 30));
        $c['zones'] = array_slice($c['zones'], 0, 1, true) + ['z2' => $c['zones'][$zids[0]], 'z3' => $c['zones'][$zids[0]]];
        $c['zones']['z2']['id'] = 'z2';
        $c['zones']['z3']['id'] = 'z3';
        $c['hist'] = [];
        $c['last_hist'] = null;
        foreach (array_keys($c['zones']) as $zid) {
            $c['zones'][$zid]['fans'] = [];
        }
        for ($m = 0; $m <= 72 * 60; $m++) {
            foreach (array_keys($c['zones']) as $n => $zid) {
                $c['zones'][$zid]['warmest'] = ['id' => $ids[intdiv($m, 37 + $n) % 30], 'c' => 30.0 + ($m % 97) / 10];
            }
            fanboyHistory($c, $m * 60.0, $w0 + $m * 60);
        }
        $with = strlen(jsonEncode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($c['hist'])));
        $plain = strlen(jsonEncode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY, 'zones' => array_map(fn ($z) => array_map(fn ($p) => array_slice($p, 0, 4), $z), $c['hist'])]));
        same('fanboy history leads: 3 days × 3 zones — each zone within the bound, the file under 200 KB, the leaders under a fifth of it', [true, true, true],
            [max(array_map('count', $c['hist'])) <= FANBOY_HISTORY_MAX, $with < 200000, ($with - $plain) < $with / 5], "with leaders $with bytes, without $plain");
    } finally {
        fanboyTEnd();
    }
}

/**
 * 2026-11-20 (the owner: a zone of several curves — «how close was the second one?»): a zone of two or more curves
 * records where each curve stood (its kind's warmest source, whole °C), a row per 5-minute span with each curve's peak,
 * 3 days at most — beside the points: the points, `src` and `leads` stay exactly as before (an older reader loses nothing),
 * `curves` is a key of its own; a zone of one curve records nothing new. An older file is read as before. The Dashboard
 * tile's reader ignores the key. The worst case the setup allows (8 zones × 8 kinds, 3 days, long source ids) is measured.
 */
function testFanboyHistoryCurves(): void
{
    $w0 = intdiv(1763100000, 300) * 300;
    $t = fanboyT('historycurves');
    try {
        $c = fanboyControlNew(fanboyTSettings(), FANBOY_T_KEY, fanboyMe('control'));
        same('fanboy history curves: the test board — «disks» one curve (disk), «case» two (nvme, ram)', [['disk'], ['nvme', 'ram']],
            [array_keys(fanboyZoneKindCurves($c['zones']['disks'])), array_keys(fanboyZoneKindCurves($c['zones']['case']))]);
        $at = function (float $nvme, ?float $ram, float $disk) use (&$c): void {
            $c['zones']['case']['by_kind'] = ['nvme' => ['source' => FANBOY_T_NVME, 'c' => $nvme]] + ($ram === null ? [] : ['ram' => ['source' => 'ram1', 'c' => $ram]]);
            $c['zones']['case']['warmest'] = ['id' => FANBOY_T_NVME, 'c' => $nvme];
            $c['zones']['disks']['by_kind'] = ['disk' => ['source' => 'disk2', 'c' => $disk]];
            $c['zones']['disks']['warmest'] = ['id' => 'disk2', 'c' => $disk];
        };
        // two spans: 41.4 / 44.6 / 43 in the first (the peak, rounded), RAM asleep in the second
        foreach ([[0, 41.4, 30.2], [60, 44.6, 31.0], [120, 43.0, 29.4], [300, 40.0, null], [360, 39.0, null]] as [$dt, $nv, $ram]) {
            $at($nv, $ram, 35.0);
            fanboyHistory($c, (float) $dt, $w0 + $dt);
        }
        same('fanboy history curves: two curves — a row per 5 minutes, each curve\'s peak in whole °C, null where its sources slept; one curve: nothing new',
            [['case'], ['nvme', 'ram'], [$w0 => [45, 31], $w0 + 300 => [40, null]]],
            [array_keys($c['curves']), $c['curves']['case']['kinds'], $c['curves']['case']['rows']]);
        same('fanboy history curves: the points as before (a point a minute, the leader the fifth)', [5, [$w0 + 360, 39.0, null, null, FANBOY_T_NVME]],
            [count($c['hist']['case']), end($c['hist']['case'])]);
        // several curves: a kind added later goes to the end, earlier rows keep their order
        $c['zones']['case']['sources'][] = ['kind' => 'cpu', 'id' => 'coretemp@x#1'];
        $c['zones']['case']['by_kind']['cpu'] = ['source' => 'coretemp@x#1', 'c' => 61.0];
        $c['zones']['case']['by_kind']['ram'] = ['source' => 'ram1', 'c' => 33.0];
        fanboyHistory($c, 600.0, $w0 + 600);
        same('fanboy history curves: a third curve added — at the end; the earlier rows as they were', [['nvme', 'ram', 'cpu'], [45, 31], [39, 33, 61]],
            [$c['curves']['case']['kinds'], $c['curves']['case']['rows'][$w0], $c['curves']['case']['rows'][$w0 + 600]]);

        // the file: points, src and leads exactly as without the new key (an older reader's view), `curves` beside them
        $with = fanboyHistoryPack($c['hist'], $c['curves']);
        $without = fanboyHistoryPack($c['hist']);
        same('fanboy history curves: packed — zones, src, leads the same as without; the curves a key of their own (kinds, the span, its start, a row per span)',
            [$without, ['kinds' => ['nvme', 'ram', 'cpu'], 'every' => FANBOY_HISTORY_CURVE, 't0' => $w0, 'v' => [[45, 31], [40, null], [39, 33, 61]]]],
            [array_diff_key($with, ['curves' => 1]), $with['curves']['case']]);
        $back = fanboyHistoryCurvesUnpack(json_decode(jsonEncode($with), true), $w0 + 600);
        same('fanboy history curves: read back the same', $c['curves'], $back);
        same('fanboy history curves: no zone of several curves — the file without the key (as before)', false, isset(fanboyHistoryPack($c['hist'], [])['curves']));
        // only in exactly its shape
        $j = json_decode(jsonEncode($with), true);
        $bad = [];
        foreach (['kind' => ['kinds' => ['nvme', 'fan']], 'twice' => ['kinds' => ['nvme', 'nvme']], 'every' => ['every' => 60], 't0' => ['t0' => $w0 + 1],
                  'row' => ['v' => [[45, 31, 1, 2]]], 'word' => ['v' => [[45, '31']]], 'float' => ['v' => [[45.5, 31]]], 'many' => ['v' => array_fill(0, FANBOY_HISTORY_CURVE_MAX + 1, null)]] as $what => $change) {
            $x = $j;
            $x['curves']['case'] = $change + $x['curves']['case'];
            if (fanboyHistoryCurvesUnpack($x, $w0 + 600) !== []) {
                $bad[] = $what;
            }
        }
        same('fanboy history curves: a zone\'s line not in exactly its shape (an unknown or doubled kind, another span, a start off the span, a row too long, a word, a fraction, too many rows) — left out', [], $bad);
        same('fanboy history curves: rows older than 3 days — left out', [], fanboyHistoryCurvesUnpack($j, $w0 + 600 + FANBOY_HISTORY_SPAN + 300));

        // kept and loaded: RAM, then the data folder's copy; an older office's file (no key) read as before
        @mkdir(fanboyRunDir(), 0700, true);
        fanboyHistoryKeep($c, true);
        $loaded = fanboyHistoryLoad(FANBOY_T_KEY, $w0 + 600, $curves);
        same('fanboy history curves: kept and loaded — the points as before (35.0 comes back as 35: compared as numbers), the curves back', [true, $c['curves']], [$loaded == $c['hist'], $curves]);
        $file = json_decode((string) file_get_contents(FANBOY_DIR . '/history.json'), true);
        unset($file['curves']);
        file_put_contents(FANBOY_DIR . '/history.json', json_encode($file));
        @unlink(fanboyRunDir() . '/history.json');
        same('fanboy history curves: an older office\'s file (no key) — the points as before, no curves (they begin with the update)', [true, []],
            [fanboyHistoryLoad(FANBOY_T_KEY, $w0 + 600, $curves) == $c['hist'], $curves]);
        fanboyHistoryKeep($c, true);

        // the page's part: the curves that reach into the period, beside the points
        $part = fanboyHistoryPart(1, $w0 + 600);
        same('fanboy history curves: the page\'s part — the curves beside the points', [['nvme', 'ram', 'cpu'], 3], [$part['curves']['case']['kinds'] ?? null, count($part['curves']['case']['v'] ?? [])]);

        // a settings reload keeps them
        $owner = $c['owner'];
        fanboyControlReload($c, fanboyTSettings(), FANBOY_T_KEY, $owner);
        same('fanboy history curves: a settings save keeps them', ['nvme', 'ram', 'cpu'], $c['curves']['case']['kinds'] ?? null);

        // the Dashboard tile's reader (src/dashboard-fanboy.php, another worker's file): the same with the key as without
        $dash = function (array $file) use ($t): string {
            $dir = "{$t['tmp']}/dashrun";
            @mkdir("$dir/fanboy", 0700, true);
            file_put_contents("$dir/fanboy/history.json", json_encode($file));
            $code = 'define("OFFICE_IN_UNRAID", true); require ' . var_export(OFFICE_DIR . '/src/bootstrap.php', true) . '; require_once ' . var_export(OFFICE_DIR . '/src/page.php', true) . ';'
                . ' require_once ' . var_export(OFFICE_DIR . '/src/dashboard.php', true) . '; require_once ' . var_export(OFFICE_DIR . '/src/dashboard-fanboy.php', true) . ';'
                . ' echo json_encode(officeFanboyDashHistory(' . var_export(FANBOY_T_KEY, true) . ', ' . ($GLOBALS['fbcNow'] ?? 0) . ', 24));';
            $p = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
                ['OFFICE_RUN_DIR' => $dir, 'OFFICE_DATA_DIR' => "{$t['tmp']}/no-data", 'PATH' => getenv('PATH')]);
            $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            proc_close($p);
            return $out;
        };
        $GLOBALS['fbcNow'] = $w0 + 600;
        $f = json_decode((string) file_get_contents(fanboyRunDir() . '/history.json'), true);
        $plain = $f;
        unset($plain['curves']);
        $a = $dash($f);
        same('fanboy history curves: the Dashboard tile\'s reader — the same with the curves as without (it keeps the leader\'s line)', [true, $dash($plain)],
            [isset($f['curves']) && str_starts_with($a, '{'), $a]);
        unset($GLOBALS['fbcNow']);

        // the size (the owner: «does it fill /run with very many fans and curves?»): the worst case the setup allows —
        // FANBOY_ZONES_MAX zones, each with every kind (8 curves), 3 days, the leader changing among 64 long source ids
        $kinds = array_keys(FANBOY_KIND_BOUNDS);
        $ids = array_map(fn ($i) => "it8696_a0060607@platform/it87.2608/very/long/device/path#$i", range(1, 64));
        $zone = $c['zones']['case'];
        $zone['fans'] = [];
        $zone['sources'] = array_map(fn ($k) => ['kind' => $k, 'id' => "$k-1"], $kinds);
        $c['zones'] = [];
        for ($z = 1; $z <= FANBOY_ZONES_MAX; $z++) {
            $c['zones']["zone-$z"] = ['id' => "zone-$z"] + $zone;
        }
        [$c['hist'], $c['curves'], $c['last_hist']] = [[], [], null];
        for ($m = 0; $m <= 72 * 60 + 30; $m++) {
            foreach (array_keys($c['zones']) as $n => $zid) {
                $c['zones'][$zid]['warmest'] = ['id' => $ids[intdiv($m, 37 + $n) % 64], 'c' => 30.0 + ($m % 97) / 10];
                $c['zones'][$zid]['by_kind'] = array_combine($kinds, array_map(fn ($i) => ['c' => 100.0 + $i - ($m % 70)], array_keys($kinds)));
            }
            fanboyHistory($c, $m * 60.0, $w0 + $m * 60);
        }
        $base = strlen(jsonEncode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($c['hist'])));
        $all = strlen(jsonEncode(['v' => 1, 'board' => FANBOY_T_KEY, 'every' => FANBOY_HISTORY_EVERY] + fanboyHistoryPack($c['hist'], $c['curves'])));
        $rows = max(array_map(fn ($h) => count($h['rows']), $c['curves']));
        same('fanboy history curves: the worst case — 8 zones × 8 curves × 3 days: rows bounded, the file under 600 KB (the fan desk\'s RAM line is 1 MB), the leader\'s line as before',
            [true, true, true], [$rows <= FANBOY_HISTORY_CURVE_MAX, $all < 600 * 1024, max(array_map('count', $c['hist'])) <= FANBOY_HISTORY_MAX],
            "points and leaders $base bytes, with the curves $all bytes, rows per zone $rows");
        fwrite(STDERR, "fanboy history curves: worst case — points and leaders $base bytes, with the curves $all bytes, rows per zone $rows\n");
    } finally {
        fanboyTEnd();
    }
}

/**
 * The zone editor's heat sources (2026-11-20, the owner: about 70 on his server pushed the curve far down): a block per
 * kind, folded to one line — how many, the warmest, how many sleep, the curve it follows —, unfolding in place; the
 * behaviour in a browser: tools/fanboy/zones.mjs (the demo state fanboy-uxmany)
 */
function testFanboyZoneSources(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $start = (int) strpos($js, 'function zoneSources(z, i) {');
    $fn = substr($js, $start, (int) strpos($js, "\n}\n", $start) - $start);
    check('fanboy zone sources: one function, called between the fans and the curve', $start > 0
        && str_contains($js, "card.appendChild(para(T('zones.sources_sub')));\n  card.appendChild(zoneSources(z, i));\n  card.appendChild(curvePart(z, i));"), '');
    check('fanboy zone sources: a block per kind (the unassigned disks a group of their own), the line a button that unfolds its chips in place — aria-expanded, aria-controls, the page kept in place',
        str_contains($fn, "[...kinds.slice(0, 2), 'unassigned', ...kinds.slice(2)].forEach((k) => {") && str_contains($fn, "tog.setAttribute('aria-controls', chips.id);")
        && str_contains($fn, "tog.setAttribute('aria-expanded', open ? 'true' : 'false');") && str_contains($fn, 'tog.onclick = () => Office.keepInPlace(tog, () => {'), $fn);
    check('fanboy zone sources: folded or open is the user\'s view of the page (the folds kept while the page lives) — never stored, never sent',
        str_contains($fn, 'const key = `src:${z.id}:${k}`;') && str_contains($fn, 'folds.set(key, now);') && !str_contains($fn, 'Office.store') && !str_contains($fn, 'localStorage') && !str_contains($fn, 'Office.api'), $fn);
    check('fanboy zone sources: a zone without any source — all open (kept open while the first are chosen), the line that it needs one',
        str_contains($fn, 'if (none && !folds.has(key)) folds.set(key, true);') && str_contains($fn, "if (none) box.appendChild(para(T('zones.sources_first'),"), $fn);
    check('fanboy zone sources: the summary — how many chosen, the warmest only among those with a temperature (a sleeping disk never), how many sleep; none chosen: how many there are',
        str_contains($fn, "const warm = chosen.filter((s) => num(s.c) !== null).sort((a, b) => b.c - a.c)[0] || null;")
        && str_contains($fn, "const asleep = chosen.filter((s) => num(s.c) === null && s.asleep).length;")
        && str_contains($fn, "T('zones.src_none', { n: list.length })") && str_contains($fn, "T('zones.src_chosen', { n: chosen.length })")
        && str_contains($fn, "warm ? T('zones.src_some_asleep', { n: asleep }) : T('zones.src_all_asleep', { n: asleep })"), $fn);
    check('fanboy zone sources: the warmest\'s temperature marked for the number check (tools/fanboy/desk.mjs --nums)',
        str_contains($fn, "sum.dataset.nums = JSON.stringify([{ v: warm.c, from: ['setup', 'sources', `=\${warm.id}`, 'c'] }]);"), $fn);
    check('fanboy zone sources: the goal a kind has in the curve cards\' words (2026-11-20: Leise, Normal, Kühl, Eigene Kurve); several kinds: a button bringing that kind\'s card in front — no aria-expanded (it opens nothing)',
        str_contains($fn, "choiceName(kindChoice(z, c))") && str_contains($fn, 'kindSel.set(z.id, ck[0]);')
        && str_contains($fn, ".fb-kindpick[data-kind=\"\${ck[0]}\"]") && str_contains($js, 'box.dataset.zone = z.id;') && substr_count($fn, 'aria-expanded') === 2 && !str_contains($fn, "go.setAttribute('aria-expanded'")
        && str_contains($fn, "b.scrollIntoView({ block: 'center' });     /* reveal */"), $fn);
    $en = langFile(OFFICE_WEB . '/desks/fanboy/lang/en.json');
    $keys = ['zones.sources_first', 'zones.src_none', 'zones.src_chosen', 'zones.src_some_asleep', 'zones.src_all_asleep', 'zones.src_no_temp', 'zones.src_change', 'zones.src_hide'];
    same('fanboy zone sources: its words (five languages: testStrings), the counts plurals', [true, true, true, true],
        [array_diff($keys, array_keys($en)) === [], is_array($en['zones.src_none'] ?? null), is_array($en['zones.src_chosen'] ?? null), is_array($en['zones.src_all_asleep'] ?? null)]);
    $demo = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-uxmany.json'), true);
    same('fanboy zone sources: the demo of a big server — about 70 heat sources, the disk cage 28 of them, a zone with nothing yet, RAM on a curve of its own',
        [69, [28, 2, 0], ['points' => [[40, 25], [55, 60], [65, 100]]]],
        [count($demo['setup']['sources'] ?? []), array_map(fn ($z) => count($z['sources']), $demo['setup']['zones'] ?? []), $demo['setup']['zones'][1]['curves']['ram'] ?? null]);
}

/**
 * A CPU cooler given to him (round 4, `cpu_own`) is his on every picture of his page (2026-11-20, the owner: «the tile is
 * right, his page is wrong»): drawn solid with «Fanboy runs this fan», never the BIOS's dotted frame or «The BIOS steers
 * it»; the outputs' list never calls it «watched». The other CPU coolers and pumps stay the BIOS's.
 */
function testFanboyCpuOwnWheel(): void
{
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $start = (int) strpos($js, 'function wheelOf(id, opts) {');
    $fn = substr($js, $start, (int) strpos($js, "\n}\n", $start) - $start);
    check('fanboy cpu own wheel: wheelOf() — a fan of the BIOS is one he doesn\'t hold or one of the BIOS\'s roles that isn\'t his (isOurs: a CPU cooler given to him is)',
        str_contains($fn, 'const watched = ROLES_BIOS.includes(f.role) && !isOurs(f);') && str_contains($fn, 'const bios = !held || watched;')
        && str_contains($fn, ": watched ? T('pic.watched_title') : T('pic.bios_title')) : T('pic.his_title'),") && !str_contains($fn, '!held || ROLES_BIOS.includes(f.role)'), $fn);
    check('fanboy cpu own wheel: the outputs\' list — «watched» only for the BIOS\'s own', str_contains($js,
        "if (f.role && ROLES_BIOS.includes(f.role) && !isOurs(f)) return chipOfText('quiet', T(`role.\${f.role}`) + ' · ' + T('out.watched'), T('out.watched_title'));"), '');
    check('fanboy cpu own wheel: isOurs() — his roles, or a CPU cooler given to him', str_contains($js, "const isOurs = (f) => !!f && (ROLES_OURS.includes(f.role) || (f.role === 'cpu' && !!f.cpu_own));"), '');
    $demo = json_decode((string) file_get_contents(OFFICE_DIR . '/tests/ui/demo/fanboy-cpu.json'), true);
    $cpu = array_values(array_filter($demo['setup']['fans'] ?? [], fn ($f) => ($f['role'] ?? null) === 'cpu'))[0] ?? [];
    same('fanboy cpu own wheel: the demo that shows it (fanboy-cpu) — a CPU cooler given to him, held by his control', [true, true],
        [is_int($cpu['cpu_own'] ?? null), (bool) array_filter(array_merge(...array_column($demo['control']['zones'] ?? [], 'fans')), fn ($f) => ($f['id'] ?? '') === ($cpu['id'] ?? '-') && ($f['held'] ?? false) === true)]);
}

/**
 * 2026-11-20 (the owner's first set-up: «I was still setting up the CPU, why is he already at output 3?»): an output under
 * test stays at full until the user answers — no clock moves on to the next one. Unanswered it stops at its ceiling
 * (FANBOY_UP_HOLD; the quiet test FANBOY_UP_HOLD_QUIET) as `unanswered`: everything back, the next output never started,
 * what the looks saw kept; the page gone still ends it after the lease; a test's whole time grows with its outputs
 */
function testFanboySetupWaits(): void
{
    $t = fanboyT('waits');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        $run = function (bool $quiet, ?float $answerAfter, ?float $stopAt = null) use ($t, &$spec): array {
            $clock = 0.0;
            $full = [];
            $notes = [];
            $io = fanboyTIo($t, $spec, $clock, $stopAt, $answerAfter);
            $sleep = $io['sleep'];
            $io['sleep'] = function (float $s) use ($sleep, $t, &$full, &$clock): void {
                foreach ([1, 4] as $n) {
                    if (fanboyTOut($t, $n)[0] === 255) {
                        $full[$n][] = $clock;
                    }
                }
                $sleep($s);
            };
            $step = fanboyStepRun(['id' => 'w' . mt_rand(), 'kind' => 'test_up', 'fans' => [fanboyTId(1), fanboyTId(4)], 'quiet' => $quiet], $io);
            $span = array_map(fn ($c) => max($c) - min($c), $full);
            return [$step, $span, $clock, fanboyStepRead()];
        };
        // nobody answers, the switch off: output 1 at full up to the ceiling, then the step stops — output 4 never at full
        [$step, $span, $clock] = $run(false, null);
        same('fanboy waits: no answer — output 1 at full ' . round($span[1] ?? 0) . ' s (its ceiling ' . FANBOY_UP_HOLD . ' s), the step stopped as «unanswered», output 4 never started, its looks kept (tach 1)',
            [true, 'stopped', 'unanswered', false, [fanboyTId(1)], 'tach'],
            [($span[1] ?? 0) >= FANBOY_UP_HOLD - 1.0 && ($span[1] ?? 0) <= FANBOY_UP_HOLD + 1.0, $step['status'], $step['why'], isset($span[4]), $step['done'], $step['results'][fanboyTId(1)]['found'] ?? null]);
        same('fanboy waits: … every output exactly as before, nothing held', [$before, []], [array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // the quiet test: its own, shorter ceiling — the others stood meanwhile, all back
        [$step, $span] = $run(true, null);
        same('fanboy waits: the quiet test unanswered — output 1 at full ' . round($span[1] ?? 0) . ' s (its ceiling ' . FANBOY_UP_HOLD_QUIET . ' s), stopped as «unanswered», output 4 never started; everything back, nothing held',
            [true, 'stopped', 'unanswered', false, $before, []],
            [($span[1] ?? 0) >= FANBOY_UP_HOLD_QUIET - 1.0 && ($span[1] ?? 0) <= FANBOY_UP_HOLD_QUIET + 1.0, $step['status'], $step['why'], isset($span[4]),
                array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // he answers after 100 s (round 8's minute is long gone): until then output 1 is at full and output 4 waits
        [$step, $span] = $run(true, 100.0);
        same('fanboy waits: answered after 100 s — output 1 at full until then (' . round($span[1] ?? 0) . ' s), then output 4 (answered the same way), the test done',
            [true, true, 'done', 'tach', 'tach'],
            [($span[1] ?? 0) >= 99.0 && ($span[1] ?? 0) <= 101.0, isset($span[4]), $step['status'], $step['results'][fanboyTId(1)]['found'] ?? null, $step['results'][fanboyTId(4)]['found'] ?? null]);
        // the page gone (no step_alive): still ends it after the lease — everything back
        [$step, $span] = $run(true, null, 50.0);
        same('fanboy waits: the page gone at 50 s — stopped as «page_gone», output 4 never started, everything back',
            ['stopped', 'page_gone', false, $before, []], [$step['status'], $step['why'], isset($span[4]), array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        // what the page reads: the output waits for the answer, `until` its ceiling
        $notes = [];
        $clock = 0.0;
        $io = fanboyTIo($t, $spec, $clock, null, 20.0);
        $o = fanboyOutputs(fanboyDetect(fanboySysRoot() ?: null))[fanboyTId(1)];
        $io['note'] = function (array $p) use (&$notes): void { $notes[] = $p; };
        fanboyTestUp($o, fanboyMe('step'), $io, fanboyBoardKey(fanboyBoard(fanboyPaths(fanboySysRoot() ?: null)['dmi'])), true);
        $full = array_values(array_filter($notes, fn ($p) => ($p['phase'] ?? null) === 'full'))[0] ?? [];
        same('fanboy waits: the step says it — phase full, `waits`, `until` the quiet ceiling from now', ['full', true, true, true],
            [$full['phase'] ?? null, $full['waits'] ?? null, $full['quiet'] ?? null, abs(($full['until'] ?? 0) - time() - FANBOY_UP_HOLD_QUIET) <= 3]);
        fanboyGiveBack(null, 'step', fanboyMe('step'));
        same('fanboy waits: a test\'s whole time — per output its ceiling and ' . FANBOY_UP_STEP_EXTRA . ' s, never under ' . FANBOY_STEP_MAX . ' s; a measuring per fan its own bound (2026-11-20)',
            [6 * (FANBOY_UP_HOLD_QUIET + FANBOY_UP_STEP_EXTRA), 6 * (FANBOY_UP_HOLD + FANBOY_UP_STEP_EXTRA), FANBOY_STEP_MAX, 6 * (fanboyMeasureMaxSecs() + FANBOY_UP_STEP_EXTRA)],
            [fanboyStepMax(['kind' => 'test_up', 'quiet' => true, 'fans' => range(1, 6)]), fanboyStepMax(['kind' => 'test_up', 'fans' => range(1, 6)]),
                fanboyStepMax(['kind' => 'test_up', 'quiet' => true, 'fans' => [1]]), fanboyStepMax(['kind' => 'measure', 'fans' => range(1, 6)])]);
        same('fanboy waits: the ceilings — 10 minutes, 3 in the quiet test; the state tells the page', [600.0, 180.0, 600, 180],
            [FANBOY_UP_HOLD, FANBOY_UP_HOLD_QUIET, fanboyTimes()['up_hold'], fanboyTimes()['up_hold_quiet']]);
        // the page: it says the output waits for the answer and when it pauses; the panel he chose stays his (it follows a
        // run only from the run's own row), the running one a click away; after a pause «Go on testing»; what he is in
        // stays where it is on the screen (tools/fanboy/setup-live.mjs clicks it through)
        $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
        $l = ['en' => json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/lang/en.json'), true)];
        check('fanboy waits: the page — `waits` read, the strip\'s clock counts to the pause, the follow only from the run\'s own row, the running output a link away, «Go on testing» after a pause, the focused node kept in place',
            str_contains($js, "r.waits = phase === 'full' && !!p.waits;")
            && str_contains($js, "setText(since, run.waits && run.left !== null ? T('strip.waits_left', { time: clockText(run.left) })")
            && str_contains($js, "if (to && to !== followed && ids.includes(to) && !typing && (!followed || !sel || sel === followed)) sel = to;")
            && str_contains($js, "panel: (id, api) => panelMake(id, api.now, api.update, api.select),")
            && str_contains($js, "if (why === 'unanswered') { const tm = timesNow(); return T('step.ended.unanswered', { n: Math.round((s.quiet ? tm.up_hold_quiet : tm.up_hold) / 60) }); }")
            && str_contains($js, "if (rest.length) out.push(btn(T('test.resume'), 'btn', () => startTest([rest[0]]), T('test.one_tip')));")
            && str_contains($js, "if (view && a && a !== document.body && view.contains(a)) Office.keepInPlace(a, go); else go();")
            && str_contains($l['en']['panel.now'], 'until you answer') && is_file(OFFICE_DIR . '/tools/fanboy/setup-live.mjs'));
    } finally {
        fanboyTEnd();
    }
}

/**
 * 2026-11-20 (the owner: «“On the CPU” or “in the case” is missing» in «Wo sitzt er?»): two places on no wall — on the CPU,
 * inside the case — stored like the walls (additive: the walls keep their meaning); the page offers per role the places
 * that fit, the likeliest first (a CPU cooler: on the CPU); the server picture draws them among the heat sources, their
 * air going on to the rear, never deciding the zone's air. And the look (for the picture) on the setup tab: open, no fold.
 */
function testFanboySetupPlaces(): void
{
    $t = fanboyT('places');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $clock = 0.0;
        fanboyStepRun(['id' => 'pl1', 'kind' => 'test_up', 'fans' => [fanboyTId(1), fanboyTId(4)]], fanboyTIo($t, $spec, $clock, null, 10.0));
        fanboyRole(['fan' => fanboyTId(1), 'role' => 'cpu', 'name' => 'CPU', 'place' => 'cpu']);
        fanboyRole(['fan' => fanboyTId(4), 'role' => 'ram', 'name' => 'RAM', 'place' => 'inside']);
        $fans = fanboyBoardRaw()['fans'];
        same('fanboy places: «on the CPU» and «inside the case» stored like a wall', ['cpu', 'inside'], [$fans[fanboyTId(1)]['place'] ?? null, $fans[fanboyTId(4)]['place'] ?? null]);
        fanboyRole(['fan' => fanboyTId(4), 'role' => 'case', 'place' => 'rear']);
        same('fanboy places: … a wall again, as before', 'rear', fanboyBoardRaw()['fans'][fanboyTId(4)]['place'] ?? null);
        try {
            fanboyRole(['fan' => fanboyTId(4), 'role' => 'case', 'place' => 'middle']);
            $key = 'kept';
        } catch (Problem $p) {
            $key = $p->key;
        }
        same('fanboy places: a place nobody knows still refused', 'fanboy_bad_place', $key);
        same('fanboy places: the agent\'s places — the five walls as before, then the two on no wall', ['front', 'rear', 'top', 'bottom', 'side', 'cpu', 'inside'], FANBOY_PLACES);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $art = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/fan-art.js');
    $css = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.css');
    $l = [];
    foreach (['en', 'de', 'it', 'fr', 'es'] as $code) {
        $l[$code] = json_decode((string) file_get_contents(OFFICE_DIR . "/public/desks/fanboy/lang/$code.json"), true);
    }
    check('fanboy places: the page knows the same places as the agent and fan-art.js, each with its words in all five languages',
        str_contains($js, "const PLACES = ['front', 'rear', 'top', 'bottom', 'side', 'cpu', 'inside'];") && str_contains($art, "const PLACES = ['front', 'rear', 'top', 'bottom', 'side', 'cpu', 'inside'];")
        && count(array_filter($l, fn ($x) => is_string($x['place.cpu'] ?? null) && is_string($x['place.inside'] ?? null))) === 5
        && $l['de']['place.cpu'] === 'Auf der CPU' && $l['de']['place.inside'] === 'Im Gehäuse');
    check('fanboy places: per role the places that fit — a CPU cooler «on the CPU» first, a pump, RAM and NVMe fan «inside» first, the case and the disk cage a wall first, «on the CPU» never for them; a stored place stays offered; the options built anew only when they change, never under his hand',
        str_contains($js, "cpu: ['cpu', 'top', 'front', 'rear', 'inside', 'bottom', 'side'],") && str_contains($js, "pump: ['inside', 'top', 'front', 'rear', 'bottom', 'side'],")
        && str_contains($js, "case: ['front', 'rear', 'top', 'bottom', 'side', 'inside'],") && str_contains($js, "disks: ['front', 'side', 'bottom', 'top', 'rear', 'inside'],")
        && str_contains($js, "return stored && PLACES.includes(stored) && !list.includes(stored) ? [...list, stored] : list;")
        && str_contains($js, "if (document.activeElement === place) return;") && str_contains($js, "placeOptions(cur === 'empty' ? '' : cur, placeNow);"));
    check('fanboy places: the picture — a fan on the CPU or inside among the heat sources (on no wall), its air toward the rear, not counted for the zone\'s way',
        str_contains($art, "const INSIDE = ['cpu', 'inside'];") && str_contains($art, "if (INSIDE.includes(place)) continue;")
        && str_contains($art, "const side = inside ? wall.rear : wall[place];") && str_contains($art, "(inside ? inner : sides[side]).appendChild(w.el);")
        && str_contains($art, "if (inner.childElementCount) mid.appendChild(inner);"));
    // 2026-11-20 (the owner: ««Zeig mir» can go, «Testen» does exactly the same»): never both side by side
    check('fanboy show or test: a found fan\'s panel offers «test again» — «show me» only while he steers (a test refused until he hands back; the control lifts it and takes it back), then working, not set back; the help says so, the quiet test\'s help no longer speaks of showing',
        str_contains($js, "const showOnly = control().running && situation() !== 'other' && ['tach', 'silent'].includes(found);")
        && str_contains($js, "if (showOnly) out.push(btn(T('show.button'), 'btn plain', () => { expect({ now: T('strip.starting_show', { fan: fanSay(id) }), done: () => !!runNow().kind }); send('show', { fan: id, quiet: false }, { fresh: true }); }, T('show.tip')));\n        else out.push(btn(leftAlone(f) ? T('test.start_one') : T('test.again_long'), 'btn plain', () => startTest([id]), T('test.one_tip')));")
        && str_contains($js, "const blocked = !!run.kind || (!!noTest && !showOnly) || away;") && str_contains($js, "else if (!run.kind && noTest && !showOnly) reason = noTest;")
        && str_contains($l['de']['help.show_text'], 'Nur solange Fanboy regelt') && str_contains($l['en']['help.show_text'], '«Test again» does the same')
        && !str_contains($l['de']['help.quiet_text'], 'gezeigt'));
    check('fanboy look open: on the setup tab the look (maker, model, colours, blades) is a section shown open under its heading — no fold, no kept fold state; «give back to» stays a fold',
        str_contains($js, "const look = el('section', 'fb-look-open');") && str_contains($js, "const lookSum = el('h4', 'fb-q');")
        && !str_contains($js, "keepFold(el('details', 'fb-fold'), `look:\${id}`") && str_contains($js, "const back = keepFold(el('details', 'fb-fold'), `back:\${id}`, false);")
        && str_contains($css, '.fb-look-open{'));
}

/**
 * 2026-11-20 (the owner's two recordings: a horizontal scrollbar flashing on the set-up tab, the page's scrollbar
 * twitching on the overview): whatever fan-art.js turns or lets fly sits in a box of its own that clips it — the
 * wheel's turning square in .fan-spin, a wheel's streaks in .fan-air, a zone's in .fan-flow — so no frame of an
 * animation changes a page's size (tools/fanboy/frames.mjs and `dashboard.mjs --frames` check it in a browser)
 */
function testFanboyArtClipped(): void
{
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/fan-art.js');
    $css = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/fan-art.css');
    check('fanboy art clipped: the turning part in .fan-spin (square: a rounded clip repaints every frame), a wheel\'s and a zone\'s air clip their streaks',
        str_contains($js, "const spin = el('div', 'fan-spin');\n    spin.appendChild(rotor);\n    box.append(frame, spin, front, arc, badge);")
        && str_contains($css, '.fan-spin{position:absolute; inset:0; overflow:hidden; overflow:clip}') && !preg_match('/\.fan-spin\{[^}]*radius/', $css)
        && preg_match('/\.fan-air\{[^}]*overflow:hidden; overflow:clip/', $css) === 1 && preg_match('/\.fan-flow\{[^}]*overflow:hidden/', $css) === 1);
    $frames = (string) file_get_contents(OFFICE_DIR . '/tools/fanboy/frames.mjs');
    $dash = (string) file_get_contents(OFFICE_DIR . '/tools/fanboy/dashboard.mjs');
    check('fanboy art clipped: the browser checks — every animated part clipped inside the picture, the page\'s size steady over every moment and frame; the Dashboard tile likewise',
        str_contains($frames, 'export async function sizeChecks(page, secs = 3)') && str_contains($frames, "if (!c || !c.closest(art)) bad.add(")
        && str_contains($dash, "const { sizeChecks, sizeVerdicts } = await import(path.join(HERE, 'frames.mjs'));"));
}

/**
 * 2026-11-20 (the owner: «first test output 1, label it and so on, save at the end, not at every click; then on to output
 * two … there is a switch for stopping the others; that is how it must be»): step 2 one output after the other — the
 * switch on, every other output stands while one is tested, whatever its role (a CPU cooler and a pump too); off, none is
 * touched; the test of one output ends with its answer (every fan back); the details are stored only by ONE save
 * (fan_save) carrying every field — before it the output is «not yet set»; a refused save stores nothing; an output
 * saved before is changed again with one save; «nothing attached» and «I heard something» in the same request.
 */
function testFanboySetupFlow(): void
{
    $t = fanboyT('flow');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        $before = array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]);
        // outputs 4 and 5 already told: the CPU cooler and a pump
        fanboyRole(['fan' => fanboyTId(4), 'role' => 'cpu']);
        fanboyRole(['fan' => fanboyTId(5), 'role' => 'pump']);
        $watch = function (array &$low) use ($t): callable {
            return function () use ($t, &$low): void {
                if (fanboyTOut($t, 1)[0] === 255) {
                    foreach ([2, 3, 4, 5, 6] as $n) {
                        $low[$n][] = fanboyTOut($t, $n)[0];
                    }
                }
            };
        };
        $low = [];
        [$step] = fanboyR8Run($t, $spec, [1], true, $watch($low));
        same('fanboy flow: the switch on — while output 1 runs every other output stands (a fixed 0), the CPU cooler (4) and the pump (5) too; the test of one output done, every output back',
            [[2, 3, 4, 5, 6], 'done', [fanboyTId(1)], $before, []],
            [array_keys(array_filter($low, fn ($v) => in_array(0, $v, true))), $step['status'], $step['done'], array_map(fn ($n) => fanboyTOut($t, $n), [1, 2, 3, 4, 5, 6]), fanboyHeld()]);
        $low = [];
        fanboyR8Run($t, $spec, [1], false, $watch($low));
        same('fanboy flow: the switch off — no other output touched while output 1 runs', [], array_keys(array_filter($low, fn ($v) => array_unique($v) !== [128])));
        // the test's verdict is the step's; what it is, its name … only with the save
        $f = fanboyBoardRaw()['fans'][fanboyTId(1)] ?? [];
        same('fanboy flow: after the test, before the save — found (the step\'s verdict), nothing of his yet: no role, no name, no place («not yet set»)',
            ['tach', null, '', null], [$f['found'] ?? null, $f['role'] ?? null, $f['name'] ?? '', $f['place'] ?? null]);
        // ONE save with every field
        $r = fanboyFanSave(['fan' => fanboyTId(1), 'role' => 'case', 'name' => 'Vorn unten', 'place' => 'front', 'count' => 2,
            'look' => ['model' => 'noctua-nf-a12x25-pwm', 'frame' => 'black', 'wheel' => 'graphite']]);
        $f = fanboyBoardRaw()['fans'][fanboyTId(1)];
        same('fanboy flow: one save carries everything — what it is, its name, where it sits, how many, its look',
            [true, 'case', 'Vorn unten', 'front', 2, 'noctua-nf-a12x25-pwm', 'black', 'graphite'],
            [$r['saved'] ?? null, $f['role'] ?? null, $f['name'] ?? null, $f['place'] ?? null, $f['count'] ?? null, $f['model']['id'] ?? null, $f['frame'] ?? null, $f['wheel'] ?? null]);
        foreach ([['role' => 'boss'], ['role' => 'case', 'place' => 'middle'], ['role' => 'case', 'name' => str_repeat('x', 41)], ['role' => 'case', 'look' => 'black']] as $bad) {
            try {
                fanboyFanSave($bad + ['fan' => fanboyTId(1), 'name' => 'Neu']);
                $key = 'kept';
            } catch (Problem $p) {
                $key = $p->key;
            }
            $f = fanboyBoardRaw()['fans'][fanboyTId(1)];
            same('fanboy flow: a refused save (' . json_encode($bad) . ') stores nothing', [true, 'Vorn unten', 'case'], [$key !== 'kept', $f['name'] ?? null, $f['role'] ?? null]);
        }
        // reopened: changed again with one save; what it doesn't name (the look) stays
        fanboyFanSave(['fan' => fanboyTId(1), 'role' => 'disks', 'name' => 'Käfig', 'place' => 'side', 'count' => 1]);
        $f = fanboyBoardRaw()['fans'][fanboyTId(1)];
        same('fanboy flow: an output saved before, opened again — one save changes it, its look stays', ['disks', 'Käfig', 'side', 1, 'noctua-nf-a12x25-pwm'],
            [$f['role'] ?? null, $f['name'] ?? null, $f['place'] ?? null, $f['count'] ?? null, $f['model']['id'] ?? null]);
        // no tach followed (outputs 2 and 3): «nothing attached» and «heard something» each in the save
        fanboyR8Run($t, $spec, [2, 3], false);
        fanboyFanSave(['fan' => fanboyTId(2), 'role' => 'empty']);
        fanboyFanSave(['fan' => fanboyTId(3), 'role' => 'case', 'name' => 'Deckel']);
        $fans = fanboyBoardRaw()['fans'];
        same('fanboy flow: no tach followed — «nothing attached» saved as empty; a role saved means «I heard it» (a fan without feedback)',
            ['empty', 'silent', 'case', 'Deckel'], [$fans[fanboyTId(2)]['found'] ?? null, $fans[fanboyTId(3)]['found'] ?? null, $fans[fanboyTId(3)]['role'] ?? null, $fans[fanboyTId(3)]['name'] ?? null]);
        check('fanboy flow: the action is there', str_contains((string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy.php'), "'fan_save'      => fn (array \$r) => fanboyFanSave(\$r),"));
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $panel = substr($js, $at = (int) strpos($js, 'function panelMake('), (int) strpos($js, 'function cpuBlock(') - $at);
    $l = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/lang/de.json'), true);
    check('fanboy flow: the page — the panel sends nothing while he fills it in (role, name, place, count, alike, look go into its draft); «Speichern und weiter» sends it once and starts the next output\'s test on that click; a test starts for one output only',
        !str_contains($panel, "send('role'") && !str_contains($panel, "send('fan_look'") && !str_contains($js, ".fan_name`") && str_contains($panel, "send('fan_save', body).then((j) => {")
        && str_contains($panel, "if (nextId) testNext(nextId);") && str_contains($js, "const ids = list.slice(0, 1);")
        && str_contains($panel, "const b = btn(nextId ? T('save.next', { fan: fanSay(nextId) }) : T('save.only'), 'btn', () => save(nextId),")
        && str_contains($panel, "return nextId ? [btn(T('save.empty_next', { fan: fanSay(nextId) }), 'btn', () => testNext(nextId),")
        && $l['save.next'] === 'Speichern und weiter zu {fan}' && str_contains($l['quiet.hint_2'], 'auch der CPU-Kühler und eine Pumpe'));
}

/**
 * 2026-11-20 (the owner: «wenn nichts angeschlossen ist wäre anstelle des Lüfter-Icons vielleicht ein Fragezeichen oder
 * nichts»): an output with nothing attached is drawn as an empty connector — the dotted frame, no fan, no turning, no air
 * (fan-art.js state `empty`) — in the panel, its row and step 1's list; its panel drops the fan's fields and look, keeps
 * «Was ist das?»; a role chosen again brings them back, and saved it is a fan without feedback after all
 */
function testFanboyEmptyOutput(): void
{
    $t = fanboyT('emptyout');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR8Spec();
        fanboyTSettle($t, $spec);
        fanboyR8Run($t, $spec, [2], false);
        fanboyFanSave(['fan' => fanboyTId(2), 'role' => 'empty']);
        same('fanboy empty: output 2, no tach, «nothing attached» saved — found empty', 'empty', fanboyBoardRaw()['fans'][fanboyTId(2)]['found'] ?? null);
        fanboyFanSave(['fan' => fanboyTId(2), 'role' => 'case', 'name' => 'Hinten oben']);
        $f = fanboyBoardRaw()['fans'][fanboyTId(2)];
        same('fanboy empty: … he changed his mind — a case fan after all, saved as a fan without feedback, with its name', ['silent', 'case', 'Hinten oben'],
            [$f['found'] ?? null, $f['role'] ?? null, $f['name'] ?? null]);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $art = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/fan-art.js');
    $css = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/fan-art.css');
    check('fanboy empty: fan-art.js knows the empty connector — the dotted frame alone, nothing turns, no air',
        str_contains($art, "const STATES = ['run', 'stopped', 'unknown', 'bios', 'empty'];") && str_contains($art, "f.air === false || state === 'empty' ? null")
        && str_contains($art, "state === 'stopped' || state === 'unknown' || state === 'empty' ? 0") && str_contains($css, '.fan-s-empty .fan-box{outline:1.5px dotted var(--muted)')
        && str_contains($css, '.fan-s-empty .fan-frame, .fan-s-empty .fan-spin, .fan-s-empty .fan-front, .fan-s-empty .fan-arc{visibility:hidden}'));
    check('fanboy empty: the page draws it so — the panel and its row (wheelNow), step 1\'s list; the panel without the fan\'s fields and look while «nothing attached» is chosen, «Was ist das?» kept',
        str_contains($js, "if (f && f.found === 'empty' && run.id !== id) return { state: 'empty', rpm: 0, badge: '' };")
        && str_contains($js, "state: empty ? 'empty' : 'bios', mm: 120 }")
        && str_contains($js, "what.hidden = never;\n    fields.hidden = look.hidden = never || cur === 'empty';")
        && str_contains($js, "if (cur === 'empty') hint = T('panel.empty_note');"));
}

/**
 * 2026-11-20 (the owner: «bei Zonen dasselbe mit dem Haken klicken, Haken geht rein und wieder raus — ein Am-Ende-Speichern-
 * Button»): what the zones step says about a fan («darf stehen», «Es sind gleiche Lüfter») is part of the step's draft and
 * goes with «Speichern» in the SAME request as the zones (zones_save {zones, fans}); a bad entry refused before anything
 * is written; what an entry doesn't say stays as it is
 */
function testFanboyZonesDraft(): void
{
    $t = fanboyT('zonesdraft');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1,
              'stops' => true, 'floor_rpm' => null, 'start_from_stop' => 35, 'kick_start' => null, 'stops_safe' => true];
        fanboyR12Store([4 => $m, 5 => $m]);
        $zones = array_map(fn ($z) => ['name' => 'Z ' . $z['id']] + $z, fanboyZonesPropose(fanboyDetect(), fanboyBoardRaw()));
        fanboyZonesSave(['zones' => $zones, 'fans' => [['fan' => fanboyTId(4), 'off_ok' => true], ['fan' => fanboyTId(5), 'alike' => true]]]);
        $f = fanboyBoardRaw()['fans'];
        same('fanboy zones draft: one save — the zones and what the step said about its fans (4 may stand; 5 «the same fans»), 5\'s «may stand» as it was',
            [true, true, true, false], [count(fanboyBoardRaw()['zones'] ?? []) > 0, $f[fanboyTId(4)]['off_ok'] ?? null, $f[fanboyTId(5)]['alike'] ?? null, $f[fanboyTId(5)]['off_ok'] ?? false]);
        $before = fanboyBoardRaw();
        foreach ([['fan' => 'nope:pwm9', 'off_ok' => true], ['fan' => fanboyTId(4), 'off_ok' => 'yes'], 'x'] as $bad) {
            try {
                fanboyZonesSave(['zones' => [], 'fans' => [['fan' => fanboyTId(5), 'off_ok' => true], $bad]]);
                $key = 'kept';
            } catch (Problem $p) {
                $key = $p->key;
            }
            same('fanboy zones draft: a bad entry (' . json_encode($bad) . ') — refused, nothing written (zones and fans as before)', [true, $before], [$key !== 'kept', fanboyBoardRaw()]);
        }
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    check('fanboy zones draft: the page — «darf stehen» and «Es sind gleiche Lüfter» go into the draft (no «role» request from the step), the CPU cooler\'s confirmation kept with it; «Speichern» sends zones and fans together, «Verwerfen» drops both; the curve follows the draft',
        str_contains($js, "if (f.role !== 'cpu' || !c.checked) { changeFan(id, { off_ok: c.checked, cpu_off_confirm: undefined }); return; }")
        && str_contains($js, "act: async () => { changeFan(id, { off_ok: true, cpu_off_confirm: true }); return true; }")
        && str_contains($js, "() => changeFan(id, { alike: true }),") && str_contains($js, "act('zones_save', { zones: zonesNow().map(cleanZone), fans: zfansList() }, b,")
        && str_contains($js, "() => { zdraft = null; zfans = null; curves.clear(); drawn = ''; redraw(); }") && str_contains($js, 'const fans = (z.fans || []).map(setupFan).filter(Boolean).map(zfanNow);')
        && !preg_match("/act\\('role', \\{ fan: id, role: f\\.role, (alike|name)/", $js));
}

/**
 * 2026-11-20 (the owner: «und wenn jemand deswegen die Lüfter nicht einstellen und nicht vermessen kann? ein Haken zum
 * Übersteuern mit Warnung?» — and «auch hier sollte anschliessend übersteuern möglich sein» for no CPU reading): a step
 * started with the user's tick `warm_ok` runs without the warmth guards for that ONE run — not refused, not stopped, a CPU
 * cooler's standstill not cut for a missing reading; the record says so, the settings never carry it; without the tick
 * every guard acts as before
 */
function testFanboyWarmOverride(): void
{
    $t = fanboyT('warmok');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        fanboyTSettle($t, $spec);
        $m = ['table' => [[100, 1500], [50, 900], [10, 420]], 'min_pct' => 10, 'start_pct' => 10, 'max_rpm' => 1500, 'at' => 1];
        fanboyR12Store();
        // a warm CPU: the quiet test refused — with the tick it starts, the record carries it, the settings don't
        fanboyR12CpuTemp($t, 75);
        try {
            fanboyStepStart('test_up', [fanboyTId(1)], true);
            $key = 'started';
        } catch (Problem $p) {
            $key = $p->key;
        }
        $r = fanboyStepStart('test_up', [fanboyTId(1)], true, true);
        same('fanboy warm ok: the CPU at 75 °C — the quiet test refused without the tick; with it started, the step\'s record says so, the settings never',
            ['fanboy_quiet_warm', true, true, false], [$key, $r['ok'] ?? null, fanboyStepRead()['warm_ok'] ?? null, str_contains(json_encode(fanboyBoardRaw()), 'warm_ok')]);
        @unlink(fanboyStepFile());
        fanboyR12CpuTemp($t, 28);
        // a measuring run whose source gets warm in the middle (the CPU to 90 at the first look): stopped without the tick,
        // through with it
        $run = function (int $n, bool $ok, callable $each) use ($t, &$spec): array {
            $clock = 0.0;
            $io = fanboyTIo($t, $spec, $clock);
            $sleep = $io['sleep'];
            $io['sleep'] = function (float $s) use ($sleep, $each, &$clock): void {
                $sleep($s);
                $each($clock);
            };
            return fanboyStepRun(['id' => 'wo' . mt_rand(), 'kind' => 'measure', 'fans' => [fanboyTId($n)]] + ($ok ? ['warm_ok' => true] : []), $io);
        };
        $hot = function (?int $c, ?string $phase = null) use ($t): callable {
            return function () use ($t, $c, $phase): void {
                if ($phase === null || (fanboyStepRead()['progress']['phase'] ?? null) === $phase) {
                    fanboyR12CpuTemp($t, $c);
                }
            };
        };
        $step = $run(4, false, $hot(90));
        fanboyR12CpuTemp($t, 28);
        same('fanboy warm ok: the CPU at 90 °C during the table, no tick — stopped «warm» as before', ['stopped', 'warm'], [$step['status'], $step['why']]);
        fanboyR12Store();
        $step = $run(4, true, $hot(90));
        fanboyR12CpuTemp($t, 28);
        same('fanboy warm ok: … with the tick — through: measured, the step done, the record says it ran with the guard off, every output back',
            ['done', true, true, [128, 1], []], [$step['status'], is_array(fanboyBoardRaw()['fans'][fanboyTId(4)]['measured'] ?? null), $step['warm_ok'] ?? null, fanboyTOut($t, 4), fanboyHeld()]);
        // the CPU cooler standing with no CPU reading: cut without the tick, not with it
        fanboyR12Store();
        $step = $run(2, false, $hot(null, 'stop_check'));
        fanboyR12CpuTemp($t, 28);
        same('fanboy warm ok: the CPU cooler standing, the CPU gives no reading, no tick — cut («no reading»), its table kept', ['done', 'no_reading'],
            [$step['status'], $step['cuts'][0]['why'] ?? null]);
        fanboyR12Store();
        $step = $run(2, true, $hot(null, 'stop_check'));
        fanboyR12CpuTemp($t, 28);
        $mm = fanboyBoardRaw()['fans'][fanboyTId(2)]['measured'] ?? [];
        same('fanboy warm ok: … with the tick — not cut: its standstill part runs through, the step done, the cooler back as before',
            ['done', [], true, [128, 1]], [$step['status'], $step['cuts'] ?? [], array_key_exists('stops', $mm), fanboyTOut($t, 2)]);
        unset($m);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $l = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/lang/de.json'), true);
    $agent = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-setup.php');
    check('fanboy warm ok: the page offers the tick only where a warmth guard spoke on this visit (a refusal, a stop, a cut), sends it with the next start only, then forgets it; the agent logs a run with the guard off',
        str_contains($js, "if (j.error && j.error.key === 'fanboy_quiet_warm') warmRefused =") && str_contains($js, "if (STARTS.includes(action)) { warmTick = false; warmRefused = null; }")
        && str_contains($js, "return send('test_up', { fans: ids, quiet: quietOn(), ...warmBody() }, { fresh: true });") && str_contains($js, "return send('measure', { fans: ids, ...warmBody() }, { fresh: true });")
        && str_contains($js, 'const ws = run.kind ? null : warmSpoke();') && !str_contains($js, "Office.store('fanboy.warm")
        && $l['warm.tick'] === 'Trotzdem starten — ich weiss, dass {where} schon {c} °C hat.' && $l['warm.tick_none'] === 'Trotzdem starten — ich weiss, dass Fanboy die CPU-Temperatur nicht lesen kann.'
        && str_contains($agent, "runs with the warmth guard off (the user's tick, this run only)"));
}

/**
 * 2026-11-20 (the owner's measuring: a fan with a soft start passed 12 and failed 10 tries from standing at 4 s; «ein
 * Hinweis, wie lange es dauert, pro Lüfter»): a start value gets FANBOY_START_WAIT (12 s) to make a standing fan turn,
 * ending as soon as two looks turn; each pass says how long the fan took, the measurement keeps the longest (start_secs);
 * the page says «usually about m minutes per fan, at most n» from the agent's own numbers (setup.times.measure_usual, measure_max)
 */
function testFanboyStartWait(): void
{
    $t = fanboyT('startwait');
    try {
        fanboyTDisks($t, 35, null, 33);
        $spec = fanboyR12Spec();
        $spec['fans'][1]['soft'] = 6.0;                  // output 1: turns only after 6 s at a value that starts it
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $step = fanboyR12Measure($t, $spec, 1);
        $m = fanboyBoardRaw()['fans'][fanboyTId(1)]['measured'] ?? [];
        same('fanboy start wait: a fan that starts after 6 s — passes from standing (start value found, no failed try), start_secs about 6',
            ['done', true, true, 0, true], [$step['status'], is_int($m['start_from_stop'] ?? null), ($m['start_tries']['passed'] ?? 0) >= 3, $m['start_tries']['failed'] ?? null,
                ($m['start_secs'] ?? 0) >= 6.0 && ($m['start_secs'] ?? 99) <= 8.0], json_encode($m));
        $spec['fans'][1]['soft'] = 1000.0;               // one that never starts from standing
        fanboyTSettle($t, $spec);
        fanboyR12Store();
        $step = fanboyR12Measure($t, $spec, 1);
        $m = fanboyBoardRaw()['fans'][fanboyTId(1)]['measured'] ?? [];
        same('fanboy start wait: a fan that never starts from standing — every try the full wait, no start value, no start time, it never stands; its table kept; the restart check after it finds it standing (full, an alert) and stops the step',
            ['stopped', 'no_restart', null, null, false, 10], [$step['status'], $step['why'], $m['start_from_stop'] ?? null, $m['start_secs'] ?? null, $m['stops_safe'] ?? null, $m['min_pct'] ?? null], json_encode($m));
        same('fanboy start wait: 12 s per start value; one fan\'s measuring at most 1053 s (18 minutes), usually 233 s (about 4: without the standstill part), both in the state for the page',
            [12.0, 1053, 1053, 233, 233], [FANBOY_START_WAIT, fanboyMeasureMaxSecs(), fanboySetupState(fanboyDetect(), fanboyBoardRaw())['times']['measure_max'] ?? null,
                fanboyMeasureUsualSecs(), fanboySetupState(fanboyDetect(), fanboyBoardRaw())['times']['measure_usual'] ?? null]);
        same('fanboy start wait: a measuring step\'s whole time grows with its fans', [FANBOY_STEP_MAX, 3 * (1053 + FANBOY_UP_STEP_EXTRA)],
            [fanboyStepMax(['kind' => 'hub_look', 'fans' => [1]]), fanboyStepMax(['kind' => 'measure', 'fans' => [1, 2, 3]])]);
    } finally {
        fanboyTEnd();
    }
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $l = json_decode((string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/lang/de.json'), true);
    check('fanboy start wait: the page — «meist etwa {m} Minuten, höchstens {n}» per fan from the state\'s measure_usual (rounded) and measure_max (rounded up), «Lüfter i von n» while it runs, the start time in the fan\'s facts',
        str_contains($js, 'measure_min: Math.ceil(tm.measure_max / 60),') && str_contains($js, 'measure_usual_min: Math.round(tm.measure_usual / 60),')
        && str_contains($l['step.measure_sub'], 'Das Vermessen dauert pro Lüfter meist etwa {measure_usual_min} Minuten, höchstens {measure_min}. Lass es durchlaufen.')
        && str_contains($js, "setText(since, T('strip.of', { i, n }));") && str_contains($js, "T('stand.start_secs', { s: fmt.number(st.start_secs) })"));
}

/**
 * 2026-11-20 (the owner: «immer pro Wärmequelle»; «wenn man seine eigene Kurve mal hat … damit die eigene Einstellung immer
 * dem letzten Stand entspricht»): a kind's goal is its own — a preset may keep his last own curve beside it (`own`),
 * stored with the save and back on the next look, steering nothing while the preset is chosen
 */
function testFanboyKindGoal(): void
{
    $t = fanboyT('kindgoal');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        $settings['armed'] = false;
        fanboyTStore($settings);
        $z = ['id' => 'disks', 'name' => 'Disks', 'fans' => [fanboyTId(4)], 'unit' => 'pct', 'target' => ['preset' => 'normal'],
            'sources' => [['id' => 'parity'], ['id' => 'disk1']], 'curves' => ['disk' => ['preset' => 'quiet', 'own' => [[47, 0], [55, 100]]]]];
        fanboyZonesSave(['zones' => [$z]]);
        $kept = fanboyBoardRaw()['zones'][0]['curves']['disk'] ?? null;
        same('fanboy kind goal: saved — the disk\'s goal Leise with his own curve kept beside it, as stored', ['preset' => 'quiet', 'own' => [[47, 0], [55, 100]]], $kept);
        same('fanboy kind goal: … the control steers by Leise (45 °C 0 %, 55 °C 100 %), never by the kept curve', [[45.0, 0], [55.0, 100]],
            fanboyKindCurve(fanboyBoardRaw()['zones'][0], 'disk')[0]);
        try {
            fanboyZonesSave(['zones' => [['curves' => ['disk' => ['preset' => 'quiet', 'own' => [[47, 0]]]]] + $z]]);
            $key = 'kept';
        } catch (Problem $p) {
            $key = $p->key;
        }
        same('fanboy kind goal: a kept curve of one point — refused as any curve (which zone, which kind)', 'fanboy_zone_curve', $key);
        // 2026-11-20 (the owner: «Wir brauchen also im Grunde keine Grenze»): a flat own curve, a disk far over the disks'
        // old limit (55 °C) — the curve's value, and no alarm
        $settings = fanboyTSettings();
        $settings['zones'][0]['curves'] = ['disk' => ['points' => [[30, 40], [70, 40]]]];
        fanboyTStore($settings);
        $spec = fanboyTSpec();
        fanboyTSettle($t, $spec);
        fanboyBeat();
        $c = fanboyControlNew($settings, FANBOY_T_KEY, fanboyMe('control'));
        $now = 7000.0;
        fanboyTDisks($t, 62, null, 33);
        fanboyTRounds($c, $now, $t, $spec, 60);
        same('fanboy kind goal: no limit — the parity at 62 °C on a flat own curve of 40 %: the zone at 40 % (102), no alarm after two minutes',
            [40.0, 102, null], [round($c['zones']['disks']['cur'], 1), fanboyTOut($t, 4)[0], $c['alarm']]);
        fanboyLetGo($c, 'end');
    } finally {
        fanboyTEnd();
    }
}

/**
 * 2026-11-20 (the owner: «hier fehlen noch punkte, 2 sind zu wenig»): an own curve has four points — the page's
 * fourPoints() puts the missing ones ON the line (whole degrees, a whole value where the line has one), so a curve of two
 * or three keeps its value at every degree; four or more stay as they are. Run in node against the agent's own reading of
 * a curve (fanboyCurveAt()).
 */
function testFanboyFourPoints(): void
{
    $js = (string) file_get_contents(OFFICE_DIR . '/public/desks/fanboy/desk.js');
    $fn = preg_match('~\nfunction fourPoints\(points\) \{\n.*?\n\}\n~s', $js, $m) ? $m[0] : '';
    $node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: (is_executable('/usr/local/bin/node') ? '/usr/local/bin/node' : '');
    if ($fn === '') {
        check('fanboy four points: the page\'s fourPoints() is there', false);
        return;
    }
    if ($node === '') {
        skip('fanboy four points: the page\'s fourPoints() (needs node)');
        return;
    }
    $cases = ['nvme' => [[50, 0], [58, 76]], 'three' => [[40, 25], [55, 60], [65, 100]], 'four' => [[30, 0], [40, 20], [50, 70], [60, 100]],
        'five' => [[30, 0], [35, 10], [40, 20], [50, 70], [60, 100]], 'short' => [[50, 0], [51, 100]], 'odd' => [[40, 0], [47, 100]]];
    foreach (array_keys(FANBOY_SOURCE_DEFAULTS) as $k) {
        foreach (FANBOY_PRESETS as $p) {
            $cases["$k:$p"] = fanboyKindPreset($p, $k);
        }
    }
    $tmp = sys_get_temp_dir() . '/uso-fanboy-four-' . getmypid() . '.js';
    file_put_contents($tmp, $fn . "\nconst c = " . json_encode($cases) . ";\nconst out = {};\nfor (const k of Object.keys(c)) out[k] = fourPoints(c[k]);\nconsole.log(JSON.stringify(out));\n");
    $got = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' 2>&1'), true);
    @unlink($tmp);
    if (!is_array($got)) {
        check('fanboy four points: node answered', false);
        return;
    }
    same('fanboy four points: his NVMe curve (50 °C 0 %, 58 °C 76 %) — four points on its line, whole degrees and percents', [[50, 0], [52, 19], [56, 57], [58, 76]], $got['nvme']);
    same('fanboy four points: the disk\'s «Normal» (40 → 50 °C) as an own curve — 43 °C 30 %, 47 °C 70 % between', [[40, 0], [43, 30], [47, 70], [50, 100]], $got['disk:normal']);
    same('fanboy four points: four or five points — as they are', [$cases['four'], $cases['five']], [$got['four'], $got['five']]);
    same('fanboy four points: a span with no whole degree inside (50 → 51 °C) — no point made up', $cases['short'], $got['short']);
    $bad = [];
    foreach ($cases as $k => $pts) {
        if (in_array($k, ['four', 'five', 'short'], true)) {
            continue;
        }
        $four = $got[$k] ?? [];
        if (count($four) !== 4 || array_filter($four, fn ($p) => !is_int($p[0])) !== []) {
            $bad[] = "$k: " . json_encode($four);
            continue;
        }
        for ($t = 0; $t <= 120; $t++) {
            if (abs(fanboyCurveAt($pts, (float) $t) - fanboyCurveAt(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $four), (float) $t)) > 0.05) {
                $bad[] = "$k at $t °C";
                break;
            }
        }
    }
    same('fanboy four points: every preset of every kind, three points, an odd span — four points at whole degrees, the same value at every degree (± 0.05 where the line has no whole value)', [], $bad);
}

/**
 * 2026-11-20 (the owner's «Speichern»: «Die Zonen passen nicht zu dem, was da ist (sources)» — Fanboy's own proposal held
 * 36 sources, a save took 32): zones_propose never proposes what zones_save refuses — boards of 68 and of 300 heat sources,
 * the proposal saved as the page sends it (each zone named); a zone never over FANBOY_SOURCES_MAX
 */
function testFanboyProposeSaves(): void
{
    foreach ([68, 300] as $want) {
        $base = fanboyFakeBoard();
        $t = fanboyT("propose$want");
        try {
            $have = count(fanboySources(fanboyDetect()));
            fanboyTEnd();
            $chips = array_map(function ($c) use ($want, $have) {
                if ($c[1] !== 'gigabyte_wmi') {
                    return $c;
                }
                $n = count($c['temps']) + $want - $have;
                $c['temps'] = array_combine(range(1, $n), array_map(fn ($i) => [30000 + ($i % 20) * 500, ''], range(1, $n)));
                return $c;
            }, $base['chips']);
            $t = fanboyT("propose$want", 10, FANBOY_T_ADDR, ['chips' => $chips]);
            fanboyTDisks($t, 35, null, 33);
            $settings = fanboyTSettings();
            $settings['zones'] = [];
            fanboyTStore($settings);
            $scan = fanboyDetect();
            $zones = fanboyZonesPropose($scan, fanboyBoardRaw());
            $named = array_map(fn ($z) => ['name' => $z['name'] !== '' ? $z['name'] : ucfirst($z['kind'])] + $z, $zones);
            try {
                fanboyZonesSave(['zones' => $named]);
                $key = 'saved';
            } catch (Problem $p) {
                $key = $p->key . ' ' . json_encode($p->params);
            }
            $sizes = array_map(fn ($z) => count($z['sources']), $zones);
            same("fanboy propose saves: a board of $want heat sources — his proposal saved as it is, no zone over " . FANBOY_SOURCES_MAX . ' (' . json_encode($sizes) . ')',
                [$want, 'saved', true, count($zones)], [count(fanboySources($scan)), $key, max($sizes) <= FANBOY_SOURCES_MAX, count(fanboyBoardRaw()['zones'] ?? [])]);
        } finally {
            fanboyTEnd();
        }
    }
}

/**
 * 2026-11-20: a refusal a page can reach names the zone in a plain sentence — never a bare field name; only a broken
 * request is the generic one («reload the page»), five languages
 */
function testFanboyZonesWords(): void
{
    $keys = ['fanboy_bad_zones' => [], 'fanboy_zones_max' => ['max'], 'fanboy_zone_name' => ['n', 'max'], 'fanboy_zone_fan_gone' => ['fan', 'zone'],
        'fanboy_zone_fan_twice' => ['fan', 'zone', 'other'], 'fanboy_zone_no_fan' => ['zone'], 'fanboy_zone_no_source' => ['zone'],
        'fanboy_zone_sources_max' => ['zone', 'n', 'max'], 'fanboy_zone_source_gone' => ['source', 'zone'], 'fanboy_zone_curve' => ['zone', 'kind']];
    $bad = [];
    foreach (['de', 'en', 'it', 'fr', 'es'] as $l) {
        $j = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$l.json"), true);
        foreach ($keys as $k => $ph) {
            $text = (string) ($j["errors.$k"] ?? '');
            preg_match_all('/\{(\w+)\}/', $text, $m);
            if ($text === '' || array_diff($ph, $m[1]) || str_contains($text, '{field}') || preg_match('/\((sources?|fans|curves|zones?|name)\)/', $text)) {
                $bad[] = "$l:$k";
            }
        }
    }
    same('fanboy zones words: each refusal a sentence of its own in five languages, naming what it needs (zone, fan, source, kind) — no «(sources)»', [], $bad);
    $t = fanboyT('zoneswords');
    try {
        fanboyTDisks($t, 35, null, 33);
        $settings = fanboyTSettings();
        fanboyTStore($settings);
        $z = $settings['zones'][0];
        $refused = function (array $zones): array {
            try {
                fanboyZonesCheck($zones, fanboyDetect(), fanboyBoardRaw());
                return ['kept'];
            } catch (Problem $p) {
                return [$p->key, $p->params];
            }
        };
        $many = array_map(fn ($i) => ['id' => "x$i"], range(1, FANBOY_SOURCES_MAX + 1));
        same('fanboy zones words: the refusals name the zone — too many sources (how many, the most), no fan, no source, a source gone (its name), a fan in two zones',
            [['fanboy_zone_sources_max', ['zone' => 'Disks', 'n' => FANBOY_SOURCES_MAX + 1, 'max' => FANBOY_SOURCES_MAX]], ['fanboy_zone_no_fan', ['zone' => 'Disks']],
             ['fanboy_zone_no_source', ['zone' => 'Disks']], ['fanboy_zone_source_gone', ['source' => 'disk9', 'id' => 'disk9', 'zone' => 'Disks']],
             ['fanboy_zone_fan_twice', ['fan' => 'Cage', 'zone' => 'Disks', 'other' => 'Two']]],
            [$refused([['sources' => $many] + $z]), $refused([['fans' => []] + $z]), $refused([['sources' => []] + $z]), $refused([['sources' => [['id' => 'disk9']]] + $z]),
             $refused([$z, ['id' => 'two', 'name' => 'Two'] + $z])]);
        same('fanboy zones words: a curve of nine points — which zone, which kind', ['fanboy_zone_curve', 'Disks', 'disk'],
            (fn ($r) => [$r[0], $r[1]['zone'] ?? null, $r[1]['kind'] ?? null])($refused([['curves' => ['disk' => ['points' => array_map(fn ($i) => [30 + $i * 3, $i * 10], range(0, 8))]]] + $z])));
    } finally {
        fanboyTEnd();
    }
}
