import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.LINKED_INLINE_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [name, fixture] of Object.entries(fixtures)) {
            const { display, alignment, linkDisplay } = fixture;
            const heights = {};
            for (const role of ['source', 'native']) {
                await page.setContent('<!doctype html><style>body{font-size:16px;line-height:24px}.wp-block-image a{display:inline-block}</style>' + fixture[role]);
                await page.locator('img').evaluate(img => img.decode());
                heights[role] = await page.locator('figure').evaluate(el => el.getBoundingClientRect().height);
                assert.equal(await page.locator('img').evaluate(el => getComputedStyle(el).display), display);
                assert.equal(await page.locator('img').evaluate(el => getComputedStyle(el).verticalAlign), alignment);
                assert.equal(await page.locator('a').evaluate(el => getComputedStyle(el).display), linkDisplay);
                if (display === 'inline' && alignment === 'bottom') {
                    const imageHeight = await page.locator('img').evaluate(el => el.getBoundingClientRect().height);
                    assert.equal(heights[role], imageHeight, `${width}px ${role}: no descender gap`);
                }
            }
            if (alignment === 'baseline') {
                const imageHeight = await page.locator('img').evaluate(el => el.getBoundingClientRect().height);
                assert.ok(heights.native > imageHeight, `${width}px: retained baseline strut`);
            }
            assert.equal(heights.native, heights.source, `${width}px ${name}: retained figure height`);
        }
    }
} finally {
    await browser.close();
}
