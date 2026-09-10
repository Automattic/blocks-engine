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

assert.match(files['style.css'], /min-height:470\.75px/, 'a shorter variant height replaces the primary flow reserve');
assert.match(files['style.css'], /data-figma-responsive-variant="header"/, 'a structurally distinct compact header projects its variant children');

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
      const header = document.querySelector('[data-figma-node-id="hero:desktop:header"]');
      const intro = document.querySelector('[data-figma-node-id="intro:desktop"]');
      const introText = document.querySelector('[data-figma-node-id="intro:desktop:copy"]');
      const media = document.querySelector('[data-figma-node-id="hero:desktop:media"]');
      const footer = document.querySelector('[data-figma-node-id="closing:desktop"]');
      const desktopNavigation = document.querySelector('[data-figma-node-id="hero:desktop:header:navigation"]');
      const compactMenu = document.querySelector('[data-figma-node-id="hero:mobile:header:menu"]');
      const box = (element) => element.getBoundingClientRect();
      const heroBox = box(hero);
      const introBox = box(intro);
      const introTextBox = box(introText);
      const footerBox = box(footer);
      window.scrollTo(0, Math.max(0, introTextBox.top - 24));
      const mediaBox = box(media);
      const range = document.createRange();
      range.selectNodeContents(introText);
      const textRects = [...range.getClientRects()].map((rect) => rect.toJSON());
      const intersects = (first, second) => first.left < second.right && first.right > second.left && first.top < second.bottom && first.bottom > second.top;
      const firstTextRect = textRects[0];
      const hit = document.elementsFromPoint(firstTextRect.left + firstTextRect.width / 2, firstTextRect.top + firstTextRect.height / 2);
      const clipped = [...introText.parentElement.closest('.figma-root').querySelectorAll('*')]
        .filter((element) => element.contains(introText) && ['hidden', 'clip'].includes(getComputedStyle(element).overflow))
        .some((element) => textRects.some((rect) => {
          const ancestor = box(element);
          return rect.top < ancestor.top || rect.bottom > ancestor.bottom || rect.left < ancestor.left || rect.right > ancestor.right;
        }));
      return {
        hero: heroBox.toJSON(),
        header: box(header).toJSON(),
        intro: introBox.toJSON(),
        introText: introTextBox.toJSON(),
        introTextRects: textRects,
        media: mediaBox.toJSON(),
        introIntersectsMedia: textRects.some((rect) => intersects(rect, mediaBox)),
        introClipped: clipped,
        hitOwnsIntro: hit.some((element) => element === intro || intro.contains(element) || element.contains(intro)),
        desktopNavigationVisible: desktopNavigation && getComputedStyle(desktopNavigation).display !== 'none',
        compactMenuVisible: compactMenu && getComputedStyle(compactMenu).display !== 'none' && compactMenu.getBoundingClientRect().width > 0,
        compactMenuAccessible: compactMenu?.getAttribute('data-figma-node-name') === 'Open menu',
        footer: footerBox.toJSON(),
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: window.innerWidth,
        documentHeight: document.documentElement.scrollHeight,
      };
    });

    assert.equal(layout.hero.height, viewport.heroHeight, `${viewport.name} uses its source hero height`);
    if (viewport.width <= 430) assert.equal(layout.header.height, 127, `${viewport.name} uses the compact header height`);
    assert.equal(layout.intro.top - layout.hero.bottom, viewport.gap, `${viewport.name} preserves authored hero-to-intro spacing`);
    assert.ok(layout.intro.top >= layout.hero.bottom, `${viewport.name} hero and intro do not overlap`);
    assert.ok(layout.introTextRects.length > 0, `${viewport.name} intro text has rendered range rectangles`);
    assert.ok(layout.introTextRects.every((rect) => rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < layout.documentHeight), `${viewport.name} intro text ranges are visible in the document`);
    assert.equal(layout.introIntersectsMedia, false, `${viewport.name} intro text does not intersect painted hero media`);
    assert.equal(layout.introClipped, false, `${viewport.name} intro text is not clipped`);
    assert.equal(layout.hitOwnsIntro, true, `${viewport.name} hit testing resolves to intro content instead of hero media`);
    if (viewport.width <= 430) {
      assert.equal(layout.desktopNavigationVisible, false, `${viewport.name} does not expose the desktop navigation as clipped compact chrome`);
      assert.equal(layout.compactMenuVisible, true, `${viewport.name} exposes the compact variant navigation representation`);
      assert.equal(layout.compactMenuAccessible, true, `${viewport.name} preserves the compact navigation control semantics`);
    } else {
      assert.equal(layout.desktopNavigationVisible, true, `${viewport.name} retains desktop navigation`);
      assert.equal(layout.compactMenuVisible, false, `${viewport.name} hides compact variant navigation`);
    }
    assert.ok(layout.footer.top >= layout.intro.bottom, `${viewport.name} footer remains after intro flow`);
    assert.ok(layout.documentHeight >= layout.footer.bottom, `${viewport.name} full page contains the footer`);
    assert.ok(layout.documentWidth <= layout.viewportWidth, `${viewport.name} has no horizontal overflow`);
    await page.close();
  }
} finally {
  await browser.close();
}

console.log('Responsive hero intro flow browser regression passed');
