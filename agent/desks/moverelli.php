<?php
declare(strict_types=1);

/*
 * Ms. Moverelli — she completes Unraid's mover. She looks after the Smart Mover that ships with the office
 * (smartmover/, from github.com/helmi1987/custom-mover-for-unraid): per share it picks the files by age, exclude lists
 * and the pool's fill, and pipes them to Unraid's own move binary — the same binary Unraid's mover uses, so allocation,
 * split level and in-use checks stay Unraid's. She never copies a file herself.
 *
 *   data/moverelli/   smart_mover.ini (written by her: every value checked), excludes/ (her own lists), the log,
 *                     status.json (the script's), office-run.json, office-history.json, office-output.txt
 *
 * Runs go through "php agent.php job moverelli list|dry|run": started by the host's atd (from the page) or the
 * office's cron file (on her schedule). Real runs (`run`) only while Unraid's own mover schedule is «Disabled» —
 * otherwise both movers work on the same shares; her page switches it off the way Unraid's form does (Jack Emby's
 * embyMoverOff(), emby-mover.php). Never while Unraid's mover (or Mover Tuning's age_mover) is at work, never beside one
 * of Jack Emby's runs (EmbyCache, the gather) — and his never beside hers (embyRunCheck()). While Jack is hired her
 * ini must keep EmbyCache's list out for his shares (embyMoverRule() «moverelli», moverelliRunCheck()).
 *
 * While she is in charge no other mover runs on a schedule of its own (the owner's rule, 2026-10-10): real runs are
 * refused while Unraid's schedule is on or Mover Tuning (if installed) has an active cron line of its own
 * (moverelliForeign()); User Scripts and other plugins' cron lines that call a mover are named on her page and to the
 * Team Lead — never changed by her.
 *
 * Unraid 7.3.2 and newer (fit). The tests put stand-ins into $GLOBALS['moverelliHost'] (moverelliHost()).
 */

const MOVERELLI_MODES     = ['list' => ['--list-shares'], 'dry' => [], 'run' => ['--run']];
const MOVERELLI_UNRAID    = '7.3.2';        // the oldest Unraid she works on
const MOVERELLI_HISTORY   = 40;
const MOVERELLI_STALE     = 8 * 86400;      // her schedule is set, but nothing ran for this long: the Team Lead says so
const MOVERELLI_LOG_TAIL  = 512 * 1024;
const MOVERELLI_FILES_MAX = 20;             // exclude files per scope (global, a share)
const MOVERELLI_LINES_MAX = 2000;           // lines of one of her own exclude lists
const MOVERELLI_SHARE     = '/^[\w.\- ]{1,64}$/uD';     // a share name she writes as an INI section and a file name
const MOVERELLI_DEFAULTS  = ['min_age' => 0, 'age_stat' => 'ctime', 'move_when_used_above' => 0, 'move_prefer_shares' => true,
                             'mover_debug' => 0, 'max_list' => 50];     // Smart Mover's own defaults (its README)
// what each number may be: [min, max]
const MOVERELLI_RANGES    = ['min_age' => [0, 3650], 'move_when_used_above' => [0, 100], 'mover_debug' => [0, 3], 'max_list' => [1, 100000]];

// what calls a mover in a User Script or a cron line (officeForeignCalls()): Unraid's mover script or Mover Tuning's
// (`mover`, `age_mover`, `mover.php` — as a command: at the start, after a cron line's time, after ; & | ( ` or a wrapper such as
// nohup, php, bash; by its name or its path in Unraid or Mover Tuning), Unraid's
// move binary by its path, a Smart Mover of one's own
const MOVERELLI_FOREIGN = [
    'mover'      => '~(?:^|^(?:@\w+|\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+|[;&|(`]\s*|\$\(\s*|\b(?:exec|nohup|nice|ionice|timeout|sudo|setsid|php|bash|sh)(?:\s+-\S+(?:\s+\d+)?)*\s+|\s(?=/))\s*(?:/usr/local/sbin/|/usr/local/emhttp/plugins/ca\.mover\.tuning/)?(?:age_)?mover(?:\.old|\.php)?(?=\s|$|[;&|)`])~m',
    'move'       => '~(?:/usr/libexec/unraid|/usr/local/s?bin)/move(?=\s|$|[;&|)`"\'])~m',
    'smartmover' => '~custommover_run\.sh~',
];

// a crontab line that runs something: five time fields or an @word, then a command (a VAR=value line is none)
const MOVERELLI_CRON_LINE = '/^(?:@(?:reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)|[0-9*\/,\-A-Za-z]+(?:\s+[0-9*\/,\-A-Za-z]+){4})\s+\S/';

define('MOVERELLI_APP', OFFICE_DIR . '/smartmover');
define('MOVERELLI_DATA', DATA_DIR . '/moverelli');

desk('moverelli', [
    'fit'     => fn (): array => moverelliFit(),
    'start'   => fn () => moverelliScan(),
    'actions' => [
        'refresh'   => fn (array $r) => ['ok' => true, 'state' => moverelliScan()],
        'save'      => fn (array $r) => moverelliSave($r['settings'] ?? null),
        'start_run' => fn (array $r) => moverelliStart(textField($r, 'mode')),
        'stop'      => fn (array $r) => ['ok' => true, 'asked' => moverelliStopAsk(), 'state' => moverelliScan()],
        'schedule'  => fn (array $r) => moverelliSetSchedule(cronField($r)),
        'output'    => fn (array $r) => moverelliOutput(),
        'log'       => fn (array $r) => moverelliLog(),
        // «Switch Unraid's mover schedule off…»: Unraid's own ⟦Mover schedule⟧ «Disabled», as its form sets it (emby-mover.php)
        'mover_off' => fn (array $r) => moverelliMoverOff($r) + ['state' => moverelliScan()],
    ],
    'jobs'    => [
        'moverelli' => fn (array $args) => moverelliJob($args),
    ],
    'checks'  => fn () => moverelliChecks(),
]);

