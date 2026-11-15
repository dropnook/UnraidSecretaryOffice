<?php
declare(strict_types=1);

/*
 * Round 6, part 3 (the owner, 2026-11-15): an NVIDIA card's temperature as a heat source (kind «gpu», the GPU limits
 * and way down of round 4). NVIDIA's own driver shows no hwmon, so only nvidia-smi says it — and only where nvidia-smi
 * is there and the driver loaded (/sys/module/nvidia); Fanboy installs nothing.
 *
 *   nvidia-smi --query-gpu=uuid,name,temperature.gpu --format=csv,noheader,nounits
 *
 * started so that nothing ever waits for it: a process of its own (proc_open, pipes not blocking), looked at by whoever
 * polls (the control between its rounds every 0.2 s, the agent's tick every 150 ms) and cut off after FANBOY_GPU_TIMEOUT
 * (3 s, on the monotonic clock); at most every FANBOY_GPU_EVERY (10 s); after FANBOY_GPU_FAILS failures in a row only once
 * a minute. Who polls: the control while one of its zones has a card as a source; otherwise the agent's tick, only for a
 * minute after his page looked (the setup lists the cards) — never on its own all day.
 *
 * A card's states in a zone: a number (counts like any source) · listed without a number (temperature «[N/A]»): the usual
 * «sensor says nothing» after FANBOY_GPU_QUIET_AFTER (60 s) · not listed any more (handed to a VM — bound to vfio-pci —,
 * or nvidia-smi says «No devices were found»): «away», with its reason, no alarm, the zone goes on with its other sources.
 * Hence a zone may never have NVIDIA cards as its only sources (zones_save refuses it). nvidia-smi failing (an error, a
 * timeout) gives no number: «says nothing» too.
 *
 *   RUN_DIR/fanboy/gpu.json   {v: 1, at, ok, why, fails, cards: [{uuid, name, c}], vfio}  — the last answer, for his look
 * Not yet checked on real hardware (the page says so): a fake nvidia-smi in the tests only.
 */

const FANBOY_GPU_EVERY = 10.0;          // seconds between two asks
const FANBOY_GPU_SLOW = 60.0;           // … after FANBOY_GPU_FAILS failures in a row
const FANBOY_GPU_FAILS = 3;
const FANBOY_GPU_TIMEOUT = 3.0;         // seconds: nvidia-smi cut off
const FANBOY_GPU_QUIET_AFTER = 60.0;    // seconds a card listed without a number: «says nothing»
const FANBOY_GPU_WANTED = 60.0;         // seconds the tick asks after his page looked
const FANBOY_GPU_PREFIX = 'nvidia@';    // a card's source id: nvidia@<uuid>
const FANBOY_GPU_ARGS = ['--query-gpu=uuid,name,temperature.gpu', '--format=csv,noheader,nounits'];

function fanboyGpuFile(): string
{
    return fanboyRunDir() . '/gpu.json';
}

/** nvidia-smi when it is there and the driver is loaded — '' otherwise (the tests' tree names its own) */
function fanboyGpuBin(): string
{
    $root = fanboySysRoot();
    if (!is_dir("$root/sys/module/nvidia")) {
        return '';
    }
    foreach ($root !== '' ? ["$root/usr/bin/nvidia-smi"] : ['/usr/bin/nvidia-smi', '/usr/local/bin/nvidia-smi', '/usr/sbin/nvidia-smi'] as $f) {
        if (is_file($f) && is_executable($f)) {
            return $f;
        }
    }
    return '';
}

/** Is an NVIDIA card (PCI vendor 0x10de) bound to vfio-pci — handed to a VM? Read only */
function fanboyGpuVfio(): bool
{
    $dir = fanboySysRoot() . '/sys/bus/pci/drivers/vfio-pci';
    foreach (@scandir($dir) ?: [] as $e) {
        if (preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$/D', $e) && trim((string) @file_get_contents("$dir/$e/vendor", false, null, 0, 16)) === '0x10de') {
            return true;
        }
    }
    return false;
}

/** A poller's state, nothing asked yet */
function fanboyGpuNew(): array
{
    return ['proc' => null, 'pipes' => [], 'out' => '', 'started' => null, 'next' => -INF, 'fails' => 0, 'cards' => [], 'listed' => null, 'at' => null,
        'ok' => false, 'why' => null, 'answered' => false, 'asked' => null];
}

