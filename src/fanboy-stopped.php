<?php
declare(strict_types=1);

/*
 * Fanboy while the array isn't started — the web side's small part («always on», 2026-11-22).
 *
 * With the array stopped there is no data folder and no agent: his page has nothing to show and no action reaches
 * anybody. His control steers on all the same (it runs without the data folder), and the user must be able to see that
 * and to take the fans away from him:
 *
 *   officeFanboyStopped()          what is to be said then, from his files in RAM only (the control's status, the mark):
 *                                  the office's «array stopped» notice gets a line (agentInfo()'s `still`), his Dashboard
 *                                  tile its note and its button (dashboard-fanboy.php)
 *   officeFanboyStoppedHandBack()  «give the fans back to the mainboard» — the ONE thing the user can ask of him then.
 *                                  The web side never touches the host: it only leaves a mark in his RAM folder
 *                                  (handback.json, as it leaves secrets and a first-start request in RAM); the control
 *                                  sees it within a round and gives every fan back, the night shift copies it to the
 *                                  flash, the agent's next start makes it true in his settings (agent/desks/
 *                                  fanboy-night.php). With the agent at work the click goes to the agent as ever.
 */

const FANBOY_STOPPED_FRESH = 60;        // seconds: a control's status older than this is no running control (it writes every round)

/**
 * What there is to say of him while the array isn't started — null: nothing (the array runs, the agent works, or
 * nothing of his runs and nothing was asked). {desk, runs: his control runs, steers: … and steers, handed_back: the
 * mark stands}
 */
function officeFanboyStopped(?array $agent = null, ?string $run = null, ?int $now = null): ?array
{
    $agent ??= agentInfo();
    if (!empty($agent['running']) || !is_array($agent['no_data'] ?? null) || ($agent['no_data']['array'] ?? '') === 'Started') {
        return null;
    }
    $dir = ($run ?? officeRunDir()) . '/fanboy';
    $status = officeReadJson("$dir/control.json");
    $runs = is_array($status) && in_array($status['phase'] ?? null, ['start', 'run', 'hold', 'wait_chip'], true)
        && is_int($status['time'] ?? null) && ($now ?? time()) - $status['time'] <= FANBOY_STOPPED_FRESH;
    clearstatcache(true, "$dir/handback.json");
    $asked = @lstat("$dir/handback.json") !== false;
    if (!$runs && !$asked) {
        return null;
    }
    return ['desk' => 'fanboy', 'runs' => $runs, 'steers' => $runs && ($status['who'] ?? null) === 'fanboy', 'handed_back' => $asked];
}

/**
 * The mark «give the fans back to the mainboard» into his RAM folder — only while the array isn't started and nobody
 * else can hear it, only while his control runs, only into a real folder of root's own (never made here, never through
 * a link): a new 0600 file, renamed into place.
 */
function officeFanboyStoppedHandBack(?array $agent = null, ?string $run = null): array
{
    $s = officeFanboyStopped($agent, $run);
    if ($s === null || (!$s['runs'] && !$s['handed_back'])) {
        return ['ok' => false, 'error' => ['key' => 'bad_request', 'params' => []]];
    }
    if ($s['handed_back']) {
        return ['ok' => true, 'handed_back' => true];           // asked already: the mark stands
    }
    $dir = ($run ?? officeRunDir()) . '/fanboy';
    clearstatcache(true, $dir);
    $st = @lstat($dir);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : -1;
    if (!$st || ($st['mode'] & 0170000) !== 0040000 || ($st['mode'] & 0077) !== 0 || $st['uid'] !== $me) {
        return ['ok' => false, 'error' => ['key' => 'bad_request', 'params' => []]];
    }
    $tmp = "$dir/." . bin2hex(random_bytes(8)) . '.tmp';
    $old = umask(0177);
    $f = @fopen($tmp, 'x');
    umask($old);
    $text = (string) json_encode(['v' => 1, 'at' => time()]);
    $ok = $f !== false && @fwrite($f, $text) === strlen($text);
    $ok = $f !== false && fclose($f) && $ok && @rename($tmp, "$dir/handback.json");
    if (!$ok) {
        @unlink($tmp);
        return ['ok' => false, 'error' => ['key' => 'bad_request', 'params' => []]];
    }
    return ['ok' => true, 'handed_back' => true];
}
