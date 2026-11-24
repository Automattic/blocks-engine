// An absolutely positioned inline SVG divider stretched by author CSS
// (1x1 viewBox, preserveAspectRatio="none", percentage height) keeps its
// out-of-flow box once materialized as core/image, so the page height and
// the divider box match the source (#7220).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $source = file_get_contents($argv[1] . "/tests/fixtures/positioned-stretched-svg-divider.html"); echo json_encode(array("source" => $source, "result" => (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($source)->toArray()));';
const { source, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const svgs = Object.fromEntries(result.assets.filter(asset => asset.kind === 'svg').map(asset => [asset.path, asset.content]));
assert.equal(Object.keys(svgs).length, 1, 'the divider is materialized as one SVG asset');
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 300 } });
    await page.route('https://fixture.test/**', route => route.fulfill({ contentType: 'image/svg+xml', body: svgs[new URL(route.request().url()).pathname.slice(1)] ?? '' }));
    const measure = async (html, selector) => {
      await page.setContent(html);
      await page.locator('img').evaluateAll(images => Promise.all(images.map(image => image.decode())));
      return page.evaluate(target => {
        const box = document.querySelector(target).getBoundingClientRect();
        return { page: document.documentElement.scrollHeight, width: box.width, height: box.height, x: box.x, y: box.y };
      }, selector);
    };
    const before = await measure(source, 'svg.divider-stroke');
    const after = await measure(
      `<!doctype html><head><base href="https://fixture.test/"><style>body{margin:0}/* Native core image baseline */.wp-block-image img{max-width:100%;height:auto;vertical-align:bottom;box-sizing:border-box}${css}</style></head><body>${result.serialized_blocks}</body>`,
      'figure.divider-stroke img'
    );
    console.log(JSON.stringify({ width, before, after }));
    assert.ok(Math.abs(after.page - before.page) <= 2, `page height ${after.page} matches source ${before.page} at ${width}px`);
    for (const axis of ['width', 'height', 'x', 'y']) {
      assert.ok(Math.abs(after[axis] - before[axis]) <= 1, `divider ${axis} ${after[axis]} matches source ${before[axis]} at ${width}px`);
    }
    await page.close();
  }
} finally {
  await browser.close();
}
