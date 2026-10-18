#!/usr/bin/env node
// The change list Mr. Backupsy's Apply dialog would show for a plan, without a browser: public/desks/backup/desk.js loaded
// under node as tests/backuptiles.js loads it (OFFICE_DESK_TESTS, a stub Office), the draft made from the plan as the page
// makes it when the setup opens (setupDraftFromPlan: the plan's P, a new setup's shares local, the default for what is new,
// the declined proposals kept declined), then setupChangeItems() — the keys Apply sends that differ from settings.ini, a
// list compared as the dialog compares it (docker|no_stop … as sets, «empty» and «missing» the same).
//
//   node tools/scenarios/plan-changes.mjs <plan.json> [<settings.ini>] [--items] [--desk <desk.js>]
//   node tools/scenarios/plan-changes.mjs <plan.json> --decisions <auto|local|partner|kopia|kopia_partner> [--desk …]
//
// plan.json: the engine's state/setup-plan.json, or the answer of backup.setup_get (its `plan` - with `declined` and
// `migrated`, as the page gets it; a bare setup-plan.json has neither: nothing declined, nothing migrated).
// settings.ini (optional): what is saved NOW - its keys replace the plan's `O` (a plan older than the last Apply would
// otherwise compare with the settings of its own time). A key is a list when the plan has it as one or it repeats; a
// list written empty (`kopia_known =`) is the empty list.
// Prints one JSON array, sorted by key: [{key, was, is, kind}] - `was` what settings.ini says (null: not there), `is` what
// Apply would send, kind key | unit | partner | app_gone (the dialog's groups). --items: the dialog's rows instead
// ([{id, kind, keys, yours}]). `[]` = Apply changes nothing. --decisions <default>: instead the body Apply sends when the
// user picks that default «for everything» on the setup page and clicks Apply ({decisions, plan_time} for
// backup.setup_apply - the scenarios' scripted first setup, tools/scenarios/base.sh --make). Exit 0, 2 on a wrong call
// or an unreadable file.
// Nothing here touches a server.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, '..', '..');

const args = process.argv.slice(2);
const opt = { plan: null, ini: null, items: false, decisions: null, desk: path.join(REPO, 'public', 'desks', 'backup', 'desk.js') };
for (let i = 0; i < args.length; i++) {
  const a = args[i];
  if (a === '--items') opt.items = true;
  else if (a === '--decisions' && i + 1 < args.length) opt.decisions = args[++i];
  else if (a === '--desk' && i + 1 < args.length) opt.desk = args[++i];
  else if (a === '-h' || a === '--help') usage(0);
  else if (a.startsWith('-')) usage();
  else if (opt.plan === null) opt.plan = a;
  else if (opt.ini === null) opt.ini = a;
  else usage();
}
function usage(code = 2) {
  console.error('usage: node tools/scenarios/plan-changes.mjs <plan.json> [<settings.ini>] [--items] [--desk <desk.js>]');
  process.exit(code);
}
if (!opt.plan) usage();

const readText = (f) => { try { return fs.readFileSync(f, 'utf8'); } catch (e) { console.error(`plan-changes: cannot read ${f}: ${e.message}`); process.exit(2); } };
let plan;
try { plan = JSON.parse(readText(opt.plan)); } catch (e) { console.error(`plan-changes: ${opt.plan} is no JSON`); process.exit(2); }
if (plan && plan.plan && typeof plan.plan === 'object' && !plan.P) plan = plan.plan;       // a setup_get answer
if (!plan || typeof plan.P !== 'object' || typeof plan.O !== 'object') { console.error('plan-changes: no plan (P and O missing)'); process.exit(2); }

/**
 * settings.ini as the plan's O: «section|name|key» (an [app "X"] section → app|X|key, [general] → general|key), a key that
 * repeats or that the plan holds as a list → a list. Comments (#, ;) and blank lines skipped, values trimmed.
 */
function iniToO(text, p) {
  const out = {};
  const seen = new Map();
  let sec = null;
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#') || line.startsWith(';')) continue;
    const s = /^\[([A-Za-z0-9_-]+)(?:\s+"([^"]*)")?\]$/.exec(line);
    if (s) { sec = s[2] !== undefined ? `${s[1]}|${s[2]}` : s[1]; continue; }
    const kv = /^([A-Za-z0-9_.-]+)\s*=\s*(.*)$/.exec(line);
    if (!kv || sec === null) continue;
    const k = `${sec}|${kv[1]}`;
    seen.set(k, (seen.get(k) || 0) + 1);
    (out[k] ??= []).push(kv[2]);
  }
  const isList = (k) => seen.get(k) > 1 || Array.isArray(p.P[k]) || Array.isArray(p.O[k]);
  // a list key written empty (`kopia_known =`: recorded, nothing in it) is the empty list, as the engine's plan says it
  for (const k of Object.keys(out)) out[k] = isList(k) ? out[k].filter((v) => v !== '') : out[k][0];
  return out;
}
if (opt.ini) plan.O = iniToO(readText(opt.ini), plan);

// ------------------------------------------------------------------ desk.js under node (tests/backuptiles.js's stubs)
const node = (tag, cls, text) => ({ tag, cls, text, dataset: {}, style: {}, children: [], classList: { add() {}, remove() {}, toggle() {}, contains: () => false },
  appendChild(c) { this.children.push(c); return c; }, append(...c) { this.children.push(...c); }, setAttribute() {}, addEventListener() {},
  querySelector: () => null, querySelectorAll: () => [], remove() {} });
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
globalThis.OFFICE_DESK_TESTS = {};
globalThis.Office = {
  scope: () => T, t: T, has: () => false, store: () => null, desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {},
  selbar: () => {}, el: node, place: (anchor, n) => { n.place = anchor; return n; }, stat: (o) => ({ o, dataset: {} }),
  fmt: { date: String, relative: String, size: (b) => `${b} B`, number: String, bitrate: String, cron: String, duration: String, time: String },
};
globalThis.document = { getElementById: () => null, querySelector: () => null, querySelectorAll: () => [], createElement: (t) => node(t),
  createTextNode: (t) => ({ text: t }) };
(0, eval)(fs.readFileSync(opt.desk, 'utf8'));
const B = globalThis.OFFICE_DESK_TESTS.backup;
if (!B || typeof B.setupDraftFromPlan !== 'function' || typeof B.setupChangeItems !== 'function') {
  console.error('plan-changes: desk.js does not offer setupDraftFromPlan / setupChangeItems');
  process.exit(2);
}

B.setup.plan = plan;
if (opt.decisions !== null) {
  // the page's presetChoose(kind) on a setup made anew: the default for every row, then Apply's body (setupDecisions)
  if (!['auto', 'local', 'partner', 'kopia', 'kopia_partner'].includes(opt.decisions)) usage();
  B.setup.choice = { kind: opt.decisions, all: true, items: null };
  B.setupDraftFromPlan();
  console.log(JSON.stringify({ decisions: B.setupDecisions(), plan_time: plan.time ?? null }));
  process.exit(0);
}
B.setupDraftFromPlan();
const { saved, view, items } = B.setupChangeItems();
if (opt.items) {
  console.log(JSON.stringify(items));
} else {
  const kindOf = new Map(items.flatMap((x) => x.keys.map((k) => [k, x.kind])));
  const out = [...kindOf.keys()].sort().map((k) => ({ key: k, was: saved[k] ?? null, is: view[k] ?? null, kind: kindOf.get(k) }));
  console.log(JSON.stringify(out));
}
