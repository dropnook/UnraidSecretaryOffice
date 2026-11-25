/*
 * The night watchman's watch book as his page shows it (public/desks/watchman/desk.js bookView(), under node): entries of
 * the same kind and area on the same day fold into one row, day headings between the rows, «show more» counts rows, the
 * area filter with its counts, the filter's words and «only open» over the whole book, the entry a link asks for inside
 * its row. Fixture books: the shape of a production server after one day (31 entries: 8 new devices on the LAN, 4 at
 * jobs, 4 plugin schedules, 3 SMB machines …) and 500 entries over 90 days.
 *
 *   node tests/watchbook.js public/desks/watchman/desk.js      → one JSON line {pass, fail: [[name, got, want], …]}
 *
 * tests/run.php testWatchBookView runs it on the server; on the Mac it runs as it is (no PHP needed).
 */
'use strict';
const fs = require('fs');
const path = require('path');

globalThis.OFFICE_DESK_TESTS = {};
let dict = null;           // a desk's strings (the page's lines in words, below); else the key and its params
const T = (k, p) => {
  if (!dict) return k + (p ? ' ' + JSON.stringify(p) : '');
  let v = dict[k.replace(/^watchman\./, '')];
  if (v && typeof v === 'object') v = Number(p && p.n) === 1 ? v.one : v.other;
  return String(v ?? k).replace(/\{(\w+)\}/g, (m, x) => (p && p[x] !== undefined ? String(p[x]) : m));
};
let has = () => true;
globalThis.Office = {
  scope: (desk) => (k, p) => T(`${desk}.${k}`, p), t: T, el: () => ({}), store: () => null, storeJson: () => null,
  desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {}, has: (k) => has(k), sections: () => ({ places: () => [] }),
  fold: (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/ß/g, 'ss'),     // core.js Office.fold
  fmt: { size: (b) => b + ' B', relative: () => 'now', dayKey: (s) => new Date(s * 1000).toISOString().slice(0, 10) },
};
const file = process.argv[2] || path.join(__dirname, '../public/desks/watchman/desk.js');
(0, eval)(fs.readFileSync(file, 'utf8'));
const W = OFFICE_DESK_TESTS.watchman;

const out = { pass: 0, fail: [] };
const same = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) out.pass++; else out.fail.push([name, got, want]);
};
const day = (s) => new Date(s * 1000).toISOString().slice(0, 10);       // the tests' days: UTC, whatever the server's zone
const at = (d, hh, mm) => Date.UTC(2026, 9, d, hh, mm) / 1000;
const GROUP = { net_new_device: 'net', at_job: 'sched', cron_file: 'sched', smb_client: 'flow', login_new_ip: 'login', login_failures: 'login',
  container_new: 'container', array_stop: 'array', array_start: 'array', plugin_new: 'plugin', watch: 'watch' };
let seq = 0;
const entry = (kind, t, extra) => {
  seq++;
  return { id: 'w' + seq.toString(16).padStart(10, '0'), kind, group: GROUP[kind] || 'host', time: t - 30, last: t, count: 1, open: true,
    p: {}, t: {}, attack: null, night: false, ...extra };
};
const view = (book, o) => W.bookView(book, { onlyOpen: false, area: '', words: [], hay: W.entryHay, day, chains: [], shown: 30, page: 30, wanted: null, ...o });
const words = (s) => W.plainWords(s).split(/\s+/).filter(Boolean);
const kinds = (v) => v.rows.map((r) => `${r.kind}:${r.members.length}`);

