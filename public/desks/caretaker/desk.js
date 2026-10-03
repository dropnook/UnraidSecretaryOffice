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
  return j;
}

/** All findings, each with its desk */
function findings() {
  const out = [];
  if (!state || !state.checks) return out;
  for (const [desk, list] of Object.entries(state.checks)) {
    if (!Office.desks.has(desk)) continue;
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

function bubbleText() {
  if (!state) return T('bubble.loading');
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
    [T('help.desk'), T('help.desk_text')],
    [T('help.open'), T('help.open_text')],
    [T('check_again'), T('help.again')],
  ]));
  if (!state) { root.appendChild(el('p', 'empty', Office.t('common.loading'))); return; }

  const g = groups();
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
  return el('span', 'chip', `${d.icon} ${Office.t(desk + '.name')}`);
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
    // a page of the office itself, e.g. Mr. Backup's setup
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
  agentChanged() { if (view) render(); },
  async reception() {
    if (!state) await load(false);
    const g = groups();
    const facts = [];
    g.todo.slice(0, 3).forEach((f) => facts.push(text(f)));
    if (g.todo.length > 3) facts.push(T('fact.more', { n: g.todo.length - 3 }));
    if (!g.todo.length) facts.push(T('fact.done', { n: g.done.length }));
    return { bubble: bubbleText(), facts };
  },
});
})();
