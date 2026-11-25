import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(process.env.RICHTEXT_BORDER_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const role of ['source', 'native']) {
        // Core's border support rule must not activate the source's inert color.
        await page.setContent('<!doctype html><style>html :where([style*="border-color"]){border-style:solid}</style>' + fixture[role]);
        const paint = await page.locator('h1').locator(role === 'source' ? 'span' : 'mark').evaluate(el => {
            const style = getComputedStyle(el);
            return {
                background: style.backgroundColor,
                borders: ['Top', 'Right', 'Bottom', 'Left'].map(side => [style[`border${side}Style`], style[`border${side}Width`]]),
            };
        });
        assert.equal(paint.background, 'rgb(227, 234, 230)', role);
        assert.deepEqual(paint.borders, Array(4).fill(['none', '0px']), role);
    }
} finally {
    await browser.close();
}
