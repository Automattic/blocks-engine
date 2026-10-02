import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';

const playwrightPath = process.env.PLAYWRIGHT_MODULE || 'playwright';
const { chromium } = await import(playwrightPath);
const fixture = JSON.parse(readFileSync(`${tmpdir()}/runtime-dialog-trigger-button.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.setContent(`<style>${fixture.css}</style>${fixture.html}<script>${fixture.script}</script>`);

await page.setViewportSize({ width: 390, height: 844 });
const mobile = page.locator('#menu-toggle-mobile');
await mobile.focus();
await page.keyboard.press('Enter');
const opened = await page.evaluate(() => {
  const trigger = document.getElementById('menu-toggle-mobile');
  const panel = document.getElementById('menu-panel-mobile');
  return {
    expanded: trigger.getAttribute('aria-expanded'),
    hidden: panel.hidden,
    focus: document.activeElement && document.activeElement.getAttribute('href'),
    fallback: document.body.innerHTML.includes('<!-- wp:html') && document.body.innerHTML.includes('menu-toggle-mobile') && document.body.innerHTML.includes('wp:html') && document.querySelector('#menu-toggle-mobile').closest('.wp-block-html') !== null,
  };
});
assert.equal(opened.expanded, 'true', 'keyboard activation opens the mobile dialog');
assert.equal(opened.hidden, false, 'mobile panel is shown');
assert.equal(opened.focus, '#intro', 'open moves focus into the panel');
assert.equal(opened.fallback, false, 'the mobile trigger is not a core/html fallback');

await mobile.click();
const closed = await page.evaluate(() => {
  const trigger = document.getElementById('menu-toggle-mobile');
  const panel = document.getElementById('menu-panel-mobile');
  return { expanded: trigger.getAttribute('aria-expanded'), hidden: panel.hidden, focus: document.activeElement && document.activeElement.id };
});
assert.equal(closed.expanded, 'false', 'second activation closes the mobile dialog');
assert.equal(closed.hidden, true, 'mobile panel is hidden again');
assert.equal(closed.focus, 'menu-toggle-mobile', 'close restores focus to the trigger');

await page.setViewportSize({ width: 1280, height: 800 });
const desktop = await page.evaluate(() => {
  const variant = document.querySelector('.site-document-variant-mobile');
  const panel = document.getElementById('menu-panel');
  const variantStyle = variant ? getComputedStyle(variant).display : '';
  return { mobileDisplay: variantStyle, desktopHidden: panel.hidden, desktopExpanded: document.getElementById('menu-toggle').getAttribute('aria-expanded') };
});
assert.equal(desktop.mobileDisplay, 'none', 'mobile dialog variant stays hidden on desktop');
assert.equal(desktop.desktopHidden, true, 'desktop dialog panel stays hidden');
assert.equal(desktop.desktopExpanded, 'false', 'desktop trigger remains closed');

await browser.close();
console.log('PASS: dialog trigger opens, closes, focuses, and stays hidden on desktop');
