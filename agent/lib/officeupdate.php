<?php
declare(strict_types=1);

/*
 * Is the office up to date? — the caretaker's question.
 *
 * Once a day he asks GitHub for the latest release and compares it with the
 * running version. Unraid's plugin manager does the update; he only points
 * there.
 */

const OFFICE_REPO        = 'dropnook/UnraidSecretaryOffice';
const OFFICE_RELEASE_API = 'https://api.github.com/repos/' . OFFICE_REPO . '/releases/latest';
const OFFICE_CHECK_EVERY = 86400;

function officeUpdateFile(): string
{
    return DATA_DIR . '/office-update.json';
}

/** The cached answer as the page may use it: a version number, and a link only to the repository's page on GitHub */
function officeUpdateClean(array $cache): array
{
    if (isset($cache['latest']) && !(is_string($cache['latest']) && preg_match('/^\d{1,4}(\.\d{1,4}){1,3}\z/', $cache['latest']))) {
        unset($cache['latest']);
    }
    if (isset($cache['url']) && !(is_string($cache['url']) && str_starts_with($cache['url'], 'https://github.com/' . OFFICE_REPO . '/'))) {
        $cache['url'] = '';
    }
    return $cache;
}

/** What the page shows: running version, latest release */
function officeUpdateInfo(bool $force = false): array
{
    $cache = readJson(officeUpdateFile()) ?? [];
    if ($force || time() - (int) ($cache['checked'] ?? 0) > OFFICE_CHECK_EVERY) {
        $cache = officeUpdateFetch();
        writeAtomic(officeUpdateFile(), jsonEncode($cache));
    }
    $cache = officeUpdateClean($cache);         // also what lies in the file (others may have changed it)
    $latest = $cache['latest'] ?? null;
    return $cache + [
        'version'   => AGENT_VERSION,
        'newer'     => $latest !== null && version_compare(ltrim($latest, 'v'), AGENT_VERSION, '>'),
    ];
}

function officeUpdateFetch(): array
{
    [$exit, $out, $err] = hostNet(['curl', '-s', '-S', '-m', '15', '--proto', '=https', '--max-filesize', '1048576', '-H', 'Accept: application/vnd.github+json',
                                   '-w', '\n%{http_code}', OFFICE_RELEASE_API], 20);
    $code = (int) substr((string) strrchr(rtrim($out), "\n"), 1);
    $body = json_decode(substr($out, 0, (int) strrpos(rtrim($out), "\n")), true);
    if ($exit !== 0) {
        return ['checked' => time(), 'error' => 'offline', 'detail' => trim($err)];
    }
    if ($code === 404) {
        return ['checked' => time(), 'error' => 'not_public'];       // private repository, or no release yet
    }
    if ($code !== 200 || !is_array($body) || empty($body['tag_name'])) {
        return ['checked' => time(), 'error' => 'github', 'detail' => "HTTP $code"];
    }
    return officeUpdateClean(['checked' => time(), 'latest' => ltrim((string) $body['tag_name'], 'v'), 'name' => (string) ($body['name'] ?? ''),
            'url' => (string) ($body['html_url'] ?? ''), 'published' => strtotime((string) ($body['published_at'] ?? '')) ?: null]);
}
