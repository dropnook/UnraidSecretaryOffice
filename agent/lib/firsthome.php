<?php
declare(strict_types=1);

/*
 * Unraid's standard shares on a fresh server (2026-10-16). The office's data folder lies in <appdata>/UnraidSecretaryOffice/
 * data, and Unraid makes appdata and system (Docker's image, libvirt's: without it neither Docker nor Kopia runs) only
 * once Docker or the VMs are turned on — a fresh server without Docker has neither, and the office could do nothing but
 * say so. Each share counts as:
 *
 *   never there  (`fresh`)  Unraid knows no such share — not in shares.ini, no /boot/config/shares/<share>.cfg —
 *                           and neither Docker nor the VMs (switched on) keep anything there: a first start. Offered,
 *                           only on the user's click: the agent asks Unraid to add it the way its ⟦Shares⟧ page does
 *                           (emcmd «Add Share») with Unraid's own defaults for these shares (7.4 writes them so when
 *                           Docker/VMs are turned on): only on the first pool (cache when there is one), else on the array.
 *   gone                    Unraid knew it: the careful text, nothing offered — what was there may come back (a disk, a
 *                           folder put back), and the agent refuses a click for it too.
 *
 * Two ways the user sees it:
 *   - the data folder's share is missing: the agent waits for its data folder (agent.php's loop, makeDataDir()) and looks
 *     every few seconds; the page shows its look from RAM (RUN_DIR/home.json, officeHomeLookFile()): a welcome listing
 *     what is missing and why, one button for all that are new. The click goes into the RAM inbox
 *     (officeHomeRequestFile(), the web side's officeHomeAsk()) — the mailbox lies in the very share that is missing.
 *   - the office runs, but system is missing: the Team Lead's check (caretaker.php, homeMissing()) with the same button
 *     (his action shares_make); Mr. Backupsy and the Consultant point to him where they need Docker.
 * `domains` and `isos` (VMs only) are only mentioned. Mr. Backupsy offers the office's own share UnraidSecretaryOffice as his
 * backup place the same way (backup.php backupPlaceOffer(), backupPlaceMake(): the pool he proposes, on the user's click).
 * The tests put stand-ins into $GLOBALS['homeHost'] (homeHost()) — emcmd always a stand-in there.
 */

const HOME_STANDARD     = ['system'];               // besides the data folder's share: Docker's image, libvirt's
const HOME_OPTIONAL     = ['domains', 'isos'];      // VMs only: mentioned, never offered
const HOME_LOOK_EVERY   = 5;        // seconds between two looks while the data folder is missing
const HOME_REQUEST_TTL  = 120;      // a click older than this is no longer the user's wish
const HOME_WAIT_SHARE   = 15;       // seconds Unraid gets to show the new share under /mnt/user
const HOME_EMCMD        = '/usr/local/sbin/emcmd';
const HOME_SHARE_RE     = '/^[A-Za-z][A-Za-z0-9._-]{0,39}$/D';

/** Where the look goes and what it reads (tests: stand-ins in $GLOBALS['homeHost']) */
function homeHost(): array
{
    $h = $GLOBALS['homeHost'] ?? [];
    return [
        'shares_dir' => $h['shares_dir'] ?? '/boot/config/shares',
        'shares_ini' => $h['shares_ini'] ?? '/var/local/emhttp/shares.ini',
        'docker_cfg' => $h['docker_cfg'] ?? '/boot/config/docker.cfg',
        'domain_cfg' => $h['domain_cfg'] ?? '/boot/config/domain.cfg',
        'pools_dir'  => $h['pools_dir'] ?? '/boot/config/pools',
        'disks_ini'  => $h['disks_ini'] ?? disksIniFile(),
        'share_cfg'  => $h['share_cfg'] ?? '/boot/config/share.cfg',
        'mnt_user'   => $h['mnt_user'] ?? '/mnt/user',
        'emcmd'      => $h['emcmd'] ?? HOME_EMCMD,
        'wait'       => $h['wait'] ?? HOME_WAIT_SHARE,
    ];
}

