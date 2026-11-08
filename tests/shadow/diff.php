<?php
declare(strict_types=1);

/*
 * The differential check of jack-plan.php against MediaCache's Python planner (2026-11-07; docs/DEVELOPMENT.md «jack»). Needs python3 and
 * runs only on a test server, never in the package, never on nostromo. Three plans per case, dry and side by side: Python with its tape
 * (mediacache_shadow.py), PHP from that tape, PHP with its own live client. Anything but the same is a line with the difference.
 *
 *   php tests/shadow/diff.php [--list] [name-part …]        the stand-in servers (stub.php): every case below
 *   php tests/shadow/diff.php --names                       person_key() over every code point
 *   php tests/shadow/diff.php --real <settings.json>        real servers: variations of those settings, on the real pool and array
 */
$root = dirname(__DIR__, 2);
if (!defined('FILE_UID')) {
    define('FILE_UID', 0);
    define('FILE_GID', 0);
}
require "$root/agent/lib/util.php";
define('JACK_DATA', '/nonexistent');
require "$root/agent/desks/jack-plan.php";
require "$root/agent/desks/jack-shadow.php";

$argvRest = array_slice($argv, 1);
$GLOBALS['app'] = "$root/mediacache";

function say(string $s): void
{
    echo $s, "\n";
}

function tree(string $dir, array $files): void
{
    foreach ($files as $rel => $size) {
        $f = "$dir/$rel";
        @mkdir(dirname($f), 0777, true);
        $fh = fopen($f, 'c');
        ftruncate($fh, (int) $size);
        fclose($fh);
    }
}

