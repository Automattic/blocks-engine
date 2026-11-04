import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(readFileSync(process.env.EMPTY_FLEX_ITEM_ARTIFACT, 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    for (const shrink of [false, true]) {
    const boxes = {};
    for (const role of ['source', 'native']) {
        await page.setContent(fixture[role]);
        // Also exercise the issue's constrained title: the dummy shrinks to
        // 22px while the button remains 44px at the mobile viewport.
        if (shrink) await page.locator('.jw-mobile-menu__title').evaluate(node => { node.style.minWidth = '304px'; });
        boxes[role] = await page.locator('.jw-mobile-menu__button--dummy, .jw-mobile-menu__title, button').evaluateAll(nodes => nodes.map(node => {
            const { x, width } = node.getBoundingClientRect();
            const style = getComputedStyle(node);
            return { x, width, flex: style.flex };
        }));
        assert.equal(boxes[role].length, 3, `${role} retains all three flex items`);
        assert.equal(boxes[role][0].flex, '0 99999 auto', 'Scoped flex declarations apply to the dummy');
        assert.ok(boxes[role][0].width > 0, 'The dummy occupies main-axis space');
    }
    for (let i = 0; i < 3; i++) {
        for (const axis of ['x', 'width']) {
            assert.ok(Math.abs(boxes.source[i][axis] - boxes.native[i][axis]) < 1,
                `Item ${i} ${axis}: ${JSON.stringify(boxes)}`);
        }
    }
    if (shrink) assert.ok(Math.abs(boxes.native[0].width - 22) < 1, 'The dummy shrinks as in the captured header');
    console.log(JSON.stringify(boxes));
    }
    for (const width of [390, 1280]) {
        await page.setViewportSize({ width, height: 844 });
        const boxes = {};
        for (const role of ['source', 'native']) {
            await page.setContent(fixture.responsive[role]);
            assert.equal(await page.locator('.dummy').count(), 1, `${role} retains the responsive spacer`);
            boxes[role] = await page.locator('.dummy, .title, button').evaluateAll(nodes => nodes.map(node => {
                const { x, width } = node.getBoundingClientRect();
                return { x, width };
            }));
        }
        for (let i = 0; i < 3; i++) {
            for (const axis of ['x', 'width']) {
                assert.ok(Math.abs(boxes.source[i][axis] - boxes.native[i][axis]) < 1,
                    `Responsive ${width}px item ${i} ${axis}: ${JSON.stringify(boxes)}`);
            }
        }
        if (width === 390) assert.ok(boxes.native[0].width > 0, 'The phone spacer reserves space');
        else assert.equal(boxes.native[0].width, 0, 'The desktop row remains hidden');
    }
} finally {
    await browser.close();
}
