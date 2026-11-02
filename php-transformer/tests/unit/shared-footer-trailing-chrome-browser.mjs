// Run shared-footer-trailing-chrome.php first.
import { readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import assert from 'node:assert/strict';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(`${tmpdir()}/shared-footer-trailing-chrome.json`, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
  for (const device of ['desktop', 'mobile']) {
    const page = await browser.newPage({ viewport: { width: device === 'desktop' ? 1000 : 390, height: 150 } });
    await page.setContent(`<style>.bar{position:fixed;left:0;right:0;bottom:0;height:60px;background:#333}.bar-spacer{height:60px}.data-blocks-engine-capture-${device === 'desktop' ? 'mobile' : 'desktop'}-document{display:none}</style>${fixture.content}${fixture.footer}`);
    const scope = page.locator(`.data-blocks-engine-capture-${device}-document`).filter({ has: page.locator('footer') });
    const footer = await scope.locator('footer').boundingBox();
    const spacer = await scope.locator('.bar-spacer').boundingBox();
    assert.ok(footer && spacer && spacer.y >= footer.y + footer.height, `${device}: spacer follows footer`);
    await page.close();
  }
} finally {
  await browser.close();
}
console.log('Shared footer trailing chrome browser contract passed');
