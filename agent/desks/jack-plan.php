<?php
declare(strict_types=1);

/*
 * Jack Streamer: MediaCache's PLAN in PHP (2026-11-07). A port of the planning part of MediaCache by helmi1987 (GNU GPL v3 or
 * later; https://github.com/helmi1987/mediacache-for-unraid), from the office's copy mediacache/mediacache_lib.py and
 * mediacache_run.py, version 7.4.0 (2026-10-15) with its `USO` changes as of the office's 1.89.0. This file is GPL-3.0-or-later
 * too; mediacache/README.md says so. A later fix upstream or in that copy is ported here by hand.
 *
 * Why: Python 3 for Unraid loses its maintainer's support on 2026-11-30. For now this runs only in the shadow of Python
 * (jack-shadow.php, behind a flag file): THIS FILE NEVER COPIES, MOVES OR DELETES A FILE, WRITES NO LIST AND NO SETTING. What is
 * here reads the settings and the list, asks the media servers, looks with stat, and returns a plan: an array in the same
 * stable shape as mediacache_shadow.py's (jackPlanDry()).
 *
 * The port keeps Python's shape on purpose, function by function, so a later fix can be carried across and a difference
 * can be looked up. Where Python's data types decide what happens (an odd answer from a server), the helpers jp*() do what Python
 * does: JSON objects stay stdClass, lists stay arrays ({} is no empty list); a `.get` on something that is no dict ends the plan as
 * Python's traceback would (JackPlanCrash, outcome «crash»); only what Python raises as RuntimeError counts as a server's error
 * (JackPlanApiError, planner errors, «incomplete»).
 *
 * Paths are bytes here and str in Python. Order: UTF-8's byte order is the code points' order; a byte that is no UTF-8 is, as in
 * Python's surrogateescape, U+DC80+byte (jpCmp()). Such a byte is written as \xNN in the plan on both sides (jackPlanClean()). Names:
 * person_key() needs NFKD, casefold and lower(); PHP here has no intl, and mbstring's Unicode is newer than Python's: jack-plan-names.txt
 * holds the tables of the Python the comparison runs against (tests/shadow/gen-names.py; tools note at jpPersonKey()).
 */

const JACK_PLAN_SOURCE = '7.4.0 (2026-10-15)';      // the MediaCache this was ported from
const JACK_PLAN_TAPE_V = 1;                         // mediacache_shadow.py's TAPE_V
const JACK_PLAN_THEME  = 'theme.mp3';
const JACK_PLAN_TYPES  = ['emby', 'jellyfin', 'plex'];
const JACK_PLAN_FIELDS = 'Path,SeriesId,ParentIndexNumber,IndexNumber,LocationType';
const JACK_PLAN_PLEX_TV = 'https://plex.tv';
const JACK_PLAN_THEME_PAGE = 2000;                  // library_items(): MediaServerApi.THEME_PAGE
const JACK_PLAN_UNITS = ['' => 1, 'B' => 1, 'K' => 1024, 'KB' => 1024, 'M' => 1048576, 'MB' => 1048576, 'G' => 1073741824, 'GB' => 1073741824,
                         'T' => 1099511627776, 'TB' => 1099511627776, 'P' => 1125899906842624, 'PB' => 1125899906842624];
const JACK_PLAN_REDIRECTS = [301, 302, 303, 307, 308];
const JACK_PLAN_SOURCE_CODES = ['Weiterschauen' => 'resume', 'Als Nächstes' => 'next_up', 'Nächste Folge' => 'next_episode', 'Favorit' => 'favorite'];

/** Where Python ends in a traceback (AttributeError, TypeError, ValueError …): the whole plan ends, outcome «crash» */
final class JackPlanCrash extends RuntimeException
{
}

/** Python's RuntimeError from the API clients: this server's answer is missing — planner errors, an incomplete plan */
final class JackPlanApiError extends RuntimeException
{
}

/** Python's ConfigError: outcome «config» */
final class JackPlanConfigError extends RuntimeException
{
}

/** Asked of the network and the pool: live (JackPlanLive) or from the one set of answers (JackPlanTape) */
interface JackPlanHost
{
    /** The body of an answer; JackPlanApiError for an HTTP error or no answer. $plain headers go along redirects, $key only to its own host */
    public function fetch(string $url, array $plain, array $key, string $token, mixed $timeout): string;

    public function now(): float;

    /** @return array{int, int} total and free bytes (pool_usage()) */
    public function poolUsage(string $path): array;
}

// ===================================================================== what Python's types do

function jpCrash(string $why): never
{
    throw new JackPlanCrash($why);
}

/** human(): «1.50 GB» */
function jpHuman(int|float $size): string
{
    $size = (float) $size;
    foreach (['B', 'KB', 'MB', 'GB', 'TB', 'PB'] as $unit) {
        if ($size < 1024) {
            return sprintf('%.2f %s', $size, $unit);
        }
        $size /= 1024;
    }
    return sprintf('%.2f EB', $size);
}

/** dict.get(): only a dict has it */
function jpGet(mixed $o, string $key, mixed $default = null): mixed
{
    if (!$o instanceof stdClass) {
        jpCrash("no dict: .get('$key')");
    }
    return property_exists($o, $key) ? $o->{$key} : $default;
}

/** Python's truth: "0" is true, {} and [] are false */
function jpTrue(mixed $v): bool
{
    return match (true) {
        $v === null, $v === false, $v === 0, $v === 0.0, $v === '', $v === [] => false,
        $v instanceof stdClass => get_object_vars($v) !== [],
        default => true,
    };
}

/** `a or b` */
function jpOr(mixed $a, mixed $b): mixed
{
    return jpTrue($a) ? $a : $b;
}

/** repr() of what JSON can hold, as far as a plan shows it */
function jpRepr(mixed $v): string
{
    if (is_string($v)) {
        return "'" . str_replace("'", "\\'", str_replace('\\', '\\\\', $v)) . "'";
    }
    if (is_array($v)) {
        return '[' . implode(', ', array_map('jpRepr', $v)) . ']';
    }
    if ($v instanceof stdClass) {
        $out = [];
        foreach (get_object_vars($v) as $k => $x) {
            $out[] = jpRepr((string) $k) . ': ' . jpRepr($x);
        }
        return '{' . implode(', ', $out) . '}';
    }
    return jpStr($v);
}

/** str() / f"{v}" */
function jpStr(mixed $v): string
{
    return match (true) {
        is_string($v) => $v,
        $v === null => 'None',
        $v === true => 'True',
        $v === false => 'False',
        is_int($v) => (string) $v,
        is_float($v) => jpFloatRepr($v),
        default => jpRepr($v),
    };
}

/** repr() of a float: the shortest digits; fixed from 1e-4 up to 1e16, else «1e-05», «1.5e+16» */
function jpFloatRepr(float $v): string
{
    if (is_nan($v)) {
        return 'nan';
    }
    if (is_infinite($v)) {
        return $v > 0 ? 'inf' : '-inf';
    }
    if ($v === 0.0) {
        return fdiv(1.0, $v) < 0 ? '-0.0' : '0.0';
    }
    $r = var_export($v, true);       // the shortest round trip: «1.0E-5», «0.1», «1.0E+25», «100.0»
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:E([+-]\d+))?$/D', $r, $m)) {
        return $r;
    }
    $digits = $m[2] . ($m[3] ?? '');
    $point = strlen($m[2]) + (int) ($m[4] ?? 0);
    $lead = strlen($digits) - strlen(ltrim($digits, '0'));
    $digits = rtrim(substr($digits, $lead), '0');
    $point -= $lead;
    $exp = $point - 1;
    if ($exp < -4 || $exp >= 16) {
        $mant = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
        return $m[1] . $mant . 'e' . ($exp < 0 ? '-' : '+') . sprintf('%02d', abs($exp));
    }
    if ($point <= 0) {
        return $m[1] . '0.' . str_repeat('0', -$point) . $digits;
    }
    $int = substr(str_pad($digits, $point, '0'), 0, $point);
    $frac = (string) substr($digits, $point);
    return $m[1] . $int . '.' . ($frac === '' ? '0' : $frac);
}

/** a == b */
function jpEq(mixed $a, mixed $b): bool
{
    $num = fn (mixed $x): bool => is_int($x) || is_float($x) || is_bool($x);
    if ($num($a) && $num($b)) {
        return (float) $a === (float) $b;
    }
    if (is_array($a) || is_array($b) || $a instanceof stdClass || $b instanceof stdClass) {
        return json_encode($a) === json_encode($b);
    }
    return $a === $b;
}

/** A value as the key of a set or dict: lists and dicts are unhashable; 1, 1.0 and True are one key */
function jpKey(mixed $v): string
{
    return match (true) {
        is_array($v), $v instanceof stdClass => jpCrash('unhashable'),
        is_string($v) => "s:$v",
        $v === null => 'z',
        default => 'n:' . (float) $v,
    };
}

/** a < b for what JSON can hold, as far as sorting takes it: numbers with numbers, strings with strings, else TypeError */
function jpLess(mixed $a, mixed $b): bool
{
    $num = fn (mixed $x): bool => is_int($x) || is_float($x) || is_bool($x);
    if ($num($a) && $num($b)) {
        return (float) $a < (float) $b;
    }
    if (is_string($a) && is_string($b)) {
        return strcmp($a, $b) < 0;
    }
    jpCrash('< between ' . gettype($a) . ' and ' . gettype($b));
}

/** Two tuples: -1, 0, 1 (Python's tuple order) */
function jpCmpTuple(array $a, array $b): int
{
    foreach ($a as $i => $x) {
        if (!jpEq($x, $b[$i])) {
            return jpLess($x, $b[$i]) ? -1 : 1;
        }
    }
    return 0;
}

/** What `for x in v` gives; a dict its keys, a text its characters; anything else isn't iterable */
function jpIter(mixed $v): array
{
    return match (true) {
        is_array($v) => $v,
        $v instanceof stdClass => array_map('strval', array_keys(get_object_vars($v))),
        is_string($v) => (array) mb_str_split($v, 1, 'UTF-8'),
        default => jpCrash('not iterable'),
    };
}

/** f"{v:02d}" */
function jpTwoDigits(mixed $v): string
{
    if (is_bool($v)) {
        $v = (int) $v;
    }
    if (!is_int($v)) {
        jpCrash('{:02d} of no int');
    }
    return sprintf('%02d', $v);
}

/** Python's str.strip() (what str.isspace() calls space) */
function jpStrip(string $s): string
{
    return (string) preg_replace('/^[\t\n\x0b\f\r \x1c-\x1f\x85\xa0\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+|[\t\n\x0b\f\r \x1c-\x1f\x85\xa0\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]+$/Du', '', $s);
}

/** float(): a number, or JackPlanCrash's cousin for the callers that turn it into a ConfigError */
function jpFloat(mixed $v): float
{
    if (is_int($v) || is_float($v) || is_bool($v)) {
        return (float) $v;
    }
    if (is_string($v)) {
        $s = strtolower(jpStrip($v));
        if (preg_match('/^[+-]?(inf|infinity)$/D', $s)) {
            return $s[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^[+-]?nan$/D', $s)) {
            return NAN;
        }
        if (preg_match('/^[+-]?(\d+(_\d+)*(\.(\d+(_\d+)*)?)?|\.\d+(_\d+)*)(e[+-]?\d+(_\d+)*)?$/D', $s)) {
            return (float) str_replace('_', '', $s);
        }
    }
    throw new ValueError('no float');
}

/** int() */
function jpInt(mixed $v): int
{
    if (is_int($v)) {
        return $v;
    }
    if (is_bool($v)) {
        return (int) $v;
    }
    if (is_float($v)) {
        if (is_nan($v)) {
            throw new ValueError('int() of nan');            // Python: ValueError (a size «nanG» is a ConfigError)
        }
        if (is_infinite($v)) {
            jpCrash('int() of inf');                         // Python: OverflowError, caught nowhere
        }
        return (int) $v;
    }
    if (is_string($v) && preg_match('/^[+-]?\d+(_\d+)*$/D', $s = jpStrip($v))) {
        return (int) str_replace('_', '', $s);
    }
    throw new ValueError('no int');
}

/** x // y for floats, as Python's float_divmod */
function jpFloorDiv(float $x, float $y): float
{
    $mod = fmod($x, $y);
    $div = ($x - $mod) / $y;
    if ($mod !== 0.0 && (($y < 0.0) !== ($mod < 0.0))) {
        $div -= 1.0;
    }
    if ($div === 0.0) {
        return $x / $y < 0.0 ? -0.0 : 0.0;
    }
    $floor = floor($div);
    return $div - $floor > 0.5 ? $floor + 1.0 : $floor;
}

/** What a server's answer is, with json.loads()'s eyes: an empty body is null; no UTF-8 or no JSON throws */
function jpJson(string $body): mixed
{
    if ($body === '') {
        return null;
    }
    if (!mb_check_encoding($body, 'UTF-8')) {
        throw new ValueError('no UTF-8');
    }
    $v = json_decode($body, false, 4096);
    if (json_last_error() === JSON_ERROR_UTF16) {
        // an escaped lone surrogate: Python takes it into its text; no file has such a name (U+FFFD shows the same: not found)
        $fixed = (string) preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)\\\\u[dD][89abAB][0-9a-fA-F]{2}(?!\\\\u[dD][c-fC-F][0-9a-fA-F]{2})|(?<!\\\\)((?:\\\\\\\\)*)(?<![dD][89abAB][0-9a-fA-F]{2})\\\\u[dD][c-fC-F][0-9a-fA-F]{2}/', '$1$2\\ufffd', $body);
        $v = json_decode($fixed, false, 4096);
    }
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new ValueError('no JSON');
    }
    return $v;
}

/** A text as both sides write it into a plan: a byte that is no UTF-8 as \xNN */
function jackPlanCleanText(string $s): string
{
    if (mb_check_encoding($s, 'UTF-8')) {
        return $s;
    }
    return (string) preg_replace_callback('/(?:[\x00-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf]{2}|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf]{2}|[\xf1-\xf3][\x80-\xbf]{3}|\xf4[\x80-\x8f][\x80-\xbf]{2})+|(.)/s',
        fn (array $m): string => isset($m[1]) ? sprintf('\\x%02x', ord($m[1])) : $m[0], $s);
}

/** The whole plan through jackPlanCleanText(); stdClass stays what json_encode makes of it */
function jackPlanClean(mixed $v): mixed
{
    if (is_string($v)) {
        return jackPlanCleanText($v);
    }
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) {
            $out[is_string($k) ? jackPlanCleanText($k) : $k] = jackPlanClean($x);
        }
        return $out;
    }
    if ($v instanceof stdClass) {
        $out = new stdClass();
        foreach (get_object_vars($v) as $k => $x) {
            $out->{jackPlanCleanText((string) $k)} = jackPlanClean($x);
        }
        return $out;
    }
    return $v;
}

// ===================================================================== pathlib's PurePosixPath, as far as the plan needs it

/** @return array{string, list<string>} the root ('', '/', '//') and the parts: `//` and `/./` gone, `..` kept */
function jpParts(string $path): array
{
    $root = '';
    if (str_starts_with($path, '/')) {
        $root = str_starts_with($path, '//') && !str_starts_with($path, '///') ? '//' : '/';
    }
    return [$root, array_values(array_filter(explode('/', $path), fn (string $p): bool => $p !== '' && $p !== '.'))];
}

/** str(Path(p)), but '' for «.» */
function jpNorm(string $path): string
{
    [$root, $parts] = jpParts($path);
    return $root . implode('/', $parts);
}

/** Path(base) / rel: rel normalised and relative; '' is no part */
function jpJoin(string $base, string $rel): string
{
    return $rel === '' ? $base : ($base === '' ? $rel : (str_ends_with($base, '/') ? $base . $rel : "$base/$rel"));
}

