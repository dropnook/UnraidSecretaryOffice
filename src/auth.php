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
 * plus "refresh"); everything else needs an unlocked browser — and so do the
 * open actions that do more than read or measure (OFFICE_PIN_ACTIONS, and any
 * asked to wake sleeping disks: OFFICE_PIN_FLAGS).
 *
 * Wrong PINs are counted per client (its address; IPv6 by its /64): after
 * OFFICE_FREE_TRIES that client waits, the others don't. All clients together
 * get OFFICE_GLOBAL_TRIES before everybody waits — so many addresses can't
 * guess on and on either.
 *
 * Waiting too long, or forgot the PIN? In Unraid's terminal (root only):
 *   bash /usr/local/emhttp/plugins/unraid-secretary-office/scripts/pin.sh unblock|reset|status
 * unblock lifts the waiting times and keeps the PIN, reset forgets it (like
 * deleting data/office/auth.json); see officeAuthCli() at the end.
 */

const OFFICE_UNLOCK_HOURS = 12;
const OFFICE_COOKIE       = 'office_unlock';
const OFFICE_PIN_MIN      = 4;
const OFFICE_PIN_MAX      = 64;
const OFFICE_FREE_TRIES   = 5;      // per client; then its waiting time doubles from 30 s, up to 15 min
const OFFICE_GLOBAL_TRIES = 30;     // all clients together; then everybody waits the same way
const OFFICE_CLIENTS_MAX  = 64;     // clients with wrong tries kept (the oldest go first) …
const OFFICE_CLIENTS_KEEP = 86400;  // … and not longer than a day after their last wrong try
// open actions (desk.json) that still need the PIN: they do more than read or measure —
// setup.sh --plan holds the engine's lock for a while (a backup starting then is skipped)
const OFFICE_PIN_ACTIONS  = ['backup.setup_plan'];
// … and any open action asked to do more by one of these request fields (wake: spin up sleeping disks)
const OFFICE_PIN_FLAGS    = ['wake'];

function officeAuthFile(): string
{
    return OFFICE_DATA . '/office/auth.json';
}

/** @return array{pin_hash?:string, secret?:string, failures?:int, wait_until?:int, clients?:array<string, array{f:int, w:int, t:int}>, read?:bool} */
function officeAuthRead(): array
{
    return officeReadJson(officeAuthFile()) ?? [];
}

/**
 * Changes auth.json under a lock. $change gets the current data and returns
 * the new data (or null to leave it as it is). $keepOwner (root in a terminal,
 * officeAuthCli()): the folder belongs to the web server's user, so what root
 * creates there gets that owner — the new auth.json the old one's — and
 * nothing in it is followed if it is a link.
 */
