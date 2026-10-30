import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// Inline-block items the source separated by whitespace keep that space
// between them: each item starts where it did in the source.
const root = new URL('../../..', import.meta.url).pathname;
const source = readFileSync(`${root}/tests/fixtures/inline-block-item-spacing.html`, 'utf8');
const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
const css = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content).join('\n');
const target = `<!doctype html><style>.wp-block-buttons{display:flex;flex-wrap:wrap}\n${css}</style>${result.serialized_blocks}`;
const browser = await chromium.launch({ headless: true });
try {
  const measurements = [];
  for (const html of [source, target]) {
    const page = await browser.newPage({ viewport: { width: 1024, height: 600 } });
    await page.setContent(html);
    measurements.push(await page.evaluate(() => [...document.querySelectorAll('button')].map(button => Math.round(button.getBoundingClientRect().x))));
    await page.close();
  }
  console.log(JSON.stringify({ source: measurements[0], converted: measurements[1] }));
  assert.deepEqual(measurements[1], measurements[0]);
} finally {
  await browser.close();
}
