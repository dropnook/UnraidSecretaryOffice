#!/usr/bin/env node
// The click test: every desk page of the office, built from this repository's files (tests/ui/harness.mjs, a stub state
// per desk from tests/ui/states/), in a headless Chrome with a throwaway profile - both themes (Dark, Light) at 1440 and
// 375 px. On each page it clicks what only opens or closes something - every <summary>, every row that unfolds, every
// folding group head, every tile (button.card, and button.stat with aria-pressed: a number tile that filters or shows),
// every other control with aria-expanded - and checks:
//   - the clicked one changes state (opens or closes),
//   - nothing else that folds changes with it (another <details>, row, group, tile - a tile's siblings excepted:
//     tiles are one choice of several),
//   - it and everything else stay as they were when the desk draws itself anew right after the click (the messenger's
//     word, which every answer of the agent brings - also the answer to a post the click itself made),
//   - all of it stays as clicked after 1.5 s, and after the desk draws itself anew on its own (the minute's poll with a
//     newer look, and the messenger's word once more),
//   - what comes back into view (a tile's section shown again) shows as it was left,
//   - a tile with data-filters="<id>" (core.js Office.stat / Office.tile): while it is on, #<id> shows the filter bar's
//     note line (.filter-note, visible); off, the line is gone (unless another tile filtering #<id> is on) - the note
//     line and the rows under #<id> are the tile's own change, not another's,
//   - a tile of the section switcher (core.js Office.sections - its row stands beside [data-sections-body], tiles v2):
//     on, it carries aria-pressed=true, .tile-on and a visible .tile-mark; the body holds its section alone (one
//     .section-head) and of all section heads under the row only that one is in the window - after the click's move
//     (a desktop brings it in, a phone scrolls it to the top); off, no tile glows and the body holds every section the
//     row's tiles show, in their order (a desk without [data-sections-body] is checked as before),
//   - no console error, no page error, no horizontal scrolling (after the first paint and after each round of clicks).
// Two rounds: every candidate clicked once (opened or closed) - first what the page shows, then what that brought
// into view (a row's details, a group's rows), the tiles last -, then once more back, in reverse. What opens a dialog,
// a menu or another page isn't a toggle: closed again, counted as skipped. Actions that only post (buttons without
// aria-expanded) are never clicked; a button.stat without aria-pressed opens something else (a dialog) - never clicked,
// counted as skipped. Many alike (rows of one list) are capped: 5 of a kind per section, 60 a page - never the tiles
// of a section switcher (the row is the page's table of contents).
// Every page in a context of its own (a fresh profile: nothing remembered from another page).
//
//   node tools/ui-clicks.mjs [--only advisor,backup/setup] [--themes dark,light] [--widths 1440,375] [--jobs 6] [-v]
//                            [--states <folder>]
//   --states: the desks' states from that folder instead of tests/ui/states (a server's real state files - the scenarios,
//   tools/scenarios/page.sh); only the pages are clicked then (the routes walk, the dialog stack and the first start
//   need the stub states and are left out).
//   UC_DEBUG=1 …   prints every click and the state it left
//
// Needs node and playwright-core (USO_PLAYWRIGHT=<its folder>, else found in node's own paths or npx's cache) and a
// Chrome (USO_CHROME=<binary>, else Google Chrome in /Applications, else playwright's own Chromium).
// Exit: 0 all green, 1 a failure (the table says which), 2 wrong call, 3 playwright-core or Chrome missing (the caller
// may skip with a note - tools/ui-clicks.sh, tools/release.sh).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..');
const require = createRequire(import.meta.url);

// ------------------------------------------------------------------ the call
const ROUTES = ['caretaker', 'backup', 'backup/setup', 'restore', 'snapshot', 'cleanup/tidy', 'cleanup/where', 'logs', 'logs/setup', 'watchman', 'advisor', 'jack', 'jack/setup', 'moverelli', 'moverelli/setup'];
const opt = { only: null, themes: ['dark', 'light'], widths: [1440, 375], jobs: 6, verbose: false, perGroup: 5, perPage: 60, states: null };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  if (a === '--only') opt.only = val().split(',').filter(Boolean);
  else if (a === '--themes') opt.themes = val().split(',').filter((x) => ['dark', 'light'].includes(x));
  else if (a === '--widths') opt.widths = val().split(',').map(Number).filter((n) => n >= 320);
  else if (a === '--jobs') opt.jobs = Math.max(1, Number(val()) || 1);
  else if (a === '-v' || a === '--verbose') opt.verbose = true;
  else if (a === '--states') opt.states = path.resolve(val());
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/ui-clicks.mjs [--only <route>,…] [--themes dark,light] [--widths 1440,375] [--jobs n] [-v] [--states <folder>]');
  console.error('routes: ' + ROUTES.join(' '));
  process.exit(code);
}
const routes = opt.only ? opt.only.map((r) => r.replace(/^#?\/?/, '')) : ROUTES;
if (!routes.length || !opt.themes.length || !opt.widths.length) usage();
if (opt.states && !fs.existsSync(opt.states)) {
  console.error(`ui clicks: no states folder «${opt.states}»`);
  usage();
}
for (const r of routes) {
  if (!/^[a-z][a-z0-9_-]*(\/[A-Za-z0-9:._-]+)*$/.test(r) || !fs.existsSync(path.join(REPO, 'public', 'desks', r.split('/')[0], 'desk.js'))) {
    console.error(`ui clicks: no desk page «${r}»`);
    usage();
  }
}

// ------------------------------------------------------------------ playwright-core and a Chrome
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try {
    for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core'));
  } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) {
    try { return require(t); } catch (e) { /* the next */ }
  }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) {
  console.log('ui clicks: playwright-core not found (USO_PLAYWRIGHT=<folder>, or `npx playwright-core --version` once) - not run');
  process.exit(3);
}
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p)) || (() => { try { const p = pw.chromium.executablePath(); return p && fs.existsSync(p) ? p : null; } catch (e) { return null; } })();
if (!chrome) {
  console.log('ui clicks: no Chrome found (USO_CHROME=<binary>) - not run');
  process.exit(3);
}

const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));

