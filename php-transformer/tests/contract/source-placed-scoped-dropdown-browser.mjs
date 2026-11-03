// Run source-placed-scoped-dropdown.php first. Public compiler output and its shipped runtime.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true });
try {
  for (const observed of [0, 1]) {
    const result = JSON.parse(readFileSync(`${tmpdir()}/source-placed-scoped-dropdown-${observed}.json`));
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const css = result.assets.filter(a => a.kind === 'css').map(a => a.content).join('\n');
    await page.setContent(`<style>${result.browser_style}\n${css}</style>${result.browser_markup}<script>${result.browser_script}</script>`);
    const panel = page.locator('[data-blocks-engine-capture-document-scope="mobile"] > [data-blocks-engine-triggers]');
    const id = await panel.getAttribute('data-blocks-engine-triggers');
    const trigger = page.locator(`#${id}`);
    await trigger.click();
    const state = await panel.evaluate(panel => {
      const trigger = document.getElementById(panel.getAttribute('data-blocks-engine-triggers'));
      const rect = trigger.getBoundingClientRect();
      const hit = document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2);
      const nav = panel.querySelector('nav') || panel;
      const box = nav.getBoundingClientRect();
      return { hit: trigger.contains(hit), box: [box.x, box.y, box.width], links: [...panel.querySelectorAll('a')].map(a => a.getBoundingClientRect().y), parent: panel.parentElement.getAttribute('data-blocks-engine-capture-document-scope') };
    });
    assert.equal(await panel.evaluate(p => p.hasAttribute('open')), true);
    assert.equal(state.parent, 'mobile');
    assert.equal(state.hit, true);
    assert.deepEqual(state.box, [0, 0, 390]);
    assert.equal(state.links.length, 5);
    assert.ok(state.links.every((y, i) => !i || y > state.links[i - 1]));
    await trigger.click();
    assert.equal(await panel.evaluate(p => p.hasAttribute('open')), false);
    await page.close();
  }
} finally { await browser.close(); }
console.log('Source-placed scoped dropdown browser contract passed.');
