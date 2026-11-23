/* The Night Watchman — the office's security department. His page: the last
   round (when, the next one, what is open); how secure it stands (his posture
   tips: what he would set differently, with why, a link into Unraid and «I
   know, thanks» kept on the server); what changed — the watch book (newest
   first, under day headings; entries of one kind and area on one day fold into
   one row; a filter for its words and one for its area over the whole book; a
   row unfolds to its details; «I know, thanks» per entry, per folded row and
   «Note all») and what he keeps an eye on (what is normal, summarised; also what
   starts on its own: crontabs, the plugins' .cron files, User Scripts, at,
   notification agents; the data flow: who pulls how much, containers, what is
   written into ZFS shares, SMB's users and machines — with a link to its
   history in Grafana where the office's dashboard is; the snapshots he
   follows: gone or released without the office; the network: what the router
   sends to Unraid's syslog server — its senders, devices, detections, blocks,
   counts only for other devices, the «Whole LAN» switch). His rounds run on the
   server every five minutes, read only: agent/desks/watchman.php (the network:
   agent/lib/watchnet.php). Everything from his state goes into the page as text,
   never as HTML. */
(() => {
'use strict';

const ID = 'watchman';
const T = Office.scope(ID);
const { el, fmt } = Office;
const POLL_MS = 2000;
const PAGE = 30;                // rows of the book shown at first (and per «Show more»)
/** The kernel's names of CPU flaws (/sys/devices/system/cpu/vulnerabilities) as people know them */
const VULN = {
  spectre_v1: 'Spectre v1', spectre_v2: 'Spectre v2', spec_store_bypass: 'Speculative Store Bypass', vmscape: 'VMScape',
  spec_rstack_overflow: 'SRSO (Inception)', retbleed: 'Retbleed', mds: 'MDS', meltdown: 'Meltdown', l1tf: 'L1TF',
  tsa: 'TSA', gather_data_sampling: 'Downfall (GDS)', mmio_stale_data: 'MMIO Stale Data', reg_file_data_sampling: 'RFDS',
  srbds: 'SRBDS', tsx_async_abort: 'TAA', itlb_multihit: 'iTLB Multihit', indirect_target_selection: 'ITS',
  ghostwrite: 'GhostWrite', old_microcode: 'old microcode',
};

let state = null;
let view = null;
let timer = null;
let before = null;              // entry ids before a round this page asked for: say how many came
let beforeLast = null;          // … and when the last round ended then: «done» only for a round that ended after it
let shown = PAGE;
// the book's filter bar (core.js Office.filterBar, built once): its words are never kept (a new visit starts with the
// whole book; «only open» is the tile «Not noted yet»), its area select is a choice, kept in this browser
// ('watchman.book.pick')
let bookBar = null;
const foldsOpen = new Set();    // rows of the book that fold several entries, unfolded on this visit (their keys)
const foldsShut = new Set();    // … folded again by hand while filter words unfold them all
const openGroups = Office.storeJson('watchman.groups') || {};     // groups of «What I keep an eye on» that are open
const unfolded = new Set();     // entries unfolded on this visit
let bookRows = [];              // the book's unfoldable rows: {open(), set(open)}
let bookLabel = null;           // brings «Unfold all» up to date
let watchGroups = [];           // the groups of «What I keep an eye on» drawn now: {open(), set(open)}
// «What I keep an eye on»'s bar (core.js Office.filterBar, built once like the book's and held across the renders): its
// words are never kept — a new visit starts with the whole list, the groups folded as kept in this browser
let watchBar = null;
let watchWords = [];            // the bar's words while the list is drawn (group() reads them)
const watchShut = new Set();    // groups folded by hand while filter words unfold them (forgotten with the words)
let showKnownTips = false;      // posture tips you know about: shown on request
let wanted = null;              // an entry the search asked for (#/watchman/entry/<id>): the book shows rows down to it

const openCount = () => Object.values((state && state.open) || {}).reduce((a, b) => a + (Number(b) || 0), 0);
const postureOpen = () => Number((state && state.posture && state.posture.open) || 0);
const running = () => !!(state && state.round && state.round.running);
const hired = () => !!(Office.desks.get(ID) || {}).hired;

// ------------------------------------------------------------------ loading
/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  mood();
  if (view) Office.keepInPlace(null, render);
  follow();
}

/** His picture: the lantern — with a mark while something in the book is not noted */
function mood() {
  if (state) Office.setDeskMood(ID, openCount() ? 'alert' : '');
}

/** While a round or a look at what holds the array runs (and his page is shown), look again every two seconds; say when a round is done */
function follow() {
  clearTimeout(timer);
  if (!view) return;
  if (running() || holdRunning()) {
    timer = setTimeout(() => load(true), POLL_MS);
    return;
  }
  if (before) {
    const n = (state.book || []).filter((e) => !before.has(e.id)).length;
    const r = state.round || {};
    // «done» only when a round really ended after the click: one stopped or crashed wrote none (truth review T29)
    const ended = r.last && r.last !== beforeLast;
    before = null;
    if (r.failed || r.broken || !ended) Office.toast(T(r.broken && r.broken.why === 'stopped' ? 'round_stopped' : 'round_failed'), true);
    else Office.toast(n ? T('round_done_new', { n }) : T('round_done'));
  }
}

async function roundNow() {
  if (running() || !Office.agent.running || !hired()) return;
  const ids = new Set(((state && state.book) || []).map((e) => e.id));
  const last = (state && state.round && state.round.last) || null;
  const j = await Office.api.post(`${ID}.round`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  before = ids;
  beforeLast = last;
  if (j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  follow();
}

// ------------------------------------------------------------------ «I know, thanks»
// ------------------------------------------------------------------ his setup (1.96 step 5)
/*
 * The second page «Setup»: the same areas again on a switcher of its own (#/watchman/setup/<area>), no lights but how
 * each stands — «defaults», «n changed», «off», its exceptions. An area's section: «Watch this area» (off only after
 * a clear question naming what you won't see), its settings (each with its default in words; «Apply», «Back to the
 * defaults»), its exceptions with «Add an exception…» (the pilot: logins — a net, an address, a user from a net) and
 * your lasting answers. «Everything changed» first: every setting off its default, every area off, every exception —
 * the one place to see everything he no longer says. Whoever never opens it notices nothing of it.
 */
const setupAreas = () => CATS.filter(catShown);
const setupSw = Office.sections({
  id: `${ID}/setup`,
  key: `${ID}.setup.tile`,
  bodyId: 'wm-setup-areas',
  grid: 'stats wm-setup-tiles',
  default: 'changed',
  tiles: () => [
    { id: 'changed', key: 'setup.changed', place: 'setup.changed', tile: (a) => changedTile(a), section: () => changedSection() },
    ...setupAreas().map((id) => ({ id, key: `cat.${id}`, place: `setup.${id}`, tile: (a) => setupTile(id, a), section: () => setupSection(id) })),
  ],
  wants: (sub) => CATS.includes(sub),
});
function renderSetup(root) {
  root.appendChild(Office.pageHelp(`${ID}-setup`, [
    [T('help.setup'), T('help.setup_text')],
    [T('help.setup_off'), T('help.setup_off_text')],
    [T('help.setup_rules'), T('help.setup_rules_text')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  root.appendChild(setupSw.el);
  setupSw.render();
}
/** What differs from the defaults in an area: its settings off their default */
const changedOf = (id) => Object.entries((state && state.settings && state.settings[id]) || {}).filter(([, v]) => v && v.value !== v.def);
const rulesOf = (id) => ((state && state.rules) || []).filter((r) => r && r.cat === id);
const offAt = (id) => Number(state && state.off && state.off[id]) || 0;
function setupTile(id, a) {
  const n = changedOf(id).length;
  const r = rulesOf(id).length;
  const value = offAt(id) ? T('setup.off') : n ? T('setup.changed_n', { n }) : T('setup.default');
  return Office.stat({ label: catName(id), value, alert: !!offAt(id), sub: r ? T('setup.rules_n', { n: r }) : '', ...a });
}
function changedTile(a) {
  const n = setupAreas().reduce((x, id) => x + changedOf(id).length + rulesOf(id).length + (offAt(id) ? 1 : 0), 0);
  return Office.stat({ label: T('setup.changed'), value: n ? T('setup.changes_n', { n }) : T('setup.none'), ...a });
}
/** «Everything changed»: per area what is off its default, switched off, its exceptions */
function changedSection() {
  const s = el('section', 'section wm-setup');
  s.appendChild(Office.sectionHead(T('setup.changed'), T('setup.changed_sub'), { place: 'setup.changed' }));
  const box = el('div', 'box');
  let any = false;
  setupAreas().forEach((id) => {
    const lines = [];
    if (offAt(id)) lines.push(T('setup.off_since', { when: fmt.date(offAt(id)) }));
    changedOf(id).forEach(([k, v]) => lines.push(T('setup.changed_line', { what: T(`setting.${id}.${k}`), value: settingWords(id, k, v, v.value), def: settingWords(id, k, v, v.def) })));
    rulesOf(id).forEach((r) => lines.push(offerName(r)));
    if (!lines.length) return;
    any = true;
    const row = el('div', 'row nocheck wm-setup-row');
    const main = el('div', 'row-main');
    const name = el('button', 'btn small plain wm-setup-to', catName(id));
    name.type = 'button';
    name.onclick = () => setupSw.pick(id, 'click');
    main.appendChild(name);
    lines.forEach((x) => main.appendChild(el('div', 'row-detail', x)));
    row.appendChild(main);
    box.appendChild(row);
  });
  if (!any) box.appendChild(el('p', 'empty', T('setup.nothing')));
  s.appendChild(box);
  return s;
}
/** An area's setup: watch it or not, its settings, its exceptions (by hand too), your answers */
function setupSection(id) {
  const s = el('section', 'section wm-setup');
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !offAt(id);
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('setup.watch_switch')));
  label.title = T('setup.watch_switch_title');
  cb.onchange = () => catOffAsk(id, !cb.checked, cb);
  s.appendChild(Office.sectionHead(catName(id), T(`cat.${id}_sub`), label));
  if (offAt(id)) s.appendChild(el('p', 'callout', T('setup.off_since', { when: fmt.date(offAt(id)) })));
  const set = (state.settings && state.settings[id]) || null;
  if (set && Object.keys(set).length) s.appendChild(settingsBox(id, set));
  const rules = catRules(id);
  if (rules) s.appendChild(rules);
  if (handTypes(id).length) {
    const add = el('button', 'btn small', T('setup.add'));
    add.type = 'button';
    add.disabled = !Office.agent.running || !hired();
    add.onclick = () => ruleHandDialog(id, add);
    const acts = el('div', 'wm-setup-acts');
    acts.appendChild(add);
    s.appendChild(acts);
  }
  const known = catKnown(id);
  if (known) s.appendChild(known);
  return s;
}
/** A setting's value in words: a number with its unit, a switch on or off, a choice by its name */
function settingWords(id, k, v, x) {
  if (typeof x === 'boolean') return T(x ? 'setup.switch_on' : 'setup.switch_off');
  if (typeof x === 'string') return T(`setting.${id}.${k}.${x}`);
  return T(`setup.unit.${v.unit || 'n'}`, { n: x });
}
/** An area's settings: a field each (a number, a switch, a choice) with its default in words, «Apply» and «Back to the defaults» */
function settingsBox(id, set) {
  const box = el('div', 'box wm-settings');
  const fields = {};
  Object.entries(set).forEach(([k, v]) => {
    const row = el('div', 'row nocheck wm-setting');
    let inp;
    if (typeof v.def === 'boolean') {
      inp = el('input');
      inp.type = 'checkbox';
      inp.checked = !!v.value;
      fields[k] = () => inp.checked;
    } else if (typeof v.def === 'string') {
      inp = el('select', 'picker');
      (v.of || []).forEach((x) => inp.appendChild(new Option(T(`setting.${id}.${k}.${x}`), x)));
      inp.value = v.value;
      fields[k] = () => inp.value;
    } else {
      inp = el('input', 'search wm-setting-num');
      inp.type = 'number';
      inp.min = String(v.min);
      inp.max = String(v.max);
      inp.step = '1';
      inp.value = String(v.value);
      fields[k] = () => { const n = Number(inp.value); return Number.isInteger(n) ? n : null; };
    }
    inp.setAttribute('aria-label', T(`setting.${id}.${k}`));
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', T(`setting.${id}.${k}`)));
    main.appendChild(el('div', 'row-meta', typeof v.def === 'number'
      ? T('setup.def', { value: settingWords(id, k, v, v.def), min: settingWords(id, k, v, v.min), max: settingWords(id, k, v, v.max) })
      : T('setup.def_value', { value: settingWords(id, k, v, v.def) })));
    row.append(main, inp);
    box.appendChild(row);
  });
  const acts = el('div', 'wm-entry-acts');
  const apply = el('button', 'btn small', T('setup.apply'));
  apply.type = 'button';
  apply.disabled = !Office.agent.running || !hired();
  apply.onclick = () => {
    const out = {};
    for (const [k, get] of Object.entries(fields)) {
      const x = get();
      if (x === null) { Office.toast(T('setup.not_number'), true); return; }
      out[k] = x;
    }
    catSet(id, { set: out }, apply);
  };
  const back = el('button', 'btn small plain', T('setup.reset'));
  back.type = 'button';
  back.disabled = apply.disabled || !changedOf(id).length;
  back.onclick = () => catSet(id, { reset: true }, back);
  acts.append(apply, back);
  box.appendChild(acts);
  return box;
}
async function catSet(id, body, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.cat_set`, { cat: id, ...body });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
  if (j.state) state = j.state;
  renderAt('.wm-setup');
  Office.toast(T(body.reset ? 'setup.reset_done' : 'setup.saved'));
}
/** «Watch this area» off: one clear question naming what you won't see any more; on again at once */
function catOffAsk(id, off, cb) {
  const go = async () => {
    cb.disabled = true;
    const j = await Office.api.post(`${ID}.cat_off`, { cat: id, off, sure: off });
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); cb.checked = off; cb.disabled = false; return; }
    if (j.state) state = j.state;
    mood();
    renderAt('.wm-setup');
    Office.toast(T(off ? 'setup.turned_off' : 'rule.cat_on_done'));
  };
  if (!off) { go(); return; }
  const body = el('div', 'wm-rule-ask');
  body.append(el('p', 'callout warn', T('setup.off_ask', { area: catName(id) })), el('p', '', T(`cat.${id}_sub`)), el('p', '', T('setup.off_note')));
  Office.dialog({ title: T('setup.off_title'), body, onClose: () => { cb.checked = !offAt(id); }, buttons: [
    { text: T('setup.off_yes', { area: catName(id) }), kind: 'danger', act: () => { go(); } },
    { text: Office.t('common.cancel') },
  ] });
}
/** The exceptions an area takes by hand (the messenger's list, WATCH_HAND) */
const handTypes = (id) => ((state && state.hand && state.hand[id]) || []).filter((x) => typeof x === 'string');
const HAND_EXAMPLE = { net: '192.168.1.0/24', ip: '203.0.113.7', user_net: '192.168.1.0/24', ports: 'plex', rights: 'plex', source: 'github.com/…',
  script: '…', cron_file: '…/….cron', crond: '…', client: '192.168.1.20', client_min: '192.168.1.20', container: 'plex', disk_gone: 'disk1',
  disk_written: 'disk1', series: 'zfs-auto-snap_hourly-#', series_ds: 'tank/Media', dataset: 'tank/Media', hold: 'tank/Media', extra: '….txz',
  guest_read: 'Media', guest_write: 'Media', port: '8384', prog: 'syncthing', tunnel: 'wg0' };
const HAND_WIDE = { login: 'setup.add_public', plugin: 'setup.add_wide.plugin', data: 'setup.add_wide.data', snap: 'setup.add_wide.snap', share: 'setup.add_wide.share' };
// a second field: a user from a net, a series on a dataset
const HAND_SECOND = { user_net: ['setup.add_user', 'root'], series_ds: ['setup.add_second.series_ds', 'zfs-auto-snap_hourly-#'],
  guest_read: ['setup.add_second.proto', 'SMB'], guest_write: ['setup.add_second.proto', 'SMB'] };
const handAmount = (t) => (state && state.hand_amount && state.hand_amount[t]) || null;
/**
 * «Add an exception…» by hand: what kind of exception (the area's types), its value typed, a user for «a user from a net»
 * — the messenger checks it (a public net at most a /24, a public address or net or a whole plugin source only with the
 * clear yes, said in the dialog)
 */
function ruleHandDialog(id, b) {
  const types = handTypes(id);
  const body = el('div', 'wm-rule-hand');
  const type = el('select', 'picker');
  types.forEach((x) => type.appendChild(new Option(T('setup.add_type.' + x), x)));
  type.setAttribute('aria-label', T('setup.add_type_label'));
  const value = el('input', 'search');
  value.type = 'text';
  const user = el('input', 'search');
  user.type = 'text';
  user.placeholder = 'root';
  user.setAttribute('aria-label', T('setup.add_user'));
  const userRow = el('label', 'wm-hand-row');
  const userName = el('span', '', T('setup.add_user'));
  userRow.append(userName, user);
  const valueName = el('span', '');
  const amount = el('input', 'search wm-setting-num');
  amount.type = 'number';
  amount.min = '1';
  amount.step = '1';
  const amountName = el('span', '');
  const amountRow = el('label', 'wm-hand-row');
  amountRow.append(amountName, amount);
  const login = ['net', 'ip', 'user_net'];
  const netNot = el('p', 'role', T('rule.ask_net_not'));
  const sync = () => {
    const t = type.value;
    userRow.hidden = !HAND_SECOND[t];
    if (HAND_SECOND[t]) {
      userName.textContent = T(HAND_SECOND[t][0]);
      user.placeholder = HAND_SECOND[t][1];
      user.setAttribute('aria-label', userName.textContent);
    }
    netNot.hidden = !login.includes(t);
    value.placeholder = HAND_EXAMPLE[t] || '';
    valueName.textContent = login.includes(t) ? T('setup.add_value') : T('setup.add_value.' + t);
    value.setAttribute('aria-label', valueName.textContent);
    const unit = handAmount(t);
    amountRow.hidden = !unit;
    if (unit) {
      amountName.textContent = T('setup.add_amount.' + unit);
      amount.setAttribute('aria-label', amountName.textContent);
    }
  };
  type.addEventListener('change', sync);
  const sure = el('input');
  sure.type = 'checkbox';
  const sureRow = el('label', 'switch');
  sureRow.append(sure, el('span', '', HAND_WIDE[id] ? T(HAND_WIDE[id]) : ''));
  sureRow.hidden = !HAND_WIDE[id];
  const tr = el('label', 'wm-hand-row');
  tr.append(el('span', '', T('setup.add_type_label')), type);
  const vr = el('label', 'wm-hand-row');
  vr.append(valueName, value);
  body.append(tr, vr, amountRow, userRow, netNot, sureRow);
  sync();
  Office.dialog({ title: T('setup.add'), body, buttons: [
    { text: T('rule.add'), kind: 'primary', act: () => {
      (async () => {
        b.disabled = true;
        const n = Number(amount.value);
        if (handAmount(type.value) && !(Number.isInteger(n) && n >= 1)) { b.disabled = false; Office.toast(T('errors.watch_amount_bad'), true); return; }
        const j = await Office.api.post(`${ID}.rule_hand`, { cat: id, type: type.value, value: value.value.trim(), user: user.value.trim(), sure: sure.checked,
          ...(handAmount(type.value) ? { amount: n } : {}) });
        b.disabled = false;
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
        if (j.state) state = j.state;
        if (j.rule) justMade.add(j.rule);
        renderAt('.wm-setup');
        Office.toast(T('rule.added'));
      })();
    } },
    { text: Office.t('common.cancel') },
  ] });
}

// ------------------------------------------------------------------ exceptions (1.96, agent/desks/watchman-rules.php)
const OFFERS = ['key', 'ip', 'net', 'user_net', 'machine', 'source', 'file', 'cmd', 'share', 'disk', 'min', 'kept', 'layers', 'series_ds', 'series', 'hold', 'open',
  'prog', 'tunnel'];
// kinds whose key is «<kind>:<the thing's name>»: an exception for exactly it reads «The ports of plex may change» (6a)
const KEY_NAMED = ['container_ports', 'container_rights', 'script_changed', 'cron_file', 'flow_client', 'flow_container', 'flash_extra', 'listen_new',
  'container_privileged'];
/** A named key's thing in words: a client of the data flow «192.168.1.5 (SMB)» (its key: ip|service), else the name */
function keyThing(kind, name) {
  if (kind === 'listen_new') return name.replace(/^tcp:/, '');
  if (kind !== 'flow_client') return name;
  const [ip, svc] = name.split('|');
  return svc ? `${ip} (${({ smb: 'SMB', nfs: 'NFS', ssh: 'SSH', web: 'WebGUI' })[svc] || svc})` : ip;
}
/** What a threshold of the data flow is about, in words: a share's or disk's gone / written / files, a computer, a container */
function flowWhat(o) {
  const on = o.on || {};
  const kind = (o.kinds || [])[0] || '';
  const opt = o.opt || {};
  if (on.share || on.disk) {
    const what = on.share || on.disk;
    return T(opt.files !== undefined ? 'rule.flow.files' : kind === 'flow_written' ? 'rule.flow.written' : 'rule.flow.gone', { what });
  }
  if (on.ip) return on.ip;
  if (typeof on.key === 'string') {
    const named = KEY_NAMED.find((k) => on.key.startsWith(`${k}:`));
    if (named) return keyThing(named, on.key.slice(named.length + 1));
  }
  return '…';
}
const justMade = new Set();     // exceptions made on this visit: «Undo» beside «Lift» (their entries open again)
/**
 * An exception in words: «192.168.1.0/24 always okay», «root from 192.168.1.0/24 …»; exactly one thing: on its own
 * entry «This one always okay», elsewhere (the list, the dialog) «‹the entry, shortened› always okay»
 */
function offerName(o, e, here) {
  const on = o.on || {};
  if (on.open) return T(`rule.offer.open_${on.open === 'write' ? 'write' : 'read'}`, { share: on.share, proto: String(on.proto || '').toUpperCase() });
  if (on.prog) return T('rule.offer.prog', { prog: on.prog });
  if (on.tunnel) return T('rule.offer.tunnel', { tunnel: on.tunnel });
  if (on.series) return on.dataset ? T('rule.offer.series_ds', { series: on.series, dataset: on.dataset }) : T('rule.offer.series', { series: on.series });
  if (on.dataset) return T((o.kinds || [])[0] === 'snap_hold_released' ? 'rule.offer.hold' : 'rule.offer.dataset', { dataset: on.dataset });
  if (on.user) return T('rule.offer.user_net', { user: on.user, net: on.net });
  if (on.net) return T('rule.offer.net', { net: on.net });
  if (on.ip && !(o.opt) && (o.kinds || []).length === 1 && o.kinds[0] === 'flow_client') return T('rule.key.flow_client', { name: on.ip });
  if (on.ip && !(o.opt)) return T('rule.offer.ip', { ip: on.ip });
  if (on.machine) return T('rule.offer.machine', { machine: on.machine });
  if (on.source) return T('rule.offer.source', { source: on.source });
  if (on.file) return T('rule.offer.file', { file: on.file });
  if (on.cmd) return T('rule.offer.cmd', { cmd: o.what || '…' });
  const opt = o.opt || {};
  if (opt.min !== undefined) return T('rule.offer.min', { what: flowWhat(o), amount: fmt.size(opt.min) });
  if (opt.files !== undefined) return T('rule.offer.min', { what: flowWhat(o), amount: Number(opt.files).toLocaleString() });
  if (opt.kept === false) return T('rule.offer.kept', { share: on.share || '' });
  if (opt.layers === false) return T('rule.offer.layers', { share: on.share || '' });
  if (on.share) return T(`rule.offer.share.${(o.kinds || [])[0] === 'flow_written' ? 'flow_written' : 'flow_gone'}`, { share: on.share });
  if (on.disk) return T(`rule.offer.disk.${(o.kinds || [])[0] === 'flow_written' ? 'flow_written' : 'flow_gone'}`, { disk: on.disk });
  const named = typeof on.key === 'string' ? KEY_NAMED.find((k) => on.key.startsWith(`${k}:`)) : null;
  if (named && (o.kinds || []).length === 1 && o.kinds[0] === named) {
    const thing = on.key.slice(named.length + 1);
    // a program on changing ports (its key «prog:*»): its own words
    if (named === 'listen_new' && thing.endsWith(':*')) return T('rule.offer.prog', { prog: thing.slice(0, -2) });
    return T(`rule.key.${named}`, { name: keyThing(named, thing) });
  }
  if (here) return T('rule.offer.key');
  const src = e || ((state && state.book) || []).find((x) => x && x.id === o.from);
  const what = src ? entryName(src) : '';
  return what ? T('rule.key_of', { what: what.length > 70 ? what.slice(0, 69) + '…' : what }) : T('rule.offer.key');
}
/**
 * «… always okay» on an open entry: what it covers, what not, where it is lifted — asked once for anything wider than
 * this one thing, and more clearly for the kinds he asks about (the button names it); the messenger looks the offer up
 * anew from the entry (never trusts a net or a name from the page)
 */
async function ruleAdd(e, o, b) {
  if (!(await Office.freshState(ID))) return;
  const what = offerName(o, e);
  const n = Math.max(1, Number(o.covers) || 1);
  const go = async (sure) => {
    b.disabled = true;
    const j = await Office.api.post(`${ID}.rule_add`, { id: e.id, type: o.type, sure });
    if (!j.ok) {
      Office.toast(Office.errorText(j.error, ID), true);
      if (j.error && j.error.key === 'watch_gone') await load(true);
      else b.disabled = false;
      return;
    }
    if (j.state) state = j.state;
    if (j.rule) justMade.add(j.rule);
    mood();
    renderKeeping(e.id);
    const more = Math.max(0, (Number(j.noted) || 1) - 1);
    Office.toast(more ? `${T('rule.added')} ${T('rule.added_more', { n: more })}` : T('rule.added'));
  };
  if (!o.ask && o.type === 'key' && n === 1) { go(false); return; }
  const body = el('div', 'wm-rule-ask');
  const lines = [T('rule.ask_covers', { what, n }), o.type === 'net' || o.type === 'ip' ? T('rule.ask_net_not') : '',
    o.ask ? T('rule.ask_serious') : '', e.again ? T('rule.ask_incident') : '',
    (o.kinds || []).some((k) => k === 'flow_gone') ? T('rule.ask_must') : '', T('rule.ask_where')];
  lines.filter(Boolean).forEach((x) => body.appendChild(el('p', o.ask && x === T('rule.ask_serious') ? 'callout warn' : '', x)));
  Office.dialog({ title: T('rule.ask_title'), body, buttons: [
    { text: o.ask ? T('rule.ask_yes', { what }) : T('rule.add'), kind: 'primary', act: () => { go(!!o.ask); } },
    { text: Office.t('common.cancel') },
  ] });
}
/** «Lift» an exception (undo: right after it was made — its entries open again) */
async function ruleRemove(r, undo, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.rule_remove`, { rule: r.id, undo });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_rule_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  justMade.delete(r.id);
  mood();
  renderAt('.wm-rules');
  Office.toast(T(undo ? 'rule.undone' : 'rule.lifted'));
}
/** An area switched on again (switched off: in his setup, asked more clearly) */
async function catOn(id, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.cat_off`, { cat: id, off: false });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
  if (j.state) state = j.state;
  mood();
  renderAt('.wm-cat');
  Office.toast(T('rule.cat_on_done'));
}
/**
 * «Noted (n)»: your lasting answers in an area (1.96, concept 3.4) — folded until opened on this visit; each with when,
 * and «Take back» (it comes out of what is normal: still there, his next round tells it)
 */
const knownOpen = new Set();      // areas whose list is unfolded on this visit
function catKnown(id) {
  const list = ((state && state.known) || []).filter((k) => k && k.cat === id);
  if (!list.length) return null;
  const box = el('div', 'wm-known');
  const head = el('button', 'btn small plain wm-known-head', `${knownOpen.has(id) ? '▾' : '▸'} ${T('known.list', { n: list.length })}`);
  head.type = 'button';
  head.setAttribute('aria-expanded', knownOpen.has(id) ? 'true' : 'false');
  head.onclick = () => { if (knownOpen.has(id)) knownOpen.delete(id); else knownOpen.add(id); Office.keepInPlace(head, () => sw.render()); };
  box.appendChild(head);
  if (!knownOpen.has(id)) return box;
  const rows = el('div', 'box');
  list.slice().sort((a, b) => b.at - a.at).slice(0, 200).forEach((k) => {
    const row = el('div', 'row nocheck wm-rule');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', entryName({ kind: k.kind, p: k.p || {}, t: k.t || {}, v: null })));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('known.since', { when: fmt.date(k.at) })));
    main.appendChild(meta);
    row.appendChild(main);
    const acts = el('div', 'wm-entry-acts');
    const b = el('button', 'btn small plain', T('known.take_back'));
    b.type = 'button';
    b.title = T('known.take_back_title');
    b.disabled = !Office.agent.running || !hired();
    b.onclick = () => knownRemove(k, b);
    acts.appendChild(b);
    row.appendChild(acts);
    rows.appendChild(row);
  });
  box.appendChild(rows);
  return box;
}
async function knownRemove(k, b) {
  if (!(await Office.freshState(ID))) return;
  b.disabled = true;
  const j = await Office.api.post(`${ID}.known_remove`, { id: k.id });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_known_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  renderAt('.wm-known');
  Office.toast(T('known.taken_back'));
}

/** An area's exceptions: what each lets through, since when, how often it did, «Lift» (and «Undo» right after) */
function catRules(id) {
  const list = ((state && state.rules) || []).filter((r) => r && r.cat === id);
  if (!list.length) return null;
  const box = el('div', 'wm-rules');
  box.appendChild(el('h4', 'wm-hold-group', T('rule.list', { n: list.length })));
  const rows = el('div', 'box');
  list.forEach((r) => {
    const row = el('div', 'row nocheck wm-rule');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', offerName(r)));
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('rule.since', { when: fmt.date(r.at) })));
    meta.appendChild(el('span', '', r.hits ? T('rule.hits', { n: r.hits, when: fmt.relative(r.hit) }) : T('rule.no_hits')));
    main.appendChild(meta);
    row.appendChild(main);
    const acts = el('div', 'wm-entry-acts');
    const mk = (undo) => {
      const b = el('button', 'btn small plain', T(undo ? 'rule.undo' : 'rule.lift'));
      b.type = 'button';
      b.title = T(undo ? 'rule.undo_title' : 'rule.lift_title');
      b.disabled = !Office.agent.running || !hired();
      b.onclick = () => ruleRemove(r, undo, b);
      return b;
    };
    if (justMade.has(r.id)) acts.appendChild(mk(true));
    acts.appendChild(mk(false));
    row.appendChild(acts);
    rows.appendChild(row);
  });
  box.appendChild(rows);
  return box;
}

async function ack(e, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.ack`, { id: e.id });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  mood();
  renderKeeping(e.id);
  Office.toast(T('acked'));
}

/**
 * «This was a real incident…»: closes the entry as a real incident (nothing of it becomes normal), with an optional
 * one-line note; it goes into his history of serious incidents and, while it is there, the same from the same source is
 * reported at once and as important.
 * The dialog says what follows and offers «I know, thanks» ($ab, the row's button) as the other answer.
 */
function seriousAck(e, b, ab) {
  const box = el('div');
  box.appendChild(el('p', 'wm-serious-what', entryName(e)));
  box.appendChild(el('p', 'role', T('serious_text')));
  const f = el('div', 'field');
  const label = el('label', '', T('serious_note'));
  const note = el('input', 'input');
  note.type = 'text';
  note.maxLength = 200;
  note.placeholder = T('serious_note_ph');
  note.autocomplete = 'off';
  note.id = 'wm-serious-note';
  label.htmlFor = note.id;
  f.append(label, note);
  box.appendChild(f);
  Office.dialog({
    title: T('serious_dialog_title'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('serious_confirm'), kind: 'plain danger', act: async () => {
      if (!Office.agent.running) return false;
      const j = await Office.api.post(`${ID}.ack_serious`, { id: e.id, note: note.value.trim() });
      if (!j.ok) {
        Office.toast(Office.errorText(j.error, ID), true);
        if (j.error && j.error.key === 'watch_gone') { await load(true); return true; }
        return false;
      }
      if (j.state) state = j.state;
      mood();
      renderKeeping(e.id);
      Office.toast(T('serious_done'));
      return true;
    } }, { text: T('ack'), kind: '', act: async () => {
      if (!Office.agent.running) return false;
      await ack(e, ab);
      return true;
    } }],
  });
  if (b) b.blur();
}

/**
 * «Not serious after all…» on a row of his list of serious incidents: it leaves the list, the same is reported as usual again, and it
 * ends as «I know, thanks» would have left it — also what came back louder since from the same source
 */
async function seriousUndo(shown, b) {
  if (!(await Office.freshState(ID))) return;
  const x = ((state && state.incidents) || []).find((y) => y && y.id === shown.id);
  if (!x) { Office.toast(T('errors.watch_incident_gone'), true); renderAt('.wm-incidents'); return; }
  const box = el('div');
  box.appendChild(el('p', 'wm-serious-what', entryName(x)));
  box.appendChild(el('p', 'role', T('serious_undo_text')));
  if (x.again) box.appendChild(el('p', 'role', T('serious_undo_again', { n: x.again })));
  Office.dialog({
    title: T('serious_undo'),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('serious_undo_confirm'), kind: '', act: async () => {
      if (!Office.agent.running) return false;
      const j = await Office.api.post(`${ID}.serious_undo`, { id: x.id });
      if (!j.ok) {
        Office.toast(Office.errorText(j.error, ID), true);
        if (j.error && (j.error.key === 'watch_incident_gone' || j.error.key === 'watch_gone')) { await load(true); return true; }
        return false;
      }
      if (j.state) state = j.state;
      mood();
      renderAt('.wm-incidents', '.wm-book');
      Office.toast(T('serious_undo_done'));
      return true;
    } }],
  });
  if (b) b.blur();
}

