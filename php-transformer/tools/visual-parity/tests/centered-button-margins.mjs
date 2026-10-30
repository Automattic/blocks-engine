import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// A centered inline-block control with side margins keeps those margins
// inside its block-flow item: the item is as wide as in the source and the
// control sits at the same offset within it.
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(`${root}/tests/fixtures/centered-button-margins.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
// WordPress renders a flex layout's justifyContent as a justification class.
const blocks = result.serialized_blocks.replace(/<!-- wp:buttons (\{.*?\}) -->\s*<div class="wp-block-buttons/g, (match, attrs) => {
  const justify = JSON.parse(attrs).layout?.justifyContent;
  return justify ? match.replace('class="wp-block-buttons', `class="wp-block-buttons is-content-justification-${justify}`) : match;
});
const target = `<!doctype html><style>.wp-block-buttons{display:flex;flex-wrap:wrap}.wp-block-buttons.is-content-justification-center{justify-content:center}\n${css}</style>${blocks}`;
const browser = await chromium.launch({ headless: true });
try {
  const measurements = [];
  for (const html of [source, target]) {
    const page = await browser.newPage({ viewport: { width: 1024, height: 600 } });
    await page.setContent(html);
    measurements.push(await page.evaluate(() => [...document.querySelectorAll('li')].map(item => {
      const box = item.getBoundingClientRect();
      return [Math.round(box.width), Math.round(item.querySelector('button').getBoundingClientRect().x - box.x)];
    })));
    await page.close();
  }
  console.log(JSON.stringify({ source: measurements[0], converted: measurements[1] }));
  assert.deepEqual(measurements[1], measurements[0]);
} finally {
  await browser.close();
}
