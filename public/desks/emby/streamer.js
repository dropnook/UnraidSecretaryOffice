/* Jack Streamer's pool simulator (2026-10-14, the owner's rules — a mockup: the real night run in agent/lib/nightrun.php,
   agent/lib/poollevel.php, EmbyCache and Smart Mover is NOT changed by this file). A step of his setup wizard (desk.js):
   play the pool's nights through, by hand or in seeded scenarios, and see whether the rules hold — before the real night
   run is changed. Two parts:
     1. the pure math (no DOM, no Office — runs under node for tests/run.php): streamerPoolMath() (→ poolLevelMath() in
        agent/lib/poollevel.php), streamerNight() (→ nightRun()/nightPlan() in agent/lib/nightrun.php and EmbyCache's
        give_back/fill in embycache/embycache_run.py), the invented world (people, series, films, downloads), the
        scenarios, the invariants after every night.
     2. the step's page (Office.streamerSim.view()), loaded by desk.js like Mr. Restori's drill.js.
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
function streamerPoolMath(p) {
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
 * Real data: Jack Streamer already holds `gb` on the pool — the invented world grows (a new series per person in turn,
 * watched lately, unwatched episodes) until what he wants is at least that much, so day 0 can hold it
 */
function growFor(w, gb) {
  if (!(gb > 0)) return;
  const want = () => sum(candidates(w).map((e) => e.size));
  for (let u = 0; want() < gb * 1.05 && w.series.length < 2000; u = (u + 1) % w.people.length) addSeries(w, u, -Math.floor(w.rnd() * 10), w.cfg.episodes + 2);
}

/** A rule set's own side of the world: what lies on the pool, the downloads, what it gave back, its totals and log */
function newSide(rules, w) {
  // the downloads already there: the server's as three blocks of the last days (real data), else two days' writes
  const o = w.cfg.others0;
  const dl = o != null ? [3, 2, 1].map((d) => ({ day: -d, size: r1(o / 3) })).filter((d) => d.size > 0)
    : [{ day: -2, size: w.cfg.writes }, { day: -1, size: w.cfg.writes }];
  return { rules, pool: new Set(), dl, back: new Map(),
    up: 0, down: 0, moved: 0, spilled: 0, pingpong: 0, revived: 0, short: 0, warns: 0, warnNow: false, warnWhy: null, fillStopped: false, told: 0, log: [], hist: [], inv: invNew(), fetchedTonight: 0, forced: 0 };
}

const stale = (w, s) => w.day - s.lastSeen >= w.cfg.unwatchedDays;
/** The series' next unwatched episode's index */
const nextIdx = (w, sid) => { const e = w.eps.find((x) => x.sid === sid && !x.watched); return e ? e.idx : Infinity; };

/**
 * What Jack wants (his queue, EmbyCache's on-deck list): per person, of each series the next `episodes` unwatched ones,
 * of each film the film. A series nobody watched for N days is no longer wanted (it may go back).
 */
function wanted(w) {
  const budget = budgetOf(w.cfg);
  const all = candidates(w);
  if (budget == null) return all;
  // by budget (EmbyCache's budget_mode / cache_budget — the most he WANTS, reserving nothing), split as its
  // apply_budget() does: a person's fixed budget first (cfg.userBudgets[person], GB), the rest evenly on the others —
  // what someone doesn't need flows to the others; then each person's next files while they fit his or her part
  const per = w.people.map((_, u) => all.filter((e) => e.uid === u)
    .sort((a, b) => rank(w, a) - rank(w, b) || w.series[b.sid].lastSeen - w.series[a.sid].lastSeen || a.idx - b.idx));
  const caps = budgetCaps(budget, per.map((l) => sum(l.map((e) => e.size))), w.cfg.userBudgets || []);
  const out = [];
  per.forEach((l, u) => {
    let total = 0;
    for (const e of l) {
      if (total + e.size > caps[u] + TOL) continue;          // that one won't fit: a smaller one further on may
      out.push(e);
      total += e.size;
    }
  });
  return out;
}
/**
 * A budget split per person (pure — EmbyCache's apply_budget): fixed[u] (GB, > 0) for whoever has one; the rest of the
 * budget water-filled over the others by what they want (demand[u]): whoever wants less than an even share keeps what
 * they want, the rest is shared anew. The fixed ones beyond the budget: the others get nothing.
 */
