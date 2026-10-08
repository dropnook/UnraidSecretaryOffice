/* The Team Lead's «Partner offices» (loaded by desk.js beside it): two offices pair up and keep copies of each
   other's ZFS snapshots (plan: briefs/uso-partner-zfs-plan.md). Pairing is two pastes and a code — «Add a partner…»
   makes BLOCK-A, the partner's «Accept a partner…» answers with BLOCK-B and the SAFETY CODE, «Paste the partner's
   answer» compares it. The cards come from his state (`partners`, agent/lib/partner.php partnerPublic()): never a
   key, never a block. The agent part: partner_add / accept / finish / end / ping in agent/desks/caretaker.php.
   What a pair covers changes without a new pairing (2.29): the sender's «Change what <host> sends…» (partner_change)
   asks through the door (`offer`), the receiver's card shows the wish with «Keep it too» / «No» (partner_wish). */
(() => {
'use strict';

const ID = 'caretaker';
const T = Office.scope(ID);
const { el, fmt } = Office;
const TRUST = ['mine', 'family', 'friend'];
const DEFAULT_RETENTION = '7 4 6';

let ctx = null;         // from desk.js: { state(), took(j), reload() }

const unitText = (u) => (u === 'place' ? T('partner.unit_place')
  : u.startsWith('share:') ? T('partner.unit_share', { name: u.slice(6) })
  : u.startsWith('vm:') ? T('partner.unit_vm', { name: u.slice(3) }) : u);
const unitsText = (list) => (list && list.length ? list.map(unitText).join(', ') : T('partner.nothing'));
const retentionText = (r) => {
  const [d, w, m] = String(r || DEFAULT_RETENTION).split(' ').map(Number);
  return T('partner.retention_text', { d, w, m });
};
const windowText = (w) => {
  const [from, to] = String(w || '').split('-');
  return from === to ? T('partner.window_always') : T('partner.window_text', { from, to });
};
const busyOff = () => !Office.agent.running;
/** A door's or ssh's reason in words (the code itself when no text knows it) */
const whyText = (w) => (Office.has(`${ID}.partner.why.${w}`) ? T('partner.why.' + w) : String(w || '?'));

/** The answer of an action: its partner cards into his state, or the error said */
function took(j) {
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  if (j.partners && ctx) ctx.took(j.partners);
  return true;
}

// ------------------------------------------------------------------ the section
function section(state) {
  const p = (state && state.partners) || { pairs: [], pending: [] };
  const held = p.ticket_pairs || [];
  const asked = p.ticket_requests || [];
  const s = el('section', 'section');
  s.dataset.ct = 'partners';
  const add = el('button', 'btn small', T('partner.add'));
  add.type = 'button';
  add.disabled = busyOff();
  add.onclick = () => addDialog();
  const accept = el('button', 'btn small plain', T('partner.accept'));
  accept.type = 'button';
  accept.disabled = busyOff();
  accept.onclick = () => acceptDialog();
  const start = el('button', 'btn small plain', T('partner.t_start'));
  start.type = 'button';
  start.disabled = busyOff();
  start.title = T('partner.t_start_title');
  start.onclick = () => ticketStartDialog();
  const extra = [start, accept, add];
  if (asked.length) {
    const tf = el('button', 'btn small plain', T('partner.t_finish'));
    tf.type = 'button';
    tf.disabled = busyOff();
    tf.onclick = () => ticketFinishDialog();
    extra.unshift(tf);
  }
  if (p.pending.length) {
    const fin = el('button', 'btn small plain', T('partner.finish'));
    fin.type = 'button';
    fin.disabled = busyOff();
    fin.onclick = () => finishDialog();
    extra.unshift(fin);
  }
  s.appendChild(Office.sectionHead(T('partner.title'), T('partner.sub'), ...extra));
  const box = el('div', 'box');
  if (!p.pairs.length && !p.pending.length && !held.length && !asked.length) {
    const e = el('div', 'empty');
    e.append(el('strong', '', T('partner.none_title')), T('partner.none_text'));
    box.appendChild(e);
  }
  p.pairs.forEach((x) => box.appendChild(card(x)));
  p.pending.forEach((o) => box.appendChild(pendingRow(o)));
  held.forEach((t) => box.appendChild(ticketCard(t)));
  asked.forEach((q) => box.appendChild(ticketRequestRow(q)));
  s.appendChild(box);
  return s;
}

function chip(cls, text, tip) {
  const c = el('span', 'chip ' + cls, text);
  if (tip) c.title = tip;
  return c;
}

/** How a partner is, in one chip */
function stateChip(x) {
  if (x.silent) return chip('danger', T('partner.silent'), T('partner.silent_tip', { since: x.last_heard ? fmt.date(x.last_heard) : T('partner.never') }));
  if (x.reachable === false) return chip('warn', T('partner.unreachable'), T('partner.why.' + (x.why || 'unreachable')));
  if (x.last_heard) return chip('ok', T('partner.answers'), T('partner.answers_tip', { when: fmt.date(x.last_heard) }));
  return chip('quiet', T('partner.not_yet_heard'), T('partner.not_yet_heard_tip'));
}

function quotaBar(used, total) {
  const wrap = el('div', 'ct-pq');
  const bar = el('div', 'ct-pq-bar');
  const fill = el('div', 'ct-pq-fill');
  const pct = total ? Math.min(100, Math.round(used / total * 100)) : 0;
  fill.style.width = pct + '%';
  if (pct >= 90) fill.classList.add('full');
  bar.appendChild(fill);
  wrap.append(bar, el('span', '', total ? T('partner.quota_used', { used: fmt.size(used), total: fmt.size(total), pct }) : T('partner.quota_none', { used: fmt.size(used || 0) })));
  return wrap;
}

function card(x) {
  const r = el('div', 'row nocheck ct-partner');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', '🏢 ' + x.name));
  const meta = el('div', 'row-meta');
  meta.appendChild(stateChip(x));
  if (x.array === 'stopped') meta.appendChild(chip('warn', x.night ? T('partner.night') : T('partner.array_stopped'), T('partner.array_stopped_tip')));
  meta.appendChild(chip('quiet', T('partner.trust.' + x.trust), T('partner.trust_tip.' + x.trust)));
  if (x.public) meta.appendChild(chip('warn', T('partner.public'), T('partner.public_tip')));
  if (x.door === 'closed' || x.door === 'changed') meta.appendChild(chip('danger', T('partner.door_' + x.door), T('partner.door_' + x.door + '_tip')));
  meta.appendChild(el('span', '', `${x.address}:${x.port}`));
  if (x.last_heard) meta.appendChild(el('span', '', T('partner.last_heard', { when: fmt.relative(x.last_heard) })));
  if (x.version) meta.appendChild(el('span', '', T('partner.version', { version: x.version })));
  main.appendChild(meta);

  // what I send, and what they keep of it
  const send = el('div', 'row-detail');
  send.appendChild(el('strong', '', T('partner.i_send', { host: Office.config.host }) + ' '));
  send.append(x.sends ? unitsText(x.send_units) : T('partner.i_send_nothing'));
  main.appendChild(send);
  if (x.sends) sendLines(x).forEach((n) => main.appendChild(n));
  const tk = x.they_keep;
  if (x.sends && tk) {
    const d = el('div', 'row-detail');
    const units = Object.entries(tk.units || {});
    d.append(units.length ? T('partner.they_keep', { list: units.map(([u, v]) => T('partner.snaps_of', { n: v.snaps, unit: unitText(u) })).join(', ') }) : T('partner.they_keep_none'));
    main.appendChild(d);
    if (tk.quota && (tk.quota.bytes || tk.quota.used_bytes)) main.appendChild(quotaBar(tk.quota.used_bytes || 0, tk.quota.bytes));
    if (tk.last_run && tk.last_run.time) main.appendChild(el('div', 'row-detail', T('partner.their_last_run', { when: fmt.relative(tk.last_run.time), result: tk.last_run.result || '?' })));
  }
  if (x.silent && x.tailnet !== null && x.tailnet !== undefined) main.appendChild(el('div', 'row-detail', T(x.tailnet ? 'partner.tailnet_online' : 'partner.tailnet_offline')));
  const lt = x.last_transfer;
  if (lt && lt.mbit) main.appendChild(el('div', 'row-detail', lt.bytes ? T('partner.last_transfer', { size: fmt.size(lt.bytes), mbit: fmt.number(lt.mbit, 0) }) : T('partner.mbit', { mbit: fmt.number(lt.mbit, 0) })));

  // what I keep of theirs
  const keep = el('div', 'row-detail');
  keep.appendChild(el('strong', '', T('partner.i_keep', { host: Office.config.host }) + ' '));
  if (x.receive) {
    keep.append(T('partner.i_keep_text', { list: unitsText(x.receive.units), pool: x.receive.pool, retention: retentionText(x.receive.retention),
      window: windowText(x.receive.window) }) + (x.receive.wake ? ' ' + T('partner.wakes') : ''));
    main.appendChild(keep);
    if (x.wish && x.wish.units && x.wish.units.length) main.appendChild(wishRow(x));
    const got = x.i_keep;
    if (got) {
      main.appendChild(quotaBar(got.used_bytes || 0, x.receive.quota_gb ? x.receive.quota_gb * 1024 ** 3 : 0));
      const units = Object.entries(got.units || {});
      if (units.length) {
        main.appendChild(el('div', 'row-detail', units.map(([u, v]) => T('partner.got', { unit: unitText(u), n: v.snaps || 0, when: fmt.relative(v.time) })).join(' · ')));
      }
    }
  } else {
    keep.append(T('partner.i_keep_nothing'));
    main.appendChild(keep);
  }
  (x.tickets || []).forEach((t) => main.appendChild(ticketLine(x, t)));
  r.appendChild(main);

  const acts = el('div', 'ct-acts');
  if (x.sends) {
    const ask = el('button', 'btn small plain', T('partner.ask'));
    ask.type = 'button';
    ask.disabled = busyOff();
    ask.title = T('partner.ask_title');
    ask.onclick = async () => {
      ask.disabled = true;
      const j = await Office.api.post(`${ID}.partner_ping`, { id: x.id });
      if (took(j) && j.ask) Office.toast(j.ask.reachable ? T('partner.asked_ok', { name: x.name }) : T('partner.asked_no', { name: x.name, why: T('partner.why.' + (j.ask.why || 'unreachable')) }), !j.ask.reachable);
      ask.disabled = false;
    };
    acts.appendChild(ask);
  }
  const more = el('button', 'btn small plain', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('partner.more'));
  more.disabled = busyOff();
  more.onclick = (e) => Office.menu(e, [
    ...(x.sends ? [{ text: T('partner.change', { host: Office.config.host }), act: () => changeDialog(x) }] : []),
    ...(x.receive ? [{ text: T('partner.t_make', { name: x.name }), act: () => ticketMakeDialog(x) }] : []),
    { text: T('partner.renew'), act: () => renewDialog(x) },
    { separator: true },
    { text: T('partner.end'), kind: 'danger', act: () => endDialog(x) },
  ]);
  acts.appendChild(more);
  r.appendChild(acts);
  return r;
}

function pendingRow(o) {
  const r = el('div', 'row nocheck ct-partner ct-pending');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T('partner.pending', { id: o.id })));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip('accent', T('partner.waiting'), T('partner.waiting_tip')));
  meta.appendChild(el('span', '', T('partner.offered', { when: fmt.relative(o.created) })));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T('partner.i_send', { host: Office.config.host }) + ' ' + unitsText(o.units)));
  r.appendChild(main);
  const acts = el('div', 'ct-acts');
  const show = el('button', 'btn small plain', T('partner.show_block'));
  show.type = 'button';
  show.onclick = async () => {
    const j = await Office.api.post(`${ID}.partner_add`, { step: 'show', id: o.id });
    if (took(j)) blockDialog(T('partner.block_a_title'), j.block, T('partner.block_a_text'), j.public);
  };
  const fin = el('button', 'btn small', T('partner.finish'));
  fin.type = 'button';
  fin.onclick = () => finishDialog();
  const drop = el('button', 'btn small plain', T('partner.withdraw'));
  drop.type = 'button';
  drop.onclick = () => Office.dialog({
    title: T('partner.withdraw_title'),
    body: T('partner.withdraw_text'),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.withdraw'), kind: 'danger', act: async () => took(await Office.api.post(`${ID}.partner_end`, { id: o.id })) }],
  });
  [show, fin, drop].forEach((b) => { b.disabled = busyOff(); acts.appendChild(b); });
  r.appendChild(acts);
  return r;
}