/** Where she reads and writes, what she asks — the tests' stand-ins in $GLOBALS['moverelliHost'] */
function moverelliHost(): array
{
    $h = $GLOBALS['moverelliHost'] ?? [];
    return [
        'dir'           => $h['dir'] ?? MOVERELLI_DATA,
        'app'           => $h['app'] ?? MOVERELLI_APP,
        'shares_dir'    => $h['shares_dir'] ?? '/boot/config/shares',
        'mnt'           => $h['mnt'] ?? '/mnt',
        'env'           => $h['env'] ?? [],          // more for the script (the tests: CUSTOMMOVER_MOVER_BIN, CUSTOMMOVER_ZFS)
        'version'       => $h['version'] ?? fn (): string => reportUnraidVersion(),
        'unraid'        => $h['unraid'] ?? fn (): array => embyMoverOwn(embyMoverHost()),
        'mover_running' => $h['mover_running'] ?? fn (?int $self = null): bool => embyMoverRunning(null, $self),
        'jack_busy'     => $h['jack_busy'] ?? fn (): bool => embyAnyRunning(),
        'emby'          => $h['emby'] ?? fn (): ?array => moverelliJackLists(),
        'scheduled'     => $h['scheduled'] ?? fn (): bool => officeJobSchedule('moverelli')['enabled'],
        'gate'          => $h['gate'] ?? [],         // embyRunGate()'s options (the tests: sleep, now, array, max)
        'look_every'    => $h['look_every'] ?? EMBY_MOVER_LOOK,     // during a real run: is Unraid's mover at work? every … s
        'waitdir'       => $h['waitdir'] ?? RUN_DIR,
        'write_state'   => $h['write_state'] ?? true,
        'hired'         => $h['hired'] ?? fn (): bool => moverelliHired(),
        'lock_wait'     => $h['lock_wait'] ?? 30,  // s: the start lock she shares with Jack Emby (embyMoversLock())
        // other movers on a schedule of their own (moverelliForeign()): Mover Tuning, User Scripts, plugins' cron lines
        'tuning'        => $h['tuning'] ?? fn (): bool => (embyMoverHost()['installed'])(),
        'tuning_dir'    => $h['tuning_dir'] ?? EMBY_TUNING_DIR,
        'us_dir'        => $h['us_dir'] ?? US_DIR,
        'cron_glob'     => $h['cron_glob'] ?? '/boot/config/plugins/*/*.cron',
        'unraid_cron'   => $h['unraid_cron'] ?? EMBY_MOVER_CRON,
    ];
}

// ===================================================================== fit

/** Unraid 7.3.2 or newer — a pre-release of 7.3.2 itself counts as older (version_compare: 7.3.2-rc.1 < 7.3.2) */
function moverelliUnraidOk(string $version): bool
{
    return $version !== '' && version_compare($version, MOVERELLI_UNRAID, '>=');
}

/** Would she fit here? Unraid 7.3.2+; with Unraid's own mover schedule on, dry runs only until it is off */
function moverelliFit(?array $h = null): array
{
    $h ??= moverelliHost();
    $v = ($h['version'])();
    if ($v === '') {
        return fit(false, 'unraid_unknown', ['needed' => MOVERELLI_UNRAID]);
    }
    if (!moverelliUnraidOk($v)) {
        return fit(false, 'unraid_old', ['version' => $v, 'needed' => MOVERELLI_UNRAID]);
    }
    $f = moverelliForeign($h);
    return fit(true, $f['unraid'] === null && !$f['tuning']['active'] ? 'yes' : 'yes_dry', ['version' => $v]);
}

/** Is she hired (and not in training — then nobody can hire her)? */
function moverelliHired(): bool
{
    return empty(readJson(OFFICE_WEB . '/desks/moverelli/desk.json')['training'])
        && in_array('moverelli', staffHired($GLOBALS['agentStaffFile'] ?? null), true);
}

// ===================================================================== other movers

/**
 * The other movers on a schedule of their own: Unraid's (its schedule, or null when «Disabled»), Mover Tuning's (installed;
 * the active lines of its plugin folder's cron files — Unraid installs every plugin's *.cron into root's crontab: its
 * forced move «mover.php force start» and whatever else it schedules there), and User Scripts or other plugins' cron
 * lines that call a mover (MOVERELLI_FOREIGN; the office's own file, Unraid's mover.cron and Mover Tuning's are
 * reported above, not here). Read anew at every look.
 */
function moverelliForeign(array $h): array
{
    $own = ($h['unraid'])();
    $tuning = ['installed' => (bool) ($h['tuning'])(), 'lines' => []];
    if ($tuning['installed']) {
        foreach (glob("$h[tuning_dir]/*.cron") ?: [] as $file) {
            foreach (explode("\n", embyActiveText((string) @file_get_contents($file, false, null, 0, 65536))) as $line) {
                // a cron line: five time fields (or @daily …) and a command — never a VAR=value line
                if (preg_match(MOVERELLI_CRON_LINE, trim($line)) && ($cron = embyMoverCronLine($line, '/\S/')) !== null) {
                    $tuning['lines'][] = ['file' => basename($file), 'cron' => $cron];
                }
            }
        }
    }
    $tuning['active'] = (bool) $tuning['lines'];
    $skip = array_merge([OFFICE_CRON, $h['unraid_cron']], glob("$h[tuning_dir]/*.cron") ?: []);
    $scripts = array_values(array_filter(officeForeignCalls(MOVERELLI_FOREIGN, $h['us_dir'], $h['cron_glob'], $skip), fn ($f) => $f['enabled']));
    return ['unraid' => embyMoverScheduleOff($own) ? null : (string) ($own['cron'] ?? $own['set']), 'tuning' => $tuning,
            'scripts' => array_values(array_unique(array_column($scripts, 'where')))];
}

// ===================================================================== the INI

/**
 * smart_mover.ini as Smart Mover reads it (get_ini_val): per section the first value of each key, both trimmed;
 * comment lines (# ;) left out, CRLF tolerated. null: there is none.
 *
 * @return ?array<string, array<string, string>>
 */
function moverelliIniRead(string $file): ?array
{
    if (!is_file($file) || is_link($file)) {
        return null;
    }
    $out = [];
    $sec = null;
    foreach (preg_split('/\r?\n/', (string) @file_get_contents($file, false, null, 0, 1 << 20)) ?: [] as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || $t[0] === ';') {
            continue;
        }
        if ($t[0] === '[') {
            $sec = substr($t, 1, -1);
            $out[$sec] ??= [];
            continue;
        }
        $i = strpos($t, '=');
        if ($sec === null || $i === false) {
            continue;
        }
        $k = trim(substr($t, 0, $i));
        if (!array_key_exists($k, $out[$sec])) {
            $out[$sec][$k] = trim(substr($t, $i + 1));
        }
    }
    return $out;
}

/** Her own exclude list for a scope (null: the global one) — in her data folder */
function moverelliOwnFile(string $dir, ?string $share): string
{
    return "$dir/excludes/" . ($share === null ? 'global' : "share-$share") . '.txt';
}

/** Is this path one of her own lists (as the agent reaches the data folder, or as the user set it)? */
function moverelliIsOwn(string $path, string $dir): bool
{
    return in_array(dirname($path), ["$dir/excludes", dataPathUser("$dir/excludes")], true);
}

/** A comma list of Smart Mover's (global_excludes, excludes=) as files */
function moverelliList(string $v): array
{
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($f) => $f !== ''));
}

/** A whole number from the INI within its range, else null */
function moverelliIniInt(?string $v, string $key): ?int
{
    if ($v === null || !preg_match('/^\d{1,6}$/D', $v)) {
        return null;
    }
    [$min, $max] = MOVERELLI_RANGES[$key];
    $n = (int) $v;
    return $n >= $min && $n <= $max ? $n : null;
}

