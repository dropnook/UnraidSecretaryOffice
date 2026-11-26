/*
 * The Night Watchman's «Who woke my disks?» (public/desks/watchman/desk.js with core.js, under node, on a small stand-in
 * DOM; the agent stubbed with the click test's state tests/ui/states/watchman.json): his tile (a light since 1.96, its
 * words in its mouse-over text) and its section —
 * on a server without a spinning disk (all flash) the tile says so and the section is that one line (no switch, no
 * filter bar, no empty list); before his first look «not watching yet»; a spinning disk without a wake: «none», the
 * switch and the bar; a wake kept from before still counts; an older messenger's state (no `hdds`) reads as always; the
 * stub's wakes: the count in 7 days and today's, a disk's row filters the list and the note line says so. (Ms.
 * Protocolli's up to 1.95 — tests/logsfilters.js held these rules then.) And since 1.96 his exceptions on the page: the
 * offer on an open login, its dialog, the area's list.
 *
 *   node tests/watchwakes.js public/assets/core.js public/desks/watchman/desk.js [tests/ui/states]   → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testWatchWakesPage runs it on the server; on the Mac it runs as it is.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const coreFile = process.argv[2] || path.join(__dirname, '../public/assets/core.js');
const deskFile = process.argv[3] || path.join(__dirname, '../public/desks/watchman/desk.js');
const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};


// ------------------------------------------------------------------ a stand-in DOM (what core.js and his desk use)
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
  // selectors: '.a', '.a.b', 'tag', 'tag.a', '[data-x]', '[data-x="v"]' — enough for core.js and his desk
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
  getElementById: (id) => (id === 'sso-config' ? { textContent: JSON.stringify({ desks: [{ id: 'watchman', order: 1, hired: true, icon: '•' }],
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
for (const [k, v] of Object.entries(JSON.parse(fs.readFileSync(path.join(web, 'desks', 'watchman', 'lang', 'en.json'), 'utf8')))) O.strings['watchman.' + k] = v;
O.current = { id: 'watchman' };
O.agent = { running: true };


// ------------------------------------------------------------------ the agent, stubbed: the click test's state
const states = process.argv[4] || path.join(web, '..', 'tests', 'ui', 'states');
const base = JSON.parse(fs.readFileSync(path.join(states, 'watchman.json'), 'utf8'));
let state = null;
O.loadState = async (id, o, took) => { took({ ok: true, state }); };
O.freshState = async () => true;
const posts = [];
O.api = { get: async () => ({ ok: true }), post: async (a, d) => { posts.push([a, d]); return { ok: false, error: { key: 'agent_away' } }; } };
const dialogs = [];
O.dialog = (d) => { dialogs.push(d); return { buttons: [], close() {} }; };
O.menu = () => {};
O.toast = () => {};
(0, eval)(fs.readFileSync(deskFile, 'utf8'));
const wm = O.desks.get('watchman');

// ------------------------------------------------------------------ helpers
const tick = () => new Promise((ok) => setTimeout(ok, 0));
const tx = (n) => (n ? n.textContent : null);
const T = (k, p) => O.t('watchman.' + k, p);
function sectionOf(root, place) {
  const head = root.all().find((n) => n.dataset.place === place);
  return head ? head.closest('section') : null;
}
const barIn = (s) => (s ? s.querySelector('.filterbar') : null);

/**
 * His tile is a light since 1.96 (core.js Office.light): what the number tile said is its mouse-over text — «Who woke my
 * disks?: 17 wakes», then its lines. Read back as the number tile's value ('–' when the head has none) and lines.
 */
function tileWords(tile) {
  const label = T('wakes.title');
  const [head, ...lines] = String(tile.dataset.tip || '').split('\n');
  return [head.startsWith(label + ': ') ? head.slice(label.length + 2) : '–', ...lines];
}

