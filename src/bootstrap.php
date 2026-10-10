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

const OFFICE_VERSION = '1.57.0';
// where the tip jar leads after hiring someone ('' = no button, only the thank-you)
const OFFICE_TIP_URL = 'https://paypal.me/dropnook';
// the support page (OFFICE_SUPPORT_URL) lives in place.php since 1.48: the agent asks it for a tip's key (a claim)
// GitHub Sponsors in the tip jar ('' = no button; set once the maintainer's Sponsors profile is active)
const OFFICE_SPONSOR_URL = '';
// «Report a problem or a wish…»: the dialog's head names the other ways — the plugin's GitHub issues (for those with an
// account) and, once it exists, the forum thread ('' = not shown). The inbox itself is OFFICE_FEEDBACK_URL (src/place.php)
const OFFICE_ISSUES_URL = 'https://github.com/dropnook/UnraidSecretaryOffice/issues';
const OFFICE_FORUM_URL = '';
// the theme switch at the reception — Automatic (Unraid's theme) · Dark · Light, per browser (an experiment,
// 2026-10-07): false = nothing of it shows or loads (public/assets/theme-switch.css and .js, the lines in page.php
// and core.js marked «theme-switch»); CLAUDE.md «Theme switch» says how to remove it for good
const OFFICE_THEME_SWITCH = true;
// the text-size switch at the reception — A · A · A, per browser (for people with glasses, 2026-10-08): the small
// step is the office as it always was; false = nothing of it shows or loads (public/assets/size-switch.css and .js, the
// lines in page.php and core.js marked «size-switch»); CLAUDE.md «Text size switch» says how to remove it for good
const OFFICE_SIZE_SWITCH = true;

require __DIR__ . '/place.php';
require_once __DIR__ . '/words.php';      // Unraid's own words in the texts (shared with the agent)

define('OFFICE_PUBLIC', dirname(__DIR__));      // the web files: next to src/ in the plugin's folder
// the data folder as the user set it (usually /mnt/user/appdata/…, what the page names), and where the web side
// reads and writes it: on its pool directly when it lies in an exclusive share (officeUnraidPath(), past shfs —
// looked at anew with every request: one lstat and a readlink)
define('OFFICE_DATA_USER', rtrim(getenv('OFFICE_DATA_DIR') ?: officePluginDataDir(), '/'));
define('OFFICE_DATA', officeUnraidPath(OFFICE_DATA_USER));

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
