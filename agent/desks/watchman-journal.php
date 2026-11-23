<?php
declare(strict_types=1);

/*
 * The Night Watchman's history (1.96 step 7, the concept «Der Nachtwächter in 1.96» 5.3/5.4). Functions only, in a file of
 * its own beside his desk (the agent's desk glob loads it, watchman.php require_once's it; «journal» sorts before «php»).
 *
 * His book (book.json) has one bound for everything: over WATCH_BOOK_MAX the oldest noted line went, whoever noted it — the
 * lines he notes himself (an at job of a plugin, the office's own doing, a rule's line) pushed out what a person answered.
 * Now a line noted by himself or by an exception goes into data/watchman/journal/YYYY-MM.jsonl (the month it was last seen;
 * one JSON object a line, only appended) once it has been quiet WATCH_JOURNAL_AFTER — or earlier, the oldest first, while
 * the book is over its bound. The book keeps what is open and what a person answered («I know, thanks» on the page, the Team
 * Lead's notes, a serious incident). WATCH_JOURNAL_MONTHS months are kept; a month's file holds at most WATCH_JOURNAL_MAX —
 * what doesn't fit is counted (state.json journal.cut) and said on the page, never silently lost.
 *
 * Nothing is moved at once: a book from before 1.96 stays as it is, its lines leave the same way over time (no migration).
 * An older office on this data folder doesn't read the journal: what went there is missing from its page, nothing is lost.
 * The night shift never writes it (its book is in RAM; the handover brings its lines into the day's book).
 */

const WATCH_JOURNAL_DIR    = 'journal';
const WATCH_JOURNAL_AFTER  = 7 * 86400;          // a line noted by himself goes after this long without news
const WATCH_JOURNAL_MONTHS = 13;                 // months kept
const WATCH_JOURNAL_MAX    = 8 * 1024 * 1024;    // bytes of one month's file
const WATCH_JOURNAL_PAGE   = 100;                // lines the page gets at a time
const WATCH_JOURNAL_FILE   = '/^(\d{4})-(\d{2})\.jsonl$/D';
const WATCH_JOURNAL_HUMAN  = ['page', 'teamlead', 'relearn'];      // noted by a person (a re-learn: his click): stays in the book
const WATCH_JOURNAL_NEWEST = ['parity_check'];          // the newest line of these stays in the book: «Array and parity» reads its errors there

/** A line noted by himself or by an exception — one for the history (open ones, a person's answers, serious incidents: never) */
function watchmanJournalOwn(array $e): bool
{
    return !watchmanOpen($e) && isset($e['by']) && is_string($e['by']) && !in_array($e['by'], WATCH_JOURNAL_HUMAN, true)
        && empty($e['serious']) && isset(WATCH_KINDS[$e['kind'] ?? '']);
}

/**
 * The round's move (never the night shift's): lines noted by himself that were quiet WATCH_JOURNAL_AFTER into the history;
 * then, while the book is over WATCH_BOOK_MAX, the oldest of them too. What couldn't be written stays in the book (the
 * bound then takes the oldest noted line, as before). $st['journal']: how many went, what a full month couldn't take.
 */
function watchmanJournalMove(string $dir, array $book, array &$st, int $now): array
{
    $newest = [];
    foreach ($book as $i => $e) {
        $k = (string) ($e['kind'] ?? '');
        if (in_array($k, WATCH_JOURNAL_NEWEST, true) && (!isset($newest[$k]) || (int) $e['time'] >= (int) $book[$newest[$k]]['time'])) {
            $newest[$k] = $i;
        }
    }
    $stay = array_flip($newest);
    $go = [];
    foreach ($book as $i => $e) {
        if (!isset($stay[$i]) && watchmanJournalOwn($e) && $now - max((int) ($e['last'] ?? 0), (int) ($e['noted'] ?? 0)) >= WATCH_JOURNAL_AFTER) {
            $go[$i] = true;
        }
    }
    $left = count($book) - count($go);
    if ($left > WATCH_BOOK_MAX) {
        $own = array_filter($book, fn ($e, $i) => !isset($go[$i]) && !isset($stay[$i]) && watchmanJournalOwn($e), ARRAY_FILTER_USE_BOTH);
        uasort($own, fn ($a, $b) => (int) $a['last'] <=> (int) $b['last']);
        foreach (array_keys($own) as $i) {
            if ($left <= WATCH_BOOK_MAX) {
                break;
            }
            $go[$i] = true;
            $left--;
        }
    }
    if (!$go) {
        return $book;
    }
    $j = is_array($st['journal'] ?? null) ? $st['journal'] : [];
    $written = watchmanJournalWrite($dir, array_values(array_intersect_key($book, $go)), $now, $j);
    $st['journal'] = $j;
    foreach (array_keys($go) as $i) {
        if (!isset($written[$book[$i]['id']])) {
            unset($go[$i]);             // not written: stays
        }
    }
    return array_values(array_diff_key($book, $go));
}

