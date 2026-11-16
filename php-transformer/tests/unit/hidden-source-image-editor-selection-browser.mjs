import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// An entrance-animation runtime reveals this source card, but never runs in
// Gutenberg. Its linked SVG is lowered to a native, independently editable image.
const html = `<style>#card:not([data-enter]){visibility:hidden;transform:none!important}.icon{display:none;width:16px;height:16px}.icon svg{display:block;width:16px;height:16px}</style><main><a id="card" href="/about"><span>Read more</span><div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"><path d="M0 8h16"/></svg></div></a></main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], { input: html, encoding: 'utf8' }));
const imageFree = JSON.parse(execFileSync('php', ['-r', code, root], { input: '<main><p>Ordinary text</p></main>', encoding: 'utf8' }));
assert.equal(imageFree.assets.some(asset => asset.source === 'editor-static-state'), false, 'image-free output must not allocate an image repair stylesheet');
const imageRepair = result.assets.filter(asset => asset.kind === 'css' && asset.content.includes('figure.blocks-engine-synthetic-image-figure.wp-block-image{visibility:visible!important}'));
assert.equal(imageRepair.length, 1, 'image repair is emitted only when a native synthetic image survives compilation');
assert.equal(imageRepair[0].stylesheet_target, 'editor', 'image repair is not enqueued on the front end');
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH, headless: true });
try {
    const page = await browser.newPage();
    await page.route('**/*', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"/>' }));
    const markup = result.serialized_blocks.replaceAll('src="', 'src="https://fixture.test/');
    await page.setContent(`<style>${css}</style>${markup}`);
    const image = page.locator('figure.wp-block-image');
    assert.equal(await image.count(), 1, 'public transformer emits a native image');
    assert.equal(await image.evaluate(node => getComputedStyle(node).visibility), 'hidden', 'front-end pre-enter visibility is preserved');
    // Gutenberg substitutes client IDs for source anchors.
    const editorMarkup = markup.replace('id="card"', 'id="block-client-id"');
    await page.setContent(`<style>${css}</style><div class="editor-styles-wrapper">${editorMarkup}</div>`);
    assert.equal(await image.evaluate(node => getComputedStyle(node).visibility), 'visible', 'editor image must not inherit an unadvanced runtime state');
    assert.equal(await image.evaluate(node => node.getBoundingClientRect().width > 0), true, 'editor restores the hidden image carrier box');
    await image.click({ timeout: 2000 });
    console.log('hidden source image editor selection passed');
} finally {
    await browser.close();
}
