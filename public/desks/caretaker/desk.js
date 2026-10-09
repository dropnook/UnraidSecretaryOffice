/* The Caretaker — looks after the house: collects what every desk needs from
   the server (plugins, containers, settings), adds what the office as a whole
   benefits from, and says what is missing and what is left to do by hand.
   Every desk delivers its own checks (agent: desk(..., ['checks' => …]));
   their texts live in that desk's language file as check.<id> and
   check.<id>_how. The agent part lives in agent/desks/caretaker.php.
   Recommendations and notes can be put aside («I know, thanks»): the agent
   keeps them (data/caretaker/acks.json) and marks them "acked" in its state,
   each finding carries its "sig" — they move to «Noted» and count nowhere.
   The supporter key (core.js Office.supporter, src/supporter.php) unlocks
   nothing: with a valid one he shows a thank-you at «The team» (its
   pictures the kept keys' levels, each once: Office.supporterPictures); without,
   he asks once, friendly, a week after the office first ran here (a callout
   under «The team», «Not now» at most twice more, «Don't ask again»). */
(() => {
'use strict';

const ID = 'caretaker';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LINKS = { plugins: '/Plugins', apps: '/Apps', docker: '/Docker', userscripts: '/Settings/Userscripts', notifications: '/Settings/Notifications', settings: '/Settings',
  management: '/Settings/ManagementAccess', disks: '/Settings/DiskSettings', ups: '/Settings/UPSsettings' };

let state = null;
let view = null;
let showDone = false;
let showNoted = false;

// «Partner offices» lives in partner.js beside this file (loaded once; its version mark: this file's and the strings')
const ME = document.currentScript && document.currentScript.src;
Office.ctPartnerLoaded = () => {
  Office.ctPartner.use({
    took(partners) { if (state) state.partners = partners; if (view) Office.keepInPlace(null, render); },
    reload: () => load(true),
  });
  if (view) Office.keepInPlace(null, render);
};
if (ME && !Office.ctPartner) {
  const s = document.createElement('script');
  s.src = ME.replace(/desk\.js(\?.*)?$/, (m, q) => `partner.js${q || '?'}-${encodeURIComponent(String(Office.config.stamp || ''))}`);
  (document.currentScript.parentNode || document.head).appendChild(s);
}

/** His state: as kept at once, a new look following on his page (core.js Office.loadState()); fresh waits for a new look */
async function load(fresh) {
  return Office.loadState(ID, { fresh }, took);
}
function took(j) {
  if (j.ok && j.state) state = j.state;
  if (view) render();
  mood();
}

/** His picture says how the house is: a green check, a yellow (advice) or a red (to do) exclamation mark */
function mood() {
  if (!state) return;
  const g = groups();
  Office.setDeskMood(ID, g.todo.length ? 'todo' : g.advice.length ? 'advice' : '');
}

/** All findings, each with its desk */
function findings() {
  const out = [];
  if (!state || !state.checks) return out;
  for (const [desk, list] of Object.entries(state.checks)) {
    if (!Office.desks.has(desk) || !Office.desks.get(desk).hired) continue;
    list.forEach((f) => out.push({ ...f, desk }));
  }
  return out;
}

const text = (f, suffix) => {
  const key = `${f.desk}.check.${f.id}${suffix || ''}`;
  return Office.has(key) ? Office.t(key, f.params || {}) : (suffix ? '' : f.id);
};

/** A recommendation or a note that isn't in place can be put aside — a must can't */
const ackable = (f) => (f.level === 'recommended' || f.level === 'hint') && f.ok !== true && !!f.sig;

/** What is open (counts everywhere), what the user noted (counts nowhere), what is in place */
function groups() {
  const all = findings();
  const open = (f) => f.ok !== true && !(f.acked && ackable(f));
  return {
    todo: all.filter((f) => f.level === 'required' && f.ok !== true),
    advice: all.filter((f) => f.level === 'recommended' && open(f)),
    hints: all.filter((f) => f.level === 'hint' && open(f)),
    noted: all.filter((f) => f.acked && ackable(f)),
    done: all.filter((f) => f.ok === true),
  };
}

/** The desks that could work here, with what the caretaker found out about them */
function staff() {
  const out = [];
  for (const [id, s] of Object.entries((state && state.staff) || {})) {
    const d = Office.desks.get(id);
    if (d) out.push({ ...s, id, desk: d, hired: !!d.hired });
  }
  // who works here first, in the order set at the reception (core.js Office.deskRank); then who could come, by desk.json's order
  return out.sort((a, b) => (b.hired - a.hired)
    || (a.hired ? Office.deskRank(a.id) - Office.deskRank(b.id) : (a.desk.order ?? 0) - (b.desk.order ?? 0)));
}
const alone = () => !staff().some((s) => s.hired);
const fitText = (s) => {
  const key = `${s.id}.fit.${s.why}`;
  return Office.has(key) ? Office.t(key, s.params || {}) : T('fit.' + (s.ok ? 'yes' : 'no'), { name: Office.t(`${s.id}.name`) });
};

function bubbleText() {
  if (!state) return T('bubble.loading');
  if (alone()) return T('bubble.alone');
  const g = groups();
  if (!g.todo.length && !g.advice.length) return g.noted.length ? T('bubble.all_good_noted', { n: g.noted.length }) : T('bubble.all_good');
  const parts = [];
  if (g.todo.length) parts.push(T('bubble.todo', { n: g.todo.length }));
  if (g.advice.length) parts.push(T(g.todo.length ? 'bubble.advice' : 'bubble.advice_only', { n: g.advice.length }));
  return parts.join(' ');
}

// ------------------------------------------------------------------ rendering
function render() {
  const root = view;
  root.innerHTML = '';
  const again = el('button', 'btn plain', T('check_again'));
  again.type = 'button';
  again.disabled = !Office.agent.running;
  again.onclick = async () => {
    again.disabled = true;
    await load(true);
    Office.toast(T('checked'));
  };
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: bubbleText(), actions: [again] });
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('todo'), T('help.todo')],
    [T('advice'), T('help.advice')],
    [T('hints'), T('help.hints')],
    [T('ack'), T('help.ack')],
    [T('noted_term'), T('help.noted')],
    [el('span', 'chip danger', T('missing')), T('help.missing')],
    [el('span', 'chip warn', T('not_yet')), T('help.not_yet')],
    [el('span', 'chip warn', T('unknown')), T('help.unknown')],
    [T('team'), T('help.team')],
    [T('supporter_term'), T('help.supporter')],
    [deskChip(Office.desks.has('backup') ? 'backup' : ID), T('help.desk_text')],      // a real one, e.g. «💾 Herr Backupsi»
    [T('help.open'), T('help.open_text')],
    [T('check_again'), T('help.again')],
    [T('notify_title'), T('help.notify')],
    [T('help.parity_term'), T('help.parity')],
    [T('partner.title'), T('partner.help')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }

  const g = groups();
  root.appendChild(teamSection());                  // who works here comes first
  if (Office.supporter().ask) root.appendChild(askCallout());
  root.appendChild(officeSection());
  if (g.todo.length) root.appendChild(list('todo', T('todo_count', { n: g.todo.length }), T('todo_text'), g.todo));
  if (g.advice.length) root.appendChild(list('advice', T('advice_count', { n: g.advice.length }), T('advice_text'), g.advice));
  if (!g.todo.length && !g.advice.length) {
    const ok = el('div', 'box');
    const p = el('div', 'empty');
    p.append(el('strong', '', T('all_good_title')), T('all_good_text'));
    ok.appendChild(p);
    root.appendChild(ok);
  }
  root.appendChild(notifySection());
  if (Office.ctPartner) root.appendChild(Office.ctPartner.section(state));    // partner.js
  if (g.hints.length) root.appendChild(list('hints', T('hints_count', { n: g.hints.length }), T('hints_text'), g.hints));
  root.appendChild(doneSection(g.done));
  if (g.noted.length) root.appendChild(notedSection(g.noted));       // put aside: folded, at the end
  root.appendChild(el('p', 'role', T('checked_at', { when: fmt.relative(state.time) })));
}

