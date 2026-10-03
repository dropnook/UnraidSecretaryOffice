<?php
declare(strict_types=1);

/*
 * Shared helpers for the agent and all desks: running commands without a
 * shell, writing files atomically, logging, reading Unraid's config format.
 */

/**
 * An expected failure that the web UI shows to the user. $key is looked up in
 * the language files (errors.<key>), $params fill its {placeholders}.
 */
final class Problem extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct($key . ($params ? ' ' . json_encode($params, JSON_UNESCAPED_SLASHES) : ''));
    }

    public function toArray(): array
    {
        return ['key' => $this->key, 'params' => $this->params];
    }
}

// ===================================================================== desks

/**
 * A desk (secretary) registers what it can do:
 *   actions  name => fn(array $request): array   reachable as "<desk>.<name>"
 *   start    fn(): void                           once, when the agent is ready
 *   tick     fn(): void                           every loop (~150 ms), keep it cheap
 */
function desk(string $id, array $definition): void
{
    $GLOBALS['desks'][$id] = $definition + ['actions' => [], 'start' => null, 'tick' => null];
}

function desks(): array
{
    return $GLOBALS['desks'] ?? [];
}

/** State file of a desk in the data folder: data/<desk>.json */
function deskFile(string $desk): string
{
    return DATA_DIR . "/$desk.json";
}

function readJson(string $file): ?array
{
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

// ===================================================================== request fields

function textField(array $request, string $field): string
{
    $value = $request[$field] ?? null;
    if (!is_string($value) || $value === '' || strlen($value) > 1000) {
        throw new Problem('missing_field', ['field' => $field]);
    }
    return $value;
}

/** @return list<string> */
function idList(array $request, string $field): array
{
    $value = $request[$field] ?? null;
    if (!is_array($value) || !$value || count($value) > 10000) {
        throw new Problem('no_selection');
    }
    $ids = [];
    foreach ($value as $id) {
        if (!is_string($id) || $id === '' || strlen($id) > 1000) {
            throw new Problem('invalid_selection');
        }
        $ids[$id] = true;
    }
    return array_keys($ids);
}

// ===================================================================== commands

function bin(string $name): ?string
{
    static $cache = [];
    if (!array_key_exists($name, $cache)) {
        $cache[$name] = null;
        foreach (['/usr/local/sbin', '/usr/sbin', '/sbin', '/usr/local/bin', '/usr/bin', '/bin'] as $dir) {
            if (is_executable("$dir/$name")) {
                $cache[$name] = "$dir/$name";
                break;
            }
        }
    }
    return $cache[$name];
}

/**
 * Starts all commands at once (no shell involved) and waits for all of them.
 *
 * @param array<int|string, list<string>> $commands
 * @return array<int|string, array{0:int, 1:string, 2:string}>  exit code, stdout, stderr
 */
function runAll(array $commands, int $timeout = 120): array
{
    $env = ['PATH' => '/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C', 'HOME' => '/root'];
    $running = [];
    $results = [];
    foreach ($commands as $key => $command) {
        $pipes = [];
        $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/', $env);
        if (!is_resource($process)) {
            $results[$key] = [127, '', 'could not start ' . ($command[0] ?? '?')];
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $running[$key] = ['process' => $process, 'pipes' => $pipes, 1 => '', 2 => ''];
    }

    $deadline = microtime(true) + $timeout;
    while ($running) {
        $read = [];
        foreach ($running as $r) {
            foreach ([1, 2] as $fd) {
                if (!feof($r['pipes'][$fd])) {
                    $read[] = $r['pipes'][$fd];
                }
            }
        }
        if ($read) {
            $w = $e = null;
            @stream_select($read, $w, $e, 0, 200000);
        }
        foreach (array_keys($running) as $key) {
            foreach ([1, 2] as $fd) {
                if (!feof($running[$key]['pipes'][$fd])) {
                    $chunk = fread($running[$key]['pipes'][$fd], 65536);
                    if ($chunk !== false) {
                        $running[$key][$fd] .= $chunk;
                    }
                }
            }
            $done = feof($running[$key]['pipes'][1]) && feof($running[$key]['pipes'][2]);
            $killed = !$done && microtime(true) > $deadline;
            if (!$done && !$killed) {
                continue;
            }
            if ($killed) {
                proc_terminate($running[$key]['process'], 9);
                $running[$key][2] .= "\naborted after {$timeout} s";
            }
            fclose($running[$key]['pipes'][1]);
            fclose($running[$key]['pipes'][2]);
            $code = proc_close($running[$key]['process']);
            $results[$key] = [$killed ? 124 : $code, $running[$key][1], $running[$key][2]];
            unset($running[$key]);
        }
    }
    return $results;
}

/** @return array{0:int, 1:string, 2:string} */
function run(array $command, int $timeout = 120): array
{
    return runAll([$command], $timeout)[0];
}

/** @return list<list<string>> tab-separated lines */
function rows(string $text): array
{
    $rows = [];
    foreach (explode("\n", $text) as $line) {
        if ($line !== '') {
            $rows[] = explode("\t", $line);
        }
    }
    return $rows;
}

function num(string $value): int
{
    return ctype_digit($value) ? (int) $value : 0;
}

function hostname(): string
{
    return trim((string) @file_get_contents('/proc/sys/kernel/hostname')) ?: (gethostname() ?: 'Unraid');
}

function under(string $path, string $root): bool
{
    $root = rtrim($root, '/');
    return $path === $root || str_starts_with($path, "$root/");
}

// ===================================================================== files

function jsonEncode(array $data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

/** Writes via tmp + rename so the web UI never reads half a file. */
function writeAtomic(string $path, string $content, int $mode = 0644, int $uid = FILE_UID, int $gid = FILE_GID): void
{
    $tmp = dirname($path) . '/.' . basename($path) . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $content) === false) {
        throw new RuntimeException("cannot write $path");
    }
    @chmod($tmp, $mode);
    @chown($tmp, $uid);
    @chgrp($tmp, $gid);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("cannot replace $path");
    }
}

function logLine(string $text): void
{
    if (!is_dir(DATA_DIR)) {
        return;
    }
    clearstatcache(true, AGENT_LOG);
    if (@filesize(AGENT_LOG) > LOG_MAX) {
        @rename(AGENT_LOG, AGENT_LOG . '.1');
    }
    $new = !file_exists(AGENT_LOG);
    @file_put_contents(AGENT_LOG, date('Y-m-d H:i:s') . '  ' . str_replace("\n", ' | ', trim($text)) . "\n", FILE_APPEND);
    if ($new) {
        @chown(AGENT_LOG, FILE_UID);
        @chgrp(AGENT_LOG, FILE_GID);
    }
}

/**
 * Unraid's config files: key="value" lines, optionally in [sections] or
 * ["quoted sections"]. parse_ini_file() would turn "yes" into "1", so no.
 *
 * @return array<string, string>|array<string, array<string, string>>
 */
function readCfg(string $file, bool $sections = false): array
{
    $result = [];
    $section = null;
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if ($sections && preg_match('/^\[\s*"?(.*?)"?\s*\]$/', $line, $m)) {
            $section = $m[1];
            $result[$section] ??= [];
            continue;
        }
        if (!preg_match('/^([A-Za-z0-9_.\-]+)\s*=\s*(.*)$/', $line, $m)) {
            continue;
        }
        $value = $m[2];
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }
        if ($sections && $section !== null) {
            $result[$section][$m[1]] = $value;
        } elseif (!$sections) {
            $result[$m[1]] = $value;
        }
    }
    return $result;
}
