<?php
declare(strict_types=1);

/*
 * The one page. core.js builds the office from the desks it finds; every
 * desk renders itself (public/desks/<id>/desk.js).
 *
 * As a plugin the office is a page of Unraid's web UI: SecretaryOffice.page
 * puts it into Unraid's menu bar and calls officeInUnraid() — Unraid's
 * header, menu and footer around it, its theme's colours (office.css,
 * .in-unraid). The office's own index.php only forwards there.
 * In the Compose stack there is no Unraid around it: render_page() makes a
 * page of its own, with the office's own colours.
 *
 * Unraid accepts a POST only with its csrf_token (local_prepend.php), so the
 * page hands it to core.js, which sends it along as X-CSRF-Token.
 */

/** Where the browser finds the office's files: next to index.php, or the plugin's folder from Unraid's page */
function officeWebBase(): string
{
    return OFFICE_IN_UNRAID ? '/plugins/' . OFFICE_PLUGIN . '/' : '';
}

/** What core.js needs to start */
function officePageConfig(): array
{
    $info = agentInfo();
    $desks = officeDesks();
    $hired = officeHired();
    foreach ($desks as $id => &$desk) {
        $desk['hired'] = isset($hired[$id]);
    }
    unset($desk);
    $config = [
        'version'   => OFFICE_VERSION,
        'host'      => (string) ($info['host'] ?? 'Unraid'),
        'desks'     => array_values($desks),
        'languages' => officeLanguages(),
        'stamp'     => officeStringsStamp(),
        'tip_url'   => OFFICE_TIP_URL,
        'plugin'    => OFFICE_AS_PLUGIN,
        'base'      => officeWebBase(),
        'in_unraid' => OFFICE_IN_UNRAID,
    ];
    if (OFFICE_AS_PLUGIN) {
        $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
        $config['csrf'] = (string) ($var['csrf_token'] ?? '');
        $config['array'] = (string) ($var['fsState'] ?? '');     // Started, Stopped, Starting …
        $config['menu_name'] = officeMenuName();
        $config['menu_default'] = OFFICE_MENU_DEFAULT;
        $config['menu_max'] = OFFICE_MENU_MAX;
        $config['menu_page'] = basename(OFFICE_MENU_PAGE, '.page');
    }
    if (OFFICE_IN_UNRAID) {
        // Unraid's language (de_DE …, '' = English): the office follows it unless the browser chose another
        $config['unraid_lang'] = strtolower(strtok((string) ($GLOBALS['locale'] ?? ''), '_-') ?: 'en');
    }
    return $config;
}

/** A file's URL with its modification time, so browsers fetch it again after a change */
function officeAsset(string $file): string
{
    return officeWebBase() . $file . '?v=' . (string) @filemtime(OFFICE_PUBLIC . '/' . $file);
}

/** Stylesheets: the office's and every desk's own */
function officeStyles(): void
{
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    echo '<link rel="stylesheet" href="' . $h(officeAsset('assets/office.css')) . '">' . "\n";
    foreach (officeDesks() as $id => $d) {
        if ($d['css']) {
            echo '<link rel="stylesheet" href="' . $h(officeAsset("desks/$id/desk.css")) . '">' . "\n";
        }
    }
}

/**
 * The office itself: one element (.sso) that holds everything, dialogs and
 * menus included — office.css styles nothing outside it.
 */
function officeBody(array $config): void
{
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $inUnraid = !empty($config['in_unraid']);
    ?>
<div class="sso<?= $inUnraid ? ' in-unraid' : '' ?>" id="sso">

<header class="topbar">
<?php if (!$inUnraid): ?>
  <a class="brand" href="#/">
    <span class="dot" id="sso-dot"></span>
    <span class="brand-name" id="sso-brand">Secretary Office</span>
    <span class="hint"><?= $h($config['host']) ?></span>
  </a>
<?php endif; ?>
  <nav class="desk-tabs" id="sso-tabs" aria-label="Desks"></nav>
  <div class="topbar-right">
<?php if ($inUnraid): ?>
    <span class="dot" id="sso-dot"></span>
<?php endif; ?>
    <button class="more" id="sso-lock" type="button" aria-label="PIN" hidden></button>
    <button class="more" id="sso-more" type="button" aria-label="More">⋯</button>
  </div>
</header>

<p class="notice" id="sso-notice" hidden></p>

<main class="page" id="sso-desk"></main>

<footer class="footer" id="sso-footer"></footer>

<div class="menu" id="sso-menu" hidden></div>

<div class="dialog-backdrop" id="sso-dialog-backdrop" hidden>
  <div class="dialog" id="sso-dialog" role="dialog" aria-modal="true" aria-labelledby="sso-dialog-title">
    <h3 id="sso-dialog-title"></h3>
    <div class="dialog-body" id="sso-dialog-body"></div>
    <div class="dialog-foot" id="sso-dialog-foot"></div>
  </div>
</div>

<div class="toasts" id="sso-toasts" aria-live="polite"></div>

</div>
<script id="sso-config" type="application/json"><?= $json ?></script>
<script src="<?= $h(officeAsset('assets/core.js')) ?>"></script>
<?php foreach (officeDesks() as $id => $d): ?>
<script src="<?= $h(officeAsset("desks/$id/desk.js")) ?>"></script>
<?php endforeach; ?>
<?php
}

/** Inside Unraid's page (SecretaryOffice.page): only the office, Unraid makes the rest */
function officeInUnraid(): void
{
    officeStyles();
    officeBody(officePageConfig());
}

/** A page of its own (the Compose stack) */
function render_page(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $config = officePageConfig();
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title>Secretary Office · <?= $h($config['host']) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= $h(officeAsset('assets/icon.svg')) ?>">
<?php officeStyles(); ?>
</head>
<body class="sso-page">
<?php officeBody($config); ?>
</body>
</html>
<?php
}

/** As a plugin the office lives in Unraid's menu bar: index.php (old links, bookmarks) forwards there */
function render_forward(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $to = '/' . basename(OFFICE_MENU_PAGE, '.page');
    ?>
<!doctype html>
<meta charset="utf-8">
<title>Secretary Office</title>
<script>location.replace(<?= json_encode($to) ?> + location.hash);</script>
<p><a href="<?= htmlspecialchars($to, ENT_QUOTES) ?>">Secretary Office</a></p>
<?php
}
