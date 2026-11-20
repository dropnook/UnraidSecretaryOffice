<?php
/**
 * Fanboy's fans drawn after the real product (2026-11-20, the owner: «allgemein zu wenig Angleichung der Grafik an das
 * Originalprodukt … mehr Farben … einen Reverse-Blade-Haken»): the reverse key stored with the look (tolerant: absent =
 * as before), read back for the page and the Dashboard tile, drawn mirrored and turning the other way; the new colours'
 * and blade shapes' keys the same in the page, the tile and fan-art.js. The catalogue's shape and sources: testFanboyLook.
 */

/** The reverse key: stored with the look as true or false, null clears it, anything else refused; the state carries it */
function testFanboyLookReverse(): void
{
    fanboyT('lookreverse');
    try {
        fanboyR12Store();
        $id = fanboyTId(4);
        $look = fn (array $r) => fanboyFanLook($r + ['fan' => $id])['fan'];
        $look(['model' => 'noctua-nf-a14-pwm']);
        $f = $look(['reverse' => true]);
        $g = $look(['frame' => 'red', 'wheel' => 'blue', 'style' => 'sickle-wide']);
        same('fanboy look reverse: the tick stored as true, beside the model; another field saved later keeps it; the new colours and shape are keys like the others',
            [true, 'noctua-nf-a14-pwm', true, 'red', 'blue', 'sickle-wide'], [$f['reverse'] ?? null, $f['model']['id'] ?? null, $g['reverse'] ?? null, $g['frame'] ?? null, $g['wheel'] ?? null, $g['style'] ?? null]);
        $f = $look(['reverse' => false]);
        $h = $look(['reverse' => null]);
        same('fanboy look reverse: false is his own too (a model reverse by make, not his copy); null clears it (the model\'s says)',
            [false, false], [$f['reverse'] ?? 'missing', array_key_exists('reverse', $h)]);
        $bad = [];
        foreach (['yes', 1, 'true', []] as $v) {
            $bad[] = fanboyR6Refused(fn () => $look(['reverse' => $v]));
        }
        same('fanboy look reverse: anything but true, false or null refused (fanboy_bad_look) — never read as on or off', array_fill(0, 4, 'fanboy_bad_look'), $bad);
        // the one save of the panel carries it
        $r = fanboyFanSave(['fan' => $id, 'role' => 'case', 'name' => 'Vorn', 'look' => ['model' => 'noctua-nf-a8-pwm', 'reverse' => true]]);
        $f = fanboyBoardRaw()['fans'][$id] ?? [];
        same('fanboy look reverse: the panel\'s save (fan_save) stores it with the rest of the look', [true, 'noctua-nf-a8-pwm', true], [$r['saved'] ?? null, $f['model']['id'] ?? null, $f['reverse'] ?? null]);
        // the state for the page and the tile: the key, null where none is stored (an older office's data)
        $fans = array_column(fanboySetupState(fanboyDetect(), fanboyBoardRaw())['fans'], null, 'id');
        same('fanboy look reverse: the state\'s fan says it; a fan without the key (stored before) says null',
            [true, null], [$fans[$id]['reverse'] ?? 'missing', array_key_exists('reverse', $fans[fanboyTId(5)] ?? []) ? $fans[fanboyTId(5)]['reverse'] : 'missing']);
    } finally {
        fanboyTEnd();
    }
}

/** The Dashboard tile's look (officeFanboyDashLook) follows the page's fanLook(): the new colours and shapes, the reverse tick */
function testFanboyLookDash(): void
{
    $now = 1763100000 + 20;
    $id = static fn (int $n): string => "it8696_a0060607:it87.2608:pwm$n";
    $state = fanboyDashFansState(function (array $s) use ($id): array {
        foreach ($s['setup']['fans'] as &$f) {
            if ($f['id'] === $id(4)) {
                $f['reverse'] = true;                               // a catalogue model, mirrored
                $f['frame'] = 'green';
            }
            if ($f['id'] === $id(5)) {
                $f['style'] = 'short-broad';                        // a new shape over the model's
            }
        }
        return $s;
    });
    $o = fanboyDashWeb(['fanboy.json' => $state] + fanboyDashFiles('fanboy', 'speeds'), [], $now);
    $looks = [];
    foreach ($o['model']['zones'] ?? [] as $z) {
        foreach ($z['art'] ?? [] as $a) {
            $looks[] = [$a['look']['frame'] ?? null, $a['look']['style'] ?? null, $a['look']['reverse'] ?? null];
        }
    }
    // the others as their model is (the catalogue says reverse false for a usual one)
    same('fanboy look dash: no error; the tile draws the mirrored fan in its new colour, the new blade shape — the other fans not mirrored',
        ['', true, true, 1], ['' . ($o['err'] ?? 'x'), in_array(['green', 'swept-narrow', true], $looks, true), (bool) array_filter($looks, fn ($l) => $l[1] === 'short-broad' && $l[2] !== true),
            count(array_filter($looks, fn ($l) => $l[2] === true))], json_encode($looks));
}

