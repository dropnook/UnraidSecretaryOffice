<?php
declare(strict_types=1);

/*
 * Unraid's own words — shared by the web side and the agent.
 *
 * The office speaks the browser's language, Unraid may run in another one. A
 * text that tells the user where to click in Unraid marks each of Unraid's
 * labels as ⟦English label⟧ («Open ⟦Settings⟧ → ⟦User Utilities⟧»); it is
 * shown as Unraid shows it in the language Unraid runs in — whatever the
 * office speaks. The words come from lang/unraid/<code>.json (taken from
 * Unraid's language packs, github.com/unraid/lang-<locale>); English, a
 * language without a file and a label without an entry stay as written.
 * ⟦ ⟧ can't clash with {placeholders} or plurals; the tests check that every
 * language has the same labels per text and every dictionary all of them.
 */

const OFFICE_UNRAID_CFG = '/boot/config/plugins/dynamix/dynamix.cfg';

/** 'de' for Unraid's locale de_DE, 'en' for none (English) or anything odd */
function officeUnraidLangOf(string $locale): string
{
    $code = strtolower((string) strtok($locale, '_-'));
    return preg_match('/^[a-z]{2,3}$/D', $code) ? $code : 'en';
}

/**
 * The language Unraid runs in (Settings → Display Settings: [display] locale in dynamix.cfg, on the flash — a small
 * file, read each time); inside Unraid's page the locale it already read.
 */
function officeUnraidLang(?string $cfg = null): string
{
    if ($cfg === null && is_string($GLOBALS['locale'] ?? null)) {
        return officeUnraidLangOf($GLOBALS['locale']);
    }
    $cfg ??= OFFICE_UNRAID_CFG;
    // line by line, like the agent's readCfg(): one odd line elsewhere in the file mustn't cost the language
    $section = '';
    foreach (is_file($cfg) ? (@file($cfg, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*\[\s*"?([^"\]]*)"?\s*\]/', $line, $m)) {
            $section = $m[1];
        } elseif ($section === 'display' && preg_match('/^\s*locale\s*=\s*"?([^"]*)"?\s*$/', $line, $m)) {
            return officeUnraidLangOf($m[1]);
        }
    }
    return 'en';
}

/**
 * Unraid's labels in a language: English label => Unraid's word, from <web>/lang/unraid/<code>.json — [] for English
 * and for a language without a dictionary. Only plain label => text pairs count.
 *
 * @return array<string, string>
 */
function officeUnraidWords(string $code, string $web): array
{
    static $cache = [];
    if ($code === 'en' || !preg_match('/^[a-z]{2,3}$/D', $code)) {
        return [];
    }
    $file = "$web/lang/unraid/$code.json";
    clearstatcache(true, $file);
    $stamp = (string) @filemtime($file) . ':' . (string) @filesize($file);
    if (($cache[$file][0] ?? null) !== $stamp) {
        $data = json_decode((string) @file_get_contents($file), true);
        $words = [];
        foreach (is_array($data) ? $data : [] as $label => $word) {
            if (is_string($label) && $label !== '_meta' && is_string($word) && $word !== '') {
                $words[$label] = $word;
            }
        }
        $cache[$file] = [$stamp, $words];
    }
    return $cache[$file][1];
}

/** «⟦Settings⟧ → ⟦User Utilities⟧» in Unraid's words; a label without an entry stays as written */
function officeUnraidResolve(string $text, array $words): string
{
    if (!str_contains($text, '⟦')) {
        return $text;
    }
    return (string) preg_replace_callback('/⟦([^⟦⟧]+)⟧/u', fn ($m) => $words[$m[1]] ?? $m[1], $text);
}
