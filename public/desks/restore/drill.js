/* Mr. Restori's drill — the section «Drill» of his page (loaded by desk.js, which hands it its helpers):
   now and then he proves, on throwaway objects only, that what Mr. Backupsy keeps really comes back, and writes a
   certificate per app and VM — which level (L0 there · L1 readable and complete · L2 restorable), from which copy
   (the package, a local snapshot, Kopia), when, and what didn't work and why. «Practise now…» shows a preview first;
   the drill runs as a job whose journal this page follows. The agent part lives in agent/desks/restore-drill.php. */
(() => {
'use strict';

const ID = 'restore';
const POLL = 2000;
const KINDS = ['package', 'dump', 'sqlite', 'vmdisk', 'kopia'];
const COPIES = { package: 'package', snapshot: 'local', kopia: 'kopia' };
const BYTES = ['need', 'budget', 'have', 'want', 'bytes', 'sample_bytes'];
const TIMES = ['time', 'dump_time', 'when'];

let x = null;              // desk.js's helpers (init)
let cert = null;           // data/restore-drill.json (api part "drill"): read straight from disk, also for the tile
let dstate = null;         // restore.drill_state: settings, his drills, the one going on
let job = null;            // data/restore-drill-job.json (api part "drill-job") while a drill runs
let timer = null;
let loading = false;
const opened = new Map();  // drill id -> {journal, log, error, loading}

const T = (k, p) => x.T(k, p);

// ------------------------------------------------------------------ loading
async function loadCert() {
  const j = await Office.api.get({ a: 'part', desk: ID, part: 'drill' });
  if (j.ok) cert = j.part && j.part.interface === 1 ? j.part : null;
}

async function loadState(id) {
  if (!Office.agent.running) return null;
  const j = await Office.api.post(`${ID}.drill_state`, id ? { id } : {});
  if (j.ok && !id) {
    dstate = j;
    if (j.certificate) cert = j.certificate;
    if (j.running) job = j.running;
  }
  return j;
}

/** The section opened (or the page drawn with it open): the certificate, then his look (settings, drills) */
async function refresh() {
  if (loading) return;
  loading = true;
  try {
    await loadCert();
    await loadState();
  } finally {
    loading = false;
  }
  x.redraw();
  if (live()) poll();
}

const live = () => !!(job && ['queued', 'running'].includes(job.result));

async function poll() {
  clearTimeout(timer);
  timer = null;
  const was = live();
  const j = await Office.api.get({ a: 'part', desk: ID, part: 'drill-job' });
  if (j.ok && j.part) job = j.part;
  if (!x.shown()) return;
  if (live()) {
    x.redraw();
    timer = setTimeout(poll, POLL);
  } else if (was) {
    Office.toast(T('drill.done.' + (job.result === 'passed' ? 'passed' : job.result === 'failed' ? 'failed' : 'other'), { result: T('drill.result.' + job.result) }),
      job.result !== 'passed');
    opened.delete(job.id);
    await refresh();
  }
}

// ------------------------------------------------------------------ words
/** Params made readable: sizes, dates */
function nice(params) {
  const out = {};
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v === null || v === undefined || typeof v === 'object') out[k] = '';
    else if (BYTES.includes(k) && typeof v === 'number') out[k] = x.fmt.size(v);
    else if (TIMES.includes(k) && typeof v === 'number') out[k] = x.fmt.date(v);
    else out[k] = v;
  });
  return out;
}

/** What a code says (its params made readable) */
function codeText(code, params) {
  const key = 'drill.code.' + (code || 'drill_error');
  return Office.has(`${ID}.${key}`) ? T(key, nice(params)) : code;
}

const resultCls = (r) => ({ ok: 'ok', warning: 'warn', failed: 'danger', not_checked: 'quiet', asleep: 'quiet', skipped: 'quiet', running: 'accent', pending: 'quiet',
  passed: 'ok', aborted: 'warn', refused: 'warn', interrupted: 'danger', queued: 'quiet' }[r] || '');

