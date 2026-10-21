/* Ms. Moverelli — she completes Unraid's mover. She looks after the Smart Mover that ships with the office: per
   share it picks the files by age, exclude lists and the pool's fill and hands them to Unraid's own move binary.
   She sets it up (#/moverelli/setup), runs and schedules it and shows what it does. Real runs only while Unraid's
   own mover schedule is «Disabled». The agent part lives in agent/desks/moverelli.php. */
(() => {
'use strict';

const ID = 'moverelli';
const T = Office.scope(ID);
/** «{n} <noun>» from the lang key count.<what> — its one/other forms */
const nOf = (what, n) => T('count.' + what, { n: Number(n) || 0 });
const { el, fmt } = Office;
const POLL = 3000;
/** Where the Smart Mover comes from (helmi1987's repository) — for the credit under the tool's tile */
const ORIGIN = ['custom-mover-for-unraid', 'https://github.com/helmi1987/custom-mover-for-unraid'];
const HISTORY_SHOWN = 15;

let state = null;
let view = null;
let page = 'main';            // main | setup
let outTimer = null;
let form = null;              // the setup page's values (from her settings, anew on every visit)
let saved = null;             // what is saved, as the page sends it (formSettings() of her settings): what the bar compares with
let noJack = false;           // «Discard» this visit: Jack Emby's list not proposed again
const folds = new Map();      // the setup's share rows: unfolded or not, while the page lives

// ------------------------------------------------------------------ loading
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view && page === 'main') render();
  scheduleProgress();
}

/** A fresh look (the agent scans her shares anew), the wave while it takes; the page as it was when the agent is away */
async function lookAgain() {
  Office.busy('look.' + ID, true);
  try { await load(true); } catch (e) { /* the shown state stays */ } finally { Office.busy('look.' + ID, false); }
}

// ------------------------------------------------------------------ her run card (2026-10-12, like Mr. Restori's)
let progress = null;          // Smart Mover's live status while a real run goes (the part «progress»)
let nightNow = null;          // the night run's short state while it goes (the part «progress»: phase, round, who works)
let jackLive = null;          // his step at work in the night (free, fill): his live panel's {real, progress} (the part «progress»)
let stopping = null;          // the stop file's why while she stops (user | now | mover)
let progressAt = 0;
let progTimer = null;
const PROGRESS_POLL = 5000;
/** While a real run goes: the part «progress» every 5 s; nothing otherwise */
function scheduleProgress() {
  clearTimeout(progTimer);
  progTimer = null;
  if (!view || page !== 'main' || !realRun()) return;
  progTimer = setTimeout(askProgress, progress ? PROGRESS_POLL : 300);     // the first look soon after the run shows
}
async function askProgress() {
  if (!view || page !== 'main' || !realRun()) return;
  if (typeof document !== 'undefined' && document.hidden) { scheduleProgress(); return; }
  try { await Office.loadState(ID, { part: 'progress', fresh: true }, tookProgress); } catch (e) { scheduleProgress(); }
}
function tookProgress(j) {
  const p = j && j.ok ? j.part : null;
  if (!p || typeof p.time !== 'number' || p.time < progressAt) { scheduleProgress(); return; }
  progressAt = p.time;
  if (!p.real) { progress = null; stopping = null; nightNow = null; jackLive = null; load(true); return; }     // the run ended: her state anew — the card goes
  progress = p.progress || null;
  nightNow = p.night || null;
  jackLive = p.jack || null;
  stopping = p.stopping || null;
  redrawCard();
  if (picLive && picLive.isConnected && nightNow) picLive.update(nightNow);
  scheduleProgress();
}
function redrawCard() {
  const old = view && view.querySelector ? view.querySelector('.mo-run') : null;
  if (!old || !old.replaceWith) return;
  const card = runCard(progress, Date.now() / 1000);
  old.replaceWith(card);
  // her bubble follows the share in flight (runningText()): its way and which files
  const words = view.querySelector('.deskhead .bubble-text');
  if (words) { const now = withGreeting(bubbleText()); if (words.textContent !== now) words.textContent = now; }
  // Jack Streamer's panel under it: drawn anew, comes with his step, goes with it
  const was = view.querySelector('.mo-jack');
  const panel = jackPanel(nightNow);
  if (was) { if (panel) was.replaceWith(panel); else was.remove(); } else if (panel && card.after) card.after(panel);
}

/** His step of the night at work (free = give back, fill = fetch) and his live view here: the panel shows */
const jackAtWork = (nn) => !!(nn && nn.who === 'jack' && ['free', 'fill'].includes(nn.phase) && jackLive && jackLive.real && Office.jackRows);

/**
 * Jack Streamer's live panel under her night card while his step runs — his page's bars (core.js Office.jackRows, lent by
 * his desk.js): the bracket, «back to the array», one per person, the file in flight; «Jack Streamer at work» (her page),
 * a link to his. «Stop after this file» stays her card's (the whole night). null otherwise.
 */
function jackPanel(nn) {
  if (!jackAtWork(nn)) return null;
  const box = el('div', 'box jo-prog running mo-jack');
  const head = el('div', 'jo-prog-head');
  head.append(el('span', '', T('rc.jack_title')));          // a part of her one card: its wave is the card's
  const open = link(T('rc.night_jack_open'), () => Office.go('#/jack'));
  open.className = 'mo-link mo-jack-open';
  head.appendChild(open);
  box.appendChild(head);
  Office.jackRows(box, jackLive.progress, { noFile: true });     // his file in flight: the one line in the run's part
  return box;
}

/**
 * What her card shows of Smart Mover's live status, pure (tests/run.php under node): the steps — Umschauen, one per share
 * of the run, Aufräumen — with the current one, the share in flight by files and bytes, its percent (by bytes, else
 * files), the file in flight with its own live rate (core.js Office.runRate), the time left from the page's readings
 * (runEstimate(): files × time per file + bytes ÷ bandwidth; a range while unsure) and the end once the share in flight
 * is the run's last and the time left sure
 */
function runCardData(p, now) {
  const plan = Array.isArray(p.plan) ? p.plan : [];
  const done = new Set((p.shares || []).map((x) => x.share));
  const phase = p.phase || 'look';
  const steps = [{ name: T('rc.look'), state: phase === 'look' ? 'running' : 'done' }];
  plan.forEach((sh) => steps.push({ name: sh, state: phase === 'share' && p.share === sh ? 'running' : done.has(sh) || phase === 'tidy' ? 'done' : 'pending' }));
  steps.push({ name: T('rc.tidy'), state: phase === 'tidy' ? 'running' : 'pending' });
  const at = steps.findIndex((st) => st.state === 'running');
  const files = Number(p.share_files) || 0;
  const bytes = Number(p.share_bytes) || 0;
  const dFiles = Number(p.share_done_files) || 0;
  // the file in flight counts with what of its .partial is written (the agent measures it: moverelliInFlight())
  const fileBytes = Number(p.file_bytes) || 0;
  const dBytes = (Number(p.share_done_bytes) || 0) + fileBytes;
  const est = phase === 'share' && (bytes > 0 || files > 0) ? runEstimate(p, { files, bytes, dFiles, dBytes }, now) : null;
  // the percent (the owner, 2026-10-18: «0 %» while 5 405 of 51 312 small files were done, 1 of 329 GB): what of the
  // share's time is done once the time left is known (runEstimate(): files and bytes each by their cost); before that
  // files and bytes both — the bar as far as the further of them
  const pct = (x, all) => (all > 0 ? Math.min(100, Math.floor(100 * x / all)) : null);
  const filesPct = phase === 'share' ? pct(dFiles, files) : null;
  const bytesPct = phase === 'share' ? pct(dBytes, bytes) : null;
  const byTime = est && est.left !== null && est.part !== null ? Math.min(100, Math.floor(100 * est.part)) : null;
  const percent = phase !== 'share' ? null : byTime !== null ? byTime : Math.max(filesPct ?? -1, bytesPct ?? -1) >= 0 ? Math.max(filesPct ?? 0, bytesPct ?? 0) : null;
  const left = est && est.left !== null ? est.left : null;
  // unsure (the readings can't yet tell the time per file from the bandwidth): a range, no end
  const range = left !== null && !est.sure ? [est.lo, est.hi] : null;
  const end = left !== null && !range && plan[plan.length - 1] === p.share ? now + left : null;
  // the file's own live rate (its .partial growing): said beside it
  const fileRate = fileBytes > 0 && p.file ? Office.runRate(`moverelli|${p.started}|${p.file}`, fileBytes, now) : null;
  return { steps, at, percent, byTime: byTime !== null, filesPct, bytesPct, left, range, end, rate: est ? est.rate : null, estimating: !!est && est.left === null, fileRate, waking: !!p.waking && !fileBytes, fileBytes,
    fileSize: p.file_size == null ? null : Number(p.file_size), share: p.share || '', file: p.file || '', files, bytes, dFiles, dBytes, started: p.started || null,
    fill: phase === 'share' ? fillLine(p) : null };
}

// ---- the share's time left (the owner, 2026-10-18: «noch etwa 1 T 3 Std» after 27 min of 53 GB, then «6 days» for a run of
// under 2 h — 8 667 of 72 880 small Nextcloud files done in 535 s, but only 319 MB of 359 GB). Unraid's move costs a fixed
// time per file (fuser and the like, ~50–80 ms) plus the copy time of its bytes, so neither files a second nor bytes a
// second alone says the rest: time ≈ files × perFile + bytes ÷ bandwidth. Both are fitted by least squares over the
// page's readings once both come out known within a quarter (their mix of bytes per file varied); before that the part the readings can't tell is a prior
// (EST_PER_FILE, EST_BW) and the card says a range (EST_BW_LO … EST_BW_HI, EST_PER_FILE_LO … _HI) instead of one number.
// Smoothed; shown after a minute or 1 %.
const EST_MIN_S = 60;             // shown after a minute …
const EST_MIN_PART = 0.01;        // … or 1 % of the share's files or bytes
const EST_SETTLE_S = 10;          // never from the first seconds
const EST_BLEND = 0.3;            // each new estimate moves the shown one by this part (the shown one counts down between)
const EST_STEP_S = 5;             // a reading kept at most every 5 s …
const EST_KEEP = 64;              // … this many (beyond: every second one merged — the sums stay)
const EST_PER_FILE = 0.06;        // the prior per file: Unraid's move runs fuser and stats each file (~50–80 ms seen)
const EST_PER_FILE_LO = 0.03;
const EST_PER_FILE_HI = 0.12;
const EST_BW = 100e6;             // the prior bandwidth, bytes a second: one HDD's stream, pool or array, parity or not …
const EST_BW_LO = 50e6;           // … read/modify/write parity on a slow disk …
const EST_BW_HI = 250e6;          // … a fast HDD or SSD to SSD (the bytes rate seen is a floor on top; above this: unknown)
const EST_ERR = 0.25;             // the fit's time per file and bandwidth each known within a quarter (standard error)
// the time left in words: a range whose low end rounds to nothing says only its high end, under a minute «a few seconds»
// (never «about 0 min to 3 s», 2026-10-19)
function leftText(d) {
  const [lo, hi] = d.range || [d.left, d.left];
  if (hi < 60 && (d.range || hi < 1)) return T('rc.left_few');
  if (d.range && Math.round(lo) < 1) return T('rc.left_upto', { hi: fmt.duration(hi) });
  return d.range ? T('rc.left_range', { lo: fmt.duration(lo), hi: fmt.duration(hi) }) : T('rc.left', { left: fmt.duration(d.left) });
}

const EST_SURE = 1.25;            // a range narrower than this (high ÷ low) is said as one time
const estSeen = new Map();        // run|share -> [{t, files, bytes}, …]: the page's readings of the share (the first: its base)
const estLast = new Map();        // run|share -> {t, left, lo, hi}: the last time left shown

/**
 * Seconds per file and bandwidth from readings, pure: {perFile, bw, fit} — fit true when least squares over the stretches
 * between readings could tell both apart, else the part the readings can't tell is the prior given (perFile0, bw0) and the
 * other follows from the whole stretch; unknown when the bytes already went faster than EST_BW_HI (perFile, bw then the
 * two ends: the whole time the files', the whole time the bytes'). Always: perFile ≤ the whole stretch's seconds per file,
 * bw ≥ its bytes a second (time ≥ each part alone).
 */
function estModel(readings, perFile0, bw0) {
  const st = [];
  for (let i = 1; i < readings.length; i++) {
    const t = readings[i].t - readings[i - 1].t;
    const f = Math.max(0, readings[i].files - readings[i - 1].files);
    const b = Math.max(0, readings[i].bytes - readings[i - 1].bytes) / 1e6;     // MB: keeps the sums in range
    if (t <= 0 || (!f && !b)) continue;
    st.push([f, b, t]);
  }
  const first = readings[0], last = readings[readings.length - 1];
  const T = last.t - first.t, F = Math.max(0, last.files - first.files), B = Math.max(0, last.bytes - first.bytes);
  // the whole stretch's bounds (not its fastest 5 s: a burst of bytes into a cache would make it all too quick)
  const clamp = (perFile, bw) => ({ perFile: Math.max(0, F > 0 ? Math.min(perFile, T / F) : perFile), bw: Math.max(bw, T > 0 ? B / T : 0, 1) });
  // least squares t = a·f + c·b over the stretches, trusted only when both come out positive and each within EST_ERR of
  // itself (its standard error): a stretch of small files of varying size and a jittering fuser never make a bandwidth
  let ff = 0, fb = 0, bb = 0, ft = 0, bt = 0;
  st.forEach(([f, b, t]) => { ff += f * f; fb += f * b; bb += b * b; ft += f * t; bt += b * t; });
  const det = ff * bb - fb * fb;
  if (st.length >= 3 && ff > 0 && bb > 0 && det / (ff * bb) >= 1e-6) {
    const a = (ft * bb - bt * fb) / det;          // seconds per file
    const c = (bt * ff - ft * fb) / det;          // seconds per MB
    const rss = st.reduce((x, [f, b, t]) => x + (t - a * f - c * b) ** 2, 0);
    const s2 = st.length > 2 ? rss / (st.length - 2) : 0;
    if (a > 0 && c > 0 && Math.sqrt(s2 * bb / det) <= EST_ERR * a && Math.sqrt(s2 * ff / det) <= EST_ERR * c) return { ...clamp(a, 1e6 / c), fit: true };
  }
  // bytes faster than the bandwidth's prior allows: the readings can't tell the time per file from the bandwidth at all
  if (T > 0 && B / T > EST_BW_HI) return { perFile: F > 0 ? T / F : 0, bw: B / T, fit: false, unknown: true };
  // what the readings saw mostly: files (small ones) — the bandwidth unknown; else bytes — the time per file unknown
  if (F * EST_PER_FILE >= B / EST_BW) return { ...clamp(F > 0 ? Math.max(0, T - B / bw0) / F : perFile0, bw0), fit: false };
  const perFile = F > 0 ? Math.min(perFile0, 0.5 * T / F) : perFile0;
  return { ...clamp(perFile, B > 0 && T > F * perFile ? B / (T - F * perFile) : bw0), fit: false };
}

/**
 * The share's estimate, pure but for its memory (estSeen, estLast): {rate (bytes a second, its average — «Ø»), fileRate
 * (files a second), left (seconds; null while it is still estimated), lo, hi (the range; equal when sure), sure}. Since
 * when: the run's start while no share before it moved anything in this run (Smart Mover says no start of its own per
 * share — what is done of the run then is the share's), else since this page first saw the share at work.
 * d: {files, bytes, dFiles, dBytes} of runCardData().
 */
function runEstimate(p, d, now) {
  const key = `moverelli|${p.started}|${p.share}`;
  // what the run moved before this share (the run's counts hold the share's too)
  const before = (Number(p.done_bytes) || 0) > (Number(p.share_done_bytes) || 0) || (Number(p.done_files) || 0) > d.dFiles;
  if (!estSeen.has(key)) {
    estSeen.set(key, [!before && Number(p.started) > 0 && Number(p.started) <= now ? { t: Number(p.started), files: 0, bytes: 0 } : { t: now, files: d.dFiles, bytes: d.dBytes }]);
  }
  const readings = estSeen.get(key);
  const reading = { t: now, files: d.dFiles, bytes: d.dBytes };
  // the newest reading moves along until it is 5 s past the one before: then it stays and the next one starts
  if (readings.length > 1 && now - readings[readings.length - 2].t < EST_STEP_S) readings[readings.length - 1] = reading;
  else readings.push(reading);
  if (readings.length > EST_KEEP) estSeen.set(key, readings.filter((x, i) => i % 2 === 0 || i === readings.length - 1));
  const base = readings[0];
  const secs = now - base.t;
  const dF = Math.max(0, d.dFiles - base.files);
  const dB = Math.max(0, d.dBytes - base.bytes);
  const rate = secs > 0 && dB > 0 ? dB / secs : null;
  const fileRate = secs > 0 && dF > 0 ? dF / secs : null;
  const none = { rate, fileRate, left: null, lo: null, hi: null, sure: false, part: null };
  const enough = secs >= EST_MIN_S || (d.bytes > 0 && dB >= EST_MIN_PART * d.bytes) || (d.files > 0 && dF >= EST_MIN_PART * d.files);
  if (secs < EST_SETTLE_S || !enough || (!rate && !fileRate)) return none;
  const rs = estSeen.get(key);
  const filesLeft = d.files > 0 ? Math.max(0, d.files - d.dFiles) : 0;
  const bytesLeft = d.bytes > 0 ? Math.max(0, d.bytes - d.dBytes) : 0;
  const time = (m) => filesLeft * m.perFile + bytesLeft / m.bw;
  const m = estModel(rs, EST_PER_FILE, EST_BW);
  let raw = time(m), lo = raw, hi = raw;
  // what of the share's time is done by the same model (files × time per file + bytes ÷ bandwidth): the card's percent
  let doneT = d.dFiles * m.perFile + d.dBytes / m.bw;
  if (m.unknown) {
    // the two ends: all the time so far the files' (m.perFile), all of it the bytes' (m.bw) — the larger said, as a range
    const ends = [filesLeft * m.perFile, bytesLeft / m.bw];
    [raw, lo, hi] = [Math.max(...ends), Math.min(...ends), Math.max(...ends)];
    doneT = ends[0] >= ends[1] ? d.dFiles * m.perFile : d.dBytes / m.bw;
  } else if (!m.fit) {
    // the part the readings can't tell, from its range: the other part follows from the same stretch each time
    const ends = [estModel(rs, EST_PER_FILE_LO, EST_BW_HI), estModel(rs, EST_PER_FILE_HI, EST_BW_LO)].map(time);
    lo = Math.min(raw, ...ends);
    hi = Math.max(raw, ...ends);
  }
  const last = estLast.get(key);
  const blend = (x, y) => {
    if (!last || now < last.t) return x;
    if (now === last.t) return y;
    const counted = Math.max(0, y - (now - last.t));
    return counted + EST_BLEND * (x - counted);
  };
  const left = blend(raw, last && last.left);
  lo = Math.min(left, blend(lo, last && last.lo));
  hi = Math.max(left, blend(hi, last && last.hi));
  estLast.set(key, { t: now, left, lo, hi });
  const sure = hi <= EST_SURE * Math.max(lo, 1);
  const part = doneT + left > 0 ? Math.min(1, doneT / (doneT + left)) : null;
  return { rate, fileRate, left, lo: sure ? left : lo, hi: sure ? left : hi, sure, part };
}

/**
 * The end in words, pure: «done at about 17:45» today, «done tomorrow at about 03:10», later with its day; $now, $end:
 * seconds (the browser's own day)
 */
function endText(end, now) {
  const day = (s) => { const x = new Date(s * 1000); return new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime(); };
  const days = Math.round((day(end) - day(now)) / 86400000);
  if (days <= 0) return T('rc.end', { time: fmt.time(end) });
  if (days === 1) return T('rc.end_tomorrow', { time: fmt.time(end) });
  return T('rc.end_day', { day: fmt.date(end, true) });
}