function officeAuthUpdate(callable $change, bool $keepOwner = false): array
{
    $dir = dirname(officeAuthFile());
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new AuthProblem('auth_storage', 503);
    }
    if ($keepOwner && (is_link($dir) || is_link("$dir/.auth.lock") || is_link(officeAuthFile()))) {
        throw new AuthProblem('auth_storage', 503);
    }
    $owner = $keepOwner ? @lstat($dir) : false;
    $newLock = !file_exists("$dir/.auth.lock");
    $h = fopen("$dir/.auth.lock", 'c');
    if (!$h || !flock($h, LOCK_EX)) {
        throw new AuthProblem('auth_storage', 503);
    }
    if ($owner && $newLock) {
        @lchown("$dir/.auth.lock", $owner['uid']);
        @lchgrp("$dir/.auth.lock", $owner['gid']);
    }
    try {
        $data = officeAuthRead();
        $new = $change($data);
        if (is_array($new)) {
            // a new file of our own (never one that is already there), only readable by us before it takes the name
            $tmp = "$dir/.auth." . bin2hex(random_bytes(16)) . '.tmp';
            $f = @fopen($tmp, 'x');
            $ok = $f && @chmod($tmp, 0600) && @fwrite($f, json_encode($new, JSON_UNESCAPED_SLASHES)) !== false;
            if ($f) {
                fclose($f);
            }
            if ($ok && $owner) {
                $was = @lstat(officeAuthFile());
                $who = $was && ($was['mode'] & 0170000) === 0100000 ? $was : $owner;
                $ok = @lchown($tmp, $who['uid']) && @lchgrp($tmp, $who['gid']);
            }
            if (!$ok || !@rename($tmp, officeAuthFile())) {
                @unlink($tmp);
                throw new AuthProblem('auth_storage', 503);
            }
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

/**
 * May this request run $action ("<desk>.<name>") with $data (the request's fields; null: read from this
 * request's body)? Throws if not.
 */
function officeMayWrite(string $action, ?array $data = null): void
{
    [$desk, $name] = explode('.', $action, 2);
    $open = $name === 'refresh' || in_array($name, officeDesks()[$desk]['open_actions'] ?? [], true);
    if ($open && $name !== 'refresh' && officeOpenNeedsPin($action, $data ?? officeRequestData())) {
        $open = false;
    }
    if ($open) {
        officeMayRead();                 // reading actions: open, unless reading needs the PIN too
        return;
    }
    if (officeUnlocked() === null) {
        throw new AuthProblem('pin_required', 401);
    }
}

/** An open action that does more than read or measure this time (OFFICE_PIN_ACTIONS, OFFICE_PIN_FLAGS) */
function officeOpenNeedsPin(string $action, array $data): bool
{
    if (in_array($action, OFFICE_PIN_ACTIONS, true)) {
        return true;
    }
    foreach (OFFICE_PIN_FLAGS as $flag) {
        if (!empty($data[$flag])) {
            return true;
        }
    }
    return false;
}

/** The fields of this POST (the same body api.php reads: JSON, at most 1 MiB, depth 16) */
function officeRequestData(): array
{
    static $data = null;
    if ($data === null) {
        $d = json_decode((string) @file_get_contents('php://input', false, null, 0, 1 << 20), true, 16);
        $data = is_array($d) ? $d : [];
    }
    return $data;
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
        officeAuthWait($auth);
        if (password_verify($pin, $auth['pin_hash'])) {
            $ok = true;
            return officeAuthRight($auth);
        }
        return officeAuthFailed($auth);
    });
    if (!$ok) {
        throw new AuthProblem('pin_wrong', 403);
    }
    $until = empty($auth['pin_hash']) ? null : officeSetUnlockCookie($auth);
    return ['ok' => true, 'auth' => officeAuthStatus($until)];
}

/** Who is asking: the client's address (IPv6 by its /64 — one device has many), else "unknown" */
function officeAuthClient(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return $ip;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = (string) inet_pton($ip);
        $mapped = substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff";
        return $mapped ? (string) inet_ntop(substr($bin, 12)) : (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . "/64";
    }
    return 'unknown';
}

/** Waiting time for this client (its own, or everybody's)? Throws pin_wait then. */
function officeAuthWait(array $auth): void
{
    $client = $auth['clients'][officeAuthClient()] ?? [];
    $wait = max((int) ($auth['wait_until'] ?? 0), (int) ($client['w'] ?? 0)) - time();
    if ($wait > 0) {
        throw new AuthProblem('pin_wait', 429, ['seconds' => $wait]);
    }
}

/** The right PIN: this client's count and the count of all start anew */
function officeAuthRight(array $auth): array
{
    unset($auth['clients'][officeAuthClient()]);
    $auth['failures'] = 0;
    $auth['wait_until'] = 0;
    if (empty($auth['clients'])) {
        unset($auth['clients']);
    }
    return $auth;
}

/**
 * One more wrong PIN — wherever it was typed (unlocking, or as the current PIN
 * when changing or removing it): after OFFICE_FREE_TRIES of this client its
 * waiting time doubles from 30 s, up to 15 minutes; after OFFICE_GLOBAL_TRIES
 * of all clients together everybody's does. Only a right PIN resets the counts.
 */
