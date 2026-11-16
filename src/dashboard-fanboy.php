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
 * «Speeds with history» adds a small curve per zone — the warmest °C and the level of the last FANBOY_DASH_HOURS hours —
 * from the history the control keeps (RUN_DIR/fanboy/history.json every minute, the data folder's copy every 10 minutes:
 * already thinned by fanboyHistoryThin(), read here in exactly its shape) folded into FANBOY_DASH_POINTS spans, each with
 * its peaks: a handful of numbers per zone go into the page, never the file.
 *
 * Its rows refresh as the office's do: a small script asks api.php?a=dash&part=fanboy (the same rows, the same files)
 * every minute, every 20 s while he steers — never faster than his state changes — and when the page comes back.
 */

const FANBOY_DASH_FRESH = 60;           // seconds: the control writes every 10 s, his page looks every 5 s
const FANBOY_DASH_ZONES = 4;            // rows at most, the rest «+ n more» (a link to his page)
const FANBOY_DASH_FANS = 6;             // fans of a zone unfolded at most
const FANBOY_DASH_SHOWS = ['off', 'speeds', 'history'];
const FANBOY_DASH_HOURS = 6;            // «with history»: the last hours a zone's curve shows (the default) …
const FANBOY_DASH_POINTS = 24;          // … in this many spans, every span its warmest °C and highest level
const FANBOY_DASH_CLEAR_AFTER = 120;   // seconds the zone stays at full after an alarm's reason ended — an agent without
                                        // the state's times.clear_after: this mirror (a test compares it with FANBOY_CLEAR_AFTER)
const FANBOY_DASH_SPANS_WEB = [1, 3, 6, 12, 24, 72];   // 2026-11-16 (the owner: «durchklicken»): the spans a click goes through — fanboy-dash.php's

/**
 * The span of «with history» as the office keeps it (dashboard.json `hours`; the data folder away: the mirror in RAM) —
 * one of the six, else FANBOY_DASH_HOURS
 */
function officeFanboyDashHours(): int
{
    $j = is_dir(OFFICE_DATA) ? officeReadJson(OFFICE_DATA . '/fanboy/dashboard.json') : officeReadJson(officeRunDir() . '/fanboy/dash.json');
    $h = $j['hours'] ?? null;
    return is_int($h) && in_array($h, FANBOY_DASH_SPANS_WEB, true) ? $h : FANBOY_DASH_HOURS;
}

/** The span a click on the tile's label goes to: the next of the six, after the last the first */
function officeFanboyDashNextHours(int $h): int
{
    $i = array_search($h, FANBOY_DASH_SPANS_WEB, true);
    return FANBOY_DASH_SPANS_WEB[$i === false ? 0 : ($i + 1) % count(FANBOY_DASH_SPANS_WEB)];
}

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
 * The curves of «with history»: per zone the last FANBOY_DASH_HOURS hours of the control's history (RAM first, else the
 * data folder's copy; only this board's, only points in exactly their shape [time, °C|null, level %|null, rpm|null] as
 * fanboyHistoryThin() keeps them), folded into FANBOY_DASH_POINTS spans — each its warmest °C and highest level (a peak
 * never disappears), null where nothing was recorded. A zone without a point in the window: none.
 *
 * @return array<string, list<array{0:?float,1:?float}>>
 */
