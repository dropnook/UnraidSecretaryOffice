#!/usr/bin/env node
// INV-TRUTH on the Mac: the pages drawn from a look's state files (as page.mjs draws them) compared with what really
// protects each unit — the truth tools/scenarios/truth-facts.php read on the server at the same look (truth.json, never
// computed from the office's code). Each rule: «when the server says X, the page must not show Y» (the truth review
// 2026-10, its INV-TRUTH proposal R1–R7; tools/scenarios/README.md «INV-TRUTH»).
//
//   node tools/scenarios/truth.mjs --look <dir> [--lang en,de] [--json] [--tree <tag>] [--only <rule>,…] [--verbose]
//   node tools/scenarios/truth.mjs --self-test     tests/ui/states/truth with its invented truth inv-truth.json: exactly
//                                                  the lies listed in its `expect` red, in English and German
//
// <dir> holds states/ (the data folder's *.json, as bubble_look copies them) and truth.json (truth-facts.php). Prints one
// line per finding — «FAIL <rule> <unit>: page «…»; truth: …» — and «note» lines for what only looks odd (a share partly
// on a disk without snapshots); a last line «truth: n checked, m red». Exit 0 nothing red, 1 red, 2 wrong call, 3 no
// playwright/Chrome.
//
// Rules (the page's claim read off the DOM: rows by data-place, chips by their lang text, tiles by data-tile):
//   TR-LOCAL      a row (Mr. Backupsy's «Is everything protected?», Mr. Restori's apps and VMs) at any level above «not
//                 backed up», or a «n local snapshots» chip — the unit's dataset(s) hold an engine snapshot
//   TR-SNAPS      Mr. Restori's «n local snapshots» never more than its folders' datasets really hold
//   TR-OFFSITE    «offsite» (… + offsite) — Kopia holds a complete snapshot of its source (a VM's or an app's folder: in the
//                 share's newest snapshot); shown green alone while the repository is held or takes nothing — red
//   TR-PARTNER    «local + partner» / «at <partner>» — that partner's door lists a snapshot of the unit (a VM or app in a
//                 dataset of its own: only its own unit; else its share's); green alone while the partner's newest is not
//                 the last backup run's snapshot — red
//   TR-ROW        every share of the server (shares.ini) is a row on Mr. Backupsy's overview once a backup ran
//   TR-KOPIA-TILE the repository held or stuck → the offsite tile alerts, the bubble never «saved offsite»
//   TR-DOCKER     Docker enabled but not answering → no app «gone» on Mr. Restori's page; it says «Docker isn't running»
//   TR-UNDER      (a note, never red) a row «not backed up» whose datasets hold the engine's snapshots
//   TR-CHECKS     the Team Lead: «In place» counts exactly the checks with ok true; the musts counted exactly those
//                 required with ok false (ok null: couldn't check — neither)
// Nothing here touches a server.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');
const require = createRequire(import.meta.url);

const opt = { look: null, langs: ['en'], json: false, tree: process.env.USO_PAGE_TREE || null, only: null, verbose: false, selftest: false };
const args = process.argv.slice(2);
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  const val = () => { if (i + 1 >= args.length) usage(); return args[++i]; };
  if (a === '--look') opt.look = path.resolve(val());
  else if (a === '--lang') opt.langs = val().split(',').filter((l) => /^[a-z]{2}$/.test(l));
  else if (a === '--json') opt.json = true;
  else if (a === '--tree') opt.tree = val() || null;
  else if (a === '--only') opt.only = val().split(',');
  else if (a === '--verbose' || a === '-v') opt.verbose = true;
  else if (a === '--self-test') { opt.selftest = true; opt.langs = ['en', 'de']; }
  else if (a === '-h' || a === '--help') usage(0);
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/scenarios/truth.mjs --look <dir with states/ and truth.json> [--lang en,de] [--json] [--tree <tag>] [--only TR-…,…]');
  process.exit(code);
}
if (opt.selftest) opt.look = path.join(REPO, 'tests', 'ui', 'states', 'truth');
if (!opt.look || !opt.langs.length) usage();
const readJson = (f) => { try { return JSON.parse(fs.readFileSync(f, 'utf8')); } catch (e) { return null; } };
const STATES = fs.existsSync(path.join(opt.look, 'states')) ? path.join(opt.look, 'states') : opt.look;
const truth = readJson(path.join(opt.look, opt.selftest ? 'inv-truth.json' : 'truth.json'));
if (!truth || truth.v !== 1) { console.error(`truth: no truth.json in ${opt.look}`); process.exit(2); }

