<?php
declare(strict_types=1);

/*
 * Mr. Restori: all of libvirt.img back from the package's archive (the restore matrix's gap 7, task/restore-gaps).
 *
 * libvirt.img is the VM service's own image (mounted at /etc/libvirt while it runs): every VM's definition, the UEFI
 * variables, the TPM states, the networks, Unraid's VM snapshot lists. The engine keeps its contents as
 * <place>/server/libvirt.tar.gz (`tar -C /etc -czf … libvirt`). Before, the page said three steps by hand (VM service off,
 * a loop mount and tar in a terminal, VM service on). Now he does the middle one — only with the VM service off (the image
 * neither mounted at /etc/libvirt nor attached as a loop device, looked at in the plan and again right before):
 *
 *   1. lv_off    the VM service still off
 *   2. lv_build  a copy of the image beside it (<image>.restored-<time>, sparse), loop-mounted in RAM, emptied (it is
 *                his own copy: the image itself is never written), the archive unpacked into it, unmounted
 *   3. aside     the image → <image>.aside-<time> (a rename: every VM definition of today kept as it is)
 *   4. move      the copy → the image
 *
 * It replaces EVERY VM definition and network (said plainly first): VMs defined since that backup aren't in it — they
 * stay in the old image set aside. The VMs' disks aren't touched. «Put back» swaps the images back (VM service off too).
 * Then the user turns the VM service on in Unraid's VM Manager — never he.
 */

/** Unraid's VM settings (domain.cfg): where libvirt.img lies */
function rsLvImage(): string
{
    $cfg = readCfg($GLOBALS['rs']['domain_cfg'] ?? '/boot/config/domain.cfg');
    return (string) ($cfg['IMAGE_FILE'] ?? '/mnt/user/system/libvirt/libvirt.img');
}

/**
 * Is the VM service on — /etc/libvirt mounted, libvirt.img (or anything at its path) attached as a loop device? The
 * reason, or null when it is off.
 */
function rsLvServiceOn(string $img): ?string
{
    if (isset($GLOBALS['rs']['lv_on'])) {
        return ($GLOBALS['rs']['lv_on'])($img);            // tests: no VM service
    }
    foreach (mountTable() as $m) {
        if ($m['mount'] === RS_LIBVIRT) {
            return 'mounted';
        }
    }
    return rsLoopHolders([$img]) ? 'attached' : null;
}

/**
 * The archive's entries all inside its top folder libvirt/ (no absolute path, no ..), only files and folders (review L4:
 * a link or a device would let tar write elsewhere; /etc/libvirt holds none) — their count, else null
 */
function rsLvArchiveOk(string $archive): ?int
{
    [$exit, $out] = run(['tar', '-tzf', $archive], 120);
    [$vexit, $long] = run(['tar', '-tvzf', $archive], 120);
    if ($exit !== 0 || $vexit !== 0) {
        return null;
    }
    foreach (explode("\n", trim($long)) as $l) {
        if ($l !== '' && !in_array($l[0], ['-', 'd'], true)) {
            return null;
        }
    }
    $n = 0;
    foreach (explode("\n", trim($out)) as $e) {
        if ($e === '') {
            continue;
        }
        if (!preg_match('#^libvirt(/|$)#', $e) || preg_match('#(^|/)\.\.(/|$)#', $e)) {
            return null;
        }
        $n++;
    }
    return $n ?: null;
}

/**
 * Kind `libvirt`: all of libvirt.img back from the newest package's archive (see the head of this file). Blocked while
 * the VM service is on, without an archive, without an image (Unraid makes a new one: VM Manager on once, then off), for an
 * image that is a link, not a file, or on a disk asleep.
 */
function rsPlanLibvirt(array $r, string $stamp): array
{
    [, $ctx, $place] = rsPlanPlace();
    $server = $place['found'] ? (rsPackages($place['base'])['server'] ?? null) : null;
    return rsPlanLibvirtFor(is_array($server) ? $server : [], rsLvImage(), $stamp, $ctx);
}

