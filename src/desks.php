<?php
declare(strict_types=1);

/*
 * Desks (secretaries) and languages are found, not configured: every folder
 * public/desks/<id>/ with a desk.json is a desk, every public/lang/<code>.json
 * a language. Adding a secretary or a language means adding files.
 */

/** @return array<string, array{id:string, order:int, icon:string, refresh_after:int, css:bool}> */
function officeDesks(): array
{
    static $desks = null;
    if ($desks !== null) {
        return $desks;
    }
    $desks = [];
    foreach (glob(OFFICE_PUBLIC . '/desks/*/desk.json') ?: [] as $file) {
        $id = basename(dirname($file));
        $meta = readJsonFile($file);
        if (!$meta || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $id) || !is_file(dirname($file) . '/desk.js')) {
            continue;
        }
        $desks[$id] = [
            'id'            => $id,
            'order'         => (int) ($meta['order'] ?? 100),
            'icon'          => (string) ($meta['icon'] ?? '•'),
            'refresh_after' => (int) ($meta['refresh_after'] ?? 300),
            'css'           => is_file(dirname($file) . '/desk.css'),
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
        $meta = readJsonFile($file)['_meta'] ?? [];
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
        $strings = readJsonFile(OFFICE_PUBLIC . "/lang/$c.json") ?? [];
        foreach (officeDesks() as $id => $_) {
            foreach (readJsonFile(OFFICE_PUBLIC . "/desks/$id/lang/$c.json") ?? [] as $key => $value) {
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
