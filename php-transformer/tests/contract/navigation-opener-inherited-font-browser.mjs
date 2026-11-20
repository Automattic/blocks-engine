import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const fixtures = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./navigation-opener-inherited-font.php', import.meta.url)), '--browser-fixtures'], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
try {
  const page = await browser.newPage();
  for (const [name, fixture] of Object.entries(fixtures)) {
    for (const width of [390, 768]) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(fixture.source);
      const expected = await page.locator('button').evaluate(button => {
        const style = getComputedStyle(button);
        return [style.fontWeight, style.fontStyle, style.fontFamily, style.fontSize];
      });
      // Core-shaped dynamic markup: the replacement is inside the bold nav,
      // not a sibling as it was in the source. Load the actual emitted CSS.
      await page.setContent(`<style>${fixture.css}</style><header><nav class="wp-block-navigation blocks-engine-list-navigation ${fixture.classes}" style="font-weight:${name === 'equal-parent-and-nav' ? 400 : 700}"><button class="wp-block-navigation__responsive-container-open">☰</button><div class="wp-block-navigation__responsive-container"><a href="/a">One</a></div></nav></header>`);
      const actual = await page.locator('button').evaluate(button => {
        const style = getComputedStyle(button);
        return [style.fontWeight, style.fontStyle, style.fontFamily, style.fontSize];
      });
      assert.deepEqual(actual, expected, `${name} ${width}: source font survives reparenting`);
      assert.equal(actual[0], '400');
      assert.equal(await page.locator('a').evaluate(a => getComputedStyle(a).fontWeight), name === 'equal-parent-and-nav' ? '400' : '700', `${name}: links retain nav typography`);
    }
  }
  console.log('navigation-opener-inherited-font-browser: ok');
} finally { await browser.close(); }
