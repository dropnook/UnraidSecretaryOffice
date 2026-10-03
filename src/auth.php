<?php
declare(strict_types=1);

/*
 * Who may change things.
 *
 * Reading is open — the office belongs in the LAN. Every write request (a
 * POST to the agent) passes officeMayWrite() first. Modes:
 *
 *   none   no protection (until someone sets a PIN)
 *   pin    a PIN unlocks writing in this browser for OFFICE_UNLOCK_HOURS
 *
 * A login at a reverse proxy (e.g. Authentik forward auth, which passes the
 * user in a header) would be a third mode. It belongs here and nowhere else:
 * officeAuthMode() and officeUnlocked() are the only places that decide.
 *
 * Desks name the actions anybody may call ("open_actions" in desk.json,
 * plus "refresh"); everything else needs an unlocked browser.
 *
 * Forgot the PIN? Delete data/office/auth.json on the server.
 */

const OFFICE_UNLOCK_HOURS = 12;
const OFFICE_COOKIE       = 'office_unlock';
const OFFICE_PIN_MIN      = 4;
const OFFICE_PIN_MAX      = 64;
const OFFICE_FREE_TRIES   = 5;      // then waiting time doubles from 30 s, up to 15 min

function officeAuthFile(): string
{
    return OFFICE_DATA . '/office/auth.json';
}

/** @return array{pin_hash?:string, secret?:string, failures?:int, wait_until?:int} */
function officeAuthRead(): array
{
    return readJsonFile(officeAuthFile()) ?? [];
}

/**
 * Changes auth.json under a lock. $change gets the current data and returns
 * the new data (or null to leave it as it is).
 */
function officeAuthUpdate(callable $change): array
{
    $dir = dirname(officeAuthFile());
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new AuthProblem('auth_storage', 503);
    }
    $h = fopen("$dir/.auth.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        throw new AuthProblem('auth_storage', 503);
    }
    try {
        $data = officeAuthRead();
        $new = $change($data);
        if (is_array($new)) {
            $tmp = "$dir/.auth." . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, json_encode($new, JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, officeAuthFile())) {
                @unlink($tmp);
                throw new AuthProblem('auth_storage', 503);
            }
            @chmod(officeAuthFile(), 0600);
            $data = $new;
        }
        return $data;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

final class AuthProblem extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly int $status = 403, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}

function officeAuthMode(): string
{
    return !empty(officeAuthRead()['pin_hash']) ? 'pin' : 'none';
}

/** Valid unlock cookie in this request? Returns its expiry or null. */
function officeUnlocked(): ?int
{
    $auth = officeAuthRead();
    if (empty($auth['pin_hash'])) {
        return PHP_INT_MAX;
    }
    $cookie = (string) ($_COOKIE[OFFICE_COOKIE] ?? '');
    if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $cookie, $m) || (int) $m[1] < time()) {
        return null;
    }
    return hash_equals(officeUnlockSignature($auth, (int) $m[1]), $m[2]) ? (int) $m[1] : null;
}

/** Signed with the secret and the PIN hash: a new PIN locks every browser again. */
function officeUnlockSignature(array $auth, int $until): string
{
    return hash_hmac('sha256', $until . '|' . ($auth['pin_hash'] ?? ''), (string) ($auth['secret'] ?? ''));
}

function officeSetUnlockCookie(array $auth): int
{
    $until = time() + OFFICE_UNLOCK_HOURS * 3600;
    setcookie(OFFICE_COOKIE, $until . '.' . officeUnlockSignature($auth, $until), [
        'expires'  => $until,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on',
    ]);
    return $until;
}