/**
 * Her settings as the page sees them, from her smart_mover.ini and her own lists: {global: {min_age, age_stat,
 * move_when_used_above, move_prefer_shares, mover_debug, max_list, files, patterns}, shares: {share: {skip, min_age,
 * age_stat, move_when_used_above (null: as global), files, patterns}}}. `files` are the other exclude files the ini
 * names, `patterns` the lines of her own list. null: no ini yet.
 */
function moverelliSettings(string $dir): ?array
{
    $ini = moverelliIniRead("$dir/smart_mover.ini");
    if ($ini === null) {
        return null;
    }
    $split = function (string $v, ?string $share) use ($dir): array {
        $files = $patterns = [];
        foreach (moverelliList($v) as $f) {
            if (moverelliIsOwn($f, $dir)) {
                $patterns = moverelliOwnLines(moverelliOwnFile($dir, $share));
            } else {
                $files[] = $f;
            }
        }
        return [$files, $patterns];
    };
    $g = $ini['GLOBAL'] ?? [];
    [$files, $patterns] = $split((string) ($g['global_excludes'] ?? ''), null);
    $out = ['global' => [
        'min_age'              => moverelliIniInt($g['min_age'] ?? null, 'min_age') ?? MOVERELLI_DEFAULTS['min_age'],
        'age_stat'             => in_array($g['age_stat'] ?? '', ['ctime', 'mtime'], true) ? $g['age_stat'] : MOVERELLI_DEFAULTS['age_stat'],
        'move_when_used_above' => moverelliIniInt($g['move_when_used_above'] ?? null, 'move_when_used_above') ?? MOVERELLI_DEFAULTS['move_when_used_above'],
        'move_prefer_shares'   => ($g['move_prefer_shares'] ?? 'yes') === 'yes',
        'mover_debug'          => moverelliIniInt($g['mover_debug'] ?? null, 'mover_debug') ?? MOVERELLI_DEFAULTS['mover_debug'],
        'max_list'             => moverelliIniInt($g['max_list'] ?? null, 'max_list') ?? MOVERELLI_DEFAULTS['max_list'],
        'files'                => $files,
        'patterns'             => $patterns,
    ], 'shares' => []];
    foreach ($ini as $sec => $v) {
        if ($sec === 'GLOBAL' || !preg_match(MOVERELLI_SHARE, (string) $sec)) {
            continue;
        }
        [$files, $patterns] = $split((string) ($v['excludes'] ?? ''), (string) $sec);
        $out['shares'][$sec] = [
            'skip'                 => ($v['skip'] ?? '') === 'yes',
            'min_age'              => moverelliIniInt($v['min_age'] ?? null, 'min_age'),
            'age_stat'             => in_array($v['age_stat'] ?? '', ['ctime', 'mtime'], true) ? $v['age_stat'] : null,
            'move_when_used_above' => moverelliIniInt($v['move_when_used_above'] ?? null, 'move_when_used_above'),
            'files'                => $files,
            'patterns'             => $patterns,
        ];
    }
    return $out;
}

/** Smart Mover's defaults — what her setup page starts from */
function moverelliDefaults(): array
{
    return ['global' => MOVERELLI_DEFAULTS + ['files' => [], 'patterns' => []], 'shares' => []];
}

/** The lines of one of her own lists */
function moverelliOwnLines(string $file): array
{
    $text = (string) @file_get_contents($file, false, null, 0, 1 << 20);
    return $text === '' ? [] : array_values(preg_split('/\r?\n/', rtrim($text, "\r\n")) ?: []);
}

/**
 * An exclude file the ini may name: absolute, no `,` (Smart Mover's lists are comma lists), no control characters,
 * no `.`/`..` or empty parts, nothing trimmed away; not one of her own lists (those she keeps herself).
 */