/** His page with this `wakes` in his state (undefined: none — before his first look), at this sub-route */
async function visit(wakes, sub, then) {
  state = JSON.parse(JSON.stringify(base));
  if (wakes === undefined) delete state.wakes; else state.wakes = wakes;
  const r = new FakeNode('div');
  desk.replaceChildren(r);
  await wm.mount(r, sub);
  await tick();
  await tick();
  if (then) await then(r);
  const tile = r.all().find((n) => n.dataset && n.dataset.tile === 'wakes') || null;
  const sec = sectionOf(r, 'wakes.title');
  const res = {
    tile: tile ? tileWords(tile) : null,
    section: sec ? sec.querySelectorAll('p').map(tx) : null,
    bar: !!barIn(sec),
    switch: !!(sec && sec.querySelector('.switch')),
    boxes: sec ? sec.querySelectorAll('.box').length : -1,
    rows: sec ? sec.querySelectorAll('.wm-wake').length : -1,
    note: sec && barIn(sec) && barIn(sec).querySelector('.filter-note') && !barIn(sec).querySelector('.filter-note').hidden
      ? tx(barIn(sec).querySelector('.filter-note')) : '',
  };
  wm.unmount();
  return res;
}

async function run() {
  const now = Number(base.time);
  const flash = { hdds: [], since: now - 86400, look: 4, on: true, wakes: [] };
  same('all flash: his tile there, no number — no disk spins here', (await visit(flash, '')).tile, ['–', T('tile.wakes_no_hdd')]);
  same('all flash: his section alone (a click) — one line, no switch, no filter bar, no empty list', await visit(flash, 'wakes'),
    { tile: ['–', T('tile.wakes_no_hdd')], section: [T('wakes.no_hdd')], bar: false, switch: false, boxes: 0, rows: 0, note: '' });
  same('all flash, switched off: still the one line (nothing to switch)', (await visit({ ...flash, on: false }, 'wakes')).section, [T('wakes.no_hdd')]);
  same('before his first look: «not watching yet»', await visit(undefined, 'wakes'),
    { tile: ['–', T('tile.wakes_not_yet')], section: [T('wakes.not_yet')], bar: false, switch: false, boxes: 0, rows: 0, note: '' });
  const spinning = await visit({ ...flash, hdds: ['parity', 'disk1'] }, 'wakes');
  same('a spinning disk, no wake yet: «none», since when — the switch and the filter bar', [spinning.tile, spinning.bar, spinning.switch],
    [[T('tile.wakes_none'), T('wakes.tile_since', { when: O.fmt.date(now - 86400) })], true, true]);
  const kept = await visit({ ...flash, wakes: [{ id: 'w1', time: now - 60, from: now - 160, disks: ['disk1'], how: 'noticed', who: [] }] }, 'wakes');
  same('no spinning disk now, but a wake kept from before: counted as always', [kept.tile, kept.bar, kept.rows],
    [[T('tile.wakes_n', { n: 1 }), [T('tile.wakes_week'), T('tile.wakes_today', { n: 1 })].join(' · ')], true, 1]);
  same('an older messenger\'s state (no `hdds`): as it always was', (await visit({ since: now - 86400, look: 4, wakes: [] }, 'wakes')).tile,
    [T('tile.wakes_none'), T('wakes.tile_since', { when: O.fmt.date(now - 86400) })]);
  same('switched off: the tile says so, the section too', [(await visit({ ...base.wakes, on: false }, 'wakes')).tile[0], (await visit({ ...base.wakes, on: false }, 'wakes')).section[0]],
    [T('wakes.tile_off'), T('wakes.off_note')]);

  // the stub's wakes: every one in the list, a disk's row shows only its wakes (the note line says so)
  const all = base.wakes.wakes;
  const full = await visit(base.wakes, 'wakes');
  same('the stub: his tile counts the wakes in 7 days', full.tile[0], T('tile.wakes_n', { n: all.length }));
  same('the stub: every wake a row, the switch there', [full.rows, full.switch], [all.length, true]);
  // a disk's row unfolds its wakes right beneath it (2026-11-23, like Mr. Backupsy's rows); a second click folds them; the
  // long list «Every wake» stays whole (no disk filter); «unfold all» unfolds every disk
  const disk = 'disk2';
  const mine = all.filter((w) => w.disks.includes(disk));
  let folds = [];
  const once = await visit(base.wakes, 'wakes', async (r) => {
    const row = sectionOf(r, 'wakes.title').querySelectorAll('.wm-wake-disk').find((x) => tx(x.querySelector('.row-name')) === disk);
    row.click();
    await tick();
    const f = row.parentNode.querySelector('.wm-wake-disk-fold');
    folds.push(f ? f.querySelectorAll('.wm-wake').length : -1, row.getAttribute('aria-expanded'));
    row.click();
    await tick();
    folds.push(row.parentNode.querySelector('.wm-wake-disk-fold') ? 'still' : 'folded');
  });
  same('a disk\'s row: its wakes beneath it (at most 10, newest first), a second click folds them; the long list whole, no filter note',
    [folds, once.rows, once.note], [[Math.min(mine.length, 10), 'true', 'folded'], all.length, '']);
  let unfolded = -1;
  await visit(base.wakes, 'wakes', async (r) => {
    const sec = sectionOf(r, 'wakes.title');
    const btn = sec.querySelectorAll('button').find((b) => tx(b) === T('unfold_all'));
    if (btn) btn.click();
    await tick();
    unfolded = sec.querySelectorAll('.wm-wake-disk-fold').length;
  });
  same('«unfold all» unfolds every disk of «By disk»', unfolded, new Set(all.flatMap((w) => w.disks)).size);
}

