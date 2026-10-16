<?php
declare(strict_types=1);

/*
 * The office's first home (2026-10-16): the data folder lies in <appdata>/UnraidSecretaryOffice/data, and Unraid makes
 * the appdata share only once Docker is turned on — a fresh server without Docker has none, and the office could do
 * nothing but say so. While the agent waits for its data folder (agent.php's loop, makeDataDir()) it looks at that share
 * every few seconds and tells the page in RAM (RUN_DIR/home.json, officeHomeLookFile()):
 *
 *   never there  (`fresh`)  Unraid knows no such share — no /boot/config/shares/<share>.cfg, no entry in shares.ini —
 *                           and Docker doesn't keep its apps there: a first start. The page welcomes and offers a
 *                           button; on the user's click (the web side's officeHomeAsk(): a request in the RAM inbox and
 *                           the doorbell) the agent asks Unraid to add the share the way its ⟦Shares⟧ page does
 *                           (emcmd «Add Share»), with Unraid's defaults for appdata: only on the first pool (cache when
 *                           there is one), else on the array. Never by itself.
 *   gone                    Unraid knew it (its .cfg, Docker's path): the careful text as before, nothing offered —
 *                           what was there may come back (a disk, a folder put back).
 *
 * Once the share is there makeDataDir() creates the data folder within seconds and the office starts as ever.
 * The tests put stand-ins into $GLOBALS['homeHost'] (homeHost()) — emcmd always a stand-in there.
 */

const HOME_LOOK_EVERY   = 5;        // seconds between two looks while the data folder is missing
const HOME_REQUEST_TTL  = 120;      // a click older than this is no longer the user's wish
const HOME_WAIT_SHARE   = 15;       // seconds Unraid gets to show the new share under /mnt/user
const HOME_EMCMD        = '/usr/local/sbin/emcmd';

/** Where the look goes and what it reads (tests: stand-ins in $GLOBALS['homeHost']) */
function homeHost(): array
{
    $h = $GLOBALS['homeHost'] ?? [];
    return [
        'shares_dir' => $h['shares_dir'] ?? '/boot/config/shares',
        'shares_ini' => $h['shares_ini'] ?? '/var/local/emhttp/shares.ini',
        'docker_cfg' => $h['docker_cfg'] ?? '/boot/config/docker.cfg',
        'pools_dir'  => $h['pools_dir'] ?? '/boot/config/pools',
        'mnt_user'   => $h['mnt_user'] ?? '/mnt/user',
        'emcmd'      => $h['emcmd'] ?? HOME_EMCMD,
        'wait'       => $h['wait'] ?? HOME_WAIT_SHARE,
    ];
}

/**
 * The share the data folder lies in — <mnt_user>/<share>/UnraidSecretaryOffice/data, a name Unraid's ⟦Shares⟧ page
 * accepts (a letter first, letters, digits, «.», «_», «-», at most 40, not ending in «.») — else null: nothing to offer.
 */
function homeShareOf(string $dataUser, string $mntUser = '/mnt/user'): ?string
{
    $q = preg_quote(rtrim($mntUser, '/'), '#');
    if (!preg_match("#^$q/([A-Za-z][A-Za-z0-9._-]{0,39})/UnraidSecretaryOffice/data/?\$#D", $dataUser, $m) || str_ends_with($m[1], '.')) {
        return null;
    }
    return $m[1];
}

/** Unraid's pools (/boot/config/pools/<name>.cfg): cache first when there is one — Unraid's own default for appdata */
function homePool(?array $h = null): ?string
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
    return in_array('cache', $names, true) ? 'cache' : ($names[0] ?? null);
}

/**
 * The share of the data folder while it is missing: null when it is there (or the data folder lies elsewhere), else
 * {share, fresh, pool}. Cheap: two config files, a folder list on the flash, one stat — no disk of the array.
 *
 * @return array{share: string, fresh: bool, pool: ?string}|null
 */