/**
 * The fill of a share in words, pure: «Pool master 82 % → target below 70 %» (now: «… · now 75 %»), reached: «Target
 * reached at 69 % — the rest stays»; null while the share has no fill or it didn't start (the pool below its trigger)
 */
function fillLine(x) {
  if (!x || !x.fill || x.pool_used === '' || x.pool_used == null || !x.until) return null;
  if (x.reached) return T('rc.fill_reached', { pct: x.pool_now });
  let t = T('rc.fill', { pool: x.pool, used: x.pool_used, until: x.until });
  if (x.pool_now !== '' && x.pool_now != null && String(x.pool_now) !== String(x.pool_used)) t += ' · ' + T('rc.fill_now', { pct: x.pool_now });
  return t;
}

/** The night run's phases as the card's steps, pure — core.js Office.nightSteps() (Jack Emby's page shows the same) */
const nightSteps = (n) => Office.nightSteps(n, T);

/** The pool of the night (its picture's), else the one her shares move from */
function nightPool(nn) {
  const pic = nn && nn.picture;
  return (pic && pic.pool) || (((state && state.shares) || []).find((x) => x.state === 'moves') || {}).from || 'cache';
}
/** «Array», «Pool cache» */
const placeName = (x) => (!x || x === 'array' ? T('pic.array') : T('pic.pool_name', { pool: x }));
/**
 * The ONE line of the file in flight in her card (owner 2026-10-15), pure: hers (Smart Mover's status) or Jack Streamer's
 * (his live view) — «On its way: Teil 2.mkv (1.6 GB of 2.5 GB) — Pool cache → Array»; the pool's picture says none while
 * the run goes
 */
function onWayText(f) {
  const name = String(f.name || '').split('/').pop();
  const size = f.size != null && Number(f.size) > 0 ? ` (${T('rc.file_bytes', { done: fmt.size(Number(f.done) || 0), size: fmt.size(Number(f.size)) })})` : '';
  return T('rc.on_way', { file: name + size, from: placeName(f.from), to: placeName(f.to) });
}

/**
 * The run bar's percent, pure: «42 %» (of the share's time, with its tip) once the time left is known; before that files
 * and bytes each when the share has both — «10 % of the files · 0 % of the data» — else the one it has
 */
function percentText(d) {
  if (d.byTime || d.filesPct === null || d.bytesPct === null || d.filesPct === d.bytesPct) return `${d.percent} %`;
  return T('rc.parts', { files: d.filesPct, bytes: d.bytesPct });
}

/** The card above the tiles while a real run goes: the steps, the bar, the file in flight, the end; «Stop after this file» */
function runCard(p, now) {
  const nn = nightNow || (nightGoing() ? night().last : null);
  // the night run: the card Jack Emby's page shows too (core.js Office.nightCard — «Joint run», since when, its phases);
  // who is at work — Jack Emby's files show on his page (his live panel)
  let card;
  let top;
  if (nn) {
    card = Office.nightCard(nn, { T, now, stopping, cls: 'mo-run' });
    if (nn.who === 'jack' && !jackAtWork(nn)) {
      const line = el('div', 'card-line', T('rc.night_jack') + ' ');
      line.appendChild(link(T('rc.night_jack_open'), () => Office.go('#/jack')));
      card.appendChild(line);
    }
  } else {
    card = el('div', 'card mo-run running');
    top = el('div', 'card-head');
    top.append(el('span', 'spin'), el('span', 'card-name', T('rc.title')));
    if (stopping) top.appendChild(chip(T('stopping'), 'warn'));
    card.appendChild(top);
  }
  if (!p) {
    // the night between its steps (nobody at work): the pool measured — first, after his give-back, after her part
    const measuring = !!(nn && !nn.who && ['check', 'recheck'].includes(nn.phase));
    if (!nn || nn.who !== 'jack') card.appendChild(el('p', 'role', measuring ? T('rc.measuring', { pool: nightPool(nn) }) : T('rc.waiting')));
  } else {
    const d = runCardData(p, now);
    if (d.started && !nn) top.appendChild(el('span', 'status quiet', T('rc.since', { time: fmt.time(d.started), duration: fmt.duration(now - d.started) })));
    card.appendChild(Office.runSteps(d.steps, d.at, `moverelli|${p.started}`));
    if (d.percent !== null) {
      // the share's average (Ø) and the time left — «being estimated» before a minute or 1 %, a range while unsure (runEstimate())
      const bar = Office.runBar(d.percent, percentText(d) + (d.rate ? ' · ' + T('rc.rate_avg', { rate: fmt.size(d.rate) }) : '')
        + (d.range || d.left !== null ? ' · ' + leftText(d) : d.estimating ? ' · ' + T('rc.estimating') : ''));
      if (d.byTime) bar.title = T('rc.percent_tip');
      card.appendChild(bar);
      card.appendChild(el('div', 'card-line', T('rc.share', { share: d.share, done: d.dFiles, files: nOf('files', d.files), done_size: fmt.size(d.dBytes), size: fmt.size(d.bytes) })));
    }
    if (d.fill) card.appendChild(el('div', 'card-line', d.fill));
    if (d.file) {
      const sh = ((state && state.shares) || []).find((x) => x.share === d.share) || {};
      card.appendChild(el('div', 'card-line mo-run-file', onWayText({ name: d.file, done: d.fileBytes, size: d.fileSize, from: sh.from || nightPool(nn), to: sh.to || 'array' })
        + (d.fileRate ? ' · ' + T('rc.rate', { rate: fmt.size(d.fileRate) }) : '')));
      if (d.waking) card.appendChild(el('div', 'card-line', T('rc.waking')));
    }
    if (d.end) card.appendChild(el('div', 'card-line', endText(d.end, now)));
  }
  // Jack Streamer's file in flight while his step runs: the same one line, from his live view (his part below shows none)
  const cur = jackAtWork(nn) && jackLive.progress && jackLive.progress.current;
  if (cur && cur.rel) {
    const pool = nightPool(nn);
    card.appendChild(el('div', 'card-line mo-run-file', onWayText({ name: cur.rel, done: cur.done, size: cur.size,
      from: cur.phase === 'back' ? pool : 'array', to: cur.phase === 'back' ? 'array' : pool })));     // EmbyCache's file phase: back | fill
  }
  // the foot: «N errors, N warnings» on the left (as Mr. Backupsy's card); on the right, in Jack Emby's order and style
  // (his live panel): «Log» as a quiet link, «Stop after this file» (Unraid's move finishes the file in flight anyway —
  // there is no harder stop, 2026-10-13)
  const foot = el('div', 'card-foot mo-run-foot');
  foot.appendChild(el('span', '', T('rc.counts', { errors: nOf('errors', p ? p.errors : 0), warnings: nOf('warnings', p ? p.warnings : 0) })));
  const right = el('span', 'mo-run-right');
  const stop = button(T('stop'), 'small plain', async () => { stop.disabled = true; await act('stop'); });
  stop.disabled = !!stopping || !Office.agent.running;
  right.append(link(T('rc.show_log'), () => showOutput(true)), stop);
  foot.appendChild(right);
  card.appendChild(foot);
  return card;
}

// ------------------------------------------------------------------ the night's picture (2026-10-14, the owner)
// The setup simulator's bars live on here, while her night run with Jack Emby works and after it: the pool before the
// run, the pool after it (forecast while it goes, measured once done), where she moves to (the array or the secondary
// pool) — and the files done wandering slowly between them (night.json `picture`, agent/lib/nightrun.php).
// Jack Streamer's part in two (the owner, 2026-10-15): not yet watched in his colour, already watched (goes back) in green
// and his reserved room (owner + helmi1987, 2026-10-15): hatched after his part — his budget's room not yet filled
// the snapshots apart in black (owner, 2026-10-15; ZFS only — no figure, no part), first at the very left (owner, 2026-10-15)
const PIC_SEGS = ['snaps', 'fixed', 'others', 'jack', 'watched', 'reserve', 'free'];
const PIC_MIN_PX = 10;            // a segment with something in it is never thinner than this (the owner, 2026-10-14)
const PIC_ORDER = ['recheck', 'free', 'check'];       // the latest forecast first (a phase as it began)

/**
 * The widths of a bar's segments (fractions, summing to 1, in order), pure: each value > 0 at least `min` (a fraction —
 * a few pixels: always visible), the others shrunk in proportion to make room; 0 stays 0. The words keep the true values.
 */
function segWidths(values, min) {
  const v = values.map((x) => Math.max(0, Number(x) || 0));
  const total = v.reduce((a, x) => a + x, 0);
  if (total <= 0) return v.map(() => 0);
  const live = v.filter((x) => x > 0).length;
  const m = Math.max(0, Math.min(min || 0, 1 / live));
  const out = v.map((x) => x / total);
  const fixed = new Set();
  for (let round = 0; round < v.length; round++) {
    const small = out.map((f, i) => (v[i] > 0 && !fixed.has(i) && f < m ? i : -1)).filter((i) => i >= 0);
    if (!small.length) break;
    small.forEach((i) => { fixed.add(i); out[i] = m; });
    const rest = 1 - fixed.size * m;
    const free = out.reduce((a, f, i) => a + (fixed.has(i) ? 0 : f), 0);
    out.forEach((f, i) => { if (!fixed.has(i) && free > 0) out[i] = f * rest / free; });
  }
  return out;
}

/**
 * The picture's numbers as the page draws them, pure (tests/run.php under node): pic = night.json's `picture`, px = the
 * bar's width in pixels (for the 10 px minimum), done = the run ended, destPx = the target bar's width (its used part keeps
 * the 10 px too). {measured, pool, x (0…1, null: no limit), before,
 * after, forecast (the after bar is a forecast), dest {to, size, used, measured, w}}; a bar: {size, used, jack (all of his),
 * watched (of it what was watched; null: not known — no split), reserve (his reserved room, true value; null: no budget),
 * assumed (forecast: what waits with him not known — he fills his reserve), segs [{k, bytes, w}]} — its free part is what the
 * size leaves (the segments sum to the bar); `jack` = what isn't watched (all of his while watched isn't known); the reserve
 * is drawn out of the free part (never more than it). Not measured: measured false.
 */
function pictureBars(pic, px, done, destPx) {
  const phases = (pic && typeof pic.phases === 'object' && pic.phases) || {};
  const ok = (b) => !!(b && Number(b.size) > 0);
  const first = ['check', 'free', 'recheck', 'fill', 'done'].map((ph) => phases[ph] && phases[ph].before).find(ok) || null;
  const end = done && phases.done && ok(phases.done.before) ? phases.done.before : null;
  const fc = end ? null : PIC_ORDER.map((ph) => phases[ph] && phases[ph].forecast).find(ok) || null;
  const d = pic && pic.dest && typeof pic.dest === 'object' ? pic.dest : null;
  const dm = !!d && d.size != null && d.used != null && Number(d.size) > 0;
  const dest = d ? { to: String(d.to || 'array'), size: dm ? Number(d.size) : null, used: dm ? Number(d.used) : null, measured: dm,
    w: dm ? segWidths([Math.min(Number(d.used), Number(d.size)), Math.max(0, Number(d.size) - Number(d.used))], PIC_MIN_PX / Math.max(40, Number(destPx) || 200))[0] : null } : null;
  if (!first) return { measured: false, pool: (pic && pic.pool) || '', x: null, before: null, after: null, forecast: false, dest };
  const min = PIC_MIN_PX / Math.max(40, Number(px) || 800);
  const bar = (b) => {
    const size = Number(b.size);
    const jack = Math.max(0, Number(b.jack) || 0);
    const watched = b.jack_watched == null || !Number.isFinite(Number(b.jack_watched)) ? null : Math.min(jack, Math.max(0, Number(b.jack_watched)));
    const snaps = b.snapshots == null || !Number.isFinite(Number(b.snapshots)) ? null : Math.max(0, Number(b.snapshots));
    const val = { fixed: Math.max(0, Number(b.fixed) || 0), snaps: snaps || 0, others: Math.max(0, Number(b.others) || 0), jack: jack - (watched || 0), watched: watched || 0 };
    const used = Math.min(size, val.fixed + val.snaps + val.others + jack);
    const free = Math.max(0, size - used);
    const reserve = b.jack_reserve == null || !Number.isFinite(Number(b.jack_reserve)) ? null : Math.max(0, Number(b.jack_reserve));
    val.reserve = Math.min(free, reserve || 0);
    val.free = free - val.reserve;
    const w = segWidths(PIC_SEGS.map((k) => val[k]), min);
    return { size, used, jack, watched, snaps, reserve, assumed: b.assumed === true, segs: PIC_SEGS.map((k, i) => ({ k, bytes: val[k], w: w[i] })) };
  };
  const x = Number(pic.x) > 0 ? Math.min(1, Number(pic.x) / 100) : null;
  const after = end || fc;
  return { measured: true, pool: pic.pool || '', x, before: bar(first), after: after ? bar(after) : null, forecast: !end, dest };
}

// ---- the places of a run the picture has no bar for (the owner, 2026-10-18: a «prefer» share moving array → ripley
// while the picture is the cache's — nothing moved in it): each its own bar beside the target's, growing with what came
// (shrinking with what left), the files flying to it; it stays until the run ends
/**
 * What her run moved per place so far, pure: {place: bytes in (+) and out (−)} — the shares done (their bytes in the
 * part of their files that moved) and the share at work (its bytes done, the file in flight with them); rows: her
 * state's share rows (from, to)
 */
function movedByPlace(p, rows) {
  const out = {};
  if (!p || typeof p !== 'object') return out;
  const way = (share) => (Array.isArray(rows) ? rows : []).find((r) => r && r.share === share && r.from && r.to) || null;
  const add = (share, b) => {
    const r = way(share);
    if (!r || !(b > 0)) return;
    out[r.to] = (out[r.to] || 0) + b;
    out[r.from] = (out[r.from] || 0) - b;
  };
  for (const x of Array.isArray(p.shares) ? p.shares : []) {
    if (!x || x.share === p.share) continue;
    const files = Number(x.files) || 0;
    add(x.share, (Number(x.bytes) || 0) * (files > 0 ? Math.min(1, (Number(x.moved) || 0) / files) : 0));
  }
  if (p.phase === 'share' && p.share) add(p.share, (Number(p.share_done_bytes) || 0) + (Number(p.file_bytes) || 0));
  return out;
}
/**
 * What had moved when $t was, pure: samples [[time, {place: bytes}]] in time order, from 0 at $start (in between: on the
 * line between two readings; after the last: the last)
 */
function movedAt(samples, t, start) {
  const list = Array.isArray(samples) ? samples : [];
  if (!list.length || !(t > start)) return {};
  let a = [start, {}];
  for (const s of list) {
    if (s[0] >= t) {
      const f = s[0] > a[0] ? (t - a[0]) / (s[0] - a[0]) : 1;
      const out = {};
      for (const k of new Set([...Object.keys(a[1]), ...Object.keys(s[1])])) out[k] = (a[1][k] || 0) + f * ((s[1][k] || 0) - (a[1][k] || 0));
      return out;
    }
    a = s;
  }
  return { ...a[1] };
}
/**
 * The run's places without a bar of their own in the picture, pure: [{place, measured, size, used (now), moved (+ in,
 * − out), segs [{k: dest|arrived|free, bytes, w}]}] in the plan's order. pic: the picture (its pool, its dest's to), rows:
 * her share rows, plan: the run's shares, levels: state.levels (a pool's size and use when her state was measured),
 * moved: movedByPlace() summed over the run, then: what had moved when the levels were measured (movedAt()), px: the
 * bar's width (the 10 px minimum)
 */
function extraPlaces(pic, rows, plan, levels, moved, then, px) {
  if (!pic || typeof pic !== 'object') return [];
  const drawn = new Set([pic.pool, pic.dest && typeof pic.dest === 'object' ? String(pic.dest.to || 'array') : null].filter(Boolean));
  const places = [];
  for (const share of Array.isArray(plan) ? plan : []) {
    const r = (Array.isArray(rows) ? rows : []).find((x) => x && x.share === share);
    // only a share moving INTO a place without a bar (a yes share into the array stays as it is): that place, and where
    // it comes from when that has none either (a second pool → its pool)
    if (!r || !r.from || !r.to || drawn.has(r.to)) continue;
    for (const p of [r.to, r.from]) if (!drawn.has(p) && !places.includes(p)) places.push(p);
  }
  const min = PIC_MIN_PX / Math.max(40, Number(px) || 200);
  return places.map((place) => {
    const lv = levels && typeof levels === 'object' && place !== 'array' ? levels[place] : null;
    const m = Math.round(Number((moved || {})[place]) || 0);
    const ok = !!lv && Number(lv.size) > 0 && lv.used != null && Number.isFinite(Number(lv.used));
    if (!ok) return { place, measured: false, size: null, used: null, moved: m, segs: [] };
    const size = Number(lv.size);
    // the pool before the run: its use when measured less what had arrived by then — now that plus what came since
    const before = Math.min(size, Math.max(0, Number(lv.used) - (Number((then || {})[place]) || 0)));
    const used = Math.min(size, Math.max(0, before + m));
    const kept = Math.min(before, used);
    const val = { dest: kept, arrived: used - kept, free: size - used };
    const w = segWidths([val.dest, val.arrived, val.free], min);
    return { place, measured: true, size, used, moved: m, segs: ['dest', 'arrived', 'free'].map((k, i) => ({ k, bytes: val[k], w: w[i] })) };
  });
}
/** Her run's moves per place, kept while the run goes (a joint run calls her part more than once: each its own start) */
let moveBook = { run: null, parts: {}, samples: [], plan: [] };
/** The picture's extra places now (picture, the run going), from the page's state and her live status */
function picExtras(n, px) {
  const pic = n && n.picture;
  if (!pic || !n.running) return [];
  if (moveBook.run !== n.started) moveBook = { run: n.started, parts: {}, samples: [], plan: [] };
  const rows = (state && state.shares) || [];
  const now = Date.now() / 1000;
  if (progress && progress.started) {
    moveBook.parts[progress.started] = movedByPlace(progress, rows);
    const sum = {};
    for (const part of Object.values(moveBook.parts)) for (const [k, v] of Object.entries(part)) sum[k] = (sum[k] || 0) + v;
    const last = moveBook.samples[moveBook.samples.length - 1];
    if (!last || JSON.stringify(last[1]) !== JSON.stringify(sum)) moveBook.samples.push([now, sum]);
    if (moveBook.samples.length > 500) moveBook.samples.splice(0, moveBook.samples.length - 500);
  }
  // every share her parts of this run named (one done stays with its bar until the run ends)
  if (progress && Array.isArray(progress.plan)) for (const sh of progress.plan) if (typeof sh === 'string' && !moveBook.plan.includes(sh)) moveBook.plan.push(sh);
  const plan = moveBook.plan;
  const moved = moveBook.samples.length ? moveBook.samples[moveBook.samples.length - 1][1] : {};
  const then = movedAt(moveBook.samples, Number(state && state.time) || 0, Number(n.started) || 0);
  return extraPlaces(pic, rows, plan, state && state.levels, moved, then, px);
}

/**
 * Which way a file went, pure: onto the pool (Jack Emby fetching from the array; hers only for a share that moves back)
 * or off it — EmbyCache says «array»/«cache», Smart Mover the pool's name and where it moved to
 */
function fileOnto(f, pool) {
  return f.who === 'jack' ? f.from === 'array' : f.to === pool;
}

