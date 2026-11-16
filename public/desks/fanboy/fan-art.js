/* Fanboy's pictures (public/desks/fanboy/fan-art.js): the fan wheel, the server picture, the curve editor and the
   catalogue picker — drawn by code, no fixed text: every word comes from the caller (the desk's lang files), the
   components bring only numbers and shapes. Plain DOM, no Office.* needed; styles in fan-art.css beside this file
   (loaded once by FanArt.styles(), nested in #sso like every desk's). Everything shows inside #sso.

   window.FanArt = {
     wheel(fan)                → { el, update(fan), destroy() }        one fan, turning as fast as it really turns
     server(picture, opts)     → { el, update(picture), destroy() }    the zones with their fans and heat sources
     curve(opts)               → { el, set(points), destroy() }        temperature → percent (or rpm), points to drag
     picker(opts)              → { el, set(value), destroy() }        a fan from the catalogue, or «other fan» by hand
     catalog(url?)             → Promise<{ v, fans: [...] }>           fans-catalog.json beside this file
     turns(rpm, min, max)      → turns per second the picture shows (0, or 0.12 … 1.1, eased)
     COLOURS, STATES, PLACES, KINDS, FLOWS, SIDES,                     the keys the caller may pass
     LOOK_COLOURS, STYLES, SHAPES                                      … and a model's look may use
   }

   A fan (wheel(), and every entry of a zone's fans):
     { id, label, sub, title, badge,             words: name under the wheel, a line under it (rpm, percent …),
                                                 the tooltip, a small mark on the wheel («BIOS») — all optional
       mm: 40…200 (120), blades: 5…11 (7, or       how the real one looks
                                                 the look's style's own: STYLE_BLADES)
       rpm: number | null,                       measured speed; null = no feedback
       min, max: rpm range (catalogue or measured; unknown → 20 % … 100 % of 2000)
       state: 'run' | 'stopped' | 'unknown' | 'bios'
              run      turns as measured
              stopped  stands still although it should run: red frame
              unknown  no feedback (rpm null): stands, dashed frame
              bios     the BIOS controls it: turns as measured (when it reports), dotted frame, badge
       colour: 'auto' | 'black' | 'graphite' | 'grey' | 'white' | 'slate' | 'accent'   the wheel's colour (blades and hub):
                                                 'auto' = the model's (look) or the neutral one; any other wins over the look —
                                                 and paints a model's frame too («my copy is black»); 'accent' the wheel alone
       look: { frame, blades, hub, pads,         the model's own look (fans-catalog.json): colours from LOOK_COLOURS
               style, shape, vanes, rim,         (pads null = none), style from STYLES (the blades' character),
               sticker, sticker_colour }         shape from SHAPES (square with screw holes, round ring, corner pads),
                                                 vanes: fixed guide vanes in front (0 = none), rim: true = a closed wheel
                                                 (a ring round the blade tips), sticker: the model's name as
                                                 plain text on the hub (lines split by «\n», on a sticker of
                                                 sticker_colour; only where the wheel is drawn ≥ 72 px) — optional,
                                                 none = the neutral wheel («other fan»)
       place: 'front' | 'rear' | 'top' | 'bottom' | 'side'     (server picture only: the wall of its zone it sits in)
       flow: 'in' | 'out'                        the air: streaks coming to the wheel or leaving it, more and quicker the
                                                 faster it turns, none while it stands, calmer for a BIOS fan. Without a
                                                 flow they just leave it; in the server picture front and bottom fans take
                                                 air in, rear and top ones let it out
       side: 'right' | 'left' | 'top' | 'bottom' where the streaks are (wheel alone: right, left for flow in; the server
                                                 picture sets it from place) · air: false — no streaks at all
       caption: 'below' | 'right' | 'left'      where the words stand (default below; the server picture sets it)
       px: size in pixels (default from mm; in the server picture opts.px, 46) }

   The server picture (server()):
     { zones: [{
         id, name,                               the zone's key and its name
         note,                                   one line under the name (the curve, the percent it asks for) — optional
         fans: [fan, …],                         as above: `place` puts it in that wall, `flow` says which way the air
                                                 goes; the zone's own air drifts from its intake to its exhaust, as fast
                                                 as its fans turn on average
         sources: [{ id, label, kind, temp, asleep, limit, title, sub }],
                                                 kind: 'disk' | 'nvme' | 'cpu' | 'ram' | 'board' | 'gpu' | 'other';
                                                 temp °C or null; asleep: true for a sleeping disk (no temp read);
                                                 limit °C: from limit-5 the chip turns orange, from limit red
         warmest: source id                      optional; else the warmest temp of the zone (asleep and null left out)
       }, …] }
     opts.texts: { temp(t) → '41 °C', asleep → 'asleep', warmest → 'warmest', none → 'no heat source' }; opts.px: the wheels' size (46);
                 opts.air: false — no air at all (the zones' and the fans')

   The curve editor (curve()):
     opts = { points: [{ t: °C, v: percent or rpm }, …] (≥ 2), unit: 'percent' | 'rpm',
              tMin 20, tMax 80, vMin 0, vMax 100 (rpm: the fan's max), floor (lowest stable value, a dashed line),
              now (the zone's temperature now, a mark), fan: a wheel's fan (mm, blades, min, max, colour, look),
              rpmAt(percent) → rpm (the fan's measured table; else percent of max, never under min),
              apply(v) → the value as the control applies it (the fan's floor, its standstill): drawn bold and filled,
              the points' own line thin where he drags (none: the points are the truth),
              texts: { axisT, axisV, tickT(t), tickV(v), point(i, n) → aria label, value(t, v) → aria value text,
                       readout(t, v, rpm) → the line beside the wheel, floor, now },
              onInput(points) while dragging, onChange(points) when a change is done }
     Keyboard on a point: ←/→ temperature, ↑/↓ value (Shift ×5), + adds a point after it, Delete removes it (≥ 2 stay).
     Double click on the chart adds a point there. Points keep their order: the temperature between its neighbours,
     the value never below the point before it nor above the one after it.

   The picker (picker()):
     opts = { catalog: { fans: [...] }, value: { id } | { mm, blades } (other fan), colour (the user's, 'auto'),
              texts: { brand, model, other, choose, size, blades, mm(n) → '120 mm', option(fan) → model's line,
                       facts(fan) → a line about the chosen fan }, onChange(fan) }
     onChange gets { id, brand, model, mm, blades, rpm_min, rpm_max, connector, look } (other fan: id null, brand null,
     look null). The picker's wheel shows the chosen model's look in the user's colour; picker.colour(c) changes it. */