function homeLook(string $dataUser, ?array $h = null): ?array
{
    $h ??= homeHost();
    $share = homeShareOf($dataUser, $h['mnt_user']);
    if ($share === null) {
        return null;
    }
    clearstatcache(true, $h['mnt_user'] . "/$share");
    if (file_exists($h['mnt_user'] . "/$share")) {
        return null;
    }
    // Unraid knew it: its settings on the flash (any case — Unraid's share names don't tell case apart), or emhttp lists it
    $known = false;
    foreach (glob($h['shares_dir'] . '/*.cfg') ?: [] as $f) {
        $known = $known || strcasecmp(basename($f, '.cfg'), $share) === 0;
    }
    foreach (array_keys(readCfg($h['shares_ini'], true)) as $name) {
        $known = $known || strcasecmp((string) $name, $share) === 0;
    }
    // Docker on with its apps in this share: Unraid made it once — gone, not new
    $docker = readCfg($h['docker_cfg']);
    $apps = rtrim((string) ($docker['DOCKER_APP_CONFIG_PATH'] ?? '') ?: '/mnt/user/appdata', '/') . '/';
    $dockerHere = ($docker['DOCKER_ENABLED'] ?? '') === 'yes'
        && stripos($apps, rtrim($h['mnt_user'], '/') . "/$share/") === 0;
    return ['share' => $share, 'fresh' => !$known && !$dockerHere, 'pool' => homePool($h)];
}

/**
 * The user clicked «create»: a request in the RAM inbox (officeHomeRequestFile(), the web side's officeHomeAsk()) for
 * exactly this share, new enough, a plain file of root's — taken (removed) whatever it says.
 */
function homeRequestTake(string $share, ?string $file = null): bool
{
    $file ??= officeHomeRequestFile();
    clearstatcache(true, $file);
    $st = @lstat($file);
    if ($st === false) {
        return false;
    }
    $text = ($st['mode'] & 0170000) === 0100000 && $st['size'] <= 512 ? (string) @file_get_contents($file, false, null, 0, 512) : '';
    @unlink($file);
    $me = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $j = json_decode($text, true);
    return $st['uid'] === $me && $st['mtime'] >= time() - HOME_REQUEST_TTL && is_array($j)
        && is_string($j['share'] ?? null) && $j['share'] === $share;
}

/**
 * Unraid's ⟦Shares⟧ page's «Add Share», sent to emhttp the way its pages do (emcmd): the name, primary storage the pool
 * (only) or the array, everything else as the empty form has it. Then waits until /mnt/user shows the share.
 *
 * @return array{ok: bool, error?: array{key: string, params: array}}
 */
function homeMake(array $look, ?array $h = null): array
{
    $h ??= homeHost();
    $share = $look['share'];
    if (homeShareOf("{$h['mnt_user']}/$share/UnraidSecretaryOffice/data", $h['mnt_user']) !== $share) {
        return ['ok' => false, 'error' => ['key' => 'home_failed', 'params' => ['share' => $share, 'detail' => 'name']]];
    }
    $pool = $look['pool'];
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
    do {
        clearstatcache(true, "{$h['mnt_user']}/$share");
        if (is_dir("{$h['mnt_user']}/$share")) {
            return ['ok' => true];
        }
        usleep(250000);
    } while (microtime(true) < $until);
    return ['ok' => false, 'error' => ['key' => 'home_not_there', 'params' => ['share' => $share]]];
}

/**
 * One look while the data folder is missing (agent.php's loop, array started): what is there goes to the page
 * (officeHomeLookFile(), 0600 in RAM); a click waiting for this share is carried out. The last answer to a click
 * (made / error) stays in the look until the share is there (the agent then starts anew: agent.php), and
 * makeDataDir() reads its log line from there. Nothing to offer: the file goes.
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
    if (homeRequestTake($look['share'], $requestFile)) {
        if (!$look['fresh']) {
            $answer = ['share' => $look['share'], 'time' => time(), 'error' => ['key' => 'home_stale', 'params' => []]];
        } else {
            $r = homeMake($look, $h);
            // the log lies in the data folder, which comes only now: makeDataDir() writes the line down once it is there
            $answer = ['share' => $look['share'], 'time' => time()] + ($r['ok'] ? ['made' => true,
                'log' => "Created the share {$look['share']} on the user's click (Unraid's «Add Share», "
                    . ($look['pool'] !== null ? "only on the pool {$look['pool']}" : 'on the array') . ')'] : ['error' => $r['error']]);
        }
        $GLOBALS['homeAnswer'] = $answer;
    }
    $out = $look + ['time' => time()] + ($answer ? ['answer' => $answer] : []);
    try {
        writeAtomic($lookFile, (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0600, 0, 0);
    } catch (RuntimeException) {
        // the page then says the careful text: nothing offered
    }
    return $out;
}