function list(key, title, sub, items) {
  const s = el('section', 'section');
  s.dataset.ct = key;
  s.appendChild(Office.sectionHead(title, sub));
  const box = el('div', 'box');
  items.forEach((f) => box.appendChild(row(f)));
  s.appendChild(box);
  return s;
}

function deskChip(desk) {
  const d = Office.desks.get(desk);
  const chip = el('span', 'chip');
  chip.append(Office.deskIcon(d.id), Office.t(desk + '.name'));
  return chip;
}

function row(f) {
  const noted = !!(f.acked && ackable(f));
  const r = Office.place(`finding:${f.sig}`, el('div', 'row nocheck ct-finding' + (noted ? ' ct-noted' : '')));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', text(f)));
  const meta = el('div', 'row-meta');
  if (noted) meta.appendChild(el('span', 'chip quiet', T('level.' + f.level)));      // where it goes back to
  else if (f.level !== 'hint') {
    if (f.ok === null) meta.appendChild(el('span', 'chip warn', T('unknown')));
    else meta.appendChild(el('span', 'chip ' + (f.level === 'required' ? 'danger' : 'warn'), T(f.level === 'required' ? 'missing' : 'not_yet')));
  }
  meta.appendChild(deskChip(f.desk));
  main.appendChild(meta);
  const how = text(f, '_how');
  if (how) main.appendChild(el('div', 'row-detail', how));
  r.appendChild(main);
  const acts = el('div', 'ct-acts');
  if (f.link && f.link.startsWith('#/')) {
    // a page of the office itself, e.g. Mr. Backupsy's setup
    const a = el('a', 'btn small plain', T('open.office'));
    a.href = f.link;
    acts.appendChild(a);
  } else if (f.link && LINKS[f.link]) {
    // a page of Unraid's web UI, in the same tab like Unraid's own links
    const a = el('a', 'btn small plain', T('open.' + f.link));
    a.href = LINKS[f.link];
    acts.appendChild(a);
  }
  if (ackable(f)) {
    // «I know, thanks» / «Bring back» — kept on the server, so the Dashboard and his picture follow
    const b = el('button', 'btn small plain', T(noted ? 'unack' : 'ack'));
    b.type = 'button';
    b.title = T(noted ? 'unack_title' : 'ack_title');
    b.disabled = !Office.agent.running;
    b.onclick = () => ack(f, !noted, b);
    acts.appendChild(b);
  }
  if (acts.childNodes.length) r.appendChild(acts);
  return r;
}

