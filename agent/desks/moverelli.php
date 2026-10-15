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
 * jackMoverOff(), jack-mover.php). Never while Unraid's mover (or Mover Tuning's age_mover) is at work, never beside one
 * of Jack Emby's runs (EmbyCache, the gather) — and his never beside hers (jackRunCheck()). While Jack is hired her
 * ini must keep EmbyCache's list out for his shares (jackMoverRule() «moverelli», moverelliRunCheck()).
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
// (2026-10-14: only X — move_until_used_below, the offset and jack_min_percent are gone; officeMigratePoolX() takes them out)
// which date counts (2026-10-14): always the younger of created (birth, else ctime) and modified — Smart Mover's office
// value age_stat=newest, written by the office for all, never a setting
const MOVERELLI_AGE_STAT  = 'newest';
// Unraid's move binary, where Smart Mover looks for it (custommover_run.sh): the office hands it over, an ini never names it
const MOVERELLI_MOVE_BINS = ['/usr/libexec/unraid/move', '/usr/local/sbin/move', '/usr/local/bin/move'];
const MOVERELLI_DEFAULTS  = ['min_age' => 0, 'move_when_used_above' => 0, 'move_prefer_shares' => true,
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

require_once dirname(__DIR__) . '/lib/poollevel.php';    // the pool, simply: her X/Y and Jack Emby's half (shared with him)
require_once dirname(__DIR__) . '/lib/nightrun.php';     // the night run: Jack Emby and she, one run, one schedule (shared with him)

define('MOVERELLI_APP', OFFICE_DIR . '/smartmover');
define('MOVERELLI_DATA', DATA_DIR . '/moverelli');

desk('moverelli', [
    'fit'     => fn (): array => moverelliFit(),
    'start'   => fn () => moverelliScan(),
    'actions' => [
        'refresh'   => fn (array $r) => ['ok' => true, 'state' => moverelliScan()],
        // her settings — while Unraid's own mover schedule is on only with `mover_off: true` (the page's consent): it is
        // switched off first (moverelli-unraid.php)
        'save'      => fn (array $r) => moverelliSave($r['settings'] ?? null, null, null, moverelliConsent($r)),
        'start_run' => fn (array $r) => moverelliStart(textField($r, 'mode')),
        'stop'      => fn (array $r) => ['ok' => true, 'asked' => moverelliStopAsk(), 'state' => moverelliScan()],
        // her run card's poll (desk.json part «progress», every few seconds while a real run goes)
        'progress'  => fn (array $r) => ['ok' => true, 'state' => moverelliProgressState()],
        // the night run's schedule (hers and Jack Emby's — one): a time (a cron line) or off
        'schedule'  => fn (array $r) => moverelliSetSchedule(cronField($r)),
        'output'    => fn (array $r) => moverelliOutput(),
        'log'       => fn (array $r) => moverelliLog(),
        // «Switch Unraid's mover schedule off…»: Unraid's own ⟦Mover schedule⟧ «Disabled», as its form sets it (jack-mover.php)
        'mover_off' => fn (array $r) => moverelliMoverOff($r) + ['state' => moverelliScan()],
        // taking over an earlier install of the Smart Mover (moverelli-import.php): what would come over — and doing it, with the preview's token
        'import_preview' => fn (array $r) => moverelliImportPreview($r),
        'import_apply'   => fn (array $r) => moverelliImportDo($r),
        // her part of the let-go dialog: what letting her go does — and doing it while she is still hired (moverelli-unraid.php)
        'letgo_look'     => fn (array $r) => moverelliLetGoLook(),
        'letgo'          => fn (array $r) => moverelliLetGo($r),
    ],
    'jobs'    => [
        // the night run (nightrun.php: run, dry — her cron line, her page, Jack Emby's «Run now»); her own part inside
        // it (--night) and the list of shares go to Smart Mover directly
        'moverelli' => fn (array $args) => in_array('--night', $args, true) || in_array('list', $args, true) ? moverelliJob($args) : nightRun($args),
        // a btrfs/xfs pool's movable share folders measured (du) in the background — asked by a page or a check at most
        // once an hour (agent/lib/poollevel.php poolLevelDuAsk())
        'pool-du'   => fn (array $args) => moverelliPoolDu((string) ($args[0] ?? '')),
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
        'shares_ini'    => $h['shares_ini'] ?? OFFICE_SHARES_INI,     // which shares exist (a cfg alone is no share)
        'var_ini'       => $h['var_ini'] ?? OFFICE_VAR_INI,
        'mnt'           => $h['mnt'] ?? '/mnt',
        'env'           => $h['env'] ?? [],          // more for the script (the tests: CUSTOMMOVER_MOVER_BIN, CUSTOMMOVER_ZFS)
        'version'       => $h['version'] ?? fn (): string => reportUnraidVersion(),
        'unraid'        => $h['unraid'] ?? fn (): array => jackMoverOwn(jackMoverHost()),
        'mover_running' => $h['mover_running'] ?? fn (?int $self = null): bool => jackMoverRunning(null, $self),
        'jack_busy'     => $h['jack_busy'] ?? fn (): bool => jackAnyRunning(),
        'jack_desk'          => $h['jack_desk'] ?? fn (): ?array => moverelliJackLists(),
        'scheduled'     => $h['scheduled'] ?? fn (): bool => officeJobSchedule('moverelli', $h['cron_file'] ?? OFFICE_CRON)['enabled'],
        'gate'          => $h['gate'] ?? [],         // jackRunGate()'s options (the tests: sleep, now, array, max)
        'look_every'    => $h['look_every'] ?? JACK_MOVER_LOOK,     // during a real run: is Unraid's mover at work? every … s
        'waitdir'       => $h['waitdir'] ?? RUN_DIR,
        'write_state'   => $h['write_state'] ?? true,
        'hired'         => $h['hired'] ?? fn (): bool => moverelliHired(),
        'lock_wait'     => $h['lock_wait'] ?? 30,  // s: the start lock she shares with Jack Emby (jackMoversLock())
        // other movers on a schedule of their own (moverelliForeign()): Mover Tuning, User Scripts, plugins' cron lines
        'tuning'        => $h['tuning'] ?? fn (): bool => (jackMoverHost()['installed'])(),
        'tuning_dir'    => $h['tuning_dir'] ?? JACK_TUNING_DIR,
        'us_dir'        => $h['us_dir'] ?? US_DIR,
        'cron_glob'     => $h['cron_glob'] ?? '/boot/config/plugins/*/*.cron',
        'unraid_cron'   => $h['unraid_cron'] ?? JACK_MOVER_CRON,
        // the other places a mover may be scheduled (moverelliMovers()): Unraid's crond's own files (RAM), root's crontab,
        // and whether a plugin is installed (only an installed plugin's *.cron runs — update_cron)
        'cron_d'        => $h['cron_d'] ?? '/etc/cron.d/*',
        'root_crontab'  => $h['root_crontab'] ?? '/var/spool/cron/crontabs/root',
        'plugin_installed' => $h['plugin_installed'] ?? fn (string $name): bool => is_file("/var/log/plugins/$name.plg"),
        // taking over an earlier install (moverelliImportContext()): what the tests put in place of the server's pools, disks, tree
        'import'        => $h['import'] ?? [],
        // where she looks for an earlier install by herself (the flash only — moverelliImportFound()); the tests: their own
        'import_look'   => $h['import_look'] ?? MOVERELLI_IMPORT_LOOK,
        // the night run's line (the office's cron file; the tests: a file of their own, not applied); Jack Emby's own line
        'cron_file'     => $h['cron_file'] ?? OFFICE_CRON,
        'cron_apply'    => $h['cron_apply'] ?? true,
        'launch'        => $h['launch'] ?? fn (array $args) => hostLaunch('moverelli', $args),
        'jack_scheduled' => $h['jack_scheduled'] ?? fn (): bool => officeJobSchedule('embycache', $h['cron_file'] ?? OFFICE_CRON)['enabled'],
        'jack_runs'     => $h['jack_runs'] ?? fn (): array => jackHistory(),
        // EmbyCache's list (every form of its path, hired or not) and whether EmbyCache ever ran here (moverelliJackRan())
        'jack_lists'    => $h['jack_lists'] ?? fn (): array => (jackMoverHost()['lists'])(),
        'jack_ran'      => $h['jack_ran'] ?? fn (): bool => moverelliJackRan(JACK_DATA),
        // is the array started (ARRAY_RUNNING)?
        'array_running' => $h['array_running'] ?? fn (): bool => arrayRunning(),
        // the pool (agent/lib/poollevel.php): zfs (the tests: Smart Mover's stand-in), disks.ini's sleepers
        // (null: asked), Jack Emby's pool (his cache_path while he is hired) and EmbyCache's list
        'zfs'           => $h['zfs'] ?? ($h['env']['CUSTOMMOVER_ZFS'] ?? 'zfs'),
        'asleep'        => $h['asleep'] ?? null,
        'jack_cache'    => $h['jack_cache'] ?? fn (): ?string => moverelliJackLists() !== null ? (string) (jackReadSettings()['cache_path'] ?? '') : null,
        'jack_list_file' => $h['jack_list_file'] ?? JACK_DATA . '/mediacache_exclude.txt',
        // a btrfs/xfs pool (poolLevelPlain()): its du stored in her data folder; the tests' statfs, du and background job
        'du_cache'      => $h['du_cache'] ?? ($h['dir'] ?? MOVERELLI_DATA) . '/pool-du.json',
    ] + array_intersect_key($h, array_flip(['stat', 'du', 'du_launch']));
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
    $tuning = moverelliTuning($h);
    $movers = moverelliMovers($h);
    return ['unraid' => jackMoverScheduleOff($own) ? null : (string) ($own['cron'] ?? $own['set']), 'tuning' => $tuning,
            'movers' => $movers, 'scripts' => array_values(array_unique(array_column($movers, 'name')))];
}

/**
 * Every other mover on a schedule of its own (2026-10-12, the owner's decision — generic, not only Mover Tuning by name):
 * read from the flash and RAM only (nothing woken) — the plugins' *.cron (installed ones: only those run), Unraid's
 * crond's own files (/etc/cron.d/*, without lines the plugins' files already hold — update_cron copies them there), root's
 * crontab, User Scripts' scripts with a schedule. An active line or script that calls `mover`, `age_mover`, `mover.php`,
 * Unraid's move binary or a Smart Mover of one's own (MOVERELLI_FOREIGN) is one — except Unraid's own mover line (said
 * apart: her setup switches it off), Mover Tuning's (said apart, `tuning`) and the office's own. [{name, where, when}],
 * name: the plugin folder, the cron file's name, «User Scripts: <script>», root's crontab.
 */
function moverelliMovers(array $h): array
{
    $calls = function (string $line): bool {
        foreach (MOVERELLI_FOREIGN as $re) {
            if (preg_match($re, $line)) {
                return true;
            }
        }
        return false;
    };
    $norm = fn (string $l): string => preg_replace('/\s+/', ' ', trim($l));
    $out = [];
    $seen = [];
    $lines = function (string $file) use ($h): array {
        $keep = [];
        foreach (explode("\n", jackActiveText((string) @file_get_contents($file, false, null, 0, 1 << 20))) as $line) {
            $line = trim($line);
            // a line that runs something; never the office's own (her job, the others) nor User Scripts' (said by script below)
            if (preg_match(MOVERELLI_CRON_LINE, $line) && !str_contains($line, 'unraid-secretary-office') && !str_contains($line, (string) $h['us_dir'])) {
                $keep[] = $line;
            }
        }
        return $keep;
    };
    $add = function (string $name, string $where, string $line) use (&$out, &$seen, $norm): void {
        $seen[$norm($line)] = true;
        $out[] = ['name' => $name, 'where' => $where, 'when' => (string) jackMoverCronLine($line, '/\S/')];
    };
    // the plugins' cron files: Unraid's own mover.cron and Mover Tuning's are said apart, the office's file is its own
    foreach (glob($h['cron_glob']) ?: [] as $file) {
        $folder = basename(dirname($file));
        $skip = in_array($file, [OFFICE_CRON, $h['unraid_cron']], true) || realpath(dirname($file)) === realpath((string) $h['tuning_dir'])
            || $folder === 'dynamix' || $folder === 'unraid-secretary-office';
        foreach ($lines($file) as $line) {
            if ($skip) {
                $seen[$norm($line)] = true;        // never again from /etc/cron.d
                continue;
            }
            if ($calls($line) && ($h['plugin_installed'])($folder)) {
                $add($folder, $file, $line);
            }
        }
    }
    foreach (array_merge(glob((string) $h['cron_d']) ?: [], [(string) $h['root_crontab']]) as $file) {
        if (!is_file($file)) {
            continue;
        }
        $name = $file === $h['root_crontab'] ? 'crontab (root)' : basename($file);
        foreach ($lines($file) as $line) {
            // Unraid's own mover line (said apart) — wherever it was copied to
            if (!isset($seen[$norm($line)]) && $calls($line) && !preg_match('~/usr/local/sbin/mover(\.old)?\s+start\b~', $line)
                && !str_contains($line, 'ca.mover.tuning')) {
                $add($name, $file, $line);
            }
        }
    }
    // User Scripts with a schedule that call a mover
    $plans = [];
    foreach ((array) json_decode((string) @file_get_contents("$h[us_dir]/schedule.json", false, null, 0, 1 << 20), true) as $key => $plan) {
        if (is_string($key) && is_array($plan)) {
            $plans[basename(dirname($key))] = $plan;
        }
    }
    foreach (glob("$h[us_dir]/scripts/*/script") ?: [] as $file) {
        $script = basename(dirname($file));
        $plan = $plans[$script] ?? [];
        $freq = (string) ($plan['frequency'] ?? 'disabled');
        $when = $freq === 'custom' ? trim((string) ($plan['custom'] ?? '')) : $freq;
        if (in_array($freq, ['', 'disabled'], true) || $when === '') {
            continue;
        }
        if ($calls(jackActiveText((string) @file_get_contents($file, false, null, 0, 65536)))) {
            $out[] = ['name' => "User Scripts: $script", 'where' => $file, 'when' => $when];
        }
    }
    return $out;
}

/** Mover Tuning: {installed, lines: [{file, cron}] — the active lines of its plugin folder's cron files, active} (Jack Emby's rule asks it too) */
function moverelliTuning(array $h): array
{
    $tuning = ['installed' => (bool) ($h['tuning'])(), 'lines' => []];
    if ($tuning['installed']) {
        foreach (glob("$h[tuning_dir]/*.cron") ?: [] as $file) {
            foreach (explode("\n", jackActiveText((string) @file_get_contents($file, false, null, 0, 65536))) as $line) {
                // a cron line: five time fields (or @daily …) and a command — never a VAR=value line
                if (preg_match(MOVERELLI_CRON_LINE, trim($line)) && ($cron = jackMoverCronLine($line, '/\S/')) !== null) {
                    $tuning['lines'][] = ['file' => basename($file), 'cron' => $cron];
                }
            }
        }
    }
    $tuning['active'] = (bool) $tuning['lines'];
    return $tuning;
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
    return moverelliIniParse((string) @file_get_contents($file, false, null, 0, 1 << 20));
}

/**
 * An ini's text as Smart Mover reads it (moverelliIniRead(); also an earlier install's, moverelli-import.php): per section
 * the first value of each key, both trimmed; comment lines (# ;) left out, CRLF tolerated; lines before the first section
 * left out.
 *
 * @return array<string, array<string, string>>
 */
function moverelliIniParse(string $text): array
{
    $out = [];
    $sec = null;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
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
 * Her settings as the page sees them, from her smart_mover.ini and her own lists: {global: {min_age,
 * move_when_used_above, move_prefer_shares, mover_debug, max_list, files, patterns}, shares: {share:
 * {skip, min_age, move_when_used_above (null: as global), files, patterns}}}. The fill (2026-10-12; only X
 * since 2026-10-14): the age rule always moves; is the pool at/above move_when_used_above X (0: off), the oldest files
 * across the pool go, whatever their age, until it is below X — Smart Mover's «fill»
 * (smartmover/custommover_run.sh). `files` are the other exclude files the ini
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

/**
 * Every user share (by its cfg on the flash) she can write a section for — a cfg whose share is gone (removed by hand,
 * the cfg left behind: officeShareExists()) is none. $h: moverelliHost() (its shares_ini, mnt, var_ini).
 */
function moverelliShareNames(string $sharesDir, ?array $h = null): array
{
    $h ??= moverelliHost();
    $out = [];
    foreach (glob("$sharesDir/*.cfg") ?: [] as $f) {
        $name = basename($f, '.cfg');
        if (preg_match(MOVERELLI_SHARE, $name) && officeShareExists($name, $h['shares_ini'], $h['mnt'], $h['var_ini'])) {
            $out[] = $name;
        }
    }
    sort($out);
    return $out;
}

/**
 * The page's settings checked, every value: numbers in their range (an older page's age_stat not read — always the younger date), switches true/false, files
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
        'move_when_used_above' => $int($g, 'move_when_used_above', 'move_when_used_above', false),
        'move_prefer_shares'   => $g['move_prefer_shares'],
        'mover_debug'          => $int($g, 'mover_debug', 'mover_debug', false),
        'max_list'             => $int($g, 'max_list', 'max_list', false),
        'files'                => $files,
        'patterns'             => $patterns,
    ], 'shares' => []];
    // (an older page's move_until_used_below / jack_min_percent: not read — only X since 2026-10-14)
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
            'move_when_used_above' => $int($s, 'move_when_used_above', "$share.move_when_used_above", true),
            'files'                => $files,
            'patterns'             => $patterns,
        ];
        // a share without anything of its own needs no section
        if ($row['skip'] || $row['min_age'] !== null || $row['move_when_used_above'] !== null || $files || $patterns) {
            if ($share === 'GLOBAL') {       // its section would be a second [GLOBAL]: Smart Mover reads the first for both
                throw new Problem('moverelli_global_share', ['share' => $share]);
            }
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
          . "min_age={$g['min_age']}\nage_stat=" . MOVERELLI_AGE_STAT . "\nmove_when_used_above={$g['move_when_used_above']}\n"
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
        foreach (['min_age', 'move_when_used_above'] as $k) {
            if (($s[$k] ?? null) !== null) {
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
/** Unraid's move binary (Smart Mover's own candidates, in its order); a path that isn't there when none is - a real run then says so */
function moverelliMoveBin(): string
{
    foreach (MOVERELLI_MOVE_BINS as $bin) {
        if (is_file($bin) && is_executable($bin)) {
            return $bin;
        }
    }
    return MOVERELLI_MOVE_BINS[0];
}

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

/**
 * moverelli.save: checked, refused while a run of hers goes, written; logged. $before runs with her lock held, right
 * before anything is written (the import's copies of her files: moverelli-import.php).
 */
function moverelliSave(mixed $in, ?array $h = null, ?callable $before = null, bool $moverOff = false): array
{
    $h ??= moverelliHost();
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'settings']);
    }
    if (moverelliBusy($h['dir'])) {
        throw new Problem('moverelli_running');
    }
    $set = moverelliCheck($in, moverelliShareNames($h['shares_dir'], $h), $h['dir']);
    // Unraid's own mover goes off with her setup — asked on the page; refused without the consent, nothing written
    $unraid = moverelliUnraidOffForSave($moverOff, $h);
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
        if ($before !== null) {
            $before();
        }
        moverelliWrite($set, $h['dir']);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    logLine('Ms. Moverelli: Smart Mover settings saved (' . count($set['shares']) . ' shares with rules of their own)');
    return ['ok' => true, 'mover_off' => $unraid['off'], 'state' => moverelliScan($h)];
}

