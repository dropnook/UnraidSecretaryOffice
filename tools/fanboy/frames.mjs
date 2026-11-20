#!/usr/bin/env node
// Fanboy's turning pictures never change a page's size (2026-11-20, the owner's two recordings: on the set-up tab a
// horizontal scrollbar flashed one to three times a second and the content went 2 px up and down; on the overview the
// page's scrollbar twitched with the mouse at rest). The cause: what turns or flies reached past its box — a turning
// wheel's square corners, the air's streaks — and, near a page's edge, every layout taken mid-turn made the page wider
// or taller for a moment. The rule: whatever fan-art.js animates sits in a box of its own that clips it, and the page's
// scrollWidth, scrollHeight and scroll position never change without input.
// In the office's test harness (tests/ui/harness.mjs) with the invented demo states, in a headless Chrome with a
// throwaway profile, at 1440 and 375 px: the overview before the set-up (the BIOS's turning wheels), the overview while
// he steers, the set-up tab with a test running (the mouse resting on a row's chip), the zone editor (the wheels beside
// the curve). For each:
//   - also with the picture pushed to the window's right edge (the harness pads the page; Unraid's layout may not);
//   - every animated part of his pictures has its clipping box inside the picture (the wheel's .fan-spin, the streaks'
//     .fan-air, the zone's .fan-flow) — fails on code where something turns unclipped, wherever the page's edges are;
//   - every animation stepped through 60 moments of its turn: the page's scrollWidth and scrollHeight stay;
//   - 3 s of animation frames, sampled on each: scrollWidth, scrollHeight and window.scrollY stay.
// The Dashboard tile: `node tools/fanboy/dashboard.mjs --frames` (the same checks, imported from here). Dev-only.
//
//   node tools/fanboy/frames.mjs [--width 1440|375] [-v]
// Exit 1 on a failed check, 3: playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');

/**
 * The checks on a page already drawn: {unclipped: [what turns without a box of its own that clips it], stepped: {w, h}
 * (the sizes seen while every animation is stepped through its turn), frames: {w, h, y, n} (seen on each frame of $secs)}
 */
export async function sizeChecks(page, secs = 3) {
  const unclipped = await page.evaluate(() => {
    const art = '.fan-wheel, .fan-server, .fan-zone, .fan-flow';
    const clipper = (n) => {
      for (let p = n.parentElement; p && p !== document.documentElement; p = p.parentElement) {
        const cs = getComputedStyle(p);
        if ((cs.overflowX !== 'visible' && cs.overflowY !== 'visible') || /paint|strict|content/.test(cs.contain)) return p;
      }
      return null;
    };
    const bad = new Set();
    for (const a of document.getAnimations()) {
      const t = a.effect && a.effect.target;
      if (!t || !t.closest(art)) continue;
      const c = clipper(t);
      // its clipping box must be a part of the picture itself (the wheel, its air, the zone's air) — not the page
      if (!c || !c.closest(art)) bad.add(`${t.tagName.toLowerCase()}.${[...t.classList].join('.')} (clipped by ${c ? `${c.tagName.toLowerCase()}.${[...c.classList].join('.')}` : 'nothing'})`);
    }
    return [...bad];
  });
  const stepped = await page.evaluate(() => {
    const de = document.documentElement;
    const anims = document.getAnimations();
    const w = new Set(), h = new Set();
    anims.forEach((a) => a.pause());
    for (let k = 0; k < 60; k++) {
      anims.forEach((a) => { a.currentTime = k * 47; });
      w.add(de.scrollWidth);
      h.add(de.scrollHeight);
    }
    anims.forEach((a) => a.play());
    return { w: [...w], h: [...h], n: anims.length };
  });
  const frames = await page.evaluate((ms) => new Promise((ok) => {
    const de = document.documentElement;
    const w = new Set(), h = new Set(), y = new Set();
    let n = 0;
    const t0 = performance.now();
    const f = () => {
      n++;
      w.add(de.scrollWidth); h.add(de.scrollHeight); y.add(Math.round(window.scrollY));
      if (performance.now() - t0 < ms) requestAnimationFrame(f); else ok({ w: [...w], h: [...h], y: [...y], n });
    };
    requestAnimationFrame(f);
  }), secs * 1000);
  return { unclipped, stepped, frames };
}