function levelChip(it) {
  const copy = COPIES[it.copy] || 'package';
  return x.chip(T('drill.level', { level: it.level, copy: T('drill.copy.' + copy) }), it.result === 'failed' ? 'danger' : it.level >= 2 ? 'ok' : it.level >= 1 ? 'accent' : 'quiet',
    T('drill.level_hint.' + Math.max(0, Math.min(2, it.level))) + ' ' + T('drill.copy_hint.' + copy));
}

// ------------------------------------------------------------------ the tile
function tileLine() {
  if (live()) {
    const steps = job.steps || [];
    const at = steps.filter((s) => !['pending', 'running'].includes(s.state)).length;
    return [T('drill.tile_running'), T('drill.tile_step', { n: Math.min(at + 1, steps.length), total: steps.length })];
  }
  if (!cert || !cert.last) return [T('drill.tile_none'), ''];
  const l = cert.last;
  return [T(l.result === 'passed' ? 'drill.tile_passed' : 'drill.tile_failed', { n: l.proven || 0, failed: l.failed || 0 }), x.fmt.relative(l.ended)];
}

// ------------------------------------------------------------------ the section
function section() {
  const actions = [];
  const go = x.button(T('drill.practise'), 'small', practiseDialog);
  go.disabled = !Office.agent.running || live();
  const set = x.button(T('drill.settings'), 'small plain', settingsDialog);
  set.disabled = !Office.agent.running || !dstate;
  actions.push(set, go);
  const s = x.sectionBox(T('drill.title'), T('drill.sub'), ...actions);
  if (!dstate && !cert) {
    s.appendChild(el('p', 'empty', Office.agent.running ? Office.t('common.loading') : T('drill.no_agent')));
    if (!loading && Office.agent.running) refresh();
    return s;
  }
  if (live()) s.appendChild(runningBox());
  s.appendChild(headline());
  if (cert && cert.items && cert.items.length) {
    s.appendChild(problems());
    s.appendChild(rows());
  }
  s.appendChild(history());
  return s;
}

const el = (...a) => Office.el(...a);

/** The drill going on: its step, what it does, a bar */
function runningBox() {
  const steps = job.steps || [];
  const at = steps.findIndex((st) => st.state === 'running');
  const done = steps.filter((st) => !['pending', 'running'].includes(st.state)).length;
  const box = el('div', 'callout running rs-dr-run');
  const cur = at >= 0 ? steps[at] : null;
  box.append(el('span', 'spin'), ' ', T('drill.running', { n: done + (cur ? 1 : 0), total: steps.length }));
  if (cur) box.append(' — ', T('drill.kind.' + cur.do), ': ', stepName(cur));
  const bar = el('div', 'bar thin rs-bar');
  const i = el('i', 'data');
  i.style.width = (steps.length ? Math.floor(100 * done / steps.length) : 0) + '%';
  bar.appendChild(i);
  box.appendChild(bar);
  if (job.deadline) box.appendChild(el('div', 'role', T('drill.until', { time: x.fmt.time(job.deadline) })));
  return box;
}

function stepName(st) {
  const what = st.do === 'dump' ? st.container : st.do === 'vmdisk' ? `${st.target || ''} ${(st.source || '').split('/').pop()}`
    : st.do === 'kopia' ? st.source : st.do === 'sqlite' ? (st.file || '').split('/').pop() : '';
  return what && what !== st.name ? `${st.name} · ${what}` : st.name;
}

