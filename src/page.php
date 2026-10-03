<?php
declare(strict_types=1);

/*
 * The one page. core.js builds the office from the desks it finds; every
 * desk renders itself (public/desks/<id>/desk.js). Look and feel taken from
 * levelnext Airdrop.
 */

function render_page(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $info = agentInfo();
    $host = (string) ($info['host'] ?? 'Unraid');
    $desks = officeDesks();
    $config = [
        'version'   => OFFICE_VERSION,
        'host'      => $host,
        'desks'     => array_values($desks),
        'languages' => officeLanguages(),
        'stamp'     => officeStringsStamp(),
    ];
    $v = static fn (string $file): string => (string) @filemtime(OFFICE_PUBLIC . '/' . $file);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title>Secretary Office · <?= $h($host) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>🗂️</text></svg>">
<link rel="stylesheet" href="assets/office.css?v=<?= $v('assets/office.css') ?>">
<?php foreach ($desks as $id => $d): if ($d['css']): ?>
<link rel="stylesheet" href="desks/<?= $id ?>/desk.css?v=<?= $v("desks/$id/desk.css") ?>">
<?php endif; endforeach; ?>
</head>
<body>

<header class="topbar">
  <a class="brand" href="#/">
    <span class="dot" id="agent-dot"></span>
    <span class="brand-name" id="brand-name">Secretary Office</span>
    <span class="hint" id="brand-host"><?= $h($host) ?></span>
  </a>
  <nav class="tabs" id="tabs" aria-label="Desks"></nav>
  <div class="topbar-right">
    <select class="lang" id="lang" aria-label="Language"></select>
    <button class="more" id="btn-more" type="button" aria-label="More">⋯</button>
  </div>
</header>

<p class="notice" id="notice" hidden></p>

<main class="page" id="desk"></main>

<footer class="footer" id="footer"></footer>

<div class="menu" id="menu" hidden></div>

<div class="dialog-backdrop" id="dialog-backdrop" hidden>
  <div class="dialog" id="dialog" role="dialog" aria-modal="true" aria-labelledby="dialog-title">
    <h3 id="dialog-title"></h3>
    <div class="dialog-body" id="dialog-body"></div>
    <div class="dialog-foot" id="dialog-foot"></div>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script id="office-config" type="application/json"><?= $json ?></script>
<script src="assets/core.js?v=<?= $v('assets/core.js') ?>"></script>
<?php foreach ($desks as $id => $d): ?>
<script src="desks/<?= $id ?>/desk.js?v=<?= $v("desks/$id/desk.js") ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
