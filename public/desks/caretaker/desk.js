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
   under «The team», «Not now» at most twice more, «Don't ask again»).
   His page is a row of tiles (tiles v2, core.js Office.sections): Team, Still to do, In place, Partners, the notification
   centre (its history, what waits for its summary), the Office (its version) last — each its section under the row,
   nothing chosen = all of them. */
(() => {
'use strict';

const ID = 'caretaker';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LINKS = { plugins: '/Plugins', apps: '/Apps', shares: '/Shares', docker: '/Docker', userscripts: '/Settings/Userscripts', notifications: '/Settings/Notifications', settings: '/Settings',
  management: '/Settings/ManagementAccess', disks: '/Settings/DiskSettings', ups: '/Settings/UPSsettings' };

let state = null;
let view = null;
let showDone = false;
let showNoted = false;
// the bar over his lists (core.js Office.filterBar, built once): words only, over every list (to do, advice, hints, in
// place, noted) — it shows itself only from Office.FILTER_MIN open points on, so a quiet server sees nothing; never kept
let listBar = null;

// «Partner offices» lives in partner.js beside this file (loaded once; its version mark: this file's and the strings')
const ME = document.currentScript && document.currentScript.src;
Office.ctPartnerLoaded = () => {
  Office.ctPartner.use({
    took(partners) { if (state) state.partners = partners; if (view) Office.keepInPlace(null, render); },
    tookNet(usonet) { if (state) state.usonet = usonet; if (view) Office.keepInPlace(null, render); },     // the USO network's card
    reload: () => load(true),
    peek: () => load(false),          // his state as kept (the cards anew after a partner job: partnerRescanAsk())
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

/** A finding's words: a param <name>_t (a Unix time beside the agent's written-out <name>) is the date in the reader's language */
const checkParams = (f) => {
  const p = { ...(f.params || {}) };
  Object.keys(p).forEach((k) => {
    const m = /^(\w+)_t$/.exec(k);
    if (m && typeof p[k] === 'number' && p[k] > 0) p[m[1]] = fmt.date(p[k]);
  });
  return p;
};
const text = (f, suffix) => {
  const key = `${f.desk}.check.${f.id}${suffix || ''}`;
  return Office.has(key) ? Office.t(key, checkParams(f)) : (suffix ? '' : f.id);
};

/** A recommendation or a note that isn't in place can be put aside — a must can't */
const ackable = (f) => (f.level === 'recommended' || f.level === 'hint') && f.ok !== true && !!f.sig;

/**
 * What is open (counts everywhere), what the user noted (counts nowhere), what is in place — and what couldn't be checked
 * (ok null: a disk asleep, Docker silent, a first run still to come): neither a must nor in place, a group of its own that
 * counts nowhere (truth review T13; the agent's caretakerRed() and the Dashboard take only false as well)
 */
function groups() {
  const all = findings();
  const open = (f) => f.ok === false && !(f.acked && ackable(f));
  return {
    todo: all.filter((f) => f.level === 'required' && f.ok === false),
    advice: all.filter((f) => f.level === 'recommended' && open(f)),
    unchecked: all.filter((f) => f.level !== 'hint' && f.ok !== true && f.ok !== false && !(f.acked && ackable(f))),
    hints: all.filter((f) => f.level === 'hint' && f.ok !== true && !(f.acked && ackable(f))),
    noted: all.filter((f) => f.acked && ackable(f)),
    done: all.filter((f) => f.ok === true),
  };
}

/** The desks that could work here, with what the caretaker found out about them */
function staff() {
  const out = [];
  for (const [id, s] of Object.entries((state && state.staff) || {})) {
    const d = Office.desks.get(id);
    // a desk in training (desk.json "training") is no candidate: her own row below, nobody can hire her yet
    if (d && !d.training) out.push({ ...s, id, desk: d, hired: !!d.hired });
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
  const unchecked = g.unchecked.length ? ' ' + T('bubble.unchecked', { n: g.unchecked.length }) : '';
  if (!g.todo.length && !g.advice.length) return (g.noted.length ? T('bubble.all_good_noted', { n: g.noted.length }) : T(unchecked ? 'bubble.all_good_checked' : 'bubble.all_good')) + unchecked;
  const parts = [];
  if (g.todo.length) parts.push(T('bubble.todo', { n: g.todo.length }));
  if (g.advice.length) parts.push(T(g.todo.length ? 'bubble.advice' : 'bubble.advice_only', { n: g.advice.length }));
  return parts.join(' ') + unchecked;
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
    const j = await load(true).catch(() => null);
    // «done» only for a look that came: the agent away or the look still under way is said (truth review O12)
    if (j && j.ok && !j.refreshing) Office.toast(T('checked'));
    else Office.toast(j && j.error ? Office.errorText(j.error, ID) : T('check_not_done'), true);
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
    [el('span', 'chip quiet', T('unknown')), T('help.unknown')],
    [T('team'), T('help.team')],
    [T('supporter_term'), T('help.supporter')],
    [deskChip(Office.desks.has('backup') ? 'backup' : ID), T('help.desk_text')],      // a real one, e.g. «💾 Herr Backupsi»
    [T('help.open'), T('help.open_text')],
    [T('check_again'), T('help.again')],
    [T('notify_title'), T('help.notify')],
    [T('help.parity_term'), T('help.parity')],
    [T('partner.title'), T('partner.help')],
    [T('usonet.title'), T('usonet.help')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }
  root.appendChild(sw.el);          // the row and under it the chosen section — or, nothing chosen, all of them
  sw.render();
  root.appendChild(el('p', 'role', T('checked_at', { when: fmt.relative(state.time) })));
  // «n of m fit» over the points still open and those noted (what is in place is filtered too, but doesn't make the bar come)
  const { g, fit } = lists();
  const counted = ['todo', 'advice', 'hints', 'noted'];
  bar().done(counted.reduce((a, k) => a + fit[k].length, 0), counted.reduce((a, k) => a + g[k].length, 0));
}

/**
 * His row and the section under it (core.js Office.sections, CLAUDE.md «Tiles»): a tile per section — the team, what is
 * still open and what is in place (the pair), the partner offices, the reports to Unraid (the centre, its history, what
 * waits for the summary), the office itself (its version, the plainest) last. No start tile: nothing chosen
 * shows every section in the row's order; a choice is kept as caretaker.tile, #/caretaker/<tile> picks one like a click.
 */
const sw = Office.sections({
  id: ID,
  grid: 'stats ct-tiles',
  default: '',
  tiles: [
    { id: 'team', key: 'tile.team', place: 'tile.team', tile: (a) => teamTile(a), section: () => teamSection() },
    { id: 'todo', key: 'tile.todo', place: 'tile.todo', tile: (a) => todoTile(a), section: () => pointsSection() },
    { id: 'done', key: 'tile.done', place: 'tile.done', tile: (a) => doneTile(a), section: () => doneSection() },
    { id: 'partner', key: 'tile.partner', place: 'tile.partner', tile: (a) => partnerTile(a),
      section: () => (Office.ctPartner ? Office.ctPartner.section(state) : partnerWaiting()) },      // partner.js
    { id: 'notify', key: 'tile.notify', place: 'tile.notify', tile: (a) => notifyTile(a), section: () => notifySection() },
    { id: 'notify_log', key: 'tile.notify_log', place: 'tile.notify_log', tile: (a) => notifyLogTile(a), section: () => notifyLogSection() },
    { id: 'notify_wait', key: 'tile.notify_wait', place: 'tile.notify_wait', tile: (a) => notifyWaitTile(a), section: () => notifyWaitSection() },
    { id: 'office', key: 'tile.office', place: 'tile.office', tile: (a) => officeTile(a), section: () => officeSection() },
  ],
});

/** The findings in his groups and, beside them, what the bar's words leave of each */
function lists() {
  const g = groups();
  const filtering = !!(listBar && listBar.read().words.length);
  const fit = {};
  for (const k of Object.keys(g)) fit[k] = filtering ? g[k].filter(fits) : g[k];
  return { g, fit, filtering };
}

// ------------------------------------------------------------------ the tiles
/** «Team»: who is hired, who could come — red when a hired desk is missing at the messenger */
function teamTile(a) {
  const all = staff();
  const miss = missingDesks().length;
  const hired = all.filter((x) => x.hired).length + miss;
  const could = all.filter((x) => !x.hired && x.ok).length;
  return Office.stat({ label: T('tile.team'), value: fmt.number(hired), alert: miss > 0,
    sub: [T('tile.team_line', { hired: T('count.hired', { n: hired }), could: T('count.could', { n: could }) }), miss ? T('count.missing', { n: miss }) : ''],
    ...a });
}

/** «Office»: the version running, up to date or the update waiting */
function officeTile(a) {
  const o = state.office || {};
  const sub = o.newer ? T('tile.office_update', { version: o.latest }) : o.latest ? T('office_current') : o.error ? T('office_error.' + o.error) : '';
  return Office.stat({ label: T('tile.office'), value: o.version || '?', sub, alert: !!o.newer, ...a });
}

/** «Still to do»: the points still open — red while a must or a recommendation waits */
function todoTile(a) {
  const g = groups();
  const n = g.todo.length + g.advice.length + g.hints.length;
  const parts = [[g.todo, 'count.must'], [g.advice, 'count.advice'], [g.hints, 'count.hint'], [g.unchecked, 'count.unchecked']]
    .filter(([l]) => l.length).map(([l, k]) => T(k, { n: l.length }));
  return Office.stat({ label: T('tile.todo'), value: fmt.number(n), alert: g.todo.length + g.advice.length > 0,
    sub: parts.length ? parts.join(' · ') : T('tile.todo_none'), ...a });
}

/** «Partners»: how many, whether they answer — red when one is silent or an offer or a ticket waits */
function partnerTile(a) {
  const p = state.partners || {};
  const pairs = p.pairs || [];
  const held = p.ticket_pairs || [];
  // a key or a door gone (a plugin remove takes both), or a partner that no longer knows the pair (set up anew), counts
  // at once — never the last «answers» of the state
  const broken = (x) => x.key_missing || x.door === 'closed' || x.door === 'changed' || x.no_pair;
  const lost = pairs.filter(broken).length;
  const silent = pairs.filter((x) => !broken(x) && x.silent).length;
  const answer = pairs.filter((x) => !broken(x) && !x.silent && x.reachable !== false && x.last_heard).length;
  const waiting = (p.pending || []).length + (p.ticket_requests || []).length
    + pairs.filter((x) => x.offer && Array.isArray(x.offer.pending) && x.offer.pending.length).length;
  const sub = [];
  if (lost) sub.push(T('count.pair_anew', { n: lost }));
  if (silent) sub.push(T('count.silent', { n: silent }));
  else if (answer && answer === pairs.length) sub.push(T('tile.partner_all', { n: answer }));
  else if (answer) sub.push(T('count.answers', { n: answer }));
  if (waiting) sub.push(T('count.waiting', { n: waiting }));
  if (!pairs.length && !held.length && !waiting) sub.push(T('tile.partner_none'));
  return Office.stat({ label: T('tile.partner'), value: fmt.number(pairs.length + held.length), sub: sub.join(' · '),
    alert: lost > 0 || silent > 0 || waiting > 0, ...a });
}

/** «In place»: what is fine, and how many points you noted */
function doneTile(a) {
  const g = groups();
  return Office.stat({ label: T('tile.done'), value: fmt.number(g.done.length),
    sub: g.noted.length ? T('count.noted', { n: g.noted.length }) : T('tile.done_sub'), ...a });
}

// ------------------------------------------------------------------ what is open
/** «Still to do»: the musts, the recommendations and what is good to know — one section, a tinted head per kind */
function pointsSection() {
  const { g, fit } = lists();
  const s = el('section', 'section');
  s.dataset.ct = 'open';
  s.appendChild(Office.sectionHead(T('todo_count', { n: g.todo.length + g.advice.length + g.hints.length }), T('open_sub'), { place: 'todo_count' }));
  s.appendChild(bar().el);          // the bar over his lists: here, or under «In place» when that is chosen
  if (g.todo.length) s.appendChild(list('todo', T('must_count', { n: g.todo.length }), T('todo_text'), fit.todo));
  if (g.advice.length) s.appendChild(list('advice', T('advice_count', { n: g.advice.length }), T('advice_text'), fit.advice));
  if (!g.todo.length && !g.advice.length) {
    const ok = el('div', 'box');
    const p = el('div', 'empty');
    p.append(el('strong', '', T('all_good_title')), T('all_good_text'));
    ok.appendChild(p);
    s.appendChild(ok);
  }
  if (g.unchecked.length) s.appendChild(list('unchecked', T('unchecked_count', { n: g.unchecked.length }), T('unchecked_text'), fit.unchecked));
  if (g.hints.length) s.appendChild(list('hints', T('hints_count', { n: g.hints.length }), T('hints_text'), fit.hints));
  return s;
}

/** «Partner offices» while partner.js is still on its way */
function partnerWaiting() {
  const s = el('section', 'section');
  s.dataset.ct = 'partners';
  s.appendChild(Office.sectionHead(T('partner.title'), T('partner.sub'), { place: 'partner.title' }));
  s.appendChild(el('p', 'empty', Office.t('common.loading')));
  return s;
}

/** The bar over his lists, built once */
function bar() {
  if (!listBar) listBar = Office.filterBar({ key: 'caretaker.list', label: T('list.filter_label'), onChange: () => { if (view) render(); } });
  return listBar;
}
/** A point's words for the bar: what it says, what to do, the desk, its level */
const fits = (f) => Office.fits(listBar ? listBar.read().words : [], [text(f), text(f, '_how'), Office.t(f.desk + '.name'), T('level.' + f.level)]);
/** «Nothing fits» in a list the bar empties (its head stays) */
const noFit = () => el('p', 'empty', T('list.none_fit'));

/** One kind of point inside a section: a tinted head (its name and what it means), the rows under it */
function list(key, title, sub, items, extra) {
  const box = el('div', 'box ct-group');
  box.dataset.ct = key;
  const head = el('div', 'ct-subhead');
  const words = el('div', 'ct-subhead-words');
  words.append(el('strong', '', title), el('span', '', sub));
  head.appendChild(words);
  if (extra) head.appendChild(extra);
  box.appendChild(head);
  (items || []).forEach((f) => box.appendChild(row(f)));
  if (items && !items.length) box.appendChild(noFit());
  return box;
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
    if (f.ok !== false) {                // couldn't check: neutral, never red (T13)
      const c = el('span', 'chip quiet', T('unknown'));
      c.title = T('unknown_tip');
      meta.appendChild(c);
    } else meta.appendChild(el('span', 'chip ' + (f.level === 'required' ? 'danger' : 'warn'), T(f.level === 'required' ? 'missing' : 'not_yet')));
  }
  meta.appendChild(deskChip(f.desk));
  main.appendChild(meta);
  // the how-text says what to fix — for a check that couldn't look, why it is open instead («Missing on …: .» was wrong)
  const how = f.level !== 'hint' && f.ok !== true && f.ok !== false ? T('unchecked_how') : text(f, '_how');
  if (how) main.appendChild(el('div', 'row-detail', how));
  r.appendChild(main);
  const acts = el('div', 'ct-acts');
  if (f.link && f.link.startsWith('#/')) {
    // a page of the office itself, e.g. Mr. Backupsy's setup — the desk's own word for it when it has one (check.<id>_link)
    const a = el('a', 'btn small plain', text(f, '_link') || T('open.office'));
    a.href = f.link;
    acts.appendChild(a);
  } else if (f.link && LINKS[f.link]) {
    // a page of Unraid's web UI, in the same tab like Unraid's own links
    const a = el('a', 'btn small plain', T('open.' + f.link));
    a.href = LINKS[f.link];
    acts.appendChild(a);
  }
  if (f.id === 'std_shares' && f.ok === false && !noted && f.params && f.params.shares) {
    // Unraid's standard shares never there (system): created on this click only, the way Unraid's ⟦Shares⟧ page does
    const names = String(f.params.shares).split(', ').filter(Boolean);
    const b = el('button', 'btn small', names.length === 1 ? T('shares_make_one', { share: names[0] }) : T('shares_make_all'));
    b.type = 'button';
    b.disabled = !Office.agent.running;
    b.onclick = () => sharesMake(names, b);
    acts.appendChild(b);
  }
  if ((f.id === 'partner_line_orphan' || f.id === 'partner_line_orphan_quiet') && f.ok === false && f.params && f.params.pair) {
    // a door line of no partnership (a server set up anew, its flash kept): out of authorized_keys on this click only
    const b = el('button', 'btn small', T('partner.line_remove'));
    b.type = 'button';
    b.title = T('partner.line_remove_title', { pair: f.params.pair });
    b.disabled = !Office.agent.running;
    b.onclick = () => lineRemove(f.params.pair, b);
    acts.appendChild(b);
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

async function sharesMake(names, b) {
  b.disabled = true;
  b.classList.add('running');
  b.prepend(el('span', 'spin'));
  await Office.freshState(ID);
  const j = await Office.api.post(`${ID}.shares_make`, { shares: names });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    await load(true);       // as things are now: the card shows what is still missing
    return;
  }
  state = j.state;
  if (view) renderKeeping(['advice', 'done']);
  mood();
  Office.toast(T('shares_made', { n: j.made.length, shares: j.made.join(', ') }));
}

async function lineRemove(pair, b) {
  b.disabled = true;
  b.classList.add('running');
  b.prepend(el('span', 'spin'));
  await Office.freshState(ID);
  const j = await Office.api.post(`${ID}.partner_line_remove`, { pair });
  if (!j.ok) {
    Office.toast(Office.errorText(j.error, ID), true);
    await load(true);       // as things are now: the line may be gone or belong to a pairing meanwhile
    return;
  }
  state = j.state;
  if (view) renderKeeping(['advice', 'done']);
  mood();
  Office.toast(T('partner.line_removed', { pair: j.pair }));
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

// ------------------------------------------------------------------ the notification centre
/*
 * The office's one place for what goes to Unraid's notifications (agent/lib/notifycenter.php; its part «notify»,
 * loaded with his page) — three tiles, each its section: the centre itself (per colleague a threshold, «successes
 * too», right away or in a summary at a time; quiet hours, «silent until …», the sign of life, a test), what waits for
 * a summary (and how it would read), the history. Every list has its bar (core.js Office.filterBar). Alerts always go
 * right away — the page says so, no control can stop them. The tips' numbers come from the agent's constants (limits).
 */
let nc = null;            // the part (officeNotifyPublic())
let ncDraft = null;       // the settings as edited here, not saved yet (null: as saved)
let ncOpen = '';          // '' | 'test' | 'silent' — the choice row under the head
const ncBars = {};        // the lists' bars (core.js Office.filterBar, built once per visit; their extras never kept)
const ncF = { when: '', fate: '', time: '' };     // the bars' own extra selects: filters of this visit, never kept
/** An element with its children: texts and nodes (core.js el() takes one text) */
const ncEl = (tag, cls, ...kids) => {
  const n = el(tag, cls);
  kids.forEach((k) => { if (k !== null && k !== undefined && k !== false) n.append(k.nodeType ? k : String(k)); });
  return n;
};

async function loadNotify(fresh) {
  return Office.loadState(ID, { part: 'notify', fresh }, tookNotify);
}
function tookNotify(j) {
  const p = j && j.ok ? j.part : null;
  if (!p || !p.settings) return;
  nc = p;
  if (view) Office.keepInPlace(null, render);
}
const ncSaved = () => (nc ? nc.settings : null);
const ncSet = () => ncDraft || ncSaved();
const ncDirty = () => !!ncDraft && JSON.stringify(ncDraft) !== JSON.stringify(ncSaved());
/** A change to the draft (a copy of what is saved, the first time) — then the section anew, in place */
function ncEdit(anchor, change) {
  if (!ncDraft) ncDraft = JSON.parse(JSON.stringify(ncSaved()));
  change(ncDraft);
  Office.keepInPlace(anchor, render);
}
const ncName = (desk) => (desk === 'office' ? Office.t('notifycenter.office') : Office.desks.has(desk) ? Office.t(desk + '.name') : desk);
/** Who of the senders works here: the hired ones, and the office itself */
const ncDesks = () => (nc ? nc.desks.filter((d) => d === 'office' || (Office.desks.has(d) && Office.desks.get(d).hired)) : []);
/** The tips' numbers, from the agent's constants */
const ncNums = () => {
  const l = (nc && nc.limits) || {};
  return { desk: l.desk, office: l.office, alerts: l.alerts, minutes: Math.round((l.window || 0) / 60), bundle: Math.round((l.bundle || 0) / 60),
    hours: Math.round((l.forget || 0) / 3600) };
};
const ncTip = (node, key) => { node.title = T(key, ncNums()); return node; };
/** A select of the bar's own (beside its words): its value in ncF, said in the note line, never kept */
function ncPick(name, label, options, draw) {
  const sel = el('select', 'picker');
  sel.setAttribute('aria-label', label);
  sel.dataset.keep = '1';
  for (const [v, txt] of options) {
    const o = el('option', '', txt);
    o.value = v;
    sel.appendChild(o);
  }
  sel.value = ncF[name];
  sel.onchange = () => { ncF[name] = sel.value; Office.keepInPlace(sel, draw); };
  return sel;
}
function ncBar(list) {
  if (ncBars[list]) return ncBars[list];
  const draw = () => sw.render();
  // the centre's lists are short, but each always has its whole bar — words and selects (owner, 2026-10-20)
  const o = { key: `caretaker.nc_${list}`, label: T(`nc.f_${list}`), onChange: draw, min: 0 };
  if (list === 'who') {
    o.select = { label: T('nc.level_head'), all: T('nc.f_level_all') };
    o.extra = [ncPick('when', T('nc.when_head'), [['', T('nc.f_when_all')], ['now', T('nc.when.now')], ['summary', T('nc.f_when_summary')]], draw)];
  } else {
    o.select = { label: T('nc.f_who_label'), all: T('nc.f_who_all') };
    if (list === 'log') {
      o.extra = [ncPick('fate', T('nc.f_fate_label'), [['', T('nc.f_fate_all')], ...['sent', 'held', 'collected', 'counted', 'dropped', 'failed'].map((f) => [f, T('nc.fate.' + f)])], draw),
        ncPick('time', T('nc.f_time_label'), [['', T('nc.f_time_all')], ['day', T('nc.f_time.day')], ['week', T('nc.f_time.week')]], draw)];
    }
  }
  ncBars[list] = Office.filterBar(o);
  return ncBars[list];
}
/** After a list's rows: «n of m fit» and the note line, with the bar's own selects as its parts (× takes one away) */
function ncDone(bar, n, total, names) {
  const sels = Array.from(bar.el.querySelectorAll('select.picker:not(.filter-pick)'));
  const parts = names.filter((k) => ncF[k]).map((k) => {
    const sel = sels.find((s) => s.getAttribute('aria-label') === { when: T('nc.when_head'), fate: T('nc.f_fate_label'), time: T('nc.f_time_label') }[k]);
    const text = sel && sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : ncF[k];
    return [text, () => { ncF[k] = ''; if (sel) sel.value = ''; }];
  });
  bar.done(n, total, { parts, clear: (what) => { if (what === 'all') names.forEach((k) => { ncF[k] = ''; sels.forEach((s) => { if (!s.classList.contains('filter-pick')) s.value = ''; }); }); } });
}
const ncTime = (t) => fmt.time(t);
const ncDay = (t) => fmt.date(t);

// ---- the tiles
/** «Notification centre»: the last that went — red when it didn't arrive; silent, collecting */
function notifyTile(a) {
  const n = state.notify || {};
  const c = n.center || {};
  const last = c.last || null;
  const failed = !!(last && last.sent === false);
  const sub = [!last ? T('tile.notify_none') : T(failed ? 'tile.notify_failed' : 'tile.notify_last', { when: fmt.relative(last.time) })];
  if (c.silent_until) sub.push(T('nc.tile_silent', { when: fmt.relative(c.silent_until) }));
  if (c.collecting) sub.push(T('nc.tile_collecting', { time: ncTime(c.collecting) }));
  return Office.stat({ label: T('tile.notify'), value: fmt.number(c.day ? c.day.sent : 0), sub, alert: failed || n.available === false, ...a });
}
/** «Waiting for the summary»: how many, since when collecting */
function notifyWaitTile(a) {
  const c = (state.notify || {}).center || {};
  return Office.stat({ label: T('tile.notify_wait'), value: fmt.number(c.waiting || 0),
    sub: c.collecting ? T('nc.tile_collecting', { time: ncTime(c.collecting) }) : c.waiting ? T('nc.tile_waiting', { n: c.waiting }) : T('nc.waiting_none'), ...a });
}
/** «History»: what the last day did — sent, collected or not sent, didn't arrive */
function notifyLogTile(a) {
  const d = ((state.notify || {}).center || {}).day || { sent: 0, held: 0, failed: 0 };
  const sub = [T('nc.tile_day', { sent: T('nc.count.sent', { n: d.sent }), held: T('nc.count.held', { n: d.held }) })];
  if (d.failed) sub.push(T('nc.count.failed', { n: d.failed }));
  return Office.stat({ label: T('tile.notify_log'), value: fmt.number(d.sent + d.held), sub, alert: d.failed > 0, ...a });
}

// ---- the centre itself
function notifySection() {
  const s = el('section', 'section');
  s.dataset.ct = 'notify';
  const busy = !Office.agent.running;
  const test = el('button', 'btn small plain', T('nc.test'));
  test.type = 'button';
  test.disabled = busy || !nc;
  test.setAttribute('aria-expanded', String(ncOpen === 'test'));
  test.onclick = () => { ncOpen = ncOpen === 'test' ? '' : 'test'; Office.keepInPlace(test, render); };
  const silentOn = !!(nc && nc.settings.silent_until && nc.settings.silent_until > Date.now() / 1000);
  const silent = el('button', 'btn small plain', T(silentOn ? 'nc.silent_end' : 'nc.silent'));
  silent.type = 'button';
  silent.disabled = busy || !nc;
  ncTip(silent, 'nc.tip.silent');
  silent.onclick = () => (silentOn ? ncSilent(silent, 0) : (ncOpen = ncOpen === 'silent' ? '' : 'silent', Office.keepInPlace(silent, render)));
  s.appendChild(Office.sectionHead(T('notify_title'), T('notify_sub'), ncEl('span', 'ct-acts', test, silent), { place: 'notify_title' }));
  if (!nc) {
    s.appendChild(el('p', 'empty', Office.t('common.loading')));
    return s;
  }
  s.appendChild(ncEl('p', 'ct-nc-rules', ncTip(el('span', 'chip ok', T('nc.chip.alerts')), 'nc.tip.alerts'), ' ',
    ncTip(el('span', 'chip', T('nc.chip.flood')), 'nc.tip.flood')));
  if (nc.available === false) s.appendChild(el('p', 'role', T('notify_missing')));
  if (silentOn) s.appendChild(el('p', 'callout warn ct-nc-silent', T('nc.silent_on', { when: ncDay(nc.settings.silent_until) })));
  if (ncOpen === 'test') s.appendChild(ncTestRow());
  if (ncOpen === 'silent') s.appendChild(ncSilentRow());
  s.appendChild(ncWhoBox());
  s.appendChild(ncCommonBox());
  return s;
}

/** The test: one notification at a level, past the settings — then where Unraid sends that level */
function ncTestRow() {
  const r = ncEl('div', 'ct-subhead ct-nc-choice', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.test_pick'))));
  for (const level of ['normal', 'warning', 'alert']) {
    const b = el('button', 'btn small', T('nc.test_as.' + level));
    b.type = 'button';
    b.disabled = !Office.agent.running;
    b.onclick = async () => {
      b.disabled = true;
      b.appendChild(el('span', 'spin'));
      const j = await Office.api.post(`${ID}.notify_test`, { level });
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); b.disabled = false; return; }
      const ch = j.channels || {};
      const where = ['browser', 'mail', 'agents'].filter((k) => ch[k]).map((k) => T('nc.ch.' + k));
      Office.toast(j.sent ? T('nc.test_sent', { level: T('nc.test_as.' + level), where: where.length ? where.join(', ') : T('nc.ch_none') }) : T('nc.test_failed'), !j.sent);
      if (j.part) nc = j.part;
      ncOpen = '';
      Office.keepInPlace(null, render);
    };
    r.appendChild(b);
  }
  return r;
}

/** «Silent until …»: a few times to pick, or a day and hour of one's own */
function ncSilentRow() {
  const r = ncEl('div', 'ct-subhead ct-nc-choice', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.silent_pick')), el('span', '', T('nc.silent_note'))));
  const now = new Date();
  const morning = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 8, 0, 0);
  for (const [key, until] of [['nc.silent_hours', Date.now() + 3 * 3600e3], ['nc.silent_morning', morning.getTime()], ['nc.silent_week', Date.now() + 7 * 86400e3]]) {
    const b = el('button', 'btn small plain', T(key));
    b.type = 'button';
    b.onclick = () => ncSilent(b, Math.floor(until / 1000));
    r.appendChild(b);
  }
  const own = el('input', 'input small');
  own.type = 'datetime-local';
  own.setAttribute('aria-label', T('nc.silent_own'));
  const go = el('button', 'btn small', T('nc.silent_go'));
  go.type = 'button';
  go.onclick = () => {
    const t = own.value ? new Date(own.value).getTime() : NaN;
    if (!Number.isFinite(t) || t <= Date.now()) { Office.toast(T('nc.silent_bad'), true); return; }
    ncSilent(go, Math.floor(t / 1000));
  };
  r.append(own, go);
  return r;
}
async function ncSilent(btn, until) {
  btn.disabled = true;
  const j = await Office.api.post(`${ID}.notify_silent`, { until });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); btn.disabled = false; return; }
  if (j.state) state = j.state;
  if (j.part) nc = j.part;
  ncOpen = '';
  Office.keepInPlace(null, render);
  Office.toast(until ? T('nc.silent_done') : T('nc.silent_ended'));
}

/** A select of the office's (input small), its options [[value, text]] */
function ncSelect(label, value, options, onChange) {
  const sel = el('select', 'input small');
  sel.setAttribute('aria-label', label);
  for (const [v, txt] of options) {
    const o = el('option', '', txt);
    o.value = v;
    sel.appendChild(o);
  }
  sel.value = value;
  sel.disabled = !Office.agent.running;
  sel.onchange = () => onChange(sel.value, sel);
  return sel;
}
function ncClock(label, value, onChange) {
  const i = el('input', 'input small ct-nc-clock');
  i.type = 'time';
  i.value = value;
  i.setAttribute('aria-label', label);
  i.disabled = !Office.agent.running;
  i.onchange = () => { if (/^([01]\d|2[0-3]):[0-5]\d$/.test(i.value)) onChange(i.value, i); };
  return ncTip(i, 'nc.tip.at');
}
/** An empty slot of a colleague's row: the control it stands for, invisible, out of reach (no tab, no title, no click) */
function ncSlot(node) {
  node.classList.add('ct-nc-slot');
  node.removeAttribute('title');
  node.setAttribute('aria-hidden', 'true');
  node.querySelectorAll('input').forEach((i) => { i.disabled = true; i.tabIndex = -1; });
  if (node.matches('input')) { node.disabled = true; node.tabIndex = -1; }
  return node;
}
const ncLevelText = (id, level) => T(level === 'alert' && !(nc.sends[id] || []).includes('alert') ? 'nc.level.off' : 'nc.level.' + level);

/** «Who reports what»: a row per colleague — threshold, successes, right away or in the summary at a time; its bar */
function ncWhoBox() {
  const set = ncSet();
  const box = el('div', 'box ct-group');
  box.appendChild(Office.place('nc.who', ncEl('div', 'ct-subhead', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.who')), el('span', '', T('nc.who_sub'))))));
  const bar = ncBar('who');
  const ids = ncDesks();
  bar.options(['all', 'warning', 'alert'].map((l) => [l, T('nc.level.' + l), ids.filter((id) => (set.desks[id] || {}).level === l).length]));
  box.appendChild(bar.el);
  const f = bar.read();
  const fits = ids.filter((id) => {
    const d = set.desks[id] || { level: 'all', when: 'now' };
    return (!f.pick || d.level === f.pick) && (!ncF.when || d.when === ncF.when)
      && Office.fits(f.words, [ncName(id), ...(nc.sends[id] || []).map((l) => T('nc.send.' + l)), ncLevelText(id, d.level), T('nc.when.' + d.when)]);
  });
  for (const id of fits) {
    const d = set.desks[id] || { level: 'all', success: true, when: 'now', at: null };
    const sends = nc.sends[id] || [];
    const r = el('div', 'row nocheck ct-nc-row');
    r.dataset.id = id;
    const main = el('div', 'row-main');
    main.appendChild(id === 'office' ? el('span', 'chip', T('nc.office_row')) : deskChip(id));
    main.appendChild(ncEl('div', 'row-meta', el('span', '', T('nc.sends', { what: sends.map((l) => T('nc.send.' + l)).join(', ') }))));
    const col = (nc.collecting || {})[id];
    if (col) main.appendChild(ncTip(el('div', 'row-detail', T('nc.collecting', { time: ncTime(col.since), n: col.n })), 'nc.tip.flood'));
    // four slots in every row — «successes too», threshold, when, time — an empty one held by an invisible stand-in of
    // the same control, so the selects line up in one column whether a colleague has successes or a summary time
    const ctl = el('div', 'ct-nc-ctl ct-nc-set');
    const lab = el('label', 'check');
    const cb = el('input');
    cb.type = 'checkbox';
    lab.append(cb, el('span', '', T('nc.success')));
    if ((nc.success || []).includes(id)) {
      cb.checked = d.success !== false;
      cb.disabled = !Office.agent.running || d.level !== 'all';
      cb.onchange = () => ncEdit(cb, (x) => { x.desks[id].success = cb.checked; });
      ctl.appendChild(ncTip(lab, 'nc.tip.success'));
    } else ctl.appendChild(ncSlot(lab));
    ctl.appendChild(ncTip(ncSelect(T('nc.level_label', { name: ncName(id) }), d.level, ['all', 'warning', 'alert'].map((l) => [l, ncLevelText(id, l)]),
      (v, sel) => ncEdit(sel, (x) => { x.desks[id].level = v; })), 'nc.tip.level'));
    const quiet = d.level === 'alert';
    const when = ncSelect(T('nc.when_label', { name: ncName(id) }), d.when, [['now', T('nc.when.now')], ['summary', T('nc.when.summary')]],
      (v, sel) => ncEdit(sel, (x) => { x.desks[id].when = v; }));
    when.disabled = when.disabled || quiet;
    ctl.appendChild(ncTip(when, 'nc.tip.when'));
    const clock = ncClock(T('nc.at_label', { name: ncName(id) }), d.at || set.at, (v, i) => ncEdit(i, (x) => { x.desks[id].at = v; }));
    ctl.appendChild(d.when === 'summary' && !quiet ? clock : ncSlot(clock));
    r.append(main, ctl);
    box.appendChild(r);
  }
  ncDone(bar, fits.length, ids.length, ['when']);
  return box;
}

/** «For everyone»: the summary's time (and everyone into it), quiet hours, the sign of life — and saving */
function ncCommonBox() {
  const set = ncSet();
  const box = el('div', 'box ct-group');
  box.appendChild(Office.place('nc.common', ncEl('div', 'ct-subhead', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.common')), el('span', '', T('nc.common_sub'))))));
  const all = el('button', 'btn small plain', T('nc.all_summary'));
  all.type = 'button';
  all.disabled = !Office.agent.running;
  all.onclick = () => ncEdit(all, (x) => { for (const id of ncDesks()) if (x.desks[id].level !== 'alert') { x.desks[id].when = 'summary'; x.desks[id].at = null; } });
  const r1 = ncEl('div', 'row nocheck ct-nc-row', ncEl('div', 'row-main', el('div', 'row-name text', T('nc.at')), el('div', 'row-detail', T('nc.at_sub'))),
    ncEl('div', 'ct-nc-ctl', ncClock(T('nc.at'), set.at, (v, i) => ncEdit(i, (x) => { x.at = v; })), all));
  const ql = el('label', 'check');
  const qc = el('input');
  qc.type = 'checkbox';
  qc.checked = !!set.quiet.on;
  qc.disabled = !Office.agent.running;
  qc.onchange = () => ncEdit(qc, (x) => { x.quiet.on = qc.checked; });
  ql.append(qc, el('span', '', T('nc.quiet')));
  ncTip(ql, 'nc.tip.quiet');
  const ctl2 = el('div', 'ct-nc-ctl');
  if (set.quiet.on) {
    ctl2.append(el('span', 'ct-nc-word', T('nc.quiet_from')), ncTip(ncClock(T('nc.quiet_from'), set.quiet.from, (v, i) => ncEdit(i, (x) => { x.quiet.from = v; })), 'nc.tip.quiet'),
      el('span', 'ct-nc-word', T('nc.quiet_to')), ncTip(ncClock(T('nc.quiet_to'), set.quiet.to, (v, i) => ncEdit(i, (x) => { x.quiet.to = v; })), 'nc.tip.quiet'));
  }
  const r2 = ncEl('div', 'row nocheck ct-nc-row', ncEl('div', 'row-main', ql, el('div', 'row-detail', T('nc.quiet_sub'))), ctl2);
  const wl = el('label', 'check');
  const wc = el('input');
  wc.type = 'checkbox';
  wc.checked = !!set.all_well;
  wc.disabled = !Office.agent.running;
  wc.onchange = () => ncEdit(wc, (x) => { x.all_well = wc.checked; });
  wl.append(wc, el('span', '', T('nc.all_well')));
  const r3 = ncEl('div', 'row nocheck ct-nc-row', ncEl('div', 'row-main', wl, el('div', 'row-detail', T('nc.all_well_sub'))));
  box.append(r1, r2, r3);
  if (ncDirty()) {
    const save = el('button', 'btn small', T('nc.save'));
    save.type = 'button';
    save.disabled = !Office.agent.running;
    save.onclick = () => ncSave(save);
    const undo = el('button', 'btn small plain', T('nc.undo'));
    undo.type = 'button';
    undo.onclick = () => { ncDraft = null; Office.keepInPlace(undo, render); };
    box.appendChild(ncEl('div', 'ct-subhead ct-nc-save', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.unsaved'))), save, undo));
  }
  return box;
}
async function ncSave(btn) {
  btn.disabled = true;
  btn.appendChild(el('span', 'spin'));
  const x = ncDraft;
  const desks = {};
  for (const [id, d] of Object.entries(x.desks)) {
    if (!nc.desks.includes(id)) continue;
    desks[id] = { level: d.level, success: d.success !== false, when: d.when, at: d.at || null };
  }
  const j = await Office.api.post(`${ID}.notify_save`, { desks, at: x.at, quiet: { on: !!x.quiet.on, from: x.quiet.from, to: x.quiet.to }, all_well: !!x.all_well });
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); btn.disabled = false; return; }
  if (j.state) state = j.state;
  if (j.part) nc = j.part;
  ncDraft = null;
  Office.keepInPlace(null, render);
  Office.toast(T('nc.saved'));
}

/** Why something waits or was not sent — a chip with its tip (the guard's numbers in it) */
function ncWhyChip(e) {
  const chip = el('span', `chip ${e.fate === 'sent' ? 'ok' : e.fate === 'failed' ? 'danger' : e.fate === 'dropped' ? '' : 'warn'}`, T('nc.fate.' + e.fate));
  // an alert counted as «the same» was the very same alert within a minute (the alerts' tip says so), never the flood rule
  if (e.why === 'alert_cap' || (e.why === 'same' && e.level === 'alert')) return ncTip(chip, 'nc.tip.alerts');
  if (e.why === 'flood' || e.why === 'same') return ncTip(chip, 'nc.tip.flood');
  chip.title = e.why && Office.has(`${ID}.nc.why.${e.why}`) ? T('nc.why.' + e.why) : T('nc.fate_tip.' + e.fate);
  return chip;
}
/** The bar's «who» select: the colleagues a list names, each with how many */
const ncWhoOptions = (list) => [...new Set(list.map((e) => e.desk))].map((d) => [d, ncName(d), list.filter((e) => e.desk === d).length]);

// ---- what waits for a summary
function notifyWaitSection() {
  const s = el('section', 'section');
  s.dataset.ct = 'notify_wait';
  const n = nc ? nc.waiting.length : 0;
  s.appendChild(Office.sectionHead(T('nc.wait_head'), nc && n && nc.next ? T('nc.next', { time: fmt.relative(Math.max(nc.next, nc.time)) }) : T('nc.waiting_sub'),
    { place: 'nc.wait_head' }));
  if (!nc) { s.appendChild(el('p', 'empty', Office.t('common.loading'))); return s; }
  const bar = ncBar('wait');
  bar.options(ncWhoOptions(nc.waiting));
  const box = el('div', 'box');
  box.appendChild(bar.el);
  const f = bar.read();
  const fits = nc.waiting.filter((e) => (!f.pick || e.desk === f.pick) && Office.fits(f.words, [e.subject, e.description, ncName(e.desk), T('nc.fate.' + e.fate)]));
  if (!n) box.appendChild(ncEl('div', 'row nocheck', ncEl('div', 'row-main', el('div', 'row-detail', T('nc.waiting_none')))));
  for (const e of fits) {
    const meta = ncEl('div', 'row-meta', el('span', '', fmt.relative(e.time)), el('span', '', ncName(e.desk)), ncWhyChip(e),
      e.due ? el('span', '', T('nc.goes', { when: fmt.relative(e.due) })) : null);
    box.appendChild(ncEl('div', 'row nocheck ct-nc-log', ncEl('div', 'row-main', el('div', 'row-name text', e.subject), meta,
      e.description ? el('div', 'row-detail', e.description) : null)));
  }
  ncDone(bar, fits.length, n, []);
  s.appendChild(box);
  if (nc.preview) {
    s.appendChild(ncEl('div', 'box ct-group', ncEl('div', 'ct-subhead', ncEl('span', 'ct-subhead-words', el('strong', '', T('nc.preview')), el('span', '', T('nc.preview_sub')))),
      ncEl('div', 'row nocheck', ncEl('div', 'row-main', el('div', 'row-name text', nc.preview.subject), el('div', 'row-detail', nc.preview.description),
        el('pre', 'code ct-nc-preview', nc.preview.message)))));
  }
  return s;
}

// ---- the history
function notifyLogSection() {
  const s = el('section', 'section');
  s.dataset.ct = 'notify_log';
  s.appendChild(Office.sectionHead(T('nc.log_title'), T('nc.log_sub'), { place: 'nc.log_title' }));
  if (!nc) { s.appendChild(el('p', 'empty', Office.t('common.loading'))); return s; }
  const all = nc.log || [];
  const bar = ncBar('log');
  bar.options(ncWhoOptions(all));
  const box = el('div', 'box');
  box.appendChild(bar.el);
  const f = bar.read();
  const since = ncF.time === 'day' ? nc.time - 86400 : ncF.time === 'week' ? nc.time - 7 * 86400 : 0;
  const fits = all.filter((e) => (!f.pick || e.desk === f.pick) && (!ncF.fate || e.fate === ncF.fate) && e.time >= since
    && Office.fits(f.words, [e.subject, e.description, ncName(e.desk), T('nc.fate.' + e.fate)]));
  if (!all.length) box.appendChild(ncEl('div', 'row nocheck', ncEl('div', 'row-main', el('div', 'row-detail', T('nc.log_none')))));
  for (const e of fits.slice(0, 200)) {
    const when = el('span', '', fmt.relative(e.time));
    when.title = ncDay(e.time);
    box.appendChild(ncEl('div', 'row nocheck ct-nc-log', ncEl('div', 'row-main', el('div', 'row-name text', e.subject),
      ncEl('div', 'row-meta', when, el('span', '', ncName(e.desk)), ncWhyChip(e)), e.description ? el('div', 'row-detail', e.description) : null)));
  }
  ncDone(bar, fits.length, all.length, ['fate', 'time']);
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
  const open = all.filter((s) => !s.hired && s.ok && !needsAway(s));
  const extra = [];
  const sup = Office.supporter();     // also his one ask (under the list)
  if (sup.state === 'valid') {
    // the supporter keys' plate: a thank-you, not a button (a green outline), as tall as the buttons beside it; the
    // name (the newest key's) as text only; the pictures each kept level's in level order with ×n when several
    // (☕×3 ☕☕ 🍰 🥂, signed in the keys — only the owner sees it, never a rank)
    const level = Office.supporterPictures(sup);
    const MARK = '\u0001';
    const plate = el('span', 'chip ok ct-supporter');
    // its words by the highest level and how many keys of it (2026-10-09: «Prost!», «jam-jam», «schon der 3. Kaffee»)
    const top = level.top;
    const many = level.count(top);
    const variant = top === 'coffee' ? (many >= 3 ? 'coffee_many' : many === 2 ? 'coffee_two' : '') : `${top}_${many >= 2 ? 'more' : 'one'}`;
    // several names on the keys: all of them — «Benj & Janine», from three on «Benj & 2 weitere» (2026-10-09)
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
  // who works here, a hired desk the messenger doesn't know right after them, then who could come
  const missing = missingDesks().map((d) => ({ id: d.id, desk: d, hired: true, ok: true, why: 'yes', missing: true }));
  [...all.filter((x) => x.hired), ...missing, ...all.filter((x) => !x.hired)].forEach((x) => box.appendChild(teamRow(x)));
  // under contract but still learning (desk.json "training"): shown, not hired yet
  [...Office.desks.values()].filter((d) => d.training).forEach((d) => box.appendChild(trainingRow(d)));
  s.appendChild(box);
  if (sup.ask) s.appendChild(askCallout());          // his one ask: under «The team»
  return s;
}

/** Desks hired here that the messenger doesn't know (no agent part: an update half done) — they can't do anything */
function missingDesks() {
  const known = (state && state.staff) || {};
  return [...Office.desks.values()].filter((d) => d.hired && !d.always && !d.training && !known[d.id]);
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
  main.appendChild(el('div', 'row-detail', Office.t(`${d.id}.training`)));    // what she will do, «hireable soon»
  r.appendChild(main);
  r.appendChild(el('span'));     // nothing to click: no hire button, no tip (the tip jar is in the head)
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
  if (x.missing) meta.appendChild(el('span', 'chip danger', T('agent_missing')));
  else meta.appendChild(el('span', 'chip ' + (x.hired ? 'ok' : x.ok ? 'accent' : 'quiet'), T(x.hired ? 'in_team' : x.ok ? 'could_come' : 'declined')));
  meta.appendChild(el('span', '', Office.t(`${x.id}.role`)));
  main.appendChild(meta);
  if (x.missing) main.appendChild(el('div', 'row-detail', T('agent_missing_text')));
  else if (!x.hired || x.why !== 'yes') main.appendChild(el('div', 'row-detail', fitText(x)));     // once hired, "would like to come" is old news
  if (!x.hired && x.ok && needsAway(x)) main.appendChild(el('div', 'row-detail', T('needs_away', { name: Office.t(`${x.id}.name`), with: nameWith(x.desk.needs) })));
  r.appendChild(main);
  let b = null;
  if (x.hired) {
    b = el('button', 'btn small plain', T('fire'));
    b.onclick = () => fire(x.id);
  } else if (x.ok && !needsAway(x)) {
    // a desk that asks for a colleague (desk.json "with"): both at once while the colleague isn't here and would come —
    // the colleague first, so he stands first in the team; hiring him alone stays possible. One that needs her
    // ("needs": Jack Emby only under Ms. Moverelli) comes only with her or after her (src/staff.php refuses otherwise)
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
/** A candidate who needs a colleague (desk.json "needs") that is neither hired nor able to come: no «Hire» */
function needsAway(x) {
  const id = x.desk && x.desk.needs;
  if (!id) return false;
  const w = staff().find((s) => s.id === id);
  return !w || (!w.hired && !(w.ok && !w.desk.training));
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

/**
 * «In place»: what is fine — folded — and under it what the user put aside with «I know, thanks» (folded too, each with
 * «Bring back»); the bar over his lists stands here when this section is chosen
 */
function doneSection() {
  const { g, fit, filtering } = lists();
  const s = el('section', 'section');
  s.dataset.ct = 'done';
  const toggle = el('button', 'btn small plain', showDone ? T('hide') : T('show'));
  toggle.type = 'button';
  toggle.onclick = () => { showDone = !showDone; Office.keepInPlace(null, render); };
  s.appendChild(Office.sectionHead(T('done', { n: g.done.length }), T('done_sub'), toggle, { place: 'done' }));
  if (sw.active() === 'done') s.appendChild(bar().el);
  if (showDone) {
    const box = el('div', 'box');
    if (!g.done.length) box.appendChild(el('p', 'empty', T('done_none')));
    else if (filtering && !fit.done.length) box.appendChild(noFit());
    fit.done.forEach((f) => {
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
  if (g.noted.length) {
    const more = el('button', 'btn small plain', showNoted ? T('hide') : T('show'));
    more.type = 'button';
    more.onclick = () => { showNoted = !showNoted; Office.keepInPlace(null, render); };
    const box = list('noted', T('noted', { n: g.noted.length }), T('noted_sub'), showNoted ? fit.noted : null, more);
    Office.place('noted', box.firstChild);
    s.appendChild(box);
  }
  return s;
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  /** sub: a tile (#/caretaker/partner — a partner's finding, the night watchman's door tips; the others the search's) */
  async mount(root, sub) {
    view = root;
    bar().reset();        // a new visit: every point (the bar's words are never kept)
    Object.values(ncBars).forEach((b) => b.reset());
    ncF.when = ncF.fate = ncF.time = '';
    ncDraft = null;
    ncOpen = '';
    sw.mount(sub);
    render();
    loadNotify(false);    // the notification centre's part (beside his state, not after it)
    await load(false);
  },
  unmount() { view = null; },
  poll() { load(false); if (!ncDirty()) loadNotify(false); },
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

// his places for the search (core.js «places and the search»; places.json beside desk.json lists the same keys): each
// section opens through its tile's sub-route
Office.places(ID, [
  ...sw.places(),
  ...[['team', 'team'], ['office', 'office'], ['todo_count', 'todo'], ['notify_title', 'notify'], ['partner.title', 'partner'], ['done', 'done']]
    .map(([key, tile]) => ({ kind: 'section', key, route: `#/caretaker/${tile}` })),
  { kind: 'section', key: 'noted', route: '#/caretaker/done', shown: () => !state || groups().noted.length > 0 },     // drawn only with something put aside
  ...['nc.who', 'nc.common'].map((key) => ({ kind: 'section', key, route: '#/caretaker/notify' })),
  { kind: 'section', key: 'nc.wait_head', route: '#/caretaker/notify_wait' },
  { kind: 'section', key: 'nc.log_title', route: '#/caretaker/notify_log' },
  ...[['todo', 'help.todo'], ['advice', 'help.advice'], ['hints', 'help.hints'], ['ack', 'help.ack'], ['noted_term', 'help.noted'],
    ['missing', 'help.missing'], ['not_yet', 'help.not_yet'], ['unknown', 'help.unknown'], ['team', 'help.team'],
    ['supporter_term', 'help.supporter'], ['help.open', 'help.open_text'], ['check_again', 'help.again'], ['notify_title', 'help.notify'],
    ['help.parity_term', 'help.parity'], ['partner.title', 'partner.help']].map(([key, text]) => ({ kind: 'help', key, text })),
]);

// what his state holds for the search (core.js «items»): the points still open (as his lists word them), who could work
// here, the partner offices and the tickets (partner.js marks their cards) — each through its tile's sub-route
const LISTS = { required: 'todo', recommended: 'advice', hint: 'hints' };
Office.placesFrom(ID, (s) => {
  const out = [];
  for (const [desk, list] of Object.entries(s.checks || {})) {
    if (!Office.desks.has(desk) || !Office.desks.get(desk).hired || !Array.isArray(list)) continue;
    list.forEach((f) => {
      if (!f || !LISTS[f.level] || f.ok === true || !f.sig || (f.acked && ackable(f))) return;
      out.push({ text: text({ ...f, desk }), sub: `${T(f.level !== 'hint' && f.ok !== false ? 'unknown' : LISTS[f.level])} · ${Office.t(desk + '.name')}`, route: '#/caretaker/todo', anchor: `finding:${f.sig}` });
    });
  }
  for (const [id, x] of Object.entries(s.staff || {})) {
    const d = Office.desks.get(id);
    if (!d || !x) continue;
    out.push({ text: Office.t(`${id}.name`), sub: `${T('team')} · ${T(d.hired ? 'in_team' : x.ok ? 'could_come' : 'declined')}`, route: '#/caretaker/team', anchor: `staff:${id}` });
  }
  const p = s.partners || {};
  // a pair's card says which way the copies go: two pairs with the same partner (one each way) told apart by its words
  const way = (x) => [x.sends ? T('partner.i_send', { host: Office.config.host, name: x.name }) : '', x.receive ? T('partner.i_keep', { host: Office.config.host, name: x.name }) : '']
    .filter(Boolean).map((w) => w.replace(/[\s:：]+$/, '')).join(' · ');
  const route = '#/caretaker/partner';
  (p.pairs || []).forEach((x) => out.push({ text: x.name, sub: [T('partner.title'), way(x)].filter(Boolean).join(' · '), route, anchor: `partner:${x.id}` }));
  (p.ticket_pairs || []).forEach((t) => out.push({ text: T('partner.t_card', { name: t.name, of: t.of }), sub: T('partner.title'), route, anchor: `ticket:${t.id}` }));
  (p.ticket_requests || []).forEach((q) => out.push({ text: T('partner.t_request', { id: q.id }), sub: T('partner.title'), route, anchor: `ticket:${q.id}` }));
  return out;
});

// tests/run.php draws a candidate's row under node (the «Hire together with …» button)
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.caretaker = { setState: (s) => { state = s; }, staff, teamRow };
}
})();
