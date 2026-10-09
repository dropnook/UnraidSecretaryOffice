<?php
declare(strict_types=1);

/*
 * JSON API for the office.
 *
 * GET  ?a=state&desk=<id>[&fresh=1|wait=1|stored=1]   a desk's last state, at once (show first, then look — apiLook()):
 *                                     older than its refresh_after it is `stale` and the agent looks again in the
 *                                     background (`refreshing`, one look per desk at a time); fresh=1 waits for a new
 *                                     look (the Tour / «Look again» buttons), wait=1 for the one under way (the page's
 *                                     second ask), stored=1 never asks the agent (the reception, the badges)
 * GET  ?a=part&desk=<id>&part=<name>[&fresh=1|wait=1|stored=1]  an extra state file data/<id>-<name>.json, looked at
 *                                     again the same way where desk.json "parts" says how (officeDeskParts())
 * GET  ?a=strings&lang=<code>         all UI strings of a language
 * GET  ?a=places                      the words of the places the search finds (core.js), in every language — only the
 *                                     keys the desks list in places.json, nothing a request names (apiPlaceWords())
 * GET  ?a=places&part=text            their texts (explanations, the Consultant's guide paragraphs) in English only — the
 *                                     page has its own language's in the strings (apiPlaceTexts())
 * GET  ?a=log                         tail of the agent log
 * GET  ?a=dash&lang=<code>            the rows of the tile on Unraid's Dashboard (dashboard.php), in the browser's language
 * GET  ?a=agent                       the messenger alone (agentInfo(): the array stopped, the night shift)
 * POST {"a": "<desk>.<action>", ...}  a request for the agent; it checks everything
 * POST {"a": "office.hire|fire"}       who works here (see staff.php)
 * POST {"a": "office.staff_order", "order": [desk, …]}   in which order (the reception's cards, the tabs; staff.php)
 * POST {"a": "office.supporter_set|supporter_remove|supporter_ask|supporter_code"}   the supporter keys (supporter.php)
 * POST {"a": "office.supporter_claim", auto?}   the key a tip made, asked of the support page by the agent
 *                                     (agent/lib/supporter.php) — never by the browser
 * POST {"a": "office.lang", "lang": <code>}   the language the page shows, for the notifications (desks.php)
 * POST {"a": "office.report_preview|report_send|reports", …}   «Report a problem or a wish…»: the agent's
 *                                     (agent/lib/report.php) — it alone ever sends a report, and only on report_send
 * POST {"a": "office.report_image", "data": <base64>}   one picture for the next preview: checked by its first bytes and
 *                                     size, left in the RAM inbox (apiReportImageStash()) — the answer is its ref
 *
 * Who may use it is Unraid's business: everything under /plugins/… is behind
 * its login (nginx auth_request), and every POST needs its csrf_token
 * (local_prepend.php). On top of that POSTs need JSON and the header
 * X-Office: 1 — another web page in the same browser can't send that without
 * a CORS preflight, which never succeeds here — and an Origin, if any, of
 * this host. Whoever is logged in to Unraid is root anyway; the desks add
 * previews and confirmations against mistakes, not a second lock.
 *
 * Answers of 1 KB and more go out gzip-compressed when the browser takes gzip (apiSend()).
 */

// the pictures' first bytes and limits — one definition with the agent's (a file of pure functions, it touches nothing)
require_once __DIR__ . '/reportimage.php';

