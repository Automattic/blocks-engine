import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const styles = [
  'color:red!important;color:blue',
  'color:red;color:blue!important',
  'color:red!important;color:blue!important',
  'color:red;color:blue',
  'font-size:32px!important;font-size:12px',
  'font-size:32px ! IMPORTANT;font-size:12px',
];
const browser = await chromium.launch({ headless: true });
try {
  for (const style of styles) {
    const source = `<p style="${style}">Authored text</p>`;
    const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
    const result = JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
    const output = `<style>${result.assets.map(asset => asset.content ?? '').join('\n')}</style>${result.serialized_blocks}`;
    for (const width of [390, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 400 } });
      const read = async markup => {
        await page.setContent(`<!doctype html><html><body>${markup}</body></html>`);
        return page.locator('p').evaluate(element => ({ color: getComputedStyle(element).color, fontSize: getComputedStyle(element).fontSize, width: element.getBoundingClientRect().width, height: element.getBoundingClientRect().height }));
      };
      const before = await read(source);
      const after = await read(output);
      assert.deepEqual(after, before, `${style} at ${width}px`);
      console.log(JSON.stringify({ style, viewport: width, source: before, converted: after }));
      await page.close();
    }
  }
} finally {
  await browser.close();
}