/** The history's folder: a real folder of root, 0700 (never through a link) — null when it can't be */
function watchmanJournalDir(string $dir, bool $make = true): ?string
{
    $j = "$dir/" . WATCH_JOURNAL_DIR;
    clearstatcache();
    if (is_link($j) || (file_exists($j) && !is_dir($j))) {
        return null;
    }
    if (!is_dir($j) && (!$make || !@mkdir($j, 0700))) {
        return null;
    }
    return $j;
}

/**
 * Lines appended to their month's file (the month they were last seen); a full month takes no more — counted in
 * $j['cut'][month]. Old months beyond WATCH_JOURNAL_MONTHS go. Returns the ids written.
 *
 * @return array<string, true>
 */
function watchmanJournalWrite(string $dir, array $entries, int $now, array &$j): array
{
    $folder = watchmanJournalDir($dir);
    if ($folder === null) {
        return [];
    }
    $by = [];
    foreach ($entries as $e) {
        $by[date('Y-m', max(1, (int) ($e['last'] ?? $e['time'] ?? $now)))][] = $e;
    }
    $done = [];
    foreach ($by as $month => $list) {
        $file = "$folder/$month.jsonl";
        $st = @lstat($file);
        if ($st && (($st['mode'] & 0170000) !== 0100000 || $st['nlink'] !== 1)) {
            continue;                       // not a plain file of its own: never written through
        }
        $size = $st ? (int) $st['size'] : 0;
        $text = '';
        foreach ($list as $e) {
            $line = jsonEncode($e) . "\n";
            if ($size + strlen($text) + strlen($line) > WATCH_JOURNAL_MAX) {
                $j['cut'][$month] = (int) ($j['cut'][$month] ?? 0) + 1;      // a full month: counted, said on the page
                $done[(string) $e['id']] = true;                              // … and out of the book all the same (the bound)
                continue;
            }
            $text .= $line;
            $done[(string) $e['id']] = true;
        }
        if ($text === '') {
            continue;
        }
        $old = umask(0077);
        $h = @fopen($file, 'a');
        umask($old);
        if (!$h) {
            foreach ($list as $e) {
                unset($done[(string) $e['id']]);
            }
            continue;
        }
        flock($h, LOCK_EX);
        fwrite($h, $text);
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
    }
    $j['moved'] = (int) ($j['moved'] ?? 0) + count($done);
    $j['last'] = $now;
    // only WATCH_JOURNAL_MONTHS months: the older files go (their cut counts with them)
    $keep = date('Y-m', strtotime(date('Y-m-01', $now) . ' -' . (WATCH_JOURNAL_MONTHS - 1) . ' months'));
    foreach (@scandir($folder) ?: [] as $f) {
        if (preg_match(WATCH_JOURNAL_FILE, $f, $m) && "$m[1]-$m[2]" < $keep && is_file("$folder/$f") && !is_link("$folder/$f")) {
            @unlink("$folder/$f");
            unset($j['cut']["$m[1]-$m[2]"]);
        }
    }
    return $done;
}

/**
 * For his page, on demand (the action `journal`): the months there are (newest first, each with its lines and what a full
 * month couldn't take), and a page of one month's lines, newest first, in the book's shape for the page
 *
 * @return array{months: list<array{month: string, lines: int, cut: int}>, month: ?string, entries: list<array>, more: bool, offset: int}
 */