function api_main(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            match (apiText($_GET, 'a')) {
                'state'   => answerThenLook(apiState(apiText($_GET, 'desk'), apiLookMode($_GET))),
                'part'    => answerThenLook(apiPart(apiText($_GET, 'desk'), apiText($_GET, 'part'), apiLookMode($_GET))),
                'strings' => apiStrings(apiText($_GET, 'lang', 'en')),
                'places'  => apiPlaces(apiText($_GET, 'part')),
                'log'     => answer(['ok' => true, 'lines' => apiLogTail(400)]),
                'dash'    => apiDash(apiText($_GET, 'lang')),
                'agent'   => answer(['ok' => true, 'agent' => agentInfo()]),
                default   => answer(['ok' => false, 'error' => ['key' => 'bad_request']], 404),
            };
        }
        if ($method !== 'POST') {
            answer(['ok' => false, 'error' => ['key' => 'bad_request']], 405);
        }

        checkOrigin();
        $raw = (string) file_get_contents('php://input', false, null, 0, API_IMAGE_BODY_MAX + 1);
        $data = json_decode($raw, true, 16);
        $action = is_array($data) ? apiText($data, 'a') : '';
        if (strlen($raw) > ($action === 'office.report_image' ? API_IMAGE_BODY_MAX : API_BODY_MAX)) {
            answer(['ok' => false, 'error' => ['key' => 'bad_request']], 413);      // only a picture may be larger than 1 MB
        }
        unset($raw);
        if ($action === 'office.report_image') {
            answer(apiReportImageStash($data));
        }
        if ($action === 'office.hire' || $action === 'office.fire') {
            answer(officeStaffAction($action, $data));
        }
        if ($action === 'office.staff_order') {
            answer(officeStaffOrderAction($data));
        }
        if (in_array($action, OFFICE_SUPPORTER_ACTIONS, true)) {
            answer(officeSupporterAction($action, $data));
        }
        if ($action === 'office.lang') {
            answer(officeLangRemember($data));
        }
        $office = in_array($action, OFFICE_AGENT_ACTIONS, true);      // the office's own, answered by the agent
        if (!$office && (!preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_]*$/D', $action) || !isset(officeDesks()[explode('.', $action)[0]]))) {
            answer(['ok' => false, 'error' => ['key' => 'unknown_action', 'params' => ['action' => $action]]], 400);
        }
        unset($data['a']);
        $desk = explode('.', $action)[0];
        if (!$office && !officeIsHired($desk) && explode('.', $action)[1] !== 'refresh') {
            answer(['ok' => false, 'error' => ['key' => 'not_hired', 'params' => ['desk' => $desk]]], 403);
        }
        set_time_limit(660);
        ignore_user_abort(true);   // a deletion runs to the end even if the tab closes

        // secrets never go into the mailbox (it lies on the pool): through RAM, see apiSecretStash()
        $stash = array_key_exists('secret', $data) ? apiSecretStash($action, $data['secret']) : null;
        unset($data['secret']);
        if ($stash !== null) {
            $data['secret_ref'] = $stash;
        }
        try {
            $response = askAgent($action, $data, 600);
        } finally {
            if ($stash !== null) {
                apiSecretDrop($stash);     // the agent took it already — or never will
            }
        }
        $response['agent'] = agentInfo();
        answer($response);
    } catch (OfficeProblem $e) {
        answer(['ok' => false, 'error' => ['key' => $e->key, 'params' => $e->params]], $e->status);
    } catch (AgentAway $e) {
        answer(['ok' => false, 'error' => ['key' => 'agent_away'], 'agent' => agentInfo()], 503);
    } catch (AgentBusy $e) {
        answer(['ok' => false, 'error' => ['key' => 'agent_busy'], 'agent' => agentInfo()], 504);
    } catch (AgentRestarted $e) {
        // a deploy or a crash restarted it while the request waited: said at once, never a wait of minutes
        answer(['ok' => false, 'error' => ['key' => 'agent_restarted'], 'agent' => agentInfo()], 503);
    } catch (Throwable $e) {
        error_log('UnraidSecretaryOffice: ' . $e);
        answer(['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]], 500);
    }
}

/**
 * A text of the query or the request: absent → $default, a string as it is — anything else (`?desk[]=y`, `{"a": [..]}`) is
 * refused (bad_request), never cast: «Array» and a warning (QA 2026-10-08, finding 14).
 */
function apiText(array $from, string $key, string $default = ''): string
{
    $value = $from[$key] ?? $default;
    if (!is_string($value)) {
        throw new OfficeProblem('bad_request', 400);
    }
    return $value;
}

/** A desk's state, at once — older than its refresh_after the agent looks again (apiLook()) */
function apiState(string $desk, string $look = ''): array
{
    $desks = officeDesks();
    if (!isset($desks[$desk])) {
        return ['ok' => false, 'error' => ['key' => 'unknown_desk', 'params' => ['desk' => $desk]]];
    }
    return ['ok' => true] + apiLook(OFFICE_DATA . "/$desk.json", "$desk.refresh", $desks[$desk]['refresh_after'], $desk, $look);
}

/**
 * An extra state part; one desk.json names under "parts" is looked at again like a desk's state (apiLook(): the
 * server's clock, never the browser's; the short wait), hired desks only — any other part is a plain file, as it is.
 */
function apiPart(string $desk, string $part, string $look = ''): array
{
    $desks = officeDesks();
    if (!isset($desks[$desk]) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $part)) {
        return ['ok' => false, 'error' => ['key' => 'bad_request']];
    }
    $file = OFFICE_DATA . "/$desk-$part.json";
    $rule = $desks[$desk]['parts'][$part] ?? null;
    if ($rule === null || !officeIsHired($desk)) {
        return ['ok' => true, 'agent' => agentInfo(), 'part' => officeReadJson($file)];
    }
    $r = apiLook($file, "$desk.{$rule['action']}", $rule['refresh_after'], "$desk.$part", $look);
    $r['part'] = $r['state'];
    unset($r['state']);
    return ['ok' => true] + $r;
}