function officeFanboyDashHistory(string $board, int $now, ?int $hours = null): array
{
    $hours ??= officeFanboyDashHours();
    $file = null;
    foreach ([officeRunDir() . '/fanboy/history.json', is_dir(OFFICE_DATA) ? OFFICE_DATA . '/fanboy/history.json' : ''] as $f) {
        $j = $f === '' ? null : officeReadJson($f);
        if (is_array($j) && ($j['v'] ?? null) === 1 && is_array($j['zones'] ?? null) && ($board === '' || ($j['board'] ?? null) === $board)) {
            $file = $j;
            break;
        }
    }
    $num = static fn (mixed $v): ?float => is_int($v) || is_float($v) ? (float) $v : null;
    $from = $now - $hours * 3600;
    $span = intdiv($hours * 3600, FANBOY_DASH_POINTS);
    $out = [];
    foreach ($file['zones'] ?? [] as $zid => $pts) {
        if (!is_string($zid) || !is_array($pts)) {
            continue;
        }
        $spans = array_fill(0, FANBOY_DASH_POINTS, [null, null]);
        $any = false;
        foreach ($pts as $p) {
            if (!is_array($p) || !array_is_list($p) || count($p) !== 4 || !is_int($p[0]) || $p[0] <= $from || $p[0] > $now) {
                continue;
            }
            $k = min(FANBOY_DASH_POINTS - 1, intdiv($p[0] - $from - 1, $span));
            [$c, $l] = [$num($p[1]), $num($p[2])];
            $spans[$k] = [$c === null ? $spans[$k][0] : max($c, $spans[$k][0] ?? $c), $l === null ? $spans[$k][1] : max($l, $spans[$k][1] ?? $l)];
            $any = $any || $c !== null || $l !== null;
        }
        if ($any) {
            $out[$zid] = $spans;
        }
    }
    return $out;
}

/**
 * A zone's curve as inline SVG (no script, no link; colours from the tile's tokens), two bands so the lines never lie on
 * each other: the warmest °C on top as a line on its own scale (at least 10 °C high), the level below as a shaded area on
 * 0–100 %; a gap where nothing was recorded. Plus its caption.
 *
 * @param list<array{0:?float,1:?float}> $spans
 * @return array{svg:string, temp:string, level:string}
 */
function officeFanboyDashCurve(array $s, array $spans, string $lang): array
{
    [$w, $h, $split] = [120, 36, 17];           // the temperature in 1…15, the level in 17…36
    $temps = array_values(array_filter(array_column($spans, 0), 'is_float'));
    $levels = array_values(array_filter(array_column($spans, 1), 'is_float'));
    $n = count($spans);
    $x = static fn (int $i): string => number_format($n > 1 ? $i * $w / ($n - 1) : 0, 1, '.', '');
    $y = static fn (float $v, float $lo, float $hi, float $bottom, float $top): string
        => number_format($bottom - ($v - $lo) / max(0.001, $hi - $lo) * ($bottom - $top), 1, '.', '');
    // the runs of recorded spans, each its own piece (a gap stays a gap)
    $runs = static function (int $col) use ($spans): array {
        $out = [];
        $cur = [];
        foreach ($spans as $i => $p) {
            if ($p[$col] === null) {
                $cur && ($out[] = $cur);
                $cur = [];
                continue;
            }
            $cur[$i] = $p[$col];
        }
        $cur && ($out[] = $cur);
        return $out;
    };
    $svg = '';
    foreach ($runs(1) as $run) {
        $first = array_key_first($run);
        $last = array_key_last($run);
        $line = implode(' ', array_map(static fn ($i, $v) => $x($i) . ',' . $y(min(100.0, max(0.0, $v)), 0, 100, $h, $split), array_keys($run), $run));
        $svg .= '<path class="sso-fb-lvl" d="M' . $x($first) . ',' . $h . ' L' . $line . ' L' . $x($last) . ',' . $h . ' Z"/>';
        $svg .= '<polyline class="sso-fb-lvl-line" points="' . $line . '"/>';
    }
    if ($temps) {
        $mid = (min($temps) + max($temps)) / 2;
        [$lo, $hi] = [min(min($temps), $mid - 5), max(max($temps), $mid + 5)];
        foreach ($runs(0) as $run) {
            $pts = implode(' ', array_map(static fn ($i, $v) => $x($i) . ',' . $y($v, $lo, $hi, $split - 2, 1), array_keys($run), $run));
            $svg .= count($run) > 1 ? '<polyline class="sso-fb-temp" points="' . $pts . '"/>' : '<circle class="sso-fb-dot" cx="' . strtok($pts, ',') . '" cy="' . substr($pts, strpos($pts, ',') + 1) . '" r="1.5"/>';
        }
    }
    $range = static fn (array $v, int $dec): array => [officeFanboyDashNum(min($v), $lang, $dec), officeFanboyDashNum(max($v), $lang, $dec)];
    $temp = '';
    if ($temps) {
        [$a, $b] = $range($temps, 0);
        $temp = $a === $b ? officeDashT($s, 'fanboy.dash.curve_temp_one', ['c' => $a]) : officeDashT($s, 'fanboy.dash.curve_temp', ['lo' => $a, 'hi' => $b]);
    }
    $level = '';
    if ($levels) {
        [$a, $b] = $range($levels, 0);
        $level = $a === $b ? officeDashT($s, 'fanboy.dash.curve_level_one', ['pct' => $a]) : officeDashT($s, 'fanboy.dash.curve_level', ['lo' => $a, 'hi' => $b]);
    }
    return ['svg' => '<svg class="sso-fb-curve" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">' . $svg . '</svg>',
        'temp' => $temp, 'level' => $level];
}

