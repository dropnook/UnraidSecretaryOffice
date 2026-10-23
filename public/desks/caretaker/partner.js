/* The Team Lead's «Partner offices» (loaded by desk.js beside it): two offices pair up and keep copies of each
   other's ZFS snapshots (the partner plan). Pairing is two pastes and a code — «Add a partner…»
   makes BLOCK-A, the partner's «Accept a partner…» answers with BLOCK-B and the SAFETY CODE, «Paste the partner's
   answer» compares it. The cards come from his state (`partners`, agent/lib/partner.php partnerPublic()): never a
   key, never a block. The agent part: partner_add / accept / finish / end / ping in agent/desks/caretaker.php.
   Since 1.60 (the concept «Partner einfach») the Team Lead only pairs: what this server sends is the level per share
   and VM in Mr. Backupsy's setup (the card says it: «{host} sends to {name}: …»), the receiver's consent is the
   pairing — its rules (pool, quota, retention; since 1.60.2 its door always wakes the pool) at «Accept a partner…», its door takes any unit up to the quota,
   at any time («{host} keeps of {name}: …», a unit it never saw before chipped «new»). */
(() => {
'use strict';

const ID = 'caretaker';
const T = Office.scope(ID);
const { el, fmt } = Office;
const TRUST = ['mine', 'family', 'friend'];
const DEFAULT_RETENTION = '7 4 6';

let ctx = null;         // from desk.js: { took(j), reload(), peek() }
let ready = null;       // what each side of a pairing needs here (the agent's `ready`: Mr. Backupsy hired and set up, Mr. Restori hired)

const unitText = (u) => (u === 'place' ? T('partner.unit_place')
  : u.startsWith('share:') ? T('partner.unit_share', { name: u.slice(6) })
  : u.startsWith('vm:') ? T('partner.unit_vm', { name: u.slice(3) }) : u);
const unitsText = (list) => (list && list.length ? list.map(unitText).join(', ') : T('partner.nothing'));
const retentionText = (r) => {
  const [d, w, m] = String(r || DEFAULT_RETENTION).split(' ').map(Number);
  const n = (what, x) => T('count.' + what, { n: Number.isFinite(x) ? x : 0 });
  return T('partner.retention_text', { d: n('days', d), w: n('weeks', w), m: n('months', m) });
};
const busyOff = () => !Office.agent.running;
/** A door's or ssh's reason in words (the code itself when no text knows it) */
const whyText = (w) => (Office.has(`${ID}.partner.why.${w}`) ? T('partner.why.' + w) : String(w || '?'));

// «Ask now» under way: pair id → its last_try before the click. The ask is a job of the agent's (never its request, BG1):
// the page looks at his state a few times, the answer is said once the card carries a newer try and no longer asks
const waiting = new Map();
const ASK_LOOKS = [3, 8, 16, 30, 50, 80, 120];

/** Said once a pair asked by «Ask now» has its answer on the card */
function askAnswered(pairs) {
  pairs.forEach((x) => {
    if (!waiting.has(x.id) || x.asking || (x.last_try || 0) <= waiting.get(x.id)) return;
    waiting.delete(x.id);
    const fine = x.reachable === true && !x.why;
    Office.toast(fine ? T('partner.asked_ok', { name: x.name }) : T('partner.asked_no', { name: x.name, why: whyText(x.why || 'unreachable') }), !fine);
  });
}

/** «Ask now»: the agent hands the ask to a job and answers at once; the card says «asking …» until the answer is there */
async function askNow(x, btn) {
  btn.disabled = true;
  const j = await Office.api.post(`${ID}.partner_ping`, { id: x.id });
  if (!took(j)) { btn.disabled = false; return; }
  waiting.set(x.id, x.last_try || 0);
  ASK_LOOKS.forEach((sec) => setTimeout(() => { if (waiting.has(x.id) && ctx) ctx.peek(); }, sec * 1000));
  setTimeout(() => waiting.delete(x.id), (ASK_LOOKS[ASK_LOOKS.length - 1] + 5) * 1000);
}

/** The answer of an action: its partner cards into his state, or the error said */
function took(j) {
  if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
  if (j.partners && ctx) ctx.took(j.partners);
  return true;
}

// ------------------------------------------------------------------ the section
function section(state) {
  const p = (state && state.partners) || { pairs: [], pending: [] };
  ready = p.ready || null;
  askAnswered(p.pairs);
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
  s.appendChild(Office.sectionHead(T('partner.title'), T('partner.sub'), ...extra, { place: 'partner.title' }));
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
  // his kept state's picture of the network may be older than the last look the page made itself (usonet_look, every
  // 4 s while it is busy): the newer of the two is drawn — never a «joining» from before the node was in (2026-10-23)
  const kept = state && state.usonet;
  netShown = netLive && (!kept || !kept.time || netLive.time >= kept.time) ? netLive : kept;
  s.appendChild(netBox(netShown));
  return s;
}

// ------------------------------------------------------------------ the USO network (agent/lib/usonet.php)
/* The office's own Tailscale node underneath the pairing (owner's concept, D1–D10): this server and its partner offices
   see each other in a tailnet of their own; then they pair as always, over the node's address (the picker lists it).
   His state's `usonet` (usonetPublic(): never a key); while it joins, leaves or starts the page looks every few seconds. */
const NET_BUSY = ['joining', 'leaving', 'starting'];
// states that change on their own within a minute (the helper is started again every minute, contact comes back): the
// page keeps looking at them too — without the wave (2026-10-23, the lab: «not running» stood until a reload)
const NET_WATCH = [...NET_BUSY, 'down', 'no_contact'];
// a fold the user opened or closed stays so while the page lives (every render builds the section anew)
const folds = new Map();
function keepFold(det, key, open) {
  det.open = folds.has(key) ? folds.get(key) : open;
  let shown = det.open;
  det.addEventListener('toggle', () => {
    if (det.open === shown) return;
    shown = det.open;
    folds.set(key, det.open);
  });
  return det;
}
let netTimer = null;
let netSince = 0;       // since when the page looks at a busy network (0: it doesn't)
let netShown = null;    // the network as the section drew it last («Change the address…» lists its online peers)
let netLive = null;     // the last answer of the page's own look (usonet_look) or of a join/leave — drawn when newer than his kept state's
const NET_WATCH_MAX = 20 * 60 * 1000;      // a join may take long (the helper's download, the login); the look stops after that

/**
 * The network's answer into his state (desk.js tookNet), and while it is busy another look every few seconds — one
 * timer, however often the page is drawn, until the state settles (then the card flips to «in the network» on its own;
 * 2026-10-23: before, every render counted a look and the looks ran out after ~80 s while the join took longer — the
 * card stayed on «joining» until a reload).
 */
function netTook(n) {
  if (!n) return;
  if (!netLive || !n.time || !netLive.time || n.time >= netLive.time) netLive = n;
  if (ctx && ctx.tookNet) ctx.tookNet(n);
  netWatch(n);
}

/** Arms the poll: looks again in 4 s while the network is busy (joining, leaving, starting) — started by a render or an action */
function netWatch(n) {
  const busy = NET_WATCH.includes(n.state);
  if (!busy) { clearTimeout(netTimer); netTimer = null; netSince = 0; return; }
  if (!netSince) netSince = Date.now();
  if (netTimer || Date.now() - netSince > NET_WATCH_MAX) return;
  netTimer = setTimeout(async () => {
    netTimer = null;
    const j = await Office.api.post(`${ID}.usonet_look`, {});
    if (j.ok && j.usonet) netTook(j.usonet);
    else netWatch(n);          // the agent away for a moment: look again
  }, 4000);
}

function netChip(n) {
  const cls = { in: 'ok', joining: 'quiet', leaving: 'quiet', starting: 'quiet', off: 'quiet' }[n.state] || 'danger';
  const c = chip(cls, T('usonet.state.' + n.state), T('usonet.tip.' + n.state));
  if (NET_BUSY.includes(n.state)) { c.classList.add('running'); c.prepend(el('span', 'spin'), ' '); }     // .running shows the wave (office.css)
  return c;
}

function netBox(n) {
  n = n || { state: 'off', joined: false, peers: [], ips: [] };
  const box = el('div', 'box ct-group ct-net');
  Office.place('usonet', box);
  const head = el('div', 'ct-subhead');
  const words = el('div', 'ct-subhead-words');
  words.append(el('strong', '', T('usonet.title')), el('span', '', T('usonet.sub')));
  head.append(words, netChip(n));
  if (!n.joined && !NET_BUSY.includes(n.state)) {
    const join = el('button', 'btn small', T('usonet.join'));
    join.type = 'button';
    join.disabled = busyOff();
    join.onclick = () => joinDialog(n);
    head.appendChild(join);
  } else if (n.joined && n.state !== 'leaving') {
    const leave = el('button', 'btn small plain', T('usonet.leave'));
    leave.type = 'button';
    leave.disabled = busyOff() || n.state === 'joining';
    leave.onclick = () => leaveDialog();
    head.appendChild(leave);
  }
  box.appendChild(head);
  const say = (cls, text) => { const r = el('div', 'row nocheck'); r.appendChild(el('p', cls, text)); box.appendChild(r); return r; };
  // the last lines of the network's log (scrubbed by the agent): with a join that drags on or failed
  const logLines = () => {
    if (!(n.log || []).length) return;
    const r = el('div', 'row nocheck');
    r.appendChild(codeBox(n.log.join('\n')));
    box.appendChild(r);
  };
  if (NET_BUSY.includes(n.state)) {
    // one line: the wave, the step and the time since the start (the job's mark; starting has none: the helper's state);
    // after a few minutes: it drags on, and why it might — the log's last lines
    const job = n.job || {};
    const r = el('div', 'row nocheck ct-net-busy');
    const p = el('p', 'role running');          // .running shows the wave (office.css)
    p.append(el('span', 'spin'), ' ', n.state === 'starting' ? T('usonet.tip.starting') : T('usonet.step.' + (job.step || 'queued')));
    if (job.since) p.append(' · ', T('usonet.for', { time: fmt.duration(Math.max(1, Math.round(Date.now() / 1000) - job.since)) }));
    r.appendChild(p);
    box.appendChild(r);
    if (n.slow) {
      say('callout warn', T('usonet.slow', { time: fmt.duration(Math.max(1, Math.round(Date.now() / 1000) - (job.since || 0))) }));
      logLines();
    }
  } else if (n.state === 'failed') {
    say('callout warn', T('usonet.why.' + (n.why || 'no_contact')));
    if ((n.log || []).length) { say('role', T('usonet.log_lines')); logLines(); }
  } else if (n.state === 'refused') say('callout warn', T('usonet.why.' + (n.why || 'checksum')));
  else if (n.state === 'off') say('role', T('usonet.terms'));
  else if (['no_contact', 'down', 'needs_key'].includes(n.state)) {
    say('callout warn', T('usonet.' + n.state + '_text', { since: n.since ? T('usonet.since', { when: fmt.relative(n.since) }) : '' }));
  }
  netWatch(n);          // busy (joined or not yet): the page looks again in a few seconds
  if (!n.joined || !n.ips || !n.ips.length) return box;
  // this server in it: its name and address (the one to pick at «Add a partner…»), whether it carries the tag
  const me = Office.place('usonet:node', el('div', 'row nocheck ct-partner'));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', '🏠 ' + T('usonet.node', { name: n.name || n.node })));
  const meta = el('div', 'row-meta');
  meta.appendChild(n.tagged === false ? chip('danger', T('usonet.untagged_chip'), T('usonet.untagged')) : chip('ok', 'tag:uso', T('usonet.tagged_tip')));
  n.ips.forEach((a) => meta.appendChild(el('span', 'mono', a)));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T('usonet.address', { address: n.ips[0] })));
  if (n.foreign) main.appendChild(el('p', 'callout warn', T('usonet.foreign', { n: n.foreign })));
  me.appendChild(main);
  box.appendChild(me);
  // the other offices in the network
  if (!(n.peers || []).length) say('role', T('usonet.peers_none'));
  (n.peers || []).forEach((p) => {
    const r = Office.place(`usonet:${p.name}`, el('div', 'row nocheck ct-partner'));
    const m = el('div', 'row-main');
    m.appendChild(el('div', 'row-name text', '🏢 ' + p.name));
    const pm = el('div', 'row-meta');
    pm.appendChild(p.online ? chip('ok', T('usonet.peer_online'), T('usonet.peer_online_tip'))
      : chip('warn', T('usonet.peer_offline'), p.last_seen ? T('usonet.peer_offline_tip', { when: fmt.date(p.last_seen) }) : T('usonet.peer_offline_never')));
    // relayed is how every path starts (Tailscale goes direct once traffic flows; the agent sends a few pings): a plain
    // chip, never a warning (2026-10-23)
    if (p.online) pm.appendChild(p.direct ? chip('quiet', T('usonet.peer_direct'), T('usonet.peer_direct_tip'))
      : chip('quiet', T('usonet.peer_relay', { relay: p.relay || '?' }), T('usonet.peer_relay_tip')));
    if (!p.tagged) pm.appendChild(chip('danger', T('usonet.peer_untagged'), T('usonet.peer_untagged_tip')));
    (p.ips || []).slice(0, 1).forEach((a) => pm.appendChild(el('span', 'mono', a)));
    m.appendChild(pm);
    r.appendChild(m);
    box.appendChild(r);
  });
  // the policy lines (D6: shown and checked, never edited) — folded
  const det = keepFold(el('details', 'ct-net-policy'), 'usonet-policy', false);
  det.appendChild(el('summary', '', T('usonet.policy')));
  det.appendChild(el('p', 'role', T('usonet.step_policy')));
  det.appendChild(codeBox((n.policy || {}).tailscale || ''));
  det.appendChild(el('p', 'role', T('usonet.policy_headscale')));
  det.appendChild(codeBox((n.policy || {}).headscale || ''));
  const pr = el('div', 'row nocheck');
  pr.appendChild(det);
  box.appendChild(pr);
  return box;
}

