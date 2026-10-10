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
 * Several keys are kept (2026-10-09: up to OFFICE_SUPPORTER_KEYS_MAX, the same
 * payload once); the plate shows each level's picture once, in level order — never a
 * count, never a rank —, and the name of the newest key.
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
 *     l the thank-you's level (2026-10-08), exactly "round" (a tip from 20),
 *     "cake" (from 50) or "raise" (from 100). Without l the key is «coffee» (any tip,
 *     and every key made before levels) — coffee is never written as l. The level is
 *     signed like the rest, so the office never shows one nobody signed; it only
 *     chooses the plate's picture (☕ ☕☕ 🍰 🥂 — one coffee, a round, a cake, the
 *     whole team toasts a pay rise) and unlocks nothing, like the key.
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
 * data/office/supporter.json (the web side's, like staff.json; 0600 — the agent writes it too, for a claim, under
 * the same lock):
 *   {"first_seen": <ts>, "keys": [{"key": "USO1…", "added": <ts>}, …],
 *    "codes": [{"code": "<43 b64url>", "made": <ts>, "opened"?: <ts>, "asked"?: <ts>, "auto"?: <ts>}, …],
 *    "ask": {"later": <n>, "next": <ts>, "never": true}}
 * A file from before 1.48 has one "key" (+ "key_added"): read as a list of one, written as the list.
 *
 * The key arrives by itself (2026-10-09): when the tip jar opens the support page the office hands it a
 * one-time code (officeSupporterCodeTake(): 256 random bits, b64url, kept here with its time; a code from the last
 * hour is handed out again, at most OFFICE_SUPPORTER_CODES_MAX, each lives a day) in the link's fragment
 * (#claim=<code> — never sent in a request line). After a paid tip the tip Worker keeps the key under SHA-256(code)
 * for a day; the agent — never the browser — asks GET <support page>/api/claim?code=<code> (agent/lib/supporter.php)
 * and gets the key once. It is checked here like a pasted one (a forged or foreign key never gets in), added, and the
 * code forgotten. Asked when the user says so (the tip jar's «I've tipped — look for my key»: every open code) and
 * once on its own, a minute after the support page was opened, on the page's next state refresh (opened codes only);
 * never in a loop. The page still shows the key for copying by hand.
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
const OFFICE_SUPPORTER_ACTIONS   = ['office.supporter_set', 'office.supporter_remove', 'office.supporter_ask', 'office.supporter_code'];
const OFFICE_SUPPORTER_LEVELS    = ['coffee', 'round', 'cake', 'raise'];   // the thank-you's picture; coffee = no l in the key
const OFFICE_SUPPORTER_KEYS_MAX  = 20;            // keys kept for this server (the same payload once)
const OFFICE_SUPPORTER_CODES_MAX = 3;             // open claim codes at a time
const OFFICE_SUPPORTER_CODE_TTL  = 86400;         // a claim code lives a day (the tip Worker keeps the key that long)
const OFFICE_SUPPORTER_CODE_REUSE = 3600;         // a code made in the last hour is handed out again
const OFFICE_SUPPORTER_CLAIM_WAIT = 60;           // the one ask on its own: a minute after the support page opened
const OFFICE_SUPPORTER_CLAIM_GAP = 10;            // a code is asked at most every 10 s («look for my key» again)
const OFFICE_SUPPORTER_CODE_RE   = '/^[A-Za-z0-9_-]{43}$/D';   // 32 random bytes, b64url
const OFFICE_SUPPORTER_REF_RE    = '/^[0-9a-f]{12}$/D';        // a kept key's reference for the page (never the key)

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

/** The payload part of a key (what it says) — the same payload is the same thank-you, whatever the signature */
function officeSupporterPayloadOf(string $key): string
{
    $key = (string) preg_replace('/\s+/', '', $key);
    $parts = explode('.', $key);
    return count($parts) === 3 ? $parts[1] : $key;
}

/** A kept key's reference for the page (remove it by this): 12 hex of the payload's hash — the key itself stays here */
function officeSupporterRef(string $key): string
{
    return substr(hash('sha256', officeSupporterPayloadOf($key)), 0, 12);
}

/**
 * supporter.json in today's shape: "keys" a list of {key, added} (an old single "key" moved into it), the same
 * payload once, at most OFFICE_SUPPORTER_KEYS_MAX (the newest); "codes" only well-formed ones. Expired codes go
 * with $now.
 */
function officeSupporterNormalize(array $data, ?int $now = null): array
{
    $keys = [];
    $list = is_array($data['keys'] ?? null) ? array_values($data['keys']) : [];
    if (is_string($data['key'] ?? null)) {
        $list[] = ['key' => $data['key'], 'added' => (int) ($data['key_added'] ?? 0)];
    }
    unset($data['key'], $data['key_added']);
    $seen = [];
    foreach ($list as $k) {
        if (!is_array($k) || !is_string($k['key'] ?? null) || $k['key'] === '' || strlen($k['key']) > 4 * OFFICE_SUPPORTER_KEY_MAX) {
            continue;
        }
        $payload = officeSupporterPayloadOf($k['key']);
        if (isset($seen[$payload])) {
            continue;
        }
        $seen[$payload] = true;
        $keys[] = ['key' => $k['key'], 'added' => max(0, (int) ($k['added'] ?? 0))];
    }
    $data['keys'] = array_slice($keys, -OFFICE_SUPPORTER_KEYS_MAX);
    $codes = [];
    foreach (is_array($data['codes'] ?? null) ? $data['codes'] : [] as $c) {
        if (!is_array($c) || !is_string($c['code'] ?? null) || !preg_match(OFFICE_SUPPORTER_CODE_RE, $c['code']) || !is_int($c['made'] ?? null)) {
            continue;
        }
        if ($now !== null && ($c['made'] + OFFICE_SUPPORTER_CODE_TTL <= $now || $c['made'] > $now + 300)) {
            continue;
        }
        $codes[] = array_intersect_key($c, ['code' => 1, 'made' => 1, 'opened' => 1, 'asked' => 1, 'auto' => 1]);
    }
    $data['codes'] = array_slice($codes, -OFFICE_SUPPORTER_CODES_MAX);
    if (!$data['codes']) {
        unset($data['codes']);
    }
    return $data;
}

/**
 * A checked key added (whitespace out): the same payload already kept → nothing changes; over the cap the oldest
 * goes. @return array{0: array, 1: bool} the data, whether it was new
 */
function officeSupporterKeyAdd(array $data, string $key, int $now): array
{
    $data = officeSupporterNormalize($data);
    $clean = (string) preg_replace('/\s+/', '', $key);
    $payload = officeSupporterPayloadOf($clean);
    foreach ($data['keys'] as $k) {
        if (officeSupporterPayloadOf($k['key']) === $payload) {
            return [$data, false];
        }
    }
    $data['keys'][] = ['key' => $clean, 'added' => $now];
    $data['keys'] = array_slice($data['keys'], -OFFICE_SUPPORTER_KEYS_MAX);
    return [$data, true];
}

/** A kept key removed by its reference ('' = all of them) */
function officeSupporterKeyRemove(array $data, string $ref): array
{
    $data = officeSupporterNormalize($data);
    $data['keys'] = $ref === '' ? [] : array_values(array_filter($data['keys'], fn (array $k): bool => officeSupporterRef($k['key']) !== $ref));
    return $data;
}

/**
 * The code for the support page's link: the newest open one when it was made within the hour (the Worker keeps
 * several keys under one code), else a new one — at most OFFICE_SUPPORTER_CODES_MAX, the oldest never-opened goes
 * first. @return array{0: array, 1: string}
 */
function officeSupporterCodeTake(array $data, int $now): array
{
    $data = officeSupporterNormalize($data, $now);
    $codes = $data['codes'] ?? [];
    $last = $codes ? $codes[count($codes) - 1] : null;
    if ($last !== null && $last['made'] > $now - OFFICE_SUPPORTER_CODE_REUSE) {
        return [$data, $last['code']];
    }
    $code = officeB64url(random_bytes(32));
    $codes[] = ['code' => $code, 'made' => $now];
    while (count($codes) > OFFICE_SUPPORTER_CODES_MAX) {
        $drop = 0;
        foreach ($codes as $i => $c) {
            if (!isset($c['opened'])) {
                $drop = $i;
                break;
            }
        }
        array_splice($codes, $drop, 1);
    }
    $data['codes'] = $codes;
    return [$data, $code];
}

/** The support page was opened with this code: it may be asked on its own once, a minute from now */
function officeSupporterCodeOpened(array $data, string $code, int $now): array
{
    $data = officeSupporterNormalize($data, $now);
    foreach ($data['codes'] ?? [] as $i => $c) {
        if (hash_equals($c['code'], $code)) {
            $data['codes'][$i]['opened'] = $now;
            unset($data['codes'][$i]['auto']);
        }
    }
    return $data;
}

/**
 * The codes to ask now, marked as asked. $auto (the page's one ask on its own): opened codes a minute after the
 * opening, each once; else (the user's «look for my key»): every open code, each at most every
 * OFFICE_SUPPORTER_CLAIM_GAP seconds. @return array{0: array, 1: list<string>}
 */
function officeSupporterClaimPick(array $data, bool $auto, int $now): array
{
    $data = officeSupporterNormalize($data, $now);
    $pick = [];
    foreach ($data['codes'] ?? [] as $i => $c) {
        $due = $auto
            ? isset($c['opened']) && !isset($c['auto']) && $now >= (int) $c['opened'] + OFFICE_SUPPORTER_CLAIM_WAIT
            : $now >= (int) ($c['asked'] ?? 0) + OFFICE_SUPPORTER_CLAIM_GAP;
        if ($due) {
            $pick[] = $c['code'];
            $data['codes'][$i]['asked'] = $now;
            if ($auto) {
                $data['codes'][$i]['auto'] = $now;
            }
        }
    }
    return [$data, $pick];
}

/**
 * The tip Worker's answers taken in: code => ['keys' => [...]] (it gave them — and forgot them: the code goes),
 * 'none' (nothing there yet: the code stays), 'failed' (no answer: it stays). Each key is checked like a pasted one;
 * only a valid one for this server is kept. @return array{0: array, 1: int, 2: int} the data, keys added, keys refused
 */
function officeSupporterClaimTake(array $data, array $answers, ?string $serverId, int $now): array
{
    $data = officeSupporterNormalize($data, $now);
    $added = 0;
    $refused = 0;
    foreach ($answers as $code => $answer) {
        if (!is_array($answer) || !is_array($answer['keys'] ?? null)) {
            continue;
        }
        $data['codes'] = array_values(array_filter($data['codes'] ?? [], fn (array $c): bool => !hash_equals($c['code'], (string) $code)));
        foreach ($answer['keys'] as $key) {
            if (!is_string($key) || officeSupporterCheck($key, $serverId)['state'] !== 'valid') {
                $refused++;
                continue;
            }
            [$data, $new] = officeSupporterKeyAdd($data, $key, $now);
            $added += $new ? 1 : 0;
        }
    }
    if (!($data['codes'] ?? [])) {
        unset($data['codes']);
    }
    return [$data, $added, $refused];
}

/**
 * What the page gets (CONFIG.supporter): this server's ID; the kept keys' state — 'valid' when any is (else the
 * newest's: 'other', 'invalid'; 'none' without keys) — with the newest key's name, date, ID and level; `levels`
 * (each valid key's level once, in level order: the plate's pictures), `keys` (each with its reference, state,
 * name, date, level — newest first; never the key itself), whether the team lead asks, and `claim`: open = codes the
 * support page was opened with, wait = seconds until the one ask on its own is due (null: none)
 */
function officeSupporterInfo(array $data, ?string $serverId, int $now): array
{
    $data = officeSupporterNormalize($data, $now);
    $keys = [];
    foreach ($data['keys'] as $i => $k) {
        $check = officeSupporterCheck($k['key'], $serverId);
        $row = ['ref' => officeSupporterRef($k['key']), 'state' => $check['state'], 'order' => $i];
        if (isset($check['id'])) {
            $row += ['name' => $check['name'], 'date' => $check['date'], 'key_id' => $check['id'], 'level' => $check['level']];
        }
        $keys[] = $row;
    }
    // newest first: by the key's day, then by when it came
    usort($keys, fn (array $a, array $b): int => [$b['date'] ?? '', $b['order']] <=> [$a['date'] ?? '', $a['order']]);
    $valid = array_values(array_filter($keys, fn (array $k): bool => $k['state'] === 'valid'));
    $state = $valid ? 'valid' : ($keys ? $keys[0]['state'] : 'none');
    $info = ['id' => $serverId, 'state' => $state];
    $lead = $valid[0] ?? ($keys[0] ?? null);
    if ($lead !== null && isset($lead['key_id'])) {
        $info += ['name' => $lead['name'], 'date' => $lead['date'], 'key_id' => $lead['key_id'], 'level' => $lead['level']];
    }
    $have = array_column($valid, 'level');
    $info['levels'] = array_values(array_filter(OFFICE_SUPPORTER_LEVELS, fn (string $l): bool => in_array($l, $have, true)));
    // every name on the valid keys, newest first, each once (the plate thanks them all: «Benj & Janine»)
    $info['names'] = array_values(array_unique(array_column($valid, 'name')));
    // how many valid keys of each level (2026-10-09: «so viele Tassen, wie ich will») — the plate shows ☕×3
    $info['counts'] = (object) array_filter(array_map(fn (string $l): int => count(array_keys($have, $l, true)), array_combine(OFFICE_SUPPORTER_LEVELS, OFFICE_SUPPORTER_LEVELS)));
    $info['keys'] = array_map(function (array $k): array {
        unset($k['order']);
        return $k;
    }, $keys);
    $info['ask'] = officeSupporterAskDue($data, $state === 'valid', $now);
    $open = 0;
    $wait = null;
    foreach ($data['codes'] ?? [] as $c) {
        if (isset($c['opened'])) {
            $open++;
            if (!isset($c['auto'])) {
                $due = max(0, (int) $c['opened'] + OFFICE_SUPPORTER_CLAIM_WAIT - $now);
                $wait = $wait === null ? $due : min($wait, $due);
            }
        }
    }
    $info['claim'] = ['open' => $open, 'wait' => $wait];
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
        $data = officeSupporterNormalize(officeSupporterRead($file), $now);
        $data['first_seen'] = (int) ($data['first_seen'] ?? 0) ?: $now;
        $data = officeSupporterNormalize($change($data), $now);
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

/**
 * office.supporter_set {key} (adds it), office.supporter_remove {ref?} (that one; none: all),
 * office.supporter_ask {answer: later|never}, office.supporter_code {} (the code for the support page's link) or
 * {opened: <code>} (the page was opened with it)
 */
function officeSupporterAction(string $action, array $data): array
{
    $serverId = officeServerId();
    $now = time();
    $extra = [];
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
        $extra['key'] = ['name' => $check['name'], 'level' => $check['level'], 'date' => $check['date']];
        $change = function (array $d) use ($key, $now, &$extra): array {
            [$d, $extra['added']] = officeSupporterKeyAdd($d, $key, $now);
            return $d;
        };
    } elseif ($action === 'office.supporter_remove') {
        $ref = $data['ref'] ?? '';
        if (!is_string($ref) || ($ref !== '' && !preg_match(OFFICE_SUPPORTER_REF_RE, $ref))) {
            throw new OfficeProblem('bad_request');
        }
        $change = fn (array $d): array => officeSupporterKeyRemove($d, $ref);
    } elseif ($action === 'office.supporter_ask') {
        $answer = $data['answer'] ?? null;
        if (!in_array($answer, ['later', 'never'], true)) {
            throw new OfficeProblem('bad_request');
        }
        $change = fn (array $d): array => officeSupporterAnswer($d, $answer, $now);
    } elseif ($action === 'office.supporter_code') {
        $opened = $data['opened'] ?? null;
        if ($opened !== null && (!is_string($opened) || !preg_match(OFFICE_SUPPORTER_CODE_RE, $opened))) {
            throw new OfficeProblem('bad_request');
        }
        $change = function (array $d) use ($opened, $now, &$extra): array {
            if ($opened !== null) {
                return officeSupporterCodeOpened($d, $opened, $now);
            }
            [$d, $extra['code']] = officeSupporterCodeTake($d, $now);
            return $d;
        };
    } else {
        throw new OfficeProblem('unknown_action', 400, ['action' => $action]);
    }
    $stored = officeSupporterStore(officeSupporterFile(), $change, $now);
    if ($stored === null) {
        throw new OfficeProblem('supporter_storage', 503);
    }
    return ['ok' => true] + $extra + ['supporter' => officeSupporterInfo($stored, $serverId, $now)];
}
