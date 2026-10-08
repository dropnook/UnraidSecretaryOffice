<?php
declare(strict_types=1);

/*
 * The supporter key — a thank-you, never a lock.
 *
 * The office is free and complete: nothing is ever locked, and nothing but
 * the reminders asks this file (backups, restores, the desks never do). A tip
 * with this server's ID in its note gets a supporter key from the maintainer;
 * with a valid one the office stops reminding (the tip jar after hiring, the
 * team lead's one ask) and the team lead shows a small thank-you with the name,
 * its picture the tip's level (coffee, a round, cake, a pay rise — not a rank).
 *
 * Server ID: sha256("uso-supporter:" . strtoupper(GUID)), its first 16 hex
 * digits upper-case as XXXX-XXXX-XXXX-XXXX. The GUID is Unraid's regGUID in
 * /var/local/emhttp/var.ini (flashGUID while that is empty) — the web side
 * reads var.ini anyway (csrf_token, fsState: page.php, mailbox.php); only the
 * hash goes to the page, the GUID never leaves the server.
 *
 * Key: USO1.<b64url(payload)>.<b64url(signature)>, made by the maintainer's support
 * page (OFFICE_SUPPORT_URL) or by hand with tools/supporter-key.sh — the private key
 * never comes near the office. A key for another server has no effect. For whoever
 * makes keys, exactly what officeSupporterCheck() accepts:
 *   - payload: UTF-8 JSON, an object with exactly the keys v, id, name, date in this
 *     order — {"v":1,"id":"XXXX-XXXX-XXXX-XXXX","name":"<name>","date":"YYYY-MM-DD"} —
 *     or with l as a fifth key: {…,"date":"YYYY-MM-DD","l":"cake"};
 *     v the integer 1, id the server ID (16 upper-case hex digits in groups of 4),
 *     name 1–60 characters (not bytes), none of them a control/format character or a
 *     line/paragraph separator, no white space at either end; date a real day;
 *     l the thank-you's level (Benj, 2026-10-08), exactly "round" (a tip from 20),
 *     "cake" (from 50) or "raise" (from 100). Without l the key is «coffee» (any tip,
 *     and every key made before levels) — coffee is never written as l. The level is
 *     signed like the rest, so the office never shows one nobody signed; it only
 *     chooses the plate's picture (☕ ☕☕ 🍰 💐) and unlocks nothing, like the key.
 *     Compact, non-ASCII unescaped is what the tool writes (JSON.stringify() gives the
 *     same); the bytes are never re-encoded here, any valid JSON spelling of that shape counts.
 *   - b64url: RFC 4648 base64url, no padding, canonical (unused bits zero).
 *   - signature: ECDSA P-256 with SHA-256 over the ASCII string "USO1." + the payload's
 *     b64url exactly as transmitted, DER-encoded (SEQUENCE of two INTEGERs, ≤ 72 bytes) —
 *     WebCrypto's sign() returns raw r||s (64 bytes): convert it to DER first.
 *   - the whole key ≤ 1000 characters; white space in it (a key broken across lines
 *     in a mail) is taken out before checking. Checked with openssl_verify().
 * OFFICE_SUPPORTER_PUBKEY (a PEM file) replaces the public key — tests only.
 *
 * data/office/supporter.json (the web side's, like staff.json; 0600):
 *   {"first_seen": <ts>, "key": "USO1…", "key_added": <ts>, "ask": {"later": <n>, "next": <ts>, "never": true}}
 */

const OFFICE_SUPPORTER_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE2p2CR6VnacA3h+TcgYMrVyFczVf0
GBylBXhHiYTCsHOyt5mz2eycn2jnR0K0LeucZ5jyPLRt4gKwCwWTdxzOug==
-----END PUBLIC KEY-----
PEM;

const OFFICE_SUPPORTER_KEY_MAX   = 1000;          // characters of a key (whitespace taken out)
const OFFICE_SUPPORTER_NAME_MAX  = 60;            // characters of the name in it
const OFFICE_SUPPORTER_ID        = '/^[0-9A-F]{4}(?:-[0-9A-F]{4}){3}$/D';
const OFFICE_SUPPORTER_ASK_AFTER = 7 * 86400;     // the team lead's one ask: a week after the office first ran here
const OFFICE_SUPPORTER_ASK_AGAIN = 30 * 86400;    // «Not now»: again in a month …
const OFFICE_SUPPORTER_ASKS      = 3;             // … at most twice more
const OFFICE_SUPPORTER_ACTIONS   = ['office.supporter_set', 'office.supporter_remove', 'office.supporter_ask'];
const OFFICE_SUPPORTER_LEVELS    = ['coffee', 'round', 'cake', 'raise'];   // the thank-you's picture; coffee = no l in the key