// ------------------------------------------------------------------ in the page
// Installed into every page: finds the candidates, gives each a key that survives the desk drawing itself anew (its
// place, its kind, its words without numbers, its rank among its likes), reads what is open.
const PAGE_HELPERS = () => {
  const desk = () => document.getElementById('sso-desk');
  const visible = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; };
  const words = (s) => String(s || '').toLowerCase().replace(/[^\p{L} ]+/gu, ' ').replace(/\s+/g, ' ').trim().slice(0, 40);
  function kindOf(e) {
    if (e.matches('details > summary')) return 'summary';
    if (e.matches('.unfolds')) return 'row';
    if (e.matches('.group-head')) return 'group';
    if (e.matches('button.card, button.stat[aria-pressed]')) return 'tile';
    if (e.matches('[aria-expanded]')) return 'expand';
    return null;
  }
  function label(e, kind) {
    if (kind === 'row') return words((e.querySelector('.row-name, td, th') || e).textContent);
    if (kind === 'group') return words((e.querySelector('.group-title, .group-name, strong, span') || e).textContent);
    if (kind === 'expand') return (e.id || e.className || e.tagName).toString().slice(0, 40);
    // a tile's words without its mark («Open», «Filter on» - there only while it is on): one key on and off
    // (wherever it stands: a number tile's leads its label, a card's ends its head line)
    const c = e.cloneNode(true);
    c.querySelectorAll('.tile-mark').forEach((m) => m.remove());
    return words(c.textContent);
  }
  function all() {
    const d = desk();
    const out = [];
    const ranks = new Map();
    d.querySelectorAll('details > summary, .unfolds, .group-head, button.card, button.stat[aria-pressed], [aria-expanded]').forEach((e) => {
      const kind = kindOf(e);
      if (!kind) return;
      // a control inside a row that unfolds is the row's own (its name, «Details»): the row stands for it
      if (kind === 'expand' && e.closest('.unfolds')) return;
      if (kind === 'expand' && e.closest('.group-head')) return;
      const place = e.closest('[data-place]');
      const base = `${kind}|${place ? place.dataset.place : ''}|${label(e, kind)}`;
      const n = (ranks.get(base) || 0) + 1;
      ranks.set(base, n);
      const section = e.closest('section, .section');
      const sectionPlace = section && section.querySelector('[data-place]');
      // a tile of the section switcher (Office.sections) is never capped: the row is the page's table of contents
      const v2 = kind === 'tile' && !!e.dataset.tile && !!e.parentElement && !!e.parentElement.parentElement
        && [...e.parentElement.parentElement.children].some((x) => x.matches('[data-sections-body]'));
      out.push({ e, kind, key: `${base}|${n}`, group: `${kind}|${sectionPlace ? sectionPlace.dataset.place : ''}`, v2 });
    });
    return out;
  }
  function stateOf(e, kind) {
    if (kind === 'summary') return e.parentElement.open ? 'open' : 'closed';
    if (kind === 'row') return e.classList.contains('open') || e.getAttribute('aria-expanded') === 'true'
      || !!e.querySelector(':scope [aria-expanded="true"]') ? 'open' : 'closed';
    if (kind === 'group') { const g = e.closest('.group'); return g && g.classList.contains('closed') ? 'closed' : 'open'; }
    if (kind === 'tile') return e.classList.contains('active') || e.getAttribute('aria-pressed') === 'true' ? 'on' : 'off';
    return e.getAttribute('aria-expanded') === 'true' ? 'open' : 'closed';
  }
  window.__uc = {
    list: () => all().filter((c) => visible(c.e) && !c.e.disabled && !c.e.closest('[disabled]'))
      .map(({ kind, key, group, v2 }) => ({ kind, key, group, v2 })),
    snap: () => Object.fromEntries(all().map((c) => [c.key, stateOf(c.e, c.kind)])),
    // mark the one to click (and where: a row's name, never its chips or buttons) - false when it isn't there
    mark(key) {
      document.querySelectorAll('[data-uc]').forEach((x) => x.removeAttribute('data-uc'));
      const c = all().find((x) => x.key === key);
      if (!c || !visible(c.e)) return false;      // not there, or hidden by an earlier click (a row of a group it closed)
      let t = c.e;
      if (c.kind === 'row') t = c.e.querySelector('.row-name') || c.e.querySelector('td, th') || c.e;
      if (c.kind === 'group') t = c.e.querySelector('.group-title, .group-name') || c.e;
      t.setAttribute('data-uc', '1');
      return true;
    },
    sibling(a, b) {          // two tiles of one choice: the same parent
      const list = all();
      const x = list.find((c) => c.key === a);
      const y = list.find((c) => c.key === b);
      return !!(x && y && x.kind === 'tile' && y.kind === 'tile' && x.e.parentElement === y.e.parentElement);
    },
    filtered(a, b) {         // b lies in the list tile a filters (data-filters): its rows, its note line
      const list = all();
      const x = list.find((c) => c.key === a);
      const y = list.find((c) => c.key === b);
      const box = x && x.kind === 'tile' && x.e.dataset.filters ? document.getElementById(x.e.dataset.filters) : null;
      return !!(box && y && box.contains(y.e));
    },
    // the note line of the list a tile filters: null (the tile filters nothing), else whether the list is there, the
    // line shows in it, and another tile filtering that list is on
    filterNote(key) {
      const c = all().find((x) => x.key === key);
      if (!c || c.kind !== 'tile' || !c.e.dataset.filters) return null;
      const id = c.e.dataset.filters;
      const box = document.getElementById(id);
      const note = !!box && [...box.querySelectorAll('.filter-note')].some((n) => !n.closest('[hidden]') && visible(n));
      const others = [...document.querySelectorAll('[data-filters]')].some((t) => t !== c.e && t.dataset.filters === id
        && (t.getAttribute('aria-pressed') === 'true' || t.classList.contains('active')));
      return { id, list: !!box, note, others };
    },
    // a tile of the section switcher (Office.sections: its row's parent holds [data-sections-body]): what the v2 rule
    // asks after its click - null for any other tile
    sections(key) {
      const c = all().find((x) => x.key === key);
      if (!c || c.kind !== 'tile' || !c.e.dataset.tile) return null;
      const grid = c.e.parentElement;
      const body = grid && grid.parentElement ? [...grid.parentElement.children].find((x) => x.matches('[data-sections-body]')) : null;
      if (!body) return null;
      const menu = document.getElementById('menu');
      const above = menu && /^(sticky|fixed)$/.test(getComputedStyle(menu).position) ? menu.getBoundingClientRect().bottom : 0;
      const inView = (e) => { const r = e.getBoundingClientRect(); return r.bottom > above + 1 && r.top < innerHeight - 1; };
      const name = (h) => words(h.textContent).slice(0, 30);
      const heads = [...body.querySelectorAll('.section-head')].filter(visible);
      const under = [...desk().querySelectorAll('.section-head')].filter((h) => visible(h) && (grid.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING));
      const tiles = [...grid.children].filter((x) => x.dataset.tile);
      const shows = [];
      tiles.forEach((x) => { if (x.dataset.shows && !shows.includes(x.dataset.shows)) shows.push(x.dataset.shows); });
      const mark = c.e.querySelector('.tile-mark');
      return {
        tile: c.e.dataset.tile, on: c.e.getAttribute('aria-pressed') === 'true', glow: c.e.classList.contains('tile-on'), mark: !!mark && visible(mark),
        shows: c.e.dataset.shows, active: body.dataset.active || '', drawn: (body.dataset.sections || '').split(' ').filter(Boolean), all: shows,
        glowing: tiles.filter((x) => x.classList.contains('tile-on')).length, heads: heads.length,
        headInView: heads.length === 1 && inView(heads[0]), othersInView: under.filter((h) => !heads.includes(h) && inView(h)).map(name),
      };
    },
    // number tiles that open something else (no aria-pressed): never clicked, counted as skipped
    openers: () => [...desk().querySelectorAll('button.stat:not([aria-pressed])')].filter((e) => visible(e)).length,
    within(a, b) {           // b lies inside a's own part (an unfolded row's details, a group's rows, a details' body)
      const list = all();
      const x = list.find((c) => c.key === a);
      const y = list.find((c) => c.key === b);
      if (!x || !y) return false;
      const host = x.kind === 'summary' ? x.e.parentElement : x.kind === 'group' ? (x.e.closest('.group') || x.e) : x.e;
      return host !== y.e && host.contains(y.e);
    },
    scroll() {
      const w = document.documentElement.clientWidth;
      const sw = document.documentElement.scrollWidth;
      if (sw <= w + 1) return null;
      let worst = null;
      document.querySelectorAll('#sso *').forEach((e) => {
        const r = e.getBoundingClientRect();
        if (r.width && r.right > w + 1 && (!worst || r.right > worst.r)) worst = { r: r.right, what: `${e.tagName.toLowerCase()}.${String(e.className).split(' ').join('.')}` };
      });
      return `${sw} px wide in ${w} px${worst ? ` (${worst.what}, right edge ${Math.round(worst.r)})` : ''}`;
    },
    busy: () => !!(window.Office && (Office.dialogOpen() || Office.menuOpen() || (Office.paletteOpen && Office.paletteOpen()))),
  };
};

