/*
 * Ms. Protocolli's lists (public/desks/logs/desk.js with core.js, under node, on a small stand-in DOM; the agent stubbed
 * with the click test's states, tests/ui/states/logs*.json): every list has its filter bar (/var/log, the containers'
 * logs, «Errors & warnings», «Syslog», the reader) with a time filter in it; a time filter narrows the rows (a file by
 * its last write, a kind by its newest line, the reader's lines by their own — a line without one by the line before)
 * and stands in the note line like any filter, its × and «Show all» take it away; her saved filters stand above their
 * list and apply only by a click — opening the desk shows everything, also with a filter marked `open` (APPLY_ON_OPEN
 * is off); «Save filter…» sends what the bar has set, «⋯» offers rename and delete; a new visit empties every bar.
 * The order (newest / oldest first, by service) in «Syslog», «Errors & warnings» and the reader: a choice kept per browser,
 * named in the note line only when it isn't the list's own; a click on a line's service shows only that one, a saved
 * filter keeps it.
 *
 *   node tests/logsfilters.js public/assets/core.js public/desks/logs/desk.js [tests/ui/states]   → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testLogsFiltersPage runs it on the server; on the Mac it runs as it is.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const coreFile = process.argv[2] || path.join(__dirname, '../public/assets/core.js');
const deskFile = process.argv[3] || path.join(__dirname, '../public/desks/logs/desk.js');
const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};

// ------------------------------------------------------------------ a stand-in DOM (what core.js and her desk use)
let doc = null;
class FakeNode {
  constructor(tag) {
    this.tagName = String(tag).toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attrs = {};
    this.dataset = {};
    this.style = {};
    this.hidden = false;
    this.text = '';
    this.listeners = {};
    this.className = '';
    this.type = '';
    this.val = '';
    this.checked = false;
    this.disabled = false;
    this.offsetHeight = 0;
    this.scrollTop = 0;
    this.scrollHeight = 0;
    this.clientHeight = 0;
    this.selectionStart = 0;
    this.selectionEnd = 0;
  }
  get classList() {
    const self = this;
    const list = () => self.className.split(/\s+/).filter(Boolean);
    return {
      add: (...c) => { self.className = [...new Set([...list(), ...c])].join(' '); },
      remove: (...c) => { self.className = list().filter((x) => !c.includes(x)).join(' '); },
      toggle: (c, on) => { const has = list().includes(c); const want = on === undefined ? !has : !!on; if (want !== has) self.classList[want ? 'add' : 'remove'](c); return want; },
      contains: (c) => list().includes(c),
    };
  }
  get id() { return this.attrs.id || ''; }
  set id(v) { this.attrs.id = String(v); }
  get title() { return this.attrs.title || ''; }
  set title(v) { this.attrs.title = String(v); }
  get href() { return this.attrs.href || ''; }
  set href(v) { this.attrs.href = String(v); }
  get value() {
    if (this.tagName !== 'SELECT') return this.val;
    return this.options.some((o) => o.val === this.val) ? this.val : (this.options[0] ? this.options[0].val : '');
  }
  set value(v) { this.val = String(v ?? ''); }
  get options() { return this.children.filter((c) => c.tagName === 'OPTION'); }
  get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; this.text = v === null || v === undefined ? '' : String(v); }
  set innerHTML(v) { this.textContent = v; }
  get firstChild() { return this.children[0] || null; }
  get lastChild() { return this.children[this.children.length - 1] || null; }
  get nodeType() { return this.tagName === '#TEXT' ? 3 : 1; }
  get isConnected() { let n = this; while (n.parentNode) n = n.parentNode; return n === doc.body; }
  appendChild(c) {
    if (typeof c === 'string') c = textNode(c);
    if (c.tagName === '#FRAGMENT') { [...c.children].forEach((x) => this.appendChild(x)); return c; }
    if (c.parentNode) c.parentNode.removeChild(c);
    c.parentNode = this;
    this.children.push(c);
    return c;
  }
  append(...cs) { cs.forEach((c) => this.appendChild(c)); }
  prepend(...cs) { cs.reverse().forEach((c) => { if (typeof c === 'string') c = textNode(c); if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.unshift(c); }); }
  insertBefore(c, ref) { if (!ref) return this.appendChild(c); if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.splice(this.children.indexOf(ref), 0, c); return c; }
  replaceChildren(...cs) { [...this.children].forEach((c) => this.removeChild(c)); this.text = ''; this.append(...cs); }
  replaceWith(n) { if (!this.parentNode) return; const p = this.parentNode; p.insertBefore(n, this); p.removeChild(this); }
  removeChild(c) {
    this.children = this.children.filter((x) => x !== c);
    c.parentNode = null;
    if (doc.activeElement && c.contains(doc.activeElement)) doc.activeElement = doc.body;
    return c;
  }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  setAttribute(k, v) { if (k === 'id') this.id = v; else this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  toggleAttribute(k, on) { if (on) this.attrs[k] = ''; else delete this.attrs[k]; }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  removeEventListener() {}
  dispatchEvent(ev) { return this.dispatch(ev.type); }
  dispatch(type, extra) {
    const ev = { type, target: this, currentTarget: this, defaultPrevented: false, button: 0, detail: 1, clientX: 1, clientY: 1,
      preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...(extra || {}) };
    for (let n = this; n; n = n.parentNode) {
      ev.currentTarget = n;
      (n.listeners[type] || []).forEach((fn) => fn.call(n, ev));
      if (typeof n['on' + type] === 'function') n['on' + type](ev);
    }
    return ev;
  }
  click() { return this.dispatch('click'); }
  focus() { doc.activeElement = this; }
  blur() { doc.activeElement = doc.body; }
  setSelectionRange() {}
  contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
  all() { return this.children.flatMap((c) => (c.tagName === '#TEXT' ? [] : [c, ...c.all()])); }
  // selectors: '.a', '.a.b', 'tag', 'tag.a', '[data-x]', '[data-x="v"]' — enough for core.js and her desk
  matches(sel) {
    return String(sel).split(',').some((s) => {
      const m = s.trim();
      const at = /^([a-z]*)\[([a-z-]+)(?:="?([^"\]]*)"?)?\]$/.exec(m);
      if (at) {
        const k = at[2];
        const has = k.startsWith('data-') ? this.dataset[k.slice(5).replace(/-(\w)/g, (x, c) => c.toUpperCase())] : this.attrs[k];
        return (!at[1] || this.tagName === at[1].toUpperCase()) && has !== undefined && (at[3] === undefined || has === at[3]);
      }
      const [tag, ...cls] = m.split('.');
      return (!tag || this.tagName === tag.toUpperCase()) && cls.every((c) => this.classList.contains(c));
    });
  }
  querySelector(sel) { return this.all().find((c) => c.matches(sel)) || null; }
  querySelectorAll(sel) { return this.all().filter((c) => c.matches(sel)); }
  closest(sel) { for (let n = this; n && n.matches; n = n.parentNode) if (n.matches(sel)) return n; return null; }
  getBoundingClientRect() { return { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }; }
  scrollIntoView() {}
}
const textNode = (s) => { const n = new FakeNode('#text'); n.text = String(s); n.all = () => []; return n; };
const desk = new FakeNode('main');
desk.id = 'sso-desk';
doc = {
  body: new FakeNode('body'),
  activeElement: null,
  getElementById: (id) => (id === 'sso-config' ? { textContent: JSON.stringify({ desks: [{ id: 'logs', order: 1, hired: true, icon: '•' }],
    languages: [{ code: 'en' }], base: '', staff_order: [] }) } : id === 'menu' ? null : id === 'sso-desk' ? desk : new FakeNode('div')),
  querySelector: (s) => (s === '#sso-desk' ? desk : new FakeNode('div')),
  querySelectorAll: () => [],
  createElement: (tag) => new FakeNode(tag),
  createDocumentFragment: () => new FakeNode('#fragment'),
  addEventListener() {},
  documentElement: { scrollHeight: 0, clientWidth: 1440, lang: 'en', dir: 'ltr' },
  hidden: false,
};
doc.activeElement = doc.body;
doc.body.appendChild(desk);
globalThis.document = doc;
globalThis.window = globalThis;
globalThis.innerHeight = 800; globalThis.innerWidth = 1440; globalThis.scrollY = 0;
globalThis.scrollBy = () => {};
globalThis.scrollTo = () => {};
globalThis.matchMedia = (q) => ({ matches: /min-width/.test(q) });
globalThis.getSelection = () => '';
globalThis.CSS = { escape: (s) => String(s) };
globalThis.Node = FakeNode;
globalThis.requestAnimationFrame = (f) => setTimeout(f, 0);
globalThis.Option = function Option(text, value) { const o = new FakeNode('option'); o.text = String(text); o.val = String(value ?? text); return o; };
const stored = {};
const def = (name, value) => Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
def('localStorage', {
  getItem: (k) => (k in stored ? stored[k] : null),
  setItem: (k, v) => { stored[k] = String(v); },
  removeItem: (k) => { delete stored[k]; },
});
def('navigator', { languages: ['en'] });
globalThis.history = { replaceState() {} };
globalThis.location = { hash: '', reload() {} };

(0, eval)(fs.readFileSync(coreFile, 'utf8'));
const O = globalThis.Office;
const web = path.join(path.dirname(coreFile), '..');
O.strings = JSON.parse(fs.readFileSync(path.join(web, 'lang', 'en.json'), 'utf8'));
for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(path.join(web, 'desks', 'logs', 'lang', 'en.json'), 'utf8')))) O.strings['logs.' + k] = v;
O.current = { id: 'logs' };
O.agent = { running: true };

// ------------------------------------------------------------------ the agent, stubbed: the click test's states
const states = process.argv[4] || path.join(web, '..', 'tests', 'ui', 'states');
const now = Math.floor(Date.now() / 1000);
const base = JSON.parse(fs.readFileSync(path.join(states, 'logs.json'), 'utf8'));
const tourFile = JSON.parse(fs.readFileSync(path.join(states, 'logs-tour.json'), 'utf8'));
/** The tour as if it were just made: every time moved by the same step (rows keep their distance to it) */
const moved = (x, by) => (Array.isArray(x) ? x.map((y) => moved(y, by)) : x && typeof x === 'object'
  ? Object.fromEntries(Object.entries(x).map(([k, v]) => [k, ['time', 'since', 'first', 'last'].includes(k) && typeof v === 'number' && v > 1e9 ? v + by : moved(v, by)])) : x);