// ------------------------------------------------------------------ form parts
function field(label, control, help) {
  const f = el('div', 'field');
  const l = el('label', '', label);
  f.append(l, control);
  if (help) f.appendChild(el('small', '', help));
  return f;
}

function input(type, value, cls) {
  const i = el('input', 'input' + (cls ? ' ' + cls : ''));
  i.type = type;
  if (value !== undefined && value !== null) i.value = value;
  return i;
}

function tick(text, checked, help, disabled) {
  const l = el('label', 'check');
  const cb = el('input');
  cb.type = 'checkbox';
  cb.checked = !!checked;
  cb.disabled = !!disabled;
  const t = el('span', '', text);
  if (help) t.appendChild(el('small', '', help));
  l.append(cb, t);
  return { node: l, cb };
}

/** This office's address for the partner: its own addresses to choose, or another typed in */
function addressPicker(addresses, port) {
  const box = el('div');
  const sel = el('select', 'input');
  (addresses || []).forEach((a) => {
    const o = el('option', '', `${a.address} (${a.iface})${a.private ? '' : ' — ' + T('partner.public')}`);
    o.value = a.address;
    sel.appendChild(o);
  });
  const other = el('option', '', T('partner.address_other'));
  other.value = '';
  sel.appendChild(other);
  const typed = input('text', '', 'mono');
  typed.placeholder = T('partner.address_placeholder');
  typed.hidden = (addresses || []).length > 0;
  sel.onchange = () => { typed.hidden = sel.value !== ''; };
  const p = input('number', port || 22);
  p.min = 1; p.max = 65535;
  box.append(field(T('partner.address'), sel), typed);
  box.appendChild(field(T('partner.port'), p, T('partner.port_help')));
  return { node: box, value: () => ({ address: sel.value || typed.value.trim(), port: Number(p.value) }) };
}

