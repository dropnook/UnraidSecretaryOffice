<?php
declare(strict_types=1);

/*
 * Jack Streamer: «Check the structure…» (2026-11-07). His tools work with a clean structure: a film in a folder of its own
 * under the library (`movie_mode: folder` takes the whole folder), a series as `<library>/<series>/<season>/<episode>`
 * (the gather goes by the folder). A film lying directly in a library's root, a folder holding two films, a series flat in
 * the root: those go wrong quietly (a whole root taken for «the film's folder», a series never consolidated). The owner
 * (2026-10-17): only a hint — the office never moves or renames anything; the files stay where they are.
 *
 * On the user's click only: the action `structure_start` asks, his tick starts ONE job (`php agent.php job jack-structure`,
 * a process of its own: the API paging can take a while, never inside the tick) and takes up its end. No schedule, nothing
 * in the joint run. Only the media servers' API (paths are the server's word): never a stat on /mnt — a sleeping disk stays
 * asleep. The rules (jackStructureJudge()):
 *
 *   flat_movie     a film whose file lies directly in a library location
 *   shared_folder  a folder directly under a location holding more than one film (ONE finding per folder)
 *   flat_series    a series whose folder is a location itself, or whose episodes lie directly in it
 *   unmapped       a path no mapping covers: the office can't tell where it is — counted, not judged
 *
 * What the servers say about a flat series (Tower 2026-11-07, Jellyfin, Emby and Plex side by side): Jellyfin and Emby make
 * NO Series item of episodes lying in the root — only Episode items without a SeriesId; Plex makes a show, but neither its
 * section's list nor /library/metadata/<id> names a Location for it (a show in a folder of its own has one there, its section's
 * list never). So the episodes are read too (jackStructureItems()), their paths only.
 *
 * The result: JACK_DATA/structure.json {v, time, servers: [{name, films, series, items, unmapped, found, findings:
 * [{kind, title, path}], error?}], films, series, items, unmapped, found} — at most JACK_STRUCTURE_MAX findings kept, `found`
 * says how many there were. Paths and titles only, never a key. The page has the counts from his state (`structure`), the
 * list from the part «structure» (desk.json).
 */

const JACK_STRUCTURE_V       = 1;
const JACK_STRUCTURE_FILE    = 'structure.json';
const JACK_STRUCTURE_MAX     = 500;        // findings kept over all servers
const JACK_STRUCTURE_PAGE    = 500;        // items per request: jackApi() gives one request 15 s
const JACK_STRUCTURE_SECONDS = 900;        // one server's whole list; a server that never ends its pages is no answer
const JACK_STRUCTURE_JOB     = 'jack-structure';
const JACK_STRUCTURE_KINDS   = ['flat_movie', 'shared_folder', 'flat_series'];
const JACK_STRUCTURE_RUN     = 'jack-structure-run.json';   // in RAM: the job at work {pid, since}, or the last one that left no result {failed}
const JACK_STRUCTURE_DISC    = ['Dvd', 'BluRay', 'HdDvd'];  // the server's VideoType: `Path` names the rip's FOLDER, not a file

$GLOBALS['jackStructureRun'] = null;        // null: nothing; ['asked' => time]; ['pid', 'since', 'process'] (null after a restart of the agent)

// ===================================================================== the rules

/** Where a server's path lies under its mappings (MediaCache's Locations.to_host(): longest match, at a path's border only): the host's path, '' = deliberately left out, null = none covers it */
function jackStructureMapped(string $path, array $maps): ?string
{
    $best = null;
    foreach ($maps as $from => $to) {
        $from = (string) $from;
        if (($path === $from || str_starts_with($path, "$from/")) && ($best === null || strlen($from) > strlen($best))) {
            $best = $from;
        }
    }
    if ($best === null) {
        return null;
    }
    $to = (string) $maps[$best];
    return $to === '' ? '' : $to . substr($path, strlen($best));
}

