import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const fixtures = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./cover-breakpoint-image-position.php', import.meta.url)), '--browser-fixtures'], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true, ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}) });
try {
  const page = await browser.newPage();
  for (const [name, fixture] of Object.entries(fixtures)) {
    const css = fixture.result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
    for (const width of [390, 1440]) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(`<style>${css}</style>${fixture.result.serialized_blocks}`);
      const actual = await page.locator('.wp-block-cover__image-background').evaluate(image => getComputedStyle(image).objectPosition);
      assert.equal(actual, width === 390 ? fixture.expected : '42% 50%', `${name} at ${width}`);
    }
  }
  console.log('cover-breakpoint-image-position-browser: ok');
} finally { await browser.close(); }