const tour = moved(tourFile, now - 60 - tourFile.time);
let state = null;
const freshState = () => {
  const s = JSON.parse(JSON.stringify(base));
  s.time = now;
  s.tz = 0;
  s.boot = now - 7200;
  s.filters = [
    { id: 'a1b2c3d4e5f6', list: 'syslog', name: 'Disks today', words: '', cat: 'drive', src: '', only: false, time: { preset: 'today' }, open: true },
    { id: '0f1e2d3c4b5a', list: 'syslog', name: 'Network, last hour', words: 'br0', cat: 'network', src: '', only: false, time: { preset: 'hour' }, open: false },
    { id: '1234567890ab', list: 'varlog', name: 'Since the start', words: '', cat: '', src: '', only: false, time: { preset: 'boot' }, open: false },
    { id: 'abcabcabcabc', list: 'syslog', name: 'Kernel only', words: '', cat: '', src: '', svc: 'kernel', only: false, time: null },
    { id: 'ffffffffffff', list: 'syslog', name: 5, words: '', cat: '', src: '', only: false, time: null },          // not in her shape: left out
  ];
  return s;
};
const iso = (t) => new Date(t * 1000).toISOString().replace('.000Z', 'Z');
const readLines = [`${iso(now - 7200)} Tower sshd[12]: old line`, `${iso(now - 1800)} Tower kernel: newer error here`, '    at the next line of its trace',
  `${iso(now - 60)} Tower emhttpd: newest line`];