/** The plan for this package's server part (its archive) and this image */
function rsPlanLibvirtFor(array $server, string $img, string $stamp, array $ctx): array
{
    $archive = is_string($server['libvirt'] ?? null) ? $server['libvirt'] : null;
    $plan = rsPlanBase('libvirt', 'libvirt.img', ['image' => $img, 'archive' => $archive]);
    $plan['downtime'] = 0;
    if ($archive === null || !is_file($archive) || is_link($archive)) {
        $plan['blockers'][] = ['key' => 'restore_libvirt_no_archive', 'params' => []];
        return $plan;
    }
    $plan['source'] = ['path' => $archive, 'time' => $server['libvirt_time'] ?? null, 'bytes' => $server['libvirt_bytes'] ?? (int) @filesize($archive)];
    if (!rsCleanPath($img) || !str_ends_with($img, '.img') || !str_starts_with($img, $GLOBALS['rs']['lv_root'] ?? '/mnt/')) {
        $plan['blockers'][] = ['key' => 'restore_libvirt_no_image', 'params' => ['path' => $img]];
        return $plan;
    }
    $on = rsLvServiceOn($img);
    if ($on !== null) {
        // the VM service on; or off, and the image still attached as a loop device — something else holds it (a container
        // that mounts the host's / , Tower 2026-10-25: Node-Exporter): Unraid's VM Manager couldn't mount it again either
        $plan['blockers'][] = ['key' => $on === 'attached' ? 'restore_libvirt_attached' : 'restore_libvirt_service_on', 'params' => ['path' => $img]];
    }
    // the image's share asleep: never woken for this
    $asleep = array_values(array_map(fn ($p) => (string) $p['base'], array_filter(rsLocate(dirname($img), $ctx), fn ($p) => $p['asleep'])));
    if ($asleep) {
        $plan['blockers'][] = ['key' => 'restore_libvirt_asleep', 'params' => ['base' => implode(', ', $asleep)]];
        return $plan;
    }
    clearstatcache();
    // no link on the way inside its share (the share's top may be an exclusive share's own link: rsLinkOnWay()), nor the image
    $within = rsLvWithin($img);
    $link = is_link($img) ? $img : rsLinkOnWay($img, $within);
    if ($link !== null) {
        $plan['blockers'][] = ['key' => 'restore_path_link', 'params' => ['path' => $img, 'link' => $link]];
        return $plan;
    }
    if (!is_file($img)) {
        $plan['blockers'][] = ['key' => 'restore_libvirt_no_image', 'params' => ['path' => $img]];
        return $plan;
    }
    if (rsLvArchiveOk($archive) === null) {
        $plan['blockers'][] = ['key' => 'restore_libvirt_archive_bad', 'params' => ['path' => $archive]];
    }
    $to = "$img.restored-$stamp";
    $aside = "$img.aside-$stamp";
    foreach ([$to, $aside] as $p) {
        if (file_exists($p) || is_link($p)) {
            $plan['blockers'][] = ['key' => 'restore_exists', 'params' => ['path' => $p]];
        }
    }
    $size = (int) @filesize($img);
    $free = @disk_free_space(dirname($img));
    $plan['sizes'] = ['need' => $size, 'free' => $free === false ? null : (int) $free, 'measuring' => false, 'compresses' => false];
    if ($free !== false && $size > $free * 0.95) {
        $plan['blockers'][] = ['key' => 'restore_no_space', 'params' => ['need' => $size, 'free' => (int) $free]];
    }
    $mnt = officeRunDir() . "/libvirt-restore-$stamp";
    // the VM service looked at again right before the image is set aside: the copy took minutes (review M1); aside and
    // move look at the way again too (`within`, rsStepLink())
    $plan['steps'] = [
        ['do' => 'lv_off', 'image' => $img],
        ['do' => 'lv_build', 'image' => $img, 'to' => $to, 'from' => $archive, 'mnt' => $mnt],
        ['do' => 'lv_off', 'image' => $img],
        ['do' => 'aside', 'path' => $img, 'to' => $aside, 'within' => $within],
        ['do' => 'move', 'from' => $to, 'to' => $img, 'within' => $within],
    ];
    $plan['aside'][] = ['what' => 'libvirt', 'from' => $img, 'to' => $aside];
    $plan['notes'][] = ['key' => 'note.libvirt_replaces', 'warn' => true, 'params' => ['when' => $server['libvirt_time'] ?? null, 'aside' => $aside]];
    $plan['notes'][] = ['key' => 'note.libvirt_disks', 'params' => []];
    $plan['after'][] = ['key' => 'after.libvirt_on', 'params' => ['aside' => $aside]];
    $plan['putback_pre'] = [['do' => 'lv_off', 'image' => $img]];
    return $plan;
}

/** The share folder the image lies in (/mnt/<user, pool or disk>/<share>; tests: under lv_root) — `within` for its steps */
function rsLvWithin(string $img): string
{
    $root = rtrim((string) ($GLOBALS['rs']['lv_root'] ?? '/mnt/'), '/') . '/';
    $parts = explode('/', substr($img, strlen($root)));
    return $root . implode('/', array_slice($parts, 0, isset($GLOBALS['rs']['lv_root']) ? 1 : 2));
}