/** Python with its tape: [plan, tape] */
function pyPlan(array $env, string $work, string $mode = 'run'): array
{
    $tape = "$work/tape.jsonl";
    $plan = "$work/plan.json";
    @unlink($plan);
    $full = $env + ['PYTHONDONTWRITEBYTECODE' => '1', 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => '/root'];
    $p = proc_open(['python3', $GLOBALS['app'] . '/mediacache_shadow.py', '--tape', $tape, '--plan', $plan, '--logs', "$work/logs", '--mode', $mode],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$work/py.out", 'w'], 2 => ['file', "$work/py.err", 'w']], $pipes, $GLOBALS['app'], $full);
    $exit = proc_close($p);
    $doc = readJson($plan);
    return [$doc ?? ['outcome' => "no plan (exit $exit): " . substr((string) file_get_contents("$work/py.err"), -400)], JackPlanTape::read($tape)];
}

function roundTrip(array $doc): array
{
    return json_decode((string) json_encode($doc), true);
}

/** The three plans of one setup: the differences, per comparison. With a stub's log: each request and the key it carried, Python's and PHP's live client's, the same */
function threePlans(array $env, string $work, string $plexTv, ?string $log = null, array $secrets = [], string $mode = 'run'): array
{
    @unlink((string) $log);
    [$py, $tape] = pyPlan($env, $work, $mode);
    $pyAsked = $log !== null ? (string) @file_get_contents($log) : '';
    $tapeText = (string) @file_get_contents("$work/tape.jsonl");
    $fromTape = $tape === null ? ['outcome' => 'no tape'] : roundTrip(jackPlanRun($env, $tape, $plexTv, $mode));
    @unlink((string) $log);
    $live = new JackPlanLive($env);
    $seen = roundTrip(jackPlanRun($env, $live, $plexTv, $mode));
    $liveDiff = jackShadowDiff($py, $seen);
    $tapeDiff = jackShadowDiff($py, $fromTape, $tape->missing ?? [], $tape?->left() ?? []);
    if ($log !== null) {
        $a = array_values(array_filter(explode("\n", $pyAsked)));
        $b = array_values(array_filter(explode("\n", (string) @file_get_contents($log))));
        if ($a !== $b) {
            foreach ($a as $i => $line) {
                if (($b[$i] ?? null) !== $line) {
                    $liveDiff[] = ['kind' => 'client', 'path' => "request $i of " . count($a) . '/' . count($b), 'python' => $line, 'php' => $b[$i] ?? null];
                    break;
                }
            }
            if (count($b) > count($a) && array_slice($b, 0, count($a)) === $a) {
                $liveDiff[] = ['kind' => 'client', 'path' => 'more requests', 'python' => count($a), 'php' => $b[count($a)]];
            }
        }
        foreach ($secrets as $secret) {
            if (str_contains($tapeText, $secret)) {
                $tapeDiff[] = ['kind' => 'key on the tape', 'path' => '', 'python' => true, 'php' => null];
            }
        }
    }
    return ['py' => $py, 'tape' => $tapeDiff, 'live' => $liveDiff, 'php' => $fromTape, 'seen' => $seen, 'asked' => $live->asked];
}

function report(string $name, array $r, ?string $bad): bool
{
    $on = count($r['py']['on_deck'] ?? []);
    $line = sprintf('%-46s %-8s deck %-5d fill %-4d back %-3d clean %-3d %s', $name, $r['py']['outcome'] ?? '?', $on, count($r['py']['fill']['rels'] ?? []),
        count($r['py']['give_back']['paths'] ?? []), count($r['py']['cleanup']['paths'] ?? []), !empty($r['py']['incomplete']) ? 'incomplete' : '');
    $fine = !$r['tape'] && !$r['live'] && $bad === null;
    say(($fine ? 'same  ' : 'DIFF  ') . $line);
    foreach (['tape' => $r['tape'], 'live' => $r['live']] as $which => $d) {
        foreach (array_slice($d, 0, 12) as $x) {
            say("      $which {$x['kind']} {$x['path']}\n        python " . json_encode($x['python'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n        php    " . json_encode($x['php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if (count($d) > 12) {
            say('      … ' . (count($d) - 12) . ' more');
        }
    }
    if ($r['php']['outcome'] === 'crash' && ($r['py']['outcome'] ?? '') !== 'crash') {
        say('      php: ' . ($r['php']['detail'] ?? ''));
    }
    if ($bad !== null) {
        say("      the case itself: $bad");
    }
    return $fine;
}

// ======================================================================== person_key() over everything

if (($argvRest[0] ?? '') === '--names') {
    $work = sys_get_temp_dir() . '/uso-shadow-names-' . getmypid();
    @mkdir($work);
    $py = <<<'PY'
import sys, json
sys.path.insert(0, sys.argv[1])
import unicodedata
def person_key(name):
    n = unicodedata.normalize("NFKD", str(name or ""))
    return "p:" + "".join(c for c in n if not unicodedata.combining(c) and not c.isspace()).casefold()
out = open(sys.argv[2], "w", encoding="utf-8", errors="surrogatepass")
for c in range(0x110000):
    if 0xD800 <= c <= 0xDFFF: continue
    out.write(person_key("a" + chr(c) + "B") + "\n")
out.close()
PY;
    file_put_contents("$work/k.py", $py);
    passthru('python3 -I ' . escapeshellarg("$work/k.py") . ' ' . escapeshellarg($GLOBALS['app']) . ' ' . escapeshellarg("$work/keys.txt"));
    $fh = fopen("$work/keys.txt", 'r');
    $bad = [];
    $n = 0;
    for ($c = 0; $c < 0x110000; $c++) {
        if ($c >= 0xD800 && $c <= 0xDFFF) {
            continue;
        }
        $line = rtrim((string) fgets($fh), "\n");
        // str.splitlines() is not in play: one line per key; a key holding \n itself (none: whitespace is removed)
        $mine = jpPersonKey('a' . mb_chr($c, 'UTF-8') . 'B');
        $n++;
        if ($line !== $mine) {
            $bad[] = sprintf('U+%04X python %s php %s', $c, json_encode($line), json_encode($mine));
        }
    }
    say("person_key: $n code points, " . count($bad) . ' differ');
    foreach (array_slice($bad, 0, 40) as $b) {
        say("  $b");
    }
    exec('rm -rf ' . escapeshellarg($work));
    exit($bad ? 1 : 0);
}

// ======================================================================== real servers

if (($argvRest[0] ?? '') === '--real') {
    $base = json_decode((string) file_get_contents($argvRest[1]), true);
    $list = $argvRest[2] ?? null;
    $work = sys_get_temp_dir() . '/uso-shadow-real-' . getmypid();
    @mkdir("$work/data/logs", 0777, true);
    $byType = fn (array $types): array => array_values(array_filter($base['instances'], fn (array $i): bool => in_array($i['type'], $types, true)));
    $mapsOff = function (array $inst, string $sub): array {         // a folder deliberately not cached
        $m = (array) $inst['path_mappings'];
        $docker = array_key_first($m);
        $m["$docker/$sub"] = '';
        $inst['path_mappings'] = $m;
        return $inst;
    };
    $userIds = [];
    $cases = [
        'emby alone' => ['instances' => $byType(['emby'])],
        'jellyfin alone' => ['instances' => $byType(['jellyfin'])],
        'plex alone' => ['instances' => $byType(['plex'])],
        'all three' => [],
        'all three, count mode' => ['cache_budget' => ''],
        'all three, budget 3 GB' => ['cache_budget' => '3G'],
        'all three, budget 500 MB (too small)' => ['cache_budget' => '500M'],
        'all three, budget 20 GB, films 20 %' => ['cache_budget' => '20G', 'movie_share_percent' => 20],
        'separate limits: 1 film, 1 series' => ['max_resume_movies' => 1, 'max_resume_series' => 1],
        'separate limits: no films' => ['max_resume_movies' => 0],
        'separate limits: no series' => ['max_resume_series' => 0],
        'no next up' => ['use_next_up' => false],
        'one episode per series' => ['max_episodes_per_series' => 1, 'cache_budget' => '20G'],
        'favourites off' => ['max_favorite_series' => 0],
        'movie_mode file' => ['movie_mode' => 'file'],
        'cleanup by rsync' => ['cleanup_tool' => 'rsync'],
        'a mapping left empty: Familienserien not cached' => ['instances' => array_map(fn (array $i): array => $mapsOff($i, 'Familienserien'), $base['instances'])],
        'a mapping left empty: Familienfilme not cached' => ['instances' => array_map(fn (array $i): array => $mapsOff($i, 'Familienfilme'), $base['instances'])],
        'a server that does not answer among them' => ['instances' => array_merge($base['instances'], [['servername' => 'off', 'type' => 'emby', 'url' => 'http://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/mnt/media' => '/mnt/user/media']]])],
        'a wrong key' => ['instances' => array_map(fn (array $i): array => ['api_key' => $i['type'] === 'plex' ? $i['api_key'] : 'wrongwrongwrong'] + $i, $base['instances'])],
        'themes on' => ['themes' => true],
        'array_source disk' => ['array_source' => 'disk'],
    ];
    $envs = ['' => [], ' + give back (min free 99)' => ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => '99'], ' + no fill' => ['MEDIACACHE_NO_FILL' => '1', 'MEDIACACHE_GIVE_BACK_FREE' => '1'],
             ' + watched only' => ['MEDIACACHE_WATCHED_ONLY' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => '40', 'MEDIACACHE_KEEP_BYTES' => '1000']];
    $all = true;
    $n = 0;
    foreach ($cases as $name => $over) {
        file_put_contents("$work/data/mediacache_settings.json", json_encode($over + $base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($list !== null) {
            copy($list, "$work/data/mediacache_exclude.txt");
        }
        foreach ($envs as $tag => $env) {
            foreach (['run', 'release'] as $mode) {
                if ($mode === 'release' && $tag !== '') {
                    continue;
                }
                $r = threePlans($env + ['MEDIACACHE_DIR' => "$work/data"], $work, JACK_PLAN_PLEX_TV, null, [], $mode);
                $all = report($name . $tag . ($mode === 'release' ? ' (release)' : ''), $r, null) && $all;
                $n++;
            }
        }
    }
    say(($all ? 'all the same' : 'DIFFERENCES') . " ($n setups, " . count($cases) . ' settings)');
    exec('rm -rf ' . escapeshellarg($work));
    exit($all ? 0 : 1);
}

// ======================================================================== the stand-in servers

$port = 18900 + getmypid() % 900;
$tmp = sys_get_temp_dir() . '/uso-shadow-' . getmypid();
@mkdir($tmp);
$worldFile = "$tmp/world.json";
$logFile = "$tmp/stub.log";
file_put_contents($worldFile, '{}');
$stub = proc_open(['setsid', PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=0', '-S', "127.0.0.1:$port", __DIR__ . '/stub.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp, ['STUB_WORLD' => $worldFile, 'STUB_LOG' => $logFile, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'PHP_CLI_SERVER_WORKERS' => '4']);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $s, 0.2); $i++) {
    usleep(100000);
}
$base = "http://127.0.0.1:$port";
$shutdown = function () use ($stub, $tmp): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pid = (int) (proc_get_status($stub)['pid'] ?? 0);
    if ($pid > 1) {
        posix_kill(-$pid, SIGTERM);      // the server and its workers: their own process group (setsid)
    }
    proc_terminate($stub);
    proc_close($stub);
    exec('rm -rf ' . escapeshellarg($tmp));
};
register_shutdown_function($shutdown);

$film = fn (string $id, string $name, string $path, array $x = []): array => $x + ['Id' => $id, 'Type' => 'Movie', 'Name' => $name, 'Path' => $path, 'LocationType' => 'FileSystem'];
$ep = fn (string $sid, string $sname, int $s, int $e, string $path, array $x = []): array => $x + ['Id' => "$sid-$s-$e", 'Type' => 'Episode', 'Name' => "Folge $e", 'SeriesId' => $sid, 'SeriesName' => $sname,
    'ParentIndexNumber' => $s, 'IndexNumber' => $e, 'Path' => $path, 'LocationType' => 'FileSystem', 'UserData' => ['Played' => false]];
$u = fn (string $id, string $name): array => ['Id' => $id, 'Name' => $name];

// the same home library everywhere
$A = '/m/Filme/Ferien (2019)/Ferien.mkv';
$B = '/m/Filme/Taufe (2018)/Taufe.mkv';
$C = '/m/Filme/Schulfest (2020)/Schulfest.mkv';
$D = '/m/Filme/Velotour (2023)/Velotour.mkv';
$files = ['Filme/Ferien (2019)/Ferien.mkv' => 4000, 'Filme/Ferien (2019)/Ferien.srt' => 10, 'Filme/Ferien (2019)/extras/Making of.mkv' => 90,
    'Filme/Taufe (2018)/Taufe.mkv' => 2000, 'Filme/Schulfest (2020)/Schulfest.mkv' => 3000, 'Filme/Velotour (2023)/Velotour.mkv' => 9000, 'Filme/Flach.mkv' => 700, 'Filme/Flach.srt' => 7];
$eps = [];
foreach (['Hausbau' => 'H', 'Garten' => 'G'] as $sname => $sid) {
    for ($s = 1; $s <= 2; $s++) {
        for ($e = 1; $e <= 4; $e++) {
            $p = sprintf('Serien/%s/Season %02d/%s S%02dE%02d', $sname, $s, $sname, $s, $e);
            $files["$p.mkv"] = 300 + 10 * $e + $s;
            $files["$p.srt"] = 5;
            $eps[$sid][] = $ep($sid, $sname, $s, $e, "/m/$p.mkv");
        }
    }
}
$H = $eps['H'];
$G = $eps['G'];
$series = fn (string $id, string $name): array => ['Id' => $id, 'Type' => 'Series', 'Name' => $name, 'Path' => "/m/Serien/$name"];
$mb = [
    'users' => [$u('u1', 'Anna'), $u('u2', 'Leo')],
    'resume' => ['u1' => [$film('fa', 'Ferien', $A), $H[1]], 'u2' => [$film('fb', 'Taufe', $B), $film('fc', 'Schulfest', $C)]],
    'nextup' => ['u1' => [$G[0]], 'u2' => [$H[4]]],
    'favs' => ['u2' => [$series('G', 'Garten')]],
    'episodes' => ['H' => $H, 'G' => $G],
];
$plexItem = function (array $i, array $x = []): array {
    $m = ['ratingKey' => $i['Id'], 'title' => $i['Name'], 'type' => strtolower($i['Type']), 'Media' => [['Part' => [['file' => $i['Path']]]]]];
    if ($i['Type'] === 'Episode') {
        $m += ['grandparentRatingKey' => $i['SeriesId'], 'grandparentTitle' => $i['SeriesName'], 'parentIndex' => $i['ParentIndexNumber'], 'index' => $i['IndexNumber']];
    }
    return $m + $x;
};
$now = time();
$plex = ['type' => 'plex', 'root' => ['machineIdentifier' => 'mid1', 'myPlexSigninState' => 'ok', 'myPlexUsername' => 'x'],
    'ondeck' => ['plexadmintoken' => [$plexItem($film('p1', 'Ferien', $A), ['lastViewedAt' => $now - 86400]), $plexItem($H[2], ['lastViewedAt' => $now - 3600]), $plexItem($H[5])],
                 'friendtoken' => [$plexItem($film('p2', 'Velotour', $D), ['lastViewedAt' => $now - 86400]), $plexItem($film('p3', 'Taufe', $B), ['lastViewedAt' => $now - 400 * 86400]), $plexItem($G[1], ['viewCount' => 1, 'lastViewedAt' => $now - 100])]],
    'leaves' => ['H' => array_map($plexItem, $H), 'G' => array_map($plexItem, $G)]];
$plextv = ['type' => 'plextv', 'account' => ['id' => 111, 'title' => 'Chef', 'username' => 'chef', 'authToken' => 'secret-account-token'],
    'shared' => '<MediaContainer><SharedServer userID="222" username="Freund" email="f@example.invalid" accessToken="friendtoken"/><SharedServer userID="333" username="Ohne" accessToken=""/></MediaContainer>'];
$raw = fn (array $map): array => ['raw' => $map];
$bodyIs = fn (string $b): array => ['body' => $b];
$notFile = fn (array $py): ?string => null;
$deck = fn (int $n): callable => fn (array $py): ?string => count($py['on_deck'] ?? []) === $n ? null : 'expected ' . $n . ' files on deck, python has ' . count($py['on_deck'] ?? []);
$out = fn (string $o): callable => fn (array $py): ?string => ($py['outcome'] ?? '') === $o ? null : "expected outcome $o, python has " . ($py['outcome'] ?? '?');
$inc = fn (array $py): ?string => !empty($py['incomplete']) ? null : 'expected an incomplete plan';
$complete = fn (array $py): ?string => empty($py['incomplete']) && ($py['outcome'] ?? '') === 'ok' ? null : 'expected a complete plan';
$hasFill = fn (array $py): ?string => !empty($py['fill']['rels']) ? null : 'expected a fill';
$all = fn (callable ...$c): callable => function (array $py) use ($c): ?string {
    foreach ($c as $f) {
        if (($x = $f($py)) !== null) {
            return $x;
        }
    }
    return null;
};
$emby1 = ['one' => ['type' => 'emby', 'key' => 'k1'] + $mb];
$oneRaw = fn (array $map, array $w = []): array => ['one' => $w + ['type' => 'emby', 'key' => 'k1'] + $raw($map) + $mb];

$cases = [];
$case = function (string $name, array $spec) use (&$cases): void {
    $cases[$name] = $spec;
};

// ---------------------------------------------------- the three servers
$case('emby: resumed, next up, favourites, two users', ['world' => $emby1, 'expect' => $all($complete, $hasFill)]);
$case('jellyfin: the same', ['world' => ['one' => ['type' => 'jellyfin', 'key' => 'k1'] + $mb], 'expect' => $all($complete, $hasFill)]);
$case('plex: admin + friend, on deck, days_to_monitor', ['world' => ['px' => $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'instance' => ['days_to_monitor' => 30], 'expect' => $all($complete, $hasFill)]);
$case('plex: unclaimed, plex.tv says no: admin alone, complete', ['world' => ['px' => ['root' => ['machineIdentifier' => 'mid1']] + $plex, 'plextv' => ['type' => 'plextv'] + $raw(['/api/v2/user' => ['status' => 401]])], 'key' => 'plexadmintoken', 'expect' => $complete]);
$case('plex: claimed, plex.tv says no: incomplete', ['world' => ['px' => $plex, 'plextv' => ['type' => 'plextv'] + $raw(['/api/v2/user' => ['status' => 401]])], 'key' => 'plexadmintoken', 'expect' => $inc]);
$case('plex: shared_servers is no XML', ['world' => ['px' => $plex, 'plextv' => ['shared' => '<MediaContainer><oops'] + $plextv], 'key' => 'plexadmintoken', 'expect' => $inc]);
$case('plex: valid_users, plex.tv silent, claimed', ['world' => ['px' => $plex, 'plextv' => ['type' => 'plextv'] + $raw(['/api/v2/user' => ['status' => 500]])], 'key' => 'plexadmintoken', 'settings' => ['valid_users' => ['222']], 'expect' => $inc]);
$case('plex: the limit after the kind, not before', ['world' => ['px' => ['ondeck' => ['plexadmintoken' => [$plexItem($H[0]), $plexItem($film('p1', 'Ferien', $A)), $plexItem($G[0]), $plexItem($film('p2', 'Taufe', $B))]]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'settings' => ['max_resume_movies' => 1, 'max_resume_series' => 1, 'number_episodes' => 0], 'expect' => $deck(5)]);
$case('plex: valid_users picks the friend', ['world' => ['px' => $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'settings' => ['valid_users' => ['222']], 'expect' => $complete]);
$case('all three at once, the same people', ['world' => ['em' => ['type' => 'emby'] + $mb, 'je' => ['type' => 'jellyfin', 'users' => [$u('j1', ' ánna'), $u('j2', 'LEO')], 'resume' => ['j1' => [$film('x', 'Ferien', $A), $film('y', 'Velotour', $D)], 'j2' => [$H[6]]], 'nextup' => ['j1' => [$G[3]]], 'episodes' => ['H' => $H, 'G' => $G]],
    'px' => $plex, 'plextv' => $plextv], 'keys' => ['px' => 'plexadmintoken'], 'expect' => $all($complete, $hasFill)]);

$case('redirect: the same host keeps the key', ['world' => ['one' => ['type' => 'emby', 'key' => 'k1'] + $raw(['/Users' => ['status' => 302, 'location' => "$base/one/Users2"], '/Users2' => ['json' => $mb['users']], '/Sessions' => ['status' => 301, 'location' => '/one/Sessions2'], '/Sessions2' => ['json' => []], '/Shows/NextUp' => ['status' => 307, 'location' => 'NextUp2'], '/Shows/NextUp2' => ['json' => ['Items' => [$G[0]]]]]) + $mb], 'expect' => $all($complete, $hasFill)]);
$case('redirect: another host never gets it', ['world' => ['one' => ['type' => 'emby', 'key' => 'k1'] + $raw(['/Users' => ['status' => 302, 'location' => str_replace('127.0.0.1', 'localhost', $base) . '/two/Users']]) + $mb, 'two' => ['type' => 'emby', 'users' => [$u('u9', 'Fremd')]]], 'expect' => $complete]);
$case('redirect: the loop, no Location, a 304, odd scheme', ['world' => $oneRaw(['/Users' => ['status' => 302, 'location' => '/one/Users'], '/Sessions' => ['status' => 302], '/Shows/NextUp' => ['status' => 304], '/Users/u1/Items/Resume' => ['status' => 302, 'location' => 'file:///etc/passwd']]), 'expect' => $inc]);
$case('redirect: plex to its own host and away', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['status' => 302, 'location' => "$base/px/library/onDeck2"], '/library/onDeck2' => ['json' => ['MediaContainer' => ['Metadata' => [$plexItem($film('p1', 'Ferien', $A))]]]], '/status/sessions' => ['status' => 302, 'location' => str_replace('127.0.0.1', 'localhost', $base) . '/two/x']]] + $plex, 'plextv' => $plextv, 'two' => ['type' => 'emby']], 'key' => 'plexadmintoken']);

// ---------------------------------------------------- budgets, limits, ranks
foreach (['2500' => 2500, '10K' => 10240, '30K' => 30720] as $tag => $b) {
    $case("budget $tag, two persons", ['world' => $emby1, 'settings' => ['cache_budget' => $tag]]);
}
$case('budget too small for anything', ['world' => $emby1, 'settings' => ['cache_budget' => '10'], 'expect' => $deck(0)]);
$case('budget: an entry two persons want (films)', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u1', 'Anna'), $u('u2', 'Leo')], 'resume' => ['u1' => [$film('fa', 'Ferien', $A), $film('fd', 'Velotour', $D)], 'u2' => [$film('fa', 'Ferien', $A), $film('fc', 'Schulfest', $C)]]]], 'settings' => ['cache_budget' => '9K']]);
$case('budget: fixed person, under the id of another server', ['world' => ['em' => ['type' => 'emby'] + $mb, 'je' => ['type' => 'jellyfin', 'users' => [$u('j1', 'Anna')], 'resume' => ['j1' => [$film('fd', 'Velotour', $D)]]]], 'settings' => ['cache_budget' => '40K', 'valid_users' => ['u1' => [], 'u2' => ['budget' => '6K'], 'j1' => ['budget' => '12K']]]]);
$case('budget: fixed budgets above the whole', ['world' => $emby1, 'settings' => ['cache_budget' => '4K', 'valid_users' => ['u1' => ['budget' => '3K'], 'u2' => ['budget' => '3K']]]]);
$case('budget: films share 0 / 100', ['world' => $emby1, 'settings' => ['cache_budget' => '8K', 'movie_share_percent' => 0]]);
$case('budget: films share 100', ['world' => $emby1, 'settings' => ['cache_budget' => '8K', 'movie_share_percent' => 100]]);
$case('budget: at most one episode a series', ['world' => $emby1, 'settings' => ['cache_budget' => '30K', 'max_episodes_per_series' => 1]]);
$case('count: number_episodes 0', ['world' => $emby1, 'settings' => ['number_episodes' => 0]]);
$case('count: number_episodes 1', ['world' => $emby1, 'settings' => ['number_episodes' => 1]]);
$case('limits: 1 film 1 series, each person', ['world' => $emby1, 'settings' => ['max_resume_movies' => 1, 'max_resume_series' => 1]]);
$case('limits: resume 0 / 0, next up only', ['world' => $emby1, 'settings' => ['max_resume_movies' => 0, 'max_resume_series' => 0]]);
$case('limits: falls back on max_resume_items', ['world' => $emby1, 'settings' => ['max_resume_items' => 1]]);
$case('no next up, no favourites', ['world' => $emby1, 'settings' => ['use_next_up' => false, 'max_favorite_series' => 0]]);
$case('valid_users: one of two, one unknown', ['world' => $emby1, 'settings' => ['valid_users' => ['u2', 'nobody']]]);
$case('valid_users: nobody of them here', ['world' => $emby1, 'settings' => ['valid_users' => ['nobody']], 'expect' => $deck(0)]);
$case('movie_mode file', ['world' => $emby1, 'settings' => ['movie_mode' => 'file']]);

// a chain: sizes falling, so a later episode would fit where an earlier one didn't: never episode 5 without 4
$chainEps = [];
$chainFiles = [];
foreach ([900, 800, 300, 100] as $i => $sz) {
    $chainFiles[sprintf('Serien/Kette/S01/E%d.mkv', $i + 1)] = $sz;
    $chainEps[] = $ep('K', 'Kette', 1, $i + 1, sprintf('/m/Serien/Kette/S01/E%d.mkv', $i + 1));
}
$one = ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$chainEps[0]]], 'episodes' => ['K' => $chainEps]]];
$case('budget: a chain stops where it no longer fits', ['world' => $one, 'extra' => $chainFiles, 'settings' => ['cache_budget' => '1300', 'movie_share_percent' => 0], 'expect' => $deck(1)]);
$case('budget: exactly the budget fits', ['world' => $one, 'extra' => $chainFiles, 'settings' => ['cache_budget' => '1700', 'movie_share_percent' => 0], 'expect' => $deck(2)]);
$case('budget: exactly the films\' share', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('e', 'Genau', '/m/Filme/Genau/g.mkv'), $film('n', 'Nächster', '/m/Filme/Danach/d.mkv'), $chainEps[3]]], 'episodes' => ['K' => [$chainEps[3]]]]],
    'extra' => $chainFiles + ['Filme/Genau/g.mkv' => 500, 'Filme/Danach/d.mkv' => 10], 'settings' => ['cache_budget' => '1000', 'movie_share_percent' => 50], 'expect' => $deck(3)]);
