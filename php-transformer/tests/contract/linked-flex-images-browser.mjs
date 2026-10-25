import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(process.env.LINKED_FLEX_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        const boxes = {};
        for (const role of ['source', 'native']) {
            // Core's link rule is deliberately present: without the authored
            // display:block the rebuilt anchor becomes a zero-width inline box.
            await page.setContent('<style>.wp-block-image a{display:inline-block}</style>' + fixture[role]);
            boxes[role] = await page.locator('img').evaluateAll(nodes => nodes.map(node => {
                const { x, y, width, height } = node.getBoundingClientRect();
                return { x, y, width, height };
            }));
        }
        for (let i = 0; i < 3; i++) {
            for (const key of ['x', 'y', 'width', 'height']) {
                assert.ok(Math.abs(boxes.source[i][key] - boxes.native[i][key]) < 1,
                    `${width}px tile ${i} ${key}: ${JSON.stringify(boxes)}`);
            }
        }
        console.log(`${width}px: source/native tiles ${boxes.native.map(b => `${b.width}×${b.height}`).join(', ')}`);
    }
} finally {
    await browser.close();
}