/** The page's consent that Unraid's own mover schedule goes off with her save: `mover_off` exactly true (absent: no) */
function moverelliConsent(array $r): bool
{
    return array_key_exists('mover_off', $r) && boolField($r, 'mover_off');
}

/**
 * What her ini keeps out, for Jack Emby's rule (jackMoverRule()): null while she isn't hired or has no smart_mover.ini
 * yet (not set up: she refuses all her own runs, so she doesn't count for his rule at all — as if not hired), else
 * {global: [files], shares: {share: {skip, excludes: [files]}}} — her own lists among the files. $hired: the tests.
 */
function moverelliExcludeLook(?string $dir = null, ?bool $hired = null): ?array
{
    $dir ??= MOVERELLI_DATA;
    return ($hired ?? moverelliHired()) && moverelliConfigured($dir) ? moverelliExcludeFrom($dir) : null;
}

/** Is she set up — her smart_mover.ini there? (Without it every run of hers is refused: moverelli_not_configured) */
function moverelliConfigured(string $dir): bool
{
    return is_file("$dir/smart_mover.ini");
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
    if (!in_array('jack', staffHired($GLOBALS['agentStaffFile'] ?? null), true)) {
        return null;
    }
    $h = jackMoverHost();
    return ['lists' => ($h['lists'])(), 'shares' => ($h['shares'])()];
}