/** This server's ID for a supporter key, null when var.ini names no GUID */
function officeServerId(string $varIni = '/var/local/emhttp/var.ini'): ?string
{
    $var = @parse_ini_file($varIni) ?: [];
    foreach (['regGUID', 'flashGUID'] as $k) {
        $guid = trim((string) ($var[$k] ?? ''));
        if ($guid !== '') {
            return officeServerIdOf($guid);
        }
    }
    return null;
}

function officeServerIdOf(string $guid): ?string
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,62}[A-Za-z0-9]$/D', $guid)) {
        return null;
    }
    return implode('-', str_split(strtoupper(substr(hash('sha256', 'uso-supporter:' . strtoupper($guid)), 0, 16)), 4));
}

function officeB64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** base64url without padding — only in its one canonical spelling, else null */
function officeB64urlDecode(string $text): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $text) || strlen($text) % 4 === 1) {
        return null;
    }
    $bin = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $bin !== false && officeB64url($bin) === $text ? $bin : null;
}

function officeSupporterPublicKey(): string
{
    $file = getenv('OFFICE_SUPPORTER_PUBKEY');           // the tests' throw-away key
    return is_string($file) && $file !== '' ? (string) @file_get_contents($file) : OFFICE_SUPPORTER_PUBLIC_KEY;
}

/** A name as a key may carry it: 1–60 printable characters, no space at either end (shown as text only) */
function officeSupporterNameOk(mixed $name): bool
{
    return is_string($name) && $name !== '' && $name === trim($name) && mb_strlen($name) <= OFFICE_SUPPORTER_NAME_MAX
        && preg_match('/^[^\p{C}\p{Zl}\p{Zp}]+$/uD', $name) === 1;
}

/**
 * What a key says, checked strictly: 'valid' (signed, for this server), 'other' (signed, for
 * another server — no effect) or 'invalid' (anything else). Never throws.
 * @return array{state: string, id?: string, name?: string, date?: string, level?: string}
 */
function officeSupporterCheck(string $key, ?string $serverId): array
{
    $bad = ['state' => 'invalid'];
    if (strlen($key) > 4 * OFFICE_SUPPORTER_KEY_MAX) {
        return $bad;
    }
    $key = (string) preg_replace('/\s+/', '', $key);       // pasted from a mail, it may be broken across lines
    if (strlen($key) > OFFICE_SUPPORTER_KEY_MAX || !preg_match('/^USO1\.([A-Za-z0-9_-]{16,800})\.([A-Za-z0-9_-]{8,96})$/D', $key, $m)) {
        return $bad;
    }
    $payload = officeB64urlDecode($m[1]);
    $signature = officeB64urlDecode($m[2]);
    if ($payload === null || $signature === null || strlen($signature) > 72) {     // DER of a P-256 signature: at most 72 bytes
        return $bad;
    }
    $public = @openssl_pkey_get_public(officeSupporterPublicKey());
    $details = $public ? openssl_pkey_get_details($public) : false;
    if (!$details || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
        return $bad;
    }
    if (@openssl_verify('USO1.' . $m[1], $signature, $public, OPENSSL_ALGO_SHA256) !== 1) {
        return $bad;
    }
    $data = json_decode($payload, true, 2);
    if (!is_array($data) || !in_array(array_keys($data), [['v', 'id', 'name', 'date'], ['v', 'id', 'name', 'date', 'l']], true)) {
        return $bad;
    }
    ['v' => $v, 'id' => $id, 'name' => $name, 'date' => $date] = $data;
    $level = $data['l'] ?? 'coffee';                        // no l: coffee (and every key from before levels)
    if ($v !== 1 || !is_string($id) || !preg_match(OFFICE_SUPPORTER_ID, $id) || !officeSupporterNameOk($name)
        || !is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])
        || (array_key_exists('l', $data) && !in_array($data['l'], ['round', 'cake', 'raise'], true))) {
        return $bad;
    }
    return ['state' => $serverId !== null && hash_equals($serverId, $id) ? 'valid' : 'other', 'id' => $id, 'name' => $name, 'date' => $date, 'level' => $level];
}

/** Does the team lead ask now? A week after the first sight, never with a valid key, «Not now» at most twice */
function officeSupporterAskDue(array $data, bool $valid, int $now): bool
{
    $ask = is_array($data['ask'] ?? null) ? $data['ask'] : [];
    $first = (int) ($data['first_seen'] ?? 0);
    if ($valid || $first <= 0 || !empty($ask['never']) || (int) ($ask['later'] ?? 0) >= OFFICE_SUPPORTER_ASKS) {
        return false;
    }
    return $now >= ((int) ($ask['next'] ?? 0) ?: $first + OFFICE_SUPPORTER_ASK_AFTER);
}

/** The answer to his ask: 'later' (again in a month, at most twice more) or 'never' */
function officeSupporterAnswer(array $data, string $answer, int $now): array
{
    $ask = is_array($data['ask'] ?? null) ? $data['ask'] : [];
    if ($answer === 'never') {
        $ask['never'] = true;
    } else {
        $ask['later'] = (int) ($ask['later'] ?? 0) + 1;
        $ask['next'] = $now + OFFICE_SUPPORTER_ASK_AGAIN;
    }
    $data['ask'] = $ask;
    return $data;
}

