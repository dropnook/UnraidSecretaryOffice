<?php
declare(strict_types=1);

/*
 * Jack Emby — the intern who loves Emby (say it fast: "check Emby"). He looks
 * after two tools that ship with the office:
 *
 *   embycache/  EmbyCache (from github.com/helmi1987/embycache-for-unraid):
 *               puts what people are about to watch on the fast pool, so the
 *               array disks can sleep, and brings it back to the disk it came
 *               from once it's watched
 *   gather/     the media gather (from github.com/helmi1987/media-disk-gather-for-unraid):
 *               brings the files of a film or series folder together on one
 *               array disk; it runs once before EmbyCache's first real run
 *
 * Both keep their data apart, in data/embycache and data/gather (settings,
 * exclude list, lock, logs, status) — an office update never
 * touches them. EmbyCache's settings are written by its own save_config(),
 * the gather's consolidate.ini by Jack (values checked, every one quoted).
 *
 * Runs go through "php agent.php job embycache|gather <mode>": started by the
 * host's atd (from the page) or the office's cron file (on a schedule). The job keeps the two apart (never at the same time), records
 * how it went and keeps the output of the last run.
 *
 * The Emby API key stays on the server: it is never part of the state the
 * web page gets.
 */

const JACK_LOG_TAIL = 96 * 1024;
// release: everything on the exclude list back to the array — only when he is let go (agent/desks/jack-letgo.php)
const JACK_MODES    = ['report' => ['--show-on-deck', '--compact'], 'dry' => [], 'run' => ['--run'], 'release' => ['--release']];
// measure: a dry run over the shares of the chosen libraries, only for what lies where (#8) — asked from the page only
const GATHER_MODES  = ['dry' => ['--dryrun'], 'run' => ['--run'], 'measure' => ['--dryrun']];
const GATHER_CACHE_ONLY = ['skip', 'most-free'];   // a folder only on the cache, with the cache switched on: leave it, or the disk with most free space
const JACK_SIZES_SHARES = 200;     // shares kept in sizes.json
const JACK_SIZES_ROOTS  = 64;      // disks/pools per share
const JACK_POOL_GROUPS  = 5000;    // what lies on the pool, by film/series folder: the current state, all of it (a bound against a broken list only)
const JACK_LIST_NOTE    = 'office-list.json';   // his list as his last run (or the office) left it: lines, mtime, size (jackListNote())
const JACK_LIST_REBUILD_MAX = 200000;          // files of his shares on the pool a lost list is written anew from (more: not written)
const JACK_SETTINGS = ['cache_path', 'cache_budget', 'number_episodes', 'movie_share_percent', 'max_episodes_per_series',
                       'max_resume_items', 'max_resume_movies', 'max_resume_series', 'max_favorite_series', 'use_next_up', 'min_free_percent', 'movie_mode',
                       'array_source', 'array_path', 'user_path',
                       'array_disks_glob', 'mover_debug_level', 'parallel_per_disk'];
// (2026-10-14, his setup back on tiles: create_share_root has no field — it stays as his settings have it, EmbyCache's
// default false; the wizard's give_back_unwatched_days, read by nothing, goes at the next save and with the update)
// budget mode always (2026-10-14, the owner): the budget in total is the hard cap, every person at least this much
const JACK_MIN_PERSON_BYTES = 20 << 30;
// MediaCache's own ways (the owner, 2026-10-15 — before, Jack forced rsync both ways): filling with rsync (its default,
// file by file: his live panel and «stop after this file»), giving back with Unraid's move binary (its default: through
// shfs, keeping the share's «Minimum free space», making a ZFS disk's dataset itself). Jack's shares are «Cache: yes» on his
// pool (jackShareFit() ok), so the binary moves them Cache → Array. Written into the settings, never forced by environment.
const JACK_TOOLS = ['fill_tool' => 'rsync', 'cleanup_tool' => 'mover'];
const JACK_FIXED       = ['create_share_root' => true];
// copies at once (2026-10-29, MediaCache's parallel_per_disk — helmi's: per source disk): his setup's «copies at once per
// disk» 1 … JACK_PARALLEL_MAX (1 as MediaCache's default); the total is the array's data disks × that, at most
// JACK_PARALLEL_TOTAL_MAX — the disks are the limit (more than one per HDD rarely gets faster: its head jumps)
const JACK_PARALLEL_MAX       = 4;
const JACK_PARALLEL_TOTAL_MAX = 8;
// rsync's options are the office's too (EmbyCache's defaults): never what a settings file names - rsync runs as root, and
// an option like --log-file writes wherever it says (review 2026-10-15)
const JACK_RSYNC_ARGS  = '-aAX --numeric-ids';
const GATHER_LOCK   = '/var/run/consolidate_master.lock';     // the gather's own lock (consolidate_master.sh)
const JACK_HISTORY  = 40;
const JACK_IGNORED  = '#^/(config|metadata|transcoding-temp|cache|logs|var|boot|tmp)#';   // Emby's own folders, never media
// the rule (2026-10-06): never a real gather while someone watches Emby
const JACK_WATCH_TIMEOUT = 5;       // seconds: an Emby that hasn't answered by then counts as down (the run may go)
const JACK_WATCH_PAGE    = 2;       // … asked from the page (in the agent's loop): shorter — the job itself asks again
const JACK_WATCH_EVERY   = 900;     // a scheduled gather that finds someone watching looks again every 15 min …
const JACK_WATCH_MAX     = 7200;    // … for up to 2 h, then that night is skipped
const JACK_WATCH_DURING  = 60;      // during a real gather Emby is asked once a minute; someone watching = stop after the current folder
const JACK_WATCH_SHOWN   = 10;      // at most this many watchers kept
// the media servers MediaCache speaks to (1.65.0), each with the port its container listens on
const JACK_SERVER_PORTS  = ['emby' => 8096, 'jellyfin' => 8096, 'plex' => 32400];
const JACK_SERVER_NAMES  = ['emby' => 'Emby', 'jellyfin' => 'Jellyfin', 'plex' => 'Plex'];
// a key or token: Emby/Jellyfin hex, Plex's token letters, digits, - and _ (never a space, a quote or a line break)
const JACK_KEY = '/^[A-Za-z0-9_-]{8,128}$/D';

require_once dirname(__DIR__) . '/lib/poollevel.php';    // Ms. Moverelli's limit and his half of the pool (shared with her)

define('JACK_APP', OFFICE_DIR . '/mediacache');
define('GATHER_APP', OFFICE_DIR . '/gather');
define('JACK_DATA', DATA_DIR . '/mediacache');
define('GATHER_DATA', DATA_DIR . '/gather');

desk('jack', [
    'fit'     => function (): array {
        $emby = jackContainers();
        if (!$emby) {
            return fit(false, 'no_emby');
        }
        // an intern: only under Ms. Moverelli (desk.json `needs`) — she can't come, he can't either
        if (function_exists('moverelliUnraidOk') && !moverelliUnraidOk((string) (moverelliHost()['version'])())) {
            return fit(false, 'no_moverelli', ['name' => $emby[0]['name']]);
        }
        // real runs once she is set up and keeps his list out, Unraid's mover schedule «Disabled» (jack-mover.php)
        $rule = jackMoverRule();
        return fit(true, $rule['ok'] || $rule['why'] === 'moverelli' ? 'yes' : 'yes_dry', ['name' => $emby[0]['name']]);
    },
    'start'   => fn () => jackScan(),
    'actions' => [
        'refresh'      => fn (array $r) => ['ok' => true, 'state' => jackScan()],
        'connect'      => fn (array $r) => jackConnect(textField($r, 'url'), optText($r, 'api_key'), optText($r, 'type') ?: 'emby'),
        'save'         => fn (array $r) => jackSave($r['settings'] ?? null),
        'gather_save'  => fn (array $r) => jackGatherSave($r['gather'] ?? null),
        'start_run'    => fn (array $r) => jackStart('embycache', textField($r, 'mode')),
        'gather_start' => fn (array $r) => jackStart('gather', textField($r, 'mode')),
        'schedule'     => fn (array $r) => jackSetSchedule(textField($r, 'job'), cronField($r)),
        'output'       => fn (array $r) => jackOutput(textField($r, 'tool')),
        'log'          => fn (array $r) => jackLog(textField($r, 'tool')),
        'import_preview' => fn (array $r) => jackImportPreview($r),     // taking over an earlier install: what would come over
        'import_apply'   => fn (array $r) => jackImportDo($r),          // … and doing it, with the preview's token
        // his let-go dialog (agent/desks/jack-letgo.php): the look, then — still hired — schedules off and the tick's release
        'letgo_look'     => fn (array $r) => jackLetGoLook(),
        'letgo'          => fn (array $r) => jackLetGo($r),
        'letgo_seen'     => fn (array $r) => jackLetGoSeen(),
        // «Switch the mover schedule off…» (jack-mover.php): Unraid's own ⟦Mover schedule⟧ «Disabled», as its form sets it
        'mover_off'      => fn (array $r) => jackMoverOff($r) + ['state' => jackScan()],
        // the part «progress» (desk.json): the live panel while EmbyCache runs for real (jack-progress.php) — two RAM files
        // — and, while the night run with Ms. Moverelli goes, its card (jackNightNow(): phases, who works, her share)
        // (his step of it at work: his view by the night's word — jackNightProgress())
        'progress'       => fn (array $r) => ['ok' => true, 'state' => jackProgressNow(jackNightNow())],
        // «Stop after this file» in that panel: EmbyCache's stop request with why `user` (jack-progress.php); while the
        // night run goes, the whole night stops after the file in flight (nightStopAsk(), as on her card)
        // «Set up from scratch» (2026-10-14, the owner: really zero — no copy)
        'reset'          => fn (array $r) => jackReset(),
        // the setup's pool simulator, real data: the pool measured now — only on the user's click (it may wake the pool)
        'stop'           => fn (array $r) => ['ok' => true, 'asked' => jackNightNow() !== null ? nightStopAsk() : jackStopAsk(), 'state' => jackScan()],
    ],
    'jobs'    => [
        'embycache' => fn (array $args) => jackJob('embycache', $args),
        'gather'    => fn (array $args) => jackJob('gather', $args),
    ],
    'checks'  => fn () => jackChecks(),
    'metrics' => fn (): array => jackMetrics(),
]);

/**
 * Jack Emby's numbers for Prometheus (lib/metrics.php, once a minute): what
 * EmbyCache keeps on the pool (his state, as of his last look) and how the
 * last real run of each tool went (his list of runs).
 */
function jackMetrics(?string $state = null, ?string $history = null): array
{
    $cache = metricsCached($state ?? deskFile('jack'), function (string $f): ?array {
        $j = readJson($f);
        return $j && !empty($j['configured']) && is_array($j['cache'] ?? null) ? ['files' => (int) ($j['cache']['files'] ?? 0), 'bytes' => (int) ($j['cache']['bytes'] ?? 0)] : null;
    });
    $runs = metricsCached($history ?? JACK_DATA . '/office-history.json', function (string $f): array {
        $last = [];
        foreach ((array) (readJson($f)['runs'] ?? []) as $r) {       // newest first
            $tool = is_array($r) ? ($r['tool'] ?? null) : null;
            // runs that never started (refused, skipped while someone watched Emby) are no runs; one stopped for a watcher went well
            if (in_array($tool, ['embycache', 'gather'], true) && !isset($last[$tool]) && ($r['mode'] ?? '') === 'run'
                && !in_array($r['result'] ?? '', ['refused', 'skipped'], true)) {
                $last[$tool] = ['ok' => in_array($r['result'] ?? '', ['ok', 'stopped'], true), 'finished' => (int) ($r['finished'] ?? 0)];
            }
        }
        return $last;
    });
    $out = [];
    if ($cache !== null) {
        $out[] = metricsGauge('uso_jack_cache_bytes', 'What EmbyCache keeps on the pool right now (as of Jack\'s last look)', $cache['bytes']);
        $out[] = metricsGauge('uso_jack_cache_files', 'Files EmbyCache keeps on the pool right now (as of Jack\'s last look)', $cache['files']);
    }
    $out[] = metricsGauge('uso_jack_last_run_ok', 'Whether the last real run of EmbyCache / the gather went well',
        array_map(fn ($tool) => [['tool' => $tool], $runs[$tool]['ok']], array_keys($runs)));
    $out[] = metricsGauge('uso_jack_last_run_end_timestamp_seconds', 'When the last real run of EmbyCache / the gather ended',
        array_map(fn ($tool) => [['tool' => $tool], $runs[$tool]['finished']], array_keys($runs)));
    return $out;
}

// ===================================================================== state

/**
 * 1.65.0: his EmbyCache folder still waiting to come over (the step `mediacache` was pending: a run went at the agent's
 * start) — tried again now, cheaply: only while data/mediacache isn't there and data/embycache is (agent/lib/migrate.php)
 */
function jackMigratePending(): void
{
    if (function_exists('officeMigrateRetry') && !is_dir(JACK_DATA) && is_dir(DATA_DIR . '/embycache') && !is_link(DATA_DIR . '/embycache')) {
        officeMigrateRetry('mediacache');
    }
}

/**
 * Set up, his list there — and never «keep nothing» by accident (H1, 2026-10-21). Before his first real run nothing of his
 * lies on the pool: written empty (the lab, 2026-10-16: with Ms. Moverelli hired his real run is the joint night run, which
 * waited for a list only that run makes — a fresh install never started; MediaCache rewrites it at its first real run).
 * After one (or a list taken over from an old EmbyCache), a list that went missing — or was emptied by anyone but his runs
 * and the office (its note, JACK_LIST_NOTE) — is written anew from what of his shares lies on his pool: an incomplete plan
 * (a media server that can't be asked) «keeps the list as it was», and an empty one let Ms. Moverelli move his films back
 * to the array. More than his films may stand on it (a download not yet moved): his next complete run sets it right.
 * Where the pool can't be looked at (asleep and not $o['wake'], unreadable, too many files) nothing is written: a missing
 * list makes Smart Mover leave the shares that name it (its ERROR) and his real runs refuse (jack_list_lost). Never over a
 * link. $o (the tests): wake, ran, running, shares, asleep. Answer: 'written' (empty, the first), 'rebuilt', 'lost', null.
 */
function jackListEnsure(?array $settings, string $dir = JACK_DATA, array $o = []): ?string
{
    $f = "$dir/mediacache_exclude.txt";
    if (!jackConfigured($settings) || !is_dir($dir) || is_link($f) || (file_exists($f) && !is_file($f))) {
        return null;
    }
    clearstatcache(true, $f);
    if (is_file($f)) {
        // there: untouched — unless emptied since his last run or the office wrote it (none of his runs going); seen with
        // entries: noted (once per change) — a list that goes missing later held films
        $note = readJson("$dir/" . JACK_LIST_NOTE);
        $seen = is_array($note) && [(int) ($note['mtime'] ?? 0), (int) ($note['size'] ?? -1)] === [(int) filemtime($f), (int) filesize($f)];
        if (trim((string) @file_get_contents($f, false, null, 0, 4096)) !== '') {
            if (!$seen) {
                jackListNote($dir);
            }
            return null;
        }
        if ($seen || !is_array($note) || (int) ($note['lines'] ?? 0) <= 0 || !($o['ran'] ?? jackListRan($dir))
            || ($o['running'] ?? fn (): bool => jackJobInfo('embycache')['running'])()) {
            return null;
        }
    } elseif (!($o['ran'] ?? jackListRan($dir))) {
        writeAtomic($f, '', 0600, 0, 0);
        jackListNote($dir);
        logLine('Jack Streamer: his list written, empty until his first real run (Ms. Moverelli\'s joint night run waits for it)');
        return 'written';
    }
    $was = is_file($f) ? 'emptied' : 'missing';
    $cache = rtrim((string) $settings['cache_path'], '/');
    $asleep = $cache !== '' && baseAsleep(basename($cache), $o['asleep'] ?? sleepingDisks());
    $paths = $cache === '' || ($asleep && empty($o['wake'])) ? null : jackListFromPool($cache, $o['shares'] ?? jackMappedShares($settings));
    if ($paths === null) {
        logLine("Jack Streamer: his list is $was — not written anew now (" . ($asleep && empty($o['wake']) ? 'the pool sleeps' : 'the pool can\'t be read')
            . '): Ms. Moverelli leaves the shares that name it, his real runs wait');
        return 'lost';
    }
    writeAtomic($f, $paths ? implode("\n", $paths) . "\n" : '', 0600, 0, 0);
    jackListNote($dir, ['rebuilt' => ['at' => time(), 'was' => $was, 'files' => count($paths)]]);
    logLine("Jack Streamer: his list was $was after his runs — written anew from what of his shares lies on the pool (" . count($paths)
        . ' files); his next complete run sets it right');
    return 'rebuilt';
}

/**
 * Has he worked here — a real run of MediaCache's, a list taken over from an old EmbyCache, or his list seen with entries
 * (its note's `had`)? Then a lost list held films.
 */
function jackListRan(string $dir = JACK_DATA): bool
{
    if (is_file("$dir/mediacache_origin.json") || glob("$dir/mediacache_{exclude.txt,origin.json}.before-import-*", GLOB_BRACE)
        || (readJson("$dir/" . JACK_LIST_NOTE)['had'] ?? null) === true) {
        return true;
    }
    $real = fn (mixed $r): bool => is_array($r) && ($r['tool'] ?? '') === 'embycache' && in_array($r['mode'] ?? '', ['run', 'release'], true)
        && !in_array($r['result'] ?? '', ['refused', 'skipped'], true);
    return (bool) array_filter(jackHistory($dir), $real) || $real(readJson("$dir/office-run.json"));
}

/**
 * Every file of his shares on his pool (absolute pool paths, as MediaCache writes them; no links, no line breaks), sorted —
 * null: a share's folder can't be read or there are more than JACK_LIST_REBUILD_MAX
 */
function jackListFromPool(string $cache, array $shares): ?array
{
    $out = [];
    foreach ($shares as $share) {
        $root = "$cache/$share";
        if (!is_dir($root)) {
            continue;
        }
        try {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                if ($file->isLink() || !$file->isFile() || str_contains($path, "\n") || str_contains($path, "\r")) {
                    continue;
                }
                $out[] = $path;
                if (count($out) > JACK_LIST_REBUILD_MAX) {
                    return null;
                }
            }
        } catch (UnexpectedValueException) {
            return null;
        }
    }
    sort($out, SORT_STRING);
    return array_values(array_unique($out));
}

/**
 * The office's note of his list as it was left — by his run (jackJob()), the office, the import or seen by his look: its
 * lines, mtime and size (a list emptied by anyone else is told apart by them), `had` (it once held entries). $extra:
 * `rebuilt` (the Team Lead's hint) — kept until his next complete run ($complete: cleared); unknown keys kept.
 */
function jackListNote(string $dir = JACK_DATA, array $extra = [], bool $complete = false): void
{
    $f = "$dir/mediacache_exclude.txt";
    clearstatcache(true, $f);
    if (!is_file($f) || is_link($f)) {
        return;
    }
    $note = (array) (readJson("$dir/" . JACK_LIST_NOTE) ?? []);
    if ($complete) {
        unset($note['rebuilt']);
    }
    $lines = count(array_filter(array_map('trim', (array) @file($f, FILE_IGNORE_NEW_LINES)), fn ($l) => $l !== ''));
    writeAtomic("$dir/" . JACK_LIST_NOTE, jsonEncode(['v' => 1, 'lines' => $lines, 'mtime' => (int) filemtime($f), 'size' => (int) filesize($f),
        'had' => $lines > 0 || ($note['had'] ?? null) === true] + $extra + $note), 0600, 0, 0);
}

function jackScan(): array
{
    jackMigratePending();
    $settings = jackReadSettings();
    jackListEnsure($settings);
    $gather = jackGatherSettings();
    $jobs = ['embycache' => jackJobInfo('embycache'), 'gather' => jackJobInfo('gather')];
    $state = [
        'time'       => time(),
        'python'     => jackPython(),
        'emby'       => jackContainers(),
        'docker'     => houseDockerState(),     // 'off' / 'silent': his media servers can't be seen — the page says so (T36)
        'versions'   => jackVersions(),
        'configured' => jackConfigured($settings),
        'settings'   => $settings !== null ? jackSettingsPublic($settings) : null,
        'shares'     => $settings !== null ? jackSharesSized(jackShares($settings)) : [],
        'cache'      => jackCacheStats($settings),
        'pool'       => jackPoolUsage($settings),
        // Ms. Moverelli's rules on their own (2026-10-14: a fresh Jack Streamer has no pool of his yet — his setup shows
        // her X, offset, «guaranteed» and age rule all the same): {x, y, k, min_age}, null without her ini
        'moverelli'  => jackMoverelliRules(),
        'gather'     => [
            'settings' => $gather,
            'ready'    => jackGatherReady(),
            'last'     => readJson(GATHER_DATA . '/last-real.json'),
            'waiting'  => jackGatherWaiting(),
        ],
        'jobs'       => $jobs,
        'progress'   => ($prog = jackProgressState($jobs['embycache']))['progress'],     // a real EmbyCache run: the live panel (jack-progress.php)
        'stopping'   => $prog['stopping'],         // … asked to stop after the file it is on (why: user, mover)
        'last'       => jackLastRun($history = jackHistory()),    // the newest real run from his list (never «never» while one goes)
        'last_status' => readJson(JACK_DATA . '/status.json'),       // what the last run of any mode did: the result box under the tiles
        'history'    => array_slice($history, 0, 20),
        'schedules'  => ['embycache' => officeJobSchedule('embycache'), 'gather' => officeJobSchedule('gather')],
        // the night run with Ms. Moverelli (agent/lib/nightrun.php): its one schedule (her line), tonight's plan, the last night
        'night'      => jackNight(),
        'foreign'    => jackForeignSchedules(),
        'pools'      => jackPools(),
        'share_info' => jackShareInfo(),
        'pool_dirs'  => jackPoolDirs(),
        'pool_sizes' => jackPoolSizes(),        // each pool's size in bytes (on ZFS the whole pool): the budget never above it
        // copies at once (2026-10-29): the array's data disks — his setup's «copies at once per disk» × these, at most the cap
        'parallel'   => ['disks' => jackArrayDisks(), 'max' => JACK_PARALLEL_MAX, 'total_max' => JACK_PARALLEL_TOTAL_MAX],
        'old_clone'  => is_dir(JACK_DATA . '/app/.git'),
        // Unraid's mover: may real runs go (its schedule «Disabled», Ms. Moverelli supervising with his list kept out), is it at work
        'mover'      => jackMoverState(),
        // the joint run with Ms. Moverelli waiting on its schedule (Unraid's mover, Mr. Backupsy's local part — M17)
        'waiting'    => ['embycache' => jackRunWaiting('embycache'), 'gather' => jackRunWaiting('gather'), 'night' => jackRunWaiting('moverelli')],
        'letgo'      => jackLetGoNote(),       // hired again: what he switched off when he was let go (said once)
    ];
    writeAtomic(deskFile('jack'), jsonEncode($state));
    return $state;
}

function jackPython(): ?string
{
    [$exit, $out] = run(['python3', '--version'], 10);
    return $exit === 0 ? trim(str_replace('Python', '', $out)) : null;
}

/** The versions of the two tools that ship with the office */
function jackVersions(): array
{
    $lib = (string) @file_get_contents(JACK_APP . '/mediacache_lib.py', false, null, 0, 4096);
    $sh = (string) @file_get_contents(GATHER_APP . '/consolidate_master.sh', false, null, 0, 1024);
    return [
        'embycache' => preg_match('/__version__\s*=\s*"([^"]+)"/', $lib, $m) ? $m[1] : null,
        'gather'    => preg_match('/\((V[\d.]+)\)/', $sh, $m) ? $m[1] : null,
    ];
}