function trustPicker(chosen) {
  const box = el('div', 'field');
  box.appendChild(el('div', 'field-title', T('partner.trust_title')));
  const radios = TRUST.map((t) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'ct-trust';
    r.value = t;
    r.checked = t === (chosen || 'mine');
    const s = el('span', '', T('partner.trust.' + t));
    s.appendChild(el('small', '', T('partner.trust_tip.' + t)));
    l.append(r, s);
    box.appendChild(l);
    return r;
  });
  return { node: box, value: () => (radios.find((r) => r.checked) || radios[0]).value };
}

/** Ticks for the units this office can send (the engine's plan: datasets of their own only) */
function unitPicker(units, title) {
  const box = el('div', 'field');
  box.appendChild(el('div', 'field-title', title));
  const ticks = [];
  if (!units || !units.length) box.appendChild(el('small', '', T('partner.units_none')));
  (units || []).forEach((u) => {
    const t = tick(unitText(u.id), false, u.ok ? (u.dataset || '') : T('partner.unit_why.' + (u.why || 'not_dataset')), !u.ok);
    box.appendChild(t.node);
    if (u.ok) ticks.push([u.id, t.cb]);
  });
  return { node: box, value: () => ticks.filter(([, cb]) => cb.checked).map(([id]) => id) };
}

/** What I keep of theirs: which of their units, the pool, quota, retention, window, wake */
function receivePicker(offered, pools, defaults) {
  const box = el('div');
  const units = el('div', 'field');
  units.appendChild(el('div', 'field-title', T('partner.receive_units')));
  const ticks = offered.map((u) => {
    const t = tick(unitText(u), true);
    units.appendChild(t.node);
    return [u, t.cb];
  });
  box.appendChild(units);
  const pool = el('select', 'input');
  (pools || []).forEach((p) => {
    const o = el('option', '', p.asleep ? T('partner.pool_asleep', { name: p.name }) : T('partner.pool_free', { name: p.name, free: fmt.size(p.free) }));
    o.value = p.name;
    pool.appendChild(o);
  });
  box.appendChild(field(T('partner.pool'), pool, T('partner.pool_help')));
  const quota = input('number', defaults.quota_gb || 0);
  quota.min = 0;
  box.appendChild(field(T('partner.quota'), quota, T('partner.quota_help')));
  const [d, w, m] = String(defaults.retention || DEFAULT_RETENTION).split(' ');
  const ret = el('div', 'ct-pret');
  const rd = input('number', d), rw = input('number', w), rm = input('number', m);
  [[rd, 'partner.ret_d'], [rw, 'partner.ret_w'], [rm, 'partner.ret_m']].forEach(([i, k]) => {
    i.min = 0; i.max = 999;
    const l = el('label', 'ct-pret-part');
    l.append(i, el('span', '', T(k)));
    ret.appendChild(l);
  });
  box.appendChild(field(T('partner.retention'), ret, T('partner.retention_help')));
  const [wf, wt] = String(defaults.window || '00:00-07:00').split('-');
  const win = el('div', 'ct-pret');
  const from = input('time', wf), to = input('time', wt);
  win.append(from, el('span', '', '–'), to);
  box.appendChild(field(T('partner.window'), win, T('partner.window_help')));
  const wake = tick(T('partner.wake'), defaults.wake !== false, T('partner.wake_help'));
  box.appendChild(wake.node);
  return {
    node: box,
    value: () => ({
      units: ticks.filter(([, cb]) => cb.checked).map(([u]) => u),
      pool: pool.value,
      quota_gb: Math.max(0, Math.round(Number(quota.value) || 0)),
      retention: [rd, rw, rm].map((i) => Math.max(0, Math.min(999, Math.round(Number(i.value) || 0)))).join(' '),
      window: `${from.value || '00:00'}-${to.value || '00:00'}`,
      wake: wake.cb.checked,
    }),
  };
}

