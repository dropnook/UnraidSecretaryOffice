<?php
declare(strict_types=1);

/*
 * PHPStan's bootstrap (phpstan.neon, tools/phpstan.sh): the constants the office defines at runtime from a call —
 * define('RUN_DIR', officeRunDir()), define('OFFICE_DIR', dirname(__DIR__)) … — which PHPStan can't work out from the
 * code. Only their type matters (phpstan.neon lists them as dynamicConstantNames): the values here are never used.
 * Never loaded by the office itself.
 */
foreach (['OFFICE_DIR', 'RUN_DIR', 'DATA_DIR_USER', 'OFFICE_PUBLIC', 'OFFICE_DATA', 'OFFICE_DATA_USER', 'WATCH_CRON_LINE'] as $name) {
    defined($name) || define($name, '/phpstan');
}
