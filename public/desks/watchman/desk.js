/* The Night Watchman — keeps the watch book and tells only what is different
   from normal. His page: the last round (when, the next one, what is open),
   the watch book (newest first; a row unfolds to its details; «I know,
   thanks» per entry and «Note all») and what he
   keeps an eye on (what is normal, summarised; also what starts on its own:
   crontabs, the plugins' .cron files, User Scripts, at, notification agents;
   the data flow: who pulls how much, containers, what is written into ZFS
   shares, SMB's users and machines). His rounds run on the server
   every five minutes, read only: agent/desks/watchman.php. Everything from his
   state goes into the page as text, never as HTML. */
(() => {
'use strict';

const ID = 'watchman';
const T = Office.scope(ID);
const { el, fmt } = Office;
const POLL_MS = 2000;
const PAGE = 30;                // rows of the book shown at first (and per «Show more»)

let state = null;
let view = null;
let timer = null;
let before = null;              // entry ids before a round this page asked for: say how many came
let shown = PAGE;
let onlyOpen = Office.store('watchman.only_open') === '1';
const openGroups = Office.storeJson('watchman.groups') || {};     // groups of «What I keep an eye on» that are open
const unfolded = new Set();     // entries unfolded on this visit
let bookRows = [];              // the book's unfoldable rows: {open(), set(open)}
let bookLabel = null;           // brings «Unfold all» up to date
let watchGroups = [];           // {open(), set(open)}

const openCount = () => Object.values((state && state.open) || {}).reduce((a, b) => a + (Number(b) || 0), 0);
const running = () => !!(state && state.round && state.round.running);
const hired = () => !!(Office.desks.get(ID) || {}).hired;

// ------------------------------------------------------------------ loading
async function load(fresh) {
  let j = { ok: false };
  try { j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) }); } catch (e) { /* keep what we have */ }
  if (j.ok && j.state) state = j.state;
  mood();
  if (view) Office.keepInPlace(null, render);
  follow();
  return j;
}

/** His picture: the lantern — with a mark while something in the book is not noted */
function mood() {
  if (state) Office.setDeskMood(ID, openCount() ? 'alert' : '');
}

/** While a round runs (and his page is shown), look again every two seconds; say when it is done */
function follow() {
  clearTimeout(timer);
  if (!view) return;
  if (running()) {
    timer = setTimeout(() => load(true), POLL_MS);
    return;
  }
  if (before) {
    const n = (state.book || []).filter((e) => !before.has(e.id)).length;
    before = null;
    if (state.round && state.round.failed) Office.toast(T('round_failed'), true);
    else Office.toast(n ? T('round_done_new', { n }) : T('round_done'));
  }
}

async function roundNow() {
  if (running() || !Office.agent.running || !hired()) return;
  const ids = new Set(((state && state.book) || []).map((e) => e.id));
  const j = await Office.api.post(`${ID}.round`, {});
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return; }
  before = ids;
  if (j.state) state = j.state;
  if (view) Office.keepInPlace(null, render);
  follow();
}

// ------------------------------------------------------------------ «I know, thanks»
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

