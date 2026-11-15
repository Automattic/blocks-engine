import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const html = `<style>.grid{display:grid;grid-template-rows:60px 30px;container-type:size}.layer{position:absolute;inset:0}.host{display:block;width:80px;height:60px}.host img{position:absolute;width:100%;height:100%}.section{position:relative;min-height:60px}</style><main><section class="section"><div class="grid"><div class="layer"><div class="host"><img src="/one.svg" width="80" height="60" alt="One"></div></div><div class="layer"><div class="host"><img src="/two.svg" width="80" height="60" alt="Two"></div></div></div></section><section class="section"><h2>Following content</h2></section></main>`;
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
        assert.deepEqual(await page.locator('.layer').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).position)),
            ['absolute', 'absolute'], 'front-end background layers retain source positioning');
        assert.equal(await page.locator('.grid').evaluate(node => getComputedStyle(node).display), 'grid',
            'front-end source grid is unchanged');
        assert.equal(await page.locator('.grid').evaluate(node => getComputedStyle(node).containerType), 'size',
            'front-end source size containment is unchanged');
        await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${markup}</div>`);
        const figures = page.locator('figure.wp-block-image');
        assert.equal(await figures.count(), 2);
        for (let i = 0; i < 2; i++) await figures.nth(i).click({ timeout: 2000 });
        await page.locator('h2').click({ timeout: 2000 });
        const imageBottom = await figures.evaluateAll(nodes => Math.max(...nodes.map(node => node.getBoundingClientRect().bottom)));
        const headingTop = await page.locator('h2').evaluate(node => node.getBoundingClientRect().top);
        assert.ok(headingTop >= imageBottom, 'background layer ancestors expand so following sections cannot intercept image selection');
    }
    console.log('fill layer editor selection passed');
} finally {
    await browser.close();
}
