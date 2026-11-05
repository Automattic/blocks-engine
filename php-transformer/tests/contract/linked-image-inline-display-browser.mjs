import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.LINKED_INLINE_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [display, fixture] of Object.entries(fixtures)) {
            const heights = {};
            for (const role of ['source', 'native']) {
                await page.setContent('<style>body{font-size:16px;line-height:24px}.wp-block-image a{display:inline-block}</style>' + fixture[role]);
                await page.locator('img').evaluate(img => img.decode());
                heights[role] = await page.locator('figure').evaluate(el => el.getBoundingClientRect().height);
                assert.equal(await page.locator('img').evaluate(el => getComputedStyle(el).display), display);
                if (display === 'inline') {
                    const imageHeight = await page.locator('img').evaluate(el => el.getBoundingClientRect().height);
                    assert.equal(heights[role], imageHeight, `${width}px ${role}: no descender gap`);
                }
            }
            assert.equal(heights.native, heights.source, `${width}px ${display}: retained figure height`);
        }
    }
} finally {
    await browser.close();
}