/** The page, the tile and fan-art.js know the same colours and blade shapes; the picker has the tick and resets it with the model */
function testFanboyLookKeys(): void
{
    $node = testsNode();
    $js = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/desk.js');
    $art = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.js');
    $dash = (string) file_get_contents(OFFICE_DIR . '/src/dashboard-fanboy.php');
    $list = static function (string $src, string $re): array {
        return preg_match($re, $src, $m) ? array_map(fn ($x) => trim($x, " '\n"), explode(',', preg_replace('/\s+/', ' ', $m[1]))) : [];
    };
    $colours = $list($art, "/const LOOK_COLOURS = \\[([^\\]]+)\\]/");
    $styles = $list($art, "/const STYLES = \\[([^\\]]+)\\]/");
    same('fanboy look keys: the page\'s colours and blade shapes are fan-art.js\'s (desk.js LOOK_COLOURS, LOOK_STYLES)',
        [$colours, $styles], [$list($js, "/const LOOK_COLOURS = \\[([^\\]]+)\\]/"), $list($js, "/const LOOK_STYLES = \\[([^\\]]+)\\]/")]);
    same('fanboy look keys: … and the Dashboard tile\'s (FANBOY_DASH_LOOK_COLOURS, FANBOY_DASH_LOOK_STYLES)',
        [$colours, $styles], [$list($dash, "/const FANBOY_DASH_LOOK_COLOURS = \\[([^\\]]+)\\]/"), $list($dash, "/const FANBOY_DASH_LOOK_STYLES = \\[([^\\]]+)\\]/")]);
    check('fanboy look keys: at least red, orange, yellow, green, blue, white, black and grey among the colours; two new blade shapes (short and broad, the wide-tipped sickle)',
        !array_diff(['red', 'orange', 'yellow', 'green', 'blue', 'white', 'black', 'grey', 'purple'], $colours) && !array_diff(['short-broad', 'sickle-wide'], $styles));
    $lang = [];
    foreach (['en', 'de', 'fr', 'it', 'es'] as $l) {
        $s = json_decode((string) file_get_contents(OFFICE_WEB . "/desks/fanboy/lang/$l.json"), true) ?: [];
        foreach (array_merge(array_map(fn ($c) => "colour.$c", $colours), array_map(fn ($c) => "style.$c", $styles), ['look.reverse', 'look.reverse_tip', 'look.reverse_model']) as $k) {
            if (!is_string($s[$k] ?? null) || trim($s[$k]) === '') {
                $lang[] = "$l:$k";
            }
        }
    }
    same('fanboy look keys: every colour, blade shape and the reverse tick named in all five languages', [], $lang);
    check('fanboy look keys: the picker has the «Reverse-Blade» tick (into the draft, sent with «Speichern»); another model, «back to the proposal» and undo set it with the rest',
        str_contains($js, "rev.onchange = () => set('reverse', rev.checked);") && str_contains($js, "keep({ model: v.id, frame: '', wheel: '', style: '', blades: 0, reverse: null, colour: 'auto' });")
        && str_contains($js, "keep({ frame: '', wheel: '', style: '', blades: 0, reverse: null, colour: 'auto' });") && str_contains($js, 'blades: u.blades, reverse: u.reverse });')
        && str_contains($js, "if (reverse !== null && (look || reverse)) look = { ...(look || {}), reverse };"));
    $agent = (string) file_get_contents(OFFICE_DIR . '/agent/desks/fanboy-setup.php');
    check('fanboy look keys: the panel\'s one save passes the reverse key on to the look',
        str_contains($agent, "array_flip(['model', 'mm', 'blades', 'frame', 'wheel', 'style', 'colour', 'reverse'])"));
    // drawn: the reverse fan's turning box mirrored — the blades mirrored and the one animation turning the other way
    $css = (string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fan-art.css');
    // Corsair: «reverse fans do not spin in the opposite direction … the blades are inverted» — mirrored blades, the same turn
    check('fanboy look keys: a reverse fan — its blades mirrored inside the turning part, the wheel turning the same way (one animation for all, no mirrored box), the fan\'s own word over the model\'s',
        str_contains($art, "const g = svg('g', reverse ? { class: 'fan-blades', transform: 'scale(-1 1)' } : { class: 'fan-blades' });")
        && str_contains($art, "rotorSvg(mm, blades, style, sticker, rim, size, reverse)") && !str_contains($css, 'scaleX(-1)') && str_contains($art, "(reverse ? ' fan-reverse' : '')")
        && str_contains($art, "const reverse = typeof f.reverse === 'boolean' ? f.reverse : !!(f.look && typeof f.look === 'object' && f.look.reverse === true);"));
    if ($node === '') {
        check('fanboy look keys: node is missing here - the blades\' drawing skipped', true);
        return;
    }
    $tmp = hardeningTmp('fanboy-look-keys');
    file_put_contents("$tmp/t.js", <<<'JS'
const fs = require('fs');
globalThis.window = globalThis;
globalThis.document = { currentScript: null, addEventListener() {}, getElementById: () => null, hidden: false };
new Function(fs.readFileSync(process.argv[2], 'utf8'))();
const cat = JSON.parse(fs.readFileSync(process.argv[3], 'utf8')).fans;
const by = (id) => cat.find((f) => f.id === id);
const a8 = by('noctua-nf-a8-pwm'), a14 = by('noctua-nf-a14-pwm'), g2 = by('noctua-nf-a14x25-g2-pwm');
const pick = (f) => { const l = window.FanArt.lookOf(f.look); return [f.blades, l.style, l.shape, l.hub_size, l.struts]; };
console.log(JSON.stringify({ a8: pick(a8), a14: pick(a14), g2: pick(g2), reverseByMake: cat.filter((f) => f.look.reverse).map((f) => f.id) }));
JS);
    $o = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg("$tmp/t.js") . ' ' . escapeshellarg(OFFICE_WEB . '/desks/fanboy/fan-art.js')
        . ' ' . escapeshellarg(OFFICE_WEB . '/desks/fanboy/fans-catalog.json') . ' 2>&1'), true);
    // the owner's example (2026-11-20): the 80 mm and the 140 mm of one maker clearly apart — the NF-A8's seven short broad
    // blades on a big hub, the NF-A14 PWM's seven wide ones on a small hub, the NF-A14x25 G2's nine long sickles (his photo)
    check('fanboy look keys: the owner\'s example — NF-A8: 7 short broad blades, big hub, pads; NF-A14 PWM: 7 wide blades, small hub, corner pieces, struts; NF-A14x25 G2: 9 wide-tipped sickles',
        is_array($o) && $o['a8'][0] === 7 && $o['a8'][1] === 'short-broad' && $o['a8'][2] === 'pads' && $o['a14'][0] === 7 && $o['a14'][1] === 'swept-wide' && $o['a14'][2] === 'corner'
        && $o['a8'][3] > $o['a14'][3] + 0.1 && $o['a14'][4] > 0 && $o['g2'][0] === 9 && $o['g2'][1] === 'sickle-wide', json_encode($o));
    $named = [];
    foreach (json_decode((string) file_get_contents(OFFICE_WEB . '/desks/fanboy/fans-catalog.json'), true)['fans'] ?? [] as $f) {
        if (stripos((string) ($f['model'] ?? ''), 'reverse blade') !== false) {
            $named[] = $f['id'];
        }
    }
    check('fanboy look keys: reverse by make exactly where the maker sells it so (a model named «Reverse Blade»), and at least one there',
        is_array($o) && $named !== [] && $o['reverseByMake'] === $named, json_encode([$o['reverseByMake'] ?? null, $named]));
    hardeningRm($tmp);
}
