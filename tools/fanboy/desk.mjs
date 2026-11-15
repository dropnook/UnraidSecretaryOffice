#!/usr/bin/env node
// Fanboy's page (public/desks/fanboy/desk.js) in the office's test harness (tests/ui/harness.mjs, the invented demo
// states of tests/ui/demo/fanboy*.json — tools/fanboy/demo-states.mjs makes them) in a headless Chrome with a throwaway
// profile: the overview and every step of the setup as pictures (black and white, de and en, 1440 and 390), the
// situations, an animated picture of the overview with its fans turning, and its CPU — measured as tools/fanboy/look.mjs
// measures the pictures alone (Chrome's main thread and all its processes, 8 s, median of 3). Dev-only.
//
//   node tools/fanboy/desk.mjs [--out <folder>] [--no-shots] [--no-cpu] [--no-movie] [--no-callouts] [--only <name>,…] [-v]
//   --callouts (default on): every demo state, both routes shown whole — fails when a callout's text stands twice
// Fails (exit 1) on a console error, a page error or a horizontal scroll. Exit 3: playwright-core or Chrome missing.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import zlib from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);
const args = process.argv.slice(2);
const opt = { out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), 'fanboy-desk'), shots: true, cpu: true, movie: true, callouts: true, only: null, v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--out') opt.out = path.resolve(args[++i]);
  else if (a === '--no-shots') opt.shots = false;
  else if (a === '--no-cpu') opt.cpu = false;
  else if (a === '--no-movie') opt.movie = false;
  else if (a === '--no-callouts') opt.callouts = false;
  else if (a === '--only') opt.only = args[++i].split(',');
  else if (a === '-v') opt.v = true;
  else { console.log(`fanboy desk: unknown argument ${a}`); process.exit(2); }
}
function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* no npx cache */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
if (!pw || !pw.chromium) { console.log('fanboy desk: playwright-core not found (USO_PLAYWRIGHT=<folder>) - not run'); process.exit(3); }
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!chrome) { console.log('fanboy desk: no Chrome found (USO_CHROME=<binary>) - not run'); process.exit(3); }
const { startHarness, DEMO_NAMES_EN } = await import(path.join(REPO, 'tests', 'ui', 'harness.mjs'));

