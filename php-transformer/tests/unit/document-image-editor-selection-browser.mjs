import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// Whole-document captures use different markers from device-only islands.
// A desktop browser can still have a narrow Gutenberg canvas (List View and
// inspector open): neither document's native images may disappear there.
const html = `<style>
@media(min-width:1024px){.data-blocks-engine-capture-mobile-document{display:none!important}}
@media(max-width:1023px){.data-blocks-engine-capture-desktop-document{display:none!important}.data-blocks-engine-capture-mobile-document{display:contents!important}}
</style><main><p class="sr-only" style="display:none">Retained support text</p>
<div class="data-blocks-engine-capture-desktop-document" data-blocks-engine-capture-document-scope><section><img src="/desktop.svg" width="80" height="60" alt="Desktop"></section></div>
<div class="data-blocks-engine-capture-mobile-document" data-blocks-engine-capture-document-scope><section><img src="/mobile.svg" width="80" height="60" alt="Mobile"></section></div>
</main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60"/></svg>' }));
    const markup = result.serialized_blocks.replaceAll('src="/', 'src="https://fixture.test/');
    for (const width of [1440, 800]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<style>${css}</style>${markup}`);
        assert.deepEqual(await page.locator('img').evaluateAll(nodes => nodes.map(node => !node.getClientRects().length)),
            width === 1440 ? [false, true] : [true, false], 'front-end document visibility is unchanged');
        assert.equal(await page.getByText('Retained support text').isVisible(), false, 'support text stays hidden on the front end');
        await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${markup}</div>`);
        const support = page.getByText('Retained support text');
        // Serialized native paragraphs receive contenteditable from Gutenberg.
        await support.evaluate(node => node.contentEditable = 'true');
        await support.fill('Edited support text', { timeout: 2000 });
        const figures = page.locator('figure.wp-block-image');
        assert.equal(await figures.count(), 2);
        for (let index = 0; index < 2; index++) {
            await figures.nth(index).click({ timeout: 2000 });
            assert.ok(await figures.nth(index).evaluate(node => node.getBoundingClientRect().height > 0), 'each document image has an editor selection box');
        }
    }
    console.log('document image editor selection passed');
} finally {
    await browser.close();
}
