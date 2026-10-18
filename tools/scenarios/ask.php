<?php
// tools/scenarios/ask.php — a scenario's button press: the request the page sends, through the plugin's own
// src/mailbox.php askAgent() (mailbox + doorbell), with the checks src/api.php makes before it (the action's shape, the
// desk known, hired unless `refresh` — the page's fresh look is `<desk>.refresh`; `office.ping` is the agent's own).
// office.hire / office.fire go where the page's go: officeStaffAction() on the web side. Never a desk function directly.
// Secrets never travel this way (the page stashes them in RAM: not for scenarios).
//
//   php ask.php [--wait <s>] [--raw] [--save-post <file>] <desk.action> [json]
//
// Prints the answer as one JSON line — without `state` (unless --raw) — and the errors api.php would answer as
// {ok:false, error:{key}} (agent_away, agent_busy, agent_restarted, not_hired, unknown_action, internal).
// --save-post <file>: the answer (without state and agent) also as a canned dialog answer for the page tool
// (tests/ui/states/post/<desk>.<action>.json). Exit 0 ok:true · 1 ok:false · 2 usage or no plugin.
$P = getenv('USO_PLUGIN') ?: '/usr/local/emhttp/plugins/unraid-secretary-office';
$args = array_slice($argv, 1);
$wait = 60.0;
$raw = false;
$save = null;
while ($args && str_starts_with($args[0], '--')) {
    $o = array_shift($args);
    if ($o === '--raw') {
        $raw = true;
    } elseif ($o === '--wait' && $args && is_numeric($args[0])) {
        $wait = max(1.0, (float) array_shift($args));
    } elseif ($o === '--save-post' && $args) {
        $save = array_shift($args);
    } else {
        fwrite(STDERR, "ask.php: unknown option $o\n");
        exit(2);
    }
}
$action = $args[0] ?? '';
$data = $args[1] ?? '{}';
$data = json_decode($data, true);
if ($action === '' || !is_array($data) || array_is_list($data) && $data !== []) {
    fwrite(STDERR, "usage: php ask.php [--wait <s>] [--raw] [--save-post <file>] <desk.action> [json object]\n");
    exit(2);
}
if (!is_file("$P/src/bootstrap.php")) {
    fwrite(STDERR, "ask.php: no plugin at $P\n");
    exit(2);
}
require "$P/src/bootstrap.php";

function scenarioAnswer(array $r, bool $raw, ?string $save): never
{
    if (!$raw) {
        unset($r['state']);
    }
    $json = json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json, PHP_EOL;
    if ($save !== null) {
        $canned = $r;
        unset($canned['state'], $canned['agent']);
        file_put_contents($save, json_encode($canned, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    exit(($r['ok'] ?? false) === true ? 0 : 1);
}

if (array_key_exists('secret', $data)) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => 'scenario_no_secret']], $raw, null);
}
try {
    if ($action === 'office.hire' || $action === 'office.fire') {
        scenarioAnswer(officeStaffAction($action, $data), $raw, $save);
    }
    $office = in_array($action, ['office.ping', 'office.report_preview', 'office.report_diag', 'office.reports'], true);
    if (!$office && (!preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_]*$/D', $action) || !isset(officeDesks()[explode('.', $action)[0]]))) {
        scenarioAnswer(['ok' => false, 'error' => ['key' => 'unknown_action', 'params' => ['action' => $action]]], $raw, $save);
    }
    $desk = explode('.', $action)[0];
    if (!$office && !officeIsHired($desk) && explode('.', $action)[1] !== 'refresh') {
        scenarioAnswer(['ok' => false, 'error' => ['key' => 'not_hired', 'params' => ['desk' => $desk]]], $raw, $save);
    }
    $r = askAgent($action, $data, $wait);
    $r['agent'] = agentInfo();
    scenarioAnswer($r, $raw, $save);
} catch (OfficeProblem $e) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => $e->key, 'params' => $e->params]], $raw, $save);
} catch (AgentAway $e) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => 'agent_away', 'detail' => $e->getMessage()]], $raw, $save);
} catch (AgentBusy $e) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => 'agent_busy']], $raw, $save);
} catch (AgentRestarted $e) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => 'agent_restarted']], $raw, $save);
} catch (Throwable $e) {
    scenarioAnswer(['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]], $raw, $save);
}
