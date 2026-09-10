import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(directory, 'fixtures', 'ordered-list-flow.scenegraph.json');
const transformerPath = path.resolve(directory, '..', '..', 'figma-transformer.php');
const requireVisualParity = createRequire(path.resolve(directory, '..', '..', '..', 'php-transformer', 'tools', 'visual-parity', 'package.json'));
const { chromium } = requireVisualParity('playwright');
const scenegraph = JSON.parse(await readFile(fixturePath, 'utf8'));
const php = `require ${JSON.stringify(transformerPath)}; $scenegraph = json_decode(file_get_contents($argv[1]), true); $result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph, array('responsive_variants' => array(array('frame_id' => 'page:desktop', 'viewport_width' => 1440, 'primary' => true), array('frame_id' => 'page:mobile', 'viewport_width' => 390)))); $files = array(); foreach ($result['files'] as $file) { if (in_array($file['path'], array('index.html', 'style.css'), true)) { $files[$file['path']] = $file['content']; } } echo json_encode($files);`;
const files = JSON.parse(execFileSync('php', ['-r', php, fixturePath], { encoding: 'utf8' }));

assert.match(files['index.html'], /<ol\b/, 'the synthetic fixture emits an ordered list');
assert.doesNotMatch(files['style.css'], /counter-(?:reset|increment)|::before\{content:counter\(figma-list-item\)/, 'ordered lists do not use CSS counters');
assert.match(files['index.html'], /figma-list-marker/, 'ordered items emit an explicit painted marker');
assert.match(files['index.html'], /figma-list-marker" aria-hidden="true" style="flex:0 0 48px"/, 'markers reserve their decoded source inline slot inside the flex list item');

const browser = await chromium.launch({ headless: true });
try {
  for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
    const page = await browser.newPage({ viewport });
    await page.setContent(files['index.html'].replace('<link rel="stylesheet" href="style.css">', `<style>${files['style.css']}</style>`));
    const layout = await page.evaluate(() => {
      const list = document.querySelector('ol');
      const items = [...list.querySelectorAll(':scope > li')];
      const markers = [...list.querySelectorAll(':scope > li > .figma-list-marker')];
      const heading = document.querySelector('[data-figma-node-id="heading:following"]');
      const visibleBottom = (element) => {
        const style = getComputedStyle(element);
        return style.display === 'none' || style.visibility === 'hidden' ? null : element.getBoundingClientRect().bottom;
      };
      const contentBottoms = items.flatMap((item) => [item, ...item.querySelectorAll('*')])
        .map(visibleBottom)
        .filter((bottom) => bottom !== null);
      return {
        markers: markers.map((marker) => ({ text: marker.textContent, box: marker.getBoundingClientRect().toJSON(), display: getComputedStyle(marker).display })),
        itemOrdinalText: items.map((item) => [...item.querySelectorAll('.figma-list-marker')].map((marker) => marker.textContent)),
        itemHeights: items.map((item) => item.getBoundingClientRect().height),
        listBottom: list.getBoundingClientRect().bottom,
        headingTop: heading.getBoundingClientRect().top,
        contentBottom: Math.max(...contentBottoms),
        horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth,
      };
    });

    if (process.env.ORDERED_LIST_FLOW_SCREENSHOT_DIR) {
      await page.screenshot({ path: path.join(process.env.ORDERED_LIST_FLOW_SCREENSHOT_DIR, `ordered-list-flow-${viewport.name}.png`), fullPage: true });
    }

    assert.deepEqual(layout.markers.map(({ text }) => text), ['1.', '2.', '3.', '4.'], `${viewport.name} paints each ordinal once and in order`);
    assert.ok(layout.markers.every(({ box, display }) => display !== 'none' && box.width > 0 && box.height > 0), `${viewport.name} marker boxes are visible`);
    assert.deepEqual(layout.itemOrdinalText, [['1.'], ['2.'], ['3.'], ['4.']], `${viewport.name} has no duplicate ordinal text`);
    if (viewport.name === 'mobile') {
      assert.ok(layout.itemHeights.some((height) => height > 96), `mobile hug-sized list items grow with wrapped content: ${layout.itemHeights.join(', ')}`);
    }
    assert.ok(layout.headingTop >= layout.contentBottom, `${viewport.name} emitted following heading remains below every visible list-item descendant (${layout.headingTop} >= ${layout.contentBottom})`);
    assert.ok(layout.listBottom >= layout.contentBottom, `${viewport.name} list container contains every visible list-item descendant (${layout.listBottom} >= ${layout.contentBottom})`);
    if (viewport.name === 'mobile') {
      assert.equal(layout.horizontalOverflow, false, 'mobile semantic list does not introduce horizontal overflow');
    }
    await page.close();
  }
} finally {
  await browser.close();
}

console.log('Ordered-list flow browser regression passed');
