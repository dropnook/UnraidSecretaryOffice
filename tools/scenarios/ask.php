<?php
// tools/scenarios/ask.php — a scenario's button press: the request the page sends, through the plugin's own
// src/mailbox.php askAgent() (mailbox + doorbell), with the checks src/api.php makes before it (the action's shape, the
// desk known, hired unless `refresh` — the page's fresh look is `<desk>.refresh`; `office.ping` is the agent's own).
// office.hire / office.fire go where the page's go: officeStaffAction() on the web side. Never a desk function directly.
// Secrets never travel this way (the page stashes them in RAM: not for scenarios).
//
//   php ask.php [--wait <s>] [--raw] [--every <s>] [--save-post <file>] <desk.action> [json]
//
// Prints the answer as one JSON line — without `state` (unless --raw) — and the errors api.php would answer as
// {ok:false, error:{key}} (agent_away, agent_busy, agent_restarted, not_hired, unknown_action, internal).
// --save-post <file>: the answer (without state and agent) also as a canned dialog answer for the page tool
// (tests/ui/states/post/<desk>.<action>.json). Exit 0 ok:true · 1 ok:false · 2 usage or no plugin.
// --every <s>: asks again and again, <s> after each answer, until killed — one line per answer with t0 (asked) and t
// (answered), microtime; one resident process (2026-10-25: while a 4 GiB file moved, a new `php` per look waited up to
// 3.5 s to start — its pages read back from the flash — while one already running answered within 0.8 s).
$P = getenv('USO_PLUGIN') ?: '/usr/local/emhttp/plugins/unraid-secretary-office';
$args = array_slice($argv, 1);
$wait = 60.0;
$raw = false;
$save = null;
$every = null;
while ($args && str_starts_with($args[0], '--')) {
    $o = array_shift($args);
    if ($o === '--raw') {
        $raw = true;
    } elseif ($o === '--wait' && $args && is_numeric($args[0])) {
        $wait = max(1.0, (float) array_shift($args));
    } elseif ($o === '--every' && $args && is_numeric($args[0])) {
        $every = max(0.0, (float) array_shift($args));
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

/** --every: the same request again and again in this one process, each answer a line with t0 and t; never returns */
function scenarioRepeat(string $action, array $data, float $wait, float $every, bool $raw): never
{
    while (true) {
        $t0 = microtime(true);
        try {
            $r = askAgent($action, $data, $wait);
        } catch (AgentAway $e) {
            $r = ['ok' => false, 'error' => ['key' => 'agent_away', 'detail' => $e->getMessage()]];
        } catch (AgentBusy) {
            $r = ['ok' => false, 'error' => ['key' => 'agent_busy']];
        } catch (AgentRestarted) {
            $r = ['ok' => false, 'error' => ['key' => 'agent_restarted']];
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => ['key' => 'internal', 'params' => ['detail' => $e->getMessage()]]];
        }
        if (!$raw) {
            unset($r['state']);
        }
        echo json_encode(['t0' => $t0, 't' => microtime(true)] + $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
        usleep((int) ($every * 1e6));
    }
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
    if ($every !== null) {
        scenarioRepeat($action, $data, $wait, $every, $raw);
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