// the page drawn: this clone's, or a tag's (as page.mjs: git archive into a cache folder, today's harness beside it)
function pageTree(tag) {
  const run = (cmd, a, o = {}) => execFileSync(cmd, a, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...o });
  const sha = run('git', ['-C', REPO, 'rev-parse', '--verify', '--quiet', `${tag}^{commit}`]).trim();
  const dir = path.join(os.tmpdir(), 'uso-page-tree', sha);
  if (!fs.existsSync(path.join(dir, '.complete'))) {
    const tmp = `${dir}.${process.pid}`;
    fs.rmSync(tmp, { recursive: true, force: true });
    fs.mkdirSync(tmp, { recursive: true });
    const tar = run('git', ['-C', REPO, 'archive', '--format=tar', sha, 'public', 'src', 'tests/ui'], { encoding: 'buffer', maxBuffer: 1 << 30 });
    run('tar', ['-x', '-C', tmp], { input: tar, stdio: ['pipe', 'pipe', 'pipe'] });
    fs.copyFileSync(path.join(REPO, 'tests', 'ui', 'harness.mjs'), path.join(tmp, 'tests', 'ui', 'harness.mjs'));
    fs.writeFileSync(path.join(tmp, '.complete'), `${tag}\n`);
    fs.rmSync(dir, { recursive: true, force: true });
    fs.renameSync(tmp, dir);
  }
  return dir;
}
const PAGE = opt.tree ? pageTree(opt.tree) : REPO;

function findPlaywright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* none */ }
  for (const t of tries.filter(Boolean)) { try { return require(t); } catch (e) { /* the next */ } }
  return null;
}
const pw = findPlaywright();
const chrome = [process.env.USO_CHROME, '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium']
  .find((p) => p && fs.existsSync(p));
if (!pw || !chrome) { console.error('truth: playwright-core or Chrome not found (USO_PLAYWRIGHT, USO_CHROME)'); process.exit(3); }

