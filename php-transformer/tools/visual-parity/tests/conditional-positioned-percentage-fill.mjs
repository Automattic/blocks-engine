import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = new URL('../../..', import.meta.url).pathname;
const code = 'require $argv[1] . "/vendor/autoload.php"; $rows = array(); foreach (require $argv[1] . "/tests/fixtures/conditional-positioned-percentage-fill.php" as $fixture) { $result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform("<style>" . $fixture["css"] . "</style>" . $fixture["html"])->toArray(); $fixture["result"] = array("serialized_blocks" => $result["serialized_blocks"], "assets" => $result["assets"]); $rows[] = $fixture; } echo json_encode($rows);';
const fixtures = JSON.parse(execFileSync('php', ['-r', code, root], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
  for (const fixture of fixtures) {
    const css = fixture.result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const measure = async (html, styles) => {
        await page.setContent(`<!doctype html><style>body{margin:0}${styles}</style>${html}`);
        return page.locator('.paint').evaluate(node => {
          const box = node.getBoundingClientRect();
          const style = getComputedStyle(node);
          return { x: box.x, y: box.y, width: box.width, height: box.height, position: style.position, backgroundImage: style.backgroundImage, backgroundSize: style.backgroundSize, backgroundPosition: style.backgroundPosition };
        });
      };
      const source = await measure(fixture.html, fixture.css);
      const candidate = await measure(fixture.result.serialized_blocks, css);
      assert.deepEqual(candidate, source, `${fixture.name}: source paint and geometry at ${width}px`);
      if (fixture.preserve && ((fixture.name === 'desktop absolute fill' && width > 800) || (fixture.name !== 'desktop absolute fill' && width <= 800))) {
        assert.equal(candidate.height, 180, `${fixture.name}: positive containing-block fill`);
      }
      await page.close();
    }
  }
  console.log('OK: conditional positioned percentage fill browser regression (12 cases × 3 viewports)');
} finally {
  await browser.close();
}
