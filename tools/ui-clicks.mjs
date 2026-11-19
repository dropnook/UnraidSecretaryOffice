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
//   UC_FORCE_FAIL=<route> …   one page of that route red on purpose (tools/release.sh's retry, USO_RELEASE_FLAKY there)
// Every red page ends the output with a line «ui clicks failed: <--only route>|<page>|<theme>|<width>» (tools/release.sh
// runs those routes once more: green then = flaky, red twice = red).
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
// ------------------------------------------------------------------ Ms. Dustdevil's treemap (2026-11-06: one treemap to click into)
// «Storage»: the shares at the top, what a scanned share holds drawn inside its cell; a click on a share goes inside, on a folder
// deeper; the breadcrumb, Escape and the X come back up; a share without a map scans first (the wake question for one on the
// array, the wave in its cell, it opens when the scan ends and nothing else was clicked); «Scan all…» asks once and queues;
// no horizontal scrolling and no jumping page at phone width either, by finger a tap shows and the next one goes in.
/**
 * The plan in PHP beside Python (agent/desks/jack-shadow.php): no tile without its flag (no key in his state); with it the tile says the counts,
 * its section the differences from the part «shadow»; all the same: no list, the part never asked; no python3: «not compared: no Python»,
 * the footer names PHP. At 1440 and 375 px.
 */
