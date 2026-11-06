import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./layered-navigation-display.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    for (const width of [390, 1440]) {
        const page = await browser.newPage({viewport: {width, height: 900}});
        await page.setContent(fixture.source);
        const expected = await page.locator('nav').evaluate(nav => getComputedStyle(nav).display);
        // Core's host layout surface and unlayered global layout default.
        await page.locator('nav').evaluate((nav, classes) => {
            nav.className = `wp-block-navigation is-layout-flex ${classes}`;
        }, fixture.classes);
        await page.addStyleTag({content: 'body .is-layout-flex{display:flex}'});
        const withoutRepair = fixture.css.replace(/:root:root:root :is\([^{}]*\)\{display:revert-layer\}/g, '');
        await page.addStyleTag({content: withoutRepair});
        if (width === 390) {
            assert.equal(await page.locator('nav').evaluate(nav => getComputedStyle(nav).display), 'flex', 'without the repair Core exposes the phone nav');
        }
        await page.addStyleTag({content: fixture.css});
        assert.equal(await page.locator('nav').evaluate(nav => getComputedStyle(nav).display), expected, `display at ${width}px`);
        assert.equal(expected, width === 390 ? 'none' : 'flex');
        await page.close();
    }
    console.log('Layered navigation display matches source at phone and desktop widths.');
} finally {
    await browser.close();
}