function ackAll() {
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
  if (!view) return;
  const find = () => (id && view.querySelector(`.wm-entry[data-id="${CSS.escape(id)}"]`)) || view.querySelector('.wm-book');
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
function bubbleText() {
  if (!state) return T('bubble.loading');
  if (running()) return T('bubble.touring');
  if (!state.on_watch) return T('bubble.first');
  const n = openCount();
  if (n) return T('bubble.open', { n });
  if (state.round && state.round.failed) return T('bubble.failed');
  return T('bubble.quiet', { when: fmt.relative(state.round && state.round.last) });
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
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: Office.withGreeting(ID, bubbleText()), actions: [btn] });
  root.appendChild(head);
  const lim = (state && state.limits) || {};
  root.appendChild(Office.pageHelp(ID, [
    [T('help.book'), T('help.book_text', { keep: lim.keep || 500, days: lim.days || 90 })],
    [T('ack'), T('help.ack_text')],
    [T('help.normal'), T('help.normal_text')],
    [T('round_now'), T('help.round_text')],
    [T('help.logins'), T('help.logins_text', { burst: lim.burst || 5, minutes: Math.round((lim.window || 600) / 60) })],
    [T('help.containers'), T('help.containers_text')],
    [T('help.plugins'), T('help.plugins_text')],
    [T('help.flash'), T('help.flash_text')],
    [T('help.shares'), T('help.shares_text')],
    [T('help.sched'), T('help.sched_text')],
    [T('help.flow'), T('help.flow_text', flowLimits())],
    [T('help.flow_not'), T('help.flow_not_text')],
    [T('help.notify'), T('help.notify_text')],
    [T('help.safe'), T('help.safe_text')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  root.append(roundSection(), bookSection(), watchSection());
}

// ------------------------------------------------------------------ the last round
function stat(label, value, sub, cls, tip) {
  const b = el('div', 'stat' + (cls ? ' ' + cls : ''));
  const v = el('div', 'stat-value', value);
  if (tip) v.title = tip;
  b.append(el('div', 'stat-label', label), v);
  if (sub) b.appendChild(el('div', 'stat-sub', sub));
  return b;
}

function roundSection() {
  const s = el('section', 'section');
  const r = state.round || {};
  s.appendChild(Office.sectionHead(T('round.title'), T('round.sub'), running() ? el('span', 'hint', T('stat.running')) : null, notifySwitch()));
  const stats = el('div', 'stats');
  stats.appendChild(stat(T('stat.last'), r.last ? fmt.relative(r.last) : T('stat.never'),
    r.last ? fmt.date(r.last) + (r.duration_ms ? ' · ' + T('stat.took', { ms: r.duration_ms }) : '') : ''));
  stats.appendChild(stat(T('stat.next'), running() ? T('stat.running') : r.next ? fmt.relative(r.next) : '–',
    !running() && r.next ? fmt.date(r.next) : ''));
  const n = openCount();
  stats.appendChild(stat(T('stat.open'), fmt.number(n),
    n ? T('stat.open_kinds', { n: Object.keys(state.open || {}).length }) : T('stat.open_none'), n ? 'alert' : ''));
  stats.appendChild(stat(T('stat.since'), state.on_watch ? fmt.relative(state.on_watch) : T('stat.never'),
    state.on_watch ? fmt.date(state.on_watch) : ''));
  s.appendChild(stats);
  const notes = el('div', 'wm-notes');
  if (!state.on_watch) notes.appendChild(el('p', 'callout', T('round.first')));
  if (r.failed) notes.appendChild(el('p', 'callout warn', T('round.failed')));
  if (r.skipped) notes.appendChild(el('p', 'role', T('round.skipped', { size: fmt.size(r.skipped) })));
  if (state.on_watch && r.docker === false) notes.appendChild(el('p', 'role', T('round.no_docker')));
  if (state.on_watch && r.shares === false) notes.appendChild(el('p', 'role', T('round.no_shares')));
  const told = state.notified;
  if (told && told.time) notes.appendChild(el('p', 'role', T('round.notified', { when: fmt.relative(told.time), n: (told.items || []).length })));
  if (state.notify && state.notify.available === false) notes.appendChild(el('p', 'role', T('notify_missing')));
  if (notes.children.length) s.appendChild(notes);
  return s;
}

/** Reports to Unraid's notifications: on (default) or off — like the team lead's switch */
function notifySwitch() {
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !state.notify || state.notify.on !== false;
  cb.disabled = !Office.agent.running || !hired();
  label.append(cb, el('span', '', T('notify_switch')));
  label.title = T('help.notify_text');
  cb.onchange = async () => {
    cb.disabled = true;
    const on = cb.checked;
    const j = await Office.api.post(`${ID}.notify_set`, { on });
    if (!j.ok) {
      cb.checked = !on;
      cb.disabled = false;
      Office.toast(Office.errorText(j.error, ID), true);
      return;
    }
    if (j.state) state = j.state;
    if (view) Office.keepInPlace(null, render);
    Office.toast(T(on ? 'notify_on' : 'notify_off'));
  };
  return label;
}

// ------------------------------------------------------------------ the watch book
function bookSection() {
  const s = el('section', 'section');
  const sw = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = onlyOpen;
  sw.append(cb, el('span', '', T('book.only_open')));
  const extras = [sw];
  if (openCount()) {
    const all = el('button', 'btn small', T('ack_all'));
    all.type = 'button';
    all.title = T('ack_all_title');
    all.disabled = !Office.agent.running;
    all.onclick = ackAll;
    extras.push(all);
  }
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  extras.push(unfold);
  s.appendChild(Office.sectionHead(T('book.title'), T('book.sub'), ...extras));

  const box = el('div', 'box wm-book');
  bookLabel = () => {
    unfold.textContent = bookRows.some((x) => !x.open()) ? T('unfold_all') : T('fold_all');
    unfold.hidden = bookRows.length < 2;
  };
  unfold.onclick = () => {
    const open = bookRows.some((x) => !x.open());
    Office.keepInPlace(unfold, () => bookRows.forEach((x) => x.set(open)));
    bookLabel();
  };
  const fill = () => {
    box.innerHTML = '';
    bookRows = [];
    const list = (state.book || []).filter((e) => !onlyOpen || e.open);
    list.slice(0, shown).forEach((e) => box.appendChild(entryRow(e)));
    if (!list.length) box.appendChild(el('p', 'empty', onlyOpen ? T('book.empty_open') : T('book.empty')));
    if (list.length > shown) {
      const more = el('button', 'btn small plain wm-more', T('book.more', { n: Math.min(PAGE, list.length - shown) }));
      more.type = 'button';
      more.onclick = () => { shown += PAGE; Office.keepInPlace(null, fill); };
      box.appendChild(more);
    }
    bookLabel();
  };
  cb.onchange = () => {
    onlyOpen = cb.checked;
    Office.store('watchman.only_open', onlyOpen ? '1' : null);
    shown = PAGE;
    Office.keepInPlace(sw, fill);
  };
  fill();
  s.appendChild(box);
  return s;
}

/** One entry: what, when, noted or not; a click unfolds its details */
function entryRow(e) {
  const watch = e.kind === 'watch';
  const r = el('div', 'row nocheck wm-entry' + (watch ? ' wm-watch' : ' unfolds') + (e.open ? ' wm-open' : ''));
  r.dataset.id = e.id;
  const main = el('div', 'row-main');
  const name = el('div', 'row-name text', T('entry.' + e.kind, entryParams(e)));
  const meta = el('div', 'row-meta');
  if (watch) {
    meta.appendChild(chip(T('state.watch'), 'accent', T('state.watch_title')));
  } else {
    name.title = T('details');
    meta.appendChild(e.open ? chip(T('state.open'), 'warn', T('state.open_title')) : chip(T('state.noted'), 'quiet', T('state.noted_title')));
    meta.appendChild(chip(T('group.' + e.group), '', T('group_title.' + e.group)));
  }
  const when = el('span', '', fmt.date(e.last));
  when.dataset.tip = fmt.relative(e.last);
  meta.appendChild(when);
  if (e.count > 1 && e.kind !== 'login_failures') meta.appendChild(el('span', '', T('times', { n: e.count })));
  main.append(name, meta);
  r.appendChild(main);
  if (e.open) {
    const b = el('button', 'btn small plain', T('ack'));
    b.type = 'button';
    b.title = T('ack_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ack(e, b);
    r.appendChild(b);
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
  bookRows.push({ open: () => !!box, set });
  if (unfolded.has(e.id)) set(true);
  return r;
}

/** An entry's words; the data flow's sizes, what is normal and the hours in this browser's language */
function entryParams(e) {
  const t = { ...(e.t || {}), n: e.count };
  const p = e.p || {};
  if (e.group !== 'flow') return t;
  if (p.bytes !== undefined) t.size = fmt.size(p.bytes);
  if (e.kind.startsWith('flow_')) t.usual = usualText(e.kind, p);
  if (Array.isArray(p.hours)) t.hours = p.hours.map(hourName).join(', ');
  return t;
}

/** "usually at most 1 GB per hour at this time" — or why it was told while still learning */
function usualText(kind, p) {
  if (p.learning) return kind === 'flow_written' ? T('flow.learning_share', { pct: p.pct || 0 }) : T('flow.learning', { size: fmt.size(p.limit || 0) });
  return p.usual > 0 ? T('flow.usual', { size: fmt.size(p.usual) }) : T('flow.usual_none');
}

/** An hour of the week (Monday 0:00 = 0) as "Mon 03:00" in this browser's language */
function hourName(h) {
  const d = new Date(2024, 0, 1 + Math.floor(h / 24), h % 24);      // 1 January 2024 was a Monday
  let day = '';
  try { day = new Intl.DateTimeFormat(Office.locale, { weekday: 'short' }).format(d); } catch (err) { day = String(Math.floor(h / 24) + 1); }
  return `${day} ${String(h % 24).padStart(2, '0')}:00`;
}

const flowLimits = () => {
  const l = (state && state.flow && state.flow.limits) || {};
  return { keep: l.keep || 14, factor: l.factor || 4, min: fmt.size(l.min || 2 * 1024 ** 3), learn: (state && state.flow && state.flow.learn) || 7,
    new: fmt.size(l.new || 50 * 1024 ** 3), part: l.part || 20 };
};

/** docker run flags as small code chips */
function flags(tokens) {
  const box = el('span', 'wm-flags');
  (tokens || []).forEach((t) => box.appendChild(el('span', 'wm-flag', t)));
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
  if (e.count > 1) add(T('detail.count'), T('times', { n: e.count }));
  if (e.group === 'login') {
    add(T('detail.ip'), p.ip, true);
    const users = (p.users || []).join(', ');
    add(T('detail.users'), [users, p.unknown ? T('detail.unknown', { n: p.unknown }) : ''].filter(Boolean).join(' · '));
    add(T('detail.services'), (e.t && e.t.service) || '');
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
    if (p.share) add(T('detail.share'), p.share, true);
    if (p.bytes !== undefined) {
      add(T(e.kind === 'flow_written' ? 'detail.written' : e.kind === 'flow_container' ? 'detail.sent' : 'detail.pulled'),
        T('detail.in_minutes', { size: fmt.size(p.bytes), minutes: p.minutes || 1 }));
      if (e.kind === 'flow_written' && p.pct) add(T('detail.part'), `${fmt.number(p.pct)} %`);
      if (!p.learning) add(T('detail.usual'), usualText(e.kind, p));
      if (p.peak) add(T('detail.peak'), fmt.size(p.peak));
    }
    if (Array.isArray(p.hours) && p.hours.length) add(T('detail.hours'), p.hours.map(hourName).join(', '));
  } else if (e.group === 'sched') {
    if (p.file) add(T('detail.cron_file'), '/boot/config/plugins/' + p.file + (p.new ? ` (${T('detail.file_new')})` : ''), true);
    if (p.path) add(T('detail.program'), p.path, true);
    if (p.plugin) add(T('detail.plugin'), p.plugin);
    if (p.job) add(T('detail.job'), p.job, true);
    if (p.jobs && p.jobs.length) add(T('detail.jobs'), lines(p.jobs, p.lines));
    if (e.kind === 'script_new' || e.kind === 'script_changed') {
      add(T('detail.script'), p.name);
      add(T('detail.schedule'), freq(p.cron));
      if (e.kind === 'script_changed' && p.old !== p.cron) add(T('detail.schedule_old'), freq(p.old));
      if (e.kind === 'script_changed') add(T('detail.content'), T(p.content ? 'detail.content_changed' : 'detail.content_same'));
    }
    if (e.kind === 'at_job') {
      add(T('detail.when'), fmt.date(p.when));
      add(T('detail.command'), p.cmd || '?', true);
      if (p.uid !== null && p.uid !== undefined) add(T('detail.uid'), String(p.uid));
    }
    if (e.kind === 'at_userscript') {
      add(T('detail.script'), p.name);
      add(T('detail.started'), fmt.date(p.when));
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
  if (e.open) notes.push(T('adopt.' + e.kind));
  if (e.noted) notes.push(T('noted.' + (['teamlead', 'baseline', 'auto'].includes(e.by) ? e.by : 'page'), { when: fmt.date(e.noted) }));
  if (e.told) notes.push(T('detail.told', { when: fmt.date(e.told) }));
  else if (e.muted && e.tell) notes.push(T('detail.muted'));
  else if (e.open) notes.push(T(e.tell ? 'detail.not_told' : 'detail.book_only'));
  notes.forEach((n) => box.appendChild(el('p', 'wm-note', n)));
  return box;
}

// ------------------------------------------------------------------ what he keeps an eye on
function item(name, parts, extra, label) {
  const r = el('div', 'row nocheck wm-item');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name' + (label ? ' text' : ''), name));
  const meta = el('div', 'row-meta');
  parts.filter(Boolean).forEach((x) => meta.appendChild(el('span', '', x)));
  if (extra) meta.appendChild(extra);
  if (meta.children.length) main.appendChild(meta);
  r.appendChild(main);
  return r;
}

/** A tinted title bar that folds its rows (closed unless opened, remembered in this browser) */
function group(key, title, sum, rows) {
  const box = el('div', 'group' + (openGroups[key] ? '' : ' closed'));
  const head = el('div', 'group-head');
  head.tabIndex = 0;
  head.setAttribute('role', 'button');
  const mid = el('div', 'group-mid');
  const tl = el('div', 'group-title');
  tl.appendChild(el('span', '', title));
  mid.append(tl, el('div', 'group-meta', sum));
  head.append(el('span', 'group-arrow', '▼'), mid);
  const body = el('div', 'group-rows');
  rows.forEach((x) => body.appendChild(x));
  const set = (open) => {
    box.classList.toggle('closed', !open);
    if (open) openGroups[key] = true; else delete openGroups[key];
    Office.storeJson('watchman.groups', openGroups);
  };
  const toggle = () => Office.keepInPlace(head, () => set(box.classList.contains('closed')));
  head.onclick = (e) => { toggle(); if (e.detail > 0) head.blur(); };
  head.onkeydown = (e) => { if (e.target === head && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(); } };
  box.append(head, body);
  if (rows.length) watchGroups.push({ open: () => !box.classList.contains('closed'), set });
  else head.classList.add('wm-empty-group');
  return box;
}

function watchSection() {
  const s = el('section', 'section');
  const unfold = el('button', 'btn small plain');
  unfold.type = 'button';
  s.appendChild(Office.sectionHead(T('watch.title'), T('watch.sub'), unfold));
  const box = el('div', 'box wm-watchlist');
  const w = state.watch;
  if (!w) {
    box.appendChild(el('p', 'empty', T('watch.none')));
    unfold.hidden = true;
    s.appendChild(box);
    return s;
  }
  box.appendChild(group('ips', T('watch.ips'), T('watch.ips_sum', { n: w.ips.length }),
    w.ips.map((x) => item(x.ip, [(x.users || []).join(', '), (x.services || []).join(', '), T('watch.last_seen', { when: fmt.relative(x.last) })]))));
  if (w.fail_ips.length) {
    box.appendChild(group('fail_ips', T('watch.fail_ips'), T('watch.fail_ips_sum', { n: w.fail_ips.length }),
      w.fail_ips.map((x) => item(x.ip, [T('watch.since', { when: fmt.date(x.since) }), x.quiet ? T('watch.quiet', { n: x.quiet }) : '',
        T('watch.last_seen', { when: fmt.relative(x.last) })]))));
  }
  const c = w.containers;
  box.appendChild(group('containers', T('watch.containers'),
    c ? T('watch.containers_sum', { special: c.special.length, count: c.count }) : T('watch.containers_wait'),
    c ? c.special.map((x) => item(x.name, [], flags(x.tokens))) : []));
  box.appendChild(group('plugins', T('watch.plugins'), T('watch.plugins_sum', { n: w.plugins.length }),
    w.plugins.map((x) => item(x.name, [x.source || T('watch.no_source'), x.version || '']))));
  const f = w.flash || { extra: [], users: [], keys: [] };
  const flash = [
    item(T('watch.go'), [f.go ? T('watch.go_lines', { n: f.go.lines }) : T('watch.go_none')]),
    item(T('watch.extra'), f.extra.length ? f.extra.map((x) => `${x.file} · ${fmt.size(x.size)}`) : [T('watch.extra_none')]),
    item(T('watch.users'), [f.users.join(', ')], null, true),
  ];
  if (f.keys.length) f.keys.forEach((k) => flash.push(item(T('watch.keys'), [k.user, [k.type, k.comment].filter(Boolean).join(' '), k.fp], null, true)));
  else flash.push(item(T('watch.keys'), [T('watch.keys_none')], null, true));
  box.appendChild(group('flash', T('watch.flash'), T('watch.flash_sum', { extra: f.extra.length,
    users: T('watch.flash_users', { n: f.users.length }), keys: T('watch.flash_keys', { n: f.keys.length }) }), flash));
  const sh = w.shares;
  box.appendChild(group('shares', T('watch.shares'),
    !sh ? T('watch.shares_wait') : sh.open.length ? T('watch.shares_sum', { open: sh.open.length, count: sh.count }) : T('watch.shares_none'),
    sh ? sh.open.map((x) => item(x.share, [x.smb ? `SMB: ${level(x.smb)}` : '', x.nfs ? `NFS: ${level(x.nfs)}` : ''])) : []));
  box.appendChild(schedGroup(w.sched));
  flowGroups(state.flow).forEach((g) => box.appendChild(g));
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

// ------------------------------------------------------------------ data flow
/** "learning: 2 of 7 days" on a row — only when it is behind the rest (it came later); nothing once learned */
const learningText = (days, f) => (days === null || days === undefined || (days === f.days && f.days < f.learn) ? ''
  : T('watch.flow_learning', { days, learn: f.learn || 7 }));
/** A quiet line in a group: what can't be looked at here, and why */
const note = (text) => el('p', 'wm-note wm-flow-note', text);
const total = (list, k) => (list || []).reduce((a, x) => a + (Number(x[k]) || 0), 0);
const serviceName = (s) => ({ smb: 'SMB', nfs: 'NFS', ssh: 'SSH', web: 'WebGUI' }[s] || s);

/** "usually at most 1 GB per hour now", or what «I know, thanks» made normal */
function usualRow(x) {
  if (x.learning !== null && x.learning !== undefined) return '';
  const n = Math.max(x.usual || 0, x.ack || 0);
  return n > 0 ? T('watch.flow_usual', { size: fmt.size(n) }) : T('watch.flow_usual_none');
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
  out.push(group('flow_clients', T('watch.flow_clients'),
    T('watch.flow_clients_sum', { learning: overall, n: (f.clients || []).length, size: fmt.size(total(f.clients, 'day')) }), clients));

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
    can.docker === false ? T('watch.flow_no_docker') : T('watch.flow_containers_sum', { size: fmt.size(total(f.containers, 'day')) }), cts));

  // written into ZFS shares
  const zfs = can.zfs;
  const shares = [];
  (f.shares || []).forEach((x) => shares.push(item(x.share, [T('watch.flow_day', { size: fmt.size(x.day) }),
    x.office ? T('watch.flow_office', { size: fmt.size(x.office) }) : '',
    x.snapshots ? (x.snap ? T('watch.flow_written_now', { when: fmt.relative(x.snap), size: fmt.size(x.written) }) : '') : T('watch.flow_nosnap'),
    usualRow(x), learningText(x.learning, f)])));
  if (zfs && zfs.asleep && zfs.asleep.length) shares.push(item(zfs.asleep.join(', '), [T('watch.flow_asleep')]));
  const noShares = !zfs ? T('watch.flow_no_zfs') : !(f.shares || []).length && !(zfs.asleep || []).length ? T('watch.flow_no_zfs_shares') : '';
  if (noShares) shares.push(note(noShares));
  out.push(group('flow_shares', T('watch.flow_shares'),
    noShares || T('watch.flow_shares_sum', { n: (f.shares || []).length, size: fmt.size(total(f.shares, 'day')) }), shares));

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
  if (can.office) out[0].querySelector('.group-meta').textContent += ' · ' + T('watch.flow_office_now');
  return out;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = root;
    render();
    await load(false);
  },
  unmount() { clearTimeout(timer); view = null; bookRows = []; watchGroups = []; bookLabel = null; },
  poll() { if (!running()) load(false); },
  started() { if (hired() && !state) load(false); },      // his picture shows the mark on every page
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
    if (state.watch) facts.push(T('fact.known', { ips: state.watch.ips.length, plugins: state.watch.plugins.length }));
    return { bubble: bubbleText(), facts };
  },
});
})();