/** A name Unraid's ⟦Shares⟧ page accepts: a letter first, letters, digits, «.», «_», «-», at most 40, not ending in «.» */
function homeShareValid(string $name): bool
{
    return preg_match(HOME_SHARE_RE, $name) === 1 && !str_ends_with($name, '.');
}

/** The share the data folder lies in — <mnt_user>/<share>/UnraidSecretaryOffice/data — else null: nothing to offer */
function homeShareOf(string $dataUser, string $mntUser = '/mnt/user'): ?string
{
    $q = preg_quote(rtrim($mntUser, '/'), '#');
    if (!preg_match("#^$q/([^/]+)/UnraidSecretaryOffice/data/?\$#D", $dataUser, $m) || !homeShareValid($m[1])) {
        return null;
    }
    return $m[1];
}

/** Unraid's pools (/boot/config/pools/<name>.cfg), sorted by name */
function homePools(?array $h = null): array
{
    $h ??= homeHost();
    $names = [];
    foreach (glob($h['pools_dir'] . '/*.cfg') ?: [] as $f) {
        $n = basename($f, '.cfg');
        if (preg_match('/^[a-z_][a-z0-9_~.-]{0,39}$/D', $n) && is_file($f)) {
            $names[] = $n;
        }
    }
    sort($names, SORT_STRING);
    return $names;
}

/** The pool for Unraid's standard shares: cache first when there is one — Unraid's own default for these shares */
function homePool(?array $h = null): ?string
{
    $names = homePools($h);
    return in_array('cache', $names, true) ? 'cache' : ($names[0] ?? null);
}

/**
 * One share: null when it is there, else {share, fresh}. Cheap and never a disk of the array: emhttp's list of shares
 * (shares.ini, RAM) first — a share it lists counts as there, never looked at under /mnt/user —, else one stat of
 * /mnt/user/<share> (a name shfs doesn't know), config files on the flash.
 *
 * @return array{share: string, fresh: bool}|null
 */
function homeShareState(string $share, ?array $h = null): ?array
{
    $h ??= homeHost();
    foreach (array_keys(readCfg($h['shares_ini'], true)) as $name) {
        if (strcasecmp((string) $name, $share) === 0) {
            return null;
        }
    }
    $at = rtrim($h['mnt_user'], '/') . "/$share";
    clearstatcache(true, $at);
    if (file_exists($at)) {
        return null;
    }
    // Unraid knew it: its settings on the flash (any case — Unraid's share names don't tell case apart)
    $known = false;
    foreach (glob($h['shares_dir'] . '/*.cfg') ?: [] as $f) {
        $known = $known || strcasecmp(basename($f, '.cfg'), $share) === 0;
    }
    // Docker or the VMs on with something of theirs in it: Unraid made it once — gone, not new
    $docker = readCfg($h['docker_cfg']);
    $vms = readCfg($h['domain_cfg']);
    $paths = array_merge(
        ($docker['DOCKER_ENABLED'] ?? '') === 'yes'
            ? [($docker['DOCKER_APP_CONFIG_PATH'] ?? '') ?: '/mnt/user/appdata/', ($docker['DOCKER_IMAGE_FILE'] ?? '') ?: '/mnt/user/system/docker/'] : [],
        ($vms['SERVICE'] ?? '') === 'enable'
            ? [(string) ($vms['IMAGE_FILE'] ?? ''), (string) ($vms['DOMAINDIR'] ?? ''), (string) ($vms['MEDIADIR'] ?? '')] : []);
    foreach ($paths as $p) {
        $known = $known || ($p !== '' && stripos(rtrim($p, '/') . '/', "$at/") === 0);
    }
    return ['share' => $share, 'fresh' => !$known];
}

/**
 * Unraid's standard shares that are missing (HOME_STANDARD; with $data the data folder's share first) and the optional
 * ones (HOME_OPTIONAL, names only).
 *
 * @return array{missing: list<array{share: string, fresh: bool}>, optional: list<string>, pool: ?string}
 */
