<?php
declare(strict_types=1);

/*
 * Where the office lives — shared by the web side and the agent.
 *
 * The office is an Unraid plugin: its code lies in RAM under
 * /usr/local/emhttp/plugins/unraid-secretary-office (the web files at the top,
 * src/, agent/, backup/ … beside them), Unraid's own nginx/PHP serve the page
 * behind the Unraid login, the agent is a service on the host.
 *
 * The plugin keeps one small setting on the flash (DATA_DIR in its .cfg);
 * state, logs and the backup engine's data stay in appdata, which comes with
 * the array.
 */

const OFFICE_PLUGIN     = 'unraid-secretary-office';
const OFFICE_PLUGIN_CFG = '/boot/config/plugins/' . OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cfg';
// The Unraid version the office was tested on, major.minor — the ONE place (here: shared by the web side and the agent).
// The .plg's max is <major>.99.99 (officeUnraidMax(); plugin/build.sh refuses another): Unraid moves a plugin whose max
// it exceeds to plugins-error at boot — the office and its nightly backups gone without a word. The team lead warns
// beyond this version (caretakerUnraidTested()). Raise it (and with a new major the .plg's max) only once tested.
const OFFICE_UNRAID_TESTED = '7.3';

// «Report a problem or a wish…» (agent/lib/report.php): the makers' inbox — a Cloudflare Worker that turns a report into
// an issue in a private GitHub repository. Shared by the web side (the menu item shows only with an address) and the
// agent (the only one that sends — never without the user's click on «Send»). '' = the feature is hidden. A line
// FEEDBACK_URL="http://<host>:<port>" in the plugin's .cfg on the flash points it elsewhere (tests: a Worker on the
// developer's machine) — officeFeedbackUrl().
const OFFICE_FEEDBACK_URL = 'https://feedback.uso.dropnook.app';

/**
 * Where reports go: FEEDBACK_URL of the plugin's .cfg when it is set and an address of exactly the shape
 * http(s)://<host>[:<port>] (no path, no user, nothing else — read like DATA_DIR), else OFFICE_FEEDBACK_URL. $cfg: the
 * tests' own file.
 */
function officeFeedbackUrl(string $cfg = OFFICE_PLUGIN_CFG): string
{
    $set = @parse_ini_file($cfg, false, INI_SCANNER_RAW) ?: [];
    $url = is_string($set['FEEDBACK_URL'] ?? null) ? trim($set['FEEDBACK_URL'], " \t\"'") : '';
    if ($url !== '' && preg_match('#^https?://[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?/?$#D', $url)) {
        return rtrim($url, '/');
    }
    return OFFICE_FEEDBACK_URL;
}

/** The .plg's max for the tested version: <major>.99.99 */
function officeUnraidMax(string $tested = OFFICE_UNRAID_TESTED): string
{
    return explode('.', $tested)[0] . '.99.99';
}

/** The plugin's data folder: DATA_DIR in its .cfg, by default <appdata>/UnraidSecretaryOffice/data */
function officePluginDataDir(): string
{
    $cfg = @parse_ini_file(OFFICE_PLUGIN_CFG) ?: [];
    if (is_string($cfg['DATA_DIR'] ?? null) && str_starts_with($cfg['DATA_DIR'], '/mnt/')) {
        return rtrim($cfg['DATA_DIR'], '/');
    }
    $docker = @parse_ini_file('/boot/config/docker.cfg') ?: [];
    $appdata = rtrim((string) ($docker['DOCKER_APP_CONFIG_PATH'] ?? '') ?: '/mnt/user/appdata', '/');
    return "$appdata/UnraidSecretaryOffice/data";
}