/** «Join the USO network…»: what the network's owner does once, the key, the optional Headscale — then the agent's job */
function joinDialog(n) {
  const box = el('div');
  box.appendChild(el('p', '', T('usonet.join_text')));
  const ol = el('ol', 'ct-net-steps');
  const li1 = el('li');
  li1.append(el('span', '', T('usonet.step_policy')), codeBox((n.policy || {}).tailscale || ''));
  const li2 = el('li', '', T('usonet.step_key'));
  ol.append(li1, li2);
  box.appendChild(ol);
  const key = input('password', '', 'mono');
  key.setAttribute('autocomplete', 'off');
  key.spellcheck = false;
  key.placeholder = 'tskey-auth-…';
  box.appendChild(field(T('usonet.key'), key, T('usonet.key_help')));
  const control = input('text', n.control || '', 'mono');
  control.placeholder = 'https://headscale.example.org';
  control.setAttribute('autocomplete', 'off');
  box.appendChild(field(T('usonet.control'), control, T('usonet.control_help')));
  box.appendChild(el('p', 'callout', T('usonet.fetch_note')));
  box.appendChild(el('p', 'role', T('usonet.terms')));
  Office.dialog({
    title: T('usonet.join_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('usonet.join_go'), kind: '', act: async () => {
      const k = key.value.trim();
      if (!k) { key.focus(); return false; }
      const j = await Office.api.post(`${ID}.usonet_join`, { control: control.value.trim(), secret: { key: k } });
      key.value = '';
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
      Office.toast(T('usonet.join_started'));
      netSince = 0;
      netTook(j.usonet);
      return true;
    } }],
  });
  setTimeout(() => key.focus(), 0);
}

