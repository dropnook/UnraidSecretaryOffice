/*
 * The section switcher and the page tabs every desk shares (public/assets/core.js, under node, on a small stand-in DOM):
 *   sections  Office.sections() — the choice (pick, the second click, the default, the store, a tile's route, a tile the
 *             list only has later), the «nothing active» page (every section once, in the row's order), a filter tile
 *             (never stored, data-filters on its section, its words for the bar's note line), the attributes and the mark
 *             (aria-pressed, aria-controls, .tile-on, «Filter on» / «Open»), the sticky head with «◂ All», the viewport
 *             rule (a phone scrolls the head to the top, a desktop brings it in only when it isn't), the focus on the
 *             clicked tile, places(); Office.stat / Office.tile with mark
 *   pages     Office.pages() / Office.pageFrom() / Office.deskHead({pages}) — two tabs, aria-selected, href = the route, a
 *             third refused, the click, the kept page
 *   schedule  Office.scheduleTile() — off, the next run (today, tomorrow, due), the last run and how it ended, work that
 *             goes by itself (never «Off»), a card with its own words, its place and route in the switcher
 *
 *   node tests/sections.js public/assets/core.js [sections|pages|schedule]   → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testSections (sections), testDeskPages (pages) and testScheduleTile (schedule) run it on the server; on
 * the Mac it runs as it is.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const file = process.argv[2] || path.join(__dirname, '../public/assets/core.js');
const part = process.argv[3] || 'all';
const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};

// ------------------------------------------------------------------ a stand-in DOM (what core.js and the switcher use)
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
    this.offsetHeight = 0;
    this.rect = null;          // a test sets where it is on screen
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
  get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; this.text = v === null || v === undefined ? '' : String(v); }
  get firstChild() { return this.children[0] || null; }
  get lastChild() { return this.children[this.children.length - 1] || null; }
  get nodeType() { return this.tagName === '#TEXT' ? 3 : 1; }
  get isConnected() { let n = this; while (n.parentNode) n = n.parentNode; return n === doc.body; }
  appendChild(c) {
    if (typeof c === 'string') c = textNode(c);
    if (c.parentNode) c.parentNode.removeChild(c);
    c.parentNode = this;
    this.children.push(c);
    return c;
  }
  append(...cs) { cs.forEach((c) => this.appendChild(c)); }
  prepend(...cs) { cs.reverse().forEach((c) => { if (typeof c === 'string') c = textNode(c); if (c.parentNode) c.parentNode.removeChild(c); c.parentNode = this; this.children.unshift(c); }); }
  replaceChildren(...cs) { [...this.children].forEach((c) => this.removeChild(c)); this.text = ''; this.append(...cs); }
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
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  removeEventListener() {}
  dispatch(type, extra) {
    const ev = { type, target: this, defaultPrevented: false, button: 0, preventDefault() { this.defaultPrevented = true; }, stopPropagation() {}, ...(extra || {}) };
    for (let n = this; n; n = n.parentNode) (n.listeners[type] || []).forEach((fn) => fn.call(n, ev));
    return ev;
  }
  click() { return this.dispatch('click'); }
  focus() { doc.activeElement = this; }
  contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
  all() { return this.children.flatMap((c) => (c.tagName === '#TEXT' ? [] : [c, ...c.all()])); }
  // selectors: '.a', '.a.b', 'tag', '[data-x]' — enough for core.js's own
  matches(sel) {
    return String(sel).split(',').some((s) => {
      const m = s.trim();
      if (m.startsWith('[')) { const k = m.slice(1, -1).split('=')[0]; return k.startsWith('data-') ? k.slice(5).replace(/-(\w)/g, (x, c) => c.toUpperCase()) in this.dataset : k in this.attrs; }
      const [tag, ...cls] = m.split('.');
      return (!tag || this.tagName === tag.toUpperCase()) && cls.every((c) => this.classList.contains(c));
    });
  }
  querySelector(sel) { return this.all().find((c) => c.matches(sel)) || null; }
  querySelectorAll(sel) { return this.all().filter((c) => c.matches(sel)); }
  closest(sel) { for (let n = this; n && n.matches; n = n.parentNode) if (n.matches(sel)) return n; return null; }
  getBoundingClientRect() { return this.rect || { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }; }
  scrollIntoView(o) { scrolled.push(['into', this.className, o]); }
  find(cls) { return this.all().find((c) => c.classList.contains(cls)) || null; }
}
const textNode = (s) => { const n = new FakeNode('#text'); n.text = String(s); n.all = () => []; return n; };
const desk = new FakeNode('main');
desk.id = 'sso-desk';
const scrolled = [];
doc = {
  body: new FakeNode('body'),
  activeElement: null,
  getElementById: (id) => (id === 'sso-config' ? { textContent: JSON.stringify({ desks: [{ id: 'shelf', order: 1, hired: true }], languages: [{ code: 'en' }], base: '', staff_order: [] }) }
    : id === 'menu' ? null : id === 'sso-desk' ? desk : new FakeNode('div')),
  querySelector: (s) => (s === '#sso-desk' ? desk : new FakeNode('div')),
  querySelectorAll: () => [],
  createElement: (tag) => new FakeNode(tag),
  addEventListener() {},
  documentElement: { scrollHeight: 0, clientWidth: 1440 },
  hidden: false,
};
doc.activeElement = doc.body;
doc.body.appendChild(desk);
globalThis.document = doc;
globalThis.window = globalThis;
globalThis.innerHeight = 800; globalThis.innerWidth = 1440; globalThis.scrollY = 0;
globalThis.scrollBy = (x, y) => { scrolled.push(['by', y]); };
globalThis.scrollTo = (a) => { scrolled.push(['to', typeof a === 'object' ? a.top : a]); };
let wide = true;               // the window's width: ≥ 768 (desktop) or a phone
globalThis.matchMedia = (q) => ({ matches: /min-width/.test(q) ? wide : !wide });
const stored = {};
const def = (name, value) => Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
def('localStorage', {
  getItem: (k) => (k in stored ? stored[k] : null),
  setItem: (k, v) => { stored[k] = String(v); },
  removeItem: (k) => { delete stored[k]; },
});
def('navigator', { languages: ['en'] });
const replaced = [];
globalThis.history = { replaceState(a, b, url) { replaced.push(url); } };
globalThis.location = { hash: '', reload() {} };

(0, eval)(fs.readFileSync(file, 'utf8'));
const O = globalThis.Office;
O.strings = JSON.parse(fs.readFileSync(path.join(path.dirname(file), '..', 'lang', 'en.json'), 'utf8'));
O.strings['shelf.name'] = 'Ms. Shelf';
O.current = { id: 'shelf' };           // the desk shown (Office.subroute() writes its address)

// ------------------------------------------------------------------ the switcher
function sections() {
  const log = [];
  let partner = false;          // a tile the list only has later (a partner's, once the state came)
  const section = (name, heads = 1) => () => {
    log.push(['draw', name, sw.active()]);
    const s = O.el('section', 'section');
    for (let i = 0; i < heads; i++) s.appendChild(O.sectionHead(name.toUpperCase(), 'sub'));
    return s;
  };
  const tiles = () => [
    { id: 'apps', key: 'tile.apps', tile: (a) => O.tile({ name: 'Apps', ...a }), section: section('apps') },
    { id: 'vms', key: 'stat.vms', tile: (a) => O.stat({ label: 'VMs', value: 1, ...a }), section: 'prot', filters: 'VMs' },
    { id: 'prot', key: 'stat.prot', tile: (a) => O.stat({ label: 'Protection', value: 9, ...a }), section: 'prot' },
    { id: 'sched', key: 'tile.sched', tile: (a) => O.tile({ name: 'Schedule', ...a }), section: section('sched') },
    ...(partner ? [{ id: 'partner:ab12cd34', tile: (a) => O.tile({ name: 'At vault', ...a }), section: section('partner') }] : []),
    { id: 'none', key: 'tile.none', tile: (a) => O.tile({ name: 'Empty', ...a }), section: () => null },
  ];
  const mk = (more) => O.sections({ id: 'shelf', grid: 'cards sh-tiles', tiles, sections: { prot: section('prot') }, default: 'apps',
    onPick: (id, how) => log.push(['pick', id, how]), wants: (s) => /^partner:[0-9a-f]{8}$/.test(s), ...more });
  let sw = mk();
  const tileEl = (id) => sw.grid.children.find((n) => n.dataset.tile === id);
  const pressed = () => sw.grid.children.filter((n) => n.getAttribute('aria-pressed') === 'true').map((n) => n.dataset.tile);
  const drawn = () => sw.body.dataset.sections;
  desk.replaceChildren(sw.el);

  same('el: a .sections with the row (its classes) and the body', [sw.el.className, sw.grid.className, sw.body.className, sw.el.children.length],
    ['sections', 'cards sh-tiles', 'sections-body', 2]);
  same('body: an id of its own, data-sections-body', [sw.body.id, sw.body.dataset.sectionsBody], ['shelf-sections', '1']);
  sw.mount('');
  sw.render();
  same('default: the start tile on the first visit (nothing stored) — apps alone under the row', [sw.active(), drawn(), sw.body.dataset.active, pressed()],
    ['apps', 'apps', 'apps', ['apps']]);
  const apps = tileEl('apps');
  same('tile: a button, aria-pressed, aria-controls = the body, its place tile.<id>, data-tile', [apps.tagName, apps.getAttribute('aria-pressed'), apps.getAttribute('aria-controls'), apps.dataset.place, apps.dataset.tile],
    ['BUTTON', 'true', 'shelf-sections', 'tile.apps', 'apps']);
  const mark = apps.find('tile-mark');
  same('mark: the active section tile glows (.tile-on) and says «Open», aria-hidden', [apps.classList.contains('tile-on'), mark && mark.textContent, mark && mark.getAttribute('aria-hidden')],
    [true, 'Open', 'true']);
  same('mark: the other tiles neither glow nor carry a mark', sw.grid.children.filter((n) => n !== apps).map((n) => [n.classList.contains('tile-on'), !!n.find('tile-mark')]),
    [[false, false], [false, false], [false, false], [false, false]]);
  same('tiles: the row in the list\'s order, number tiles as button.stat', sw.grid.children.map((n) => `${n.tagName.toLowerCase()}.${n.className.split(' ')[0]}:${n.dataset.tile}`),
    ['button.card:apps', 'button.stat:vms', 'button.stat:prot', 'button.card:sched', 'button.card:none']);
  same('store: nothing written by the first visit', stored['office.shelf.tile'], undefined);

  // the second click: nothing active — every section once, in the row's order (the shared one at its first tile; one
  // that draws nothing left out)
  log.length = 0;
  apps.click();
  same('second click: nothing active, the page whole — apps, prot (once for two tiles), sched; the empty one left out', [sw.active(), drawn(), pressed(), stored['office.shelf.tile']],
    ['', 'apps prot sched', [], '']);
  same('second click: onPick before anything is drawn', log[0], ['pick', '', 'click']);
  same('second click: no .tile-on, no mark left', [sw.grid.querySelectorAll('.tile-on').length, sw.grid.querySelectorAll('.tile-mark').length], [0, 0]);
  same('nothing active: no sticky head, no «◂ All»', [sw.body.querySelectorAll('.sticky').length, sw.body.querySelectorAll('.section-all').length], [0, 0]);
  same('focus: on the clicked tile, drawn anew', doc.activeElement === tileEl('apps'), true);

  // a filter tile: its shared section filtered, never kept
  log.length = 0;
  tileEl('vms').click();
  const vms = tileEl('vms');
  same('filter tile: its section alone — prot, drawn after onPick (the desk reads sw.active())', [sw.active(), drawn(), log.filter((x) => x[0] === 'draw')],
    ['vms', 'prot', [['draw', 'prot', 'vms']]]);
  same('filter tile: never stored — the store says nothing active', stored['office.shelf.tile'], '');
  const prot = sw.body.children[0];
  same('filter tile: data-filters = the id of its section (given by the switcher)', [vms.dataset.filters, prot.id, prot.dataset.section], ['shelf-s-prot', 'shelf-s-prot', 'prot']);
  same('filter tile: the mark says «Filter on»', [vms.classList.contains('tile-on'), vms.find('tile-mark').textContent], [true, 'Filter on']);
  same('filter tile: the unfiltered tile of that section has no data-filters', tileEl('prot').dataset.filters, undefined);
  same('note: the filter\'s words for the bar of its section, nothing for another', [sw.note('prot').tile, sw.note().tile, sw.note('apps').tile, typeof sw.note('prot').clear],
    ['VMs', 'VMs', '', 'function']);
  same('sticky: the chosen section\'s head .sticky, «◂ All» first in its bar', [prot.find('section-head').classList.contains('sticky'), prot.find('section-bar').children[0].className, prot.find('section-bar').children[0].textContent],
    [true, 'chip section-all', '◂ All']);
  same('body: one .section-head with a tile active', sw.body.querySelectorAll('.section-head').length, 1);
  tileEl('prot').click();
  same('the same section unfiltered: a section tile, stored', [sw.active(), drawn(), sw.note('prot').tile, stored['office.shelf.tile']], ['prot', 'prot', '', 'prot']);
  tileEl('vms').click();
  prot.find('section-all');
  sw.body.querySelector('.section-all').click();
  same('«◂ All»: nothing active, the page whole', [sw.active(), drawn(), log[log.length - 4]], ['', 'apps prot sched', ['pick', '', 'all']]);
  tileEl('vms').click();
  sw.clear();
  same('clear() (the bar\'s chip, «Show all»): nothing active', [sw.active(), drawn()], ['', 'apps prot sched']);

  // the store and a new visit
  tileEl('sched').click();
  same('a section tile is kept', stored['office.shelf.tile'], 'sched');
  sw = mk();
  desk.replaceChildren(sw.el);
  log.length = 0;
  same('a new visit: the kept tile (no route)', [sw.mount(''), sw.active(), log[0]], [false, 'sched', ['pick', 'sched', 'store']]);
  sw.render();
  same('… drawn', drawn(), 'sched');
  tileEl('vms').click();
  sw.mount('');
  same('a new visit after a filter tile: the last section choice (a filter is never kept, the choice before it stays)', sw.active(), 'sched');
  sw.pick('prot');
  sw.mount('');
  same('pick() from the desk keeps a section tile like a click', sw.active(), 'prot');
  sw.pick('prot');
  same('pick() of the active one: nothing (the second click)', sw.active(), '');
  sw.pick('prot', 'route');
  sw.pick('prot', 'route');
  same('pick(id, route): no toggle', sw.active(), 'prot');
  stored['office.shelf.tile'] = 'gone';
  sw.mount('');
  same('a kept tile that went: nothing active', [sw.active(), sw.render() && drawn()], ['', 'apps prot sched']);

  // routes: #/shelf/<tile> sets it like a click, the address back to #/shelf
  replaced.length = 0;
  same('route: a tile\'s sub-route picks it (a filter tile: the store untouched)', [sw.mount('vms'), sw.active(), replaced.slice(), stored['office.shelf.tile']], [true, 'vms', ['#/shelf'], 'gone']);
  same('route: a section tile\'s route is kept', [sw.mount('sched'), sw.active(), stored['office.shelf.tile']], [true, 'sched', 'sched']);
  same('route: a sub nobody\'s tile is the desk\'s (false), the kept choice stands', [sw.mount('setup'), sw.active()], [false, 'sched']);
  // a tile the list only has later (wants): chosen at once, drawn once it is there
  same('route: a partner\'s tile before the state came — taken (wants)', [sw.mount('partner:ab12cd34'), sw.active()], [true, '']);
  sw.render();
  same('… the page whole meanwhile', drawn(), 'apps prot sched');
  partner = true;
  sw.render();
  same('… its section once the tile is there', [sw.active(), drawn(), pressed()], ['partner:ab12cd34', 'partner:ab12cd34', ['partner:ab12cd34']]);
  same('places: the tiles with a key (not a partner\'s), anchor tile.<id>, route #/<desk>/<id>', sw.places(), [
    { kind: 'tile', key: 'tile.apps', anchor: 'tile.apps', route: '#/shelf/apps' }, { kind: 'tile', key: 'stat.vms', anchor: 'tile.vms', route: '#/shelf/vms' },
    { kind: 'tile', key: 'stat.prot', anchor: 'tile.prot', route: '#/shelf/prot' }, { kind: 'tile', key: 'tile.sched', anchor: 'tile.sched', route: '#/shelf/sched' },
    { kind: 'tile', key: 'tile.none', anchor: 'tile.none', route: '#/shelf/none' }]);
  partner = false;
  sw.render();
  same('the chosen tile went from the list: the page whole', [sw.active(), drawn()], ['', 'apps prot sched']);
  sw.pick('sched');
  sw.render();
  sw.render();
  same('render(): the choice stays (every answer of the agent draws anew)', [sw.active(), drawn(), pressed()], ['sched', 'sched', ['sched']]);
  const key2 = O.sections({ id: 'shelf', key: 'shelf.section', tiles, default: '' });
  stored['office.shelf.section'] = 'apps';
  same('key: a key kept before (restore.section …)', [key2.mount(''), key2.active()], [false, 'apps']);
  same('default \'\': nothing active on a first visit', [O.sections({ id: 'other', tiles, default: '' }).active()], ['']);

  // the viewport rule
  const head = () => sw.body.querySelector('.section-head');
  const drawRect = () => { sw.body.querySelectorAll('.section-head').forEach((h) => { h.rect = { top: 1200, bottom: 1240, height: 40 }; }); };
  sw.pick('sched');               // off
  wide = false;
  globalThis.scrollY = 300;
  scrolled.length = 0;
  const realDraw = sw.body.replaceChildren.bind(sw.body);
  sw.body.replaceChildren = (...c) => { realDraw(...c); };
  // a phone: the chosen head to the top (Unraid's header static there: 0) — where it stands after keepInPlace
  const orig = O.keepInPlace;
  O.keepInPlace = (a, change) => { change(); drawRect(); };
  tileEl('apps').click();
  same('phone: the page scrolls so the chosen head stands at the top (its top + scrollY)', scrolled.filter((s) => s[0] === 'to'), [['to', 1500]]);
  same('phone: the head sticky at top 0 (no sticky Unraid menu)', [head().classList.contains('sticky'), head().style.top], [true, '0px']);
  // the second tap with the tile in view: nothing moves; «◂ All» with the tile out of view: back to it
  scrolled.length = 0;
  tileEl('apps').rect = { top: 200, bottom: 280 };
  tileEl('apps').click();
  same('phone: the second tap, the tile in view — no move', scrolled.filter((s) => s[0] === 'to'), []);
  tileEl('apps').click();
  scrolled.length = 0;
  globalThis.scrollY = 2000;
  O.keepInPlace = (a, change) => { change(); sw.grid.children.forEach((n) => { n.rect = { top: -900, bottom: -820 }; }); };
  sw.body.querySelector('.section-all').click();
  same('phone: «◂ All» — back to the row, the tile that went off at the top (12 px under it)', [sw.active(), scrolled.filter((s) => s[0] === 'to')], ['', [['to', 2000 - 900 - 12]]]);
  same('phone: … the focus on that tile', doc.activeElement === tileEl('apps'), true);
  // a desktop: in view — nothing; below the window — scrollIntoView({block: 'nearest'})
  wide = true;
  scrolled.length = 0;
  O.keepInPlace = (a, change) => { change(); sw.body.querySelectorAll('.section-head').forEach((h) => { h.rect = { top: 600, bottom: 640 }; }); };
  tileEl('sched').click();
  same('desktop: the head in view after keepInPlace — no move', scrolled, []);
  tileEl('sched').click();
  O.keepInPlace = (a, change) => { change(); drawRect(); };
  tileEl('sched').click();
  same('desktop: the head below the window — brought in with block nearest, nothing else', scrolled, [['into', 'section-head sticky', { block: 'nearest' }]]);
  same('desktop: the focus stays on the clicked tile', doc.activeElement === tileEl('sched'), true);
  scrolled.length = 0;
  tileEl('sched').click();
  same('desktop: going off moves nothing', scrolled, []);
  O.keepInPlace = orig;

  // the builders' mark
  const s0 = O.stat({ label: 'x', value: 1, on: () => {}, pressed: false, mark: 'Filter on' });
  const s1 = O.stat({ label: 'x', value: 1, on: () => {}, pressed: true, mark: 'Filter on' });
  same('stat: mark only while pressed, with .tile-on', [!!s0.find('tile-mark'), s0.classList.contains('tile-on'), s1.find('tile-mark').textContent, s1.classList.contains('tile-on')],
    [false, false, 'Filter on', true]);
  const c1 = O.tile({ name: 'x', on: () => {}, active: true, mark: 'Open' });
  same('tile: mark while active, the last child, .active and .tile-on', [c1.lastChild.className, c1.lastChild.textContent, c1.className], ['tile-mark', 'Open', 'card active tile-on']);
  same('stat / tile: no mark given — as before', [O.stat({ label: 'x', value: 1, on: () => {}, pressed: true }).classList.contains('tile-on'), !!O.tile({ name: 'x', on: () => {}, active: true }).find('tile-mark')],
    [false, false]);
  // a builder that drops the helper's attributes: the switcher sets them anyway
  const bare = O.sections({ id: 'bare', tiles: [{ id: 'a', tile: () => O.el('button', 'card'), section: section('a') }, { id: 'f', tile: () => O.el('button', 'stat'), section: 'a', filters: 'F' }] });
  bare.pick('f');
  same('a builder that dropped them: aria-pressed, aria-controls, data-filters, place, the mark set by the switcher',
    bare.grid.children.map((n) => [n.getAttribute('aria-pressed'), n.getAttribute('aria-controls'), n.dataset.filters || null, n.dataset.place, n.classList.contains('tile-on'), !!n.find('tile-mark')]),
    [['false', 'bare-sections', null, 'tile.a', false, false], ['true', 'bare-sections', 'bare-s-a', 'tile.f', true, true]]);
}

// ------------------------------------------------------------------ the page tabs
function pages() {
  const list = [{ id: '', text: 'Overview' }, { id: 'setup', text: 'Set up' }];
  const nav = O.pages('shelf', list, 'setup');
  same('pages: a nav.desk-pages, role tablist, named by the desk', [nav.tagName, nav.className, nav.getAttribute('role'), nav.getAttribute('aria-label')], ['NAV', 'desk-pages', 'tablist', 'Ms. Shelf']);
  same('pages: two tabs — role tab, aria-selected, href = today\'s route', nav.children.map((a) => [a.tagName, a.getAttribute('role'), a.getAttribute('aria-selected'), a.getAttribute('href'), a.textContent, a.className]),
    [['A', 'tab', 'false', '#/shelf', 'Overview', ''], ['A', 'tab', 'true', '#/shelf/setup', 'Set up', 'active']]);
  same('pages: the overview\'s word in five languages\' office strings', O.t('common.page_overview'), 'Overview');
  same('pages: a third tab refused (null) — two pages, never more', O.pages('shelf', [...list, { id: 'more', text: 'More' }], ''), null);
  same('pages: one tab is no tabs (null); no list (null)', [O.pages('shelf', [list[0]], ''), O.pages('shelf', null, '')], [null, null]);
  same('pages: a current nobody has — the first', O.pages('shelf', list, 'zzz').children.map((a) => a.getAttribute('aria-selected')), ['true', 'false']);
  location.hash = '#/shelf/setup';
  const ev = nav.children[0].click();
  same('pages: a click is Office.go() to its route (the browser\'s own jump prevented)', [location.hash, ev.defaultPrevented], ['#/shelf', true]);
  const evNew = nav.children[1].dispatch('click', { metaKey: true });
  same('pages: with ⌘ / Ctrl the browser\'s (a new tab)', [location.hash, evNew.defaultPrevented], ['#/shelf', false]);
  // Ms. Dustdevil's: the page shown kept, for a route that names none
  const two = [{ id: 'where', text: 'Where is what' }, { id: 'tidy', text: 'Tidy up' }];
  O.pages('cleanup', two, 'tidy', 'cleanup.page');
  same('pages: store keeps the page shown', stored['office.cleanup.page'], 'tidy');
  same('pageFrom: a route\'s page, else the kept one, else the first', [O.pageFrom(two, 'where/docker', 'cleanup.page'), O.pageFrom(two, '', 'cleanup.page'), O.pageFrom(two, 'stat:x', 'cleanup.page'), O.pageFrom(list, '', null), O.pageFrom(list, 'setup', null)],
    ['where', 'tidy', 'tidy', '', 'setup']);
  stored['office.cleanup.page'] = 'gone';
  same('pageFrom: a kept page that isn\'t one — the first', O.pageFrom(two, '', 'cleanup.page'), 'where');
  // in the head: a strip across its whole width, its last child (after the text column and the actions), returned as pages
  const h = O.deskHead({ id: 'shelf' }, { bubble: 'Hello', actions: [], pages: { list, current: '' } });
  const kids = h.head.children.map((c) => c.className || c.tagName);
  same('deskHead: the tabs the head\'s last child, after the text column and the actions, returned as pages', [!!h.pages, kids.indexOf('desk-pages') > kids.indexOf('deskhead-actions'), kids[kids.length - 1], !h.head.find('deskhead-text').find('desk-pages'), h.pages && h.pages.children[0].getAttribute('aria-selected')],
    [true, true, 'desk-pages', true, 'true']);
  const plain = O.deskHead({ id: 'shelf' }, { bubble: 'Hello', actions: [] });
  same('deskHead: no pages — no tabs, as before', [plain.pages, !!plain.head.find('desk-pages')], [null, false]);
  const sub = O.deskHead({ id: 'shelf' }, { page: 'Set up', pages: { list, current: 'setup' } });
  same('deskHead: pages beat page: (no crumb, the tabs say where one is)', [sub.head.classList.contains('subpage'), !!sub.head.find('deskhead-crumb'), !!sub.pages], [false, false, true]);
}

// ------------------------------------------------------------------ the schedule tile
function schedule() {
  const now = Math.round(Date.now() / 1000);
  const at = (h, m, days = 0) => { const d = new Date(); d.setDate(d.getDate() + days); d.setHours(h, m, 0, 0); return Math.round(d.getTime() / 1000); };
  const later = at(23, 59) > now + 120 ? at(23, 59) : at(23, 59, 1);
  const parts = (n) => [n.find('stat-label') && n.find('stat-label').textContent, n.find('stat-value') && n.find('stat-value').textContent,
    n.all().filter((c) => c.classList.contains('stat-sub')).map((c) => c.textContent), n.classList.contains('alert'), n.classList.contains('sched-off')];
  const x = O.scheduleTile('shelf', { section: () => O.el('section', 'section') });
  same('schedule: an entry of the switcher — id schedule, its key and place tile.schedule, its section the desk\'s', [x.id, x.key, x.place, typeof x.section, typeof x.tile],
    ['schedule', 'tile.schedule', 'tile.schedule', 'function', 'function']);
  same('schedule: no line — «Off», when it runs instead, not run yet, quiet (no alert)', parts(x.tile({})),
    ['Schedule', 'Off', ['Only when you start it here', 'Not run yet'], false, true]);
  O.strings['shelf.tile.schedule'] = 'Plan';
  const on = O.scheduleTile('shelf', { schedule: { enabled: true, custom: '59 23 * * *', next: later }, last: { at: now - 7200, how: 'ok' }, sub: ['mine'] }).tile({});
  same('schedule: on — the desk\'s own name, the next run, the cron line in words, the last run and how it ended, the desk\'s line',
    parts(on), ['Plan', O.scheduleWhen(later), [O.fmt.cron('59 23 * * *'), `Last: ${O.fmt.relative(now - 7200)} · done`, 'mine'], false, false]);
  same('schedule: the tip names the next run in full and the cron line', [on.title.includes(O.fmt.date(later, true)), on.title.includes('cron: 59 23 * * *')], [true, true]);
  delete O.strings['shelf.tile.schedule'];
  same('schedule: when — today a time, tomorrow «Tomorrow, …», within the week its day, else the date',
    [O.scheduleWhen(at(23, 0), at(1, 0)), O.scheduleWhen(at(3, 30, 1), at(23, 0)), O.scheduleWhen(at(3, 30, 3), at(23, 0)).endsWith(O.fmt.time(at(3, 30, 3))), O.scheduleWhen(at(3, 30, 20), at(23, 0))],
    [O.fmt.time(at(23, 0)), `Tomorrow, ${O.fmt.time(at(3, 30, 1))}`, true, O.fmt.date(at(3, 30, 20))]);
  const failed = O.scheduleTile('shelf', { schedule: { enabled: true, custom: '30 3 * * *', next: now - 3600 }, last: { at: now - 90000, how: 'failed' } }).tile({});
  same('schedule: a next time gone by is «due now», never a time past; the last run failed — alert', [parts(failed)[1], parts(failed)[2][1], parts(failed)[3]],
    ['due now', `Last: ${O.fmt.relative(now - 90000)} · failed`, true]);
  same('schedule: running now; an unknown ending reads as done, never as failed', [O.scheduleLast({ at: now, how: 'running' }), O.scheduleLast({ at: now - 60, how: 'weird' }).endsWith('· done'), O.scheduleLast(null)],
    ['Running now', true, 'Not run yet']);
  const every = O.scheduleTile('shelf', { every: 'a round every five minutes', next: now + 180, last: { at: now - 120, how: 'stopped' } }).tile({});
  same('schedule: work that goes by itself — never «Off», how often, the last one stopped (no alert)', [parts(every)[1], parts(every)[2][0], parts(every)[2][1].endsWith('· stopped'), parts(every)[3], parts(every)[4]],
    [O.scheduleWhen(now + 180), 'a round every five minutes', true, false, false]);
  const card = O.scheduleTile('shelf', { card: true, key: 'plans', schedule: { enabled: true, custom: '', next: later }, sub: ['2 active'], chip: 'failed' }).tile({});
  const lines = card.all().filter((c) => c.classList.contains('card-line')).map((c) => c.textContent);
  same('schedule: a card — «Next time: …» first, its own lines, its warn label', [card.className.split(' ')[0], lines[0], lines.includes('2 active'), card.find('chip') && card.find('chip').textContent],
    ['card', `Next time: ${O.scheduleWhen(later)}`, true, 'failed']);
  const own = O.scheduleTile('shelf', { card: true, key: 'plans', schedule: { enabled: false }, value: 'none yet' }).tile({});
  same('schedule: the desk\'s own words instead of the next run (none set up) — no «only when you start it» beside them',
    own.all().filter((c) => c.classList.contains('card-line')).map((c) => c.textContent), ['none yet', 'Not run yet']);
  const sw = O.sections({ id: 'shelf', grid: 'stats', tiles: () => [O.scheduleTile('shelf', { section: () => { const s = O.el('section', 'section'); s.appendChild(O.sectionHead('SCHED', 'sub')); return s; } })] });
  desk.replaceChildren(sw.el);
  same('schedule: in the switcher a place #/shelf/schedule, the route picks it', [sw.places(), sw.mount('schedule'), sw.active()],
    [[{ kind: 'tile', key: 'tile.schedule', anchor: 'tile.schedule', route: '#/shelf/schedule' }], true, 'schedule']);
  sw.render();
  same('schedule: drawn as the tile of its section — data-tile, its place marked, the section alone under it', [sw.grid.children[0].dataset.tile, sw.grid.children[0].dataset.place, sw.body.dataset.sections],
    ['schedule', 'tile.schedule', 'schedule']);
}

try {
  if (part === 'all' || part === 'sections') sections();
  if (part === 'all' || part === 'pages') pages();
  if (part === 'all' || part === 'schedule') schedule();
} catch (e) {
  out.fail.push(['ran through', String(e && e.stack || e), 'no error']);
}
console.log(JSON.stringify(out));
process.exit(0);
