<?php
// tools/fanboy/vm-responder.php — the fans and sensors of vm-rig.sh's chip (dev only, test3 only). Every 0.2 s:
// reads each output (pwmN, pwmN_enable) as the chip would see its registers, moves each fan's rpm toward what that
// output asks (a lag), writes fanN_input; writes the temperatures the knobs say. Writes only fanN_input, tempN_input
// (atomic: a new file + rename, as a sysfs read never sees half a number) and — like a chip refusing a mode it hasn't —
// puts back a pwmN_enable it can't take.
//
//   php vm-responder.php <rig dir>      (vm-rig.sh starts it; knobs.json there, the log on stdout)
//
// The fans (what is «plugged» where):
//   pwm1 → fan1   the CPU cooler: 600–1800 rpm, stalls under 15 %, starts at 20 %, quick (τ 0.8 s)
//   pwm2 → fan2   two case fans on a splitter, only one tach wire: 300–1200 rpm (one fan's), stalls under 20 %, τ 1.2 s
//   pwm3 → —      a 3-pin case fan without a tach wire: fan3_input reads 0 — «did something get louder?» yes
//   pwm4 → fan4   the disk cage fan: 375–1500 rpm, stalls under 25 %, starts again only at 35 %, slow (τ 2 s)
//   pwm5 → fan5   rear: 250–1000 rpm, stalls under 10 %, starts at 15 %, τ 1.5 s
//   pwm6 → —      an empty connector (fan6_input 0) — «louder?» no
// Knobs (knobs.json): cpu, board1–3, nvme (°C); standN 1 = fan N blocked (0 rpm whatever is written); scaleN 0.8 =
// fan N turns 20 % slower than measured (worn, dusty);
// quiet_<sensor> 1 = that sensor says nothing (an empty read).
// The chip's own curve (enable 2): duty from board1 — 30 % at ≤ 30 °C … 100 % at ≥ 60 °C; enable 0: full speed.
declare(strict_types=1);

$rig = $argv[1] ?? '/tmp/uso-fanboy-rig';
$FANS = [
    1 => ['out' => 1, 'max' => 1800, 'stall' => 15, 'start' => 20, 'tau' => 0.8],
    2 => ['out' => 2, 'max' => 1200, 'stall' => 20, 'start' => 20, 'tau' => 1.2],
    4 => ['out' => 4, 'max' => 1500, 'stall' => 25, 'start' => 35, 'tau' => 2.0],
    5 => ['out' => 5, 'max' => 1000, 'stall' => 10, 'start' => 15, 'tau' => 1.5],
];
$rpm = array_fill_keys(array_keys($FANS), 0.0);
$running = array_fill_keys(array_keys($FANS), false);   // the motor driven: stops under «stall», starts again only at «start»
$last = [];          // the last whole value read per file (a write in progress reads empty: the register didn't change)
$lastEnable = [];
$t = hrtime(true) / 1e9;
$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });
pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });

function say(string $s): void { echo gmdate('H:i:s') . " responder: $s\n"; }
function readNum(string $f): ?int
{
    $s = @file_get_contents($f, false, null, 0, 32);
    return is_string($s) && preg_match('/^\s*(-?\d+)\s*$/D', $s, $m) ? (int) $m[1] : null;
}
/** an attribute's new content, atomically — only where the attribute is there (a chip gone stays gone) */
function putAttr(string $f, string $v): void
{
    if (!is_file($f)) {
        return;
    }
    $tmp = dirname($f) . '/.rig-' . basename($f);
    if (@file_put_contents($tmp, $v) !== false) {
        @rename($tmp, $f);
    }
}
function chipDir(string $rig): ?string
{
    $l = json_decode((string) @file_get_contents("$rig/layout.json"), true);
    if (!is_array($l) || ($l['chip'] ?? '') !== 'there') {
        return null;
    }
    $d = "/sys/devices/platform/{$l['addr']}/hwmon/hwmon{$l['it87']}";
    return is_dir($d) ? $d : null;
}
function cpuDir(string $rig): ?string
{
    $l = json_decode((string) @file_get_contents("$rig/layout.json"), true);
    $d = is_array($l) ? "/sys/devices/platform/intel_rapl_msr.0/hwmon/hwmon{$l['cpu']}" : '';
    return $d !== '' && is_dir($d) ? $d : null;
}

