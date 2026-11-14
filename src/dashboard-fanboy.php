<?php
declare(strict_types=1);

/*
 * Fanboy's own tile on Unraid's Dashboard (SecretaryOfficeDashboard.page puts it into $mytiles under a key of its own:
 * Unraid's content manager moves and hides it apart from the office's tile — it knows a tile by its title, «Fanboy» in
 * every language). It exists only while his desk is there (the switch file), he is hired and his setting «Show on the
 * Dashboard» (<data folder>/fanboy/dashboard.json, set by the agent's fanboy.dashboard) is not off.
 *
 * It reads state files only, like the office's tile: his state (data/fanboy.json, written at every look of his page)
 * and the control's status in RAM (RUN_DIR/fanboy/control.json, written every few seconds while it runs) — never
 * hwmon, never a disk, never the agent. While the array is stopped the data folder isn't there: then the agent's mirror
 * in RAM (RUN_DIR/fanboy/dash.json, written with every look and every change of the setting) says whether the tile
 * was on, and the control's last word why the BIOS runs the fans. Numbers older than FANBOY_DASH_FRESH seconds are
 * never shown as now: the tile says since when.
 *
 * Its rows refresh as the office's do: a small script asks api.php?a=dash&part=fanboy (the same rows, the same files)
 * every minute, every 20 s while he steers — never faster than his state changes — and when the page comes back.
 */

const FANBOY_DASH_FRESH = 60;           // seconds: the control writes every 10 s, his page looks every 5 s
const FANBOY_DASH_ZONES = 4;            // rows at most, the rest «+ n more» (a link to his page)
const FANBOY_DASH_FANS = 6;             // fans of a zone unfolded at most
const FANBOY_DASH_SHOWS = ['off', 'speeds', 'history'];

/** The setting as stored: «off» unless the file says exactly one of the others (missing, broken, odd: off) */
function officeFanboyDashShow(?string $data = null): string
{
    $j = officeReadJson(($data ?? OFFICE_DATA) . '/fanboy/dashboard.json');
    $show = $j['show'] ?? null;
    return is_string($show) && in_array($show, FANBOY_DASH_SHOWS, true) ? $show : 'off';
}

/**
 * Should the tile be there, and with what? null: no tile. The data folder there: his desk shown, hired, the setting;
 * not there (the array stopped): the agent's mirror in RAM, exactly in its shape.
 */
function officeFanboyDashGate(): ?string
{
    if (is_dir(OFFICE_DATA)) {
        if (!isset(officeHired()['fanboy'])) {
            return null;                // hidden (no switch file: no such desk) or not hired
        }
        $show = officeFanboyDashShow();
        return $show === 'off' ? null : $show;
    }
    $mirror = officeReadJson(officeRunDir() . '/fanboy/dash.json');
    $show = $mirror['show'] ?? null;
    return ($mirror['v'] ?? null) === 1 && is_string($show) && in_array($show, ['speeds', 'history'], true) ? $show : null;
}

/**
 * The office's strings with his — read from his own language files when the office doesn't list his desk right now (the
 * data folder away: his switch file can't be seen)
 */
function officeFanboyDashStrings(string $lang): array
{
    $s = officeStrings($lang);
    if (!isset($s['fanboy.name'])) {
        foreach (array_unique(['en', $lang]) as $code) {
            foreach (officeReadJson(OFFICE_PUBLIC . "/desks/fanboy/lang/$code.json") ?? [] as $key => $value) {
                if (is_string($key) && $key !== '_meta') {
                    $s["fanboy.$key"] = $value;
                }
            }
        }
    }
    return $s;
}

/** A number for the tile in the language's way (27.9 → «27,9» in German), rounded to $dec places */
function officeFanboyDashNum(float|int $v, string $lang, int $dec = 0): string
{
    return number_format((float) $v, $dec, in_array($lang, ['de', 'it', 'fr', 'es'], true) ? ',' : '.', '');
}

