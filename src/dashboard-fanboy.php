<?php
declare(strict_types=1);

/*
 * Fanboy's own tile on Unraid's Dashboard (SecretaryOfficeDashboard.page puts it into $mytiles under a key of its own:
 * Unraid's content manager moves and hides it apart from the office's tile — it knows a tile by its title, «Fanboy» in
 * every language). It exists only while he is hired and his setting «Show on the
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
// 2026-11-20 (the owner: «the fans I configured, with their symbol and speed»): in «speeds» each zone's fans under it, each
// drawn as on his page (fan-art.js) — only once the user gave a fan of his a look or a name; a server without: as before
const FANBOY_DASH_SYM_PX = 28;          // the symbol's size in the tile (his page: 46 px and more); the sticker shows only from 72
const FANBOY_DASH_ART_FANS = 4;         // fans of a zone with their symbols at most (the owner, 2026-11-20): one in alarm always
                                        // among them, else his page's order; the rest «+ n more fans» (without symbols: FANBOY_DASH_FANS)
const FANBOY_DASH_LOOK_COLOURS = ['black', 'charcoal', 'graphite', 'grey', 'silver', 'white', 'beige', 'brown', 'frost',
    'red', 'orange', 'yellow', 'green', 'blue', 'purple'];                                                                  // desk.js LOOK_COLOURS
const FANBOY_DASH_LOOK_STYLES = ['swept-narrow', 'swept-wide', 'broad', 'short-broad', 'straight', 'sickle', 'sickle-wide']; // desk.js LOOK_STYLES
const FANBOY_DASH_NEUTRAL_LOOK = ['frame' => 'charcoal', 'blades' => 'grey', 'hub' => 'graphite', 'pads' => null, 'style' => 'swept-wide',
    'shape' => 'square', 'vanes' => 0, 'rim' => false, 'sticker' => null, 'sticker_colour' => 'black'];                   // desk.js NEUTRAL_LOOK
const FANBOY_DASH_ROLES_BIOS = ['cpu', 'pump', 'unknown'];                                                                  // desk.js ROLES_BIOS

/**
 * The fans' catalogue (fans-catalog.json beside his page, shipped with the plugin) by model id — read only when a fan of
 * the setup names a model; entries in their shape only
 *
 * @return array<string, array>
 */
function officeFanboyDashCatalog(array $fans): array
{
    $want = false;
    foreach ($fans as $f) {
        $want = $want || is_string($f['model']['id'] ?? null);
    }
    if (!$want) {
        return [];
    }
    $out = [];
    foreach (officeReadJson(OFFICE_PUBLIC . '/desks/fanboy/fans-catalog.json')['fans'] ?? [] as $c) {
        if (is_array($c) && is_string($c['id'] ?? null)) {
            $out[$c['id']] = $c;
        }
    }
    return $out;
}

/** A measured fan's rpm at a percentage (its table [[pct, rpm] …]), else null — desk.js tableRpm() */
function officeFanboyDashTableRpm(mixed $table, float $pct): ?float
{
    $pts = is_array($table) ? array_values(array_filter($table, static fn ($p) => is_array($p) && array_is_list($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1]))) : [];
    if (!$pts) {
        return null;
    }
    usort($pts, static fn ($a, $b) => $a[0] <=> $b[0]);
    if ($pct <= $pts[0][0]) {
        return (float) $pts[0][1];
    }
    for ($i = 1; $i < count($pts); $i++) {
        if ($pct <= $pts[$i][0]) {
            [$p0, $r0] = $pts[$i - 1];
            [$p1, $r1] = $pts[$i];
            return $r0 + ($r1 - $r0) * ($pct - $p0) / (($p1 - $p0) ?: 1);
        }
    }
    return (float) $pts[count($pts) - 1][1];
}

/**
 * How a fan of his setup looks (desk.js fanLook()): size, blades, its range (what he measured before the catalogue), the
 * model's look with what the user chose over it (frame, wheel, blades' kind, reverse blades; the one «colour» of before
 * counts for frame and wheel) — and whether it is the user's own (`own`: a model, a colour, a style or a name given). Keys the wheel of
 * fan-art.js takes, read with defaults: a setup of an older version draws the neutral wheel.
 *
 * @return array{mm:int, blades:?int, min:?float, max:?float, look:?array, own:bool}
 */
