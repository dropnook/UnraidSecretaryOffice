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

// ------------------------------------------------------------------ her run card (2026-10-12, like Mr. Restori's)
let progress = null;          // Smart Mover's live status while a real run goes (the part «progress»)
let nightNow = null;          // the night run's short state while it goes (the part «progress»: phase, round, who works)
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
  if (!p.real) { progress = null; stopping = null; nightNow = null; load(true); return; }     // the run ended: her state anew — the card goes
  progress = p.progress || null;
  nightNow = p.night || null;
  stopping = p.stopping || null;
  redrawCard();
  if (picLive && picLive.isConnected && nightNow) picLive.update(nightNow);
  scheduleProgress();
}
function redrawCard() {
  const old = view && view.querySelector ? view.querySelector('.mo-run') : null;
  if (old && old.replaceWith) old.replaceWith(runCard(progress, Date.now() / 1000));
}

/**
 * What her card shows of Smart Mover's live status, pure (tests/run.php under node): the steps — Umschauen, one per share
 * of the run, Aufräumen — with the current one, the share in flight by files and bytes, its percent (by bytes, else
 * files), the file in flight, the time left from the measured rate (core.js Office.runRate: two readings needed) and
 * the end once the share in flight is the run's last
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
  const percent = phase !== 'share' ? null : bytes > 0 ? Math.min(100, Math.floor(100 * dBytes / bytes)) : files > 0 ? Math.min(100, Math.floor(100 * dFiles / files)) : null;
  let left = null;
  let end = null;
  let rate = null;
  if (phase === 'share' && bytes > 0) {
    rate = Office.runRate(`moverelli|${p.started}|${p.share}`, dBytes, now);
    if (rate) {
      left = Math.max(0, (bytes - dBytes) / rate);
      if (plan[plan.length - 1] === p.share) end = now + left;
    }
  }
  // the file's own rate too (its .partial growing): said beside it even before the share's bar has two readings
  const fileRate = fileBytes > 0 && p.file ? Office.runRate(`moverelli|${p.started}|${p.file}`, fileBytes, now) : null;
  return { steps, at, percent, left, end, rate, fileRate, waking: !!p.waking && !fileBytes, fileBytes, fileSize: p.file_size == null ? null : Number(p.file_size), share: p.share || '', file: p.file || '', files, bytes, dFiles, dBytes, started: p.started || null,
    fill: phase === 'share' ? fillLine(p) : null };
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

/** The card above the tiles while a real run goes: the steps, the bar, the file in flight, the end; «Stop after this file» */
function runCard(p, now) {
  const nn = nightNow || (nightGoing() ? night().last : null);
  // the night run: the card Jack Emby's page shows too (core.js Office.nightCard — «Joint run», since when, its phases);
  // who is at work — Jack Emby's files show on his page (his live panel)
  let card;
  let top;
  if (nn) {
    card = Office.nightCard(nn, { T, now, stopping, cls: 'mo-run' });
    if (nn.who === 'jack') {
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
    if (!nn || nn.who !== 'jack') card.appendChild(el('p', 'role', T('rc.waiting')));
  } else {
    const d = runCardData(p, now);
    if (d.started && !nn) top.appendChild(el('span', 'status quiet', T('rc.since', { time: fmt.time(d.started), duration: fmt.duration(now - d.started) })));
    card.appendChild(Office.runSteps(d.steps, d.at, `moverelli|${p.started}`));
    if (d.percent !== null) {
      card.appendChild(Office.runBar(d.percent, `${d.percent} %` + (d.rate ? ' · ' + T('rc.rate', { rate: fmt.size(d.rate) }) : '')
        + (d.left !== null ? ' · ' + T('rc.left', { left: fmt.duration(d.left) }) : '')));
      card.appendChild(el('div', 'card-line', T('rc.share', { share: d.share, done: d.dFiles, files: nOf('files', d.files), done_size: fmt.size(d.dBytes), size: fmt.size(d.bytes) })));
    }
    if (d.fill) card.appendChild(el('div', 'card-line', d.fill));
    if (d.file) {
      card.appendChild(el('div', 'card-line mo-run-file', T('rc.file', { file: d.file })
        + (d.fileSize ? ' · ' + T('rc.file_bytes', { done: fmt.size(d.fileBytes), size: fmt.size(d.fileSize) }) : '')
        + (d.fileRate || d.rate ? ' · ' + T('rc.rate', { rate: fmt.size(d.fileRate || d.rate) }) : '')));
      if (d.waking) card.appendChild(el('div', 'card-line', T('rc.waking')));
    }
    if (d.end) card.appendChild(el('div', 'card-line', T('rc.end', { time: fmt.time(d.end) })));
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
const PIC_SEGS = ['fixed', 'others', 'jack', 'free'];
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
 * after, forecast (the after bar is a forecast), dest {to, size, used, measured, w}}; a bar: {size, used, segs [{k, bytes,
 * w}]} — its free part is what the size leaves (the segments sum to the bar). Not measured (no pool numbers): measured false.
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
    const val = { fixed: Math.max(0, Number(b.fixed) || 0), others: Math.max(0, Number(b.others) || 0), jack: Math.max(0, Number(b.jack) || 0) };
    const used = Math.min(size, val.fixed + val.others + val.jack);
    val.free = Math.max(0, size - used);
    const w = segWidths(PIC_SEGS.map((k) => val[k]), min);
    return { size, used, segs: PIC_SEGS.map((k, i) => ({ k, bytes: val[k], w: w[i] })) };
  };
  const x = Number(pic.x) > 0 ? Math.min(1, Number(pic.x) / 100) : null;
  const after = end || fc;
  return { measured: true, pool: pic.pool || '', x, before: bar(first), after: after ? bar(after) : null, forecast: !end, dest };
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
  const head = el('div', 'mo-pic-head', T(n.running ? 'pic.title_run' : 'pic.title_last'));
  const grid = el('div', 'mo-pic-grid');
  const rowsBox = el('div', 'mo-pic-rowsBox');
  const side = el('div', 'mo-pic-dest');
  grid.append(rowsBox, side);
  const legend = el('div', 'mo-pic-legend');
  const line = el('div', 'mo-pic-file');
  const dot = el('span', 'mo-pic-dot');
  box.append(head, grid, legend, line, dot);
  const pct = (b, size) => fmt.number(size > 0 ? b * 100 / size : 0, 1);
  const segName = (k) => T('pic.seg.' + k);
  let data = null;
  /** One bar with its title and X; its segments' words on hover (title) */
  function barRow(title, b) {
    const row = el('div', 'mo-pic-row');
    row.appendChild(el('div', 'mo-pic-label', title));
    const bar = el('div', 'mo-pic-bar');
    bar.setAttribute('role', 'img');
    bar.setAttribute('aria-label', `${title}: ${T('pic.bar_label', { pct: pct(b.used, b.size) })}`);
    for (const s of b.segs) {
      const seg = el('i', 'mo-pic-seg mo-seg-' + s.k);
      seg.style.width = `${(s.w * 100).toFixed(3)}%`;
      seg.title = T('pic.seg_tip', { what: segName(s.k), size: fmt.size(s.bytes), pct: pct(s.bytes, b.size) });
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
      rowsBox.appendChild(barRow(T('pic.before'), data.before));
      if (data.after) rowsBox.appendChild(barRow(T(data.forecast ? 'pic.forecast' : 'pic.after'), data.after));
      // the legend: each part before → after (the true values)
      for (const k of PIC_SEGS) {
        const it = el('span', 'mo-pic-key');
        const b = data.before.segs.find((s) => s.k === k).bytes;
        const a = data.after ? data.after.segs.find((s) => s.k === k).bytes : null;
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
    say(lastFile());
  }
  const files = () => (Array.isArray(pic.files) ? pic.files.filter((f) => f && typeof f.name === 'string') : []);
  const lastFile = () => files().slice(-1)[0] || null;
  const short = (name) => { const b = String(name).split('/').pop(); return b.length > 48 ? b.slice(0, 46) + '…' : b; };
  /** Where a file was and went in words: Jack Emby's between the pool and the array, hers between the pool and her target */
  function ends(f) {
    const pool = T('pic.pool_name', { pool: pic.pool || '' });
    const to = f.who === 'jack' || !data || !data.dest || data.dest.to === 'array' ? T('pic.array') : T('pic.pool_name', { pool: data.dest.to });
    return fileOnto(f, pic.pool) ? [to, pool] : [pool, to];
  }
  function say(f, moving) {
    line.textContent = '';
    if (!f) return;
    const [from, to] = ends(f);
    line.textContent = T(moving ? 'pic.moving' : 'pic.last_file', { file: short(f.name), size: fmt.size(Number(f.bytes) || 0), from, to });
  }
  // the slow walk: one real file at a time from where it was to where it went (her files and Jack Emby's watched ones
  // off the pool, his new ones onto it); the newest as they come, the latest few again while nothing new comes — calm
  const seen = new Set();
  const queue = [];
  const key = (f) => `${f.who}|${f.name}|${f.bytes}`;
  function feed() {
    for (const f of files()) if (!seen.has(key(f))) { seen.add(key(f)); queue.push(f); }
  }
  let walking = false;
  async function walk() {
    if (walking) return;
    walking = true;
    while (box.isConnected && n.running && data && data.measured && !reducedMotion()) {
      if (!queue.length) queue.push(...files().slice(-6));
      if (!queue.length) break;
      await flyFile(queue.shift());
      await new Promise((r) => setTimeout(r, 1500));
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
  function flyFile(f) {
    const onto = fileOnto(f, pic.pool);
    const rows = box.querySelectorAll('.mo-pic-rowsBox .mo-pic-bar');
    const k = f.who === 'jack' ? 'jack' : 'others';
    const target = spot(box.querySelector('.mo-pic-target'));
    const from = onto ? target : spot(rows[0] && rows[0].querySelector(`.mo-seg-${k}`));
    const to = onto ? spot((rows[1] || rows[0]) && (rows[1] || rows[0]).querySelector(`.mo-seg-${k}`)) : target;
    if (!from || !to || !dot.animate) return Promise.resolve();
    dot.className = 'mo-pic-dot mo-seg-' + k;
    say(f, true);
    const at = (p) => `translate(${p.x.toFixed(1)}px, ${p.y.toFixed(1)}px)`;
    return dot.animate([{ transform: at(from), opacity: 0 }, { transform: at(from), opacity: 1, offset: 0.12 },
      { transform: at(to), opacity: 1, offset: 0.88 }, { transform: at(to), opacity: 0 }], { duration: 5000, easing: 'ease-in-out', fill: 'forwards' })
      .finished.catch(() => {});
  }
  draw();
  feed();
  box.update = (nn) => {
    if (!nn || !nn.picture) return;
    n = nn;
    pic = nn.picture;
    head.textContent = T(n.running ? 'pic.title_run' : 'pic.title_last');
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
/** «cache», «array» in words: the array is named in the page's language */
const where = (x) => (x === 'array' ? T('array') : x || '–');
function schedText(sc) {
  if (!sc || !sc.enabled) return T('not_scheduled');
  return sc.frequency === 'custom' ? fmt.cron(sc.custom) : sc.frequency;
}

/** One line about how a run went (her list of runs, the last run) */
function runSummary(r) {
  if (r.result === 'refused') {
    return T('refused', { why: Office.errorText({ key: r.why, params: { schedule: r.schedule || '', shares: r.shares || '', version: r.version || '', needed: (state && state.unraid.needed) || '' } }, ID) })
      + (Number(r.times) > 1 ? ' · ' + T('refused_times', { n: Number(r.times) }) : '');
  }
  if (r.result === 'skipped') return T('skipped_mover', { min: Math.round((r.waited || 0) / 60) });
  const s = r.status || r;
  const mode = r.mode || s.mode;
  if (s.result === 'busy') return T('result.busy');
  if (s.result === 'config') return s.message || T('result.config');
  if (mode === 'list') return nOf('shares', (s.shares || []).length);
  const notes = [];
  if (s.result === 'stopped') {
    const file = String(r.stop_file || s.stop_file || '').split('/').pop() || '?';
    notes.push(r.why === 'mover' ? T(r.mover_by === 'schedule' ? 'stopped_mover_schedule' : 'stopped_mover')
      : r.why === 'now' ? T('stopped_now', { file }) : T('stopped_user', { file, share: s.stop_share || '?' }));
  }
  if (mode === 'dry') notes.push(T('dry_summary', { files: nOf('files', s.files), size: fmt.size(s.bytes || 0) }));
  else notes.push(T('run_summary', { moved: s.moved || 0, files: s.files || 0, size: fmt.size(s.bytes || 0), left: nOf('left', s.left) }));
  if (s.errors) notes.push(nOf('errors', s.errors));
  if (r.waited) notes.push(T('waited', { min: Math.round(r.waited / 60) }));
  return notes.join(' · ');
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const intro = T('bubble.intro');
  if (!unraidOk()) return `${intro} ${T('bubble.unraid_old', { version: state.unraid.version || '?', needed: state.unraid.needed })}`;
  if (!state.configured) return `${intro} ${T('bubble.setup')}`;
  if (running()) return T('bubble.running');
  if (!othersOff()) return T('bubble.dry');
  if (!state.schedule.enabled) return T('bubble.no_schedule');
  return T('bubble.ready');
}

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
  return Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: headActions(),
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

  // hints, no sections: they stay at every click
  hints().forEach((n) => root.appendChild(n));
  const s = switcher();
  root.appendChild(s.el);
  s.render();
  root.appendChild(footer());
}

/** What stands over the row: Unraid's mover, other movers, Jack Emby, a run going (the night's too), not set up */
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
    out.push(runCard(progress, Date.now() / 1000));
    // under the card: the night's picture — the pool before, the forecast after, where she moves to, files on the way
    const pic = livePicture(nightNow || (nightGoing() ? night().last : null));
    if (pic) out.push(pic);
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
  const short = n.jack ? tonightShort(n.plan) : null;
  if (short) sub.push(short);
  sub.push(`${T('unraid_schedule')}: ${m.off ? T('unraid_off') : T('unraid_on')}`);
  return Office.stat({ label: T('schedule'), value: schedText(sc), sub, tip: sub.join('\n'), alert: !m.off || !sc.enabled, ...a });
}

/**
 * Tonight's plan in words, pure (tests/run.php under node): «Tonight: Jack Emby gives back 70 GB · Ms. Moverelli moves
 * 20 GB of downloads · Jack Emby fetches 20 GB (40 GB wait)» — from the pool as it stands and his last status
 */
function tonightText(p) {
  if (!p || !p.measured) return T('night.unmeasured');
  // what Jack Emby gives back is what was watched — known only once he looked (a dry run's number when there is one)
  const parts = [Number(p.back) > 0 ? T('night.back', { size: fmt.size(p.back) }) : T('night.back_watched'),
    Number(p.goes) > 0 ? T('night.goes', { size: fmt.size(p.goes) }) : T('night.goes_none'),
    Number(p.fill) > 0 ? T('night.fill', { size: fmt.size(p.fill) }) : T('night.fill_none')];
  if (Number(p.wait) > 0) parts.push(T('night.wait', { size: fmt.size(p.wait) }));
  return T('night.tonight', { what: parts.join(' · ') });
}

/** Tonight's plan short, for the tile, pure: «Tonight: 70 GB back · 60 GB wait» — the long line lives in the section */
function tonightShort(p) {
  if (!p || !p.measured) return null;
  const parts = [];
  if (Number(p.back) > 0) parts.push(T('night.tile_back', { size: fmt.size(p.back) }));
  if (Number(p.goes) > 0) parts.push(T('night.tile_goes', { size: fmt.size(p.goes) }));
  if (Number(p.fill) > 0) parts.push(T('night.tile_fill', { size: fmt.size(p.fill) }));
  if (Number(p.wait) > 0) parts.push(T('night.tile_wait', { size: fmt.size(p.wait) }));
  return T('night.tile', { what: parts.length ? parts.join(' · ') : T('night.tile_none') });
}

/** The last night in words, pure: when, how it ended (done, target missed, stopped in a phase, refused), its rounds */
function lastNightText(l) {
  if (!l || !l.started) return '';
  const when = fmt.relative(l.finished || l.started);
  const how = l.result === 'missed' ? T(l.why === 'jack' ? 'night.last_missed_jack' : 'night.last_missed', { pct: l.end_pct ?? '?' })
    : l.result === 'stopped' ? T('night.last_stopped', { phase: T('rc.phase.' + (l.stopped_in || 'free')) })
    : l.result === 'refused' ? T('night.last_refused') : T('night.last_ok');
  return T('night.last', { when, how }) + (Number(l.round) > 1 ? ' · ' + T('night.rounds', { n: Number(l.round) }) : '')
    + (l.mode === 'dry' ? ' · ' + T('mode.dry') : '');
}
/** «Shares»: how many move, the rules for all as its line — alert while Jack Emby's list isn't kept out for a share of his */
function sharesTile(a) {
  const shares = state.shares || [];
  const g = (state.settings || {}).global || {};
  const rule = [T('rule_age', { n: g.min_age ?? 0 }),
    fillText(g.move_when_used_above)].join(' · ');
  const sub = [T('shares_moving', { n: shares.filter((x) => x.state === 'moves').length }), rule];
  return Office.stat({ label: T('shares'), value: `${shares.filter((x) => x.state === 'moves').length} / ${shares.length}`, sub, tip: sub.join('\n'),
    alert: !!(jack().uncovered || []).length, ...a });
}
/** «Last run»: when, which mode and how it went — «running» while one goes */
function lastRunTile(a) {
  const last = lastRun();
  let value = T('last_run_none');
  const sub = [];
  let alert = false;
  if (realRun() || (running() && state.job.mode === 'dry')) {
    value = T('output_running');
    sub.push(T('mode.' + state.job.mode));
  } else if (last && last.finished) {
    value = fmt.relative(last.finished);
    sub.push(`${T('mode.' + last.mode)} · ${T('result.' + (last.result || 'failed'))}`);
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
  // the night run with Jack Emby: tonight's plan (no Emby asked — what waits from his last run), the last night, a dry run
  if (n.jack && state.configured) {
    const plan = Office.place('sched:night', el('div', 'row nocheck'));
    const pm = el('div', 'row-main');
    pm.appendChild(el('div', 'row-name', tonightText(n.plan)));
    const last = lastNightText(n.last);
    pm.appendChild(el('div', 'row-meta', T('night.steps') + (last ? ' · ' + last : '')));
    const dry = button(T('night.dry'), 'small plain', async () => { dry.disabled = true; await act('start_run', { mode: 'dry' }, T('night.dry_started')); });
    dry.disabled = !Office.agent.running || running() || nightGoing();
    plan.append(pm, dry);
    box.appendChild(plan);
  }
  // after the night run: its picture (the pool before and after, where she moved to, the last file)
  const lastPic = n.jack && n.last && !n.last.running ? nightPicture(n.last) : null;
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
  if (lastPic) s.appendChild(lastPic);
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
    meta.appendChild(chip(T('state.' + x.state), STATE_CLASS[x.state] || 'quiet', T('state_tip.' + x.state)));
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
/** The last run: how it went, a line per share that had something */
function resultBox() {
  const last = state.last;
  if (!last || !last.result || !['run', 'dry'].includes(last.mode)) return null;     // a look at the shares' list is no run
  const box = el('div', 'box mo-results');
  const h = (state.history || []).find((r) => !['refused', 'skipped'].includes(r.result));
  box.appendChild(el('div', '', runSummary({ mode: last.mode, status: last, result: last.result, why: h && h.result === last.result ? h.why : null })));
  (last.shares || []).filter((x) => x.files || x.moved || x.left).forEach((x) => {
    box.appendChild(el('div', 'mo-share-line', T(last.mode === 'run' ? 'share_moved' : 'share_would', { share: x.share,
      moved: x.moved || 0, files: nOf('files', x.files), size: fmt.size(x.bytes || 0), left: nOf('left', x.left) })));
  });
  // the fill: where the pool stood and whether the target was reached
  (last.shares || []).filter((x) => x.fill && x.pool_used !== '' && Number(x.pool_used) >= Number(x.threshold)).forEach((x) => {
    const reached = x.reached ? ' — ' + T('rc.fill_reached', { pct: x.pool_now }) : '';
    box.appendChild(el('div', 'mo-share-line', `${x.share}: ${T('rc.fill', { pool: x.pool, used: x.pool_used, until: x.until })}${reached}`));
  });
  return box;
}
/** A run's words for the bar: its mode, result, who started it, the summary */
const runHay = (r) => [T('mode.' + r.mode), T('result.' + (r.result || 'failed')), T('by.' + r.by), runSummary(r)];
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
      el('span', '', fmt.date(r.started)), el('span', '', T('by.' + r.by)));
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
      const j = await Office.api.post(`${ID}.start_run`, { mode });
      if (!j.ok) { showError(err, j.error); return false; }
      if (j.state) state = j.state;
      if (view && page === 'main') render();     // no log dialog: a real run shows its card (as Mr. Backupsy's)
      return true;
    } }],
  });
}