/** Media server containers (Emby, Jellyfin, Plex) with what the setup needs: kind, address and the folders they see */
function jackContainers(): array
{
    $found = [];
    foreach (houseContainers() as $c) {
        $type = jackServerType($c['image']);
        if ($type === null) {
            continue;
        }
        $inspect = houseInspect($c['name']) ?? [];
        $ip = null;
        foreach ((array) ($inspect['NetworkSettings']['Networks'] ?? []) as $net) {
            $ip = ($net['IPAddress'] ?? '') ?: $ip;
        }
        $inner = JACK_SERVER_PORTS[$type];
        $port = $inner;
        foreach ((array) ($inspect['HostConfig']['PortBindings']["$inner/tcp"] ?? []) as $b) {
            if (!empty($b['HostPort'])) {
                $port = (int) $b['HostPort'];
                $ip = null;          // published on the host
            }
        }
        if (($inspect['HostConfig']['NetworkMode'] ?? '') === 'host') {
            $ip = null;              // host network (Plex often): the host's address
        }
        $mounts = [];
        foreach ((array) ($inspect['Mounts'] ?? []) as $m) {
            $dest = (string) ($m['Destination'] ?? '');
            if ($dest !== '' && !preg_match('#^/(config|dev|tmp|cache|transcode)#', $dest)) {
                $mounts[$dest] = (string) ($m['Source'] ?? '');
            }
        }
        $found[] = ['name' => $c['name'], 'image' => $c['image'], 'type' => $type, 'running' => $c['running'],
                    'url' => 'http://' . ($ip ?? jackHostIp()) . ':' . $port, 'mounts' => $mounts];
    }
    return $found;
}

/**
 * Is this the image of an Emby server? Only the last part of the image name
 * counts, without registry and tag: emby/embyserver, linuxserver/emby,
 * lscr.io/linuxserver/emby, binhex/arch-emby, embyserver_arm64v8 … — but not
 * tools around Emby such as EmbyCache or EmbyStat. Container names never
 * matter: they are the user's own.
 */
function jackIsServerImage(string $image): bool
{
    return jackServerType($image) !== null;
}

/**
 * Which media server an image is: emby, jellyfin, plex — or null. The same rule for each: the last part of the image name
 * without registry and tag (emby/embyserver, linuxserver/emby, jellyfin/jellyfin, lscr.io/linuxserver/jellyfin,
 * plexinc/pms-docker, linuxserver/plex, binhex/arch-plex …); tools around them (EmbyStat, Jellyseerr, Tautulli, plex-meta-
 * manager …) are not servers.
 */
function jackServerType(string $image): ?string
{
    $name = strtolower(preg_replace('#[:@].*$#', '', basename(preg_replace('#@.*$#', '', $image))));
    return match (true) {
        (bool) preg_match('/(^|[-_.])emby(server)?($|[-_.])/', $name) => 'emby',
        (bool) preg_match('/(^|[-_.])jellyfin(-server)?($|[-_.])/', $name) => 'jellyfin',
        (bool) preg_match('/(^|[-_.])(plex(mediaserver)?|pms-docker)$/', $name) => 'plex',      // at the end: plex-meta-manager is a tool
        default => null,
    };
}

function jackHostIp(): string
{
    $url = houseGuiUrl() ?? 'http://127.0.0.1';
    return (string) parse_url($url, PHP_URL_HOST);
}

/** The pools EmbyCache could cache onto */
function jackPools(): array
{
    $pools = [];
    foreach (mountTable() as $m) {
        if (preg_match('#^/mnt/([^/]+)$#', $m['mount'], $x) && !preg_match('/^(disk\d+|user0?|disks|remotes|addons|rootshare)$/', $x[1])
            && in_array($m['fs'], ['zfs', 'btrfs', 'xfs'], true)) {
            $pools[$m['mount']] = true;
        }
    }
    ksort($pools);
    return array_keys($pools);
}

/**
 * The pools' sizes in bytes: on ZFS the whole pool (used + avail of its root dataset, one `zfs list` — no disk is read),
 * else the file system's size. [mount => bytes]; a pool not mounted or unreadable is left out.
 */
function jackPoolSizes(?array $mounts = null, ?callable $zfs = null, ?array $poolMounts = null): array
{
    $mounts ??= mountTable();
    $zfs ??= function (): array {
        [$exit, $out] = run(['zfs', 'list', '-Hp', '-d', '0', '-o', 'name,used,avail'], 10);
        $sizes = [];
        foreach ($exit === 0 ? explode("\n", trim($out)) : [] as $line) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) === 3 && is_numeric($f[1]) && is_numeric($f[2])) {
                $sizes[$f[0]] = (int) $f[1] + (int) $f[2];
            }
        }
        return $sizes;
    };
    $pools = array_flip($poolMounts ?? jackPools());   // the tests name their pools (a test server's pools change)
    $z = null;
    $out = [];
    foreach ($mounts as $m) {
        if (!isset($pools[$m['mount']])) {
            continue;
        }
        if ($m['fs'] === 'zfs') {
            $z ??= $zfs();
            $size = $z[$m['source']] ?? null;
        } else {
            $size = @disk_total_space($m['mount']) ?: null;
        }
        if ($size) {
            $out[$m['mount']] = (int) $size;
        }
    }
    return $out;
}

function jackReadSettings(string $dir = JACK_DATA): ?array
{
    $j = json_decode((string) @file_get_contents("$dir/mediacache_settings.json"), true);
    return is_array($j) ? jackTools($j) : null;
}

/**
 * MediaCache's settings as Jack uses them: its own tools (JACK_TOOLS — an old forced rsync for the give-back reads as the
 * move binary; the next save writes it so). The share's ZFS dataset is MediaCache's (create_share_root, 1.65.0 — before, Jack made it himself before a real run),
 * copies at once per disk as his setup chose (1 … JACK_PARALLEL_MAX, else 1; the total in his file the same — his runs
 * get the array's disks × that, jackPyEnv()).
 */
function jackTools(array $s): array
{
    // «back to the disk they came from» is gone (the owner, 2026-10-16): Unraid's share rules and its move binary decide
    unset($s['return_to_origin']);
    $per = jackParallelPerDisk($s);
    return array_replace($s, JACK_TOOLS, JACK_FIXED, ['parallel_per_disk' => $per, 'parallel_total' => $per]);
}

/** His setup's «copies at once per disk»: an int 1 … JACK_PARALLEL_MAX, anything else (none, an older file) 1 */
function jackParallelPerDisk(?array $s): int
{
    $v = $s['parallel_per_disk'] ?? 1;
    return is_int($v) && $v >= 1 && $v <= JACK_PARALLEL_MAX ? $v : 1;
}

/** The array's data disks (disks.ini `type="Data"`, an empty slot left out) — none known: 1. $ini: the tests */
function jackArrayDisks(?array $ini = null): int
{
    $n = 0;
    foreach ($ini ?? readCfg(disksIniFile(), true) as $d) {
        if (is_array($d) && ($d['type'] ?? '') === 'Data' && !str_starts_with((string) ($d['status'] ?? ''), 'DISK_NP')) {
            $n++;
        }
    }
    return max(1, $n);
}

/** Copies at once in all: the array's disks × per disk, at most JACK_PARALLEL_TOTAL_MAX */
function jackParallelTotal(int $perDisk, int $disks): int
{
    return max(1, min(JACK_PARALLEL_TOTAL_MAX, $perDisk * max(1, $disks)));
}

/** The settings as the page may see them — without the API keys */
function jackSettingsPublic(array $s): array
{
    $instances = [];
    foreach ($s['instances'] ?? [] as $i) {
        $instances[] = ['servername' => (string) ($i['servername'] ?? ''), 'url' => (string) ($i['url'] ?? ''),
                        'type' => jackType($i['type'] ?? null), 'has_key' => !empty($i['api_key']), 'path_mappings' => (array) ($i['path_mappings'] ?? [])];
    }
    $out = ['instances' => $instances, 'libraries' => array_values((array) ($s['libraries'] ?? [])),
            'library_types' => (array) ($s['library_types'] ?? []), 'valid_users' => $s['valid_users'] ?? []];
    foreach (JACK_SETTINGS as $k) {
        $out[$k] = $s[$k] ?? null;
    }
    return $out;
}

// ===================================================================== shares

/** /boot/config/shares/<share>.cfg as key => value */
function jackShareCfg(string $share): array
{
    return preg_match('/^[\w.\- ]+$/uD', $share) ? readCfg("/boot/config/shares/$share.cfg") : [];
}

/** Every user share (by its configuration on the flash) */
function jackAllShares(): array
{
    $out = [];
    foreach (glob('/boot/config/shares/*.cfg') ?: [] as $f) {
        $out[] = basename($f, '.cfg');
    }
    sort($out);
    return $out;
}

/** Every share with where it lives (for the setup: does it suit the chosen pool?) */
function jackShareInfo(): array
{
    $out = [];
    foreach (jackAllShares() as $share) {
        $cfg = jackShareCfg($share);
        $out[$share] = ['use' => (string) ($cfg['shareUseCache'] ?? ''), 'primary' => (string) ($cfg['shareCachePool'] ?? ''),
                        'secondary' => (string) ($cfg['shareCachePool2'] ?? '')];
    }
    return $out;
}

/** The share folders at the top of each pool (EmbyCache needs the share's folder there — on ZFS a dataset) */
function jackPoolDirs(): array
{
    $out = [];
    foreach (jackPools() as $pool) {
        $out[$pool] = array_values(array_map('basename', glob("$pool/*", GLOB_ONLYDIR) ?: []));
    }
    return $out;
}

/** The shares behind the mapped library folders (/mnt/user/<share>/…) */
function jackMappedShares(array $settings): array
{
    $shares = [];
    $user = rtrim((string) ($settings['user_path'] ?? '/mnt/user'), '/');
    foreach ((array) ($settings['instances'] ?? []) as $i) {
        foreach (array_merge((array) ($settings['path_mappings'] ?? []), (array) ($i['path_mappings'] ?? [])) as $host) {
            if (is_string($host) && preg_match('#^' . preg_quote($user, '#') . '/([^/]+)#', $host, $m)) {
                $shares[$m[1]] = true;
            }
        }
    }
    ksort($shares);
    return array_keys($shares);
}

/**
 * Does a share suit EmbyCache with this pool? EmbyCache moves between the
 * array (/mnt/user0) and one pool: the share must have files on the array.
 *   ok          primary = the pool, secondary = array (the usual "Cache: yes")
 *   other_pool  secondary = array, but its primary is another pool
 *   array_only  array only: works, the way back through Unraid's move binary
 *   no_array    primary pool → secondary pool: never on the array, nothing to do
 *   pool_only   prefer/only: lives on a pool, the mover would pull everything back
 */
function jackShareFit(array $cfg, string $pool): string
{
    $use = strtolower((string) ($cfg['shareUseCache'] ?? ''));
    $primary = (string) ($cfg['shareCachePool'] ?? '');
    $secondary = (string) ($cfg['shareCachePool2'] ?? '');
    return match (true) {
        $use === 'no' || $use === ''             => 'array_only',
        $use === 'yes' && $secondary !== ''      => 'no_array',
        $use === 'yes' && $primary !== $pool     => 'other_pool',
        $use === 'yes'                           => 'ok',
        default                                  => 'pool_only',
    };
}

function jackShares(array $settings, ?array $mounts = null): array
{
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    $pool = basename($cache);
    $asleep = $cache !== '' && baseAsleep($pool, sleepingDisks());   // the caretaker looks every 30 min: don't wake the pool
    $mounts ??= mountTable();
    $zfs = $cache !== '' && jackPoolDataset($cache, $mounts) !== null;
    $out = [];
    foreach (jackMappedShares($settings) as $share) {
        $cfg = jackShareCfg($share);
        $fit = jackShareFit($cfg, $pool);
        // EmbyCache won't create it; null = the pool sleeps, not looked
        $root = $cache === '' ? false : ($asleep ? null : is_dir("$cache/$share"));
        $out[] = [
            'share'     => $share,
            'use'       => (string) ($cfg['shareUseCache'] ?? ''),
            'primary'   => (string) ($cfg['shareCachePool'] ?? ''),
            'secondary' => (string) ($cfg['shareCachePool2'] ?? ''),
            'include'   => (string) ($cfg['shareInclude'] ?? ''),
            'fit'       => $fit,
            'root'      => $root,
            // missing on a ZFS pool that is the share's own: his next real run makes the dataset, as Unraid would (MediaCache's ensure_share_root())
            'auto'      => $root === false && $zfs && $fit === 'ok' && jackDatasetName($share),
            // there, but on ZFS a plain folder inside the pool's own dataset (not the share's dataset): null = not ZFS, not there or not looked
            'dataset'   => $root === true && $zfs ? jackIsDataset("$cache/$share", $mounts) : null,
        ];
    }
    return $out;
}

/*
 * The share's dataset on a ZFS pool (inbox #14, seen on Tower with 7.3.3): Unraid's shfs makes `<pool>/<share>` with a
 * plain `zfs create` when something is written through /mnt/user and the share has no folder on the pool, and destroys it
 * again when the mover has emptied it. EmbyCache writes straight to /mnt/<pool>/<share>: a mkdir there would be a plain
 * folder inside the pool's own dataset — no snapshots, no backup per share, not counted as the share's. So before a real
 * run fetches anything he makes the missing datasets himself, the way shfs does.
 */
/** The ZFS dataset mounted at the pool's folder (/mnt/<pool>) — null: not ZFS */
function jackPoolDataset(string $cache, array $mounts): ?string
{
    foreach ($mounts as $m) {
        if ($m['mount'] === $cache && $m['fs'] === 'zfs' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\-]*(\/[A-Za-z0-9][A-Za-z0-9_.: \-]*)*$/D', $m['source'])) {
            return $m['source'];
        }
    }
    return null;
}

/**
 * The ZFS array disks where the share's folder is a plain folder in the disk's own dataset (shfs makes a dataset there too):
 * the disks the gather's numbers say hold files of the share, ZFS, and no dataset mounted at /mnt/diskN/<share> — from the
 * mount table only, no disk touched
 */
function jackPlainDisks(string $share, array $sizes, array $mounts, string $mnt = '/mnt'): array
{
    $out = [];
    foreach ((array) ($sizes['shares'][$share]['roots'] ?? []) as $disk => $n) {
        $disk = (string) $disk;
        if (preg_match('/^disk\d{1,3}$/D', $disk) && (int) ($n['files'] ?? 0) > 0 && jackPoolDataset("$mnt/$disk", $mounts) !== null
            && !jackIsDataset("$mnt/$disk/$share", $mounts)) {
            $out[] = $disk;
        }
    }
    sort($out, SORT_NATURAL);
    return $out;
}

/** A share name ZFS takes as a dataset name as it is (as shfs passes it) */
function jackDatasetName(string $share): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.: \-]{0,200}$/D', $share);
}

/** Is a ZFS dataset mounted at exactly this folder? */
function jackIsDataset(string $path, array $mounts): bool
{
    foreach ($mounts as $m) {
        if ($m['mount'] === $path && $m['fs'] === 'zfs') {
            return true;
        }
    }
    return false;
}

/**
 * The share rows with what lies where (#8): the gather's last numbers per share — any run that
 * indexed it, dry or real or a measurement; a share a later run didn't cover keeps its older
 * numbers with their date — and ZFS's own count of the share's dataset on its awake pools.
 */
function jackSharesSized(array $rows, ?array $sizes = null, ?array $live = null): array
{
    $sizes ??= jackGatherSizes(readJson(GATHER_DATA . '/sizes.json'), readJson(GATHER_DATA . '/status.json'));
    $live ??= jackShareLive($rows);
    $mounts = mountTable();
    foreach ($rows as &$row) {
        $row['sizes'] = jackShareSized($sizes, (string) $row['share']);
        $row['live'] = $live[$row['share']] ?? [];
        $row['plain_disks'] = jackPlainDisks((string) $row['share'], $sizes, $mounts);
    }
    unset($row);
    return $rows;
}

/**
 * The kept numbers ($kept: sizes.json — `{v: 1, shares: {<share>: {at, mode, roots: {<disk>: {bytes, files}}}}}`)
 * with a gather's status on top ($status: its `sizes` / `sizes_at`): every share the run indexed gets the
 * run's numbers (when newer than the kept ones), the others keep theirs. Only exactly that shape is taken, from either side.
 */
function jackGatherSizes(?array $kept, ?array $status, ?string $mode = null): array
{
    $out = ['v' => 1, 'shares' => []];
    $take = function (mixed $roots): ?array {
        if (!is_array($roots) || count($roots) > JACK_SIZES_ROOTS) {
            return null;
        }
        $ok = [];
        foreach ($roots as $name => $r) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $name) || !is_array($r)
                || !is_int($r['bytes'] ?? null) || !is_int($r['files'] ?? null) || $r['bytes'] < 0 || $r['files'] < 0) {
                return null;
            }
            $ok[$name] = ['bytes' => $r['bytes'], 'files' => $r['files']];
        }
        return $ok;
    };
    $shareOk = fn (mixed $s): bool => is_string($s) && (bool) preg_match('/^[\w.\- ]{1,100}$/uD', $s);
    if (($kept['v'] ?? null) === 1 && is_array($kept['shares'] ?? null)) {
        foreach ($kept['shares'] as $share => $e) {
            $roots = $take($e['roots'] ?? null);
            if ($shareOk($share) && $roots !== null && is_int($e['at'] ?? null) && is_string($e['mode'] ?? null)) {
                $out['shares'][$share] = ['at' => $e['at'], 'mode' => $e['mode'], 'roots' => $roots];
            }
        }
    }
    $at = $status['sizes_at'] ?? null;
    if (is_array($status['sizes'] ?? null) && is_int($at) && $at > 0) {
        $mode ??= ($status['mode'] ?? '') === 'run' ? 'run' : 'dry';
        foreach ($status['sizes'] as $share => $roots) {
            $roots = $take($roots);
            if ($shareOk($share) && $roots !== null && $at > ($out['shares'][$share]['at'] ?? 0)) {
                $out['shares'][$share] = ['at' => $at, 'mode' => $mode, 'roots' => $roots];
            }
        }
    }
    if (count($out['shares']) > JACK_SIZES_SHARES) {
        uasort($out['shares'], fn ($a, $b) => $b['at'] <=> $a['at']);
        $out['shares'] = array_slice($out['shares'], 0, JACK_SIZES_SHARES, true);
    }
    ksort($out['shares']);
    return $out;
}

/** After a gather: its numbers into sizes.json (merged with the kept ones) */
function jackGatherSizesKeep(array $status, string $mode, string $dir = GATHER_DATA): void
{
    if (!isset($status['sizes'])) {
        return;
    }
    $file = "$dir/sizes.json";
    writeAtomic($file, jsonEncode(jackGatherSizes(readJson($file), $status, $mode)), 0600, 0, 0);
}

/** One share's numbers for the page: the places holding files, the biggest first; null = never measured */
function jackShareSized(array $sizes, string $share): ?array
{
    $e = $sizes['shares'][$share] ?? null;
    if (!is_array($e)) {
        return null;
    }
    $roots = [];
    foreach ($e['roots'] as $name => $r) {
        if ($r['files'] > 0) {
            $roots[] = ['name' => (string) $name, 'bytes' => $r['bytes'], 'files' => $r['files']];
        }
    }
    usort($roots, fn ($a, $b) => [$b['bytes'], $a['name']] <=> [$a['bytes'], $b['name']]);
    return ['at' => $e['at'], 'mode' => $e['mode'], 'roots' => $roots];
}

/**
 * ZFS's own count, right now, of each share's dataset on the pools the share lives on (`used`:
 * with what its snapshots and datasets below hold) — one `zfs list -d 1` per pool, only on awake
 * pools; btrfs has no cheap count (a du would read every folder), so none there.
 */
function jackShareLive(array $rows, ?array $mounts = null, ?array $asleep = null, ?callable $zfs = null): array
{
    $mounts ??= mountTable();
    $asleep ??= sleepingDisks();
    $zfs ??= function (string $pool): string {
        [$exit, $out] = run(['zfs', 'list', '-Hp', '-o', 'name,used', '-d', '1', $pool], 10);
        return $exit === 0 ? $out : '';
    };
    $pools = [];
    foreach ($mounts as $m) {
        if ($m['fs'] === 'zfs' && preg_match('#^/mnt/([a-z0-9_-]+)$#D', $m['mount'], $x) && preg_match('/^[A-Za-z0-9_.-]+$/D', $m['source'])
            && !preg_match('/^(disk\d+|user0?|disks|remotes|addons|rootshare)$/D', $x[1])) {
            $pools[$x[1]] = $m['source'];
        }
    }
    $want = [];
    foreach ($rows as $row) {
        if (strtolower((string) ($row['use'] ?? '')) === 'no') {
            continue;
        }
        foreach ([(string) ($row['primary'] ?? ''), (string) ($row['secondary'] ?? '')] as $pool) {
            if ($pool !== '' && isset($pools[$pool]) && !baseAsleep($pool, $asleep)) {
                $want[$pool][] = (string) $row['share'];
            }
        }
    }
    $out = [];
    foreach ($want as $pool => $shares) {
        $used = [];
        foreach (preg_split('/\R/', $zfs($pools[$pool])) as $line) {
            $f = explode("\t", $line);
            if (count($f) === 2 && ctype_digit($f[1])) {
                $used[$f[0]] = (int) $f[1];
            }
        }
        foreach ($shares as $share) {
            if (isset($used[$pools[$pool] . '/' . $share])) {
                $out[$share][] = ['pool' => $pool, 'used' => $used[$pools[$pool] . '/' . $share]];
            }
        }
    }
    return $out;
}

/** The shares a measurement reads: those behind the chosen libraries, as far as they exist */
function jackMeasureShares(?array $settings = null, ?array $all = null): array
{
    $settings ??= jackReadSettings();
    return $settings ? array_values(array_intersect(jackMappedShares($settings), $all ?? jackAllShares())) : [];
}

/** The measurement's own ini (the gather's consolidate.ini stays as it is): the libraries' shares, their pools and EmbyCache's */
function jackWriteMeasureIni(array $shares, array $emby, string $gatherDir = GATHER_DATA, string $jackDir = JACK_DATA): string
{
    $g = jackGatherSettings() ?? [];
    $set = ['shares' => $shares, 'min_free_gb' => (int) ($g['min_free_gb'] ?? 256), 'dup_check' => 'size', 'move_cache' => false];   // measuring moves nothing
    $ini = jackGatherIni($set, jackGatherPools($shares, $emby), "$gatherDir/consolidate.log", "$jackDir/mediacache_exclude.txt");
    writeAtomic("$gatherDir/measure.ini", $ini, 0600, 0, 0);
    return $ini;
}

// ===================================================================== what's on the pool

/**
 * What EmbyCache keeps on the pool right now (its exclude list: absolute pool paths), by film or series folder:
 * files, bytes, the disks they go back to and `since` — when the newest of them came onto the pool (its ctime: the
 * copy made it; read with the size, one stat). Newest first; the page filters and pages them. `shares`: per share over
 * the whole list {files, bytes} — the first folder below the pool, as the groups (his share rows say it).
 */
