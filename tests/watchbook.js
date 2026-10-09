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
const T = (k, p) => k + (p ? ' ' + JSON.stringify(p) : '');
let has = () => true;
globalThis.Office = {
  scope: (desk) => (k, p) => T(`${desk}.${k}`, p), t: T, el: () => ({}), store: () => null, storeJson: () => null,
  desk: () => {}, places: () => {}, placesFrom: () => {}, placesTook: () => {}, has: (k) => has(k),
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

console.log(JSON.stringify(out));