/**
 * What the page gets (CONFIG.supporter): this server's ID, the saved key's state
 * ('none', 'valid', 'other', 'invalid') with its name, date, ID and level, and whether the team lead asks
 */
function officeSupporterInfo(array $data, ?string $serverId, int $now): array
{
    $check = is_string($data['key'] ?? null) ? officeSupporterCheck($data['key'], $serverId) : ['state' => 'none'];
    $info = ['id' => $serverId, 'state' => $check['state']];
    if (isset($check['id'])) {
        $info += ['name' => $check['name'], 'date' => $check['date'], 'key_id' => $check['id'], 'level' => $check['level']];
    }
    $info['ask'] = officeSupporterAskDue($data, $check['state'] === 'valid', $now);
    return $info;
}

/**
 * A file written whole or not at all, like the agent's writeAtomic(): a new file next to it
 * (exclusive, random name, $mode from the start — never through a link) renamed over it
 */
function officeWriteAtomic(string $path, string $content, int $mode = 0600): bool
{
    $old = umask(0777 & ~$mode);
    try {
        for ($i = 0, $f = false; $i < 3 && !$f; $i++) {
            $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
            $f = @fopen($tmp, 'x');
        }
    } finally {
        umask($old);
    }
    if (!$f) {
        return false;
    }
    $ok = @fwrite($f, $content) === strlen($content);
    $ok = fclose($f) && $ok;
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Reads, changes and writes supporter.json under a lock; the first sight is stamped when
 * missing. Null when its folder (data/office, made by the agent) isn't there or writable.
 */
function officeSupporterStore(string $file, callable $change, int $now): ?array
{
    $dir = dirname($file);
    clearstatcache(true, $dir);
    if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
        return null;
    }
    $h = @fopen("$dir/.supporter.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        return null;
    }
    try {
        $data = officeSupporterRead($file);
        $data['first_seen'] = (int) ($data['first_seen'] ?? 0) ?: $now;
        $data = $change($data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && officeWriteAtomic($file, $json) ? $data : null;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function officeSupporterRead(string $file): array
{
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function officeSupporterFile(): string
{
    return OFFICE_DATA . '/office/supporter.json';
}

/** For the page (page.php); the first look stamps first_seen. Never fails the page: the key unlocks nothing */
function officeSupporterPage(): array
{
    try {
        $file = officeSupporterFile();
        $now = time();
        $data = officeSupporterRead($file);
        if ((int) ($data['first_seen'] ?? 0) <= 0) {
            $data = officeSupporterStore($file, fn (array $d): array => $d, $now) ?? $data;   // not yet (no data/office): next time
        }
        return officeSupporterInfo($data, officeServerId(), $now);
    } catch (Throwable $e) {
        error_log('UnraidSecretaryOffice: supporter: ' . $e->getMessage());
        return ['id' => null, 'state' => 'none', 'ask' => false];
    }
}

/** office.supporter_set {key}, office.supporter_remove, office.supporter_ask {answer: later|never} */
function officeSupporterAction(string $action, array $data): array
{
    $serverId = officeServerId();
    $now = time();
    if ($action === 'office.supporter_set') {
        $key = $data['key'] ?? null;
        if (!is_string($key) || trim($key) === '') {
            throw new OfficeProblem('missing_field', 400, ['field' => 'key']);
        }
        $check = officeSupporterCheck($key, $serverId);
        if ($check['state'] === 'invalid') {
            throw new OfficeProblem('supporter_invalid');
        }
        if ($check['state'] === 'other') {
            throw $serverId === null ? new OfficeProblem('supporter_no_id')
                : new OfficeProblem('supporter_other_server', 400, ['id' => $check['id'], 'server' => $serverId]);
        }
        $clean = (string) preg_replace('/\s+/', '', $key);
        $change = function (array $d) use ($clean, $now): array {
            $d['key'] = $clean;
            $d['key_added'] = $now;
            return $d;
        };
    } elseif ($action === 'office.supporter_remove') {
        $change = function (array $d): array {
            unset($d['key'], $d['key_added']);
            return $d;
        };
    } elseif ($action === 'office.supporter_ask') {
        $answer = $data['answer'] ?? null;
        if (!in_array($answer, ['later', 'never'], true)) {
            throw new OfficeProblem('bad_request');
        }
        $change = fn (array $d): array => officeSupporterAnswer($d, $answer, $now);
    } else {
        throw new OfficeProblem('unknown_action', 400, ['action' => $action]);
    }
    $stored = officeSupporterStore(officeSupporterFile(), $change, $now);
    if ($stored === null) {
        throw new OfficeProblem('supporter_storage', 503);
    }
    return ['ok' => true, 'supporter' => officeSupporterInfo($stored, $serverId, $now)];
}