function jackCacheStats(?array $settings, string $dir = JACK_DATA): array
{
    $list = @file("$dir/mediacache_exclude.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $bytes = 0;
    $groups = [];
    $shares = [];             // per share, over the whole list (the groups are cut at JACK_POOL_GROUPS): his page's share rows
    $cache = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    foreach ($list as $path) {
        $size = (int) @filesize($path);
        $since = (int) @filectime($path);          // PHP's stat cache: the same stat as the size
        $bytes += $size;
        // group by share and the first folder below it (a film folder or a series)
        $rel = $cache !== '' && str_starts_with($path, "$cache/") ? substr($path, strlen($cache) + 1) : ltrim($path, '/');
        $parts = explode('/', $rel);
        $key = $parts[0] . '/' . ($parts[1] ?? '');
        $groups[$key] ??= ['share' => $parts[0], 'title' => $parts[1] ?? $parts[0], 'files' => 0, 'bytes' => 0, 'since' => null];
        $groups[$key]['files']++;
        $groups[$key]['bytes'] += $size;
        $shares[$parts[0]] ??= ['files' => 0, 'bytes' => 0];
        $shares[$parts[0]]['files']++;
        $shares[$parts[0]]['bytes'] += $size;
        if ($since > 0 && $since > (int) $groups[$key]['since']) {
            $groups[$key]['since'] = $since;
        }
    }
    usort($groups, fn ($a, $b) => [(int) $b['since'], $a['title']] <=> [(int) $a['since'], $b['title']]);
    ksort($shares);
    return ['files' => count($list), 'bytes' => $bytes, 'groups' => array_slice(array_values($groups), 0, JACK_POOL_GROUPS), 'shares' => (object) $shares,
            'listed_at' => @filemtime("$dir/mediacache_exclude.txt") ?: null];
}

/**
 * How full the pool is, against what EmbyCache keeps free there. On ZFS the
 * pool's top folder only sees its own dataset (nearly empty, so "100 % free"):
 * the whole pool counts — its used + available space.
 */
function jackPoolUsage(?array $settings): ?array
{
    $cache = (string) ($settings['cache_path'] ?? '');
    if ($cache === '' || !is_dir($cache)) {
        return null;
    }
    $total = (float) @disk_total_space($cache);
    $free = (float) @disk_free_space($cache);
    foreach (mountTable() as $m) {
        if ($m['mount'] === rtrim($cache, '/') && $m['fs'] === 'zfs' && preg_match('#^[\w.-]+$#', $m['source'])) {
            [$exit, $out] = run(['zfs', 'list', '-Hp', '-o', 'used,avail', $m['source']], 10);
            $f = preg_split('/\s+/', trim($out));
            if ($exit === 0 && count($f) === 2 && is_numeric($f[0]) && is_numeric($f[1])) {
                $total = (float) $f[0] + (float) $f[1];
                $free = (float) $f[1];
            }
        }
    }
    if ($total <= 0) {
        return null;
    }
    $out = ['path' => $cache, 'total' => $total, 'free' => $free, 'free_percent' => round($free / $total * 100, 1), 'min_free' => $settings['min_free_percent'] ?? null];
    // Ms. Moverelli (2026-10-13, the pool simply): she keeps the pool below X, he may fill up to Y = X − offset — what he
    // holds, how many files waited in his last run for room, what he gave back (`keep`: the old «half», always 0 since
    // 2026-10-14 — his page no longer shows it, kept for an older page)
    $look = jackPoolLook($settings);
    if ($look !== null) {
        $out['moverelli'] = ['x' => $look['rules']['x'], 'y' => $look['rules']['y'], 'k' => $look['rules']['k'],
                             'min_age' => jackStreamerAge(DATA_DIR . '/moverelli/smart_mover.ini')];
        if ($look['rules']['x'] > 0) {
            $out['min_free'] = 100 - $look['rules']['y'];
        }
        $l = $look['level'];
        if ($l !== null) {
            $st = (array) (readJson(JACK_DATA . '/status.json')['pool'] ?? []);
            $out += ['keep' => $l['half'], 'held' => $l['jack'], 'back' => $l['back'], 'never' => $l['never'],
                     'waiting' => jackProgressInt($st['waiting'] ?? 0), 'given_back' => jackGiveBackList()];
        }
    }
    return $out;
}

/**
 * What his last run gave back over his limit, in words for his page (jack-phase2): EmbyCache's status `give_back.items`
 * [{title, ahead, who}, …] (the farthest ahead first), at most 20, each in its shape; with how many files went back.
 */
function jackGiveBackList(?array $status = null): array
{
    $g = (array) (($status ?? readJson(JACK_DATA . '/status.json') ?? [])['give_back'] ?? []);
    $items = [];
    foreach (array_slice(is_array($g['items'] ?? null) ? array_values($g['items']) : [], 0, 20) as $w) {
        if (($w = jackGiveBackWhy($w)) !== null) {
            $items[] = $w;
        }
    }
    return ['files' => jackProgressInt($g['files'] ?? 0), 'items' => $items];
}

/**
 * Ms. Moverelli's limit from his side (agent/lib/poollevel.php poolLevelJack()): her rules, his pool, what EmbyCache is
 * told — null while she isn't set up. $wake: a run is about to wake the pool anyway. The tests: $GLOBALS['jackPool'].
 */
function jackPoolLook(?array $settings, bool $wake = false): ?array
{
    $o = ($GLOBALS['jackPool'] ?? []) + ['ini' => DATA_DIR . '/moverelli/smart_mover.ini', 'shares_dir' => '/boot/config/shares', 'zfs' => 'zfs',
        'mnt' => '/mnt', 'jack_list' => JACK_DATA . '/mediacache_exclude.txt', 'asleep' => null];
    $o['names'] ??= array_map(fn ($f) => basename($f, '.cfg'), glob("$o[shares_dir]/*.cfg") ?: []);
    return poolLevelJack($o + ['cache_path' => (string) ($settings['cache_path'] ?? ''), 'wake' => $wake]);
}

/** Her rules from her ini, without his settings: {x, y, k, min_age} — null while she isn't set up (no ini) */
function jackMoverelliRules(): ?array
{
    $ini = ($GLOBALS['jackPool']['ini'] ?? null) ?? DATA_DIR . '/moverelli/smart_mover.ini';
    $rules = poolLevelRules($ini);
    return $rules === null ? null : $rules + ['min_age' => jackStreamerAge($ini)];
}

/** Ms. Moverelli's age rule (smart_mover.ini [GLOBAL] min_age, days) — shown in his setup; null: none set */
function jackStreamerAge(string $ini): ?int
{
    if (!is_file($ini)) {
        return null;
    }
    $in = false;
    foreach (preg_split('/\R/', (string) @file_get_contents($ini, false, null, 0, 1 << 20)) as $line) {
        $line = trim($line);
        if (preg_match('/^\[(.*)\]$/', $line, $m)) {
            $in = trim($m[1]) === 'GLOBAL';
        } elseif ($in && preg_match('/^min_age\s*=\s*(\d{1,4})$/D', $line, $m)) {
            return (int) $m[1];
        }
    }
    return null;
}

/** What EmbyCache is told for a run (dry, real): 100 − Y as his minimum free space, his half as the floor of his give-back */
function jackPoolEnv(?array $settings): array
{
    try {
        return jackPoolLook($settings, true)['env'] ?? [];
    } catch (Throwable $e) {
        logLine("Jack Streamer: Ms. Moverelli's limit not looked at ({$e->getMessage()}) — MediaCache keeps its own minimum free space");
        return [];
    }
}

/**
 * The night run with Ms. Moverelli for his page (agent/lib/nightrun.php): her schedule (the one for both), tonight's
 * plan and the last night — null while she isn't hired.
 */
function jackNight(): ?array
{
    if (!function_exists('moverelliNight') || !moverelliHired()) {
        return null;
    }
    try {
        $h = moverelliHost();
        return ['schedule' => officeJobSchedule('moverelli', $h['cron_file']), 'now' => jackNightNow($h['dir'], true)]
            + moverelliNight($h, moverelliSettings($h['dir']));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * His part «progress»: EmbyCache's live view and the night run going ($night: jackNightNow()) — while his step of the night
 * runs, his view by the night's word (jackNightProgress(): `real` from the moment his step starts, not only once his job's
 * marker shows), else by his own job (jackProgressState())
 */
function jackProgressNow(?array $night): array
{
    $jack = $night !== null && ($night['who'] ?? null) === 'jack' && in_array($night['phase'] ?? null, ['free', 'fill'], true);
    return ($jack ? jackNightProgress($night) : jackProgressState()) + ['night' => $night];
}

/**
 * The night run going for real, for his page's card (2026-10-13 — the same phases as her run card): night.json as the
 * pages read it, whether it was asked to stop, and while she works in it her share in flight from Smart Mover's own
 * status ({share, done, files} — no disk asked, no .partial measured). null: she isn't hired, no real night going.
 */
function jackNightNow(?string $dir = null, ?bool $hired = null): ?array
{
    if (!function_exists('nightState') || !($hired ?? (function_exists('moverelliHired') && moverelliHired()))) {
        return null;
    }
    $dir ??= MOVERELLI_DATA;
    $n = nightState($dir);
    if ($n === null || !$n['running'] || ($n['mode'] ?? '') !== 'run') {
        return null;
    }
    $her = null;
    $st = ($n['who'] ?? '') === 'her' ? readJson("$dir/status.json") : null;
    if (is_array($st) && ($st['result'] ?? '') === 'running' && ($st['phase'] ?? '') === 'share' && is_string($st['share'] ?? null) && $st['share'] !== '') {
        // her fill (the oldest first across the pool) comes after every share's own step: its share is among the done
        // ones, and the share_* counts are the last share's — never shown as this one's (truth review T31)
        $filling = in_array($st['share'], array_column(array_filter((array) ($st['shares'] ?? []), 'is_array'), 'share'), true);
        $her = $filling ? ['share' => $st['share'], 'fill' => true]
            : ['share' => $st['share'], 'done' => max(0, (int) ($st['share_done_files'] ?? 0)), 'files' => max(0, (int) ($st['share_files'] ?? 0))];
    }
    $stop = readJson("$dir/night-stop.json");
    return moverelliNightShort($n) + ['stopping' => is_array($stop) ? (string) ($stop['why'] ?? 'user') : null, 'her' => $her];
}

/**
 * EmbyCache's last run from his own list of runs (2026-10-11, the tiles rebuild): the newest real run (run, release), else
 * the newest of any mode — refused and skipped starts didn't run; null: none yet. Never from status.json: every start
 * removes it (the page said «never» while a run went) and a report or a dry run writes over it. His page picks the same
 * from state.history (lastRuns() in desk.js), so the page and the reception agree. $runs: his list (newest first).
 */
function jackLastRun(?array $runs = null): ?array
{
    $ran = array_values(array_filter($runs ?? jackHistory(), fn ($r) => is_array($r) && ($r['tool'] ?? '') === 'embycache'
        && !in_array($r['result'] ?? '', ['refused', 'skipped'], true)));
    foreach ($ran as $r) {
        if (in_array($r['mode'] ?? '', ['run', 'release'], true)) {
            return $r;
        }
    }
    return $ran[0] ?? null;
}

// ===================================================================== fetching from Emby (setup)

/**
 * Talks to an Emby server: does it answer, which libraries and users does it
 * have. Without a new key the stored one for this address is used, so the
 * page never needs to know it.
 */
function jackConnect(string $url, string $key, string $type = 'emby'): array
{
    $url = rtrim($url, '/');
    if (!preg_match('#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#D', $url)) {
        throw new Problem('jack_bad_url');
    }
    if (!isset(JACK_SERVER_PORTS[$type])) {
        throw new Problem('bad_request');
    }
    if ($key === '') {
        $key = jackStoredKey($url);
    }
    if ($key === '' || !preg_match(JACK_KEY, $key)) {
        throw new Problem('jack_need_key');
    }
    if ($type === 'plex') {
        return jackConnectPlex($url, $key);
    }
    $info = jackApi($url, $key, '/System/Info', $type);
    $libs = [];
    foreach ((array) jackApi($url, $key, '/Library/VirtualFolders', $type) as $lib) {
        $locs = array_values(array_filter((array) ($lib['Locations'] ?? []), fn ($l) => !preg_match(JACK_IGNORED, (string) $l)));
        if ($locs) {
            $libs[] = ['name' => (string) ($lib['Name'] ?? ''), 'type' => (string) ($lib['CollectionType'] ?? ''), 'locations' => $locs];
        }
    }
    $users = [];
    foreach ((array) jackApi($url, $key, '/Users', $type) as $u) {
        $users[] = ['id' => (string) ($u['Id'] ?? ''), 'name' => (string) ($u['Name'] ?? ''), 'admin' => !empty($u['Policy']['IsAdministrator'])];
    }
    return ['ok' => true, 'server' => ['name' => (string) ($info['ServerName'] ?? ''), 'version' => (string) ($info['Version'] ?? '')],
            'libraries' => $libs, 'users' => $users];
}

/**
 * Plex: its name and version (/identity, /), its libraries (/library/sections: movie and show sections with their
 * folders) and its people as MediaCache sees them (PlexApi.users(): the admin by plex.tv's account id — `admin` when plex.tv
 * can't be asked, an unclaimed server — and those the server is shared with). Plex has no favourites and no separate
 * «next up» (both in On Deck).
 */
function jackConnectPlex(string $url, string $key): array
{
    $root = (array) (jackApi($url, $key, '/', 'plex')['MediaContainer'] ?? []);
    $libs = [];
    foreach ((array) (jackApi($url, $key, '/library/sections', 'plex')['MediaContainer']['Directory'] ?? []) as $d) {
        $kind = (string) ($d['type'] ?? '');
        if (!in_array($kind, ['movie', 'show'], true)) {
            continue;
        }
        $locs = array_values(array_filter(array_map(fn ($l) => (string) ($l['path'] ?? ''), (array) ($d['Location'] ?? [])), fn ($l) => $l !== '' && !preg_match(JACK_IGNORED, $l)));
        if ($locs) {
            $libs[] = ['name' => (string) ($d['title'] ?? ''), 'type' => $kind === 'movie' ? 'movies' : 'tvshows', 'locations' => $locs];
        }
    }
    $users = [];
    try {
        $acc = (array) jackApi('https://plex.tv', $key, '/api/v2/user', 'plex');
        $users[] = ['id' => (string) ($acc['id'] ?? 'admin'), 'name' => (string) ($acc['title'] ?? $acc['username'] ?? 'Admin'), 'admin' => true];
        // those the server is shared with (MediaCache's PlexApi.users() asks the same): id and name only, their tokens stay at plex.tv
        $mid = (string) ($root['machineIdentifier'] ?? '');
        if (preg_match('/^[a-f0-9]{8,64}$/D', $mid)) {
            $users = array_merge($users, jackPlexShared((string) jackApi('https://plex.tv', $key, "/api/servers/$mid/shared_servers", 'plex', true)));
        }
    } catch (Problem $p) {
        $users = $users ?: [['id' => 'admin', 'name' => 'Admin', 'admin' => true]];   // unclaimed or plex.tv not reachable: the admin only
    }
    return ['ok' => true, 'server' => ['name' => (string) ($root['friendlyName'] ?? ''), 'version' => (string) ($root['version'] ?? ''), 'type' => 'plex'],
            'libraries' => $libs, 'users' => $users];
}

/** plex.tv's shared_servers (XML): [{id, name}] of the accounts the server is shared with — never their tokens or e-mail */
function jackPlexShared(string $xml): array
{
    $out = [];
    if (preg_match_all('/<SharedServer\b[^>]*>/', $xml, $m)) {
        foreach ($m[0] as $tag) {
            $id = preg_match('/\buserID="(\d{1,20})"/', $tag, $x) ? $x[1] : '';
            $name = preg_match('/\busername="([^"]{1,120})"/', $tag, $x) ? html_entity_decode($x[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : '';
            if ($id !== '') {
                $out[] = ['id' => $id, 'name' => jackWatchText($name !== '' ? $name : $id, 60), 'admin' => false];
            }
        }
    }
    return $out;
}

/** A server's kind as stored (MediaCache's default: emby) */
function jackType(mixed $t): string
{
    return is_string($t) && isset(JACK_SERVER_PORTS[strtolower($t)]) ? strtolower($t) : 'emby';
}

function jackStoredKey(string $url): string
{
    foreach (jackReadSettings()['instances'] ?? [] as $i) {
        if (rtrim((string) ($i['url'] ?? ''), '/') === rtrim($url, '/')) {
            return (string) ($i['api_key'] ?? '');
        }
    }
    return '';
}

/** The header that carries a server's key: Emby X-Emby-Token, Jellyfin (10.9+) Authorization, Plex X-Plex-Token */
function jackKeyHeader(string $type, string $key): string
{
    return match ($type) {
        'jellyfin' => "Authorization: MediaBrowser Token=\"$key\"",
        'plex'     => "X-Plex-Token: $key",
        default    => "X-Emby-Token: $key",
    };
}

/** One GET to a media server's API, through the host's network; the key goes in a header file, not on a command line (curl never follows a redirect) */
function jackApi(string $url, string $key, string $path, string $type = 'emby', bool $raw = false): mixed
{
    @mkdir(RUN_DIR, 0700, true);
    $headers = RUN_DIR . '/jack-headers.' . getmypid();
    file_put_contents($headers, jackKeyHeader($type, $key) . "\nAccept: application/json\n");
    chmod($headers, 0600);
    try {
        [$exit, $out, $err] = hostNet(['curl', '-s', '-S', '-f', '-m', '15', '-H', "@$headers", $url . $path], 20);
    } finally {
        @unlink($headers);
    }
    if ($exit !== 0) {
        throw new Problem(str_contains($err, '401') || str_contains($err, '403') ? 'jack_bad_key' : 'jack_unreachable',
            ['url' => $url, 'detail' => trim($err)]);
    }
    if ($raw) {
        return $out;                               // plex.tv's shared_servers: XML only
    }
    $j = json_decode($out, true);
    if (!is_array($j)) {
        throw new Problem('jack_unreachable', ['url' => $url, 'detail' => 'no JSON']);
    }
    return $j;
}

// ===================================================================== saving the setup

/**
 * Takes the choices of the setup form, lays them over the current settings
 * and lets EmbyCache's own save_config() write them (it checks and fills in
 * its defaults). Its load_config() must accept the result.
 */
function jackSave(mixed $in): array
{
    if (!is_array($in)) {
        throw new Problem('missing_field', ['field' => 'settings']);
    }
    if (jackAnyRunning()) {
        throw new Problem('jack_running');
    }
    $cur = jackReadSettings() ?? [];
    if (!is_array($in['instances'] ?? null)) {
        throw new Problem('bad_request');                // a list missing never means «no server»
    }
    $instances = [];
    foreach ($in['instances'] as $i) {
        if (!is_array($i)) {
            throw new Problem('bad_request');
        }
        $url = rtrim(optText($i, 'url'), '/');
        $key = trim(optText($i, 'api_key')) ?: jackStoredKey($url);
        if (!preg_match('#^https?://\S+$#D', $url) || !preg_match(JACK_KEY, $key)) {
            throw new Problem('jack_need_key');
        }
        $type = optText($i, 'type') ?: 'emby';
        if (!isset(JACK_SERVER_PORTS[$type])) {
            throw new Problem('bad_request');
        }
        $maps = [];
        foreach ((array) ($i['path_mappings'] ?? []) as $from => $to) {
            if (is_string($from) && is_string($to) && jackMappingOk($from, $to)) {
                $maps[rtrim($from, '/')] = rtrim($to, '/');        // '' = this folder deliberately not cached
            }
        }
        $name = mb_substr(trim(optText($i, 'servername')), 0, 60) ?: JACK_SERVER_NAMES[$type] . (count($instances) + 1);
        // kept from the stored server at this address: what the setup doesn't show (Plex's days_to_monitor)
        $old = array_values(array_filter((array) ($cur['instances'] ?? []), fn ($c) => is_array($c) && rtrim((string) ($c['url'] ?? ''), '/') === $url))[0] ?? [];
        $instances[] = array_diff_key($old, ['api_key' => 1, 'path_mappings' => 1, 'servername' => 1, 'url' => 1, 'type' => 1])
            + ['servername' => $name, 'type' => $type, 'url' => $url, 'api_key' => $key, 'path_mappings' => $maps];
    }
    // none (2026-10-21): every server removed on his page — only over saved settings (there is something to forget); he
    // forgets their libraries and people too, keeps budget, pool and the rest, and doesn't run until one is connected
    // (jackRunCheck(): jack_no_server). What lies on the pool stays there, protected by his list.
    if (!$instances && !$cur) {
        throw new Problem('jack_need_key');
    }
    $cfg = $cur;
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];
    // the page sends the whole form: a list missing would empty it unasked (QA 2026-10-08, finding 5)
    foreach (['libraries', 'library_types', 'valid_users', 'user_budgets'] as $k) {
        if (!is_array($in[$k] ?? null)) {
            throw new Problem('bad_request');
        }
    }
    $cfg['libraries'] = $instances ? array_values(array_filter($in['libraries'], 'is_string')) : [];
    // what kind each chosen library is (films, series …) — only for the office's overview, EmbyCache ignores it
    $cfg['library_types'] = [];
    foreach ((array) ($in['library_types'] ?? []) as $name => $type) {
        if (is_string($name) && in_array($name, $cfg['libraries'], true) && is_string($type) && preg_match('/^[a-z]{0,20}$/D', $type)) {
            $cfg['library_types'][$name] = $type;
        }
    }
    // people: a list of ids, or {id: {budget: "300G"}} when some have a budget of their own
    $users = [];
    $budgets = (array) ($in['user_budgets'] ?? []);
    foreach ($instances ? (array) ($in['valid_users'] ?? []) : [] as $id) {
        if (is_string($id) && preg_match('/^[\w-]{1,64}$/D', $id)) {
            $b = trim(optText($budgets, $id));
            if ($b !== '' && !preg_match('/^\d+(\.\d+)?\s*[KMGTP]?B?$/iD', $b)) {
                throw new Problem('jack_bad_size', ['value' => $b]);
            }
            $users[$id] = $b !== '' ? ['budget' => strtoupper(str_replace(' ', '', $b))] : (object) [];
        }
    }
    $cfg['valid_users'] = array_filter($users, fn ($u) => is_array($u)) ? $users : array_keys($users);
    // one person over several servers (1.65.0): the page sends their ids together — the budget counts each person once
    $people = $in['people'] ?? [];
    if (!is_array($people)) {
        throw new Problem('bad_request');
    }
    $persons = [];
    $grouped = [];
    foreach ($instances ? $people : [] as $ids) {
        if (!is_array($ids)) {
            throw new Problem('bad_request');
        }
        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && isset($users[$id]) && !isset($grouped[$id])));
        if (!$ids) {
            continue;
        }
        $own = array_values(array_filter($ids, fn ($id) => is_array($users[$id])));
        $persons[] = $own ? $users[$own[0]] : (object) [];
        foreach ($ids as $id) {
            $grouped[$id] = true;
        }
    }
    foreach ($users as $id => $u) {                    // an id the page didn't group: a person of its own (as before)
        if (!isset($grouped[$id])) {
            $persons[] = $u;
        }
    }
    // copies at once per disk: 1 … JACK_PARALLEL_MAX, never another value turned into one
    if (array_key_exists('parallel_per_disk', $in) && jackParallelPerDisk(['parallel_per_disk' => $in['parallel_per_disk']]) !== $in['parallel_per_disk']) {
        throw new Problem('bad_request');
    }
    foreach (JACK_SETTINGS as $k) {
        if (array_key_exists($k, $in)) {
            $cfg[$k] = $in[$k];
        }
    }
    $cfg = jackTools($cfg);                       // a fill_tool/cleanup_tool the request carries is ignored: MediaCache's own
    unset($cfg['give_back_unwatched_days']);           // the wizard's, read by nothing (create_share_root: as it was)
    $cfg['cache_budget'] = strtoupper(str_replace(' ', '', is_string($cfg['cache_budget'] ?? null) ? $cfg['cache_budget'] : ''));
    jackBudgetCheck($cfg['cache_budget'], $persons, jackPoolSizes()[rtrim((string) ($cfg['cache_path'] ?? ''), '/')] ?? null);
    if (!in_array($cfg['cache_path'] ?? '', jackPools(), true)) {
        throw new Problem('jack_bad_pool', ['path' => (string) ($cfg['cache_path'] ?? '')]);
    }
    // Unraid's views: fixed here, the page only shows them
    if (($cfg['array_path'] ?? '/mnt/user0') !== '/mnt/user0' || ($cfg['user_path'] ?? '/mnt/user') !== '/mnt/user'
        || ($cfg['array_disks_glob'] ?? '/mnt/disk[0-9]*') !== '/mnt/disk[0-9]*') {
        throw new Problem('jack_config', ['detail' => 'array_path / user_path / array_disks_glob']);
    }
    jackWriteSettings($cfg);
    jackListEnsure(jackReadSettings());               // the night run with Ms. Moverelli can start at once
    if ($gather = jackGatherSettings()) {
        jackWriteGatherIni($gather, $cfg);             // follows the pool
    }
    logLine('Jack Streamer: MediaCache settings saved');
    return ['ok' => true, 'state' => jackScan()];
}

/**
 * Budget mode always (2026-10-14, the owner): a budget in total is required and is the hard cap; every person with a
 * budget of their own at least JACK_MIN_PERSON_BYTES, together never more than the total; what they leave gives the
 * chosen people without one at least as much each. $users: id => ['budget' => …] | (object) [] (as jackSave() built them).
 */
function jackBudgetCheck(string $budget, array $users, ?int $poolBytes = null): void
{
    $min = JACK_MIN_PERSON_BYTES;
    $total = jackSizeBytes($budget);
    if ($total < $min) {
        throw new Problem('jack_budget_missing', ['min' => jackGb($min)]);
    }
    // never more than the pool holds (the lab, 2026-10-16: 60 GB on a 32 GB pool was taken) — null: size not known
    if ($poolBytes !== null && $poolBytes > 0 && $total > $poolBytes) {
        throw new Problem('jack_budget_pool', ['total' => jackGb($total), 'pool' => jackGb($poolBytes)]);
    }
    $fixed = 0;
    $others = 0;
    foreach ($users as $u) {
        if (!is_array($u)) {
            $others++;
            continue;
        }
        $b = jackSizeBytes((string) $u['budget']);
        if ($b < $min) {
            throw new Problem('jack_budget_low', ['min' => jackGb($min), 'value' => (string) $u['budget']]);
        }
        $fixed += $b;
    }
    if ($fixed > $total) {
        throw new Problem('jack_budget_over', ['fixed' => jackGb($fixed), 'total' => jackGb($total)]);
    }
    if ($others > 0 && $fixed > 0 && ($total - $fixed) / $others < $min) {
        throw new Problem('jack_budget_short', ['rest' => jackGb($total - $fixed), 'min' => jackGb($min)]);
    }
}

/** «500G», «1.5T», «800GB» in bytes (EmbyCache's parse_size: K/M/G/T/P by 1024, none = bytes) — 0 when it isn't a size */
function jackSizeBytes(string $text): int
{
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTP])?B?$/iD', trim($text), $m)) {
        return 0;
    }
    $f = ['' => 1, 'K' => 1 << 10, 'M' => 1 << 20, 'G' => 1 << 30, 'T' => 1 << 40, 'P' => 1 << 50][strtoupper($m[2] ?? '')];
    return (int) round((float) $m[1] * $f);
}