// ------------------------------------------------------------------ exceptions on his page (1.96 step 4)
async function rules() {
  state = JSON.parse(JSON.stringify(base));
  const r = new FakeNode('div');
  desk.replaceChildren(r);
  await wm.mount(r, 'login');
  await tick();
  await tick();
  const sec = sectionOf(r, 'help.lights') || r;
  const offer = r.all().find((n) => n.classList && n.classList.contains('wm-offer'));
  const first = state.book.find((e) => e.open && e.cat === 'login');
  // 1.96 «einfacher»: one button «Something like this is always all right …», its choice in whole sentences (the narrowest first)
  same('exceptions: an open login has one button «… always all right …», no «more ▾»',
    [offer && tx(offer), !!(offer && offer.parentNode.children.some((n) => tx(n) === T('rule.more')))], [T('rule.always'), false]);
  offer.click();
  await tick();
  const d = dialogs[dialogs.length - 1];
  const picks = d ? d.body.querySelectorAll('.wm-offer-pick').map(tx) : [];
  same('exceptions: the choice — every offer a sentence, the narrowest first; what it covers, what a net never does, where it is lifted; the confirm names it; nothing sent yet',
    [d && d.title, picks[0], picks.length, d && d.body.querySelectorAll('p').map(tx).filter((x) => x === T('rule.ask_where')).length,
      d && d.buttons[0].text, posts.filter((p) => p[0] === 'watchman.rule_add').length],
    [T('rule.always'), T('rule.offer.user_net', { user: first.offers[0].on.user, net: first.offers[0].on.net }), first.offers.length, 1,
      T('rule.confirm', { what: T('rule.offer.user_net', { user: first.offers[0].on.user, net: first.offers[0].on.net }) }), 0]);
  // 1.96 «einfacher»: each thing in one place — the watch page only jumps there; the lists stand in his setup
  const jump = r.all().find((n) => n.classList && n.classList.contains('wm-jump') && n.tagName === 'A');
  same('the tile jumps to its setup (what is always in order, what you had him remember) — no lists on the watch page',
    [!!jump && String(jump.href || jump.getAttribute('href')).endsWith('/setup/login'), !!r.all().find((n) => n.classList && n.classList.contains('wm-rules'))], [true, false]);
  wm.unmount();
  desk.replaceChildren(r);
  await wm.mount(r, 'setup/login');
  await tick();
  await tick();
  const list = r.all().find((n) => n.classList && n.classList.contains('wm-rules'));
  same('exceptions: the area lists its exceptions — what, since when, how often, «Lift»',
    [list && tx(list.querySelector('.row-name')), !!(list && list.querySelectorAll('button').some((b) => tx(b) === T('rule.lift')))],
    [T('rule.offer.net', { net: '192.168.178.0/24' }), true]);
  // 4b: your lasting answers in the area, folded; unfolded each with «Take back»
  const head = r.all().find((n) => n.classList && n.classList.contains('wm-known-head'));
  same('answers: «Noted (n)» in the area, folded', [head && tx(head).includes(T('known.list', { n: 1 })), head && head.getAttribute('aria-expanded')], [true, 'false']);
  head.click();
  await tick();
  const known = r.all().find((n) => n.classList && n.classList.contains('wm-known'));
  same('answers: unfolded — the address, known since, «Take back»', [!!(known && known.querySelectorAll('.row-name').length === 1),
    !!(known && known.querySelectorAll('button').some((b) => tx(b) === T('known.take_back')))], [true, true]);
  void sec;
  wm.unmount();
}

