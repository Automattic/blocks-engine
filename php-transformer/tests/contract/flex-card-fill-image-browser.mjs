import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(process.env.FLEX_CARD_FILL_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1200, height: 900 } });
    const boxes = {};
    for (const role of ['source', 'native']) {
        await page.setContent('<style>.wp-block-image{margin:0 0 1em}.wp-block-image img{height:auto;max-width:100%;vertical-align:bottom}</style>' + fixture[role]);
        boxes[role] = await page.locator('.t').evaluateAll(nodes => nodes.map(node => {
            const { x, width } = node.getBoundingClientRect();
            return { x, width };
        }));
    }
    assert.equal(boxes.native.length, 2);
    for (let i = 0; i < boxes.source.length; i++) {
        for (const axis of ['x', 'width']) {
            assert.ok(Math.abs(boxes.source[i][axis] - boxes.native[i][axis]) < 1,
                `Card ${i} ${axis}: ${JSON.stringify(boxes)}`);
        }
    }
    console.log(JSON.stringify(boxes));
} finally {
    await browser.close();
}