/** How a request wants its state: '' (at once, a stale one looked at again in the background), fresh, wait or stored */
function apiLookMode(array $query): string
{
    foreach (['fresh', 'wait', 'stored'] as $mode) {
        if (!empty($query[$mode])) {
            return $mode;
        }
    }
    return '';
}

/**
 * Show first, then look (Benj, 2026-10-07 — perf report levers 1 and 2). A state file is answered at once, with its
 * age and whether it is older than refresh_after (`stale`); a stale one is looked at again by the agent AFTER the
 * answer went out (`later`, run by answerThenLook()) — `refreshing` says a look is under way, the page then asks with
 * `wait` and gets the new state when it is there. One look per state at a time: its lock lies in RAM
 * (apiLookLock(), officeRunDir()), held by the PHP process that waits for the agent; a second page (or tab) that
 * finds it taken only says `refreshing`. The modes:
 *   ''       at once; stale → a look in the background (none to show yet, or no lock to be had → waited for, as before)
 *   fresh    wait for a new look — or for the one under way (≤ 12 s); the Tour / «Look again» buttons
 *   wait     a look is under way: wait for it to end (≤ 12 s), then the state as it is; never starts one
 *   stored   as it is, never a look — the reception and the badges every page shows (no agent call at all)
 * The agent away: as it is. The look itself is the short wait (askAgent, 10 s): a long job of the agent going on, the
 * last state will do.
 *
 * @return array{agent: array, state: ?array, age: ?int, stale: bool, refreshing: bool, refresh_after: int, later?: Closure}
 */
function apiLook(string $file, string $action, int $after, string $key, string $look): array
{
    $agent = agentInfo();
    $state = officeReadJson($file);
    $old = static fn (?array $s): bool => $s === null || time() - (int) ($s['time'] ?? 0) > $after;
    $out = static function (?array $s, bool $refreshing = false) use (&$agent, $old, $after): array {
        return ['agent' => $agent, 'state' => $s, 'age' => $s === null ? null : max(0, time() - (int) ($s['time'] ?? 0)),
                'stale' => $old($s), 'refreshing' => $refreshing, 'refresh_after' => $after];
    };
    if ($look === 'stored' || !$agent['running']) {
        return $out($state);
    }
    if ($look === 'wait') {
        apiLookWait($key, APILOOK_WAIT);
        return $out(officeReadJson($file));
    }
    if ($look !== 'fresh' && !$old($state)) {
        return $out($state);
    }
    $lock = apiLookLock($key);
    if ($lock === false) {
        if ($look === 'fresh' || $state === null) {
            apiLookWait($key, APILOOK_WAIT);          // someone else's look is under way: that one will do
            return $out(officeReadJson($file));
        }
        return $out($state, true);
    }
    if ($look === 'fresh' || $state === null || $lock === null) {
        try {
            $new = apiLookNow($action, $agent);
        } finally {
            apiLookRelease($lock);
        }
        return $out($new ?? officeReadJson($file));
    }
    return $out($state, true) + ['later' => static function () use ($action, $lock): void {
        $agent = [];
        try {
            apiLookNow($action, $agent);
        } finally {
            apiLookRelease($lock);
        }
    }];
}

/** How long a page's `wait` (or a `fresh` meeting a look under way) waits for that look: a bit more than askAgent's 10 s */
const APILOOK_WAIT = 12.0;

/** The agent's look, the short wait; its new state, or null (it failed, the agent is busy with something longer or gone) */
function apiLookNow(string $action, array &$agent): ?array
{
    try {
        $r = askAgent($action, [], 10);
        return !empty($r['ok']) && is_array($r['state'] ?? null) ? $r['state'] : null;
    } catch (AgentAway) {
        $agent['running'] = false;
    } catch (AgentBusy | AgentRestarted) {
        // busy with something longer, or restarted meanwhile — the last state will do
    }
    return null;
}