function props(rows) {
  const dl = el('dl', 'props');
  rows.forEach(([k, v]) => {
    if (v === null || v === undefined || v === '') return;
    dl.append(el('dt', '', k), typeof v === 'string' ? el('dd', '', v) : (() => { const d = el('dd'); d.appendChild(v); return d; })());
  });
  return dl;
}

function codeBox(text) {
  const pre = el('pre', 'code ct-pblock', text);
  return pre;
}

/** A block to copy, with what to do with it */
function blockDialog(title, block, text, isPublic, code) {
  const box = el('div');
  if (code) {
    box.appendChild(el('p', '', T('partner.code_text')));
    box.appendChild(el('div', 'ct-pcode', code.replace(/^(\d{3})(\d{3})$/, '$1 $2')));
  }
  box.appendChild(el('p', '', text));
  if (isPublic) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  box.appendChild(codeBox(block));
  Office.dialog({
    title,
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.close') }, { text: T('partner.copy'), kind: '', act: () => { Office.copy(block); return false; } }],
  });
}

function pasteDialog(title, text, next) {
  const box = el('div');
  box.appendChild(el('p', '', text));
  const ta = el('textarea', 'input mono ct-ppaste');
  ta.rows = 6;
  ta.spellcheck = false;
  ta.setAttribute('autocomplete', 'off');
  box.appendChild(ta);
  Office.dialog({
    title,
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.look'), kind: '', act: () => next(ta.value.trim()) }],
  });
  setTimeout(() => ta.focus(), 0);
}

/** The line that goes into authorized_keys, quoted, with the least-privilege sentence and «I have read» */
function linePart(line, name) {
  const box = el('div');
  box.appendChild(el('p', '', T('partner.line_text', { name })));
  box.appendChild(codeBox(line));
  box.appendChild(el('p', 'callout', T('partner.least_privilege', { name })));
  const read = tick(T('partner.line_read'), false);
  box.appendChild(read.node);
  return { node: box, ok: () => read.cb.checked };
}

// ------------------------------------------------------------------ add a partner (side A)
async function addDialog() {
  const j = await Office.api.post(`${ID}.partner_add`, { step: 'look' });
  if (!took(j)) return;
  const box = el('div');
  box.appendChild(el('p', '', T('partner.add_text')));
  if (!j.host_key) box.appendChild(el('p', 'callout warn', T('errors.partner_host_key')));
  const addr = addressPicker(j.addresses, j.port);
  box.appendChild(addr.node);
  const trust = trustPicker('mine');
  box.appendChild(trust.node);
  const units = unitPicker(j.units, T('partner.send_units'));
  box.appendChild(units.node);
  box.appendChild(el('p', 'callout', T('partner.clear_copies')));
  Office.dialog({
    title: T('partner.add_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.make_block'), kind: '', act: async () => {
      const a = addr.value();
      const r = await Office.api.post(`${ID}.partner_add`, { step: 'do', address: a.address, port: a.port, trust: trust.value(), units: units.value() });
      if (!took(r)) return false;
      if (ctx) ctx.reload();
      blockDialog(T('partner.block_a_title'), r.block, T('partner.block_a_text'), r.public);
      return true;
    } }],
  });
}

// ------------------------------------------------------------------ accept a partner (side B)
function acceptDialog() {
  pasteDialog(T('partner.accept_title'), T('partner.accept_text'), async (block) => {
    const j = await Office.api.post(`${ID}.partner_accept`, { step: 'look', block });
    if (!took(j)) return false;
    acceptSettings(block, j);
    return true;
  });
}

function partnerFacts(p) {
  return props([
    [T('partner.f_name'), p.name],
    [T('partner.f_address'), `${p.address}:${p.port}`],
    [T('partner.f_trust'), p.trust ? T('partner.trust.' + p.trust) : null],
    [T('partner.f_host_keys'), (p.host_keys || []).join('\n')],
    [T('partner.f_sends'), unitsText(p.units)],
  ]);
}

function acceptSettings(block, j) {
  const p = j.partner;
  if (j.self) { acceptSelf(block, j); return; }
  const box = el('div');
  box.appendChild(el('p', '', T('partner.accept_look', { name: p.name })));
  box.appendChild(partnerFacts(p));
  if (p.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  let recv = null;
  let keepIt = null;
  if (p.units.length) {
    keepIt = tick(T('partner.keep_theirs', { name: p.name }), true);
    box.appendChild(keepIt.node);
    recv = receivePicker(p.units, j.pools, j.defaults);
    box.appendChild(recv.node);
    keepIt.cb.onchange = () => { recv.node.hidden = !keepIt.cb.checked; };
    if (!j.pools.length) box.appendChild(el('p', 'callout warn', T('partner.no_pools')));
  } else {
    box.appendChild(el('p', 'callout', T('partner.they_send_nothing', { name: p.name })));
  }
  const too = tick(T('partner.send_too'), !p.units.length, T('partner.send_too_help', { name: p.name }));
  box.appendChild(too.node);
  const mine = unitPicker(j.units, T('partner.send_units'));
  mine.node.hidden = !too.cb.checked;
  box.appendChild(mine.node);
  const addr = addressPicker(j.addresses, j.port);
  addr.node.hidden = false;
  box.appendChild(addr.node);
  const trust = trustPicker(p.trust || 'mine');
  box.appendChild(trust.node);
  too.cb.onchange = () => { mine.node.hidden = !too.cb.checked; };
  Office.dialog({
    title: T('partner.accept_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.next'), kind: '', act: () => {
      const a = addr.value();
      const req = { step: 'do', block, confirm: true, address: a.address, port: a.port, trust: trust.value(),
        receive: recv && keepIt.cb.checked ? recv.value() : null, send_too: too.cb.checked, units: too.cb.checked ? mine.value() : [] };
      if (!req.receive && !req.send_too) { Office.toast(T('errors.partner_nothing'), true); return false; }
      acceptConfirm(req, j);
      return true;
    } }],
  });
}