async function startRun(mode) {
  // no dialog (as Mr. Backupsy's and Mr. Restori's runs): a real run shows its card, the others their result under the tiles
  const j = await act('start_run', { mode });
  if (j) render();
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
  (state.shares || []).forEach((x) => {
    const s = set.shares[x.share] || {};
    shares[x.share] = { skip: !!s.skip, min_age: s.min_age ?? null, move_when_used_above: s.move_when_used_above ?? null,
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
  if (!found) {
    const more = el('p', 'role mo-import-link');
    const a = el('a', '', T('import.link'));
    a.href = '#';
    a.onclick = (e) => { e.preventDefault(); importDialog(); };
    more.appendChild(a);
    root.appendChild(more);
  }
  // every change goes into the bar at the bottom (Mr. Backupsy's: Office.selbar) — the form tells it at each keystroke
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
/** The form changed (or was drawn): the bar anew */
function setupChanged() {
  markLimits();
  Office.selbar(setupBarSpec());
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
      Office.selbar(null);
      Office.go(`#/${ID}`);
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
  const out = [T('setup.level_pool', { pool, used: fmt.size(m.used), size: fmt.size(m.size), nm: fmt.size(m.nm) })];
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
      if (!c) { box.appendChild(el('p', 'role', T('setup.level_unseen', { pool }))); return; }
      const v = levelLines(pool, c, g);
      box.appendChild(el('p', 'mo-level-pool', v.lines.join(' ')));
      if (v.warn) box.appendChild(el('p', 'callout warn', v.warn));
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
function fillSetupShares(box) {
  box.innerHTML = '';
  const shares = (state.shares || []).filter((x) => form.shares[x.share]);
  const fit = shares.filter((x) => fitsBar(setupBar, shareHay(x)));
  fit.forEach((x) => {
    const sv = form.shares[x.share];
    const det = keepFold(el('details', 'box mo-share'), x.share, false);
    const sum = el('summary', '', x.share);
    sum.appendChild(chip(x.mode === 'none' ? T('state.none') : T('direction', { from: where(x.from), to: where(x.to) }), 'quiet'));
    if (x.own) sum.appendChild(chip(T('own_rules'), 'quiet'));
    if (x.mode === 'yes') {
      const th = sv.move_when_used_above ?? form.global.move_when_used_above;
      if (Number(th)) sum.appendChild(chip(fillText(th), 'quiet'));
    }
    det.appendChild(sum);
    if (x.mode === 'none') {
      det.appendChild(el('p', 'role', T('state_tip.none')));
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
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : 'main';
    bars();
    if (page === 'setup') {
      // #/moverelli/setup/<tile> (the search, a step's place) picks that step exactly like a click, the address keeps its
      // page; else the step chosen last (moverelli.setup.tile), none = every step
      if (setupSwitcher().mount(String(sub || '').split('/').slice(1).join('/'))) Office.subroute('setup');
      if (!state) await load(false);
      await Office.freshState(ID);      // the form starts from her settings: a fresh look, never a stale one
      if (view !== root || page !== 'setup') return;
      form = null;
      renderSetup();
      return;
    }
    Office.selbar(null);        // the setup's change bar stays on the setup page
    // her tiles as places: #/moverelli/<tile> picks it exactly like a click (the address is her page's again); the Team
    // Lead's «Open» for her schedule (#/moverelli/schedule) picks «Schedule» and opens its dialog as before
    switcher().mount(sub);
    render();
    await load(false);
    if (sub === 'schedule') scheduleDialog();
  },
  unmount() { view = null; clearTimeout(outTimer); clearTimeout(progTimer); progTimer = null; Office.selbar(null); },
  poll() { if (page === 'main') load(false); },
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
Office.places(ID, [
  { kind: 'section', key: 'schedule', route: `#/${ID}/schedule`, shown: configured },
  { kind: 'section', key: 'shares', route: `#/${ID}/shares` },
  { kind: 'section', key: 'history', route: `#/${ID}/runs` },
  ...TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `tile.${id}`, route: `#/${ID}/${id}`, ...(id === 'schedule' ? { shown: configured } : {}) })),
  { kind: 'section', key: 'tools' },
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'step', key, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...SETUP_TILES.map(([id, key]) => ({ kind: 'tile', key, anchor: `setup.tile.${id}`, ...SETUP, route: `#/${ID}/setup/${id}` })),
  ...[['list', 'help.list'], ['mode.dry', 'help.dry'], ['mode.run', 'help.run']].map(([key, text]) => ({ kind: 'help', key, text })),
  ...['what', 'rules', 'unraid', 'jack', 'schedule'].map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
  ...[['setup.global', 'setup.help_global'], ['setup.files', 'setup.help_files'], ['setup.shares', 'setup.help_shares'], ['setup.save', 'setup.help_save'],
    ['import.title', 'import.help']]
    .map(([key, text]) => ({ kind: 'help', key, text, ...SETUP })),
]);

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.moverelli = { setState: (s) => { state = s; }, runSummary, moverNotice, foreignNotices, shareSection, linesOf, filesOf,
    importPreview, importDone, importFileText, scheduleDialog, letGoView, letGoDoneLines, consentSection, runCardData, runCard, headActions, fillText, fillLine, resultBox, setProgress: (p, w) => { progress = p; stopping = w || null; }, setupChangeRows, setupBarSpec, setupProblems, limitsOf, markLimits, formSettings, setupApply, foundNotice, setPage: (p) => { page = p; }, setSaved: (x) => { saved = x; }, bubbleText, segWidths, pictureBars, fileOnto, nightPicture, livePicture, tonightText, tonightShort, lastNightText, nightSteps, setNight: (x) => { nightNow = x; }, schedText, scheduleSection, historySection, footer, hints,
    tileList, switcher, scheduleTile, sharesTile, lastRunTile, setupTileData, setupTileList, SETUP_TILES, TILES, renderSetup,
    setForm: (f) => { form = f; }, initForm, levelMath, levelSection, levelLines, levelNight, jackUncoveredIn, jackNotice, form: () => form, setView: (v, p) => { view = v; page = p || 'main'; }, bars, render };
}
})();