/** The last drill in one line, how the next one comes, what was downloaded */
function headline() {
  const box = el('div', 'rs-dr-head');
  const sched = (dstate && dstate.settings && dstate.settings.schedule) || 'monthly';
  if (!cert || !cert.last) {
    box.appendChild(el('p', 'callout', T('drill.none') + ' ' + T('drill.sched.' + sched)));
    return box;
  }
  const l = cert.last;
  const parts = [T('drill.counts', { n: l.proven || 0 })];
  if (l.warnings) parts.push(T('drill.counts_warn', { n: l.warnings }));
  if (l.failed) parts.push(T('drill.counts_failed', { n: l.failed }));
  if (l.not_checked) parts.push(T('drill.counts_unchecked', { n: l.not_checked }));
  if (l.asleep) parts.push(T('drill.counts_asleep', { n: l.asleep }));
  const p = el('p', 'callout' + (l.result === 'failed' ? ' warn' : ''));
  p.append(x.chip(T('drill.result.' + l.result), resultCls(l.result)), ' ',
    T('drill.last', { when: x.date(l.ended), took: x.fmt.duration(Math.max(60, (l.ended || 0) - (l.started || 0))) }), ' ', parts.join(' · '));
  box.appendChild(p);
  const facts = [];
  if (l.egress) facts.push(T('drill.egress', { size: x.fmt.size(l.egress) }));
  if (cert.last_passed && l.result !== 'passed') facts.push(T('drill.last_passed', { when: x.date(cert.last_passed) }));
  facts.push(T('drill.sched.' + sched));
  box.appendChild(el('p', 'role', facts.join(' ')));
  return box;
}

/** What failed or wants a look first — each with what to do and where */
function problems() {
  const bad = cert.items.filter((it) => ['failed', 'warning'].includes(it.result));
  const box = el('div');
  if (!bad.length) return box;
  const list = el('ul', 'rs-pv-list rs-dr-bad');
  bad.sort((a, b) => (a.result === 'failed' ? 0 : 1) - (b.result === 'failed' ? 0 : 1)).slice(0, 20).forEach((it) => {
    const li = el('li');
    li.append(x.chip(T('drill.state.' + it.result), resultCls(it.result)), ' ', el('strong', '', it.name), ' · ', T('drill.kind.' + it.kind), ': ', codeText(it.code, it.params));
    const go = whereToFix(it);
    if (go) li.append(' ', go);
    list.appendChild(li);
  });
  box.appendChild(list);
  return box;
}

/** A link to exactly where it is fixed */
function whereToFix(it) {
  const link = (text, hash) => Object.assign(el('a', '', text), { href: hash });
  if (['image_missing'].includes(it.code)) return link(T('drill.fix.docker'), '/Docker');
  if (it.kind === 'kopia' || it.code.startsWith('dump_') || it.code.startsWith('package_') || it.code === 'manifest_unreadable') return link(T('drill.fix.backup'), '#/backup');
  return null;
}

/** Per app and VM: the levels proven, «what you would lose», how long the database took to come back; unfolded: every item */
function rows() {
  const box = el('div', 'box rs-dr-rows');
  const by = new Map();
  cert.items.forEach((it) => {
    const key = ['app', 'vm'].includes(it.of) ? `${it.of}:${it.id}` : it.of === 'share' ? 'share' : 'server';
    if (!by.has(key)) by.set(key, []);
    by.get(key).push(it);
  });
  const lose = new Map((cert.lose || []).map((l) => [`${l.kind}:${l.id}`, l]));
  const keys = [...by.keys()].sort((a, b) => order(a) - order(b) || a.localeCompare(b));
  keys.forEach((key) => {
    const items = by.get(key);
    const name = key === 'share' ? T('drill.shares') : key === 'server' ? T('drill.server') : items[0].name;
    const meta = el('div', 'row-meta');
    const worst = ['failed', 'warning', 'not_checked', 'asleep'].find((r) => items.some((it) => it.result === r)) || 'ok';
    meta.appendChild(x.chip(T('drill.state.' + worst), resultCls(worst)));
    const best = new Map();
    items.filter((it) => ['ok', 'warning'].includes(it.result)).forEach((it) => {
      const c = COPIES[it.copy] || 'package';
      if (!best.has(c) || best.get(c).level < it.level) best.set(c, it);
    });
    [...best.values()].forEach((it) => meta.appendChild(levelChip(it)));
    const l = lose.get(key);
    if (l && (l.local || l.kopia)) {
      meta.appendChild(el('span', '', T(l.kopia ? 'drill.lose_both' : 'drill.lose_local', { local: l.local ? x.date(l.local) : '–', kopia: l.kopia ? x.date(l.kopia) : '' })));
    }
    if (l && l.played) meta.appendChild(el('span', 'role', T('drill.played', { time: x.fmt.duration(Math.max(60, l.played)) })));
    box.appendChild(x.unfoldingRow('dr:' + key, name, meta, () => itemsDetail(items)));
  });
  return box;
}