let picLive = null;               // the picture on the page: its node and update() (the progress part feeds it)
/** The picture's box: two bars, where she moves to, a legend, the file on the way; null without a picture */
function nightPicture(n) {
  let pic = n && n.picture;
  if (!pic || typeof pic !== 'object') return null;
  const box = el('div', 'box mo-pic');
  // its small head: while the run goes a part of her one card (the card's wave at its top), after it a box of its own
  const head = el('div', 'mo-pic-head');
  const title = (nn) => { head.textContent = T(nn.running ? 'pic.title_run' : 'pic.title_last'); };
  title(n);
  const grid = el('div', 'mo-pic-grid');
  const rowsBox = el('div', 'mo-pic-rowsBox');
  const side = el('div', 'mo-pic-dest');
  grid.append(rowsBox, side);
  const legend = el('div', 'mo-pic-legend');
  const line = el('div', 'mo-pic-file');
  const dot = el('span', 'mo-pic-dot');
  dot.setAttribute('aria-hidden', 'true');
  box.append(head, grid, legend, line, dot);
  const pct = (b, size) => fmt.number(size > 0 ? b * 100 / size : 0, 1);
  const segName = (k, b) => T(k === 'jack' && b && b.watched ? 'pic.seg.unwatched' : 'pic.seg.' + k);
  let data = null;
  let extras = [];
  const place = (p) => (p === 'array' ? T('pic.array') : T('pic.pool_name', { pool: p }));
  /** One bar with its title and X; its segments' words on hover (title) */
  function barRow(title, b, note) {
    const row = el('div', 'mo-pic-row');
    const label = el('div', 'mo-pic-label', title);
    if (note) label.appendChild(el('span', 'mo-pic-note', note));
    row.appendChild(label);
    const bar = el('div', 'mo-pic-bar');
    bar.setAttribute('role', 'img');
    bar.setAttribute('aria-label', `${title}: ${T('pic.bar_label', { pct: pct(b.used, b.size) })}`);
    for (const s of b.segs) {
      const seg = el('i', 'mo-pic-seg mo-seg-' + s.k);
      seg.style.width = `${(s.w * 100).toFixed(3)}%`;
      seg.title = T('pic.seg_tip', { what: segName(s.k, b), size: fmt.size(s.bytes), pct: pct(s.bytes, b.size) });
      seg.dataset.k = s.k;
      bar.appendChild(seg);
    }
    if (data.x !== null) {
      const mark = el('span', 'mo-pic-x' + (data.x > 0.85 ? ' flip' : ''));
      mark.style.left = `${(data.x * 100).toFixed(2)}%`;
      mark.appendChild(el('span', '', T('pic.x', { pct: Math.round(data.x * 100) })));
      bar.appendChild(mark);
    }
    row.appendChild(bar);
    return row;
  }
  function draw() {
    const width = rowsBox.clientWidth || (typeof window !== 'undefined' ? Math.min(900, window.innerWidth - 60) : 800);
    data = pictureBars(pic, width, !n.running, side.clientWidth || 200);
    rowsBox.innerHTML = '';
    side.innerHTML = '';
    legend.innerHTML = '';
    if (!data.measured) {
      rowsBox.appendChild(el('p', 'mo-pic-none', T('pic.pool_unmeasured')));
    } else {
      // a btrfs/xfs pool: its numbers estimated (statfs, the folders' du) — said beside «Before»
      rowsBox.appendChild(barRow(T('pic.before'), data.before, pic && pic.estimated === true ? T('pic.estimated') : ''));
      if (data.after) rowsBox.appendChild(barRow(T(data.forecast ? 'pic.forecast' : 'pic.after'), data.after, data.forecast && data.after.assumed ? T('pic.assumed') : ''));
      // the legend: each part before → after (the true values)
      const part = (bar, k) => (!bar ? null : k === 'jack' ? bar.jack : bar.segs.find((s) => s.k === k).bytes);
      for (const k of PIC_SEGS) {
        if (k === 'watched') {
          // «of which watched …»: only once known (his free step's plan) — what goes back in this run
          const wb = data.before.watched;
          if (wb == null) continue;
          const it = el('span', 'mo-pic-key');
          it.append(el('i', 'mo-pic-seg mo-seg-watched'), el('span', '', T('pic.watched', { size: fmt.size(wb) })));
          legend.appendChild(it);
          continue;
        }
        if (k === 'snaps' && data.before.snaps == null && (!data.after || data.after.snaps == null)) continue;     // no figure (not ZFS)
        if (k === 'reserve') {
          // «Jack Streamer reserves … (his budget)»: only with a budget — the true values, before → after
          const rb = data.before.reserve;
          const ra = data.after ? data.after.reserve : null;
          if (rb == null && ra == null) continue;
          const size = ra == null || rb == null || ra === rb ? fmt.size(rb ?? ra) : T('pic.change', { before: fmt.size(rb), after: fmt.size(ra) });
          const it = el('span', 'mo-pic-key');
          it.append(el('i', 'mo-pic-seg mo-seg-reserve'), el('span', '', T('pic.reserve', { size })));
          legend.appendChild(it);
          continue;
        }
        const it = el('span', 'mo-pic-key');
        const b = part(data.before, k);
        const a = part(data.after, k);
        if (k === 'jack' && n.jack === false && !b && !a) continue;      // a night without Jack Emby: his part gone, not «0 B»
        it.append(el('i', 'mo-pic-seg mo-seg-' + k), el('span', '', `${segName(k)} ${a === null ? fmt.size(b) : T('pic.change', { before: fmt.size(b), after: fmt.size(a) })}`));
        legend.appendChild(it);
      }
    }
    // where she moves to: the array or the secondary pool — its use when measured, never a disk woken for it
    const d = data.dest;
    if (d) {
      side.appendChild(el('div', 'mo-pic-label', d.to === 'array' ? T('pic.array') : T('pic.pool_name', { pool: d.to })));
      const dbar = el('div', 'mo-pic-bar mo-pic-target');
      if (d.measured) {
        const used = el('i', 'mo-pic-seg mo-seg-dest');
        used.style.width = `${(d.w * 100).toFixed(2)}%`;
        dbar.appendChild(used);
      }
      side.appendChild(dbar);
      const words = d.measured ? T('pic.dest_used', { used: fmt.size(d.used), size: fmt.size(d.size) }) : T('pic.unmeasured');
      side.appendChild(el('div', 'mo-pic-sub', words));
      // in the legend too, in its own colour
      if (data.measured) {
        const it = el('span', 'mo-pic-key');
        it.append(el('i', 'mo-pic-seg mo-seg-dest'), el('span', '', `${d.to === 'array' ? T('pic.array') : T('pic.pool_name', { pool: d.to })} ${words}`));
        legend.appendChild(it);
      }
    }
    // the run's other places (a «prefer» share into another pool): a bar each under the target's, what came in this run
    // in its own lighter part (what flies to it has that colour)
    extras = picExtras(n, side.clientWidth || 200);
    for (const x of extras) {
      const wrap = el('div', 'mo-pic-extra');
      wrap.dataset.place = x.place;
      wrap.appendChild(el('div', 'mo-pic-label', place(x.place)));
      const xbar = el('div', 'mo-pic-bar mo-pic-more');
      xbar.setAttribute('role', 'img');
      for (const sg of x.segs) {
        const seg = el('i', 'mo-pic-seg mo-seg-' + sg.k);
        seg.style.width = `${(sg.w * 100).toFixed(3)}%`;
        xbar.appendChild(seg);
      }
      const came = x.moved > 0 ? T('pic.arrived', { size: fmt.size(x.moved) }) : x.moved < 0 ? T('pic.went', { size: fmt.size(-x.moved) }) : '';
      const words = [x.measured ? T('pic.dest_used', { used: fmt.size(x.used), size: fmt.size(x.size) }) : T('pic.unmeasured'), came].filter(Boolean).join(' · ');
      xbar.setAttribute('aria-label', `${place(x.place)}: ${words}`);
      wrap.append(xbar, el('div', 'mo-pic-sub', words));
      side.appendChild(wrap);
      if (data.measured) {
        const it = el('span', 'mo-pic-key');
        it.append(el('i', 'mo-pic-seg mo-seg-arrived'), el('span', '', `${place(x.place)} ${words}`));
        legend.appendChild(it);
      }
    }
    say(lastFile());
  }
  const files = () => (Array.isArray(pic.files) ? pic.files.filter((f) => f && typeof f.name === 'string') : []);
  const lastFile = () => files().slice(-1)[0] || null;
  const short = (name) => { const b = String(name).split('/').pop(); return b.length > 48 ? b.slice(0, 46) + '…' : b; };
  /** A file of hers between two places neither of which is the picture's pool (2026-10-18: a «prefer» share into another
   *  pool, array → ripley while the picture is the cache's) — named by its own ends, never flown across these bars */
  const elsewhere = (f) => f.who !== 'jack' && typeof f.from === 'string' && typeof f.to === 'string' && f.from !== '' && f.to !== ''
    && f.from !== pic.pool && f.to !== pic.pool;
  /** A file of hers to or from one of the run's other places (their bars are drawn): it flies between its own ends */
  const toExtra = (f) => f.who !== 'jack' && extras.some((x) => x.place === f.from || x.place === f.to);
  const flies = (f) => !elsewhere(f) || toExtra(f);
  /** Where a file was and went in words: Jack Emby's between the pool and the array, hers between the pool and her target */
  function ends(f) {
    if (elsewhere(f)) return [place(f.from), place(f.to)];
    const pool = T('pic.pool_name', { pool: pic.pool || '' });
    const to = f.who === 'jack' || !data || !data.dest || data.dest.to === 'array' ? T('pic.array') : T('pic.pool_name', { pool: data.dest.to });
    return fileOnto(f, pic.pool) ? [to, pool] : [pool, to];
  }
  // while the run goes no file line here (the run's part says the one in flight); after it «Last moved: …»
  function say(f) {
    line.textContent = '';
    if (!f || n.running) return;
    const [from, to] = ends(f);
    line.textContent = T('pic.last_file', { file: short(f.name), size: fmt.size(Number(f.bytes) || 0), from, to });
  }
  // the slow walk: one real file at a time from where it was to where it went (her files and Jack Emby's watched ones
  // off the pool, his new ones onto it); the newest as they come, the latest few again while nothing new comes — calm
  const seen = new Set();
  const queue = [];
  const key = (f) => `${f.who}|${f.name}|${f.bytes}`;
  function feed() {
    for (const f of files()) if (!seen.has(key(f)) && flies(f)) { seen.add(key(f)); queue.push(f); }
  }
  let walking = false;
  async function walk() {
    if (walking) return;
    walking = true;
    while (box.isConnected && n.running && data && data.measured && !reducedMotion()) {
      if (!queue.length) queue.push(...files().filter(flies).slice(-6));
      if (!queue.length) break;
      await flyFile(queue.shift());
      await new Promise((r) => setTimeout(r, PIC_PAUSE_MS));
    }
    walking = false;
  }
  /** The middle of a part of the picture, in the box's own pixels (a bar segment: near its start) */
  function spot(node) {
    if (!node) return null;
    const r = node.getBoundingClientRect();
    const b = box.getBoundingClientRect();
    return { x: r.left - b.left + Math.min(r.width / 2, 40), y: r.top - b.top + r.height / 2 };
  }
  /** A segment of a bar, the first of the kinds with something in it (Jack Streamer's watched, else his part) */
  function segOf(bar, kinds) {
    if (!bar) return null;
    for (const k of kinds) {
      const s = bar.querySelector(`.mo-seg-${k}`);
      if (s && parseFloat(s.style.width) > 0) return s;
    }
    return bar.querySelector(`.mo-seg-${kinds[kinds.length - 1]}`);
  }
  /** A place's bar in the picture: an extra one (its part that came, else the bar), the target, the pool */
  function spotOf(p) {
    const x = Array.from(box.querySelectorAll('.mo-pic-extra')).find((w) => w.dataset.place === p);
    if (x) return spot(segOf(x.querySelector('.mo-pic-bar'), ['arrived', 'dest']) || x.querySelector('.mo-pic-bar'));
    if (data && data.dest && data.dest.to === p) return spot(box.querySelector('.mo-pic-target'));
    if (p === pic.pool) return spot(segOf(box.querySelector('.mo-pic-rowsBox .mo-pic-bar'), ['others']));
    return null;
  }
  function flyFile(f) {
    if (toExtra(f)) {
      // array → ripley (or a second pool → its pool): from its own end to its own end, in the colour of what came
      const a = spotOf(f.from);
      const b = spotOf(f.to);
      if (!a || !b || !dot.animate) return Promise.resolve();
      dot.className = 'mo-pic-dot mo-fly-arrived';
      dot.innerHTML = picFlyer(false, b.x < a.x);
      dot.getAnimations().forEach((x) => x.cancel());
      return dot.animate(picFlight(a, b), { duration: PIC_FLIGHT_MS, easing: 'linear', fill: 'forwards' }).finished.catch(() => {});
    }
    const onto = fileOnto(f, pic.pool);
    const rows = box.querySelectorAll('.mo-pic-rowsBox .mo-pic-bar');
    // Jack Streamer's off the pool: what was watched (green), onto it: his colour; hers: the other shares'
    const k = f.who === 'jack' ? (onto ? 'jack' : 'watched') : 'others';
    const kinds = k === 'watched' ? ['watched', 'jack'] : [k];
    const target = spot(box.querySelector('.mo-pic-target'));
    const from = onto ? target : spot(segOf(rows[0], kinds));
    const to = onto ? spot(segOf(rows[1] || rows[0], kinds)) : target;
    if (!from || !to || !dot.animate) return Promise.resolve();
    dot.className = 'mo-pic-dot mo-fly-' + k;
    dot.innerHTML = picFlyer(f.who === 'jack', to.x < from.x);
    dot.getAnimations().forEach((x) => x.cancel());       // the last flight's (kept at its end) — one at a time on it
    return dot.animate(picFlight(from, to), { duration: PIC_FLIGHT_MS, easing: 'linear', fill: 'forwards' })
      .finished.catch(() => {});
  }
  draw();
  feed();
  box.update = (nn) => {
    if (!nn || !nn.picture) return;
    n = nn;
    pic = nn.picture;
    title(n);
    draw();
    feed();
    walk();
  };
  box.started = n.started;
  if (typeof window !== 'undefined') setTimeout(walk, 600);
  return box;
}

/** The picture while the night run goes: the one on the page fed anew (its walk goes on), else a new one */
function livePicture(nn) {
  if (!nn || !nn.picture) return null;
  if (picLive && picLive.started === nn.started) { picLive.update(nn); return picLive; }
  picLive = nightPicture(nn);
  return picLive;
}
/** A whole trip of a file between the bars (the owner, 2026-10-15: slow — the reel readable) */
const PIC_FLIGHT_MS = 14000;
const PIC_PAUSE_MS = 3000;       // between two files
const PIC_BOBS = 10;              // the gentle ups and downs over the trip (half as many waves)
const PIC_STEP_MS = 100;          // one keyframe every … ms: a smooth curve, played linearly
const PIC_BOB = 4;                // px of each
const PIC_LIFT = 34;              // px the flyer rises above the higher of its two ends (never above the box's top part)
const PIC_TILT = 10;              // degrees it leans into its flight (to the right: clockwise) — the direction at a glance
/**
 * One trip's keyframes, pure (tests/run.php): out of the bar it leaves very small, slowly growing to full size while it
 * rises, across at full size leaning into its way, then slowly shrinking and sinking into the bar it reaches (his fetch:
 * out of the array into the pool, his give-back and hers the other way — fileOnto()); {x, y} in the box's pixels.
 * The owner, 2026-10-17: one even pace, no start and stop — sideways it moves at the same speed the whole trip, rising,
 * growing and bobbing are smooth curves sampled every PIC_STEP_MS and played linearly (no easing per keyframe).
 */
function picFlight(from, to) {
  const top = Math.max(PIC_LIFT, Math.min(from.y, to.y) - PIC_LIFT);
  const tilt = to.x >= from.x ? PIC_TILT : -PIC_TILT;
  const grow = 2500 / PIC_FLIGHT_MS;          // 2.5 s growing out of the bar
  const sink = 1 - 3500 / PIC_FLIGHT_MS;      // 3.5 s shrinking into the other
  const smooth = (u) => u * u * (3 - 2 * u);
  const steps = Math.round(PIC_FLIGHT_MS / PIC_STEP_MS);
  const frames = [];
  for (let i = 0; i <= steps; i++) {
    const t = i / steps;
    const up = t < grow ? smooth(t / grow) : t > sink ? smooth((1 - t) / (1 - sink)) : 1;
    const scale = 0.12 + 0.88 * up;
    const x = from.x + (to.x - from.x) * t;
    const y = from.y + (to.y - from.y) * t;
    const bob = PIC_BOB * up * Math.sin(2 * Math.PI * PIC_BOBS / 2 * t);
    const opacity = i === 0 || i === steps ? 0 : 1;
    frames.push({ transform: `translate(${x.toFixed(1)}px, ${(y + (top - y) * up + bob).toFixed(1)}px) rotate(${(tilt * up).toFixed(1)}deg) scale(${scale.toFixed(3)})`, opacity, offset: t });
  }
  return frames;
}

let picSeq = 0;

/**
 * What flies between the pool and the array / second pool (the owner, 2026-10-15: the reel without wings): a silver film
 * reel seen a little from the side — its rim and thickness, a sheen on the metal, five round holes showing the golden film
 * wound inside, turning about its hub — with a short strip of film trailing behind it, for Jack Streamer's files; a block
 * for hers. $left: it flies to the left (all
 * mirrored: the strip trails behind). Our own drawing, inline SVG, colours from the desk's tokens (the reel's rim and the
 * block in currentColor = the part's colour), no data inside.
 */
