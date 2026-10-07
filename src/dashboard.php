<?php
declare(strict_types=1);

/*
 * The office's tile on Unraid's Dashboard: the messenger, the
 * team lead's traffic light (open points only — what the user noted with «I
 * know, thanks» doesn't count) and Mr. Backupsy's last and next run (or what
 * the engine is doing right now: a backup, a check, a dry run; a run skipped
 * because the engine was busy, engine 2.20); while the array isn't started the
 * night watchman's night shift (since when, its rounds, what is new) — each row
 * a link into the office. Built from the desks' state files only (no request
 * to the agent, no disk wakes up); the tile asks api.php?a=dash again every
 * minute. While Mr. Restori restores, a row of his says what. Texts from the office's language files (dash.*) in the
 * office's language — the browser's (like the page: the one chosen in ⋯ → Language, else the browser's own), Unraid's
 * labels in them in Unraid's words (src/words.php).
 */

/**
 * The language for the tile: the one asked for (the tile's script: chosen for this browser, else the browser's) if the
 * office has it, else the browser's by its Accept-Language header, else English
 */
function officeDashLang(?string $want = null, ?string $accept = null): string
{
    $codes = array_column(officeLanguages(), 'code');
    if ($want !== null && in_array($want, $codes, true)) {
        return $want;
    }
    return officeBrowserLang($accept ?? (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), $codes) ?? 'en';
}

/** A string with {placeholders}; plurals as {one, other} by n; Unraid's labels (⟦…⟧) as Unraid shows them */
function officeDashT(array $strings, string $key, array $params = []): string
{
    $s = $strings[$key] ?? $key;
    if (is_array($s)) {
        $s = (($params['n'] ?? 0) === 1 ? ($s['one'] ?? null) : null) ?? $s['other'] ?? '';
    }
    $s = officeUnraidResolve((string) $s, officeUnraidWords(officeUnraidLang(), OFFICE_PUBLIC));
    return (string) preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($params[$m[1]] ?? $m[0]), $s);
}

/** "today 12:26", "yesterday 02:00", "tomorrow 02:00", else the date */
function officeDashWhen(array $strings, int $time, string $lang): string
{
    $day = fn (int $t) => date('Y-m-d', $t);
    $clock = date('H:i', $time);
    return match ($day($time)) {
        $day(time())          => officeDashT($strings, 'dash.today', ['time' => $clock]),
        $day(time() - 86400)  => officeDashT($strings, 'dash.yesterday', ['time' => $clock]),
        $day(time() + 86400)  => officeDashT($strings, 'dash.tomorrow', ['time' => $clock]),
        default               => date($lang === 'de' ? 'd.m. H:i' : 'M j, H:i', $time),
    };
}

/** "09:18" for a time of today, else "yesterday 23:10" or the date */
function officeDashSince(array $strings, int $time, string $lang): string
{
    return date('Y-m-d', $time) === date('Y-m-d') ? date('H:i', $time) : officeDashWhen($strings, $time, $lang);
}

/** The night shift's rounds and what is new in it: "12 rounds, 1 new entry" (no rounds counted yet: only what is new) */
function officeDashNightFacts(array $strings, array $night): string
{
    $facts = [];
    if ((int) ($night['rounds'] ?? 0) > 0) {
        $facts[] = officeDashT($strings, 'agent.night_rounds', ['n' => (int) $night['rounds']]);
    }
    $new = (int) ($night['new'] ?? 0);
    $facts[] = $new > 0 ? officeDashT($strings, 'agent.night_new', ['n' => $new]) : officeDashT($strings, 'agent.night_nothing');
    return implode(', ', $facts);
}

/** The next run of a daily cron ("M H * * *"), else null */
function officeDashNextDaily(string $cron): ?int
{
    if (!preg_match('/^(\d{1,2})\s+(\d{1,2})\s+\*\s+\*\s+\*$/', trim($cron), $m)) {
        return null;
    }
    $next = mktime((int) $m[2], (int) $m[1], 0);
    return $next > time() ? $next : strtotime('+1 day', $next);
}

/** A file of the office by its absolute address (the rows are also built by api.php, outside Unraid's page) */
function officeDashAsset(string $file): string
{
    return '/plugins/' . OFFICE_PLUGIN . '/' . $file . '?v=' . (string) @filemtime(OFFICE_PUBLIC . '/' . $file);
}