// ---------------------------------------------------------------- the 31 entries of one day (and the day before)
const book = [];
const devices = [];
for (let i = 0; i < 8; i++) {      // 8 new devices on the LAN today, 5 still open
  const e = entry('net_new_device', at(9, 14, 50 - i * 5), { open: i < 5, p: { name: i < 2 ? `phone-${i}` : `iot-${i}`, mac: `aa:bb:cc:00:00:0${i}`, ip: `192.168.1.${100 + i}`, router: 'UDM' } });
  devices.push(e);
  book.push(e);
}
for (let i = 0; i < 4; i++) book.push(entry('at_job', at(9, 13, 40 - i), { p: { cmd: `/tmp/job${i}.sh` } }));
for (let i = 0; i < 3; i++) book.push(entry('cron_file', at(9, 12, 30 - i), { p: { file: `plugin${i}.cron`, jobs: [`job ${i}`], lines: 1 } }));
const smb = [0, 1, 2].map((i) => entry('smb_client', at(9, 11, 20 - i), { open: i !== 1, night: i === 2, p: { ip: `10.0.0.${i + 1}`, user: 'benj', machine: `MAC${i}` } }));
book.push(...smb);
const login = entry('login_new_ip', at(9, 10, 0), { p: { ip: '203.0.113.7', user: 'root', service: 'ssh' } });
book.push(login);
const cont = [0, 1].map((i) => entry('container_new', at(9, 9, 59 - i), { p: { name: `box${i}`, rights: '--privileged' } }));
book.push(...cont);
// the day before
book.push(entry('cron_file', at(8, 23, 0), { open: false, p: { file: 'old.cron', jobs: ['x'], lines: 1 } }));
book.push(entry('array_stop', at(8, 22, 0), { open: false }));
book.push(entry('array_start', at(8, 21, 0), { open: false }));
book.push(entry('plugin_new', at(8, 20, 0), { p: { name: 'evil', source: 'evil.example' } }), entry('plugin_new', at(8, 19, 0), { p: { name: 'good', source: 'github.com' } }));
book.push(entry('login_failures', at(8, 18, 0), { count: 7, p: { ip: '198.51.100.9' } }), entry('login_failures', at(8, 17, 0), { count: 5, p: { ip: '198.51.100.10' } }));
book.push(entry('watch', at(8, 16, 0), { open: false }));
book.push(entry('watch', at(8, 15, 0), { open: false, p: { too_much: 1, sender: 'udm', skipped: 1000 } }), entry('watch', at(8, 14, 0), { open: false, p: { too_much: 1, sender: 'udm', skipped: 2000 } }));
book.sort((a, b) => b.last - a.last);
same('fixture: 31 entries', book.length, 31);
const chains = [{ ids: [login.id, cont[0].id] }];

let v = view(book, { chains });
same('31: same kind, same day → one row; another day or another kind → its own row', kinds(v),
  ['net_new_device:8', 'at_job:4', 'cron_file:3', 'smb_client:3', 'login_new_ip:1', 'container_new:2',
   'cron_file:1', 'array_stop:1', 'array_start:1', 'plugin_new:2', 'login_failures:2', 'watch:1', 'net_too_much:2']);
same('31: every entry in exactly one row', v.rows.reduce((a, r) => a + r.members.length, 0), 31);
const net = v.rows[0];
same('31: the folded row — members newest first, open counted, the newest time, total', [net.members[0].id, net.open, net.last, net.total, net.area],
  [devices[0].id, 5, devices[0].last, 8, 'net']);
same('31: noted and open of a kind/day in one row: «3 offen» on the SMB row (2 of 3 open), the night shift shown', [v.rows[3].open, v.rows[3].night], [2, true]);
same('31: a row with a member in a chain says so', [v.rows[5].chain, v.rows[4].chain, v.rows[0].chain], [true, true, false]);
same('31: day headings — today, then yesterday, each before its first row',
  v.items.map((x) => (x.members ? x.kind : '# ' + x.day)),
  ['# 2026-10-09', 'net_new_device', 'at_job', 'cron_file', 'smb_client', 'login_new_ip', 'container_new',
   '# 2026-10-08', 'cron_file', 'array_stop', 'array_start', 'plugin_new', 'login_failures', 'watch', 'net_too_much']);
same('31: the areas with their counts (a router\'s log too long counts to the network; the take-over line its own)',
  Object.fromEntries(v.areas), { net: 10, sched: 8, flow: 3, login: 3, container: 2, array: 2, plugin: 2, watch: 1 });
same('31: a row of one is the entry itself', v.rows[4].members.map((e) => e.id), [login.id]);

// «show more» counts rows, not entries
v = view(book, { page: 5, shown: 5 });
same('page of 5: five rows drawn (8 + 4 + 3 + 3 + 1 = 19 entries), one day heading', [v.items.filter((x) => x.members).length, v.items.filter((x) => !x.members).length, v.shown, v.rows.length],
  [5, 1, 5, 13]);
v = view(book, { page: 5, shown: 10 });
same('page of 5, shown 10: ten rows, both day headings', [v.items.filter((x) => x.members).length, v.items.filter((x) => !x.members).length], [10, 2]);