$case('budget: a share too small, the other kind waiting', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('e', 'Gross', '/m/Filme/Genau/g.mkv'), $chainEps[3]]], 'episodes' => ['K' => [$chainEps[3]]]]],
    'extra' => $chainFiles + ['Filme/Genau/g.mkv' => 700], 'settings' => ['cache_budget' => '1000', 'movie_share_percent' => 20]]);

// ---------------------------------------------------- where things lie
$case('mappings: the longest wins, at borders only', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', $A), $film('2', 'b', '/m/Filme2/Other/o.mkv'), $film('3', 'c', '/m/Deep/inner/d.mkv')]]]],
    'mappings' => ['/m' => '@T@/user', '/m/Deep' => '@T@/user/Tief'], 'extra' => ['Filme2/Other/o.mkv' => 11, 'Tief/inner/d.mkv' => 12, 'Deep/inner/d.mkv' => 13]]);
$case('mappings: an empty host path = deliberately not cached', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', $A), $film('2', 'b', $B)]]]],
    'mappings' => ['/m/Filme/Ferien (2019)' => '@T@/user/Filme/Ferien (2019)', '/m/Filme/Taufe (2018)' => ''], 'expect' => $deck(2)]);
// 2026-11-08 (the owner): an empty line is a mapping too, the longest wins — under a broader one it still means «not cached»
$case('mappings: an empty one below a shorter one: not cached', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', $A), $film('2', 'b', $B)]]]],
    'mappings' => ['/m' => '@T@/user', '/m/Filme/Taufe (2018)' => ''], 'expect' => $deck(2)]);
$case('mappings: a real one below an empty one: the longer takes it', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', $A), $film('2', 'b', $B)]]]],
    'mappings' => ['/m' => '', '/m/Filme/Taufe (2018)' => '@T@/user/Filme/Taufe (2018)'], 'expect' => $deck(2)]);