/** Bytes as «20 GB» for an error's words (whole GB, one decimal below 10) */
function jackGb(int $bytes): string
{
    $gb = $bytes / (1 << 30);
    return ($gb < 10 && fmod($gb, 1.0) !== 0.0 ? number_format($gb, 1) : (string) (int) round($gb)) . ' GB';
}

/**
 * Set up = a server and a budget (2026-10-14, the owner: budget mode only, no migration — settings without a budget, such
 * as an older count-mode setup, count as «not set up yet»: his page shows the setup's start, no run, no schedule)
 */
function jackConfigured(?array $settings): bool
{
    return $settings !== null && !empty($settings['instances']) && is_string($settings['cache_budget'] ?? null)
        && jackSizeBytes($settings['cache_budget']) >= JACK_MIN_PERSON_BYTES;
}

/** Not set up, as a refusal: every server removed (settings there, none in them) says so — else «not set up yet» */
function jackNotConfigured(?array $settings = null): Problem
{
    $settings ??= jackReadSettings();
    return new Problem($settings !== null && empty($settings['instances']) ? 'jack_no_server' : 'jack_not_configured');
}

// ===================================================================== set up from scratch (2026-10-14)
/*
 * «Set up from scratch» — really zero (the owner): no copy, no way back. Gone: EmbyCache's settings (server, API key,
 * libraries, people, amounts, N days), the import's and the updates' copies (*.before-*), his origin (mediacache_origin.json), his list's note (office-list.json),
 * his runs' state and history (office-run/-history/-output/-stop, the progress, status.json); the gather's settings
 * and state (gather.json, its ini files, last-real, sizes, status, its office-* files); his own schedules (EmbyCache's,
 * the gather's — Ms. Moverelli's night run is hers and stays; it leaves him out while he has no settings). His list is
 * emptied but kept as a file: Ms. Moverelli's ini names it (global_excludes) and Smart Mover stops a real run when a
 * named list is MISSING — an empty one simply protects nothing. So what he placed on the pool stays where it is and
 * becomes ordinary cache: she moves it by her rules (the age rule, from X). Kept: the logs (Ms. Protocolli shows
 * embycache.log and consolidate.log) and the lock files. Never while a run of his (EmbyCache or the gather) goes.
 */
const JACK_RESET_GONE   = ['mediacache_settings.json', '.mediacache_settings.trial.json', 'mediacache_origin.json', 'office-history.json', JACK_LIST_NOTE,
                           'office-run.json', 'office-output.txt', 'office-stop.json', 'jack-progress.json', 'jack-progress-speed.json', 'status.json'];
const GATHER_RESET_GONE = ['gather.json', 'consolidate.ini', 'measure.ini', 'last-real.json', 'sizes.json', 'status.json', 'office-history.json',
                           'office-run.json', 'office-output.txt', 'office-stop.json'];

/**
 * «Set up from scratch». $o (the tests): dir, gather (the folders), running (a run of his goes?), schedule (job, null)
 * — default his and the gather's cron lines off. Answer {ok, state, gone: [file …]}.
 */
function jackReset(array $o = []): array
{
    $dir = $o['dir'] ?? JACK_DATA;
    $gdir = $o['gather'] ?? GATHER_DATA;
    if (($o['running'] ?? 'jackAnyRunning')()) {
        throw new Problem('jack_running');
    }
    $gone = [];
    $drop = function (string $f) use (&$gone): void {
        if ((is_file($f) || is_link($f)) && @unlink($f)) {
            $gone[] = $f;
        }
    };
    foreach (JACK_RESET_GONE as $f) {
        $drop("$dir/$f");
    }
    foreach (glob("$dir/*.before-*") ?: [] as $f) {           // the import's copies and the updates' (before-<version>)
        $drop($f);
    }
    foreach (GATHER_RESET_GONE as $f) {
        $drop("$gdir/$f");
    }
    // his list emptied, the file kept (her ini names it: a missing list stops her real runs, an empty one protects nothing)
    if (is_dir($dir) && !is_link("$dir/mediacache_exclude.txt")) {
        writeAtomic("$dir/mediacache_exclude.txt", '', 0600, 0, 0);
    }
    $schedule = $o['schedule'] ?? fn (string $job) => officeJobSetSchedule($job, null);
    foreach (['embycache', 'gather'] as $job) {
        try {
            $schedule($job);
        } catch (Throwable $e) {
            logLine("Jack Streamer: set up from scratch — the $job schedule not switched off ({$e->getMessage()})");
        }
    }
    logLine('Jack Streamer: set up from scratch — settings, gather settings, his list (emptied), origin, runs and schedules gone ('
        . count($gone) . ' files); what lies on the pool stays as ordinary cache');
    return ['ok' => true, 'state' => $dir === JACK_DATA ? jackScan() : null, 'gone' => array_map('basename', $gone)];
}

/**
 * EmbyCache's settings file in $dir, written by its own save_config(). save_config() writes
 * before load_config() checks: it writes a trial file (EMBYCACHE_CONFIG), and only once
 * EmbyCache accepts it is that put in place. The request goes through a new 0600 file in $tmp
 * (it holds the API key).
 */
function jackWriteSettings(array $cfg, string $dir = JACK_DATA, string $tmp = RUN_DIR): void
{
    if (!is_file(JACK_APP . '/mediacache_lib.py')) {
        throw new Problem('jack_missing_tool', ['path' => JACK_APP]);
    }
    jackDataDir($dir);
    @mkdir($tmp, 0700, true);
    $cfg = jackTools($cfg);                       // whatever writes Jack's settings (his setup, the import): rsync both ways
    $file = writeNewFile("$tmp/.jack-settings", json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0600);
    if ($file === null) {
        throw new Problem('jack_config', ['detail' => "cannot write in $tmp"]);
    }
    $trial = "$dir/.mediacache_settings.trial.json";
    @unlink($trial);
    // no server (every one removed): MediaCache's load_config() refuses that by design (so no run of it can go without one) —
    // everything else it checks with a stand-in server, written to RAM beside the request and gone afterwards
    $py = 'import json, sys, pathlib; sys.path.insert(0, sys.argv[1]); import mediacache_lib as l; '
        . 'c = json.load(open(sys.argv[2], encoding="utf-8")); l.save_config(c); '
        . 'l.CONFIG_FILE = l.CONFIG_FILE if c.get("instances") else pathlib.Path(sys.argv[3]); '
        . 'c.get("instances") or l.save_config(dict(c, instances=[{"servername": "check", "type": "emby", "url": "http://check", '
        . '"api_key": "check", "path_mappings": {"/check": "/mnt/user/check"}}])); l.load_config()';
    $stand = "$tmp/.jack-settings-check.json";
    @unlink($stand);
    try {
        [$exit, , $err] = runEnv(['python3', '-c', $py, JACK_APP, $file, $stand], ['MEDIACACHE_DIR' => $dir] + jackPyEnv() + ['MEDIACACHE_CONFIG' => $trial], 30);
    } finally {
        @unlink($file);
        @unlink($stand);
    }
    if ($exit !== 0 || !is_file($trial)) {
        @unlink($trial);
        $lines = array_filter(explode("\n", trim($err)));
        throw new Problem('jack_config', ['detail' => (string) end($lines)]);
    }
    chmod($trial, 0600);
    rename($trial, "$dir/mediacache_settings.json");
}

/**
 * A path mapping: Emby's folder (absolute) => the share folder it is on this
 * server (/mnt/user/<share>/…, or '' = deliberately not cached). EmbyCache
 * moves files along these paths: no "..", ".", empty parts or control
 * characters, so a mapping can never point out of the shares.
 */
function jackMappingOk(string $from, string $to): bool
{
    $clean = function (string $path): bool {
        if (!str_starts_with($path, '/') || strlen($path) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        foreach (explode('/', trim($path, '/')) as $p) {
            if ($p === '' || $p === '.' || $p === '..') {
                return false;
            }
        }
        return true;
    };
    return $clean($from) && ($to === '' || ($clean($to) && preg_match('#^/mnt/user/[^/]+#', $to)));
}

/** settings, exclude list and logs hold the API key and file names: root only */
function jackDataDir(string $dir): void
{
    @mkdir($dir, 0700, true);
    @chmod($dir, 0700);
    @chown($dir, 0);
}

/** run() with a few extra environment variables */
function runEnv(array $command, array $env, int $timeout = 60): array
{
    $vars = [];
    foreach ($env as $k => $v) {
        $vars[] = "$k=$v";
    }
    return run(array_merge(['env'], $vars, $command), $timeout);
}

/**
 * What EmbyCache's Python always gets: its data folder, no bytecode next to the code, UTF-8 output — and rsync both ways (over
 * any settings file); in the office its minimum free space is a limit he gives back to, not only one he stops filling at
 * (EMBYCACHE_GIVE_BACK_FREE, jack-phase2 — standalone EmbyCache keeps the old behaviour); copies at once: per disk as his
 * setup chose, in all the array's disks × that (2026-10-29 — his panel shows every file in flight, «stop» ends after
 * them). $settings / $disks: the tests (read when null).
 */
function jackPyEnv(?array $settings = null, ?int $disks = null): array
{
    $per = jackParallelPerDisk($settings ?? jackReadSettings());
    return ['MEDIACACHE_DIR' => JACK_DATA, 'PYTHONDONTWRITEBYTECODE' => '1', 'PYTHONIOENCODING' => 'utf-8', 'PYTHONUNBUFFERED' => '1',
            'MEDIACACHE_GIVE_BACK_FREE' => '1',
            'MEDIACACHE_RSYNC_ARGS' => JACK_RSYNC_ARGS,
            // MediaCache's own live status in RAM
            'MEDIACACHE_PARALLEL_PER_DISK' => (string) $per, 'MEDIACACHE_PARALLEL_TOTAL' => (string) jackParallelTotal($per, $disks ?? jackArrayDisks()),
            'MEDIACACHE_LIVE_STATUS' => RUN_DIR . '/jack-live-status.json'];
}

// ===================================================================== the gather's settings

function jackGatherSettings(): ?array
{
    return readJson(GATHER_DATA . '/gather.json');
}

/** The gather's own settings (works without EmbyCache being set up): shares, minimum free space, duplicates, the cache (#14) */
function jackGatherSave(mixed $in): array
{
    if (jackAnyRunning()) {
        throw new Problem('jack_running');
    }
    jackSaveGather(jackGatherCheck($in), jackReadSettings() ?? []);
    logLine('Jack Streamer: gather settings saved');
    return ['ok' => true, 'state' => jackScan()];
}

/** The gather's settings, checked ($all: the server's shares) */
function jackGatherCheck(mixed $in, ?array $all = null): array
{
    $in = is_array($in) ? $in : [];
    $all ??= jackAllShares();
    $shares = array_values(array_unique(array_filter((array) ($in['shares'] ?? []), fn ($s) => is_string($s) && in_array($s, $all, true))));
    if (!$shares) {
        throw new Problem('jack_gather_no_share');
    }
    // both said: a missing one would put the default back unasked (QA 2026-10-08, finding 5)
    $min = $in['min_free_gb'] ?? null;
    if (!(is_int($min) || (is_string($min) && preg_match('/^\d{1,6}$/D', $min))) || (int) $min < 0 || (int) $min > 100000) {
        throw new Problem('jack_bad_number', ['field' => 'min_free_gb']);
    }
    if (!in_array($in['dup_check'] ?? null, ['size', 'cmp'], true)) {
        throw new Problem('bad_request');
    }
    // the cache switch (#14) and what happens to folders only on the cache: said too, like the others
    if (!is_bool($in['move_cache'] ?? null) || !in_array($in['cache_only_target'] ?? null, GATHER_CACHE_ONLY, true)) {
        throw new Problem('bad_request');
    }
    return ['shares' => $shares, 'min_free_gb' => (int) $min, 'dup_check' => $in['dup_check'],
            'move_cache' => $in['move_cache'], 'cache_only_target' => $in['cache_only_target']];
}

function jackSaveGather(array $gather, array $emby, string $gatherDir = GATHER_DATA, string $jackDir = JACK_DATA): void
{
    jackDataDir($gatherDir);
    writeAtomic("$gatherDir/gather.json", jsonEncode($gather), 0600, 0, 0);
    jackWriteGatherIni($gather, $emby, $gatherDir, $jackDir);
}

/** The pools the gather leaves alone: those of its shares, and EmbyCache's */
function jackGatherPools(array $shares, array $emby, ?callable $shareCfg = null): array
{
    $shareCfg ??= fn (string $s): array => jackShareCfg($s);
    $pools = [];
    foreach ($shares as $share) {
        foreach (['shareCachePool', 'shareCachePool2'] as $k) {
            $p = (string) ($shareCfg($share)[$k] ?? '');
            if ($p !== '') {
                $pools[] = "/mnt/$p";
            }
        }
    }
    if (!empty($emby['cache_path'])) {
        $pools[] = rtrim((string) $emby['cache_path'], '/');
    }
    return array_values(array_unique($pools));
}

/** consolidate.ini from Jack's settings (written again before every run, so it follows the pool) */
function jackWriteGatherIni(array $gather, array $emby, string $gatherDir = GATHER_DATA, string $jackDir = JACK_DATA): string
{
    $ini = jackGatherIni($gather, jackGatherPools($gather['shares'], $emby), "$gatherDir/consolidate.log", "$jackDir/mediacache_exclude.txt");
    writeAtomic("$gatherDir/consolidate.ini", $ini, 0600, 0, 0);
    return $ini;
}

/**
 * The ini the gather's script reads — and `source`s, as root: a 0600 copy in RAM (RUN_DIR, root's 0700 folder), never
 * the one in the data folder on the pool, which a writer as nobody could replace between its writing and its reading
 * (review 2026-10-15). Null: RAM can't take it (the run is refused).
 */
function jackGatherIniRam(string $text, string $mode): ?string
{
    if (!is_dir(RUN_DIR) && !@mkdir(RUN_DIR, 0700, true)) {
        return null;
    }
    $st = @lstat(RUN_DIR);
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || $st['uid'] !== posix_geteuid() || ($st['mode'] & 0077)) {
        return null;
    }
    return writeNewFile(RUN_DIR . "/gather-$mode.ini", $text, 0600);
}

/**
 * The text of consolidate.ini — a file bash sources: every value single
 * quoted, the shares and pools checked against what exists. The cache only
 * when switched on (`move_cache`, #14; a gather.json from before it: off):
 * then the folders' files on the cache come to their disk, folders only on
 * the cache stay or go to the disk with most free space (`cache_only_target`).
 * What EmbyCache keeps on the pool always stays: its list is the gather's
 * exclude file.
 */