function moverelliPathOk(string $path, string $dir): bool
{
    if (!preg_match('~^/[^,\x00-\x1f\x7f]{1,1023}$~D', $path) || trim($path) !== $path || moverelliIsOwn($path, $dir)) {
        return false;
    }
    foreach (explode('/', substr($path, 1)) as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/** Every user share (by its cfg on the flash) she can write a section for */
function moverelliShareNames(string $sharesDir): array
{
    $out = [];
    foreach (glob("$sharesDir/*.cfg") ?: [] as $f) {
        $name = basename($f, '.cfg');
        if (preg_match(MOVERELLI_SHARE, $name)) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

/**
 * The page's settings checked, every value: numbers in their range, age_stat ctime|mtime, switches true/false, files
 * (moverelliPathOk(), at most MOVERELLI_FILES_MAX), her own lines (no control characters, ≤ 1000 bytes each, at most
 * MOVERELLI_LINES_MAX), shares only those with a cfg. Refused with what is wrong (`moverelli_bad_value`).
 */
function moverelliCheck(mixed $in, array $shares, string $dir): array
{
    $bad = fn (string $field, mixed $value = '') => new Problem('moverelli_bad_value', ['field' => $field, 'value' => mb_substr(is_scalar($value) ? (string) $value : json_encode($value), 0, 120)]);
    if (!is_array($in) || !is_array($in['global'] ?? null) || !is_array($in['shares'] ?? null)) {
        throw new Problem('bad_request');
    }
    $int = function (array $a, string $key, string $field, bool $optional) use ($bad): ?int {
        $v = $a[$key] ?? null;
        if ($v === null && $optional) {
            return null;
        }
        [$min, $max] = MOVERELLI_RANGES[$key];
        if (!is_int($v) || $v < $min || $v > $max) {
            throw $bad($field, $v);
        }
        return $v;
    };
    $stat = function (array $a, string $field, bool $optional) use ($bad): ?string {
        $v = $a['age_stat'] ?? null;
        if ($v === null && $optional) {
            return null;
        }
        if (!in_array($v, ['ctime', 'mtime'], true)) {
            throw $bad($field, $v);
        }
        return $v;
    };
    $lists = function (array $a, string $field) use ($bad, $dir): array {
        $files = $a['files'] ?? [];
        $lines = $a['patterns'] ?? [];
        if (!is_array($files) || count($files) > MOVERELLI_FILES_MAX || !is_array($lines) || count($lines) > MOVERELLI_LINES_MAX) {
            throw $bad($field);
        }
        $okFiles = [];
        foreach ($files as $f) {
            if (!is_string($f) || !moverelliPathOk($f, $dir)) {
                throw $bad("$field.files", $f);
            }
            $okFiles[$f] = true;
        }
        $okLines = [];
        foreach ($lines as $l) {
            if (!is_string($l) || strlen($l) > 1000 || preg_match('/[\x00-\x1f\x7f]/', $l)) {
                throw $bad("$field.patterns", $l);
            }
            $okLines[] = $l;
        }
        while ($okLines && trim(end($okLines)) === '') {
            array_pop($okLines);
        }
        return [array_keys($okFiles), $okLines];
    };
    $g = $in['global'];
    if (!is_bool($g['move_prefer_shares'] ?? null)) {
        throw $bad('move_prefer_shares', $g['move_prefer_shares'] ?? '');
    }
    [$files, $patterns] = $lists($g, 'global');
    $out = ['global' => [
        'min_age'              => $int($g, 'min_age', 'min_age', false),
        'age_stat'             => $stat($g, 'age_stat', false),
        'move_when_used_above' => $int($g, 'move_when_used_above', 'move_when_used_above', false),
        'move_prefer_shares'   => $g['move_prefer_shares'],
        'mover_debug'          => $int($g, 'mover_debug', 'mover_debug', false),
        'max_list'             => $int($g, 'max_list', 'max_list', false),
        'files'                => $files,
        'patterns'             => $patterns,
    ], 'shares' => []];
    foreach ($in['shares'] as $share => $s) {
        $share = (string) $share;            // JSON's «"2024": {…}» arrives as an int key
        if (!preg_match(MOVERELLI_SHARE, $share) || !in_array($share, $shares, true)) {
            throw new Problem('moverelli_bad_share', ['share' => mb_substr((string) $share, 0, 80)]);
        }
        if (!is_array($s) || !is_bool($s['skip'] ?? null)) {
            throw $bad("$share.skip");
        }
        [$files, $patterns] = $lists($s, $share);
        $row = [
            'skip'                 => $s['skip'],
            'min_age'              => $int($s, 'min_age', "$share.min_age", true),
            'age_stat'             => $stat($s, "$share.age_stat", true),
            'move_when_used_above' => $int($s, 'move_when_used_above', "$share.move_when_used_above", true),
            'files'                => $files,
            'patterns'             => $patterns,
        ];
        // a share without anything of its own needs no section
        if ($row['skip'] || $row['min_age'] !== null || $row['age_stat'] !== null || $row['move_when_used_above'] !== null || $files || $patterns) {
            $out['shares'][$share] = $row;
        }
    }
    ksort($out['shares'], SORT_STRING);
    return $out;
}

/** The ini's text for checked settings: a scope with lines of her own names her list for it after its other files */
function moverelliIniText(array $set, string $dir): string
{
    $list = function (array $files, ?string $share, array $patterns) use ($dir): string {
        if ($patterns) {
            $files[] = dataPathUser(moverelliOwnFile($dir, $share));
        }
        return implode(',', $files);
    };
    $g = $set['global'];
    $text = "# Ms. Moverelli (Unraid Secretary Office) - written by the office; change it on her setup page\n[GLOBAL]\n"
          . 'log_file=' . dataPathUser("$dir/smart_mover.log") . "\n"
          . "min_age={$g['min_age']}\nage_stat={$g['age_stat']}\nmove_when_used_above={$g['move_when_used_above']}\n"
          . 'move_prefer_shares=' . ($g['move_prefer_shares'] ? 'yes' : 'no') . "\nmover_debug={$g['mover_debug']}\nmax_list={$g['max_list']}\n";
    if (($l = $list($g['files'], null, $g['patterns'])) !== '') {
        $text .= "global_excludes=$l\n";
    }
    foreach ($set['shares'] as $share => $s) {
        $share = (string) $share;
        $text .= "\n[$share]\n";
        if ($s['skip']) {
            $text .= "skip=yes\n";
        }
        foreach (['min_age', 'age_stat', 'move_when_used_above'] as $k) {
            if ($s[$k] !== null) {
                $text .= "$k={$s[$k]}\n";
            }
        }
        if (($l = $list($s['files'], $share, $s['patterns'])) !== '') {
            $text .= "excludes=$l\n";
        }
    }
    return $text;
}

/** Her data folder: settings, lists and logs name files of the shares — root only */
function moverelliDataDir(string $dir): void
{
    @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    @chown($dir, 0);
}

/**
 * Writes checked settings: her own lists first (each a new file + rename; lists no scope uses any more go), then the
 * ini (a new file + rename) — Smart Mover never reads half a file.
 */
function moverelliWrite(array $set, string $dir): void
{
    moverelliDataDir($dir);
    @mkdir("$dir/excludes", 0700, true);
    $keep = [];
    $own = [['', $set['global']['patterns']]];
    foreach ($set['shares'] as $share => $s) {
        $own[] = [(string) $share, $s['patterns']];          // a share named «2024» is an int key in PHP
    }
    foreach ($own as [$share, $lines]) {
        if ($lines) {
            $file = moverelliOwnFile($dir, $share === '' ? null : $share);
            writeAtomic($file, implode("\n", $lines) . "\n", 0600, 0, 0);
            $keep[$file] = true;
        }
    }
    // the ini first (it names only lists that exist now), then the lists no scope uses any more go
    writeAtomic("$dir/smart_mover.ini", moverelliIniText($set, $dir), 0600, 0, 0);
    foreach (glob("$dir/excludes/*.txt") ?: [] as $f) {
        if (!isset($keep[$f])) {
            @unlink($f);
        }
    }
}

/** moverelli.save: checked, refused while a run of hers goes, written; logged */
function moverelliSave(mixed $in, ?array $h = null): array
{
    $h ??= moverelliHost();
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'settings']);
    }
    if (moverelliBusy($h['dir'])) {
        throw new Problem('moverelli_running');
    }
    $set = moverelliCheck($in, moverelliShareNames($h['shares_dir']), $h['dir']);
    // her run's lock held while writing: a run can't start meanwhile, and one that holds it refuses the save
    moverelliDataDir($h['dir']);
    $lock = @fopen("$h[dir]/office-run.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        throw new Problem('moverelli_running');
    }
    try {
        moverelliWrite($set, $h['dir']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    logLine('Ms. Moverelli: Smart Mover settings saved (' . count($set['shares']) . ' shares with rules of their own)');
    return ['ok' => true, 'state' => moverelliScan($h)];
}

/**
 * What her ini keeps out, for Jack Emby's rule (embyMoverRule()): null while she isn't hired, else {global: [files],
 * shares: {share: {skip, excludes: [files]}}} — her own lists among the files.
 */
function moverelliExcludeLook(?string $dir = null): ?array
{
    return moverelliHired() ? moverelliExcludeFrom($dir ?? MOVERELLI_DATA) : null;
}

function moverelliExcludeFrom(string $dir): array
{
    $ini = moverelliIniRead("$dir/smart_mover.ini") ?? [];
    $out = ['global' => moverelliList((string) ($ini['GLOBAL']['global_excludes'] ?? '')), 'shares' => []];
    foreach ($ini as $sec => $v) {
        if ($sec !== 'GLOBAL') {
            $out['shares'][$sec] = ['skip' => ($v['skip'] ?? '') === 'yes', 'excludes' => moverelliList((string) ($v['excludes'] ?? ''))];
        }
    }
    return $out;
}

/** While Jack Emby is hired: EmbyCache's list (both forms of its path) and his shares — for her page and her rule */
function moverelliJackLists(): ?array
{
    if (!in_array('emby', staffHired($GLOBALS['agentStaffFile'] ?? null), true)) {
        return null;
    }
    $h = embyMoverHost();
    return ['lists' => ($h['lists'])(), 'shares' => ($h['shares'])()];
}

// ===================================================================== state

/** Smart Mover's version (its header: «Smart Mover for Unraid (custommover_run.sh) – V7») */
function moverelliEngineVersion(string $app): ?string
{
    $head = (string) @file_get_contents("$app/custommover_run.sh", false, null, 0, 1024);
    return preg_match('/custommover_run\.sh\)\s+\S+\s+(V[\d.]+)/u', $head, $m) ? $m[1] : null;
}

/**
 * The share table as Smart Mover sees it (its --list-shares): every share with its cfg — use, primary, secondary —,
 * what moves where (`yes`: primary → secondary or the array; `prefer`: back to the primary; else nothing) and the rules
 * that hold for it (its own, else the global ones; a `prefer` share's age is 0 unless its own).
 */
function moverelliShares(array $set, string $sharesDir): array
{
    $g = $set['global'];
    $out = [];
    foreach (moverelliShareNames($sharesDir) as $share) {
        $cfg = readCfg("$sharesDir/$share.cfg");
        $use = (string) ($cfg['shareUseCache'] ?? '');
        $primary = (string) ($cfg['shareCachePool'] ?? '');
        $secondary = (string) ($cfg['shareCachePool2'] ?? '');
        $own = $set['shares'][$share] ?? null;
        $row = ['share' => $share, 'use' => $use, 'primary' => $primary, 'secondary' => $secondary, 'own' => $own !== null,
                'mode' => in_array($use, ['yes', 'prefer'], true) ? $use : 'none', 'from' => null, 'to' => null];
        if ($row['mode'] !== 'none') {
            $pool = $primary !== '' ? $primary : 'cache';            // Smart Mover's default
            [$row['from'], $row['to']] = $use === 'yes' ? [$pool, $secondary !== '' ? $secondary : 'array'] : [$secondary !== '' ? $secondary : 'array', $pool];
        }
        $row['state'] = match (true) {
            $row['mode'] === 'none'                           => 'none',
            (bool) ($own['skip'] ?? false)                    => 'skip',
            $use === 'prefer' && !$g['move_prefer_shares']    => 'prefer_off',
            default                                           => 'moves',
        };
        $row['min_age'] = $own['min_age'] ?? ($use === 'prefer' ? 0 : $g['min_age']);
        $row['age_stat'] = $own['age_stat'] ?? $g['age_stat'];
        $row['threshold'] = $use === 'yes' ? ($own['move_when_used_above'] ?? $g['move_when_used_above']) : null;
        $row['excludes'] = count($g['files']) + ($g['patterns'] ? 1 : 0) + count($own['files'] ?? []) + (($own['patterns'] ?? []) ? 1 : 0);
        $out[] = $row;
    }
    return $out;
}

/** Her run started last: mode, who, when, still at work? */
function moverelliJobInfo(string $dir): array
{
    $info = readJson("$dir/office-run.json") ?? [];
    $pid = (int) ($info['pid'] ?? 0);
    $info['running'] = empty($info['finished']) && $pid > 1 && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php');
    $info['size'] = (int) @filesize("$dir/office-output.txt");
    return $info;
}

/** Is a run of hers going — her job, or a lock of hers held (her job's, Smart Mover's own)? */
function moverelliBusy(string $dir = MOVERELLI_DATA): bool
{
    return moverelliJobInfo($dir)['running'] || flockHeld("$dir/office-run.lock") || flockHeld("$dir/smartmover.lock");
}

/** Jack Emby's shares her ini doesn't keep EmbyCache's list away from (none while he isn't hired) */
function moverelliJackUncovered(?array $emby, string $dir): array
{
    return $emby === null ? [] : embyMoverelliUncovered(moverelliExcludeFrom($dir), $emby['lists'], $emby['shares']);
}

function moverelliScan(?array $h = null): array
{
    $h ??= moverelliHost();
    $dir = $h['dir'];
    $settings = moverelliSettings($dir);
    $version = ($h['version'])();
    $own = ($h['unraid'])();
    $emby = ($h['emby'])();
    $job = moverelliJobInfo($dir);
    $stop = $job['running'] ? readJson("$dir/office-stop.json") : null;
    $state = [
        'time'       => time(),
        'unraid'     => ['version' => $version, 'ok' => moverelliUnraidOk($version), 'needed' => MOVERELLI_UNRAID],
        'engine'     => moverelliEngineVersion($h['app']),
        'configured' => $settings !== null,
        // shares as an object for the page: a share named «0» or «2024» must not turn the map into a list
        'settings'   => ['global' => ($settings ?? moverelliDefaults())['global'], 'shares' => (object) ($settings ?? moverelliDefaults())['shares']],
        'shares'     => moverelliShares($settings ?? moverelliDefaults(), $h['shares_dir']),
        // Unraid's own mover: its schedule (real runs only while «Disabled»), at work right now
        'mover'      => ['off' => embyMoverScheduleOff($own), 'schedule' => $own['cron'] ?? ($own['set'] !== '' ? $own['set'] : null),
                         'running' => ($h['mover_running'])($job['running'] ? (int) $job['pid'] : null)],     // her own run's move binary isn't Unraid's mover
        // other movers on a schedule of their own: Mover Tuning's (refuses real runs), scripts that call a mover (named)
        'foreign'    => array_diff_key(moverelliForeign($h), ['unraid' => 1]),
        'job'        => $job,
        'stopping'   => $stop !== null ? (($stop['why'] ?? '') === 'mover' ? 'mover' : 'user') : null,
        'last'       => readJson("$dir/status.json"),
        'history'    => array_slice(moverelliHistory($dir), 0, 20),
        'schedule'   => officeJobSchedule('moverelli'),
        // Jack Emby: a run of his going (hers wait), EmbyCache's list and his shares her ini doesn't keep it away from
        'jack'       => ['busy' => ($h['jack_busy'])(), 'list' => $emby['lists'][0] ?? null, 'uncovered' => moverelliJackUncovered($emby, $dir)],
    ];
    if ($h['write_state']) {
        writeAtomic(deskFile('moverelli'), jsonEncode($state));
    }
    return $state;
}

// ===================================================================== runs

/** May this run start now? (asked again by the job itself) */
function moverelliRunCheck(string $mode, array $h): void
{
    if (!isset(MOVERELLI_MODES[$mode])) {
        throw new Problem('unknown_target', ['target' => mb_substr($mode, 0, 40)]);
    }
    $v = ($h['version'])();
    if (!moverelliUnraidOk($v)) {
        throw new Problem('moverelli_unraid_old', ['version' => $v !== '' ? $v : '?', 'needed' => MOVERELLI_UNRAID]);
    }
    if (!is_file("$h[dir]/smart_mover.ini")) {
        throw new Problem('moverelli_not_configured');
    }
    if (!is_file("$h[app]/custommover_run.sh")) {
        throw new Problem('moverelli_missing_tool', ['path' => $h['app']]);
    }
    if (moverelliBusy($h['dir'])) {
        throw new Problem('moverelli_running');
    }
    if (($h['jack_busy'])()) {
        throw new Problem('moverelli_emby_running');
    }
    if ($mode !== 'run') {
        return;
    }
    $own = ($h['unraid'])();
    if (!embyMoverScheduleOff($own)) {
        throw new Problem('moverelli_schedule', ['schedule' => $own['cron'] ?? $own['set']]);
    }
    // nor Mover Tuning's own schedule: it would move the same shares
    $tuning = moverelliForeign($h)['tuning'];
    if ($tuning['active']) {
        throw new Problem('moverelli_tuning_schedule', ['schedule' => $tuning['lines'][0]['cron'], 'file' => $tuning['lines'][0]['file']]);
    }
    // Jack Emby hired: EmbyCache's films stay on the pool — his list in her ini for each of his shares
    if ($uncovered = moverelliJackUncovered(($h['emby'])(), $h['dir'])) {
        throw new Problem('moverelli_emby_list', ['shares' => implode(', ', $uncovered)]);
    }
}

/** From the page: through the host's atd as "php agent.php job moverelli <mode> --office" — it lives on without the agent */
function moverelliStart(string $mode): array
{
    $h = moverelliHost();
    moverelliRunCheck($mode, $h);
    if ($mode === 'run' && ($h['mover_running'])()) {
        logLine("Ms. Moverelli: Smart Mover ($mode) not started — Unraid's mover is at work");
        throw new Problem('moverelli_mover_running');
    }
    hostLaunch('moverelli', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'moverelli', $mode, '--office']);
    logLine("Ms. Moverelli: started Smart Mover ($mode) via at");
    usleep(800000);
    return ['ok' => true, 'state' => moverelliScan($h)];
}

/** «Stop after this share»: only while a real run goes — Smart Mover's stop request, why `user`, once */
function moverelliStopAsk(?array $h = null): bool
{
    $h ??= moverelliHost();
    $job = moverelliJobInfo($h['dir']);
    if (!$job['running'] || ($job['mode'] ?? '') !== 'run' || is_file("$h[dir]/office-stop.json")) {
        return false;
    }
    writeAtomic("$h[dir]/office-stop.json", jsonEncode(['time' => time(), 'why' => 'user']), 0600, 0, 0);
    logLine('Ms. Moverelli: asked to stop — Smart Mover starts no further share');
    return true;
}

/**
 * While a real run goes: is Unraid's mover at work (not counting the move binary below this job)? every $every s —
 * then the stop file once (why `mover`): Smart Mover starts no further share. A stop file it didn't write (her page)
 * is noticed and its why taken (`user`). Returns the exit code and why it was asked to stop.
 *
 * @param resource $proc
 * @return array{exit: int, why: ?string}
 */
function moverelliRunWatch($proc, string $stopFile, callable $mover, int $every = EMBY_MOVER_LOOK): array
{
    $why = null;
    $last = time();
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            proc_close($proc);
            return ['exit' => (int) $st['exitcode'], 'why' => $why];
        }
        if ($why === null) {
            clearstatcache(true, $stopFile);
            if (is_file($stopFile)) {
                $why = (readJson($stopFile)['why'] ?? '') === 'mover' ? 'mover' : 'user';
            } elseif (time() - $last >= $every) {
                $last = time();
                try {
                    $on = (bool) $mover();
                } catch (Throwable) {
                    $on = false;
                }
                if ($on) {
                    $why = 'mover';
                    writeAtomic($stopFile, jsonEncode(['time' => time(), 'why' => 'mover']), 0600, 0, 0);
                    logLine('Ms. Moverelli: Unraid\'s mover started — Smart Mover starts no further share');
                }
            }
        }
        usleep(200000);
    }
}