/** nvidia-smi's lines: [uuid => {uuid, name, c}] — a temperature that isn't a number («[N/A]») is null */
function fanboyGpuParse(string $out): array
{
    $cards = [];
    foreach (explode("\n", $out) as $line) {
        $col = array_map('trim', explode(',', $line));
        if (count($col) < 3 || !preg_match('/^GPU-[0-9a-fA-F-]{8,64}$/D', $col[0])) {
            continue;
        }
        $name = trim((string) preg_replace('/[^A-Za-z0-9 ,._()+-]+/', ' ', implode(', ', array_slice($col, 1, -1))));
        $t = end($col);
        $c = preg_match('/^\d{1,3}(\.\d)?$/D', $t) ? (float) $t : null;
        $cards[$col[0]] = ['uuid' => $col[0], 'name' => mb_substr($name !== '' ? $name : 'NVIDIA', 0, 48), 'c' => $c !== null && $c > 0 && $c < 125 ? $c : null];
    }
    return $cards;
}

/**
 * One look of a poller at $now (monotonic seconds, the caller's clock for «when next»; the timeout always on the real
 * monotonic clock): the running nvidia-smi read on (never waited for), finished → its answer taken; none running and
 * time → started. Returns true when an answer (or a failure) came in this look.
 */
function fanboyGpuPoll(array &$g, float $now): bool
{
    if (is_resource($g['proc'])) {
        $g['out'] .= (string) @stream_get_contents($g['pipes'][1]);
        $st = proc_get_status($g['proc']);
        if ($st['running'] && fanboyMono() - $g['started'] <= FANBOY_GPU_TIMEOUT) {
            return false;
        }
        $timeout = $st['running'];
        if ($timeout) {
            proc_terminate($g['proc'], 9);
        }
        $g['out'] .= (string) @stream_get_contents($g['pipes'][1]);
        foreach ($g['pipes'] as $p) {
            @fclose($p);
        }
        @proc_close($g['proc']);
        $g['proc'] = null;
        $g['pipes'] = [];
        fanboyGpuAnswer($g, $timeout ? 'timeout' : null, $timeout ? -1 : (int) $st['exitcode']);
        return true;
    }
    if ($now < $g['next']) {
        return false;
    }
    $g['next'] = $now + ($g['fails'] >= FANBOY_GPU_FAILS ? FANBOY_GPU_SLOW : FANBOY_GPU_EVERY);
    $g['asked'] = $now;
    $bin = fanboyGpuBin();
    if ($bin === '') {
        $g['why'] = 'none';
        $g['ok'] = false;
        $g['cards'] = [];
        $g['listed'] = [];
        return false;
    }
    $p = @proc_open([$bin, ...FANBOY_GPU_ARGS], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
    if (!is_resource($p)) {
        fanboyGpuAnswer($g, 'error', -1);
        return true;
    }
    stream_set_blocking($pipes[1], false);
    $g['proc'] = $p;
    $g['pipes'] = $pipes;
    $g['out'] = '';
    $g['started'] = fanboyMono();
    return false;
}

/** nvidia-smi's answer taken: the cards listed with their numbers; «No devices were found»: none listed; else a failure */
function fanboyGpuAnswer(array &$g, ?string $why, int $exit): void
{
    $out = $g['out'];
    $g['out'] = '';
    $g['answered'] = true;
    $g['at'] = time();
    if ($why === null && $exit === 0) {
        $g['cards'] = fanboyGpuParse($out);
        $g['listed'] = array_keys($g['cards']);
        $g['fails'] = 0;
        $g['ok'] = true;
        $g['why'] = null;
        return;
    }
    if ($why === null && stripos($out, 'No devices were found') !== false) {
        $g['cards'] = [];
        $g['listed'] = [];
        $g['fails'] = 0;
        $g['ok'] = true;
        $g['why'] = 'none_listed';
        return;
    }
    // failed (an error, a timeout): no numbers — the cards known stay listed (nothing says they went), «says nothing»
    $g['fails']++;
    if ($g['fails'] >= FANBOY_GPU_FAILS) {
        $g['next'] = max($g['next'], ($g['asked'] ?? $g['next']) + FANBOY_GPU_SLOW);     // from the third failure on: once a minute
    }
    $g['ok'] = false;
    $g['why'] = $why ?? 'error';
    foreach ($g['cards'] as $u => $card) {
        $g['cards'][$u]['c'] = null;
    }
    if ($g['fails'] === FANBOY_GPU_FAILS && function_exists('logLine')) {
        logLine("Fanboy: nvidia-smi failed {$g['fails']} times in a row ({$g['why']}) — asked only once a minute now");
    }
}

/** A poller's process ended at once (the control ending or taking its settings anew): never left behind, never waited for */
function fanboyGpuStop(array &$g): void
{
    if (is_resource($g['proc'])) {
        proc_terminate($g['proc'], 9);
        foreach ($g['pipes'] as $p) {
            @fclose($p);
        }
        @proc_close($g['proc']);
    }
    $g['proc'] = null;
    $g['pipes'] = [];
}

/** The last answer into RAM for his look (fanboyDetect() reads it) */
function fanboyGpuKeep(array $g): void
{
    @mkdir(fanboyRunDir(), 0700, true);
    writeAtomic(fanboyGpuFile(), jsonEncode(['v' => 1, 'at' => $g['at'], 'ok' => $g['ok'], 'why' => $g['why'], 'fails' => $g['fails'],
        'cards' => array_values($g['cards']), 'vfio' => fanboyGpuVfio()]), 0644, 0, 0);
}

/** For his look: the cards of the last answer (fresh: within FANBOY_GPU_SLOW × 2), null where there is no nvidia-smi */
function fanboyGpuCards(): ?array
{
    if (fanboyGpuBin() === '') {
        return null;
    }
    $j = readJson(fanboyGpuFile());
    $fresh = is_int($j['at'] ?? null) && abs(time() - $j['at']) <= 2 * FANBOY_GPU_SLOW;
    $cards = [];
    foreach ($fresh && is_array($j['cards'] ?? null) ? $j['cards'] : [] as $c) {
        if (is_array($c) && is_string($c['uuid'] ?? null) && is_string($c['name'] ?? null)) {
            $cards[] = ['uuid' => $c['uuid'], 'name' => $c['name'], 'c' => is_float($c['c'] ?? null) || is_int($c['c'] ?? null) ? (float) $c['c'] : null];
        }
    }
    return ['cards' => $cards, 'ok' => $fresh && ($j['ok'] ?? false) === true, 'why' => is_string($j['why'] ?? null) ? $j['why'] : null,
        'vfio' => ($j['vfio'] ?? false) === true, 'at' => $j['at'] ?? null];
}

/**
 * The agent's side (every agent tick, ~150 ms, before his 5-s gate — a look at a running nvidia-smi is a status call):
 * only while his page looked within FANBOY_GPU_WANTED and the control doesn't ask itself
 */
function fanboyGpuTick(float $now): void
{
    $g = &$GLOBALS['fanboyGpu'];
    $g ??= fanboyGpuNew();
    if (!is_resource($g['proc']) && $now < $g['next']) {
        return;                     // nothing running, not yet time: no file read at all (this runs every agent tick)
    }
    $wanted = ($GLOBALS['fanboyGpuWanted'] ?? -INF) > $now;
    if (!is_resource($g['proc']) && (!$wanted || (fanboyControlPid() !== null && (readJson(fanboyStatusFile())['gpu'] ?? false) === true))) {
        return;
    }
    if (fanboyGpuPoll($g, $now)) {
        fanboyGpuKeep($g);
    }
}

/** His page looked: the tick asks nvidia-smi for a minute (the setup lists the cards) */
function fanboyGpuWant(?float $now = null): void
{
    $GLOBALS['fanboyGpuWanted'] = ($now ?? fanboyMono()) + FANBOY_GPU_WANTED;
}

/** A source id that is an NVIDIA card's */
function fanboyGpuIs(string $id): bool
{
    return str_starts_with($id, FANBOY_GPU_PREFIX);
}

/** The cards as heat sources (fanboySources()): kind gpu, the card's name, its number */
function fanboyGpuSources(?array $gpus): array
{
    return array_map(fn ($c) => ['kind' => 'gpu', 'id' => FANBOY_GPU_PREFIX . $c['uuid'], 'label' => $c['name'], 'c' => $c['c'], 'asleep' => false, 'nvidia' => true],
        $gpus['cards'] ?? []);
}
