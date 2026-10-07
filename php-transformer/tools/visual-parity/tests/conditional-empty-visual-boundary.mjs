import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $source = require $argv[1] . "/tests/fixtures/conditional-empty-visual-boundary.php"; echo json_encode(array("source" => $source, "result" => (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($source)->toArray()));';
const { source, result } = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const measure = async html => {
      await page.setContent(`<!doctype html><style>body{margin:0}</style>${html}`);
      return page.evaluate(() => ['paint', 'divider', 'desktop-paint'].map(name => {
        const node = document.querySelector(`.${name}`);
        const box = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return { name, width: box.width, height: box.height, x: box.x, y: box.y, background: style.backgroundImage, color: style.backgroundColor };
      }));
    };
    const before = await measure(source);
    assert.equal(before[width <= 800 ? 0 : 2].height, width <= 800 ? 180 : 40, `source has painted geometry at ${width}px`);
    const after = await measure(`<style>${css}</style>${result.serialized_blocks}`);
    assert.deepEqual(after, before, `conditional box geometry and paint conserved at ${width}px`);
    assert.equal(await page.locator('.inert,.zero-box,.state-only').count(), 0, 'inert and interaction-only boxes stay omitted');
    await page.close();
  }
  console.log('OK: conditional empty visual boundary browser regression (3 viewports)');
} finally {
  await browser.close();
}