/** p.relative_to(root): the rest, '' for the root itself; null: not below it */
function jpRelative(string $path, string $root): ?string
{
    [$pr, $pp] = jpParts($path);
    [$rr, $rp] = jpParts($root);
    if ($pr !== $rr || array_slice($pp, 0, count($rp)) !== $rp) {
        return null;
    }
    return implode('/', array_slice($pp, count($rp)));
}

/** Path(rel).parent; '' for one part */
function jpParent(string $rel): string
{
    $p = strrpos($rel, '/');
    return $p === false ? '' : substr($rel, 0, $p);
}

function jpName(string $rel): string
{
    $p = strrpos($rel, '/');
    return $p === false ? $rel : substr($rel, $p + 1);
}

/** Path.stem of the Python the plan follows: «foo.» is «foo.» up to 3.13, «foo» from 3.14 (jackPlanPython()) */
function jpStem(string $name): string
{
    $i = strrpos($name, '.');
    $last = jackPlanPython() >= [3, 14] ? strlen($name) : strlen($name) - 1;
    return $i !== false && $i > 0 && $i < $last ? substr($name, 0, $i) : $name;
}

/** Which Python's rules: the one that wrote the tape (its header), else 3.11 (Tower's, the one this was ported from) */
function jackPlanPython(?array $set = null): array
{
    static $py = [3, 11];
    if ($set !== null) {
        $py = array_map('intval', array_slice($set, 0, 2));
    }
    return $py;
}

const JACK_PLAN_UTF8_RUN = '/(?:[\x00-\x7f]|[\xc2-\xdf][\x80-\xbf]|\xe0[\xa0-\xbf][\x80-\xbf]|[\xe1-\xec\xee\xef][\x80-\xbf]{2}|\xed[\x80-\x9f][\x80-\xbf]|\xf0[\x90-\xbf][\x80-\xbf]{2}|[\xf1-\xf3][\x80-\xbf]{3}|\xf4[\x80-\x8f][\x80-\xbf]{2})+|(.)/s';

/** Python's order of two texts: code points; a byte that is no UTF-8 is U+DC80+byte (surrogateescape) */
function jpCmp(string $a, string $b): int
{
    if (mb_check_encoding($a, 'UTF-8') && mb_check_encoding($b, 'UTF-8')) {
        return strcmp($a, $b) <=> 0;
    }
    return strcmp(jpSortKey($a), jpSortKey($b)) <=> 0;
}

/** A text's code points, four bytes each (their order is the bytes' order) */
function jpSortKey(string $s): string
{
    preg_match_all(JACK_PLAN_UTF8_RUN, $s, $m, PREG_SET_ORDER);
    $out = '';
    foreach ($m as $x) {
        $out .= isset($x[1]) ? pack('N', 0xDC00 + ord($x[1])) : (string) mb_convert_encoding($x[0], 'UTF-32BE', 'UTF-8');
    }
    return $out;
}

/** sorted() of texts */
function jpSorted(array $list): array
{
    usort($list, 'jpCmp');
    return $list;
}

/** Sorted as a list of Paths: part by part, so «a/c» comes before «a b/c» */
function jpSortPaths(array $paths): array
{
    usort($paths, function (string $a, string $b): int {
        $pa = explode('/', $a);
        $pb = explode('/', $b);
        foreach ($pa as $i => $x) {
            if (!isset($pb[$i])) {
                return 1;
            }
            if (($c = jpCmp($x, $pb[$i])) !== 0) {
                return $c;
            }
        }
        return count($pa) <=> count($pb);
    });
    return $paths;
}

// ===================================================================== one person over several servers

/**
 * person_key(): the same name ignoring case, spaces and accents. NFKD without intl: jack-plan-names.txt, made from Python's own
 * unicodedata (one line per character whose NFKD is another: «hex,hex hex …»; then `#combining`, ranges of hex with a
 * non-zero combining class; `#casefold` and `#lower`, the characters str.casefold()/str.lower() change). Hangul's syllables by their
 * rule. The table is that of the Python the comparison runs against (now Tower's 3.11, Unicode 14.0; its first line says which). WHEN
 * PYTHON IS GONE: keep the table (PHP then decides alone — the same people as before) or switch to mbstring's MB_CASE_FOLD and
 * mb_strtolower (a newer Unicode: about 30 rare letters fold otherwise); never regenerate it from another Python while both run.
 */
function jpPersonKey(mixed $name): string
{
    $n = jpStr(jpOr($name, ''));
    if (preg_match('/^[\x21-\x7e\x09-\x0d\x20\x1c-\x1f]*$/D', $n)) {
        return 'p:' . strtolower((string) preg_replace('/[\x09-\x0d\x20\x1c-\x1f]+/', '', $n));
    }
    if (!mb_check_encoding($n, 'UTF-8')) {
        $n = mb_scrub($n, 'UTF-8');
    }
    [$map, $marks] = jpNamesTable();
    $out = '';
    foreach ((array) mb_str_split($n, 1, 'UTF-8') as $c) {
        $cp = mb_ord($c, 'UTF-8');
        if ($cp >= 0xAC00 && $cp <= 0xD7A3) {
            $s = $cp - 0xAC00;
            $out .= mb_chr(0x1100 + intdiv($s, 588), 'UTF-8') . mb_chr(0x1161 + intdiv($s % 588, 28), 'UTF-8') . ($s % 28 ? mb_chr(0x11A7 + $s % 28, 'UTF-8') : '');
            continue;
        }
        $out .= $map[$cp] ?? $c;
    }
    $kept = '';
    foreach ((array) mb_str_split($out, 1, 'UTF-8') as $c) {
        $cp = mb_ord($c, 'UTF-8');
        if (isset($marks[$cp]) || preg_match('/^[\t\n\x0b\f\r \x1c-\x1f\x85\xa0\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}]$/Du', $c)) {
            continue;
        }
        $kept .= $c;
    }
    return 'p:' . jpCaseMap($kept, 2);
}

/** str.casefold() ($which 2) or str.lower() (3, without Final_Sigma's Σ → ς at a word's end) by Python's table */
function jpCaseMap(string $s, int $which): string
{
    if (preg_match('/^[\x00-\x7f]*$/D', $s)) {
        return strtolower($s);
    }
    $map = jpNamesTable()[$which];
    $out = '';
    foreach ((array) mb_str_split(mb_scrub($s, 'UTF-8'), 1, 'UTF-8') as $c) {
        $out .= $map[mb_ord($c, 'UTF-8')] ?? $c;
    }
    return $out;
}

/** str.lower() */
function jpLower(string $s): string
{
    return jpCaseMap($s, 3);
}

/** @return array{array<int, string>, array<int, true>, array<int, string>, array<int, string>} NFKD, combining, casefold, lower */
function jpNamesTable(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }
    $t = [[], [], [], []];
    $part = 0;
    foreach (@file(__DIR__ . '/jack-plan-names.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with($line, '#')) {
            $part = ['#combining' => 1, '#casefold' => 2, '#lower' => 3][$line] ?? $part;
            continue;
        }
        if ($part === 1) {
            [$a, $b] = explode('-', $line . '-' . $line);
            for ($c = (int) hexdec($a), $e = (int) hexdec($b); $c <= $e; $c++) {
                $t[1][$c] = true;
            }
            continue;
        }
        [$from, $to] = explode(',', $line);
        $t[$part][(int) hexdec($from)] = implode('', array_map(fn (string $h): string => (string) mb_chr((int) hexdec($h), 'UTF-8'), explode(' ', $to)));
    }
    return $table = $t;
}

// ===================================================================== the settings (load_config())

/** parse_size(): '2.5T', '800G', a number of bytes; nothing is 0 */
function jackPlanSize(mixed $value): int
{
    if ($value === null) {
        return 0;
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return jpInt($value);
    }
    $text = str_replace(' ', '', mb_strtoupper(jpStrip(jpStr($value)), 'UTF-8'));
    if ($text === '') {
        return 0;
    }
    $num = rtrim($text, 'KMGTPB');
    $unit = substr($text, strlen($num));
    try {
        if (!array_key_exists($unit, JACK_PLAN_UNITS)) {
            throw new ValueError('no unit');
        }
        return jpInt(jpFloat($num) * JACK_PLAN_UNITS[$unit]);
    } catch (ValueError) {
        throw new JackPlanConfigError("bad size: $text");
    }
}

/** Numbers as Python reads them in a setting: what is none is a ConfigError */
function jackPlanNumber(callable $read, mixed $v, string $what): mixed
{
    try {
        return $read($v);
    } catch (ValueError) {
        throw new JackPlanConfigError("$what: no number");
    }
}

/**
 * load_config(): the settings with their defaults, every check Python makes. `instances` carry `_mappings` (list of [docker,
 * host], longest wins later) and `_skip` (docker paths with an empty host path: deliberately not cached).
 *
 * @param array<string, string> $env what Python would find in its environment
 */
function jackPlanConfig(string $file, array $env, bool $requirePaths = true): array
{
    if (!file_exists($file)) {
        throw new JackPlanConfigError('no settings');
    }
    $text = (string) @file_get_contents($file);
    if (!mb_check_encoding($text, 'UTF-8')) {
        jpCrash('settings: no UTF-8');
    }
    try {
        $raw = jpJson($text === '' ? ' ' : $text);
    } catch (ValueError) {
        throw new JackPlanConfigError('settings: no JSON');
    }
    if (!$raw instanceof stdClass) {
        jpCrash('settings: no dict');
    }
    $cfg = get_object_vars($raw) + ['cache_path' => '/mnt/cache', 'array_path' => '/mnt/user0', 'user_path' => '/mnt/user', 'array_disks_glob' => '/mnt/disk[0-9]*',
        'array_source' => 'user0', 'instances' => [], 'path_mappings' => new stdClass(), 'valid_users' => [], 'number_episodes' => 3, 'cache_budget' => '',
        'movie_share_percent' => 50, 'max_episodes_per_series' => 0, 'max_resume_items' => 10, 'max_resume_movies' => null, 'max_resume_series' => null,
        'max_favorite_series' => 10, 'use_next_up' => true, 'min_free_percent' => 20, 'movie_mode' => 'folder', 'create_share_root' => true, 'mover_debug_level' => 0,
        'rsync_args' => ['-aAX', '--numeric-ids'], 'fill_tool' => 'rsync', 'cleanup_tool' => 'mover', 'parallel_per_disk' => 1, 'parallel_total' => 1, 'copy_progress' => true,
        'status_log_interval' => 60, 'api_timeout' => 10, 'shares_cfg_dir' => '/boot/config/shares', 'themes' => false];
    $has = fn (string $k): bool => ($env[$k] ?? '') !== '';
    if ($has('MEDIACACHE_MIN_FREE_PERCENT')) {
        $cfg['min_free_percent'] = $env['MEDIACACHE_MIN_FREE_PERCENT'];
    }
    if ($has('MEDIACACHE_MOVER_DEBUG')) {
        $cfg['mover_debug_level'] = $env['MEDIACACHE_MOVER_DEBUG'];
    }
    $cfg['min_free_percent'] = jackPlanNumber('jpFloat', $cfg['min_free_percent'], 'min_free_percent');
    $cfg['mover_debug_level'] = jackPlanNumber('jpInt', $cfg['mover_debug_level'], 'mover_debug_level');
    if (array_key_exists('MEDIACACHE_RSYNC_ARGS', $env)) {
        $cfg['rsync_args'] = (array) preg_split('/\s+/', trim($env['MEDIACACHE_RSYNC_ARGS']), -1, PREG_SPLIT_NO_EMPTY);
    }
    if ($has('MEDIACACHE_FILL_TOOL')) {
        $cfg['fill_tool'] = jpLower($env['MEDIACACHE_FILL_TOOL']);
    }
    foreach (['cache_path', 'array_path', 'user_path'] as $key) {
        $cfg[$key] = rtrim(jpStr($cfg[$key]), '/') ?: '/';
    }
    if (array_key_exists('MEDIACACHE_CACHE_BUDGET', $env)) {
        $cfg['cache_budget'] = $env['MEDIACACHE_CACHE_BUDGET'];
    }
    $vu = $cfg['valid_users'];
    $cfg['user_budgets'] = [];
    if ($vu instanceof stdClass) {
        foreach (get_object_vars($vu) as $uid => $opts) {
            $b = $opts instanceof stdClass ? jackPlanSize(jpGet($opts, 'budget')) : 0;
            if ($b > 0) {
                $cfg['user_budgets'][(string) $uid] = $b;
            }
        }
    }
    $cfg['valid_users'] = array_values(array_filter(array_map('jpStr', jpIter($vu)), fn (string $k): bool => jpStrip($k) !== ''));
    $cfg['cache_budget_bytes'] = jackPlanSize($cfg['cache_budget']);
    foreach (['max_resume_movies', 'max_resume_series'] as $key) {
        $cfg[$key] = jackPlanNumber('jpInt', $cfg[$key] ?? $cfg['max_resume_items'], $key);
    }
    $cfg['movie_share_percent'] = jackPlanNumber('jpFloat', $cfg['movie_share_percent'], 'movie_share_percent');
    $cfg['max_episodes_per_series'] = jackPlanNumber('jpInt', $cfg['max_episodes_per_series'], 'max_episodes_per_series');
    if (!($cfg['movie_share_percent'] >= 0 && $cfg['movie_share_percent'] <= 100)) {
        throw new JackPlanConfigError('movie_share_percent: 0 to 100');
    }
    if (!jpTrue($cfg['instances'])) {
        throw new JackPlanConfigError('no instances');
    }
    $instances = [];
    foreach (jpIter($cfg['instances']) as $i => $one) {
        if (!$one instanceof stdClass) {
            jpCrash('an instance is no dict');
        }
        $inst = get_object_vars($one);
        $inst['url'] = rtrim(jpStr(array_key_exists('url', $inst) ? $inst['url'] : ''), '/');
        if (!array_key_exists('servername', $inst)) {
            $inst['servername'] = 'Server' . ($i + 1);
        }
        $inst['type'] = jpLower(jpStr(jpOr($inst['type'] ?? null, 'emby')));
        if (!in_array($inst['type'], JACK_PLAN_TYPES, true)) {
            throw new JackPlanConfigError('bad type');
        }
        if ($inst['type'] === 'plex') {
            $inst['days_to_monitor'] = jackPlanNumber('jpInt', array_key_exists('days_to_monitor', $inst) ? $inst['days_to_monitor'] : 99, 'days_to_monitor');
        }
        if ($inst['url'] === '' || !jpTrue($inst['api_key'] ?? null)) {
            throw new JackPlanConfigError('url or api_key missing');
        }
        $merged = [];
        foreach ([$cfg['path_mappings'], jpOr($inst['path_mappings'] ?? null, new stdClass())] as $m) {
            foreach (jackPlanMappingPairs($m) as [$k, $v]) {
                $merged["k$k"] = [$k, $v];
            }
        }
        $maps = $skip = [];
        foreach ($merged as [$k, $v]) {
            if ($k !== '' && jpTrue($v)) {
                if (!is_string($v)) {
                    jpCrash('a mapping is no text');
                }
                $maps['k' . rtrim($k, '/')] = [rtrim($k, '/'), rtrim($v, '/')];
            } elseif ($k !== '' && !jpTrue($v)) {
                $skip[] = rtrim($k, '/');
            }
        }
        $skip = jpSorted($skip);
        $inst['_mappings'] = array_values($maps);
        $inst['_skip'] = $skip;
        if (!$inst['_mappings']) {
            throw new JackPlanConfigError('no path_mappings');
        }
        $instances[] = $inst;
    }
    $cfg['instances'] = $instances;
    if ($requirePaths && (!is_dir($cfg['cache_path']) || !is_dir($cfg['array_path']))) {
        throw new JackPlanConfigError('cache_path or array_path missing');
    }
    if (!in_array($cfg['movie_mode'], ['folder', 'file'], true)) {
        throw new JackPlanConfigError('movie_mode');
    }
    if (!in_array($cfg['fill_tool'], ['rsync', 'mover'], true)) {
        throw new JackPlanConfigError('fill_tool');
    }
    if ($has('MEDIACACHE_CLEANUP_TOOL')) {
        $cfg['cleanup_tool'] = jpLower($env['MEDIACACHE_CLEANUP_TOOL']);
    }
    if (!in_array($cfg['cleanup_tool'], ['rsync', 'mover'], true)) {
        throw new JackPlanConfigError('cleanup_tool');
    }
    if (!in_array($cfg['array_source'], ['user0', 'disk'], true)) {
        throw new JackPlanConfigError('array_source');
    }
    foreach ([['parallel_per_disk', 'MEDIACACHE_PARALLEL_PER_DISK', 1], ['parallel_total', 'MEDIACACHE_PARALLEL_TOTAL', 0],
              ['status_log_interval', 'MEDIACACHE_STATUS_LOG_INTERVAL', 0]] as [$key, $var, $min]) {
        if ($has($var)) {
            $cfg[$key] = $env[$var];
        }
        $cfg[$key] = jackPlanNumber('jpInt', $cfg[$key], $key);
        if ($cfg[$key] < $min) {
            throw new JackPlanConfigError("$key: at least $min");
        }
    }
    $cfg['create_share_root'] = jpTrue($cfg['create_share_root']);
    $cfg['themes'] = $cfg['themes'] === true;
    if (!jpTrue($cfg['rsync_args'])) {
        throw new JackPlanConfigError('rsync_args');
    }
    return $cfg;
}