// name → [demo state, route, what to do before the picture (a click), every theme/lang/width or only black de]
const PICTURES = [
  ['overview', 'fanboy', 'fanboy', null, true],
  ['setup-1-detect', 'fanboy-fresh', 'fanboy/setup/detect', null, true],
  ['setup-2-test-running', 'fanboy-test', 'fanboy/setup/test', null, true],
  ['setup-2-test-louder', 'fanboy-ask', 'fanboy/setup/test', null, true],
  ['setup-3-roles', 'fanboy-roles', 'fanboy/setup/roles', null, true],
  ['setup-4-measure-running', 'fanboy-measure', 'fanboy/setup/measure', null, true],
  ['setup-5-zones', 'fanboy-ready', 'fanboy/setup/zones', null, true],
  // round 4: the CPU cooler «Fanboy regelt ihn» behind its tick; given to him (its zone, since when); the zones' degrees and reaction
  ['setup-3-roles-cpu', 'fanboy-roles', 'fanboy/setup/roles', ['.fb-cpu .seg button:nth-child(2)', '.fb-cpu-risk input[type=checkbox]'], false],
  ['setup-5-zones-cpu', 'fanboy-cpu', 'fanboy/setup/zones', null, false],
  ['overview-cpu', 'fanboy-cpu', 'fanboy/fans', null, false],
  ['setup-6-dry', 'fanboy-ready', 'fanboy/setup/dry', '.fb-step[data-step=dry] .fb-bar .btn', true],
  ['setup-7-take', 'fanboy-ready', 'fanboy/setup/take', null, true],
  ['setup-armed', 'fanboy', 'fanboy/setup/take', null, false],
  ['setup-whole', 'fanboy-roles', 'fanboy/setup', null, false],
  ['setup-page-gone', 'fanboy-gone', 'fanboy/setup/test', null, false],
  ['alarm', 'fanboy-alarm', 'fanboy', null, false],
  ['chip-missing', 'fanboy-missing', 'fanboy', null, false],
  ['chip-gone', 'fanboy-chipgone', 'fanboy', null, false],
  ['vm-no-output', 'fanboy-vm', 'fanboy', null, false],
  ['two-drivers', 'fanboy-twins', 'fanboy', null, false],
  ['setup-2-test-obeys', 'fanboy-obey', 'fanboy/setup/test', null, false],
  ['other-controller', 'fanboy-other', 'fanboy', null, false],
  ['waz-beside', 'fanboy-waz', 'fanboy/hints', null, false],
  ['ipmi', 'fanboy-ipmi', 'fanboy', null, false],
  ['no-output', 'fanboy-nooutput', 'fanboy', null, false],
  ['array-stopped', 'fanboy-array', 'fanboy', null, false],
  ['curves', 'fanboy', 'fanboy/today', null, false],
  // round 3: the history's period (the segmented choice above the curves)
  ['curves-1h', 'fanboy', 'fanboy/today', '.fb-period button:nth-child(1)', false],
  ['curves-12h', 'fanboy', 'fanboy/today', '.fb-period button:nth-child(4)', false],
  ['curves-3d', 'fanboy', 'fanboy/today', '.fb-period button:nth-child(6)', false],
  // his picture (avatar.svg; avatar-alarm.svg while something is wrong): the tab row and his head, the reception's card
  ['avatar-head', 'fanboy', 'fanboy', null, 'both', { until: '.deskhead' }],
  // round 3: the tile row (connectors, the warmest spots per kind), the setup shown whole with another controller steering
  ['tiles', 'fanboy', 'fanboy', null, 'both', { until: '[data-sections-body]', before: true }],
  ['tiles-nozones', 'fanboy-fresh', 'fanboy', null, false, { until: '[data-sections-body]', before: true }],
  ['setup-whole-other', 'fanboy-other', 'fanboy/setup', null, 'both', { whole: true }],
  ['avatar-alarm-head', 'fanboy-alarm', 'fanboy', null, 'both', { until: '.deskhead' }],
  ['avatar-reception', 'fanboy', '', null, 'both', { clip: '.desk-card[data-desk=fanboy]', wait: '.desk-card[data-desk=fanboy]' }],
  ['fans', 'fanboy', 'fanboy/fans', null, false],
].filter((p) => !opt.only || opt.only.includes(p[0]));
const LOCALES = { de: 'de-CH', en: 'en-US' };
const faults = [];
const log = (...a) => { if (opt.v) console.log(...a); };
fs.mkdirSync(opt.out, { recursive: true });
// a mark of this run on its Chrome: the CPU measure counts only its processes (another session's Chrome on the same Mac
// carries --remote-debugging-pipe too — counted with it, the numbers were nonsense)
const MARK = `--fanboy-desk-run=${process.pid}`;
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none', MARK] });
const harnesses = new Map();
async function harness(state, lang) {
  const k = `${state}/${lang}`;
  if (!harnesses.has(k)) harnesses.set(k, await startHarness({ demo: true, alias: { fanboy: state }, names: lang === 'de' ? null : DEMO_NAMES_EN }));
  return harnesses.get(k);
}
async function open(state, route, { theme = 'black', lang = 'de', width = 1440, scale = 2, reduce = false, wait = '#sso-desk section' } = {}) {
  const phone = width < 768;
  const h = await harness(state, lang);
  const ctx = await browser.newContext({ viewport: { width, height: phone ? 844 : 900 }, deviceScaleFactor: scale, isMobile: phone, hasTouch: phone,
    locale: LOCALES[lang], colorScheme: theme === 'white' ? 'light' : 'dark', reducedMotion: reduce ? 'reduce' : 'no-preference' });
  await ctx.addInitScript((l) => { try { localStorage.setItem('office.lang', l); localStorage.removeItem('office.theme'); } catch (e) { /* none */ } }, lang);
  const page = await ctx.newPage();
  const tag = `${state} ${route} ${theme} ${lang} ${width}`;
  page.on('console', (m) => { if (m.type() === 'error') faults.push(`${tag}: console: ${m.text().slice(0, 200)}`); });
  page.on('pageerror', (e) => faults.push(`${tag}: ${String(e.message).slice(0, 200)}`));
  page.on('dialog', (d) => d.dismiss().catch(() => {}));
  await page.goto(`${h.url}?theme=${theme}#/${route}`, { waitUntil: 'load' });
  await page.waitForFunction((w) => window.Office && window.FanArt && document.querySelector(w), wait, { timeout: 15000 });
  await page.evaluate(async () => { await document.fonts.ready; });
  await page.waitForTimeout(1500);       // the catalogue in, the speeds glided in
  return { ctx, page, tag };
}

