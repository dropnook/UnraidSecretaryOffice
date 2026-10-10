<?php
declare(strict_types=1);

/*
 * The key arrives by itself (2026-10-09; the rules: src/supporter.php's head). The agent is the office's
 * house messenger: it alone asks the support page (OFFICE_SUPPORT_URL, the tip Worker in the maintainer's private repository) for the
 * key a tip made — never the browser.
 *
 *   office.supporter_claim {auto?: true}
 *       → asks GET <support page>/api/claim?code=<code> for the codes due (src/supporter.php officeSupporterClaimPick():
 *         auto = the page's one ask on its own, a minute after the support page was opened; else every open code —
 *         the tip jar's «I've tipped — look for my key»). The Worker answers {ok: true, keys: [...]} once and forgets
 *         them (the code goes here too), 404 not_found (nothing yet: the code stays) or anything else (it stays). Each
 *         key is checked like a pasted one (signature, this server) before it is kept.
 *       → {ok, asked, found, refused, failed, supporter: CONFIG.supporter}
 *
 * curl through hostNet(): https only (http only when the plugin's .cfg says SUPPORT_URL="http://…" — tests), 15 s, no
 * redirects, at most 16 KB back; the address with the code goes in a 0600 config file in RAM (never on the command
 * line). Nothing of this server goes along but the code; the agent's log names how many were asked, never a code.
 */

const SUPPORTER_CLAIM_TIMEOUT = 15;        // seconds per ask
const SUPPORTER_CLAIM_BACK    = 16384;     // bytes of an answer at most

function supporterClaimFile(array $ctx = []): string
{
    return $ctx['file'] ?? OFFICE_PRIVATE . '/supporter.json';
}

/** office.supporter_claim — see the head */
function supporterClaim(array $r, array $ctx = []): array
{
    require_once dirname(__DIR__, 2) . '/src/supporter.php';
    $auto = ($r['auto'] ?? false) === true;
    $file = supporterClaimFile($ctx);
    $now = $ctx['now'] ?? time();
    $serverId = array_key_exists('server_id', $ctx) ? $ctx['server_id'] : officeServerId();
    $codes = [];
    $stored = officeSupporterStore($file, function (array $d) use ($auto, $now, &$codes): array {
        [$d, $codes] = officeSupporterClaimPick($d, $auto, $now);
        return $d;
    }, $now);
    if ($stored === null) {
        throw new Problem('supporter_storage');
    }
    $answers = [];
    foreach ($codes as $code) {
        $answers[$code] = supporterClaimAsk($code, $ctx);
    }
    $added = $refused = 0;
    if (array_filter($answers, 'is_array')) {
        $stored = officeSupporterStore($file, function (array $d) use ($answers, $serverId, $now, &$added, &$refused): array {
            [$d, $added, $refused] = officeSupporterClaimTake($d, $answers, $serverId, $now);
            return $d;
        }, $now);
        if ($stored === null) {
            throw new Problem('supporter_storage');
        }
    }
    $failed = count(array_filter($answers, fn ($a): bool => $a === 'failed'));
    if ($codes) {
        $line = sprintf('Office: supporter key — asked the support page for %d code%s%s: %d key%s found', count($codes),
            count($codes) === 1 ? '' : 's', $auto ? ' (on its own)' : '', $added, $added === 1 ? '' : 's');
        supporterClaimLog($line . ($refused ? ", $refused refused" : '') . ($failed ? ", $failed without an answer" : ''), $ctx);
    }
    return ['ok' => true, 'asked' => count($codes), 'found' => $added, 'refused' => $refused, 'failed' => $failed,
        'supporter' => officeSupporterInfo($stored, $serverId, $now)];
}

/**
 * One ask: ['keys' => [...]] (given — and forgotten there), 'none' (nothing under this code yet) or 'failed'
 * (no answer, or one that isn't the Worker's)
 */
function supporterClaimAsk(string $code, array $ctx = []): array|string
{
    $base = $ctx['url'] ?? officeSupportUrl();
    if ($base === '' || !preg_match(OFFICE_SUPPORTER_CODE_RE, $code)) {
        return 'failed';
    }
    $dir = $ctx['run_dir'] ?? RUN_DIR;
    $file = "$dir/claim." . bin2hex(random_bytes(6)) . '.curl';
    $old = umask(0177);
    $h = @fopen($file, 'x');
    umask($old);
    $config = 'url = "' . $base . '/api/claim?code=' . $code . "\"\n";
    if (!$h || @fwrite($h, $config) !== strlen($config) || !fclose($h)) {
        @unlink($file);
        return 'failed';
    }
    try {
        [$exit, $out] = hostNet(['curl', '-s', '-S', '-m', (string) SUPPORTER_CLAIM_TIMEOUT, '--proto', str_starts_with($base, 'http://') ? '=http' : '=https',
            '--max-redirs', '0', '--max-filesize', (string) SUPPORTER_CLAIM_BACK, '-H', 'Accept: application/json',
            '-A', 'UnraidSecretaryOffice/' . AGENT_VERSION, '-w', '\n%{http_code}', '-K', $file], SUPPORTER_CLAIM_TIMEOUT + 10);
    } finally {
        @unlink($file);
    }
    return supporterClaimAnswer($exit, $out);
}

/** The Worker's answer: 200 {ok: true, keys: [strings]} → the keys; 404 {error: not_found} → 'none'; else 'failed' */
function supporterClaimAnswer(int $exit, string $out): array|string
{
    $cut = strrpos(rtrim($out), "\n");
    $code = (int) substr(rtrim($out), $cut === false ? 0 : $cut + 1);
    if ($exit !== 0 || $code === 0) {
        return 'failed';
    }
    $body = json_decode($cut === false ? '' : substr($out, 0, $cut), true, 4);
    $body = is_array($body) ? $body : [];
    if ($code === 200 && ($body['ok'] ?? null) === true && is_array($body['keys'] ?? null) && array_is_list($body['keys'])) {
        return ['keys' => array_slice(array_values(array_filter($body['keys'], 'is_string')), 0, 10)];
    }
    if ($code === 404 && ($body['error'] ?? null) === 'not_found') {
        return 'none';
    }
    return 'failed';
}

function supporterClaimLog(string $text, array $ctx = []): void
{
    if (isset($ctx['log_lines'])) {
        $ctx['log_lines']($text);
        return;
    }
    logLine($text);
}