/** dict(x): a dict, or a list of pairs (an empty list too: that is how PHP's save writes no mappings); else Python's traceback */
function jackPlanMappingPairs(mixed $m): array
{
    $out = [];
    if ($m instanceof stdClass) {
        foreach (get_object_vars($m) as $k => $v) {
            $out[] = [(string) $k, $v];
        }
        return $out;
    }
    if (!is_array($m)) {
        jpCrash('path_mappings is no dict');
    }
    foreach ($m as $pair) {
        if (!is_array($pair) || count($pair) !== 2 || !is_string($pair[0])) {
            jpCrash('path_mappings is no dict');
        }
        $out[] = [$pair[0], $pair[1]];
    }
    return $out;
}

/** keep_bytes(): MEDIACACHE_KEEP_BYTES, null: no floor */
function jackPlanKeepBytes(array $env): ?int
{
    $v = jpStrip((string) ($env['MEDIACACHE_KEEP_BYTES'] ?? ''));
    if ($v === '') {
        return null;
    }
    if (!ctype_digit($v)) {
        throw new JackPlanConfigError('MEDIACACHE_KEEP_BYTES');
    }
    return (int) $v;
}

// ===================================================================== the media servers

/** urllib's urlencode() of what isn't None, in the order given */
function jackPlanQuery(array $params): string
{
    $out = [];
    foreach ($params as $k => $v) {
        if ($v !== null) {
            $out[] = str_replace('%20', '+', rawurlencode((string) $k)) . '=' . str_replace('%20', '+', rawurlencode(jpStr($v)));
        }
    }
    return implode('&', $out);
}

/** urllib's Request(): a URL without a scheme is Python's ValueError (a traceback, before anything is asked) */
function jackPlanUrlOk(string $url): string
{
    if (!preg_match('#^[^/:]+:#', $url)) {
        jpCrash('unknown url type');
    }
    return $url;
}

/** api_timeout as socket.settimeout() takes it: a number ≥ 0, or null (none: here a day); anything else a traceback at the first
 * request (JackPlanLive; from a tape the request's own record says so) */
function jackPlanTimeout(mixed $t): float
{
    if ($t === null) {
        return 86400.0;
    }
    if (!(is_int($t) || is_float($t) || is_bool($t)) || $t < 0 || is_nan((float) $t)) {
        jpCrash('settimeout() of ' . gettype($t));
    }
    return (float) $t;
}

/** The hash a token stands as on the tape (mediacache_shadow.py: token_id()); a `tape:<hash>` from an answer on the tape is that hash */
function jackPlanTokenId(string $token): string
{
    return str_starts_with($token, 'tape:') ? substr($token, 5) : substr(hash('sha256', $token), 0, 16);
}

/**
 * MediaServerApi: Emby and Jellyfin. The key goes as a header, never in the URL, and never to another host (JackPlanLive).
 */
class JackPlanServer
{
    protected string $url;

    public bool $usersIncomplete = false;

    public function __construct(string $url, protected string $apiKey, protected mixed $timeout, protected string $type, protected JackPlanHost $host)
    {
        $this->url = rtrim($url, '/');
    }

    public static function forInstance(array $inst, mixed $timeout, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV): self
    {
        if ($inst['type'] === 'plex') {
            return new JackPlanPlex($inst['url'], jpStr($inst['api_key']), $timeout, (int) $inst['days_to_monitor'], $host, $plexTv);
        }
        return new self($inst['url'], jpStr($inst['api_key']), $timeout, $inst['type'], $host);
    }

    public function get(string $path, array $params = []): mixed
    {
        $query = jackPlanQuery($params);
        $key = $this->type === 'jellyfin' ? ['Authorization' => "MediaBrowser Token=\"$this->apiKey\""] : ['X-Emby-Token' => $this->apiKey];
        $body = $this->host->fetch(jackPlanUrlOk($this->url . $path . ($query !== '' ? "?$query" : '')), ['Accept' => 'application/json'], $key, $this->apiKey, $this->timeout);
        try {
            return jpJson($body);
        } catch (ValueError $e) {
            jpCrash("$path: {$e->getMessage()}");      // json.loads() outside its try: Python's traceback
        }
    }

    /** An answer without a list is an error, never «nothing on deck» */
    public function items(string $path, array $params = []): array
    {
        $data = $this->get($path, $params);
        if ($data instanceof stdClass && is_array($data->Items ?? null)) {
            return $data->Items;
        }
        if (is_array($data)) {
            return $data;
        }
        throw new JackPlanApiError("no list from $path");
    }

    public function userItems(mixed $uid, array $params): array
    {
        return $this->type === 'jellyfin' ? $this->items('/Items', ['userId' => $uid] + $params) : $this->items('/Users/' . jpStr($uid) . '/Items', $params);
    }

    public function resumeItems(mixed $uid, array $params): array
    {
        return $this->type === 'jellyfin' ? $this->items('/UserItems/Resume', ['userId' => $uid] + $params) : $this->items('/Users/' . jpStr($uid) . '/Items/Resume', $params);
    }

    public function nextUp(mixed $uid, array $params): array
    {
        return $this->items('/Shows/NextUp', ['UserId' => $uid] + $params);
    }

    public function users(): array
    {
        return jpIter(jpOr($this->get('/Users'), []));
    }

    public function sessions(): array
    {
        return jpIter(jpOr($this->get('/Sessions'), []));
    }

    /** Every film and series of the server, in pages; for the client's look at its paging (library_items()) */
    public function libraryItems(): array
    {
        $out = [];
        for ($start = 0; ; $start += count($page)) {
            $page = $this->items('/Items', ['Recursive' => 'true', 'IncludeItemTypes' => 'Movie,Series', 'Fields' => 'Path,DateCreated',
                'EnableImages' => 'false', 'EnableUserData' => 'false', 'StartIndex' => $start, 'Limit' => JACK_PLAN_THEME_PAGE]);
            foreach ($page as $i) {
                if ($i instanceof stdClass && in_array($i->Type ?? null, ['Movie', 'Series'], true)) {
                    $out[] = ['Id' => jpStr(jpOr($i->Id ?? null, '')), 'Type' => $i->Type, 'Path' => jpOr($i->Path ?? null, ''),
                              'DateCreated' => $i->DateCreated ?? null, 'LocationType' => $i->LocationType ?? null];
                }
            }
            if (count($page) < JACK_PLAN_THEME_PAGE) {
                return $out;
            }
        }
    }

    /** theme_folder(): the folder (a Docker path) a theme.mp3 of an item would lie in — Emby and Jellyfin know it from its Path */
    public function themeFolder(array $item): mixed
    {
        return jpOr($item['Path'] ?? null, '');
    }
}

/**
 * PlexApi: the same face, items in Emby's shape. Users: the admin plus everyone the server is shared with (plex.tv); plex.tv not
 * answering for a claimed server is an incomplete plan, never «the others watch nothing».
 */
final class JackPlanPlex extends JackPlanServer
{
    private ?array $tokens = null;

    private array $names = [];

    public function __construct(string $url, string $apiKey, mixed $timeout, private int $days, JackPlanHost $host, private string $plexTv)
    {
        parent::__construct($url, $apiKey, $timeout, 'plex', $host);
    }

    private function request(string $full, string $token, bool $json = true): mixed
    {
        $plain = ['X-Plex-Client-Identifier' => 'mediacache', 'X-Plex-Product' => 'MediaCache'] + ($json ? ['Accept' => 'application/json'] : []);
        $body = $this->host->fetch(jackPlanUrlOk($full), $plain, ['X-Plex-Token' => $token], $token, $this->timeout);
        if (!mb_check_encoding($body, 'UTF-8')) {
            jpCrash('no UTF-8');                       // .decode() outside its except: Python's traceback
        }
        if (!$json) {
            return $body;
        }
        try {
            return jpJson($body);
        } catch (ValueError $e) {
            throw new JackPlanApiError($e->getMessage());
        }
    }

    public function get(string $path, array $params = [], ?string $token = null): mixed
    {
        $query = jackPlanQuery($params);
        $data = $this->request($this->url . $path . ($query !== '' ? "?$query" : ''), $token !== null && jpTrue($token) ? $token : $this->apiKey);
        $outer = jpOr($data, new stdClass());
        return jpOr(jpGet($outer, 'MediaContainer'), new stdClass());
    }

    private function loadUsers(): void
    {
        if ($this->tokens !== null) {
            return;
        }
        $tokens = ['admin' => $this->apiKey];
        $names = ['admin' => 'Admin'];
        try {
            $acc = jpOr($this->request("$this->plexTv/api/v2/user", $this->apiKey), new stdClass());
            $adminId = jpStr(jpOr(jpGet($acc, 'id'), 'admin'));
            $tokens = [$adminId => $this->apiKey];
            $names = [$adminId => jpOr(jpOr(jpGet($acc, 'title'), jpGet($acc, 'username')), 'Admin')];
            $mid = jpGet($this->get('/'), 'machineIdentifier');
            if (jpTrue($mid)) {
                $xml = (string) $this->request("$this->plexTv/api/servers/" . jpStr($mid) . '/shared_servers', $this->apiKey, false);
                $root = $xml === '' ? false : @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                if ($root === false) {
                    throw new JackPlanApiError('shared_servers: no XML');
                }
                foreach ($root->xpath('//SharedServer') ?: [] as $s) {
                    $attr = $s->attributes();
                    $uid = (string) ($attr['userID'] ?? '');
                    $tok = (string) ($attr['accessToken'] ?? '');
                    if ($uid !== '' && $tok !== '' && !array_key_exists($uid, $tokens)) {
                        $tokens[$uid] = $tok;
                        $names[$uid] = jpOr(jpOr((string) ($attr['username'] ?? ''), (string) ($attr['email'] ?? '')), $uid);
                    }
                }
            }
        } catch (JackPlanApiError) {
            try {
                $root = $this->get('/');
                $state = jpGet($root, 'myPlexSigninState');
                $this->usersIncomplete = jpStr(jpOr($state, 'none')) !== 'none' || jpTrue(jpGet($root, 'myPlexUsername'));
            } catch (JackPlanApiError) {
                $this->usersIncomplete = true;
            }
        }
        $this->tokens = [];
        foreach ($tokens as $uid => $tok) {
            $this->tokens["u$uid"] = [(string) $uid, $tok];
        }
        $this->names = $names;
    }

    public function users(): array
    {
        $this->loadUsers();
        $out = [];
        foreach ($this->tokens ?? [] as [$uid]) {
            $o = new stdClass();
            $o->Id = $uid;
            $o->Name = $this->names[$uid] ?? $uid;
            $out[] = $o;
        }
        return $out;
    }

    private function token(mixed $uid): string
    {
        $this->loadUsers();
        $tok = $this->tokens['u' . jpStr($uid)][1] ?? null;
        if ($tok === null || $tok === '') {
            throw new JackPlanApiError('no Plex token for this user');
        }
        return $tok;
    }

    private static function path(stdClass $meta): mixed
    {
        foreach (jpIter(jpOr(jpGet($meta, 'Media'), [])) as $media) {
            foreach (jpIter(jpOr(jpGet($media, 'Part'), [])) as $part) {
                if (jpTrue(jpGet($part, 'file'))) {
                    return jpGet($part, 'file');
                }
            }
        }
        return '';
    }

    private function convert(stdClass $meta): stdClass
    {
        $typ = jpGet($meta, 'type');
        $item = new stdClass();
        $item->Id = jpStr(jpGet($meta, 'ratingKey', ''));
        $item->Name = jpGet($meta, 'title', '?');
        $item->Path = self::path($meta);
        $item->UserData = new stdClass();
        $item->UserData->Played = jpTrue(jpGet($meta, 'viewCount'));
        if ($typ === 'movie') {
            $item->Type = 'Movie';
        } elseif ($typ === 'episode') {
            $item->Type = 'Episode';
            $item->SeriesId = jpStr(jpOr(jpGet($meta, 'grandparentRatingKey'), ''));
            $item->SeriesName = jpGet($meta, 'grandparentTitle', '?');
            $item->ParentIndexNumber = jpGet($meta, 'parentIndex');
            $item->IndexNumber = jpGet($meta, 'index');
        } elseif ($typ === 'show') {
            $item->Type = 'Series';
        } else {
            $item->Type = jpStr(jpOr($typ, ''));
        }
        return $item;
    }

    /** On Deck of the last days_to_monitor days; an entry never viewed (the next episode) always; IncludeItemTypes before the limit */
    public function resumeItems(mixed $uid, array $params): array
    {
        $meta = jpIter(jpOr(jpGet($this->get('/library/onDeck', [], $this->token($uid)), 'Metadata'), []));
        $cutoff = $this->host->now() - $this->days * 86400;
        $want = ['Movie' => ['movie'], 'Episode' => ['episode']][$params['IncludeItemTypes'] ?? ''] ?? ['movie', 'episode'];
        $out = [];
        foreach ($meta as $m) {
            $type = jpGet($m, 'type');
            if (!is_string($type) || !in_array($type, $want, true)) {
                continue;
            }
            $seen = jpGet($m, 'lastViewedAt');
            if ($seen === null || !jpLess($seen, $cutoff)) {
                $out[] = $this->convert($m);
            }
        }
        $limit = $params['Limit'] ?? null;
        return jpTrue($limit) ? array_slice($out, 0, max(0, jpInt($limit))) : $out;
    }

    /** Only a series' episodes; Plex has no favourites */
    public function userItems(mixed $uid, array $params): array
    {
        $parent = $params['ParentId'] ?? null;
        if (($params['IncludeItemTypes'] ?? null) !== 'Episode' || !jpTrue($parent)) {
            return [];
        }
        $meta = jpIter(jpOr(jpGet($this->get('/library/metadata/' . jpStr($parent) . '/allLeaves', [], $this->token($uid)), 'Metadata'), []));
        return array_map(function (mixed $m): stdClass {
            if (!$m instanceof stdClass) {
                jpCrash('no dict');
            }
            return $this->convert($m);
        }, $meta);
    }

    public function nextUp(mixed $uid, array $params): array
    {
        return [];
    }

