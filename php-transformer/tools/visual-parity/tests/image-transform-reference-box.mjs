import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $rows = array(); foreach (require $argv[1] . "/tests/fixtures/image-transform-reference-box.php" as $fixture) { $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform("<style>" . $fixture["css"] . "</style>" . $fixture["html"])->toArray(); $fixture["compiled"] = $result["serialized_blocks"]; $fixture["assets"] = $result["assets"]; $rows[] = $fixture; } echo json_encode($rows);';
const fixtures = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 }));
const browser = await chromium.launch({ headless: true });
try {
  for (const fixture of fixtures) {
    const css = fixture.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 1200 } });
      const measure = async (html, styles) => {
        await page.setContent(`<!doctype html><style>${styles}</style>${html}`);
        await page.locator('img').evaluate(image => image.decode());
        const geometry = await page.locator('img').evaluate(image => {
          const box = image.getBoundingClientRect();
          const style = getComputedStyle(image);
          return { x: box.x, y: box.y, width: box.width, height: box.height, transform: style.transform, origin: style.transformOrigin, translate: style.translate, scale: style.scale };
        });
        const pixels = await page.locator(fixture.crop || '.crop').screenshot();
        const badge = await page.locator('.badge').count() ? await page.locator('.badge').evaluate(node => getComputedStyle(node).transform) : null;
        return { geometry, pixels, badge };
      };
      const source = await measure(fixture.html, fixture.css);
      const candidate = await measure(fixture.compiled, css);
      assert.deepEqual(candidate.geometry, source.geometry, `${fixture.name} image reference box at ${width}px`);
      assert.deepEqual(candidate.pixels, source.pixels, `${fixture.name} exact crop pixels at ${width}px`);
      assert.equal(candidate.badge, source.badge, 'mixed non-image class ownership preserved');
      await page.close();
    }
  }
  console.log('OK: image transform reference box and exact crop pixels (9 cases × 3 viewports)');
} finally {
  await browser.close();
}