/** The verdicts of sizeChecks() as [what, ok, detail] */
export function sizeVerdicts(tag, r) {
  return [
    [`${tag}: everything that turns or flies is clipped by a box of its own in the picture`, !r.unclipped.length, r.unclipped.join(' | ')],
    [`${tag}: ${r.stepped.n} animations stepped through their turn — the page's size stays`, r.stepped.w.length === 1 && r.stepped.h.length === 1, JSON.stringify(r.stepped)],
    [`${tag}: ${r.frames.n} animation frames — scrollWidth, scrollHeight and the scroll position stay`, r.frames.w.length === 1 && r.frames.h.length === 1 && r.frames.y.length === 1, JSON.stringify(r.frames)],
  ];
}

// ------------------------------------------------------------------------------------------- the desk's own pages
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const require = createRequire(import.meta.url);
  const args = process.argv.slice(2);
  const opt = { widths: [1440, 375], v: false };
  for (let i = 0; i < args.length; i++) {
    if (args[i] === '--width') opt.widths = [Number(args[++i])];
    else if (args[i] === '-v') opt.v = true;
    else { console.log(`fanboy frames: unknown argument ${args[i]}`); process.exit(2); }
  }
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  try { for (const d of fs.readdirSync(path.join(os.homedir(), '.npm', '_npx'))) tries.push(path.join(os.homedir(), '.npm', '_npx', d, 'node_modules', 'playwright-core')); } catch (e) { /* none */ }
  let pw = null;
  for (const t of tries.filter(Boolean)) { try { pw = require(t); break; } catch (e) { /* the next */ } }
  if (!pw || !pw.chromium) { console.log('fanboy frames: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
  const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => p && fs.existsSync(p));
  if (!chrome) { console.log('fanboy frames: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }
  const { startHarness } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));
  const failed = [];
  const check = (what, ok, detail) => { if (!ok) failed.push(`${what}${detail ? ` — ${detail}` : ''}`); else if (opt.v) console.log(`ok  ${what}`); };
  // [name, demo state, route, what to wait for, where the mouse rests, a style that puts the picture at an edge]
  // (the harness pads the page, so its edges are never near a wheel; Unraid's own layout and a window's width can put
  // one there — «at the edge» pushes the picture's right wall to the window's edge and ends the page under it)
  const EDGE = '#sso .fan-server{margin-right:-28px} #sso .section:not(:first-child){display:none}';
  const SCENES = [
    ['overview before the set-up', 'fanboy-fresh', 'fanboy', '#sso .fan-server .fan-wheel', null],
    ['overview, the picture at the window\'s edge', 'fanboy-fresh', 'fanboy', '#sso .fan-server .fan-wheel', null, EDGE],
    ['overview while he steers', 'fanboy', 'fanboy', '#sso .fan-server .fan-wheel', null],
    ['set-up, a test running', 'fanboy-uxrun', 'fanboy/setup/test', '#sso .fb-panel .fan-wheel', '#sso .fb-item .chip'],
    ['zone editor', 'fanboy-ready', 'fanboy/setup/zones', '#sso .fan-wheel', null],
  ];
  const browser = await pw.chromium.launch({ executablePath: chrome, headless: true, args: ['--no-first-run'] });
  try {
    for (const width of opt.widths) {
      for (const [name, alias, route, wait, rest, edge] of SCENES) {
        const h = await startHarness({ demo: true, alias: { fanboy: alias } });
        const ctx = await browser.newContext({ viewport: { width, height: 800 }, isMobile: width < 768, hasTouch: width < 768, colorScheme: 'dark' });
        const page = await ctx.newPage();
        const errors = [];
        page.on('pageerror', (e) => errors.push(String(e)));
        await page.goto(`${h.url}?theme=black#/${route}`, { waitUntil: 'load' });
        await page.waitForSelector(wait, { timeout: 15000 });
        await page.waitForTimeout(1500);
        if (edge) await page.addStyleTag({ content: edge });
        // at the page's end (the owner's first recording) — an edge where a size change shows
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        if (rest && !(width < 768)) { const el = await page.$(rest); if (el) await el.hover(); }
        await page.waitForTimeout(300);
        for (const [what, ok, detail] of sizeVerdicts(`${width} ${name}`, await sizeChecks(page))) check(what, ok, detail);
        check(`${width} ${name}: no page error`, !errors.length, errors.join(' | '));
        await ctx.close();
        if (h.close) await h.close();
      }
    }
  } finally {
    await browser.close();
  }
  if (failed.length) {
    console.log(`fanboy frames: ${failed.length} failed`);
    failed.forEach((f) => console.log(`  ✗ ${f}`));
    process.exit(1);
  }
  console.log('fanboy frames: all checks passed');
}