/** A heat source's name as his page says it (desk.js srcLabel()): «Parity», «Disk 2», a sensor's label, else its kind */
function officeFanboyDashSource(array $s, array $w): string
{
    $id = is_string($w['id'] ?? null) ? $w['id'] : '';
    $kind = is_string($w['kind'] ?? null) ? $w['kind'] : 'other';
    if ($kind === 'disk') {
        if (preg_match('/^disk(\d+)$/D', $id, $m)) {
            return officeDashT($s, 'fanboy.src.disk', ['n' => $m[1]]);
        }
        if (preg_match('/^parity(\d*)$/D', $id, $m)) {
            return $m[1] !== '' ? officeDashT($s, 'fanboy.src.parity_n', ['n' => $m[1]]) : officeDashT($s, 'fanboy.src.parity');
        }
        return $id;
    }
    $label = is_string($w['label'] ?? null) ? trim($w['label']) : '';
    return $label !== '' ? $label : officeDashT($s, isset($s["fanboy.kind.$kind"]) ? "fanboy.kind.$kind" : 'fanboy.kind.other');
}

/** «1018 rpm», «905–1310 rpm» for several, «no speed feedback» when none reports one */
function officeFanboyDashSpeed(array $s, array $rpms): string
{
    $rpms = array_values(array_filter($rpms, 'is_int'));
    if (!$rpms) {
        return officeDashT($s, 'fanboy.pic.no_tach');
    }
    [$lo, $hi] = [min($rpms), max($rpms)];
    return $lo === $hi ? officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $lo]) : officeDashT($s, 'fanboy.fans.range', ['min' => $lo, 'max' => $hi]);
}

/**
 * Who steers, the zones with their numbers, what must be said first — as plain data (the tile's HTML is drawn from it;
 * the tests read it). $state: data/fanboy.json (null: not readable), $status: the control's status in RAM, $show: the
 * setting, $now: the clock.
 *
 * @return array{who:string, tone:string, banners:list<array{0:string,1:string}>, stale:?int, zones:list<array>, more:int,
 *               watch:list<array{0:string,1:string}>, before:?string, setup:bool, steers:bool}
 */