    public function sessions(): array
    {
        $out = [];
        foreach (jpIter(jpOr(jpGet($this->get('/status/sessions'), 'Metadata'), [])) as $m) {
            if (!$m instanceof stdClass) {
                jpCrash('no dict');
            }
            $o = new stdClass();
            $o->NowPlayingItem = new stdClass();
            $o->NowPlayingItem->Path = self::path($m);
            jpGet(jpOr(jpGet($m, 'User'), new stdClass()), 'title', '?');
            $out[] = $o;
        }
        return $out;
    }

    public function libraryItems(): array
    {
        $out = [];
        foreach (jpIter(jpOr(jpGet($this->get('/library/sections'), 'Directory'), [])) as $d) {
            $kind = ['movie' => 'Movie', 'show' => 'Series'][jpStr(jpGet($d, 'type'))] ?? null;
            if ($kind === null || !jpTrue(jpGet($d, 'key'))) {
                continue;
            }
            foreach (jpIter(jpOr(jpGet($this->get('/library/sections/' . jpStr(jpGet($d, 'key')) . '/all'), 'Metadata'), [])) as $m) {
                if (!$m instanceof stdClass || !jpTrue(jpGet($m, 'ratingKey'))) {
                    continue;
                }
                $path = '';
                if ($kind === 'Movie') {
                    $path = self::path($m);
                } else {
                    foreach (jpIter(jpOr(jpGet($m, 'Location'), [])) as $l) {
                        if (jpTrue(jpGet($l, 'path'))) {
                            $path = jpGet($l, 'path');
                            break;
                        }
                    }
                }
                $out[] = ['Id' => jpStr($m->ratingKey), 'Type' => $kind, 'Path' => jpOr($path, ''), 'DateCreated' => $m->addedAt ?? null];
            }
        }
        return $out;
    }

    /** theme_folder(): a series the list gave without its Location — asked once, when it is needed */
    public function themeFolder(array $item): mixed
    {
        if (jpTrue($item['Path'] ?? null) || ($item['Type'] ?? null) !== 'Series') {
            return jpOr($item['Path'] ?? null, '');
        }
        $meta = jpIter(jpOr(jpGet($this->get('/library/metadata/' . jpStr($item['Id'] ?? '')), 'Metadata'), [new stdClass()]))[0] ?? new stdClass();
        foreach (jpIter(jpOr(jpGet($meta, 'Location'), [])) as $l) {
            if (jpTrue(jpGet($l, 'path'))) {
                return jpGet($l, 'path');
            }
        }
        return '';
    }
}

/**
 * The network for real, and the pool's numbers for real: urllib's way with redirects. Followed for 301, 302, 303, 307, 308 (at most
 * ten; the same URL at most four times), the key only as long as the host stays the server's own — a redirect to another host goes
 * without it, and it never comes back. Never in a log, an error or a file: only headers of this one request.
 */
final class JackPlanLive implements JackPlanHost
{
    public int $asked = 0;

    public int $failed = 0;

    public function __construct(private array $env = [])
    {
    }

    public function fetch(string $url, array $plain, array $key, string $token, mixed $timeout): string
    {
        $this->asked++;
        try {
            if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new JackPlanApiError('unknown url type');       // urllib's URLError (it would try ftp: never here)
            }
            return $this->go($url, $plain, $key, jackPlanTimeout($timeout));
        } catch (JackPlanApiError $e) {
            $this->failed++;
            throw $e;
        }
    }

    private function go(string $url, array $plain, array $key, float $timeout): string
    {
        $visited = [];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        while (true) {
            $lines = [];
            foreach ($plain + $key as $k => $v) {
                $lines[] = "$k: $v";
            }
            $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $lines, 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                                                     'user_agent' => 'Python-urllib/3 (MediaCache shadow)', 'protocol_version' => 1.1]]);
            $path = (string) parse_url($url, PHP_URL_PATH);
            $fh = @fopen($url, 'r', false, $ctx);
            if ($fh === false) {
                throw new JackPlanApiError("$path: no answer");
            }
            $body = (string) stream_get_contents($fh);
            $timedOut = stream_get_meta_data($fh)['timed_out'];
            fclose($fh);
            if ($timedOut) {
                throw new JackPlanApiError("$path: timed out");
            }
            $status = 0;
            $location = null;
            foreach (http_get_last_response_headers() ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                    $status = (int) $m[1];
                    $location = null;       // only the last response's
                } elseif (preg_match('/^(?:location|uri):\s*(.*)$/i', $h, $m)) {
                    $location ??= trim($m[1]);
                }
            }
            if ($status >= 200 && $status < 300) {
                return $body;
            }
            if (!in_array($status, JACK_PLAN_REDIRECTS, true) || $location === null) {
                throw new JackPlanApiError("$path: HTTP $status");
            }
            $next = jackPlanUrlJoin($url, $location);
            $scheme = strtolower((string) parse_url($next, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true) || ($visited[$next] ?? 0) >= 4 || count($visited) >= 10) {
                throw new JackPlanApiError("$path: bad redirect");
            }
            $visited[$next] = ($visited[$next] ?? 0) + 1;
            if (strtolower((string) parse_url($next, PHP_URL_HOST)) !== $host) {
                $key = [];
                $host = "\0gone";          // the key is gone for good
            }
            $url = $next;
        }
    }

    public function now(): float
    {
        return microtime(true);
    }

    public function poolUsage(string $path): array
    {
        return jackPlanPoolUsage($path, $this->env);
    }
}

/** urljoin(), enough for a redirect's Location */
function jackPlanUrlJoin(string $base, string $location): string
{
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $location)) {
        return $location;
    }
    $p = parse_url($base) ?: [];
    $origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($location, '//')) {
        return ($p['scheme'] ?? 'http') . ":$location";
    }
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    $dir = rtrim(dirname(($p['path'] ?? '/') . 'x'), '/');
    return "$origin$dir/$location";
}

/**
 * The one set of answers (mediacache_shadow.py's --tape): each request is answered from what Python's dry run got for exactly that
 * URL and token, in the order it got them. What Python never asked is noted (`missing`), what it asked and this side didn't
 * remains (`left()`): a different question is a difference in the logic. A key is never on it, only a token's hash.
 */
final class JackPlanTape implements JackPlanHost
{
    private array $queues = [];

    private array $pool = [];

    private float $clock = 0.0;

    /** @var array<string, int> the URL (no key in it) => how often it was asked beyond the tape */
    public array $missing = [];

    public int $asked = 0;

    /** @var array{int, int} the Python that wrote it (its rules: jpStem()) */
    public array $python = [3, 11];

    public static function read(string $file): ?self
    {
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return null;
        }
        $t = new self();
        $first = true;
        while (($line = fgets($fh)) !== false) {
            $r = json_decode($line, true);
            if (!is_array($r)) {
                fclose($fh);
                return null;
            }
            if ($first) {
                if (($r['v'] ?? null) !== JACK_PLAN_TAPE_V) {
                    fclose($fh);
                    return null;
                }
                $t->clock = (float) $r['now'];
                if (is_array($r['py'] ?? null) && count($r['py']) === 2) {
                    $t->python = [(int) $r['py'][0], (int) $r['py'][1]];
                }
                $first = false;
            } elseif (isset($r['pool'])) {
                $t->pool['p' . $r['pool']] = $r;
            } else {
                $t->queues[$r['token'] . ' ' . $r['url']][] = $r;
            }
        }
        fclose($fh);
        return $first ? null : $t;
    }

    public function fetch(string $url, array $plain, array $key, string $token, mixed $timeout): string
    {
        $this->asked++;
        $id = jackPlanTokenId($token) . " $url";
        if (empty($this->queues[$id])) {
            $this->missing[$url] = ($this->missing[$url] ?? 0) + 1;
            throw new JackPlanApiError('not on the tape');
        }
        $r = array_shift($this->queues[$id]);
        if (isset($r['http']) || isset($r['error'])) {
            throw new JackPlanApiError('the tape: ' . ($r['http'] ?? $r['error']));
        }
        if (!empty($r['crash'])) {
            jpCrash('the tape: a read that failed');
        }
        return isset($r['body64']) ? (string) base64_decode($r['body64'], true) : (string) $r['body'];
    }

    /** @return array<string, int> what Python asked and this side didn't: URL => how often */
    public function left(): array
    {
        $out = [];
        foreach ($this->queues as $q) {
            foreach ($q as $r) {
                $out[$r['url']] = ($out[$r['url']] ?? 0) + 1;
            }
        }
        return $out;
    }

    public function now(): float
    {
        return $this->clock;
    }

    public function poolUsage(string $path): array
    {
        $r = $this->pool["p$path"] ?? null;
        if ($r === null) {
            $this->missing["pool:$path"] = ($this->missing["pool:$path"] ?? 0) + 1;
            jpCrash('the tape has no pool reading');
        }
        if (!empty($r['error'])) {
            jpCrash('the pool could not be read');
        }
        return [(int) $r['total'], (int) $r['free']];
    }
}

// ===================================================================== the pool's numbers (for real)

/** /proc/mounts (MEDIACACHE_MOUNTS in the tests): [[source, mountpoint, fstype]] */
function jackPlanMounts(array $env): array
{
    $text = @file_get_contents(($env['MEDIACACHE_MOUNTS'] ?? '') ?: '/proc/mounts');
    $out = [];
    foreach ($text === false ? [] : explode("\n", $text) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) >= 3) {
            $un = fn (string $s): string => (string) preg_replace_callback('/\\\\([0-7]{3})/', fn (array $m): string => chr((int) octdec($m[1])), $s);
            $out[] = [$un($f[0]), $un($f[1]), $f[2]];
        }
    }
    return $out;
}

/** mount_of(): the longest mountpoint above; among equals the last */
function jackPlanMountOf(string $path, array $mounts): ?array
{
    $p = rtrim($path, '/') ?: '/';
    $best = null;
    foreach ($mounts as $m) {
        $mp = rtrim($m[1], '/') ?: '/';
        if (($p === $mp || str_starts_with($p, rtrim($mp, '/') . '/')) && ($best === null || strlen($mp) >= strlen(rtrim($best[1], '/') ?: '/'))) {
            $best = $m;
        }
    }
    return $best;
}

/** `zfs list -Hp -o used,avail <name>` (MEDIACACHE_ZFS in the tests) */
function jackPlanZfsUsedAvail(string $name, array $env): ?array
{
    [$exit, $out] = run([($env['MEDIACACHE_ZFS'] ?? '') ?: 'zfs', 'list', '-Hp', '-o', 'used,avail', $name], 30);
    $f = preg_split('/\s+/', trim((string) $out), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return $exit === 0 && count($f) === 2 && ctype_digit($f[0]) && ctype_digit($f[1]) ? [(int) $f[0], (int) $f[1]] : null;
}

/** pool_usage(): on ZFS the whole pool (free = the dataset's avail: a quota counts), else statvfs */
function jackPlanPoolUsage(string $path, array $env): array
{
    $p = jpNorm($path);
    $best = null;
    foreach (explode("\n", (string) @file_get_contents(($env['MEDIACACHE_MOUNTS'] ?? '') ?: '/proc/mounts')) as $line) {
        $f = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($f) < 3) {
            continue;
        }
        $mnt = str_replace('\\040', ' ', $f[1]);
        if (($p === $mnt || str_starts_with($p, rtrim($mnt, '/') . '/')) && ($best === null || strlen($mnt) > strlen($best[0]))) {
            $best = [$mnt, $f[0], $f[2]];
        }
    }
    $ds = $best !== null && $best[2] === 'zfs' ? $best[1] : null;
    if ($ds !== null) {
        $pool = jackPlanZfsUsedAvail(explode('/', $ds)[0], $env);
        $own = str_contains($ds, '/') ? jackPlanZfsUsedAvail($ds, $env) : $pool;
        if ($pool !== null && $own !== null && $pool[0] + $pool[1] > 0) {
            return [$pool[0] + $pool[1], min($own[1], $pool[1])];
        }
    }
    $total = @disk_total_space($path);
    $free = @disk_free_space($path);
    if ($total === false || $free === false) {
        jpCrash('statvfs failed');
    }
    return [(int) $total, (int) $free];
}

/** free_percent_after() */
function jackPlanFreeAfter(string $path, int $size, int $delta, JackPlanHost $host): float
{
    [$total, $free] = $host->poolUsage($path);
    return $total === 0 ? 0.0 : max(0.0, ($free + $delta - $size) / $total * 100.0);
}

/** share_mover_mode(): [mode|null, primary] */
function jackPlanShareMode(array $cfg, string $share): array
{
    $read = function (string $file): ?array {
        $text = @file_get_contents($file);
        if ($text === false) {
            return null;
        }
        $vals = [];
        foreach (preg_split('/\r\n|\n|\r|\x0b|\x0c|\x1c|\x1d|\x1e|\x{85}|\x{2028}|\x{2029}/u', mb_scrub($text, 'UTF-8')) ?: [] as $line) {
            $line = jpStrip($line);
            foreach (['shareUseCache', 'shareCachePool', 'shareCachePool2', 'shareFloor', 'shareInclude', 'shareExclude'] as $key) {
                if (str_starts_with($line, "$key=")) {
                    $vals[$key] = trim(jpStrip(explode('=', $line, 2)[1]), '"');
                }
            }
        }
        return $vals;
    };
    if (!is_string($cfg['shares_cfg_dir'])) {
        jpCrash('Path() of no text');
    }
    $dir = jpNorm($cfg['shares_cfg_dir']);
    $vals = $read(jpJoin($dir, "$share.cfg"));
    if (!$vals || !array_key_exists('shareUseCache', $vals)) {
        $defaults = $read(jpJoin(jpParent($dir) === '' ? '.' : jpParent($dir), 'share.cfg'));
        if (!$defaults || !array_key_exists('shareUseCache', $defaults)) {
            return [null, null];
        }
        $vals = $defaults;
    }
    $mode = jpLower($vals['shareUseCache']);
    return [$mode === '' ? null : $mode, ($vals['shareCachePool'] ?? '') !== '' ? $vals['shareCachePool'] : 'cache'];
}

/** ensure_share_root() in a dry run: true = the root is there or would be made, false = this share's files are skipped */
function jackPlanShareRoot(array $cfg, string $cache, string $share, array $env, array &$state): bool
{
    if (array_key_exists("s$share", $state)) {
        return $state["s$share"];
    }
    $root = jpJoin($cache, $share);
    $mounts = jackPlanMounts($env);
    $pool = jackPlanMountOf($cache, $mounts);
    $fs = $pool[2] ?? '?';
    $exists = function (string $name) use ($env): bool {
        [$exit, $out] = run([($env['MEDIACACHE_ZFS'] ?? '') ?: 'zfs', 'list', '-H', '-o', 'name', $name], 60);
        return $exit === 0 && trim((string) $out) === $name;
    };
    if (is_dir($root)) {
        $ok = true;
        if ($fs === 'zfs' && $pool !== null) {
            $own = jackPlanMountOf($root, $mounts);
            if (($own === null || $own[2] !== 'zfs' || rtrim($own[1], '/') !== rtrim($root, '/')) && $exists("$pool[0]/$share")) {
                $ok = false;      // the dataset exists but isn't mounted there
            }
        }
        return $state["s$share"] = $ok;
    }
    if (empty($cfg['create_share_root']) || !in_array($fs, ['zfs', 'btrfs', 'xfs'], true)) {
        return $state["s$share"] = false;
    }
    if ($fs !== 'zfs') {
        return $state["s$share"] = true;
    }
    [$mode, $primary] = jackPlanShareMode($cfg, $share);
    if (!in_array($mode, ['yes', 'prefer', 'only'], true) || $primary !== jpName($cache)) {
        return $state["s$share"] = false;
    }
    return $state["s$share"] = !$exists("$pool[0]/$share");
}

// ===================================================================== where things lie (Locations)

/** Docker path → host path → relative to the share level; finding it on the pool and the array view */
final class JackPlanLocations
{
    public string $cache;

    public string $array;

    public string $user;

    private array $libraryRoots = [];