function officeClearUnlockCookie(): void
{
    setcookie(OFFICE_COOKIE, '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
}

function officeAuthStatus(?int $until = null): array
{
    $mode = officeAuthMode();
    $until ??= $mode === 'pin' ? officeUnlocked() : null;
    return [
        'mode'     => $mode,
        'unlocked' => $mode === 'none' || $until !== null,
        'until'    => $mode === 'pin' ? $until : null,
        'read'     => officeReadProtected(),
        'writable' => is_dir(dirname(officeAuthFile())) && is_writable(dirname(officeAuthFile())),
    ];
}

/** Does looking at the office need the PIN too? (Only with a PIN, and only when switched on.) */
function officeReadProtected(): bool
{
    $auth = officeAuthRead();
    return !empty($auth['pin_hash']) && !empty($auth['read']);
}

/** May this request read states, logs and outputs? Throws if not. */
function officeMayRead(): void
{
    if (officeReadProtected() && officeUnlocked() === null) {
        throw new AuthProblem('pin_required', 401);
    }
}

/** May this request run $action ("<desk>.<name>")? Throws if not. */
function officeMayWrite(string $action): void
{
    [$desk, $name] = explode('.', $action, 2);
    if ($name === 'refresh' || in_array($name, officeDesks()[$desk]['open_actions'] ?? [], true)) {
        officeMayRead();                 // reading actions: open, unless reading needs the PIN too
        return;
    }
    if (officeUnlocked() === null) {
        throw new AuthProblem('pin_required', 401);
    }
}

// ===================================================================== office.* actions (handled here, not by the agent)

function officeAuthAction(string $action, array $data): array
{
    return match ($action) {
        'office.unlock' => officeUnlock((string) ($data['pin'] ?? '')),
        'office.lock'   => officeLock(),
        'office.pin'    => officeSetPin((string) ($data['pin'] ?? ''), (string) ($data['current'] ?? '')),
        'office.read'   => officeSetReadProtection(!empty($data['on'])),
        default         => throw new AuthProblem('unknown_action', 400, ['action' => $action]),
    };
}

/** office.read {on}: reading needs the PIN too — only from an unlocked browser, only with a PIN */
function officeSetReadProtection(bool $on): array
{
    if (officeAuthMode() !== 'pin') {
        throw new AuthProblem('pin_needed_first', 400);
    }
    if (officeUnlocked() === null) {
        throw new AuthProblem('pin_required', 401);
    }
    officeAuthUpdate(function (array $auth) use ($on): array {
        $auth['read'] = $on;
        return $auth;
    });
    return ['ok' => true, 'auth' => officeAuthStatus()];
}

function officeLock(): array
{
    officeClearUnlockCookie();
    $status = officeAuthStatus();
    if ($status['mode'] === 'pin') {
        $status['unlocked'] = false;
        $status['until'] = null;
    }
    return ['ok' => true, 'auth' => $status];
}

function officeUnlock(string $pin): array
{
    $ok = false;
    $auth = officeAuthUpdate(function (array $auth) use ($pin, &$ok): ?array {
        if (empty($auth['pin_hash'])) {
            $ok = true;
            return null;
        }
        $wait = (int) ($auth['wait_until'] ?? 0) - time();
        if ($wait > 0) {
            throw new AuthProblem('pin_wait', 429, ['seconds' => $wait]);
        }
        if (password_verify($pin, $auth['pin_hash'])) {
            $ok = true;
            $auth['failures'] = 0;
            $auth['wait_until'] = 0;
            return $auth;
        }
        $failures = (int) ($auth['failures'] ?? 0) + 1;
        $auth['failures'] = $failures;
        if ($failures >= OFFICE_FREE_TRIES) {
            $auth['wait_until'] = time() + min(900, 30 * 2 ** ($failures - OFFICE_FREE_TRIES));
        }
        return $auth;
    });
    if (!$ok) {
        throw new AuthProblem('pin_wrong', 403);
    }
    $until = empty($auth['pin_hash']) ? null : officeSetUnlockCookie($auth);
    return ['ok' => true, 'auth' => officeAuthStatus($until)];
}

/** Set, change ($pin) or remove ($pin === '') the PIN. Needs the current one if there is one. */
function officeSetPin(string $pin, string $current): array
{
    if ($pin !== '' && (mb_strlen($pin) < OFFICE_PIN_MIN || mb_strlen($pin) > OFFICE_PIN_MAX)) {
        throw new AuthProblem('pin_length', 400, ['min' => OFFICE_PIN_MIN, 'max' => OFFICE_PIN_MAX]);
    }
    $auth = officeAuthUpdate(function (array $auth) use ($pin, $current): array {
        if (!empty($auth['pin_hash'])) {
            $wait = (int) ($auth['wait_until'] ?? 0) - time();
            if ($wait > 0) {
                throw new AuthProblem('pin_wait', 429, ['seconds' => $wait]);
            }
            if (!password_verify($current, $auth['pin_hash'])) {
                throw new AuthProblem('pin_wrong', 403);
            }
        }
        if ($pin === '') {
            return [];
        }
        return ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'secret' => bin2hex(random_bytes(32)), 'failures' => 0, 'wait_until' => 0,
                'read' => !empty($auth['read'])];      // a new PIN keeps "reading needs the PIN too"
    });
    if (empty($auth['pin_hash'])) {
        officeClearUnlockCookie();
        return ['ok' => true, 'auth' => officeAuthStatus(null)];
    }
    return ['ok' => true, 'auth' => officeAuthStatus(officeSetUnlockCookie($auth))];
}