/** The longest location a path lies in (or is): null when none */
function jackStructureLocation(string $path, array $locations): ?string
{
    $best = null;
    foreach ($locations as $l) {
        if (($path === $l || str_starts_with($path, "$l/")) && ($best === null || strlen($l) > strlen($best))) {
            $best = $l;
        }
    }
    return $best;
}

/**
 * The rules, on the server's word alone. $libs [{locations, chosen}]; $maps the server's path_mappings; $items [{type: Movie|Series,
 * title, path, disc?}]. A path in a library the setup didn't choose, or under a mapping left out on purpose ('' — his
 * tools never touch it): not looked at. No path, no mapping, or in no library the server names: `unmapped`, counted only.
 * Everything else is fine. Findings in the order of JACK_STRUCTURE_KINDS, by title.
 *
 * @return array{films: int, series: int, items: int, unmapped: int, found: int, findings: list<array{kind: string, title: string, path: string}>}
 */
function jackStructureJudge(array $items, array $libs, array $maps): array
{
    $chosen = $other = [];
    foreach ($libs as $lib) {
        foreach ((array) ($lib['locations'] ?? []) as $l) {
            if (!empty($lib['chosen'])) {
                $chosen[] = rtrim((string) $l, '/');
            } else {
                $other[] = rtrim((string) $l, '/');
            }
        }
    }
    $films = $series = $unmapped = 0;
    $flatFilms = $flatSeries = $folders = [];
    foreach ($items as $item) {
        $path = rtrim((string) ($item['path'] ?? ''), '/');
        $title = jackWatchText((string) ($item['title'] ?? ''));
        $location = $path === '' ? null : jackStructureLocation($path, $chosen);
        if ($path !== '' && $location === null && jackStructureLocation($path, $other) !== null) {
            continue;                      // a library the setup didn't choose
        }
        $mapped = $path === '' ? null : jackStructureMapped($path, $maps);
        if ($mapped === '') {
            continue;                      // this folder deliberately not cached
        }
        if ($mapped === null || $location === null) {
            $unmapped++;
            continue;
        }
        $rel = $path === $location ? '' : substr($path, strlen($location) + 1);
        $depth = $rel === '' ? 0 : substr_count($rel, '/') + 1;
        if (($item['type'] ?? '') === 'Series') {
            $series++;
            if ($rel === '') {
                $flatSeries["$title\0$mapped"] = ['kind' => 'flat_series', 'title' => $title, 'path' => $mapped];
            }
            continue;
        }
        $films++;
        if (!empty($item['disc'])) {
            continue;                      // a rip's folder: the film has its folder
        }
        if ($depth === 1) {
            $flatFilms[] = ['kind' => 'flat_movie', 'title' => $title, 'path' => $mapped];
        } elseif ($depth === 2) {
            $folders[dirname($mapped)][] = $title;
        }
    }
    $shared = [];
    foreach ($folders as $folder => $titles) {
        if (count($titles) > 1) {
            sort($titles, SORT_NATURAL | SORT_FLAG_CASE);
            $shared[] = ['kind' => 'shared_folder', 'title' => jackWatchText(implode(' · ', $titles), 240), 'path' => (string) $folder];
        }
    }
    $findings = [];
    foreach ([$flatFilms, $shared, array_values($flatSeries)] as $list) {
        usort($list, fn (array $a, array $b): int => strnatcasecmp($a['title'], $b['title']) ?: strcmp($a['path'], $b['path']));
        $findings = array_merge($findings, $list);
    }
    return ['films' => $films, 'series' => $series, 'items' => $films + $series, 'unmapped' => $unmapped, 'found' => count($findings), 'findings' => $findings];
}

/** The server's films and series as the rules take them: Emby/Jellyfin's items; one with no file (`Virtual`) is no item */
function jackStructureEmbyItems(array $page): array
{
    $out = [];
    foreach ($page as $i) {
        if (is_array($i) && in_array($i['Type'] ?? null, ['Movie', 'Series'], true) && ($i['LocationType'] ?? '') !== 'Virtual') {
            $out[] = ['type' => $i['Type'], 'title' => (string) ($i['Name'] ?? ''), 'path' => (string) ($i['Path'] ?? ''),
                      'disc' => in_array($i['VideoType'] ?? null, JACK_STRUCTURE_DISC, true)];
        }
    }
    return $out;
}