    /** @param list<array{string, string}> $mappings [docker, host] */
    public function __construct(array $cfg, private array $mappings, private array $skip = [])
    {
        $this->cache = jpNorm($cfg['cache_path']);
        $this->array = jpNorm($cfg['array_path']);
        $this->user = jpNorm($cfg['user_path']);
        foreach ($mappings as [, $host]) {
            $rel = $this->toRel($host);
            if ($rel !== null) {
                $this->libraryRoots["r$rel"] = true;
            }
        }
    }

    /** The longest mapping that fits, at a path's border only; a line with an empty host path counts too: when it is the
     *  longest, the folder is deliberately not cached (null), under a broader mapping as well (Python's to_host(), 2026-11-08) */
    public function toHost(string $docker): ?string
    {
        $best = $repl = null;
        foreach ($this->mappings as [$d, $h]) {
            if (($docker === $d || str_starts_with($docker, "$d/")) && strlen($d) > strlen($best ?? '')) {
                $best = $d;
                $repl = $h;
            }
        }
        if ($this->skipped($docker, strlen($best ?? ''))) {
            return null;
        }
        return $best === null ? null : $repl . substr($docker, strlen($best));
    }

    /** Host path → relative to the share level; a path with `..` never leaves the share: null */
    public function toRel(string $host): ?string
    {
        foreach ([$this->user, $this->array, $this->cache] as $root) {
            $rel = jpRelative($host, $root);
            if ($rel === null) {
                continue;
            }
            if (in_array('..', explode('/', $rel), true)) {
                return null;
            }
            return $rel !== '' ? $rel : null;
        }
        return null;
    }

    /** An empty line covers the path (longer than `$longerThan`: longer than the best mapping) */
    public function skipped(string $docker, int $longerThan = -1): bool
    {
        foreach ($this->skip as $d) {
            if (($docker === $d || str_starts_with($docker, "$d/")) && strlen($d) > $longerThan) {
                return true;
            }
        }
        return false;
    }

    public function relFromDocker(string $docker): ?string
    {
        $host = $this->toHost($docker);
        return $host === null ? null : $this->toRel($host);
    }

    public function onCache(string $rel): string
    {
        return jpJoin($this->cache, $rel);
    }

    public function onArray(string $rel): string
    {
        return jpJoin($this->array, $rel);
    }

    public function isLibraryRoot(string $rel): bool
    {
        return $rel === '' || !str_contains($rel, '/') || isset($this->libraryRoots["r$rel"]);
    }

    /** Files under a folder: the union of the array view and the pool, sorted as Paths */
    public function listFiles(string $relDir, bool $recursive): array
    {
        $found = [];
        foreach ([$this->array, $this->cache] as $root) {
            $base = jpJoin($root, $relDir);
            if (!is_dir($base)) {
                continue;
            }
            foreach ($recursive ? self::walk($base) : self::entries($base, true) as $f) {
                if (is_file($f)) {
                    $found[(string) jpRelative($f, $root)] = true;
                }
            }
        }
        return jpSortPaths(array_map('strval', array_keys($found)));
    }

    /** iterdir(); an unreadable folder raises in Python only here, a walk passes over it */
    private static function entries(string $dir, bool $strict): array
    {
        $names = @scandir($dir, SCANDIR_SORT_NONE);
        if ($names === false) {
            if ($strict) {
                jpCrash('a folder that can\'t be read');
            }
            return [];
        }
        $out = [];
        foreach ($names as $n) {
            if ($n !== '.' && $n !== '..') {
                $out[] = "$dir/$n";
            }
        }
        return $out;
    }

    /** rglob("*"): everything below, never through a symlink to a folder */
    private static function walk(string $dir): array
    {
        $out = [];
        foreach (self::entries($dir, false) as $p) {
            $out[] = $p;
            if (is_dir($p) && !is_link($p)) {
                foreach (self::walk($p) as $x) {
                    $out[] = $x;
                }
            }
        }
        return $out;
    }
}

// ===================================================================== the plan

/** OnDeckFile: `ranks` per person how far ahead (0 started, 1 next up, n the n-th episode ahead); several may want a file */
final class JackPlanFile
{
    public array $ranks;

    public function __construct(public string $rel, public int $size, public bool $onCache, public ?string $user, public string $source, public mixed $title, int $ahead, public string $item)
    {
        $this->ranks = $user === null ? [] : [$user => $ahead];
    }

    public function ahead(): int
    {
        return $this->ranks ? min($this->ranks) : 0;
    }
}

/** Group: all files of one item go together */
final class JackPlanGroup
{
    public int $size;

    /** @param list<JackPlanFile> $files */
    public function __construct(public array $files, public string $category, public string $chain, public string $source, public mixed $series, public mixed $title)
    {
        $this->size = array_sum(array_map(fn (JackPlanFile $f): int => $f->size, $files));
    }

    public function key(): string
    {
        $rels = array_map(fn (JackPlanFile $f): string => $f->rel, $this->files);
        sort($rels, SORT_STRING);
        return implode("\0", $rels);
    }
}

/** UserPlan: one person, over all servers with their name */
final class JackPlanUser
{
    public array $uids = [];

    public array $servers = [];

    public array $chains = [];

    public array $seen = [];

    public array $movies = [];

    public array $series = [];

    public array $selected = [];

    public array $skipped = [];

    public int $bytes = 0;

    public int $movieBytes = 0;

    public int $seriesBytes = 0;

    public ?float $budget = null;

    public bool $exhausted = true;

    public function __construct(public string $key, public mixed $name, mixed $uid = '', string $server = '')
    {
        if (jpTrue($uid)) {
            $this->uids[] = $uid;
        }
        if ($server !== '') {
            $this->servers[] = $server;
        }
    }

    public function hasCandidates(): bool
    {
        return $this->movies || $this->series;
    }
}

/** Planner: the candidates per person (resumed, next up, the next episodes, favourites), the budget, the order */
final class JackPlanner
{
    /** @var array<string, JackPlanFile> «f<rel>» => the file */
    public array $files = [];

    /** @var array<string, JackPlanUser> */
    public array $users = [];

    public int $errors = 0;

    public bool $budgetMode;

    private ?string $planning = null;

    /** The real run in PHP (jack-run.php): Python's log lines of the plan, at its places and levels (they count as its warnings and
     *  errors); null: silent, as in the shadow. fn (string $level, string $message): void */
    public ?Closure $say = null;

    public function __construct(private array $cfg, private JackPlanHost $host, private string $plexTv = JACK_PLAN_PLEX_TV)
    {
        $this->budgetMode = (int) $cfg['cache_budget_bytes'] > 0;
    }

    private function say(string $level, string $message): void
    {
        if ($this->say !== null) {
            ($this->say)($level, $message);
        }
    }

    /** files_for_item() */
    private function filesForItem(JackPlanLocations $loc, stdClass $item): array
    {
        $raw = jpOr(jpGet($item, 'Path'), '');
        if (!jpTrue($raw) || jpGet($item, 'LocationType') === 'Virtual') {
            return [];
        }
        if (!is_string($raw)) {
            jpCrash('a Path that is no text');
        }
        $rel = $loc->relFromDocker($raw);
        if ($rel === null) {
            if ($loc->skipped($raw)) {
                $this->say('DEBUG', "Ordner absichtlich nicht gecacht: $raw");
            } else {
                $this->say('WARNING', "Kein Path-Mapping für $raw – Item übersprungen (Mapping in der Config prüfen)");
            }
            return [];     // deliberately not cached (empty host path), or no mapping: skipped
        }
        $existsDir = is_dir($loc->onArray($rel)) || is_dir($loc->onCache($rel));
        $existsFile = is_file($loc->onArray($rel)) || is_file($loc->onCache($rel));
        if (!$existsDir && !$existsFile) {
            $this->say('WARNING', "Datei nicht gefunden: $rel (Mapping oder array_path/cache_path prüfen)");
            return [];
        }
        // with `themes` the theme.mp3 in the item's folder doesn't travel with the item: it belongs to the collector
        $withoutTheme = fn (array $files, string $folder): array => array_values(array_filter($files,
            fn (string $f): bool => !($this->cfg['themes'] === true && jpName($f) === JACK_PLAN_THEME && jpParent($f) === $folder)));
        if ($existsDir) {
            return $withoutTheme($loc->listFiles($rel, true), $rel);    // a DVD/BluRay folder: itself, never its parent
        }
        $parent = jpParent($rel);
        if (jpGet($item, 'Type') === 'Movie' && $this->cfg['movie_mode'] === 'folder' && !$loc->isLibraryRoot($parent)) {
            return $withoutTheme($loc->listFiles($parent, true), $parent);
        }
        // episodes, flat films, movie_mode=file: only files of the same base name (mkv, srt, nfo …), Jellyfin's trickplay folder
        $stem = jpStem(jpName($rel));
        $same = array_values(array_filter($loc->listFiles($parent, false), fn (string $f): bool => str_starts_with(jpName($f), $stem)));
        return array_merge($same, $loc->listFiles(jpJoin($parent, "$stem.trickplay"), true));
    }

    /** make_group(); $ahead: 0 started, 1 next up, n the n-th ahead */
    private function makeGroup(JackPlanLocations $loc, stdClass $item, string $category, string $chain, string $source, mixed $title, mixed $series = null, int $ahead = 0): ?JackPlanGroup
    {
        if (jpTrue($series) && !is_string($series)) {
            jpCrash("series + ' – ' of no text");
        }
        $what = jpTrue($series) ? jpStr($series) . ' – ' . jpStr($title) : $title;
        $files = [];
        foreach ($this->filesForItem($loc, $item) as $rel) {
            $cacheP = $loc->onCache($rel);
            $on = file_exists($cacheP);
            $size = @filesize($on ? $cacheP : $loc->onArray($rel));
            if ($size === false) {
                $this->say('WARNING', 'Datei nicht lesbar, übersprungen: ' . ($on ? $cacheP : $loc->onArray($rel)));
                continue;
            }
            $files[] = new JackPlanFile($rel, $size, $on, $this->planning, JACK_PLAN_SOURCE_CODES[$source] ?? 'other', $what, $ahead,
                jpStr(jpGet($item, 'Id')) . '|' . jpStr(jpGet($item, 'Path')));
        }
        return $files ? new JackPlanGroup($files, $category, $chain, $source, $series, $title) : null;
    }

    /** @return array{mixed, mixed} */
    private static function epKey(mixed $ep): array
    {
        $s = jpGet($ep, 'ParentIndexNumber');
        $e = jpGet($ep, 'IndexNumber');
        return [$s ?? -1, $e ?? -1];
    }

    /** ep_label(): «S02E03 Title», or only the title when the server gives no numbers */
    private static function epLabel(mixed $ep): mixed
    {
        $s = jpGet($ep, 'ParentIndexNumber');
        $e = jpGet($ep, 'IndexNumber');
        $name = jpGet($ep, 'Name', '?');
        return $s !== null && $e !== null ? 'S' . jpTwoDigits($s) . 'E' . jpTwoDigits($e) . ' ' . jpStr($name) : $name;
    }

    private function seriesEpisodes(JackPlanServer $api, mixed $uid, mixed $seriesId): array
    {
        $eps = $api->userItems($uid, ['ParentId' => $seriesId, 'Recursive' => 'true', 'IncludeItemTypes' => 'Episode', 'SortBy' => 'ParentIndexNumber,IndexNumber',
            'SortOrder' => 'Ascending', 'Fields' => 'Path,ParentIndexNumber,IndexNumber,LocationType']);
        $eps = array_values(array_filter($eps, fn (mixed $e): bool => jpTrue(jpGet($e, 'Path')) && jpGet($e, 'LocationType') !== 'Virtual'));
        usort($eps, fn (mixed $a, mixed $b): int => jpCmpTuple(self::epKey($a), self::epKey($b)));
        return $eps;
    }

    /** How many episodes per series at most: count mode number_episodes (0 none), budget mode max_episodes_per_series (null: the budget decides) */
    private function episodeCap(): ?int
    {
        if ($this->budgetMode) {
            $n = (int) $this->cfg['max_episodes_per_series'];
            return $n > 0 ? $n : null;
        }
        $n = jpInt($this->cfg['number_episodes']);
        return $n > 0 ? $n : 0;
    }

    private function nextEpisodesAfter(JackPlanServer $api, mixed $uid, stdClass $current): array
    {
        $cap = $this->episodeCap();
        if ($cap === 0 || !jpTrue(jpGet($current, 'SeriesId'))) {
            return [];
        }
        $cur = self::epKey($current);
        $after = array_values(array_filter($this->seriesEpisodes($api, $uid, $current->SeriesId),
            fn (mixed $e): bool => jpCmpTuple(self::epKey($e), $cur) > 0 && !jpEq(jpGet($e, 'Id'), jpGet($current, 'Id'))));
        return $cap !== null ? array_slice($after, 0, $cap) : $after;
    }

    private function nextUnplayed(JackPlanServer $api, mixed $uid, mixed $seriesId): array
    {
        $cap = $this->episodeCap();
        if ($cap === 0) {
            return [];
        }
        $unplayed = array_values(array_filter($this->seriesEpisodes($api, $uid, $seriesId),
            fn (mixed $e): bool => !jpTrue(jpGet(jpOr(jpGet($e, 'UserData'), new stdClass()), 'Played'))));
        return $cap !== null ? array_slice($unplayed, 0, $cap) : $unplayed;
    }

    /** The chain of one series from an episode: it, then the next ones in order */
    private function chainFrom(JackPlanServer $api, JackPlanLocations $loc, stdClass $item, mixed $uid, string $source, mixed $sid, mixed $sname, int $first, string $server = ''): array
    {
        $chain = [];
        $g = $this->makeGroup($loc, $item, 'series', 'series:' . jpStr($sid), $source, self::epLabel($item), $sname, $first);
        if ($g) {
            $chain[] = $g;
        }
        try {
            foreach ($this->nextEpisodesAfter($api, $uid, $item) as $i => $ep) {
                $g = $this->makeGroup($loc, $ep, 'series', 'series:' . jpStr($sid), 'Nächste Folge', self::epLabel($ep), $sname, $first + $i + 1);
                if ($g) {
                    $chain[] = $g;
                }
            }
        } catch (JackPlanApiError $e) {
            $this->errors++;
            $this->say('ERROR', "[$server] Folgen von «" . jpStr($sname) . "» nicht abrufbar: {$e->getMessage()}");
        }
        return $chain;
    }

