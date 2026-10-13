// Real-browser parity between the recognized capture disclosure runtime and
// the native dialog that replaces it. Both pages get the same clicks; after
// each one, every class token the capture recorded on each ancestor must be
// present on the native page exactly when it is present on the source page.
// Run `php tests/unit/capture-runtime-ancestor-parity.php` first for fixtures.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/capture-runtime-ancestor-parity.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
let checks = 0;

// The recorded tokens of each ancestor row plus the tokens the source node
// starts with: a replay must change the recorded tokens and keep the rest.
const rows = fixture.ancestors.map(row => ({
  tag: row.tag,
  tokens: [...new Set(`${row.closed.class} ${row.opened.class}`.split(/\s+/).filter(Boolean))],
}));
const snapshot = page => page.evaluate(rows => {
  const trigger = document.querySelector('[aria-controls="site-menu-panel"]');
  const control = trigger.matches('button') ? trigger : trigger.querySelector('button');
  const ancestorTags = [];
  for (let node = control.parentElement; node; node = node.parentElement) ancestorTags.push(node);
  return {
    expanded: control.getAttribute('aria-expanded'),
    ancestors: rows.map(row => {
      // Nearest ancestor with the tag whose classes hold the row's first
      // identifying token (a token both states keep), else just the tag.
      const node = ancestorTags.find(node => node.tagName.toLowerCase() === row.tag
        && (row.tag !== 'div' || node.classList.contains('config-0')));
      return Object.fromEntries(row.tokens.map(token => [token, node.classList.contains(token)]));
    }),
  };
}, rows);

// Read the authored classes before any runtime runs.
const authored = await (await browser.newContext({ javaScriptEnabled: false })).newPage();
await authored.setContent(fixture.source);
const initial = await authored.evaluate(rows => rows.map(row => {
  const trigger = document.querySelector('[aria-controls="site-menu-panel"]');
  for (let node = trigger.parentElement; node; node = node.parentElement) {
    if (node.tagName.toLowerCase() === row.tag && (row.tag !== 'div' || node.classList.contains('config-0'))) return [...node.classList];
  }
  return [];
}), rows);
rows.forEach((row, index) => { row.tokens = [...new Set([...row.tokens, ...initial[index]])]; });
const source = await browser.newPage({ viewport: { width: 1280, height: 800 } });
await source.setContent(fixture.source);
const native = await browser.newPage({ viewport: { width: 1280, height: 800 } });
await native.setContent(`<!doctype html><html><head><style>${fixture.css}</style></head><body>${fixture.native}<script>${fixture.script}</script></body></html>`);
assert.equal(await native.locator('script[data-blocks-engine-capture-disclosure-runtime]').count(), 0, 'the native page carries no capture runtime');
assert.equal(await source.locator('script[data-blocks-engine-capture-disclosure-runtime]').count(), 1, 'the source page runs the capture runtime');

const steps = ['initial', 'open', 'close', 'reopen', 'Escape'];
for (const step of steps) {
  for (const page of [source, native]) {
    if (step === 'Escape') await page.keyboard.press('Escape');
    else if (step !== 'initial') await page.locator('[aria-controls="site-menu-panel"]').click();
    await page.waitForTimeout(50);
  }
  const [sourceState, nativeState] = [await snapshot(source), await snapshot(native)];
  assert.deepEqual(nativeState.ancestors, sourceState.ancestors, `${step}: native ancestor class tokens match the capture runtime`);
  assert.equal(nativeState.expanded, sourceState.expanded, `${step}: native aria-expanded matches the capture runtime`);
  checks += 2;
}
const opened = await (async () => {
  await source.locator('[aria-controls="site-menu-panel"]').click();
  return snapshot(source);
})();
assert.deepEqual(opened.ancestors, [
  { 'dark-bold': false, header: true, 'theme-col--primary': true, light: true },
  { 'config-0': true, 'config-1': true, 'config-2': true, 'menu-closed': false, 'menu-open': true, 'data-blocks-engine-capture-desktop-document': true, 'config-3': true },
  { 'header--menu-open': true },
], 'the fixture exercises re-ordered, token-only and empty-class bindings');
checks++;

await browser.close();
console.log(`capture-runtime-ancestor-parity browser contract passed: ${checks} checks`);
