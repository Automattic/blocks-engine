import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const origin = process.env.COVER_MARGINS_TEST_URL;
assert.ok(origin, 'COVER_MARGINS_TEST_URL points to the disposable WordPress site');
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
const probe = page => page.evaluate(() => Object.fromEntries(['stack', 'painted'].map(name => {
  const box = document.querySelector(`.${name}`);
  const rect = box.getBoundingClientRect();
  return [name, [...box.querySelectorAll('h3,p,a')].filter(child => child.textContent.trim() && getComputedStyle(child).display !== 'contents').map(child => {
    const style = getComputedStyle(child);
    const childRect = child.getBoundingClientRect();
    return { margins: [style.marginTop, style.marginRight, style.marginBottom, style.marginLeft], x: childRect.x - rect.x, y: childRect.y - rect.y };
  })];
})));
try {
  for (const [variant, css] of [['ua', ''], ['authored', '.painted>h3{margin:12px 7px}'], ['universal', ':where(.painted)>:where(h3){margin:11px 6px}']]) {
    const html = execFileSync('php', ['-r', '$f=require $argv[1];echo $f($argv[2]);', new URL('../fixtures/cover-flex-margins.php', import.meta.url).pathname, css], { encoding: 'utf8' });
    for (const width of [390, 1440]) {
      const source = await browser.newPage({ viewport: { width, height: 900 } });
      const copy = await browser.newPage({ viewport: { width, height: 900 } });
      // The image does not affect layout; keep the render independent of picsum.
      for (const page of [source, copy]) await page.route('https://picsum.photos/**', route => route.abort());
      await source.setContent(html);
      await copy.goto(new URL(`/?pagename=cover-margins-${variant}`, origin).href, { waitUntil: 'load' });
      assert.ok(await copy.locator('.wp-block-cover .blocks-engine-css-owned-flow.is-layout-flex').count(), 'painted panel uses native cover flex group');
      assert.ok(await copy.evaluate(() => [...document.styleSheets].some(sheet => {
        try { return [...sheet.cssRules].some(rule => rule.cssText.includes('.is-layout-flex > :is(*, div)')); } catch { return false; }
      })), 'WordPress core global layout reset is loaded');
      const expected = await probe(source);
      const actual = await probe(copy);
      console.log(JSON.stringify({ variant, width, source: expected, wordpress: actual }));
      for (const name of ['stack', 'painted']) {
        assert.equal(actual[name].length, expected[name].length, `${variant} ${width} ${name} child count`);
        actual[name].forEach((child, i) => {
          assert.deepEqual(child.margins, expected[name][i].margins, `${variant} ${width} ${name} child ${i} margins`);
          for (const axis of ['x', 'y']) assert.ok(Math.abs(child[axis] - expected[name][i][axis]) < 1, `${variant} ${width} ${name} child ${i} ${axis}: ${child[axis]} vs ${expected[name][i][axis]}`);
        });
      }
      await source.close();
      await copy.close();
    }
  }
} finally { await browser.close(); }