/**
 * Emby/Jellyfin's episodes lying directly in a location: the servers make no Series of them, so each becomes one as the
 * rules take it — by its series' name, else its name up to «S01E02», one per name and location.
 *
 * @param array<string, array{type: string, title: string, path: string}> $flat kept across the pages
 */
function jackStructureFlatEpisodes(array $page, array $locations, array $flat = []): array
{
    foreach ($page as $i) {
        $path = is_array($i) ? rtrim((string) ($i['Path'] ?? ''), '/') : '';
        if ($path === '' || ($i['Type'] ?? '') !== 'Episode' || !in_array(dirname($path), $locations, true)) {
            continue;
        }
        $name = (string) ($i['SeriesName'] ?? '');
        if ($name === '') {
            $file = (string) preg_replace('/\.[^.\/]+$/', '', basename($path));
            $name = trim((string) preg_replace('/[\s._-]*[Ss]\d{1,4}[\s._-]*[Ee]\d{1,4}.*$/s', '', $file), " ._-") ?: $file;
        }
        $flat[dirname($path) . "\0" . $name] = ['type' => 'Series', 'title' => $name, 'path' => dirname($path)];
    }
    return $flat;
}

/** A Plex film's file: its first version's first part — no `file`: '' (the rules count it unmapped) */
function jackStructurePlexFile(array $m): string
{
    return (string) ($m['Media'][0]['Part'][0]['file'] ?? '');
}

/**
 * Plex's shows from the section's list and its episodes (`type=4`): a show's folder as far as Plex says it (Location, not in
 * the list on the servers seen), else the shallowest folder any of its episodes lies in — a location itself for a flat one.
 *
 * @param array<string, string> $dirs ratingKey => folder, from jackStructurePlexEpisodes()
 */
function jackStructurePlexShows(array $shows, array $dirs): array
{
    $out = [];
    foreach ($shows as $m) {
        if (!is_array($m)) {
            continue;
        }
        $path = (string) ($m['Location'][0]['path'] ?? '');
        $out[] = ['type' => 'Series', 'title' => (string) ($m['title'] ?? ''), 'path' => $path !== '' ? $path : ($dirs[(string) ($m['ratingKey'] ?? '')] ?? '')];
    }
    return $out;
}

/** @param array<string, string> $dirs kept across the pages: a show's shallowest folder so far */
function jackStructurePlexEpisodes(array $page, array $dirs = []): array
{
    foreach ($page as $e) {
        $file = is_array($e) ? jackStructurePlexFile($e) : '';
        $key = is_array($e) ? (string) ($e['grandparentRatingKey'] ?? '') : '';
        if ($file === '' || $key === '') {
            continue;
        }
        $dir = dirname($file);
        if (!isset($dirs[$key]) || substr_count($dir, '/') < substr_count($dirs[$key], '/')) {
            $dirs[$key] = $dir;
        }
    }
    return $dirs;
}

// ===================================================================== asking the servers (the job)

/** Pages until one comes back short: $get(start, count) gives one page; the deadline on the monotonic clock. @return list<array<mixed>> */
function jackStructurePages(callable $get, int $deadline): array
{
    $out = [];
    for ($start = 0; ; $start += JACK_STRUCTURE_PAGE) {
        if (hrtime(true) > $deadline) {
            throw new Problem('jack_structure_slow');
        }
        $page = array_values($get($start, JACK_STRUCTURE_PAGE));
        array_push($out, ...$page);
        if (count($page) < JACK_STRUCTURE_PAGE) {
            return $out;
        }
    }
}

/**
 * One server's libraries and items, the rules' input. $api(path): one GET, jackApi() with the server's address and key —
 * Problem on no answer. Plex's sections the setup didn't choose are never read.
 *
 * @return array{libs: list<array{locations: list<string>, chosen: bool}>, items: list<array<string, mixed>>}
 */
