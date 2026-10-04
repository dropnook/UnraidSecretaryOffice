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
 * The office's entry in Unraid's menu bar (plugin only): the page
 * SecretaryOffice.page, its label the Name= line of that page. Unraid reads
 * .page files on every request, so a new name shows after a reload. The
 * user's choice is MENU_NAME in the plugin's .cfg on the flash; the page in
 * RAM gets it again at every install and boot (the .plg) and when it changes
 * (the caretaker's action menu_name).
 */
const OFFICE_MENU_PAGE    = 'SecretaryOffice.page';
const OFFICE_MENU_DEFAULT = 'Sekretariat';
const OFFICE_MENU_MAX     = 15;

/** A label fit for the menu bar and for the .page header (an ini value in quotes) */
function officeMenuNameValid(string $name): bool
{
    return mb_strlen($name) <= OFFICE_MENU_MAX && preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N} .&+_-]*[\p{L}\p{N}.])?$/u', $name) === 1;
}

/** The menu label the user chose, else the default */
function officeMenuName(): string
{
    $cfg = @parse_ini_file(OFFICE_PLUGIN_CFG) ?: [];
    $name = trim((string) ($cfg['MENU_NAME'] ?? ''));
    return officeMenuNameValid($name) ? $name : OFFICE_MENU_DEFAULT;
}

/** Puts the label into the page's Name= line (new file + rename); false if the page isn't there */
function officeMenuPageApply(string $dir, string $name): bool
{
    $page = "$dir/" . OFFICE_MENU_PAGE;
    $text = @file_get_contents($page);
    if ($text === false || !officeMenuNameValid($name)) {
        return false;
    }
    $new = preg_replace('/^Name="[^"\n]*"$/m', 'Name="' . $name . '"', $text, 1);
    if ($new === null || $new === $text) {
        return $new !== null;
    }
    $tmp = "$dir/." . OFFICE_MENU_PAGE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $new) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    return @rename($tmp, $page) || (@unlink($tmp) && false);
}