/** His serious incidents: each makes the same from the same source reported at once and as important while it is on the
 * list (no end since 2026-11-20 — they count in «How secure does it stand?») */
const louderCount = () => ((state && state.incidents) || []).filter(Boolean).length;

/** «All n: I know, thanks» on a row that folds several entries: those of it still open, in one request (ack_some) */
async function ackSome(list, b, key) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.ack_some`, { ids: list.map((e) => e.id) });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  mood();
  renderAt(`.wm-fold-head[data-fold="${CSS.escape(key)}"]`, '.wm-book');
  Office.toast(T('acked_all', { n: Number(j.noted) || list.length }));
}

async function ackAll() {
  if (!(await Office.freshState(ID))) return;      // «all» means what is in his book now: never a stale look at it
  const n = openCount();
  if (!n || !Office.agent.running) return;
  Office.dialog({
    title: T('ack_all'),
    body: el('p', '', T('ack_all_text', { n })),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('ack_all'), kind: '', act: async () => {
      const j = await Office.api.post(`${ID}.ack_all`, {});
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
      if (j.state) state = j.state;
      mood();
      renderKeeping(null);
      Office.toast(T('acked_all', { n: Number(j.noted) || n }));
      return true;
    } }],
  });
}

/** Re-render without the page jumping: the row that was noted stays where it is (or, gone from the list, the book) */
function renderKeeping(id) {
  renderAt(id && `.wm-entry[data-id="${CSS.escape(id)}"]`, '.wm-book');
}

/** Re-render; the first of these that is there stays where it was on screen */
function renderAt(...selectors) {
  if (!view) return;
  const find = () => selectors.map((x) => x && view.querySelector(x)).find(Boolean);
  const a = find();
  const top = a ? a.getBoundingClientRect().top : null;
  Office.keepInPlace(null, render);
  const b = find();
  if (top !== null && b) {
    const d = b.getBoundingClientRect().top - top;
    if (Math.abs(d) > 1) window.scrollBy(0, d);
  }
}

// ------------------------------------------------------------------ rendering
// greet.1 «Evening.» only in the evening and the night (17:00–05:59 on the reader's clock — 2026-10-18: a bubble is true
// right now); the others are his round's, said at any hour
const greetOk = (key, hour = new Date().getHours()) => key !== 'greet.1' || hour >= 17 || hour < 6;

function bubbleText() {
  if (!state) return T('bubble.loading');
  if (running()) return T('bubble.touring');
  if (!state.on_watch) return T('bubble.first');
  const n = openCount();
  const tips = postureOpen() ? ' ' + T('bubble.posture', { n: postureOpen() }) : '';
  if (n) return T('bubble.open', { n }) + tips;
  if (state.round && (state.round.failed || state.round.broken)) return T('bubble.failed') + tips;
  // the syslog stopped growing: no logins seen is no «all quiet» (T30)
  if (state.round && state.round.syslog_full) return T('bubble.syslog_full', { when: fmt.relative(state.round.last) }) + tips;
  return T('bubble.quiet', { when: fmt.relative(state.round && state.round.last) }) + tips;
}

function chip(text, cls, tip) {
  const c = el('span', 'chip' + (cls ? ' ' + cls : ''), text);
  if (tip) c.title = tip;
  return c;
}

function render() {
  const root = view;
  if (!root) return;
  root.innerHTML = '';
  bookRows = [];
  watchGroups = [];
  const btn = el('button', 'btn plain' + (running() ? ' running' : ''));
  btn.type = 'button';
  btn.append(el('span', 'spin'), T('round_now'));
  btn.title = T('round_now_title');
  btn.disabled = !Office.agent.running || running() || !hired();
  btn.onclick = roundNow;
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: [btn],
    pages: { list: PAGES(), current: page } });
  root.appendChild(head);
  if (page === 'setup') { renderSetup(root); return; }
  const lim = (state && state.limits) || {};
  root.appendChild(Office.pageHelp(ID, [
    [T('help.lights'), T('help.lights_text')],
    [T('help.posture'), T('help.posture_text')],
    [T('help.book'), T('help.book_text', { keep: lim.keep || 500, days: lim.days || 90 })],
    [T('ack'), T('help.ack_text')],
    [T('help.serious'), T('help.serious_text')],
    [T('help.normal'), T('help.normal_text')],
    [T('round_now'), T('help.round_text')],
    [T('help.logins'), T('help.logins_text', { burst: lim.burst || 5, minutes: Math.round((lim.window || 600) / 60) })],
    [T('help.containers'), T('help.containers_text')],
    [T('help.plugins'), T('help.plugins_text')],
    [T('help.office'), T('help.office_text')],
    [T('help.flash'), T('help.flash_text')],
    [T('help.shares'), T('help.shares_text')],
    [T('help.sched'), T('help.sched_text')],
    [T('help.flow'), T('help.flow_text', flowLimits())],
    [T('help.flow_gone'), T('help.flow_gone_text', goneLimits())],
    [T('help.flow_not'), T('help.flow_not_text')],
    [T('help.snaps'), T('help.snaps_text')],
    [T('help.host'), T('help.host_text')],
    [T('help.host_not'), T('help.host_not_text')],
    [T('help.net'), T('help.net_text')],
    [T('help.net_not'), T('help.net_not_text')],
    [T('help.attack'), T('help.attack_text')],
    [T('help.siem'), T('help.siem_text')],
    [T('help.chain'), T('help.chain_text')],
    [T('help.night'), T('help.night_text')],
    [T('help.parity'), T('help.parity_text')],
    [T('help.hold'), T('help.hold_text')],
    [T('help.wakes'), T('help.wakes_text')],
    [T('help.grafana'), T('help.grafana_text')],
    [T('help.notify'), T('help.notify_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  root.append(roundHead(), sw.el);
  sw.render();
}

// ------------------------------------------------------------------ the row: his areas as lights (1.96)
/**
 * His page on core's switcher (CLAUDE.md «Tiles», Office.sections): every tile a light (core.js Office.light) — first
 * the five that cut across (the watch book, how secure it stands, serious incidents, what he keeps an eye on, his
 * schedule), then his areas (agent/desks/watchman-cats.php: state.cats — a light, its reasons, its entries), what holds
 * the array and who woke the disks. The filter above the row (Office.sections({filter})): an area's words (its name,
 * what it looks at, its open entries), «only with a finding». «Watch book» to start; nothing chosen shows every section
 * of the row in its order. Over the row stays the head of his last round. #/watchman/<tile> picks one; the old
 * #/watchman/open (the tile «Not noted yet», gone in 1.96) is the book with «only open» on.
 */
// his areas with entries, in the order of state.cats (hold and wakes have sections of their own); vuln and hw only once
// the messenger has them for real (their steps — `not_yet` until then)
// his two pages (1.96 step 5): the watch and his setup — two tabs (core.js Office.pages)
const PAGES = () => [{ id: '', text: T('page.watch') }, { id: 'setup', text: T('page.setup') }];
let page = '';
const CATS = ['login', 'container', 'plugin', 'flash', 'share', 'sched', 'pull', 'data', 'snap', 'host', 'door', 'partner', 'router', 'lan', 'array', 'vuln', 'hw'];
const CAT_LATER = ['vuln', 'hw'];
// an area's group in «What I keep an eye on» (the jump at the end of its section)
const CAT_WATCH = { login: 'ips', container: 'containers', plugin: 'plugins', flash: 'flash', share: 'shares', sched: 'sched', pull: 'flow_clients',
  data: 'flow_shares', snap: 'snaps', host: 'host', door: 'host', partner: 'partner', router: 'net', lan: 'net' };
const CAT_SEEN = ['open', 'open_tell', 'tips'];        // reasons the light says by itself; the others stand in its section
const FINDING = ['danger', 'warn', 'note'];
const catOf = (id) => (state && state.cats && state.cats[id] && typeof state.cats[id] === 'object' ? state.cats[id] : null);
const catLevel = (id) => { const c = catOf(id); return c ? String(c.level || '') : ''; };
const catName = (id) => T('cat.' + id);
const catShown = (id) => (catOf(id) ? !(catOf(id).why || []).includes('not_yet') : !CAT_LATER.includes(id));
const crossLevel = (id, fallback) => (state && state.cats_cross && state.cats_cross[id]) || fallback;

const sw = Office.sections({
  id: ID,
  grid: 'lights wm-tiles',
  default: 'book',
  tiles: () => [               // a function: the words in the language the page has now, the areas the state has
    { id: 'book', key: 'tile.book', place: 'tile.book', tile: (a) => bookTile(a), section: 'book', words: () => [T('tile.book'), T('book.sub')],
      level: () => crossLevel('book', openCount() ? 'warn' : state ? 'ok' : '') },
    { id: 'posture', key: 'tile.posture', place: 'tile.posture', tile: (a) => postureTile(a), section: () => postureSection(), words: () => [T('tile.posture'), T('posture.sub')],
      level: () => postureLevel() },
    { id: 'incidents', key: 'tile.incidents', place: 'tile.incidents', tile: (a) => incidentsTile(a), section: () => incidentsSection(),
      words: () => [T('tile.incidents'), T('incidents.sub')], level: () => incidentsLevel() },
    { id: 'watch', key: 'tile.watch', place: 'tile.watch', tile: (a) => watchTile(a), section: () => watchSection(), words: () => [T('tile.watch'), T('watch.sub')],
      level: () => '' },
    { ...(roundSchedule()), words: () => [T('tile.schedule'), T('round.sub')], level: () => crossLevel('schedule', '') },
    ...CATS.filter(catShown).map((id) => ({ id, key: `cat.${id}`, place: `tile.${id}`, tile: (a) => catTile(id, a), section: () => catSection(id),
      words: () => catWords(id), level: () => catLevel(id) })),
    { id: 'hold', key: 'tile.hold', place: 'tile.hold', tile: (a) => holdTile(a), section: () => holdSection(), words: () => [T('tile.hold'), T('hold.sub')],
      level: () => catLevel('hold') },
    { id: 'wakes', key: 'tile.wakes', place: 'tile.wakes', tile: (a) => wakesTile(a), section: () => wakesView(), words: () => [T('wakes.title'), T('tile.wakes')],
      level: () => catLevel('wakes') },
  ],
  sections: { book: () => bookSection() },
  filter: { label: () => T('cat.row_filter'), only: () => ({ text: T('cat.row_only'), hint: T('cat.row_only_hint') }),
    words: (x) => (x.words ? x.words() : [x.id]), finding: (x) => FINDING.includes(x.level ? x.level() : '') },
});

/** A tile of his row as a light (core.js Office.light): what the number tile said becomes its mouse-over text */
function lightTile(o) {
  const subs = [].concat(o.sub ?? []).filter((x) => x !== '' && x !== null && x !== undefined);
  const head = o.value !== undefined && o.value !== '' && o.value !== '–' ? `${o.label}: ${o.value}` : String(o.label);
  const { value, sub, alert, ...rest } = o;
  return Office.light({ ...rest, tip: [head, ...subs, ...(o.more || [])].join('\n') });
}

/** An area's light; its mouse-over text: how many open (how many important), the newest, what it couldn't see, when he looked */
function catTile(id, a) {
  return Office.light({ label: catName(id), level: catLevel(id), tip: catTip(id).join('\n'), ...a });
}
function catTip(id) {
  const c = catOf(id);
  if (!c) return [catName(id), T('cat.tip_unknown')];
  const first = c.open ? [T('cat.tip_open', { n: c.open }), c.tell ? T('cat.tip_tell', { n: c.tell }) : ''].filter(Boolean).join(', ')
    : Office.t('common.light.' + (Office.LIGHTS.includes(c.level) ? c.level : 'unknown'));
  const lines = [`${catName(id)}: ${first}`];
  const newest = catEntries(id).filter((e) => e.open).sort((x, y) => y.last - x.last)[0];
  if (newest) {
    const what = entryName(newest);
    lines.push(T('cat.tip_newest', { what: what.length > 110 ? what.slice(0, 109) + '…' : what, when: fmt.relative(newest.last) }));
  }
  (c.why || []).filter((w) => !CAT_SEEN.includes(w) && Office.has(`${ID}.cat.why.${w}`)).slice(0, 2).forEach((w) => lines.push(T('cat.why.' + w)));
  if (state && state.round && state.round.last) lines.push(T('cat.tip_checked', { when: fmt.relative(state.round.last) }));
  return lines.slice(0, 4);
}
/** An area's entries in his book (each entry's `cat`, from the messenger) */
const catEntries = (id) => ((state && state.book) || []).filter((e) => e && typeof e.id === 'string' && e.cat === id);
/** The words the row's filter meets an area by: its name, what it looks at, its open entries' words */
function catWords(id) {
  return [catName(id), T(`cat.${id}_sub`), ...catEntries(id).filter((e) => e.open).slice(0, 50).map((e) => entryHay(e))];
}

/** «How secure does it stand?»: serious incidents red, advice not known yet green with «!» (a «good to know» tip never colours, W6) */
function postureLevel() {
  const p = state && state.posture;
  if (!p) return '';
  const tips = p.tips || [];
  const advice = tips.some((x) => x.level) ? tips.filter((x) => x.level === 'advice' && !x.known).length : tips.filter((x) => !x.known).length;
  return louderCount() ? 'danger' : advice ? 'note' : 'ok';
}
/** «Serious incidents»: grey while there are none, green with «!» while he keeps some (each one makes the same louder) */
const incidentsLevel = () => (!state ? '' : ((state.incidents || []).length ? 'note' : 'none'));

/**
 * «Schedule» on the office's schedule tile (Office.scheduleTile): his rounds go by themselves every five minutes — never
 * «Off» —, the next one, the last and how it ended (went wrong, stopped after too long, on it now). Its section says there
 * is nothing to set
 */
function roundSchedule() {
  const r = (state && state.round) || {};
  const broken = r.broken ? (r.broken.why === 'stopped' ? 'stopped' : 'failed') : '';
  const last = running() ? { at: r.last || Date.now() / 1000, how: 'running' } : r.last ? { at: r.last, how: r.failed ? 'failed' : broken || 'ok' } : null;
  const entry = Office.scheduleTile(ID, { every: T('schedule.every'), next: running() ? 0 : r.next || 0, last, section: () => roundScheduleSection() });
  // as a light: the schedule tile's own words (when next, how the last ended) become its mouse-over text
  return { ...entry, tile: (a) => {
    const n = entry.tile({});
    const txt = (c) => (n && n.querySelectorAll ? [...n.querySelectorAll(c)].map((x) => x.textContent).filter(Boolean) : []);
    return lightTile({ label: T('tile.schedule'), value: txt('.stat-value')[0] || '', sub: txt('.stat-sub'),
      level: crossLevel('schedule', r.failed || broken ? 'danger' : r.last ? 'ok' : ''), ...a });
  } };
}
function roundScheduleSection() {
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('tile.schedule'), T('round.sub')));
  s.appendChild(el('p', 'role', T('schedule.fixed')));
  return s;
}

/** Anything open in the book? (what «only open» could show) */
const bookHasOpen = () => ((state && state.book) || []).some((e) => e && e.open);

/** «Watch book»: how many entries, how many open, the newest */
function bookTile(a) {
  const book = ((state && state.book) || []).filter((e) => e && typeof e.id === 'string');
  const open = book.filter((e) => e.open).length;
  const newest = Math.max(0, ...book.map((e) => Number(e.last) || 0));
  return lightTile({ label: T('tile.book'), value: T('tile.book_entries', { n: book.length }), level: crossLevel('book', open ? 'warn' : state ? 'ok' : ''),
    sub: book.length ? [T('tile.book_open', { n: open }), newest ? T('tile.book_newest', { when: fmt.relative(newest) }) : ''].filter(Boolean).join(' · ') : T('tile.book_empty'),
    ...a });
}

/** «How secure does it stand?»: his tips not known yet — or that you know them all — and how many you know */
function postureTile(a) {
  const p = state && state.posture;
  const tips = (p && p.tips) || [];
  const known = tips.filter((x) => x.known).length;
  const open = tips.length - known;
  // red only for his advice not known yet — a «good to know» tip is no alarm (W6; an older state: every open tip)
  const advice = tips.some((x) => x.level) ? tips.filter((x) => x.level === 'advice' && !x.known).length : open;
  const louder = louderCount();      // serious incidents lately: part of how it stands
  return lightTile({ label: T('tile.posture'), level: postureLevel(),
    value: !p ? '–' : open ? T('tile.posture_tips', { n: open }) : known ? T('tile.posture_all_known') : T('tile.posture_none'),
    sub: [known ? T('tile.posture_known', { n: known }) : '', louder ? T('tile.posture_incidents', { n: louder }) : ''].filter(Boolean).join(' · '), ...a });
}

/** «Serious incidents»: how many he keeps in his history (each one watched more closely while it is there) */
function incidentsTile(a) {
  const list = (state && state.incidents) || [];
  const louder = louderCount();
  return lightTile({ label: T('tile.incidents'), level: incidentsLevel(), sub: louder && louder !== list.length ? T('tile.posture_incidents', { n: louder }) : '',
    value: list.length ? T('tile.incidents_n', { n: list.length }) : T('tile.incidents_none'), ...a });
}

/**
 * His history of serious incidents (newest first): what it was, when it came, when you acknowledged it, your note, that the
 * same from the same source is reported at once while it is listed, and how often it came back since
 */
function incidentsSection() {
  const s = el('section', 'section wm-incidents');
  s.appendChild(Office.sectionHead(T('incidents.title'), T('incidents.sub'), { place: 'incidents.title' }));
  const list = (state && state.incidents) || [];
  if (!list.length) {
    s.appendChild(el('p', 'role wm-incidents-none', T('incidents.none')));
    return s;
  }
  const box = el('div', 'box');
  list.forEach((x) => box.appendChild(incidentRow(x)));
  s.appendChild(box);
  return s;
}

function incidentRow(x) {
  const r = Office.place(`incident:${x.id}`, el('div', 'row nocheck wm-incident'));
  r.dataset.id = x.id;
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', entryName(x)));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('incidents.louder'), 'warn', T('incidents.louder_title')));
  meta.appendChild(chip(T('group.' + x.group), '', T('group_title.' + x.group)));
  const tech = attackLink(x.attack);
  if (tech) meta.appendChild(tech);
  if (x.again) meta.appendChild(chip(T('incidents.again', { n: x.again }), 'warn', T('incidents.again_title')));
  const when = el('span', '', fmt.date(x.last));
  when.dataset.tip = fmt.relative(x.last);
  meta.appendChild(when);
  const acked = el('span', '', T('incidents.acked', { when: fmt.relative(x.at) }));
  acked.dataset.tip = fmt.date(x.at);
  meta.appendChild(acked);
  main.appendChild(meta);
  if (x.note) main.appendChild(el('div', 'row-detail wm-incident-note', x.note));
  r.appendChild(main);
  const acts = el('div', 'wm-entry-acts');
  const b = el('button', 'btn small plain', T('serious_undo'));
  b.type = 'button';
  b.title = T('serious_undo_title');
  b.disabled = !Office.agent.running;
  b.onclick = () => seriousUndo(x, b);
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

/** The last round got no answer from Docker: what he shows of containers is the last round that reached it (W7) */
const dockerOff = () => !!(state && state.on_watch && state.round && state.round.docker === false);

/** «Keeping an eye on»: the containers, plugins and shares he watches */
function watchTile(a) {
  const w = state && state.watch;
  const c = w && w.containers;
  const sh = w && w.shares;
  // Docker not answering: never «8 containers» as if counted now — said instead (truth review W7)
  return lightTile({ label: T('tile.watch'), level: !state ? '' : state.on_watch ? 'ok' : 'none',
    value: dockerOff() ? Office.t('common.docker_off') : c ? T('count.containers', { n: Number(c.count) || 0 }) : '–',
    sub: w ? [T('count.plugins', { n: (w.plugins || []).length }), sh ? T('count.shares', { n: Number(sh.count) || 0 }) : ''].filter(Boolean).join(' · ') : '',
    ...a });
}

// ------------------------------------------------------------------ the last round: the row's head
/** A day as «08.10.» (its full date as the tip) */
function shortDay(t) {
  const x = el('span', '', new Date(t * 1000).toLocaleDateString(Office.locale || undefined, { day: '2-digit', month: '2-digit' }));
  x.title = fmt.date(t);
  return x;
}

/**
 * «The last round» over the row (a hint, not a section — it stays at every click): one line — when (how long it took),
 * the next one, on watch since, learning until — and his two switches on its right; what to know about the round under it
 */
function roundHead() {
  const s = el('div', 'wm-round');
  const r = state.round || {};
  const head = Office.sectionHead(T('round.title'), '', running() ? el('span', 'hint', T('stat.running')) : null, notifySwitch(), syslogSwitch(),
    { place: 'round.title' });
  const line = el('div', 'section-sub wm-round-line');
  line.title = T('round.sub');
  const parts = [];
  const last = el('span', '', r.last ? (r.duration_ms ? T('round.last_ms', { when: fmt.relative(r.last), ms: r.duration_ms }) : fmt.relative(r.last)) : T('stat.never'));
  if (r.last) last.title = fmt.date(r.last);
  parts.push(last);
  if (!running() && r.next) {
    const next = el('span', '', T('round.next', { when: fmt.relative(r.next) }));
    next.title = fmt.date(r.next);
    parts.push(next);
  }
  if (state.on_watch) {
    const since = el('span', '', T('round.since') + ' ');
    since.appendChild(shortDay(state.on_watch));
    parts.push(since);
    // still learning what is normal for the data flow? and until when (2026-10-10)
    const f = state.flow;
    if (f && f.since && f.learn) {
      const end = f.since + f.learn * 86400;
      const learn = el('span', '', end > Date.now() / 1000 ? T('round.learning') + ' ' : T('round.learned'));
      if (end > Date.now() / 1000) learn.appendChild(shortDay(end));
      parts.push(learn);
    }
  }
  parts.forEach((x, i) => { if (i) line.append(' · '); line.appendChild(x); });
  head.appendChild(line);
  s.appendChild(head);
  const notes = el('div', 'wm-notes');
  if (!state.on_watch) notes.appendChild(el('p', 'callout', T('round.first')));
  if (r.failed) notes.appendChild(el('p', 'callout warn', T('round.failed')));
  else if (r.broken) notes.appendChild(el('p', 'callout warn', T(r.broken.why === 'stopped' ? 'round.stopped' : 'round.failed')));
  if (r.syslog_full) {
    const full = el('p', 'callout warn', T('round.syslog_full', { free: fmt.size(r.syslog_full.free || 0), total: fmt.size(r.syslog_full.total || 0) }) + ' ');
    const a = el('a', '', T('round.syslog_full_link'));
    a.href = '#/logs';
    full.appendChild(a);
    notes.appendChild(full);
  }
  if (r.skipped) notes.appendChild(el('p', 'role', T('round.skipped', { size: fmt.size(r.skipped) })));
  if (state.on_watch && r.docker === false) notes.appendChild(el('p', 'role', T('round.no_docker')));
  if (state.on_watch && r.shares === false) notes.appendChild(el('p', 'role', T('round.no_shares')));
  const told = state.notified;
  if (told && told.time) notes.appendChild(el('p', 'role', T('round.notified', { when: fmt.relative(told.time), n: (told.items || []).length })));
  if (state.notify && state.notify.available === false) notes.appendChild(el('p', 'role', T('notify_missing')));
  const night = state.night;
  if (night && night.until) {
    notes.appendChild(el('p', 'role', T('round.night', { from: fmt.date(night.since), until: fmt.date(night.until) })
      + (night.from === 'flash' ? ' ' + T('round.night_flash') : '')));
  }
  if (notes.children.length) s.appendChild(notes);
  return s;
}

/**
 * Reports to Unraid's notifications: since 1.68 the Team Lead's notification centre decides how much and when (his
 * «Reports» tile) — here only the way there
 */
function notifySwitch() {
  const a = el('a', 'btn small plain', Office.t('notifycenter.where_link'));
  a.href = '#/caretaker/notify';
  a.title = Office.t('notifycenter.where');
  return a;
}

/** For a SIEM: also write each new entry to Unraid's syslog (its remote syslog sends it on) — off by default */
function syslogSwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !!(state.syslog && state.syslog.on);
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('syslog_switch')));
  label.title = T('syslog_title');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.syslog_set`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    if (view) Office.keepInPlace(null, render);
    Office.toast(T(on ? 'syslog_on' : 'syslog_off'));
  };
  return label;
}

