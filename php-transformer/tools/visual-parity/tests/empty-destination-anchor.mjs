import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const image = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
const css = '.media{width:80%;}.media a{display:block;width:100%;line-height:0}.media img{width:80px;height:60px}.notice{position:relative;width:300px;height:40px}.overlay{position:absolute;inset:0}.hitbox{display:block;width:120px;height:48px}.ratio{display:block;width:120px;aspect-ratio:1.5;line-height:0}.unique{display:block;line-height:0}';
const fixtures = [
  ['redundant whitespace sibling', `<div class="media"><a href="https://example.com/plant"><img src="${image}" alt="Pencil Plant"></a><a class="blank" href="https://example.com/plant">\n \n</a></div>`, '.blank', 0],
  ['accessible overlay', '<div class="notice"><p id="notice-text">Tickets available</p><a class="overlay" aria-labelledby="notice-text" href="https://example.com/tickets"></a></div>', '.overlay', 40],
  ['positive geometry', '<a class="hitbox" href="https://example.com/plant"></a>', '.hitbox', 48],
  ['aspect ratio geometry', '<a class="ratio" href="https://example.com/plant">\n </a>', '.ratio', 80],
  ['unique destination', '<a class="unique" href="https://example.com/unique">\n </a>', '.unique', 0],
];
function transform(source) {
  const code = 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());';
  return JSON.parse(execFileSync('php', ['-r', code, root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
}
const browser = await chromium.launch({ headless: true });
try {
  for (const [name, source, selector, height] of fixtures) {
    const result = transform(`<style>${css}</style><main>${source}</main>`);
    assert.deepEqual(result.fallbacks, [], `${name}: no unsupported finding`);
    const generatedCss = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
    for (const width of [390, 768, 1440]) {
      const page = await browser.newPage({ viewport: { width, height: 900 } });
      const probe = async (html, styles) => {
        await page.setContent(`<!doctype html><style>body{margin:0}${styles}</style>${html}`);
        return page.locator(selector).evaluate(link => {
          const box = link.getBoundingClientRect();
          const labelledby = link.getAttribute('aria-labelledby');
          return { width: box.width, height: box.height, href: link.getAttribute('href'), name: labelledby ? document.getElementById(labelledby)?.textContent : link.getAttribute('aria-label'), hit: box.height > 0 ? document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)?.closest('a')?.getAttribute('href') : null };
        });
      };
      const before = await probe(`<main>${source}</main>`, css);
      assert.equal(before.height, height, `${name}: source hitbox at ${width}px`);
      const after = await probe(result.serialized_blocks, generatedCss);
      assert.deepEqual(after, before, `${name}: destination, name and hitbox conserved at ${width}px`);
      if (name === 'redundant whitespace sibling') {
        assert.equal(await page.locator('a:has(img)').getAttribute('href'), 'https://example.com/plant');
        assert.equal(await page.locator('img').getAttribute('alt'), 'Pencil Plant');
      }
      await page.close();
    }
  }
  console.log('OK: empty destination anchor browser regression (5 cases × 3 viewports)');
} finally {
  await browser.close();
}