const order = (key) => (key.startsWith('app:') ? 0 : key.startsWith('vm:') ? 1 : key === 'server' ? 2 : 3);

function itemsDetail(items) {
  const ul = el('ul', 'rs-pv-list rs-dr-items');
  items.forEach((it) => {
    const li = el('li');
    li.append(x.chip(T('drill.state.' + it.result), resultCls(it.result)), ' ', T('drill.kind.' + it.kind));
    if (it.what && it.what !== it.name) li.append(' ', el('code', '', it.what));
    li.append(' · ', codeText(it.code, it.params));
    if (['ok', 'warning'].includes(it.result)) li.append(' ', levelChip(it));
    if (it.state_time) li.append(' · ', T('drill.state_of', { when: x.date(it.state_time) }));
    ul.appendChild(li);
  });
  return ul;
}

/** His last drills, each with its journal on a click */
function history() {
  const list = (dstate && dstate.drills) || [];
  const wrap = el('div', 'rs-dr-hist');
  wrap.appendChild(el('div', 'rs-part-title', T('drill.history')));
  if (!list.length) { wrap.appendChild(el('p', 'role', T('drill.history_none'))); return wrap; }
  const box = el('div', 'box');
  list.forEach((d) => {
    const meta = el('div', 'row-meta');
    const r = job && job.id === d.id ? job.result : d.result;
    meta.appendChild(x.chip(T('drill.result.' + r), resultCls(r), d.reason ? reasonText(d.reason) : null));
    meta.appendChild(el('span', '', x.date(d.started || d.created)));
    meta.appendChild(el('span', '', T('drill.scope.' + (['monthly', 'weekly', 'now'].includes(d.scope) ? d.scope : 'now'))));
    const c = d.counts || {};
    meta.appendChild(el('span', 'role', T('drill.counts', { n: c.ok || 0 }) + (c.failed ? ' · ' + T('drill.counts_failed', { n: c.failed }) : '')));
    box.appendChild(x.unfoldingRow('drj:' + d.id, T('drill.journal_name', { when: x.date(d.started || d.created) }), meta, () => journalDetail(d)));
  });
  wrap.appendChild(box);
  return wrap;
}

/** Why a drill ended early or didn't start: a stop of its own (deadline, array, from outside) or a refusal */
function reasonText(key) {
  return ['deadline', 'stopped', 'array_stopping', 'budget', 'drill_error'].includes(key) ? codeText(key, {}) : Office.errorText({ key, params: {} }, ID);
}

function journalDetail(d) {
  const box = el('div');
  const have = opened.get(d.id);
  const fill = (j, log) => {
    box.innerHTML = '';
    if (d.reason) box.appendChild(el('p', 'callout warn', reasonText(d.reason)));
    const ol = el('ol', 'rs-pv-steps rs-j-steps');
    (j.steps || []).forEach((st) => {
      const li = el('li', 'rs-j-' + (st.state || 'pending'));
      li.append(x.chip(T('drill.state.' + (st.state || 'pending')), resultCls(st.state || 'pending')), ' ', T('drill.kind.' + st.do), ': ', stepName(st));
      if (st.code && !['pending', 'running'].includes(st.state)) li.appendChild(el('div', 'role', codeText(st.code, st.params)));
      ol.appendChild(li);
    });
    box.appendChild(ol);
    if (log && log.length) box.appendChild(x.fold(T('j.log'), el('pre', 'code rs-j-log', log.join('\n'))));
  };
  if (have && have.journal) fill(have.journal, have.log);
  else if (have && have.error) box.appendChild(el('p', 'callout warn', Office.errorText(have.error, ID)));
  else {
    box.appendChild(el('p', 'role', Office.t('common.loading')));
    if (!have) {
      opened.set(d.id, { loading: true });
      loadState(d.id).then((j) => {
        opened.set(d.id, j && j.ok ? { journal: j.journal, log: j.log } : { error: (j && j.error) || { key: 'agent_away' } });
        if (box.isConnected) {
          const o = opened.get(d.id);
          if (o.journal) fill(o.journal, o.log);
          else { box.innerHTML = ''; box.appendChild(el('p', 'callout warn', Office.errorText(o.error, ID))); }
        }
      });
    }
  }
  return box;
}