function acceptConfirm(req, j) {
  const p = j.partner;
  const box = el('div');
  let line = null;
  if (req.receive) {
    if (!j.line) { Office.toast(Office.errorText({ key: 'partner_unresolved', params: { address: p.address } }, ID), true); return; }
    line = linePart(j.line, p.name);
    box.appendChild(line.node);
  } else {
    box.appendChild(el('p', '', T('partner.no_line', { name: p.name })));
  }
  if (req.send_too) box.appendChild(el('p', '', T('partner.my_key_made', { name: p.name })));
  Office.dialog({
    title: T('partner.confirm_title', { name: p.name }),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: req.receive ? T('partner.write_and_pair') : T('partner.pair'), kind: '', act: async () => {
      if (line && !line.ok()) { Office.toast(T('partner.line_read_first'), true); return false; }
      const r = await Office.api.post(`${ID}.partner_accept`, req);
      if (!took(r)) return false;
      blockDialog(T('partner.block_b_title'), r.block, T('partner.block_b_text', { name: p.name }), r.public, r.code);
      return true;
    } }],
  });
}

/** This office's own block pasted: it pairs with itself — a test on one server (no second block, no code) */
function acceptSelf(block, j) {
  const p = j.partner;
  const box = el('div');
  box.appendChild(el('p', 'callout', T('partner.self_text', { address: `${p.address}:${p.port}` })));
  const recv = receivePicker(p.units, j.pools, j.defaults);
  box.appendChild(recv.node);
  if (!j.pools.length) box.appendChild(el('p', 'callout warn', T('partner.no_pools')));
  const line = j.line ? linePart(j.line, p.name) : null;
  if (line) box.appendChild(line.node);
  Office.dialog({
    title: T('partner.self_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.write_and_pair'), kind: '', act: async () => {
      if (!line) { Office.toast(Office.errorText({ key: 'partner_unresolved', params: { address: p.address } }, ID), true); return false; }
      if (!line.ok()) { Office.toast(T('partner.line_read_first'), true); return false; }
      const r = await Office.api.post(`${ID}.partner_accept`, { step: 'do', block, confirm: true, receive: recv.value() });
      if (!took(r)) return false;
      const a = r.ask;
      Office.toast(a && a.reachable ? T('partner.self_done') : T('partner.self_silent', { why: T('partner.why.' + ((a && a.why) || 'unreachable')) }), !(a && a.reachable));
      return true;
    } }],
  });
}

// ------------------------------------------------------------------ the partner's answer (side A)
function finishDialog() {
  pasteDialog(T('partner.finish_title'), T('partner.finish_text'), async (block) => {
    const j = await Office.api.post(`${ID}.partner_finish`, { step: 'look', block });
    if (!took(j)) return false;
    finishCompare(block, j);
    return true;
  });
}

