import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $source = file_get_contents($argv[1] . "/tests/fixtures/empty-css-sized-paint.html"); echo json_encode(array("source" => $source, "result" => (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($source)->toArray()));';
const { source, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const measure = async html => {
      await page.setContent(html);
      return page.locator('.swatch,.chip').evaluateAll(nodes => nodes.map(node => {
        const box = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return { width: box.width, height: box.height, x: box.x, y: box.y, color: style.backgroundColor, border: style.border, radius: style.borderRadius };
      }));
    };
    const before = await measure(source);
    const after = await measure(`<!doctype html><style>${css}</style>${result.serialized_blocks}`);
    assert.deepEqual(after, before, `source box and paint conserved at ${width}px`);
    await page.locator('.chip').first().evaluate(node => { node.style.backgroundColor = 'rgb(1, 2, 3)'; });
    assert.equal(await page.locator('.chip').first().evaluate(node => getComputedStyle(node).backgroundColor), 'rgb(1, 2, 3)', 'native background remains editable');
    console.log(`${width}px: ${before[0].width}×${before[0].height} source = imported`);
    await page.close();
  }
} finally {
  await browser.close();
}
