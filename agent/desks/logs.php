<?php
declare(strict_types=1);

/*
 * Ms. Protocolli — reads every log out loud. Understanding them is not her
 * job: she shows them, follows them like tail -f and lets the page filter.
 *
 * Only sources from a fixed list (logsSources()): the office's own logs,
 * Unraid's logs in /var/log (RAM — nothing wakes up), the flash's parity
 * history, User Scripts outputs and the containers' docker logs. A request
 * names a source id, never a path. Read only.
 */

const LOGS_MAX_LINES = 10000;
const LOGS_MAX_BYTES = 8 * 1024 * 1024;     // read at most this much from the end of a file
const LOGS_MAX_CHUNK = 1024 * 1024;         // and at most this much new text per follow step

desk('logs', [
    'fit'     => fn (): array => fit(true, 'yes'),
    'actions' => [
        'refresh' => fn (array $r) => ['ok' => true, 'state' => logsScan()],
        'read'    => fn (array $r) => logsRead(textField($r, 'source'), (int) ($r['lines'] ?? 500),
                                               isset($r['offset']) ? (int) $r['offset'] : null),
    ],
]);

/**
 * Every source that can be read here: id => [group, label, kind, target, label param].
 * kind "file" (target: path) or "docker" (target: container) or "dmesg".
 */
function logsSources(): array
{
    $s = [];
    $file = function (string $id, string $group, string $label, string $path, string $param = '') use (&$s): void {
        if (is_file($path)) {
            $s[$id] = ['group' => $group, 'label' => $label, 'kind' => 'file', 'target' => $path, 'param' => $param];
        }
    };

    // the office
    $file('agent', 'office', 'agent', AGENT_LOG);
    $file('agent.1', 'office', 'agent_old', AGENT_LOG . '.1');
    $backupLogs = glob(DATA_DIR . '/unraid-backup/logs/*.log') ?: [];
    usort($backupLogs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice(array_filter($backupLogs, fn ($f) => basename($f) !== 'latest.log'), 0, 30) as $f) {
        $file('backup:' . basename($f), 'office', 'backup_log', $f, basename($f, '.log'));
    }
    $file('embycache', 'office', 'embycache', DATA_DIR . '/embycache/logs/embycache.log');
    $file('embycache-run', 'office', 'embycache_run', DATA_DIR . '/embycache/office-output.txt');
    foreach (['UnraidSecretaryOffice', 'UnraidSecretaryOffice-Agent'] as $c) {
        $s["container:$c"] = ['group' => 'office', 'label' => 'container', 'kind' => 'docker', 'target' => $c, 'param' => $c];
    }

    // Unraid
    $file('syslog', 'unraid', 'syslog', '/var/log/syslog');
    $file('syslog.1', 'unraid', 'syslog_old', '/var/log/syslog.1');
    $s['dmesg'] = ['group' => 'unraid', 'label' => 'dmesg', 'kind' => 'dmesg', 'target' => '', 'param' => ''];
    $file('docker', 'unraid', 'docker', '/var/log/docker.log');
    $file('libvirt', 'unraid', 'libvirt', '/var/log/libvirt/libvirtd.log');
    foreach (glob('/var/log/libvirt/qemu/*.log') ?: [] as $f) {
        $file('vm:' . basename($f, '.log'), 'unraid', 'vm', $f, basename($f, '.log'));
    }
    $file('nginx', 'unraid', 'nginx', '/var/log/nginx/error.log');
    foreach (glob('/var/log/samba/log.*') ?: [] as $f) {
        $file('samba:' . basename($f), 'unraid', 'samba', $f, basename($f));
    }
    $file('php', 'unraid', 'php', '/var/log/phplog');
    $file('graphql', 'unraid', 'api', '/var/log/graphql-api.log');
    $file('mail', 'unraid', 'mail', '/var/log/maillog');
    $file('compose', 'unraid', 'compose', '/var/log/compose.manager.log');
    $file('parity', 'unraid', 'parity', '/boot/config/parity-checks.log');

    // User Scripts: what each script printed last
    foreach (glob('/tmp/user.scripts/tmpScripts/*/log.txt') ?: [] as $f) {
        $name = basename(dirname($f));
        $file('us:' . $name, 'userscripts', 'user_script', $f, $name);
    }

    // every container's docker logs
    foreach (houseContainers() as $c) {
        $s['container:' . $c['name']] ??= ['group' => 'containers', 'label' => 'container', 'kind' => 'docker', 'target' => $c['name'], 'param' => $c['name']];
    }
    return $s;
}

