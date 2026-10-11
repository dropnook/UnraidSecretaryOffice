/*
 * The night watchman's «What I keep an eye on» with its filter bar (public/desks/watchman/desk.js, under node): the pure
 * part — groupFit() (words in a group's head — title, summary, its extra words: «Docker» — keep the whole group; else
 * each item on its own words with the head; notes go), fitTally(), groupOpen() (with words every group shown unfolded unless folded by
 * hand; without them as kept) — and watchSection() itself on a small stand-in DOM: words hit an item's name, a chip's
 * word, a sub line, a group's title; a group without hits is left out, the one with hits unfolded with «n of m fit» on
 * its summary; the bar told «n of m»; no hits at all: the empty line; no words: every group as before, folded as kept.
 * Then the demo state of the click test (tests/ui/demo/watchman.json) once: drawn whole, and a container's name.
 *
 *   node tests/watchfilter.js public/desks/watchman/desk.js     → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testWatchFilter runs it on the server; on the Mac it runs as it is (no PHP needed).
 */
'use strict';
const fs = require('fs');
const path = require('path');

const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};

// ------------------------------------------------------------------ a stand-in DOM (what the watch list's code uses)
class Node {
  constructor(tag) { this.tagName = String(tag).toUpperCase(); this.children = []; this.parentNode = null; this.className = ''; this.text = ''; this.dataset = {}; this.attrs = {}; this.hidden = false; this.listeners = {}; }
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
  get textContent() { return this.text + this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children = []; this.text = v === null || v === undefined ? '' : String(v); }
  get nodeType() { return 1; }
  appendChild(c) { if (typeof c === 'string') { const t = new Node('#text'); t.text = c; c = t; } c.parentNode = this; this.children.push(c); return c; }
  append(...cs) { cs.forEach((c) => this.appendChild(c)); }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return k in this.attrs ? this.attrs[k] : null; }
  addEventListener(ev, f) { (this.listeners[ev] = this.listeners[ev] || []).push(f); }
  all(pred, acc = []) { this.children.forEach((c) => { if (pred(c)) acc.push(c); c.all(pred, acc); }); return acc; }
  byClass(c) { return this.all((n) => n.classList.contains(c)); }
}
const el = (tag, cls, text) => { const n = new Node(tag); if (cls) n.className = cls; if (text !== undefined && text !== null) n.textContent = text; return n; };

