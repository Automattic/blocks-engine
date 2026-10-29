import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// A linked image whose link is the flex item keeps the link's box: the
// core/image figure between the bar and the link must not add a line box,
// and an inline image keeps the link's own line box (its strut).
const root = new URL('../../..', import.meta.url).pathname;
const browser = await chromium.launch({ headless: true });
try {
for (const fixture of ['linked-flex-item-image', 'linked-flex-item-inline-image']) {
const source = readFileSync(`${root}/tests/fixtures/${fixture}.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const core = '.wp-block-image img{max-width:100%;height:auto;box-sizing:border-box;vertical-align:bottom}.wp-block-image{margin:0}';
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const target = `<!doctype html><style>${core}\n${css}</style>${result.serialized_blocks}`;
  const measurements = [];
  for (const html of [source, target]) {
    const page = await browser.newPage({ viewport: { width: 1024, height: 600 } });
    await page.setContent(html);
    measurements.push(await page.evaluate(() => {
      const box = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return [Math.round(r.y), Math.round(r.height)]; };
      return { bar: box('.site-bar'), link: box('.site-brand'), image: box('.site-brand img') };
    }));
    await page.close();
  }
  console.log(JSON.stringify({ fixture, source: measurements[0], converted: measurements[1] }));
  assert.deepEqual(measurements[1], measurements[0], fixture);
}
} finally {
  await browser.close();
}
