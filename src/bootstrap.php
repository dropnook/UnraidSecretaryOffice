<?php
declare(strict_types=1);

/*
 * Shared entry for the page and the API.
 *
 * As a plugin (see place.php): the web files and this folder lie side by side
 * in /usr/local/emhttp/plugins/unraid-secretary-office, Unraid's nginx serves
 * them behind its login, the data folder comes from the plugin's .cfg.
 * In the container: DocumentRoot = /var/www/html (public/), this folder sits
 * next to it as /var/www/src, runtime data in /var/www/data.
 * Either way the agent writes each desk's state into the data folder and
 * picks up requests from its mailbox (see agent/agent.php).
 *
 * Environment (container):
 *   OFFICE_DATA_DIR   data folder shared with the agent (default /var/www/data)
 *   TZ                time zone for the few dates the server formats
 */

const OFFICE_VERSION = '1.20.2';
// where the tip jar leads after hiring someone ('' = no button, only the thank-you)
const OFFICE_TIP_URL = 'https://paypal.me/vipermark2';

require __DIR__ . '/place.php';

define('OFFICE_AS_PLUGIN', officeIsPlugin(dirname(__DIR__)));
// the web files: next to src/ in the plugin, /var/www/html in the container, ../public in the repository
define('OFFICE_PUBLIC', rtrim(getenv('OFFICE_PUBLIC_DIR') ?: match (true) {
    OFFICE_AS_PLUGIN                   => dirname(__DIR__),
    is_dir(dirname(__DIR__) . '/html') => dirname(__DIR__) . '/html',
    default                            => dirname(__DIR__) . '/public',
}, '/'));
define('OFFICE_DATA', rtrim(getenv('OFFICE_DATA_DIR') ?: (OFFICE_AS_PLUGIN ? officePluginDataDir() : '/var/www/data'), '/'));

// inside Unraid's own page (SecretaryOffice.page) the office leaves Unraid's settings alone
defined('OFFICE_IN_UNRAID') || define('OFFICE_IN_UNRAID', false);
if (!OFFICE_IN_UNRAID) {
    // Unraid's PHP has already set the server's zone (local_prepend.php)
    $zone = getenv('TZ') ?: date_default_timezone_get();
    date_default_timezone_set($zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
    unset($zone);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (PHP_SAPI !== 'cli') {
        header_remove('X-Powered-By');
    }
}

require __DIR__ . '/mailbox.php';
require __DIR__ . '/desks.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/staff.php';

/** (not readJsonFile: Community Applications and Fix Common Problems have one of that name) */
function officeReadJson(string $file): ?array
{
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
