import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const repository = path.resolve(directory, '..', '..', '..');
const fixturePath = path.join(directory, 'fixtures', 'footer-newsletter-flow.scenegraph.json');
const transformerPath = path.join(repository, 'figma-transformer', 'figma-transformer.php');
const requireFromVisualParity = createRequire(path.join(repository, 'php-transformer', 'tools', 'visual-parity', 'package.json'));
const { chromium } = requireFromVisualParity('playwright');
const scenegraph = JSON.parse(await readFile(fixturePath, 'utf8'));
const php = `require ${JSON.stringify(transformerPath)}; $scenegraph = json_decode(file_get_contents($argv[1]), true); $result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph, array('frame_id' => 'page:desktop', 'responsive_variants' => array(array('frame_id' => 'page:desktop', 'viewport_width' => 1440, 'primary' => true), array('frame_id' => 'page:mobile', 'viewport_width' => 390)))); $files = array(); foreach ($result['files'] as $file) { if (in_array($file['path'], array('index.html', 'style.css'), true)) { $files[$file['path']] = $file['content']; } } echo json_encode($files);`;
const files = JSON.parse(execFileSync('php', ['-r', php, fixturePath], { encoding: 'utf8' }));

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.setContent(files['index.html'].replace('<link rel="stylesheet" href="style.css">', `<style>${files['style.css']}</style>`));
  const layout = await page.evaluate(() => {
    const footer = document.querySelector('footer');
    const [newsletter, band] = footer.children;
    const pageRoot = document.querySelector('[data-figma-root]');
    const footerBox = footer.getBoundingClientRect();
    const newsletterBox = newsletter.getBoundingClientRect();
    const bandBox = band.getBoundingClientRect();
    return {
      documentWidth: document.documentElement.scrollWidth,
      viewportWidth: window.innerWidth,
      footerHeight: footerBox.height,
      newsletter: { top: newsletterBox.top, bottom: newsletterBox.bottom, width: newsletterBox.width, position: getComputedStyle(newsletter).position },
      band: { left: bandBox.left, top: bandBox.top, bottom: bandBox.bottom, width: bandBox.width, position: getComputedStyle(band).position },
      pageBottom: pageRoot.getBoundingClientRect().bottom,
      footerBottom: footerBox.bottom,
    };
  });

  assert.ok(layout.documentWidth <= layout.viewportWidth, `document width ${layout.documentWidth} must fit viewport ${layout.viewportWidth}`);
  assert.equal(layout.newsletter.position, 'relative', 'the inset panel enters footer flow at the narrow breakpoint');
  assert.equal(layout.band.position, 'relative', 'the footer band enters footer flow at the narrow breakpoint');
  assert.ok(layout.newsletter.width <= layout.viewportWidth, 'the newsletter panel fits the mobile viewport');
  assert.ok(layout.band.width <= layout.viewportWidth, 'the footer band fits the mobile viewport');
  assert.ok(layout.band.left >= 0, 'the footer band does not retain an off-canvas desktop breakout offset');
  assert.ok(layout.band.top >= layout.newsletter.bottom, 'the authored non-overlapping newsletter and footer band remain sequential');
  assert.ok(layout.footerBottom - layout.band.bottom <= 1, 'the footer does not retain unused space after its final semantic region');
} finally {
  await browser.close();
}

console.log('Responsive footer newsletter flow browser regression passed');