// ------------------------------------------------------------------ the office as far as the page needs it
const fold = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/ß/g, 'ss');     // core.js Office.fold
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
let kept = {};                            // Office.storeJson
const bar = { words: '', done: null, resets: 0 };
globalThis.OFFICE_DESK_TESTS = {};
globalThis.Office = {
  scope: (desk) => (k, p) => T(`${desk}.${k}`, p), t: T, el, has: () => true,
  store: () => null, storeJson: (k, v) => { if (v === undefined) return kept[k] ? JSON.parse(JSON.stringify(kept[k])) : null; kept[k] = JSON.parse(JSON.stringify(v)); return v; },
  desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {}, sections: () => ({ places: () => [], render: () => {} }),
  fold, filterWords: (s) => fold(s).split(/\s+/).filter(Boolean),
  fits: (words, hay) => {                 // core.js Office.fits
    if (!words || !words.length) return true;
    const h = Array.isArray(hay) ? hay.filter((x) => x !== null && x !== undefined && x !== '').map(fold).join('\u0001') : fold(hay);
    return words.every((w) => h.includes(w));
  },
  filterBar: (o) => ({ el: el('div', 'toolbar filterbar'), key: o.key, label: o.label, onChange: o.onChange,
    read: () => ({ words: Office.filterWords(bar.words), only: false, pick: '', tile: '' }),
    done: (n, total) => { bar.done = [n, total]; }, reset: () => { bar.words = ''; bar.resets++; } }),
  sectionHead: (title, sub, ...rest) => { const h = el('div', 'section-head'); h.appendChild(el('h2', '', title)); rest.forEach((x) => { if (x && x.nodeType) h.appendChild(x); }); return h; },
  place: (anchor, node) => { node.dataset.place = anchor; return node; },
  keepInPlace: (a, f) => f(), safeHref: (u) => (typeof u === 'string' && /^(#|\/|https?:)/.test(u) ? u : null),
  agent: { running: true }, desks: { get: () => ({ hired: true }) },
  fmt: { size: (b) => b + ' B', relative: () => 'now', date: () => 'a date', dayKey: () => '', number: (n) => String(n), cron: (c) => c, duration: (s) => s + ' s' },
};
const file = process.argv[2] || path.join(__dirname, '../public/desks/watchman/desk.js');
(0, eval)(fs.readFileSync(file, 'utf8'));
const W = OFFICE_DESK_TESTS.watchman;
const words = (s) => Office.filterWords(s);

// ------------------------------------------------------------------ the pure part
const hays = [['alpha', '-p 80:80/tcp'], ['beta', '--privileged'], null, ['gamma']];
let f = W.groupFit([], ['Containers', '3 of 9'], hays);
same('fit: no words — the group and every row, as without the bar', [f.shown, f.whole, f.rows, f.n, f.total], [true, true, [true, true, true, true], 3, 3]);
f = W.groupFit(words('beta'), ['Containers', '3 of 9'], hays);
same('fit: an item\'s name — only that row, the note gone', [f.shown, f.whole, f.rows, f.n, f.total], [true, false, [false, true, false, false], 1, 3]);
f = W.groupFit(words('PRIVILEGED'), ['Containers', '3 of 9'], hays);
same('fit: a chip\'s word (case folded)', f.rows, [false, true, false, false]);
f = W.groupFit(words('contain'), ['Containers', '3 of 9'], hays);
same('fit: the group\'s title — the whole group as it is (the note too)', [f.shown, f.whole, f.rows, f.n], [true, true, [true, true, true, true], 3]);
f = W.groupFit(words('containers alpha'), ['Containers', '3 of 9'], hays);
same('fit: words on the title and an item together', [f.whole, f.rows], [false, [true, false, false, false]]);
f = W.groupFit(words('zzz'), ['Containers', '3 of 9'], hays);
same('fit: no hits — the group not shown', [f.shown, f.n, f.total], [false, 0, 3]);
f = W.groupFit(words('alpha beta'), ['Containers', '3 of 9'], hays);
same('fit: a word never fits across two items', f.shown, false);
f = W.groupFit(words('waiting'), ['Schedules', 'waiting for his round'], []);
same('fit: an empty group found by its summary', [f.shown, f.n, f.total], [true, 0, 0]);
f = W.groupFit(words('docker'), ['Containers', '3 of 9', 'Docker'], hays);
same('fit: a group\'s own extra word — the whole group', [f.shown, f.whole, f.n], [true, true, 3]);
same('tally: n, total, groups shown', W.fitTally([{ n: 1, total: 3, shown: true }, { n: 0, total: 5, shown: false }, { n: 2, total: 2, shown: true }]), { n: 3, total: 10, shown: 2 });
same('open: no words — as kept', [W.groupOpen('a', [], { a: true }, new Set()), W.groupOpen('b', [], { a: true }, new Set())], [true, false]);
same('open: words — unfolded, unless folded by hand', [W.groupOpen('b', ['x'], {}, new Set()), W.groupOpen('b', ['x'], { b: true }, new Set(['b']))], [true, false]);

// ------------------------------------------------------------------ the section on the stand-in DOM
const state = {
  book: [], open: {}, posture: null, net: null, snaps: null, flow: null,
  watch: {
    ips: [{ ip: '192.168.1.20', users: ['root'], services: ['ssh'], last: 1 }, { ip: '192.168.1.30', users: ['benj'], services: ['web'], last: 1 }],
    fail_ips: [],
    containers: { count: 33, special: [{ name: 'Plex', tokens: ['--net=host'] }, { name: 'tailscale', tokens: ['--privileged', '--cap-add=NET_ADMIN'] }, { name: 'Grafana', tokens: ['-p 3000:3000/tcp'] }] },
    plugins: [{ name: 'unassigned.devices', source: 'github.com/dlandon', version: '2026.09.01' }, { name: 'user.scripts', source: 'github.com/Squidly271', version: '2025.01.01' }],
    flash: { go: { lines: 3 }, extra: [{ file: 'config/wireguard.cfg', size: 300 }], users: ['root', 'benj'], keys: [] },
    shares: { count: 10, open: [{ share: 'media', smb: 2, nfs: 0 }] },
    sched: { crontab: ['0 3 * * * /usr/local/sbin/mover'], files: [], scripts: [{ name: 'nightly-tailscale-check', cron: 'daily' }], agents: [] },
    host: null,
  },
};
W.setState(state);
const groups = (sec) => sec.byClass('group').map((g) => g.dataset.place.replace('watch:', ''));
const rowsOf = (sec, key) => { const g = sec.byClass('group').find((x) => x.dataset.place === 'watch:' + key); return g ? g.byClass('wm-item').map((r) => r.children[0].children[0].textContent) : null; };
const metaOf = (sec, key) => { const g = sec.byClass('group').find((x) => x.dataset.place === 'watch:' + key); return g ? g.byClass('group-meta')[0].textContent : null; };
const closed = (sec) => sec.byClass('group').filter((g) => g.classList.contains('closed')).map((g) => g.dataset.place.replace('watch:', ''));
const ALL = ['ips', 'containers', 'plugins', 'flash', 'shares', 'sched', 'host', 'net', 'snaps', 'flow_clients'];

kept = { 'watchman.groups': { plugins: true } };
// the page reads its kept groups once, when loaded: load it again with them
(0, eval)(fs.readFileSync(file, 'utf8'));
const V = OFFICE_DESK_TESTS.watchman;
V.setState(state);
bar.words = '';
let sec = V.watchSection();
same('section: the bar under the head, above the box', sec.children.map((c) => c.className), ['section-head', 'toolbar filterbar', 'box wm-watchlist']);
same('section: no words — every group as before', groups(sec), ALL);
same('section: no words — folded as kept (only «plugins» open)', closed(sec), ALL.filter((k) => k !== 'plugins'));
const items = 2 + 3 + 2 + 4 + 1 + 2;          // ips, containers, plugins, flash (go, extra, users, keys), shares, sched (crontab line, script)
same('section: no words — the bar told every item', bar.done, [items, items]);
same('section: no words — summaries as they are', metaOf(sec, 'containers'), 'watchman.watch.containers_sum {"special":3,"count":33,"n":33}');

bar.words = 'tailscale';
sec = V.watchSection();
same('words «tailscale»: the groups with hits (a container\'s name, a User Script\'s name)', groups(sec), ['containers', 'sched']);
same('words «tailscale»: only the fitting rows', [rowsOf(sec, 'containers'), rowsOf(sec, 'sched')], [['tailscale'], ['nightly-tailscale-check']]);
same('words «tailscale»: the groups with hits unfolded', closed(sec), []);
same('words «tailscale»: the summary followed by «n of m fit»', metaOf(sec, 'containers'), 'watchman.watch.containers_sum {"special":3,"count":33,"n":33} · common.filter_hits {"n":1,"total":3}');
same('words «tailscale»: the bar «2 of all»', bar.done, [2, items]);
same('words «tailscale»: no empty line', sec.byClass('empty').length, 0);

bar.words = 'net_admin';
sec = V.watchSection();
same('words: a chip\'s word', [groups(sec), rowsOf(sec, 'containers')], [['containers'], ['tailscale']]);
bar.words = 'dlandon';
sec = V.watchSection();
same('words: a sub line (a plugin\'s source)', [groups(sec), rowsOf(sec, 'plugins')], [['plugins'], ['unassigned.devices']]);
bar.words = 'watch.plugins';
sec = V.watchSection();
same('words: a group\'s title — the whole group', [groups(sec), rowsOf(sec, 'plugins'), metaOf(sec, 'plugins')], [['plugins'], ['unassigned.devices', 'user.scripts'], 'watchman.watch.plugins_sum {"n":2}']);
bar.words = 'wireguard';
sec = V.watchSection();
same('words: the flash\'s files', [groups(sec), rowsOf(sec, 'flash')], [['flash'], ['watchman.watch.extra']]);

bar.words = 'tailscale';
sec = V.watchSection();
// fold a group by hand while the words are typed: for as long as they are; the kept state untouched
sec.byClass('group').find((g) => g.dataset.place === 'watch:sched').byClass('group-head')[0].onclick({ detail: 0 });
same('words: a group folded by hand stays folded while the words stay', closed(V.watchSection()), ['sched']);
same('words: … the kept groups untouched', kept['watchman.groups'], { plugins: true });

bar.words = 'docker';
sec = V.watchSection();
same('words «docker»: the containers with special rights, whole', [groups(sec), rowsOf(sec, 'containers')], [['containers'], ['Plex', 'tailscale', 'Grafana']]);

bar.words = 'zzz-nothing';
sec = V.watchSection();
same('no hits at all: no group, the empty line', [groups(sec), sec.byClass('empty').map((p) => p.textContent)], [[], ['watchman.watch.filter.none']]);
same('no hits at all: the bar «0 of all»', bar.done, [0, items]);

bar.words = '';               // «Show all» empties the words
sec = V.watchSection();
same('Show all: every group back', groups(sec), ALL);
same('Show all: folded as they were', closed(sec), ALL.filter((k) => k !== 'plugins'));
same('Show all: every row back', rowsOf(sec, 'containers'), ['Plex', 'tailscale', 'Grafana']);

// ------------------------------------------------------------------ the demo state of the click test
const demo = JSON.parse(fs.readFileSync(path.join(__dirname, 'ui/demo/watchman.json'), 'utf8'));
V.setState(demo.state || demo);
bar.words = '';
let ok = true;
try { sec = V.watchSection(); } catch (e) { ok = String(e && e.stack); }
same('demo: drawn whole without words', ok, true);
const whole = groups(sec).length;
same('demo: every item counted', bar.done && bar.done[0] === bar.done[1] && bar.done[1] > 20, true);
bar.words = 'immich_server';
sec = V.watchSection();
same('demo: a container\'s name — its special rights and its data flow', groups(sec), ['containers', 'flow_containers']);
same('demo: fewer groups than without words', groups(sec).length < whole, true);
bar.words = 'docker';
sec = V.watchSection();
same('demo: «docker» — both container groups, whole', [groups(sec), bar.done[0] === 5 + (demo.state || demo).flow.containers.length], [['containers', 'flow_containers'], true]);

console.log(JSON.stringify(out));
