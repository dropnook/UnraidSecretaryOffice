// ?noair: the pictures without their air (the CPU measurement's comparison with the first look).
// Fanboy's pictures with invented data: a home server with a disk cage and a case — no real server's names, serials or
// paths. Dev-only (tools/fanboy/, never in the package). The words here stand in for the desk's lang files: the
// components (public/desks/fanboy/fan-art.js) carry none of their own.
(function () {
  'use strict';
  const q = new URLSearchParams(location.search);
  const root = document.getElementById('fl-demo');
  const nf = new Intl.NumberFormat('en-US');
  const rpmText = (n) => `${nf.format(Math.round(n))} rpm`;
  const temp = (t) => `${t} °C`;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  function part(title, note) {
    const s = el('section');
    s.append(el('h3', null, title));
    if (note) s.append(el('p', 'fl-note', note));
    root.appendChild(s);
    return s;
  }

  // ---------------------------------------------------------------- twelve wheels: sizes, blades, speeds, states, colours
  const WHEELS = [
    { id: 'w1', label: 'Front top', mm: 120, blades: 7, min: 300, max: 1500, rpm: 1180, colour: 'graphite', flow: 'in' },
    { id: 'w2', label: 'Front bottom', mm: 120, blades: 9, min: 450, max: 2000, rpm: 640, flow: 'in' },
    { id: 'w3', label: 'Rear', mm: 140, blades: 9, min: 300, max: 1500, rpm: 900, colour: 'black' },
    { id: 'w4', label: 'Top', mm: 140, blades: 7, min: 400, max: 1600, rpm: 1450, colour: 'white' },
    { id: 'w5', label: 'Disk cage', mm: 92, blades: 7, min: 500, max: 2000, rpm: 1750, colour: 'grey' },
    { id: 'w6', label: 'Small', mm: 80, blades: 7, min: 400, max: 2200, rpm: 2100, colour: 'slate' },
    { id: 'w7', label: 'Big intake', mm: 200, blades: 9, min: 350, max: 800, rpm: 520, colour: 'accent' },
    { id: 'w8', label: 'Card cooler', mm: 40, blades: 5, min: 1500, max: 6000, rpm: 4800 },
    { id: 'w9', label: 'Slowest', mm: 120, blades: 11, min: 300, max: 1500, rpm: 310, colour: 'graphite' },
    { id: 'w10', label: 'Side', mm: 120, blades: 7, min: 300, max: 1500, rpm: 0, state: 'stopped', sub: '0 rpm · should run' },
    { id: 'w11', label: 'Bottom', mm: 120, blades: 7, rpm: null, state: 'unknown', sub: 'no feedback' },
    { id: 'w12', label: 'CPU cooler', mm: 120, blades: 7, min: 300, max: 1700, rpm: 1050, state: 'bios', badge: 'BIOS', colour: 'black' },
  ];
  const subOf = (w) => w.sub || (w.rpm == null ? '' : rpmText(w.rpm));
  const s1 = part('Fans', 'Each wheel hints at how fast its fan turns: it stands at 0 rpm and turns 0.12 to 1.1 times a second from the fan\'s lowest to its highest speed, eased, so slow looks slow and fast stays readable. Its air drifts away from it (or, for «Front top» and «Front bottom», comes to it), more and quicker the faster it turns. «Side» stands although it should run; «Bottom» gives no feedback; the BIOS runs «CPU cooler».');
  const row = el('div', 'fl-wheels');
  s1.appendChild(row);
  const wheels = (q.has('none') ? [] : WHEELS).map((w) => {     // ?none: the page without a wheel (the CPU baseline)
    const x = FanArt.wheel(Object.assign({}, w, { sub: subOf(w), title: `${w.label} · ${w.mm} mm · ${w.blades} blades` }, q.has('noair') ? { air: false } : {}));
    row.appendChild(x.el);
    return { w, x };
  });

  // ?only=movie: three wheels — slow, medium, fast — and a zone with its air (tools/fanboy/look.mjs records it)
  if (q.get('only') === 'movie') {
    row.replaceChildren();
    for (const w of wheels) w.x.destroy();
    const three = [['Slow', 340], ['Medium', 800], ['Fast', 1500]].map(([label, rpm]) => FanArt.wheel({ label, sub: rpmText(rpm), mm: 120, blades: 7, min: 300, max: 1500, rpm, colour: 'graphite' }));
    for (const x of three) row.appendChild(x.el);
    s1.querySelector('.fl-note').remove();
    const z = FanArt.server({ zones: [{ id: 'cage', name: 'Disk cage', fans: [
      { id: 'ft', label: 'Front top', mm: 120, blades: 7, min: 300, max: 1500, rpm: 1180, place: 'front', colour: 'graphite', sub: rpmText(1180) },
      { id: 'fb', label: 'Front bottom', mm: 120, blades: 7, min: 300, max: 1500, rpm: 1160, place: 'front', colour: 'graphite', sub: rpmText(1160) },
      { id: 'r', label: 'Rear', mm: 120, blades: 7, min: 300, max: 1500, rpm: 900, place: 'rear', colour: 'black', sub: rpmText(900) }],
    sources: [{ id: 'd1', label: 'Disk 1', kind: 'disk', temp: 36, limit: 45 }, { id: 'd2', label: 'Disk 2', kind: 'disk', temp: 38, limit: 45 },
      { id: 'd5', label: 'Disk 5', kind: 'disk', temp: 41, limit: 45 }, { id: 'p1', label: 'Parity', kind: 'disk', temp: 39, limit: 45 }] }] },
    { texts: { temp, asleep: 'asleep', warmest: 'the warmest of this zone' } });
    z.el.classList.add('fl-movie-zone');
    s1.appendChild(z.el);
    document.documentElement.dataset.catalog = '0';
    return;
  }

  if (q.get('only') === 'wheels') {
    window.flDemo = { wheels };
    if (!q.has('calm')) changing();
    return;
  }

  // ---------------------------------------------------------------- the server picture: two zones
  const pic = {
    zones: [
      {
        id: 'cage', name: 'Disk cage', note: 'Normal curve · Disk 5 at 41 °C asks for 52 %',
        fans: [
          { id: 'ft', label: 'Front top', mm: 120, blades: 7, min: 300, max: 1500, rpm: 1180, place: 'front', colour: 'graphite' },
          { id: 'fb', label: 'Front bottom', mm: 120, blades: 7, min: 300, max: 1500, rpm: 1160, place: 'front', colour: 'graphite' },
        ],
        sources: [
          { id: 'd1', label: 'Disk 1', kind: 'disk', temp: 36, limit: 45 },
          { id: 'd2', label: 'Disk 2', kind: 'disk', temp: 38, limit: 45 },
          { id: 'd3', label: 'Disk 3', kind: 'disk', temp: null, asleep: true, limit: 45, title: 'Asleep: not woken for a temperature' },
          { id: 'd4', label: 'Disk 4', kind: 'disk', temp: 37, limit: 45 },
          { id: 'd5', label: 'Disk 5', kind: 'disk', temp: 41, limit: 45 },
          { id: 'p1', label: 'Parity', kind: 'disk', temp: 39, limit: 45 },
        ],
      },
      {
        id: 'case', name: 'Case', note: 'Quiet curve · NVMe at 48 °C asks for 35 %',
        fans: [
          { id: 'top', label: 'Top', mm: 140, blades: 9, min: 300, max: 1500, rpm: 620, place: 'top', colour: 'black', flow: 'out' },
          { id: 'front', label: 'Front', mm: 140, blades: 9, min: 300, max: 1500, rpm: 700, place: 'front', colour: 'black', flow: 'in' },
          { id: 'rear', label: 'Rear', mm: 120, blades: 7, min: 300, max: 1500, rpm: 0, state: 'stopped', place: 'rear', colour: 'black', sub: '0 rpm · should run' },
          { id: 'cpu', label: 'CPU cooler', mm: 120, blades: 7, rpm: 1050, min: 300, max: 1700, state: 'bios', badge: 'BIOS', place: 'rear', colour: 'black' },
        ],
        sources: [
          { id: 'nvme', label: 'NVMe', kind: 'nvme', temp: 48, limit: 70 },
          { id: 'cpu', label: 'CPU', kind: 'cpu', temp: 44, limit: 80, title: 'The BIOS cools the CPU; Fanboy only watches it' },
          { id: 'ram', label: 'RAM', kind: 'ram', temp: 39, limit: 60 },
          { id: 'board', label: 'Board', kind: 'board', temp: 41, limit: 60 },
        ],
        warmest: 'nvme',
      },
    ],
  };
  for (const z of pic.zones) for (const f of z.fans) f.sub = f.sub || subOf(f);
  const s2 = part('Server', 'Each zone a piece of the case: its fans in the walls where the air comes in and goes out, the heat sources they answer to between them, the air drifting through. The warmest source of a zone is framed.');
  const server = FanArt.server(pic, { air: !q.has('noair'), texts: { temp, asleep: 'asleep', warmest: 'the warmest of this zone', none: 'no heat source' } });
  s2.appendChild(server.el);
  if (q.get('only') === 'server') {        // the CPU measurement of the server picture alone
    s1.remove();
    for (const w of wheels) w.x.destroy();
    window.flDemo = { server, pic };
    if (!q.has('calm')) changing(true);
    return;
  }

  // ---------------------------------------------------------------- the curve editor
  const s3 = part('Curve', 'Drag a point, or focus it and use the arrow keys (Shift: steps of 5, + adds one, Delete removes one). The wheel turns as the fan would at the temperature under the pointer.');
  const box = el('div', 'fl-box');
  s3.appendChild(box);
  const out = el('p', 'fl-note');
  const curveFan = { mm: 120, blades: 7, min: 300, max: 1500, colour: 'graphite' };
  const curve = FanArt.curve({
    points: [{ t: 30, v: 25 }, { t: 38, v: 35 }, { t: 44, v: 60 }, { t: 50, v: 100 }],
    tMin: 20, tMax: 60, floor: 20, now: 41, fan: curveFan,
    texts: {
      axisT: 'Temperature', axisV: 'Speed', tickT: (t) => `${t}°`, tickV: (v) => `${v} %`,
      point: (i, n) => `Point ${i + 1} of ${n}`, value: (t, v) => `${t} °C: ${v} %`,
      readout: (t, v, r) => `${t} °C → ${v} % · ${rpmText(r)}`, floor: 'lowest steady speed', now: 'now 41 °C',
    },
    onChange: (p) => { out.textContent = 'Changed: ' + p.map((x) => `${x.t}°→${x.v}%`).join(', '); },
  });
  box.append(curve.el, out);

  // ---------------------------------------------------------------- the catalogue picker
  const s4 = part('Which fan?', 'From the makers\' data sheets, or «Other fan» with the size by hand.');
  const pbox = el('div', 'fl-box');
  s4.appendChild(pbox);
  FanArt.catalog().then((cat) => {
    const picker = FanArt.picker({
      catalog: cat, value: { id: 'noctua-nf-a12x25-pwm' },
      texts: {
        brand: 'Maker', model: 'Model', other: 'Other fan', choose: 'Choose …', size: 'Size', blades: 'Blades',
        mm: (n) => `${n} mm`, option: (f) => `${f.model} · ${f.mm} mm`,
        facts: (f) => [f.mm ? `${f.mm} mm` : '', f.rpm_max ? (f.rpm_min != null ? `${nf.format(f.rpm_min)}–${nf.format(f.rpm_max)} rpm` : `up to ${nf.format(f.rpm_max)} rpm`) : '',
          f.blades ? `${f.blades} blades` : '', f.connector || ''].filter(Boolean).join(' · ') + (f.id ? '' : ' · measured once it runs'),
      },
    });
    pbox.appendChild(picker.el);
    document.documentElement.dataset.catalog = String(cat.fans.length);
  }).catch((e) => { pbox.textContent = String(e); });

  window.flDemo = { wheels, server, curve, pic };
  if (!q.has('calm')) changing();

  /** new speeds every few seconds, as the agent's look would bring them: they glide in */
  function changing(serverOnly) {
    let k = 0;
    setInterval(() => {
      k++;
      if (window.flDemo.server) {
        const p = JSON.parse(JSON.stringify(window.flDemo.pic));
        for (const z of p.zones) for (const f of z.fans) if (!f.state || f.state === 'run') { f.rpm = Math.round(f.min + (f.max - f.min) * (0.5 + 0.5 * Math.sin(k * 0.9 + f.mm / 37))); f.sub = rpmText(f.rpm); }
        window.flDemo.server.update(p);
      }
      if (serverOnly) return;
      for (const { w, x } of wheels) {
        if (w.state && w.state !== 'run') continue;
        const span = (w.max || 1500) - (w.min || 300);
        const rpm = Math.round((w.min || 300) + span * (0.5 + 0.5 * Math.sin(k * 0.9 + w.mm / 37)));
        x.update({ rpm, sub: rpmText(rpm) });
      }
    }, 3000);
  }
})();
