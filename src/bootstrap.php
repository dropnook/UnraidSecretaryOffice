<?php
declare(strict_types=1);

/*
 * Shared entry for the page and the API.
 *
 * In the container: DocumentRoot = /var/www/html (public/), this folder sits
 * next to it as /var/www/src. Runtime data lives in /var/www/data — the agent
 * on the host writes each desk's state there and picks up requests from the
 * mailbox (see agent/agent.php).
 *
 * Environment:
 *   OFFICE_DATA_DIR   data folder shared with the agent (default /var/www/data)
 *   TZ                time zone for the few dates the server formats
 */

const OFFICE_VERSION = '1.9.1';
// where the tip jar leads after hiring someone ('' = no button, only the thank-you)
const OFFICE_TIP_URL = 'https://paypal.me/vipermark2';

// public/ is the DocumentRoot: /var/www/html in the container, ../public in the repository
define('OFFICE_PUBLIC', rtrim(getenv('OFFICE_PUBLIC_DIR') ?: (is_dir(dirname(__DIR__) . '/html') ? dirname(__DIR__) . '/html' : dirname(__DIR__) . '/public'), '/'));
define('OFFICE_DATA', rtrim(getenv('OFFICE_DATA_DIR') ?: '/var/www/data', '/'));

$zone = getenv('TZ');
date_default_timezone_set($zone && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

require __DIR__ . '/mailbox.php';
require __DIR__ . '/desks.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/staff.php';

function readJsonFile(string $file): ?array
{
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