// only open
v = view(book, { onlyOpen: true });
same('only open: noted entries leave their rows; rows of none go', kinds(v),
  ['net_new_device:5', 'at_job:4', 'cron_file:3', 'smb_client:2', 'login_new_ip:1', 'container_new:2', 'plugin_new:2', 'login_failures:2']);
same('only open: the areas count what is open', Object.fromEntries(v.areas), { net: 5, sched: 7, flow: 2, login: 3, container: 2, plugin: 2 });
same('only open: the total of a row is what the switches leave', v.rows[0].total, 5);

// the area
v = view(book, { area: 'net' });
same('area «net»: the devices and the router\'s long logs, under their days', v.items.map((x) => (x.members ? `${x.kind}:${x.members.length}` : '# ' + x.day)),
  ['# 2026-10-09', 'net_new_device:8', '# 2026-10-08', 'net_too_much:2']);
same('area «net»: the counts stay those of the whole book (the area itself is not counted)', Object.fromEntries(v.areas).sched, 8);
v = view(book, { area: 'sched', onlyOpen: true });
same('area + only open combine', kinds(v), ['at_job:4', 'cron_file:3']);

// the filter's words: the whole book, entries' params (MAC, IP, names), area and kind
v = view(book, { words: words('AA:BB:CC:00:00:03') });
same('words: a MAC meets one device — a row of one, though its kind/day has 8', [kinds(v), v.rows[0].total], [['net_new_device:1'], 8]);
v = view(book, { words: words('phone') });
same('words: a name meets two devices — one row of the two hits, its total 8', [kinds(v), v.rows[0].total], [['net_new_device:2'], 8]);
v = view(book, { words: words('192.168.1.10') });
same('words: an IP (as a part: .100 … .107)', kinds(v), ['net_new_device:8']);
v = view(book, { words: words('phone'), onlyOpen: true });
same('words + only open: the two phones are open', kinds(v), ['net_new_device:2']);
v = view(book, { words: words('iot'), onlyOpen: true });
same('words + only open: of six iot devices three are open', kinds(v), ['net_new_device:3']);
v = view(book, { words: words('benj mac1') });
same('words: every word must meet (an SMB machine by user and name)', kinds(v), ['smb_client:1']);
v = view(book, { words: words('at_job') });
same('words: the kind', kinds(v), ['at_job:4']);
v = view(book, { words: words('watchman.book.area_watch') });
same('words: the area\'s name (here the stand-in\'s key: the take-over line\'s area «His watch»)', kinds(v), ['watch:1']);
v = view(book, { words: words('nothing-like-this') });
same('words: nothing met — no rows, the areas still counted', [v.rows.length, v.items.length, Object.fromEntries(v.areas).net], [0, 0, 10]);
v = view(book, { words: words('évil') });
same('words: accents folded (évil → evil)', kinds(v), ['plugin_new:1']);

// the entry a link (the search, #/watchman/entry/<id>) asks for
v = view(book, { wanted: devices[6].id });
same('wanted: inside the folded row of the devices — its key, row 0', [v.wanted && v.wanted.row, v.wanted && v.wanted.key, v.wanted && v.wanted.entry], [0, net.key, devices[6].id]);
v = view(book, { wanted: book[book.length - 1].id, page: 5, shown: 5 });
same('wanted: in the last row — «shown» grows by pages until it is drawn', [v.wanted && v.wanted.row, v.shown, v.items.filter((x) => x.members).length], [12, 15, 13]);
v = view(book, { wanted: devices[6].id, onlyOpen: true });
same('wanted: a noted entry behind «only open» — hidden, not found', [v.wanted, v.hidden], [null, true]);
v = view(book, { wanted: devices[0].id, area: 'sched' });
same('wanted: behind the area — hidden', [v.wanted, v.hidden], [null, true]);
v = view(book, { wanted: devices[0].id, words: words('at_job') });
same('wanted: behind the words — hidden', [v.wanted, v.hidden], [null, true]);
v = view(book, { wanted: 'w0123456789' });
same('wanted: not in the book at all — neither found nor hidden', [v.wanted, v.hidden], [null, false]);
v = view(book, { wanted: smb[1].id, words: words('mac1') });
same('wanted: met by the words too — found in its row of one', [v.wanted && v.wanted.row, v.rows[0].members.length], [0, 1]);