function leaveDialog() {
  Office.dialog({
    title: T('usonet.leave_title'),
    body: T('usonet.leave_text'),
    buttons: [{ text: Office.t('common.cancel') }, { text: T('usonet.leave_go'), kind: 'danger', act: async () => {
      const j = await Office.api.post(`${ID}.usonet_leave`, {});
      if (!j.ok) { Office.toast(Office.errorText(j.error, ID), true); return false; }
      Office.toast(T('usonet.leave_started'));
      netSince = 0;
      netTook(j.usonet);
      return true;
    } }],
  });
}

function chip(cls, text, tip) {
  const c = el('span', 'chip ' + cls, text);
  if (tip) c.title = tip;
  return c;
}

/** How a partner is, in one chip */
function stateChip(x) {
  // a key or a door gone (a plugin remove takes both): said at once, never the last «answers»
  if (x.key_missing) return chip('danger', T('partner.key_missing'), T('partner.key_missing_tip'));
  if (x.door === 'closed' || x.door === 'changed') return chip('danger', T('partner.door_' + x.door), T('partner.door_' + x.door + '_tip'));
  // it answers but doesn't know this pair any more (set up anew, its flash kept): never «answers» (2026-10-16)
  if (x.no_pair) return chip('danger', T('partner.no_pair'), T('partner.why.no_pair'));
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

/**
 * What a side of a pairing needs here (the agent's `ready`): sending needs Mr. Backupsy hired and set up with a backup
 * place, starting here from a copy needs Mr. Restori — said on the card and in the dialogs, with the way (hire him, or
 * his setup); a pairing itself is allowed at any time. Null: nothing missing
 */
function needLine(side) {
  const r = ready;
  if (!r) return null;
  const line = el('div', 'row-detail ct-need');
  const hireBtn = (id, key) => {
    const b = el('button', 'btn small', T(key));
    b.type = 'button';
    b.disabled = busyOff();
    b.onclick = async () => { b.disabled = true; if (await Office.hire([id])) { Office.toast(T('hired', { names: Office.t(`${id}.name`) })); if (ctx) ctx.reload(); } else b.disabled = false; };
    return b;
  };
  if (side === 'send') {
    if (r.backup_hired && r.place) return null;
    line.append(T('partner.need_backupsy'), ' ');
    if (!r.backup_hired) line.appendChild(hireBtn('backup', 'partner.hire_backupsy'));
    else { const go = el('a', 'ct-plink', T('partner.i_send_setup')); go.href = '#/backup/setup'; line.appendChild(go); }
    return line;
  }
  if (r.restore_hired) return null;
  line.append(T('partner.need_restori'), ' ');
  line.appendChild(hireBtn('restore', 'partner.hire_restori'));
  return line;
}

function card(x) {
  const r = Office.place(`partner:${x.id}`, el('div', 'row nocheck ct-partner'));
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', '🏢 ' + x.name));
  const meta = el('div', 'row-meta');
  meta.appendChild(stateChip(x));
  if (x.asking) { const a = el('span', 'ct-asking'); a.append(el('span', 'spin'), ' ', T('partner.asking', { name: x.name })); meta.appendChild(a); }
  if (x.array === 'stopped') meta.appendChild(chip('warn', x.night ? T('partner.night') : T('partner.array_stopped'), T('partner.array_stopped_tip')));
  // the trust a pair was made with (up to 1.71.0 a question; since then every new pair is «mine»): shown only when it says something
  if (x.trust && x.trust !== 'mine' && TRUST.includes(x.trust)) meta.appendChild(chip('quiet', T('partner.trust.' + x.trust), T('partner.trust_tip.' + x.trust)));
  if (x.public) meta.appendChild(chip('warn', T('partner.public'), T('partner.public_tip')));
  if (x.key_missing && (x.door === 'closed' || x.door === 'changed')) meta.appendChild(chip('danger', T('partner.door_' + x.door), T('partner.door_' + x.door + '_tip')));
  meta.appendChild(el('span', '', `${x.address}:${x.port}`));
  if (x.last_heard) meta.appendChild(el('span', '', T('partner.last_heard', { when: fmt.relative(x.last_heard) })));
  if (x.version) meta.appendChild(el('span', '', T('partner.version', { version: x.version })));
  main.appendChild(meta);
  const swap = switchHint(x);
  if (swap) main.appendChild(swap);

  // what I send (the levels in Mr. Backupsy's setup), and what they keep of it
  const send = el('div', 'row-detail');
  send.appendChild(el('strong', '', T('partner.i_send', { host: Office.config.host, name: x.name }) + ' '));
  // 1.60.1: an ended partnership's units wait for this pair in Mr. Backupsy's setup (apply_from) - said plainly, with the way
  const from = x.sends ? (x.apply_from || []) : [];
  const toSetup = () => { const go = el('a', 'ct-plink', T('partner.i_send_setup')); go.href = '#/backup/setup'; send.append(' ', go); };
  // 1.64: without Mr. Backupsy nothing goes — no «open the setup, apply» (the need line below says to hire him); the units
  // waiting for this pair are named by this pair's own name, never an ended partnership's old one
  const hired = !ready || ready.backup_hired !== false;
  if (!x.sends) send.append(T('partner.i_send_nothing'));
  else if ((x.send_units || []).length) send.append(unitsText(x.send_units));
  else if (!hired) send.append(T('partner.i_send_not_yet'));
  else if (!from.length) { send.append(T('partner.i_send_none_yet')); toSetup(); }
  if (hired && from.length) {
    if ((x.send_units || []).length) send.append(' · ');
    send.append(T('partner.i_send_apply', { name: x.name, n: from.reduce((a, f) => a + f.n, 0) }));
    toSetup();
  }
  main.appendChild(send);
  if (x.sends) { const need = needLine('send'); if (need) main.appendChild(need); }
  if (x.sends) sendLines(x).forEach((n) => main.appendChild(n));
  const tk = x.no_pair ? null : x.they_keep;         // what it said before it forgot this pair isn't how it is now
  if (x.no_pair) main.appendChild(el('div', 'row-detail', T('partner.no_pair_detail', { name: x.name })));
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
  if (lt && lt.mbit) main.appendChild(el('div', 'row-detail', lt.bytes ? T('partner.last_transfer', { size: fmt.size(lt.bytes), rate: fmt.bitrate(lt.mbit) }) : T('partner.mbit', { rate: fmt.bitrate(lt.mbit) })));

  // what I keep of theirs: every unit received (a unit never seen before is «new» — the consent is the pairing), the rules
  const keep = el('div', 'row-detail');
  keep.appendChild(el('strong', '', T('partner.i_keep', { host: Office.config.host, name: x.name }) + ' '));
  if (x.receive) {
    const units = x.i_keep_units || [];
    keep.append(units.length ? unitsText(units.map((u) => u.unit)) : T('partner.i_keep_none_yet', { name: x.name }));
    main.appendChild(keep);
    main.appendChild(el('div', 'row-detail', T(x.receive.quota_gb ? 'partner.i_keep_rules' : 'partner.i_keep_rules_nolimit', { pool: x.receive.pool, quota: x.receive.quota_gb ? fmt.size(x.receive.quota_gb * 1024 ** 3) : '',
      retention: retentionText(x.receive.retention) }) + ' ' + T('partner.wakes')));      // 1.60.2: always (the door wakes its pool)
    const needR = needLine('receive');
    if (needR) main.appendChild(needR);
    else if (units.length) main.appendChild(el('div', 'row-detail', T('partner.i_keep_start', { name: x.name })));
    if (x.named && x.named.units && x.named.units.length && units.length === 0) main.appendChild(el('div', 'row-detail', T('partner.named', { name: x.name, list: unitsText(x.named.units) })));
    const got = x.i_keep;
    if (got) {
      main.appendChild(quotaBar(got.used_bytes || 0, x.receive.quota_gb ? x.receive.quota_gb * 1024 ** 3 : 0));
      if (units.length) {
        const line = el('div', 'row-detail ct-got');
        units.forEach((u, i) => {
          if (i) line.append(' · ');
          line.append(T('partner.got', { unit: unitText(u.unit), n: u.snaps || 0, when: fmt.relative(u.time) }));
          if (u.new) line.append(' ', chip('accent', T('partner.unit_new'), T('partner.unit_new_tip', { name: x.name, when: fmt.relative(u.first) })));
          if (u.stale) line.append(' ', chip('warn', T('partner.unit_stale'), T('partner.unit_stale_tip', { name: x.name, when: fmt.relative(u.time) })));
        });
        main.appendChild(line);
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
    ask.disabled = busyOff() || !!x.asking;
    ask.title = T('partner.ask_title');
    ask.onclick = () => askNow(x, ask);
    acts.appendChild(ask);
  }
  const more = el('button', 'btn small plain', '⋯');
  more.type = 'button';
  more.setAttribute('aria-label', T('partner.more'));
  more.disabled = busyOff();
  more.onclick = (e) => Office.menu(e, [
    ...(x.sends ? [{ text: T('partner.levels', { host: Office.config.host }), act: () => Office.go('#/backup/setup') }] : []),
    ...(x.receive ? [{ text: T('partner.t_make', { name: x.name }), act: () => ticketMakeDialog(x) }] : []),
    { text: T('partner.address_change'), act: () => addressDialog(x) },
    { text: T('partner.renew'), act: () => renewDialog(x) },
    { separator: true },
    { text: T('partner.end'), kind: 'danger', act: () => endDialog(x) },
  ]);
  acts.appendChild(more);
  r.appendChild(acts);
  return r;
}

// ------------------------------------------------------------------ «Change the address…» (2026-10-23)
/* The first real pairing ended with both sides storing each other's LAN — two LANs apart, the pair reached neither way.
   Each side changes its own address of the other here, without pairing anew: the online USO peers to pick (the one of
   the partner's name first) or an address typed in; the agent looks for the partner's pinned host key there (a job),
   then stores it (pairs.json, known_hosts, the door's from=) — partner_address() in agent/lib/partner.php. */

/** The node name the partner's office has in the USO network (usonetNodeName(): StedyUnraid → uso-stedyunraid) */
const nodeName = (name) => ('uso-' + (String(name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'unraid')).slice(0, 63);
const ip4 = (ips) => (ips || []).find((a) => /^\d+\.\d+\.\d+\.\d+$/.test(a)) || '';

/** The card's line when its address is outside the USO network while the partner is online in it (`usonet_match`) */
function switchHint(x) {
  const m = x.usonet_match;
  if (!m || x.reachable === true) return null;
  const d = el('div', 'row-detail callout warn ct-switch');
  d.append(x.reachable === false ? T('partner.usonet_switch_unreached', { name: x.name, address: m.address })
    : T('partner.usonet_switch', { name: x.name, address: m.address }), ' ');
  const b = el('button', 'btn small', T('partner.usonet_switch_btn'));
  b.type = 'button';
  b.disabled = busyOff();
  b.onclick = () => addressDialog(x);
  d.appendChild(b);
  return d;
}

function addressDialog(x) {
  const n = netShown || {};
  const peers = n.state === 'in' ? (n.peers || []).filter((p) => p.online && p.tagged !== false && ip4(p.ips)) : [];
  const want = (x.usonet_match && x.usonet_match.name) || nodeName(x.name);
  const box = el('div');
  box.appendChild(el('p', '', T('partner.address_text', { name: x.name })));
  const now = el('p', 'role', T('partner.address_now', { address: `${x.address}:${x.port}` }));
  box.appendChild(now);
  const radios = [];
  const radio = (value, label, small, checked) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'ct-addr';
    r.value = value;
    r.checked = checked;
    const t = el('span', '', label);
    if (small) t.appendChild(small);
    l.append(r, t);
    radios.push(r);
    return l;
  };
  const opts = el('div', 'field ct-opts ct-peers');
  if (peers.length) {
    opts.appendChild(el('div', 'field-title', T('partner.address_peers')));
    const pre = peers.find((p) => p.name === want) || peers.find((p) => ip4(p.ips) === x.address);
    peers.forEach((p) => {
      const m = el('small');
      m.append(el('span', 'mono', ip4(p.ips)), ' · ', p.direct ? T('usonet.peer_direct') : T('usonet.peer_relay_short'));
      opts.appendChild(radio(ip4(p.ips), '🏢 ' + p.name, m, p === pre));
    });
  }
  const typed = input('text', peers.length ? '' : x.address, 'mono');
  typed.placeholder = T('partner.address_placeholder');
  opts.appendChild(radio('', T('partner.address_typed'), null, !radios.some((r) => r.checked)));
  opts.appendChild(typed);          // on a line of its own below its choice (inside the label it shrank to nothing)
  typed.addEventListener('input', () => { radios[radios.length - 1].checked = true; });     // typing picks it (the dialog focuses the field first)
  box.appendChild(opts);
  const port = input('number', x.port || 22);
  port.min = 1; port.max = 65535;
  box.appendChild(field(T('partner.port'), port));
  const out = el('div', 'ct-addr-out');
  box.appendChild(out);
  const chosen = () => {
    const r = radios.find((x2) => x2.checked);
    return { address: (r && r.value) || typed.value.trim(), port: Number(port.value) };
  };
  // the steps: pick → looking (the job) → saved | unreachable (save all the same) | wrong_key (pick again)
  let mode = 'pick';
  let tried = null;
  let d = null;
  const paint = () => {
    const b = d && d.buttons[1];
    if (!b) return;
    b.textContent = mode === 'saved' ? Office.t('common.close') : mode === 'unreachable' || mode === 'no_key'
      ? T(mode === 'no_key' ? 'partner.address_save' : 'partner.address_save_anyway') : T('partner.address_try');
    b.disabled = busyOff() || mode === 'looking';
    opts.querySelectorAll('input').forEach((i) => { i.disabled = mode === 'looking' || mode === 'saved'; });
    port.disabled = mode === 'looking' || mode === 'saved';
  };
  const say = (cls, text, more) => {
    out.replaceChildren();
    const p = el('p', cls);
    if (cls.includes('running')) p.append(el('span', 'spin'), ' ');
    p.append(text);
    out.appendChild(p);
    if (more) out.appendChild(el('p', 'role', more));
    if (d) d.fit();
  };
  const outcome = (look) => {
    const at = `${look.address}:${look.port}`;
    if (look.state === 'saved') {
      mode = 'saved';
      now.textContent = T('partner.address_now', { address: at });
      const mine = n.state === 'in' ? ip4(n.ips) : '';
      if (look.why === 'denied') say('callout warn', T('partner.address_saved_denied', { name: x.name, address: at }), mine ? T('partner.address_mine', { address: mine }) : '');
      else say('callout', T('partner.address_saved', { name: x.name, address: at }));
      Office.toast(T('partner.address_done', { name: x.name, address: at }));
    } else if (look.state === 'wrong_key') {
      mode = 'pick';
      say('callout warn', T('partner.address_wrong_key', { address: at }));
    } else {
      mode = 'unreachable';
      say('callout warn', T('partner.address_unreachable', { address: at, why: whyText(look.why || 'unreachable') }));
    }
    paint();
  };
  const follow = (since) => {
    setTimeout(async () => {
      if (!box.isConnected) return;
      const j = await Office.api.post(`${ID}.partner_address`, { id: x.id, step: 'look' });
      const look = j.ok ? j.look : null;
      if (look && look.state !== 'looking' && look.time >= since) {
        if (j.partners && ctx) ctx.took(j.partners);
        outcome(look);
      } else if (Date.now() - since * 1000 < 150000) follow(since);
      else outcome({ ...tried, state: 'unreachable', why: 'timeout' });
    }, 2000);
  };
  d = Office.dialog({
    title: T('partner.address_title', { name: x.name }),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.address_try'), kind: '', act: async () => {
      if (mode === 'saved') return true;
      const a = chosen();
      if (mode === 'unreachable' || mode === 'no_key') {
        const j = await Office.api.post(`${ID}.partner_address`, { id: x.id, step: 'save', confirm: true, address: a.address, port: a.port });
        if (!took(j)) return false;
        Office.toast(T('partner.address_done', { name: x.name, address: `${a.address}:${a.port}` }));
        return true;
      }
      const j = await Office.api.post(`${ID}.partner_address`, { id: x.id, step: 'try', address: a.address, port: a.port });
      if (!took(j)) return false;
      const look = j.look || {};
      tried = { address: a.address, port: a.port };
      if (look.state === 'no_key') {
        mode = 'no_key';
        say('callout', T('partner.address_no_key', { host: Office.config.host, name: x.name }));
      } else {
        mode = 'looking';
        say('callout running', T('partner.address_looking', { name: x.name, address: `${a.address}:${a.port}` }));
        follow(look.time || Math.floor(Date.now() / 1000));
      }
      setTimeout(paint, 0);          // after the dialog put its buttons back as they were
      return false;
    } }],
  });
  // a new choice after an answer starts over: look first
  opts.addEventListener('change', () => { if (mode === 'unreachable' || mode === 'no_key') { mode = 'pick'; out.replaceChildren(); paint(); } });
  typed.addEventListener('input', () => { if (mode === 'unreachable' || mode === 'no_key') { mode = 'pick'; out.replaceChildren(); paint(); } });
  port.addEventListener('input', () => { if (mode === 'unreachable' || mode === 'no_key') { mode = 'pick'; out.replaceChildren(); paint(); } });
  paint();
}