function officeFanboyDashLook(array $f, array $cat): array
{
    $num = static fn (mixed $v): ?float => is_int($v) || is_float($v) ? (float) $v : null;
    $m = is_array($f['model'] ?? null) ? $f['model'] : [];
    $c = is_string($m['id'] ?? null) ? ($cat[$m['id']] ?? null) : null;
    $measured = ($f['measured'] ?? false) === true;
    // its speeds only from his measuring (2026-11-20: the catalogue is about the picture alone)
    $max = $measured && $num($f['max_rpm'] ?? null) ? $num($f['max_rpm']) : null;
    $min = $measured && $num($f['min_pct'] ?? null) ? officeFanboyDashTableRpm($f['table'] ?? null, (float) $num($f['min_pct'])) : null;
    $colour = static fn (mixed $v): ?string => is_string($v) && in_array($v, FANBOY_DASH_LOOK_COLOURS, true) ? $v : null;
    $base = $c !== null && is_array($c['look'] ?? null) ? $c['look'] : null;
    $old = $colour($f['colour'] ?? null);
    $frame = $colour($f['frame'] ?? null) ?? $old;
    $wheel = $colour($f['wheel'] ?? null) ?? $old;
    $style = is_string($f['style'] ?? null) && in_array($f['style'], FANBOY_DASH_LOOK_STYLES, true) ? $f['style'] : null;
    $look = $frame !== null || $wheel !== null || $style !== null
        ? array_merge($base ?? FANBOY_DASH_NEUTRAL_LOOK, $frame !== null ? ['frame' => $frame] : [], $wheel !== null ? ['blades' => $wheel, 'hub' => $wheel] : [], $style !== null ? ['style' => $style] : [])
        : $base;
    // 2026-11-20: a reverse-blade fan — the user's tick over the model's (fan-art.js mirrors its blades; it turns as usual)
    if (is_bool($f['reverse'] ?? null) && ($look !== null || $f['reverse'])) {
        $look = array_merge($look ?? [], ['reverse' => $f['reverse']]);
    }
    $blades = is_int($m['blades'] ?? null) ? $m['blades'] : ($c !== null && is_int($c['blades'] ?? null) ? $c['blades'] : ($look !== null ? null : 7));
    $mm = $c !== null && is_int($c['mm'] ?? null) ? $c['mm'] : (is_int($m['mm'] ?? null) ? $m['mm'] : 120);
    $named = is_string($f['name'] ?? null) && trim($f['name']) !== '';
    return ['mm' => $mm, 'blades' => $blades, 'min' => $min, 'max' => $max, 'look' => $look, 'own' => $look !== null || $named];
}

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
 * Should the tile be there, and with what? null: no tile. The data folder there: hired, the setting;
 * not there (the array stopped): the agent's mirror in RAM, exactly in its shape.
 */
function officeFanboyDashGate(): ?string
{
    if (is_dir(OFFICE_DATA)) {
        if (!isset(officeHired()['fanboy'])) {
            return null;                // not hired
        }
        $show = officeFanboyDashShow();
        return $show === 'off' ? null : $show;
    }
    $mirror = officeReadJson(officeRunDir() . '/fanboy/dash.json');
    $show = $mirror['show'] ?? null;
    return ($mirror['v'] ?? null) === 1 && is_string($show) && in_array($show, ['speeds', 'history'], true) ? $show : null;
}

