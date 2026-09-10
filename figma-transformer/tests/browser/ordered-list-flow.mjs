import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const directory = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(directory, 'fixtures', 'ordered-list-flow.scenegraph.json');
const transformerPath = path.resolve(directory, '..', '..', 'figma-transformer.php');
const scenegraph = JSON.parse(await readFile(fixturePath, 'utf8'));
const php = `require ${JSON.stringify(transformerPath)}; $scenegraph = json_decode(file_get_contents($argv[1]), true); $result = blocks_engine_figma_transformer_transform_scenegraph($scenegraph, array('responsive_variants' => array(array('frame_id' => 'page:desktop', 'viewport_width' => 1440, 'primary' => true), array('frame_id' => 'page:mobile', 'viewport_width' => 390)))); $files = array(); foreach ($result['files'] as $file) { if (in_array($file['path'], array('index.html', 'style.css'), true)) { $files[$file['path']] = $file['content']; } } echo json_encode($files);`;
const files = JSON.parse(execFileSync('php', ['-r', php, fixturePath], { encoding: 'utf8' }));

assert.match(files['index.html'], /<ol\b/, 'the synthetic fixture emits an ordered list');
assert.doesNotMatch(files['style.css'], /counter-(?:reset|increment)|::before\{content:counter\(figma-list-item\)/, 'ordered lists use native markers instead of generated counters');
assert.match(files['style.css'], /@media \(max-width:390px\)[\s\S]*height:auto/, 'mobile output releases fixed semantic list-item heights');

const browser = await chromium.launch({ headless: true });
try {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.setContent(files['index.html'].replace('<link rel="stylesheet" href="style.css">', `<style>${files['style.css']}</style>`));
  const layout = await page.evaluate(() => {
    const list = document.querySelector('ol');
    const items = [...list.querySelectorAll(':scope > li')];
    const heading = document.createElement('h2');
    heading.textContent = 'Following section';
    list.after(heading);
    return {
      listStyle: getComputedStyle(list).listStyleType,
      generatedMarkers: items.map((item) => getComputedStyle(item, '::before').content),
      itemHeights: items.map((item) => item.getBoundingClientRect().height),
      listBottom: list.getBoundingClientRect().bottom,
      headingTop: heading.getBoundingClientRect().top,
    };
  });

  if (process.env.ORDERED_LIST_FLOW_SCREENSHOT) {
    await page.screenshot({ path: process.env.ORDERED_LIST_FLOW_SCREENSHOT, fullPage: true });
  }

  assert.equal(layout.listStyle, 'decimal');
  assert.deepEqual(layout.generatedMarkers, ['none', 'none', 'none']);
  assert.ok(layout.itemHeights.some((height) => height > 96), `wrapped list items grow beyond their desktop measured height: ${layout.itemHeights.join(', ')}`);
  assert.ok(layout.headingTop >= layout.listBottom, 'a following section heading remains after the expanded list flow');
} finally {
  await browser.close();
}

console.log('Ordered-list flow browser regression passed');