// ------------------------------------------------------------------ «Practise now…»
async function practiseDialog() {
  const body = el('div', 'rs-dlg');
  const pv = el('div');
  const ok = el('label', 'check rs-confirm');
  const okBox = el('input');
  okBox.type = 'checkbox';
  ok.append(okBox, el('span', '', T('drill.confirm')));
  body.append(pv, ok);
  pv.appendChild(el('p', 'role', T('drill.loading_plan')));
  let plan = null;
  const d = Office.dialog({
    title: T('drill.practise_title'),
    body,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('drill.go'), kind: '', act: start }],
  });
  const go = d.buttons[1];
  const update = () => { go.disabled = !(plan && !(plan.blockers || []).length && okBox.checked); };
  okBox.onchange = update;
  update();
  const j = await Office.api.post(`${ID}.drill_plan`, {});
  if (!Office.dialogOpen()) return;
  pv.innerHTML = '';
  if (!j.ok) { pv.appendChild(el('p', 'callout warn', Office.errorText(j.error, ID))); return; }
  plan = j.preview;
  pv.appendChild(previewView(plan));
  update();

  async function start() {
    const r = await Office.api.post(`${ID}.drill_start`, { stamp: plan.stamp, token: plan.token });
    if (!r.ok) {
      Office.toast(Office.errorText(r.error, ID), true);
      return false;
    }
    if (r.state) { dstate = r.state; if (r.state.running) job = r.state.running; }
    Office.toast(T('drill.started'));
    x.redraw();
    poll();
    return true;
  }
}

/** What a drill would do: per kind how many, how long, RAM and download budgets, what sleeps, what it reads of the live apps */
function previewView(p) {
  const box = el('div', 'rs-preview');
  (p.blockers || []).forEach((b) => box.appendChild(el('p', 'callout warn', Office.errorText({ key: b.key, params: nice(b.params) }, ID))));
  box.appendChild(el('p', 'role', T('drill.pv_intro')));
  const ul = el('ul', 'rs-pv-list');
  KINDS.forEach((k) => {
    const n = (p.counts || {})[k] || 0;
    if (n) ul.appendChild(el('li', '', T('drill.pv.' + k, { n })));
  });
  box.appendChild(ul);
  box.appendChild(el('p', 'role', T('drill.pv_time', { time: x.fmt.duration(Math.max(60, p.estimate || 0)), until: x.fmt.time(p.deadline) })
    + (p.next_backup ? ' ' + T('drill.pv_next', { when: x.date(p.next_backup) }) : '')));
  box.appendChild(el('p', 'role', T('drill.pv_ram', { size: x.fmt.size(p.ram || 0) })));
  if ((p.too_big || []).length) box.appendChild(el('p', 'callout', T('drill.pv_too_big', { names: p.too_big.map((t) => t.name).join(', ') })));
  if (p.kopia) box.appendChild(el('p', 'role', p.kopia.running ? T('drill.pv_kopia', { size: x.fmt.size((p.kopia_mb || 0) * 1048576), name: p.kopia.container || 'kopia' })
    : T('drill.pv_kopia_off', { name: p.kopia.container || 'kopia' })));
  if ((p.asleep || []).length) box.appendChild(el('p', 'callout', T('drill.pv_asleep', { names: p.asleep.join(', ') })));
  if ((p.live || []).length) {
    box.appendChild(x.listBlock(T('drill.pv_live'), p.live.map((l) => T('drill.pv_live_' + l.what, { name: l.name }))));
  }
  box.appendChild(el('p', 'role', T('drill.pv_safe')));
  return box;
}