$case('mappings: per server over the global ones', ['world' => ['em' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/x/Filme/Ferien (2019)/Ferien.mkv')]]], 'je' => ['type' => 'jellyfin', 'users' => [$u('j', 'Leo')], 'resume' => ['j' => [$film('2', 'b', '/y/Filme/Taufe (2018)/Taufe.mkv')]]]],
    'settings' => ['path_mappings' => ['/x' => '@T@/user', '/y' => '@T@/nowhere']], 'instances' => ['em' => ['path_mappings' => []], 'je' => ['path_mappings' => ['/y' => '@T@/user']]], 'mappings' => null]);
$case('mappings: a path with .. never leaves the share', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/m/Filme/../../../etc/passwd'), $film('2', 'b', '/m/Filme/x/../Taufe (2018)/Taufe.mkv')]]]], 'expect' => $deck(0)]);
$case('mappings: a real file beyond the share stays out', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/m/../draussen/x.mkv')]]]], 'outside' => ['draussen/x.mkv' => 5], 'expect' => $deck(0)]);
$case('mappings: no mapping fits, odd slashes', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/nix/x.mkv'), $film('2', 'b', '/m//Filme/./Taufe (2018)//Taufe.mkv')]]]]]);
$case('a flat film in the library root and in the share root', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/m/Filme/Flach.mkv')]]]], 'expect' => $deck(2)]);
$case('a library below the share: its root is no film folder', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'a', '/lib/Flach2.mkv'), $film('2', 'b', '/lib/Ordner/Film.mkv')]]]],
    'mappings' => ['/lib' => '@T@/user/medien/Filme'], 'extra' => ['medien/Filme/Flach2.mkv' => 5, 'medien/Filme/Andere.mkv' => 6, 'medien/Filme/Ordner/Film.mkv' => 7, 'medien/Filme/Ordner/Film.nfo' => 8]]);
$case('a disc folder as the item; a series folder as the item', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'Rip', '/m/Filme/Rip (2000)'), ['Id' => 'x', 'Type' => 'Episode', 'Name' => 'e', 'Path' => '/m/Serien/Garten']]]]], 'extra' => ['Filme/Rip (2000)/VIDEO_TS/VTS_01_1.VOB' => 50, 'Filme/Rip (2000)/VIDEO_TS/VTS_01_0.IFO' => 5]]);
$case('exclusive share: only on the pool', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$film('1', 'p', '/m/Nur/Pool/p.mkv')]]]], 'cache' => ['Nur/Pool/p.mkv' => 77, 'Nur/Pool/p.srt' => 7], 'expect' => $deck(2)]);
$case('some files on the pool, some on the array, one on both', ['world' => $emby1, 'cache' => ['Filme/Ferien (2019)/Ferien.mkv' => 4000, 'Serien/Hausbau/Season 01/Hausbau S01E02.mkv' => 321, 'Serien/Hausbau/Season 01/Hausbau S01E03.srt' => 5, 'Filme/Taufe (2018)/Taufe.mkv' => 1999]]);
$case('what plays stays: by path and by name', ['world' => ['one' => ['type' => 'emby', 'key' => 'k1', 'sessions' => [['NowPlayingItem' => ['Path' => $B], 'UserName' => 'Leo'], ['NowPlayingItem' => ['Path' => '/elsewhere/Schulfest.mkv'], 'UserName' => 'x'], ['UserName' => 'idle'], ['NowPlayingItem' => null]]] + $mb],
    'list' => ['@T@/pool/Filme/Taufe (2018)/Taufe.mkv', '@T@/pool/Filme/Alt/alt.mkv'], 'cache' => ['Filme/Taufe (2018)/Taufe.mkv' => 2000, 'Filme/Alt/alt.mkv' => 500]]);

// ---------------------------------------------------- the list, the cleanup, share settings
$old = ['@T@/pool/Filme/Alt/alt.mkv', '@T@/pool/Filme/Alt/alt.srt', '@T@/pool/Prefer/x/x.mkv', '@T@/pool/Only/y/y.mkv', '@T@/pool/Nein/z/z.mkv', '@T@/pool/Ohne/w/w.mkv', '@T@/pool/Filme/gone.mkv', '/elsewhere/q.mkv', '# a comment', '', '  @T@/pool/Filme/Spaces/s.mkv  '];
$oldCache = ['Filme/Alt/alt.mkv' => 500, 'Filme/Alt/alt.srt' => 5, 'Prefer/x/x.mkv' => 11, 'Only/y/y.mkv' => 12, 'Nein/z/z.mkv' => 13, 'Ohne/w/w.mkv' => 14, 'Filme/Spaces/s.mkv' => 15];
$shareCfgs = ['Filme' => "shareUseCache=\"yes\"\nshareCachePool=\"pool\"\n", 'Serien' => "shareUseCache=\"yes\"\nshareCachePool=\"pool\"\n", 'Prefer' => "shareUseCache=\"prefer\"\nshareCachePool=\"pool\"\n", 'Only' => "shareUseCache=\"only\"\nshareCachePool=\"pool\"\n", 'Nein' => "shareUseCache=\"no\"\n"];
$case('cleanup: the move binary, share by share', ['world' => $emby1, 'list' => $old, 'cache' => $oldCache, 'shares' => $shareCfgs, 'expect' => $complete]);
$case('cleanup: rsync, whatever the share', ['world' => $emby1, 'list' => $old, 'cache' => $oldCache, 'shares' => $shareCfgs, 'settings' => ['cleanup_tool' => 'rsync']]);
$case('cleanup: skipped when the plan is incomplete', ['world' => $oneRaw(['/Users/u2/Items/Resume' => ['status' => 500]]), 'list' => $old, 'cache' => $oldCache, 'shares' => $shareCfgs, 'expect' => $inc]);
$case('cleanup: sessions don\'t answer: incomplete', ['world' => $oneRaw(['/Sessions' => ['status' => 503]]), 'list' => $old, 'cache' => $oldCache, 'expect' => $inc]);
$case('themes on: theme.mp3 stays out of a film, in the list protected', ['world' => $emby1, 'settings' => ['themes' => true], 'extra' => ['Filme/Ferien (2019)/theme.mp3' => 33], 'list' => ['@T@/pool/Filme/Taufe (2018)/theme.mp3'], 'cache' => ['Filme/Taufe (2018)/theme.mp3' => 34]]);
$case('themes off: theme.mp3 travels with its film', ['world' => $emby1, 'extra' => ['Filme/Ferien (2019)/theme.mp3' => 33], 'list' => ['@T@/pool/Filme/Taufe (2018)/theme.mp3'], 'cache' => ['Filme/Taufe (2018)/theme.mp3' => 34]]);

// ---------------------------------------------------- the pool: give back by rank, free space
$pool = fn (int $total, int $free): array => ['zfs' => ['tank' => [$total - $free, $free]]];
$onPool = ['Filme/Ferien (2019)/Ferien.mkv' => 4000, 'Filme/Ferien (2019)/Ferien.srt' => 10, 'Filme/Ferien (2019)/extras/Making of.mkv' => 90, 'Filme/Taufe (2018)/Taufe.mkv' => 2000, 'Filme/Schulfest (2020)/Schulfest.mkv' => 3000];
foreach ($H as $i => $e) {
    $onPool[substr($e['Path'], 3)] = 300 + 10 * $e['IndexNumber'] + $e['ParentIndexNumber'];
}
$give = ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => '20'];
$case('give back: pool above its limit, by rank', ['world' => $emby1, 'cache' => $onPool, 'env' => $give] + $pool(100000, 15000));
$case('give back: the watched count as free first', ['world' => $emby1, 'cache' => $onPool + ['Filme/Alt/alt.mkv' => 2000], 'list' => ['@T@/pool/Filme/Alt/alt.mkv'], 'env' => $give, 'shares' => $shareCfgs] + $pool(100000, 17000));
$case('give back: never below his half', ['world' => $emby1, 'cache' => $onPool, 'env' => $give + ['MEDIACACHE_KEEP_BYTES' => '6000']] + $pool(100000, 1000));
$case('give back: his half above what he holds', ['world' => $emby1, 'cache' => $onPool, 'env' => $give + ['MEDIACACHE_KEEP_BYTES' => '99999']] + $pool(100000, 1000));
$case('give back: incomplete, the cleanup counts nothing', ['world' => $oneRaw(['/Shows/NextUp' => ['status' => 500]]), 'cache' => $onPool + ['Filme/Alt/alt.mkv' => 2000], 'list' => ['@T@/pool/Filme/Alt/alt.mkv'], 'env' => $give, 'shares' => $shareCfgs, 'expect' => $inc] + $pool(100000, 15000));
$case('give back: what plays stays', ['world' => ['one' => ['type' => 'emby', 'sessions' => [['NowPlayingItem' => ['Path' => $C]]]] + $mb], 'cache' => $onPool, 'env' => $give] + $pool(100000, 1000));
$case('watched only: only the cleanup, what he holds counted', ['world' => $emby1, 'cache' => $onPool, 'env' => $give + ['MEDIACACHE_WATCHED_ONLY' => '1', 'MEDIACACHE_KEEP_BYTES' => '100']] + $pool(100000, 1000));
$case('min free: the fill stops, later ones wait', ['world' => $emby1, 'settings' => ['min_free_percent' => 20]] + $pool(100000, 23000));
$case('min free: the rounding at the very border', ['world' => $emby1, 'cache' => $onPool, 'env' => ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => '33.3']] + $pool(99999, 33299));
$case('min free 0: no limit', ['world' => $emby1, 'cache' => $onPool, 'env' => ['MEDIACACHE_GIVE_BACK_FREE' => '1', 'MEDIACACHE_MIN_FREE_PERCENT' => '0']] + $pool(100000, 1));
$case('a share dataset with a quota', ['world' => $emby1, 'zfs' => ['tank' => [1000, 100000], 'tank/Filme' => [10, 8000]], 'mounts' => ['tank/Filme @T@/pool/Filme zfs rw 0 0'], 'cache' => ['Filme/.keep' => 1]]);