if (opt.shots) {
  for (const [name, state, route, click, every, shot] of PICTURES) {
    const looks = every === 'both' ? [{ theme: 'black', lang: 'de', width: 1440 }, { theme: 'white', lang: 'de', width: 1440 }, { theme: 'black', lang: 'en', width: 390 }, { theme: 'white', lang: 'en', width: 390 }]
      : every ? ['black', 'white'].flatMap((theme) => ['de', 'en'].flatMap((lang) => [1440, 390].map((width) => ({ theme, lang, width }))))
        : [{ theme: 'black', lang: 'de', width: 1440 }, { theme: 'white', lang: 'en', width: 390 }];
    for (const l of looks) {
      try {
        const { ctx, page, tag } = await open(state, route, { ...l, ...(shot && shot.wait ? { wait: shot.wait } : {}) });
        for (const one of click ? [].concat(click) : []) {        // one selector or several, clicked in turn
          await page.click(one);
          await page.waitForTimeout(900);
        }
        const wide = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        if (wide > 1) faults.push(`${tag}: the page scrolls sideways by ${wide} px`);
        const file = path.join(opt.out, `${name}-${l.theme}-${l.lang}-${l.width}.png`);
        if (shot && shot.whole) {
          // the page shown whole: the tile the page opened with clicked off again
          await page.evaluate(() => { const on = document.querySelector('#sso-desk button.stat[aria-pressed=true]'); if (on) on.click(); });
          await page.waitForTimeout(700);
        }
        if (shot && shot.until) {
          const box = await page.locator(shot.until).first().boundingBox();
          await page.screenshot({ path: file, clip: { x: 0, y: 0, width: l.width, height: Math.ceil(shot.before ? box.y : box.y + box.height + 12) } });
        } else if (shot && shot.clip) await page.locator(shot.clip).first().screenshot({ path: file });
        else await page.screenshot({ path: file, fullPage: true, animations: 'allow', caret: 'hide' });
        log(file);
        if (name === 'overview') await page.locator('.fb-server').screenshot({ path: path.join(opt.out, `overview-picture-${l.theme}-${l.lang}-${l.width}.png`) });
        await ctx.close();
      } catch (e) {
        faults.push(`${name} ${l.theme} ${l.lang} ${l.width}: ${String(e.message).split('\n')[0]}`);
      }
    }
  }
  // reduced motion: still wheels with their arcs
  const { ctx, page } = await open('fanboy', 'fanboy/who', { reduce: true });
  await page.screenshot({ path: path.join(opt.out, 'overview-still-black-de-1440.png'), fullPage: true });
  const still = await page.evaluate(() => document.getAnimations().filter((a) => a.playState === 'running').length);
  if (still) faults.push(`reduced motion: ${still} animations still running`);
  await ctx.close();
}

