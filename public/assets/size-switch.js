/* Unraid Secretary Office — the text-size switch at the reception: A · A · A
   (for people with glasses, 2026-10-08; size-switch.css says how it works and how to switch it off or remove it).

   Small = the office as it always was: no data-size on #sso. Medium / Large = #sso[data-size] (CSS zoom), kept in this
   browser (Office.store 'size'); src/page.php sets the attribute from the same key before the first paint, so nothing
   jumps. core.js puts Office.size.control() into the reception's head next to the theme switch and a line into the help
   (the lines marked «size-switch»). Loaded only while OFFICE_SIZE_SWITCH is true. */
(() => {
'use strict';

const Office = window.Office;
if (!Office || !Office.config.size_switch) return;
const { t, el, $ } = Office;
const ROOT = $('#sso');
const CHOICES = ['small', 'medium', 'large'];

Office.size = {
  /** 'small', 'medium' or 'large': what this browser keeps */
  get() {
    const v = Office.store('size');
    return v === 'medium' || v === 'large' ? v : 'small';
  },
  /** Choose: the attribute on #sso at once (size-switch.css does the rest), kept in this browser, every switch follows */
  set(value) {
    const v = CHOICES.includes(value) ? value : 'small';
    Office.store('size', v === 'small' ? null : v);
    if (Office.hideTip) Office.hideTip();      // a tip placed at the old size would stand in the wrong place
    if (v === 'small') ROOT.removeAttribute('data-size'); else ROOT.dataset.size = v;
    document.querySelectorAll('#sso .size-switch').forEach(apply);
  },
  /** The segmented control: three radios (the arrow keys move between them), the letter A in three sizes, a tip on each */
  control() {
    const box = el('div', 'seg size-switch');
    box.setAttribute('role', 'radiogroup');
    box.setAttribute('aria-label', t('office.size_title'));
    for (const c of CHOICES) {
      const b = el('button', '', 'A');
      b.type = 'button';
      b.setAttribute('role', 'radio');
      b.setAttribute('aria-label', t(`office.size_${c}`));
      b.dataset.choice = c;
      b.dataset.tip = t('office.size_tip', { size: t(`office.size_${c}`) });   // «… — in this browser only»
      b.onclick = () => Office.size.set(c);
      b.onkeydown = onKey;
      box.appendChild(b);
    }
    apply(box);
    return box;
  },
};

/** The chosen radio checked and in the tab order, the others reached with the arrow keys */
function apply(box) {
  const now = Office.size.get();
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
  Office.size.set(CHOICES[j]);
  const next = e.currentTarget.parentElement.querySelector(`button[data-choice="${CHOICES[j]}"]`);
  if (next) next.focus();
}
})();
