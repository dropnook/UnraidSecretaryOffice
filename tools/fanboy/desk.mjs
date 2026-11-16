#!/usr/bin/env node
// Fanboy's page (public/desks/fanboy/desk.js) in the office's test harness (tests/ui/harness.mjs, the invented demo
// states of tests/ui/demo/fanboy*.json — tools/fanboy/demo-states.mjs makes them) in a headless Chrome with a throwaway
// profile: the overview and every step of the setup as pictures (black and white, de and en, 1440 and 390), the
// situations, an animated picture of the overview with its fans turning, and its CPU — measured as tools/fanboy/look.mjs
// measures the pictures alone (Chrome's main thread and all its processes, 8 s, median of 3). Dev-only.
//
//   node tools/fanboy/desk.mjs [--out <folder>] [--no-shots] [--no-cpu] [--no-movie] [--no-callouts] [--only <name>,…] [-v]
//   --callouts (default on): every demo state, both routes shown whole — fails when a callout's text stands twice
//   --nums (default on; --no-nums): the steps' sentences that take numbers from the state, each compared with the demo state shown
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
const opt = { out: path.join(process.env.USO_SHOTS || path.join(os.homedir(), 'uso-shots'), 'fanboy-desk'), shots: true, cpu: true, movie: true, callouts: true, nums: true, only: null, v: false };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--out') opt.out = path.resolve(args[++i]);
  else if (a === '--no-shots') opt.shots = false;
  else if (a === '--no-cpu') opt.cpu = false;
  else if (a === '--no-movie') opt.movie = false;
  else if (a === '--no-callouts') opt.callouts = false;
  else if (a === '--no-nums') opt.nums = false;
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
  ['setup-2-test-assign', 'fanboy-roles', 'fanboy/setup/test', null, true],
  ['setup-4-measure-running', 'fanboy-measure', 'fanboy/setup/measure', null, true],
  ['setup-5-zones', 'fanboy-ready', 'fanboy/setup/zones', null, true],
  // round 4: the CPU cooler «Fanboy regelt ihn» behind its tick; given to him (its zone, since when); the zones' degrees and reaction
  ['setup-2-test-assign-cpu', 'fanboy-roles', 'fanboy/setup/test', ['.fb-item[data-row$=pwm1]', '.fb-cpu .seg button:nth-child(2)', '.fb-cpu-risk input[type=checkbox]'], false],
  ['setup-5-zones-cpu', 'fanboy-cpu', 'fanboy/setup/zones', null, false],
  ['overview-cpu', 'fanboy-cpu', 'fanboy/fans', null, false],
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
  // round 6: 1 the fans he doesn't steer, watched (before any setup)
  ['r6-1-watch', 'fanboy-watch', 'fanboy/fans', '.fb-more summary', false],
  // round 6: 4 «show me this fan» — a button per output (here the disk cage shown right now)
  ['r6-4-show', 'fanboy-show', 'fanboy/fans', '.page-help summary', false],
  // round 6: 5 old disk values — the hint says how old and what his zones add
  ['r6-5-old-disks', 'fanboy', 'fanboy/hints', '.fb-hints summary', false],
  // round 7: F3 nobody steers — outputs left on a fixed value
  ['r7-3-fixed', 'fanboy-fixed', 'fanboy', null, false],
  // round 7: F2 the test step's cards keep their size through a test (running, then done)
  ['r7-2-test-running', 'fanboy-test', 'fanboy/setup/test', null, false],
  ['r7-2-test-done', 'fanboy-ask', 'fanboy/setup/test', null, false],
  // round 8: G1 the quiet test — the switch, and a test running with the other fans standing
  ['r8-1-quiet-running', 'fanboy-quiet', 'fanboy/setup/test', null, false],
  ['r8-1-quiet-switch', 'fanboy-ask', 'fanboy/setup/test', '.page-help summary', false],
  // round 8: «Testen und zuordnen» — before, during (fields in use, «Gefunden — weiter»), after
  ['r8-2-assign-before', 'fanboy-fresh', 'fanboy/setup/test', null, false],
  ['r8-2-assign-during', 'fanboy-quiet', 'fanboy/setup/test', null, false],
  ['r8-2-assign-after', 'fanboy-roles', 'fanboy/setup/test', null, false],
  // round 9: H2 a fan at 100 % for safety, undone on its card; H3 what runs now (a quiet test, the pause)
  ['r9-2-safety', 'fanboy-safety', 'fanboy/setup/test', null, false],
  ['r9-2-safety-fans', 'fanboy-safety', 'fanboy/fans', null, false],
  ['r9-3-now-full', 'fanboy-quiet', 'fanboy/setup/test', null, false],
  ['r9-3-now-pause', 'fanboy-pause', 'fanboy/setup/test', null, false],
  // the setup as one flow (fanboy-ux): step 2's list and panel — before the first test, a quiet test running with the
  // fields in use, the restart pause, an output found and assigned, one set to full for safety, a refused click
  ['ux-2-1-before', 'fanboy-fresh', 'fanboy/setup/test', null, false],
  ['ux-2-2-running', 'fanboy-uxrun', 'fanboy/setup/test', null, false],
  ['ux-2-3-pause', 'fanboy-uxpause', 'fanboy/setup/test', null, false],
  ['ux-2-4-assigned', 'fanboy-uxfound', 'fanboy/setup/test', ['.fb-item[data-row$=pwm4]', '.fb-panel[data-fan$=pwm4] .fb-fold summary'], false],
  ['ux-2-7-look', 'fanboy-uxfound', 'fanboy/setup/test', ['.fb-item[data-row$=pwm5]', '.fb-panel[data-fan$=pwm5] .fb-fold summary'], false],      // «Aussehen (fürs Bild)»: a black copy of a beige model — «Von dir angepasst»
  ['ux-2-5-safety', 'fanboy-uxsafety', 'fanboy/setup/test', null, false],
  // … and the other steps in the same parts: rows to read, the measuring (idle and running), the zones (none yet, two), the
  // dry run (before and after), take over and handed over; the overview while a show runs
  ['ux-1-detect', 'fanboy-fresh', 'fanboy/setup/detect', null, false],
  ['ux-3-1-measure', 'fanboy-uxfound', 'fanboy/setup/measure', null, false],
  ['ux-3-2-measuring', 'fanboy-measure', 'fanboy/setup/measure', null, false],
  // round 12: the standstill part of the measuring, what it found, and «may stand still» by that proof (the CPU cooler's zone)
  ['ux-3-3-stopcheck', 'fanboy-uxstop', 'fanboy/setup/measure', null, false],
  ['ux-3-4-stand', 'fanboy-uxstand', 'fanboy/setup/measure', '.fb-item[data-row$=pwm5]', false],
  ['ux-4-3-zones-stand', 'fanboy-uxstand', 'fanboy/setup/zones', '.fb-item[data-row=cpu]', false],
  ['ux-4-1-zones-none', 'fanboy-zones', 'fanboy/setup/zones', null, false],
  ['ux-4-2-zones', 'fanboy-ready', 'fanboy/setup/zones', null, false],
  ['ux-4-4-zones-kinds', 'fanboy-steering', 'fanboy/setup/zones', '.fb-item[data-row=case]', false],       // round 13: one chart, a curve per kind, who leads
  ['ux-4-5-zones-devs', 'fanboy-uxdevs', 'fanboy/setup/zones', null, false],                               // round 13b: «Nicht zugewiesen / an VMs», «in Gebrauch, Temperatur unbekannt»
  ['ux-4-6-zones-hub', 'fanboy-uxhub', 'fanboy/setup/zones', '.fb-item[data-row=case]', false],           // round 14: «darf stehen» waits — the way out right there
  ['ux-4-7-zones-loose', 'fanboy-uxloose', 'fanboy/setup/zones', null, false],                             // four sleeping disks outside array and pools, in no zone: a calm hint
  ['ux-5-3-take-loose', 'fanboy-uxloose', 'fanboy/setup/take', null, false, { settle: 1500 }],
  ['ux-2-8-alike', 'fanboy-uxhub', 'fanboy/setup/test', '.fb-item[data-row$=pwm5]', false],                // round 14: «Gleiche Lüfter an allen Steckern?»
  ['ux-3-5-hub', 'fanboy-uxhub', 'fanboy/setup/measure', '.fb-item[data-row$=pwm5]', false],               // … the margin in words, «Mit den Augen prüfen»
  ['ux-3-6-hub-look', 'fanboy-uxhublook', 'fanboy/setup/measure', '.fb-item[data-row$=pwm5]', false],      // … the look running: «Drehen alle Lüfter bei 40 %?»
  ['ux-5-1-take', 'fanboy-ready', 'fanboy/setup/take', null, false, { settle: 1500 }],       // with «Das würde ich jetzt setzen» (the dry run's answer)
  ['ux-5-2-steers', 'fanboy-cpu', 'fanboy/setup/take', null, false],
  ['ux-0-overview-show', 'fanboy-show', 'fanboy/fans', null, false],
  ['ux-0-net', 'fanboy-uxnet', 'fanboy', null, false],                 // the net: a heat source in no zone is warm — said in the banner and under «Zuletzt»
  ['ux-2-6-refused', 'fanboy-uxopen', 'fanboy/setup/test', '.fb-panel-acts .btn', false,
    { post: { 'fanboy.test_up': { ok: false, error: { key: 'fanboy_quiet_warm', params: { what: 'CPU', c: 72 } } } }, settle: 7000 }],      // (the toast gone: the strip keeps the refusal)
].filter((p) => !opt.only || opt.only.some((o) => p[0] === o || (o.endsWith('*') && p[0].startsWith(o.slice(0, -1)))));
const LOCALES = { de: 'de-CH', en: 'en-US' };
const faults = [];
const log = (...a) => { if (opt.v) console.log(...a); };
fs.mkdirSync(opt.out, { recursive: true });
// a mark of this run on its Chrome: the CPU measure counts only its processes (another session's Chrome on the same Mac
// carries --remote-debugging-pipe too — counted with it, the numbers were nonsense)
const MARK = `--fanboy-desk-run=${process.pid}`;
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check', '--font-render-hinting=none', MARK] });
const harnesses = new Map();
async function harness(state, lang, post) {
  const k = `${state}/${lang}/${post ? JSON.stringify(post) : ''}`;
  if (!harnesses.has(k)) {
    const h = await startHarness({ demo: true, alias: { fanboy: state }, names: lang === 'de' ? null : DEMO_NAMES_EN });
    // a picture's own answer to an action (a refusal to show how the page says it)
    for (const [action, answer] of Object.entries(post || {})) h.setPost(action, () => answer);
    harnesses.set(k, h);
  }
  return harnesses.get(k);
}
async function open(state, route, { theme = 'black', lang = 'de', width = 1440, scale = 2, reduce = false, wait = '#sso-desk section', post = null } = {}) {
  const phone = width < 768;
  const h = await harness(state, lang, post);
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
        const { ctx, page, tag } = await open(state, route, { ...l, ...(shot && shot.wait ? { wait: shot.wait } : {}), ...(shot && shot.post ? { post: shot.post } : {}) });
        for (const one of click ? [].concat(click) : []) {        // one selector or several, clicked in turn
          await page.click(one);
          await page.waitForTimeout(900);
        }
        if (shot && shot.settle) await page.waitForTimeout(shot.settle);
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
const demoStates = fs.readdirSync(path.join(REPO, 'tests', 'ui', 'demo')).filter((f) => /^fanboy(-[a-z]+)?\.json$/.test(f) && f !== 'fanboy-history.json').map((f) => f.replace(/\.json$/, ''));
if (opt.callouts) {
  const states = demoStates;
  let pages = 0;
  for (const state of states) {
    for (const route of ['fanboy', 'fanboy/setup']) {
      const { ctx, page, tag } = await open(state, route, { scale: 1 });
      await page.evaluate(() => { const on = document.querySelector('#sso-desk button.stat[aria-pressed=true]'); if (on) on.click(); });
      await page.waitForTimeout(500);
      const callouts = () => page.evaluate(() => [...document.querySelectorAll('#sso-desk .callout')].filter((c) => c.getClientRects().length)
        .map((c) => c.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
      const texts = await callouts();
      const twice = [...new Set(texts.filter((x, i) => texts.indexOf(x) !== i))];
      for (const x of twice) faults.push(`${tag}: the same callout twice on the page shown whole — «${x.slice(0, 90)}»`);
      // … and after a visit to his other page and back (2026-11-15, the owner's server: a banner made anew at every visit
      // left the old one standing — the same red text twice): never two visible banners with the same text
      const other = route === 'fanboy' ? '#/fanboy/setup' : '#/fanboy';
      for (const hash of [other, `#/${route}`, other, `#/${route}`]) {
        await page.evaluate((h) => { location.hash = h; }, hash);
        await page.waitForTimeout(700);
      }
      const back = await callouts();
      const again = [...new Set(back.filter((x, i) => back.indexOf(x) !== i))];
      for (const x of again) faults.push(`${tag}: the same callout twice after a visit to the other page and back — «${x.slice(0, 90)}»`);
      const banners = await page.evaluate(() => document.querySelectorAll('#sso-desk .fb-banner').length);
      if (banners !== 1) faults.push(`${tag}: ${banners} banner elements on the page (exactly one, moved — never made anew)`);
      pages++;
      await ctx.close();
    }
  }
  console.log(`fanboy desk: callouts — ${pages} pages shown whole and again after the other page and back (${states.length} demo states × 2 routes), ${faults.filter((f) => f.includes('callout twice') || f.includes('banner elements')).length} with a text twice`);
}
if (opt.nums) {
  let numbers = 0;
  const states = demoStates;
  // every number a sentence of his page takes from the state equals the state's value at that moment (2026-11-15, the
  // owner: a confirmation named degrees and seconds nobody had checked): elements carry data-nums = [{v, from: [path…],
  // via?}] — a path part «=<id>» picks the entry with that id; `via` names how the page derives it (as the agent does)
  const VIA = { floor: (f) => { if (typeof f.floor === 'number') return f.floor; const b = f.measured && f.found === 'tach' && typeof f.min_pct === 'number' ? Math.min(100, f.min_pct + 5) : 40; return f.role === 'cpu' && f.cpu_own ? Math.max(b, 40) : b; },
    pct255: (v) => Math.round(v / 2.55), round: (v) => Math.round(v) };
  for (const state of states) {
    const demo = JSON.parse(fs.readFileSync(path.join(REPO, 'tests', 'ui', 'demo', `${state}.json`), 'utf8'));
    if (!((demo.setup || {}).fans || []).some((f) => f.measured) && !((demo.setup || {}).zones || []).length) continue;
    for (const route of ['fanboy/setup/measure', 'fanboy/setup/zones']) {
      const { ctx, page, tag } = await open(state, route, { scale: 1 });
      // every row once: each panel's sentences
      const rows = await page.locator('#sso-desk .fb-item:not(.fb-item-read)').count();
      for (let k = 0; k < Math.max(1, rows); k++) {
        if (rows) { await page.locator('#sso-desk .fb-item:not(.fb-item-read)').nth(k).click(); await page.waitForTimeout(350); }
        const marked = await page.evaluate(() => [...document.querySelectorAll('#sso-desk [data-nums]')].filter((n) => n.getClientRects().length).map((n) => ({ text: n.textContent, nums: n.dataset.nums })));
        for (const m of marked) {
          for (const x of JSON.parse(m.nums || '[]')) {
            let at = demo;
            for (const part of x.from) at = at == null ? at : String(part).startsWith('=') ? (Array.isArray(at) ? at.find((e) => e && e.id === String(part).slice(1)) : undefined) : at[part];
            const want = x.via ? VIA[x.via](at) : at;
            numbers++;
            if (want !== x.v) faults.push(`${tag}: a sentence shows ${x.v} where the state has ${JSON.stringify(want)} (${x.from.join('/')}) — «${m.text.slice(0, 80)}»`);
            else if (!m.text.replace(/[^0-9]/g, '').includes(String(x.v).replace(/[^0-9]/g, ''))) faults.push(`${tag}: the state's ${x.v} (${x.from.join('/')}) is not in its sentence — «${m.text.slice(0, 80)}»`);
          }
        }
      }
      await ctx.close();
    }
  }
  console.log(`fanboy desk: ${numbers} numbers in marked sentences compared with the state (${faults.filter((f) => f.includes('where the state has') || f.includes('is not in its sentence')).length} wrong)`);
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