    /** plan_user() */
    private function planUser(JackPlanServer $api, JackPlanLocations $loc, string $name, mixed $uid, mixed $uname): void
    {
        $key = jpPersonKey($uname);
        $up = $this->users[$key] ?? null;
        if ($up === null) {
            $up = new JackPlanUser($key, $uname, $uid, $name);
        } else {
            $up->uids[] = $uid;
            if (!in_array($name, $up->servers, true)) {
                $up->servers[] = $name;
            }
        }
        $this->planning = $up->key;
        $resume = [];
        try {
            foreach (['Movie' => 'max_resume_movies', 'Episode' => 'max_resume_series'] as $kind => $setting) {
                $limit = (int) $this->cfg[$setting];
                if ($limit > 0) {
                    foreach ($api->resumeItems($uid, ['MediaTypes' => 'Video', 'IncludeItemTypes' => $kind, 'Limit' => $limit, 'Fields' => JACK_PLAN_FIELDS]) as $x) {
                        $resume[] = $x;
                    }
                }
            }
        } catch (JackPlanApiError $e) {
            $this->errors++;
            $this->say('ERROR', "[$name] Resume-Liste von " . jpStr($uname) . " nicht abrufbar: {$e->getMessage()}");
            return;
        }
        $chains = [];
        $seenSeries = [];
        foreach ($resume as $item) {
            $type = jpGet($item, 'Type');
            if ($type === 'Movie') {
                $g = $this->makeGroup($loc, $item, 'movie', 'movie:' . jpStr(jpGet($item, 'Id')), 'Weiterschauen', jpGet($item, 'Name', '?'));
                if ($g && !isset($up->seen[$g->key()])) {
                    $up->seen[$g->key()] = true;
                    $up->movies[] = $g;
                }
                continue;
            }
            if ($type !== 'Episode') {
                continue;
            }
            $sname = jpGet($item, 'SeriesName', '?');
            $sid = jpOr(jpGet($item, 'SeriesId'), 'ep:' . jpStr(jpGet($item, 'Id')));
            if (isset($seenSeries[jpKey($sid)])) {
                continue;
            }
            $seenSeries[jpKey($sid)] = true;
            if ($chain = $this->chainFrom($api, $loc, $item, $uid, 'Weiterschauen', $sid, $sname, 0, $name)) {
                $chains[] = $chain;
            }
        }
        if (jpTrue($this->cfg['use_next_up'])) {
            try {
                $nextUp = $api->nextUp($uid, ['Limit' => (int) $this->cfg['max_resume_series'], 'Fields' => JACK_PLAN_FIELDS]);
            } catch (JackPlanApiError $e) {
                $this->errors++;
                $this->say('ERROR', "[$name] NextUp-Liste von " . jpStr($uname) . " nicht abrufbar: {$e->getMessage()}");
                $nextUp = [];
            }
            foreach ($nextUp as $item) {
                if (jpGet($item, 'Type') !== 'Episode') {
                    continue;
                }
                $sname = jpGet($item, 'SeriesName', '?');
                $sid = jpOr(jpGet($item, 'SeriesId'), 'ep:' . jpStr(jpGet($item, 'Id')));
                if (isset($seenSeries[jpKey($sid)])) {
                    continue;
                }
                $seenSeries[jpKey($sid)] = true;
                if ($chain = $this->chainFrom($api, $loc, $item, $uid, 'Als Nächstes', $sid, $sname, 1, $name)) {
                    $chains[] = $chain;
                }
            }
        }
        $maxFav = jpInt($this->cfg['max_favorite_series']);
        if ($maxFav > 0) {
            try {
                $favs = $api->userItems($uid, ['Recursive' => 'true', 'IncludeItemTypes' => 'Series', 'Filters' => 'IsFavorite', 'Limit' => $maxFav, 'Fields' => 'Path']);
            } catch (JackPlanApiError $e) {
                $this->errors++;
                $this->say('ERROR', "[$name] Favoriten von " . jpStr($uname) . " nicht abrufbar: {$e->getMessage()}");
                $favs = [];
            }
            foreach ($favs as $series) {
                $sid = jpGet($series, 'Id');
                if (isset($seenSeries[jpKey($sid)])) {
                    continue;
                }
                $seenSeries[jpKey($sid)] = true;
                $chain = [];
                try {
                    foreach ($this->nextUnplayed($api, $uid, $sid) as $i => $ep) {
                        $g = $this->makeGroup($loc, $ep, 'series', 'series:' . jpStr($sid), 'Favorit', self::epLabel($ep), jpGet($series, 'Name', '?'), $i + 1);
                        if ($g) {
                            $chain[] = $g;
                        }
                    }
                } catch (JackPlanApiError $e) {
                    $this->errors++;
                    $this->say('ERROR', "[$name] Folgen von Favorit «" . jpStr(jpGet($series, 'Name', '?')) . "» nicht abrufbar: {$e->getMessage()}");
                }
                if ($chain) {
                    $chains[] = $chain;
                }
            }
        }
        // round robin over all series of all the person's servers: one episode per round; an episode two servers name once
        foreach ($chains as $c) {
            $up->chains[] = $c;
        }
        $up->series = [];
        $seen = [];
        foreach ($up->movies as $g) {
            $seen[$g->key()] = true;
        }
        for ($pos = 0, $more = true; $more; $pos++) {
            $more = false;
            foreach ($up->chains as $c) {
                if (isset($c[$pos])) {
                    $more = true;
                    if (!isset($seen[$c[$pos]->key()])) {
                        $seen[$c[$pos]->key()] = true;
                        $up->series[] = $c[$pos];
                    }
                }
            }
        }
        $this->users[$up->key] = $up;
    }

    /** plan_instance() */
    private function planInstance(array $inst, array $uids): void
    {
        $api = JackPlanServer::forInstance($inst, $this->cfg['api_timeout'], $this->host, $this->plexTv);
        $loc = new JackPlanLocations($this->cfg, $inst['_mappings'], $inst['_skip']);
        $name = $inst['servername'];
        if (!$uids) {
            try {
                $users = $api->users();
            } catch (JackPlanApiError $e) {
                $this->errors++;
                $this->say('ERROR', '[' . jpStr($name) . "] Benutzerliste nicht abrufbar: {$e->getMessage()}");
                return;
            }
            if ($api->usersIncomplete || !$users) {
                $this->errors++;    // no users, or Plex's shared ones missing: never «nobody watches anything»
                $this->say('ERROR', '[' . jpStr($name) . '] Benutzerliste unvollständig – Cleanup wird übersprungen');
            }
            foreach ($users as $u) {
                $id = self::userId($u);
                $uids[] = [$id, jpGet($u, 'Name', $id)];
            }
        }
        foreach ($uids as [$uid, $uname]) {
            $this->planUser($api, $loc, jpStr($name), $uid, $uname);
        }
    }

    /** u["Id"] */
    private static function userId(mixed $u): mixed
    {
        if (!$u instanceof stdClass || !property_exists($u, 'Id')) {
            jpCrash('a user without Id');
        }
        return $u->Id;
    }

    /** select(): fills a person's budget, films and series in the share by bytes (soft). What no longer fits stops its chain: never episode 5 without 4 */
    public static function select(JackPlanUser $up, float $budget, float $share): void
    {
        $queues = ['movie' => $up->movies, 'series' => $up->series];
        $idx = ['movie' => 0, 'series' => 0];
        $used = ['movie' => 0, 'series' => 0];
        $target = ['movie' => $budget * $share, 'series' => $budget * (1.0 - $share)];
        $stopped = [];
        $up->selected = $up->skipped = [];
        $up->bytes = 0;
        $full = [];
        $relaxed = false;
        $fraction = function (string $c) use (&$used, $target): float {
            return $target[$c] > 0 ? $used[$c] / $target[$c] : INF;
        };
        while (true) {
            $avail = [];
            foreach (['movie', 'series'] as $c) {
                if ($idx[$c] < count($queues[$c]) && !isset($full[$c])) {
                    $avail[] = $c;
                }
            }
            if (!$avail) {
                if (!$full) {
                    break;
                }
                $full = [];
                $relaxed = true;
                continue;
            }
            $cat = $avail[0];
            foreach ($avail as $c) {
                if ($fraction($c) < $fraction($cat)) {
                    $cat = $c;
                }
            }
            $g = $queues[$cat][$idx[$cat]];
            $other = $cat === 'movie' ? 'series' : 'movie';
            if (!$relaxed && !isset($stopped[$g->chain]) && $used[$cat] + $g->size > $target[$cat] && $idx[$other] < count($queues[$other])) {
                $full[$cat] = true;
                continue;
            }
            $idx[$cat]++;
            if (isset($stopped[$g->chain])) {
                $up->skipped[] = $g;
                continue;
            }
            if ($up->bytes + $g->size > $budget) {
                $stopped[$g->chain] = true;
                $up->skipped[] = $g;
                continue;
            }
            $up->selected[] = $g;
            $up->bytes += $g->size;
            $used[$cat] += $g->size;
        }
        $up->movieBytes = $used['movie'];
        $up->seriesBytes = $used['series'];
        $up->exhausted = !$up->skipped;
        $up->budget = $budget;
    }

    /** apply_budget(): fixed budgets first, the rest evenly; what one doesn't need flows to the others */
    public function applyBudget(): void
    {
        $total = (int) $this->cfg['cache_budget_bytes'];
        $share = (float) $this->cfg['movie_share_percent'] / 100.0;
        $budgets = $this->cfg['user_budgets'];
        $active = array_values(array_filter($this->users, fn (JackPlanUser $u): bool => $u->hasCandidates()));
        $explicit = [];
        foreach ($active as $u) {
            foreach ($u->uids as $i) {
                if (is_string($i) && array_key_exists($i, $budgets)) {
                    $explicit[$u->key] = $budgets[$i];
                    break;
                }
            }
        }
        if ($total - array_sum($explicit) < 0) {
            $this->say('WARNING', 'Feste Benutzer-Budgets (' . jpHuman(array_sum($explicit)) . ') übersteigen cache_budget (' . jpHuman($total) . ')');
        }
        $pool = max(0, $total - array_sum($explicit));
        $flexible = array_values(array_filter($active, fn (JackPlanUser $u): bool => !isset($explicit[$u->key])));
        $fixed = [];
        $cap = 0.0;
        for ($r = 0, $rounds = count($flexible) + 1; $r < $rounds; $r++) {
            $open = array_values(array_filter($flexible, fn (JackPlanUser $u): bool => !array_key_exists($u->key, $fixed)));
            if (!$open) {
                break;
            }
            $cap = ($pool - array_sum($fixed)) / count($open);
            $changed = false;
            foreach ($open as $u) {
                self::select($u, (float) $cap, $share);
                if ($u->exhausted && $u->bytes < $cap) {
                    $fixed[$u->key] = $u->bytes;
                    $changed = true;
                }
            }
            if (!$changed) {
                break;
            }
        }
        foreach ($flexible as $u) {
            if (!array_key_exists($u->key, $fixed)) {
                self::select($u, (float) $cap, $share);
            }
        }
        foreach ($active as $u) {
            if (isset($explicit[$u->key])) {
                self::select($u, (float) $explicit[$u->key], $share);
            }
        }
        foreach ($this->say !== null ? $active : [] as $u) {
            $skipped = array_sum(array_map(fn (JackPlanGroup $g): int => $g->size, $u->skipped));
            $this->say('INFO', 'Budget ' . jpStr($u->name) . ': ' . jpHuman($u->bytes) . ' von ' . jpHuman((float) $u->budget) . ' (Filme ' . jpHuman($u->movieBytes)
                . ', Serien ' . jpHuman($u->seriesBytes) . ')' . ($u->skipped ? ' – nicht im Budget: ' . count($u->skipped) . ' Einträge, ' . jpHuman($skipped) : ''));
        }
    }

    /** run(): the plan, in the order of the fill */
    public function run(): array
    {
        $wanted = $this->cfg['valid_users'];
        foreach ($this->cfg['instances'] as $inst) {
            $uids = [];
            if ($wanted) {
                $known = [];
                try {
                    $api = JackPlanServer::forInstance($inst, $this->cfg['api_timeout'], $this->host, $this->plexTv);
                    foreach ($api->users() as $u) {
                        $id = self::userId($u);
                        $known[jpKey($id)] = jpGet($u, 'Name', $id);
                    }
                } catch (JackPlanApiError $e) {
                    $this->errors++;
                    $this->say('ERROR', '[' . jpStr($inst['servername']) . "] nicht erreichbar: {$e->getMessage()}");
                    continue;
                }
                if ($api->usersIncomplete) {
                    $this->errors++;     // a chosen user may be one plex.tv didn't give: never «nobody of them is here»
                    $this->say('ERROR', '[' . jpStr($inst['servername']) . '] Benutzerliste unvollständig – Cleanup wird übersprungen');
                }
                foreach ($wanted as $u) {
                    if (array_key_exists("s:$u", $known)) {
                        $uids[] = [$u, $known["s:$u"]];
                    }
                }
                if (!$uids) {
                    $this->say('WARNING', '[' . jpStr($inst['servername']) . '] keiner der konfigurierten Benutzer existiert auf dieser Instanz');
                    continue;
                }
            }
            $this->planInstance($inst, $uids);
        }
        if ($this->budgetMode) {
            $this->applyBudget();
        } else {
            foreach ($this->users as $u) {
                $u->selected = array_merge($u->movies, $u->series);
            }
        }
        foreach ($this->users as $u) {
            foreach ($u->selected as $g) {
                foreach ($g->files as $f) {
                    $first = $this->files["f$f->rel"] ?? null;
                    if ($first === null) {
                        $this->files["f$f->rel"] = $f;
                    } elseif ($first !== $f) {
                        foreach ($f->ranks as $k => $a) {
                            $first->ranks[$k] = min($a, $first->ranks[$k] ?? $a);
                        }
                    }
                }
            }
        }
        if ($this->budgetMode && $this->say !== null) {
            $this->say('INFO', 'Budget gesamt: ' . jpHuman(array_sum(array_map(fn (JackPlanFile $f): int => $f->size, $this->files))) . ' von '
                . jpHuman((int) $this->cfg['cache_budget_bytes']) . ' für ' . count(array_filter($this->users, fn (JackPlanUser $u): bool => $u->hasCandidates())) . ' aktive Benutzer');
        }
        return $this->fillOrder();
    }

    /** not_in_budget(): what waits beyond the budget, each entry once, none another's budget takes along; `persons` per person. null outside the budget mode */
    public function notInBudget(): ?array
    {
        if (!$this->budgetMode) {
            return null;
        }
        $seen = [];
        $out = ['items' => 0, 'films' => 0, 'episodes' => 0, 'bytes' => 0];
        $persons = [];
        foreach ($this->users as $u) {
            $mine = [];
            foreach ($u->skipped as $g) {
                $key = $g->key();
                $taken = false;
                foreach ($g->files as $f) {
                    $taken = $taken || isset($this->files["f$f->rel"]);
                }
                if (!$g->files || isset($mine[$key]) || $taken) {
                    continue;
                }
                $mine[$key] = true;
                $kind = $g->category === 'movie' ? 'films' : 'episodes';
                $persons[$u->key] ??= ['name' => $u->name, 'items' => 0, 'films' => 0, 'episodes' => 0, 'bytes' => 0];
                $persons[$u->key]['items']++;
                $persons[$u->key][$kind]++;
                $persons[$u->key]['bytes'] += $g->size;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out['items']++;
                $out[$kind]++;
                $out['bytes'] += $g->size;
            }
        }
        $out['persons'] = array_values($persons);
        return $out;
    }

