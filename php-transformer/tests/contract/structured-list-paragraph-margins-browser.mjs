import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(readFileSync(process.env.STRUCTURED_LIST_MARGIN_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const [name, fixture] of Object.entries(fixtures)) {
            const steps = {};
            for (const role of ['source', 'native']) {
                await page.setContent(fixture[role]);
                const tops = await page.locator('li').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect().top));
                steps[role] = tops.slice(1).map((top, i) => top - tops[i]);
            }
            assert.deepEqual(steps.native, steps.source, `${name} at ${width}px preserves item rhythm`);
            if (name === 'ua') assert.deepEqual(steps.source, [42, 42], '26px line height plus 1em collapsed margin');
        }
    }
} finally {
    await browser.close();
}
