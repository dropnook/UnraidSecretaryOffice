<?php
declare(strict_types=1);

/*
 * Lang keys: asked for but missing, and present but (seemingly) never used — a reviewer's look, not a test.
 *
 *   php tools/lang-keys.php [--all]
 *
 * Missing (exact): T('key') in every JS file of a desk (desk.js and its helpers — partner.js, drill.js), t('key') /
 * Office.t('key') anywhere, nOf('what') → count.<what>; checked against en.json of the office and the desk.
 *
 * Unused (a heuristic — keys are often built: T(`check.${id}`), 'errors.' + key, PHP codes the page translates): a key
 * counts as used when its words appear quoted in the code that can ask for it (the desk's JS and PHP, the libraries,
 * core.js, src/, the engine for Mr. Backupsy), as `<desk>.<key>`, or in places.json; «built» when a prefix of it is
 * quoted and followed by + / ${ / . (JS template, concatenation, PHP); «code» when its last part appears quoted (a
 * code from PHP or the engine). Only keys with none of that are listed (--all: the built and code ones too, counted).
 * Always-used families: name, role, visit, greet.<n>, fire_note, count.* via nOf, *_how beside a check.
 */

$root = dirname(__DIR__);
$all = in_array('--all', $argv, true);

function lk_json(string $file): array
{
    $j = json_decode((string) @file_get_contents($file), true);
    return is_array($j) ? $j : [];
}

function lk_read(array $files): string
{
    $s = '';
    foreach ($files as $f) {
        if (is_file($f)) {
            $s .= "\n" . file_get_contents($f);
        }
    }
    return $s;
}

function lk_glob(string $pattern): array
{
    return glob($pattern, GLOB_BRACE) ?: [];
}

$officeKeys = array_keys(array_diff_key(lk_json("$root/public/lang/en.json"), ['_meta' => 1]));
$desks = [];
foreach (lk_glob("$root/public/desks/*/lang/en.json") as $f) {
    $desks[basename(dirname($f, 2))] = array_keys(lk_json($f));
}

$core = lk_read(["$root/public/assets/core.js", "$root/public/assets/theme-switch.js", "$root/public/assets/size-switch.js"]);
$src = lk_read(array_merge(lk_glob("$root/src/*.php"), lk_glob("$root/public/*.php"), lk_glob("$root/plugin/*.page")));
$lib = lk_read(array_merge(lk_glob("$root/agent/lib/*.php"), ["$root/agent/agent.php", "$root/agent/partner-door.php"]));
$places = lk_read(array_merge(["$root/public/assets/places.json"], lk_glob("$root/public/desks/*/places.json")));
$allJs = lk_read(lk_glob("$root/public/desks/*/*.js"));
$allPhp = lk_read(lk_glob("$root/agent/desks/*.php"));
$engine = lk_read(array_merge(lk_glob("$root/backup/*.sh"), lk_glob("$root/backup/lib/*.sh")));

