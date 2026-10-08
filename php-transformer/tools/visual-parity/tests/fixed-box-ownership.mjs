import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const sourceFixture = `<style>
  .centered { max-width:1046px; margin:0 auto; padding:20px; box-sizing:border-box }
  .brand { margin:0; line-height:20px }
  .spacer { height:120px }
  .fixed-box, .class-fixed { position:fixed }
</style>
<section class="fixed-box" style="position:fixed;width:100%;height:80px;top:0;left:0;z-index:10">
  <div class="centered"><p class="brand">Editable brand</p></div>
</section>
<div class="spacer"></div><p class="copy">Editable body</p>
<section class="class-fixed" style="width:25%;height:40px;right:0;bottom:0"><p class="brand">Corner</p></section>
<section class="inline-fixed" style="position:fixed;width:50%;height:60px;left:0;bottom:0"><p class="brand">Inline only</p></section>
<details class="disclosure"><summary>Open panel</summary>
  <div class="overlay-panel" role="dialog" aria-modal="true" style="position:fixed;inset:0;width:100%;height:100%;z-index:50;background:#fff"><p>Dialog content</p></div>
</details>`;
const transformed = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
$css = array_filter($result['assets'] ?? array(), static fn(array $asset): bool => 'css' === ($asset['kind'] ?? ''));
echo json_encode(array('markup' => $result['serialized_blocks'], 'css' => implode("\\n", array_column($css, 'content'))));
`, transformerRoot, Buffer.from(sourceFixture).toString('base64')], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width:1440, height:900 } });
  const baseCss = 'body{margin:0;padding-top:24px;min-height:1800px;font:16px/20px sans-serif}p{margin:0}';
  const measure = () => page.evaluate(() => Object.fromEntries(['.fixed-box', '.fixed-box .brand', '.spacer', '.copy', '.class-fixed', '.inline-fixed'].map((selector) => {
    const element = document.querySelector(selector);
    const rect = element.getBoundingClientRect();
    return [selector, { x:rect.x, y:rect.y, width:rect.width, height:rect.height, position:getComputedStyle(element).position }];
  })));
  for (const width of [1440, 800]) {
    await page.setViewportSize({ width, height:900 });
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.setContent(`<style>${baseCss}</style>${sourceFixture}`);
    const source = await measure();
    await page.setContent(`<style>${baseCss}${transformed.css}</style>${transformed.markup}`);
    const imported = await measure();
    console.log(JSON.stringify({ width, source, imported }));
    assert.deepEqual(imported, source, 'fixed boxes retain dimensions, zero insets, centered content and spacer flow');
    await page.evaluate(() => window.scrollTo(0, 100));
    assert.equal((await measure())['.fixed-box'].y, 0, 'frontend header remains viewport-fixed');
    await page.locator('.copy').click();
    assert.equal(await page.locator('.overlay-panel').isVisible(), false, 'closed fixed dialog does not intercept body clicks');
    await page.locator('.disclosure summary').click();
    const panel = await page.locator('.overlay-panel').evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return { x:rect.x, y:rect.y, width:rect.width, height:rect.height, position:getComputedStyle(element).position };
    });
    assert.deepEqual(panel, { x:0, y:0, width, height:900, position:'fixed' }, 'opened dialog retains its complete viewport box');
    await page.locator('.disclosure summary').press('Space');
    assert.equal(await page.locator('.overlay-panel').isVisible(), false, 'native disclosure closes the fixed dialog again');
    await page.locator('.copy').click();
  }
  await page.setViewportSize({ width:1440, height:900 });
  await page.evaluate(() => window.scrollTo(0, 0));
  // Model Core's block-root positioning in its editor canvas, as the existing
  // layout-shell editor geometry gate does. Use the production generated CSS.
  await page.setContent(`<style>${baseCss}${transformed.css}.block-editor-block-list__block{position:relative}</style><div class="editor-styles-wrapper">${transformed.markup}</div>`);
  await page.locator('section').evaluateAll((elements) => elements.forEach((element) => element.classList.add('block-editor-block-list__block')));
  const editor = await measure();
  console.log(JSON.stringify({ editor }));
  for (const selector of ['.fixed-box', '.class-fixed', '.inline-fixed']) {
    assert.equal(editor[selector].position, 'relative', `${selector} is editable in canvas flow`);
    assert.equal(await page.locator(selector).evaluate((element) => getComputedStyle(element).inset), '0px', `${selector} has zero used relative offsets in the editor`);
  }
  assert.equal(editor['.fixed-box'].y, 24);
  assert.equal(editor['.fixed-box'].width, 1440);
  assert.equal(editor['.fixed-box'].height, 80);
  assert.equal(editor['.spacer'].y, 104, 'editor spacer follows accommodated chrome');
  const brand = page.locator('.fixed-box .brand');
  await brand.evaluate((element) => element.contentEditable = 'true');
  await brand.fill('Updated brand');
  assert.equal(await brand.textContent(), 'Updated brand', 'accommodated native paragraph remains reachable for text editing');
  await page.evaluate(() => window.scrollTo(0, 100));
  assert.equal((await measure())['.fixed-box'].y, -76, 'editor chrome scrolls with the canvas rather than covering other blocks');
  assert.match(transformed.markup, /wp:paragraph/, 'brand and body remain native editable paragraphs');
  assert.doesNotMatch(transformed.markup, /wp:html/, 'fixed geometry does not require a raw HTML fallback');
} finally {
  await browser.close();
}
console.log('Fixed box ownership browser geometry passed');
