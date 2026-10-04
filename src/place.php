<?php
declare(strict_types=1);

/*
 * Where the office lives — shared by the web side and the agent.
 *
 * Two ways to run it:
 *   plugin   installed by Unraid's plugin manager: code in RAM under
 *            /usr/local/emhttp/plugins/unraid-secretary-office (web files and
 *            src/, agent/, backup/ side by side), served by Unraid's own
 *            nginx/PHP behind the Unraid login, the agent a service on the host
 *   stack    the repository in appdata, run by the Compose stack (compose.yaml)
 *
 * The plugin keeps one small setting on the flash (DATA_DIR in its .cfg);
 * state, logs and the backup engine's data stay in appdata, which comes with
 * the array.
 */

const OFFICE_PLUGIN     = 'unraid-secretary-office';
const OFFICE_PLUGIN_CFG = '/boot/config/plugins/' . OFFICE_PLUGIN . '/' . OFFICE_PLUGIN . '.cfg';

/** Is the office in $dir (where src/ lies) installed as a plugin? */
function officeIsPlugin(string $dir): bool
{
    return str_starts_with($dir . '/', '/usr/local/emhttp/plugins/');
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

/*
 * The office's entry in Unraid's web UI (plugin only): the page
 * SecretaryOffice.page. Where it shows is its Menu= line — its own entry in
 * the menu bar (Tasks:85, between Apps and Tools; the label its Name= line)
 * or, as before 1.17, an icon under Settings → User Utilities (Utilities;
 * then Title=, Icon= and Tag= name it there). Unraid reads .page files on every
 * request, so a change shows after a reload. The user's choice is MENU_NAME
 * and MENU_PLACE in the plugin's .cfg on the flash; the page in RAM gets it
 * again at every install and boot (the .plg) and when it changes (the
 * caretaker's action menu_name).
 */
const OFFICE_MENU_PAGE    = 'SecretaryOffice.page';
const OFFICE_MENU_DEFAULT = 'Sekretariat';
const OFFICE_MENU_MAX     = 15;
const OFFICE_MENU_PLACES  = ['menu' => 'Tasks:85', 'settings' => 'Utilities'];

/** A label fit for the menu bar and for the .page header (an ini value in quotes) */
function officeMenuNameValid(string $name): bool
{
    return mb_strlen($name) <= OFFICE_MENU_MAX && preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N} .&+_-]*[\p{L}\p{N}.])?$/u', $name) === 1;
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
    return isset(OFFICE_MENU_PLACES[$place]) ? $place : 'menu';
}

/** The office's address in Unraid's web UI */
function officeMenuUrl(string $place): string
{
    return ($place === 'settings' ? '/Settings/' : '/') . basename(OFFICE_MENU_PAGE, '.page');
}

/**
 * Puts place and label into the page's header (new file + rename): Menu= and
 * Name=; under Settings also Title=, Icon= and Tag=, which Unraid shows there.
 * False if the page isn't there or the choice isn't valid.
 */
function officeMenuPageApply(string $dir, string $name, string $place = 'menu'): bool
{
    $page = "$dir/" . OFFICE_MENU_PAGE;
    $text = @file_get_contents($page);
    if ($text === false || !officeMenuNameValid($name) || !isset(OFFICE_MENU_PLACES[$place])) {
        return false;
    }
    [$header, $body] = array_pad(explode("\n---\n", $text, 2), 2, null);
    if ($body === null) {
        return false;
    }
    $lines = [];
    foreach (explode("\n", $header) as $line) {
        $key = strtok($line, '=');
        if (!in_array($key, ['Menu', 'Name', 'Title', 'Icon', 'Tag'], true)) {
            $lines[] = $line;
        }
    }
    $own = ['Menu="' . OFFICE_MENU_PLACES[$place] . '"', 'Name="' . $name . '"'];
    if ($place === 'settings') {
        $own[] = 'Title="' . $name . '"';
        $own[] = 'Icon="unraid-secretary-office.png"';
        $own[] = 'Tag="bell-o"';          // the icon in Unraid's title bar above the office
    }
    $new = implode("\n", array_merge($own, $lines)) . "\n---\n" . $body;
    if ($new === $text) {
        return true;
    }
    $tmp = "$dir/." . OFFICE_MENU_PAGE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $new) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    return @rename($tmp, $page) || (@unlink($tmp) && false);
}