// ------------------------------------------------------------------ «Add something that is always all right…» (1.96)
/*
 * The dialog by hand: the newer container types listed; the names he knows suggested (a datalist, the placeholder its
 * first name); a wide one's note; a typo in a full list gets its calm line; SMB or NFS a choice, sent as the agent takes it.
 */
async function handDialog() {
  state = JSON.parse(JSON.stringify(base));
  const open = async (sub) => {
    const r = new FakeNode('div');
    desk.replaceChildren(r);
    await wm.mount(r, `setup/${sub}`);
    await tick();
    await tick();
    const btn = r.all().find((n) => n.tagName === 'BUTTON' && tx(n) === T('setup.add'));
    if (btn) btn.click();
    return dialogs[dialogs.length - 1];
  };
  const pick = (sel, v) => { sel.value = v; sel.dispatch('change'); };
  let d = await open('container');
  const [type] = d.body.querySelectorAll('select');
  const [value] = d.body.querySelectorAll('input');
  pick(type, 'container|new');
  const names = d.body.querySelectorAll('datalist').find((x) => x.id === value.getAttribute('list'));
  const shown = (c) => d.body.querySelectorAll(c).filter((x) => !x.hidden).map(tx);
  same('hand dialog: the container types; «a new container of this name» — its names suggested, the first as the example, its note',
    [['container|privileged', 'container|host', 'container|new'].every((x) => type.options.some((o) => o.val === x)), value.placeholder, names && names.children.length,
      shown('p.callout.wm-hand-note')],
    [true, 'EmbyServer', 9, [T('setup.add_wide.new')]]);
  value.value = 'Grafanna';
  value.dispatch('input');
  const notYet = shown('p.role.wm-hand-note');       // «may appear»: it isn't there yet — no line
  pick(type, 'container|privileged');
  value.value = 'Grafanna';
  value.dispatch('input');
  const typo = shown('p.role.wm-hand-note');
  value.value = 'graf';
  value.dispatch('input');
  const typing = shown('p.role.wm-hand-note');      // still the start of a name he knows — no line
  value.value = 'grafana';
  value.dispatch('input');
  const cased = shown('p.role.wm-hand-note');       // another case is another name: the line
  value.value = 'Grafana';
  value.dispatch('input');
  same('hand dialog: a name he doesn\'t know — a calm line (not for «may appear», not while still typing a known one); one he knows — none',
    [notYet, typo, typing, cased, shown('p.role.wm-hand-note')], [[], [T('setup.add_unknown')], [], [T('setup.add_unknown')], []]);
  wm.unmount();
  d = await open('access');
  const [t2, proto] = d.body.querySelectorAll('select');
  pick(t2, 'share|guest_write');
  pick(proto, 'nfs');
  const [v2] = d.body.querySelectorAll('input');
  v2.value = 'media';
  const n = posts.length;
  d.buttons[0].act();
  await tick();
  const sent = posts.slice(n).find((x) => x[0] === 'watchman.rule_hand');
  same('hand dialog: guests writing — SMB or NFS a choice, its value sent as the second field', [proto.options.map((o) => [o.val, o.text]), sent && sent[1]],
    [[['smb', 'SMB'], ['nfs', 'NFS']], { cat: 'share', type: 'guest_write', value: 'media', user: 'nfs' }]);
  wm.unmount();
}

// ------------------------------------------------------------------ «Known vulnerabilities»: what he looked at, one filter over all
/*
 * 2026-11-23 (nostromo: «php2shell» → «0 of 39»): the area says how many of the list's entries concern programs here, by
 * product; its filter searches every list — what waits, «checked: doesn't concern you», «can't be checked for sure» —
 * by CVE, product, name, where; a list holding hits stands open while the words are typed.
 */
