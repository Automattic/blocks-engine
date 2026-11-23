import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.FROZEN_HEIGHT_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [source, fixture] of Object.entries(fixtures)) {
            await page.setContent(fixture.native);
            const height = await page.locator('.cards').evaluate(el => el.getBoundingClientRect().height);
            assert.ok(Math.abs(height - Math.min(fixture.height, width * 0.8358)) < 0.02, `${source} at ${width}px keeps its own frozen height`);
            assert.equal(await page.locator('.cards').evaluate(el => getComputedStyle(el).overflow), 'hidden');
        }
    }
} finally {
    await browser.close();
}
