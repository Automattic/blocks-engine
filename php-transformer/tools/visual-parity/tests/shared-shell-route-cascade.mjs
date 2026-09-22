import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const generated = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$style = static function (string $columns): string {
    return '.grid{display:grid}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}@media(min-width:1024px){.lg\\:grid-cols-2{grid-template-columns:repeat(' . $columns . ',minmax(0,1fr))}}[data-footer=shared]{display:flex;align-items:center}';
};
$document = static function (string $style, string $items): string {
    return '<!doctype html><html><head><style>' . $style . '</style></head><body><footer data-footer="shared">Brand</footer><main><div class="grid grid-cols-1 lg:grid-cols-2" data-grid>' . $items . '</div></main></body></html>';
};
$result = (new \\Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'kind' => 'html', 'content' => $document($style('2'), '<div>One</div><div>Two</div>')),
        array('path' => 'about.html', 'kind' => 'html', 'content' => $document($style('3'), '<div>One</div><div>Two</div><div>Three</div>')),
    ),
))->toArray();
$assets = $result['source_reports']['wordpress_site_plan']['assets'] ?? array();
$routes = array();
$pages = array();
$seenAuthor = false;
$afterAuthorOrder = true;
foreach ($assets as $asset) {
    if ('author' === ($asset['stylesheet_placement'] ?? '')) $seenAuthor = true;
    if ('after-author' === ($asset['stylesheet_placement'] ?? '') && !$seenAuthor) $afterAuthorOrder = false;
    if ('css' !== ($asset['kind'] ?? null)) continue;
    foreach ($asset['scopes'] ?? array() as $scope) {
        $source = $scope['source_path'] ?? null;
        if (is_string($source) && in_array($source, array('index.html', 'about.html'), true)) $routes[$source][] = (string) ($asset['content'] ?? '');
    }
}
foreach ($result['source_reports']['compiled_site']['pages'] ?? array() as $page) $pages[$page['source_path']] = $page['block_markup'] ?? '';
echo json_encode(array('routes' => $routes, 'pages' => $pages, 'serialized_blocks' => $result['serialized_blocks'] ?? '', 'after_author_order' => $afterAuthorOrder));
`, transformerRoot], { encoding: 'utf8' }));

assert.deepEqual(Object.keys(generated.routes).sort(), ['about.html', 'index.html'], 'both route stylesheet scopes are emitted');
assert.match(generated.routes['index.html'].join(''), /grid-cols-1/);
assert.match(generated.routes['about.html'].join(''), /lg\\:grid-cols-2/);
assert.match(generated.serialized_blocks, /grid-cols-1/);
assert.match(generated.pages['index.html'], /blocks-engine-[a-z-]+-[0-9a-f]{12}-\d+/);
assert.equal(generated.after_author_order, true, 'staged asset order keeps after-author support after authored CSS');

const routeCss = (route) => generated.routes[route].join('\n');
const markup = (route) => generated.pages[route];
const editorMarkup = (route) => `<div class="editor-styles-wrapper"><div class="block-editor-block-list__layout">${markup(route)}</div></div>`;

const browser = await chromium.launch({ headless: true });
try {
  for (const viewport of [{ name: 'desktop', width: 1440, height: 800 }, { name: 'mobile', width: 390, height: 800 }]) {
    for (const route of ['index.html', 'about.html']) {
      const items = 'index.html' === route ? '<div>One</div><div>Two</div>' : '<div>One</div><div>Two</div><div>Three</div>';
      const candidate = await browser.newPage({ viewport });
      const editor = await browser.newPage({ viewport });
      const css = routeCss(route);
      await candidate.setContent(`<style>body{margin:0}${css}</style>${markup(route)}`);
      await editor.setContent(`<style>body{margin:0}.block-editor-block-list__layout{display:contents}${css}</style>${editorMarkup(route)}`);

      const columns = async (page) => page.locator('[class*="grid"]').evaluateAll((elements) => {
        const element = elements.find((candidate) => 'grid' === getComputedStyle(candidate).display) || elements[0];
        const style = getComputedStyle(element);
        return { tracks: style.gridTemplateColumns.trim().split(/\s+/).length, display: style.display };
      });
      const candidateColumns = await columns(candidate);
      const editorColumns = await columns(editor);
      const expected = 'desktop' === viewport.name ? ('index.html' === route ? 2 : 3) : 1;
      assert.deepEqual(candidateColumns, { tracks: expected, display: 'grid' }, `${route} staged frontend keeps route responsive columns`);
      assert.deepEqual(editorColumns, { tracks: expected, display: 'grid' }, `${route} staged editor keeps route responsive columns`);
      assert.equal(await candidate.locator('footer, [class*="footer"]').first().evaluate((element) => getComputedStyle(element).display), 'flex', `${route} staged frontend retains shared generated footer styling`);

      await candidate.close();
      await editor.close();
    }
  }
} finally {
  await browser.close();
}

console.log('Shared-shell route cascade browser regression passed');
