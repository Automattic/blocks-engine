import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');
const source = '<p><span class="toggle-icon" style="display:block;width:32px"><svg viewBox="0 0 448 512"><path d="M0 0h448v512H0z"/></svg></span></p><p><span class="explicit-icon" style="display:block;width:32px"><svg width="24" height="24" viewBox="0 0 512 512"><path d="M0 0h512v512H0z"/></svg></span></p><p><span class="css-icon" style="display:block;width:32px"><svg style="width:20px;height:22px" viewBox="0 0 448 512"><path d="M0 0h448v512H0z"/></svg></span></p>';
const compiled = JSON.parse(execFileSync('php', ['-r', `
require $argv[1] . '/vendor/autoload.php';
$result = (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray();
echo json_encode($result);
`, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
let markup = compiled.serialized_blocks;
let css = '';
for (const asset of compiled.assets) {
  if (asset.kind === 'svg') {
    markup = markup.split(asset.source_url).join(`data:image/svg+xml;base64,${Buffer.from(asset.content).toString('base64')}`);
  } else if (asset.content) {
    css += asset.content;
  }
}
// Optionally exercise real WordPress do_blocks() rather than the serialized
// save markup. WP_CLI points to a wp-cli.phar on disposable-site environments.
if (process.env.WORDPRESS_PATH && process.env.WP_CLI) {
  markup = execFileSync('php', [process.env.WP_CLI, `--path=${process.env.WORDPRESS_PATH}`, '--allow-root', 'eval', `echo do_blocks(base64_decode('${Buffer.from(markup).toString('base64')}'));`], { encoding: 'utf8' });
}
assert.doesNotMatch(markup, /width:448px|height:512px/);
const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 844 } });
    const measure = () => page.locator('svg,img').evaluateAll((nodes) => nodes.map((node) => {
      const { width, height } = node.getBoundingClientRect();
      return { width, height };
    }));
    await page.setContent(source);
    const expected = await measure();
    await page.setContent(`<style>${css}</style>${markup}`);
    await page.locator('img').evaluateAll((nodes) => Promise.all(nodes.map((node) => node.decode())));
    const actual = await measure();
    for (const [index, box] of actual.entries()) {
      for (const axis of ['width', 'height']) {
        assert.ok(Math.abs(box[axis] - expected[index][axis]) < 0.02, `${width}px icon ${index} ${axis} matches source within subpixel rounding`);
      }
    }
    assert.equal(actual[0].width, 32);
    assert.ok(Math.abs(actual[0].height - 32 * 512 / 448) < 0.02);
    assert.deepEqual(actual[1], { width: 24, height: 24 });
    assert.deepEqual(actual[2], { width: 20, height: 22 });
    console.log(JSON.stringify({ viewport: width, expected, actual }));
    await page.close();
  }
} finally {
  await browser.close();
}