function budgetCaps(budget, demand, fixed) {
  const caps = demand.map((_, u) => (fixed[u] > 0 ? fixed[u] : null));
  let pool = Math.max(0, budget - sum(caps.filter((x) => x != null)));
  let open = demand.map((_, u) => u).filter((u) => caps[u] == null);
  for (let changed = true; changed && open.length;) {
    changed = false;
    const share = pool / open.length;
    for (const u of open) {
      if (demand[u] < share) { caps[u] = demand[u]; pool -= demand[u]; changed = true; }
    }
    open = open.filter((u) => caps[u] == null);
  }
  const share = open.length ? pool / open.length : 0;
  open.forEach((u) => { caps[u] = share; });
  return caps;
}
/** The budget in GB when he goes by budget (cfg.budget > 0), else null (by count: N episodes ahead) */
const budgetOf = (c) => (c.budget > 0 ? c.budget : null);
/** What he could want: the unwatched of every series watched lately — by count the next N per series, by budget all of them */
function candidates(w) {
  const c = w.cfg;
  const out = [];
  for (const s of w.series) {
    if (stale(w, s)) continue;
    const list = w.eps.filter((e) => e.sid === s.id && !e.watched).sort((a, b) => a.idx - b.idx);
    out.push(...(s.film ? list.slice(0, 1) : budgetOf(c) == null ? list.slice(0, c.episodes) : list));
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
  const want = sum(wanted(w).map((e) => e.size));
  const c = w.cfg;
  const p = { size: c.pool, nm: m.nm, jack: m.jack, others: m.others, x: c.x, offset: c.offset, k: c.k, want };
  return { ...streamerPoolMath(p), want, watched: m.watched };
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
  const queue = wanted(w).filter((e) => !side.pool.has(e.id));
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
function streamerNight(w, side, L) {
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
    const after = streamerPoolMath({ size: c.pool, nm: m.nm, jack, others: m.others - aged, x: c.x, offset: c.offset, k: c.k, want: m.want });
    // hers only from X (the owner 2026-10-14): what they borrowed over their limit first, then — the pool still
    // over Y — out of their own half too; below X they stay (Jack Streamer is held back by his fill limit instead)
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
  // fill: nothing while a warning stands (the owner, 2026-10-14 — he only frees then: watched and N-day files; it
  // clears once a night ends without its cause) — target: nightRun()'s fill step, skipped while the last night's
  // warning stands (night.json); nothing while over his limit; else up to his limit and never over Y
  const m = mathOf(w, side);
  side.fillStopped = !!side.warnNow;
  if (side.warnNow) {
    L('fill_warn', {}, 'hold');
  } else if (m.jack > m.jackLimit + TOL) {
    L('fill_over', { held: r1(m.jack), limit: r1(m.jackLimit) }, 'hold');
  } else {
    const budget = budgetOf(c);
    side.fetchedTonight = fill(w, side, (e, now) => {
      if (now.jack + e.size > m.jackLimit + TOL) return 'skip_limit';
      if (budget != null && now.jack + e.size > budget + TOL) return 'skip_budget';      // he holds at most min(budget, fill limit)
      if (now.nm + now.jack + now.others + e.size > m.yb + TOL) return 'skip_y';
      return '';
    }, L);
  }
  tell(w, side, L);
}

/**
 * The end of the night: still at/over X → «told at once»; and the warning (the owner, 2026-10-14 — a message, not a rule)
 * when the cache for new writes is practically gone because Jack Streamer can't give back fast enough (appdata growing
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
  // (also when nothing is left to share — what never moves already reaches Y: then the cache for new writes is gone too)
  // the causes, each in its own words on the page: what never moves reaches Y; the day's writes don't fit up to X while he
  // is over his limit; the others' promise is broken
  const causes = [];
  if (m.never) causes.push('never');
  if (left < w.cfg.writes - TOL && m.jack > m.jackLimit + TOL) causes.push('room');
  if (m.others + left < promised - TOL && !m.never) causes.push('promise');
  side.warnNow = causes.length > 0;
  side.warnWhy = side.warnNow ? { causes, nm: r1(m.nm), room: r1(left), writes: w.cfg.writes, promised: r1(promised), have: r1(m.others + left) } : null;
  if (side.warnNow) { side.warns++; L('warn', { room: r1(left), writes: w.cfg.writes, promised: r1(promised) }, 'bad'); }
}

// ---------------------------------------------------------------------- the invariants (after every night)
const INVARIANTS = ['jack', 'others', 'unwatched', 'pingpong', 'limit'];
function invNew() { return Object.fromEntries(INVARIANTS.map((k) => [k, { ok: true, first: null, fails: 0, told: null }])); }

/**
 * The five checks of a night:
 *   jack       he holds ≥ min(H, what he wants) (less one file: whole files) — unless held back while the others hold
 *              more than their half (she moves theirs only from X on) — and never more than his fill limit after the night; over it only with files he
 *              may not give back (unwatched, watched lately) and nothing fetched
 *   others     the others always keep at least half of their own half plus the air Y…X (what they hold and what is free
 *              up to X together — «nobody takes everything») — or the night warned (tell()) —, and nothing of the day's
 *              writes had to go to the array
 *   unwatched  nothing unwatched went back unless its series/film was unwatched ≥ N days
 *   pingpong   no file given back and fetched again within 7 days
 *   limit      the pool ≤ X after the night — else «told at once» (the owner, 2026-10-14: expected, e.g. when what never
 *              moves reaches Y and his unwatched files stay; shown as such, not as a failure); red only if not told
 */
function invariants(w, side, before) {
  const m = mathOf(w, side);
  const files = measure(w, side).files;
  const want = wanted(w);
  const wantTotal = sum(want.map((e) => e.size));
  const heldWanted = sum(want.filter((e) => side.pool.has(e.id)).map((e) => e.size));
  const biggest = Math.max(0, ...want.filter((e) => !side.pool.has(e.id)).map((e) => e.size));
  const overOk = side.fetchedTonight <= TOL && files.every((e) => !e.watched && !stale(w, w.series[e.sid]));
  const r = {};
  // he is simply held back while the others hold more than their half (the owner, 2026-10-14: she moves only from X on;
  // writes during the night can leave them over it after her move too) — his half is owed once she has moved theirs
  const heldBack = m.others > m.halfD + TOL;
  // … and while a warning stands he fetches nothing at all (his half is owed again once it clears)
  // by budget everything he holds counts against it (files further ahead stay — unwatched never go back)
  const held = budgetOf(w.cfg) != null ? m.jack : heldWanted;
  r.jack = (held >= Math.min(m.half, wantTotal) - biggest - TOL || heldBack || side.fillStopped)
    && (m.jack <= m.jackLimit + TOL || overOk || (side.fillStopped && side.fetchedTonight <= TOL && files.every((e) => !e.watched && !stale(w, w.series[e.sid]))));
  // what is promised (the owner, 2026-10-14): they always keep at least half of their own half, plus the air Y…X — what
  // they hold and what is free up to X together
  const owed = m.halfD / 2 + m.air;
  const theirs = m.others + Math.max(0, m.xb - m.used);
  if (!m.never) side.short = Math.max(side.short, r1(owed - theirs));
  r.others = m.never || ((theirs >= owed - TOL || side.warnNow) && side.spilled <= before.spilled + TOL);
  r.unwatched = side.forced === before.forced;
  r.pingpong = side.pingpong === before.pingpong;
  const toldNow = side.told > before.told;
  r.limit = m.used <= m.xb + TOL || toldNow;
  if (toldNow && side.inv.limit.told == null) side.inv.limit.told = w.day;
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
  growFor(w, Math.max(w.cfg.jack0 || 0, budgetOf(w.cfg) || 0));
  const sim = { w, sides: { new: newSide('new', w) } };
  for (const side of Object.values(sim.sides)) {      // the first night fills him (day 0)
    // what he holds already (real data): his queue's files up to that size, a person at a time in turn — day 0 holds
    // the measured amount (within one file)
    if (w.cfg.jack0 > 0) {
      const per = w.people.map((_, u) => candidates(w).filter((e) => e.uid === u).sort((x, y) => rank(w, x) - rank(w, y) || x.idx - y.idx));
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
    side.eve = mathOf(w, side);                // day 0 «before the run»: the start as set
    side.prevMorn = null;
    if (!(w.cfg.jack0 > 0)) streamerNight(w, side, logger(w, side));
    side.morn = mathOf(w, side);
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
    up: r1(side.up - before.up), down: r1(side.down - before.down), moved: r1(side.moved - before.moved), inv: r, warn: !!side.warnNow,
    told: side.told > (before.told || 0) });
  if (side.hist.length > 400) side.hist.shift();
}

/** One day for both: the day's draws, the writes, the night, the checks */
function simDay(sim) {
  const w = sim.w;
  w.day++;
  const d = dayDraws(w);
  w.nightWrites = d.nightWrites;
  growFor(w, budgetOf(w.cfg) || 0);           // by budget: enough unwatched in the world to fill it, every day
  for (const side of Object.values(sim.sides)) {
    const L = logger(w, side);
    const before = { spilled: side.spilled, forced: side.forced, pingpong: side.pingpong, told: side.told, up: side.up, down: side.down, moved: side.moved };
    L('day', { day: w.day, writes: d.writes, watched: d.watched.length, fresh: d.fresh.length }, 'day');
    writeDay(w, side, d.writes, L);
    // the day on display has two pictures: the evening (after the day's writes, watching, new episodes) and the morning
    // (after the joint night run)
    side.prevMorn = side.morn;
    side.eve = mathOf(w, side);
    streamerNight(w, side, L);
    side.morn = mathOf(w, side);
    record(w, side, before, true);
  }
  return d;
}
/** Play (2026-10-14): one day — and the checks that turned red with it (they stop the play) */
function playTick(sim) {
  const before = Object.fromEntries(INVARIANTS.map((k) => [k, sim.sides.new.inv[k].ok]));
  simDay(sim);
  return INVARIANTS.filter((k) => before[k] && !sim.sides.new.inv[k].ok);
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
  // by budget (the most he wants, in GB): smaller than his half H, and bigger than his fill limit (H + half the others' unused)
  { id: 'budget_small', days: 30, cfg: (c) => ({ budget: Math.max(10, Math.round(halfOf(c) * 0.5)) }) },
  { id: 'budget_big', days: 30, cfg: (c) => ({ budget: Math.round(halfOf(c) * 1.8) }) },
  { id: 'tiny', days: 30, cfg: { pool: 256, nm: 60, writes: 20, epMin: 1, epMax: 4, filmMin: 8, filmMax: 15 } },
];
/** His half H of a setting (GB) — the budget scenarios are sized by it */
const halfOf = (c) => Math.max(0, (c.x - c.offset) * c.pool / 100 - c.nm) * c.k / 100;
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

// ---------------------------------------------------------------------- the bar's handles (pure)
/** A place on the bar (0…1 of its width) in GB of the pool, and back */
const barGb = (frac, pool) => Math.max(0, Math.min(1, +frac || 0)) * Math.max(0, +pool || 0);
const gbFrac = (gb, pool) => (pool > 0 ? Math.max(0, Math.min(1, gb / pool)) : 0);
/** What the start puts on the pool for the other shares (newSide()'s default: two days of writes) */
const othersAtStart = (c) => (c.others0 != null ? c.others0 : 2 * c.writes);
/** A setting within the rules: NM ≥ 0, NM + others ≤ pool, what Jack starts with fits behind them, 1 ≤ offset, Y < X ≤ 100 */
function fitCfg(cfg) {
  const c = { ...cfg };
  c.pool = Math.max(1, +c.pool || 1);
  c.nm = r1(Math.max(0, Math.min(c.pool, +c.nm || 0)));
  if (c.others0 != null) c.others0 = r1(Math.max(0, Math.min(c.pool - c.nm, c.others0)));
  if (c.jack0 != null) c.jack0 = r1(Math.max(0, Math.min(c.pool - c.nm - othersAtStart(c), c.jack0)));
  c.x = Math.max(2, Math.min(100, Math.round(+c.x || 0)));
  c.offset = Math.max(1, Math.min(c.x - 1, Math.round(+c.offset || 0)));
  return c;
}
/**
 * One value dragged in the bar (or stepped with the keys) into the settings, snapped (1 % for X and Y, about 1 % of the
 * pool for sizes) and within the rules. key: nm (the grey/white edge — the white's end stays), end (the white's end: what
 * the others hold at the start), x (Y stays), y (X stays: the offset = X − Y); value: GB for nm/end, % for x/y.
 */
function dragSet(cfg, key, value) {
  const c = { ...cfg };
  const step = Math.max(1, Math.round(c.pool / 100));
  const snap = (v) => Math.round(v / step) * step;
  const end = c.nm + othersAtStart(c);
  if (key === 'nm') {
    c.nm = Math.max(0, Math.min(end, snap(value)));
    c.others0 = end - c.nm;
  } else if (key === 'end') {
    c.others0 = Math.max(c.nm, Math.min(c.pool, snap(value))) - c.nm;
  } else if (key === 'x') {
    const y = c.x - c.offset;
    c.x = Math.max(y + 1, Math.min(100, Math.round(value)));
    c.offset = c.x - y;
  } else if (key === 'y') {
    c.offset = c.x - Math.max(1, Math.min(c.x - 1, Math.round(value)));
  }
  return fitCfg(c);
}

/**
 * The widths of a bar's segments (fractions, summing to 1, in order): each value > 0 at least `min` (a few pixels: always
 * visible), the others shrunk in proportion to make room; 0 stays 0. The words keep the true values.
 */
function segWidths(values, min) {
  const v = values.map((x) => Math.max(0, +x || 0));
  const total = sum(v);
  if (total <= 0) return v.map(() => 0);
  const live = v.filter((x) => x > 0).length;
  const m = Math.max(0, Math.min(min || 0, 1 / live));
  const out = v.map((x) => x / total);
  const fixed = new Set();
  for (let round = 0; round < values.length; round++) {
    const small = out.map((f, i) => (v[i] > 0 && !fixed.has(i) && f < m ? i : -1)).filter((i) => i >= 0);
    if (!small.length) break;
    small.forEach((i) => { fixed.add(i); out[i] = m; });
    const rest = 1 - fixed.size * m;
    const free = out.reduce((a, f, i) => a + (fixed.has(i) ? 0 : f), 0);
    out.forEach((f, i) => { if (!fixed.has(i) && free > 0) out[i] = f * rest / free; });
  }
  return out;
}

const math = { playTick, budgetCaps, streamerPoolMath, barGb, gbFrac, dragSet, fitCfg, segWidths, newSim, simDay, simDays, simEvent, runScenario, summary, mathOf, measure, wanted, DEFAULTS, SCENARIOS,
  INVARIANTS, EVENTS: Object.keys(EVENTS), rng, PINGPONG_DAYS };
if (typeof module !== 'undefined' && module.exports) module.exports = math;       // tests/run.php under node
if (typeof Office === 'undefined') return;

// ====================================================================== 2. the step's page
/*
 * Office.streamerSim.view(h) → the simulator's section for his wizard (desk.js setupSimulator()). h: {T, el, fmt, nOf,
 * button, section, base() (the settings to start from: his form's N days, Ms. Moverelli's X/offset/k/age, his pool as
 * measured — or null), measure() (the explicit «Measure now»: the agent measures the pool now, may wake it; the level, false when it measured nothing, null on an error)}. The
 * simulation lives here across the wizard's drawings (V); a control redraws only the simulator's body, in place.
 */
const GIB = 1024 * 1024 * 1024;
const V = { src: null, cfg: null, seed: 162, sim: null, results: null, playing: false, paused: false, timer: 0, speed: 3000, stopNote: null, shown: '', measured: null, failed: false, busy: false };
// [key, min, max, step, unit] — unit: gb | pct | n | days. NM, what the others hold, X and Y are set in the bar (its handles);
// the pool's size stays a slider, the rest folds into «More values»
const POOL_SLIDER = ['pool', 128, 4096, 32, 'gb'];
const SLIDERS = [
  ['writes', 0, 400, 5, 'gb'], ['watch', 0, 20, 1, 'n'], ['newEps', 0, 20, 1, 'n'], ['people', 1, 8, 1, 'n'], ['k', 0, 100, 5, 'pct'],
  ['unwatchedDays', 1, 120, 1, 'days'], ['age', 0, 30, 1, 'days'],
];
/** What the user opened stays open across the simulator's drawings (as desk.js's keepFold) */
const folds = new Map();
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
/** A changed value (a slider, a handle): own test values from now, the simulation anew at day 0 */
function setCfg(h, cfg) {
  V.cfg = fitCfg(cfg);
  V.src = 'test';
  restart(h);
}
/** The start as the «before the run» bar shows it now: NM, what the others and Jack Streamer hold — a handle moves one */
function shownStart() {
  const m = V.sim.sides.new.eve;
  return { ...V.cfg, nm: r1(m.nm), others0: r1(m.others), jack0: r1(m.jack), nmGrow: V.cfg.nmGrow };
}

/** The settings from a source: the real pool (GB) and the rules, or the sliders' own; null when the real one isn't measured */
function baseCfg(h, src) {
  const b = h.base() || {};
  const cfg = { ...DEFAULTS, unwatchedDays: b.days || 30 };
  if (b.x > 0) Object.assign(cfg, { x: b.x, offset: Math.max(0, b.x - b.y), k: b.k == null ? 50 : b.k });
  if (b.age != null) cfg.age = b.age;
  if (b.budget > 0) cfg.budget = b.budget;       // his «amount» in the wizard: by budget (GB)
  if (b.budget > 0 && b.userBudgets) Object.assign(cfg, { userBudgets: b.userBudgets, people: Math.max(1, Math.min(8, b.userBudgets.length)) });
  const lv = V.measured || b.level;
  if (src === 'real') {
    if (!lv) return null;
    Object.assign(cfg, { pool: r1(lv.size / GIB), nm: r1(lv.nm / GIB), others0: r1(lv.others / GIB), jack0: r1(lv.jack / GIB) });
    if (lv.x > 0) Object.assign(cfg, { x: lv.x, offset: Math.max(0, lv.x - lv.y), k: lv.k });
  }
  return cfg;
}
function restart(h) {
  stopPlay();                                 // a new start (a drag, a slider, «Start over», a scenario) ends the play
  V.stopNote = null;
  V.sim = newSim(V.cfg, V.seed);
  V.shown = '';
}

// ---- play (2026-10-14): «Until a check turns red» plays a day every V.speed ms (1, 3 or 10 s), the bars moving (a
// transition while playing; none under prefers-reduced-motion); «Pause» / «Continue». It stops by itself on a red check
// (said, the check marked), on a new start, after a year, or when the simulator is gone (the dialog closed). ONE timer:
// V.timer, set only by startPlay()'s step and cleared only by stopPlay().
const PLAY_SPEEDS = [1000, 3000, 10000];
function stopPlay() {
  clearTimeout(V.timer);
  V.timer = 0;
  V.playing = false;
}
function startPlay(h, body) {
  if (V.playing) return;
  V.playing = true;
  V.paused = false;
  V.stopNote = null;
  const step = () => {
    V.timer = 0;
    if (!V.playing) return;
    if (!body.isConnected) { stopPlay(); return; }
    const red = playTick(V.sim);
    if (red.length) { stopPlay(); V.stopNote = { day: V.sim.w.day, red }; }
    else if (V.sim.w.day >= 365) stopPlay();
    playShow(h, body);
    if (V.playing) V.timer = setTimeout(step, V.speed || 3000);
  };
  playShow(h, body);
  V.timer = setTimeout(step, 50);             // the first day at once, then one every V.speed
}
function pausePlay(h, body) {
  stopPlay();
  V.paused = true;
  playShow(h, body);
}
/** During the play: the bars in place (they move), the button's words, and below the bars all anew */
function playShow(h, body) {
  const pic = body.querySelector('.jo-sim-pic');
  if (!pic || !pic.update) { redraw(h, body); return; }
  pic.classList.toggle('playing', !!V.playing);
  pic.update();
  const btn = body.querySelector('[data-play]');
  if (btn) btn.textContent = playWords(h);
  const tail = body.querySelector('.jo-sim-tail');
  if (tail) tail.replaceWith(tailOf(h, body));
}
const playWords = (h) => h.T(V.playing ? 'sim.pause' : V.paused ? 'sim.resume' : 'sim.until');

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
  // no number yet (not set up, or never measured): say how to get one; «asleep or no ZFS» only after a measuring that failed
  } else facts.appendChild(el('p', 'role', V.failed ? T('sim.unmeasured') : T('sim.not_yet', { button: T('sim.measure') })));
  const mbar = el('div', 'toolbar');
  const mb = h.button(T('sim.measure'), 'small plain', async () => {
    mb.disabled = true;
    const got = await h.measure();
    mb.disabled = false;
    if (got) { V.measured = got; V.failed = false; V.src = 'real'; V.cfg = baseCfg(h, 'real'); restart(h); redraw(h, body); } else if (got === false && !lv) { V.failed = true; redraw(h, body); }
  });
  mbar.append(mb, el('span', 'role', T('sim.measure_hint')));
  facts.appendChild(mbar);
  if (V.src === 'real' && lv) facts.appendChild(el('p', 'role', T('sim.real_note')));
  body.appendChild(facts);

  // ---- the sliders (a change starts the simulation over with them, as a handle does): the pool's size, then folded
  // the values the bar can't show
  const unit = (u, v) => (u === 'gb' ? size(v) : u === 'pct' ? `${v} %` : u === 'days' ? h.nOf('days', v) : String(v));
  const slider = ([k, min, max, step, u]) => {
    const row = el('label', 'jo-sim-slider');
    const inp = el('input');
    inp.type = 'range';
    inp.min = min;
    inp.max = Math.max(max, Math.ceil(V.cfg[k] || 0));
    inp.step = step;
    inp.value = V.cfg[k];
    const out = el('output', 'jo-sim-out', unit(u, V.cfg[k]));
    inp.oninput = () => { out.textContent = unit(u, Number(inp.value)); };
    inp.onchange = () => { setCfg(h, { ...V.cfg, [k]: Number(inp.value) }); redraw(h, body); };
    row.append(el('span', 'jo-sim-name', T('sim.f.' + k)), inp, out);
    return row;
  };
  const top = el('div', 'jo-sim-ctl');
  top.appendChild(slider(POOL_SLIDER));
  // the amount: by count (N episodes ahead) or by budget (the most he wants, in GB — it reserves nothing)
  const scope = el('label', 'jo-sim-slider');
  const mode = el('select', 'picker');
  mode.append(new Option(T('setup.mode_count'), 'count'), new Option(T('setup.mode_budget'), 'budget'));
  mode.value = V.cfg.budget > 0 ? 'budget' : 'count';
  mode.onchange = () => {
    setCfg(h, { ...V.cfg, budget: mode.value === 'budget' ? Math.round(halfOf(V.cfg)) || Math.round(V.cfg.pool / 10) : 0 });
    redraw(h, body);
  };
  scope.append(el('span', 'jo-sim-name', T('setup.mode')), mode);
  top.appendChild(scope);
  if (V.cfg.budget > 0) top.appendChild(slider(['budget', 1, Math.ceil(V.cfg.pool), Math.max(1, Math.round(V.cfg.pool / 100)), 'gb']));
  body.appendChild(top);
  const more = keepFold(el('details', 'jo-sim-more'), 'more', false);
  more.appendChild(el('summary', '', T('sim.more')));
  const ctl = el('div', 'jo-sim-ctl');
  SLIDERS.forEach((x) => ctl.appendChild(slider(x)));
  const seedRow = el('label', 'jo-sim-slider');
  const seed = el('input', 'input jo-sim-seed');
  seed.type = 'number';
  seed.min = 0;
  seed.value = V.seed;
  seed.onchange = () => { V.seed = Math.max(0, Math.floor(Number(seed.value) || 0)); restart(h); redraw(h, body); };
  seedRow.append(el('span', 'jo-sim-name', T('sim.f.seed')), seed);
  ctl.appendChild(seedRow);
  // the chance: the same number gives the same course; «roll again» a new one, the simulation anew at day 0
  const dice = el('div', 'jo-sim-dice');
  dice.append(h.button(T('sim.reroll'), 'small plain', () => { V.seed = 1 + Math.floor(Math.random() * 99999); restart(h); redraw(h, body); }),
    el('span', 'role', T('sim.seed_hint')));
  ctl.appendChild(dice);
  more.appendChild(ctl);
  body.appendChild(more);

  // ---- days and events
  const days = el('div', 'toolbar jo-sim-acts');
  const go = (fn) => () => { fn(); redraw(h, body); };
  days.append(h.button(T('sim.run1'), 'small', go(() => simDays(V.sim, 1))), h.button(T('sim.run7'), 'small', go(() => simDays(V.sim, 7))),
    h.button(T('sim.run30'), 'small', go(() => simDays(V.sim, 30))));
  const playBtn = h.button(playWords(h), 'small', () => (V.playing ? pausePlay(h, body) : startPlay(h, body)));
  playBtn.dataset.play = '1';
  const speed = el('select', 'picker jo-sim-speed');
  speed.setAttribute('aria-label', T('sim.speed'));
  PLAY_SPEEDS.forEach((ms) => speed.appendChild(new Option(`${ms / 1000} s`, String(ms))));
  speed.value = String(V.speed || 3000);
  speed.onchange = () => { V.speed = Number(speed.value); };
  days.append(playBtn, speed, h.button(T('sim.reset'), 'small plain', go(() => restart(h))));
  body.appendChild(days);
  const ev = el('div', 'toolbar jo-sim-acts');
  ev.appendChild(el('span', 'jo-sim-label', T('sim.events')));
  math.EVENTS.forEach((id) => ev.appendChild(h.button(T('sim.ev.' + id), 'small plain', go(() => simEvent(V.sim, id)))));
  body.appendChild(ev);

  // ---- the day on display
  const pick = el('div', 'jo-sim-src');
  pick.appendChild(el('span', 'jo-sim-day', V.shown ? T('sim.day_of', { n: V.sim.w.day, scenario: T('sim.sc.' + V.shown) }) : T('sim.day', { n: V.sim.w.day })));
  body.appendChild(pick);
  const pic = picture(h, body);
  pic.classList.toggle('playing', !!V.playing);
  body.appendChild(pic);
  body.appendChild(tailOf(h, body));
}