// ------------------------------------------------------------------ missing
$missing = [];
$have = fn (string $desk, string $k): bool => $desk === '' ? in_array($k, $officeKeys, true) : in_array($k, $desks[$desk] ?? [], true);
$haveFull = function (string $k) use ($have, $desks): bool {
    if ($have('', $k)) {
        return true;
    }
    $p = strpos($k, '.');
    return $p !== false && isset($desks[substr($k, 0, $p)]) && $have(substr($k, 0, $p), substr($k, $p + 1));
};
foreach (lk_glob("$root/public/desks/*/*.js") as $file) {
    $desk = basename(dirname($file));
    $js = (string) file_get_contents($file);
    $js = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
    preg_match_all("/(?<![.\\w])T\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $js, $m);
    foreach (array_unique($m[1]) as $k) {
        if (!$have($desk, $k)) {
            $missing[] = "$desk/" . basename($file) . ": T('$k')";
        }
    }
    preg_match_all("/\\b(?:Office\\.)?t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $js, $m);
    foreach (array_unique($m[1]) as $k) {
        if (!$haveFull($k)) {
            $missing[] = "$desk/" . basename($file) . ": t('$k')";
        }
    }
    preg_match_all("/\\bnOf\\(\\s*'([a-z0-9_]+)'/", $js, $m);
    foreach (array_unique($m[1]) as $k) {
        if (!$have($desk, "count.$k")) {
            $missing[] = "$desk/" . basename($file) . ": nOf('$k') → count.$k";
        }
    }
}
$coreNc = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $core);
preg_match_all("/(?<![.\\w])t\\(\\s*'([a-z0-9_.]+)'\\s*[,)]/", $coreNc, $m);
foreach (array_unique($m[1]) as $k) {
    if (!$haveFull($k)) {
        $missing[] = "core.js: t('$k')";
    }
}
// PHP: officeNotifyText('<desk>', '<key>') / officeDashT('<key>')
foreach (array_merge(lk_glob("$root/agent/desks/*.php"), lk_glob("$root/agent/lib/*.php"), lk_glob("$root/src/*.php")) as $file) {
    $php = (string) file_get_contents($file);
    preg_match_all("/officeNotifyText\\(\\s*'([a-z0-9_]+)'\\s*,\\s*'([a-z0-9_.]+)'/", $php, $m, PREG_SET_ORDER);
    foreach ($m as [, $desk, $k]) {
        if (!$have($desk === 'office' ? '' : $desk, $k)) {
            $missing[] = basename($file) . ": officeNotifyText('$desk', '$k')";
        }
    }
    preg_match_all("/officeDashT\\(\\s*'([a-z0-9_.]+)'/", $php, $m);
    foreach (array_unique($m[1]) as $k) {
        if (!$haveFull($k)) {
            $missing[] = basename($file) . ": officeDashT('$k')";
        }
    }
}

// ------------------------------------------------------------------ unused
function lk_used(string $key, string $code, string $alias = ''): ?string
{
    $q = preg_quote($key, '/');
    if (preg_match('/[\'"`]' . $q . '[\'"`]/', $code) || ($alias !== '' && preg_match('/[\'"`]' . preg_quote("$alias.$key", '/') . '[\'"`]/', $code))) {
        return 'literal';
    }
    // a prefix built on: 'a.b.' + x, `a.b.${x}`, "a.b.{$x}", 'a.b.' . $x
    $cuts = [];
    for ($i = strlen($key) - 1; $i > 0; $i--) {
        if (in_array($key[$i - 1], ['.', '_', '-'], true)) {
            $cuts[] = substr($key, 0, $i);
        }
    }
    foreach ($cuts as $p) {
        $pq = preg_quote($p, '/');
        if (preg_match('/[\'"`]' . $pq . '[\'"]\s*[+.]|`' . $pq . '\$\{|"' . $pq . '\{?\$/', $code)
            || ($alias !== '' && preg_match('/[\'"`]' . preg_quote("$alias.$p", '/') . "['\"]\\s*[+.]|`" . preg_quote("$alias.$p", '/') . '\$\{|`\$\{[^}]+\}\.' . $pq . '\$\{/', $code))) {
            return 'built';
        }
    }
    $tail = substr($key, (int) strrpos($key, '.') + (str_contains($key, '.') ? 1 : 0));
    $tail = preg_replace('/_how$/', '', $tail);
    if ($tail !== '' && preg_match('/[\'"`]' . preg_quote($tail, '/') . '[\'"`]/', $code)) {
        return 'code';
    }
    return null;
}

$always = '/^(?:_meta|name|role|visit|fire_note|greet\.\d+|count\..+)$/';
$report = ['literal' => 0, 'built' => 0, 'code' => 0];
$unused = [];
$check = function (string $set, array $keys, string $code, string $alias) use (&$unused, &$report, $always, $places, $all): void {
    foreach ($keys as $k) {
        if (preg_match($always, $k)) {
            continue;
        }
        $how = lk_used($k, $code, $alias);
        if ($how === null && preg_match('/"' . preg_quote($k, '/') . '"/', $places)) {
            $how = 'literal';
        }
        if ($how === null) {
            $unused[] = "$set: $k";
        } else {
            $report[$how]++;
            if ($all && $how !== 'literal') {
                $unused[] = "$set: $k ($how)";
            }
        }
    }
};
$check('office', $officeKeys, $core . $src . $lib . $allJs . $allPhp, '');
foreach ($desks as $desk => $keys) {
    $code = lk_read(array_merge(lk_glob("$root/public/desks/$desk/*.js"), lk_glob("$root/agent/desks/$desk*.php"))) . $lib . $core . $src
        . ($desk === 'backup' || $desk === 'restore' ? $engine : '') . $allJs . $allPhp;
    $check($desk, $keys, $code, $desk);
}

echo "Lang keys asked for but missing in en.json: " . count($missing) . "\n";
foreach ($missing as $m) {
    echo "  $m\n";
}
echo "\nLang keys with no trace in the code (heuristic): " . count(array_filter($unused, fn ($u) => !str_ends_with($u, ')'))) . "\n";
foreach ($unused as $u) {
    echo "  $u\n";
}
printf("\n(used: %d literal, %d built from a prefix, %d by a code's name)\n", $report['literal'], $report['built'], $report['code']);
exit($missing ? 1 : 0);