function jackGatherIni(array $gather, array $pools, string $log, string $exclude): string
{
    $q = fn (string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
    $dirs = [];
    foreach ($gather['shares'] as $share) {
        if (!preg_match('/^[\w.\- ]+$/uD', $share)) {
            throw new Problem('jack_bad_share', ['share' => $share]);
        }
        $dirs[] = $q("/mnt/user/$share");
    }
    $pools = array_values(array_filter($pools, fn ($p) => preg_match('#^/mnt/[a-z0-9_-]+$#', $p) && !preg_match('#^/mnt/(disk\d+|user0?|disks|remotes|addons)$#', $p)));
    return "# consolidate.ini - written by the Unraid Secretary Office (Jack Streamer), change it there\n"
        . 'BASE_DIRS=(' . implode(' ', $dirs) . ")\n"
        . 'LOGFILE=' . $q($log) . "\n"
        . "ARRAY_PATTERN='/mnt/disk[0-9]*'\n"
        . 'CACHE_PATTERN=' . $q(implode(' ', $pools)) . "\n"
        . 'EXCLUDE_FILE=' . $q($exclude) . "\n"
        . "DRYRUN=true\n"
        . 'MIN_FREE_GB=' . (int) $gather['min_free_gb'] . "\n"
        . 'MOVE_CACHE=' . (($gather['move_cache'] ?? false) === true ? 'true' : 'false') . "\n"
        . 'CACHE_ONLY_TARGET=' . $q(($gather['cache_only_target'] ?? '') === 'most-free' ? 'most-free' : 'skip') . "\n"
        . 'DUP_CHECK=' . $q($gather['dup_check'] === 'cmp' ? 'cmp' : 'size') . "\n";
}

/** Has the gather brought the folders together at least once (a real run that went through)? */
function jackGatherReady(): bool
{
    $last = readJson(GATHER_DATA . '/last-real.json');
    return $last !== null && in_array($last['result'] ?? '', ['ok', 'errors'], true);
}

// ===================================================================== taking over an earlier install

/*
 * Someone who ran helmi1987's tools before (from a User Scripts folder, say) types the folder
 * where the old files lie; Jack never searches. The agent reads only the known files there —
 * plain files, size-capped — on the server: the Emby API key never goes to the page (the preview
 * says found / not found, and every answer is scrubbed of it). Preview first (what changes, what
 * is left out, what gets Jack's defaults, how many cached files come over), then the
 * import with the preview's token. Before anything of Jack's is overwritten, a copy of it stays
 * next to it (<file>.before-import-<time>), so it can be undone by hand.
 */

const JACK_IMPORT_FILES = [   // the only files read in the old folder: name, size cap
    'settings' => ['mediacache_settings.json', 1048576],
    'exclude'  => ['mediacache_exclude.txt', 16777216],
    'ini'      => ['consolidate.ini', 65536],
];
// set by Jack whatever the old file says (Unraid's views, the share configs, rsync both ways); the mover only as EmbyCache finds it itself
const JACK_IMPORT_FIXED = ['array_path' => '/mnt/user0', 'user_path' => '/mnt/user', 'array_disks_glob' => '/mnt/disk[0-9]*',
                           'shares_cfg_dir' => '/boot/config/shares'] + JACK_TOOLS;
const JACK_MOVER_BINS   = ['', '/usr/libexec/unraid/move', '/usr/local/sbin/move', '/usr/local/bin/move'];
// rsync options an imported rsync_args may hold: plain switches only (no -e, no files, no remote shell)
const JACK_RSYNC_SHORT  = '/^-[aAXHSDglopPrtuvhxWcm]+$/D';
const JACK_RSYNC_LONG   = ['--numeric-ids', '--sparse', '--hard-links', '--acls', '--xattrs', '--preallocate', '--whole-file', '--no-whole-file',
                           '--checksum', '--inplace', '--partial', '--progress', '--human-readable', '--times', '--perms', '--owner', '--group',
                           '--archive', '--verbose', '--quiet'];
const JACK_IMPORT_PCT   = ['min_free_percent', 'movie_share_percent'];
// a key the old install doesn't have and Jack has no value for: what Jack's setup page would choose
// (desk.js initForm()/saveSetup()), where it differs from EmbyCache's DEFAULTS; max_resume_movies/_series: max_resume_items
const JACK_DEFAULTS = JACK_TOOLS + ['array_source' => 'user0',
                            'movie_mode' => 'folder', 'create_share_root' => true, 'mover_debug_level' => 0, 'number_episodes' => 3,
                            'movie_share_percent' => 50, 'max_episodes_per_series' => 0, 'max_favorite_series' => 10, 'use_next_up' => true,
                            'min_free_percent' => 20];
// MediaCache's keys Jack sets himself without a word: the share root, one copy per disk (his setup changes it), its rsync
// progress and log lines
const JACK_IMPORT_QUIET = JACK_FIXED + ['parallel_per_disk' => 1, 'parallel_total' => 1, 'copy_progress' => true, 'status_log_interval' => 60];
const GATHER_IMPORT_KEYS = ['BASE_DIRS', 'MIN_FREE_GB', 'DUP_CHECK', 'MOVE_CACHE', 'CACHE_ONLY_TARGET'];  // what Jack's gather settings take over
const GATHER_IMPORT_JACK = ['LOGFILE', 'ARRAY_PATTERN', 'CACHE_PATTERN', 'EXCLUDE_FILE', 'DRYRUN'];      // Jack's own

/** What the import looks at: Jack's folders and the server's pools, shares and sleeping disks (the tests pass their own) */
function jackImportContext(): array
{
    return ['jack_dir' => JACK_DATA, 'gather_dir' => GATHER_DATA, 'tmp' => RUN_DIR, 'fs' => '',
            'pools' => jackPools(), 'shares' => jackAllShares(), 'asleep' => sleepingDisks(),
            'share_cfg' => fn (string $s): array => jackShareCfg($s), 'defaults' => null];
}

/** The two folders of a request ('' = not used) */
function jackImportFolders(array $r): array
{
    $out = [];
    foreach (['embycache', 'gather'] as $k) {
        $v = $r[$k] ?? '';
        if (!is_string($v) || strlen($v) > 1024) {
            throw new Problem('missing_field', ['field' => $k]);
        }
        $out[$k] = trim($v);
    }
    if ($out['embycache'] === '' && $out['gather'] === '') {
        throw new Problem('jack_import_nothing');
    }
    return $out;
}

function jackImportPreview(array $r): array
{
    $f = jackImportFolders($r);
    $plan = jackImportPlan($f['embycache'], $f['gather'], jackImportContext());
    return ['ok' => true, 'preview' => $plan['preview'] + ['running' => jackAnyRunning()]];
}

function jackImportDo(array $r): array
{
    $f = jackImportFolders($r);
    if (jackAnyRunning()) {
        throw new Problem('jack_running');
    }
    $ctx = jackImportContext();
    $plan = jackImportPlan($f['embycache'], $f['gather'], $ctx);
    if (!hash_equals($plan['preview']['token'], textField($r, 'token'))) {
        throw new Problem('jack_import_changed');
    }
    if (!$plan['preview']['ready']) {
        throw new Problem('jack_import_blocked');
    }
    $done = jackImportApply($plan['do'], $ctx);
    logLine('Jack Streamer: took over an earlier install (' . implode(', ', array_keys(array_filter($plan['do']))) . ') from '
        . implode(', ', array_filter($f)) . ($done['backups'] ? '; copies of his own: .before-import-' . $done['stamp'] : ''));
    return ['ok' => true, 'done' => jackImportScrub($done, $plan['secrets']), 'state' => jackScan()];
}

/** The folder typed for an earlier install, checked before anything in it is read (agent/lib/import.php, shared with Ms. Moverelli) — never one of his own */
function jackImportFolder(string $path, array $ctx): string
{
    return importFolder($path, $ctx + ['own' => [$ctx['jack_dir'], $ctx['gather_dir']]], 'jack_import');
}

/** One of the known files in the old folder (importRead(): plain, one hard link, at most $cap bytes); null when it isn't there */
function jackImportRead(string $dir, string $name, int $cap): ?string
{
    return importRead($dir, $name, $cap, 'jack_import');
}

/** EmbyCache's own DEFAULTS (the version that ships with the office), asked from mediacache_lib.py */
function jackImportDefaults(): array
{
    $py = 'import json, sys; sys.path.insert(0, sys.argv[1]); import mediacache_lib as l; print(json.dumps(l.DEFAULTS))';
    [$exit, $out, $err] = runEnv(['python3', '-c', $py, JACK_APP], jackPyEnv(), 30);
    $j = $exit === 0 ? json_decode(trim($out), true) : null;
    if (!is_array($j) || !array_key_exists('cache_path', $j)) {
        $lines = array_filter(explode("\n", trim((string) $err)));
        throw new Problem('jack_config', ['detail' => (string) (end($lines) ?: 'DEFAULTS')]);
    }
    return $j;
}

/**
 * What would come over from the old folders, against Jack's files now. Returns the preview (for
 * the page: no API key, the token over everything that will be done), what will be done (`do`,
 * agent only) and the secrets the answers are scrubbed of.
 */
function jackImportPlan(string $jackFolder, string $gatherFolder, array $ctx): array
{
    $ctx['defaults'] ??= jackImportDefaults();
    $secrets = [];
    $preview = ['embycache' => null, 'gather' => null];
    $do = ['embycache' => null, 'gather' => null];
    $current = readJson($ctx['jack_dir'] . '/mediacache_settings.json');
    foreach ((array) ($current['instances'] ?? []) as $i) {
        if (is_array($i) && is_string($i['api_key'] ?? null) && $i['api_key'] !== '') {
            $secrets[] = $i['api_key'];
        }
    }
    if ($jackFolder !== '') {
        [$preview['embycache'], $do['embycache']] = jackImportEmbyCache(jackImportFolder($jackFolder, $ctx), $ctx, $current, $secrets);
        $preview['embycache']['folder'] = rtrim($jackFolder, '/');
    }
    if ($gatherFolder !== '') {
        $emby = $do['embycache']['settings'] ?? $current ?? [];
        [$preview['gather'], $do['gather']] = jackImportGather(jackImportFolder($gatherFolder, $ctx), $ctx, $emby);
        $preview['gather']['folder'] = rtrim($gatherFolder, '/');
    }
    if (!in_array(true, (array) ($preview['embycache']['found'] ?? []), true) && !($preview['gather']['found'] ?? false)) {
        throw new Problem('jack_import_none');
    }
    $preview['ready'] = $do['embycache'] !== null || $do['gather'] !== null;
    $preview = jackImportScrub($preview, $secrets);
    // the token: what the page saw, what will be done, and Jack's own files as they are now
    $mine = '';
    foreach ([$ctx['jack_dir'] . '/mediacache_settings.json', $ctx['jack_dir'] . '/mediacache_exclude.txt', $ctx['jack_dir'] . '/mediacache_origin.json',
              $ctx['gather_dir'] . '/gather.json'] as $f) {
        $mine .= (string) @sha1_file($f) . '|';
    }
    $preview['token'] = sha1(jsonEncode($preview) . "\n" . sha1(jsonEncode($do)) . "\n" . $mine);
    return ['preview' => $preview, 'do' => $do, 'secrets' => $secrets];
}

/** Every secret value replaced, wherever it appears in an answer for the page */
function jackImportScrub(mixed $data, array $secrets): mixed
{
    $secrets = array_values(array_filter(array_unique($secrets), fn ($s) => is_string($s) && strlen($s) >= 4));
    if (!$secrets) {
        return $data;
    }
    if (is_array($data)) {
        $out = [];
        foreach ($data as $k => $v) {
            $out[is_string($k) ? str_replace($secrets, '•••', $k) : $k] = jackImportScrub($v, $secrets);
        }
        return $out;
    }
    return is_string($data) ? str_replace($secrets, '•••', $data) : $data;
}

/** An address as the page may see it: no user:password@, no query */
function jackImportMaskUrl(string $url): string
{
    return preg_replace(['#^(https?://)[^/@]*@#', '#\?.*$#s'], ['$1•••@', '?•••'], $url);
}

/** MediaCache's part: settings and the list of what lies on the pool (an old origin list is no longer taken over, 1.65.0) */
function jackImportEmbyCache(string $dir, array $ctx, ?array $current, array &$secrets): array
{
    $files = [];
    foreach (['settings', 'exclude'] as $k) {
        // a standalone MediaCache's files, else an older EmbyCache's (embycache_*) — the same shape
        $files[$k] = jackImportRead($dir, JACK_IMPORT_FILES[$k][0], JACK_IMPORT_FILES[$k][1])
            ?? jackImportRead($dir, str_replace('mediacache_', 'embycache_', JACK_IMPORT_FILES[$k][0]), JACK_IMPORT_FILES[$k][1]);
    }
    $pv = ['found' => array_map(fn ($f) => $f !== null, $files), 'blockers' => [], 'warnings' => [], 'changes' => [], 'same' => 0,
           'defaults' => [], 'jack' => [], 'dropped' => [], 'instances' => [], 'users' => null, 'libraries' => null,
           'exclude' => null];
    $cfg = null;
    if ($files['settings'] !== null) {
        $old = json_decode($files['settings'], true);
        if (!is_array($old) || ($old && array_is_list($old))) {
            $pv['blockers'][] = ['key' => 'bad_json'];
        } else {
            $cfg = jackImportSettings($old, $ctx, $current, $pv, $secrets);
        }
    }
    // the list: checked against the pool of the settings Jack will have
    $target = $cfg ?? $current;
    $cache = rtrim((string) ($target['cache_path'] ?? ''), '/');
    $exclude = null;
    if ($files['exclude'] !== null) {
        if ($target === null || $cache === '') {
            $pv['blockers'][] = ['key' => 'no_settings'];
        } else {
            $mine = array_values(array_filter(array_map('trim', explode("\n", (string) @file_get_contents($ctx['jack_dir'] . '/mediacache_exclude.txt')))));
            $exclude = $mine;
            if ($files['exclude'] !== null) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $files['exclude'])), fn ($l) => $l !== ''));
                $ok = array_values(array_unique(array_filter($lines, fn ($l) => jackImportPathOk($l, $cache, $ctx['shares']))));
                $exclude = array_values(array_unique(array_merge($mine, $ok)));
                sort($exclude, SORT_STRING);
                $bad = array_values(array_filter($lines, fn ($l) => !jackImportPathOk($l, $cache, $ctx['shares'])));
                $pv['exclude'] = ['lines' => count($lines), 'ok' => count($ok), 'bad' => count($bad),
                                  'bad_sample' => array_map(fn ($l) => mb_substr($l, 0, 200), array_slice($bad, 0, 5)),
                                  'already' => count(array_intersect($ok, $mine)), 'mine' => count($mine), 'total' => count($exclude)];
            }
            $away = count(array_filter($mine, fn ($l) => !str_starts_with($l, "$cache/")));
            if ($away) {                                   // Jack's own list on another pool: EmbyCache won't bring those back
                $pv['warnings'][] = ['key' => 'pool_change', 'params' => ['n' => $away, 'pool' => $cache]];
            }
        }
    }
    if ($files['settings'] !== null && $files['exclude'] === null) {
        $pv['warnings'][] = ['key' => 'no_exclude'];
    }
    if ($cfg !== null && !$pv['blockers']) {
        $detail = jackImportTrial($cfg, $ctx);
        if ($detail !== null) {
            $pv['blockers'][] = ['key' => 'config', 'params' => ['detail' => $detail]];
        }
    }
    $pv['ok'] = !$pv['blockers'] && in_array(true, $pv['found'], true);
    $do = $pv['ok'] ? ['settings' => $cfg, 'exclude' => $files['exclude'] !== null ? $exclude : null] : null;
    return [$pv, $do];
}

/** A line of the list: a file under <pool>/<share>/ of this server, no "." or ".." */
function jackImportPathOk(string $path, string $cache, array $shares): bool
{
    if ($cache === '' || !str_starts_with($path, "$cache/") || strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8')
        || preg_match('/[\x00-\x1f\x7f]/', $path)) {
        return false;
    }
    $parts = explode('/', substr($path, strlen($cache) + 1));
    if (count($parts) < 2 || !in_array($parts[0], $shares, true)) {
        return false;
    }
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/**
 * The old settings mapped onto EmbyCache's keys of the version that ships with the office: keys
 * it doesn't know are left out; keys the old install doesn't have keep Jack's current value, or —
 * when he has none — Jack's own default (JACK_DEFAULTS, as his setup page would choose);
 * values that aren't valid here or belong to the office are Jack's. Fills the preview's lists;
 * returns the settings to write.
 */
function jackImportSettings(array $old, array $ctx, ?array $current, array &$pv, array &$secrets): array
{
    $defaults = (array) $ctx['defaults'];
    $structured = ['instances', 'path_mappings', 'valid_users', 'libraries'];
    foreach (array_keys($old) as $k) {
        if (!array_key_exists($k, $defaults) && $k !== 'library_types') {
            $pv['dropped'][] = (string) $k;          // the name only: its value may be anything
        }
    }
    $cfg = [];
    foreach ($defaults as $k => $def) {
        if (in_array($k, $structured, true)) {
            continue;
        }
        if (array_key_exists($k, JACK_IMPORT_QUIET)) {   // Jack's own, whatever the old file says — not worth a line in the preview
            $cfg[$k] = JACK_IMPORT_QUIET[$k];
            continue;
        }
        if ($k === 'cache_path') {                   // the pool: one of this server's, never a default
            $v = is_string($old[$k] ?? null) ? rtrim($old[$k], '/') : '';
            if (!in_array($v, (array) $ctx['pools'], true)) {
                $pv['blockers'][] = ['key' => 'pool', 'params' => ['path' => mb_substr($v, 0, 200)]];
            }
            $cfg[$k] = $v;
            continue;
        }
        if (!array_key_exists($k, $old)) {
            $mine = $current[$k] ?? null;
            $kept = $mine !== null && !isset(JACK_IMPORT_FIXED[$k]) && jackImportValueOk($k, $mine, $def);
            $cfg[$k] = $kept ? $mine : jackImportJackDefault($k, $def, $old);
            $pv['defaults'][] = ['key' => $k, 'kept' => $kept, 'value' => jackImportShow($cfg[$k])];
            continue;
        }
        $v = $old[$k];
        if (isset(JACK_IMPORT_FIXED[$k])) {
            $cfg[$k] = JACK_IMPORT_FIXED[$k];
            if (!is_string($v) || ($k === 'array_disks_glob' ? $v : rtrim($v, '/')) !== JACK_IMPORT_FIXED[$k]) {
                $pv['jack'][] = ['key' => $k, 'old' => jackImportShow($v), 'new' => JACK_IMPORT_FIXED[$k]];
            }
            continue;
        }
        if ($k === 'cache_budget' && is_string($v)) {
            $v = strtoupper(str_replace(' ', '', $v));
        }
        if (jackImportValueOk($k, $v, $def)) {
            $cfg[$k] = $v;
        } else {
            $cfg[$k] = $def;
            $pv['jack'][] = ['key' => $k, 'old' => jackImportShow($v), 'new' => $def];
        }
    }

    // servers: their mappings (the old global ones merged in, as EmbyCache does), the key stays here
    $global = is_array($old['path_mappings'] ?? null) ? $old['path_mappings'] : [];
    $instances = [];
    foreach (is_array($old['instances'] ?? null) ? $old['instances'] : [] as $n => $i) {
        if (!is_array($i)) {
            continue;
        }
        $url = rtrim(is_string($i['url'] ?? null) ? trim($i['url']) : '', '/');
        $type = jackType($i['type'] ?? null);          // MediaCache's kind (an EmbyCache file has none: emby)
        $name = mb_substr(trim(is_string($i['servername'] ?? null) ? $i['servername'] : ''), 0, 60) ?: JACK_SERVER_NAMES[$type] . (count($instances) + 1);
        $key = is_string($i['api_key'] ?? null) ? trim($i['api_key']) : '';
        if ($key !== '') {
            $secrets[] = $key;                        // even one that isn't valid never goes to the page
        }
        $state = 'found';
        if (!preg_match(JACK_KEY, $key)) {
            $key = '';
            foreach ((array) ($current['instances'] ?? []) as $c) {
                if (is_array($c) && rtrim((string) ($c['url'] ?? ''), '/') === $url) {
                    $key = (string) ($c['api_key'] ?? '');
                }
            }
            $state = preg_match(JACK_KEY, $key) ? 'jack' : 'missing';
        }
        $maps = $bad = $show = [];
        // the old global mappings first, the server's own over them (as EmbyCache merges them)
        foreach (array_replace($global, is_array($i['path_mappings'] ?? null) ? $i['path_mappings'] : []) as $from => $to) {
            $from = rtrim((string) $from, '/');
            $to = is_string($to) ? rtrim($to, '/') : null;
            if ($from === '' || $to === null || !jackMappingOk($from, $to)) {
                $bad[] = mb_substr($from, 0, 200);
                continue;
            }
            $maps[$from] = $to;
            $show[] = ['from' => $from, 'to' => $to, 'there' => $to === '' ? true : jackImportThere($to, $ctx)];
        }
        $okUrl = (bool) preg_match('#^https?://\S+$#D', $url) && strlen($url) <= 500;
        $pv['instances'][] = ['servername' => $name, 'type' => $type, 'url' => jackImportMaskUrl(mb_substr($url, 0, 200)), 'key' => $state,
                              'mappings' => $show, 'bad_mappings' => $bad];
        if (!$okUrl) {
            $pv['blockers'][] = ['key' => 'bad_url', 'params' => ['server' => $name]];
        }
        if ($state === 'missing') {
            $pv['blockers'][] = ['key' => 'no_key', 'params' => ['server' => $name]];
        }
        $instances[] = ['servername' => $name, 'type' => $type, 'url' => $url, 'api_key' => $key, 'path_mappings' => $maps]
            + ($type === 'plex' && is_int($i['days_to_monitor'] ?? null) ? ['days_to_monitor' => $i['days_to_monitor']] : []);
    }
    if (!$instances) {
        $pv['blockers'][] = ['key' => 'no_server'];
    }
    $cfg['instances'] = $instances;
    $cfg['path_mappings'] = [];

    // people: a list of ids, or {id: {budget}} when some have a budget of their own
    $vu = is_array($old['valid_users'] ?? null) ? $old['valid_users'] : [];
    $users = [];
    $badUsers = 0;
    foreach ($vu as $k => $v) {
        $id = array_is_list($vu) ? $v : $k;
        $opts = array_is_list($vu) ? null : $v;
        if (!(is_string($id) || is_int($id)) || !preg_match('/^[\w-]{1,64}$/D', (string) $id)) {
            $badUsers++;
            continue;
        }
        $b = is_array($opts) && is_string($opts['budget'] ?? null) ? strtoupper(str_replace(' ', '', $opts['budget'])) : '';
        if ($b !== '' && !preg_match('/^\d+(\.\d+)?[KMGTP]?B?$/D', $b)) {
            $badUsers++;
            $b = '';
        }
        $users[(string) $id] = $b !== '' ? ['budget' => $b] : (object) [];
    }
    $cfg['valid_users'] = array_filter($users, fn ($u) => is_array($u)) ? $users : array_map('strval', array_keys($users));
    $pv['users'] = ['n' => count($users), 'budgets' => count(array_filter($users, fn ($u) => is_array($u))), 'bad' => $badUsers];
    $cfg['libraries'] = array_values(array_filter(is_array($old['libraries'] ?? null) ? $old['libraries'] : [], fn ($l) => is_string($l) && strlen($l) <= 200));
    $pv['libraries'] = $cfg['libraries'];
    // what kind each library is: only the office's overview — kept from Jack's own where the name matches
    $cfg['library_types'] = array_intersect_key(is_array($current['library_types'] ?? null) ? $current['library_types'] : [], array_flip($cfg['libraries']));

    $listed = array_merge(['instances', 'path_mappings', 'valid_users', 'library_types', 'libraries'], array_column($pv['defaults'], 'key'), array_column($pv['jack'], 'key'));
    foreach ($cfg as $k => $v) {
        if (in_array($k, $listed, true)) {
            continue;                                  // shown with the servers, people, defaults or Jack's own
        }
        if ($current !== null && array_key_exists($k, $current) && $current[$k] === $v) {
            $pv['same']++;
        } else {
            $pv['changes'][] = ['key' => $k, 'old' => $current !== null && array_key_exists($k, $current) ? jackImportShow($current[$k]) : null, 'new' => jackImportShow($v)];
        }
    }
    return $cfg;
}

/** Jack's own default for a key: what his setup page would choose, else EmbyCache's DEFAULTS */
function jackImportJackDefault(string $key, mixed $def, array $old): mixed
{
    if (isset(JACK_IMPORT_FIXED[$key])) {
        return JACK_IMPORT_FIXED[$key];
    }
    if (in_array($key, ['max_resume_movies', 'max_resume_series'], true)) {
        $items = $old['max_resume_items'] ?? null;
        return is_int($items) && $items >= 0 && $items <= 100000 ? $items : 10;
    }
    return array_key_exists($key, JACK_DEFAULTS) ? JACK_DEFAULTS[$key] : $def;
}

/** A value as the preview shows it (strings capped; lists and objects as JSON) */
function jackImportShow(mixed $v): mixed
{
    if (is_string($v)) {
        return mb_substr($v, 0, 200);
    }
    if (is_array($v) || is_object($v)) {
        return mb_substr(json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 0, 200);
    }
    return $v;
}

/** Is an old value valid for EmbyCache here? Its type as the default's, the few choices it knows, nothing that runs or writes elsewhere */
function jackImportValueOk(string $key, mixed $v, mixed $def): bool
{
    return match (true) {
        $key === 'mover_bin'      => in_array($v, JACK_MOVER_BINS, true),
        $key === 'rsync_args'     => is_array($v) && array_is_list($v) && $v && count($v) <= 20
                                     && !array_filter($v, fn ($a) => !is_string($a) || (!preg_match(JACK_RSYNC_SHORT, $a) && !in_array($a, JACK_RSYNC_LONG, true))),
        $key === 'cache_budget'   => is_string($v) && ($v === '' || (bool) preg_match('/^\d+(\.\d+)?[KMGTP]?B?$/D', $v)),
        $key === 'movie_mode'     => in_array($v, ['folder', 'file'], true),
        isset(JACK_TOOLS[$key]) => $v === JACK_TOOLS[$key],   // MediaCache's own way, nothing else
        $key === 'array_source'   => in_array($v, ['user0', 'disk'], true),
        $key === 'mover_debug_level' => is_int($v) && $v >= 0 && $v <= 3,
        in_array($key, JACK_IMPORT_PCT, true) => (is_int($v) || is_float($v)) && $v >= 0 && $v <= 100,
        is_bool($def)             => is_bool($v),
        $def === null             => $v === null || (is_int($v) && $v >= 0 && $v <= 100000),
        is_int($def), is_float($def) => (is_int($v) || is_float($v)) && $v >= 0 && $v <= 100000,
        is_string($def)           => is_string($v) && strlen($v) <= 200 && !preg_match('/[\x00-\x1f\x7f]/', $v),
        default                   => false,
    };
}

/** Is a mapping's host folder there on this server? null = its disks sleep, not looked at */
function jackImportThere(string $host, array $ctx): ?bool
{
    if (!preg_match('#^/mnt/user/([^/]+)(/.*)?$#D', $host, $m) || !in_array($m[1], (array) $ctx['shares'], true)) {
        return false;
    }
    if (($m[2] ?? '') === '') {
        return true;                                   // the share itself: its config is on the flash
    }
    foreach (importShareBases($m[1], $ctx) as $b) {
        if (baseAsleep($b, (array) $ctx['asleep'])) {
            return null;
        }
    }
    return is_dir(($ctx['fs'] ?? '') . $host);
}

/** Would EmbyCache accept these settings? Its own save_config() + load_config() on a trial folder; null = yes, else why not */
function jackImportTrial(array $cfg, array $ctx): ?string
{
    $dir = ($ctx['tmp'] ?? RUN_DIR) . '/jack-import-trial.' . bin2hex(random_bytes(4));
    try {
        jackWriteSettings($cfg, $dir, (string) ($ctx['tmp'] ?? RUN_DIR));
        return null;
    } catch (Problem $p) {
        return (string) ($p->params['detail'] ?? $p->key);
    } finally {
        @unlink("$dir/mediacache_settings.json");
        @unlink("$dir/.mediacache_settings.trial.json");
        @rmdir($dir);
    }
}

/** The gather's part: consolidate.ini onto Jack's gather settings (shares, free space, duplicates, the cache) */
function jackImportGather(string $dir, array $ctx, array $emby): array
{
    $text = jackImportRead($dir, JACK_IMPORT_FILES['ini'][0], JACK_IMPORT_FILES['ini'][1]);
    $pv = ['found' => $text !== null, 'blockers' => [], 'warnings' => [], 'changes' => [], 'jack' => [], 'dropped' => [],
           'shares' => [], 'dropped_shares' => [], 'strange' => 0];
    if ($text === null) {
        return [$pv + ['ok' => false], null];
    }
    [$vars, $pv['strange']] = jackImportIni($text);
    $cur = readJson($ctx['gather_dir'] . '/gather.json');
    if ($cur !== null) {                                // a gather.json from before the cache switch: off, «leave them»
        $cur += ['move_cache' => false, 'cache_only_target' => 'skip'];
    }
    $new = ['shares' => [], 'min_free_gb' => (int) ($cur['min_free_gb'] ?? 256), 'dup_check' => ($cur['dup_check'] ?? 'size') === 'cmp' ? 'cmp' : 'size',
            'move_cache' => ($cur['move_cache'] ?? false) === true, 'cache_only_target' => ($cur['cache_only_target'] ?? '') === 'most-free' ? 'most-free' : 'skip'];
    $jack = ['LOGFILE' => $ctx['gather_dir'] . '/consolidate.log', 'ARRAY_PATTERN' => '/mnt/disk[0-9]*', 'CACHE_PATTERN' => null,
             'EXCLUDE_FILE' => $ctx['jack_dir'] . '/mediacache_exclude.txt', 'DRYRUN' => 'true'];
    foreach ($vars as $k => $v) {
        if (in_array($k, GATHER_IMPORT_KEYS, true)) {
            continue;
        }
        if (!in_array($k, GATHER_IMPORT_JACK, true)) {
            $pv['dropped'][] = $k;
            continue;
        }
        $old = is_array($v) ? implode(' ', $v) : $v;
        if ($k === 'EXCLUDE_FILE' && $old !== '' && $old !== $jack[$k]) {
            $pv['warnings'][] = ['key' => 'gather_exclude', 'params' => ['path' => mb_substr($old, 0, 200)]];
        }
        if ($k !== 'CACHE_PATTERN' && $old !== $jack[$k]) {
            $pv['jack'][] = ['key' => $k, 'old' => mb_substr($old, 0, 200), 'new' => $jack[$k]];
        }
    }
    foreach (is_array($vars['BASE_DIRS'] ?? null) ? $vars['BASE_DIRS'] : (isset($vars['BASE_DIRS']) ? [$vars['BASE_DIRS']] : []) as $base) {
        $base = rtrim($base, '/');
        if (preg_match('#^/mnt/user/([\w.\- ]+)$#uD', $base, $m) && in_array($m[1], (array) $ctx['shares'], true)) {
            $new['shares'][] = $m[1];
        } else {
            $pv['dropped_shares'][] = ['path' => mb_substr($base, 0, 200),
                                       'why' => preg_match('#^/mnt/user/([\w.\- ]+)$#uD', $base) ? 'missing' : (str_starts_with($base, '/mnt/user/') ? 'not_share' : 'outside')];
        }
    }
    $new['shares'] = array_values(array_unique($new['shares']));
    // MOVE_CACHE: the old install's own ini (its setup never wrote it — «--include-cache» on its command line isn't read)
    foreach (['MIN_FREE_GB' => 'min_free_gb', 'DUP_CHECK' => 'dup_check', 'MOVE_CACHE' => 'move_cache', 'CACHE_ONLY_TARGET' => 'cache_only_target'] as $ini => $key) {
        if (!array_key_exists($ini, $vars)) {
            continue;
        }
        $v = $vars[$ini];
        $ok = match ($key) {
            'min_free_gb'       => is_string($v) && preg_match('/^\d{1,6}$/D', $v) && (int) $v <= 100000,
            'dup_check'         => in_array($v, ['size', 'cmp'], true),
            'move_cache'        => in_array($v, ['true', 'false'], true),
            'cache_only_target' => in_array($v, GATHER_CACHE_ONLY, true),
        };
        if ($ok) {
            $new[$key] = match ($key) { 'min_free_gb' => (int) $v, 'move_cache' => $v === 'true', default => $v };
        } else {
            $pv['jack'][] = ['key' => $ini, 'old' => jackImportShow($v), 'new' => $new[$key]];
        }
    }
    $pools = jackGatherPools($new['shares'], $emby, $ctx['share_cfg']);
    $oldCache = $vars['CACHE_PATTERN'] ?? null;
    if ($oldCache !== null && (is_array($oldCache) ? implode(' ', $oldCache) : $oldCache) !== implode(' ', $pools)) {
        $pv['jack'][] = ['key' => 'CACHE_PATTERN', 'old' => mb_substr(is_array($oldCache) ? implode(' ', $oldCache) : $oldCache, 0, 200), 'new' => implode(' ', $pools)];
    }
    $pv['shares'] = $new['shares'];
    if (!$new['shares']) {
        $pv['blockers'][] = ['key' => 'no_share'];
    }
    foreach ($new as $k => $v) {
        $was = $cur[$k] ?? null;
        if ($was !== $v) {
            $pv['changes'][] = ['key' => $k, 'old' => $was === null ? null : jackImportShow($was), 'new' => jackImportShow($v)];
        }
    }
    $pv['ok'] = !$pv['blockers'];
    return [$pv, $pv['ok'] ? jackGatherCheck($new, (array) $ctx['shares']) : null];
}

/**
 * consolidate.ini as consolidate_master.sh would see it — read, never sourced: NAME=value lines
 * with '…', "…" (no $ or `) or plain words, NAME=( … ) for a list (over several lines too);
 * comments and empty lines skipped. Returns [name => value or list, lines not understood].
 */
function jackImportIni(string $text): array
{
    $vars = [];
    $strange = 0;
    $lines = preg_split('/\r?\n/', $text);
    for ($l = 0; $l < count($lines); $l++) {
        $line = trim($lines[$l]);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/sD', $line, $m)) {
            $strange++;
            continue;
        }
        $value = $m[2];
        if (str_starts_with($value, '(')) {
            $value = substr($value, 1);
            while (($words = jackImportWords($value, true)) === false && $l + 1 < count($lines) && strlen($value) < 65536) {
                $value .= "\n" . $lines[++$l];
            }
        } else {
            $words = jackImportWords($value, false);
        }
        if (!is_array($words)) {
            $strange++;
            continue;
        }
        $vars[$m[1]] = str_starts_with($m[2], '(') ? $words : ($words[0] ?? '');
    }
    return [$vars, $strange];
}