/** The lock file of a look at <key> (<desk> or <desk>.<part>) in the office's RAM folder, or null when there is none to be had */
function apiLookLockFile(string $key): ?string
{
    $dir = officeRunDir();
    if (!preg_match('/^[a-z][a-z0-9_-]{0,31}(\.[a-z][a-z0-9_-]{0,31})?$/D', $key) || is_link($dir) || !is_dir($dir)) {
        return null;
    }
    $file = "$dir/look-$key.lock";
    return is_link($file) ? null : $file;
}

/**
 * Take the lock of a look: the open handle (ours until apiLookRelease()), false while another process holds it (a look is
 * under way), null when there is none to be had (no RAM folder — the agent not started yet —, a link).
 *
 * @return resource|false|null
 */
function apiLookLock(string $key): mixed
{
    $file = apiLookLockFile($key);
    if ($file === null) {
        return null;
    }
    $mask = umask(0077);
    $h = @fopen($file, 'c');
    umask($mask);
    if (!$h) {
        return null;
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return false;
    }
    return $h;
}

/** @param resource|false|null $lock */
function apiLookRelease(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Is a look at <key> under way (its lock held by another process)? */
function apiLookBusy(string $key): bool
{
    $lock = apiLookLock($key);
    apiLookRelease($lock);
    return $lock === false;
}

/** Wait (≤ $seconds) while a look at <key> is under way */
function apiLookWait(string $key, float $seconds): void
{
    $until = microtime(true) + $seconds;
    while (apiLookBusy($key) && microtime(true) < $until) {
        usleep(50000);
    }
}

/**
 * Answer a state or part; a look left for later (apiLook() `later`) runs once the browser has the answer: php-fpm hands
 * it over with fastcgi_finish_request() (complete and compressed by then, apiSend()), other servers (php -S) are told
 * to close after its length — the PHP process then waits for the agent and lets go of the look's lock.
 */
function answerThenLook(array $data): never
{
    $later = $data['later'] ?? null;
    unset($data['later']);
    if (!$later instanceof Closure) {
        answer($data);
    }
    ignore_user_abort(true);
    set_time_limit(60);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    $fpm = function_exists('fastcgi_finish_request');
    if (!$fpm) {
        header('Connection: close');
    }
    apiSend(apiJson($data));          // complete, compressed, its length said — before php-fpm hands it over
    if ($fpm) {
        fastcgi_finish_request();
    } else {
        flush();
    }
    try {
        $later();
    } catch (Throwable $e) {
        error_log('UnraidSecretaryOffice: ' . $e);
    }
    exit;
}

function apiStrings(string $code): never
{
    $codes = array_column(officeLanguages(), 'code');
    if (!in_array($code, $codes, true)) {
        $code = 'en';
    }
    header('Cache-Control: public, max-age=86400');   // the URL carries a version stamp
    answer(['ok' => true, 'lang' => $code, 'strings' => officeStrings($code)]);
}

/**
 * The search's words (core.js «places and the search»): the page knows its places (Office.places() in each desk.js) and
 * the office's language; to find «Partn» in an English office it needs the other languages' words too — of those keys
 * only, not the whole strings ×5. The page asks once, on the first open of the search, with the strings' stamp and the
 * version in the URL (a day in the browser's cache, like the strings). part=text: the places' texts (phase 3) — in five
 * languages ≈ 166 KB gzip beside the words' 14 (measured 2026-10-08), so not with the words: English only (31 KB gzip),
 * asked right after them by a page that speaks another language (its own language's texts are in its strings already).
 */
function apiPlaces(string $part = ''): never
{
    header('Cache-Control: public, max-age=86400');   // the URL carries the stamp and the version
    if ($part === 'text') {
        answer(['ok' => true, 'lang' => 'en', 'texts' => apiPlaceTexts()]);
    }
    answer(['ok' => true, 'langs' => array_column(officeLanguages(), 'code'), 'words' => apiPlaceWords()]);
}

/**
 * A places.json: {"keys": [the places' lang keys], "texts": [their texts' keys, a guide's paragraphs as '<prefix>.*']} —
 * only well-formed keys (a list of the place keys alone is read as before)
 *
 * @return array{keys: list<string>, texts: list<string>}
 */
function apiPlaceList(string $file): array
{
    $j = officeReadJson($file) ?? [];
    $ok = static fn (mixed $list, string $re): array => array_values(array_filter(is_array($list) ? $list : [],
        static fn ($k): bool => is_string($k) && preg_match($re, $k) === 1));
    $key = '/^[a-z0-9_]+(\.[a-z0-9_]+){0,5}$/D';
    if (array_is_list($j)) {
        return ['keys' => $ok($j, $key), 'texts' => []];
    }
    return ['keys' => $ok($j['keys'] ?? [], $key), 'texts' => $ok($j['texts'] ?? [], '/^[a-z0-9_]+(\.[a-z0-9_]+){0,5}(\.\*)?$/D')];
}

/**
 * The keys of the places: the office's (public/assets/places.json) and each desk's (public/desks/<id>/places.json, its
 * own keys like T('…'), as <id>.<key>) plus every desk's name — the lists tests/run.php keeps equal to the desks'
 * Office.places(). Only well-formed keys; nothing from the request. texts: their texts' keys instead.
 *
 * @return list<string>
 */
function apiPlaceKeys(bool $texts = false): array
{
    $which = $texts ? 'texts' : 'keys';
    $keys = [];
    foreach (apiPlaceList(OFFICE_PUBLIC . '/assets/places.json')[$which] as $k) {
        $keys[$k] = true;
    }
    foreach (officeDesks() as $id => $_) {
        if (!$texts) {
            $keys["$id.name"] = true;
        }
        foreach (apiPlaceList(OFFICE_PUBLIC . "/desks/$id/places.json")[$which] as $k) {
            $keys["$id.$k"] = true;
        }
    }
    return array_keys($keys);
}

/**
 * The places' texts in English: {<key>: text} — each text key the places.json files list, a guide's '<prefix>.*' as its
 * paragraphs <prefix>.1, <prefix>.2 … as long as they exist; a plural's forms joined
 *
 * @return array<string, string>
 */
function apiPlaceTexts(): array
{
    $en = officeStrings('en');
    $text = static fn (mixed $v): string => is_array($v) ? implode(' ', array_unique(array_filter($v, 'is_string'))) : (is_string($v) ? $v : '');
    $out = [];
    foreach (apiPlaceKeys(true) as $k) {
        $keys = [$k];
        if (str_ends_with($k, '.*')) {
            $keys = [];
            for ($i = 1, $p = substr($k, 0, -2); $i < 100 && isset($en["$p.$i"]); $i++) {
                $keys[] = "$p.$i";
            }
        }
        foreach ($keys as $one) {
            $s = $text($en[$one] ?? null);
            if ($s !== '') {
                $out[$one] = $s;
            }
        }
    }
    return $out;
}

/**
 * The places' words per language: {<key>: {<code>: text}} — a plural's forms joined; a language that says it as English
 * does (or not yet) left out, English has it.
 *
 * @return array<string, array<string, string>>
 */
function apiPlaceWords(): array
{
    $keys = apiPlaceKeys();
    $text = static fn (mixed $v): string => is_array($v) ? implode(' ', array_unique(array_filter($v, 'is_string'))) : (is_string($v) ? $v : '');
    $en = officeStrings('en');
    $words = [];
    foreach (array_column(officeLanguages(), 'code') as $code) {
        $strings = $code === 'en' ? $en : officeStrings($code);
        foreach ($keys as $k) {
            $s = $text($strings[$k] ?? null);
            if ($s !== '' && ($code === 'en' || $s !== $text($en[$k] ?? null))) {
                $words[$k][$code] = $s;
            }
        }
    }
    return $words;
}

function checkOrigin(): void
{
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (($_SERVER['HTTP_X_OFFICE'] ?? '') !== '1' || !str_starts_with($type, 'application/json')) {
        answer(['ok' => false, 'error' => ['key' => 'rejected']], 403);
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && preg_replace('#^https?://#', '', $origin) !== ($_SERVER['HTTP_HOST'] ?? '')) {
        answer(['ok' => false, 'error' => ['key' => 'rejected']], 403);
    }
}

/** A request's body: at most 1 MB — a picture for a report (office.report_image, base64) at most this */
const API_BODY_MAX = 1 << 20;
const API_IMAGE_BODY_MAX = 3 << 20;
/** Pictures waiting in the RAM inbox for a preview: at most this many, each gone after API_IMAGE_TTL seconds */
const API_IMAGES_WAITING = 2 * REPORT_IMG_COUNT_MAX;
const API_IMAGE_TTL = 900;

/** The office's own actions the agent answers (agent/lib/report.php officeAgentActions()) — no desk, never «not hired» */
const OFFICE_AGENT_ACTIONS = ['office.report_preview', 'office.report_send', 'office.reports', 'office.supporter_claim'];

/** The only actions that may carry secrets (the Consultant's Kopia setup) */
const OFFICE_SECRET_ACTIONS = ['advisor.kopia_repo'];

/**
 * Secrets for the agent (keys, passwords the user types) must never touch
 * persistent storage — and the mailbox lies in the data folder on the pool,
 * which is snapshotted and backed up. So they go into a file of their own in
 * officeInboxDir() (a root-only folder in /run, a tmpfs: RAM), 0600 from the
 * start, a random name; only that name travels in the request. The agent
 * reads the file and removes it at once; apiSecretDrop() removes it here in
 * any case after the request.
 * Answers an error itself (and ends the request) when it can't.
 */
function apiSecretStash(string $action, mixed $secret): string
{
    $ok = in_array($action, OFFICE_SECRET_ACTIONS, true) && is_array($secret) && $secret && count($secret) <= 8;
    foreach ($ok ? $secret : [] as $k => $v) {
        $ok = $ok && is_string($k) && preg_match('/^[a-z_]{1,32}$/D', $k) === 1 && is_string($v) && strlen($v) <= 4096;
    }
    if (!$ok) {
        answer(['ok' => false, 'error' => ['key' => 'bad_request']], 400);
    }
    $dir = officeInboxDir();
    @mkdir(dirname($dir), 0700, true);
    @mkdir($dir, 0700);
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 || $st['uid'] !== $me) {
        answer(['ok' => false, 'error' => ['key' => 'ad_secret_inbox', 'params' => ['dir' => $dir]]], 500);
    }
    $id = bin2hex(random_bytes(16));
    $text = json_encode($secret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $old = umask(0177);
    $f = @fopen("$dir/$id.secret", 'x');          // new, ours, 0600 from the start; never through a link
    umask($old);
    $written = $f !== false && @fwrite($f, (string) $text) === strlen((string) $text);
    if ($f === false || !fclose($f) || !$written) {
        @unlink("$dir/$id.secret");
        answer(['ok' => false, 'error' => ['key' => 'ad_secret_inbox', 'params' => ['dir' => $dir]]], 500);
    }
    return $id;
}

/**
 * One picture for a report's preview (dropnook/UnraidSecretaryOffice#6) — the web side only looks at it: base64 that
 * decodes strictly, ≤ REPORT_IMG_IN_MAX bytes, a PNG, JPEG or WebP by its first bytes (SVG, HTML and anything else
 * refused here already). It goes where the secrets go (officeInboxDir(), RAM — never the mailbox, which lies on the
 * pool): a new 0600 file `<ref>.image`. The agent takes it with the preview, draws it anew (src/reportimage.php)
 * and removes it; what nobody took goes after API_IMAGE_TTL, and no more than API_IMAGES_WAITING wait at a time.
 *
 * @return array{ok: true, ref: string, bytes: int, type: string}
 */
function apiReportImageStash(array $data, ?string $dir = null): array
{
    $b64 = $data['data'] ?? null;
    if (!is_string($b64) || $b64 === '' || strlen($b64) > intdiv(REPORT_IMG_IN_MAX + 2, 3) * 4 + 4) {
        throw new OfficeProblem(is_string($b64) && $b64 !== '' ? 'report_image_big' : 'bad_request', 400, ['n' => 1]);
    }
    $bytes = preg_match('#^[A-Za-z0-9+/]+={0,2}$#D', $b64) ? base64_decode($b64, true) : false;
    if (!is_string($bytes) || $bytes === '') {
        throw new OfficeProblem('report_image_bad', 400, ['n' => 1]);
    }
    if (strlen($bytes) > REPORT_IMG_IN_MAX) {
        throw new OfficeProblem('report_image_big', 400, ['n' => 1]);
    }
    $type = reportImageType($bytes);
    if ($type === null) {
        throw new OfficeProblem('report_image_type', 400, ['n' => 1]);
    }
    $dir ??= officeInboxDir();
    @mkdir(dirname($dir), 0700, true);
    @mkdir($dir, 0700);
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 || $st['uid'] !== $me) {
        throw new OfficeProblem('office_storage', 500);
    }
    $waiting = 0;
    foreach (glob("$dir/*.image") ?: [] as $f) {
        $fs = @lstat($f);
        if ($fs && (($fs['mode'] & 0170000) !== 0100000 || $fs['mtime'] < time() - API_IMAGE_TTL)) {
            @unlink($f);
        } elseif ($fs) {
            $waiting++;
        }
    }
    if ($waiting >= API_IMAGES_WAITING) {
        throw new OfficeProblem('report_images_many', 429, ['n' => REPORT_IMG_COUNT_MAX]);
    }
    $ref = bin2hex(random_bytes(16));
    $old = umask(0177);
    $f = @fopen("$dir/$ref.image", 'x');            // new, ours, 0600 from the start; never through a link
    umask($old);
    $written = $f !== false && @fwrite($f, $bytes) === strlen($bytes);
    if ($f === false || !fclose($f) || !$written) {
        @unlink("$dir/$ref.image");
        throw new OfficeProblem('office_storage', 500);
    }
    return ['ok' => true, 'ref' => $ref, 'bytes' => strlen($bytes), 'type' => $type];
}