// ===================================================================== state

/**
 * Has EmbyCache ever run for real (or been imported) on this server? Read only, from Jack's own traces in his data
 * folder: mediacache_origin.json (every real run and release writes it together with the list, also empty), a copy an
 * import put aside (`*.before-import-*`), his list of runs (an EmbyCache run or release that started), his last run's
 * marker (office-run.json: EmbyCache, run or release). None of them: EmbyCache's list can't exist yet.
 */
function moverelliJackRan(string $dir): bool
{
    if (is_file("$dir/mediacache_origin.json") || glob("$dir/mediacache_{exclude.txt,origin.json}.before-import-*", GLOB_BRACE)) {
        return true;
    }
    $real = fn (mixed $r): bool => is_array($r) && ($r['tool'] ?? '') === 'embycache' && in_array($r['mode'] ?? '', ['run', 'release'], true)
        && !in_array($r['result'] ?? '', ['refused', 'skipped'], true);
    foreach ((array) (readJson("$dir/office-history.json")['runs'] ?? []) as $r) {
        if ($real($r)) {
            return true;
        }
    }
    return $real(readJson("$dir/office-run.json"));
}

/**
 * EmbyCache's list named in her ini (for all shares or a share's own), none of its forms there, and EmbyCache never ran
 * here: the path her ini names — her real runs wait for his first one. Else null (not named, there, or gone after he
 * ran: then Smart Mover's ERROR skips the share).
 */