// ------------------------------------------------------------------ one page
async function runPage(browser, harness, route, theme, width) {
  const t0 = Date.now();
  const res = { route, theme, width, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US', deviceScaleFactor: 1 });
  await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, theme);
  const page = await ctx.newPage();
  const errors = [];
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => {
    const at = (/\/(desks\/[^?]+|assets\/[^?]+)\?[^:]*:(\d+)/.exec(String(e.stack || '')) || []).slice(1).join(':');
    errors.push(`page error: ${String(e.message).slice(0, 160)}${at ? ` (${at})` : ''}`);
  });
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  try {
    await page.goto(`${harness.url}#/${route}`, { waitUntil: 'load' });
    await page.waitForFunction(() => window.Office && Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), null, { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(1200);
    await page.evaluate(PAGE_HELPERS);
    const wide = await page.evaluate(() => __uc.scroll());
    if (wide) fail(`horizontal scrolling after the first paint: ${wide}`);

    // the candidates, capped per section and kind
    const per = new Map();
    const list = [];
    const known = new Set();
    const pick = (found) => {
      const picked = [];
      for (const c of found) {
        if (known.has(c.key)) continue;
        known.add(c.key);
        const n = (per.get(c.group) || 0) + 1;
        per.set(c.group, n);
        if (!c.v2 && (n > opt.perGroup || list.length + picked.length >= opt.perPage)) { res.capped++; continue; }
        picked.push(c);
      }
      return picked;
    };
    const settle = async (ms) => { await page.waitForTimeout(ms); };
    // every key's state when last seen: what comes back into view (a tile's section shown again, a row unfolded
    // again) shows as it was left - the desk drew it anew, the user's choice stays
    const lastSeen = new Map();
    let shownBefore = new Set();
    const snap = async () => {
      const now = await page.evaluate(() => __uc.snap());
      for (const [k, v] of Object.entries(now)) {
        if (!shownBefore.has(k) && lastSeen.has(k) && lastSeen.get(k) !== v && clicked.has(k)) {
          fail(`${kindOf(k)} «${short(k)}» came back into view ${v}, was left ${lastSeen.get(k)}`);
        }
        lastSeen.set(k, v);
      }
      shownBefore = new Set(Object.keys(now));
      return now;
    };
    const clicked = new Set();
    const short = (key) => key.split('|').filter(Boolean).slice(0, 3).join(' · ');
    const kindOf = (key) => key.split('|')[0];
    let expected = null;      // the whole page's folding as the clicks left it
    const dropped = new Set(); // no toggle after all (a dialog, a menu, another page) or broken: not clicked again

    async function clickOne(c, round) {
      if (dropped.has(c.key)) return;
      const before = await snap();
      if (!(c.key in before)) { if (round === 2) res.hidden++; return; }     // hidden by another's click (a tile's section)
      if (!(await page.evaluate((k) => __uc.mark(k), c.key))) return;
      const hash = await page.evaluate(() => location.hash);
      try {
        await page.click('[data-uc]', { timeout: 3000 });
      } catch (e) {
        fail(`${c.kind} «${short(c.key)}»: could not be clicked (${String(e.message).split('\n')[0].slice(0, 80)})`);
        dropped.add(c.key);
        return;
      }
      await settle(250);
      // a dialog, a menu or another page: no toggle - put back, skipped
      const away = await page.evaluate((h) => location.hash.split('/')[1] !== h.split('/')[1], hash);
      if (await page.evaluate(() => __uc.busy()) || away) {
        await page.keyboard.press('Escape');
        await page.evaluate(() => { if (Office.dialogOpen() && Office.closeDialog) Office.closeDialog(); });
        if (away) { await page.evaluate((h) => { location.hash = h; }, hash); await settle(800); }
        await settle(200);
        dropped.add(c.key);
        res.skipped++;
        return;
      }
      const after = await snap();
      clicked.add(c.key);
      if (process.env.UC_DEBUG) console.log(`    [${route} ${theme} ${width}] round ${round} ${short(c.key)}: ${before[c.key]} → ${after[c.key]}`);
      if (round === 1) res.tested++;
      if (!(c.key in after)) {
        fail(`${c.kind} «${short(c.key)}»: gone after its click`);
        dropped.add(c.key);
        expected = after;
        return;
      }
      if (after[c.key] === before[c.key]) fail(`${c.kind} «${short(c.key)}» (${before[c.key]}): a click didn't change it - it stays ${after[c.key]}`);
      for (const k of Object.keys(after)) {
        if (k === c.key || !(k in before) || after[k] === before[k]) continue;
        // tiles are one choice: another one goes off when this one goes on
        if (c.kind === 'tile' && kindOf(k) === 'tile' && after[c.key] === 'on' && after[k] === 'off') continue;
        if (await page.evaluate(([a, b]) => __uc.sibling(a, b) || __uc.within(a, b) || __uc.filtered(a, b), [c.key, k])) continue;
        fail(`${c.kind} «${short(c.key)}»: its click changed ${kindOf(k)} «${short(k)}» too (${before[k]} → ${after[k]})`);
      }
      await noteCheck(c, after[c.key], 'its click');
      await sectionsCheck(c, 'its click', true);
      // the desk draws itself anew right after (as at every answer of the agent, which brings the messenger's word)
      await page.evaluate(() => Office.setAgent({ ...Office.agent }));
      await settle(80);
      const redrawn = await snap();
      if (c.key in redrawn) { await noteCheck(c, redrawn[c.key], 'the desk drew itself anew'); await sectionsCheck(c, 'the desk drew itself anew', false); }
      for (const [k, v] of Object.entries(after)) {
        if (!(k in redrawn) || redrawn[k] === v) continue;
        fail(k === c.key ? `${c.kind} «${short(c.key)}» (${v} by the click): ${redrawn[k]} again once the desk drew itself anew`
          : `${c.kind} «${short(c.key)}»: after its click the desk drew itself anew and ${kindOf(k)} «${short(k)}» went ${v} → ${redrawn[k]}`);
      }
      expected = redrawn;
    }
    // a tile that filters a list (data-filters): on → the list's note line shows, off → it is gone
    async function noteCheck(c, state, when) {
      if (c.kind !== 'tile') return;
      const n = await page.evaluate((k) => __uc.filterNote(k), c.key);
      if (!n) return;
      if (!n.list) fail(`tile «${short(c.key)}» filters #${n.id}, which isn't on the page (${when})`);
      else if (state === 'on' && !n.note) fail(`tile «${short(c.key)}» is on: no note line (.filter-note) in #${n.id} (${when})`);
      else if (state === 'off' && n.note && !n.others) fail(`tile «${short(c.key)}» is off: the note line in #${n.id} is still there (${when})`);
    }
    // a tile of the section switcher (tiles v2): on - it glows with its mark, its section alone under the row and in the
    // window (view: after the click's move); off - nothing glows, every section of the row in its order
    async function sectionsCheck(c, when, view) {
      if (c.kind !== 'tile') return;
      const v = await page.evaluate((k) => __uc.sections(k), c.key);
      if (!v) return;
      res.sections = (res.sections || 0) + (view ? 1 : 0);
      const at = `tile «${short(c.key)}» (${when})`;
      if (v.on) {
        if (!v.glow || !v.mark) fail(`${at}: on, but ${!v.glow ? 'no .tile-on' : ''}${!v.glow && !v.mark ? ' and ' : ''}${!v.mark ? 'no visible .tile-mark' : ''}`);
        if (v.glowing !== 1) fail(`${at}: ${v.glowing} tiles glow, one should`);
        if (v.active !== v.tile) fail(`${at}: on, but the body says «${v.active || 'nothing'}» is active`);
        if (v.drawn.join(' ') !== v.shows) fail(`${at}: the body holds «${v.drawn.join(' ') || 'nothing'}» - its section «${v.shows}» alone should stand there`);
        if (v.heads !== 1) fail(`${at}: ${v.heads} section heads in the body - one (its section's)`);
        if (view && v.heads === 1 && !v.headInView) fail(`${at}: its section's head isn't in the window after the click`);
        if (view && v.othersInView.length) fail(`${at}: other section heads under the row in the window too: ${v.othersInView.join(' / ')}`);
      } else {
        if (v.glow || v.mark || v.glowing) fail(`${at}: off, but ${v.glowing || 1} tile(s) still glow or carry the mark`);
        if (v.active) fail(`${at}: off, but the body says ${v.active} is active`);
        if (v.drawn.join(' ') !== v.all.join(' ')) fail(`${at}: nothing active - the body holds «${v.drawn.join(' ')}», the row's tiles show «${v.all.join(' ')}» (a section that draws nothing?)`);
      }
    }
    async function afterRound(round) {
      if (!expected) return;
      await settle(1500);
      const later = await snap();
      for (const [k, v] of Object.entries(expected)) {
        if (k in later && later[k] !== v) fail(`round ${round}: ${kindOf(k)} «${short(k)}» was ${v}, 1.5 s later ${later[k]}`);
      }
      // what the desk does on its own: the messenger's word (every answer of the agent brings it) and the minute's poll
      await page.evaluate(() => { Office.setAgent({ ...Office.agent }); if (Office.current && Office.current.poll) Office.current.poll(); });
      await settle(900);
      const anew = await snap();
      for (const [k, v] of Object.entries(expected)) {
        if (k in anew && anew[k] !== v) fail(`round ${round}: ${kindOf(k)} «${short(k)}» was ${v}, ${anew[k]} after the desk drew itself anew`);
      }
      expected = anew;
      const w = await page.evaluate(() => __uc.scroll());
      if (w) fail(`horizontal scrolling after round ${round} of clicks: ${w}`);
    }

    // round 1: each opened or closed - then what that showed (a row's details, a group's rows) the same way; tiles last
    // (a tile shows another section in place of this one)
    res.skipped += await page.evaluate(() => __uc.openers());
    const first = pick(await page.evaluate(() => __uc.list()));
    const tiles = first.filter((c) => c.kind === 'tile');
    const plain = first.filter((c) => c.kind !== 'tile');
    for (const c of plain) await clickOne(c, 1);
    const more = pick(await page.evaluate(() => __uc.list()));
    const inner = more.filter((x) => x.kind !== 'tile');
    for (const c of inner) await clickOne(c, 1);
    tiles.push(...more.filter((x) => x.kind === 'tile'));
    await afterRound(1);
    for (const c of tiles) await clickOne(c, 1);
    if (tiles.length) await afterRound(1);
    // round 2: back again - the tiles first (this section again), then the inner ones, then the rest
    for (const c of [...tiles].reverse()) await clickOne(c, 2);
    if (tiles.length) await afterRound(2);
    for (const c of [...plain, ...inner].reverse()) await clickOne(c, 2);
    await afterRound(2);
  } catch (e) {
    fail(`the run broke off: ${String(e.message).split('\n')[0].slice(0, 160)}`);
  }
  // a log dialog (status line + a long log) fits the window at every text size: title and «Close» in sight
  // (1.55.0 pushed «Close» below a short window - 2026-10-10); once per theme and width, on one page
  if (route === 'jack') {
    try {
      await page.setViewportSize({ width, height: 650 });
      for (const size of ['', 'medium', 'large']) {
        const r = await page.evaluate((size) => {
          const root = document.getElementById('sso');
          if (size) root.dataset.size = size; else delete root.dataset.size;
          const box = document.createElement('div');
          const st = document.createElement('p');
          st.textContent = 'status line';
          const pre = document.createElement('pre');
          pre.className = 'code';
          pre.textContent = Array.from({ length: 400 }, (_, i) => 'line ' + i).join('\n');
          box.append(st, pre);
          const d = Office.dialog({ title: 'log', body: box, wide: 'log' });
          const a = document.getElementById('sso-dialog').getBoundingClientRect();
          const f = document.getElementById('sso-dialog-foot').getBoundingClientRect();
          d.close();
          delete root.dataset.size;
          return { top: a.top, bottom: f.bottom, vh: innerHeight };
        }, size);
        if (r.top < 0 || r.bottom > r.vh + 1) fail(`log dialog cut at text size ${size || 'small'} (top ${Math.round(r.top)}, buttons end ${Math.round(r.bottom)} of ${r.vh})`);
      }
    } catch (e) {
      fail(`log dialog check: ${String(e.message).split('\n')[0].slice(0, 120)}`);
    }
  }
  for (const e of [...new Set(errors)].slice(0, 5)) fail(`console: ${e}`);
  await ctx.close().catch(() => {});
  res.ms = Date.now() - t0;
  return res;
}