// ------------------------------------------------------------------------------------------------- lang texts
// a key's text in a language (the drawn page's lang files, then this clone's); `office.x` the office's own
function keyText(key, lang) {
  const [d, ...rest] = key.split('.');
  const roots = PAGE === REPO ? [REPO] : [PAGE, REPO];
  for (const root of roots) {
    for (const l of [lang, 'en']) {
      const f = d === 'office' ? path.join(root, 'public', 'lang', `${l}.json`) : path.join(root, 'public', 'desks', d, 'lang', `${l}.json`);
      const j = readJson(f);
      if (j && j[rest.join('.')] !== undefined) return j[rest.join('.')];
    }
  }
  return undefined;
}
// a key's text as a whole-text pattern: plural forms, {…} anything; returns RegExp|null
function keyRe(key, lang, whole = true) {
  const v = keyText(key, lang);
  if (v === undefined) return null;
  const forms = typeof v === 'string' ? [v] : Object.values(v || {}).filter((x) => typeof x === 'string');
  const src = forms.map((t) => t.split(/(\{[a-z_]+\})/).map((p) => (/^\{[a-z_]+\}$/.test(p) ? `(?<${p.slice(1, -1)}>.+?)` : p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+'))).join(''));
  // named groups may repeat across forms: one RegExp per form
  return src.map((s) => new RegExp(whole ? `^\\s*${s}\\s*$` : s, 's'));
}
const matchKey = (text, key, lang, whole = true) => {
  for (const re of keyRe(key, lang, whole) || []) { const m = re.exec(text); if (m) return m.groups || {}; }
  return null;
};
const LEVELS = ['none', 'local', 'partner', 'offsite', 'partner_offsite'];
const levelOf = (text, lang) => LEVELS.find((l) => matchKey(text, `office.protect.${l}`, lang)) || null;
const WHY = ['no_run', 'no_snaps', 'kopia_never', 'kopia_held', 'kopia_gone', 'kopia_stuck', 'kopia_away', 'kopia_failed', 'new_share', 'partner_never', 'partner_skipped', 'partner_failed'];
const whyOf = (text, lang) => WHY.find((w) => matchKey(text, `office.protect.why.${w}`, lang)) || null;

// ------------------------------------------------------------------------------------------------- the pages
const { startHarness } = await import(path.join(PAGE, 'tests', 'ui', 'harness.mjs'));
const harness = await startHarness({ states: STATES, ...(truth.host ? { host: truth.host } : {}) });
const browser = await pw.chromium.launch({ headless: true, executablePath: chrome, args: ['--no-first-run', '--no-default-browser-check'] });
async function draw(route, lang) {
  const desk = route.split('/')[0];
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: lang === 'en' ? 'en-US' : lang });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message).slice(0, 200)));
  await page.goto(`${harness.url}#/${route}`, { waitUntil: 'load' });
  const drawn = await page.waitForFunction((d) => window.Office && Office.current && Office.current.id === d
    && document.querySelector('#sso-desk .section, #sso-desk section, #sso-desk .box'), desk, { timeout: 15000 }).then(() => true, () => false);
  let text = '';
  for (let calm = 0, until = Date.now() + 10000; calm < 3 && Date.now() < until;) {
    await page.waitForTimeout(200);
    const now = await page.evaluate(() => (document.getElementById('sso') || document.body).innerText);
    calm = now === text ? calm + 1 : 0;
    text = now;
  }
  const dom = await page.evaluate(() => {
    const chips = (r) => [...r.querySelectorAll('.chip')].map((c) => ({ text: c.textContent.trim(), cls: c.className, title: c.title || '' }));
    return {
      rows: [...document.querySelectorAll('#sso [data-place]')].filter((r) => /^(row:)?(share|vm|app):|^row:flash$/.test(r.dataset.place) && (r.matches('tr, .row')))
        .map((r) => ({ place: r.dataset.place.replace(/^row:/, ''), name: (r.querySelector('th, .row-name') || {}).textContent || '', chips: chips(r) })),
      tiles: [...document.querySelectorAll('#sso .stat[data-tile]')].map((t) => ({ tile: t.dataset.tile, alert: t.classList.contains('alert'),
        value: (t.querySelector('.stat-value') || {}).textContent || '', subs: [...t.querySelectorAll('.stat-sub')].map((s) => s.textContent) })),
      bubble: ((document.querySelector('#sso .bubble, #sso .desk-bubble') || {}).innerText || ''),
    };
  });
  await ctx.close();
  return { text, drawn, errors, ...dom };
}

// ------------------------------------------------------------------------------------------------- the rules
const out = [];
let checked = 0;
const rule = (id) => !opt.only || opt.only.includes(id);
const fail = (id, unit, page, tr) => out.push({ verdict: 'FAIL', id, unit, page, truth: tr });
const note = (id, unit, page, tr) => out.push({ verdict: 'note', id, unit, page, truth: tr });
const okRun = truth.runs && truth.runs.last && ['ok', 'warnings'].includes(truth.runs.last.result) ? truth.runs.last.snapshot : null;
const snapsTxt = (places) => (places || []).map((p) => `${p.base} ${p.fs}${p.ds ? ` ${p.ds}` : ''}: ${p.asleep ? 'asleep' : p.snaps === null ? '?' : `${p.snaps} engine${p.all ? `, ${p.all} in all` : ''}`}`).join(', ') || 'no place';

// the partner truth of a unit: {held: true|false|null, by: [names], newest: {name: snap}}
function partnerTruth(units) {
  let any = null;
  const by = [], newest = {};
  for (const p of truth.partners || []) {
    if (!p.asked) continue;
    for (const u of units) {
      const x = p.units[u];
      if (!x) continue;
      if (x.n === null) { any = any || null; continue; }
      if (any === null) any = false;
      if (x.n > 0) { any = true; by.push(p.name); newest[p.name] = x.newest; }
    }
  }
  // a unit no partner asked about: settings.ini names no partner for it — nothing there
  if (any === null && (truth.partners || []).every((p) => p.asked)) any = false;
  return { held: any, by, newest };
}
// the units that carry a share's, VM's or app's data to a partner
const shareUnits = (s) => (s === truth.dumps_share ? ['place', `share:${s}`] : [`share:${s}`]);
function vmUnits(v) {
  const vm = truth.vms[v];
  if (!vm) return [];
  return vm.disks.some((d) => d.own_dataset) ? [`vm:${v}`] : [...new Set(vm.disks.map((d) => d.share).filter(Boolean))].flatMap(shareUnits);
}
function appUnits(a) {
  const app = truth.apps[a];
  if (!app || !app.folders.length) return [];
  return app.folders.some((f) => f.own_dataset) ? [`app:${a}`] : [...new Set(app.folders.map((f) => f.share))].flatMap(shareUnits);
}

