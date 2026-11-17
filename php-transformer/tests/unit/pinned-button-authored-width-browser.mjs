// WP_ROOT=/path/to/wordpress node tests/unit/pinned-button-authored-width-browser.mjs
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./pinned-button-authored-width.php', import.meta.url)), '--fixture'], {encoding: 'utf8'}));
const coreCss = ['button', 'buttons'].map(block => readFileSync(`${process.env.WP_ROOT}/wp-includes/blocks/${block}/style.min.css`, 'utf8')).join('\n');
const browser = await chromium.launch({executablePath: process.env.CHROME_PATH || '/usr/bin/google-chrome', args: ['--no-sandbox']});
try {
    const page = await browser.newPage({viewport: {width: 1280, height: 800}});
    await page.setContent(fixture.source);
    const source = await page.locator('a.btn').boundingBox();
    for (const css of [coreCss + fixture.css, fixture.css + coreCss]) {
        await page.setContent(`<style>${css}</style>${fixture.markup}`);
        const link = page.locator('a.wp-block-button__link');
        const actual = await link.boundingBox();
        assert.ok(Math.abs(actual.width - source.width) <= 1, `Link width ${actual.width} vs source ${source.width}`);
        const label = await link.evaluate(element => {
            const range = document.createRange();
            range.selectNodeContents(element);
            const text = range.getBoundingClientRect();
            const box = element.getBoundingClientRect();
            return {contained: text.left >= box.left - 1 && text.right <= box.right + 1};
        });
        assert.ok(label.contained, 'Full label must remain on the link background');
        console.log(`Source ${source.width.toFixed(2)}px; imported ${actual.width.toFixed(2)}px; label contained`);
    }
    // Fixture 89: a low-specificity link reset must not beat the button
    // color lifted into theme.json, in either stylesheet load order.
    await page.setContent(fixture.color.source);
    const sourceColor = await page.locator('a').evaluate(element => getComputedStyle(element).color);
    assert.equal(sourceColor, 'rgb(23, 35, 29)');
    const globalStyles = ':root :where(.wp-element-button,.wp-block-button__link){color:#17231d}';
    for (const css of [globalStyles + fixture.color.css, fixture.color.css + globalStyles]) {
        await page.setContent(`<style>${coreCss}${css}</style>${fixture.color.markup}`);
        const actualColor = await page.locator('a.wp-block-button__link').evaluate(element => getComputedStyle(element).color);
        assert.equal(actualColor, sourceColor, 'Generic color:inherit must lose to the lifted button color');
        console.log(`Source and imported button label color: ${actualColor}`);
    }
} finally {
    await browser.close();
}
