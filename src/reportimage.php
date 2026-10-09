<?php
declare(strict_types=1);

/*
 * Pictures in a report (dropnook/UnraidSecretaryOffice#6): a screenshot the user adds to «Report a problem or a
 * wish…» goes only as this file makes it — decoded and drawn anew with GD, so nothing of the file the browser had
 * travels on: no metadata (EXIF with its GPS and camera, XMP, ICC profiles, text chunks, comments), no bytes behind
 * the picture's end (a PNG that is also an HTML page), no frames but the first.
 *
 *   magic bytes first          PNG, JPEG or WebP by their first bytes — anything else (SVG, GIF, HTML …) is refused
 *                              before GD sees it; WebP only where this PHP's GD reads and writes it (Unraid 7.3.3's
 *                              bundled GD does neither: refused there, the page turns a WebP into a PNG first)
 *   the size before decoding   ≤ REPORT_IMG_IN_MAX bytes, each side ≤ REPORT_IMG_SIDE_IN_MAX, ≤ REPORT_IMG_PIXELS_MAX
 *                              pixels (getimagesizefromstring() reads the header only: no decompression bomb reaches GD)
 *   drawn anew                 the longest side ≤ REPORT_IMG_SIDE_MAX; a JPEG turned upright by its EXIF orientation
 *                              (the only thing read of the EXIF), then it goes
 *   written anew               a JPEG stays a JPEG (quality 88), anything else becomes a PNG; too large for its share of
 *                              the bytes (≤ REPORT_IMG_OUT_MAX each, ≤ REPORT_IMG_TOTAL_MAX together): a PNG becomes a
 *                              JPEG (on white), then lower quality, then smaller — or it is refused. GD's own comment
 *                              in a JPEG («CREATOR: gd-jpeg …») is taken out as well.
 *
 * Self-contained (no other file of the office), shared like place.php: the web side (api.php, page.php) and the agent's
 * report.php require it, and the agent runs it as a process of its own for every picture —
 * `php -n src/reportimage.php <in> <out> <max bytes>` — so a picture that makes GD run out of memory
 * or crash ends that process, never the agent. It answers one line of JSON: {ok, type, width, height, bytes, scaled}
 * or {ok: false, key}. Keys: report_image_type, report_image_big, report_image_bad.
 */

const REPORT_IMG_COUNT_MAX   = 3;                   // pictures per report
const REPORT_IMG_IN_MAX      = 2 * 1024 * 1024;     // bytes of a picture as the browser has it
const REPORT_IMG_OUT_MAX     = 1536 * 1024;         // bytes of one as it goes (1.5 MB)
const REPORT_IMG_TOTAL_MAX   = 4 * 1024 * 1024;     // bytes of all of them as they go
const REPORT_IMG_SIDE_MAX    = 2560;                // pixels of the longest side as it goes
const REPORT_IMG_SIDE_MIN    = 480;                 // made smaller to fit, never below this
const REPORT_IMG_SIDE_IN_MAX = 12000;               // pixels of a side before (read from the header)
const REPORT_IMG_PIXELS_MAX  = 36000000;            // pixels before (36 MP: ~150 MB in GD)
const REPORT_IMG_MEMORY      = '512M';              // the process of its own
const REPORT_IMG_JPEG_Q      = [88, 78, 68];        // the qualities tried, best first
const REPORT_IMG_MIME        = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

/** A picture's kind by its first bytes: png, jpeg, webp — or null (SVG, GIF, HTML, anything else) */
function reportImageType(string $bytes): ?string
{
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (str_starts_with($bytes, "\xff\xd8\xff")) {
        return 'jpeg';
    }
    if (strlen($bytes) >= 16 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

/** The kinds this PHP's GD can both read and write — only those are taken (Unraid 7.3.3: png, jpeg) */
function reportImageTypes(): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagetypes')) {
        return [];
    }
    $t = imagetypes();
    $out = [];
    foreach (['png' => [IMG_PNG, 'imagepng'], 'jpeg' => [IMG_JPG, 'imagejpeg'], 'webp' => [IMG_WEBP, 'imagewebp']] as $k => [$bit, $fn]) {
        if (($t & $bit) && function_exists($fn)) {
            $out[] = $k;
        }
    }
    return $out;
}

/**
 * One picture as it may go — see the head of this file. $maxBytes: its share of the bytes.
 *
 * @return array{ok: true, type: string, data: string, width: int, height: int, scaled: bool}|array{ok: false, key: string}
 */