function officeFanboyDashModel(array $s, ?array $state, ?array $status, string $lang, int $now): array
{
    $int = static fn (mixed $v): ?int => is_int($v) ? $v : (is_float($v) ? (int) round($v) : null);
    $str = static fn (mixed $v): string => is_string($v) ? $v : '';
    $setup = is_array($state['setup'] ?? null) ? $state['setup'] : [];
    $board = $str($setup['board'] ?? null);
    $phase = $str($status['phase'] ?? null);
    // the control's word counts when it is about this board (or nothing else is known) — live while it writes
    $mine = is_array($status) && ($state === null || $board === '' || $str($status['board'] ?? null) === $board);
    $statusAt = $mine ? ($int($status['time'] ?? null) ?? 0) : 0;
    $runs = $mine && in_array($phase, ['start', 'run', 'hold', 'wait_chip'], true);
    $stateAt = $int($state['time'] ?? null) ?? 0;
    $out = ['who' => '', 'tone' => '', 'banners' => [], 'stale' => null, 'zones' => [], 'more' => 0, 'watch' => [], 'before' => null,
            'setup' => false, 'steers' => false];

    // who steers and why, in one line
    $who = is_array($state['who'] ?? null) ? $state['who'] : ['who' => 'bios', 'why' => null];
    if ($runs) {
        $who = $str($status['who'] ?? null) === 'fanboy' ? ['who' => 'fanboy', 'zones' => count((array) ($status['zones'] ?? []))]
            : ['who' => 'bios', 'why' => $status['why'] ?? null];
    } elseif ($mine && $phase === 'ended' && $statusAt >= $stateAt && ($who['who'] ?? '') === 'fanboy') {
        $who = ['who' => 'bios', 'why' => $status['why'] ?? null];          // it ended after his last look: the BIOS again
    } elseif ($mine && $phase === 'ended' && $statusAt >= $stateAt && in_array($status['why'] ?? null, ['array_stopped', 'crashed', 'released', 'hand_back', 'stopped'], true)) {
        $who = ['who' => 'bios', 'why' => $status['why']];
    }
    $whoId = $str($who['who'] ?? null);
    $why = $str($who['why'] ?? null);
    $out['steers'] = $whoId === 'fanboy';
    if ($out['steers']) {
        $out['who'] = officeDashT($s, 'fanboy.who.fanboy', ['n' => (int) ($who['zones'] ?? 0)]);
        $out['tone'] = 'green';
    } else {
        $name = match ($whoId) {
            'bios'  => officeDashT($s, 'fanboy.who.bios'),
            'bmc'   => officeDashT($s, 'fanboy.who.bmc'),
            default => $str($who['name'] ?? null) !== '' ? $str($who['name']) : $whoId,
        };
        $out['who'] = $name . (isset($s["fanboy.dash.why.$why"]) ? ' · ' . officeDashT($s, "fanboy.dash.why.$why") : '');
        $out['tone'] = in_array($why, ['crashed', 'chip_missing', 'array_stopped'], true) ? 'orange' : '';
    }

    // the numbers: the control's while it runs (its status), else his last look's — and how old they are
    // (a control that ended after his last look: its numbers there are gone with it — the BIOS's speeds from the look)
    $endedSince = $mine && $phase === 'ended' && $statusAt >= $stateAt;
    $control = $runs ? $status : (!$endedSince && is_array($state['control'] ?? null) && ($state['control']['running'] ?? false) === true ? $state['control'] : null);
    $at = $runs ? $statusAt : $stateAt;
    if ($state === null && !$runs) {
        $at = 0;                        // the data folder away: nothing to show but who
    }
    if ($at > 0 && $now - $at > FANBOY_DASH_FRESH) {
        $out['stale'] = $at;
    }

    // first, plain: the alarm, the chip missing, a fan that stands
    $alarm = is_array($control['alarm'] ?? null) ? $control['alarm'] : null;
    if ($alarm !== null && isset($s['fanboy.alarm.' . $str($alarm['code'] ?? null)])) {
        $params = array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', is_array($alarm['params'] ?? null) ? $alarm['params'] : []);
        $out['banners'][] = ['red', officeDashT($s, 'fanboy.alarm.' . $alarm['code'], $params + ['fan' => '', 'source' => '', 'c' => '', 'zone' => ''])];
    }
    if (in_array($why, ['chip_missing', 'crashed'], true) || ($runs && $phase === 'wait_chip')) {
        $missing = is_array($control['missing'] ?? null) ? $int($control['missing']['since'] ?? null) : null;
        $text = officeDashT($s, 'fanboy.control.' . ($why === 'crashed' ? 'crashed' : 'chip_missing'));
        $out['banners'][] = ['orange', $missing ? officeDashT($s, 'fanboy.dash.since', ['what' => $text, 'when' => officeDashSince($s, $missing, $lang)]) : $text];
    }
    $standing = [];

    // one row per zone (his setup's order), its fans unfolding
    $zones = is_array($setup['zones'] ?? null) ? array_values(array_filter($setup['zones'], 'is_array')) : [];
    $live = [];
    foreach (is_array($control['zones'] ?? null) ? $control['zones'] : [] as $z) {
        if (is_array($z) && is_string($z['id'] ?? null)) {
            $live[$z['id']] = $z;
        }
    }
    if (!$zones && $live) {
        $zones = array_values($live);           // no setup known (the data folder away): the control's own list
    }
    $named = [];
    foreach (is_array($setup['fans'] ?? null) ? $setup['fans'] : [] as $f) {
        if (is_array($f) && is_string($f['id'] ?? null)) {
            $named[$f['id']] = $f;
        }
    }
    $tach = officeFanboyDashTachs($state);
    $sources = [];
    foreach (is_array($setup['sources'] ?? null) ? $setup['sources'] : [] as $src) {
        if (is_array($src) && is_string($src['id'] ?? null)) {
            $sources[$src['id']] = $src;
        }
    }
    $fanName = static function (string $id, array $f = []) use ($s, $named, $str): string {
        $name = $str($f['name'] ?? null) ?: $str($named[$id]['name'] ?? null);
        if ($name !== '') {
            return $name;
        }
        return preg_match('/:pwm(\d{1,2})$/D', $id, $m) ? officeDashT($s, 'fanboy.out.name', ['n' => (int) $m[1]]) : $id;
    };
    foreach ($zones as $z) {
        $id = $str($z['id'] ?? null);
        $l = $live[$id] ?? null;
        $name = $str($z['name'] ?? null) ?: (isset($s["fanboy.zones.kind.$id"]) ? officeDashT($s, "fanboy.zones.kind.$id") : $id);
        $fans = [];
        $rpms = [];
        if ($l !== null) {
            foreach (is_array($l['fans'] ?? null) ? $l['fans'] : [] as $f) {
                if (!is_array($f) || !is_string($f['id'] ?? null)) {
                    continue;
                }
                $rpm = $int($f['rpm'] ?? null);
                $pct = $int($f['pct'] ?? null);
                $rpms[] = $rpm;
                $fans[] = [$fanName($f['id'], $f), ($rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm]))
                    . ($pct !== null ? ' · ' . officeDashT($s, 'fanboy.dash.pct', ['pct' => $pct]) : '')];
                if ($rpm === 0 && $pct !== null && $pct > 0 && ($f['held'] ?? false) === true) {
                    $standing[] = $fanName($f['id'], $f);
                }
            }
            $warm = is_array($l['warmest'] ?? null) && is_numeric($l['warmest']['c'] ?? null) ? $l['warmest'] : null;
            $level = is_numeric($l['level'] ?? null) ? (float) $l['level'] : null;
        } else {
            // the BIOS runs them: each fan's speed from his last look, the warmest source of the zone from it
            foreach (is_array($z['fans'] ?? null) ? $z['fans'] : [] as $fid) {
                if (!is_string($fid)) {
                    continue;
                }
                $rpm = $tach[$fid] ?? null;
                $rpms[] = $rpm;
                $fans[] = [$fanName($fid), $rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm])];
            }
            $warm = null;
            foreach (is_array($z['sources'] ?? null) ? $z['sources'] : [] as $ref) {
                $src = is_array($ref) && is_string($ref['id'] ?? null) ? ($sources[$ref['id']] ?? null) : null;
                if ($src !== null && is_numeric($src['c'] ?? null) && ($warm === null || (float) $src['c'] > (float) $warm['c'])) {
                    $warm = $src;
                }
            }
            $level = null;
        }
        $unit = $str($z['unit'] ?? $l['unit'] ?? null);
        $out['zones'][] = [
            'id'      => $id,
            'name'    => $name,
            'warmest' => $warm !== null ? officeDashT($s, 'fanboy.pic.warmest', ['where' => officeFanboyDashSource($s, $warm), 'c' => officeFanboyDashNum((float) $warm['c'], $lang)])
                : officeDashT($s, 'fanboy.pic.quiet'),
            'level'   => $level === null ? officeDashT($s, 'fanboy.pic.bios')
                : ($unit === 'rpm' ? officeDashT($s, 'fanboy.pic.rpm', ['rpm' => (int) round($level)]) : officeDashT($s, 'fanboy.dash.pct', ['pct' => (int) round($level)])),
            'speed'   => count($fans) ? officeFanboyDashSpeed($s, $rpms) : officeDashT($s, 'fanboy.zones.no_fan'),
            'fans'    => array_slice($fans, 0, FANBOY_DASH_FANS),
            'fans_more' => max(0, count($fans) - FANBOY_DASH_FANS),
        ];
    }
    $out['more'] = max(0, count($out['zones']) - FANBOY_DASH_ZONES);
    $out['zones'] = array_slice($out['zones'], 0, FANBOY_DASH_ZONES);

    // the CPU cooler and pumps: the BIOS runs them, he only watches
    $watch = is_array($control['watch'] ?? null) ? $control['watch'] : null;
    if ($watch === null) {
        $watch = [];
        foreach ($named as $fid => $f) {
            if (in_array($f['role'] ?? null, ['cpu', 'pump'], true)) {
                $watch[] = ['id' => $fid, 'name' => $f['name'] ?? '', 'role' => $f['role'], 'rpm' => $tach[$fid] ?? null];
            }
        }
    }
    foreach (array_slice(array_values(array_filter($watch, 'is_array')), 0, 3) as $w) {
        $rpm = $int($w['rpm'] ?? null);
        $name = $str($w['name'] ?? null) ?: officeDashT($s, 'fanboy.role.' . (($w['role'] ?? '') === 'pump' ? 'pump' : 'cpu'));
        $out['watch'][] = [$name, $rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm])];
        if ($rpm === 0) {
            $standing[] = $name;
        }
    }
    if ($standing && ($alarm === null || !in_array($alarm['code'] ?? null, ['stands', 'bios_stands'], true))) {
        $out['banners'][] = ['red', officeDashT($s, 'fanboy.dash.stands', ['fan' => implode(', ', array_unique($standing))])];
    }

    // before the setup: the connectors that report a speed — connectors, not fans (one may carry a splitter)
    if (!$out['zones'] && $state !== null) {
        $turning = array_values(array_filter($tach['inputs'], static fn ($r) => $r > 0));
        $out['before'] = $turning ? officeDashT($s, 'fanboy.dash.connectors', ['n' => count($turning)]) . ' · ' . officeFanboyDashSpeed($s, $turning)
            : officeDashT($s, 'fanboy.dash.connectors_none');
        $out['setup'] = in_array($whoId, ['bios'], true) && in_array($why, ['not_set_up', ''], true);
    }
    return $out;
}