/**
 * Who steers, the zones with their numbers, what must be said first — as plain data (the tile's HTML is drawn from it;
 * the tests read it). $state: data/fanboy.json (null: not readable), $status: the control's status in RAM, $now: the
 * clock, $hist: «with history» — the zones' curves (officeFanboyDashHistory()), null: «speeds» only.
 *
 * @return array{who:string, tone:string, mood:string, banners:list<array{0:string,1:string}>, stale:?int, zones:list<array>,
 *               more:int, watch:list<array{0:string,1:string}>, before:?string, setup:bool, steers:bool, history:bool, no_curve:bool}
 */
function officeFanboyDashModel(array $s, ?array $state, ?array $status, string $lang, int $now, ?array $hist = null): array
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
    $out = ['who' => '', 'tone' => '', 'mood' => '', 'banners' => [], 'stale' => null, 'zones' => [], 'more' => 0, 'watch' => [], 'before' => null,
            'setup' => false, 'steers' => false, 'history' => $hist !== null, 'no_curve' => false];

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
            'nobody' => officeDashT($s, 'fanboy.who.nobody'),       // round 7: outputs on a fixed value someone left
            default => $str($who['name'] ?? null) !== '' ? $str($who['name']) : $whoId,
        };
        $out['who'] = $name . (isset($s["fanboy.dash.why.$why"]) ? ' · ' . officeDashT($s, "fanboy.dash.why.$why") : '');
        $out['tone'] = in_array($why, ['crashed', 'chip_missing', 'array_stopped', 'fixed'], true) ? 'orange' : '';
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
        $raw = is_array($alarm['params'] ?? null) ? $alarm['params'] : [];
        $params = array_map(static fn ($v) => is_string($v) || is_int($v) || is_float($v) ? (string) $v : '', $raw);
        // round 4 (the owner's words): a fan that stands — it and the other fans of its zone at 100 %; alone in its zone: it
        $key = $alarm['code'] === 'stands' && ($raw['alone'] ?? false) === true ? 'stands_alone' : $alarm['code'];
        // 2026-11-16 (the owner): «… bis er wieder dreht, und danach noch {min} Minuten» — from the state's times
        $clear = $int(is_array($setup['times'] ?? null) ? ($setup['times']['clear_after'] ?? null) : null) ?? FANBOY_DASH_CLEAR_AFTER;
        $params['min'] = (string) (int) round($clear / 60);
        $out['banners'][] = ['red', officeDashT($s, "fanboy.alarm.$key", $params + ['fan' => '', 'source' => '', 'c' => '', 'zone' => ''])];
    }
    if (in_array($why, ['chip_missing', 'crashed'], true) || ($runs && $phase === 'wait_chip')) {
        $missing = is_array($control['missing'] ?? null) ? $int($control['missing']['since'] ?? null) : null;
        $text = officeDashT($s, 'fanboy.control.' . ($why === 'crashed' ? 'crashed' : 'chip_missing'));
        $out['banners'][] = ['orange', $missing ? officeDashT($s, 'fanboy.dash.since', ['what' => $text, 'when' => officeDashSince($s, $missing, $lang)]) : $text];
    }
    // his picture as on his page (desk.js setDeskMood): the alarm drawing while an alarm, a missing chip or a stopped control holds
    $out['mood'] = $alarm !== null || in_array($why, ['chip_missing', 'crashed'], true) || ($runs && $phase === 'wait_chip') ? 'alarm' : '';
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
        if ($name === '') {
            $name = preg_match('/:pwm(\d{1,2})$/D', $id, $m) ? officeDashT($s, 'fanboy.out.name', ['n' => (int) $m[1]]) : $id;
        }
        // round 3: several fans on this one connector (a splitter) — one value, one speed for all of them
        $count = $named[$id]['count'] ?? 1;
        return is_int($count) && $count > 1 ? $name . ' · ' . officeDashT($s, 'fanboy.pic.on_one', ['n' => $count]) : $name;
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
                // one he holds and drives that reports 0 rpm stands: marked on its row (and said at the top)
                $stands = $rpm === 0 && $pct !== null && $pct > 0 && ($f['held'] ?? false) === true;
                $fans[] = [$fanName($f['id'], $f), ($stands ? officeDashT($s, 'fanboy.pic.should_turn')
                    : ($rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm])))
                    . ($pct !== null ? ' · ' . officeDashT($s, 'fanboy.dash.pct', ['pct' => $pct]) : ''), $stands];
                if ($stands) {
                    $standing[] = $fanName($f['id'], $f);
                }
            }
            $warm = is_array($l['warmest'] ?? null) && is_numeric($l['warmest']['c'] ?? null) ? $l['warmest'] : null;
            $level = is_numeric($l['level'] ?? null) ? (float) $l['level'] : null;
            $full = ($l['full'] ?? false) === true;         // round 4: an alarm in this zone — its fans at 100 %, the others steer on
        } else {
            // the BIOS runs them: each fan's speed from his last look, the warmest source of the zone from it
            foreach (is_array($z['fans'] ?? null) ? $z['fans'] : [] as $fid) {
                if (!is_string($fid)) {
                    continue;
                }
                $rpm = $tach[$fid] ?? null;
                $rpms[] = $rpm;
                $fans[] = [$fanName($fid), $rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm]), false];
            }
            $warm = null;
            foreach (is_array($z['sources'] ?? null) ? $z['sources'] : [] as $ref) {
                $src = is_array($ref) && is_string($ref['id'] ?? null) ? ($sources[$ref['id']] ?? null) : null;
                if ($src !== null && is_numeric($src['c'] ?? null) && ($warm === null || (float) $src['c'] > (float) $warm['c'])) {
                    $warm = $src;
                }
            }
            $level = null;
            $full = false;
        }
        $unit = $str($z['unit'] ?? $l['unit'] ?? null);
        $out['zones'][] = [
            'id'      => $id,
            'name'    => $name,
            'warmest' => $warm !== null ? officeDashT($s, 'fanboy.pic.warmest', ['where' => officeFanboyDashSource($s, $warm), 'c' => officeFanboyDashNum((float) $warm['c'], $lang)])
                : officeDashT($s, 'fanboy.pic.quiet'),
            'level'   => $full ? officeDashT($s, 'fanboy.pic.full') : ($level === null ? officeDashT($s, 'fanboy.pic.bios')
                : ($unit === 'rpm' ? officeDashT($s, 'fanboy.pic.rpm', ['rpm' => (int) round($level)]) : officeDashT($s, 'fanboy.dash.pct', ['pct' => (int) round($level)]))),
            'full'    => $full,
            'speed'   => count($fans) ? officeFanboyDashSpeed($s, $rpms) : officeDashT($s, 'fanboy.zones.no_fan'),
            'stands'  => in_array(true, array_column($fans, 2), true),
            'fans'    => array_slice($fans, 0, FANBOY_DASH_FANS),
            'fans_more' => max(0, count($fans) - FANBOY_DASH_FANS),
            'curve'   => $hist !== null && isset($hist[$id]) ? officeFanboyDashCurve($s, $hist[$id], $lang) : null,
        ];
    }
    $out['more'] = max(0, count($out['zones']) - FANBOY_DASH_ZONES);
    $out['zones'] = array_slice($out['zones'], 0, FANBOY_DASH_ZONES);
    $out['no_curve'] = $hist !== null && $out['zones'] && !array_filter(array_column($out['zones'], 'curve'));

    // the CPU cooler and pumps: the BIOS runs them, he only watches — a CPU cooler given to him (round 4: `cpu_own`, on the
    // owner's risk) is his: in its zone above, not here
    $watch = is_array($control['watch'] ?? null) ? $control['watch'] : null;
    if ($watch === null) {
        $watch = [];
        foreach ($named as $fid => $f) {
            if (in_array($f['role'] ?? null, ['cpu', 'pump'], true) && !(($f['role'] ?? null) === 'cpu' && is_int($f['cpu_own'] ?? null))) {
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
    // round 6: a fan he doesn't steer that stands — told by his watch (his last look's word, the data folder's state)
    $idle = is_array($state['idle'] ?? null) && ($state['idle']['on'] ?? true) !== false && is_array($state['idle']['tachs'] ?? null) ? $state['idle']['tachs'] : [];
    foreach ($idle as $x) {
        if (is_array($x) && ($x['state'] ?? null) === 'alarm' && is_string($x['name'] ?? null) && $x['name'] !== '') {
            $standing[] = $x['name'];
            $out['mood'] = 'alarm';
        }
    }
    if ($standing && ($alarm === null || !in_array($alarm['code'] ?? null, ['stands', 'bios_stands'], true))) {
        $out['banners'][] = ['red', officeDashT($s, 'fanboy.dash.stands', ['fan' => implode(', ', array_unique($standing))])];
    }

    // before the setup: the connectors that report a speed — connectors, not fans (one may carry a splitter)
    if (!$out['zones'] && $state !== null) {
        $turning = array_values(array_filter($tach['inputs'], static fn ($r) => $r > 0));
        $out['before'] = $turning ? officeDashT($s, 'fanboy.fans.reporting', ['n' => count($turning)]) . ' · ' . officeFanboyDashSpeed($s, $turning)
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
function officeFanboyDashRows(string $lang, ?int $now = null, ?string $show = null): string
{
    $s = officeFanboyDashStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $now ??= time();
    $show ??= officeFanboyDashGate() ?? 'speeds';
    $state = is_dir(OFFICE_DATA) ? officeReadJson(OFFICE_DATA . '/fanboy.json') : null;
    $status = officeReadJson(officeRunDir() . '/fanboy/control.json');
    $board = is_string($state['setup']['board'] ?? null) ? $state['setup']['board'] : (is_string($status['board'] ?? null) ? $status['board'] : '');
    $hours = officeFanboyDashHours();
    $hist = $show === 'history' ? officeFanboyDashHistory($board, $now, $hours) : null;
    $m = officeFanboyDashModel($s, $state, $status, $lang, $now, $hist);
    $url = officeMenuUrl(officeMenuPlace()) . '#/fanboy';
    $out = '<div class="sso-fb-who' . ($m['tone'] !== '' ? ' ' . $m['tone'] . '-text' : '') . '" data-steers="' . ($m['steers'] ? '1' : '0') . '" data-mood="'
        . $m['mood'] . '">' . $h($m['who']) . '</div>';
    foreach ($m['banners'] as [$tone, $text]) {
        $out .= '<a class="sso-fb-banner ' . $tone . '-text" href="' . $h($url) . '">' . $h($text) . '</a>';
    }
    if ($m['stale'] !== null) {
        $out .= '<div class="sso-fb-stale">' . $h(officeDashT($s, 'fanboy.dash.stale', ['when' => officeDashSince($s, $m['stale'], $lang)])) . '</div>';
    }
    $dim = $m['stale'] !== null ? ' sso-fb-old' : '';
    foreach ($m['zones'] as $z) {
        $curve = '';
        if ($z['curve'] !== null) {
            // the span's label is a button: a click goes to the next span (1 · 3 · 6 · 12 · 24 · 72 h), the same for everyone
            $next = officeFanboyDashNextHours($hours);
            $curve = '<span class="sso-fb-graph">' . $z['curve']['svg'] . '<small><button type="button" class="sso-fb-span" data-hours="' . $next . '" title="'
                . $h(officeDashT($s, 'fanboy.dash.span_tip', ['next' => $next])) . '">' . $h(officeDashT($s, 'fanboy.dash.curve', ['h' => $hours])) . '</button>'
                . ($z['curve']['temp'] !== '' ? '<span class="sso-fb-key-temp">' . $h($z['curve']['temp']) . '</span>' : '')
                . ($z['curve']['level'] !== '' ? '<span class="sso-fb-key-lvl">' . $h($z['curve']['level']) . '</span>' : '') . '</small></span>';
        }
        // the row (name, level) in a flex row of its own, the curve under it at the tile's width — a <summary> itself isn't
        // laid out as flex by every browser (the owner saw the curve squeezed to the right, about 40 px)
        $out .= '<details class="sso-fb-zone' . $dim . '" data-zone="' . $h($z['id']) . '"' . ($z['stands'] || $z['full'] ? ' open' : '') . '><summary>'
            . '<span class="sso-fb-row"><span class="sso-fb-name">' . $h($z['name']) . '<small>' . $h($z['warmest']) . '</small></span>'
            . '<span class="sso-fb-val"><b' . ($z['full'] ? ' class="red-text"' : '') . '>' . $h($z['level']) . '</b><small' . ($z['stands'] ? ' class="red-text"' : '') . '>'
            . $h($z['speed']) . '</small></span></span>' . $curve . '</summary>';
        foreach ($z['fans'] as [$name, $text, $stands]) {
            $out .= '<div class="sso-fb-fan' . ($stands ? ' red-text' : '') . '"><span>' . $h($name) . '</span><span>' . $h($text) . '</span></div>';
        }
        if ($z['fans_more'] > 0) {
            $out .= '<a class="sso-fb-more" href="' . $h($url . '/fans') . '">' . $h(officeDashT($s, 'fanboy.dash.fans_more', ['n' => $z['fans_more']])) . '</a>';
        }
        $out .= '</details>';
    }
    if ($m['no_curve']) {
        $out .= '<div class="sso-fb-line sso-fb-nocurve' . $dim . '"><span>' . $h(officeDashT($s, 'fanboy.dash.curve_none')) . '</span></div>';
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
    if (($show = officeFanboyDashGate()) === null) {
        return '';
    }
    $lang = officeDashLang();
    $s = officeFanboyDashStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $rows = officeFanboyDashRows($lang, null, $show);
    // his picture (a case fan; the alarm drawing while something is wrong at his fans — swapped again with every refresh)
    $pics = [];
    foreach (['' => 'avatar.svg', 'alarm' => 'avatar-alarm.svg'] as $mood => $file) {
        if (is_file(OFFICE_PUBLIC . "/desks/fanboy/$file")) {
            $pics[$mood] = officeDashAsset("desks/fanboy/$file");
        }
    }
    $mood = str_contains($rows, 'data-mood="alarm"') && isset($pics['alarm']) ? 'alarm' : '';
    $icon = isset($pics['']) ? '<img class="sso-fb-logo" src="' . $h($pics[$mood]) . '" alt="">' : '<span class="sso-fb-logo" aria-hidden="true">🌀</span>';
    $api = '/plugins/' . OFFICE_PLUGIN . '/api.php?a=dash&part=fanboy&lang=';
    // Unraid's content manager knows a tile by its title: the English name, the same whatever the language
    return '<tbody id="db-sso-fanboy" title="' . $h(officeDashT(officeFanboyDashStrings('en'), 'fanboy.name')) . '">'
        . '<tr><td><span class="tile-header"><span class="tile-header-left">' . $icon
        . '<div class="section"><h3 class="tile-header-main">' . $h(officeDashT($s, 'fanboy.name')) . '</h3></div></span>'
        . '<span class="tile-header-right"><span class="tile-header-right-controls">'
        . '<a href="' . $h(officeMenuUrl(officeMenuPlace()) . '#/fanboy') . '" title="' . $h(officeDashT($s, 'fanboy.dash.open')) . '"><i class="fa fa-fw fa-external-link control"></i></a>'
        . '</span></span></span></td></tr>'
        . '<tr><td><div id="sso-fb-dash" class="sso-fb-dash">' . $rows . '</div></td></tr>'
        . '</tbody>'
        . '<style>'
        // his tones on Unraid's themes (7.4 names the theme on <html>): Unraid's own red, green and link blue read below 4.5:1 on
        // the dark tiles and its orange below it on the light ones — the lighter or darker step of Unraid's own palette instead
        // (tools/fanboy/dashboard.mjs measures it in all four themes); another theme or an older Unraid: Unraid's own
        . 'html.Theme--black #db-sso-fanboy,html.Theme--gray #db-sso-fanboy{--sso-fb-red:var(--red-300);--sso-fb-green:var(--green-500);--sso-fb-orange:var(--orange-300);--sso-fb-link:var(--blue-700);--sso-fb-curve:var(--orange-300)}'
        . 'html.Theme--white #db-sso-fanboy,html.Theme--azure #db-sso-fanboy{--sso-fb-red:var(--red-900);--sso-fb-green:var(--green-900);'
        . '--sso-fb-orange:color-mix(in srgb,var(--orange-900) 80%,var(--black));--sso-fb-link:var(--blue-900);--sso-fb-curve:color-mix(in srgb,var(--orange-400) 85%,var(--black))}'
        . '#db-sso-fanboy .sso-fb-dash .red-text{color:var(--sso-fb-red,var(--red-600))}'
        . '#db-sso-fanboy .sso-fb-dash .green-text{color:var(--sso-fb-green,var(--green-800))}'
        . '#db-sso-fanboy .sso-fb-dash .orange-text{color:var(--sso-fb-orange,var(--orange-300))}'
        . '#db-sso-fanboy .sso-fb-dash a:not(.sso-fb-banner){color:var(--sso-fb-link,var(--link-text-color))}'
        . '#db-sso-fanboy .sso-fb-fan.red-text span:last-child,#db-sso-fanboy small.red-text{opacity:1;font-weight:bold}'
        . '#db-sso-fanboy .sso-fb-logo{width:32px;height:32px;margin-right:8px;font-size:26px;line-height:32px;text-align:center;flex:none}'
        // Unraid's tables keep every cell on one line (tbody td: white-space nowrap): his texts wrap, or a long alarm widens the column
        . '#db-sso-fanboy .sso-fb-dash{display:flex;flex-direction:column;min-width:0;white-space:normal}'
        . '#db-sso-fanboy .sso-fb-who{padding:0 0 6px;overflow-wrap:anywhere}'
        . '#db-sso-fanboy .sso-fb-banner{display:block;padding:6px 0;font-weight:bold;text-decoration:none;overflow-wrap:anywhere;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-stale{padding:6px 0;opacity:.8;font-style:italic;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-old{opacity:.6}'
        . '#db-sso-fanboy .sso-fb-zone{border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso-fanboy .sso-fb-zone summary{display:block;padding:6px 0;cursor:pointer;list-style:none}'
        . '#db-sso-fanboy .sso-fb-row{display:flex;align-items:center;gap:10px;width:100%}'
        . '#db-sso-fanboy .sso-fb-zone summary::-webkit-details-marker{display:none}'
        . '#db-sso-fanboy .sso-fb-zone summary:hover .sso-fb-name{text-decoration:underline}'
        // the span's label: looks like what it does (a link's colour, dotted, a pointer), its tip says the next span
        . '#db-sso-fanboy .sso-fb-span{background:none;border:0;padding:0;margin:0;font:inherit;color:var(--sso-fb-link,var(--link-text-color));cursor:pointer;'
        . 'text-decoration:underline dotted;text-underline-offset:2px;text-transform:none;letter-spacing:normal;min-width:0;min-height:0;height:auto;line-height:inherit;box-shadow:none}'
        . '#db-sso-fanboy .sso-fb-span:hover,#db-sso-fanboy .sso-fb-span:focus-visible{text-decoration-style:solid}'
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
        // «with history»: the curve under the zone's row, the level shaded in the text's colour, the warmest °C as a line in
        // Unraid's orange (on the light themes a darker step: ≥ 3:1 for a line, and never mistaken for the alarm's red)
        . '#db-sso-fanboy .sso-fb-graph{display:flex;flex-direction:column;gap:2px;width:100%;min-width:0;margin-top:4px}'
        . '#db-sso-fanboy .sso-fb-curve{display:block;width:100%;height:38px;overflow:visible}'
        . '#db-sso-fanboy .sso-fb-lvl{fill:currentColor;opacity:.14;stroke:none}'
        . '#db-sso-fanboy .sso-fb-lvl-line{fill:none;stroke:currentColor;stroke-opacity:.5;stroke-width:1;vector-effect:non-scaling-stroke}'
        . '#db-sso-fanboy .sso-fb-temp{fill:none;stroke:var(--sso-fb-curve,var(--orange-300));stroke-width:1.75;stroke-linejoin:round;vector-effect:non-scaling-stroke}'
        . '#db-sso-fanboy .sso-fb-dot{fill:var(--sso-fb-curve,var(--orange-300))}'
        . '#db-sso-fanboy .sso-fb-graph small{display:flex;flex-wrap:wrap;gap:2px 12px}'
        . '#db-sso-fanboy .sso-fb-key-temp::before{content:"";display:inline-block;width:12px;height:2px;margin-right:5px;vertical-align:middle;background:var(--sso-fb-curve,var(--orange-300))}'
        . '#db-sso-fanboy .sso-fb-key-lvl::before{content:"";display:inline-block;width:10px;height:8px;margin-right:5px;vertical-align:middle;background:currentColor;opacity:.3}'
        . '</style>'
        // the rows again as the office's tile does it (the language chosen in the office, else the browser's): every
        // minute, every 20 s while he steers (his status changes every 10 s), when the page comes back; unfolded zones stay
        . '<script>(function(){var codes=' . json_encode(array_column(officeLanguages(), 'code')) . ',own=null,i,j;'
        . 'try{own=localStorage.getItem("office.lang");}catch(e){}'
        . 'if(codes.indexOf(own)<0){own=null;var t=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||""];'
        . 'for(i=0;i<t.length&&!own;i++){var x=String(t[i]||"").toLowerCase(),m=x.split("-")[0];'
        . 'for(j=0;j<codes.length&&!own;j++){if(codes[j].toLowerCase()===x)own=codes[j];}if(!own&&codes.indexOf(m)>=0)own=m;}}'
        . 'var u=' . json_encode($api) . '+encodeURIComponent(own||"en"),next=null;'
        . 'var pics=' . json_encode($pics, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES) . ';'
        . 'function box(){return document.getElementById("sso-fb-dash");}'
        . 'function mood(){var w=box()&&box().querySelector(".sso-fb-who"),i=document.querySelector("#db-sso-fanboy img.sso-fb-logo"),m=w&&w.getAttribute("data-mood")==="alarm"&&pics.alarm?"alarm":"";'
        . 'if(i&&pics[m]&&i.getAttribute("src")!==pics[m])i.setAttribute("src",pics[m]);}'
        . 'function steers(){var w=box()&&box().querySelector(".sso-fb-who");return !!w&&w.getAttribute("data-steers")==="1";}'
        . 'function again(){clearTimeout(next);next=setTimeout(function(){if(!document.hidden)load();},steers()?20000:60000);}'
        . 'function load(){fetch(u,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){var b=box();if(b&&j&&j.ok){'
        . 'var open=[].map.call(b.querySelectorAll("details[open]"),function(d){return d.getAttribute("data-zone");});b.innerHTML=j.html;'
        . '[].forEach.call(b.querySelectorAll("details"),function(d){if(open.indexOf(d.getAttribute("data-zone"))>=0)d.open=true;});mood();}again();})'
        . '.catch(function(){again();});}'
        // a click on the span's label: the next span for the office (fanboy.dashboard {hours}), then the rows anew — never
        // unfolds or folds the zone it sits in
        . 'document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target.closest("#sso-fb-dash .sso-fb-span"):null;if(!t)return;'
        . 'e.preventDefault();e.stopPropagation();if(t.disabled)return;t.disabled=true;var hd={"Content-Type":"application/json","X-Office":"1"};'
        . 'if(typeof csrf_token!=="undefined")hd["X-CSRF-Token"]=csrf_token;'
        . 'fetch(' . json_encode('/plugins/' . OFFICE_PLUGIN . '/api.php') . ',{method:"POST",headers:hd,body:JSON.stringify({a:"fanboy.dashboard",hours:parseInt(t.getAttribute("data-hours"),10)})})'
        . '.then(function(){load();}).catch(function(){t.disabled=false;});},true);'
        . 'again();document.addEventListener("visibilitychange",function(){if(!document.hidden)load();});})();</script>';
}