function pendingRow(o) {
  const r = el('div', 'row nocheck ct-partner ct-pending');
  const main = el('div', 'row-main');
  main.appendChild(el('div', 'row-name text', T('partner.pending', { id: o.id })));
  const meta = el('div', 'row-meta');
  meta.appendChild(chip('accent', T('partner.waiting'), T('partner.waiting_tip')));
  meta.appendChild(el('span', '', T('partner.offered', { when: fmt.relative(o.created) })));
  main.appendChild(meta);
  main.appendChild(el('div', 'row-detail', T('partner.pending_text')));
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

/* a list of radios or ticks under a title (.ct-opts): the office's label.check rows, control then label and its
   small - inside a .field office.css's «.field > label» (a field's title) would push the words to the right edge */
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

/**
 * This office's address for the partner: its own addresses to choose, or another typed in. `usonet`: the look's `usonet`
 * (partnerUsonet()) — in the network the WireGuard hint stays away (the way is the network; this picker then lives
 * folded under «Another address…»)
 */
function addressPicker(addresses, port, usonet) {
  const box = el('div');
  const sel = el('select', 'input');
  (addresses || []).forEach((a) => {
    const o = el('option', '', `${a.address} (${a.usonet ? T('usonet.picker') : a.iface})${a.private ? '' : ' — ' + T('partner.public')}`);
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
  const f = field(T('partner.address'), sel);
  f.appendChild(typed);
  // over WireGuard the tunnel's address (wg0, wg1 …) is the one to pick - it is listed only while the tunnel is up
  // (partnerMyAddresses: ip addr, the iface per address), so say when none is up right now (2026-10-08)
  const wg = (addresses || []).some((a) => /^wg\d*$/.test(String(a.iface || '')));
  if (!(usonet && usonet.in)) f.appendChild(el('small', '', T('partner.address_hint') + (wg ? '' : ' ' + T('partner.address_nowg'))));
  box.appendChild(f);
  box.appendChild(field(T('partner.port'), p, T('partner.port_help')));
  return { node: box, value: () => ({ address: sel.value || typed.value.trim(), port: Number(p.value) }) };
}

/**
 * This server's own address for the partner when it is in the USO network (2026-10-23, «pairing must be simple»): its
 * USO address and the office's door there, nothing to choose — said in one line; the old way (LAN, WireGuard, a public
 * address, another port) folded under «Another address…» and taken only while that fold is open. `key`: the fold's name
 * (kept while the page lives). Not in the network: the picker as always.
 * Joined or joining but its address not known yet (the first real pairing, 2026-10-23: a block with the LAN went out):
 * never the LAN instead — the line says to wait (the wave) and looks every few seconds; `gate(button)` keeps the
 * dialog's button off until the address is there or the fold is open. `other()`: the fold open — said to the agent,
 * which refuses any other address without it (partnerAddressChosen()).
 */
function myAddress(j, key) {
  const u = { ...(j.usonet || {}) };
  if (!(u.in && u.address) && !u.wait) {
    const addr = addressPicker(j.addresses, j.port, u);
    return { node: addr.node, value: addr.value, usonet: () => false, other: () => false, gate: () => {} };
  }
  const box = el('div', 'ct-myaddr');
  const line = el('p', 'callout');
  box.appendChild(line);
  const det = keepFold(el('details', 'ct-net-policy'), key, false);
  det.appendChild(el('summary', '', T('partner.address_other')));
  det.appendChild(el('p', 'role', T('partner.address_other_text')));
  const addr = addressPicker(j.addresses, j.port, { in: true });
  det.appendChild(addr.node);
  box.appendChild(det);
  let btn = null;
  const gateNow = () => { if (btn) btn.disabled = busyOff() || !(det.open || u.address); };
  const draw = () => {
    line.replaceChildren();
    if (u.address) {
      line.className = 'callout';
      line.append(el('strong', '', T('partner.via_usonet', { address: u.address })), ' ', T('partner.via_usonet_mine'));
    } else if (['joining', 'starting', 'in'].includes(u.state)) {
      line.className = 'callout running ct-net-wait';
      line.append(el('span', 'spin'), ' ', T('partner.usonet_wait'));
    } else {
      line.className = 'callout warn';
      line.append(T('partner.usonet_not_ready', { state: T('usonet.state.' + (u.state || 'down')) }));
    }
    gateNow();
  };
  det.addEventListener('toggle', gateNow);
  draw();
  if (!u.address) {
    // the network's own look (usonet_look) every few seconds while the dialog is open: its IPv4 once the node is in
    const look = async () => {
      if (!box.isConnected) return;          // the dialog closed
      const n = await Office.api.post(`${ID}.usonet_look`, {});
      if (n.ok && n.usonet) {
        const ip = n.usonet.state === 'in' ? (n.usonet.ips || []).find((a) => /^\d+\.\d+\.\d+\.\d+$/.test(a)) : null;
        u.state = n.usonet.state;
        if (ip) u.address = ip;
        draw();
        netTook(n.usonet);
      }
      if (!u.address) setTimeout(look, 3000);
    };
    setTimeout(look, 1500);
  }
  return {
    node: box,
    usonet: () => !det.open && !!u.address,
    other: () => det.open,
    gate: (b) => { btn = b; gateNow(); },
    value: () => (det.open ? addr.value() : { address: u.address, port: Number(u.port) || 22 }),
  };
}

/**
 * The partner's block brought an address outside the USO network while this server is in it (2026-10-23: so nostromo
 * stored its partner's LAN): said plainly — reached only in the same LAN — and, when that partner is online in the
 * network (the peer of its name, `usonet.match`), its USO address offered instead (ticked). Null when there is nothing
 * to say. `value()`: the address to take instead, or ''.
 */
function outsideNote(p, u) {
  if (!(u && u.in) || u.peer) return null;
  const box = el('div');
  box.appendChild(el('p', 'callout warn', T('partner.block_outside', { name: p.name, address: p.address })));
  if (!u.match) {
    box.appendChild(el('p', 'role', T('partner.block_outside_none', { name: p.name })));
    return { node: box, value: () => '' };
  }
  const take = tick(T('partner.block_outside_match', { name: p.name, address: u.match.address }), true,
    T('partner.block_outside_match_help', { peer: u.match.name }));
  take.node.classList.add('ct-outside-take');
  box.appendChild(take.node);
  return { node: box, value: () => (take.cb.checked ? u.match.address : '') };
}

/**
 * «Pair with»: the offices online in the USO network, one to pick (the partner this block is for — the block itself
 * carries only this server's address; the pick names the partner in what the page says and in the office's log).
 * Nobody online: said, with what to look at. Null when this server isn't in the network.
 */
function peerPicker(usonet) {
  const u = usonet || {};
  if (!(u.in && u.address)) return null;
  const box = el('div', 'field ct-opts ct-peers');
  box.appendChild(el('div', 'field-title', T('partner.pick_title')));
  const peers = (u.peers || []).filter((p) => p.online);
  if (!peers.length) {
    box.appendChild(el('p', 'callout', T('partner.pick_none')));
    return { node: box, value: () => '' };
  }
  box.appendChild(el('p', 'role', T('partner.pick_text')));
  const radios = peers.map((p, i) => {
    const l = el('label', 'check');
    const r = el('input');
    r.type = 'radio';
    r.name = 'ct-peer';
    r.value = p.name;
    r.checked = i === 0;
    const s = el('span', '', '🏢 ' + p.name);
    const m = el('small');
    m.append(el('span', 'mono', p.address), ' · ', p.direct ? T('usonet.peer_direct') : T('usonet.peer_relay_short'));
    s.appendChild(m);
    l.append(r, s);
    box.appendChild(l);
    return r;
  });
  return { node: box, value: () => (radios.find((r) => r.checked) || radios[0]).value };
}

/** The one sentence instead of «Who is the partner?» (2026-10-23): whoever runs the partner server can read the copies */
function trustNote() {
  return el('p', 'role', T('partner.trust_note'));
}

/**
 * What I keep of theirs — the consent is the pairing: any unit the partner sends, up to the quota, at any time. The rules:
 * the pool, the quota, the retention (the door always wakes its pool, 1.60.2). `name`: the partner (the text «I keep what {name} sends»).
 * A quota of 0 is said (no limit: the partner may fill the pool).
 */
function receivePicker(name, pools, defaults) {
  const box = el('div');
  box.appendChild(el('p', '', T('partner.consent', { name })));
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
  const open = el('p', 'callout warn', T('partner.quota_open', { name }));
  open.hidden = true;
  box.appendChild(open);
  const openLook = () => { open.hidden = Number(quota.value) !== 0; };
  quota.addEventListener('input', openLook);
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
  setTimeout(openLook, 0);
  return {
    node: box,
    look: openLook,
    value: () => ({
      pool: pool.value,
      quota_gb: Math.max(0, Math.round(Number(quota.value) || 0)),
      retention: [rd, rw, rm].map((i) => Math.max(0, Math.min(999, Math.round(Number(i.value) || 0)))).join(' '),
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
  // in the USO network: the partner picked from the offices online there, this server's USO address given without a
  // choice (the old way folded); else the address picker as always (2026-10-23)
  const pick = peerPicker(j.usonet);
  if (pick) box.appendChild(pick.node);
  const addr = myAddress(j, 'partner-add-other');
  box.appendChild(addr.node);
  box.appendChild(trustNote());
  box.appendChild(el('p', 'role', T('partner.add_levels')));
  { const need = needLine('send'); if (need) { need.className = 'callout'; box.appendChild(need); } }
  if (!(j.units || []).some((u) => u.ok)) box.appendChild(el('p', 'callout', T('partner.units_none')));
  const d = Office.dialog({
    title: T('partner.add_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.make_block'), kind: '', act: async () => {
      const a = addr.value();
      const peer = pick && addr.usonet() ? pick.value() : '';
      const req = { step: 'do', address: a.address, port: a.port };
      if (addr.other()) req.other = true;
      if (peer) req.peer = peer;
      const r = await Office.api.post(`${ID}.partner_add`, req);
      if (!took(r)) return false;
      if (ctx) ctx.reload();
      blockDialog(T('partner.block_a_title'), r.block, peer ? T('partner.block_a_text_peer', { name: peer }) : T('partner.block_a_text'), r.public);
      return true;
    } }],
  });
  addr.gate(d.buttons[1]);
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
    [T('partner.f_host_keys'), (p.host_keys || []).join('\n')],
    [T('partner.f_sends'), p.units && p.units.length ? T('partner.f_sends_units', { list: unitsText(p.units) }) : T('partner.f_sends_levels')],
  ]);
}

function acceptSettings(block, j) {
  const p = j.partner;
  if (j.self) { acceptSelf(block, j); return; }
  const box = el('div');
  box.appendChild(el('p', '', T('partner.accept_look', { name: p.name })));
  box.appendChild(partnerFacts(p));
  if (p.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  // in the USO network (or joining it) this server always answers with its own USO address and the office's door there,
  // nothing to choose (2026-10-23: until 1.71.1 only when the block's address was of a tailnet — else the LAN, and the
  // pair reached neither way); a block from outside the network is said, its sender's USO address offered instead.
  // Not in it while the block's address is of a tailnet — said, with the way
  const u = j.usonet || {};
  if (!u.in && !u.wait && u.range) box.appendChild(el('p', 'callout warn', T('partner.usonet_not_in', { name: p.name })));
  const outside = outsideNote(p, u);
  if (outside) box.appendChild(outside.node);
  const keepIt = tick(T('partner.keep_theirs', { name: p.name }), true);
  box.appendChild(keepIt.node);
  const recv = receivePicker(p.name, j.pools, j.defaults);
  box.appendChild(recv.node);
  keepIt.cb.onchange = () => { recv.node.hidden = !keepIt.cb.checked; };
  if (!j.pools.length) box.appendChild(el('p', 'callout warn', T('partner.no_pools')));
  const too = tick(T('partner.send_too'), false, T('partner.send_too_help', { name: p.name }));
  box.appendChild(too.node);
  const levels = el('p', 'role', T('partner.add_levels'));
  levels.hidden = true;
  box.appendChild(levels);
  const needSend = needLine('send');
  if (needSend) { needSend.className = 'callout'; needSend.hidden = true; box.appendChild(needSend); }
  const addr = myAddress(j, 'partner-accept-other');
  box.appendChild(addr.node);
  box.appendChild(trustNote());
  too.cb.onchange = () => { levels.hidden = !too.cb.checked; if (needSend) needSend.hidden = !too.cb.checked; };
  const d = Office.dialog({
    title: T('partner.accept_title'),
    body: box,
    wide: true,
    buttons: [{ text: Office.t('common.cancel') }, { text: T('partner.next'), kind: '', act: () => {
      const a = addr.value();
      const req = { step: 'do', block, confirm: true, address: a.address, port: a.port,
        receive: keepIt.cb.checked ? recv.value() : null, send_too: too.cb.checked };
      if (addr.other()) req.other = true;
      const theirs = outside ? outside.value() : '';
      if (theirs) req.their_address = theirs;
      if (!req.receive && !req.send_too) { Office.toast(T('errors.partner_nothing'), true); return false; }
      acceptConfirm(req, j);
      return true;
    } }],
  });
  addr.gate(d.buttons[1]);
}

function acceptConfirm(req, j) {
  const p = j.partner;
  const box = el('div');
  let line = null;
  if (req.receive) {
    const text = req.their_address ? j.line_match : j.line;       // the line lets them in from the address taken
    if (!text) { Office.toast(Office.errorText({ key: 'partner_unresolved', params: { address: p.address } }, ID), true); return; }
    line = linePart(text, p.name);
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
  const recv = receivePicker(p.name, j.pools, j.defaults);
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
    [T('partner.f_keeps'), keeps ? T('partner.they_keep_text', { pool: keeps.pool, quota: keeps.quota_gb ? fmt.size(keeps.quota_gb * 1024 ** 3) : T('partner.wish_no_quota'), retention: retentionText(keeps.retention) })
      : T('partner.they_keep_nothing')],
    [T('partner.f_sends'), p.key ? (p.units && p.units.length ? T('partner.f_sends_units', { list: unitsText(p.units) }) : T('partner.f_sends_levels')) : T('partner.nothing')],
  ]));
  if (p.public) box.appendChild(el('p', 'callout warn', T('partner.public_tip')));
  // its answer brought an address outside the USO network while this server is in it: said, its USO address offered (2026-10-23)
  const outside = outsideNote(p, j.usonet);
  if (outside) box.appendChild(outside.node);
  let recv = null;
  let keepIt = null;
  let line = null;
  if (p.key) {
    keepIt = tick(T('partner.keep_theirs', { name: p.name }), true);
    box.appendChild(keepIt.node);
    recv = receivePicker(p.name, j.pools, j.defaults);
    box.appendChild(recv.node);
    // the line lets them in from the address taken: theirs from the block, or their USO address ticked above
    const lines = { block: j.line ? linePart(j.line, p.name) : null, net: j.line_match ? linePart(j.line_match, p.name) : null };
    const pickLine = () => {
      line = outside && outside.value() && lines.net ? lines.net : lines.block;
      Object.values(lines).forEach((l) => { if (l) l.node.hidden = l !== line || !keepIt.cb.checked; });
    };
    Object.values(lines).forEach((l) => { if (l) box.appendChild(l.node); });
    if (outside) box.addEventListener('change', (e) => { if (e.target.closest('.ct-outside-take')) pickLine(); });
    keepIt.cb.onchange = () => { recv.node.hidden = !keepIt.cb.checked; pickLine(); };
    pickLine();
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
        const req = { step: 'do', block, confirm: true, code: j.code, receive: keep ? recv.value() : null };
        const theirs = outside ? outside.value() : '';
        if (theirs) req.their_address = theirs;
        const r = await Office.api.post(`${ID}.partner_finish`, req);
        if (!took(r)) return false;
        const a = r.ask;
        Office.toast(!a ? T('partner.paired', { name: p.name })
          : a.reachable ? T('partner.paired_ok', { name: p.name }) : T('partner.paired_silent', { name: p.name, why: T('partner.why.' + (a.why || 'unreachable')) }), !!a && !a.reachable);
        return true;
      } },
    ],
  });
}

