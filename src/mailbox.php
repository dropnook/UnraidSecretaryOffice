<?php
declare(strict_types=1);

/*
 * Talking to the agent on the host.
 *
 * The container may not call zfs, docker & co. itself. It drops a request
 * into data/mailbox/ (<id>.request), the agent picks it up within ~150 ms
 * and puts <id>.response next to it.
 */

final class AgentAway extends RuntimeException {}
final class AgentBusy extends RuntimeException {}

/** Is the agent alive? It touches agent.json every 20 seconds. */
function agentInfo(): array
{
    $file = OFFICE_DATA . '/agent.json';
    clearstatcache(true, $file);
    $info = readJsonFile($file) ?? [];
    $pulse = (int) @filemtime($file);
    $info['pulse'] = $pulse ?: null;
    $info['running'] = !empty($info['running']) && $pulse > time() - 70 && is_dir(OFFICE_DATA . '/mailbox');
    return $info;
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

    $raw = json_encode(['action' => $action] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $request)) {
        @unlink($tmp);
        throw new AgentAway('mailbox_write');
    }

    $deadline = microtime(true) + $wait;
    $picked = false;
    while (microtime(true) < $deadline) {
        usleep(50000);
        clearstatcache();
        if (is_file($response)) {
            $text = (string) @file_get_contents($response);
            @unlink($response);
            $answer = json_decode($text, true);
            if (!is_array($answer)) {
                throw new RuntimeException('unreadable answer from the agent');
            }
            return $answer;
        }
        $picked = $picked || !is_file($request);
    }
    if (!$picked && @unlink($request)) {
        throw new AgentAway('not_picked_up');
    }
    throw new AgentBusy('still_working');
}