/** Below the bars: why the play stopped, the checks, the totals, the days, the log, the scenarios (anew at every day) */
function tailOf(h, body) {
  const { T, el } = h;
  const tail = el('div', 'jo-sim-tail');
  if (V.stopNote) {
    tail.appendChild(el('p', 'callout danger jo-sim-stopnote', T('sim.stopped_red', { day: V.stopNote.day,
      checks: V.stopNote.red.map((k) => T('sim.inv.' + k)).join(' · ') })));
  }
  tail.append(checks(h), totals(h, V.sim.sides.new), dayTable(h, V.sim.sides.new), logBox(h, V.sim.sides.new), scenarios(h, body));
  return tail;
}

/**
 * The day on display as two pool bars on one scale (the owner, 2026-10-14): «before the run» — the evening, after the day's
 * writes, watching and new episodes (day 0: the start) — and «after the run» — the morning, after the joint night run.
 * Each: never moves · others · Jack unwatched · Jack watched · free, X and Y above, H and the fill limit below, and what
 * changed (the evening against the morning before, the morning against the evening). Segments > 0 keep a few pixels
 * (segWidths(), 10 px); the words keep the true values — the one legend says each before → after. Four handles on the «before» bar set the start directly (pointer —
 * mouse and touch —, keys, role=slider): the grey/white edge (NM), the white's end (what the others hold), X and Y (the
 * offset). Jack Streamer's part is the rules': it follows. A move sets a new start and rebuilds day 0 (cheap), both
 * bars updated in place — H and the fill limit move live; letting go draws the simulator anew. From real data the first
 * move switches to own test values, starting from the real ones.
 */
