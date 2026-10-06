<?php
declare(strict_types=1);

// the office is a page in Unraid's web UI (SecretaryOffice.page): old links and bookmarks lead there
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/page.php';

render_forward();
