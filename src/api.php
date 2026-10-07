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
 * GET  ?a=log                         tail of the agent log
 * GET  ?a=dash&lang=<code>            the rows of the tile on Unraid's Dashboard (dashboard.php), in the browser's language
 * GET  ?a=agent                       the messenger alone (agentInfo(): the array stopped, the night shift)
 * POST {"a": "<desk>.<action>", ...}  a request for the agent; it checks everything
 * POST {"a": "office.hire|fire"}       who works here (see staff.php)
 * POST {"a": "office.staff_order", "order": [desk, …]}   in which order (the reception's cards, the tabs; staff.php)
 * POST {"a": "office.supporter_set|supporter_remove|supporter_ask"}   the supporter key (supporter.php)
 * POST {"a": "office.lang", "lang": <code>}   the language the page shows, for the notifications (desks.php)
 *
 * Who may use it is Unraid's business: everything under /plugins/… is behind
 * its login (nginx auth_request), and every POST needs its csrf_token
 * (local_prepend.php). On top of that POSTs need JSON and the header
 * X-Office: 1 — another web page in the same browser can't send that without
 * a CORS preflight, which never succeeds here — and an Origin, if any, of
 * this host. Whoever is logged in to Unraid is root anyway; the desks add
 * previews and confirmations against mistakes, not a second lock.
 */

function api_main(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            match ((string) ($_GET['a'] ?? '')) {
                'state'   => answerThenLook(apiState((string) ($_GET['desk'] ?? ''), apiLookMode($_GET))),
                'part'    => answerThenLook(apiPart((string) ($_GET['desk'] ?? ''), (string) ($_GET['part'] ?? ''), apiLookMode($_GET))),
                'strings' => apiStrings((string) ($_GET['lang'] ?? 'en')),
                'log'     => answer(['ok' => true, 'lines' => apiLogTail(400)]),
                'dash'    => apiDash((string) ($_GET['lang'] ?? '')),
                'agent'   => answer(['ok' => true, 'agent' => agentInfo()]),
                default   => answer(['ok' => false, 'error' => ['key' => 'bad_request']], 404),
            };
        }
        if ($method !== 'POST') {
            answer(['ok' => false, 'error' => ['key' => 'bad_request']], 405);
        }

        checkOrigin();
        $data = json_decode((string) file_get_contents('php://input', false, null, 0, 1 << 20), true, 16);
        $action = is_array($data) ? (string) ($data['a'] ?? '') : '';
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
        if (!preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_]*$/D', $action) || !isset(officeDesks()[explode('.', $action)[0]])) {
            answer(['ok' => false, 'error' => ['key' => 'unknown_action', 'params' => ['action' => $action]]], 400);
        }
        unset($data['a']);
        $desk = explode('.', $action)[0];
        if (!officeIsHired($desk) && explode('.', $action)[1] !== 'refresh') {
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
 * it over with fastcgi_finish_request(), other servers (php -S) are told its length and to close — the PHP process
 * then waits for the agent and lets go of the look's lock.
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
    $body = apiJson($data);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
    } else {
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
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
    echo apiJson($data);
    exit;
}

function apiJson(array $data): string
{
    return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** The rows of the office's tile on Unraid's Dashboard (src/dashboard.php), for its refresh every minute */
function apiDash(string $lang): never
{
    require_once __DIR__ . '/page.php';
    require_once __DIR__ . '/dashboard.php';
    answer(['ok' => true, 'html' => officeDashRows(officeDashLang($lang))]);
}