// ------------------------------------------------------------------ an older partner (up to 1.59)
/**
 * The sender's card: a partner of today takes any unit (its status says `takes_all`); an older one keeps only what its
 * Team Lead agreed to (`agreed`) — then the units Mr. Backupsy sends it that aren't agreed are named, with what the
 * office asked it through its door (`offer`: its Team Lead sees the wish on its card), and that an update there ends it.
 */
function sendLines(x) {
  const out = [];
  const tk = x.they_keep;
  if (!x.older || !tk) return out;
  const sent = x.send_units || [];
  const missing = sent.filter((u) => !(tk.agreed || []).includes(u));
  if (!missing.length) return out;
  const o = x.offer;
  let asked = '';
  if (o && o.ok === false) asked = o.why === 'unknown_verb' ? T('partner.offer_old', { name: x.name }) : T('partner.offer_refused', { name: x.name, why: whyText(o.why) });
  else if (o && o.ok && o.time) asked = T('partner.offer_pending', { name: x.name, list: unitsText(missing), when: fmt.relative(o.time) });
  out.push(el('div', 'row-detail', T('partner.older', { name: x.name, list: unitsText(tk.agreed || []), missing: unitsText(missing) }) + (asked ? ' ' + asked : '')));
  return out;
}

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
  const r = Office.place(`ticket:${t.id}`, el('div', 'row nocheck ct-partner ct-ticket-card'));
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
  const r = Office.place(`ticket:${q.id}`, el('div', 'row nocheck ct-partner ct-pending'));
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