function moverelliJackListPending(array $h): ?string
{
    if (!moverelliConfigured($h['dir'])) {
        return null;
    }
    $look = moverelliExcludeFrom($h['dir']);
    $named = array_merge((array) $look['global'], ...array_map(fn ($s) => (array) ($s['excludes'] ?? []), array_values((array) $look['shares'])));
    $lists = array_values(array_filter((array) ($h['jack_lists'])(), 'is_string'));
    $hit = array_values(array_intersect($named, $lists));
    if (!$hit) {
        return null;
    }
    foreach ($lists as $f) {
        clearstatcache(true, $f);
        if (is_file($f)) {
            return null;
        }
    }
    return ($h['jack_ran'])() ? null : $hit[0];
}

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
function moverelliShares(array $set, string $sharesDir, ?array $h = null): array
{
    $g = $set['global'];
    $out = [];
    foreach (moverelliShareNames($sharesDir, $h) as $share) {
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
        $row['threshold'] = $use === 'yes' ? ($own['move_when_used_above'] ?? $g['move_when_used_above']) : null;
        $row['excludes'] = count($g['files']) + ($g['patterns'] ? 1 : 0) + count($own['files'] ?? []) + (($own['patterns'] ?? []) ? 1 : 0);
        $out[] = $row;
    }
    return $out;
}

// ===================================================================== the pool (agent/lib/poollevel.php)

/** Jack Emby's pool: the pool of his cache_path (/mnt/<pool>) while he is hired, else null */
function moverelliJackPool(array $h): ?string
{
    $cache = ($h['jack_cache'])();
    $mnt = rtrim($h['mnt'], '/');
    return is_string($cache) && preg_match('#^' . preg_quote($mnt, '#') . '/([\w.\-]{1,64})/?$#D', $cache, $m) && !in_array($m[1], ['user', 'user0'], true) ? $m[1] : null;
}

/**
 * Every pool her movable shares live on (yes|prefer, the primary) measured for her rule $g (move_when_used_above X):
 * {pool: level + {mine: Jack Emby's pool} | null} (null: not looked at — no
 * ZFS pool, or asleep). Jack's bytes count only on his pool.
 */
function moverelliLevels(array $g, array $h): array
{
    $names = moverelliShareNames($h['shares_dir'], $h);
    $pools = [];
    foreach ($names as $share) {
        $cfg = readCfg("$h[shares_dir]/$share.cfg");
        if (in_array((string) ($cfg['shareUseCache'] ?? ''), ['yes', 'prefer'], true)) {
            $p = (string) ($cfg['shareCachePool'] ?? '');
            $pools[$p !== '' ? $p : 'cache'] = true;
        }
    }
    ksort($pools, SORT_STRING);
    $jack = moverelliJackPool($h);
    $out = [];
    foreach (array_keys($pools) as $pool) {
        $pool = (string) $pool;
        $mine = $pool === $jack;
        $level = poolLevel($pool, ['x' => (int) $g['move_when_used_above'], 'shares_dir' => $h['shares_dir'], 'names' => $names, 'zfs' => $h['zfs'],
            'mnt' => $h['mnt'], 'jack_list' => $mine ? $h['jack_list_file'] : null, 'asleep' => $h['asleep'], 'measure' => $h['pool_measure'] ?? null]
            + array_intersect_key($h, array_flip(['stat', 'du', 'du_cache', 'du_launch'])));
        $out[$pool] = $level === null ? null : $level + ['mine' => $mine];
    }
    return $out;
}

/** The pools of moverelliLevels() not looked at that are btrfs/xfs waiting for their first du (poolLevelWaiting()) */
function moverelliLevelsWaiting(array $levels, array $h): array
{
    $o = ['shares_dir' => $h['shares_dir'], 'names' => moverelliShareNames($h['shares_dir'], $h), 'mnt' => $h['mnt']]
       + array_intersect_key($h, array_flip(['stat', 'du_cache']));
    return array_values(array_filter(array_map('strval', array_keys(array_filter($levels, fn ($l) => $l === null))),
        fn (string $pool) => !poolsBySleep([$pool], $h['asleep'])['asleep'] && poolLevelWaiting($pool, $o)));
}

/** The background job: a btrfs/xfs pool's movable share folders measured and stored (poolLevelDuJob()); 0: stored */
function moverelliPoolDu(string $pool): int
{
    $h = moverelliHost();
    $code = poolLevelDuJob($pool, ['shares_dir' => $h['shares_dir'], 'names' => moverelliShareNames($h['shares_dir'], $h), 'mnt' => $h['mnt'], 'asleep' => $h['asleep']]
        + array_intersect_key($h, array_flip(['stat', 'du', 'du_cache'])));
    logLine("Ms. Moverelli: pool $pool's movable share folders " . ($code === 0 ? 'measured' : 'not measured'));
    return $code;
}

/**
 * A real run's fill that ended above her limit X (the share's own, else the global) goes to Unraid's notifications at once
 * (a warning per pool): from Smart Mover's status file — a share with the fill, not reached, not stopped
 */
function moverelliTargetNotify(array $status): int
{
    if (($status['result'] ?? '') === 'stopped') {
        return 0;
    }
    $lang = officeNotifyLang();
    $sent = 0;
    $told = [];
    foreach ((array) ($status['shares'] ?? []) as $s) {
        $pool = (string) ($s['pool'] ?? '');
        if (!is_array($s) || ($s['fill'] ?? false) !== true || ($s['reached'] ?? true) !== false || $pool === '' || isset($told[$pool])
            || !preg_match('/^[\w.\-]{1,64}$/D', $pool) || (string) ($s['pool_now'] ?? '') === '' || (int) ($s['pool_now'] ?? 0) < (int) ($s['until'] ?? 0)) {
            continue;
        }
        $told[$pool] = true;
        if (officeNotify(officeNotifyText('moverelli', 'notify.target_subject', ['pool' => $pool], $lang),
            officeNotifyText('moverelli', 'notify.target_missed', ['pool' => $pool, 'now' => (int) $s['pool_now'], 'x' => (int) ($s['until'] ?? 0)], $lang),
            'warning', '', officeNotifyLink('#/moverelli'))) {
            $sent++;
            logLine("Ms. Moverelli: told Unraid's notifications — pool $pool: target not reached");
        }
    }
    return $sent;
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
    return moverelliJobInfo($dir)['running'] || flockHeld("$dir/office-run.lock") || flockHeld("$dir/smartmover.lock")
        || (!nightInside() && nightRunning($dir));
}