function finishCompare(block, j) {
  const p = j.partner;
  const box = el('div');
  box.appendChild(el('p', '', T('partner.compare_text', { name: p.name })));
  box.appendChild(el('div', 'ct-pcode', j.code.replace(/^(\d{3})(\d{3})$/, '$1 $2')));
  const keeps = p.receive;
  box.appendChild(props([
    [T('partner.f_name'), p.name],
    [T('partner.f_address'), `${p.address}:${p.port}`],
    [T('partner.f_host_keys'), (p.host_keys || []).join('\n')],
    [T('partner.f_keeps'), keeps ? T('partner.they_keep_text', { list: unitsText(keeps.units), retention: retentionText(keeps.retention), window: windowText(keeps.window) }) : T('partner.they_keep_nothing')],
    [T('partner.f_sends'), unitsText(p.units)],
  ]));
  if (p.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  let recv = null;
  let keepIt = null;
  let line = null;
  if (p.units.length) {
    keepIt = tick(T('partner.keep_theirs', { name: p.name }), true);
    box.appendChild(keepIt.node);
    recv = receivePicker(p.units, j.pools, j.defaults);
    box.appendChild(recv.node);
    if (j.line) {
      line = linePart(j.line, p.name);
      box.appendChild(line.node);
    }
    keepIt.cb.onchange = () => { recv.node.hidden = !keepIt.cb.checked; if (line) line.node.hidden = !keepIt.cb.checked; };
  }
  Office.dialog({
    title: T('partner.finish_title'),
    body: box,
    wide: true,
    buttons: [
      { text: T('partner.no_match'), act: () => { Office.dialog({ title: T('partner.no_match_title'), body: T('partner.no_match_text', { name: p.name }) }); return true; } },
      { text: T('partner.match'), kind: '', act: async () => {
        const keep = recv && keepIt.cb.checked;
        if (keep && (!line || !line.ok())) { Office.toast(line ? T('partner.line_read_first') : Office.errorText({ key: 'partner_unresolved', params: { address: p.address } }, ID), true); return false; }
        const r = await Office.api.post(`${ID}.partner_finish`, { step: 'do', block, confirm: true, code: j.code, receive: keep ? recv.value() : null });
        if (!took(r)) return false;
        const a = r.ask;
        Office.toast(!a ? T('partner.paired', { name: p.name })
          : a.reachable ? T('partner.paired_ok', { name: p.name }) : T('partner.paired_silent', { name: p.name, why: T('partner.why.' + (a.why || 'unreachable')) }), !!a && !a.reachable);
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ what a pair covers, changed (2.29)
/**
 * The sender's card: what was asked for and isn't agreed (yet), what is no longer sent while the partner still keeps
 * its copies. `offered` is when the partner last answered an offer; its status (they_keep) says what it agreed to keep
 * and what of the wish its Team Lead hasn't decided on — when that status is newer than the answer.
 */
function sendLines(x) {
  const out = [];
  const sent = x.send_units || [];
  const asked = (x.wanted || []).filter((u) => !sent.includes(u));
  const tk = x.they_keep;
  if (asked.length) {
    const o = x.offer;
    if (!x.offered) {
      out.push(el('div', 'row-detail', T('partner.offer_wait', { name: x.name, list: unitsText(asked) })
        + (o && o.why ? ' (' + whyText(o.why) + ')' : '')));
    } else if (o && o.ok === false) {
      out.push(el('div', 'row-detail', o.why === 'unknown_verb' ? T('partner.offer_old', { name: x.name })
        : T('partner.offer_refused', { name: x.name, why: whyText(o.why) })));
    } else {
      const wish = tk && Array.isArray(tk.wish) && Array.isArray(tk.agreed) && x.status_time && x.status_time >= x.offered ? tk.wish : null;
      const no = wish ? asked.filter((u) => !wish.includes(u)) : [];
      const wait = asked.filter((u) => !no.includes(u));
      if (wait.length) out.push(el('div', 'row-detail', T('partner.offer_pending', { name: x.name, list: unitsText(wait), when: fmt.relative(x.offered) })));
      if (no.length) out.push(el('div', 'row-detail', T('partner.offer_no', { name: x.name, list: unitsText(no), host: Office.config.host })));
    }
  }
  const kept = Object.keys((tk && tk.units) || {}).filter((u) => !sent.includes(u) && !asked.includes(u));
  if (kept.length) out.push(el('div', 'row-detail', T('partner.no_longer_sent', { name: x.name, list: unitsText(kept) })));
  return out;
}

/** «Change what <host> sends…»: the units as «Add a partner…» shows them, what goes now ticked; new ones are asked for */
async function changeDialog(x) {
  const j = await Office.api.post(`${ID}.partner_change`, { step: 'look', id: x.id });
  if (!took(j)) return;
  const sent = j.send_units || [];
  const had = [...new Set([...sent, ...(j.wanted || [])])];
  const box = el('div');
  box.appendChild(el('p', '', T('partner.change_text', { name: x.name })));
  const field = el('div', 'field');
  field.appendChild(el('div', 'field-title', T('partner.send_units')));
  const ticks = [];
  const seen = new Set();
  const note = (u) => (sent.includes(u) ? T('partner.unit_sent') : had.includes(u) ? T('partner.unit_asked', { name: x.name }) : '');
  (j.units || []).forEach((u) => {
    seen.add(u.id);
    const mine = had.includes(u.id);
    const help = [u.ok ? (u.dataset || '') : T('partner.unit_why.' + (u.why || 'not_dataset')), note(u.id)].filter(Boolean).join(' · ');
    const t = tick(unitText(u.id), mine, help, !u.ok && !mine);         // what goes or was asked for can always be unticked
    field.appendChild(t.node);
    if (u.ok || mine) ticks.push([u.id, t.cb]);
  });
  had.filter((u) => !seen.has(u)).forEach((u) => {
    const t = tick(unitText(u), true, [T('partner.unit_gone'), note(u)].filter(Boolean).join(' · '));
    field.appendChild(t.node);
    ticks.push([u, t.cb]);
  });
  if (!ticks.length) field.appendChild(el('small', '', T('partner.units_none')));
  box.appendChild(field);
  box.appendChild(el('p', 'callout', T('partner.change_note', { name: x.name })));
  Office.dialog({
    title: T('partner.change_title', { host: Office.config.host, name: x.name }),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.change_ask', { name: x.name }), kind: '', act: async () => {
      const units = ticks.filter(([, cb]) => cb.checked).map(([u]) => u);
      if (!units.length) { Office.toast(T('partner.change_none', { name: x.name }), true); return false; }
      const r = await Office.api.post(`${ID}.partner_change`, { step: 'do', id: x.id, units });
      if (!took(r)) return false;
      const o = r.offer;
      Office.toast(changedText(x, units, r), !!(o && !o.ok));
      return true;
    } }],
  });
}

/** The toast after «Ask <partner>»: what no longer goes, what it keeps too, what its Team Lead decides, no answer */
function changedText(x, units, r) {
  const parts = [];
  const before = x.send_units || [];
  const asked = r.asked || [];
  if ((r.removed || []).length) parts.push(T('partner.changed_removed', { name: x.name, list: unitsText(r.removed) }));
  const agreed = units.filter((u) => !before.includes(u) && !asked.includes(u));
  if (agreed.length) parts.push(T('partner.changed_agreed', { name: x.name, list: unitsText(agreed) }));
  const o = r.offer;
  if (o && o.ok && (o.pending || []).length) parts.push(T('partner.changed_asked', { name: x.name, list: unitsText(o.pending) }));
  else if (o && !o.ok && !o.reachable) parts.push(T('partner.changed_unanswered', { name: x.name, why: whyText(o.why) }));
  else if (o && !o.ok) parts.push(o.why === 'unknown_verb' ? T('partner.offer_old', { name: x.name }) : T('partner.offer_refused', { name: x.name, why: whyText(o.why) }));
  return parts.length ? parts.join(' ') : T('partner.changed_same');
}

/** The receiver's card: what the partner would also like to send — «Keep it too» / «No» */
function wishRow(x) {
  const d = el('div', 'row-detail ct-wish');
  d.appendChild(chip('accent', T('partner.wish', { name: x.name, list: unitsText(x.wish.units) }), T('partner.wish_tip', { name: x.name, when: fmt.relative(x.wish.time) })));
  const keep = el('button', 'btn small', T('partner.wish_keep'));
  keep.type = 'button';
  keep.disabled = busyOff();
  keep.onclick = () => wishKeepDialog(x);
  const no = el('button', 'btn small plain', T('partner.wish_no'));
  no.type = 'button';
  no.disabled = busyOff();
  no.title = T('partner.wish_no_tip', { name: x.name });
  no.onclick = async () => {
    no.disabled = true;
    const j = await Office.api.post(`${ID}.partner_wish`, { id: x.id, keep: false, confirm: true });
    if (took(j)) Office.toast(T('partner.wish_said_no', { name: x.name }));
    else no.disabled = false;
  };
  d.append(' ', keep, ' ', no);
  return d;
}

/** «Keep it too»: which of the wished units, where their copies go, the agreement's terms (they stay) */
function wishKeepDialog(x) {
  const r = x.receive;
  const box = el('div');
  box.appendChild(el('p', '', T('partner.wish_text', { name: x.name })));
  const ticks = x.wish.units.map((u) => {
    const t = tick(unitText(u), true, `${r.pool}/UnraidSecretaryOffice-partners/${x.id}/${u.replace(':', '-')}`);
    box.appendChild(t.node);
    return [u, t.cb];
  });
  box.appendChild(el('p', '', T('partner.wish_where', { name: x.name, pool: r.pool })));
  box.appendChild(el('p', 'callout', T('partner.wish_terms', { name: x.name, quota: r.quota_gb ? fmt.size(r.quota_gb * 1024 ** 3) : T('partner.wish_no_quota'),
    retention: retentionText(r.retention), window: windowText(r.window) })));
  Office.dialog({
    title: T('partner.wish_title', { name: x.name }),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.wish_keep'), kind: '', act: async () => {
      const units = ticks.filter(([, cb]) => cb.checked).map(([u]) => u);
      if (!units.length) { Office.toast(T('partner.wish_pick'), true); return false; }
      const j = await Office.api.post(`${ID}.partner_wish`, { id: x.id, units, keep: true, confirm: true });
      if (!took(j)) return false;
      Office.toast(T('partner.wish_kept', { name: x.name, list: unitsText(units) }));
      return true;
    } }],
  });
}

// ------------------------------------------------------------------ end, renew
function endDialog(x, after) {
  const box = el('div');
  box.appendChild(el('p', '', T('partner.end_text', { name: x.name })));
  box.appendChild(el('p', 'callout', T('partner.end_copies', { name: x.name })));
  Office.dialog({
    title: T('partner.end_title', { name: x.name }),
    body: box,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.end'), kind: 'danger', act: async () => {
      const j = await Office.api.post(`${ID}.partner_end`, { id: x.id });
      if (!took(j)) return false;
      Office.toast(T('partner.ended', { name: x.name }));
      if (after) setTimeout(after, 0);
      return true;
    } }],
  });
}