/**
 * The office's strings with his — read from his own language files when the office's strings don't carry his (the data
 * folder away)
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
 * fanboyHistoryThin() keeps them, who led beside them as fanboyHistoryPack() writes it: `src`, `leads`), folded into
 * FANBOY_DASH_POINTS spans — each its warmest °C, highest level and rpm (a peak never disappears) and the source that led
 * at its warmest moment (2026-11-17, the marker's readout), null where nothing was recorded. A zone without a point in
 * the window: none.
 *
 * @return array<string, list<array{0:?float,1:?float,2:?int,3:?string}>>
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
    $src = is_array($file['src'] ?? null) && array_is_list($file['src']) ? $file['src'] : [];
    $out = [];
    foreach ($file['zones'] ?? [] as $zid => $pts) {
        if (!is_string($zid) || !is_array($pts)) {
            continue;
        }
        // who led: an entry a point, the index into `src` — only when it fits the points exactly
        $leads = $file['leads'][$zid] ?? null;
        $leads = is_array($leads) && array_is_list($leads) && array_is_list($pts) && count($leads) === count($pts) ? $leads : [];
        $spans = array_fill(0, FANBOY_DASH_POINTS, [null, null, null, null]);
        $any = false;
        foreach ($pts as $i => $p) {
            if (!is_array($p) || !array_is_list($p) || count($p) !== 4 || !is_int($p[0]) || $p[0] <= $from || $p[0] > $now) {
                continue;
            }
            $k = min(FANBOY_DASH_POINTS - 1, intdiv($p[0] - $from - 1, $span));
            [$c, $l, $r] = [$num($p[1]), $num($p[2]), is_int($p[3]) ? $p[3] : null];
            $lead = is_int($leads[$i] ?? null) && is_string($src[$leads[$i]] ?? null) ? $src[$leads[$i]] : null;
            $b = $spans[$k];
            // the leader of the span's warmest moment (a tie: the later one's)
            $peak = $c !== null && ($b[0] === null || $c >= $b[0]);
            $spans[$k] = [$c === null ? $b[0] : max($c, $b[0] ?? $c), $l === null ? $b[1] : max($l, $b[1] ?? $l), $r === null ? $b[2] : max($r, $b[2] ?? $r), $peak ? $lead : $b[3]];
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
 * 0–100 %; a gap where nothing was recorded. Plus its caption, and per span the marker's words (2026-11-17: the level, the
 * fans' speed, the source that led with its temperature — «warmest spot» where none was recorded; null: nothing there),
 * the marker's line in the SVG, shown only while the pointer is on the curve.
 *
 * @param list<array{0:?float,1:?float,2?:?int,3?:?string}> $spans
 * @param list<array> $sources the setup's heat sources (his state's setup.sources), for the leaders' names
 * @return array{svg:string, temp:string, level:string, marks:list<?string>}
 */
function officeFanboyDashCurve(array $s, array $spans, string $lang, array $sources = []): array
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
    $byId = [];
    foreach ($sources as $x) {
        if (is_array($x) && is_string($x['id'] ?? null)) {
            $byId[$x['id']] = $x;
        }
    }
    $deg = static fn (float $c): string => officeFanboyDashNum($c, $lang, floor($c) === $c ? 0 : 1);
    $marks = array_map(static function (array $p) use ($s, $byId, $deg): ?string {
        $parts = [];
        if (is_float($p[1] ?? null)) {
            $parts[] = officeDashT($s, 'fanboy.value', ['pct' => (int) round($p[1])]);
        }
        if (is_int($p[2] ?? null)) {
            $parts[] = officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $p[2]]);
        }
        if (is_float($p[0] ?? null)) {
            $lead = is_string($p[3] ?? null) && isset($byId[$p[3]]) ? $byId[$p[3]] : null;
            $parts[] = $lead !== null ? officeDashT($s, 'fanboy.hist.lead', ['where' => officeFanboyDashSource($s, $lead), 'c' => $deg($p[0])])
                : officeDashT($s, 'fanboy.hist.lead_none', ['c' => $deg($p[0])]);
        }
        return $parts ? implode(' · ', $parts) : null;
    }, $spans);
    return ['svg' => '<svg class="sso-fb-curve" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true" focusable="false">' . $svg
        . '<line class="sso-fb-mark" x1="0" x2="0" y1="0" y2="' . $h . '"/></svg>',
        'temp' => $temp, 'level' => $level, 'marks' => $marks];
}

/**
 * Which fans of a zone the tile shows: with symbols FANBOY_DASH_ART_FANS (2026-11-20, the owner) — every fan that stands
 * though it should turn first (an alarm is never behind «more»), then the others in his page's order, shown in that order;
 * without symbols the first FANBOY_DASH_FANS as before
 *
 * @param list<array> $fans the zone's fans [name, text, stands] in his page's order
 * @param list<array> $arts their symbols (state 'stopped': stands though it should turn)
 * @return array<int, true> the indexes kept
 */
function officeFanboyDashPick(array $fans, array $arts, bool $art): array
{
    if (!$art) {
        return array_fill_keys(array_keys(array_slice($fans, 0, FANBOY_DASH_FANS)), true);
    }
    $alarm = array_keys(array_filter($fans, static fn ($f, $i) => ($f[2] ?? false) === true || ($arts[$i]['state'] ?? '') === 'stopped', ARRAY_FILTER_USE_BOTH));
    $keep = array_slice(array_values(array_unique([...$alarm, ...array_keys($fans)])), 0, FANBOY_DASH_ART_FANS);
    sort($keep);
    return array_fill_keys($keep, true);
}