/**
 * Unraid's exclusive shares (Settings → Global Share Settings → Permit exclusive shares; emhttpd decides at every
 * array start): /mnt/user/<share> is then Unraid's symlink `../<pool>/<share>` (or `/mnt/<pool>/<share>`) to the
 * share's only volume, past shfs (FUSE). That one link — exactly this shape, the same share name, a real folder
 * behind it — is followed: $path comes back on the pool, reached there without shfs (nostromo, 2026-10-07: the agent's
 * look at its data folder and mailbox 4 µs instead of 200 µs). Anything else comes back as it is: a path outside
 * /mnt/user, a share that is no link (secondary
 * storage, a folder of it on another disk or pool), any other link. One lstat, a readlink and one lstat — nothing is
 * opened, nothing woken (a pool behind such a link is awake whenever its share is reached at all). The one helper for
 * the agent (its data folder, the Consultant, Ms. Dustdevil) and the web side (its data folder); $mnt: another /mnt
 * for the tests.
 */
function officeUnraidPath(string $path, string $mnt = '/mnt'): string
{
    $q = preg_quote($mnt, '#');
    if (!preg_match("#^$q/user/([^/]+)(/.*)?\$#D", $path, $m)) {
        return $path;
    }
    $share = "$mnt/user/{$m[1]}";
    clearstatcache(true, $share);
    if (!is_link($share)) {
        return $path;
    }
    $to = (string) @readlink($share);
    if (!preg_match("#^(?:\\.\\./|$q/)([^/]+)/([^/]+)/?\$#D", $to, $t) || $t[2] !== $m[1]
        || in_array($t[1], ['user', 'user0', 'addons', 'remotes', 'disks', 'rootshare'], true)) {
        return $path;
    }
    $real = "$mnt/{$t[1]}/{$m[1]}";
    clearstatcache(true, $real);
    $st = @lstat($real);
    return ($st && ($st['mode'] & 0170000) === 0040000) ? $real . ($m[2] ?? '') : $path;
}

/**
 * The office's folder in RAM (/run is a tmpfs): the agent's RUN_DIR — its pid and heartbeat, locks, the doorbell, the
 * night shift — root only (0700). The web side reads the heartbeat and the night shift's state there and rings the
 * doorbell, nothing else. OFFICE_RUN_DIR points elsewhere: the tests never meet the live agent's folder.
 */
function officeRunDir(): string
{
    return rtrim(getenv('OFFICE_RUN_DIR') ?: '/var/run/unraid-secretary-office', '/');
}

/*
 * The office's entry in Unraid's web UI: the page
 * SecretaryOffice.page. Where it shows is its Menu= line — its own entry in
 * the menu bar (Tasks:85, between Apps and Tools; the label its Name= line),
 * an icon under Settings → User Utilities as before 1.17 (Utilities; then
 * Title=, Icon= and Tag= name it there), or only a button in Unraid's header
 * (the page has no Menu= then — Unraid still shows it at /SecretaryOffice —
 * and SecretaryOfficeButton.page gets Menu="Buttons:…"; Unraid loads a
 * button page on every page, so it holds nothing but the jump).
 * Unraid reads .page files on every request, so a change shows after a
 * reload. The user's choice is MENU_NAME and MENU_PLACE in the plugin's .cfg
 * on the flash; the pages in RAM get it again at every install and boot (the
 * .plg) and when it changes (the caretaker's action menu_name).
 */
const OFFICE_MENU_PAGE    = 'SecretaryOffice.page';
const OFFICE_BUTTON_PAGE  = 'SecretaryOfficeButton.page';
const OFFICE_MENU_DEFAULT = 'Sekretariat';
const OFFICE_MENU_MAX     = 15;
const OFFICE_MENU_PLACES  = ['menu' => 'Tasks:85', 'settings' => 'Utilities', 'button' => null];

/** A label fit for the menu bar and for the .page header (an ini value in quotes) */
function officeMenuNameValid(string $name): bool
{
    return mb_strlen($name) <= OFFICE_MENU_MAX && preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N} .&+_-]*[\p{L}\p{N}.])?$/uD', $name) === 1;
}

