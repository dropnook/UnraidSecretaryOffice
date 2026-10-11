/*
 * The filter and the tiles every desk shares (public/assets/core.js, under node): the pure matcher Office.fold /
 * Office.filterWords / Office.fits, and — on a small stand-in DOM — Office.filterBar (shown from Office.FILTER_MIN rows or
 * while anything filters, «n of m fit», the note line of a tile filter with its chip and «Show all», the select kept per
 * browser and words / «only …» never, the switch gone when there is nothing to switch to, the focus kept through a
 * drawing), Office.stat and Office.tile (button or div, aria-pressed, aria-controls, data-filters, no inline handlers).
 *
 *   node tests/filter.js public/assets/core.js [words|bar]     → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testFilterWords (words) and testFilterBar (bar) run it on the server; on the Mac it runs as it is.
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

// ------------------------------------------------------------------ a stand-in DOM (just what core.js and the helpers use)
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
    this.checked = false;
    this.type = '';
    this.val = '';
    this.offsetHeight = 0;
    this.offsetWidth = 0;
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
  get value() {
    if (this.tagName !== 'SELECT') return this.val;
    return this.children.some((o) => o.val === this.val) ? this.val : '';
  }
  set value(v) { this.val = String(v); if (this.tagName === 'INPUT') { this.selectionStart = this.selectionEnd = this.val.length; } }
  get title() { return this.attrs.title || ''; }
  set title(v) { this.attrs.title = String(v); }
  get id() { return this.attrs.id || ''; }
  set id(v) { this.attrs.id = String(v); }
  get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children.forEach((c) => { c.parentNode = null; }); this.children = []; this.text = v === null || v === undefined ? '' : String(v); }
  get lastChild() { return this.children[this.children.length - 1] || null; }
  get nodeType() { return 1; }
  get isConnected() { let n = this; while (n.parentNode) n = n.parentNode; return n === doc.body; }
  appendChild(c) {
    if (typeof c === 'string') c = textNode(c);
    if (c.parentNode) c.parentNode.removeChild(c);
    c.parentNode = this;
    this.children.push(c);
    return c;
  }
  append(...cs) { cs.forEach((c) => this.appendChild(c)); }
  prepend(...cs) { cs.reverse().forEach((c) => { if (typeof c === 'string') c = textNode(c); c.parentNode = this; this.children.unshift(c); }); }
  replaceChildren(...cs) { [...this.children].forEach((c) => this.removeChild(c)); this.text = ''; this.append(...cs); }
  removeChild(c) {
    this.children = this.children.filter((x) => x !== c);
    c.parentNode = null;
    if (doc.activeElement && c.contains(doc.activeElement)) doc.activeElement = doc.body;     // a browser's focus fixup
    return c;
  }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  hasAttribute(k) { return k in this.attrs; }
  removeAttribute(k) { delete this.attrs[k]; }
  addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); }
  removeEventListener() {}
  dispatch(type, bubbles) {
    const ev = { type, target: this, preventDefault() {}, stopPropagation() {} };
    for (let n = this; n; n = bubbles ? n.parentNode : null) (n.listeners[type] || []).forEach((fn) => fn.call(n, ev));
  }
  click() { this.dispatch('click', true); }
  focus() {
    const was = doc.activeElement;
    if (was === this) return;
    if (was && was !== doc.body && was.isConnected) was.dispatch('focusout', true);
    doc.activeElement = this;
    this.dispatch('focusin', true);
  }
  blur() { if (doc.activeElement === this) { doc.activeElement = doc.body; this.dispatch('focusout', true); } }
  setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; }
  contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
  closest() { return null; }
  matches() { return false; }
  querySelector() { return null; }
  querySelectorAll() { return []; }
  getBoundingClientRect() { return { top: 0, bottom: 0, left: 0, right: 0, width: 0, height: 0 }; }
  all() { return this.children.flatMap((c) => (c.tagName === '#TEXT' ? [] : [c, ...c.all()])); }
  find(cls) { return this.all().find((c) => c.classList.contains(cls)) || null; }
}
const textNode = (s) => { const n = new FakeNode('#text'); n.text = String(s); n.all = () => []; return n; };
const desk = new FakeNode('div');
doc = {
  body: new FakeNode('body'),
  activeElement: null,
  getElementById: (id) => (id === 'sso-config' ? { textContent: JSON.stringify({ desks: [], languages: [{ code: 'en' }], base: '', staff_order: [] }) } : new FakeNode('div')),
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
globalThis.scrollBy = () => {}; globalThis.scrollTo = () => {};
const stored = {};
const writes = [];
// node ≥ 21 has a navigator (and may have a localStorage) of its own, read-only: laid over
const def = (name, value) => Object.defineProperty(globalThis, name, { value, configurable: true, writable: true });
def('localStorage', {
  getItem: (k) => (k in stored ? stored[k] : null),
  setItem: (k, v) => { stored[k] = String(v); writes.push(k); },
  removeItem: (k) => { delete stored[k]; writes.push(k); },
});
def('navigator', { languages: ['en'] });
globalThis.history = { replaceState() {} };
globalThis.location = { hash: '', reload() {} };
globalThis.Option = function Option(text, value) { const o = new FakeNode('option'); o.textContent = text; o.val = value === undefined ? String(text) : String(value); return o; };

(0, eval)(fs.readFileSync(file, 'utf8'));
const O = globalThis.Office;
O.strings = JSON.parse(fs.readFileSync(path.join(path.dirname(file), '..', 'lang', 'en.json'), 'utf8'));

// ------------------------------------------------------------------ the matcher
function words() {
  same('fold: lower case, accents folded', O.fold('Éléphant MÜLLER Ñandú çà'), 'elephant muller nandu ca');
  same('fold: ß → ss (also the capital ẞ)', [O.fold('Straße'), O.fold('STRAẞE')], ['strasse', 'strasse']);
  same('fold: Ł is no accent — it stays (lower case)', O.fold('Łódź'), 'łodz');
  same('fold: null / undefined / numbers', [O.fold(null), O.fold(undefined), O.fold(42)], ['', '', '42']);
  same('filterWords: folded, split on white space', O.filterWords('Müller  Fotos'), ['muller', 'fotos']);
  same('filterWords: empty and only spaces → none', [O.filterWords(''), O.filterWords('   '), O.filterWords(null), O.filterWords(undefined)], [[], [], [], []]);
  same('filterWords: tabs, new lines, leading and trailing spaces', O.filterWords(' a\tB\n  c  '), ['a', 'b', 'c']);
  same('fits: no words fit everything', [O.fits([], ''), O.fits([], 'x'), O.fits(null, null), O.fits([], [])], [true, true, true, true]);
  same('fits: every word must be in it, in any order', [O.fits(['fotos', 'mull'], 'Müller Fotos'), O.fits(['fotos', 'x'], 'Müller Fotos')], [true, false]);
  same('fits: typed with or without accents', [O.fits(O.filterWords('cafe'), 'Café'), O.fits(O.filterWords('Café'), 'cafe'), O.fits(O.filterWords('strasse'), 'Hauptstraße')], [true, true, true]);
  same('fits: a word inside a word', O.fits(['ott'], 'Lottery'), true);
  same('fits: a list of strings', [O.fits(['muller', 'fotos'], ['Müller', 'Fotos']), O.fits(['fotos'], ['Müller', null, undefined, '', 'Fotos'])], [true, true]);
  same('fits: a word across two strings of the list does NOT fit (the \\u0001 joiner)', [O.fits(['bc'], ['ab', 'cd']), O.fits(['b c'], ['ab', 'cd']), O.fits(['bc'], 'ab cd')], [false, false, false]);
  same('fits: numbers in the list', O.fits(['42'], ['x', 42]), true);
  same('fits: the hay null', O.fits(['a'], null), false);
}

// ------------------------------------------------------------------ the bar and the tiles
async function bar() {
  const tick = () => new Promise((r) => setTimeout(r, 0));
  const anchors = [];
  const keep = O.keepInPlace;
  O.keepInPlace = (anchor, change) => { anchors.push(anchor); keep(anchor, change); };
  same('FILTER_MIN: one constant, 10', O.FILTER_MIN, 10);
  const calls = [];
  const cleared = [];
  const extra = O.el('button', 'btn small plain', 'Unfold all');
  const mk = (more) => O.filterBar({ key: 'test.list', label: 'Filter the list', only: { text: 'only open', hint: 'what is still open' },
    select: { label: 'Area', all: 'Every area' }, extra: [extra], onChange: (f, what) => calls.push([what, f]), ...more });
  const b = mk();
  const box = b.el;
  const input = box.find('filter-words');
  const sel = box.find('filter-pick');
  const sw = box.find('filter-only');
  const cb = sw.children[0];
  const hits = box.find('filter-hits');
  const note = box.find('filter-note');
  const chip = box.find('filter-chip');
  const all = box.find('filter-all');
  same('bar: the toolbar, built once (class filterbar)', [box.tagName, box.className], ['DIV', 'toolbar filterbar']);
  same('bar: the field — placeholder common.filter, its label, data-keep, a search field', [input.placeholder, input.getAttribute('aria-label'), input.dataset.keep, input.type],
    [O.strings['common.filter'], 'Filter the list', '1', 'search']);
  same('bar: the parts in order — words, select, «only …», extras, hits, the note line', box.children.map((c) => c.className.split(' ').pop()),
    ['filter-words', 'filter-pick', 'filter-only', 'plain', 'filter-hits', 'filter-note']);
  same('bar: the switch\'s hint, the select\'s label, the hits aria-live', [sw.title, sel.getAttribute('aria-label'), hits.getAttribute('aria-live')], ['what is still open', 'Area', 'polite']);
  same('bar: nothing filters at first', b.read(), { words: [], only: false, pick: '', tile: '' });
  same('bar: no inline handlers', [input.oninput, cb.onchange, sel.onchange, chip.onclick, all.onclick].map((x) => x === undefined || x === null), [true, true, true, true, true]);

  b.done(3, 3);
  same('done: below the threshold, nothing filtering — the filter\'s own controls gone, the extras stay (and the bar with them)',
    [box.hidden, input.hidden, sel.hidden, sw.hidden, hits.textContent, note.hidden], [false, true, true, true, '', true]);
  const bare = mk({ extra: [] });
  bare.done(3, 3);
  same('done: below the threshold without extras — the whole bar hidden', bare.el.hidden, true);
  b.done(10, 10);
  same('done: from FILTER_MIN rows on — shown, no hits text', [box.hidden, input.hidden, sel.hidden, sw.hidden, hits.textContent], [false, false, false, false, '']);
  const small = mk({ min: 3 });
  small.done(3, 3);
  same('done: min overrides the threshold', small.el.hidden, false);

  writes.length = 0;
  input.value = 'Müller  Fotos';
  input.dispatch('input', true);
  same('words: onChange with the folded words, inside keepInPlace(the field)', [calls.length, calls[0] && calls[0][0], calls[0] && calls[0][1].words, anchors[anchors.length - 1] === input],
    [1, 'words', ['muller', 'fotos'], true]);
  b.done(2, 3);
  same('done: a short list filtered keeps its bar, «n of m fit»', [box.hidden, hits.textContent], [false, '2 of 3 fit']);
  cb.checked = true;
  cb.dispatch('change', true);
  same('only: onChange inside keepInPlace(the switch)', [calls[1][0], calls[1][1].only, anchors[anchors.length - 1] === sw], ['only', true, true]);
  same('words and «only …» are never stored', writes, []);

  b.options([['net', 'Network', 3], ['login', 'Logins', 1], ['x', 'No count']]);
  same('options: «all» first, counts in brackets', sel.children.map((o) => [o.val, o.textContent]), [['', 'Every area'], ['net', 'Network (3)'], ['login', 'Logins (1)'], ['x', 'No count']]);
  sel.value = 'login';
  sel.dispatch('change', true);
  same('select: onChange inside keepInPlace(the select), stored as <key>.pick', [calls[2][0], calls[2][1].pick, anchors[anchors.length - 1] === sel, stored['office.test.list.pick']],
    ['pick', 'login', true, 'login']);
  const again = mk();
  same('select: a new visit has the stored pick, words and switch empty', again.read(), { words: [], only: false, pick: 'login', tile: '' });
  again.options([['net', 'Network', 3], ['login', 'Logins', 0]]);
  same('options: the stored pick kept while its option is there', [again.read().pick, again.el.find('filter-pick').value], ['login', 'login']);
  const before = calls.length;
  again.options([['net', 'Network', 3]]);
  await tick();
  same('options: its option gone — «all», forgotten, drawn anew (gone)', [again.read().pick, stored['office.test.list.pick'], calls.slice(before).map((c) => c[0])],
    ['', undefined, ['gone']]);
  sel.value = 'login';
  sel.dispatch('change', true);        // the first bar picks again (stored again)

  // the «only …» switch with nothing to switch to
  const n0 = calls.length;
  b.done(0, 3, { onlyAvail: false });
  await tick();
  same('onlyAvail false: the switch hidden and off, drawn anew once', [sw.hidden, cb.checked, b.read().only, calls.slice(n0).map((c) => c[0])], [true, false, false, ['gone']]);
  b.done(0, 3, { onlyAvail: false });
  await tick();
  same('onlyAvail false again: nothing more', calls.length, n0 + 1);
  b.done(2, 3);
  same('onlyAvail left out: the switch back', sw.hidden, false);

  // a tile filters
  b.tile('VMs');
  b.done(1, 12, { clear: (what) => cleared.push(what) });
  same('tile: the note line — «The list shows only:», the chip, the count; the bar\'s own hits empty',
    [note.hidden, note.children[0].textContent, chip.find('filter-chip-name').textContent, chip.getAttribute('aria-label'), box.find('filter-note-hits').textContent, hits.textContent, all.textContent, box.hidden],
    [false, 'The list shows only:', 'VMs', 'Remove the filter VMs', '· 1 of 12 fit', '', 'Show all', false]);
  same('tile: read() names it', b.read().tile, 'VMs');
  same('tile: the chip and «Show all» are buttons', [chip.tagName, chip.type, all.tagName, all.type], ['BUTTON', 'button', 'BUTTON', 'button']);
  const n1 = calls.length;
  chip.click();
  same('chip: clears the tile — clear() first, then onChange(tile)', [cleared, b.read().tile, calls.slice(n1).map((c) => c[0]), b.read().words.length > 0, b.read().pick], [['tile'], '', ['tile'], true, 'login']);
  b.done(1, 12);
  same('chip: the note line gone, the words still filter', [note.hidden, hits.textContent], [true, '1 of 12 fit']);
  b.done(5, 5, { tile: 'Shares' });
  same('done({tile}): sets the tile filter too', [note.hidden, b.read().tile], [false, 'Shares']);
  cb.checked = true;
  const n2 = calls.length;
  all.click();
  same('«Show all»: words, «only …» and the tile cleared, the select stays (a choice, not a search)', [b.read(), input.value, cleared.slice(-1)[0], calls.slice(n2).map((c) => c[0])],
    [{ words: [], only: false, pick: 'login', tile: '' }, '', 'all', ['all']]);
  b.done(5, 5);
  same('«Show all»: the pick still filters — the bar stays, «n of m fit»', [box.hidden, hits.textContent], [false, '5 of 5 fit']);

  // reset (a new visit), set
  input.value = 'abc';
  cb.checked = true;
  b.tile('VMs');
  b.reset();
  same('reset: words, «only …» and the tile gone, the select stays', b.read(), { words: [], only: false, pick: 'login', tile: '' });
  const n3 = calls.length;
  b.set({ text: 'Café', only: true, pick: 'net' });
  same('set: words, switch and pick from the desk — no onChange, the pick stored', [b.read(), calls.length - n3, stored['office.test.list.pick']],
    [{ words: ['cafe'], only: true, pick: 'net', tile: '' }, 0, 'net']);
  b.set({ pick: '' });
  same('set: the pick back to all forgets it', stored['office.test.list.pick'], undefined);

  // the focus through a drawing: the desk empties its root and appends the bar again
  desk.appendChild(box);
  input.value = 'hello';
  input.focus();
  input.setSelectionRange(2, 4);
  input.dispatch('keyup', true);
  desk.replaceChildren();               // innerHTML = '' — the focus falls to the body
  same('focus: lost while the bar is out', doc.activeElement === doc.body, true);
  desk.appendChild(box);
  b.done(1, 3);
  same('focus: back on the field after done(), the selection too', [doc.activeElement === input, input.selectionStart, input.selectionEnd], [true, 2, 4]);
  // a browser that says focusout when the field is taken out (and one that doesn't, above): back all the same
  input.dispatch('focusout', true);
  desk.replaceChildren();
  desk.appendChild(box);
  b.done(1, 3);
  await tick();
  same('focus: back after done() also when the removal said focusout — and it stays after the check', [doc.activeElement === input], [true]);
  desk.replaceChildren();
  desk.appendChild(box);
  b.done(1, 3);
  same('focus: … and again at the next drawing', doc.activeElement === input, true);
  // the desk builds its section detached and appends it after done() (the watchman's bookSection())
  desk.replaceChildren();
  const section = O.el('section');
  section.appendChild(box);
  b.done(1, 3);
  same('focus: the bar still detached at done() — not yet', doc.activeElement === input, false);
  desk.appendChild(section);
  await Promise.resolve();
  same('focus: … back once the desk\'s drawing is through (a microtask later)', doc.activeElement === input, true);
  input.blur();
  const other = O.el('input');
  desk.appendChild(other);
  other.focus();
  await tick();
  desk.replaceChildren();
  desk.appendChild(box);
  desk.appendChild(other);
  other.focus();
  b.done(1, 3);
  same('focus: never taken from elsewhere', doc.activeElement === other, true);
  O.keepInPlace = keep;

  // the tiles
  let hit = 0;
  const st = O.stat({ label: 'VMs', value: 12, sub: ['3 off', 'all local'], alert: true });
  same('stat: no on → div.stat, no aria-pressed', [st.tagName, st.className, st.hasAttribute('aria-pressed'), st.children.map((c) => [c.className, c.textContent])],
    ['DIV', 'stat alert', false, [['stat-label', 'VMs'], ['stat-value', '12'], ['stat-sub', '3 off'], ['stat-sub', 'all local']]]);
  const sp = O.stat({ label: 'VMs', value: 0, sub: '', on: () => hit++, pressed: true, controls: 'bk-prot', filters: 'bk-prot', tip: 'the whole text', id: 'bk-vms', place: 'stat.vms' });
  same('stat: on + pressed → button.stat[aria-pressed], type button, controls, data-filters, title, id, place',
    [sp.tagName, sp.type, sp.getAttribute('aria-pressed'), sp.getAttribute('aria-controls'), sp.dataset.filters, sp.title, sp.id, sp.dataset.place, sp.children.length],
    ['BUTTON', 'button', 'true', 'bk-prot', 'bk-prot', 'the whole text', 'bk-vms', 'stat.vms', 2]);
  sp.click();
  same('stat: the click through addEventListener, no inline handler', [hit, sp.onclick === undefined || sp.onclick === null], [1, true]);
  same('stat: pressed false → aria-pressed false', O.stat({ label: 'x', value: 1, on: () => {}, pressed: false }).getAttribute('aria-pressed'), 'false');
  same('stat: on without pressed (opens a dialog) → no aria-pressed', O.stat({ label: 'x', value: 1, on: () => {} }).hasAttribute('aria-pressed'), false);
  const icon = O.el('span', 'rs-tile-icon', '📦');
  const tl = O.tile({ name: 'Apps', icon, chip: 'look', count: 1234, lines: [['12 apps', '3 missing'], 'one line', O.el('div', 'bar')], active: true, on: () => hit++, controls: 'rs-body', filters: 'rs-list', place: 'tile.apps' });
  same('tile: active → button.card.active[aria-pressed=true], type button',
    [tl.tagName, tl.className, tl.getAttribute('aria-pressed'), tl.type, tl.getAttribute('aria-controls'), tl.dataset.filters, tl.dataset.place], ['BUTTON', 'card active', 'true', 'button', 'rs-body', 'rs-list', 'tile.apps']);
  same('tile: the head — icon, name, chip, count', tl.children[0].children.map((c) => [c.className, c.textContent]),
    [['rs-tile-icon', '📦'], ['card-name', 'Apps'], ['chip card-chip', 'look'], ['card-count', (1234).toLocaleString('en-GB')]]);
  same('tile: lines — [left, right] → card-figures, a string → card-line, a node as it is', tl.children.slice(1).map((c) => [c.className, c.children.map((x) => x.textContent)]),
    [['card-figures', ['12 apps', '3 missing']], ['card-line', ['one line']], ['bar', []]]);
  tl.click();
  same('tile: the click through addEventListener', [hit, tl.onclick === undefined || tl.onclick === null], [2, true]);
  const off = O.tile({ name: 'VMs', icon: '🖥', lines: [], on: () => {} });
  same('tile: not active → aria-pressed false; an icon as text → span.card-icon', [off.className, off.getAttribute('aria-pressed'), off.children[0].children[0].className], ['card', 'false', 'card-icon']);
  same('tile: no on → div.card, no aria-pressed', [O.tile({ name: 'Array' }).tagName, O.tile({ name: 'Array' }).hasAttribute('aria-pressed')], ['DIV', false]);
}

(async () => {
  try {
    if (part === 'all' || part === 'words') words();
    if (part === 'all' || part === 'bar') await bar();
  } catch (e) {
    out.fail.push(['ran through', String(e && e.stack || e), 'no error']);
  }
  console.log(JSON.stringify(out));
  process.exit(0);
})();
