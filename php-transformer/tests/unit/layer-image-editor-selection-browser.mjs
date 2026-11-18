import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// A stacked slideshow: absolutely positioned slides share one box, and each
// slide's centered caption layer sits over its inline photo.
const html = `<style>.posts{position:relative;height:400px}.slide{position:absolute;top:0;left:0;width:100%;height:100%;overflow:hidden}.caption{position:absolute;left:50%;top:50%;width:90%;transform:translate(-50%,-50%);text-align:center}.slide img{position:relative;width:600px;height:400px}</style><div class="posts"><div class="slide" style="z-index:2"><div class="caption"><p>First slide</p></div><img src="https://fixture.test/one.svg" alt="One"></div><div class="slide" style="z-index:1;opacity:0"><div class="caption"><p>Second slide</p></div><img src="https://fixture.test/two.svg" alt="Two"></div></div><section><h2>Following content</h2></section>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('https://fixture.test/**', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400"/></svg>' }));
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<style>${css}</style>${result.serialized_blocks}`);
        assert.deepEqual(await page.locator('.slide').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).position)),
            ['absolute', 'absolute'], 'front-end slides keep their stacked layers');
        assert.equal(await page.locator('.caption').first().evaluate(node => getComputedStyle(node).position), 'absolute',
            'front-end caption keeps its overlay');
        await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
        const figures = page.locator('figure.wp-block-image');
        assert.equal(await figures.count(), 2);
        for (let i = 0; i < 2; i++) {
            const figure = figures.nth(i);
            await figure.evaluate(node => node.addEventListener('click', () => { node.dataset.clicked = 'true'; }));
            await figure.click({ timeout: 2000 });
            assert.equal(await figure.getAttribute('data-clicked'), 'true', `stacked slide image ${i + 1} is selectable in the editor`);
        }
        await page.locator('p').first().click({ timeout: 2000 });
        await page.locator('p').nth(1).click({ timeout: 2000 });
    }
    console.log('layer image editor selection passed');
} finally {
    await browser.close();
}