// the row's words
const fold = (r) => W.foldName(r);
same('fold text: a plural per kind with n (the row\'s members)', fold(view(book).rows[0]), 'watchman.fold.net_new_device {"n":8}');
has = (k) => k !== 'watchman.fold.at_job';
same('fold text: a kind without its own text — «{n} entries — <area>»', fold(view(book).rows[1]), 'watchman.fold.any {"n":4,"area":"watchman.group.sched"}');
has = () => true;
same('fold text: a router\'s log too long is a kind of its own (net_too_much)', fold(view(book).rows[12]), 'watchman.fold.net_too_much {"n":2}');
same('entry words: the MAC, the IP and the name are in them', ['aa:bb:cc:00:00:03', '192.168.1.103', 'iot-3'].every((w) => W.entryHay(devices[3]).includes(w)), true);

// ---------------------------------------------------------------- 500 entries over 90 days
let r = 7;
const rnd = (n) => { r = (r * 1103515245 + 12345) % 2147483648; return r % n; };
const many = [];
const pool = ['net_new_device', 'at_job', 'cron_file', 'smb_client', 'login_new_ip', 'login_failures', 'container_new', 'plugin_new', 'array_stop', 'array_start'];
const now = at(9, 15, 0);
for (let i = 0; i < 500; i++) {
  const t = now - rnd(90 * 86400);
  many.push(entry(pool[rnd(pool.length)], t, { open: rnd(3) === 0, p: { name: `n${i}`, mac: `de:ad:be:ef:${String(i % 100).padStart(2, '0')}:${String(Math.floor(i / 100)).padStart(2, '0')}` } }));
}
many.sort((a, b) => b.last - a.last);
const t0 = process.hrtime.bigint();
v = view(many);
const ms = Number(process.hrtime.bigint() - t0) / 1e6;
same('500: every entry in exactly one row', v.rows.reduce((a, x) => a + x.members.length, 0), 500);
same('500: each row one kind, one area, one day; its members newest first', v.rows.every((x) => x.members.every((e) => e.kind === x.kind && day(e.last) === x.day && (GROUP[e.kind] || 'host') === x.area)
  && x.members.every((e, i, a) => !i || a[i - 1].last >= e.last)), true);
same('500: rows newest first', v.rows.every((x, i, a) => !i || a[i - 1].last >= x.last), true);
same('500: no kind twice on a day', new Set(v.rows.map((x) => x.key)).size, v.rows.length);
same('500: fewer rows than entries', v.rows.length < 500, true);
const drawn = v.items.filter((x) => x.members);
same('500: the first page — 30 rows, a heading for each of their days', [drawn.length, v.items.filter((x) => !x.members).length], [30, new Set(drawn.map((x) => x.day)).size]);
same('500: the open count of a row is its open members', v.rows.every((x) => x.open === x.members.filter((e) => e.open).length), true);
same('500: the areas sum to the book', v.areas.reduce((a, x) => a + x[1], 0), 500);
const deep = many[480];
v = view(many, { wanted: deep.id });
same('500: an entry far down — its row drawn, «shown» a whole number of pages', [v.wanted !== null, v.wanted && v.wanted.row < v.shown, v.shown % 30, v.items.some((x) => x.members && x.members.some((e) => e.id === deep.id))],
  [true, true, 0, true]);
v = view(many, { words: words('de:ad:be:ef:42'), onlyOpen: true });
same('500: words + only open — only open hits, from the whole book', v.rows.every((x) => x.members.every((e) => e.open && e.p.mac.startsWith('de:ad:be:ef:42'))), true);
same('500: … all of them', v.rows.reduce((a, x) => a + x.members.length, 0), many.filter((e) => e.open && e.p.mac.startsWith('de:ad:be:ef:42')).length);
same('500: the view takes a few milliseconds (with the entries\' words: < 100 ms)', ms < 100, true);
const t1 = process.hrtime.bigint();
view(many, { words: words('n4') });
same('500: filtering by words < 100 ms', Number(process.hrtime.bigint() - t1) / 1e6 < 100, true);

// ---------------------------------------------------------------- the page's line of a share of one dataset: who likely wrote it
dict = JSON.parse(fs.readFileSync(path.join(path.dirname(file), 'lang/en.json'), 'utf8'));
const none = (x) => W.srcFrom({ share: 'maple/Media', src: [{ t: 'none', b: 1, ...x }] });
same('src line: no guess — none visible, as before', none({}), ' No single source visible — maple/Media is one dataset.');
const guess = { guess: [{ t: 'ct', name: 'EmbyServer', path: '/mnt/user/Media' }, { t: 'vm', name: 'Win11', path: '/mnt/user/Media/vm/w.img', disk: true },
  { t: 'stack', name: 'tools', path: '/mnt/maple/Media/x', ro: true, off: true }], more: 2 };
