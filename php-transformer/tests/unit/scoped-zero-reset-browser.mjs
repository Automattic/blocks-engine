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
    const cases = [
        {
            name: 'Losing reset preserves source pitch and last-child override',
            selector: '.list',
            measure: list => ({
                height: list.getBoundingClientRect().height,
                items: [...list.children].map(item => ({
                    y: item.getBoundingClientRect().y,
                    margin: getComputedStyle(item).marginBottom,
                })),
            }),
            check: source => {
                assert.deepEqual(source.items.map(item => item.y), [12, 53, 94, 135]);
                assert.equal(source.height, 147);
            },
        },
        {
            name: 'Winning scoped padding reset neutralises core button chrome',
            selector: 'a[role="button"], .wp-block-button__link',
            measure: button => ({
                width: button.getBoundingClientRect().width,
                padding: getComputedStyle(button).padding,
            }),
            check: source => {
                assert.equal(source.width, 196);
                assert.equal(source.padding, '0px');
            },
        },
    ];
    for (const row of cases) {
        await page.setContent(fixture.source);
        const source = await page.locator(row.selector).evaluate(row.measure);
        row.check(source);
        await page.setContent(`<style>${fixture.css}</style>${fixture.candidate}`);
        await page.addStyleTag({content: readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8')});
        const candidate = await page.locator(row.selector).evaluate(row.measure);
        console.log(JSON.stringify({name: row.name, source, candidate}));
        assert.deepEqual(candidate, source, row.name);
    }
} finally {
    await browser.close();
}
