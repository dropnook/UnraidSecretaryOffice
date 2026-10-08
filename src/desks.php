<?php
declare(strict_types=1);

/*
 * Desks (secretaries) and languages are found, not configured: every folder
 * public/desks/<id>/ with a desk.json is a desk, every public/lang/<code>.json
 * a language. Adding a secretary or a language means adding files.
 */

/**
 * Every desk, in desk.json's order ("order", then the id) — the order of a fresh office; the user may set another one
 * at the reception (staff.php officeStaffOrder()).
 *
 * @return array<string, array{id:string, order:int, icon:string, refresh_after:int, parts:array<string, array{refresh_after:int, action:string}>, css:bool, always:bool, training:bool, with:?string}>
 */
function officeDesks(): array
{
    static $desks = null;
    if ($desks !== null) {
        return $desks;
    }
    $desks = [];
    foreach (glob(OFFICE_PUBLIC . '/desks/*/desk.json') ?: [] as $file) {
        $id = basename(dirname($file));
        $meta = officeReadJson($file);
        if (!$meta || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || !is_file(dirname($file) . '/desk.js')) {
            continue;
        }
        $desks[$id] = [
            'id'            => $id,
            'order'         => (int) ($meta['order'] ?? 100),
            'icon'          => (string) ($meta['icon'] ?? '•'),
            'refresh_after' => (int) ($meta['refresh_after'] ?? 300),
            'parts'         => officeDeskParts($meta['parts'] ?? null),
            'css'           => is_file(dirname($file) . '/desk.css'),
            'always'        => !empty($meta['always']),       // always in the office (the caretaker), never hired or fired
            'training'      => !empty($meta['training']),     // still learning: the caretaker shows him, nobody can hire him yet
            // the colleague he needs (desk.json "with": Mr. Restori works from Mr. Backupsy's packages): the Team Lead
            // offers to hire both at once; only another desk's id, else null
            'with'          => is_string($meta['with'] ?? null) && $meta['with'] !== $id ? $meta['with'] : null,
        ];
    }
    foreach ($desks as $id => $desk) {
        if ($desk['with'] !== null && !isset($desks[$desk['with']])) {
            $desks[$id]['with'] = null;
        }
    }
    uasort($desks, fn ($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    return $desks;
}

/**
 * Extra state parts (data/<desk>-<part>.json) the API keeps fresh like the desk's state: desk.json
 * `"parts": {"<part>": {"refresh_after": <seconds>, "action": "<action>"}}` — apiPart() asks the agent for
 * `<desk>.<action>` when the part is older than that (the server's clock, the short wait). Only well-formed
 * entries count; the rest are plain files the page reads as they are.
 *
 * @return array<string, array{refresh_after:int, action:string}>
 */
function officeDeskParts(mixed $meta): array
{
    $parts = [];
    foreach (is_array($meta) ? $meta : [] as $part => $rule) {
        if (is_string($part) && preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $part) && is_array($rule)
            && is_string($rule['action'] ?? null) && preg_match('/^[a-z][a-z0-9_]*$/D', $rule['action'])
            && is_int($rule['refresh_after'] ?? null) && $rule['refresh_after'] > 0) {
            $parts[$part] = ['refresh_after' => $rule['refresh_after'], 'action' => $rule['action']];
        }
    }
    return $parts;
}

/** @return list<array{code:string, name:string}> */
function officeLanguages(): array
{
    $languages = [];
    foreach (glob(OFFICE_PUBLIC . '/lang/*.json') ?: [] as $file) {
        $code = basename($file, '.json');
        $meta = officeReadJson($file)['_meta'] ?? [];
        if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $code)) {
            $languages[] = ['code' => $code, 'name' => (string) ($meta['name'] ?? $code)];
        }
    }
    usort($languages, fn ($a, $b) => strcmp($a['code'], $b['code']));
    return $languages;
}

/**
 * All strings for one language: office + every desk, English filled in
 * wherever the language has no translation yet.
 */