function homeMissing(?string $data = null, ?array $h = null): array
{
    $h ??= homeHost();
    $missing = [];
    foreach (array_unique(array_merge($data !== null ? [$data] : [], HOME_STANDARD)) as $share) {
        if (($s = homeShareState($share, $h)) !== null) {
            $missing[] = $s;
        }
    }
    $optional = [];
    foreach (HOME_OPTIONAL as $share) {
        if (homeShareState($share, $h) !== null) {
            $optional[] = $share;
        }
    }
    return ['missing' => $missing, 'optional' => $optional, 'pool' => homePool($h)];
}

/**
 * The data folder's share while it is missing: null when it is there (or the data folder lies elsewhere), else
 * {share, fresh, pool, missing, optional} — `share`/`fresh` the data folder's, `missing` every standard share missing.
 */
function homeLook(string $dataUser, ?array $h = null): ?array
{
    $h ??= homeHost();
    $share = homeShareOf($dataUser, $h['mnt_user']);
    if ($share === null || ($state = homeShareState($share, $h)) === null) {
        return null;
    }
    return $state + homeMissing($share, $h);
}

/** The new ones of the missing (what the button creates) */
function homeFreshNames(array $missing): array
{
    return array_values(array_map(fn ($s) => $s['share'], array_filter($missing, fn ($s) => $s['fresh'])));
}

/**
 * The user clicked «create» while the data folder is missing: a request in the RAM inbox (officeHomeRequestFile(), the
 * web side's officeHomeAsk()), new enough, a plain file of root's — taken (removed) whatever it says. The shares asked
 * for (valid names), or null: no click.
 *
 * @return list<string>|null
 */
function homeRequestTake(?string $file = null): ?array
{
    $file ??= officeHomeRequestFile();
    clearstatcache(true, $file);
    $st = @lstat($file);
    if ($st === false) {
        return null;
    }
    $text = ($st['mode'] & 0170000) === 0100000 && $st['size'] <= 1024 ? (string) @file_get_contents($file, false, null, 0, 1024) : '';
    @unlink($file);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $j = json_decode($text, true);
    $shares = is_array($j) ? ($j['shares'] ?? null) : null;
    if ($st['uid'] !== $me || $st['mtime'] < time() - HOME_REQUEST_TTL || !is_array($shares) || !$shares || count($shares) > 4
        || !array_is_list($shares)) {
        return null;
    }
    foreach ($shares as $s) {
        if (!is_string($s) || !homeShareValid($s)) {
            return null;
        }
    }
    return array_values(array_unique($shares));
}

/**
 * Unraid's ⟦Shares⟧ page's «Add Share», sent to emhttp the way its pages do (emcmd): the name, primary storage the pool
 * (only) or the array, everything else as the empty form has it. Then waits until /mnt/user shows the share.
 *
 * @return array{ok: bool, error?: array{key: string, params: array}}
 */
function homeMake(string $share, ?string $pool, ?array $h = null): array
{
    $h ??= homeHost();
    if (!homeShareValid($share)) {
        return ['ok' => false, 'error' => ['key' => 'home_failed', 'params' => ['share' => $share, 'detail' => 'name']]];
    }
    $form = [
        'shareNameOrig' => '', 'shareName' => $share, 'shareComment' => '', 'shareFloor' => '',
        'shareUseCache' => $pool !== null ? 'only' : 'no', 'shareCachePool' => $pool ?? '', 'shareCachePool2' => '',
        'shareCOW' => 'auto', 'shareAllocator' => 'highwater', 'shareSplitLevel' => '', 'shareInclude' => '', 'shareExclude' => '',
        'cmdEditShare' => 'Add Share',
    ];
    [$exit, $out, $err] = run([$h['emcmd'], http_build_query($form, '', '&', PHP_QUERY_RFC3986)], 60);
    if ($exit !== 0) {
        $said = mb_substr(trim($out . ' ' . $err), 0, 200);
        return ['ok' => false, 'error' => ['key' => 'home_failed', 'params' => ['share' => $share, 'detail' => $said !== '' ? $said : "exit $exit"]]];
    }
    $until = microtime(true) + $h['wait'];
    $at = rtrim($h['mnt_user'], '/') . "/$share";
    do {
        clearstatcache(true, $at);
        if (is_dir($at)) {
            return ['ok' => true];
        }
        usleep(250000);
    } while (microtime(true) < $until);
    return ['ok' => false, 'error' => ['key' => 'home_not_there', 'params' => ['share' => $share]]];
}

