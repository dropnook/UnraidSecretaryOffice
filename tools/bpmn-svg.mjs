#!/usr/bin/env node
// Renders a BPMN file of docs/diagrams/ to its SVG the way the existing ones were made: bpmn-js (bpmn.io) drawing it in
// a headless Chrome (playwright-core with a throwaway profile - like tools/ui-shots.mjs), then its own saveSVG(). Needs
// node, playwright-core (USO_PLAYWRIGHT=<its folder>, else npx's cache), a Chrome, and the internet once for bpmn-js
// from unpkg (BPMN_JS=<a local bpmn-navigated-viewer.production.min.js> instead).
//
//   node tools/bpmn-svg.mjs docs/diagrams/partner-pairing.bpmn [more.bpmn …]   -> beside each: <name>.svg
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';

const files = process.argv.slice(2);
if (!files.length) { console.error('usage: node tools/bpmn-svg.mjs <file.bpmn> …'); process.exit(2); }

function playwright() {
  const tries = [process.env.USO_PLAYWRIGHT, 'playwright-core'];
  const npx = path.join(os.homedir(), '.npm', '_npx');
  try { for (const d of fs.readdirSync(npx)) tries.push(path.join(npx, d, 'node_modules', 'playwright-core')); } catch (e) { /* none */ }
  const req = createRequire(import.meta.url);
  for (const t of tries.filter(Boolean)) { try { return req(t); } catch (e) { /* next */ } }
  return null;
}
const pw = playwright();
if (!pw) { console.error('bpmn svg: playwright-core not found'); process.exit(3); }
const chrome = process.env.USO_CHROME || (fs.existsSync('/Applications/Google Chrome.app') ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : undefined);
const lib = process.env.BPMN_JS ? fs.readFileSync(process.env.BPMN_JS, 'utf8') : null;
const page_html = `<!doctype html><html><head><meta charset="utf-8"></head><body><div id="c" style="width:2000px;height:1000px"></div>
${lib ? `<script>${lib}</script>` : '<script src="https://unpkg.com/bpmn-js@17.11.1/dist/bpmn-navigated-viewer.production.min.js"></script>'}</body></html>`;

const browser = await pw.chromium.launch({ executablePath: chrome, headless: true });
let failed = 0;
try {
  const page = await browser.newPage();
  await page.setContent(page_html, { waitUntil: 'load' });
  await page.waitForFunction(() => !!window.BpmnJS, null, { timeout: 30000 });
  for (const f of files) {
    const xml = fs.readFileSync(f, 'utf8');
    try {
      const svg = await page.evaluate(async (x) => {
        const v = new window.BpmnJS({ container: '#c' });
        await v.importXML(x);
        const { svg } = await v.saveSVG();
        v.destroy();
        return svg;
      }, xml);
      const out = f.replace(/\.bpmn$/, '') + '.svg';
      fs.writeFileSync(out, svg);
      console.log(`ok    ${out} (${svg.length} bytes)`);
    } catch (e) {
      failed++;
      console.log(`FAIL  ${f}: ${e.message.split('\n')[0]}`);
    }
  }
} finally {
  await browser.close();
}
process.exit(failed ? 1 : 0);
