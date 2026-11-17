import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress block-library/style.min.css');
const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./scoped-zero-reset.php', import.meta.url)), '--json'], {encoding: 'utf8'}));
const browser = await chromium.launch({headless: true});
try {
    const page = await browser.newPage({viewport: {width: 1440, height: 900}});
    const measure = () => page.locator('.list').evaluate(list => ({
        height: list.getBoundingClientRect().height,
        items: [...list.children].map(item => ({
            y: item.getBoundingClientRect().y,
            margin: getComputedStyle(item).marginBottom,
            inline: item.style.cssText,
        })),
    }));
    await page.setContent(fixture.source);
    const source = await measure();
    await page.setContent(`<style>${fixture.css}</style>${fixture.candidate}`);
    await page.addStyleTag({content: readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8')});
    const candidate = await measure();
    console.log(JSON.stringify({source, candidate}));
    assert.deepEqual(source.items.map(item => item.y), [12, 53, 94, 135]);
    assert.equal(source.height, 147);
    assert.deepEqual(candidate, source, 'Shared resets preserve authored pitch and the last-child override without inline zeros');
} finally {
    await browser.close();
}