/** The label the user chose, else the default */
function officeMenuName(): string
{
    $cfg = @parse_ini_file(OFFICE_PLUGIN_CFG) ?: [];
    $name = trim((string) ($cfg['MENU_NAME'] ?? ''));
    return officeMenuNameValid($name) ? $name : OFFICE_MENU_DEFAULT;
}

/** Where the office shows in Unraid: 'menu' (its own entry in the menu bar, the default) or 'settings' */
function officeMenuPlace(): string
{
    $cfg = @parse_ini_file(OFFICE_PLUGIN_CFG) ?: [];
    $place = (string) ($cfg['MENU_PLACE'] ?? '');
    return array_key_exists($place, OFFICE_MENU_PLACES) ? $place : 'menu';
}

/** The office's address in Unraid's web UI */
function officeMenuUrl(string $place): string
{
    return ($place === 'settings' ? '/Settings/' : '/') . basename(OFFICE_MENU_PAGE, '.page');
}

/**
 * Puts place and label into the pages' headers (new file + rename): Menu= and
 * Name=; outside the menu bar also Title= and Tag= (Unraid's title bar), under
 * Settings Icon=; the button page gets Menu="Buttons:…" only as a button.
 * False if the page isn't there or the choice isn't valid.
 */
function officeMenuPageApply(string $dir, string $name, string $place = 'menu'): bool
{
    if (!officeMenuNameValid($name) || !array_key_exists($place, OFFICE_MENU_PLACES)) {
        return false;
    }
    $own = [];
    if ($place !== 'button') {
        $own[] = 'Menu="' . OFFICE_MENU_PLACES[$place] . '"';
    }
    $own[] = 'Name="' . $name . '"';
    if ($place !== 'menu') {                // no entry of its own in the menu bar: Unraid's title bar names it
        $own[] = 'Title="' . $name . '"';
        $own[] = 'Tag="building-o"';        // the icon in that title bar
    }
    if ($place === 'settings') {
        $own[] = 'Icon="unraid-secretary-office.png"';
    }
    $button = $place === 'button' ? ['Menu="Buttons:90"', 'Title="' . $name . '"'] : ['Title="' . $name . '"'];
    return officePageHeader("$dir/" . OFFICE_MENU_PAGE, $own, ['Menu', 'Name', 'Title', 'Icon', 'Tag'])
        && (!is_file("$dir/" . OFFICE_BUTTON_PAGE) || officePageHeader("$dir/" . OFFICE_BUTTON_PAGE, $button, ['Menu', 'Title']));
}

/** A .page with its header lines $keys replaced by $own (first), the rest as it was; new file + rename */
function officePageHeader(string $page, array $own, array $keys): bool
{
    $text = @file_get_contents($page);
    if ($text === false) {
        return false;
    }
    [$header, $body] = array_pad(explode("\n---\n", $text, 2), 2, null);
    if ($body === null) {
        return false;
    }
    $lines = [];
    foreach (explode("\n", $header) as $line) {
        if (!in_array(strtok($line, '='), $keys, true)) {
            $lines[] = $line;
        }
    }
    $new = implode("\n", array_merge($own, $lines)) . "\n---\n" . $body;
    if ($new === $text) {
        return true;
    }
    $tmp = dirname($page) . '/.' . basename($page) . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $new) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    return @rename($tmp, $page) || (@unlink($tmp) && false);
}

/**
 * Where the web side hands secrets to the agent (the Consultant's Kopia setup):
 * a root-only folder in /run — a tmpfs, RAM — never the mailbox, which lies in
 * the data folder on the pool (snapshotted, backed up). See apiSecretStash()
 * in src/api.php and advisorSecretTake() in agent/desks/advisor.php.
 * OFFICE_INBOX_DIR (or OFFICE_RUN_DIR) points elsewhere for the tests.
 */
function officeInboxDir(): string
{
    return rtrim(getenv('OFFICE_INBOX_DIR') ?: officeRunDir() . '/inbox', '/');
}