say('started, pid ' . getmypid());
$knobs = [];
while (!$stop) {
    $now = hrtime(true) / 1e9;
    $dt = max(0.01, $now - $t);
    $t = $now;
    $k = json_decode((string) @file_get_contents("$rig/knobs.json"), true);
    if (is_array($k)) {
        $knobs = $k;
    }
    $chip = chipDir($rig);
    $board1 = (float) ($knobs['board1'] ?? 35);
    // the outputs as the chip sees them (registers) — kept while the chip is gone (the fans turn on at the last values)
    $duty = [];
    for ($n = 1; $n <= 6; $n++) {
        if ($chip !== null) {
            $e = readNum("$chip/pwm{$n}_enable");
            if ($e !== null && !in_array($e, [0, 1, 2], true)) {
                // it87 takes 0 (full), 1 (manual), 2 (its curve): another number is refused — put back
                say("pwm{$n}_enable $e refused (it87 has 0, 1, 2) — back to " . ($lastEnable[$n] ?? 2));
                putAttr("$chip/pwm{$n}_enable", ($lastEnable[$n] ?? 2) . "\n");
                $e = $lastEnable[$n] ?? 2;
            }
            if ($e !== null) {
                if (($lastEnable[$n] ?? null) !== $e) {
                    say("pwm{$n}_enable " . ($lastEnable[$n] ?? '-') . " → $e");
                }
                $lastEnable[$n] = $e;
            }
            $v = readNum("$chip/pwm$n");
            if ($v !== null) {
                if (($last[$n] ?? null) !== $v && ($lastEnable[$n] ?? 2) !== 2) {
                    say("pwm$n " . ($last[$n] ?? '-') . " → $v");
                }
                $last[$n] = max(0, min(255, $v));
            }
        }
        $e = $lastEnable[$n] ?? 2;
        $duty[$n] = match ($e) {
            0 => 100.0,
            1 => ($last[$n] ?? 128) / 2.55,
            default => max(30.0, min(100.0, 30 + ($board1 - 30) * 70 / 30)),
        };
    }
    foreach ($FANS as $f => $spec) {
        $d = $duty[$spec['out']];
        $turns = !((int) ($knobs["stand$f"] ?? 0)) && ($running[$f] ? $d >= $spec['stall'] : $d >= $spec['start']);
        if ($turns !== $running[$f]) {
            say("fan$f " . ($turns ? "starts at {$d}%" : 'stops' . (($knobs["stand$f"] ?? 0) ? ' (told to stand)' : " under {$spec['stall']}% ({$d}%)")));
        }
        $running[$f] = $turns;
        // scaleN: a worn or dusty fan turns that much slower than when it was measured (the rpm zone's correction)
        $want = $turns ? $spec['max'] * (0.25 + 0.75 * $d / 100) * (float) ($knobs["scale$f"] ?? 1.0) : 0.0;
        $rpm[$f] += ($want - $rpm[$f]) * (1 - exp(-$dt / $spec['tau']));
        if ($rpm[$f] < 1) {
            $rpm[$f] = 0.0;
        }
    }
    if ($chip !== null) {
        for ($n = 1; $n <= 6; $n++) {
            $r = isset($FANS[$n]) && $rpm[$n] >= 50 ? (int) round($rpm[$n] * (1 + (mt_rand(-100, 100) / 10000))) : 0;
            putAttr("$chip/fan{$n}_input", "$r\n");
        }
        foreach ([1, 2, 3] as $i) {
            putAttr("$chip/temp{$i}_input", (int) ($knobs["quiet_board$i"] ?? 0) ? '' : ((int) round(($knobs["board$i"] ?? 35) * 1000)) . "\n");
        }
    }
    if (($cpu = cpuDir($rig)) !== null) {
        $c = (float) ($knobs['cpu'] ?? 45);
        foreach ([1 => 0, 2 => -2, 3 => -3] as $i => $off) {
            putAttr("$cpu/temp{$i}_input", (int) ($knobs['quiet_cpu'] ?? 0) ? '' : ((int) round(($c + $off) * 1000)) . "\n");
        }
    }
    $nvme = '/sys/devices/pci0000:00/0000:00:11.0/nvme/nvme1/hwmon1';
    if (is_file("$nvme/.rig")) {            // only the rig's copy over the real folder (vm-rig.sh puts the mark there)
        putAttr("$nvme/temp1_input", (int) ($knobs['quiet_nvme'] ?? 0) ? '' : ((int) round(($knobs['nvme'] ?? 42) * 1000)) . "\n");
    }
    usleep(200000);
}
say('ended');
