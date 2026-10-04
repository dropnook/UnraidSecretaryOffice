<?php
declare(strict_types=1);

/*
 * Keeping the office itself up to date — the caretaker's job.
 *
 * Once a day he asks GitHub for the latest release and compares it with the
 * running version. As a plugin, Unraid's plugin manager updates it (he only
 * points there). Installed with git clone (the stack), he can also
 * update: a fast-forward pull of the branch, refused when the code was
 * changed locally. The office reads its code live and the agent restarts
 * itself, so an update is active at once; only a changed compose.yaml needs
 * the stack restarted, and he says so. Files keep the owner the folder had.
 */

const OFFICE_REPO        = 'vipermark2/UnraidSecretaryOffice';
const OFFICE_RELEASE_API = 'https://api.github.com/repos/' . OFFICE_REPO . '/releases/latest';
const OFFICE_CHECK_EVERY = 86400;

function officeUpdateFile(): string
{
    return DATA_DIR . '/office-update.json';
}

/** git with the office's repository; root works on a folder someone else owns */
function officeGit(array $args, int $timeout = 60, bool $net = false): array
{
    $cmd = array_merge(['git', '-c', 'safe.directory=*', '-C', OFFICE_DIR], $args);
    return $net ? hostNet($cmd, $timeout) : run($cmd, $timeout);
}

/** What the page shows: running version, latest release, how it is installed */
function officeUpdateInfo(bool $force = false): array
{
    $cache = readJson(officeUpdateFile()) ?? [];
    if ($force || time() - (int) ($cache['checked'] ?? 0) > OFFICE_CHECK_EVERY) {
        $cache = officeUpdateFetch();
        writeAtomic(officeUpdateFile(), jsonEncode($cache));
    }
    $git = is_dir(OFFICE_DIR . '/.git');
    $changed = false;
    $branch = null;
    if ($git) {
        [$e1, $st] = officeGit(['status', '--porcelain', '--untracked-files=no'], 20);
        $changed = $e1 !== 0 || trim($st) !== '';
        [, $br] = officeGit(['rev-parse', '--abbrev-ref', 'HEAD'], 10);
        $branch = trim($br) ?: null;
    }
    $latest = $cache['latest'] ?? null;
    return $cache + [
        'version'   => AGENT_VERSION,
        'newer'     => $latest !== null && version_compare(ltrim($latest, 'v'), AGENT_VERSION, '>'),
        'plugin'    => AS_PLUGIN,
        'git'       => $git,
        'changed'   => $changed,
        'branch'    => $branch,
    ];
}

function officeUpdateFetch(): array
{
    [$exit, $out, $err] = hostNet(['curl', '-s', '-S', '-m', '15', '-H', 'Accept: application/vnd.github+json',
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
    return ['checked' => time(), 'latest' => ltrim((string) $body['tag_name'], 'v'), 'name' => (string) ($body['name'] ?? ''),
            'url' => (string) ($body['html_url'] ?? ''), 'published' => strtotime((string) ($body['published_at'] ?? '')) ?: null];
}

/** "Update": fast-forward to what the repository has, never over local changes */
function officeUpdate(): array
{
    $info = officeUpdateInfo();
    if (!$info['git']) {
        throw new Problem('office_not_git');
    }
    if ($info['changed']) {
        throw new Problem('office_local_changes');
    }
    $branch = $info['branch'] ?: 'main';
    [, $before] = officeGit(['rev-parse', 'HEAD'], 10);
    [$exit, , $err] = officeGit(['fetch', '--quiet', '--tags', 'origin', $branch], 120, true);
    if ($exit !== 0) {
        throw new Problem('office_git', ['detail' => trim($err)]);
    }
    [$exit, , $err] = officeGit(['merge', '--ff-only', '--quiet', 'FETCH_HEAD'], 60);
    if ($exit !== 0) {
        throw new Problem('office_git', ['detail' => trim($err)]);
    }
    [, $after] = officeGit(['rev-parse', 'HEAD'], 10);
    $before = trim($before);
    $after = trim($after);
    $files = [];
    if ($before !== $after) {
        [, $list] = officeGit(['diff', '--name-only', $before, $after], 20);
        $files = array_values(array_filter(explode("\n", trim($list))));
        // git ran as root: give changed files the folder's owner back (edits over SMB keep working)
        $owner = @stat(OFFICE_DIR);
        foreach ($files as $f) {
            $path = OFFICE_DIR . '/' . $f;
            if ($owner && file_exists($path)) {
                @chown($path, $owner['uid']);
                @chgrp($path, $owner['gid']);
            }
        }
    }
    logLine("Caretaker: office updated " . substr($before, 0, 7) . ' → ' . substr($after, 0, 7) . ' (' . count($files) . ' files)');
    @unlink(officeUpdateFile());
    return ['ok' => true, 'updated' => $before !== $after, 'files' => count($files),
            'compose' => in_array('compose.yaml', $files, true), 'state' => caretakerScan()];
}
