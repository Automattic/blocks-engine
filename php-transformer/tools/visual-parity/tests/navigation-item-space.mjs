import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const root = new URL('../../..', import.meta.url).pathname;
const core = readFileSync(root + '/tests/fixtures/core-navigation-style.css', 'utf8');
const browser = await chromium.launch({ headless: true });
try {
  for (const display of ['block', 'inline']) {
    const base = 'body{margin:0;font:17px/29px serif}';
    const css = `${base}.menu ul{padding:0;margin:0}.menu ul li{display:inline-block;list-style:none}.menu ul li a{display:${display};padding:${display === 'block' ? '38px 10px' : '0'}}`;
    const source = `<style>${css}</style><header><nav class="menu"><ul>
  <li><a href="/">Home</a></li>
  <li><a href="/covers">Covers</a></li>
  <li><a href="/contact">Contact</a></li>
</ul></nav></header>`;
    const result = JSON.parse(execFileSync('php', ['-r', 'require $argv[1] . "/vendor/autoload.php"; echo json_encode((new Automattic\\BlocksEngine\\PhpTransformer\\HtmlToBlocks\\HtmlTransformer())->transform(base64_decode($argv[2]))->toArray());', root, Buffer.from(source).toString('base64')], { encoding: 'utf8' }));
    assert.deepEqual(result.fallbacks, []);
    const navigation = result.blocks.flatMap(block => [block, ...(block.innerBlocks ?? [])]).find(block => block.blockName === 'core/navigation');
    assert.ok(navigation, 'menu lowers to core/navigation');
    const generated = result.assets.filter(asset => asset.kind === 'css').map(asset => asset.content ?? '').join('\n');
    // core/navigation server-rendered shape, with no text nodes between items.
    const items = navigation.innerBlocks.map(link => `<li class="wp-block-navigation-item wp-block-navigation-link ${link.attrs.className ?? ''}"><a class="wp-block-navigation-item__content" href="${link.attrs.url}"><span class="wp-block-navigation-item__label">${link.attrs.label}</span></a></li>`).join('');
    const copy = `<style>${base}.is-layout-flex{display:flex;flex-wrap:wrap;align-items:center}${core}${generated}</style><header><nav class="wp-block-navigation is-layout-flex ${navigation.attrs.className}"><ul class="wp-block-navigation__container wp-block-navigation ${navigation.attrs.className}">${items}</ul></nav></header>`;
    for (const width of [1440, 160]) {
      const measure = async html => {
        const page = await browser.newPage({ viewport: { width, height: 400 } });
        try {
          await page.setContent(html);
          return await page.locator('li').evaluateAll(items => items.map(item => {
            const anchor = item.querySelector('a');
            const range = document.createRange();
            range.selectNodeContents(anchor.firstChild.nodeType === 3 ? anchor : anchor.querySelector('span'));
            const label = range.getBoundingClientRect();
            return { height: item.getBoundingClientRect().height, x: label.x, y: label.y };
          }));
        } finally { await page.close(); }
      };
      const want = await measure(source), got = await measure(copy);
      assert.equal(got.length, want.length);
      got.forEach((item, index) => {
        for (const key of ['height', 'x', 'y']) {
          assert.ok(Math.abs(item[key] - want[index][key]) <= 0.5, `${display} links at ${width}px: item ${index} ${key} ${item[key]} matches source ${want[index][key]}`);
        }
      });
    }
  }
} finally { await browser.close(); }
console.log('Navigation inter-item space preserves block and inline link geometry');
