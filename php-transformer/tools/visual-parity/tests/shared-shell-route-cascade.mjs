import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = process.env.BLOCKS_ENGINE_ROOT || path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const generated = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler;
use Automattic\\BlocksEngine\\PhpTransformer\\WordPressSitePlan\\WordPressSitePlan;
$document = static function (string $columns, string $title): string {
    return '<!doctype html><html><head><style>[data-chrome=grid]{display:flex;align-items:center}.route-grid{display:grid;grid-template-columns:repeat(1,minmax(0,1fr))}@media (min-width:1024px){.route-grid{grid-template-columns:repeat(' . $columns . ',minmax(0,1fr))}}.footer-mark{display:flex;align-items:center}</style></head><body><header data-chrome="grid">Brand<nav><a href="/">Home</a><a href="/about">About</a></nav></header><div role="contentinfo"><p class="footer-mark">Footer</p></div><main><div class="route-grid"><div>One</div><div>Two</div><div>Three</div></div><h1>' . $title . '</h1></main></body></html>';
};
$artifact = array('entrypoint' => 'index.html', 'files' => array(
    array('path' => 'index.html', 'kind' => 'html', 'content' => $document('2', 'Home')),
    array('path' => 'about.html', 'kind' => 'html', 'content' => $document('3', 'About')),
));
$compiler = new ArtifactCompiler();
$shared = $compiler->prepareShared($artifact);
$pages = $compiler->preparePages($artifact, $shared);
$receipts = $compiler->compilePreparedPages($shared, $pages);
$result = $compiler->compose($shared, $receipts)->toArray();
$plan = (new WordPressSitePlan())->fromResult($result);
$parts = array_column($plan['template_parts'] ?? array(), null, 'slug');
echo json_encode(array(
    'pages' => array_column($plan['pages'] ?? array(), 'canonical_block_markup', 'source_path'),
    'parts' => array_column($plan['template_parts'] ?? array(), 'canonical_block_markup', 'slug'),
    'assets' => $plan['assets'] ?? array(),
), JSON_THROW_ON_ERROR);
`, transformerRoot], { encoding: 'utf8' }));

assert.deepEqual(Object.keys(generated.pages).sort(), ['about.html', 'index.html']);
assert.ok(Object.keys(generated.parts).some((slug) => slug.startsWith('footer')), 'final plan contains a shared footer part');

const applicableCss = (route) => generated.assets
  .filter((asset) => asset.kind === 'css' && (asset.scopes || []).some((scope) => scope.kind === 'global' || scope.source_path === route))
  .map((asset) => asset.content || '')
  .join('\n');
const pageMarkup = (route) => generated.pages[route] + Object.entries(generated.parts)
  .filter(([slug]) => slug.startsWith('footer'))
  .map(([, markup]) => markup)
  .join('');

const browser = await chromium.launch({ headless: true });
try {
  for (const viewport of [{ name: 'desktop', width: 1440, height: 800 }, { name: 'mobile', width: 390, height: 800 }]) {
    for (const route of ['index.html', 'about.html']) {
      const page = await browser.newPage({ viewport });
      const css = applicableCss(route);
      await page.setContent(`<style>body{margin:0}${css}</style>${pageMarkup(route)}`);
      const grid = page.locator('[class*="route-grid"]').first();
      const style = await grid.evaluate((element) => getComputedStyle(element));
      const expectedTracks = viewport.name === 'desktop' ? (route === 'index.html' ? 2 : 3) : 1;
      assert.equal(style.display, 'grid', `${route} final frontend keeps grid display`);
      assert.equal(style.gridTemplateColumns.trim().split(/\s+/).length, expectedTracks, `${route} final frontend keeps route cascade`);
      assert.equal(await page.locator('.footer-mark').first().evaluate((element) => getComputedStyle(element).display), 'flex', `${route} final shared footer keeps projected styling`);
      await page.close();
    }
  }
} finally {
  await browser.close();
}

console.log('Shared-shell route cascade browser regression passed');
