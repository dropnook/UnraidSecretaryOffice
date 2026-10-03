/* John — the intern and Emby fan. For now he only introduces himself:
   EmbyCache (github.com/helmi1987/embycache-for-unraid) keeps what people are
   about to watch on the fast pool, so the array disks can sleep. The agent
   part lives in agent/desks/emby.php. */
(() => {
'use strict';

const ID = 'emby';
const T = Office.scope(ID);
const { el } = Office;

let state = null;
let view = null;

async function load() {
  const j = await Office.api.get({ a: 'state', desk: ID });
  if (j.ok && j.state) state = j.state;
  if (view) render();
}

function render() {
  const root = view;
  root.innerHTML = '';
  const { head } = Office.deskHead(Office.desks.get(ID), { bubble: T('bubble.hello') });
  root.appendChild(head);

  const plan = el('section', 'section');
  plan.appendChild(Office.sectionHead(T('soon'), T('soon_sub')));
  const ul = el('ul', 'facts');
  ['cache', 'deck', 'sleep', 'setup', 'updates'].forEach((k) => ul.appendChild(el('li', '', T('soon.' + k))));
  const box = el('div', 'box');
  box.style.padding = '12px 16px';
  box.appendChild(ul);
  plan.appendChild(box);
  root.appendChild(plan);

  const facts = el('section', 'section');
  facts.appendChild(Office.sectionHead(T('seen'), T('seen_sub')));
  const stats = el('div', 'stats');
  const stat = (label, value, sub) => {
    const s = el('div', 'stat');
    s.append(el('div', 'stat-label', label), el('div', 'stat-value', value));
    if (sub) s.appendChild(el('div', 'stat-sub', sub));
    stats.appendChild(s);
  };
  if (state) {
    stat('Emby', state.emby.length ? state.emby.map((e) => e.name).join(', ') : T('none'),
      state.emby.length ? (state.emby.every((e) => e.running) ? T('running') : T('not_running')) : T('no_emby'));
    stat('Python', state.python || T('none'), state.python ? T('python_ok') : T('python_missing'));
  }
  facts.appendChild(stats);
  root.appendChild(facts);
}

Office.desk({
  id: ID,
  mount(root) { view = root; render(); load(); },
  unmount() { view = null; },
  poll() { load(); },
  async reception() {
    if (!state) await load();
    return { bubble: T('bubble.hello_short'), facts: [T('fact.soon')] };
  },
});
})();