const MIN_PX = 10;                // a segment with something in it is never thinner than this (the owner, 2026-10-14)
const SEGS = ['nm', 'others', 'unw', 'wat'];
function picture(h, body) {
  const { T, el, fmt } = h;
  const box = el('div', 'jo-sim-pic');
  const gbText = (gb) => fmt.size(Math.max(0, gb) * GIB);
  const signed = (d) => `${d > 0 ? '+' : '−'}${gbText(Math.abs(d))}`;

  /** One bar with its title, its marks and its line of changes; handles: the «before» bar's */
  function row(id, handles) {
    const r = el('div', 'jo-sim-row');
    const head = el('div', 'jo-sim-rowhead');
    const changes = el('span', 'jo-sim-changes');
    head.append(el('b', '', T('sim.bar.' + id)), changes);
    const bar = el('div', 'jo-sim-bar' + (handles ? ' grips' : ''));
    const segs = {};
    for (const cls of SEGS) { segs[cls] = el('i', 'jo-sim-seg ' + cls); bar.appendChild(segs[cls]); }
    // a segment's words (GB and % of the pool): on hover with a mouse, on a tap with a finger (gone after a moment)
    const segTip = el('span', 'jo-sim-tip jo-sim-segtip');
    bar.appendChild(segTip);
    let tipTimer = 0;
    const showTip = (seg) => {
      clearTimeout(tipTimer);
      segTip.textContent = seg.dataset.words || '';
      const b = bar.getBoundingClientRect();
      const r = seg.getBoundingClientRect();
      segTip.style.display = 'block';
      const tw = segTip.offsetWidth;
      const mid = r.left - b.left + r.width / 2;
      segTip.style.left = `${Math.round(Math.max(tw / 2, Math.min(b.width - tw / 2, mid)))}px`;
    };
    const hideTip = () => { segTip.style.display = ''; };
    for (const seg of Object.values(segs)) {
      seg.addEventListener('pointerenter', (e) => { if (e.pointerType === 'mouse') showTip(seg); });
      seg.addEventListener('pointerleave', (e) => { if (e.pointerType === 'mouse') hideTip(); });
      seg.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') { showTip(seg); tipTimer = setTimeout(hideTip, 2500); } });
    }
    const marks = {};
    for (const [mid, below, left] of [['x', false, false], ['y', false, true], ['budget', false, true], ['h', true, true], ['limit', true, false]]) {
      const k = el('span', 'jo-sim-mark' + (below ? ' below' : ''));
      k.appendChild(el('span', ''));
      marks[mid] = { node: k, left };
      bar.appendChild(k);
    }
    const grips = {};
    if (handles) {
      for (const key of ['nm', 'end', 'x', 'y']) {
        const g = el('span', 'jo-sim-grip ' + (key === 'x' || key === 'y' ? 'top' : 'mid'));
        g.dataset.drag = key;
        g.tabIndex = 0;
        g.setAttribute('role', 'slider');
        g.setAttribute('aria-label', T('sim.drag.' + key));
        const tip = el('span', 'jo-sim-tip');
        g.appendChild(tip);
        grips[key] = { node: g, tip };
        bar.appendChild(g);
      }
    }
    r.append(head, bar);
    /** m: this bar's numbers; prev: what it is compared with (null: nothing — day 0's start) */
    r.update = (m, prev) => {
      const w = V.sim.w;
      const pool = w.cfg.pool;
      const y = Math.max(0, w.cfg.x - w.cfg.offset);
      const val = { nm: m.nm, others: m.others, unw: m.jack - m.watched, wat: m.watched };
      const wid = segWidths([...SEGS.map((c) => val[c]), Math.max(0, pool - m.used)], MIN_PX / Math.max(200, bar.clientWidth || 800));
      const titles = { nm: 'sim.bar.nm', others: 'sim.bar.others', unw: 'sim.bar.unw', wat: 'sim.bar.wat' };
      const ofPool = (gb) => T('sim.of_pool', { pct: fmt.number(Math.max(0, gb) * 100 / pool, 1) });
      SEGS.forEach((c, i) => {
        segs[c].style.width = `${(wid[i] * 100).toFixed(3)}%`;
        segs[c].dataset.words = `${T(titles[c])} · ${gbText(val[c])} · ${ofPool(val[c])}`;
        segs[c].setAttribute('aria-label', segs[c].dataset.words);
      });
      bar.setAttribute('role', 'img');
      bar.setAttribute('aria-label', `${T('sim.bar.' + id)}: ${T('sim.bar_label', { pct: r1(m.used * 100 / pool) })}`);
      // H and the fill limit from where his part starts (NM + others) — but he fills only up to Y: a marker past Y stands
      // at Y and says so («… (to Y)»), so nobody reads a fill area beyond it
      const from = m.nm + m.others;
      const budget = w.cfg.budget > 0 ? w.cfg.budget : null;
      const at = { x: m.xb, y: m.yb, h: Math.min(from + m.half, m.yb), limit: Math.min(from + m.jackLimit, m.yb), budget: Math.min(from + (budget || 0), m.yb) };
      const toY = (word, raw) => (raw > m.yb + 0.05 ? T('sim.mark.to_y', { what: word }) : word);
      const words = { x: `X ${w.cfg.x} %`, y: `Y ${y} %`, h: toY('H', from + m.half), limit: toY(T('sim.mark.limit'), from + m.jackLimit),
        budget: budget ? toY(T('sim.mark.budget'), from + budget) : '' };
      // H and the fill limit on the same spot (within a few pixels): one label for both, on the limit's line — apart, each
      // its own (sideLabels() staggers them when they would collide)
      const close = Math.abs(gbFrac(at.h, pool) - gbFrac(at.limit, pool)) * (bar.clientWidth || 800) < 6;
      if (close) { words.limit = toY(`H · ${T('sim.mark.limit')}`, from + Math.max(m.half, m.jackLimit)); words.h = ''; }
      for (const [mid, mk] of Object.entries(marks)) {
        mk.node.style.display = mid === 'budget' && !budget ? 'none' : '';      // the budget's mark only by budget
        mk.node.style.left = `${(gbFrac(at[mid], pool) * 100).toFixed(2)}%`;
        mk.node.firstChild.textContent = words[mid];
        mk.frac = gbFrac(at[mid], pool);
      }
      sideLabels(bar, marks);
      // what changed against the bar before it (the true values)
      if (!prev) changes.textContent = '';
      else {
        const pv = { nm: prev.nm, others: prev.others, unw: prev.jack - prev.watched, wat: prev.watched };
        const list = SEGS.filter((c) => Math.abs(val[c] - pv[c]) >= 0.05).map((c) => `${T(titles[c])} ${signed(val[c] - pv[c])}`);
        changes.textContent = list.length ? list.join(' · ') : T('sim.bar.same');
      }
      // the handles on the segments' edges as drawn
      const edge = { nm: wid[0], end: wid[0] + wid[1] };
      const vals = { nm: [m.nm, 0, m.nm + m.others], end: [m.nm + m.others, m.nm, pool], x: [w.cfg.x, y + 1, 100], y: [y, 1, w.cfg.x - 1] };
      for (const [key, g] of Object.entries(grips)) {
        const [now, lo, hi] = vals[key];
        const sizeKey = key === 'nm' || key === 'end';
        g.node.style.left = sizeKey ? `${(edge[key] * 100).toFixed(3)}%` : `${now}%`;
        const text = sizeKey ? `${gbText(key === 'nm' ? m.nm : m.others)} · ${ofPool(key === 'nm' ? m.nm : m.others)}` : `${now} %`;
        g.node.setAttribute('aria-valuenow', String(r1(now)));
        g.node.setAttribute('aria-valuemin', String(r1(lo)));
        g.node.setAttribute('aria-valuemax', String(r1(hi)));
        g.node.setAttribute('aria-valuetext', `${T('sim.drag.' + key)}: ${text}`);
        g.tip.textContent = `${T('sim.drag.' + key)}: ${text}`;
        // the value's tip stays inside the bar (shown while dragging or focused: then it has a width)
        const tw = g.tip.offsetWidth;
        const px = (sizeKey ? edge[key] : now / 100) * (bar.clientWidth || 0);
        const shift = !tw ? 0 : Math.min(0, (bar.clientWidth || 0) - px - tw / 2) + Math.max(0, tw / 2 - px);
        g.tip.style.transform = `translateX(calc(-50% + ${Math.round(shift)}px))`;
      }
    };
    r.bar = bar;
    r.grips = grips;
    return r;
  }

  /**
   * Each mark's label on its own side of the line (Y and H to the left, X and the fill limit to the right) — unless it
   * would be cut off at the bar's end: then the other side. Measured once laid out; before that, by the 90 % rule.
   */
  function sideLabels(bar, marks) {
    const width = bar.clientWidth;
    for (const mk of Object.values(marks)) {
      const label = mk.node.firstChild;
      const lw = label.offsetWidth;
      if (!width || !lw) { mk.node.classList.toggle('flip', mk.left || mk.frac > 0.9); continue; }
      const px = mk.frac * width;
      const fitsLeft = px - lw - 4 >= 0;
      const fitsRight = px + lw + 4 <= width;
      mk.node.classList.toggle('flip', mk.left ? fitsLeft || !fitsRight : !fitsRight && fitsLeft);
    }
    // H's and the fill limit's labels (both below) would overlap: the limit's one row lower
    const a = marks.h && marks.h.node.firstChild;
    const b = marks.limit && marks.limit.node.firstChild;
    let hit = false;
    if (width && a && b && a.textContent && b.textContent && marks.h.node.style.display !== 'none') {
      marks.limit.node.classList.remove('stagger');
      const ra = a.getBoundingClientRect();
      const rb = b.getBoundingClientRect();
      hit = ra.right + 4 > rb.left && rb.right + 4 > ra.left;
    }
    if (marks.limit) marks.limit.node.classList.toggle('stagger', hit);
    bar.classList.toggle('staggered', hit);
  }

  const eve = row('before', true);
  const morn = row('after', false);
  box.append(eve, morn);
  const legend = el('div', 'jo-sim-legend');
  const keys = {};
  for (const [cls, key] of [['nm', 'sim.bar.nm'], ['others', 'sim.bar.others'], ['unw', 'sim.bar.unw'], ['wat', 'sim.bar.wat'], ['free', 'sim.bar.free']]) {
    const it = el('span', 'jo-sim-key');
    const text = el('span', '');
    it.append(el('i', 'jo-sim-seg ' + cls), text);
    keys[cls] = [text, key];
    legend.appendChild(it);
  }
  box.appendChild(legend);
  const numbers = el('p', 'role jo-sim-numbers');
  box.appendChild(numbers);
  // the warning of the day on display, in plain words (what was sent, and why) — as the office's warnings look
  const warnMsg = el('p', 'callout warn jo-sim-warnmsg');
  box.insertBefore(warnMsg, legend);

  /** Both bars, their marks, the handles and the words from the simulation as it stands (in place — during a drag too) */
  function update() {
    const side = V.sim.sides.new;
    const tag = body.querySelector('.jo-sim-day');       // the day shown (a drag starts over at day 0)
    if (tag && !V.shown) tag.textContent = T('sim.day', { n: V.sim.w.day });
    eve.update(side.eve, side.prevMorn);
    morn.update(side.morn, side.eve);
    const why = side.warnWhy;
    warnMsg.style.display = why ? '' : 'none';
    warnMsg.textContent = !why ? '' : T('sim.warn_msg', { why: why.causes.map((c) => T('sim.warn_why.' + c, { nm: gbText(why.nm), room: gbText(why.room),
      writes: gbText(why.writes), promised: gbText(why.promised), have: gbText(why.have) })).join('; ') });
    const m = side.morn;
    const of = (x) => ({ nm: x.nm, others: x.others, unw: x.jack - x.watched, wat: x.watched, free: V.sim.w.cfg.pool - x.used });
    const a = of(side.eve);
    const b = of(m);
    for (const [cls, [text, key]] of Object.entries(keys)) {
      text.textContent = Math.abs(a[cls] - b[cls]) < 0.05 ? `${T(key)} ${gbText(b[cls])}` : `${T(key)} ${gbText(a[cls])} → ${gbText(b[cls])}`;
    }
    numbers.textContent = T('sim.numbers', { room: gbText(m.room), h: gbText(m.half), d: gbText(m.halfD),
      limit: gbText(m.jackLimit), olimit: gbText(m.othersLimit), want: gbText(m.want) });
  }
  update();
  requestAnimationFrame(update);       // once the bars have their width: the few pixels a small segment keeps
  box.update = update;                 // the play moves the bars in place (their widths change: the transition shows it)

  /** After a change: everything below the bars anew (the checks, the days), the handle that moved keeps the focus */
  const settle = (key) => {
    redraw(h, body);
    const g = body.querySelector(`[data-drag="${key}"]`);
    if (g) g.focus({ preventScroll: true });
  };
  for (const [key, { node: g }] of Object.entries(eve.grips)) {
    // pointer: from what the «before» bar shows now, a new start at every move (one per frame), both bars in place
    g.addEventListener('pointerdown', (ev) => {
      if (ev.button !== undefined && ev.button !== 0) return;
      ev.preventDefault();
      const rect = eve.bar.getBoundingClientRect();
      const base = shownStart();
      let frame = 0;
      let last = ev.clientX;
      g.setPointerCapture(ev.pointerId);
      g.classList.add('dragging');
      const apply = () => {
        frame = 0;
        const frac = (last - rect.left) / Math.max(1, rect.width);
        const value = key === 'x' || key === 'y' ? frac * 100 : barGb(frac, base.pool);
        setCfg(h, dragSet(base, key, value));
        update();
      };
      g.onpointermove = (e) => { last = e.clientX; if (!frame) frame = requestAnimationFrame(apply); };
      g.onpointerup = g.onpointercancel = () => {
        if (frame) { cancelAnimationFrame(frame); apply(); }
        g.onpointermove = g.onpointerup = g.onpointercancel = null;
        g.classList.remove('dragging');
        settle(key);
      };
    });
    // keys: arrows a step (1 % or about 1 % of the pool), Page Up/Down ten, Home/End the ends
    g.addEventListener('keydown', (ev) => {
      const sizeKey = key === 'nm' || key === 'end';
      const base = shownStart();
      const step = sizeKey ? Math.max(1, Math.round(base.pool / 100)) : 1;
      const now = { nm: base.nm, end: base.nm + base.others0, x: base.x, y: base.x - base.offset }[key];
      const to = { ArrowLeft: now - step, ArrowDown: now - step, ArrowRight: now + step, ArrowUp: now + step, PageDown: now - 10 * step,
        PageUp: now + 10 * step, Home: -1e9, End: 1e9 }[ev.key];
      if (to === undefined) return;
      ev.preventDefault();
      setCfg(h, dragSet(base, key, to));
      settle(key);
    });
  }
  // the hint, and back to the server's values once a handle (or a slider) left them
  const foot = el('div', 'toolbar jo-sim-dragfoot');
  foot.appendChild(el('span', 'role', T('sim.drag_hint')));
  const lv = V.measured || (h.base() || {}).level;
  if (lv && V.src === 'test') {
    foot.appendChild(h.button(T('sim.back_real'), 'small plain', () => { V.src = 'real'; V.cfg = baseCfg(h, 'real'); restart(h); redraw(h, body); }));
  }
  box.appendChild(foot);
  return box;
}

