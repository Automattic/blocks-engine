// Run captured-header-panel-ownership.php first; exercise the emitted companion runtime.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/captured-header-panel-ownership.json`));
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.setContent(`<style>${fixture.css}</style>${fixture.markup}<script>${fixture.script}</script>`);
  const links = page.locator('#menuDialog a');
  assert.equal(await links.count(), 7);
  assert.equal(await links.first().isVisible(), false);
  for (const close of ['toggle', 'Escape']) {
    await page.locator('button#menuBtn, #menuBtn button').click();
    assert.equal(await page.locator('[id="authoredList"]').count(), 1);
    assert.equal(await page.locator('#menuDialog .breakdance-menu-list:not(.is-visible)').count(), 0);
    for (let i = 0; i < 7; i++) {
      assert.equal(await links.nth(i).isVisible(), true);
      const box = await links.nth(i).boundingBox();
      assert.deepEqual([box.x, box.y, box.width, box.height], [0, 93 + i * 55, 390, 55]);
    }
    if (close === 'toggle') await page.locator('button#menuBtn, #menuBtn button').click();
    else await page.keyboard.press('Escape');
    assert.equal(await links.first().isVisible(), false);
    assert.equal(await page.locator('#menuDialog .breakdance-menu-list.is-visible').count(), 0);
  }
  const triggerBox = await page.locator('button#searchBtn, #searchBtn button').boundingBox();
  assert.deepEqual([triggerBox.x, triggerBox.y, triggerBox.width, triggerBox.height], [313, 33, 52, 52]);
  await page.locator('button#searchBtn, #searchBtn button').click();
  assert.equal(await page.locator('#searchDialog').evaluate(node => node.hasAttribute('open')), true);
  const field = page.locator('#searchDialog input');
  assert.equal(await field.isVisible(), true);
  const box = await field.boundingBox();
  assert.deepEqual([box.x, box.y, box.width, box.height], [92, 374, 258, 52]);
  await page.keyboard.press('Escape');
  assert.equal(await field.isVisible(), false);
  await page.locator('button#searchBtn, #searchBtn button').click();
  await field.focus();
  assert.equal(await field.evaluate(node => node === document.activeElement), true);
  await page.locator('#searchClose').click();
  assert.equal(await field.isVisible(), false);
  // Real section reproduction: sibling icon controls must own separate panels.
  const section = JSON.parse(readFileSync(`${tmpdir()}/breakdance-header-panel-ownership.json`));
  await page.setContent(`<style>${section.css}</style>${section.markup}<script>${section.script}</script>`);
  const search = page.locator('button.search-form__button--full-screen');
  const menu = page.getByRole('button', { name: 'Open Menu', exact: true });
  assert.equal(await search.count(), 1);
  assert.equal(await menu.count(), 1);
  const searchId = await search.getAttribute('aria-controls');
  const menuId = await menu.getAttribute('aria-controls');
  assert.notEqual(searchId, menuId);
  assert.equal(await search.evaluate(node => node.parentElement.classList.contains('bde-search-form-24585-153')), true);
  assert.equal(await menu.evaluate(node => node.closest('.bde-wp-menu-24585-143') !== null), true);
  const searchField = page.locator(`#${searchId} input.search-form__field`);
  assert.equal(await searchField.count(), 1);
  assert.equal(await searchField.isVisible(), false);
  await search.click();
  assert.equal(await search.getAttribute('aria-expanded'), 'true');
  assert.equal(await searchField.isVisible(), true);
  await page.keyboard.press('Escape');
  assert.equal(await searchField.isVisible(), false);
  await menu.click();
  const sectionLinks = page.locator(`#${menuId} a`);
  assert.equal(await sectionLinks.count(), 7);
  for (const link of await sectionLinks.all()) assert.equal(await link.isVisible(), true);
  assert.equal(await searchField.isVisible(), false);
  await page.keyboard.press('Escape');
  assert.equal(await sectionLinks.first().isVisible(), false);
} finally {
  await browser.close();
}
console.log('Captured header panel ownership browser contract passed');