/** one row's claim against the unit's truth */
function judgeRow(page, r, lang) {
  const [kind, ...rest] = r.place.split(':');
  const name = rest.join(':');
  const level = r.chips.map((c) => levelOf(c.text, lang)).find(Boolean);
  const whys = r.chips.map((c) => whyOf(c.text, lang)).filter(Boolean);
  const snapChip = r.chips.map((c) => matchKey(c.text, 'restore.chip.snaps', lang)).find(Boolean);
  const partnerChips = r.chips.map((c) => matchKey(c.text, 'restore.chip.at_partner', lang)).filter(Boolean).map((g) => g.name);
  const kopiaChip = r.chips.some((c) => matchKey(c.text, 'restore.chip.kopia', lang));
  let local, offsite, places, units, detail;
  if (kind === 'share') {
    const s = truth.shares[name];
    if (!s) return;
    ({ local, places } = s);
    offsite = truth.kopia.asked ? !!(s.kopia && s.kopia.n > 0) : null;
    units = shareUnits(name);
  } else if (kind === 'vm') {
    const v = truth.vms[name];
    if (!v) return;
    local = v.local; offsite = v.offsite; places = v.disks.flatMap((d) => d.places); units = vmUnits(name);
  } else if (kind === 'app') {
    const a = truth.apps[name];
    if (!a || !a.folders.length) return;         // a package of an app gone, or an app without data: its package's chips
    local = a.local; offsite = a.offsite; places = a.folders.flatMap((f) => f.places); units = appUnits(name);
    detail = a.folders.map((f) => `${f.share}/${f.folder}${f.own_dataset ? ' (own dataset)' : ''}`).join(', ');
  } else return;
  const unit = `${page} ${r.place}`;
  const shown = r.chips.map((c) => `«${c.text}»`).join(' ');
  checked++;
  if (opt.verbose) out.push({ verdict: 'seen', id: 'row', unit, page: shown, truth: `local ${local}, offsite ${offsite}, partner ${JSON.stringify(partnerTruth(units).held)} (${units.join(' ')})` });
  const claimsLocal = (level && level !== 'none') || (snapChip && Number(String(snapChip.n || '1').replace(/\D/g, '')) > 0);
  if (rule('TR-LOCAL') && claimsLocal) {
    if (local === 'no') fail('TR-LOCAL', unit, shown, `no engine snapshot holds it (${snapsTxt(places)})${detail ? ` — ${detail}` : ''}`);
    else if (local === 'partial') note('TR-LOCAL', unit, shown, `only part of it is in a snapshot (${snapsTxt(places)})`);
  }
  if (rule('TR-SNAPS') && snapChip && snapChip.n !== undefined) {
    const n = Number(String(snapChip.n).replace(/\D/g, ''));
    // snapshots of any name count here (the chip: «local ZFS or btrfs snapshots that hold its folders»)
    const real = places.filter((p) => (p.all ?? p.snaps) !== null).map((p) => p.all ?? p.snaps);
    if (real.length && n > Math.max(...real)) fail('TR-SNAPS', unit, shown, `its datasets hold ${real.join(' / ')} snapshots (${snapsTxt(places)})`);
    else if (real.length && n > Math.min(...real)) note('TR-SNAPS', unit, shown, `one of its parts holds only ${Math.min(...real)} (${snapsTxt(places)})`);
  }
  // less than there is: never red (the dangerous direction is the other), a note to read
  if (rule('TR-LOCAL') && level === 'none' && local === 'yes') note('TR-UNDER', unit, shown, `its datasets hold engine snapshots (${snapsTxt(places)})${truth.runs.last ? `; the last backup run ${truth.runs.last.run} ended ${truth.runs.last.result}` : ''}`);
  const claimsOffsite = level === 'offsite' || level === 'partner_offsite' || kopiaChip;
  if (rule('TR-OFFSITE') && claimsOffsite) {
    if (offsite === false) fail('TR-OFFSITE', unit, shown, truth.kopia.asked ? `Kopia holds no complete snapshot of it${detail ? ` (${detail})` : ''}` : 'Kopia off');
    else if (!whys.length && (truth.kopia.held || truth.kopia.stuck)) fail('TR-OFFSITE', unit, shown, `the repository is ${truth.kopia.held ? 'held (suddenly empty)' : 'taking nothing (stuck)'} — green alone`);
  }
  const claimsPartner = level === 'partner' || level === 'partner_offsite' || partnerChips.length;
  if (rule('TR-PARTNER') && claimsPartner) {
    const pt = partnerTruth(units);
    if (pt.held === false) fail('TR-PARTNER', unit, shown, `no partner holds ${units.join(' / ') || 'a unit of it'}${detail ? ` (${detail})` : ''}`);
    else if (pt.held === true && partnerChips.length) {
      const missing = partnerChips.filter((n) => !pt.by.includes(n));
      if (missing.length) fail('TR-PARTNER', unit, shown, `${missing.join(', ')} holds nothing of ${units.join(' / ')} (held by: ${pt.by.join(', ')})`);
    }
    if (pt.held === true && !whys.length && okRun && level && level.startsWith('partner')) {
      const stale = Object.entries(pt.newest).filter(([, s]) => s !== okRun);
      if (stale.length === pt.by.length) fail('TR-PARTNER', unit, shown, `the last backup run (${okRun}) did not reach it: newest there ${stale.map(([n, s]) => `${n} ${s}`).join(', ')} — green alone`);
    }
  }
}

