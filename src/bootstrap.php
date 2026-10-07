<?php
declare(strict_types=1);

/*
 * Shared entry for the page and the API.
 *
 * The web files and this folder lie side by side in the plugin's folder
 * /usr/local/emhttp/plugins/unraid-secretary-office (see place.php); Unraid's
 * nginx serves them behind its login, the data folder comes from the plugin's
 * .cfg. The agent writes each desk's state into the data folder and picks up
 * requests from its mailbox (see agent/agent.php).
 *
 * Environment (tests only):
 *   OFFICE_DATA_DIR   another data folder than the plugin's DATA_DIR
 */

const OFFICE_VERSION = '1.31.0';
// where the tip jar leads after hiring someone ('' = no button, only the thank-you)
const OFFICE_TIP_URL = 'https://paypal.me/vipermark2';
// the support page (PayPal checkout that shows the supporter key right after the tip, and again for a lost one):
// set, the tip jar's main button opens <url>?id=<server ID>&lang=<language> instead of OFFICE_TIP_URL ('' = not yet)
const OFFICE_SUPPORT_URL = '';
// GitHub Sponsors in the tip jar ('' = no button; set once the maintainer's Sponsors profile is active)
const OFFICE_SPONSOR_URL = '';

require __DIR__ . '/place.php';

define('OFFICE_PUBLIC', dirname(__DIR__));      // the web files: next to src/ in the plugin's folder
define('OFFICE_DATA', rtrim(getenv('OFFICE_DATA_DIR') ?: officePluginDataDir(), '/'));

// inside Unraid's own page (SecretaryOffice.page) the office leaves Unraid's settings alone
defined('OFFICE_IN_UNRAID') || define('OFFICE_IN_UNRAID', false);
if (!OFFICE_IN_UNRAID) {
    // Unraid's PHP has already set the server's zone (local_prepend.php)
    $zone = date_default_timezone_get();
    date_default_timezone_set($zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
    unset($zone);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    if (PHP_SAPI !== 'cli') {
        header_remove('X-Powered-By');
    }
}

/** A request the web side refuses itself (hiring, firing): an error key for the page and the HTTP status */
final class OfficeProblem extends RuntimeException
{
    public function __construct(public readonly string $key, public readonly int $status = 400, public readonly array $params = [])
    {
        parent::__construct($key);
    }
}

require __DIR__ . '/mailbox.php';
require __DIR__ . '/desks.php';
require __DIR__ . '/staff.php';
require __DIR__ . '/supporter.php';

/** (not readJsonFile: Community Applications and Fix Common Problems have one of that name) */
function officeReadJson(string $file): ?array
{
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