/** Right before: the VM service still off (an image swapped under a running libvirt would be lost to it) */
function rsDoLvOff(array $s): array
{
    $on = rsLvServiceOn((string) $s['image']);
    return $on === null ? ['state' => 'ok'] : rsFail($on === 'attached' ? 'lv_attached' : 'lv_service_on', ['path' => (string) $s['image']]);
}

/** mount -o loop / umount of his copy (tests: their stand-ins) */
function rsLvRun(array &$j, array $cmd, int $timeout = 120): array
{
    if (isset($GLOBALS['rs']['lv_cmd'])) {
        return ($GLOBALS['rs']['lv_cmd'])($cmd);
    }
    return rsRun($j, $cmd, $timeout);
}

/**
 * His copy of the image, the archive's contents in it: copied sparse beside the image (the image itself is never opened),
 * loop-mounted in RAM, emptied, the archive unpacked (its top folder libvirt/ dropped, owners as they were), unmounted. A
 * copy left by a failure is his own: «Put back» sets it aside (never deleted).
 */
function rsDoLvBuild(array &$j, int $i): array
{
    $s = $j['steps'][$i];
    $img = (string) $s['image'];
    $to = (string) $s['to'];
    $from = (string) $s['from'];
    $mnt = (string) $s['mnt'];
    if (!str_starts_with($to, "$img.restored-") || !rsCleanPath($to) || !str_starts_with($mnt, officeRunDir() . '/libvirt-restore-')) {
        return rsFail('lv_failed', ['path' => $to]);
    }
    clearstatcache();
    if (file_exists($to) || is_link($to)) {
        return rsFail('exists', ['path' => $to]);
    }
    if (!is_file($img) || is_link($img) || !is_file($from) || is_link($from) || rsLvArchiveOk($from) === null) {
        return rsFail('lv_failed', ['path' => $from]);
    }
    $undo = [['do' => 'aside', 'path' => $to, 'to' => "$to.putback-{T}", 'optional' => true]];
    [$exit, , $err] = rsRun($j, ['cp', '--sparse=always', '--preserve=mode,ownership,timestamps', $img, $to], 900);
    if ($exit !== 0) {
        return rsFail('lv_failed', ['path' => $to], trim($err)) + ['undo' => $undo];
    }
    if (!is_dir($mnt) && !@mkdir($mnt, 0700, true)) {
        return rsFail('mkdir_failed', ['path' => $mnt]) + ['undo' => $undo];
    }
    [$exit, , $err] = rsLvRun($j, ['mount', '-o', 'loop', $to, $mnt]);
    if ($exit !== 0) {
        @rmdir($mnt);
        return rsFail('lv_failed', ['path' => $to], trim((string) $err)) + ['undo' => $undo];
    }
    $fail = null;
    // his own copy emptied (the image itself stays as it is, set aside in the next step), the archive into it
    [$exit, , $err] = rsRun($j, ['find', $mnt, '-mindepth', '1', '-xdev', '-delete'], 300);
    if ($exit !== 0) {
        $fail = rsFail('lv_failed', ['path' => $to], trim($err));
    }
    if ($fail === null) {
        [$exit, , $err] = rsRun($j, ['tar', '-xzf', $from, '-C', $mnt, '--strip-components=1', '--numeric-owner'], 600);
        if ($exit !== 0) {
            $fail = rsFail('lv_failed', ['path' => $from], trim($err));
        }
    }
    rsRun($j, ['sync'], 120);
    [$exit, , $err] = rsLvRun($j, ['umount', $mnt]);
    if ($exit !== 0) {
        // busy for a moment (a scan, the page cache): once more after a pause — else said: the loop device stays (review M5)
        rsRun($j, ['sync'], 120);
        usleep(isset($GLOBALS['rs']['lv_cmd']) ? 0 : 3000000);
        [$exit, , $err] = rsLvRun($j, ['umount', $mnt]);
    }
    if ($exit !== 0) {
        [, $lo] = run(['losetup', '-j', $to], 20);
        $loop = preg_match('#^(/dev/loop\d+):#', trim((string) $lo), $m) ? $m[1] : '?';
        $fail = rsFail('lv_umount_failed', ['path' => $mnt, 'loop' => $loop], trim((string) $err));
    } else {
        @rmdir($mnt);
    }
    return $fail !== null ? $fail + ['undo' => $undo] : ['state' => 'ok', 'undo' => $undo];
}
