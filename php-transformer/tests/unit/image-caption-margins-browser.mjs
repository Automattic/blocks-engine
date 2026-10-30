import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
// Use the real WordPress stylesheet, not a hand-written approximation.
assert.ok(process.env.BLOCK_LIBRARY_CSS, 'Set BLOCK_LIBRARY_CSS to WordPress wp-includes/css/dist/block-library/style.min.css');
const blockLibrary = readFileSync(process.env.BLOCK_LIBRARY_CSS, 'utf8');
const browser = await chromium.launch({headless: true});
try {
    for (const margin of ['', 'margin:3px 0 7px', 'margin-top:0.5em;margin-bottom:1em']) {
        const fixture = JSON.parse(execFileSync('php', [fileURLToPath(new URL('./image-caption-margins-fixture.php', import.meta.url)), margin], {encoding: 'utf8'}));
        const page = await browser.newPage({viewport: {width: 1440, height: 900}});
        await page.route('https://picsum.photos/**', route => route.fulfill({contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"/>'}));
        const measure = () => page.locator('figcaption').evaluateAll(elements => elements.map(element => ({
            top: getComputedStyle(element).marginTop,
            bottom: getComputedStyle(element).marginBottom,
            gap: element.parentElement.querySelector('img').getBoundingClientRect().bottom - element.getBoundingClientRect().bottom,
        })));
        await page.setContent(fixture.source);
        const source = await measure();
        await page.setContent(`<style>${fixture.css}</style>${fixture.candidate}`);
        // Load core last: the restatement must win by specificity, not load order.
        await page.addStyleTag({content: blockLibrary});
        const candidate = await measure();
        console.log(JSON.stringify({margin: margin || 'UA default', source, candidate}));
        assert.deepEqual(candidate.map(({top, bottom}) => ({top, bottom})), source.map(({top, bottom}) => ({top, bottom})), 'used vertical margins match the source');
        if (!margin) {
            assert.equal(candidate[2].gap, 0, 'overlay caption is flush with the image bottom');
        }
        if (margin.includes('0.5em')) {
            assert.doesNotMatch(fixture.css, /figcaption\{margin-(?:top|bottom):/, 'no redundant margin carrier for core defaults');
        }
        await page.close();
    }
} finally {
    await browser.close();
}
