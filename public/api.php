<?php
declare(strict_types=1);

$base = is_dir(__DIR__ . '/src') ? __DIR__ : dirname(__DIR__);   // plugin: src/ right here
require $base . '/src/bootstrap.php';
require $base . '/src/api.php';

api_main();