// ------------------------------------------------------------------ settings
function settingsDialog() {
  const s = { ...(dstate.settings || {}) };
  const body = el('div', 'rs-opts');
  const way = el('div', 'field');
  way.appendChild(el('div', 'field-title', T('drill.set_schedule')));
  ['monthly', 'weekly', 'off'].forEach((v) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'rs-dr-sched';
    r.checked = s.schedule === v;
    r.onchange = () => { s.schedule = v; };
    const text = el('span', '', T('drill.set.' + v));
    text.appendChild(el('small', '', T('drill.set.' + v + '_hint')));
    l.append(r, text);
    way.appendChild(l);
  });
  body.appendChild(way);
  const f = el('div', 'field');
  const lab = el('label', '', T('drill.set_kopia'));
  const inp = el('input', 'input');
  inp.type = 'number';
  inp.min = '0';
  inp.max = '102400';
  inp.step = '128';
  inp.id = 'rs-dr-kopia';
  lab.htmlFor = inp.id;
  inp.value = String(s.kopia_mb ?? 1024);
  f.append(lab, inp, el('small', 'role', T('drill.set_kopia_hint')));
  body.appendChild(f);
  ['live_catalog', 'live_sqlite'].forEach((k) => {
    const l = el('label', 'check');
    const c = el('input');
    c.type = 'checkbox';
    c.checked = !!s[k];
    c.onchange = () => { s[k] = c.checked; };
    const text = el('span', '', T('drill.set.' + k));
    text.appendChild(el('small', '', T('drill.set.' + k + '_hint')));
    l.append(c, text);
    body.appendChild(l);
  });
  Office.dialog({
    title: T('drill.settings_title'),
    body,
    buttons: [{ text: Office.t('common.cancel') }, {
      text: T('drill.save'),
      kind: '',
      act: async () => {
        const mb = parseInt(inp.value, 10);
        const j = await Office.api.post(`${ID}.drill_set`, { schedule: s.schedule, kopia_mb: Number.isFinite(mb) ? Math.max(0, mb) : 1024,
          live_catalog: !!s.live_catalog, live_sqlite: !!s.live_sqlite });
        if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
        dstate = j;
        Office.toast(T('drill.saved'));
        x.redraw();
        return true;
      },
    }],
  });
}

// ------------------------------------------------------------------ for desk.js
Office.restoreDrill = {
  /** desk.js's helpers: T, fmt, date, chip, button, sectionBox, unfoldingRow, fold, listBlock, redraw(), shown() */
  init(helpers) {
    x = helpers;
    loadCert().then(() => x.tiles());
  },
  tileLine: () => (x ? tileLine() : ['', '']),
  section,
  refresh,
  /** an app's row on his page: «proven …» or «not proven» */
  appChip(kind, id) {
    if (!cert || !cert.items) return null;
    const items = cert.items.filter((it) => it.of === kind && it.id === id);
    if (!items.length) return null;
    const bad = items.some((it) => it.result === 'failed');
    return x.chip(bad ? T('drill.chip_failed') : T('drill.chip_proven', { when: x.fmt.relative(cert.last.ended) }), bad ? 'danger' : 'ok', T('drill.chip_hint'));
  },
  stop() { clearTimeout(timer); timer = null; },
};
if (globalThis.OFFICE_DESK_TESTS) {
  globalThis.OFFICE_DESK_TESTS.drill = { nice, codeText, reasonText, setCert: (c) => { cert = c; }, setJob: (j) => { job = j; }, tileLine };
}
})();
