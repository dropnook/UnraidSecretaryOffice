<?php
declare(strict_types=1);

/*
 * The Night Watchman's signs in the log (1.96, the concept «Der Nachtwächter in 1.96», 11.3 H). He reads the syslog every
 * round from his place anyway (watchmanReadFile()); besides the logins, each line now meets the shared patterns of
 * agent/lib/logsigns.php. What a pattern finds:
 *   host_oom    the kernel ended a program because the memory was full                         (area host, quiet)
 *   host_crash  a service of the server (sshd, smbd, nginx, php-fpm, emhttpd, dockerd) crashed
 *               WATCH_SIGN_CRASHES times within an hour: an attack, a fault or failing memory    (area host, quiet)
 *   hw_io       read or write errors of a disk (block layer, XFS, btrfs, ext4, an NVMe timeout) (area hw, told)
 *   host_flood  very many connection attempts on a port (SYN cookies), the connection table full (area host, quiet)
 * One entry a pattern, thing and day (key <kind>:<thing>:<day>), counting up — also once noted —, with up to
 * WATCH_SIGN_LINES cleaned lines as evidence (logsignsClean(): no time, no host, no addresses). A kernel's stumble and
 * «[Hardware Error]» are the hardware step's evidence (hw_kernel, hw_mce): counted in state.json `signs.evidence`, not
 * booked here. Taken over anew: nothing (what is in the log before he watches is history).
 * Only lines of this server: a line naming another host (a misrouted remote syslog) is left out.
 */

// the services whose repeated crash is worth a line — a program's name as the kernel gives it (php-fpm with its version)
const WATCH_SIGN_SERVICES = '/^(?:sshd|sshd-session|smbd|nginx|php-fpm[0-9.]{0,6}|emhttpd|dockerd)$/D';
const WATCH_SIGN_CRASHES  = 3;          // a service's crashes …
const WATCH_SIGN_WINDOW   = 3600;       // … within an hour: host_crash
const WATCH_SIGN_MAX      = 500;        // signs taken from one round's lines at most (more: counted, left)
const WATCH_SIGN_LINES    = 3;          // evidence lines an entry keeps

/** A disk's device as the log names it → its base device and Unraid's name for it (disks.ini), when it has one */
function watchmanSignDisk(string $dev, array $disks): array
{
    $base = preg_match('/^(nvme\d+n\d+)(?:p\d+)?$/D', $dev, $m) ? $m[1]
        : (preg_match('/^(sd[a-z]{1,3}|vd[a-z]{1,3}|hd[a-z]{1,3})\d*$/D', $dev, $m) ? $m[1] : $dev);
    // the array's md devices (md1p1, md1): disk1
    if (preg_match('/^md(\d{1,2})(?:p\d+)?$/D', $dev, $m)) {
        return ['dev' => $dev, 'disk' => 'disk' . $m[1]];
    }
    foreach ($disks as $name => $d) {
        $their = (string) ($d['device'] ?? '');
        // an NVMe timeout names the controller (nvme0): its namespace in disks.ini (nvme0n1)
        if ($their !== '' && ($their === $base || (preg_match('/^nvme\d{1,3}$/D', $base) && str_starts_with($their, "{$base}n")))) {
            return ['dev' => $base, 'disk' => (string) $name];
        }
    }
    return ['dev' => $base, 'disk' => null];
}

/**
 * One entry a kind, thing and day: counted up wherever it stands (open or noted), the newest evidence lines kept
 *
 * @return bool  booked new
 */
function watchmanSignBump(array &$book, string $kind, string $key, int $time, int $add, array $p, string $line): bool
{
    foreach ($book as $i => $e) {
        if (($e['key'] ?? '') === $key && $e['kind'] === $kind) {
            $book[$i]['count'] = (int) $e['count'] + $add;
            $book[$i]['last'] = max((int) $e['last'], $time);
            $lines = (array) ($e['p']['lines'] ?? []);
            if ($line !== '' && !in_array($line, $lines, true)) {
                $lines = array_slice([...$lines, $line], -WATCH_SIGN_LINES);
            }
            $book[$i]['p'] = ['lines' => $lines] + $p;
            return false;
        }
    }
    $book[] = watchmanEntry($kind, $key, $time, ['lines' => $line === '' ? [] : [$line]] + $p, $add);
    return true;
}

/**
 * This round's signs ($signs: watchmanReadFile()'s, in the log's order) into his book. $host: this server's name (lower
 * case; '' — not known: every line counts). $disks: disks.ini's sections (a device's name). $st['signs']: the services'
 * recent crashes (for the hour's rule) and the hardware step's evidence.
 *
 * @return list<string>  the kinds booked new
 */
