import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.SYNTHETIC_PARENT_BOX_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [name, fixture] of Object.entries(fixtures)) {
            const gaps = {};
            for (const role of ['source', 'native']) {
                await page.setContent(fixture[role]);
                gaps[role] = await page.locator('a[href^="mailto:"]').evaluate(link => {
                    const child = link.closest('p') || link;
                    return child.nextElementSibling.getBoundingClientRect().top - link.getBoundingClientRect().top;
                });
            }
            assert.equal(gaps.native, gaps.source, `${name} at ${width}px preserves the link gap`);
            if (name === 'margin') assert.equal(gaps.source - 24, 8, '8px below the 24px link box');
        }
    }
} finally {
    await browser.close();
}
