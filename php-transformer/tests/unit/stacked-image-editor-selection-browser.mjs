import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// Inline SVGs become native images. Their source boxes overlap intentionally,
// but their editor block roots must each remain selectable.
const html = `<style>.stack{position:relative;display:inline-block;width:160px;height:128px}
.icon{display:inline-block;position:absolute;inset:0;margin:auto}
.back{width:160px;height:128px}.front{width:80px;height:64px}</style>
<main><span class="stack"><svg class="icon back" viewBox="0 0 100 100"><circle cx="50" cy="50" r="50"/></svg><svg class="icon front" viewBox="0 0 100 100"><path d="M0 0h100v100H0z"/></svg></span><h2>Following heading</h2><p>Following paragraph</p></main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><path d="M0 0h100v100H0z"/></svg>' }));
    const markup = result.serialized_blocks.replaceAll('src="assets/', 'src="https://fixture.test/assets/');
    await page.setContent(`<style>${css}</style>${markup}`);
    const front = await page.locator('figure').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).display));
    assert.deepEqual(front, ['contents', 'contents'], 'front-end carriers remain transparent');
    await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
    const frontGeometry = await page.locator('img').evaluateAll(images => images.map(image => {
        const box = image.getBoundingClientRect();
        return { position: getComputedStyle(image).position, width: box.width, height: box.height };
    }));
    assert.deepEqual(frontGeometry, [
        { position: 'static', width: 160, height: 128 },
        { position: 'static', width: 80, height: 64 },
    ], 'editor flow overrides must not change the front-end source stack');
    assert.deepEqual(await page.locator('figure').evaluateAll(nodes => nodes.map(node => getComputedStyle(node).position)),
        ['absolute', 'absolute'], 'source positioning remains on front-end carriers');
    assert.equal(await page.locator('.stack').evaluate(node => node.getBoundingClientRect().height), 128,
        'front-end source container keeps its fixed height');
    await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${markup}</div>`);
    const figures = page.locator('figure');
    assert.equal(await figures.count(), 2);
    await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
    const stack = await page.locator('.stack').boundingBox();
    const heading = await page.locator('h2').boundingBox();
    const imageBoxes = await figures.evaluateAll(nodes => nodes.map(node => {
        const box = node.getBoundingClientRect();
        return { top: box.top, bottom: box.bottom };
    }));
    assert.ok(stack.y + stack.height >= Math.max(...imageBoxes.map(box => box.bottom)),
        'editor source stack expands to contain both image boxes');
    assert.ok(heading.y >= imageBoxes[1].bottom, 'following content cannot cover the editor image roots');
    await page.locator('h2').click({ timeout: 2000 });
    for (let i = 0; i < 2; i++) {
        await figures.nth(i).click({ timeout: 2000 });
        assert.equal(await figures.nth(i).evaluate(node => getComputedStyle(node).position), 'static');
    }
    console.log('stacked image editor selection passed');
} finally {
    await browser.close();
}
