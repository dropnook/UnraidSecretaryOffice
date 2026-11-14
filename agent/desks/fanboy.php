<?php
declare(strict_types=1);

/*
 * Fanboy — the office's building services, the biggest fan of his fans (docs/DEVELOPMENT.md «The desks»).
 *
 * Part 1, the foundation: he looks (agent/desks/fanboy-detect.php), says the situation, keeps a store of settings that
 * never depends on a boot number, and writes a report for the makers' wish form. He writes NOTHING to a fan — no pwm
 * file, anywhere (testFanboyNoWrite greps this desk's files). Setup and the control service come in part 2.
 *
 * HIDDEN until the owner lets him out: desk.json "switch": "show.json" — the desk exists only where
 * <data folder>/fanboy/show.json lies (a plain file, any content, never a link). Without it: no desk() here (no action,
 * no fit, no check), and the web side (src/desks.php officeDeskSwitchedOn()) shows no tile, no route, no hiring. Letting
 * him out for everyone = removing the "switch" key from desk.json.
 *
 * The store (part 2 adds setup and control on top; keep this shape, add keys):
 *   <data folder>/fanboy/settings.json
 *   {"v": 1,
 *    "boards": {                                  keyed by fanboyBoardKey(): «vendor | model» — another board's entry
 *      "<board key>": {                           stays stored and counts again when that board comes back
 *        "board": {"vendor": "…", "model": "…"},
 *        "fans": {                                keyed by fanboyFanId(): «chip:address:pwmN» (no hwmonN ever)
 *          "<fan id>": {"chip": "it8696", "address": "it87.2624", "output": 4, "tach": 4 | null, …part 2's keys}
 *        }}}}
 * Readers take only entries in exactly that shape and never rewrite or delete anything that doesn't match
 * (fanboyStoreRead(), fanboyResolve()): what fits nothing stays where it is, Fanboy says what is missing.
 */

define('FANBOY_DIR', DATA_DIR . '/fanboy');
const FANBOY_STORE = 'settings.json';
const FANBOY_REPORT_MAX = 3500;                  // bytes: the wish form's text takes 4096, the user's words go above it
const FANBOY_IPMI_FANS_MAX = 24;

/** The switch file's name (desk.json "switch"), null once he is out for everyone */
function fanboySwitchName(?string $web = null): ?string
{
    $s = readJson(($web ?? OFFICE_WEB) . '/desks/fanboy/desk.json')['switch'] ?? null;
    return is_string($s) && preg_match('/^[a-z0-9_-]{1,32}\.json$/D', $s) ? $s : null;
}

/**
 * Is the desk there? Out for everyone (no "switch" in desk.json) — or the switch file lies in his data folder: a plain
 * file, never a link. The same rule as src/desks.php officeDeskSwitchedOn() (testFanboyHidden compares them).
 */
function fanboyShown(?string $dataDir = null, ?string $web = null): bool
{
    $meta = readJson(($web ?? OFFICE_WEB) . '/desks/fanboy/desk.json');
    if ($meta === null) {
        return false;                   // his desk.json not readable: hidden (never shown by a mistake)
    }
    if (!array_key_exists('switch', $meta)) {
        return true;
    }
    $s = fanboySwitchName($web);
    $f = ($dataDir ?? DATA_DIR) . "/fanboy/$s";
    return $s !== null && !is_link($f) && is_file($f);
}

if (fanboyShown()) {
    desk('fanboy', [
        'fit'     => fn (): array => fanboyFit(fanboyDetect()),
        'actions' => [
            'refresh' => fn (array $r) => ['ok' => true, 'state' => fanboyScan()],
            // the text for the wish form: shown to the user in the form before anything goes (read only, like refresh)
            'report'  => fn (array $r) => ['ok' => true, 'report' => fanboyReportText($scan = fanboyDetect(), fanboyIpmiFans($scan))],
        ],
    ]);
}

/** A new look, kept as his state (data/fanboy.json) */
function fanboyScan(): array
{
    $state = fanboyDetect();
    writeAtomic(deskFile('fanboy'), jsonEncode($state));
    return $state;
}

/** For the Team Lead: Fanboy fits every server (he can always say what he finds); the reason says what that is */
function fanboyFit(array $scan): array
{
    $code = $scan['situation']['code'];
    return fit(true, $code === 'ok' ? 'yes' : $code, $code === 'other' ? ['who' => implode(', ', $scan['situation']['names'] ?? [])] : []);
}

// ===================================================================== the store: identity without the boot number

function fanboyStoreFile(?string $dir = null): string
{
    return ($dir ?? FANBOY_DIR) . '/' . FANBOY_STORE;
}

/** The board's key: «vendor | model», spaces folded — '' parts stay '' (a board saying nothing is one board too) */
function fanboyBoardKey(array $board): string
{
    $f = fn ($s) => trim((string) preg_replace('/\s+/', ' ', is_string($s) ? $s : ''));
    return $f($board['vendor'] ?? '') . ' | ' . $f($board['model'] ?? '');
}