async function vulnPage() {
  state = JSON.parse(JSON.stringify(base));
  const r = new FakeNode('div');
  desk.replaceChildren(r);
  await wm.mount(r, 'vuln');
  await tick();
  await tick();
  const sec = sectionOf(r, 'area.vuln');
  const counted = sec && sec.querySelector('.wm-vuln-counted');
  same('vulnerabilities: how many of the list concern programs here, by product',
    counted && tx(counted), T('vuln.counted', { n: 47, total: O.fmt.number(1484), list: 'Linux kernel 39 · PHP 5 · Bash 3' }));
  // a source away for days (2026-11-23): a calm line in the area and in the help's «Known vulnerabilities», never a colour
  const downs = r.all().filter((n) => n.classList && n.classList.contains('wm-vuln-down')).map(tx);
  same('vulnerabilities: the NVD away for 3 days — one calm line in the area, the same in the help; the area stays green',
    [downs.length, downs[0], downs[0] === downs[1], tileWords(r.all().find((n) => n.dataset && n.dataset.tile === 'vuln'))[0] !== undefined],
    // (the fixture's vuln.unsure holds four rows — the kernel, Bash, the old PHP and DSM's build; the projects' advisories'
    // unsure row is no NVD matter and isn't counted here)
    [2, [T('vuln.down', { source: T('vuln.source.nvd'), n: 3, when: O.fmt.date(state.vuln.down[0].next) }), T('vuln.down_nvd', { n: 4 })].join(' '), true, true]);
  // his limits (2026-11-25): one calm paragraph, always there while the area is on
  same('vulnerabilities: the paragraph of his limits stands in the area', tx(sec.querySelector('.wm-vuln-limits')), T('vuln.limits'));
  // «What he checked» (2026-11-25): folded; opened — this server, the containers (the fixture's ct_read 4 of ct_total 7:
  // webapp, notes-db, nas, media; the others by reason), the
  // routers by their log (no longer above the lists), and what he never looks at, the 2 VMs counted
  same('vulnerabilities: no router line above the lists any more (they stand under «What he checked»)', sec.querySelectorAll('.wm-vuln-router').length, 0);
  const lookHead = () => sectionOf(r, 'area.vuln').querySelectorAll('.wm-known-head').find((b) => tx(b).includes(T('vuln.look_title')));
  same('vulnerabilities: «What he checked» folded, its head with the containers read', tx(lookHead()),
    `▸ ${T('vuln.look_title')} · ${T('vuln.look_ct', { n: 7, read: 4 })}`);
  lookHead().click();
  await tick();
  const look = sectionOf(r, 'area.vuln').querySelector('.wm-vuln-look');
  const lookRows = look ? look.querySelectorAll('.row-name').map(tx) : [];
  const lookText = look ? look.querySelectorAll('p').map(tx).concat(look.querySelectorAll('li').map(tx)) : [];
  same('vulnerabilities: «What he checked» opened — the rows of this server, the containers, the routers by their log',
    lookRows, ['Unraid 7.4.1', 'Linux kernel 6.12.54-Unraid', 'PHP 8.4.14', 'OpenSSH 10.0p2', 'Bash 4.3.011',
      T('vuln.look_ct_row', { where: 'webapp', product: 'PHP', version: '7.2.20' }), T('vuln.look_ct_row', { where: 'webapp', product: 'nginx', version: '1.27.1' }),
      T('vuln.look_ct_row', { where: 'notes-db', product: 'PostgreSQL', version: '17.12' }),
      // 2026-11-26: DSM's value is a build; a linuxserver label shown as judged («12.4», not «12.4ubu2604-ls61»)
      T('vuln.look_ct_row', { where: 'nas', product: 'Synology DSM', version: T('vuln.build', { build: '72806' }) }),
      T('vuln.look_ct_row', { where: 'media', product: 'Jellyfin', version: '12.4' }),
      T('vuln.router_seen', { product: 'MikroTik RouterOS', version: '7.24.5', date: O.fmt.date(state.vuln.routers[0].at) }), T('vuln.router_unknown', { product: 'MikroTik RouterOS' })]);
  same('vulnerabilities: the containers he couldn\'t check by reason, that he never looks inside, the VMs counted, what he never looks at',
    [T('vuln.look_unread_none', { n: 2, names: 'homevideos, photos' }), T('vuln.look_unread_unasked', { n: 1, names: 'wiki' }), T('vuln.look_inside'),
      T('vuln.look_not_vm_n', { n: 2 }), T('vuln.look_not_net'), T('vuln.look_plugins')].map((x) => lookText.includes(x)), [true, true, true, true, true, true]);
  lookHead().click();
  await tick();
  const typed = async (words) => {
    const input = barIn(sectionOf(r, 'area.vuln')).querySelector('.filter-words');
    input.value = words;
    input.dispatch('input');
    await tick();
    const s2 = sectionOf(r, 'area.vuln');
    return [s2.querySelectorAll('.wm-vuln-not').map((x) => tx(x.querySelector('.row-name'))).length,
      tx(barIn(s2).querySelector('.filter-note') || new FakeNode('span'))];
  };
  const bash = await typed('bash');
  const metas = sectionOf(r, 'area.vuln').querySelectorAll('.row-meta').map(tx);
  const ssh = await typed('regreSSHion');
  const cve = await typed('CVE-2016-5195');
  const none = await typed('php2shell');
  same('vulnerabilities: the filter searches the calm lists (product, name, CVE) and «What he checked» — Bash there and on this server; nothing found says so',
    [bash[0], ssh[0], cve[0], none[0]], [2, 1, 1, 0]);
  same('vulnerabilities: an unsure line says why — the list names the version only roughly', metas.some((m) => m.includes(T('vuln.unsure_why_rough', { bound: '4.3' }))), true);
  const old = await typed('CVE-2010-3904');
  same('vulnerabilities: the filter finds a line «long fixed» too (its own fold, open while it holds hits)', old[0], 1);
  // the fixture: not 3 + unsure 4 + the projects' advisories 3 (2 affected, 1 unsure) = 10 in the lists; host 5 + containers
  // 5 + unread 3 + routers 2 = 15 he checked
  same('vulnerabilities: the filter\'s count is over all the lines (25 here: 10 in the lists, 15 he checked)', /\b25\b/.test(none[1]), true);
  // the projects' own advisories (2026-11-26): their fold critical first, each row its severity, summary and the advisory's
  // link built from its id; the unsure one under «Can't be checked for sure» with the advisory's own words
  const gh = await typed('Jellyfin');
  const ghSec = sectionOf(r, 'area.vuln');
  const ghHead = ghSec.querySelectorAll('.wm-known-head').map(tx).find((x) => x.includes(T('vuln.ghsa_title', { n: 2 })));
  const ghRows = ghSec.querySelectorAll('.wm-vuln-not').map((x) => tx(x.querySelector('.row-name')));
  const links = ghSec.querySelectorAll('.wm-vuln-link').map((a) => a.href);
  same('vulnerabilities: the projects\' advisories — their fold, critical first, the link to each; the unsure one with its words; «What he checked» finds the container',
    [!!ghHead, ghRows.slice(0, 3), links, ghSec.querySelectorAll('.row-meta').map(tx).some((m) => m.includes(T('vuln.sev_critical')) && m.includes('Path traversal')),
      ghSec.querySelectorAll('.row-meta').map(tx).some((m) => m.includes(T('vuln.unsure_why_ghsa', { range: '> main@4ffa26c9' }))), gh[0]],
    [true, [T('vuln.ghsa_row', { id: 'GHSA-x4m2-9qrc-7hvp', product: 'Jellyfin', where: T('vuln.not_in', { where: 'media' }), have: '12.4', fixed: '12.5' }),
      T('vuln.ghsa_row', { id: 'CVE-2026-90011', product: 'Jellyfin', where: T('vuln.not_in', { where: 'media' }), have: '12.4', fixed: '12.4.2' }),
      T('vuln.unsure_row', { product: 'Jellyfin', cve: 'GHSA-r9vw-3mqx-hc5f', have: '12.4', where: T('vuln.not_in', { where: 'media' }) })],
    ['https://github.com/advisories/GHSA-x4m2-9qrc-7hvp', 'https://github.com/advisories/GHSA-p7c3-w2jf-q8mx'], true, true, 4]);
  const ghLine = ghSec.querySelectorAll('.wm-vuln-counted').map(tx);
  same('vulnerabilities: what he read of the projects\' advisories, one calm line', ghLine.includes(T('vuln.ghsa_line', { list: 'Jellyfin 7' })), true);
  await typed('nas');
  same('vulnerabilities: DSM\'s unsure row says its build as a build', sectionOf(r, 'area.vuln').querySelectorAll('.wm-vuln-not').map((x) => tx(x.querySelector('.row-name')))
    .includes(T('vuln.unsure_row', { product: 'Synology DSM', cve: 'CVE-2024-10441', have: T('vuln.build', { build: '72806' }), where: T('vuln.not_in', { where: 'nas' }) })), true);
  const ct = await typed('webapp');
  const ctMetas = sectionOf(r, 'area.vuln').querySelectorAll('.row-meta').map(tx);
  same('vulnerabilities: a container\'s name finds its unsure line (its why: the line past its end) and its rows under «What he checked»',
    [ct[0], ctMetas.some((m) => m.includes(T('vuln.unsure_why_eol', { line: '7.2' })))], [3, true]);
  await typed('homevideos');
  same('vulnerabilities: a container he couldn\'t check is found by its name too', (sectionOf(r, 'area.vuln').querySelector('.wm-vuln-look') || new FakeNode('div')).querySelectorAll('p').map(tx)
    .includes(T('vuln.look_unread_none', { n: 1, names: 'homevideos' })), true);
  wm.unmount();
}