same('src line: the entry\'s line — names only, two of them, the rest counted (the agent\'s words, testWatchmanFlowGuess)', none(guess),
  ' No single source visible. Likely: EmbyServer, Win11 and 3 more (a guess).');
same('src line: the detail row — a running container, a VM\'s disk, a stopped read-only stack, two more, in full',
  W.srcNone({ t: 'none', b: 1, ...guess }, 'maple/Media'),
  'No single source visible. Likely: container EmbyServer (maps /mnt/user/Media), VM Win11 (a disk in Media), Compose stack tools'
  + ' (maps /mnt/maple/Media/x, read-only, not running) and 2 more — a guess from the setup, not measured.');
same('src line: one read-only container — the line, the detail row', [none({ guess: [{ t: 'ct', name: 'plex', path: '/mnt/user/Media/Movies', ro: true }] }),
  W.srcNone({ t: 'none', guess: [{ t: 'ct', name: 'plex', path: '/mnt/user/Media/Movies', ro: true }] }, 'maple/Media')],
  [' No single source visible. Likely: plex (a guess).',
    'No single source visible. Likely: container plex (maps /mnt/user/Media/Movies, read-only) — a guess from the setup, not measured.']);
same('src line: an entry of an older office (no sources) says nothing', W.srcFrom({ share: 'maple/Media' }), '');
dict = null;

// 1.96 «einfacher»: 12 tiles over the areas — «Only what waits for you» is red and yellow, nothing else; an entry's tile
same('tiles: «only what waits for you» — red and yellow only', ['danger', 'warn', 'note', 'ok', 'none', ''].map((l) => W.tileWaits(l)), [true, true, false, false, false, false]);
W.setState({ tiles: { login: { level: 'warn', why: ['open'], parts: ['login', 'partner'] } } });
same('tiles: a tile\'s light from the messenger; none known — no light', [W.tileLevel('login'), W.tileLevel('net')], ['warn', '']);
same('tiles: an entry\'s tile — the messenger\'s, else by kind (go on «Plugins and scripts»), else by area (an older state)',
  [W.entryTile({ tile: 'access', kind: 'flash_ssh_key' }), W.entryTile({ kind: 'flash_go', cat: 'flash' }), W.entryTile({ kind: 'net_dns', cat: 'lan' }), W.entryTile({ kind: 'watch' })],
  ['access', 'plugins', 'net', null]);
same('tiles: every area on one tile, the old places on theirs', [Object.values(W.TILE_PARTS).flat().length, new Set(Object.values(W.TILE_PARTS).flat()).size, W.TILE_ROUTE.pull, W.TILE_ROUTE.hold, W.TILE_ROUTE.posture],
  [19, 19, 'data', 'array', '']);

// «What holds the array?»: what still waits for you, in words — a User Script that may run and a VM you know never count
const holdLookOf = (more = {}) => ({ started: true, time: 1, count: 3, times: { fits: true, disk: 90, need: 60 },
  holds: [{ kind: 'userscript', name: 'sync' }], vms: [{ name: 'Win11', agent: 'none' }, { name: 'Lab', agent: 'disconnected' }, { name: 'HA', agent: 'connected' }], ...more });
const rule = (k, v) => ({ cat: 'hold', on: { [k]: v } });
W.setState({ rules: [rule('script', 'sync'), rule('vm', 'Win11'), rule('vm', 'Lab')] });
same('hold: all three known (a User Script, two VMs without agent) — nothing waits, three known', W.holdWaiting(holdLookOf()), { names: [], known: 3, more: 0 });
W.setState({ rules: [rule('script', 'sync')] });
same('hold: two VMs not known — named, as the tile\'s tip says them', W.holdWaiting(holdLookOf()).names.length, 2);
same('hold: things beyond the look\'s list count as more', W.holdWaiting(holdLookOf({ count: 6 })).more, 3);
same('hold: the array stopped — nothing waits', W.holdWaiting(holdLookOf({ started: false })).names, []);
dict = JSON.parse(fs.readFileSync(path.join(path.dirname(file), 'lang/en.json'), 'utf8'));
Office.light = (o) => o;
Office.fmt.relative = () => 'just now';
W.setState({ rules: [rule('script', 'sync'), rule('vm', 'Win11')], hold: { look: holdLookOf() } });
same('hold tile: one VM waits — yellow, its tip says why; what you know said beside it',
  W.holdTile({}).tip.split('\n'), ['What holds the array?: 1 thing', '2 you know · looked just now', '1 thing would hold the array: VM Lab without the guest agent']);