/** A fan's id: the chip's name, its device's address and the output — «it8696:it87.2624:pwm4» */
function fanboyFanId(string $chip, string $address, int $output): string
{
    return "$chip:$address:pwm$output";
}

/** One stored fan in exactly its shape, else null (never repaired, never removed) */
function fanboyStoredFan(mixed $f): ?array
{
    if (!is_array($f) || !is_string($f['chip'] ?? null) || !preg_match(FANBOY_NAME_RE, $f['chip'])
        || !is_string($f['address'] ?? null) || !preg_match(FANBOY_NAME_RE, $f['address'])
        || !is_int($f['output'] ?? null) || $f['output'] < 1 || $f['output'] > 99
        || (array_key_exists('tach', $f) && $f['tach'] !== null && (!is_int($f['tach']) || $f['tach'] < 1 || $f['tach'] > 99))) {
        return null;
    }
    return $f + ['tach' => null];
}

/**
 * The store as kept: {v, boards: {key: {board, fans: {id: fan}}}} — only entries in exactly their shape; the file is
 * never written here (and nothing in it ever deleted because it doesn't match). No file or a broken one: no boards.
 */
function fanboyStoreRead(?string $file = null): array
{
    $j = readJson($file ?? fanboyStoreFile());
    $out = ['v' => 1, 'boards' => []];
    foreach (is_array($j['boards'] ?? null) ? $j['boards'] : [] as $key => $b) {
        if (!is_string($key) || !is_array($b) || !is_array($b['board'] ?? null)) {
            continue;
        }
        $fans = [];
        foreach (is_array($b['fans'] ?? null) ? $b['fans'] : [] as $id => $f) {
            if (is_string($id) && ($fan = fanboyStoredFan($f)) !== null) {
                $fans[$id] = $fan;
            }
        }
        $out['boards'][$key] = ['board' => ['vendor' => (string) ($b['board']['vendor'] ?? ''), 'model' => (string) ($b['board']['model'] ?? '')], 'fans' => $fans];
    }
    return $out;
}

/** This board's settings (null: none yet — a new board, the others' stay stored) and the other boards kept */
function fanboyBoardSettings(array $store, array $board): array
{
    $key = fanboyBoardKey($board);
    return ['key' => $key, 'here' => $store['boards'][$key] ?? null, 'others' => array_values(array_diff(array_keys($store['boards']), [$key]))];
}

/**
 * Where a stored fan is now, looked up in a fresh scan on every use (never a stored path):
 *   how: here (same chip, same address) · moved (the address changed, exactly one chip of that name: taken) ·
 *        missing (no chip of that name) · ambiguous (the address gone, several chips of that name: none taken) ·
 *        no_output (the chip is there, the output isn't)
 *   path: the output's file now (only for here and moved; the hwmon root of the scan + this boot's hwmonN), tach: the
 *   fingerprint — the paired tach input there now (true), gone (false), none stored (null).
 */
function fanboyResolve(array $fan, array $scan, string $hwmon = FANBOY_HWMON): array
{
    $same = array_values(array_filter($scan['chips'] ?? [], fn ($c) => $c['name'] === $fan['chip']));
    $at = array_values(array_filter($same, fn ($c) => $c['address'] === $fan['address']));
    $how = $at ? 'here' : (count($same) === 1 ? 'moved' : ($same ? 'ambiguous' : 'missing'));
    $chip = $at[0] ?? (count($same) === 1 ? $same[0] : null);
    if ($chip === null) {
        return ['how' => $how, 'path' => null, 'address' => null, 'tach' => null];
    }
    if (!in_array($fan['output'], array_column($chip['outputs'], 'n'), true)) {
        return ['how' => 'no_output', 'path' => null, 'address' => $chip['address'], 'tach' => null];
    }
    return [
        'how'     => $how,
        'path'    => rtrim($hwmon, '/') . '/' . $chip['hwmon'] . '/pwm' . $fan['output'],
        'address' => $chip['address'],
        'tach'    => $fan['tach'] === null ? null : in_array($fan['tach'], array_column($chip['fans'], 'n'), true),
    ];
}

// ===================================================================== the report for the wish form

/**
 * The names of a BMC's fan sensors (IPMI boards only), read with ipmitool when it is there (the IPMI plugin brings it):
 * `ipmitool sdr type Fan`, read only. [] when there is no BMC, no ipmitool or it says nothing usable.
 */