function picFlyer(reel, left = false) {
  const still = reducedMotion();
  const g = `mo-fly${++picSeq}-`;
  const cx = 75;
  const cy = 42;
  const R = 26;
  const sq = 0.84;                 // seen a little from the side
  const holes = Array.from({ length: 5 }, (_, i) => {
    const a = i * 2 * Math.PI / 5;
    return [Number((13.6 * Math.sin(a)).toFixed(2)), Number((-13.6 * Math.cos(a)).toFixed(2))];
  });
  const circle = (x, y, r) => `M${x - r} ${y}a${r} ${r} 0 1 0 ${2 * r} 0a${r} ${r} 0 1 0 ${-2 * r} 0Z`;
  const defs = '<defs>'
    + `<linearGradient id="${g}m" x1="0" y1="0" x2="1" y2="1"><stop offset="0" class="mo-metal-light"/><stop offset=".55" class="mo-metal"/><stop offset="1" class="mo-metal-dark"/></linearGradient>`
    + `<radialGradient id="${g}w"><stop offset=".3" class="mo-gold-dark"/><stop offset="1" class="mo-gold"/></radialGradient></defs>`;
  const film = ['M68 62C60 74 48 70 37 76S20 86 9 83', 'M68 62C60 72 48 74 37 78S20 82 9 86'];
  const wave = still ? '' : `<animate attributeName="d" dur="1.1s" values="${film[0]};${film[1]};${film[0]}" calcMode="spline" keyTimes="0;0.5;1" keySplines="0.4 0 0.6 1;0.4 0 0.6 1" repeatCount="indefinite"/>`;
  const strip = (cls) => `<path class="${cls}" d="${film[0]}">${wave}</path>`;
  const body = reel
    ? strip('mo-film-edge') + strip('mo-film') + strip('mo-film-holes') + strip('mo-film-mid')
      // its thickness, then the face: the film wound on the hub, the turning plate with its holes over it, the sheen, the rim
      + `<ellipse cx="${cx - 5}" cy="${cy}" rx="${R * sq}" ry="${R}" class="mo-reel-side"/><rect x="${cx - 5}" y="${cy - R}" width="5" height="${2 * R}" class="mo-reel-band"/>`
      + `<g transform="translate(${cx} ${cy}) scale(${sq} 1)"><circle r="${R - 1}" class="mo-reel-gap"/>`
      + `<circle r="18" fill="url(#${g}w)" class="mo-reel-film"/><circle r="11" class="mo-reel-wound"/><circle r="14.5" class="mo-reel-wound"/>`
      + `<g class="mo-fly-reel"><path class="mo-reel-plate" fill="url(#${g}m)" fill-rule="evenodd" d="${circle(0, 0, R)}${holes.map(([x, y]) => circle(x, y, 6)).join('')}"/>`
      + `<circle r="5" class="mo-reel-hub"/>${holes.slice(0, 3).map(([x, y]) => `<circle cx="${(x * 0.19).toFixed(2)}" cy="${(y * 0.19).toFixed(2)}" r="0.9" class="mo-reel-dot"/>`).join('')}</g>`
      + `<path class="mo-reel-sheen" fill-rule="evenodd" d="M-24 -6A24 24 0 0 1 6 -23.2L5 -19.4A20 20 0 0 0 -19.6 -4Z"/>`
      + `<circle r="${R}" class="mo-reel-rim"/></g>`
    : `<rect x="${cx - 15}" y="${cy - 15}" width="30" height="30" rx="5" fill="currentColor" class="mo-fly-body"/>`;
  const all = body;
  return `<svg viewBox="0 0 150 96" width="112" height="72" focusable="false" aria-hidden="true">${defs}`
    + (left ? `<g transform="translate(150 0) scale(-1 1)">${all}</g>` : all) + '</svg>';
}
const reducedMotion = () => !!(typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

async function act(action, data, okText) {
  const j = await Office.api.post(`${ID}.${action}`, data || {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return null; }
  if (j.state) state = j.state;
  if (okText) Office.toast(okText);
  if (view && page === 'main') render();
  return j;
}

// ------------------------------------------------------------------ helpers
function button(text, kind, onclick) {
  const b = el('button', 'btn' + (kind ? ' ' + kind : ''), text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}
function chip(text, cls, title) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (title) c.title = title;
  return c;
}
/**
 * The fill as words (2026-10-12; only X since 2026-10-14): «from 80 % the oldest first, until below it» — at/above X the
 * oldest across the pool go, whatever their age; off (no limit): «whatever the fill» (the age rule alone)
 */
function fillText(threshold) {
  return Number(threshold) ? T('rule_fill', { pct: threshold }) : T('rule_always');
}
function section(title, sub, ...extra) {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(title, sub, ...extra));
  return s;
}
/** A button that reads as a quiet link */
function link(text, onclick) {
  const b = el('button', 'mo-link', text);
  b.type = 'button';
  b.onclick = onclick;
  return b;
}
/** An error line inside a dialog: a refusal stays readable there (the dialog stays open) */
function errorLine() {
  const box = el('div', 'callout warn');
  box.setAttribute('role', 'alert');
  box.style.display = 'none';
  return box;
}
function showError(box, error) {
  box.innerHTML = '';
  box.appendChild(el('div', '', Office.errorText(error, ID)));
  box.style.display = '';
  box.scrollIntoView({ block: 'nearest' });     /* reveal */ // the error line inside its dialog
}

const running = () => !!(state && state.job && state.job.running);
/** Her last run that moved or would have (a real or a dry run that started) — a look at the shares' list is none */
const lastRun = () => ((state && state.history) || []).find((r) => ['run', 'dry'].includes(r.mode) && !['refused', 'skipped'].includes(r.result)) || null;
const realRun = () => (running() && state.job.mode === 'run') || nightGoing();
const unraidOk = () => !!(state && state.unraid && state.unraid.ok);
const mover = () => (state && state.mover) || { off: true, schedule: null, running: false };
const jack = () => (state && state.jack) || { busy: false, list: null, uncovered: [] };
/**
 * Jack Emby's shares a setup form doesn't keep EmbyCache's list away from — the agent's rule (jackMoverelliUncovered())
 * on the form not yet saved: a share of his is covered when it is left out, or when one form of his list's path stands
 * in the files for all shares or in its own. None while he isn't hired (no list).
 */
function jackUncoveredIn(f) {
  const j = jack();
  const lists = (j.lists && j.lists.length ? j.lists : [j.list]).filter(Boolean);
  if (!lists.length || !f) return [];
  const global = (f.global && f.global.files) || [];
  return (j.shares || []).filter((share) => {
    const own = (f.shares || {})[share];
    if (own && own.skip) return false;
    return !lists.some((l) => global.includes(l) || (own && (own.files || []).includes(l)));
  });
}
/** Other movers on a schedule of their own: Mover Tuning's (refuses real runs), scripts that call a mover (named) */
const foreign = () => (state && state.foreign) || { tuning: { installed: false, active: false, lines: [] }, scripts: [] };
/** May real runs go: Unraid's schedule off, Mover Tuning without a schedule of its own */
/** Another mover on a schedule of its own besides Unraid's: Mover Tuning's, or any other (moverelliMovers()) */
const otherMovers = () => foreign().tuning.active || (foreign().movers || []).length > 0;
const othersOff = () => mover().off && !otherMovers();
/** The night run (agent/lib/nightrun.php): with Jack Emby, tonight's plan, the last night or the one going */
const night = () => (state && state.night) || { jack: false, plan: null, last: null };
/** A real night run going (her tool or Jack Emby's at work in it) */
const nightGoing = () => !!(night().last && night().last.running && night().last.mode === 'run');
/**
 * The kind of the run going (run | dry | list), null when none: a night's own (its record — her job's file may still be
 * the last run's while the night checks or Jack Streamer's step goes), else her job's
 */
const goingMode = () => (night().last && night().last.running ? night().last.mode || 'run' : running() ? state.job.mode : null);
/** «cache», «array» in words: the array is named in the page's language */
const where = (x) => (x === 'array' ? T('array') : x || '–');
function schedText(sc) {
  if (!sc || !sc.enabled) return T('not_scheduled');
  return sc.frequency === 'custom' ? fmt.cron(sc.custom) : sc.frequency;
}

/** A refused run's reason as its row keeps it (moverelli.php moverelliRefusedParams): every placeholder of her refusals filled */
const REFUSED_PARAMS = ['schedule', 'shares', 'version', 'file', 'path', 'name', 'when'];
function refusedParams(r) {
  const p = { needed: (state && state.unraid && state.unraid.needed) || '' };
  for (const k of REFUSED_PARAMS) p[k] = r[k] == null ? '' : String(r[k]);
  return p;
}

/** One line about how a run went (her list of runs, the last run) */
function runSummary(r) {
  if (r.result === 'refused') {
    return T('refused', { why: Office.errorText({ key: r.why, params: refusedParams(r) }, ID) })
      + (Number(r.times) > 1 ? ' · ' + T('refused_times', { n: Number(r.times) }) : '');
  }
  if (r.result === 'skipped') return T('skipped_mover', { min: Math.round((r.waited || 0) / 60) });
  const s = r.status || r;
  const mode = r.mode || s.mode;
  if (s.result === 'busy') return T('result.busy');
  if (s.result === 'config') return s.message || T('result.config');
  if (mode === 'list') return nOf('shares', (s.shares || []).length);
  const notes = [];
  if (r.result === 'interrupted') {
    // cut short without her end (a power cut, a restart, the job killed): what had moved by then, when it is known
    const why = T(r.why === 'restart' ? 'interrupted_restart' : 'interrupted_ended');
    if (s.files == null) return why;
    notes.push(why);
  }
  if (s.result === 'stopped') {
    const file = String(r.stop_file || s.stop_file || '').split('/').pop() || '?';
    notes.push(r.why === 'mover' ? T(r.mover_by === 'schedule' ? 'stopped_mover_schedule' : 'stopped_mover')
      : r.why === 'now' ? T('stopped_now', { file }) : r.why === 'array' ? T('stopped_array', { file })
        : T('stopped_user', { file, share: s.stop_share || '?' }));
  }
  // D-F7: a run that found no file says so, never «0 of 0 moved (0 B)» (a stopped one may not have looked yet)
  if (s.result !== 'stopped' && !Number(s.files) && !Number(s.moved) && !Number(s.left)) notes.push(T('run_nothing'));
  else if (mode === 'dry') notes.push(T('dry_summary', { files: nOf('files', s.files), size: fmt.size(s.bytes || 0) }));
  else notes.push(T('run_summary', { moved: s.moved || 0, files: s.files || 0, size: fmt.size(s.bytes || 0), left: nOf('left', s.left) }));
  if (s.errors) notes.push(nOf('errors', s.errors));
  if (r.waited) notes.push(T('waited', { min: Math.round(r.waited / 60) }));
  if (r.jack_out) notes.push(jackOutText(r.jack_out));
  return notes.join(' · ');
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (!unraidOk()) return `${intro} ${T('bubble.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (running() || nightGoing()) return runningText();
  if (!othersOff()) return T('bubble.dry');
  if (!state.schedule.enabled) return T('bubble.no_schedule');
  return T('bubble.ready');
}

/**
 * Her bubble while a real run moves a share (the owner, 2026-10-18: «off to the array!» while nextcloud_data went from the
 * array into the pool ripley), pure: the way of the share in flight (Smart Mover's live status, `progress.share`) as her
 * share table has it — into the array, back into its pool (prefer), on into its second pool — and which files: older than
 * its age rule, the oldest first (a fill rule only), else all of them (but what her lists keep). No share in flight yet (looking around, tidying up,
 * a list or a dry run): «I'm at work».
 */
function runningText() {
  const sh = realRun() && progress && progress.share ? ((state && state.shares) || []).find((x) => x.share === progress.share) : null;
  if (!sh || !sh.to) return T('bubble.running');
  const age = Number(sh.min_age) || 0;
  const what = age > 0 ? T('bubble.what_old', { n: age }) : Number(sh.threshold) > 0 ? T('bubble.what_oldest')
    : T(Number(sh.excludes) > 0 ? 'bubble.what_all_but' : 'bubble.what_all');
  const way = sh.to === 'array' ? 'to_array' : sh.use === 'prefer' ? (sh.from === 'array' ? 'back_from_array' : 'back_from_pool') : 'to_pool';
  return T('bubble.run_' + way, { what, share: sh.share, from: sh.from || '', to: sh.to });
}

// her greetings that say files go into the array (greet.1 «old files … off to the array», greet.2): only while none of her
// runs goes and one of her shares really goes that way by age or fill — never beside a run into a pool (the bubble says
// that run's way then: no greeting at all while she works). 2026-10-18: greet.3 «it stays on the fast pool» only with a
// share of hers on a pool, greet.5 «Jack Streamer! Hands off the pool» only while Jack Streamer works here. core.js
// Office.greet picks only among those true now (her page and the reception's card alike)
const GREET_TO_ARRAY = ['greet.1', 'greet.2'];
const onPool = (x) => [x.from, x.to, x.primary].some((p) => typeof p === 'string' && p !== '' && p !== 'array');
function greetOk(key) {
  if (running() || nightGoing()) return false;
  const shares = (state && state.shares) || [];
  if (GREET_TO_ARRAY.includes(key)) return shares.some((x) => x.state === 'moves' && x.to === 'array' && (Number(x.min_age) > 0 || Number(x.threshold) > 0));
  if (key === 'greet.3') return shares.some(onPool);
  if (key === 'greet.5') return !!((Office.desks && Office.desks.get && Office.desks.get('jack')) || {}).hired;
  return true;
}
function greeting() {
  const g = Office.greet(ID);
  const key = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => `greet.${i}`).find((k) => g === T(k));
  return g && key && !greetOk(key) ? '' : g;
}
const withGreeting = (text) => { const g = greeting(); return g ? `${g} ${text}` : text; };

// ------------------------------------------------------------------ Unraid's mover
/**
 * Unraid's own mover schedule: on — why only lists and dry runs go, «Switch Unraid's mover schedule off…» and «Set it
 * yourself» (Unraid's ⟦Mover Settings⟧); off — that ⟦Move now⟧ by hand still runs Unraid's mover. At work right now.
 */
function moverNotice() {
  const m = mover();
  const box = el('div', m.off ? 'mo-mover' : 'callout warn mo-mover');
  if (!m.off) {
    box.appendChild(el('p', '', T('mover.on', { schedule: m.schedule ? fmt.cron(m.schedule) : '?' })));
    const bar = el('div', 'toolbar');
    const b = button(T('mover.off'), 'small', moverOffDialog);
    b.disabled = !Office.agent.running;
    const a = el('a', 'btn small plain', T('mover.self'));
    a.href = '/Settings/MoverSettings';
    bar.append(b, a);
    box.appendChild(bar);
  } else {
    box.appendChild(el('p', 'role', T('mover.ok')));
  }
  if (m.running) box.appendChild(el('p', 'role', T('mover.running')));
  return box;
}

/**
 * The other movers that run on a schedule of their own besides Unraid's: Mover Tuning's own cron line (real runs refused
 * until it is off — in Mover Tuning's settings, never changed by her), User Scripts and cron lines that call a mover
 * (named); and always the hint that such scripts belong switched off while she is in charge.
 */
/** A schedule as her page says it: a cron line in words, else User Scripts' own word (daily, hourly …) as it is */
const whenText = (w) => (/^\S+(\s+\S+){4}$/.test(w) ? fmt.cron(w) : w);
function foreignNotices() {
  const f = foreign();
  const out = [];
  if (f.tuning.active) {
    const l = f.tuning.lines[0] || {};
    out.push(el('p', 'callout warn', T('notice.tuning', { schedule: l.cron ? fmt.cron(l.cron) : '?', file: l.file || '' })));
  }
  // every other mover on a schedule of its own (moverelliMovers()): named with its schedule — her real runs wait
  const movers = Array.isArray(f.movers) ? f.movers : (f.scripts || []).map((name) => ({ name, when: '' }));    // an older agent: names only
  movers.forEach((x) => out.push(el('p', 'callout warn', T('notice.foreign_mover', { name: x.name, when: x.when ? whenText(x.when) : '?' }))));
  if (!movers.length) out.push(el('p', 'role mo-hint', T('notice.scripts_hint')));
  return out;
}

/** «Switch Unraid's mover schedule off…»: what it does, then moverelli.mover_off {confirm: true} */
async function moverOffDialog() {
  if (!(await Office.freshState(ID))) return;
  if (mover().off) { render(); return; }      // off meanwhile: the notice says so
  const body = el('div');
  const err = errorLine();
  body.append(el('p', '', T('mover.off_text')), el('p', 'role', T('mover.off_move_now')), el('p', 'role', T('mover.off_back')), err);
  Office.dialog({
    title: T('mover.off_title'),
    body,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('mover.off_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.mover_off`, { confirm: true });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      Office.toast(T('mover.off_done'));
      if (view && page === 'main') render();
      return true;
    } }],
  });
}

/**
 * Her taking Unraid's mover by the hand — a section of the apply dialog while its own schedule is on: the schedule it
 * had, the ⟦Move now⟧ warning (Jack Emby's films named while he is hired), her let-go gives it back. No Settings path
 * here (that is in her help).
 */
function consentSection() {
  const m = mover();
  const box = el('div', 'mo-consent');
  box.append(el('p', 'mo-imp-head', T('consent.title')), el('p', '', T('consent.text', { schedule: m.schedule ? fmt.cron(m.schedule) : '?' })),
    el('p', 'role', T(jack().hired ? 'consent.move_now' : 'consent.move_now_solo')), el('p', 'role', T('consent.back')));
  return box;
}

// ------------------------------------------------------------------ main view
/*
 * Her page (tiles v2, CLAUDE.md «Tiles», 2026-10-12): a tile is a section. The row — «Schedule» (hers and Unraid's
 * mover's), «Shares» (what moves where, the rules for all), «Last run» (her runs, the last one's result on top) — is the
 * page's table of contents; under it stands the chosen tile's section alone, or, nothing chosen (the start), every section
 * in the row's order — her page as it was. Hints stay above the row at every click (Unraid's mover, other movers, Jack
 * Emby, a run going, not set up), the tool's version and the credit to helmi1987 under all. Two pages as tabs: «Overview»
 * and «Set up» (#/moverelli/setup).
 */
const PAGES = () => [{ id: '', text: Office.t('common.page_overview') }, { id: 'setup', text: T('page.setup') }];
/** Set up (or not known yet — the search's places): her schedule has a tile only then */
const configured = () => !state || !!state.configured;
/** [tile id, lang key] of her row, in its order (the switcher's tiles, her places) */
const TILES = [['schedule', 'schedule'], ['shares', 'shares'], ['runs', 'last_run']];
let sw = null;
/** Her row and its sections (core.js's section switcher) — made at the first drawing, one per page life */
function switcher() {
  if (sw) return sw;
  sw = Office.sections({
    id: ID,                          // kept as moverelli.tile, routes #/moverelli/<tile>
    grid: 'stats mo-tiles',
    default: '',                     // the start: every section (her page as it always was)
    tiles: () => tileList(),
  });
  return sw;
}
/** The tiles for the switcher, each with its section (the schedule only once she is set up) */
function tileList() {
  const make = { schedule: scheduleTile, shares: sharesTile, runs: lastRunTile };
  const section = { schedule: scheduleSection, shares: shareSection, runs: historySection };
  return TILES.filter(([id]) => id !== 'schedule' || configured())
    .map(([id, key]) => ({ id, key, place: `tile.${id}`, tile: (a) => (state ? make[id](a) : Office.stat({ label: T(key), value: '–', ...a })),
      section: () => (state ? section[id]() : null) }));
}

/** The desk's own actions — on both tabs (the other page is the tab, no head button for it) */
function headActions() {
  const actions = [];
  if (state && state.configured) {
    actions.push(button(T('list'), 'plain', () => startRun('list')));
    actions.push(button(T('start'), '', chooseRun));
  }
  actions.forEach((b) => { b.disabled = !Office.agent.running || running() || !unraidOk(); });
  return actions;
}
/** Her head with the two tabs (Office.pages()) */
function deskHead() {
  return Office.deskHead(Office.desks.get(ID), { bubble: withGreeting(bubbleText()), actions: headActions(),
    pages: { list: PAGES(), current: page === 'setup' ? 'setup' : '' } }).head;
}

function render() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID, [
    [T('help.what'), T('help.what_text')],
    [T('list'), T('help.list')],
    [T('mode.dry'), T('help.dry')],
    [T('mode.run'), T('help.run')],
    [T('help.rules'), T('help.rules_text')],
    [T('help.unraid'), T('help.unraid_text')],
    [T('help.jack'), T('help.jack_text')],
    [T('help.schedule'), T('help.schedule_text')],
  ]));
  if (!state) return;

  // the row first (the house rule: tiles right under the head), then the hints, no sections: they stay at every click
  const s = switcher();
  root.appendChild(s.el);
  underRow(s, hints());
  s.render();
  root.appendChild(footer());
}

/** Hints right under the row, over the sections: one box in the switcher (it lives as long as the page; core.js redraws only the row and the body), filled anew */
function underRow(s, nodes) {
  if (!s.hints) s.hints = s.el.insertBefore(el('div', 'mo-hints'), s.body);
  s.hints.innerHTML = '';
  nodes.forEach((n) => s.hints.appendChild(n));
}

