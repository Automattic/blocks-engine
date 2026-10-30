import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$source = require $argv[1] . '/tests/fixtures/gallery-stage-controls.php';
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\ArtifactCompiler\\ArtifactCompiler())->compile(array(
    'entrypoint' => 'index.html',
    'files' => array('index.html' => '<link rel="stylesheet" href="gallery.css">' . $source['html'], 'gallery.css' => $source['css']),
))->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$definition = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\Generators\\AuthoredCarouselBlockGenerator())->definition('custom');
echo json_encode(array('source' => '<style>' . $source['css'] . '</style>' . $source['html'], 'markup' => $plan['pages'][0]['canonical_block_markup'], 'css' => $definition['assets']['style.css'] . implode("\\n", array_column($plan['assets'], 'content'))));
`, root], { encoding: 'utf8' }));
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [1440, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    await page.setContent(`<style>body{margin:0}${compiled.css}</style><div id="source">${compiled.source}</div><div id="generated">${compiled.markup}</div>`);
    const geometry = await page.evaluate(() => {
      const source = document.querySelector('#source .gallery-slides').getBoundingClientRect();
      const track = document.querySelector('#generated .blocks-engine-authored-carousel__track');
      const box = track.getBoundingClientRect();
      const image = track.querySelector('img');
      const imageBox = image.getBoundingClientRect();
      return { source: { width: source.width, height: source.height }, stage: { width: box.width, height: box.height }, image: { width: imageBox.width, height: imageBox.height, visibility: getComputedStyle(image).visibility }, slides: track.children.length, thumbnails: document.querySelectorAll('#generated .blocks-engine-authored-carousel__thumbnail').length };
    });
    assert.equal(geometry.slides, 3);
    assert.equal(geometry.thumbnails, 3);
    assert.equal(geometry.image.visibility, 'visible');
    for (const axis of ['width', 'height']) {
      assert.ok(Math.abs(geometry.stage[axis] - geometry.source[axis]) < 1, `${width}: stage ${axis}: ${JSON.stringify(geometry)}`);
      assert.ok(Math.abs(geometry.image[axis] - geometry.source[axis]) < 1, `${width}: selected image ${axis}: ${JSON.stringify(geometry)}`);
    }
    await page.close();
  }
} finally { await browser.close(); }
console.log('Gallery source/generated sizing and selected image visibility passed');