/**
 * Each fan's speed by his last look (his setup's fans: chip, output, the tach inputs the test paired) and every tach
 * input's speed ('inputs'): from the chips of his state only
 *
 * @return array<string, int|null|list<int>>
 */
function officeFanboyDashTachs(?array $state): array
{
    $byChip = [];
    $inputs = [];
    foreach (is_array($state['chips'] ?? null) ? $state['chips'] : [] as $c) {
        if (!is_array($c) || !is_string($c['name'] ?? null) || ($c['kind'] ?? '') === 'gpu') {
            continue;
        }
        foreach (is_array($c['fans'] ?? null) ? $c['fans'] : [] as $f) {
            if (is_array($f) && is_int($f['n'] ?? null)) {
                $rpm = is_int($f['rpm'] ?? null) ? $f['rpm'] : null;
                $byChip[$c['name'] . ':' . (is_string($c['address'] ?? null) ? $c['address'] : '')][$f['n']] = $rpm;
                if ($rpm !== null) {
                    $inputs[] = $rpm;
                }
            }
        }
    }
    $out = ['inputs' => $inputs];
    foreach (is_array($state['setup']['fans'] ?? null) ? $state['setup']['fans'] : [] as $f) {
        if (!is_array($f) || !is_string($f['id'] ?? null) || !preg_match('/^(.*):pwm\d{1,2}$/D', $f['id'], $m)) {
            continue;
        }
        $rpms = [];
        foreach (is_array($f['tachs'] ?? null) ? $f['tachs'] : [] as $n) {
            if (is_int($n) && is_int($byChip[$m[1]][$n] ?? null)) {
                $rpms[] = $byChip[$m[1]][$n];
            }
        }
        $out[$f['id']] = $rpms ? max($rpms) : null;
    }
    return $out;
}

