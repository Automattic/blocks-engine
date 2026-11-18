import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

// A wrapper of classed phrasing items (address, phone, email link) lowers to one
// paragraph per item. The email link keeps its own markup and class inside the
// paragraph, and the paragraph keeps the class too so the desktop sibling rule
// (`.site-phone+.site-email`) still finds it. Only the link may be the item's
// box: the mobile `display:block` and `::before{content:'Email'}` painted a
// second "Email" line on the paragraph, growing the footer by a line.
const root = new URL('../../..', import.meta.url).pathname;
const css = 'body{margin:0;font:14px/28.8px sans-serif}#siteInfo{text-align:center;letter-spacing:2px}'
  + '.site-phone,.site-email{white-space:nowrap}'
  + '.site-address+.site-phone,.site-address+.site-email,.site-phone+.site-email{margin-left:1em}'
  + '@media only screen and (max-width:640px){.site-phone,.site-email{display:block;margin-left:0 !important}.site-email>span{display:none}.site-email:before{content:\'Email\'}}';
const source = '<footer><div id="siteInfo"><span class="site-address">459 Broadway, New York</span><span class="site-phone">(212) 555-0123</span>'
  + '<a href="mailto:hello@example.com" class="site-email"><span>hello@example.com</span></a></div></footer>';

const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
const result = JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(`<style>${css}</style><main>${source}</main>`).toString('base64')], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }));
const generatedCss = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');

const browser = await chromium.launch({ headless: true });
try {
  for (const width of [390, 1440]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const measure = async (html, styles) => {
      await page.setContent(`<!doctype html><style>${styles}</style>${html}`);
      return page.evaluate(() => {
        const info = document.getElementById('siteInfo');
        const email = info.querySelector('a.site-email');
        const box = email.getBoundingClientRect();
        return {
          height: Math.round(info.getBoundingClientRect().height),
          emailLeft: Math.round(box.left),
          emailWidth: Math.round(box.width),
          labels: [...info.querySelectorAll('*')].filter(node => getComputedStyle(node, '::before').content.includes('Email')).length,
        };
      });
    };
    const expected = await measure(`<main>${source}</main>`, css);
    const actual = await measure(result.serialized_blocks, `body{margin:0}${generatedCss}`);
    assert.deepEqual(actual, expected, `footer site info keeps the source geometry at ${width}px`);
    await page.close();
  }
  console.log('OK: retained inline item paints one box (2 viewports)');
} finally {
  await browser.close();
}