// ------------------------------------------------------------------ how secure it stands
/**
 * His posture tips: how the server stands now (not what changed) — each with why, where in Unraid and
 * «I know, thanks» (kept on the server for every browser; the tip comes back when what it is about changes).
 * Known ones are folded away, «Show known» brings them back.
 */
function postureSection() {
  const s = el('section', 'section wm-posture');
  const p = state.posture;
  const tips = (p && p.tips) || [];
  const known = tips.filter((x) => x.known);
  const extras = [];
  if (known.length) {
    const b = el('button', 'btn small plain', T(showKnownTips ? 'posture.hide_known' : 'posture.show_known', { n: known.length }));
    b.type = 'button';
    b.onclick = () => { showKnownTips = !showKnownTips; renderAt('.wm-posture'); };
    extras.push(b);
  }
  s.appendChild(Office.sectionHead(T('posture.title'), T('posture.sub'), ...extras, { place: 'posture.title' }));
  if (!p) {
    s.appendChild(el('p', 'role wm-posture-none', T(state.on_watch ? 'posture.wait' : 'posture.first')));
    return s;
  }
  const list = tips.filter((x) => !x.known || showKnownTips);
  if (!list.length) {
    s.appendChild(el('p', 'role wm-posture-none', T(tips.length ? 'posture.all_known' : 'posture.none')));
    return s;
  }
  const box = el('div', 'box');
  list.forEach((x) => box.appendChild(postureRow(x)));
  s.appendChild(box);
  return s;
}

/** A tip's words: names and numbers from the agent, the CPU's flaws by the names people know */
function postureParams(x) {
  const p = { ...(x.p || {}) };
  if (Array.isArray(p.open)) p.open = p.open.map((v) => VULN[v] || v).join(', ') || '–';
  if (x.id === 'flash') p.access = T('level.' + (p.level === 'public' ? 'public' : 'secure'));
  return p;
}

function postureRow(x) {
  const r = el('div', 'row nocheck wm-tip' + (x.known ? ' wm-tip-known' : ''));
  r.dataset.id = x.id;      // not data-tip: that is the office's tooltip hook (it swallowed the row's clicks up to 1.36.0)
  const main = el('div', 'row-main');
  const params = postureParams(x);
  main.appendChild(el('div', 'row-name text', T(`posture.${x.id}.title`, params)));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T(`posture.level_${x.level}`), x.level === 'advice' ? 'accent' : 'quiet', T(`posture.level_${x.level}_text`)));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T(`posture.${x.id}.why`, params)));
  // privileged containers that are stopped and start by nothing: not part of the tip, a quiet line in it (inbox #10)
  if (x.id === 'privileged' && params.quiet) main.appendChild(el('div', 'row-detail role', T('posture.privileged.quiet', { quiet: params.quiet, n: Number(params.quiet_n) || 1 })));
  // what your exceptions call intended (1.96): named, no advice (lifted in his setup)
  if ((x.id === 'privileged' || x.id === 'public') && params.intended) main.appendChild(el('div', 'row-detail role', T('posture.intended', { names: params.intended })));
  if (x.id === 'privileged' && dockerOff()) main.appendChild(el('div', 'row-detail role', T('posture.as_last_seen', { docker: Office.t('common.docker_off') })));
  r.appendChild(main);
  const acts = el('div', 'wm-tip-acts');
  const href = x.link && Office.safeHref(x.link.path);
  if (href && Office.has(`${ID}.posture.to_${x.link.to}`)) {
    const a = el('a', 'btn small plain', T(`posture.to_${x.link.to}`, { name: x.link.name || '' }));      // into Unraid, in the same tab like its own links
    a.href = href;
    acts.appendChild(a);
  }
  const b = el('button', 'btn small plain', T(x.known ? 'posture.show_again' : 'ack'));
  b.type = 'button';
  b.title = T(x.known ? 'posture.show_again_title' : 'posture.ack_title');
  b.disabled = !Office.agent.running || !hired();
  b.onclick = () => postureAck(x, !x.known, b);
  acts.appendChild(b);
  r.appendChild(acts);
  return r;
}