/** The tile's rows as HTML (escaped) — what the page shows and api.php?a=dash&part=fanboy sends again */
function officeFanboyDashRows(string $lang, ?int $now = null): string
{
    $s = officeFanboyDashStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $state = is_dir(OFFICE_DATA) ? officeReadJson(OFFICE_DATA . '/fanboy.json') : null;
    $m = officeFanboyDashModel($s, $state, officeReadJson(officeRunDir() . '/fanboy/control.json'), $lang, $now ?? time());
    $url = officeMenuUrl(officeMenuPlace()) . '#/fanboy';
    $out = '<div class="sso-fb-who' . ($m['tone'] !== '' ? ' ' . $m['tone'] . '-text' : '') . '" data-steers="' . ($m['steers'] ? '1' : '0') . '">' . $h($m['who']) . '</div>';
    foreach ($m['banners'] as [$tone, $text]) {
        $out .= '<a class="sso-fb-banner ' . $tone . '-text" href="' . $h($url) . '">' . $h($text) . '</a>';
    }
    if ($m['stale'] !== null) {
        $out .= '<div class="sso-fb-stale">' . $h(officeDashT($s, 'fanboy.dash.stale', ['when' => officeDashSince($s, $m['stale'], $lang)])) . '</div>';
    }
    $dim = $m['stale'] !== null ? ' sso-fb-old' : '';
    foreach ($m['zones'] as $z) {
        $out .= '<details class="sso-fb-zone' . $dim . '" data-zone="' . $h($z['id']) . '"><summary>'
            . '<span class="sso-fb-name">' . $h($z['name']) . '<small>' . $h($z['warmest']) . '</small></span>'
            . '<span class="sso-fb-val"><b>' . $h($z['level']) . '</b><small>' . $h($z['speed']) . '</small></span></summary>';
        foreach ($z['fans'] as [$name, $text]) {
            $out .= '<div class="sso-fb-fan"><span>' . $h($name) . '</span><span>' . $h($text) . '</span></div>';
        }
        if ($z['fans_more'] > 0) {
            $out .= '<a class="sso-fb-more" href="' . $h($url . '/fans') . '">' . $h(officeDashT($s, 'fanboy.dash.fans_more', ['n' => $z['fans_more']])) . '</a>';
        }
        $out .= '</details>';
    }
    if ($m['more'] > 0) {
        $out .= '<a class="sso-fb-more" href="' . $h($url . '/who') . '">' . $h(officeDashT($s, 'fanboy.dash.zones_more', ['n' => $m['more']])) . '</a>';
    }
    if ($m['before'] !== null) {
        $out .= '<div class="sso-fb-line' . $dim . '"><span>' . $h($m['before']) . '</span>'
            . ($m['setup'] ? '<a href="' . $h($url . '/setup') . '">' . $h(officeDashT($s, 'fanboy.dash.set_up')) . '</a>' : '') . '</div>';
    }
    if ($m['watch']) {
        $out .= '<div class="sso-fb-line sso-fb-watch' . $dim . '"><span>' . $h(officeDashT($s, 'fanboy.dash.watched')) . '</span><span>'
            . $h(implode(' · ', array_map(static fn ($w) => "$w[0] $w[1]", $m['watch']))) . '</span></div>';
    }
    return $out;
}

