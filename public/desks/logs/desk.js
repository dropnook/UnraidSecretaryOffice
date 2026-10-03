/* Ms. Protocolli — reads every log out loud, understands none of it. Pick a
   source (the office's own, Unraid's, User Scripts, containers), how many
   lines, follow it live like tail -f, filter by text or "errors and warnings
   only", copy or download what is shown. The agent part lives in
   agent/desks/logs.php; lines always go into the page as text, never as HTML. */
(() => {
'use strict';

const ID = 'logs';
const T = Office.scope(ID);
const { el, fmt } = Office;
const FOLLOW_MS = 2000;
const LINE_CHOICES = [100, 500, 2000, 10000];
const GROUPS = ['office', 'unraid', 'userscripts', 'containers'];

let state = null;
let view = null;
let lines = [];                 // what was read, all of it (up to the line count)
let offset = null;              // follow position in a file
let timer = null;
let loading = false;
const opts = {
  source: Office.store('logs.source') || 'syslog',
  lines: Number(Office.store('logs.lines')) || 500,
  follow: Office.store('logs.follow') !== '0',
  wrap: Office.store('logs.wrap') === '1',
  only: Office.store('logs.only') === '1',
  query: '',
};

const ERROR = /\b(error|err|fail(ed|ure)?|fatal|panic|crit(ical)?|emerg|alert|segfault|denied|oops|call trace|i\/o error)\b/i;
const WARN = /\b(warn(ing)?|timeout|timed out|retry|retrying)\b/i;
const level = (line) => (ERROR.test(line) ? 'error' : WARN.test(line) ? 'warn' : '');

// ------------------------------------------------------------------ loading
async function load(fresh) {
  const j = await Office.api.get({ a: 'state', desk: ID, ...(fresh ? { fresh: 1 } : {}) });
  if (j.ok && j.state) state = j.state;
  if (view) { renderHead(); fillSources(); }
  return j;
}

function source() { return (state?.sources || []).find((s) => s.id === opts.source) || null; }

/** Read the source anew (all of it) or — following a file — only what was added */
async function read(fresh) {
  if (loading || !view) return;
  loading = true;
  const req = { source: opts.source, lines: opts.lines };
  if (!fresh && offset !== null) req.offset = offset;
  const j = await Office.api.post(`${ID}.read`, req);
  loading = false;
  if (!view) return;
  if (!j.ok) {
    lines = [];
    offset = null;
    view.status.textContent = Office.errorText(j.error, ID);
    renderLines(true);
    return;
  }
  const atBottom = view.out.scrollTop + view.out.clientHeight >= view.out.scrollHeight - 40;
  if (fresh || j.reset || j.follow === 'replace' || offset === null) lines = j.lines;
  else lines = lines.concat(j.lines).slice(-opts.lines);
  offset = j.follow === 'append' ? j.offset : null;
  view.meta = j;
  renderLines(fresh || atBottom);
}

function schedule() {
  clearTimeout(timer);
  if (!view || !opts.follow) return;
  timer = setTimeout(async () => {
    if (!document.hidden && !Office.dialogOpen()) await read(false);
    schedule();
  }, FOLLOW_MS);
}

// ------------------------------------------------------------------ rendering
function build(root) {
  const v = {};
  const { head, bubble } = Office.deskHead(Office.desks.get(ID), { bubble: '' });
  v.bubble = bubble;
  root.appendChild(head);
  root.appendChild(Office.pageHelp(ID, [
    [T('help.source'), T('help.source_text')],
    [T('live'), T('help.live')],
    [T('only_problems'), T('help.only')],
    [el('span', 'lg-sample error', T('help.red')), T('help.colours')],
    [T('help.safe'), T('help.safe_text')],
  ]));

  const s = el('section', 'section');
  v.hint = el('span', 'hint');
  s.appendChild(Office.sectionHead(T('reading'), T('reading_sub'), v.hint));

  const bar = el('div', 'toolbar lg-bar');
  v.source = el('select', 'picker lg-source');
  v.source.setAttribute('aria-label', T('help.source'));
  v.source.onchange = () => { opts.source = v.source.value; Office.store('logs.source', opts.source); restart(); };
  v.lines = el('select', 'picker');
  v.lines.setAttribute('aria-label', T('lines_label'));
  LINE_CHOICES.forEach((n) => v.lines.appendChild(new Option(T('lines', { n }), String(n))));
  v.lines.value = String(opts.lines);
  v.lines.onchange = () => { opts.lines = Number(v.lines.value); Office.store('logs.lines', String(opts.lines)); restart(); };
  v.query = el('input', 'search');
  v.query.type = 'search';
  v.query.placeholder = T('filter');
  v.query.spellcheck = false;
  v.query.oninput = () => { opts.query = v.query.value; renderLines(false); };
  const sw = (key, text, onchange) => {
    const label = el('label', 'switch');
    const input = el('input');
    input.type = 'checkbox';
    input.checked = opts[key];
    input.onchange = () => { opts[key] = input.checked; Office.store('logs.' + key, input.checked ? '1' : '0'); onchange(); };
    label.append(input, el('span', '', text));
    return label;
  };
  v.followSw = sw('follow', T('live'), () => { if (opts.follow) read(false).then(schedule); else clearTimeout(timer); });
  const onlySw = sw('only', T('only_problems'), () => renderLines(false));
  const wrapSw = sw('wrap', T('wrap'), () => view.out.classList.toggle('wrap', opts.wrap));
  const copy = el('button', 'btn small plain', Office.t('common.copy'));
  copy.type = 'button';
  copy.onclick = () => Office.copy(shown().join('\n'));
  const save = el('button', 'btn small plain', T('download'));
  save.type = 'button';
  save.onclick = download;
  bar.append(v.source, v.lines, v.query, v.followSw, onlySw, wrapSw, copy, save);
  s.appendChild(bar);

  v.out = el('pre', 'code lg-out' + (opts.wrap ? ' wrap' : ''));
  v.out.setAttribute('tabindex', '0');
  v.status = el('p', 'role lg-status');
  s.append(v.out, v.status);
  root.appendChild(s);
  return v;
}

function renderHead() {
  if (!view) return;
  view.bubble.innerHTML = '';
  view.bubble.append(Office.withGreeting(ID, bubbleText()));
}

function bubbleText() {
  if (!state) return T('bubble.loading');
  const e = state.syslog?.errors || 0;
  const w = state.syslog?.warnings || 0;
  if (!e && !w) return T('bubble.calm');
  return T('bubble.counted', { errors: e, warnings: w });
}

/** The source list, grouped; a source that is gone falls back to the syslog */
function fillSources() {
  const sel = view.source;
  sel.innerHTML = '';
  const all = state?.sources || [];
  for (const g of GROUPS) {
    const items = all.filter((s) => s.group === g);
    if (!items.length) continue;
    const og = el('optgroup');
    og.label = T('group.' + g);
    items.forEach((s) => og.appendChild(new Option(sourceName(s), s.id)));
    sel.appendChild(og);
  }
  if (!all.some((s) => s.id === opts.source)) opts.source = all.some((s) => s.id === 'syslog') ? 'syslog' : (all[0]?.id || '');
  sel.value = opts.source;
}

function sourceName(s) {
  const name = T('source.' + s.label, { name: s.param });
  return s.size !== null && s.size !== undefined ? `${name} · ${fmt.size(s.size)}` : name;
}

function shown() {
  const q = opts.query.trim().toLowerCase();
  return lines.filter((l) => (!opts.only || level(l)) && (!q || l.toLowerCase().includes(q)));
}

/** Lines into the page — as text nodes, coloured by level, the filter word marked */
function renderLines(toBottom) {
  if (!view) return;
  const out = view.out;
  const list = shown();
  const q = opts.query.trim();
  const frag = document.createDocumentFragment();
  for (const line of list) {
    const row = el('span', 'lg-line ' + level(line));
    if (q) {
      const lower = line.toLowerCase();
      const ql = q.toLowerCase();
      let i = 0;
      for (let at = lower.indexOf(ql); at !== -1; at = lower.indexOf(ql, i)) {
        row.append(line.slice(i, at), el('mark', '', line.slice(at, at + q.length)));
        i = at + q.length;
      }
      row.append(line.slice(i));
    } else {
      row.textContent = line;
    }
    frag.append(row, '\n');
  }
  out.innerHTML = '';
  if (!list.length) out.appendChild(el('span', 'lg-empty', lines.length ? T('nothing_matches') : T('empty')));
  out.appendChild(frag);
  if (toBottom) out.scrollTop = out.scrollHeight;
  const src = source();
  const m = view.meta || {};
  const parts = [];
  if (src?.path) parts.push(src.path);
  if (m.size !== undefined && m.size !== null) parts.push(fmt.size(m.size));
  if (m.time) parts.push(T('changed', { when: fmt.relative(m.time) }));
  parts.push(list.length === lines.length ? T('count', { n: lines.length }) : T('count_filtered', { n: list.length, total: lines.length }));
  view.status.textContent = parts.join(' · ');
  const problems = lines.filter((l) => level(l) === 'error').length;
  view.hint.textContent = problems ? T('errors_seen', { n: problems }) : '';
}

function download() {
  const text = shown().join('\n') + '\n';
  const a = el('a');
  a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
  a.download = `${CONFIG_HOST()}-${opts.source.replace(/[^A-Za-z0-9._-]+/g, '_')}.log`;
  document.body.appendChild(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
const CONFIG_HOST = () => (Office.config && Office.config.host) || 'unraid';

function restart() {
  clearTimeout(timer);
  lines = [];
  offset = null;
  view.meta = null;
  view.out.textContent = Office.t('common.loading');
  read(true).then(schedule);
}

// ------------------------------------------------------------------ desk
Office.desk({
  id: ID,
  async mount(root) {
    view = build(root);
    renderHead();
    await load(false);
    restart();
  },
  unmount() { clearTimeout(timer); view = null; },
  poll() { load(false); },
  agentChanged() { if (view) renderHead(); },
  menu() {
    return [{ text: T('menu.reload'), act: () => { load(true); if (view) restart(); } }];
  },
  async reception() {
    if (!state) await load(false);
    const facts = [];
    if (state) {
      facts.push(T('fact.sources', { n: state.sources.length }));
      facts.push(T('fact.syslog', { errors: state.syslog?.errors || 0, warnings: state.syslog?.warnings || 0 }));
    }
    return { bubble: bubbleText(), facts };
  },
});
})();