function watchmanJournalRead(string $dir, ?string $month, int $offset = 0, ?array $st = null): array
{
    $folder = watchmanJournalDir($dir, false);
    $cut = is_array($st['journal']['cut'] ?? null) ? $st['journal']['cut'] : [];
    $months = [];
    foreach ($folder !== null ? (@scandir($folder) ?: []) : [] as $f) {
        if (preg_match(WATCH_JOURNAL_FILE, $f, $m) && is_file("$folder/$f") && !is_link("$folder/$f")) {
            $months["$m[1]-$m[2]"] = true;
        }
    }
    foreach (array_keys($cut) as $mo) {
        if (is_string($mo) && preg_match('/^\d{4}-\d{2}$/D', $mo)) {
            $months[$mo] = true;
        }
    }
    krsort($months);
    $list = [];
    $lines = [];
    foreach (array_keys($months) as $mo) {
        $all = watchmanJournalLines("$folder/$mo.jsonl");
        $list[] = ['month' => (string) $mo, 'lines' => count($all), 'cut' => (int) ($cut[$mo] ?? 0)];
        if ($month === null && $list[0]['month'] === $mo) {
            $month = (string) $mo;
        }
        if ($mo === $month) {
            $lines = $all;
        }
    }
    if ($month !== null && !isset($months[$month])) {
        $month = null;
    }
    $lines = array_reverse($lines);
    $offset = max(0, $offset);
    $page = array_slice($lines, $offset, WATCH_JOURNAL_PAGE);
    return ['months' => $list, 'month' => $month, 'entries' => array_values(array_map('watchmanJournalForPage', $page)),
            'more' => count($lines) > $offset + WATCH_JOURNAL_PAGE, 'offset' => $offset];
}

/** A month's lines: each in exactly an entry's shape (anything else left out) */
function watchmanJournalLines(string $file): array
{
    $st = @lstat($file);
    if (!$st || ($st['mode'] & 0170000) !== 0100000) {
        return [];
    }
    $out = [];
    foreach (explode("\n", (string) @file_get_contents($file, false, null, 0, WATCH_JOURNAL_MAX + 65536)) as $l) {
        $e = $l !== '' ? json_decode($l, true) : null;
        if (is_array($e) && is_string($e['id'] ?? null) && preg_match(WATCH_ID, $e['id']) && isset(WATCH_KINDS[$e['kind'] ?? ''])
            && is_int($e['time'] ?? null) && is_int($e['last'] ?? null)) {
            $out[] = $e + ['count' => 1, 'p' => [], 'by' => null, 'noted' => null];
        }
    }
    return $out;
}

/** A history line as his page reads a book entry (never an internal value) */
function watchmanJournalForPage(array $e): array
{
    return ['id' => $e['id'], 'kind' => $e['kind'], 'group' => WATCH_KINDS[$e['kind']][0] ?? 'watch', 'cat' => watchmanCatOf((string) $e['kind']),
            'attack' => WATCH_ATTACK[$e['kind']] ?? null, 'v' => watchmanEntryVariant($e), 'time' => (int) $e['time'], 'last' => (int) $e['last'],
            'count' => (int) ($e['count'] ?? 1), 'open' => false, 't' => watchmanText($e),
            'p' => array_filter((array) ($e['p'] ?? []), fn ($k) => !str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY),
            'noted' => $e['noted'] ?? null, 'by' => $e['by'] ?? null, 'rule' => is_string($e['rule'] ?? null) ? $e['rule'] : null, 'journal' => true];
}

/** The action `journal` (his page): a month (YYYY-MM, empty: the newest) from an offset */
function watchmanJournalAction(string $month, int $offset, ?string $dir = null): array
{
    if ($month !== '' && !preg_match('/^\d{4}-\d{2}$/D', $month)) {
        throw new Problem('bad_request');
    }
    $dir ??= watchmanDir();
    return ['ok' => true, 'journal' => watchmanJournalRead($dir, $month === '' ? null : $month, $offset, watchmanLoad($dir)['state'])];
}