async function ack(f, on, b) {
  b.disabled = true;
  const j = await Office.api.post(`${ID}.${on ? 'ack' : 'unack'}`, { sig: f.sig });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    if (j.error && j.error.key === 'ack_gone') await load(true);      // the point changed meanwhile: show it as it is now
    else b.disabled = false;
    return;
  }
  state = j.state;
  // the row leaves its list: its section stays where it is on screen — if the section went, the one above it;
  // brought back, it goes up into its list and «Noted» (or «In place» above it) stays put
  if (view) renderKeeping(on ? (f.level === 'hint' ? ['hints', 'notify'] : ['advice', 'office']) : ['noted', 'done']);
  mood();
  Office.toast(T(on ? 'acked' : 'unacked'));
}

/** Re-render without the page jumping: the first of these sections that is there before and after keeps its place */
function renderKeeping(keys) {
  const top = (k) => {
    const n = view && view.querySelector(`[data-ct="${k}"]`);
    return n ? n.getBoundingClientRect().top : null;
  };
  const before = keys.map(top);
  Office.keepInPlace(null, render);
  for (let i = 0; i < keys.length; i++) {
    const now = top(keys[i]);
    if (before[i] === null || now === null) continue;
    if (Math.abs(now - before[i]) > 1) window.scrollBy(0, now - before[i]);
    break;
  }
}

