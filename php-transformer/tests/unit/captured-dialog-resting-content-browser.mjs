// Run captured-dialog-class-hidden-resting-panel.php first.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/captured-dialog-resting-content.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
  const source = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await source.setContent(fixture.source);
  for (const method of ['toggle', 'Escape']) {
    await source.locator('#menuBtn').click();
    assert.equal(await source.locator('#menuPanel').textContent(), 'AboutContact');
    if (method === 'toggle') await source.locator('#menuBtn').click();
    else await source.keyboard.press('Escape');
    assert.equal(await source.locator('#menuPanel').textContent(), 'Closed content');
    assert.equal(await source.locator('#menuPanel').getAttribute('style'), null);
  }
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.setContent(`<style>.hidden{display:none}${fixture.css}</style>${fixture.markup}<script>${fixture.script}</script>`);
  const trigger = page.locator('#menuBtn button');
  const panel = page.locator('#menuPanel');
  const closed = panel.locator('.captured-dialog-closed-content');
  const opened = panel.locator('.captured-dialog-open-content');
  assert.equal(await closed.textContent(), 'Closed content');
  for (const method of ['toggle', 'Escape']) {
    await trigger.click();
    assert.equal(await trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(await opened.isVisible(), true);
    assert.equal(await closed.isVisible(), false);
    assert.equal(await panel.getAttribute('class').then(value => value.split(/\s+/).includes('hidden')), false);
    if (method === 'toggle') await trigger.click();
    else await page.keyboard.press('Escape');
    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(await opened.isVisible(), false);
    assert.equal(await panel.getAttribute('style'), null);
    assert.equal(await panel.getAttribute('class').then(value => value.split(/\s+/).includes('hidden')), true);
    // The resting class hides the whole panel. Reveal it to inspect the
    // selected content independently of the source's closed presentation.
    await panel.evaluate(node => node.classList.remove('hidden'));
    assert.equal(await closed.isVisible(), true);
    assert.equal(await opened.isVisible(), false);
  }
} finally {
  await browser.close();
}
console.log('Captured dialog resting content browser contract passed');