async function postureAck(x, on, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.posture_ack`, { id: x.id, on });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'watch_tip_gone') await load(true);
    else b.disabled = false;
    return;
  }
  if (j.state) state = j.state;
  renderAt('.wm-posture', '.wm-cat-tips');
  Office.toast(T(on ? 'posture.acked' : 'posture.unacked'));
}

// ------------------------------------------------------------------ the watch book
/** The area an entry is filtered by: its group; a router's log too long for a round counts to the network */
const entryArea = (e) => (e.kind === 'watch' && e.p && e.p.too_much ? 'net' : e.group || 'watch');
/** What folds with what (beside the area and the day): the same kind — a router's log too long is a kind of its own */
const foldKind = (e) => (e.kind === 'watch' && e.p && e.p.too_much ? 'net_too_much' : e.kind);
/** Words as the filter compares them: lower case, without accents, ß as ss — core's Office.fold, as the bar's own */
const plainWords = (s) => Office.fold(s);

/**
 * The watch book as his page shows it — no DOM, nothing of the page's state (tests/watchbook.js runs it under node).
 * book: the entries newest first, as his state has them. o: {onlyOpen, area ('' = every area), words (plainWords, [] =
 * none), hay(e) → the entry's words (plainWords), day(seconds) → 'YYYY-MM-DD', chains ([{ids}]), shown, page, wanted (an
 * entry's id or null)}. Entries of the same kind and area on the same day (of their last time) fold into one row.
 * Returns {
 *   areas: [[area, n], …] — the entries per area that «only open» leaves (the words and the area itself not counted),
 *   rows: every row the switches and words leave: {key, day, kind, area, members (newest first — one member: the entry
 *     itself), total (the members without the words), open (members not noted), last, night, chain},
 *   items: what to draw — {day} before the first row of each day, then the rows, the first `shown` of them,
 *   shown: rows drawn (more when the entry asked for lies further down),
 *   wanted: {row, key, entry} where the entry asked for is, or null; hidden: it is in the book but the switches hide it }
 */
function bookView(book, o) {
  const list = (book || []).filter((e) => e && typeof e.id === 'string');
  const page = o.page || PAGE;
  const chained = new Set();
  (o.chains || []).forEach((c) => (c.ids || []).forEach((id) => chained.add(id)));
  const counts = new Map();
  list.forEach((e) => { if (!o.onlyOpen || e.open) counts.set(entryArea(e), (counts.get(entryArea(e)) || 0) + 1); });
  const areas = [...counts.entries()];
  const base = list.filter((e) => (!o.onlyOpen || e.open) && (!o.area || entryArea(e) === o.area));
  const keyOf = (e) => `${o.day(e.last)}|${entryArea(e)}|${foldKind(e)}`;
  const totals = new Map();
  base.forEach((e) => totals.set(keyOf(e), (totals.get(keyOf(e)) || 0) + 1));
  const words = o.words || [];
  const hits = words.length ? base.filter((e) => { const h = o.hay(e); return words.every((w) => h.includes(w)); }) : base;
  const byKey = new Map();
  const rows = [];
  hits.forEach((e) => {
    const key = keyOf(e);
    let r = byKey.get(key);
    if (!r) {
      r = { key, day: o.day(e.last), kind: foldKind(e), area: entryArea(e), members: [], total: totals.get(key), open: 0, last: e.last, night: false, chain: false };
      byKey.set(key, r);
      rows.push(r);
    }
    r.members.push(e);
    if (e.open) r.open++;
    if (e.last > r.last) r.last = e.last;
    if (e.night) r.night = true;
    if (chained.has(e.id)) r.chain = true;
  });
  let shown = Math.max(0, o.shown || page);
  let wanted = null;
  let hidden = false;
  if (o.wanted) {
    const at = rows.findIndex((r) => r.members.some((e) => e.id === o.wanted));
    if (at >= 0) {
      const r = rows[at];
      wanted = { row: at, key: r.key, entry: o.wanted };
      shown = Math.max(shown, Math.ceil((at + 1) / page) * page);
    } else hidden = list.some((e) => e.id === o.wanted);
  }
  const items = [];
  let day = null;
  rows.slice(0, shown).forEach((r) => {
    if (r.day !== day) { day = r.day; items.push({ day }); }
    items.push(r);
  });
  return { areas, rows, items, shown, wanted, hidden };
}

/** An entry's words for the filter: its text, its area and kind, what it names (addresses, MACs, names …) */
function entryHay(e) {
  const out = [entryName(e), areaName(entryArea(e)), e.kind, foldKind(e)];
  const add = (v, depth) => {
    if (v === null || v === undefined || depth > 3) return;
    if (typeof v === 'string' || typeof v === 'number') out.push(String(v));
    else if (Array.isArray(v)) v.forEach((x) => add(x, depth + 1));
    else if (typeof v === 'object') Object.values(v).forEach((x) => add(x, depth + 1));
  };
  add(e.p, 0);
  add(e.t, 0);
  return plainWords(out.join(' \u0001 '));
}

/** An area's name for the select (the take-over lines have no group chip: «His watch») */
const areaName = (a) => (a === 'watch' ? T('book.area_watch') : T('group.' + a));

/** The text of a row that folds several entries: «8 devices never seen on the LAN» — or «{n} × <area>» for a kind without one */
function foldName(r) {
  return Office.has(`${ID}.fold.${r.kind}`) ? T('fold.' + r.kind, { n: r.members.length })
    : T('fold.any', { n: r.members.length, area: areaName(r.area) });
}

/**
 * The book's bar (core.js Office.filterBar), built once and held across the renders: the words (entryHay), the area
 * (counts per area, kept per browser — today's 'watchman.area' read once into the bar's key), «Unfold all»; all on the
 * whole book. «n of m fit» stands in the bar; while the tile «Not noted yet» filters, the note line says it.
 */
function bookBarGet() {
  if (bookBar) return bookBar;
  const old = Office.store('watchman.area');
  if (old && !Office.store('watchman.book.pick')) Office.store('watchman.book.pick', old);
  if (old) Office.store('watchman.area', null);
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  unfold.onclick = () => {
    const open = bookRows.some((x) => !x.open());
    Office.keepInPlace(unfold, () => {
      bookRows.filter((x) => x.fold).forEach((x) => x.set(open));       // the folded rows first: their entries come or go
      bookRows.filter((x) => !x.fold).forEach((x) => x.set(open));
    });
    bookLabel();
  };
  bookLabel = () => {
    unfold.textContent = bookRows.some((x) => !x.open()) ? T('unfold_all') : T('fold_all');
    unfold.hidden = bookRows.length < 2;
  };
  bookBar = Office.filterBar({
    key: 'watchman.book',
    label: T('book.filter_label'),
    select: { label: T('book.area_label'), all: T('book.area_all') },
    only: { text: T('stat.open'), hint: T('stat.open_tip') },     // «Not noted yet» (the tile of that name up to 1.95; #/watchman/open)
    extra: [unfold],
    onChange: (f, what) => {
      if (what === 'words' || what === 'all') foldsShut.clear();
      if (what !== 'gone') shown = PAGE;
      if (view) sw.render();
    },
  });
  return bookBar;
}

function bookSection() {
  const s = el('section', 'section');
  s.id = 'wm-book';
  const extras = [];
  if (openCount()) {
    const all = el('button', 'btn small', T('ack_all'));
    all.type = 'button';
    all.title = T('ack_all_title');
    all.disabled = !Office.agent.running;
    all.onclick = ackAll;
    extras.push(all);
  }
  s.appendChild(Office.sectionHead(T('book.title'), T('book.sub'), ...extras, { place: 'book.title' }));
  const bar = bookBarGet();
  s.appendChild(bar.el);

  // what the book can't hold is said, never dropped quietly (concept 3.4, cases 6 and the answers' list)
  const keep = Number(state.limits && state.limits.keep) || 500;
  if (openCount() >= keep) s.appendChild(el('p', 'callout warn', T('book.full', { n: keep })));
  if (state.known_full) s.appendChild(el('p', 'callout warn', T('known.full')));
  const box = el('div', 'box wm-book');
  const onlyOpen = bar.read().only;                // «Not noted yet»: the bar's switch (a tile up to 1.95)
  const look = (asked) => {
    const f = bar.read();
    return bookView(state.book, {
      onlyOpen, area: f.pick, words: f.words, hay: entryHay, day: fmt.dayKey,
      chains: state.chains, shown, page: PAGE, wanted: asked,
    });
  };
  const fillAreas = (v) => {
    const pick = bar.read().pick;
    const list = v.areas.map(([a, n]) => [a, n, areaName(a)]);
    if (pick && !list.some(([a]) => a === pick)) list.push([pick, 0, areaName(pick)]);     // chosen, none left now: it stays
    list.sort((x, y) => x[2].localeCompare(y[2], Office.locale));
    bar.options(list.map(([a, n, name]) => [a, T('book.area_n', { area: name, n })]));
  };
  const fill = () => {
    box.innerHTML = '';
    bookRows = [];
    let v = look(wanted);
    if (v.hidden) {          // the entry asked for (the search, a link) hides behind the words or the area: the whole book
      const open = (state.book || []).some((e) => e.id === wanted && e.open);
      if (open || !onlyOpen) bar.set({ text: '', pick: '' });
      v = look(wanted);
    }
    if (v.wanted) {
      shown = v.shown;
      if (v.wanted.key) { foldsOpen.add(v.wanted.key); foldsShut.delete(v.wanted.key); }
      unfolded.add(v.wanted.entry);
      wanted = null;
    }
    fillAreas(v);
    const f = bar.read();
    // what may belong together: always on the whole book; with the words or an area, only a chain with an entry shown
    const drawn = new Set(v.rows.flatMap((r) => r.members.map((e) => e.id)));
    const narrowed = !!(f.words.length || f.pick);
    (state.chains || []).filter((c) => !narrowed || (c.ids || []).some((id) => drawn.has(id))).forEach((c) => box.appendChild(chainCallout(c)));
    v.items.forEach((x) => box.appendChild(x.members ? (x.members.length > 1 ? foldRow(x) : entryRow(x.members[0])) : dayHead(x.day)));
    if (!v.rows.length) {
      box.appendChild(el('p', 'empty', narrowed ? T('book.empty_filter') : onlyOpen ? T('book.empty_open') : T('book.empty')));
    }
    if (v.rows.length > v.shown) {
      const more = el('button', 'btn small plain wm-more', T('book.more', { n: Math.min(PAGE, v.rows.length - v.shown) }));
      more.type = 'button';
      more.onclick = () => { shown += PAGE; Office.keepInPlace(null, fill); };
      box.appendChild(more);
    }
    bookLabel();
    // «only open», the words and the area are named in the note line (its × and «Show all»: the whole book again)
    bar.done(v.rows.reduce((a, r) => a + r.members.length, 0), (state.book || []).length, { onlyAvail: bookHasOpen() || onlyOpen });
  };
  fill();
  s.appendChild(box);
  return s;
}

// ------------------------------------------------------------------ an area's section (1.96)
/*
 * One area of his (agent/desks/watchman-cats.php): what he looks at there, what he couldn't see in his last round (a
 * callout each — state.cats[id].why), its entries of the book in the book's own rows (open first is the book's order by
 * time; folded by day and kind, «I know, thanks» as there) under a bar of its own (words, «only open», the kind), and a
 * jump to its group in «What I keep an eye on». An older messenger's state has no areas: one line says so.
 */
const catBars = {};             // each area's bar, built once and held across the renders (its words never kept)
function catBarGet(id) {
  catBars[id] ??= Office.filterBar({
    label: T('cat.filter_label'),
    only: { text: T('stat.open'), hint: T('stat.open_tip') },
    select: { label: T('cat.kind_label'), all: T('cat.kind_all') },      // no key: the kind is never kept
    onChange: () => { if (view) sw.render(); },
  });
  return catBars[id];
}
const CAT_CALLOUT_WARN = ['syslog_full', 'snaps_failed', 'snaps_capped', 'router_asleep', 'router_unreadable', 'router_unknown', 'parity_todo',
  'parity_again', 'parity_errors'];
function catSection(id) {
  const c = catOf(id);
  const s = el('section', 'section wm-cat');
  const key = CAT_WATCH[id];
  const extras = [];
  if (key) {
    const to = el('button', 'btn small plain', T('cat.to_watch'));
    to.type = 'button';
    to.onclick = () => { sw.pick('watch', 'click'); Office.reveal(`watch:${key}`); };
    extras.push(to);
  }
  // the light in words beside the name (on a phone, without a mouse, the one place the tile's first line stands)
  const said = catState(id);
  s.appendChild(Office.sectionHead(catName(id), T(`cat.${id}_sub`), ...(said ? [said] : []), ...extras));
  if (!c) {
    s.appendChild(el('p', 'role', T('cat.unknown')));
    return s;
  }
  (c.why || []).filter((w) => !CAT_SEEN.includes(w) && Office.has(`${ID}.cat.why.${w}`))
    .forEach((w) => s.appendChild(el('p', 'callout' + (CAT_CALLOUT_WARN.includes(w) ? ' warn' : ''), T('cat.why.' + w))));
  if (c.off) {
    const on = el('button', 'btn small', T('rule.cat_on'));
    on.type = 'button';
    on.disabled = !Office.agent.running || !hired();
    on.onclick = () => catOn(id, on);
    s.appendChild(on);
  }
  const rules = catRules(id);
  const known = catKnown(id);
  const all = catEntries(id);
  if (sw.active() !== id) {
    // the whole page (nothing chosen): no entries again under the book — how many, a click shows this area alone
    if (all.length) {
      const open = all.filter((e) => e.open).length;
      const what = [T('cat.in_book', { n: all.length }), open ? T('cat.tip_open', { n: open }) : ''].filter(Boolean).join(', ');
      const b = el('button', 'btn small plain wm-cat-more', T('cat.in_book_go', { what }));
      b.type = 'button';
      b.onclick = () => sw.pick(id, 'click');
      s.appendChild(b);
    } else s.appendChild(el('p', 'role', T('cat.empty')));
    const advice = catAdvice(id);
    if (advice) s.appendChild(advice);
    if (rules) s.appendChild(rules);
    if (known) s.appendChild(known);
    return s;
  }
  const bar = catBarGet(id);
  s.appendChild(bar.el);
  const f = bar.read();
  // the kinds there are in this area's entries, for the bar's select (each by its book name: «3 new addresses»)
  const kinds = new Map();
  all.forEach((e) => kinds.set(e.kind, (kinds.get(e.kind) || 0) + 1));
  bar.options([...kinds.entries()].map(([k, n]) => [k, Office.has(`${ID}.fold.${k}`) ? T('fold.' + k, { n }) : k]));
  const list = all.filter((e) => !f.pick || e.kind === f.pick);
  const box = el('div', 'box wm-book wm-cat-book');
  const v = bookView(list, { onlyOpen: f.only, area: '', words: f.words, hay: entryHay, day: fmt.dayKey, chains: [], shown: 1000, page: 1000, wanted: null });
  v.items.forEach((x) => box.appendChild(x.members ? (x.members.length > 1 ? foldRow(x, f.words.length > 0) : entryRow(x.members[0])) : dayHead(x.day)));
  if (!v.rows.length) box.appendChild(el('p', 'empty', all.length ? T('cat.empty_filter') : T('cat.empty')));
  s.appendChild(box);
  bar.done(v.rows.reduce((n, r) => n + r.members.length, 0), all.length, { onlyAvail: all.some((e) => e.open) || f.only });
  const advice = catAdvice(id);
  if (advice) s.appendChild(advice);
  if (rules) s.appendChild(rules);
  if (known) s.appendChild(known);
  return s;
}

/**
 * The light in words for an area's head: «2 open, 1 of them important · looked 3 minutes ago» — the first line of its
 * mouse-over text without the name (a phone has no mouse; the head stands at the top of the chosen section)
 */
function catState(id) {
  const c = catOf(id);
  if (!c) return null;
  const lines = catTip(id);
  const first = String(lines[0] || '').slice(catName(id).length + 2);
  const looked = state && state.round && state.round.last ? T('cat.tip_checked', { when: fmt.relative(state.round.last) }) : '';
  const span = el('span', 'wm-cat-state wm-cat-' + (Office.LIGHTS.includes(c.level) ? c.level : 'unknown'), [first, looked].filter(Boolean).join(' · '));
  span.title = lines.join('\n');
  return span;
}

/**
 * «Advice» in an area: its tips of «How secure does it stand?» (the same rows, the same «I know, thanks» — they stay
 * there too), the ones you know only counted; nothing when the area has none now
 */
function catAdvice(id) {
  const tips = ((state && state.posture && state.posture.tips) || []).filter((x) => x && x.cat === id);
  if (!tips.length) return null;
  const open = tips.filter((x) => !x.known || showKnownTips);
  const known = tips.length - tips.filter((x) => !x.known).length;
  const box = el('div', 'wm-cat-tips');
  box.appendChild(el('h4', 'wm-hold-group', T('cat.tips_title')));
  if (open.length) {
    const list = el('div', 'box');
    open.forEach((x) => list.appendChild(postureRow(x)));
    box.appendChild(list);
  }
  if (known && !showKnownTips) box.appendChild(el('p', 'role', T('cat.tips_known', { n: known })));
  return box;
}

/** A day's heading between the rows: «Today», «Yesterday», then the date */
function dayHead(day) {
  const h = el('div', 'wm-day', fmt.dayTitle(day));
  h.setAttribute('role', 'heading');
  h.setAttribute('aria-level', '3');
  return h;
}

/**
 * Several entries of one kind and area on one day, as one row: how many, the newest time, the strongest state of its
 * members (open before noted), «All n: I know, thanks» for those still open; a click unfolds the entries, each as its
 * own row. While filter words are typed the rows unfold by themselves (only the entries the words meet are in them).
 */
function foldRow(x, typed) {
  const n = x.members.length;
  const first = x.members[0];
  const watch = first.kind === 'watch';
  const words = typed !== undefined ? !!typed : !!(bookBar && bookBar.read().words.length);      // an area's section hands its own bar's
  const wrap = el('div', 'wm-fold');
  const r = el('div', 'row nocheck wm-entry wm-fold-head unfolds' + (x.open ? ' wm-open' : ''));
  r.dataset.fold = x.key;
  const main = el('div', 'row-main');
  const name = el('div', 'row-name text', foldName(x));
  name.title = T('fold_title');
  const meta = el('div', 'row-meta');
  if (watch) {
    meta.appendChild(first.p && first.p.too_much ? chip(T('state.too_much'), 'warn', T('state.too_much_title')) : chip(T('state.watch'), 'accent', T('state.watch_title')));
  } else {
    meta.appendChild(x.open ? chip(T('book.open_n', { n: x.open }), 'warn', T('state.open_title')) : chip(T('state.noted'), 'quiet', T('state.noted_title')));
    meta.appendChild(chip(T('group.' + first.group), '', T('group_title.' + first.group)));
    const tech = attackLink(first.attack);
    if (tech) meta.appendChild(tech);
    if (x.chain) meta.appendChild(chip(T('chain_chip'), 'warn', T('chain_title_fold')));
    if (x.night) meta.appendChild(chip(T('state.night'), 'accent', T('state.night_title')));
  }
  const when = el('span', '', fmt.date(x.last));
  when.dataset.tip = fmt.relative(x.last);
  meta.appendChild(when);
  if (words && x.total > n) meta.appendChild(el('span', '', T('book.hits', { n, total: x.total })));
  main.append(name, meta);
  r.appendChild(main);
  const open = x.members.filter((e) => e.open);
  if (open.length) {
    const b = el('button', 'btn small plain', open.length > 1 ? T('book.ack_n', { n: open.length }) : T('ack'));
    b.type = 'button';
    b.title = T('book.ack_n_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ackSome(open, b, x.key);
    r.appendChild(b);
  }
  const body = el('div', 'wm-members');
  wrap.append(r, body);
  let isOpen = false;
  const set = (on) => {
    if (on === isOpen) return;
    isOpen = on;
    r.classList.toggle('open', on);
    r.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) {
      foldsOpen.add(x.key);
      foldsShut.delete(x.key);
      x.members.forEach((e) => { const row = entryRow(e, x.key); row.classList.add('wm-member'); body.appendChild(row); });
    } else {
      foldsOpen.delete(x.key);
      if (words) foldsShut.add(x.key);
      bookRows = bookRows.filter((y) => y.owner !== x.key);
      body.innerHTML = '';
    }
  };
  r.onclick = (ev) => {
    if (ev.target.closest('button, a, input, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!isOpen));
    if (bookLabel) bookLabel();
  };
  bookRows.push({ open: () => isOpen, set, fold: true });
  r.setAttribute('aria-expanded', 'false');
  if (foldsOpen.has(x.key) || (words && !foldsShut.has(x.key))) set(true);
  return wrap;
}

/** Entries that may belong together (a SOC's correlation): what came within how many minutes, oldest first */
function chainCallout(c) {
  const box = el('div', 'callout warn wm-chain');
  const minutes = Math.max(1, Math.round((c.last - c.first) / 60));
  box.appendChild(el('p', '', T('chain.text', { n: c.ids.length, minutes })));
  const byId = new Map((state.book || []).map((e) => [e.id, e]));
  const ul = el('ul', 'wm-chain-list');
  c.ids.map((id) => byId.get(id)).filter(Boolean).forEach((e) => {
    ul.appendChild(el('li', '', `${fmt.time(e.time)} · ${T('group.' + e.group)} · ${entryName(e)}`));
  });
  box.appendChild(ul);
  box.appendChild(el('p', 'wm-note', T('chain.how')));
  return box;
}

/** An entry in words, as its row names it (and the search finds it) */
function entryName(e) {
  if (e.kind === 'watch' && e.p && e.p.too_much) {
    return T(e.p.ros ? 'entry.net_too_much_mikrotik' : 'entry.net_too_much', { sender: e.p.sender || '', size: fmt.size(e.p.skipped || 0) });
  }
  return T('entry.' + (e.v || atVariant(e) || e.kind), entryParams(e));     // e.v: the shape a network entry has now (agent: watchnetEntryVariant())
}

/** An at job he doesn't know: running (=…) says since when, one still waiting days past its time says so (agent: watchmanAtSet()); a plugin's running */
function atVariant(e) {
  if (e.kind === 'at_plugin' && e.p && String(e.p.job || '').startsWith('=')) return 'at_plugin_run';      // atd runs it (=…)
  if (e.kind !== 'at_job' || !e.p) return null;
  return e.p.run ? 'at_job_run' : e.tell ? 'at_job_late' : null;
}

/** Kinds whose words say how many already (failed logins, a port's link losses, outages, restarts a day) */
const COUNT_IN_WORDS = ['login_failures', 'net_router_login_failures', 'net_link_down', 'net_outage', 'net_router_reboot'];

/** One entry: what, when, noted or not; a click unfolds its details (owner: the folded row it is drawn in, if any) */
function entryRow(e, owner) {
  const watch = e.kind === 'watch';
  const tooMuch = watch && !!(e.p && e.p.too_much);       // more of a router's log than a round reads (agent: watchnetTooMuch())
  const r = Office.place(`entry:${e.id}`, el('div', 'row nocheck wm-entry' + (watch ? ' wm-watch' : ' unfolds') + (e.open ? ' wm-open' : '')));
  r.dataset.id = e.id;
  const main = el('div', 'row-main');
  const name = el('div', 'row-name text', entryName(e));
  const meta = el('div', 'row-meta');
  if (tooMuch) {
    meta.appendChild(chip(T('state.too_much'), 'warn', T('state.too_much_title')));
  } else if (watch) {
    meta.appendChild(chip(T('state.watch'), 'accent', T('state.watch_title')));
  } else {
    name.title = T('details');
    meta.appendChild(e.open ? chip(T('state.open'), 'warn', T('state.open_title'))
      : e.serious ? chip(T('state.serious'), 'warn', T('state.serious_title')) : chip(T('state.noted'), 'quiet', T('state.noted_title')));
    if (e.again) meta.appendChild(chip(T('state.again'), 'warn', T('state.again_title')));
    meta.appendChild(chip(T('group.' + e.group), '', T('group_title.' + e.group)));
    const tech = attackLink(e.attack);
    if (tech) meta.appendChild(tech);
    const chain = (state.chains || []).find((c) => c.ids.includes(e.id));
    if (chain) meta.appendChild(chip(T('chain_chip'), 'warn', T('chain_title', { n: chain.ids.length })));
    if (e.night) meta.appendChild(chip(T('state.night'), 'accent', T('state.night_title')));
  }
  const when = el('span', '', fmt.date(e.last));
  when.dataset.tip = fmt.relative(e.last);
  meta.appendChild(when);
  // snapshots: the count is how many, it stands in the words already
  if (e.count > 1 && !COUNT_IN_WORDS.includes(e.kind) && e.group !== 'snap') meta.appendChild(el('span', '', T('times', { n: e.count })));
  main.append(name, meta);
  r.appendChild(main);
  if (e.open) {
    const acts = el('div', 'wm-entry-acts');
    // 1.96: a state's answer holds until you take it back; an event's is for this time (the messenger says which: lasts)
    const once = e.lasts === false;
    const b = el('button', 'btn small plain', T(once ? 'ack_once' : 'ack'));
    b.type = 'button';
    b.title = e.lasts === true ? `${T('ack_title')} ${T('ack_lasts_title')}` : T('ack_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ack(e, b);
    acts.appendChild(b);
    // an exception (1.96): the narrowest the messenger offers as a button, the others under «more ▾»
    const offers = watch ? [] : (Array.isArray(e.offers) ? e.offers : []).filter((o) => o && OFFERS.includes(o.type));
    if (offers.length) {
      const ob = el('button', 'btn small plain wm-offer', offerName(offers[0], e, true));
      ob.type = 'button';
      ob.title = T('rule.offer_title');
      ob.disabled = !Office.agent.running || !hired();
      ob.onclick = () => ruleAdd(e, offers[0], ob);
      acts.appendChild(ob);
      if (offers.length > 1) {
        const more = el('button', 'btn small plain', T('rule.more'));
        more.type = 'button';
        more.disabled = ob.disabled;
        more.onclick = (ev) => Office.menu(ev, offers.slice(1).map((o) => ({ text: offerName(o, e, true), act: () => ruleAdd(e, o, more) })));
        acts.appendChild(more);
      }
    }
    if (!watch) {
      const sb = el('button', 'btn small plain wm-serious', T('serious'));
      sb.type = 'button';
      sb.title = T('serious_title');
      sb.disabled = !Office.agent.running;
      sb.onclick = () => seriousAck(e, sb, b);
      acts.appendChild(sb);
    }
    r.appendChild(acts);
  }
  if (watch) return r;
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfolded.delete(e.id); r.classList.remove('open'); }
    if (open && !box) { box = details(e); r.appendChild(box); unfolded.add(e.id); r.classList.add('open'); }
  };
  r.onclick = (ev) => {
    if (ev.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;     // selecting text
    Office.keepInPlace(r, () => set(!box));
    if (bookLabel) bookLabel();
  };
  bookRows.push({ open: () => !!box, set, owner });
  if (unfolded.has(e.id)) set(true);
  return r;
}

/** The entry's nearest MITRE ATT&CK technique: a quiet chip with its explanation — no link out (2026-10-09:
 * a click opened nothing inside Unraid's page, and a link out is one more thing that can break) */
function attackLink(id) {
  if (typeof id !== 'string' || !/^T\d{4}(\.\d{3})?$/.test(id)) return null;
  const c = el('span', 'chip quiet wm-attack', id);
  c.title = T('attack_title', { id });
  return c;
}

/** A way in, in words (agent: watchmanDoorWords()) */
function doorWords(d) {
  return T('door.' + (d.door || d.what || 'ssh'), { name: d.name || '', port: d.port === null || d.port === undefined ? '–' : String(d.port),
    issuer: d.issuer || '', n: d.new_peers || d.peers || 0 });
}

/** An entry's words; the data flow's sizes, what is normal and the hours in this browser's language */
function entryParams(e) {
  const t = { ...(e.t || {}), n: e.count };
  const p = e.p || {};
  if (e.kind === 'watch' && !p.too_much) {      // «Took over the watch»: each count in its own words (count.*: one/other)
    const c = (what, k) => T('count.' + what, { n: Number(t[k]) || 0 });
    Object.assign(t, { ips: c('login_addresses', 'ips'), containers: c('containers', 'containers'), plugins: c('plugins', 'plugins'), shares: c('shares', 'shares') });
  }
  if (e.kind === 'proc_odd') t.where = p.where || T('where.host');
  if (e.kind === 'door_new') t.door = doorWords(p);
  if (e.kind === 'door_changed') t.what = doorWhat(p.what);
  if (e.kind === 'net_firewall_change') t.area = T('net_area.' + (['firewall', 'nat', 'port_forward', 'policy'].includes(p.area) ? p.area : 'firewall'));
  if (e.kind === 'parity_check') t.why = parityWhy(p);
  if (e.kind.startsWith('at_') && p.when) Object.assign(t, { when: fmt.date(p.when), since: fmt.date(p.since || p.when) });     // the browser's own way, like its details
  if (e.group !== 'flow') return t;
  const n = flowShape(e) === 'hour' ? p.hour : p.bytes !== undefined ? p.bytes : p.files;      // what its sentence says (agent: watchmanFlowSaid())
  if (p.bytes !== undefined) t.size = fmt.size(n);
  else if (p.files !== undefined) t.size = T('gone.files_n', { n: Number(n) || 0 });     // files deleted: counted
  if (flowShape(e) === 'span') t.span = fmt.duration(60 * (p.minutes || 0));
  if (e.kind.startsWith('flow_')) t.usual = usualText(e.kind, p);
  if (e.kind === 'flow_gone') t.more = goneMore(p);
  if (e.kind === 'flow_written') t.from = srcFrom(p);
  if (Array.isArray(p.hours)) t.hours = p.hours.map(hourName).join(', ');
  return t;
}

/** A data-flow entry's sentence (agent: watchmanFlowShape(), sent as e.v): 'hour' — the hour that tripped, 'span' — so
 * much over a long time, '' — the pull in its minutes */
function flowShape(e) {
  const m = /_(hour|span)$/.exec(e.group === 'flow' && e.v ? e.v : '');
  return m ? m[1] : '';
}

/** "3.1 GB in 12 min", "… in one hour", "… in 5 h 40 min" — as its sentence says it */
function flowSaid(e, size) {
  const p = e.p || {};
  const shape = flowShape(e);
  return shape === 'hour' ? T('detail.in_hour', { size }) : shape === 'span' ? T('detail.in_span', { size, span: fmt.duration(60 * (p.minutes || 0)) })
    : T('detail.in_minutes', { size, minutes: p.minutes || 1 });
}

/**
 * Who wrote into a share (agent: watchmanFlowSources(), watchmanFlowSourceWhat()): a container's writable layer, Docker's
 * image layers per image, libvirt's dataset, another dataset, the share's own, the rest
 */
function srcWhat(x) {
  const name = x.name || '';
  switch (x.t) {
    case 'ct': return T('src.container', { name });
    case 'img': return T(x.new ? 'src.layers_new' : 'src.layers', { n: Number(x.n) || 1, image: name ? T('src.image', { name }) : T('src.image_unknown') });
    case 'libvirt': return T('src.libvirt', { name });
    case 'ds': return T('src.dataset', { name });
    case 'own': return x.dir !== undefined ? T('src.own_libvirt', { name, folder: [name.split('/').slice(1).join('/'), x.dir].filter(Boolean).join('/') }) : T('src.own', { name });
    default: return T('src.rest');
  }
}

/** One source with its bytes, new or deleted since */
function srcLine(x) {
  const key = x.new && x.t !== 'img' ? (x.gone ? 'src.line_new_gone' : 'src.line_new') : (x.gone ? 'src.line_gone' : 'src.line');
  return T(key, { what: srcWhat(x), size: fmt.size(x.b || 0) });
}

/**
 * Where no source can be seen (a share of one dataset): who likely wrote it, from the setup (agent: watchmanFlowGuess(),
 * watchmanFlowGuessText()) — at most three named, «and {n} more»; without a guess that none can be seen. short: the
 * entry's line — names only, two of them; the full words in its detail row
 */
function srcNone(x, share, short) {
  const guess = (Array.isArray(x.guess) ? x.guess : []).filter((g) => g && typeof g === 'object');
  if (!guess.length) return T('src.none', { share: share || '' });
  if (short) {
    const names = guess.slice(0, 2).map((g) => String(g.name || '')).join(', ');
    const rest = guess.length + (Number(x.more) || 0) - Math.min(guess.length, 2);
    return T('src.guess_short', { list: rest > 0 ? T('src.guess_more', { list: names, n: rest }) : names });
  }
  return T('src.guess', { list: guessList(x, share) });
}

/**
 * The likely writers in full words (agent: watchmanFlowGuessText()): a container, VM or stack mapping the path, a User
 * Script naming it, a container's template while Docker didn't answer — at most three, «and {n} more»
 */
function guessList(x, share) {
  const guess = (Array.isArray(x.guess) ? x.guess : []).filter((g) => g && typeof g === 'object');
  const name = String(share || '').split('/').slice(1).join('/') || String(share || '');
  const one = (g) => {
    const how = [g.disk ? T('src.guess_disk', { share: name }) : T(g.t === 'us' ? 'src.guess_names' : 'src.guess_maps', { path: String(g.path || '') })];
    if (g.t === 'tpl') how.push(T('src.guess_tpl'));
    if (g.ro) how.push(T('src.guess_ro'));
    if (g.off && g.t !== 'tpl') how.push(T('src.guess_off'));
    const key = { vm: 'src.guess_vm', stack: 'src.guess_stack', us: 'src.guess_us' }[g.t] || 'src.guess_ct';
    return T(key, { name: String(g.name || ''), how: how.join(', ') });
  };
  const list = guess.slice(0, 3).map(one).join(', ');
  const more = Number(x.more) || 0;
  return more > 0 ? T('src.guess_more', { list, n: more }) : list;
}

/** Whose a router's line about this server likely is (agent: watchmanNetGuess()) — a guess, never a fact */
function netGuess(p) {
  if (!Array.isArray(p.guess)) return '';
  const one = (g) => {
    const t = g.t === 'vm' ? T('net_guess.vm', { name: String(g.name || '') })
      : g.port ? T('net_guess.port', { name: String(g.name || ''), port: String(g.port) }) : T('net_guess.addr', { name: String(g.name || '') });
    return g.off ? `${t}, ${T('src.guess_off')}` : t;
  };
  const list = p.guess.filter((g) => g && typeof g === 'object').map(one).join(', ');
  return list ? T('net_guess.text', { list }) : T('net_guess.none');
}

/** The biggest source behind an entry's line, or that none can be seen ('' without sources: an entry of an older office) */
function srcFrom(p) {
  if (p.disk) {        // written to an XFS/btrfs disk: measured for it whole (btrfs: where, if it could tell — agent: watchmanBtrfsWhere())
    const top = (p.where && Array.isArray(p.where.top)) ? p.where.top : [];
    const list = top.map((x) => `${x.share} (${fmt.size(x.b || 0)})`).join(', ');
    return ' ' + (top.length ? T(p.where.part ? 'src.disk_most_part' : 'src.disk_most', { list, n: fmt.number(20000) }) + ' ' : '') + T('gone.disk', { fs: p.fs || '' });
  }
  const top = Array.isArray(p.src) ? p.src[0] : null;
  if (!top || top.t === 'rest') return '';
  return ' ' + (top.t === 'none' ? srcNone(top, p.share, true) : T('src.most', { what: srcWhat(top), size: fmt.size(top.b || 0) }));
}

/**
 * Why a parity check runs (agent/lib/paritywhy.php paritywhyWhy()): its reason in words — after an unclean stop how it
 * ended and what held up the array, as far as Unraid's log shows it; a tip to keep the syslog when nothing was kept
 */
function parityWhy(p) {
  const reason = ['unclean', 'schedule', 'tuning', 'manual', 'rebuild'].includes(p.reason) ? p.reason : 'unknown';
  if (reason !== 'unclean') {
    const who = (p.logins || []).map((l) => `${l.user ? l.user + '@' : ''}${l.ip} (${loginService(l.service)})`);
    return T('parity.why.' + reason, { action: p.action || '', who: who.length ? [...new Set(who)].join(', ') : '–' });
  }
  const stop = ['timeout', 'late', 'crash'].includes(p.stop) ? p.stop : 'unknown';
  let out = T('parity.why.unclean_' + stop, { timeout: Number(p.timeout) || 90 });
  if (stop === 'timeout') out += p.held ? T('parity.held', { held: p.held }) : T('parity.held_none');
  if (!p.kept && (stop === 'timeout' || stop === 'unknown') && p.from !== 'previous' && !p.held) out += T('parity.keep_syslog');
  if ((Number(p.streak) || 0) >= 2) out += T('parity.repeat', { n: Number(p.streak) });
  return out;
}

/** What of a partner's door line changed (agent: watchmanPartnerCompare()), in words */
const doorWhat = (list) => (list || []).map((w) => T('door_what.' + (['from', 'restrict', 'command', 'key', 'options'].includes(w) ? w : 'options'))).join(', ');

/** What else an entry of vanished data says (agent: watchmanGoneMore()): its snapshots, who moved data then, a whole disk, pools asleep */
function goneMore(p) {
  const out = [];
  if (p.kept > 0) out.push(T('gone.kept', { size: fmt.size(p.kept) }));
  if (p.from === 'clients') out.push(T('gone.who_clients', { list: (p.clients || []).join(', ') }));
  else if (p.from === 'server') out.push(Array.isArray(p.guess) && p.guess.length ? T('gone.who_server_guess', { list: guessList(p, 'user/' + (p.share || '')) }) : T('gone.who_server'));
  if ((p.users || []).length) out.push(T('gone.who_smb', { users: p.users.join(', ') }));
  if (p.files !== undefined && p.bytes !== undefined) out.push(T('gone.files', { n: Number(p.files) || 0 }));
  if (p.files !== undefined && p.renames) out.push(T('gone.renames'));
  if (p.disk) out.push(T('gone.disk', { fs: p.fs || '' }));
  if ((p.asleep || []).length) out.push(T('gone.asleep', { pools: p.asleep.join(', ') }));
  return out.length ? ' ' + out.join(' ') : '';
}

/** What moves data now (agent: watchmanFlowMovers()), in words */
const movingText = (list) => (list || []).map((m) => (Office.has(`${ID}.moving.${m}`) ? T('moving.' + m) : m)).join(', ');

/** "usually at most 1 GB per hour at this time" — or why it was told while still learning */
function usualText(kind, p) {
  if (p.bytes === undefined && p.files !== undefined) {             // files deleted: counted, not measured
    if (p.learning) return T('flow.learning_files', { n: Number(p.limit) || 0 });
    return p.usual > 0 ? T('flow.usual_files', { n: Number(p.usual) }) : T('flow.usual_none');
  }
  if (p.learning) return (kind === 'flow_written' && !p.disk) || (kind === 'flow_gone' && p.part) ? T('flow.learning_share', { pct: p.pct || 0 }) : T('flow.learning', { size: fmt.size(p.limit || 0) });
  return p.usual > 0 ? T('flow.usual', { size: fmt.size(p.usual) }) : T('flow.usual_none');
}

/** An hour of the week (Monday 0:00 = 0) as "Mon 03:00" in this browser's language */
function hourName(h) {
  const d = new Date(2024, 0, 1 + Math.floor(h / 24), h % 24);      // 1 January 2024 was a Monday
  let day = '';
  try { day = new Intl.DateTimeFormat(Office.locale, { weekday: 'short' }).format(d); } catch (err) { day = String(Math.floor(h / 24) + 1); }
  return `${day} ${String(h % 24).padStart(2, '0')}:00`;
}

const goneLimits = () => {
  const l = (state && state.flow && state.flow.limits) || {};
  return { new: fmt.size(l.gone_new || 100 * 1024 ** 3), part: l.gone_part || 10, learn: (state && state.flow && state.flow.learn) || 7,
    files_min: fmt.number(l.files_min || 2000), files_new: fmt.number(l.files_new || 20000) };
};
const flowLimits = () => {
  const l = (state && state.flow && state.flow.limits) || {};
  return { keep: l.keep || 14, factor: l.factor || 4, min: fmt.size(l.min || 2 * 1024 ** 3), learn: (state && state.flow && state.flow.learn) || 7,
    new: fmt.size(l.new || 50 * 1024 ** 3), part: l.part || 20, files_new: fmt.number(l.files_new || 20000) };
};

/** docker run flags as small code chips (their words for the list's filter: wmWords, read by item()) */
function flags(tokens) {
  const box = el('span', 'wm-flags');
  (tokens || []).forEach((t) => box.appendChild(el('span', 'wm-flag', t)));
  box.wmWords = (tokens || []).map(String);
  return box;
}

const level = (n) => T('level.' + (n >= 2 ? 'public' : n === 1 ? 'secure' : 'none'));

/** A User Scripts schedule: its word (daily, at the array's start …) or the cron line in words */
const freq = (c) => (c && Office.has(`${ID}.freq.${c}`) ? T('freq.' + c) : fmt.cron(c || '') || '–');

/** Lines as a small list (mono), "… and N more" when there were more */
function lines(list, total) {
  const box = el('div', 'wm-lines');
  (list || []).forEach((x) => box.appendChild(el('div', 'mono', x)));
  const more = (Number(total) || 0) - (list || []).length;
  if (more > 0) box.appendChild(el('div', 'wm-note', T('detail.more', { n: more })));
  return box;
}

/** A command for Unraid's terminal: shown, copied on a click — never run by the office */
function fixBox(cmd) {
  const box = el('div', 'wm-fix');
  const code = el('code', 'mono', cmd);
  const b = el('button', 'btn small plain', Office.t('common.copy'));
  b.type = 'button';
  b.dataset.own = '1';
  b.onclick = () => Office.copy(cmd);
  box.append(code, b, el('div', 'wm-note', T('detail.fix_note')));
  return box;
}

function details(e) {
  const box = el('div', 'row-detail wm-detail');
  const dl = el('dl', 'kv');
  const add = (label, value, mono) => {
    if (value === null || value === undefined || value === '') return;
    const dd = el('dd', mono ? 'mono' : '');
    if (value instanceof Node) dd.appendChild(value); else dd.textContent = String(value);
    dl.append(el('dt', '', label), dd);
  };
  const p = e.p || {};
  add(T('detail.first'), fmt.date(e.time));
  if (e.last !== e.time) add(T('detail.last'), fmt.date(e.last));
  if (e.count > 1 && e.group !== 'snap') add(T('detail.count'), T('times', { n: e.count }));
  if (e.kind === 'kopia_emptied') {
    // the offsite copy emptied: the way back is the Consultant's Object Lock guide, its step with the read-only --point-in-time connection
    const adv = Office.desks.get('advisor');
    if (adv && adv.hired) {
      const b = el('button', 'btn small', T('detail.kopia_back'));
      b.type = 'button';
      b.dataset.own = '1';
      b.onclick = () => { Office.go('#/advisor'); Office.reveal('lock.8', { name: T('detail.kopia_back'), part: 'ext.kopia' }); };
      add(Office.t('advisor.name'), b);
    }
  } else if (e.kind === 'parity_check') {
    // the operation, how far it is, and where the reason was seen (Unraid's diagnostics, the syslog it kept, its own line)
    add(T('detail.parity_action'), p.action, true);
    const errors = T('parity.errors', { n: Number(p.errors) || 0 });
    add(T('detail.parity_state'), p.end ? T(Number(p.exit) === 0 ? 'parity.state_done' : 'parity.state_failed', { when: fmt.date(p.end), code: p.exit, errors })
      : T('parity.state_running'));
    const from = [];
    if (p.evidence && Office.has(`${ID}.parity.from_${p.evidence}`)) from.push(T('parity.from_' + p.evidence));
    if (p.from === 'diag' && p.diag) from.push(T('parity.from_diag', { name: p.diag }));
    else if (p.from === 'previous') from.push(T('parity.from_previous'));
    if (from.length) {
      const box = el('div', '');
      from.forEach((x) => box.appendChild(el('div', '', x)));
      add(T('detail.parity_from'), box);
    }
    if (p.reason === 'unclean' && !p.kept) {
      const a = el('a', '', T('parity.to_syslog'));
      a.href = '/Settings/SyslogSettings';      // into Unraid, in the same tab like its own links
      add(T('detail.parity_keep'), a);
    }
    if ((p.logins || []).length) add(T('detail.logins'), lines(p.logins.map((l) => `${fmt.time(l.t)} · ${l.user ? l.user + '@' : ''}${l.ip} · ${loginService(l.service)}`)));
  } else if (e.group === 'array') {
    // who had logged in around then (the syslog never says who clicked)
    const who = (p.logins || []).map((l) => `${fmt.time(l.t)} · ${l.user ? l.user + '@' : ''}${l.ip} · ${loginService(l.service)}`);
    add(T('detail.logins'), who.length ? lines(who) : T('detail.logins_none'));
    if (who.length) dl.lastChild.title = T('detail.logins_how');
  } else if (e.group === 'login') {
    add(T('detail.ip'), p.ip, true);
    const users = (p.users || []).join(', ');
    add(T('detail.users'), [users, p.unknown ? T('detail.unknown', { n: p.unknown }) : ''].filter(Boolean).join(' · '));
    add(T('detail.services'), (e.t && e.t.service) || '');
  } else if (e.kind === 'drill_throwaway') {
    // Mr. Restori's drill: its throwaways, as its record names them
    add(T('detail.drill'), p.id, true);
    add(T('detail.name'), (p.names || []).join(', '), true);
    add(T('detail.image'), p.image, true);
  } else if (e.group === 'container') {
    add(T('detail.name'), p.name);
    add(T('detail.image'), p.image, true);
    add(T('detail.rights'), flags(p.tokens));
  } else if (e.group === 'plugin') {
    add(T('detail.plugin'), p.name);
    add(T('detail.source'), p.source || T('watch.no_source'), true);
    if (e.kind === 'plugin_source') add(T('detail.old'), p.old || T('watch.no_source'), true);
    add(T('detail.version'), p.version);
  } else if (e.kind === 'flash_go') {
    add(T('detail.go'), p.gone ? T('detail.go_gone') : T('detail.go_lines', { added: p.added || 0, removed: p.removed || 0, lines: p.lines || 0 }));
    // the added lines' text (the only text of go he keeps, in this entry); of removed lines only their number
    if (!p.gone && (p.text || []).length) add(T('detail.go_added'), lines(p.text, p.added));
    if (!p.gone && p.removed > 0) add(T('detail.go_removed'), T('detail.go_removed_n', { n: Number(p.removed) }));
  } else if (e.kind === 'flash_extra') {
    add(T('detail.file'), '/boot/extra/' + (p.file || ''), true);
    add(T('detail.size'), fmt.size(p.size));
    add(T('detail.changed'), fmt.date(p.time));
  } else if (e.group === 'flash') {
    add(T('detail.user'), p.user);
    if (e.kind === 'flash_ssh_key') {
      add(T('detail.key'), [p.type, p.comment].filter(Boolean).join(' '), true);
      add(T('detail.fp'), p.fp, true);
    }
  } else if (e.group === 'share') {
    add(T('detail.share'), p.share);
    add(T('detail.access'), `${String(p.proto || '').toUpperCase()}: ${level(p.level === 'public' ? 2 : 1)}`);
  } else if (e.group === 'flow') {
    if (p.ip) add(T('detail.ip'), p.ip, true);
    if (p.machine) add(T('detail.machine'), p.machine);
    if (p.user) add(T('detail.user'), p.user);
    if (Array.isArray(p.users) && p.users.length) add(T('detail.users'), p.users.join(', '));
    if (p.service) add(T('detail.services'), (e.t && e.t.service) || p.service);
    if (p.name) add(T('detail.name'), p.name);
    if (p.image) add(T('detail.image'), p.image, true);
    if (p.share && !p.disk) add(T('detail.share'), p.share, true);      // a disk's entry carries its name as share for older pages only
    if (p.disk) add(T('detail.disk'), `${p.disk} (${p.fs || '?'})`, true);
    if (p.disk && (p.shares || []).length) add(T('detail.shares_on'), p.shares.join(', '));
    if (p.bytes !== undefined) {
      add(T(e.kind === 'flow_written' ? 'detail.written' : e.kind === 'flow_gone' ? 'detail.gone' : e.kind === 'flow_container' ? 'detail.sent' : 'detail.pulled'),
        flowSaid(e, fmt.size(flowShape(e) === 'hour' ? p.hour : p.bytes)));
      if ((e.kind === 'flow_written' || e.kind === 'flow_gone') && p.pct) add(T('detail.part'), `${fmt.number(p.pct)} %`);
      if (e.kind === 'flow_written' && Array.isArray(p.src) && p.src.length) {
        const box = el('div', '');
        p.src.forEach((x) => box.appendChild(el('div', '', x.t === 'none' ? srcNone(x, p.share) : srcLine(x))));
        add(T('detail.sources'), box);
      }
      if (e.kind === 'flow_gone' && p.kept) add(T('detail.kept'), fmt.size(p.kept));
      if (e.kind === 'flow_gone' && p.from) add(T('detail.who'), p.from === 'clients' ? (p.clients || []).join(', ') : T('detail.who_server'));
      if (e.kind === 'flow_gone' && (p.asleep || []).length) add(T('detail.asleep'), p.asleep.join(', '));
      if (e.kind === 'flow_gone' && p.files !== undefined) add(T('detail.files'), T('gone.files_n', { n: Number(p.files) || 0 }));
      if (!p.learning) add(T('detail.usual'), usualText(e.kind, p));
      if (p.peak) add(T('detail.peak'), fmt.size(p.peak));
    } else if (p.files !== undefined) {           // files deleted (ZFS per share, XFS per disk): a count
      add(T('detail.files'), flowSaid(e, T('gone.files_n', { n: Number(flowShape(e) === 'hour' ? p.hour : p.files) || 0 })));
      if (p.from) add(T('detail.who'), p.from === 'clients' ? (p.clients || []).join(', ') : T('detail.who_server'));
      if (!p.learning) add(T('detail.usual'), usualText(e.kind, p));
      if (p.peak) add(T('detail.peak'), T('gone.files_n', { n: Number(p.peak) }));
    }
    if (Array.isArray(p.hours) && p.hours.length) add(T('detail.hours'), p.hours.map(hourName).join(', '));
  } else if (e.group === 'snap') {
    add(T('detail.where'), `${p.where || '?'} (${p.fs === 'btrfs' ? 'btrfs' : 'ZFS'})`, true);
    if ((p.datasets || []).length) add(T('detail.datasets'), lines(p.datasets));
    add(T(e.kind === 'snap_gone' ? 'detail.snaps_gone' : 'detail.snaps_released'), lines(p.names, e.count));
    if (p.held) add(T('detail.held'), T('detail.held_n', { n: p.held }));
    if (p.fs !== 'btrfs') add(T('detail.history'), (p.history || []).length ? lines(p.history) : T('detail.history_none'));
    add(T('detail.evidence'), (p.evidence || []).length ? lines(p.evidence) : T('detail.evidence_none_snap'));
    if (e.kind === 'snap_gone' && (p.series || []).length) add(T('detail.series'), lines(p.series));
  } else if (e.group === 'host') {
    if (e.kind === 'log_cleared') {
      add(T('detail.log'), p.log, true);
      add(T('detail.how'), (p.how || []).map((h) => T('detail.how_' + h)).join(' · '));
      if ((p.sizes || []).length) add(T('detail.sizes'), lines(p.sizes));
    } else if (e.kind === 'user_ram') {
      add(T('detail.user'), p.user);
      add(T('detail.user_id'), String(p.uid));
      add(T('detail.why'), (p.why || []).map((w) => T('detail.why_' + w)).join(' · '));
    } else if (e.kind === 'listen_new') {
      add(T('detail.program'), p.prog, true);
      add(T('detail.port'), p.port === null || p.port === undefined ? T('detail.port_dynamic') : String(p.port));
      add(T('detail.addresses'), (p.addr || []).map((a) => (a === '*' ? T('detail.addr_all') : a)).join(', '));
    } else if (e.kind === 'proc_odd') {
      add(T('detail.program'), p.prog, true);
      add(T('detail.exe'), p.exe, true);
      add(T('detail.runs_in'), p.where || T('where.host'));
    } else if (e.kind === 'door_new') {
      add(T('detail.door'), doorWords(p));
      if (p.door === 'wg' || p.door === 'api_sso') add(T('detail.peers'), T('detail.peers_n', { n: p.peers || 0, new: p.new_peers || 0 }));
    } else if (e.kind === 'api_key_new' || e.kind === 'api_key_changed') {
      // Unraid's API: the key's name, roles and permissions as its file says — never its value (the agent never keeps it)
      add(T('detail.api_key'), p.name);
      add(T('detail.roles'), (p.roles || []).join(', ') || T('detail.roles_none'));
      if (e.kind === 'api_key_changed') add(T('detail.roles_old'), (p.old_roles || []).join(', ') || T('detail.roles_none'));
      add(T('detail.permissions'), (p.rights || []).length ? lines(p.rights, p.perms) : T('detail.roles_none'));
      if (p.full) add(T('detail.api_full'), T('detail.api_full_text'));
    }
  } else if (e.group === 'partner') {
    // a partner office's door (the Team Lead's pairing): never a key, only its fingerprint and addresses
    add(T('detail.partner'), p.name || p.id);
    if (e.kind === 'partner_paired') {
      if (p.kind === 'ticket') add(T('detail.ticket'), T('detail.ticket_text', { of: p.of || '', until: p.expires ? fmt.date(p.expires) : '?' }));
      add(T('detail.address'), p.address, true);
      add(T('detail.from'), p.from, true);
      add(T('detail.fingerprint'), p.fp, true);
    } else if (e.kind === 'door_changed') {
      add(T('detail.changed'), doorWhat(p.what));
      add(T('detail.from'), p.from || T('detail.from_none'), !!p.from);
      if (p.from_old !== p.from) add(T('detail.from_old'), p.from_old || T('detail.from_none'), !!p.from_old);
      add(T('detail.restrict'), T(p.restrict ? 'detail.restrict_on' : 'detail.restrict_off'));
      add(T('detail.command'), T(p.command ? 'detail.command_door' : 'detail.command_other'));
      if ((p.others || []).length) add(T('detail.options'), p.others.join(', '), true);
    } else if (e.kind === 'door_key_moved') {
      add(T('detail.ip'), p.ip, true);
      add(T('detail.how'), T(p.how === 'key' ? 'detail.moved_key' : 'detail.moved_address'));
      add(T('detail.partner_ips'), (p.ips || []).join(', '), true);
      add(T('detail.fingerprint'), p.fp, true);
      if ((p.users || []).length) add(T('detail.user'), p.users.join(', '));
    } else if (e.kind === 'door_refused') {
      add(T('detail.refused_why'), (p.why || []).join(', '), true);
      if (!p.known) add(T('detail.partner_unknown'), T('detail.partner_unknown_text'));
    }
  } else if (e.group === 'net') {
    netDetails(e, p, add);
  } else if (e.group === 'sched') {
    // a plugin's at job names its file in «runs» (a file of the plugin, or /usr/bin/plugin_update_helper — not on the flash)
    if (p.file && e.kind !== 'at_plugin') add(T('detail.cron_file'), '/boot/config/plugins/' + p.file + (p.new ? ` (${T('detail.file_new')})` : ''), true);
    if (p.plugin) add(T('detail.plugin'), p.plugin);
    const atNum = e.kind.startsWith('at_') && /^[a-zA-Z=][0-9a-f]{13}$/.test(p.job || '') ? parseInt(p.job.slice(1, 6), 16) : null;     // as atq shows it
    if (atNum !== null) add(T('detail.at_number'), String(atNum), true);
    else if (p.job) add(T('detail.job'), p.job, true);
    if (p.jobs && p.jobs.length) add(T('detail.jobs'), lines(p.jobs, p.lines));
    if (e.kind === 'script_new' || e.kind === 'script_changed') {
      add(T('detail.script'), p.name);
      add(T('detail.schedule'), freq(p.cron));
      if (e.kind === 'script_changed' && p.old !== p.cron) add(T('detail.schedule_old'), freq(p.old));
      if (e.kind === 'script_changed') add(T('detail.content'), T(p.content ? 'detail.content_changed' : 'detail.content_same'));
    }
    if (e.kind === 'at_job') {
      if (p.queued) add(T('detail.queued'), fmt.date(p.queued));
      add(T('detail.when'), fmt.date(p.when));
      if (p.run && p.since) add(T('detail.running_since'), fmt.date(p.since));
      add(T('detail.command'), p.cmd || '?', true);
      if (p.user || (p.uid !== null && p.uid !== undefined)) add(T('detail.uid'), p.user || String(p.uid));
      if (p.prog) add(T('detail.file_owner'), `${p.prog}: ${p.owner || '–'}`, true);
    }
    if (e.kind === 'at_userscript') {
      add(T('detail.script'), p.name);
      add(T('detail.started'), fmt.date(p.when));
    }
    if (e.kind === 'at_plugin') {
      add(T('detail.when'), fmt.date(p.when));
      add(T('detail.command'), p.file || '?', true);
      if (p.left) add(T('detail.plugin_removed'), fmt.date(p.left));     // what its removed plugin left (agent: watchmanAtLeftNote())
    }
    if (e.kind === 'notify_agent') {
      add(T('detail.agent'), p.name);
      add(T('detail.content'), T(p.new ? 'detail.file_new' : 'detail.content_changed'));
    }
    if (p.mtime) add(T('detail.file_time'), fmt.date(p.mtime));
    if (Array.isArray(p.evidence)) add(T('detail.evidence'), p.evidence.length ? lines(p.evidence) : T('detail.evidence_none'));
    if (e.t && e.t.fix) add(T('detail.fix'), fixBox(e.t.fix));
  }
  box.appendChild(dl);
  const notes = [];
  if (e.group === 'flow' && p.learning) notes.push(T('detail.learning'));
  if (p.office && e.kind.startsWith('cron_file')) notes.push(T('detail.office_cron'));
  else if (p.office_file && e.kind.startsWith('cron_file')) notes.push(T('detail.office_file'));
  if (e.open) notes.push(T('adopt.' + (e.v === 'net_ips_server_list' ? e.v : e.kind)));     // a block list: its own words (the whole list)
  const by = e.by === 'office' && e.kind === 'partner_paired' ? 'office_partner' : e.by === 'office' && e.kind === 'drill_throwaway' ? 'office_drill' : e.by;
  if (e.noted && by === 'plugin' && e.kind === 'at_plugin' && p.left) notes.push(T('noted.plugin_left', { when: fmt.date(p.left) }));     // agent: watchmanAtLeftNote()
  else if (e.noted) notes.push(T('noted.' + (['teamlead', 'baseline', 'auto', 'office', 'office_partner', 'office_drill', 'office_cron', 'schedule', 'array', 'unraid', 'plugin', 'back', 'router', 'parity', 'rule', 'known'].includes(by) ? by : 'page'),
    { when: fmt.date(e.noted), at: fmt.date(Number(e.p && e.p.known_at) || e.noted) }));
  // you knew it — as it was then: something has changed since (1.96, the answer's fingerprint differs)
  if (e.open && e.p && Number(e.p.known_at) > 0) notes.push(T('known.changed', { when: fmt.date(Number(e.p.known_at)) }));
  if (e.told) notes.push(T('detail.told', { when: fmt.date(e.told) }));
  else if (e.muted && e.tell) notes.push(T('detail.muted'));
  else if (e.open) notes.push(T(e.tell ? 'detail.not_told' : 'detail.book_only'));
  notes.forEach((n) => box.appendChild(el('p', 'wm-note', n)));
  return box;
}

/**
 * A network entry's details (agent: watchnetEvent()): the router, its file, what the entry is about — another device's
 * address, MAC or name only for a new device or one claiming the server's place —, the router's line (scrubbed there)
 */
function netDetails(e, p, add) {
  add(T('detail.router'), p.router || p.sender || '');
  if (p.sender && p.sender !== p.router) add(T('detail.sender'), p.sender, true);
  switch (e.kind) {
    case 'net_sender_new':
      if (p.product) add(T('detail.version'), `${p.product} ${p.version || ''}`.trim());
      break;
    case 'net_new_device':
      add(T('detail.device'), p.name || '–');
      add(T('detail.mac'), p.mac + (p.random ? ` (${T('detail.random')})` : ''), true);
      add(T('detail.ip'), p.ip || '–', true);
      if (p.network) add(T('detail.network'), p.network);
      if (p.taken) add(T('detail.taken'), T('detail.taken_text', { mac: p.taken }));
      break;
    case 'net_spoof':
      add(T('detail.mac'), p.mac, true);
      add(T('detail.device'), [p.device, p.device_ip].filter(Boolean).join(' · ') || '–');
      add(T('detail.server_macs'), (p.known || []).join(', ') || '–', true);
      break;
    case 'net_router_login':
      add(T('detail.admin'), p.admin + (p.new_admin ? ` (${T('detail.admin_new')})` : ''));
      add(T('detail.ip'), p.ip || '?', true);
      if (p.method) add(T('detail.how'), p.method);
      break;
    case 'net_firewall_change':
    case 'net_router_config':
      add(T('detail.admin'), (p.admins || []).join(', '));
      if (p.area) add(T('detail.area'), T('net_area.' + p.area));
      if ((p.msgs || []).length) add(T('detail.changes'), lines(p.msgs, e.count));
      break;
    case 'net_vpn_login':
      add(T('detail.vpn_user'), p.user || '?');
      add(T('detail.ip'), p.ip || '?', true);
      if (p.how) add(T('detail.how'), p.how);
      break;
    case 'net_ips_server':
      add(T('detail.signature'), `${p.signature || ''} (${p.sig || '?'})`);
      add(T('detail.direction'), T(p.dir === 'out' ? 'detail.dir_out' : 'detail.dir_in'));
      add(T('detail.remote'), [p.remote, p.dpt ? `${(p.proto || '').toLowerCase()}/${p.dpt}` : ''].filter(Boolean).join(' '), true);
      if (p.risk) add(T('detail.risk'), p.risk);
      if (Array.isArray(p.guess)) add(T('detail.net_guess'), netGuess(p));
      break;
    case 'net_blocked_from_server':
      add(T('detail.destination'), `${p.dst} ${(p.proto || '').toLowerCase()}${p.dpt ? '/' + p.dpt : ''}`, true);
      add(T('detail.rule'), `${p.zone || ''} ${p.action || ''} ${p.rule || ''}`.trim(), true);
      break;
    case 'net_log_silent':
      add(T('detail.last_line'), fmt.date(p.last));
      if (p.usual) add(T('detail.usual_gap'), fmt.duration(p.usual));
      break;
    case 'net_router_login_failures':
      add(T('detail.ip'), p.ip || T('detail.console'), !!p.ip);
      if ((p.methods || []).length) add(T('detail.how'), p.methods.join(', '));
      if ((p.users || []).length) add(T('detail.admins_tried'), p.users.join(', '));
      if (p.unknown) add(T('detail.names_unknown'), T('detail.names_unknown_text', { n: p.unknown }));
      if (p.vpn) add(T('detail.vpn_fail'), T('detail.vpn_fail_text'));
      break;
    case 'net_link_down':
      add(T('detail.iface'), p.iface, true);
      add(T('detail.link_down'), fmt.date(p.down));
      add(T('detail.link_back'), p.back ? `${fmt.date(p.back)} (${T('detail.minutes', { n: p.minutes || 0 })})` : T('detail.still_down'));
      if (p.flapping) add(T('detail.flapping'), T('detail.flapping_text', { n: e.count }));
      break;
    case 'net_outage':
      add(T('detail.iface'), `${p.iface}${p.via ? ` (${p.via === 'pppoe' ? 'PPPoE' : 'DHCP'})` : ''}`, true);
      add(T('detail.outage_from'), fmt.date(p.from));
      if (p.down) add(T('detail.outage_now'), T('detail.outage_since', { when: fmt.date(p.down), n: p.minutes || 0 }));
      else add(T('detail.outage_to'), p.to ? fmt.date(p.to) : T('detail.outage_unknown'));
      if (e.count > 1) add(T('detail.outages'), T('detail.outages_text', { n: e.count, minutes: p.minutes || 0, longest: Math.max(1, Math.round((p.longest || 0) / 60)) }));
      if (p.reason) add(T('detail.reason'), p.reason, true);
      break;
    case 'net_router_reboot':
      add(T('detail.restart'), T(p.clean ? 'detail.restart_clean' : 'detail.restart_unclean'));
      if ((p.admins || []).length) add(T('detail.restart_by'), p.admins.join(', '));
      if (p.power) add(T('detail.power'), T('detail.power_text', { when: fmt.date(p.power) }));
      break;
    default:
  }
  if (p.evidence) add(T('detail.router_line'), p.evidence, true);
}

// ------------------------------------------------------------------ what holds the array
/*
 * «What holds the array?» (agent/lib/arrayhold.php): what would hold up stopping the array or shutting down, right now —
 * looked at in a job of his on the server when the page opens (at most every HOLD_AGAIN s) and on «Check now»; read only.
 * Four groups: what holds it (a file or a working folder there), whom Unraid waits for (VMs, containers: how long), what
 * stops by itself, what is not the array — and whether Unraid's shutdown time-out is enough.
 */
const HOLD_AGAIN = 120;
const holdOf = () => (state && state.hold) || {};
const holdLook = () => holdOf().look || null;
const holdRunning = () => !!holdOf().running;

async function holdCheck(quiet) {
  if (holdRunning() || !Office.agent.running || !hired()) return;
  const j = await Office.api.post(`${ID}.hold`, {});
  if (!j.ok) { if (!quiet) Office.toast(Office.errorText(j.error, ID), true); return; }
  if (j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  follow();
}

/** Opening his page looks again when the last look is older than HOLD_AGAIN (or there is none since the server started) */
function holdAuto() {
  const l = holdLook();
  if (!l || Date.now() / 1000 - (Number(l.time) || 0) > HOLD_AGAIN) holdCheck(true);
}

function holdTile(a) {
  const l = holdLook();
  const n = l ? Number(l.count) || 0 : 0;
  const short = !!(l && l.started && l.times && l.times.fits === false);
  const value = holdRunning() && !l ? T('hold.running') : !l ? '–' : !l.started ? T('tile.hold_stopped') : n ? T('tile.hold_n', { n }) : T('tile.hold_none');
  return lightTile({ label: T('tile.hold'), value, level: catLevel('hold') || (n || short ? 'warn' : l ? 'ok' : ''),
    sub: !l ? T('tile.hold_never') : [short ? T('tile.hold_short') : '', T('tile.hold_when', { when: fmt.relative(l.time) })].filter(Boolean).join(' · '), ...a });
}

function holdSection() {
  const s = el('section', 'section wm-hold');
  const b = el('button', 'btn small plain' + (holdRunning() ? ' running' : ''));
  b.type = 'button';
  b.append(el('span', 'spin'), T('hold.check'));
  b.title = T('hold.check_title');
  b.disabled = !Office.agent.running || holdRunning() || !hired();
  b.onclick = () => holdCheck(false);
  s.appendChild(Office.sectionHead(T('hold.title'), T('hold.sub'), b, { place: 'hold.title' }));
  const l = holdLook();
  if (!l) {
    s.appendChild(el('p', 'role', T(holdRunning() ? 'hold.looking' : 'hold.never')));
    return s;
  }
  if (!l.started) {
    s.appendChild(el('p', 'role', T('hold.stopped')));
    return s;
  }
  const n = Number(l.count) || 0;
  const t = l.times || {};
  s.appendChild(el('p', 'callout' + (n || t.fits === false ? ' warn' : ''), [n ? T('hold.verdict_n', { n }) : T('hold.verdict_none'),
    T(t.fits === false ? 'hold.times_short' : 'hold.times_fits', { disk: t.disk, need: t.need, detail: holdNeed(t) })].join(' ')));
  const when = el('p', 'role', T('hold.when', { when: fmt.relative(l.time), ms: Number(l.ms) || 0 }) + (l.cut ? ' ' + T('hold.cut') : ''));
  when.title = fmt.date(l.time);
  s.appendChild(when);
  const group = (key, sub, rows) => {
    s.appendChild(el('h4', 'wm-hold-group', T(key)));
    if (sub) s.appendChild(el('p', 'role', sub));
    const box = el('div', 'box');
    rows.forEach((r) => box.appendChild(r));
    s.appendChild(box);
  };
  const holds = (l.holds || []).filter((h) => h && typeof h.kind === 'string');
  group('hold.holds', T(holds.length ? 'hold.holds_sub' : 'hold.holds_none'), holds.map(holdRow)
    .concat(l.more ? [el('div', 'row nocheck role', T('hold.more_things', { n: l.more }))] : []));
  const vms = (l.vms || []).filter((v) => v && typeof v.name === 'string');
  const cts = Array.isArray(l.containers) ? l.containers.filter((c) => c && typeof c.name === 'string') : null;
  const waits = vms.map((v) => vmRow(v, t)).concat((cts || []).map((c) => ctRow(c, t)));
  if (!vms.length) waits.unshift(el('div', 'row nocheck role', T('hold.no_vms')));
  if (!cts) waits.push(el('div', 'row nocheck role', Office.t('common.docker_off')));
  else if (!cts.length) waits.push(el('div', 'row nocheck role', T('hold.no_containers')));
  group('hold.waits', T('hold.waits_sub', { docker: t.docker, vm: t.vm }), waits);
  const itself = (l.itself || []).filter((x) => x && typeof x.kind === 'string');
  if (itself.length) group('hold.itself', T('hold.itself_sub'), itself.map(itselfRow));
  // ZFS snapshots mounted right now (on the server, inside containers, in other processes) — said, not counted as holding the array
  const sn = l.snaps && typeof l.snaps === 'object' ? l.snaps : null;
  const snCts = sn && Array.isArray(sn.containers) ? sn.containers.filter((c) => c && typeof c.name === 'string' && Number(c.n) > 0) : [];
  if (sn && (Number(sn.host) > 0 || snCts.length || Number(sn.process) > 0)) {
    const snRow = (text, title) => {
      const r = el('div', 'row nocheck');
      const main = el('div', 'row-main');
      const name = el('div', 'row-name text', text);
      name.title = title;
      main.appendChild(name);
      r.appendChild(main);
      return r;
    };
    group('hold.snaps', T('hold.snaps_sub'), (Number(sn.host) > 0 ? [snRow(T('hold.snaps_host', { n: Number(sn.host) }), T('hold.snaps_host_title'))] : [])
      .concat(snCts.map((c) => snRow(T('hold.snaps_container', { name: c.name, n: Number(c.n) }), T('hold.snaps_container_title'))))
      .concat(Number(sn.process) > 0 ? [snRow(T('hold.snaps_process', { n: Number(sn.process) }), T('hold.snaps_process_title'))] : []));
  }
  const outside = (l.outside || []).filter((x) => x && typeof x.mount === 'string');
  if (outside.length) group('hold.outside', T('hold.outside_sub'), outside.map((x) => {
    const r = el('div', 'row nocheck');
    const main = el('div', 'row-main');
    main.appendChild(el('div', 'row-name text', x.mount));
    const meta = el('div', 'row-meta');
    meta.appendChild(chip(T('hold.out_' + (x.kind === 'remote' ? 'remote' : 'ud')), 'quiet', T('hold.out_' + (x.kind === 'remote' ? 'remote' : 'ud') + '_title')));
    if (x.type) meta.appendChild(chip(x.type, '', T('hold.out_type')));
    main.appendChild(meta);
    r.appendChild(main);
    return r;
  }));
  return s;
}

/** «about 140 s: 50 s for the containers + 60 s for the VMs + 30 s to unmount» — only the parts there are */
function holdNeed(t) {
  return [t.containers ? T('hold.need_ct', { s: t.containers }) : '', t.vms ? T('hold.need_vm', { s: t.vms }) : '', T('hold.need_margin', { s: t.margin })]
    .filter(Boolean).join(' + ');
}

/** One thing that holds it: what it is, its files there (five at most), what to do */
function holdRow(h) {
  const r = el('div', 'row nocheck wm-hold-row');
  const main = el('div', 'row-main');
  const kind = ['shell', 'userscript', 'plugin', 'cron', 'loop'].includes(h.kind) ? h.kind : 'program';
  const via = ['ssh', 'web', 'tmux', 'screen'].includes(h.via) ? h.via : 'ssh';
  main.appendChild(el('div', 'row-name text', kind === 'shell' ? T('hold.k.shell_' + via) : T('hold.k.' + kind, { name: String(h.name || ''), prog: String(h.prog || '') })));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('hold.holds_chip'), 'warn', T('hold.holds_chip_title')));
  if (kind !== 'loop' && h.prog) meta.appendChild(chip(String(h.prog), '', T('hold.prog_title', { pid: Number(h.pid) || 0 })));
  if (Number(h.procs) > 1) meta.appendChild(chip(T('hold.procs', { n: Number(h.procs) }), '', T('hold.procs_title')));
  if (kind === 'loop' && h.mount) meta.appendChild(chip(T('hold.loop_at', { mount: String(h.mount) }), '', T('hold.loop_at_title')));
  main.appendChild(meta);
  (h.files || []).forEach((f) => { if (typeof f === 'string') main.appendChild(el('div', 'row-detail text wm-hold-file', f)); });
  if (h.more) main.appendChild(el('div', 'row-detail role', T('hold.more_files', { n: Number(h.more) })));
  main.appendChild(el('div', 'row-detail role', T('hold.how.' + kind)));
  r.appendChild(main);
  return r;
}

/** A running VM: its guest agent, how long Unraid waits for it */
function vmRow(v, t) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', v.name));
  const meta = el('div', 'row-meta');
  const agent = ['connected', 'disconnected', 'none'].includes(v.agent) ? v.agent : 'none';
  meta.appendChild(chip(T('hold.vm_agent_' + agent), agent === 'connected' ? 'ok' : 'warn', T('hold.vm_agent_' + agent + '_title')));
  if (v.state === 'paused') meta.appendChild(chip(T('hold.vm_paused'), '', T('hold.vm_paused_title')));
  meta.appendChild(chip(T('hold.wait_s', { s: t.vm }), '', T('hold.vm_wait_title', { s: t.vm })));
  main.appendChild(meta);
  if (agent !== 'connected') main.appendChild(el('div', 'row-detail role', T(v.windows ? 'hold.vm_windows' : 'hold.vm_no_agent', { s: t.vm })));
  r.appendChild(main);
  return r;
}

/** A running container: Unraid's own or not, how long it gets, whether it asks for more */
function ctRow(c, t) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', c.name));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('hold.wait_s', { s: Number(c.gets) || 0 }), '', T(c.managed ? 'hold.ct_unraid_title' : 'hold.ct_compose_title', { s: Number(c.gets) || 0 })));
  if (!c.managed) meta.appendChild(chip(T('hold.ct_compose'), 'quiet', T('hold.ct_compose_title', { s: Number(c.gets) || 0 })));
  if (c.short) meta.appendChild(chip(T('hold.ct_short', { own: Number(c.own) || 0 }), 'warn', T('hold.ct_short_title', { own: Number(c.own) || 0, s: Number(c.gets) || 0 })));
  main.appendChild(meta);
  r.appendChild(main);
  return r;
}

/** What stops by itself: the office's jobs, Unraid's mover, an SMB client's open files, Unraid's local syslog server */
function itselfRow(x) {
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  const files = Number(x.files) || 0;
  let name;
  if (x.kind === 'office') name = T('hold.office', { desk: Office.t(`${x.desk}.name`) });
  else if (x.kind === 'mover') name = T('hold.mover');
  else if (x.kind === 'smb') name = x.machine ? T('hold.smb', { machine: String(x.machine) }) : T('hold.smb_someone');
  else if (x.kind === 'syslog') name = T('hold.syslog');
  else name = T('hold.nfs');
  main.appendChild(el('div', 'row-name text', name));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip(T('hold.itself_chip'), 'ok', T('hold.itself_' + (['office', 'mover', 'smb', 'syslog'].includes(x.kind) ? x.kind : 'nfs') + '_title')));
  if (files) meta.appendChild(chip(T('hold.files_open', { n: files }), '', T('hold.files_open_title')));
  main.appendChild(meta);
  if (x.kind === 'smb' && files) main.appendChild(el('div', 'row-detail role', T('hold.smb_text')));
  r.appendChild(main);
  return r;
}

// ------------------------------------------------------------------ who woke my disks?
/*
 * «Who woke my disks?» (agent/desks/watchman-diskwakes.php; Ms. Protocolli's up to 1.95): every wake of a spinning disk he
 * noticed in the last 7 days — per disk how often (a click shows only its wakes: a filter, never kept, said in the note
 * line) and per wake who he caught waiting for it while it spun up, the surest first; «Not found out» when nobody was. Only
 * rotating disks are watched; on a server without one (all flash: SSDs, NVMe) the tile and its section say so in one line —
 * a hidden tile looked like a bug. Beside «What holds the array?»: both say who touches the disks.
 */
let wakeDisk = '';              // the disk chosen in «By disk»: a filter, never kept
let shownWakes = [];            // the unfoldable rows of the wakes: {open(), set(open)}
const unfoldedWakes = new Set(); // wakes unfolded on this visit
const wakesOf = () => (state && state.wakes && Array.isArray(state.wakes.wakes) ? state.wakes.wakes : []);
/** No rotating disk there (the state says so) and no wake kept from before: nothing to watch — an older messenger's
    state says nothing (no `hdds`), the tile shows as it always did */
const wakesNoHdd = () => !!(state && state.wakes && Array.isArray(state.wakes.hdds) && !state.wakes.hdds.length && !wakesOf().length);
// the wakes' filter bar (core.js Office.filterBar, built once like the book's): its words and the disk chosen are never kept
let wakesBar = null;
function wakesBarGet() {
  wakesBar ??= Office.filterBar({ key: 'watchman.wakes', label: T('wakes.filter_label'), onChange: () => { if (view) sw.render(); } });
  return wakesBar;
}
/** Midnight of the state's day (the server looked just now; a kept state counts by its own day) */
const startOfToday = () => { const d = new Date(((state && state.time) || Date.now() / 1000) * 1000); d.setHours(0, 0, 0, 0); return d.getTime() / 1000; };
const wakeHead = (text) => { const h = el('div', 'wm-wake-subhead'); h.appendChild(el('span', 'wm-wake-subhead-name', text)); return h; };

/** What woke it, in words: «Plex (container)», «SMB: laptop», «Scheduled task: …», «Mr. Backupsy (the office)» */
function causeName(c) {
  if (!c) return T('wakes.cause.none');
  const name = c.name || '';
  switch (c.kind) {
    case 'container': return T('wakes.cause.container', { name: name || c.prog });
    case 'vm': return T('wakes.cause.vm', { name: name || c.prog });
    case 'smb': return name ? T('wakes.cause.smb', { name }) : T('wakes.cause.smb_anon');
    case 'nfs': return T('wakes.cause.nfs');
    case 'office': return c.desk && Office.desks.has(c.desk) ? T('wakes.cause.office', { name: Office.t(c.desk + '.name') }) : T('wakes.cause.office_anon');
    case 'mover': return T('wakes.cause.mover');
    case 'cron': return T('wakes.cause.cron', { name: name || c.prog });
    default:
      if (c.prog === 'shfs') return T('wakes.cause.shfs');
      return c.by ? T('wakes.cause.program_by', { name: c.prog, by: starterName(c) }) : T('wakes.cause.program', { name: c.prog });
  }
}

/** Who started a plain program: Unraid, its web page, a plugin, a user script, a terminal (agent/lib/procwho.php procwhoStarter()) */
function starterName(c) {
  switch (c.by) {
    case 'plugin': return T('wakes.by.plugin', { name: c.byname || '?' });
    case 'user_script': return T('wakes.by.user_script', { name: c.byname || '?' });
    default: return T('wakes.by.' + c.by);
  }
}

/** The tile (a number, like «What holds the array?»): the wakes in 7 days and today's — or why there is no number */
function wakesTile(a) {
  const all = wakesOf();
  const w = state && state.wakes;
  let value = '–';
  let sub = [];
  if (!w) sub = [state ? T('tile.wakes_not_yet') : Office.t('common.loading')];
  else if (wakesNoHdd()) sub = [T('tile.wakes_no_hdd')];
  else if (w.on === false) [value, sub] = [T('wakes.tile_off'), all.length ? [T('wakes.tile_week', { n: all.length })] : []];
  else if (!all.length) [value, sub] = [T('tile.wakes_none'), [T('wakes.tile_since', { when: fmt.date(w.since) })]];
  else {
    const today = all.filter((x) => x.time >= startOfToday()).length;
    [value, sub] = [T('tile.wakes_n', { n: all.length }), [T('tile.wakes_week'), today ? T('tile.wakes_today', { n: today }) : ''].filter(Boolean).join(' · ')];
  }
  return lightTile({ label: T('wakes.title'), value, sub, level: catLevel('wakes'), cls: 'wm-wakes-tile', ...a });
}

/** His switch in the section's head: on unless switched off, kept on the server (never in the browser) */
function wakesSwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = state.wakes.on !== false;
  cb.disabled = !Office.agent.running;
  label.append(cb, el('span', '', T('wakes.switch')));
  label.title = T('wakes.switch_title');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.wakes_watch`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    Office.keepInPlace(label, () => sw.render());
    Office.toast(T(on ? 'wakes.switched_on' : 'wakes.switched_off'));
  };
  return label;
}

