import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const widths = [100, 110, 120, 130, 143];
const fixture = {
    markup: `<div class="exact-menu blocks-engine-css-owned-inline-flow" style="width:603px;height:49px;overflow:hidden">${widths
        .map(
            (width, index) =>
                `<div class="wp-block-group" style="display:inline-block;width:${width}px;height:49px"><a href="/${index}">Item ${index}</a></div>`
        )
        .join('')}</div>`,
    css: ':where(.blocks-engine-css-owned-inline-flow){display:flex;flex-wrap:wrap;align-items:baseline;gap:0}:where(.blocks-engine-css-owned-inline-flow)>*{flex:none}@media(max-width:700px){.exact-menu{display:none}}',
};

// Gutenberg may put formatting whitespace between nested block delimiters.
const serializedWithWhitespace = fixture.markup.replaceAll('--><!-- wp:group', '-->\n<!-- wp:group');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1008, height: 400 } });
    await page.setContent(`<style>*{box-sizing:border-box}${fixture.css}</style>${serializedWithWhitespace}`);
    const boxes = await page.locator('.exact-menu > .wp-block-group').evaluateAll((items) =>
        items.map((item) => {
            const box = item.getBoundingClientRect();
            return { x: box.x, y: box.y, width: box.width, height: box.height };
        })
    );
    const menu = await page.locator('.exact-menu').evaluate((item) => {
        const box = item.getBoundingClientRect();
        return { x: box.x, y: box.y, width: box.width, height: box.height };
    });

    assert.equal(menu.width, 603);
    assert.equal(menu.height, 49);
    assert.deepEqual(boxes.map(({ width }) => width), [100, 110, 120, 130, 143]);
    assert.ok(boxes.every(({ y }) => y === boxes[0].y), 'all five items remain on one row');
    assert.equal(boxes.at(-1).x + boxes.at(-1).width, menu.x + menu.width);

    await page.setViewportSize({ width: 600, height: 400 });
    assert.equal(await page.locator('.exact-menu').evaluate((item) => getComputedStyle(item).display), 'none');
} finally {
    await browser.close();
}

console.log('Exact-fit inline flow geometry passed');