const tilesOf = (p) => Object.fromEntries(p.tiles.map((t) => [t.tile, t]));
const report = { pages: [] };
for (const lang of opt.langs) {
  // Mr. Backupsy's overview
  if (['TR-LOCAL', 'TR-OFFSITE', 'TR-PARTNER', 'TR-ROW', 'TR-KOPIA-TILE'].some(rule) && fs.existsSync(path.join(STATES, 'backup.json'))) {
    const p = await draw('backup', lang);
    report.pages.push({ route: 'backup', lang, drawn: p.drawn, rows: p.rows.length, errors: p.errors });
    if (!p.drawn) fail('TR-PAGE', 'backup', 'did not draw', p.errors.join('; '));
    for (const r of p.rows) if (/^(share|vm):/.test(r.place)) judgeRow('backup', r, lang);
    if (rule('TR-ROW') && p.drawn && truth.runs.n > 0 && truth.settings) {
      const rows = new Set(p.rows.map((r) => r.place));
      for (const s of Object.keys(truth.shares)) {
        checked++;
        if (!rows.has(`share:${s}`)) fail('TR-ROW', `backup share:${s}`, 'no row', `the share is on the server (set: ${truth.shares[s].set || 'not in the setup'}; ${truth.shares[s].local === 'yes' ? 'snapshotted' : 'no snapshot'})`);
      }
    }
    if (rule('TR-KOPIA-TILE') && p.drawn && (truth.kopia.held || truth.kopia.stuck)) {
      checked++;
      const t = tilesOf(p).kopia;
      if (t && !t.alert) fail('TR-KOPIA-TILE', 'backup tile kopia', `«${t.value} · ${t.subs.join(' · ')}» without alert`, truth.kopia.held ? 'the repository is held' : 'the storage takes nothing');
      if (matchKey(p.text, 'backup.bubble.last_ok_kopia', lang, false)) fail('TR-KOPIA-TILE', 'backup bubble', '«… saved offsite»', truth.kopia.held ? 'the repository is held' : 'the storage takes nothing');
    }
  }
  // Mr. Restori's apps and VMs
  if (fs.existsSync(path.join(STATES, 'restore.json'))) {
    for (const route of ['restore/apps', 'restore/vms']) {
      const p = await draw(route, lang);
      report.pages.push({ route, lang, drawn: p.drawn, rows: p.rows.length, errors: p.errors });
      if (!p.drawn) { fail('TR-PAGE', route, 'did not draw', p.errors.join('; ')); continue; }
      for (const r of p.rows) if (r.place.startsWith(route.endsWith('apps') ? 'app:' : 'vm:')) judgeRow(route, r, lang);
      if (route === 'restore/apps' && rule('TR-DOCKER') && truth.docker.enabled && !truth.docker.answers) {
        checked++;
        const gone = p.rows.filter((r) => r.place.startsWith('app:') && r.chips.some((c) => matchKey(c.text, 'restore.chip.gone', lang)));
        if (gone.length) fail('TR-DOCKER', route, `«gone» on ${gone.map((r) => r.place).join(', ')}`, 'Docker is not answering — nothing is known gone');
        if (!matchKey(p.text, 'office.common.docker_off', lang, false)) fail('TR-DOCKER', route, 'never says «Docker isn\'t running»', 'Docker is enabled and not answering');
      }
    }
  }
  // the Team Lead
  const ct = readJson(path.join(STATES, 'caretaker.json'));
  if (rule('TR-CHECKS') && ct && ct.checks) {
    const all = Object.values(ct.checks).flat().filter((f) => f && typeof f === 'object');
    const must = all.filter((f) => f.level === 'required' && f.ok === false).length;
    const done = all.filter((f) => f.ok === true).length;
    const unknown = all.filter((f) => f.level !== 'hint' && f.ok !== true && f.ok !== false).length;
    const p = await draw('caretaker', lang);
    report.pages.push({ route: 'caretaker', lang, drawn: p.drawn, errors: p.errors });
    if (p.drawn) {
      checked++;
      const t = tilesOf(p);
      const num = (s) => Number(String(s).replace(/[^\d]/g, ''));
      if (t.done && num(t.done.value) !== done) fail('TR-CHECKS', 'caretaker tile done', `«${t.done.value}»`, `${done} checks with ok true (${unknown} couldn't be checked)`);
      if (t.todo) {
        const shownMust = t.todo.subs.join(' · ').split(' · ').map((x) => matchKey(x, 'caretaker.count.must', lang)).find(Boolean);
        const n = shownMust ? num(shownMust.n ?? 1) : 0;
        if (n !== must) fail('TR-CHECKS', 'caretaker tile todo', `«${t.todo.subs.join(' · ')}»`, `${must} required checks with ok false (${unknown} couldn't be checked)`);
      }
    } else fail('TR-PAGE', 'caretaker', 'did not draw', p.errors.join('; '));
  }
}
await browser.close();
await harness.close();