/** Per disk: how often today and in 7 days, and what woke it most often */
function wakesByDisk(all) {
  const by = new Map();
  for (const w of all) {
    for (const d of w.disks) {
      if (!by.has(d)) by.set(d, { today: 0, week: 0, causes: new Map() });
      const x = by.get(d);
      x.week += 1;
      if (w.time >= startOfToday()) x.today += 1;
      const who = trailLeads(w) ? T('wakes.trail_most', { dir: trailTop(trailDirs(w)[0]) }) : causeName(w.who[0]);
      x.causes.set(who, (x.causes.get(who) || 0) + 1);
    }
  }
  return [...by.entries()].sort((p, q) => q[1].week - p[1].week || p[0].localeCompare(q[0]));
}

function wakesView() {
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  const label = () => { unfold.textContent = shownWakes.some((x) => !x.open()) ? T('unfold_all') : T('fold_all'); };
  unfold.onclick = () => {
    const open = shownWakes.some((x) => !x.open());
    Office.keepInPlace(unfold, () => shownWakes.forEach((x) => x.set(open)));
    label();
  };
  shownWakes = [];
  const look = (state && state.wakes && state.wakes.look) || 4;
  const box = Office.place('search.wakes', el('section', 'section wm-wakes'));      // the search's «disk wake-ups, spin-up»
  const known = !!(state && state.wakes);
  const noHdd = wakesNoHdd();
  box.appendChild(Office.sectionHead(T('wakes.title'), T('wakes.sub', { s: look }), ...(known && !noHdd ? [wakesSwitch()] : []), unfold, { place: 'wakes.title' }));
  unfold.hidden = true;
  if (!known || noHdd) {
    // before her first look: not yet / loading; no spinning disk: that one line — no switch, no filter bar, no empty list
    box.appendChild(el('p', 'empty', noHdd ? T('wakes.no_hdd') : state ? T('wakes.not_yet') : Office.t('common.loading')));
    return box;
  }
  if (state.wakes.on === false) box.appendChild(el('p', 'callout', T('wakes.off_note')));
  const all = wakesOf().slice().sort((p, q) => q.time - p.time);
  if (wakeDisk && !all.some((w) => w.disks.includes(wakeDisk))) wakeDisk = '';
  // by disk: a row each, a click shows only its wakes
  const disks = el('div', 'box wm-wake-disks');
  disks.appendChild(wakeHead(T('wakes.by_disk')));
  for (const [disk, x] of wakesByDisk(all)) {
    const on = wakeDisk === disk;
    const r = el('div', 'row nocheck wm-wake-disk' + (on ? ' wm-wake-on' : ''));
    const main = el('div', 'row-main');
    const name = el('div', 'row-name', disk);
    name.title = on ? T('wakes.disk_all_title') : T('wakes.disk_only_title', { disk });
    const meta = el('div', 'row-meta');
    if (x.today) meta.appendChild(el('span', '', T('wakes.n_today', { n: x.today })));
    meta.appendChild(el('span', '', T('wakes.n_week', { n: x.week })));
    const [most, n] = [...x.causes.entries()].sort((p, q) => q[1] - p[1])[0];
    meta.appendChild(el('span', 'wm-wake-most', T('wakes.most', { who: most, n })));
    main.append(name, meta);
    r.appendChild(main);
    r.onclick = () => Office.keepInPlace(box, () => { wakeDisk = on ? '' : disk; sw.render(); });
    disks.appendChild(r);
  }
  if (all.length) box.appendChild(disks);
  // every wake, newest first: the bar's words over disks, causes, files, commands; the time by the wake's
  const bar = wakesBarGet();
  box.appendChild(bar.el);
  const f = bar.read();
  const fits = all.filter((w) => (!wakeDisk || w.disks.includes(wakeDisk))
    && Office.fits(f.words, [w.disks.join(' '), ...w.who.map((c) => [causeName(c), T('wakes.sure.' + c.sure), c.prog, c.cmd, ...(c.files || [])].join(' ')), ...(w.cron || []), ...(w.started || []),
      w.who.length ? '' : T('wakes.cause.none'), ...trailDirs(w).map((d) => trailName(w, d))]));
  const list = el('div', 'box');
  list.appendChild(wakeHead(T('wakes.every')));
  for (const w of fits) list.appendChild(wakeRow(w, look));
  if (!fits.length) list.appendChild(el('p', 'empty', all.length ? T('wakes.none_fit') : T('wakes.none')));
  box.appendChild(list);
  bar.done(fits.length, all.length, { tile: wakeDisk, clear: () => { wakeDisk = ''; } });
  if (state.wakes.on !== false) box.appendChild(el('p', 'role wm-wake-note', T('wakes.note', { when: fmt.date(state.wakes.since), s: look })));
  label();
  unfold.hidden = shownWakes.length < 2;
  return box;
}

