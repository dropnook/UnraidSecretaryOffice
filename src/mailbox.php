<?php
declare(strict_types=1);

/*
 * Talking to the agent on the host.
 *
 * The web side never calls zfs, docker & co. itself, even though Unraid's
 * php-fpm runs as root: it leaves that to the agent. It drops a request
 * into data/mailbox/ (<id>.request), the agent picks it up within ~150 ms
 * and puts <id>.response next to it.
 */

final class AgentAway extends RuntimeException {}
final class AgentBusy extends RuntimeException {}
/** The agent restarted (or stopped) while a request waited: what it hadn't answered is lost */
final class AgentRestarted extends RuntimeException {}

/** Is the agent alive? It touches agent.json every 20 seconds. */
function agentInfo(): array
{
    $file = OFFICE_DATA . '/agent.json';
    clearstatcache(true, $file);
    $info = officeReadJson($file) ?? [];
    $pulse = (int) @filemtime($file);
    $info['pulse'] = $pulse ?: null;
    $info['running'] = !empty($info['running']) && $pulse > time() - 70 && is_dir(OFFICE_DATA . '/mailbox');
    if (!$info['running'] && !is_dir(OFFICE_DATA)) {
        // the data folder comes with the array: say why nobody answers
        $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
        $info['no_data'] = ['array' => (string) ($var['fsState'] ?? ''), 'dir' => OFFICE_DATA];
    }
    unset($info['night']);
    if (!$info['running'] && ($night = officeNightShift()) !== null) {
        $info['night'] = $night;        // the array isn't started: the night watchman keeps watch from RAM and the flash
    }
    return $info;
}

/**
 * The night watchman's night shift (`php agent.php nightshift`, while the array isn't started — agent/agent.php
 * nightShift(), watchmanNightRound()): on while the process that holds its lock (WATCH_NIGHT_LOCK, which carries its
 * pid) lives, and keeping watch once its first round wrote its state (WATCH_NIGHT_DIR/state.json: `night` with since,
 * rounds, new — what is new in the night and open). RAM only, nothing under /mnt. Never takes the lock itself: the
 * night shift's own non-blocking lock at its start must never meet a look from here.
 *
 * @return array{since: int, rounds: int, new: int, last: ?int}|null  null: off (no lock; its process ended, the lock stays
 *                                                                     behind; its pid now another process's) or no watch
 *                                                                     (no mirror, no round yet: no state)
 */
function officeNightShift(?string $run = null): ?array
{
    $run ??= officeRunDir();
    $pid = trim((string) @file_get_contents("$run/nightshift.lock", false, null, 0, 16));
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $pid) || !str_contains((string) @file_get_contents("/proc/$pid/cmdline", false, null, 0, 4096), "agent.php\0nightshift")) {
        return null;
    }
    $st = officeReadJson("$run/nightshift/state.json");
    $night = $st['night'] ?? null;
    if (!is_array($night) || (int) ($st['hired'] ?? 0) <= 0 || (int) ($night['since'] ?? 0) <= 0 || isset($night['until'])) {
        return null;
    }
    $last = (int) ($st['round']['time'] ?? 0);
    return ['since' => (int) $night['since'], 'rounds' => max(0, (int) ($night['rounds'] ?? 0)), 'new' => max(0, (int) ($night['new'] ?? 0)),
            'last' => $last > 0 ? $last : null];
}

function askAgent(string $action, array $data = [], float $wait = 20.0): array
{
    $mailbox = OFFICE_DATA . '/mailbox';
    if (!is_dir($mailbox) || !is_writable($mailbox)) {
        throw new AgentAway('mailbox_missing');
    }
    $id = bin2hex(random_bytes(16));
    $tmp = "$mailbox/.$id.tmp";
    $request = "$mailbox/$id.request";
    $response = "$mailbox/$id.response";

    // who will answer: the agent of agent.json (pid and start time) — read before the request is there
    $who = agentIdentity(officeReadJson(OFFICE_DATA . '/agent.json'));
    $raw = json_encode(['action' => $action] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $request)) {
        @unlink($tmp);
        throw new AgentAway('mailbox_write');
    }

    $deadline = microtime(true) + $wait;
    $picked = false;
    $round = 0;
    while (microtime(true) < $deadline) {
        usleep(50000);
        clearstatcache();
        if (is_file($response)) {
            return agentAnswer($response);
        }
        $picked = $picked || !is_file($request);
        if ($who !== null && ++$round % 10 === 0) {
            // every half second: still the same agent? A new one empties the mailbox when it starts
            // (setUp()), a stopped one answers nothing more — what was waiting is lost
            $now = agentIdentity(officeReadJson(OFFICE_DATA . '/agent.json'));
            if ($now === null || ($now['id'] === $who['id'] && ($now['running'] || !$who['running']))) {
                continue;
            }
            clearstatcache();
            if (is_file($response)) {
                return agentAnswer($response);
            }
            if (is_file($request)) {
                if ($now['id'] !== $who['id'] && $now['running']) {
                    $who = $now;        // came after the new one emptied its mailbox (agent.json is written after that): it answers
                    continue;
                }
                if (!@unlink($request)) {
                    continue;           // taken this very moment: the next look decides
                }
            }
            throw new AgentRestarted('restarted');
        }
    }
    if (!$picked && @unlink($request)) {
        throw new AgentAway('not_picked_up');
    }
    throw new AgentBusy('still_working');
}

/**
 * Which agent process wrote agent.json — its pid and start time (a restart in place keeps the pid,
 * never the start time) — and whether it said it runs; null when it can't tell.
 *
 * @return array{id: string, running: bool}|null
 */
function agentIdentity(?array $info): ?array
{
    $pid = $info['pid'] ?? null;
    $started = $info['started'] ?? null;
    if (!is_int($pid) || !is_int($started) || $pid <= 1) {
        return null;
    }
    return ['id' => "$pid:$started", 'running' => !empty($info['running'])];
}

function agentAnswer(string $response): array
{
    $text = (string) @file_get_contents($response);
    @unlink($response);
    $answer = json_decode($text, true);
    if (!is_array($answer)) {
        throw new RuntimeException('unreadable answer from the agent');
    }
    return $answer;
}