// ---------------------------------------------------- share roots on the pool
$case('share root: missing, zfs, allowed: the dataset would be made', ['world' => $emby1, 'shares' => $shareCfgs, 'expect' => $hasFill]);
$case('share root: missing, zfs, no setting for the share: skipped', ['world' => $emby1, 'shares' => []]);
$case('share root: missing, wrong pool', ['world' => $emby1, 'shares' => ['Filme' => "shareUseCache=\"yes\"\nshareCachePool=\"other\"\n", 'Serien' => "shareUseCache=\"only\"\nshareCachePool=\"pool\"\n"]]);
$case('share root: missing, dataset there but not mounted', ['world' => $emby1, 'shares' => $shareCfgs, 'zfs' => ['tank' => [0, 500000000], 'tank/Filme' => [0, 500000000]]]);
$case('share root: a plain folder on zfs', ['world' => $emby1, 'shares' => $shareCfgs, 'cache' => ['Filme/.keep' => 1]]);
$case('share root: a plain folder while the dataset exists unmounted', ['world' => $emby1, 'shares' => $shareCfgs, 'cache' => ['Filme/.keep' => 1], 'zfs' => ['tank' => [0, 500000000], 'tank/Filme' => [0, 500000000]]]);
$case('share root: missing on xfs', ['world' => $emby1, 'fs' => 'xfs']);
$case('share root: missing, pool not mounted (unknown fs)', ['world' => $emby1, 'fs' => null]);
$case('share root: create_share_root off', ['world' => $emby1, 'settings' => ['create_share_root' => false], 'shares' => $shareCfgs]);
$case('share settings: defaults from share.cfg', ['world' => $emby1, 'list' => $old, 'cache' => $oldCache, 'defaults' => "shareUseCache=\"yes\"\nshareCachePool=\"pool\"\n"]);

// ---------------------------------------------------- answers that aren't there
$case('users: HTTP 500', ['world' => $oneRaw(['/Users' => ['status' => 500]]), 'expect' => $inc]);
$case('users: empty list: never «nobody watches»', ['world' => $oneRaw(['/Users' => ['json' => []]]), 'expect' => $inc]);
$case('users: null', ['world' => $oneRaw(['/Users' => ['body' => 'null']]), 'expect' => $inc]);
$case('users: empty body', ['world' => $oneRaw(['/Users' => ['body' => '']]), 'expect' => $inc]);
$case('users: {}', ['world' => $oneRaw(['/Users' => ['body' => '{}']]), 'expect' => $inc]);
$case('users: a dict with something', ['world' => $oneRaw(['/Users' => ['body' => '{"Id":"u1"}']]), 'expect' => $out('crash')]);
$case('users: a number', ['world' => $oneRaw(['/Users' => ['body' => '5']]), 'expect' => $out('crash')]);
$case('users: no Id', ['world' => $oneRaw(['/Users' => ['body' => '[{"Name":"Anna"}]']]), 'expect' => $out('crash')]);
$case('users: no Name', ['world' => $oneRaw(['/Users' => ['body' => '[{"Id":"u1"},{"Id":"u2","Name":null}]']])]);
$case('users: numbers as ids and names', ['world' => $oneRaw(['/Users' => ['body' => '[{"Id":17,"Name":42},{"Id":0,"Name":"Null"}]']])]);
$case('users: not JSON', ['world' => $oneRaw(['/Users' => ['body' => '<html>']]), 'expect' => $out('crash')]);
$case('users: no UTF-8', ['world' => $oneRaw(['/Users' => ['b64' => base64_encode("[{\"Id\":\"u1\",\"Name\":\"M\xfcller\"}]")]]), 'expect' => $out('crash')]);
$case('users: a BOM in front', ['world' => $oneRaw(['/Users' => ['body' => "\xef\xbb\xbf[]"]]), 'expect' => $out('crash')]);
$case('server: nothing listens', ['world' => [], 'servers' => [['servername' => 'off', 'type' => 'emby', 'url' => 'http://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/m' => '@T@/user']]], 'expect' => $inc]);
$case('server: a wrong key', ['world' => ['one' => ['type' => 'emby', 'key' => 'other'] + $mb], 'expect' => $inc]);
$case('server: one of two down', ['world' => $emby1, 'servers' => [['servername' => 'off', 'type' => 'jellyfin', 'url' => 'http://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/m' => '@T@/user']]], 'expect' => $inc]);
$case('server: an answer that takes too long', ['world' => $oneRaw(['/Users/u1/Items/Resume' => ['sleep' => 3, 'json' => ['Items' => []]]]), 'settings' => ['api_timeout' => 1], 'expect' => $inc]);
$case('resume: no Items key', ['world' => $oneRaw(['/Users/u1/Items/Resume|Movie' => ['json' => ['TotalRecordCount' => 0]]]), 'expect' => $inc]);
$case('resume: Items is no list', ['world' => $oneRaw(['/Users/u1/Items/Resume|Episode' => ['body' => '{"Items":{}}']]), 'expect' => $inc]);
$case('resume: a bare list', ['world' => $oneRaw(['/Users/u1/Items/Resume|Movie' => ['json' => [$film('fa', 'Ferien', $A)]]]), 'expect' => $complete]);
$case('resume: empty list for everybody', ['world' => ['one' => ['type' => 'emby', 'users' => $mb['users']]], 'list' => ['@T@/pool/Filme/Alt/alt.mkv'], 'cache' => ['Filme/Alt/alt.mkv' => 500], 'shares' => $shareCfgs, 'expect' => $all($complete, $deck(0))]);
$case('resume: items that are no dicts', ['world' => $oneRaw(['/Users/u1/Items/Resume|Movie' => ['body' => '{"Items":["x",1]}']]), 'expect' => $out('crash')]);
$case('next up: HTTP 500', ['world' => $oneRaw(['/Shows/NextUp' => ['status' => 500]]), 'expect' => $inc]);
$case('episodes: HTTP 500, the chain keeps what it has', ['world' => $oneRaw(['/Users/u1/Items' => ['status' => 500]]), 'expect' => $inc]);
$case('favourites: HTTP 500', ['world' => $oneRaw(['/Users/u2/Items?Recursive=true&IncludeItemTypes=Series&Filters=IsFavorite&Limit=10&Fields=Path' => ['status' => 500]]), 'expect' => $inc]);
$case('sessions: a dict', ['world' => $oneRaw(['/Sessions' => ['body' => '{"a":1}']]), 'expect' => $out('crash')]);
$case('sessions: Path is a number', ['world' => $oneRaw(['/Sessions' => ['body' => '[{"NowPlayingItem":{"Path":5}}]']]), 'expect' => $out('crash')]);
$case('plex: onDeck 500', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['status' => 500]]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'expect' => $inc]);
$case('plex: not JSON is its error', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['body' => '<xml/>']]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'expect' => $inc]);
$case('plex: no MediaContainer', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['body' => '{}'], '/status/sessions' => ['body' => '{"MediaContainer":null}']]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken']);
$case('plex: a list for a container', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['body' => '[1]']]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'expect' => $out('crash')]);
$case('plex: a lastViewedAt of text', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['body' => '{"MediaContainer":{"Metadata":[{"type":"movie","title":"x","lastViewedAt":"gestern"}]}}']]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken', 'expect' => $out('crash')]);
$case('plex: odd entries: no Media, a show, no type', ['world' => ['px' => ['raw' => ['/library/onDeck' => ['body' => '{"MediaContainer":{"Metadata":[{"type":"movie","title":"ohne"},{"type":"show","title":"s"},{"title":"t"},{"type":"episode","title":"e","ratingKey":9}]}}']]] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken']);
$case('plex: the account answers a list', ['world' => ['px' => $plex, 'plextv' => ['account' => [1, 2]] + $plextv], 'key' => 'plexadmintoken', 'expect' => $out('crash')]);
$case('plex: admin without id or names', ['world' => ['px' => $plex, 'plextv' => ['account' => new stdClass()] + $plextv], 'key' => 'plexadmintoken']);
$case('plex: no machine id', ['world' => ['px' => ['root' => new stdClass()] + $plex, 'plextv' => $plextv], 'key' => 'plexadmintoken']);

// ---------------------------------------------------- odd items
$oneItem = fn (array $resume, array $w = [], array $cs = []): array => $cs + ['world' => ['one' => $w + ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => $resume]]]];
$case('item: no Type, odd Type, no Id, no Name, no Path', $oneItem([['Name' => 'x', 'Path' => $A], ['Type' => 'Audio', 'Path' => $B], ['Type' => 'Movie', 'Path' => $C], ['Type' => 'Movie', 'Id' => 'y'], ['Type' => 'Movie', 'Id' => 'z', 'Name' => null, 'Path' => $D]]));
$case('item: a Virtual episode and film', $oneItem([$film('1', 'v', $A, ['LocationType' => 'Virtual']), $ep('H', 'Hausbau', 1, 1, $H[0]['Path'], ['LocationType' => 'Virtual'])], ['episodes' => ['H' => $H]]));
$case('item: a path that isn\'t there', $oneItem([$film('1', 'v', '/m/Filme/Nie/nie.mkv'), $film('2', 'a', $A)]));
$case('item: Path a number', $oneItem([$film('1', 'v', $A, ['Path' => 5])], [], ['expect' => $out('crash')]));
$case('item: Path empty, 0, false', $oneItem([$film('1', 'v', $A, ['Path' => '']), $film('2', 'v', $A, ['Path' => 0]), $film('3', 'v', $A, ['Path' => false])]));
$case('episode: no numbers: only its title', $oneItem([['Id' => 'e', 'Type' => 'Episode', 'Name' => 'Pilot', 'SeriesId' => 'H', 'SeriesName' => 'Hausbau', 'Path' => $H[0]['Path']]], ['episodes' => ['H' => $H]]));
$case('episode: no SeriesId, no SeriesName', $oneItem([['Id' => 'e', 'Type' => 'Episode', 'Name' => 'Pilot', 'ParentIndexNumber' => 1, 'IndexNumber' => 2, 'Path' => $H[1]['Path']]]));
$case('episode: SeriesName null, empty, number', $oneItem([$H[0], $ep('G', 'x', 1, 1, $G[0]['Path'], ['SeriesName' => null]), $ep('Q', 'x', 1, 2, $G[1]['Path'], ['SeriesName' => ''])], ['episodes' => []]));
$case('episode: SeriesName a number', $oneItem([$ep('G', 'x', 1, 1, $G[0]['Path'], ['SeriesName' => 7])], [], ['expect' => $out('crash')]));
$case('episode: numbers as text', $oneItem([$ep('H', 'Hausbau', 1, 1, $H[0]['Path'], ['ParentIndexNumber' => '1'])], ['episodes' => []], ['expect' => $out('crash')]));
$case('episode: numbers as floats', $oneItem([$ep('H', 'Hausbau', 1, 1, $H[0]['Path'], ['IndexNumber' => 1.5])], ['episodes' => []], ['expect' => $out('crash')]));
$case('episode: bool numbers, negative numbers', $oneItem([$ep('H', 'Hausbau', 1, 1, $H[0]['Path'], ['IndexNumber' => true]), $ep('G', 'Garten', 1, 1, $G[0]['Path'], ['IndexNumber' => -3])], ['episodes' => []]));
$case('episode: the series list out of order, unnumbered ones', $oneItem([$H[1]], ['episodes' => ['H' => [$H[7], $H[3], $ep('H', 'Hausbau', 0, 1, $H[5]['Path']), ['Id' => 'q', 'Name' => 'Special', 'Path' => $H[6]['Path']], $H[2]]]]));
$case('episode: Ids equal to the current one', $oneItem([$H[1]], ['episodes' => ['H' => [$H[2], ['Id' => $H[1]['Id'], 'ParentIndexNumber' => 2, 'IndexNumber' => 1, 'Path' => $H[4]['Path']]]]]));
$case('favourites: played ones left out, no Id', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'favs' => ['u' => [$series('H', 'Hausbau'), ['Type' => 'Series', 'Name' => 'Ohne Id']]], 'episodes' => ['H' => array_map(fn (array $e): array => ['UserData' => ['Played' => $e['IndexNumber'] < 3]] + $e, $H), '' => [$G[0]]]]], 'settings' => ['number_episodes' => 2]]);
$case('favourites: an Id that is a list', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'favs' => ['u' => [['Id' => [1], 'Name' => 'x']]]]], 'expect' => $out('crash')]);
$case('next up: other types, a series seen before', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('u', 'Anna')], 'resume' => ['u' => [$H[0]]], 'nextup' => ['u' => [$H[1], $film('m', 'kein', $A), $G[2]]], 'episodes' => ['H' => $H, 'G' => $G]]]]);
$case('the same film twice for one person, under two ids', $oneItem([$film('1', 'a', $A), $film('2', 'b', $A), $film('3', 'c', $A)]));
$case('two chains naming the same episode', $oneItem([$ep('X', 'Eins', 1, 1, $H[0]['Path']), $ep('Y', 'Zwei', 1, 1, $H[0]['Path'])], ['episodes' => ['X' => [$H[1]], 'Y' => [$H[1], $H[2]]]], ['settings' => ['cache_budget' => '1G']]));
$case('names: one person in three spellings, Hangul, ß', ['world' => ['em' => ['type' => 'emby', 'users' => [$u('a', 'Müller'), $u('h', '한글'), $u('s', 'Straße')], 'resume' => ['a' => [$film('1', 'a', $A)], 'h' => [$film('2', 'b', $B)], 's' => [$film('3', 'c', $C)]]], 'je' => ['type' => 'jellyfin', 'users' => [$u('a2', ' MU' . "\u{308}" . 'LLER'), $u('h2', "\u{1112}\u{1161}\u{11ab}\u{1100}\u{1173}\u{11af}"), $u('s2', 'STRASSE')], 'resume' => ['a2' => [$film('4', 'd', $D)], 's2' => [$film('3', 'c', $C)]]]], 'settings' => ['cache_budget' => '20K'], 'expect' => $all(fn (array $py): ?string => count($py['persons']) === 3 ? null : 'expected three persons')]);

