import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtures = JSON.parse(execFileSync('php', [new URL('./projected-core-layer-cascade.php', import.meta.url).pathname, '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage();
    const measure = async html => {
        await page.setContent(html);
        return page.locator('a[href]').evaluateAll(links => links.map(link => {
            const box = link.getBoundingClientRect();
            const icon = link.querySelector('svg,img');
            return {width: box.width, height: box.height, iconTop: icon ? icon.getBoundingClientRect().top - box.top : null};
        }));
    };
    for (const width of [390, 1440]) {
        await page.setViewportSize({width, height: 900});
        for (const [name, fixture] of Object.entries(fixtures)) {
            const expected = await measure(fixture.source);
            // Core's unlayered defaults are deliberately loaded after the
            // projected sheet: specificity must bridge them in either order.
            const actual = await measure(`<style>${fixture.css}</style><style>.wp-block-button__link{box-sizing:border-box;display:inline-block;text-decoration:none;padding:calc(.667em + 2px) calc(1.333em + 2px);border-radius:9999px}.wp-block-button{display:inline-block}</style>${fixture.blocks}`);
            assert.equal(actual.length, expected.length);
            for (let i = 0; i < expected.length; i++) {
                for (const key of Object.keys(expected[i])) {
                    assert.ok(Math.abs(actual[i][key] - expected[i][key]) < .1, `${name} ${width}px link ${i} ${key}: ${JSON.stringify({expected, actual})}`);
                }
            }
            if (name === 'hover') {
                await page.locator('a[href]').nth(1).hover();
                assert.equal(await page.locator('a[href]').nth(1).evaluate(link => getComputedStyle(link).gap), '10px');
            }
        }
    }
    console.log('Projected layered and plain icon links match source at phone and desktop widths.');
} finally {
    await browser.close();
}