/**
 * Shell words without any expansion: '…' as is, "…" with \ escapes (a $ or ` in it = refused),
 * \x, plain characters. A list ends at ")"; after the value only blanks or a comment.
 * Returns the words, false when a list or a quote isn't closed yet, null when refused.
 */
function jackImportWords(string $s, bool $list): array|false|null
{
    $words = [];
    $cur = null;
    $n = strlen($s);
    for ($i = 0; $i < $n;) {
        $c = $s[$i];
        if ($c === "'") {
            $j = strpos($s, "'", $i + 1);
            if ($j === false) {
                return $list ? false : null;
            }
            $cur = ($cur ?? '') . substr($s, $i + 1, $j - $i - 1);
            $i = $j + 1;
        } elseif ($c === '"') {
            $buf = '';
            for ($i++; $i < $n && $s[$i] !== '"'; $i++) {
                if ($s[$i] === '$' || $s[$i] === '`') {
                    return null;
                }
                if ($s[$i] === '\\' && $i + 1 < $n && str_contains("\"\\\$`\n", $s[$i + 1])) {
                    $i++;
                }
                $buf .= $s[$i];
            }
            if ($i >= $n) {
                return $list ? false : null;
            }
            $cur = ($cur ?? '') . $buf;
            $i++;
        } elseif ($c === '\\' && $i + 1 < $n) {
            $cur = ($cur ?? '') . $s[$i + 1];
            $i += 2;
        } elseif ($c === ' ' || $c === "\t" || $c === "\n") {
            if ($cur !== null) {
                $words[] = $cur;
                $cur = null;
            }
            if (!$list && $c !== "\n") {           // after a value: only a comment may follow
                $rest = ltrim(substr($s, $i));
                return $rest === '' || $rest[0] === '#' ? $words : null;
            }
            $i++;
        } elseif ($list && $c === '#' && $cur === null) {
            $nl = strpos($s, "\n", $i);
            if ($nl === false) {
                return false;
            }
            $i = $nl;
        } elseif ($list && $c === ')') {
            if ($cur !== null) {
                $words[] = $cur;
            }
            $rest = ltrim(substr($s, $i + 1));
            return $rest === '' || $rest[0] === '#' ? $words : null;
        } elseif (str_contains('$`;&|<>()', $c) || ($list && (str_contains('*?[{', $c) || ($c === '~' && $cur === null)))) {
            return null;                               // something bash would run or expand
        } else {
            $cur = ($cur ?? '') . $c;
            $i++;
        }
    }
    if ($list) {
        return false;
    }
    if ($cur !== null) {
        $words[] = $cur;
    }
    return $words ?: [''];
}

/**
 * Does what the preview showed: a copy of each of Jack's files first (<file>.before-import-<time>,
 * next to it), then MediaCache's settings (its own save_config(), trial first), the list merged,
 * the gather's settings — and the gather's ini follows EmbyCache's pool.
 */
function jackImportApply(array $do, array $ctx): array
{
    $stamp = date('Ymd-His');
    $backups = [];
    if ($do['embycache']) {
        $e = $do['embycache'];
        jackDataDir($ctx['jack_dir']);
        $backups = jackImportBackup($ctx['jack_dir'], array_filter(['mediacache_settings.json' => $e['settings'] !== null,
            'mediacache_exclude.txt' => $e['exclude'] !== null]), $stamp);
        if ($e['settings'] !== null) {
            jackWriteSettings($e['settings'], $ctx['jack_dir'], (string) ($ctx['tmp'] ?? RUN_DIR));
        }
        if ($e['exclude'] !== null) {
            writeAtomic($ctx['jack_dir'] . '/mediacache_exclude.txt', $e['exclude'] ? implode("\n", $e['exclude']) . "\n" : '', 0600, 0, 0);
            jackListNote($ctx['jack_dir']);             // the list as taken over: an emptied one later is told apart
        }
    }
    $emby = readJson($ctx['jack_dir'] . '/mediacache_settings.json') ?? [];
    if ($do['gather']) {
        $backups = array_merge($backups, jackImportBackup($ctx['gather_dir'], ['gather.json' => true, 'consolidate.ini' => true], $stamp));
        jackSaveGather($do['gather'], $emby, $ctx['gather_dir'], $ctx['jack_dir']);
    } elseif ($do['embycache'] && ($g = readJson($ctx['gather_dir'] . '/gather.json')) && !empty($g['shares'])) {
        jackWriteGatherIni($g, $emby, $ctx['gather_dir'], $ctx['jack_dir']);     // follows the pool, like Jack's own save
    }
    return ['stamp' => $stamp, 'backups' => $backups, 'embycache' => $do['embycache'] !== null, 'gather' => $do['gather'] !== null,
            'settings' => ($do['embycache']['settings'] ?? null) !== null,
            'exclude' => isset($do['embycache']['exclude']) ? count($do['embycache']['exclude']) : null];
}

/** A copy of each of these files of Jack's that exists (importBackup(): plain files only), named <file>.before-import-<time> */
function jackImportBackup(string $dir, array $names, string $stamp): array
{
    return importBackup($dir, $names, $stamp);
}

// ===================================================================== who watches Emby (before and during a real gather)

/*
 * The rule (2026-10-06): Jack never starts a real gather while someone watches Emby — it moves
 * files across the array disks and wakes every one of them. Before each real run he asks every
 * Emby server of EmbyCache's settings for its /Sessions (the key stays in this process: PHP's
 * curl, a header in memory — never a command line, a log or the page's state). A session with a
 * NowPlayingItem (also paused) = someone watches — with its LastActivityDate (`seen`), so the page
 * can show a session left behind (a device asleep with a film paused, hours ago) and how to end it
 * in Emby. Emby that doesn't answer (refused, timeout) =
 * down: the run may go, said in the log. Emby that answers but not usably (401/403, another
 * status, no JSON) = don't start, say why. Dry runs are never asked about.
 */

/**
 * One GET of who plays what: <url>/Sessions (Emby, Jellyfin), <url>/status/sessions (Plex) — HTTP status, body (capped),
 * curl's error number and text.
 *
 * @return array{status: int, body: string, errno: int, error: string}
 */
function jackWatchFetch(string $url, string $key, int $timeout = JACK_WATCH_TIMEOUT, string $type = 'emby'): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'errno' => -1, 'error' => 'no curl in PHP'];
    }
    $body = '';
    $c = curl_init(rtrim($url, '/') . ($type === 'plex' ? '/status/sessions' : '/Sessions'));
    curl_setopt_array($c, [
        CURLOPT_HTTPHEADER     => [jackKeyHeader($type, $key), 'Accept: application/json'],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_PROXY          => '',
        CURLOPT_NOPROXY        => '*',
        CURLOPT_USERAGENT      => 'unraid-secretary-office',
        CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$body): int {
            if (strlen($body) > 8 * 1024 * 1024) {
                return 0;                       // far more than sessions ever are: stop reading (no JSON then)
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    curl_setopt($c, defined('CURLOPT_PROTOCOLS_STR') ? CURLOPT_PROTOCOLS_STR : CURLOPT_PROTOCOLS,
        defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : (CURLPROTO_HTTP | CURLPROTO_HTTPS));
    curl_exec($c);
    $out = ['status' => (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE), 'body' => $body, 'errno' => curl_errno($c), 'error' => curl_error($c)];
    unset($c);
    return $out;
}

/**
 * One server's answer, judged: free | watching (who: user, title, device, client, paused, seen) | down |
 * error (why: jack_watch_key, jack_watch_answer).
 *
 * @param array{status: int, body: string, errno: int, error: string} $r
 */
function jackWatchJudge(array $r, string $type = 'emby'): array
{
    $status = (int) ($r['status'] ?? 0);
    if ($status === 0) {
        // no HTTP answer at all: refused, timed out, name unknown, connection dropped — Emby is down
        $errno = (int) ($r['errno'] ?? 0);
        if (in_array($errno, [6, 7, 28, 52, 55, 56], true)) {
            return ['state' => 'down', 'detail' => jackWatchText((string) ($r['error'] ?? ''), 200)];
        }
        return ['state' => 'error', 'why' => 'jack_watch_answer', 'detail' => jackWatchText((string) ($r['error'] ?? '') ?: "curl $errno", 200)];
    }
    if ($status === 401 || $status === 403) {
        return ['state' => 'error', 'why' => 'jack_watch_key', 'detail' => "HTTP $status"];
    }
    if ($status !== 200) {
        return ['state' => 'error', 'why' => 'jack_watch_answer', 'detail' => "HTTP $status"];
    }
    $j = json_decode((string) ($r['body'] ?? ''), true);
    if ($type === 'plex') {
        return jackWatchJudgePlex($j);
    }
    if (!is_array($j) || !array_is_list($j)) {
        return ['state' => 'error', 'why' => 'jack_watch_answer', 'detail' => 'no JSON'];
    }
    $who = [];
    foreach ($j as $s) {
        $np = is_array($s) ? ($s['NowPlayingItem'] ?? null) : null;
        if (!is_array($np) || !$np) {
            continue;
        }
        $title = jackWatchText((string) ($np['Name'] ?? ''));
        $series = jackWatchText((string) ($np['SeriesName'] ?? ''));
        if ($series !== '') {
            $se = is_int($np['ParentIndexNumber'] ?? null) && is_int($np['IndexNumber'] ?? null)
                ? sprintf('S%02dE%02d ', $np['ParentIndexNumber'], $np['IndexNumber']) : '';
            $title = trim("$series – $se$title");
        }
        $who[] = ['user' => jackWatchText((string) ($s['UserName'] ?? '')), 'title' => $title,
                  'device' => jackWatchText((string) ($s['DeviceName'] ?? '')), 'client' => jackWatchText((string) ($s['Client'] ?? '')),
                  'paused' => !empty($s['PlayState']['IsPaused']), 'seen' => jackWatchSeen($s['LastActivityDate'] ?? null)];
    }
    return $who ? ['state' => 'watching', 'who' => array_slice($who, 0, JACK_WATCH_SHOWN)] : ['state' => 'free'];
}

/**
 * Plex's /status/sessions: {MediaContainer: {size, Metadata: [{title, grandparentTitle, parentIndex, index, User: {title},
 * Player: {title, product, state}}]}} — every entry plays (Plex lists nothing else), paused by Player.state. Plex says
 * no last activity.
 */
function jackWatchJudgePlex(mixed $j): array
{
    $mc = is_array($j) ? ($j['MediaContainer'] ?? null) : null;
    if (!is_array($mc) || (isset($mc['Metadata']) && !is_array($mc['Metadata']))) {
        return ['state' => 'error', 'why' => 'jack_watch_answer', 'detail' => 'no JSON'];
    }
    $who = [];
    foreach ((array) ($mc['Metadata'] ?? []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $title = jackWatchText((string) ($m['title'] ?? ''));
        $series = jackWatchText((string) ($m['grandparentTitle'] ?? ''));
        if ($series !== '' && ($m['type'] ?? '') === 'episode') {
            $se = is_int($m['parentIndex'] ?? null) && is_int($m['index'] ?? null) ? sprintf('S%02dE%02d ', $m['parentIndex'], $m['index']) : '';
            $title = trim("$series – $se$title");
        }
        $player = is_array($m['Player'] ?? null) ? $m['Player'] : [];
        $who[] = ['user' => jackWatchText((string) ($m['User']['title'] ?? '')), 'title' => $title,
                  'device' => jackWatchText((string) ($player['title'] ?? '')), 'client' => jackWatchText((string) ($player['product'] ?? '')),
                  'paused' => ($player['state'] ?? '') === 'paused', 'seen' => null];
    }
    return $who ? ['state' => 'watching', 'who' => array_slice($who, 0, JACK_WATCH_SHOWN)] : ['state' => 'free'];
}

/**
 * When Emby last heard from a session (its LastActivityDate, e.g. «2026-10-06T21:37:12.8805824Z»)
 * as a Unix time, or null: a device that went to sleep with a film paused stays «watching» for hours
 * — the page says how long ago that was. Only a full date with its zone counts; Emby's empty date
 * (year 1) is none; a clock ahead of ours is now. For the page and the list of runs, not the log.
 */
function jackWatchSeen(mixed $date, ?int $now = null): ?int
{
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})$/D', $date)) {
        return null;
    }
    // PHP's parser takes microseconds at most: Emby's seven digits of a second are cut to six
    $t = strtotime((string) preg_replace('/(\.\d{6})\d+/', '$1', $date));
    if ($t === false || $t < 946684800) {
        return null;
    }
    return min($t, $now ?? time());
}

/** A name from Emby as plain, short text (no control characters) */
function jackWatchText(string $s, int $max = 120): string
{
    $s = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($s, 'UTF-8')));
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

/**
 * Who watches Emby right now, over every server in EmbyCache's settings: watching (anyone on any
 * server) > error (a server answered unusably) > free (one answered: nobody) > down (none answered);
 * unknown = no server with a key to ask. Never carries the key.
 *
 * @return array{state: string, who: list<array>, why?: string, detail?: string, url?: string, time: int}
 */
function jackWatching(?array $settings = null, ?callable $fetch = null): array
{
    $settings ??= jackReadSettings() ?? [];
    $fetch ??= 'jackWatchFetch';
    $seen = [];
    foreach ((array) ($settings['instances'] ?? []) as $i) {
        $url = rtrim((string) ($i['url'] ?? ''), '/');
        $key = (string) ($i['api_key'] ?? '');
        if (!preg_match('#^https?://[^\s/]+(:\d+)?(/[^\s]*)?$#D', $url) || $key === '') {
            continue;
        }
        $type = jackType($i['type'] ?? null);
        $seen[] = jackWatchJudge($fetch($url, $key, JACK_WATCH_TIMEOUT, $type), $type) + ['url' => $url];
    }
    $time = time();
    $who = array_merge(...array_map(fn ($s) => $s['who'] ?? [], $seen ?: [[]]));
    if ($who) {
        return ['state' => 'watching', 'who' => array_slice($who, 0, JACK_WATCH_SHOWN), 'time' => $time];
    }
    foreach (['error', 'free', 'down'] as $state) {
        foreach ($seen as $s) {
            if ($s['state'] === $state) {
                return ['state' => $state, 'who' => []] + array_intersect_key($s, ['why' => 1, 'detail' => 1, 'url' => 1]) + ['time' => $time];
            }
        }
    }
    return ['state' => 'unknown', 'who' => [], 'time' => $time];
}

/** «anna: Film (iPad, paused); …» — for the office's log only */
function jackWatchersLine(array $who): string
{
    return implode('; ', array_map(fn ($w) => ($w['user'] ?: '?') . ': ' . ($w['title'] ?: '?')
        . ' (' . ($w['device'] ?: $w['client'] ?: '?') . ($w['paused'] ? ', paused' : '') . ')', $who));
}

/** A look that stops a run from the page: someone watches, or Emby's answer can't be used */
function jackWatchProblem(array $look): ?Problem
{
    return match ($look['state']) {
        'watching' => new Problem('jack_watching', ['who' => $look['who']]),
        'error'    => new Problem($look['why'] ?? 'jack_watch_answer', ['url' => $look['url'] ?? '', 'detail' => $look['detail'] ?? '']),
        default    => null,
    };
}

/**
 * Before a real run (php agent.php job gather run, embycache run|release): may it start? Unraid's mover at work (any
 * real run — jack-mover.php) or, before a real gather, someone watching Emby: from the page (or the let-go) → refused.
 * On schedule → wait: look again every JACK_WATCH_EVERY (mover: JACK_MOVER_EVERY) seconds up to JACK_WATCH_MAX
 * (JACK_MOVER_MAX), then skip. While waiting it holds only its own wait lock (never the gather's or EmbyCache's) and
 * shows itself in jack-<tool>-wait.json (`why`: watching | mover) — both in RAM (RUN_DIR), so a wait of hours keeps
 * nothing open on the pool; a second scheduled start meanwhile adds nothing (result `already`). A real run of this tool
 * that started meanwhile (from the page) ends the wait, so does the array stopping (`array`), so does its schedule being
 * switched off meanwhile (`off` — Jack let go).
 * $o: look, mover, sleep, now, array, scheduled (callables), watch (ask Emby's watchers: a real gather), dir (the tool's
 * data), waitdir, every, max — for the tests.
 *
 * @return array{go: bool, result?: string, why?: string, look: array, waited: int, lock: mixed}
 *   go true: start now; `lock` is the wait lock (or null) — release it with jackWaitEnd() once the run shows as running
 */