// ---------------------------------------------------- unicode and odd names on the disk
$nfd = "Mu\u{308}ller Fe\u{301}te";
$nfc = "Müller Fête";
$case('unicode: NFD on the disk, NFC from the server: not found', $oneItem([$film('1', 'a', "/m/Filme/$nfc/$nfc.mkv"), $film('2', 'b', "/m/Filme/$nfd/$nfd.mkv")], [], ['extra' => ["Filme/$nfd/$nfd.mkv" => 21], 'expect' => $deck(1)]));
$case('unicode: both on the disk, order by code points', $oneItem([$film('1', 'a', "/m/Filme/$nfc/$nfc.mkv"), $film('2', 'b', "/m/Filme/Ordnung/x.mkv"), $film('3', 'c', "/m/Filme/$nfd/$nfd.mkv")], [], ['extra' => ["Filme/$nfd/$nfd.mkv" => 21, "Filme/$nfc/$nfc.mkv" => 22, 'Filme/Ordnung/x.mkv' => 1, 'Filme/Ordnung/x y/a.mkv' => 2, 'Filme/Ordnung/x/a.mkv' => 3, 'Filme/Ordnung/ä.srt' => 4, 'Filme/Ordnung/z.srt' => 5, 'Filme/Ordnung/😀.mkv' => 6, 'Filme/Ordnung/.versteckt' => 7, 'Filme/Ordnung/Z.srt' => 8, 'Filme/Ordnung/a b.srt' => 9, 'Filme/Ordnung/a-b.srt' => 10]]));
$case('unicode: an escaped surrogate pair and emoji in JSON', $oneItem([], [], ['world' => $oneRaw(['/Users/u1/Items/Resume|Movie' => ['body' => '{"Items":[{"Id":"1","Type":"Movie","Name":"\ud83d\ude00 Film","Path":"/m/Filme/Ordnung/\ud83d\ude00.mkv"}]}']]), 'extra' => ['Filme/Ordnung/😀.mkv' => 6]]));
$case('unicode: a lone surrogate in a Path and a Name', $oneItem([], [], ['world' => $oneRaw(['/Users/u1/Items/Resume|Movie' => ['body' => '{"Items":[{"Id":"1","Type":"Movie","Name":"a\ud83db","Path":"/m/Filme/\ud83d/x.mkv"},{"Id":"2","Type":"Movie","Name":"b\ude00","Path":"/m/Filme/Taufe (2018)/Taufe.mkv"}]}']])]));
$case('a file name that is no UTF-8', $oneItem([$film('1', 'a', '/m/Filme/Bytes/ok.mkv')], [], ['extra' => ['Filme/Bytes/ok.mkv' => 1, "Filme/Bytes/M\xfcller.srt" => 2, "Filme/Bytes/\xc3.srt" => 3, 'Filme/Bytes/ok.srt' => 4]]));
$case('stems: same beginning, dots, no extension, trickplay', $oneItem([$ep('H', 'H', 1, 1, '/m/Serien/Stem/E1.mkv'), $ep('G', 'G', 1, 2, '/m/Serien/Stem/.hidden'), $ep('K', 'K', 1, 3, '/m/Serien/Stem/plain'), $ep('L', 'L', 1, 4, '/m/Serien/Stem/ends.')], [], ['extra' => ['Serien/Stem/E1.mkv' => 1, 'Serien/Stem/E1.srt' => 2, 'Serien/Stem/E10.mkv' => 3, 'Serien/Stem/E1 - Teil 2.mkv' => 4, 'Serien/Stem/E1.trickplay/320 - 10x10/0.jpg' => 5, 'Serien/Stem/E1.trickplay/index.txt' => 6, 'Serien/Stem/.hidden' => 7, 'Serien/Stem/.hidden.nfo' => 8, 'Serien/Stem/plain' => 9, 'Serien/Stem/plain.srt' => 10, 'Serien/Stem/ends.' => 11, 'Serien/Stem/ends.nfo' => 12]]));
$case('symlinks: a folder not followed, a file taken, one dangling', $oneItem([$film('1', 'a', '/m/Filme/Links/l.mkv')], [], ['extra' => ['Filme/Links/l.mkv' => 1, 'Filme/Woanders/w.mkv' => 2, 'Filme/Woanders/sub/w2.mkv' => 3], 'links' => ['Filme/Links/ordner' => '../Woanders', 'Filme/Links/datei.mkv' => '../Woanders/w.mkv', 'Filme/Links/weg.mkv' => '../Woanders/nie.mkv']]));
$case('a newline, a tab, quotes and % in names', $oneItem([$film('1', 'a', "/m/Filme/Odd %20 'q' \"d\"/a\tb\nc.mkv")], [], ['extra' => ["Filme/Odd %20 'q' \"d\"/a\tb\nc.mkv" => 1, "Filme/Odd %20 'q' \"d\"/+plus&amp.srt" => 2]]));
$case('the list: \\r line ends, a theme mark, unicode spaces', ['world' => $emby1, 'listRaw' => "# USO themes: theme.mp3 kept\r\n@T@/pool/Filme/Alt/alt.mkv\r\n\u{a0}@T@/pool/Filme/Alt/alt.srt\u{2003}\n@T@/pool//Filme/Alt/./x.mkv\u{2028}#c\n", 'cache' => ['Filme/Alt/alt.mkv' => 500, 'Filme/Alt/alt.srt' => 5, 'Filme/Alt/x.mkv' => 6], 'shares' => $shareCfgs]);
$case('the list: no UTF-8', ['world' => $emby1, 'listRaw' => "@T@/pool/Filme/M\xfcller\n", 'expect' => $out('crash')]);
$case('the list: the pool itself', ['world' => $emby1, 'listRaw' => "@T@/pool\n", 'expect' => $out('crash')]);

// ---------------------------------------------------- huge answers
$bigEps = [];
$bigFiles = [];
for ($i = 1; $i <= 1200; $i++) {
    $p = sprintf('Serien/Gross/S%02d/E%04d', intdiv($i, 100), $i);
    $bigFiles["$p.mkv"] = 100 + $i;
    $bigEps[] = $ep('B', 'Gross', intdiv($i, 100), $i, "/m/$p.mkv");
}
$bigFilms = [];
for ($i = 1; $i <= 400; $i++) {
    $bigFiles[sprintf('Filme/Viele/F%04d/f.mkv', $i)] = 50 + $i;
    $bigFilms[] = $film("f$i", "F$i", sprintf('/m/Filme/Viele/F%04d/f.mkv', $i));
}
$case('huge: 1200 episodes, 400 films, 8 people, a budget', ['world' => ['one' => ['type' => 'emby', 'users' => array_map(fn (int $i): array => $u("u$i", "Person $i"), range(1, 8)),
    'resume' => array_combine(array_map(fn (int $i): string => "u$i", range(1, 8)), array_map(fn (int $i): array => array_merge(array_slice($bigFilms, $i * 40, 30), [$bigEps[$i * 100]]), range(1, 8))), 'episodes' => ['B' => $bigEps]]],
    'extra' => $bigFiles, 'settings' => ['cache_budget' => '200K', 'max_resume_movies' => 25]]);