/**
 * The shares a click asked for, each looked at again: all of them missing and new now (else nothing at all,
 * home_stale), then made one after the other (the first refusal ends it). The log line for each made.
 *
 * @return array{ok: bool, made: list<string>, log: list<string>, error?: array{key: string, params: array}}
 */
function homeMakeAll(array $asked, ?array $h = null): array
{
    $h ??= homeHost();
    $pool = homePool($h);
    foreach ($asked as $share) {
        if (!(homeShareState($share, $h)['fresh'] ?? false)) {
            return ['ok' => false, 'made' => [], 'log' => [], 'error' => ['key' => 'home_stale', 'params' => []]];
        }
    }
    $made = $log = [];
    foreach ($asked as $share) {
        $r = homeMake($share, $pool, $h);
        if (!$r['ok']) {
            return ['ok' => false, 'made' => $made, 'log' => $log, 'error' => $r['error']];
        }
        $made[] = $share;
        $log[] = "Created the share $share on the user's click (Unraid's «Add Share», " . ($pool !== null ? "only on the pool $pool" : 'on the array') . ')';
    }
    return ['ok' => true, 'made' => $made, 'log' => $log];
}

/**
 * One look while the data folder is missing (agent.php's loop, array started): what is there goes to the page
 * (officeHomeLookFile(), 0600 in RAM); a click waiting is carried out — only for shares missing and new, the data
 * folder's among them. The last answer to a click (made / error) stays in the look until the share is there (the agent
 * then starts anew: agent.php), and makeDataDir() reads its log lines from there. Nothing to offer: the file goes.
 */
function homeTick(string $dataUser, ?string $lookFile = null, ?string $requestFile = null, ?array $h = null): ?array
{
    $lookFile ??= officeHomeLookFile();
    $look = homeLook($dataUser, $h);
    if ($look === null) {
        @unlink($lookFile);
        @unlink($requestFile ?? officeHomeRequestFile());
        unset($GLOBALS['homeAnswer']);
        return null;
    }
    $answer = $GLOBALS['homeAnswer'] ?? null;
    if (is_array($answer) && ($answer['share'] ?? '') !== $look['share']) {
        $answer = null;
    }
    if (($asked = homeRequestTake($requestFile)) !== null) {
        $answer = ['share' => $look['share'], 'time' => time()];
        if (!$look['fresh'] || !in_array($look['share'], $asked, true) || array_diff($asked, homeFreshNames($look['missing']))) {
            $answer['error'] = ['key' => 'home_stale', 'params' => []];
        } else {
            // the data folder's share last: once it is there the agent moves in
            $order = array_merge(array_values(array_diff($asked, [$look['share']])), [$look['share']]);
            $r = homeMakeAll($order, $h);
            // the log lies in the data folder, which comes only now: makeDataDir() writes the lines down once it is there
            $answer += $r['ok'] ? ['made' => true, 'log' => $r['log']] : ['error' => $r['error'], 'log' => $r['log']];
        }
        $GLOBALS['homeAnswer'] = $answer;
        if (!empty($answer['made'])) {
            $look = homeLook($dataUser, $h) ?? $look;
        }
    }
    $out = $look + ['time' => time()] + ($answer ? ['answer' => $answer] : []);
    try {
        writeAtomic($lookFile, (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0600, 0, 0);
    } catch (RuntimeException) {
        // the page then says the careful text: nothing offered
    }
    return $out;
}