function jackRunGate(string $tool, string $by, array $o = []): array
{
    $watch = (bool) ($o['watch'] ?? ($tool === 'gather'));
    $ask = $o['look'] ?? fn () => jackWatching();
    $mover = $o['mover'] ?? fn (): bool => jackMoverRunning();
    $look = function () use ($watch, $ask, $mover): array {
        if ($mover()) {
            return ['state' => 'mover', 'who' => []];
        }
        return $watch ? $ask() : ['state' => 'free', 'who' => []];
    };
    $sleep = $o['sleep'] ?? fn (int $s) => sleep($s);
    $now = $o['now'] ?? fn () => time();
    $dir = $o['dir'] ?? jackToolDir($tool);
    $waitDir = $o['waitdir'] ?? RUN_DIR;
    $started = $o['array'] ?? fn () => (readCfg('/var/local/emhttp/var.ini')['fsState'] ?? '') === 'Started';
    $scheduled = $o['scheduled'] ?? fn () => officeJobSchedule($tool)['enabled'];
    $blocked = fn (array $w): bool => in_array($w['state'], ['watching', 'mover'], true);
    $whyOf = fn (array $w): string => $w['state'] === 'mover' ? 'jack_mover_running' : 'jack_watching';

    $w = $look();
    if (!$blocked($w)) {
        return jackGateVerdict($w, 0, null);
    }
    if ($by !== 'schedule') {
        return ['go' => false, 'result' => 'refused', 'why' => $whyOf($w), 'look' => $w, 'waited' => 0, 'lock' => null];
    }
    @mkdir($waitDir, 0700, true);
    $lock = @fopen("$waitDir/jack-$tool-wait.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) {
            fclose($lock);
        }
        return ['go' => false, 'result' => 'already', 'look' => $w, 'waited' => 0, 'lock' => null];
    }
    $since = $now();
    while (true) {
        $mov = $w['state'] === 'mover';
        $until = $since + (int) ($o['max'] ?? ($mov ? JACK_MOVER_MAX : JACK_WATCH_MAX));
        $next = min($now() + (int) ($o['every'] ?? ($mov ? JACK_MOVER_EVERY : JACK_WATCH_EVERY)), $until);
        writeAtomic("$waitDir/jack-$tool-wait.json", jsonEncode(['pid' => getmypid(), 'since' => $since, 'until' => $until, 'next' => $next,
                                                                 'looked' => $now(), 'who' => $w['who'], 'why' => $mov ? 'mover' : 'watching']), 0600, 0, 0);
        if ($now() >= $until) {
            jackWaitEnd($lock, $waitDir, $tool);
            return ['go' => false, 'result' => 'skipped', 'why' => $whyOf($w), 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $sleep(max(1, $next - $now()));
        if (!$started()) {
            jackWaitEnd($lock, $waitDir, $tool);     // the array stopped meanwhile: no data folder, no run now
            return ['go' => false, 'result' => 'array', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        if (!$scheduled()) {
            jackWaitEnd($lock, $waitDir, $tool);     // its schedule was switched off meanwhile (Jack let go): no run now
            return ['go' => false, 'result' => 'off', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $run = readJson("$dir/office-run.json") ?? [];
        if (($run['mode'] ?? '') === 'run' && (int) ($run['started'] ?? 0) >= $since) {
            jackWaitEnd($lock, $waitDir, $tool);     // a real run started from the page meanwhile: this one is done
            return ['go' => false, 'result' => 'meanwhile', 'look' => $w, 'waited' => $now() - $since, 'lock' => null];
        }
        $w = $look();
        if (!$blocked($w)) {
            $v = jackGateVerdict($w, $now() - $since, $lock);
            if (!$v['go']) {
                jackWaitEnd($lock, $waitDir, $tool);
                $v['lock'] = null;
            }
            return $v;
        }
    }
}

/** A real gather's gate (jackRunGate() for the gather) — kept by its name */
function jackGatherGate(string $by, array $o = []): array
{
    return jackRunGate('gather', $by, $o);
}

/** The look that isn't «watching»: free/down/unknown → go; error → refused, why */
function jackGateVerdict(array $w, int $waited, mixed $lock): array
{
    if ($w['state'] === 'error') {
        return ['go' => false, 'result' => 'refused', 'why' => $w['why'] ?? 'jack_watch_answer', 'look' => $w, 'waited' => $waited, 'lock' => $lock];
    }
    return ['go' => true, 'look' => $w, 'waited' => $waited, 'lock' => $lock];
}

/** The wait is over: its file goes, its lock is let go */
function jackWaitEnd(mixed $lock, string $waitDir = RUN_DIR, string $tool = 'gather'): void
{
    if ($lock === null) {
        return;                            // no wait: nothing of a wait to remove (another start may be waiting)
    }
    @unlink("$waitDir/jack-$tool-wait.json");
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** A scheduled gather waiting for Emby to be free or the mover to finish (for the page), or null */
function jackGatherWaiting(string $waitDir = RUN_DIR): ?array
{
    return jackRunWaiting('gather', $waitDir);
}

/** A scheduled run of $tool that waits (why: watching | mover | backup — the joint run for Mr. Backupsy's local part), or null */
function jackRunWaiting(string $tool, string $waitDir = RUN_DIR): ?array
{
    $w = readJson("$waitDir/jack-$tool-wait.json");
    if (!$w || !flockHeld("$waitDir/jack-$tool-wait.lock")) {
        return null;                       // a wait whose job is gone (reboot, killed) is no wait
    }
    $why = in_array($w['why'] ?? '', ['mover', 'backup'], true) ? $w['why'] : 'watching';
    return array_intersect_key($w, ['since' => 1, 'until' => 1, 'next' => 1, 'looked' => 1, 'who' => 1]) + ['why' => $why];
}

/**
 * While a real run goes: is Unraid's mover at work? every $moverEvery seconds (any real run — jack-mover.php); and,
 * with $watchers (a real gather), Emby every $every seconds. The mover started, or someone watching → the stop request
 * ($stopFile: CONSOLIDATE_STOP, EMBYCACHE_STOP) is written once and the tool ends after the folder (the gather) or the
 * file (EmbyCache) it is on (exit 3, result `stopped`). Emby down or an unusable answer changes nothing during a run.
 * A stop request it didn't write (his panel's «Stop after this file», jack.stop) is noticed too and its why taken
 * (`user`). Returns the exit code, why it was asked to stop and who was watching then. $tick (EmbyCache's live panel)
 * is asked every half second.
 *
 * @param resource $proc
 * @return array{exit: int, stopped_for: ?array, stopped_why: ?string}
 */
function jackRunWatch($proc, string $stopFile, bool $watchers, ?callable $look = null, ?callable $mover = null,
                      int $every = JACK_WATCH_DURING, int $moverEvery = JACK_MOVER_LOOK, ?callable $tick = null): array
{
    $look ??= fn () => jackWatching();
    $mover ??= fn (): bool => jackMoverRunning(null, getmypid());
    $asked = null;
    $why = null;
    $last = $lastMover = time();
    while (true) {
        $st = proc_get_status($proc);
        if (!$st['running']) {
            proc_close($proc);
            // ended before the watch looked (a tool that stops itself at once): a stop request lying there still says why
            clearstatcache(true, $stopFile);
            if ($why === null && is_file($stopFile)) {
                $j = json_decode((string) @file_get_contents($stopFile, false, null, 0, 65536), true);
                $why = in_array($j['why'] ?? null, ['mover', 'watching'], true) ? $j['why'] : 'user';
                $asked = $why === 'watching' && is_array($j['who'] ?? null) ? $j['who'] : null;
            }
            return ['exit' => (int) $st['exitcode'], 'stopped_for' => $why === 'watching' ? $asked : null, 'stopped_why' => $why];
        }
        if ($why === null) {
            clearstatcache(true, $stopFile);
            if (is_file($stopFile)) {         // asked from elsewhere (his panel): its why
                $j = json_decode((string) @file_get_contents($stopFile, false, null, 0, 65536), true);
                $why = in_array($j['why'] ?? null, ['mover', 'watching'], true) ? $j['why'] : 'user';
                $asked = $why === 'watching' && is_array($j['who'] ?? null) ? $j['who'] : null;
                logLine('Jack Streamer: asked to stop (' . $why . ') — the ' . ($watchers ? 'gather stops after the current folder' : 'run stops after the current file'));
            }
        }
        if ($why === null && time() - $lastMover >= $moverEvery) {
            $lastMover = time();
            try {
                $on = $mover();
            } catch (Throwable) {
                $on = false;
            }
            if ($on) {
                $why = 'mover';
                writeAtomic($stopFile, jsonEncode(['time' => time(), 'why' => 'mover']), 0600, 0, 0);
                logLine('Jack Streamer: Unraid\'s mover started — the ' . ($watchers ? 'gather stops after the current folder' : 'run stops after the current file'));
            }
        }
        if ($watchers && $why === null && time() - $last >= $every) {
            $last = time();
            try {
                $w = $look();
            } catch (Throwable) {
                $w = ['state' => 'error'];
            }
            if ($w['state'] === 'watching') {
                $why = 'watching';
                $asked = $w['who'];
                writeAtomic($stopFile, jsonEncode(['time' => time(), 'who' => $asked, 'why' => 'watching']), 0600, 0, 0);
                logLine('Jack Streamer: someone started watching Emby — the gather stops after the current folder (' . jackWatchersLine($asked) . ')');
            }
        }
        if ($tick !== null) {
            $tick();                    // EmbyCache's live panel: a speed sample every 10 s (jack-progress.php)
        }
        usleep(500000);
    }
}

/** While a real gather runs (jackRunWatch() with Emby's watchers) — kept by its name */
function jackGatherWatch($proc, string $stopFile, ?callable $look = null, int $every = JACK_WATCH_DURING, ?callable $mover = null): array
{
    return jackRunWatch($proc, $stopFile, true, $look, $mover, $every);
}

// ===================================================================== runs

/**
 * Starts a run from the page: through the host's atd as "php agent.php job
 * <tool> <mode> --office", so it lives on without the agent. A real gather
 * first asks Emby who watches (the rule above) — no override: whoever really
 * wants it ends that session in Emby (its dashboard's Stop, or on the device)
 * or stops the Emby service himself.
 */
function jackStart(string $tool, string $mode): array
{
    if ($mode === 'release') {
        throw new Problem('unknown_target', ['target' => $mode]);      // only his let-go brings everything back (jack.letgo)
    }
    jackRunCheck($tool, $mode);
    if ($mode === 'run' && jackMoverRunning()) {
        logLine("Jack Streamer: $tool (run) not started — Unraid's mover is at work");
        throw new Problem('jack_mover_running');
    }
    // EmbyCache's real run is the night run with Ms. Moverelli (2026-10-13): frees, rechecks, fetches last — the whole of it
    if ($tool === 'embycache' && $mode === 'run' && function_exists('nightRun') && moverelliHired()) {
        if ((nightOrderHost()['backup_holds'])()) {
            // started here it never waits: refused while a backup does its local part (agent/lib/nightorder.php)
            logLine('Jack Streamer: the night run not started — a backup runs');
            throw new Problem('jack_backup_running');
        }
        hostLaunch('moverelli', [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', 'moverelli', 'run', '--office']);
        logLine('Jack Streamer: started the night run with Ms. Moverelli via at');
        usleep(800000);
        return ['ok' => true, 'state' => jackScan()];
    }
    if ($tool === 'gather' && in_array($mode, ['run', 'measure'], true)) {      // a measurement reads every disk of the shares: not while someone watches either
        // asked in the agent's own loop: a short look (an Emby that is slow counts as down here — the job asks again, fully)
        $look = jackWatching(null, fn (string $url, string $key, int $t = 0, string $type = 'emby') => jackWatchFetch($url, $key, JACK_WATCH_PAGE, $type));
        if ($p = jackWatchProblem($look)) {
            logLine("Jack Streamer: gather ($mode) not started — " . ($look['state'] === 'watching' ? 'someone watches Emby: ' . jackWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            throw $p;
        }
    }
    hostLaunch("jack-$tool", [PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', $tool, $mode, '--office']);
    logLine("Jack Streamer: started $tool ($mode) via at");
    usleep(800000);
    return ['ok' => true, 'state' => jackScan()];
}

/** May this run start now? (also asked again by the job itself) */
function jackRunCheck(string $tool, string $mode): void
{
    if ($tool === 'embycache') {
        if (!isset(JACK_MODES[$mode])) {
            throw new Problem('unknown_target', ['target' => $mode]);
        }
        if (!jackConfigured(jackReadSettings())) {
            throw jackNotConfigured();
        }
        // the gather is an optional helper (the owner, 2026-10-16): no real run waits for it any more
    } else {
        if (!isset(GATHER_MODES[$mode])) {
            throw new Problem('unknown_target', ['target' => $mode]);
        }
        if ($mode === 'measure') {
            if (!jackMeasureShares()) {
                throw new Problem('jack_measure_none');
            }
        } elseif (!jackGatherSettings() || !jackGatherSettings()['shares']) {
            throw new Problem('jack_gather_not_configured');
        }
    }
    if (jackJobInfo('embycache')['running'] || jackJobInfo('gather')['running']
        || flockHeld(JACK_DATA . '/mediacache.lock') || flockHeld(GATHER_LOCK)) {
        throw new Problem('jack_running');
    }
    // never beside a run of Ms. Moverelli's Smart Mover (agent/desks/moverelli.php) — any of hers, any of his
    if (function_exists('moverelliBusy') && moverelliBusy()) {
        throw new Problem('jack_moverelli_running');
    }
    // real runs only while Unraid's mover schedule is «Disabled» and Ms. Moverelli keeps his list out (jack-mover.php)
    if ($mode === 'run' && ($p = jackMoverProblem(jackMoverRule()))) {
        throw $p;
    }
}

function jackAnyRunning(): bool
{
    return jackJobInfo('embycache')['running'] || jackJobInfo('gather')['running']
        || flockHeld(JACK_DATA . '/mediacache.lock') || flockHeld(GATHER_LOCK);
}

function jackToolDir(string $tool): string
{
    return $tool === 'gather' ? GATHER_DATA : JACK_DATA;
}

/** The run started last (by the office or a schedule): mode, who, when, still at work? */
function jackJobInfo(string $tool): array
{
    $info = readJson(jackToolDir($tool) . '/office-run.json') ?? [];
    $pid = (int) ($info['pid'] ?? 0);
    $info['running'] = empty($info['finished']) && $pid > 1 && str_contains((string) @file_get_contents("/proc/$pid/cmdline"), 'agent.php');
    $out = jackToolDir($tool) . '/office-output.txt';
    $info['size'] = (int) @filesize($out);
    $info['updated'] = @filemtime($out) ?: null;
    return $info;
}

/**
 * "php agent.php job embycache|gather [mode] [--office]" — what the cron file
 * (job.sh) and the page's runs call. Never both at once: a real
 * run holds the other tool's lock while it works. EmbyCache's real run waits
 * for the gather's first one. Returns the tool's exit code.
 */
function jackJob(string $tool, array $args): int
{
    $by = in_array('--night', $args, true) ? 'night' : (in_array('--letgo', $args, true) ? 'letgo' : (in_array('--office', $args, true) ? 'office' : 'schedule'));
    $noFill = in_array('--nofill', $args, true);       // inside the night run: only free (give back, cleanup), fetch nothing
    // inside the joint run: who started it (Ms. Moverelli's schedule or her page) — his run list says that, never «at night»
    $from = $by === 'night' ? (in_array('--office', $args, true) ? 'office' : 'schedule') : null;
    $args = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--')));
    $mode = $args[0] ?? 'run';
    jackMigratePending();
    $dir = jackToolDir($tool);
    try {
        jackRunCheck($tool, $mode);
    } catch (Problem $p) {
        if (!in_array($p->key, ['jack_not_configured', 'jack_gather_not_configured', 'jack_measure_none'], true)) {
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + jackRefusedParams($p->params));
            if (str_starts_with($p->key, 'jack_mover_')) {
                logLine("Jack Streamer: $tool ($mode) not started — the mover could take MediaCache's files ($p->key); only dry runs go");
            }
        }
        if ($mode === 'release') {
            jackReleaseRefusedTell($p->key);
        }
        fwrite(STDERR, "$tool: not started ($p->key)\n");
        return 1;
    }
    // a real run: never while Unraid's mover is at work; a real gather never while someone watches Emby (the rule above) —
    // asked before any lock is taken
    $wait = null;
    $note = [];
    $real = ($tool === 'gather' && $mode === 'run') || ($tool === 'embycache' && in_array($mode, ['run', 'release'], true));
    if ($real) {
        $asked = time();
        $gate = jackRunGate($tool, $by);
        $look = $gate['look'];
        if (!$gate['go']) {
            return jackGateRefused($gate, $tool, $mode, $by, $asked);
        }
        $wait = $gate['lock'];
        if ($gate['waited'] > 0) {
            $note['waited'] = $gate['waited'];
        }
        if (in_array($look['state'], ['down', 'unknown'], true)) {
            $note['emby'] = $look['state'];
            logLine('Jack Streamer: ' . ($look['state'] === 'down' ? "Emby doesn't answer (" . ($look['detail'] ?? '') . ')' : 'no Emby server to ask')
                . ' — the gather goes ahead');
        }
        try {
            jackRunCheck($tool, $mode);           // after a wait: still nothing else at work?
        } catch (Problem $p) {
            jackWaitEnd($wait, RUN_DIR, $tool);
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key] + $note);
            if ($mode === 'release') {
                jackReleaseRefusedTell($p->key);
            }
            return 1;
        }
    }
    // a measurement from the page: asked again here, fully (Emby down = it goes, like a real gather)
    if ($tool === 'gather' && $mode === 'measure') {
        $look = jackWatching();
        if ($p = jackWatchProblem($look)) {
            logLine('Jack Streamer: measuring not started — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . jackWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + ($p->key === 'jack_watching' ? ['who' => $look['who']] : ['detail' => (string) ($look['detail'] ?? '')]));
            return 1;
        }
    }
    // bringing everything back when he is let go (jack-letgo.php): never while someone watches — asked again here, fully
    if ($tool === 'embycache' && $mode === 'release') {
        $look = jackWatching();
        if ($p = jackLetGoWatchProblem($look)) {
            logLine('Jack Streamer: the films are not brought back — ' . ($look['state'] === 'watching' ? 'someone watches Emby: ' . jackWatchersLine($look['who'])
                : "Emby's answer: " . ($look['why'] ?? '') . ' ' . ($look['detail'] ?? '')));
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => $p->key]
                + ($look['state'] === 'watching' ? ['who' => $look['who']] : ['detail' => (string) ($look['detail'] ?? '')]));
            jackReleaseRefusedTell($p->key);
            return 1;
        }
    }
    // the other tool's lock, held for the whole run, so it can't start meanwhile (a measurement too: EmbyCache moves files)
    $hold = null;
    if (in_array($mode, ['run', 'measure', 'release'], true)) {
        $hold = @fopen($tool === 'gather' ? JACK_DATA . '/mediacache.lock' : GATHER_LOCK, 'c');
        if (!$hold || !flock($hold, LOCK_EX | LOCK_NB)) {
            jackWaitEnd($wait, RUN_DIR, $tool);
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => 'jack_running']);
            if ($mode === 'release') {
                jackReleaseRefusedTell('jack_running');
            }
            return 1;
        }
    }
    jackDataDir($dir);
    // his list gone (or emptied by someone else) after his runs: written anew from what lies on the pool before MediaCache
    // reads it — an incomplete plan keeps «the list as it was», never an empty one for a lost one (H1, 2026-10-21); the
    // let-go's release brings back what it names. Not to be written anew: no real run (jack_list_lost)
    if ($tool === 'embycache' && in_array($mode, ['run', 'release'], true) && jackListEnsure(jackReadSettings(), $dir, ['wake' => true]) === 'lost') {
        jackWaitEnd($wait, RUN_DIR, $tool);
        if ($hold) {
            flock($hold, LOCK_UN);
            fclose($hold);
        }
        jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => 'jack_list_lost']);
        if ($mode === 'release') {
            jackReleaseRefusedTell('jack_list_lost');
        }
        return 1;
    }
    // the start lock shared with Ms. Moverelli (jack-mover.php): none of her runs going, then his marker — one at a time
    $movers = jackMoversLock();
    if ($movers === null || (function_exists('moverelliBusy') && moverelliBusy())) {
        jackMoversUnlock($movers);
        jackWaitEnd($wait, RUN_DIR, $tool);
        if ($hold) {
            flock($hold, LOCK_UN);
            fclose($hold);
        }
        jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => time(), 'finished' => time(), 'result' => 'refused', 'why' => 'jack_moverelli_running']);
        if ($mode === 'release') {
            jackReleaseRefusedTell('jack_moverelli_running');
        }
        return 1;
    }
    $started = time();
    $run = ['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $started, 'pid' => getmypid()] + ($from !== null ? ['from' => $from] : []);
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    jackMoversUnlock($movers);
    jackWaitEnd($wait, RUN_DIR, $tool);          // the run shows as running now: a second start is refused by that
    @unlink("$dir/status.json");
    @unlink("$dir/office-stop.json");
    // inside the night: «Stop after this file» asked while this job started (see moverelliJob()) — asked again here
    if ($by === 'night' && function_exists('nightHost') && is_file(($night = (string) nightHost()['dir']) . '/night-stop.json')) {
        writeAtomic("$dir/office-stop.json", (string) @file_get_contents("$night/night-stop.json"), 0600, 0, 0);
    }

    // the shares' folders on a ZFS pool: MediaCache makes the dataset itself, as Unraid does (create_share_root, 1.65.0)
    $progress = null;                    // a real EmbyCache run: its two RAM files for the live panel
    if ($tool === 'gather') {
        $text = $mode === 'measure' ? jackWriteMeasureIni(jackMeasureShares(), jackReadSettings() ?? [])
            : jackWriteGatherIni(jackGatherSettings() ?? [], jackReadSettings() ?? []);
        $ramIni = jackGatherIniRam($text, $mode);
        // no ini in RAM: no run (never the script with its own defaults) - it ends failed, said like any other failure
        $cmd = $ramIni !== null ? array_merge(['bash', GATHER_APP . '/consolidate_master.sh'], GATHER_MODES[$mode]) : null;
        $env = ['CONSOLIDATE_CONFIG' => (string) $ramIni, 'CONSOLIDATE_STATUS' => "$dir/status.json"]
             + ($mode === 'run' ? ['CONSOLIDATE_STOP' => "$dir/office-stop.json"] : []);
        $cwd = '/';
    } else {
        $cmd = array_merge(['python3', JACK_APP . '/mediacache_run.py'], JACK_MODES[$mode]);
        $env = jackPyEnv() + ['MEDIACACHE_RESULT' => "$dir/status.json"] + ($real ? ['MEDIACACHE_STOP' => "$dir/office-stop.json"] : [])
             // Ms. Moverelli's limit and his half (a dry run sees what the real one would)
             + (in_array($mode, ['dry', 'run'], true) ? jackPoolEnv(jackReadSettings()) : [])
             + ($noFill ? ['MEDIACACHE_NO_FILL' => '1'] : []);
        $cwd = JACK_APP;
        if ($real) {                     // the live panel on his page (jack-progress.php): EmbyCache's progress in RAM
            $progress = jackProgressPaths();
            jackProgressClear($progress);
            $env['MEDIACACHE_PROGRESS'] = $progress['file'];
        }
    }
    $out = fopen("$dir/office-output.txt", 'w');
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'HOME' => '/root', 'LANG' => 'C.UTF-8'] + $env;
    if ($cmd === null) {
        fwrite($out, "The gather's settings could not be put in RAM (" . RUN_DIR . ") - nothing done.\n");
    }
    $proc = $cmd !== null ? proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, $cwd, $env) : false;
    $stoppedFor = $stoppedWhy = null;
    if (!is_resource($proc)) {
        $exit = 127;
    } elseif ($real) {
        // stops after a folder (the gather) or a file (EmbyCache) when the mover starts — or someone starts watching (the gather)
        ['exit' => $exit, 'stopped_for' => $stoppedFor, 'stopped_why' => $stoppedWhy] = jackRunWatch($proc, "$dir/office-stop.json", $tool === 'gather',
            tick: $progress !== null ? jackProgressTicker($progress) : null);
    } else {
        $exit = proc_close($proc);
    }
    fclose($out);
    @unlink("$dir/office-stop.json");
    if (isset($ramIni)) {
        @unlink($ramIni);
    }
    if ($progress !== null) {
        jackProgressClear($progress);   // the panel goes with the run
    }
    if ($hold) {
        flock($hold, LOCK_UN);
        fclose($hold);
    }
    $status = readJson("$dir/status.json") ?? [];
    if ($tool === 'embycache' && in_array($mode, ['run', 'release'], true)) {
        // his list as his run left it (before the run shows finished: a look meanwhile never takes it for emptied by someone
        // else); a complete plan ends a «written anew» — the list is his own again
        jackListNote($dir, [], $exit === 0 && ($status['incomplete'] ?? null) === false);
    }
    $run += ['finished' => time(), 'exit' => $exit];
    writeAtomic("$dir/office-run.json", jsonEncode($run), 0600, 0, 0);
    $result = (string) ($status['result'] ?? ($exit === 0 ? 'ok' : 'failed'));
    if ($tool === 'gather' && $mode === 'run' && in_array($result, ['ok', 'errors'], true)) {
        writeAtomic("$dir/last-real.json", jsonEncode($status + ['by' => $by]), 0600, 0, 0);
    }
    if ($tool === 'gather') {
        try {
            jackGatherSizesKeep($status, $mode, $dir);       // what lies where, for his page (#8)
        } catch (Throwable $e) {
            fwrite(STDERR, "gather: sizes not kept: {$e->getMessage()}\n");
        }
    }
    if ($mode === 'release') {
        $c = (array) ($status['cleanup'] ?? []);
        logLine('Jack Streamer: brought ' . (int) ($c['done'] ?? 0) . ' of ' . (int) ($c['planned'] ?? 0) . ' files back to the array ('
            . $result . ', ' . (int) ($status['protected'] ?? 0) . ' still on the pool)');
    }
    if ($result === 'stopped') {
        // 1.64.2: a stop the watcher didn't see (the night run writes EmbyCache's own stop file, EmbyCache stops itself) was
        // asked by someone — only the watcher knows «watching», so without its word it is the user's
        $note['why'] = in_array($stoppedWhy, ['mover', 'watching'], true) ? $stoppedWhy : 'user';
        if ($note['why'] === 'watching') {
            $note['who'] = $stoppedFor ?? [];
        }
        logLine('Jack Streamer: ' . ($tool === 'gather' ? 'the gather stopped after ' . (int) ($status['folders_done'] ?? 0) . ' of ' . (int) ($status['folders'] ?? 0) . ' folders'
            : "EmbyCache ($mode) stopped after the file it was on") . ' — ' . match ($note['why']) {
                'mover' => "Unraid's mover started", 'watching' => 'someone watches Emby', default => 'as asked' });
    }
    jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'from' => $from, 'started' => $started, 'finished' => time(),
                  'exit' => $exit, 'result' => $result, 'status' => jackStatusShort($status)] + $note);
    try {
        jackNotify($tool, $mode, $result, $status, $exit, $note['why'] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, "$tool: notification failed: {$e->getMessage()}\n");
    }
    return $exit;
}

/**
 * A real run that doesn't start because of its gate (jackRunGate()): refused (Unraid's mover at work or someone watches,
 * from the page or the let-go; Emby's answer unusable), skipped (blocked for the gate's whole wait on schedule), or
 * nothing new (another scheduled start already waits; a real run from the page went meanwhile; the array stopped; the
 * schedule switched off). Logged; refused and skipped also in Jack's list of runs, with who watched — the page says it
 * was skipped and why; a refused release (his let-go) is told to Unraid's notifications too.
 */
