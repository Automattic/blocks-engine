import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
const html = `<style>.hero{position:relative;overflow:hidden}.backdrop{position:absolute;inset:0}.photo{width:100%;height:100%;object-fit:cover}.shade{position:absolute;inset:0;background:#0004}.content{position:relative;padding:100px}</style><section class="hero"><div class="backdrop"><img class="photo" src="https://fixture.test/photo.svg" alt=""><div class="shade"></div></div><div class="content"><h1>Foreground heading</h1><p>Foreground text</p></div></section>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('https://fixture.test/**', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180"/></svg>' }));
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<style>${css}</style>${result.serialized_blocks}`);
        assert.equal(await page.locator('.backdrop').evaluate(node => getComputedStyle(node).position), 'absolute');
        await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${result.serialized_blocks}</div>`);
        await page.locator('img').evaluate(image => image.decode());
        const figure = page.locator('figure');
        await figure.evaluate(node => node.addEventListener('click', () => { node.dataset.clicked = 'true'; }));
        await figure.click({ timeout: 2000 });
        assert.equal(await figure.getAttribute('data-clicked'), 'true', 'background image remains pointer-selectable behind foreground content');
        await page.locator('h1').click({ timeout: 2000 });
        await page.locator('p').click({ timeout: 2000 });
        const image = await figure.boundingBox();
        const content = await page.locator('.content').boundingBox();
        assert.ok(content.y >= image.y + image.height, 'editor separates background image and foreground content hit boxes');
    }
    console.log('background fill image editor selection passed');
} finally {
    await browser.close();
}