function reportImageMake(string $bytes, int $maxBytes = REPORT_IMG_OUT_MAX): array
{
    $no = static fn (string $key): array => ['ok' => false, 'key' => $key];
    $type = reportImageType($bytes);
    if ($type === null || !in_array($type, reportImageTypes(), true)) {
        return $no('report_image_type');
    }
    if (strlen($bytes) > REPORT_IMG_IN_MAX) {
        return $no('report_image_big');
    }
    $info = @getimagesizefromstring($bytes);
    $want = ['png' => IMAGETYPE_PNG, 'jpeg' => IMAGETYPE_JPEG, 'webp' => IMAGETYPE_WEBP][$type];
    if (!is_array($info) || ($info[2] ?? null) !== $want || !is_int($info[0] ?? null) || !is_int($info[1] ?? null) || $info[0] < 1 || $info[1] < 1) {
        return $no('report_image_bad');
    }
    [$w, $h] = $info;
    if ($w > REPORT_IMG_SIDE_IN_MAX || $h > REPORT_IMG_SIDE_IN_MAX || $w * $h > REPORT_IMG_PIXELS_MAX) {
        return $no('report_image_big');
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src instanceof GdImage || imagesx($src) !== $w || imagesy($src) !== $h) {
        return $no('report_image_bad');
    }
    if ($type === 'jpeg') {
        $src = reportImageUpright($src, reportImageJpegOrientation($bytes));
        $w = imagesx($src);
        $h = imagesy($src);
    }
    $side = min(REPORT_IMG_SIDE_MAX, max($w, $h));
    $as = $type === 'jpeg' ? 'jpeg' : 'png';
    $q = 0;
    for (;;) {
        $img = reportImageScaled($src, $side, $as === 'png');
        $data = reportImageWrite($img, $as, REPORT_IMG_JPEG_Q[$q]);
        $out = ['w' => imagesx($img), 'h' => imagesy($img)];
        unset($img);
        if ($data !== null && strlen($data) <= $maxBytes) {
            return ['ok' => true, 'type' => $as, 'data' => $data, 'width' => $out['w'], 'height' => $out['h'], 'scaled' => $out['w'] !== $w || $out['h'] !== $h];
        }
        if ($data === null) {
            return $no('report_image_bad');
        }
        if ($as === 'png') {
            $as = 'jpeg';                       // a photo or a busy screenshot: as a JPEG on white
        } elseif ($q < count(REPORT_IMG_JPEG_Q) - 1) {
            $q++;
        } elseif ($side > REPORT_IMG_SIDE_MIN) {
            $side = max(REPORT_IMG_SIDE_MIN, (int) floor($side * 0.75));
            $q = 1;
        } else {
            return $no('report_image_big');
        }
    }
}

/** The longest side ≤ $side (a new truecolour picture either way: nothing of the decoded one is written as it was) */
function reportImageScaled(GdImage $src, int $side, bool $alpha): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $f = min(1.0, $side / max($w, $h));
    $nw = max(1, (int) round($w * $f));
    $nh = max(1, (int) round($h * $f));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($alpha) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));     // a JPEG has no transparency: on white
        imagealphablending($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** The picture's bytes as $as (png, jpeg — a JPEG without GD's comment), or null */
function reportImageWrite(GdImage $img, string $as, int $q): ?string
{
    $h = fopen('php://memory', 'w+');
    if ($h === false) {
        return null;
    }
    $ok = match ($as) {
        'png'  => @imagepng($img, $h, 9),
        'jpeg' => @imagejpeg($img, $h, $q),
        default => false,
    };
    rewind($h);
    $data = $ok ? (string) stream_get_contents($h) : '';
    fclose($h);
    if ($data === '') {
        return null;
    }
    return $as === 'jpeg' ? reportImageJpegClean($data) : $data;
}

/**
 * A JPEG with only what draws it: SOI, APP0 (JFIF), the tables, the frame and the scans up to EOI — every other APPn
 * (EXIF, XMP, ICC, Photoshop) and every comment out, nothing after EOI. null when it isn't a JPEG this can walk.
 */
function reportImageJpegClean(string $s): ?string
{
    $n = strlen($s);
    if ($n < 4 || substr($s, 0, 2) !== "\xff\xd8") {
        return null;
    }
    $out = "\xff\xd8";
    $i = 2;
    while ($i + 4 <= $n) {
        if ($s[$i] !== "\xff") {
            return null;
        }
        $m = ord($s[$i + 1]);
        if ($m === 0xff) {                          // fill bytes
            $i++;
            continue;
        }
        $len = unpack('n', substr($s, $i + 2, 2))[1];
        if ($len < 2 || $i + 2 + $len > $n) {
            return null;
        }
        $seg = substr($s, $i, 2 + $len);
        $i += 2 + $len;
        if ($m === 0xda) {                          // SOS: the scans to EOI (more tables and scans in a progressive one)
            $end = reportImageJpegScanEnd($s, $i);
            if ($end === null) {
                return null;
            }
            $out .= $seg . substr($s, $i, $end - $i);
            $i = $end;
            if (substr($s, $i, 2) === "\xff\xd9") {
                return $out . "\xff\xd9";
            }
            continue;
        }
        if ($m === 0xfe || ($m >= 0xe1 && $m <= 0xef) || ($m === 0xe0 && ($len !== 16 || substr($seg, 4, 5) !== "JFIF\0"))) {
            continue;                               // a comment, APP1–15, an APP0 that isn't a bare JFIF (a thumbnail): out
        }
        $out .= $seg;
    }
    return null;
}