// ---------------------------------------------------- settings
$settingsCase = fn (array $s, ?callable $x = null): array => ['world' => $emby1, 'settings' => $s, 'expect' => $x ?? $out('config')];
$case('settings: min_free_percent is no number', $settingsCase(['min_free_percent' => 'viel']));
$case('settings: mover_debug_level null', $settingsCase(['mover_debug_level' => null]));
$case('settings: a bad size', $settingsCase(['cache_budget' => '12X']));
$case('settings: a bad movie_mode', $settingsCase(['movie_mode' => 'x']));
$case('settings: share out of range', $settingsCase(['movie_share_percent' => 101]));
$case('settings: no instances', $settingsCase(['instances' => []]));
$case('settings: an unknown type', ['world' => [], 'servers' => [['servername' => 'x', 'type' => 'kodi', 'url' => 'http://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/m' => '/x']]], 'expect' => $out('config')]);
$case('settings: no key', ['world' => [], 'servers' => [['type' => 'emby', 'url' => 'http://127.0.0.1:9', 'path_mappings' => ['/m' => '/x']]], 'expect' => $out('config')]);
$case('settings: only empty mappings', ['world' => [], 'servers' => [['type' => 'emby', 'url' => 'http://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/m' => '']]], 'expect' => $out('config')]);
$case('settings: no pool folder', $settingsCase(['cache_path' => '/nonexistent/pool']));
$case('settings: numbers as text, sizes in odd spellings', $settingsCase(['min_free_percent' => ' 12.5 ', 'max_resume_movies' => '3', 'cache_budget' => ' 2.5 t', 'movie_share_percent' => '1_0', 'parallel_total' => '0'], $complete));
$case('settings: path_mappings as an empty list (how the setup saves none)', $settingsCase(['path_mappings' => []], $complete));
$case('settings: path_mappings as a list of pairs, odd ones', ['world' => $emby1, 'settings' => ['path_mappings' => [['/n', '/mnt/user/n']]]]);
$case('settings: path_mappings a number', $settingsCase(['path_mappings' => 5], $out('crash')));
$case('settings: a dict of people with budgets, without', $settingsCase(['cache_budget' => '50K', 'valid_users' => ['u1' => ['budget' => 1000], 'u2' => null]], $complete));
$case('settings: themes only as a real true', $settingsCase(['themes' => 'true'], $complete) + ['extra' => ['Filme/Ferien (2019)/theme.mp3' => 33]]);
$case('env: MEDIACACHE_KEEP_BYTES no number', ['world' => $emby1, 'env' => ['MEDIACACHE_KEEP_BYTES' => 'x'], 'expect' => $out('config')]);
$case('env: a budget from outside', ['world' => $emby1, 'env' => ['MEDIACACHE_CACHE_BUDGET' => '3K']]);
$case('array_source disk: the source is the disk', ['world' => $emby1, 'settings' => ['array_source' => 'disk'], 'disks' => ['disk1' => ['Filme/Ferien (2019)/Ferien.mkv' => 4000], 'disk2' => ['Filme/Ferien (2019)/Ferien.mkv' => 4000, 'Filme/Taufe (2018)/Taufe.mkv' => 2000]]]);

// ---------------------------------------------------- the review (2026-11-08): what the real runs do, orders, odd settings
$case('no fill (the joint run\'s first step): give back and clean up, fetch nothing', ['world' => $emby1, 'cache' => $onPool + $oldCache, 'list' => $old, 'env' => $give + ['MEDIACACHE_NO_FILL' => '1']] + $pool(100000, 15000));
$case('no fill: what waits on the array only counted', ['world' => $emby1, 'env' => ['MEDIACACHE_NO_FILL' => '1'], 'expect' => fn (array $py): ?string => ($py['fill']['waiting'] ?? 0) > 0 && !$py['fill']['rels'] ? null : 'expected only waiting']);
$case('release: everything on the list back, what plays stays', ['mode' => 'release', 'world' => ['one' => ['type' => 'emby', 'sessions' => [['NowPlayingItem' => ['Path' => '/m/Filme/Alt/alt.mkv']]]] + $mb], 'list' => $old, 'cache' => $oldCache, 'expect' => fn (array $py): ?string => count($py['cleanup']['paths'] ?? []) > 2 ? null : 'expected a cleanup']);
$case('release: a server not answering — what plays can\'t be told', ['mode' => 'release', 'world' => $oneRaw(['/Sessions' => ['status' => 500]]), 'list' => $old, 'cache' => $oldCache, 'expect' => $inc]);
$case('release: themes stay', ['mode' => 'release', 'world' => $emby1, 'settings' => ['themes' => true], 'list' => ['@T@/pool/Filme/Taufe (2018)/theme.mp3', '@T@/pool/Filme/Alt/alt.mkv'], 'cache' => ['Filme/Taufe (2018)/theme.mp3' => 34, 'Filme/Alt/alt.mkv' => 500]]);
$case('release: themes go too (RELEASE_THEMES)', ['mode' => 'release', 'world' => $emby1, 'settings' => ['themes' => true], 'env' => ['MEDIACACHE_RELEASE_THEMES' => '1'], 'list' => ['@T@/pool/Filme/Taufe (2018)/theme.mp3', '@T@/pool/Filme/Alt/alt.mkv'], 'cache' => ['Filme/Taufe (2018)/theme.mp3' => 34, 'Filme/Alt/alt.mkv' => 500]]);
$case('order: names that are no UTF-8 beside UTF-8 ones (surrogateescape)', $oneItem([$film('1', 'a', '/m/Filme/Gemischt/Don’t.mkv')], [], ['extra' => ["Filme/Gemischt/Don’t.mkv" => 1, "Filme/Gemischt/Don\x92t.srt" => 2, "Filme/Gemischt/Don\xc0t.nfo" => 3, "Filme/Gemischt/Don😀t.jpg" => 4, "Filme/Gemischt/Don\xfft.x" => 5, "Filme/Gemischt/Don\u{e000}t.y" => 6]]));
$case('order: give back inside an item with such names', $oneItem([$film('1', 'a', '/m/Filme/Gemischt/Don’t.mkv')], [], ['extra' => ["Filme/Gemischt/Don’t.mkv" => 1000, "Filme/Gemischt/Don\x92t.srt" => 2000],
    'cache' => ["Filme/Gemischt/Don’t.mkv" => 1000, "Filme/Gemischt/Don\x92t.srt" => 2000], 'env' => $give] + $pool(100000, 19500)));
$case('names: numbers as names at the same rank (who gives back)', ['world' => ['one' => ['type' => 'emby', 'users' => [$u('a', '10'), $u('b', '9'), $u('c', '1e3'), $u('d', '999')],
    'resume' => ['a' => [$film('1', 'x', $A)], 'b' => [$film('1', 'x', $A)], 'c' => [$film('1', 'x', $A)], 'd' => [$film('1', 'x', $A)]]]], 'cache' => $onPool, 'env' => $give] + $pool(100000, 1000));
$case('names: letters newer folds than Python\'s Unicode', ['world' => ['em' => ['type' => 'emby', 'users' => [$u('a', "\u{1c89}x"), $u('b', "\u{a7cb}y")], 'resume' => ['a' => [$film('1', 'a', $A)], 'b' => [$film('2', 'b', $B)]]], 'je' => ['type' => 'jellyfin', 'users' => [$u('c', "\u{1c8a}x"), $u('d', "\u{264}y")], 'resume' => ['c' => [$film('3', 'c', $C)], 'd' => [$film('4', 'd', $D)]]]]]);
$case('floats: ids and titles as floats', $oneItem([$film('x', 'a', $A, ['Id' => 1.0e-5, 'Name' => -0.0]), $film('y', 'b', $B, ['Id' => 1.0e16, 'Name' => 123.25]), $film('z', 'c', $C, ['Id' => 0.0001, 'Name' => 1.5e300])]));
$case('settings: api_timeout null (no timeout)', $settingsCase(['api_timeout' => null], $complete));
$case('settings: api_timeout as text', $settingsCase(['api_timeout' => '10'], $out('crash')));
$case('settings: api_timeout negative', $settingsCase(['api_timeout' => -1], $out('crash')));
$case('settings: a size of nan', $settingsCase(['cache_budget' => 'nanG']));
$case('settings: a size of inf', $settingsCase(['cache_budget' => 'infG'], $out('crash')));
$case('settings: shares_cfg_dir null', ['world' => $emby1, 'settings' => ['shares_cfg_dir' => null], 'shares' => [], 'expect' => $out('crash')]);
$case('settings: a server\'s url null', ['world' => [], 'servers' => [['type' => 'emby', 'url' => null, 'api_key' => 'x', 'path_mappings' => ['/m' => '/x']]], 'expect' => $out('crash')]);
$case('settings: a server\'s url without http', ['world' => [], 'servers' => [['type' => 'emby', 'url' => 'ftp://127.0.0.1:9', 'api_key' => 'x', 'path_mappings' => ['/m' => '@T@/user']]], 'expect' => $inc]);
$case('plex.tv: every token form scrubbed from the tape', ['world' => ['px' => $plex, 'plextv' => ['account' => ['id' => 111, 'title' => 'Chef', 'authToken' => 'secret-account-token', 'services' => [['token' => 'svc-token-1']], 'subscription' => ['plexToken' => 'svc-token-1']],
    'shared' => "<MediaContainer><SharedServer userID='222' username='Freund' accessToken='single-quoted-tok' inviteToken=\"other-attr-tok\"/><SharedServer userID=\"223\" username=\"Zwei\" accessToken=\"friendtoken\"/></MediaContainer>"] + $plextv], 'key' => 'plexadmintoken',
    'expect' => fn (array $py): ?string => count($py['persons'] ?? []) === 3 ? null : 'expected the admin and two friends']);

// ======================================================================== run them

