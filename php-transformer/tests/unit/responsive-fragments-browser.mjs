// Run responsive-fragments.php first (executes the theme render filter).
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/responsive-fragments.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 700 } });
    await page.route('https://example.test/**', route => route.fulfill({ contentType: 'text/html', body: `<style>${fixture.css}</style>${fixture.header}${fixture.content}` }));
    await page.goto('https://example.test/');
    await page.addScriptTag({ content: fixture.script });
    const expected = width === 390 ? 'section--data-blocks-engine-capture-mobile' : 'section';
    const check = async () => {
      await page.waitForFunction(id => Math.abs(document.getElementById(id).getBoundingClientRect().top) < 2, expected);
      assert.equal(await page.evaluate(() => location.hash), '#section', 'Authored hash stays unchanged');
    };
    // Same-page menu navigation, repeated same-hash click, then incoming hash.
    await page.locator('a[href$="#section"]:visible').first().click();
    await check();
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('a[href$="#section"]:visible').first().click();
    await check();
    await page.evaluate(() => { location.hash = ''; window.scrollTo(0, 0); location.hash = '#section'; });
    await check();
    // Incoming fragment: the resolver boots after the browser's native jump
    // (which selects the hidden desktop id at phone width).
    await page.goto('https://example.test/#section');
    await page.addScriptTag({ content: fixture.script });
    await check();
    await page.close();
  }
} finally { await browser.close(); }
console.log('Responsive fragments browser contract passed');