function renewDialog(x) {
  Office.dialog({
    title: T('partner.renew_title', { name: x.name }),
    body: T('partner.renew_text', { name: x.name }),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.renew'), kind: '', act: () => { endDialog(x, addDialog); return true; } }],
  });
}

// ------------------------------------------------------------------ restore tickets (a gone server's copies to a new server)
/** A ticket given on the holder's card: for whom, until when, «End» */
function ticketLine(x, t) {
  const d = el('div', 'row-detail ct-ticket');
  d.append('🎫 ', T(t.expired ? 'partner.t_given_expired' : 'partner.t_given', { name: t.name, address: t.address, until: fmt.date(t.expires), of: x.name }), ' ');
  if (t.door === 'closed' && !t.expired) d.appendChild(chip('warn', T('partner.door_closed'), T('partner.t_door_closed_tip')));
  const end = el('button', 'btn small plain', T('partner.t_end'));
  end.type = 'button';
  end.disabled = busyOff();
  end.onclick = () => ticketEndDialog(t.id, T('partner.t_end_given', { name: t.name, of: x.name }));
  d.appendChild(end);
  return d;
}

/** A new server's ticket: the holder, whose copies, until when — Mr. Restori pulls them */
function ticketCard(t) {
  const r = el('div', 'row nocheck ct-partner ct-ticket-card');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', '🎫 ' + T('partner.t_card', { name: t.name, of: t.of })));
  const meta = el('div', 'row-meta');
  meta.appendChild(t.expired ? chip('danger', T('partner.t_expired')) : chip('accent', T('partner.t_until', { until: fmt.date(t.expires) }), T('partner.t_until_tip')));
  if (t.last_heard) meta.appendChild(chip('ok', T('partner.answers'), T('partner.answers_tip', { when: fmt.date(t.last_heard) })));
  meta.appendChild(el('span', '', `${t.address}:${t.port}`));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T('partner.t_units', { name: t.name, list: unitsText(t.units) })));
  const to = el('div', 'row-detail');
  const a = el('a', '', T('partner.t_to_restori'));
  a.href = '#/restore';
  to.appendChild(a);
  main.appendChild(to);
  r.appendChild(main);
  const acts = el('div', 'ct-acts');
  const end = el('button', 'btn small plain', T('partner.t_end'));
  end.type = 'button';
  end.disabled = busyOff();
  end.onclick = () => ticketEndDialog(t.id, T('partner.t_end_held', { name: t.name, of: t.of }));
  acts.appendChild(end);
  r.appendChild(acts);
  return r;
}

/** A request for a ticket that waits for the holder's answer: its block again, «Paste the ticket», withdraw */
function ticketRequestRow(q) {
  const r = el('div', 'row nocheck ct-partner ct-pending');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T('partner.t_request', { id: q.id })));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip('accent', T('partner.waiting'), T('partner.t_waiting_tip')));
  meta.appendChild(el('span', '', T('partner.offered', { when: fmt.relative(q.created) })));
  main.appendChild(meta);
  r.appendChild(main);
  const acts = el('div', 'ct-acts');
  const show = el('button', 'btn small plain', T('partner.show_block'));
  show.type = 'button';
  show.onclick = async () => {
    const j = await Office.api.post(`${ID}.partner_ticket_start`, { step: 'show', id: q.id });
    if (took(j)) blockDialog(T('partner.t_block_n_title'), j.block, T('partner.t_block_n_text'), false);
  };
  const fin = el('button', 'btn small', T('partner.t_finish'));
  fin.type = 'button';
  fin.onclick = () => ticketFinishDialog();
  const drop = el('button', 'btn small plain', T('partner.withdraw'));
  drop.type = 'button';
  drop.onclick = () => ticketEndDialog(q.id, T('partner.t_withdraw_text'));
  [show, fin, drop].forEach((b) => { b.disabled = busyOff(); acts.appendChild(b); });
  r.appendChild(acts);
  return r;
}