/** The whole tile ('' when it isn't there) for $mytiles in SecretaryOfficeDashboard.page */
function officeFanboyDashTile(): string
{
    if (officeFanboyDashGate() === null) {
        return '';
    }
    $lang = officeDashLang();
    $s = officeFanboyDashStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $icon = is_file(OFFICE_PUBLIC . '/desks/fanboy/avatar.svg') ? '<img class="sso-fb-logo" src="' . $h(officeDashAsset('desks/fanboy/avatar.svg')) . '" alt="">'
        : '<span class="sso-fb-logo" aria-hidden="true">🌀</span>';
    $api = '/plugins/' . OFFICE_PLUGIN . '/api.php?a=dash&part=fanboy&lang=';
    // Unraid's content manager knows a tile by its title: the English name, the same whatever the language
    return '<tbody id="db-sso-fanboy" title="' . $h(officeDashT(officeFanboyDashStrings('en'), 'fanboy.name')) . '">'
        . '<tr><td><span class="tile-header"><span class="tile-header-left">' . $icon
        . '<div class="section"><h3 class="tile-header-main">' . $h(officeDashT($s, 'fanboy.name')) . '</h3></div></span>'
        . '<span class="tile-header-right"><span class="tile-header-right-controls">'
        . '<a href="' . $h(officeMenuUrl(officeMenuPlace()) . '#/fanboy') . '" title="' . $h(officeDashT($s, 'fanboy.dash.open')) . '"><i class="fa fa-fw fa-external-link control"></i></a>'
        . '</span></span></span></td></tr>'
        . '<tr><td><div id="sso-fb-dash" class="sso-fb-dash">' . officeFanboyDashRows($lang) . '</div></td></tr>'
        . '</tbody>'
        . '<style>'
        . '#db-sso-fanboy .sso-fb-logo{width:32px;height:32px;margin-right:8px;font-size:26px;line-height:32px;text-align:center;flex:none}'
        . '#db-sso-fanboy .sso-fb-dash{display:flex;flex-direction:column;min-width:0}'
        . '#db-sso-fanboy .sso-fb-who{padding:0 0 6px;overflow-wrap:anywhere}'
        . '#db-sso-fanboy .sso-fb-banner{display:block;padding:6px 0;font-weight:bold;text-decoration:none;overflow-wrap:anywhere;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-stale{padding:6px 0;opacity:.8;font-style:italic;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-old{opacity:.6}'
        . '#db-sso-fanboy .sso-fb-zone{border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-zone summary{display:flex;align-items:center;gap:10px;padding:6px 0;cursor:pointer;list-style:none}'
        . '#db-sso-fanboy .sso-fb-zone summary::-webkit-details-marker{display:none}'
        . '#db-sso-fanboy .sso-fb-zone summary:hover .sso-fb-name{text-decoration:underline}'
        . '#db-sso-fanboy .sso-fb-name{display:flex;flex-direction:column;min-width:0;overflow-wrap:anywhere}'
        . '#db-sso-fanboy small{opacity:.7;font-size:.92em}'
        . '#db-sso-fanboy .sso-fb-val{margin-left:auto;display:flex;flex-direction:column;text-align:right;white-space:nowrap}'
        . '#db-sso-fanboy .sso-fb-fan{display:flex;gap:10px;padding:2px 0 2px 14px;font-size:.92em}'
        . '#db-sso-fanboy .sso-fb-fan span:first-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '#db-sso-fanboy .sso-fb-fan span:last-child{margin-left:auto;white-space:nowrap;opacity:.8}'
        . '#db-sso-fanboy .sso-fb-more{display:block;padding:4px 0 6px;font-size:.92em}'
        . '#db-sso-fanboy .sso-fb-line{display:flex;flex-wrap:wrap;gap:4px 10px;padding:6px 0;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-line a{margin-left:auto}'
        . '#db-sso-fanboy .sso-fb-watch span:last-child{margin-left:auto;text-align:right}'
        . '</style>'
        // the rows again as the office's tile does it (the language chosen in the office, else the browser's): every
        // minute, every 20 s while he steers (his status changes every 10 s), when the page comes back; unfolded zones stay
        . '<script>(function(){var codes=' . json_encode(array_column(officeLanguages(), 'code')) . ',own=null,i,j;'
        . 'try{own=localStorage.getItem("office.lang");}catch(e){}'
        . 'if(codes.indexOf(own)<0){own=null;var t=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||""];'
        . 'for(i=0;i<t.length&&!own;i++){var x=String(t[i]||"").toLowerCase(),m=x.split("-")[0];'
        . 'for(j=0;j<codes.length&&!own;j++){if(codes[j].toLowerCase()===x)own=codes[j];}if(!own&&codes.indexOf(m)>=0)own=m;}}'
        . 'var u=' . json_encode($api) . '+encodeURIComponent(own||"en"),next=null;'
        . 'function box(){return document.getElementById("sso-fb-dash");}'
        . 'function steers(){var w=box()&&box().querySelector(".sso-fb-who");return !!w&&w.getAttribute("data-steers")==="1";}'
        . 'function again(){clearTimeout(next);next=setTimeout(function(){if(!document.hidden)load();},steers()?20000:60000);}'
        . 'function load(){fetch(u,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){var b=box();if(b&&j&&j.ok){'
        . 'var open=[].map.call(b.querySelectorAll("details[open]"),function(d){return d.getAttribute("data-zone");});b.innerHTML=j.html;'
        . '[].forEach.call(b.querySelectorAll("details"),function(d){if(open.indexOf(d.getAttribute("data-zone"))>=0)d.open=true;});}again();})'
        . '.catch(function(){again();});}'
        . 'again();document.addEventListener("visibilitychange",function(){if(!document.hidden)load();});})();</script>';
}