/** What stands under the row: Unraid's mover, other movers, Jack Emby, a run going (the night's too), not set up */
function hints() {
  const out = [];
  if (!unraidOk()) out.push(el('p', 'callout warn', T('notice.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })));
  if (state.configured) {
    out.push(moverNotice());
    out.push(...foreignNotices());
  }
  const j = jack();
  if (j.busy && !running() && !nightGoing()) out.push(el('p', 'callout', T('notice.jack_busy')));
  // EmbyCache's list in her rules before EmbyCache's first run: her real runs wait for it (said here, never notified)
  if (j.pending) out.push(el('p', 'callout', T('notice.jack_pending')));
  if ((j.uncovered || []).length) {
    const p = el('p', 'callout warn', T('notice.jack_list', { shares: j.uncovered.join(', '), file: j.list || '' }) + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    out.push(p);
  }
  // the last run: Unraid's mover started while she ran — she stopped at once and cleaned up
  const last = (state.history || [])[0];
  if (!running() && last && last.result === 'stopped' && last.why === 'mover') {
    out.push(el('p', 'callout warn', T(last.mover_by === 'schedule' ? 'stopped_mover_schedule' : 'stopped_mover')));
  }
  if (realRun()) {
    // ONE card while the run goes (owner 2026-10-15): the run (steps, progress, buttons), Jack Streamer at work (his step
    // only), the pool in this run — one wave at its top, the parts below sections inside it (a divider, a small head)
    const one = el('div', 'card mo-one running');
    one.appendChild(runCard(progress, Date.now() / 1000));
    // right under it, while Jack Streamer's step runs: his live panel (the same as on his page)
    const jp = jackPanel(nightNow || (nightGoing() ? night().last : null));
    if (jp) one.appendChild(jp);
    // under the card: the night's picture — the pool before, the forecast after, where she moves to, files on the way
    const pic = livePicture(nightNow || (nightGoing() ? night().last : null));
    if (pic) one.appendChild(pic);
    out.push(one);
  } else if (running()) {
    const p = el('p', 'callout', T('notice.running', { mode: T('mode.' + state.job.mode) }) + ' ');
    p.appendChild(button(T('rc.show_log'), 'small plain', () => showOutput(true))).disabled = !Office.agent.running;
    if (realRun()) {
      p.appendChild(document.createTextNode(' '));
      const stop = button(state.stopping ? T('stopping') : T('stop'), 'small plain', async () => { stop.disabled = true; await act('stop'); });
      stop.disabled = !!state.stopping || !Office.agent.running;
      p.appendChild(stop);
    }
    out.push(p);
  }
  if (!state.configured) {
    const p = el('p', 'callout', T('notice.setup') + ' ');
    p.appendChild(button(T('setup_open'), 'small', () => Office.go(`#/${ID}/setup`)));
    out.push(p);
  }
  return out;
}

// ---- the tiles: each the summary of its section
/** «Schedule»: the night run's (a time, none), tonight's plan, Unraid's mover's as its line — alert while nothing starts it or Unraid's is on */
function scheduleTile(a) {
  const sc = state.schedule || {};
  const m = mover();
  const n = night();
  const sub = [sc.enabled ? T(n.jack ? 'night.with_jack' : 'by_office') : T('tile.no_schedule')];
  const short = state.configured ? tonightShort(n.plan, ageRule()) : null;
  if (short) sub.push(short);
  sub.push(`${T('unraid_schedule')}: ${m.off ? T('unraid_off') : T('unraid_on')}`);
  return Office.stat({ label: T('schedule'), value: schedText(sc), sub, tip: sub.join('\n'), alert: !m.off || !sc.enabled, ...a });
}

/**
 * Tonight's plan in words, pure (tests/run.php under node): «Tonight: Jack Emby gives back 70 GB · Ms. Moverelli moves
 * 20 GB of downloads · Jack Emby fetches 20 GB (40 GB wait)» — from the pool as it stands and his last status; without
 * him (not taking part: jack false) her part only
 */
function tonightText(p, jack = true) {
  if (!p || !p.measured) return T(jack ? 'night.unmeasured' : 'night.unmeasured_mine');
  if (!jack) return T('night.tonight', { what: Number(p.goes) > 0 ? T('night.goes', { size: fmt.size(p.goes) }) : T('night.goes_none') });
  // what Jack Emby gives back is what was watched — known only once he looked (a dry run's number when there is one)
  const parts = [Number(p.back) > 0 ? T('night.back', { size: fmt.size(p.back) }) : T('night.back_watched'),
    Number(p.goes) > 0 ? T('night.goes', { size: fmt.size(p.goes) }) : T('night.goes_none'),
    Number(p.fill) > 0 ? T('night.fill', { size: fmt.size(p.fill) }) : T('night.fill_none')];
  if (Number(p.wait) > 0) parts.push(T('night.wait', { size: fmt.size(p.wait) }));
  return T('night.tonight', { what: parts.join(' · ') });
}

/**
 * What her age rule moves tonight (owner 2026-10-14: below X the limit moves nothing, but the age rule always does): the
 * days of the shares that move — their effective min_age from her shares' list, else her ini (a share's own, else the
 * global); {days} when all agree (0 = everything), {mixed} when not, {none} when no share moves at all
 */
function ageRule() {
  const set = (state && state.settings) || {};
  const g = Number((set.global || {}).min_age) || 0;
  const own = set.shares || {};
  const shares = (state && state.shares) || [];
  const moving = shares.filter((x) => x.state === 'moves');
  if (shares.length && !moving.length) return { none: true };
  const daysOf = (x) => {
    if (!x) return g;
    if (x.min_age !== '' && x.min_age != null && Number.isFinite(Number(x.min_age))) return Number(x.min_age);
    const o = own[x.share];
    return o && o.min_age != null ? Number(o.min_age) || 0 : g;
  };
  const days = [...new Set((moving.length ? moving : [null]).map(daysOf))];
  return days.length === 1 ? { days: days[0] } : { mixed: true };
}

/**
 * Tonight's plan short, for the tile, pure: «Tonight: 70 GB back · 60 GB wait» — the long line lives in the section.
 * Nothing over X: what the age rule moves (age = ageRule()), never «nothing to move» while it moves something
 */
function tonightShort(p, age = null) {
  if (!p || !p.measured) return null;
  const parts = [];
  if (Number(p.back) > 0) parts.push(T('night.tile_back', { size: fmt.size(p.back) }));
  if (Number(p.goes) > 0) parts.push(T('night.tile_goes', { size: fmt.size(p.goes) }));
  if (Number(p.fill) > 0) parts.push(T('night.tile_fill', { size: fmt.size(p.fill) }));
  if (Number(p.wait) > 0) parts.push(T('night.tile_wait', { size: fmt.size(p.wait) }));
  if (!parts.length) {
    parts.push(!age || age.none ? T('night.tile_none') : age.mixed ? T('night.tile_age_mixed')
      : age.days > 0 ? T('night.tile_age', { n: age.days }) : T('night.tile_age_all'));
  }
  return T('night.tile', { what: parts.join(' · ') });
}

/**
 * Jack Streamer not in a night (his step refused — not set up, «Consolidate folders» first …; night.json / her record
 * `jack_out`, his refusal's code), pure: «Jack Streamer wasn't in it: run «Consolidate folders» once first» — '' when he was
 */
function jackOutText(why) {
  if (!why) return '';
  const k = why === 'jack_gather_first' ? 'night.jack_why_gather' : why === 'jack_not_configured' || why === 'jack_no_server' ? 'night.jack_why_setup'
    : why === 'jack_list_lost' ? 'night.jack_why_list' : 'night.jack_why_other';
  return T('night.jack_out', { why: T(k) });
}
/** The last night in words, pure: when, how it ended (done, target missed, stopped in a phase, refused), its rounds */
function lastNightText(l) {
  if (!l || !l.started) return '';
  const when = fmt.relative(l.finished || l.started);
  const how = l.result === 'missed' ? T(l.why === 'jack' ? 'night.last_missed_jack' : 'night.last_missed', { pct: l.end_pct ?? '?' })
    : l.result === 'stopped' ? T('night.last_stopped', { phase: Office.nightStoppedName(l, T) })
    : l.result === 'refused' ? T('night.last_refused') : T('night.last_ok');
  return T(l.jack === false || l.jack_out ? 'night.last_mine' : 'night.last', { when, how }) + (Number(l.round) > 1 ? ' · ' + T('night.rounds', { n: Number(l.round) }) : '')
    + (l.mode === 'dry' ? ' · ' + T('mode.dry') : '') + (l.jack_out ? ' · ' + jackOutText(l.jack_out) : '');
}
/**
 * The rules of the shares that move as they really are, pure (the owner, 2026-10-18: the tile said only the global rule
 * while a «prefer» share moved everything back): one group per way and rule — into the array, into a second pool, back
 * into the pool — biggest first: [{way, pool, n, rule, names}]. A share's own rules (state.shares: its age, its limit)
 * put it in a group of its own.
 */
function shareGroups(st) {
  const g = ((st && st.settings) || {}).global || {};
  const num = (v) => (v !== '' && v != null && Number.isFinite(Number(v)) ? Number(v) : null);
  const groups = [];
  for (const x of ((st && st.shares) || []).filter((r) => r && r.state === 'moves')) {
    const back = x.mode === 'prefer';
    const way = back ? 'back' : x.to && x.to !== 'array' ? 'pool' : 'array';
    const age = num(x.min_age) ?? (back ? 0 : num(g.min_age) ?? 0);
    const fill = back ? null : num(x.threshold) ?? num(g.move_when_used_above) ?? 0;
    const rule = back && !age ? T('tile.rule_all') : [T('rule_age', { n: age }), ...(back ? [] : [fillText(fill)])].join(' · ');
    const pool = way === 'pool' ? String(x.to) : '';
    let gr = groups.find((y) => y.way === way && y.pool === pool && y.rule === rule);
    if (!gr) groups.push(gr = { way, pool, n: 0, rule, names: [] });
    gr.n++;
    gr.names.push(x.share);
  }
  return groups.sort((p, q) => q.n - p.n);
}
/**
 * The tile's rule lines, pure: one group into the array — its rule alone (as ever); else a line per group «2 into the
 * array: older than 60 days · …», a group of one named, at most three lines — beyond «… and 2 with rules of their own»
 */
function shareRuleLines(st) {
  const groups = shareGroups(st);
  if (!groups.length) return [];
  if (groups.length === 1 && groups[0].way === 'array') return [groups[0].rule];
  const line = (gr) => {
    const t = T('tile.group_' + gr.way, { n: gr.n, pool: gr.pool, rule: gr.rule });
    return gr.n === 1 && groups.length > 1 ? T('tile.group_named', { what: t, share: gr.names[0] }) : t;
  };
  if (groups.length <= 3) return groups.map(line);
  return [...groups.slice(0, 2).map(line), T('tile.groups_more', { n: groups.slice(2).reduce((a, gr) => a + gr.n, 0) })];
}
/** «Shares»: how many move, their rules per group as its lines — alert while Jack Emby's list isn't kept out for a share of his */
function sharesTile(a) {
  const shares = state.shares || [];
  const sub = [T('shares_moving', { n: shares.filter((x) => x.state === 'moves').length }), ...shareRuleLines(state)];
  return Office.stat({ label: T('shares'), value: `${shares.filter((x) => x.state === 'moves').length} / ${shares.length}`, sub, tip: sub.join('\n'),
    alert: !!(jack().uncovered || []).length, ...a });
}
/** «Last run»: when, which mode and how it went — «running» while one goes */
function lastRunTile(a) {
  const last = lastRun();
  let value = T('last_run_none');
  const sub = [];
  let alert = false;
  const going = goingMode();
  if (going) {
    value = T('output_running');
    sub.push(T('mode.' + going));
  } else if (last && last.finished) {
    value = fmt.relative(last.finished);
    sub.push(`${T('mode.' + last.mode)} · ${T('result.' + (last.result || 'failed'))}`);
    if (last.jack_out) sub.push(jackOutText(last.jack_out));
    alert = !!((last.status && last.status.errors) || !['ok', 'stopped'].includes(last.result));
  }
  return Office.stat({ label: T('last_run'), value, sub, tip: sub.join('\n'), alert, ...a });
}

// ---- the sections
/** «Schedule»: when her real runs go on their own («Change…»), and Unraid's own mover schedule (off: as it should be) */
function scheduleSection() {
  const s = section(T('schedule'), T('schedule_sub'), { place: 'schedule' });
  const box = el('div', 'box');
  const sc = state.schedule || {};
  const n = night();
  const mine = Office.place('sched:mine', el('div', 'row nocheck'));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name', T(n.jack ? 'sched.night' : 'sched.mine')));
  const meta = el('div', 'row-meta');
  meta.appendChild(sc.enabled ? el('span', '', schedText(sc)) : chip(T('not_scheduled'), 'warn'));
  meta.appendChild(el('span', '', sc.enabled ? T(n.jack ? 'night.with_jack' : 'by_office') : T('schedule.off_hint')));
  main.appendChild(meta);
  const change = button(T('sched.change'), 'small plain', () => scheduleDialog());
  change.disabled = !Office.agent.running;
  mine.append(main, change);
  box.appendChild(mine);
  // the night run (with Jack Emby while he takes part): tonight's plan (no Emby asked — what waits from his last run), the
  // last night, a dry run
  if (state.configured) {
    const plan = Office.place('sched:night', el('div', 'row nocheck'));
    const pm = el('div', 'row-main');
    pm.appendChild(el('div', 'row-name', tonightText(n.plan, !!n.jack)));
    const last = lastNightText(n.last);
    pm.appendChild(el('div', 'row-meta', T(n.jack ? 'night.steps' : 'night.steps_mine') + (last ? ' · ' + last : '')));
    // Jack Streamer not in the last night: to his page, where he says what to do
    if (n.last && n.last.jack_out) pm.appendChild(el('div', 'row-meta')).appendChild(link(T('night.jack_open'), () => Office.go(n.last.jack_out === 'jack_gather_first' ? '#/jack/gather' : '#/jack')));
    const dry = button(T('night.dry'), 'small plain', async () => { dry.disabled = true; await act('start_run', { mode: 'dry' }, T(n.jack ? 'night.dry_started' : 'night.dry_started_mine')); });
    dry.disabled = !Office.agent.running || running() || nightGoing();
    plan.append(pm, dry);
    box.appendChild(plan);
  }
  const m = mover();
  const own = Office.place('sched:unraid', el('div', 'row nocheck'));
  const main2 = el('div', 'row-main');
  main2.appendChild(el('div', 'row-name', T('unraid_schedule')));
  const meta2 = el('div', 'row-meta');
  meta2.appendChild(chip(m.off ? T('unraid_off') : T('unraid_on'), m.off ? 'ok' : 'warn'));
  meta2.appendChild(el('span', '', m.off ? T('unraid_off_sub') : (m.schedule ? fmt.cron(m.schedule) : '')));
  main2.appendChild(meta2);
  own.appendChild(main2);
  box.appendChild(own);
  s.appendChild(box);
  return s;
}

const STATE_CLASS = { moves: 'ok', skip: 'quiet', prefer_off: 'quiet', none: 'quiet' };
// the lists' filter bars (Office.filterBar — built once per visit, never kept): her shares, her runs, the setup's shares
let sharesBar = null;
let runsBar = null;
let setupBar = null;
const dom = {};
/** Do the bar's words fit this hay (no bar, no words: yes) */
const fitsBar = (bar, hay) => { const f = bar ? bar.read() : null; return !f || !f.words.length || Office.fits(f.words, hay); };
function bars() {
  if (!sharesBar) sharesBar = Office.filterBar({ key: 'moverelli.shares', label: T('filter.shares'), onChange: () => { if (dom.shares && dom.shares.isConnected) fillShares(dom.shares); } });
  if (!runsBar) runsBar = Office.filterBar({ key: 'moverelli.runs', label: T('filter.runs'), onChange: () => { if (dom.runs && dom.runs.isConnected) fillRuns(dom.runs); } });
  if (!setupBar) setupBar = Office.filterBar({ key: 'moverelli.setup', label: T('filter.shares'), onChange: () => { if (dom.setup && dom.setup.isConnected) fillSetupShares(dom.setup); } });
  sharesBar.reset();
  runsBar.reset();
  setupBar.reset();
}

/** Every share as Smart Mover sees it: what moves where, under which rules */
function shareSection() {
  const shares = state.shares || [];
  const moving = shares.filter((x) => x.state === 'moves').length;
  const s = section(T('shares'), T('shares_sub'), chip(T('shares_moving', { n: moving }), moving ? 'ok' : 'quiet'), { place: 'shares' });
  if (sharesBar) s.appendChild(sharesBar.el);
  const box = el('div', 'box');
  dom.shares = box;
  fillShares(box);
  s.appendChild(box);
  return s;
}
/** A share's words for the bar: its name, what it does, where its files go */
const shareHay = (x) => [x.share, T('state.' + x.state), where(x.from), where(x.to), x.primary || ''];
function fillShares(box) {
  box.innerHTML = '';
  const shares = state.shares || [];
  const fit = shares.filter((x) => fitsBar(sharesBar, shareHay(x)));
  fit.forEach((x) => {
    const row = Office.place(`share:${x.share}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', x.share));
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('state.' + x.state), STATE_CLASS[x.state] || 'quiet', x.state === 'none' ? idleText(x) : T('state_tip.' + x.state)));
    if (x.mode !== 'none') meta.appendChild(el('span', '', T('direction', { from: where(x.from), to: where(x.to) })));
    else meta.appendChild(el('span', '', T('share_where', { primary: x.use === 'no' || !x.use ? T('array') : (x.primary || '–') })));
    if (x.own) meta.appendChild(chip(T('own_rules'), 'quiet'));
    main.appendChild(meta);
    if (x.state === 'moves') {
      const rules = el('div', 'row-meta');
      rules.appendChild(el('span', '', T('rule_age', { n: x.min_age })));
      if (x.threshold) rules.appendChild(el('span', '', fillText(x.threshold)));
      if (x.excludes) rules.appendChild(el('span', '', nOf('lists', x.excludes)));
      main.appendChild(rules);
    }
    row.appendChild(main);
    box.appendChild(row);
  });
  if (!shares.length) box.appendChild(el('p', 'empty', T('shares_none')));
  if (sharesBar) sharesBar.done(fit.length, shares.length);
}

/** «My runs»: the last run's result on top (its shares, the log, its output), then every run with how it went */
function historySection() {
  const s = section(T('history'), T('history_sub'), { place: 'history' });
  // after the night run: its picture on top (the pool before and after, where she moved to, the last file) — under the
  // «Last run» tile, once; while a run goes the live one stands under her run card instead
  const n = night();
  const lastPic = n.last && !n.last.running ? nightPicture(n.last) : null;
  if (lastPic) s.appendChild(lastPic);
  const rb = resultBox();
  if (rb) s.appendChild(rb);
  const bar = el('div', 'toolbar');
  if (state.configured) bar.appendChild(button(T('show_log'), 'small plain', showLog));
  if (state.job && state.job.mode) bar.appendChild(link(T('show_output', { mode: T('mode.' + state.job.mode) }), () => showOutput(false)));
  if (bar.children.length) s.appendChild(bar);
  if (runsBar) s.appendChild(runsBar.el);
  const box = el('div', 'box');
  dom.runs = box;
  fillRuns(box);
  s.appendChild(box);
  return s;
}
/** A share's files left — with the reason Unraid's move binary gave (C-M16: «No medium found»), else «in use or already there» */
const leftCount = (x) => (Number(x.left) > 0 && x.left_why ? T('left_why', { n: Number(x.left), why: x.left_why }) : nOf('left', x.left));
/** The last run: how it went, a line per share that had something */
function resultBox() {
  const last = state.last;
  if (!last || !last.result || !['run', 'dry'].includes(last.mode)) return null;     // a look at the shares' list is no run
  const box = el('div', 'box mo-results');
  const h = (state.history || []).find((r) => !['refused', 'skipped'].includes(r.result));
  box.appendChild(el('div', '', runSummary({ mode: last.mode, status: last, result: last.result, why: h && h.result === last.result ? h.why : null })));
  (last.shares || []).filter((x) => x.files || x.moved || x.left).forEach((x) => {
    box.appendChild(el('div', 'mo-share-line', T(last.mode === 'run' ? 'share_moved' : 'share_would', { share: x.share,
      moved: x.moved || 0, files: nOf('files', x.files), size: fmt.size(x.bytes || 0), left: leftCount(x) })));
  });
  // the fill: where the pool stood and whether the target was reached
  (last.shares || []).filter((x) => x.fill && x.pool_used !== '' && Number(x.pool_used) >= Number(x.threshold)).forEach((x) => {
    const reached = x.reached ? ' — ' + T('rc.fill_reached', { pct: x.pool_now }) : '';
    box.appendChild(el('div', 'mo-share-line', `${x.share}: ${T('rc.fill', { pool: x.pool, used: x.pool_used, until: x.until })}${reached}`));
  });
  return box;
}
/** A run's words for the bar: its mode, result, who started it, the summary */
/** Who started a run; inside the joint run its record's `from` (her schedule or this page), with «in the joint run» while
 *  Jack Streamer took part (his flag false: her part alone). A record from before `from`: only what is known */
const runBy = (r) => {
  if (r.by !== 'night') return T('by.' + r.by);
  const from = ['office', 'schedule'].includes(r.from) ? T('by.' + r.from) : '';
  return [from, r.jack === false ? '' : T('by.night')].filter(Boolean).join(' · ');
};
const runHay = (r) => [T('mode.' + r.mode), T('result.' + (r.result || 'failed')), runBy(r), runSummary(r)];
function fillRuns(box) {
  box.innerHTML = '';
  const runs = state.history || [];
  const fit = runs.filter((r) => fitsBar(runsBar, runHay(r)));
  if (!runs.length) box.appendChild(el('p', 'empty', T('history_none')));
  fit.forEach((r) => {
    const row = Office.place(`run:${r.started}`, el('div', 'row nocheck'));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name', T('mode.' + r.mode)));
    const meta = el('div', 'row-meta');
    const ok = r.result === 'ok';
    const cls = ['refused', 'skipped', 'stopped'].includes(r.result) ? 'quiet' : ok ? 'ok' : 'warn';
    meta.append(chip(T('result.' + (r.result || 'failed')), cls, r.exit != null ? T('exit_code', { n: r.exit }) : ''),
      el('span', '', fmt.date(r.started)), el('span', '', runBy(r)));
    if (r.finished && r.finished - r.started >= 60) meta.appendChild(el('span', '', fmt.duration(r.finished - r.started)));
    main.append(meta, el('div', 'row-meta', runSummary(r)));
    row.appendChild(main);
    box.appendChild(row);
  });
  if (runsBar) runsBar.done(fit.length, runs.length);
}

/** A quiet line under the tool's versions: the Smart Mover is helmi1987's */
function credit() {
  const box = el('div', 'mo-credit');
  box.append(el('b', '', T('credit_title')), ' ', T('credit'), ' ');
  const a = el('a', '', ORIGIN[0]);
  a.href = ORIGIN[1];
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  box.appendChild(a);
  return box;
}
/** Under all (no section): Smart Mover's and Unraid's versions, then the credit to helmi1987 */
function footer() {
  const box = Office.place('tools', el('div', 'mo-foot'));
  const line = el('div', 'row-meta mo-tools');
  line.append(el('span', '', `Smart Mover ${state.engine || '?'} · ${T('tool_sub')}`),
    el('span', unraidOk() ? '' : 'mo-old', `Unraid ${(state.unraid && state.unraid.version) || '?'} · ${T('unraid_sub', { needed: (state.unraid && state.unraid.needed) || '' })}`));
  box.append(line, credit());
  return box;
}

// ------------------------------------------------------------------ runs
function radioList(name, options, current, onchange) {
  const box = el('div');
  options.forEach(([value, text, hint, disabled]) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = name;
    input.checked = value === current;
    input.disabled = !!disabled;
    input.onchange = () => onchange(value);
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
  });
  return box;
}

/** «Start…»: a dry run, or a real one — only while Unraid's schedule is off and Jack Emby's list is kept out */
async function chooseRun() {
  if (!(await Office.freshState(ID))) return;
  let mode = 'dry';
  const off = mover().off;
  const tuning = otherMovers();
  const covered = !(jack().uncovered || []).length;
  const box = radioList('mo-mode', [
    ['dry', T('mode.dry'), T('mode_hint.dry')],
    ['run', T('mode.run'), !off ? T('mode_hint.run_mover') : tuning ? T('mode_hint.run_tuning') : !covered ? T('mode_hint.run_jack') : T('mode_hint.run'), !off || tuning || !covered],
  ], mode, (m) => { mode = m; });
  const err = errorLine();
  box.appendChild(err);
  Office.dialog({
    title: T('start_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('start_go'), kind: '', act: async () => {
      const before = runKeys();
      const j = await Office.api.post(`${ID}.start_run`, { mode });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      if (view && page === 'main') render();     // no log dialog: a real run shows its card (as Mr. Backupsy's)
      followRun(mode, { before });
      return true;
    } }],
  });
}

async function startRun(mode) {
  // no dialog (as Mr. Backupsy's and Mr. Restori's runs): a real run shows its card, the others their result under the tiles
  const before = runKeys();
  const j = await act('start_run', { mode });
  if (j) { render(); followRun(mode, { before }); }
}

/**
 * After a click on «Show shares» or «Start…» (live 2026-10-14: the list ran, nothing showed): the section where its result
 * lands is shown at once — the shares for the list, her runs otherwise — and the page follows the run (her state every
 * POLL) until it ended: refused or skipped → said right there (a toast with why), done → a toast; a real run hands over
 * to its card. A refusal at the start itself is act()'s toast already.
 */
let followTimer = null;
function followRun(mode, opts) {
  const o = opts || {};
  clearTimeout(followTimer);
  const before = o.before || new Set();
  const first = o.tries == null;
  const tries = first ? 40 : o.tries;
  if (first && sw && view && page === 'main') sw.pick(mode === 'list' ? 'shares' : 'runs', 'run');
  const ended = runEnded(mode, before);
  if (first && !ended && mode !== 'list' && !realRun()) Office.toast(T('run_started', { mode: T('mode.' + mode), tile: T('last_run') }));
  if (ended) {
    if (['refused', 'skipped'].includes(ended.result)) Office.toast(runSummary(ended), true);
    else Office.toast(T(mode === 'list' ? 'list_done' : 'run_done', { mode: T('mode.' + mode), tile: T(mode === 'list' ? 'shares' : 'last_run') }));
    return ended;
  }
  if (realRun() || tries <= 0 || !view || page !== 'main') return null;       // a real run: its card follows it from here
  followTimer = setTimeout(async () => { await load(true); followRun(mode, { before, tries: tries - 1 }); }, POLL);
  return null;
}
/** Her records as keys (a refusal counted again is a new one) — taken before a click, so its run is the one not among them */
const runKey = (r) => `${r.mode}|${r.started}|${r.times || 1}`;
const runKeys = () => new Set(((state && state.history) || []).map(runKey));
/** The run a click started, once it ended, pure: her newest record of that mode not there before the click (the night's own too) — null while it goes */
function runEnded(mode, before) {
  if (running() || (night().last && night().last.running)) return null;
  return ((state && state.history) || []).find((r) => r.mode === mode && !before.has(runKey(r))) || null;
}

/** The output of her last run, following it while it runs */
async function showOutput(follow) {
  clearTimeout(outTimer);
  const pre = el('pre', 'code', Office.t('common.loading'));
  const status = el('p', 'role');
  const box = el('div');
  box.append(status, pre);
  const dlg = Office.dialog({ title: T('output_title'), body: box, wide: 'log', onClose: () => clearTimeout(outTimer) });
  let wasRunning = false;
  const tick = async () => {
    const j = await Office.api.post(`${ID}.output`, {});
    if (!j.ok) { pre.textContent = Office.errorText(j.error, ID); return; }
    const atEnd = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
    pre.textContent = j.text || T('output_empty');
    const info = j.info || {};
    status.textContent = info.mode ? `${T('mode.' + info.mode)} · ${info.running ? T('output_running') : T('output_done')} · ${fmt.date(info.started)}` : '';
    if (atEnd || follow) pre.scrollTop = pre.scrollHeight;
    const young = info.started && Date.now() / 1000 - info.started < 10;      // its first line may not be written yet
    if (info.running || (follow && young && !wasRunning)) { wasRunning = wasRunning || info.running; outTimer = setTimeout(tick, POLL); }
    else if (follow) { follow = false; load(true); }
  };
  tick();
  return dlg;
}

async function showLog() {
  const pre = el('pre', 'code', Office.t('common.loading'));
  Office.dialog({ title: 'smart_mover.log', body: pre, wide: 'log' });
  const j = await Office.api.post(`${ID}.log`, {});
  pre.textContent = j.ok ? (j.text || T('output_empty')) : Office.errorText(j.error, ID);
  pre.scrollTop = pre.scrollHeight;
}

// ------------------------------------------------------------------ her schedule
/** When Smart Mover runs for real on its own: once a night (default 05:15), a cron expression, or not at all */
async function scheduleDialog() {
  if (!(await Office.freshState(ID))) return;
  if (!state.configured) {
    Office.dialog({
      title: T('schedule_title'),
      body: T('schedule.need_setup'),
      buttons: [{ text: Office.t('common.close') }, { text: T('setup_open'), kind: '', act: () => { Office.go(`#/${ID}/setup`); } }],
    });
    return;
  }
  const sc = state.schedule || {};
  const cur = (sc.enabled && sc.custom) || '';
  const pad = (n) => String(n).padStart(2, '0');
  const m2 = /^(\d{1,2}) (\d{1,2}) \* \* \*$/.exec(cur);
  let mode = sc.enabled ? (m2 ? 'daily' : 'custom') : 'daily';
  const time = el('input', 'input');
  time.type = 'time';
  time.value = m2 ? `${pad(m2[2])}:${pad(m2[1])}` : '05:15';
  const cron = el('input', 'input mono');
  cron.value = cur || '15 5 * * *';
  cron.spellcheck = false;
  const box = el('div', 'mo-schedule');
  box.appendChild(el('p', '', T(night().jack ? 'schedule.intro_night' : 'schedule.intro')));
  if (!othersOff()) box.appendChild(el('p', 'callout warn', T('schedule.unraid_on')));
  const option = (id, text, hint, extra, off) => {
    const label = el('label', 'check');
    const input = el('input');
    input.type = 'radio';
    input.name = 'mo-schedule';
    input.checked = mode === id;
    input.disabled = !!off;
    input.onchange = () => { mode = id; update(); };
    const span = el('span', '', text);
    if (hint) span.appendChild(el('small', '', hint));
    label.append(input, span);
    box.appendChild(label);
    if (extra) {
      const f = el('div', 'mo-schedule-field');
      f.appendChild(extra);
      box.appendChild(f);
    }
  };
  option('daily', T('schedule.nightly'), T('schedule.nightly_hint'), time);
  option('custom', T('schedule.custom'), T('schedule.custom_hint'), cron);
  option('off', T('schedule.off'), T('schedule.off_hint'));
  const update = () => { time.disabled = mode !== 'daily'; cron.disabled = mode !== 'custom'; };
  update();
  Office.dialog({
    title: T('schedule_title'),
    body: box,
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('schedule.save'), kind: '', act: async () => {
        let expr = '';
        if (mode === 'daily') {
          if (!/^\d\d:\d\d$/.test(time.value)) { Office.toast(T('schedule.need_time'), true); return false; }
          const [h, m] = time.value.split(':').map(Number);
          expr = `${m} ${h} * * *`;
        } else if (mode === 'custom') expr = cron.value.trim();
        const j = await Office.api.post(`${ID}.schedule`, { cron: expr });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.state) state = j.state;
        if (!j.live) Office.toast(T('schedule.not_live'), true);
        else Office.toast(expr ? T('schedule.saved_on', { when: fmt.cron(expr) }) : T('schedule.saved_off'));
        if (view && page === 'main') render();
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ setup (#/moverelli/setup)
/*
 * Everything smart_mover.ini holds: the rules for all shares (age, which time counts, the pool's fill, prefer shares,
 * the move binary's debug level, lines in a dry run's output, files to leave alone), then per share its own (left
 * out, its own age and fill, more files to leave alone). Files to leave alone: exclude lists elsewhere (one path per
 * line) and her own lines (paths, globs, words — Smart Mover's exclude format).
 */
const linesOf = (text) => String(text).split('\n').map((x) => x.replace(/\r$/, ''));
const filesOf = (text) => linesOf(text).map((x) => x.trim()).filter(Boolean);

function initForm() {
  const set = JSON.parse(JSON.stringify(state.settings));
  const shares = {};
  // the shares Unraid has, and (2026-10-18) those it no longer has whose own rules are still saved: kept as they are
  [...(state.shares || []).map((x) => x.share), ...(state.shares_gone || [])].forEach((name) => {
    const s = set.shares[name] || {};
    shares[name] = { skip: !!s.skip, min_age: s.min_age ?? null, move_when_used_above: s.move_when_used_above ?? null,
      files: s.files || [], patterns: s.patterns || [] };
  });
  // (an older agent's move_until_used_below / jack_min_percent: not carried — only X since 2026-10-14)
  const { move_until_used_below: _y, jack_min_percent: _k, age_stat: _a, ...g } = set.global;
  form = { global: g, shares };
  saved = formSettings(form);
  // Jack Emby hired and his list not kept out for every share of his: proposed in the files for all shares — in the form
  // only, nothing is written before «Save»; what was there stays, «Remove it again» takes exactly this line out
  const list = jack().list;
  if (list && !noJack && jackUncoveredIn(form).length) {
    form.global.files = [...form.global.files, list];
    form.jackAdded = list;
  }
}

/**
 * Over the setup's row (a hint, it stays at every click): Jack Emby's list added for you — why, save to keep it, «Remove
 * it again»; or, taken out (or never added), the shares of his still not covered
 */
function jackNotice() {
  const j = jack();
  if (!j.list || !form) return null;
  if (form.jackAdded && form.global.files.includes(form.jackAdded)) {
    const p = el('p', 'callout mo-suggest', T('setup.jack_added', { file: form.jackAdded }) + ' ');
    p.appendChild(button(T('setup.jack_remove'), 'small plain', () => {
      form.global.files = form.global.files.filter((x) => x !== form.jackAdded);
      form.jackAdded = null;
      noJack = true;
      renderSetup();
    }));
    return p;
  }
  const open = jackUncoveredIn(form);
  return open.length ? el('p', 'callout warn', T('errors.moverelli_jack_list', { shares: open.join(', ') })) : null;
}

function input(value, onchange, cls) {
  const i = el('input', 'input' + (cls ? ' ' + cls : ''));
  i.value = value ?? '';
  i.oninput = () => onchange(i.value);
  return i;
}
/** A whole number; optional: empty = null (the rule for all shares counts) */
function number(obj, key, min, max, optional) {
  const i = el('input', 'input');
  i.type = 'number';
  i.min = min;
  i.max = max;
  i.value = obj[key] ?? '';
  if (optional) i.placeholder = T('setup.as_global');
  i.oninput = () => { const v = i.value.trim(); obj[key] = v === '' && optional ? null : Number(v); };
  return i;
}
function select(obj, key, options) {
  const s = el('select', 'picker');
  options.forEach(([value, text]) => s.appendChild(new Option(text, value)));
  s.value = obj[key] ?? '';
  s.onchange = () => { obj[key] = s.value === '' ? null : (/^\d+$/.test(s.value) ? Number(s.value) : s.value); };
  return s;
}
function textarea(lines, onchange, rows) {
  const ta = el('textarea', 'input mono');
  ta.rows = rows || 3;
  ta.spellcheck = false;
  ta.value = (lines || []).join('\n');
  ta.oninput = () => onchange(ta.value);
  return ta;
}
function field(label, inputEl, hint) {
  const f = el('div', 'field');
  f.append(el('label', '', label), inputEl);
  if (hint) f.appendChild(el('small', '', hint));
  return f;
}
function check(text, checked, onchange, small) {
  const label = el('label', 'check');
  const i = el('input');
  i.type = 'checkbox';
  i.checked = !!checked;
  i.onchange = () => onchange(i.checked);
  const span = el('span', '', text);
  if (small) span.appendChild(el('small', '', small));
  label.append(i, span);
  return label;
}
function keepFold(det, key, open) {
  det.open = folds.has(key) ? folds.get(key) : open;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;
    shown = det.open;
    folds.set(key, det.open);
  });
  return det;
}

/** «Files to leave alone» of a scope: other lists (paths) and her own lines; EmbyCache's list one click away */
function excludeFields(obj, scope) {
  const box = el('div', 'mo-form');
  const files = textarea(obj.files, (v) => { obj.files = filesOf(v); });
  box.append(field(T('setup.files'), files, T('setup.files_hint')),
    field(T('setup.patterns'), textarea(obj.patterns, (v) => { obj.patterns = linesOf(v); }, 4), T('setup.patterns_hint')));
  const list = jack().list;
  if (list && !obj.files.includes(list)) {
    const b = button(T('setup.add_jack'), 'small plain', () => { obj.files = [...obj.files, list]; files.value = obj.files.join('\n'); b.remove(); setupChanged(); });
    b.title = list;
    b.dataset.scope = scope;
    box.appendChild(b);
  }
  return box;
}

function renderSetup() {
  if (!view) return;
  const root = view;
  root.innerHTML = '';
  // the tabs say where one is (no «← Back», no head button): under them the page's head line, her setup words its sub line
  root.appendChild(deskHead());
  root.appendChild(Office.pageHelp(ID + '-setup', [
    [T('setup.global'), T('setup.help_global')],
    [T('setup.files'), T('setup.help_files')],
    [T('setup.shares'), T('setup.help_shares')],
    [T('setup.save'), T('setup.help_save')],
    [T('import.title'), T('import.help')],
  ]));
  const ph = el('div', 'mo-pagehead');
  ph.append(el('div', 'mo-pagehead-title', T('page.setup')), el('p', 'mo-pagehead-sub', T('setup.bubble')));
  root.appendChild(ph);
  if (!state) return;
  if (!form) initForm();
  limitFields.clear();
  // hints over the row (they stay at every click): Jack Emby's list — added for you, or still missing for a share of his
  const jn = jackNotice();
  if (jn) root.appendChild(jn);
  // an earlier install she found by herself (the flash): offered at the top; else only a quiet link at the very bottom
  const found = foundNotice();
  if (found) root.appendChild(found);
  // the row of her setup's steps and under it the step chosen (none: every step in order — the page as before the tiles)
  const s = setupSwitcher();
  root.appendChild(s.el);
  s.render();
  root.appendChild(saveFootEl());
  if (!found) {
    const more = el('p', 'role mo-import-link');
    const a = el('a', '', T('import.link'));
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); importDialog(); };
    more.appendChild(a);
    root.appendChild(more);
  }
  // every change goes into «Save» at the bottom (saveFootEl()) — the form tells it at each keystroke
  if (!root.dataset.moBar) {
    root.dataset.moBar = '1';
    const bump = () => { if (page === 'setup') setTimeout(setupChanged, 0); };
    root.addEventListener('input', bump);
    root.addEventListener('change', bump);
  }
  setupChanged();
}

/** «I found an earlier install (<folder>) — take it over?» over the row, while she finds one on the flash */
function foundNotice() {
  const f = (state && state.import_found) || [];
  if (!f.length) return null;
  const p = el('p', 'callout mo-found', T('import.found', { folder: f[0] }) + ' ');
  const b = button(T('import.open'), 'small', () => { importFolder = f[0]; importDialog(); });
  b.disabled = !Office.agent.running;
  p.appendChild(b);
  return p;
}

// ---- the change bar and the apply dialog (like Mr. Backupsy's setup, 2026-10-12)
/** The settings as the page sends them (pure): only the shares with something of their own, empty own lines none */
function formSettings(f) {
  const shares = {};
  Object.entries(f.shares).forEach(([name, sh]) => {
    const patterns = sh.patterns.join('\n').trim() === '' ? [] : sh.patterns;
    if (sh.skip || sh.min_age !== null || sh.move_when_used_above !== null || sh.files.length || patterns.length) {
      shares[name] = { ...sh, files: [...sh.files], patterns: [...patterns] };
    }
  });
  const g = f.global;
  return { global: { ...g, files: [...g.files],
    patterns: g.patterns.join('\n').trim() === '' ? [] : [...g.patterns] }, shares };
}

const SET_KEYS = ['min_age', 'move_when_used_above', 'move_prefer_shares', 'mover_debug', 'max_list'];
const SHARE_KEYS = ['skip', 'min_age', 'move_when_used_above'];
/** A value as the apply dialog says it (null in a share: as for all shares) */
function setValue(key, v) {
  if (v === null || v === undefined || v === '') return T('setup.as_global');
  if (typeof v === 'boolean') return Office.t(v ? 'common.yes' : 'common.no');
  if (key === 'min_age') return T('setup.tile.days', { n: Number(v) || 0 });
  if (key === 'move_when_used_above') return Number(v) ? `${v} %` : T('setup.fill_off');
  return String(v);
}

/**
 * Every change of the form against what is saved, as the dialog lists it (pure): [{mark: 'add'|'gone'|null, label, what}]
 * — a value «a → b», a list of what stays and an own line + / −, a share's own rules + / − as a whole, a share's
 * changed rule with its name. The bar counts exactly these rows.
 */
function setupChangeRows(was, now) {
  const rows = [];
  const lists = (scope, a, b) => {
    const pre = scope ? T('setup.row_share', { share: scope }) + ' — ' : '';
    b.files.filter((x) => !a.files.includes(x)).forEach((x) => rows.push({ mark: 'add', label: pre + T('setup.files'), what: x }));
    a.files.filter((x) => !b.files.includes(x)).forEach((x) => rows.push({ mark: 'gone', label: pre + T('setup.files'), what: x }));
    const own = (l) => l.map((x) => x.trim()).filter(Boolean);
    own(b.patterns).filter((x) => !own(a.patterns).includes(x)).forEach((x) => rows.push({ mark: 'add', label: pre + T('setup.patterns'), what: x }));
    own(a.patterns).filter((x) => !own(b.patterns).includes(x)).forEach((x) => rows.push({ mark: 'gone', label: pre + T('setup.patterns'), what: x }));
  };
  SET_KEYS.forEach((k) => {
    if (String(was.global[k]) !== String(now.global[k])) {
      rows.push({ mark: null, label: T('setup.row_global', { what: T(IMPORT_LABEL[k]) }), what: `${setValue(k, was.global[k])} → ${setValue(k, now.global[k])}` });
    }
  });
  lists('', was.global, now.global);
  const names = [...new Set([...Object.keys(was.shares), ...Object.keys(now.shares)])].sort();
  names.forEach((n) => {
    const a = was.shares[n];
    const b = now.shares[n];
    if (!a) { rows.push({ mark: 'add', label: T('setup.row_share', { share: n }), what: T('setup.row_own') }); return; }
    if (!b) { rows.push({ mark: 'gone', label: T('setup.row_share', { share: n }), what: T('setup.row_own') }); return; }
    SHARE_KEYS.forEach((k) => {
      if (String(a[k] ?? null) !== String(b[k] ?? null)) {
        rows.push({ mark: null, label: `${T('setup.row_share', { share: n })} — ${T(IMPORT_LABEL[k])}`,
          what: k === 'skip' ? Office.t(b[k] ? 'common.yes' : 'common.no') : `${setValue(k, a[k])} → ${setValue(k, b[k])}` });
      }
    });
    lists(n, a, b);
  });
  return rows;
}

/**
 * What keeps the form from being saved, at once (pure; the same rules as moverelliCheck()): [{scope: '' | share, text}]
 * — only X since 2026-10-14: a limit is a whole number from 0 to 100 (the field says it), nothing else can clash
 */
function setupProblems(f) {
  const out = [];
  const bad = (v) => v !== null && v !== undefined && !(Number.isInteger(Number(v)) && String(v).trim() !== '' && Number(v) >= 0 && Number(v) <= 100);
  if (bad(f.global.move_when_used_above)) out.push({ scope: '', text: T('setup.err_limit') });
  Object.entries(f.shares).forEach(([name, sv]) => { if (bad(sv.move_when_used_above ?? null)) out.push({ scope: name, text: T('setup.err_limit') }); });
  return out;
}
/** The limit field per scope ('' = for all shares) as drawn now: [field, error line] */
const limitFields = new Map();
function limitPair(scope, thField) {
  const err = el('p', 'mo-field-err');
  err.setAttribute('role', 'alert');
  thField.appendChild(err);
  limitFields.set(scope, [thField, err]);
  markLimits();
}
/** The fields of every scope with a problem: the error style on both, the reason under «… bis» */
function markLimits() {
  if (!form) return;
  const probs = setupProblems(form);
  limitFields.forEach(([th, err], scope) => {
    const p = probs.filter((x) => x.scope === scope);
    if (th.classList) th.classList.toggle('mo-bad', p.length > 0);
    err.textContent = p.map((x) => x.text).join(' ');
  });
}

/** The bar's spec (pure): null while nothing differs from what is saved and she is set up; else the count = the dialog's rows */
function setupBarSpec() {
  if (page !== 'setup' || !state || !form || !saved) return null;
  const n = setupChangeRows(saved, formSettings(form)).length;
  if (!n && state.configured) return null;
  // a problem in the form: «Apply…» stays off, the bar says why (the fields say it too)
  const p = setupProblems(form)[0];
  const why = p ? (p.scope ? `${T('setup.row_share', { share: p.scope })} — ${p.text}` : p.text) : null;
  return {
    title: n ? T('setup.bar_changes', { n }) : T('setup.bar_new'),
    sub: why ? T('setup.bar_blocked', { why }) : T(state.configured ? 'setup.bar_sub' : 'setup.bar_sub_new'),
    blocked: !!why,
    buttons: [
      { text: T('setup.discard'), kind: 'plain', disabled: !n, act: () => { noJack = true; form = null; renderSetup(); } },
      { text: T('setup.save'), disabled: !Office.agent.running || running() || !!why, act: setupApply },
    ],
  };
}
/**
 * «Save» at the bottom of her setup, with «Discard» and a quiet line — what differs from what is saved, or why «Save»
 * can't go yet (bar_blocked) — drawn from setupBarSpec(); no floating change bar on her page (2026-10-21, the owner:
 * the page's own «Save» is enough). Nothing differs (and she is set up): «Save» grey, no line, no «Discard».
 */
let saveDraw = null;
function saveFootEl() {
  const foot = el('div', 'toolbar mo-save');
  const save = button(T('setup.save'), '', () => setupApply());
  const discard = button(T('setup.discard'), 'plain', () => { noJack = true; form = null; renderSetup(); });
  const line = el('span', 'role mo-save-line');
  foot.append(save, discard, line);
  saveDraw = () => {
    const spec = setupBarSpec();
    const go = spec && spec.buttons[1];
    save.disabled = !go || !!go.disabled;
    discard.hidden = !spec || !!spec.buttons[0].disabled;
    line.textContent = spec ? `${spec.title} — ${spec.sub}` : '';
    if (line.classList) line.classList.toggle('mo-save-no', !!(spec && spec.blocked));
  };
  saveDraw();
  return foot;
}
/** The form changed (or was drawn): «Save» at the bottom anew */
function setupChanged() {
  markLimits();
  if (saveDraw) saveDraw();
}

/**
 * Her setup page left (another page or desk; a visit there starts from her settings again) with changes not saved:
 * said, never lost without a word (2026-10-17, silent controls)
 */
function leftSetup() {
  if (page === 'setup' && form && saved && setupChangeRows(saved, formSettings(form)).length) Office.toast(T('setup.left_unsaved'));
}

/**
 * «Apply…»: every change with Mr. Backupsy's marks, and — while Unraid's own mover schedule is on — her taking it by
 * the hand as a section of its own (its schedule, the ⟦Move now⟧ warning, her let-go gives it back): one dialog,
 * «Apply» saves (with the consent when that section is there)
 */
function setupApply() {
  const now = formSettings(form);
  const rows = setupChangeRows(saved, now);
  const box = el('div', 'mo-apply');
  box.appendChild(el('p', '', rows.length ? T('setup.apply_text', { n: rows.length }) : T('setup.apply_new')));
  if (rows.length) {
    const ul = el('ul', 'shortlist');
    rows.forEach((r) => {
      const li = el('li', r.mark ? 'marked' : '');
      if (r.mark) li.appendChild(Office.diffMark(r.mark, T(r.mark === 'add' ? 'setup.mark_add' : 'setup.mark_gone')));
      li.appendChild(document.createTextNode(r.label));
      li.appendChild(el('span', '', r.what));
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }
  // what the next night moves with these numbers, per pool
  levelList().forEach(([pool, c]) => { if (c) box.appendChild(el('p', 'callout', levelNight(pool, levelMath(c, now.global.move_when_used_above)))); });
  const off = !mover().off;
  if (off) box.appendChild(consentSection());
  box.appendChild(el('p', 'role', T('setup.help_save')));
  const err = errorLine();
  box.appendChild(err);
  Office.dialog({
    title: T('setup.apply_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('setup.apply_go'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.save`, off ? { settings: now, mover_off: true } : { settings: now });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      Office.toast(T(j.mover_off ? 'setup.saved_mover_off' : 'setup.saved'));
      form = null;
      saved = null;
      Office.go(`#/${ID}`);
      if (typeof window !== 'undefined') window.scrollTo(0, 0);     // saved: her overview from its top, not left at the bottom where «Save» was
      return true;
    } }],
  });
}

// ---- the setup's steps as tiles (tiles v2, 2026-10-12, like Jack Emby's): a number tile per step — the rules for all
// shares, the shares' own, and (once she is set up) taking over an earlier install; a click shows that step alone, nothing
// chosen = every step in order; kept as moverelli.setup.tile, a step's route #/moverelli/setup/<tile>. The form lives
// across every drawing (form): a step drawn anew shows what was typed.
/** [tile id, lang key (the step's title: the tile's label, its place for the search)] in the steps' order */
const SETUP_TILES = [['global', 'setup.global'], ['shares', 'setup.shares'], ['import', 'import.title']];
let setupSw = null;
function setupSwitcher() {
  if (setupSw) return setupSw;
  setupSw = Office.sections({
    id: `${ID}/setup`,           // the routes #/moverelli/setup/<tile>
    key: 'moverelli.setup.tile',
    bodyId: 'mo-setup-steps',
    grid: 'stats mo-setup-tiles',
    default: '',                 // the start: every step
    tiles: () => setupTileList(),
    wants: (sub) => SETUP_TILES.some(([id]) => id === sub),      // a route before her state is in
  });
  return setupSw;
}
/** The tiles for the switcher: setupTileData() as it stands, each with its step as its section (none before her state) */
function setupTileList() {
  if (!state || !form) return [];
  return setupTileData().map((d) => ({ id: d.id, key: d.key, place: `setup.tile.${d.id}`,
    tile: (a) => Office.stat({ label: T(d.key), value: d.value, sub: d.sub.filter(Boolean), alert: d.alert, ...a }), section: () => setupStep(d.id) }));
}
function setupStep(id) {
  if (id === 'global') return setupGlobal();
  if (id === 'shares') return setupShares();
  return importSection();
}

/**
 * Her setup's tiles, pure (no DOM): [{id, key, value, sub: [text], alert}] in the steps' order — for all shares (the age,
 * the fill, how many lists and lines of what stays; alert while a share of Jack Emby's isn't covered), per share (how many
 * have rules of their own, how many are left out), taking over an earlier install (once she is set up).
 */
function setupTileData() {
  const g = form.global;
  const own = Object.values(form.shares).filter((s) => s.skip || s.min_age !== null || s.move_when_used_above !== null
    || s.files.length || s.patterns.join('\n').trim() !== '');
  const lines = g.patterns.filter((x) => x.trim() !== '' && !x.trim().startsWith('#')).length;
  const out = [];
  out.push({ id: 'global', key: 'setup.global', value: T('setup.tile.days', { n: Number(g.min_age) || 0 }),
    sub: [fillText(g.move_when_used_above),
      T('setup.tile.stays', { lists: nOf('lists', g.files.length), lines: nOf('lines', lines) })], alert: jackUncoveredIn(form).length > 0 });
  out.push({ id: 'shares', key: 'setup.shares', value: String(own.length),
    sub: [T('setup.tile.own', { n: own.length }), T('setup.tile.skipped', { n: Object.values(form.shares).filter((s) => s.skip).length })], alert: false });
  if ((state.import_found || []).length) out.push({ id: 'import', key: 'import.title', value: T('setup.tile.found'), sub: [state.import_found[0]], alert: false });
  return out;
}

/** 1. the rules for all shares */
function setupGlobal() {
  const g = form.global;
  const s1 = section(T('setup.global'), T('setup.global_sub'), { place: 'setup.global' });
  const f1 = el('div', 'mo-form');
  f1.append(
    field(T('setup.min_age'), number(g, 'min_age', 0, 3650), T(jack().hired ? 'setup.min_age_hint_jack' : 'setup.min_age_hint')),
    ...limitsOf('', g, T('setup.threshold_hint'), false),
    field(T('setup.debug'), select(g, 'mover_debug', [0, 1, 2, 3].map((n) => [String(n), String(n)])), T('setup.debug_hint')),
    field(T('setup.max_list'), number(g, 'max_list', 1, 100000), T('setup.max_list_hint')),
    check(T('setup.prefer'), g.move_prefer_shares, (on) => { g.move_prefer_shares = on; }, T('setup.prefer_hint')),
  );
  // the rule in her words and per pool the numbers: drawn anew at every keystroke
  const levels = levelSection(g);
  s1.addEventListener('input', () => levels.redraw());
  s1.append(f1, levels, excludeFields(g, 'global'));
  return s1;
}

// ---- the pool, only X (2026-10-14, agent/lib/poollevel.php): one limit X. Above it she moves the oldest files across the
// pool (never Jack Emby's) until it is below X again; Jack Emby gives back only what was watched and fetches only up to X.
/** Her pools: [pool, raw | null] — Jack Emby's pool first */
function levelList() {
  const c = (state && state.levels) || {};
  return Object.entries(c).sort(([a, x], [b, y]) => Number(!!(y && y.mine)) - Number(!!(x && x.mine)) || a.localeCompare(b));
}
/**
 * The numbers for the form's X (pure; the same rule as poolLevelMath()): bytes — need: what must go to be at X (her
 * oldest; Jack Emby's watched counts too, unknown here); never: what never moves reaches X; jackOver: with Jack Emby's
 * bytes it does
 */
function levelMath(c, x) {
  const size = Math.max(0, Number(c.size) || 0);
  const used = Math.min(Math.max(0, Number(c.used) || 0), size);
  const m = Math.min(Math.max(0, Number(c.movable) || 0), used);
  const nm = used - m;
  x = Number(x) || 0;
  const jack = c.mine ? Math.min(Math.max(0, Number(c.jack) || 0), m) : 0;
  const xb = x > 0 ? Math.floor(x * size / 100) : 0;
  const never = x > 0 && size > 0 && nm >= xb;
  return { size, used, nm, x, jack, others: m - jack, need: x > 0 ? Math.max(0, used - xb) : 0, never,
    jackOver: !never && x > 0 && size > 0 && nm + jack >= xb, nmPct: size > 0 ? nm * 100 / size : 0 };
}
/** What the next night moves on a pool, in her words */
function levelNight(pool, m) {
  return m.need > 0 ? T('setup.level_night_goes', { pool, goes: fmt.size(Math.min(m.need, m.others)) }) : T('setup.level_night_none', { pool });
}
/** One pool in words: what it holds and what never moves, the next night — a warning when she can't get below X */
function levelLines(pool, c, g) {
  const m = levelMath(c, g.move_when_used_above);
  // a btrfs/xfs pool: estimated, with the age of its last measuring of the share folders
  const p = { pool, used: fmt.size(m.used), size: fmt.size(m.size), nm: fmt.size(m.nm) };
  const out = [c.estimated === true ? T('setup.level_pool_estimated', { ...p, age: fmt.relative(Number(c.measured_at) || 0) }) : T('setup.level_pool', p)];
  if (m.never) return { lines: out, warn: T('setup.level_never', { pool, nm: Math.floor(m.nmPct), x: m.x }) };
  out.push(levelNight(pool, m));
  return { lines: out, warn: m.jackOver && jack().hired ? T('setup.level_jack_over', { pool, jack: fmt.size(m.jack), x: m.x }) : null };
}
/** «Der Pool»: her rule with the number put in, per pool a few lines — drawn anew at every keystroke (redraw()) */
function levelSection(g) {
  const box = el('div', 'mo-levels');
  box.redraw = () => {
    box.innerHTML = '';
    const x = Number(g.move_when_used_above) || 0;
    if (!(x > 0)) { box.appendChild(el('p', 'role', T('setup.level_off'))); return; }
    box.appendChild(el('p', 'mo-level-rule', T(jack().hired ? 'setup.level_rule_jack' : 'setup.level_rule', { x })));
    levelList().forEach(([pool, c]) => {
      if (!c) {
        // a btrfs/xfs pool before its first measuring (it runs in the background) — else asleep or not measurable
        const wait = Array.isArray(state.levels_wait) && state.levels_wait.includes(pool);
        box.appendChild(el('p', 'role', T(wait ? 'setup.level_wait' : 'setup.level_unseen', { pool })));
        return;
      }
      const v = levelLines(pool, c, g);
      box.appendChild(el('p', 'mo-level-pool', v.lines.join(' ')));
      if (v.warn) box.appendChild(el('p', 'callout warn', v.warn));
      // a share's folder on the pool that is no dataset of its own: counted as never moving (Jack Streamer's page says how to fix)
      const folders = Array.isArray(c.folders) ? c.folders : [];
      if (folders.length) box.appendChild(el('p', 'callout warn', T('setup.level_folders', { pool, shares: folders.join(', ') })));
    });
  };
  box.redraw();
  return box;
}

/** «Höchstens so voll» for a scope, registered for the immediate check (markLimits()) */
function limitsOf(scope, obj, thHint, optional) {
  const th = field(T('setup.threshold'), number(obj, 'move_when_used_above', 0, 100, optional), thHint);
  limitPair(scope, th);
  return [th];
}

/** 2. per share: a fold each (the bar's words: name, where its files go) */
function setupShares() {
  const s2 = section(T('setup.shares'), T('setup.shares_sub'), { place: 'setup.shares' });
  if (setupBar) s2.appendChild(setupBar.el);
  const box = el('div');
  dom.setup = box;
  fillSetupShares(box);
  s2.appendChild(box);
  return s2;
}
/** A share's own rules in a few words (pure): left out, its age, its limit, its lists and lines; '' when none */
function ownRulesText(sv) {
  if (!sv) return '';
  const parts = [];
  if (sv.skip) parts.push(T('setup.own_part.skip'));
  if (sv.min_age !== null && sv.min_age !== undefined) parts.push(T('setup.own_part.age', { days: T('setup.tile.days', { n: Number(sv.min_age) || 0 }) }));
  if (sv.move_when_used_above !== null && sv.move_when_used_above !== undefined) parts.push(T('setup.own_part.limit', { pct: Number(sv.move_when_used_above) || 0 }));
  if ((sv.files || []).length) parts.push(nOf('lists', sv.files.length));
  const lines = (sv.patterns || []).map((x) => x.trim()).filter(Boolean).length;
  if (lines) parts.push(nOf('lines', lines));
  return parts.join(', ');
}

/**
 * A share whose own rules do nothing (2026-10-18): nothing to move with its storage, or gone from Unraid — what they
 * are and «Remove rules»: they leave the form (the bar counts it, «Save» makes it so, «Discard» brings them back);
 * never removed by her
 */
function idleRules(x, sv, redraw) {
  const box = el('div', 'mo-idle-rules');
  const what = ownRulesText(sv);
  if (!what) {
    box.appendChild(el('p', 'role', T('setup.own_removed', { save: T('setup.save'), discard: T('setup.discard') })));
    return box;
  }
  box.appendChild(el('p', '', T(x.gone ? 'setup.own_gone' : 'setup.own_idle', { share: x.share }) + ' ' + what + '.'));
  const b = button(T('setup.own_remove'), 'small plain', () => {
    Object.assign(sv, { skip: false, min_age: null, move_when_used_above: null, files: [], patterns: [] });
    redraw();
    setupChanged();
  });
  box.appendChild(b);
  return box;
}

/**
 * Why a share has nothing to move, as Unraid has it set up (the owner, 2026-10-18), pure: only on its pool (`only`), only
 * on the array (`no`) — neither has a ⟦Secondary storage⟧ —, else (an older agent's row without `use`) that it has none
 */
function idleText(x) {
  if (x.use === 'only') return T('state_tip.none_pool', { share: x.share, pool: x.primary || 'cache' });
  if (x.use === 'no') return T('state_tip.none_array', { share: x.share });
  return T('state_tip.none', { share: x.share });
}

function fillSetupShares(box) {
  box.innerHTML = '';
  const gone = (state.shares_gone || []).filter((n) => form.shares[n]).map((n) => ({ share: n, mode: 'none', state: 'gone', gone: true, own: true }));
  const shares = [...(state.shares || []).filter((x) => form.shares[x.share]), ...gone].sort((a, b) => (a.share < b.share ? -1 : a.share > b.share ? 1 : 0));
  const fit = shares.filter((x) => fitsBar(setupBar, shareHay(x)));
  fit.forEach((x) => {
    const sv = form.shares[x.share];
    const det = keepFold(el('details', 'box mo-share'), x.share, false);
    const sum = el('summary', '', x.share);
    sum.appendChild(chip(x.gone ? T('state.gone') : x.mode === 'none' ? T('state.none') : T('direction', { from: where(x.from), to: where(x.to) }), 'quiet'));
    if (x.own) sum.appendChild(chip(T('own_rules'), 'quiet'));
    if (x.mode === 'yes') {
      const th = sv.move_when_used_above ?? form.global.move_when_used_above;
      if (Number(th)) sum.appendChild(chip(fillText(th), 'quiet'));
    }
    det.appendChild(sum);
    if (x.mode === 'none') {
      if (!x.gone) det.appendChild(el('p', 'role', idleText(x)));
      if (x.own) det.appendChild(idleRules(x, sv, () => fillSetupShares(box)));
    } else {
      const f = el('div', 'mo-form');
      f.append(check(T('setup.skip'), sv.skip, (on) => { sv.skip = on; }, T('setup.skip_hint')),
        field(T('setup.min_age'), number(sv, 'min_age', 0, 3650, true), x.mode === 'prefer' ? T('setup.min_age_prefer') : ''));
      if (x.mode === 'yes') {
        f.append(...limitsOf(x.share, sv, '', true));
      }
      det.append(f, excludeFields(sv, x.share));
    }
    box.appendChild(det);
  });
  if (!shares.length) box.appendChild(el('p', 'empty', T('shares_none')));
  if (setupBar) setupBar.done(fit.length, shares.length);
}

// ------------------------------------------------------------------ taking over an earlier install
/*
 * Someone who ran helmi1987's Smart Mover before (usually from a User Script) types the folder of its smart_mover.ini;
 * the agent reads it and the lists it names on the server (agent/desks/moverelli-import.php), the preview says what
 * would come over and what is left out, the import needs its token. It replaces her settings as a whole. Afterwards:
 * switch the old User Script off yourself, start with a dry run, the copies of her files are named.
 */
let importFolder = '';
/** The setup's label of a key of the ini */
const IMPORT_LABEL = { min_age: 'setup.min_age', move_when_used_above: 'setup.threshold',
  move_prefer_shares: 'setup.prefer',
  mover_debug: 'setup.debug', max_list: 'setup.max_list', skip: 'setup.skip' };

function importSection() {
  const b = button(T('import.open'), 'small', importDialog);
  b.disabled = !Office.agent.running;
  return section(T('import.title'), T('import.sub'), b, { place: 'import.title' });
}

function importDialog() {
  const box = el('div');
  box.appendChild(el('p', '', T('import.intro')));
  const f = el('div', 'mo-form');
  const i = input(importFolder, (x) => { importFolder = x; });
  i.placeholder = '/mnt/user/system/scripts/custommover';
  f.appendChild(field(T('import.folder'), i, T('import.folder_hint')));
  const err = errorLine();
  box.append(f, el('p', 'role', T('import.read_only')), err);
  Office.dialog({ title: T('import.title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('import.look'), kind: '', act: () => importLook(err) }] });
}

async function importLook(err) {
  const folder = importFolder.trim();
  const j = await Office.api.post(`${ID}.import_preview`, { folder });
  if (!j.ok) { showError(err, j.error); return false; }
  importPreview(j.preview, folder);
  return true;
}

/** A value as her setup page says it */
function importValue(key, v) {
  if (typeof v === 'boolean') return T(v ? 'import.yes' : 'import.no');
  return String(v);
}

/** A short list: the label (mono) and a text beside it */
function importList(items) {
  const ul = el('ul', 'shortlist');
  items.forEach(([k, text, cls]) => { const li = el('li', cls || '', k); if (text !== undefined) li.appendChild(el('span', '', text)); ul.appendChild(li); });
  return ul;
}
function importHead(text) { return el('p', 'mo-imp-head', text); }
const importScope = (scope) => (scope === 'GLOBAL' ? T('import.scope_global') : scope);

/** What a list file of the old ini became: copied (how many entries), Jack Emby's own named, or not read and why */
function importFileText(f) {
  if (f.how === 'jack') return T('import.how.jack');
  if (f.how === 'copied') return T('import.how.copied', { entries: nOf('entries', f.lines) });
  return T('import.how.not_read.' + (f.why || 'not_allowed'));
}

/** What stops the import, with what to do: a list not read, lines her lists can't take, a bad age or fill, too much */
function importBlockText(b) {
  const scope = importScope(b.scope);
  if (b.why === 'not_read') return T('import.block.not_read.' + (b.reason || 'not_allowed'), { scope, file: b.file });
  if (b.why === 'bad_lines') return T('import.block.bad_lines', { scope, n: Number(b.n) || 0 });
  if (b.why === 'value') {
    return T('import.block.value', { scope, key: T(IMPORT_LABEL[b.key] || 'setup.min_age'), reason: T('import.why.bad.' + b.reason, { value: b.value }) });
  }
  if (b.why === 'files') return T('import.block.files', { scope, n: b.n, max: b.max });
  return T('import.block.lines', { scope, max: b.max });
}

function importPreview(p, folder) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.preview_bubble')));
  const kv = el('dl', 'kv');
  kv.append(el('dt', '', T('import.folder')), el('dd', 'mono', p.folder));
  box.appendChild(kv);
  (p.blocked || []).forEach((b) => box.appendChild(el('p', 'callout warn', importBlockText(b))));
  if ((p.jack_uncovered || []).length) box.appendChild(el('p', 'callout warn', T('errors.moverelli_jack_list', { shares: p.jack_uncovered.join(', ') })));
  if (p.configured) {
    box.appendChild(el('p', 'callout', T('import.replaces') + (p.gone.length ? ' ' + T('import.gone', { shares: p.gone.join(', ') }) : '')));
  }

  // the values: for all shares, then per share
  box.appendChild(importHead(T('setup.global')));
  box.appendChild(importList(p.global.map((x) => [T(IMPORT_LABEL[x.key]),
    importValue(x.key, x.value) + (x.old ? '' : ' · ' + T('import.default'))])));
  box.appendChild(importHead(T('setup.shares')));
  if (p.shares.length) {
    box.appendChild(importList(p.shares.map((s) => [s.share,
      s.values.length ? s.values.map((x) => `${T(IMPORT_LABEL[x.key])}: ${importValue(x.key, x.value)}`).join(' · ') : T('import.only_lists')])));
  } else {
    box.appendChild(el('p', 'role', T('import.shares_none')));
  }

  // the lists: what comes into hers, file by file
  if (p.lists.length) {
    box.appendChild(importHead(T('setup.files')));
    p.lists.forEach((l) => {
      box.appendChild(el('p', 'mo-imp-scope', `${importScope(l.scope)} — ${T('import.taken', { entries: nOf('entries', l.taken) })}`));
      box.appendChild(importList(l.files.map((f) => [f.file, importFileText(f), f.how === 'not_read' ? 'mo-imp-warn' : ''])));
      l.files.filter((f) => f.frozen).forEach(() => box.appendChild(el('p', 'role', T('import.how.frozen'))));
      if (l.sample.length) box.appendChild(el('p', 'role', T('import.sample', { lines: l.sample.join(' · ') })));
      if (l.bad) box.appendChild(el('p', 'role', T('import.bad_lines', { n: l.bad })));
    });
  }
  if (p.jack_lists) box.appendChild(el('p', 'role', T('import.jack_note')));

  // what is left out, and why
  if (p.left.length) {
    box.appendChild(importHead(T('import.left')));
    box.appendChild(importList(p.left.map((x) => [`[${x.section}] ${x.key}`,
      x.why === 'bad' ? T('import.why.bad.' + x.reason, { value: x.value }) : T('import.why.' + x.why, { value: x.value || '' })])));
  }
  if (p.skipped.length) {
    box.appendChild(importHead(T('import.skipped')));
    box.appendChild(importList(p.skipped.map((x) => [`[${x.section}]`, T('import.skip_why.' + x.why)])));
  }

  if (p.running) box.appendChild(el('p', 'callout warn', T('errors.moverelli_running')));
  else if (!p.ready) box.appendChild(el('p', 'callout warn', T('errors.moverelli_import_blocked')));
  else box.appendChild(el('p', 'role', T('import.backup_note')));
  // Unraid's own mover goes off with it, like her save: said here, the button says it too (its click is the consent)
  const off = !mover().off;
  if (off && p.ready && !p.running) {
    box.appendChild(el('p', 'callout', `${T('consent.text', { schedule: mover().schedule ? fmt.cron(mover().schedule) : '?' })} ${T('consent.back')}`));
  }
  const err = errorLine();
  box.appendChild(err);
  const buttons = [{ text: Office.t('common.cancel') }];
  if (p.ready && !p.running && Office.agent.running) buttons.push({ text: T(off ? 'import.go_mover_off' : 'import.go'), kind: '', act: () => importGo(folder, p, err, off) });
  Office.dialog({ title: T('import.preview_title'), body: box, wide: true, buttons });
}

async function importGo(folder, p, err, moverOff) {
  const j = await Office.api.post(`${ID}.import_apply`, moverOff ? { folder, token: p.token, mover_off: true } : { folder, token: p.token });
  if (!j.ok) { showError(err, j.error); return false; }
  if (j.state) state = j.state;
  form = null;
  importDone(j.done);
  return true;
}

function importDone(d) {
  const box = el('div');
  box.appendChild(el('p', '', T('import.done_bubble')));
  const what = [T('import.done_rules'), T('import.done_shares', { n: d.shares }), T('import.done_lines', { n: d.lines })];
  if (d.jack) what.push(T('import.done_jack'));
  box.appendChild(importList(what.map((w) => [w])));
  const still = (state && state.foreign && state.foreign.scripts) || [];
  box.appendChild(el('p', 'callout warn', T('import.done_switch_off') + (still.length ? ' ' + T('import.done_still', { where: still.join(', ') }) : '')));
  box.appendChild(el('p', 'callout', T('import.done_dry')));
  if (d.backups.length) {
    box.append(importHead(T('import.done_copies')), importList(d.backups.map((f) => [f])), el('p', 'role', T('import.done_undo', { stamp: d.stamp })));
  } else {
    box.appendChild(el('p', 'role', T('import.done_nothing_before')));
  }
  Office.dialog({ title: T('import.done_title'), body: box, wide: true,
    buttons: [{ text: Office.t('common.close'), kind: '' }], onClose: () => Office.go(`#/${ID}`) });
}

// ------------------------------------------------------------------ let go
/**
 * Her part of the let-go dialog (core.js Office.fireDialog → letGo; agent/desks/moverelli-unraid.php). She looks first
 * (moverelli.letgo_look): Jack Emby still hired — she can't go, the intern never works unsupervised (the button stays
 * off; src/staff.php refuses too); else her schedule goes off and Unraid's own mover schedule comes back as it was before
 * her. «Let go» — while she is still hired — sends moverelli.letgo {confirm} once; afterwards a dialog says what she did.
 */
function letGoPart(box) {
  const lines = el('div', 'mo-letgo');
  box.appendChild(lines);
  let button = null;
  let blocked = false;
  let sent = null;
  const show = (j) => {
    lines.replaceChildren(...letGoView(j).map(([cls, text]) => el('p', cls, text)));
    blocked = !!(j && j.ok && j.jack);
    if (button) button.disabled = blocked;
  };
  let looked = null;
  if (!Office.agent.running) lines.replaceChildren(el('p', 'callout warn', T('letgo.agent_away')));
  else {
    lines.replaceChildren(el('p', 'role', T('letgo.looking')));
    looked = Office.api.post(`${ID}.letgo_look`, {}).then(show, (e) => show({ ok: false, error: { key: 'internal', params: { detail: String((e && e.message) || e) } } }));
  }
  return {
    looked,                      // the look under way (the tests wait for it)
    bind(b) { button = b; if (blocked) b.disabled = true; },
    before() {                   // once, even if «Let go» is pressed again
      if (!sent) sent = Office.api.post(`${ID}.letgo`, { confirm: true });
      return sent;
    },
    done(r) {
      const out = letGoDoneLines(r);
      if (!out.length) return;
      const body = el('div');
      out.forEach(([cls, text]) => body.appendChild(el('p', cls, text)));
      Office.dialog({ title: T('letgo.done_title'), body, buttons: [{ text: Office.t('common.close'), kind: '' }] });
    },
  };
}
/** What her look says, as lines [class, text] (pure) */
function letGoView(j) {
  if (!j || !j.ok) return [['callout warn', Office.errorText((j && j.error) || { key: 'internal' }, ID)]];
  if (j.jack) return [['callout warn', T('letgo.jack')]];
  const out = [['', T(j.schedule ? 'letgo.sched_off' : 'letgo.sched_none')]];
  const u = j.unraid || {};
  if (!u.off) out.push(['role', T('letgo.unraid_on')]);
  else if (u.back) out.push(['', T('letgo.unraid_back', { schedule: fmt.cron(u.back) })]);
  else out.push(['role', T('letgo.unraid_stays')]);
  return out;
}
/** After she was let go: what she did (pure) */
function letGoDoneLines(r) {
  if (!r || !r.ok) return [['callout warn', T('letgo.done_error', { error: Office.errorText((r && r.error) || { key: 'internal' }, ID) })]];
  const out = [];
  const u = r.unraid || {};
  if (u.back) out.push(['', T('letgo.done_back', { schedule: fmt.cron(u.was) })]);
  else if (u.kept) out.push(['role', T('letgo.done_kept', { schedule: fmt.cron(u.schedule) })]);
  else if (u.error) out.push(['callout warn', T('letgo.done_back_not', { error: Office.errorText(u.error, ID) })]);
  return out;
}

// ------------------------------------------------------------------ desk
Office.desk({
  letGo: letGoPart,
  id: ID,
  runs: (s) => !!((s.job && s.job.running) || (s.night && s.night.last && s.night.last.running)),       // core.js: an old kept state saying so isn't shown
  async mount(root, sub) {
    leftSetup();
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';
    bars();
    if (page === 'setup') {
      // #/moverelli/setup/<tile> (the search, a step's place) picks that step exactly like a click, the address keeps its
      // page; else the step chosen last (moverelli.setup.tile), none = every step
      if (setupSwitcher().mount(String(sub || '').split('/').slice(1).join('/'))) Office.subroute('setup');
      if (!state) await load(false);
      // the form starts from her settings and Unraid's shares as they are now: a fresh look, never a stale one (the owner,
      // 2026-10-18: a share set pool-only in Unraid still showed as «back into the pool» — her state up to 5 min old)
      await lookAgain();
      if (view !== root || page !== 'setup') return;
      form = null;
      renderSetup();
      return;
    }
    saveDraw = null;
    // her tiles as places: #/moverelli/<tile> picks it exactly like a click (the address is her page's again); the Team
    // Lead's «Open» for her schedule (#/moverelli/schedule) picks «Schedule» and opens its dialog as before
    switcher().mount(sub);
    render();
    await load(false);
    if (sub === 'schedule') scheduleDialog();
    // show first, then look: her shares as Unraid has them now (a share's ⟦Primary storage⟧ or ⟦Secondary storage⟧
    // changed since her last look) — the agent's tick looks too once a share's cfg changes (moverelliTick())
    if (view === root && page === 'main') lookAgain();
  },
  unmount() { leftSetup(); view = null; saveDraw = null; clearTimeout(outTimer); clearTimeout(progTimer); progTimer = null; },
  poll() { if (page === 'main') load(false); },
  greetOk,
  agentChanged() { if (view) (page === 'setup' ? renderSetup() : render()); },
  menu() {
    const items = [{ text: T('menu.refresh'), act: () => load(true) }];
    if (state && state.configured) {
      items.push({ text: T('schedule_title'), act: scheduleDialog });
      items.push({ text: T('show_log'), act: showLog });
    }
    return items;
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state && state.configured) {
      facts.push(T('fact.shares', { n: (state.shares || []).filter((x) => x.state === 'moves').length }));
      const lr = lastRun();
      if (lr && lr.finished) facts.push(T('fact.last', { when: fmt.relative(lr.finished) }));
      if (!othersOff()) facts.push(T('fact.dry'));
    } else {
      facts.push(T('fact.setup'));
    }
    return { bubble: T('bubble.hello_short'), facts };
  },
});

// what her state holds for the search: the shares (#/moverelli/shares), the runs her list shows (#/moverelli/runs)
Office.placesFrom(ID, (s, part) => {
  if (part) return [];
  const out = [];
  (s.shares || []).forEach((x) => out.push({ text: x.share, sub: T('shares'), route: `#/${ID}/shares`, anchor: `share:${x.share}` }));
  (s.history || []).slice(0, HISTORY_SHOWN).forEach((r) => out.push({ text: `${T('mode.' + r.mode)} · ${fmt.date(r.started)}`,
    sub: `${T('history')} · ${T('result.' + (r.result || 'failed'))}`, route: `#/${ID}/runs`, anchor: `run:${r.started}` }));
  return out;
});

// her places for the search (places.json lists the same keys): each section by the route of its tile (a section stands
// under the row only while its tile is chosen — or none is), her tiles (place tile.<id>), then the setup's steps and tiles
const SETUP = { route: '#/moverelli/setup', crumb: 'setup_open' };
const importShown = () => !state || !!(state.import_found || []).length;     // not known yet: listed
Office.places(ID, [
  { kind: 'section', key: 'schedule', route: `#/${ID}/schedule`, shown: configured },
  { kind: 'section', key: 'shares', route: `#/${ID}/shares` },
  { kind: 'section', key: 'history', route: `#/${ID}/runs` },
  ...TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `tile.${id}`, route: `#/${ID}/${id}`, ...(id === 'schedule' ? { shown: configured } : {}) })),
  { kind: 'section', key: 'tools' },
  // (the import's part only while something to import was found — its tile comes only then)
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'step', key, ...SETUP, route: `#/${ID}/setup/${id}`, ...(id === 'import' ? { shown: importShown } : {}) })),
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `setup.tile.${id}`, ...SETUP, route: `#/${ID}/setup/${id}`, ...(id === 'import' ? { shown: importShown } : {}) })),
  ...[['list', 'help.list'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run']].map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'rules', 'unraid', 'jack', 'schedule'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup.global', 'setup.help_global'], ['setup.files', 'setup.help_files'], ['setup.shares', 'setup.help_shares'], ['setup.save', 'setup.help_save'],
    ['import.title', 'import.help']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.moverelli = { setState: (s) => { state = s; }, runSummary, leftText, onWayText, moverNotice, foreignNotices, shareSection, linesOf, filesOf,
    importPreview, importDone, importFileText, leftCount, scheduleDialog, letGoView, letGoDoneLines, consentSection, runCardData, percentText, runningText, greeting, greetOk, idleText, runEstimate, estModel, endText, shareGroups, shareRuleLines, runCard, headActions, fillText, fillLine, resultBox, setProgress: (p, w) => { progress = p; stopping = w || null; }, setupChangeRows, setupBarSpec, saveFootEl, setupProblems, ownRulesText, idleRules, fillSetupShares, limitsOf, markLimits, formSettings, setupApply, foundNotice, setPage: (p) => { page = p; }, setSaved: (x) => { saved = x; }, bubbleText, segWidths, pictureBars, picFlyer, picFlight, fileOnto, movedByPlace, movedAt, extraPlaces, nightPicture, livePicture, tonightText, tonightShort, ageRule, lastNightText, jackOutText, followRun, runEnded, runKeys, setSw: (x) => { sw = x; }, nightSteps, setNight: (x) => { nightNow = x; }, setJackLive: (x) => { jackLive = x; }, jackPanel, jackAtWork, redrawCard, schedText, scheduleSection, historySection, footer, hints,
    tileList, switcher, scheduleTile, sharesTile, lastRunTile, setupTileData, setupTileList, SETUP_TILES, TILES, renderSetup,
    setForm: (f) => { form = f; }, initForm, levelMath, levelSection, levelLines, levelNight, jackUncoveredIn, jackNotice, form: () => form, setView: (v, p) => { view = v; page = p || 'main'; }, bars, render };
}
})();