// ------------------------------------------------------------------ a data folder as the 1.96 test stand left it (5th argument «stand»)
/*
 * tests/run.php testWatchmanStandRender hands in the page state the messenger made from a data folder with what the
 * 1.96 test stand has (rules.json with exceptions, lasting answers, settings, an area off): the new page («einfacher»)
 * draws every tile, the book, the schedule and every tile of his setup without an error; the exception and the answer
 * stand in the setup of their tile; the area switched off says so there.
 */
async function stand() {
  state = JSON.parse(JSON.stringify(base));
  const errs = [];
  const visitAll = async (subs) => {
    for (const sub of subs) {
      const r = new FakeNode('div');
      desk.replaceChildren(r);
      try { await wm.mount(r, sub); await tick(); await tick(); } catch (e) { errs.push(`${sub}: ${String(e && e.stack || e).slice(0, 300)}`); }
      wm.unmount();
    }
  };
  const TILES = ['login', 'access', 'container', 'plugins', 'server', 'data', 'snap', 'net', 'hw', 'vuln', 'array', 'wakes'];
  await visitAll(['', ...TILES, 'book', 'schedule', 'incidents', 'posture', 'pull', 'hold', 'setup', 'setup/changed', ...TILES.map((t) => `setup/${t}`)]);
  same('stand: every tile, the book, the schedule, the old places and every tile of his setup drawn without an error', errs, []);
  const r = new FakeNode('div');
  desk.replaceChildren(r);
  await wm.mount(r, 'setup/login');
  await tick();
  await tick();
  const rules = r.all().find((n) => n.classList && n.classList.contains('wm-rules'));
  const known = r.all().find((n) => n.classList && n.classList.contains('wm-known-head'));
  same('stand: its exception and its answer in the setup of «Logins»', [!!rules, !!known], [true, true]);
  wm.unmount();
  desk.replaceChildren(r);
  await wm.mount(r, 'setup/snap');
  await tick();
  await tick();
  same('stand: the area switched off says so in its tile\'s setup', r.all().some((n) => n.classList && n.classList.contains('callout') && /off|aus/i.test(tx(n))), true);
  wm.unmount();
}

(process.argv[5] === 'stand' ? stand() : run().then(rules).then(handDialog).then(vulnPage)).then(() => { console.log(JSON.stringify(out)); process.exit(0); },
  (e) => { out.fail.push(['ran', String(e && e.stack || e).slice(0, 600), 'no error']); console.log(JSON.stringify(out)); process.exit(0); });