function officeAuthFailed(array $auth): array
{
    $now = time();
    $wait = fn (int $over): int => $now + min(900, 30 * 2 ** min(10, $over));
    $failures = (int) ($auth['failures'] ?? 0) + 1;
    $auth['failures'] = $failures;
    if ($failures >= OFFICE_GLOBAL_TRIES) {
        $auth['wait_until'] = $wait($failures - OFFICE_GLOBAL_TRIES);
    }
    $clients = [];
    foreach ((array) ($auth['clients'] ?? []) as $k => $c) {
        if (is_array($c) && $now - (int) ($c['t'] ?? 0) < OFFICE_CLIENTS_KEEP) {
            $clients[(string) $k] = ['f' => (int) ($c['f'] ?? 0), 'w' => (int) ($c['w'] ?? 0), 't' => (int) ($c['t'] ?? 0)];
        }
    }
    $me = officeAuthClient();
    $f = ($clients[$me]['f'] ?? 0) + 1;
    $clients[$me] = ['f' => $f, 'w' => $f >= OFFICE_FREE_TRIES ? $wait($f - OFFICE_FREE_TRIES) : 0, 't' => $now];
    uasort($clients, fn ($a, $b) => $b['t'] <=> $a['t']);
    $auth['clients'] = array_slice($clients, 0, OFFICE_CLIENTS_MAX, true);
    return $auth;
}

/** Set, change ($pin) or remove ($pin === '') the PIN. Needs the current one if there is one. */
function officeSetPin(string $pin, string $current): array
{
    if ($pin !== '' && (mb_strlen($pin) < OFFICE_PIN_MIN || mb_strlen($pin) > OFFICE_PIN_MAX)) {
        throw new AuthProblem('pin_length', 400, ['min' => OFFICE_PIN_MIN, 'max' => OFFICE_PIN_MAX]);
    }
    $wrong = false;
    $auth = officeAuthUpdate(function (array $auth) use ($pin, $current, &$wrong): array {
        if (!empty($auth['pin_hash'])) {
            officeAuthWait($auth);
            if (!password_verify($current, $auth['pin_hash'])) {
                // counts like a wrong PIN at unlocking: this is no way around the waiting time
                $wrong = true;
                return officeAuthFailed($auth);
            }
        }
        if ($pin === '') {
            return [];
        }
        return ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'secret' => bin2hex(random_bytes(32)), 'failures' => 0, 'wait_until' => 0,
                'read' => !empty($auth['read'])];      // a new PIN keeps "reading needs the PIN too"
    });
    if ($wrong) {
        throw new AuthProblem('pin_wrong', 403);
    }
    if (empty($auth['pin_hash'])) {
        officeClearUnlockCookie();
        return ['ok' => true, 'auth' => officeAuthStatus(null)];
    }
    return ['ok' => true, 'auth' => officeAuthStatus(officeSetUnlockCookie($auth))];
}

// ===================================================================== in Unraid's terminal (scripts/pin.sh, root only)

/*
 * For whoever stands at the server — never reachable from the web (CLI only,
 * pin.sh checks for root):
 *   status   is a PIN set, does looking need it too, who waits how long
 *   unblock  forgets the wrong tries and every waiting time; the PIN and its
 *            secret stay, so browsers that are unlocked stay unlocked
 *   reset    forgets the PIN — auth.json emptied, as removing the PIN in the
 *            dialog does (or deleting the file): anyone who may open the
 *            office may change things again, until a new PIN is set
 * Same lock and file handling as the page; auth.json stays 0600 with its owner.
 */
