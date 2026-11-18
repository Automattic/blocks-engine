import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const routes = JSON.parse(readFileSync(process.env.RESTYLE_ROUTES, 'utf8'));
const tree = JSON.parse(readFileSync(new URL('../fixtures/box-tree/index.json', import.meta.url), 'utf8'));
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage();
    for (const [name, viewport] of Object.entries(tree.viewports)) {
        await page.setViewportSize(viewport);
        await page.goto(routes.pages[0].url, { waitUntil: 'networkidle' });
        const actual = await page.locator('h1').evaluate(node => {
            const css = getComputedStyle(node);
            return { color: css.color, opacity: css.opacity };
        });
        const expected = {
            color: process.env.BOX_TREE_EDITED === '1' ? 'rgb(0, 170, 85)' : name === 'desktop' ? 'rgb(0, 0, 0)' : 'rgb(0, 0, 180)',
            opacity: name === 'desktop' ? '0.75' : '1',
        };
        assert.deepEqual(actual, expected, `${name}: captured paint survives core styles, but editor color wins`);
    }
} finally {
    await browser.close();
}
console.log('PASS: WordPress evidence-route computed styles and editor override');