function apiSecretDrop(string $id): void
{
    if (preg_match('/^[0-9a-f]{32}$/D', $id)) {
        @unlink(officeInboxDir() . "/$id.secret");
    }
}

/** @return list<string> */
function apiLogTail(int $count): array
{
    $lines = @file(OFFICE_DATA . '/agent.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_slice($lines, -$count);
}

function answer(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    apiSend(apiJson($data));
    exit;
}

function apiJson(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Answers below this many bytes go out as they are: gzip would gain little for a round of zlib and two headers */
const API_GZIP_MIN = 1024;
/** zlib's level: 1 takes four fifths off a state in ≈ 1.5 ms per 300 KB (nostromo); 6 another tenth for 2.5× the time */
const API_GZIP_LEVEL = 1;

/**
 * Send an answer's bytes (Benj 2026-10-07, perf report lever 6): gzip-compressed when the browser takes it
 * (apiGzipWanted()) and the body is worth it (≥ API_GZIP_MIN) — −80…85 % of the bytes, what counts over a VPN or
 * Unraid Connect (the reception's states ≈ 1 MB → 0.2 MB, the strings 465 → 155 KB). Compressed before anything goes
 * out and sent with its Content-Length, so answerThenLook() hands php-fpm a complete answer (fastcgi_finish_request());
 * `Vary` on every answer big enough, whichever way it went (a cache keeps one per encoding). Unraid's nginx
 * compresses only .js/.css/.woff (`gzip off` for .php) and its PHP has zlib.output_compression off — were either on,
 * nothing is compressed twice here; something out already (never, with display_errors off): as it is.
 */
function apiSend(string $body): void
{
    if (headers_sent()) {
        echo $body;
        return;
    }
    $twice = (bool) ini_get('zlib.output_compression') || in_array('ob_gzhandler', ob_list_handlers(), true);
    if (strlen($body) >= API_GZIP_MIN && !$twice) {
        header('Vary: Accept-Encoding');
        $packed = apiGzipWanted() ? gzencode($body, API_GZIP_LEVEL) : false;
        if ($packed !== false) {
            header('Content-Encoding: gzip');
            $body = $packed;
        }
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
}

/** Does the browser take gzip? Accept-Encoding lists codings, each with a weight (`gzip, br;q=0.8`; `q=0` = not that one) */
function apiGzipWanted(?string $accept = null): bool
{
    $accept ??= (string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    foreach (explode(',', strtolower($accept)) as $coding) {
        [$name, $weight] = array_map('trim', explode(';', $coding, 2) + [1 => '']);
        if ($name === 'gzip' || $name === 'x-gzip') {
            return !preg_match('/^q\s*=\s*0(\.0{0,3})?$/D', $weight);
        }
    }
    return false;
}

/** The rows of the office's tile on Unraid's Dashboard (src/dashboard.php), for its refresh every minute */
function apiDash(string $lang): never
{
    require_once __DIR__ . '/page.php';
    require_once __DIR__ . '/dashboard.php';
    answer(['ok' => true, 'html' => officeDashRows(officeDashLang($lang))]);
}