function jackStructureItems(string $type, callable $api, array $chosen): array
{
    $deadline = hrtime(true) + JACK_STRUCTURE_SECONDS * 1000000000;
    $isChosen = fn (string $name): bool => !$chosen || in_array($name, $chosen, true);
    $libs = $items = [];
    if ($type === 'plex') {
        foreach ((array) ($api('/library/sections')['MediaContainer']['Directory'] ?? []) as $d) {
            $kind = is_array($d) ? (string) ($d['type'] ?? '') : '';
            $key = is_array($d) ? (string) ($d['key'] ?? '') : '';
            if (!in_array($kind, ['movie', 'show'], true) || !preg_match('/^\d{1,12}$/D', $key)) {
                continue;
            }
            $locations = array_values(array_filter(array_map(fn ($l) => is_array($l) ? (string) ($l['path'] ?? '') : '', (array) ($d['Location'] ?? [])), fn ($l) => $l !== '' && !preg_match(JACK_IGNORED, $l)));
            $on = $isChosen((string) ($d['title'] ?? ''));
            $libs[] = ['locations' => $locations, 'chosen' => $on];
            if (!$on) {
                continue;
            }
            $list = fn (string $query) => jackStructurePages(fn (int $start, int $n) => (array) ($api("/library/sections/$key/all?{$query}X-Plex-Container-Start=$start&X-Plex-Container-Size=$n")['MediaContainer']['Metadata'] ?? []), $deadline);
            if ($kind === 'movie') {
                foreach ($list('') as $m) {
                    if (is_array($m)) {
                        $items[] = ['type' => 'Movie', 'title' => (string) ($m['title'] ?? ''), 'path' => jackStructurePlexFile($m)];
                    }
                }
            } else {
                $shows = $list('');
                array_push($items, ...jackStructurePlexShows($shows, $shows ? jackStructurePlexEpisodes($list('type=4&')) : []));
            }
        }
        return ['libs' => $libs, 'items' => $items];
    }
    $locations = [];
    foreach ((array) $api('/Library/VirtualFolders') as $lib) {
        if (is_array($lib)) {
            $locs = array_values(array_map(fn ($l) => rtrim((string) $l, '/'), array_filter((array) ($lib['Locations'] ?? []), fn ($l) => !preg_match(JACK_IGNORED, (string) $l))));
            $on = $isChosen((string) ($lib['Name'] ?? ''));
            $libs[] = ['locations' => $locs, 'chosen' => $on];
            if ($on) {
                array_push($locations, ...$locs);
            }
        }
    }
    $list = fn (string $types) => jackStructurePages(fn (int $start, int $n) => (array) ($api("/Items?Recursive=true&IncludeItemTypes=$types&Fields=Path&EnableImages=false&EnableUserData=false&EnableTotalRecordCount=false&StartIndex=$start&Limit=$n")['Items'] ?? []), $deadline);
    $items = jackStructureEmbyItems($list('Movie,Series'));
    array_push($items, ...array_values(jackStructureFlatEpisodes($list('Episode'), $locations)));
    return ['libs' => $libs, 'items' => $items];
}

/**
 * The result file's content: at most $cap findings kept over all servers, every count as it was; a server that gave no
 * answer stands with its `error` (never «everything in its place»).
 *
 * @param list<array<string, mixed>> $servers
 */
function jackStructureResult(array $servers, int $time, int $cap = JACK_STRUCTURE_MAX): array
{
    $out = ['v' => JACK_STRUCTURE_V, 'time' => $time, 'servers' => [], 'films' => 0, 'series' => 0, 'items' => 0, 'unmapped' => 0, 'found' => 0];
    foreach ($servers as $s) {
        foreach (['films', 'series', 'items', 'unmapped', 'found'] as $k) {
            $out[$k] += (int) ($s[$k] ?? 0);
        }
        $s['findings'] = array_slice((array) ($s['findings'] ?? []), 0, max(0, $cap));
        $cap -= count($s['findings']);
        $out['servers'][] = $s;
    }
    return $out;
}