function fanboyIpmiFans(array $scan, ?string $bin = null): array
{
    if (($scan['ipmi'] ?? null) === null) {
        return [];
    }
    $bin ??= array_values(array_filter(['/usr/bin/ipmitool', '/usr/sbin/ipmitool', '/usr/local/bin/ipmitool', '/usr/local/sbin/ipmitool'], 'is_executable'))[0] ?? '';
    if ($bin === '' || !is_executable($bin)) {
        return [];
    }
    [$exit, $out] = run([$bin, 'sdr', 'type', 'Fan'], 15);
    $names = [];
    foreach ($exit === 0 ? explode("\n", $out) : [] as $line) {
        $name = trim(explode('|', $line)[0]);
        if (preg_match('/^[A-Za-z0-9 _.#-]{1,32}$/D', $name) && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    return array_slice($names, 0, FANBOY_IPMI_FANS_MAX);
}

/**
 * The report for the makers' wish form — technical only (the concept's list): the board's model, chips and drivers,
 * how many outputs and tach inputs, the kinds of sensors, kernel and Unraid version, the situation; for an IPMI board
 * the BMC's manufacturer and product id, firmware revision and its fan sensors' names. NEVER: the server's name,
 * serial numbers, disk ids or names, paths, a device's address, names the user gave. English (it is read by the makers),
 * plain lines, no «/» at all (testFanboyReport).
 */
function fanboyReportText(array $scan, array $ipmiFans = []): string
{
    $clean = fn ($s) => trim((string) preg_replace('/[^A-Za-z0-9 ,.()_+:#-]+/', ' ', (string) $s));
    $b = $scan['board'] ?? [];
    $lines = ['Fanboy detection report v1'];
    $lines[] = 'Board: ' . ($clean($b['vendor'] ?? '') ?: '?') . ' - ' . ($clean($b['model'] ?? '') ?: '?');
    $lines[] = 'Kernel: ' . ($clean($scan['kernel'] ?? '') ?: '?') . ' · Unraid: ' . ($clean($scan['unraid'] ?? '') ?: '?');
    $s = $scan['situation'] ?? ['code' => '?'];
    $what = $clean($s['code'] ?? '?');
    if (!empty($s['who'])) {
        $what .= ' (' . implode(', ', array_map($clean, $s['who'])) . ')';
    }
    if (!empty($s['plugins'])) {
        $what .= ' (plugin ' . implode(', ', array_map($clean, $s['plugins'])) . ', module ' . implode(', ', array_map($clean, $s['modules'] ?? [])) . ' not loaded)';
    }
    $lines[] = "Situation: $what";
    $lines[] = 'Chips:';
    $kinds = [];
    foreach ($scan['chips'] ?? [] as $c) {
        $turning = count(array_filter($c['fans'], fn ($f) => ($f['rpm'] ?? 0) > 0));
        $modes = array_count_values(array_map(fn ($o) => (string) ($o['mode'] ?? '?'), $c['outputs']));
        ksort($modes);
        $line = '- ' . $clean($c['name']) . ' (driver ' . ($c['module'] !== null ? $clean($c['module']) : '-') . ', ' . $clean($c['kind']) . '): '
            . 'outputs ' . count($c['outputs']);
        if ($c['outputs']) {
            $line .= ' (auto mode ' . (array_filter($c['outputs'], fn ($o) => $o['auto']) ? 'yes' : 'no') . '; now '
                . implode(', ', array_map(fn ($m, $n) => "$n $m", array_keys($modes), $modes)) . ')';
        }
        $line .= ', tach inputs ' . count($c['fans']) . " ($turning turning), temperatures " . count($c['temps']);
        $lines[] = $line;
        if ($c['temps']) {
            $kinds[$c['kind']] = ($kinds[$c['kind']] ?? 0) + count($c['temps']);
        }
    }
    if (!($scan['chips'] ?? [])) {
        $lines[] = '- none' . (empty($scan['hwmon']) ? ' (no hwmon at all)' : '');
    }
    ksort($kinds);
    $lines[] = 'Sensor kinds: ' . ($kinds ? implode(', ', array_map(fn ($k, $n) => "$k $n", array_keys($kinds), $kinds)) : 'none');
    $disks = $scan['disks'] ?? [];
    $lines[] = 'Unraid disks: ' . count($disks) . ' (' . count(array_filter($disks, fn ($d) => $d['rotational'])) . ' rotating)';
    foreach ($scan['controllers'] ?? [] as $x) {
        $lines[] = 'Other controller: ' . $clean($x['id']) . ' (settings ' . ($x['settings'] ? 'on' : 'off') . ', loop ' . ($x['running'] ? 'running' : 'not running') . ')';
    }
    if (($i = $scan['ipmi'] ?? null) !== null) {
        $lines[] = 'IPMI: BMC ' . ($i['bmc'] ? 'seen' : 'not seen') . '; manufacturer id ' . ($clean($i['manufacturer_id'] ?? '') ?: '?')
            . ', product id ' . ($clean($i['product_id'] ?? '') ?: '?') . ', device id ' . ($clean($i['device_id'] ?? '') ?: '?')
            . ', firmware ' . ($clean($i['firmware_revision'] ?? '') ?: '?') . ', IPMI ' . ($clean($i['ipmi_version'] ?? '') ?: '?');
        $lines[] = 'IPMI fan sensors: ' . ($ipmiFans ? implode(', ', array_map($clean, $ipmiFans)) : 'not read (no ipmitool)');
    }
    $text = implode("\n", $lines);
    return strlen($text) > FANBOY_REPORT_MAX ? mb_strcut($text, 0, FANBOY_REPORT_MAX, 'UTF-8') : $text;
}