function watchmanSignsCompare(array &$book, array &$st, array $signs, string $host, array $disks, int $now): array
{
    $mine = is_array($st['signs'] ?? null) ? $st['signs'] : [];
    $crash = [];
    foreach ((array) ($mine['crash'] ?? []) as $prog => $times) {
        $keep = array_values(array_filter((array) $times, fn ($t) => is_int($t) && $t > $now - WATCH_SIGN_WINDOW && $t <= $now + 300));
        if ($keep) {
            $crash[(string) $prog] = $keep;
        }
    }
    $evidence = is_array($mine['evidence'] ?? null) ? $mine['evidence'] : [];
    $added = [];
    foreach ($signs as $s) {
        if (!is_array($s) || !is_string($s['kind'] ?? null)) {
            continue;
        }
        $lineHost = $s['host'] ?? null;
        if ($host !== '' && is_string($lineHost) && $lineHost !== $host && !str_starts_with($lineHost, "$host.")) {
            continue;                       // another host's line in this syslog: not this server's
        }
        $t = is_int($s['time'] ?? null) && $s['time'] <= $now + 300 ? $s['time'] : $now;
        $day = date('Y-m-d', $t);
        $thing = (string) ($s['thing'] ?? '');
        $line = (string) ($s['line'] ?? '');
        $new = false;
        switch ($s['kind']) {
            case 'host_oom':
                $new = watchmanSignBump($book, 'host_oom', "host_oom:$thing:$day", $t, 1, ['prog' => $thing], $line);
                break;
            case 'host_crash':
                if (!preg_match(WATCH_SIGN_SERVICES, $thing)) {
                    break;                  // a program of no service: its own business (a container's, a user's)
                }
                $crash[$thing] = array_slice([...($crash[$thing] ?? []), $t], -20);
                $recent = count(array_filter($crash[$thing], fn ($x) => $x > $t - WATCH_SIGN_WINDOW));
                if ($recent < WATCH_SIGN_CRASHES) {
                    break;
                }
                // the first time: all the hour's crashes; after that one each
                $key = "host_crash:$thing:$day";
                $add = in_array($key, array_column($book, 'key'), true) ? 1 : $recent;
                $new = watchmanSignBump($book, 'host_crash', $key, $t, $add, ['prog' => $thing], $line);
                break;
            case 'hw_io':
                $d = watchmanSignDisk($thing, $disks);
                $new = watchmanSignBump($book, 'hw_io', "hw_io:{$d['dev']}:$day", $t, 1, $d, $line);
                break;
            case 'host_flood':
                $table = $s['sign'] === 'conntrack';
                $what = $table ? 'conntrack' : "port:$thing";
                $new = watchmanSignBump($book, 'host_flood', "host_flood:$what:$day", $t, 1, ['port' => $table ? null : (int) $thing], $line);
                break;
            case 'hw_kernel':
            case 'hw_mce':
                $ev = is_array($evidence[$s['kind']] ?? null) ? $evidence[$s['kind']] : ['n' => 0];
                $evidence[$s['kind']] = ['n' => (int) ($ev['n'] ?? 0) + 1, 'last' => $t, 'sign' => (string) $s['sign'], 'line' => $line];
                break;
        }
        if ($new) {
            $added[] = $s['kind'];
        }
    }
    $st['signs'] = ['crash' => $crash, 'evidence' => $evidence];
    return $added;
}

/** An entry's words */
function watchmanSignText(array $e): array
{
    $p = (array) ($e['p'] ?? []);
    return match ((string) $e['kind']) {
        'host_oom', 'host_crash' => ['prog' => (string) ($p['prog'] ?? '') ?: '?'],
        'hw_io'      => ['disk' => is_string($p['disk'] ?? null) && $p['disk'] !== '' ? "{$p['disk']} (" . (string) ($p['dev'] ?? '') . ')' : ((string) ($p['dev'] ?? '') ?: '?')],
        'host_flood' => ['port' => isset($p['port']) && is_int($p['port']) ? (string) $p['port'] : '–'],
        default      => [],
    };
}

/** Its words' shape: the connection table full (entry.host_flood_table) */
function watchmanSignVariant(array $e): ?string
{
    return ($e['kind'] ?? '') === 'host_flood' && array_key_exists('port', (array) ($e['p'] ?? [])) && $e['p']['port'] === null ? 'host_flood_table' : null;
}
