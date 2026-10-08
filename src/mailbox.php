<?php
declare(strict_types=1);

/*
 * Talking to the agent on the host.
 *
 * The web side never calls zfs, docker & co. itself, even though Unraid's
 * php-fpm runs as root: it leaves that to the agent. It drops a request
 * into data/mailbox/ (<id>.request) and rings the agent's doorbell (a FIFO
 * in its RAM folder: agentRing()); the agent picks the request up at once
 * (without the doorbell within ~150 ms) and puts <id>.response next to it.
 */

final class AgentAway extends RuntimeException {}
final class AgentBusy extends RuntimeException {}
/** The agent restarted (or stopped) while a request waited: what it hadn't answered is lost */
final class AgentRestarted extends RuntimeException {}

/**
 * Who is at work, and since when it gave a sign of life: the agent's heartbeat in RAM (agent.json in officeRunDir(),
 * touched every 20 seconds; agent/agent.php writeInfo()) — or its copy in the data folder, which an older agent (up to
 * 1.32) touched instead: the newer of the two counts (a deploy's first seconds, a downgrade).
 *
 * @return array{0: ?array, 1: int}  its content (null: unreadable) and its mtime (0: none)
 */
function agentRecord(): array
{
    $best = [null, 0];
    foreach ([officeRunDir() . '/agent.json', OFFICE_DATA . '/agent.json'] as $file) {
        clearstatcache(true, $file);
        $pulse = (int) @filemtime($file);
        if ($pulse > $best[1]) {
            $best = [officeReadJson($file), $pulse];
        }
    }
    return $best;
}

/** Is the agent alive? Its heartbeat is at most 70 s old (agentRecord()) and its mailbox there. */
function agentInfo(): array
{
    [$info, $pulse] = agentRecord();
    $info ??= [];
    $info['pulse'] = $pulse ?: null;
    $info['running'] = agentUp($info, $pulse) && is_dir(OFFICE_DATA . '/mailbox');
    if (!$info['running'] && !is_dir(OFFICE_DATA)) {
        // the data folder comes with the array: say why nobody answers
        $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
        $info['no_data'] = ['array' => (string) ($var['fsState'] ?? ''), 'dir' => OFFICE_DATA_USER];
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

/** How long a request waits for an agent the heartbeat calls away before it is taken back (agent_away): a restart after a
 *  code change, or agent.sh's supervisor starting it again, takes about a second */
const AGENT_AWAY_GRACE = 4.0;

/**
 * Is the agent at work by its heartbeat (agentRecord())? It said it runs and gave a sign of life within 70 s — the same
 * rule as agentInfo()'s `running`.
 */
function agentUp(?array $info, int $pulse): bool
{
    return !empty($info['running']) && $pulse > time() - 70;
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

    // who will answer: the agent of the heartbeat (pid and start time) — read before the request is there
    $who = agentIdentity(agentRecord()[0]);
    $raw = json_encode(['action' => $action] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $request)) {
        @unlink($tmp);
        throw new AgentAway('mailbox_write');
    }
    agentRing();

    // the answer: the agent starts at once, a state it has at hand is there in a few ms — looked for after 2, 4 and
    // 8 ms, then every 10 ms for the first two seconds, then every 50 ms (one stat each, on the pool directly for an
    // exclusive share)
    $start = microtime(true);
    $deadline = $start + $wait;
    $nextLook = $start + 0.5;
    $pause = 2000;
    while (($t = microtime(true)) < $deadline) {
        usleep($pause);
        $pause = $t - $start < 2 ? min(10000, $pause * 2) : 50000;
        clearstatcache();
        if (is_file($response)) {
            return agentAnswer($response);
        }
        if (microtime(true) < $nextLook) {
            continue;
        }
        // every half second: still the same agent? A new one empties the mailbox when it starts
        // (setUp()), a stopped one answers nothing more — what was waiting is lost
        $nextLook = microtime(true) + 0.5;
        [$info, $pulse] = agentRecord();
        $now = agentIdentity($info);
        if ($who !== null && $now !== null && !($now['id'] === $who['id'] && ($now['running'] || !$who['running']))) {
            clearstatcache();
            if (is_file($response)) {
                return agentAnswer($response);
            }
            if (is_file($request)) {
                if ($now['id'] !== $who['id'] && $now['running']) {
                    $who = $now;        // came after the new one emptied its mailbox (its heartbeat is written after that): it answers
                    continue;
                }
                if (!@unlink($request)) {
                    continue;           // taken this very moment: the next look decides
                }
            }
            throw new AgentRestarted('restarted');
        }
        // away (stopped, the array stopping, killed — its heartbeat says so or went stale): nobody will take the request.
        // After a short grace it is taken back and said at once, never a wait of up to ten minutes (a php-fpm worker each)
        if (microtime(true) - $start >= AGENT_AWAY_GRACE && !agentUp($info, $pulse)) {
            clearstatcache();
            if (is_file($response)) {
                return agentAnswer($response);
            }
            if (@unlink($request)) {
                throw new AgentAway('not_running');
            }
            // taken this very moment: it works on it after all — its answer is waited for
        }
    }
    if (@unlink($request)) {
        throw new AgentAway('not_picked_up');      // still there: nobody took it (taken, it is gone — unlink fails)
    }
    throw new AgentBusy('still_working');
}

/**
 * Rings the agent's doorbell after a request was dropped, so it looks at once instead of at its next round (≤ 150 ms):
 * one byte into the FIFO the agent made in its RAM folder (agent/agent.php doorbellOpen()). Of the agent's files the
 * web side writes that one only — never a signal, never a process. Only a FIFO of the web server's own user (root, like the
 * agent) that nobody else may open, never through a link (lstat, then the open handle's fstat: the same inode);
 * opened for reading and writing, so it never waits for a reader, and written non-blocking — no doorbell, no agent
 * reading it, a full one: nothing happens, the agent's next round finds the request as before.
 */
function agentRing(?string $bell = null): bool
{
    $bell ??= officeRunDir() . '/doorbell';
    clearstatcache(true, $bell);
    $st = @lstat($bell);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    if (!$st || ($st['mode'] & 0170000) !== 0010000 || $st['uid'] !== $me || ($st['mode'] & 0077)) {
        return false;
    }
    $h = @fopen($bell, 'r+');
    if (!$h) {
        return false;
    }
    $fs = @fstat($h);
    $ok = $fs && $fs['dev'] === $st['dev'] && $fs['ino'] === $st['ino'] && stream_set_blocking($h, false) && @fwrite($h, "\n") === 1;
    fclose($h);
    return $ok;
}

/**
 * Which agent process wrote the heartbeat — its pid and start time (a restart in place keeps the pid,
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