/**
 * Who steers, the zones with their numbers, what must be said first — as plain data (the tile's HTML is drawn from it;
 * the tests read it). $state: data/fanboy.json (null: not readable), $status: the control's status in RAM, $now: the
 * clock, $hist: «with history» — the zones' curves (officeFanboyDashHistory()), null: «speeds» only.
 *
 * Once a fan of the setup has a look or a name (2026-11-20, `art`): each zone's `art` beside its `fans` (the symbol fan-art.js
 * draws, his page's state; at most FANBOY_DASH_ART_FANS, officeFanboyDashPick()), `watch_art` beside `watch` (a watched fan
 * with a look and a name, else null). «Speeds» lays them out under each zone, «with history» in each zone's fold.
 *
 * @return array{who:string, tone:string, mood:string, banners:list<array{0:string,1:string}>, stale:?int, zones:list<array>,
 *               more:int, watch:list<array{0:string,1:string}>, watch_art:list<?array>, before:?string, setup:bool, steers:bool,
 *               history:bool, no_curve:bool, art:bool}
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
    $out = ['who' => '', 'tone' => '', 'mood' => '', 'banners' => [], 'stale' => null, 'zones' => [], 'more' => 0, 'watch' => [], 'watch_art' => [], 'before' => null,
            'setup' => false, 'steers' => false, 'history' => $hist !== null, 'no_curve' => false, 'art' => false];

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
    // 2026-11-20: each fan's symbol as his page draws it (desk.js wheelOf()) — in «speeds», once a fan has a look or a name
    $cat = officeFanboyDashCatalog(array_values($named));
    $looks = [];
    foreach ($named as $fid => $f) {
        $looks[$fid] = officeFanboyDashLook($f, $cat);
        $out['art'] = $out['art'] || $looks[$fid]['own'];
    }
    $art = static function (string $id, ?float $rpm, string $state, string $title) use ($looks): array {
        $l = $looks[$id] ?? officeFanboyDashLook([], []);
        // the wheel's key over refreshes: a short hash, never the output's internal name on the page
        return ['id' => substr(md5($id), 0, 10), 'mm' => $l['mm'], 'blades' => $l['blades'], 'min' => $l['min'], 'max' => $l['max'], 'look' => $l['look'],
            'rpm' => $rpm === null ? null : (int) round($rpm), 'state' => $state, 'title' => $title];
    };
    // a fan of the BIOS on his page: the CPU cooler and pumps (a CPU cooler given to him is his: round 4)
    $biosRole = static fn (string $id): bool => in_array($named[$id]['role'] ?? null, FANBOY_DASH_ROLES_BIOS, true) && !is_int($named[$id]['cpu_own'] ?? null);
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
        $arts = [];
        $rpms = [];
        if ($l !== null) {
            foreach (is_array($l['fans'] ?? null) ? $l['fans'] : [] as $f) {
                if (!is_array($f) || !is_string($f['id'] ?? null)) {
                    continue;
                }
                $rpm = $int($f['rpm'] ?? null);
                $pct = $int($f['pct'] ?? null);
                $rpms[] = $rpm;
                $held = ($f['held'] ?? false) === true;
                // one he holds and drives that reports 0 rpm stands: marked on its row (and said at the top)
                $stands = $rpm === 0 && $pct !== null && $pct > 0 && $held;
                // 2026-11-20: one that may stand and stands now says so (his page's words), never «0 rpm»
                $restsAt = $held && !$stands && is_int($f['stand_since'] ?? null) ? ($f['starts_at'] ?? null) : false;
                $fans[] = [$fanName($f['id'], $f), ($stands ? officeDashT($s, 'fanboy.pic.should_turn')
                    : ($restsAt !== false ? (is_int($restsAt) || is_float($restsAt) ? officeDashT($s, 'fanboy.pic.stands_until', ['c' => officeFanboyDashNum((float) $restsAt, $lang, floor((float) $restsAt) === (float) $restsAt ? 0 : 1)])
                        : officeDashT($s, 'fanboy.pic.stands_ok'))
                    : ($rpm === null ? officeDashT($s, 'fanboy.pic.no_tach') : officeDashT($s, 'fanboy.pic.rpm', ['rpm' => $rpm]))))
                    . ($pct !== null && $restsAt === false ? ' · ' . officeDashT($s, 'fanboy.dash.pct', ['pct' => $pct]) : ''), $stands];
                // the symbol: his page's state (desk.js wheelOf()) — a silent fan of his turns at the share he gives it
                $silent = ($named[$f['id']]['found'] ?? null) === 'silent';
                $bios = !$held || $biosRole($f['id']);
                $st = $bios ? 'bios' : ($silent ? 'unknown' : 'run');
                if ($held && !$silent && $rpm === 0 && ($pct === null || $pct > 0)) {
                    $st = 'stopped';
                }
                $max = $looks[$f['id']]['max'] ?? null;
                $arts[] = $art($f['id'], $silent && $held && $pct !== null ? ($max ?? 2000) * $pct / 100 : ($st === 'unknown' ? null : $rpm),
                    $silent && $held ? 'run' : $st, officeDashT($s, $st === 'bios' ? ($biosRole($f['id']) ? 'fanboy.pic.watched_title' : 'fanboy.pic.bios_title') : 'fanboy.pic.his_title'));
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
                $arts[] = $art($fid, $rpm, 'bios', officeDashT($s, $biosRole($fid) ? 'fanboy.pic.watched_title' : 'fanboy.pic.bios_title'));
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
        $keep = officeFanboyDashPick($fans, $arts, $out['art']);
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
            'fans'    => array_values(array_intersect_key($fans, $keep)),
            'art'     => array_values(array_intersect_key($arts, $keep)),       // each fan's symbol, in the order of `fans`
            'fans_more' => count($fans) - count($keep),
            'curve'   => $hist !== null && isset($hist[$id]) ? officeFanboyDashCurve($s, $hist[$id], $lang, is_array($setup['sources'] ?? null) ? $setup['sources'] : []) : null,
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
        // its symbol only with a look and a name of the user's (else it stays in the one line as before)
        $wid = $str($w['id'] ?? null);
        $given = is_string($named[$wid]['name'] ?? null) && trim($named[$wid]['name']) !== '';
        $out['watch_art'][] = $wid !== '' && $given && ($looks[$wid]['look'] ?? null) !== null ? $art($wid, $rpm, 'bios', officeDashT($s, 'fanboy.pic.watched_title')) : null;
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
    // a fan's row with its symbol: an empty box of the symbol's size (the tile's script draws the wheel of fan-art.js into it
    // from data-fan — nothing moves before it is there), the name, the speed
    $artRow = static fn (?array $a, string $name, string $text, bool $red): string => '<div class="sso-fb-fan sso-fb-fan-art' . ($red ? ' red-text' : '') . '">'
        . '<span class="sso-fb-sym"' . ($a !== null ? ' data-fan="' . $h((string) json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '" title="' . $h($a['title']) . '"' : '') . '></span>'
        . '<span class="sso-fb-fname">' . $h($name) . '</span><span class="sso-fb-fval">' . $h($text) . '</span></div>';
    // «+ n more fans» (a link to his page's «Now»), the same in both layouts
    $fansMore = static fn (int $n): string => $n > 0 ? '<a class="sso-fb-more" href="' . $h($url . '/now') . '">' . $h(officeDashT($s, 'fanboy.dash.fans_more', ['n' => $n])) . '</a>' : '';
    foreach ($m['zones'] as $z) {
        if ($m['art'] && !$m['history']) {
            // «speeds» with the fans the user set up: the zone's line (name, warmest spot, level), its fans always under it,
            // each with its symbol and its own speed — the zone's range of speeds only where it has no fan
            $out .= '<div class="sso-fb-zone sso-fb-zone-art' . $dim . '" data-zone="' . $h($z['id']) . '">'
                . '<span class="sso-fb-row"><span class="sso-fb-name">' . $h($z['name']) . '<small>' . $h($z['warmest']) . '</small></span>'
                . '<span class="sso-fb-val"><b' . ($z['full'] ? ' class="red-text"' : '') . '>' . $h($z['level']) . '</b>'
                . ($z['fans'] ? '' : '<small>' . $h($z['speed']) . '</small>') . '</span></span>';
            foreach ($z['fans'] as $i => [$name, $text, $stands]) {
                $out .= $artRow($z['art'][$i] ?? null, $name, $text, $stands);
            }
            $out .= $fansMore($z['fans_more']) . '</div>';
            continue;
        }
        $curve = '';
        if ($z['curve'] !== null) {
            // the span's label is a button: a click goes to the next span (1 · 3 · 6 · 12 · 24 · 72 h), the same for everyone
            $next = officeFanboyDashNextHours($hours);
            // the marker's words a span each (the times the tile's script adds: the browser's clock and language)
            $curve = '<span class="sso-fb-graph" data-from="' . ($now - $hours * 3600) . '" data-span="' . intdiv($hours * 3600, FANBOY_DASH_POINTS) . '" data-m="'
                . $h((string) json_encode($z['curve']['marks'], JSON_UNESCAPED_UNICODE)) . '">' . $z['curve']['svg'] . '<small><span class="sso-fb-read"></span><button type="button" class="sso-fb-span" data-hours="' . $next . '" title="'
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
        foreach ($z['fans'] as $i => [$name, $text, $stands]) {
            // «with history» and the fans the user set up (2026-11-20): the unfolded list with their symbols, as in «speeds»
            $out .= $m['art'] ? $artRow($z['art'][$i] ?? null, $name, $text, $stands)
                : '<div class="sso-fb-fan' . ($stands ? ' red-text' : '') . '"><span>' . $h($name) . '</span><span>' . $h($text) . '</span></div>';
        }
        $out .= $fansMore($z['fans_more']) . '</details>';
    }
    if ($m['no_curve']) {
        $out .= '<div class="sso-fb-line sso-fb-nocurve' . $dim . '"><span>' . $h(officeDashT($s, 'fanboy.dash.curve_none')) . '</span></div>';
    }
    if ($m['more'] > 0) {
        $out .= '<a class="sso-fb-more" href="' . $h($url . '/now') . '">' . $h(officeDashT($s, 'fanboy.dash.zones_more', ['n' => $m['more']])) . '</a>';
    }
    if ($m['before'] !== null) {
        $out .= '<div class="sso-fb-line' . $dim . '"><span>' . $h($m['before']) . '</span>'
            . ($m['setup'] ? '<a href="' . $h($url . '/setup') . '">' . $h(officeDashT($s, 'fanboy.dash.set_up')) . '</a>' : '') . '</div>';
    }
    if ($m['watch']) {
        // those with a look and a name of the user's on rows of their own with their symbol (only in «speeds» with symbols)
        $own = $m['art'] && !$m['history'] ? array_filter($m['watch_art']) : [];
        $line = array_values(array_diff_key($m['watch'], $own));
        $out .= '<div class="sso-fb-line sso-fb-watch' . $dim . '"><span>' . $h(officeDashT($s, 'fanboy.dash.watched')) . '</span>'
            . ($line ? '<span>' . $h(implode(' · ', array_map(static fn ($w) => "$w[0] $w[1]", $line))) . '</span>' : '') . '</div>';
        if ($own) {
            $out .= '<div class="sso-fb-watched' . $dim . '">';
            foreach ($own as $i => $a) {
                $out .= $artRow($a, $m['watch'][$i][0], $m['watch'][$i][1], $a['rpm'] === 0);
            }
            $out .= '</div>';
        }
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
        // #sso around the rows: fan-art.css (the symbols) is nested in #sso like every style of the office; the Dashboard has
        // no other #sso (the office's own page is never on it)
        . '<tr><td><div id="sso" class="sso-fb-host"><div id="sso-fb-dash" class="sso-fb-dash">' . $rows . '</div></div></td></tr>'
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
        . '#db-sso-fanboy .sso-fb-watch span+span{margin-left:auto;text-align:right}'
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
        // the marker (2026-11-17): its line on the curve and its words in place of the caption — only while the pointer is on it
        . '#db-sso-fanboy .sso-fb-curve{touch-action:pan-y}'
        . '#db-sso-fanboy .sso-fb-mark{display:none;stroke:currentColor;stroke-opacity:.8;stroke-width:1;vector-effect:non-scaling-stroke}'
        . '#db-sso-fanboy .sso-fb-on .sso-fb-mark{display:inline}'
        . '#db-sso-fanboy .sso-fb-read{display:none;font-variant-numeric:tabular-nums}'
        . '#db-sso-fanboy .sso-fb-on small>*{display:none}'
        . '#db-sso-fanboy .sso-fb-on small>.sso-fb-read{display:inline}'
        // the symbols (2026-11-20): fan-art.css asks for the office's tokens — the same as office.css makes them from Unraid's
        // theme (its orange from Unraid's palette here: the Dashboard has no fallback colour of the office's)
        . '#db-sso-fanboy #sso{--bg:var(--background-color);--surface:var(--mild-background-color);--surface2:color-mix(in srgb,var(--text-color) 7%,var(--mild-background-color));'
        . '--ink:var(--text-color);--ink2:color-mix(in srgb,var(--text-color) 90%,var(--background-color));--muted:color-mix(in srgb,var(--text-color) 80%,var(--background-color));'
        . '--line:color-mix(in srgb,var(--text-color) 13%,var(--background-color));--line2:color-mix(in srgb,var(--text-color) 24%,var(--background-color));'
        . '--accent:color-mix(in srgb,var(--orange-500) 82%,var(--text-color));--accent-soft:color-mix(in srgb,var(--orange-500) 16%,var(--mild-background-color));'
        . '--danger:color-mix(in srgb,var(--red-800) 78%,var(--text-color));--danger-ink:color-mix(in srgb,var(--red-800) 55%,var(--text-color))}'
        . '#db-sso-fanboy .sso-fb-fan-art{align-items:center;padding:3px 0 3px 10px}'
        . '#db-sso-fanboy .sso-fb-fan-art .sso-fb-sym{flex:none;display:flex;align-items:center;justify-content:center;width:' . FANBOY_DASH_SYM_PX . 'px;height:' . FANBOY_DASH_SYM_PX . 'px;overflow:visible}'
        . '#db-sso-fanboy .sso-fb-fan-art .fan-wheel{display:block;--fan-px:' . FANBOY_DASH_SYM_PX . 'px}'
        . '#db-sso-fanboy .sso-fb-fan-art .fan-cap{display:none}'
        . '#db-sso-fanboy .sso-fb-fan-art .sso-fb-fname{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
        . '#db-sso-fanboy .sso-fb-fan-art .sso-fb-fval{margin-left:auto;white-space:nowrap;opacity:.8}'
        . '#db-sso-fanboy .sso-fb-fan-art.red-text .sso-fb-fval{opacity:1;font-weight:bold}'
        . '#db-sso-fanboy .sso-fb-zone-art{padding:6px 0 4px}'
        . '#db-sso-fanboy .sso-fb-zone-art>.sso-fb-row{padding-bottom:3px}'
        . '#db-sso-fanboy .sso-fb-watched{padding:0 0 4px}'
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
        // the symbols: fan-art.js (his page's drawing) loaded once when a row asks for one; a wheel kept over every refresh by
        // its fan (it glides to the new speed instead of starting again) — it turns by the browser's compositor, rests off
        // screen, in a hidden tab, with reduced motion and while the fan stands (fan-art.js); no timer of its own
        . 'var art=' . json_encode(officeDashAsset('desks/fanboy/fan-art.js'), JSON_UNESCAPED_SLASHES) . ',asked=false,wheels={};'
        . 'function syms(){var b=box(),l=b?b.querySelectorAll(".sso-fb-sym[data-fan]"):[],old=wheels,seen={};if(!l.length&&!Object.keys(old).length)return;'
        . 'if(!window.FanArt){if(!asked&&l.length){asked=true;var x=document.createElement("script");x.src=art;x.onload=syms;document.head.appendChild(x);}return;}'
        . 'wheels={};[].forEach.call(l,function(n){var f;try{f=JSON.parse(n.getAttribute("data-fan"));}catch(e){return;}var k=f.id+"#"+(seen[f.id]=(seen[f.id]||0)+1);'
        // by the fan's size (2026-11-20, the owner: «ja einschalten»): 140 mm and up fill the box, an 80 mm one is smaller
        // (fan-art.js's pxOf()) — the box keeps its size, the rows don't move
        . 'f.px=Math.min(' . FANBOY_DASH_SYM_PX . ',Math.round(' . FANBOY_DASH_SYM_PX . '*(28+0.3*(+f.mm||120))/70));f.air=false;f.colour="auto";f.title="";var w=old[k];if(w){delete old[k];w.update(f);}else{w=window.FanArt.wheel(f);}n.appendChild(w.el);wheels[k]=w;});'
        . 'for(var k in old)old[k].destroy();}'
        . 'function mood(){var w=box()&&box().querySelector(".sso-fb-who"),i=document.querySelector("#db-sso-fanboy img.sso-fb-logo"),m=w&&w.getAttribute("data-mood")==="alarm"&&pics.alarm?"alarm":"";'
        . 'if(i&&pics[m]&&i.getAttribute("src")!==pics[m])i.setAttribute("src",pics[m]);}'
        . 'function steers(){var w=box()&&box().querySelector(".sso-fb-who");return !!w&&w.getAttribute("data-steers")==="1";}'
        . 'function again(){clearTimeout(next);next=setTimeout(function(){if(!document.hidden)load();},steers()?20000:60000);}'
        . 'function load(){fetch(u,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){var b=box();if(b&&j&&j.ok){'
        . 'var open=[].map.call(b.querySelectorAll("details[open]"),function(d){return d.getAttribute("data-zone");});b.innerHTML=j.html;'
        . '[].forEach.call(b.querySelectorAll("details"),function(d){if(open.indexOf(d.getAttribute("data-zone"))>=0)d.open=true;});mood();syms();}again();})'
        . '.catch(function(){again();});}'
        // a click on the span's label: the next span for the office (fanboy.dashboard {hours}), then the rows anew — never
        // unfolds or folds the zone it sits in
        . 'document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target.closest("#sso-fb-dash .sso-fb-span"):null;if(!t)return;'
        . 'e.preventDefault();e.stopPropagation();if(t.disabled)return;t.disabled=true;var hd={"Content-Type":"application/json","X-Office":"1"};'
        . 'if(typeof csrf_token!=="undefined")hd["X-CSRF-Token"]=csrf_token;'
        . 'fetch(' . json_encode('/plugins/' . OFFICE_PLUGIN . '/api.php') . ',{method:"POST",headers:hd,body:JSON.stringify({a:"fanboy.dashboard",hours:parseInt(t.getAttribute("data-hours"),10)})})'
        . '.then(function(){load();}).catch(function(){t.disabled=false;});},true);'
        // the marker: on the tile's own box (it stays when the rows come anew) — nothing runs while the pointer is elsewhere;
        // the span under the pointer: a line there and its words (the server's) with its times (the browser's) in place
        // of the caption; a tap on the curve marks without unfolding the zone, a tap beside it lets go
        . 'var on=null,touch=false;function off(){if(on){on.classList.remove("sso-fb-on");on=null;}}'
        . 'function hm(t){return new Date(t*1000).toLocaleTimeString(own||"en",{hour:"2-digit",minute:"2-digit"});}'
        . 'function mark(e){var v=e.target&&e.target.closest?e.target.closest(".sso-fb-curve"):null,g=v&&v.parentNode;if(!g||!g.hasAttribute("data-m")){off();return;}'
        . 'var m;try{m=g.m||(g.m=JSON.parse(g.getAttribute("data-m")));}catch(x){return;}var n=m.length,r=v.getBoundingClientRect();if(n<2||!r.width)return;'
        . 'var i=Math.max(0,Math.min(n-1,Math.round((e.clientX-r.left)/r.width*(n-1)))),f=+g.getAttribute("data-from"),s=+g.getAttribute("data-span"),a=f+i*s,'
        . 'w=(s*n>=86400?new Date(a*1000).toLocaleDateString(own||"en",{weekday:"short"})+" ":"")+hm(a)+"–"+hm(a+s),l=v.querySelector(".sso-fb-mark"),x=(i*120/(n-1)).toFixed(1);'
        . 'if(l){l.setAttribute("x1",x);l.setAttribute("x2",x);}var t=g.querySelector(".sso-fb-read");if(t)t.textContent=w+" · "+(m[i]===null?"–":m[i]);'
        . 'if(on!==g){off();on=g;g.classList.add("sso-fb-on");}if(e.type==="pointerdown")touch=e.pointerType!=="mouse";}'
        . 'var bx=box();if(bx){bx.addEventListener("pointermove",mark);bx.addEventListener("pointerdown",mark);'
        . 'bx.addEventListener("pointerleave",function(e){if(e.pointerType==="mouse")off();});'
        . 'bx.addEventListener("click",function(e){if(touch&&e.target.closest&&e.target.closest(".sso-fb-curve")){e.preventDefault();}touch=false;},true);}'
        . 'syms();again();document.addEventListener("visibilitychange",function(){if(!document.hidden)load();});})();</script>';
}
