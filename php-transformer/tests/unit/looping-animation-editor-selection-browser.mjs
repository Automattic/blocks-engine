import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || '../../tools/visual-parity/node_modules/playwright/index.mjs');
const root = new URL('../..', import.meta.url).pathname;
// The editor must not retain a loop's translated end frame. Finite entrance
// animations, in contrast, still need their revealed end state.
const html = `<style>
.rail{overflow:hidden;width:600px}.track{display:flex;width:max-content;animation:40s linear infinite conveyor}.item{width:300px;flex:none}.item img{width:80px;height:40px}
@keyframes conveyor{to{transform:translateX(-50%)}}
.reveal{animation:appear 2s both}@keyframes appear{from{opacity:0}to{opacity:1}}
/* Neither a string token nor a custom property is an iteration count. */
.finite-string{animation:"infinite" 2s both}.finite-custom{--animation:infinite;animation:appear 2s both}
.text-loop{animation:40s linear infinite conveyor}
@keyframes infinite{from{opacity:0}to{opacity:1}}
</style><main><div class="rail"><div class="track">${Array.from({length:6}, (_, i) => `<div class="item"><img src="logo.svg" alt="Logo ${i}"></div>`).join('')}</div></div><p class="reveal">Revealed text</p><p class="finite-string">String name</p><p class="finite-custom">Custom property</p><p class="text-loop">Unrelated text loop</p></main>`;
const code = 'require $argv[1]."/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(stream_get_contents(STDIN))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root], {input:html, encoding:'utf8'}));
const assets = result.assets.filter(asset => asset.kind === 'css');
const shared = assets.filter(asset => asset.stylesheet_target !== 'editor').map(asset => asset.content).join('\n');
const editor = assets.filter(asset => asset.stylesheet_target === 'editor').map(asset => asset.content).join('\n');
assert.ok(!shared.includes('animation-fill-mode:none!important'), 'loop repair is editor-only');
const browser = await chromium.launch({executablePath:process.env.CHROME_PATH, headless:true});
try {
    const page = await browser.newPage({viewport:{width:1000,height:700}});
    await page.route('**/logo.svg', route => route.fulfill({contentType:'image/svg+xml', body:'<svg xmlns="http://www.w3.org/2000/svg" width="80" height="40"/>'}));
    const markup = result.serialized_blocks.replaceAll('src="logo.svg"', 'src="https://fixture.test/logo.svg"');
    await page.setContent(`<style>${shared}</style>${markup}`);
    assert.equal(await page.locator('.track').evaluate(node => getComputedStyle(node).animationIterationCount), 'infinite', 'front end retains live loop');
    // Preview surfaces can load the generated CSS without an editor canvas.
    // The loop-specific override must not turn off front-end keyframe fill.
    await page.addStyleTag({content:editor});
    assert.notEqual(await page.locator('.track').evaluate(node => getComputedStyle(node).animationFillMode), 'none', 'loop fill repair requires an editor canvas');
    await page.setContent(`<style>${shared}\n${editor}</style><div class="editor-styles-wrapper">${markup}</div>`);
    await page.waitForTimeout(100);
    assert.equal(await page.locator('.track').evaluate(node => getComputedStyle(node).transform), 'none', 'editor restores underlying track position');
    assert.notEqual(await page.locator('.text-loop').evaluate(node => getComputedStyle(node).transform), 'none', 'unrelated text loop retains its existing settled appearance');
    for (const image of await page.locator('figure.wp-block-image').all()) {
        await image.click({timeout:2000});
    }
    for (const selector of ['.reveal', '.finite-string', '.finite-custom']) {
        assert.equal(await page.locator(selector).evaluate(node => getComputedStyle(node).opacity), '1', `${selector}: finite reveal remains settled`);
    }
    console.log('looping animation editor selection passed');
} finally {
    await browser.close();
}