/** A server's mappings as MediaCache merges them: the settings' own under the server's, without a trailing slash */
function jackStructureMaps(array $settings, array $instance): array
{
    $maps = [];
    foreach (array_merge((array) ($settings['path_mappings'] ?? []), (array) ($instance['path_mappings'] ?? [])) as $from => $to) {
        if (is_string($to)) {
            $maps[rtrim((string) $from, '/')] = rtrim($to, '/');
        }
    }
    return $maps;
}

/** php agent.php job jack-structure: every server of his setup asked, the result written whatever they answered. $api: the tests' stand-in */
function jackStructureJob(?callable $api = null, string $dir = JACK_DATA): int
{
    $settings = jackReadSettings($dir) ?? [];
    $chosen = array_values(array_filter((array) ($settings['libraries'] ?? []), 'is_string'));
    $servers = [];
    foreach ((array) ($settings['instances'] ?? []) as $i) {
        if (!is_array($i)) {
            continue;
        }
        $type = jackType($i['type'] ?? null);
        $name = jackWatchText((string) ($i['servername'] ?? ''), 60) ?: JACK_SERVER_NAMES[$type];
        $url = rtrim((string) ($i['url'] ?? ''), '/');
        $ask = $api !== null ? fn (string $path) => $api($url, $path) : fn (string $path) => jackApi($url, (string) ($i['api_key'] ?? ''), $path, $type);
        try {
            $got = jackStructureItems($type, $ask, $chosen);
            $servers[] = ['name' => $name] + jackStructureJudge($got['items'], $got['libs'], jackStructureMaps($settings, $i));
        } catch (Problem $p) {
            $servers[] = ['name' => $name, 'films' => 0, 'series' => 0, 'items' => 0, 'unmapped' => 0, 'found' => 0, 'findings' => [],
                          'error' => ['key' => $p->key, 'params' => array_intersect_key($p->params, ['url' => 1, 'detail' => 1])]];
            logLine("Jack Streamer: the structure check got no answer from $name ({$p->key})");
        }
    }
    $result = jackStructureResult($servers, time());
    writeAtomic("$dir/" . JACK_STRUCTURE_FILE, jsonEncode($result));
    logLine("Jack Streamer: structure checked - {$result['items']} items, {$result['found']} not in the expected pattern, {$result['unmapped']} without a mapping");
    return 0;
}

// ===================================================================== the agent's side

function jackStructureRunFile(): string
{
    return RUN_DIR . '/' . JACK_STRUCTURE_RUN;
}

/** The result as written, only in its shape: null while never checked */
function jackStructureRead(string $dir = JACK_DATA): ?array
{
    $r = readJson("$dir/" . JACK_STRUCTURE_FILE);
    return is_array($r) && ($r['v'] ?? null) === JACK_STRUCTURE_V && is_int($r['time'] ?? null) && is_array($r['servers'] ?? null) ? $r : null;
}

/**
 * For his state: {time, films, series, items, unmapped, found, servers_failed, running, failed} — `time` null while none
 * was ever finished; failed: the last job left no result (the older result stays what it is). Null: never asked.
 */
function jackStructureSummary(string $dir = JACK_DATA): ?array
{
    $r = jackStructureRead($dir);
    $run = readJson(jackStructureRunFile());
    $going = $GLOBALS['jackStructureRun'] !== null;
    $failed = !$going && is_int($run['failed'] ?? null) && $run['failed'] >= (int) ($r['time'] ?? 0);
    if ($r === null && !$going && !$failed) {
        return null;
    }
    $n = fn (string $k): int => is_int($r[$k] ?? null) ? $r[$k] : 0;
    return ['time' => $r['time'] ?? null, 'films' => $n('films'), 'series' => $n('series'), 'items' => $n('items'), 'unmapped' => $n('unmapped'), 'found' => $n('found'),
            'servers_failed' => $r === null ? 0 : count(array_filter($r['servers'], fn ($s) => is_array($s) && isset($s['error']))),
            'running' => $going, 'failed' => $failed];
}

