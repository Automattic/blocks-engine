import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = fileURLToPath(new URL('../../..', import.meta.url));
const code = 'require $argv[1]."/vendor/autoload.php"; $out=[]; foreach(require $argv[1]."/tests/fixtures/conditional-inline-positioning.php" as $fixture){$r=(new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform("<style>".$fixture["css"]."</style>".$fixture["html"])->toArray();$fixture["result"]=$r;$out[]=$fixture;}echo json_encode($out);';
const fixtures = JSON.parse(execFileSync('php', ['-r', code, root], { encoding:'utf8' }));
const browser = await chromium.launch({headless:true});
try {
  const page = await browser.newPage();
  await page.route('https://example.test/**', route => route.fulfill({contentType:'text/html',body:'Map'}));
  for (const fixture of fixtures) {
    const css = fixture.result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content || '').join('\n');
    for (const width of [390,768,1440]) {
      await page.setViewportSize({width,height:900});
      const measure = async (html, styles) => {
        await page.setContent(`<!doctype html><style>body{margin:0}${styles}</style>${html}`);
        return page.locator('.paint').evaluate(node => {
          const box=node.getBoundingClientRect();
          return {width:box.width,height:box.height,x:box.x,y:box.y};
        });
      };
      const source = await measure(fixture.html,fixture.css);
      const candidate = await measure(fixture.result.serialized_blocks,css);
      if (fixture.active.includes(width)) {
        assert.deepEqual(candidate,source,`${fixture.name} preserves the positioned iframe surface at ${width}px`);
        assert.equal(candidate.height,180);
      } else {
        // No containing block is proven here: keep the existing conservative
        // flow fallback instead of carrying an unconditional absolute layer.
        assert.equal(await page.locator('.fill').evaluate(node => getComputedStyle(node).position),'static',`${fixture.name} has no unconditional absolute placement at ${width}px`);
      }
    }
    assert.equal(fixture.result.source_reports.wp_block_validity.status,'pass');
  }
  console.log(`OK: conditional inline positioning browser regression (${fixtures.length} fixtures x 3 viewports)`);
} finally {
  await browser.close();
}