// ------------------------------------------------------------------ no text twice: every demo state, both pages shown whole
// (round 3, the owner: with another controller the setup page said the same warning three times) — every callout's text
// once on a page, never again in a step
if (opt.callouts) {
  const states = fs.readdirSync(path.join(REPO, 'tests', 'ui', 'demo')).filter((f) => /^fanboy(-[a-z]+)?\.json$/.test(f) && f !== 'fanboy-history.json').map((f) => f.replace(/\.json$/, ''));
  let pages = 0;
  for (const state of states) {
    for (const route of ['fanboy', 'fanboy/setup']) {
      const { ctx, page, tag } = await open(state, route, { scale: 1 });
      await page.evaluate(() => { const on = document.querySelector('#sso-desk button.stat[aria-pressed=true]'); if (on) on.click(); });
      await page.waitForTimeout(500);
      const texts = await page.evaluate(() => [...document.querySelectorAll('#sso-desk .callout')].map((c) => c.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
      const twice = [...new Set(texts.filter((x, i) => texts.indexOf(x) !== i))];
      for (const x of twice) faults.push(`${tag}: the same callout twice on the page shown whole — «${x.slice(0, 90)}»`);
      pages++;
      await ctx.close();
    }
  }
  console.log(`fanboy desk: callouts — ${pages} pages shown whole (${states.length} demo states × 2 routes), ${faults.filter((f) => f.includes('callout twice')).length} with a text twice`);
}

// ------------------------------------------------------------------ the overview's picture as an animated PNG (as look.mjs)
function chunks(buf) {
  const out = [];
  for (let o = 8; o < buf.length;) {
    const len = buf.readUInt32BE(o), type = buf.toString('latin1', o + 4, o + 8);
    out.push({ type, data: buf.subarray(o + 8, o + 8 + len) });
    o += 12 + len;
  }
  return out;
}
function chunk(type, data) {
  const head = Buffer.alloc(8);
  head.writeUInt32BE(data.length, 0);
  head.write(type, 4, 'latin1');
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(zlib.crc32(Buffer.concat([head.subarray(4), data])) >>> 0, 0);
  return Buffer.concat([head, data, crc]);
}
function apng(frames, fps) {
  const ihdr = chunks(frames[0]).find((c) => c.type === 'IHDR').data;
  const w = ihdr.readUInt32BE(0), h = ihdr.readUInt32BE(4);
  const parts = [frames[0].subarray(0, 8), chunk('IHDR', ihdr)];
  const ac = Buffer.alloc(8);
  ac.writeUInt32BE(frames.length, 0);
  parts.push(chunk('acTL', ac));
  let seq = 0;
  frames.forEach((f, i) => {
    const fc = Buffer.alloc(26);
    fc.writeUInt32BE(seq++, 0); fc.writeUInt32BE(w, 4); fc.writeUInt32BE(h, 8);
    fc.writeUInt16BE(1, 20); fc.writeUInt16BE(fps, 22);
    parts.push(chunk('fcTL', fc));
    for (const c of chunks(f).filter((x) => x.type === 'IDAT')) {
      if (i === 0) { parts.push(chunk('IDAT', c.data)); continue; }
      const sq = Buffer.alloc(4);
      sq.writeUInt32BE(seq++, 0);
      parts.push(chunk('fdAT', Buffer.concat([sq, c.data])));
    }
  });
  parts.push(chunk('IEND', Buffer.alloc(0)));
  return Buffer.concat(parts);
}
if (opt.movie) {
  const fps = 25, n = 75;
  const { ctx, page } = await open('fanboy', 'fanboy/who', { scale: 1, width: 1200 });
  const box = await page.locator('.fb-server').boundingBox();
  await page.evaluate(() => {
    window.fbFrames = document.getAnimations().map((a) => ({ a, t: a.currentTime, r: a.playState === 'running' ? a.playbackRate : 0 }));
    for (const f of window.fbFrames) f.a.pause();
  });
  const frames = [];
  for (let k = 0; k < n; k++) {
    await page.evaluate(([k, ms]) => { for (const f of window.fbFrames) f.a.currentTime = f.t + k * ms * f.r; }, [k, 1000 / fps]);
    frames.push(await page.screenshot({ clip: { x: box.x - 8, y: box.y - 8, width: Math.ceil(box.width + 16), height: Math.ceil(box.height + 16) } }));
  }
  fs.writeFileSync(path.join(opt.out, 'overview-turning.png'), apng(frames, fps));
  log('overview-turning.png', Math.round(fs.statSync(path.join(opt.out, 'overview-turning.png')).size / 1024) + ' KB');
  await ctx.close();
}

// ------------------------------------------------------------------ CPU: the finished overview, as look.mjs measures
function chromeTree() {
  const rows = execFileSync('ps', ['-A', '-o', 'pid=,ppid=,time=,command='], { encoding: 'utf8' }).split('\n').filter(Boolean).map((l) => {
    const m = /^\s*(\d+)\s+(\d+)\s+(\S+)\s+(.*)$/.exec(l);
    return m && { pid: +m[1], ppid: +m[2], time: m[3], cmd: m[4] };
  }).filter(Boolean);
  const secs = (t) => t.split(/[:-]/).reverse().reduce((s, x, i) => s + parseFloat(x) * [1, 60, 3600, 86400][i], 0);
  const roots = rows.filter((r) => r.cmd.startsWith(chrome) && r.cmd.includes(MARK) && !rows.some((p) => p.pid === r.ppid && p.cmd.startsWith(chrome)));
  const keep = new Set(roots.map((r) => r.pid));
  for (let grew = true; grew;) { grew = false; for (const r of rows) if (!keep.has(r.pid) && keep.has(r.ppid)) { keep.add(r.pid); grew = true; } }
  return new Map(rows.filter((r) => keep.has(r.pid)).map((r) => [r.pid, secs(r.time)]));
}
const spent = (a, b) => [...b].reduce((s, [pid, t]) => s + (a.has(pid) ? t - a.get(pid) : 0), 0);
async function measure(state, route, reduce, secs) {
  const { ctx, page } = await open(state, route, { scale: 1, reduce });
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Performance.enable');
  await page.waitForTimeout(3000);
  const metric = async () => Object.fromEntries((await cdp.send('Performance.getMetrics')).metrics.map((m) => [m.name, m.value]));
  const a = await metric(), ca = chromeTree(), t0 = Date.now();
  await page.waitForTimeout(secs * 1000);
  const b = await metric(), cb = chromeTree(), dt = (Date.now() - t0) / 1000;
  const wheels = await page.evaluate(() => document.querySelectorAll('.fan-wheel').length);
  const streaks = await page.evaluate(() => document.querySelectorAll('.fan-streak').length);
  await ctx.close();
  return { wheels, streaks, main: 100 * (b.TaskDuration - a.TaskDuration) / dt, all: 100 * spent(ca, cb) / dt };
}
if (opt.cpu) {
  // the page polls the agent every 5 s (here the harness): each look draws the page again and glides the speeds
  const looks = [['fanboy', 'fanboy/hints', false, 'baseline: his page without the picture (the hints)'],
    ['fanboy', 'fanboy', false, 'the overview: picture, fans, sensors, curves'],
    ['fanboy', 'fanboy/who', false, 'the server picture alone (tile «Who steers»)'],
    ['fanboy', 'fanboy', true, 'the overview, reduced motion (still wheels)'],
    ['fanboy-ready', 'fanboy/setup/zones', false, 'the setup\'s zones step (curve editors)']];
  const got = looks.map(() => []);
  for (let round = 0; round < 3; round++) for (const [i, [s, r, red]] of looks.entries()) got[i].push(await measure(s, r, red, 8));
  const median = (list, key) => list.map((r) => r[key]).sort((x, y) => x - y)[1];
  const lines = looks.map(([, , , name], i) => `${name.padEnd(52)} wheels ${String(got[i][0].wheels).padStart(2)} streaks ${String(got[i][0].streaks).padStart(2)}  main thread ${median(got[i], 'main').toFixed(2)} %`
    + `  all Chrome processes ${median(got[i], 'all').toFixed(1)} % of one core  (runs: ${got[i].map((r) => r.all.toFixed(1)).join(' / ')})`);
  const text = `Fanboy's page: CPU in headless Chrome (${new Date().toISOString().slice(0, 16)}, ${os.cpus()[0].model}, load ${os.loadavg()[0].toFixed(1)}, 1440×900, scale 1, 8 s each, median of 3)\n${lines.join('\n')}\n`;
  fs.writeFileSync(path.join(opt.out, 'cpu.txt'), text);
  console.log(text.trim());
}

await browser.close();
for (const h of harnesses.values()) await h.close();
if (faults.length) { console.log('fanboy desk: ' + faults.length + ' fault(s)\n  ' + [...new Set(faults)].join('\n  ')); process.exit(1); }
console.log(`fanboy desk: done → ${opt.out}`);