// ------------------------------------------------------------------ all of them
const started = Date.now();
const harness = await startHarness(opt.states ? { states: opt.states } : {});
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check'] });
const jobs = [];
for (const route of routes) for (const theme of opt.themes) for (const width of opt.widths) jobs.push({ route, theme, width });
const results = [];
let next = 0;
await Promise.all(Array.from({ length: Math.min(opt.jobs, jobs.length) }, async () => {
  while (next < jobs.length) {
    const j = jobs[next++];
    const r = await runPage(browser, harness, j.route, j.theme, j.width);
    results.push(r);
    if (opt.verbose) console.log(`  ${r.fails.length ? 'FAIL' : 'ok  '} ${j.route} ${j.theme} ${j.width} (${(r.ms / 1000).toFixed(1)} s)`);
  }
}));
// ------------------------------------------------------------------ the dialog stack (2026-10-14)
// A dialog opened over a modal one stacks: Escape, a click outside and its «Cancel» close only it; the one below comes back
// as it was — the typed value, the focus, still modal (the page inert). A long dialog fits the window: its body scrolls to
// its end, the head and the foot stay in view — at 1280×720 and 390 px, and with the office's box transformed and pushed
// down (as Unraid's page may do: then «fixed» is that box, not the window). On Jack Streamer's «Set up» tab.
async function dialogStack(browser, harness) {
  const res = { route: 'dialog stack', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const fail = (what) => res.fails.push(what);
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
  // a modal dialog with a field (and, long: many lines) over the page
  const outer = (long) => page.evaluate((lines) => {
    const box = document.createElement('div');
    const i = document.createElement('input');
    i.className = 'input uso-stack-field';
    box.appendChild(i);
    for (let n = 0; n < lines; n++) { const p = document.createElement('p'); p.textContent = `line ${n + 1}`; box.appendChild(p); }
    Office.dialog({ title: 'outer', body: box, modal: true, wide: true, onEscape: () => {}, buttons: [{ text: 'Close' }] });
  }, long ? 120 : 0);
  try {
    await page.goto(`${harness.url}#/jack/setup/servers`, { waitUntil: 'load' });
    await page.waitForSelector('#sso-desk .jo-instance input', { timeout: 15000 });
    await outer(false);
    const name = page.locator('.dialog .uso-stack-field');
    await name.fill('Stacked');
    const look = () => page.evaluate(() => ({ outer: document.querySelector('#sso-dialog-title').textContent === 'outer',
      value: (document.querySelector('.dialog .uso-stack-field') || {}).value, inert: document.querySelector('#sso-desk').inert,
      open: !document.querySelector('#sso-dialog-backdrop').hidden, modal: document.querySelector('#sso-dialog-backdrop').classList.contains('modal'),
      title: document.querySelector('#sso-dialog-title').textContent, focus: !!(document.activeElement && document.activeElement.matches('.dialog .uso-stack-field')) }));
    const inner = () => page.evaluate(() => { Office.dialog({ title: 'inner', body: 'over the outer one', buttons: [{ text: 'Cancel' }] }); });
    for (const [how, end] of [['Escape', () => page.keyboard.press('Escape')], ['its «Cancel»', () => page.click('#sso-dialog-foot .btn')],
      ['a click outside', () => page.mouse.click(5, 890)]]) {
      await name.focus();
      await inner();
      await page.waitForTimeout(150);
      const over = await look();
      res.tested++;
      if (over.outer || over.title !== 'inner' || !over.inert) fail(`${how}: the inner dialog is not shown on top (${JSON.stringify(over)})`);
      await end();
      await page.waitForTimeout(200);
      const back = await look();
      if (!back.open || !back.outer || back.value !== 'Stacked' || !back.modal || !back.inert || !back.focus) {
        fail(`${how} closed more than the inner dialog, or the outer one did not come back as it was (${JSON.stringify(back)})`);
      }
    }
    for (const [w, hgt, shift] of [[1280, 720, false], [390, 844, false], [1280, 720, true]]) {
      await page.setViewportSize({ width: w, height: hgt });
      await page.goto(`${harness.url}#/jack/setup/servers`, { waitUntil: 'load' });
      await page.waitForSelector('#sso-desk .jo-instance input', { timeout: 15000 });
      if (shift) {
        await page.evaluate(() => { const o = document.querySelector('#sso'); o.style.transform = 'translateZ(0)'; o.style.marginTop = '160px'; window.dispatchEvent(new Event('resize')); });
      }
      await outer(true);
      await page.waitForTimeout(300);
      const fit = await page.evaluate(() => {
        const d = document.querySelector('#sso-dialog'), b = document.querySelector('#sso-dialog-body'), f = document.querySelector('#sso-dialog-foot'), h = document.querySelector('#sso-dialog-title');
        b.scrollTop = b.scrollHeight;
        const rd = d.getBoundingClientRect(), rf = f.getBoundingClientRect(), rh = h.getBoundingClientRect();
        return { scrolls: b.scrollHeight > b.clientHeight, end: Math.abs(b.scrollTop + b.clientHeight - b.scrollHeight) <= 2,
          inside: rd.top >= 0 && rd.bottom <= innerHeight + 1, head: rh.top >= 0, foot: rf.top >= 0 && rf.bottom <= innerHeight + 1 && !!f.querySelector('.btn') };
      });
      res.tested++;
      if (!fit.scrolls || !fit.end || !fit.inside || !fit.head || !fit.foot) fail(`long dialog at ${w}×${hgt}${shift ? ' (office box transformed, pushed down)' : ''}: ${JSON.stringify(fit)}`);
    }
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  for (const e of [...new Set(errors)]) fail(e);
  await ctx.close();
  return res;
}
// ------------------------------------------------------------------ a fresh server's first start (2026-10-16)
// No appdata and no system share (Docker never on): the messenger waits without his data folder. The page welcomes (the
// Team Lead's icon, both shares listed, the VMs' named, «Create missing standard shares», «First start» in the top line)
// instead of the careful «missing» text, both themes at 1440 and 375 px, nothing scrolls sideways; the click posts
// office.home_make for exactly those shares and shows the wave; once
// he is at work the page loads anew and the reception is back. A share that was there and is gone keeps the careful
// text, without a button. On a harness of its own (the messenger away there).
const NO_HOME = (fresh, answer) => ({ running: false, no_data: { array: 'Started', dir: '/mnt/user/appdata/UnraidSecretaryOffice/data',
  appdata: '/mnt/user/appdata', home: { share: 'appdata', fresh, pool: 'cache', answer: answer || null,
    missing: [{ share: 'appdata', fresh }, { share: 'system', fresh: true }], optional: ['domains', 'isos'] } } });
async function firstHome(browser) {
  const res = { route: 'first start', theme: 'both', width: 0, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({ agent: NO_HOME(true) });
  try {
    for (const theme of ['dark', 'light']) {
      for (const width of [1440, 375]) {
        h.setAgent(NO_HOME(true));
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
        await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, theme);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
        await page.goto(h.url, { waitUntil: 'load' });
        await page.waitForSelector('#sso-notice.home .home-acts .btn', { timeout: 15000 });
        const look = await page.evaluate(() => ({ title: (document.querySelector('#sso-notice.home strong') || {}).textContent,
          button: (document.querySelector('#sso-notice.home .home-acts .btn') || {}).textContent,
          label: document.querySelector('#sso-state-label').textContent, icon: !!document.querySelector('#sso-notice.home img.desk-icon'),
          listed: [...document.querySelectorAll('#sso-notice.home .home-list li strong')].map((n) => n.textContent).join(','),
          optional: /domains, isos/.test(document.querySelector('#sso-notice.home').textContent),
          wide: document.documentElement.scrollWidth > innerWidth + 1, cards: document.querySelectorAll('.reception .desk-card').length }));
        res.tested++;
        if (look.title !== 'Welcome to the office!' || look.button !== 'Create missing standard shares' || look.label !== 'First start' || !look.icon
          || look.cards || look.listed !== 'appdata,system' || !look.optional) {
          fail(`${theme} ${width}: the welcome is not as it should be (${JSON.stringify(look)})`);
        }
        if (look.wide) fail(`${theme} ${width}: the page scrolls sideways`);
        if (theme === 'dark' && width === 1440) {
          const before = h.posts.length;
          await page.click('#sso-notice.home .home-acts .btn');
          await page.waitForTimeout(300);
          const post = h.posts.slice(before).find((p) => p.action === 'office.home_make');
          const wave = await page.evaluate(() => { const w = document.querySelector('#sso-notice.home .home-acts .spin'); return !!w && getComputedStyle(w).display !== 'none' && !document.querySelector('#sso-notice.home .home-acts .btn'); });
          res.tested++;
          if (!post || JSON.stringify(post.data.shares) !== '["appdata","system"]') fail(`the click did not post office.home_make for appdata and system (${JSON.stringify(post || null)})`);
          if (!wave) fail('after the click: no wave, or the button still there');
          // he made it and is at work: the page loads anew, the reception is back
          h.setAgent(null);
          await page.waitForSelector('.reception .desk-card', { timeout: 15000 }).catch(() => fail('the office at work: the page did not come back to the reception'));
          res.tested++;
        }
        for (const e of [...new Set(errors)]) fail(`${theme} ${width}: ${e}`);
        await ctx.close();
      }
    }
    // gone, not new: the careful text, no button
    h.setAgent(NO_HOME(false));
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    await page.goto(h.url, { waitUntil: 'load' });
    await page.waitForSelector('#sso-notice:not([hidden]) strong', { timeout: 15000 });
    const gone = await page.evaluate(() => ({ title: document.querySelector('#sso-notice strong').textContent, button: !!document.querySelector('#sso-notice .btn'),
      label: document.querySelector('#sso-state-label').textContent }));
    res.tested++;
    if (gone.title !== 'The share appdata is missing.' || gone.button || gone.label !== 'appdata missing') fail(`gone: not the careful text (${JSON.stringify(gone)})`);
    await ctx.close();
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
// ------------------------------------------------------------------ the USO network's card while it is busy (2026-10-23)
// The card drawn with a join under way: the wave, the step and the time, no page error (1.71.0 rendered itself into a
// «Maximum call stack size exceeded»: the render armed the look, the look rendered), the page looks again on its own
// (POST caretaker.usonet_look) and flips to «in the network» with the answer - then looks no more. The failed and the
// leaving state drawn too (the log's lines, the wave).
async function netBusy(browser) {
  const res = { route: 'USO network card', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({});
  const base = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', 'caretaker.json'), 'utf8'));
  const withNet = (usonet) => ({ ...base, usonet: { ...base.usonet, ...usonet } });
  const now = Math.floor(Date.now() / 1000);
  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
    // joining, not in yet (JOINED=no while the helper is fetched): the wave, the step, the time
    h.setState('caretaker', withNet({ joined: false, state: 'joining', joining: 'join', ips: [], peers: [], job: { what: 'join', step: 'fetch', since: now - 47 }, slow: false, log: [] }));
    await page.goto(`${h.url}#/caretaker/partner`, { waitUntil: 'load' });
    const busy = await page.waitForSelector('.ct-net .ct-net-busy .spin', { state: 'attached', timeout: 15000 }).then(() => true, () => false);
    res.tested++;
    if (!busy) fail('joining: no wave on the card');
    const words = await page.evaluate(() => (document.querySelector('.ct-net .ct-net-busy') || {}).textContent || '');
    if (!/Fetching the helper/.test(words) || !/for \d+ s/.test(words)) fail(`joining: the step and the time are not said (${JSON.stringify(words)})`);
    const chip = await page.evaluate(() => { const c = document.querySelector('.ct-net .ct-subhead .chip'); return c ? { text: c.textContent.trim(), spin: !!c.querySelector('.spin') } : null; });
    if (!chip || !/joining/.test(chip.text) || !chip.spin) fail(`joining: the chip is not «joining» with the wave (${JSON.stringify(chip)})`);
    // the page looks again on its own; the look's answer (states/post/caretaker.usonet_look.json) says «in»: the card flips, the looks end
    const flipped = await page.waitForFunction(() => { const c = document.querySelector('.ct-net .ct-subhead .chip'); return c && /in the network/.test(c.textContent) && !document.querySelector('.ct-net .ct-net-busy'); }, null, { timeout: 12000 }).then(() => true, () => false);
    res.tested++;
    const looks = () => h.posts.filter((p) => p.action === 'caretaker.usonet_look').length;
    if (!flipped) fail(`joining → in: the card did not flip on its own (looks posted: ${looks()})`);
    const seen = looks();
    await page.waitForTimeout(5500);
    res.tested++;
    if (looks() !== seen) fail(`in the network: the page still looks at it (${looks() - seen} more looks)`);
    if (seen < 1) fail('the page never looked at the network while it was joining');
    // failed: the why and the log's lines; leaving: the wave; starting: the chip with the wave - each drawn without an error
    for (const [name, usonet, want] of [
      ['failed', { joined: false, state: 'failed', why: 'key_refused', job: null, slow: false, log: ['2026-10-23 13:40:02 helper started (PID 4242)', 'control: the key was refused'] }, '.ct-net .callout.warn, .ct-net pre.code'],
      ['slow', { joined: true, state: 'joining', joining: 'join', job: { what: 'join', step: 'login', since: now - 260 }, slow: true, log: ['control: no response yet'] }, '.ct-net .callout.warn'],
      ['leaving', { joined: true, state: 'leaving', joining: 'leave', job: { what: 'leave', step: 'leave', since: now - 5 }, slow: false, log: [] }, '.ct-net .ct-net-busy .spin'],
      ['starting', { joined: true, state: 'starting', joining: null, job: null, slow: false, log: [] }, '.ct-net .ct-subhead .chip .spin'],
      ['down', { joined: true, state: 'down', joining: null, job: null, since: now - 20, slow: false, log: [] }, '.ct-net .callout.warn'],
    ]) {
      h.setState('caretaker', withNet(usonet));
      await page.reload({ waitUntil: 'load' });
      const there = await page.waitForSelector(want, { state: 'attached', timeout: 15000 }).then(() => true, () => false);
      res.tested++;
      if (!there) fail(`${name}: not drawn as it should be («${want}» missing)`);
      if (name === 'failed') {
        const log = await page.evaluate(() => (document.querySelector('.ct-net pre.code') || {}).textContent || '');
        if (!/key was refused/.test(log)) fail('failed: the log\'s lines are not shown');
      }
      if (name === 'down') {
        // the helper is started again every minute: the page keeps looking (the lab, 2026-10-23: «not running» stood until a reload)
        const before = looks();
        const again = await page.waitForFunction(() => /in the network/.test((document.querySelector('.ct-net .ct-subhead .chip') || {}).textContent || ''), null, { timeout: 12000 }).then(() => true, () => false);
        res.tested++;
        if (!again || looks() <= before) fail(`down: the page did not look again on its own (looks: ${looks() - before})`);
      }
      await page.waitForTimeout(600);
    }
    for (const e of [...new Set(errors)]) fail(e);
    await ctx.close();
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
// ------------------------------------------------------------------ the routes walk (2026-10-16)
// Every place Jack Streamer's and Ms. Moverelli's search names (Office.placesOf: sections, tiles, setup steps; the items
// of their demo states: Office.itemsOf) and every '#/<desk>…' address their pages link to: each opened cold (a fresh
// load of that address) and from the reception as the search does (Office.goToPlace) - no page error, the desk drawn,
// the place named (its data-place / data-id) there and in sight. A click on a run in the search broke Jack's page
// (#/jack/history before his state was there).
const WALK = ['jack', 'moverelli'];
async function routeWalk(browser, harness, desk) {
  const res = { route: `${desk} routes`, theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
  const page = await ctx.newPage();
  let errors = [];
  page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  const drawn = () => page.waitForFunction((d) => window.Office && Office.current && Office.current.id === d
    && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), desk, { timeout: 8000 }).then(() => true, () => false);
  const open = async (url) => { await page.goto('about:blank'); await page.goto(url, { waitUntil: 'load' }); };
  try {
    await open(`${harness.url}#/${desk}`);
    await drawn();
    await page.waitForFunction((d) => Office.itemsOf(d).length > 0, desk, { timeout: 8000 }).catch(() => fail('no items from the demo state'));
    const found = await page.evaluate((d) => ({
      places: Office.placesOf(d).filter((p) => p.kind !== 'help' && !p.act && (!p.shown || p.shown())).map((p) => ({ route: p.route, anchor: p.anchor, what: `${p.kind} ${p.key}` })),
      items: Office.itemsOf(d).map((p) => ({ route: p.route, anchor: p.anchor, what: `item ${p.label}` })),
    }), desk);
    const src = WALK.map((d) => fs.readFileSync(path.join(REPO, 'public', 'desks', d, 'desk.js'), 'utf8')).join('\n');
    const links = [...new Set([...src.matchAll(new RegExp(`['\`](#/${desk}(?:/[a-z0-9/_-]*)?)['\`]`, 'g'))].map((m) => m[1]))]
      .map((route) => ({ route, anchor: null, what: `link ${route}` }));
    const seen = new Set();
    const targets = [...found.places, ...found.items, ...links].filter((x) => !seen.has(`${x.route}|${x.anchor}`) && seen.add(`${x.route}|${x.anchor}`));
    for (const route of new Set(targets.map((x) => x.route))) {
      errors = [];
      await open(`${harness.url}${route}`);
      if (!(await drawn())) fail(`${route} (opened cold): the desk not drawn`);
      await page.waitForTimeout(600);
      for (const e of new Set(errors)) fail(`${route} (opened cold): ${e}`);
      res.tested++;
    }
    for (const x of targets.filter((t) => t.anchor)) {
      errors = [];
      await open(`${harness.url}#/`);
      await page.waitForFunction(() => window.Office && Office.goToPlace && document.querySelector('#sso-desk'), null, { timeout: 8000 }).catch(() => {});
      await page.evaluate((t) => Office.goToPlace({ desk: t.desk, route: t.route, anchor: t.anchor, hired: true, label: t.what }), { ...x, desk });
      if (!(await drawn())) fail(`${x.what} → ${x.route}: the desk not drawn`);
      const there = await page.waitForFunction((a) => {
        const q = (s) => document.querySelector(s);
        const v = String(a).replace(/["\\]/g, '\\$&');
        const n = q(`[data-place="${v}"]`) || q(`[data-id="${v}"]`);
        return n && n.getClientRects().length > 0;
      }, x.anchor, { timeout: 6000 }).then(() => true, () => false);
      if (!there) fail(`${x.what} → ${x.route}: «${x.anchor}» not there`);
      for (const e of new Set(errors)) fail(`${x.what} → ${x.route}: ${e}`);
      res.tested++;
    }
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await ctx.close();
  res.ms = Date.now() - t0;
  return res;
}
const walks = opt.states ? [] : WALK.filter((d) => !opt.only || routes.includes(d));
results.push(...await Promise.all(walks.map((d) => routeWalk(browser, harness, d))));
if (!opt.states && (!opt.only || routes.includes('jack/setup'))) results.push(await dialogStack(browser, harness));
if (!opt.states && (!opt.only || routes.includes('caretaker'))) results.push(await firstHome(browser));
if (!opt.states && (!opt.only || routes.includes('caretaker'))) results.push(await netBusy(browser));
await browser.close();
await harness.close();

// ------------------------------------------------------------------ the table
results.sort((a, b) => routes.indexOf(a.route) - routes.indexOf(b.route) || opt.themes.indexOf(a.theme) - opt.themes.indexOf(b.theme) || b.width - a.width);
const pad = (s, n) => String(s).padEnd(n);
console.log(`${pad('page', 15)}${pad('theme', 7)}${pad('width', 7)}${pad('clicked', 9)}${pad('skipped', 9)}${pad('capped', 8)}result`);
for (const r of results) {
  console.log(`${pad(r.route, 15)}${pad(r.theme, 7)}${pad(r.width, 7)}${pad(r.tested, 9)}${pad(r.skipped, 9)}${pad(r.capped, 8)}${r.fails.length ? `FAIL (${r.fails.length})` : 'ok'}`);
}
const failed = results.filter((r) => r.fails.length);
for (const r of failed) {
  console.log(`\n${r.route} ${r.theme} ${r.width}:`);
  for (const f of r.fails) console.log(`  - ${f}`);
}
const clicks = results.reduce((s, r) => s + r.tested, 0);
const v2 = results.reduce((s, r) => s + r.sections, 0);
console.log(`\nui clicks: ${results.length} pages, ${clicks} toggles clicked twice${v2 ? `, ${v2} switcher clicks checked (tiles v2)` : ''}, ${failed.length} failed - ${((Date.now() - started) / 1000).toFixed(0)} s`);
process.exit(failed.length ? 1 : 0);