function jackGateRefused(array $gate, string $tool, string $mode, string $by, int $asked): int
{
    $look = $gate['look'];
    $who = $look['who'] ?? [];
    $name = $tool === 'gather' ? 'gather' : 'EmbyCache';
    switch ($gate['result']) {
        case 'already':
            logLine("Jack Streamer: a scheduled $tool run already waits — nothing new");
            return 0;
        case 'meanwhile':
            logLine("Jack Streamer: the scheduled $tool run stops waiting — a real run was started from the page meanwhile");
            return 0;
        case 'array':
            logLine("Jack Streamer: the scheduled $tool run stops waiting — the array was stopped");
            return 0;
        case 'off':
            logLine("Jack Streamer: the scheduled $tool run stops waiting — its schedule was switched off");
            return 0;
        case 'skipped':
            $what = ($gate['why'] ?? '') === 'jack_mover_running' ? "Unraid's mover was at work" : 'someone watched Emby';
            logLine("Jack Streamer: the scheduled $tool run is skipped — $what for " . intdiv($gate['waited'], 60) . ' min'
                . ($who ? ' (' . jackWatchersLine($who) . ')' : ''));
            fwrite(STDERR, "$tool: skipped ({$gate['why']})\n");
            jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $asked, 'finished' => time(), 'result' => 'skipped',
                          'why' => $gate['why'], 'who' => $who, 'waited' => $gate['waited']]);
            return 0;
    }
    $why = (string) ($gate['why'] ?? 'jack_watching');
    logLine("Jack Streamer: $name ($mode) not started — " . match ($why) {
        'jack_watching'      => 'someone watches Emby: ' . jackWatchersLine($who),
        'jack_mover_running' => "Unraid's mover is at work",
        default              => "Emby's answer: $why " . ($look['detail'] ?? ''),
    });
    fwrite(STDERR, "$tool: not started ($why)\n");
    jackRemember(['tool' => $tool, 'mode' => $mode, 'by' => $by, 'started' => $asked, 'finished' => time(), 'result' => 'refused', 'why' => $why]
        + ($why === 'jack_watching' ? ['who' => $who] : ($why === 'jack_mover_running' ? [] : ['detail' => (string) ($look['detail'] ?? '')]))
        + ($gate['waited'] > 0 ? ['waited' => $gate['waited']] : []));
    if ($mode === 'release') {
        jackReleaseRefusedTell($why);
    }
    return 1;
}

/**
 * How a real run ended, if Unraid should hear of it: failed, aborted, config
 * (EmbyCache didn't accept its settings) or errors (done, with problems).
 * Report and dry runs stay quiet, so do runs that never started (refused)
 * and EmbyCache's "busy" (another EmbyCache was at work). A real run stopped
 * because Unraid's mover started ($why `mover`) is told (`stopped_mover`, normal); one the user asked to stop on his
 * page ($why `user`) is not — he knows — unless it had errors.
 */
function jackNotifyOutcome(string $mode, string $result, array $status, ?string $why = null): ?string
{
    if ($result === 'stopped' && $why === 'mover' && in_array($mode, ['run', 'release'], true)) {
        return 'stopped_mover';          // the mover started: a normal note, no failure
    }
    if ($result === 'stopped' && $why === 'user') {
        // the user asked on his page: he knows (2026-10-10: «das muss nicht kommen») — only its errors are told
        return in_array($mode, ['run', 'release'], true) && (int) ($status['errors'] ?? 0) > 0 ? 'errors' : null;
    }
    if ($mode === 'release') {
        // bringing everything back when he was let go: his page is gone, so a good end is told too (`ok`, normal)
        if (in_array($result, ['failed', 'aborted', 'config', 'busy'], true)) {
            return $result;
        }
        return $result === 'errors' || (int) ($status['errors'] ?? 0) > 0 ? 'errors' : ($result === 'ok' ? 'ok' : 'failed');
    }
    if ($mode !== 'run') {
        return null;
    }
    if (in_array($result, ['failed', 'aborted', 'config'], true)) {
        return $result;
    }
    // a gather stopped because someone started watching Emby is no failure — only its errors are told
    return $result === 'errors' || (in_array($result, ['ok', 'stopped'], true) && (int) ($status['errors'] ?? 0) > 0) ? 'errors' : null;
}

/** A real run that went wrong goes to Unraid's notifications (warning) — from the tool's status file, never its log lines */
function jackNotify(string $tool, string $mode, string $result, array $status, int $exit, ?string $why = null): bool
{
    $outcome = jackNotifyOutcome($mode, $result, $status, $why);
    if ($outcome === null) {
        return false;
    }
    $lang = officeNotifyLang();
    $problems = (int) ($status['errors'] ?? 0)
              + ($tool === 'gather' ? (int) ($status['conflicts'] ?? 0) + (int) ($status['full'] ?? 0) + (int) ($status['dirs_failed'] ?? 0) : 0);
    // MediaCache's files not copied / not brought back, counted per file (2026-10-22: «4 problems» for two files — its
    // log has two ERROR lines for one); its other problems stay counted as before
    $files = $tool === 'embycache' ? jackFailedText(jackFailedFiles($status), $lang) : '';
    $said = $files !== '' ? $files : ($problems > 0 ? officeNotifyText('jack', 'notify.problems', ['n' => $problems], $lang) : '');
    $message = trim((string) ($status['message'] ?? ''));
    $c = (array) ($status['cleanup'] ?? []);
    if (in_array($outcome, ['stopped_mover', 'stopped_user'], true)) {
        $f = (array) ($status['fill'] ?? []);
        $detail = officeNotifyText('jack', "notify.$outcome" . ($tool === 'gather' ? '_gather' : ($mode === 'release' ? '_release' : '')),
            $tool === 'gather' ? ['done' => (int) ($status['folders_done'] ?? 0), 'total' => (int) ($status['folders'] ?? 0)]
                               : ['n' => (int) ($c['done'] ?? 0) + (int) ($f['done'] ?? 0)], $lang)
                . ($said !== '' ? " $said" : '');
    } elseif ($mode === 'release' && $status && in_array($outcome, ['ok', 'errors'], true)) {
        $left = (int) ($status['protected'] ?? 0);
        $detail = officeNotifyText('jack', 'notify.released', ['n' => (int) ($c['done'] ?? 0)], $lang)
                . ($left > 0 ? ' ' . officeNotifyText('jack', 'notify.released_left', ['n' => $left], $lang) : '')
                . ($said !== '' ? " $said" : '');
    } elseif (!$status) {
        $detail = officeNotifyText('jack', 'notify.no_status', ['exit' => $exit], $lang);
    } elseif ($message !== '' && $message !== 'Signal') {
        $detail = $message;
    } else {
        $detail = $said;
    }
    if (!empty($status['incomplete']) && !in_array($outcome, ['stopped_mover', 'stopped_user'], true)) {
        // a server (or plex.tv for Plex's people) didn't answer fully: said plainly — nothing went back, the pool stays protected
        $detail = trim(officeNotifyText('jack', 'incomplete', [], $lang) . ' ' . $detail);
    }
    $release = $mode === 'release';
    $sent = officeNotify(
        officeNotifyText('jack', $release ? 'notify.subject_release' : 'notify.subject', ['tool' => officeNotifyText('jack', "notify.tool.$tool", [], $lang),
                                                     'result' => officeNotifyText('jack', "result.$outcome", [], $lang)], $lang),
        trim($detail . ($release ? '' : ' ' . officeNotifyText('jack', 'notify.see', [], $lang))),
        ($release && $outcome === 'ok') || in_array($outcome, ['stopped_mover', 'stopped_user'], true) ? 'normal' : 'warning', '', $release ? null : officeNotifyLink('#/jack'),
        'jack', ($release ? 'release_' : 'run_') . $outcome);
    if ($sent) {
        logLine("Jack Streamer: told Unraid's notifications — $tool ($mode) $outcome");
    }
    return $sent;
}

/**
 * The files a MediaCache run could not copy to the pool / bring back to the array — its result's `failed` (per file),
 * read only in its shape: {fill, back}; null without it (a run that never got that far, an older status)
 */
function jackFailedFiles(array $status): ?array
{
    $f = $status['failed'] ?? null;
    if (!is_array($f) || !is_int($f['fill'] ?? null) || !is_int($f['back'] ?? null)) {
        return null;
    }
    return ['fill' => max(0, $f['fill']), 'back' => max(0, $f['back']), 'vanished' => is_int($f['vanished'] ?? null) ? max(0, $f['vanished']) : 0];
}

/**
 * «2 files not copied to the pool — they stay on the array.» (and the way back), and why when someone else deleted their
 * half copies (a sync with --delete into the share, 2026-10-22) — '' when none failed
 */
function jackFailedText(?array $failed, string $lang): string
{
    $parts = [];
    foreach (['fill' => 'notify.not_copied', 'back' => 'notify.not_back'] as $k => $key) {
        if (($failed[$k] ?? 0) > 0) {
            $parts[] = officeNotifyText('jack', $key, ['n' => $failed[$k]], $lang);
        }
    }
    if ($parts && ($failed['vanished'] ?? 0) > 0) {
        $parts[] = officeNotifyText('jack', 'failed.vanished', ['n' => $failed['vanished']], $lang);
    }
    return implode(' ', $parts);
}

/**
 * Bringing the films back didn't start (someone watches, a run going…): he was let go, his page is gone — Unraid's
 * notifications say so (warning), with the reason in words.
 */
function jackReleaseRefusedTell(string $why): void
{
    try {
        $lang = officeNotifyLang();
        officeNotify(
            officeNotifyText('jack', 'notify.subject_release', ['tool' => officeNotifyText('jack', 'notify.tool.embycache', [], $lang),
                                                                 'result' => officeNotifyText('jack', 'result.refused', [], $lang)], $lang),
            officeNotifyText('jack', "errors.$why", [], $lang) . ' ' . officeNotifyText('jack', 'notify.release_stay', [], $lang), 'warning', '', null,
            'jack', 'release_refused');
    } catch (Throwable $e) {
        fwrite(STDERR, "embycache: notification failed: {$e->getMessage()}\n");
    }
}

/** A run's status for his list of runs: the sizes stay in sizes.json, the list keeps how many shares were measured */
function jackStatusShort(array $status): array
{
    if (is_array($status['sizes'] ?? null)) {
        $status['measured'] = count($status['sizes']);
    }
    unset($status['sizes'], $status['sizes_at']);
    return $status;
}

/** Jack's own list of runs (both tools, newest first) */
function jackHistory(string $dir = JACK_DATA): array
{
    return (array) (readJson("$dir/office-history.json")['runs'] ?? []);
}

/** What a refused run's row keeps of its reason: every placeholder of his refusals, as text — his page fills the reason from the row */
function jackRefusedParams(array $params): array
{
    $keep = array_intersect_key($params, array_flip(['schedule', 'shares', 'file', 'detail', 'name']));
    return array_map(fn ($v) => mb_substr((string) $v, 0, 300), array_filter($keep, 'is_scalar'));
}

/** A run (or one that didn't start) into his list of runs; $dir: the tests' */
function jackRemember(array $entry, string $dir = JACK_DATA): void
{
    if (($entry['from'] ?? null) === null) {
        unset($entry['from']);                          // only a joint run's record says who started it
    }
    jackDataDir($dir);
    $lock = fopen("$dir/office-history.lock", 'c');
    flock($lock, LOCK_EX);
    $runs = jackHistory($dir);
    $top = $runs[0] ?? null;
    // a scheduled run refused again for the same reason (the mover's rule, every hour): one line, counted — not 40
    if (($entry['result'] ?? '') === 'refused' && ($entry['by'] ?? '') === 'schedule' && is_array($top)
        && array_intersect_key($top, array_flip(['tool', 'mode', 'by', 'result', 'why'])) == array_intersect_key($entry, array_flip(['tool', 'mode', 'by', 'result', 'why']))) {
        $entry += ['first' => (int) ($top['first'] ?? $top['started'] ?? 0), 'times' => (int) ($top['times'] ?? 1) + 1];
        array_shift($runs);
    }
    $runs = array_slice(array_merge([$entry], $runs), 0, JACK_HISTORY);
    writeAtomic("$dir/office-history.json", jsonEncode(['runs' => $runs]), 0600, 0, 0);
    flock($lock, LOCK_UN);
    fclose($lock);
}

/** One of his two tools — anything else is refused, never read as EmbyCache (QA 2026-10-08, finding 14: «../x» answered ok) */
function jackToolKnown(string $tool): void
{
    if (!in_array($tool, ['embycache', 'gather'], true)) {
        throw new Problem('unknown_target', ['target' => mb_substr($tool, 0, 40)]);
    }
}

/** The output of the last run of a tool, as plain text */
function jackOutput(string $tool): array
{
    jackToolKnown($tool);
    $text = (string) @file_get_contents(jackToolDir($tool) . '/office-output.txt', false, null, 0, 2 * 1024 * 1024);
    // his last run was a step of the night with Ms. Moverelli: the whole night's output — every step, not only his last
    $night = DATA_DIR . '/moverelli/night-output.txt';
    if ($tool === 'embycache' && (jackJobInfo($tool)['by'] ?? '') === 'night' && is_file($night) && !is_link($night)) {
        $text = (string) @file_get_contents($night, false, null, 0, 2 * 1024 * 1024);
    }
    return ['ok' => true, 'info' => jackJobInfo($tool), 'text' => jackPlainOutput($text)];
}

/** Terminal output as text: progress lines (\r) collapsed, colour/erase codes and the log prefix gone */
function jackPlainOutput(string $text): string
{
    $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
    $lines = [];
    foreach (explode("\n", $text) as $line) {
        $parts = explode("\r", $line);
        $last = '';
        foreach (array_reverse($parts) as $p) {
            if (trim($p) !== '') {
                $last = $p;
                break;
            }
        }
        if (count($parts) > 1 && $last !== '' && preg_match('/^\s*\[\d+\/\d+\] \d+%/', $last)) {
            continue;                                  // a progress line that was overwritten
        }
        $lines[] = $last;
    }
    return preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d,\d+ \| (?:INFO|DEBUG) \| /m', '', implode("\n", $lines));
}

function jackLog(string $tool): array
{
    jackToolKnown($tool);
    $file = $tool === 'gather' ? GATHER_DATA . '/consolidate.log' : JACK_DATA . '/logs/mediacache.log';
    $size = (int) @filesize($file);
    $h = @fopen($file, 'r');
    if (!$h) {
        return ['ok' => true, 'text' => ''];
    }
    fseek($h, max(0, $size - 512 * 1024));
    $text = (string) stream_get_contents($h);
    fclose($h);
    return ['ok' => true, 'text' => $text, 'cut' => $size > 512 * 1024];
}

// ===================================================================== schedules

/** EmbyCache's and the gather's schedule: a line in the office's cron file */
function jackSetSchedule(string $job, ?string $cron): array
{
    if (!in_array($job, ['embycache', 'gather'], true)) {
        throw new Problem('unknown_target', ['target' => $job]);
    }
    // EmbyCache's schedule is the night run's (2026-10-13): one time for Ms. Moverelli and him — her line
    if ($job === 'embycache' && function_exists('moverelliSetSchedule') && moverelliHired()) {
        if ($cron !== null && !jackConfigured(jackReadSettings())) {
            throw jackNotConfigured();
        }
        $r = moverelliSetSchedule($cron);
        if ($cron === null && officeJobSchedule('embycache')['enabled']) {
            officeJobSetSchedule('embycache', null);
        }
        logLine('Jack Streamer: the night run with Ms. Moverelli ' . ($cron !== null ? "set to $cron" : 'switched off'));
        return ['ok' => true, 'live' => $r['live'], 'state' => jackScan()];
    }
    if ($cron !== null) {
        if ($job === 'embycache' && !jackConfigured(jackReadSettings())) {
            throw jackNotConfigured();
        }
        if ($job === 'gather' && !(jackGatherSettings()['shares'] ?? [])) {
            throw new Problem('jack_gather_not_configured');
        }
    }
    $live = officeJobSetSchedule($job, $cron);
    logLine("Jack Streamer: $job schedule " . ($cron !== null ? "set to $cron" : 'switched off') . ($live ? '' : ' (not in the crontab yet)'));
    if ($cron !== null) {
        jackLetGoSeen();        // switched on again: his page needn't say any more that the let-go switched it off
    }
    return ['ok' => true, 'live' => $live, 'state' => jackScan()];
}

/**
 * Other things that start EmbyCache or the gather on their own: the user's
 * User Scripts entries and cron lines. They would run beside Jack's schedule,
 * outside his locks. Only what really runs counts (inbox #9, 1.51.0: a line the
 * user had commented out kept the warning up): a script's or a cron file's
 * comment lines and `: <<'EOF'` blocks are no call (jackActiveText()), a User
 * Script whose schedule is off (none, «disabled», «custom» without a line) is
 * listed but not `enabled`. Read anew on every look at his state (jackScan():
 * his tour, every refresh, after each action) and by the caretaker's check.
 *
 * $usDir, $cronGlob, $officeCron: the places (the tests).
 */
function jackForeignSchedules(string $usDir = US_DIR, string $cronGlob = '/boot/config/plugins/*/*.cron', string $officeCron = OFFICE_CRON): array
{
    return officeForeignCalls(['embycache' => '/mediacache_run\.py/', 'gather' => '/consolidate_master\.sh/'], $usDir, $cronGlob, [$officeCron]);
}

/**
 * User Scripts entries and other plugins' cron lines that call one of $tools (tool => regex over what runs): Jack's
 * look for EmbyCache and the gather started elsewhere, Ms. Moverelli's for other movers. A script names the first tool
 * of $tools its active text matches, a cron line the tool matched first in it; cron lines that run a User Script are
 * left to the script's entry, the files in $skip (the office's own cron file, …) are not read.
 *
 * @return list<array{tool: string, where: string, enabled: bool}>
 */
function officeForeignCalls(array $tools, string $usDir = US_DIR, string $cronGlob = '/boot/config/plugins/*/*.cron', array $skip = [OFFICE_CRON]): array
{
    $found = [];
    // User Scripts keeps its schedules by the script's path; looked up by its folder like the watchman does
    $plans = [];
    foreach ((array) json_decode((string) @file_get_contents("$usDir/schedule.json", false, null, 0, 1 << 20), true) as $key => $plan) {
        if (is_string($key) && is_array($plan)) {
            $plans[basename(dirname($key))] = $plan;
        }
    }
    foreach (glob("$usDir/scripts/*/script") ?: [] as $file) {
        $name = basename(dirname($file));
        $text = jackActiveText((string) @file_get_contents($file, false, null, 0, 65536));
        $tool = null;
        foreach ($tools as $t => $re) {
            if (preg_match($re, $text)) {
                $tool = $t;
                break;
            }
        }
        if ($tool) {
            $plan = $plans[$name] ?? [];
            $freq = (string) ($plan['frequency'] ?? 'disabled');
            $on = !in_array($freq, ['', 'disabled'], true) && ($freq !== 'custom' || trim((string) ($plan['custom'] ?? '')) !== '');
            $found[] = ['tool' => $tool, 'where' => "User Scripts: $name", 'enabled' => $on];
        }
    }
    foreach (glob($cronGlob) ?: [] as $file) {
        if (in_array($file, $skip, true)) {
            continue;
        }
        foreach (explode("\n", jackActiveText((string) @file_get_contents($file, false, null, 0, 1 << 20))) as $line) {
            if (str_contains($line, $usDir)) {
                continue;
            }
            $tool = null;
            $at = PHP_INT_MAX;
            foreach ($tools as $t => $re) {
                if (preg_match($re, $line, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $at) {
                    [$tool, $at] = [$t, $m[0][1]];
                }
            }
            if ($tool !== null) {
                $found[] = ['tool' => $tool, 'where' => $file, 'enabled' => true];
            }
        }
    }
    return $found;
}

/**
 * A shell script's or a cron file's lines that may run something: comment lines (`#` after optional blanks) and the
 * blocks people comment out with `: <<'EOF'` … `EOF` (any word, quoted or not, also `<<-`) left out. A `#` later in
 * a line is left alone (it may lie in quotes).
 */
function jackActiveText(string $text): string
{
    $out = [];
    $until = null;
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if ($until !== null) {
            if (trim($line) === $until) {
                $until = null;
            }
            continue;
        }
        if (preg_match('/^\s*:\s*<<-?\s*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\1\s*$/D', $line, $m)) {
            $until = $m[2];
            continue;
        }
        if (preg_match('/^\s*(#|$)/', $line)) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

// ===================================================================== checks (for the caretaker)

/**
 * The Team Lead's point about the mover: `mover` (Unraid's own schedule must be «Disabled» — Ms. Moverelli switches it
 * off with her setup), `mover_moverelli_setup` (she isn't set up yet), `mover_moverelli` (her ini doesn't keep his list
 * out for these shares), `mover_tuning_schedule` (Mover Tuning moves on a schedule of its own). Required: without it no
 * real run goes. Links his page — hers for what she must do.
 */
function jackMoverFinding(array $rule): array
{
    $id = match ($rule['why']) {
        'moverelli'       => 'mover_moverelli_setup',
        'moverelli_list'  => 'mover_moverelli',
        'tuning_schedule' => 'mover_tuning_schedule',
        default           => 'mover',
    };
    return finding($id, 'required', $rule['ok'], array_filter([
        'shares'   => $id === 'mover_moverelli' ? implode(', ', $rule['moverelli']['uncovered'] ?? []) : null,
        'schedule' => $id === 'mover_tuning_schedule' ? (string) ($rule['tuning']['cron'] ?? '') : null,
        'name'     => $id === 'mover_tuning_schedule' ? jackMoverName($rule['tuning'] ?? null) : null,
    ], fn ($v) => $v !== null), in_array($id, ['mover_moverelli_setup', 'mover_moverelli'], true) ? '#/moverelli/setup' : '#/jack');
}

function jackChecks(): array
{
    $out = [];
    $emby = jackContainers();
    $out[] = finding('jack_container', 'recommended', (bool) $emby, [], 'docker');
    if (!$emby) {
        return $out;                       // without Emby nothing else matters for Jack
    }
    $out[] = finding('python', 'required', jackPython() !== null, [], 'apps');
    $settings = jackReadSettings();
    $out[] = finding('configured', 'required', jackConfigured($settings), [], '#/jack/setup');
    if (!$settings) {
        return $out;
    }
    $shares = jackShares($settings);
    $missing = array_column(array_filter($shares, fn ($s) => $s['root'] === false && !$s['auto']), 'share');
    $unseen = array_filter($shares, fn ($s) => $s['root'] === null);
    $out[] = finding('share_root', 'required', $missing ? false : ($unseen ? null : true), ['shares' => implode(', ', $missing), 'pool' => (string) ($settings['cache_path'] ?? '')], '#/jack');
    $pool = rtrim((string) ($settings['cache_path'] ?? ''), '/');
    $sizes = jackGatherSizes(readJson(GATHER_DATA . '/sizes.json'), readJson(GATHER_DATA . '/status.json'));
    $mounts = mountTable();
    $plain = [];
    foreach ($shares as $s) {
        if ($s['dataset'] === false) {
            $plain[] = "$pool/$s[share]";
        }
        foreach (jackPlainDisks((string) $s['share'], $sizes, $mounts) as $disk) {
            $plain[] = "/mnt/$disk/$s[share]";
        }
    }
    $out[] = finding('share_dataset', 'recommended', !$plain, ['where' => implode(', ', $plain)], '#/jack');
    $unfit = array_column(array_filter($shares, fn ($s) => !in_array($s['fit'], ['ok', 'array_only'], true)), 'share');
    $out[] = finding('share_fit', 'recommended', !$unfit, ['shares' => implode(', ', $unfit)], '#/jack');
    // EmbyCache runs in the night run with Ms. Moverelli (her line) — or, without her, on a line of its own
    $out[] = finding('schedule', 'recommended', officeJobSchedule('moverelli')['enabled'] || officeJobSchedule('embycache')['enabled'], [], '#/jack/schedule');
    // his list (H1, 2026-10-21): gone after his runs and not written anew yet (his shares stay where they are, his runs
    // wait); written anew from the pool — to know, his next complete run sets it right
    $list = JACK_DATA . '/mediacache_exclude.txt';
    $out[] = finding('list_lost', 'recommended', is_file($list) || !jackListRan(), ['file' => $list], '#/jack');
    $out[] = finding('list_rebuilt', 'hint', !is_array(readJson(JACK_DATA . '/' . JACK_LIST_NOTE)['rebuilt'] ?? null), ['file' => $list], '#/jack');
    // the gather: optional — a calm hint, never a point that stays open (the owner, 2026-10-16)
    $out[] = finding('gather_optional', 'hint', true, [], '#/jack');
    $foreign = array_filter(jackForeignSchedules(), fn ($f) => $f['enabled']);
    $out[] = finding('foreign', 'recommended', !$foreign, ['where' => implode(', ', array_column($foreign, 'where'))], 'userscripts');
    // Unraid's mover schedule «Disabled» (and Mover Tuning, if any, keeping his list), else only dry runs go (jack-mover.php): one point
    $out[] = jackMoverFinding(jackMoverRule());
    return $out;
}