/** A cron line due then: the office's own by the desk whose job it is (office:<job>), else its command */
const JOB_DESK = { backup: 'backup', snapshots: 'snapshot', embycache: 'jack', gather: 'jack', moverelli: 'moverelli' };
function cronName(x) {
  const job = /^office:([a-z]+)$/.exec(x);
  if (!job) return x;
  const desk = JOB_DESK[job[1]];
  return T('wakes.office_job', { name: desk && Office.desks.has(desk) ? Office.t(desk + '.name') : job[1] });
}

/** A time to the second (the trail's «17:56:18–17:56:23») */
function secTime(seconds) {
  const d = new Date(seconds * 1000);
  try { return new Intl.DateTimeFormat(Office.locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(d); } catch (e) { return d.toLocaleTimeString(); }
}

/** The trail's folders (what was written on the woken disk then): their names, the disk said when there are several */
const trailDirs = (w) => (w.trail && Array.isArray(w.trail.dirs) ? w.trail.dirs : []);
/** The trail leads a wake when nobody surer than «maybe» was caught (everyone just waiting, no file of theirs there) */
const trailLeads = (w) => trailDirs(w).length > 0 && (!w.who.length || w.who.every((c) => c.sure === 'maybe'));
/** A trail folder's first two levels for counting by disk («Media/Music videos» of «Media/Music videos/Rock/Season 47») */
const trailTop = (d) => (d.dir ? d.dir.split('/').slice(0, 2).join('/') : T('wakes.trail_top'));
function trailName(w, d) {
  const many = new Set(trailDirs(w).map((x) => x.disk)).size > 1;
  return (many ? d.disk + ': ' : '') + (d.dir || T('wakes.trail_top'));
}

/** One folder of the trail: «Mediathek/Musik-Videos: 12 files (3.1 MB) written 17:56:18–17:56:23» */
function trailLine(w, d) {
  const p = { n: d.n, dir: trailName(w, d), size: fmt.size(d.bytes) };
  if (!d.first) return T('wakes.trail_dir_untimed', p);
  const when = d.last && d.last !== d.first ? `${secTime(d.first)}–${secTime(d.last)}` : secTime(d.first);
  return T('wakes.trail_dir', { ...p, when });
}

/** The trail unfolded: a hint, not proof — its folders, the rest counted, how it was looked for */
function trailBox(w) {
  const box = el('div', 'wm-wake-cause wm-wake-trail');
  const dirs = trailDirs(w);
  if (!dirs.length) {
    box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_nothing')));
    return box;
  }
  box.appendChild(el('div', 'wm-wake-cause-head', T('wakes.trail_head')));
  const lines = dirs.map((d) => trailLine(w, d));
  if (w.trail.more) lines.push(T('wakes.trail_more', { n: w.trail.more }));
  box.appendChild(el('pre', 'code wm-wake-files', lines.join('\n')));
  if (w.trail.how !== 'btrfs') box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_walk')));
  if (w.trail.cut) box.appendChild(el('p', 'role wm-wake-fact', T('wakes.trail_cut')));
  return box;
}

/** A label: its words and its own tip */
function sureChip(c) {
  return chip(T('wakes.sure.' + c.sure), c.sure === 'sure' || c.sure === 'wrote' ? 'ok' : c.sure === 'maybe' ? 'quiet' : '', T('wakes.sure.' + c.sure + '_title'));
}

/**
 * One wake: when, which disks, the likeliest cause; unfolded its files (its name stands in the head line already),
 * every other cause with its name and files, what was due then, how timed, Unraid's own look said apart
 */
function wakeRow(w, look) {
  const r = el('div', 'row nocheck unfolds wm-wake');
  const main = el('div', 'row-main');
  const name = el('div', 'row-name', `${fmt.date(w.time)} · ${w.disks.join(' + ')}`);
  name.title = T('details');
  const meta = el('div', 'row-meta');
  const first = w.who[0];
  if (first && trailLeads(w)) {
    // only a «maybe» caught (waiting, no file of it on the disk): what was written then says more — it leads, the maybe follows
    const v = el('span', 'wm-wake-trail-verdict', T('wakes.trail_verdict_maybe', { dir: trailName(w, trailDirs(w)[0]) }));
    v.title = T('wakes.trail_verdict_maybe_title');
    meta.append(v, chip(T('wakes.trail_also', { who: w.who.map(causeName).join(', ') }), 'quiet', T('wakes.trail_also_title')));
  } else if (first) {
    meta.appendChild(el('b', 'wm-wake-who', causeName(first)));
    meta.appendChild(sureChip(first));
    if (w.who.length > 1) meta.appendChild(chip(T('wakes.more', { n: w.who.length - 1 }), '', T('wakes.more_title')));
  } else if (trailDirs(w).length) {
    // nobody caught, but something was written on it just then: that folder, as a hint
    const v = el('span', 'wm-wake-trail-verdict', T('wakes.trail_verdict', { dir: trailName(w, trailDirs(w)[0]) }));
    v.title = T('wakes.trail_verdict_title');
    meta.appendChild(v);
  } else meta.appendChild(chip(T('wakes.cause.none'), 'quiet', T('wakes.cause.none_title')));
  if (w.cut) meta.appendChild(chip(T('wakes.cut'), 'warn', T('wakes.cut_title')));
  main.append(name, meta);
  r.appendChild(main);
  let box = null;
  const set = (open) => {
    if (!open && box) { box.remove(); box = null; unfoldedWakes.delete(w.id); r.classList.remove('open'); }
    if (open && !box) {
      box = el('div', 'row-detail wm-wake-detail');
      const lead = trailLeads(w);       // the trail stands in the head line: it comes first, every cause with its name
      if (lead) box.appendChild(trailBox(w));
      w.who.forEach((c, i) => {
        const one = el('div', 'wm-wake-cause');
        const head = el('div', 'wm-wake-cause-head');
        if (i > 0 || lead) head.append(el('b', '', causeName(c)), ' ', sureChip(c));
        if (c.kind !== 'program' && c.prog) head.append(i > 0 ? ' ' : '', el('span', 'role', T('wakes.prog', { name: c.prog })));
        if (head.childNodes.length) one.appendChild(head);
        if (c.files && c.files.length) one.appendChild(el('pre', 'code wm-wake-files', c.files.join('\n')));
        else if (c.cmd) one.appendChild(el('pre', 'code wm-wake-files', c.cmd));
        box.appendChild(one);
      });
      if (!w.who.length) box.appendChild(el('p', 'role', T('wakes.cause.none_title')));
      if (w.trail && !lead) box.appendChild(trailBox(w));
      const facts = [];
      if (w.unraid) facts.push(T('wakes.unraid'));
      if (w.cron && w.cron.length) facts.push(T('wakes.cron_due', { list: w.cron.map(cronName).join(' · ') }));
      if (w.started && w.started.length) facts.push(T('wakes.started', { list: w.started.join(', ') }));
      facts.push(w.how === 'caught' ? T(w.who.length ? 'wakes.how.caught' : 'wakes.how.asked') : w.how === 'syslog' ? T('wakes.how.syslog')
        : T('wakes.how.noticed', { from: fmt.date(w.from), to: fmt.date(w.time) }));
      if (w.smart && w.how === 'caught') facts.push(T('wakes.smart', { when: fmt.date(w.smart) }));
      if (!w.named) facts.push(T('wakes.naming'));
      for (const x of facts) box.appendChild(el('p', 'role wm-wake-fact', x));
      r.appendChild(box);
      unfoldedWakes.add(w.id);
      r.classList.add('open');
    }
  };
  r.onclick = (e) => {
    if (e.target.closest('button, a, input, .row-detail, [data-own]')) return;
    if (String(window.getSelection && window.getSelection()).length) return;      // selecting text
    Office.keepInPlace(r, () => set(!box));
  };
  shownWakes.push({ open: () => !!box, set });
  if (unfoldedWakes.has(w.id)) set(true);
  return r;
}

// ------------------------------------------------------------------ what he keeps an eye on
/**
 * One thing he keeps an eye on: its name, its sub lines, its chips (flags()). Its hay for the list's filter (wmHay, built
 * here once per drawing): the name, every sub line, the chips' words — what the row shows, nothing more.
 */
function item(name, parts, extra, label) {
  const r = el('div', 'row nocheck wm-item');
  r.wmHay = [name, ...parts.filter(Boolean), ...((extra && extra.wmWords) || [])];
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name' + (label ? ' text' : ''), name));
  const meta = el('div', 'row-meta');
  parts.filter(Boolean).forEach((x) => meta.appendChild(el('span', '', x)));
  if (extra) meta.appendChild(extra);
  if (meta.children.length) main.appendChild(meta);
  r.appendChild(main);
  return r;
}

/**
 * What the filter words leave of a group (pure: tests/watchfilter.js). Words in its head — title, summary and the
 * group's own extra words («Docker» for the two container groups) — keep the whole group as it is; else each item (a row
 * with a hay: item()) fits on its own words together with the head (the words may fall on both: «container immich»), a
 * row without one (a note, a link, a switch) goes. A group with nothing left is not shown. No words: everything.
 *   head: [title, summary, …], hays: per row its hay or null → {shown, whole, rows: [bool per row], n (items that fit), total (items)}
 */
function groupFit(words, head, hays) {
  const total = hays.filter(Boolean).length;
  if (!words || !words.length || Office.fits(words, head)) return { shown: true, whole: true, rows: hays.map(() => true), n: total, total };
  const rows = hays.map((h) => !!h && Office.fits(words, [...head, ...h]));
  const n = rows.filter(Boolean).length;
  return { shown: n > 0, whole: false, rows, n, total };
}
/** The whole list's count from its groups' fits: {n, total, shown (groups shown)} — the bar's «n of m fit» */
const fitTally = (fits) => fits.reduce((a, f) => ({ n: a.n + f.n, total: a.total + f.total, shown: a.shown + (f.shown ? 1 : 0) }), { n: 0, total: 0, shown: 0 });
/**
 * Is a group open? Without words as kept in this browser (opened: 'watchman.groups'); with words every group shown is
 * unfolded, unless folded by hand meanwhile (shut) — the kept state stays untouched, «Show all» brings it back
 */
const groupOpen = (key, words, opened, shut) => ((words && words.length) ? !shut.has(key) : !!opened[key]);

/**
 * A tinted title bar that folds its rows (closed unless opened, remembered in this browser). With filter words
 * (watchWords) only its fitting rows (groupFit(); more: words the group is found by besides its title and summary),
 * unfolded, its summary followed by «n of m fit»; box.wmFit says what was left — the section leaves out a group with nothing.
 */
function group(key, title, sum, rows, more) {
  const fit = groupFit(watchWords, [title, sum, ...(more || [])], rows.map((x) => x.wmHay || null));
  const words = watchWords.length > 0;
  const box = Office.place(`watch:${key}`, el('div', 'group' + (groupOpen(key, watchWords, openGroups, watchShut) ? '' : ' closed')));
  box.wmFit = fit;
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  const mid = el('div', 'group-mid');
  const tl = el('div', 'group-title');
  tl.appendChild(el('span', '', title));
  mid.append(tl, el('div', 'group-meta', words && !fit.whole ? `${sum} · ${Office.t('common.filter_hits', { n: fit.n, total: fit.total })}` : sum));
  head.append(el('span', 'group-arrow', '▼'), mid);
  const body = el('div', 'group-rows');
  const drawn = rows.filter((x, i) => fit.rows[i]);
  drawn.forEach((x) => body.appendChild(x));
  const set = (open) => {
    box.classList.toggle('closed', !open);
    if (words) {             // folded or unfolded while the words are typed: for as long as they are
      if (open) watchShut.delete(key); else watchShut.add(key);
      return;
    }
    if (open) openGroups[key] = true; else delete openGroups[key];
    Office.storeJson('watchman.groups', openGroups);
  };
  const toggle = () => Office.keepInPlace(head, () => set(box.classList.contains('closed')));
  head.onclick = (e) => { toggle(); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(); } };
  box.append(head, body);
  if (drawn.length && fit.shown) watchGroups.push({ open: () => !box.classList.contains('closed'), set });
  else if (!drawn.length) head.classList.add('wm-empty-group');
  return box;
}

