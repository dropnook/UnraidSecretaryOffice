<?php
declare(strict_types=1);

/*
 * JSON API for the office.
 *
 * GET  ?a=state&desk=<id>[&fresh=1]   a desk's last state (refreshed when stale)
 * GET  ?a=part&desk=<id>&part=<name>  an extra state file data/<id>-<name>.json
 * GET  ?a=strings&lang=<code>         all UI strings of a language
 * GET  ?a=log                         tail of the agent log
 * GET  ?a=auth                        PIN set? this browser unlocked?
 * POST {"a": "<desk>.<action>", ...}  a request for the agent; it checks everything
 * POST {"a": "office.unlock|lock|pin"} handled here (see auth.php)
 *
 * POSTs need JSON and the header X-Office: 1. Another web page in the same
 * browser can't send that without a CORS preflight, which never succeeds
 * here. Reading is open (LAN); changing things can be protected by a PIN.
 */

function api_main(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $a = (string) ($_GET['a'] ?? '');
            match ($a) {
                'state'   => answer(apiState((string) ($_GET['desk'] ?? ''), !empty($_GET['fresh']))),
                'part'    => answer(apiPart((string) ($_GET['desk'] ?? ''), (string) ($_GET['part'] ?? ''))),
                'strings' => apiStrings((string) ($_GET['lang'] ?? 'en')),
                'log'     => answer(['ok' => true, 'lines' => apiLogTail(400)]),
                'auth'    => answer(['ok' => true, 'auth' => officeAuthStatus()]),
                default   => answer(['ok' => false, 'error' => ['key' => 'bad_request']], 404),
            };
        }
        if ($method !== 'POST') {
            answer(['ok' => false, 'error' => ['key' => 'bad_request']], 405);
        }

        checkOrigin();
        $data = json_decode((string) file_get_contents('php://input', false, null, 0, 1 << 20), true, 16);
        $action = is_array($data) ? (string) ($data['a'] ?? '') : '';
        if (str_starts_with($action, 'office.')) {
            answer(officeAuthAction($action, $data));
        }
        if (!preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_]*$/', $action) || !isset(officeDesks()[explode('.', $action)[0]])) {
            answer(['ok' => false, 'error' => ['key' => 'unknown_action', 'params' => ['action' => $action]]], 400);
        }
        unset($data['a']);
        officeMayWrite($action);
        set_time_limit(660);
        ignore_user_abort(true);   // a deletion runs to the end even if the tab closes

        $response = askAgent($action, $data, 600);
        $response['agent'] = agentInfo();
        answer($response);
    } catch (AuthProblem $e) {
        answer(['ok' => false, 'error' => ['key' => $e->key, 'params' => $e->params], 'auth' => officeAuthStatus()], $e->status);
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
    $state = readJsonFile(OFFICE_DATA . "/$desk.json");
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
    if (!isset(officeDesks()[$desk]) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $part)) {
        return ['ok' => false, 'error' => ['key' => 'bad_request']];
    }
    return ['ok' => true, 'agent' => agentInfo(), 'part' => readJsonFile(OFFICE_DATA . "/$desk-$part.json")];
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
