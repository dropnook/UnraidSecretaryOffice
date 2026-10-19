<?php
declare(strict_types=1);

/*
 * Ms. Protocolli's saved filters (2026-10-19, as Enhanced Log's «Saved states») — functions only, in a file of their
 * own beside her desk: the agent's desk glob loads it before logs.php, which require_once's it; nothing here uses
 * logs.php's constants at its top level.
 *
 * What a list's filter bar has set — the words, a Schildchen (a category of «Syslog», a log of «Errors & warnings»),
 * «only errors & warnings» (the reader), a service (a syslog line's program, chosen by a click on it), the time (a preset that stays relative: the last hour, the last 24 hours,
 * today, since the last start; or a range «from … to …», either end open) — kept under a name the user typed, per
 * list, in data/logs-filters.json: on the server, so every browser and the phone see the same (never Office.store).
 * **Never applied by itself** (the office's rule: a page never opens with a hidden filter) — only by a click on its
 * button. `open` (apply when opening) is read and kept but never set here: a later «apply when opening» would be that
 * one flag and the page's APPLY_ON_OPEN.
 *
 * Tolerant (CLAUDE.md «Updates»): the reader takes only entries in exactly their shape, the writer keeps every entry and
 * key it doesn't know as it was.
 */
const LOGS_FILTER_LISTS   = ['syslog', 'found', 'varlog', 'docker', 'read'];
const LOGS_FILTER_MAX     = 20;            // saved filters per list at most
const LOGS_FILTER_NAME    = 60;            // characters of a name at most
const LOGS_FILTER_WORDS   = 300;           // bytes of the filter words at most
const LOGS_FILTER_PRESETS = ['hour', 'day', 'today', 'boot'];
const LOGS_FILTER_CATS    = ['error', 'warning', 'drive', 'fs', 'network', 'minor', 'array', 'login', 'system', 'limetech', 'emhttp'];
// a log's Schildchen in «Errors & warnings»: one log (src:<its id>) or the rest («n more»)
const LOGS_FILTER_SRC     = '/^(?:more|src:[A-Za-z0-9][A-Za-z0-9:._@-]{0,199})$/D';
// a service: a syslog line's program as the page reads it (desk.js SERVICE: no space, colon or bracket, 64 at most)
const LOGS_FILTER_SVC     = '/^[^\s:\[\]\x00-\x1f\x7f]{1,64}$/uD';

function logsFiltersFile(): string
{
    return $GLOBALS['logsFiltersFile'] ?? DATA_DIR . '/logs-filters.json';      // tests: a file of their own
}

/** Why a name can't be a saved filter's ('' = it can): its error's key (errors.logs_filter_name_…) */
function logsFilterNameWhy(string $name): string
{
    if (!mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        return 'logs_filter_name_form';
    }
    $name = trim($name);
    if ($name === '') {
        return 'logs_filter_name_empty';
    }
    return mb_strlen($name) > LOGS_FILTER_NAME ? 'logs_filter_name_long' : '';
}

/** A name as two names compare: trimmed, any case */
function logsFilterNameKey(string $name): string
{
    return mb_strtolower(trim($name));
}

/**
 * A filter's time in exactly its shape: null (none), {preset} or {from, to} (seconds, either may be null, not both;
 * from before to) — anything else null
 */
function logsFilterTime(mixed $t): ?array
{
    if (!is_array($t)) {
        return null;
    }
    if (isset($t['preset'])) {
        return is_string($t['preset']) && in_array($t['preset'], LOGS_FILTER_PRESETS, true) && count($t) === 1 ? ['preset' => $t['preset']] : null;
    }
    $from = $t['from'] ?? null;
    $to = $t['to'] ?? null;
    $ok = fn ($x): bool => $x === null || (is_int($x) && $x > 0 && $x < 1 << 40);
    if (!$ok($from) || !$ok($to) || ($from === null && $to === null) || ($from !== null && $to !== null && $from >= $to)
        || array_diff(array_keys($t), ['from', 'to'])) {
        return null;
    }
    return ['from' => $from, 'to' => $to];
}

/**
 * What a filter sets, in exactly its shape — or null: {words, cat, src, svc, only, time}; cat one of her categories (or
 * ''), src a log's Schildchen (or ''), svc a service (or ''; an entry from before has none), words one line, at least
 * one of them set
 */
