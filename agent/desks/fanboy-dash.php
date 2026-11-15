<?php
declare(strict_types=1);

/*
 * Fanboy's setting «Show on the Dashboard» (his own tile on Unraid's Dashboard, src/dashboard-fanboy.php): off (the
 * default), speeds, speeds with history. Office-wide, kept in his data folder beside his settings:
 *   <data folder>/fanboy/dashboard.json   {"v": 1, "show": "off|speeds|history", "at": <time>}
 * Not in settings.json on purpose: the control takes every fan anew when that file changes (a display choice must
 * never make the fans hiccup). Read tolerantly — no file, a broken one, an odd value: off; a writer keeps keys it
 * doesn't know.
 * The mirror in RAM (RUN_DIR/fanboy/dash.json {v: 1, show}) is what the tile reads while the data folder isn't there
 * (the array stopped): written with every look of his and every change of the setting, «off» while he isn't hired.
 */

const FANBOY_DASH_CHOICES = ['off', 'speeds', 'history'];

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

/** The action fanboy.dashboard {show}: the field read strictly (a string, one of the three), kept, the mirror with it */
function fanboyDashSet(array $r): array
{
    $show = textField($r, 'show');
    if (!in_array($show, FANBOY_DASH_CHOICES, true)) {
        throw new Problem('bad_request');
    }
    $raw = readJson(fanboyDashFile()) ?? [];
    $raw['v'] = is_int($raw['v'] ?? null) ? $raw['v'] : 1;
    $raw['show'] = $show;
    $raw['at'] = time();
    @mkdir(FANBOY_DIR, 0700, true);
    writeAtomic(fanboyDashFile(), jsonEncode($raw), 0600);
    fanboyDashMirror($show);
    return ['ok' => true, 'show' => $show, 'state' => fanboyScan()];
}

/** For his state (every look): the setting, and the mirror in RAM kept with it — off while he isn't hired */
function fanboyDashState(?bool $hired = null): string
{
    $show = fanboyDashRead();
    fanboyDashMirror(($hired ?? in_array('fanboy', staffHired(), true)) ? $show : 'off');
    return $show;
}

/** The mirror in RAM, written only when it changes */
function fanboyDashMirror(string $show): void
{
    $file = fanboyRunDir() . '/dash.json';
    $j = jsonEncode(['v' => 1, 'show' => $show]);
    if (@file_get_contents($file) === $j) {
        return;
    }
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic($file, $j, 0644, 0, 0);
}