    /** fill_order(): round robin over the persons by their next entry, per person by rank; an entry several want once, at its earliest place */
    public function fillOrder(): array
    {
        $lists = [];
        foreach ($this->users as $u) {
            $groups = [];
            foreach ($u->selected as $i => $g) {
                if ($g->files) {
                    $groups[] = [min(array_map(fn (JackPlanFile $f): int => $f->ranks[$u->key] ?? 0, $g->files)), $i, $g];
                }
            }
            usort($groups, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $lists[] = array_map(fn (array $t): JackPlanGroup => $t[2], $groups);
        }
        $order = $placed = [];
        $rounds = $lists ? max(array_map('count', $lists)) : 0;
        for ($r = 0; $r < $rounds; $r++) {
            foreach ($lists as $l) {
                if (isset($l[$r])) {
                    $fs = $l[$r]->files;
                    usort($fs, fn (JackPlanFile $a, JackPlanFile $b): int => jpCmp($a->rel, $b->rel));
                    foreach ($fs as $f) {
                        $canon = $this->files["f$f->rel"] ?? null;
                        if ($canon !== null && !isset($placed[spl_object_id($canon)])) {
                            $placed[spl_object_id($canon)] = true;
                            $order[] = $canon;
                        }
                    }
                }
            }
        }
        foreach ($this->files as $f) {
            if (!isset($placed[spl_object_id($f)])) {
                $order[] = $f;
            }
        }
        return $order;
    }

    /** names(): who wants a file, whoever watches it soonest first */
    public function names(JackPlanFile $f): string
    {
        $name = function (string $k): mixed {
            return isset($this->users[$k]) ? $this->users[$k]->name : $k;
        };
        $lower = function (string $k) use ($name): string {
            $n = $name($k);
            if (!is_string($n)) {
                jpCrash('.lower() of no text');
            }
            return jpLower($n);
        };
        $keys = array_map('strval', array_keys($f->ranks));
        usort($keys, fn (string $a, string $b): int => ($f->ranks[$a] <=> $f->ranks[$b]) ?: jpCmp($lower($a), $lower($b)));
        return implode(', ', array_map(fn (string $k): string => jpStr($name($k)), $keys));
    }
}

/**
 * give_back_order(): in which order files go back when he is above his limit: furthest ahead first (what someone started last);
 * at the same rank first from whoever has most on the pool right now (counted anew after each); an entry several want at its nearest
 * rank, counting for each. The files of one item go together.
 *
 * @param list<JackPlanFile> $kept
 * @return list<JackPlanFile>
 */
function jackPlanGiveBackOrder(array $kept): array
{
    $items = $prepared = [];
    foreach ($kept as $f) {
        $items["i$f->item"][] = $f;
        foreach ($f->ranks as $k => $a) {
            $prepared[$k] = ($prepared[$k] ?? 0) + $f->size;
        }
    }
    $byAhead = [];
    foreach ($items as $fs) {
        $ranks = [];
        foreach ($fs as $f) {
            foreach ($f->ranks as $k => $a) {
                $ranks[$k] = min($a, $ranks[$k] ?? $a);
            }
        }
        $last = '';
        foreach ($fs as $f) {
            $last = jpCmp($f->rel, $last) > 0 ? $f->rel : $last;
        }
        $byAhead[$ranks ? min($ranks) : 0][] = [$last, $fs, $ranks];
    }
    krsort($byAhead);
    $out = [];
    foreach ($byAhead as $ahead => $pending) {
        usort($pending, fn (array $a, array $b): int => jpCmp($b[0], $a[0]));     // stable, as sorted(reverse=True)
        while ($pending) {
            $best = 0;
            $bestHas = -INF;
            foreach ($pending as $i => $t) {
                $has = 0;
                $any = false;
                foreach ($t[2] as $k => $a) {
                    if ($a === $ahead) {
                        $has = $any ? max($has, $prepared[$k] ?? 0) : ($prepared[$k] ?? 0);
                        $any = true;
                    }
                }
                if ($has > $bestHas) {
                    $best = $i;
                    $bestHas = $has;
                }
            }
            $fs = array_splice($pending, $best, 1)[0][1];
            usort($fs, fn (JackPlanFile $a, JackPlanFile $b): int => jpCmp($b->rel, $a->rel));
            foreach ($fs as $f) {
                $out[] = $f;
                foreach ($f->ranks as $k => $a) {
                    $prepared[$k] = ($prepared[$k] ?? 0) - $f->size;
                }
            }
        }
    }
    return $out;
}

/** _exclude_sections(): every entry of his list, comments out; read_text() of no UTF-8 raises */
function jackPlanReadList(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $text = (string) @file_get_contents($file);
    if (!mb_check_encoding($text, 'UTF-8')) {
        jpCrash('the list: no UTF-8');
    }
    $out = [];
    foreach (preg_split('/\r\n|\n|\r|\x0b|\x0c|\x1c|\x1d|\x1e|\x{85}|\x{2028}|\x{2029}/u', $text) ?: [] as $line) {
        $line = jpStrip($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $out["e$line"] = $line;
        }
    }
    return array_values($out);
}

/**
 * collect_sessions(): what plays right now — [rels «r<rel>» => rel, names (basename => n), ok]; a server that doesn't answer: not ok.
 * $say: Python's log lines (the real run in PHP, jack-run.php), null silent.
 *
 * @return array{array<string, string>, array<int|string, int>, bool}
 */
function jackPlanSessions(array $cfg, JackPlanHost $host, string $plexTv, ?Closure $say = null): array
{
    $rels = $names = [];
    $sessionsOk = true;
    foreach ($cfg['instances'] as $inst) {
        $api = JackPlanServer::forInstance($inst, $cfg['api_timeout'], $host, $plexTv);
        $loc = new JackPlanLocations($cfg, $inst['_mappings'], $inst['_skip']);
        try {
            $sessions = $api->sessions();
        } catch (JackPlanApiError $e) {
            $sessionsOk = false;
            $say && $say('WARNING', '[' . jpStr($inst['servername']) . "] Sessions nicht abrufbar: {$e->getMessage()}");
            continue;
        }
        foreach ($sessions as $s) {
            $p = jpGet(jpOr(jpGet($s, 'NowPlayingItem'), new stdClass()), 'Path');
            if (!jpTrue($p)) {
                continue;
            }
            if (!is_string($p)) {
                jpCrash('a Path that is no text');
            }
            $names["n$p"] = str_ends_with($p, '/') ? '' : jpName($p);
            $rel = $loc->relFromDocker($p);
            $who = jpGet($s, 'UserName', '?');
            if ($rel !== null) {
                $rels["r$rel"] = $rel;
                $say && $say('INFO', '[' . jpStr($inst['servername']) . '] Läuft gerade (' . jpStr($who) . "): $rel");
            }
        }
    }
    return [$rels, array_flip(array_map('strval', array_values($names))), $sessionsOk];
}

/**
 * share_mode_ok(): can the move binary bring this share's files back (Cache → Array)? Once per share, said once (the real run).
 * cleanup_tool rsync: always.
 */
function jackPlanShareModeOk(array $cfg, string $share, array &$checked, ?Closure $say): bool
{
    if (array_key_exists("s$share", $checked)) {
        return $checked["s$share"];
    }
    if ($cfg['cleanup_tool'] === 'rsync') {
        return $checked["s$share"] = true;
    }
    [$mode, $primary] = jackPlanShareMode($cfg, $share);
    if ($mode === 'yes') {
        $say && $say('INFO', "Share «{$share}»: shareUseCache=yes (Primary $primary) – Mover Cache → Array – Cleanup über das move-Binary funktioniert");
        return $checked["s$share"] = true;
    }
    if ($mode === null || $mode === 'no') {
        $say && $say('WARNING', "Share «{$share}»: shareUseCache=" . ($mode ?? '?') . ' – ' . ($mode === null
            ? 'keine Share-Konfiguration gefunden – Mover-Richtung unbekannt, Cleanup wird versucht'
            : 'Array only: der reguläre Mover fasst den Share nicht an; ob das move-Binary Pool-Pfade trotzdem aufs Array schiebt, ist ungetestet'));
        return $checked["s$share"] = true;
    }
    $say && $say('ERROR', "Share «{$share}»: shareUseCache=$mode (Primary $primary) – das move-Binary kann diesen Share nicht Cache → Array bringen. "
        . 'Cleanup für diesen Share übersprungen (Alternative: cleanup_tool=rsync).');
    return $checked["s$share"] = false;
}

/**
 * cleanup(): the entries of his list nobody wants any more (not in $current), what goes back first by $backRank, then by path; what
 * plays, a kept theme.mp3 and a share the move binary can't give back stay protected. [cache paths, bytes, protected «p<entry>» => entry,
 * sizes «c<path>» => bytes]
 */
function jackPlanCleanup(array $cfg, JackPlanLocations $loc, array $list, array $current, array $backRank, callable $playing, bool $keepThemes, ?Closure $say = null): array
{
    $protected = $cleanup = $sizes = [];
    $toArray = 0;
    usort($list, function (string $a, string $b) use ($backRank): int {
        return [isset($backRank["c$a"]), $backRank["c$a"] ?? 0] <=> [isset($backRank["c$b"]), $backRank["c$b"] ?? 0] ?: jpCmp($a, $b);
    });
    $checked = [];
    foreach ($list as $p) {
        if (isset($current["c$p"])) {
            continue;
        }
        $path = jpNorm($p);
        if (!file_exists($path)) {
            continue;
        }
        if ($keepThemes && jpName($path) === JACK_PLAN_THEME) {
            $protected["p$p"] = $p;
            continue;
        }
        $rel = jpRelative($p, $loc->cache);
        if ($rel === null) {
            $say && $say('WARNING', "Exclude-Eintrag liegt nicht unter {$loc->cache}, ignoriert: $p");
            continue;
        }
        if ($playing($rel)) {
            $say && $say('INFO', "[BLEIBT: läuft gerade] $rel");
            $protected["p$p"] = $p;
            continue;
        }
        if ($rel === '') {
            jpCrash('rel.parts[0] of the pool itself');
        }
        if (!jackPlanShareModeOk($cfg, explode('/', $rel)[0], $checked, $say)) {
            $protected["p$p"] = $p;
            continue;
        }
        $size = @filesize($path);
        if ($size === false) {
            continue;
        }
        $toArray += $size;
        $cleanup[] = jpJoin($loc->cache, $rel);
        $sizes['c' . jpJoin($loc->cache, $rel)] = $size;
    }
    return [$cleanup, $toArray, $protected, $sizes];
}

/**
 * Everything a run decides before it moves anything, in Runner._execute()'s order: sessions, the plan, what goes back by rank
 * (give_back()), the cleanup's selection («incomplete»: no cleanup, the old list protected, never «nothing watched»). The dry plan
 * (jackPlanDry()) and the real run in PHP (jack-run.php) both act on exactly this; nothing here moves or writes. $say: Python's log
 * lines (the real run), null silent (the shadow).
 *
 * @param array<string, string> $env what Python would find in its environment
 */
function jackPlanDecide(array $cfg, array $env, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV, ?Closure $say = null): array
{
    clearstatcache(true);
    $listFile = jpJoin(($env['MEDIACACHE_DIR'] ?? '') ?: '.', 'mediacache_exclude.txt');
    // collect_sessions(): what plays right now is never touched
    [$rels, $names, $sessionsOk] = jackPlanSessions($cfg, $host, $plexTv, $say);
    $playing = fn (string $rel): bool => isset($rels["r$rel"]) || isset($names[jpName($rel)]);

    $planner = new JackPlanner($cfg, $host, $plexTv);
    $planner->say = $say;
    $files = $planner->run();
    $notInBudget = $planner->notInBudget();
    if ($say) {
        $say('INFO', 'On deck: ' . count($files) . ' Dateien, ' . jpHuman(array_sum(array_map(fn (JackPlanFile $f): int => $f->size, $files)))
            . ' (davon ' . count(array_filter($files, fn (JackPlanFile $f): bool => $f->onCache)) . ' bereits auf dem Cache)');
    }
    $loc = new JackPlanLocations($cfg, $cfg['instances'][0]['_mappings']);
    $current = [];
    foreach ($files as $f) {
        $current['c' . $loc->onCache($f->rel)] = true;
    }
    $incomplete = $planner->errors > 0 || !$sessionsOk;
    $themes = $cfg['themes'] === true;
    $minFree = (float) $cfg['min_free_percent'];
    $watchedOnly = ($env['MEDIACACHE_WATCHED_ONLY'] ?? '') === '1';
    $giveBackFree = ($env['MEDIACACHE_GIVE_BACK_FREE'] ?? '') === '1' && !$watchedOnly && $minFree > 0;
    $keep = jackPlanKeepBytes($env);
    $held = $givenBack = $givenBackBytes = 0;
    $backRank = $givenList = $backWhy = [];

    // give_back(): only in the office; what is watched goes first (the cleanup's bytes count as free), then by rank
    if ($giveBackFree || $watchedOnly) {
        $kept = array_values(array_filter($files, fn (JackPlanFile $f): bool => $f->onCache && file_exists($loc->onCache($f->rel))));
        $held = array_sum(array_map(fn (JackPlanFile $f): int => $f->size, $kept));
        if ($giveBackFree) {
            [$total, $free] = $host->poolUsage($loc->cache);
            if (!$incomplete) {
                foreach (jackPlanReadList($listFile) as $p) {        // cleanup_frees()
                    if (isset($current["c$p"]) || ($themes && jpName(jpNorm($p)) === JACK_PLAN_THEME)) {
                        continue;
                    }
                    $rel = jpRelative($p, $loc->cache);
                    if ($rel === null || $playing($rel)) {
                        continue;
                    }
                    if ($cfg['cleanup_tool'] !== 'rsync') {
                        if ($rel === '') {
                            jpCrash('rel.parts[0] of the pool itself');
                        }
                        if (jackPlanShareMode($cfg, explode('/', $rel)[0])[0] !== 'yes') {
                            continue;
                        }
                    }
                    $size = @filesize(jpNorm($p));
                    if ($size !== false) {
                        $free += $size;
                    }
                }
            }
            $need = 0;
            if ($total > 0 && $free * 100 < $total * $minFree) {
                $need = jpInt(-jpFloorDiv(-$total * $minFree, 100.0)) - $free;
            }
            $why = $need > 0 ? sprintf('Zu wenig frei auf dem Pool: %.1f %% (%s von %s), Minimum %s %% – %s gehen zurück aufs Array (bis %s %% frei), das am weitesten voraus Liegende zuerst',
                $free / $total * 100, jpHuman($free), jpHuman($total), jpFloatG($minFree), jpHuman($need), jpFloatG($minFree)) : '';
            if ($need > 0 && $keep !== null) {
                $room = $held - $keep;
                if ($room <= 0) {
                    $say && $say('INFO', sprintf('Zu wenig frei auf dem Pool (%.1f %%, Minimum %s %%), aber ich halte nur %s – meine Hälfte ist %s: nichts geht zurück',
                        $free / $total * 100, jpFloatG($minFree), jpHuman($held), jpHuman($keep)));
                } elseif ($room < $need) {
                    $why .= ' – höchstens ' . jpHuman($room) . ': meine Hälfte (' . jpHuman($keep) . ') bleibt';
                }
                $need = $room <= 0 ? 0 : min($need, $room);
            }
            if ($need > 0) {
                $say && $say('WARNING', $why);
                $freed = 0;
                foreach (jackPlanGiveBackOrder($kept) as $f) {
                    if ($freed >= $need) {
                        break;
                    }
                    if ($playing($f->rel)) {
                        $say && $say('INFO', "[BLEIBT: läuft gerade] $f->rel");
                        continue;
                    }
                    $p = $loc->onCache($f->rel);
                    unset($current["c$p"]);
                    $freed += $f->size;
                    $held -= $f->size;
                    $givenBack++;
                    $givenBackBytes += $f->size;
                    $entry = ['title' => jpTrue($f->title) ? $f->title : $f->rel, 'ahead' => $f->ahead(), 'who' => $planner->names($f)];
                    $backWhy["c$p"] = $entry;
                    $backRank["c$p"] = count($backRank);
                    if ((!$givenList || !jpEq($givenList[count($givenList) - 1]['title'], $entry['title'])) && count($givenList) < 20) {
                        $givenList[] = $entry;
                    }
                    if ($say) {
                        $a = $f->ahead();
                        $say('INFO', "[ZURÜCK: Rang] $f->rel (" . jpHuman($f->size) . ') – Gebe zurück: ' . jpStr($entry['title']) . ' ('
                            . ($a <= 0 ? 'angefangen' : ($a === 1 ? 'als Nächstes' : "Folge $a voraus")) . ($entry['who'] !== '' ? ", {$entry['who']}" : '') . ')');
                    }
                }
            }
        }
    }

    // the cleanup's selection: entries of the old list nobody wants any more
    $protected = $cleanup = $sizes = [];
    $toArray = 0;
    $list = jackPlanReadList($listFile);
    if ($incomplete) {
        $say && $say('WARNING', 'Planung unvollständig (Medienserver nicht vollständig erreichbar) – Cleanup wird übersprungen, bisherige Exclude-Liste bleibt geschützt');
        foreach ($list as $p) {
            if (file_exists(jpNorm($p))) {
                $protected["p$p"] = $p;
            }
        }
    } else {
        [$cleanup, $toArray, $protected, $sizes] = jackPlanCleanup($cfg, $loc, $list, $current, $backRank, $playing, $themes, $say);
    }
    return ['cfg' => $cfg, 'env' => $env, 'rels' => $rels, 'names' => $names, 'sessions_ok' => $sessionsOk, 'playing' => $playing, 'planner' => $planner,
            'files' => $files, 'not_in_budget' => $notInBudget, 'loc' => $loc, 'current' => $current, 'incomplete' => $incomplete, 'themes' => $themes,
            'min_free' => $minFree, 'give_back_free' => $giveBackFree, 'watched_only' => $watchedOnly, 'keep' => $keep, 'held' => $held,
            'given_back' => $givenBack, 'given_back_bytes' => $givenBackBytes, 'back_rank' => $backRank, 'back_why' => $backWhy, 'given_list' => $givenList,
            'list' => $list, 'protected' => $protected, 'cleanup' => $cleanup, 'cleanup_sizes' => $sizes, 'to_array' => $toArray];
}

/** f"{v:g}" for the free space's percent */
function jpFloatG(float $v): string
{
    return $v == floor($v) && abs($v) < 1e15 ? (string) (int) $v : rtrim(rtrim(sprintf('%.6g', $v), '0'), '.');
}

/**
 * The dry run's fill: what is still on the array comes to the pool, in the order of the plan, as long as the free space lasts (the
 * cleanup's bytes counted as freed, each planned copy as taken); MEDIACACHE_NO_FILL (the joint run's first step): nothing fetched, what
 * lies on the array only counted as waiting. $shareRoot: ensure_share_root() of a dry run (jackPlanShareRoot(), or the real run's in
 * dry mode with its words).
 */
function jackPlanDryFill(array $d, JackPlanHost $host, ?Closure $say = null, ?Closure $shareRoot = null): array
{
    $cfg = $d['cfg'];
    $env = $d['env'];
    $loc = $d['loc'];
    $playing = $d['playing'];
    $simDelta = $d['cleanup'] ? $d['to_array'] : 0;
    $held = $d['held'];
    $roots = $fill = $dropped = [];
    $toCache = $waiting = $waitingBytes = 0;
    $noFill = ($env['MEDIACACHE_NO_FILL'] ?? '') === '1';
    $shareRoot ??= fn (string $share, array &$roots): bool => jackPlanShareRoot($cfg, $loc->cache, $share, $env, $roots);
    foreach ($noFill ? $d['files'] : [] as $f) {
        if (!file_exists($loc->onCache($f->rel))) {
            $waiting++;
            $waitingBytes += $f->size;
        }
    }
    $lastGroup = null;
    foreach ($noFill ? [] : $d['files'] as $f) {
        if ($f->onCache) {
            continue;
        }
        if ($playing($f->rel)) {
            $say && $say('INFO', "[SKIP: läuft gerade] $f->rel");
            $dropped[] = $f->rel;
            continue;
        }
        $share = explode('/', $f->rel)[0];
        if (!$shareRoot($share, $roots)) {
            $say && $say('WARNING', "[SKIP: Share-Wurzel] $f->rel");
            $dropped[] = $f->rel;
            continue;
        }
        $shareRootDir = jpJoin($loc->cache, $share);
        $after = jackPlanFreeAfter($loc->cache, $f->size, $simDelta, $host);
        if (is_dir($shareRootDir)) {
            $after = min($after, jackPlanFreeAfter($shareRootDir, $f->size, $simDelta, $host));
        }
        if ($after < $d['min_free']) {
            $say && $say('WARNING', sprintf('[SKIP: Freiplatz] %s (%s) – danach nur %.1f %% frei, Minimum %s %% (Dry-Run: Cleanup und geplante Kopien eingerechnet)',
                $f->rel, jpHuman($f->size), $after, jpFloatG($d['min_free'])));
            $waiting++;
            $waitingBytes += $f->size;
            $dropped[] = $f->rel;
            continue;
        }
        $src = $loc->onArray($f->rel);
        $disks = [];
        // Locations.on_disk(): the shadow looks at no disk unless the copy reads from one (array_source disk); the run's dry mode as Python's
        foreach ($cfg['array_source'] === 'disk' || $say !== null ? glob(jpJoin(jpNorm($cfg['array_disks_glob']), $f->rel)) ?: [] : [] as $disk) {
            if (file_exists($disk)) {
                $disks[] = $disk;
            }
        }
        $disks = jpSorted($disks);
        if ($cfg['array_source'] === 'disk') {
            $src = $disks[0] ?? $src;
        }
        if (!is_file($src)) {
            $say && $say('WARNING', "Quelle nicht gefunden: $src");
            $dropped[] = $f->rel;
            continue;
        }
        $toCache += $f->size;
        $held += $f->size;
        $fill[] = $f->rel;
        $simDelta -= $f->size;
        if ($say) {
            $key = jackPlanGroupKey($f->rel);
            if ($key !== $lastGroup) {
                $say('INFO', "[PLAN: -> CACHE] $key   (" . jpStr($f->title) . ')');
                $lastGroup = $key;
            }
            $say('INFO', sprintf('      %10s  %-7s %s', jpHuman($f->size), $disks ? jackPlanDiskName($disks[0], $cfg) : '?', $f->rel));
        }
    }
    return ['rels' => $fill, 'dropped' => $dropped, 'bytes' => $toCache, 'waiting' => $waiting, 'waiting_bytes' => $waitingBytes, 'held' => $held, 'sim_delta' => $simDelta];
}

/** group_key(): share/top folder (the series or the film's folder), else the share */
function jackPlanGroupKey(string $rel): string
{
    $parts = explode('/', $rel);
    return count($parts) > 2 ? "$parts[0]/$parts[1]" : $parts[0];
}

/** Path(p).parts: the root (when there is one) as the first part */
function jpPyParts(string $path): array
{
    [$root, $parts] = jpParts($path);
    return $root !== '' ? array_merge([$root], $parts) : $parts;
}

/** _disk_name(): /mnt/disk3/Filme/x.mkv -> disk3 */
function jackPlanDiskName(string $path, array $cfg): string
{
    $depth = count(jpPyParts($cfg['array_disks_glob']));
    $parts = jpPyParts($path);
    return count($parts) >= $depth ? $parts[$depth - 1] : '?';
}

/**
 * The plan in the stable shape of mediacache_shadow.py's (both sides of the comparison, and what the real run in PHP acted on): the
 * decisions ($d, jackPlanDecide()) and the fill ($fill, jackPlanDryFill() or the real run's).
 */
function jackPlanDoc(array $d, array $fill): array
{
    $planner = $d['planner'];
    $files = $d['files'];
    $sortedRels = jpSorted(array_map('strval', array_values($d['rels'])));
    $sortedNames = jpSorted(array_map('strval', array_keys($d['names'])));
    $group = fn (JackPlanGroup $g): array => ['chain' => $g->chain, 'category' => $g->category, 'source' => $g->source, 'series' => $g->series, 'title' => $g->title,
        'size' => $g->size, 'files' => array_map(fn (JackPlanFile $x): string => $x->rel, $g->files)];
    $backPaths = array_keys($d['back_rank']);
    return jackPlanClean([
        'outcome'    => 'ok',
        'tape'       => JACK_PLAN_TAPE_V,
        'mode'       => 'run',
        'incomplete' => $d['incomplete'],
        'errors'     => $planner->errors,
        'sessions'   => ['rels' => $sortedRels, 'names' => $sortedNames, 'ok' => $d['sessions_ok']],
        'persons'    => array_map(fn (JackPlanUser $u): array => ['key' => $u->key, 'name' => $u->name, 'uids' => $u->uids, 'servers' => $u->servers,
            'budget' => $u->budget === null ? null : sprintf('%.3f', $u->budget), 'bytes' => $u->bytes, 'movie_bytes' => $u->movieBytes, 'series_bytes' => $u->seriesBytes,
            'exhausted' => $u->exhausted, 'selected' => array_map($group, $u->selected), 'skipped' => array_map($group, $u->skipped)], array_values($planner->users)),
        'on_deck'    => array_map(function (JackPlanFile $f): array {
            $ranks = $f->ranks;
            ksort($ranks, SORT_STRING);
            return ['rel' => $f->rel, 'size' => $f->size, 'on_cache' => $f->onCache, 'user' => $f->user, 'source' => $f->source, 'title' => $f->title, 'ranks' => $ranks, 'item' => $f->item];
        }, $files),
        'not_in_budget'   => $d['not_in_budget'],
        'give_back_order' => array_map(fn (JackPlanFile $f): string => $f->rel, jackPlanGiveBackOrder(array_values(array_filter($files, fn (JackPlanFile $f): bool => $f->onCache)))),
        'give_back'  => ['paths' => array_map(fn (int|string $k): string => substr((string) $k, 1), $backPaths), 'files' => $d['given_back'], 'bytes' => $d['given_back_bytes'],
                         'held' => $fill['held'], 'keep' => $d['keep'], 'items' => $d['given_list']],
        'cleanup'    => ['paths' => $d['cleanup'], 'bytes' => $d['to_array']],
        'protected'  => jpSorted(array_values($d['protected'])),
        'fill'       => ['rels' => $fill['rels'], 'dropped' => $fill['dropped'], 'bytes' => $fill['bytes'], 'waiting' => $fill['waiting'], 'waiting_bytes' => $fill['waiting_bytes']],
        'sim_delta'  => $fill['sim_delta'],
    ]);
}

/**
 * Runner's dry run, every decision of it, in the shape of mediacache_shadow.py's plan: nothing moves, nothing is written.
 *
 * @param array<string, string> $env what Python would find in its environment
 */
function jackPlanDry(array $cfg, array $env, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV): array
{
    $d = jackPlanDecide($cfg, $env, $host, $plexTv);
    return jackPlanDoc($d, jackPlanDryFill($d, $host));
}

/**
 * --release's decisions (Runner.release()): what plays stays, everything else on his list goes back the cleanup's way; nothing is
 * planned. With `themes` the theme.mp3 stay unless MEDIACACHE_RELEASE_THEMES=1. [sessions, ok, the cleanup's selection …]
 *
 * @param array<string, string> $env
 */
function jackPlanReleaseDecide(array $cfg, array $env, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV, ?Closure $say = null): array
{
    clearstatcache(true);
    $listFile = jpJoin(($env['MEDIACACHE_DIR'] ?? '') ?: '.', 'mediacache_exclude.txt');
    [$rels, $names, $sessionsOk] = jackPlanSessions($cfg, $host, $plexTv, $say);
    $playing = fn (string $rel): bool => isset($rels["r$rel"]) || isset($names[jpName($rel)]);
    if (!$sessionsOk && $say) {
        $say('WARNING', 'Nicht alle Emby-Instanzen haben geantwortet – was dort gerade läuft, kann nicht geschützt werden (das Office fragt vorher, ob jemand schaut)');
    }
    $loc = new JackPlanLocations($cfg, $cfg['instances'][0]['_mappings']);
    $keepThemes = $cfg['themes'] === true && ($env['MEDIACACHE_RELEASE_THEMES'] ?? '') !== '1';
    $list = jackPlanReadList($listFile);
    [$cleanup, $toArray, $protected, $sizes] = jackPlanCleanup($cfg, $loc, $list, [], [], $playing, $keepThemes, $say);
    return ['cfg' => $cfg, 'env' => $env, 'rels' => $rels, 'names' => $names, 'sessions_ok' => $sessionsOk, 'playing' => $playing, 'loc' => $loc,
            'keep_themes' => $keepThemes, 'list' => $list, 'cleanup' => $cleanup, 'to_array' => $toArray, 'protected' => $protected, 'cleanup_sizes' => $sizes,
            'back_why' => [], 'back_rank' => []];
}

/** --release's decisions in the plan's shape */
function jackPlanReleaseDoc(array $d): array
{
    return jackPlanClean(['outcome' => 'ok', 'tape' => JACK_PLAN_TAPE_V, 'mode' => 'release', 'incomplete' => !$d['sessions_ok'],
        'sessions' => ['rels' => jpSorted(array_map('strval', array_values($d['rels']))), 'names' => jpSorted(array_map('strval', array_keys($d['names']))), 'ok' => $d['sessions_ok']],
        'cleanup' => ['paths' => $d['cleanup'], 'bytes' => $d['to_array']], 'protected' => jpSorted(array_values($d['protected'])), 'keep_themes' => $d['keep_themes']]);
}

/** --release's decisions, dry: nothing is written (no list, no themes state) */
function jackPlanReleaseDry(array $cfg, array $env, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV): array
{
    return jackPlanReleaseDoc(jackPlanReleaseDecide($cfg, $env, $host, $plexTv));
}

/**
 * One whole dry plan from the settings, ending as Python's script would: outcome ok | config | crash (with a Python traceback, or
 * what no Python has: anything PHP throws). Never throws.
 *
 * @param array<string, string> $env
 */
function jackPlanRun(array $env, JackPlanHost $host, string $plexTv = JACK_PLAN_PLEX_TV, string $mode = 'run'): array
{
    try {
        jackPlanPython($host instanceof JackPlanTape ? $host->python : [3, 11]);
        $dir = ($env['MEDIACACHE_DIR'] ?? '') ?: '.';
        $cfg = jackPlanConfig(($env['MEDIACACHE_CONFIG'] ?? '') ?: jpJoin($dir, 'mediacache_settings.json'), $env);
        jackPlanKeepBytes($env);
        return $mode === 'release' ? jackPlanReleaseDry($cfg, $env, $host, $plexTv) : jackPlanDry($cfg, $env, $host, $plexTv);
    } catch (JackPlanConfigError) {
        return ['outcome' => 'config', 'tape' => JACK_PLAN_TAPE_V];
    } catch (Throwable $e) {
        return ['outcome' => 'crash', 'tape' => JACK_PLAN_TAPE_V, 'detail' => get_class($e) . ': ' . jackPlanCleanText($e->getMessage())];
    }
}

/**
 * The plan as a process of its own (jack-shadow.php starts it, and stops it at once when the array stops, a stop is asked or its time is
 * up: nothing of the share is held while it plans): `php jack-plan.php --env <json> --out <json> --mode run|release [--tape <file>]
 * [--plex-tv <url>]`. Without --tape it asks the servers itself (JackPlanLive). Writes {plan, asked, failed, missing, left}, 0600.
 */
function jackPlanMain(array $argv): int
{
    $opt = [];
    for ($i = 1; $i < count($argv) - 1; $i += 2) {
        $opt[ltrim((string) $argv[$i], '-')] = (string) $argv[$i + 1];
    }
    $env = json_decode((string) @file_get_contents($opt['env'] ?? ''), true);
    if (!is_array($env) || !isset($opt['out'])) {
        fwrite(STDERR, "jack-plan: --env and --out\n");
        return 2;
    }
    $host = isset($opt['tape']) ? JackPlanTape::read($opt['tape']) : new JackPlanLive($env);
    if ($host === null) {
        fwrite(STDERR, "jack-plan: no tape\n");
        return 2;
    }
    $plan = jackPlanRun(array_map('strval', $env), $host, $opt['plex-tv'] ?? JACK_PLAN_PLEX_TV, ($opt['mode'] ?? 'run') === 'release' ? 'release' : 'run');
    $out = ['plan' => $plan, 'asked' => $host->asked, 'failed' => $host instanceof JackPlanLive ? $host->failed : 0,
            'missing' => $host instanceof JackPlanTape ? $host->missing : [], 'left' => $host instanceof JackPlanTape ? $host->left() : []];
    $tmp = $opt['out'] . '.tmp';
    umask(077);
    if (@file_put_contents($tmp, (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) === false || !@rename($tmp, $opt['out'])) {
        return 1;
    }
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    if (!function_exists('run')) {
        if (!defined('FILE_UID')) {
            define('FILE_UID', 0);
            define('FILE_GID', 0);
        }
        require_once dirname(__DIR__) . '/lib/util.php';
    }
    exit(jackPlanMain($argv));
}