const red = out.filter((x) => x.verdict === 'FAIL');
if (opt.selftest) {
  // exactly the expected lies, in every language drawn
  const got = new Set(red.map((x) => `${x.id} ${x.unit}`));
  const want = new Set(truth.expect || []);
  const missing = [...want].filter((x) => !got.has(x));
  const extra = [...got].filter((x) => !want.has(x));
  for (const x of red) console.log(`${want.has(`${x.id} ${x.unit}`) ? 'found' : 'EXTRA'} ${x.id} ${x.unit}: page ${x.page}; truth: ${x.truth}`);
  for (const x of missing) console.log(`MISSED ${x}`);
  console.log(`truth self-test: ${missing.length || extra.length ? 'FAIL' : 'ok'} — ${want.size} lies expected, ${got.size - extra.length} found, ${missing.length} missed, ${extra.length} extra (${opt.langs.join(', ')})`);
  process.exit(missing.length || extra.length ? 1 : 0);
}
if (opt.json) console.log(JSON.stringify({ checked, red: red.length, findings: out, pages: report.pages }));
else {
  for (const x of out) console.log(`${x.verdict} ${x.id} ${x.unit}: page ${x.page}; truth: ${x.truth}`);
  console.log(`truth: ${checked} checked, ${red.length} red, ${out.filter((x) => x.verdict === 'note').length} notes (${report.pages.map((p) => `${p.route} ${p.lang}${p.drawn ? '' : ' NOT DRAWN'}`).join(', ')})`);
}
process.exit(red.length ? 1 : 0);