/** The list for the page (no paths of anything but files) and a word for the reception */
function logsScan(): array
{
    $list = [];
    foreach (logsSources() as $id => $src) {
        $list[] = ['id' => $id, 'group' => $src['group'], 'label' => $src['label'], 'param' => $src['param'],
                   'path' => $src['kind'] === 'file' ? $src['target'] : null,
                   'size' => $src['kind'] === 'file' ? (int) @filesize($src['target']) : null,
                   'time' => $src['kind'] === 'file' ? (@filemtime($src['target']) ?: null) : null];
    }
    // how loud is the syslog lately? (the last 1000 lines; she counts, she doesn't judge)
    $errors = $warnings = 0;
    foreach (logsTail('/var/log/syslog', 1000) as $line) {
        $level = logsLevel($line);
        $errors += $level === 'error' ? 1 : 0;
        $warnings += $level === 'warn' ? 1 : 0;
    }
    $state = ['time' => time(), 'sources' => $list, 'syslog' => ['errors' => $errors, 'warnings' => $warnings]];
    writeAtomic(deskFile('logs'), jsonEncode($state));
    return $state;
}

/** error / warn / '' — the same words the page colours (she doesn't know what they mean) */
function logsLevel(string $line): string
{
    if (preg_match('/\b(error|err|fail(ed|ure)?|fatal|panic|crit(ical)?|emerg|alert|segfault|denied|oops|call trace|i\/o error)\b/i', $line)) {
        return 'error';
    }
    return preg_match('/\b(warn(ing)?|timeout|timed out|retry|retrying)\b/i', $line) ? 'warn' : '';
}

/** The last $n lines of a file, reading at most LOGS_MAX_BYTES from its end */
function logsTail(string $path, int $n): array
{
    $size = (int) @filesize($path);
    $h = @fopen($path, 'r');
    if (!$h || !$size) {
        return [];
    }
    $want = min($size, LOGS_MAX_BYTES, max(65536, $n * 400));
    fseek($h, $size - $want);
    $text = (string) fread($h, $want);
    fclose($h);
    $lines = explode("\n", rtrim($text, "\n"));
    if ($want < $size) {
        array_shift($lines);            // started in the middle of a line
    }
    return array_slice($lines, -$n);
}

/**
 * Read a source: the last $lines lines, or — with $offset for a file — only
 * what was added since (tail -f). A file that got shorter was rotated: then
 * the last lines again, with "reset".
 */
function logsRead(string $id, int $lines, ?int $offset): array
{
    $src = logsSources()[$id] ?? null;
    if (!$src) {
        throw new Problem('logs_unknown', ['source' => $id]);
    }
    $lines = max(10, min(LOGS_MAX_LINES, $lines));
    $clean = fn (string $s) => preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $s);      // colour codes from containers

    if ($src['kind'] === 'file') {
        clearstatcache(true, $src['target']);
        $size = (int) @filesize($src['target']);
        if ($offset !== null && $offset <= $size) {
            $h = @fopen($src['target'], 'r');
            if (!$h) {
                throw new Problem('logs_unreadable', ['source' => $id]);
            }
            $len = min($size - $offset, LOGS_MAX_CHUNK);
            fseek($h, $size - $len);      // more than a chunk? then the newest part
            $text = $len > 0 ? (string) fread($h, $len) : '';
            fclose($h);
            // only whole lines; the rest comes with the next step
            $cut = strrpos($text, "\n");
            $text = $cut === false ? '' : substr($text, 0, $cut + 1);
            $new = $text === '' ? [] : explode("\n", rtrim($text, "\n"));
            return ['ok' => true, 'lines' => array_map($clean, $new), 'offset' => $size - $len + strlen($text),
                    'size' => $size, 'time' => @filemtime($src['target']) ?: null, 'follow' => 'append'];
        }
        $tail = logsTail($src['target'], $lines);
        return ['ok' => true, 'lines' => array_map($clean, $tail), 'offset' => $size, 'size' => $size,
                'time' => @filemtime($src['target']) ?: null, 'follow' => 'append', 'reset' => $offset !== null];
    }

    if ($src['kind'] === 'dmesg') {
        [$exit, $out, $err] = run(['dmesg', '-T'], 20);
        if ($exit !== 0) {
            throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
        }
        $all = explode("\n", rtrim($out, "\n"));
        return ['ok' => true, 'lines' => array_map($clean, array_slice($all, -$lines)), 'follow' => 'replace'];
    }

    // docker logs: stdout and stderr, merged by their timestamps
    [$exit, $out, $err] = run(['docker', 'logs', '--tail', (string) $lines, '--timestamps', $src['target']], 30);
    if ($exit !== 0 && trim($out) === '') {
        throw new Problem('logs_unreadable', ['source' => $id, 'detail' => trim($err)]);
    }
    $all = array_merge(array_filter(explode("\n", $out), 'strlen'), array_filter(explode("\n", $err), 'strlen'));
    sort($all, SORT_STRING);
    return ['ok' => true, 'lines' => array_map($clean, array_slice($all, -$lines)), 'follow' => 'replace'];
}
