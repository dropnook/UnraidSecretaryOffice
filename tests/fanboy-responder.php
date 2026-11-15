<?php
declare(strict_types=1);

/*
 * Fans that answer — for Fanboy's tests only (tests/run.php requires it; `php tests/fanboy-responder.php <root>
 * <spec.json>` runs it in the background for the tests with processes of their own). In a fake tree made by
 * fanboyFakeTree() each tach input follows the output it hangs on: its rpm moves towards the value's rpm with a lag,
 * a fan stalls below its stall value and starts again only at its start value, the chip's own mode (enable ≥ 2) runs
 * it at its «auto» rpm, enable 0 at full. An output without a tach just has no entry; a hub: two inputs on one output.
 *
 *   $spec = ['hwmon' => 'hwmon10',                         the chip's folder under <root>/sys/class/hwmon
 *            'fans'  => [tach => ['output' => n, 'max' => rpm at 255, 'stall' => 0–1 (below: stops),
 *                                 'start' => 0–1 (from standing: needs this), 'auto' => rpm in the chip's mode,
 *                                 'lag' => seconds, 'stuck' => true (never turns), 'inverted' => true (more is slower),
 *                                 'auto_dead' => true (the chip's mode does nothing: the value counts, it87 #56),
 *                                 'bios' => true (the BIOS runs it whatever is written: its «auto» rpm)]],
 *            'revert' => [output => enable]]       the BIOS puts that output's mode back after every write (round 5)
 */

function fanboyFakeRpmTarget(array $f, ?int $value, ?int $enable, int $now): int
{
    if (!empty($f['stuck'])) {
        return 0;
    }
    if (!empty($f['bios']) || ($enable !== null && $enable >= 2 && empty($f['auto_dead']))) {
        return (int) ($f['auto'] ?? 1200);
    }
    $d = $enable === 0 ? 1.0 : max(0, min(255, $value ?? 0)) / 255;
    if (!empty($f['inverted'])) {
        $d = 1.0 - $d;
    }
    $stall = (float) ($f['stall'] ?? 0.2);
    $start = (float) ($f['start'] ?? max($stall, 0.3));
    if ($d < $stall || ($now < 50 && $d < $start)) {
        return 0;
    }
    return (int) round(($f['max'] ?? 1800) * (0.2 + 0.8 * $d));
}

/** One step of $dt seconds: every tach input of the spec a bit closer to what its output asks */
function fanboyFakeRespond(string $root, array $spec, float $dt): void
{
    $dir = "$root/sys/class/hwmon/" . $spec['hwmon'];
    foreach ($spec['revert'] ?? [] as $n => $enable) {
        if (is_file("$dir/pwm{$n}_enable") && trim((string) @file_get_contents("$dir/pwm{$n}_enable")) !== (string) $enable) {
            @file_put_contents("$dir/pwm{$n}_enable", "$enable\n");
        }
    }
    foreach ($spec['fans'] as $tach => $f) {
        $n = $f['output'];
        $read = function (string $file): ?int {
            $s = @file_get_contents($file);
            return is_string($s) && preg_match('/^\s*(-?\d+)\s*$/D', $s, $m) ? (int) $m[1] : null;
        };
        if (!is_dir($dir)) {
            return;
        }
        $now = $read("$dir/fan{$tach}_input") ?? 0;
        $want = fanboyFakeRpmTarget($f, $read("$dir/pwm$n"), is_file("$dir/pwm{$n}_enable") ? $read("$dir/pwm{$n}_enable") : 1, $now);
        $lag = max(0.01, (float) ($f['lag'] ?? 1.0));
        $next = $now + ($want - $now) * min(1.0, $dt / $lag);
        if (abs($want - $next) < 15) {
            $next = $want;
        }
        @file_put_contents("$dir/fan{$tach}_input.new", (int) round($next) . "\n");
        @rename("$dir/fan{$tach}_input.new", "$dir/fan{$tach}_input");
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [$root, $specFile] = [$argv[1] ?? '', $argv[2] ?? ''];
    $spec = json_decode((string) @file_get_contents($specFile), true);
    if (!is_dir($root) || !is_array($spec)) {
        exit(2);
    }
    $until = hrtime(true) + 300e9;
    $last = hrtime(true);
    while (!is_file("$root/responder.stop") && hrtime(true) < $until) {
        usleep(100000);
        $now = hrtime(true);
        $s = json_decode((string) @file_get_contents($specFile), true);         // a test may change it while it runs
        fanboyFakeRespond($root, is_array($s) ? $s : $spec, ($now - $last) / 1e9);
        $last = $now;
    }
    exit(0);
}