/** «Start from a partner's copy…» (the new server): its address as the holder sees it → its key and BLOCK-N */
async function ticketStartDialog() {
  const j = await Office.api.post(`${ID}.partner_ticket_start`, { step: 'look' });
  if (!took(j)) return;
  const box = el('div');
  box.appendChild(el('p', '', T('partner.t_start_text')));
  const addr = addressPicker(j.addresses, 22);
  addr.node.querySelectorAll('.field')[1].hidden = true;        // the holder never connects here: no port
  box.appendChild(addr.node);
  box.appendChild(el('p', 'callout', T('partner.t_start_after')));
  Office.dialog({
    title: T('partner.t_start'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.make_block'), kind: '', act: async () => {
      const r = await Office.api.post(`${ID}.partner_ticket_start`, { step: 'do', address: addr.value().address });
      if (!took(r)) return false;
      if (ctx) ctx.reload();
      blockDialog(T('partner.t_block_n_title'), r.block, T('partner.t_block_n_text'), false);
      return true;
    } }],
  });
}

/** «Hand <name>'s copies to a new server…» (the holder, on the gone server's card): paste BLOCK-N, then the line */
function ticketMakeDialog(x) {
  pasteDialog(T('partner.t_make', { name: x.name }), T('partner.t_make_text', { name: x.name }), async (block) => {
    const j = await Office.api.post(`${ID}.partner_ticket_make`, { step: 'look', pair: x.id, block });
    if (!took(j)) return false;
    ticketMakeConfirm(x, block, j);
    return true;
  });
}

function ticketMakeConfirm(x, block, j) {
  const n = j.new;
  const box = el('div');
  box.appendChild(el('p', '', T('partner.t_make_look', { name: n.name, of: j.of, until: fmt.date(j.expires) })));
  box.appendChild(props([
    [T('partner.f_name'), n.name],
    [T('partner.f_address'), n.address],
    [T('partner.t_f_key'), n.key],
    [T('partner.t_f_units'), unitsText(j.units)],
    [T('partner.t_f_until'), fmt.date(j.expires)],
  ]));
  if (n.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  if (!j.line) { Office.toast(Office.errorText({ key: 'partner_unresolved', params: { address: n.address } }, ID), true); return; }
  box.appendChild(el('p', '', T('partner.t_line_text', { name: n.name, of: j.of })));
  box.appendChild(codeBox(j.line));
  box.appendChild(el('p', 'callout', T('partner.t_least_privilege', { name: n.name, of: j.of })));
  const read = tick(T('partner.line_read'), false);
  box.appendChild(read.node);
  const addr = addressPicker(j.addresses, j.port);
  box.appendChild(addr.node);
  const close = tick(T('partner.t_close_door', { of: j.of }), false, T('partner.t_close_door_help', { of: j.of }));
  box.appendChild(close.node);
  Office.dialog({
    title: T('partner.t_make', { name: x.name }),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.t_write'), kind: '', act: async () => {
      if (!read.cb.checked) { Office.toast(T('partner.line_read_first'), true); return false; }
      const a = addr.value();
      const r = await Office.api.post(`${ID}.partner_ticket_make`, { step: 'do', pair: x.id, block, confirm: true, address: a.address, port: a.port, close_door: close.cb.checked });
      if (!took(r)) return false;
      blockDialog(T('partner.t_block_t_title'), r.block, T('partner.t_block_t_text', { name: n.name, until: fmt.date(r.expires) }), r.public, r.code);
      return true;
    } }],
  });
}

/** «Paste the ticket» (the new server): BLOCK-T, the code compared, then Mr. Restori pulls */
function ticketFinishDialog() {
  pasteDialog(T('partner.t_finish'), T('partner.t_finish_text'), async (block) => {
    const j = await Office.api.post(`${ID}.partner_ticket_finish`, { step: 'look', block });
    if (!took(j)) return false;
    const t = j.ticket;
    const box = el('div');
    box.appendChild(el('p', '', T('partner.t_compare', { name: t.name })));
    box.appendChild(el('div', 'ct-pcode', j.code.replace(/^(\d{3})(\d{3})$/, '$1 $2')));
    box.appendChild(props([
      [T('partner.f_name'), t.name],
      [T('partner.t_f_of'), t.of],
      [T('partner.f_address'), `${t.address}:${t.port}`],
      [T('partner.f_host_keys'), (t.host_keys || []).join('\n')],
      [T('partner.t_f_units'), unitsText(t.units)],
      [T('partner.t_f_until'), fmt.date(t.expires)],
    ]));
    if (t.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
    Office.dialog({
      title: T('partner.t_finish'),
      body: box,
      wide: true,
      buttons: [
        { text: T('partner.no_match'), act: () => { Office.dialog({ title: T('partner.no_match_title'), body: T('partner.no_match_text', { name: t.name }) }); return true; } },
        { text: T('partner.match'), kind: '', act: async () => {
          const r = await Office.api.post(`${ID}.partner_ticket_finish`, { step: 'do', block, confirm: true, code: j.code });
          if (!took(r)) return false;
          const a = r.ask;
          Office.toast(a && a.reachable ? T('partner.t_taken', { name: t.name, of: t.of }) : T('partner.t_taken_silent', { name: t.name, why: T('partner.why.' + ((a && a.why) || 'unreachable')) }), !(a && a.reachable));
          return true;
        } },
      ],
    });
    return true;
  });
}

function ticketEndDialog(id, text) {
  Office.dialog({
    title: T('partner.t_end'),
    body: text,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.t_end'), kind: 'danger', act: async () => took(await Office.api.post(`${ID}.partner_ticket_end`, { id })) }],
  });
}

Office.ctPartner = {
  /** desk.js hands over: state() his state, took(partners) puts new cards in and renders, reload() a fresh look */
  use(c) { ctx = c; },
  section,
};
if (typeof Office.ctPartnerLoaded === 'function') Office.ctPartnerLoaded();
})();
