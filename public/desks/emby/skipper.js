/* Jack the Skipper's pool simulator (2026-10-14, the owner's rules — a mockup: the real night run in agent/lib/nightrun.php,
   agent/lib/poollevel.php, EmbyCache and Smart Mover is NOT changed by this file). A step of his setup wizard (desk.js):
   play the pool's nights through, by hand or in seeded scenarios, and see whether the rules hold — before the real night
   run is changed. Two parts:
     1. the pure math (no DOM, no Office — runs under node for tests/run.php): skipperPoolMath() (→ poolLevelMath() in
        agent/lib/poollevel.php), skipperNight() (→ nightRun()/nightPlan() in agent/lib/nightrun.php and EmbyCache's
        give_back/fill in embycache/embycache_run.py), the invented world (people, series, films, downloads), the
        scenarios, the invariants after every night; the rules of 1.62.1 beside them (v162Math(), the night as built),
        so a scenario shows the difference.
     2. the step's page (Office.skipperSim.view()), loaded by desk.js like Mr. Restori's drill.js.
   Units: GB (floats) throughout the math — the PHP target works in bytes (ints); a size of 1 GB here is 2^30 bytes there.
   Every text of the page and the log is a lang key (sim.*) of his desk; the log keeps {k, p} and the page words it. */