W.setState({ rules: [rule('script', 'sync'), rule('vm', 'Win11'), rule('vm', 'Lab')], hold: { look: holdLookOf() } });
same('hold tile: everything known — green, «nothing»', [W.holdTile({}).level, W.holdTile({}).tip.split('\n')[0]], ['ok', 'What holds the array?: nothing']);
dict = null;

// the hand-made exception's note (1.96, Benj: no second yes — the note says what it means, as he types); the address
// rule is the agent's watchmanIpPrivate(): 10/8, 172.16/12, 192.168/16, 100.64/10, fc00::/7, fe80::/10
const note = (id, t, v) => W.handNote(id, t, v);
same('hand note: a LAN address or net — nothing to say', [note('login', 'ip', '192.168.1.7'), note('login', 'net', '10.0.0.0/8'), note('login', 'net', '172.20.0.0/16'),
  note('login', 'ip', '100.100.1.1'), note('login', 'net', 'fd00:1:2:3::/64'), note('login', 'ip', 'fe80::1')], [null, null, null, null, null, null]);
same('hand note: an address on the internet — it says so; a net wider than /24 (/48) — it says to narrow it',
  [note('login', 'ip', '203.0.113.9'), note('login', 'net', '203.0.113.0/24'), note('login', 'net', '203.0.0.0/16'), note('login', 'net', '2001:db8::/32'),
    note('login', 'user_net', '172.32.0.0/24'), note('router', 'admin', '8.8.8.8')],
  ['setup.add_public', 'setup.add_public', 'setup.add_net_wide', 'setup.add_net_wide', 'setup.add_public', 'setup.add_public_router']);
same('hand note: half typed or not an address — nothing yet', [note('login', 'ip', '203.0.'), note('login', 'ip', '')], [null, null]);
same('hand note: wide by what it is — a whole source, the data flow, snapshots, guests writing, a whole sender, a product, a CVE',
  [note('plugin', 'source', 'github.com'), note('plugin', 'source', 'github.com/alice'), note('data', 'gone', 'Downloads'), note('snap', 'series', 'x'),
    note('share', 'guest_write', 'Media'), note('share', 'guest_read', 'Media'), note('router', 'sender', '192.168.1.1'), note('vuln', 'product', 'php'), note('vuln', 'cve', 'CVE-2024-1')],
  ['setup.add_wide.plugin', null, 'setup.add_wide.data', 'setup.add_wide.snap', 'setup.add_wide.share', null, 'setup.add_wide.router', 'setup.add_wide.vuln', 'setup.add_wide.cve']);

same('hand note: a container privileged, on the server\'s network or processes, new by its name — their own notes; an SMB machine, a flooded port — none',
  [note('container', 'privileged', 'plex'), note('container', 'host', 'plex'), note('container', 'new', 'plex'), note('login', 'machine', 'LAPTOP'), note('host', 'flood', '443')],
  ['setup.add_wide.privileged', 'setup.add_wide.host', 'setup.add_wide.new', null, null]);
// the names he knows (state.hand_names): a list as it came, a wake's cause by the kind chosen (with its containers or VMs)
W.setState({ hand_names: { container: ['plex', 'sonarr'], vm: ['Win11'], cause: ['container:fotosync', 'program:rsync', 'vm:Lab'], share: 'not a list' } });
same('hand names: a list, a cause of a kind (a container\'s: its causes and the containers), none, a broken list — nothing',
  [W.handNames('container'), W.handNames('cause', 'container'), W.handNames('cause', 'program'), W.handNames('cause', 'vm'), W.handNames('cause', 'smb'), W.handNames('share'), W.handNames('tunnel')],
  [['plex', 'sonarr'], ['fotosync', 'plex', 'sonarr'], ['rsync'], ['Lab', 'Win11'], [], [], []]);

console.log(JSON.stringify(out));
