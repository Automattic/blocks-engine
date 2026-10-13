// Real-browser parity between the recognized capture disclosure runtime and
// the native dialog that replaces it, for attribute-only ancestor rows. Both
// pages get the same clicks and keys; after each step, every recorded
// attribute on each bound ancestor must hold the same value on both pages
// (absent on both, or equal), and the bound header must paint the same.
// Run `php tests/unit/capture-runtime-attribute-parity.php` first for fixtures.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/capture-runtime-attribute-parity.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
let checks = 0;

const rows = fixture.ancestors.map(row => ({ selector: row.selector, names: Object.keys(row.closed) }));
const allNames = [...new Set(rows.flatMap(row => row.names))];
const snapshot = page => page.evaluate(({ rows, allNames }) => {
  const control = document.querySelector('#site-header button');
  // No ancestor outside the recorded ones may receive a recorded attribute.
  const strays = [];
  for (let node = control.parentElement; node; node = node.parentElement) {
    if (rows.some(row => document.querySelector(row.selector) === node)) continue;
    allNames.forEach(name => { if (node.hasAttribute(name)) strays.push(`${node.tagName}.${name}`); });
  }
  return {
    expanded: control.getAttribute('aria-expanded'),
    background: getComputedStyle(document.querySelector('#site-header')).backgroundColor,
    strays,
    ancestors: rows.map(row => {
      const node = document.querySelector(row.selector);
      return Object.fromEntries(row.names.map(name => [name, node.getAttribute(name)]));
    }),
  };
}, { rows, allNames });

const source = await browser.newPage({ viewport: { width: 1280, height: 800 } });
await source.setContent(fixture.source);
const native = await browser.newPage({ viewport: { width: 1280, height: 800 } });
await native.setContent(`<!doctype html><html><head><style>${fixture.css}</style></head><body>${fixture.native}<script>${fixture.script}</script></body></html>`);
assert.equal(await native.locator('script[data-blocks-engine-capture-disclosure-runtime]').count(), 0, 'the native page carries no capture runtime');
assert.equal(await source.locator('script[data-blocks-engine-capture-disclosure-runtime]').count(), 1, 'the source page runs the capture runtime');
assert.equal(await native.locator('details').count(), 0, 'the text-labelled trigger stays a native dialog trigger, not core/details');

const steps = ['initial', 'open', 'close', 'reopen', 'Escape', 'open again'];
const seen = [];
for (const step of steps) {
  for (const page of [source, native]) {
    if (step === 'Escape') await page.keyboard.press('Escape');
    else if (step !== 'initial') await page.locator('#site-header button').click();
    await page.waitForTimeout(50);
  }
  const [sourceState, nativeState] = [await snapshot(source), await snapshot(native)];
  assert.deepEqual(nativeState.ancestors, sourceState.ancestors, `${step}: native ancestor attributes match the capture runtime`);
  assert.equal(nativeState.expanded, sourceState.expanded, `${step}: native aria-expanded matches the capture runtime`);
  assert.equal(nativeState.background, sourceState.background, `${step}: the attribute-keyed header paint matches`);
  assert.deepEqual(nativeState.strays, [], `${step}: no unrecorded ancestor receives a recorded attribute`);
  seen.push(sourceState);
  checks += 4;
}
assert.deepEqual(seen[1].ancestors, [
  { 'data-section-theme': 'light' },
  { 'aria-expanded': 'true', 'data-state': 'open' },
  { 'data-menu': 'open' },
], 'the fixture exercises set, added and value-changed attributes when open');
assert.deepEqual(seen[2].ancestors, [
  { 'data-section-theme': 'dark' },
  { 'aria-expanded': null, 'data-state': 'closed' },
  { 'data-menu': null },
], 'the fixture exercises removal of attributes whose closed value is null');
assert.notEqual(seen[1].background, seen[2].background, 'the header paint follows its replayed attribute');
checks += 3;

await browser.close();
console.log(`capture-runtime-attribute-parity browser contract passed: ${checks} checks`);
