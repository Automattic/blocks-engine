// A captured device island inside a source size container (Wix background
// layers declare `container-type:size`) must stay selectable in the editor.
// Size containment ignores the expanded editor copies when sizing the
// container, so without an editor-only reset its auto height stays 0 and the
// following section paints over both images (issue #5920).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const html = `<style>@media(min-width:1024px){.data-blocks-engine-capture-mobile-only{display:none!important}}@media(max-width:1023px){.data-blocks-engine-capture-desktop-only{display:none!important}}.layer{display:grid}.background{position:relative;width:100%;height:100%;overflow:clip;container-type:size}.media{position:absolute;inset:0}.host{position:absolute;inset:0}.host img{width:100%;height:100%;object-fit:cover}.next{position:relative;background:#fff;height:200px}</style><main><section><div class="layer"><div class="background"><div class="media"><div class="host data-blocks-engine-capture-desktop-only"><img src="/desktop.svg" width="80" height="60" alt="Desktop"></div><div class="host data-blocks-engine-capture-mobile-only"><img src="/mobile.svg" width="80" height="60" alt="Mobile"></div></div></div></div></section><section class="next"><h2>Following content</h2></section></main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    const markup = result.serialized_blocks.replaceAll('src="/', 'src="https://fixture.test/');
    await page.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="60"><rect width="80" height="60"/></svg>' }));
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.setContent(`<style>${css}</style>${markup}`);
    assert.deepEqual(await page.locator('img').evaluateAll(nodes => nodes.map(node => !node.getClientRects().length)),
        [false, true], 'front-end device visibility is unchanged');
    await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${markup}</div>`);
    const figures = page.locator('figure.wp-block-image');
    assert.equal(await figures.count(), 2);
    for (let i = 0; i < 2; i++) await figures.nth(i).click({ timeout: 2000 });
    const imageBottom = await figures.evaluateAll(nodes => Math.max(...nodes.map(node => node.getBoundingClientRect().bottom)));
    const followingTop = await page.locator('h2').evaluate(node => node.closest('section').getBoundingClientRect().top);
    assert.ok(followingTop >= imageBottom, 'the size container expands around both editable variants');
    console.log('device image editor size container passed');
} finally {
    await browser.close();
}