/** Action structure_start, on the user's click: one job at a time; his tick starts it. Asking again while one goes is no error — it is what the page already shows */
function jackStructureAsk(): array
{
    if (!array_filter((array) (jackReadSettings()['instances'] ?? []), 'is_array')) {
        throw new Problem('jack_no_server');
    }
    if ($GLOBALS['jackStructureRun'] === null) {
        $GLOBALS['jackStructureRun'] = ['asked' => time()];
        @unlink(jackStructureRunFile());
    }
    return ['ok' => true, 'state' => jackScan()];
}

/** The part «structure» (desk.json): what the last check found, as the agent reads it now */
function jackStructureList(string $dir = JACK_DATA): array
{
    return ['ok' => true, 'state' => ['time' => time(), 'result' => jackStructureRead($dir)]];
}

/** His start: a job still at work from before a restart of the agent is taken up by its pid and its name; none other starts by itself */
function jackStructureStart(): void
{
    $run = readJson(jackStructureRunFile());
    if (is_int($run['pid'] ?? null)) {
        if (jackStructurePidWorks($run['pid'])) {
            $GLOBALS['jackStructureRun'] = ['pid' => $run['pid'], 'since' => (int) ($run['since'] ?? 0), 'process' => null];
        } else {
            jackStructureEnded((int) ($run['since'] ?? 0));
        }
    }
}

function jackStructurePidWorks(int $pid): bool
{
    return str_contains((string) @file_get_contents("/proc/$pid/cmdline"), JACK_STRUCTURE_JOB);
}

/** @return list<string> */
function jackStructureCommand(): array
{
    return $GLOBALS['jackStructureCommand'] ?? ['nice', '-n', '10', PHP_BINARY, OFFICE_DIR . '/agent/agent.php', 'job', JACK_STRUCTURE_JOB];
}

/** His tick: nothing but a variable read unless a check was asked for; the job started, its end taken up. $file: his state (tests) */
function jackStructureTick(?string $file = null): void
{
    $run = $GLOBALS['jackStructureRun'];
    if ($run === null) {
        return;
    }
    if (isset($run['asked'])) {
        $pipes = [];
        $process = proc_open(jackStructureCommand(), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
        if (!is_resource($process)) {
            $GLOBALS['jackStructureRun'] = null;
            jackStructureEnded(time(), $file);
            return;
        }
        $GLOBALS['jackStructureRun'] = ['pid' => (int) proc_get_status($process)['pid'], 'since' => time(), 'process' => $process];
        writeAtomic(jackStructureRunFile(), jsonEncode(['pid' => $GLOBALS['jackStructureRun']['pid'], 'since' => $GLOBALS['jackStructureRun']['since']]), 0600, 0, 0);
        return;
    }
    if ($run['process'] !== null ? proc_get_status($run['process'])['running'] : jackStructurePidWorks($run['pid'])) {
        return;
    }
    if ($run['process'] !== null) {
        proc_close($run['process']);
    }
    $GLOBALS['jackStructureRun'] = null;
    jackStructureEnded($run['since'], $file);
}

/** A job over: without a result newer than its start it failed (noted in RAM, the page says so); his saved state takes the new counts — no whole look, the rest is as old as it was */
function jackStructureEnded(int $since, ?string $file = null): void
{
    $r = jackStructureRead();
    if ((int) ($r['time'] ?? 0) < $since) {
        writeAtomic(jackStructureRunFile(), jsonEncode(['failed' => time()]), 0600, 0, 0);
        logLine('Jack Streamer: the structure check ended without a result');
    } else {
        @unlink(jackStructureRunFile());
    }
    $file ??= deskFile('jack');
    $state = readJson($file);
    if (is_array($state)) {
        $state['structure'] = jackStructureSummary();
        writeAtomic($file, jsonEncode($state));
    }
}

/** The Team Lead's hint: only after a check that found something — never a must, absent while never checked or clean */
function jackStructureFinding(?array $summary): ?array
{
    $n = (int) ($summary['found'] ?? 0);
    return $n > 0 ? finding('structure', 'hint', false, ['n' => $n], '#/jack/shares') : null;
}
