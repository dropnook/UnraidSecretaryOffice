// ?only=looks on the demo page (tools/fanboy/demo.html; dev-only, never in the package): every model of the catalogue
// drawn after its look at the picker's size, the states on a beige, a white and a black model, three pickers and a zone
// with a beige, a black and a white fan, one of them in alarm. The white fan is invented here (the catalogue lists each
// model in its own first colour). The words stand in for the desk's lang files.
(function () {
  'use strict';
  const q = new URLSearchParams(location.search);
  if (q.get('only') !== 'looks') return;
  const root = document.getElementById('fl-demo');
  const nf = new Intl.NumberFormat('en-US');
  const rpmText = (n) => `${nf.format(Math.round(n))} rpm`;
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  function part(title, note, id) {
    const s = el('section');
    if (id) s.id = id;
    s.append(el('h3', null, title));
    if (note) s.append(el('p', 'fl-note', note));
    root.appendChild(s);
    return s;
  }
  const WHITE = { frame: 'white', blades: 'white', hub: 'white', pads: null, style: 'swept-wide', shape: 'square', vanes: 0, rim: false, sticker: 'P12', sticker_colour: 'white' };
  const texts = {
    brand: 'Maker', model: 'Model', other: 'Other fan', choose: 'Choose …', size: 'Size', blades: 'Blades',
    mm: (n) => `${n} mm`, option: (f) => `${f.model} · ${f.mm} mm`,
    facts: (f) => [f.mm ? `${f.mm} mm` : '', f.rpm_max ? `up to ${nf.format(f.rpm_max)} rpm` : '', f.blades ? `${f.blades} blades` : ''].filter(Boolean).join(' · '),
  };

  FanArt.catalog().then((cat) => {
    const fans = cat.fans;
    const by = (id) => fans.find((f) => f.id === id);
    const wheelOf = (f, more) => Object.assign({ label: f.model, sub: `${f.brand} · ${f.mm} mm`, mm: f.mm, blades: f.blades, min: f.rpm_min, max: f.rpm_max,
      rpm: Math.round((f.rpm_max || 1500) * 0.55), look: f.look, px: 120, air: false, title: `${f.brand} ${f.model}` }, more || {});

    // the sheet: all models, maker by maker
    const sheet = part(`The catalogue's ${fans.length} models`, 'Each drawn after its model: the frame, the blades\' colour and character, the pads, the name on the hub — plain text, no logo.', 'fl-sheet');
    const grid = el('div', 'fl-wheels fl-sheet');
    sheet.appendChild(grid);
    for (const f of fans) grid.appendChild(FanArt.wheel(wheelOf(f)).el);

    // the states on a beige, a white and a black model, and the user's colour over a model's
    const st = part('States on a model\'s colours', 'Running, standing although it should run (red), no feedback (dashed), the BIOS\'s (dotted) — on a beige, a white and a black fan; last: a model with the colour «accent» chosen, and «other fan».', 'fl-states');
    const srow = el('div', 'fl-wheels');
    st.appendChild(srow);
    const f12 = by('noctua-nf-f12-pwm'), sw4 = by('be-quiet-silent-wings-4-120mm-pwm');
    const white = { brand: 'Demo', model: 'White fan', mm: 120, blades: null, rpm_min: 200, rpm_max: 1800, look: WHITE };
    const states = [['run', 'running'], ['stopped', '0 rpm · should run'], ['unknown', 'no feedback'], ['bios', 'BIOS']];
    for (const f of [f12, white, sw4]) {
      for (const [state, sub] of states) {
        srow.appendChild(FanArt.wheel(wheelOf(f, { state, sub, rpm: state === 'stopped' ? 0 : state === 'unknown' ? null : 900, badge: state === 'bios' ? 'BIOS' : '' })).el);
      }
    }
    srow.appendChild(FanArt.wheel(wheelOf(by('noctua-nf-a12x25-pwm'), { colour: 'accent', sub: 'colour: accent' })).el);
    srow.appendChild(FanArt.wheel({ label: 'Other fan', sub: '120 mm · 7 blades', mm: 120, blades: 7, rpm: 800, max: 1500, px: 120, air: false }).el);

    // three pickers
    const pk = part('The picker', 'Three models chosen.', 'fl-pickers');
    const prow = el('div', 'fl-pickers');
    pk.appendChild(prow);
    for (const id of ['noctua-nf-f12-pwm', 'arctic-p12-pwm-pst', 'be-quiet-silent-wings-4-120mm-pwm']) {
      const box = el('div', 'fl-box');
      box.appendChild(FanArt.picker({ catalog: cat, value: { id }, texts }).el);
      prow.appendChild(box);
    }

    // a zone with a beige, a black and a white fan, the black one in alarm
    const zp = part('A zone', 'Front: a beige and a white fan taking air in; rear: a black one that stands although it should run.', 'fl-zone');
    const zf = (f, more) => Object.assign(wheelOf(f), { px: undefined, air: undefined }, more);
    const zone = FanArt.server({ zones: [{ id: 'case', name: 'Case', note: '42 % · curve «quiet»', fans: [
      zf(f12, { id: 'f1', label: 'Front top', place: 'front', rpm: 1050, sub: rpmText(1050) }),
      zf(white, { id: 'f2', label: 'Front bottom', place: 'front', rpm: 1100, sub: rpmText(1100) }),
      zf(sw4, { id: 'r1', label: 'Rear', place: 'rear', rpm: 0, state: 'stopped', sub: '0 rpm · should run' })],
    sources: [{ id: 'cpu', label: 'CPU', kind: 'cpu', temp: 58, limit: 85 }, { id: 'd1', label: 'Disk 1', kind: 'disk', temp: 37, limit: 45 },
      { id: 'd2', label: 'Disk 2', kind: 'disk', temp: 39, limit: 45 }, { id: 'n1', label: 'NVMe', kind: 'nvme', temp: 47, limit: 70 }] }] },
    { px: 64, texts: { temp: (t) => `${t} °C`, asleep: 'asleep', warmest: 'the warmest of this zone', none: 'no heat source' } });
    zone.el.style.maxWidth = '640px';
    zp.appendChild(zone.el);
    document.documentElement.dataset.looks = String(fans.length);
  }).catch((e) => { root.textContent = String(e); });
})();
