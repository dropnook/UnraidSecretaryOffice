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
 * @return array<string, array{id:string, order:int, icon:string, refresh_after:int, parts:array<string, array{refresh_after:int, action:string}>, css:bool, always:bool, training:bool}>
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
        ];
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