/**
 * "php agent.php job moverelli [list|dry|run] [--office]" — what her schedule (job.sh: a real run) and the page call.
 * Checked again here; a real run passes the gate (Unraid's mover at work: refused from the page, on schedule it waits
 * like Jack's — embyRunGate()), holds her lock for the whole run, and stops before the next share when the mover starts.
 * Records the run, keeps its output, tells Unraid's notifications when a real run went wrong. Returns the exit code.
 */
function moverelliJob(array $args, ?array $h = null): int
{
    $h ??= moverelliHost();
    $dir = $h['dir'];
    $by = in_array('--office', $args, true) ? 'office' : 'schedule';
    $args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
    $mode = $args[0] ?? 'run';
    // let go (or never hired): a schedule line left in the office's cron file moves nothing — said in the log only
    if (!($h['hired'])()) {
        logLine("Ms. Moverelli: Smart Mover ($mode) not started — she isn't hired (a schedule left behind does nothing)");
        fwrite(STDERR, "moverelli: not started (not hired)\n");
        return 1;
    }
    $refused = function (string $why, array $params = []) use ($mode, $by, $dir): int {
        moverelliRemember(['mode' => $mode, 'by' => $by, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $why]
            + array_intersect_key($params, ['schedule' => 1, 'shares' => 1, 'version' => 1, 'file' => 1]), $dir);
        logLine("Ms. Moverelli: Smart Mover ($mode) not started ($why)");
        fwrite(STDERR, "moverelli: not started ($why)\n");
        return 1;
    };
    try {
        moverelliRunCheck($mode, $h);
    } catch (Problem $p) {
        if ($p->key === 'moverelli_not_configured' || $p->key === 'unknown_target') {
            fwrite(STDERR, "moverelli: not started ($p->key)\n");
            return 1;
        }
        return $refused($p->key, $p->params);
    }
    $real = $mode === 'run';
    $wait = null;
    $note = [];
    if ($real) {
        $asked = time();
        $gate = embyRunGate('moverelli', $by, ['watch' => false, 'dir' => $dir, 'waitdir' => $h['waitdir'],
            'mover' => fn (): bool => ($h['mover_running'])(), 'scheduled' => $h['scheduled']] + $h['gate']);
        if (!$gate['go']) {
            if (in_array($gate['result'], ['refused', 'skipped'], true)) {
                moverelliRemember(['mode' => $mode, 'by' => $by, 'started' => $asked, 'finished' => time(), 'result' => $gate['result'],
                                   'why' => 'moverelli_mover_running'] + ($gate['waited'] > 0 ? ['waited' => $gate['waited']] : []), $dir);
                logLine("Ms. Moverelli: Smart Mover (run) " . ($gate['result'] === 'skipped' ? 'skipped' : 'not started') . " — Unraid's mover is at work");
                return $gate['result'] === 'skipped' ? 0 : 1;
            }
            logLine("Ms. Moverelli: the scheduled run stops waiting ({$gate['result']})");
            return 0;
        }
        $wait = $gate['lock'];
        if ($gate['waited'] > 0) {
            $note['waited'] = $gate['waited'];
        }
        try {
            moverelliRunCheck($mode, $h);          // after a wait: still nothing else at work?
        } catch (Problem $p) {
            embyWaitEnd($wait, $h['waitdir'], 'moverelli');
            return $refused($p->key, $p->params);
        }
    }
    moverelliDataDir($dir);
    // the start lock she shares with Jack Emby: his runs' check and marker and hers never interleave (two cron jobs in
    // the same minute) — inside it: none of his going, her own lock taken, her office-run.json written
    $movers = embyMoversLock((int) $h['lock_wait']);
    if ($movers === null || ($h['jack_busy'])()) {
        embyMoversUnlock($movers);
        embyWaitEnd($wait, $h['waitdir'], 'moverelli');
        return $refused('moverelli_emby_running');
    }
    $hold = @fopen("$dir/office-run.lock", 'c');
    if (!$hold || !flock($hold, LOCK_EX | LOCK_NB)) {
        embyMoversUnlock($movers);
        embyWaitEnd($wait, $h['waitdir'], 'moverelli');
        return $refused('moverelli_running');
    }
    $started = time();
    $run = ['mode' => $mode, 'by' => $by, 'started' => $started, 'pid' => getmypid()];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    embyMoversUnlock($movers);
    embyWaitEnd($wait, $h['waitdir'], 'moverelli');
    @unlink("$dir/status.json");
    @unlink("$dir/office-stop.json");
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8',
            'CUSTOMMOVER_INI' => "$dir/smart_mover.ini", 'CUSTOMMOVER_LOG' => "$dir/smart_mover.log", 'CUSTOMMOVER_LOCK' => "$dir/smartmover.lock",
            'CUSTOMMOVER_STATUS' => "$dir/status.json", 'CUSTOMMOVER_STOP' => "$dir/office-stop.json",
            'CUSTOMMOVER_SHARES_DIR' => $h['shares_dir'], 'CUSTOMMOVER_MNT' => $h['mnt'], 'OFFICE_RUN_DIR' => RUN_DIR]
         + ($real ? ['CUSTOMMOVER_EXCLUDES_REQUIRED' => '1'] : []) + $h['env'];     // a real run: a list that is gone skips its share
    $out = fopen("$dir/office-output.txt", 'w');
    $proc = proc_open(array_merge(['bash', "$h[app]/custommover_run.sh"], MOVERELLI_MODES[$mode]), [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, '/', $env);
    $why = null;
    if (!is_resource($proc)) {
        $exit = 127;
    } elseif ($real) {
        $self = getmypid();
        ['exit' => $exit, 'why' => $why] = moverelliRunWatch($proc, "$dir/office-stop.json", fn (): bool => ($h['mover_running'])($self), (int) $h['look_every']);
    } else {
        $exit = proc_close($proc);
    }
    fclose($out);
    @unlink("$dir/office-stop.json");
    $status = readJson("$dir/status.json") ?? [];
    $run += ['finished' => time(), 'exit' => $exit];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    flock($hold, LOCK_UN);
    fclose($hold);
    $result = (string) ($status['result'] ?? ($exit === 0 ? 'ok' : 'failed'));
    if ($result === 'stopped') {
        $note['why'] = $why === 'mover' ? 'mover' : 'user';
    }
    moverelliRemember(['mode' => $mode, 'by' => $by, 'started' => $started, 'finished' => time(), 'exit' => $exit, 'result' => $result,
                       'status' => moverelliStatusShort($status)] + $note, $dir);
    logLine("Ms. Moverelli: Smart Mover ($mode) $result" . ($mode === 'run' ? ' — ' . (int) ($status['moved'] ?? 0) . ' moved, '
        . (int) ($status['left'] ?? 0) . ' left' : '') . (isset($note['why']) ? " (stopped: {$note['why']})" : ''));
    try {
        moverelliNotify($mode, $result, $status, $exit, $note['why'] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, "moverelli: notification failed: {$e->getMessage()}\n");
    }
    return $exit;
}

