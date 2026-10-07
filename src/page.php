<?php
declare(strict_types=1);

/*
 * The one page. core.js builds the office from the desks it finds; every
 * desk renders itself (public/desks/<id>/desk.js).
 *
 * The office is a page of Unraid's web UI: SecretaryOffice.page puts it into
 * Unraid's menu bar and calls officeInUnraid() — Unraid's header, menu and
 * footer around it, its theme's colours (office.css). The office's own
 * index.php only forwards there.
 *
 * Unraid accepts a POST only with its csrf_token (local_prepend.php), so the
 * page hands it to core.js, which sends it along as X-CSRF-Token.
 */

/** Where the browser finds the office's files from Unraid's page: the plugin's folder */
function officeWebBase(): string
{
    return '/plugins/' . OFFICE_PLUGIN . '/';
}

/** What core.js needs to start */
function officePageConfig(): array
{
    $info = agentInfo();
    $desks = officeDesks();
    $hired = officeHired();
    foreach ($desks as $id => &$desk) {
        $desk['hired'] = isset($hired[$id]);
        // its own drawing (desks/<id>/avatar.svg), the emoji only where there is none
        $desk['avatar'] = is_file(OFFICE_PUBLIC . "/desks/$id/avatar.svg") ? officeAsset("desks/$id/avatar.svg") : null;
    }
    unset($desk);
    $config = [
        'version'   => OFFICE_VERSION,
        'host'      => (string) ($info['host'] ?? 'Unraid'),
        'desks'     => array_values($desks),
        'languages' => officeLanguages(),
        'stamp'     => officeStringsStamp(),
        'tip_url'   => OFFICE_TIP_URL,
        'support_url' => OFFICE_SUPPORT_URL,
        'sponsor_url' => OFFICE_SPONSOR_URL,
        'supporter' => officeSupporterPage(),     // the supporter key: a thank-you, it unlocks nothing (supporter.php)
        'base'      => officeWebBase(),
        'reception_icon' => officeAsset('assets/reception.svg'),
        'agent'     => $info,       // the messenger, or why nobody answers (the array stopped, the night shift): said at once
    ];
    $var = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
    $config['csrf'] = (string) ($var['csrf_token'] ?? '');
    $config['array'] = (string) ($var['fsState'] ?? '');     // Started, Stopped, Starting …
    $config['menu_name'] = officeMenuName();
    $config['menu_default'] = OFFICE_MENU_DEFAULT;
    $config['menu_max'] = OFFICE_MENU_MAX;
    $config['menu_page'] = basename(OFFICE_MENU_PAGE, '.page');
    $config['menu_place'] = officeMenuPlace();
    // Unraid's language (de_DE …, '' = English): the office follows it unless the browser chose another
    $config['unraid_lang'] = strtolower(strtok((string) ($GLOBALS['locale'] ?? ''), '_-') ?: 'en');
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
 * The office itself: one element (#sso) that holds everything, dialogs and
 * menus included — office.css styles nothing outside it.
 */
function officeBody(array $config): void
{
    $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    ?>
<div class="sso in-unraid" id="sso">

<header class="topbar">
  <nav class="desk-tabs" id="sso-tabs" aria-label="Desks"></nav>
  <div class="topbar-right">
    <button class="agent-state" id="sso-state" type="button"><span class="agent-label" id="sso-state-label"></span><span class="dot" id="sso-dot"></span></button>
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

/** The office lives in Unraid's web UI: index.php (old links, bookmarks) forwards there */
function render_forward(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $to = officeMenuUrl(officeMenuPlace());
    ?>
<!doctype html>
<meta charset="utf-8">
<title>Secretary Office</title>
<script>location.replace(<?= json_encode($to) ?> + location.hash);</script>
<p><a href="<?= htmlspecialchars($to, ENT_QUOTES) ?>">Secretary Office</a></p>
<?php
}
