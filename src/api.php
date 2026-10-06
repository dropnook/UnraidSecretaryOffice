<?php
declare(strict_types=1);

/*
 * JSON API for the office.
 *
 * GET  ?a=state&desk=<id>[&fresh=1]   a desk's last state (refreshed when stale)
 * GET  ?a=part&desk=<id>&part=<name>  an extra state file data/<id>-<name>.json
 * GET  ?a=strings&lang=<code>         all UI strings of a language
 * GET  ?a=log                         tail of the agent log
 * GET  ?a=dash&lang=<code>            the rows of the tile on Unraid's Dashboard (dashboard.php)
 * POST {"a": "<desk>.<action>", ...}  a request for the agent; it checks everything
 * POST {"a": "office.hire|fire"}       who works here (see staff.php)
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
                'state'   => answer(apiState((string) ($_GET['desk'] ?? ''), !empty($_GET['fresh']))),
                'part'    => answer(apiPart((string) ($_GET['desk'] ?? ''), (string) ($_GET['part'] ?? ''))),
                'strings' => apiStrings((string) ($_GET['lang'] ?? 'en')),
                'log'     => answer(['ok' => true, 'lines' => apiLogTail(400)]),
                'dash'    => apiDash((string) ($_GET['lang'] ?? '')),
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
    } catch (Throwable $e) {
        error_log('UnraidSecretaryOffice: ' . $e);
        answer(['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]], 500);
    }
}

/** A desk's state; when older than its refresh_after, the agent reads it anew. */
function apiState(string $desk, bool $fresh): array
{
    $desks = officeDesks();
    if (!isset($desks[$desk])) {
        return ['ok' => false, 'error' => ['key' => 'unknown_desk', 'params' => ['desk' => $desk]]];
    }
    $agent = agentInfo();
    $state = officeReadJson(OFFICE_DATA . "/$desk.json");
    $age = $state ? time() - (int) ($state['time'] ?? 0) : PHP_INT_MAX;
    if ($agent['running'] && ($fresh || $age > $desks[$desk]['refresh_after'])) {
        try {
            $r = askAgent("$desk.refresh", [], 10);
            if (!empty($r['ok']) && is_array($r['state'] ?? null)) {
                $state = $r['state'];
            }
        } catch (AgentAway) {
            $agent['running'] = false;
        } catch (AgentBusy) {
            // busy with something longer — the last state will do
        }
    }
    return ['ok' => true, 'agent' => $agent, 'state' => $state];
}

function apiPart(string $desk, string $part): array
{
    if (!isset(officeDesks()[$desk]) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $part)) {
        return ['ok' => false, 'error' => ['key' => 'bad_request']];
    }
    return ['ok' => true, 'agent' => agentInfo(), 'part' => officeReadJson(OFFICE_DATA . "/$desk-$part.json")];
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** The rows of the office's tile on Unraid's Dashboard (src/dashboard.php), for its refresh every minute */
function apiDash(string $lang): never
{
    require_once __DIR__ . '/page.php';
    require_once __DIR__ . '/dashboard.php';
    answer(['ok' => true, 'html' => officeDashRows(officeDashLang($lang))]);
}