// ------------------------------------------------------------------ reports to Unraid's notifications
/** What turns red is also told to Unraid (agent: caretakerNotifyEvaluate) — the switch, and the last report */
function notifySection() {
  const n = state.notify || { on: true, available: true, waiting: 0, last: null };
  const s = el('section', 'section');
  s.dataset.ct = 'notify';
  const label = el('label', 'switch');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = n.on !== false;
  cb.disabled = !Office.agent.running;
  label.append(cb, el('span', '', T('notify_switch')));
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
    state = j.state;
    Office.keepInPlace(null, render);
    Office.toast(T(on ? 'notify_on' : 'notify_off'));
  };
  s.appendChild(Office.sectionHead(T('notify_title'), T('notify_sub'), label, { place: 'notify_title' }));

  const box = el('div', 'box');
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  const last = n.last;
  const items = last ? (last.items || []) : [];
  main.appendChild(el('div', 'row-name text', !last ? T('notify_none')
    : T(last.sent === false ? 'notify_last_failed' : 'notify_last', { when: fmt.relative(last.time), n: items.length })));
  if (n.on !== false && n.waiting) {
    const meta = el('div', 'row-meta');
    meta.appendChild(el('span', '', T('notify_waiting', { n: n.waiting })));
    main.appendChild(meta);
  }
  if (items.length) main.appendChild(el('div', 'row-detail', items.map((f) => text(f)).join(' · ')));
  if (n.available === false) main.appendChild(el('div', 'row-detail', T('notify_missing')));
  r.appendChild(main);
  box.appendChild(r);
  s.appendChild(box);
  return s;
}

// ------------------------------------------------------------------ the office itself
function officeSection() {
  const o = state.office || {};
  const s = el('section', 'section');
  s.dataset.ct = 'office';
  const again = el('button', 'btn small plain', T('office_check'));
  again.type = 'button';
  again.disabled = !Office.agent.running;
  again.onclick = async () => {
    again.disabled = true;
    const j = await Office.api.post(`${ID}.office_check`, {});
    if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); again.disabled = false; return; }
    state = j.state;
    render();
    Office.toast(T('office_checked'));
  };
  s.appendChild(Office.sectionHead(T('office'), T('office_sub'), again, { place: 'office' }));
  const box = el('div', 'box');
  const row = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', `🗂️ Unraid Secretary Office ${o.version || ''}`));
  const meta = el('div', 'row-meta');
  if (o.newer) meta.appendChild(el('span', 'chip warn', T('office_newer', { version: o.latest })));
  else if (o.latest) meta.appendChild(el('span', 'chip ok', T('office_current')));
  else if (o.error) meta.appendChild(el('span', 'chip quiet', T('office_error.' + o.error)));
  if (o.checked) meta.appendChild(el('span', '', T('office_checked_at', { when: fmt.relative(o.checked) })));
  main.appendChild(meta);
  if (o.newer) main.appendChild(el('div', 'row-detail', T('office_how')));
  row.appendChild(main);
  const right = el('div', 'ct-office-actions');
  if (o.newer && Office.safeHref(o.url)) {
    const a = el('a', 'btn small plain', T('office_whats_new'));
    a.href = Office.safeHref(o.url);
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    right.appendChild(a);
  }
  if (o.newer) {
    const a = el('a', 'btn small', T('office_to_plugins'));
    a.href = '/Plugins';
    right.appendChild(a);
  }
  row.appendChild(right);
  box.appendChild(row);
  s.appendChild(box);
  return s;
}