/**
 * The list's bar (core.js Office.filterBar), built once and held across the renders like the book's: the words over
 * every group's title and summary and each item in it (groupFit()); «n of m fit» over all items and «Show all» in its note
 * line. «Unfold all» stays right of the section's head.
 */
function watchBarGet() {
  if (watchBar) return watchBar;
  watchBar = Office.filterBar({
    key: 'watchman.watch',
    label: T('watch.filter.label'),
    onChange: (f, what) => {
      if (what === 'words' || what === 'all') watchShut.clear();
      if (view) sw.render();
    },
  });
  return watchBar;
}

function watchSection() {
  const s = el('section', 'section');
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  s.appendChild(Office.sectionHead(T('watch.title'), T('watch.sub'), unfold, { place: 'watch.title' }));
  const box = el('div', 'box wm-watchlist');
  const w = state.watch;
  watchGroups = [];
  if (!w) {
    box.appendChild(el('p', 'empty', T('watch.none')));
    unfold.hidden = true;
    s.appendChild(box);
    return s;
  }
  const bar = watchBarGet();
  s.appendChild(bar.el);
  watchWords = bar.read().words;
  const gs = [];
  gs.push(group('ips', T('watch.ips'), T('watch.ips_sum', { n: w.ips.length }),
    w.ips.map((x) => item(x.ip, [(x.users || []).join(', '), (x.services || []).join(', '), T('watch.last_seen', { when: fmt.relative(x.last) })]))));
  if (w.fail_ips.length) {
    gs.push(group('fail_ips', T('watch.fail_ips'), T('watch.fail_ips_sum', { n: w.fail_ips.length }),
      w.fail_ips.map((x) => item(x.ip, [T('watch.since', { when: fmt.date(x.since) }), x.quiet ? T('watch.quiet', { n: x.quiet }) : '',
        T('watch.last_seen', { when: fmt.relative(x.last) })]))));
  }
  const c = w.containers;
  const cSum = c ? T('watch.containers_sum', { special: c.special.length, count: c.count, n: Number(c.count) || 0 }) : '';
  gs.push(group('containers', T('watch.containers'),
    !c ? T('watch.containers_wait') : dockerOff() ? T('watch.containers_last', { docker: Office.t('common.docker_off'), sum: cSum }) : cSum,
    c ? c.special.map((x) => item(x.name, [], flags(x.tokens))) : [], ['Docker']));
  gs.push(group('plugins', T('watch.plugins'), T('watch.plugins_sum', { n: w.plugins.length }),
    w.plugins.map((x) => item(x.name, [x.source || T('watch.no_source'), x.version || '']))));
  const f = w.flash || { extra: [], users: [], keys: [] };
  const flash = [
    item(T('watch.go'), [f.go ? T('watch.go_lines', { n: f.go.lines }) : T('watch.go_none')]),
    item(T('watch.extra'), f.extra.length ? f.extra.map((x) => `${x.file} · ${fmt.size(x.size)}`) : [T('watch.extra_none')]),
    item(T('watch.users'), [f.users.join(', ')], null, true),
  ];
  if (f.keys.length) f.keys.forEach((k) => flash.push(item(T('watch.keys'), [k.user, [k.type, k.comment].filter(Boolean).join(' '), k.fp], null, true)));
  else flash.push(item(T('watch.keys'), [T('watch.keys_none')], null, true));
  gs.push(group('flash', T('watch.flash'), T('watch.flash_sum', { extra: f.extra.length,
    users: T('watch.flash_users', { n: f.users.length }), keys: T('watch.flash_keys', { n: f.keys.length }) }), flash));
  const sh = w.shares;
  gs.push(group('shares', T('watch.shares'),
    !sh ? T('watch.shares_wait') : sh.open.length ? T('watch.shares_sum', { open: sh.open.length, count: sh.count }) : T('watch.shares_none'),
    sh ? sh.open.map((x) => item(x.share, [x.smb ? `SMB: ${level(x.smb)}` : '', x.nfs ? `NFS: ${level(x.nfs)}` : ''])) : []));
  gs.push(schedGroup(w.sched));
  gs.push(hostGroup(w.host));
  if (w.partner && (w.partner.pairs.length || w.partner.strays.length)) gs.push(partnerGroup(w.partner));
  gs.push(netGroup(state.net));
  gs.push(snapGroup(state.snaps));
  gs.push(...flowGroups(state.flow));
  const tally = fitTally(gs.map((g) => g.wmFit));
  gs.filter((g) => g.wmFit.shown).forEach((g) => box.appendChild(g));
  if (!tally.shown) box.appendChild(el('p', 'empty', T('watch.filter.none')));
  watchWords = [];
  const label = () => {
    unfold.textContent = watchGroups.some((x) => !x.open()) ? T('unfold_all') : T('fold_all');
    unfold.hidden = !watchGroups.length;
  };
  unfold.onclick = () => {
    const open = watchGroups.some((x) => !x.open());
    Office.keepInPlace(unfold, () => watchGroups.forEach((x) => x.set(open)));
    label();
  };
  box.addEventListener('click', () => setTimeout(label, 0));
  label();
  s.appendChild(box);
  bar.done(tally.n, tally.total);
  return s;
}

/** What starts on its own: root's own crontab line by line, the .cron files, User Scripts, at, the notification agents */
function schedGroup(s) {
  if (!s) return group('sched', T('watch.sched'), T('watch.sched_wait'), []);
  const rows = [];
  // root's own lines as they are; another user's or /etc/cron.d's other files carry their place in front ("cron.d/x: …")
  (s.crontab || []).forEach((x) => rows.push(item(x, [/^[\w.-]+(\/[^:]+)?: /.test(x) ? '' : T('watch.crontab')])));
  if (s.twice) rows.push(item(T('watch.crontab'), [T('watch.crontab_twice', { n: s.twice })], null, true));
  (s.files || []).forEach((x) => rows.push(item(x.file, ['.cron', T('watch.cron_lines', { n: x.lines })])));
  (s.scripts || []).forEach((x) => rows.push(item(x.name, [T('watch.script'), freq(x.cron)])));
  if (s.at) rows.push(item(T('watch.at'), [T('watch.at_sum', { n: s.at })], null, true));
  (s.agents || []).forEach((x) => rows.push(item(x, [T('watch.agent')])));
  const sum = T('watch.sched_sum', { lines: (s.crontab || []).length, files: (s.files || []).length,
    scripts: (s.scripts || []).length, agents: (s.agents || []).length });
  return group('sched', T('watch.sched'), sum, rows);
}

/** The server itself: the ports programs listen on, programs from odd places he knows, the ways in that are open */
function hostGroup(h) {
  if (!h) return group('host', T('watch.host'), T('watch.host_wait'), []);
  const rows = [];
  h.doors.forEach((d) => rows.push(item(doorWords(d), [T('watch.door')], null, true)));
  // Unraid's API keys: name and roles (never more of them)
  (h.api || []).forEach((k) => rows.push(item(k.name, [T('watch.api_key'), (k.roles || []).join(', ') || T('detail.roles_none'),
    k.full ? T('watch.api_full') : ''])));
  h.listen.forEach((l) => rows.push(item(l.port === null || l.port === undefined ? `${l.prog} (${T('detail.port_dynamic')})` : `${l.port} · ${l.prog}`,
    [T('watch.port'), (l.addr || []).map((a) => (a === '*' ? T('detail.addr_all') : a)).join(', ')])));
  h.procs.forEach((x) => rows.push(item(x.exe, [x.prog, x.where || T('where.host')])));
  return group('host', T('watch.host'), T('watch.host_sum', { ports: h.listen.length, doors: h.doors.length, keys: (h.api || []).length,
    procs: h.procs.length, users: h.users }), rows);
}

