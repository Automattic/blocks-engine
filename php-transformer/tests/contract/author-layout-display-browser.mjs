import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {readFileSync} from 'node:fs';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./author-layout-display.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
if (!process.env.WORDPRESS_PATH) throw new Error('Set WORDPRESS_PATH to load Core block-library CSS.');
const coreCss = readFileSync(`${process.env.WORDPRESS_PATH}/wp-includes/css/dist/block-library/style.min.css`, 'utf8');
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
try {
    for (const width of [390, 768, 1440]) {
        const page = await browser.newPage({viewport: {width, height: 900}});
        await page.setContent(fixture.source);
        const expected = await page.locator('.icon').evaluate(icon => getComputedStyle(icon).display);
        for (const editor of [false, true]) {
            await page.setContent(`<style>${coreCss}\n${fixture.css}</style>${fixture.blocks}`);
            // Core adds layout classes at render time, not in saved markup.
            await page.locator('.icon').evaluate(icon => icon.classList.add('is-layout-flex'));
            if (editor) await page.locator('body').evaluate(body => body.classList.add('editor-styles-wrapper'));
            // Load Core's global layout rule last to exercise the cascade,
            // independently of stylesheet enqueue order.
            await page.addStyleTag({content: 'body .is-layout-flex{display:flex}'});
            assert.equal(await page.locator('.icon').evaluate(icon => getComputedStyle(icon).display), expected, `${width}px, editor=${editor}`);
            assert.equal(expected, 'none');
        }
        await page.close();
    }
    console.log('Authored hidden layout matches source on frontend and editor surfaces.');
} finally {
    await browser.close();
}