/** A run's status for her list of runs: the totals, per share only what it did and its counts */
function moverelliStatusShort(array $status): array
{
    $out = array_intersect_key($status, array_flip(['mode', 'result', 'files', 'bytes', 'moved', 'left', 'errors', 'stop_share', 'message']));
    $out['shares'] = [];
    foreach (array_slice((array) ($status['shares'] ?? []), 0, 200) as $s) {
        if (is_array($s)) {
            $out['shares'][] = array_intersect_key($s, array_flip(['share', 'state', 'files', 'bytes', 'moved', 'left']));
        }
    }
    return $out;
}

/** Her list of runs, newest first */
function moverelliHistory(string $dir = MOVERELLI_DATA): array
{
    return (array) (readJson("$dir/office-history.json")['runs'] ?? []);
}

/** A run (or one that didn't start) into her list of runs — a scheduled one refused again for the same reason counts up */
function moverelliRemember(array $entry, string $dir = MOVERELLI_DATA): void
{
    moverelliDataDir($dir);
    $lock = fopen("$dir/office-history.lock", 'c');
    flock($lock, LOCK_EX);
    $runs = moverelliHistory($dir);
    $top = $runs[0] ?? null;
    $same = array_flip(['mode', 'by', 'result', 'why']);
    if (($entry['result'] ?? '') === 'refused' && ($entry['by'] ?? '') === 'schedule' && is_array($top)
        && array_intersect_key($top, $same) == array_intersect_key($entry, $same)) {
        $entry += ['first' => (int) ($top['first'] ?? $top['started'] ?? 0), 'times' => (int) ($top['times'] ?? 1) + 1];
        array_shift($runs);
    }
    $runs = array_slice(array_merge([$entry], $runs), 0, MOVERELLI_HISTORY);
    writeAtomic("$dir/office-history.json", jsonEncode(['runs' => $runs]), 0600, 0, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/**
 * How a real run ended, if Unraid should hear of it: failed, config, busy (another Smart Mover or the stock mover in the
 * way), errors (done, with problems), stopped_mover (Unraid's mover started: a normal note). Lists and dry runs stay
 * quiet, so do runs that never started and one the user stopped on her page (unless it had errors).
 */
function moverelliNotifyOutcome(string $mode, string $result, array $status, ?string $why = null): ?string
{
    if ($mode !== 'run') {
        return null;
    }
    if ($result === 'stopped') {
        return $why === 'mover' ? 'stopped_mover' : ((int) ($status['errors'] ?? 0) > 0 ? 'errors' : null);
    }
    if (in_array($result, ['failed', 'config', 'busy', 'errors'], true)) {
        return $result;
    }
    return null;
}

/** A real run that went wrong goes to Unraid's notifications (warning) — from Smart Mover's status file, never its log */
function moverelliNotify(string $mode, string $result, array $status, int $exit, ?string $why = null): bool
{
    $outcome = moverelliNotifyOutcome($mode, $result, $status, $why);
    if ($outcome === null) {
        return false;
    }
    $lang = officeNotifyLang();
    $detail = match (true) {
        !$status                     => officeNotifyText('moverelli', 'notify.no_status', ['exit' => $exit], $lang),
        $outcome === 'stopped_mover' => officeNotifyText('moverelli', 'notify.stopped_mover', ['n' => (int) ($status['moved'] ?? 0)], $lang),
        default                      => officeNotifyText('moverelli', 'notify.counts', ['moved' => (int) ($status['moved'] ?? 0),
                                            'left' => (int) ($status['left'] ?? 0), 'errors' => (int) ($status['errors'] ?? 0)], $lang)
                                        . (trim((string) ($status['message'] ?? '')) !== '' ? ' ' . trim((string) $status['message']) : ''),
    };
    $sent = officeNotify(officeNotifyText('moverelli', 'notify.subject', ['result' => officeNotifyText('moverelli', "result.$outcome", [], $lang)], $lang),
        trim($detail . ' ' . officeNotifyText('moverelli', 'notify.see', [], $lang)), $outcome === 'stopped_mover' ? 'normal' : 'warning', '',
        officeNotifyLink('#/moverelli'));
    if ($sent) {
        logLine("Ms. Moverelli: told Unraid's notifications — Smart Mover ($mode) $outcome");
    }
    return $sent;
}

/** The output of her last run, as plain text */
function moverelliOutput(?array $h = null): array
{
    $h ??= moverelliHost();
    $text = (string) @file_get_contents("$h[dir]/office-output.txt", false, null, 0, 2 * 1024 * 1024);
    return ['ok' => true, 'info' => moverelliJobInfo($h['dir']), 'text' => (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text)];
}

/** The end of Smart Mover's log */
function moverelliLog(?array $h = null): array
{
    $h ??= moverelliHost();
    $file = "$h[dir]/smart_mover.log";
    $size = (int) @filesize($file);
    $f = @fopen($file, 'r');
    if (!$f) {
        return ['ok' => true, 'text' => ''];
    }
    fseek($f, max(0, $size - MOVERELLI_LOG_TAIL));
    $text = (string) stream_get_contents($f);
    fclose($f);
    return ['ok' => true, 'text' => $text, 'cut' => $size > MOVERELLI_LOG_TAIL];
}

// ===================================================================== Unraid's mover schedule, her schedule

/**
 * moverelli.mover_off {confirm: true}: Unraid's own ⟦Mover schedule⟧ «Disabled» exactly as its form sets it — Jack
 * Emby's embyMoverOff() (emby-mover.php), her name in the log; its refusal in her words.
 */
function moverelliMoverOff(array $r, ?array $host = null): array
{
    try {
        return embyMoverOff($r, $host, fn (string $text) => logLine("Ms. Moverelli: $text"));
    } catch (Problem $p) {
        if ($p->key === 'emby_mover_off_failed') {
            throw new Problem('moverelli_mover_off_failed', $p->params);
        }
        throw $p;
    }
}

/** Her schedule: a line in the office's cron file (a real run); when it was set is noted for the Team Lead */
function moverelliSetSchedule(?string $cron, ?array $h = null): array
{
    $h ??= moverelliHost();
    if ($cron !== null && !is_file("$h[dir]/smart_mover.ini")) {
        throw new Problem('moverelli_not_configured');
    }
    $live = officeJobSetSchedule('moverelli', $cron);
    moverelliDataDir($h['dir']);
    writeAtomic("$h[dir]/office-schedule.json", jsonEncode(['set' => time(), 'cron' => $cron]), 0600, 0, 0);
    logLine('Ms. Moverelli: schedule ' . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => moverelliScan($h)];
}

/**
 * Did a run of hers go lately, with her schedule on? true; false when nothing ran (refused and skipped don't count)
 * for MOVERELLI_STALE since the schedule was set or the last run started; null when neither is known.
 */
function moverelliRanLately(string $dir, ?int $now = null): ?bool
{
    $now ??= time();
    $set = (int) (readJson("$dir/office-schedule.json")['set'] ?? 0);
    $ran = 0;
    foreach (moverelliHistory($dir) as $r) {
        if (is_array($r) && !in_array($r['result'] ?? '', ['refused', 'skipped'], true)) {
            $ran = max($ran, (int) ($r['started'] ?? 0));
        }
    }
    $since = max($set, $ran);
    return $since === 0 ? null : $now - $since <= MOVERELLI_STALE;
}

// ===================================================================== checks (for the Team Lead)

function moverelliChecks(?array $h = null): array
{
    $h ??= moverelliHost();
    $out = [];
    $configured = is_file("$h[dir]/smart_mover.ini");
    $out[] = finding('configured', 'required', $configured, [], '#/moverelli/setup');
    if (!$configured) {
        return $out;
    }
    $scheduled = ($h['scheduled'])();
    $out[] = finding('schedule', 'recommended', $scheduled, [], '#/moverelli/schedule');
    // no other mover on a schedule of its own while she is in charge — else both move the same shares
    $f = moverelliForeign($h);
    $out[] = finding('unraid_schedule', 'required', $f['unraid'] === null, ['schedule' => (string) $f['unraid']], '#/moverelli');
    if ($f['tuning']['installed']) {
        $out[] = finding('tuning_schedule', 'required', !$f['tuning']['active'], ['schedule' => (string) ($f['tuning']['lines'][0]['cron'] ?? '')], '#/moverelli');
    }
    $out[] = finding('foreign_scripts', 'recommended', !$f['scripts'], ['where' => implode(', ', $f['scripts'])], 'userscripts');
    if ($scheduled) {
        $out[] = finding('ran', 'recommended', moverelliRanLately($h['dir']), ['days' => intdiv(MOVERELLI_STALE, 86400)], '#/moverelli');
    }
    $emby = ($h['emby'])();
    if ($emby !== null) {
        $out[] = finding('emby_list', 'required', !moverelliJackUncovered($emby, $h['dir']),
            ['shares' => implode(', ', moverelliJackUncovered($emby, $h['dir'])), 'file' => (string) ($emby['lists'][0] ?? '')], '#/moverelli/setup');
    }
    return $out;
}
