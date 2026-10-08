import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const transformerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
use Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler;

$logos = '';
for ($i = 1; $i <= 7; ++$i) {
    $svg = rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="60"><rect width="100" height="60" fill="#246"/><text x="8" y="36" fill="white">Logo ' . $i . '</text></svg>');
    $logos .= '<li class="carousel-slide"><div class="logo-shell"><img class="visible-logo" width="100" height="60" src="data:image/svg+xml,' . $svg . '" alt="Logo ' . $i . '"></div></li>';
}
$preload = '';
for ($i = 1; $i <= 16; ++$i) {
    $preload .= '<div class="preload-logo">Preloaded ' . $i . '</div>';
}
$html = '<!doctype html><html><head><style>.carousel-slide{display:inline-block;position:relative;width:100px;height:60px}.carousel-track{list-style:none;padding:0;margin:0;white-space:nowrap}</style><link rel="stylesheet" href="desktop.css" media="(min-width: 1280px)"></head><body>'
    . '<article><h1>Article heading</h1><p class="article-copy">Article body remains in normal flow.</p>'
    . '<section id="bs-4"><span><div class="gallery-stage" style="position:relative;height:85px"><div class="preload-track" style="position:absolute;visibility:hidden;display:flex;height:0">' . $preload . '</div>'
    . '<div class="carousel-viewport"><ul class="carousel-track">' . $logos . '</ul></div></div></span></section>'
    . '<p id="after-carousel">Following article copy stays after the carousel.</p></article></body></html>';
$result = (new ArtifactCompiler())->compile(array(
    'schema' => ArtifactCompiler::INPUT_SCHEMA,
    'entrypoint' => 'index.html',
    'files' => array(
        array('path' => 'index.html', 'content' => $html),
        array('path' => 'desktop.css', 'content' => '.logo-shell{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%)}'),
    ),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'] ?? array();
$page = $plan['pages'][0] ?? array();
echo json_encode(array(
    'markup' => (string) ($page['canonical_block_markup'] ?? ''),
    'css' => implode("\\n", array_map(static fn(array $asset): string => (string) ($asset['content'] ?? ''), $plan['assets'] ?? array())),
));
`, transformerRoot], { encoding: 'utf8' }));

assert.match(compiled.markup, /preload-track/);
assert.match(compiled.markup, /visible-logo/);
assert.match(compiled.css, /@media\s*\(min-width:\s*1280px\)/);

const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 768, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.setContent(`<!doctype html><style>body{margin:0}${compiled.css}</style>${compiled.markup}`);
    const geometry = await page.evaluate(() => {
      const section = document.querySelector('#bs-4');
      const logos = [...section.querySelectorAll('.visible-logo')];
      const visible = logos.filter((image) => {
        const style = getComputedStyle(image);
        const rect = image.getBoundingClientRect();
        return style.visibility === 'visible' && style.display !== 'none' && rect.width > 1 && rect.height > 1;
      });
      const preload = section.querySelector('.preload-track');
      const visibleLogoShell = section.querySelector('.logo-shell');
      const after = document.querySelector('#after-carousel');
      return {
        documentHeight: document.documentElement.scrollHeight,
        sectionHeight: section.getBoundingClientRect().height,
        visibleLogos: visible.length,
        preloadPosition: getComputedStyle(preload).position,
        preloadVisibility: getComputedStyle(preload).visibility,
        visibleLogoPosition: getComputedStyle(visibleLogoShell).position,
        headingY: document.querySelector('h1').getBoundingClientRect().y,
        afterY: after.getBoundingClientRect().y,
      };
    });
    assert.equal(geometry.visibleLogos, 7, `${width}px keeps the seven visible carousel logos: ${JSON.stringify(geometry)}`);
    assert.equal(geometry.preloadPosition, 'absolute', `${width}px keeps preload out of flow`);
    assert.equal(geometry.preloadVisibility, 'hidden', `${width}px keeps the preload track hidden`);
    if (width < 1280) assert.equal(geometry.visibleLogoPosition, 'static', `${width}px keeps the visible sibling logos in flow`);
    assert.ok(geometry.sectionHeight >= 60 && geometry.sectionHeight <= 160, `${width}px has a bounded logo section: ${JSON.stringify(geometry)}`);
    assert.ok(geometry.headingY < geometry.afterY, `${width}px keeps article text in normal order: ${JSON.stringify(geometry)}`);
    assert.ok(geometry.documentHeight < 1400, `${width}px does not create vertical slide stacking: ${JSON.stringify(geometry)}`);
    await page.close();
  }

  const desktop = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await desktop.setContent(`<!doctype html><style>body{margin:0}${compiled.css}</style>${compiled.markup}`);
  const desktopPosition = await desktop.locator('.logo-shell').first().evaluate((element) => getComputedStyle(element).position);
  assert.equal(desktopPosition, 'absolute', 'the desktop media condition still activates its positioned logo styling');
  await desktop.close();
} finally {
  await browser.close();
}

console.log('Positioned carousel visibility/browser regression passed');