function logsFilterWhat(array $f): ?array
{
    $w = ['words' => $f['words'] ?? '', 'cat' => $f['cat'] ?? '', 'src' => $f['src'] ?? '', 'svc' => $f['svc'] ?? '', 'only' => $f['only'] ?? false];
    if (!is_string($w['words']) || !is_string($w['cat']) || !is_string($w['src']) || !is_string($w['svc']) || !is_bool($w['only'])) {
        return null;
    }
    if ($w['svc'] !== '' && !preg_match(LOGS_FILTER_SVC, $w['svc'])) {
        return null;
    }
    if (strlen($w['words']) > LOGS_FILTER_WORDS || !mb_check_encoding($w['words'], 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $w['words'])
        || ($w['cat'] !== '' && !in_array($w['cat'], LOGS_FILTER_CATS, true)) || ($w['src'] !== '' && !preg_match(LOGS_FILTER_SRC, $w['src']))) {
        return null;
    }
    $w['words'] = trim($w['words']);
    $w['time'] = array_key_exists('time', $f) && $f['time'] !== null ? logsFilterTime($f['time']) : null;
    if (array_key_exists('time', $f) && $f['time'] !== null && $w['time'] === null) {
        return null;                    // a time she doesn't know: not hers to judge
    }
    return $w['words'] === '' && $w['cat'] === '' && $w['src'] === '' && $w['svc'] === '' && !$w['only'] && $w['time'] === null ? null : $w;
}

/**
 * Her saved filters as kept: [{id, list, name, words, cat, src, svc, only, time, open, made}] — only entries in exactly their
 * shape; what she doesn't know stays in the file ($all['keep'], logsFiltersWrite()), so do keys she doesn't know.
 */
function logsFilters(): array
{
    $raw = readJson(logsFiltersFile()) ?? [];
    $out = ['filters' => [], 'keep' => [], 'raw' => $raw];
    $ids = [];
    foreach (is_array($raw['filters'] ?? null) ? array_values($raw['filters']) : [] as $f) {
        $what = is_array($f) ? logsFilterWhat($f) : null;
        $ok = $what !== null && is_string($f['id'] ?? null) && preg_match('/^[0-9a-f]{12}$/D', $f['id'])
            && is_string($f['list'] ?? null) && in_array($f['list'], LOGS_FILTER_LISTS, true)
            && is_string($f['name'] ?? null) && logsFilterNameWhy($f['name']) === '' && !isset($ids[$f['id']]);
        if (!$ok) {
            $out['keep'][] = $f;        // not hers to judge: kept as it is
            continue;
        }
        $ids[$f['id']] = true;
        $out['filters'][] = ['id' => $f['id'], 'list' => $f['list'], 'name' => trim($f['name'])] + $what
            + ['open' => ($f['open'] ?? false) === true, 'made' => is_int($f['made'] ?? null) ? $f['made'] : null, 'raw' => $f];
    }
    return $out;
}

/** What the page gets: per filter what it sets and its name, never more */
function logsFiltersForPage(): array
{
    return array_map(fn (array $f): array => array_intersect_key($f, array_flip(['id', 'list', 'name', 'words', 'cat', 'src', 'svc', 'only', 'time', 'open'])),
        logsFilters()['filters']);
}

/** Write them back: what she knows in its shape (with every key of its own she doesn't know), what she doesn't as it was */
function logsFiltersWrite(array $all): void
{
    $raw = $all['raw'];
    $raw['v'] = 1;
    $raw['time'] = time();
    $raw['filters'] = array_merge(array_map(function (array $f): array {
        $keep = is_array($f['raw'] ?? null) ? $f['raw'] : [];
        $mine = ['id' => $f['id'], 'list' => $f['list'], 'name' => $f['name'], 'words' => $f['words'], 'cat' => $f['cat'], 'src' => $f['src'],
                 'svc' => $f['svc'], 'only' => $f['only'], 'time' => $f['time'], 'made' => $f['made']];
        if ($f['open'] ?? false) {
            $mine['open'] = true;
        }
        return $mine + $keep;
    }, $all['filters']), $all['keep']);
    writeAtomic(logsFiltersFile(), jsonEncode($raw));
}

/** A seconds field of a request: absent or null → null, an int as it is — anything else refused (never cast) */
function logsFilterSeconds(array $r, string $field): ?int
{
    $v = $r[$field] ?? null;
    if ($v !== null && !is_int($v)) {
        throw new Problem('bad_request');
    }
    return $v;
}

