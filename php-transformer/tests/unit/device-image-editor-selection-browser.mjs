import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const html = `<style>@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}.grid{display:grid;grid-template-rows:60px 30px}.grid>*{grid-area:1/1}.clip{position:relative;height:30px;overflow:clip}.media{position:absolute;inset:0}.stack{position:relative;height:60px;aspect-ratio:4/3}.host{display:block;position:absolute;inset:0;width:80px;height:60px}.host img{position:absolute;width:100%;height:100%}</style><main><div class="grid"><div class="clip"><div class="media"><div class="stack"><div class="host data-blocks-engine-capture-desktop-only"><img src="/desktop.svg" width="80" height="60" alt="Desktop"></div><div class="host data-blocks-engine-capture-mobile-only"><img src="/mobile.svg" width="80" height="60" alt="Mobile"></div></div></div></div><h2>Following content</h2></div></main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    const markup = result.serialized_blocks.replaceAll('src="/', 'src="https://fixture.test/');
    await page.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60"/></svg>' }));
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<style>${css}</style>${markup}`);
        assert.deepEqual(await page.locator('img').evaluateAll(nodes => nodes.map(node => !node.getClientRects().length)),
            width === 1440 ? [false, true] : [true, false], 'front-end device visibility is unchanged');
        await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${markup}</div>`);
        const figures = page.locator('figure.wp-block-image');
        assert.equal(await figures.count(), 2);
        for (let i = 0; i < 2; i++) await figures.nth(i).click({ timeout: 2000 });
        await page.locator('h2').click({ timeout: 2000 });
        const imageBottom = await figures.evaluateAll(nodes => Math.max(...nodes.map(node => node.getBoundingClientRect().bottom)));
        const headingTop = await page.locator('h2').evaluate(node => node.getBoundingClientRect().top);
        assert.ok(headingTop >= imageBottom, 'captured grid tracks expand around both editable variants');
    }
    console.log('device image editor selection passed');
} finally {
    await browser.close();
}