// ------------------------------------------------------------------ the team
function teamSection() {
  const all = staff();
  const open = all.filter((s) => !s.hired && s.ok);
  const extra = [];
  const sup = Office.supporter();
  if (sup.state === 'valid') {
    // the supporter keys' plate: a thank-you, not a button (a green outline), as tall as the buttons beside it; the
    // name (the newest key's) as text only; the pictures each kept level's in level order with ×n when several
    // (☕×3 ☕☕ 🍰 🥂, signed in the keys — only the owner sees it, never a rank)
    const level = Office.supporterPictures(sup);
    const MARK = '\u0001';
    const plate = el('span', 'chip ok ct-supporter');
    // its words by the highest level and how many keys of it (Benj, 2026-10-09: «Prost!», «jam-jam», «schon der 3. Kaffee»)
    const top = level.top;
    const many = level.count(top);
    const variant = top === 'coffee' ? (many >= 3 ? 'coffee_many' : many === 2 ? 'coffee_two' : '') : `${top}_${many >= 2 ? 'more' : 'one'}`;
    // several names on the keys: all of them — «Benj & Janine», from three on «Benj & 2 weitere» (Benj, 2026-10-09)
    const names = Array.isArray(sup.names) && sup.names.length ? sup.names : [sup.name];
    const who = names.length === 1 ? names[0] : names.length === 2 ? T('supporter_names_two', { a: names[0], b: names[1] })
      : T('supporter_names_more', { a: names[0], n: names.length - 1 });
    const words = (variant && T(`supporter_plate.${variant}`, { icon: MARK, name: who, n: many })) || T('supporter_plate', { icon: MARK, name: who });
    words.split(MARK).forEach((part, i) => {
      if (i) plate.appendChild(el('span', 'ct-supporter-pics', level.icon));
      if (part.trim()) plate.appendChild(el('span', 'ct-supporter-words', part.trim()));
    });
    plate.title = T('supporter_plate_title', { level: level.text, date: Office.fmt.day(sup.date) });
    extra.push(plate);
  }
  const tip = el('button', 'btn small plain', T('tip_button'));
  tip.type = 'button';
  tip.title = T('tip_button_title');
  tip.onclick = () => Office.tipJar();
  extra.push(tip);
  if (Office.config.report && Office.reportDialog) {
    // «Report a problem or a wish…»: he passes it on to the people who build the office (the office as a whole)
    const report = el('button', 'btn small plain', T('report_button'));
    report.type = 'button';
    report.title = T('report_button_title');
    report.onclick = () => Office.reportDialog('office');
    extra.push(report);
  }
  if (open.length > 1) {
    const b = el('button', 'btn small', T('hire_all', { n: open.length }));
    b.type = 'button';
    b.disabled = !Office.agent.running;
    b.onclick = () => hire(open.map((s) => s.id));
    extra.push(b);
  }
  const s = el('section', 'section');
  s.appendChild(Office.sectionHead(T('team'), T(alone() ? 'team_sub_alone' : 'team_sub'), ...extra, { place: 'team' }));
  const box = el('div', 'box');
  all.forEach((x) => box.appendChild(teamRow(x)));
  // under contract but still learning (desk.json "training"): shown, not hired yet
  [...Office.desks.values()].filter((d) => d.training).forEach((d) => box.appendChild(trainingRow(d)));
  s.appendChild(box);
  return s;
}

/**
 * His one friendly ask — a week after the office first ran here, never with a supporter key; not a
 * dialog, a quiet callout on his page. The answer is kept on the server (data/office/supporter.json).
 */
function askCallout() {
  const box = el('div', 'callout ct-ask');
  box.dataset.ct = 'ask';
  box.appendChild(el('p', '', T('ask_text')));
  const acts = el('div', 'ct-ask-acts');
  const add = (text, cls, act) => {
    const b = el('button', cls, text);
    b.type = 'button';
    b.onclick = act;
    acts.appendChild(b);
  };
  add(T('ask_tip'), 'btn small', async () => { await Office.supporterAsk('later'); Office.tipJar(); });
  add(T('ask_later'), 'btn small plain', async () => { if (await Office.supporterAsk('later')) Office.toast(T('ask_later_done')); });
  add(T('ask_never'), 'btn small plain', async () => { if (await Office.supporterAsk('never')) Office.toast(T('ask_never_done')); });
  box.appendChild(acts);
  return box;
}

function trainingRow(d) {
  const r = el('div', 'row nocheck ct-person ct-candidate');
  const av = Office.avatar(d.id);
  av.classList.add('ct-avatar');
  r.appendChild(av);
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', Office.t(`${d.id}.name`)));
  const meta = el('div', 'row-meta');
  meta.appendChild(el('span', 'chip quiet', T('in_training')));
  meta.appendChild(el('span', '', Office.t(`${d.id}.role`)));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', Office.t(`${d.id}.training`)));
  r.appendChild(main);
  const b = el('button', 'btn small plain', T('training_tip'));
  b.type = 'button';
  b.title = T('training_tip_title');
  b.onclick = () => Office.tipJar();
  r.appendChild(b);
  return r;
}

