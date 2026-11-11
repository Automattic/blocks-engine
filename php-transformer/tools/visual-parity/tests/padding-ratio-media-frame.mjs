import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $html = require $argv[1] . "/tests/fixtures/padding-ratio-media-frame.php"; $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($html)->toArray(); echo json_encode(array("html" => $html, "result" => $result));';
const { html, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
console.log(JSON.stringify({ markup: result.serialized_blocks, css }));
assert.equal((result.serialized_blocks.match(/<!-- wp:image /g) || []).length, 1, 'one native linked image');
const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await page.route('**/logo.webp', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="441" height="120"><rect width="100%" height="100%" fill="#456"/></svg>' }));
  const measure = async content => {
    await page.setContent(content);
    await page.locator('img').evaluate(image => image.decode());
    return page.evaluate(() => {
      const box = document.querySelector('img').getBoundingClientRect();
      return { width: box.width, height: box.height, bottom: box.bottom, paragraphTop: document.querySelector('p').getBoundingClientRect().top };
    });
  };
  const source = await measure(html.replace('<head>', '<head><base href="https://fixture.test/">'));
  const candidate = await measure(`<!doctype html><head><base href="https://fixture.test/"><style>.wp-block-image img{max-width:100%;height:auto;vertical-align:bottom}${css}</style></head><body>${result.serialized_blocks}</body>`);
  console.log(JSON.stringify({ source, candidate }));
  for (const box of [source, candidate]) {
    assert.ok(Math.abs(box.width - 441) < 0.1, 'image width is 441px');
    assert.ok(Math.abs(box.height - 120) < 0.1, 'image height is 120px');
    assert.ok(box.paragraphTop >= box.bottom, 'following paragraph stays below the image');
  }
} finally {
  await browser.close();
}
