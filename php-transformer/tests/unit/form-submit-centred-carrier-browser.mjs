// CHROME_PATH=/usr/bin/google-chrome node tests/unit/form-submit-centred-carrier-browser.mjs
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./form-submit-centred-carrier.php', import.meta.url)), '--fixture'], { encoding: 'utf8' }));
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    const measure = () => page.locator('button').evaluate((node) => {
        const { x, width } = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return { x, width, center: x + width / 2, font: style.fontFamily, background: style.backgroundColor, color: style.color, radius: style.borderRadius };
    });
    const assertPaint = (imported, source, label) => {
        assert.deepEqual({ ...imported, x: 0, width: 0, center: 0 }, { ...source, x: 0, width: 0, center: 0 }, `${label} keeps source paint`);
    };
    // #5851: the provider saves its own core/button around the control marker
    // and re-applies the centring placement to that wrapper through its
    // overlay. The engine must not deliver the shift to the link a second
    // time, so the saved button stays at its source position.
    await page.setContent(fixture.form.source);
    const source = await measure();
    assert.equal(source.font, '"Courier New", monospace');
    await page.setContent(`<style>${fixture.form.css}</style>${fixture.form.markup}`);
    const imported = await measure();
    for (const key of [ 'x', 'width' ]) {
        assert.ok(Math.abs(imported[ key ] - source[ key ]) <= 1, `provider form ${key}: source=${source[ key ]}, imported=${imported[ key ]}`);
    }
    assertPaint(imported, source, 'provider form');
    // The engine's own lowering keeps the flattened carrier and must still
    // receive the whole centring set through it. The lowered link drops the
    // user-agent outset border WordPress core also drops, so the source
    // position compares on the anchored centre, not the left edge.
    await page.setContent(fixture.standalone.source);
    const plainSource = await measure();
    await page.setContent(`<style>${fixture.standalone.css}</style>${fixture.standalone.markup}`);
    const plainImported = await measure();
    assert.ok(Math.abs(plainImported.center - plainSource.center) <= 1, `flattened carrier centre: source=${plainSource.center}, imported=${plainImported.center}`);
    assertPaint(plainImported, plainSource, 'flattened carrier');
    console.log('Centred submit carrier browser geometry passed');
} finally {
    await browser.close();
}