/** What a request's bar has set (the page sends all of it, every time) */
function logsFilterFromRequest(array $r): array
{
    $words = optText($r, 'words');
    $only = boolField($r, 'only');
    if (strlen($words) > LOGS_FILTER_WORDS || !mb_check_encoding($words, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $words)) {
        throw new Problem('logs_filter_words', ['n' => LOGS_FILTER_WORDS]);
    }
    $preset = optText($r, 'time');
    $time = null;
    if ($preset === 'range') {
        $time = logsFilterTime(['from' => logsFilterSeconds($r, 'from'), 'to' => logsFilterSeconds($r, 'to')]);
        if ($time === null) {
            throw new Problem('logs_filter_range');
        }
    } elseif ($preset !== '') {
        if (!in_array($preset, LOGS_FILTER_PRESETS, true)) {
            throw new Problem('bad_request');
        }
        $time = ['preset' => $preset];
    }
    $f = ['words' => $words, 'cat' => optText($r, 'cat'), 'src' => optText($r, 'src'), 'svc' => optText($r, 'svc'), 'only' => $only, 'time' => $time];
    if (trim($words) === '' && $f['cat'] === '' && $f['src'] === '' && $f['svc'] === '' && !$only && $time === null) {
        throw new Problem('logs_filter_nothing');
    }
    return logsFilterWhat($f) ?? throw new Problem('bad_request');      // a category, a log or a service she doesn't know
}

/** «Save filter…»: what the bar has set now, under a name, for one list (a name once per list) */
function logsFilterSave(string $list, string $name, array $r): array
{
    if (!in_array($list, LOGS_FILTER_LISTS, true)) {
        throw new Problem('bad_request');
    }
    $why = logsFilterNameWhy($name);
    if ($why !== '') {
        throw new Problem($why, ['n' => LOGS_FILTER_NAME]);
    }
    $what = logsFilterFromRequest($r);
    $all = logsFilters();                // now, not what the page showed
    $mine = array_values(array_filter($all['filters'], fn (array $f): bool => $f['list'] === $list));
    if (in_array(logsFilterNameKey($name), array_map(fn (array $f): string => logsFilterNameKey($f['name']), $mine), true)) {
        throw new Problem('logs_filter_twice', ['name' => trim($name)]);
    }
    if (count($mine) >= LOGS_FILTER_MAX) {
        throw new Problem('logs_filter_full', ['n' => LOGS_FILTER_MAX]);
    }
    $taken = array_merge(array_column($all['filters'], 'id'), array_column(array_filter($all['keep'], 'is_array'), 'id'));
    do {
        $id = bin2hex(random_bytes(6));
    } while (in_array($id, $taken, true));
    $all['filters'][] = ['id' => $id, 'list' => $list, 'name' => trim($name)] + $what + ['open' => false, 'made' => time(), 'raw' => []];
    logsFiltersWrite($all);
    logLine("Ms. Protocolli: a saved filter for $list");
    return ['ok' => true, 'id' => $id];
}

/** «Rename…»: by its id, as kept now; the new name once in its list */
function logsFilterRename(string $id, string $name): array
{
    $why = logsFilterNameWhy($name);
    if ($why !== '') {
        throw new Problem($why, ['n' => LOGS_FILTER_NAME]);
    }
    $all = logsFilters();
    $at = array_search($id, array_column($all['filters'], 'id'), true);
    if ($at === false) {
        throw new Problem('logs_filter_gone');
    }
    $list = $all['filters'][$at]['list'];
    foreach ($all['filters'] as $i => $f) {
        if ($i !== $at && $f['list'] === $list && logsFilterNameKey($f['name']) === logsFilterNameKey($name)) {
            throw new Problem('logs_filter_twice', ['name' => trim($name)]);
        }
    }
    $all['filters'][$at]['name'] = trim($name);
    logsFiltersWrite($all);
    logLine("Ms. Protocolli: a saved filter of $list renamed");
    return ['ok' => true];
}

/** «Delete»: by its id, as kept now (what is in the list only changes the page's view: nothing else goes with it) */
function logsFilterRemove(string $id): array
{
    $all = logsFilters();
    $left = array_values(array_filter($all['filters'], fn (array $f): bool => $f['id'] !== $id));
    if (count($left) === count($all['filters'])) {
        throw new Problem('logs_filter_gone');
    }
    $all['filters'] = $left;
    logsFiltersWrite($all);
    logLine('Ms. Protocolli: a saved filter deleted');
    return ['ok' => true];
}