async function jackShadow(browser) {
  const res = { route: 'jack shadow', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const h = await startHarness({});
  const file = (name) => JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', `${name}.json`), 'utf8'));
  const stub = file('jack');
  const part = file('jack-shadow');
  const idle = { ...stub, jobs: { embycache: { ...stub.jobs.embycache, running: false }, gather: stub.jobs.gather }, night: { ...stub.night, now: null } };
  const tile = '#sso-desk [data-place="tile.shadow"], #sso-desk .stat:has-text("The plan in PHP")';
  for (const width of [1440, 375]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
    try {
      h.setState('jack', idle);
      h.setState('jack-shadow', part);
      await page.goto(`${h.url}#/jack`, { waitUntil: 'load' });
      await page.waitForSelector('#sso-desk .stats');
      res.tested++;
      if (await page.locator(tile).count()) fail(`${width}: a tile without the flag`);
      // with the flag
      h.setState('jack', { ...idle, shadow: part.summary });
      await page.goto(`${h.url}#/jack/shadow`, { waitUntil: 'load' });
      await page.reload({ waitUntil: 'load' });
      await page.waitForSelector('#sso-desk .jo-structure');
      await page.waitForFunction(() => document.querySelectorAll('#sso-desk .jo-structure .row').length > 0, null, { timeout: 8000 });
      res.tested++;
      const seen = await page.evaluate(() => {
        const b = document.querySelector('#sso-desk .jo-structure');
        return { text: b.textContent, rows: [...b.querySelectorAll('.row')].map((r) => [r.querySelector('.row-name').textContent, r.querySelector('.chip').textContent, r.querySelectorAll('.jo-structure-path').length]),
          tile: [...document.querySelectorAll('#sso-desk .stat')].map((x) => x.textContent).find((t) => t.includes('The plan in PHP')) || '',
          wide: document.documentElement.scrollWidth > window.innerWidth };
      });
      if (!seen.tile.includes('12 days the same, 1 with differences') || !seen.tile.includes('3 not compared') || !seen.tile.includes('1 where both sides failed') || !seen.tile.includes('1 with a difference seen only once')) fail(`${width}: the tile's counts: ${seen.tile}`);
      if (!seen.text.includes('both sides failed, nothing compared') || !seen.text.includes(': different plans')) fail(`${width}: what each listed comparison came to`);
      if (seen.rows.length !== 4 || seen.rows[3][1] !== 'How the plan ended' || seen.rows[0][1] !== 'Comes to the pool' || seen.rows[1][1] !== 'Goes back by rank' || seen.rows[0][2] !== 2) fail(`${width}: the rows: ${JSON.stringify(seen.rows)}`);
      if (!seen.text.includes('2 more differences not listed')) fail(`${width}: what isn't listed`);
      if (seen.wide) fail(`${width}: scrolls sideways`);
      // all the same: no list
      h.setState('jack', { ...idle, shadow: { same: 5, differ: 0, once: 0, error: 0, not: 0, last: part.summary.last } });
      await page.reload({ waitUntil: 'load' });
      await page.waitForSelector('#sso-desk .jo-structure');
      res.tested++;
      if (await page.locator('#sso-desk .jo-structure .row').count()) fail(`${width}: rows though all the same`);
      // no python3 on the server (2026-11-12): PHP acts alone — the tile and its section say «not compared: no Python», no Python
      // callout or bubble, the footer names PHP and no Python
      h.setState('jack', { ...idle, python: null, php: '8.4.12', shadow: { same: 0, differ: 0, once: 0, error: 0, not: 2, last: part.summary.last, php: true, python: false } });
      await page.reload({ waitUntil: 'load' });
      await page.waitForSelector('#sso-desk .jo-structure');
      res.tested++;
      const nopy = await page.evaluate(() => ({
        tile: [...document.querySelectorAll('#sso-desk .stat')].map((x) => x.textContent).find((t) => t.includes('The plan in PHP')) || '',
        page: document.querySelector('#sso-desk').textContent,
        foot: (document.querySelector('#sso-desk .jo-tools') || {}).textContent || '',
        wide: document.documentElement.scrollWidth > window.innerWidth,
      }));
      if (!nopy.tile.includes('Not compared: no Python') || !nopy.tile.includes('2 not compared')) fail(`${width}: no Python — the tile: ${nopy.tile}`);
      if (!nopy.page.includes('This server has no Python 3: PHP plans each real run')) fail(`${width}: no Python — the section doesn't say who moves`);
      if (nopy.page.includes('last compared on')) fail(`${width}: no Python, never compared — still «last compared on»`);
      if (nopy.page.includes('Python 3 is missing') || nopy.page.includes('I need Python 3')) fail(`${width}: no Python — still asked for`);
      if (!nopy.foot.includes('PHP 8.4.12') || nopy.foot.includes('Python')) fail(`${width}: no Python — the footer: ${nopy.foot}`);
      if (nopy.wide) fail(`${width}: no Python — scrolls sideways`);
      for (const e of errors) fail(`${width}: ${e}`);
    } catch (e) {
      fail(`${width}: ${String(e.message).slice(0, 200)}`);
    } finally {
      await ctx.close();
    }
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}

/**
 * Jack Streamer's «Check the structure…» (agent/desks/jack-structure.php): the list drawn, grouped by kind with kinds as labels;
 * who didn't answer; the clean sentence; a check at work (the wave, the button off) and its end coming by the one timer; the
 * click sends exactly one structure_start. At 1440 and 375 px.
 */
async function jackStructure(browser) {
  const res = { route: 'jack structure', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const h = await startHarness({});
  const file = (name) => JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', `${name}.json`), 'utf8'));
  const stub = file('jack');
  const part = file('jack-structure');
  const clean = { ...stub.structure, found: 0, unmapped: 0, servers_failed: 0 };
  const cleanPart = { ...part, result: { ...part.result, found: 0, unmapped: 0, servers: [{ name: 'Emby', films: 8, series: 3, items: 11, unmapped: 0, found: 0, findings: [] }] } };
  const box = '#sso-desk .jo-structure';
  for (const width of [1440, 375]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 160)));
    // the stub has a real run going (the check goes beside it: his part doesn't carry the check's end); at phone width none
    const jack = width === 1440 ? stub : { ...stub, jobs: { embycache: { ...stub.jobs.embycache, running: false }, gather: stub.jobs.gather }, night: { ...stub.night, now: null } };
    try {
      h.setState('jack', jack);
      h.setState('jack-structure', part);
      await page.goto(`${h.url}#/jack/shares`, { waitUntil: 'load' });
      await page.waitForSelector(`${box} .chip`, { timeout: 8000 });
      const list = await page.evaluate((sel) => {
        const b = document.querySelector(sel);
        return { rows: [...b.querySelectorAll('.row')].map((r) => [r.querySelector('.chip').textContent, r.querySelector('.chip').title !== '', r.querySelector('.row-name').textContent, r.querySelector('.jo-structure-path').textContent]),
          text: b.textContent, wide: document.documentElement.scrollWidth > innerWidth };
      }, box);
      res.tested++;
      const kinds = list.rows.map((r) => r[0]);
      if (kinds.join('|') !== "Film in the library's root|Several films in one folder|Series without a folder") fail(`${width}: the kinds as labels, in their order: ${JSON.stringify(kinds)}`);
      if (!list.rows.every((r) => r[1])) fail(`${width}: a label without its own title`);
      if (list.rows[1][2] !== 'Ferien am See · Velotour am Rhein' || !list.rows[1][3].includes('/mnt/user/media/Familienfilme/Ferien am See (2019)')) fail(`${width}: title and path of a row: ${JSON.stringify(list.rows[1])}`);
      if (!list.rows[0][3].startsWith('Emby · ')) fail(`${width}: several servers, the row doesn't name its own: ${list.rows[0][3]}`);
      if (!list.text.includes('8 films and 3 series checked at') || !list.text.includes('3 items not in the expected pattern')) fail(`${width}: the sentence: ${list.text.slice(0, 160)}`);
      if (!list.text.includes('Plex not checked: ')) fail(`${width}: the server without an answer isn't named`);
      if (!list.text.includes('2 items lie where no folder mapping reaches')) fail(`${width}: what couldn't be judged isn't said`);
      if (list.wide) fail(`${width}: the page scrolls sideways`);
      if (await page.locator(`${box} a`).count()) fail(`${width}: a path is linked to something`);

      // the click: a dialog, then the action exactly once; the check at work: the wave, the button off
      h.setPost('jack.structure_start', () => ({ state: { ...jack, structure: { ...jack.structure, running: true } } }));
      h.setState('jack', { ...jack, structure: { ...jack.structure, running: true } });
      const before = h.posts.filter((x) => x.action === 'jack.structure_start').length;
      const check = page.getByRole('button', { name: 'Check the structure…' });
      await check.click();
      await page.waitForSelector('#sso-dialog-foot .btn', { timeout: 4000 });
      if (h.posts.filter((x) => x.action === 'jack.structure_start').length !== before) fail(`${width}: the check started before the dialog's button`);
      await page.locator('#sso-dialog-foot .btn', { hasText: 'Check' }).click();
      await page.waitForSelector(`${box} .spin`, { timeout: 4000 });
      res.tested++;
      if (h.posts.filter((x) => x.action === 'jack.structure_start').length !== before + 1) fail(`${width}: not exactly one structure_start`);
      if (!(await page.locator(`${box}`).textContent()).includes("I'm asking the media servers")) fail(`${width}: at work without saying so`);
      if (!(await check.isDisabled())) fail(`${width}: the button stays on while a check goes`);

      // its end: his state says so, the one timer picks it up and the list follows — the clean sentence, no list
      h.setState('jack', { ...jack, structure: { ...clean, time: jack.structure.time + 300 } });
      h.setState('jack-structure', { ...cleanPart, result: { ...cleanPart.result, time: jack.structure.time + 300 } });
      await page.waitForFunction((sel) => { const b = document.querySelector(sel); return b && !b.querySelector('.spin') && b.textContent.includes('everything in its place'); }, box, { timeout: 15000 });
      res.tested++;
      const end = await page.evaluate((sel) => ({ rows: document.querySelectorAll(`${sel} .row`).length, text: document.querySelector(sel).textContent }), box);
      if (end.rows) fail(`${width}: rows in a clean result`);
      if (!end.text.includes('8 films and 3 series checked at')) fail(`${width}: the clean sentence: ${end.text}`);
      if (await check.isDisabled()) fail(`${width}: the button stays off after the check`);
    } catch (e) {
      fail(`${width}: broke off: ${String(e.message).split('\n').slice(0, 3).join(' ').slice(0, 240)}`);
    }
    errors.forEach((e) => fail(`${width}: page error: ${e}`));
    await ctx.close();
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}

async function cleanupMap(browser) {
  const res = { route: 'cleanup map', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => { if (res.fails.length < 40 && !res.fails.includes(what)) res.fails.push(what); };
  const h = await startHarness({});
  const stateFile = (name) => JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', `${name}.json`), 'utf8'));
  const space = stateFile('cleanup-space');
  const series = space.shares.find((s) => s.name === 'series');
  const domains = space.shares.find((s) => s.name === 'domains');
  const system = space.shares.find((s) => s.name === 'system');
  // the agent's answer: what was asked for runs. mapsAlso(): a scan ended — its map there, nothing running
  const scanStub = () => h.setPost('cleanup.where_map', (body) => {
    const st = stateFile('cleanup-where-map');
    st.running = body.paths.slice(0, 1);
    st.queue = body.paths.slice(1);
    h.setState('cleanup-where-map', st);
    return { map: st };
  });
  const scanEnded = (name, measure) => {
    const st = stateFile('cleanup-where-map');
    st.maps[name] = { path: measure, at: 1793540900, took: 3, nodes: 505, bytes: 1000, partial: false };
    h.setState('cleanup-where-map', st);
    const tree = stateFile('cleanup-where-map-films');
    h.setState(`cleanup-where-map-${name}`, { ...tree, share: name, path: measure, at: 1793540900, took: 3, tree: { ...tree.tree, n: name } });
  };
  const scanReset = () => { for (const n of ['cleanup-where-map', 'cleanup-where-map-domains', 'cleanup-where-map-system']) h.setState(n, undefined); };
  const open = async (ctx) => {
    const page = await ctx.newPage();
    await page.goto(`${h.url}#/cleanup/where/space`, { waitUntil: 'load' });
    await page.waitForSelector('.cl-map-top .cl-map-cell', { timeout: 8000 });
    return page;
  };
  const cellOf = (page, name) => page.locator(`.cl-map-cell[data-share="${name}"]`);
  const crumbsOf = (page) => page.locator('.cl-map-crumbs > *:not(.cl-map-sep)').count();
  const pos = { position: { x: 8, y: 8 } };           // on the cell's own head, never on what it holds
  scanStub();
  for (const width of [1440, 375]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
    const errors = [];
    const page = await open(ctx);
    page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 160)); });
    try {
      const crumbs = () => crumbsOf(page);
      const cell = (n) => cellOf(page, n);
      const noScroll = async (when) => {
        if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) fail(`${width} px: horizontal scrolling ${when}`);
      };
      // a redraw never makes the page jump: the panel stays where it is on screen
      const steady = async (when, change) => {
        await page.evaluate(() => { const p = document.querySelector('.cl-map'); window.scrollBy(0, p.getBoundingClientRect().top - 120); });
        const top = () => page.evaluate(() => document.querySelector('.cl-map').getBoundingClientRect().top);
        const was = await top();
        await change();
        const now = await top();
        if (Math.abs(now - was) > 1.5) fail(`${width} px: the page jumped ${when} (${was} → ${now})`);
      };
      // something running in a line: the wave and the words at the line's size, never a chip
      const runningInLine = async (line, where) => {
        const wrong = await page.evaluate((sel) => {        // in one go: the poll may draw the line anew at any moment
          const box = document.querySelector(sel);
          if (box.querySelector('.chip.running')) return 'a chip';
          const r = box.querySelector('.cl-space-running');
          const wave = r && r.querySelector('.spin');
          if (!wave || !(wave.getBoundingClientRect().width > 0)) return 'no wave';
          if (!r.textContent.trim()) return 'no words';
          return getComputedStyle(r).fontSize === getComputedStyle(r.parentElement).fontSize ? '' : `${getComputedStyle(r).fontSize} in a line of ${getComputedStyle(r.parentElement).fontSize}`;
        }, line);
        if (wrong) fail(`${width} px: the running state ${where}: ${wrong}`);
      };
      // ---- the top: the shares, what a scanned one holds inside its cell, the bar
      await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 5000 }).catch(() => fail(`${width} px: a scanned share's content is not drawn inside its cell`));
      if (await page.locator('.cl-map-cell[data-share="series"] .cl-map-in').count()) fail(`${width} px: a share without a map has content`);
      if (await page.locator('.cl-map-crumbs').count()) fail(`${width} px: a breadcrumb at the top`);
      if ((await page.locator('.cl-map-all').textContent()) !== 'Scan all…') fail(`${width} px: the bar's button says «${await page.locator('.cl-map-all').textContent()}»`);
      if (!(await page.locator('.cl-map-hint').textContent()).includes('scans it')) fail(`${width} px: the hint does not say the first click scans`);
      if (!(await cell('films').locator('.cl-space-tmrect').evaluate((e) => getComputedStyle(e).fill !== 'none'))) fail(`${width} px: the share's own fill is gone`);
      if (await page.locator('.cl-map-legend').count()) fail(`${width} px: the kinds' legend at the top`);
      if (!(await page.locator('.cl-space-unmeasured').count())) fail(`${width} px: the unmeasured line is gone`);
      await noScroll('at the top');
      const topBox = await page.locator('.cl-map-svg').getAttribute('viewBox');
      if (topBox !== (width > 640 ? '0 0 1000 360' : '0 0 400 400')) fail(`${width} px: the stage's viewBox is ${topBox}`);
      res.tested++;
      // ---- the relief: the switch in the bar; on: over files alone, the pointer through it; off: none, through a redraw and a reload
      const reliefSwitch = page.locator('.cl-map-head .cl-map-reliefseg button');
      const reliefs = () => page.locator('.cl-map-svg .cl-map-relief').count();
      const reliefOn = () => reliefSwitch.getAttribute('aria-pressed');
      const reliefRight = async (where) => {
        if ((await reliefSwitch.count()) !== 1) fail(`${width} px: no relief switch in the bar ${where}`);
        if ((await reliefOn()) !== 'true' || !(await reliefs())) fail(`${width} px: the relief is not on ${where} (${await reliefOn()}, ${await reliefs()})`);
        const wrong = await page.evaluate(() => {
          const bad = [];
          const stops = [...document.querySelectorAll('.cl-map-svg linearGradient stop')].map((x) => Number(getComputedStyle(x).stopOpacity));
          if (stops.length !== 4 || Math.max(...stops) > 0.181 || stops[1] !== 0 || stops[2] !== 0) bad.push(`the gradient's stops ${stops}`);
          for (const o of document.querySelectorAll('.cl-map-svg .cl-map-relief')) {
            const own = o.previousElementSibling;
            if (!own || !own.matches('.cl-map-rect') || own.matches('.cl-map-dir, .cl-map-dir2, .cl-map-restbox')) { bad.push(`over ${own ? own.getAttribute('class') : 'nothing'}`); continue; }
            if (['x', 'y', 'width', 'height'].some((k) => o.getAttribute(k) !== own.getAttribute(k))) bad.push('not on its file');
            if (Number(o.getAttribute('width')) < 4 || Number(o.getAttribute('height')) < 4) bad.push('on a rectangle under 4 units');
            if (getComputedStyle(o).pointerEvents !== 'none') bad.push('in the pointer\'s way');
          }
          return bad.slice(0, 3).join('; ');
        });
        if (wrong) fail(`${width} px: the relief ${where}: ${wrong}`);
      };
      const reliefOff = async (where) => {
        if ((await reliefOn()) !== 'false' || (await reliefs())) fail(`${width} px: the relief is not off ${where} (${await reliefOn()}, ${await reliefs()})`);
      };
      // ---- the colour's switch at the top too: what the shares hold takes it, the shares keep their own colour
      const topColour = page.locator('.cl-map-head .cl-map-seg button');
      if ((await topColour.count()) !== 2) fail(`${width} px: no colour switch in the bar at the top`);
      if (!(await page.locator('.cl-map-tools .cl-map-seg + .cl-map-reliefseg').count())) fail(`${width} px: at the top the relief switch is not beside the colour's`);
      await steady('switching the colour at the top', () => topColour.nth(1).click());
      if ((await topColour.nth(1).getAttribute('aria-pressed')) !== 'true') fail(`${width} px: «by age» not pressed at the top`);
      if (!(await page.locator('.cl-map-top .cl-map-in.cl-map-t-new, .cl-map-top .cl-map-in.cl-map-t-mid, .cl-map-top .cl-map-in.cl-map-t-old').count())) fail(`${width} px: at the top nothing is coloured by age`);
      if (await page.locator('.cl-map-legend').count()) fail(`${width} px: the colour's switch changed the top's own legend`);
      await noScroll('with both switches at the top');
      await topColour.nth(0).click();
      await reliefRight('at the top');
      if ((await reliefSwitch.textContent()) !== 'Relief' || !(await reliefSwitch.getAttribute('title'))) fail(`${width} px: the relief switch says «${await reliefSwitch.textContent()}», its title «${await reliefSwitch.getAttribute('title')}»`);
      await steady('switching the relief', () => reliefSwitch.click());
      await reliefOff('after its switch');
      await page.reload({ waitUntil: 'load' });
      await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 8000 });
      await reliefOff('after a reload');
      await reliefSwitch.click();
      await reliefRight('switched on again');
      await noScroll('with the relief switch');
      res.tested++;
      // ---- the line: the pointed-at share's numbers; pointing at what a cell holds names it, path from the share's root
      if (width > 640) {
        await cell('series').hover(pos);
        if (!(await page.locator('.cl-space-tminfo').textContent()).startsWith('series')) fail('hovering a share: its numbers not in the line');
        await cell('films').locator('.cl-map-in').first().hover({ force: true });
        const said = await page.locator('.cl-map-infoline').textContent();
        if (!said.startsWith('films') || !(await page.locator('.cl-map-infoline .cl-map-path').textContent()).startsWith('/')) fail(`pointing inside a share's cell: the line says «${said}»`);
      }
      res.tested++;
      // ---- a click on a share goes inside: the breadcrumb, the kinds' legend, the X
      await steady('going into a share', () => cell('films').click(pos));
      await page.waitForSelector('.cl-map-crumbs', { timeout: 5000 });
      if (await page.locator('.cl-map-top').count()) fail(`${width} px: still the top after a click on a share`);
      const before = await crumbs();
      if (before !== 2 || !(await page.locator('.cl-map-crumbs').textContent()).startsWith('Shares')) fail(`${width} px: the breadcrumb is «${await page.locator('.cl-map-crumbs').textContent()}»`);
      if (!(await page.locator('.cl-map-legend').count())) fail(`${width} px: no kinds' legend inside a share`);
      if (await page.locator('.cl-map-all').count()) fail(`${width} px: «Scan all…» inside a share`);
      const inBox = await page.locator('.cl-map-svg').getAttribute('viewBox');
      if (inBox !== (width > 640 ? '0 0 1000 480' : '0 0 400 400')) fail(`${width} px: the stage inside a share has the viewBox ${inBox}`);
      if (!(await page.locator('.cl-map-close').getAttribute('aria-label'))) fail(`${width} px: the X has no label`);
      if (!(await page.getByRole('button', { name: 'Scan again…' }).count())) fail(`${width} px: no «Scan again…» inside a share`);
      await noScroll('inside a share');
      // a click on a share that has its map fetches nothing again (they came when the section opened)
      res.tested++;
      // ---- the relief inside: the switch beside the colour's, the legend's swatches lit like the files, a rest's never
      await reliefRight('inside a share');
      if (!(await page.locator('.cl-map-tools .cl-map-seg + .cl-map-reliefseg').count())) fail(`${width} px: the relief switch is not beside the colour's`);
      const lit = () => page.locator('.cl-map-legend .cl-map-relief').count();
      if ((await lit()) !== 9) fail(`${width} px: ${await lit()} of the legend's nine kinds have the relief`);
      if (await page.locator('.cl-map-legend .cl-map-restbox + .cl-map-relief').count()) fail(`${width} px: the legend's rest has the relief`);
      if (await page.evaluate(() => getComputedStyle(document.querySelector('.cl-map-svg .cl-map-relief')).fill === 'none')) fail(`${width} px: the overlay has no fill`);
      await reliefSwitch.click();
      await reliefOff('inside a share');
      if (await lit()) fail(`${width} px: the legend keeps the relief while it is off`);
      await page.locator('.cl-map-seg button').nth(1).click();       // another redraw: the colour's switch
      await reliefOff('after the colour\'s switch');
      await page.locator('.cl-map-seg button').nth(0).click();
      await reliefSwitch.click();
      await reliefRight('inside, on again');
      res.tested++;
      if (width > 640) {
        await page.locator('.cl-map-cell').first().hover(pos);
        const said = await page.locator('.cl-map-info').textContent();
        if (!said.includes('/') || !/[KMGT]B|B\b/.test(said)) fail(`hover box says «${said}»`);
      }
      // a rest never reads as an empty area: hatched at both levels and in the legend, its rectangle says how many and how much
      const hatched = (loc) => loc.first().evaluate((e) => getComputedStyle(e).fill.includes('url('));
      const rest = page.locator('.cl-map-cell[aria-label*="… 6 folders and 214 files"]');
      if (!(await hatched(rest.locator('.cl-map-restbox')))) fail(`${width} px: the rest's rectangle is not hatched`);
      if (!(await hatched(page.locator('.cl-map-in.cl-map-restbox')))) fail(`${width} px: a rest inside a folder is not hatched`);
      if (!(await hatched(page.locator('.cl-map-legend .cl-map-restbox')))) fail(`${width} px: the legend's rest is not hatched`);
      if (await page.evaluate(() => [...document.querySelectorAll('.cl-map pattern line')].some((l) => getComputedStyle(l).stroke === 'none'))) fail(`${width} px: the hatching has no lines`);
      const words = await rest.locator('text').allTextContents();
      if (!words[0].startsWith('… 6 folders') || !words.join(' ').includes('214 files') || words.join(' ').includes('…', 1) || !words.join(' ').includes('96 GB') || words.join(' ').includes('≈ ')) fail(`${width} px: the rest says «${words.join(' | ')}»`);
      if (width > 640) {
        await rest.hover();
        const said = await page.locator('.cl-map-info').textContent();
        if (!said.includes('… 6 folders and 214 files') || said.includes('≈') || !said.includes('in Share films') || !/\d+ GB/.test(said)) fail(`the hover box of a rest says «${said}»`);
        // a rest without `d` (a map of an older walker, and the stub's inner ones): in files alone
        await page.locator('.cl-map-in.cl-map-restbox').first().hover({ force: true });
        const inner = await page.locator('.cl-map-info .cl-map-path').textContent();
        if (!/… \d+ files?$/.test(inner)) fail(`the hover box of a rest without folders counted says «${inner}»`);
      }
      res.tested++;
      // ---- a folder goes one deeper, the breadcrumb grows
      const label = await page.locator('.cl-map-cell').first().getAttribute('aria-label');
      await steady('going into a folder', () => page.locator('.cl-map-cell').first().click(pos));
      if ((await crumbs()) !== before + 1) fail(`${width} px: a click on «${label}» did not zoom in (${before} → ${await crumbs()})`);
      await noScroll('zoomed in');
      res.tested++;
      // ---- the colour by age: the switch and the legend
      await page.locator('.cl-map-seg button').nth(1).click();
      if ((await page.locator('.cl-map-seg button').nth(1).getAttribute('aria-pressed')) !== 'true') fail(`${width} px: «by age» not pressed`);
      if ((await page.locator('.cl-map-legend .cl-space-key').count()) !== 4) fail(`${width} px: the age legend has not three buckets and the rest`);
      if (!(await page.locator('.cl-map-rect.cl-map-t-new, .cl-map-rect.cl-map-t-mid, .cl-map-rect.cl-map-t-old').count())) fail(`${width} px: no rectangle coloured by age`);
      if ((await crumbs()) !== before + 1) fail(`${width} px: switching the colour lost the place`);
      await page.locator('.cl-map-seg button').nth(0).click();
      res.tested++;
      // a fill comes from her tokens, in this theme: never transparent or black
      const fill = await page.locator('.cl-map-rect:not(.cl-map-dir):not(.cl-map-restbox)').first().evaluate((e) => getComputedStyle(e).fill);
      if (!fill || fill === 'none' || fill === 'rgb(0, 0, 0)') fail(`${width} px: a rectangle's fill is ${fill}`);
      // ---- the breadcrumb back: to the share's root, its second part
      await page.locator('.cl-map-crumb').nth(1).click();
      if ((await crumbs()) !== before) fail(`${width} px: the breadcrumb did not go back up`);
      res.tested++;
      // a folder that is one chain down to its files: drawn through to them, each level its own class; what is pointed at says
      // its whole path, a click goes one step only (into the outer cell's folder)
      const inner = (cls) => page.locator(`.cl-map-cell[aria-label^="Archiv"] .${cls}`);
      if ((await inner('cl-map-dir2').count()) < 3) fail(`${width} px: the chain's folders are not drawn inside its cell`);
      if (!(await page.locator('.cl-map-in2').count())) fail(`${width} px: no second level anywhere on the stage`);
      const file = page.locator('.cl-map-cell[aria-label^="Archiv"] .cl-map-in:not(.cl-map-dir2)').first();
      if (width > 640) {
        await file.hover({ force: true });
        const p = await page.locator('.cl-map-info .cl-map-path').textContent();
        if (!p.startsWith('/Archiv/Digitalisiert/Kassetten/Hochzeit 1998/')) fail(`the hover box names «${p}» for a file at the end of the chain`);
      }
      await file.click({ force: true });
      if ((await crumbs()) !== before + 1 || (await page.locator('.cl-map-here').textContent()) !== 'Archiv') fail(`${width} px: a click deep inside did not go one step, into «Archiv»`);
      await page.locator('.cl-map-crumb').nth(1).click();
      res.tested++;
      // ---- the window's width: drawn again only when phone ↔ desktop changes, in the same place
      const box = () => page.locator('.cl-map-svg').getAttribute('viewBox');
      const wide = await box();
      await page.locator('.cl-map-cell').first().click(pos);
      await page.evaluate(() => { document.querySelector('.cl-map-svg').dataset.same = '1'; });
      await page.setViewportSize({ width: width > 640 ? width - 200 : width + 60, height: 900 });
      await page.waitForTimeout(350);
      if (!(await page.locator('.cl-map-svg[data-same]').count())) fail(`${width} px: drawn again though phone or desktop stayed`);
      await page.setViewportSize({ width: width > 640 ? 500 : 900, height: 900 });
      await page.waitForFunction((was) => document.querySelector('.cl-map-svg').getAttribute('viewBox') !== was, wide, { timeout: 2000 }).catch(() => fail(`${width} px: the window changed sides, the viewBox stayed ${wide}`));
      if ((await crumbs()) !== before + 1) fail(`${width} px: the redraw lost the place`);
      await page.setViewportSize({ width, height: 900 });
      await page.waitForFunction((was) => document.querySelector('.cl-map-svg').getAttribute('viewBox') === was, wide, { timeout: 2000 }).catch(() => fail(`${width} px: back again, the viewBox is not ${wide}`));
      await page.locator('.cl-map-crumb').nth(1).click();
      res.tested++;
      // a scan under a minute says so, never «0 min» (the stub's took 47 s)
      if (!(await page.locator('.cl-map-line').textContent()).includes('under a minute')) fail(`${width} px: the line says «${await page.locator('.cl-map-line').textContent()}»`);
      // ---- the keyboard: Enter goes in, Escape goes up one level, out of the share to the top; at the top it does nothing
      await page.locator('.cl-map-cell').first().focus();
      await page.keyboard.press('Enter');
      if ((await crumbs()) !== before + 1) fail(`${width} px: Enter did not zoom in`);
      await page.keyboard.press('Escape');
      if ((await crumbs()) !== before) fail(`${width} px: Escape did not go up`);
      await page.keyboard.press('Escape');
      if (!(await page.locator('.cl-map-top').count())) fail(`${width} px: Escape at a share's root did not come back to the shares`);
      await page.keyboard.press('Escape');
      if (!(await page.locator('.cl-map-top').count())) fail(`${width} px: Escape at the top did something`);
      await page.keyboard.press('Enter');         // the focus is back on the share we came from
      await page.waitForSelector('.cl-map-crumbs', { timeout: 3000 }).catch(() => fail(`${width} px: back at the top the focus is not on the share`));
      res.tested++;
      // ---- the X and the breadcrumb's «Shares» come back from any depth
      await page.locator('.cl-map-cell').first().click(pos);
      await steady('on the X', () => page.locator('.cl-map-close').click());
      if (!(await page.locator('.cl-map-top').count())) fail(`${width} px: the X did not come back to the shares`);
      await cell('films').click(pos);
      await page.waitForSelector('.cl-map-crumbs', { timeout: 3000 });
      await page.locator('.cl-map-crumb').first().click();
      if (!(await page.locator('.cl-map-top').count())) fail(`${width} px: «Shares» in the breadcrumb did not come back`);
      res.tested++;
      // ---- back at the top a resize draws the top's stage, the same listener
      await page.setViewportSize({ width: width > 640 ? 500 : 900, height: 900 });
      await page.waitForFunction((was) => document.querySelector('.cl-map-top').getAttribute('viewBox') !== was, topBox, { timeout: 2000 }).catch(() => fail(`${width} px: the top's stage kept its viewBox`));
      await page.setViewportSize({ width, height: 900 });
      await page.waitForFunction((was) => document.querySelector('.cl-map-top').getAttribute('viewBox') === was, topBox, { timeout: 2000 }).catch(() => fail(`${width} px: the top's stage did not come back`));
      res.tested++;
      // ---- a scan of the share we are in: the wave and its words in the bar, «Scan again…» away; when it ends, back
      await cell('films').click(pos);
      await page.waitForSelector('.cl-map-crumbs', { timeout: 5000 });
      await page.getByRole('button', { name: 'Scan again…' }).click();
      const ask = page.getByRole('button', { name: 'Scan', exact: true });
      await ask.waitFor({ timeout: 1000 }).then(() => ask.click(), () => {});       // a share on the array asks first
      await page.waitForSelector('.cl-map-tools .cl-space-running', { timeout: 8000 }).catch(() => fail(`${width} px: inside a share its running scan is not said`));
      await runningInLine('.cl-map-tools', 'inside a share');
      await noScroll('with a scan said inside a share');
      h.setState('cleanup-where-map', undefined);
      await page.waitForSelector('.cl-map-tools .cl-space-running', { state: 'detached', timeout: 8000 }).catch(() => fail(`${width} px: inside a share the scan is still said when it ended`));
      await page.locator('.cl-map-close').click();
      res.tested++;
      // ---- a pool-only share without a map: no question, the wave in its cell, «scanning» in the line; it opens when the scan ends
      const asked = h.posts.length;
      await cell('domains').click(pos);
      await page.waitForSelector('.cl-map-over .spin', { timeout: 3000 }).catch(() => fail(`${width} px: no wave in the cell of a share being scanned`));
      if (await page.locator('.dialog:visible, [role=dialog]:visible').count()) fail(`${width} px: a question for a share on a pool only`);
      if (!(await page.locator('.cl-space-tminfo').textContent()).includes('scanning')) fail(`${width} px: the line does not say «scanning»`);
      await runningInLine('.cl-space-tminfo', 'in the line under the treemap');
      const sent = h.posts.slice(asked).filter((p) => p.action === 'cleanup.where_map').pop();
      if (!sent || JSON.stringify(sent.data && sent.data.paths) !== JSON.stringify([domains.measure])) fail(`${width} px: the scan asked for ${JSON.stringify(sent)}`);
      await noScroll('with a wave in a cell');
      scanEnded('domains', domains.measure);
      await page.waitForFunction(() => (document.querySelector('.cl-map-here') || {}).textContent === 'domains', null, { timeout: 8000 }).catch(() => fail(`${width} px: the share did not open when its scan ended`));
      await page.locator('.cl-map-close').click();
      res.tested++;
      // ---- something else clicked since: it does not open, its content appears in its cell
      await cell('system').click(pos);
      await page.waitForSelector('.cl-map-over .spin', { timeout: 3000 });
      await page.locator('.cl-map .cl-space-legend').first().click();
      scanEnded('system', system.measure);
      await page.waitForSelector('.cl-map-cell[data-share="system"] .cl-map-in', { timeout: 8000 }).catch(() => fail(`${width} px: the scanned share's content did not appear in its cell`));
      if (!(await page.locator('.cl-map-top').count())) fail(`${width} px: the share opened though something else was clicked`);
      res.tested++;
      // ---- on the array: asks first; a failed scan says so in the cell's rectangle and the line
      const asked2 = h.posts.length;
      await cell('series').click(pos);
      await page.waitForSelector('.dialog, [role=dialog]', { timeout: 3000 }).catch(() => fail(`${width} px: no wake question for a share on the array`));
      if (h.posts.length !== asked2) fail(`${width} px: the scan started before the question was answered`);
      await page.getByRole('button', { name: 'Scan', exact: true }).click();
      await page.mouse.move(5, 5);          // the pointer moves on: the dialog closed over the treemap, a cell under it would be the line's share
      await page.waitForSelector('.cl-map-over .spin', { timeout: 3000 }).catch(() => fail(`${width} px: no wave after the question`));
      const failedState = stateFile('cleanup-where-map');
      failedState.failed = { series: { at: 1793540950 } };
      h.setState('cleanup-where-map', failedState);
      await page.waitForSelector('.cl-space-tmrect.cl-map-failed', { timeout: 8000 }).catch(() => fail(`${width} px: a failed scan is not said in the cell`));
      await page.mouse.move(5, 5);
      if (!(await page.locator('.cl-space-tminfo').textContent()).includes('brought no map')) fail(`${width} px: a failed scan is not said in the line`);
      if (await page.locator('.cl-map-crumbs').count()) fail(`${width} px: a failed scan opened something`);
      scanReset();
      res.tested++;
    } catch (e) {
      fail(`${width} px: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    errors.forEach((e) => fail(`${width} px: ${e}`));
    await ctx.close();
    scanReset();
  }
  // ---- «Scan all…»: asks once which shares wake which disks, queues every share that can be scanned, waves in their cells
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await open(ctx);
    try {
      const asked = h.posts.length;
      await page.locator('.cl-map-all').click();
      await page.waitForSelector('.dialog, [role=dialog]', { timeout: 3000 });
      const said = await page.locator('.cl-map-wakes').textContent();
      if (!said.includes('series: disk1, disk3') || said.includes('films')) fail(`scan all: the question says «${said}»`);
      if (h.posts.length !== asked) fail('scan all: queued before the question was answered');
      await page.getByRole('button', { name: 'Scan', exact: true }).click();
      await page.waitForSelector('.cl-map-all .spin', { timeout: 3000 }).catch(() => fail('scan all: no wave on the button'));
      const sent = h.posts.slice(asked).filter((p) => p.action === 'cleanup.where_map');
      const can = space.shares.filter((s) => !s.missing && s.bytes !== 0).map((s) => s.measure);
      if (sent.length !== 1 || JSON.stringify(sent[0].data.paths) !== JSON.stringify(can)) fail(`scan all: asked for ${JSON.stringify(sent.map((p) => p.data))}, not ${JSON.stringify(can)}`);
      const text = await page.locator('.cl-map-all').textContent();
      if (!text.includes(`${can.length} in the queue`)) fail(`scan all: the button says «${text}»`);
      if (!(await page.locator('.cl-map-all').isDisabled())) fail('scan all: the button can be clicked again');
      const waves = await page.locator('.cl-map-over .spin').count();
      const cells = await page.locator('.cl-map-top .cl-map-cell').count();
      if (waves !== cells) fail(`scan all: ${waves} waves over ${cells} cells`);
      if (!(await page.locator('.cl-space-unmeasured .cl-map-chip.cl-space-running .spin').count())) fail('scan all: no wave beside an unmeasured share');
      if (await page.locator('.cl-space-unmeasured .chip.running').count()) fail('scan all: an unmeasured share\'s running state is a chip');
      res.tested++;
    } catch (e) {
      fail(`scan all: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    await ctx.close();
    scanReset();
    // a server of pools only: nothing to ask
    const pools = JSON.parse(JSON.stringify(space));
    pools.shares = pools.shares.filter((s) => !s.unknown.length && s.primary !== 'array' && s.secondary !== 'array');
    h.setState('cleanup-space', pools);
    const ctx2 = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page2 = await open(ctx2);
    try {
      await page2.locator('.cl-map-all').click();
      await page2.waitForSelector('.cl-map-all .spin', { timeout: 3000 });
      if (await page2.locator('.dialog:visible, [role=dialog]:visible').count()) fail('scan all: a question on a server of pools only');
      res.tested++;
    } catch (e) {
      fail(`scan all, pools only: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    await ctx2.close();
    h.setState('cleanup-space', undefined);
    scanReset();
  }
  // a very wide and deep tree: never more than 3 000 rectangles inside the cells at any level, and the largest folders got them
  {
    const m = 1600000000;
    const size = (i) => (500 - i * 7) * 1048576;
    const leaf = (n, i) => ({ n, b: size(i), f: 1, m, k: 'video' });
    const wide = (name, depth) => {
      const c = [];
      for (let i = 0; i < 48; i++) c.push(depth > 1 ? wide(`${name}-${i}`, depth - 1) : leaf(`${name}-${i}.mkv`, i));
      return { n: name, b: c.reduce((a, x) => a + x.b, 0), f: c.reduce((a, x) => a + x.f, 0), m, c };
    };
    const parts = [wide('big-a', 3), wide('big-b', 3)];
    for (let i = 0; i < 20; i++) parts.push(wide(`small-${i}`, 1));
    const tree = { n: 'films', b: parts.reduce((a, x) => a + x.b, 0), f: parts.reduce((a, x) => a + x.f, 0), m, c: parts };
    h.setState('cleanup-where-map-films', { v: 1, share: 'films', path: '/mnt/big/films', at: 1793540800, took: 47, nodes: 30000, partial: false, tree });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await open(ctx);
    try {
      await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 8000 });
      const top = await page.locator('.cl-map-in').count();
      if (top > 3000) fail(`a huge tree: ${top} rectangles at the top (at most 3 000)`);
      await cellOf(page, 'films').click(pos);
      await page.waitForSelector('.cl-map-crumbs', { timeout: 8000 });
      const n = await page.locator('.cl-map-in').count();
      if (n > 3000 || n < 2500) fail(`a huge tree: ${n} rectangles inside the cells (at most 3 000, and it should use them)`);
      const first = await page.locator('.cl-map-cell').first().locator('.cl-map-in').count();
      const last = await page.locator('.cl-map-cell').last().locator('.cl-map-in').count();
      if (first <= last) fail(`a huge tree: the largest cell holds ${first} rectangles, the smallest ${last}`);
      res.tested++;
    } catch (e) {
      fail(`a huge tree: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    await ctx.close();
    h.setState('cleanup-where-map-films', undefined);
  }
  // ---- a share's snapshots: its content over the walked part, a hatched strip for the rest; no map, no snapshots, a du's size: no strip
  {
    const withPhotos = stateFile('cleanup-where-map');
    const photos = space.shares.find((s) => s.name === 'photos');
    withPhotos.maps.photos = { path: photos.measure, at: 1793540800, took: 47, nodes: 505, bytes: 1000, partial: false };
    const maps = (extra) => { const st = JSON.parse(JSON.stringify(withPhotos)); Object.assign(st.maps, extra); return st; };
    const tree = stateFile('cleanup-where-map-films');
    h.setState('cleanup-where-map-photos', { ...tree, share: 'photos', path: photos.measure, tree: { ...tree.tree, n: 'photos' } });
    for (const width of [1440, 375]) {
      h.setState('cleanup-where-map', undefined);
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
      const page = await open(ctx);
      try {
        await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 5000 });
        if (await cellOf(page, 'photos').locator('.cl-map-snaps').count()) fail(`${width} px: a strip on a share without a map`);
        await page.close();
        h.setState('cleanup-where-map', maps({}));
        const page2 = await open(ctx);
        const cell = cellOf(page2, 'photos');
        await page2.waitForSelector('.cl-map-cell[data-share="photos"] .cl-map-snaps', { timeout: 5000 }).catch(() => fail(`${width} px: no snapshots' strip on a scanned share with snapshots`));
        if (await cellOf(page2, 'films').locator('.cl-map-snaps').count()) fail(`${width} px: a strip on a scanned share without snapshots`);
        const num = (loc, k) => loc.evaluate((e, a) => Number(e.getAttribute(a)), k);
        const strip = cell.locator('.cl-map-snaps');
        const [sw, sh, sx, sy] = [await num(strip, 'width'), await num(strip, 'height'), await num(strip, 'x'), await num(strip, 'y')];
        const inner = await cell.locator('.cl-map-in').evaluateAll((l) => l.filter((e) => e.classList.value.split(' ').includes('cl-map-in') && !/cl-map-in[234]/.test(e.classList.value)).map((e) => ({ x: Number(e.getAttribute('x')), y: Number(e.getAttribute('y')), r: Number(e.getAttribute('x')) + Number(e.getAttribute('width')), b: Number(e.getAttribute('y')) + Number(e.getAttribute('height')) })));
        const box = { x: Math.min(...inner.map((i) => i.x)), y: Math.min(...inner.map((i) => i.y)), r: Math.max(...inner.map((i) => i.r)), b: Math.max(...inner.map((i) => i.b)) };
        const part = (sw * sh) / (sw * sh + (box.r - box.x) * (box.b - box.y));
        const want = photos.snaps / photos.bytes;
        if (Math.abs(part - want) > 0.02) fail(`${width} px: the strip is ${part.toFixed(3)} of the cell, its snapshots ${want.toFixed(3)}`);
        if (box.r > sx + sw + 0.5 || box.b > sy + sh + 0.5 || (box.r > sx + 0.5 && box.b > sy + 0.5)) fail(`${width} px: the content reaches into the strip (${JSON.stringify(box)}, strip ${sx},${sy} ${sw}×${sh})`);
        // on the side that keeps the content closer to square
        const [cw, ch] = [box.r - box.x, box.b - box.y];
        const off = (a, b) => Math.max(a / b, b / a);
        const other = sw > sh ? off(cw * (1 - want), ch / (1 - want)) : off(cw / (1 - want), ch * (1 - want));
        if (off(cw, ch) > other + 0.05) fail(`${width} px: the strip on the worse side: the content ${cw.toFixed(0)}×${ch.toFixed(0)}`);
        if (!(await strip.evaluate((e) => getComputedStyle(e).fill.includes('url(')))) fail(`${width} px: the strip is not hatched`);
        if (await page2.evaluate(() => [...document.querySelectorAll('#cl-map-snaphatch line')].some((l) => getComputedStyle(l).stroke === 'none'))) fail(`${width} px: the strip's hatching has no lines`);
        if (width > 640) {
          await strip.hover({ force: true });
          const said = await page2.locator('.cl-space-tminfo').textContent();
          if (!said.startsWith('photos') || !said.includes('of it in snapshots')) fail(`pointing at the strip: the line says «${said}»`);
          await strip.click({ force: true });
          await page2.waitForSelector('.cl-map-crumbs', { timeout: 3000 }).catch(() => fail('a click on the strip did not open the share'));
          if (await page2.locator('.cl-map-snaps').count()) fail('a strip inside the share');
          if (await page2.evaluate(() => document.querySelector('.cl-map-svg').getAttribute('viewBox')) !== '0 0 1000 480') fail('the share opened by the strip is not 480 high');
          await page2.locator('.cl-map-close').click();
          if ((await page2.locator('.cl-map-top').getAttribute('viewBox')) !== '0 0 1000 360') fail('back at the shares the stage is not 360 high');
        }
        if (await page2.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) fail(`${width} px: horizontal scrolling with the strip`);
        res.tested++;
      } catch (e) {
        fail(`the strip, ${width} px: ${String(e.message).split('\n')[0].slice(0, 180)}`);
      }
      await ctx.close();
    }
    h.setState('cleanup-where-map-photos', undefined);
    scanReset();
  }
  // the maps come after the first draw, the largest cell's first
  {
    const films2 = stateFile('cleanup-where-map');
    films2.maps.photos = { path: '/mnt/big/photos', at: 1793540800, took: 47, nodes: 505, bytes: 1000, partial: false };
    h.setState('cleanup-where-map', films2);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    const asked = [];
    page.on('request', (r) => { const m = /part=where-map&share=([^&]+)/.exec(r.url()) || /share=([^&]+).*part=where-map/.exec(r.url()); if (m) asked.push(m[1]); });
    try {
      await page.goto(`${h.url}#/cleanup/where/space`, { waitUntil: 'load' });
      await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 8000 });
      if (asked[0] !== 'films' || asked[1] !== 'photos') fail(`the maps were fetched in the order ${asked.join(', ')}: the largest cell's first`);
      res.tested++;
    } catch (e) {
      fail(`the maps' order: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    await ctx.close();
    scanReset();
  }
  // by finger: a tap shows what it is, a tap on what is shown goes in — at every level; X and «Scan all…» big enough
  {
    const ctx = await browser.newContext({ viewport: { width: 375, height: 800 }, hasTouch: true, isMobile: true, locale: 'en-US' });
    const page = await open(ctx);
    try {
      await page.waitForSelector('.cl-map-cell[data-share="films"] .cl-map-in', { timeout: 5000 });
      const high = async (loc, what) => { const b = await loc.boundingBox(); if (!b || b.height < 44) fail(`touch: ${what} is ${b && b.height} px high, not 44`); };
      await high(page.locator('.cl-map-all'), '«Scan all…»');
      const films = cellOf(page, 'films');
      await films.tap(pos);
      if (await page.locator('.cl-map-crumbs').count()) fail('touch: the first tap on a share went in instead of showing');
      if (!(await page.locator('.cl-space-tminfo').textContent()).startsWith('films')) fail('touch: the first tap on a share showed nothing in the line');
      await films.tap(pos);
      await page.waitForSelector('.cl-map-crumbs', { timeout: 3000 }).catch(() => fail('touch: the second tap on a share did not go in'));
      await high(page.locator('.cl-map-close'), 'the X');
      const before = await crumbsOf(page);
      const cell = page.locator('.cl-map-cell').first();
      await cell.tap(pos);
      if ((await crumbsOf(page)) !== before) fail('touch: the first tap went in instead of showing');
      if (!(await page.locator('.cl-map-info .cl-map-path').count())) fail('touch: the first tap showed nothing');
      await cell.tap(pos);
      if ((await crumbsOf(page)) !== before + 1) fail('touch: the second tap did not go in');
      await page.locator('.cl-map-close').tap();
      if (!(await page.locator('.cl-map-top').count())) fail('touch: the X did not come back');
      // back at the top the first tap on the same share only shows again
      await cellOf(page, 'films').tap(pos);
      if (await page.locator('.cl-map-crumbs').count()) fail('touch: coming back, the first tap went straight in');
      // a share without a map: the first tap its numbers, the second its scan
      const asked = h.posts.length;
      await cellOf(page, 'domains').tap(pos);
      if (h.posts.length !== asked) fail('touch: the first tap started a scan');
      await cellOf(page, 'domains').tap(pos);
      await page.waitForSelector('.cl-map-over .spin', { timeout: 3000 }).catch(() => fail('touch: the second tap did not start the scan'));
      if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) fail('touch: horizontal scrolling');
      res.tested++;
    } catch (e) {
      fail(`touch: ${String(e.message).split('\n')[0].slice(0, 180)}`);
    }
    await ctx.close();
    scanReset();
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}

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
// ------------------------------------------------------------------ a pair's address and the USO network (2026-10-23)
// The first real pairing: both blocks carried the LAN, the pair reached neither way. Accept, in the network, a block with
// a LAN address: this server's USO address by itself (one line), the warning that names the block's address, the
// partner's USO address ticked — the request carries it (their_address), the line shown lets the partner in from there.
// Joining: the wave and the button off, until the network's look gives the address. «Change the address…» from the
// card's hint: the peer of the partner's name picked, the look (try → look) and its outcome said.
async function pairAddress(browser) {
  const res = { route: 'pair address', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({});
  const read = (f) => JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', f), 'utf8'));
  const base = read('caretaker.json');
  const acceptLook = read('post/caretaker.partner_accept.json');
  const lan = { ...acceptLook, partner: { ...acceptLook.partner, address: '192.168.3.20' },
    line: acceptLook.line.replace('from="100.64.0.3"', 'from="192.168.3.20"'), line_match: acceptLook.line,
    usonet: { ...acceptLook.usonet, peer: false, range: false, wait: false, state: 'in', match: { name: 'uso-partnernas', address: '100.64.0.3', online: true, direct: true } } };
  let accepted = null;
  h.setPost('caretaker.partner_accept', (d) => (d.step === 'look' ? lan : (accepted = d, acceptLook)));
  const errors = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'en-US' });
    const page = await ctx.newPage();
    page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
    const dlg = () => page.evaluate(() => { const d = document.querySelector('#sso-dialog-backdrop:not([hidden]) #sso-dialog'); return d ? d.textContent : ''; });
    const lastBtn = '#sso-dialog-foot button:last-child';
    const acceptOpen = async () => {
      await page.goto(`${h.url}#/caretaker/partner`, { waitUntil: 'load' });
      await page.waitForFunction(() => [...document.querySelectorAll('#sso button')].some((b) => /Accept a partner/.test(b.textContent) && !b.disabled), null, { timeout: 15000 });
      await page.evaluate(() => [...document.querySelectorAll('#sso button')].find((b) => /Accept a partner/.test(b.textContent)).click());
      await page.waitForSelector('#sso-dialog textarea', { timeout: 5000 });
      await page.fill('#sso-dialog textarea', 'eyJ2IjoxfQ==');
      await page.click(lastBtn);
      await page.waitForSelector('#sso-dialog .ct-myaddr', { timeout: 5000 });
    };
    // 1. in the network, a block with the LAN: the USO line, the warning with the block's address, the partner's USO address ticked
    await acceptOpen();
    const look = await page.evaluate(() => ({ line: (document.querySelector('#sso-dialog .ct-myaddr .callout strong') || {}).textContent || '',
      warn: [...document.querySelectorAll('#sso-dialog .callout.warn')].map((n) => n.textContent).join(' | '),
      take: !!(document.querySelector('#sso-dialog .ct-outside-take input') || {}).checked,
      fold: !!(document.querySelector('#sso-dialog .ct-myaddr details') || {}).open, picker: !!document.querySelector('#sso-dialog > * > .field select') }));
    res.tested++;
    if (look.line !== 'Over the USO network: 100.64.0.4' || look.fold) fail(`accept, LAN block: not this server's USO address by itself (${JSON.stringify(look)})`);
    if (!/sent 192\.168\.3\.20 — an address outside the USO network/.test(look.warn)) fail(`accept, LAN block: no warning naming the block's address (${look.warn})`);
    if (!look.take) fail('accept, LAN block: the partner\'s USO address is not offered (ticked)');
    await page.click(lastBtn);
    await page.waitForFunction(() => /Write the line and pair/.test((document.querySelector('#sso-dialog-foot') || {}).textContent || ''), null, { timeout: 5000 });
    const line = await page.evaluate(() => (document.querySelector('#sso-dialog pre.code') || {}).textContent || '');
    res.tested++;
    if (!line.includes('from="100.64.0.3"')) fail(`accept, LAN block: the line doesn't let the partner in from its USO address (${line.slice(0, 80)})`);
    await page.check('#sso-dialog label.check input');
    await page.click(lastBtn);
    await page.waitForFunction(() => /Paste this block/i.test((document.querySelector('#sso-dialog') || {}).textContent || '') || document.querySelector('#sso-dialog .ct-pcode'), null, { timeout: 5000 }).catch(() => {});
    res.tested++;
    if (!accepted || accepted.address !== '100.64.0.4' || accepted.port !== 22 || accepted.their_address !== '100.64.0.3' || 'other' in accepted) {
      fail(`accept, LAN block: the request is not the USO address with the partner's USO address (${JSON.stringify(accepted && { address: accepted.address, their: accepted.their_address, other: accepted.other })})`);
    }
    await page.keyboard.press('Escape');

    // 2. joining: the wave, the button off — then the network's look gives the address: the line flips, the button on
    h.setPost('caretaker.partner_accept', (d) => (d.step === 'look' ? { ...lan, usonet: { ...lan.usonet, in: false, address: null, wait: true, state: 'joining', match: null } } : acceptLook));
    await acceptOpen();
    const waiting = await page.evaluate(() => ({ wave: !!document.querySelector('#sso-dialog .ct-net-wait .spin'), off: document.querySelector('#sso-dialog-foot button:last-child').disabled,
      text: (document.querySelector('#sso-dialog .ct-myaddr .callout') || {}).textContent || '' }));
    res.tested++;
    if (!waiting.wave || !waiting.off || !/joining it — a moment/.test(waiting.text)) fail(`accept, joining: no wave, or the button on (${JSON.stringify(waiting)})`);
    const flipped = await page.waitForFunction(() => /Over the USO network: 100\.64\.0\.4/.test((document.querySelector('#sso-dialog .ct-myaddr .callout') || {}).textContent || '')
      && !document.querySelector('#sso-dialog-foot button:last-child').disabled, null, { timeout: 8000 }).then(() => true, () => false);
    res.tested++;
    if (!flipped) fail('accept, joining: the address never came (the dialog didn\'t look at the network)');
    // the fold opened on purpose: the button on, the request says «other»
    await page.keyboard.press('Escape');

    // 3. «Change the address…» from the card's hint: the peer of the partner's name picked, try → look → saved (its door says no yet)
    const pair = { ...base.partners.pairs[0], reachable: false, why: 'unreachable', usonet_match: { name: 'uso-partnernas', address: '100.64.0.3', online: true, direct: true } };
    h.setState('caretaker', { ...base, partners: { ...base.partners, pairs: [pair] } });
    const now = Math.floor(Date.now() / 1000);
    let looks = 0;
    let tried = null;
    const cards = { ...base.partners, pairs: [pair] };
    h.setPost('caretaker.partner_address', (d) => {
      if (d.step === 'try') { tried = d; return { look: { address: d.address, port: d.port, time: now, state: 'looking' }, partners: cards }; }
      if (!tried) return { look: null, partners: cards };          // the dialog's own look when it opens (the pair taken anew)
      looks++;
      return { look: { address: '100.64.0.3', port: 22, time: now, state: looks < 2 ? 'looking' : 'saved', why: 'denied' }, partners: cards };
    });
    await page.goto(`${h.url}#/caretaker/partner?fresh=1`, { waitUntil: 'load' });
    await page.waitForSelector('.ct-switch button', { timeout: 15000 }).catch(() => fail('change: no hint on the card'));
    const hint = await page.evaluate(() => (document.querySelector('.ct-switch') || {}).textContent || '');
    res.tested++;
    if (!/Not reached — PartnerNAS is in the USO network: switch the address to 100\.64\.0\.3/.test(hint)) fail(`change: the card's hint (${hint})`);
    await page.click('.ct-switch button');
    await page.waitForSelector('#sso-dialog .ct-peers input[type=radio]', { timeout: 5000 });
    const picked = await page.evaluate(() => (document.querySelector('#sso-dialog .ct-peers input[type=radio]:checked') || {}).value);
    res.tested++;
    if (picked !== '100.64.0.3') fail(`change: the partner's peer is not picked (${picked})`);
    await page.click(lastBtn);
    const wave = await page.waitForSelector('#sso-dialog .ct-addr-out .spin', { state: 'attached', timeout: 3000 }).then(() => true, () => false);
    const offWhile = await page.evaluate(() => document.querySelector('#sso-dialog-foot button:last-child').disabled);
    res.tested++;
    if (!wave || !offWhile) fail(`change: no wave while it looks, or the button on (${wave}, ${offWhile})`);
    if (!tried || tried.address !== '100.64.0.3' || tried.port !== 22 || tried.id !== pair.id) fail(`change: the try (${JSON.stringify(tried)})`);
    const said = await page.waitForFunction(() => /doesn't let this server in yet/.test((document.querySelector('#sso-dialog .ct-addr-out') || {}).textContent || ''), null, { timeout: 10000 }).then(() => true, () => false);
    const close = await page.evaluate(() => document.querySelector('#sso-dialog-foot button:last-child').textContent);
    res.tested++;
    if (!said || close !== 'Close') fail(`change: the outcome is not said («door says no yet», Close) (${await dlg()})`);
    await page.click(lastBtn);

    // 4. (2026-10-24, nostromo ↔ StedyUnraid) the network card: each name once, its earlier nodes folded and faded under it
    // (one still listed online); the pair reached at its live node: no hint on the card
    h.setState('caretaker', { ...base, partners: { ...base.partners, pairs: [{ ...pair, address: '100.64.0.3', reachable: true, why: null, usonet_match: null }] } });
    await page.goto('about:blank');          // a page loaded anew: its own earlier look of the network (step 2) would be drawn instead
    await page.goto(`${h.url}#/caretaker/partner`, { waitUntil: 'load' });
    await page.waitForSelector('.ct-net .ct-net-older', { state: 'attached', timeout: 15000 }).catch(() => fail('network card: the earlier nodes are not folded under the name'));
    const net = await page.evaluate(() => {
      const rows = [...document.querySelectorAll('.ct-net .row-name')].map((n) => n.textContent);
      const folds = [...document.querySelectorAll('.ct-net details.ct-net-older')];
      return { names: rows.filter((t) => /uso-partnernas/.test(t)).length, folds: folds.map((d) => d.querySelector('summary').textContent), open: folds.some((d) => d.open),
        hint: !!document.querySelector('.ct-switch') };
    });
    res.tested++;
    if (net.names !== 1) fail(`network card: uso-partnernas listed ${net.names} times`);
    if (net.open || !net.folds.includes('2 earlier nodes of this name') || !net.folds.includes('1 earlier node of this server')) fail(`network card: the folds (${JSON.stringify(net.folds)}, open ${net.open})`);
    if (net.hint) fail('card: a «switch the address» hint while the pair answers at its live node');
    await page.click('[data-place="usonet:uso-partnernas"] details.ct-net-older summary');
    const inside = await page.evaluate(() => (document.querySelector('[data-place="usonet:uso-partnernas"] details.ct-net-older[open]') || {}).textContent || '');
    res.tested++;
    if (!/100\.64\.0\.36 · listed as online too/.test(inside) || !/«Machines»/.test(inside)) fail(`network card: the fold's lines (${inside.slice(0, 160)})`);

    // 5. «Start from a partner's copy…» in the network: this server's USO address by itself, the old picker folded; the request carries it
    let started = null;
    h.setPost('caretaker.partner_ticket_start', (d) => (d.step === 'look'
      ? { name: 'Tower', addresses: [{ address: '192.168.1.10', iface: 'br0', private: true }, { address: '100.64.0.4', iface: 'uso-net', private: true, usonet: true }],
        usonet: { in: true, address: '100.64.0.4', port: 22, peers: [], peer: false, range: false, wait: false, state: 'in', match: null } }
      : (started = d, { id: 'a1b2c3d4', block: 'eyJ2IjoxfQ==', partners: base.partners })));
    await page.evaluate(() => [...document.querySelectorAll('#sso button')].find((b) => /Start from a partner/.test(b.textContent)).click());
    await page.waitForSelector('#sso-dialog .ct-myaddr', { timeout: 5000 }).catch(() => fail('ticket start: not this server\'s USO address (no one-line address)'));
    const tline = await page.evaluate(() => ({ line: (document.querySelector('#sso-dialog .ct-myaddr .callout strong') || {}).textContent || '',
      fold: !!(document.querySelector('#sso-dialog .ct-myaddr details') || {}).open, port: [...document.querySelectorAll('#sso-dialog input[type=number]')].some((i) => i.offsetParent) }));
    res.tested++;
    if (tline.line !== 'Over the USO network: 100.64.0.4' || tline.fold || tline.port) fail(`ticket start: ${JSON.stringify(tline)}`);
    await page.click(lastBtn);
    await page.waitForFunction(() => !document.querySelector('#sso-dialog .ct-myaddr'), null, { timeout: 5000 }).catch(() => {});
    res.tested++;
    if (!started || started.address !== '100.64.0.4' || 'other' in started) fail(`ticket start: the request (${JSON.stringify(started)})`);
    await page.keyboard.press('Escape');
    for (const e of [...new Set(errors)]) fail(e);
    await ctx.close();
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
// ------------------------------------------------------------------ several transfers to a partner at once (engine 2.73)
// A run in its partner step sending two datasets side by side (status.json partner.streams): the run card and the bubble
// say «appdata and 1 more», the bytes of both of the estimate of both, the agent's rate (what the run moved to that
// partner) and the time left - at 1440 and 375 px, nothing scrolls sideways, no page error.
async function partnerStreams(browser) {
  const res = { route: 'partner streams', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({});
  const base = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', 'backup.json'), 'utf8'));
  const now = Math.floor(Date.now() / 1000);
  const p0 = base.partners[0];
  const id = p0.id;
  const streams = [{ id, unit: 'share:appdata', since: now - 120, bytes: 3 * 1024 ** 3, estimate: 8 * 1024 ** 3 }, { id, unit: 'share:domains', since: now - 60, bytes: 1024 ** 3, estimate: 2 * 1024 ** 3 }];
  h.setState('backup', { ...base, running: true, holder: { holder: 'backup', mode: 'backup' },
    status: { ...base.status, phase: 'partner', result: 'running', started: now - 300, finished: 0,
      partner: { partners: [{ id, name: p0.name }], planned: ['place', 'share:appdata', 'share:domains'].map((unit) => ({ id, unit })), current: streams[0], streams,
        done: [{ id, unit: 'place', snap: 'uso-backup-20261024-0200', from: null, bytes: 1024 ** 2, seconds: 3, mbit: 2.8, resumed: false }], skipped: [], failed: [], interrupted: null, interrupted_all: [] } },
    partners: [{ ...p0, current: { unit: 'share:appdata', since: now - 120, bytes: 4 * 1024 ** 3, estimate: 10 * 1024 ** 3, units: ['share:appdata', 'share:domains'], rate: 120 * 1024 ** 2, eta: 600 } }, ...base.partners.slice(1)] });
  try {
    for (const width of [1440, 375]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
      const page = await ctx.newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
      await page.goto(`${h.url}#/backup`, { waitUntil: 'load' });
      const ok = await page.waitForFunction(() => /appdata and 1 more/.test((document.querySelector('#sso') || {}).innerText || ''), null, { timeout: 15000 }).then(() => true, () => false);
      res.tested++;
      const words = await page.evaluate(() => (document.querySelector('#sso') || {}).innerText || '');
      if (!ok) fail(`${width} px: «appdata and 1 more» nowhere on the page`);
      const line = words.split('\n').find((l) => /^To .+: appdata and 1 more/.test(l)) || '';
      res.tested++;
      if (!/4(\.0)? GB of ~10(\.0)? GB/.test(line) || !/120 MB\/s/.test(line) || !/about 10 min left/.test(line)) fail(`${width} px: the run card's line is not both transfers with the rate and the time left (${JSON.stringify(line)})`);
      res.tested++;
      if (!/sending appdata and 1 more to /.test(words)) fail(`${width} px: the bubble doesn't say both transfers`);
      const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      res.tested++;
      if (wide) fail(`${width} px: the page scrolls sideways`);
      for (const e of errors) fail(`${width} px: ${e}`);
      await ctx.close();
    }
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
// ------------------------------------------------------------------ the wait's wave never on the page's content (2026-10-25)
// core.js busyPlace(): an action waiting for its answer showed a large wave at the window's middle - on Ms. Snapshotini's
// disk card it sat on «Tour, reading them too» like a broken button. Now, on several pages at both widths and themes:
//   - a wait at the page's top: the slim wave shows (not the corner pill), inside the window, on no control, row or words
//     (beside the desk head it may stand in the window's middle: nothing there);
//   - a dialog's button that posts: the wave in the dialog's foot, on none of the dialog's controls or words;
//   - deep in the page, nothing free: the pill in the bottom right corner, in the window, never at the middle;
//   - a button that waves itself (the disk card's «Tour, reading them too»): no other wave - until it leaves the window;
//   - the wait over: the mark gone.
const BUSY_ROUTES = ['snapshot', 'backup', 'restore', 'caretaker', 'cleanup/tidy', 'logs', 'advisor', 'jack', 'moverelli', 'watchman'];   // every desk
async function busyMark(browser) {
  const res = { route: 'busy mark', theme: 'dark', width: 1440, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({});
  const snap = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', 'snapshot.json'), 'utf8'));
  (snap.zfs.pools || []).forEach((p) => { if (p.array) p.asleep = true; });
  snap.zfs.asleep = (snap.zfs.pools || []).filter((p) => p.array).map((p) => p.name);
  h.setState('snapshot', snap);
  h.setPost('snapshot.scan', () => ({ state: snap }));
  // where the mark is and what lies under it (#sso-busy itself left out; in a dialog only the dialog's own)
  const look = () => {
    const shield = document.getElementById('sso-busy');
    const w = shield && shield.firstElementChild;
    if (!shield || getComputedStyle(shield).display === 'none') return { shown: false };
    if (!w || w.hidden) return { shown: true, wave: false };
    const r = w.getBoundingClientRect();
    shield.style.display = 'none';
    const scope = Office.dialogOpen() ? document.getElementById('sso-dialog') : document.getElementById('sso');
    const under = [...scope.querySelectorAll('button, a, input, select, textarea, summary, label, .row, .unfolds, .chip, h1, h2, h3, p, li, td, th, pre')]
      .filter((e) => { const b = e.getBoundingClientRect(); return b.width && b.height && b.left < r.right && b.right > r.left && b.top < r.bottom && b.bottom > r.top; })
      .map((e) => `${e.tagName.toLowerCase()}${e.className ? '.' + String(e.className).split(' ')[0] : ''} «${(e.textContent || '').trim().slice(0, 30)}»`);
    shield.style.display = 'block';
    const mid = r.left < innerWidth * 0.6 && r.right > innerWidth * 0.4 && r.top < innerHeight * 0.6 && r.bottom > innerHeight * 0.4;
    return { shown: true, wave: true, corner: w.classList.contains('busy-corner'), under, mid, inside: r.left >= 0 && r.top >= 0 && r.right <= document.documentElement.clientWidth && r.bottom <= innerHeight,
      box: [r.left, r.top, r.width, r.height].map(Math.round).join(',') };
  };
  try {
    for (const theme of ['dark', 'light']) {
      for (const width of [1440, 375]) {
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
        await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, theme);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
        // the actions this check starts wait 2.5 s for their answer
        await page.route('**/api.php', async (r) => {
          let a = '';
          try { a = JSON.parse(r.request().postData() || '{}').a || ''; } catch (e) { /* not ours */ }
          if (/^(snapshot\.scan|uc\.slow)$/.test(a)) await new Promise((ok) => setTimeout(ok, 2500));
          await r.continue().catch(() => {});
        });
        const at = `${theme} ${width}`;
        let m;
        for (const route of BUSY_ROUTES) {
          await page.goto(`${h.url}#/${route}`, { waitUntil: 'load' });
          await page.waitForFunction(() => window.Office && Office.current && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), null, { timeout: 15000 }).catch(() => {});
          await page.waitForTimeout(600);
          // at the top: the slim wave on nothing
          await page.evaluate(() => { window.scrollTo(0, 0); Office.busy('uc', true); });
          await page.waitForTimeout(600);
          m = await page.evaluate(look);
          res.tested++;
          if (!m.shown || !m.wave) fail(`${route} ${at}: a wait at the top shows no wave`);
          else if (m.corner || m.under.length || !m.inside) fail(`${route} ${at}: the wave at the top (${m.box}${m.corner ? ', the corner pill' : ''}) lies on ${m.under.join(', ') || 'the window\'s edge'}`);
          // deep in the page: somewhere in the window, never at the middle
          await page.evaluate(() => window.scrollTo(0, (document.documentElement.scrollHeight - innerHeight) / 2));
          await page.waitForTimeout(300);
          m = await page.evaluate(look);
          res.tested++;
          if (!m.shown || !m.wave || !m.inside || m.mid || (!m.corner && m.under.length)) fail(`${route} ${at}: deep in the page the wave is ${!m.wave ? 'missing' : `at ${m.box}${m.under && m.under.length ? ` on ${m.under.join(', ')}` : ''}`}`);
          await page.evaluate(() => Office.busy('uc', false));
          await page.waitForTimeout(100);
          m = await page.evaluate(look);
          res.tested++;
          if (m.shown) fail(`${route} ${at}: the mark stays after the wait`);
          // a dialog's button that posts: the wave in its foot
          await page.evaluate(() => { window.scrollTo(0, 0); Office.dialog({ title: 'Wait', body: 'A moment.', buttons: [{ text: 'Cancel' }, { text: 'Go on', kind: 'primary', act: () => Office.api.post('uc.slow', {}) }] }); });
          await page.click('#sso-dialog-foot button:last-child');
          await page.waitForTimeout(700);
          m = await page.evaluate(look);
          const foot = await page.evaluate(() => { const r = document.getElementById('sso-dialog-foot').getBoundingClientRect(); const w = document.querySelector('#sso-busy span').getBoundingClientRect(); return w.top >= r.top && w.bottom <= r.bottom && w.left >= r.left && w.right <= r.right; });
          res.tested++;
          if (!m.wave || m.corner || !foot || m.under.length) fail(`${route} ${at}: a dialog's wait - the wave ${!m.wave ? 'is missing' : `at ${m.box}${!foot ? ', not in the foot' : ''}${m.under.length ? ` on ${m.under.join(', ')}` : ''}`}`);
          await page.waitForFunction(() => getComputedStyle(document.getElementById('sso-busy')).display === 'none', null, { timeout: 6000 }).catch(() => fail(`${route} ${at}: the dialog's wait never ended`));
          await page.evaluate(() => { if (Office.dialogOpen() && Office.closeDialog) Office.closeDialog(); });
          await page.keyboard.press('Escape');
        }
        // the disk card's «Tour, reading them too» waves itself: no other wave while it is in sight
        await page.goto(`${h.url}#/snapshot`, { waitUntil: 'load' });
        await page.waitForTimeout(1200);
        const tour = await page.evaluate(() => {
          const b = document.querySelector('.sp-disks .card-foot button');
          if (!b) return false;
          b.scrollIntoView({ block: 'center' });
          b.click();
          return true;
        });
        res.tested++;
        if (!tour) fail(`snapshot ${at}: no «Tour, reading them too» on the disk card (the stub's array asleep)`);
        else {
          await page.waitForTimeout(800);
          m = await page.evaluate(look);
          const own = await page.evaluate(() => { const s = document.querySelector('.sp-disks .card-foot button.running .spin'); return !!s && s.getBoundingClientRect().width > 0; });
          if (!own) fail(`snapshot ${at}: the tour's button doesn't wave while the tour runs`);
          if (!m.shown || m.wave) fail(`snapshot ${at}: the tour's button waves - ${!m.shown ? 'no wait at all (clicks not held)' : `another wave at ${m.box}`}`);
          // the button out of sight: the wave comes back
          await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
          await page.waitForTimeout(300);
          const away = await page.evaluate(() => document.querySelector('.sp-disks .card-foot button').getBoundingClientRect().bottom < 0);
          if (away) {
            m = await page.evaluate(look);
            res.tested++;
            if (!m.wave) fail(`snapshot ${at}: the tour's button out of sight - no wave`);
          }
          await page.waitForFunction(() => getComputedStyle(document.getElementById('sso-busy')).display === 'none', null, { timeout: 6000 }).catch(() => fail(`snapshot ${at}: the tour's wait never ended`));
        }
        const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (wide) fail(`${at}: the page scrolls sideways`);
        for (const e of [...new Set(errors)]) fail(`${at}: ${e}`);
        await ctx.close();
      }
    }
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
// ------------------------------------------------------------------ a job gone silent (OFFICE_JOB_STALE, 2026-11-07)
// A restore and a drill whose heartbeat stopped: the agent's word in the state → the sentence on the card, the wave stays,
// «running» gone, no new button; the heartbeat moving on in the job file → the sentence goes at once. Both themes, both widths.
async function staleJob(browser) {
  const res = { route: 'restore', theme: 'stale job', width: 0, tested: 0, skipped: 0, capped: 0, hidden: 0, sections: 0, fails: [], ms: 0 };
  const t0 = Date.now();
  const fail = (what) => res.fails.push(what);
  const h = await startHarness({});
  const now = Math.floor(Date.now() / 1000);
  const hb = now - 31 * 60;
  const base = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', 'restore.json'), 'utf8'));
  const restore = { id: '20261107-120000-aa11', kind: 'files', what: 'photos', result: 'running', started: hb - 120, heartbeat: hb, pid: 4242, finished: null,
    steps: [{ do: 'aside', path: '/mnt/user/photos', state: 'ok' }, { do: 'copy', from: '/a', to: '/mnt/user/photos', state: 'running' }] };
  const drill = { id: '20261107-130000-bb22', kind: 'drill', what: 'now', scope: 'now', result: 'running', started: hb - 120, heartbeat: hb, pid: 4243, finished: null, deadline: now + 7200,
    steps: [{ do: 'package', kind: 'app', id: 'photos', name: 'photos', state: 'running' }, { do: 'package', kind: 'app', id: 'notes', name: 'notes', state: 'pending' }] };
  const dstate = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'states', 'post', 'restore.drill_state.json'), 'utf8'));
  try {
    for (const theme of ['dark', 'light']) {
      for (const width of [1440, 375]) {
        const at = `${theme} ${width}`;
        const ctx = await browser.newContext({ viewport: { width, height: 900 }, locale: 'en-US' });
        await ctx.addInitScript((t) => { try { localStorage.setItem('office.theme', t); } catch (e) { /* none */ } }, theme);
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(`page error: ${String(e.message).slice(0, 160)}`));
        // the restore: the card above the tiles
        h.setState('restore', { ...base, running: { id: restore.id, kind: restore.kind, what: restore.what, result: 'running', started: restore.started, step: 2, steps: 2, stale: true, heartbeat: hb }, drill_running: null });
        h.setState('restore-job', restore);
        await page.goto(`${h.url}#/restore`, { waitUntil: 'load' });
        const there = await page.waitForSelector('.rs-run .rs-stale', { timeout: 15000 }).then(() => true, () => false);
        res.tested++;
        if (!there) fail(`${at}: a silent restore - no sentence on its card`);
        const card = await page.evaluate(() => { const c = document.querySelector('.rs-run'); return c && { text: c.textContent, wave: !!c.querySelector('.spin'), chip: [...c.querySelectorAll('.card-head .chip')].length, buttons: [...c.querySelectorAll('button')].map((b) => b.textContent) }; });
        res.tested++;
        if (!card || !/No sign of life for 31 minutes/.test(card.text)) fail(`${at}: the card's sentence (${JSON.stringify(card)})`);
        else {
          if (!card.wave) fail(`${at}: the wave is gone from the card`);
          if (card.chip) fail(`${at}: the card still says «running»`);
          if (card.buttons.join() !== 'Journal') fail(`${at}: a new button on the card (${card.buttons.join()})`);
        }
        if (theme === 'dark' && width === 1440 && process.env.USO_STALE_SHOTS) await page.screenshot({ path: path.join(process.env.USO_STALE_SHOTS, 'restore-card.png') });
        // it wakes: the job file's heartbeat moves on, the sentence goes at the next poll
        h.setState('restore-job', { ...restore, heartbeat: now });
        const gone = await page.waitForFunction(() => { const c = document.querySelector('.rs-run'); return c && !c.querySelector('.rs-stale') && /running/.test(c.textContent); }, null, { timeout: 8000 }).then(() => true, () => false);
        res.tested++;
        if (!gone) fail(`${at}: awake again, the sentence stays`);
        // an agent that doesn't know the word yet (an older state): as before
        h.setState('restore', { ...base, running: { id: restore.id, kind: restore.kind, what: restore.what, result: 'running', started: restore.started, step: 2, steps: 2 } });
        h.setState('restore-job', restore);
        await page.reload({ waitUntil: 'load' });
        await page.waitForSelector('.rs-run', { timeout: 15000 }).catch(() => {});
        res.tested++;
        if (await page.$('.rs-run .rs-stale')) fail(`${at}: an older agent's state says the sentence`);
        // the drill: its card under the tile
        h.setState('restore', { ...base, running: null, drill_running: { id: drill.id, stale: true, heartbeat: hb } });
        h.setState('restore-job', undefined);
        h.setState('restore-drill-job', drill);
        h.setPost('restore.drill_state', () => ({ ...dstate, running: { ...drill, stale: true } }));
        await page.goto(`${h.url}#/restore/drill`, { waitUntil: 'load' });
        await page.reload({ waitUntil: 'load' });
        const box = await page.waitForSelector('.rs-dr-run .rs-stale', { timeout: 15000 }).then(() => true, () => false);
        res.tested++;
        if (!box) fail(`${at}: a silent drill - no sentence on its card`);
        else {
          const d = await page.evaluate(() => { const c = document.querySelector('.rs-dr-run'); return { text: c.textContent, wave: !!c.querySelector('.spin'), buttons: c.querySelectorAll('button').length }; });
          if (!/No sign of life for 31 minutes/.test(d.text) || !d.wave || d.buttons) fail(`${at}: the drill's card (${JSON.stringify(d)})`);
        }
        if (theme === 'dark' && width === 1440 && process.env.USO_STALE_SHOTS) await page.screenshot({ path: path.join(process.env.USO_STALE_SHOTS, 'drill-card.png') });
        const wide = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        res.tested++;
        if (wide) fail(`${at}: the page scrolls sideways`);
        for (const e of [...new Set(errors)]) fail(`${at}: ${e}`);
        h.setState('restore-drill-job', undefined);
        h.setPost('restore.drill_state', null);
        await ctx.close();
      }
    }
  } catch (e) {
    fail(String(e.message || e).split('\n')[0].slice(0, 200));
  }
  await h.close();
  res.ms = Date.now() - t0;
  return res;
}
const walks = opt.states ? [] : WALK.filter((d) => !opt.only || routes.includes(d));
results.push(...await Promise.all(walks.map((d) => routeWalk(browser, harness, d))));
if (!opt.states && (!opt.only || routes.includes('cleanup/where'))) results.push(await cleanupMap(browser));
if (!opt.states && (!opt.only || routes.includes('jack/setup'))) results.push(await dialogStack(browser, harness));
if (!opt.states && (!opt.only || routes.includes('jack'))) results.push(await jackStructure(browser));
if (!opt.states && (!opt.only || routes.includes('jack'))) results.push(await jackShadow(browser));
if (!opt.states && (!opt.only || routes.includes('caretaker'))) results.push(await firstHome(browser));
if (!opt.states && (!opt.only || routes.includes('caretaker'))) results.push(await netBusy(browser));
if (!opt.states && (!opt.only || routes.includes('caretaker'))) results.push(await pairAddress(browser));
if (!opt.states && (!opt.only || routes.includes('backup'))) results.push(await partnerStreams(browser));
if (!opt.states && (!opt.only || routes.includes('snapshot'))) results.push(await busyMark(browser));
if (!opt.states && (!opt.only || routes.includes('restore'))) results.push(await staleJob(browser));
await browser.close();
await harness.close();

// ------------------------------------------------------------------ a forced red (tools/release.sh's retry, tested)
// UC_FORCE_FAIL=<route>: one page of that route (the first in the table's order) gets a failure of its own - how
// tools/release.sh shows its retry of a red page (USO_RELEASE_FLAKY there). Never set in a real run.
const ONLY_OF = { 'cleanup map': 'cleanup/where', 'dialog stack': 'jack/setup', 'jack structure': 'jack', 'jack shadow': 'jack', 'first start': 'caretaker',
  'USO network card': 'caretaker', 'pair address': 'caretaker', 'partner streams': 'backup', 'busy mark': 'snapshot' };
// the --only route that runs a result's page again (a route walk, a special check: its desk's route)
const onlyOf = (r) => ONLY_OF[r.route] || (r.route.endsWith(' routes') ? r.route.slice(0, -' routes'.length) : r.route);

// ------------------------------------------------------------------ the table
results.sort((a, b) => routes.indexOf(a.route) - routes.indexOf(b.route) || opt.themes.indexOf(a.theme) - opt.themes.indexOf(b.theme) || b.width - a.width);
if (process.env.UC_FORCE_FAIL) {
  const r = results.find((x) => onlyOf(x) === process.env.UC_FORCE_FAIL.replace(/^#?\/?/, ''));
  if (r) r.fails.push('forced red (UC_FORCE_FAIL - a test of tools/release.sh\'s retry, never a real failure)');
}
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
// for tools/release.sh: one line a red page - the --only route that runs it again | the page | its theme | its width
if (failed.length) console.log('');
for (const r of failed) console.log(`ui clicks failed: ${onlyOf(r)}|${r.route}|${r.theme}|${r.width}`);
const clicks = results.reduce((s, r) => s + r.tested, 0);
const v2 = results.reduce((s, r) => s + r.sections, 0);
console.log(`\nui clicks: ${results.length} pages, ${clicks} toggles clicked twice${v2 ? `, ${v2} switcher clicks checked (tiles v2)` : ''}, ${failed.length} failed - ${((Date.now() - started) / 1000).toFixed(0)} s`);
process.exit(failed.length ? 1 : 0);