/** The partner offices' doors: per pair whether its line is there as the office wrote it; lines of no pair */
function partnerGroup(x) {
  const rows = [];
  x.pairs.forEach((p) => rows.push(item(p.name, [
    p.address,
    !p.door ? T('watch.partner_no_door') : T('watch.partner_line_' + (['ok', 'changed', 'missing'].includes(p.line) ? p.line : 'missing')),
    [p.receives ? T('watch.partner_receives') : '', p.sends ? T('watch.partner_sends') : ''].filter(Boolean).join(' · '),
  ])));
  x.strays.forEach((id) => rows.push(item(`uso-partner:${id}`, [T('watch.partner_stray')], null, true)));
  const sum = T('watch.partner_sum', { n: x.pairs.length }) + (x.transfers ? ' · ' + T('watch.partner_transfers', { n: x.transfers }) : '');
  return group('partner', T('watch.partner'), sum, rows);
}

/**
 * The network: Unraid's syslog server and its senders (the router's name and version from its lines, the last line's
 * age, lines a day, lines not understood), devices known, detections this week (other devices only counted), what the
 * router blocked from the server, what isn't in the book; the «Whole LAN» switch and its counts. Never another
 * device's address (the agent sends none here).
 */
function netGroup(n) {
  if (!n) return group('net', T('watch.net'), T('watch.net_wait'), []);
  const rows = [];
  const toAdvisor = () => {
    const a = el('a', '', T('watch.net_to_advisor'));
    a.href = '#/advisor';
    return a;
  };
  const say = (text, link) => {
    const p = note(text);
    if (link) p.append(' ', toAdvisor());
    rows.push(p);
  };
  const stateText = { off: 'watch.net_off', asleep: 'watch.net_asleep', array: 'watch.net_array', unknown: 'watch.net_unknown', unreadable: 'watch.net_unreadable' };
  if (stateText[n.state]) say(T(stateText[n.state], { folder: (n.cfg && n.cfg.folder) || '' }), n.state === 'off');
  if (n.cfg && n.cfg.folder && n.state !== 'off') rows.push(note(T('watch.net_folder', { folder: n.cfg.folder })));
  const senders = n.senders || [];
  if (n.state === 'on' && !senders.length) {
    const c = el('p', 'callout wm-net-listening', T('watch.net_listening'));
    c.append(' ', toAdvisor());
    rows.push(c);
  }
  senders.forEach((x) => rows.push(item(x.host ? `${x.host} · ${x.sender}` : x.sender, [
    x.product ? `${x.product} ${x.version || (x.ros ? T('watch.net_version_unknown') : '')}`.trim() : '',
    x.last ? T('watch.net_last', { when: fmt.relative(x.last) }) : '',
    T('watch.net_per_day', { n: x.per_day || 0 }),
    x.other_pct > 0 ? T('watch.net_other', { pct: fmt.number(x.other_pct) }) : '',
    x.prefix_other ? T('watch.net_prefix_other', { n: x.prefix_other }) : '',
  ])));
  // a MikroTik sending without RouterOS's topics (the default format, or the syslog format without add-topics-string)
  senders.filter((x) => x.ros && x.ros_format).forEach((x) => say(T('watch.net_ros_format', { router: x.host || x.sender }), true));
  if (senders.length) {
    rows.push(item(T('watch.net_counts', { devices: n.devices, admins: n.admins, vpn: n.vpn }), [], null, true));
    const d = n.detections || {};
    rows.push(item(senders.some((x) => x.detections) ? T('watch.net_detections', { in: d.in || 0, out: d.out || 0, other: d.other || 0 })
      : T('watch.net_no_detections'), [], null, true));
    const b = n.blocked || { week: 0, top: [] };
    if (senders.some((x) => x.firewall) || b.week) {
      rows.push(item(T('watch.net_blocked', { n: b.week || 0 }), (b.top || []).map((k) => T('watch.net_blocked_key', { key: k.key, n: k.n })), null, true));
    } else {
      rows.push(item(T('watch.net_no_firewall'), [], null, true));
    }
    const dr = n.dropped || {};
    if (dr.update || dr.wan) rows.push(item(T('watch.net_dropped', { update: T('count.updates', { n: dr.update || 0 }), wan: T('count.wan_changes', { n: dr.wan || 0 }) }), [], null, true));
  }
  if ((n.own || []).length) rows.push(note(T('watch.net_own', { list: n.own.join(', ') })));
  if (n.skipped) rows.push(note(T('watch.net_skipped', { size: fmt.size(n.skipped) })));
  if (n.more) rows.push(note(T('watch.net_more', { n: n.more })));
  if (senders.length) rows.push(lanSwitch());
  (n.lan || []).forEach((x) => rows.push(item(x.name || x.mac, [T('watch.net_lan_row', { c: T('count.connections', { n: Number(x.connects) || 0 }), d: T('count.detections', { n: Number(x.detections) || 0 }) })])));
  if (state.net_lan && !(n.lan || []).length) rows.push(note(T('watch.net_lan_none')));
  const det = n.detections || {};
  // the head says the state while the files aren't read (asleep …), never the last counts as now (W9)
  const sum = senders.length && !stateText[n.state] ? T('watch.net_sum', { senders: senders.length, devices: n.devices, det: (det.in || 0) + (det.out || 0) })
    : T(stateText[n.state] || 'watch.net_listening', { folder: (n.cfg && n.cfg.folder) || '' });
  return group('net', T('watch.net'), sum, rows);
}

/** The «Whole LAN» switch: per device on the LAN how often it connected and how many detections named it — counts, off by default */
function lanSwitch() {
  const label = el('label', 'switch wm-net-lan');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !!state.net_lan;
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('watch.net_lan_switch')));
  label.title = T('watch.net_lan_title');
  label.dataset.own = '1';
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.net_lan_set`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    if (view) Office.keepInPlace(label, render);
    Office.toast(T(on ? 'watch.net_lan_on' : 'watch.net_lan_off'));
  };
  const row = el('div', 'row nocheck wm-item');
  row.appendChild(label);
  return row;
}

/** The snapshots he follows: per pool and disk how many, which sleep (compared once awake), the series you taught him */
function snapGroup(s) {
  if (!s) return group('snaps', T('watch.snaps'), T('watch.snaps_wait'), []);
  const rows = [];
  let total = 0;
  Object.entries(s.zfs || {}).forEach(([pool, n]) => { total += n; rows.push(item(pool, ['ZFS', T('watch.snaps_n', { n })])); });
  Object.entries(s.btrfs || {}).forEach(([disk, n]) => { total += n; rows.push(item(disk, ['btrfs', T('watch.snaps_n', { n })])); });
  (s.asleep || []).forEach((x) => rows.push(item(x, [T('watch.snaps_asleep')])));
  (s.capped || []).forEach((x) => rows.push(item(x, [T('watch.snaps_capped')])));
  (s.series || []).forEach((x) => rows.push(item(x, [T('watch.snaps_learned')])));
  const office = Object.values(s.expected || {}).reduce((a, b) => a + (Number(b) || 0), 0);
  if (office) rows.push(item(T('watch.snaps_office'), [T('watch.snaps_office_n', { n: office })], null, true));
  if (s.zfs === null && s.btrfs === null) rows.push(note(T('watch.snaps_none')));
  // zfs didn't answer on the last round: nothing was compared — never «0 snapshots · pools and disks: 0» (truth review W5)
  if (s.failed) rows.unshift(note(T('watch.snaps_failed')));
  const sum = T('watch.snaps_sum', { n: total, places: Object.keys(s.zfs || {}).length + Object.keys(s.btrfs || {}).length });
  return group('snaps', T('watch.snaps'), s.failed && !total ? T('watch.snaps_failed_sum') : sum, rows);
}

// ------------------------------------------------------------------ data flow
/** "learning: 2 of 7 days" on a row — only when it is behind the rest (it came later); nothing once learned */
const learningText = (days, f) => (days === null || days === undefined || (days === f.days && f.days < f.learn) ? ''
  : T('watch.flow_learning', { days, learn: f.learn || 7 }));
/** A quiet line in a group: what can't be looked at here, and why */
const note = (text) => el('p', 'wm-note wm-flow-note', text);
const total = (list, k) => (list || []).reduce((a, x) => a + (Number(x[k]) || 0), 0);
const serviceName = (s) => ({ smb: 'SMB', nfs: 'NFS', ssh: 'SSH', web: 'WebGUI' }[s] || s);
/** A login's service as the agent names it (watchmanServiceName()): WebGUI, SSH (publickey) */
const loginService = (s) => (s === 'web' ? 'WebGUI' : String(s || '').startsWith('ssh:') ? `SSH (${String(s).slice(4)})` : 'SSH');

/** "usually at most 1 GB per hour now", or what «I know, thanks» made normal */
function usualRow(x) {
  if (x.learning !== null && x.learning !== undefined) return '';
  const n = Math.max(x.usual || 0, x.ack || 0);
  return n > 0 ? T('watch.flow_usual', { size: fmt.size(n) }) : T('watch.flow_usual_none');
}

/**
 * The data flow's history in Grafana: the office's dashboard at this group's panel — only where the
 * consultant found Grafana with the office's dashboard (agent: watchmanGrafana()). A link, never embedded.
 */
function grafanaRow(key) {
  const href = state.grafana && Office.safeHref(state.grafana[key]);
  if (!href) return null;
  const p = el('p', 'wm-note wm-flow-note');
  const a = el('a', '', T('watch.grafana'));
  a.href = href;
  a.target = '_blank';
  a.rel = 'noopener noreferrer';
  a.title = T('watch.grafana_title');
  p.appendChild(a);
  return p;
}

/** «What I keep an eye on» of the data flow: who pulls, containers, written into shares, SMB's users and machines */
function flowGroups(f) {
  if (!f) return [group('flow_clients', T('watch.flow_clients'), T('watch.flow_wait'), [])];
  const can = f.can || {};
  const overall = f.days < f.learn ? T('watch.flow_learning', { days: f.days, learn: f.learn }) : T('watch.flow_learned');
  const out = [];

  // who pulls: per client and service
  const clients = [];
  if (can.ss === false) clients.push(note(T('watch.flow_no_ss')));
  (f.clients || []).forEach((x) => clients.push(item(x.name ? `${x.ip} · ${x.name}` : x.ip,
    [serviceName(x.service), T('watch.flow_day', { size: fmt.size(x.day) }), usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : '', T('watch.last_seen', { when: fmt.relative(x.last) })])));
  const gc = grafanaRow('flow_clients');
  if (gc) clients.push(gc);
  out.push(group('flow_clients', T('watch.flow_clients'),
    T('watch.flow_clients_sum', { learning: overall, n: (f.clients || []).length, size: fmt.size(total(f.clients, 'day')) })
      + (can.office ? ' · ' + T('watch.flow_office_now') : ''), clients));

  // containers: the top senders
  const cts = [];
  if (can.docker === false) cts.push(note(T('watch.flow_no_docker')));
  (f.containers || []).forEach((x) => cts.push(item(x.name, [T('watch.flow_day', { size: fmt.size(x.day) }),
    x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '',
    x.media ? T('watch.flow_media') : x.kopia ? T('watch.flow_kopia') : usualRow(x), learningText(x.learning, f),
    x.with && x.with.length ? T('watch.flow_with', { names: x.with.join(', ') }) : ''])));
  if (can.host && can.host.length) cts.push(item(can.host.join(', '), [T('watch.flow_host')]));
  if (f.idle) cts.push(note(T('watch.flow_idle', { n: f.idle })));
  out.push(group('flow_containers', T('watch.flow_containers'),
    can.docker === false ? T('watch.flow_no_docker') : T('watch.flow_containers_sum', { size: fmt.size(total(f.containers, 'day')) }), cts, ['Docker']));

  // written into ZFS shares
  const zfs = can.zfs;
  const shares = [];
  (f.shares || []).forEach((x) => shares.push(item(x.share, [T('watch.flow_day', { size: fmt.size(x.day) }),
    x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '',
    x.snapshots ? (x.snap ? T('watch.flow_written_now', { when: fmt.relative(x.snap), size: fmt.size(x.written) }) : '') : T('watch.flow_nosnap'),
    usualRow(x), learningText(x.learning, f)])));
  (f.disks || []).forEach((x) => shares.push(item(`${x.disk} · ${(x.shares || []).join(', ') || '–'}`,
    [T('watch.flow_gone_disk', { fs: x.fs }), T('watch.flow_day', { size: fmt.size(x.day) }),
      x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '', usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : ''])));
  if (zfs && zfs.asleep && zfs.asleep.length) shares.push(item(zfs.asleep.join(', '), [T('watch.flow_asleep')]));
  const fsDisks = Object.keys(can.fs || {});
  const noShares = (f.disks || []).length || fsDisks.length ? ''
    : !zfs ? T('watch.flow_no_zfs') : !(f.shares || []).length && !(zfs.asleep || []).length ? T('watch.flow_no_zfs_shares') : '';
  if (noShares) shares.push(note(noShares));
  const gs = !noShares && grafanaRow('flow_shares');
  if (gs) shares.push(gs);
  out.push(group('flow_shares', T('watch.flow_shares'), noShares || ((f.disks || []).length
    ? T('watch.flow_written_sum', { shares: T('count.zfs_shares', { n: (f.shares || []).length }), disks: T('count.disks', { n: f.disks.length }),
      size: fmt.size(total(f.shares, 'day') + total(f.disks, 'day')) })
    : T('watch.flow_shares_sum', { n: (f.shares || []).length, size: fmt.size(total(f.shares, 'day')) })), shares));

  // gone from shares: ZFS per share, XFS/btrfs per disk
  const gone = [];
  if (can.moving && can.moving.length) gone.push(note(T('watch.flow_moving', { what: movingText(can.moving) })));
  (f.gone || []).forEach((x) => gone.push(item(x.disk ? `${x.disk} · ${(x.shares || []).join(', ') || '–'}` : x.share,
    [x.disk ? T('watch.flow_gone_disk', { fs: x.fs }) : '', x.day || x.expected || !x.files ? T('watch.flow_day', { size: fmt.size(x.day) }) : '',
      x.files ? T('watch.flow_files_day', { n: x.files }) : '',
      x.files_expected ? T('watch.flow_files_expected', { n: x.files_expected }) : '',
      x.expected ? T('watch.flow_gone_expected', { size: fmt.size(x.expected) }) : '', usualRow(x), learningText(x.learning, f),
      x.ack ? T('watch.flow_noted', { size: fmt.size(x.ack) }) : '', (x.asleep || []).length ? T('watch.flow_gone_asleep', { pools: x.asleep.join(', ') }) : ''])));
  const disks = can.disks;
  if (disks && disks.asleep && disks.asleep.length) gone.push(item(disks.asleep.join(', '), [T('watch.flow_asleep')]));
  const btrfs = fsDisks.filter((d) => can.fs[d] === 'btrfs');
  if (btrfs.length) gone.push(note(T('watch.flow_btrfs_files', { disks: btrfs.join(', ') })));
  const noGone = !(f.gone || []).length && !zfs && !(disks && disks.disks && disks.disks.length) ? T('watch.flow_gone_none') : '';
  if (noGone) gone.push(note(noGone));
  out.push(group('flow_gone', T('watch.flow_gone'),
    noGone || T('watch.flow_gone_sum', { n: (f.gone || []).length, size: fmt.size(total(f.gone, 'day')) }), gone));

  // SMB: users and machines
  const smb = f.smb || { users: [], clients: [] };
  const rows = [];
  const smbState = can.smb === 'off' ? T('watch.flow_smb_off') : can.smb === null ? T('watch.flow_smb_none') : '';
  if (smbState) rows.push(note(smbState));
  if (smb.users.length) rows.push(item(T('watch.flow_smb_users_row'), [smb.users.join(', ')], null, true));
  smb.clients.forEach((x) => rows.push(item(x.name ? `${x.ip} · ${x.name}` : x.ip,
    [T('watch.flow_smb_hours', { n: x.hours }), learningText(x.learning, f), T('watch.last_seen', { when: fmt.relative(x.last) })])));
  out.push(group('flow_smb', T('watch.flow_smb'), smbState || T('watch.flow_smb_sum', {
    users: T('watch.flow_smb_users', { n: smb.users.length }), machines: T('watch.flow_smb_machines', { n: smb.clients.length }) }), rows));
  return out;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  /** sub: a tile (#/watchman/book, …/open — only what's not noted yet —, …/posture, …/watch); «entry/<id>» (the search's):
      that entry of the whole book among the rows shown */
  async mount(root, sub) {
    view = root;
    page = Office.pageFrom(PAGES(), sub) === 'setup' ? 'setup' : '';
    if (page === 'setup') {
      // #/watchman/setup/<area> picks that area like a click; else «Everything changed»
      const picked = setupSw.mount(String(sub || '').split('/').slice(1).join('/'));
      if (picked) Office.subroute('setup');
      render();
      await load(false);
      return;
    }
    const m = /^entry\/([\w-]{1,64})$/.exec(sub || '');
    if (m) wanted = m[1];
    bookBarGet().reset();          // a new visit: the whole book (the area is a choice and stays)
    watchBarGet().reset();         // … and the whole of what he keeps an eye on, folded as kept
    watchShut.clear();
    wakesBarGet().reset();         // … and every wake, of every disk
    wakeDisk = '';
    Object.values(catBars).forEach((b) => b.reset().set({ pick: '' }));      // … and every area's list whole
    if (sub === 'open') bookBarGet().set({ only: true });     // the tile «Not noted yet» up to 1.95: the book, only open
    sw.mount(m || sub === 'open' ? 'book' : sub);    // a tile's sub-route picks it like a click (the address back to #/watchman)
    render();
    await load(false);
    holdAuto();
  },
  unmount() { clearTimeout(timer); view = null; bookRows = []; watchGroups = []; shownWakes = []; wanted = null; },
  poll() { if (!running()) load(false); },
  started() { if (hired() && !state) load(false); },      // his picture shows the mark on every page
  greetOk: (key) => greetOk(key),
  agentChanged() { if (view) render(); },
  menu() {
    return [
      { text: T('round_now'), act: roundNow, disabled: !Office.agent.running || running() || !hired() },
      { text: T('ack_all'), act: ackAll, disabled: !Office.agent.running || !openCount() },
    ];
  },
  async reception() {
    if (!state) await load(false);
    if (!state) return { bubble: T('bubble.loading'), facts: [] };
    const facts = [];
    facts.push(state.round && state.round.last ? T('fact.last', { when: fmt.relative(state.round.last) }) : T('fact.first'));
    const n = openCount();
    if (state.on_watch) facts.push(n ? T('fact.open', { n }) : T('fact.quiet'));
    if (postureOpen()) facts.push(T('fact.posture', { n: postureOpen() }));
    if (louderCount()) facts.push(T('tile.posture_incidents', { n: louderCount() }));
    if (state.watch) facts.push(T('fact.known', { ips: T('count.known_addresses', { n: state.watch.ips.length }), plugins: T('count.plugins', { n: state.watch.plugins.length }) }));
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys)
Office.places(ID, [
  { kind: 'section', key: 'round.title' },
  { kind: 'section', key: 'posture.title', route: `#/${ID}/posture` },
  { kind: 'section', key: 'book.title', route: `#/${ID}/book` },
  { kind: 'section', key: 'watch.title', route: `#/${ID}/watch` },
  { kind: 'section', key: 'incidents.title', route: `#/${ID}/incidents` },
  { kind: 'section', key: 'hold.title', route: `#/${ID}/hold` },
  { kind: 'section', key: 'wakes.title', route: `#/${ID}/wakes` },
  { kind: 'section', key: 'setup.changed', route: `#/${ID}/setup/changed`, crumb: 'page.setup' },           // his setup (1.96 step 5)
  { kind: 'help', key: 'help.setup', text: 'help.setup_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  { kind: 'help', key: 'help.setup_off', text: 'help.setup_off_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  { kind: 'help', key: 'help.setup_rules', text: 'help.setup_rules_text', route: `#/${ID}/setup`, crumb: 'page.setup' },
  // the same section by the words people search it with (disk wake-ups, spin-up), in every language — its page alone
  { kind: 'section', key: 'search.wakes', route: `#/${ID}/wakes`, anchor: 'search.wakes', text: 'help.wakes_text' },
  ...sw.places(),             // his tiles: the book, «Not noted yet», how secure, what he keeps an eye on
  { kind: 'help', key: 'ack', text: 'help.ack_text' },
  { kind: 'help', key: 'round_now', text: 'help.round_text' },
  ...['posture', 'book', 'serious', 'normal', 'logins', 'containers', 'plugins', 'office', 'flash', 'shares', 'sched', 'flow', 'flow_gone', 'flow_not',
    'snaps', 'host', 'host_not', 'net', 'net_not', 'attack', 'siem', 'chain', 'night', 'parity', 'hold', 'wakes', 'grafana', 'notify', 'safe', 'lights']
    .map((x) => ({ kind: 'help', key: `help.${x}`, text: `help.${x}_text` })),
]);

// what his state holds for the search (core.js «items»): the entries of his book not noted yet (in his words, never a
// router's or a log's line), his posture tips not known yet (their rows carry the tip's id: data-id), what he keeps an
// eye on — each group, found by its title and the names in it (plugins, containers, shares, User Scripts); each through
// its tile's sub-route, so its section is drawn
const ENTRIES_MAX = 120;
Office.placesFrom(ID, (s) => {
  const out = [];
  (s.book || []).filter((e) => e && e.open).slice(0, ENTRIES_MAX).forEach((e) => out.push({
    text: entryName(e), sub: e.kind === 'watch' ? T('state.watch') : `${T('group.' + e.group)} · ${T('state.open')}`,
    route: `#/${ID}/entry/${e.id}`, anchor: `entry:${e.id}`,
  }));
  ((s.posture && s.posture.tips) || []).filter((x) => x && !x.known).forEach((x) => out.push({
    text: T(`posture.${x.id}.title`, postureParams(x)), sub: `${T('posture.title')} · ${T('posture.level_' + x.level)}`, route: `#/${ID}/posture`, anchor: x.id,
  }));
  (s.incidents || []).filter((x) => x && typeof x.id === 'string').forEach((x) => out.push({
    text: entryName(x), sub: `${T('incidents.title')} · ${T('group.' + x.group)}`, route: `#/${ID}/incidents`, anchor: `incident:${x.id}`,
    words: typeof x.note === 'string' ? x.note : '',
  }));
  const w = s.watch;
  if (!w) return out;
  const names = (list, k) => (list || []).map((x) => x && x[k]).filter((x) => typeof x === 'string').join(' ');
  const groups = [['ips', ''], ...((w.fail_ips || []).length ? [['fail_ips', '']] : []), ['containers', names(w.containers && w.containers.special, 'name')],
    ['plugins', names(w.plugins, 'name')], ['flash', ''], ['shares', names(w.shares && w.shares.open, 'share')], ['sched', names(w.sched && w.sched.scripts, 'name')],
    ['host', ''], ...(w.partner && ((w.partner.pairs || []).length || (w.partner.strays || []).length) ? [['partner', '']] : []), ['net', ''], ['snaps', ''],
    ...(s.flow ? ['flow_clients', 'flow_containers', 'flow_shares', 'flow_gone', 'flow_smb'] : ['flow_clients']).map((k) => [k, ''])];
  groups.forEach(([key, words]) => out.push({ text: T('watch.' + key), sub: T('watch.title'), route: `#/${ID}/watch`, anchor: `watch:${key}`, words }));
  return out;
});

if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.watchman = { setState: (s) => { state = s; }, greetOk, bookView, entryArea, foldKind, plainWords, entryHay, foldName,
    groupFit, fitTally, groupOpen, watchSection, srcFrom, srcNone, wakesTile, wakesView, causeName };
}
})();
