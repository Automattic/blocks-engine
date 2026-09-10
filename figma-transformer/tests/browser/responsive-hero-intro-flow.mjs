import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(directory, 'fixtures', 'responsive-hero-intro-flow.scenegraph.json');
const transformerPath = path.resolve(directory, '..', '..', 'figma-transformer.php');
const requireVisualParity = createRequire(path.resolve(directory, '..', '..', '..', 'php-transformer', 'tools', 'visual-parity', 'package.json'));
const { chromium } = requireVisualParity('playwright');
const scenegraph = JSON.parse(await readFile(fixturePath, 'utf8'));
const php = `require ${JSON.stringify(transformerPath)}; $scenegraph = json_decode(file_get_contents($argv[1]), true); $result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph, array('responsive_variants' => array(array('frame_id' => 'page:desktop', 'viewport_width' => 1440, 'primary' => true), array('frame_id' => 'page:mobile', 'viewport_width' => 390)))); $files = array(); foreach ($result['files'] as $file) { if (in_array($file['path'], array('index.html', 'style.css'), true)) { $files[$file['path']] = $file['content']; } } echo json_encode($files);`;
const files = JSON.parse(execFileSync('php', ['-r', php, fixturePath], { encoding: 'utf8' }));

assert.match(files['style.css'], /min-height:0;height:470\.75px/, 'a shorter variant height clears the primary flow reserve');

const browser = await chromium.launch({ headless: true });
try {
  for (const viewport of [
    { name: 'desktop', width: 1440, height: 900, gap: 96, heroHeight: 1050 },
    { name: 'mobile-375', width: 375, height: 844, gap: 64, heroHeight: 470.75 },
    { name: 'mobile-390', width: 390, height: 844, gap: 64, heroHeight: 470.75 },
    { name: 'mobile-430', width: 430, height: 844, gap: 64, heroHeight: 470.75 },
  ]) {
    const page = await browser.newPage({ viewport });
    await page.setContent(files['index.html'].replace('<link rel="stylesheet" href="style.css">', `<style>${files['style.css']}</style>`));
    const layout = await page.evaluate(() => {
      const hero = document.querySelector('[data-figma-node-id="hero:desktop"]');
      const intro = document.querySelector('[data-figma-node-id="intro:desktop"]');
      const footer = document.querySelector('[data-figma-node-id="closing:desktop"]');
      const box = (element) => element.getBoundingClientRect();
      const heroBox = box(hero);
      const introBox = box(intro);
      const footerBox = box(footer);
      return {
        hero: heroBox.toJSON(),
        intro: introBox.toJSON(),
        footer: footerBox.toJSON(),
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
        documentHeight: document.documentElement.scrollHeight,
      };
    });

    assert.equal(layout.hero.height, viewport.heroHeight, `${viewport.name} uses its source hero height`);
    assert.equal(layout.intro.top - layout.hero.bottom, viewport.gap, `${viewport.name} preserves authored hero-to-intro spacing`);
    assert.ok(layout.intro.top >= layout.hero.bottom, `${viewport.name} hero and intro do not overlap`);
    assert.ok(layout.footer.top >= layout.intro.bottom, `${viewport.name} footer remains after intro flow`);
    assert.ok(layout.documentHeight >= layout.footer.bottom, `${viewport.name} full page contains the footer`);
    assert.ok(layout.documentWidth <= layout.viewportWidth, `${viewport.name} has no horizontal overflow`);
    await page.close();
  }
} finally {
  await browser.close();
}

console.log('Responsive hero intro flow browser regression passed');
