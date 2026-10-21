import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const cwd = fileURLToPath(new URL('../../../', import.meta.url));
const source = '<div style="background:#f4eee9"><p style="background-color:#f4eee9;padding:0;margin:16px 0">Text</p><p style="background-color:#f4eee9;padding:0;margin:16px 0">More</p><p style="background-color:#f4eee9">Implicit zero</p></div>';
const markup = execFileSync('php', ['-r', `require 'vendor/autoload.php'; echo (new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform($argv[1], array())->toArray()['serialized_blocks'];`, source], { cwd, encoding: 'utf8' });
const browser = await chromium.launch();
try {
  const page = await browser.newPage();
  const padding = async (html, css = '') => {
    await page.setContent(`<style>${css}</style>${html}`);
    return page.locator('p').evaluateAll(elements => elements.map(element => {
      const style = getComputedStyle(element);
      return [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft];
    }));
  };
  const before = await padding(source);
  // Core's paragraph background rule must not change the source box.
  const after = await padding(markup, ':where(p.has-background){padding:1.25em 2.375em}');
  assert.deepEqual(after, before);
  assert.deepEqual(after, Array.from({ length: 3 }, () => ['0px', '0px', '0px', '0px']));
} finally {
  await browser.close();
}
console.log('Background paragraph rendered padding: passed');