function teamRow(x) {
  const r = Office.place(`staff:${x.id}`, el('div', 'row nocheck ct-person' + (x.hired ? '' : ' ct-candidate')));
  const av = Office.avatar(x.id);
  av.classList.add('ct-avatar');
  r.appendChild(av);
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', Office.t(`${x.id}.name`)));
  const meta = el('div', 'row-meta');
  meta.appendChild(el('span', 'chip ' + (x.hired ? 'ok' : x.ok ? 'accent' : 'quiet'), T(x.hired ? 'in_team' : x.ok ? 'could_come' : 'declined')));
  meta.appendChild(el('span', '', Office.t(`${x.id}.role`)));
  main.appendChild(meta);
  if (!x.hired || x.why !== 'yes') main.appendChild(el('div', 'row-detail', fitText(x)));     // once hired, "would like to come" is old news
  r.appendChild(main);
  let b = null;
  if (x.hired) {
    b = el('button', 'btn small plain', T('fire'));
    b.onclick = () => fire(x.id);
  } else if (x.ok) {
    // a desk that needs a colleague (desk.json "with"): both at once while the colleague isn't here and would come —
    // the colleague first, so he stands first in the team; hiring him alone stays possible (nothing blocks)
    const w = colleague(x);
    b = el('button', 'btn small', w ? T('hire_with', { name: nameWith(w.id) }) : T('hire'));
    b.onclick = () => hire(w ? [w.id, x.id] : [x.id]);
  }
  if (b) {
    b.type = 'button';
    b.disabled = !Office.agent.running;
    r.appendChild(b);
  } else {
    r.appendChild(el('span'));
  }
  return r;
}

/** The colleague a candidate asks to come with (desk.json "with"), while he isn't hired and would come; else null */
function colleague(x) {
  const id = x.desk && x.desk.with;
  if (!id || id === x.id) return null;
  const w = staff().find((s) => s.id === id);
  return w && !w.hired && w.ok && !w.desk.training ? w : null;
}
/** His name as «with …» needs it (de «Herrn Backupsi», es «el señor Backupsi»): <desk>.name_with, else the name */
const nameWith = (id) => Office.t(Office.has(`${id}.name_with`) ? `${id}.name_with` : `${id}.name`);

async function hire(ids) {
  if (!await Office.hire(ids)) return;
  Office.toast(T('hired', { names: ids.map((id) => Office.t(`${id}.name`)).join(', ') }));
  await load(true);
}

function fire(id) {
  Office.fireDialog(id, () => load(true));
}

function doneSection(items) {
  const s = el('section', 'section');
  s.dataset.ct = 'done';
  const toggle = el('button', 'btn small plain', showDone ? T('hide') : T('show'));
  toggle.type = 'button';
  toggle.onclick = () => { showDone = !showDone; Office.keepInPlace(null, render); };
  s.appendChild(Office.sectionHead(T('done', { n: items.length }), T('done_sub'), toggle, { place: 'done' }));
  if (showDone) {
    const box = el('div', 'box');
    items.forEach((f) => {
      const r = el('div', 'row nocheck');
      const main = el('div', 'row-main');
      main.appendChild(el('div', 'row-name text', '✓ ' + text(f)));
      const meta = el('div', 'row-meta');
      meta.append(deskChip(f.desk), el('span', '', T('level.' + f.level)));
      main.appendChild(meta);
      r.appendChild(main);
      box.appendChild(r);
    });
    s.appendChild(box);
  }
  return s;
}