/** Jack Emby's shares her ini doesn't keep EmbyCache's list away from (none while he isn't hired or she isn't set up) */
function moverelliJackUncovered(?array $jackDesk, string $dir): array
{
    return $jackDesk === null || !moverelliConfigured($dir) ? [] : jackMoverelliUncovered(moverelliExcludeFrom($dir), $jackDesk['lists'], $jackDesk['shares']);
}

function moverelliScan(?array $h = null): array
{
    $h ??= moverelliHost();
    $dir = $h['dir'];
    $settings = moverelliSettings($dir);
    $version = ($h['version'])();
    $own = ($h['unraid'])();
    $jackDesk = ($h['jack_desk'])();
    $job = moverelliJobInfo($dir);
    $stop = $job['running'] ? readJson("$dir/office-stop.json") : null;
    $state = [
        'time'       => time(),
        'unraid'     => ['version' => $version, 'ok' => moverelliUnraidOk($version), 'needed' => MOVERELLI_UNRAID],
        'engine'     => moverelliEngineVersion($h['app']),
        'configured' => $settings !== null,
        // shares as an object for the page: a share named «0» or «2024» must not turn the map into a list
        'settings'   => ['global' => ($settings ?? moverelliDefaults())['global'], 'shares' => (object) ($settings ?? moverelliDefaults())['shares']],
        'shares'     => moverelliShares($settings ?? moverelliDefaults(), $h['shares_dir'], $h),
        // Unraid's own mover: its schedule (real runs only while «Disabled»), at work right now
        'mover'      => ['off' => jackMoverScheduleOff($own), 'schedule' => $own['cron'] ?? ($own['set'] !== '' ? $own['set'] : null),
                         'running' => ($h['mover_running'])($job['running'] ? (int) $job['pid'] : null)],     // her own run's move binary isn't Unraid's mover
        // other movers on a schedule of their own: Mover Tuning's (refuses real runs), scripts that call a mover (named)
        'foreign'    => array_diff_key(moverelliForeign($h), ['unraid' => 1]),
        'job'        => $job,
        'stopping'   => $stop !== null ? (($stop['why'] ?? '') === 'mover' ? 'mover' : 'user') : null,
        'last'       => readJson("$dir/status.json"),
        'history'    => array_slice(moverelliHistory($dir), 0, 20),
        'schedule'   => officeJobSchedule('moverelli', $h['cron_file']),
        // the night run (nightrun.php): the last one or the one going (phase, round, who works), and tonight's plan
        'night'      => moverelliNight($h, $settings),
        // Jack Emby: a run of his going (hers wait), EmbyCache's list and his shares her ini doesn't keep it away from;
        // while he is hired also every form of his list's path and his shares — her setup page proposes his list from them
        // (the same rule as jackMoverelliUncovered(), on the form not yet saved)
        'jack'       => ['busy' => ($h['jack_busy'])(), 'hired' => $jackDesk !== null, 'list' => $jackDesk['lists'][0] ?? null,
                         'lists' => array_values($jackDesk['lists'] ?? []), 'shares' => array_values($jackDesk['shares'] ?? []),
                         'uncovered' => moverelliJackUncovered($jackDesk, $dir),
                         // EmbyCache's list in her ini, but EmbyCache never ran yet: her real runs wait for his first one
                         'pending' => moverelliJackListPending($h)],
        // an earlier install she found by herself on the flash: her setup page offers it at the top
        'import_found' => moverelliImportFound($h),
        // the pool per pool of her movable shares (null: not looked at — no ZFS, asleep): her setup's sentence and numbers
        // Jack Emby's bytes on his pool (`mine`)
        'levels'     => (object) ($levels = moverelliLevels(($settings ?? moverelliDefaults())['global'], $h)),
        // the pools not looked at that are btrfs/xfs waiting for their first du (the background job on its way): «not measured yet»
        'levels_wait' => moverelliLevelsWaiting($levels, $h),
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
    if (!moverelliConfigured($h['dir'])) {
        throw new Problem('moverelli_not_configured');
    }
    // EmbyCache's list in her ini, but EmbyCache never ran here: it can't exist yet — no real run until his first one
    // (said once: a scheduled refusal is counted, never notified); a list that existed and is gone stays Smart Mover's ERROR
    if ($mode === 'run' && ($file = moverelliJackListPending($h)) !== null) {
        throw new Problem('moverelli_jack_list_pending', ['file' => $file]);
    }
    if (!is_file("$h[app]/custommover_run.sh")) {
        throw new Problem('moverelli_missing_tool', ['path' => $h['app']]);
    }
    if (moverelliBusy($h['dir'])) {
        throw new Problem('moverelli_running');
    }
    if (($h['jack_busy'])()) {
        throw new Problem('moverelli_jack_running');
    }
    if ($mode !== 'run') {
        return;
    }
    $own = ($h['unraid'])();
    if (!jackMoverScheduleOff($own)) {
        throw new Problem('moverelli_schedule', ['schedule' => $own['cron'] ?? $own['set']]);
    }
    // nor Mover Tuning's own schedule: it would move the same shares
    $tuning = moverelliTuning($h);
    if ($tuning['active']) {
        throw new Problem('moverelli_tuning_schedule', ['schedule' => $tuning['lines'][0]['cron'], 'file' => $tuning['lines'][0]['file']]);
    }
    // nor any other mover on a schedule of its own (a plugin, a cron line, a User Script): it doesn't know her exceptions
    if ($m = moverelliMovers($h)) {
        throw new Problem('moverelli_foreign_mover', ['name' => $m[0]['name'], 'when' => $m[0]['when']]);
    }
    // Jack Emby hired: EmbyCache's films stay on the pool — his list in her ini for each of his shares
    if ($uncovered = moverelliJackUncovered(($h['jack_desk'])(), $h['dir'])) {
        throw new Problem('moverelli_jack_list', ['shares' => implode(', ', $uncovered)]);
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

/** «Stop after this file»: only while a real run goes — Smart Mover's stop request, why `user`, once (the file in flight finishes) */
function moverelliStopAsk(?array $h = null): bool
{
    $h ??= moverelliHost();
    if (nightRunning($h['dir'])) {
        return nightStopAsk($h);        // the whole night: the tool at work stops after its file, no further step
    }
    $job = moverelliJobInfo($h['dir']);
    if (!$job['running'] || ($job['mode'] ?? '') !== 'run' || is_file("$h[dir]/office-stop.json")) {
        return false;
    }
    writeAtomic("$h[dir]/office-stop.json", jsonEncode(['time' => time(), 'why' => 'user']), 0600, 0, 0);
    logLine('Ms. Moverelli: asked to stop — Smart Mover finishes the file it is on and starts no further one');
    return true;
}

/** Her run card's poll: {time, running, real, stopping: why of the stop file or null, progress: Smart Mover's live status or null} */
function moverelliProgressState(?array $h = null): array
{
    $h ??= moverelliHost();
    $job = moverelliJobInfo($h['dir']);
    $night = nightState($h['dir']);
    $nightReal = $night !== null && $night['running'] && ($night['mode'] ?? '') === 'run';
    $real = ($job['running'] && ($job['mode'] ?? '') === 'run') || $nightReal;
    $st = $job['running'] && ($job['mode'] ?? '') === 'run' ? readJson("$h[dir]/status.json") : null;
    $stop = $real ? (readJson("$h[dir]/office-stop.json") ?? ($nightReal ? readJson("$h[dir]/night-stop.json") : null)) : null;
    $prog = is_array($st) && ($st['result'] ?? '') === 'running' ? $st : null;
    if ($prog !== null) {
        $prog += moverelliInFlight($prog, $h);
    }
    $short = $nightReal ? moverelliNightShort($night) : null;
    $jackLive = $short !== null && ($night['who'] ?? null) === 'jack' ? (nightHost($h)['jack_live'])() : [];
    if ($short !== null && is_array($short['picture'] ?? null) && in_array($night['who'] ?? null, ['jack', 'her'], true)) {
        // the picture's files with those of the step at work (its tool's live status; night.json has them once it is done)
        $live = $night['who'] === 'her' ? ($st['recent'] ?? []) : ($jackLive['recent'] ?? []);
        $short['picture']['files'] = array_slice(array_merge((array) ($short['picture']['files'] ?? []), nightFiles($live, $night['who'])), -NIGHT_FILES_MAX);
    }
    if ($short !== null && is_array($short['picture'] ?? null) && ($night['who'] ?? null) === 'jack' && ($night['phase'] ?? null) === 'free') {
        // his free step at work: what was watched from EmbyCache's plan (its progress in RAM, this night's — never an older
        // run's file) — the «before» bar split at once
        $back = $jackLive['back'] ?? null;
        if (is_array($back) && is_int($back['bytes'] ?? null) && is_int($jackLive['started'] ?? null) && $jackLive['started'] >= (int) ($night['started'] ?? PHP_INT_MAX)) {
            $short['picture'] = nightPictureWatched($short['picture'], $back['bytes']);
        }
    }
    // his step at work (free = give back, fill = fetch): his live panel's numbers under her card, as his own page has them
    // (jack-progress.php, two RAM reads) — {real, progress}, null in her steps and between them. Real by the night's word
    // (a real night, his step written), never only by his job's marker: his panel shows from the moment his step starts,
    // «working out what goes where» until EmbyCache's first progress (2026-10-15)
    $jack = null;
    if ($short !== null && ($night['who'] ?? null) === 'jack' && in_array($night['phase'] ?? null, ['free', 'fill'], true)) {
        $jl = (nightHost($h)['jack_progress'])($night);
        $jack = ['real' => true, 'progress' => is_array($jl) && is_array($jl['progress'] ?? null) ? $jl['progress'] : null];
    }
    return ['time' => time(), 'running' => $job['running'] || $nightReal, 'real' => $real, 'stopping' => is_array($stop) ? (string) ($stop['why'] ?? 'user') : null,
            'progress' => $prog, 'night' => $short, 'jack' => $jack];
}

/**
 * The file in flight, measured (2026-10-12, seen live): Unraid's move works through shfs (nothing in its /proc io) and
 * writes to <dest>/<share>/<rel>.partial, renamed at the end — what of it is written is that file's ALLOCATED size
 * (st_blocks · 512: its apparent size may be preallocated to the whole). Looked for only where the share's files go
 * (its secondary pool, else the array disks) and only on awake ones (the target is awake while written); the path
 * strictly from the source's below the share. {file_bytes, file_size (the file's own), waking (no .partial yet, a
 * destination still asleep: the move waits for its disk)} (0 / null when none is found).
 */
function moverelliInFlight(array $p, array $h): array
{
    $file = (string) ($p['file'] ?? '');
    $share = (string) ($p['share'] ?? '');
    $mnt = rtrim($h['mnt'], '/');
    $none = ['file_bytes' => 0, 'file_size' => null];
    if ($file === '' || ($p['phase'] ?? '') !== 'share' || !preg_match(MOVERELLI_SHARE, $share)
        || !preg_match('#^' . preg_quote($mnt, '#') . '/[\w.\-]+/' . preg_quote($share, '#') . '/(.+)$#Du', $file, $m) || str_contains("/$m[1]/", '/../')) {
        return $none;
    }
    $rel = $m[1];
    $cfg = readCfg("$h[shares_dir]/$share.cfg");
    $use = (string) ($cfg['shareUseCache'] ?? '');
    $pool = (string) ($cfg['shareCachePool'] ?? '') ?: 'cache';
    $second = (string) ($cfg['shareCachePool2'] ?? '');
    $dests = $use === 'prefer' ? [$pool] : ($second !== '' ? [$second] : array_map('basename', glob("$mnt/disk[0-9]*", GLOB_ONLYDIR) ?: []));
    $sleep = poolsBySleep($dests, $h['asleep']);
    $awake = $sleep['awake'];
    clearstatcache();
    $src = @stat($file);
    foreach ($awake as $d) {
        $part = "$mnt/$d/$share/$rel.partial";
        $st = @lstat($part);
        if ($st !== false && ($st['mode'] & 0170000) === 0100000) {
            $size = $src !== false ? (int) $src['size'] : null;
            $done = (int) $st['blocks'] * 512;
            return ['file_bytes' => $size !== null ? min($done, $size) : $done, 'file_size' => $size];
        }
    }
    // no .partial yet and a destination still asleep: Unraid's move waits for the disk to spin up
    return ['file_bytes' => 0, 'file_size' => $src !== false ? (int) $src['size'] : null, 'waking' => (bool) $sleep['asleep']];
}

/**
 * While a real run goes: is Unraid's mover at work (not counting the move binary below this job)? every $every s —
 * then she stops after the file in flight (2026-10-13: Unraid's move catches TERM and finishes its file anyway, so there
 * is no harder stop): the stop file {why: mover, by: hand|schedule — Unraid's own schedule off: started by hand}; Smart
 * Mover starts no further file and ends «stopped». A stop file it didn't write (her page) is read each look: its why
 * (user). Returns the exit code, why it stopped and, for the mover, by what.
 *
 * @param resource $proc
 * @return array{exit: int, why: ?string, by: ?string}
 */
function moverelliRunWatch($proc, string $stopFile, callable $mover, int $every = JACK_MOVER_LOOK, ?callable $byHand = null): array
{
    $why = null;
    $by = null;
    $last = time();
    while (true) {
        $st = proc_get_status($proc);
        clearstatcache(true, $stopFile);
        if (!$st['running']) {
            proc_close($proc);
            // a stop file written after the last look (a run that ended in between): its why still counts
            if ($why === null && is_file($stopFile)) {
                $f = readJson($stopFile) ?? [];
                $why = in_array($f['why'] ?? '', ['mover', 'now'], true) ? $f['why'] : 'user';
                $by = $why === 'mover' ? (($f['by'] ?? '') === 'schedule' ? 'schedule' : 'hand') : null;
            }
            return ['exit' => (int) $st['exitcode'], 'why' => $why, 'by' => $by];
        }
        if (is_file($stopFile)) {
            $f = readJson($stopFile) ?? [];
            $why = in_array($f['why'] ?? '', ['mover', 'now'], true) ? $f['why'] : 'user';
            $by = $why === 'mover' ? (($f['by'] ?? '') === 'schedule' ? 'schedule' : 'hand') : null;
        } elseif (time() - $last >= $every) {
            $last = time();
            try {
                $on = (bool) $mover();
            } catch (Throwable) {
                $on = false;
            }
            if ($on) {
                $why = 'mover';
                $by = $byHand === null || ($byHand)() ? 'hand' : 'schedule';
                // after the file in flight (2026-10-13): Unraid's move catches TERM and finishes the file anyway — no harder stop
                writeAtomic($stopFile, jsonEncode(['time' => time(), 'why' => 'mover', 'by' => $by]), 0600, 0, 0);
                logLine("Ms. Moverelli: Unraid's mover started ($by) — she stops after the file in flight");
            }
        }
        usleep(200000);
    }
}

/**
 * "php agent.php job moverelli [list|dry|run] [--office]" — what her schedule (job.sh: a real run) and the page call.
 * Checked again here; a real run passes the gate (Unraid's mover at work: refused from the page, on schedule it waits
 * like Jack's — jackRunGate()), holds her lock for the whole run, and stops before the next share when the mover starts.
 * Records the run, keeps its output, tells Unraid's notifications when a real run went wrong. Returns the exit code.
 */
function moverelliJob(array $args, ?array $h = null): int
{
    $h ??= moverelliHost();
    $dir = $h['dir'];
    $by = in_array('--night', $args, true) ? 'night' : (in_array('--office', $args, true) ? 'office' : 'schedule');
    // inside the night run (nightrun.php): the room Jack Emby fetches into — her fill goes until the pool uses at most this
    $room = $roomPool = null;
    foreach ($by === 'night' ? $args : [] as $a) {
        if (preg_match('/^--fill-until=(\d{1,18})$/D', $a, $m)) {
            $room = $m[1];
        } elseif (preg_match('/^--fill-pool=([\w.\-]{1,64})$/D', $a, $m)) {
            $roomPool = $m[1];
        }
    }
    $solo = $by === 'night' && in_array('--solo', $args, true);      // the night without Jack Streamer: the record says so
    $jackOut = null;                                                  // … and why, when he refused tonight (his refusal's code)
    foreach ($solo ? $args : [] as $a) {
        $jackOut = preg_match('/^--jack-out=([a-z][a-z0-9_]{0,63})$/D', $a, $m) ? $m[1] : $jackOut;
    }
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
        // a scheduled run waits for Unraid's mover; inside the night (which waited already) and from the page: refused at once
        $gate = jackRunGate('moverelli', $by === 'schedule' ? 'schedule' : 'office', ['watch' => false, 'dir' => $dir, 'waitdir' => $h['waitdir'],
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
            jackWaitEnd($wait, $h['waitdir'], 'moverelli');
            return $refused($p->key, $p->params);
        }
    }
    moverelliDataDir($dir);
    // the start lock she shares with Jack Emby: his runs' check and marker and hers never interleave (two cron jobs in
    // the same minute) — inside it: none of his going, her own lock taken, her office-run.json written
    $movers = jackMoversLock((int) $h['lock_wait']);
    if ($movers === null || ($h['jack_busy'])()) {
        jackMoversUnlock($movers);
        jackWaitEnd($wait, $h['waitdir'], 'moverelli');
        return $refused('moverelli_jack_running');
    }
    $hold = @fopen("$dir/office-run.lock", 'c');
    if (!$hold || !flock($hold, LOCK_EX | LOCK_NB)) {
        jackMoversUnlock($movers);
        jackWaitEnd($wait, $h['waitdir'], 'moverelli');
        return $refused('moverelli_running');
    }
    $started = time();
    $run = ['mode' => $mode, 'by' => $by, 'started' => $started, 'pid' => getmypid()];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    jackMoversUnlock($movers);
    jackWaitEnd($wait, $h['waitdir'], 'moverelli');
    @unlink("$dir/status.json");
    @unlink("$dir/office-stop.json");
    // inside the night: «Stop after this file» asked while this job started (the night named her the tool at work, then
    // the line above took her stop file away) — asked again, so the run stops after its first file instead of at its end
    if ($by === 'night' && is_file("$dir/night-stop.json")) {
        writeAtomic("$dir/office-stop.json", (string) @file_get_contents("$dir/night-stop.json"), 0600, 0, 0);
    }
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8',
            'CUSTOMMOVER_INI' => "$dir/smart_mover.ini", 'CUSTOMMOVER_LOG' => "$dir/smart_mover.log", 'CUSTOMMOVER_LOCK' => "$dir/smartmover.lock",
            'CUSTOMMOVER_STATUS' => "$dir/status.json", 'CUSTOMMOVER_STOP' => "$dir/office-stop.json",
            'CUSTOMMOVER_SHARES_DIR' => $h['shares_dir'], 'CUSTOMMOVER_MNT' => $h['mnt'], 'OFFICE_RUN_DIR' => RUN_DIR]
         // a real run: a list that is gone skips its share; a share whose array disks sleep says it wakes them (her log)
         + ($real ? ['CUSTOMMOVER_EXCLUDES_REQUIRED' => '1', 'CUSTOMMOVER_DISKS_INI' => disksIniFile()] : [])
         // the night run: Jack Emby's GB first — her fill makes room for his fetch below X (only on his pool)
         + ($room !== null && $roomPool !== null ? ['CUSTOMMOVER_FILL_UNTIL_BYTES' => $room, 'CUSTOMMOVER_FILL_UNTIL_POOL' => $roomPool] : [])
         + $h['env']
         // the move binary is Unraid's, never what an ini names (review 2026-10-15: the script runs mover_bin as root)
         + ['CUSTOMMOVER_MOVER_BIN' => moverelliMoveBin()];
    $out = fopen("$dir/office-output.txt", 'w');
    $proc = proc_open(array_merge(['bash', "$h[app]/custommover_run.sh"], MOVERELLI_MODES[$mode]), [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, '/', $env);
    $why = null;
    if (!is_resource($proc)) {
        $exit = 127;
    } elseif ($real) {
        $self = getmypid();
        ['exit' => $exit, 'why' => $why, 'by' => $moverBy] = moverelliRunWatch($proc, "$dir/office-stop.json", fn (): bool => ($h['mover_running'])($self), (int) $h['look_every'],
            fn (): bool => jackMoverScheduleOff(($h['unraid'])()));
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
        $note['why'] = in_array($why, ['mover', 'now'], true) ? $why : 'user';
        if ($why === 'mover') {
            $note['mover_by'] = $moverBy ?? 'hand';
        }
        $note['stop_file'] = (string) ($status['stop_file'] ?? '');
    }
    moverelliRemember(['mode' => $mode, 'by' => $by, 'started' => $started, 'finished' => time(), 'exit' => $exit, 'result' => $result,
                       'status' => moverelliStatusShort($status)] + ($solo ? ['jack' => false] : []) + ($jackOut !== null ? ['jack_out' => $jackOut] : []) + $note, $dir);
    logLine("Ms. Moverelli: Smart Mover ($mode) $result" . ($mode === 'run' ? ' — ' . (int) ($status['moved'] ?? 0) . ' moved, '
        . (int) ($status['left'] ?? 0) . ' left' : '') . (isset($note['why']) ? " (stopped: {$note['why']})" : ''));
    try {
        moverelliNotify($mode, $result, $status, $exit, $note['why'] ?? null);
        if ($real && $cap === null) {      // inside the night with a cap: the night tells a missed target at its end
            moverelliTargetNotify($status);
        }
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
            $out['shares'][] = array_intersect_key($s, array_flip(['share', 'state', 'files', 'bytes', 'moved', 'left', 'pool', 'until', 'pool_used', 'pool_now', 'fill', 'reached']));
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
    if (($entry['result'] ?? '') === 'refused' && in_array($entry['by'] ?? '', ['schedule', 'night'], true) && is_array($top)
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
 * Emby's jackMoverOff() (jack-mover.php), her name in the log; its refusal in her words.
 */
function moverelliMoverOff(array $r, ?array $host = null): array
{
    try {
        return jackMoverOff($r, $host, fn (string $text) => logLine("Ms. Moverelli: $text"));
    } catch (Problem $p) {
        if ($p->key === 'jack_mover_off_failed') {
            throw new Problem('moverelli_mover_off_failed', $p->params);
        }
        throw $p;
    }
}

/**
 * The night run's schedule — one for Jack Emby and her (2026-10-13, the owner's decision): off, or at a time (her line
 * in the office's cron file: `job moverelli`, the night run). A line of Jack Emby's own EmbyCache goes with it (the night
 * runs his part). When it was set is noted in office-schedule.json for the Team Lead.
 */
function moverelliSetSchedule(?string $cron, ?array $h = null): array
{
    $h ??= moverelliHost();
    if ($cron !== null && !moverelliConfigured($h['dir'])) {
        throw new Problem('moverelli_not_configured');
    }
    $live = officeJobSetSchedule('moverelli', $cron, $h['cron_file'], (bool) $h['cron_apply']);
    if ($cron !== null && officeJobSchedule('embycache', $h['cron_file'])['enabled']) {
        officeJobSetSchedule('embycache', null, $h['cron_file'], (bool) $h['cron_apply']);
        logLine("Ms. Moverelli: Jack Streamer's own EmbyCache schedule switched off — the night run does his part");
    }
    moverelliDataDir($h['dir']);
    writeAtomic("$h[dir]/office-schedule.json", jsonEncode(['v' => 1, 'set' => time(), 'cron' => $cron]), 0600, 0, 0);
    logLine('Ms. Moverelli: the night run\'s schedule ' . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    return ['ok' => true, 'live' => $live, 'state' => moverelliScan($h)];
}

/**
 * The night run for her page and Jack Emby's: the last one or the one going (phase, round, who works, its steps and
 * result) and tonight's plan from the pool as it stands now (no Emby asked: what waits from his last status).
 */
function moverelliNight(array $h, ?array $settings): array
{
    $n = nightState($h['dir']);
    $nh = nightHost($h);
    // Jack Emby is in the night only when he takes part (hired and set up) — else her part alone, on the pool of her shares
    $jack = (bool) ($nh['jack_on'])();
    $plan = null;
    if ($settings !== null) {
        try {
            $g = $settings['global'];
            $plan = nightWords(nightPlan(($nh['level'])($g, $nh, false), $jack ? nightWaiting($nh) : 0));
        } catch (Throwable $e) {
            $plan = null;
        }
    }
    return ['jack' => $jack, 'plan' => $plan, 'last' => $n !== null ? moverelliNightShort($n) : null];
}

/** night.json as the pages read it */
function moverelliNightShort(array $n): array
{
    return array_intersect_key($n, array_flip(['mode', 'by', 'started', 'finished', 'phase', 'round', 'who', 'jack', 'pool', 'plan', 'steps',
        'result', 'stopped_in', 'stopped_who', 'missed', 'end_pct', 'running', 'waited', 'why', 'picture', 'jack_out']));
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
        // a run that moved or would have — a look at the shares' list is none
        if (is_array($r) && in_array($r['mode'] ?? '', ['run', 'dry'], true) && !in_array($r['result'] ?? '', ['refused', 'skipped'], true)) {
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
    $configured = moverelliConfigured($h['dir']);
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
    // every other mover on a schedule of its own: required — her real runs are refused while one is active
    $m = $f['movers'];
    $out[] = finding('foreign_mover', 'required', !$m, ['name' => implode(', ', array_column($m, 'name')), 'when' => (string) ($m[0]['when'] ?? '')], '#/moverelli');
    $jackDesk = ($h['jack_desk'])();
    if ($scheduled) {
        $out[] = finding('ran', 'recommended', moverelliRanLately($h['dir']), ['days' => intdiv(MOVERELLI_STALE, 86400)], '#/moverelli');
    }
    // one schedule for the two (the night run): Jack Emby's EmbyCache on a line of its own runs outside the night
    if ($jackDesk !== null) {
        $out[] = finding('night_schedule', 'recommended', !($h['jack_scheduled'])(), [], '#/moverelli/schedule');
    }
    // EmbyCache's list in her ini before EmbyCache's first run: her real runs wait for it — to know, nothing to do
    $pending = moverelliJackListPending($h);
    $out[] = finding('jack_list_pending', 'hint', $pending === null, ['file' => (string) $pending], '#/jack');
    if ($jackDesk !== null) {
        $out[] = finding('jack_list', 'required', !moverelliJackUncovered($jackDesk, $h['dir']),
            ['shares' => implode(', ', moverelliJackUncovered($jackDesk, $h['dir'])), 'file' => (string) ($jackDesk['lists'][0] ?? '')], '#/moverelli/setup');
    }
    // per pool (a sleeping pool: not looked at, ok null): what never moves reaches X — she never
    // reaches her target; much of the pool in the movable shares' snapshots (what she moves is freed only with the retention)
    $g = moverelliSettings($h['dir'])['global'] ?? null;
    if ($g !== null && (int) $g['move_when_used_above'] > 0) {
        $levels = moverelliLevels($g, $h);
        // one finding each: the first pool where it fails, else the first looked at (null: none looked at)
        $one = function (string $id, string $level, callable $bad, callable $params) use (&$out, $levels): void {
            $seen = array_filter($levels);
            $hit = array_filter($seen, $bad);
            $pool = array_key_first($hit ?: $seen);
            $out[] = finding($id, $level, $pool === null ? null : !$hit, $pool === null ? ['pool' => ''] : ['pool' => (string) $pool] + $params($levels[$pool]),
                $id === 'pool_snapshots' ? '#/moverelli' : '#/moverelli/setup');
        };
        $one('pool_never_moves', 'recommended', fn ($c) => $c['never'],
            fn ($c) => ['nm' => (int) floor($c['nm_pct']), 'size' => poolLevelSize($c['size']), 'x' => $c['x']]);
        $one('pool_snapshots', 'hint', fn ($c) => $c['snap_pct'] > POOL_SNAP_HINT, fn ($c) => ['size' => poolLevelSize($c['snapshots'])]);
    }
    return $out;
}
