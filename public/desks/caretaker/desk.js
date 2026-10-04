/* The Caretaker — looks after the house: collects what every desk needs from
   the server (plugins, containers, settings), adds what the office as a whole
   benefits from, and says what is missing and what is left to do by hand.
   Every desk delivers its own checks (agent: desk(..., ['checks' => …]));
   their texts live in that desk's language file as check.<id> and
   check.<id>_how. The agent part lives in agent/desks/caretaker.php. */
(() => {
'use strict';

const ID = 'caretaker';
const T = Office.scope(ID);
const { el, fmt } = Office;
const LINKS = { plugins: '/Plugins', apps: '/Apps', docker: '/Docker', userscripts: '/Settings/Userscripts', notifications: '/Settings/Notifications', settings: '/Settings' };

let state = null;
let view = null;
let showDone = false;

async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view) render();
  mood();
  return j;
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

function groups() {
  const all = findings();
  return {
    todo: all.filter((f) => f.level === 'required' && f.ok !== true),
    advice: all.filter((f) => f.level === 'recommended' && f.ok !== true),
    hints: all.filter((f) => f.level === 'hint' && f.ok !== true),
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
  return out.sort((a, b) => (a.desk.order ?? 0) - (b.desk.order ?? 0));
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
  if (!g.todo.length && !g.advice.length) return T('bubble.all_good');
  const parts = [];
  if (g.todo.length) parts.push(T('bubble.todo', { n: g.todo.length }));
  if (g.advice.length) parts.push(T('bubble.advice', { n: g.advice.length }));
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
    [el('span', 'chip danger', T('missing')), T('help.missing')],
    [el('span', 'chip warn', T('not_yet')), T('help.not_yet')],
    [el('span', 'chip warn', T('unknown')), T('help.unknown')],
    [T('team'), T('help.team')],
    [deskChip(Office.desks.has('backup') ? 'backup' : ID), T('help.desk_text')],      // a real one, e.g. «💾 Herr Backupsi»
    [T('help.open'), T('help.open_text')],
    [T('check_again'), T('help.again')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }

  const g = groups();
  root.appendChild(teamSection());                  // who works here comes first
  root.appendChild(officeSection());
  if (g.todo.length) root.appendChild(list(T('todo'), T('todo_text'), g.todo));
  if (g.advice.length) root.appendChild(list(T('advice'), T('advice_text'), g.advice));
  if (!g.todo.length && !g.advice.length) {
    const ok = el('div', 'box');
    const p = el('div', 'empty');
    p.append(el('strong', '', T('all_good_title')), T('all_good_text'));
    ok.appendChild(p);
    root.appendChild(ok);
  }
  if (g.hints.length) root.appendChild(list(T('hints'), T('hints_text'), g.hints));
  root.appendChild(doneSection(g.done));
  root.appendChild(el('p', 'role', T('checked_at', { when: fmt.relative(state.time) })));
}

function list(title, sub, items) {
  const s = el('section', 'section');
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
  const r = el('div', 'row nocheck');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', text(f)));
  const meta = el('div', 'row-meta');
  if (f.level !== 'hint') {
    if (f.ok === null) meta.appendChild(el('span', 'chip warn', T('unknown')));
    else meta.appendChild(el('span', 'chip ' + (f.level === 'required' ? 'danger' : 'warn'), T(f.level === 'required' ? 'missing' : 'not_yet')));
  }
  meta.appendChild(deskChip(f.desk));
  main.appendChild(meta);
  const how = text(f, '_how');
  if (how) main.appendChild(el('div', 'row-detail', how));
  r.appendChild(main);
  if (f.link && f.link.startsWith('#/')) {
    // a page of the office itself, e.g. Mr. Backupsy's setup
    const a = el('a', 'btn small plain', T('open.office'));
    a.href = f.link;
    r.appendChild(a);
  } else if (f.link && state.gui && LINKS[f.link]) {
    const a = el('a', 'btn small plain', T('open.' + f.link));
    a.href = state.gui + LINKS[f.link];
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    r.appendChild(a);
  }
  return r;
}

// ------------------------------------------------------------------ the office itself
function officeSection() {
  const o = state.office || {};
  const s = el('section', 'section');
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
  s.appendChild(Office.sectionHead(T('office'), T('office_sub'), again));
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
  let note = '';
  if (o.newer && o.plugin) note = T('office_how_plugin');
  else if (o.newer && !o.git) note = T('office_how_manual');
  else if (o.newer && o.changed) note = T('office_how_changed');
  if (note) main.appendChild(el('div', 'row-detail', note));
  row.appendChild(main);
  const right = el('div', 'ct-office-actions');
  if (o.newer && o.url) {
    const a = el('a', 'btn small plain', T('office_whats_new'));
    a.href = o.url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    right.appendChild(a);
  }
  if (o.newer && o.plugin) {
    const a = el('a', 'btn small', T('office_to_plugins'));
    a.href = '/Plugins';
    right.appendChild(a);
  }
  if (o.newer && o.git && !o.changed) {
    const b = el('button', 'btn small', T('office_update'));
    b.type = 'button';
    b.disabled = !Office.agent.running;
    b.onclick = () => officeUpdate(o);
    right.appendChild(b);
  }
  row.appendChild(right);
  box.appendChild(row);
  s.appendChild(box);
  return s;
}

function officeUpdate(o) {
  Office.dialog({
    title: T('office_update_title', { version: o.latest }),
    body: el('p', '', T('office_update_text')),
    buttons: [
      { text: Office.t('common.cancel') },
      { text: T('office_update'), kind: '', act: async () => {
        const j = await Office.api.post(`${ID}.office_update`, {});
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        if (j.compose) Office.dialog({ title: T('office_compose_title'), body: el('p', '', T('office_compose_text')) });
        else Office.toast(T('office_updated'));
        setTimeout(() => location.reload(), j.compose ? 15000 : 2500);      // the new page code
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ the team
function teamSection() {
  const all = staff();
  const open = all.filter((s) => !s.hired && s.ok);
  const extra = [];
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
  s.appendChild(Office.sectionHead(T('team'), T(alone() ? 'team_sub_alone' : 'team_sub'), ...extra));
  const box = el('div', 'box');
  all.forEach((x) => box.appendChild(teamRow(x)));
  s.appendChild(box);
  return s;
}

function teamRow(x) {
  const r = el('div', 'row nocheck ct-person' + (x.hired ? '' : ' ct-candidate'));
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
    b = el('button', 'btn small', T('hire'));
    b.onclick = () => hire([x.id]);
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
  const toggle = el('button', 'btn small plain', showDone ? T('hide') : T('show'));
  toggle.type = 'button';
  toggle.onclick = () => { showDone = !showDone; Office.keepInPlace(null, render); };
  s.appendChild(Office.sectionHead(T('done', { n: items.length }), T('done_sub'), toggle));
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
})();