/**
 * The team lead's traffic light: what is left to do and what he recommends —
 * only desks that work here, only what is open (not in place, and not put
 * aside with «I know, thanks»: the agent marks those "acked", never a must)
 *
 * @return array{0:int, 1:int}  to do, recommended
 */
function officeDashCareCounts(array $care, array $hired): array
{
    $todo = $advice = 0;
    foreach ((array) ($care['checks'] ?? []) as $desk => $list) {
        if (!isset($hired[$desk])) {
            continue;
        }
        foreach ((array) $list as $f) {
            if (!is_array($f) || ($f['ok'] ?? null) === true) {
                continue;
            }
            $level = $f['level'] ?? '';
            if ($level === 'required') {
                $todo++;
            } elseif ($level === 'recommended' && empty($f['acked'])) {
                $advice++;
            }
        }
    }
    return [$todo, $advice];
}

/** Mr. Backupsy's line while the engine runs: a backup, a check or a dry run (status.json "mode") — its key in dash.* */
function officeDashBackupRunning(array $backup): string
{
    $status = (array) ($backup['status'] ?? []);
    $mode = ($status['result'] ?? '') === 'running' ? (string) ($status['mode'] ?? '') : '';
    return match ($mode) {
        'check'  => 'dash.bk_checking',
        'dryrun' => 'dash.bk_dryrun',
        default  => 'dash.bk_running',
    };
}

/**
 * Mr. Backupsy's state on the tile: running, the newest run skipped because the engine was busy
 * (engine 2.20 — as long as no run finished after it), else how the last run went
 *
 * @return array{0:string, 1:string, 2:?int}  key of its text, tone, the time to show (null: none)
 */
function officeDashBackupState(array $backup): array
{
    if (!empty($backup['running'])) {
        return [officeDashBackupRunning($backup), 'orange', null];
    }
    $last = ($backup['history'] ?? [])[0] ?? null;
    $lastTime = is_array($last) ? (int) (($last['finished'] ?? 0) ?: ($last['started'] ?? 0)) : 0;
    $skip = ($backup['skips'] ?? [])[0] ?? null;
    if (is_array($skip) && (int) ($skip['time'] ?? 0) > $lastTime) {
        return ['backup.dash_skipped', 'orange', (int) $skip['time']];
    }
    if (is_array($last)) {
        $result = (string) ($last['result'] ?? '');
        // ended because the array was being stopped (engine 2.24): a stopped run, not a failure - the next one continues
        if ($result === 'aborted' && ($last['message'] ?? '') === 'array_stopping') {
            return ['backup.dash_array_stop', 'orange', $lastTime];
        }
        return ['dash.bk_' . (in_array($result, ['ok', 'warnings'], true) ? $result : 'failed'),
                match ($result) { 'ok' => 'green', 'warnings' => 'orange', default => 'red' }, $lastTime];
    }
    return ['dash.bk_none', 'orange', null];
}

/** Is Mr. Restori restoring right now? His job file says so and its heartbeat is fresh (a killed job's isn't) */
function officeDashRestoring(?array $job, ?int $now = null): bool
{
    return is_array($job) && in_array($job['result'] ?? '', ['queued', 'running'], true) && is_string($job['what'] ?? null)
        && ($now ?? time()) - (int) ($job['heartbeat'] ?? 0) < 180;
}

/**
 * Mr. Restori's drill on the tile (its certificate, data/restore-drill.json, only — no row before the first drill):
 * passed (green), failed (orange), overdue — no passed drill for 60 days (plain)
 *
 * @return array{0:string, 1:string, 2:int}|null  key of its text, tone, the time to show
 */
function officeDashDrill(?array $cert, ?int $now = null): ?array
{
    $last = is_array($cert) && ($cert['interface'] ?? 0) === 1 && is_array($cert['last'] ?? null) ? $cert['last'] : null;
    if (!$last || !in_array($last['result'] ?? '', ['passed', 'failed'], true)) {
        return null;
    }
    $passed = (int) ($cert['last_passed'] ?? 0);
    if ($last['result'] === 'failed') {
        return ['restore.dash_drill_failed', 'orange', (int) ($last['ended'] ?? 0)];
    }
    return ($now ?? time()) - $passed > 60 * 86400 ? ['restore.dash_drill_overdue', 'plain', $passed] : ['restore.dash_drill_passed', 'green', $passed];
}

