import assert from 'node:assert/strict';
const {chromium} = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const url = process.env.SHELL_LANDMARK_TEST_URL;
const variant = process.env.SHELL_LANDMARK_FRAMED === '1' ? 'framed' : 'body-level';
assert.ok(url, 'Actual Core HTTP runtime is required');
const browser = await chromium.launch();
try {
    for (const path of ['/', '/other/']) {
        const page = await browser.newPage({viewport: {width: 390, height: 900}});
        await page.goto(new URL(path, url).href, {waitUntil: 'domcontentloaded'});
        const headers = await page.locator('header').evaluateAll(nodes => nodes.map(node => {
            const style = getComputedStyle(node);
            const box = node.getBoundingClientRect();
            const link = node.querySelector('a').getBoundingClientRect();
            return {inset: link.top - box.top, transport: !!node.closest('.wp-block-template-part'), padding: [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft], background: style.backgroundColor, nested: !!node.parentElement.closest('header'), links: node.querySelectorAll('a').length};
        }));
        assert.equal(headers.length, 1, `${variant} ${path} renders exactly one <header>: ${JSON.stringify(headers)}`);
        assert.deepEqual(headers[0].padding, ['12px', '12px', '12px', '12px'], `${variant} ${path} header carries the authored 12px padding once: ${JSON.stringify(headers)}`);
        assert.equal(headers[0].background, 'rgb(250, 250, 250)', `${variant} ${path} header carries the authored background`);
        // The padding is applied once: a second box around or inside the
        // landmark would push its navigation further from the header edge.
        assert.equal(headers[0].inset, 12, `${variant} ${path} header navigation sits 12px inside the one padded box: ${JSON.stringify(headers)}`);
        assert.equal(headers[0].transport, false, `${variant} ${path} header is not wrapped in a template-part transport box`);
        assert.equal(headers[0].nested, false, `${variant} ${path} header is not nested in another header`);
        assert.equal(headers[0].links, 2, `${variant} ${path} header holds its navigation`);
        await page.close();
    }
} finally {
    await browser.close();
}
console.log(`Shell landmark box browser proof passed (${variant})`);
