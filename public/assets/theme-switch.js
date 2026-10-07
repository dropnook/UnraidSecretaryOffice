/* Unraid Secretary Office — the theme switch at the reception: Automatic · Dark · Light
   (an experiment, Benj 2026-10-07; theme-switch.css says how it works and how to switch it off or remove it).

   Automatic = Unraid's theme: no data-theme on #sso, the office as it always was. Dark / Light = #sso[data-theme],
   kept in this browser (Office.store 'theme'); src/page.php sets the attribute from the same key before the first
   paint, so the page never flashes the other look. core.js puts Office.theme.control() into the reception's head
   and a line into the help (the lines marked «theme-switch»). Loaded only while OFFICE_THEME_SWITCH is true. */
(() => {
'use strict';

const Office = window.Office;
if (!Office || !Office.config.theme_switch) return;
const { t, el, $ } = Office;
const ROOT = $('#sso');
const CHOICES = ['auto', 'dark', 'light'];

// the icons, stroked in the text's colour: a half-filled circle (automatic), a moon, a sun
const ICONS = {
  auto: '<circle cx="12" cy="12" r="8.5"/><path class="fill" d="M12 3.5a8.5 8.5 0 0 1 0 17z"/>',
  dark: '<path d="M19.5 14.2A8 8 0 1 1 9.8 4.5a6.5 6.5 0 0 0 9.7 9.7z"/>',
  light: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.3M12 19.2v2.3M2.5 12h2.3M19.2 12h2.3M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M5.3 18.7l1.6-1.6M17.1 6.9l1.6-1.6"/>',
};
function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.innerHTML = ICONS[name];      // a constant of this file, never anything from outside
  return svg;
}

/** Unraid's theme on this page (the html element's Theme--<name> class), named in the Automatic tip */
const unraidTheme = () => (document.documentElement.className.match(/\bTheme--(black|white|azure|gray)\b/) || [])[1] || '';

Office.theme = {
  /** 'auto', 'dark' or 'light': what this browser keeps */
  get() {
    const v = Office.store('theme');
    return v === 'dark' || v === 'light' ? v : 'auto';
  },
  /** Choose: the attribute on #sso at once (theme-switch.css does the rest), kept in this browser, every switch follows */
  set(value) {
    const v = CHOICES.includes(value) ? value : 'auto';
    Office.store('theme', v === 'auto' ? null : v);
    if (v === 'auto') ROOT.removeAttribute('data-theme'); else ROOT.dataset.theme = v;
    document.querySelectorAll('#sso .theme-switch').forEach(apply);
  },
  /** The segmented control: three radios (the arrow keys move between them), an icon and a word each, a tip on each */
  control() {
    const box = el('div', 'seg theme-switch');
    box.setAttribute('role', 'radiogroup');
    box.setAttribute('aria-label', t('office.theme_title'));
    for (const c of CHOICES) {
      const b = el('button');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.dataset.choice = c;
      b.dataset.tip = t(`office.theme_${c}_title`, { theme: unraidTheme() || t('common.unknown') });
      b.append(icon(c), el('span', '', t(`office.theme_${c}`)));
      b.onclick = () => Office.theme.set(c);
      b.onkeydown = onKey;
      box.appendChild(b);
    }
    apply(box);
    return box;
  },
};

/** The chosen radio checked and in the tab order, the others reached with the arrow keys */
function apply(box) {
  const now = Office.theme.get();
  box.querySelectorAll('button').forEach((b) => {
    const on = b.dataset.choice === now;
    b.setAttribute('aria-checked', on ? 'true' : 'false');
    b.tabIndex = on ? 0 : -1;
  });
}

/** Like a native radio group: the arrow keys choose the next or previous one, Home and End the first or last */
function onKey(e) {
  const steps = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1, Home: 'first', End: 'last' };
  const step = steps[e.key];
  if (step === undefined) return;
  e.preventDefault();
  const i = CHOICES.indexOf(e.currentTarget.dataset.choice);
  const j = step === 'first' ? 0 : step === 'last' ? CHOICES.length - 1 : (i + step + CHOICES.length) % CHOICES.length;
  Office.theme.set(CHOICES[j]);
  const next = e.currentTarget.parentElement.querySelector(`button[data-choice="${CHOICES[j]}"]`);
  if (next) next.focus();
}
})();