/** The tile's rows as HTML (escaped) */
function officeDashRows(string $lang): string
{
    $s = officeStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $url = officeMenuUrl(officeMenuPlace());
    $base = '/plugins/' . OFFICE_PLUGIN . '/';
    $row = static function (string $desk, string $icon, string $name, string $state, string $tone, string $sub = '') use ($h, $url): string {
        return '<a class="sso-dash-row" href="' . $h($url . '#/' . $desk) . '">'
            . '<img class="sso-dash-icon" src="' . $h($icon) . '" alt="">'
            . '<span class="sso-dash-name">' . $h($name) . ($sub !== '' ? '<small>' . $h($sub) . '</small>' : '') . '</span>'
            . '<span class="sso-dash-state ' . $tone . '-text">' . $h($state) . '</span></a>';
    };
    $out = '';
    $agent = agentInfo();
    $night = !$agent['running'] && is_array($agent['night'] ?? null) ? $agent['night'] : null;
    $out .= $row('', officeDashAsset('assets/messenger.svg'), officeDashT($s, 'dash.messenger'),
        officeDashT($s, $agent['running'] ? 'agent.label_on'
            : ($night || (isset($agent['no_data']) && ($agent['no_data']['array'] ?? '') !== 'Started') ? 'agent.label_array' : 'agent.label_off')),
        $agent['running'] ? 'green' : 'red');

    // the array isn't started: the night watchman's night shift keeps watch (RAM and flash only) — since when, its rounds, what is new
    if ($night) {
        $out .= $row('', officeDashAsset('desks/watchman/avatar.svg'), officeDashT($s, 'watchman.name'),
            officeDashT($s, 'dash.night', ['time' => officeDashSince($s, (int) $night['since'], $lang)]), $night['new'] ? 'orange' : 'green',
            officeDashNightFacts($s, $night));
    }

    // the team lead: what is left to do, what he recommends (only desks that work here, only what is open)
    $hired = officeHired();
    $care = officeReadJson(OFFICE_DATA . '/caretaker.json');
    if ($care) {
        [$todo, $advice] = officeDashCareCounts($care, $hired);
        [$mood, $state, $tone] = $todo ? ['-todo', officeDashT($s, 'dash.todo', ['n' => $todo]), 'red']
            : ($advice ? ['-advice', officeDashT($s, 'dash.advice', ['n' => $advice]), 'orange'] : ['', officeDashT($s, 'dash.all_good'), 'green']);
        $out .= $row('caretaker', officeDashAsset("desks/caretaker/avatar$mood.svg"), officeDashT($s, 'caretaker.name'), $state, $tone);
    }

    // Mr. Backupsy: the last run and the next one
    $backup = isset($hired['backup']) ? officeReadJson(OFFICE_DATA . '/backup.json') : null;
    if ($backup) {
        [$key, $tone, $when] = officeDashBackupState($backup);
        $state = officeDashT($s, $key) . ($when ? ' · ' . officeDashWhen($s, $when, $lang) : '');
        $schedule = (array) ($backup['schedule'] ?? []);
        $cron = (string) ($schedule['cron'] ?? $schedule['custom'] ?? '');
        $next = !empty($schedule['enabled']) && $cron !== '' ? officeDashNextDaily($cron) : null;
        $sub = empty($schedule['enabled']) ? officeDashT($s, 'dash.bk_no_schedule')
            : ($next ? officeDashT($s, 'dash.bk_next', ['when' => officeDashWhen($s, $next, $lang)]) : officeDashT($s, 'dash.bk_scheduled'));
        $out .= $row('backup', officeDashAsset('desks/backup/avatar.svg'), officeDashT($s, 'backup.name'), $state, $tone, $sub);
    }

    // Mr. Restori, only while he restores (his job file, as long as the job writes its heartbeat)
    $job = isset($hired['restore']) ? officeReadJson(OFFICE_DATA . '/restore-job.json') : null;
    if (officeDashRestoring($job)) {
        $out .= $row('restore', officeDashAsset('desks/restore/avatar.svg'), officeDashT($s, 'restore.name'),
            officeDashT($s, 'restore.dash_restoring', ['what' => (string) $job['what']]), 'orange');
    } elseif ($drill = officeDashDrill(isset($hired['restore']) ? officeReadJson(OFFICE_DATA . '/restore-drill.json') : null)) {
        // otherwise his drill: the last one's result (no row before the first drill)
        $out .= $row('restore/drill', officeDashAsset('desks/restore/avatar.svg'), officeDashT($s, 'restore.name'),
            officeDashT($s, $drill[0], ['when' => officeDashWhen($s, $drill[2], $lang)]), $drill[1]);
    }
    return $out;
}