const posts = [];
O.loadState = async (id, o, took) => { took({ ok: true, state }); };
O.freshState = async () => true;
O.api = {
  get: async (q) => (q.a === 'part' ? { ok: true, part: tour } : { ok: true }),
  post: async (action, data) => {
    posts.push([action, data]);
    if (action === 'logs.read') return { ok: true, lines: readLines.slice(), follow: 'replace', size: 100, time: now };
    if (action.startsWith('logs.filter_')) return { ok: true, state };
    return { ok: false, error: { key: 'agent_away' } };
  },
};
const dialogs = [];
const menus = [];
const toasts = [];
O.dialog = (d) => { dialogs.push(d); return { buttons: [], close() {} }; };
O.menu = (e, items) => { menus.push(items); };
O.toast = (text) => { toasts.push(text); };

stored['office.logs.source'] = 'syslog';          // the reader's log (a choice, kept per browser: read when her desk loads)
(0, eval)(fs.readFileSync(deskFile, 'utf8'));
const logs = O.desks.get('logs');

// ------------------------------------------------------------------ helpers
const tick = () => new Promise((ok) => setTimeout(ok, 0));
const tx = (n) => (n ? n.textContent : null);
const T = (k, p) => O.t('logs.' + k, p);
/** The bars, by the section they stand in (its place: varlog.title …; the reader's section is «reading») */
function sectionOf(root, place) {
  const head = root.all().find((n) => n.dataset.place === place);
  return head ? head.closest('section') : null;
}
const barIn = (s) => (s ? s.querySelector('.filterbar') : null);
const note = (bar) => (bar ? bar.querySelector('.filter-note') : null);
const noteChips = (bar) => (bar ? bar.querySelector('.filter-chips').querySelectorAll('.filter-chip-name').map(tx) : null);      // what filters (the order apart)
const rowsIn = (s, cls) => (s ? s.querySelectorAll(cls).length : -1);
const pick = (bar) => bar.querySelector('.lg-time-pick');
const setPick = (bar, v) => { const p = pick(bar); p.value = v; p.dispatch('change'); };

