<?php
declare(strict_types=1);

/*
 * Fanboy's setting «Show on the Dashboard» (his own tile on Unraid's Dashboard, src/dashboard-fanboy.php): off, speeds,
 * speeds with history. No file: off — until his first «Take over» writes «speeds» where nobody ever chose
 * (fanboyDashDefault(), 2026-11-21, the owner); a file, whatever it says, is never touched by that. Office-wide, kept in
 * his data folder beside his settings:
 *   <data folder>/fanboy/dashboard.json   {"v": 1, "show": "off|speeds|history", "hours": 1|3|6|12|24|72, "at": <time>}
 * `hours` (2026-11-16, the owner: «durchklicken»): the span of «with history»'s curves, clicked through on the tile itself,
 * the same six as his page's history; none or another value: 6.
 * Not in settings.json on purpose: the control takes every fan anew when that file changes (a display choice must
 * never make the fans hiccup). Read tolerantly — no file, a broken one, an odd value: off; a writer keeps keys it
 * doesn't know.
 * The mirror in RAM (RUN_DIR/fanboy/dash.json {v: 1, show, hours}) is what the tile reads while the data folder isn't there
 * (the array stopped): written with every look of his and every change of the setting, «off» while he isn't hired.
 */

const FANBOY_DASH_CHOICES = ['off', 'speeds', 'history'];
const FANBOY_DASH_SPANS = [1, 3, 6, 12, 24, 72];        // the tile's history spans (hours) — the page's history's six
const FANBOY_DASH_SPAN_DEFAULT = 6;

function fanboyDashFile(): string
{
    return FANBOY_DIR . '/dashboard.json';
}

/** The setting as stored: one of the three, else off */
function fanboyDashRead(?string $file = null): string
{
    $show = (readJson($file ?? fanboyDashFile()) ?? [])['show'] ?? null;
    return is_string($show) && in_array($show, FANBOY_DASH_CHOICES, true) ? $show : 'off';
}

/** The span as stored: one of the six, else 6 */
function fanboyDashHours(?string $file = null): int
{
    $h = (readJson($file ?? fanboyDashFile()) ?? [])['hours'] ?? null;
    return is_int($h) && in_array($h, FANBOY_DASH_SPANS, true) ? $h : FANBOY_DASH_SPAN_DEFAULT;
}

/**
 * The action fanboy.dashboard {show?, hours?}: each field alone (not sent: as it was), at least one; read strictly — show
 * a string of the three, hours an integer of the six — kept, the mirror with it
 */
function fanboyDashSet(array $r): array
{
    $hasHours = array_key_exists('hours', $r);
    $show = $hasHours && !array_key_exists('show', $r) ? null : textField($r, 'show');
    if ($show !== null && !in_array($show, FANBOY_DASH_CHOICES, true)) {
        throw new Problem('bad_request');
    }
    $hours = $hasHours ? $r['hours'] : null;
    if ($hasHours && (!is_int($hours) || !in_array($hours, FANBOY_DASH_SPANS, true))) {
        throw new Problem('bad_request');
    }
    $raw = readJson(fanboyDashFile()) ?? [];
    $raw['v'] = is_int($raw['v'] ?? null) ? $raw['v'] : 1;
    if ($show !== null) {
        $raw['show'] = $show;
    }
    if ($hours !== null) {
        $raw['hours'] = $hours;
    }
    $raw['at'] = time();
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic(fanboyDashFile(), jsonEncode($raw), 0600);
    fanboyDashMirror(fanboyDashRead(), fanboyDashHours());
    return ['ok' => true, 'show' => fanboyDashRead(), 'hours' => fanboyDashHours(), 'state' => fanboyScan()];
}

/**
 * The default for a new setup (2026-11-21, the owner: «eigentlich sollte genau diese Variante default eingeschaltet sein,
 * wenn jemand Fanboy neu einstellt»): at «Take over», where no dashboard.json is there yet (nobody ever chose), the tile
 * goes on with «speeds», the mirror with it, logged. A file of any shape (off, broken, a link) is the user's: untouched.
 * True when it wrote.
 */
function fanboyDashDefault(): bool
{
    $file = fanboyDashFile();
    if (file_exists($file) || is_link($file)) {
        return false;
    }
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic($file, jsonEncode(['v' => 1, 'show' => 'speeds', 'at' => time()]), 0600);
    fanboyDashState(true);              // the take over is his action: he is hired
    logLine('Fanboy: his Dashboard tile on with «Speeds» — the setup taken over, and nobody had chosen yet');
    return true;
}

/** For his state (every look): the setting, and the mirror in RAM kept with it — off while he isn't hired */
function fanboyDashState(?bool $hired = null): string
{
    $show = fanboyDashRead();
    fanboyDashMirror(($hired ?? in_array('fanboy', staffHired(), true)) ? $show : 'off', fanboyDashHours());
    return $show;
}

/** The mirror in RAM, written only when it changes */
function fanboyDashMirror(string $show, int $hours = FANBOY_DASH_SPAN_DEFAULT): void
{
    $file = fanboyRunDir() . '/dash.json';
    $j = jsonEncode(['v' => 1, 'show' => $show, 'hours' => $hours]);
    if (@file_get_contents($file) === $j) {
        return;
    }
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic($file, $j, 0644, 0, 0);
}