/** What the user put aside with «I know, thanks» — folded, each with «Bring back» */
function notedSection(items) {
  const s = el('section', 'section');
  s.dataset.ct = 'noted';
  const toggle = el('button', 'btn small plain', showNoted ? T('hide') : T('show'));
  toggle.type = 'button';
  toggle.onclick = () => { showNoted = !showNoted; Office.keepInPlace(null, render); };
  s.appendChild(Office.sectionHead(T('noted', { n: items.length }), T('noted_sub'), toggle, { place: 'noted' }));
  if (showNoted) {
    const box = el('div', 'box');
    items.forEach((f) => box.appendChild(row(f)));
    s.appendChild(box);
  }
  return s;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = root;
    render();
    await load(false);
  },
  unmount() { view = null; },
  poll() { load(false); },
  started() { if (!state) load(false); },     // his badge shows on every page
  agentChanged() { if (view) render(); },
  supporterChanged() { if (view) Office.keepInPlace(null, render); },     // a key entered or removed, his ask answered
  async reception() {
    if (!state) await load(false);
    if (alone()) {
      const could = staff().filter((x) => x.ok).map((x) => Office.t(`${x.id}.name`));
      return { bubble: T('bubble.alone_short'), facts: could.length ? [T('fact.could_come', { names: could.join(', ') })] : [] };
    }
    const g = groups();
    const facts = [];
    g.todo.slice(0, 3).forEach((f) => facts.push(text(f)));
    if (g.todo.length > 3) facts.push(T('fact.more', { n: g.todo.length - 3 }));
    if (!g.todo.length) facts.push(T('fact.done', { n: g.done.length }));
    return { bubble: bubbleText(), facts };
  },
});

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys)
Office.places(ID, [
  { kind: 'section', key: 'team' },
  { kind: 'section', key: 'office' },
  { kind: 'section', key: 'notify_title' },
  { kind: 'section', key: 'partner.title' },
  { kind: 'section', key: 'done' },
  { kind: 'section', key: 'noted', shown: () => !state || groups().noted.length > 0 },     // drawn only with something put aside
  ...[['todo', 'help.todo'], ['advice', 'help.advice'], ['hints', 'help.hints'], ['ack', 'help.ack'], ['noted_term', 'help.noted'],
    ['missing', 'help.missing'], ['not_yet', 'help.not_yet'], ['unknown', 'help.unknown'], ['team', 'help.team'],
    ['supporter_term', 'help.supporter'], ['help.open', 'help.open_text'], ['check_again', 'help.again'], ['notify_title', 'help.notify'],
    ['help.parity_term', 'help.parity'], ['partner.title', 'partner.help']].map(([key, text]) => ({ kind: 'help', key, text })),
]);

// what his state holds for the search (core.js «items»): the points still open (as his lists word them), who could work
// here, the partner offices and the tickets (partner.js marks their cards)
const LISTS = { required: 'todo', recommended: 'advice', hint: 'hints' };
Office.placesFrom(ID, (s) => {
  const out = [];
  for (const [desk, list] of Object.entries(s.checks || {})) {
    if (!Office.desks.has(desk) || !Office.desks.get(desk).hired || !Array.isArray(list)) continue;
    list.forEach((f) => {
      if (!f || !LISTS[f.level] || f.ok === true || !f.sig || (f.acked && ackable(f))) return;
      out.push({ text: text({ ...f, desk }), sub: `${T(LISTS[f.level])} · ${Office.t(desk + '.name')}`, anchor: `finding:${f.sig}` });
    });
  }
  for (const [id, x] of Object.entries(s.staff || {})) {
    const d = Office.desks.get(id);
    if (!d || !x) continue;
    out.push({ text: Office.t(`${id}.name`), sub: `${T('team')} · ${T(d.hired ? 'in_team' : x.ok ? 'could_come' : 'declined')}`, anchor: `staff:${id}` });
  }
  const p = s.partners || {};
  // a pair's card says which way the copies go: two pairs with the same partner (one each way) told apart by its words
  const way = (x) => [x.sends ? T('partner.i_send', { host: Office.config.host }) : '', x.receive ? T('partner.i_keep', { host: Office.config.host }) : '']
    .filter(Boolean).map((w) => w.replace(/[\s:：]+$/, '')).join(' · ');
  (p.pairs || []).forEach((x) => out.push({ text: x.name, sub: [T('partner.title'), way(x)].filter(Boolean).join(' · '), anchor: `partner:${x.id}` }));
  (p.ticket_pairs || []).forEach((t) => out.push({ text: T('partner.t_card', { name: t.name, of: t.of }), sub: T('partner.title'), anchor: `ticket:${t.id}` }));
  (p.ticket_requests || []).forEach((q) => out.push({ text: T('partner.t_request', { id: q.id }), sub: T('partner.title'), anchor: `ticket:${q.id}` }));
  return out;
});

// tests/run.php draws a candidate's row under node (the «Hire together with …» button)
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.caretaker = { setState: (s) => { state = s; }, staff, teamRow };
}
})();