(function () {
  'use strict';
  if (window.FanArt) return;

  const SVG = 'http://www.w3.org/2000/svg';
  const COLOURS = ['auto', 'black', 'graphite', 'grey', 'white', 'slate', 'accent'];
  const STATES = ['run', 'stopped', 'unknown', 'bios'];
  const PLACES = ['front', 'rear', 'top', 'bottom', 'side'];
  const KINDS = ['disk', 'nvme', 'cpu', 'ram', 'board', 'gpu', 'other'];
  // a model's look: its colours by name (fan-art.css turns each into the office's palette), its blades' character, its frame
  const LOOK_COLOURS = ['black', 'charcoal', 'graphite', 'grey', 'silver', 'white', 'beige', 'brown', 'frost'];
  const DARK = ['black', 'charcoal', 'graphite', 'grey', 'brown'];       // a sticker of these carries light letters
  const STYLES = ['swept-narrow', 'swept-wide', 'broad', 'straight', 'sickle'];
  const SHAPES = ['square', 'ring', 'pads'];
  const STICKER_PX = 72;           // the model's name on the hub only where the wheel is at least this big
  const GLIDE_MS = 900;            // a new speed glides in over this long, never a jump
  const ME = document.currentScript && document.currentScript.src;

  const num = (v, d) => (typeof v === 'number' && isFinite(v) ? v : d);
  const clamp = (v, lo, hi) => Math.min(hi, Math.max(lo, v));
  const pick = (v, list, d) => (list.includes(v) ? v : d);
  const text = (v) => (v == null ? '' : String(v));
  function el(tag, cls, content) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (content != null) n.textContent = content;
    return n;
  }
  function svg(tag, attrs) {
    const n = document.createElementNS(SVG, tag);
    for (const k in attrs || {}) n.setAttribute(k, attrs[k]);
    return n;
  }

  /** fan-art.css beside this file, once (the desk loads only its desk.css by itself; src/page.php) */
  function styles() {
    if (!ME || document.getElementById('fan-art-css')) return;
    const l = document.createElement('link');
    l.id = 'fan-art-css';
    l.rel = 'stylesheet';
    l.href = ME.replace(/fan-art\.js(\?.*)?$/, (m, q) => `fan-art.css${q || ''}`);
    document.head.appendChild(l);
  }

  /**
   * The picture's speed: it hints at the real one, never 1:1. 0 rpm stands; the fan's min … max turns 0.12 … 1.1 times a
   * second, eased (the share of the range to the power 0.6: more difference in the slow half than at the top), so slow
   * looks pleasantly slow and the top stays readable (7 blades at 1.1 turns: 6.6° a frame at 60 Hz — no strobing, no
   * disc). Below min down to 0 in proportion, above max stays 1.1. Unknown range: 20 % … 100 % of 2000 rpm.
   */
  const SLOW = 0.12, FAST = 1.1, EASE = 0.6;
  function turns(rpm, min, max) {
    if (!(typeof rpm === 'number' && rpm > 0)) return 0;
    const hi = num(max, 0) > 0 ? max : 2000;
    const lo = num(min, 0) > 0 && min < hi ? min : hi * 0.2;
    if (rpm <= lo) return SLOW * rpm / lo;
    return SLOW + (FAST - SLOW) * Math.pow(Math.min(1, (rpm - lo) / (hi - lo)), EASE);
  }

  // ------------------------------------------------------------------------------------------------ the wheel
  /** Pixels for a fan of this size: a 200 mm fan bigger than an 80 mm one, but not 2.5 times */
  const pxOf = (mm) => Math.round(28 + 0.3 * mm);

  /**
   * The blades' character (a model's look.style): w the blade's width at the hub and wt at the tip (shares of the step
   * between two blades), s how far the tip is swept forward (share of the step + degrees), b how much the blade bows.
   * No style = the neutral wheel as it always was.
   */
  const BLADE = {
    '': { w: 0.55, wt: 0.8, s: 0.42, s0: 8, b: 1.04 },
    'swept-narrow': { w: 0.4, wt: 0.6, s: 0.62, s0: 10, b: 1.05 },      // many slim blades swept far (Noctua's A-series)
    'swept-wide': { w: 0.66, wt: 0.94, s: 0.5, s0: 9, b: 1.06 },        // few wide blades, overlapping at the tip (pressure)
    broad: { w: 0.7, wt: 0.84, s: 0.24, s0: 5, b: 1.02 },               // broad paddles, little sweep
    straight: { w: 0.48, wt: 0.56, s: 0.1, s0: 2, b: 1.0 },             // plain, nearly straight blades
    sickle: { w: 0.3, wt: 0.46, s: 0.95, s0: 16, b: 1.1 },              // long slim sickles
  };
  /** a style's blades where the maker doesn't say how many (the catalogue's blades null) */
  const STYLE_BLADES = { 'swept-narrow': 9, 'swept-wide': 5, broad: 7, straight: 7, sickle: 7 };
  /** One blade from the hub (rh) to the tip (rt) at angle a (degrees, 0 = up, clockwise), swept forward */
  function bladePath(a, n, rh, rt, style) {
    const step = 360 / n;
    const k = BLADE[style] || BLADE[''];
    const w = k.w * step, wt = k.wt * step, s = k.s * step + k.s0;
    const p = (r, deg) => {
      const q = (deg - 90) * Math.PI / 180;
      return `${(r * Math.cos(q)).toFixed(2)} ${(r * Math.sin(q)).toFixed(2)}`;
    };
    const mid = (rh + rt) / 2;
    return `M${p(rh, a)} Q${p(mid * k.b, a + s * 0.2)} ${p(rt, a + s)}`
      + ` A${rt} ${rt} 0 0 1 ${p(rt, a + s + wt)}`
      + ` Q${p(mid * 0.98, a + w + s * 0.62)} ${p(rh, a + w)}`
      + ` A${rh} ${rh} 0 0 0 ${p(rh, a)}Z`;
  }

  /** The hub's share of the blades' radius: a big fan's hub is smaller; a hub with a sticker at least 0.38 */
  const hubShare = (mm, sticker) => Math.max(clamp(0.42 - mm * 0.0009, 0.24, 0.4), sticker ? 0.38 : 0);
  /** The turning part: blades and hub, centred on 0 0 (viewBox -50 … 50) */
  function rotorSvg(mm, blades, style, sticker, rim) {
    const s = svg('svg', { viewBox: '-50 -50 100 100', 'aria-hidden': 'true', focusable: 'false' });
    const rt = 48.5, rh = rt * hubShare(mm, sticker);
    const g = svg('g', { class: 'fan-blades' });
    for (let i = 0; i < blades; i++) g.appendChild(svg('path', { d: bladePath(i * 360 / blades, blades, rh - 1, rt, style) }));
    s.appendChild(g);
    if (rim) s.appendChild(svg('circle', { class: 'fan-rim', r: rt - 1.4 }));       // a closed wheel: the blades held by a ring
    s.appendChild(svg('circle', { class: 'fan-hub', r: (rh + 0.5).toFixed(2) }));
    s.appendChild(svg('circle', { class: 'fan-hub2', r: (rh * 0.62).toFixed(2) }));
    return s;
  }

  /**
   * The housing: a rounded square with the round opening and the four screw holes (square), the same with a pad in each
   * corner (pads), or only a ring round the opening (ring)
   */
  const OPENING = 'M50 2.5A47.5 47.5 0 1 0 50 97.5A47.5 47.5 0 1 0 50 2.5Z';
  const CORNERS = [[8.5, 8.5], [91.5, 8.5], [8.5, 91.5], [91.5, 91.5]];
  function frameSvg(shape) {
    const s = svg('svg', { viewBox: '0 0 100 100', class: 'fan-frame', 'aria-hidden': 'true', focusable: 'false' });
    const ring = shape === 'ring';
    s.appendChild(svg('path', { class: 'fan-housing', 'fill-rule': 'evenodd',
      d: (ring ? 'M50 0.5A49.5 49.5 0 1 0 50 99.5A49.5 49.5 0 1 0 50 0.5Z ' : 'M11 1H89A10 10 0 0 1 99 11V89A10 10 0 0 1 89 99H11A10 10 0 0 1 1 89V11A10 10 0 0 1 11 1Z ') + OPENING }));
    s.appendChild(svg('circle', { class: 'fan-well', cx: 50, cy: 50, r: 47.5 }));
    if (ring) return s;
    for (const [x, y] of CORNERS) {
      if (shape === 'pads') {
        // a pad over the corner, cut round by the opening: a rounded square with the screw hole in it
        s.appendChild(svg('path', { class: 'fan-pad', d: padPath(x, y) }));
      }
      s.appendChild(svg('circle', { class: 'fan-screw', cx: x, cy: y, r: 2.6 }));
    }
    return s;
  }
  /** A corner pad: from the frame's corner to the opening's edge (an L of rubber, its inner side round) */
  function padPath(x, y) {
    const sx = x < 50 ? 1 : -1, sy = y < 50 ? 1 : -1;
    const cx = x < 50 ? 1.6 : 98.4, cy = y < 50 ? 1.6 : 98.4;               // the frame's outer corner
    const a = 17;                                                            // the pad's reach along each side
    const p = (dx, dy) => `${(cx + sx * dx).toFixed(2)} ${(cy + sy * dy).toFixed(2)}`;
    return `M${p(9, 0)}H${(cx + sx * a).toFixed(2)}Q${p(a - 1, 6)} ${p(10.5, 10.5)}Q${p(6, a - 1)} ${p(0, a)}V${(cy + sy * 9).toFixed(2)}A9 9 0 0 ${sx === sy ? 1 : 0} ${p(9, 0)}Z`;
  }

  /** What stands still in front of the blades: a model's guide vanes and the sticker with its name (viewBox 0 … 100) */
  function frontSvg(look, mm, px) {
    const s = svg('svg', { viewBox: '0 0 100 100', class: 'fan-front', 'aria-hidden': 'true', focusable: 'false' });
    const rh = 47.3 * hubShare(mm, stickerShown(look, px));          // the hub in these units (the rotor sits at 2.5 % inset)
    if (look && look.vanes) {
      const g = svg('g', { class: 'fan-vanes' });
      for (let i = 0; i < look.vanes; i++) {
        const a = (i * 360 / look.vanes - 90) * Math.PI / 180, b = a - 0.32;
        const at = (r, q) => `${(50 + r * Math.cos(q)).toFixed(2)} ${(50 + r * Math.sin(q)).toFixed(2)}`;
        g.appendChild(svg('path', { d: `M${at(rh, a)}Q${at((rh + 47) / 2, a - 0.1)} ${at(47, b)}` }));
      }
      s.appendChild(g);
    }
    const lines = stickerShown(look, px) ? look.sticker : null;
    if (lines) {
      const r = rh * 0.86;
      s.appendChild(svg('circle', { class: 'fan-sticker', cx: 50, cy: 50, r: r.toFixed(2) }));
      const long = Math.max(...lines.map((l) => l.length));
      const size = Math.min(2 * r * 0.9 / (long * 0.56), 2 * r * 0.78 / (lines.length * 1.02), 9);
      const t = svg('text', { class: 'fan-sticker-text', x: 50, 'text-anchor': 'middle', 'font-size': size.toFixed(2) });
      lines.forEach((l, i) => {
        const span = svg('tspan', { x: 50, y: (50 + (i - (lines.length - 1) / 2) * size * 1.02 + size * 0.36).toFixed(2) });
        span.textContent = l;
        t.appendChild(span);
      });
      s.appendChild(t);
    }
    return s;
  }
  const stickerShown = (look, px) => !!(look && look.sticker && px >= STICKER_PX);

  /** A model's look as the wheel may use it: only known names and shapes, null without one (the neutral wheel) */
  function lookOf(l) {
    if (!l || typeof l !== 'object') return null;
    const c = (v) => pick(v, LOOK_COLOURS, null);
    const sticker = typeof l.sticker === 'string' && l.sticker.trim()
      ? l.sticker.split('\n').map((x) => x.trim().slice(0, 10)).filter(Boolean).slice(0, 3) : null;
    return { frame: c(l.frame), blades: c(l.blades), hub: c(l.hub) || c(l.blades), pads: c(l.pads), style: pick(l.style, STYLES, ''),
      shape: pick(l.shape, SHAPES, 'square'), vanes: clamp(Math.round(num(l.vanes, 0)), 0, 15), rim: l.rim === true,
      sticker: sticker && sticker.length ? sticker : null, sticker_colour: pick(l.sticker_colour, LOOK_COLOURS, 'black') };
  }

  /** The still picture's speed (reduced motion): an arc round the opening, its length the speed */
  function arcSvg() {
    const s = svg('svg', { viewBox: '0 0 100 100', class: 'fan-arc', 'aria-hidden': 'true', focusable: 'false' });
    s.appendChild(svg('circle', { class: 'fan-arc-track', cx: 50, cy: 50, r: 47.5, pathLength: 100 }));
    s.appendChild(svg('circle', { class: 'fan-arc-bar', cx: 50, cy: 50, r: 47.5, pathLength: 100, transform: 'rotate(-90 50 50)', 'stroke-dasharray': '0 100' }));
    return s;
  }

  // Everything that moves (a wheel with its air, a zone's air) is a mover: its animations (transform and opacity only,
  // the browser's compositor turns them) run at one rate that glides to each new speed. All movers share one eye on the
  // screen, one ear on the tab and one on the user's motion setting: nothing runs off screen, in a hidden tab or with
  // reduced motion.
  const live = new Set();
  const reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  const eye = window.IntersectionObserver ? new IntersectionObserver((seen) => {
    for (const e of seen) {
      const m = e.target.fanMover;
      if (!m) continue;
      if (!e.target.isConnected) { m.destroy(); continue; }        // taken off the page without destroy(): let it go
      m.seen = e.isIntersecting;
      m.apply();
    }
  }, { rootMargin: '40px' }) : null;
  const all = (fn) => { for (const m of [...live]) fn(m); };
  document.addEventListener('visibilitychange', () => all((m) => (m.el.isConnected ? m.apply() : m.destroy())));
  if (reduce.addEventListener) reduce.addEventListener('change', () => all((m) => m.mode()));

  /** an endless animation of one element, its clock at phase (0 … 1 of a pass), paused until a mover runs it */
  function loop(node, frames, phase) {
    if (!node.animate) return null;
    const a = node.animate(frames, { duration: 1000, iterations: Infinity });
    a.currentTime = (phase || 0) * 1000;
    a.pause();
    return a;
  }

  /**
   * A mover on host: parts [{ a: animation, k: its speed per turn, on(rate): runs at this rate, rows: [{ nodes, on(rate) }]
   * shown at this rate }].
   * rate = the wheel's turns a second (0 stands); glide(to) moves it there over GLIDE_MS while it is seen.
   */
  function mover(host) {
    const m = { el: host, seen: !eye, rate: 0, target: 0, parts: [], raf: 0, onMode: null };
    host.fanMover = m;
    /** run what is shown at this rate; while gliding (some), the air takes the new rate only every few frames */
    m.apply = (some) => {
      const go = m.seen && !document.hidden && !reduce.matches;
      for (const p of m.parts) {
        if (!p.a) continue;
        const on = m.rate > 0.005 && p.on(m.rate);
        for (const r of p.rows || []) {           // the streaks shown at this rate (none while it stands)
          const show = on && r.on(m.rate);
          if (r.shown !== show) { for (const n of r.nodes) n.style.visibility = show ? '' : 'hidden'; r.shown = show; }
        }
        if (on && go) {
          if ((!some || p.rotor) && Math.abs(p.a.playbackRate - m.rate * p.k) > 1e-4) p.a.playbackRate = m.rate * p.k;
          if (p.a.playState !== 'running') p.a.play();
        } else if (p.a.playState === 'running') {
          p.a.pause();
        }
      }
    };
    m.glide = (to) => {
      m.target = to;
      cancelAnimationFrame(m.raf);
      if (reduce.matches || !m.seen || document.hidden || Math.abs(to - m.rate) < 0.005) {
        m.rate = to;
        m.apply();
        return;
      }
      const from = m.rate, t0 = performance.now();
      let frame = 0;
      const step = (now) => {
        const k = Math.min(1, (now - t0) / GLIDE_MS);
        m.rate = from + (to - from) * (1 - Math.pow(1 - k, 3));
        m.apply(k < 1 && ++frame % 4 !== 0);
        if (k < 1) m.raf = requestAnimationFrame(step);
      };
      m.raf = requestAnimationFrame(step);
    };
    m.mode = () => {
      if (reduce.matches) { cancelAnimationFrame(m.raf); m.rate = m.target; }
      if (m.onMode) m.onMode();
      m.apply();
    };
    /** drop the parts named by the test (all without one), their animations ended */
    m.drop = (test) => {
      m.parts = m.parts.filter((p) => {
        if (test && !test(p)) return true;
        if (p.a) p.a.cancel();
        return false;
      });
    };
    m.destroy = () => {
      cancelAnimationFrame(m.raf);
      m.drop();
      if (eye) eye.unobserve(host);
      live.delete(m);
      host.fanMover = null;
    };
    live.add(m);
    if (eye) eye.observe(host);
    return m;
  }

  // ---------------------------------------------------------------------------------------------------- the air
  const SIDES = ['left', 'right', 'top', 'bottom'];
  const FLOWS = ['in', 'out'];
  const AXIS = { left: ['x', -1], right: ['x', 1], top: ['y', -1], bottom: ['y', 1] };
  const tr = (axis, v) => (axis === 'x' ? `translateX(${v}%)` : `translateY(${v}%)`);
  /**
   * A stream of air in box: each streak ([across %, phase 0…1]) a small element of its own sliding along the axis the way
   * the air goes, fading in and out (transform and opacity: the compositor's work). Small layers on purpose: one strip
   * the stream wide, or a mask fading its ends, cost two to four times as much in Chrome (measured 2026-11-14). Its pass
   * runs from `from` to `to` % of its own length; k = passes a second per turn of the wheel. The faster the air, the more
   * streaks show (at least `least`); a hidden one rests.
   */
  function stream(m, box, axis, sign, rows, k, least, from, to, peak, hold) {
    const [a, b] = sign > 0 ? [from, to] : [to, from];
    const mid = hold ? [{ opacity: peak, offset: 0.15 }, { opacity: peak, offset: 0.85 }] : [{ opacity: peak, offset: 0.3 }];
    const frames = [{ transform: tr(axis, a), opacity: 0 }, ...mid, { transform: tr(axis, b), opacity: 0 }];
    rows.forEach(([across, phase], i) => {
      const s = el('span', 'fan-streak');
      s.style.setProperty('--fan-at', across + '%');
      box.appendChild(s);
      const on = (rate) => i < Math.max(least, Math.ceil(rows.length * Math.pow(Math.min(1, rate / FAST), 1.4)));
      m.parts.push({ air: true, a: loop(s, frames, phase), k, on, rows: [{ nodes: [s], shown: true, on }] });
    });
  }

  /**
   * A wheel's air: up to four fine streaks on its outside (side), leaving the wheel (out, and without a flow) or coming
   * to it (in); the faster it turns, the more of them and the quicker; none while it stands; a BIOS fan's calmer.
   */
  const STREAKS = [[24, 0], [72, 0.5], [46, 0.25], [88, 0.75]];          // across %, phase
  function wheelAir(m, box, side, flow, calm) {
    m.drop((p) => p.air);
    const old = box.querySelector('.fan-air');
    if (old) old.remove();
    if (!side) return;
    const [axis, toward] = AXIS[side];
    const air = el('div', `fan-air fan-air-${side} fan-air-${axis}${calm ? ' fan-air-calm' : ''}`);
    air.setAttribute('aria-hidden', 'true');
    // a streak is 45 % of the stream long: from -40 % to 180 % of itself it crosses it
    stream(m, air, axis, flow === 'in' ? -toward : toward, STREAKS.slice(0, calm ? 2 : 4), calm ? 1.0 : 1.7, 1, -40, 180, calm ? 0.6 : 0.9, false);
    box.appendChild(air);
  }

  function wheel(fan) {
    styles();
    let f = {};
    const box = el('div', 'fan-box');
    const rotor = el('div', 'fan-rotor');
    const badge = el('span', 'fan-badge');
    const arc = arcSvg();
    const root = el('figure', 'fan-wheel');
    const cap = el('figcaption', 'fan-cap');
    const label = el('span', 'fan-label'), sub = el('span', 'fan-sub');
    cap.append(label, sub);
    let frame = frameSvg('square'), front = frontSvg(null, 120, 0);
    box.append(frame, rotor, front, arc, badge);
    root.append(box, cap);
    let drawn = '', aired = '', framed = '', fronted = '', painted = '';
    const m = mover(root);
    const w = { el: root, mover: m };
    root.fanWheel = w;
    m.onMode = () => root.classList.toggle('fan-still', !!reduce.matches);

    w.update = (next) => {
      f = Object.assign({}, f, next || {});
      const mm = clamp(Math.round(num(f.mm, 120)), 40, 200);
      const look = lookOf(f.look);
      const blades = clamp(Math.round(num(f.blades, (look && STYLE_BLADES[look.style]) || 7)), 5, 11);
      const state = pick(f.state, STATES, 'run');
      const px = Math.round(num(f.px, pxOf(mm)));
      const colour = pick(f.colour, COLOURS, 'auto');
      const own = !!(look && colour === 'auto');           // the model paints its wheel unless the user chose a colour
      root.className = `fan-wheel fan-c-${colour} fan-s-${state}` + (look ? ' fan-look' : '') + (own ? ' fan-look-wheel' : '')
        + (reduce.matches ? ' fan-still' : '') + (f.caption === 'right' || f.caption === 'left' ? ` fan-cap-${f.caption}` : '')
        + (f.air !== false && f.caption !== 'right' && f.caption !== 'left' ? ` fan-airy-${pick(f.side, SIDES, f.flow === 'in' ? 'left' : 'right')}` : '')
        + (f.cls ? ' ' + f.cls : '');
      root.style.setProperty('--fan-px', px + 'px');
      const style = look ? look.style : '';
      const sticker = stickerShown(look, px);
      const rim = !!(look && look.rim);
      if (drawn !== `${mm}/${blades}/${style}/${sticker}/${rim}`) {      // the shape only when it changed: the turning goes on
        drawn = `${mm}/${blades}/${style}/${sticker}/${rim}`;
        rotor.replaceChildren(rotorSvg(mm, blades, style, sticker, rim));
      }
      const shape = look ? look.shape : 'square';
      if (framed !== shape) { framed = shape; const n = frameSvg(shape); frame.replaceWith(n); frame = n; }
      const frontKey = look ? `${mm}/${look.vanes}/${sticker ? look.sticker.join('|') : ''}` : '';
      if (fronted !== frontKey) { fronted = frontKey; const n = frontSvg(look, mm, px); front.replaceWith(n); front = n; }
      // the model's colours: named ones (fan-art.css makes each from the office's palette), the wheel's only when it is his
      // the user's colour on a model says «my copy is another colour than the standard one» (a black edition of a beige
      // fan): it paints the whole fan — frame, blades and hub —; the model keeps its shape, pads and name. «accent» stays
      // a mark on the wheel alone (the page's «this one runs now»).
      const edition = !!(look && colour !== 'auto' && colour !== 'accent');
      const paint = look ? { '--fan-frame': edition ? null : look.frame, '--fan-pad': look.pads, '--fan-tag': look.sticker_colour,
        '--fan-blade': own ? look.blades : null, '--fan-hub': own ? look.hub : null } : {};
      const paintKey = JSON.stringify(paint);
      if (painted !== paintKey) {
        painted = paintKey;
        for (const v of ['--fan-frame', '--fan-pad', '--fan-tag', '--fan-blade', '--fan-hub']) {
          if (paint[v]) root.style.setProperty(v, `var(--fan-k-${paint[v]})`); else root.style.removeProperty(v);
        }
        if (look) root.style.setProperty('--fan-tag-ink', DARK.includes(look.sticker_colour) ? 'var(--gray-100)' : 'var(--gray-800)');
        else root.style.removeProperty('--fan-tag-ink');
      }
      if (!m.parts.some((p) => p.rotor)) {
        m.parts.unshift({ rotor: true, a: loop(rotor, [{ transform: 'rotate(0turn)' }, { transform: 'rotate(1turn)' }], 0), k: 1, on: () => true });
      }
      // the air: on the side given (default: right, or left for air coming in), none with air: false
      const flow = pick(f.flow, FLOWS, null);
      const side = f.air === false ? null : pick(f.side, SIDES, flow === 'in' ? 'left' : 'right');
      const airKey = `${side}/${flow}/${state === 'bios'}`;
      if (aired !== airKey) { aired = airKey; wheelAir(m, box, side, flow, state === 'bios'); }
      label.textContent = text(f.label);
      sub.textContent = text(f.sub);
      label.hidden = !f.label;
      sub.hidden = !f.sub;
      badge.textContent = text(f.badge);
      badge.hidden = !f.badge;
      if (f.title) root.title = f.title; else root.removeAttribute('title');
      if (f.id != null) root.dataset.id = f.id;
      const rpm = state === 'stopped' || state === 'unknown' ? 0 : num(f.rpm, 0);
      const max = num(f.max, 0) > 0 ? f.max : 2000;
      const share = clamp(rpm / max, 0, 1);
      arc.lastChild.setAttribute('stroke-dasharray', `${(share * 100).toFixed(1)} 100`);
      arc.lastChild.setAttribute('visibility', share > 0 ? 'visible' : 'hidden');     // a round cap would leave a dot at 0
      m.glide(turns(rpm, f.min, f.max));
      return w;
    };
    w.destroy = () => { m.destroy(); root.fanWheel = null; };
    w.update(fan);
    return w;
  }

  // ------------------------------------------------------------------------------------------- the server picture
  /** a heat source's mark: a small drawing per kind (no words) */
  const KIND_PATHS = {
    disk: 'M3 2h10v12H3z M8 9.5a2.5 2.5 0 1 0 0.01 0z',
    nvme: 'M1 5h14v6H1z M3 7h2v2H3z M7 7h2v2H7z M11 7h2v2h-2z',
    cpu: 'M4 4h8v8H4z M6 1v3 M10 1v3 M6 12v3 M10 12v3 M1 6h3 M1 10h3 M12 6h3 M12 10h3',
    ram: 'M1 5h14v5H1z M3 10v2 M6 10v2 M10 10v2 M13 10v2 M4 7h2 M10 7h2',
    board: 'M2 2h12v12H2z M5 5h3v3H5z M10 5v6 M5 11h3',
    gpu: 'M1 4h14v8H1z M8 8a2.5 2.5 0 1 0 0.01 0z M3 12v2',
    other: 'M8 2v8 M8 10a2.5 2.5 0 1 0 0.01 0z',
  };
  function kindIcon(kind) {
    const s = svg('svg', { viewBox: '0 0 16 16', class: 'fan-kind', 'aria-hidden': 'true', focusable: 'false' });
    s.appendChild(svg('path', { d: KIND_PATHS[kind] || KIND_PATHS.other }));
    return s;
  }
  /** the zone's warmest source: the caller's, else the highest temperature (asleep and unknown left out) */
  function warmestOf(zone) {
    const list = Array.isArray(zone.sources) ? zone.sources : [];
    if (zone.warmest != null && list.some((s) => s.id === zone.warmest)) return zone.warmest;
    let best = null;
    for (const s of list) if (!s.asleep && typeof s.temp === 'number' && (!best || s.temp > best.temp)) best = s;
    return best ? best.id : null;
  }

  // The zone as a piece of a case: its walls a frame, the fans on the wall they sit in (place), the heat sources between
  // them, the air drifting through from the intake to the exhaust. A fan without flow: front and bottom take air in,
  // rear and top let it out (the usual case). On a phone the zone stands upright: front fans on the top wall, rear ones on
  // the bottom wall, the air from top to bottom.
  const narrow = window.matchMedia ? window.matchMedia('(max-width:640px)') : { matches: false };
  const WALL = { wide: { front: 'left', rear: 'right', top: 'top', side: 'top', bottom: 'bottom' },
    tall: { front: 'top', rear: 'bottom', top: 'top', side: 'top', bottom: 'bottom' } };
  const flowAt = (fan, place) => pick(fan.flow, FLOWS, place === 'front' || place === 'bottom' ? 'in' : 'out');
  /** the zone's air: [axis, sign] from what its fans take in and let out (each fan pushes along its wall's normal) */
  function zoneWay(fans, wall) {
    let x = 0, y = 0;
    for (const fan of fans) {
      const place = pick(fan.place, PLACES, 'front');
      const [axis, toward] = AXIS[wall[place]];
      const v = flowAt(fan, place) === 'out' ? toward : -toward;
      if (axis === 'x') x += v; else y += v;
    }
    if (!x && !y) return wall === WALL.tall ? ['y', 1] : ['x', 1];
    return Math.abs(x) >= Math.abs(y) ? ['x', x > 0 ? 1 : -1] : ['y', y > 0 ? 1 : -1];
  }
  /** how fast the zone's air goes: the mean of its fans' pictures (standing ones count 0, a BIOS fan's at half) */
  function zoneRate(fans) {
    if (!fans.length) return 0;
    let sum = 0;
    for (const fan of fans) {
      const state = pick(fan.state, STATES, 'run');
      if (state === 'stopped' || state === 'unknown') continue;
      sum += turns(num(fan.rpm, 0), fan.min, fan.max) * (state === 'bios' ? 0.5 : 1);
    }
    return sum / fans.length;
  }
  const ZONE_STREAKS = [[14, 0], [60, 0.55], [36, 0.3], [82, 0.8], [24, 0.15], [70, 0.65], [48, 0.42]];
  function zoneAir(m, layer, axis, sign) {
    m.drop();
    layer.replaceChildren();
    layer.className = `fan-flow fan-flow-${axis}`;
    // a streak is 20 % of the zone long: from -110 % to 560 % of itself it crosses it
    stream(m, layer, axis, sign, ZONE_STREAKS, 0.32, 2, -110, 560, 1, true);
  }

  function server(picture, opts) {
    styles();
    const o = opts || {};
    const tx = Object.assign({ temp: (t) => String(t), asleep: '', warmest: '', none: '' }, o.texts || {});
    const px = num(o.px, 46);
    const root = el('div', 'fan-server');
    let wheels = new Map(), flows = new Map(), last = picture;

    function chip(s, warm) {
      const c = el('span', `fan-src fan-k-${pick(s.kind, KINDS, 'other')}`);
      const t = typeof s.temp === 'number' ? s.temp : null;
      const lim = num(s.limit, null);
      if (lim != null && t != null) c.classList.add(t >= lim ? 'fan-hot' : t >= lim - 5 ? 'fan-warm' : 'fan-cool');
      if (s.asleep) c.classList.add('fan-asleep');
      if (warm) c.classList.add('fan-warmest');
      if (s.id != null) c.dataset.id = s.id;
      c.append(kindIcon(s.kind), el('span', 'fan-src-name', text(s.label)));
      c.appendChild(el('span', 'fan-src-temp', t != null ? tx.temp(t) : s.asleep ? tx.asleep : '–'));
      if (s.sub) c.appendChild(el('span', 'fan-src-sub', text(s.sub)));
      const tip = [s.title, warm ? tx.warmest : ''].filter(Boolean).join(' · ');
      if (tip) c.title = tip;
      if (warm && tx.warmest) c.setAttribute('aria-label', `${text(s.label)} ${t != null ? tx.temp(t) : ''} · ${tx.warmest}`);
      return c;
    }

    function draw(p) {
      last = p;
      const wall = narrow.matches ? WALL.tall : WALL.wide;
      const keep = new Map(), keepFlows = new Map();
      root.replaceChildren();
      const zones = Array.isArray(p && p.zones) ? p.zones : [];
      zones.forEach((z, zi) => {
        const zid = z.id != null ? String(z.id) : String(zi);
        const fans = Array.isArray(z.fans) ? z.fans : [];
        const box = el('section', 'fan-zone');
        box.dataset.id = zid;
        const head = el('header', 'fan-zone-head');
        head.appendChild(el('h4', 'fan-zone-name', text(z.name)));
        if (z.note) head.appendChild(el('span', 'fan-zone-note', text(z.note)));
        box.appendChild(head);
        const area = el('div', 'fan-zone-area');
        // the air first: behind the walls' fans and the chips
        let fl = flows.get(zid);
        if (!fl || !fl.m.el.fanMover) {
          const layer = el('div', 'fan-flow');
          layer.setAttribute('aria-hidden', 'true');
          fl = { layer, m: mover(layer), way: '' };
        }
        flows.delete(zid);
        keepFlows.set(zid, fl);
        const [axis, sign] = zoneWay(fans, wall);
        if (o.air === false) { fl.m.drop(); fl.layer.replaceChildren(); fl.way = ''; } else if (fl.way !== `${axis}${sign}`) { fl.way = `${axis}${sign}`; zoneAir(fl.m, fl.layer, axis, sign); }
        area.appendChild(fl.layer);
        const sides = {};
        for (const w of SIDES) {
          sides[w] = el('div', `fan-side fan-wall-${w}`);
          area.appendChild(sides[w]);
        }
        const mid = el('div', 'fan-heat');
        area.appendChild(mid);
        fans.forEach((fan, fi) => {
          const key = `${zid}/${fan.id != null ? fan.id : fi}`;
          const place = pick(fan.place, PLACES, 'front');
          const side = wall[place];
          const look = Object.assign({ px }, fan, { side, flow: flowAt(fan, place), caption: side === 'right' ? 'left' : 'right' },
            o.air === false ? { air: false } : {});
          let w = wheels.get(key);
          if (w && w.el.fanWheel) w.update(look); else w = wheel(look);
          wheels.delete(key);
          keep.set(key, w);
          sides[side].appendChild(w.el);
        });
        for (const w of SIDES) {
          if (sides[w].childElementCount) area.classList.add(`fan-has-${w}`); else sides[w].remove();
        }
        const warm = warmestOf(z);
        const list = Array.isArray(z.sources) ? z.sources : [];
        if (!list.length && tx.none) mid.appendChild(el('span', 'fan-src-none', tx.none));
        for (const s of list) mid.appendChild(chip(s, s.id != null && s.id === warm));
        box.appendChild(area);
        root.appendChild(box);
        fl.m.glide(zoneRate(fans));
      });
      for (const w of wheels.values()) w.destroy();      // fans and zones that are gone
      for (const f of flows.values()) f.m.destroy();
      wheels = keep;
      flows = keepFlows;
    }
    const turn = () => draw(last);                       // phone ↔ desk: the zones stand up or lie down
    if (narrow.addEventListener) narrow.addEventListener('change', turn);
    draw(picture);
    return {
      el: root,
      update: (p) => draw(p),
      destroy() {
        for (const w of wheels.values()) w.destroy();
        for (const f of flows.values()) f.m.destroy();
        wheels.clear();
        flows.clear();
        if (narrow.removeEventListener) narrow.removeEventListener('change', turn);
      },
    };
  }

  // ------------------------------------------------------------------------------------------- the curve editor
  const PL = 46, PR = 14, PT = 14, PB = 32;     // the chart's room for its scales; its size is the size it is shown at

  function curve(opts) {
    styles();
    const o = Object.assign({ unit: 'percent', tMin: 20, tMax: 80 }, opts || {});
    const rpm = o.unit === 'rpm';
    const fan = Object.assign({ mm: 120, blades: 7 }, o.fan || {});
    const vMin = num(o.vMin, 0), vMax = num(o.vMax, rpm ? num(fan.max, 2000) : 100);
    const tMin = num(o.tMin, 20), tMax = num(o.tMax, 80);
    const vStep = rpm ? 10 : 1;
    const tx = Object.assign({ axisT: '', axisV: '', tickT: (t) => String(t), tickV: (v) => String(v),
      point: () => '', value: (t, v) => `${t} ${v}`, readout: () => '', floor: '', now: '' }, o.texts || {});
    let pts = [];
    let W = 600, H = 300;

    const x = (t) => PL + (t - tMin) / (tMax - tMin) * (W - PL - PR);
    const y = (v) => PT + (1 - (v - vMin) / (vMax - vMin)) * (H - PT - PB);
    const tAt = (px) => tMin + (px - PL) / (W - PL - PR) * (tMax - tMin);
    const vAt = (py) => vMin + (1 - (py - PT) / (H - PT - PB)) * (vMax - vMin);
    const snapV = (v) => clamp(Math.round(v / vStep) * vStep, vMin, vMax);
    const snapT = (t) => clamp(Math.round(t), tMin, tMax);
    /** the curve's value at a temperature: flat before the first and after the last point, straight between them */
    function valueAt(t) {
      if (!pts.length) return vMin;
      if (t <= pts[0].t) return pts[0].v;
      for (let i = 1; i < pts.length; i++) {
        if (t <= pts[i].t) {
          const a = pts[i - 1], b = pts[i];
          return a.v + (b.v - a.v) * (t - a.t) / ((b.t - a.t) || 1);
        }
      }
      return pts[pts.length - 1].v;
    }
    /** the fan's rpm at a value: rpm as it is; a percent through the fan's table, else percent of max, never under min */
    function rpmOf(v) {
      if (rpm) return v;
      if (typeof o.rpmAt === 'function') return o.rpmAt(v);
      if (v <= 0) return 0;
      const max = num(fan.max, 2000);
      return Math.max(num(fan.min, 0), max * v / 100);
    }
    /** keep the order: a point's temperature between its neighbours', its value between theirs */
    function fit(i, t, v) {
      const a = pts[i - 1], b = pts[i + 1];
      t = clamp(snapT(t), a ? a.t + 1 : tMin, b ? b.t - 1 : tMax);
      v = clamp(snapV(v), a ? a.v : vMin, b ? b.v : vMax);
      return { t, v };
    }
    const copy = () => pts.map((p) => ({ t: p.t, v: p.v }));

    const root = el('div', 'fan-curve');
    const chart = el('div', 'fan-curve-chart');
    const side = el('div', 'fan-curve-side');
    const read = el('p', 'fan-curve-read');
    read.setAttribute('aria-live', 'polite');
    const s = svg('svg', { viewBox: `0 0 ${W} ${H}`, class: 'fan-curve-svg' });   // its size set by fitSize()
    chart.appendChild(s);
    const wh = wheel(Object.assign({}, fan, { px: num(fan.px, 96), rpm: 0, state: 'run', air: false }));
    side.append(wh.el, read);
    root.append(chart, side);
    if (tx.axisT) chart.appendChild(el('span', 'fan-curve-axis fan-curve-axis-t', tx.axisT));
    if (tx.axisV) chart.appendChild(el('span', 'fan-curve-axis fan-curve-axis-v', tx.axisV));

    const grid = svg('g', { class: 'fan-curve-grid' });
    const area = svg('path', { class: 'fan-curve-area' });
    const line = svg('polyline', { class: 'fan-curve-line' });
    const eff = svg('polyline', { class: 'fan-curve-eff' });
    const ends = svg('g', { class: 'fan-curve-ends' });
    const marks = svg('g', { class: 'fan-curve-marks' });
    const cross = svg('line', { class: 'fan-curve-cross', y1: PT, y2: H - PB, visibility: 'hidden' });
    const dots = svg('g', { class: 'fan-curve-dots' });
    s.append(grid, area, line, eff, ends, marks, cross, dots);

    function mark(cls, x1, y1, x2, y2, label, lx, ly, anchor) {
      marks.appendChild(svg('line', { class: cls, x1, y1, x2, y2 }));
      if (label) {
        const t = svg('text', { class: cls, x: lx, y: ly, 'text-anchor': anchor });
        t.textContent = label;
        marks.appendChild(t);
      }
    }
    /** the scales, the floor and the now mark: drawn at the chart's real size, so its text is the page's size */
    function frame() {
      grid.replaceChildren();
      marks.replaceChildren();
      s.setAttribute('viewBox', `0 0 ${W} ${H}`);
      cross.setAttribute('y2', H - PB);
      const every = W < 420 ? 20 : 10;        // every 10 °C, on a phone every 20 °C; five steps of the value
      for (let t = Math.ceil(tMin / every) * every; t <= tMax; t += every) {
        grid.appendChild(svg('line', { x1: x(t), x2: x(t), y1: PT, y2: H - PB }));
        const lb = svg('text', { x: x(t), y: H - PB + 18, 'text-anchor': 'middle' });
        lb.textContent = tx.tickT(t);
        grid.appendChild(lb);
      }
      for (let k = 0; k <= 5; k++) {
        const v = vMin + (vMax - vMin) * k / 5;
        grid.appendChild(svg('line', { x1: PL, x2: W - PR, y1: y(v), y2: y(v) }));
        const lb = svg('text', { x: PL - 8, y: y(v) + 4, 'text-anchor': 'end' });
        lb.textContent = tx.tickV(rpm ? Math.round(v / 10) * 10 : Math.round(v));
        grid.appendChild(lb);
      }
      if (typeof o.floor === 'number') mark('fan-curve-floor', PL, y(o.floor), W - PR, y(o.floor), tx.floor, W - PR - 4, y(o.floor) - 5, 'end');
      if (typeof o.now === 'number' && o.now >= tMin && o.now <= tMax) {
        const right = x(o.now) > W * 0.7;      // near the right edge its words go to the left of the mark
        mark('fan-curve-now', x(o.now), PT, x(o.now), H - PB, tx.now, x(o.now) + (right ? -5 : 5), PT + 12, right ? 'end' : 'start');
      }
    }
    /** the value as the control applies it (o.apply: the fan's floor, its standstill) — the truth the chart draws bold */
    const applied = (v) => (typeof o.apply === 'function' ? o.apply(v) : v);
    let shownT = typeof o.now === 'number' ? o.now : null;
    function show(t) {
      const v = applied(valueAt(t)), r = Math.round(rpmOf(v));
      wh.update({ rpm: r, state: r > 0 ? 'run' : 'stopped' });
      read.textContent = tx.readout(Math.round(t), rpm ? Math.round(v) : Math.round(v), r);
    }
    function paint() {
      const xy = pts.map((p) => `${x(p.t).toFixed(1)},${y(p.v).toFixed(1)}`);
      line.setAttribute('points', xy.join(' '));
      const first = pts[0], last = pts[pts.length - 1];
      if (typeof o.apply === 'function') {
        // what the fan really gets: the curve after its floor and its standstill, a step where it starts — drawn bold and
        // filled; the points' own line stays, thin, where he drags
        const exy = [];
        for (let t = tMin; t <= tMax + 1e-6; t += 0.25) exy.push(`${x(t).toFixed(1)},${y(applied(valueAt(t))).toFixed(1)}`);
        eff.setAttribute('points', exy.join(' '));
        line.setAttribute('class', 'fan-curve-line fan-curve-wish');
        area.setAttribute('d', `M${PL},${H - PB} L${exy.join(' L')} L${W - PR},${H - PB}Z`);
      } else area.setAttribute('d', `M${PL},${H - PB} L${PL},${y(first.v)} L${xy.join(' L')} L${W - PR},${y(last.v)} L${W - PR},${H - PB}Z`);
      ends.replaceChildren(svg('line', { x1: PL, x2: x(first.t), y1: y(first.v), y2: y(first.v) }),
        svg('line', { x1: x(last.t), x2: W - PR, y1: y(last.v), y2: y(last.v) }));
      pts.forEach((p, i) => {
        let g = dots.children[i];
        if (!g) {
          g = svg('g', { class: 'fan-curve-pt', tabindex: '0', role: 'slider' });
          g.append(svg('circle', { class: 'fan-curve-hit', r: 18 }), svg('circle', { class: 'fan-curve-dot', r: 7 }));
          dots.appendChild(g);
        }
        g.dataset.i = i;
        g.setAttribute('transform', `translate(${x(p.t).toFixed(1)} ${y(p.v).toFixed(1)})`);
        g.setAttribute('aria-label', tx.point(i, pts.length));
        g.setAttribute('aria-valuemin', vMin);
        g.setAttribute('aria-valuemax', vMax);
        g.setAttribute('aria-valuenow', p.v);
        g.setAttribute('aria-valuetext', tx.value(p.t, p.v));
      });
      while (dots.children.length > pts.length) dots.lastChild.remove();
      show(shownT != null ? shownT : pts[Math.floor(pts.length / 2)].t);
    }
    const emit = (fn) => { if (typeof fn === 'function') fn(copy()); };

    /** the pointer's place in the chart's own units */
    function at(ev) {
      const r = s.getBoundingClientRect();
      return { px: (ev.clientX - r.left) / r.width * W, py: (ev.clientY - r.top) / r.height * H };
    }
    let drag = null;
    s.addEventListener('pointerdown', (ev) => {
      const g = ev.target.closest && ev.target.closest('.fan-curve-pt');
      if (!g) return;
      ev.preventDefault();
      const i = +g.dataset.i;
      drag = { i, was: copy(), id: ev.pointerId };
      g.focus({ preventScroll: true });
      root.classList.add('fan-dragging');
      try { s.setPointerCapture(ev.pointerId); } catch (e) { /* a pointer already gone */ }
    });
    s.addEventListener('pointermove', (ev) => {
      const { px, py } = at(ev);
      const t = clamp(tAt(px), tMin, tMax);
      if (drag) {
        pts[drag.i] = fit(drag.i, tAt(px), vAt(py));
        shownT = pts[drag.i].t;
        paint();
        emit(o.onInput);
      } else {
        shownT = t;
        show(t);
      }
      cross.setAttribute('x1', x(shownT));
      cross.setAttribute('x2', x(shownT));
      cross.setAttribute('visibility', 'visible');
    });
    const end = (keep) => {
      if (!drag) return;
      if (!keep) pts = drag.was;
      drag = null;
      root.classList.remove('fan-dragging');
      paint();
      emit(o.onChange);
    };
    s.addEventListener('pointerup', () => end(true));
    s.addEventListener('pointercancel', () => end(false));
    s.addEventListener('pointerleave', () => {
      if (drag) return;
      cross.setAttribute('visibility', 'hidden');
      shownT = typeof o.now === 'number' ? o.now : null;
      paint();
    });
    s.addEventListener('dblclick', (ev) => {
      if (ev.target.closest && ev.target.closest('.fan-curve-pt')) return;
      const { px, py } = at(ev);
      const t = snapT(tAt(px));
      if (pts.some((p) => p.t === t)) return;
      let i = pts.findIndex((p) => p.t > t);
      if (i < 0) i = pts.length;
      pts.splice(i, 0, { t, v: snapV(vAt(py)) });
      pts[i] = fit(i, pts[i].t, pts[i].v);
      paint();
      emit(o.onChange);
    });
    s.addEventListener('keydown', (ev) => {
      const g = ev.target.closest && ev.target.closest('.fan-curve-pt');
      if (!g) return;
      const i = +g.dataset.i, p = pts[i], big = ev.shiftKey ? 5 : 1;
      let t = p.t, v = p.v;
      if (ev.key === 'ArrowLeft') t -= big;
      else if (ev.key === 'ArrowRight') t += big;
      else if (ev.key === 'ArrowUp') v += big * vStep;
      else if (ev.key === 'ArrowDown') v -= big * vStep;
      else if ((ev.key === 'Delete' || ev.key === 'Backspace') && pts.length > 2) {
        ev.preventDefault();
        pts.splice(i, 1);
        paint();
        emit(o.onChange);
        const n = dots.children[Math.min(i, pts.length - 1)];
        if (n) n.focus();
        return;
      } else if (ev.key === '+' || ev.key === 'Insert') {
        const b = pts[i + 1];
        const nt = b ? Math.round((p.t + b.t) / 2) : Math.min(tMax, p.t + 5);
        if (nt === p.t || (b && nt === b.t)) return;
        ev.preventDefault();
        pts.splice(i + 1, 0, { t: nt, v: b ? snapV((p.v + b.v) / 2) : p.v });
        pts[i + 1] = fit(i + 1, pts[i + 1].t, pts[i + 1].v);
        paint();
        emit(o.onChange);
        dots.children[i + 1].focus();
        return;
      } else return;
      ev.preventDefault();
      pts[i] = fit(i, t, v);
      shownT = pts[i].t;
      paint();
      emit(o.onChange);
    });
    s.addEventListener('focusin', (ev) => {
      const g = ev.target.closest && ev.target.closest('.fan-curve-pt');
      if (g && pts[+g.dataset.i]) { shownT = pts[+g.dataset.i].t; show(shownT); }
    });

    /** the chart follows its width (a phone, a narrow column): drawn anew at the size it is shown */
    function fitSize() {
      const w = Math.round(s.parentNode ? chart.clientWidth - 16 : 0);
      if (w < 200 || w === W) return;
      W = w;
      H = Math.round(clamp(W * 0.45, 190, 320));
      frame();
      if (pts.length) paint();
    }
    const watch = window.ResizeObserver ? new ResizeObserver(fitSize) : null;
    if (watch) watch.observe(chart);
    frame();

    function set(points) {
      const list = (Array.isArray(points) ? points : [])
        .filter((p) => p && typeof p.t === 'number' && typeof p.v === 'number')
        .map((p) => ({ t: snapT(p.t), v: snapV(p.v) }))
        .sort((a, b) => a.t - b.t);
      pts = [];
      for (const p of list) {
        if (pts.length && p.t <= pts[pts.length - 1].t) continue;
        pts.push({ t: p.t, v: pts.length ? Math.max(p.v, pts[pts.length - 1].v) : p.v });
      }
      if (pts.length < 2) pts = [{ t: tMin + 10, v: snapV(vMin + (vMax - vMin) * 0.3) }, { t: tMax - 10, v: vMax }];
      paint();
    }
    set(o.points);
    return { el: root, set, points: copy, valueAt, destroy() { wh.destroy(); if (watch) watch.disconnect(); } };
  }

  // ------------------------------------------------------------------------------------------- the catalogue
  function catalog(url) {
    const href = url || (ME ? ME.replace(/fan-art\.js(\?.*)?$/, (m, q) => `fans-catalog.json${q || ''}`) : 'fans-catalog.json');
    return fetch(href, { credentials: 'same-origin' }).then((r) => {
      if (!r.ok) throw new Error(`fans-catalog.json: ${r.status}`);
      return r.json();
    });
  }

  const SIZES = [40, 50, 60, 70, 80, 92, 120, 140, 160, 180, 200];
  function picker(opts) {
    styles();
    const o = opts || {};
    const tx = Object.assign({ brand: '', model: '', other: '', choose: '', size: '', blades: '', mm: (n) => String(n),
      option: (f) => f.model, facts: () => '' }, o.texts || {});
    const fans = (o.catalog && Array.isArray(o.catalog.fans) ? o.catalog.fans : []).filter((f) => f && f.id && f.brand && f.model);
    const brands = [...new Set(fans.map((f) => f.brand))].sort((a, b) => a.localeCompare(b));
    const OTHER = '\u0000other';
    const root = el('div', 'fan-pick');
    const form = el('div', 'fan-pick-form');
    const field = (label, input) => {
      const l = el('label', 'fan-pick-field');
      l.append(el('span', 'fan-pick-label', label), input);
      form.appendChild(l);
      return l;
    };
    const opt = (value, label, sel) => { const n = el('option', null, label); n.value = value; if (sel) n.selected = true; return n; };
    const brandSel = el('select'), modelSel = el('select'), sizeSel = el('select'), bladeSel = el('select');
    const fBrand = field(tx.brand, brandSel), fModel = field(tx.model, modelSel);
    const fSize = field(tx.size, sizeSel), fBlades = field(tx.blades, bladeSel);
    for (const n of SIZES) sizeSel.appendChild(opt(n, tx.mm(n)));
    for (let n = 5; n <= 11; n++) bladeSel.appendChild(opt(n, String(n)));
    const facts = el('p', 'fan-pick-facts');
    const look = el('div', 'fan-pick-look');
    const wh = wheel({ mm: 120, blades: 7, rpm: 0, px: 120, air: false });
    look.append(wh.el, facts);
    root.append(form, look);
    void fBrand;

    let cur = null;
    let colour = pick(o.colour, COLOURS, 'auto');      // the user's colour for this fan: the preview shows what the pictures will
    function chosen() {
      if (brandSel.value === OTHER) {
        return { id: null, brand: null, model: null, mm: +sizeSel.value, blades: +bladeSel.value, rpm_min: null, rpm_max: null, connector: null, look: null };
      }
      const f = fans.find((x) => x.id === modelSel.value);
      return f ? { id: f.id, brand: f.brand, model: f.model, mm: f.mm, blades: f.blades, rpm_min: f.rpm_min, rpm_max: f.rpm_max, connector: f.connector, look: f.look || null } : null;
    }
    function models() {
      const b = brandSel.value;
      modelSel.replaceChildren(opt('', tx.choose));
      for (const f of fans.filter((x) => x.brand === b).sort((p, q) => p.mm - q.mm || p.model.localeCompare(q.model))) {
        modelSel.appendChild(opt(f.id, tx.option(f), cur && cur.id === f.id));
      }
    }
    function look2() {
      const other = brandSel.value === OTHER;
      fModel.hidden = other || !brandSel.value;
      fSize.hidden = fBlades.hidden = !other;
      cur = chosen();
      facts.textContent = cur ? tx.facts(cur) : '';
      const mx = cur && cur.rpm_max ? cur.rpm_max : 1500;
      wh.update(cur ? { mm: cur.mm || 120, blades: cur.blades || null, min: cur.rpm_min, max: mx, rpm: Math.round(mx * 0.6), state: 'run', look: cur.look, colour }
        : { mm: 120, blades: 7, rpm: 0, state: 'unknown', look: null, colour });
    }
    const changed = () => { look2(); if (typeof o.onChange === 'function') o.onChange(cur); };
    brandSel.addEventListener('change', () => { models(); changed(); });
    modelSel.addEventListener('change', changed);
    sizeSel.addEventListener('change', changed);
    bladeSel.addEventListener('change', changed);

    function set(value) {
      const v = value || {};
      const f = v.id ? fans.find((x) => x.id === v.id) : null;
      brandSel.replaceChildren(opt('', tx.choose));
      for (const b of brands) brandSel.appendChild(opt(b, b, f && f.brand === b));
      brandSel.appendChild(opt(OTHER, tx.other, !f && (v.mm || v.blades)));
      cur = f ? { id: f.id } : null;
      models();
      if (!f && (v.mm || v.blades)) {
        sizeSel.value = String(SIZES.includes(v.mm) ? v.mm : 120);
        bladeSel.value = String(clamp(num(v.blades, 7), 5, 11));
      } else {
        sizeSel.value = '120';
        bladeSel.value = '7';
      }
      look2();
    }
    set(o.value);
    return { el: root, set, value: () => cur, colour(c) { colour = pick(c, COLOURS, 'auto'); look2(); }, destroy() { wh.destroy(); } };
  }

  window.FanArt = { wheel, server, curve, picker, catalog, turns, styles, lookOf, COLOURS, STATES, PLACES, KINDS, FLOWS, SIDES, LOOK_COLOURS, STYLES, SHAPES };
})();
