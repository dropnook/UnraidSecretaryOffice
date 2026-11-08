<?php
declare(strict_types=1);

/*
 * The stand-in media servers of the differential check (tests/shadow/diff.php): `php -S` with this router. One process plays any number of
 * servers and plex.tv, told apart by the first part of the path (http://127.0.0.1:PORT/<name>). What they answer comes from the file
 * STUB_WORLD (made by diff.php); `raw` entries give any odd answer byte by byte. STUB_LOG gets a line per request: which key came along.
 */
$world = json_decode((string) file_get_contents((string) getenv('STUB_WORLD')));
$uri = (string) $_SERVER['REQUEST_URI'];
$cut = explode('?', $uri, 2);
$path = $cut[0];
$qs = $cut[1] ?? '';
parse_str($qs, $q);
$parts = explode('/', $path, 3);
$srv = $parts[1] ?? '';
$rest = '/' . ($parts[2] ?? '');
$s = $world->{$srv} ?? null;
$token = (string) ($_SERVER['HTTP_X_EMBY_TOKEN'] ?? $_SERVER['HTTP_X_PLEX_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents((string) getenv('STUB_LOG'), json_encode(['srv' => $srv, 'path' => $rest, 'qs' => $qs, 'key' => $token]) . "\n", FILE_APPEND);

function answer(mixed $v): never
{
    header('Content-Type: application/json');
    echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function rawAnswer(stdClass $r): never
{
    if (isset($r->sleep)) {
        sleep((int) $r->sleep);
    }
    http_response_code((int) ($r->status ?? 200));
    if (isset($r->location)) {
        header('Location: ' . $r->location);
    }
    header('Content-Type: application/json');
    if (isset($r->b64)) {
        echo base64_decode($r->b64);
    } elseif (isset($r->body)) {
        echo $r->body;
    } elseif (property_exists($r, 'json')) {
        echo json_encode($r->json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (!$s instanceof stdClass) {
    http_response_code(404);
    exit;
}
$raw = $s->raw ?? new stdClass();
foreach (["$rest?$qs", $rest . '|' . ($q['IncludeItemTypes'] ?? ''), $rest] as $k) {
    if (isset($raw->{$k})) {
        rawAnswer($raw->{$k});
    }
}
if (isset($s->key) && !str_contains($token, (string) $s->key) && ($s->type ?? '') !== 'plex') {
    http_response_code(401);
    exit;
}
$bag = fn (string $field, mixed $id): array => (array) ($s->{$field}->{(string) $id} ?? []);
$limited = function (array $items) use ($q): array {
    if (isset($q['IncludeItemTypes'])) {
        $items = array_values(array_filter($items, fn ($i) => ($i->Type ?? null) === $q['IncludeItemTypes']));
    }
    return isset($q['Limit']) ? array_slice($items, 0, (int) $q['Limit']) : $items;
};
switch ($s->type ?? '') {
    case 'emby':
    case 'jellyfin':
        if ($rest === '/Users') {
            answer($s->users ?? []);
        }
        if ($rest === '/Sessions') {
            answer($s->sessions ?? []);
        }
        if ($rest === '/Shows/NextUp') {
            answer(['Items' => $limited($bag('nextup', $q['UserId'] ?? ''))]);
        }
        $uid = $q['userId'] ?? null;
        $what = null;
        if (preg_match('#^/Users/([^/]+)/Items(/Resume)?$#', $rest, $m)) {
            [$uid, $what] = [$m[1], isset($m[2]) ? 'resume' : 'items'];
        } elseif ($rest === '/UserItems/Resume') {
            $what = 'resume';
        } elseif ($rest === '/Items' && $uid !== null) {
            $what = 'items';
        }
        if ($what === 'resume') {
            answer(['Items' => $limited($bag('resume', $uid))]);
        }
        if ($what === 'items') {
            if (($q['Filters'] ?? '') === 'IsFavorite') {
                answer(['Items' => array_slice($bag('favs', $uid), 0, (int) ($q['Limit'] ?? 1000))]);
            }
            answer(['Items' => $bag('episodes', $q['ParentId'] ?? '')]);
        }
        if ($rest === '/Items') {
            $all = (array) ($s->library ?? []);
            answer(['Items' => array_slice($all, (int) ($q['StartIndex'] ?? 0), (int) ($q['Limit'] ?? 100000)), 'TotalRecordCount' => count($all)]);
        }
        break;
    case 'plex':
        $token = (string) ($_SERVER['HTTP_X_PLEX_TOKEN'] ?? '');
        $c = fn (array $m): never => answer(['MediaContainer' => (object) $m]);
        if ($rest === '/') {
            $c((array) ($s->root ?? ['machineIdentifier' => 'mid']));
        }
        if ($rest === '/library/onDeck') {
            $c(['Metadata' => $bag('ondeck', $token)]);
        }
        if (preg_match('#^/library/metadata/([^/]+)/allLeaves$#', $rest, $m)) {
            $c(['Metadata' => $bag('leaves', $m[1])]);
        }
        if ($rest === '/status/sessions') {
            $c(['Metadata' => (array) ($s->sessions ?? [])]);
        }
        if ($rest === '/library/sections') {
            $c(['Directory' => (array) ($s->sections ?? [])]);
        }
        if (preg_match('#^/library/sections/([^/]+)/all$#', $rest, $m)) {
            $c(['Metadata' => $bag('section', $m[1])]);
        }
        break;
    case 'plextv':
        if ($rest === '/api/v2/user') {
            answer($s->account ?? new stdClass());
        }
        if (preg_match('#^/api/servers/[^/]+/shared_servers$#', $rest)) {
            header('Content-Type: application/xml');
            echo (string) ($s->shared ?? '<MediaContainer/>');
            exit;
        }
        break;
}
http_response_code(404);