function officeAuthCli(string $command, ?int $now = null): int
{
    if (PHP_SAPI !== 'cli') {
        return 2;
    }
    $now ??= time();
    if (!is_dir(OFFICE_DATA)) {
        fwrite(STDERR, 'The data folder ' . OFFICE_DATA . " isn't there - is the array started?\n");
        return 1;
    }
    try {
        switch ($command) {
            case 'status':
                echo officeAuthDescribe(officeAuthRead(), $now);
                return 0;
            case 'unblock':
                $r = officeAuthUnblock();
                echo match (true) {
                    !$r['pin']     => "No PIN is set - nobody waits.\n",
                    !$r['changed'] => "Nobody was waiting - nothing changed.\n",
                    default        => "Waiting times lifted: {$r['failures']} wrong tries forgotten, those of {$r['clients']} device(s) too.\n"
                                    . "The PIN stays as it was; browsers that are unlocked stay unlocked.\n",
                };
                return 0;
            case 'reset':
                echo officeAuthReset()
                    ? "PIN forgotten. Anyone who may open the office can change things again - set a new one in the office (its ... menu, PIN).\n"
                    : "No PIN was set - nothing changed.\n";
                return 0;
        }
    } catch (AuthProblem $e) {
        fwrite(STDERR, 'Could not change ' . officeAuthFile() . " ({$e->key}).\n");
        return 1;
    }
    fwrite(STDERR, "Usage: pin.sh status|unblock|reset\n");
    return 2;
}

/** Forgets the wrong tries and every waiting time, keeps the PIN and its secret. @return array{pin: bool, changed: bool, failures: int, clients: int} */
function officeAuthUnblock(): array
{
    $r = ['pin' => false, 'changed' => false, 'failures' => 0, 'clients' => 0];
    if (!is_file(officeAuthFile())) {
        return $r;
    }
    officeAuthUpdate(function (array $auth) use (&$r): ?array {
        $r['pin'] = !empty($auth['pin_hash']);
        $r['failures'] = (int) ($auth['failures'] ?? 0);
        $r['clients'] = count((array) ($auth['clients'] ?? []));
        $r['changed'] = $r['failures'] > 0 || $r['clients'] > 0 || (int) ($auth['wait_until'] ?? 0) > 0;
        if (!$r['changed']) {
            return null;
        }
        $auth['failures'] = 0;
        $auth['wait_until'] = 0;
        unset($auth['clients']);
        return $auth;
    }, true);
    return $r;
}

/** Forgets the PIN (and with it "looking needs the PIN too" and every wait). @return bool whether there was one */
function officeAuthReset(): bool
{
    $had = false;
    if (is_file(officeAuthFile())) {
        officeAuthUpdate(function (array $auth) use (&$had): ?array {
            $had = !empty($auth['pin_hash']);
            return $auth === [] ? null : [];
        }, true);
    }
    return $had;
}

/** What status tells: PIN set?, looking protected?, the waits now */
function officeAuthDescribe(array $auth, int $now): string
{
    if (empty($auth['pin_hash'])) {
        return "PIN: not set - anyone who may open the office can change things.\n";
    }
    $left = fn (int $until): string => $until > $now ? sprintf('%d min %02d s', intdiv($until - $now, 60), ($until - $now) % 60) : '';
    $out = "PIN: set" . (!empty($auth['read']) ? ' (looking needs it too)' : '') . "\n";
    $all = (int) ($auth['wait_until'] ?? 0);
    $out .= 'Wrong tries of all devices: ' . (int) ($auth['failures'] ?? 0) . ' of ' . OFFICE_GLOBAL_TRIES
          . ($left($all) !== '' ? ' - everybody waits ' . $left($all) . ' more' : '') . "\n";
    $clients = (array) ($auth['clients'] ?? []);
    if (!$clients) {
        return $out . "No device has wrong tries.\n";
    }
    foreach ($clients as $client => $c) {
        $w = $left((int) ($c['w'] ?? 0));
        $out .= sprintf("  %-28s %d wrong%s\n", (string) $client, (int) ($c['f'] ?? 0), $w !== '' ? ", waits $w more" : '');
    }
    return $out . "To lift the waiting times and keep the PIN: pin.sh unblock\n";
}