/** The five checks, both rule sets side by side: green, or red from day n */
function checks(h) {
  const { T, el } = h;
  const t = el('table', 'jo-sim-table');
  const head = el('tr');
  head.append(el('th', '', T('sim.checks')), el('th', '', ''));
  t.appendChild(head);
  for (const k of INVARIANTS) {
    const tr = el('tr', V.stopNote && V.stopNote.red.includes(k) ? 'jo-sim-hot' : '');      // the check that stopped the play
    const name = el('td', '', T('sim.inv.' + k));
    name.title = T('sim.inv.' + k + '_tip');
    tr.appendChild(name);
    const s = V.sim.sides.new.inv[k];
    const td = el('td');
    // «told at once» (the pool over X after a night, reported): expected by the rules — said, not red
    if (s.ok && s.told != null) td.appendChild(el('span', 'chip warn', T('sim.told_from', { day: s.told })));
    else td.appendChild(el('span', 'chip ' + (s.ok ? 'ok' : 'danger'), s.ok ? T('sim.green') : T('sim.red_from', { day: s.first })));
    tr.appendChild(td);
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
    if (r.told) td.appendChild(el('span', 'chip warn', T('sim.told')));
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
  head.append(el('th', '', T('sim.col.scenario')), el('th', '', T('sim.col.result')), el('th', '', ''));
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
    tr.append(el('td', '', `${T('sim.sc.' + res.id)} · ${h.nOf('days', res.days)}`), cell(res.r.new));
    const td = el('td');
    td.appendChild(h.button(T('sim.show'), 'small plain', () => {
      stopPlay();
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

Office.streamerMath = math;
Office.streamerSim = { view, math };
if (typeof Office.streamerLoaded === 'function') Office.streamerLoaded();
})();