(() => {
'use strict';

// ====================================================================== 1. the pure math
const NIGHT_ROUNDS_MAX = 3;        // nightrun.php NIGHT_ROUNDS_MAX: check → free → recheck at most twice more
const PINGPONG_DAYS = 7;           // a file given back unwatched and fetched again within this many days = ping-pong
const TOL = 0.01;                  // GB: rounding room in the checks

/** mulberry32: the seeded RNG — the same seed, the same month */
function rng(seed) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6D2B79F5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const sum = (a) => a.reduce((s, x) => s + x, 0);
const r1 = (v) => Math.round(v * 10) / 10;

/**
 * The pool's numbers under the new rules (pure). p: {size, nm, jack, others, x, offset, k, want} — GB (or bytes: any one
 * unit), x/offset/k in %; want: what Jack wants (his queue's size), null = as much as he holds.
 *   Y      = X − offset                      the air Y…X stays for the day's writes
 *   A      = max(0, Y·size − NM)             the room the caching shares share (poolLevelMath's R)
 *   H      = A·k/100                         Jack's half (a floor); D_half = A − H the others' half (a floor)
 *   jackLimit   = H + max(0, D_half − D)/2   he may borrow at most HALF of what the others don't use of their half
 *   othersLimit = D_half + max(0, H − u)/2   … and they half of what he doesn't use of his: u = max(J, min(H, want))
 * Neither side can ever occupy everything. need = used − Y·size (what must go so the pool stands at Y).
 * PHP target: poolLevelMath() gets `jack_limit`, `others_limit` beside `half`/`half_d` (EMBYCACHE_KEEP_BYTES = H stays,
 * a new EMBYCACHE_FILL_MAX_BYTES = jackLimit; Smart Mover CUSTOMMOVER_FILL_MAX_BYTES = max(0, D − othersLimit)).
 */
function skipperPoolMath(p) {
  const size = Math.max(0, +p.size || 0);
  const nm = Math.max(0, +p.nm || 0);
  const jack = Math.max(0, +p.jack || 0);
  const others = Math.max(0, +p.others || 0);
  const x = Math.max(0, Math.min(100, +p.x || 0));
  const y = Math.max(0, x - Math.max(0, +p.offset || 0));
  const k = Math.max(0, Math.min(100, p.k == null ? 50 : +p.k));
  const yb = y * size / 100;
  const xb = x * size / 100;
  const used = nm + jack + others;
  const room = Math.max(0, yb - nm);
  const half = room * k / 100;
  const halfD = room - half;
  const use = Math.max(jack, Math.min(half, p.want == null ? jack : Math.max(0, +p.want)));
  const jackLimit = half + Math.max(0, halfD - others) / 2;
  const othersLimit = halfD + Math.max(0, half - use) / 2;
  return { size, nm, jack, others, used, x, y, k, yb, xb, air: xb - yb, room, half, halfD, jackLimit, othersLimit,
    need: Math.max(0, used - yb), over: used >= xb && xb > 0, never: size > 0 && nm >= yb };
}

/** 1.62.1's numbers, 1:1 poolLevelMath() (agent/lib/poollevel.php) — for the comparison only */
function v162Math(p) {
  const size = Math.max(0, +p.size || 0);
  const nm = Math.max(0, +p.nm || 0);
  const jack = Math.max(0, +p.jack || 0);
  const m = jack + Math.max(0, +p.others || 0);
  const used = nm + m;
  const x = +p.x || 0;
  const y = Math.max(0, x - Math.max(0, +p.offset || 0));
  const yb = y * size / 100;
  const room = y > 0 ? Math.max(0, yb - nm) : 0;
  const half = room * Math.max(0, Math.min(100, p.k == null ? 50 : +p.k)) / 100;
  const need = y > 0 ? Math.max(0, used - yb) : 0;
  const back = y > 0 && used > yb ? Math.min(Math.max(0, jack - half), need) : 0;
  const goes = x > 0 && y > 0 && used * 100 >= x * size ? Math.min(Math.max(0, m - jack - (room - half)), need - back) : 0;
  return { size, nm, used, yb, xb: x * size / 100, room, half, halfD: room - half, need, back, goes, never: size > 0 && nm >= yb };
}

// ---------------------------------------------------------------------- the world (invented: no real titles, ever)
const PEOPLE = ['Anna', 'Ben', 'Carla', 'Dario', 'Elif', 'Finn', 'Greta', 'Hugo'];
const SERIES = ['Orion', 'Lumen', 'Atlas', 'Kestrel', 'Juniper', 'Delta', 'Nimbus', 'Sable', 'Quartz', 'Vela', 'Tundra', 'Ember',
  'Harbor', 'Mosaic', 'Pilot', 'Riven'];

/** The settings a simulation starts from (GB, %, days); the sliders change them, a scenario sets some */
const DEFAULTS = {
  pool: 1024, nm: 150, nmGrow: 0, writes: 60, watch: 4, newEps: 3, people: 3, x: 80, offset: 10, k: 50, days: 30, age: 3,
  episodes: 10, films: 0.1, filmMin: 30, filmMax: 60, epMin: 2, epMax: 10, nightWrites: 0,
};

/** A new world: people with three series each (some started earlier), a film or two; nothing on the pool yet */
function newWorld(cfg, seed) {
  const c = { ...DEFAULTS, ...cfg };
  const w = { cfg: c, seed: seed >>> 0, rnd: rng(seed), day: 0, nm: c.nm, people: [], series: [], eps: [], mods: [], nextId: 1 };
  const n = Math.max(1, Math.min(PEOPLE.length, Math.round(c.people)));
  for (let u = 0; u < n; u++) {
    w.people.push({ name: PEOPLE[u] });
    for (let s = 0; s < 3; s++) addSeries(w, u, -Math.floor(w.rnd() * 10), 4 + Math.floor(w.rnd() * 6));
    if (w.rnd() < c.films * 3) addFilm(w, u, -Math.floor(w.rnd() * 5));
  }
  return w;
}
function addSeries(w, uid, day, count) {
  const s = { id: w.series.length, uid, film: false, title: SERIES[w.series.length % SERIES.length] + (w.series.length >= SERIES.length ? ' ' + (1 + Math.floor(w.series.length / SERIES.length)) : ''),
    lastSeen: day, abandoned: false, n: 0 };
  w.series.push(s);
  for (let i = 0; i < count; i++) addEp(w, s, day);
  return s;
}
function addFilm(w, uid, day) {
  const s = { id: w.series.length, uid, film: true, title: '', lastSeen: day, abandoned: false, n: 0 };
  s.title = `${w.people[uid].name} · ${s.id + 1}`;
  w.series.push(s);
  addEp(w, s, day);
}
function addEp(w, s, day) {
  const c = w.cfg;
  const size = s.film ? c.filmMin + w.rnd() * (c.filmMax - c.filmMin) : c.epMin + w.rnd() * (c.epMax - c.epMin);
  w.eps.push({ id: w.nextId++, sid: s.id, uid: s.uid, idx: s.n++, size: r1(size), watched: false, added: day });
}

/**
 * Real data: Jack the Skipper already holds `gb` on the pool — the invented world grows (a new series per person in turn,
 * watched lately, unwatched episodes) until what he wants is at least that much, so day 0 can hold it
 */
function growFor(w, gb) {
  if (!(gb > 0)) return;
  const want = () => sum(wanted(w, { rules: 'new' }).map((e) => e.size));
  for (let u = 0; want() < gb * 1.05 && w.series.length < 2000; u = (u + 1) % w.people.length) addSeries(w, u, -Math.floor(w.rnd() * 10), w.cfg.episodes + 2);
}

/** A rule set's own side of the world: what lies on the pool, the downloads, what it gave back, its totals and log */
function newSide(rules, w) {
  // the downloads already there: the server's as three blocks of the last days (real data), else two days' writes
  const o = w.cfg.others0;
  const dl = o != null ? [3, 2, 1].map((d) => ({ day: -d, size: r1(o / 3) })).filter((d) => d.size > 0)
    : [{ day: -2, size: w.cfg.writes }, { day: -1, size: w.cfg.writes }];
  return { rules, pool: new Set(), dl, back: new Map(),
    up: 0, down: 0, moved: 0, spilled: 0, pingpong: 0, revived: 0, short: 0, warns: 0, warnNow: false, told: 0, log: [], hist: [], inv: invNew(), fetchedTonight: 0, forced: 0 };
}

const stale = (w, s) => w.day - s.lastSeen >= w.cfg.unwatchedDays;
/** The series' next unwatched episode's index */
const nextIdx = (w, sid) => { const e = w.eps.find((x) => x.sid === sid && !x.watched); return e ? e.idx : Infinity; };

/**
 * What Jack wants (his queue, EmbyCache's on-deck list): per person, of each series the next `episodes` unwatched ones,
 * of each film the film. New rules: a series nobody watched for N days is no longer wanted (it may go back). 1.62.1: all.
 */
function wanted(w, side) {
  const c = w.cfg;
  const out = [];
  for (const s of w.series) {
    if (side.rules === 'new' && stale(w, s)) continue;
    const list = w.eps.filter((e) => e.sid === s.id && !e.watched).sort((a, b) => a.idx - b.idx).slice(0, s.film ? 1 : c.episodes);
    out.push(...list);
  }
  return out;
}

/** How far ahead a file is (0 = the next one to watch) */
const rank = (w, e) => e.idx - nextIdx(w, e.sid);

function measure(w, side) {
  const onPool = w.eps.filter((e) => side.pool.has(e.id));
  return { jack: sum(onPool.map((e) => e.size)), watched: sum(onPool.filter((e) => e.watched).map((e) => e.size)),
    others: sum(side.dl.map((d) => d.size)), nm: w.nm, files: onPool };
}

/** The numbers of a side now (the rule set's own math; `want` = his queue's size) */
function mathOf(w, side) {
  const m = measure(w, side);
  const want = sum(wanted(w, side).map((e) => e.size));
  const c = w.cfg;
  const p = { size: c.pool, nm: m.nm, jack: m.jack, others: m.others, x: c.x, offset: c.offset, k: c.k, want };
  return { ...skipperPoolMath(p), want, watched: m.watched, v162: side.rules === 'v162' ? v162Math(p) : null };
}

// ---------------------------------------------------------------------- the day
/** One day's draws, once for both rule sets (the same world): watching, new episodes, writes, appdata */
function dayDraws(w) {
  const c = w.cfg;
  const mod = (k) => w.mods.filter((m) => m.from <= w.day && w.day <= m.to).reduce((v, m) => (m[k] == null ? v : v * m[k]), 1);
  const d = { watched: [], fresh: [], writes: 0, nightWrites: 0 };
  // writes to the other caching shares (downloads …): ±30 % around the setting, times the day's events
  d.writes = r1(Math.max(0, c.writes * (0.7 + w.rnd() * 0.6) * mod('writes')));
  d.nightWrites = r1(c.nightWrites * mod('nightWrites'));
  w.nm = Math.max(0, w.nm + c.nmGrow * mod('nmGrow'));
  for (const m of w.mods) if (m.from === w.day && m.nm) w.nm += m.nm;
  // watching: a person, one of their series (the one watched last most often), its next episode
  const views = Math.round(c.watch * mod('watch') * (0.6 + w.rnd() * 0.8));
  for (let i = 0; i < views; i++) {
    const u = Math.floor(w.rnd() * w.people.length);
    const mine = w.series.filter((s) => s.uid === u && !s.abandoned && w.eps.some((e) => e.sid === s.id && !e.watched))
      .sort((a, b) => b.lastSeen - a.lastSeen || a.id - b.id);
    if (!mine.length) continue;
    const s = w.rnd() < 0.7 ? mine[0] : mine[Math.floor(w.rnd() * mine.length)];
    const e = w.eps.filter((x) => x.sid === s.id && !x.watched).sort((a, b) => a.idx - b.idx)[0];
    e.watched = true;
    e.watchedDay = w.day;
    s.lastSeen = w.day;
    d.watched.push(e.id);
  }
  // new episodes (on the array); now and then a new film for someone
  const news = Math.round(c.newEps * (0.5 + w.rnd()));
  for (let i = 0; i < news; i++) {
    const open = w.series.filter((s) => !s.film);
    if (!open.length) break;
    if (w.rnd() < c.films) {
      addFilm(w, Math.floor(w.rnd() * w.people.length), w.day);
    } else {
      const s = open[Math.floor(w.rnd() * open.length)];
      addEp(w, s, w.day);
    }
    d.fresh.push(w.eps[w.eps.length - 1].id);
  }
  return d;
}

/** A day's writes land on the pool until it is full; the rest goes to the array (Unraid's «minimum free space») — a red signal */
function writeDay(w, side, size, L) {
  const m = measure(w, side);
  const free = Math.max(0, w.cfg.pool - m.nm - m.jack - m.others);
  const got = Math.min(size, free);
  if (got > 0) side.dl.push({ day: w.day, size: r1(got) });
  const spilled = r1(size - got);
  if (spilled > TOL) { side.spilled += spilled; L('spill', { size: spilled }, 'bad'); }
  return spilled;
}

// ---------------------------------------------------------------------- the night
/** Give a file back to the array (whole files): the reason, ping-pong bookkeeping, the third invariant */
function giveBack(w, side, e, why, L) {
  side.pool.delete(e.id);
  side.down += e.size;
  if (!e.watched) side.back.set(e.id, { day: w.day, seen: w.series[e.sid].lastSeen });
  const s = w.series[e.sid];
  if (!e.watched && !stale(w, s)) side.forced++;                      // unwatched, and its series watched lately: invariant 3
  L(why, { title: title(w, e), size: e.size, ahead: rank(w, e) }, 'back');
}
function title(w, e) {
  const s = w.series[e.sid];
  return s.film ? s.title : `${s.title} · ${e.idx + 1} (${w.people[e.uid].name})`;
}
/** Her part: the age rule, then the oldest of the others down by `amount` (also out of their own half) */
function herAge(w, side, L) {
  const old = side.dl.filter((d) => w.day - d.day >= w.cfg.age);
  const moved = r1(sum(old.map((d) => d.size)));
  side.dl = side.dl.filter((d) => w.day - d.day < w.cfg.age);
  if (moved > 0) { side.moved += moved; L('age', { size: moved, days: w.cfg.age }, 'mov'); }
  return moved;
}
function herOldest(w, side, amount, why, L) {
  let left = amount;
  let moved = 0;
  side.dl.sort((a, b) => a.day - b.day);
  for (const d of side.dl) {
    if (left <= TOL) break;
    const t = Math.min(d.size, left);
    d.size = r1(d.size - t);
    left -= t;
    moved += t;
  }
  side.dl = side.dl.filter((d) => d.size > TOL);
  moved = r1(moved);
  if (moved > 0) { side.moved += moved; L(why, { size: moved }, 'mov'); }
  return moved;
}

/**
 * Jack fills last: round-robin per person, the next episode first; a person stops at the first file that doesn't fit
 * (never episode 5 without 4). cap(e, m) → '' (fits) | why. Returns what came up.
 */
function fill(w, side, cap, L) {
  const queue = wanted(w, side).filter((e) => !side.pool.has(e.id));
  const per = w.people.map((_, u) => queue.filter((e) => e.uid === u)
    .sort((a, b) => rank(w, a) - rank(w, b) || w.series[b.sid].lastSeen - w.series[a.sid].lastSeen || a.idx - b.idx));
  const stopped = new Set();
  let up = 0;
  for (let turn = 0, progress = true; progress; turn++) {
    progress = false;
    for (let i = 0; i < per.length; i++) {
      const u = (i + w.day) % per.length;
      if (stopped.has(u) || !per[u].length) continue;
      const e = per[u][0];
      const why = cap(e, measure(w, side));
      if (why) { stopped.add(u); L(why, { title: title(w, e), size: e.size }, 'skip'); continue; }
      per[u].shift();
      side.pool.add(e.id);
      side.up += e.size;
      up += e.size;
      // fetched again within 7 days of going back: ping-pong — unless someone watched its series again since (it came back)
      const was = side.back.get(e.id);
      if (was && w.day - was.day <= PINGPONG_DAYS) {
        if (w.series[e.sid].lastSeen > was.seen) { side.revived++; L('revived', { title: title(w, e), day: was.day }, 'skip'); }
        else { side.pingpong++; L('pingpong', { title: title(w, e), day: was.day }, 'bad'); }
      }
      side.back.delete(e.id);
      L('fetch', { title: title(w, e), size: e.size }, 'fill');
      progress = true;
    }
  }
  return r1(up);
}

/**
 * The night under the new rules (2026-10-13/14) — one joint run: check → reckon → free (who frees more first) →
 * recheck (≤ 3 rounds) → Jack fills last → tell. Jack gives back what was watched (always, first), unwatched never —
 * except a series or film nobody watched for N days (it is off his list then, like a watched file: back at the next night,
 * the farthest ahead first); while over his limit he fetches nothing. She: the age rule always; from X on the others'
 * oldest go — what they borrowed first, out of their own half too when needed, down to Y (below X nothing of theirs moves).
 * PHP target: nightPlan()/nightRun() in agent/lib/nightrun.php; Jack's part EmbyCache's give_back (EMBYCACHE_KEEP_BYTES,
 * a new EMBYCACHE_STALE_DAYS) and fill (a new EMBYCACHE_FILL_MAX_BYTES), hers Smart Mover's fill (CUSTOMMOVER_FILL_MAX_BYTES).
 */
function skipperNight(w, side, L) {
  const c = w.cfg;
  side.fetchedTonight = 0;
  for (let round = 1; round <= NIGHT_ROUNDS_MAX; round++) {
    // check
    const m = mathOf(w, side);
    if (round === 1 && m.never) L('never', { nm: m.nm }, 'bad');
    if (round === 1) side.overTonight = m.over;
    if (round > 1) {
      if (m.need <= Math.max(c.pool / 100, 1) && m.jack <= m.jackLimit + TOL && m.others <= m.othersLimit + TOL) break;
      L('round', { round, size: m.need }, 'skip');
    }
    // reckon: his list (watched and stale ones, always), her amount
    const files = measure(w, side).files;
    const watched = files.filter((e) => e.watched);
    let jack = m.jack - sum(watched.map((e) => e.size));
    const staleList = files.filter((e) => !e.watched && stale(w, w.series[e.sid]))
      .sort((a, b) => rank(w, b) - rank(w, a) || a.idx - b.idx);
    // a stale series/film is off his list (as a watched file is): back at the next night, the farthest ahead first
    const backStale = staleList;
    jack -= sum(backStale.map((e) => e.size));
    const aged = sum(side.dl.filter((d) => w.day - d.day >= c.age).map((d) => d.size));
    const after = skipperPoolMath({ size: c.pool, nm: m.nm, jack, others: m.others - aged, x: c.x, offset: c.offset, k: c.k, want: m.want });
    // hers only from X (as 1.62.1, the owner 2026-10-14): what they borrowed over their limit first, then — the pool still
    // over Y — out of their own half too; below X they stay (Jack the Skipper is held back by his fill limit instead)
    let goes = 0;
    if (m.over) {
      goes = Math.max(0, after.others - after.othersLimit);
      if (after.used - goes > after.yb) goes = Math.min(after.others, after.used - after.yb);
    }
    const jackFree = sum(watched.map((e) => e.size)) + sum(backStale.map((e) => e.size));
    if (round === 1) L('plan', { back: r1(jackFree), goes: r1(goes), age: r1(aged), first: jackFree >= goes + aged ? 'jack' : 'her' }, 'skip');
    // free: who frees more first
    const his = () => { watched.forEach((e) => giveBack(w, side, e, 'back_watched', L)); backStale.forEach((e) => giveBack(w, side, e, 'back_stale', L)); };
    const hers = () => { herAge(w, side, L); if (goes > TOL) herOldest(w, side, goes, m.over ? 'goes_x' : 'goes_over', L); };
    if (jackFree >= goes + aged) { his(); hers(); } else { hers(); his(); }
    if (round === 1 && c.nightWrites > 0 && w.nightWrites > 0) { L('night_writes', { size: w.nightWrites }, 'skip'); writeDay(w, side, w.nightWrites, L); }
    if (jackFree + goes + aged <= TOL && round > 1) break;
  }
  // fill: nothing while over his limit; else up to his limit and never over Y
  const m = mathOf(w, side);
  if (m.jack > m.jackLimit + TOL) {
    L('fill_over', { held: r1(m.jack), limit: r1(m.jackLimit) }, 'hold');
  } else {
    side.fetchedTonight = fill(w, side, (e, now) => {
      if (now.jack + e.size > m.jackLimit + TOL) return 'skip_limit';
      if (now.nm + now.jack + now.others + e.size > m.yb + TOL) return 'skip_y';
      return '';
    }, L);
  }
  tell(w, side, L);
}

/** The night as 1.62.1 runs it (nightrun.php + poolLevelMath(), the mockup's V1 without its «only free» switch) */
function v162Night(w, side, L) {
  const c = w.cfg;
  side.fetchedTonight = 0;
  // EmbyCache's cleanup: what was watched goes back first (the mockup measured after it, as here)
  measure(w, side).files.filter((e) => e.watched).forEach((e) => giveBack(w, side, e, 'back_watched', L));
  for (let round = 1; round <= NIGHT_ROUNDS_MAX; round++) {
    const m = mathOf(w, side);
    const a = m.v162;
    const aged = sum(side.dl.filter((d) => w.day - d.day >= c.age).map((d) => d.size));
    if (round > 1 && (a.need <= Math.max(c.pool / 100, 1) || a.back + a.goes === 0)) break;
    if (round === 1) side.overTonight = m.over;
    if (round === 1) L('plan', { back: r1(a.back), goes: r1(a.goes), age: r1(aged), first: a.back >= a.goes + aged ? 'jack' : 'her' }, 'skip');
    else L('round', { round, size: r1(a.need) }, 'skip');
    const his = () => {
      // give back above Y, the farthest ahead first, never below H — unwatched too (that is what changes)
      let freed = 0;
      let jack = m.jack;
      const list = measure(w, side).files.filter((e) => !e.watched).sort((x, y) => rank(w, y) - rank(w, x) || x.idx - y.idx);
      for (const e of list) {
        if (freed >= a.back - TOL) break;
        if (jack - e.size < a.half - TOL) continue;
        giveBack(w, side, e, 'back_borrowed', L);
        freed += e.size;
        jack -= e.size;
      }
    };
    const hers = () => { herAge(w, side, L); if (a.goes > TOL) herOldest(w, side, a.goes, 'goes_x', L); };
    if (a.back >= a.goes + aged) { his(); hers(); } else { hers(); his(); }
    if (round === 1 && c.nightWrites > 0 && w.nightWrites > 0) { L('night_writes', { size: w.nightWrites }, 'skip'); writeDay(w, side, w.nightWrites, L); }
  }
  const yb = mathOf(w, side).yb;
  side.fetchedTonight = fill(w, side, (e, now) => (now.nm + now.jack + now.others + e.size > yb + TOL ? 'skip_y' : ''), L);
  tell(w, side, L);
}

/**
 * The end of the night: still at/over X → «told at once»; and the warning (the owner, 2026-10-14 — a message, not a rule)
 * when the cache for new writes is practically gone because Jack the Skipper can't give back fast enough (appdata growing
 * fast): the room left up to X (their free room under Y plus the air) is less than one day of writes while he holds more
 * than his fill limit, or the others no longer have what they are promised (half of their own half plus the air, what they hold and the room up to X together).
 * Target in the office: nightrun.php's end, beside nightMissedTell() — officeNotify('warning', …) with a new
 * moverelli `notify.night_tight` (×5), its stamp shared as every sender's; a Team Lead finding is not needed for a one-off.
 */
function tell(w, side, L) {
  const m = mathOf(w, side);
  if (m.used > m.xb + TOL) { side.told++; L('told', { pct: r1(m.used * 100 / w.cfg.pool), x: w.cfg.x }, 'bad'); }
  const left = Math.max(0, m.xb - m.used);
  const promised = m.halfD / 2 + m.air;
  // the day's writes don't fit up to X while he holds more than his limit (it is him who can't give back — her X trigger
  // alone leaves the pool between Y and X on purpose: the air belongs to the day), or the others' promise is broken
  side.warnNow = !m.never && ((left < w.cfg.writes - TOL && m.jack > m.jackLimit + TOL) || m.others + left < promised - TOL);
  if (side.warnNow) { side.warns++; L('warn', { room: r1(left), writes: w.cfg.writes, promised: r1(promised) }, 'bad'); }
}

// ---------------------------------------------------------------------- the invariants (after every night)
const INVARIANTS = ['jack', 'others', 'unwatched', 'pingpong', 'limit'];
function invNew() { return Object.fromEntries(INVARIANTS.map((k) => [k, { ok: true, first: null, fails: 0 }])); }

/**
 * The five checks of a night, against the NEW rules' numbers (for 1.62.1 too — that is the comparison):
 *   jack       he holds ≥ min(H, what he wants) (less one file: whole files) — unless held back while the others hold
 *              more than their half (she moves theirs only from X on) — and never more than his fill limit after the night; over it only with files he
 *              may not give back (unwatched, watched lately) and nothing fetched
 *   others     the others always keep at least half of their own half plus the air Y…X (what they hold and what is free
 *              up to X together — «nobody takes everything») — or the night warned (tell()) —, and nothing of the day's
 *              writes had to go to the array
 *   unwatched  nothing unwatched went back unless its series/film was unwatched ≥ N days
 *   pingpong   no file given back and fetched again within 7 days
 *   limit      the pool ≤ X after the night — else «told at once» was logged; red only when Jack's part over H made it
 */
function invariants(w, side, before) {
  const m = mathOf(w, side);
  const files = measure(w, side).files;
  const want = wanted(w, { rules: 'new' });
  const wantTotal = sum(want.map((e) => e.size));
  const heldWanted = sum(want.filter((e) => side.pool.has(e.id)).map((e) => e.size));
  const biggest = Math.max(0, ...want.filter((e) => !side.pool.has(e.id)).map((e) => e.size));
  const overOk = side.fetchedTonight <= TOL && files.every((e) => !e.watched && !stale(w, w.series[e.sid]));
  const r = {};
  // he is simply held back while the others hold more than their half (the owner, 2026-10-14: she moves only from X on;
  // writes during the night can leave them over it after her move too) — his half is owed once she has moved theirs
  const heldBack = m.others > m.halfD + TOL;
  r.jack = (heldWanted >= Math.min(m.half, wantTotal) - biggest - TOL || heldBack) && (m.jack <= m.jackLimit + TOL || overOk);
  // what is promised (the owner, 2026-10-14): they always keep at least half of their own half, plus the air Y…X — what
  // they hold and what is free up to X together
  const owed = m.halfD / 2 + m.air;
  const theirs = m.others + Math.max(0, m.xb - m.used);
  if (!m.never) side.short = Math.max(side.short, r1(owed - theirs));
  r.others = m.never || ((theirs >= owed - TOL || side.warnNow) && side.spilled <= before.spilled + TOL);
  r.unwatched = side.forced === before.forced;
  r.pingpong = side.pingpong === before.pingpong;
  r.limit = m.used <= m.xb + TOL || (side.told > before.told && m.nm + m.others + Math.min(m.jack, m.half) > m.xb - TOL);
  for (const k of INVARIANTS) {
    if (r[k]) continue;
    const s = side.inv[k];
    s.ok = false;
    s.fails++;
    if (s.first == null) s.first = w.day;
  }
  return r;
}

// ---------------------------------------------------------------------- a simulation (both rule sets on one world)
/** A simulation: the world and the two rule sets' sides; cfg as DEFAULTS (+ unwatchedDays) */
function newSim(cfg, seed) {
  const w = newWorld({ unwatchedDays: 30, ...cfg }, seed == null ? 162 : seed);
  w.nightWrites = 0;
  growFor(w, w.cfg.jack0 || 0);
  const sim = { w, sides: { new: newSide('new', w), v162: newSide('v162', w) } };
  for (const side of Object.values(sim.sides)) {      // the first night fills him (day 0)
    // what he holds already (real data): his queue's files up to that size, a person at a time in turn — day 0 holds
    // the measured amount (within one file)
    if (w.cfg.jack0 > 0) {
      const per = w.people.map((_, u) => wanted(w, side).filter((e) => e.uid === u).sort((x, y) => rank(w, x) - rank(w, y) || x.idx - y.idx));
      let held = 0;
      for (let turn = 0; held < w.cfg.jack0 && per.some((l) => l.length); turn++) {
        const l = per[turn % per.length];
        if (!l.length) continue;
        const e = l.shift();
        side.pool.add(e.id);
        held += e.size;
      }
    }
    // own test values: the first night fills him; the server's: day 0 is the pool as measured (no night before it)
    if (!(w.cfg.jack0 > 0)) (side.rules === 'new' ? skipperNight : v162Night)(w, side, logger(w, side));
    record(w, side, { spilled: 0, forced: 0, pingpong: 0, told: 0 }, false);
  }
  return sim;
}
function logger(w, side) {
  return (k, p, c) => { side.log.push({ d: w.day, k, p: p || {}, c: c || '' }); if (side.log.length > 1500) side.log.splice(0, side.log.length - 1500); };
}
function record(w, side, before, check) {
  const m = mathOf(w, side);
  const r = check ? invariants(w, side, before) : null;
  side.hist.push({ day: w.day, used: r1(m.used), pct: r1(m.used * 100 / w.cfg.pool), jack: r1(m.jack), others: r1(m.others), nm: r1(m.nm),
    up: r1(side.up - before.up), down: r1(side.down - before.down), moved: r1(side.moved - before.moved), inv: r, warn: !!side.warnNow });
  if (side.hist.length > 400) side.hist.shift();
}

/** One day for both: the day's draws, the writes, the night, the checks */
function simDay(sim) {
  const w = sim.w;
  w.day++;
  const d = dayDraws(w);
  w.nightWrites = d.nightWrites;
  for (const side of Object.values(sim.sides)) {
    const L = logger(w, side);
    const before = { spilled: side.spilled, forced: side.forced, pingpong: side.pingpong, told: side.told, up: side.up, down: side.down, moved: side.moved };
    L('day', { day: w.day, writes: d.writes, watched: d.watched.length, fresh: d.fresh.length }, 'day');
    writeDay(w, side, d.writes, L);
    (side.rules === 'new' ? skipperNight : v162Night)(w, side, L);
    record(w, side, before, true);
  }
  return d;
}
/** Days in a row; `untilRed`: stop at the first red check of the new rules (or after `n`) */
function simDays(sim, n, untilRed) {
  for (let i = 0; i < n; i++) {
    simDay(sim);
    if (untilRed && !INVARIANTS.every((k) => sim.sides.new.inv[k].ok)) break;
  }
  return sim;
}

/** The manual events (a day's modifiers from today on) */
const EVENTS = {
  spike: (w) => w.mods.push({ from: w.day + 1, to: w.day + 3, writes: 3, nightWrites: 1 }),
  appdata: (w) => w.mods.push({ from: w.day + 1, to: w.day + 1, nm: 50 }),
  binge: (w) => w.mods.push({ from: w.day + 1, to: w.day + 7, watch: 4 }),
  nobody: (w) => w.mods.push({ from: w.day + 1, to: w.day + 14, watch: 0 }),
  abandon: (w) => {
    const open = w.series.filter((s) => !s.film && !s.abandoned && w.eps.some((e) => e.sid === s.id && !e.watched));
    if (!open.length) return null;
    const s = open[Math.floor(w.rnd() * open.length)];
    s.abandoned = true;
    return s.title;
  },
};
function simEvent(sim, id) {
  const got = EVENTS[id] ? EVENTS[id](sim.w) : null;
  for (const side of Object.values(sim.sides)) logger(sim.w, side)('event', { what: id, title: got || '' }, 'day');
  return got;
}

/** The automatic scenarios: each a setting and its events, seeded; run `days` and report */
const SCENARIOS = [
  { id: 'normal', days: 30, cfg: {} },
  { id: 'spike', days: 30, cfg: { nightWrites: 30 }, mods: [{ from: 8, to: 10, writes: 3 }, { from: 1, to: 7, nightWrites: 0 }, { from: 11, to: 99, nightWrites: 0 }] },
  { id: 'nobody', days: 60, cfg: {}, mods: [{ from: 3, to: 47, watch: 0 }] },
  { id: 'binge', days: 21, cfg: {}, mods: [{ from: 7, to: 13, watch: 4 }] },
  { id: 'appdata', days: 40, cfg: (c) => ({ nmGrow: ((c.x - c.offset) * c.pool / 100 - 20 - c.nm) / 35 }), mods: [{ from: 36, to: 99, nmGrow: 0 }] },
  { id: 'films', days: 30, cfg: { films: 0.6, filmMin: 40, filmMax: 80, watch: 3 } },
  { id: 'tiny', days: 30, cfg: { pool: 256, nm: 60, writes: 20, epMin: 1, epMax: 4, filmMin: 8, filmMax: 15 } },
];
/** One scenario on a base setting (the sliders') with a seed: the simulation after its days */
function runScenario(id, base, seed) {
  const sc = SCENARIOS.find((s) => s.id === id);
  const b = { ...DEFAULTS, unwatchedDays: 30, ...base };
  const own = typeof sc.cfg === 'function' ? sc.cfg(b) : sc.cfg;
  const cfg = { ...b, ...own };
  // a scenario with a pool or NM of its own starts empty: the server's contents (real data) belong to the server's pool
  if ('pool' in own || 'nm' in own) { delete cfg.others0; delete cfg.jack0; }
  const sim = newSim(cfg, (seed == null ? 162 : seed) + SCENARIOS.indexOf(sc) * 1000);
  sim.w.mods.push(...(sc.mods || []).map((m) => ({ ...m })));
  sim.scenario = id;
  return simDays(sim, sc.days, false);
}
/** A scenario's result per rule set: each check green or the first red day, ping-pong, moved up/down, spilled, told */
function summary(sim) {
  const out = {};
  for (const [k, side] of Object.entries(sim.sides)) {
    out[k] = { inv: Object.fromEntries(INVARIANTS.map((i) => [i, side.inv[i].ok ? null : side.inv[i].first])), ok: INVARIANTS.every((i) => side.inv[i].ok),
      pingpong: side.pingpong, revived: side.revived, warns: side.warns, short: Math.max(0, side.short), up: r1(side.up), down: r1(side.down), moved: r1(side.moved), spilled: r1(side.spilled), told: side.told,
      forced: side.forced };
  }
  return out;
}

const math = { skipperPoolMath, v162Math, newSim, simDay, simDays, simEvent, runScenario, summary, mathOf, measure, wanted, DEFAULTS, SCENARIOS,
  INVARIANTS, EVENTS: Object.keys(EVENTS), rng, PINGPONG_DAYS };
if (typeof module !== 'undefined' && module.exports) module.exports = math;       // tests/run.php under node
if (typeof Office === 'undefined') return;

// ====================================================================== 2. the step's page
/*
 * Office.skipperSim.view(h) → the simulator's section for his wizard (desk.js setupSimulator()). h: {T, el, fmt, nOf,
 * button, section, base() (the settings to start from: his form's N days, Ms. Moverelli's X/offset/k/age, his pool as
 * measured — or null), measure() (the explicit «Measure now»: the agent measures the pool now, may wake it)}. The
 * simulation lives here across the wizard's drawings (V); a control redraws only the simulator's body, in place.
 */
const GIB = 1024 * 1024 * 1024;
const V = { src: null, cfg: null, seed: 162, sim: null, rules: 'new', results: null, shown: '', measured: null, busy: false };
const SLIDERS = [         // [key, min, max, step, unit]  — unit: gb | pct | n | days
  ['pool', 128, 4096, 32, 'gb'], ['nm', 0, 2048, 10, 'gb'], ['writes', 0, 400, 5, 'gb'], ['watch', 0, 20, 1, 'n'],
  ['newEps', 0, 20, 1, 'n'], ['people', 1, 8, 1, 'n'], ['x', 20, 95, 1, 'pct'], ['offset', 0, 30, 1, 'pct'], ['k', 0, 100, 5, 'pct'],
  ['unwatchedDays', 1, 120, 1, 'days'], ['age', 0, 30, 1, 'days'],
];

/** The settings from a source: the real pool (GB) and the rules, or the sliders' own; null when the real one isn't measured */
function baseCfg(h, src) {
  const b = h.base() || {};
  const cfg = { ...DEFAULTS, unwatchedDays: b.days || 30 };
  if (b.x > 0) Object.assign(cfg, { x: b.x, offset: Math.max(0, b.x - b.y), k: b.k == null ? 50 : b.k });
  if (b.age != null) cfg.age = b.age;
  const lv = V.measured || b.level;
  if (src === 'real') {
    if (!lv) return null;
    Object.assign(cfg, { pool: r1(lv.size / GIB), nm: r1(lv.nm / GIB), others0: r1(lv.others / GIB), jack0: r1(lv.jack / GIB) });
    if (lv.x > 0) Object.assign(cfg, { x: lv.x, offset: Math.max(0, lv.x - lv.y), k: lv.k });
  }
  return cfg;
}
function restart(h) {
  V.sim = newSim(V.cfg, V.seed);
  V.shown = '';
}

function view(h) {
  const { T, el } = h;
  const sec = h.section(T('sim.title'), T('sim.sub'), { place: 'sim.title' });
  sec.classList.add('jo-sim');
  const body = el('div', 'jo-sim-body');
  sec.appendChild(body);
  if (V.src === null) V.src = h.base() && h.base().level ? 'real' : 'test';
  if (!V.cfg) V.cfg = baseCfg(h, V.src) || baseCfg(h, 'test');
  if (!V.sim) restart(h);
  draw(h, body);
  return sec;
}

/** The simulator's body anew (its own controls redraw only this, kept in place) */
function redraw(h, body) {
  Office.keepInPlace(body, () => draw(h, body));
}

function draw(h, body) {
  const { T, el, fmt } = h;
  const size = (gb) => fmt.size(Math.max(0, gb) * GIB);
  body.replaceChildren();
  // ---- the data: the server's or one's own
  const src = el('div', 'jo-sim-src');
  const radio = (value, text) => {
    const l = el('label', 'check');
    const i = el('input');
    i.type = 'radio';
    i.name = 'jo-sim-src';
    i.checked = V.src === value;
    i.onchange = () => { V.src = value; V.cfg = baseCfg(h, value) || baseCfg(h, 'test'); restart(h); redraw(h, body); };
    l.append(i, el('span', '', text));
    return l;
  };
  src.append(el('span', 'jo-sim-label', T('sim.source')), radio('real', T('sim.src_real')), radio('test', T('sim.src_test')));
  body.appendChild(src);
  const lv = V.measured || (h.base() || {}).level;
  const facts = el('div', 'jo-sim-facts');
  if (lv) {
    facts.appendChild(el('p', 'role', T('sim.measured', { pool: lv.pool || '–', when: fmt.relative(lv.at), size: fmt.size(lv.size), used: fmt.size(lv.used),
      nm: fmt.size(lv.nm), jack: fmt.size(lv.jack), others: fmt.size(lv.others) })));
  } else facts.appendChild(el('p', 'role', T('sim.unmeasured')));
  const mbar = el('div', 'toolbar');
  const mb = h.button(T('sim.measure'), 'small plain', async () => {
    mb.disabled = true;
    const got = await h.measure();
    mb.disabled = false;
    if (got) { V.measured = got; V.src = 'real'; V.cfg = baseCfg(h, 'real'); restart(h); redraw(h, body); }
  });
  mbar.append(mb, el('span', 'role', T('sim.measure_hint')));
  facts.appendChild(mbar);
  if (V.src === 'real' && lv) facts.appendChild(el('p', 'role', T('sim.real_note')));
  body.appendChild(facts);

  // ---- the sliders (a change starts the simulation over with them)
  const ctl = el('div', 'jo-sim-ctl');
  const unit = (u, v) => (u === 'gb' ? size(v) : u === 'pct' ? `${v} %` : u === 'days' ? h.nOf('days', v) : String(v));
  for (const [k, min, max, step, u] of SLIDERS) {
    const row = el('label', 'jo-sim-slider');
    const inp = el('input');
    inp.type = 'range';
    inp.min = min;
    inp.max = Math.max(max, Math.ceil(V.cfg[k] || 0));
    inp.step = step;
    inp.value = V.cfg[k];
    const out = el('output', 'jo-sim-out', unit(u, V.cfg[k]));
    inp.oninput = () => { out.textContent = unit(u, Number(inp.value)); };
    inp.onchange = () => { V.cfg = { ...V.cfg, [k]: Number(inp.value) }; delete V.cfg.others0; delete V.cfg.jack0; V.src = 'test'; restart(h); redraw(h, body); };
    row.append(el('span', 'jo-sim-name', T('sim.f.' + k)), inp, out);
    ctl.appendChild(row);
  }
  const seedRow = el('label', 'jo-sim-slider');
  const seed = el('input', 'input jo-sim-seed');
  seed.type = 'number';
  seed.min = 0;
  seed.value = V.seed;
  seed.onchange = () => { V.seed = Math.max(0, Math.floor(Number(seed.value) || 0)); restart(h); redraw(h, body); };
  seedRow.append(el('span', 'jo-sim-name', T('sim.f.seed')), seed);
  ctl.appendChild(seedRow);
  body.appendChild(ctl);

  // ---- days and events
  const days = el('div', 'toolbar jo-sim-acts');
  const go = (fn) => () => { fn(); redraw(h, body); };
  days.append(h.button(T('sim.run1'), 'small', go(() => simDays(V.sim, 1))), h.button(T('sim.run7'), 'small', go(() => simDays(V.sim, 7))),
    h.button(T('sim.run30'), 'small', go(() => simDays(V.sim, 30))), h.button(T('sim.until'), 'small', go(() => simDays(V.sim, 365, true))),
    h.button(T('sim.reset'), 'small plain', go(() => restart(h))));
  body.appendChild(days);
  const ev = el('div', 'toolbar jo-sim-acts');
  ev.appendChild(el('span', 'jo-sim-label', T('sim.events')));
  math.EVENTS.forEach((id) => ev.appendChild(h.button(T('sim.ev.' + id), 'small plain', go(() => simEvent(V.sim, id)))));
  body.appendChild(ev);

  // ---- which rule set the picture shows
  const pick = el('div', 'jo-sim-src');
  pick.appendChild(el('span', 'jo-sim-label', T('sim.rules')));
  [['new', 'sim.rules_new'], ['v162', 'sim.rules_old']].forEach(([v, key]) => {
    const l = el('label', 'check');
    const i = el('input');
    i.type = 'radio';
    i.name = 'jo-sim-rules';
    i.checked = V.rules === v;
    i.onchange = () => { V.rules = v; redraw(h, body); };
    l.append(i, el('span', '', T(key)));
    pick.appendChild(l);
  });
  pick.appendChild(el('span', 'jo-sim-day', V.shown ? T('sim.day_of', { n: V.sim.w.day, scenario: T('sim.sc.' + V.shown) }) : T('sim.day', { n: V.sim.w.day })));
  body.appendChild(pick);
  body.appendChild(picture(h, V.sim.sides[V.rules]));
  body.appendChild(checks(h));
  body.appendChild(totals(h, V.sim.sides[V.rules]));
  body.appendChild(dayTable(h, V.sim.sides[V.rules]));
  body.appendChild(logBox(h, V.sim.sides[V.rules]));
  body.appendChild(scenarios(h, body));
}

/** The pool bar: never moves · others · Jack unwatched · Jack watched · free — X, Y above, H and the fill limit below */
function picture(h, side) {
  const { T, el, fmt } = h;
  const w = V.sim.w;
  const m = mathOf(w, side);
  const pct = (gb) => `${Math.max(0, Math.min(100, gb * 100 / w.cfg.pool)).toFixed(2)}%`;
  const box = el('div', 'jo-sim-pic');
  const bar = el('div', 'jo-sim-bar');
  bar.setAttribute('role', 'img');
  bar.setAttribute('aria-label', T('sim.bar_label', { pct: r1(m.used * 100 / w.cfg.pool) }));
  const seg = (cls, gb, key) => { const s = el('i', 'jo-sim-seg ' + cls); s.style.width = pct(gb); s.title = `${T(key)} · ${fmt.size(gb * GIB)}`; bar.appendChild(s); };
  seg('nm', m.nm, 'sim.bar.nm');
  seg('others', m.others, 'sim.bar.others');
  seg('unw', m.jack - m.watched, 'sim.bar.unw');
  seg('wat', m.watched, 'sim.bar.wat');
  // Y and H with their label to the left of the line, X and the fill limit to the right (to the left near the bar's end)
  const mark = (gb, text, below, left) => {
    const k = el('span', 'jo-sim-mark' + (below ? ' below' : '') + (left || gb / w.cfg.pool > 0.9 ? ' flip' : ''));
    k.style.left = pct(gb);
    k.appendChild(el('span', '', text));
    bar.appendChild(k);
  };
  mark(m.xb, `X ${w.cfg.x} %`);
  mark(m.yb, `Y ${Math.max(0, w.cfg.x - w.cfg.offset)} %`, false, true);
  mark(m.nm + m.others + m.half, 'H', true, true);
  mark(m.nm + m.others + m.jackLimit, T('sim.mark.limit'), true);
  box.appendChild(bar);
  const legend = el('div', 'jo-sim-legend');
  [['nm', 'sim.bar.nm', m.nm], ['others', 'sim.bar.others', m.others], ['unw', 'sim.bar.unw', m.jack - m.watched], ['wat', 'sim.bar.wat', m.watched],
    ['free', 'sim.bar.free', w.cfg.pool - m.used]].forEach(([cls, key, gb]) => {
    const it = el('span', 'jo-sim-key');
    it.append(el('i', 'jo-sim-seg ' + cls), el('span', '', `${T(key)} ${fmt.size(Math.max(0, gb) * GIB)}`));
    legend.appendChild(it);
  });
  box.appendChild(legend);
  box.appendChild(el('p', 'role jo-sim-numbers', T('sim.numbers', { room: fmt.size(m.room * GIB), h: fmt.size(m.half * GIB), d: fmt.size(m.halfD * GIB),
    limit: fmt.size(m.jackLimit * GIB), olimit: fmt.size(m.othersLimit * GIB), want: fmt.size(m.want * GIB) })));
  return box;
}

/** The five checks, both rule sets side by side: green, or red from day n */
function checks(h) {
  const { T, el } = h;
  const t = el('table', 'jo-sim-table');
  const head = el('tr');
  head.append(el('th', '', T('sim.checks')), el('th', '', T('sim.rules_new')), el('th', '', T('sim.rules_old')));
  t.appendChild(head);
  for (const k of INVARIANTS) {
    const tr = el('tr');
    const name = el('td', '', T('sim.inv.' + k));
    name.title = T('sim.inv.' + k + '_tip');
    tr.appendChild(name);
    for (const r of ['new', 'v162']) {
      const s = V.sim.sides[r].inv[k];
      const td = el('td');
      td.appendChild(el('span', 'chip ' + (s.ok ? 'ok' : 'danger'), s.ok ? T('sim.green') : T('sim.red_from', { day: s.first })));
      tr.appendChild(td);
    }
    t.appendChild(tr);
  }
  const wrap = el('div', 'jo-sim-scroll');
  wrap.appendChild(t);
  return wrap;
}

function totals(h, side) {
  const { T, el, fmt } = h;
  const kv = el('dl', 'kv jo-sim-kv');
  const add = (key, v) => kv.append(el('dt', '', T(key)), el('dd', '', v));
  add('sim.tot.up', fmt.size(side.up * GIB));
  add('sim.tot.down', fmt.size(side.down * GIB));
  add('sim.tot.moved', fmt.size(side.moved * GIB));
  add('sim.tot.spilled', fmt.size(side.spilled * GIB));
  add('sim.tot.pingpong', String(side.pingpong));
  add('sim.tot.revived', String(side.revived));
  add('sim.tot.short', fmt.size(Math.max(0, side.short) * GIB));
  add('sim.tot.told', String(side.told));
  add('sim.tot.warns', String(side.warns));
  return kv;
}

function dayTable(h, side) {
  const { T, el, fmt } = h;
  const box = el('div', 'jo-sim-scroll jo-sim-days');
  const t = el('table', 'jo-sim-table');
  const head = el('tr');
  ['day', 'pool', 'jack', 'others', 'up', 'down', 'moved', 'checks'].forEach((c) => head.appendChild(el('th', c === 'checks' ? '' : 'num', T('sim.col.' + c))));
  t.appendChild(head);
  const gb = (v) => fmt.size(v * GIB);
  side.hist.slice(-14).reverse().forEach((r) => {
    const tr = el('tr');
    const bad = r.inv ? INVARIANTS.filter((k) => !r.inv[k]) : [];
    [String(r.day), `${r.pct} %`, gb(r.jack), gb(r.others), gb(r.up), gb(r.down), gb(r.moved)].forEach((v) => tr.appendChild(el('td', 'num', v)));
    const td = el('td');
    td.appendChild(r.inv ? el('span', 'chip ' + (bad.length ? 'danger' : 'ok'), bad.length ? bad.map((k) => T('sim.short.' + k)).join(' · ') : T('sim.green')) : el('span', 'role', '–'));
    if (r.warn) td.appendChild(el('span', 'chip warn', T('sim.warn')));
    tr.appendChild(td);
    t.appendChild(tr);
  });
  box.appendChild(t);
  return box;
}

/** The log in the night run's words (the last 200 lines; a day's head line starts each night) */
function logBox(h, side) {
  const { T, el, fmt } = h;
  const box = el('div', 'jo-sim-log');
  box.setAttribute('tabindex', '0');
  box.setAttribute('aria-label', T('sim.log_title'));
  const words = (l) => {
    const p = { ...l.p };
    for (const k of ['size', 'back', 'goes', 'age', 'held', 'limit', 'writes', 'nm', 'room', 'promised']) if (typeof p[k] === 'number') p[k] = fmt.size(p[k] * GIB);
    if (l.k === 'age') p.days = h.nOf('days', l.p.days);
    if (p.first) p.first = T('sim.who.' + p.first);
    if (l.k === 'event') p.what = T('sim.ev.' + l.p.what) + (l.p.title ? ` (${l.p.title})` : '');
    return T('sim.log.' + l.k, p);
  };
  side.log.slice(-200).forEach((l) => box.appendChild(el('div', 'jo-sim-line ' + (l.c || ''), words(l))));
  if (!side.log.length) box.appendChild(el('div', 'role', '–'));
  requestAnimationFrame(() => { box.scrollTop = box.scrollHeight; });
  return box;
}

/** The scenarios: run all (both rule sets each), a row per scenario; «Show» plays it into the picture above */
function scenarios(h, body) {
  const { T, el } = h;
  const box = el('div', 'jo-sim-sc');
  box.appendChild(el('div', 'jo-part-head', T('sim.scenarios')));
  box.appendChild(el('p', 'role', T('sim.scenarios_sub')));
  const bar = el('div', 'toolbar');
  bar.appendChild(h.button(T('sim.run_all'), 'small', () => {
    V.results = SCENARIOS.map((sc) => ({ id: sc.id, days: sc.days, r: summary(runScenario(sc.id, V.cfg, V.seed)) }));
    V.resultsOn = { ...V.cfg, seed: V.seed, src: V.src };
    redraw(h, body);
  }));
  box.appendChild(bar);
  if (!V.results) return box;
  const t = el('table', 'jo-sim-table');
  const head = el('tr');
  head.append(el('th', '', T('sim.col.scenario')), el('th', '', T('sim.rules_new')), el('th', '', T('sim.rules_old')), el('th', '', ''));
  t.appendChild(head);
  const cell = (x) => {
    const td = el('td');
    const red = INVARIANTS.filter((k) => x.inv[k] != null);
    td.appendChild(el('span', 'chip ' + (red.length ? 'danger' : 'ok'), red.length
      ? T('sim.red_list', { day: Math.min(...red.map((k) => x.inv[k])), checks: red.map((k) => T('sim.short.' + k)).join(' · ') }) : T('sim.green')));
    td.appendChild(el('span', 'role jo-sim-cellnote', T('sim.cell', { pp: x.pingpong, warns: x.warns })));
    return td;
  };
  V.results.forEach((res) => {
    const tr = el('tr');
    tr.append(el('td', '', `${T('sim.sc.' + res.id)} · ${h.nOf('days', res.days)}`), cell(res.r.new), cell(res.r.v162));
    const td = el('td');
    td.appendChild(h.button(T('sim.show'), 'small plain', () => {
      V.sim = runScenario(res.id, V.cfg, V.seed);
      V.shown = res.id;
      redraw(h, body);
    }));
    tr.appendChild(td);
    t.appendChild(tr);
  });
  const wrap = el('div', 'jo-sim-scroll');
  wrap.appendChild(t);
  box.appendChild(wrap);
  // what the table ran on (the sliders' values at «Run all» — real data or one's own): results differ with them
  const c = V.resultsOn || {};
  box.appendChild(el('p', 'role jo-sim-base', T(c.src === 'real' ? 'sim.base_real' : 'sim.base_test', { pool: h.fmt.size((c.pool || 0) * GIB),
    nm: h.fmt.size((c.nm || 0) * GIB), writes: h.fmt.size((c.writes || 0) * GIB), x: c.x, offset: c.offset, k: c.k,
    days: h.nOf('days', c.unwatchedDays || 0), age: h.nOf('days', c.age || 0), seed: c.seed })));
  return box;
}

Office.skipperMath = math;
Office.skipperSim = { view, math };
if (typeof Office.skipperLoaded === 'function') Office.skipperLoaded();
})();