$only = array_values(array_filter($argvRest, fn (string $a): bool => $a !== '--list'));
if (in_array('--list', $argvRest, true)) {
    foreach (array_keys($cases) as $n) {
        say($n);
    }
    $shutdown();
    exit(0);
}
$failed = $ran = 0;
foreach ($cases as $name => $c) {
    if ($only && !array_filter($only, fn (string $o): bool => str_contains($name, $o))) {
        continue;
    }
    $ran++;
    $t = "$tmp/case";
    exec('rm -rf ' . escapeshellarg($t));
    mkdir("$t/data/logs", 0777, true);
    mkdir("$t/user0", 0777, true);
    mkdir("$t/pool", 0777, true);
    mkdir("$t/user", 0777, true);
    mkdir("$t/cfg/shares", 0777, true);
    tree("$t/user0", ($c['extra'] ?? []) + $files);
    tree("$t/pool", $c['cache'] ?? []);
    tree($t, $c['outside'] ?? []);
    foreach ($c['links'] ?? [] as $rel => $to) {
        symlink($to, "$t/user0/$rel");
    }
    foreach ($c['disks'] ?? [] as $disk => $f) {
        mkdir("$t/$disk");
        tree("$t/$disk", $f);
    }
    foreach ($c['shares'] ?? $shareCfgs as $share => $text) {
        file_put_contents("$t/cfg/shares/$share.cfg", $text);
    }
    if (isset($c['defaults'])) {
        file_put_contents("$t/cfg/share.cfg", $c['defaults']);
    }
    $sub = fn (string $s): string => str_replace('@T@', $t, $s);
    // the servers
    $world = $c['world'];
    file_put_contents($worldFile, json_encode($world, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    $instances = [];
    $maps = array_key_exists('mappings', $c) ? ($c['mappings'] ?? []) : ['/m' => "$t/user"];
    foreach ($world as $srv => $w) {
        if (($w['type'] ?? '') === 'plextv') {
            continue;
        }
        $instances[] = ['servername' => $srv, 'type' => $w['type'], 'url' => "$base/$srv", 'api_key' => $c['keys'][$srv] ?? $c['key'] ?? 'k1', 'path_mappings' => array_map($sub, $maps)] + ($c['instance'] ?? [])
            + (isset($c['instances'][$srv]) ? array_map(fn ($m) => is_array($m) ? array_map($sub, $m) : $m, $c['instances'][$srv]) : []);
    }
    foreach ($c['instances'] ?? [] as $srv => $over) {
        foreach ($instances as &$i) {
            if ($i['servername'] === $srv) {
                $i['path_mappings'] = array_map($sub, $over['path_mappings']);
            }
        }
        unset($i);
    }
    foreach ($c['servers'] ?? [] as $x) {
        $x['path_mappings'] = array_map($sub, $x['path_mappings']);
        $instances[] = $x;
    }
    $settings = ['cache_path' => "$t/pool", 'array_path' => "$t/user0", 'user_path' => "$t/user", 'array_disks_glob' => "$t/disk[0-9]*", 'shares_cfg_dir' => "$t/cfg/shares",
        'instances' => $instances, 'min_free_percent' => 1, 'api_timeout' => 5];
    foreach ($c['settings'] ?? [] as $k => $v) {
        if ($k === 'path_mappings' && is_array($v)) {
            $v = array_map(fn (mixed $m): mixed => is_string($m) ? $sub($m) : $m, $v);
        }
        $settings[$k] = $v;
    }
    file_put_contents("$t/data/mediacache_settings.json", json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if (isset($c['list'])) {
        file_put_contents("$t/data/mediacache_exclude.txt", implode("\n", array_map($sub, $c['list'])) . "\n");
    }
    if (isset($c['listRaw'])) {
        file_put_contents("$t/data/mediacache_exclude.txt", $sub($c['listRaw']));
    }
    // the pool: a stand-in zfs, a stand-in /proc/mounts
    $zfs = $c['zfs'] ?? ['tank' => [0, 500000000]];
    $fs = array_key_exists('fs', $c) ? $c['fs'] : 'zfs';
    $mounts = $fs === null ? '' : ($fs === 'zfs' ? "tank $t/pool zfs rw 0 0\n" : "/dev/sdx1 $t/pool $fs rw 0 0\n");
    foreach ($c['mounts'] ?? [] as $line) {
        $mounts .= $sub($line) . "\n";
    }
    file_put_contents("$t/mounts", $mounts);
    $table = '';
    foreach ($zfs as $name2 => [$used, $avail]) {
        $table .= "$name2 $used $avail\n";
    }
    file_put_contents("$t/zfs.txt", $table);
    file_put_contents("$t/zfs", "#!/bin/bash\n# list -Hp -o used,avail NAME | list -H -o name NAME\nname=\${@: -1}\nline=\$(grep -F \"\$name \" $t/zfs.txt | head -1)\n[ -n \"\$line\" ] || exit 1\nset -- \$1 \$2 \$3 \$4 \$5\nif [ \"\$4\" = name ]; then echo \"\$name\"; else echo \"\$line\" | awk '{print \$2\"\\t\"\$3}'; fi\n");
    chmod("$t/zfs", 0755);
    $env = ['MEDIACACHE_DIR' => "$t/data", 'MEDIACACHE_MOUNTS' => "$t/mounts", 'MEDIACACHE_ZFS' => "$t/zfs", 'MEDIACACHE_SHADOW_PLEX_TV' => "$base/plextv"] + array_map(fn ($v) => is_string($v) ? $sub($v) : $v, $c['env'] ?? []);
    // settings with no pool folder: as asked
    $look = function () use ($t): string {
        exec('find ' . escapeshellarg("$t/user0") . ' ' . escapeshellarg("$t/pool") . ' ' . escapeshellarg("$t/data") . ' -printf "%p %s %T@\n" | grep -v "/mediacache.lock\|/data " | sort | md5sum', $o);
        return (string) ($o[0] ?? '');
    };
    $before = $look();
    // the key never on the tape: the instance's, the account's and the friend's tokens
    $r = threePlans($env, $t, "$base/plextv", $logFile, ['"k1"', 'plexadmintoken', 'friendtoken', 'secret-account-token', 'svc-token-1', 'single-quoted-tok', 'other-attr-tok'], $c['mode'] ?? 'run');
    $bad = isset($c['expect']) ? ($c['expect'])($r['py']) : null;
    if ($look() !== $before) {
        $bad = 'something changed in the array, the pool or his data';
    }
    $fine = report($name, $r, $bad);
    if (!$fine) {
        $failed++;
    }
}

// ---------------------------------------------------- the client's paging: every film and series, Python's library_items() and PHP's libraryItems()
if (!$only || in_array('paging', $only, true)) {
    $lib = [];
    for ($i = 0; $i < 4500; $i++) {
        $lib[] = $i % 97 === 0 ? ['Id' => "x$i", 'Type' => 'Audio'] : ['Id' => "i$i", 'Type' => $i % 3 ? 'Movie' : 'Series', 'Path' => "/m/Ä$i/" . ($i % 5 ? 'a.mkv' : ''), 'DateCreated' => '2026-01-01'];
    }
    $sections = [['key' => '1', 'type' => 'movie'], ['key' => '2', 'type' => 'show'], ['key' => '3', 'type' => 'artist'], ['type' => 'movie']];
    $pm = ['type' => 'plex', 'sections' => $sections, 'section' => ['1' => [['ratingKey' => 5, 'Media' => [['Part' => [['file' => '/m/x.mkv']]]]], ['title' => 'no key']], '2' => [['ratingKey' => '7', 'Location' => [['path' => '/m/S']]], ['ratingKey' => 8]]]];
    file_put_contents($worldFile, json_encode(['lib' => ['type' => 'emby', 'library' => $lib], 'exact' => ['type' => 'jellyfin', 'library' => array_slice($lib, 0, 4000)], 'bad' => ['type' => 'emby'] + $raw(['/Items' => ['body' => '{}']]), 'px' => $pm], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $py = <<<'PY'
import sys, json
sys.path.insert(0, sys.argv[1])
import mediacache_lib as l
out = {}
for name, typ in (("lib", "emby"), ("exact", "jellyfin"), ("bad", "emby"), ("px", "plex")):
    api = l.MediaServerApi.for_instance({"url": sys.argv[2] + "/" + name, "api_key": "k", "type": typ, "days_to_monitor": 99}, 5)
    try:
        out[name] = [[i["Id"], i["Type"], i["Path"]] for i in api.library_items()]
    except RuntimeError:
        out[name] = "error"
print(json.dumps(out))
PY;
    mkdir("$tmp/pg");
    file_put_contents("$tmp/pg/p.py", $py);
    @unlink($logFile);
    exec('MEDIACACHE_DIR=' . escapeshellarg("$tmp/pg") . ' PYTHONDONTWRITEBYTECODE=1 python3 -I ' . escapeshellarg("$tmp/pg/p.py") . ' ' . escapeshellarg($GLOBALS['app']) . ' ' . escapeshellarg($base), $o);
    $want = json_decode((string) end($o), true);
    $pyAsked = file_get_contents($logFile);
    @unlink($logFile);
    $host = new JackPlanLive([]);
    $got = [];
    foreach (['lib' => 'emby', 'exact' => 'jellyfin', 'bad' => 'emby', 'px' => 'plex'] as $name => $typ) {
        try {
            $got[$name] = array_map(fn (array $i): array => [$i['Id'], $i['Type'], $i['Path']],
                JackPlanServer::forInstance(['url' => "$base/$name", 'api_key' => 'k', 'type' => $typ, 'days_to_monitor' => 99], 5, $host)->libraryItems());
        } catch (JackPlanApiError) {
            $got[$name] = 'error';
        }
    }
    $same = $want === json_decode((string) json_encode($got), true) && $pyAsked === file_get_contents($logFile);
    say(($same ? 'same  ' : 'DIFF  ') . sprintf('paging: %d + %d + %d items, an answer without a list, %d requests', count($want['lib'] ?? []), count($want['exact'] ?? []), count($want['px'] ?? []), substr_count((string) $pyAsked, "\n")));
    if (!$same) {
        $failed++;
    }
    $ran++;
}
$shutdown();
say(($failed ? "$failed of $ran DIFFER" : "all $ran the same"));
exit($failed ? 1 : 0);