async function run() {
  // ---- a visit: every list has its bar, a time filter in it, nothing set by itself
  state = freshState();
  const root = new FakeNode('div');
  desk.replaceChildren(root);
  await logs.mount(root, '');
  await tick();
  await tick();
  const places = { varlog: 'varlog.title', docker: 'docker.title', found: 'found.title', syslog: 'syslog.title', read: 'reading' };
  const sec = Object.fromEntries(Object.entries(places).map(([k, p]) => [k, sectionOf(root, p)]));
  same('every list: its section on the whole page', Object.values(sec).map((s) => !!s), [true, true, true, true, true]);
  const bars = Object.fromEntries(Object.entries(sec).map(([k, s]) => [k, barIn(s)]));
  same('every list: a filter bar of its own (core.js Office.filterBar)', Object.values(bars).map((b) => !!b), [true, true, true, true, true]);
  same('every list: a time filter in its bar', Object.values(bars).map((b) => !!(b && b.querySelector('.lg-time'))), [true, true, true, true, true]);
  same('the time filter: any time, the presets, since the server started (a boot time known), from … to …',
    pick(bars.varlog).options.map((o) => o.val), ['', 'hour', 'day', 'today', 'boot', 'range']);
  same('opening: nothing applies by itself — no note line, no words, no time (also a filter marked open)',
    Object.values(bars).map((b) => [note(b).hidden, b.querySelector('.filter-words').value, pick(b).value]),
    Object.values(bars).map(() => [true, '', '']));
  same('opening: her saved filters stand above their lists, none pressed', [sec.syslog.querySelectorAll('.lg-saved-btn').map(tx),
    sec.varlog.querySelectorAll('.lg-saved-btn').map(tx), root.querySelectorAll('.lg-saved-btn').filter((b) => b.getAttribute('aria-pressed') === 'true').length],
  [['Disks today', 'Network, last hour', 'Kernel only'], ['Since the start'], 0]);
  same('opening: «Save filter…» only while something is set', root.querySelectorAll('.lg-saved-save').length, 0);
  same('opening: nothing asked of the agent about her filters', posts.filter(([a]) => a.startsWith('logs.filter_')).length, 0);

  // ---- the time filter: rows narrowed, said in the note line, its × and «Show all» take it away
  const files = rowsIn(sec.varlog, '.lg-readable') + sec.varlog.querySelectorAll('.row').filter((r) => !r.classList.contains('lg-readable')).length;
  const all = (tour.varlog.files || []).length;
  same('/var/log: every file without a filter', files, all);
  setPick(bars.varlog, 'hour');
  let v = sectionOf(root, 'varlog.title');
  const hour = (tour.varlog.files || []).filter((f) => f.time >= now - 3600).length;
  same('/var/log: «Last hour» — the files by their last write', v.querySelectorAll('.row').length, hour);
  same('/var/log: the time in the note line like any filter', [note(barIn(v)).hidden, noteChips(barIn(v))], [false, [T('time.hour')]]);
  same('/var/log: … with «n of m»', tx(barIn(v).querySelector('.filter-note-hits')), '· ' + O.t('common.filter_hits', { n: hour, total: all }));
  barIn(v).querySelector('.filter-chip').click();
  v = sectionOf(root, 'varlog.title');
  same('/var/log: the time\'s × takes it away', [note(barIn(v)).hidden, pick(barIn(v)).value, v.querySelectorAll('.row').length], [true, '', all]);
  setPick(barIn(v), 'range');
  v = sectionOf(root, 'varlog.title');
  const b = barIn(v);
  const days = b.querySelectorAll('.lg-time-day');
  const clocks = b.querySelectorAll('.lg-time-clock');
  const d = new Date((now + 86400) * 1000);
  days[0].value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  clocks[0].value = '7:05';
  days[0].dispatch('change');
  v = sectionOf(root, 'varlog.title');
  same('/var/log: «From» tomorrow — no file fits, the range in the note line', [v.querySelectorAll('.row').length, tx(v.querySelector('p.empty')), noteChips(barIn(v))],
    [0, T('found.none_fit'), [T('time.chip_from', { from: O.fmt.date(new Date(d.getFullYear(), d.getMonth(), d.getDate(), 7, 5).getTime() / 1000) })]]);
  barIn(v).querySelector('.filter-all').click();
  v = sectionOf(root, 'varlog.title');
  same('/var/log: «Show all» takes the time away too', [note(barIn(v)).hidden, pick(barIn(v)).value, v.querySelectorAll('.row').length], [true, '', all]);
  same('/var/log: a bad time is marked (the whole day counts)', (() => { clocks[1].value = '25:00'; clocks[1].dispatch('input'); return clocks[1].classList.contains('lg-time-bad'); })(), true);
  clocks[1].value = '';
  clocks[1].dispatch('input');

  // ---- a saved filter: applied by its click, nothing else
  let s = sectionOf(root, 'syslog.title');
  const kinds = s.querySelectorAll('.lg-kind').length;
  s.querySelectorAll('.lg-saved-btn').find((x) => tx(x) === 'Network, last hour').click();
  s = sectionOf(root, 'syslog.title');
  const sorted = (tour.sources.find((x) => x.id === 'syslog').sorted || []).filter((g) => g.cat === 'network' && g.last >= now - 3600 && /br0/i.test(g.sample)).length;
  same('saved: its click sets what it says — the words, the category, the time', [barIn(s).querySelector('.filter-words').value, pick(barIn(s)).value,
    noteChips(barIn(s))], ['br0', 'hour', [T('cat.network'), T('time.hour'), '«br0»']]);
  same('saved: … the rows narrowed by it', s.querySelectorAll('.lg-kind').length, sorted);
  same('saved: … its button pressed, the others not, no «Save filter…» (it is saved)', [s.querySelectorAll('.lg-saved-btn').map((x) => x.getAttribute('aria-pressed')),
    s.querySelectorAll('.lg-saved-save').length], [['false', 'true', 'false'], 0]);
  same('saved: the lists without a click stay as they were', [note(barIn(sectionOf(root, 'found.title'))).hidden, note(barIn(sectionOf(root, 'varlog.title'))).hidden], [true, true]);
  barIn(s).querySelector('.filter-all').click();
  s = sectionOf(root, 'syslog.title');
  same('saved: «Show all» — everything again, the button no longer pressed', [s.querySelectorAll('.lg-kind').length, note(barIn(s)).hidden,
    s.querySelectorAll('.lg-saved-btn').map((x) => x.getAttribute('aria-pressed'))], [kinds, true, ['false', 'false', 'false']]);
  s.querySelector('.lg-saved-more').click();
  same('saved: «⋯» offers rename and delete', (menus[0] || []).map((m) => m.text), [T('saved.rename'), T('saved.delete')]);
  menus[0][1].act();
  const del = dialogs.pop();
  same('saved: delete asks first', [del && del.title, del && del.buttons.length], [T('saved.delete_title'), 2]);
  await del.buttons[1].act();
  same('saved: … then the agent deletes it by its id', posts.filter(([a]) => a === 'logs.filter_remove').map(([, x]) => x), [{ id: 'a1b2c3d4e5f6' }]);
  menus[0][0].act();
  const ren = dialogs.pop();
  ren.body.querySelector('input').value = 'Disks, today';
  await ren.buttons[1].act();
  same('saved: rename sends its id and the new name', posts.filter(([a]) => a === 'logs.filter_rename').map(([, x]) => x), [{ id: 'a1b2c3d4e5f6', name: 'Disks, today' }]);

  // ---- the order: newest first (the list's own), oldest first, by service — a choice, named only when not the list's own
  const svcOf = (g) => (/^\S+\s+\d+\s+[\d:]+\s+\S+\s+([^\s:[\]]+)/.exec(g.sample) || [])[1] || '';
  const allKinds = (tour.sources.find((x) => x.id === 'syslog').sorted || []);
  const sysKinds = allKinds.filter((g) => g.cat !== 'other');        // what no rule knows: only by its chip
  const lastOf = new Map(allKinds.map((g) => [g.sample.slice(g.skip).replace(/^[\s:|\]-]+/, ''), g]));
  const shownKinds = () => sectionOf(root, 'syslog.title').querySelectorAll('.lg-kind-line').map((n) => lastOf.get(tx(n).replace(/ …$/, '')));
  const lasts = () => shownKinds().map((g) => g.last);
  same('order: «Syslog» newest first by default, nothing said', [lasts(), note(barIn(sectionOf(root, 'syslog.title'))).hidden],
    [sysKinds.map((g) => g.last).sort((x, y) => y - x), true]);
  let sortSel = barIn(sectionOf(root, 'syslog.title')).querySelector('.lg-sort');
  same('order: a choice of four in the bar (the most frequent first too)', sortSel.options.map((o) => o.val), ['newest', 'oldest', 'service', 'count']);
  sortSel.value = 'oldest';
  sortSel.dispatch('change');
  s = sectionOf(root, 'syslog.title');
  same('order: oldest first — the rows by their newest line, said in the note line, kept per browser', [lasts(), note(barIn(s)).hidden,
    tx(barIn(s).querySelector('.filter-order')), barIn(s).querySelector('.filter-note-text').hidden, stored['office.logs.syslog.sort']],
  [sysKinds.map((g) => g.last).sort((x, y) => x - y), false, T('sort.oldest_note') + '×', true, 'oldest']);
  barIn(s).querySelector('.filter-order').querySelector('.filter-chip').click();
  s = sectionOf(root, 'syslog.title');
  same('order: its × — back to the list\'s own, nothing said, nothing kept', [lasts()[0], note(barIn(s)).hidden, stored['office.logs.syslog.sort']],
    [Math.max(...sysKinds.map((g) => g.last)), true, undefined]);
  sortSel = barIn(s).querySelector('.lg-sort');
  sortSel.value = 'service';
  sortSel.dispatch('change');
  const bySvc = shownKinds().map(svcOf);
  same('order: by service — A to Z', bySvc, bySvc.slice().sort((x, y) => x.localeCompare(y, undefined, { sensitivity: 'base' })));
  same('order: … more than one service in the demo', new Set(bySvc).size > 2, true);
  sortSel = barIn(sectionOf(root, 'syslog.title')).querySelector('.lg-sort');
  sortSel.value = 'count';
  sortSel.dispatch('change');
  s = sectionOf(root, 'syslog.title');
  const counts = shownKinds().map((g) => g.count);
  same('order: the most frequent first — by «×n», said in the note line', [counts, tx(barIn(s).querySelector('.filter-order'))],
    [counts.slice().sort((x, y) => y - x), T('sort.count_note') + '×']);
  same('order: … what no rule knows stays out of the list', shownKinds().some((g) => g.cat === 'other'), false);
  const other = barIn(s).querySelector('[data-cat="other"]');
  same('no rule: its own chip, last', [!!other, [...barIn(s).querySelectorAll('.lg-cat-chip')].pop() === other], [true, true]);
  other.click();
  s = sectionOf(root, 'syslog.title');
  same('no rule: … a click shows only what no rule knows, the most frequent first, a word above', [shownKinds().map((g) => [g.cat, g.count]),
    s.querySelectorAll('.lg-note').some((n) => tx(n) === T('cats.other_list'))],
    [allKinds.filter((g) => g.cat === 'other').map((g) => [g.cat, g.count]).sort((x, y) => y[1] - x[1]), true]);
  barIn(s).querySelector('[data-cat="other"]').click();
  sortSel = barIn(sectionOf(root, 'syslog.title')).querySelector('.lg-sort');
  sortSel.value = 'service';
  sortSel.dispatch('change');

  // ---- a click on a line's service: only that one, said in the note line, again: all
  s = sectionOf(root, 'syslog.title');
  const svcBtn = s.querySelectorAll('.lg-svc').find((b) => tx(b) === 'emhttpd');
  same('service: each row\'s service a button with its own tip', [!!svcBtn, svcBtn && svcBtn.title], [true, T('svc.only_title', { name: 'emhttpd' })]);
  svcBtn.click();
  s = sectionOf(root, 'syslog.title');
  same('service: its click shows only that service, in the note line', [[...new Set(shownKinds().map(svcOf))], noteChips(barIn(s)),
    s.querySelectorAll('.lg-svc').every((b) => b.getAttribute('aria-pressed') === 'true')], [['emhttpd'], [T('svc.chip', { name: 'emhttpd' })], true]);
  same('service: … the order still said after the filter', tx(barIn(s).querySelector('.filter-order')), '· ' + T('sort.service_note') + '×');
  s.querySelector('.lg-svc').click();
  s = sectionOf(root, 'syslog.title');
  same('service: the same one again — every service', [shownKinds().length, noteChips(barIn(s))], [kinds, []]);
  s.querySelectorAll('.lg-saved-btn').find((x) => x.dataset.saved === 'abcabcabcabc').click();
  s = sectionOf(root, 'syslog.title');
  same('service: a saved filter keeps it — its click sets the service', [[...new Set(shownKinds().map(svcOf))], noteChips(barIn(s))],
    [['kernel'], [T('svc.chip', { name: 'kernel' })]]);
  barIn(s).querySelector('.filter-all').click();
  s = sectionOf(root, 'syslog.title');
  same('service: «Show all» takes it away, the order stays (a choice)', [note(barIn(s)).hidden, tx(barIn(s).querySelector('.filter-order')), shownKinds().length],
    [false, T('sort.service_note') + '×', kinds]);
  barIn(s).querySelector('.filter-order').querySelector('.filter-chip').click();
  s = sectionOf(root, 'syslog.title');
  s.querySelectorAll('.lg-svc').find((b) => tx(b) === 'kernel').click();
  s = sectionOf(root, 'syslog.title');
  same('service: kernel alone is «Kernel only» — its button pressed, nothing to save', [s.querySelectorAll('.lg-saved-btn').filter((x) => x.getAttribute('aria-pressed') === 'true').map(tx),
    s.querySelectorAll('.lg-saved-save').length], [['Kernel only'], 0]);
  s.querySelector('.lg-svc').click();
  s = sectionOf(root, 'syslog.title');
  s.querySelectorAll('.lg-svc').find((b) => tx(b) === 'emhttpd').click();
  s = sectionOf(root, 'syslog.title');
  s.querySelector('.lg-saved-save').click();
  const kdlg = dialogs.pop();
  kdlg.body.querySelector('input').value = 'Web UI';
  await kdlg.buttons[1].act();
  same('service: «Save filter…» sends it', posts.filter(([a]) => a === 'logs.filter_save').map(([, x]) => [x.list, x.svc]), [['syslog', 'emhttpd']]);
  barIn(sectionOf(root, 'syslog.title')).querySelector('.filter-all').click();
  let fd = sectionOf(root, 'found.title');
  same('order: «Errors & warnings» has its own (newest first)', barIn(fd).querySelector('.lg-sort').value, 'newest');
  posts.splice(0, posts.length, ...posts.filter(([a]) => a !== 'logs.filter_save'));

  // ---- «Save filter…»: what the bar has set, for its list
  let f = sectionOf(root, 'found.title');
  const words = barIn(f).querySelector('.filter-words');
  words.value = 'smbd';
  words.dispatch('input');
  f = sectionOf(root, 'found.title');
  setPick(barIn(f), 'day');
  f = sectionOf(root, 'found.title');
  const save = f.querySelector('.lg-saved-save');
  same('save: «Save filter…» while something is set', tx(save), T('saved.save'));
  save.click();
  const dlg = dialogs.pop();
  same('save: a dialog that says what it keeps', [dlg && dlg.title, dlg && tx(dlg.body.querySelector('p'))],
    [T('saved.save_title'), T('saved.save_text', { what: `«smbd» · ${T('time.day')}` })]);
  const left = await dlg.buttons[1].act();
  same('save: no name — not sent, said', [left, posts.filter(([a]) => a === 'logs.filter_save').length, tx(dlg.body.querySelector('.lg-saved-why'))],
    [false, 0, T('errors.logs_filter_name_empty')]);
  dlg.body.querySelector('input').value = '  Samba today ';
  await dlg.buttons[1].act();
  same('save: the agent gets the list, the name and exactly what is set (a preset stays relative)', posts.filter(([a]) => a === 'logs.filter_save').map(([, x]) => x),
    [{ list: 'found', name: 'Samba today', words: 'smbd', cat: '', src: '', only: false, svc: '', time: 'day', from: null, to: null }]);

  // ---- the reader: its lines by their own time, a line without one by the one before
  const r = sectionOf(root, 'reading');
  same('reader: every line read', r.querySelectorAll('.lg-line').length, 4);
  setPick(barIn(r), 'hour');
  same('reader: «Last hour» by the lines\' own time (the trace\'s next line with its line)', r.querySelectorAll('.lg-line').map(tx),
    [readLines[1], readLines[2], readLines[3]]);
  same('reader: the time in its note line', noteChips(barIn(r)), [T('time.hour')]);
  const only = barIn(r).querySelector('.filter-only').querySelector('input');
  only.checked = true;
  only.dispatch('change');
  same('reader: «only errors & warnings» is its bar\'s switch, with the time', [r.querySelectorAll('.lg-line').map(tx), noteChips(barIn(r))],
    [[readLines[1]], [T('time.hour'), T('only_problems')]]);

  only.checked = false;
  only.dispatch('change');
  barIn(r).querySelector('.filter-all').click();
  same('reader: the order of the file by default (the oldest first), nothing said', [r.querySelectorAll('.lg-line').map(tx), note(barIn(r)).hidden], [readLines, true]);
  r.querySelectorAll('.lg-svc-in').find((x) => x.dataset.svc === 'kernel').click();
  same('reader: a click on a line\'s service — only its lines (a trace\'s next line with it), in the note line',
    [r.querySelectorAll('.lg-line').map(tx), noteChips(barIn(r))], [[readLines[1], readLines[2]], [T('svc.chip', { name: 'kernel' })]]);
  r.querySelector('.lg-svc-in').click();
  same('reader: the same again — every line', r.querySelectorAll('.lg-line').length, 4);
  const rs = barIn(r).querySelector('.lg-sort');
  rs.value = 'newest';
  rs.dispatch('change');
  same('reader: newest first — the lines turned, said in the note line', [r.querySelectorAll('.lg-line').map(tx), tx(barIn(r).querySelector('.filter-order'))],
    [readLines.slice().reverse(), T('sort.newest_note') + '×']);
  rs.value = 'service';
  rs.dispatch('change');
  same('reader: by service — A to Z, a trace\'s next line with its line', r.querySelectorAll('.lg-line').map(tx), [readLines[3], readLines[1], readLines[2], readLines[0]]);
  rs.value = 'oldest';
  rs.dispatch('change');

  // ---- a new visit: every bar empty again, nothing applied
  logs.unmount();
  state = freshState();
  const root2 = new FakeNode('div');
  desk.replaceChildren(root2);
  await logs.mount(root2, '');
  await tick();
  await tick();
  const again = Object.values(places).map((p) => barIn(sectionOf(root2, p)));
  same('a new visit: every bar empty, nothing applied by itself', again.map((x) => [note(x).hidden, x.querySelector('.filter-words').value, pick(x).value,
    !!(x.querySelector('.filter-only') && x.querySelector('.filter-only').querySelector('input').checked)]), again.map(() => [true, '', '', false]));
  logs.unmount();

  // ---- «Who woke my disks?» on a server without a spinning disk (all flash): her tile is there and says so in one line;
  // its section (a click) the same line — no switch, no filter bar, no empty list. Before her first look: «not yet».
  const wakesVisit = async (wakes, sub) => {
    state = freshState();
    if (wakes === undefined) delete state.wakes; else state.wakes = wakes;
    const r = new FakeNode('div');
    desk.replaceChildren(r);
    await logs.mount(r, sub);
    await tick();
    await tick();
    const tile = r.all().find((n) => n.dataset && n.dataset.tile === 'wakes') || null;
    const sec = sectionOf(r, 'wakes.title');
    const out = {
      tile: tile ? tile.querySelectorAll('.card-line').map(tx) : null,
      empty: !!(tile && tile.classList.contains('empty-card')),
      section: sec ? sec.querySelectorAll('p').map(tx) : null,
      bar: !!barIn(sec),
      switch: !!(sec && sec.querySelector('.switch')),
      boxes: sec ? sec.querySelectorAll('.box').length : -1,
    };
    logs.unmount();
    return out;
  };
  const flash = { hdds: [], since: now - 86400, look: 4, on: true, wakes: [] };
  same('all flash: her tile there, one line — no disk spins here', await wakesVisit(flash, ''),
    { tile: [T('wakes.no_hdd')], empty: true, section: [T('wakes.no_hdd')], bar: false, switch: false, boxes: 0 });
  same('all flash: her section alone (a click) — the same one line, no filter bar', await wakesVisit(flash, 'wakes'),
    { tile: [T('wakes.no_hdd')], empty: true, section: [T('wakes.no_hdd')], bar: false, switch: false, boxes: 0 });
  same('all flash, switched off: still the one line (nothing to switch)', (await wakesVisit({ ...flash, on: false }, 'wakes')).section, [T('wakes.no_hdd')]);
  same('before her first look: «not yet», as always', await wakesVisit(undefined, 'wakes'),
    { tile: [T('wakes.not_yet')], empty: true, section: [T('wakes.not_yet')], bar: false, switch: false, boxes: 0 });
  const spinning = await wakesVisit({ ...flash, hdds: ['parity', 'disk1'] }, 'wakes');
  same('a spinning disk, no wake yet: «none in 7 days», the switch and the filter bar', [spinning.tile[0], spinning.bar, spinning.switch],
    [T('wakes.tile_none'), true, true]);
  const old = await wakesVisit({ ...flash, wakes: [{ id: 'w1', time: now - 3600, from: now - 3700, disks: ['disk1'], how: 'noticed', who: [] }] }, 'wakes');
  same('no spinning disk now, but a wake kept from before: shown as always', [old.tile, old.bar], [[T('wakes.tile_today', { n: 1 }), T('wakes.tile_week', { n: 1 })], true]);
  const older = await wakesVisit({ since: now - 86400, look: 4, wakes: [] }, 'wakes');
  same('an older messenger\'s state (no `hdds`): the tile as it always was', [older.tile, older.bar], [[T('wakes.tile_none'), T('wakes.tile_since', { when: O.fmt.date(now - 86400) })], true]);
}

// her follow and tour timers end with the page; a failure on the way leaves them running: out after the line
run().then(() => { console.log(JSON.stringify(out)); process.exit(0); },
  (e) => { out.fail.push(['ran', String(e && e.stack || e).slice(0, 600), 'no error']); console.log(JSON.stringify(out)); process.exit(0); });
