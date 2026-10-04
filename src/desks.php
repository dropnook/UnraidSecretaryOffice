<?php
declare(strict_types=1);

/*
 * Desks (secretaries) and languages are found, not configured: every folder
 * public/desks/<id>/ with a desk.json is a desk, every public/lang/<code>.json
 * a language. Adding a secretary or a language means adding files.
 */

/** @return array<string, array{id:string, order:int, reception_order:int, icon:string, refresh_after:int, open_actions:list<string>, css:bool, always:bool}> */
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
            'reception_order' => (int) ($meta['reception_order'] ?? $meta['order'] ?? 100),   // place at the reception, if different
            'icon'          => (string) ($meta['icon'] ?? '•'),
            'refresh_after' => (int) ($meta['refresh_after'] ?? 300),
            'open_actions'  => array_values(array_filter((array) ($meta['open_actions'] ?? []), 'is_string')),
            'css'           => is_file(dirname($file) . '/desk.css'),
            'always'        => !empty($meta['always']),       // always in the office (the caretaker), never hired or fired
        ];
    }
    uasort($desks, fn ($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
    return $desks;
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