/** Where a scan's entropy-coded data ends (the next marker that isn't a stuffed byte or a restart) */
function reportImageJpegScanEnd(string $s, int $i): ?int
{
    $n = strlen($s);
    while (($i = strpos($s, "\xff", $i)) !== false && $i + 1 < $n) {
        $m = ord($s[$i + 1]);
        if ($m === 0x00 || ($m >= 0xd0 && $m <= 0xd7) || $m === 0xff) {
            $i += $m === 0xff ? 1 : 2;
            continue;
        }
        return $i;
    }
    return null;
}

/** The EXIF orientation of a JPEG (1–8; 1 when it says none) — the only thing ever read of its EXIF */
function reportImageJpegOrientation(string $s): int
{
    $n = min(strlen($s), 262144);
    $i = 2;
    while ($i + 4 <= $n && $s[$i] === "\xff") {
        $m = ord($s[$i + 1]);
        if ($m === 0xda || $m === 0xd9) {
            break;
        }
        $len = unpack('n', substr($s, $i + 2, 2))[1];
        if ($len < 2) {
            break;
        }
        if ($m === 0xe1 && substr($s, $i + 4, 6) === "Exif\0\0") {
            $t = substr($s, $i + 10, $len - 8);
            $le = str_starts_with($t, "II*\0");
            if (!$le && !str_starts_with($t, "MM\0*")) {
                return 1;
            }
            $u16 = fn (int $o): int => strlen($t) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($t, $o, 2))[1] : 0;
            $u32 = fn (int $o): int => strlen($t) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($t, $o, 4))[1] : 0;
            $ifd = $u32(4);
            $count = $u16($ifd);
            for ($k = 0; $k < min($count, 200); $k++) {
                $e = $ifd + 2 + 12 * $k;
                if ($u16($e) === 0x0112) {
                    $v = $u16($e + 8);
                    return $v >= 1 && $v <= 8 ? $v : 1;
                }
            }
            return 1;
        }
        $i += 2 + $len;
    }
    return 1;
}

/** The picture turned as its EXIF orientation says (a phone's photo of a screen comes upright) */
function reportImageUpright(GdImage $im, int $o): GdImage
{
    if ($o === 2 || $o === 4 || $o === 5 || $o === 7) {
        imageflip($im, IMG_FLIP_HORIZONTAL);
    }
    $angle = match ($o) {
        3, 4 => 180,
        6, 7 => 270,          // imagerotate turns anticlockwise: 270 = a quarter clockwise
        5, 8 => 90,
        default => 0,
    };
    if ($angle !== 0) {
        $turned = imagerotate($im, $angle, 0);
        if ($turned instanceof GdImage) {
            return $turned;
        }
    }
    return $im;
}

// ===================================================================== as a process of its own

/** `php -n reportimage.php <in> <out> <max bytes>`: one picture, one line of JSON on stdout */
function reportImageMain(array $argv): int
{
    $in = $argv[1] ?? '';
    $out = $argv[2] ?? '';
    $max = (int) ($argv[3] ?? 0);
    $bytes = $in !== '' && is_file($in) && !is_link($in) ? @file_get_contents($in, false, null, 0, REPORT_IMG_IN_MAX + 1) : false;
    if (!is_string($bytes) || $out === '' || $max < 1024) {
        echo json_encode(['ok' => false, 'key' => 'report_image_bad']), "\n";
        return 1;
    }
    $r = reportImageMake($bytes, min($max, REPORT_IMG_OUT_MAX));
    unset($bytes);
    if (!$r['ok']) {
        echo json_encode($r), "\n";
        return 1;
    }
    $old = umask(0177);
    $h = @fopen($out, 'x');                         // new, 0600 from the start, never through a link
    umask($old);
    if (!$h || @fwrite($h, $r['data']) !== strlen($r['data']) || !fclose($h)) {
        @unlink($out);
        echo json_encode(['ok' => false, 'key' => 'office_storage']), "\n";
        return 1;
    }
    echo json_encode(['ok' => true, 'type' => $r['type'], 'width' => $r['width'], 'height' => $r['height'], 'bytes' => strlen($r['data']),
                      'sha256' => hash('sha256', $r['data']), 'scaled' => $r['scaled']]), "\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    ini_set('memory_limit', REPORT_IMG_MEMORY);
    ini_set('display_errors', 'stderr');
    exit(reportImageMain($argv));
}
