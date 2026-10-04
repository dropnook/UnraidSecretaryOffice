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