/** The whole tile for Unraid's Dashboard ($mytiles in SecretaryOfficeDashboard.page) */
function officeDashTile(): string
{
    $lang = officeDashLang();
    $codes = array_column(officeLanguages(), 'code');
    $s = officeStrings($lang);
    $h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);
    $name = officeMenuName();
    $api = '/plugins/' . OFFICE_PLUGIN . '/api.php?a=dash&lang=';
    return '<tbody id="db-sso" title="' . $h($name) . '">'
        . '<tr><td><span class="tile-header"><span class="tile-header-left">'
        . '<img class="sso-dash-logo" src="' . $h(officeDashAsset('assets/reception.svg')) . '" alt="">'
        . '<div class="section"><h3 class="tile-header-main">' . $h($name) . '</h3></div></span>'
        . '<span class="tile-header-right"><span class="tile-header-right-controls">'
        . '<a href="' . $h(officeMenuUrl(officeMenuPlace())) . '" title="' . $h(officeDashT($s, 'dash.open')) . '"><i class="fa fa-fw fa-external-link control"></i></a>'
        . '</span></span></span></td></tr>'
        . '<tr><td><div id="sso-dash" class="sso-dash">' . officeDashRows($lang) . '</div></td></tr>'
        . '</tbody>'
        . '<style>'
        . '#db-sso .sso-dash-logo{width:32px;height:32px;margin-right:8px}'
        . '#db-sso .sso-dash{display:flex;flex-direction:column}'
        . '#db-sso .sso-dash-row{display:flex;align-items:center;gap:10px;padding:6px 0;color:inherit;text-decoration:none;border-top:1px solid var(--table-border-color,rgba(128,128,128,.25))}'
        . '#db-sso .sso-dash-row:first-child{border-top:0}'
        . '#db-sso .sso-dash-row:hover .sso-dash-name{text-decoration:underline}'
        . '#db-sso .sso-dash-icon{width:26px;height:26px;flex:none}'
        . '#db-sso .sso-dash-name{display:flex;flex-direction:column;min-width:0}'
        . '#db-sso .sso-dash-name small{opacity:.7;font-size:.92em}'
        . '#db-sso .sso-dash-state{margin-left:auto;text-align:right}'
        . '</style>'
        // the office's language like the page's (core.js pickLanguage()): the one this browser chose in the office
        // (⋯ → Language), else the first of the browser's languages the office speaks, else English
        . '<script>(function(){var lang=' . json_encode($lang) . ',codes=' . json_encode($codes) . ',own=null,i,j;'
        . 'try{own=localStorage.getItem("office.lang");}catch(e){}'
        . 'if(codes.indexOf(own)<0){own=null;var t=navigator.languages&&navigator.languages.length?navigator.languages:[navigator.language||""];'
        . 'for(i=0;i<t.length&&!own;i++){var x=String(t[i]||"").toLowerCase(),m=x.split("-")[0];'
        . 'for(j=0;j<codes.length&&!own;j++){if(codes[j].toLowerCase()===x)own=codes[j];}if(!own&&codes.indexOf(m)>=0)own=m;}}'
        . 'own=own||"en";var u=' . json_encode($api) . '+encodeURIComponent(own);'
        . 'function load(){fetch(u,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){var b=document.getElementById("sso-dash");if(b&&j&&j.ok)b.innerHTML=j.html;}).catch(function(){});}'
        // the first time when the server guessed another language; then every minute while the page is in view, and when it comes back
        . 'if(own!==lang)load();setInterval(function(){if(!document.hidden)load();},60000);'
        . 'document.addEventListener("visibilitychange",function(){if(!document.hidden)load();});})();</script>';
}