function officeStrings(string $code): array
{
    $layers = function (string $c): array {
        $strings = officeReadJson(OFFICE_PUBLIC . "/lang/$c.json") ?? [];
        foreach (officeDesks() as $id => $_) {
            foreach (officeReadJson(OFFICE_PUBLIC . "/desks/$id/lang/$c.json") ?? [] as $key => $value) {
                if ($key !== '_meta') {
                    $strings["$id.$key"] = $value;
                }
            }
        }
        return $strings;
    };
    $english = $layers('en');
    return $code === 'en' ? $english : array_replace($english, $layers($code));
}

/** Newest change of any language file — used to bust the browser cache */
function officeStringsStamp(): int
{
    $stamp = 0;
    foreach (array_merge(glob(OFFICE_PUBLIC . '/lang/*.json') ?: [], glob(OFFICE_PUBLIC . '/desks/*/lang/*.json') ?: []) as $file) {
        $stamp = max($stamp, (int) @filemtime($file));
    }
    return $stamp;
}

/**
 * The browser's language from its Accept-Language header: the first (by weight, then order) the office speaks — exact
 * (pt-br) or by its main part (de-CH → de) —, null when none. The page takes navigator.languages by the same rule
 * (core.js browserLanguage()); this is for what the server shows before any script runs (the Dashboard tile).
 *
 * @param list<string> $codes
 */
function officeBrowserLang(string $accept, array $codes): ?string
{
    $tags = [];
    foreach (array_slice(explode(',', $accept), 0, 32) as $i => $part) {
        $bits = array_map('trim', explode(';', $part));
        $tag = strtolower($bits[0]);
        $q = 1.0;
        foreach (array_slice($bits, 1) as $bit) {
            if (preg_match('/^q\s*=\s*([01](?:\.\d{0,3})?)$/D', $bit, $m)) {
                $q = (float) $m[1];
            }
        }
        if ($q > 0 && preg_match('/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/D', $tag)) {
            $tags[] = [$q, -$i, $tag];
        }
    }
    rsort($tags);
    $known = [];
    foreach ($codes as $code) {
        $known[strtolower($code)] = $code;
    }
    foreach ($tags as [, , $tag]) {
        $main = explode('-', $tag)[0];
        if (isset($known[$tag]) || isset($known[$main])) {
            return $known[$tag] ?? $known[$main];
        }
    }
    return null;
}

/** Where the language the office was last used in is kept: the notifications speak it (agent/lib/house.php officeNotifyLang()) */
function officeLangFile(): string
{
    return OFFICE_DATA . '/office/lang.json';
}

/** The language kept there, null when none (or not one the office speaks) */
function officeLangRemembered(?string $file = null): ?string
{
    $file ??= officeLangFile();
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || (int) @filesize($file) > 4096) {
        return null;
    }
    $lang = (officeReadJson($file) ?? [])['lang'] ?? null;
    return is_string($lang) && in_array($lang, array_column(officeLanguages(), 'code'), true) ? $lang : null;
}

/**
 * office.lang {lang}: the page shows the office in this language (chosen in ⋯ → Language, or the browser's) — kept
 * in data/office/lang.json for the notifications, which the server makes without a browser. Only a language the
 * office speaks; written (new file + rename) only when it changes.
 */
function officeLangRemember(array $data, ?string $file = null): array
{
    $lang = $data['lang'] ?? null;
    if (!is_string($lang) || !in_array($lang, array_column(officeLanguages(), 'code'), true)) {
        throw new OfficeProblem('bad_request');
    }
    $file ??= officeLangFile();
    $dir = dirname($file);
    clearstatcache(true, $dir);
    if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
        throw new OfficeProblem('office_storage', 503);
    }
    if (officeLangRemembered($file) !== $lang
        && !officeWriteAtomic($file, (string) json_encode(['lang' => $lang, 'time' => time()]), 0644)) {
        throw new OfficeProblem('office_storage', 503);
    }
    return ['ok' => true, 'lang' => $lang];
}
